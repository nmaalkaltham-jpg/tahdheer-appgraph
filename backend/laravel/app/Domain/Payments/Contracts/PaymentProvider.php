<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\InvalidWebhookSignature;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\PaymentIntent;
use App\Domain\Payments\ProviderCharge;
use App\Domain\Payments\ProviderRefundRejected;
use App\Domain\Payments\ProviderUnavailable;
use App\Domain\Payments\RefundOutcomeUnknown;
use App\Domain\Payments\RefundResult;
use App\Domain\Payments\WebhookEvent;
use App\Models\Order;
use Illuminate\Http\Request;

interface PaymentProvider
{
    public function name(): string;

    /** هل يقبل المزوّد Apple Pay بإعداده الحاليّ؟ يقرّره المزوّد لا المتحكّم. */
    public function supportsApplePay(): bool;

    /**
     * طريقةُ الدفع الوحيدة التي يطلبها المتجر بالاسم. `null` = صفحة المزوّد
     * المستضافة بكلّ طرقها كما كانت دائمًا.
     */
    public const METHOD_APPLE_PAY = 'apple_pay';

    /**
     * ينشئ نيّة الدفع. `$method` تفضيلٌ للصفحة المستضافة لا مسارُ مالٍ آخر:
     * المبلغ والعملة والطلب هي هي؛ ومزوّدٌ لا يميّز الطرق يتجاهله.
     */
    public function createIntent(Order $order, ?string $method = null, ?string $idempotencyKey = null): PaymentIntent;

    /**
     * هل يُموِّل هذا المزوّد برسمٍ أنشأه المتجر لطلبٍ بعينه فقط؟
     *
     * `true` حين تكون هويّة الطلب في حمولة المزوّد غير موقَّعة (Tap):
     * الرسم الذي لا نيّة له عندنا لا يُموِّل شيئًا.
     */
    public function bindsChargesToIntents(): bool;

    /** معرّف التاجر المتوقَّع في أحداث المزوّد، أو `null` حين لا يُعرف. */
    public function merchantId(): ?string;

    /**
     * Verify the webhook signature and parse the event.
     *
     * @throws InvalidWebhookSignature
     */
    public function verifyWebhook(Request $request): WebhookEvent;

    /**
     * يطلب من المزوّد ردّ مبلغٍ من تحصيلٍ بعينه.
     *
     * `$idempotencyKey` يُمرَّر للمزوّد: النداء نفسه مرّتين استردادٌ واحد.
     *
     * @throws ProviderRefundRejected رفضٌ حاسم — المال لم يخرج
     * @throws RefundOutcomeUnknown لا ردّ حاسم — قد يكون خرج
     */
    public function refund(Payment $payment, int $amountMinor, string $currency, string $idempotencyKey, ?string $reason): RefundResult;

    /**
     * سؤال المزوّد عن رسم — لتعافي خطّافٍ ضائع.
     *
     * @throws ProviderUnavailable لا جواب — لا يُستنتج منه شيء
     */
    public function retrieveCharge(string $chargeId): ProviderCharge;

    /**
     * سؤال المزوّد عن استرداد — لحسم ما لم يصل خطّافه.
     *
     * @throws ProviderUnavailable
     */
    public function retrieveRefund(string $providerRefundId): RefundResult;
}
