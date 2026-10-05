<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\ProductCapabilities;
use App\Domain\Search\QueryPipeline;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/search — delegates to the QueryPipeline (MIP Part 6) and
 * returns the pre-decided contract shape. Facet tab counts are computed from
 * the actual filtered result set — never hardcoded, never stale.
 */
class SearchController extends Controller
{
    /** Type tabs evidenced by the Search Results design: الكل/عروض/PDF/Word. */
    private const TYPE_LABELS = ['PPTX' => 'عروض', 'PDF' => 'PDF', 'DOCX' => 'Word'];

    public function __invoke(Request $request, QueryPipeline $pipeline): JsonResponse
    {
        /*
         * اختيارٌ متعدّد: `subject[]=أ&subject[]=ب` («أو» داخل البُعد، «و» بين
         * الأبعاد). القيمة المفردة تبقى نصًّا كما كانت — روابطُ محفوظة لا تنكسر.
         */
        foreach (QueryPipeline::MULTI_SELECT as $dim) {
            if (is_string($request->query($dim))) {
                $request->merge([$dim => [$request->query($dim)]]);
            }
        }

        $validated = $request->validate([
            // Browse surfaces (a stage page, a facet click) carry filters and no
            // text — requiring `q` there made category browsing impossible.
            'q' => 'nullable|string|max:200',
            'stage' => 'nullable|array|max:10',
            /*
             * الصف بُعدٌ كامل في الفهرس والمزوّدين ومستخرج الكيانات، وكان
             * الوحيد الذي لا يقبله هذا الباب — فكان `?grade=` يُسقَط صامتًا
             * وتُعاد النتائج كاملةً وكأن المرشِّح طُبِّق. أرشيف الصفوف يقوم
             * عليه.
             */
            'grade' => 'nullable|array|max:10',
            'subject' => 'nullable|array|max:10',
            'semester' => 'nullable|array|max:10',
            'kind' => 'nullable|array|max:10',
            'stage.*' => 'string|max:120',
            'grade.*' => 'string|max:120',
            'subject.*' => 'string|max:120',
            'semester.*' => 'string|max:120',
            'kind.*' => 'string|max:120',
            'type' => 'sometimes|string|in:all,pptx,pdf,docx',
            /*
             * §٦ — بُعدُ **نوع المنتج**، باسمٍ جديد لا بتوسيع `type`.
             *
             * `type` تعني منذ أوّل يوم امتداد الملفّ (عروض · PDF · Word)، ولها
             * مستهلكون أحياء في المتجر وفي عناوين قابلة للمشاركة. وتوسيعُها
             * لتعني «ماديّ/رقميّ» كان يجعل رابطًا محفوظًا يعني شيئًا آخر بعد
             * النشر — وهو كسرٌ صامت لا يُرى في أيّ اختبار.
             *
             * فالبُعد الجديد `nature`، وقيمُه من سجلّ القدرات لا مكتوبةً هنا.
             */
            'nature' => ['sometimes', 'string', Rule::in(array_map('mb_strtolower', ProductCapabilities::NATURES))],
            'sort' => 'sometimes|string|in:relevance,bestselling,newest,price_asc,price_desc',
            'page' => 'sometimes|integer|min:1|max:1000',
        ]);

        $out = $pipeline->run(
            raw: (string) ($validated['q'] ?? ''),
            typeTab: $validated['type'] ?? 'all',
            sort: $validated['sort'] ?? 'relevance',
            page: (int) ($validated['page'] ?? 1),
            explicitFilters: [
                'stage' => $this->chosen($validated['stage'] ?? null),
                'grade' => $this->chosen($validated['grade'] ?? null),
                'subject' => $this->chosen($validated['subject'] ?? null),
                'semester' => $this->chosen($validated['semester'] ?? null),
                'kind' => $this->chosen($validated['kind'] ?? null),
                /*
                 * يُرفع إلى الأحرف الكبيرة **هنا مرّةً واحدة**، فلا يُعيد كلّ
                 * مزوّدٍ حسمَ الحالة بنفسه كما يقع مع `format` اليوم.
                 */
                'nature' => isset($validated['nature']) ? mb_strtoupper($validated['nature']) : null,
            ],
            /* بلا نصّ = تصفّحٌ بالأوجه؛ يُفصل في القياس عن البحث المكتوب. */
            context: ['source' => trim((string) ($validated['q'] ?? '')) === '' ? 'browse' : 'search'],
        );

        $result = $out['result'];

        $types = [['key' => 'all', 'label' => 'الكل', 'count' => $result->totalUnfiltered]];
        foreach (self::TYPE_LABELS as $format => $label) {
            $count = (int) ($result->formatCounts[$format] ?? 0);
            if ($count > 0) {
                $types[] = ['key' => strtolower($format), 'label' => $label, 'count' => $count];
            }
        }

        return response()->json([
            'query' => [
                'raw' => $out['raw'],
                'normalized' => $out['normalized'],
                'correction' => $out['correction'],
            ],
            'filtersExtracted' => $out['filters'],
            'results' => [
                'items' => ProductCardResource::collection($result->items)->resolve($request),
                'total' => $result->total,
                'page' => $out['page'],
                'perPage' => $out['request']->perPage,
            ],
            /*
             * الطبقة الثانية — بعد النتائج المطابقة لا بينها، مجموعاتٍ مُسمّاة
             * بسبب قربها (`RelatedResults`). فارغةٌ حين تكفي النتائج.
             */
            'related' => [
                'groups' => array_map(fn (array $g): array => [
                    'reason' => $g['reason'],
                    'label' => $g['label'],
                    'items' => ProductCardResource::collection($g['items'])->resolve($request),
                ], $out['related']),
            ],
            'facets' => [
                'types' => $types,
                /*
                 * توزيعات التصنيف بأعداد حقيقية على المجموعة المرشَّحة
                 * الحالية. القيمة هي المصطلح كما فُهرس (اسم عربي)، وهي نفسها
                 * قيمة المرشِّح المقبولة في stage/subject/semester أعلاه.
                 */
                'taxonomy' => $this->taxonomyFacets($result->facetCounts),
                /*
                 * §٦ — توزيع أنواع المنتج. يغيب حين لا يوجد ما يُختار بينه:
                 * مرشِّحٌ بخيارٍ واحد ليس اختيارًا، وعرضُه يُوهم بتصفيةٍ لا
                 * تُغيّر شيئًا.
                 */
                'natures' => $this->natureFacets($result->facetCounts),
            ],
            'sort' => $out['sort'],
            'zeroResult' => $out['zeroResult'],
            'recovery' => $out['recovery'],
            'meta' => [
                // يربط نقرة النتيجة اللاحقة بهذا الاستعلام في POST /search/click.
                'queryId' => $out['queryId'],
                'clickToken' => $out['clickToken'],
                // المرشّحات التي أُسقطت لإنقاذ صفر نتائج — تُعرض لا تُخفى.
                'relaxed' => $out['relaxed'],
            ],
        ]);
    }

