<?php

namespace App\Http\Resources;

use App\Domain\Catalog\BestSellers;
use App\Domain\Catalog\BundleSummary;
use App\Domain\Catalog\ProductCapabilities;
use App\Domain\Pricing\PricingService;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal honest product card. Every number derives from a real source:
 * discount is computed server-side by PricingService only when a sale is
 * active; rating fields are ABSENT until real review aggregates exist —
 * never 0, never invented. `editable` is a three-state in storage
 * (yes|no|unset) and «غير محدد» stays silent: the key is omitted entirely
 * when unset rather than emitted as false.
 *
 * Response keys are camelCase — the single API convention across every
 * endpoint (catalog, search, cart, checkout, orders). Storage columns stay
 * snake_case; this resource is the boundary between the two.
 *
 * @mixin Product
 */
class ProductCardResource extends JsonResource
{
    /**
     * طبيعة المنتج من مجموعته، أو من العمود القديم حين لا مجموعة.
     *
     * الترجمة تمرّ بـ`ProductCapabilities` لا بـ`match` مكتوبٍ هنا: كانت
     * مكتوبةً في أربعة مواضع، كلٌّ منها يفترض «رقميّ» عند الغياب.
     */
    private function productNature(): ?string
    {
        /*
         * لا استعلامَ هنا مهما كان.
         *
         * هذا المورد يُصيَّر لكلّ بطاقةٍ في القائمة، فاستعلامٌ واحد هنا هو
         * N+1 مكتمل: صفحةٌ من أربعٍ وعشرين بطاقة تُنتج أربعةً وعشرين
         * استعلامًا إضافيًّا. فالعلاقة تُحمَّل مسبقًا من المستدعي، وحين لا
         * تُحمَّل يُقرأ العمود القديم وحده — وهو محمولٌ على الصفّ نفسه.
         */
        $nature = $this->resource->relationLoaded('group')
            ? $this->group?->product_nature
            : null;

        return $nature ?? ProductCapabilities::natureOfLegacy($this->fulfillment_type);
    }

    /**
     * المجموعة تُحمّل عدّاد الشراء لكلّ بطاقاتها باستعلامٍ واحد قبل التصيير.
     *
     * @param  mixed  $resource
     */
    public static function collection($resource)
    {
        $ids = collect($resource)
            ->filter(fn ($p) => $p instanceof Product && $p->paid_units_sold === null)
            ->map(fn (Product $p) => (int) $p->id)
            ->values()
            ->all();

        if ($ids !== []) {
            app(BestSellers::class)->prime($ids);
        }

        return parent::collection($resource);
    }

