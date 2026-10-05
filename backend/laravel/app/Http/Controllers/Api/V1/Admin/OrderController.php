<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\RefundExceedsCaptured;
use App\Domain\Orders\RefundFailed;
use App\Domain\Orders\RefundIdempotencyConflict;
use App\Domain\Orders\RefundService;
use App\Domain\Payments\RefundOutcomeUnknown;
use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Orders console (MIP §8.1). Refunds delegate to the existing
 * App\Domain\Orders\RefundService — the refund row, the order status
 * transition, entitlement revocation and the `refund.completed` outbox
 * record are all its responsibility and are never duplicated here.
 */
class OrderController extends Controller
{
    /*
     * يبقى الاسم لأنّ له مستهلكين، ولم يعد يملك الحقيقة: `OrderStatus`
     * يملكها. وكان التعليق يقول «مرآةٌ لـEntitlementService» — ومرآةٌ تُصان
     * باليد تنكسر عند أوّل تعديلٍ يُنسى.
     */
    public const PAID_STATUSES = OrderStatus::COLLECTED;

    /** A partially refunded order still has a remaining refundable balance. */
    /*
     * قائمةٌ رابعة زالت: «يجوز ردٌّ إضافيّ» ليس حالةً بل حالةً **وبقيّةً**.
     * وكتابتُه قائمةً كان يعرض الزرّ على طلبٍ استُرد كاملًا ثمّ يرفضه النطاق.
     */

    private const STATUSES = OrderStatus::ALL;

    /** طلبات تنتظر فعلًا بشريًّا — مرآة `OperationsSummaryController`. */
    public const NEEDS_ACTION_STATUSES = ['PENDING', 'AWAITING_PAYMENT', 'PROCESSING'];

    /**
     * عدساتٌ مسمّاة — الوجهة التي يهبط عليها رقمٌ في لوحة التشغيل.
     *
     * ─── لماذا عدسةٌ لا مرشّح حالةٍ ثانٍ ───
     *
     * «إجمالي المبيعات» يُحسب على ثلاث حالات لا واحدة، و«المستردّ» ليس حالةً
     * أصلًا بل عمودَ مبلغ، و«لم يُسلَّم» غيابُ صفٍّ في جدولٍ آخر. فلا واحدةٌ
     * منها يعبّر عنها `?status=`، وربطُ بطاقةٍ برابطٍ يعرض مجموعةً غير التي
     * كوّنت رقمها أسوأ من تركها بلا رابط: المشغّل يعدّ الصفوف فلا تطابق
     * العنوان، فيفقد الثقة في اللوحة كلّها.
     *
     * القائمة مغلقة: ما ليس فيها يُرَدّ ٤٢٢ لا يُمرَّر إلى SQL.
     */
    private const VIEWS = ['paid', 'needsAction', 'refunded', 'undelivered', 'unfulfilled'];

    public function __construct(private readonly AdminAuditLogger $audit) {}

    /**
     * تطبيق عدسةٍ مسمّاة على الاستعلام القائم.
     *
     * «لم يُسلَّم» و«لم يُنفَّذ» غيابُ صفٍّ لا وجوده، فيُكتبان `whereNotExists`
     * لا بوصلةٍ خارجيّة: الوصلة الخارجيّة تضاعف الصفوف حين يكون للطلب أكثر
     * من صلاحيّة، فيقول العدّ رقمًا أكبر من عدد الطلبات نفسها.
     *
     * @param  Builder<Order>  $query
     */
    private function applyView($query, string $view): void
    {
        match ($view) {
            'paid' => $query->whereIn('status', self::PAID_STATUSES),
            'needsAction' => $query->whereIn('status', self::NEEDS_ACTION_STATUSES),
            'refunded' => $query->where('refunded_total_minor', '>', 0),
            'undelivered' => $query
                ->whereIn('status', self::PAID_STATUSES)
                ->whereNotExists(fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from('entitlements')
                    ->whereColumn('entitlements.order_id', 'orders.id')),
            'unfulfilled' => $query
                ->whereIn('status', self::PAID_STATUSES)
                ->whereNotExists(fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from('fulfillment_units')
                    ->whereColumn('fulfillment_units.order_id', 'orders.id')),
        };
    }

