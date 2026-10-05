<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Orders\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * تسوية: ما أبلغ به المزوّد مقابل ما يقوله دفترنا (§١٧).
 *
 * ─── لماذا كانت مستحيلة ───
 *
 * `payments` كان بلا عمود مبلغ، فالحقيقة الماليّة الوحيدة هي
 * `orders.total_minor` — وهو **ما طُلب** لا **ما وصل**. ومقارنةُ رقمٍ بنفسه
 * ليست تسوية: تقول «كلّ شيء متطابق» دائمًا مهما اختلف الواقع.
 *
 * ─── لماذا «غير معروف» فئةٌ مستقلّة ───
 *
 * الدفعات التي سبقت تسجيل المبلغ لا نعرف ما أُبلغ به عنها. عدُّها متطابقةً
 * كذب، وعدُّها مختلفةً كذبٌ آخر. تُعدّ **مجهولة** ويُعلَن عددُها — فيعرف
 * المشغّل حدود ما تُثبته التسوية.
 *
 * ─── ما لا تفعله ───
 *
 * لا تُصلح شيئًا. كلّ فرقٍ هنا يحتاج قرارًا بشريًّا: ردّ الفرق، أو تصحيح
 * الطلب، أو مراجعة المزوّد. وإصلاحٌ آليّ للمال هو بالضبط ما يحوّل خطأً
 * واحدًا إلى سلسلة.
 */
final class Reconciliation
{
    public function __construct(private readonly SettlementReconciler $settlements) {}

