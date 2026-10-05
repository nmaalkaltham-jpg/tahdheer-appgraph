<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Domain\Navigation\MenuTree;
use App\Domain\Navigation\Models\NavigationMenu;
use App\Domain\Navigation\NavigationCache;
use App\Domain\Navigation\NavigationOutboxSubscriber;
use App\Domain\Navigation\NavigationRevalidator;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxRegistry;
use App\Domain\UrlPlatform\Jobs\RevalidateStorefrontJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * العقد العامّ للتنقّل، وحلقة الإبطال التي كانت مفقودة.
 *
 * الخطر الذي تحرسه: نشرٌ يُعلَن ناجحًا ولا يصل زائرًا — إمّا لأنّ الاستجابة
 * لا تحمل ما يُقارَن به (رقم الإصدار)، أو لأنّ المتجر لا يُخطَر فيبقى على
 * نسخته المخزّنة.
 */
class NavigationContractTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<array{0: string, 1: string}> $pairs */
    private function publish(array $pairs, string $location = 'header', int $revision = 3): NavigationMenu
    {
        $nodes = MenuTree::normalize(array_map(
            static fn (array $p): array => ['id' => $p[0], 'parentId' => null, 'label' => $p[0], 'url' => $p[1]],
            $pairs,
        ));

        // `updateOrCreate`: الترويسة والتذييل يصلان مزروعَين من هجرتَي النقل.
        $menu = NavigationMenu::query()->updateOrCreate(
            ['location' => $location],
            [
                'name' => MenuTree::LOCATIONS[$location],
                'draft_nodes' => $nodes,
                'published_nodes' => $nodes,
                'published_version' => $revision,
                'published_at' => now(),
                'version' => $revision,
            ],
        );
        NavigationCache::invalidate($location);

        return $menu;
    }

    /* ───── العقد العامّ ───── */

    public function test_the_public_response_carries_the_revision_it_is_serving(): void
    {
        $menu = $this->publish([['home', '/']], 'header', 7);

        $body = $this->getJson('/api/v1/navigation/header')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('tahdheer.navigation/v1', $body['meta']['schema']);
        $this->assertSame('header', $body['meta']['location']);
        $this->assertSame(7, $body['meta']['revision'], 'بلا رقم الإصدار لا يمكن إثبات أنّ ما نُشر هو ما يُخدَم');
        $this->assertFalse($body['meta']['inherited']);
        $this->assertNotNull($body['meta']['publishedAt']);
        // البصمة تصف الشجرة المخدومة نفسها لا شجرةً أخرى.
        $this->assertSame(
            MenuTree::fingerprint((array) $menu->published_nodes),
            $body['meta']['fingerprint'],
        );
    }

    public function test_an_unpublished_location_is_an_honest_empty_with_a_null_revision(): void
    {
        /*
         * التذييل يصل مزروعًا من هجرة نقله من الشيفرة، فـ«غير منشور» حالةٌ
         * تُصنع صراحةً هنا لا تُفترض. ولو افتُرضت لصار الاختبار يمرّ لأنّ
         * القاعدة فارغة لا لأنّ الاستجابة صادقة.
         */
        NavigationMenu::query()->where('location', 'footer')->update([
            'published_nodes' => null,
            'published_version' => null,
            'published_at' => null,
        ]);
        NavigationCache::invalidate('footer');

        $body = $this->getJson('/api/v1/navigation/footer')->assertOk()->json();

        $this->assertSame([], $body['data']);
        $this->assertNull($body['meta']['revision']);
        $this->assertNull($body['meta']['publishedAt']);
        $this->assertFalse($body['meta']['inherited']);
    }

    /** الوراثة تُعلَن: مشغّلٌ ينشر الجوّال ولا يرى فرقًا يحتاج أن يعرف السبب. */
    public function test_inheritance_is_declared_not_hidden(): void
    {
        $this->publish([['home', '/']], 'header', 4);

        $body = $this->getJson('/api/v1/navigation/mobile')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertTrue($body['meta']['inherited']);
        $this->assertSame(4, $body['meta']['revision'], 'الإصدار المُعلَن هو إصدار ما خُدم فعلًا');
    }

    public function test_the_envelope_never_reports_a_revision_for_a_tree_it_did_not_serve(): void
    {
        $this->publish([['a', '/a']], 'header', 1);
        $first = $this->getJson('/api/v1/navigation/header')->json('meta');

        $menu = NavigationMenu::query()->where('location', 'header')->firstOrFail();
        $menu->forceFill([
            'published_nodes' => MenuTree::normalize([['id' => 'b', 'parentId' => null, 'label' => 'ب', 'url' => '/b']]),
            'published_version' => 2,
        ])->save();
        NavigationCache::invalidate('header');

        $second = $this->getJson('/api/v1/navigation/header')->json('meta');

        $this->assertNotSame($first['fingerprint'], $second['fingerprint']);
        $this->assertSame(2, $second['revision']);
    }

    /* ───── حلقة الإبطال ───── */

    public function test_publishing_a_location_revalidates_it_and_everything_that_inherits_it(): void
    {
        $this->assertSame(['navigation:header', 'navigation:mobile'], NavigationRevalidator::tagsFor('header'));
        $this->assertSame(['navigation:footer'], NavigationRevalidator::tagsFor('footer'));
    }

    /**
     * الحلقة المفقودة: حدثٌ يُكتب ولا يستهلكه أحد.
     *
     * كان `navigation.published` يُكتب في الصندوق الصادر ولا مشترِك له، فيبطل
     * كاش Laravel ويبقى المتجر على نسخته حتّى تنقضي مهلتها.
     */
    public function test_a_navigation_publish_event_dispatches_a_tagged_revalidation(): void
    {
        Queue::fake();
        OutboxRegistry::flush();
        NavigationOutboxSubscriber::register();

        $consumers = OutboxRegistry::consumersFor('navigation.published');
        $this->assertCount(1, $consumers, 'لا مشترِك ⇒ النشر لا يبلغ المتجر أبدًا');

        $event = new OutboxEvent;
        $event->forceFill([
            'id' => 991,
            'event_type' => 'navigation.published',
            'payload' => ['location' => 'header', 'revision' => 5],
        ]);

        $consumers[0]($event);

        Queue::assertPushed(RevalidateStorefrontJob::class, static function (RevalidateStorefrontJob $job): bool {
            return $job->tags === ['navigation:header', 'navigation:mobile'] && $job->paths === [];
        });
    }

    public function test_a_rollback_revalidates_too(): void
    {
        Queue::fake();
        OutboxRegistry::flush();
        NavigationOutboxSubscriber::register();

        $consumers = OutboxRegistry::consumersFor('navigation.rolled_back');
        $this->assertCount(1, $consumers);

        $event = new OutboxEvent;
        $event->forceFill(['id' => 992, 'event_type' => 'navigation.rolled_back', 'payload' => ['location' => 'footer']]);
        $consumers[0]($event);

        Queue::assertPushed(RevalidateStorefrontJob::class, static fn (RevalidateStorefrontJob $job): bool => $job->tags === ['navigation:footer']);
    }

    /** موقعٌ مجهول في الحمولة لا يُبطل وسمًا مخترَعًا. */
    public function test_an_unknown_location_in_the_payload_revalidates_nothing(): void
    {
        Queue::fake();
        OutboxRegistry::flush();
        NavigationOutboxSubscriber::register();

        $event = new OutboxEvent;
        $event->forceFill(['id' => 993, 'event_type' => 'navigation.published', 'payload' => ['location' => 'nowhere']]);
        OutboxRegistry::consumersFor('navigation.published')[0]($event);

        Queue::assertNothingPushed();
    }
}
