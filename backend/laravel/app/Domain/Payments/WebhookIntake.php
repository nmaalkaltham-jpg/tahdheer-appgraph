<?php

namespace App\Domain\Payments;

use App\Domain\Orders\OrderFinalizer;
use App\Domain\Orders\RefundService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\WebhookDelivery;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * استقبال خطّاف الدفع: احفظ أوّلًا، ثمّ عالج.
 *
 * ─── الترتيب هو العقد ───
 *
 *   ١) تحقّق من التوقيع.
 *   ٢) **احفظ الوارد** بمفتاح إزالة تكرار.
 *   ٣) عالج.
 *   ٤) سجّل النتيجة على التسليم نفسه.
 *
 * كان الترتيب (١) ثمّ (٣) بلا حفظ: كلّ عطلٍ في المعالجة يخسر الحدث نهائيًّا
 * بعد أن يستسلم المزوّد عن إعادة الإرسال. الزبون دفع، والمتجر لا يعلم، ولا
 * حمولة تبقى ليُصلَح بها شيء.
 *
 * ─── لماذا التكرار يُردّ من الحفظ لا من المعالجة ───
 *
 * المزوّدون يعيدون الإرسال بالتصميم. والقيد الفريد على (المزوّد، المفتاح)
 * يجعل الإرسال العاشر يصطدم بالأوّل قبل أن يلمس طلبًا أو دفعة — لا فحصٌ
 * قبل الإدراج، فالفحص سباق.
 */
class WebhookIntake
{
    /** حالاتُ رسمٍ نهائيّة بلا مال (وثائق Tap: كلّها «فشل الدفع»). */
    public const TERMINAL_FAILURES = ['ABANDONED', 'CANCELLED', 'FAILED', 'DECLINED', 'RESTRICTED', 'VOID', 'TIMEDOUT'];

    public function __construct(
        private readonly OrderFinalizer $finalizer,
        private readonly RefundService $refunds,
    ) {}

    /**
     * @return array{delivery: WebhookDelivery, duplicate: bool}
     */
    public function record(WebhookEvent $event, bool $signatureVerified): array
    {
        $key = $this->dedupeKey($event);

        $existing = WebhookDelivery::query()
            ->where('provider', $event->provider)->where('dedupe_key', $key)->first();

        if ($existing !== null) {
            return ['delivery' => $existing, 'duplicate' => true];
        }

        try {
            $delivery = WebhookDelivery::create([
                'provider' => $event->provider,
                'kind' => $event->kind,
                'dedupe_key' => $key,
                'external_event_id' => $this->externalId($event),
                'charge_id' => $event->chargeId,
                'refund_id' => $event->refundId,
                'order_number' => $event->orderNumber,
                'signature_verified' => $signatureVerified,
                'succeeded' => $event->succeeded,
                /*
                 * المبلغ يُحفظ على التسليم أيضًا: إعادة التشغيل تُعيد بناء
                 * الحدث من هذا الصفّ، وتحليلُ الحمولة بمحلّل اليوم يعني أنّ
                 * حدثًا وصل قبل سنة يُفهم بقواعد تغيّرت.
                 */
                'amount_minor' => $event->amountMinor,
                'currency' => $event->currency,
                'amount_problem' => $event->amountProblem,
                'status' => 'RECEIVED',
                'payload' => $event->payload,
                'received_at' => now(),
            ]);

            return ['delivery' => $delivery, 'duplicate' => false];
        } catch (QueryException $e) {
            // خسر السباق مع إرسالٍ متزامن — القيد هو ما التقطه.
            $winner = WebhookDelivery::query()
                ->where('provider', $event->provider)->where('dedupe_key', $key)->first();

            if ($winner === null) {
                throw $e;
            }

            return ['delivery' => $winner, 'duplicate' => true];
        }
    }

