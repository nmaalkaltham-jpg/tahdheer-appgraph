<?php

namespace App\Domain\Catalog;

use App\Domain\Assets\Models\ProductFile;
use App\Domain\Downloads\DownloadDelivery;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * معاينة المنتج المجّانيّة — الملفّات التي يجوز لأيّ زائرٍ رؤيتها.
 *
 * صفحة المنتج في التصميم ترسم شريط صفحاتٍ و«معاينة كاملة». مصدرها الوحيد
 * `product_files` بدور `sample` وأصلٌ مرئيّته `public_sample`؛ وما عداه ملفٌّ
 * مدفوع لا يقترب من مُصيِّر.
 *
 * والشرط مضاعَف عمدًا: الدور يقول ما قصده المحرّر، والمرئيّة تقول ما يسمح به
 * التخزين. تعارضُهما يعني خطأً في التأليف، وفي التعارض يفوز الأشدّ — فلا
 * يتسرّب أصلٌ مدفوع بدورٍ خاطئ.
 *
 * ورابطٌ لا يمكن إنتاجه يُسقِط العنصر بدل أن يُصدَّر مكسورًا: المزوّد المحلّي
 * بلا مسارٍ مُعنوَن بالمفتاح بالتصميم، فتغيب المعاينة على بيئة التطوير ولا
 * تُزيَّف. وهذا يعني أنّ الصفحة تعرض الغلاف وحده هناك — وهو الصدق لا النقص.
 */
final class ProductPreviews
{
    public const ROLE = 'sample';

    public const TTL_SECONDS = 900;

    public function __construct(private readonly DownloadDelivery $delivery) {}

    /**
     * يضيف إلى استعلام قائمةٍ عمودَ `has_public_sample`: هل للمنتج ملفّ عيّنةٍ
     * عامّ؟ الشرط نفسه الذي تعرض به صفحة العيّنات منتجاتها — استعلامٌ فرعيّ
     * واحد للقائمة كلّها لا استعلامٌ لكلّ بطاقة. `ProductCardResource` يقرؤه
     * فيُصدر `hasSample`.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function withSampleFlag(Builder $query): Builder
    {
        return $query->withExists(['files as has_public_sample' => fn ($files) => $files
            ->where('product_files.role', self::ROLE)
            ->whereHas('asset', fn ($asset) => $asset->where('assets.visibility', 'public_sample'))]);
    }

    /**
     * @return list<array{url: string, name: string, mime: ?string}>
     */
    public function forProduct(Product $product): array
    {
        if (! $product->relationLoaded('files')) {
            return [];
        }

        $out = [];

        foreach ($product->files as $file) {
            if ($file->role !== self::ROLE) {
                continue;
            }

            $asset = $file->asset;

            /* `isPaidPrivate` يرمي في `publicUrl`؛ الفحص هنا يمنع الرمي لا يكرّره. */
            if ($asset === null || $asset->visibility !== 'public_sample') {
                continue;
            }

            /*
             * رابط المزوّد أوّلًا (رابطٌ موقَّع من التخزين الكائنيّ لا يمرّ
             * بالتطبيق أصلًا)، ثمّ مسار الخدمة الخاصّ بالعيّنات.
             *
             * والاحتياطيّ ليس تلطيفًا لفشل: المزوّد المحلّي **لا يصدر** رابطًا
             * بلا منحة بالتصميم، والعيّنة العامّة لا منحة لها. فبلا هذا
             * الاحتياطيّ تبقى العيّنات غير قابلةٍ للخدمة على كلّ نشرٍ محلّيّ —
             * وهي حالةٌ صحيحة لا استثناء.
             */
            $url = $this->delivery->publicUrl($asset, self::TTL_SECONDS)
                ?? url('/api/v1/samples/files/'.$asset->id);

            $out[] = [
                'url' => $url,
                'name' => (string) ($asset->customer_label ?: $asset->original_name),
                'mime' => $asset->mime,
            ];
        }

        return $out;
    }

    public static function isSample(ProductFile $file): bool
    {
        return $file->role === self::ROLE;
    }
}
