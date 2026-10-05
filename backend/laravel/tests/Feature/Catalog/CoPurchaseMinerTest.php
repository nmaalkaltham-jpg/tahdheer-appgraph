<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\CoPurchaseMiner;
use App\Domain\Catalog\Models\ProductGroup;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «يُشترى معه عادةً» — من طلباتٍ حُصّل مالها، أو من قرار محرّر، ولا شيء ثالث.
 */
class CoPurchaseMinerTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(string $title): Product
    {
        $this->seq++;

        $product = Product::create([
            'sku' => 'CP-'.$this->seq,
            'slug' => 'cp-'.$this->seq,
            'title' => $title,
            'status' => 'published',
            'published_at' => now(),
            'fulfillment_type' => 'digital',
        ]);

        ProductGroup::create([
            'slug' => 'cpg-'.$this->seq,
            'title' => $title,
            'product_nature' => 'DIGITAL',
            'status' => 'published',
            'legacy_product_id' => $product->id,
        ]);

        return $product;
    }

    /** @param  list<Product>  $products */
    private function order(array $products, string $status = 'PAID'): void
    {
        $this->seq++;
        $orderId = DB::table('orders')->insertGetId([
            'number' => 'ORD-'.$this->seq,
            'email' => 'buyer'.$this->seq.'@example.test',
            'status' => $status,
            'totals_gross_minor' => 100 * count($products),
            'total_minor' => 100 * count($products),
            // الحالة والمبلغ المستردّ يتّفقان — قيدُ المحرّك يرفض «مستردّ» بلا مبلغ.
            'refunded_total_minor' => $status === 'REFUNDED' ? 100 * count($products) : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($products as $product) {
            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'product_id' => $product->id,
                'title_snapshot' => $product->title,
                'price_snapshot_minor' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function groupId(Product $product): int
    {
        return (int) DB::table('product_groups')->where('legacy_product_id', $product->id)->value('id');
    }

    public function test_a_pair_that_meets_the_threshold_is_written_in_both_directions(): void
    {
        $prep = $this->product('تحضير العلوم');
        $deck = $this->product('عروض بوربوينت العلوم');

        $this->order([$prep, $deck]);
        $this->order([$prep, $deck]);

        $result = app(CoPurchaseMiner::class)->mine();

        $this->assertSame(2, $result['written']);

        $rows = DB::table('product_relationships')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(CoPurchaseMiner::SOURCE, $row->source);
            $this->assertSame(100, $row->confidence, 'كلّ من اشترى أحدهما اشترى الآخر — الثقة ١٠٠٪');
        }

        $this->assertDatabaseHas('product_relationships', [
            'from_product_group_id' => $this->groupId($prep),
            'to_product_group_id' => $this->groupId($deck),
            'relation_type' => CoPurchaseMiner::RELATION,
        ]);
    }

    public function test_a_single_co_occurrence_is_a_coincidence_not_a_habit(): void
    {
        $a = $this->product('تحضير الرياضيات');
        $b = $this->product('أوراق عمل الرياضيات');

        $this->order([$a, $b]);

        $this->assertSame(0, app(CoPurchaseMiner::class)->mine()['written']);
        $this->assertDatabaseCount('product_relationships', 0);
    }

    public function test_unpaid_orders_carry_no_signal(): void
    {
        $a = $this->product('تحضير لغتي');
        $b = $this->product('اختبارات لغتي');

        $this->order([$a, $b], 'PENDING');
        $this->order([$a, $b], 'CANCELLED');
        $this->order([$a, $b], 'REFUNDED');

        $this->assertSame(0, app(CoPurchaseMiner::class)->mine()['written']);
    }

    public function test_confidence_is_the_share_of_that_products_orders_not_a_raw_count(): void
    {
        $a = $this->product('تحضير العلوم');
        $b = $this->product('ملخّص العلوم');

        $this->order([$a, $b]);
        $this->order([$a, $b]);
        $this->order([$a]);
        $this->order([$a]);

        app(CoPurchaseMiner::class)->mine();

        /* أ في أربعة طلبات، ب معه في اثنين ⇒ ٥٠٪. وب في اثنين فقط ⇒ ١٠٠٪. */
        $this->assertSame(50, (int) DB::table('product_relationships')
            ->where('from_product_group_id', $this->groupId($a))->value('confidence'));
        $this->assertSame(100, (int) DB::table('product_relationships')
            ->where('from_product_group_id', $this->groupId($b))->value('confidence'));
    }

    public function test_an_editorial_relationship_is_never_overwritten_or_deleted(): void
    {
        $a = $this->product('تحضير العلوم');
        $b = $this->product('عروض العلوم');

        DB::table('product_relationships')->insert([
            'from_product_group_id' => $this->groupId($a),
            'to_product_group_id' => $this->groupId($b),
            'relation_type' => CoPurchaseMiner::RELATION,
            'position' => 0,
            'source' => 'VERIFIED_HUMAN',
            'confidence' => null,
            'note' => 'اختيار المحرّر',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->order([$a, $b]);
        $this->order([$a, $b]);

        app(CoPurchaseMiner::class)->mine();

        $row = DB::table('product_relationships')
            ->where('from_product_group_id', $this->groupId($a))->first();

        $this->assertSame('VERIFIED_HUMAN', $row->source, 'إحصاءٌ دهس قرار محرّر');
        $this->assertSame('اختيار المحرّر', $row->note);
    }

    public function test_a_pattern_that_lapses_disappears_instead_of_lingering(): void
    {
        $a = $this->product('تحضير العلوم');
        $b = $this->product('اختبارات العلوم');

        $this->order([$a, $b]);
        $this->order([$a, $b]);
        app(CoPurchaseMiner::class)->mine();
        $this->assertDatabaseCount('product_relationships', 2);

        /* ترتفع العتبة فوق الدعم القائم: النمط لم يعد نمطًا. */
        app(CoPurchaseMiner::class)->mine(5);
        $this->assertDatabaseCount('product_relationships', 0);
    }

    public function test_the_storefront_shows_the_recommendation_and_omits_it_when_empty(): void
    {
        $prep = $this->product('تحضير العلوم — الخامس');
        $deck = $this->product('عروض بوربوينت العلوم — الخامس');

        $this->assertArrayNotHasKey(
            'boughtTogether',
            $this->getJson("/api/v1/products/{$prep->slug}")->assertOk()->json('data'),
        );

        $this->order([$prep, $deck]);
        $this->order([$prep, $deck]);
        app(CoPurchaseMiner::class)->mine();

        $cards = $this->getJson("/api/v1/products/{$prep->slug}")
            ->assertOk()->json('data.boughtTogether');

        $this->assertCount(1, $cards);
        $this->assertSame($deck->id, $cards[0]['id']);
    }
}
