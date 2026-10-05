<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Operations\Jobs\ReplayWebhookDeliveryJob;
use App\Domain\Operations\OperationLedger;
use App\Domain\Operations\RequestFingerprint;
use App\Domain\Payments\Models\WebhookDelivery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * أحداث الدفع الواردة وإعادة تشغيلها.
 *
 * ─── لماذا هذه الشاشة موجودة ───
 *
 * «هل وصلنا هذا الحدث؟» سؤالٌ يُطرح في كلّ نزاعٍ مع مزوّد دفع، وكان جوابه
 * عندنا: لا نعرف. والأسوأ أنّ حدثًا فشلت معالجته لعطلٍ عابر كان يحتاج
 * تدخّلًا يدويًّا في قاعدة البيانات — أي أنّ إصلاح دفعةٍ ضائعة كان يتمّ
 * خارج التدقيق وبلا صلاحية.
 *
 * ─── لماذا إعادة التشغيل آمنة ───
 *
 * `OrderFinalizer` خاملُ التكرار بمفتاح `provider:chargeId` مفروضٍ في
 * المحرّك. إعادةُ حدثٍ عولج سلفًا تُعيد «مكرّر» ولا تُنشئ دفعةً ثانية.
 */
class PaymentOperationsController extends Controller
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly OperationLedger $operations,
    ) {}

    /** التسليمات الواردة — الأحدث أوّلًا، والفاشل يُرشَّح وحده. */
    public function webhooks(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'sometimes|string|in:RECEIVED,PROCESSED,IGNORED,UNMATCHED,FAILED',
            'provider' => 'sometimes|string|max:32',
            'orderNumber' => 'sometimes|string|max:64',
        ]);

        $deliveries = WebhookDelivery::query()
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['provider'] ?? null, fn ($q, $p) => $q->where('provider', $p))
            ->when($filters['orderNumber'] ?? null, fn ($q, $n) => $q->where('order_number', $n))
            ->orderByDesc('received_at')
            ->limit(200)
            ->get()
            ->map(fn (WebhookDelivery $d) => [
                'id' => $d->id,
                'provider' => $d->provider,
                'status' => $d->status,
                'succeeded' => $d->succeeded,
                'chargeId' => $d->charge_id,
                'orderNumber' => $d->order_number,
                'attempts' => (int) $d->attempts,
                'replayCount' => (int) $d->replay_count,
                'lastError' => $d->last_error,
                'receivedAt' => $d->received_at?->toIso8601String(),
                'processedAt' => $d->processed_at?->toIso8601String(),
                'replayable' => $d->isReplayable(),
            ]);

        return response()->json([
            'data' => $deliveries,
            'failed' => WebhookDelivery::query()->where('status', 'FAILED')->count(),
            'unmatched' => WebhookDelivery::query()->where('status', 'UNMATCHED')->count(),
        ]);
    }

    /**
     * إعادة تشغيل تسليمٍ محفوظ — حجزٌ ثمّ إرسال إلى الطابور.
     *
     * المفتاح يحمل عدّاد الإعادات، فإعادةٌ ثانيةٌ مقصودة مسموحة، والنقرة
     * المكرّرة على الزرّ نفسه ليست كذلك.
     */
    public function replayWebhook(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $delivery = WebhookDelivery::query()->find($id);

        if ($delivery === null) {
            return response()->json(['message' => 'لا تسليم بهذا المعرّف.'], 404);
        }

        if (! $delivery->isReplayable()) {
            return response()->json([
                'message' => 'هذا التسليم في حالةٍ لا تُعاد: '.$delivery->status,
            ], 422);
        }

        $key = "webhook_replay:{$delivery->id}:{$delivery->replay_count}";

        [$operation, $isNew] = $this->operations->reserve(
            $key, 'webhook_replay', 'webhook_delivery', $delivery->id, $actor->id,
            RequestFingerprint::of(['deliveryId' => $delivery->id, 'replayCount' => (int) $delivery->replay_count]),
        );

        if (! $isNew) {
            return response()->json([
                'operationId' => $operation->id,
                'status' => $operation->status,
                'duplicate' => true,
                'result' => $operation->result,
            ]);
        }

        $this->audit->log(
            $actor, 'payments.webhook.replay.requested', 'webhook_delivery', $delivery->id,
            ['status' => $delivery->status, 'replayCount' => (int) $delivery->replay_count],
            ['operationId' => $operation->id], $request->input('reason'), $request,
        );

        ReplayWebhookDeliveryJob::dispatch($operation->id, $delivery->id);

        return response()->json([
            'operationId' => $operation->id,
            'correlationId' => $operation->correlation_id,
            'status' => $operation->status,
            'duplicate' => false,
        ], 202);
    }
}
