<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Entitlements\RevocationPolicy;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RBAC is enforced on the server (MIP §8.4): a role that lacks the permission
 * is refused even when it can reach the surrounding surface.
 */
class AdminPermissionsTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    public function test_read_only_can_list_products_but_cannot_publish_them(): void
    {
        $product = $this->makeReadyProduct('TD-P-RO');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ReadOnly));

        $this->getJson('/api/v1/admin/products')->assertOk();

        $this->patchJson("/api/v1/admin/products/{$product->id}/publish")
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('requiredPermission', 'products.publish');

        // Refused BEFORE any state change — the product is still a draft.
        $this->assertSame('draft', $product->fresh()->status);
    }

    public function test_read_only_cannot_create_or_update_products(): void
    {
        $product = $this->makeProduct('TD-P-RO2');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ReadOnly));

        $this->postJson('/api/v1/admin/products', ['productNature' => 'DIGITAL', 'sku' => 'TD-NEW', 'slug' => 'td-new', 'title' => 'جديد'])
            ->assertStatus(403)
            ->assertJsonPath('requiredPermission', 'products.create');

        $this->patchJson("/api/v1/admin/products/{$product->id}", ['title' => 'محاولة تعديل'])
            ->assertStatus(403)
            ->assertJsonPath('requiredPermission', 'products.update');

        $this->assertDatabaseMissing('products', ['sku' => 'TD-NEW']);
        $this->assertNotSame('محاولة تعديل', $product->fresh()->title);
    }

    public function test_read_only_cannot_refund_an_order(): void
    {
        $order = Order::query()->create([
            'number' => 'TD-RO-1', 'email' => 'noura@example.com',
            'status' => 'PAID', 'total_minor' => 40000, 'totals_gross_minor' => 40000,
        ]);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ReadOnly));

        $this->getJson('/api/v1/admin/orders')->assertOk();

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amountMinor' => 40000, 'policy' => RevocationPolicy::Immediate->value,
        ])
            ->assertStatus(403)
            ->assertJsonPath('requiredPermission', 'orders.refund');

        $this->assertSame('PAID', $order->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_wrong_role_is_refused_on_surfaces_outside_its_scope(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        // The analytics role owns the dashboard…
        $this->getJson('/api/v1/admin/dashboard')->assertOk();

        // …and nothing else.
        $this->getJson('/api/v1/admin/products')
            ->assertStatus(403)->assertJsonPath('requiredPermission', 'products.read');
        $this->getJson('/api/v1/admin/orders')
            ->assertStatus(403)->assertJsonPath('requiredPermission', 'orders.read');
        $this->getJson('/api/v1/admin/customers')
            ->assertStatus(403)->assertJsonPath('requiredPermission', 'customers.read');
        $this->getJson('/api/v1/admin/search/synonyms')
            ->assertStatus(403)->assertJsonPath('requiredPermission', 'search.synonyms.manage');
    }

    public function test_product_manager_cannot_touch_orders_or_synonyms(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));

        $this->getJson('/api/v1/admin/products')->assertOk();
        $this->getJson('/api/v1/admin/orders')->assertStatus(403);
        $this->getJson('/api/v1/admin/search/synonyms')->assertStatus(403);
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);
    }

    public function test_forbidden_message_names_the_permission_and_the_role_in_arabic(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ReadOnly));

        $this->postJson('/api/v1/admin/search/synonyms', ['term' => 'تحضير', 'synonym' => 'تحاضير'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'صلاحية «إدارة مرادفات البحث» غير ممنوحة لدورك (قراءة فقط).');
    }
}
