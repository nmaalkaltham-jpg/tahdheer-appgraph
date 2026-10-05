<?php

namespace App\Domain\Fulfillment;

/**
 * خطّة تنفيذ بندٍ واحد: الاستراتيجيّات ومن أين اشتُقّت (§٩).
 *
 * `source` يُسجَّل لأنّ الاشتقاق ثلاثُ طرقٍ مختلفة الثقة: عرضٌ مربوط
 * بالبند، أو عرضٌ بُلغ عبر جسر المنتج القديم، أو عمود `fulfillment_type`
 * وحده. حين يُخطئ التسليم لاحقًا يكون الفارق بينها هو السؤال الأوّل.
 */
final readonly class FulfillmentPlan
{
    /** @param list<string> $strategies */
    public function __construct(
        public array $strategies,
        public string $source,
    ) {}

    public function isMixed(): bool
    {
        return count($this->strategies) > 1;
    }
}
