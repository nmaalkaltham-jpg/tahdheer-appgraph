<?php

namespace Tests\Feature\Home;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two homepage product rails, /api/v1/products?source=best|featured.
 *
 * Both exist to stop a lie: «الأكثر مبيعًا» must mean units actually paid for,
 * and «منتجات مختارة» must mean somebody chose them. The tests below are the
 * proof that neither can quietly degrade into «newest».
 */
class HomeProductSourcesTest extends TestCase
{
    use RefreshDatabase;

    private int $orderNo = 0;

    private function product(string $sku, int $daysAgo = 1, bool $featured = false): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'slug' => strtolower($sku),
            'title' => 'تحضير — '.$sku,
            'status' => 'published',
            'featured' => $featured,
            'published_at' => now()->subDays($daysAgo),
        ]);
        ProductPrice::query()->create(['product_id' => $product->id, 'regular_minor' => 3500]);

        return $product;
    }

    private function sell(Product $product, int $times, string $status = 'PAID'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $order = Order::query()->create([
                'number' => 'TD-'.str_pad((string) ++$this->orderNo, 5, '0', STR_PAD_LEFT),
                'email' => 'buyer@example.com',
                'status' => $status,
                'paid_at' => $status === 'PAID' ? now() : null,
                'total_minor' => 3500,
                'totals_gross_minor' => 3500,
            ]);
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'title_snapshot' => $product->title,
                'price_snapshot_minor' => 3500,
            ]);
        }
    }

    public function test_best_ranks_by_paid_units_and_excludes_products_that_never_sold(): void
    {
        $never = $this->product('TD-B0', daysAgo: 1);   // newest, but zero sales
        $few = $this->product('TD-B1', daysAgo: 20);
        $many = $this->product('TD-B2', daysAgo: 40);

        $this->sell($few, 2);
        $this->sell($many, 5);

        $response = $this->getJson('/api/v1/products?source=best')->assertOk()->assertJsonCount(2, 'data');

        $response->assertJsonPath('data.0.sku', 'TD-B2')
            ->assertJsonPath('data.1.sku', 'TD-B1');

        $this->assertNotContains($never->sku, array_column($response->json('data'), 'sku'));
    }

    public function test_best_ignores_orders_whose_money_was_never_collected(): void
    {
        $product = $this->product('TD-B3');
        $this->sell($product, 4, 'CANCELLED');
        $this->sell($product, 2, 'AWAITING_PAYMENT');

        $this->getJson('/api/v1/products?source=best')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_best_is_empty_before_the_first_sale_instead_of_falling_back_to_newest(): void
    {
        $this->product('TD-B4');
        $this->product('TD-B5');

        $this->getJson('/api/v1/products?source=best')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_best_never_promotes_an_unpublished_product(): void
    {
        $draft = Product::query()->create([
            'sku' => 'TD-B6', 'slug' => 'td-b6', 'title' => 'مسودة', 'status' => 'draft',
        ]);
        $this->sell($draft, 9);

        $this->getJson('/api/v1/products?source=best')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_featured_returns_only_flagged_products(): void
    {
        $this->product('TD-F0', daysAgo: 1);                    // newest, NOT featured
        $this->product('TD-F1', daysAgo: 30, featured: true);
        $this->product('TD-F2', daysAgo: 10, featured: true);

        $response = $this->getJson('/api/v1/products?source=featured')->assertOk()
            ->assertJsonCount(2, 'data');

        // Newest of the chosen ones first — but only ever among the chosen.
        $response->assertJsonPath('data.0.sku', 'TD-F2')->assertJsonPath('data.1.sku', 'TD-F1');
    }

    public function test_featured_defaults_to_off_so_the_rail_is_empty_until_someone_chooses(): void
    {
        $this->product('TD-F3');
        $this->product('TD-F4');

        $this->getJson('/api/v1/products?source=featured')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_limit_and_unknown_sources_are_still_validated(): void
    {
        $this->product('TD-F5', featured: true);
        $this->product('TD-F6', daysAgo: 3, featured: true);

        $this->getJson('/api/v1/products?source=featured&limit=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?source=trending')->assertStatus(422);
    }
}
