<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;

/**
 * حالات الطلب — والسؤالان الماليّان اللذان تُجاب بهما.
 *
 * ─── العيب الذي يزيله ───
 *
 * سؤالان اثنان كانا مكتوبين ثلاثَ عشرة مرّة في ثلاثة عشر ملفًّا، ويتّفقان
 * بالمصادفة وحدها:
 *
 *   «هل المال في اليد الآن؟»   → PAID · PROCESSING · COMPLETED
 *   «هل وصل المال يومًا؟»      → ما سبق + PARTIALLY_REFUNDED · REFUNDED
 *
 * والاختلاف ليس فرضيًّا: `RefundService` كان ينقصه `COMPLETED` و`PROCESSING`
 * من قائمة المحصَّل، فيُحسب المحصَّل صفرًا ويُرفض استردادٌ مشروع — عيبٌ كامن
 * ظهر لحظة صار الطلب يبلغ `COMPLETED`. وحالةٌ جديدة تُضاف إلى ستٍّ من ثلاث
 * عشرة تُنتج ثلاثة أنظمةٍ تختلف على وجود المال: الاستحقاق يمنح، والتسوية
 * تُبلّغ عن تحصيلٍ يتيم، ورسمُ الاحتيال لا يرى شيئًا.
 *
 * ─── ولماذا في النطاق لا في وحدة تحكّم ───
 *
 * تسعةُ أصنافٍ في النطاق (التحليلات والماليّة والمخاطر) كانت تستورد ثابتًا
 * من `Api\V1\Admin\OrderController` — أي أنّ النطاق يصعد إلى طبقة HTTP
 * ليعرف حقيقةً عن المال.
 */
final class OrderStatus
{
    /**
     * §٣٤ — مرآةُ `enum` الحالات في ترحيل التجارة، بترتيبها.
     *
     * الترتيب جزءٌ من المطابقة: `enum` في PostgreSQL يحفظه، وما يُولَّد منه
     * يتبعه.
     */
    public const ALL = [
        'PENDING', 'AWAITING_PAYMENT', 'PAID', 'PROCESSING', 'COMPLETED',
        'FAILED', 'CANCELLED', 'REFUNDED', 'PARTIALLY_REFUNDED',
    ];

    /**
     * المال في اليد الآن.
     *
     * يُجاب به: هل يُمنح استحقاق؟ هل يُعدّ هذا الطلب إيرادًا؟ هل يُحسب في
     * قُمع الشراء؟ — والمستردّ **ليس** منها: ماله خرج.
     */
    public const COLLECTED = ['PAID', 'PROCESSING', 'COMPLETED'];

    /**
     * وصل المال يومًا — وإن رُدّ بعضُه أو كلُّه.
     *
     * يُجاب به سؤالٌ آخر تمامًا: هل يجوز ردٌّ إضافيّ؟ هل يملك هذا الزبون
     * حقّ مراجعةٍ؟ هل يدخل في كشف الاحتيال؟ — طيُّه في الأوّل يجعل طلبًا
     * استُرد نصفُه غير قابلٍ لردّ النصف الآخر.
     */
    public const EVER_COLLECTED = [
        'PAID', 'PROCESSING', 'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED',
    ];

    public static function isCollected(?string $status): bool
    {
        return $status !== null && in_array($status, self::COLLECTED, true);
    }

    public static function wasEverCollected(?string $status): bool
    {
        return $status !== null && in_array($status, self::EVER_COLLECTED, true);
    }

    /**
     * هل يبقى في هذا الطلب ما يُردّ؟
     *
     * سؤالٌ ثالث لا يُختزل في قائمة: «وصل المال **وبقي منه شيء**». وكتابتُه
     * قائمةً رابعة (`REFUNDABLE_STATUSES`) كان يُخفي شطره الثاني، فتعرض
     * اللوحة زرَّ استردادٍ على طلبٍ استُرد كاملًا ثمّ يرفضه النطاق.
     */
    public static function hasRefundableBalance(Order $order): bool
    {
        return self::wasEverCollected($order->status)
            && (int) $order->refunded_total_minor + (int) $order->refund_reserved_minor < (int) $order->total_minor;
    }
}
