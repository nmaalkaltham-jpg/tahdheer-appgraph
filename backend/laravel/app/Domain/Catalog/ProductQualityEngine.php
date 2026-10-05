<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\ProductContentSection;
use App\Domain\Catalog\Models\ProductGroup;

/**
 * محرّك جودة بيانات المنتج (§٦٠ §٦١ §٦٢ §١٥٧).
 *
 * يقيس سبعة أبعادٍ مستقلّة ويسمّي ناقصَ كلٍّ منها. الناقص يُسمّى ولا يُعدّ:
 * «٣ حقول ناقصة» تُرسل المشغّل يبحث، و«لا وزن على الصيغة» تُرسله يُصلح.
 *
 * وكلّ قاعدةٍ هنا تقيس ما **يُستعمل فعلًا** في هذا النظام لا قائمةً مثاليّة:
 * الوزن مطلوبٌ للماديّ وحده لأنّ محرّك الشحن يرفض بدونه، وGTIN لا يُطلب
 * أصلًا لأنّ §٤٠ تمنع اختلاقه و§١٥٨ تفرّق بين «مجهول» و«غير منطبق».
 */
class ProductQualityEngine
{
    /** §٦٠ — الأبعاد. مستقلّةٌ عمدًا ولا تُجمع في متوسّط. */
    public const DIMENSIONS = [
        'IDENTITY', 'CLASSIFICATION', 'CONTENT', 'SPECIFICATION',
        'FULFILLMENT', 'SEARCH', 'PROVENANCE',
    ];

    public function score(ProductGroup $group): ProductScorecard
    {
        $group->loadMissing(['variants.offers.fulfillments', 'attributeValues']);

        $checks = [
            'IDENTITY' => $this->identity($group),
            'CLASSIFICATION' => $this->classification($group),
            'CONTENT' => $this->content($group),
            'SPECIFICATION' => $this->specification($group),
            'FULFILLMENT' => $this->fulfillment($group),
            'SEARCH' => $this->search($group),
            'PROVENANCE' => $this->provenance($group),
        ];

        $scores = [];
        $missing = [];

        foreach ($checks as $dimension => $result) {
            [$passed, $gaps] = $result;
            $total = $passed + count($gaps);
            $scores[$dimension] = $total === 0 ? 100 : (int) round($passed / $total * 100);
            $missing[$dimension] = $gaps;
        }

        return new ProductScorecard($group->id, $scores, $missing);
    }

    /** @return array{0: int, 1: list<string>} */
    private function identity(ProductGroup $group): array
    {
        $gaps = [];
        $passed = 0;

        trim((string) $group->title) !== '' ? $passed++ : $gaps[] = 'لا عنوان';
        trim((string) $group->slug) !== '' ? $passed++ : $gaps[] = 'لا مُعرّف عنوان (slug)';

        $variants = $group->variants;
        if ($variants->isEmpty()) {
            $gaps[] = 'لا صيغة واحدة — لا شيء يُباع';
        } else {
            $passed++;
            foreach ($variants as $variant) {
                trim((string) $variant->sku) === ''
                    ? $gaps[] = "الصيغة {$variant->id} بلا رمز SKU"
                    : $passed++;
            }
        }

        return [$passed, $gaps];
    }

    /** @return array{0: int, 1: list<string>} */
    private function classification(ProductGroup $group): array
    {
        $gaps = [];
        $passed = 0;

        $group->product_nature !== null ? $passed++ : $gaps[] = 'لا طبيعة منتج';

        /*
         * المنهج يُطلب للتعليميّ وحده. طلبُه من كلّ منتجٍ يجعل بطاقة أيّ
         * منتجٍ غير تعليميّ حمراء إلى الأبد، فتُهمَل البطاقة كلّها.
         */
        if ($group->subject_id !== null || $group->grade_id !== null) {
            $group->grade_id !== null ? $passed++ : $gaps[] = 'صفٌّ دراسيّ ناقص مع وجود مادّة';
            $group->subject_id !== null ? $passed++ : $gaps[] = 'مادّة ناقصة مع وجود صفّ';
        }

        return [$passed, $gaps];
    }

