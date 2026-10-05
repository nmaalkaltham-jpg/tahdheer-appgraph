<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductGroupAuthoring;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كلّ طبيعةٍ تبلغ السلّة — عبر الواجهة العامّة لا بإدراجٍ مباشر.
 *
 * ─── لماذا عبر الواجهة ───
 *
 * `MixedCartTest` يُدرج صفوف السلّة مباشرةً، فيُثبت اشتقاق المتطلّبات ولا
 * يمرّ بالبوّابة أصلًا. والبوّابة كانت شرطًا واحدًا —
 * `->where('fulfillment_type','digital')` — يجعل كلّ منتجٍ غير رقميّ
 * مستحيل الإضافة. أي أنّ منفّذ الشحن وآلة حالات الشحنات وستّ الطبائع كانت
 * كلّها غير قابلةٍ للبلوغ من المتجر، ولا اختبارَ يكشف ذلك لأنّ كلّ اختبارٍ
 * كان يتجاوزها.
 */
class AnyProductTypeCartTest extends TestCase
{
    use RefreshDatabase;

    private function published(string $nature, array $strategies = [], int $priceMinor = 5000): Product
    {
        $n = bin2hex(random_bytes(4));

        $product = Product::create([
            'sku' => "SKU-{$n}",
            'slug' => "p-{$n}",
            'title' => 'منتج اختباريّ',
            'status' => 'published',
            'fulfillment_type' => 'digital',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'regular_minor' => $priceMinor,
            'currency' => 'SAR',
        ]);

        app(ProductGroupAuthoring::class)->create($product, $nature, $strategies);

        /* المجموعة والصيغة والعرض تُنشر، وإلّا لم تُحلّ الصيغة في السلّة. */
        ProductGroup::query()->where('legacy_product_id', $product->id)
            ->update(['status' => 'published']);
        ProductVariant::query()->where('legacy_product_id', $product->id)
            ->update(['status' => 'published']);

        $variant = ProductVariant::query()->where('legacy_product_id', $product->id)->firstOrFail();
        ProductOffer::query()->where('product_variant_id', $variant->id)
            ->update(['status' => 'active', 'regular_minor' => $priceMinor]);

        return $product;
    }

    public function test_every_nature_reaches_the_cart(): void
    {
        foreach (['DIGITAL', 'PHYSICAL', 'SERVICE', 'ACCESS_BASED'] as $nature) {
            $product = $this->published($nature);

            $this->postJson('/api/v1/cart/items', ['productId' => $product->id])
                ->assertOk();
        }

        $hybrid = $this->published('HYBRID', ['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT']);
        $this->postJson('/api/v1/cart/items', ['productId' => $hybrid->id])->assertOk();
    }

    public function test_the_cart_declares_that_a_physical_line_needs_an_address(): void
    {
        $product = $this->published('PHYSICAL');

        $payload = $this->postJson('/api/v1/cart/items', ['productId' => $product->id])->assertOk();

        $this->assertTrue($payload->json('requirements.requiresShippingAddress'),
            'السلّة تُعلن ما تحتاجه — والواجهة لا تقرّره بنفسها');
        $this->assertContains('PHYSICAL_SHIPMENT', $payload->json('requirements.strategies'));
        $this->assertTrue($payload->json('requirements.canProceed'));
    }

    public function test_a_digital_cart_still_asks_for_no_address(): void
    {
        $product = $this->published('DIGITAL');

        $this->postJson('/api/v1/cart/items', ['productId' => $product->id])
            ->assertOk()
            ->assertJsonPath('requirements.requiresShippingAddress', false)
            ->assertJsonPath('requirements.isMixed', false);
    }

    public function test_a_hybrid_line_alone_is_already_mixed(): void
    {
        $product = $this->published('HYBRID', ['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT']);

        $payload = $this->postJson('/api/v1/cart/items', ['productId' => $product->id])->assertOk();

        $this->assertTrue($payload->json('requirements.isMixed'));
        $this->assertTrue($payload->json('requirements.requiresShippingAddress'));
        $this->assertCount(2, $payload->json('requirements.strategies'),
            'الهجين ينقسم إلى وحدتَي تنفيذٍ مستقلّتين — واحدةٌ منهما ليست هجينًا');
    }

    public function test_quantity_is_honoured_for_physical_and_capped_for_digital(): void
    {
        $physical = $this->published('PHYSICAL', [], 5000);

        $payload = $this->postJson('/api/v1/cart/items', [
            'productId' => $physical->id, 'qty' => 3,
        ])->assertOk();

        $this->assertSame(3, $payload->json('items.0.qty'));
        $this->assertSame(5000, $payload->json('items.0.priceMinor'), 'الصفّ يعرض سعر الوحدة');
        $this->assertSame(15000, $payload->json('totals.grossMinor'),
            'والإجمالي يجمع قيمة السطر — وإلّا قال الطلب ثلاثًا وحُصّل ثمن واحدة');
    }

    public function test_a_digital_line_stays_at_one_however_many_are_asked(): void
    {
        $digital = $this->published('DIGITAL', [], 5000);

        $payload = $this->postJson('/api/v1/cart/items', [
            'productId' => $digital->id, 'qty' => 7,
        ])->assertOk();

        $this->assertSame(1, $payload->json('items.0.qty'),
            'نسختان من ملفٍّ واحد لا معنى لهما — والحدّ قدرةٌ تُسأل لا ثابتٌ عامّ');
        $this->assertSame(5000, $payload->json('totals.grossMinor'));
    }

    public function test_a_service_cart_asks_for_booking_not_an_address(): void
    {
        $product = $this->published('SERVICE', ['APPOINTMENT']);

        $payload = $this->postJson('/api/v1/cart/items', ['productId' => $product->id])->assertOk();

        $this->assertTrue($payload->json('requirements.requiresBookingDetails'));
        $this->assertFalse($payload->json('requirements.requiresShippingAddress'));
    }

    public function test_an_unpublished_product_is_still_refused(): void
    {
        $product = $this->published('PHYSICAL');
        $product->update(['status' => 'draft']);

        $this->postJson('/api/v1/cart/items', ['productId' => $product->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['productId']);
    }

    public function test_delivery_is_attributed_to_the_line_offer_not_a_legacy_guess(): void
    {
        $product = $this->published('PHYSICAL');
        $variant = ProductVariant::query()->where('legacy_product_id', $product->id)->firstOrFail();
        $offer = ProductOffer::query()->where('product_variant_id', $variant->id)->firstOrFail();

        $this->assertSame(
            ['PHYSICAL_SHIPMENT'],
            OfferFulfillment::query()->where('product_offer_id', $offer->id)->pluck('strategy')->all(),
        );
    }
}
