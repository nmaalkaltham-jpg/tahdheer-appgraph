<?php

namespace Tests\Feature\Operations;

use App\Domain\Operations\Jobs\RetryFulfillmentUnitJob;
use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * القرار «الإعادة آمنة» يقابله فعلٌ — بعمليّةٍ جديدة، ولا فوق عمليّةٍ حيّة.
 */
class OperationsRetryCommandTest extends TestCase
{
    use RefreshDatabase;

    private function temporaryFailure(int $unitId): OperationRecord
    {
        $ledger = app(OperationLedger::class);
        [$record] = $ledger->reserve("fulfillment_retry:{$unitId}:0", 'fulfillment_retry', 'fulfillment_unit', $unitId);
        $ledger->fail($record, 'TEMPORARY', 'توفيقٌ بعد انقطاع: لم يقع أثر.');

        return $record->fresh();
    }

    public function test_dry_run_lists_without_reserving_or_dispatching(): void
    {
        Queue::fake();
        $this->temporaryFailure(41);

        Artisan::call('operations:retry', ['--dry-run' => true]);

        $this->assertStringContainsString('"queued":1', Artisan::output());
        $this->assertSame(1, OperationRecord::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_temporary_failure_is_retried_as_a_new_operation(): void
    {
        Queue::fake();
        $failed = $this->temporaryFailure(42);

        Artisan::call('operations:retry');

        $new = OperationRecord::query()->where('id', '!=', $failed->id)->firstOrFail();
        $this->assertSame('RESERVED', $new->status);
        $this->assertSame('FAILED', $failed->fresh()->status); // النهائيّ نهائيّ
        Queue::assertPushed(RetryFulfillmentUnitJob::class, fn ($job) => $job->operationId === $new->id && $job->unitId === 42);
    }

    public function test_a_subject_with_a_live_operation_is_not_retried_again(): void
    {
        Queue::fake();
        $this->temporaryFailure(43);

        Artisan::call('operations:retry');
        Artisan::call('operations:retry');

        $this->assertStringContainsString('"skipped_live":1', Artisan::output());
        Queue::assertPushed(RetryFulfillmentUnitJob::class, 1);
    }
}