    /** ترتيب الأبعاد وتسمياتها ثابتان؛ الأعداد من المزوّد وحده. */
    private const TAXONOMY_LABELS = [
        'stage' => 'المرحلة',
        'grade' => 'الصف',
        'subject' => 'المادة',
        'kind' => 'نوع الملف',
        'semester' => 'الفصل',
    ];

    /**
     * @param  array<string, array<string, int>>  $facetCounts
     * @return list<array{key: string, label: string, values: list<array{value: string, count: int}>}>
     */
    /**
     * توزيع أنواع المنتج، بترتيب المفردة لا بترتيب الأعداد.
     *
     * الترتيب ثابتٌ عمدًا: الأنواع مفردةٌ مغلقة، وتبديلُ مواضعها بين استعلامٍ
     * وآخر بحسب الأعداد يجعل المرشِّح يقفز تحت إصبع الزائر. والتصنيف يُرتَّب
     * بالأعداد لأنّ مصطلحاته مفتوحةٌ وكثيرة.
     *
     * @param  array<string, array<string, int>>  $facetCounts
     * @return list<array{key: string, label: string, count: int}>
     */
    private function natureFacets(array $facetCounts): array
    {
        $counts = $facetCounts['nature'] ?? [];

        $out = [];
        foreach (ProductCapabilities::NATURES as $nature) {
            $count = (int) ($counts[$nature] ?? 0);

            if ($count > 0) {
                $out[] = [
                    'key' => $nature,
                    'label' => ProductCapabilities::NATURE_LABELS[$nature],
                    'count' => $count,
                ];
            }
        }

        // نوعٌ واحد ⇒ لا اختيار. تُعاد قائمةٌ فارغة والواجهة لا ترسم شيئًا.
        return count($out) < 2 ? [] : $out;
    }

    private function taxonomyFacets(array $facetCounts): array
    {
        $out = [];
        foreach (self::TAXONOMY_LABELS as $key => $label) {
            $values = $facetCounts[$key] ?? [];
            if ($values === []) {
                continue;
            }
            arsort($values);

            $out[] = [
                'key' => $key,
                'label' => $label,
                'values' => array_map(
                    static fn (string $value): array => ['value' => $value, 'count' => (int) $values[$value]],
                    array_keys($values),
                ),
            ];
        }

        return $out;
    }

    /**
     * قيمةٌ واحدة ⇐ نصّ (السلوك القديم حرفيًّا)، وأكثر ⇐ قائمةٌ فريدة، ولا شيء ⇐ null.
     *
     * @param  list<string|null>|null  $values
     * @return string|list<string>|null
     */
    private function chosen(?array $values): string|array|null
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $values ?? [],
        ), static fn (string $v): bool => $v !== '')));

        return match (count($values)) {
            0 => null,
            1 => $values[0],
            default => $values,
        };
    }
}
