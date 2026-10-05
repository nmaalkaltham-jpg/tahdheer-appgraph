<?php

namespace Tests\Feature\Search;

use App\Domain\Outbox\OutboxProcessor;
use App\Domain\Outbox\OutboxRegistry;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Search\Indexing\SearchOutboxSubscriber;
use App\Domain\Search\Indexing\SyncProductJob;
use App\Domain\Search\Models\SearchIndexState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Index build/swap/rollback and outbox-driven sync — all Meilisearch HTTP is
 * faked and payload-asserted; no live engine ran for these tests.
 */
class IndexingTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        OutboxRegistry::flush();
    }

    public function test_reindex_builds_next_generation_pushes_settings_and_published_documents_then_swaps(): void
    {
        $this->seedSearchWorld();
        $this->seedCorpus();
        $this->makeProduct('TD-DRAFT', 'مسودة غير منشورة', 100, ['status' => 'draft', 'published_at' => null]);
        Http::fake();

        $this->artisan('search:reindex')->assertExitCode(0);

        $state = SearchIndexState::forEngine('meilisearch');
        $this->assertSame('products_v1', $state->index_name);
        $this->assertSame(1, (int) $state->generation);
        $this->assertSame('ready', $state->status);
        $this->assertNotNull($state->last_built_at);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/indexes/products_v1/settings')
            && isset($r['typoTolerance'])
            && $r['typoTolerance']['disableOnAttributes'] === ['sku']);

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'PUT' || ! str_contains($r->url(), '/indexes/products_v1/documents')) {
                return false;
            }
            $skus = array_column($r->data(), 'sku');

            return count($skus) === 6 && ! in_array('TD-DRAFT', $skus, true);
        });
    }

    public function test_swap_is_versioned_and_rollback_points_back_one_generation(): void
    {
        $this->seedSearchWorld();
        Http::fake();

        $this->artisan('search:reindex')->assertExitCode(0);
        $this->artisan('search:reindex')->assertExitCode(0);
        $this->assertSame('products_v2', SearchIndexState::forEngine('meilisearch')->index_name);

        $this->artisan('search:reindex', ['--rollback' => true])->assertExitCode(0);
        $state = SearchIndexState::forEngine('meilisearch');
        $this->assertSame('products_v1', $state->index_name);
        $this->assertSame(1, (int) $state->generation);
    }

    public function test_rollback_refuses_when_no_previous_generation_exists(): void
    {
        $this->artisan('search:reindex', ['--rollback' => true])->assertExitCode(1);
    }

    public function test_failed_build_marks_state_failed_and_keeps_the_live_index(): void
    {
        $this->seedSearchWorld();
        Http::fake(['*' => Http::response(['message' => 'engine down'], 500)]);

        $this->artisan('search:reindex')->assertExitCode(1);

        $state = SearchIndexState::forEngine('meilisearch');
        $this->assertSame('failed', $state->status);
        $this->assertSame('products_v0', $state->index_name);
        $this->assertSame(0, (int) $state->generation);
    }

    public function test_sync_command_upserts_published_and_deletes_unpublished(): void
    {
        $this->seedSearchWorld();
        $published = $this->makeProduct('TD-SY1', 'تحضير الوحدة الثانية', 900, ['resource_type' => 'تحضير']);
        $draft = $this->makeProduct('TD-SY2', 'مسودة قيد الإعداد', 900, ['status' => 'draft', 'published_at' => null]);
        Http::fake();

        $this->artisan('search:sync', ['product' => $published->id])->assertExitCode(0);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/documents')
            && $r->data()[0]['sku'] === 'TD-SY1');

        $this->artisan('search:sync', ['product' => $draft->id])->assertExitCode(0);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/documents/'.$draft->id));

        $this->artisan('search:sync', ['product' => 999999])->assertExitCode(1);
    }

    public function test_outbox_product_events_dispatch_the_queueable_sync_job(): void
    {
        Queue::fake();
        SearchOutboxSubscriber::register();

        DB::transaction(fn () => (new OutboxWriter)->write('product', '42', 'product.published', ['id' => 42], 'product:42:published'));
        DB::transaction(fn () => (new OutboxWriter)->write('product', '42', 'product.updated', ['id' => 42], 'product:42:updated'));

        $stats = (new OutboxProcessor)->process();

        $this->assertSame(2, $stats['processed']);
        Queue::assertPushed(SyncProductJob::class, 2);
        Queue::assertPushed(SyncProductJob::class, fn (SyncProductJob $job) => $job->productId === 42);
    }
}
