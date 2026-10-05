<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Cart\CartService;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Downloads\DownloadDelivery;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * سلسلة شراء الصيغة — من الاختيار إلى الملفّ المُسلَّم.
 *
 * كانت مقطوعةً في ثلاثة مواضع: السلّة لا تقبل صيغةً، وعرضُ السلّة والدفع
 * يسعّران من `product_prices` وحدها، والتسليم يختار بـ`product_id` وحده.
 * أيُّ واحدٍ منها يكفي ليدفع المشتري ثمن PowerPoint ويستلم Word.
 */
class VariantPurchaseChainTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $word;

    private ProductVariant $deck;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'VC-1',
            'slug' => 'vc-1',
            'title' => 'تحضير العلوم — الخامس الابتدائي',
            'status' => 'published',
            'published_at' => now(),
            'fulfillment_type' => 'digital',
        ]);

        DB::table('product_prices')->insert([
            'product_id' => $this->product->id,
            'regular_minor' => 4500,
            'currency' => 'SAR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $group = ProductGroup::create([
            'slug' => 'vc-g-1',
            'title' => $this->product->title,
            'product_nature' => 'DIGITAL',
            'status' => 'published',
            'legacy_product_id' => $this->product->id,
        ]);

        $this->word = $this->variant($group, 'Word', 'DOCX', 0, 4500);
        $this->deck = $this->variant($group, 'PowerPoint', 'PPTX', 1, 5500);
    }

    private function variant(ProductGroup $group, string $title, string $format, int $pos, int $minor): ProductVariant
    {
        $variant = ProductVariant::create([
            'product_group_id' => $group->id,
            'sku' => 'VC-'.$format,
            'slug' => 'vc-'.strtolower($format),
            'title' => $title,
            'format' => $format,
            'position' => $pos,
            'status' => 'published',
        ]);

        ProductOffer::create([
            'product_variant_id' => $variant->id,
            'selling_model' => 'ONE_TIME',
            'inventory_strategy' => 'UNLIMITED',
            'regular_minor' => $minor,
            'currency' => 'SAR',
            'status' => 'active',
        ]);

        return $variant;
    }

    /** مشترٍ واحد لكلّ الحالات — الاستحقاق لا يقوم بلا صاحب. */
    private function buyer(): Customer
    {
        return Customer::firstOrCreate(
            ['email' => 'buyer@example.test'],
            ['name' => 'مشترٍ'],
        );
    }

    private function attachFile(?ProductVariant $variant, string $label): Asset
    {
        $asset = Asset::create([
            'provider' => 'local',
            'provider_ref' => 'assets/'.uniqid(),
            'original_name' => $label,
            'sha256' => hash('sha256', $label),
            'size_bytes' => 100,
            'mime' => 'application/octet-stream',
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

    public function test_the_cart_prices_the_chosen_format_not_the_product(): void
    {
        $payload = $this->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'variantId' => $this->deck->id,
        ])->assertOk()->json();

        $this->assertSame(5500, $payload['items'][0]['priceMinor'], 'سُعِّر السطر بثمن المنتج لا بثمن الصيغة');
        $this->assertSame('PPTX', $payload['items'][0]['format']);
        $this->assertSame(5500, $payload['totals']['totalMinor']);
    }

    public function test_two_formats_of_one_product_are_two_lines(): void
    {
        /*
         * عبر الخدمة لا عبر نداءين HTTP: عميل الاختبار لا يحمل كعكة السلّة
         * بين النداءات، فيُنشئ كلُّ نداءٍ سلّةً جديدة ويمرّ الاختبار بلا أن
         * يقيس شيئًا. السلّة الواحدة الصريحة هي ما يُفحص هنا.
         */
        $cart = app(CartService::class)->resolveOrCreate(request());

        app(CartService::class)->addItem($cart, $this->product->id, $this->word->id);
        app(CartService::class)->addItem($cart, $this->product->id, $this->deck->id);

        $payload = app(CartService::class)->payload($cart);

        $this->assertCount(2, $payload['items'], 'صيغةٌ ابتلعت أختها في السلّة');
        $this->assertSame(10000, $payload['totals']['totalMinor']);
        $this->assertEqualsCanonicalizing(
            ['DOCX', 'PPTX'],
            array_column($payload['items'], 'format'),
        );
    }

    public function test_adding_the_same_format_twice_changes_nothing(): void
    {
        $cart = app(CartService::class)->resolveOrCreate(request());

        app(CartService::class)->addItem($cart, $this->product->id, $this->word->id);
        app(CartService::class)->addItem($cart, $this->product->id, $this->word->id);

        $this->assertCount(1, app(CartService::class)->payload($cart)['items']);
    }

    public function test_a_variant_of_another_product_cannot_price_this_one(): void
    {
        $other = Product::create([
            'sku' => 'VC-2', 'slug' => 'vc-2', 'title' => 'منتج آخر',
            'status' => 'published', 'published_at' => now(), 'fulfillment_type' => 'digital',
        ]);

        $this->expectException(ValidationException::class);

        app(CartService::class)->addItem(
            app(CartService::class)->resolveOrCreate(request()),
            $other->id,
            $this->deck->id,
        );
    }

    public function test_a_product_without_a_variant_still_adds_at_its_own_price(): void
    {
        $payload = $this->postJson('/api/v1/cart/items', ['productId' => $this->product->id])
            ->assertOk()->json();

        $this->assertSame(4500, $payload['items'][0]['priceMinor']);
        $this->assertArrayNotHasKey('variantId', $payload['items'][0]);
    }

    public function test_delivery_hands_over_the_file_of_the_purchased_format(): void
    {
        $wordAsset = $this->attachFile($this->word, 'word.docx');
        $deckAsset = $this->attachFile($this->deck, 'deck.pptx');

        $order = Order::create([
            'number' => 'TD-VC-1', 'email' => 'b@example.test', 'status' => 'PAID',
            'totals_gross_minor' => 5500, 'total_minor' => 5500,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->deck->id,
            'title_snapshot' => $this->product->title,
            'price_snapshot_minor' => 5500,
        ]);

        $entitlement = Entitlement::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'customer_id' => $this->buyer()->id,
            'product_id' => $this->product->id,
            'status' => 'ACTIVE',
            'granted_at' => now(),
        ]);

        $delivered = app(DownloadDelivery::class)->assetFor($entitlement);

        $this->assertNotNull($delivered);
        $this->assertSame($deckAsset->id, $delivered->id, 'دفع ثمن PowerPoint واستلم غيره');
        $this->assertNotSame($wordAsset->id, $delivered->id);
    }

    public function test_a_shared_file_reaches_every_format(): void
    {
        $shared = $this->attachFile(null, 'shared.pdf');

        $order = Order::create([
            'number' => 'TD-VC-2', 'email' => 'b@example.test', 'status' => 'PAID',
            'totals_gross_minor' => 5500, 'total_minor' => 5500,
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->deck->id,
            'title_snapshot' => $this->product->title,
            'price_snapshot_minor' => 5500,
        ]);
        $entitlement = Entitlement::create([
            'order_id' => $order->id, 'order_item_id' => $item->id,
            'customer_id' => $this->buyer()->id,
            'product_id' => $this->product->id, 'status' => 'ACTIVE', 'granted_at' => now(),
        ]);

        $this->assertSame($shared->id, app(DownloadDelivery::class)->assetFor($entitlement)?->id);
    }

    public function test_a_legacy_line_without_a_variant_keeps_its_file(): void
    {
        $legacy = $this->attachFile(null, 'legacy.docx');
        $this->attachFile($this->deck, 'deck.pptx');

        $order = Order::create([
            'number' => 'TD-VC-3', 'email' => 'b@example.test', 'status' => 'PAID',
            'totals_gross_minor' => 4500, 'total_minor' => 4500,
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'title_snapshot' => $this->product->title,
            'price_snapshot_minor' => 4500,
        ]);
        $entitlement = Entitlement::create([
            'order_id' => $order->id, 'order_item_id' => $item->id,
            'customer_id' => $this->buyer()->id,
            'product_id' => $this->product->id, 'status' => 'ACTIVE', 'granted_at' => now(),
        ]);

        $this->assertSame(
            $legacy->id,
            app(DownloadDelivery::class)->assetFor($entitlement)?->id,
            'سطرٌ قديم بلا صيغة استلم ملفّ صيغةٍ لم يشترها',
        );
    }

    public function test_one_cart_cannot_become_two_paid_orders(): void
    {
        /*
         * الدفع كان يقرأ البنود خارج المعاملة ويُفرّغ السلّة بعدها، فنداءان
         * متزامنان يُنشئان طلبين من سلّةٍ واحدة — يدفع الزبون مرّتين.
         * الاختبار تسلسليّ لا متزامن: الثاني يمثّل النداء الذي استيقظ بعد
         * أن حرّر الأوّل القفل، وهو ما يجب أن يجد السلّة فارغة.
         */
        $cart = app(CartService::class)->resolveOrCreate(request());
        app(CartService::class)->addItem($cart, $this->product->id, $this->word->id);

        $details = [
            'name' => 'مشترٍ',
            'email' => 'buyer@example.test',
            'phone' => '0500000000',
        ];

        app(CheckoutService::class)->createOrder($cart, $details);

        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->createOrder($cart->fresh(), $details);
    }

    public function test_a_variant_without_a_format_does_not_borrow_the_products(): void
    {
        /*
         * سطرٌ عنوانه «PowerPoint» وشريحته «DOCX» يسمّي صيغةً ويصفها بأخرى،
         * وهو آخر ما يراه المشتري قبل الدفع.
         */
        $this->deck->update(['format' => null]);

        $cart = app(CartService::class)->resolveOrCreate(request());
        app(CartService::class)->addItem($cart, $this->product->id, $this->deck->id);

        $line = app(CartService::class)->payload($cart)['items'][0];

        $this->assertNull($line['format'], 'استعار السطرُ صيغةَ المنتج القديمة');
        $this->assertSame('PowerPoint', $line['variantTitle']);
    }

    public function test_the_main_file_outranks_a_variant_bound_answer_key(): void
    {
        /*
         * الأخصّيّة كانت تسبق الدور، فمشتري الصيغة يستلم نموذج الإجابة
         * ولا يستلم الدرس أبدًا — الاستعلام يعيد ملفًّا واحدًا.
         */
        $main = $this->attachFile(null, 'main-lesson.docx');
        $asset = Asset::create([
            'provider' => 'local', 'provider_ref' => 'assets/'.uniqid(),
            'original_name' => 'answers.pdf', 'sha256' => hash('sha256', 'answers'),
            'size_bytes' => 10, 'mime' => 'application/pdf', 'visibility' => 'paid_private',
        ]);
        ProductFile::create([
            'product_id' => $this->product->id,
            'product_variant_id' => $this->deck->id,
            'asset_id' => $asset->id,
            'role' => 'answer_key',
            'position' => 0,
        ]);

        $order = Order::create([
            'number' => 'TD-VC-9', 'email' => 'b@example.test', 'status' => 'PAID',
            'totals_gross_minor' => 5500, 'total_minor' => 5500,
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id,
            'product_variant_id' => $this->deck->id, 'title_snapshot' => 'x',
            'price_snapshot_minor' => 5500,
        ]);
        $entitlement = Entitlement::create([
            'order_id' => $order->id, 'order_item_id' => $item->id,
            'customer_id' => $this->buyer()->id, 'product_id' => $this->product->id,
            'status' => 'ACTIVE', 'granted_at' => now(),
        ]);

        $this->assertSame(
            $main->id,
            app(DownloadDelivery::class)->assetFor($entitlement)?->id,
            'سُلِّم نموذج الإجابة بدل الدرس',
        );
    }
}
