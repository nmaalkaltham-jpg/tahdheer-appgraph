<?php

namespace Tests\Feature;

use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxProcessor;
use App\Domain\Outbox\OutboxRegistry;
use App\Domain\Outbox\OutboxWriter;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OutboxRegistry::flush();
    }

    public function test_writer_commits_atomically_with_the_domain_change(): void
    {
        DB::transaction(function () {
            Setting::query()->create(['key' => 'demo', 'value' => ['on' => true]]);
            (new OutboxWriter)->write('setting', 'demo', 'setting.changed', ['key' => 'demo'], 'setting:demo:v1');
        });

        $this->assertDatabaseHas('settings', ['key' => 'demo']);
        $this->assertDatabaseHas('outbox_events', [
            'idempotency_key' => 'setting:demo:v1',
            'status' => 'PENDING',
        ]);
    }

    public function test_rollback_discards_domain_change_and_outbox_row_together(): void
    {
        try {
            DB::transaction(function () {
                Setting::query()->create(['key' => 'doomed', 'value' => null]);
                (new OutboxWriter)->write('setting', 'doomed', 'setting.changed', [], 'setting:doomed:v1');
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseMissing('settings', ['key' => 'doomed']);
        $this->assertDatabaseMissing('outbox_events', ['idempotency_key' => 'setting:doomed:v1']);
    }

    public function test_writer_is_idempotent_on_the_key(): void
    {
        DB::transaction(function () {
            $writer = new OutboxWriter;
            $first = $writer->write('order', '9', 'order.paid', ['n' => 1], 'order:9:paid');
            $second = $writer->write('order', '9', 'order.paid', ['n' => 2], 'order:9:paid');
            $this->assertSame($first->id, $second->id);
        });

        $this->assertSame(1, OutboxEvent::query()->where('idempotency_key', 'order:9:paid')->count());
    }

    public function test_processor_marks_consumed_events_processed(): void
    {
        $consumed = [];
        OutboxRegistry::register('order.paid', function (OutboxEvent $event) use (&$consumed) {
            $consumed[] = $event->idempotency_key;
        });

        DB::transaction(fn () => (new OutboxWriter)->write('order', '5', 'order.paid', ['order_id' => 5], 'order:5:paid'));

        $stats = (new OutboxProcessor)->process();

        $this->assertSame(['order:5:paid'], $consumed);
        $this->assertSame(1, $stats['processed']);

        $event = OutboxEvent::query()->firstWhere('idempotency_key', 'order:5:paid');
        $this->assertSame('PROCESSED', $event->status);
        $this->assertNotNull($event->processed_at);
    }

    public function test_failing_consumer_retries_with_backoff_then_dead(): void
    {
        OutboxRegistry::register('order.paid', function () {
            throw new RuntimeException('consumer down');
        });

        DB::transaction(fn () => (new OutboxWriter)->write('order', '7', 'order.paid', [], 'order:7:paid'));

        $processor = new OutboxProcessor;

        // Attempt 1: fails, backs off 1 minute, stays PENDING.
        $stats = $processor->process();
        $event = OutboxEvent::query()->firstWhere('idempotency_key', 'order:7:paid');
        $this->assertSame(1, $stats['failed']);
        $this->assertSame('PENDING', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertTrue($event->next_retry_at->isFuture());

        // Not yet due: nothing is claimed.
        $this->assertSame(0, array_sum($processor->process()));

        // Walk through the exponential ladder: 1, 5, 25, 125 minutes → DEAD on the 5th.
        foreach ([5, 25, 125] as $expectedBackoff) {
            $this->travelTo($event->next_retry_at->copy()->addSecond());
            $processor->process();
            $event->refresh();
            $this->assertSame('PENDING', $event->status);
            $this->assertSame(
                $expectedBackoff,
                (int) round($event->updated_at->diffInMinutes($event->next_retry_at)),
            );
        }

        $this->travelTo($event->next_retry_at->copy()->addSecond());
        $stats = $processor->process();
        $event->refresh();

        $this->assertSame(1, $stats['dead']);
        $this->assertSame('DEAD', $event->status);
        $this->assertSame(5, $event->attempts);
    }

    public function test_a_row_stranded_in_processing_is_reclaimed_after_the_claim_timeout(): void
    {
        $seen = [];
        OutboxRegistry::register('setting.changed', function (OutboxEvent $e) use (&$seen) {
            $seen[] = $e->id;
        });

        $stranded = DB::transaction(fn () => (new OutboxWriter)->write('setting', 's', 'setting.changed', [], 'setting:s:stranded'));
        // عاملٌ مات بعد الاستحواذ وقبل الختم.
        $stranded->forceFill(['status' => 'PROCESSING', 'updated_at' => now()->subMinutes(OutboxProcessor::CLAIM_TIMEOUT_MINUTES + 1)])->save();

        $fresh = DB::transaction(fn () => (new OutboxWriter)->write('setting', 's', 'setting.changed', [], 'setting:s:fresh-claim'));
        $fresh->forceFill(['status' => 'PROCESSING'])->save(); // ما زال حيًّا — لا يُنتزع

        (new OutboxProcessor)->process();

        $this->assertContains($stranded->id, $seen);
        $this->assertNotContains($fresh->id, $seen);
        $this->assertSame('PROCESSED', $stranded->fresh()->status);
        $this->assertSame(1, (int) $stranded->fresh()->attempts); // الاستعادة محاولةٌ تُعدّ
    }

    public function test_a_row_reclaimed_too_often_dies_instead_of_looping(): void
    {
        OutboxRegistry::register('setting.changed', fn () => null);
        $poison = DB::transaction(fn () => (new OutboxWriter)->write('setting', 'p', 'setting.changed', [], 'setting:p:poison'));
        $poison->forceFill([
            'status' => 'PROCESSING', 'attempts' => OutboxProcessor::MAX_ATTEMPTS - 1,
            'updated_at' => now()->subMinutes(OutboxProcessor::CLAIM_TIMEOUT_MINUTES + 1),
        ])->save();

        $stats = (new OutboxProcessor)->process();

        $this->assertSame('DEAD', $poison->fresh()->status);
        $this->assertSame(1, $stats['dead']);
    }
}
