<?php

declare(strict_types=1);

namespace App\Domain\Search\Explain;

use App\Domain\Search\ArabicNormalizer;
use App\Domain\Search\ProviderResolver;
use App\Domain\Search\Providers\PgFallbackProvider;
use App\Domain\Search\QueryPipeline;
use App\Domain\Search\SearchRequest;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * مُفسِّر الترتيب (§٣٧ و§١٥٠) — «لماذا ظهر هذا؟ ولماذا هو فوق ذاك؟».
 *
 * ─── القاعدة التي يقوم عليها الملفّ كلّه ───
 *
 * **لا شرطَ مطابقةٍ واحد مكتوبٌ هنا.** كلّ جملةٍ يقولها هذا المفسِّر عن حقلٍ
 * أو كلمةٍ مقيسةٌ بتشغيل دالّة الإنتاج نفسها مرّتين: مرّةً كما هي، ومرّةً بعد
 * إزالة الإشارة المسؤول عنها. الفرق بين الدرجتين **هو** الجواب.
 *
 * وهذا ليس أناقةً: كتابة «العنوان يبدأ بالاستعلام ⇒ ٦٠٠» هنا تعني نسختين من
 * المنطق. النسخة الثانية تبقى صحيحةً حتى أوّل تعديلٍ في الأولى، ثمّ تصير
 * شاشةَ تصحيحٍ **تكذب بثقة** — وهي أسوأ من غياب الشاشة: الغياب يُبقي المشغّل
 * جاهلًا، والكذب يبني عنده نموذجًا خاطئًا يقرّر على أساسه.
 *
 * فالإسناد هنا **استئصالٌ مقيس**: احذف الحقل، أعد التشغيل، اقرأ الهبوط.
 *
 * ─── وحدات الاستئصال تتبع ما تقرؤه الدالّة، لا أعمدة الجدول ───
 *
 * `score()` تبني «الجسد» من `subtitle` و`short_desc` معًا
 * (`Providers/PgFallbackProvider.php:216`). فاستئصال أحدهما وحده لا يُسقط
 * طبقة الجسد ما دام الآخر يطابق — والمفسِّر حينها يعجز عن تسمية الحقل الحاسم
 * ويرفع رايةَ انحراف كاذبة. الوحدات أربع لأنّ الطبقات تُنتَج من أربع، وتفصيلُ
 * العمودين يُعرض تحت وحدته لا بدلًا منها.
 */
final class RelevanceExplainer
{
    /**
     * وحدات الإشارة الأربع — بترتيب قراءة `score()` نفسه.
     *
     * @var list<array{unit: string, label: string, columns: list<string>, digits: bool}>
     */
    private const UNITS = [
        ['unit' => 'sku', 'label' => 'رمز المنتج', 'columns' => ['sku'], 'digits' => true],
        ['unit' => 'title', 'label' => 'العنوان', 'columns' => ['title'], 'digits' => false],
        ['unit' => 'body', 'label' => 'العنوان الفرعي + الوصف المختصر', 'columns' => ['subtitle', 'short_desc'], 'digits' => false],
        ['unit' => 'description', 'label' => 'الوصف الكامل', 'columns' => ['description'], 'digits' => false],
    ];

