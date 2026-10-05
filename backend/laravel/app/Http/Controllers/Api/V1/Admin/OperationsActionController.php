<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Inventory\InventoryService;
use App\Domain\Operations\Jobs\RetryFulfillmentUnitJob;
use App\Domain\Operations\Jobs\VerifyAssetSourceJob;
use App\Domain\Operations\OperationLedger;
use App\Domain\Operations\RequestFingerprint;
use App\Domain\Returns\ReturnTransitionService;
use App\Domain\Risk\Models\RiskAssessment;
use App\Domain\Shipping\ShipmentTransitionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * أفعال منصّة العمليّات.
 *
 * منصّةٌ للقراءة وحدها تقريرٌ لا وحدةُ تحكّم: المشغّل يرى أنّ وحدةً فشلت
 * ولا يملك إلّا فتح تذكرة. هذه النقاط تجعل ما يُرى قابلًا للتصرّف فيه.
 *
 * وكلّ فعلٍ هنا يُسجَّل في سجلّ التدقيق بحالته قبل وبعد (§١٥٥ «كلّ عمليّة
 * حسّاسة مُدقَّقة»). والسبب ليس الامتثال: تصحيحُ جردٍ بلا أثرٍ يجعل الفرق
 * بين الرفّ والنظام غير قابلٍ للتفسير بعد أسبوع، وإعادةُ محاولةِ تسليمٍ
 * بلا أثرٍ تُخفي أنّ أحدهم حاول ستّ مرّات.
 *
 * ولا انتقالَ حالةٍ حرّ: الشحنة والإرجاع لهما دورتا حياةٍ مُعرَّفتان
 * (§٢٦ §٣٣)، والقفز فيهما يُنتج «سُلّمت» لشحنةٍ لم تخرج.
 */
