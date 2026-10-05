<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Admin\AdminRole;
use App\Domain\Payments\ProviderDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * حالة مزوّد الدفع — ولا سرّ يخرج منها (§٤٣ §٤٩).
 *
 * الشاشة كانت تنادي نقطةً غير موجودة فتعرض عطلًا. وأخطر ما يخفيه غيابها
 * هو الفرق بين **المضبوط** و**المحلول**: إعدادٌ يقول «وهميّ» ومزوّدٌ حيٌّ
 * يقبض.
 */
class ProviderDiagnosticsTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function diagnostics(): array
    {
        return app(ProviderDiagnostics::class)->status();
    }

    /**
     * إعداد Tap كاملًا بعقده الحاليّ (TapCredentials): وضعٌ ومفاتيحُه ومعرّف تاجر.
     *
     * كان الاختبار يضبط `services.tap.secret_key` وحده — المفتاح القديم الذي
     * يُقرأ انتقاليًّا فقط حين يغيب مفتاح الوضع. فلمّا حمل `.env` الحاوية
     * `TAP_TEST_SECRET_KEY` صار ذلك المفتاح يغلب ما يضبطه الاختبار، فيقيس
     * الاختبار إعداد المطوّر لا حالته. يُستبدل القسم كلّه هنا فلا يتسرّب
     * مفتاحٌ من البيئة، ولا سرّ حقيقيّ في الاختبار.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function tap(array $overrides): void
    {
        config(['services.tap' => array_replace_recursive([
            'base_url' => config('services.tap.base_url'),
            'frontend_url' => config('services.tap.frontend_url'),
            'webhook_secret' => null,
            'secret_key' => null,
            'mode' => 'test',
            'merchant_id' => '68052232',
            'allow_live_outside_production' => false,
            'test' => ['secret_key' => null, 'public_key' => null],
            'live' => ['secret_key' => null, 'public_key' => null],
            'apple_pay' => false,
        ], $overrides)]);
    }

    public function test_a_secret_is_never_returned_in_full(): void
    {
        // الوضع الحيّ خارج الإنتاج يحتاج إذنه المسمّى — يُمنح هنا كي يُقاس التلميح لا رفضُ الوضع.
        $this->tap([
            'mode' => 'live',
            'allow_live_outside_production' => true,
            'live' => ['secret_key' => 'REDACTED_PUBLIC_TEST_CREDENTIAL'],
        ]);

        $status = $this->diagnostics();
        $hint = $status['tap']['credentials']['secretKey'];
        $this->assertSame([], array_filter(
            $status['tap']['problems']['errors'],
            fn ($k) => str_starts_with((string) $k, 'isolation_'),
            ARRAY_FILTER_USE_KEY,
        ), 'إعدادٌ حيٌّ متّسق لا يُبلغ عن خلط بيئات');

        /*
         * الغرض إثبات الوجود لا الكشف: طباعةُ المفتاح كاملًا في شاشةٍ
         * إداريّة تضعه في لقطات الشاشة وسجلّات المتصفّح.
         */
        $this->assertNotSame('REDACTED_PUBLIC_TEST_CREDENTIAL', $hint);
        $this->assertStringNotContainsString('ghijkl', (string) $hint);
        // وتبقى البادئة والأحرف الأخيرة: تُميّز الحيّ من التجريبيّ وتُميّز التدوير.
        $this->assertStringStartsWith('sk_live', (string) $hint);
        $this->assertStringEndsWith('mnop', (string) $hint);
    }

    public function test_an_absent_secret_reads_as_absent_not_as_an_empty_hint(): void
    {
        // لا مفتاح لأيّ وضع ولا مفتاحٌ قديم — ولا يُقرأ مفتاح المطوّر من `.env`.
        $this->tap([]);

        $status = $this->diagnostics();

        $this->assertNull($status['tap']['credentials']['secretKey']);
        $this->assertFalse($status['tap']['configured']);
        // بلا مفتاح لا تحصيل ولا تحقّق من توقيع — وهذا خطأ لا تحذير.
        $this->assertArrayHasKey('secret_key', $status['tap']['problems']['errors']);
    }

    /** مفتاح الوضع هو المصدر: مفتاحٌ تجريبيّ في الوضع التجريبيّ يُلمَّح ويُنسب إلى متغيّره. */
    public function test_the_mode_key_is_the_authoritative_secret_source(): void
    {
        $this->tap(['test' => ['secret_key' => 'REDACTED_PUBLIC_TEST_CREDENTIAL']]);

        $credentials = $this->diagnostics()['tap']['credentials'];

        $this->assertStringStartsWith('sk_test', (string) $credentials['secretKey']);
        $this->assertStringNotContainsString('ghijkl', (string) $credentials['secretKey']);
        $this->assertSame('TAP_TEST_SECRET_KEY', $credentials['secretSource']);
        $this->assertSame('configured', $credentials['testSecret']);
        $this->assertSame('missing', $credentials['liveSecret']);
    }

    /** مفتاحٌ حيٌّ في الوضع التجريبيّ خلطُ بيئات — يُبلَّغ خطأً ولا يُعدّ المزوّد مضبوطًا. */
    public function test_a_live_key_in_test_mode_is_reported_as_an_isolation_error(): void
    {
        $this->tap(['test' => ['secret_key' => 'REDACTED_PUBLIC_TEST_CREDENTIAL']]);

        $status = $this->diagnostics();
        $isolation = array_filter(
            $status['tap']['problems']['errors'],
            fn ($k) => str_starts_with((string) $k, 'isolation_'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertNotEmpty($isolation, 'خلط التجربة بالإنتاج يجب أن يظهر');
    }

    /** معرّف التاجر إلزاميٌّ حين يُضبط المفتاح — لا يُخفى غيابه. */
    public function test_a_key_without_a_merchant_id_is_reported(): void
    {
        $this->tap(['merchant_id' => '', 'test' => ['secret_key' => 'REDACTED_PUBLIC_TEST_CREDENTIAL']]);

        $errors = $this->diagnostics()['tap']['problems']['errors'];

        $this->assertNotEmpty(array_filter($errors, fn ($m) => str_contains((string) $m, 'TAP_MERCHANT_ID')));
    }

    public function test_configured_and_resolved_are_reported_separately(): void
    {
        // `fake` مسموحٌ في الاختبار فيُحلّ كما هو.
        config(['services.payments.provider' => 'fake']);

        $resolution = $this->diagnostics()['providerResolution'];

        $this->assertSame('fake', $resolution['configured']);
        $this->assertSame('fake', $resolution['resolved']);
        $this->assertFalse($resolution['fellBack']);
    }

    public function test_an_unknown_provider_is_reported_as_a_fallback_with_its_reason(): void
    {
        config(['services.payments.provider' => 'stripe']);

        $resolution = $this->diagnostics()['providerResolution'];

        /*
         * الإعداد يقول «stripe» والذي يقبض Tap. حقلٌ واحد لا يسع الحقيقتين،
         * وإخفاءُ الفرق يجعل المشغّل يظنّ أنّه يجرّب بينما مالٌ يتحرّك.
         */
        $this->assertSame('stripe', $resolution['configured']);
        $this->assertSame('tap', $resolution['resolved']);
        $this->assertTrue($resolution['fellBack']);
        $this->assertSame('unknown_provider', $resolution['reason']);
    }

    public function test_the_live_mode_is_derived_from_the_key_prefix(): void
    {
        config(['services.tap.secret_key' => 'REDACTED_PUBLIC_TEST_CREDENTIAL']);
        $this->assertSame('live', $this->diagnostics()['tap']['mode']);

        config(['services.tap.secret_key' => 'REDACTED_PUBLIC_TEST_CREDENTIAL']);
        $this->assertSame('test', $this->diagnostics()['tap']['mode']);
    }

    public function test_the_endpoint_requires_the_payments_permission(): void
    {
        // بادئةُ المفتاح ووضعُ الحياة معلوماتُ إعدادٍ لا معلوماتُ طلب.
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));
        $this->getJson('/api/v1/admin/payments/provider')->assertStatus(403);
    }

    public function test_the_endpoint_returns_the_shape_the_panel_reads(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/payments/provider')
            ->assertOk()
            ->assertJsonStructure([
                'providerResolution' => ['configured', 'resolved', 'fellBack', 'reason', 'mode'],
                'appEnv',
                'tap' => ['mode', 'configured', 'problems' => ['errors', 'warnings'], 'credentials', 'webhookUrl'],
                'recent' => ['captured24h', 'failed24h', 'ignored24h', 'duplicateCaptures'],
            ]);
    }
}