    public function toArray(Request $request): array
    {
        $price = $this->price;
        $saleMinor = $price !== null ? PricingService::activeSaleMinor($price) : null;

        $out = [
            'id' => $this->id,
            'sku' => $this->sku,
            'title' => $this->title,
            'slug' => $this->slug,
            'url' => '/products/'.$this->slug,
            'categoryPath' => $this->categoryPath(),
            'resourceType' => $this->resource_type,
            'format' => $this->format,
            /*
             * البتّة القديمة تبقى — عقدٌ عامّ لها مستهلكون في المتجر، وحذفُها
             * كسرٌ بلا مقابل. لكنّها لم تعد **كلّ** ما يُقال عن النوع: ستّ
             * طبائع كانت تُطوى في نعم/لا، فالهجين يقول «رقميّ» والخدمة تقول
             * «ليس رقميًّا» — وكلاهما نصفُ حقيقة.
             */
            'digital' => $this->fulfillment_type === 'digital',
            /*
             * §٦ — الطبيعة كما هي. `null` تعني «منتجٌ سبق النموذج الكونيّ»
             * ولا تُملأ بافتراض: المجهول يُعلَن مجهولًا (§١٥٨).
             */
            'productNature' => $this->productNature(),
            'publishedAt' => $this->published_at?->toIso8601String(),
            /*
             * آخر تعديلٍ على المحتوى، وعنوانُ الإصدار كما وُسم (أو null — لا
             * يُشتقّ من التاريخ). و«الأكثر مبيعًا» من قائمةٍ واحدة تُحسب مرّةً
             * لكلّ طلب (`BestSellers`) — لا عمودَ يُقلَب يدويًّا.
             */
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'version' => $this->version,
            'bestSeller' => app(BestSellers::class)->contains((int) $this->id),
            /*
             * عدد عمليّات الشراء المدفوعة — صفرٌ يُعلَن صفرًا هنا (البطاقة تقرّر
             * إخفاءه)، من العدّاد نفسه الذي يرتّب «الأكثر مبيعًا». العمود
             * `paid_units_sold` حين حُمِّل في الاستعلام، وإلّا من الذاكرة المُحمَّلة
             * لكلّ القائمة دفعةً واحدة (`collection()` تُحمّلها).
             */
            'purchaseCount' => $this->paid_units_sold !== null
                ? (int) $this->paid_units_sold
                : app(BestSellers::class)->unitsFor((int) $this->id),
            /*
             * الترخيص والحزمة يُقرآن من علاقاتٍ محمَّلةٍ مسبقًا فحسب — صفحة
             * المنتج تحمّلها، والقوائم لا؛ فهناك تكون القيمة null صادقةً بمعنى
             * «لم يُسأل» لا «لا ترخيص»، بدل استعلامٍ لكلّ بطاقة.
             */
            'licenseLabel' => $this->licenseLabel(),
            'bundle' => BundleSummary::forProduct($this->resource),
        ];

        /*
         * الصفّ والفصل وصيغ الملفّ وعيّنته — ما تعرضه البطاقة تحت العنوان. كلّها
         * من علاقاتٍ حمّلها المستدعي مسبقًا (لا استعلامَ لكلّ بطاقة، كما في
         * `categoryPath`)، والغائب يغيب: لا صفَّ ولا فصلَ ولا صيغةَ تُكتب ولم
         * تُسجَّل. والمفتاحان `gradeName`/`semesterName` لا `grade`/`semester`:
         * `ProductResource` يرث هذا المورد ويُصدر `grade`/`semester` كائنين،
         * والاتّحاد (`+`) يُبقي مفتاح الأب — فالاسم المستقلّ يمنع التصادم.
         */
        foreach (['grade' => 'gradeName', 'semester' => 'semesterName'] as $relation => $key) {
            if ($this->resource->relationLoaded($relation) && $this->resource->{$relation} !== null) {
                $out[$key] = $this->resource->{$relation}->name_ar;
            }
        }

        $formats = $this->variantFormats();
        if ($formats !== null) {
            $out['formats'] = $formats;
        }

        /* `has_public_sample` يضيفه `ProductPreviews::withSampleFlag` للقائمة كلّها باستعلامٍ واحد. */
        if ($this->resource->getAttribute('has_public_sample') !== null) {
            $out['hasSample'] = (bool) $this->resource->getAttribute('has_public_sample');
        }

        // Cover image — emitted ONLY when a real row exists, with its stored
        // dimensions so the storefront reserves the box (no layout shift). No
        // cover ⇒ the key is absent and the card renders its own empty media
        // block, exactly as the artboards draw it.
        if ($this->resource->relationLoaded('cover') && $this->cover !== null) {
            $out['image'] = [
                // Absolute URL: the storefront runs on a different origin (and in
                // production media lives on object storage / a CDN), so a relative
                // path would resolve against the storefront and 404. A stored URL
                // that is already absolute is passed through untouched.
                'url' => $this->absoluteMediaUrl($this->cover->url),
                'alt' => $this->cover->alt_ar ?? $this->title,
                'w' => $this->cover->width,
                'h' => $this->cover->height,
            ];
        }

        // Rating — ONLY from a materialized aggregate built out of approved,
        // order-backed reviews, and only when it actually counts something. A
        // product with no reviews emits no rating keys at all, so the card
        // renders no star row instead of an empty or zeroed one.
        if ($this->resource->relationLoaded('reviewAggregate')
            && $this->reviewAggregate !== null
            && $this->reviewAggregate->count > 0) {
            $out['ratingAvg'] = round((float) $this->reviewAggregate->avg_rating, 2);
            $out['ratingCount'] = (int) $this->reviewAggregate->count;
        }

        // Three-state: only a decided value is published.
        if ($this->editable === 'yes' || $this->editable === 'no') {
            $out['editable'] = $this->editable === 'yes';
        }

        if ($price !== null) {
            $out['regularMinor'] = $price->regular_minor;
            $out['currency'] = $price->currency;
            $out['onSale'] = $saleMinor !== null;
            $out['priceMinor'] = $saleMinor ?? $price->regular_minor;
            if ($saleMinor !== null) {
                $out['discountPct'] = PricingService::discountPct($price->regular_minor, $saleMinor);
                $out['saleTo'] = $price->sale_to?->toIso8601String();
            }
        }

        return $out;
    }

    /**
     * اسم شروط الترخيص المربوطة بأوّل عرضٍ منشور — أو null حين لا شروط، أو
     * حين لم تُحمَّل السلسلة (القوائم).
     */
    private function licenseLabel(): ?string
    {
        if (! $this->resource->relationLoaded('group') || $this->group === null
            || ! $this->group->relationLoaded('publishedVariants')) {
            return null;
        }

        foreach ($this->group->publishedVariants as $variant) {
            $offer = $variant->relationLoaded('offer') ? $variant->offer : null;

            if ($offer !== null && $offer->relationLoaded('licenseTerms') && $offer->licenseTerms !== null) {
                return (string) $offer->licenseTerms->name;
            }
        }

        return null;
    }

    /**
     * صيغ الملفّ من صيغ المجموعة المنشورة — بلا تكرار وبترتيبها — أو null حين
     * لم تُحمَّل السلسلة أو لم تُسجَّل صيغة. عمود `format` القديم يبقى في
     * `format` لمن لم يدخل النموذج الكونيّ بعد.
     *
     * @return list<string>|null
     */
    private function variantFormats(): ?array
    {
        if (! $this->resource->relationLoaded('group') || $this->group === null
            || ! $this->group->relationLoaded('publishedVariants')) {
            return null;
        }

        $formats = $this->group->publishedVariants
            ->map(fn ($variant) => strtoupper(trim((string) $variant->format)))
            ->filter(fn (string $format) => $format !== '')
            ->unique()
            ->values()
            ->all();

        return $formats === [] ? null : $formats;
    }

    /** Resolve a stored media path against the API origin when it is relative. */
    protected function absoluteMediaUrl(string $url): string
    {
        if (preg_match('#^(https?:)?//#i', $url) === 1) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    /**
     * Breadcrumb labels built only from relations the caller eager-loaded —
     * an unloaded relation yields an empty path rather than an N+1 query.
     *
     * @return list<string>
     */
    protected function categoryPath(): array
    {
        $path = [];

        foreach (['stage', 'subject'] as $relation) {
            if ($this->resource->relationLoaded($relation) && $this->resource->{$relation} !== null) {
                $path[] = $this->resource->{$relation}->name_ar;
            }
        }

        return $path;
    }
}
