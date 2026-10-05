<?php

namespace App\Http\Resources;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Pricing\PricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * صيغةٌ واحدة من صيغ المنتج، بسعرها.
 *
 * التسعير يمرّ بـ`PricingService::activeSaleWithin` — القاعدة نفسها التي
 * تسعّر `product_prices`، فلا تفترق نافذة تخفيضٍ عن أختها.
 *
 * وصيغةٌ بلا عرضٍ فعّال **لا تُصدَّر بمفاتيح سعرٍ فارغة ولا بصفر**: تُصدَّر
 * بـ`purchasable: false` وبلا سعر. الواجهة تعطّل الخيار بدل أن تعرض «٠ ر.س».
 *
 * @mixin ProductVariant
 */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $offer = $this->offer;

        $out = [
            'id' => $this->id,
            'sku' => $this->sku,
            'slug' => $this->slug,
            /* فارغٌ يعني «خذ عنوان المجموعة» (§٤١)، والصيغة تسمّي نفسها حينئذ. */
            'title' => $this->title,
            'format' => $this->format,
            /*
             * تلميحُ الصيغة تحت اسمها — مشتقٌّ لا مكتوب: «للطباعة» لملفّ PDF،
             * و«قابل للتعديل» لـWord/PowerPoint حين قرّر المحرّر أنّ المنتج
             * قابلٌ للتعديل فعلًا (`editable = yes`)، و«Word + PPT + PDF» للحزمة
             * من صيغ مكوّناتها. ما لا يُشتقّ يبقى null — لا جدولَ تلميحاتٍ يُخمَّن.
             */
            'note' => $this->note(),
            'purchasable' => $offer !== null,
        ];

        if ($offer === null) {
            return $out;
        }

        $regular = (int) $offer->regular_minor;
        $sale = PricingService::activeSaleWithin(
            $offer->sale_minor,
            $offer->sale_from,
            $offer->sale_to,
        );

        $out += [
            'currency' => $offer->currency,
            'sellingModel' => $offer->selling_model,
            'regularMinor' => $regular,
            'priceMinor' => $sale ?? $regular,
            'onSale' => $sale !== null,
        ];

        if ($sale !== null) {
            $out['discountPct'] = PricingService::discountPct($regular, $sale);
            $out['saleTo'] = $offer->sale_to?->toIso8601String();
        }

        return $out;
    }

    /** كلمات الصيغ كما تُكتب في التلميح، بترتيب عرضها. */
    private const FORMAT_WORDS = [
        'DOCX' => 'Word', 'DOC' => 'Word', 'PPTX' => 'PPT', 'PPT' => 'PPT',
        'PDF' => 'PDF', 'XLSX' => 'Excel', 'XLS' => 'Excel',
    ];

    private function note(): ?string
    {
        $group = $this->relationLoaded('group') ? $this->group : null;

        if ($group !== null && $group->product_nature === 'COMPOSITE' && $group->relationLoaded('bundleComponents')) {
            $order = array_values(array_unique(array_values(self::FORMAT_WORDS)));
            $words = $group->bundleComponents
                ->map(fn ($c) => self::FORMAT_WORDS[strtoupper((string) $c->variant?->format)] ?? null)
                ->filter()
                ->unique()
                ->sortBy(fn (string $w) => array_search($w, $order, true))
                ->values();

            return $words->isEmpty() ? null : $words->implode(' + ');
        }

        $format = strtoupper((string) $this->format);

        if ($format === 'PDF') {
            return 'للطباعة';
        }

        if (in_array($format, ['DOCX', 'DOC', 'PPTX', 'PPT'], true)) {
            $product = $group?->relationLoaded('legacyProduct') ? $group->legacyProduct : null;

            return $product?->editable === 'yes' ? 'قابل للتعديل' : null;
        }

        return null;
    }
}
