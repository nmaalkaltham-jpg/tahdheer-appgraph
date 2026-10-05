<?php

namespace App\Domain\Entitlements;

use App\Domain\Orders\OrderStatus;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Risk\DownloadRiskEngine;
use App\Models\DownloadEvent;
use App\Models\DownloadGrant;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The digital access authority. Entitlements are an independent ledger —
 * never derived from order rows at request time.
 */
class EntitlementService
{
    /** Order statuses under which a download may proceed. */
    private const PAID_STATUSES = OrderStatus::COLLECTED;

    public const GRANT_TTL_HOURS = 24;

    public function __construct(
        private readonly OutboxWriter $outbox,
        private readonly DownloadRiskEngine $risk,
    ) {}

    /**
     * Grant entitlements for every item of an order. MUST run inside the
     * caller's transaction (the order-finalize critical path). Idempotent:
     * an order item already granted is skipped.
     *
     * @return Collection<int, Entitlement>
     */
    public function grantForOrder(Order $order): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'EntitlementService::grantForOrder must be called inside a database transaction.'
            );
        }
        if ($order->customer_id === null) {
            throw new RuntimeException('Cannot grant entitlements for an order without a customer.');
        }

        // الرفض قبل أوّل كتابة: لا صفّ نصفيّ لطلبٍ لا إذن له.
        self::assertAuthorized($order);

        $granted = collect();

        foreach ($order->items as $item) {
            $granted->push($this->grantForItem($order, $item));
        }

        return $granted;
    }

    /**
     * Grant for ONE item.
     *
     * Extracted so the fulfillment orchestrator can grant per strategy instead
     * of per order. `grantForOrder` grants to every line unconditionally, which
     * is only correct while every line is a digital download — a physical line
     * would silently receive download access it should never have.
     *
     * Idempotent on order_item_id, as before.
     */
    public function grantForItem(Order $order, OrderItem $item): Entitlement
    {
        /*
         * الإذن يُفحص هنا أيضًا لا في `grantForOrder` وحدها: هذه الدالّة
         * عامّة ويُنادى عليها مباشرةً من التنفيذ، فحارسٌ في الأعلى وحده
         * يتركها مكشوفة.
         */
        self::assertAuthorized($order);

        $entitlement = Entitlement::query()->firstOrCreate(
            ['order_item_id' => $item->id],
            [
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'version_label' => $item->version_label,
                'granted_at' => now(),
                'status' => 'ACTIVE',
            ],
        );

        $this->outbox->write(
            aggregateType: 'entitlement',
            aggregateId: (string) $entitlement->id,
            eventType: 'entitlement.granted',
            payload: ['entitlement_id' => $entitlement->id, 'order_id' => $order->id],
            idempotencyKey: "entitlement:{$entitlement->id}:granted",
        );

        return $entitlement;
    }

    /**
     * Validate an entitlement for download and issue a grant token.
     * The HASH is stored; the RAW token is returned once to the caller.
     *
     * @throws DownloadDenied
     */
    public function validateForDownload(Entitlement $entitlement, ?string $ip = null, ?string $userAgent = null): string
    {
        $this->assertDownloadable($entitlement, $ip, $userAgent);

        $rawToken = bin2hex(random_bytes(32));

        DB::transaction(function () use ($entitlement, $rawToken, $ip) {
            DownloadGrant::query()->create([
                'entitlement_id' => $entitlement->id,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => now()->addHours(self::GRANT_TTL_HOURS),
                'ip' => $ip,
            ]);

            DownloadEvent::query()->create([
                'entitlement_id' => $entitlement->id,
                'event_type' => 'link-generated',
                'meta' => $ip !== null ? ['ip' => $ip] : null,
            ]);
        });

        return $rawToken;
    }

    /**
     * الفحوص العقديّة وحدها — بلا إصدار رابط. يستعمله الاسترداد الفعليّ
     * للرابط: كان يُصدر منحةً جديدة مع كلّ تنزيل ويرمي رمزها.
     *
     * @throws DownloadDenied
     */
    public function assertDownloadable(Entitlement $entitlement, ?string $ip = null, ?string $userAgent = null): void
    {
        $entitlement->refresh();

        if ($entitlement->status !== 'ACTIVE') {
            throw new DownloadDenied('not_active');
        }
        if ($entitlement->expires_at !== null && $entitlement->expires_at->isPast()) {
            throw new DownloadDenied('expired');
        }
        if ($entitlement->download_limit !== null
            && $entitlement->download_count >= $entitlement->download_limit) {
            throw new DownloadDenied('limit_exhausted');
        }
        if (! in_array($entitlement->order->status, self::PAID_STATUSES, true)) {
            throw new DownloadDenied('order_not_paid');
        }

        /*
         * تقييم الخطر (§١٠٣ §١٠٥) — **يُسجّل ولا يمنع**.
         *
         * الفحوص الأربعة أعلاه هي وحدها ما يمنع، وكلّها عقديّة: حالةٌ وانتهاءٌ
         * وحدٌّ مُعلَن ودفعٌ لم يقع. أمّا إشارات الإساءة — دفعةُ تنزيلات،
         * عناوينُ كثيرة، استردادٌ بعد تنزيل — فترفع تقييمًا لعينٍ بشريّة ولا
         * تقطع الوصول.
         *
         * و§١٠٥ تقول لماذا: «إشارات الخطر لا تُثبت إساءةً تلقائيًّا». عائلةٌ
         * على شبكاتٍ مختلفة ومسافرٌ بين مدينتين يُنتجان النمط نفسه، ومنعُهما
         * يكلّف في الدعم والسمعة أكثر ممّا يوفّره منعُ لصٍّ واحد.
         */
        $this->risk->assess($entitlement, $ip, $userAgent);
    }

    /**
     * يحجز تنزيلًا واحدًا من الحدّ — تحديثٌ شرطيّ واحد في المحرّك.
     *
     * «اقرأ العدّاد ثمّ زده بعد البثّ» كان يسمح لعشرة طلباتٍ متوازية على
     * رابطٍ واحد بأن تمرّ كلّها من حدٍّ قدره واحد. وعدد الصفوف المتأثّرة هو
     * الجواب: صفرٌ يعني أنّ الحدّ نفد.
     */
    public function claimDownload(Entitlement $entitlement): bool
    {
        $claimed = Entitlement::query()
            ->whereKey($entitlement->id)
            ->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('download_limit')->orWhereColumn('download_count', '<', 'download_limit'))
            ->increment('download_count');

        return $claimed === 1;
    }

    /** يُعيد تنزيلًا حُجز ولم يُبَثّ (فشل التخزين بعد الحجز). */
    public function releaseDownload(Entitlement $entitlement): void
    {
        Entitlement::query()->whereKey($entitlement->id)->where('download_count', '>', 0)->decrement('download_count');
    }

    /**
     * Apply a revocation policy to an entitlement.
     */
    /**
     * حالاتُ الطلب التي وصل فيها مالٌ يُبرّر تسليم المنتج.
     *
     * `PARTIALLY_REFUNDED` و`REFUNDED` منها: المال وصل فعلًا ثمّ رُدّ بعضُه
     * أو كلّه، والسحب بعد الردّ قرارُ سياسةٍ منفصل (`RevocationPolicy`) لا
     * إنكارٌ لأنّ البيع وقع.
     */
    private const AUTHORIZED_STATUSES = OrderStatus::EVER_COLLECTED;

    /**
     * @throws EntitlementNotAuthorized
     */
    private static function assertAuthorized(Order $order): void
    {
        if (! in_array((string) $order->status, self::AUTHORIZED_STATUSES, true)) {
            throw new EntitlementNotAuthorized($order->id, (string) $order->status);
        }
    }

    public function revoke(Entitlement $entitlement, string $reason, RevocationPolicy $policy): Entitlement
    {
        DB::transaction(function () use ($entitlement, $reason, $policy) {
            // تحت قفل الصفّ، والمسحوب نهائيّ: لا يُكتب طابعُ السحب مرّتين.
            $entitlement = Entitlement::query()->whereKey($entitlement->id)->lockForUpdate()->firstOrFail();

            if ($entitlement->status === 'REVOKED') {
                return;
            }

            $changes = ['revocation_reason' => $reason];

            switch ($policy) {
                case RevocationPolicy::Immediate:
                    $changes['status'] = 'REVOKED';
                    $changes['revoked_at'] = $entitlement->revoked_at ?? now();
                    break;
                case RevocationPolicy::Grace3d:
                    $changes['expires_at'] = now()->addDays(3);
                    break;
                case RevocationPolicy::Keep:
                case RevocationPolicy::UpdatesOnly:
                case RevocationPolicy::LicenseOnly:
                    // Access remains; the policy is recorded and enforced by the
                    // version/license surfaces (later phase).
                    break;
            }

            $entitlement->update($changes);

            DownloadEvent::query()->create([
                'entitlement_id' => $entitlement->id,
                'event_type' => 'revoked',
                'meta' => ['reason' => $reason, 'policy' => $policy->value],
            ]);

            $this->outbox->write(
                aggregateType: 'entitlement',
                aggregateId: (string) $entitlement->id,
                eventType: 'entitlement.revoked',
                payload: [
                    'entitlement_id' => $entitlement->id,
                    'policy' => $policy->value,
                    'reason' => $reason,
                ],
                idempotencyKey: "entitlement:{$entitlement->id}:revoked",
            );
        });

        return $entitlement->refresh();
    }
}
