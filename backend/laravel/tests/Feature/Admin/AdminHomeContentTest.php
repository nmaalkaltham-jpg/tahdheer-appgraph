<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminAuditEntry;
use App\Domain\Content\HomeContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحرير محتوى الصفحة الرئيسة — والعطل الذي وُلد منه.
 *
 * `HomeContent` كان يُقرأ ولا يُكتب: لا مسار إداريّ يمسّ `home`، ولا شاشة
 * تحرّره. فكان عنوان الرئيسة ونصّها وأزرارها تتغيّر بتحرير البذّار وإعادة
 * البذر — أي بنشرةٍ من مطوّر لا بقرارٍ من مالك.
 *
 * والقيمة هنا في ثلاثة فصول لا في «هل يحفظ»:
 *   · من يحرّر المحتوى ليس من يضبط السمة (`content.manage` ≠ `appearance.manage`).
 *   · ما يُحفظ هو بالضبط ما يُقرأ — لا مفتاحَ يُخزَّن ثمّ يسقط عند العرض.
 *   · الإفراغ حذفٌ صريح لا كائنٌ فارغ: «مضبوطٌ بلا قيمة» و«غير مضبوط»
 *     يُقرآن واحدًا في المتجر، فلا يبقى منهما إلّا واحد.
 */
class AdminHomeContentTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    /* ───── الصلاحية ───── */

    public function test_content_manager_capability_is_separate_from_appearance(): void
    {
        /*
         * دور «قراءة فقط» لا يملك `content.manage` — ولا يكتب. والفحص
         * الإيجابي (المخوَّل يصل) في الاختبارات أدناه: تعليق `api-admin.php`
         * يسجّل أنّ الاكتفاء بالفحص السلبيّ أخفى ثلاثة أدوار معطّلة بصمت.
         */
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ReadOnly))
            ->putJson('/api/v1/admin/home-content/hero', ['payload' => ['title' => 'عنوان']])
            ->assertForbidden();

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->getJson('/api/v1/admin/home-content')
            ->assertOk()
            ->assertJsonPath('data.blocks.home\\.hero', null);
    }

    public function test_an_unknown_block_is_a_real_404_not_a_validation_error(): void
    {
        /* دلالة المصدر تُحفظ: المفتاح جزءٌ من المسار، ومجهولُه موردٌ مفقود. */
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson('/api/v1/admin/home-content/not_a_block', ['payload' => ['title' => 'x']])
            ->assertNotFound();
    }

    /* ───── ما يُحفظ هو ما يُقرأ ───── */

    public function test_saved_hero_is_exactly_what_the_storefront_reads(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson('/api/v1/admin/home-content/hero', [
                'payload' => [
                    'title' => '  كل ما تحتاجه لدرسك  ',
                    'subtitle' => 'اختر مرحلتك وصفّك.',
                    'cta_label' => 'تصفّح المنتجات',
                    'cta_url' => '/shop',
                    'trust' => ['تحميل فوري بعد الدفع', '   ', 'صيغ Word وPowerPoint'],
                ],
            ])
            ->assertOk();

        $hero = HomeContent::hero();

        self::assertNotNull($hero);
        /* التشذيب يقع قبل الحفظ، فلا يُخزَّن ما سيُقرأ مشذَّبًا. */
        self::assertSame('كل ما تحتاجه لدرسك', $hero['title']);
        self::assertSame('/shop', $hero['cta_url']);
        /* الفراغ يسقط من الشرائح — لا شريحةَ بيضاء في المتجر. */
        self::assertSame(['تحميل فوري بعد الدفع', 'صيغ Word وPowerPoint'], $hero['trust']);
    }

    public function test_a_hero_without_a_title_is_refused_storage_not_stored_headless(): void
    {
        /*
         * القارئ يُعيد null لكتلةٍ بلا عنوان. فحفظُها كان سيُنتج صفًّا في
         * `settings` لا يُصيَّر أبدًا — ومحرّرًا يظنّ أنّه كتب شيئًا ولم يكتب.
         */
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson('/api/v1/admin/home-content/hero', ['payload' => ['subtitle' => 'نصٌّ بلا عنوان']])
            ->assertOk()
            ->assertJsonPath('data.value', null);

        self::assertNull(HomeContent::payload(HomeContent::HERO));
        self::assertNull(HomeContent::hero());
    }

    public function test_emptying_a_block_deletes_the_row_rather_than_storing_an_empty_object(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/hero', ['payload' => ['title' => 'عنوانٌ مؤقّت']])
            ->assertOk();
        self::assertNotNull(HomeContent::payload(HomeContent::HERO));

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/hero', ['payload' => ['title' => '']])
            ->assertOk();
        self::assertNull(HomeContent::payload(HomeContent::HERO));
    }

    /* ───── القوائم ───── */

    public function test_a_faq_entry_missing_its_answer_is_dropped_on_write(): void
    {
        /* سؤالٌ بلا جواب وعدٌ فارغ — يسقط هنا كما يسقط عند القراءة. */
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson('/api/v1/admin/home-content/faq', [
                'payload' => [
                    ['question' => 'متى يصلني الملف؟', 'answer' => 'فور نجاح الدفع.'],
                    ['question' => 'سؤالٌ بلا جواب'],
                    ['answer' => 'جوابٌ بلا سؤال'],
                ],
            ])
            ->assertOk();

        $faq = HomeContent::faq();

        self::assertCount(1, $faq);
        self::assertSame('متى يصلني الملف؟', $faq[0]['question']);
        /* `id` مشتقٌّ من الموضع بعد الإسقاط — لا فجوةَ ترقيم. */
        self::assertSame(1, $faq[0]['id']);
    }

    public function test_cards_keep_their_optional_description_and_drop_untitled_rows(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson('/api/v1/admin/home-content/why', [
                'payload' => [
                    ['title' => 'مبنيّة على المنهج', 'description' => 'كل ملف مرتبط بمرحلة ومادة.'],
                    ['title' => 'تحميل فوري'],
                    ['description' => 'وصفٌ بلا عنوان'],
                ],
            ])
            ->assertOk();

        $cards = HomeContent::cards(HomeContent::WHY);

        self::assertCount(2, $cards);
        self::assertArrayHasKey('description', $cards[0]);
        self::assertArrayNotHasKey('description', $cards[1]);
    }

    /* ───── ترتيب الأقسام ───── */

    public function test_layout_keeps_order_and_drops_unknown_ids(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson('/api/v1/admin/home-content/layout', [
                'payload' => ['faq', 'hero', 'لا-وجود-له', 'trust', 'hero'],
            ])
            ->assertOk();

        /* الترتيب كما أُرسل، والمجهول والمكرّر يسقطان — لا يُعاد فرزه. */
        self::assertSame(['faq', 'hero', 'trust'], HomeContent::layout());
    }

    public function test_two_hero_sections_are_refused_with_a_422_that_says_why(): void
    {
        /*
         * `hero` و`smart-finder` كلاهما يحمل `h1`. وتفعيلُهما معًا خرقُ بنيةٍ
         * لا ذوقُ تصميم — ولذلك يُرفض الحفظ ولا يُسقَط أحدهما بصمت: إسقاطه
         * يعني أنّ المالك ضغط «احفظ» فرأى ترتيبًا غير الذي اختاره.
         */
        $response = $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson('/api/v1/admin/home-content/layout', [
                'payload' => ['hero', 'smart-finder', 'trust'],
            ]);

        $response->assertStatus(422);
        self::assertStringContainsString('هيرو', (string) $response->json('message'));
        /* ولا يُكتب شيء: الرفض لا يترك حالةً نصفيّة. */
        self::assertNull(HomeContent::layout());
    }

    public function test_either_hero_alone_is_accepted(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/layout', ['payload' => ['smart-finder', 'trust']])
            ->assertOk();
        self::assertSame(['smart-finder', 'trust'], HomeContent::layout());

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/layout', ['payload' => ['hero', 'trust']])
            ->assertOk();
        self::assertSame(['hero', 'trust'], HomeContent::layout());
    }

    public function test_unset_layout_is_null_not_an_empty_list(): void
    {
        /*
         * الفرق يقرّر ما يعرضه المتجر: `null` ⇒ «لم يُضبط» فيقع على ترتيبه
         * الافتراضيّ، و`[]` ⇒ «أخلِ الصفحة». فلا يُحفظ الفراغ أصلًا.
         */
        self::assertNull(HomeContent::layout());

        $owner = $this->makeAdmin(AdminRole::Owner);
        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/layout', ['payload' => ['trust']])
            ->assertOk();
        self::assertSame(['trust'], HomeContent::layout());

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/layout', ['payload' => []])
            ->assertOk();
        self::assertNull(HomeContent::layout());
    }

    public function test_public_endpoint_answers_204_until_the_owner_sets_an_order(): void
    {
        $this->getJson('/api/v1/home/layout')->assertNoContent();

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson('/api/v1/admin/home-content/layout', ['payload' => ['hero', 'faq']])
            ->assertOk();

        $this->getJson('/api/v1/home/layout')
            ->assertOk()
            ->assertJsonPath('sections', ['hero', 'faq']);
    }

    /* ───── التدقيق ───── */

    /** الشريط كتلةٌ بلا عنوان: اللوحة تراه في السجلّ، والكاتب يحفظه بلا `title`. */
    public function test_announcements_block_is_in_the_schema_and_saves_without_a_title(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);

        $index = $this->actingAsAdmin($owner)
            ->getJson('/api/v1/admin/home-content')
            ->assertOk()
            ->json('data');

        self::assertSame('block', $index['schema']['home.announcements']['kind']);
        self::assertFalse($index['schema']['home.announcements']['title']);
        self::assertSame('announcements_mode', $index['schema']['home.announcements']['fields'][0]);
        self::assertSame('block', $index['schema']['home.identity']['kind']);
        self::assertFalse($index['schema']['home.identity']['title']);
        self::assertSame('social_x_url', $index['schema']['home.identity']['fields'][4]);
        self::assertFalse($index['sections']['lesson-bundle']['hero']);
        self::assertFalse($index['sections']['popular-by-grade']['hero']);
        /* قسم «كل المنتجات» مسجَّل للمالك ولا يحمل h1 — يعيش بجانب الهيرو. */
        self::assertSame('كل المنتجات', $index['sections']['all-products']['label']);
        self::assertFalse($index['sections']['all-products']['hero']);

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/announcements', ['payload' => [
                'announcements_mode' => 'static', 'announcement_1' => 'شحن مجّاني', 'ignored' => 'x',
            ]])
            ->assertOk();

        $this->getJson('/api/v1/home/announcements')
            ->assertOk()
            ->assertJsonPath('items', ['شحن مجّاني'])
            ->assertJsonPath('mode', 'static');

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/identity', ['payload' => [
                'document_label' => 'وثيقة العمل الحر', 'document_number' => 'FL-9', 'social_x_url' => 'https://x.com/t',
            ]])
            ->assertOk();

        $this->getJson('/api/v1/home/identity')
            ->assertOk()
            ->assertJsonPath('document.number', 'FL-9')
            ->assertJsonPath('socials.0.network', 'x');

        self::assertSame(
            ['announcements_mode' => 'static', 'announcement_1' => 'شحن مجّاني'],
            HomeContent::payload(HomeContent::ANNOUNCEMENTS),
        );
    }

    public function test_hero_variant_is_normalised_on_save(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/hero', ['payload' => ['title' => 'هيرو', 'variant' => 'story']])
            ->assertOk();
        self::assertSame('slider', HomeContent::payload(HomeContent::HERO)['variant']);

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/hero', ['payload' => ['title' => 'هيرو', 'variant' => 'brand']])
            ->assertOk();
        self::assertSame('brand', HomeContent::payload(HomeContent::HERO)['variant']);

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/hero', ['payload' => ['title' => 'هيرو', 'variant' => 'neon']])
            ->assertOk();
        self::assertArrayNotHasKey('variant', HomeContent::payload(HomeContent::HERO));

        self::assertSame('block', HomeContent::blocks()[HomeContent::PROMO]['kind']);
    }

    public function test_every_edit_is_audited_with_before_and_after(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);

        $this->actingAsAdmin($owner)
            ->putJson('/api/v1/admin/home-content/cta', ['payload' => ['title' => 'ابدأ اليوم']])
            ->assertOk();

        $entry = AdminAuditEntry::query()
            ->where('action', 'home_content.update')
            ->latest('id')
            ->first();

        self::assertNotNull($entry);
        self::assertSame('home.cta', $entry->entity_id);
        self::assertSame($owner->email, $entry->actor_email);
        /* «قبل» فارغة لأوّل كتابة — وهي حقيقةٌ لا نقص. */
        self::assertNull($entry->before);
        self::assertSame('ابدأ اليوم', $entry->after['title']);
    }
}
