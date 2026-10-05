<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Catalog\Models\ProductOffer;

/**
 * حالة التوفّر المُعلَنة — مشتقّةٌ من المخزون لا من حالة النشر.
 *
 * ─── العيب الذي يزيله ───
 *
 * كان التوفّر في بيانات المدرسة (`schema.org`) يُشتقّ من `status === 'published'`:
 * أي أنّ كلّ منتجٍ منشور يُعلَن «متوفّر» لمحرّكات البحث، ولو نفد مخزونه
 * كلُّه. وذاك إعلانٌ كاذب يُعاقَب عليه في نتائج التسوّق، ويُرسل زائرًا إلى
 * صفحةٍ لا يستطيع الشراء منها.
 *
 * وكان يُشتقّ مرّتين بنصّين متطابقين في `SchemaBuilder` (العرض المفرد
 * والمجمَّع)، فيفترقان عند أوّل تعديل.
 *
 * ─── و«مجهول» حالةٌ مُعلَنة لا صفر ───
 *
 * أربعٌ من استراتيجيّات المخزون (`LICENSE_POOL` · `SEAT_CAPACITY` ·
 * `TIME_CAPACITY` · `EXTERNAL`) لا عدّاد لها في هذا المستودع. فتُعاد
 * `UNKNOWN` صراحةً، ويُسقط القارئُ عقدة التوفّر بدل أن يخترع «متوفّر».
 * الغياب يُعلَن ولا يُملأ (§١٥٨).
 */
final class AvailabilityState
{
    public const IN_STOCK = 'IN_STOCK';

    public const OUT_OF_STOCK = 'OUT_OF_STOCK';

    public const PRE_ORDER = 'PRE_ORDER';

    public const BACK_ORDER = 'BACK_ORDER';

    /** لا عدّاد لهذه الاستراتيجيّة — فلا يُقال شيء. */
    public const UNKNOWN = 'UNKNOWN';

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * حالةُ عرضٍ بعينه.
     *
     * السؤال عن **العرض** لا عن المنتج: استراتيجيّة المخزون صفةُ عرضٍ،
     * فمنتجٌ له صيغةٌ متعقَّبة نفدت وأخرى لا نهائيّة ليس له جوابٌ واحد.
     */
    public function forOffer(ProductOffer $offer): string
    {
        return match ($offer->inventory_strategy) {
            'UNLIMITED', 'MADE_TO_ORDER' => self::IN_STOCK,
            'PREORDER' => self::PRE_ORDER,
            'BACKORDER' => self::BACK_ORDER,
            'TRACKED' => $this->inventory->availableFor((int) $offer->product_variant_id) > 0
                ? self::IN_STOCK
                : self::OUT_OF_STOCK,
            default => self::UNKNOWN,
        };
    }

    /**
     * ترجمةُ الحالة إلى عنوان `schema.org`، أو `null` حين لا يُقال شيء.
     *
     * `null` تعني: احذف العقدة. وكتابةُ `InStock` عند الجهل هي بالضبط
     * الكذبة التي يوجد هذا الصنف لمنعها.
     */
    public static function schemaUrl(string $state): ?string
    {
        return match ($state) {
            self::IN_STOCK => 'https://schema.org/InStock',
            self::OUT_OF_STOCK => 'https://schema.org/OutOfStock',
            self::PRE_ORDER => 'https://schema.org/PreOrder',
            self::BACK_ORDER => 'https://schema.org/BackOrder',
            default => null,
        };
    }

    /** هل يُشترى الآن؟ يُستعمل في فهرسة البحث (`in_stock`). */
    public static function isBuyable(string $state): bool
    {
        return $state !== self::OUT_OF_STOCK;
    }
}
