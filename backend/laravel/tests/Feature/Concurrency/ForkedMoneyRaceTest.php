<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Orders\Models\Refund;
use App\Domain\Orders\OrderFinalizer;
use App\Domain\Orders\OrderToken;
use App\Domain\Orders\RefundService;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\ProviderRequery;
use App\Domain\Payments\WebhookEvent;
use App\Domain\Payments\WebhookIntake;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Commerce\CommerceFixtures;
use Tests\TestCase;

/**
 * سباقاتٌ حقيقيّة: عمليّاتٌ مستقلّة (pcntl_fork) كلٌّ باتّصاله بقاعدة البيانات،
 * تنطلق معًا عند حاجز، وتنادي خدمات الإنتاج نفسها.
 *
 * ─── لماذا لا يكفي اتّصالٌ ثانٍ في العمليّة نفسها ───
 *
 * اختبارٌ يقفل الصفّ بيده ثمّ يُثبت أنّ اتّصالًا آخر ينتظر يُثبت أنّ
 * PostgreSQL يقفل — لا أنّ الخدمة تقفل. هنا لا يقفل الاختبار شيئًا: العمليّات
 * تتسابق على الخدمة، والنتيجة النهائيّة في القاعدة هي البرهان.
 *
 * البيانات تُلتزم فعلًا (بلا معاملة تغليف) لتراها العمليّات الأخرى، وتُمحى
 * كلّها في النهاية: كلّ صفٍّ أحدث من لقطة البداية في كلّ جدول.
 */
class ForkedMoneyRaceTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private const WORKERS = 8;

    /** @var array<string, int> جداول بمعرّفٍ رقميّ: أعلى معرّفٍ قبل الاختبار */
    private array $watermark = [];

    /** @var array<string, list<string>> جداول بمعرّفٍ غير رقميّ: معرّفاتها قبل الاختبار */
    private array $snapshot = [];

    protected function beginDatabaseTransaction(): void
    {
        // عمدًا فارغة — العمليّات المتفرّعة يجب أن ترى ما التُزم.
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();

        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يحتاج pcntl وPostgreSQL.');
        }

        foreach ($this->tablesWithId() as $table => $type) {
            if (in_array($type, ['bigint', 'integer', 'smallint'], true)) {
                $this->watermark[$table] = (int) DB::table($table)->max('id');
            } else {
                $this->snapshot[$table] = DB::table($table)->pluck('id')->map(fn ($v) => (string) $v)->all();
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->watermark !== [] || $this->snapshot !== []) {
            DB::statement('SET session_replication_role = replica');
            foreach ($this->watermark as $table => $max) {
                DB::table($table)->where('id', '>', $max)->delete();
            }
            foreach ($this->snapshot as $table => $ids) {
                DB::table($table)->when($ids !== [], fn ($q) => $q->whereNotIn('id', $ids))->delete();
            }
            DB::statement('SET session_replication_role = DEFAULT');
        }

        parent::tearDown();
    }

    /** @return array<string, string> table → id data type */
    private function tablesWithId(): array
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', 'public')->where('column_name', 'id')
            ->whereNotIn('table_name', ['migrations'])
            ->pluck('data_type', 'table_name')->all();
    }

    /**
     * يشغّل `$work` في N عمليّةٍ مستقلّة تنطلق معًا، ويُعيد نتائجها.
     *
     * @return list<array{ok: bool, value?: mixed, error?: string}>
     */
    private function race(int $n, \Closure $work): array
    {
        $dir = sys_get_temp_dir().'/race_'.uniqid('', true);
        mkdir($dir);
        $go = $dir.'/go';

        // الأب يغلق اتّصاله: لا يرث الأبناء مقبسًا مشتركًا يُفسد جلسته.
        DB::disconnect();

        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                try {
                    DB::reconnect();
                    DB::statement("SET lock_timeout = '10s'");
                    $deadline = microtime(true) + 10;
                    while (! file_exists($go) && microtime(true) < $deadline) {
                        usleep(500);
                    }
                    $result = ['ok' => true, 'value' => $work($i)];
                } catch (\Throwable $e) {
                    $result = ['ok' => false, 'error' => get_class($e).': '.mb_substr($e->getMessage(), 0, 300)];
                }
                file_put_contents("{$dir}/{$i}.json", json_encode($result));
                // بلا إغلاقٍ منظّم: لا مدمّرات ولا تنظيف PHPUnit في الابن.
                posix_kill(getmypid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        usleep(50000);
        touch($go);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        $results = [];
        for ($i = 0; $i < $n; $i++) {
            $file = "{$dir}/{$i}.json";
            $results[] = file_exists($file) ? json_decode((string) file_get_contents($file), true) : ['ok' => false, 'error' => 'no result'];
            @unlink($file);
        }
        @unlink($go);
        @rmdir($dir);

        return $results;
    }

    private function awaitingOrder(string $sku): Order
    {
        $cookie = $this->cartWith([$this->makeProduct($sku.'-'.uniqid(), 40000)]);

        return $this->checkoutOrder($cookie)['order'];
    }

    private function paidOrder(string $sku): Order
    {
        $order = $this->awaitingOrder($sku);
        app(OrderFinalizer::class)->finalize($order, 'fake', 'chg_'.$order->id, [], 40000, 'SAR');

        return $order->fresh();
    }

    private function captureEvent(Order $order, string $chargeId): WebhookEvent
    {
        return new WebhookEvent(
            provider: 'fake', chargeId: $chargeId, orderNumber: (string) $order->number, succeeded: true,
            payload: ['charge_id' => $chargeId, 'order_number' => $order->number, 'status' => 'CAPTURED', 'amount_minor' => 40000],
            amountMinor: 40000, currency: 'SAR',
        );
    }

    /** مسارُ متحكّم الخطّاف نفسه: احفظ، ثمّ عالج ما لم يُحسم. */
    private function deliver(WebhookEvent $event): string
    {
        $intake = app(WebhookIntake::class);
        ['delivery' => $delivery, 'duplicate' => $dup] = $intake->record($event, true);
        if ($dup && $delivery->isSettled()) {
            return 'duplicate-settled';
        }

        return $intake->process($delivery, $event)->status;
    }

    private function assertFundedOnce(Order $order, string $why): void
    {
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('funding', 'FUNDED')->count(), "FUNDED rows · {$why}");
        $this->assertSame(1, DB::table('entitlements')->where('order_id', $order->id)->count(), "entitlements · {$why}");
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'order.paid')->where('aggregate_id', (string) $order->id)->count(), "order.paid · {$why}");
        $this->assertSame('COMPLETED', $order->fresh()->status, $why);
    }

    // ── الخطّافات ─────────────────────────────────────────────────────────

    public function test_the_same_capture_delivered_by_eight_processes_funds_once(): void
    {
        $order = $this->awaitingOrder('TD-RACE-1');
        $event = $this->captureEvent($order, 'chg_race_1');

        $results = $this->race(self::WORKERS, fn () => $this->deliver($event));

        $this->assertFundedOnce($order, json_encode($results));
        $this->assertSame(1, Payment::query()->where('idempotency_key', 'fake:chg_race_1')->count());
        $this->assertSame(1, DB::table('webhook_deliveries')->where('charge_id', 'chg_race_1')->count());
    }

    public function test_webhook_and_requery_racing_fund_once(): void
    {
        $order = $this->awaitingOrder('TD-RACE-2');
        $charge = FakeProvider::chargeId($order);
        Payment::query()->where('order_id', $order->id)->update(['created_at' => now()->subMinutes(15)]);

        $results = $this->race(self::WORKERS, function (int $i) use ($order, $charge) {
            if ($i % 2 === 0) {
                return $this->deliver($this->captureEvent($order, $charge));
            }
            FakeProvider::$charges[$charge] = ['status' => 'CAPTURED', 'amount_minor' => 40000, 'currency' => 'SAR'];

            return app(ProviderRequery::class)->run();
        });

        $this->assertFundedOnce($order, json_encode($results));
    }

    public function test_the_requery_job_run_by_duplicate_schedulers_funds_once(): void
    {
        $order = $this->awaitingOrder('TD-RACE-3');
        $charge = FakeProvider::chargeId($order);
        Payment::query()->where('order_id', $order->id)->update(['created_at' => now()->subMinutes(15)]);

        $results = $this->race(self::WORKERS, function () use ($charge) {
            FakeProvider::$charges[$charge] = ['status' => 'CAPTURED', 'amount_minor' => 40000, 'currency' => 'SAR'];

            return Artisan::call('payments:requery');
        });

        $this->assertFundedOnce($order, json_encode($results));
    }

    public function test_two_different_valid_captures_racing_fund_once_and_record_the_extra(): void
    {
        // بطاقة وApple Pay دُفعا معًا: مالٌ مرّتين — يُموِّل أحدهما، والآخر زائدٌ مسجَّل.
        $order = $this->awaitingOrder('TD-RACE-4');

        $results = $this->race(self::WORKERS, fn (int $i) => $this->deliver($this->captureEvent($order, 'chg_race_4_'.($i % 2))));

        $this->assertFundedOnce($order, json_encode($results));
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('funding', 'EXTRA')->count(), json_encode($results));
        $this->assertSame(2, Payment::query()->where('order_id', $order->id)->where('state', 'CAPTURED')->count());
    }

    // ── الاستردادات ─────────────────────────────────────────────────────────

    public function test_eight_concurrent_full_refunds_move_money_once(): void
    {
        $order = $this->paidOrder('TD-RACE-5');

        $results = $this->race(self::WORKERS, function (int $i) use ($order) {
            config()->set('services.fake.refund_mode', 'succeed');

            return app(RefundService::class)->refund(Order::query()->findOrFail($order->id), 40000, 'x', RevocationPolicy::Keep, "full-{$i}")->status;
        });

        $settled = Refund::query()->where('order_id', $order->id)->whereIn('status', Refund::SETTLED)->get();
        $this->assertCount(1, $settled, json_encode($results));
        $this->assertSame(40000, (int) $settled->sum('amount_minor'));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        $fresh = $order->fresh();
        $this->assertSame([40000, 0, 'REFUNDED'], [(int) $fresh->refunded_total_minor, (int) $fresh->refund_reserved_minor, $fresh->status]);
        $this->assertSame(40000, (int) Payment::query()->where('order_id', $order->id)->where('funding', 'FUNDED')->value('refunded_minor'));
    }

    public function test_eight_concurrent_partial_refunds_never_exceed_the_capture(): void
    {
        $order = $this->paidOrder('TD-RACE-6');

        $results = $this->race(self::WORKERS, function (int $i) use ($order) {
            config()->set('services.fake.refund_mode', 'succeed');

            return app(RefundService::class)->refund(Order::query()->findOrFail($order->id), 10000, 'x', RevocationPolicy::Keep, "part-{$i}")->status;
        });

        $this->assertSame(4, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        $this->assertSame(40000, (int) Refund::query()->where('order_id', $order->id)->whereIn('status', Refund::SETTLED)->sum('amount_minor'));
        $this->assertSame(40000, (int) $order->fresh()->refunded_total_minor);
        $this->assertSame('REFUNDED', $order->fresh()->status);
    }

    public function test_the_same_refund_key_from_eight_processes_is_one_refund(): void
    {
        $order = $this->paidOrder('TD-RACE-7');

        $results = $this->race(self::WORKERS, function () use ($order) {
            config()->set('services.fake.refund_mode', 'succeed');

            return app(RefundService::class)->refund(Order::query()->findOrFail($order->id), 15000, 'x', RevocationPolicy::Keep, 'same-key')->id;
        });

        $ids = array_unique(array_column(array_filter($results, fn ($r) => $r['ok']), 'value'));
        $this->assertCount(1, $ids, json_encode($results));
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
        $this->assertSame(15000, (int) $order->fresh()->refunded_total_minor);
    }

    public function test_an_app_refund_racing_a_dashboard_refund_never_overruns(): void
    {
        $order = $this->paidOrder('TD-RACE-8');
        $charge = 'chg_'.$order->id;

        $results = $this->race(self::WORKERS, function (int $i) use ($order, $charge) {
            config()->set('services.fake.refund_mode', 'succeed');
            if ($i % 2 === 0) {
                return app(RefundService::class)->refund(Order::query()->findOrFail($order->id), 30000, 'x', RevocationPolicy::Keep, "app-{$i}")->status;
            }

            return app(RefundService::class)->settleFromProvider('fake', "re_dash_{$i}", 'REFUNDED', $charge, 30000, 'SAR');
        });

        $fresh = $order->fresh();
        $this->assertLessThanOrEqual(40000, (int) $fresh->refunded_total_minor + (int) $fresh->refund_reserved_minor, json_encode($results));
        $this->assertSame(30000, (int) Refund::query()->where('order_id', $order->id)->whereIn('status', Refund::SETTLED)->sum('amount_minor'), json_encode($results));
        $this->assertLessThanOrEqual(40000, (int) Payment::query()->where('order_id', $order->id)->where('funding', 'FUNDED')->value('refunded_minor'));
    }

    // ── §٥ تبديل الطريقة من تبويبين معًا، ثمّ دفعُ الرسمين معًا ───────────────

    public function test_parallel_method_switching_then_parallel_payment_funds_once(): void
    {
        $tap = [
            'services.payments.provider' => 'tap', 'services.tap.apple_pay' => true,
            'services.tap.mode' => 'test', 'services.tap.merchant_id' => '68052232',
            'services.tap.test.secret_key' => 'sk_test_race0001', 'services.tap.webhook_secret' => null,
        ];
        config($tap);
        $order = $this->awaitingOrder('TD-RACE-9');
        Payment::query()->where('order_id', $order->id)->delete(); // متجرٌ بمزوّدٍ واحد (Tap)
        $token = OrderToken::make($order->number, $order->email);

        // تبويبان يطلبان طريقتين مختلفتين في اللحظة نفسها.
        $opened = $this->race(2, function (int $i) use ($tap, $token) {
            config($tap);
            Http::fake([
                'api.tap.company/v2/charges/*' => fn ($r) => Http::response(['id' => basename($r->url()), 'status' => 'INITIATED', 'amount' => 400, 'currency' => 'SAR']),
                'api.tap.company/v2/charges' => fn ($r) => Http::response(['id' => 'chg_TS_par_'.substr(md5((string) $r['reference']['idempotent']), 0, 8), 'status' => 'INITIATED', 'transaction' => ['url' => 'https://x']]),
            ]);

            return $this->postJson("/api/v1/checkout/orders/{$token}/payment", ['paymentMethod' => $i === 0 ? null : 'apple_pay'])->status();
        });

        $charges = Payment::query()->where('order_id', $order->id)->where('provider', 'tap')->whereNotNull('intent_id')->pluck('intent_id')->all();
        $this->assertGreaterThanOrEqual(1, count($charges), json_encode($opened));
        $this->assertLessThanOrEqual(2, count($charges), 'طريقتان — رسمان على الأكثر');

        // ثمّ يُدفع كلّ رسمٍ قائم، من عمليّاتٍ متوازية، ويصل خطّاف كلٍّ مرّتين.
        $results = $this->race(self::WORKERS, function (int $i) use ($tap, $order, $charges) {
            config($tap);
            $charge = $charges[$i % count($charges)];
            $event = new WebhookEvent(
                provider: 'tap', chargeId: $charge, orderNumber: (string) $order->number, succeeded: true,
                payload: ['id' => $charge, 'status' => 'CAPTURED', 'amount' => 400, 'currency' => 'SAR'],
                amountMinor: 40000, currency: 'SAR',
            );

            return $this->deliver($event);
        });

        $this->assertFundedOnce($order, json_encode($results));
        $this->assertSame(count($charges) - 1, Payment::query()->where('order_id', $order->id)->where('funding', 'EXTRA')->count(), json_encode($results));
    }
}
