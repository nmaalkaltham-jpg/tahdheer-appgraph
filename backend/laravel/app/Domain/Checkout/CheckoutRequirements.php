<?php

namespace App\Domain\Checkout;

/**
 * ما يطلبه هذا الدفع بعينه (§١٠٠ §٣٢).
 *
 * «الدفع الرقميّ البحت لا يطلب عنوان شحن» ليست تحسينًا في الواجهة بل قاعدةً
 * في §٣٢: طلبُ عنوانٍ لمن يشتري ملفًّا يُسقط تحويلاتٍ ويجمع بياناتٍ شخصيّة
 * بلا سبب — وجمعُ ما لا يلزم مسألةُ خصوصيّة لا ذوق (§١٠٦).
 *
 * والمطلوب يُشتقّ من **استراتيجيّات ما في السلّة**، لا من نوع المنتج ولا من
 * علمٍ في الواجهة. فسلّةٌ فيها ملفّ وكتابٌ مطبوع تطلب عنوانًا لأنّ فيها
 * شحنةً، لا لأنّ أحدهما «ماديّ».
 */
final readonly class CheckoutRequirements
{
    /**
     * @param  list<string>  $strategies
     * @param  list<string>  $unresolvable  أسبابٌ تمنع الدفع أصلًا
     */
    public function __construct(
        public array $strategies,
        public bool $requiresShippingAddress,
        public bool $requiresBookingDetails,
        public bool $isMixed,
        public array $unresolvable = [],
    ) {}

    public function canProceed(): bool
    {
        return $this->unresolvable === [];
    }
}
