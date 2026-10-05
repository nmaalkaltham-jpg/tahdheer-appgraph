<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Models\Payment;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Deterministic dev/test provider. Never available in production —
 * ProviderRegistry refuses to resolve it there.
 */
class FakeProvider implements PaymentProvider
{
    public const SIGNATURE_HEADER = 'X-Fake-Signature';

    /**
     * سجلّ نداءات الاسترداد — للاختبار: يُثبت أنّ النداء وقع وبأيّ مفتاح.
     *
     * @var list<array{chargeId: string, amountMinor: int, currency: string, idempotencyKey: string, reason: ?string}>
     */
    public static array $refundCalls = [];

    /**
     * ما «يعرفه» المزوّد المزيَّف عند السؤال المباشر — للاختبار.
     *
     * @var array<string, array{status: string, amount_minor?: int, currency?: string}>
     */
    public static array $charges = [];

    /** @var array<string, string> providerRefundId → status */
    public static array $refunds = [];

    /**
     * ما «نفّذه» المزوّد فعلًا — مفتاحٌ واحد مالٌ واحد، كـ`reference.idempotent`
     * عند Tap: النداء نفسه مرّتين يعيد الأوّل ولا يُخرج مالًا ثانيًا.
     *
     * @var array<string, array{id: string, amountMinor: int}>
     */
    public static array $executedRefunds = [];

    public static function resetRefunds(): void
    {
        self::$refundCalls = [];
        self::$executedRefunds = [];
        self::$charges = [];
        self::$refunds = [];
    }

    public function retrieveCharge(string $chargeId): ProviderCharge
    {
        $c = self::$charges[$chargeId] ?? throw new ProviderUnavailable("Fake provider knows no charge {$chargeId}.");
        $status = mb_strtoupper($c['status']);

        return new ProviderCharge(
            chargeId: $chargeId,
            status: match ($status) {
                'CAPTURED' => ProviderCharge::CAPTURED,
                'INITIATED' => ProviderCharge::OPEN,
                'DECLINED', 'CANCELLED', 'ABANDONED', 'TIMEDOUT' => ProviderCharge::FAILED,
                default => ProviderCharge::UNKNOWN,
            },
            providerStatus: $status,
            amountMinor: $c['amount_minor'] ?? null,
            currency: isset($c['amount_minor']) ? ($c['currency'] ?? 'SAR') : null,
            amountProblem: null,
            refunds: $c['refunds'] ?? [],
        );
    }

    public function retrieveRefund(string $providerRefundId): RefundResult
    {
        $status = mb_strtoupper(self::$refunds[$providerRefundId] ?? throw new ProviderUnavailable('unknown refund'));

        return new RefundResult($providerRefundId, match ($status) {
            'REFUNDED' => RefundResult::SUCCEEDED,
            'FAILED' => RefundResult::FAILED,
            default => RefundResult::PENDING,
        }, $status);
    }

    public function name(): string
    {
        return 'fake';
    }

    public function supportsApplePay(): bool
    {
        return false;
    }

    /** المزيَّف يقبل رسومًا بأيّ هويّة: الاختبارات تصنعها بلا نيّة. */
    public function bindsChargesToIntents(): bool
    {
        return (bool) config('services.fake.binds_charges', false);
    }

    public function merchantId(): ?string
    {
        return config('services.fake.merchant_id');
    }

    /** المزوّد الوهميّ لا يميّز الطرق: `$method` يُتجاهل عمدًا. */
    public function createIntent(Order $order, ?string $method = null, ?string $idempotencyKey = null): PaymentIntent
    {
        return new PaymentIntent(
            provider: 'fake',
            intentId: self::chargeId($order),
            status: 'requires_payment',
            payUrl: null,
        );
    }

    public function verifyWebhook(Request $request): WebhookEvent
    {
        $given = (string) $request->header(self::SIGNATURE_HEADER, '');
        $expected = self::sign($request->getContent());

        if ($given === '' || ! hash_equals($expected, $given)) {
            throw new InvalidWebhookSignature('Fake webhook signature mismatch.');
        }

        $payload = $request->json()->all();

        if (($payload['object'] ?? null) === 'refund') {
            return new WebhookEvent(
                provider: 'fake',
                chargeId: (string) ($payload['charge_id'] ?? ''),
                orderNumber: '',
                succeeded: ($payload['status'] ?? null) === 'REFUNDED',
                payload: $payload,
                // كـTap: مبلغ الاسترداد يصل ليُسجَّل ما رُدّ من اللوحة مباشرةً.
                amountMinor: isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : null,
                currency: isset($payload['amount_minor']) ? mb_strtoupper((string) ($payload['currency'] ?? 'SAR')) : null,
                kind: WebhookEvent::REFUND,
                refundId: (string) ($payload['refund_id'] ?? ''),
            );
        }

        return new WebhookEvent(
            provider: 'fake',
            chargeId: (string) ($payload['charge_id'] ?? ''),
            orderNumber: (string) ($payload['order_number'] ?? ''),
            succeeded: ($payload['status'] ?? null) === 'CAPTURED',
            payload: $payload,
            // يُبلّغ ما وصله: المسار نفسه يُختبر من طرفه إلى طرفه.
            amountMinor: isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : null,
            currency: isset($payload['amount_minor'])
                ? mb_strtoupper((string) ($payload['currency'] ?? 'SAR'))
                : null,
        );
    }

    /**
     * سلوكُه من `services.fake.refund_mode`:
     *   succeed (الافتراض) · pending · reject · timeout
     */
    public function refund(Payment $payment, int $amountMinor, string $currency, string $idempotencyKey, ?string $reason): RefundResult
    {
        self::$refundCalls[] = [
            'chargeId' => (string) $payment->intent_id,
            'amountMinor' => $amountMinor,
            'currency' => $currency,
            'idempotencyKey' => $idempotencyKey,
            'reason' => $reason,
        ];

        $mode = (string) (config('services.fake.refund_mode') ?? 'succeed');
        $id = 'fake_re_'.substr(hash('sha256', $idempotencyKey), 0, 16);

        /*
         * `timeout_after_execute`: المزوّد نفّذ ثمّ انقطع الردّ — أخطر الحالات،
         * المال خرج والتطبيق لا يعلم.
         */
        if (in_array($mode, ['succeed', 'pending', 'timeout_after_execute'], true) && ! isset(self::$executedRefunds[$idempotencyKey])) {
            self::$executedRefunds[$idempotencyKey] = ['id' => $id, 'amountMinor' => $amountMinor];
        }

        if ($mode === 'timeout_after_execute') {
            throw new RefundOutcomeUnknown('Fake provider executed the refund, then the response was lost.');
        }

        return match ($mode) {
            'reject' => throw new ProviderRefundRejected('Fake provider rejected the refund.'),
            'timeout' => throw new RefundOutcomeUnknown('Fake provider timed out.'),
            'pending' => new RefundResult($id, RefundResult::PENDING),
            default => new RefundResult($id, RefundResult::SUCCEEDED),
        };
    }

    public static function chargeId(Order $order): string
    {
        return 'fake_chg_'.$order->id;
    }

    public static function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, (string) config('app.key'));
    }
}
