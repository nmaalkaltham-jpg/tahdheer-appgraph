<?php

namespace Tests\Feature\UrlPlatform;

use App\Domain\Outbox\OutboxEvent;
use App\Domain\Redirects\RedirectRule;
use App\Domain\UrlPlatform\Models\UrlHistory;
use App\Domain\UrlPlatform\UrlPlatformError;
use App\Domain\UrlPlatform\UrlRegistry;
use App\Domain\UrlPlatform\UrlStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlRegistryTest extends TestCase
{
    use RefreshDatabase;

    private UrlRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(UrlRegistry::class);
    }

    public function test_claim_reserves_the_path_and_suffixes_deterministically_on_collision(): void
    {
        $a = $this->registry->claim('product', 1, 'ar', 'math');
        $b = $this->registry->claim('product', 2, 'ar', 'math');
        $c = $this->registry->claim('product', 3, 'ar', 'Math');

        $this->assertSame('/products/math', $a->path);
        $this->assertSame('math-2', $b->slug);
        $this->assertSame('Math-3', $c->slug, 'التفرّد لا يفرّق بين الحالتين');
        $this->assertSame(UrlStatus::Reserved->value, $a->status);
    }

    public function test_claim_without_suffix_reports_collision_by_name(): void
    {
        $this->registry->claim('product', 1, 'ar', 'math');

        $this->expectException(UrlPlatformError::class);
        $this->expectExceptionMessage('مملوكٌ لكيانٍ آخر');
        $this->registry->claim('product', 2, 'ar', 'math', ['allowSuffix' => false]);
    }

    public function test_reserved_names_are_refused(): void
    {
        $this->expectException(UrlPlatformError::class);
        $this->expectExceptionMessage('محجوزٌ');
        $this->registry->claim('page', 1, 'ar', 'admin');
    }

    public function test_changing_a_published_slug_writes_history_redirect_and_events_atomically(): void
    {
        $entry = $this->registry->publish($this->registry->claim('product', 7, 'ar', 'old-name'));

        $updated = $this->registry->changeSlug($entry, 'new-name', ['actorId' => 9, 'reason' => 'إعادة تسمية', 'expectedVersion' => $entry->version]);

        $this->assertSame('/products/new-name', $updated->path);
        $this->assertSame($entry->version + 1, $updated->version);

        $rule = RedirectRule::query()->where('from_path', '/products/old-name')->firstOrFail();
        $this->assertSame('/products/new-name', $rule->to_path);
        $this->assertSame(301, (int) $rule->status_code);

        $history = UrlHistory::query()->where('registry_id', $entry->id)->where('event', 'slug_changed')->firstOrFail();
        $this->assertSame('/products/old-name', $history->previous_path);
        $this->assertSame($rule->id, (int) $history->redirect_rule_id);
        $this->assertSame(9, (int) $history->actor_id);

        $events = OutboxEvent::query()->where('aggregate_type', 'url')->pluck('event_type')->all();
        $this->assertContains('url.slug_changed', $events);
        $this->assertContains('url.redirect_created', $events);

        // الحلّ يشرح المسار القديم كتحويل ٣٠١ إلى الكيان.
        $resolution = $this->registry->resolve('/products/old-name/');
        $this->assertSame('redirect', $resolution['outcome']);
        $this->assertSame(301, $resolution['httpStatus']);
        $this->assertSame(7, $resolution['entity']['entityId']);
    }

    public function test_two_changes_flatten_into_one_hop(): void
    {
        $entry = $this->registry->publish($this->registry->claim('product', 7, 'ar', 'a'));
        $entry = $this->registry->changeSlug($entry, 'b');
        $this->registry->changeSlug($entry, 'c');

        $this->assertSame('/products/c', RedirectRule::query()->where('from_path', '/products/a')->value('to_path'));
        $this->assertSame('/products/c', RedirectRule::query()->where('from_path', '/products/b')->value('to_path'));
    }

    public function test_a_draft_slug_change_writes_no_redirect(): void
    {
        $entry = $this->registry->claim('product', 7, 'ar', 'draft-a');
        $this->registry->changeSlug($entry, 'draft-b');

        $this->assertSame(0, RedirectRule::query()->count());
        $this->assertSame(1, UrlHistory::query()->where('event', 'slug_changed')->count());
    }

    public function test_locked_entries_refuse_changes_unless_forced(): void
    {
        $entry = $this->registry->lock($this->registry->publish($this->registry->claim('product', 7, 'ar', 'fixed')));

        try {
            $this->registry->changeSlug($entry, 'moved');
            $this->fail('كان يجب أن يُرفض');
        } catch (UrlPlatformError $e) {
            $this->assertSame('LOCKED', $e->errorCode);
        }

        $forced = $this->registry->changeSlug($entry, 'moved', ['force' => true]);
        $this->assertSame('/products/moved', $forced->path);
    }

    public function test_historical_slugs_of_another_entity_are_protected(): void
    {
        $entry = $this->registry->publish($this->registry->claim('product', 7, 'ar', 'first'));
        $this->registry->changeSlug($entry, 'second');

        try {
            $this->registry->claim('product', 8, 'ar', 'first', ['allowSuffix' => false]);
            $this->fail('كان يجب أن يُرفض');
        } catch (UrlPlatformError $e) {
            $this->assertSame('PROTECTED_HISTORY', $e->errorCode);
        }

        // الكيان نفسه يرجع إلى رابطه بلا حرج.
        $back = $this->registry->rollback($entry->fresh(), UrlHistory::query()->where('event', 'slug_changed')->value('id'));
        $this->assertSame('/products/first', $back->path);
    }

    public function test_stale_version_is_refused(): void
    {
        $entry = $this->registry->publish($this->registry->claim('product', 7, 'ar', 'v'));

        try {
            $this->registry->changeSlug($entry, 'w', ['expectedVersion' => $entry->version - 1]);
            $this->fail('كان يجب أن يُرفض');
        } catch (UrlPlatformError $e) {
            $this->assertSame('STALE_VERSION', $e->errorCode);
        }
    }

    public function test_retire_with_gone_policy_resolves_to_410(): void
    {
        $entry = $this->registry->publish($this->registry->claim('product', 7, 'ar', 'gone-soon'));
        $this->registry->retire($entry, ['policy' => 'gone', 'reason' => 'سُحب']);

        $resolution = $this->registry->resolve('/products/gone-soon');
        $this->assertSame('gone', $resolution['outcome']);
        $this->assertSame(410, $resolution['httpStatus']);
        // المسار تحرّر للتاريخ لا للاستعمال: كيانٌ آخر لا يأخذه.
    }

    public function test_retire_with_redirect_policy_creates_the_rule(): void
    {
        $entry = $this->registry->publish($this->registry->claim('product', 7, 'ar', 'old-kit'));
        $this->registry->publish($this->registry->claim('product', 8, 'ar', 'new-kit'));
        $this->registry->retire($entry, ['policy' => 'redirect', 'target' => '/products/new-kit']);

        $this->assertSame('/products/new-kit', RedirectRule::query()->where('from_path', '/products/old-kit')->value('to_path'));
        $this->assertSame(UrlStatus::Replaced->value, $entry->fresh()->status);
    }

    public function test_resolve_explains_reserved_unknown_and_suggests_near_misses(): void
    {
        $this->registry->publish($this->registry->claim('product', 1, 'ar', 'math-grade-4'));

        $reserved = $this->registry->resolve('/admin/anything');
        $this->assertSame('reserved', $reserved['outcome']);

        $missing = $this->registry->resolve('/products/math-grade-5');
        $this->assertSame('not_found', $missing['outcome']);
        $this->assertSame(404, $missing['httpStatus']);
        $this->assertSame('/products/math-grade-4', $missing['suggestions'][0]['path']);

        $hostile = $this->registry->resolve('/products/../admin');
        $this->assertSame('invalid', $hostile['outcome']);
    }

    public function test_health_reports_measured_counters(): void
    {
        $this->registry->publish($this->registry->claim('product', 1, 'ar', 'x'));
        $health = $this->registry->health();

        $this->assertSame(1, $health['registry']['total']);
        $this->assertSame([], $health['redirects']['loops']);
        $this->assertSame('NOT_MEASURED', $health['orphans']['state']);
    }
}
