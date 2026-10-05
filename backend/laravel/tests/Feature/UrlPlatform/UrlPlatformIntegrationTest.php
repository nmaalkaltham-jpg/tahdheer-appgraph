<?php

namespace Tests\Feature\UrlPlatform;

use App\Domain\Navigation\Models\NavigationMenu;
use App\Domain\Navigation\NavigationCache;
use App\Domain\UrlPlatform\InternalLinkRepair;
use App\Domain\UrlPlatform\Models\UrlRegistryEntry;
use App\Domain\UrlPlatform\RouteBuilders;
use App\Domain\UrlPlatform\UrlRegistry;
use App\Domain\UrlPlatform\UrlStatus;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlPlatformIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_links_are_rewritten_exactly_when_a_slug_changes(): void
    {
        $menu = NavigationMenu::query()->updateOrCreate(['location' => 'header'], [
            'name' => 'الرأس',
            'draft_nodes' => ['a' => ['id' => 'a', 'url' => '/products/old', 'label' => 'قديم'], 'b' => ['id' => 'b', 'url' => '/products/older', 'label' => 'غيره']],
            'published_nodes' => ['a' => ['id' => 'a', 'url' => '/products/old', 'label' => 'قديم']],
            'published_version' => 1,
            'published_at' => now(),
        ]);
        NavigationCache::published('header');

        $result = app(InternalLinkRepair::class)->repair('/products/old', '/products/new');

        $this->assertSame(['menus' => 1, 'links' => 2], $result);
        $fresh = $menu->fresh();
        $this->assertSame('/products/new', $fresh->draft_nodes['a']['url']);
        $this->assertSame('/products/older', $fresh->draft_nodes['b']['url'], 'المطابقة حرفيّة لا بالبادئة');
        $this->assertSame('/products/new', NavigationCache::published('header')['a']['url'], 'المخزن أُبطل');
    }

    public function test_backfill_registers_taxonomy_terms_as_published_and_is_idempotent(): void
    {
        Stage::query()->create(['slug' => 'الابتدائية', 'name_ar' => 'الابتدائيّة', 'position' => 1]);

        $this->artisan('url:backfill')->assertSuccessful();
        $this->artisan('url:backfill')->assertSuccessful();

        $entry = UrlRegistryEntry::query()->where('entity_type', 'stage')->firstOrFail();
        $this->assertSame(RouteBuilders::path('stage', 'الابتدائية'), $entry->path);
        $this->assertSame(UrlStatus::Published->value, $entry->status);
        $this->assertSame(1, UrlRegistryEntry::query()->where('entity_type', 'stage')->count());
    }

    public function test_taxonomy_paths_mirror_the_storefront_shape(): void
    {
        $this->assertSame('/stages/الابتدائية', RouteBuilders::path('stage', 'الابتدائية'));
        $this->assertSame('/grades/grade-4', RouteBuilders::path('grade', 'grade-4'));
        $this->assertSame('/products/math-4', RouteBuilders::product('math-4'));
        $this->assertSame('/en/products/math-4', RouteBuilders::product('math-4', 'en'));
    }

    public function test_slug_change_history_makes_the_old_path_resolvable_for_menus(): void
    {
        $registry = app(UrlRegistry::class);
        $entry = $registry->publish($registry->claim('stage', 5, 'ar', 'primary'));
        $registry->changeSlug($entry, 'elementary');

        $resolution = $registry->resolve('/stages/primary');
        $this->assertSame('redirect', $resolution['outcome']);
        $this->assertSame('/stages/elementary', $resolution['redirect']['target']);
    }
}
