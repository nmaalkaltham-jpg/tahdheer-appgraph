<?php

namespace Tests\Feature\Commerce;

use App\Domain\Cart\CartService;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Testing\TestResponse;

trait CommerceFixtures
{
    protected function setUp(): void
    {
        parent::setUp();
        // JSON test requests only carry cookies with credentials enabled.
        $this->withCredentials();
    }

    protected function makeProduct(string $sku, int $regularMinor, ?int $saleMinor = null): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'slug' => strtolower($sku),
            'title' => 'تحضير مادة لغتي — '.$sku,
            'status' => 'published',
            'fulfillment_type' => 'digital',
            'format' => 'pptx',
            'published_at' => now()->subDay(),
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'regular_minor' => $regularMinor,
            'sale_minor' => $saleMinor,
            'sale_from' => $saleMinor !== null ? now()->subDay() : null,
            'sale_to' => $saleMinor !== null ? now()->addWeek() : null,
            'currency' => 'SAR',
        ]);

        return $product;
    }

    protected function cartCookie(TestResponse $response): string
    {
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getName() === CartService::COOKIE_NAME) {
                return (string) $cookie->getValue();
            }
        }

        $this->fail('Expected a '.CartService::COOKIE_NAME.' cookie on the response.');
    }

    /** @param array<int, Product> $products */
    protected function cartWith(array $products): string
    {
        // Start a genuinely fresh cart: cookies persist across requests in a test.
        unset($this->unencryptedCookies[CartService::COOKIE_NAME], $this->defaultCookies[CartService::COOKIE_NAME]);
        $cookie = null;
        foreach ($products as $product) {
            $request = $cookie === null ? $this : $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie);
            $response = $request->postJson('/api/v1/cart/items', ['productId' => $product->id]);
            $response->assertOk();
            $cookie = $this->cartCookie($response);
        }

        return $cookie;
    }

    /**
     * Full happy-path checkout over the API.
     *
     * @return array{order: Order, token: string, number: string}
     */
    protected function checkoutOrder(string $cartCookie, array $overrides = []): array
    {
        $response = $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cartCookie)
            ->postJson('/api/v1/checkout/orders', array_merge([
                'name' => 'نورة العتيبي',
                'email' => 'noura@example.com',
                'phone' => '512345678',
                'taxProfile' => null,
                'policyConsent' => true,
                'marketingOptIn' => false,
            ], $overrides));

        $response->assertCreated();
        $number = $response->json('orderNumber');

        return [
            'order' => Order::query()->where('number', $number)->firstOrFail(),
            'token' => $response->json('orderToken'),
            'number' => $number,
        ];
    }
}
