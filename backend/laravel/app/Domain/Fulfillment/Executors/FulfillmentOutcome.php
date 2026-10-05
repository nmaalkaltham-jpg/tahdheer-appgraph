<?php

namespace App\Domain\Fulfillment\Executors;

/**
 * نتيجة محاولة تنفيذٍ واحدة.
 *
 * ثلاث نتائج لا اثنتان، والثالثة هي المهمّة: **الانتظار ليس فشلًا**. شحنةٌ
 * تنتظر عنوانًا وخدمةٌ تنتظر موعدًا حالتان صحيحتان، وعدّهما فشلًا يملأ لوحة
 * التنبيهات بما لا يحتاج إصلاحًا فيُهمَل ما يحتاجه.
 */
final readonly class FulfillmentOutcome
{
    private function __construct(
        public string $status,
        public ?string $reason = null,
        public ?int $entitlementId = null,
        public ?int $shipmentId = null,
        public ?int $licenseKeyId = null,
    ) {}

    public static function fulfilled(
        ?int $entitlementId = null,
        ?int $shipmentId = null,
        ?int $licenseKeyId = null,
    ): self {
        return new self('FULFILLED', null, $entitlementId, $shipmentId, $licenseKeyId);
    }

    /** ينتظر شيئًا مشروعًا — بيانات مشغّل أو اختيار زبون. */
    public static function pending(string $reason): self
    {
        return new self('PENDING', $reason);
    }

    /** وقع خطأ. الرسالة تُعرض للمشغّل كما هي. */
    public static function failed(string $reason): self
    {
        return new self('FAILED', $reason);
    }

    public function isFulfilled(): bool
    {
        return $this->status === 'FULFILLED';
    }
}
