<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\VariantAuthoring;
use App\Domain\Downloads\DownloadDelivery;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تأليف صيغ المنتج من اللوحة.
 *
 * وأهمّ ما يُحرَس هنا ليس الحقول بل **ثابت الصدق**: الملفّ العامّ صحيحٌ ما
 * دامت الصيغة واحدة، فإذا ظهرت الثانية صار قابلًا للتسليم لمن لم يشترِه.
 */
class AdminProductVariantsTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    private Product $product;

    private ProductGroup $group;

    private ProductVariant $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'AV-1', 'slug' => 'av-1', 'title' => 'تحضير العلوم — الخامس',
            'status' => 'published', 'published_at' => now(), 'fulfillment_type' => 'digital',
            'format' => 'DOCX',
        ]);

        $this->group = ProductGroup::create([
            'slug' => 'av-g-1', 'title' => $this->product->title,
            'product_nature' => 'DIGITAL', 'status' => 'published',
            'legacy_product_id' => $this->product->id,
        ]);

        $this->bridge = ProductVariant::create([
            'product_group_id' => $this->group->id,
            'sku' => 'AV-1-DOCX', 'slug' => 'av-1-docx', 'title' => 'Word',
            'format' => 'DOCX', 'position' => 0, 'status' => 'published',
            'legacy_product_id' => $this->product->id,
        ]);

        ProductOffer::create([
            'product_variant_id' => $this->bridge->id, 'selling_model' => 'ONE_TIME',
            'inventory_strategy' => 'UNLIMITED', 'regular_minor' => 3500,
            'currency' => 'SAR', 'status' => 'active',
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
    }

    private function base(): string
    {
        return "/api/v1/admin/products/{$this->product->id}/variants";
    }

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'AV-1-PPTX',
            'slug' => 'av-1-pptx',
            'title' => 'PowerPoint',
            'format' => 'PPTX',
            'status' => 'published',
            'sellingModel' => 'ONE_TIME',
            'offerStatus' => 'active',
            'regularMinor' => 5500,
            'saleMinor' => 4400,
        ], $overrides);
    }

    private function attachDeliverable(?ProductVariant $variant, string $name): Asset
    {
        $asset = Asset::create([
            'provider' => 'local', 'provider_ref' => 'assets/'.uniqid(),
            'original_name' => $name, 'sha256' => hash('sha256', $name),
            'size_bytes' => 10, 'mime' => 'application/octet-stream',
            'visibility' => 'paid_private',
        ]);

        ProductFile::create([
            'product_id' => $this->product->id,
            'product_variant_id' => $variant?->id,
            'asset_id' => $asset->id,
            'role' => 'main',
            'position' => 0,
        ]);

        return $asset;
    }

    public function test_the_index_lists_the_bridged_variant_with_its_offer(): void
    {
        $rows = $this->getJson($this->base())->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('AV-1-DOCX', $rows[0]['sku']);
        $this->assertTrue($rows[0]['isBridge']);
        $this->assertSame(3500, $rows[0]['offer']['regularMinor']);
        $this->assertSame(3500, $rows[0]['offer']['effectiveMinor']);
    }

    public function test_creating_a_variant_writes_its_offer_and_its_fulfilment(): void
    {
        $rows = $this->postJson($this->base(), $this->payload())->assertCreated()->json('data');

        $this->assertCount(2, $rows);

        $created = ProductVariant::query()->where('sku', 'AV-1-PPTX')->firstOrFail();
        $this->assertNull($created->legacy_product_id, 'نازعت الصيغةُ الجديدة الجسرَ الفريد');

        $offer = $created->offer;
        $this->assertNotNull($offer);
        $this->assertSame(4400, $offer->effectivePriceMinor());

        /*
         * التسليم يُكتب صراحةً: بدونه يهبط المُحلّل إلى جسر المنتج القديم
         * فتُسلَّم الصيغة الجديدة باستراتيجيّة الأولى.
         */
        $this->assertDatabaseHas('offer_fulfillments', [
            'product_offer_id' => $offer->id,
            'strategy' => VariantAuthoring::DEFAULT_STRATEGY,
        ]);
    }

    public function test_the_second_variant_rebinds_the_generic_file_to_the_first(): void
    {
        $generic = $this->attachDeliverable(null, 'word.docx');

        $this->postJson($this->base(), $this->payload())->assertCreated();

        $this->assertSame(
            $this->bridge->id,
            (int) ProductFile::query()->where('asset_id', $generic->id)->value('product_variant_id'),
            'بقي الملفّ عامًّا بعد ظهور صيغةٍ ثانية — يُسلَّم لمن لم يشترِه',
        );
    }

    public function test_a_buyer_of_the_new_format_is_not_handed_the_old_file(): void
    {
        $word = $this->attachDeliverable(null, 'word.docx');
        $this->postJson($this->base(), $this->payload())->assertCreated();
        $deck = ProductVariant::query()->where('sku', 'AV-1-PPTX')->firstOrFail();

        $customer = Customer::create(['email' => 'b@example.test', 'name' => 'مشترٍ']);
        $order = Order::create([
            'number' => 'TD-AV-1', 'email' => $customer->email, 'status' => 'PAID',
            'totals_gross_minor' => 4400, 'total_minor' => 4400,
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id,
            'product_variant_id' => $deck->id, 'title_snapshot' => $this->product->title,
            'price_snapshot_minor' => 4400,
        ]);
        $entitlement = Entitlement::create([
            'order_id' => $order->id, 'order_item_id' => $item->id,
            'customer_id' => $customer->id, 'product_id' => $this->product->id,
            'status' => 'ACTIVE', 'granted_at' => now(),
        ]);

        $delivered = app(DownloadDelivery::class)->assetFor($entitlement);

        $this->assertNotSame(
            $word->id,
            $delivered?->id,
            'مشتري PowerPoint استلم ملفّ Word',
        );
        $this->assertNull($delivered, 'صيغةٌ بلا ملفٍّ يجب أن تكون غيابًا صريحًا لا ملفَّ غيرها');
    }

    public function test_samples_stay_generic_because_they_are_not_sold(): void
    {
        $asset = Asset::create([
            'provider' => 'local', 'provider_ref' => 'assets/'.uniqid(),
            'original_name' => 'عيّنة.pdf', 'sha256' => hash('sha256', 'sample'),
            'size_bytes' => 10, 'mime' => 'application/pdf', 'visibility' => 'public_sample',
        ]);
        ProductFile::create([
            'product_id' => $this->product->id, 'asset_id' => $asset->id,
            'role' => 'sample', 'position' => 0,
        ]);

        $this->postJson($this->base(), $this->payload())->assertCreated();

        $this->assertNull(
            ProductFile::query()->where('asset_id', $asset->id)->value('product_variant_id'),
            'حُصرت العيّنة في صيغةٍ فاختفت عن مشتري الأخرى',
        );
    }

    public function test_a_duplicate_sku_is_a_field_error_not_a_server_error(): void
    {
        $this->postJson($this->base(), $this->payload(['sku' => 'AV-1-DOCX']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    public function test_a_sale_price_above_the_regular_one_is_refused(): void
    {
        $this->postJson($this->base(), $this->payload(['regularMinor' => 1000, 'saleMinor' => 2000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('saleMinor');
    }

    public function test_a_free_selling_model_must_cost_zero(): void
    {
        $this->postJson($this->base(), $this->payload(['sellingModel' => 'FREE', 'saleMinor' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('regularMinor');
    }

    public function test_editing_the_regular_price_below_a_stored_sale_is_refused(): void
    {
        $this->postJson($this->base(), $this->payload())->assertCreated();
        $created = ProductVariant::query()->where('sku', 'AV-1-PPTX')->firstOrFail();

        /* التخفيض ٤٤٠٠ مخزَّن؛ خفضُ الأصليّ تحته يقلب القيد. */
        $this->patchJson("{$this->base()}/{$created->id}", ['regularMinor' => 4000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('saleMinor');
    }

    public function test_the_bridged_variant_is_never_deleted(): void
    {
        $this->postJson($this->base(), $this->payload())->assertCreated();

        $this->deleteJson("{$this->base()}/{$this->bridge->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');
    }

    public function test_the_last_variant_is_never_deleted(): void
    {
        $this->deleteJson("{$this->base()}/{$this->bridge->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');
    }

    public function test_a_sold_variant_is_paused_not_deleted(): void
    {
        $this->postJson($this->base(), $this->payload())->assertCreated();
        $created = ProductVariant::query()->where('sku', 'AV-1-PPTX')->firstOrFail();

        $order = Order::create([
            'number' => 'TD-AV-2', 'email' => 'b@example.test', 'status' => 'PAID',
            'totals_gross_minor' => 4400, 'total_minor' => 4400,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id,
            'product_variant_id' => $created->id, 'title_snapshot' => 'x',
            'price_snapshot_minor' => 4400,
        ]);

        $this->deleteJson("{$this->base()}/{$created->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');
    }

    public function test_a_variant_holding_files_is_not_deleted_silently(): void
    {
        $this->postJson($this->base(), $this->payload())->assertCreated();
        $created = ProductVariant::query()->where('sku', 'AV-1-PPTX')->firstOrFail();
        $this->attachDeliverable($created, 'deck.pptx');

        $this->deleteJson("{$this->base()}/{$created->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');
    }

    public function test_a_clean_variant_is_deleted_with_its_offer_and_fulfilment(): void
    {
        $this->postJson($this->base(), $this->payload())->assertCreated();
        $created = ProductVariant::query()->where('sku', 'AV-1-PPTX')->firstOrFail();
        $offerId = $created->offer->id;

        $rows = $this->deleteJson("{$this->base()}/{$created->id}")->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertDatabaseMissing('product_variants', ['id' => $created->id]);
        $this->assertDatabaseMissing('product_offers', ['id' => $offerId]);
        $this->assertDatabaseMissing('offer_fulfillments', ['product_offer_id' => $offerId]);
    }

    public function test_a_variant_of_another_product_is_a_404_not_a_403(): void
    {
        $other = Product::create([
            'sku' => 'AV-2', 'slug' => 'av-2', 'title' => 'منتج آخر',
            'status' => 'published', 'published_at' => now(), 'fulfillment_type' => 'digital',
        ]);

        $this->patchJson("/api/v1/admin/products/{$other->id}/variants/{$this->bridge->id}", ['status' => 'draft'])
            ->assertNotFound();
    }

    public function test_every_mutation_leaves_one_audit_entry(): void
    {
        $this->postJson($this->base(), $this->payload())->assertCreated();
        $created = ProductVariant::query()->where('sku', 'AV-1-PPTX')->firstOrFail();
        $this->patchJson("{$this->base()}/{$created->id}", ['status' => 'draft'])->assertOk();
        $this->deleteJson("{$this->base()}/{$created->id}")->assertOk();

        $actions = DB::table('admin_audit_log')
            ->where('entity_type', 'product')
            ->whereIn('action', ['product.variant.created', 'product.variant.updated', 'product.variant.deleted'])
            ->pluck('action');

        $this->assertEqualsCanonicalizing(
            ['product.variant.created', 'product.variant.updated', 'product.variant.deleted'],
            $actions->all(),
        );
    }

    public function test_the_storefront_shows_the_new_format_end_to_end(): void
    {
        $this->postJson($this->base(), $this->payload())->assertCreated();

        $variants = $this->getJson("/api/v1/products/{$this->product->slug}")
            ->assertOk()->json('data.variants');

        $this->assertCount(2, $variants);
        $formats = array_column($variants, 'format');
        $this->assertEqualsCanonicalizing(['DOCX', 'PPTX'], $formats);
        $pptx = collect($variants)->firstWhere('format', 'PPTX');
        $this->assertSame(4400, $pptx['priceMinor']);
        $this->assertSame(20, $pptx['discountPct']);
    }
}
