<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\EventIngest;
use App\Domain\Analytics\VisitorIdentity;
use App\Domain\Cart\CartService;
use App\Domain\Insights\InsightsRegistry;
use App\Domain\Insights\InsightWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Commerce\CommerceFixtures;
use Tests\TestCase;

/**
 * إسناد الطلب — الوصلة التي كانت مستحيلة.
 *
 * جدول `orders` لا يحمل عمود إسنادٍ واحدًا: بحثٌ في أعمدته عن مُحيلٍ أو
 * حملةٍ أو سلّةٍ أو زائر لم يطابق إلّا `refunded_total_minor` مصادفةً. فهذه
 * الاختبارات تحرس وصلةً جديدة لا سلوكًا قائمًا — وأخطر ما يمسّها تعديلٌ
 * يجعلها تكتب بلا موافقة، أو يجعلها تُبطل بيعًا حين تفشل.
 */
class OrderAttributionTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private const VISITOR = 'aaaaaaaa-1111-2222-3333-444444444444';

    public function test_a_consented_journey_is_attributed_to_its_first_page(): void
    {
        $this->grantConsent();

        // رحلةٌ من ثلاث صفحات: الأولى هي التي تُنسب إليها، لا الأخيرة.
        $this->visit('/blog/كيف-تكتب-تحضيرًا', 'https://www.google.com/search?q=تحضير');
        $this->visit('/shop');
        $this->visit('/products/sku-1');

        $product = $this->makeProduct('SKU-1', 5000);
        $order = $this->checkoutOrder($this->cartWith([$product]))['order'];

        $row = DB::table('order_attributions')->where('order_id', $order->id)->first();

        $this->assertNotNull($row, 'الطلب المدفوع من زائرٍ موافق يُسنَد.');
        $this->assertSame(
            '/blog/كيف-تكتب-تحضيرًا',
            $row->landing_path,
            'اللمسة الأولى لا الأخيرة: آخر لمسةٍ تنسب كلّ إيرادٍ إلى صفحة الدفع.',
        );
        $this->assertSame(
            'google.com',
            $row->landing_referrer_host,
            'بلا `www.`: تركُها يشقّ المُحيل الواحد إلى سطرين فيبدو نصفَ حجمه مرّتين.',
        );
        $this->assertSame(3, (int) $row->touch_count);
        $this->assertSame(app(EventIngest::class)->hash(self::VISITOR), $row->visitor_hash);
        $this->assertNull($row->anonymized_at);
    }

    /** بلا موافقة لا بصمة، فلا شيء يُسنَد إليه — ولا صفٌّ بأعمدةٍ فارغة يوهم بالعكس. */
    public function test_an_unconsented_order_gets_no_attribution_row(): void
    {
        $this->visit('/shop');

        $product = $this->makeProduct('SKU-2', 4000);
        $order = $this->checkoutOrder($this->cartWith([$product]))['order'];

        $this->assertSame(0, DB::table('order_attributions')->where('order_id', $order->id)->count());
    }

    /** إشارة الرفض تعلو على كلّ شيء — حتّى على موافقةٍ مسجّلة سابقًا. */
    public function test_the_refusal_signal_blocks_attribution(): void
    {
        $this->grantConsent();
        $this->visit('/shop');

        $product = $this->makeProduct('SKU-3', 4000);
        $cart = $this->cartWith([$product]);

        $response = $this->withHeaders(['Sec-GPC' => '1'])
            ->withUnencryptedCookie(CartService::COOKIE_NAME, $cart)
            ->postJson('/api/v1/checkout/orders', [
                'name' => 'نورة العتيبي',
                'email' => 'noura@example.com',
                'phone' => '512345678',
                'taxProfile' => null,
                'policyConsent' => true,
            ]);

        $response->assertCreated();
        $this->assertSame(0, DB::table('order_attributions')->count());
    }

    /**
     * فشل الإسناد لا يُبطل بيعًا.
     *
     * الزبون دفع والطلب قائم، وردٌّ بخطأ بعد خصم المبلغ أسوأ بما لا يُقاس من
     * فقد سطر قياس. يُحاكى العطل بإسقاط الجدول — أقرب ما يكون من فشلٍ حقيقيّ
     * في الكتابة، وهو الذي كشف أنّ `catch` وحده لا يكفي في PostgreSQL.
     */
    public function test_an_attribution_failure_never_fails_the_order(): void
    {
        $this->grantConsent();
        $this->visit('/shop');

        DB::statement('DROP TABLE order_attributions');

        $product = $this->makeProduct('SKU-4', 4000);
        $result = $this->checkoutOrder($this->cartWith([$product]));

        $this->assertNotNull($result['order']->id, 'الطلب قائمٌ رغم عطل الإسناد.');
    }

    /** إخفاء الهويّة يمحو البصمة ويُبقي الجواب التجاريّ. */
    public function test_expiry_clears_the_fingerprint_and_keeps_the_page(): void
    {
        $this->grantConsent();
        $this->visit('/landing');

        $product = $this->makeProduct('SKU-5', 7000);
        $order = $this->checkoutOrder($this->cartWith([$product]))['order'];

        DB::table('order_attributions')
            ->where('order_id', $order->id)
            ->update(['created_at' => now()->subDays(120)]);

        $this->artisan('analytics:prune')->assertSuccessful();

        $row = DB::table('order_attributions')->where('order_id', $order->id)->first();

        $this->assertNull($row->visitor_hash);
        $this->assertNull($row->session_hash);
        $this->assertNotNull($row->anonymized_at);
        $this->assertSame('/landing', $row->landing_path, 'المسار لا يعرّف بشخصٍ فيبقى — وإلّا تغيّر تقرير الربع الماضي كلّ ليلة.');

        // والكنسة لا تتكرّر على ما مُحي: دفترٌ ينبض كلّ ليلة عن صفوفٍ مُحيت مرّة يكذب.
        $before = DB::table('anonymization_log')->count();
        $this->artisan('analytics:prune')->assertSuccessful();
        $this->assertSame($before, DB::table('anonymization_log')->count());
    }

    /** اللوحة تقرأ ما كُتب — والتغطية تُعلَن بمقامها. */
    public function test_the_pages_domain_reports_the_revenue_and_its_coverage(): void
    {
        $this->grantConsent();
        $this->visit('/landing');

        $product = $this->makeProduct('SKU-6', 9900);
        $order = $this->checkoutOrder($this->cartWith([$product]))['order'];

        // الإسناد يُكتب عند الإنشاء، والإيراد لا يُحسب قبل الدفع — والاثنان صحيحان.
        $order->update(['status' => 'PAID']);

        $insight = app(InsightsRegistry::class)->make('pages');
        $metrics = [];
        foreach ($insight->metrics(InsightWindow::fromRequest([])) as $metric) {
            $metrics[$metric->key] = $metric->toArray();
        }

        $this->assertSame('MEASURED', $metrics['attributedOrders']['state']);
        $this->assertSame(1, $metrics['attributedOrders']['value']);
        $this->assertSame(100.0, $metrics['attributionCoverage']['value']);
        $this->assertSame(9900, $metrics['attributedRevenue']['value']);

        $rows = $insight->lists(InsightWindow::fromRequest([]))['revenueByPage']['rows'];
        $this->assertSame([['path' => '/landing', 'orders' => 1, 'revenueMinor' => 9900]], $rows);
    }

    /** الموافقة تُمنح بالمعرّف نفسه الذي تحمله بقيّة الطلبات في هذا الاختبار. */
    private function grantConsent(): void
    {
        $this->withUnencryptedCookie(VisitorIdentity::VISITOR_COOKIE, self::VISITOR)
            ->postJson('/api/v1/consent', ['granted' => true])
            ->assertOk();
    }

    private function visit(string $path, ?string $referrer = null): void
    {
        $this->withUnencryptedCookie(VisitorIdentity::VISITOR_COOKIE, self::VISITOR)
            ->postJson('/api/v1/events', [
                'events' => [array_filter([
                    'type' => 'page_view',
                    'path' => $path,
                    'referrer' => $referrer,
                ], fn ($v) => $v !== null)],
            ])->assertNoContent();
    }
}
