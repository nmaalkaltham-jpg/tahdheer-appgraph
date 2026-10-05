<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * سجلّ المدفوعات والمستردّات.
 *
 * أخطر ما تكشفه هذه الشاشة تناقضٌ لا يُرى في أيّ رقمٍ آخر: دفعةٌ حُصّلت
 * وطلبُها غير مدفوع. الطلبات تبدو سليمة، والمدفوعات تبدو سليمة، والتناقض
 * بينهما وحده — ولا يُصدر عطلًا ولا شكوى حتّى يكتشفه الزبون.
 */
class PaymentLedgerTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function order(string $status = 'PAID', string $number = 'TD-P-1'): Order
    {
        return Order::create([
            'number' => $number, 'email' => 'a@b.test', 'status' => $status,
            'totals_gross_minor' => 10000, 'total_minor' => 10000, 'order_key_hash' => hash('sha256', $number),
        ]);
    }

    private function payment(Order $order, string $state = 'CAPTURED'): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'provider' => 'fake',
            'intent_id' => 'chg_'.uniqid(), 'method' => 'card',
            'masked_instrument' => '•••• 4242', 'state' => $state,
            'idempotency_key' => 'fake:'.uniqid(),
        ]);
    }

    // ── الصلاحيات ────────────────────────────────────────────────────────

    public function test_the_ledger_requires_order_read(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::SeoManager));
        $this->getJson('/api/v1/admin/payments/transactions')->assertStatus(403);
    }

    // ── القائمة ──────────────────────────────────────────────────────────

    public function test_transactions_carry_the_order_they_belong_to(): void
    {
        $order = $this->order();
        $this->payment($order);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/payments/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.orderNumber', 'TD-P-1')
            ->assertJsonPath('data.0.orderStatus', 'PAID')
            ->assertJsonPath('data.0.instrument', '•••• 4242');
    }

    public function test_the_search_matches_the_order_number(): void
    {
        $this->payment($this->order(number: 'TD-FIND-ME'));
        $this->payment($this->order(number: 'TD-OTHER'));

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // المشغّل يبدأ من رقم الطلب لا من معرّف البوّابة.
        $this->getJson('/api/v1/admin/payments/transactions?q=FIND-ME')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.orderNumber', 'TD-FIND-ME');
    }

    public function test_the_state_filter_narrows_the_list(): void
    {
        $this->payment($this->order(number: 'TD-A'), 'CAPTURED');
        $this->payment($this->order(number: 'TD-B'), 'FAILED');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/payments/transactions?state=FAILED')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.state', 'FAILED');
    }

    // ── الملخّص ──────────────────────────────────────────────────────────

    public function test_an_empty_ledger_reports_no_failure_rate_instead_of_zero(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        /*
         * صفرٌ هنا يُقرأ «لا فشل» وهو في الحقيقة «لا بيانات». الفرق يقرّر
         * هل يُحتفل بالرقم أم يُشكّ فيه.
         */
        $this->getJson('/api/v1/admin/payments/transactions')
            ->assertOk()
            ->assertJsonPath('summary.failureRate', null)
            ->assertJsonPath('summary.transactions', 0);
    }

    public function test_the_failure_rate_is_measured_when_there_is_data(): void
    {
        $this->payment($this->order(number: 'TD-1'), 'CAPTURED');
        $this->payment($this->order(number: 'TD-2'), 'FAILED');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/payments/transactions')
            ->assertOk()
            ->assertJsonPath('summary.failureRate', 0.5);
    }

    public function test_money_taken_for_an_unpaid_order_is_counted(): void
    {
        $this->payment($this->order('AWAITING_PAYMENT', 'TD-STUCK'), 'CAPTURED');
        $this->payment($this->order('PAID', 'TD-FINE'), 'CAPTURED');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        /*
         * مالٌ أُخذ من زبونٍ لم يستلم شيئًا. لا يُرى في أيّ رقمٍ آخر: كلا
         * الجدولين يبدو سليمًا، والتناقض بينهما وحده.
         */
        $this->getJson('/api/v1/admin/payments/transactions')
            ->assertOk()
            ->assertJsonPath('summary.capturedButUnpaidOrders', 1);
    }

    // ── التفصيل ──────────────────────────────────────────────────────────

    public function test_the_detail_scrubs_the_provider_payload(): void
    {
        $payment = $this->payment($this->order());
        PaymentEvent::create([
            'payment_id' => $payment->id,
            'event_type' => 'charge.captured',
            'payload' => [
                'status' => 'CAPTURED',
                'amount' => 10000,
                'card_number' => '4242424242424242',
                'customer_secret' => 'sk_live_abc',
            ],
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $response = $this->getJson("/api/v1/admin/payments/transactions/{$payment->id}")->assertOk();

        $payload = $response->json('data.events.0.payload');

        // قائمة سماحٍ لا منع: المزوّد يضيف حقولًا بلا إعلامنا.
        $this->assertSame('CAPTURED', $payload['status']);
        $this->assertArrayNotHasKey('card_number', $payload);
        $this->assertArrayNotHasKey('customer_secret', $payload);
    }

    public function test_a_missing_transaction_is_404(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->getJson('/api/v1/admin/payments/transactions/999999')->assertStatus(404);
    }

    // ── المستردّات ───────────────────────────────────────────────────────

    public function test_refunds_report_the_unnotified_count(): void
    {
        $order = $this->order();

        DB::table('refunds')->insert([
            ['order_id' => $order->id, 'amount_minor' => 5000, 'reason' => 'تلف',
                'revocation_policy' => 'REVOKE', 'notified_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $order->id, 'amount_minor' => 2500, 'reason' => 'جزئيّ',
                'revocation_policy' => 'KEEP', 'notified_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        /*
         * مستردٌّ لم يُبلَّغ به العميل يُنتج شكوى «أين مالي؟» عن مشكلةٍ حُلّت
         * سلفًا — وهو أسوأ أنواع الشكاوى لأنّه يبدو إهمالًا.
         */
        $this->getJson('/api/v1/admin/payments/refunds')
            ->assertOk()
            ->assertJsonPath('summary.refunds', 2)
            ->assertJsonPath('summary.totalMinor', 7500)
            ->assertJsonPath('summary.unnotified', 1)
            ->assertJsonPath('data.0.orderNumber', 'TD-P-1');
    }
}