    /**
     * أعمدة الفرز المسموحة — قائمةٌ بيضاء لا اسمٌ يأتي من العميل.
     *
     * تمريرُ اسم العمود كما ورد يفتح الباب لعمودٍ لا وجود له (خطأُ خادمٍ
     * يُعرض للمستعمل) ولعمودٍ لا يجوز الفرز به. والمفتاح المعروض يختلف عن
     * اسم العمود عمدًا: الواجهة لا يجب أن تعرف مخطَّط الجدول.
     */
    private const SORTABLE = [
        'number' => 'number',
        'status' => 'status',
        'total' => 'total_minor',
        'createdAt' => 'created_at',
        'paidAt' => 'paid_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'view' => ['sometimes', Rule::in(self::VIEWS)],
            'from' => 'sometimes|date',
            'to' => 'sometimes|date',
            'q' => 'sometimes|string|max:200',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
            'sort' => ['sometimes', Rule::in(array_keys(self::SORTABLE))],
            'dir' => ['sometimes', Rule::in(['asc', 'desc'])],
        ], AdminValidation::MESSAGES);

        $query = Order::query();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['view'])) {
            $this->applyView($query, $filters['view']);
        }
        /*
         * المدى يُقصّ على `created_at` — العمود نفسه الذي يقصّ عليه ملخّص
         * اللوحة، وإلّا لَعرض الرابط مجموعةً غير التي حُسب منها المؤشّر.
         * و`to` تُمدّ إلى آخر اليوم: تاريخٌ بلا وقتٍ يعني منتصف ليله، فكان
         * «إلى الخامس عشر» يُسقط طلبات الخامس عشر كلّها.
         */
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $needle = '%'.trim($filters['q']).'%';
            $query->where(fn ($q) => $q->where('number', 'like', $needle)->orWhere('email', 'like', $needle));
        }

        /*
         * عدّ الحالات يُحسب على البحث **دون** مرشّح الحالة.
         *
         * وإلّا لَأظهر تبويبُ «مدفوع» رقمه وحده وأصفارًا في البقيّة — فيبدو
         * المتجر بلا طلباتٍ في الحالات الأخرى بينما هي مُخفاةٌ بالمرشّح.
         */
        $countQuery = Order::query();
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $needle = '%'.trim($filters['q']).'%';
            $countQuery->where(fn ($q) => $q->where('number', 'like', $needle)->orWhere('email', 'like', $needle));
        }
        /*
         * العدسة والمدى يُطبَّقان على العدّ أيضًا — بخلاف الحالة وحدها.
         *
         * التبويبات بُعد الحالة، فإسقاطها من العدّ هو المقصود. أمّا العدسة
         * والمدى فبُعدٌ آخر: تركُهما يجعل التبويبات تعدّ الطلبات كلّها بينما
         * الجدول تحتها يعرض مدًى واحدًا، فيقرأ المشغّل «١٢٣ مكتمل» فوق ثلاثة
         * صفوف.
         */
        if (isset($filters['view'])) {
            $this->applyView($countQuery, $filters['view']);
        }
        if (isset($filters['from'])) {
            $countQuery->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (isset($filters['to'])) {
            $countQuery->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }
        $statusCounts = array_fill_keys(self::STATUSES, 0);
        foreach ($countQuery->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status') as $status => $total) {
            $statusCounts[(string) $status] = (int) $total;
        }

        /*
         * `id` مفتاحُ قطعٍ ثانويّ دائمًا.
         *
         * الفرز بعمودٍ متكرّر القيم (الحالة مثلًا) بلا قاطعٍ ثانويّ ترتيبٌ
         * غير محدَّد في PostgreSQL: الصفّ نفسه قد يظهر في الصفحة الأولى
         * والثانية معًا، وقد يختفي آخر بينهما.
         */
        $column = self::SORTABLE[$filters['sort'] ?? ''] ?? null;
        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($column === null) {
            $query->orderByDesc('id');
        } else {
            $query->orderBy($column, $direction)->orderByDesc('id');
        }

        $paginator = $query->paginate(
            perPage: (int) ($filters['perPage'] ?? 20),
            page: (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Order $o) => $this->row($o))->values(),
            'meta' => AdminValidation::meta($paginator),
            'filters' => ['statuses' => self::STATUSES],
            'statusCounts' => $statusCounts,
            'sortable' => array_keys(self::SORTABLE),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $order = Order::query()->with('items')->findOrFail($id);

        $refunds = DB::table('refunds')->where('order_id', $order->id)->orderBy('id')->get();
        $payments = DB::table('payments')->where('order_id', $order->id)->orderBy('id')->get();

        return response()->json([
            'data' => $this->row($order) + [
                'phone' => $order->phone,
                'taxOrgName' => $order->tax_org_name,
                'taxVatNumber' => $order->tax_vat_number,
                'marketingOptIn' => (bool) $order->marketing_opt_in,
                'items' => $order->items->map(fn (OrderItem $i) => [
                    'id' => $i->id,
                    'productId' => $i->product_id,
                    'title' => $i->title_snapshot,
                    'priceMinor' => (int) $i->price_snapshot_minor,
                    'versionLabel' => $i->version_label,
                ])->values(),
                'payments' => $payments->map(fn ($p) => [
                    'id' => $p->id,
                    'provider' => $p->provider,
                    'method' => $p->method,
                    'maskedInstrument' => $p->masked_instrument,
                    'state' => $p->state,
                ])->values(),
                'refunds' => $refunds->map(fn ($r) => [
                    'id' => $r->id,
                    'amountMinor' => (int) $r->amount_minor,
                    'status' => $r->status,
                    'provider' => $r->provider,
                    'providerRefundId' => $r->provider_refund_id,
                    'providerError' => $r->provider_error,
                    'reason' => $r->reason,
                    'policy' => $r->revocation_policy,
                    'createdAt' => $r->created_at === null ? null : Carbon::parse($r->created_at)->toIso8601String(),
                    'settledAt' => $r->settled_at === null ? null : Carbon::parse($r->settled_at)->toIso8601String(),
                ])->values(),
                /*
                 * المحسوم وحده «مستردّ». والمحجوز (ينتظر المزوّد) يُعرض
                 * مستقلًّا ويُخصم من القابل للردّ — لا يُردّ فوقه.
                 */
                'refundedMinor' => (int) $order->refunded_total_minor,
                'refundReservedMinor' => (int) $order->refund_reserved_minor,
                'refundableMinor' => max(0, (int) $order->total_minor - (int) $order->refunded_total_minor - (int) $order->refund_reserved_minor),
                'entitlements' => Entitlement::query()->where('order_id', $order->id)->get()
                    ->map(fn (Entitlement $e) => [
                        'id' => $e->id,
                        'productId' => $e->product_id,
                        'status' => $e->status,
                        'downloadCount' => (int) $e->download_count,
                        'revokedAt' => $e->revoked_at?->toIso8601String(),
                    ])->values(),
            ],
        ]);
    }

    public function refund(Request $request, AdminUser $actor, RefundService $refunds, int $id): JsonResponse
    {
        $order = Order::query()->findOrFail($id);

        /*
         * «وصل المال **وبقي منه شيء**» — لا قائمةُ حالاتٍ رابعة.
         *
         * القائمة السابقة كانت تقبل `PARTIALLY_REFUNDED` بلا نظرٍ في المتبقّي،
         * فيُعرض الزرّ على طلبٍ استُرد كاملًا على دفعتين ثمّ يرفضه النطاق.
         * والرسالة تقول الآن أيّ الشرطين سقط.
         */
        if (! OrderStatus::wasEverCollected($order->status)) {
            return response()->json([
                'message' => 'لا يمكن استرداد طلب في الحالة «'.$order->status.'» — الاسترداد متاح للطلبات التي وصل مالها.',
                'code' => 'ORDER_NOT_REFUNDABLE',
            ], 422);
        }

        if (! OrderStatus::hasRefundableBalance($order)) {
            return response()->json([
                'message' => 'هذا الطلب استُرد بالكامل — لا يبقى فيه ما يُردّ.',
                'code' => 'ORDER_NOT_REFUNDABLE',
            ], 422);
        }

        $alreadyRefunded = (int) $order->refunded_total_minor + (int) $order->refund_reserved_minor;
        $refundable = (int) $order->total_minor - $alreadyRefunded;

        /*
         * السقف هنا **رسالةٌ مبكّرة** لا حارس: الحارس في `RefundService`
         * تحت قفل الصفّ، وفي قيدٍ على الطلب في المحرّك. وجودُه هنا يجعل
         * الخطأ مقروءًا قبل أن يُرسَل، وغيابُ الحارس هناك كان يعني أنّ أيّ
         * مستدعٍ آخر — أو طلبين متزامنين — يتجاوزانه.
         */
        $data = $request->validate([
            'amountMinor' => 'required|integer|min:1|max:'.max($refundable, 0),
            'reason' => 'sometimes|nullable|string|max:255',
            'policy' => ['required', Rule::in(array_column(RevocationPolicy::cases(), 'value'))],
            /*
             * إلزاميّ كحركة المخزون: نقرةٌ مكرّرة بلا مفتاح كانت تُخرج المال
             * مرّتين — القفل يُسلسل الطلبين ولا يُميّز بينهما.
             */
            'idempotencyKey' => 'required|string|min:8|max:120',
        ], AdminValidation::MESSAGES + [
            'amountMinor.max' => 'المبلغ يتجاوز المتبقي القابل للاسترداد ('.$refundable.' هللة).',
        ]);

        $statusBefore = $order->status;

        try {
            $refund = $refunds->refund(
                $order,
                (int) $data['amountMinor'],
                $data['reason'] ?? null,
                RevocationPolicy::from($data['policy']),
                $data['idempotencyKey'] ?? null,
            );
        } catch (RefundExceedsCaptured $e) {
            // سباقٌ فاز به طلبٌ آخر بين التحقّق والقفل — يُقرأ سببًا لا عطلًا.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'REFUND_EXCEEDS_CAPTURED',
                'remainingMinor' => $e->remainingMinor(),
            ], 422);
        } catch (RefundIdempotencyConflict $e) {
            return response()->json([
                'message' => 'المفتاح نفسه أُرسل بطلبٍ مختلف (مبلغ أو سياسة) — أعد التحميل وحاول من جديد.',
                'code' => 'IDEMPOTENCY_CONFLICT',
                'refundId' => $e->existingRefundId,
            ], 409);
        } catch (RefundFailed $e) {
            /*
             * رفضٌ حاسم من المزوّد: الصفّ `FAILED` وحجزُه أُفرج عنه. يُدوَّن
             * في السجلّ — محاولةُ ردٍّ رُفضت حدثٌ ماليّ يُسأل عنه.
             */
            $this->audit->log(
                $actor, 'order.refund_rejected', 'order', $order->id,
                ['status' => $statusBefore], ['error' => $e->getMessage(), 'refundId' => $e->refund?->id],
                $data['reason'] ?? null, $request,
            );

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'REFUND_PROVIDER_REJECTED',
            ], 502);
        } catch (RefundOutcomeUnknown $e) {
            /*
             * المزوّد لم يجب. الحجز باقٍ فلا يُردّ فوقه، والمحاولة التالية
             * على الطلب نفسه تعيد السؤال بالمفتاح نفسه قبل أيّ ردٍّ جديد.
             */
            $this->audit->log(
                $actor, 'order.refund_unresolved', 'order', $order->id,
                ['status' => $statusBefore], ['error' => $e->getMessage()],
                $data['reason'] ?? null, $request,
            );

            return response()->json([
                'message' => 'لم يصل ردٌّ من مزوّد الدفع. المبلغ محجوز ولن يُردّ مرّتين — أعد المحاولة لاحقًا وسيُستكمل الاسترداد نفسه.',
                'code' => 'REFUND_OUTCOME_UNKNOWN',
            ], 502);
        }

        $this->audit->log(
            $actor, 'order.refunded', 'order', $order->id,
            ['status' => $statusBefore, 'refundedMinor' => $alreadyRefunded],
            ['status' => $order->fresh()->status, 'refundedMinor' => (int) $order->fresh()->refunded_total_minor, 'refundStatus' => $refund->status, 'refundId' => $refund->id, 'policy' => $data['policy']],
            $data['reason'] ?? null, $request,
        );

        return response()->json([
            'data' => [
                'refundId' => $refund->id,
                'orderId' => $order->id,
                'amountMinor' => (int) $refund->amount_minor,
                'status' => $refund->status,
                'providerRefundId' => $refund->provider_refund_id,
                'policy' => $refund->revocation_policy,
                'orderStatus' => $order->fresh()->status,
            ],
        ], 201);
    }

    private function row(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'email' => $order->email,
            'customerId' => $order->customer_id,
            'status' => $order->status,
            'grossMinor' => (int) $order->totals_gross_minor,
            'discountMinor' => (int) $order->discount_minor,
            'couponMinor' => (int) $order->coupon_minor,
            'totalMinor' => (int) $order->total_minor,
            'vatMinor' => (int) $order->vat_minor,
            'paidAt' => $order->paid_at === null ? null : Carbon::parse($order->paid_at)->toIso8601String(),
            'createdAt' => $order->created_at?->toIso8601String(),
        ];
    }
}
