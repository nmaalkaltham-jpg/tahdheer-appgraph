<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfillment\Executors\ExecutorRegistry;
use App\Domain\Fulfillment\Executors\GoogleDriveDeliveryExecutor;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Orders\OrderFinalizer;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تسليم Drive — إعادة تحقّقٍ حيّة قبل منح الاستحقاق (§١٣ §١٥ §١٦).
 *
 * الفرق الذي تُثبته هذه الاختبارات: الحالة المخزَّنة قد تقول `CURRENT` وقد
 * ذهب الإذن قبل دقيقة. ملفّ Drive يملكه غيرنا، فما صحّ عند آخر مزامنةٍ ليس
 * بالضرورة صحيحًا لحظة البيع.
 *
 * **ما لا تغطّيه:** لا حساب Drive حقيقيّ — الشبكة مزيَّفة.
 */
class GoogleDriveDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_drive.access_token' => 'token-for-test']);
    }

    /** @return array{0: Order, 1: AssetSource} */
    private function paidDriveOrder(string $syncState = 'CURRENT'): array
    {
        $n = uniqid();

        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'منتج Drive',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'مجموعة', 'product_nature' => 'DIGITAL',
            'status' => 'published', 'legacy_product_id' => $product->id,
        ]);
        $variant = ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}",
            'status' => 'published', 'legacy_product_id' => $product->id,
        ]);
        $offer = ProductOffer::create([
            'product_variant_id' => $variant->id, 'regular_minor' => 5000, 'status' => 'active',
        ]);
        OfferFulfillment::create([
            'product_offer_id' => $offer->id, 'strategy' => 'GOOGLE_DRIVE_DELIVERY', 'position' => 0,
        ]);

        $asset = Asset::create([
            'provider' => 'garage_s3', 'provider_ref' => "drive/FILE_{$n}/1",
            'original_name' => 'ملفّ.pdf', 'sha256' => hash('sha256', $n),
            'size_bytes' => 2048, 'mime' => 'application/pdf',
            'visibility' => 'paid_private', 'usage_count' => 1,
        ]);
        ProductFile::create([
            'product_id' => $product->id, 'asset_id' => $asset->id,
            'role' => 'main', 'position' => 1, 'product_variant_id' => $variant->id,
        ]);
        $source = AssetSource::create([
            'asset_id' => $asset->id, 'provider' => 'GOOGLE_DRIVE',
            'delivery_mode' => 'IMPORT_ONCE', 'external_id' => "FILE_{$n}",
            'imported_revision' => '1', 'source_revision' => '1',
            'sync_state' => $syncState,
            /*
             * القيد `asset_sources_failure_has_reason` يوجب سببًا مع كلّ حالة
             * فشل. حالةُ فشلٍ بلا سبب تُنتج لوحةً تقول «معطوب» ولا تقول لماذا.
             */
            'last_error' => in_array($syncState, AssetSource::BLOCKING_STATES, true)
                ? 'سببٌ محفوظٌ من فحصٍ سابق'
                : null,
        ]);

        $customer = Customer::create(['email' => "{$n}@test.local", 'name' => 'زبون']);
        $order = Order::create([
            'number' => "TD-{$n}", 'customer_id' => $customer->id, 'email' => $customer->email,
            'status' => 'PAID', 'total_minor' => 5000,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_variant_id' => $variant->id, 'product_offer_id' => $offer->id,
            'title_snapshot' => 'منتج Drive', 'price_snapshot_minor' => 5000, 'quantity' => 1,
        ]);

        return [$order->fresh('items'), $source];
    }

    private function unit(Order $order): FulfillmentUnit
    {
        app(FulfillmentOrchestrator::class)->fulfill($order);

        return FulfillmentUnit::query()->where('order_id', $order->id)->firstOrFail();
    }

    public function test_drive_delivery_has_its_own_executor_not_the_generic_digital_one(): void
    {
        $registry = app(ExecutorRegistry::class);

        $this->assertInstanceOf(
            GoogleDriveDeliveryExecutor::class,
            $registry->for('GOOGLE_DRIVE_DELIVERY'),
        );
    }

    public function test_a_readable_drive_file_is_delivered(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(
            ['id' => 'FILE', 'name' => 'ملفّ.pdf', 'mimeType' => 'application/pdf', 'version' => 1], 200,
        )]);

        [$order] = $this->paidDriveOrder();

        $this->assertSame('FULFILLED', $this->unit($order)->status);
        $this->assertDatabaseCount('entitlements', 1);
    }

    public function test_a_revoked_permission_blocks_the_grant_even_when_the_stored_state_says_current(): void
    {
        /* ٤٠٣ = الملفّ سليم والإذن ذهب. الحالة المخزَّنة `CURRENT` وهي بائتة. */
        Http::fake(['www.googleapis.com/*' => Http::response([], 403)]);

        [$order, $source] = $this->paidDriveOrder('CURRENT');
        $unit = $this->unit($order);

        $this->assertSame('FAILED', $unit->status);
        $this->assertStringContainsString('إذن', (string) $unit->last_error);
        $this->assertSame(0, \DB::table('entitlements')->count(),
            'استحقاقٌ لملفٍّ لا يُقرأ يقود إلى تنزيلٍ فاشل وشكوى');

        /* وما اكتُشف هنا يُكتب في السجلّ فيراه المشغّل، لا يضيع مع الاستجابة. */
        $this->assertSame('ACCESS_REVOKED', $source->refresh()->sync_state);
    }

    public function test_a_deleted_drive_file_blocks_the_grant_and_says_so_distinctly(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response([], 404)]);

        [$order, $source] = $this->paidDriveOrder('CURRENT');
        $unit = $this->unit($order);

        $this->assertSame('FAILED', $unit->status);
        $this->assertStringContainsString('لم يعد موجودًا', (string) $unit->last_error);
        $this->assertSame('SOURCE_MISSING', $source->refresh()->sync_state,
            '«حُذف» يوجب استبدال مصدر، و«سُحب الإذن» يوجب إصلاح اعتماد — وطيُّهما معًا يُنتج لوحةً تقول «أصلح شيئًا ما»');
    }

    public function test_an_unreachable_drive_still_delivers_the_copy_we_already_hold(): void
    {
        /*
         * ٥٠٠ عند Drive ليست حكمًا على الملفّ، والبايتات عندنا بحكم
         * IMPORT_ONCE. منعُ التسليم هنا يعاقب الزبون على عطلٍ لا يمسّ ما اشتراه.
         */
        Http::fake(['www.googleapis.com/*' => Http::response([], 500)]);

        [$order] = $this->paidDriveOrder('CURRENT');

        $this->assertSame('FULFILLED', $this->unit($order)->status);
        $this->assertDatabaseCount('entitlements', 1);
    }

    public function test_a_missing_credential_does_not_block_a_copy_we_already_hold(): void
    {
        config(['services.google_drive.access_token' => '']);

        [$order] = $this->paidDriveOrder('CURRENT');

        $this->assertSame('FULFILLED', $this->unit($order)->status,
            'غياب الإعداد عطلٌ عندنا لا عند الملفّ — والنسخة مستورَدة');
    }

    public function test_a_blocking_stored_state_still_refuses_when_there_is_no_usable_copy(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response([], 500)]);

        /*
         * الأصل مربوطٌ لكنّ حالته فشلٌ محفوظ: `isDeliverable()` تُعيد `false`،
         * فلا يُمنح استحقاقٌ استنادًا إلى نسخةٍ لا يُوثق بها.
         */
        [$order] = $this->paidDriveOrder('SYNC_FAILED');

        $unit = $this->unit($order);

        $this->assertSame('FAILED', $unit->status);
        $this->assertSame(0, \DB::table('entitlements')->count());
    }

    public function test_drive_is_asked_outside_the_finalize_transaction(): void
    {
        $levels = [];
        Http::fake(function () use (&$levels) {
            $levels[] = \Illuminate\Support\Facades\DB::transactionLevel();

            return Http::response(['id' => 'FILE', 'name' => 'ملفّ.pdf', 'mimeType' => 'application/pdf', 'version' => 1], 200);
        });
        [$order] = $this->paidDriveOrder();

        // RefreshDatabase يلفّ الاختبار بمعاملة، فالمستوى الأساس هو ما نقيس عليه.
        $base = \Illuminate\Support\Facades\DB::transactionLevel();

        app(OrderFinalizer::class)->finalize($order, 'fake', 'chg_drive_'.uniqid());

        $this->assertNotEmpty($levels);
        // كلّ نداءٍ إلى Drive وقع قبل فتح معاملة التثبيت لا داخلها.
        $this->assertSame([], array_filter($levels, fn ($l) => $l > $base));
        $this->assertSame('FULFILLED', FulfillmentUnit::query()->where('order_id', $order->id)->firstOrFail()->status);
    }
}
