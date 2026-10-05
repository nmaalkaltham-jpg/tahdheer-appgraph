<?php

declare(strict_types=1);

namespace App\Domain\Ai\Jobs;

use App\Domain\Ai\AiRequestLedger;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Product\ProductAiEnrichment;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إثراء منتجٍ خارج دورة الطلب.
 *
 * ─── لماذا في الطابور ───
 *
 * الاستدلال على معالجٍ بلا مسرّع يستغرق قرابة الثانية للتصنيف وأكثر للكلمات،
 * ونداءان في طلبٍ إداريّ واحد يتجاوزان ميزانيّة الشاشة. «لا تحجب طلب المشرف
 * بعملٍ طويل» قاعدةٌ صريحة، ولذلك تُعيد وحدة التحكّم ٢٠٢ ولا تنتظر.
 *
 * ─── الخمول ───
 *
 * `ShouldBeUnique` على معرّف المنتج: ضغطتان متتاليتان على «حلّل المنتج» لا
 * تُنتجان مهمّتين. والمهلة ٦٦٠ ثانية كما في `ReindexJob` — أطول من أيّ تشغيلٍ
 * معقول، فلا يُحبَس المفتاح بعد موت العامل.
 *
 * ─── وما لا يُعاد ───
 *
 * «الذكاء مطفأ» و«القدرة مطفأة» و«مشغول» ليست أعطالًا تستحقّ محاولةً رابعة:
 * إعادةُ محاولةٍ لميزةٍ مطفأة تملأ الطابور بعملٍ مرفوضٍ سلفًا. تُسجَّل ويُخرَج
 * بهدوء. أمّا السقوط والمهلة فمؤقّتان ويُرمَيان ليُعيدهما الطابور.
 */
class ProductAiEnrichmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** محاولاتٌ محدودة: ما يفشل ثلاثًا يحتاج إنسانًا لا محاولةً رابعة. */
    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 660;

    public function __construct(
        public readonly int $productId,
        public readonly ?int $actorId = null,
        /*
         * صفّ الدفتر الذي حجزه المُجدوِل. وجودُه يعني أنّ للنداء سطرًا مرئيًّا
         * يمكن إلغاؤه قبل أن يبدأ — وغيابُه يبقي المسار القديم عاملًا.
         */
        public readonly ?int $requestId = null,
    ) {
        $this->onQueue((string) config('ai.queue', 'ai'));
    }

    /** تراجعٌ أسّيّ بتشويشٍ — كي لا تصطفّ المحاولات على اللحظة نفسها. */
    public function backoff(): array
    {
        return [10 + random_int(0, 10), 60 + random_int(0, 30), 240 + random_int(0, 60)];
    }

    public function handle(ProductAiEnrichment $enrichment, AiRequestLedger $ledger): void
    {
        /*
         * الإلغاء يُفحص أوّلًا: مهمّةٌ أُلغيت وهي في الطابور يجب ألّا تستهلك
         * مقعد استدلالٍ حين يأتي دورها. والفحص تحديثٌ شرطيّ ذرّيّ لا قراءةٌ
         * ثمّ كتابة.
         */
        if ($this->requestId !== null && ! $ledger->start($this->requestId)) {
            Log::info('ai.product.enrich_cancelled', ['productId' => $this->productId, 'requestId' => $this->requestId]);

            return;
        }

        $product = Product::query()->find($this->productId);

        if ($product === null) {
            // حُذف المنتج بعد الجدولة: لا شيء يُفعل، ولا خطأ يُرمى.
            return;
        }

        try {
            $result = $enrichment->run($product, $this->actorId);

            Log::info('ai.product.enriched', [
                'productId' => $this->productId,
                'result' => $result,
            ]);
        } catch (AiUnavailable $e) {
            /*
             * ─── «مشغول» ليس «مطفأ» ───
             *
             * كُشف في مراجعةٍ خصميّة: كان كلّ ما ليس عطلًا يُبتلع ويُنهى
             * بنجاح. فمهمّةٌ رُدّت `AI_BUSY` — وهي الحالة **المتوقّعة** تحت
             * الحمل بمقعدٍ واحد — كانت تُعلَن ناجحة ولا تُعاد أبدًا: يضغط
             * المشرف «حلّل» فيأخذ ٢٠٢ ولا يصله اقتراحٌ قطّ، بلا أثرٍ يدلّه.
             *
             * فالقرار الإداريّ (مطفأ · قدرة مطفأة · غير مهيّأ) يُنهي بهدوء،
             * والازدحام العابر (مشغول · محدود · دائرة مفتوحة) يُرمى ليُعيده
             * الطابور بتراجعه الأسّيّ.
             */
            $administrative = [
                AiUnavailable::DISABLED,
                AiUnavailable::FEATURE_OFF,
                AiUnavailable::NOT_CONFIGURED,
            ];

            if (in_array($e->reason, $administrative, true)) {
                Log::info('ai.product.enrich_skipped', ['productId' => $this->productId, 'reason' => $e->reason]);

                return;
            }

            if (! $e->isFault()) {
                Log::info('ai.product.enrich_deferred', ['productId' => $this->productId, 'reason' => $e->reason]);

                throw $e;
            }

            Log::warning('ai.product.enrich_failed', ['productId' => $this->productId, 'reason' => $e->reason]);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        /*
         * لا حالةَ تُثبَّت: الإثراء لا يغيّر شيئًا في المنتج، فلا صفّ يبقى
         * «قيد التنفيذ». والأثر محفوظ في `ai_runs` بحالته.
         */
        Log::error('ai.product.enrich_exhausted', [
            'productId' => $this->productId,
            'error' => $e->getMessage(),
        ]);
    }
}
