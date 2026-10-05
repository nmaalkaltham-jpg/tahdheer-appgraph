<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Finance\Reconciliation;
use App\Domain\Orders\Models\Refund;
use App\Domain\Orders\RefundExceedsCaptured;
use App\Domain\Orders\RefundFailed;
use App\Domain\Orders\RefundIdempotencyConflict;
use App\Domain\Orders\RefundService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\ProviderDiagnostics;
use App\Domain\Payments\RefundOutcomeUnknown;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * سجلّ المدفوعات والمستردّات.
 *
 * ─── لماذا هذه الشاشة ───
 *
 * «هل وصل المال؟» و«هل يطابق ما وصل ما نُسجّله؟» سؤالان يُطرحان يوميًّا،
 * وكان جوابهما يُقرأ من قاعدة البيانات يدويًّا.
 *
 * ─── أخطر رقمٍ في الصفحة ───
 *
 * `capturedButUnpaidOrders`: دفعةٌ حُصّلت عند المزوّد وطلبُها ليس مدفوعًا
 * عندنا. هذا مالٌ أُخذ من زبونٍ لم يستلم شيئًا — ولا يُصدر عطلًا ولا سجلًّا
 * ولا شكوى حتّى يكتشفه الزبون. ويُعدّ هنا صراحةً لأنّه لا يُرى في أيّ رقم
 * آخر: الطلبات تبدو سليمة، والمدفوعات تبدو سليمة، والتناقض بينهما وحده.
 *
 * ─── الحمولة تُنقّى ───
 *
 * أحداث البوّابة تحمل حمولة المزوّد كما وصلت، وفيها ما لا يُعرض لمشغّل:
 * بطاقات، ورموز، ومعرّفات عملاء عند المزوّد. تُنقّى بقائمة سماحٍ صريحة —
 * لا بقائمة منعٍ يفوتها كلّ حقلٍ جديد يضيفه المزوّد.
 */
class PaymentLedgerController extends Controller
{
    /**
     * حقولٌ يُسمح بعرضها من حمولة المزوّد.
     *
     * قائمةُ سماحٍ لا قائمة منع: المزوّد يضيف حقولًا بلا إعلامنا، وقائمة
     * المنع تفوتها كلّها بصمت.
     */
    private const SAFE_PAYLOAD_KEYS = [
        'status', 'amount', 'currency', 'charge_id', 'order_number',
        'captured', 'reference', 'response_code', 'message', 'simulated',
    ];

