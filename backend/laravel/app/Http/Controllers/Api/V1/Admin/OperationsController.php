<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminValidation;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Inventory\Models\InventoryReservation;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\ReturnTransitionService;
use App\Domain\Risk\FraudGraph;
use App\Domain\Risk\Models\RiskAssessment;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\ShipmentTransitionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * منصّة العمليّات — ما يحتاج تدخّلًا اليوم.
 *
 * اللوحة كانت مبنيّةً لمتجرٍ رقميّ بحت: طلبٌ يُدفع فيُمنح استحقاقٌ وينتهي
 * الأمر. وبعد بناء التنفيذ والمخزون والشحن صار بين «دُفع» و«وصل» عملٌ
 * حقيقيّ لا سطح له، فيبقى غير مرئيّ حتّى يسأل زبون.
 *
 * والمبدأ الحاكم لكلّ نقطةٍ هنا: **تُعيد ما يحتاج فعلًا لا كلّ ما هو
 * موجود**. قائمةٌ بكلّ وحدات التنفيذ تقريرٌ؛ وقائمةٌ بالمعلّق وسببِ تعليقه
 * قائمةُ عمل.
 *
 * لا صلاحيّة جديدة هنا عمدًا: استحداثُ `operations.read` يوجب ترحيل الأدوار
 * القائمة، وحجبُ السطح عن كلّ مشغّلٍ حتّى تُمنح. القراءة تتبع النطاق الأقرب
 * — الطلبات للتنفيذ والشحن والإرجاع، والمنتجات للمخزون.
 */
