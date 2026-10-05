<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Search\Indexing\Indexer;
use App\Domain\Search\Indexing\ReindexJob;
use App\Domain\Search\Models\SearchIndexState;
use App\Domain\Search\ProviderResolver;
use App\Domain\Search\Providers\MeilisearchProvider;
use App\Models\Kind;
use App\Models\Product;
use App\Models\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * حالة فهرس البحث وعملياته.
 *
 * السبب المباشر لوجود هذا الملفّ: عمل الإنتاج على احتياطي PostgreSQL منذ
 * الإطلاق والفهرس لم يُبنَ قطّ — ولا شاشة واحدة كانت تُظهر ذلك. المحرّك سليم،
 * البحث يُجيب 200، وكلّ طلب يمرّ بالمسار الأبطأ صامتًا. هذه الشاشة تجعل
 * «أيّ مسار يخدم البحث الآن؟» سؤالًا له جواب مقروء.
 */
final class SearchIndexController
{
    public function __construct(
        private readonly MeilisearchProvider $provider,
        private readonly ProviderResolver $resolver,
        private readonly AdminAuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $state = SearchIndexState::query()->where('engine', 'meilisearch')->first();
        $engineHealthy = $this->provider->healthy();

        /*
         * التأخّر يُقاس من القاعدة لا من المحرّك: آخر تعديل منتج منشور بعد
         * آخر بناء يعني وثائق قديمة في الفهرس، حتى لو المحرّك «سليمًا».
         */
        $newestChange = Product::query()->published()->max('updated_at');
        $lagSeconds = null;
        if ($state?->last_built_at !== null && $newestChange !== null) {
            $newest = Carbon::parse($newestChange);
            $lagSeconds = $newest->greaterThan($state->last_built_at)
                ? $state->last_built_at->diffInSeconds($newest)
                : 0;
        }

        /*
         * انحراف الكتالوج عن مصطلحات التصنيف: resource_type نصّ حرّ يقارن
         * حرفيًّا في مرشِّح الكيان. «عرض» بدل «عرض بوربوينت» جعل استعلامًا
         * مستخرَجًا بالكامل يعيد صفرًا في الإنتاج. يُكشف هنا ويُصلَح في
         * المنتج — البحث لا يستطيع تعويض كتالوج منحرف بلا حدود.
         */
        $kindSlugs = Kind::query()->pluck('slug')->all();
        $mismatched = Product::query()->published()
            ->whereNotNull('resource_type')
            ->whereNotIn('resource_type', $kindSlugs)
            ->pluck('resource_type', 'sku')
            ->all();

        return response()->json(['data' => [
            'engineHealthy' => $engineHealthy,
            // بلا صفّ حالة: الفهرس لم يُبنَ قطّ — وهذه حقيقة تُعرض لا تُجمَّل.
            'everBuilt' => $state !== null && (int) $state->generation > 0,
            'indexName' => $state?->index_name,
            'generation' => $state === null ? 0 : (int) $state->generation,
            'status' => $state?->status ?? 'never_built',
            'lastBuiltAt' => $state?->last_built_at?->toIso8601String(),
            'publishedProducts' => Product::query()->published()->count(),
            'staleSeconds' => $lagSeconds,
            /*
             * المسار الفعلي الذي سيخدم الطلب القادم، بنفس منطق ProviderResolver
             * حرفيًّا: الاسم المضبوط أوّلًا ثم صحّة المحرّك. المضبوط pg يعني
             * أن المحرّك لن يُحاوَل مهما كان سليمًا — وهذه كانت حالة الإنتاج.
             */
            'configuredProvider' => $this->resolver->configuredName(),
            // sku ⇒ القيمة المنحرفة. فارغة = الكتالوج مطابق للمصطلحات.
            'kindMismatches' => $mismatched,
            'servingPath' => $servingPath = ($this->resolver->configuredName() === 'meilisearch'
                && $engineHealthy
                && $state !== null && (int) $state->generation > 0
                ? 'meilisearch'
                : 'postgres_fallback'),
            'measured' => $measured = $this->measured(),
            /*
             * التنبّؤ يخالف القياس ⇒ تعثّرٌ لا يراه فحصُ الصحّة (استثناءٌ داخل
             * طلبٍ بعينه). null حين لا سطر يُقاس — «لم يُقس» ليست «يطابق».
             */
            'predictionDiverges' => $measured['servedPath'] === null
                ? null
                : $measured['servedPath'] !== ($servingPath === 'meilisearch' ? 'meilisearch' : 'pg'),
        ]]);
    }