    public function transactions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => 'sometimes|string|max:32',
            'q' => 'sometimes|string|max:191',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = Payment::query()
            ->with('order:id,number,status,total_minor')
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->where('state', $state))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                // البحث يشمل رقم الطلب: المشغّل يبدأ منه لا من معرّف البوّابة.
                $q->where(fn ($inner) => $inner
                    ->where('intent_id', 'ilike', $like)
                    ->orWhereHas('order', fn ($o) => $o->where('number', 'ilike', $like)));
            });

        $paginator = $query->orderByDesc('id')->paginate(
            perPage: (int) ($filters['perPage'] ?? 50),
            page: (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'data' => $paginator->getCollection()->map($this->present(...))->values(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
            'filters' => [
                'states' => Payment::query()->distinct()->orderBy('state')->pluck('state')->all(),
                'providers' => Payment::query()->distinct()->orderBy('provider')->pluck('provider')->all(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    public function transaction(int $id): JsonResponse
    {
        $payment = Payment::query()->with('order:id,number,status,total_minor')->find($id);

        if ($payment === null) {
            return response()->json(['message' => 'لا عمليّة دفعٍ بهذا المعرّف.'], 404);
        }

        $events = PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentEvent $e) => [
                'id' => $e->id,
                'eventType' => $e->event_type,
                'at' => $e->created_at?->toIso8601String(),
                'payload' => $this->scrub((array) $e->payload),
            ])->values();

        return response()->json([
            'data' => $this->present($payment) + ['events' => $events],
        ]);
    }

    public function refunds(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ]);

        $paginator = DB::table('refunds')
            ->leftJoin('orders', 'orders.id', '=', 'refunds.order_id')
            ->orderByDesc('refunds.id')
            ->select([
                'refunds.id', 'refunds.order_id', 'refunds.amount_minor', 'refunds.reason',
                'refunds.status', 'refunds.provider', 'refunds.provider_refund_id', 'refunds.provider_error', 'refunds.settled_at',
                'refunds.revocation_policy', 'refunds.notified_at', 'refunds.created_at',
                'orders.number as order_number',
            ])
            ->paginate(
                perPage: (int) ($filters['perPage'] ?? 50),
                page: (int) ($filters['page'] ?? 1),
            );

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($row) => [
                'id' => (int) $row->id,
                'orderId' => (int) $row->order_id,
                'orderNumber' => $row->order_number,
                'amountMinor' => (int) $row->amount_minor,
                'status' => $row->status,
                'provider' => $row->provider,
                'providerRefundId' => $row->provider_refund_id,
                'providerError' => $row->provider_error,
                'settledAt' => $row->settled_at === null
                    ? null
                    : Carbon::parse($row->settled_at)->toIso8601String(),
                'reason' => $row->reason,
                'policy' => $row->revocation_policy,
                'notifiedAt' => $row->notified_at === null
                    ? null
                    : Carbon::parse($row->notified_at)->toIso8601String(),
                'at' => $row->created_at === null
                    ? null
                    : Carbon::parse($row->created_at)->toIso8601String(),
            ])->values(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
            'summary' => [
                'refunds' => (int) DB::table('refunds')->count(),
                // ما خرج فعلًا: المرفوض لم يخرج، والمعلَّق لم يُحسم بعد.
                'totalMinor' => (int) DB::table('refunds')->whereIn('status', Refund::SETTLED)->sum('amount_minor'),
                /*
                 * غير محسوم: ينتظر المزوّد أو انقطع ردُّه. مالٌ محجوز قد يكون
                 * خرج — كلّ صفٍّ هنا يحتاج عينًا حتّى يُحسم.
                 */
                'unresolved' => (int) DB::table('refunds')->whereIn('status', Refund::OPEN)->count(),
                'unresolvedMinor' => (int) DB::table('refunds')->whereIn('status', Refund::OPEN)->sum('amount_minor'),
                /*
                 * مستردٌّ لم يُبلَّغ به العميل: المال عاد ولا يعلم. يُعدّ
                 * لأنّه يُنتج شكوى «أين مالي؟» عن مشكلةٍ حُلّت سلفًا.
                 */
                'unnotified' => (int) DB::table('refunds')->whereNull('notified_at')->count(),
            ],
        ]);
    }

    /**
     * التسوية: ما أبلغ به المزوّد مقابل ما يقوله دفترنا.
     *
     * قراءةٌ محضة ولا تُصلح شيئًا: كلّ فرقٍ هنا يحتاج قرارًا بشريًّا،
     * وإصلاحٌ آليّ للمال يحوّل خطأً واحدًا إلى سلسلة.
     *
     * والسلسلة كاملة: بوّابة ← دفعة ← طلب ← تحصيل ← تسوية ← رسوم ← استرداد
     * ← استحقاق. وكلّ فرقٍ يحمل المتوقَّع والفعليّ وحكم المزوّد والدليل
     * والكيانات المتأثّرة وفعل الإصلاح.
     */
    public function reconciliation(Reconciliation $reconciliation): JsonResponse
    {
        return response()->json($reconciliation->run());
    }

    /**
     * حالة مزوّد الدفع — ولا سرّ يخرج منها.
     *
     * تفرّق بين **المضبوط** و**المحلول**: `PAYMENTS_PROVIDER=fake` في إنتاجٍ
     * يسقط إلى Tap الحيّ، فالإعداد يقول «وهميّ» والذي يقبض حقيقيّ. وإخفاء
     * الفرق يجعل المشغّل يظنّ أنّه يجرّب بينما مالٌ حقيقيّ يتحرّك.
     */
    public function provider(ProviderDiagnostics $diagnostics): JsonResponse
    {
        return response()->json($diagnostics->status());
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $byState = Payment::query()
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state')
            ->map(fn ($v) => (int) $v)
            ->all();

        $transactions = array_sum($byState);
        $failed = (int) ($byState['FAILED'] ?? 0);

        return [
            'transactions' => $transactions,
            'byState' => $byState,
            // لا مقام فلا نسبة: صفرٌ هنا كان سيُقرأ «لا فشل» وهو «لا بيانات».
            'failureRate' => $transactions === 0 ? null : round($failed / $transactions, 4),
            'gatewayEvents' => (int) PaymentEvent::query()->count(),
            'capturedButUnpaidOrders' => (int) Payment::query()
                ->where('state', 'CAPTURED')
                ->whereHas('order', fn ($q) => $q->where('status', '!=', 'PAID'))
                ->count(),
        ];
    }

    /**
     * POST /admin/payments/transactions/{id}/refund — ردُّ مالٍ لم يُموِّل طلبًا.
     *
     * تحصيلٌ مرفوض (مبلغ/عملة/طلبٌ مُلغى) أو زائد (دُفع الطلب مرّتين): المال
     * عند المتجر ولا يقابله شيء. يُردّ عبر المزوّد بالمسار المحروس نفسه
     * (حجز · مفتاح خمول · حسم) وسقفُه ما قبضه هذا التحصيل وحده.
     */
    public function refundCapture(Request $request, AdminUser $actor, RefundService $refunds, AdminAuditLogger $audit, int $id): JsonResponse
    {
        $payment = Payment::query()->find($id);
        if ($payment === null) {
            return response()->json(['message' => 'لا عمليّة دفعٍ بهذا المعرّف.'], 404);
        }

        $data = $request->validate([
            'amountMinor' => 'required|integer|min:1',
            'reason' => 'required|string|min:3|max:255',
            'idempotencyKey' => 'required|string|min:8|max:120',
        ], AdminValidation::MESSAGES);

        $before = ['funding' => $payment->funding, 'refundedMinor' => (int) $payment->refunded_minor];

        try {
            $refund = $refunds->refundCapture($payment, (int) $data['amountMinor'], $data['reason'], $data['idempotencyKey']);
        } catch (RefundIdempotencyConflict $e) {
            return response()->json(['message' => 'المفتاح نفسه أُرسل بطلبٍ مختلف.', 'code' => 'IDEMPOTENCY_CONFLICT', 'refundId' => $e->existingRefundId], 409);
        } catch (RefundExceedsCaptured $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'REFUND_EXCEEDS_CAPTURED', 'remainingMinor' => $e->remainingMinor()], 422);
        } catch (RefundFailed $e) {
            $audit->log($actor, 'payment.capture_refund_rejected', 'payment', $payment->id, $before, ['error' => $e->getMessage()], $data['reason'], $request);

            return response()->json(['message' => $e->getMessage(), 'code' => 'REFUND_PROVIDER_REJECTED'], 422);
        } catch (RefundOutcomeUnknown $e) {
            $audit->log($actor, 'payment.capture_refund_unresolved', 'payment', $payment->id, $before, ['error' => $e->getMessage()], $data['reason'], $request);

            return response()->json(['message' => 'لم يصل ردٌّ من مزوّد الدفع. المبلغ محجوز ولن يُردّ مرّتين — أعد المحاولة لاحقًا.', 'code' => 'REFUND_OUTCOME_UNKNOWN'], 502);
        }

        $payment->refresh();
        $audit->log(
            $actor, 'payment.capture_refunded', 'payment', $payment->id, $before,
            ['refundId' => $refund->id, 'status' => $refund->status, 'refundedMinor' => (int) $payment->refunded_minor],
            $data['reason'], $request,
        );

        return response()->json(['data' => [
            'refundId' => $refund->id,
            'status' => $refund->status,
            'amountMinor' => (int) $refund->amount_minor,
            'payment' => $this->present($payment->load('order:id,number,status,total_minor')),
        ]], 201);
    }

    /** @return array<string, mixed> */
    private function present(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'provider' => $payment->provider,
            'intentId' => $payment->intent_id,
            'method' => $payment->method,
            'instrument' => $payment->masked_instrument,
            'state' => $payment->state,
            /*
             * الحقيقة الماليّة بجانب حالة الطلب لا مكانها: المتوقَّع، وما
             * قبضه المزوّد، وقرار التمويل وسببه، وما رُدّ منه وما حُجز.
             */
            'amountMinor' => $payment->amount_minor === null ? null : (int) $payment->amount_minor,
            'currency' => $payment->currency,
            'funding' => $payment->funding,
            'fundingReason' => $payment->funding_reason,
            'refundedMinor' => (int) $payment->refunded_minor,
            'refundReservedMinor' => (int) $payment->refund_reserved_minor,
            'refundableMinor' => in_array($payment->funding, ['UNFUNDABLE', 'EXTRA'], true) && $payment->amount_minor !== null
                ? max(0, (int) $payment->amount_minor - (int) $payment->refunded_minor - (int) $payment->refund_reserved_minor)
                : null,
            'orderId' => $payment->order_id,
            'orderNumber' => $payment->order?->number,
            'orderStatus' => $payment->order?->status,
            'orderTotalMinor' => $payment->order?->total_minor,
            'at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scrub(array $payload): array
    {
        return array_intersect_key($payload, array_flip(self::SAFE_PAYLOAD_KEYS));
    }
}
