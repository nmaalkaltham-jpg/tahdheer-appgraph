<?php

namespace Tests\Feature;

use App\Domain\Entitlements\DownloadDenied;
use App\Domain\Entitlements\EntitlementService;
use App\Domain\Entitlements\RevocationPolicy;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EntitlementService
    {
        return app(EntitlementService::class);
    }

    private function makePaidOrder(): Order
    {
        $customer = Customer::query()->create(['email' => 'buyer@example.com', 'name' => 'مشترية']);
        $product = Product::query()->create([
            'sku' => 'TD-0001', 'slug' => 'test-product', 'title' => 'تحضير لغتي', 'status' => 'published',
        ]);
        $order = Order::query()->create([
            'number' => 'TD-1001',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'PAID',
            'total_minor' => 4000,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'price_snapshot_minor' => 4000,
            'version_label' => 'v1.0',
        ]);

        return $order->fresh('items');
    }

    public function test_grant_creates_ledger_rows_and_outbox_events(): void
    {
        $order = $this->makePaidOrder();

        $granted = DB::transaction(fn () => $this->service()->grantForOrder($order));

        $this->assertCount(1, $granted);
        $entitlement = $granted->first();
        $this->assertSame('ACTIVE', $entitlement->status);
        $this->assertSame('v1.0', $entitlement->version_label);
        $this->assertNull($entitlement->expires_at);      // permanent access by default
        $this->assertNull($entitlement->download_limit);  // unlimited by default
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'entitlement.granted',
            'idempotency_key' => "entitlement:{$entitlement->id}:granted",
        ]);

        // Idempotent: re-granting the same order adds nothing.
        DB::transaction(fn () => $this->service()->grantForOrder($order));
        $this->assertSame(1, Entitlement::query()->count());
    }

    public function test_validate_issues_hashed_grant_and_returns_raw_token(): void
    {
        $order = $this->makePaidOrder();
        $entitlement = DB::transaction(fn () => $this->service()->grantForOrder($order))->first();

        $raw = $this->service()->validateForDownload($entitlement, '203.0.113.9');

        $this->assertSame(64, strlen($raw));
        $this->assertDatabaseHas('download_grants', [
            'entitlement_id' => $entitlement->id,
            'token_hash' => hash('sha256', $raw),
            'ip' => '203.0.113.9',
        ]);
        // Raw token never stored.
        $this->assertDatabaseMissing('download_grants', ['token_hash' => $raw]);

        $grant = $entitlement->fresh()->id;
        $this->assertDatabaseHas('download_events', [
            'entitlement_id' => $grant,
            'event_type' => 'link-generated',
        ]);
    }

    public function test_validate_denies_expired_entitlement(): void
    {
        $order = $this->makePaidOrder();
        $entitlement = DB::transaction(fn () => $this->service()->grantForOrder($order))->first();
        $entitlement->update(['expires_at' => now()->subDay()]);

        try {
            $this->service()->validateForDownload($entitlement);
            $this->fail('Expected DownloadDenied');
        } catch (DownloadDenied $e) {
            $this->assertSame('expired', $e->reason);
        }
    }

    public function test_validate_denies_exhausted_download_limit(): void
    {
        $order = $this->makePaidOrder();
        $entitlement = DB::transaction(fn () => $this->service()->grantForOrder($order))->first();
        $entitlement->update(['download_limit' => 3, 'download_count' => 3]);

        try {
            $this->service()->validateForDownload($entitlement);
            $this->fail('Expected DownloadDenied');
        } catch (DownloadDenied $e) {
            $this->assertSame('limit_exhausted', $e->reason);
        }
    }

    public function test_validate_denies_unpaid_order(): void
    {
        $order = $this->makePaidOrder();
        $entitlement = DB::transaction(fn () => $this->service()->grantForOrder($order))->first();
        $order->update(['status' => 'AWAITING_PAYMENT']);

        try {
            $this->service()->validateForDownload($entitlement);
            $this->fail('Expected DownloadDenied');
        } catch (DownloadDenied $e) {
            $this->assertSame('order_not_paid', $e->reason);
        }
    }

    public function test_revoke_immediate_blocks_downloads(): void
    {
        $order = $this->makePaidOrder();
        $entitlement = DB::transaction(fn () => $this->service()->grantForOrder($order))->first();

        $this->service()->revoke($entitlement, 'refund completed', RevocationPolicy::Immediate);

        $entitlement->refresh();
        $this->assertSame('REVOKED', $entitlement->status);
        $this->assertNotNull($entitlement->revoked_at);
        $this->assertSame('refund completed', $entitlement->revocation_reason);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'entitlement.revoked']);

        $this->expectException(DownloadDenied::class);
        $this->service()->validateForDownload($entitlement);
    }

    public function test_revoke_grace_3d_keeps_access_until_the_window_closes(): void
    {
        $order = $this->makePaidOrder();
        $entitlement = DB::transaction(fn () => $this->service()->grantForOrder($order))->first();

        $this->service()->revoke($entitlement, 'refund requested', RevocationPolicy::Grace3d);

        $entitlement->refresh();
        $this->assertSame('ACTIVE', $entitlement->status);
        // Still downloadable inside the grace window.
        $raw = $this->service()->validateForDownload($entitlement);
        $this->assertNotSame('', $raw);

        // After the window it is denied as expired.
        $this->travel(4)->days();
        $this->expectException(DownloadDenied::class);
        $this->service()->validateForDownload($entitlement);
    }

    public function test_revoke_keep_policy_records_reason_only(): void
    {
        $order = $this->makePaidOrder();
        $entitlement = DB::transaction(fn () => $this->service()->grantForOrder($order))->first();

        $this->service()->revoke($entitlement, 'goodwill refund', RevocationPolicy::Keep);

        $entitlement->refresh();
        $this->assertSame('ACTIVE', $entitlement->status);
        $this->assertNull($entitlement->expires_at);
        $this->assertSame('goodwill refund', $entitlement->revocation_reason);
    }

    public function test_the_download_limit_is_claimed_atomically(): void
    {
        $order = $this->makePaidOrder();
        $entitlement = DB::transaction(fn () => $this->service()->grantForOrder($order))->first();
        $entitlement->update(['download_limit' => 1]);

        // «اقرأ ثمّ زد» كان يمرّر طلبين متوازيين من حدٍّ قدره واحد.
        $this->assertTrue($this->service()->claimDownload($entitlement));
        $this->assertFalse($this->service()->claimDownload($entitlement));
        $this->assertSame(1, (int) $entitlement->fresh()->download_count);

        $this->service()->releaseDownload($entitlement);
        $this->assertTrue($this->service()->claimDownload($entitlement));
    }

    public function test_asserting_downloadability_issues_no_grant(): void
    {
        $order = $this->makePaidOrder();
        $entitlement = DB::transaction(fn () => $this->service()->grantForOrder($order))->first();
        $before = DB::table('download_grants')->count();

        $this->service()->assertDownloadable($entitlement);

        $this->assertSame($before, DB::table('download_grants')->count());
    }
}
