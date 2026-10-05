<?php

namespace Tests\Feature\Operations;

use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OperationsPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_old_finished_records_are_pruned(): void
    {
        $ledger = app(OperationLedger::class);
        [$old] = $ledger->reserve('stock_movement:old', 'stock_movement');
        $ledger->succeed($old, ['ok' => true]);
        OperationRecord::query()->whereKey($old->id)->update(['completed_at' => now()->subDays(120)]);
        [$recent] = $ledger->reserve('stock_movement:recent', 'stock_movement');
        $ledger->succeed($recent, ['ok' => true]);
        [$uncertain] = $ledger->reserve('stock_movement:unc', 'stock_movement');
        $ledger->acquire($uncertain, 'w', 60);
        $ledger->markProcessing($uncertain);
        $ledger->markUncertain($uncertain, 'x');
        OperationRecord::query()->whereKey($uncertain->id)->update(['created_at' => now()->subDays(400)]);

        Artisan::call('operations:prune', ['--days' => 90, '--dry-run' => true]);
        $this->assertSame(3, OperationRecord::query()->count());

        Artisan::call('operations:prune', ['--days' => 90]);

        $this->assertNull(OperationRecord::query()->find($old->id));
        $this->assertNotNull(OperationRecord::query()->find($recent->id));
        $this->assertNotNull(OperationRecord::query()->find($uncertain->id)); // المجهولة لا تُمسّ
    }
}