class OperationsController extends Controller
{
    /**
     * طابور التنفيذ: ما لم يُغلق بعد، ومعه سببه.
     *
     * السبب يُعاد مع الصفّ لا يُطلب على حدة: المشغّل يقرأ «لا عنوان شحن»
     * فيعرف ما يفعل، بدل أن يرى «معلّق» ويفتح كلّ وحدةٍ ليكتشف لماذا.
     */
    public function fulfillmentUnits(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'sometimes|string|max:32',
            'strategy' => 'sometimes|string|max:48',
        ]);

        $units = FulfillmentUnit::query()
            ->with(['order:id,number,status,email', 'item:id,title_snapshot,sku_snapshot'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['strategy'] ?? null, fn ($q, $s) => $q->where('strategy', $s))
            ->when(! isset($filters['status']), fn ($q) => $q->open())
            ->orderByRaw("case when status = 'FAILED' then 0 else 1 end")
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            ->map(fn (FulfillmentUnit $u) => [
                'id' => $u->id,
                'orderId' => $u->order_id,
                'orderNumber' => $u->order?->number,
                'customerEmail' => $u->order?->email,
                'title' => $u->item?->title_snapshot,
                'sku' => $u->item?->sku_snapshot,
                'strategy' => $u->strategy,
                'status' => $u->status,
                // السبب أو الخطأ — أحدهما دائمًا مملوء بحكم قيدٍ في المحرّك.
                'reason' => $u->pending_reason ?? $u->last_error,
                'attempts' => (int) $u->attempts,
                'createdAt' => $u->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => $units,
            'summary' => FulfillmentUnit::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    /**
     * أعمدة فرز الشحنات — قائمةٌ بيضاء لا اسمٌ يأتي من العميل.
     */
    private const SHIPMENT_SORTABLE = [
        'status' => 'status',
        'cost' => 'shipping_cost_minor',
        'weight' => 'weight_grams',
        'shippedAt' => 'shipped_at',
        'deliveredAt' => 'delivered_at',
        'createdAt' => 'created_at',
    ];

    /**
     * الشحنات — مُصفَّحة لا مقطوعة عند مئتين.
     *
     * ─── العيب الذي أُزيل ───
     *
     * كان `limit(200)` بلا إجماليّ وبلا تصفّح: تُعرض مئتا صفٍّ ولا شيء يقول
     * إنّ خلفها المزيد. فيُقرأ الجدول على أنّه كلّ الشحنات، ويبحث المشغّل عن
     * شحنةٍ موجودة فلا يجدها ويستنتج أنّها لم تُنشأ — وهو استنتاجٌ خاطئ يقود
     * إلى إنشاءٍ مكرّر.
     */
    public function shipments(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'sometimes|string|max:40',
            'q' => 'sometimes|string|max:200',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
            'sort' => ['sometimes', Rule::in(array_keys(self::SHIPMENT_SORTABLE))],
            'dir' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        $query = Shipment::query()
            ->with(['order:id,number,email', 'carrier:id,code,name,tracking_url_template'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));

        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $needle = '%'.trim($filters['q']).'%';
            $query->where(function ($q) use ($needle) {
                $q->where('tracking_number', 'like', $needle)
                    ->orWhereHas('order', fn ($o) => $o->where('number', 'like', $needle));
            });
        }

        /* `id` قاطعٌ ثانويّ دائمًا — بدونه الترتيب غير محدَّد عبر الصفحات. */
        $column = self::SHIPMENT_SORTABLE[$filters['sort'] ?? ''] ?? 'created_at';
        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($column, $direction)->orderByDesc('id')->paginate(
            perPage: (int) ($filters['perPage'] ?? 25),
            page: (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Shipment $s) => [
                'id' => $s->id,
                'orderNumber' => $s->order?->number,
                'carrier' => $s->carrier?->name,
                'service' => $s->service_name,
                'status' => $s->status,
                'trackingNumber' => $s->tracking_number,
                // الرابط مبنيٌّ من قالب الناقل، وفارغٌ حين لا رقم — لا رابط مُلفَّق.
                'trackingUrl' => $s->trackingUrl(),
                'costMinor' => $s->shipping_cost_minor,
                'weightGrams' => $s->weight_grams,
                'shippedAt' => $s->shipped_at?->toIso8601String(),
                'deliveredAt' => $s->delivered_at?->toIso8601String(),
                /*
                 * ما يجوز من هنا — بقرار النطاق لا بخريطةٍ ثانية في الواجهة.
                 *
                 * مسار التحديث يبثّه بعد كلّ انتقال، وكانت القائمة لا تبثّه —
                 * فلم يكن لدى الشاشة ما تبني عليه أزرارها إلّا نسخُ
                 * `TRANSITIONS` إليها. ونسختان للخريطة تتباعدان، وأوّل تباعدٍ
                 * زرٌّ يَعِد بانتقالٍ يرفضه الخادم.
                 */
                'allowedNext' => ShipmentTransitionService::allowedFrom((string) $s->status),
            ])->values(),
            'meta' => AdminValidation::meta($paginator),
        ]);
    }

    /**
     * المخزون: ما يقترب من النفاد أوّلًا.
     *
     * الترتيب بالمتاح تصاعديًّا لا بالاسم: القائمة تُفتح لتقرّر إعادة طلبٍ،
     * والمرتّبة أبجديًّا تُخفي المنتج الذي نفد في منتصف الصفحة الثالثة.
     */
    private const STOCK_SORTABLE = [
        'available' => '(on_hand - reserved)',
        'reserved' => 'reserved',
        'onHand' => 'on_hand',
        'reorderPoint' => 'reorder_point',
        'countedAt' => 'counted_at',
    ];

    public function inventory(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'lowOnly' => 'sometimes',
            'q' => 'sometimes|string|max:200',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
            'sort' => ['sometimes', Rule::in(array_keys(self::STOCK_SORTABLE))],
            'dir' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        $base = fn () => StockLevel::query()
            ->when($request->boolean('lowOnly'), fn ($q) => $q->whereRaw('(on_hand - reserved) <= coalesce(reorder_point, 0)'))
            ->when(
                isset($filters['q']) && trim($filters['q']) !== '',
                function ($q) use ($filters) {
                    $needle = '%'.trim($filters['q']).'%';
                    $q->whereHas('variant', fn ($v) => $v->where('sku', 'like', $needle)
                        ->orWhereHas('group', fn ($g) => $g->where('title', 'like', $needle)));
                },
            );

        $query = $base()->with(['variant:id,sku,slug,product_group_id', 'variant.group:id,title', 'location:id,code,name,sellable']);

        /*
         * الافتراض يبقى «ما يقترب من النفاد أوّلًا»: القائمة تُفتح لتقرّر
         * إعادة طلبٍ، والمرتّبة أبجديًّا تُخفي ما نفد في منتصف الصفحة الثالثة.
         */
        $column = self::STOCK_SORTABLE[$filters['sort'] ?? ''] ?? '(on_hand - reserved)';
        $direction = ($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $paginator = $query
            ->orderByRaw("{$column} {$direction}")
            ->orderByDesc('id')
            ->paginate(
                perPage: (int) ($filters['perPage'] ?? 25),
                page: (int) ($filters['page'] ?? 1),
            );

        /*
         * «يحتاج إعادة طلب» يُعدّ على المجموعة كلّها لا على الصفحة.
         *
         * كان يُحسب في الواجهة من الصفوف المحمّلة، فيقول «٣» وفي المخزن
         * ثلاثون — وهو الرقم الذي يُبنى عليه أمر شراء.
         */
        $needsReorder = $base()->whereRaw('(on_hand - reserved) <= coalesce(reorder_point, 0)')->count();

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (StockLevel $l) => [
                'id' => $l->id,
                /*
                 * المعرّفان يُرسلان لأنّ حركة المخزون تُطلب بهما لا باسمٍ
                 * معروض. وبدونهما كانت اللوحة تعرض جردًا لا تستطيع تصحيحه،
                 * فيُصحَّح من صدفةٍ خارج التدقيق — وهو أسوأ من عدم تصحيحه.
                 */
                'variantId' => $l->product_variant_id,
                'locationId' => $l->inventory_location_id,
                'sku' => $l->variant?->sku,
                'product' => $l->variant?->group?->title,
                'location' => $l->location?->name,
                'sellableLocation' => (bool) $l->location?->sellable,
                'onHand' => (int) $l->on_hand,
                'reserved' => (int) $l->reserved,
                'available' => $l->available(),
                'reorderPoint' => $l->reorder_point,
                'needsReorder' => $l->needsReorder(),
                'countedAt' => $l->counted_at?->toIso8601String(),
            ])->values(),
            'meta' => AdminValidation::meta($paginator),
            'needsReorder' => $needsReorder,
            'heldReservations' => InventoryReservation::query()->where('status', 'HELD')->count(),
            'expiredHolds' => InventoryReservation::query()->expired()->count(),
        ]);
    }

    /**
     * عناقيد المخاطرة — تحليلٌ يُقرأ ولا يحكم (§١٨).
     *
     * ─── القانون الحاكم ───
     *
     *   قرارُ المخاطرة ≠ الحقيقة الماليّة.
     *
     * قراءةٌ محضة: لا تلمس طلبًا ولا دفعةً ولا استحقاقًا. وشبهةٌ تُغيّر حالة
     * دفعٍ تُفسد الدفتر بإشارةٍ ظنّيّة، ثمّ لا يُعرف أيّ الأرقام حقيقة.
     */
    public function riskClusters(FraudGraph $graph): JsonResponse
    {
        return response()->json($graph->clusters());
    }

    public function returns(Request $request): JsonResponse
    {
        $requests = ReturnRequest::query()
            ->with('order:id,number,email')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('requested_at')
            ->limit(200)
            ->get()
            ->map(fn (ReturnRequest $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'orderNumber' => $r->order?->number,
                'status' => $r->status,
                'customerReason' => $r->customer_reason,
                'rejectionReason' => $r->rejection_reason,
                'refundMinor' => $r->refund_amount_minor,
                'requestedAt' => $r->requested_at?->toIso8601String(),
                /* ما يجوز من هنا — للسبب نفسه المشروح على الشحنات أعلاه. */
                'allowedNext' => ReturnTransitionService::allowedFrom((string) $r->status),
            ]);

        return response()->json(['data' => $requests]);
    }

    /**
     * صحّة مصادر الأصول — ما انقطع عن مصدره (§١٦).
     *
     * يُعرض المحجوب أوّلًا: مصدرٌ مفقودٌ أو مسحوب الإذن يعني منتجًا يُباع
     * ولا يُسلَّم، وهو أسوأ ما يمكن أن يبقى غير مرئيّ.
     */
    public function assetSources(): JsonResponse
    {
        $sources = AssetSource::query()
            ->with('asset:id,original_name,mime')
            /*
             * القائمة تُبنى من `AssetSource::BLOCKING_STATES` لا تُكتب نصًّا.
             *
             * النسخة المكتوبة هنا كانت رابعَ نسخةٍ من الثلاثيّ نفسه (النموذج،
             * والقيد في المحرّك، ولوحة الإدارة). وحالةٌ حاجبةٌ رابعة تُضاف إلى
             * النموذج تترك هذا الترتيب يدفنها في آخر القائمة — أي تُخفي عن
             * المشغّل بالضبط ما تُبنى هذه الشاشة لإظهاره.
             */
            ->orderByRaw(
                'case when sync_state in ('
                .implode(',', array_fill(0, count(AssetSource::BLOCKING_STATES), '?'))
                .') then 0 else 1 end',
                AssetSource::BLOCKING_STATES,
            )
            ->orderBy('last_verified_at')
            ->limit(300)
            ->get()
            ->map(fn (AssetSource $s) => [
                'id' => $s->id,
                'provider' => $s->provider,
                'deliveryMode' => $s->delivery_mode,
                'fileName' => $s->file_name ?? $s->asset?->original_name,
                'syncState' => $s->sync_state,
                'lastError' => $s->last_error,
                'versionFrozen' => (bool) $s->version_frozen,
                'lastVerifiedAt' => $s->last_verified_at?->toIso8601String(),
                'deliverable' => $s->isDeliverable(),
                /*
                 * الحجب يُحسب هنا فلا تحمل اللوحة نسختها من الثلاثيّ.
                 * `deliverable` لا تُغني عنه: الوسيط الخارجيّ قابلٌ للتسليم
                 * بلا أصلٍ داخليّ، وحالةٌ حاجبة ليست نفيَ التسليم دائمًا.
                 */
                'blocking' => in_array($s->sync_state, AssetSource::BLOCKING_STATES, true),
            ]);

        return response()->json([
            'data' => $sources,
            'blocking' => AssetSource::query()->blocking()->count(),
        ]);
    }

    /**
     * طابور مراجعة المخاطر (§١٠٣ §١٠٥).
     *
     * ما رُفع لعينٍ بشريّة وحده. الجدول كلّه تقريرٌ لا يُقرأ؛ والمرفوع
     * قائمةُ عمل — وإشاراتُه معه لأنّ القرار يُتّخذ بقراءتها لا بقراءة درجة.
     */
    public function riskReviews(Request $request): JsonResponse
    {
        $assessments = RiskAssessment::query()
            ->with(['customer:id,email,name', 'entitlement:id,product_id'])
            ->when(
                $request->query('all') === null,
                fn ($q) => $q->awaitingReview(),
                fn ($q) => $q->whereNotNull('review_outcome'),
            )
            ->orderByDesc('score')->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (RiskAssessment $a) => [
                'id' => $a->id,
                'subjectType' => $a->subject_type,
                'customerEmail' => $a->customer?->email,
                'decision' => $a->decision,
                'score' => (int) $a->score,
                'signals' => $a->signals,
                'reason' => $a->reason,
                'reviewOutcome' => $a->review_outcome,
                'reviewNote' => $a->review_note,
                'createdAt' => $a->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => $assessments,
            'awaiting' => RiskAssessment::query()->awaitingReview()->count(),
        ]);
    }
}
