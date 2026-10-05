<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\OrderFinalizer;
use App\Providers\RateLimitServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * تصلّب الـAPI — كل اختبار هنا يثبّت إصلاح عطل وُجد بدليل تشغيلي.
 */
class ApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * الخنق يردّ 429 بعقد الأخطاء نفسه، لا بشكل ثانٍ.
     *
     * الافتراضي في Laravel نصّ إنجليزي بلا `code`، فلا تستطيع الواجهة تمييزه
     * عن خطأ آخر. والأهمّ: معالج الاستثناءات الجديد كان يبتلع
     * HttpResponseException — وهي التي يحمل بها وسيط الخنق ردّه — فيحوّل كل
     * تجاوز حدّ إلى 500. عطلٌ كان سيظهر عند أوّل فيض حقيقي في الإنتاج فقط.
     */
    public function test_rate_limit_returns_the_shared_error_contract(): void
    {
        $limit = RateLimitServiceProvider::LIMITS['telemetry'];

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/not-found', ['path' => '/x-'.$i])->assertStatus(202);
        }

        $this->postJson('/api/v1/not-found', ['path' => '/over'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED')
            ->assertJsonStructure(['message', 'code', 'retryAfter'])
            ->assertHeader('Retry-After');
    }

    /**
     * انتهاك التفرّد وحده يُبتلَع؛ أي خطأ قاعدة بيانات آخر يُعاد رميه.
     *
     * الالتقاط الواسع كان يعامل الجمود وفشل التسلسل وانقطاع الاتصال معاملة
     * «تسليم مكرّر»: تتراجع المعاملة ويردّ الـwebhook بالنجاح، فتتوقّف
     * البوّابة عن إعادة المحاولة — مال مقبوض وطلب لا يُموَّل أبدًا.
     */
    public function test_only_a_unique_violation_counts_as_a_duplicate_delivery(): void
    {
        $method = new ReflectionMethod(OrderFinalizer::class, 'isUniqueViolation');

        $make = static fn (string $sqlstate, string $message): QueryException => new QueryException(
            'pgsql', 'insert into outbox_events …', [], new PDOException($message, 0),
        );

        // انتهاك تفرّد حقيقي (PostgreSQL) ⇒ يُبتلَع.
        $unique = $make('23505', 'SQLSTATE[23505]: unique_violation');
        $reflected = new \ReflectionProperty(QueryException::class, 'code');
        $reflected->setValue($unique, '23505');
        $this->assertTrue($method->invoke(app(OrderFinalizer::class), $unique));

        // جمود ⇒ لا يُبتلَع، بل يُعاد رميه فتعيد البوّابة المحاولة.
        $deadlock = $make('40P01', 'SQLSTATE[40P01]: deadlock_detected');
        $reflected->setValue($deadlock, '40P01');
        $this->assertFalse($method->invoke(app(OrderFinalizer::class), $deadlock));

        // انقطاع اتصال ⇒ لا يُبتلَع.
        $gone = $make('08006', 'SQLSTATE[08006]: connection_failure');
        $reflected->setValue($gone, '08006');
        $this->assertFalse($method->invoke(app(OrderFinalizer::class), $gone));
    }

    /**
     * لا مفتاح إعداد يُقرأ بـ env() في وقت الطلب.
     *
     * `php artisan config:cache` — وهو المعتاد في الإنتاج — يجعل env() تُعيد
     * null. سبعة مفاتيح كانت تعتمد عليها: أسرار Tap (فتُرفض كل إشعارات
     * الدفع) ومضيف Meilisearch ومزوّد الدفع.
     */
    public function test_every_referenced_service_key_exists_in_config(): void
    {
        foreach ([
            'services.payments.provider',
            'services.tap.base_url',
            'services.tap.secret_key',
            // عقد Tap الحاليّ (TapCredentials): الوضع ومعرّف التاجر ومفتاح كلّ بيئة.
            'services.tap.mode',
            'services.tap.merchant_id',
            'services.tap.test.secret_key',
            'services.tap.live.secret_key',
            'services.tap.webhook_secret',
            'services.tap.frontend_url',
            'services.meilisearch.host',
            'services.meilisearch.key',
        ] as $key) {
            $this->assertTrue(
                Config::has($key),
                "المفتاح {$key} غير معرَّف في config/ — سيصير null بعد config:cache",
            );
        }
    }

    /** رمز الحالة الافتراضي لا يكشف اسم النموذج ولا رسالة إنجليزية داخلية. */
    public function test_a_missing_resource_does_not_leak_internal_detail(): void
    {
        $body = $this->getJson('/api/v1/products/no-such-slug-at-all')
            ->assertStatus(404)
            ->getContent();

        $this->assertStringNotContainsString('App\\Models', $body);
        $this->assertStringNotContainsString('No query results', $body);
    }
}
