<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Analytics\EventIngest;
use App\Domain\Analytics\VisitorIdentity;
use App\Domain\Cart\CartService;
use App\Models\Product;
use App\Models\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commerce\CommerceFixtures;
use Tests\TestCase;

/**
 * قمعُ البحث: بحث ← ظهور ← نقرة ← سلّة ← طلب — منسوبًا بلا بيانات شخصيّة.
 */
class SearchFunnelTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private const VISITOR = 'aaaaaaaa-1111-2222-3333-444444444444';

    private const SESSION = 'bbbbbbbb-1111-2222-3333-444444444444';

    private function consent(): void
    {
        $this->withUnencryptedCookie(VisitorIdentity::VISITOR_COOKIE, self::VISITOR)
            ->withUnencryptedCookie(VisitorIdentity::SESSION_COOKIE, self::SESSION)
            ->postJson('/api/v1/consent', ['granted' => true])->assertOk();
    }

    /** @return array{queryId: int, token: string, ids: list<int>} */
    private function search(string $q): array
    {
        $r = $this->getJson('/api/v1/search?q='.rawurlencode($q))->assertOk();

        return ['queryId' => (int) $r->json('meta.queryId'), 'token' => (string) $r->json('meta.clickToken'), 'ids' => array_map('intval', $r->json('results.items.*.id'))];
    }

    private function impress(array $s, array $ids): TestResponse
    {
        return $this->postJson('/api/v1/search/impressions', ['queryId' => $s['queryId'], 'token' => $s['token'], 'productIds' => $ids]);
    }

    private function click(array $s, int $productId, string $destination = 'pdp'): TestResponse
    {
        return $this->postJson('/api/v1/search/click', ['queryId' => $s['queryId'], 'token' => $s['token'], 'productId' => $productId, 'position' => 1, 'destination' => $destination]);
    }

    private function sessionHash(): string
    {
        return app(EventIngest::class)->hash(self::SESSION);
    }

    public function test_the_search_event_records_what_is_needed_to_reinterpret_it(): void
    {
        $a = $this->makeProduct('FN-1', 1000);
        $this->makeProduct('FN-2', 2000);
        Product::query()->whereKey($a->id)->update(['title' => 'ملزمة الكسور']);

        $s = $this->search('ملزمة الكسور');
        $row = SearchQuery::query()->findOrFail($s['queryId']);

        $this->assertSame('search', $row->source);
        $this->assertSame($s['ids'], $row->result_ids);
        $this->assertSame(1, $row->filters['page']);
        $this->assertIsInt($row->latency_ms);
        $this->assertSame('browse', SearchQuery::query()->findOrFail($this->getJson('/api/v1/search?subject=x')->json('meta.queryId'))->source);
    }

    public function test_impressions_count_once_per_search_and_product_and_only_for_real_results(): void
    {
        $a = $this->makeProduct('FN-1', 1000);
        $other = $this->makeProduct('FN-OTHER', 1000);
        Product::query()->whereKey($a->id)->update(['title' => 'ملزمة الكسور']);
        Product::query()->whereKey($other->id)->update(['title' => 'شيء آخر']);
        $s = $this->search('ملزمة الكسور');

        $this->impress($s, [$a->id, $other->id])->assertNoContent();
        $this->impress($s, [$a->id])->assertNoContent(); // re-render, back navigation, refresh
        $this->postJson('/api/v1/search/impressions', ['queryId' => $s['queryId'], 'token' => str_repeat('0', 64), 'productIds' => [$a->id]])->assertNoContent();

        $rows = DB::table('search_interactions')->where('kind', 'impression')->get();
        $this->assertCount(1, $rows, 'one impression; the product that was not a result is refused');
        $this->assertSame($a->id, (int) $rows[0]->product_id);
        $this->assertSame(1, (int) $rows[0]->rank, 'rank comes from the stored result order, not the client');
    }

    public function test_a_click_counts_once_and_implies_its_impression(): void
    {
        $a = $this->makeProduct('FN-1', 1000);
        Product::query()->whereKey($a->id)->update(['title' => 'ملزمة الكسور']);
        $s = $this->search('ملزمة الكسور');

        $this->click($s, $a->id)->assertNoContent();
        $this->click($s, $a->id)->assertNoContent();

        $this->assertSame(1, DB::table('search_interactions')->where('kind', 'click')->count());
        $this->assertSame(1, DB::table('search_interactions')->where('kind', 'impression')->count());
        $this->assertSame('pdp', DB::table('search_interactions')->where('kind', 'click')->value('destination'));
    }

    public function test_the_session_is_bound_only_with_analytics_consent(): void
    {
        $a = $this->makeProduct('FN-1', 1000);
        Product::query()->whereKey($a->id)->update(['title' => 'ملزمة الكسور']);

        $anonymous = $this->search('ملزمة الكسور');
        $this->withUnencryptedCookie(VisitorIdentity::VISITOR_COOKIE, self::VISITOR)
            ->withUnencryptedCookie(VisitorIdentity::SESSION_COOKIE, self::SESSION);
        $this->impress($anonymous, [$a->id]);
        $this->assertNull(SearchQuery::query()->findOrFail($anonymous['queryId'])->session_hash, 'no consent, no fingerprint');

        $this->consent();
        $consented = $this->search('ملزمة الكسور');
        $this->impress($consented, [$a->id]);
        $this->assertSame($this->sessionHash(), SearchQuery::query()->findOrFail($consented['queryId'])->session_hash);

        $refused = $this->search('ملزمة الكسور');
        $this->withHeader('Sec-GPC', '1');
        $this->impress($refused, [$a->id]);
        $this->assertNull(SearchQuery::query()->findOrFail($refused['queryId'])->session_hash, 'GPC refuses identity');
    }

    public function test_each_order_line_is_attributed_to_its_own_search_and_browsed_products_to_none(): void
    {
        $this->consent();
        $fractions = $this->makeProduct('FN-FRAC', 1500);
        $cells = $this->makeProduct('FN-CELL', 2500);
        $browsed = $this->makeProduct('FN-BROWSE', 900);
        Product::query()->whereKey($fractions->id)->update(['title' => 'ملزمة الكسور']);
        Product::query()->whereKey($cells->id)->update(['title' => 'درس الخلية']);
        Product::query()->whereKey($browsed->id)->update(['title' => 'منتج بالتصفح']);

        $s1 = $this->search('ملزمة الكسور');
        $this->click($s1, $fractions->id);
        $s2 = $this->search('درس الخلية');
        $this->impress($s2, [$cells->id]); // added from the results grid, never clicked

        $cart = $this->cartWith([$fractions, $cells, $browsed]);
        $this->postJson('/api/v1/cart/items', ['productId' => $fractions->id]); // a repeated add
        $order = $this->checkoutOrder($cart)['order'];

        $adds = DB::table('search_attributions')->where('stage', 'add_to_cart')->pluck('search_query_id', 'product_id');
        $this->assertSame([$fractions->id => $s1['queryId'], $cells->id => $s2['queryId']], array_map('intval', $adds->all()));

        $checkout = DB::table('search_attributions')->where('stage', 'checkout')->where('order_id', $order->id)->get()->keyBy('product_id');
        $this->assertCount(2, $checkout, 'the browsed product is not search-attributed');
        $this->assertSame($s1['queryId'], (int) $checkout[$fractions->id]->search_query_id);
        $this->assertSame($s2['queryId'], (int) $checkout[$cells->id]->search_query_id);
        $this->assertSame(1500, (int) $checkout[$fractions->id]->value_minor);
    }

    public function test_an_autocomplete_selection_is_one_search_with_its_click_and_attributes_the_cart(): void
    {
        $this->consent();
        $fractions = $this->makeProduct('FN-AC', 1500);
        $other = $this->makeProduct('FN-AC-OTHER', 900);
        Product::query()->whereKey($fractions->id)->update(['title' => 'ملزمة الكسور']);
        Product::query()->whereKey($other->id)->update(['title' => 'منتج آخر']);

        $suggested = $this->getJson('/api/v1/search/suggest?q='.rawurlencode('ملزمة الكس'))->assertOk();
        $this->assertSame($fractions->id, $suggested->json('items.0.id'));
        $this->assertSame(0, SearchQuery::query()->count(), 'a keystroke is not a search');

        // A product that was not suggested for this text is refused silently.
        $this->postJson('/api/v1/search/suggest/select', ['q' => 'ملزمة الكس', 'productId' => $other->id])->assertNoContent();
        $this->assertSame(0, SearchQuery::query()->count());

        $this->postJson('/api/v1/search/suggest/select', ['q' => 'ملزمة الكس', 'productId' => $fractions->id])->assertNoContent();
        $row = SearchQuery::query()->sole();
        $this->assertSame('autocomplete', $row->source);
        $this->assertSame([$fractions->id], $row->result_ids);
        $this->assertSame($this->sessionHash(), $row->session_hash);
        $this->assertSame(1, DB::table('search_interactions')->where(['search_query_id' => $row->id, 'kind' => 'click', 'rank' => 1])->count());

        $this->postJson('/api/v1/cart/items', ['productId' => $fractions->id]);
        $this->assertSame($row->id, (int) DB::table('search_attributions')->where(['stage' => 'add_to_cart', 'product_id' => $fractions->id])->value('search_query_id'));
    }

    public function test_a_removed_product_loses_its_attribution_and_nothing_is_written_without_consent(): void
    {
        $this->consent();
        $a = $this->makeProduct('FN-1', 1000);
        Product::query()->whereKey($a->id)->update(['title' => 'ملزمة الكسور']);
        $s = $this->search('ملزمة الكسور');
        $this->click($s, $a->id);
        $cart = $this->cartWith([$a]);
        $itemId = (int) $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cart)->getJson('/api/v1/cart')->json('items.0.id');
        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cart)->deleteJson('/api/v1/cart/items/'.$itemId)->assertOk();

        $this->assertSame(1, DB::table('search_attributions')->where('stage', 'remove_from_cart')->count());

        DB::table('search_attributions')->delete();
        DB::table('consent_records')->delete();
        $s2 = $this->search('ملزمة الكسور');
        $this->click($s2, $a->id);
        $this->cartWith([$a]);
        $this->assertSame(0, DB::table('search_attributions')->count(), 'no consent, no attribution');
    }

    public function test_expiry_clears_search_fingerprints_and_keeps_the_counts(): void
    {
        $this->consent();
        $a = $this->makeProduct('FN-1', 1000);
        Product::query()->whereKey($a->id)->update(['title' => 'ملزمة الكسور']);
        $s = $this->search('ملزمة الكسور');
        $this->click($s, $a->id);
        $this->cartWith([$a]);
        SearchQuery::query()->whereKey($s['queryId'])->update(['created_at' => now()->subDays(120)]);
        DB::table('search_attributions')->update(['created_at' => now()->subDays(120)]);

        $this->artisan('analytics:prune')->assertExitCode(0);

        $this->assertNull(SearchQuery::query()->findOrFail($s['queryId'])->session_hash);
        $this->assertSame(0, DB::table('search_attributions')->whereNotNull('visitor_hash')->count());
        $this->assertSame(1, DB::table('search_attributions')->count());
        $this->assertSame(1, DB::table('search_interactions')->where('kind', 'click')->count());
    }
}
