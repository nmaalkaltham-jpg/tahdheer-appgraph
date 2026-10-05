<?php

namespace Tests\Feature\Architecture;

use App\Providers\RateLimitServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * سلوك حدود المعدّل على مسارات التجارة — لا شكلها وحده.
 *
 * `CommerceThrottleTest` يثبت أيّ وسيطٍ على أيّ مسار. وهذا يثبت ما يعنيه
 * ذلك للزائر: التصفّح العاديّ (الترويسة تقرأ السلّة في كلّ صفحة، وصفحة
 * الطلب تستطلع حالته) لا يبلغ ‎429‎، والكتابة أضيق من القراءة، ولا مسار دفعٍ
 * أو إنشاء طلبٍ على حدّ القراءة المتسامح، والإغراق بالقراءة يُردّ في النهاية.
 *
 * والتصنيف بالأثر التجاريّ لا بالفعل وحده: `GET /downloads/redeem/{token}`
 * فعلُه GET لكنّه يستهلك رمز تنزيلٍ ويعدّ التنزيلات، فهو على حدّ الكتابة.
 */
class CommerceRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * كلّ مسارٍ تجاريّ عامّ مصنَّفًا — مسارٌ جديد بلا تصنيفٍ هنا يُسقط الاختبار.
     *
     * @var array<string, array{class: string, throttle: string|null}>
     */
    private const CLASSIFICATION = [
        'GET api/v1/checkout/methods' => ['class' => 'READ', 'throttle' => 'throttle:read'],
        'GET api/v1/cart' => ['class' => 'READ', 'throttle' => 'throttle:read'],
        'GET api/v1/orders/{token}' => ['class' => 'READ', 'throttle' => 'throttle:read'],
        'POST api/v1/cart/items' => ['class' => 'WRITE', 'throttle' => 'throttle:write'],
        'DELETE api/v1/cart/items/{itemId}' => ['class' => 'WRITE', 'throttle' => 'throttle:write'],
        'POST api/v1/cart/coupon' => ['class' => 'ABUSE-SENSITIVE', 'throttle' => 'throttle:write'],
        'DELETE api/v1/cart/coupon' => ['class' => 'WRITE', 'throttle' => 'throttle:write'],
        'POST api/v1/checkout/orders' => ['class' => 'PAYMENT-SENSITIVE', 'throttle' => 'throttle:write'],
        'POST api/v1/checkout/orders/{token}/payment' => ['class' => 'PAYMENT-SENSITIVE', 'throttle' => 'throttle:write'],
        'POST api/v1/downloads/{entitlementId}/grant' => ['class' => 'ABUSE-SENSITIVE', 'throttle' => 'throttle:write'],
        'GET api/v1/downloads/redeem/{rawToken}' => ['class' => 'ABUSE-SENSITIVE', 'throttle' => 'throttle:write'],
        // تُعيد البوّابة المحاولة وحمايته التوقيع — حدٌّ هنا يُسقط إشعار دفعٍ حقيقيّ.
        'POST api/v1/payments/webhook/{provider}' => ['class' => 'PAYMENT-SENSITIVE', 'throttle' => null],
        // أداة تطوير: المتحكّم يردّ ‎404‎ خارج local/testing.
        'POST api/v1/payments/fake/{orderToken}/confirm' => ['class' => 'PAYMENT-SENSITIVE', 'throttle' => null],
    ];

    /** الصفحات التي تلمس التجارة: كلّ تصييرٍ يقرأ السلّة من الترويسة. */
    private const COMMERCE_PREFIXES = ['api/v1/cart', 'api/v1/checkout', 'api/v1/orders', 'api/v1/downloads', 'api/v1/payments'];

    /** @return list<string> */
    private function throttlesOf(RouteDefinition $route): array
    {
        return array_values(array_filter(
            $route->gatherMiddleware(),
            fn ($m) => str_starts_with((string) $m, 'throttle:'),
        ));
    }

    public function test_every_public_commerce_route_is_classified_and_on_its_limiter(): void
    {
        $seen = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $isCommerce = false;
            foreach (self::COMMERCE_PREFIXES as $prefix) {
                if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                    $isCommerce = true;
                }
            }
            if (! $isCommerce) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = "{$method} {$uri}";
                $seen[] = $key;
                $this->assertArrayHasKey($key, self::CLASSIFICATION, "مسارٌ تجاريّ بلا تصنيف: {$key}");
                $want = self::CLASSIFICATION[$key]['throttle'];
                $this->assertSame($want === null ? [] : [$want], $this->throttlesOf($route), $key);
            }
        }

        $this->assertEqualsCanonicalizing(array_keys(self::CLASSIFICATION), $seen, 'تصنيفٌ لمسارٍ لم يعد موجودًا');
    }

    public function test_no_payment_or_mutation_route_sits_on_the_permissive_read_limiter(): void
    {
        foreach (self::CLASSIFICATION as $key => $spec) {
            if ($spec['class'] === 'READ') {
                continue;
            }
            $this->assertNotSame('throttle:read', $spec['throttle'], "{$key} ({$spec['class']}) على حدّ القراءة");
        }
    }

    public function test_write_limit_is_stricter_than_read_limit(): void
    {
        $this->assertLessThan(
            RateLimitServiceProvider::LIMITS['read'],
            RateLimitServiceProvider::LIMITS['write'],
        );
    }

    /**
     * جلسة تصفّحٍ عاديّة بحجمٍ يفوق حدّ الكتابة كاملًا: ٨٠ صفحة في دقيقة، كلٌّ
     * منها يقرأ السلّة (الترويسة) وطرق الدفع (التذييل). على حدّ الكتابة القديم
     * كانت الصفحة الحادية والستّون تُردّ ‎429‎.
     */
    public function test_normal_browsing_with_the_header_cart_read_never_hits_429(): void
    {
        $pages = 80;
        $this->assertGreaterThan(RateLimitServiceProvider::LIMITS['write'], $pages);

        for ($i = 0; $i < $pages; $i++) {
            $this->getJson('/api/v1/cart')->assertOk();
            $this->getJson('/api/v1/checkout/methods')->assertOk();
        }
    }

    /**
     * استطلاع حالة الطلب كما تفعله صفحة الطلب (كلّ ٢–١٠ ثوانٍ لثلاث دقائق)
     * مضاعفًا، مع قراءة السلّة في كلّ مرّة — لا ‎429‎.
     */
    public function test_order_status_polling_stays_available(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->getJson('/api/v1/orders/not-a-real-token')->assertNotFound();
            $this->getJson('/api/v1/cart')->assertOk();
        }
    }

    /** الإغراق بالقراءة يُردّ في النهاية — القراءة ليست بلا حدّ. */
    public function test_read_abuse_is_still_throttled(): void
    {
        $limit = RateLimitServiceProvider::LIMITS['read'];

        for ($i = 0; $i < $limit; $i++) {
            $this->getJson('/api/v1/cart')->assertOk();
        }

        $this->getJson('/api/v1/cart')
            ->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }

    /** الكتابة تُخنق قبل القراءة بكثير — وحدّها منفصل عن حدّ القراءة. */
    public function test_write_is_throttled_at_the_stricter_limit_and_independently_of_reads(): void
    {
        $limit = RateLimitServiceProvider::LIMITS['write'];

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/cart/coupon', ['code' => 'NOPE-'.$i])->assertStatus(422);
        }

        $this->postJson('/api/v1/cart/coupon', ['code' => 'OVER'])->assertStatus(429);

        // قراءة السلّة ما زالت متاحة: الحدّان دلوان منفصلان.
        $this->getJson('/api/v1/cart')->assertOk();
    }
}
