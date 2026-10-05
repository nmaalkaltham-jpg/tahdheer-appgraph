<?php

namespace App\Http\Resources;

use App\Domain\Catalog\CoPurchaseMiner;
use App\Domain\Catalog\ProductPreviews;
use App\Domain\Catalog\RelatedProducts;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Full PDP payload: the card fields plus long copy, curriculum links,
 * typed facts and SEO row. Facts appear only when a real row exists.
 *
 * @mixin Product
 */
class ProductResource extends ProductCardResource
{
    public function toArray(Request $request): array
    {
        $out = parent::toArray($request) + [
            'subtitle' => $this->subtitle,
            'shortDesc' => $this->short_desc,
            'description' => $this->description,
            /*
             * التخطيط **المنشور** فقط: المسوّدة تخصّ المحرّر، وتسريبها إلى
             * الواجهة يجعل كل تعديل غير مكتمل مرئيًّا للعملاء فورًا.
             * الغياب يعني «استخدم الترتيب الافتراضي» لا «لا تعرض شيئًا».
             */
            'layout' => $this->publishedLayout(),
            'stage' => $this->term($this->stage),
            'grade' => $this->term($this->grade),
            'subject' => $this->term($this->subject),
            'semester' => $this->term($this->semester),
            'facts' => $this->facts->map(fn ($fact) => [
                'key' => $fact->key,
                'value' => $fact->int_value ?? $fact->text_value,
            ])->values(),
            /*
             * معرض الصور — الغلاف أولًا ثم الباقي بترتيب `position` المخزَّن،
             * بالأبعاد نفسها التي تُصدَّر في `image` كي يحجز المعرض الصندوق ولا
             * تقفز الصفحة أثناء التحميل.
             *
             * `whenLoaded`: علاقة غير محمَّلة تعني «غياب المفتاح» لا استعلامًا
             * لكل منتج — الصفحة تُصيَّر عندئذ من `image` وحده.
             */
            /*
             * صيغ الملفّ وأسعارها — Word وPowerPoint وPDF للدرس نفسه.
             *
             * المصفوفة تُصدَّر دائمًا ما دامت المجموعة محمَّلة، حتّى بعنصرٍ واحد:
             * «لهذا المنتج صيغةٌ واحدة» حقيقةٌ تخصّ الواجهة، وهي التي تجعلها
             * تُخفي المُبدِّل بدل أن تعرض خيارًا وحيدًا لا معنى لاختياره.
             * وغياب المجموعة (منتجٌ خارج الردم) يُسقط المفتاح كلّه.
             */
            'variants' => $this->whenLoaded(
                'group',
                fn () => $this->group === null
                    ? null
                    : ProductVariantResource::collection($this->variantsWithContext()),
            ),
            /* `purchaseCount` يأتي من البطاقة الأمّ — من `paid_units_sold` المحمَّل في `show()`. */
            'images' => $this->whenLoaded('media', fn () => $this->media
                ->sortByDesc('is_cover')
                ->map(fn ($medium) => [
                    'url' => $this->absoluteMediaUrl($medium->url),
                    'alt' => $medium->alt_ar ?? $this->title,
                    'w' => $medium->width,
                    'h' => $medium->height,
                ])->values()),
        ];

        /*
         * جدول التوافق ومصفوفة المحتوى — تبويبات صفحة المنتج.
         *
         * كلاهما يُصدَّر فقط حين توجد صفوف: تبويبٌ فارغ أسوأ من تبويبٍ غائب،
         * لأنّه يَعِد بمعلومةٍ ثمّ يخلف. والواجهة تبني التبويبات ممّا وصلها.
         */
        /*
         * صفحات المعاينة المجّانيّة. تُصدَّر فقط حين يُنتَج رابطٌ فعلًا
         * (انظر `ProductPreviews`) — فلا شريط صفحاتٍ يعد بمعاينةٍ لا تُفتح.
         */
        $previews = app(ProductPreviews::class)->forProduct($this->resource);
        if ($previews !== []) {
            $out['previews'] = $previews;
        }

        if ($this->relationLoaded('group') && $this->group !== null) {
            $compat = $this->group->compatibilities;
            if ($compat->isNotEmpty()) {
                $out['compatibility'] = $compat->map(fn ($row) => array_filter([
                    'kind' => $row->target_kind,
                    'name' => $row->target_name,
                    'minVersion' => $row->minimum_version,
                    'note' => $row->note,
                ], static fn ($v) => $v !== null))->values();
            }

            /*
             * «يُشترى معه عادةً» — توصياتٌ لها مصدر: سلوكُ شراءٍ مقيس أو قرارُ
             * محرّر. المنشورُ وحده يُعرض، وقسمٌ بلا صفٍّ يغيب عن الصفحة بدل
             * أن يُملأ بمنتجاتٍ «مشابهة» لم يقل أحدٌ إنّها تُشترى معه.
             */
            $together = $this->group->relationships
                ->where('relation_type', CoPurchaseMiner::RELATION)
                ->map(fn ($rel) => $rel->target?->legacyProduct)
                ->filter(fn ($p) => $p !== null && $p->status === 'published')
                ->unique('id')
                ->take(8)
                ->values();

            if ($together->isNotEmpty()) {
                $out['boughtTogether'] = ProductCardResource::collection($together)->resolve($request);
            }

            $sections = $this->group->contentSections;
            if ($sections->isNotEmpty()) {
                $out['sections'] = $sections->map(fn ($row) => array_filter([
                    'kind' => $row->kind,
                    'heading' => $row->heading,
                    'body' => $row->body,
                    'items' => $row->items,
                ], static fn ($v) => $v !== null && $v !== []))->values();
            }
        }

        /*
         * «منتجات ذات صلة»: الصفّ والمادّة نفسُهما أوّلًا ثمّ ما يُشترى معه.
         * مصفوفةٌ دائمًا — فارغةٌ حين لا صلة — كي لا تُحيّر الواجهةَ بين الغياب
         * والفراغ. و`boughtTogether` يبقى كما هو: عقدٌ قائمٌ بمصدرٍ مختلف.
         */
        $out['related'] = ProductCardResource::collection(
            app(RelatedProducts::class)->forProduct($this->resource),
        )->resolve($request);

        if ($this->seo !== null) {
            $out['seo'] = [
                'seoTitle' => $this->seo->seo_title,
                'seoDesc' => $this->seo->seo_desc,
                'quickAnswer' => $this->seo->quick_answer,
            ];
        }

        return $out;
    }