    /**
     * يعالج تسليمًا محفوظًا. آمنٌ للتكرار: `OrderFinalizer` خاملُ التكرار
     * بمفتاح `provider:chargeId`، فإعادة التشغيل لا تُنشئ دفعةً ثانية.
     */
    public function process(WebhookDelivery $delivery, WebhookEvent $event): WebhookDelivery
    {
        $delivery->increment('attempts');

        if ($event->isRefund()) {
            return $this->processRefund($delivery, $event);
        }

        if (! $event->succeeded) {
            /*
             * حدثٌ لا يعني دفعًا ناجحًا. يُحفظ ويُعلَّم متجاهَلًا — لا يُهمل
             * بلا أثر: «لماذا لم يُحسب هذا الحدث؟» سؤالٌ يجب أن يُجاب.
             */
            $delivery->update(['status' => 'IGNORED', 'processed_at' => now()]);

            /*
             * ورسمٌ انتهى بلا مال (رُفض، أُلغي، انتهت مهلته) تُغلق نيّتُه:
             * رابطُه ميت، وإبقاؤه «قائمًا» كان يُعيده للزبون في «ادفع الآن»
             * فيهبط على صفحة دفعٍ منتهية. الإغلاق يجعل المحاولة التالية رسمًا
             * جديدًا بمحاولةٍ جديدة.
             */
            $status = mb_strtoupper((string) ($event->payload['status'] ?? ''));
            if (in_array($status, self::TERMINAL_FAILURES, true) && $event->chargeId !== '') {
                Payment::query()
                    ->where('provider', $event->provider)
                    ->where('intent_id', $event->chargeId)
                    ->where('state', 'CREATED')
                    ->update(['state' => 'FAILED']);
            }

            /*
             * استردادٌ أو إلغاءٌ أو نزاعٌ من جهة المزوّد يصل هنا أيضًا ولا
             * يُعالَج بعد: يُسجَّل تحذيرًا لا يُهمَل بصمت، فالدفتر يختلف عن
             * البوّابة وأحدٌ يجب أن يعلم.
             */
            Log::warning('payments.webhook.ignored', [
                'deliveryId' => $delivery->id,
                'provider' => $event->provider,
                'chargeId' => $event->chargeId,
                'orderNumber' => $event->orderNumber,
                'status' => $event->payload['status'] ?? null,
            ]);

            return $delivery;
        }

        $order = Order::query()->where('number', $event->orderNumber)->first();

        if ($order === null) {
            /*
             * دفعةٌ بلا طلبٍ مطابق. هذه **ليست** نجاحًا ولا فشلًا عندنا: قد
             * يكون الطلب في الطريق، أو يكون المزوّد أرسل ما لا يخصّنا.
             * تُحفظ منفصلةً ليُعاد تشغيلها حين يظهر الطلب.
             */
            Log::warning('payments.webhook.unknown_order', [
                'provider' => $event->provider,
                'orderNumber' => $event->orderNumber,
                'deliveryId' => $delivery->id,
            ]);

            $delivery->update(['status' => 'UNMATCHED', 'processed_at' => now()]);

            return $delivery;
        }

        try {
            /*
             * `OrderFinalizer` يفتح معاملته بنفسه ويحمل مفتاح خمولٍ
             * `provider:chargeId`. لا تُلفّ بمعاملةٍ ثانية: التداخل يمنع
             * التقاط تصادم المفتاح الفريد بوصفه «مكرّرًا» فيصير خطأً.
             */
            $applied = $this->finalizer->finalize(
                $order, $event->provider, $event->chargeId, $event->payload,
                $event->amountMinor, $event->currency, $event->amountProblem, $event->merchantId,
            );

            $delivery->update([
                'status' => 'PROCESSED',
                'processed_at' => now(),
                'last_error' => null,
            ]);

            $delivery->setAttribute('applied', $applied);

            return $delivery;
        } catch (PaymentUnfundable $e) {
            /*
             * حُسم: المال سُجِّل كما وصل، والطلب لم يُموَّل. لا يُعاد الرمي —
             * إعادةُ الإرسال من المزوّد لن تغيّر الحقيقة، والتسوية تحمل
             * التناقض إلى المشغّل حادثًا (فرق مبلغ / تحصيلٌ يتيم).
             */
            $delivery->update([
                'status' => 'MISMATCH',
                'processed_at' => now(),
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            $delivery->setAttribute('applied', false);

            return $delivery;
        } catch (Throwable $e) {
            /*
             * الفشل يُسجَّل بسببه ولا يُبتلع. والحمولة محفوظة، فالإصلاح
             * صار زرَّ إعادةٍ لا تدخّلًا يدويًّا في قاعدة البيانات.
             */
            Log::error('payments.webhook.process_failed', [
                'deliveryId' => $delivery->id,
                'provider' => $event->provider,
                'error' => $e->getMessage(),
            ]);

            $delivery->update([
                'status' => 'FAILED',
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }
    }

    /**
     * خطّاف استرداد: يحسم صفًّا محجوزًا بهويّته عند المزوّد.
     *
     * لا يُموِّل طلبًا ولا يُنشئ استردادًا: استردادٌ لا نعرفه (لم نطلبه
     * نحن) يُحفظ `UNMATCHED` ليُراجَع — مالٌ خرج من لوحة المزوّد بلا صفٍّ
     * عندنا حدثٌ يستحقّ عينًا لا تجاهلًا.
     */
    private function processRefund(WebhookDelivery $delivery, WebhookEvent $event): WebhookDelivery
    {
        try {
            $outcome = $this->refunds->settleFromProvider(
                $event->provider, (string) $event->refundId, (string) ($event->payload['status'] ?? ''),
                $event->chargeId, $event->amountMinor, $event->currency,
            );
        } catch (Throwable $e) {
            Log::error('payments.webhook.refund_failed', [
                'deliveryId' => $delivery->id, 'provider' => $event->provider, 'error' => $e->getMessage(),
            ]);
            $delivery->update(['status' => 'FAILED', 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e;
        }

        $status = match ($outcome) {
            'unmatched' => 'UNMATCHED',
            'ignored' => 'IGNORED',
            default => 'PROCESSED',
        };

        if ($outcome === 'unmatched') {
            Log::warning('payments.webhook.unknown_refund', [
                'provider' => $event->provider, 'refundId' => $event->refundId, 'deliveryId' => $delivery->id,
            ]);
        }

        $delivery->update(['status' => $status, 'processed_at' => now(), 'last_error' => null]);
        $delivery->setAttribute('applied', $outcome === 'applied');

        return $delivery;
    }

    /**
     * هويّة الحدث عند المزوّد.
     *
     * تُفضَّل هويّةٌ يصدرها المزوّد إن وُجدت. وإلّا تُركَّب من (الرسوم،
     * الحالة): حدثان لرسمٍ واحد بحالتين مختلفتين حدثان لا واحد.
     */
    private function dedupeKey(WebhookEvent $event): string
    {
        if ($event->isRefund()) {
            /*
             * هويّة الاسترداد + حالته: «قيد التنفيذ» ثمّ «مستردّ» للاسترداد
             * الواحد حدثان — وهويّةُ الاسترداد وحدها كانت ستُسقط الثاني مكرّرًا.
             */
            $status = $event->payload['status'] ?? ($event->succeeded ? 'REFUNDED' : 'OTHER');

            return $event->refundId.':'.(is_scalar($status) ? (string) $status : 'OTHER');
        }

        $external = $this->externalId($event);

        if ($external !== null) {
            return $external;
        }

        $status = $event->payload['status'] ?? ($event->succeeded ? 'SUCCEEDED' : 'OTHER');

        return $event->chargeId.':'.(is_scalar($status) ? (string) $status : 'OTHER');
    }

    private function externalId(WebhookEvent $event): ?string
    {
        foreach (['event_id', 'id', 'eventId'] as $field) {
            $value = $event->payload[$field] ?? null;

            /*
             * Tap يضع معرّف **الرسم** في `id` لا معرّف الحدث. فقراءته هويّةً
             * للحدث كانت تجعل «مصرَّح» ثمّ «مقبوض» لرسمٍ واحد تسليمًا واحدًا،
             * ويُهمَل الثاني بوصفه مكرّرًا — مالٌ يُقبض وطلبٌ لا يُموَّل.
             * ما يساوي معرّف الرسم ليس هويّةَ حدث.
             */
            if (is_string($value) && $value !== '' && $value !== $event->chargeId) {
                return $value;
            }
        }

        return null;
    }
}
