<?php

namespace App\Domain\Payments;

final readonly class WebhookEvent
{
    public const CHARGE = 'charge';

    public const REFUND = 'refund';

    /**
     * @param  array<string, mixed>  $payload
     * @param  int|null  $amountMinor  ما أبلغ به المزوّد؛ `null` = لم يُبلّغ
     * @param  string|null  $currency  يقترن بالمبلغ دائمًا — رقمٌ بلا عملة لا يُقارَن
     * @param  self::CHARGE|self::REFUND  $kind  رسمٌ (يُموِّل طلبًا) أو استردادٌ (يحسم استردادًا)
     * @param  string|null  $refundId  هويّة الاسترداد عند المزوّد — للاسترداد وحده
     */
    public function __construct(
        public string $provider,
        public string $chargeId,
        public string $orderNumber,
        public bool $succeeded,
        public array $payload = [],
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public string $kind = self::CHARGE,
        public ?string $refundId = null,
        /** مبلغٌ حاضرٌ ولا يُقرأ بدقّة (مفقود، منازل زائدة، صفر): يُرفض تمويله. */
        public ?string $amountProblem = null,
        /** تاجرُ الحدث كما أرسله المزوّد (Tap: `merchant.id`). */
        public ?string $merchantId = null,
    ) {}

    public function isRefund(): bool
    {
        return $this->kind === self::REFUND;
    }
}
