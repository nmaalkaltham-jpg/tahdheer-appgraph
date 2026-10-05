<?php

namespace App\Domain\Payments;

use App\Domain\Finance\Reconciliation;
use Illuminate\Support\Facades\DB;

/**
 * مقاييس صحّة الدفع — أرقامٌ يُنبَّه عليها لا يُبحث عنها في السجلّات.
 *
 * كلّ مقياسٍ هنا يقابل قرارًا بشريًّا أو إنذارًا: مالٌ لم يُموِّل، استردادٌ
 * غامض، نيّةٌ عالقة، تسليمٌ فشل. تُحسب من الدفتر لا من السجلّات، فلا تضيع
 * بدوران ملفّ سجلّ. بلا معرّفاتٍ شخصيّة ولا مفاتيح.
 */
final class PaymentHealth
{
    /** @return array<string, int|float|null> */
    public function snapshot(int $windowHours = 24): array
    {
        $since = now()->subHours($windowHours);

        $captures = DB::table('payments')->where('state', 'CAPTURED')->where('captured_at', '>=', $since);
        $deliveries = DB::table('webhook_deliveries')->where('received_at', '>=', $since);
        $oldestPending = DB::table('refunds')->where('status', 'PENDING')->min('created_at');

        return [
            'window_hours' => $windowHours,
            'captures_funded' => (clone $captures)->where('funding', 'FUNDED')->count(),
            'captures_unfundable' => (clone $captures)->where('funding', 'UNFUNDABLE')->count(),
            'captures_extra' => (clone $captures)->where('funding', 'EXTRA')->count(),
            'intents_failed' => DB::table('payments')->where('state', 'FAILED')->where('updated_at', '>=', $since)->count(),
            'intent_creation_failed' => DB::table('payments')->where('state', 'INTENT_FAILED')->where('updated_at', '>=', $since)->count(),
            'requery_recovered_captures' => DB::table('payment_events')->where('created_at', '>=', $since)
                ->where('payload->source', 'provider_requery')->count(),
            'webhooks_received' => (clone $deliveries)->count(),
            'webhooks_failed' => (clone $deliveries)->where('status', 'FAILED')->count(),
            'webhooks_mismatch' => (clone $deliveries)->where('status', 'MISMATCH')->count(),
            'webhooks_unmatched' => (clone $deliveries)->where('status', 'UNMATCHED')->count(),
            'webhooks_redelivered' => (int) (clone $deliveries)->sum(DB::raw('greatest(attempts - 1, 0)')),
            'refunds_succeeded' => DB::table('refunds')->where('status', 'SUCCEEDED')->where('settled_at', '>=', $since)->count(),
            'refunds_failed' => DB::table('refunds')->where('status', 'FAILED')->where('updated_at', '>=', $since)->count(),
            'refunds_unknown_open' => DB::table('refunds')->where('status', 'UNKNOWN')->count(),
            'refunds_pending_open' => DB::table('refunds')->where('status', 'PENDING')->count(),
            'refund_pending_oldest_minutes' => $oldestPending === null ? null : (int) now()->diffInMinutes($oldestPending, true),
            'refunds_from_provider_dashboard' => DB::table('refunds')->where('source', 'provider')->where('created_at', '>=', $since)->count(),
            'stale_unpaid_intents' => DB::table('payments')->where('state', 'CREATED')
                ->where('created_at', '<', now()->subMinutes(ProviderRequery::CHARGE_GRACE_MINUTES * 3))
                ->whereIn('order_id', DB::table('orders')->select('id')->whereIn('status', ['PENDING', 'AWAITING_PAYMENT']))
                ->count(),
        ];
    }

    /**
     * ما يستوجب إنذارًا الآن — بخطورةٍ تعكس خطر المال. فارغةٌ = لا إنذار.
     *
     * CRITICAL: مالٌ قد أُخذ أو خرج ولا يقابله الدفتر (يُعالَج الآن).
     * HIGH:     مالٌ معلَّق أو قرارٌ ينتظر مشغّلًا (يُعالَج اليوم).
     * WARNING:  مسارُ تعافٍ صامت (يُفحص).
     *
     * @param  array<string, int|float|null>  $m
     * @param  array<string, int>|null  $recon  أعداد التسوية؛ `null` = تُحسب هنا
     * @return list<array{code: string, severity: 'CRITICAL'|'HIGH'|'WARNING', value: int|float}>
     */
    public function alerts(array $m, ?array $recon = null): array
    {
        $recon ??= app(Reconciliation::class)->run()['counts'];

        $rules = [
            // مالٌ وصل ولم يُموِّل، أو أُخذ مرّتين، أو دفترٌ يخالف الواقع.
            ['captures_unfundable', 'CRITICAL', $m['captures_unfundable']],
            ['captures_extra', 'CRITICAL', $m['captures_extra']],
            ['unfunded_money_open', 'CRITICAL', $recon['orphanCapture'] ?? 0],
            ['duplicate_capture_open', 'CRITICAL', $recon['duplicateCapture'] ?? 0],
            ['refund_ledger_mismatch', 'CRITICAL', $recon['refundLedgerMismatch'] ?? 0],
            ['paid_without_fulfillment', 'CRITICAL', $recon['paidWithoutFulfillment'] ?? 0],
            ['entitlement_without_funding', 'CRITICAL', $recon['unauthorizedEntitlements'] ?? 0],
            ['funded_amount_mismatch', 'CRITICAL', $recon['amountMismatch'] ?? 0],
            ['webhooks_failed', 'CRITICAL', $m['webhooks_failed']],
            // مالٌ معلَّق أو قرارٌ ينتظر.
            ['refunds_unknown_open', 'HIGH', $m['refunds_unknown_open']],
            ['refund_pending_stale', 'HIGH', ($m['refund_pending_oldest_minutes'] ?? 0) > 60 ? $m['refund_pending_oldest_minutes'] : 0],
            ['dashboard_refunds_recorded', 'HIGH', $recon['providerRefunds'] ?? 0],
            ['webhooks_unmatched', 'HIGH', $m['webhooks_unmatched']],
            // مسار التعافي صامت: نيّاتٌ عالقة ولا خطّاف ولا سؤالٌ استعاد شيئًا.
            ['webhooks_or_requery_silent', 'WARNING',
                ($m['stale_unpaid_intents'] > 0 && $m['requery_recovered_captures'] === 0 && $m['webhooks_received'] === 0) ? $m['stale_unpaid_intents'] : 0],
        ];

        $alerts = [];
        foreach ($rules as [$code, $severity, $value]) {
            if ((int) $value > 0) {
                $alerts[] = ['code' => $code, 'severity' => $severity, 'value' => $value];
            }
        }

        return $alerts;
    }
}
