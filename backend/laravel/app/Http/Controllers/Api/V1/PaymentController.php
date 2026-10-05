<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\OrderFinalizer;
use App\Domain\Orders\OrderToken;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\InvalidWebhookSignature;
use App\Domain\Payments\ProviderRegistry;
use App\Domain\Payments\WebhookIntake;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly OrderFinalizer $finalizer,
        private readonly WebhookIntake $intake,
    ) {}

    /**
     * خطّاف المزوّد: تحقّق · **احفظ** · عالج.
     *
     * الحفظ قبل المعالجة ليس تفصيلًا: بدونه كان كلّ عطلٍ في المعالجة يخسر
     * الحدث نهائيًّا بعد أن يستسلم المزوّد — فيدفع الزبون ولا يعلم المتجر،
     * ولا تبقى حمولةٌ يُصلَح بها شيء.
     *
     * والتكرار يُردّ من الحفظ لا من المعالجة: القيد الفريد على (المزوّد،
     * مفتاح التكرار) يجعل الإرسال العاشر يصطدم بالأوّل قبل أن يلمس طلبًا.
     */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        $adapter = $this->providers->resolve($provider);
        if ($adapter === null) {
            return response()->json(['message' => 'مزوّد الدفع غير معروف'], 404);
        }

        try {
            $event = $adapter->verifyWebhook($request);
        } catch (InvalidWebhookSignature $e) {
            /*
             * توقيعٌ فاسد لا يُحفظ. حفظُه يجعل جدول التسليمات مسربًا لأيّ
             * مجهولٍ يعرف العنوان، ويُتيح «إعادة تشغيل» حدثٍ لم يُصدره أحد.
             */
            Log::warning('payments.webhook.invalid_signature', ['provider' => $provider]);

            return response()->json(['message' => 'توقيع غير صالح'], 401);
        }

        ['delivery' => $delivery, 'duplicate' => $duplicate] = $this->intake->record($event, true);

        if ($duplicate && $delivery->isSettled()) {
            // حُسم سلفًا: يُعاد الجواب ولا يُعالَج شيء من جديد.
            return response()->json([
                'received' => true,
                'duplicate' => true,
                'deliveryId' => $delivery->id,
                'status' => $delivery->status,
            ]);
        }

        $processed = $this->intake->process($delivery, $event);

        return response()->json([
            'received' => true,
            'duplicate' => $processed->getAttribute('applied') === false,
            // يبقى مفتاحًا مستقلًّا: عملاء قائمون يقرؤونه، وحذفُه كسرُ عقد.
            'ignored' => $processed->status === 'IGNORED',
            'deliveryId' => $processed->id,
            'status' => $processed->status,
        ]);
    }

    /** DEV-ONLY: simulates a successful charge end-to-end. 404 outside local/testing. */
    public function fakeConfirm(string $orderToken): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $order = Order::query()
            ->where('order_key_hash', OrderToken::hash($orderToken))
            ->first();

        abort_if($order === null, 404);

        $this->finalizer->finalize($order, 'fake', FakeProvider::chargeId($order), ['simulated' => true]);

        $order->refresh();

        return response()->json(['orderNumber' => $order->number, 'status' => $order->status]);
    }
}
