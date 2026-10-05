<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * الحلقة الأخيرة: تحصيلٌ ← تسوية ← رسوم ← صافٍ.
 *
 * ─── لماذا منفصلٌ عن `Reconciliation` ───
 *
 * التسوية تعتمد على مصدرٍ خارجيّ قد لا يكون مربوطًا. ودمجُها في المُوفِّق
 * العامّ كان سيجعل غيابَ المصدر يبدو عيبًا في الدفتر — فيُنتج تنبيهًا عن
 * كلّ تحصيلٍ في المتجر.
 *
 * ─── القاعدة الحاكمة: التغطية قبل الحكم ───
 *
 * إن لم تُستورَد تسويةٌ واحدة قطّ، فلا يُقال «كلّ التحصيلات بلا تسوية».
 * تُعلَن التغطية `NONE` ويُصمت عن الباقي. سبعةٌ وعشرون تنبيهًا كاذبًا يُدرِّب
 * المشغّل على إغلاق التنبيهات بلا قراءة — فيُغلق الحقيقيّ معها.
 */
final class SettlementReconciler
{
    /** كم يومًا يُمهَل التحصيل قبل أن يُعدّ غياب تسويته عيبًا. */
    private const SETTLEMENT_GRACE_DAYS = 10;

    /**
     * @return array{coverage: string, settledPayments: int, unsettledPayments: int, findings: list<array<string,mixed>>}
     */
    public function run(int $limit = 100): array
    {
        $totalSettlements = (int) DB::table('settlements')->count();

        if ($totalSettlements === 0) {
            /*
             * لا مصدر مربوط. الصمت هنا صدقٌ لا تهاون: الحكم بلا بيانات
             * يُنتج ضجيجًا يُفقد التنبيهات معناها.
             */
            return [
                'coverage' => 'NONE',
                'settledPayments' => 0,
                'unsettledPayments' => (int) DB::table('payments')->where('state', 'CAPTURED')->count(),
                'findings' => [],
            ];
        }

        $findings = [];

        // ── تسويةٌ لا تطابق تحصيلها ────────────────────────────────────
        foreach ($this->amountMismatches($limit) as $row) {
            $findings[] = new Finding(
                code: 'AMOUNT_MISMATCH',
                severity: 'CRITICAL',
                expected: ['grossMinor' => (int) $row->amount_minor, 'source' => 'capture'],
                actual: ['grossMinor' => (int) $row->gross_minor, 'source' => 'settlement'],
                providerVerdict: 'SETTLED',
                evidence: [
                    'paymentId' => (int) $row->payment_id,
                    'settlementRef' => $row->settlement_ref,
                    'chargeRef' => $row->charge_ref,
                ],
                affectedEntities: ['payment#'.$row->payment_id, 'settlement#'.$row->id],
                repairAction: 'REVIEW_WITH_PROVIDER',
                dedupeKey: 'settle:amount:'.$row->id,
                title: 'التسوية تخالف التحصيل: '
                    .((int) $row->gross_minor - (int) $row->amount_minor).' هللة',
            );
        }

        // ── رسومٌ خارج النطاق المعقول ──────────────────────────────────
        foreach ($this->feeAnomalies($limit) as $row) {
            $rate = (int) $row->gross_minor === 0 ? 0.0 : (int) $row->fee_minor / (int) $row->gross_minor;

            $findings[] = new Finding(
                code: 'FEE_MISMATCH',
                severity: 'HIGH',
                expected: ['feeRateMax' => 0.1, 'source' => 'policy'],
                actual: ['feeMinor' => (int) $row->fee_minor, 'feeRate' => round($rate, 4)],
                providerVerdict: 'SETTLED',
                evidence: ['settlementRef' => $row->settlement_ref, 'grossMinor' => (int) $row->gross_minor],
                affectedEntities: ['settlement#'.$row->id],
                repairAction: 'REVIEW_WITH_PROVIDER',
                dedupeKey: 'settle:fee:'.$row->id,
                title: 'رسوم خارج النطاق: '.round($rate * 100, 1).'٪',
            );
        }

        // ── تحصيلٌ تجاوزت مهلتُه بلا تسوية ─────────────────────────────
        foreach ($this->missingSettlements($limit) as $row) {
            $findings[] = new Finding(
                code: 'MISSING_SETTLEMENT',
                severity: 'HIGH',
                expected: ['settlementWithinDays' => self::SETTLEMENT_GRACE_DAYS],
                actual: ['settlement' => null],
                // لم يُسأل المزوّد: غيابُ الصفّ عندنا ليس نفيًا منه.
                providerVerdict: 'UNKNOWN',
                evidence: ['paymentId' => (int) $row->id, 'chargeId' => $row->intent_id,
                    'capturedAt' => (string) $row->captured_at],
                affectedEntities: ['payment#'.$row->id],
                repairAction: 'FETCH_FROM_PROVIDER',
                dedupeKey: 'settle:missing:'.$row->id,
                title: 'تحصيل بلا تسوية بعد '.self::SETTLEMENT_GRACE_DAYS.' أيّام',
            );
        }

        // ── تسويتان للرسم نفسه في دفعتين ───────────────────────────────
        foreach ($this->duplicateSettlements($limit) as $row) {
            $findings[] = new Finding(
                code: 'DUPLICATE_SETTLEMENT',
                severity: 'CRITICAL',
                expected: ['settlements' => 1],
                actual: ['settlements' => (int) $row->copies],
                providerVerdict: 'SETTLED',
                evidence: ['chargeRef' => $row->charge_ref, 'provider' => $row->provider],
                affectedEntities: ['charge:'.$row->charge_ref],
                repairAction: 'REVIEW_WITH_PROVIDER',
                dedupeKey: 'settle:dupe:'.$row->provider.':'.$row->charge_ref,
                title: 'تسويتان للرسم '.$row->charge_ref,
            );
        }

        // ── تسويةٌ بلا تحصيلٍ عندنا ────────────────────────────────────
        foreach ($this->orphanSettlements($limit) as $row) {
            $findings[] = new Finding(
                code: 'ORPHAN_SETTLEMENT',
                severity: 'CRITICAL',
                expected: ['capture' => 'exists'],
                actual: ['capture' => null],
                providerVerdict: 'SETTLED',
                evidence: ['settlementRef' => $row->settlement_ref, 'chargeRef' => $row->charge_ref,
                    'netMinor' => (int) $row->net_minor],
                affectedEntities: ['settlement#'.$row->id],
                /*
                 * مالٌ وصل الحساب ولا دفعة تقابله: إمّا خطّافٌ ضاع وإمّا طلبٌ
                 * لم يُسجَّل. إعادةُ تشغيل الخطّاف أوّل ما يُجرَّب.
                 */
                repairAction: 'REPLAY_WEBHOOK',
                dedupeKey: 'settle:orphan:'.$row->id,
                title: 'تسوية بلا تحصيل: '.$row->charge_ref,
            );
        }

        $settled = (int) DB::table('settlements')->whereNotNull('payment_id')->distinct()->count('payment_id');
        $capturedTotal = (int) DB::table('payments')->where('state', 'CAPTURED')->count();

        return [
            // `PARTIAL` تُعلن أنّ ما لم يُسوَّ قد يكون خارج المصدر لا عيبًا.
            'coverage' => $settled >= $capturedTotal ? 'FULL' : 'PARTIAL',
            'settledPayments' => $settled,
            'unsettledPayments' => max(0, $capturedTotal - $settled),
            'findings' => array_map(static fn (Finding $f) => $f->toArray(), $findings),
        ];
    }

