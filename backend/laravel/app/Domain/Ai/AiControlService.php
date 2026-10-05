<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Models\AiRun;
use App\Domain\Queueing\QueueDepth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * لوحة تحكّم الذكاء — الحالة والأفعال التشغيليّة.
 *
 * ─── لماذا الإيقاف في الذاكرة المؤقّتة لا في ملفّ البيئة ───
 *
 * `AI_ENABLED` قرار نشرٍ يحتاج إعادة تشغيل. والمشغّل الذي يرى الخادم يختنق
 * الساعة الثالثة فجرًا يحتاج زرًّا يعمل الآن. الإيقاف هنا مفتاحٌ مشترك يقرؤه
 * كلّ عامل فورًا، ويبقى بعد إعادة تشغيل العمّال لأنّه في المخزن لا في الذاكرة.
 *
 * ─── والاستئناف لا يُلغي الراية ───
 *
 * الإيقاف طبقةٌ فوق `AI_ENABLED` لا بديلٌ عنها: استئنافُ خدمةٍ رايتُها مطفأة
 * لا يُشغّلها، وإلّا صار زرٌّ في لوحةٍ يتجاوز قرار نشر.
 */
final class AiControlService
{
    private const PAUSED_KEY = 'ai:paused';

    private const CACHE_PREFIX = 'ai:out:';

    public function __construct(
        private readonly AiHealthService $health,
        private readonly AiPolicy $policy,
        private readonly AiCircuitBreaker $breaker,
        private readonly AiPromptRegistry $prompts,
    ) {}

    public function isPaused(): bool
    {
        return Cache::get(self::PAUSED_KEY) !== null;
    }

    /** إيقافٌ بلا مهلة: يبقى حتّى يستأنفه إنسان، ولا يعود وحده بعد ساعة. */
    public function pause(): void
    {
        Cache::forever(self::PAUSED_KEY, true);
    }

    public function resume(): void
    {
        Cache::forget(self::PAUSED_KEY);
        // الاستئناف يُغلق الدائرة أيضًا: المشغّل أصلح السبب وأعلن ذلك بفعله.
        $this->breaker->recordSuccess();
    }