    /** @return array{0: int, 1: list<string>} */
    private function content(ProductGroup $group): array
    {
        $gaps = [];
        $passed = 0;

        $sections = ProductContentSection::query()
            ->where('product_group_id', $group->id)->pluck('kind')->all();

        /* §٤٩ — الحدّ الأدنى الذي يجعل الصفحة قابلةً للإجابة آليًّا. */
        foreach (['SUMMARY' => 'ملخّص', 'INCLUDED_ITEMS' => 'ما يتضمّنه', 'DELIVERY' => 'كيف يُسلَّم'] as $kind => $label) {
            in_array($kind, $sections, true) ? $passed++ : $gaps[] = "قسم «{$label}» غائب";
        }

        trim((string) $group->short_desc) !== '' ? $passed++ : $gaps[] = 'لا وصف مختصر';

        return [$passed, $gaps];
    }

    /** @return array{0: int, 1: list<string>} */
    private function specification(ProductGroup $group): array
    {
        $gaps = [];
        $passed = 0;

        $group->attributeValues->isNotEmpty()
            ? $passed++
            : $gaps[] = 'لا سمة مكتوبة النوع واحدة';

        /*
         * §٢٧ — الوزن للماديّ وحده، ولأنّ محرّك الشحن يرفض بدونه فعلًا:
         * الوحدة تبقى معلّقةً برسالة «الوزن يُقاس ولا يُقدَّر».
         */
        if ($group->product_nature !== null
            && ProductCapabilities::requiresPhysicalAttributes($group->product_nature)) {
            foreach ($group->variants as $variant) {
                $variant->weight_grams !== null
                    ? $passed++
                    : $gaps[] = "الصيغة {$variant->sku} بلا وزن — الشحن يرفضها";
            }
        }

        return [$passed, $gaps];
    }

    /** @return array{0: int, 1: list<string>} */
    private function fulfillment(ProductGroup $group): array
    {
        $gaps = [];
        $passed = 0;

        foreach ($group->variants as $variant) {
            $offers = $variant->offers;

            if ($offers->isEmpty()) {
                $gaps[] = "الصيغة {$variant->sku} بلا عرض — لا ثمن لها";

                continue;
            }

            $passed++;

            foreach ($offers as $offer) {
                $offer->fulfillments->isEmpty()
                    ? $gaps[] = "العرض {$offer->id} بلا استراتيجيّة تسليم — لا يُنفَّذ"
                    : $passed++;

                $offer->tax_class_id !== null
                    ? $passed++
                    : $gaps[] = "العرض {$offer->id} بلا صنف ضريبة";
            }
        }

        if ($group->variants->isEmpty()) {
            $gaps[] = 'لا صيغة — لا شيء يُسلَّم';
        }

        return [$passed, $gaps];
    }

    /** @return array{0: int, 1: list<string>} */
    private function search(ProductGroup $group): array
    {
        $gaps = [];
        $passed = 0;

        $facetable = AttributeValue::query()
            ->where('product_group_id', $group->id)
            ->whereHas('definition', fn ($q) => $q->where('facetable', true))
            ->count();

        $facetable > 0 ? $passed++ : $gaps[] = 'لا سمة قابلة للتصفية — لا يظهر في أيّ واجهة تصفية';

        trim((string) $group->description) !== '' ? $passed++ : $gaps[] = 'لا وصف مطوّل يُفهرَس';

        return [$passed, $gaps];
    }

    /**
     * §٥٩ §١٦٠ — هل حقائق هذا المنتج قانونيّة أم مرشَّحة؟
     *
     * الهدف الصارم «صفر ترقيةٍ غير مُتحقَّقة إلى القانونيّ» يحتاج قياسًا،
     * وهذا هو: كم قيمةً تعيش على اقتراح نموذجٍ أو استخراج OCR بلا اعتماد.
     */
    private function provenance(ProductGroup $group): array
    {
        $gaps = [];
        $passed = 0;

        foreach ($group->attributeValues as $value) {
            if (in_array($value->source, AttributeValue::CANONICAL_SOURCES, true)) {
                $passed++;

                continue;
            }

            $gaps[] = "سمةٌ مصدرها {$value->source} لم تُعتمد بعد";
        }

        return [$passed, $gaps];
    }
}
