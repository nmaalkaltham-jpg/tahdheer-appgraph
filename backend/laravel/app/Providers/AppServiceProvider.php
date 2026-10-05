<?php

namespace App\Providers;

use App\Domain\Ai\AiCircuitBreaker;
use App\Domain\Ai\Contracts\AiEngine;
use App\Domain\Ai\Engines\LlamaCppEngine;
use App\Domain\Ai\Engines\NullAiEngine;
use App\Domain\Assets\Sources\ConfiguredGoogleDriveCredentials;
use App\Domain\Assets\Sources\GoogleDriveCredentials;
use App\Domain\Catalog\BestSellers;
use App\Domain\Fulfillment\Executors\DigitalDeliveryExecutor;
use App\Domain\Fulfillment\Executors\ExecutorRegistry;
use App\Domain\Fulfillment\Executors\GoogleDriveDeliveryExecutor;
use App\Domain\Fulfillment\Executors\LicenseKeyExecutor;
use App\Domain\Fulfillment\Executors\ShipmentExecutor;
use App\Domain\Navigation\NavigationOutboxSubscriber;
use App\Domain\Operations\ReconcilerRegistry;
use App\Domain\Operations\Reconcilers\AssetVerifyReconciler;
use App\Domain\Operations\Reconcilers\FulfillmentRetryReconciler;
use App\Domain\Operations\Reconcilers\StockMovementReconciler;
use App\Domain\Operations\Reconcilers\WebhookReplayReconciler;
use App\Domain\Payments\TapCredentials;
use App\Domain\Reviews\ReviewOutboxSubscriber;
use App\Domain\Search\Indexing\SearchOutboxSubscriber;
use App\Domain\UrlPlatform\SlugEngine;
use App\Domain\UrlPlatform\UrlOutboxSubscriber;
use App\Domain\Writing\AnthropicWritingAssistant;
use App\Domain\Writing\NullWritingAssistant;
use App\Domain\Writing\WritingAssistantProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SlugEngine::class, fn () => new SlugEngine(
            (int) config('url.slug_min_length', 1),
            (int) config('url.slug_max_length', 80),
        ));
        /*
         * سجلّ الموفّقين: من يعرف كيف يسأل عن أثر كلّ نوع عمليّة.
         *
         * التسجيل صريحٌ هنا لا باكتشافٍ تلقائيّ: موفّقٌ يُضاف بالخطأ يقرّر
         * مصير أثرٍ حقيقيّ، فوجودُه يجب أن يكون قرارًا مكتوبًا.
         */
        // قائمة «الأكثر مبيعًا» تُحسب مرّةً لكلّ طلب ثمّ تُسأل عنها كلّ بطاقة.
        $this->app->singleton(BestSellers::class);

        $this->app->singleton(ReconcilerRegistry::class, static fn ($app) => new ReconcilerRegistry([
            $app->make(StockMovementReconciler::class),
            $app->make(FulfillmentRetryReconciler::class),
            $app->make(AssetVerifyReconciler::class),
            $app->make(WebhookReplayReconciler::class),
        ]));

        /*
         * اعتماد Drive: واجهةٌ واحدة وتنفيذٌ واحد اليوم (رمزٌ من الإعداد).
         * الربط هنا يجعل تبديله بتدفّق تجديدٍ لاحقًا تغييرَ سطرٍ واحد،
         * ولا يعرف المحوّل بالتبديل شيئًا (§١٥).
         */
        $this->app->bind(GoogleDriveCredentials::class, ConfiguredGoogleDriveCredentials::class);

        /*
         * منفّذو التنفيذ. القائمة صريحة لا مُكتشَفة: ترتيبُ التسجيل يقرّر من
         * يتولّى استراتيجيّةً لو ادّعاها منفّذان، والاكتشاف التلقائيّ يجعل
         * ذلك يتبدّل بترتيب الملفّات على القرص.
         */
        $this->app->singleton(ExecutorRegistry::class, static fn ($app) => new ExecutorRegistry([
            $app->make(DigitalDeliveryExecutor::class),
            /*
             * Drive بعد العامّ وقبل غيره: لو ادّعياها معًا فالأخير يغلب،
             * والمقصود أن يغلب المتخصّص. وهو لا يدّعي غيرها، فلا يزاحم أحدًا.
             */
            $app->make(GoogleDriveDeliveryExecutor::class),
            $app->make(LicenseKeyExecutor::class),
            $app->make(ShipmentExecutor::class),
        ]));

        /*
         * المساعد الكتابي: الواجهة تُحلّ إلى المزوّد المضبوط، وإلى المزوّد
         * الرافض حين لا يُضبط شيء. الرفض الصريح أصدق من ردّ مُلفَّق يصل
         * صفحة منتج حقيقية.
         */
        $this->app->bind(WritingAssistantProvider::class, static function (): WritingAssistantProvider {
            $configured = new AnthropicWritingAssistant(
                // trim: مفتاح بمسافة زائدة من ملفّ بيئة يفشل بـ401 غامض.
                apiKey: trim((string) config('services.writing.key')),
                model: trim((string) config('services.writing.model')),
                timeout: (int) config('services.writing.timeout', 30),
            );

            return match ((string) config('services.writing.provider')) {
                // اسمٌ في ملفّ بيئة بلا مفتاح ليس تهيئة: يُردّ إلى الرافض
                // بدل أن يفشل كل نداء بـ401 يُقرأ عطلًا عابرًا.
                'anthropic' => $configured->isConfigured() ? $configured : new NullWritingAssistant,
                default => new NullWritingAssistant,
            };
        });

        /*
         * محرّك الاستدلال المحلّيّ — النمط نفسه: مُهيّأٌ أو رافض.
         *
         * الراية المطفأة تُحلّ إلى الرافض لا إلى محرّكٍ حيّ لا يُنادى: فحصُ
         * الراية عند كلّ مُستدعٍ كان يُنسى في مسارٍ واحد فيُنادى النموذج وهو
         * مطفأ. والحلّ في الحاوية يجعل الإطفاء بنيويًّا.
         *
         * ولا سقوطَ إلى مزوّدٍ خارجيّ مدفوع: محرّكٌ محلّيّ أو لا محرّك.
         */
        $this->app->singleton(AiEngine::class, static function (): AiEngine {
            if (! (bool) config('ai.enabled')) {
                return new NullAiEngine('AI_ENABLED مطفأة.');
            }

            $baseUrl = trim((string) config('ai.engine.base_url'));

            if ($baseUrl === '') {
                return new NullAiEngine('AI_BASE_URL فارغ.');
            }

            return new LlamaCppEngine(
                baseUrl: rtrim($baseUrl, '/'),
                model: trim((string) config('ai.engine.model')),
                connectTimeout: (float) config('ai.engine.connect_timeout'),
            );
        });

        $this->app->singleton(AiCircuitBreaker::class, static fn (): AiCircuitBreaker => new AiCircuitBreaker(
            threshold: (int) config('ai.breaker.failure_threshold'),
            openSeconds: (int) config('ai.breaker.open_seconds'),
        ));
    }

    /**
     * تسجيل مشتركي صندوق الصادر.
     *
     * كلا الصنفين يوثّق في رأسه: «استدعِ ::register() مرّة حيث تُهيّأ
     * المشتركات» — ولم يستدعِهما أحد قطّ، وكانت boot() فارغة. النتيجة أن
     * OutboxRegistry::consumersFor() تُعيد [] لكل نوع حدث، فيُكتب الحدث في
     * الجدول ولا يجد مستهلكًا:
     *
     *   · product.published / product.updated ⇒ فهرس Meilisearch لا يُحدَّث
     *     أبدًا، فمنتج منشور لا يظهر في البحث.
     *   · review.approved ⇒ تجميع التقييمات لا يُعاد بناؤه، فالنجوم تجمد.
     *
     * لا خطأ ولا سطر سجلّ: الحدث يُعالَج «بنجاح» بصفر مستهلك ويُوسَم
     * PROCESSED. أصمت أنواع الأعطال.
     */
    public function boot(): void
    {
        // إعداد Tap المتناقض (وضع تجربة بمفتاح إنتاج أو العكس) يُسقط الإقلاع.
        TapCredentials::assertConsistentAtBoot();

        SearchOutboxSubscriber::register();
        UrlOutboxSubscriber::register();
        NavigationOutboxSubscriber::register();
        ReviewOutboxSubscriber::register();
    }
}
