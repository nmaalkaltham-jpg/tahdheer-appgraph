<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Entitlements\EntitlementService;
use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Inventory\InventoryService;
use App\Domain\Orders\Models\Refund;
use App\Domain\Orders\RefundService;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Payments\ProviderRegistry;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdminOrdersTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    /** A paid order with one item and one live entitlement. */
    private function paidOrder(string $number, int $totalMinor = 40000): Order
    {
        $product = $this->makeReadyProduct('SKU-'.$number);

        $customer = Customer::query()->create([
            'email' => strtolower($number).'@example.com', 'name' => 'نورة العتيبي',
        ]);

        $order = Order::query()->create([
            'number' => $number, 'customer_id' => $customer->id, 'email' => $customer->email,
            'status' => 'PAID', 'totals_gross_minor' => $totalMinor, 'total_minor' => $totalMinor,
            'vat_minor' => (int) round($totalMinor * 15 / 115), 'paid_at' => now(),
        ]);

        $item = OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'title_snapshot' => $product->title, 'price_snapshot_minor' => $totalMinor,
        ]);

        Entitlement::query()->create([
            'customer_id' => $customer->id, 'order_id' => $order->id, 'order_item_id' => $item->id,
            'product_id' => $product->id, 'granted_at' => now(), 'status' => 'ACTIVE',
        ]);

        return $order;
    }

    public function test_empty_order_book_is_an_explicit_empty_list(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->getJson('/api/v1/admin/orders')
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_list_and_detail_expose_camelcase_money_in_minor_units(): void
    {
        $order = $this->paidOrder('TD-O-1');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->getJson('/api/v1/admin/orders?status=PAID')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'TD-O-1')
            ->assertJsonPath('data.0.totalMinor', 40000)
            ->assertJsonPath('data.0.vatMinor', 5217);

        $this->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.refundedMinor', 0)
            ->assertJsonPath('data.refundableMinor', 40000)
            ->assertJsonCount(1, 'data.entitlements')
            ->assertJsonPath('data.entitlements.0.status', 'ACTIVE');
    }

    /**
     * The endpoint must DELEGATE: the partial mock proves RefundService::refund
     * is the single writer, and passthru() keeps its real side effects so the
     * refund row, status transition, revocation and outbox entry are asserted.
     */
    public function test_refund_delegates_to_the_existing_refund_service(): void
    {
        $order = $this->paidOrder('TD-O-2');

        $service = Mockery::mock(
            RefundService::class,
            [
                app(EntitlementService::class),
                app(OutboxWriter::class),
                /* السحب صار يمرّ بالمنفّذين، فالخدمة تحتاج المنسّق. */
                app(FulfillmentOrchestrator::class),
                /* والإفراج عن حجزٍ لم يُستهلك يحتاج خدمة المخزون. */
                app(InventoryService::class),
                /* والتنفيذ عند المزوّد يحتاج سجلّ المزوّدين. */
                app(ProviderRegistry::class),
            ],
        )->makePartial();
        $service->shouldReceive('refund')
            ->once()
            ->withArgs(fn (Order $o, int $amount, ?string $reason, RevocationPolicy $policy) => $o->id === $order->id
                && $amount === 40000
                && $reason === 'ملف تالف'
                && $policy === RevocationPolicy::Immediate)
            ->passthru();
        $this->instance(RefundService::class, $service);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amountMinor' => 40000, 'reason' => 'ملف تالف', 'policy' => 'immediate', 'idempotencyKey' => 'refund-delegate:aaaa1111',
        ])
            ->assertCreated()
            ->assertJsonPath('data.amountMinor', 40000)
            ->assertJsonPath('data.policy', 'immediate')
            ->assertJsonPath('data.orderStatus', 'REFUNDED');

        $this->assertDatabaseHas('refunds', ['order_id' => $order->id, 'amount_minor' => 40000]);
        $this->assertSame('REVOKED', Entitlement::query()->where('order_id', $order->id)->firstOrFail()->status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'refund.completed']);
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'order.refunded', 'reason' => 'ملف تالف']);
    }

    /**
     * إعادةُ الإرسال بالمفتاح نفسه لا تُخرج المال مرّتين.
     *
     * الحال التي تقع ليست نقرةً مزدوجة — الزرّ معطَّل أثناء التنفيذ — بل
     * ردٌّ يضيع في الشبكة أو مهلةٌ عند الوكيل: يرى المشغّل فشلًا فيعيد، وقد
     * نجح الأوّل. وكانت اللوحة لا ترسل مفتاحًا أصلًا، و`RefundService` لا
     * يُبطل التكرار إلّا حين يجده — فلا شيء يمنع استردادين (قاعدة ٣).
     */
    public function test_the_same_idempotency_key_refunds_once(): void
    {
        $order = $this->paidOrder('TD-O-IDEM');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $body = ['amountMinor' => 15000, 'policy' => 'keep', 'idempotencyKey' => 'refund-tab-1:abcd1234'];

        $first = $this->postJson("/api/v1/admin/orders/{$order->id}/refund", $body)->assertCreated();
        $second = $this->postJson("/api/v1/admin/orders/{$order->id}/refund", $body)->assertCreated();

        /* الردّ الثاني هو الأوّل نفسه لا استردادٌ جديد. */
        $this->assertSame(
            $first->json('data.refundId'),
            $second->json('data.refundId'),
            'الإعادة بالمفتاح نفسه يجب أن تُعيد الاسترداد الأوّل لا أن تُنشئ ثانيًا.',
        );
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
        $this->assertSame(15000, (int) $order->fresh()->refunded_total_minor);
    }

    /** ومبلغٌ مُصحَّح مفتاحٌ جديد — وهو استردادٌ آخر بحقّ لا تكرارٌ يُبتلَع. */
    public function test_a_different_key_is_a_second_refund(): void
    {
        $order = $this->paidOrder('TD-O-IDEM2');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amountMinor' => 10000, 'policy' => 'keep', 'idempotencyKey' => 'refund-tab-1:aaaa1111',
        ])->assertCreated();

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amountMinor' => 5000, 'policy' => 'keep', 'idempotencyKey' => 'refund-tab-1:bbbb2222',
        ])->assertCreated();

        $this->assertSame(2, Refund::query()->where('order_id', $order->id)->count());
        $this->assertSame(15000, (int) $order->fresh()->refunded_total_minor);
    }

    public function test_partial_refund_then_over_refund_is_capped_by_the_remaining_amount(): void
    {
        $order = $this->paidOrder('TD-O-3');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amountMinor' => 15000, 'policy' => 'keep', 'idempotencyKey' => 'refund-over:aaaa1111',
        ])->assertCreated()->assertJsonPath('data.orderStatus', 'PARTIALLY_REFUNDED');

        $this->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.refundedMinor', 15000)
            ->assertJsonPath('data.refundableMinor', 25000);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amountMinor' => 25001, 'policy' => 'keep',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.amountMinor.0', 'المبلغ يتجاوز المتبقي القابل للاسترداد (25000 هللة).');

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_unpaid_order_cannot_be_refunded(): void
    {
        $order = Order::query()->create([
            'number' => 'TD-O-4', 'email' => 'x@example.com',
            'status' => 'AWAITING_PAYMENT', 'total_minor' => 40000,
        ]);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amountMinor' => 100, 'policy' => 'immediate',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ORDER_NOT_REFUNDABLE');

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_unknown_revocation_policy_is_rejected_in_arabic(): void
    {
        $order = $this->paidOrder('TD-O-5');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amountMinor' => 100, 'policy' => 'delete-everything',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.policy.0', 'القيمة المُرسلة غير مسموح بها.');

        $this->assertDatabaseCount('refunds', 0);
    }

    // ── الفرز والعدّ في الخادم ───────────────────────────────────────────

    /**
     * الفرز يقع في الخادم لا في الصفحة.
     *
     * جدولٌ يفرز الصفوف المحمّلة وحدها يقول «الأعلى» وهو أعلى عشرين صفًّا
     * لا أعلى الجميع — وهو رقمٌ يبدو صحيحًا ويُبنى عليه قرار.
     */
    public function test_orders_sort_by_an_allowed_column(): void
    {
        $this->paidOrder('TD-S1', 10000);
        $this->paidOrder('TD-S2', 90000);
        $this->paidOrder('TD-S3', 50000);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/orders?sort=total&dir=desc')
            ->assertOk()
            ->assertJsonPath('data.0.number', 'TD-S2')
            ->assertJsonPath('data.2.number', 'TD-S1');

        $this->getJson('/api/v1/admin/orders?sort=total&dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.number', 'TD-S1');
    }

    /**
     * عمودٌ خارج القائمة البيضاء يُرفض.
     *
     * تمريرُ الاسم كما ورد يفتح الباب لعمودٍ لا وجود له — خطأُ خادمٍ يُعرض
     * للمستعمل — ولعمودٍ لا يجوز الفرز به.
     */
    public function test_an_unknown_sort_column_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/orders?sort=order_key_hash')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['sort']]);
    }

    /**
     * عدّ الحالات يُحسب **دون** مرشّح الحالة.
     *
     * وإلّا لَأظهر تبويبُ «مدفوع» رقمه وحده وأصفارًا في البقيّة، فيبدو
     * المتجر بلا طلباتٍ في الحالات الأخرى بينما هي مُخفاةٌ بالمرشّح.
     */
    public function test_status_counts_ignore_the_status_filter(): void
    {
        $this->paidOrder('TD-C1');
        $cancelled = $this->paidOrder('TD-C2');
        $cancelled->update(['status' => 'CANCELLED']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/orders?status=PAID')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('statusCounts.PAID', 1)
            ->assertJsonPath('statusCounts.CANCELLED', 1);
    }

    /**
     * الفرز بعمودٍ متكرّر القيم لا يُنتج ترتيبًا غير محدَّد.
     *
     * بلا قاطعٍ ثانويّ يجوز لـPostgreSQL أن يعيد الصفّ نفسه في صفحتين —
     * ويُخفي غيره بينهما. القاطع هو `id`.
     */
    public function test_sorting_by_a_repeated_column_is_still_deterministic(): void
    {
        foreach (['TD-D1', 'TD-D2', 'TD-D3'] as $number) {
            $this->paidOrder($number);
        }

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $first = $this->getJson('/api/v1/admin/orders?sort=status&dir=asc&perPage=2&page=1')->json('data');
        $second = $this->getJson('/api/v1/admin/orders?sort=status&dir=asc&perPage=2&page=2')->json('data');

        $numbers = array_merge(array_column($first, 'number'), array_column($second, 'number'));

        $this->assertSame(3, count(array_unique($numbers)), 'صفٌّ تكرّر بين صفحتين.');
    }
}
