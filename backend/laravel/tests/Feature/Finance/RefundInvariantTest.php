<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Orders\RefundExceedsCaptured;
use App\Domain\Orders\RefundService;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ثوابت الاسترداد (§٧٠): مجموع المستردّ لا يتجاوز المحصَّل أبدًا.
 *
 * ─── لماذا لا يكفي السقف في وحدة التحكّم ───
 *
 * كان الحدّ يُحسب في `OrderController` ثمّ يُمرَّر إلى التحقّق. وثلاث طرقٍ
 * تتجاوزه:
 *   · نداءٌ من خدمةٍ أخرى أو مهمّةٍ أو عمليّةٍ جماعيّة لا يمرّ بوحدة التحكّم.
 *   · طلبان متزامنان يقرآن الحدّ نفسه قبل أن يكتب أحدهما.
 *   · إعادة إرسالٍ بعد ضياع الردّ تُنشئ استردادًا ثانيًا.
 *
 * والمال المستردّ زيادةً لا يُستعاد بزرّ: يخرج من الحساب فعلًا.
 */
class RefundInvariantTest extends TestCase
{
    use RefreshDatabase;

    private RefundService $refunds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refunds = app(RefundService::class);
    }

    private function order(int $total = 10000, string $status = 'PAID'): Order
    {
        return Order::create([
            'number' => 'TD-R-'.uniqid(),
            'email' => 'a@b.test',
            'status' => $status,
            'totals_gross_minor' => $total,
            'total_minor' => $total,
            'order_key_hash' => hash('sha256', uniqid()),
        ]);
    }

    public function test_the_service_refuses_to_refund_more_than_the_order_total(): void
    {
        $order = $this->order(10000);
        $this->refunds->refund($order, 8000, 'جزئيّ', RevocationPolicy::Keep);

        /*
         * الحدّ ملكُ النطاق لا وحدة التحكّم: أيّ مستدعٍ آخر — مهمّة، عمليّة
         * جماعيّة، نقطة نهاية جديدة — كان يتجاوزه كاملًا.
         */
        $this->expectException(RefundExceedsCaptured::class);
        $this->refunds->refund($order->refresh(), 5000, 'زائد', RevocationPolicy::Keep);
    }

    public function test_the_database_refuses_an_over_refund_even_by_raw_write(): void
    {
        $order = $this->order(10000);
        $this->refunds->refund($order, 10000, 'كامل', RevocationPolicy::Keep);

        // القيد في المحرّك لا في الشيفرة: تجاوزٌ كهذا مستحيلٌ بنيويًّا.
        $this->expectException(QueryException::class);
        DB::table('orders')->where('id', $order->id)->update(['refunded_total_minor' => 10001]);
    }

    public function test_partial_refunds_that_sum_to_the_total_close_the_order(): void
    {
        $order = $this->order(10000);

        $this->refunds->refund($order, 6000, 'أوّل', RevocationPolicy::Keep);
        $this->refunds->refund($order->refresh(), 4000, 'ثانٍ', RevocationPolicy::Keep);

        /*
         * الحالة كانت تُشتقّ من **مبلغ الاسترداد الواحد** لا من المجموع، فيبقى
         * طلبٌ استُرد كاملًا معلَّمًا «مستردّ جزئيًّا» إلى الأبد — فيُحسب في
         * تقارير المال خطأً ويُعرض للاسترداد من جديد.
         */
        $this->assertSame('REFUNDED', $order->refresh()->status);
        $this->assertSame(10000, (int) $order->refresh()->refunded_total_minor);
    }

    public function test_a_resent_refund_creates_one_refund_only(): void
    {
        $order = $this->order(10000);

        $first = $this->refunds->refund($order, 3000, 'شكوى', RevocationPolicy::Keep, 'ref-fixed');
        $second = $this->refunds->refund($order->refresh(), 3000, 'شكوى', RevocationPolicy::Keep, 'ref-fixed');

        // المال لا يخرج مرّتين لأنّ ردًّا ضاع في الشبكة.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('refunds')->where('order_id', $order->id)->count());
        $this->assertSame(3000, (int) $order->refresh()->refunded_total_minor);
    }

    public function test_a_zero_refund_is_refused(): void
    {
        $order = $this->order(10000);

        $this->expectException(RefundExceedsCaptured::class);
        $this->refunds->refund($order, 0, 'صفر', RevocationPolicy::Keep);
    }

    public function test_an_unpaid_order_cannot_be_refunded(): void
    {
        $order = $this->order(10000, 'AWAITING_PAYMENT');

        // لا مال محصَّل ⇦ لا مال يُردّ. الاسترداد هنا خروجٌ صافٍ من الحساب.
        $this->expectException(RefundExceedsCaptured::class);
        $this->refunds->refund($order, 1000, 'بلا دفع', RevocationPolicy::Keep);
    }
}
