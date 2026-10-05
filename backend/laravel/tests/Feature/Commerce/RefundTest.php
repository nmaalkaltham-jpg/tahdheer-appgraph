<?php

namespace Tests\Feature\Commerce;

use App\Domain\Entitlements\DownloadDenied;
use App\Domain\Entitlements\EntitlementService;
use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Orders\RefundService;
use App\Domain\Outbox\OutboxEvent;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private function paidOrder(string $sku): Order
    {
        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);
        $result = $this->checkoutOrder($cookie);
        $this->postJson("/api/v1/payments/fake/{$result['token']}/confirm")->assertOk();

        return $result['order']->fresh();
    }

    public function test_immediate_policy_revokes_now(): void
    {
        $order = $this->paidOrder('TD-R-001');

        $refund = app(RefundService::class)->refund(
            $order, (int) $order->total_minor, 'طلب العميلة', RevocationPolicy::Immediate,
        );

        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id,
            'order_id' => $order->id,
            'amount_minor' => (int) $order->total_minor,
            'revocation_policy' => 'immediate',
        ]);
        $this->assertSame('REFUNDED', $order->fresh()->status);

        $entitlement = Entitlement::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('REVOKED', $entitlement->status);
        $this->assertNotNull($entitlement->revoked_at);

        $this->assertSame(1, OutboxEvent::query()
            ->where('event_type', 'refund.completed')
            ->where('idempotency_key', "refund:{$refund->id}:completed")
            ->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'entitlement.revoked')->count());
    }

    public function test_grace_3d_policy_keeps_access_until_cutoff(): void
    {
        $order = $this->paidOrder('TD-R-002');

        app(RefundService::class)->refund(
            $order, (int) $order->total_minor, 'استرداد مع مهلة', RevocationPolicy::Grace3d,
        );

        $entitlement = Entitlement::query()->where('order_id', $order->id)->firstOrFail();

        // Still ACTIVE now; the cutoff is scheduled ~3 days out via expires_at.
        $this->assertSame('ACTIVE', $entitlement->status);
        $this->assertNull($entitlement->revoked_at);
        $this->assertNotNull($entitlement->expires_at);
        $this->assertEqualsWithDelta(
            now()->addDays(3)->getTimestamp(),
            $entitlement->expires_at->getTimestamp(),
            5,
        );

        // After the cutoff the ledger itself denies (expired), even before
        // any status-sweeper job runs.
        $this->travel(4)->days();
        $this->expectException(DownloadDenied::class);
        app(EntitlementService::class)->validateForDownload($entitlement);
    }

    public function test_partial_refund_marks_partially_refunded(): void
    {
        $order = $this->paidOrder('TD-R-003');

        app(RefundService::class)->refund($order, 1000, null, RevocationPolicy::Keep);

        $this->assertSame('PARTIALLY_REFUNDED', $order->fresh()->status);
        // Keep policy: access untouched.
        $this->assertSame('ACTIVE', Entitlement::query()->where('order_id', $order->id)->firstOrFail()->status);
    }

    public function test_a_partial_refund_with_immediate_policy_keeps_the_entitlement(): void
    {
        $order = $this->paidOrder('TD-R-004');

        app(RefundService::class)->refund($order, 1, 'تعويض تأخير', RevocationPolicy::Immediate);

        /*
         * ردُّ هللةٍ واحدة من أربعمئة ريال لا يُفقد الزبون الملفّ: السحب
         * مشروطٌ بردٍّ كامل — للمفاتيح والشحنات **والاستحقاقات** سواء.
         */
        $this->assertSame('PARTIALLY_REFUNDED', $order->fresh()->status);
        $this->assertSame('ACTIVE', Entitlement::query()->where('order_id', $order->id)->firstOrFail()->status);
    }

    public function test_revoked_at_is_written_once(): void
    {
        $order = $this->paidOrder('TD-R-005');
        $service = app(EntitlementService::class);
        $entitlement = Entitlement::query()->where('order_id', $order->id)->firstOrFail();

        $service->revoke($entitlement, 'أوّل', RevocationPolicy::Immediate);
        $first = $entitlement->fresh()->revoked_at;
        $this->travel(1)->hours();
        $service->revoke($entitlement->fresh(), 'ثانٍ', RevocationPolicy::Immediate);

        $this->assertEquals($first, $entitlement->fresh()->revoked_at);
    }
}
