<?php

namespace Tests\Feature\Commerce;

use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Finance\Reconciliation;
use App\Domain\Orders\Models\Refund;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\RefundExceedsCaptured;
use App\Domain\Orders\RefundFailed;
use App\Domain\Orders\RefundService;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\RefundOutcomeUnknown;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * §٩ §١٩ — إعادةٌ مئةَ مرّة، وتسلسلاتٌ عشوائيّة، والثوابت بعد كلّ خطوة.
 *
 * الثوابت المفحوصة بعد **كلّ** حدث:
 *   I1  refunded + reserved ≤ total (للطلبات التي وصل مالُها)
 *   I2  رسمُ مزوّدٍ واحد = صفُّ تحصيلٍ واحد، ونداءُ استردادٍ واحد لكلّ مفتاح
 *   I3  الاستحقاق نشطٌ ⇔ الطلب مموَّل ولم يُردّ مالُه كاملًا (سياسة فوريّة)
 *   I4  حالةُ الطلب تطابق المحسوم (يحرسها القيد أيضًا)
 *   I5  «refund.completed» واحد لكلّ استردادٍ محسوم، ولا شيء لغيره
 */
class MoneyModelTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private function awaitingOrder(string $sku): Order
    {
        FakeProvider::resetRefunds();
        config()->set('services.fake.refund_mode', 'succeed');
        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);

        return $this->checkoutOrder($cookie)['order'];
    }

    private function capture(Order $order, string $chargeId): TestResponse
    {
        $body = json_encode([
            'charge_id' => $chargeId, 'order_number' => $order->number, 'status' => 'CAPTURED',
            'amount_minor' => 40000, 'currency' => 'SAR',
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/payments/webhook/fake', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body),
        ], content: $body);
    }

    private function refundWebhook(string $refundId, string $chargeId, string $status): TestResponse
    {
        $body = json_encode(['object' => 'refund', 'refund_id' => $refundId, 'charge_id' => $chargeId, 'status' => $status], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/payments/webhook/fake', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body),
        ], content: $body);
    }

    public function test_the_same_capture_webhook_one_hundred_times_funds_once(): void
    {
        $order = $this->awaitingOrder('TD-MM-001');

        for ($i = 0; $i < 100; $i++) {
            $this->capture($order, 'chg_100')->assertOk();
        }

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('state', 'CAPTURED')->count());
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.paid')->where('aggregate_id', (string) $order->id)->count());
        $this->assertSame(1, DB::table('webhook_deliveries')->where('charge_id', 'chg_100')->count());
    }

    public function test_the_same_refund_request_one_hundred_times_calls_the_provider_once(): void
    {
        $order = $this->awaitingOrder('TD-MM-002');
        $this->capture($order, 'chg_r100')->assertOk();
        $service = app(RefundService::class);

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $service->refund($order->fresh(), 40000, 'x', RevocationPolicy::Immediate, 'same-key')->id;
        }

        $this->assertCount(1, array_unique($ids));
        $this->assertCount(1, FakeProvider::$refundCalls, 'المزوّد نودي أكثر من مرّة لاستردادٍ واحد.');
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
        $this->assertSame(40000, (int) $order->fresh()->refunded_total_minor);
    }

    public function test_a_timed_out_refund_retried_one_hundred_times_moves_money_once(): void
    {
        $order = $this->awaitingOrder('TD-MM-003');
        $this->capture($order, 'chg_t100')->assertOk();
        $service = app(RefundService::class);

        config()->set('services.fake.refund_mode', 'timeout');
        for ($i = 0; $i < 50; $i++) {
            try {
                $service->refund($order->fresh(), 40000, 'x', RevocationPolicy::Immediate, 'k-timeout');
            } catch (RefundOutcomeUnknown) {
            }
        }
        config()->set('services.fake.refund_mode', 'succeed');
        for ($i = 0; $i < 50; $i++) {
            $service->refund($order->fresh(), 40000, 'x', RevocationPolicy::Immediate, 'k-timeout');
        }

        // كلّ نداءٍ حمل مفتاح المزوّد نفسه: عنده استردادٌ واحد مهما تكرّر.
        $keys = array_unique(array_column(FakeProvider::$refundCalls, 'idempotencyKey'));
        $this->assertCount(1, $keys);
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
        $this->assertSame('SUCCEEDED', Refund::query()->where('order_id', $order->id)->value('status'));
        $this->assertSame(40000, (int) $order->fresh()->refunded_total_minor);
        $this->assertSame(0, (int) $order->fresh()->refund_reserved_minor);
    }

    /**
     * تسلسلاتٌ عشوائيّة من الأحداث الماليّة، والثوابت بعد كلّ خطوة.
     * البذرة ثابتة فالفشل يُعاد إنتاجه: يُطبع التسلسل عند أوّل خرق.
     */
    public function test_random_event_sequences_preserve_the_money_invariants(): void
    {
        $events = ['capture', 'capture_dup', 'capture_other', 'refund_ok', 'refund_reject', 'refund_timeout', 'refund_pending', 'settle_ok', 'settle_fail', 'refund_partial', 'reconcile'];
        $service = app(RefundService::class);

        for ($run = 0; $run < 12; $run++) {
            mt_srand(1000 + $run);
            $order = $this->awaitingOrder('TD-MM-R'.$run);
            $trace = [];
            $pendingRefund = null;

            for ($step = 0; $step < 14; $step++) {
                $event = $events[mt_rand(0, count($events) - 1)];
                $trace[] = $event;
                $fresh = $order->fresh();

                try {
                    switch ($event) {
                        case 'capture':
                            $this->capture($order, 'chg_'.$run)->assertOk();
                            break;
                        case 'capture_dup':
                            $this->capture($order, 'chg_'.$run)->assertOk();
                            break;
                        case 'capture_other':
                            // رسمٌ ثانٍ صالح على الطلب نفسه: يُسجَّل ولا يمنح استحقاقًا ثانيًا.
                            $this->capture($order, 'chg_'.$run.'_b')->assertOk();
                            break;
                        case 'refund_ok':
                        case 'refund_partial':
                            config()->set('services.fake.refund_mode', 'succeed');
                            $amount = $event === 'refund_partial' ? 10000 : 40000;
                            $service->refund($fresh, $amount, 'x', RevocationPolicy::Immediate, "k{$run}-{$step}");
                            break;
                        case 'refund_reject':
                            config()->set('services.fake.refund_mode', 'reject');
                            $service->refund($fresh, 40000, 'x', RevocationPolicy::Immediate, "k{$run}-{$step}");
                            break;
                        case 'refund_timeout':
                            config()->set('services.fake.refund_mode', 'timeout');
                            $service->refund($fresh, 20000, 'x', RevocationPolicy::Immediate, "k{$run}-{$step}");
                            break;
                        case 'refund_pending':
                            config()->set('services.fake.refund_mode', 'pending');
                            $pendingRefund = $service->refund($fresh, 40000, 'x', RevocationPolicy::Immediate, "k{$run}-{$step}");
                            break;
                        case 'settle_ok':
                        case 'settle_fail':
                            if ($pendingRefund !== null) {
                                $this->refundWebhook($pendingRefund->provider_refund_id, 'chg_'.$run, $event === 'settle_ok' ? 'REFUNDED' : 'FAILED')->assertOk();
                            }
                            break;
                        case 'reconcile':
                            app(Reconciliation::class)->run();
                            break;
                    }
                } catch (RefundExceedsCaptured|RefundFailed|RefundOutcomeUnknown) {
                    // نتائج مشروعة للحدث؛ الثوابت هي ما يُفحص.
                }

                $this->assertInvariants($order->fresh(), $run, $trace);
            }
        }
    }

    /** @param  list<string>  $trace */
    private function assertInvariants(Order $order, int $run, array $trace): void
    {
        $why = 'run '.$run.' · '.implode(' → ', $trace);
        $total = (int) $order->total_minor;
        $refunded = (int) $order->refunded_total_minor;
        $reserved = (int) $order->refund_reserved_minor;

        // I1
        $this->assertGreaterThanOrEqual(0, $refunded, $why);
        $this->assertGreaterThanOrEqual(0, $reserved, $why);
        $this->assertLessThanOrEqual($total, $refunded + $reserved, 'I1 '.$why);

        // I2 — رسمٌ واحد صفٌّ واحد؛ ومفتاحُ استردادٍ واحد نداءٌ… (يُفحص في اختبار الإعادة)
        $charges = Payment::query()->where('order_id', $order->id)->where('state', 'CAPTURED')->pluck('intent_id');
        $this->assertSame($charges->count(), $charges->unique()->count(), 'I2 '.$why);
        $this->assertLessThanOrEqual(1, Entitlement::query()->where('order_id', $order->id)->count(), 'I2 '.$why);

        // I4 — المحسوم يحكم الحالة
        $settled = (int) Refund::query()->where('order_id', $order->id)->whereIn('status', Refund::SETTLED)->sum('amount_minor');
        $this->assertSame($settled, $refunded, 'I4 settled≠refunded '.$why);
        if ($refunded === 0) {
            $this->assertNotContains($order->status, ['REFUNDED', 'PARTIALLY_REFUNDED'], 'I4 '.$why);
        } elseif ($refunded >= $total) {
            $this->assertSame('REFUNDED', $order->status, 'I4 '.$why);
        } else {
            $this->assertSame('PARTIALLY_REFUNDED', $order->status, 'I4 '.$why);
        }

        // I3 — الاستحقاق نشط ⇔ مموَّل ولم يُردّ كاملًا (سياسة فوريّة)
        $entitlement = Entitlement::query()->where('order_id', $order->id)->first();
        $funded = OrderStatus::wasEverCollected($order->status);
        if ($entitlement !== null) {
            $this->assertTrue($funded, 'I3 استحقاقٌ بلا تمويل '.$why);
            $this->assertSame($refunded >= $total ? 'REVOKED' : 'ACTIVE', $entitlement->status, 'I3 '.$why);
        } else {
            $this->assertFalse($funded, 'I3 تمويلٌ بلا استحقاق '.$why);
        }

        // I5
        $completed = OutboxEvent::query()->where('event_type', 'refund.completed')->where('aggregate_type', 'refund')
            ->get()->filter(fn ($e) => Refund::query()->whereKey((int) $e->aggregate_id)->where('order_id', $order->id)->exists())->count();
        $this->assertSame(Refund::query()->where('order_id', $order->id)->whereIn('status', Refund::SETTLED)->count(), $completed, 'I5 '.$why);
    }
}
