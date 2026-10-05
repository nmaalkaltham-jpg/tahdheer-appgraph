<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Orders\RefundExceedsCaptured;
use App\Domain\Orders\RefundService;
use App\Models\Order;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * برهان التزامن على الاسترداد — PostgreSQL حقيقيّ.
 *
 * التغليف مُعطَّل عمدًا: داخل معاملةٍ واحدة لا تزامنَ أصلًا، فاختبارٌ ينجح
 * هناك لا يُثبت شيئًا عن سباقٍ حقيقيّ على المال.
 */
class RefundConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $orderIds = [];

    protected function beginDatabaseTransaction(): void
    {
        // عمدًا فارغة — الصفوف تُلتزم فعلًا فيراها اتّصالٌ ثانٍ.
    }

    protected function tearDown(): void
    {
        if ($this->orderIds !== []) {
            DB::table('refunds')->whereIn('order_id', $this->orderIds)->delete();
            DB::table('outbox_events')->where('aggregate_type', 'refund')->delete();
            DB::table('orders')->whereIn('id', $this->orderIds)->delete();
            $this->orderIds = [];
        }

        parent::tearDown();
    }

    private function order(int $total = 10000): Order
    {
        $order = Order::create([
            'number' => 'TD-C-'.uniqid(),
            'email' => 'a@b.test',
            'status' => 'PAID',
            'totals_gross_minor' => $total,
            'total_minor' => $total,
            'order_key_hash' => hash('sha256', uniqid()),
        ]);

        $this->orderIds[] = $order->id;

        return $order;
    }

    private function secondConnection(): Connection
    {
        config(['database.connections.concurrent' => config('database.connections.pgsql')]);
        DB::purge('concurrent');

        $second = DB::connection('concurrent');
        $second->statement("SET lock_timeout = '2s'");

        return $second;
    }

    /**
     * البرهان من جهة الخدمة لا من جهة الاختبار.
     *
     * الصيغة السابقة كانت تقفل الصفّ **بيد الاختبار** ثمّ تتحقّق أنّ اتّصالًا
     * ثانيًا يُمنع — فتنجح ولو حُذف القفل من `RefundService` كلّه (ثبت ذلك
     * بطفرةٍ: حُذف القفل ونجحت). هنا يُقفل الصفّ من اتّصالٍ ثانٍ، ويُنادى
     * الاسترداد الحقيقيّ بمهلة قفلٍ قصيرة: الخدمة تنتظر الصفّ ولا تكتب
     * استردادًا فوقه.
     *
     * والسقف نفسه لا يعتمد على هذا القفل: يُفرض في تحديثٍ شرطيّ واحد
     * (`RefundService::reserve`) وفي قيد المحرّك — انظر `RefundInvariantTest`.
     */
    public function test_the_refund_service_waits_for_the_order_row_lock(): void
    {
        $order = $this->order();
        $second = $this->secondConnection();

        $second->beginTransaction();
        $second->select('select id from orders where id = ? for update', [$order->id]);

        DB::statement("SET lock_timeout = '1s'");

        try {
            app(RefundService::class)->refund($order, 1000, 'x', RevocationPolicy::Keep, 'lock:'.uniqid());
            $this->fail('الاسترداد مرّ وصفُّ الطلب مقفولٌ من اتّصالٍ آخر — الخدمة لا تقفل.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('lock timeout', $e->getMessage());
        } finally {
            DB::statement('SET lock_timeout = 0');
            $second->rollBack();
        }

        $this->assertSame(0, DB::table('refunds')->where('order_id', $order->id)->count());
    }

    public function test_two_refunds_cannot_together_exceed_the_captured_total(): void
    {
        $order = $this->order(10000);
        $service = app(RefundService::class);

        $service->refund($order, 7000, 'أوّل', RevocationPolicy::Keep);

        /*
         * الثاني يقرأ حالةً تغيّرت. بلا القفل والتحقّق في النطاق كان الاثنان
         * يمرّان معًا فيخرج من الحساب ١٣٠٠٠ عن طلبٍ قيمتُه ١٠٠٠٠.
         */
        try {
            $service->refund($order->refresh(), 6000, 'ثانٍ', RevocationPolicy::Keep);
            $this->fail('التجاوز مرّ — الثابت الماليّ مكسور.');
        } catch (RefundExceedsCaptured $e) {
            $this->assertSame(3000, $e->remainingMinor());
        }

        $this->assertSame(7000, (int) DB::table('orders')->where('id', $order->id)->value('refunded_total_minor'));
    }

    public function test_the_engine_itself_refuses_an_over_refund_from_a_second_connection(): void
    {
        $order = $this->order(10000);
        app(RefundService::class)->refund($order, 9000, 'أوّل', RevocationPolicy::Keep);

        // القيد يعيش في المحرّك: لا اتّصال ولا شيفرة تتجاوزه.
        $this->expectException(QueryException::class);
        $this->secondConnection()
            ->table('orders')->where('id', $order->id)
            ->update(['refunded_total_minor' => 12000]);
    }
}
