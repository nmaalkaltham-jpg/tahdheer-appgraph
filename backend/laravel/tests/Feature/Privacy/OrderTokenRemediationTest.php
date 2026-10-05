<?php

namespace Tests\Feature\Privacy;

use App\Domain\Orders\OrderToken;
use App\Domain\Payments\TapProvider;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Storage\StorageFixtures;
use Tests\TestCase;

/**
 * Remediation of order tokens that earlier code already wrote in plaintext:
 * `privacy:scrub-order-tokens` finds them in every sink, optionally rotates the
 * exposed orders' tokens, and redacts the rows — dry-run unless `--apply`.
 */
class OrderTokenRemediationTest extends TestCase
{
    use RefreshDatabase;
    use StorageFixtures;

    private const SINKS = ['analytics_events', 'order_attributions', 'not_found_hits', 'webhook_deliveries', 'payment_events'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
    }

    public function test_dry_run_reports_exposure_and_changes_nothing(): void
    {
        [$token] = $this->order('COMPLETED');
        $this->leak($token);
        $before = $this->snapshot();

        $this->artisan('privacy:scrub-order-tokens')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_redacts_every_sink_and_merges_not_found_rows(): void
    {
        [$first, $order] = $this->order('COMPLETED');
        [$second] = $this->order('REFUNDED');
        $this->leak($first, $order);
        $this->leak($second);
        DB::table('not_found_hits')->insert($this->notFound('/orders/[token]', null, 5));

        $this->artisan('privacy:scrub-order-tokens', ['--apply' => true, '--keep-tokens' => true])->assertSuccessful();

        foreach ([$first, $second] as $token) {
            $this->assertSinksNeverHold($token);
        }

        $this->assertSame(
            ['/en/orders/[token]', '/orders/[token]'],
            DB::table('analytics_events')->distinct()->orderBy('path')->pluck('path')->all(),
        );
        $this->assertSame('/orders/[token]', DB::table('order_attributions')->value('landing_path'));
        $payload = json_decode((string) DB::table('payment_events')->value('payload'), true);
        $this->assertSame('http://127.0.0.1:3000/orders/[token]', $payload['redirect']['url']);
        $this->assertSame('m_1', $payload['merchant']['id'], 'provider evidence is otherwise untouched');

        $orders = DB::table('not_found_hits')->where('path', '/orders/[token]')->get();
        $this->assertCount(1, $orders, 'rows that collapse onto one path merge instead of violating the unique index');
        $this->assertSame(5 + 3 + 3, (int) $orders[0]->hits);
        $this->assertSame(
            ['http://127.0.0.1:3000/account/orders', 'http://127.0.0.1:3000/orders/[token]'],
            DB::table('not_found_hits')->whereNotNull('referrer')->orderBy('referrer')->distinct()->pluck('referrer')->all(),
        );
    }

    public function test_rotation_revokes_exposed_tokens_across_every_status_and_flow(): void
    {
        $exposed = [];
        foreach (['COMPLETED', 'PAID', 'AWAITING_PAYMENT', 'FAILED', 'REFUNDED'] as $status) {
            [$token, $order] = $this->order($status);
            $this->leak($token);
            $exposed[$status] = [$token, $order];
        }
        [$guestToken, $guest] = $this->order('COMPLETED', guest: true);
        $this->leak($guestToken);
        [$quietToken, $quiet] = $this->order('COMPLETED');

        $product = $this->makeProduct('SKU-ROT-1');
        $this->importAsset($product, "ملف مدفوع.\n");
        $download = $this->paidEntitlement($product, 'reader@example.com');
        $this->leak($download['token']);

        $this->artisan('privacy:scrub-order-tokens', ['--apply' => true, '--rotate' => true])->assertSuccessful();

        foreach ([...$exposed, 'GUEST' => [$guestToken, $guest], 'DOWNLOAD' => [$download['token'], $download['order']]] as $label => [$old, $order]) {
            $order = $order->fresh();
            $new = OrderToken::forOrder($order);

            $this->assertNotSame($old, $new, "{$label}: token rotated");
            $this->assertSame(1, (int) $order->order_key_version, "{$label}: version bumped once");
            $this->getJson("/api/v1/orders/{$old}")->assertNotFound();
            $this->getJson("/api/v1/orders/{$new}")->assertOk()->assertJsonPath('orderNumber', $order->number);
            $this->postJson("/api/v1/checkout/orders/{$old}/payment")->assertNotFound();
            $this->assertSinksNeverHold($old);
        }

        $this->postJson("/api/v1/checkout/orders/{$this->newToken($exposed['COMPLETED'][1])}/payment")
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PAYABLE');

        $entitlement = (int) $download['entitlement']->id;
        $this->withHeader('X-Order-Token', $download['token'])
            ->postJson("/api/v1/downloads/{$entitlement}/grant")
            ->assertForbidden();
        $this->assertNotSame('', $this->grantUrl($this->newToken($download['order']), $entitlement));

        $this->assertSame(0, (int) $quiet->fresh()->order_key_version, 'an order whose token never leaked keeps it');
        $this->getJson("/api/v1/orders/{$quietToken}")->assertOk();
    }

    public function test_payment_redirect_carries_the_rotated_token(): void
    {
        config()->set('services.tap.mode', 'test');
        config()->set('services.tap.test.secret_key', 'sk_test_rotation');
        config()->set('services.tap.frontend_url', 'https://store.example');
        Http::fake(['api.tap.company/v2/charges' => Http::response(['id' => 'chg_rot', 'transaction' => ['url' => 'https://tap.example/pay']], 200)]);

        [$old, $order] = $this->order('AWAITING_PAYMENT');
        $this->leak($old);
        $this->artisan('privacy:scrub-order-tokens', ['--apply' => true, '--rotate' => true])->assertSuccessful();

        (new TapProvider)->createIntent($order->fresh());

        $redirect = Http::recorded()[0][0]->data()['redirect']['url'];
        $this->assertSame('https://store.example/orders/'.$this->newToken($order), $redirect);
    }

    /** A buyer who paid and closed the tab leaves no page view: only Tap's echoed redirect.url held the token. */
    public function test_a_token_exposed_only_in_provider_payloads_is_rotated(): void
    {
        [$token, $order] = $this->order('PAID');
        $charge = json_encode([
            'id' => 'chg_only', 'transaction' => ['url' => 'https://checkout.tap.company/?mode=page&token=eyJx'],
            'redirect' => ['url' => "https://store.example/orders/{$token}"], 'post' => ['url' => 'https://api.example/x'],
        ]);
        DB::table('webhook_deliveries')->insert([
            'provider' => 'tap', 'dedupe_key' => 'only:1', 'payload' => $charge, 'received_at' => now(), 'status' => 'RECEIVED',
        ]);

        $this->artisan('privacy:scrub-order-tokens', ['--apply' => true, '--rotate' => true])
            ->expectsOutputToContain('matching live orders: 1')
            ->assertSuccessful();

        $this->assertSame(1, (int) $order->fresh()->order_key_version);
        $this->getJson("/api/v1/orders/{$token}")->assertNotFound();
        $this->assertSinksNeverHold($token);
    }

    /** A charge opened before rotation keeps its old return link: the operator must be told, not surprised. */
    public function test_rotation_reports_orders_whose_open_charge_returns_to_the_revoked_link(): void
    {
        [$token, $order] = $this->order('AWAITING_PAYMENT');
        $this->leak($token);
        DB::table('payments')->insert([
            'order_id' => $order->id, 'provider' => 'tap', 'idempotency_key' => "tap:intent:{$order->id}",
            'state' => 'CREATED', 'intent_id' => 'chg_open', 'pay_url' => 'https://checkout.tap.company/?token=x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('privacy:scrub-order-tokens', ['--apply' => true, '--rotate' => true])
            ->expectsOutputToContain('1 rotated order(s) have an open provider charge')
            ->assertSuccessful();
    }

    /** Scrubbing destroys the only record of what leaked, so keeping the leaked tokens alive must be a deliberate choice. */
    public function test_apply_refuses_to_erase_the_evidence_without_an_explicit_rotation_choice(): void
    {
        [$token] = $this->order('COMPLETED');
        $this->leak($token);
        $before = $this->snapshot();

        $this->artisan('privacy:scrub-order-tokens', ['--apply' => true])
            ->expectsOutputToContain('--rotate or --keep-tokens')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    /** Legacy rows the old code never normalised: '/orders%2F…', '//orders/…', and values near the column width. */
    public function test_legacy_rows_that_collide_or_grow_are_merged_and_truncated(): void
    {
        [$token] = $this->order('COMPLETED');
        $now = now();
        DB::table('not_found_hits')->insert([
            $this->notFound("//orders/{$token}", null, 2),
            $this->notFound('/orders%2Fab', null, 3),
            $this->notFound('/orders/cd/', null, 4),
        ]);
        $long = '/'.str_repeat('p', 188).'/orders/ab';
        DB::table('analytics_events')->insert([
            'occurred_at' => $now, 'event_type' => 'page_view', 'path' => $long,
            'device' => 'desktop', 'is_logged_in' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->artisan('privacy:scrub-order-tokens', ['--apply' => true, '--rotate' => true])->assertSuccessful();

        $this->assertSame(['/orders/[token]' => 9], DB::table('not_found_hits')->pluck('hits', 'path')->map(fn ($h) => (int) $h)->all());
        $this->assertSame(200, mb_strlen((string) DB::table('analytics_events')->value('path')));
        $this->assertSinksNeverHold($token);
    }

    /** Logs cannot be rewritten safely while they are appended to; a token found in one is revoked instead. */
    public function test_tokens_found_in_log_files_are_rotated_and_the_files_left_untouched(): void
    {
        [$token, $order] = $this->order('PAID');
        $log = tempnam(sys_get_temp_dir(), 'tokens');
        $line = "[2026-09-10 10:00:00] local.DEBUG: <a href=\"http://127.0.0.1:3000/orders/{$token}\" style=\"x\">\n";
        file_put_contents($log, $line);

        try {
            $this->artisan('privacy:scrub-order-tokens', ['--apply' => true, '--rotate' => true, '--tokens-from' => [$log]])
                ->expectsOutputToContain('matching live orders: 1')
                ->assertSuccessful();

            $this->assertSame(1, (int) $order->fresh()->order_key_version);
            $this->getJson("/api/v1/orders/{$token}")->assertNotFound();
            $this->assertSame($line, file_get_contents($log), 'the source file is only read');

            $this->artisan('privacy:scrub-order-tokens', ['--tokens-from' => ['/nonexistent/file.log']])->assertFailed();
        } finally {
            @unlink($log);
        }
    }

    public function test_rotate_without_apply_changes_nothing(): void
    {
        [$token, $order] = $this->order('COMPLETED');
        $this->leak($token);

        $this->artisan('privacy:scrub-order-tokens', ['--rotate' => true])->assertSuccessful();

        $this->assertSame(0, (int) $order->fresh()->order_key_version);
        $this->getJson("/api/v1/orders/{$token}")->assertOk();
    }

    /** @return array{string, Order} */
    private function order(string $status, bool $guest = false): array
    {
        static $n = 0;
        $n++;
        $number = 'TD-R'.$n;
        $email = "buyer{$n}@example.com";
        $token = OrderToken::make($number, $email);

        $order = Order::query()->create([
            'number' => $number,
            'customer_id' => $guest ? null : $this->customerId($email),
            'email' => $email,
            'status' => $status,
            'totals_gross_minor' => 40000,
            'total_minor' => 40000,
            'vat_minor' => 5217,
            'order_key_hash' => OrderToken::hash($token),
            'paid_at' => in_array($status, ['PAID', 'COMPLETED', 'REFUNDED'], true) ? now() : null,
            'refunded_total_minor' => $status === 'REFUNDED' ? 40000 : 0,
        ]);

        return [$token, $order];
    }

    private function customerId(string $email): int
    {
        return (int) Customer::query()->create(['email' => $email, 'name' => 'زبون'])->id;
    }

    /** Writes the token the way the pre-fix code did: raw, in every sink. */
    private function leak(string $token, ?Order $attributed = null): void
    {
        $now = now();
        foreach (["/orders/{$token}", "/orders/{$token}", "/en/orders/{$token}"] as $path) {
            DB::table('analytics_events')->insert([
                'occurred_at' => $now, 'event_type' => 'page_view', 'path' => $path,
                'device' => 'desktop', 'is_logged_in' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if ($attributed !== null) {
            DB::table('order_attributions')->insert([
                'order_id' => $attributed->id, 'landing_path' => "/orders/{$token}", 'touch_count' => 1, 'created_at' => $now,
            ]);
        }

        DB::table('not_found_hits')->insert([
            $this->notFound("/orders/{$token}", "http://127.0.0.1:3000/account/orders?token={$token}", 3),
            $this->notFound('/products/gone-'.substr(md5($token), 0, 6), "http://127.0.0.1:3000/orders/{$token}", 1),
        ]);

        // Provider evidence stored before RedactedJson: Tap's charge object echoes redirect.url.
        $charge = json_encode(['id' => 'chg_'.substr(md5($token), 0, 8), 'redirect' => ['url' => "http://127.0.0.1:3000/orders/{$token}"], 'merchant' => ['id' => 'm_1']]);
        DB::table('webhook_deliveries')->insert([
            'provider' => 'tap', 'dedupe_key' => 'leak:'.md5($token), 'payload' => $charge, 'received_at' => $now, 'status' => 'RECEIVED',
        ]);
        if ($attributed !== null) {
            $payment = DB::table('payments')->insertGetId([
                'order_id' => $attributed->id, 'provider' => 'tap', 'idempotency_key' => 'leak:'.$attributed->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('payment_events')->insert(['payment_id' => $payment, 'event_type' => 'charge.captured', 'payload' => $charge, 'created_at' => $now]);
        }
    }

    /** @return array<string, mixed> */
    private function notFound(string $path, ?string $referrer, int $hits): array
    {
        return [
            'path' => $path, 'url_hash' => hash('sha256', $path), 'referrer' => $referrer, 'hits' => $hits,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function newToken(Order $order): string
    {
        return OrderToken::forOrder($order->fresh());
    }

    private function assertSinksNeverHold(string $token): void
    {
        foreach (self::SINKS as $table) {
            $dump = json_encode(DB::table($table)->get()->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($token, (string) $dump, "{$table} still holds a raw order token");
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $out = [];
        foreach ([...self::SINKS, 'orders'] as $table) {
            $out[$table] = DB::table($table)->orderBy($table === 'order_attributions' ? 'order_id' : 'id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }
}