class OperationsActionController extends Controller
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly InventoryService $inventory,
        private readonly OperationLedger $operations,
        private readonly ShipmentTransitionService $shipments,
        private readonly ReturnTransitionService $returns,
    ) {}

    /**
     * إعادة محاولة وحدة تنفيذ — حجزٌ ثمّ إرسال.
     *
     * لا تنفيذ هنا. التنفيذ قد ينادي مزوّدًا خارجيًّا، ونداءٌ كهذا داخل طلب
     * HTTP إداريّ يربط زمن استجابة اللوحة بطرفٍ ثالث: تتعلّق الصفحة، ينتهي
     * وقت الوكيل، يُعيد المشغّل النقر — فيُنفَّذ الفعل مرّتين بينما هو يظنّه
     * لم يقع مرّة.
     *
     * والمفتاح يحمل «جيل المحاولة» (عدّاد محاولات الوحدة)، فإعادةٌ **مقصودة**
     * بعد فشلٍ تحصل على مفتاحٍ جديد، وإعادةُ إرسالٍ عارضة للطلب نفسه تصطدم
     * بالمفتاح القائم وتُعيد جوابه.
     */
    public function retryUnit(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $unit = FulfillmentUnit::query()->find($id);

        if ($unit === null) {
            return response()->json(['message' => 'لا وحدة تنفيذٍ بهذا المعرّف.'], 404);
        }

        if ($unit->isTerminal()) {
            throw ValidationException::withMessages([
                'unit' => ['الوحدة في حالةٍ نهائيّة — لا تُعاد محاولتها.'],
            ]);
        }

        $key = "fulfillment_retry:{$unit->id}:{$unit->attempts}";
        [$operation, $isNew] = $this->operations->reserve(
            $key, 'fulfillment_retry', 'fulfillment_unit', $unit->id, $actor->id,
            RequestFingerprint::of(['unitId' => $unit->id, 'attempts' => (int) $unit->attempts]),
        );

        if (! $isNew) {
            // طلبٌ مكرّر: يُعاد جوابُ الأوّل ولا يُرسَل شيء إلى الطابور.
            return response()->json([
                'operationId' => $operation->id,
                'status' => $operation->status,
                'duplicate' => true,
                'result' => $operation->result,
            ], 200);
        }

        $this->audit->log(
            $actor, 'fulfillment.unit.retry.requested', 'fulfillment_unit', $unit->id,
            ['status' => $unit->status, 'attempts' => (int) $unit->attempts],
            ['operationId' => $operation->id], $request->input('reason'), $request,
        );

        RetryFulfillmentUnitJob::dispatch($operation->id, $unit->id);

        return response()->json([
            'operationId' => $operation->id,
            'correlationId' => $operation->correlation_id,
            'status' => $operation->status,
            'duplicate' => false,
        ], 202);
    }

    /**
     * إعادة التحقّق من مصدر أصل — حجزٌ ثمّ إرسال (§١٦).
     *
     * المفتاح `asset_verify:{source}` بلا طابعٍ زمنيّ عمدًا: طلبان متزامنان
     * على المصدر نفسه ينهاران إلى واحد. هذا هو التجميع المطلوب — لا صفٌّ
     * من تحقّقاتٍ متطابقة تُرهق المزوّد وتستنزف الحصّة.
     *
     * وما انتهى يُفتح من جديد: مفتاحٌ أُنجز سابقًا لا يمنع تحقّقًا لاحقًا،
     * لأنّ حالة المصدر تتغيّر بمرور الوقت.
     */
    public function verifySource(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $source = AssetSource::query()->find($id);

        if ($source === null) {
            return response()->json(['message' => 'لا مصدر أصلٍ بهذا المعرّف.'], 404);
        }

        $key = "asset_verify:{$source->id}:".($source->last_verified_at?->timestamp ?? 0);
        [$operation, $isNew] = $this->operations->reserve(
            $key, 'asset_verify', 'asset_source', $source->id, $actor->id,
            RequestFingerprint::of(['sourceId' => $source->id]),
        );

        if (! $isNew) {
            return response()->json([
                'operationId' => $operation->id,
                'status' => $operation->status,
                'duplicate' => true,
                'result' => $operation->result,
            ], 200);
        }

        $this->audit->log(
            $actor, 'asset_source.verify.requested', 'asset_source', $source->id,
            ['syncState' => $source->sync_state], ['operationId' => $operation->id], null, $request,
        );

        VerifyAssetSourceJob::dispatch($operation->id, $source->id);

        return response()->json([
            'operationId' => $operation->id,
            'correlationId' => $operation->correlation_id,
            'status' => $operation->status,
            'duplicate' => false,
        ], 202);
    }

    /**
     * حركة مخزون يدويّة — إدخالٌ أو تصحيح.
     *
     * السبب مطلوبٌ نصًّا لا خيارًا من قائمة: القوائم تنتهي إلى «أخرى» التي
     * لا تفسّر شيئًا بعد شهر، والدفتر يُقرأ ليُفهم لا ليُعدّ.
     */
    public function stockMovement(Request $request, AdminUser $actor): JsonResponse
    {
        $data = $request->validate([
            'variantId' => 'required|integer|exists:product_variants,id',
            'locationId' => 'required|integer|exists:inventory_locations,id',
            'kind' => ['required', Rule::in(['RECEIPT', 'ADJUSTMENT'])],
            'quantity' => 'required|integer|not_in:0',
            'reason' => 'required|string|min:3|max:500',
            /*
             * مفتاح الخمول **إلزاميّ** لا اختياريّ: حركة المخزون أثرٌ لا
             * يُلغى، وتركُ الخمول خيارًا يعني أنّ أوّل عميلٍ ينساه يُضاعف
             * جردًا. العميل يولّده مرّةً ويُعيده مع كلّ إعادة إرسال.
             */
            'idempotencyKey' => 'required|string|min:8|max:120',
        ]);

        $key = 'stock_movement:'.$data['idempotencyKey'];

        /*
         * البصمة تحمي من أسوأ سوء استعمالٍ للخمول: مفتاحٌ يُعاد بجسمٍ مختلف.
         * بدونها كان من يطلب نقل خمسةٍ بمفتاحٍ استُعمل لعشرة يتلقّى «تمّ»،
         * والذي تمّ ليس ما طلبه.
         */
        [$operation, $isNew] = $this->operations->reserve(
            $key, 'stock_movement', 'product_variant', (int) $data['variantId'], $actor->id,
            RequestFingerprint::of($data),
        );

        if (! $isNew) {
            // المفتاح نفسه: تُعاد النتيجة الأولى ولا تتحرّك كمّيّة.
            return response()->json(($operation->result ?? []) + ['duplicate' => true]);
        }

        /*
         * التنفيذ هنا مباشر، فيحمل إيجارًا كأيّ عاملٍ: موتُ العمليّة بين الحجز
         * والختم كان يتركها «محجوزة» بلا إيجار فلا يراها التوفيق أبدًا.
         */
        $this->operations->acquire($operation, 'request:'.$operation->id);
        $this->operations->markProcessing($operation);

        try {
            $level = $data['kind'] === 'RECEIPT'
                ? $this->inventory->receive((int) $data['variantId'], (int) $data['locationId'], (int) $data['quantity'], $data['reason'], $key)
                : $this->inventory->adjust((int) $data['variantId'], (int) $data['locationId'], (int) $data['quantity'], $data['reason'], $actor->id, $key);
        } catch (RuntimeException $e) {
            $this->operations->fail($operation, 'PERMANENT_BUSINESS', $e->getMessage());

            throw ValidationException::withMessages(['quantity' => [$e->getMessage()]]);
        }

        $this->audit->log(
            $actor, 'inventory.'.mb_strtolower($data['kind']), 'product_variant', (int) $data['variantId'],
            null,
            ['locationId' => $data['locationId'], 'delta' => (int) $data['quantity'], 'onHand' => $level->on_hand, 'reserved' => $level->reserved],
            $data['reason'], $request,
        );

        $result = [
            'onHand' => (int) $level->on_hand,
            'reserved' => (int) $level->reserved,
            'available' => $level->available(),
        ];

        $this->operations->succeed($operation, $result);

        return response()->json($result + ['duplicate' => false]);
    }

    /**
     * تقدّم الشحنة — تفويضٌ كامل إلى نطاق الشحن.
     *
     * الجسم هنا أربعة أسطر عمدًا: تحقّقٌ من الشكل، ثمّ نداء، ثمّ تسلسل.
     * كان يقرأ الشحنة ويتحقّق ويعدّلها بنفسه — أي أنّ سياسة الانتقال كانت
     * تعيش في وحدة تحكّمٍ إداريّة، والقراءة والكتابة بلا قفلٍ ولا معاملة.
     */
    public function updateShipment(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(ShipmentTransitionService::TRANSITIONS))],
            'trackingNumber' => 'sometimes|nullable|string|max:120',
            'reason' => 'sometimes|nullable|string|max:500',
        ]);

        $shipment = $this->shipments->transition(
            shipmentId: $id,
            to: $data['status'],
            trackingNumber: $data['trackingNumber'] ?? null,
            actor: $actor,
            request: $request,
            reason: $data['reason'] ?? null,
        );

        return response()->json([
            'status' => $shipment->status,
            'trackingNumber' => $shipment->tracking_number,
            'deliveredAt' => $shipment->delivered_at?->toIso8601String(),
            /* الواجهة تقرأ المسموح من الخادم ولا تخترع خريطةً ثانية. */
            'allowedNext' => ShipmentTransitionService::allowedFrom($shipment->status),
        ]);
    }

    /**
     * تقدّم طلب الإرجاع — تفويضٌ كامل إلى نطاق الإرجاع.
     *
     * وردّ المال نفسه ليس هنا ولا هناك: فعلٌ ماليّ يمرّ بـ`RefundService`
     * ليُنشئ صفّ استرداد ويسحب الاستحقاق ويكتب في الصندوق. تكرارُه يُنتج
     * دفترين ماليّين.
     */
    public function updateReturn(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(ReturnTransitionService::TRANSITIONS))],
            'note' => 'sometimes|nullable|string|max:1000',
        ]);

        $return = $this->returns->transition(
            returnId: $id,
            to: $data['status'],
            note: $data['note'] ?? null,
            actor: $actor,
            request: $request,
        );

        return response()->json([
            'status' => $return->status,
            'allowedNext' => ReturnTransitionService::allowedFrom($return->status),
        ]);
    }

    /**
     * إقفال مراجعة خطر (§١٠٥).
     *
     * القرار البشريّ يُسجَّل ولا يُلغي التقييم: التقييم ما رآه النظام،
     * والمراجعة ما قرّره الإنسان. طمسُ الأوّل بالثاني يُفقد القدرة على قياس
     * دقّة القواعد لاحقًا — كم مرّةً رفعت ما لا يستحقّ.
     */
    public function reviewRisk(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $assessment = RiskAssessment::query()->findOrFail($id);

        $data = $request->validate([
            'outcome' => ['required', Rule::in(['CLEARED', 'CONFIRMED_ABUSE'])],
            'note' => 'required|string|min:3|max:1000',
        ]);

        $before = ['reviewOutcome' => $assessment->review_outcome];

        $assessment->update([
            'review_outcome' => $data['outcome'],
            'review_note' => $data['note'],
            'reviewed_by_admin_user_id' => $actor->id,
            'reviewed_at' => now(),
        ]);

        $this->audit->log(
            $actor, 'risk.reviewed', 'risk_assessment', $assessment->id,
            $before, ['reviewOutcome' => $data['outcome']], $data['note'], $request,
        );

        return response()->json(['reviewOutcome' => $assessment->review_outcome]);
    }
}
