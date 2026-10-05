<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\ReturnTransitionService;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\ShipmentTransitionService;
use App\Models\Order;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PDOException;
use Tests\TestCase;

/**
 * برهان التزامن على PostgreSQL حقيقيّ.
 *
 * ─── لماذا لا تصلح الاختبارات المعتادة ───
 *
 * `RefreshDatabase` يلفّ كلّ اختبارٍ في معاملةٍ تُلغى بعده. وداخل معاملةٍ
 * واحدة لا يوجد تزامنٌ أصلًا: لا اتّصال ثانٍ يرى الصفّ، ولا قفلٌ يتنازع
 * عليه أحد. فاختبارٌ ينجح هناك لا يُثبت شيئًا عن سباقٍ حقيقيّ.
 *
 * ولذلك يُعطَّل التغليف هنا عمدًا (‎beginDatabaseTransaction‎ فارغة): الصفوف
 * تُلتزم فعلًا فيراها اتّصالٌ ثانٍ، ويُنظَّف ما أُنشئ في `tearDown`.
 *
 * ─── كيف يُثبَت القفل بلا تعليق الاختبار ───
 *
 * `FOR UPDATE NOWAIT` على الاتّصال الثاني: إن كان القفل محجوزًا رُمي فورًا
 * بدل الانتظار. فالنجاح في الرمي **هو** الدليل، والاختبار لا يتجمّد.
 */
class TransitionLockingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $orderIds = [];

    /** @var list<int> */
    private array $actorIds = [];

    /**
     * لا معاملة تغليف: التزامن يحتاج التزامًا حقيقيًّا.
     */
    protected function beginDatabaseTransaction(): void
    {
        // عمدًا فارغة — انظر شرح الصنف.
    }

    protected function tearDown(): void
    {
        if ($this->orderIds !== []) {
            DB::table('shipments')->whereIn('order_id', $this->orderIds)->delete();
            DB::table('return_requests')->whereIn('order_id', $this->orderIds)->delete();
            DB::table('outbox_events')->where('aggregate_type', 'shipment')->delete();
            DB::table('outbox_events')->where('aggregate_type', 'return_request')->delete();
            DB::table('admin_audit_log')->whereIn('entity_type', ['shipment', 'return_request'])->delete();
            DB::table('orders')->whereIn('id', $this->orderIds)->delete();
            $this->orderIds = [];
        }

        /*
         * الفاعلون يُلتزمون كغيرهم. وتركُهم يُسقط
         * `Admin/AdminSeederTest::…assertSame(0, AdminUser::count())`
         * متى تغيّر ترتيب التشغيل — عطبٌ يظهر في CI ولا يظهر محلّيًّا.
         */
        if ($this->actorIds !== []) {
            DB::table('admin_users')->whereIn('id', $this->actorIds)->delete();
            $this->actorIds = [];
        }

        parent::tearDown();
    }

    /** اتّصالٌ ثانٍ مستقلّ إلى قاعدة الاختبار نفسها. */
    private function secondConnection(): Connection
    {
        config(['database.connections.concurrent' => config('database.connections.pgsql')]);
        DB::purge('concurrent');

        $second = DB::connection('concurrent');
        // لئلّا يتجمّد الاختبار لو تسلّل انتظارٌ من مكانٍ آخر.
        $second->statement("SET lock_timeout = '2s'");

        return $second;
    }

    private function actor(): AdminUser
    {
        return AdminUser::create([
            'email' => 'ops-'.uniqid().'@test.local',
            'name' => 'مشغّل',
            'password_hash' => Hash::make('x'),
            'role' => AdminRole::Owner->value,
        ]);
    }

    private function order(): Order
    {
        $order = Order::create([
            'number' => 'TD-'.uniqid(), 'email' => 'c@test.local',
            'status' => 'PAID', 'total_minor' => 5000,
        ]);
        $this->orderIds[] = $order->id;

        return $order;
    }

    private function shipment(string $status = 'PENDING', ?string $tracking = null): Shipment
    {
        return Shipment::create([
            'order_id' => $this->order()->id, 'status' => $status,
            'tracking_number' => $tracking, 'idempotency_key' => uniqid(),
        ]);
    }

    // ── البرهان المباشر: القفل محجوز فعلًا ──────────────────────────────

    public function test_the_row_lock_is_actually_held_against_a_second_connection(): void
    {
        $shipment = $this->shipment();
        $second = $this->secondConnection();

        DB::beginTransaction();

        try {
            // الاتّصال الأوّل يحجز الصفّ، كما تفعل خدمة الانتقال بالضبط.
            DB::table('shipments')->where('id', $shipment->id)->lockForUpdate()->first();

            $blocked = false;

            try {
                $second->select('SELECT id FROM shipments WHERE id = ? FOR UPDATE NOWAIT', [$shipment->id]);
            } catch (PDOException) {
                $blocked = true;
            }

            /*
             * هذا هو الدليل: اتّصالٌ ثانٍ لا يستطيع قراءةَ الصفّ للتعديل
             * ما دام الأوّل يحجزه. فالتسلسل «اقرأ ثمّ قرّر ثمّ اكتب» صار
             * مستحيلًا على متوازيَين.
             */
            $this->assertTrue($blocked, 'القفل غير محجوز — التسلسل ليس آمنًا');
        } finally {
            DB::rollBack();
        }

        // وبعد فكّ القفل يمرّ الاتّصال الثاني — ويُقرأ الصفّ نفسه لا مجرّد «لم يُرمَ».
        $after = $second->select('SELECT id FROM shipments WHERE id = ? FOR UPDATE NOWAIT', [$shipment->id]);
        $this->assertCount(1, $after);
        $this->assertSame($shipment->id, (int) $after[0]->id);
    }

    public function test_a_second_transition_sees_committed_state_and_is_refused(): void
    {
        $service = $this->app->make(ShipmentTransitionService::class);
        $actor = $this->actor();
        $shipment = $this->shipment('PENDING');

        $service->transition($shipment->id, 'LABEL_CREATED', null, $actor);

        /*
         * الطلب الثاني يصل بعد التزام الأوّل — وهو ما يفعله المتنازعُ الخاسر
         * عند فكّ القفل. يقرأ الحالة الجديدة فيُرفض صراحةً، لا ينجح على
         * حالةٍ قديمة كما كان يحدث قبل القفل.
         */
        $this->expectException(ValidationException::class);
        $service->transition($shipment->id, 'LABEL_CREATED', null, $actor);
    }

    public function test_two_returns_decisions_cannot_both_win(): void
    {
        $service = $this->app->make(ReturnTransitionService::class);
        $actor = $this->actor();

        $return = ReturnRequest::create([
            'number' => 'RMA-'.uniqid(), 'order_id' => $this->order()->id,
            'status' => 'REQUESTED', 'customer_reason' => 'لم يناسبني',
        ]);

        $service->transition($return->id, 'APPROVED', null, $actor);

        // القبول وقع؛ فالرفض من «REQUESTED» لم يعد ممكنًا.
        $this->expectException(ValidationException::class);
        $service->transition($return->id, 'REJECTED', 'مستعمل', $actor);
    }

    public function test_delivered_at_is_not_rewritten_by_a_repeated_arrival(): void
    {
        $service = $this->app->make(ShipmentTransitionService::class);
        $actor = $this->actor();
        $shipment = $this->shipment('IN_TRANSIT', 'TRK-1');

        $service->transition($shipment->id, 'DELIVERED', null, $actor);
        $first = $shipment->fresh()->delivered_at;

        // نداءٌ ثانٍ: الحالة نهائيّة فيُرفض — والختم لا يتزحزح.
        try {
            $service->transition($shipment->id, 'DELIVERED', null, $actor);
        } catch (ValidationException) {
            // متوقَّع
        }

        /*
         * لو كُتب الختم بلا شرط لأزاحه النداء الثاني إلى الآن، فبدت الشحنة
         * مُسلَّمةً متأخّرةً ساعاتٍ عن حقيقتها — ويفسد كلّ قياسٍ لزمن التسليم.
         */
        $this->assertEquals($first, $shipment->fresh()->delivered_at);
    }

    public function test_state_audit_and_outbox_commit_together(): void
    {
        $service = $this->app->make(ShipmentTransitionService::class);
        $actor = $this->actor();
        $shipment = $this->shipment('PENDING');

        $service->transition($shipment->id, 'LABEL_CREATED', null, $actor);

        $this->assertSame('LABEL_CREATED', $shipment->fresh()->status);
        $this->assertSame(1, DB::table('admin_audit_log')
            ->where('entity_type', 'shipment')->where('entity_id', $shipment->id)->count());
        $this->assertSame(1, DB::table('outbox_events')
            ->where('aggregate_type', 'shipment')->where('aggregate_id', (string) $shipment->id)->count());
    }

    public function test_a_failed_transition_leaves_no_audit_and_no_event(): void
    {
        $service = $this->app->make(ShipmentTransitionService::class);
        $actor = $this->actor();
        $shipment = $this->shipment('PENDING');

        try {
            // قفزةٌ ممنوعة: PENDING ⇸ DELIVERED
            $service->transition($shipment->id, 'DELIVERED', 'TRK-9', $actor);
        } catch (ValidationException) {
            // متوقَّع
        }

        /*
         * لو التزم التدقيق وسقطت الحالة لبقي في السجلّ «نجحت» لعمليّةٍ لم
         * تقع — وهو أسوأ من غياب السجلّ لأنّه يُصدَّق.
         */
        $this->assertSame('PENDING', $shipment->fresh()->status);
        $this->assertSame(0, DB::table('admin_audit_log')
            ->where('entity_type', 'shipment')->where('entity_id', $shipment->id)->count());
        $this->assertSame(0, DB::table('outbox_events')
            ->where('aggregate_id', (string) $shipment->id)->count());
    }
}
