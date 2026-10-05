<?php

namespace Tests\Feature\Commerce;

use App\Domain\Cart\CartService;
use App\Domain\Cart\Models\Coupon;
use App\Domain\Outbox\OutboxEvent;
use App\Models\DownloadEvent;
use App\Models\Entitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fake-provider end-to-end: cart → checkout → confirm → PAID → download. */
class PaymentFlowTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    public function test_full_purchase_and_download_path(): void
    {
        $plain = $this->makeProduct('TD-P-001', 40000);
        $onSale = $this->makeProduct('TD-P-002', 30000, 25000);
        Coupon::query()->create(['code' => 'TEACHER15', 'type' => 'percent', 'value' => 15]);

        $cookie = $this->cartWith([$plain, $onSale]);
        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/cart/coupon', ['code' => 'TEACHER15'])->assertOk();

        $result = $this->checkoutOrder($cookie);
        $order = $result['order'];
        $token = $result['token'];

        // Dev-only fake confirm drives the finalizer end-to-end.
        $this->postJson("/api/v1/payments/fake/{$token}/confirm")
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED');

        /*
         * «مكتمل» لا «مدفوع»: الطلب رقميٌّ بحت، فوحدة تنفيذه نُفِّذت في
         * المعاملة نفسها ولا شيء ينتظر. و`paid_at` يبقى مكتوبًا — الدفع وقع
         * وحالةُ التسليم لا تمحوه.
         */
        $order->refresh();
        $this->assertSame('COMPLETED', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(2, Entitlement::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OutboxEvent::query()
            ->where('event_type', 'order.paid')
            ->where('idempotency_key', "order:{$order->id}:paid")
            ->count());
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id, 'provider' => 'fake', 'state' => 'CAPTURED',
        ]);

        // Order view: unlocked files with real entitlement ids, paidAt present.
        $view = $this->getJson('/api/v1/orders/'.$token)->assertOk()
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('files.0.locked', false)
            ->assertJsonPath('files.1.locked', false)
            ->assertJsonPath('totals.totalMinor', 55250);
        $this->assertNotNull($view->json('paidAt'));
        $entitlementId = $view->json('files.0.entitlementId');
        $this->assertIsInt($entitlementId);

        // Grant a download with the order token.
        $grant = $this->withHeader('X-Order-Token', $token)
            ->postJson("/api/v1/downloads/{$entitlementId}/grant")
            ->assertOk();
        $this->assertNotNull($grant->json('expiresAt'));
        $url = $grant->json('url');
        $this->assertStringContainsString('/api/v1/downloads/redeem/', $url);
        $this->assertDatabaseHas('download_events', [
            'entitlement_id' => $entitlementId, 'event_type' => 'link-generated',
        ]);

        // Redeem: delivery works now (Storage domain), but these fixture
        // products have no file attached — honest 501, never a fake file.
        // The delivered-file path is covered by Tests\Feature\Storage.
        $this->getJson($url)
            ->assertStatus(501)
            ->assertJsonPath('code', 'NOT_CONFIGURED');
    }

    public function test_grant_denied_before_payment_with_reason_unpaid(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-P-003', 40000)]);
        $result = $this->checkoutOrder($cookie);

        // Manufacture the entitlement early to isolate the unpaid check.
        $entitlement = Entitlement::query()->create([
            'customer_id' => $result['order']->customer_id,
            'order_id' => $result['order']->id,
            'order_item_id' => $result['order']->items()->firstOrFail()->id,
            'product_id' => $result['order']->items()->firstOrFail()->product_id,
            'granted_at' => now(),
            'status' => 'ACTIVE',
        ]);

        $this->withHeader('X-Order-Token', $result['token'])
            ->postJson("/api/v1/downloads/{$entitlement->id}/grant")
            ->assertForbidden()
            ->assertJsonPath('reason', 'unpaid');

        $this->assertDatabaseHas('download_events', [
            'entitlement_id' => $entitlement->id, 'event_type' => 'denied',
        ]);
    }

    public function test_grant_denied_with_wrong_or_missing_token(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-P-004', 40000)]);
        $result = $this->checkoutOrder($cookie);
        $this->postJson("/api/v1/payments/fake/{$result['token']}/confirm")->assertOk();
        $entitlementId = Entitlement::query()->firstOrFail()->id;

        $this->postJson("/api/v1/downloads/{$entitlementId}/grant")
            ->assertForbidden()->assertJsonPath('reason', 'forbidden');

        $this->withHeader('X-Order-Token', 'wrong-token')
            ->postJson("/api/v1/downloads/{$entitlementId}/grant")
            ->assertForbidden()->assertJsonPath('reason', 'forbidden');

        $this->assertSame(2, DownloadEvent::query()->where('event_type', 'denied')->count());
    }

    public function test_grant_denied_after_revocation_with_reason_revoked(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-P-005', 40000)]);
        $result = $this->checkoutOrder($cookie);
        $this->postJson("/api/v1/payments/fake/{$result['token']}/confirm")->assertOk();

        $entitlement = Entitlement::query()->firstOrFail();
        $entitlement->update(['status' => 'REVOKED', 'revoked_at' => now()]);

        $this->withHeader('X-Order-Token', $result['token'])
            ->postJson("/api/v1/downloads/{$entitlement->id}/grant")
            ->assertForbidden()
            ->assertJsonPath('reason', 'revoked');
    }

    public function test_redeem_with_unknown_token_is_404(): void
    {
        $this->getJson('/api/v1/downloads/redeem/deadbeef')->assertNotFound();
    }

    public function test_fake_confirm_is_dev_only(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-P-006', 40000)]);
        $result = $this->checkoutOrder($cookie);

        $this->app['env'] = 'production';
        $this->postJson("/api/v1/payments/fake/{$result['token']}/confirm")->assertNotFound();
        $this->app['env'] = 'testing';

        $this->assertSame('AWAITING_PAYMENT', $result['order']->fresh()->status);
    }
}
