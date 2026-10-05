<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Search\Models\SearchIndexState;
use App\Domain\Search\Providers\MeilisearchProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * انحرافُ الفهرس يُكشف ويُصلَح — لا يُترك حتّى إعادة البناء الكاملة.
 *
 * المزامنة الحدثيّة يُطلقها متحكّم المنتجات وحده؛ تغييرُ السعر والكلمات المفتاحيّة
 * واستخراجُ ملفّ لا يُعلنها أحد، فيبقى Meilisearch على القديم (قاسه `search:parity`:
 * سعرٌ قديم ونصُّ ملفٍّ قديم). `search:reconcile` يقارن كلَّ وثيقةٍ متوقَّعة بما
 * في الفهرس ويُصلح الفرق وحده.
 */
class IndexReconcileTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    public function test_missing_and_stale_products_are_pushed_and_orphans_removed(): void
    {
        $this->seedSearchWorld();
        $fresh = $this->makeProduct('RC-OK', 'ملزمة سليمة', 1000);
        $stale = $this->makeProduct('RC-STALE', 'ملزمة بسعر جديد', 2000);
        $missing = $this->makeProduct('RC-MISSING', 'ملزمة غير مفهرسة', 3000);
        SearchIndexState::forEngine('meilisearch')->update(['index_name' => 'products_v7', 'generation' => 7, 'status' => 'ready']);

        $provider = app(MeilisearchProvider::class);
        $okDoc = $provider->documentFor($fresh->fresh());
        $staleDoc = ['price_minor' => 999] + $provider->documentFor($stale->fresh());
        Http::fake([
            '*/indexes/products_v7/documents?*' => Http::response(['results' => [$okDoc, $staleDoc, ['id' => 424242, 'title' => 'محذوف']]]),
            '*' => Http::response(['taskUid' => 1], 202),
        ]);

        $this->artisan('search:reconcile')->assertExitCode(0);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/indexes/products_v7/documents')
            && array_column($r->data(), 'id') === [(int) $stale->id]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && array_column($r->data(), 'id') === [(int) $missing->id]);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/indexes/products_v7/documents/424242'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT' && array_column($r->data(), 'id') === [(int) $fresh->id]);
    }

    /**
     * وثيقةٌ يرفضها المحرّك لا تُوقف التوفيق: البقيّة تُصلح، والفاشلةُ تُعدّ
     * وتُسجَّل وتُعاد في التشغيل التالي (الانحراف يُقاس من جديد كلّ مرّة)،
     * والخروجُ فشلٌ ليراه المُجدوِل.
     */
    public function test_one_rejected_document_does_not_abort_the_rest_and_is_reported(): void
    {
        $this->seedSearchWorld();
        $bad = $this->makeProduct('RC-BAD', 'ملزمة يرفضها المحرّك', 1000);
        $good = $this->makeProduct('RC-GOOD', 'ملزمة سليمة غير مفهرسة', 2000);
        SearchIndexState::forEngine('meilisearch')->update(['index_name' => 'products_v7', 'generation' => 7, 'status' => 'ready']);
        Http::fake(function (Request $r) use ($bad) {
            if ($r->method() === 'GET') {
                return Http::response(['results' => []]);
            }
            if ($r->method() === 'PUT' && array_column($r->data(), 'id') === [(int) $bad->id]) {
                return Http::response(['message' => 'boom'], 500);
            }

            return Http::response(['taskUid' => 1], 202);
        });
        Log::spy();

        $this->artisan('search:reconcile')->assertExitCode(1);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && array_column($r->data(), 'id') === [(int) $good->id]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []) => $m === 'search.reconcile.document_failed' && $c['id'] === (int) $bad->id)->once();
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c = []) => $m === 'search.reconcile' && $c['pushed'] === 1 && $c['failed'] === 1)->once();
    }

    public function test_reconcile_is_scheduled_hourly_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'search:reconcile'));

        $this->assertNotNull($event, 'search:reconcile غير مُجدوَل');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_a_dry_run_reports_and_writes_nothing(): void
    {
        $this->seedSearchWorld();
        $this->makeProduct('RC-MISSING', 'ملزمة غير مفهرسة', 3000);
        SearchIndexState::forEngine('meilisearch')->update(['index_name' => 'products_v7', 'generation' => 7, 'status' => 'ready']);
        Http::fake(['*/indexes/products_v7/documents?*' => Http::response(['results' => []]), '*' => Http::response([], 202)]);

        $this->artisan('search:reconcile --dry-run')->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => in_array($r->method(), ['PUT', 'DELETE'], true));
    }
}
