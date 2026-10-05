<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Operations\Exceptions\IdempotencyConflict;
use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use App\Domain\Operations\RequestFingerprint;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * برهان التزامن على العمليّات والمخزون — PostgreSQL حقيقيّ.
 *
 * ─── لماذا لا تصلح الاختبارات المعتادة ───
 *
 * `RefreshDatabase` يلفّ الاختبار في معاملةٍ تُلغى بعده، وداخل معاملةٍ واحدة
 * لا تزامنَ أصلًا: لا اتّصال ثانٍ يرى الصفّ، ولا قفل يُتنازع عليه. فاختبارٌ
 * ينجح هناك لا يُثبت شيئًا عن سباقٍ حقيقيّ.
 *
 * التغليف مُعطَّل هنا عمدًا، وما يُنشأ يُنظَّف في `tearDown`.
 */
class OperationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $variantIds = [];

    /** @var list<int> */
    private array $groupIds = [];

    /** @var list<int> */
    private array $locationIds = [];

    /** @var list<string> */
    private array $keys = [];

    protected function beginDatabaseTransaction(): void
    {
        // عمدًا فارغة — التزامن يحتاج التزامًا حقيقيًّا.
    }

    protected function tearDown(): void
    {
        if ($this->keys !== []) {
            DB::table('operation_records')->whereIn('idempotency_key', $this->keys)->delete();
            DB::table('stock_ledger')->whereIn('idempotency_key', $this->keys)->delete();
        }

        if ($this->variantIds !== []) {
            DB::table('stock_ledger')->whereIn('product_variant_id', $this->variantIds)->delete();
            DB::table('stock_levels')->whereIn('product_variant_id', $this->variantIds)->delete();
            /*
             * أحداث التوفّر تُصدَر بـ`product_variant` لا `inventory`. وتركُها
             * ملتزمةً يُسقط اختبارات صندوق الصادر التي تعدّ الصفوف — وهو
             * تسريبُ حالةٍ بين اختبارين لا عيبٌ فيهما.
             */
            DB::table('outbox_events')
                ->where('aggregate_type', 'product_variant')
                ->whereIn('aggregate_id', array_map('strval', $this->variantIds))
                ->delete();
            DB::table('product_variants')->whereIn('id', $this->variantIds)->delete();
        }

        if ($this->groupIds !== []) {
            DB::table('product_groups')->whereIn('id', $this->groupIds)->delete();
        }

        if ($this->locationIds !== []) {
            DB::table('inventory_locations')->whereIn('id', $this->locationIds)->delete();
        }

        $this->variantIds = $this->groupIds = $this->locationIds = [];
        $this->keys = [];

        parent::tearDown();
    }

    private function secondConnection(): Connection
    {
        config(['database.connections.concurrent' => config('database.connections.pgsql')]);
        DB::purge('concurrent');

        $second = DB::connection('concurrent');
        // لئلّا يتجمّد الاختبار لو تسلّل انتظارٌ من مكانٍ آخر.
        $second->statement("SET lock_timeout = '2s'");

        return $second;
    }

    private function variant(): ProductVariant
    {
        $n = uniqid();
        $group = ProductGroup::create([
            'slug' => "cg-{$n}", 'title' => 'م', 'product_nature' => 'PHYSICAL', 'status' => 'published',
        ]);
        $this->groupIds[] = $group->id;

        $variant = ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "CV-{$n}", 'slug' => "cv-{$n}", 'status' => 'published',
        ]);
        $this->variantIds[] = $variant->id;

        return $variant;
    }

    private function location(): InventoryLocation
    {
        $location = InventoryLocation::create(['code' => 'CL-'.uniqid(), 'name' => 'م', 'kind' => 'WAREHOUSE']);
        $this->locationIds[] = $location->id;

        return $location;
    }

    private function ledger(): OperationLedger
    {
        return $this->app->make(OperationLedger::class);
    }

    // ── الحجز ────────────────────────────────────────────────────────────

    public function test_two_reservations_on_one_key_cannot_both_be_new(): void
    {
        $key = 'conc:reserve:'.uniqid();
        $this->keys[] = $key;
        $print = RequestFingerprint::of(['quantity' => 1]);

        [$first, $firstIsNew] = $this->ledger()->reserve($key, 'stock_movement', null, null, null, $print);
        [$second, $secondIsNew] = $this->ledger()->reserve($key, 'stock_movement', null, null, null, $print);

        /*
         * القيد الفريد في المحرّك هو ما يحسم، لا فحصٌ قبل الإدراج. والفحص
         * وحده سباقٌ بعينه: بين قراءته وكتابته تتّسع فجوةٌ يدخلها طلبٌ ثانٍ.
         */
        $this->assertTrue($firstIsNew);
        $this->assertFalse($secondIsNew);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('operation_records')->where('idempotency_key', $key)->count());
    }

    public function test_a_unique_violation_is_provable_at_the_engine(): void
    {
        $key = 'conc:unique:'.uniqid();
        $this->keys[] = $key;

        $this->ledger()->reserve($key, 'stock_movement');

        // إدراجٌ مباشر من اتّصالٍ ثانٍ: المحرّك يرفض، لا الشيفرة.
        $this->expectException(QueryException::class);

        $this->secondConnection()->table('operation_records')->insert([
            'idempotency_key' => $key, 'kind' => 'stock_movement', 'status' => 'RESERVED',
            'attempts' => 0, 'reconcile_attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── الإيجار ──────────────────────────────────────────────────────────

    public function test_a_stale_operation_cannot_get_two_owners(): void
    {
        $key = 'conc:lease:'.uniqid();
        $this->keys[] = $key;

        [$record] = $this->ledger()->reserve($key, 'stock_movement');
        $this->ledger()->markProcessing($record);

        // إيجارٌ مات صاحبه — وهو ما يلتقطه التعافي.
        DB::table('operation_records')->where('id', $record->id)->update([
            'lease_owner' => 'worker-dead', 'lease_expires_at' => now()->subMinute(),
        ]);

        $ledger = $this->ledger();

        $first = $ledger->acquire($record->fresh(), 'recovery-a', 60);
        $second = $ledger->acquire($record->fresh(), 'recovery-b', 60);

        /*
         * التحديث الشرطيّ يُقيَّم في المحرّك، فلا فجوة بين الفحص والكتابة.
         * لو فاز الاثنان لسأل موفّقان المزوّد وكتبا نتيجتين.
         */
        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame('recovery-a', $record->fresh()->lease_owner);
    }

    public function test_the_lease_row_is_locked_during_a_ledger_transaction(): void
    {
        $key = 'conc:lock:'.uniqid();
        $this->keys[] = $key;

        [$record] = $this->ledger()->reserve($key, 'stock_movement');
        $second = $this->secondConnection();
        $observed = false;

        DB::transaction(function () use ($record, $second, &$observed) {
            OperationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            try {
                // `NOWAIT` يرمي فورًا بدل الانتظار — فالرمي هو الدليل.
                $second->select('select id from operation_records where id = ? for update nowait', [$record->id]);
            } catch (PDOException) {
                $observed = true;
            }
        });

        $this->assertTrue($observed, 'الصفّ لم يكن مقفولًا أثناء المعاملة.');
    }

    // ── المخزون ──────────────────────────────────────────────────────────

    public function test_inventory_cannot_double_apply_the_same_key(): void
    {
        $variant = $this->variant();
        $location = $this->location();
        $key = 'conc:stock:'.uniqid();
        $this->keys[] = $key;

        $inventory = $this->app->make(InventoryService::class);

        $inventory->receive($variant->id, $location->id, 10, 'شحنة', $key);
        $inventory->receive($variant->id, $location->id, 10, 'شحنة', $key);

        /*
         * جردٌ يتضاعف بإعادة إرسالٍ عارضة هو أسوأ ما يقع لمخزون: يُباع ما لا
         * يوجد ولا يعرف أحدٌ من أين جاء الفرق.
         */
        $this->assertSame(10, (int) DB::table('stock_levels')
            ->where('product_variant_id', $variant->id)->value('on_hand'));
        $this->assertSame(1, DB::table('stock_ledger')->where('idempotency_key', $key)->count());
    }

    public function test_the_stock_level_row_is_locked_during_a_movement(): void
    {
        $variant = $this->variant();
        $location = $this->location();
        $key = 'conc:stocklock:'.uniqid();
        $this->keys[] = $key;

        $this->app->make(InventoryService::class)->receive($variant->id, $location->id, 5, 'أوّليّ', $key.':seed');
        $this->keys[] = $key.':seed';

        $levelId = (int) DB::table('stock_levels')->where('product_variant_id', $variant->id)->value('id');
        $second = $this->secondConnection();
        $observed = false;

        DB::transaction(function () use ($levelId, $second, &$observed) {
            DB::table('stock_levels')->where('id', $levelId)->lockForUpdate()->first();

            try {
                $second->select('select id from stock_levels where id = ? for update nowait', [$levelId]);
            } catch (PDOException) {
                $observed = true;
            }
        });

        $this->assertTrue($observed, 'صفّ المخزون لم يكن مقفولًا أثناء الحركة.');
    }

    public function test_a_second_movement_with_a_different_body_is_a_conflict(): void
    {
        $key = 'conc:print:'.uniqid();
        $this->keys[] = $key;
        $ledger = $this->ledger();

        $ledger->reserve($key, 'stock_movement', null, null, null, RequestFingerprint::of(['quantity' => 10]));

        $this->expectException(IdempotencyConflict::class);
        $ledger->reserve($key, 'stock_movement', null, null, null, RequestFingerprint::of(['quantity' => 5]));
    }

    // ── التنفيذ ──────────────────────────────────────────────────────────

    public function test_fulfillment_cannot_double_execute_under_one_lease(): void
    {
        $key = 'conc:fulfil:'.uniqid();
        $this->keys[] = $key;

        [$record] = $this->ledger()->reserve($key, 'fulfillment_retry');
        $ledger = $this->ledger();

        $this->assertNotNull($ledger->acquire($record->fresh(), 'worker-a'));

        /*
         * عاملٌ ثانٍ يصل بينما الأوّل يعمل: لا يستحوذ، فلا ينفّذ. وهذا هو
         * كلّ ما يمنع تنفيذَين متوازيين على وحدة التنفيذ نفسها.
         */
        $this->assertNull($ledger->acquire($record->fresh(), 'worker-b'));

        $ledger->succeed($record->fresh(), ['ok' => true]);

        // وبعد النجاح لا يُستحوذ عليها أصلًا: النهائيّة لا تُعاد.
        $this->assertNull($ledger->acquire($record->fresh(), 'worker-c'));
    }
}
