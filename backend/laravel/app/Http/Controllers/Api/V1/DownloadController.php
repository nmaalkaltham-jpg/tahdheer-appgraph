<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\Models\Asset;
use App\Domain\Downloads\DenyReason;
use App\Domain\Downloads\DownloadDelivery;
use App\Domain\Downloads\SignedDeliveryUrl;
use App\Domain\Entitlements\DownloadDenied;
use App\Domain\Entitlements\EntitlementService;
use App\Domain\Orders\OrderToken;
use App\Http\Controllers\Controller;
use App\Models\DownloadEvent;
use App\Models\DownloadGrant;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class DownloadController extends Controller
{
    private const MAX_TOKEN_BYTES = 4096;

    private const DEFAULT_URL_TTL = 900;

    private const RETRY_AFTER_SECONDS = 30;

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly DownloadDelivery $delivery,
    ) {}

    /**
     * X-Order-Token proves order ownership.
     *
     * Every authorization deny is audited, while secrets, object keys and
     * signed URLs are never written to the audit stream.
     */
    public function grant(Request $request, int $entitlementId): JsonResponse
    {
        $orderToken = $this->headerToken($request, 'X-Order-Token');

        if ($orderToken === null) {
            return $this->deny(null, DenyReason::FORBIDDEN);
        }

        $order = Order::query()
            ->select(['id'])
            ->where('order_key_hash', OrderToken::hash($orderToken))
            ->first();

        if ($order === null) {
            return $this->deny(null, DenyReason::FORBIDDEN);
        }

        /*
         * Ownership is part of the query itself. We never load an entitlement
         * belonging to another order and then branch on its existence.
         */
        $entitlement = Entitlement::query()
            ->whereKey($entitlementId)
            ->where('order_id', $order->id)
            ->with('orderItem')
            ->first();

        if ($entitlement === null) {
            return $this->deny(null, DenyReason::FORBIDDEN);
        }

        try {
            $rawGrantToken = $this->entitlements->validateForDownload(
                $entitlement,
                $request->ip(),
            );
        } catch (DownloadDenied $e) {
            return $this->deny(
                $entitlement->id,
                DenyReason::fromInternal($e->reason),
            );
        }

        if (! $this->validToken($rawGrantToken)) {
            Log::critical('downloads.invalid_grant_token_generated', [
                'entitlement_id' => $entitlement->id,
            ]);

            return $this->serviceFailure(
                'DOWNLOAD_GRANT_FAILED',
                'تعذّر إنشاء رابط التحميل',
            );
        }

        $resolvedAsset = $this->delivery->assetFor($entitlement);
        $tokenHash = $this->tokenHash($rawGrantToken);

        try {
            $binding = DB::transaction(
                function () use (
                    $tokenHash,
                    $resolvedAsset,
                    $entitlement,
                ): array {
                    $grant = DownloadGrant::query()
                        ->where('token_hash', $tokenHash)
                        ->lockForUpdate()
                        ->first();

                    if ($grant === null) {
                        return [
                            'status' => 'missing',
                            'grant' => null,
                            'asset' => null,
                        ];
                    }

                    if (
                        $grant->expires_at === null
                        || $grant->expires_at->isPast()
                    ) {
                        return [
                            'status' => 'expired',
                            'grant' => $grant,
                            'asset' => null,
                        ];
                    }

                    /*
                     * Once an asset is bound to a grant, that binding is the
                     * authority for the lifetime of the grant. Admin edits to
                     * ProductFile cannot silently turn the grant into another
                     * file or variant.
                     */
                    if ($grant->asset_id !== null) {
                        if (
                            $resolvedAsset !== null
                            && (int) $grant->asset_id
                                !== (int) $resolvedAsset->id
                        ) {
                            Log::warning(
                                'downloads.grant_asset_resolution_changed',
                                [
                                    'grant_id' => $grant->id,
                                    'entitlement_id' => $entitlement->id,
                                    'bound_asset_id' => $grant->asset_id,
                                    'resolved_asset_id' => $resolvedAsset->id,
                                ],
                            );
                        }

                        $boundAsset = Asset::query()->find($grant->asset_id);

                        if ($boundAsset === null) {
                            return [
                                'status' => 'bound_asset_missing',
                                'grant' => $grant,
                                'asset' => null,
                            ];
                        }

                        return [
                            'status' => 'ok',
                            'grant' => $grant,
                            'asset' => $boundAsset,
                        ];
                    }

                    if ($resolvedAsset !== null) {
                        $grant->forceFill([
                            'asset_id' => $resolvedAsset->id,
                        ])->save();
                    }

                    return [
                        'status' => 'ok',
                        'grant' => $grant,
                        'asset' => $resolvedAsset,
                    ];
                },
                attempts: 3,
            );
        } catch (Throwable $e) {
            Log::error('downloads.grant_binding_failed', [
                'entitlement_id' => $entitlement->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->serviceFailure(
                'DOWNLOAD_GRANT_FAILED',
                'تعذّر تجهيز رابط التحميل',
            );
        }

        /** @var DownloadGrant|null $grant */
        $grant = $binding['grant'];

        if ($binding['status'] === 'expired') {
            return $this->deny(
                $entitlement->id,
                DenyReason::EXPIRED,
            );
        }

        if ($binding['status'] === 'bound_asset_missing') {
            Log::error('downloads.bound_asset_row_missing', [
                'entitlement_id' => $entitlement->id,
                'grant_id' => $grant?->id,
                'asset_id' => $grant?->asset_id,
            ]);

            return $this->json([
                'code' => 'FILE_REMOVED',
                'message' => 'الملف المرتبط بهذا الرابط لم يعد متوفرًا',
            ], 410);
        }

        if ($binding['status'] !== 'ok' || $grant === null) {
            return $this->serviceFailure(
                'DOWNLOAD_GRANT_INVALID',
                'تعذّر تجهيز رابط التحميل',
            );
        }

        /** @var Asset|null $asset */
        $asset = $binding['asset'];

        $ttl = $this->deliveryTtl($grant);

        if ($ttl <= 0) {
            return $this->deny(
                $entitlement->id,
                DenyReason::EXPIRED,
            );
        }

        if ($asset === null) {
            $issuedAt = now();

            return $this->json([
                'url' => SignedDeliveryUrl::forSeconds(
                    $rawGrantToken,
                    $ttl,
                ),
                'expiresAt' => $grant->expires_at->toJSON(),
                'urlExpiresAt' => $issuedAt
                    ->copy()
                    ->addSeconds($ttl)
                    ->toJSON(),
                'fileAvailable' => false,
                'filename' => null,
            ]);
        }

        try {
            $url = $this->delivery->issueUrl(
                $asset,
                $rawGrantToken,
                $ttl,
            );
        } catch (Throwable $e) {
            Log::error('downloads.url_issue_failed', [
                'entitlement_id' => $entitlement->id,
                'grant_id' => $grant->id,
                'asset_id' => $asset->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->serviceFailure(
                'DOWNLOAD_URL_FAILED',
                'تعذّر إنشاء رابط التحميل',
            );
        }

        $issuedAt = now();

        return $this->json([
            'url' => $url,
            'expiresAt' => $grant->expires_at->toJSON(),
            'urlExpiresAt' => $issuedAt
                ->copy()
                ->addSeconds($ttl)
                ->toJSON(),
            'fileAvailable' => true,
            'filename' => $asset->downloadName(),
        ]);
    }

    /**
     * Redeem flow:
     *
     * signature -> grant -> entitlement -> current eligibility ->
     * immutable asset binding -> atomic download claim -> stream.
     *
     * "completed" is written only after the stream reaches EOF.
     */
    public function redeem(
        Request $request,
        string $rawToken,
    ): JsonResponse|StreamedResponse {
        if (! $this->validToken($rawToken)) {
            return $this->invalidLink();
        }

        /*
         * Preserve the public API contract: an unknown grant token is 404.
         *
         * Signature validation follows only after the token resolves to a grant.
         */
        $grant = DownloadGrant::query()
            ->where('token_hash', $this->tokenHash($rawToken))
            ->first();

        if ($grant === null) {
            return $this->invalidLink();
        }

        if (! $request->hasValidSignature()) {
            $reason = URL::hasCorrectSignature($request)
                ? DenyReason::EXPIRED
                : DenyReason::INVALID_SIGNATURE;

            $this->auditSafely(
                $grant->entitlement_id,
                $reason,
                'redeem',
            );

            return $this->json(['reason' => $reason], 403);
        }

        if (
            $grant->expires_at === null
            || $grant->expires_at->isPast()
        ) {
            return $this->denyRedeem(
                $grant->entitlement_id,
                DenyReason::EXPIRED,
            );
        }

        $entitlement = Entitlement::query()
            ->with('orderItem')
            ->find($grant->entitlement_id);

        if ($entitlement === null) {
            Log::warning('downloads.grant_orphaned', [
                'grant_id' => $grant->id,
                'entitlement_id' => $grant->entitlement_id,
            ]);

            return $this->denyRedeem(
                $grant->entitlement_id,
                DenyReason::FORBIDDEN,
            );
        }

        try {
            $this->entitlements->assertDownloadable(
                $entitlement,
                $request->ip(),
            );
        } catch (DownloadDenied $e) {
            return $this->denyRedeem(
                $entitlement->id,
                DenyReason::fromInternal($e->reason),
            );
        }

        $resolvedAsset = $grant->asset_id !== null
            ? Asset::query()->find($grant->asset_id)
            : $this->delivery->assetFor($entitlement);

        if ($grant->asset_id !== null && $resolvedAsset === null) {
            Log::error('downloads.bound_asset_row_missing', [
                'grant_id' => $grant->id,
                'entitlement_id' => $entitlement->id,
                'asset_id' => $grant->asset_id,
            ]);

            $this->auditSafely(
                $entitlement->id,
                DenyReason::NOT_AVAILABLE,
                'redeem',
            );

            return $this->json([
                'code' => 'FILE_REMOVED',
                'message' => 'الملف المرتبط بهذا الرابط لم يعد متوفرًا',
            ], 410);
        }

        if ($resolvedAsset === null) {
            $this->auditSafely(
                $entitlement->id,
                DenyReason::NOT_AVAILABLE,
                'redeem',
            );

            return $this->json([
                'code' => 'NOT_CONFIGURED',
                'message' => 'لا يوجد ملف مرتبط بهذا المنتج بعد',
            ], 501);
        }

        $asset = $resolvedAsset;

        try {
            $reservation = DB::transaction(
                function () use (
                    $grant,
                    $entitlement,
                    $asset,
                ): array {
                    $lockedGrant = DownloadGrant::query()
                        ->whereKey($grant->id)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedGrant === null) {
                        return ['status' => 'missing'];
                    }

                    if (
                        $lockedGrant->expires_at === null
                        || $lockedGrant->expires_at->isPast()
                    ) {
                        return ['status' => 'expired'];
                    }

                    /*
                     * Preserve an existing asset binding. Legacy grants without
                     * asset_id are bound on first redeem before any bytes leave.
                     */
                    if (
                        $lockedGrant->asset_id !== null
                        && (int) $lockedGrant->asset_id
                            !== (int) $asset->id
                    ) {
                        Log::critical(
                            'downloads.redeem_asset_binding_mismatch',
                            [
                                'grant_id' => $lockedGrant->id,
                                'entitlement_id' => $entitlement->id,
                                'bound_asset_id' => $lockedGrant->asset_id,
                                'requested_asset_id' => $asset->id,
                            ],
                        );

                        return ['status' => 'forbidden'];
                    }

                    if ($lockedGrant->asset_id === null) {
                        $lockedGrant->forceFill([
                            'asset_id' => $asset->id,
                        ])->save();
                    }

                    /*
                     * EntitlementService remains the authority for download
                     * limits. No new "single-use grant" business rule is
                     * invented here; concurrent requests are serialized around
                     * this grant and the entitlement claim remains atomic.
                     */
                    if (! $this->entitlements->claimDownload($entitlement)) {
                        return ['status' => 'limit'];
                    }

                    DownloadEvent::query()->create([
                        'entitlement_id' => $entitlement->id,
                        'event_type' => 'started',
                        'meta' => [
                            'grant_id' => $lockedGrant->id,
                            'asset_id' => $asset->id,
                        ],
                    ]);

                    return [
                        'status' => 'ok',
                        'grant_id' => (int) $lockedGrant->id,
                    ];
                },
                attempts: 3,
            );
        } catch (Throwable $e) {
            Log::error('downloads.reservation_failed', [
                'grant_id' => $grant->id,
                'entitlement_id' => $entitlement->id,
                'asset_id' => $asset->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->serviceFailure(
                'DOWNLOAD_RESERVATION_FAILED',
                'تعذّر بدء التحميل',
            );
        }

        switch ($reservation['status']) {
            case 'expired':
                return $this->denyRedeem(
                    $entitlement->id,
                    DenyReason::EXPIRED,
                );

            case 'limit':
                return $this->denyRedeem(
                    $entitlement->id,
                    DenyReason::fromInternal('limit_exhausted'),
                );

            case 'missing':
            case 'forbidden':
                return $this->denyRedeem(
                    $entitlement->id,
                    DenyReason::FORBIDDEN,
                );

            case 'ok':
                break;

            default:
                Log::critical('downloads.unknown_reservation_status', [
                    'status' => $reservation['status'] ?? null,
                    'grant_id' => $grant->id,
                    'entitlement_id' => $entitlement->id,
                ]);

                return $this->serviceFailure(
                    'DOWNLOAD_RESERVATION_FAILED',
                    'تعذّر بدء التحميل',
                );
        }

        $grantId = (int) $reservation['grant_id'];

        try {
            /*
             * StorageUnavailable thrown while opening the stream is still early
             * enough to return the claimed download slot to the customer.
             *
             * Failures after StreamedResponse is returned are handled by the
             * callbacks below. Those attempts stay counted because bytes may
             * already have reached the client.
             */
            return $this->delivery->stream(
                $asset,
                onCompleted: function () use (
                    $grantId,
                    $entitlement,
                    $asset,
                ): void {
                    $this->markCompleted(
                        $grantId,
                        $entitlement,
                        $asset,
                    );
                },
                onFailed: function (Throwable $e) use (
                    $grantId,
                    $entitlement,
                    $asset,
                ): void {
                    $this->markStreamFailed(
                        $grantId,
                        $entitlement,
                        $asset,
                        $e,
                    );
                },
            );
        } catch (StorageUnavailable $e) {
            $this->releaseClaimSafely($entitlement);

            if ($e->reason === StorageUnavailable::MISSING_OBJECT) {
                Log::error('downloads.object_missing', [
                    'entitlement_id' => $entitlement->id,
                    'asset_id' => $asset->id,
                    'grant_id' => $grantId,
                ]);

                $this->auditSafely(
                    $entitlement->id,
                    DenyReason::STORAGE_UNAVAILABLE,
                    'redeem',
                );

                return $this->json([
                    'code' => 'FILE_MISSING',
                    'message' => 'الملف غير متوفر في التخزين — أُبلغ الفريق',
                ], 410);
            }

            Log::warning('downloads.storage_unavailable', [
                'entitlement_id' => $entitlement->id,
                'asset_id' => $asset->id,
                'grant_id' => $grantId,
                'reason' => $e->reason,
            ]);

            $this->auditSafely(
                $entitlement->id,
                DenyReason::STORAGE_UNAVAILABLE,
                'redeem',
            );

            return $this->json([
                'code' => 'STORAGE_UNAVAILABLE',
                'message' => 'تعذّر الوصول إلى الملف حاليًا',
            ], 503)->header(
                'Retry-After',
                (string) self::RETRY_AFTER_SECONDS,
            );
        } catch (Throwable $e) {
            $this->releaseClaimSafely($entitlement);

            Log::error('downloads.delivery_open_failed', [
                'entitlement_id' => $entitlement->id,
                'asset_id' => $asset->id,
                'grant_id' => $grantId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->serviceFailure(
                'DOWNLOAD_FAILED',
                'تعذّر بدء تحميل الملف',
            );
        }
    }

    /**
     * A successful stream is the only place that records "completed".
     */
    private function markCompleted(
        int $grantId,
        Entitlement $entitlement,
        Asset $asset,
    ): void {
        try {
            DB::transaction(function () use (
                $grantId,
                $entitlement,
                $asset,
            ): void {
                DownloadGrant::query()
                    ->whereKey($grantId)
                    ->update(['used_at' => now()]);

                DownloadEvent::query()->create([
                    'entitlement_id' => $entitlement->id,
                    'event_type' => 'completed',
                    'meta' => [
                        'grant_id' => $grantId,
                        'asset_id' => $asset->id,
                    ],
                ]);
            });
        } catch (Throwable $e) {
            Log::critical('downloads.completion_audit_failed', [
                'grant_id' => $grantId,
                'entitlement_id' => $entitlement->id,
                'asset_id' => $asset->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A mid-stream failure is observable, but the download claim is not
     * released because bytes may already have reached the customer.
     */
    private function markStreamFailed(
        int $grantId,
        Entitlement $entitlement,
        Asset $asset,
        Throwable $failure,
    ): void {
        try {
            DownloadEvent::query()->create([
                'entitlement_id' => $entitlement->id,
                'event_type' => 'failed',
                'meta' => [
                    'grant_id' => $grantId,
                    'asset_id' => $asset->id,
                    'failure' => $failure::class,
                ],
            ]);
        } catch (Throwable $auditFailure) {
            Log::error('downloads.stream_failure_audit_failed', [
                'grant_id' => $grantId,
                'entitlement_id' => $entitlement->id,
                'asset_id' => $asset->id,
                'exception' => $auditFailure::class,
                'message' => $auditFailure->getMessage(),
            ]);
        }

        Log::warning('downloads.stream_failed', [
            'grant_id' => $grantId,
            'entitlement_id' => $entitlement->id,
            'asset_id' => $asset->id,
            'exception' => $failure::class,
            'message' => $failure->getMessage(),
        ]);
    }

    /**
     * Return a claimed slot only when no response body could have started.
     */
    private function releaseClaimSafely(Entitlement $entitlement): void
    {
        try {
            $this->entitlements->releaseDownload($entitlement);
        } catch (Throwable $e) {
            Log::critical('downloads.claim_release_failed', [
                'entitlement_id' => $entitlement->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delivery URL TTL = min(remaining grant lifetime, configured ceiling).
     *
     * An expired grant returns 0; it is never resurrected for one second.
     */
    private function deliveryTtl(DownloadGrant $grant): int
    {
        if ($grant->expires_at === null) {
            return 0;
        }

        $ceiling = max(
            1,
            (int) config(
                'filesystems.assets.url_ttl',
                self::DEFAULT_URL_TTL,
            ),
        );

        $remaining = $grant->expires_at->getTimestamp()
            - now()->getTimestamp();

        return $remaining <= 0
            ? 0
            : min($ceiling, $remaining);
    }

    private function headerToken(
        Request $request,
        string $header,
    ): ?string {
        $value = trim((string) $request->header($header, ''));

        return $this->validToken($value) ? $value : null;
    }

    private function validToken(string $token): bool
    {
        $length = strlen($token);

        return $length > 0
            && $length <= self::MAX_TOKEN_BYTES;
    }

    private function tokenHash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    private function invalidLink(): JsonResponse
    {
        return $this->json([
            'message' => 'رابط التحميل غير صالح',
        ], 404);
    }

    private function deny(
        ?int $entitlementId,
        string $reason,
    ): JsonResponse {
        $this->auditSafely(
            $entitlementId,
            $reason,
            'grant',
        );

        return $this->json([
            'reason' => $reason,
        ], 403);
    }

    private function denyRedeem(
        ?int $entitlementId,
        string $reason,
    ): JsonResponse {
        $this->auditSafely(
            $entitlementId,
            $reason,
            'redeem',
        );

        return $this->json([
            'reason' => $reason,
        ], 403);
    }

    /**
     * Append-only audit. Failure to write an audit row must not turn a
     * security deny into HTTP 500; the operational log receives the failure.
     */
    private function auditSafely(
        ?int $entitlementId,
        string $reason,
        ?string $stage = null,
    ): void {
        try {
            DownloadEvent::query()->create([
                'entitlement_id' => $entitlementId,
                'event_type' => 'denied',
                'meta' => array_filter(
                    [
                        'reason' => $reason,
                        'stage' => $stage,
                    ],
                    static fn ($value) => $value !== null,
                ),
            ]);
        } catch (Throwable $e) {
            Log::error('downloads.audit_failed', [
                'entitlement_id' => $entitlementId,
                'reason' => $reason,
                'stage' => $stage,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function serviceFailure(
        string $code,
        string $message,
    ): JsonResponse {
        return $this->json([
            'code' => $code,
            'message' => $message,
        ], 503)->header(
            'Retry-After',
            (string) self::RETRY_AFTER_SECONDS,
        );
    }

    /**
     * Download-control JSON is private and must never be cached.
     *
     * @param  array<string, mixed>  $data
     */
    private function json(
        array $data,
        int $status = 200,
    ): JsonResponse {
        return response()
            ->json($data, $status)
            ->header(
                'Cache-Control',
                'private, no-store, no-cache, max-age=0, must-revalidate',
            )
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
