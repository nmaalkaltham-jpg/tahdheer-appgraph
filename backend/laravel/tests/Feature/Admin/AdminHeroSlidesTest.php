<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminAuditEntry;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Content\HomeContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحرير شرائح الهيرو وإعدادات حركته من اللوحة — `home.hero_slides`
 * و`home.hero_slider` عبر الكاتب نفسه والصلاحيّة نفسها (`content.manage`).
 */
class AdminHeroSlidesTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    /** @var array<string, AdminUser> */
    private array $admins = [];

    private function save(string $block, array $payload, AdminRole $role = AdminRole::Owner)
    {
        /* مديرٌ واحد لكلّ دور في الاختبار — `makeAdmin` يبذر بريدًا ثابتًا. */
        $this->admins[$role->value] ??= $this->makeAdmin($role);

        return $this->actingAsAdmin($this->admins[$role->value])
            ->putJson("/api/v1/admin/home-content/{$block}", ['payload' => $payload]);
    }

    public function test_schema_exposes_both_blocks_with_arabic_labels_and_closed_lists(): void
    {
        $schema = $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->getJson('/api/v1/admin/home-content')
            ->assertOk()
            ->assertJsonPath('data.blocks.home\\.hero_slides', null)
            ->json('data.schema');

        $slides = $schema['home.hero_slides'];
        self::assertSame('slides', $slides['kind']);
        self::assertSame(8, $slides['max']);
        self::assertSame(['brand', 'offer', 'search', 'image'], array_column($slides['slide']['kinds'], 'value'));
        self::assertSame('بحث', $slides['slide']['kinds'][2]['label']);
        self::assertContains('search', $slides['slide']['kinds'][2]['fields']);
        self::assertNotContains('primary', $slides['slide']['kinds'][2]['fields']);
        self::assertTrue($slides['slide']['fields']['title']['required']);
        self::assertSame(120, $slides['slide']['fields']['title']['max']);
        self::assertSame(HomeContent::SLIDE_THEMES, array_column($slides['slide']['fields']['theme']['options'], 'value'));

        $slider = $schema['home.hero_slider'];
        self::assertSame('block', $slider['kind']);
        self::assertFalse($slider['title']);
        self::assertSame(['slide', 'fade'], array_column($slider['settings']['transition']['options'], 'value'));
        self::assertSame(3000, $slider['settings']['intervalMs']['min']);
    }

    public function test_writing_needs_content_manage(): void
    {
        $this->save('hero_slides', [['title' => 'x']], AdminRole::ReadOnly)->assertForbidden();
        $this->save('hero_slider', ['autoplay' => false], AdminRole::ReadOnly)->assertForbidden();
        self::assertNull(HomeContent::payload(HomeContent::HERO_SLIDES));
    }

    public function test_ids_are_generated_on_first_save_and_preserved_on_resave(): void
    {
        $first = $this->save('hero_slides', [
            ['kind' => 'brand', 'title' => 'الأولى'],
            ['kind' => 'offer', 'title' => 'الثانية', 'id' => 'not-an-id'],
        ])->assertOk()->json('data.value');

        self::assertCount(2, $first);
        foreach ($first as $slide) {
            self::assertMatchesRegularExpression('/^s_[0-9a-f]{4,8}$/', $slide['id']);
        }
        self::assertNotSame($first[0]['id'], $first[1]['id']);

        /* إعادة ترتيبٍ وتعديل: المُعرِّفان يتبعان شريحتيهما. */
        $second = $this->save('hero_slides', [
            [...$first[1], 'title' => 'الثانية معدّلة'],
            $first[0],
            /* تكرارٌ في اللوحة يحمل مُعرِّف أصله — فيُعطى مُعرِّفًا جديدًا. */
            [...$first[0], 'title' => 'نسخة'],
        ])->assertOk()->json('data.value');

        self::assertSame($first[1]['id'], $second[0]['id']);
        self::assertSame($first[0]['id'], $second[1]['id']);
        self::assertNotContains($second[2]['id'], [$first[0]['id'], $first[1]['id']]);
    }

    public function test_writer_normalises_caps_at_eight_and_drops_untitled(): void
    {
        $rows = array_map(fn (int $i) => ['title' => "شريحة {$i}", 'theme' => 'rainbow'], range(1, 10));
        array_unshift($rows, ['subtitle' => 'بلا عنوان'], 'نصّ');

        $stored = $this->save('hero_slides', $rows)->assertOk()->json('data.value');

        self::assertCount(8, $stored);
        self::assertSame('شريحة 1', $stored[0]['title']);
        self::assertSame('navy-teal', $stored[0]['theme']);
        self::assertTrue($stored[0]['enabled']);
        self::assertSame($stored, HomeContent::payload(HomeContent::HERO_SLIDES));
    }

    public function test_round_trip_disabled_slide_is_stored_but_not_published(): void
    {
        $this->save('hero', ['title' => 'الهيرو'])->assertOk();
        $this->save('hero_slides', [
            ['kind' => 'search', 'title' => 'ابحث', 'enabled' => false, 'search' => ['chips' => [['label' => 'الصف الأول', 'href' => '/grade/1']]]],
            ['kind' => 'image', 'title' => 'صورة', 'media' => ['url' => 'https://cdn.example.com/a.jpg', 'alt' => 'فصل'], 'overlay' => 'strong'],
        ])->assertOk();
        $this->save('hero_slider', ['autoplay' => false, 'intervalMs' => '8000', 'transition' => 'fade', 'ambient' => 'kenburns'])
            ->assertOk()
            ->assertJsonPath('data.value.intervalMs', 8000);

        self::assertCount(2, HomeContent::payload(HomeContent::HERO_SLIDES));

        $hero = $this->getJson('/api/v1/home/hero')->assertOk();
        $hero->assertJsonPath('title', 'الهيرو')
            ->assertJsonCount(1, 'slides')
            ->assertJsonPath('slides.0.kind', 'image')
            ->assertJsonPath('slides.0.overlay', 'strong')
            ->assertJsonPath('slides.0.media.focal', 'center')
            ->assertJsonPath('slider.autoplay', false)
            ->assertJsonPath('slider.transition', 'fade')
            ->assertJsonPath('slider.ambient', 'kenburns')
            ->assertJsonPath('slider.height', 'standard');
        self::assertArrayNotHasKey('enabled', $hero->json('slides.0'));
    }

    public function test_emptying_either_block_deletes_its_row(): void
    {
        $this->save('hero_slides', [['title' => 'x']])->assertOk();
        $this->save('hero_slider', ['autoplay' => false])->assertOk();

        $this->save('hero_slides', [])->assertOk()->assertJsonPath('data.value', null);
        $this->save('hero_slider', [])->assertOk()->assertJsonPath('data.value', null);

        self::assertNull(HomeContent::payload(HomeContent::HERO_SLIDES));
        self::assertNull(HomeContent::payload(HomeContent::HERO_SLIDER));
    }

    public function test_slide_edits_are_audited(): void
    {
        $this->save('hero_slides', [['title' => 'x']])->assertOk();

        $entry = AdminAuditEntry::query()->where('action', 'home_content.update')->latest('id')->first();

        self::assertNotNull($entry);
        self::assertSame('home.hero_slides', $entry->entity_id);
        self::assertSame('x', $entry->after[0]['title']);
    }
}
