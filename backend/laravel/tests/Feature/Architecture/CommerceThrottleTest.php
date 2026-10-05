<?php

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * حدود المعدّل على مسارات التجارة العامّة — مثبَّتةٌ بالاختبار لا بالاتّفاق.
 *
 * القراءات المتكرّرة (السلّة في كلّ ترويسة، والطلب المستطلَع) على حدّ القراءة،
 * وكلّ ما يغيّر حالةً — بما فيه منحُ التنزيل — على حدّ الكتابة.
 */
class CommerceThrottleTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function routes(): array
    {
        return [
            'cart read' => ['GET', 'api/v1/cart', 'throttle:read'],
            'order read' => ['GET', 'api/v1/orders/{token}', 'throttle:read'],
            'checkout methods' => ['GET', 'api/v1/checkout/methods', 'throttle:read'],
            'cart add' => ['POST', 'api/v1/cart/items', 'throttle:write'],
            'checkout' => ['POST', 'api/v1/checkout/orders', 'throttle:write'],
            'download grant' => ['POST', 'api/v1/downloads/{entitlementId}/grant', 'throttle:write'],
        ];
    }

    #[DataProvider('routes')]
    public function test_each_public_commerce_route_sits_on_the_expected_throttle(string $method, string $uri, string $throttle): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));

        $this->assertNotNull($route, "المسار مفقود: {$method} {$uri}");

        $throttles = array_values(array_filter($route->gatherMiddleware(), fn ($m) => str_starts_with((string) $m, 'throttle:')));

        $this->assertSame([$throttle], $throttles, "{$method} {$uri}");
    }
}
