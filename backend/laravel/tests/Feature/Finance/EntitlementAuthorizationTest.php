<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Entitlements\EntitlementNotAuthorized;
use App\Domain\Entitlements\EntitlementService;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ثوابت الاستحقاق (§٧١): لا استحقاق بلا إذنٍ تجاريّ.
 *
 * ─── العيب ───
 *
 * `grantForItem` كان يشترط معاملةً وعميلًا، ولا يسأل **هل دُفع الطلب**.
 * فأيّ مستدعٍ — مهمّة، أو عمليّة جماعيّة، أو فعلٌ إداريّ، أو نقطة نهاية
 * جديدة — يمنح المنتج مجّانًا. والاستحقاق الممنوح يُنزَّل فورًا: الملفّ يخرج
 * ولا يعود.
 *
 * السياسة كانت عند المستدعي لا عند البدائيّة — وهو النمط نفسه الذي سمح
 * بتجاوز سقف الاسترداد.
 */
class EntitlementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
    }

    private function order(string $status): Order
    {
        $customer = DB::table('customers')->insertGetId([
            'email' => 'c-'.uniqid().'@b.test', 'name' => 'عميل',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Order::create([
            'number' => 'TD-E-'.uniqid(),
            'email' => 'a@b.test',
            'customer_id' => $customer,
            // الحالة والمبلغ المستردّ يتّفقان — قيدُ المحرّك يرفض «مستردّ جزئيًّا» بلا مبلغ.
            'refunded_total_minor' => match ($status) {
                'PARTIALLY_REFUNDED' => 5000, 'REFUNDED' => 10000, default => 0
            },
            'status' => $status,
            'totals_gross_minor' => 10000,
            'total_minor' => 10000,
            'order_key_hash' => hash('sha256', uniqid()),
        ]);
    }

    private function item(Order $order): OrderItem
    {
        // قاعدة الاختبار تُبنى فارغة — المنتج يُنشأ هنا لا يُفترض وجوده.
        $n = uniqid();
        $product = DB::table('products')->insertGetId([
            'sku' => "P-{$n}", 'slug' => "p-{$n}", 'title' => 'منتج',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product,
            'title_snapshot' => 'منتج',
            'price_snapshot_minor' => 10000,
            'quantity' => 1,
        ]);
    }

    public function test_an_unpaid_order_cannot_be_granted_an_entitlement(): void
    {
        $order = $this->order('AWAITING_PAYMENT');
        $item = $this->item($order);

        /*
         * هذا هو التسريب: منتجٌ يُسلَّم قبل أن يصل مال. والملفّ الرقميّ حين
         * يخرج لا يعود — لا استردادَ يُصلح تنزيلًا وقع.
         */
        $this->expectException(EntitlementNotAuthorized::class);

        DB::transaction(fn () => $this->entitlements->grantForItem($order, $item));
    }

    public function test_a_paid_order_is_granted_normally(): void
    {
        $order = $this->order('PAID');
        $item = $this->item($order);

        $entitlement = DB::transaction(fn () => $this->entitlements->grantForItem($order, $item));

        $this->assertSame('ACTIVE', $entitlement->status);
        $this->assertSame($order->id, $entitlement->order_id);
    }

    public function test_a_partially_refunded_order_keeps_its_authorization(): void
    {
        $order = $this->order('PARTIALLY_REFUNDED');
        $item = $this->item($order);

        // مالٌ وصل ثمّ رُدّ بعضُه — الإذن قائم، والسحب قرارُ سياسةٍ منفصل.
        $entitlement = DB::transaction(fn () => $this->entitlements->grantForItem($order, $item));

        $this->assertSame('ACTIVE', $entitlement->status);
    }

    public function test_a_cancelled_order_cannot_be_granted(): void
    {
        $order = $this->order('CANCELLED');
        $item = $this->item($order);

        $this->expectException(EntitlementNotAuthorized::class);
        DB::transaction(fn () => $this->entitlements->grantForItem($order, $item));
    }

    public function test_granting_for_a_whole_unpaid_order_is_refused_before_any_row(): void
    {
        $order = $this->order('AWAITING_PAYMENT');
        $this->item($order);
        $this->item($order);

        try {
            DB::transaction(fn () => $this->entitlements->grantForOrder($order->fresh('items')));
            $this->fail('مُنح استحقاقٌ لطلبٍ غير مدفوع.');
        } catch (EntitlementNotAuthorized) {
            // لا صفّ نصفيّ: الرفض قبل أوّل كتابة.
            $this->assertSame(0, Entitlement::query()->where('order_id', $order->id)->count());
        }
    }

    public function test_the_exception_names_the_order_and_its_status(): void
    {
        $order = $this->order('AWAITING_PAYMENT');
        $item = $this->item($order);

        try {
            DB::transaction(fn () => $this->entitlements->grantForItem($order, $item));
            $this->fail('لم يُرفض.');
        } catch (EntitlementNotAuthorized $e) {
            // المشغّل يقرأ **لماذا** رُفض لا أنّه رُفض.
            $this->assertSame($order->id, $e->orderId);
            $this->assertSame('AWAITING_PAYMENT', $e->orderStatus);
        }
    }
}
