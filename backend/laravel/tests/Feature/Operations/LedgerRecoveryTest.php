<?php

namespace Tests\Feature\Operations;

use App\Domain\Assets\AssetSourceResolver;
use App\Domain\Assets\Contracts\DigitalAssetSource;
use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\Sources\SourceHealth;
use App\Domain\Assets\Sources\SourceMetadata;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Operations\Exceptions\IdempotencyConflict;
use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use App\Domain\Operations\ReconcilerRegistry;
use App\Domain\Operations\Reconcilers\AssetVerifyReconciler;
use App\Domain\Operations\Reconcilers\StockMovementReconciler;
use App\Domain\Operations\RequestFingerprint;
use App\Domain\Operations\StaleOperationRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الإيجار، والبصمة، والنتيجة المجهولة، والتعافي بعد موت العامل.
 *
 * كلّ اختبارٍ هنا يقابل طريقةً يقع بها تنفيذٌ مزدوج أو أثرٌ يتيم في نظامٍ
 * لا يملك هذه الآليّات.
 */
class LedgerRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function source(): AssetSource
    {
        $asset = Asset::create([
            'provider' => 'local', 'provider_ref' => 'obj/'.uniqid(),
            'original_name' => 'f.pdf', 'sha256' => str_pad(uniqid(), 64, 'a'),
            'size_bytes' => 10, 'mime' => 'application/pdf',
        ]);

        return AssetSource::create([
            'asset_id' => $asset->id, 'provider' => 'INTERNAL_OBJECT_STORAGE',
            'delivery_mode' => 'IMPORT_ONCE', 'external_id' => 'obj/'.uniqid(),
            'sync_state' => 'CURRENT',
        ]);
    }

    private function variant(): ProductVariant
    {
        $n = uniqid();
        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'م', 'product_nature' => 'PHYSICAL', 'status' => 'published',
        ]);

        return ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}", 'status' => 'published',
        ]);
    }

    private function location(): InventoryLocation
    {
        return InventoryLocation::create(['code' => 'L-'.uniqid(), 'name' => 'م', 'kind' => 'WAREHOUSE']);
    }

    private function ledger(): OperationLedger
    {
        return $this->app->make(OperationLedger::class);
    }

    // ── البصمة ───────────────────────────────────────────────────────────

    public function test_the_same_key_with_the_same_request_replays(): void
    {
        $ledger = $this->ledger();
        $print = RequestFingerprint::of(['quantity' => 5, 'reason' => 'جرد']);

        [$first, $isNew] = $ledger->reserve('k-1', 'stock_movement', null, null, null, $print);
        $this->assertTrue($isNew);

        [$second, $isNewAgain] = $ledger->reserve('k-1', 'stock_movement', null, null, null, $print);
        $this->assertFalse($isNewAgain);
        $this->assertSame($first->id, $second->id);
    }

    public function test_field_order_does_not_change_the_fingerprint(): void
    {
        /*
         * ترتيب الحقول ليس جزءًا من معنى الطلب. بصمةٌ تتبعه كانت سترفض
         * إعادة إرسالٍ مشروعة بـ٤٠٩ — عطلٌ أسوأ من الذي تمنعه.
         */
        $this->assertSame(
            RequestFingerprint::of(['a' => 1, 'b' => ['y' => 2, 'x' => 3]]),
            RequestFingerprint::of(['b' => ['x' => 3, 'y' => 2], 'a' => 1]),
        );
    }

    public function test_the_idempotency_key_itself_is_excluded_from_the_fingerprint(): void
    {
        // لو دخل المفتاح البصمة لصارت كلّ بصمةٍ فريدةً بالضرورة فبطل الفحص.
        $this->assertSame(
            RequestFingerprint::of(['quantity' => 5, 'idempotencyKey' => 'aaa']),
            RequestFingerprint::of(['quantity' => 5, 'idempotencyKey' => 'bbb']),
        );
    }

    public function test_the_same_key_with_a_different_request_conflicts(): void
    {
        $ledger = $this->ledger();
        $ledger->reserve('k-2', 'stock_movement', null, null, null, RequestFingerprint::of(['quantity' => 10]));

        $this->expectException(IdempotencyConflict::class);
        $ledger->reserve('k-2', 'stock_movement', null, null, null, RequestFingerprint::of(['quantity' => 5]));
    }

    // ── الإيجار ──────────────────────────────────────────────────────────

    public function test_only_one_worker_can_hold_the_lease(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('k-3', 'stock_movement');

        $this->assertNotNull($ledger->acquire($record, 'worker-a'));

        // عاملان على العمليّة نفسها ينفّذان الأثر مرّتين — هذا ما يمنعه.
        $this->assertNull($ledger->acquire($record->fresh(), 'worker-b'));
    }

    public function test_an_expired_lease_can_be_taken_over(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('k-4', 'stock_movement');
        $ledger->acquire($record, 'worker-a', 1);

        OperationRecord::query()->whereKey($record->id)->update(['lease_expires_at' => now()->subMinute()]);

        // موتُ العامل لا يجوز أن يجمّد العمليّة إلى الأبد.
        $this->assertNotNull($ledger->acquire($record->fresh(), 'worker-b'));
        $this->assertSame('worker-b', $record->fresh()->lease_owner);
    }

    public function test_a_foreign_owner_cannot_heartbeat(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('k-5', 'stock_movement');
        $ledger->acquire($record, 'worker-a');

        // نبضٌ من عاملٍ فقد إيجاره يجب أن يفشل لا أن يسرقه ممّن التقطه.
        $this->assertFalse($ledger->heartbeat($record->fresh(), 'worker-b'));
        $this->assertTrue($ledger->heartbeat($record->fresh(), 'worker-a'));
    }

    public function test_a_heartbeat_extends_the_lease(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('k-6', 'stock_movement');
        $ledger->acquire($record, 'worker-a', 30);
        $before = $record->fresh()->lease_expires_at;

        $this->travel(5)->seconds();
        $ledger->heartbeat($record->fresh(), 'worker-a', 30);

        $this->assertTrue($record->fresh()->lease_expires_at->gt($before));
    }

    // ── المجهول ──────────────────────────────────────────────────────────

    public function test_an_uncertain_operation_is_not_finished(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('k-7', 'stock_movement');
        $ledger->markUncertain($record, 'انتهت المهلة قبل الجواب.');

        $fresh = $record->fresh();
        $this->assertTrue($fresh->isUncertain());
        $this->assertFalse($fresh->isFinished());
        $this->assertSame('UNKNOWN', $fresh->provider_state);
        // الإيجار يُحرَّر: التوفيق يحتاج أن يستحوذ عليه.
        $this->assertNull($fresh->lease_owner);
    }

    public function test_a_timeout_does_not_overwrite_a_success(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('k-8', 'stock_movement');
        $ledger->succeed($record, ['ok' => true]);
        $ledger->markUncertain($record->fresh(), 'جوابٌ متأخّر');

        // نجاحٌ مسجَّل لا يُقلب شكًّا بجوابٍ وصل بعد فوات الأوان.
        $this->assertSame('SUCCEEDED', $record->fresh()->status);
    }

    // ── التعافي ──────────────────────────────────────────────────────────

    public function test_a_dead_worker_moves_the_operation_to_uncertain(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('stock_movement:gone', 'stock_movement');
        $ledger->acquire($record, 'worker-dead', 1);
        $ledger->markProcessing($record);
        OperationRecord::query()->whereKey($record->id)->update(['lease_expires_at' => now()->subMinute()]);

        $tally = $this->recovery()->run();

        $this->assertSame(1, $tally['scanned']);
        // لا سطر في الدفتر ⇦ لم يقع شيء ⇦ الإعادة آمنة.
        $this->assertSame(1, $tally['absent']);
        $this->assertSame('ABSENT', $record->fresh()->provider_state);
        $this->assertSame('TEMPORARY', $record->fresh()->failure_class);
    }

    public function test_a_committed_stock_movement_is_reconciled_as_confirmed(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('stock_movement:landed', 'stock_movement');
        $ledger->acquire($record, 'worker-dead', 1);
        $ledger->markProcessing($record);

        /*
         * الأثر وقع — سطرٌ في الدفتر بالمفتاح نفسه — ثمّ مات العامل قبل أن
         * يسجّل النتيجة. إعلانُه فشلًا كان سيترك حركةَ مخزونٍ يتيمة.
         */
        $variant = $this->variant();
        $location = $this->location();

        DB::table('stock_ledger')->insert([
            'product_variant_id' => $variant->id, 'inventory_location_id' => $location->id,
            'movement' => 'RECEIPT', 'quantity_delta' => 7, 'balance_after' => 7,
            'reason' => 'شحنة', 'idempotency_key' => 'stock_movement:landed',
            'created_at' => now(),
        ]);

        OperationRecord::query()->whereKey($record->id)->update(['lease_expires_at' => now()->subMinute()]);

        $tally = $this->recovery()->run();

        $this->assertSame(1, $tally['confirmed']);
        $this->assertSame('SUCCEEDED', $record->fresh()->status);
        $this->assertSame('CONFIRMED', $record->fresh()->provider_state);
        $this->assertSame(7, $record->fresh()->result['onHand']);
    }

    /**
     * الاختبار الإلزاميّ: المزوّد نجح، والعامل مات قبل التوفيق المحلّيّ.
     *
     * المطلوب إثباته أربعة أشياء معًا: يكتشف التعافي الحالة، ويسأل المزوّد
     * سؤالًا **قرائيًّا**، ويوفّق الحالة المحلّيّة، و**لا ينادي المزوّد
     * لتنفيذٍ ثانٍ**. الشرط الأخير هو كلّ المعنى: نظامٌ يتعافى بإعادة
     * التنفيذ هو مصدر التنفيذ المزدوج لا علاجه.
     */
    public function test_a_crashed_worker_recovers_without_re_executing_at_the_provider(): void
    {
        $source = $this->source();
        $probe = $this->fakeProvider();
        $this->app->instance(AssetSourceResolver::class, $this->resolverFor($probe));

        $ledger = $this->ledger();
        [$record] = $ledger->reserve('asset_verify:'.$source->id.':0', 'asset_verify', 'asset_source', $source->id);
        $ledger->acquire($record, 'worker-dead', 1);
        $ledger->markProcessing($record);

        // أثرُ المزوّد وقع وسُجّل، ثمّ مات العامل قبل إنهاء العمليّة.
        DB::table('asset_source_syncs')->insert([
            'asset_source_id' => $source->id, 'trigger' => 'manual',
            'outcome' => 'NO_CHANGE', 'created_at' => now(),
        ]);

        OperationRecord::query()->whereKey($record->id)->update(['lease_expires_at' => now()->subMinute()]);

        $tally = $this->recovery()->run();

        $this->assertSame(1, $tally['confirmed']);
        $this->assertSame('SUCCEEDED', $record->fresh()->status);
        $this->assertSame('CONFIRMED', $record->fresh()->provider_state);
        $this->assertTrue($record->fresh()->result['reconciled']);

        // ولا استيراد ولا مزامنة: التوفيق يسأل ولا ينفّذ.
        $this->assertSame(0, $probe->imports);
        $this->assertSame(0, $probe->syncs);
    }

    public function test_reconciliation_probes_the_provider_when_there_is_no_local_trace(): void
    {
        $source = $this->source();
        $probe = $this->fakeProvider();
        $this->app->instance(AssetSourceResolver::class, $this->resolverFor($probe));

        $ledger = $this->ledger();
        [$record] = $ledger->reserve('asset_verify:'.$source->id.':1', 'asset_verify', 'asset_source', $source->id);
        $ledger->acquire($record, 'worker-dead', 1);
        $ledger->markProcessing($record);
        OperationRecord::query()->whereKey($record->id)->update(['lease_expires_at' => now()->subMinute()]);

        $tally = $this->recovery()->run();

        $this->assertSame(1, $probe->inspections);   // سُئل
        $this->assertSame(0, $probe->imports);       // ولم يُنفَّذ
        $this->assertSame(1, $tally['absent']);
        $this->assertSame('ABSENT', $record->fresh()->provider_state);
    }

    public function test_a_provider_that_cannot_answer_leaves_the_operation_uncertain(): void
    {
        $source = $this->source();
        $probe = $this->fakeProvider(unavailable: true);
        $this->app->instance(AssetSourceResolver::class, $this->resolverFor($probe));

        $ledger = $this->ledger();
        [$record] = $ledger->reserve('asset_verify:'.$source->id.':2', 'asset_verify', 'asset_source', $source->id);
        $ledger->acquire($record, 'worker-dead', 1);
        $ledger->markProcessing($record);
        OperationRecord::query()->whereKey($record->id)->update(['lease_expires_at' => now()->subMinute()]);

        $tally = $this->recovery()->run();

        /*
         * عطلُ المزوّد ليس جوابًا عن الأثر. إعلانُه «غيابًا» كان سيسمح
         * بإعادةٍ قد تكون تنفيذًا ثانيًا حين يعود المزوّد.
         */
        $this->assertSame(1, $tally['unknown']);
        $this->assertSame('UNCERTAIN', $record->fresh()->status);
        $this->assertSame('UNKNOWN', $record->fresh()->provider_state);
        $this->assertSame(0, $probe->imports);
    }

    public function test_recovery_is_safe_to_run_twice(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('stock_movement:twice', 'stock_movement');
        $ledger->acquire($record, 'worker-dead', 1);
        $ledger->markProcessing($record);
        OperationRecord::query()->whereKey($record->id)->update(['lease_expires_at' => now()->subMinute()]);

        $this->recovery()->run();
        $second = $this->recovery()->run();

        // حُسمت في المرّة الأولى فلم تعد تُلتقط: لا حلقة توفيقٍ لا تنتهي.
        $this->assertSame(0, $second['scanned']);
    }

    public function test_an_operation_kind_without_a_reconciler_is_left_alone(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('mystery:1', 'mystery_kind');
        $ledger->acquire($record, 'worker-dead', 1);
        $ledger->markProcessing($record);
        OperationRecord::query()->whereKey($record->id)->update(['lease_expires_at' => now()->subMinute()]);

        $tally = $this->recovery()->run();

        // لا موفّق ⇦ لا جواب يُخترع. تبقى كما هي ويُعلَن تخطّيها.
        $this->assertSame(1, $tally['skipped']);
        $this->assertSame('PROCESSING', $record->fresh()->status);
    }

    // ── أدوات ────────────────────────────────────────────────────────────

    private function recovery(): StaleOperationRecovery
    {
        return new StaleOperationRecovery(
            $this->ledger(),
            new ReconcilerRegistry([
                new StockMovementReconciler,
                new AssetVerifyReconciler($this->app->make(AssetSourceResolver::class)),
            ]),
        );
    }

    private function fakeProvider(bool $unavailable = false): object
    {
        return new class($unavailable) implements DigitalAssetSource
        {
            public int $inspections = 0;

            public int $imports = 0;

            public int $syncs = 0;

            public function __construct(private readonly bool $unavailable) {}

            public function provider(): string
            {
                return 'INTERNAL_OBJECT_STORAGE';
            }

            public function inspect(string $externalId): SourceMetadata
            {
                $this->inspections++;

                if ($this->unavailable) {
                    throw new SourceUnavailable($this->provider(), 'المزوّد لا يجيب');
                }

                return new SourceMetadata($externalId, 'f.pdf', 'application/pdf', 10, 'r1');
            }

            public function validate(string $externalId): array
            {
                return [];
            }

            public function import(string $externalId): array
            {
                $this->imports++;

                return ['key' => 'k', 'sha256' => str_repeat('a', 64), 'size_bytes' => 10];
            }

            public function sync(string $externalId, ?string $sinceRevision): ?array
            {
                $this->syncs++;

                return null;
            }

            public function getMetadata(string $externalId): SourceMetadata
            {
                return $this->inspect($externalId);
            }

            public function verifyAccess(string $externalId): bool
            {
                return true;
            }

            public function getRevision(string $externalId): ?string
            {
                return 'r1';
            }

            public function getChecksum(string $externalId): ?string
            {
                return null;
            }

            public function openSecureStream(string $externalId)
            {
                throw new \RuntimeException('لا بثّ في الاختبار — الموفّق لا يقرأ بايتات.');
            }

            public function health(): SourceHealth
            {
                return new SourceHealth($this->provider(), true, ! $this->unavailable, null);
            }
        };
    }

    private function resolverFor(object $adapter): AssetSourceResolver
    {
        return new class($adapter) extends AssetSourceResolver
        {
            public function __construct(private readonly object $adapter) {}

            public function for(string $provider): DigitalAssetSource
            {
                return $this->adapter;
            }
        };
    }

    public function test_a_reservation_whose_job_never_came_is_recovered_after_the_grace_period(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('stock_movement:lost', 'stock_movement');
        OperationRecord::query()->whereKey($record->id)
            ->update(['created_at' => now()->subMinutes(OperationLedger::RESERVED_GRACE_MINUTES + 1)]);

        $tally = $this->recovery()->run();

        // لا سطر في الدفتر ⇦ لم يقع شيء ⇦ تُعلَّم مؤقّتةً والإعادة آمنة — لا «محجوزة» إلى الأبد.
        $this->assertSame(1, $tally['absent']);
        $this->assertSame('FAILED', $record->fresh()->status);
    }

    public function test_a_fresh_reservation_is_left_for_its_job(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('stock_movement:fresh', 'stock_movement');

        $tally = $this->recovery()->run();

        $this->assertSame(0, $tally['scanned']);
        $this->assertSame('RESERVED', $record->fresh()->status);
    }

    public function test_a_finished_operation_cannot_be_marked_processing_again(): void
    {
        $ledger = $this->ledger();
        [$record] = $ledger->reserve('stock_movement:done', 'stock_movement');
        $ledger->succeed($record, ['ok' => true]);

        $after = $ledger->markProcessing($record->fresh());

        $this->assertSame('SUCCEEDED', $after->status);
    }
}
