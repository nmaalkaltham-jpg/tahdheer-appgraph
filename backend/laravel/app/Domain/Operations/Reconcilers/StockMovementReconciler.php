<?php

namespace App\Domain\Operations\Reconcilers;

use App\Domain\Operations\Contracts\OperationReconciler;
use App\Domain\Operations\Models\OperationRecord;
use Illuminate\Support\Facades\DB;

/**
 * هل سُجّلت حركة المخزون؟
 *
 * دفتر المخزون يحمل مفتاح الخمول في كلّ سطر، فالسؤال يُجاب بقراءةٍ واحدة
 * لا بتخمين. وهذا أقوى من سؤال مزوّدٍ خارجيّ: الأثر والدليل في معاملةٍ
 * واحدة، فإن وُجد السطر فقد وقع الأثر يقينًا.
 */
class StockMovementReconciler implements OperationReconciler
{
    public function kind(): string
    {
        return 'stock_movement';
    }

    public function reconcile(OperationRecord $record): array
    {
        $row = DB::table('stock_ledger')
            ->where('idempotency_key', $record->idempotency_key)
            ->first();

        if ($row === null) {
            /*
             * غائب **يقينًا**: السطر يُكتب في المعاملة نفسها التي تُعدّل
             * الرصيد. غيابُه يعني أنّ المعاملة لم تلتزم، فالرصيد لم يتحرّك.
             */
            return ['state' => 'ABSENT'];
        }

        return [
            'state' => 'CONFIRMED',
            'reference' => (string) $row->id,
            'result' => [
                'variantId' => (int) $row->product_variant_id,
                'locationId' => (int) $row->inventory_location_id,
                'movement' => $row->movement,
                'onHand' => (int) $row->balance_after,
                'reconciled' => true,
            ],
        ];
    }
}