    private function amountMismatches(int $limit): Collection
    {
        return DB::table('settlements as s')
            ->join('payments as p', 'p.id', '=', 's.payment_id')
            ->whereNotNull('p.amount_minor')
            ->whereColumn('s.gross_minor', '!=', 'p.amount_minor')
            ->limit($limit)
            ->get(['s.id', 's.gross_minor', 's.settlement_ref', 's.charge_ref', 'p.id as payment_id', 'p.amount_minor']);
    }

    private function feeAnomalies(int $limit): Collection
    {
        /*
         * عشرة في المئة سقفٌ متحفّظ: رسوم البطاقات في السوق أقلّ منه بكثير،
         * وتجاوزُه يعني خطأ إدخالٍ أو عملةً مختلفة أو رسمًا غير متوقّع.
         */
        return DB::table('settlements')
            ->whereRaw('fee_minor > gross_minor * 0.1')
            ->limit($limit)
            ->get(['id', 'fee_minor', 'gross_minor', 'settlement_ref']);
    }

    private function missingSettlements(int $limit): Collection
    {
        return DB::table('payments as p')
            ->where('p.state', 'CAPTURED')
            ->whereNotNull('p.captured_at')
            ->where('p.captured_at', '<', now()->subDays(self::SETTLEMENT_GRACE_DAYS))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('settlements as s')
                ->whereColumn('s.payment_id', 'p.id'))
            ->limit($limit)
            ->get(['p.id', 'p.intent_id', 'p.captured_at']);
    }

    private function duplicateSettlements(int $limit): Collection
    {
        return DB::table('settlements')
            ->select('provider', 'charge_ref', DB::raw('count(*) as copies'))
            ->groupBy('provider', 'charge_ref')
            ->havingRaw('count(*) > 1')
            ->limit($limit)
            ->get();
    }

    private function orphanSettlements(int $limit): Collection
    {
        return DB::table('settlements')
            ->whereNull('payment_id')
            ->limit($limit)
            ->get(['id', 'settlement_ref', 'charge_ref', 'net_minor']);
    }
}
