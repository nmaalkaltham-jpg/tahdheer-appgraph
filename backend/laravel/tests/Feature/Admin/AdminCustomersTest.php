<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCustomersTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    /** @return array{customer: Customer, entitlement: Entitlement} */
    private function customerWithEntitlement(string $email, Product $product): array
    {
        $customer = Customer::query()->create(['email' => $email, 'name' => 'نورة العتيبي']);

        $order = Order::query()->create([
            'number' => 'TD-'.substr(md5($email), 0, 6), 'customer_id' => $customer->id,
            'email' => $email, 'status' => 'PAID', 'total_minor' => 40000,
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'title_snapshot' => $product->title, 'price_snapshot_minor' => 40000,
        ]);
        $entitlement = Entitlement::query()->create([
            'customer_id' => $customer->id, 'order_id' => $order->id, 'order_item_id' => $item->id,
            'product_id' => $product->id, 'granted_at' => now(), 'status' => 'ACTIVE',
        ]);

        return ['customer' => $customer, 'entitlement' => $entitlement];
    }

    public function test_empty_customer_book_is_an_explicit_empty_list(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->getJson('/api/v1/admin/customers')
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_list_carries_real_order_and_entitlement_counts(): void
    {
        $product = $this->makeReadyProduct('TD-CU-1');
        $this->customerWithEntitlement('noura@example.com', $product);
        Customer::query()->create(['email' => 'quiet@example.com', 'name' => 'عميلة بلا طلبات']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $response = $this->getJson('/api/v1/admin/customers')->assertOk()->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'))->keyBy('email');
        $this->assertSame(1, $rows['noura@example.com']['ordersCount']);
        $this->assertSame(1, $rows['noura@example.com']['activeEntitlementsCount']);
        // A real observed zero, not a placeholder: this account genuinely has none.
        $this->assertSame(0, $rows['quiet@example.com']['ordersCount']);
        $this->assertSame(0, $rows['quiet@example.com']['entitlementsCount']);
    }

    public function test_detail_lists_orders_and_the_entitlement_ledger(): void
    {
        $product = $this->makeReadyProduct('TD-CU-2');
        ['customer' => $customer] = $this->customerWithEntitlement('detail@example.com', $product);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->getJson("/api/v1/admin/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.email', 'detail@example.com')
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonCount(1, 'data.entitlements')
            ->assertJsonPath('data.entitlements.0.productTitle', $product->title)
            ->assertJsonPath('data.entitlements.0.status', 'ACTIVE');
    }

    public function test_revoke_delegates_to_the_entitlement_service_and_is_audited(): void
    {
        $product = $this->makeReadyProduct('TD-CU-3');
        ['entitlement' => $entitlement] = $this->customerWithEntitlement('revoke@example.com', $product);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->postJson("/api/v1/admin/entitlements/{$entitlement->id}/revoke", [
            'reason' => 'مشاركة غير مصرّح بها', 'policy' => 'immediate',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'REVOKED')
            ->assertJsonPath('data.revocationReason', 'مشاركة غير مصرّح بها');

        // EntitlementService side effects, not reimplemented in the controller.
        $this->assertDatabaseHas('download_events', ['entitlement_id' => $entitlement->id, 'event_type' => 'revoked']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'entitlement.revoked']);
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'entitlement.revoked', 'reason' => 'مشاركة غير مصرّح بها',
        ]);
    }

    public function test_revoke_requires_a_reason_and_refuses_a_second_revocation(): void
    {
        $product = $this->makeReadyProduct('TD-CU-4');
        ['entitlement' => $entitlement] = $this->customerWithEntitlement('twice@example.com', $product);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->postJson("/api/v1/admin/entitlements/{$entitlement->id}/revoke", ['policy' => 'immediate'])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'هذا الحقل مطلوب.');

        $this->postJson("/api/v1/admin/entitlements/{$entitlement->id}/revoke", [
            'reason' => 'أول مرة', 'policy' => 'immediate',
        ])->assertOk();

        $this->postJson("/api/v1/admin/entitlements/{$entitlement->id}/revoke", [
            'reason' => 'مرة ثانية', 'policy' => 'immediate',
        ])->assertStatus(422)->assertJsonPath('code', 'ALREADY_REVOKED');
    }
}
