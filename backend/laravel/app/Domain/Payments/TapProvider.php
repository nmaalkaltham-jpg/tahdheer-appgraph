<?php

namespace App\Domain\Payments;

use App\Domain\Orders\OrderToken;
use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Models\Payment;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tap Payments adapter (evidence: legacy Tap-WooCommerce-V2 integration).
 * Config-driven via config('services.tap.*') — set TAP_SECRET_KEY /
 * TAP_WEBHOOK_SECRET in the environment and wire them into
 * config/services.php when that file is unfrozen (frozen for this build).
 *
 * Verified against developers.tap.company (2026-09-24):
 * - webhook `hashstring` header = HMAC-SHA256 with the merchant secret key over
 *   x_id·x_amount·x_currency·x_gateway_reference·x_payment_reference·x_status·
 *   x_created, amount formatted to the currency's ISO-4217 decimals (SAR: 2).
 *   Charges, authorizations and refunds share this exact shape.
 * - refunds: POST /v2/refunds {charge_id, amount, currency, reason,
 *   reference.idempotent} — the same idempotent string never starts a second
 *   refund.
 * Still unverified: `source.id = src_all` as the hosted-page all-methods source.
 */
class TapProvider implements PaymentProvider
{
    public const BASE_URL = 'https://api.tap.company/v2';

    public function name(): string
    {
        return 'tap';
    }

    /** Apple Pay يحتاج تفعيلًا في حساب Tap؛ العلم يُعلنه لا يُثبته. */
    public function supportsApplePay(): bool
    {
        return (bool) config('services.tap.apple_pay', false);
    }

    /** `reference.order` خارج توقيع Tap — فالرسم لا يُموِّل إلّا طلبَ نيّته. */
    public function bindsChargesToIntents(): bool
    {
        return true;
    }

    public function merchantId(): ?string
    {
        return TapCredentials::fromConfig()->merchantId;
    }

