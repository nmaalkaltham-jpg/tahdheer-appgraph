<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * الوكيل الموثوق ليس تفصيلًا: بدونه تُفهرس كل حدود المعدّل على عنوان
 * Traefik، فيصير للزوّار جميعًا دلوٌ واحد — مهاجم يستنفد حصّة الجميع، وزائر
 * شرعي يُخنق. والعطل صامت تمامًا: لا خطأ، ولا اختبار يسقط، فقط أرقام خاطئة.
 */
class TrustedProxyTest extends TestCase
{
    public function test_client_ip_comes_from_the_forwarded_header(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.5',          // Traefik داخل شبكة Docker
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9', // العميل الحقيقي
        ])->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertSame('203.0.113.9', request()->ip());
    }

    public function test_forwarded_proto_makes_the_request_secure(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->getJson('/api/v1/health')->assertOk();

        $this->assertTrue(request()->secure(), 'خلف TLS يجب أن يكون الطلب آمنًا');
    }

    public function test_an_untrusted_source_cannot_forge_its_address(): void
    {
        // طالب من خارج النطاقات الموثوقة يدّعي عنوانًا آخر — يُتجاهل ادّعاؤه.
        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ])->getJson('/api/v1/health')->assertOk();

        $this->assertSame('198.51.100.7', request()->ip());
    }

    /**
     * ‎TRUSTED_PROXIES=‎ الفارغ يعني «افتراضي» لا «لا تثق بأحد».
     *
     * env() تُعيد الافتراض حين يغيب المفتاح فقط، لا حين يوجد فارغًا. وكتابة
     * المفتاح فارغًا أطبع ما يُفعل للدلالة على «غير محدَّد» — وكانت تُفرغ
     * قائمة الوكلاء فيقرأ Laravel عنوان الوكيل عنوانًا للعميل، فيصير للعالم
     * كلّه دلو حدٍّ واحد. قِسناه على الإنتاج: 25 طلبًا من جهاز خفّضت عدّاد
     * جهاز آخر.
     */
    public function test_an_empty_trusted_proxies_value_still_trusts_the_private_ranges(): void
    {
        // البيئة هنا بلا المفتاح؛ الاختبار يوثّق أن السلوك واحد في الحالتين.
        $this->withServerVariables([
            'REMOTE_ADDR' => '172.16.2.10',        // nginx داخل شبكة Docker
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ])->getJson('/api/v1/health')->assertOk();

        $this->assertSame('203.0.113.9', request()->ip());
    }
}
