<?php

namespace Tests\Feature\Commerce;

use App\Domain\Cart\CartService;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class CartApiTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    public function test_empty_cart_is_an_explicit_empty_state_and_creates_no_row(): void
    {
        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertExactJson([
                'items' => [],
                'totals' => [
                    'grossMinor' => 0, 'productDiscountMinor' => 0, 'couponDiscountMinor' => 0,
                    'totalMinor' => 0, 'vatMinor' => 0, 'savingMinor' => 0,
                ],
                'coupon' => null,
                'count' => 0,
            ]);

        $this->assertSame(0, Cart::query()->count());
    }

    public function test_add_item_sets_encrypted_httponly_lax_cookie(): void
    {
        $product = $this->makeProduct('TD-C-001', 40000);

        $response = $this->postJson('/api/v1/cart/items', ['productId' => $product->id]);

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.productId', $product->id)
            ->assertJsonPath('items.0.qty', 1)
            ->assertJsonPath('items.0.priceMinor', 40000);

        $cookie = collect($response->baseResponse->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === CartService::COOKIE_NAME);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        // Cookie carries only the encrypted cart uuid.
        $this->assertSame(Cart::query()->firstOrFail()->token, Crypt::decryptString($cookie->getValue()));
    }

    /**
     * عقد الحقل، لا مجرّد وجوده.
     *
     * كان CartService يُعيد categoryPath نصًّا مدموجًا بـ' / ' بينما
     * ProductCardResource يُعيده مصفوفة، والواجهة تكتب join(' › ') في كل
     * موضع. فانهار عرض السلة فور إضافة أوّل منتج — «join is not a function»
     * — بعد استجابة 200 صحيحة تمامًا. اثنان وعشرون اختبارًا للسلة كانت تمرّ
     * ولم يمسك أيٌّ منها هذا، لأن أيًّا منها لم يثبّت نوع الحقل.
     */
    public function test_category_path_is_an_array_matching_the_product_contract(): void
    {
        $product = $this->makeProduct('TD-C-PATH', 40000);

        $response = $this->postJson('/api/v1/cart/items', ['productId' => $product->id]);
        $response->assertOk();

        $path = $response->json('items.0.categoryPath');
        $this->assertIsArray($path, 'categoryPath يجب أن يكون مصفوفة — الواجهة تستدعي join() عليه');

        foreach ($path as $segment) {
            $this->assertIsString($segment);
            $this->assertStringNotContainsString('/', $segment, 'الفاصل قرار عرض لا يُدمَج في الخادم');
        }

        // القراءة اللاحقة تحمل العقد نفسه، لا شكل الكتابة وحدها. الكوكي
        // يُمرَّر صراحةً: طلب بلا كوكي يرى سلّةً أخرى فارغة.
        $cookie = $this->cartCookie($response);
        $this->assertIsArray(
            $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
                ->getJson('/api/v1/cart')
                ->json('items.0.categoryPath'),
        );
    }

    public function test_duplicate_add_is_a_noop_qty_locked_to_one(): void
    {
        $product = $this->makeProduct('TD-C-002', 40000);
        $cookie = $this->cartWith([$product]);

        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/cart/items', ['productId' => $product->id])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.qty', 1);

        $this->assertSame(1, CartItem::query()->count());
        $this->assertSame(1, (int) CartItem::query()->firstOrFail()->qty);
    }

    public function test_unknown_product_is_rejected(): void
    {
        $this->postJson('/api/v1/cart/items', ['productId' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('message', 'المنتج غير متاح');
    }

    public function test_remove_item(): void
    {
        $product = $this->makeProduct('TD-C-003', 40000);
        $cookie = $this->cartWith([$product]);
        $itemId = CartItem::query()->firstOrFail()->id;

        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->deleteJson("/api/v1/cart/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('items', []);
    }

    public function test_remove_missing_item_is_404(): void
    {
        $this->deleteJson('/api/v1/cart/items/12345')->assertNotFound();
    }

    public function test_totals_chain_with_sale_and_percent_coupon(): void
    {
        $plain = $this->makeProduct('TD-C-004', 40000);
        $onSale = $this->makeProduct('TD-C-005', 30000, 25000);
        Coupon::query()->create(['code' => 'TEACHER15', 'type' => 'percent', 'value' => 15]);

        $cookie = $this->cartWith([$plain, $onSale]);

        $response = $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/cart/coupon', ['code' => 'TEACHER15']);

        $response->assertOk()
            ->assertJsonPath('totals.grossMinor', 70000)
            ->assertJsonPath('totals.productDiscountMinor', 5000)
            ->assertJsonPath('totals.couponDiscountMinor', 9750)
            ->assertJsonPath('totals.totalMinor', 55250)
            ->assertJsonPath('totals.vatMinor', 7207)
            ->assertJsonPath('totals.savingMinor', 14750)
            ->assertJsonPath('coupon.code', 'TEACHER15')
            ->assertJsonPath('coupon.label', 'خصم 15٪')
            ->assertJsonPath('items.1.regularMinor', 30000)
            ->assertJsonPath('items.1.priceMinor', 25000)
            ->assertJsonPath('items.1.discountPct', 17);
    }

    public function test_invalid_coupon_is_422_with_arabic_message(): void
    {
        $product = $this->makeProduct('TD-C-006', 40000);
        $cookie = $this->cartWith([$product]);

        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/cart/coupon', ['code' => 'NOPE'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'رمز الخصم غير صالح أو منتهي');
    }

    public function test_expired_coupon_is_422(): void
    {
        Coupon::query()->create([
            'code' => 'OLD10', 'type' => 'percent', 'value' => 10, 'expires_at' => now()->subDay(),
        ]);
        $cookie = $this->cartWith([$this->makeProduct('TD-C-007', 40000)]);

        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/cart/coupon', ['code' => 'OLD10'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'رمز الخصم غير صالح أو منتهي');
    }

    public function test_remove_coupon_restores_totals(): void
    {
        Coupon::query()->create(['code' => 'TEACHER15', 'type' => 'percent', 'value' => 15]);
        $cookie = $this->cartWith([$this->makeProduct('TD-C-008', 40000)]);

        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/cart/coupon', ['code' => 'TEACHER15'])->assertOk();

        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->deleteJson('/api/v1/cart/coupon')
            ->assertOk()
            ->assertJsonPath('coupon', null)
            ->assertJsonPath('totals.couponDiscountMinor', 0)
            ->assertJsonPath('totals.totalMinor', 40000);
    }
}