    /** نافذةُ القياس بالساعات. */
    private const MEASURED_WINDOW_HOURS = 24;

    /** حصّةُ مزوّدٍ واحد تجعله «ما خدم»؛ دونها المسار «مختلط». */
    private const DOMINANT_SHARE = 0.9;

    /**
     * ما خدم البحث فعلًا في النافذة — من `search_queries` (يكتبه `QueryPipeline::run`).
     *
     * أسطرُ `unknown` كُتبت قبل وجود التتبّع: تُعدّ في `byProvider` و`unmeasuredRows`
     * ولا تدخل أيّ نسبة. وكلُّ نسبةٍ null حين لا سطر مقيس — لا صفرٌ يُقرأ سلامة.
     *
     * @return array<string, mixed>
     */
    private function measured(): array
    {
        $rows = SearchQuery::query()->where('created_at', '>=', now()->subHours(self::MEASURED_WINDOW_HOURS));

        $byProvider = (clone $rows)->selectRaw('provider, count(*) as n')->groupBy('provider')->pluck('n', 'provider')
            ->map(fn ($n): int => (int) $n)->sortKeys()->all();
        $measured = (clone $rows)->where('provider', '!=', 'unknown');
        $total = (clone $measured)->count();
        $degraded = $total === 0 ? null : (clone $measured)->where('degraded', true)->count();
        $reasons = (clone $measured)->where('degraded', true)->whereNotNull('degraded_reason')
            ->selectRaw('degraded_reason, count(*) as n')->groupBy('degraded_reason')->pluck('n', 'degraded_reason')
            ->map(fn ($n): int => (int) $n)->all();
        $latency = $total === 0 ? null : (clone $measured)->whereNotNull('latency_ms')->toBase()
            ->selectRaw('percentile_cont(0.5) within group (order by latency_ms) as p50, percentile_cont(0.95) within group (order by latency_ms) as p95')
            ->first();

        $served = null;
        if ($total > 0) {
            $counts = array_diff_key($byProvider, ['unknown' => 0]);
            arsort($counts);
            $top = array_key_first($counts);
            $served = $counts[$top] / $total >= self::DOMINANT_SHARE ? $top : 'mixed';
        }

        return [
            'windowHours' => self::MEASURED_WINDOW_HOURS,
            'totalQueries' => $total,
            'unmeasuredRows' => $byProvider['unknown'] ?? 0,
            'byProvider' => $byProvider,
            'degradedQueries' => $degraded,
            'degradedRate' => $degraded === null ? null : round($degraded / $total, 4),
            'reasons' => $reasons,
            'latencyP50Ms' => $latency?->p50 === null ? null : (int) round((float) $latency->p50),
            'latencyP95Ms' => $latency?->p95 === null ? null : (int) round((float) $latency->p95),
            'servedPath' => $served,
        ];
    }

    public function reindex(Request $request): JsonResponse
    {
        /*
         * بناء حيّ ⇒ 409 لا 202: قفل ShouldBeUnique يُسقط الإرسال الثاني
         * بصمت، فكانت الشاشة تقول «بدأت» وتكتب سطر تدقيق لعمل لم يُقبل.
         */
        $state = SearchIndexState::query()->where('engine', 'meilisearch')->first();
        if ($state !== null
            && $state->status === 'building'
            && $state->updated_at !== null
            && $state->updated_at->greaterThan(now()->subSeconds(660))) {
            return response()->json([
                'message' => 'بناء فهرس جارٍ بالفعل — تابع حالته من هذه الشاشة.',
                'code' => 'REINDEX_IN_PROGRESS',
            ], 409);
        }

        ReindexJob::dispatch();

        $this->audit->log(
            $this->actor(), 'search.index.reindex_requested', 'search_index', 0,
            null, null, null, $request,
        );

        // 202: قُبل الطلب والبناء في الخلفية — الحالة تُتابَع من show.
        return response()->json(['message' => 'بدأت إعادة الفهرسة في الخلفية. تابع الحالة من هذه الشاشة.'], 202);
    }

    public function rollback(Request $request, Indexer $indexer): JsonResponse
    {
        $out = $indexer->rollback();

        $this->audit->log(
            $this->actor(), 'search.index.rolled_back', 'search_index', 0,
            null, $out, null, $request,
        );

        return response()->json(['data' => $out]);
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