    public function createIntent(Order $order, ?string $method = null, ?string $idempotencyKey = null): PaymentIntent
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw new RuntimeException('Tap secret key is not configured (services.tap.secret_key).');
        }

        $frontend = rtrim((string) (config('services.tap.frontend_url') ?? env('FRONTEND_URL', '')), '/');

        $response = Http::withToken($secret)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15) // المزوّد المعلّق كان يُثبّت عامل PHP بلا حدّ
            ->post($this->baseUrl().'/charges', [
                'amount' => Money::formatMinor((int) $order->total_minor),
                'currency' => 'SAR',
                'threeDSecure' => true,
                /*
                 * `reference.idempotent` (وثائق Tap): المفتاحُ نفسه خلال ٢٤ ساعة
                 * يُعيد الرسمَ الأوّل ولا يُنشئ ثانيًا. مهلةٌ بعد أن قبِل Tap
                 * الطلب ثمّ إعادةٌ كانت تفتح رسمين قابلين للدفع لطلبٍ واحد.
                 */
                'reference' => array_filter([
                    'order' => $order->number,
                    'transaction' => $idempotencyKey,
                    'idempotent' => $idempotencyKey,
                ], fn ($v) => $v !== null),
                'customer' => [
                    'first_name' => (string) ($order->tax_org_name ?? ''),
                    'email' => $order->email,
                    'phone' => ['country_code' => '966', 'number' => (string) $order->phone],
                ],
                /*
                 * `src_all` صفحةُ Tap المستضافة بكلّ طرقها (TODO-verify)،
                 * و`src_apple_pay` الصفحةُ نفسها مقصورةً على Apple Pay — وثائق
                 * Tap: لا إعداد عند التاجر في المسار المستضاف. فالتحويل هنا
                 * وحده، ولا يمسّ المبلغ ولا العملة.
                 */
                'source' => ['id' => $method === PaymentProvider::METHOD_APPLE_PAY ? 'src_apple_pay' : 'src_all'],
                /*
                 * العودة تهبط على صفحة الطلب نفسها — لا على مسارٍ وسيط.
                 * كان هنا `/checkout/callback?order={number}` ولا وجود لذلك
                 * المسار في المتجر: المشتري يدفع فعلًا ثمّ يعود إلى 404،
                 * ورمز طلبه كان في ذاكرة الصفحة التي غادرها فيضيع معها.
                 * والرمز حتميّ (HMAC على `number|email` وإصدارِ رمز الطلب) فيُشتقّ
                 * هنا كما يُشتقّ في الدفع — ولا يُقرأ من قاعدة البيانات لأنّ
                 * المخزَّن هو تجزئته وحدها. والإصدار شرط: رمزٌ مُدوَّر يعيد
                 * المشتري إلى رمزٍ أُبطل لو اشتُقّ هنا بالصيغة الأولى.
                 */
                'redirect' => ['url' => $frontend.'/orders/'.rawurlencode(OrderToken::forOrder($order))],
                'post' => ['url' => url('/api/v1/payments/webhook/tap')],
                'metadata' => ['order_id' => (string) $order->id],
                // تاجرُ المتجر صراحةً: رسمٌ عند تاجرٍ آخر لا يُموِّل (يُفحص في الخطّاف).
                ...($this->merchantId() !== null ? ['merchant' => ['id' => $this->merchantId()]] : []),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Tap charge creation failed: HTTP '.$response->status());
        }

        return new PaymentIntent(
            provider: 'tap',
            intentId: (string) $response->json('id'),
            status: 'requires_payment',
            payUrl: $response->json('transaction.url'),
        );
    }

    public function verifyWebhook(Request $request): WebhookEvent
    {
        $secret = $this->webhookSecret();
        $given = (string) $request->header('hashstring', '');
        $payload = $request->json()->all();

        if ($secret === '' || $given === '') {
            throw new InvalidWebhookSignature('Missing Tap webhook secret or hashstring header.');
        }

        $expected = self::hashstring($payload, $secret);

        if (! hash_equals($expected, $given)) {
            throw new InvalidWebhookSignature('Tap hashstring mismatch.');
        }

        $id = (string) ($payload['id'] ?? '');

        if (($payload['object'] ?? null) === 'refund' || str_starts_with($id, 're_')) {
            /*
             * خطّاف استرداد: يحسم صفَّ استردادٍ بهويّته عند المزوّد، ولا
             * يُموِّل طلبًا. التوقيع نفسه (الشكل موثّق للرسوم والاستردادات معًا).
             */
            $refundAmount = Money::parseProviderAmount($payload['amount'] ?? null);

            return new WebhookEvent(
                provider: 'tap',
                chargeId: (string) ($payload['charge_id'] ?? ($payload['charge']['id'] ?? '')),
                orderNumber: (string) ($payload['reference']['order'] ?? ''),
                succeeded: mb_strtoupper((string) ($payload['status'] ?? '')) === 'REFUNDED',
                payload: $payload,
                // مبلغ الاسترداد يلزم لتسجيل ما رُدّ من لوحة Tap مباشرةً.
                amountMinor: $refundAmount['minor'],
                currency: isset($payload['currency']) ? mb_strtoupper((string) $payload['currency']) : null,
                kind: WebhookEvent::REFUND,
                refundId: $id,
                amountProblem: $refundAmount['problem'],
                merchantId: isset($payload['merchant']['id']) ? (string) $payload['merchant']['id'] : null,
            );
        }

        $amount = Money::parseProviderAmount($payload['amount'] ?? null);

        return new WebhookEvent(
            merchantId: isset($payload['merchant']['id']) ? (string) $payload['merchant']['id'] : null,
            provider: 'tap',
            chargeId: (string) ($payload['id'] ?? ''),
            orderNumber: (string) ($payload['reference']['order'] ?? ''),
            succeeded: ($payload['status'] ?? null) === 'CAPTURED',
            payload: $payload,
            /*
             * المبلغ والعملة داخلان في التوقيع (`x_amount` و`x_currency`)،
             * فهما حقيقةٌ موثّقة من المزوّد لا قيمةٌ يمكن للمرسل تزويرها.
             * كانا يُقرآن للتحقّق ثمّ يُرميان.
             */
            /*
             * بلا فاصلةٍ عائمة، ومغلقٌ عند الشكّ: Tap يرسل المبلغ دائمًا، فغيابُه
             * أو منازلُه الزائدة أو صفرُه فرقٌ يُرفض تمويله لا «غير معروف».
             */
            amountMinor: $amount['minor'],
            currency: $amount['minor'] !== null && isset($payload['currency'])
                ? mb_strtoupper((string) $payload['currency'])
                : null,
            amountProblem: $amount['problem'] ?? (isset($payload['currency']) ? null : 'CURRENCY_MISSING'),
        );
    }

    /**
     * يطلب ردّ مبلغٍ من رسمٍ مقبوض. `reference.idempotent` هو مفتاح الخمول
     * عند Tap: النداء نفسه مرّتين لا يبدأ استردادًا ثانيًا.
     */
    public function refund(Payment $payment, int $amountMinor, string $currency, string $idempotencyKey, ?string $reason): RefundResult
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw new RefundOutcomeUnknown('Tap secret key is not configured (services.tap.secret_key).');
        }

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($this->baseUrl().'/refunds', [
                    'charge_id' => (string) $payment->intent_id,
                    'amount' => Money::formatMinor($amountMinor),
                    'currency' => $currency,
                    // Tap يقبل ثلاث قيم فقط؛ سبب المشغّل يُحفظ في البيانات الوصفيّة.
                    'reason' => 'requested_by_customer',
                    'reference' => ['idempotent' => $idempotencyKey, 'merchant' => $idempotencyKey],
                    'post' => ['url' => url('/api/v1/payments/webhook/tap')],
                    'metadata' => [
                        'refund_key' => $idempotencyKey,
                        'note' => mb_substr((string) $reason, 0, 120),
                    ],
                ]);
        } catch (ConnectionException $e) {
            // مهلةٌ أو انقطاع: الطلب ربّما وصل. لا يُعاد أعمى ولا يُعلَن فشل.
            throw new RefundOutcomeUnknown('Tap refund request did not complete: '.$e->getMessage());
        }

        if ($response->serverError()) {
            throw new RefundOutcomeUnknown('Tap refund returned HTTP '.$response->status());
        }

        if ($response->failed()) {
            $message = (string) ($response->json('errors.0.description')
                ?? $response->json('message')
                ?? ('HTTP '.$response->status()));

            throw new ProviderRefundRejected($message);
        }

        $status = mb_strtoupper((string) $response->json('status'));
        $id = (string) $response->json('id');

        $mapped = match (true) {
            $status === 'REFUNDED' => RefundResult::SUCCEEDED,
            in_array($status, ['PENDING', 'IN_PROGRESS', 'INITIATED', 'ACCEPTED'], true) => RefundResult::PENDING,
            default => RefundResult::FAILED,
        };

        return new RefundResult(
            providerRefundId: $id !== '' ? $id : null,
            status: $mapped,
            message: $mapped === RefundResult::FAILED
                ? trim(($status !== '' ? $status : 'UNKNOWN').' '.(string) $response->json('response.message'))
                : null,
            raw: (array) $response->json(),
        );
    }

    /**
     * سؤال المزوّد مباشرةً عن رسم: `GET /v2/charges/{id}`.
     *
     * يُستعمل حين يضيع الخطّاف — المال قد يكون قُبض والطلب ينتظر. الحالات
     * تُصنَّف صراحةً، وما لا نعرفه `UNKNOWN` لا نجاحٌ ولا فشل.
     */
    public function retrieveCharge(string $chargeId): ProviderCharge
    {
        $response = $this->get('/charges/'.rawurlencode($chargeId));
        $status = mb_strtoupper((string) $response['status']);
        $amount = Money::parseProviderAmount($response['amount'] ?? null);

        return new ProviderCharge(
            chargeId: (string) ($response['id'] ?? $chargeId),
            status: match (true) {
                $status === 'CAPTURED' => ProviderCharge::CAPTURED,
                in_array($status, ['INITIATED', 'IN_PROGRESS', 'AUTHORIZED'], true) => ProviderCharge::OPEN,
                in_array($status, ['ABANDONED', 'CANCELLED', 'FAILED', 'DECLINED', 'RESTRICTED', 'VOID', 'TIMEDOUT'], true) => ProviderCharge::FAILED,
                default => ProviderCharge::UNKNOWN,
            },
            providerStatus: $status,
            amountMinor: $amount['minor'],
            currency: isset($response['currency']) ? mb_strtoupper((string) $response['currency']) : null,
            amountProblem: $amount['problem'] ?? (isset($response['currency']) ? null : 'CURRENCY_MISSING'),
            raw: $response,
            merchantId: isset($response['merchant']['id']) ? (string) $response['merchant']['id'] : null,
            refunds: self::chargeRefunds($response),
        );
    }

    /**
     * استرداداتُ الرسم كما يعرضها Tap في كائن الرسم نفسه.
     *
     * رُصد في الصندوق التجريبيّ (٢٠٢٦-٠٩-٢٥): `refunds.refund` قائمةُ عناصر
     * `{id, amount, currency, created, date, status}` — والمبلغ عددٌ بوحدة
     * العملة. وقائمةُ `/v2/refunds/list` أجابت «لا استردادات» لاستردادٍ قائم،
     * فلا يُبنى الاكتشاف عليها.
     *
     * @param  array<string, mixed>  $charge
     * @return list<array{id: string, amountMinor: int|null, currency: string|null, status: string}>
     */
    public static function chargeRefunds(array $charge): array
    {
        $raw = $charge['refunds']['refund'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        $items = array_is_list($raw) ? $raw : [$raw];

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
                continue;
            }
            $out[] = [
                'id' => $item['id'],
                'amountMinor' => Money::parseProviderAmount($item['amount'] ?? null)['minor'],
                'currency' => isset($item['currency']) ? mb_strtoupper((string) $item['currency']) : null,
                'status' => mb_strtoupper((string) ($item['status'] ?? '')),
            ];
        }

        return $out;
    }

    /** `GET /v2/refunds/{id}` — حسمُ استردادٍ لم يصل خطّافه. */
    public function retrieveRefund(string $providerRefundId): RefundResult
    {
        $response = $this->get('/refunds/'.rawurlencode($providerRefundId));
        $status = mb_strtoupper((string) $response['status']);

        return new RefundResult(
            providerRefundId: (string) ($response['id'] ?? $providerRefundId),
            status: match (true) {
                $status === 'REFUNDED' => RefundResult::SUCCEEDED,
                in_array($status, ['FAILED', 'CANCELLED', 'DECLINED'], true) => RefundResult::FAILED,
                default => RefundResult::PENDING,
            },
            message: $status,
            raw: $response,
        );
    }

    /**
     * قراءةٌ من Tap بمهلٍ صريحة. أيّ فشلٍ «غير معروف» لا «فشل»: سؤالٌ لم
     * يُجَب لا يقول شيئًا عن المال.
     *
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw new ProviderUnavailable('Tap secret key is not configured.');
        }

        try {
            $response = Http::withToken($secret)->acceptJson()->connectTimeout(5)->timeout(15)
                ->get($this->baseUrl().$path);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable('Tap did not answer: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new ProviderUnavailable('Tap returned HTTP '.$response->status());
        }

        $json = $response->json();
        if (! is_array($json) || ! isset($json['status'])) {
            throw new ProviderUnavailable('Tap returned an unreadable body.');
        }

        return $json;
    }

    /**
     * Tap's documented post-hash: HMAC-SHA256 over the concatenated
     * x_-prefixed fields (verified 2026-09-24).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hashstring(array $payload, string $secret): string
    {
        $amount = number_format((float) ($payload['amount'] ?? 0), 2, '.', '');
        $toSign = 'x_id'.($payload['id'] ?? '')
            .'x_amount'.$amount
            .'x_currency'.($payload['currency'] ?? '')
            .'x_gateway_reference'.($payload['reference']['gateway'] ?? '')
            .'x_payment_reference'.($payload['reference']['payment'] ?? '')
            .'x_status'.($payload['status'] ?? '')
            /*
             * `created`: الرسمُ يحمله في `transaction.created`، والاستردادُ في
             * `created` على المستوى الأعلى (رُصد من كائن استردادٍ حقيقيّ في
             * الصندوق التجريبيّ ٢٠٢٦-٠٩-٢٥: `transaction` بلا `created`). قراءةُ
             * الأوّل وحده كانت تُوقّع خطّافات الاسترداد بنصٍّ فارغ فتُرفض كلُّها.
             */
            .'x_created'.($payload['transaction']['created'] ?? $payload['created'] ?? '');

        return hash_hmac('sha256', $toSign, $secret);
    }

    private function baseUrl(): string
    {
        return rtrim((string) (config('services.tap.base_url') ?? self::BASE_URL), '/');
    }

    private function secretKey(): string
    {
        $credentials = TapCredentials::fromConfig();

        // إعدادٌ متناقض لا يُحصّل ولا يتحقّق: أهون من مالٍ في البيئة الخطأ.
        return $credentials->configured() ? $credentials->secretKey : '';
    }

    private function webhookSecret(): string
    {
        // Tap signs webhooks with the merchant secret key (documented); a
        // dedicated webhook secret is honoured when configured.
        $credentials = TapCredentials::fromConfig();

        return $credentials->configured() ? $credentials->webhookSecret : '';
    }
}
