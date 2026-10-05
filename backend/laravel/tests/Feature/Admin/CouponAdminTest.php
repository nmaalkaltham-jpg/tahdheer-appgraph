<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Cart\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إصدار رموز الخصم — القدرة التي كانت كاملة بلا باب.
 *
 * الجدول والقواعد وتطبيق السلة وإعادة الحساب عند الطلب كلّها مبنيّة، ولم يكن
 * لمالك المتجر سبيل إلى إصدار رمز واحد إلا بكتابة صفٍّ بيده في قاعدة البيانات.
 */
class CouponAdminTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'BACK2SCHOOL',
            'type' => 'percent',
            'value' => 15,
        ], $overrides);
    }

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics))
            ->getJson('/api/v1/admin/coupons')
            ->assertStatus(403);
    }

    /* ───── الإصدار ───── */

    public function test_a_coupon_is_issued_and_immediately_usable_in_the_cart(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->postJson('/api/v1/admin/coupons', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.label', 'خصم 15٪')
            ->assertJsonPath('data.usable', true);

        // الرمز يعمل في السلة فورًا — وهذا هو المعنى الوحيد لـ«أُصدر».
        $this->postJson('/api/v1/cart/coupon', ['code' => 'back2school'])->assertOk();
    }

    /** الرمز يُكتب بيد المشتري: المسافة والرموز تُدخَل خطأً فتُرفض عند الإصدار. */
    public function test_a_code_with_spaces_or_symbols_is_refused(): void
    {
        $admin = $this->makeAdmin(AdminRole::Owner);

        foreach (['BACK 2 SCHOOL', 'خصم%50', 'A+B'] as $bad) {
            $this->actingAsAdmin($admin)
                ->postJson('/api/v1/admin/coupons', $this->payload(['code' => $bad]))
                ->assertStatus(422);
        }
    }

    public function test_a_percentage_above_one_hundred_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->postJson('/api/v1/admin/coupons', $this->payload(['value' => 120]))
            ->assertStatus(422);
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $admin = $this->makeAdmin(AdminRole::Owner);
        $this->actingAsAdmin($admin)->postJson('/api/v1/admin/coupons', $this->payload())->assertStatus(201);
        $this->actingAsAdmin($admin)->postJson('/api/v1/admin/coupons', $this->payload())->assertStatus(422);
    }

    /* ───── الصلاحية محسوبة لا مخزَّنة ───── */

    /** «ساري» نتيجةُ نفس الدالّة التي تحكم السلة — لا رأي ثانٍ فيها. */
    public function test_the_listed_state_matches_what_the_cart_will_do(): void
    {
        Coupon::query()->create([
            'code' => 'EXPIRED', 'type' => 'fixed', 'value' => 500,
            'expires_at' => now()->subDay(),
        ]);
        Coupon::query()->create([
            'code' => 'SPENT', 'type' => 'percent', 'value' => 10,
            'usage_limit' => 2, 'usage_count' => 2,
        ]);

        $rows = collect(
            $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
                ->getJson('/api/v1/admin/coupons')->assertOk()->json('data'),
        )->keyBy('code');

        $this->assertFalse($rows['EXPIRED']['usable']);
        $this->assertSame('انتهى تاريخه', $rows['EXPIRED']['reason']);
        $this->assertFalse($rows['SPENT']['usable']);
        $this->assertSame('بلغ حدّ الاستعمال', $rows['SPENT']['reason']);

        // وما تقوله اللوحة هو ما يحدث للمشتري.
        $this->postJson('/api/v1/cart/coupon', ['code' => 'EXPIRED'])->assertStatus(422);
        $this->postJson('/api/v1/cart/coupon', ['code' => 'SPENT'])->assertStatus(422);
    }

    /* ───── الإنهاء والحذف ───── */

    public function test_expiring_stops_the_code_at_once(): void
    {
        $coupon = Coupon::query()->create(['code' => 'LIVE', 'type' => 'percent', 'value' => 20]);

        $this->postJson('/api/v1/cart/coupon', ['code' => 'LIVE'])->assertOk();

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->postJson("/api/v1/admin/coupons/{$coupon->id}/expire")
            ->assertOk()
            ->assertJsonPath('data.usable', false);

        $this->postJson('/api/v1/cart/coupon', ['code' => 'LIVE'])->assertStatus(422);
    }

    /**
     * رمزٌ استُعمل لا يُحذف.
     *
     * الطلب يحفظ مبلغ الخصم لا الرمز؛ فحذف رمز استعمله عميل يمحو تفسير خصمٍ
     * في سجلٍّ محاسبي، ويترك رقمًا بلا سبب.
     */
    public function test_a_used_coupon_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $used = Coupon::query()->create([
            'code' => 'USED', 'type' => 'percent', 'value' => 10, 'usage_count' => 3,
        ]);
        $fresh = Coupon::query()->create(['code' => 'FRESH', 'type' => 'percent', 'value' => 10]);
        $admin = $this->makeAdmin(AdminRole::Owner);

        $this->actingAsAdmin($admin)
            ->deleteJson("/api/v1/admin/coupons/{$used->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COUPON_IN_USE');

        $this->actingAsAdmin($admin)->deleteJson("/api/v1/admin/coupons/{$fresh->id}")->assertOk();

        $this->assertNotNull(Coupon::query()->find($used->id));
        $this->assertNull(Coupon::query()->find($fresh->id));
    }
}