    public function __construct(
        private readonly QueryPipeline $pipeline,
        private readonly ProviderResolver $resolver,
        private readonly ScoreProbe $probe,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function explain(string $raw, string $sort = 'relevance', int $limit = 10): array
    {
        $serving = $this->resolver->resolve();
        $explainable = $serving instanceof PgFallbackProvider;

        $run = $this->runWithoutLogging($raw, $sort);

        /** @var SearchRequest $request */
        $request = $run['request'];
        $items = $run['result']->items->take($limit);

        $lexical = $request->query;

        $results = [];
        $rank = 0;
        foreach ($items as $product) {
            $rank++;
            $results[] = $explainable && $lexical !== ''
                ? $this->explainProduct($product, $request, $rank)
                : $this->unscored($product, $rank, $explainable, $lexical);
        }

        return [
            'query' => [
                'raw' => $run['raw'],
                'normalized' => $run['normalized'],
                'lexical' => $lexical,
                'tokens' => $request->allTokens(),
                'expandedTokens' => $request->expandedTokens,
                'filtersExtracted' => array_filter($run['filters'], static fn ($v): bool => $v !== null),
                'relaxed' => $run['relaxed'],
            ],
            'provider' => [
                'configured' => $this->resolver->configuredName(),
                'serving' => $serving::class,
                'explainable' => $explainable,
                /*
                 * المفسِّر يعرف طبقاتِ `PgFallbackProvider` وحدها. لو خدم
                 * Meilisearch الطلبَ فالدرجات تخرج من محرّكٍ خارجيّ بقواعده،
                 * وعرضُ طبقاتنا فوقها يكون اختلاقًا كاملًا — فتُحجب الدرجات
                 * ويُقال السبب، ولا تُخمَّن.
                 */
                'reason' => $explainable ? null : 'المسار الخادم ليس PgFallbackProvider — طبقاته ليست طبقات هذا المفسِّر، فالدرجات محجوبة.',
            ],
            'tiers' => $this->probe->tiers(),
            'sort' => $run['sort'],
            'total' => $run['result']->total,
            'shown' => count($results),
            'results' => $results,
            'notFactors' => $this->notFactors($run['sort']),
        ];
    }

    /**
     * تشغيل خطّ الأنابيب الحقيقيّ ثمّ التراجع عن أثره الكتابيّ الوحيد.
     *
     * `QueryPipeline::run()` يكتب صفًّا في `search_queries` بلا شرط
     * (`QueryPipeline.php:290`)، وذاك الجدول هو مصدر «الاستعلامات الأكثر»
     * في شاشة التحليلات وفي إنقاذ صفر النتائج. مشغّلٌ يفحص «بوربوينت» خمسين
     * مرّةً في هذه الشاشة كان سيصنع طلبًا لم يطلبه زائرٌ واحد.
     *
     * والبديل المرفوض: أن نبني `SearchRequest` هنا بأيدينا (تطبيعٌ واستخراجُ
     * كياناتٍ وتوسيعُ مرادفات). ذاك يتفادى الكتابة ويُدخل نسخةً ثانية من
     * تحضير الاستعلام — أي بالضبط التباعدَ الذي يقوم هذا الملفّ على منعه.
     * فالمعاملة تُفتح وتُلغى: المسار حقيقيّ، والأثر صفر.
     *
     * @return array<string, mixed>
     */
    private function runWithoutLogging(string $raw, string $sort): array
    {
        DB::beginTransaction();

        try {
            return $this->pipeline->run($raw, 'all', $sort);
        } finally {
            try {
                DB::rollBack();
            } catch (Throwable) {
                // المعاملة سقطت أصلًا مع الاستثناء الأصليّ — ولا يُبتلع الأوّل بالثاني.
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function explainProduct(Product $product, SearchRequest $request, int $rank): array
    {
        $score = $this->probe->score($product, $request);
        $tier = $this->probe->tierFor($score);

        $effects = [];
        $decisive = null;
        foreach (self::UNITS as $unit) {
            $without = $this->probe->score($this->blank($product, $unit['columns']), $request);
            $text = $this->unitText($product, $unit['columns']);

            $effects[] = [
                'unit' => $unit['unit'],
                'parent' => null,
                'label' => $unit['label'],
                'columns' => $unit['columns'],
                'text' => $text,
                'normalized' => $this->normalizeAs($text, $unit['digits']),
                'scoreWithout' => $without,
                'delta' => $score - $without,
                /*
                 * «حاسم» تعني: بإزالته تسقط الدرجة تحت أرضيّة طبقتها — أي هو
                 * الحقل الذي أنتج الطبقة. وهذا أضيق من «طابَق»: حقلٌ يطابق
                 * طبقةً أدنى من المُحقَّقة أثرُه صفر، وقولُ «طابَق» عنه يُوهم
                 * بأنّه رفع الترتيب وهو لم يرفعه.
                 */
                'decisive' => $tier !== null && $without < $tier['value'],
            ];

            if ($tier !== null && $without < $tier['value'] && $decisive === null) {
                $decisive = $unit['unit'];
            }
        }

        // تفصيل العمودين داخل وحدة الجسد — تحتها لا بدلًا منها.
        foreach (['subtitle', 'short_desc'] as $column) {
            $text = (string) ($product->{$column} ?? '');
            if ($text === '') {
                continue;
            }

            $without = $this->probe->score($this->blank($product, [$column]), $request);
            $effects[] = [
                'unit' => $column,
                'parent' => 'body',
                'label' => $column === 'subtitle' ? 'العنوان الفرعي' : 'الوصف المختصر',
                'columns' => [$column],
                'text' => $text,
                'normalized' => ArabicNormalizer::normalize($text),
                'scoreWithout' => $without,
                'delta' => $score - $without,
                'decisive' => false,
            ];
        }

        return [
            'rank' => $rank,
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'title' => (string) $product->title,
            'publishedAt' => $product->published_at?->toIso8601String(),
            'score' => $score,
            'tier' => $tier,
            // الفائض فوق أرضيّة الطبقة — مكافأة التغطية حين تكون الطبقة تحتملها.
            'aboveTierFloor' => $tier === null ? null : $score - $tier['value'],
            'decisiveUnit' => $decisive,
            /*
             * رايةُ انحراف: كلّ طبقةٍ يُنتجها حقلٌ واحد، فلا بدّ أن يوجد
             * استئصالٌ يُسقط الدرجة تحت أرضيّتها. غيابُه يعني أنّ وحداتنا لم
             * تعد تصف ما تقرؤه `score()` — فيُعلَن العجز ولا تُروى قصّة.
             */
            'unattributed' => $tier !== null && $decisive === null,
            'fieldEffects' => $effects,
            'tokenEffects' => $this->tokenEffects($product, $request, $score),
            'tiersNotReached' => array_values(array_filter(
                $this->probe->tiers(),
                static fn (array $t): bool => $t['value'] > $score,
            )),
        ];
    }

    /**
     * أثر كلّ كلمة — بإزالتها من الاستعلام ومن المرادفات معًا.
     *
     * الإزالة من طرفٍ واحد كانت تُعطي فرقًا صفرًا لكلمةٍ يحملها الطرفان، فتبدو
     * الكلمة بلا أثر وهي التي رفعت الترتيب.
     *
     * @return list<array<string, mixed>>
     */
    private function tokenEffects(Product $product, SearchRequest $request, int $score): array
    {
        $queryTokens = $request->query === '' ? [] : (preg_split('/\s+/u', $request->query) ?: []);
        $out = [];

        foreach ($request->allTokens() as $token) {
            $remaining = array_values(array_filter($queryTokens, static fn (string $t): bool => $t !== $token));
            $newQuery = implode(' ', $remaining);

            $entry = [
                'token' => $token,
                'source' => in_array($token, $queryTokens, true) ? 'query' : 'synonym',
            ];

            if ($newQuery === '') {
                /*
                 * استعلامٌ فارغ لا يُقاس: `search()` لا تُنادي `score()` أصلًا
                 * حين يفرغ الاستعلام (`PgFallbackProvider.php:63`)، ومناداتها
                 * بفراغٍ تمرّ في `str_contains($sku, '')` فتردّ ٧٠٠ لكلّ منتج.
                 * رقمٌ من مسارٍ لا يقع في الإنتاج، فلا يُعرض بل يُعلَن سببه.
                 */
                $entry['scoreWithout'] = null;
                $entry['delta'] = null;
                $entry['note'] = 'إزالتها تُفرغ الاستعلام — والمسار الحيّ لا يُقيّم استعلامًا فارغًا، فلا قياس.';
                $out[] = $entry;

                continue;
            }

            $without = $this->probe->score($product, new SearchRequest(
                query: $newQuery,
                rawQuery: $request->rawQuery,
                filters: $request->filters,
                editable: $request->editable,
                expandedTokens: array_values(array_filter(
                    $request->expandedTokens,
                    static fn (string $t): bool => $t !== $token,
                )),
                typeTab: $request->typeTab,
                sort: $request->sort,
                page: $request->page,
                perPage: $request->perPage,
            ));

            $entry['scoreWithout'] = $without;
            $entry['delta'] = $score - $without;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * نسخةٌ من المنتج بأعمدةٍ مفرَّغة — للقياس وحده، ولا تُحفظ أبدًا.
     *
     * `''` لا `null`: `score()` تمرّر العنوان إلى `normalize(string $text)`،
     * والملفّ بلا `strict_types` فـ`null` تمرّ بإهمالٍ مُعلَن يملأ السجلّ.
     *
     * @param  list<string>  $columns
     */
    private function blank(Product $product, array $columns): Product
    {
        $copy = clone $product;

        foreach ($columns as $column) {
            $copy->setAttribute($column, '');
        }

        return $copy;
    }

    /** @param list<string> $columns */
    private function unitText(Product $product, array $columns): string
    {
        return trim(implode(' ', array_map(
            static fn (string $c): string => (string) ($product->{$c} ?? ''),
            $columns,
        )));
    }

    private function normalizeAs(string $text, bool $digitsOnly): string
    {
        return $digitsOnly
            ? mb_strtolower(ArabicNormalizer::normalizeDigits($text), 'UTF-8')
            : ArabicNormalizer::normalize($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function unscored(Product $product, int $rank, bool $explainable, string $lexical): array
    {
        return [
            'rank' => $rank,
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'title' => (string) $product->title,
            'publishedAt' => $product->published_at?->toIso8601String(),
            'score' => null,
            'tier' => null,
            'aboveTierFloor' => null,
            'decisiveUnit' => null,
            'unattributed' => false,
            'fieldEffects' => [],
            'tokenEffects' => [],
            'tiersNotReached' => [],
            'reason' => $explainable
                ? 'لا استعلام معجميّ بعد استخراج الكيانات — الترتيب هنا بتاريخ النشر تنازليًّا، لا بدرجة تطابق.'
                : 'المسار الخادم ليس PgFallbackProvider.',
        ];
    }

    /**
     * ما **لا** يدخل الترتيب — يُقال صراحةً لا يُسكَت عنه.
     *
     * مصحّحٌ يعرض البُعد الذي يقيسه ويُخفي البُعد الغائب يُعلّم نموذجًا خاطئًا:
     * المشغّل يرى «كلمة في العنوان» فيستنتج أنّ ما لا يراه موجودٌ في مكانٍ ما
     * ويعمل على أساسه. الأرقام هنا تُقاس عند كلّ طلب، فلا رقمٌ مكتوبٌ باليد
     * يشيخ في الملفّ.
     *
     * @return list<array<string, mixed>>
     */
    private function notFactors(string $sort): array
    {
        $queries = (int) DB::table('search_queries')->count();
        $clicked = (int) DB::table('search_queries')->whereNotNull('clicked_product_id')->count();

        return [
            [
                'key' => 'semantic',
                'label' => 'التشابه الدلاليّ (المتّجهات/التضمين)',
                'status' => 'absent',
                'detail' => 'لا إشارة دلاليّة في النظام أصلًا: البحث عن embedding وvector وsemantic في app وconfig وdatabase وroutes يعود بصفر نتيجة. '
                    .'والدليل الحيّ في الجدول أعلاه: مدخلات `score()` هي وحدات النصّ الأربع وحدها.',
            ],
            [
                'key' => 'conversion_score',
                'label' => 'درجة التحويل (conversion_score)',
                'status' => 'declared_unfed',
                'detail' => 'معلَنةٌ قاعدةَ ترتيبٍ في Meilisearch عند Providers/MeilisearchProvider.php:179، '
                    .'ولا مُنتِج لها: ProductSearchDocument::toArray لا يكتب هذا المفتاح. '
                    .'ولا أثر لها هنا على أيّ حال — المسار الخادم هو PostgreSQL.',
            ],
            [
                'key' => 'clicks',
                'label' => 'النقر والسلوك',
                'status' => 'recorded_unfed',
                'detail' => "يُسجَّل ولا يُغذّي الترتيب: {$queries} استعلامًا مسجَّلًا، منها {$clicked} بنقرةٍ مربوطة. "
                    .'و`score()` لا تقرأ جدول الاستعلامات ولا النقرات، والترتيب الافتراضيّ يفصل عند تساوي الدرجة بتاريخ النشر لا بالنقر.',
            ],
            [
                'key' => 'ratings_sales',
                'label' => 'التقييم والمبيعات',
                'status' => $sort === 'bestselling' ? 'active_as_sort' : 'not_in_relevance',
                'detail' => $sort === 'bestselling'
                    ? 'الفرز الحاليّ «الأكثر مبيعًا»: الترتيب بعدد الاستحقاقات الحقيقيّة أوّلًا ثمّ بالدرجة — فالطبقات هنا مفتاحٌ ثانٍ لا أوّل.'
                    : 'خارج الصلة تمامًا: `score()` لا تقرأ تقييمًا ولا مبيعات، وهما لا يدخلان إلّا فرزَ «الأكثر مبيعًا» وحده.',
            ],
        ];
    }
}