    /** @return array<string, mixed> */
    public function run(int $limit = 100): array
    {
        $captured = DB::table('payments')->where('state', 'CAPTURED');

        $known = (clone $captured)->whereNotNull('amount_minor')->count();
        $unknown = (clone $captured)->whereNull('amount_minor')->count();

        $fundedOnly = fn ($q) => $q->whereNull('p.funding')->orWhere('p.funding', 'FUNDED');

        /*
         * تحصيلٌ **موَّل** بمبلغٍ غير مبلغ الطلب: خرقٌ لحارس التمويل نفسه.
         * المرفوض تمويلُه (المتوقَّع أن يختلف) يظهر في «مالٌ لم يُموِّل» أدناه
         * لا هنا — فلا حادثتان للصفّ الواحد.
         */
        $mismatch = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.state', 'CAPTURED')
            ->where($fundedOnly)
            ->whereNotNull('p.amount_minor')
            ->whereColumn('p.amount_minor', '!=', 'o.total_minor')
            ->limit($limit)
            ->get(['p.id as payment_id', 'p.intent_id', 'p.amount_minor', 'o.id as order_id', 'o.number', 'o.total_minor'])
            ->map(fn ($r) => [
                'paymentId' => (int) $r->payment_id,
                'orderId' => (int) $r->order_id,
                'orderNumber' => $r->number,
                'chargeId' => $r->intent_id,
                'gatewayMinor' => (int) $r->amount_minor,
                'orderMinor' => (int) $r->total_minor,
                'differenceMinor' => (int) $r->amount_minor - (int) $r->total_minor,
            ])->values()->all();

        /*
         * عملةٌ غير عملة المتجر: رقمان متطابقان ومبلغان مختلفان. يُكتشف
         * وحده لأنّ علاجه مختلف — لا يُردّ الفرق بل يُراجَع الإعداد.
         */
        $currency = DB::table('payments')
            ->where('state', 'CAPTURED')
            ->whereNotNull('currency')
            ->where('currency', '!=', 'SAR')
            ->limit($limit)
            ->get(['id', 'order_id', 'currency', 'amount_minor'])
            ->map(fn ($r) => [
                'paymentId' => (int) $r->id,
                'orderId' => (int) $r->order_id,
                'currency' => $r->currency,
                'amountMinor' => (int) $r->amount_minor,
            ])->values()->all();

        // تحصيلان على طلبٍ واحد: إمّا خطأُ مزوّد وإمّا مالٌ أُخذ مرّتين.
        $duplicates = DB::table('payments')
            ->select('order_id', DB::raw('count(*) as captures'), DB::raw('sum(amount_minor) as total'))
            ->where('state', 'CAPTURED')
            // تحصيلٌ زائد رُدّ كاملًا حُسم أمرُه: لا يبقى مالٌ مأخوذٌ مرّتين.
            ->where(fn ($q) => $q->whereNull('amount_minor')->orWhereColumn('refunded_minor', '<', 'amount_minor'))
            ->groupBy('order_id')
            ->havingRaw('count(*) > 1')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'orderId' => (int) $r->order_id,
                'captures' => (int) $r->captures,
                'totalMinor' => $r->total === null ? null : (int) $r->total,
            ])->values()->all();

        /*
         * طلبٌ مدفوع بلا تحصيل: التناقض في الاتّجاه المعاكس — سلّمنا بضاعةً
         * ولا سجلّ دفعٍ يقابلها.
         */
        $paidWithoutCapture = DB::table('orders as o')
            ->whereIn('o.status', OrderStatus::COLLECTED)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('payments as p')
                ->whereColumn('p.order_id', 'o.id')->where('p.state', 'CAPTURED'))
            ->limit($limit)
            ->get(['o.id', 'o.number', 'o.total_minor'])
            ->map(fn ($r) => [
                'orderId' => (int) $r->id,
                'orderNumber' => $r->number,
                'totalMinor' => (int) $r->total_minor,
            ])->values()->all();

        // تحصيلٌ على طلبٍ غير مدفوع: مالٌ وصل ولم يُسلَّم شيء مقابله.
        /*
         * مالٌ لم يُموِّل شيئًا: رُفض تمويله (UNFUNDABLE بسببه)، أو وصل على
         * طلبٍ لم يصل مالُه بلا قرار (صفوفٌ قديمة) — وما لم يُردّ منه كاملًا.
         */
        $orphanQuery = fn () => DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.state', 'CAPTURED')
            ->where(fn ($q) => $q->where('p.funding', 'UNFUNDABLE')
                ->orWhere(fn ($q) => $q->whereNull('p.funding')->whereNotIn('o.status', OrderStatus::EVER_COLLECTED)))
            ->where(fn ($q) => $q->whereNull('p.amount_minor')->orWhereColumn('p.refunded_minor', '<', 'p.amount_minor'));

        $orphan = $orphanQuery()
            ->limit($limit)
            ->get(['p.id', 'o.id as order_id', 'o.number', 'o.status', 'p.funding_reason', 'p.amount_minor', 'p.refunded_minor'])
            ->map(fn ($r) => [
                'paymentId' => (int) $r->id,
                'orderId' => (int) $r->order_id,
                'orderNumber' => $r->number,
                'orderStatus' => $r->status,
                'reason' => $r->funding_reason,
                'amountMinor' => $r->amount_minor === null ? null : (int) $r->amount_minor,
                'refundedMinor' => (int) $r->refunded_minor,
            ])->values()->all();

        /*
         * استردادٌ نُفِّذ من لوحة المزوّد مباشرةً: سُجِّل، لكنّ قرار
         * الاستحقاق (يُسحب أم يبقى) لم يتّخذه أحد. آخر ثلاثين يومًا.
         */
        $providerRefunds = DB::table('refunds as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.source', 'provider')
            ->where('r.created_at', '>', now()->subDays(30))
            ->limit($limit)
            ->get(['r.id', 'r.amount_minor', 'r.scope', 'r.provider_refund_id', 'o.id as order_id', 'o.number'])
            ->map(fn ($r) => [
                'refundId' => (int) $r->id,
                'amountMinor' => (int) $r->amount_minor,
                'scope' => $r->scope,
                'providerRefundId' => $r->provider_refund_id,
                'orderId' => (int) $r->order_id,
                'orderNumber' => $r->number,
            ])->values()->all();

        /*
         * دفترُ الطلب يخالف صفوف الاسترداد: «مستردّ» بمبلغٍ لا تسنده
         * استردادات محسومة (أو العكس). الطلب يقول للزبون شيئًا لم يقع.
         */
        $ledgerQuery = fn () => DB::table('orders as o')
            ->leftJoinSub(
                DB::table('refunds')->where('scope', 'order')->whereIn('status', ['SUCCEEDED', 'MANUAL'])
                    ->groupBy('order_id')->select('order_id', DB::raw('sum(amount_minor) as settled')),
                's', 's.order_id', '=', 'o.id',
            )
            ->whereRaw('o.refunded_total_minor <> coalesce(s.settled, 0)');

        $refundLedgerMismatch = $ledgerQuery()
            ->limit($limit)
            ->get(['o.id', 'o.number', 'o.status', 'o.refunded_total_minor', DB::raw('coalesce(s.settled, 0) as settled')])
            ->map(fn ($r) => [
                'orderId' => (int) $r->id,
                'orderNumber' => $r->number,
                'orderStatus' => $r->status,
                'ledgerMinor' => (int) $r->refunded_total_minor,
                'settledMinor' => (int) $r->settled,
            ])->values()->all();

        // مالٌ وصل ولا وحدة تنفيذٍ واحدة: دفع الزبون ولم يستلم شيئًا.
        $paidWithoutFulfillmentQuery = fn () => DB::table('orders as o')
            ->whereIn('o.status', OrderStatus::COLLECTED)
            ->where('o.paid_at', '<', now()->subMinutes(10))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('order_items as i')->whereColumn('i.order_id', 'o.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('fulfillment_units as u')->whereColumn('u.order_id', 'o.id'));

        $paidWithoutFulfillment = $paidWithoutFulfillmentQuery()
            ->limit($limit)
            ->get(['o.id', 'o.number', 'o.total_minor'])
            ->map(fn ($r) => ['orderId' => (int) $r->id, 'orderNumber' => $r->number, 'totalMinor' => (int) $r->total_minor])
            ->values()->all();

        /*
         * استردادٌ يتجاوز التحصيل المُبلَّغ. القيد يمنعه مقابل `total_minor`،
         * وهذا يقيسه مقابل ما أبلغ به المزوّد فعلًا — وهما قد يختلفان.
         */
        $overRefund = DB::table('orders as o')
            ->join('payments as p', function ($join) {
                $join->on('p.order_id', '=', 'o.id')->where('p.state', '=', 'CAPTURED');
            })
            ->whereNotNull('p.amount_minor')
            ->where('o.refunded_total_minor', '>', 0)
            ->whereColumn('o.refunded_total_minor', '>', 'p.amount_minor')
            ->limit($limit)
            ->get(['o.id', 'o.number', 'o.refunded_total_minor', 'p.amount_minor'])
            ->map(fn ($r) => [
                'orderId' => (int) $r->id,
                'orderNumber' => $r->number,
                'refundedMinor' => (int) $r->refunded_total_minor,
                'capturedMinor' => (int) $r->amount_minor,
            ])->values()->all();

        /*
         * استحقاقٌ بلا إذنٍ تجاريّ: منتجٌ سُلّم عن طلبٍ لم يصل مالُه.
         *
         * الحارس في `EntitlementService` يمنع الجديد، وهذا يكشف ما وقع قبله.
         * المنعُ وحده لا يكفي: الملفّ الذي نُزّل لا يعود، فمعرفةُ من أخذه
         * هي كلّ ما بقي.
         */
        $unauthorizedEntitlements = DB::table('entitlements as e')
            ->join('orders as o', 'o.id', '=', 'e.order_id')
            ->whereNotIn('o.status', OrderStatus::EVER_COLLECTED)
            ->limit($limit)
            ->get(['e.id', 'e.customer_id', 'o.id as order_id', 'o.number', 'o.status'])
            ->map(fn ($r) => [
                'entitlementId' => (int) $r->id,
                'customerId' => (int) $r->customer_id,
                'orderId' => (int) $r->order_id,
                'orderNumber' => $r->number,
                'orderStatus' => $r->status,
            ])->values()->all();

        /*
         * الحلقة الأخيرة: تحصيل ← تسوية ← رسوم ← صافٍ.
         *
         * منفصلةٌ لأنّها تعتمد مصدرًا خارجيًّا قد لا يكون مربوطًا، ودمجُها
         * كان سيجعل غياب المصدر يبدو عيبًا في الدفتر.
         */
        $settlement = $this->settlements->run($limit);

        /*
         * نجاحٌ عند المزوّد وفشلٌ عندنا: خطّافٌ وصل موقَّعًا ولم تكتمل معالجته.
         * أخطر أنواع الفرق لأنّ المال تحرّك والأثر لم يقع — ويُكشف من
         * التسليمات لا من المدفوعات، إذ لا دفعة تُنشأ أصلًا.
         */
        $providerSuccessLocalFailure = DB::table('webhook_deliveries')
            ->where('signature_verified', true)
            ->where('succeeded', true)
            ->whereIn('status', ['FAILED', 'UNMATCHED'])
            ->limit($limit)
            ->get(['id', 'provider', 'charge_id', 'order_number', 'status', 'last_error'])
            ->map(fn ($r) => [
                'deliveryId' => (int) $r->id,
                'provider' => $r->provider,
                'chargeId' => $r->charge_id,
                'orderNumber' => $r->order_number,
                'status' => $r->status,
                'lastError' => $r->last_error,
            ])->values()->all();

        /*
         * استردادٌ لم يُحسم: انقطع ردّ المزوّد (`UNKNOWN`) أو قبِله ولم يؤكّده
         * بخطّافه في مهلةٍ معقولة (`PENDING` أقدم من ساعة). المال محجوز وربّما
         * خرج — ولا يُعرف من الدفتر وحده. يُحسم بإعادة السؤال أو بمراجعة
         * لوحة المزوّد، لا بالنسيان.
         */
        $refundUnresolved = DB::table('refunds as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->where(fn ($q) => $q->where('r.status', 'UNKNOWN')
                ->orWhere(fn ($q) => $q->where('r.status', 'PENDING')->where('r.created_at', '<', now()->subHour())))
            ->limit($limit)
            ->get(['r.id', 'r.status', 'r.amount_minor', 'r.provider', 'r.provider_refund_id', 'r.provider_error', 'r.created_at', 'o.id as order_id', 'o.number'])
            ->map(fn ($r) => [
                'refundId' => (int) $r->id,
                'status' => $r->status,
                'amountMinor' => (int) $r->amount_minor,
                'provider' => $r->provider,
                'providerRefundId' => $r->provider_refund_id,
                'error' => $r->provider_error,
                'orderId' => (int) $r->order_id,
                'orderNumber' => $r->number,
            ])->values()->all();

        /*
         * الأعداد الكاملة بجانب القوائم المقتطعة.
         *
         * القائمة محدودة بـ`$limit` لئلّا تُحمَّل الشاشة ألف صفّ. ولو عُرض
         * طولُها عددًا لقال «١٠٠» عن ١٢٣ — فيبدو الخلل أصغر ممّا هو. حدث
         * هذا فعلًا عند أوّل تشغيلٍ على بيانات حقيقيّة.
         */
        $counts = [
            'amountMismatch' => (int) DB::table('payments as p')
                ->join('orders as o', 'o.id', '=', 'p.order_id')
                ->where('p.state', 'CAPTURED')->where($fundedOnly)->whereNotNull('p.amount_minor')
                ->whereColumn('p.amount_minor', '!=', 'o.total_minor')->count(),
            'currencyMismatch' => (int) DB::table('payments')
                ->where('state', 'CAPTURED')->whereNotNull('currency')
                ->where('currency', '!=', 'SAR')->count(),
            'duplicateCapture' => (int) DB::table(DB::raw('(select order_id from payments where state = \'CAPTURED\' and (amount_minor is null or refunded_minor < amount_minor) group by order_id having count(*) > 1) as d'))->count(),
            'paidWithoutCapture' => (int) DB::table('orders as o')
                ->whereIn('o.status', OrderStatus::COLLECTED)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('payments as p')
                    ->whereColumn('p.order_id', 'o.id')->where('p.state', 'CAPTURED'))->count(),
            'orphanCapture' => (int) $orphanQuery()->count(),
            'refundLedgerMismatch' => (int) $ledgerQuery()->count(),
            'providerRefunds' => count($providerRefunds),
            /*
             * الاتّجاه الذي يراه الزبون: مالٌ وصل ولا وحدةَ تنفيذٍ واحدة —
             * تثبيتٌ التزم بالدفع وسقط قبل التنفيذ. الحارس السابق كان يكشف
             * استحقاقًا بلا مال، لا مالًا بلا استحقاق.
             */
            'paidWithoutFulfillment' => (int) $paidWithoutFulfillmentQuery()->count(),
            'refundExceedsCapture' => count($overRefund),
            'refundUnresolved' => (int) DB::table('refunds')
                ->where(fn ($q) => $q->where('status', 'UNKNOWN')
                    ->orWhere(fn ($q) => $q->where('status', 'PENDING')->where('created_at', '<', now()->subHour())))
                ->count(),
            'settlementFindings' => count($settlement['findings']),
            'providerSuccessLocalFailure' => (int) DB::table('webhook_deliveries')
                ->where('signature_verified', true)->where('succeeded', true)
                ->whereIn('status', ['FAILED', 'UNMATCHED'])->count(),
            'unauthorizedEntitlements' => (int) DB::table('entitlements as e')
                ->join('orders as o', 'o.id', '=', 'e.order_id')
                ->whereNotIn('o.status', OrderStatus::EVER_COLLECTED)
                ->count(),
        ];

        return [
            'capturedPayments' => $known + $unknown,
            'settlement' => $settlement,
            'providerSuccessLocalFailure' => $providerSuccessLocalFailure,
            'counts' => $counts,
            'knownAmount' => $known,
            // حدّ ما تُثبته التسوية — يُعلَن ولا يُطوى في «متطابق».
            'unknownAmount' => $unknown,
            'amountMismatch' => $mismatch,
            'currencyMismatch' => $currency,
            'duplicateCapture' => $duplicates,
            'paidWithoutCapture' => $paidWithoutCapture,
            'orphanCapture' => $orphan,
            'refundExceedsCapture' => $overRefund,
            'refundUnresolved' => $refundUnresolved,
            'refundLedgerMismatch' => $refundLedgerMismatch,
            'providerRefunds' => $providerRefunds,
            'paidWithoutFulfillment' => $paidWithoutFulfillment,
            'unauthorizedEntitlements' => $unauthorizedEntitlements,
            // الحكم من الأعداد الكاملة لا من القوائم المقتطعة.
            'clean' => array_sum($counts) === 0,
        ];
    }
}
