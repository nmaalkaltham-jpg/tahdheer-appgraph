<?php

namespace Tests\Feature\Home;

use App\Domain\Cart\Models\Coupon;
use App\Domain\Content\HomeContent;
use App\Domain\Content\Models\Campaign;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage content endpoints (MIP §3.3). Two properties are under test on
 * every route: the EXACT snake_case shape the shipped storefront reads, and
 * the refusal to answer with anything nobody configured.
 */
class HomeContentTest extends TestCase
{
    use RefreshDatabase;

    private function configure(string $key, array $value): void
    {
        Setting::query()->create(['key' => $key, 'value' => $value]);
    }

    public function test_unconfigured_sections_answer_empty_never_placeholder_copy(): void
    {
        $this->getJson('/api/v1/home/hero')->assertNoContent();
        $this->getJson('/api/v1/home/cta')->assertNoContent();
        $this->getJson('/api/v1/home/promo')->assertNoContent();
        $this->getJson('/api/v1/home/reviews')->assertNoContent();

        $this->getJson('/api/v1/home/why')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/home/benefits')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/home/faq')->assertOk()->assertExactJson([]);
    }

    public function test_hero_returns_the_configured_payload_unwrapped(): void
    {
        $this->configure(HomeContent::HERO, [
            'title' => 'كل ما يحتاجه المعلّم',
            'title_accent' => 'في مكان واحد',
            'eyebrow' => 'متجر رقمي',
            'subtitle' => 'ملفات جاهزة للتحميل الفوري.',
            'cta_label' => 'تصفّح المنتجات',
            'cta_url' => '/shop',
            'cta_secondary_label' => 'كيف يعمل المتجر',
            'cta_secondary_url' => '/how-it-works',
            'trust' => ['تحميل فوري', '   ', 'ملفات قابلة للتعديل'],
        ]);

        $response = $this->getJson('/api/v1/home/hero')->assertOk();

        // No `data` envelope: the storefront reads the object itself.
        $response->assertJsonPath('title', 'كل ما يحتاجه المعلّم')
            ->assertJsonPath('title_accent', 'في مكان واحد')
            ->assertJsonPath('cta_url', '/shop');

        // Blank chips are dropped rather than rendered as an empty pill.
        $this->assertSame(['تحميل فوري', 'ملفات قابلة للتعديل'], $response->json('trust'));
    }

    public function test_hero_without_a_title_is_not_a_hero(): void
    {
        $this->configure(HomeContent::HERO, ['subtitle' => 'وصف بلا عنوان']);

        $this->getJson('/api/v1/home/hero')->assertNoContent();
    }

    public function test_cards_and_faq_drop_incomplete_entries(): void
    {
        $this->configure(HomeContent::WHY, [
            ['title' => 'مبنيّة على المنهج', 'description' => 'مرتبطة بمرحلة ومادة.'],
            ['description' => 'بطاقة بلا عنوان'],
            ['title' => 'تحميل فوري'],
        ]);
        $this->configure(HomeContent::BENEFITS, [['title' => 'فاتورة ضريبية']]);
        $this->configure(HomeContent::FAQ, [
            ['question' => 'كيف أستلم الملف؟', 'answer' => 'مباشرة بعد الدفع.'],
            ['question' => 'سؤال بلا إجابة'],
            ['question' => 'هل الملفات قابلة للتعديل؟', 'answer' => 'ملفات Word نعم.'],
        ]);

        $why = $this->getJson('/api/v1/home/why')->assertOk()->assertJsonCount(2);
        $why->assertJsonPath('0.title', 'مبنيّة على المنهج')
            ->assertJsonPath('0.description', 'مرتبطة بمرحلة ومادة.');
        // A card with no description omits the key entirely.
        $this->assertSame(['title' => 'تحميل فوري'], $why->json('1'));

        $this->getJson('/api/v1/home/benefits')->assertOk()->assertJsonCount(1);

        $faq = $this->getJson('/api/v1/home/faq')->assertOk()->assertJsonCount(2);
        $faq->assertJsonPath('0.id', 1)->assertJsonPath('1.id', 2)
            ->assertJsonPath('1.question', 'هل الملفات قابلة للتعديل؟');
    }

    public function test_cta_returns_only_the_fields_the_storefront_reads(): void
    {
        $this->configure(HomeContent::CTA, [
            'title' => 'ابدأ فصلك بملفات جاهزة',
            'cta_label' => 'تصفّح',
            'cta_url' => '/shop',
            'trust' => ['لا مكان لهذا هنا'],
        ]);

        $response = $this->getJson('/api/v1/home/cta')->assertOk();

        $this->assertSame(
            ['title' => 'ابدأ فصلك بملفات جاهزة', 'cta_label' => 'تصفّح', 'cta_url' => '/shop', 'secondary' => null],
            $response->json(),
        );
    }

    public function test_promo_badge_comes_from_the_owners_promo_block(): void
    {
        Campaign::query()->create([
            'title' => 'عروض', 'url' => '/offers', 'ends_at' => now()->addDays(3),
        ]);
        $this->configure(HomeContent::PROMO, ['badge' => 'خصم 25٪ — بداية الفصل']);

        $this->getJson('/api/v1/home/promo')->assertOk()
            ->assertJsonPath('campaign.badge', 'خصم 25٪ — بداية الفصل');
    }

    public function test_hero_variant_accepts_the_current_list_and_maps_legacy_values(): void
    {
        $this->configure(HomeContent::HERO, ['title' => 'هيرو', 'variant' => 'cinematic']);
        $this->getJson('/api/v1/home/hero')->assertOk()->assertJsonPath('variant', 'slider');

        Setting::query()->where('key', HomeContent::HERO)->update(['value' => json_encode(['title' => 'هيرو', 'variant' => 'luxe'])]);
        $this->getJson('/api/v1/home/hero')->assertOk()->assertJsonPath('variant', 'luxe');

        Setting::query()->where('key', HomeContent::HERO)->update(['value' => json_encode(['title' => 'هيرو', 'variant' => 'neon'])]);
        $this->assertArrayNotHasKey('variant', $this->getJson('/api/v1/home/hero')->assertOk()->json());
    }

    public function test_unconfigured_identity_is_an_honest_empty_object(): void
    {
        $this->getJson('/api/v1/home/identity')->assertOk()->assertExactJson(['document' => null, 'socials' => []]);
    }

    public function test_identity_emits_the_document_and_only_valid_social_links(): void
    {
        $this->configure(HomeContent::IDENTITY, [
            'document_label' => 'وثيقة العمل الحر',
            'document_number' => 'FL-123456789',
            'social_whatsapp_url' => 'https://wa.me/966500000000',
            'social_instagram_url' => 'instagram.com/tahdheer',
            'social_x_url' => 'https://x.com/tahdheer',
            'social_tiktok_url' => 'javascript:alert(1)',
            'social_snapchat_url' => '  ',
        ]);

        $this->getJson('/api/v1/home/identity')->assertOk()->assertExactJson([
            'document' => ['label' => 'وثيقة العمل الحر', 'number' => 'FL-123456789'],
            'socials' => [
                ['network' => 'x', 'label' => 'إكس', 'href' => 'https://x.com/tahdheer'],
                ['network' => 'whatsapp', 'label' => 'واتساب', 'href' => 'https://wa.me/966500000000'],
            ],
        ]);

        // رقمٌ بلا اسم (أو العكس) ليس وثيقة.
        Setting::query()->where('key', HomeContent::IDENTITY)->update(['value' => json_encode(['document_number' => 'FL-1'])]);
        $this->getJson('/api/v1/home/identity')->assertOk()->assertJsonPath('document', null);
    }

    public function test_unconfigured_announcements_are_an_honest_empty_bar(): void
    {
        $this->getJson('/api/v1/home/announcements')->assertOk()->assertExactJson([
            'mode' => 'static', 'items' => [], 'link' => null, 'style' => null,
        ]);
    }

    public function test_announcements_are_normalised_from_the_stored_block(): void
    {
        $this->configure(HomeContent::ANNOUNCEMENTS, [
            'announcements_mode' => 'marquee',
            'announcement_1' => 'شحن مجّاني فوق ٢٠٠ ر.س',
            'announcement_2' => '  ',
            'announcement_3' => 'خصم ٣٠٪ على الحزم',
            'announcements_link_label' => 'عرض جميع العروض',
            'announcements_link_url' => '/offers',
            'announcements_bg' => '#0B3D91',
            'announcements_fg' => '#fff',
            'announcements_height' => '40',
        ]);

        $this->getJson('/api/v1/home/announcements')->assertOk()->assertExactJson([
            'mode' => 'marquee',
            'items' => ['شحن مجّاني فوق ٢٠٠ ر.س', 'خصم ٣٠٪ على الحزم'],
            'link' => ['label' => 'عرض جميع العروض', 'href' => '/offers'],
            'style' => ['bg' => '#0b3d91', 'fg' => '#fff', 'height' => 40],
        ]);
    }

    public function test_announcement_junk_falls_back_instead_of_leaking(): void
    {
        $this->configure(HomeContent::ANNOUNCEMENTS, [
            'announcements_mode' => 'blink',
            'announcement_1' => 'رسالة',
            'announcements_link_label' => 'بلا وجهة',
            'announcements_bg' => 'red',
            'announcements_fg' => '#000',
            'announcements_height' => '-5',
        ]);

        $this->getJson('/api/v1/home/announcements')->assertOk()->assertExactJson([
            'mode' => 'static', 'items' => ['رسالة'], 'link' => null, 'style' => null,
        ]);
    }

    /** الزرّ الثانويّ يُصدَر كائنًا واحدًا حين يكتمل نصًّا ووجهة، وإلّا null. */
    public function test_cta_secondary_button_needs_both_label_and_url(): void
    {
        $this->configure(HomeContent::CTA, [
            'title' => 'ابدأ', 'cta_label' => 'تصفّح', 'cta_url' => '/shop',
            'cta_secondary_label' => 'كيف يعمل المتجر', 'cta_secondary_url' => '/how-it-works',
        ]);

        $this->getJson('/api/v1/home/cta')->assertOk()
            ->assertJsonPath('secondary', ['label' => 'كيف يعمل المتجر', 'href' => '/how-it-works'])
            ->assertJsonPath('cta_secondary_label', 'كيف يعمل المتجر');

        Setting::query()->where('key', HomeContent::CTA)->update(['value' => json_encode([
            'title' => 'ابدأ', 'cta_secondary_label' => 'نصٌّ بلا وجهة',
        ])]);

        $this->getJson('/api/v1/home/cta')->assertOk()->assertJsonPath('secondary', null);
    }

    public function test_promo_publishes_a_live_campaign_and_a_valid_coupon(): void
    {
        $campaign = Campaign::query()->create([
            'title' => 'عروض بداية الفصل',
            'description' => 'خصومات لعدد محدود من الأيام.',
            'url' => '/offers',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
        ]);
        Coupon::query()->create(['code' => 'PREVIEW15', 'type' => 'percent', 'value' => 15]);
        $this->configure(HomeContent::PROMO_COUPON, ['code' => 'PREVIEW15']);

        $this->getJson('/api/v1/home/promo')->assertOk()
            ->assertJsonPath('campaign.title', 'عروض بداية الفصل')
            ->assertJsonPath('campaign.url', '/offers')
            ->assertJsonPath('campaign.ends_at', $campaign->ends_at->toIso8601String())
            ->assertJsonPath('campaign.endsAt', $campaign->ends_at->toIso8601String())
            ->assertJsonPath('campaign.badge', null)
            ->assertJsonPath('coupon.code', 'PREVIEW15')
            // Description is derived from the coupon row, never free text.
            ->assertJsonPath('coupon.description', 'خصم 15٪');
    }

    public function test_promo_hides_a_finished_campaign_and_an_unusable_coupon(): void
    {
        Campaign::query()->create([
            'title' => 'حملة منتهية', 'url' => '/offers',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay(),
        ]);
        Campaign::query()->create([
            'title' => 'حملة لم تبدأ', 'url' => '/offers',
            'starts_at' => now()->addWeek(), 'ends_at' => now()->addMonth(),
        ]);
        Coupon::query()->create([
            'code' => 'EXPIRED10', 'type' => 'percent', 'value' => 10,
            'expires_at' => now()->subHour(),
        ]);
        $this->configure(HomeContent::PROMO_COUPON, ['code' => 'EXPIRED10']);

        $this->getJson('/api/v1/home/promo')->assertNoContent();
    }

    public function test_promo_hides_a_coupon_that_burned_through_its_usage_limit(): void
    {
        Coupon::query()->create([
            'code' => 'SPENT', 'type' => 'fixed', 'value' => 500,
            'usage_limit' => 20, 'usage_count' => 20,
        ]);
        $this->configure(HomeContent::PROMO_COUPON, ['code' => 'SPENT']);

        $this->getJson('/api/v1/home/promo')->assertNoContent();
    }

    public function test_promo_advertises_no_coupon_the_store_did_not_choose(): void
    {
        // A perfectly valid coupon exists — but nobody put it on the homepage.
        Coupon::query()->create(['code' => 'INTERNAL', 'type' => 'percent', 'value' => 50]);

        $this->getJson('/api/v1/home/promo')->assertNoContent();
    }

    public function test_promo_halves_are_independent(): void
    {
        Campaign::query()->create([
            'title' => 'حملة بلا قسيمة', 'url' => '/offers', 'ends_at' => now()->addDays(3),
        ]);

        $response = $this->getJson('/api/v1/home/promo')->assertOk()
            ->assertJsonPath('campaign.title', 'حملة بلا قسيمة');

        $this->assertArrayNotHasKey('coupon', $response->json());
        // No description configured ⇒ the key is absent, not an empty string.
        $this->assertArrayNotHasKey('description', $response->json('campaign'));
    }
}
