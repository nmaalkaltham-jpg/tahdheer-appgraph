<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Domain\Content\HomeContent;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * قارئ الهيرو المتحرّك — `slider` و`slides` في GET /api/v1/home/hero
 * (docs/architecture/HERO-SLIDER-CONTRACT-2026-09-25.md §1).
 *
 * الشرائح هنا تُبذر صفوفًا خامًا في `settings` عمدًا، لا عبر الكاتب: القارئ
 * هو الحارس الأخير، وصفٌّ بُذر يدويًّا أو كتبه إصدارٌ أقدم يجب ألّا يصل
 * المتجر بقيمةٍ خارج العقد.
 */
class HeroSliderTest extends TestCase
{
    use RefreshDatabase;

    private function configure(string $key, array $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private function hero(): array
    {
        return $this->getJson('/api/v1/home/hero')->assertOk()->json();
    }

    public function test_unconfigured_store_still_answers_204(): void
    {
        $this->getJson('/api/v1/home/hero')->assertNoContent();
    }

    public function test_legacy_hero_gains_default_slider_and_empty_slides_without_losing_keys(): void
    {
        $this->configure(HomeContent::HERO, ['title' => 'عنوان', 'cta_label' => 'تصفّح', 'cta_url' => '/shop', 'trust' => ['فوري']]);

        $hero = $this->hero();

        self::assertSame('عنوان', $hero['title']);
        self::assertSame('/shop', $hero['cta_url']);
        self::assertSame(['فوري'], $hero['trust']);
        self::assertSame([], $hero['slides']);
        self::assertSame(HomeContent::SLIDER_DEFAULTS, $hero['slider']);
    }

    public function test_slider_is_clamped_and_closed_lists_fall_back(): void
    {
        $this->configure(HomeContent::HERO, ['title' => 'عنوان']);
        $this->configure(HomeContent::HERO_SLIDER, [
            'autoplay' => false, 'intervalMs' => 999999, 'transition' => 'spin',
            'height' => 'tall', 'ambient' => 'disco', 'showDots' => 'yes', 'showArrows' => '0',
        ]);

        $slider = $this->hero()['slider'];

        self::assertFalse($slider['autoplay']);
        self::assertSame(15000, $slider['intervalMs']);
        self::assertSame('slide', $slider['transition']);
        self::assertSame('tall', $slider['height']);
        self::assertSame('drift', $slider['ambient']);
        /* «yes» ليس منطقيًّا صريحًا فيقع على الافتراض؛ و"0" صريح. */
        self::assertTrue($slider['showDots']);
        self::assertFalse($slider['showArrows']);
        self::assertSame(array_keys(HomeContent::SLIDER_DEFAULTS), array_keys($slider));

        $this->configure(HomeContent::HERO_SLIDER, ['intervalMs' => 10]);
        self::assertSame(3000, $this->hero()['slider']['intervalMs']);
    }

    public function test_slides_are_normalised_exactly_to_the_contract(): void
    {
        $this->configure(HomeContent::HERO_SLIDES, [[
            'id' => 's_8f2c', 'kind' => 'brand', 'title' => '  كل ما يحتاجه المعلّم  ',
            'theme' => 'neon', 'align' => 'right', 'overlay' => 'max',
            'primary' => ['label' => 'تصفّح', 'href' => '/shop'],
            'secondary' => ['label' => 'بلا وجهة'],
            'media' => ['url' => '/storage/hero/a.webp', 'focal' => 'side'],
            'offer' => ['badge' => 'لا يخصّ العلامة'],
        ]]);

        $slide = $this->hero()['slides'][0];

        self::assertSame([
            'id' => 's_8f2c',
            'kind' => 'brand',
            'eyebrow' => null,
            'title' => 'كل ما يحتاجه المعلّم',
            'titleAccent' => null,
            'subtitle' => null,
            'primary' => ['label' => 'تصفّح', 'href' => '/shop'],
            'secondary' => null,
            'theme' => 'navy-teal',
            'align' => 'start',
            'media' => ['url' => '/storage/hero/a.webp', 'alt' => '', 'focal' => 'center'],
            'overlay' => 'soft',
            'showProductCard' => true,
            'offer' => null,
            'search' => null,
        ], $slide);
    }

    public function test_slides_alone_make_a_hero_even_without_legacy_copy(): void
    {
        $this->configure(HomeContent::HERO_SLIDES, [['title' => 'شريحة', 'kind' => 'mystery']]);

        $hero = $this->hero();

        self::assertArrayNotHasKey('title', $hero);
        self::assertSame('brand', $hero['slides'][0]['kind']);
        /* صفٌّ بلا مُعرِّف يُعطى مُعرِّفًا ثابتًا بين قراءتين. */
        self::assertMatchesRegularExpression('/^s_[0-9a-f]{4,8}$/', $hero['slides'][0]['id']);
        self::assertSame($hero['slides'][0]['id'], $this->hero()['slides'][0]['id']);
    }

    public function test_untitled_and_disabled_slides_are_hidden_and_order_is_the_owners(): void
    {
        $this->configure(HomeContent::HERO, ['title' => 'عنوان']);
        $this->configure(HomeContent::HERO_SLIDES, [
            ['id' => 's_0003', 'title' => 'الثالثة'],
            ['id' => 's_0001', 'title' => '   '],
            ['id' => 's_0002', 'title' => 'معطّلة', 'enabled' => false],
            'ليست شريحة',
            ['id' => 's_0004', 'title' => 'الأولى', 'enabled' => true],
        ]);

        self::assertSame(['s_0003', 's_0004'], array_column($this->hero()['slides'], 'id'));
    }

    public function test_at_most_eight_slides_are_read(): void
    {
        $this->configure(HomeContent::HERO_SLIDES, array_map(
            fn (int $i) => ['id' => sprintf('s_%04x', $i), 'title' => "شريحة {$i}"],
            range(1, 10),
        ));

        self::assertCount(8, $this->hero()['slides']);
    }

    public function test_lengths_are_truncated_to_the_contract(): void
    {
        $this->configure(HomeContent::HERO_SLIDES, [[
            'title' => str_repeat('ع', 200), 'subtitle' => str_repeat('ن', 400),
        ]]);

        $slide = $this->hero()['slides'][0];

        self::assertSame(120, mb_strlen($slide['title']));
        self::assertSame(240, mb_strlen($slide['subtitle']));
    }

    public function test_links_must_be_internal_paths_or_https(): void
    {
        $link = fn (string $href) => ['label' => 'زرّ', 'href' => $href];
        $this->configure(HomeContent::HERO_SLIDES, [
            ['title' => 'أ', 'primary' => $link('/shop?grade=1'), 'secondary' => $link('https://example.com/x')],
            ['title' => 'ب', 'primary' => $link('http://example.com'), 'secondary' => $link('//evil.example')],
            ['title' => 'ج', 'primary' => $link('javascript:alert(1)'), 'secondary' => $link('/\\evil.example')],
            ['title' => 'د', 'primary' => $link('shop'), 'secondary' => $link('/with space')],
        ]);

        $slides = $this->hero()['slides'];

        self::assertSame('/shop?grade=1', $slides[0]['primary']['href']);
        self::assertSame('https://example.com/x', $slides[0]['secondary']['href']);
        foreach ([1, 2, 3] as $i) {
            self::assertNull($slides[$i]['primary'], "primary #{$i}");
            self::assertNull($slides[$i]['secondary'], "secondary #{$i}");
        }
    }

    public function test_media_url_must_come_from_storage_or_https(): void
    {
        $media = fn (string $url) => ['kind' => 'image', 'title' => 'صورة', 'media' => ['url' => $url, 'alt' => 'فصل', 'focal' => 'top']];
        $this->configure(HomeContent::HERO_SLIDES, [
            $media('https://cdn.example.com/hero.jpg'),
            $media('/media/hero.jpg'),
            $media(rtrim((string) config('filesystems.disks.public.url'), '/').'/hero/b.webp'),
            $media('http://insecure.example.com/hero.jpg'),
            $media('/etc/passwd'),
            $media('data:image/png;base64,AAAA'),
        ]);

        $slides = $this->hero()['slides'];

        self::assertSame(['url' => 'https://cdn.example.com/hero.jpg', 'alt' => 'فصل', 'focal' => 'top'], $slides[0]['media']);
        self::assertSame('/media/hero.jpg', $slides[1]['media']['url']);
        self::assertNotNull($slides[2]['media']);
        self::assertNull($slides[3]['media']);
        self::assertNull($slides[4]['media']);
        self::assertNull($slides[5]['media']);
    }

    public function test_offer_end_in_the_past_is_sent_as_null(): void
    {
        Carbon::setTestNow('2026-09-25T12:00:00+03:00');
        $this->configure(HomeContent::HERO_SLIDES, [
            ['kind' => 'offer', 'title' => 'قادم', 'offer' => ['badge' => 'خصم ٢٥٪', 'endsAt' => '2026-10-01T00:00:00+03:00', 'couponCode' => 'BACK25']],
            ['kind' => 'offer', 'title' => 'منتهٍ', 'offer' => ['endsAt' => '2026-09-01T00:00:00+03:00']],
            ['kind' => 'offer', 'title' => 'تاريخ تالف', 'offer' => ['endsAt' => 'غدًا']],
        ]);

        $slides = $this->hero()['slides'];

        self::assertSame(['badge' => 'خصم ٢٥٪', 'endsAt' => '2026-10-01T00:00:00+03:00', 'couponCode' => 'BACK25'], $slides[0]['offer']);
        self::assertSame(['badge' => null, 'endsAt' => null, 'couponCode' => null], $slides[1]['offer']);
        self::assertNull($slides[2]['offer']['endsAt']);
        self::assertFalse($slides[0]['showProductCard']);
        Carbon::setTestNow();
    }

    public function test_search_chips_are_capped_at_six_and_each_must_be_valid(): void
    {
        $chips = array_map(fn (int $i) => ['label' => "صف {$i}", 'href' => "/grade/{$i}"], range(1, 8));
        array_unshift($chips, ['label' => 'بلا وجهة'], ['label' => 'خارجيّ', 'href' => 'http://x.com'], 'نصّ');
        $this->configure(HomeContent::HERO_SLIDES, [[
            'kind' => 'search', 'title' => 'ابحث', 'primary' => ['label' => 'لا يخصّ البحث', 'href' => '/x'],
            'search' => ['placeholder' => 'ابحث عن درس', 'chips' => $chips],
        ]]);

        $slide = $this->hero()['slides'][0];

        self::assertSame('ابحث عن درس', $slide['search']['placeholder']);
        self::assertCount(6, $slide['search']['chips']);
        self::assertSame(['label' => 'صف 1', 'href' => '/grade/1'], $slide['search']['chips'][0]);
        self::assertNull($slide['primary']);
        self::assertNull($slide['offer']);
    }
}
