<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Domain\Cases\CaseScanner;
use App\Domain\Cases\CaseService;
use App\Domain\Cases\Models\WorkCase;
use App\Domain\Finance\Reconciliation;
use App\Domain\Payments\Models\Payment;
use App\Domain\Risk\FraudGraph;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * من الكشف إلى القضيّة — بلا ضجيج وبلا إغلاقٍ آليّ.
 *
 * الكاشفات قراءةٌ محضة، والفتح فعلٌ مجدول له سجلّه. ودمجُهما كان سيجعل فتح
 * شاشةٍ يُنشئ صفوفًا — فيختلف الجواب باختلاف من نظر ومتى.
 */
class CaseScannerTest extends TestCase
{
    use RefreshDatabase;

    private CaseScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = app(CaseScanner::class);
    }

    private function mismatch(): Payment
    {
        $order = Order::create([
            'number' => 'TD-S-'.uniqid(),
            'email' => 'a@b.test',
            'status' => 'PAID',
            'totals_gross_minor' => 10000,
            'total_minor' => 10000,
            'order_key_hash' => hash('sha256', uniqid()),
        ]);

        return Payment::create([
            'order_id' => $order->id, 'provider' => 'fake',
            'intent_id' => 'chg_'.uniqid(), 'state' => 'CAPTURED',
            'idempotency_key' => 'fake:'.uniqid(),
            // المزوّد أبلغ بأقلّ ممّا يقوله الطلب.
            'amount_minor' => 9000, 'currency' => 'SAR', 'captured_at' => now(),
        ]);
    }

    public function test_a_clean_ledger_opens_nothing(): void
    {
        $result = $this->scanner->scan();

        $this->assertSame(0, $result['opened']);
        $this->assertSame(0, WorkCase::query()->count());
    }

    public function test_an_amount_mismatch_opens_a_critical_incident(): void
    {
        $payment = $this->mismatch();

        $this->scanner->scan();

        $case = WorkCase::query()->firstOrFail();

        /*
         * كلّ صفٍّ هنا مالٌ لا يطابق دفترًا. وتركُه في قائمةٍ تُقرأ عند
         * الرغبة يعني أنّه يُقرأ حين يشتكي أحد.
         */
        $this->assertSame('INCIDENT', $case->kind);
        $this->assertSame('CRITICAL', $case->severity);
        $this->assertSame(['AMOUNT_MISMATCH'], $case->reason_codes);
        $this->assertSame('payment#'.$payment->id, $case->subject_type.'#'.$case->subject_id);
    }

    public function test_scanning_twice_does_not_open_a_second_case(): void
    {
        $this->mismatch();

        $this->scanner->scan();
        $second = $this->scanner->scan();

        /*
         * المفتاح يشتقّ من الكيان لا من اللحظة. ولولا ذلك لفتح المسح كلّ
         * ربع ساعةٍ قضيّةً عن العيب نفسه — ستّ وتسعون في اليوم.
         */
        $this->assertSame(0, $second['opened']);
        $this->assertSame(1, $second['recurring']);
        $this->assertSame(1, WorkCase::query()->count());
    }

    public function test_recurrence_is_recorded_on_the_existing_thread(): void
    {
        $this->mismatch();
        $this->scanner->scan();
        $this->scanner->scan();

        $case = WorkCase::query()->firstOrFail();

        // الابتلاع الصامت يُخفي أنّ الشرط ما يزال قائمًا.
        $this->assertSame(1, $case->events()->where('action', 'recurred')->count());
    }

    public function test_a_resolved_case_reopens_if_the_defect_persists(): void
    {
        $this->mismatch();
        $this->scanner->scan();

        $case = WorkCase::query()->firstOrFail();
        app(CaseService::class)->transition($case->id, 'INVESTIGATING');
        app(CaseService::class)->transition($case->id, 'RESOLVED', null, 'ظُنّ أنّه عولج', 'DEFECT_FIXED');
        // والإغلاق بعد التحقّق: قبله تبقى القضيّة تمتصّ التكرار.
        app(CaseService::class)->transition($case->id, 'CLOSED', null, 'تُحقّق منه');

        $result = $this->scanner->scan();

        /*
         * أُغلقت والعيب قائم: تُفتح من جديد. ولو بقي المفتاح مانعًا بعد
         * الإغلاق لاختفى عيبٌ ماليّ لأنّ أحدًا ضغط «حُسمت».
         */
        $this->assertSame(1, $result['opened']);
        $this->assertSame(2, WorkCase::query()->count());
    }

    public function test_the_scanner_closes_nothing_on_its_own(): void
    {
        $payment = $this->mismatch();
        $this->scanner->scan();

        // العيب يزول من نتيجة المسح.
        $payment->update(['amount_minor' => 10000]);
        $this->scanner->scan();

        /*
         * زوالُ الشرط لا يعني أنّ العيب عولج: قد يكون خرج من نافذة التحليل.
         * والإغلاق الآليّ يمحو أثر عيبٍ ماليّ لم ينظر فيه أحد.
         */
        $this->assertSame('NEW', WorkCase::query()->firstOrFail()->state);
    }

    public function test_fraud_findings_are_warnings_not_criticals(): void
    {
        $customer = DB::table('customers')->insertGetId([
            'email' => 'c-'.uniqid().'@b.test', 'name' => 'عميل',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < 2; $i++) {
            Order::create([
                'number' => 'TD-A-'.uniqid(), 'email' => 'a@b.test', 'customer_id' => $customer,
                'status' => 'REFUNDED', 'totals_gross_minor' => 10000, 'total_minor' => 10000,
                'refunded_total_minor' => 10000, 'order_key_hash' => hash('sha256', uniqid()),
            ]);
        }

        $this->scanner->scan();

        $case = WorkCase::query()->where('kind', 'FRAUD')->firstOrFail();

        /*
         * إشارات الاحتيال ضعيفة وتتقاطع مع سلوكٍ مشروع. ورفعُها إلى «حرج»
         * يجعل الحرج بلا معنى فيُتجاهَل حين يكون حقيقيًّا.
         */
        $this->assertSame('WARNING', $case->severity);
        $this->assertSame($customer, $case->customer_id);
    }

    public function test_a_dead_outbox_event_opens_one_incident_not_one_per_event(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DB::table('outbox_events')->insert([
                'id' => Str::uuid(),
                'aggregate_type' => 'order', 'aggregate_id' => (string) $i,
                'event_type' => 'order.paid', 'payload' => json_encode([]),
                'idempotency_key' => 'k-'.uniqid().$i, 'status' => 'DEAD', 'attempts' => 5,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->scanner->scan();

        /*
         * المفتاح لا يحمل العدد: لو حمله لفتح موتُ كلّ حدثٍ قضيّةً جديدة.
         * القضيّة واحدة عن الحالة، والعدد في عنوانها.
         */
        $cases = WorkCase::query()->where('title', 'like', '%صندوق الصادر%')->get();
        $this->assertCount(1, $cases);
        $this->assertStringContainsString('3', $cases[0]->title);
        $this->assertSame(['DEAD_OUTBOX_EVENTS'], $cases[0]->reason_codes);
    }

    public function test_one_uncertain_operation_is_normal_and_opens_nothing(): void
    {
        DB::table('operation_records')->insert([
            'idempotency_key' => 'k-'.uniqid(), 'kind' => 'stock_movement',
            'status' => 'UNCERTAIN', 'last_error' => 'انتهت المهلة',
            'attempts' => 1, 'reconcile_attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->scanner->scan();

        // واحدةٌ يحسمها التوفيق في دقائق — والإنذار عليها ضجيج.
        $this->assertSame(0, WorkCase::query()->count());
    }

    public function test_a_failed_payment_webhook_is_critical(): void
    {
        DB::table('webhook_deliveries')->insert([
            'provider' => 'fake', 'dedupe_key' => 'x-'.uniqid(),
            'signature_verified' => true, 'succeeded' => true, 'status' => 'FAILED',
            'payload' => json_encode([]), 'attempts' => 3, 'replay_count' => 0,
            'last_error' => 'تعذّر', 'received_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->scanner->scan();

        /*
         * مالٌ وصل ولم يُسجَّل أثرُه — أشدّ من أيّ عطلٍ تشغيليّ آخر. وقضيّةٌ
         * **لكلّ تسليم** بمرجعه لا واحدةٌ مجمَّعة: الفعل يحتاج معرفة أيّها.
         */
        $cases = WorkCase::query()->where('severity', 'CRITICAL')->get();

        $this->assertCount(1, $cases);
        $this->assertSame(
            ['PROVIDER_SUCCESS_LOCAL_FAILURE', 'REPAIR:REPLAY_WEBHOOK'],
            $cases[0]->reason_codes,
        );
        $this->assertSame('webhook_delivery', $cases[0]->subject_type);
    }

    public function test_the_detectors_remain_read_only(): void
    {
        $this->mismatch();
        $before = DB::table('orders')->orderBy('id')->get()->toJson();

        app(Reconciliation::class)->run();
        app(FraudGraph::class)->clusters();

        // القراءة لا تفتح قضيّة: فتحُ شاشةٍ يجب ألّا يُنشئ صفوفًا.
        $this->assertSame(0, WorkCase::query()->count());
        $this->assertSame($before, DB::table('orders')->orderBy('id')->get()->toJson());
    }

    public function test_orders_stuck_awaiting_payment_open_one_aggregated_case(): void
    {
        $order = Order::create(['number' => 'TD-stuck', 'email' => 's@t.local', 'status' => 'AWAITING_PAYMENT', 'total_minor' => 100]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subHours(CaseScanner::AWAITING_PAYMENT_HOURS + 1)]);

        $this->scanner->scan();
        $this->scanner->scan();

        // قضيّةٌ واحدة عن الحالة، لا واحدةٌ لكلّ مسح.
        $this->assertSame(1, WorkCase::query()->where('recurrence_key', 'system:orders-awaiting-payment')->count());
    }

    public function test_failed_queue_jobs_open_one_aggregated_case(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
        ]);

        $this->scanner->scan();
        $this->scanner->scan();

        $this->assertSame(1, WorkCase::query()->where('recurrence_key', 'system:failed-jobs')->count());
    }
}