    /**
     * الصيغ المنشورة وقد وُصلت بمجموعتها ومنتجها المحمَّلين أصلًا — فتلميح
     * الصيغة (`note`) يقرأ `editable` ومكوّنات الحزمة بلا استعلامٍ عكسيّ.
     */
    private function variantsWithContext(): Collection
    {
        $group = $this->group;
        $group->setRelation('legacyProduct', $this->resource);

        return $group->publishedVariants->each(fn ($variant) => $variant->setRelation('group', $group));
    }

    /**
     * أقسام التخطيط المنشورة، أو null حين لا تخطيط منشور.
     *
     * @return list<array{widget: string, visible: bool}>|null
     */
    private function publishedLayout(): ?array
    {
        $row = DB::table('product_layouts')
            ->where('product_id', $this->id)
            ->value('published_layout');

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row, true);
        if (! is_array($decoded['sections'] ?? null)) {
            return null;
        }

        return array_values(array_map(static fn (array $s): array => [
            'widget' => (string) $s['widget'],
            'visible' => (bool) ($s['visible'] ?? true),
        ], $decoded['sections']));
    }

    private function term(?object $term): ?array
    {
        return $term === null ? null : [
            'id' => $term->id,
            'slug' => $term->slug,
            'name' => $term->name_ar,
        ];
    }
}
