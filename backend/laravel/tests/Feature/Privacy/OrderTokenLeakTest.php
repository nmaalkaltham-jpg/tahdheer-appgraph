<?php

namespace Tests\Feature\Privacy;

use App\Domain\Orders\OrderToken;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\WebhookDelivery;
use App\Domain\Privacy\RedactedJson;
use App\Models\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The order token is the only credential a guest has for their order, their
 * payment retry and their downloads; `orders` stores only its hash. These tests
 * pin the rule that no public write endpoint persists it in plaintext, whatever
 * the browser sends.
 */
class OrderTokenLeakTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->token = OrderToken::make('TD-9001', 'buyer@example.com');
    }

    public function test_page_view_of_an_order_page_stores_the_route_not_the_token(): void
    {
        $this->postJson('/api/v1/events', ['events' => [
            ['type' => 'page_view', 'path' => "/orders/{$this->token}", 'referrer' => "https://store.example/orders/{$this->token}"],
            ['type' => 'page_view', 'path' => "/en/orders/{$this->token}/"],
            ['type' => 'page_view', 'path' => "/orders/{$this->token}?tap_id=chg_1"],
            ['type' => 'page_view', 'path' => "/ORDERS/{$this->token}"],
        ]])->assertNoContent();

        $this->assertSame(
            ['/orders/[token]', '/en/orders/[token]/', '/orders/[token]', '/ORDERS/[token]'],
            DB::table('analytics_events')->orderBy('id')->pluck('path')->all(),
        );
        $this->assertTableNeverHolds('analytics_events');
    }

    public function test_a_token_longer_than_the_column_budget_is_redacted_before_truncation(): void
    {
        $padded = '/orders/'.$this->token.str_repeat('x', 300);

        $this->postJson('/api/v1/events', ['events' => [['type' => 'page_view', 'path' => $padded]]])
            ->assertNoContent();

        $this->assertSame('/orders/[token]', DB::table('analytics_events')->value('path'));
    }

    public function test_not_found_report_stores_neither_token_nor_query(): void
    {
        $this->postJson('/api/v1/not-found', [
            'path' => "/orders/{$this->token}",
            'referer' => "http://127.0.0.1:3000/account/orders?token={$this->token}",
        ])->assertStatus(202)->assertJsonPath('path', '/orders/[token]');

        $this->postJson('/api/v1/not-found', [
            'path' => '/products/missing',
            'referer' => "https://store.example/orders/{$this->token}?tap_id=chg_1",
        ])->assertStatus(202);

        $rows = DB::table('not_found_hits')->orderBy('id')->get(['path', 'referrer']);

        $this->assertSame('/orders/[token]', $rows[0]->path);
        $this->assertSame('http://127.0.0.1:3000/account/orders', $rows[0]->referrer);
        $this->assertSame('https://store.example/orders/[token]', $rows[1]->referrer);
        $this->assertTableNeverHolds('not_found_hits');
    }

    public function test_different_tokens_aggregate_into_one_not_found_row(): void
    {
        $other = OrderToken::make('TD-9002', 'someone@example.com');

        $this->postJson('/api/v1/not-found', ['path' => "/orders/{$this->token}"])->assertStatus(202);
        $this->postJson('/api/v1/not-found', ['path' => "/orders/{$other}"])->assertStatus(202)
            ->assertJsonPath('hits', 2);

        $this->assertSame(1, DB::table('not_found_hits')->count());
        $this->assertTableNeverHolds('not_found_hits', $other);
    }

    /** Tap echoes the charge's redirect.url — the buyer's order link — in every webhook. */
    public function test_provider_payloads_keep_everything_but_the_order_token(): void
    {
        $body = json_encode([
            'charge_id' => 'chg_leak', 'order_number' => 'TD-NOPE', 'status' => 'CAPTURED', 'amount_minor' => 100, 'currency' => 'SAR',
            'redirect' => ['url' => "https://store.example/orders/{$this->token}"],
            'merchant' => ['id' => 'm_1'],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/payments/webhook/fake',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body)],
            content: $body,
        )->assertOk();

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame('https://store.example/orders/[token]', $delivery->payload['redirect']['url']);
        $this->assertSame('m_1', $delivery->payload['merchant']['id'], 'the rest of the evidence is untouched');
        $this->assertTableNeverHolds('webhook_deliveries');
        $this->assertSame(RedactedJson::class, (new PaymentEvent)->getCasts()['payload']);
    }

    /** A buyer looking for their order pastes its link into site search: admins read search queries. */
    public function test_an_order_link_pasted_into_search_is_neither_stored_nor_logged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_SLASHES);
        });

        $this->getJson('/api/v1/search?q='.rawurlencode("https://store.example/orders/{$this->token}"))->assertOk();

        $this->assertTableNeverHolds('search_queries');
        $this->assertStringNotContainsString($this->token, implode("\n", $logged), 'a search log line holds the token');

        // Every writer of search_queries, not only the pipeline.
        $row = SearchQuery::query()->create([
            'raw_query' => "/orders/{$this->token}", 'normalized_query' => "orders {$this->token}", 'results_count' => 0,
        ]);
        $this->assertSame('/orders/[token]', $row->fresh()->raw_query);
        $this->assertTableNeverHolds('search_queries');
    }

    private function assertTableNeverHolds(string $table, ?string $token = null): void
    {
        $dump = json_encode(DB::table($table)->get()->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($token ?? $this->token, (string) $dump, "{$table} holds a raw order token");
    }
}
