<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\EventIngest;
use App\Domain\Analytics\VisitorIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الوعد المنشور، بندًا بندًا.
 *
 * كلّ اختبارٍ هنا يقابل جملةً حرفيّة في «سياسة الخصوصية» أو «سياسة ملفات
 * تعريف الارتباط» المعروضتين على الزائر. وسيلتُها ليست تغطية الشيفرة بل
 * منعُ انحرافها عن نصٍّ منشور: تعديلٌ يجعل الكعكة تُكتب قبل الموافقة يمرّ
 * كلّ فحصٍ آخر في المشروع ولا يُوقفه إلّا هذا الملفّ.
 */
class ConsentAndIngestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * الكعكات تُرسل — كما يرسلها المتصفّح.
     *
     * `postJson` في Laravel لا يحمل كعكةً إلّا مع `withCredentials`، وهو
     * مطابقٌ لعقد المتصفّح نفسه: نداءٌ عابرٌ للأصل لا يحمل كعكةً إلّا بـ
     * `credentials: 'include'` — وهي الراية التي يضعها `lib/telemetry.ts`.
     * فاختبارٌ بدونها يقيس حالةً لا تقع في المتجر.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
    }

    /** «لا يُنشأ معرّف زائر ولا تُكتب أي كوكي قياس قبل موافقتك الصريحة». */
    public function test_no_measurement_cookie_before_consent(): void
    {
        $response = $this->postJson('/api/v1/events', [
            'events' => [['type' => 'page_view', 'path' => '/p']],
        ]);

        $response->assertNoContent();
        $this->assertNull($response->getCookie(VisitorIdentity::VISITOR_COOKIE, false));
        $this->assertNull($response->getCookie(VisitorIdentity::SESSION_COOKIE, false));

        $row = DB::table('analytics_events')->where('path', '/p')->first();
        $this->assertNotNull($row, 'الحدث المجهول يُكتب: السياسة تُجيزه بلا موافقة.');
        $this->assertNull($row->visitor_hash);
        $this->assertNull($row->session_hash);
    }

    /** «الموافقة تسمح بإنشاء معرّف زائر وجلسة» — وفي الطلب نفسه يُقيَّد السطر. */
    public function test_grant_mints_identifiers_and_records_the_wording_shown(): void
    {
        $response = $this->postJson('/api/v1/consent', ['granted' => true]);

        $response->assertOk()->assertJsonPath('data.granted', true);

        $visitor = $response->getCookie(VisitorIdentity::VISITOR_COOKIE, false);
        $this->assertNotNull($visitor);
        $this->assertTrue($visitor->isHttpOnly(), 'معرّف الزائر لا يقرؤه العميل.');

        $this->assertNotNull($response->getCookie(VisitorIdentity::SESSION_COOKIE, false));

        $record = DB::table('consent_records')->where('purpose', 'ANALYTICS')->first();
        $this->assertNotNull($record);
        $this->assertTrue((bool) $record->granted);
        $this->assertSame('BANNER', $record->source);
        $this->assertSame(config('analytics.consent.policy_version'), $record->policy_version);
        $this->assertSame(
            config('analytics.consent.wording'),
            $record->wording_shown,
            'الدليل هو ما عُرض فعلًا — ولذلك النصّ من الخادم لا من جسم الطلب.',
        );
        $this->assertSame(
            app(EventIngest::class)->hash($visitor->getValue()),
            $record->subject_ref,
            'المرجع بصمةُ المعرّف: بها وحدها يجد `EventIngest` الموافقة.',
        );
    }

    /** بعد الموافقة تُبصم الزيارة — وقبلها لا تُبصم. */
    public function test_events_carry_the_identifier_only_after_consent(): void
    {
        $visitorId = '11111111-2222-3333-4444-555555555555';

        $this->withUnencryptedCookie(VisitorIdentity::VISITOR_COOKIE, $visitorId)
            ->postJson('/api/v1/events', [
                'events' => [['type' => 'page_view', 'path' => '/before']],
            ])->assertNoContent();

        $this->assertNull(DB::table('analytics_events')->where('path', '/before')->value('visitor_hash'));

        // موافقةٌ بالمعرّف نفسه: لا يُولَّد ثانٍ فينقطع العدّ عن ماضيه.
        $granted = $this->withUnencryptedCookie(VisitorIdentity::VISITOR_COOKIE, $visitorId)
            ->postJson('/api/v1/consent', ['granted' => true]);
        $this->assertSame($visitorId, $granted->getCookie(VisitorIdentity::VISITOR_COOKIE, false)?->getValue());

        $after = $this->withUnencryptedCookie(VisitorIdentity::VISITOR_COOKIE, $visitorId)
            ->postJson('/api/v1/events', [
                'events' => [['type' => 'page_view', 'path' => '/after']],
            ]);

        $after->assertNoContent();
        $this->assertSame(
            app(EventIngest::class)->hash($visitorId),
            DB::table('analytics_events')->where('path', '/after')->value('visitor_hash'),
        );
        $this->assertNotNull(
            $after->getCookie(VisitorIdentity::SESSION_COOKIE, false),
            'الجلسة تُجدَّد مع كلّ دفعة: ثلاثون دقيقة من الخمول لا من أوّل صفحة.',
        );
    }

    /** «سحب الموافقة يحذف كوكيز القياس فورًا». */
    public function test_withdrawal_clears_both_measurement_cookies_and_is_recorded(): void
    {
        $visitorId = '99999999-8888-7777-6666-555555555555';
        $this->withUnencryptedCookie(VisitorIdentity::VISITOR_COOKIE, $visitorId)
            ->postJson('/api/v1/consent', ['granted' => true]);

        $response = $this->withUnencryptedCookie(VisitorIdentity::VISITOR_COOKIE, $visitorId)
            ->postJson('/api/v1/consent', ['granted' => false]);

        $response->assertOk()->assertJsonPath('data.granted', false);
        $this->assertSame('', $response->getCookie(VisitorIdentity::VISITOR_COOKIE, false)?->getValue());
        $this->assertSame('', $response->getCookie(VisitorIdentity::SESSION_COOKIE, false)?->getValue());

        // السحب يُضاف ولا يُعدَّل: الدفتر يحفظ أنّ الإذن كان قائمًا يوم استُعمل.
        $this->assertSame(2, DB::table('consent_records')->count());
        $this->assertFalse(
            (bool) DB::table('consent_records')->orderByDesc('id')->value('granted'),
        );

        $this->withUnencryptedCookie(VisitorIdentity::VISITOR_COOKIE, $visitorId)
            ->postJson('/api/v1/events', [
                'events' => [['type' => 'page_view', 'path' => '/withdrawn']],
            ])->assertNoContent();

        $this->assertNull(DB::table('analytics_events')->where('path', '/withdrawn')->value('visitor_hash'));
    }

    /** «عند وصول أيّهما، يتوقّف القياس كلّيًا» — لا صفٌّ، ولا حتّى بلا معرّف. */
    public function test_refusal_signal_stops_measurement_entirely(): void
    {
        foreach ([['Sec-GPC' => '1'], ['DNT' => '1']] as $i => $headers) {
            $this->withHeaders($headers)->postJson('/api/v1/events', [
                'events' => [['type' => 'page_view', 'path' => "/refused-$i"]],
            ])->assertNoContent();

            $this->assertSame(0, DB::table('analytics_events')->where('path', "/refused-$i")->count());
        }
    }

    /** «ولا يوجد إعداد في الموقع يستطيع تجاوز ذلك» — والشريط إعدادٌ في الموقع. */
    public function test_consent_cannot_override_the_browser_signal(): void
    {
        $response = $this->withHeaders(['Sec-GPC' => '1'])
            ->postJson('/api/v1/consent', ['granted' => true]);

        $response->assertOk()
            ->assertJsonPath('data.granted', false)
            ->assertJsonPath('data.refusedBySignal', true);

        $this->assertNull($response->getCookie(VisitorIdentity::VISITOR_COOKIE, false));
        $this->assertNull(
            $response->getCookie(VisitorIdentity::CONSENT_COOKIE, false),
            'ولا كعكة قرار: الرفض هنا إشارةٌ من المتصفّح لا قرارٌ اتّخذه الزائر.',
        );
        $this->assertSame(0, DB::table('consent_records')->count());
    }

    /** المدد المنشورة: سنةٌ للزائر وثلاثون دقيقةً للجلسة. */
    public function test_cookie_lifetimes_match_the_published_policy(): void
    {
        /*
         * المدّة تُقاس على الكعكة المُرسَلة لا على الثابت: مقارنةُ الثابت
         * بنفسه تمرّ حتّى لو أُعلنت مدّةٌ ولم تُطبَّق.
         */
        $response = $this->postJson('/api/v1/consent', ['granted' => true]);

        foreach ([
            [VisitorIdentity::VISITOR_COOKIE, VisitorIdentity::VISITOR_MINUTES],
            [VisitorIdentity::SESSION_COOKIE, VisitorIdentity::SESSION_MINUTES],
        ] as [$name, $minutes]) {
            $cookie = $response->getCookie($name, false);
            $this->assertNotNull($cookie, "الكعكة {$name} لم تُرسَل.");
            $this->assertEqualsWithDelta(
                $minutes * 60,
                $cookie->getExpiresTime() - time(),
                120,
                "عمر {$name} المُرسَل يخالف المدّة المنشورة.",
            );
        }

        $decision = $response->getCookie(VisitorIdentity::CONSENT_COOKIE, false);

        $this->assertNotNull($decision);
        $this->assertFalse(
            $decision->isHttpOnly(),
            'كعكة القرار يقرؤها الشريط ليصمت بلا نداء شبكة — ولا تحمل معرّفًا.',
        );
    }

    /** المعرّف من الكعكة لا من جسم الطلب: وإلّا كان انتحال زائرٍ سطرًا واحدًا. */
    public function test_a_forged_identifier_in_the_body_is_ignored(): void
    {
        // زائرٌ موافقٌ حقيقيّ — معرّفه من الخادم، وهو ما يحاول المنتحل ادّعاءه.
        $victim = $this->postJson('/api/v1/consent', ['granted' => true])
            ->getCookie(VisitorIdentity::VISITOR_COOKIE, false)?->getValue();

        $this->assertNotNull($victim);

        // طلبٌ بلا كعكةٍ يدّعي المعرّف في جسمه.
        $this->postJson('/api/v1/events', [
            'visitorId' => $victim,
            'sessionId' => $victim,
            'events' => [['type' => 'page_view', 'path' => '/forged']],
        ])->assertNoContent();

        $row = DB::table('analytics_events')->where('path', '/forged')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->visitor_hash);
        $this->assertNull($row->session_hash);
    }

    /** نصّ الشريط يأتي من الخادم — ليُعرض ويُسجَّل من مصدرٍ واحد. */
    public function test_the_banner_copy_is_served_and_states_an_undecided_visitor(): void
    {
        $this->getJson('/api/v1/consent')
            ->assertOk()
            ->assertJsonPath('data.granted', null)
            ->assertJsonPath('data.refusedBySignal', false)
            ->assertJsonPath('data.wording', config('analytics.consent.wording'))
            ->assertJsonPath('data.policyVersion', config('analytics.consent.policy_version'));
    }
}