    /**
     * حال المحرّك — كلّ ما تعرضه اللوحة في نداءٍ واحد.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $enabled = (bool) config('ai.enabled');

        return [
            'enabled' => $enabled,
            'paused' => $this->isPaused(),
            'engine' => $this->health->engineName(),
            'configured' => $this->health->isConfigured(),
            /*
             * الصحّة تُقرأ من التخزين القصير لا بفحصٍ حيّ: فتحُ اللوحة لا
             * يجوز أن يضيف رحلةً إلى خدمةٍ مجهدة. وللفحص الحيّ زرّه.
             */
            'healthy' => $enabled ? $this->health->isHealthy() : null,
            'model' => (string) config('ai.engine.model'),
            'context' => (int) config('ai.engine.context'),
            'limits' => [
                'maxConcurrent' => (int) config('ai.limits.max_concurrent'),
                'activeSlots' => $this->policy->activeSlots(),
                'perActorPerMinute' => (int) config('ai.limits.per_actor_per_minute'),
                'maxQueueDepth' => (int) config('ai.limits.max_queue_depth'),
            ],
            'breaker' => [
                'open' => $this->breaker->isOpen(),
                'failures' => $this->breaker->failures(),
                'threshold' => (int) config('ai.breaker.failure_threshold'),
            ],
            'features' => (array) config('ai.features'),
            'prompts' => $this->prompts->ids(),
            'queue' => $this->queueDepth(),
            'usage' => $this->usage(),
            'recentFailures' => $this->recentFailures(),
        ];
    }

    /**
     * عمق الطابور — أو إعلانُ أنّه غير مقيس.
     *
     * سائق Redis لا يُعدّ من قاعدة البيانات. وعرضُ صفرٍ حينها كذبٌ مطمئن
     * يقول «لا شيء عالق» ولا أحد يعرف — النمط نفسه في `SystemController::queue`.
     *
     * @return array<string, mixed>
     */
    private function queueDepth(): array
    {
        /*
         * القارئ المشترك: كانت هنا نسخةٌ ثانية من منطق عدّ Redis، وثالثةٌ في
         * حارس طابور الاقتراحات. ثلاث نسخٍ تجيب السؤال نفسه تتباعد عند أوّل
         * تعديل — وقد تباعدت فعلًا: شاشة النظام كانت تقول «غير مقيس» وهذه
         * تعرض رقمًا.
         *
         * والفرق الوحيد أنّ هذه اللوحة تعنيها طابور الذكاء وحده لا المجموع.
         */
        $queue = (string) config('ai.queue');
        $depth = QueueDepth::of($queue);
        $driver = (string) config('queue.default');

        return $depth === null
            ? ['state' => 'NOT_MEASURABLE', 'driver' => $driver, 'reason' => "سائق «{$driver}» لا يُعدّ، أو تعذّر الوصول إلى مخزنه."]
            : ['state' => 'MEASURED', 'driver' => $driver, 'pending' => $depth];
    }

    /**
     * إحصاء آخر أربعٍ وعشرين ساعة.
     *
     * نسبة النجاح ونسبة الإصابة تُحسبان بمقامٍ مُتحقَّق: صفرُ نداءاتٍ يعني
     * «لا نسبة» لا «صفر بالمئة» — الثاني يُقرأ فشلًا تامًّا.
     *
     * @return array<string, mixed>
     */
    private function usage(): array
    {
        $since = Carbon::now()->subDay();

        $row = AiRun::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where status = 'SUCCEEDED') as succeeded")
            ->selectRaw("count(*) filter (where status = 'FAILED') as failed")
            ->selectRaw("count(*) filter (where status = 'REJECTED') as rejected")
            ->selectRaw('count(*) filter (where cached) as cached')
            /*
             * بلا `coalesce`: المتوسّط له مقامه الخاصّ — النداءات التي استدلّت
             * فعلًا. وكان الصفر يُكتب مكان NULL ثمّ يُحرَس بمقام **آخر**
             * (مجموع النداءات)، فيُعرض «٠ مللي» متى وُجد نداءٌ واحد مخزَّن أو
             * مرفوض ولا استدلال. صفرٌ مختلَق بامتياز.
             */
            ->selectRaw("round(avg(latency_ms) filter (where status = 'SUCCEEDED' and not cached)) as avg_latency")
            ->first();

        $total = (int) ($row->total ?? 0);
        $succeeded = (int) ($row->succeeded ?? 0);
        $cached = (int) ($row->cached ?? 0);

        return [
            'windowHours' => 24,
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => (int) ($row->failed ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'cached' => $cached,
            'successRate' => $total === 0 ? null : round($succeeded / $total * 100, 1),
            'cacheHitRate' => $total === 0 ? null : round($cached / $total * 100, 1),
            'avgLatencyMs' => ($row->avg_latency ?? null) === null ? null : (int) $row->avg_latency,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentFailures(): array
    {
        return AiRun::query()
            ->whereIn('status', ['FAILED', 'REJECTED'])
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'task', 'prompt_id', 'status', 'error_code', 'error_message', 'created_at'])
            ->map(fn (AiRun $run): array => [
                'id' => (int) $run->id,
                'task' => (string) $run->task,
                'promptId' => (string) $run->prompt_id,
                'status' => (string) $run->status,
                'errorCode' => $run->error_code,
                'errorMessage' => $run->error_message,
                'createdAt' => $run->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * مسح المخزون الآمن — مخرجات النموذج وحدها.
     *
     * ─── لماذا بالبادئة لا `Cache::flush()` ───
     *
     * المسح العامّ يمحو الجلسات والثيم والملاحة وكلّ ما في Valkey. زرٌّ في
     * لوحة الذكاء يجب ألّا يُخرج المشرفين من حساباتهم ويُفرّغ ذاكرة المتجر.
     * والسائق الذي لا يدعم المسح بالبادئة يُعلن ذلك ولا يُوسّع الأثر.
     *
     * @return array{cleared: int|null, reason: string|null}
     */
    public function clearOutputCache(): array
    {
        $store = Cache::getStore();

        if (! method_exists($store, 'connection')) {
            return ['cleared' => null, 'reason' => 'سائق التخزين لا يدعم المسح بالبادئة. ولا مسحَ عامًّا — يُخرج المشرفين ويُفرّغ ذاكرة المتجر.'];
        }

        /*
         * ─── بادئتان لا واحدة ───
         *
         * المفتاح الفعليّ في Redis هو:
         *   {database.redis.options.prefix}{cache.prefix}ai:out:…
         *
         * وكُشف في مراجعةٍ خصميّة أنّ المسح كان يبني النمط ببادئة التخزين
         * وحدها، فلا يطابق شيئًا. ثمّ إنّ phpredis يضيف بادئته تلقائيًّا إلى
         * كلّ مفتاحٍ يُمرَّر إلى `del`، بينما `scan` يردّ المفاتيح **كاملةً** —
         * فحذفُ ما يردّه المسحُ كان يُضاعف البادئة ويحذف لا شيء.
         *
         * فالبادئتان تُنزعان ويُحذف بالمفتاح المنطقيّ عبر `Cache::forget`،
         * وهو الطريق الوحيد الذي لا يعرف بادئةَ سائقٍ بعينه.
         */
        $redisPrefix = (string) config('database.redis.options.prefix', '');
        $cachePrefix = (string) config('cache.prefix');
        $pattern = $redisPrefix.$cachePrefix.self::CACHE_PREFIX.'*';

        $connection = $store->connection();
        $cleared = 0;

        try {
            $cursor = null;

            do {
                $scanned = $connection->scan($cursor, ['match' => $pattern, 'count' => 200]);

                if ($scanned === false) {
                    break;
                }

                // predis يردّ [cursor, keys]؛ وphpredis يردّ المفاتيح ويُحدّث المؤشّر بالمرجع.
                if (is_array($scanned) && count($scanned) === 2 && is_array($scanned[1] ?? null)) {
                    [$cursor, $keys] = $scanned;
                } else {
                    $keys = (array) $scanned;
                }

                foreach ($keys as $key) {
                    $logical = (string) $key;

                    foreach ([$redisPrefix, $cachePrefix] as $strip) {
                        if ($strip !== '' && str_starts_with($logical, $strip)) {
                            $logical = substr($logical, strlen($strip));
                        }
                    }

                    if (Cache::forget($logical)) {
                        $cleared++;
                    }
                }
            } while ($cursor !== null && (int) $cursor !== 0);
        } catch (Throwable $e) {
            // الفشل يُقال فشلًا: `cleared: 0` بلا سبب يُقرأ «لا شيء ليُمسح».
            return ['cleared' => null, 'reason' => 'تعذّر المسح: '.$e->getMessage()];
        }

        return ['cleared' => $cleared, 'reason' => null];
    }
}
