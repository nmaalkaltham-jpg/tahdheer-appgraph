<?php

namespace App\Domain\Shipping;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Shipping\Models\Shipment;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * سياسة انتقال الشحنة — **الجهة الوحيدة** التي تغيّر حالتها (§٢٦).
 *
 * ─── العيب الذي يصلحه هذا الصنف ───
 *
 * كان الانتقال يقع في وحدة تحكّم الإدارة بالتسلسل: اقرأ، تحقّق، عدّل. وذلك
 * سباقٌ مفتوح: طلبان متزامنان يقرآن «PENDING» معًا، فيجتاز كلاهما التحقّق،
 * ثمّ يكتب أحدهما فوق الآخر. النتيجة شحنةٌ في حالةٍ لم يقصدها أحد، أو
 * `shipped_at` يُكتب مرّتين بقيمتين.
 *
 * ─── الترتيب الذي يجعل ذلك مستحيلًا ───
 *
 *   BEGIN → LOCK → READ → VALIDATE → MUTATE → AUDIT → OUTBOX → COMMIT
 *
 * القفل **قبل** القراءة التي يُبنى عليها القرار. الطلب الثاني ينتظر عند
 * `lockForUpdate` حتّى يلتزم الأوّل، ثمّ يقرأ الحالة الجديدة فيفشل تحقّقُه
 * صراحةً بدل أن ينجح على حالةٍ قديمة.
 *
 * ─── لماذا الثلاثة في معاملةٍ واحدة ───
 *
 * الحالة والتدقيق والحدث تصف عمليّةً واحدة. لو التزم التدقيق وسقطت الحالة
 * لبقي في السجلّ «نجحت» لعمليّةٍ لم تقع — وهو أسوأ من غياب السجلّ لأنّه
 * يُصدَّق.
 */
class ShipmentTransitionService
{
    /**
     * خريطة الانتقال: الوجهة ⇦ ما يجوز المجيء منه (§٢٦).
     *
     * الحقيقة هنا وحدها. كانت في وحدة التحكّم، فصار لكلّ مستدعٍ أن يخترع
     * خريطته — والواجهة تعرض أزرارًا يرفضها الخادم أو العكس.
     */
    public const TRANSITIONS = [
        'LABEL_CREATED' => ['PENDING'],
        'PICKED_UP' => ['PENDING', 'LABEL_CREATED'],
        'IN_TRANSIT' => ['PICKED_UP', 'LABEL_CREATED'],
        'OUT_FOR_DELIVERY' => ['IN_TRANSIT'],
        'DELIVERED' => ['OUT_FOR_DELIVERY', 'IN_TRANSIT'],
        'FAILED' => ['PICKED_UP', 'IN_TRANSIT', 'OUT_FOR_DELIVERY'],
        'RETURNED' => ['FAILED', 'OUT_FOR_DELIVERY', 'IN_TRANSIT'],
        'CANCELLED' => ['PENDING', 'LABEL_CREATED'],
    ];

    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly OutboxWriter $outbox,
        private readonly FulfillmentOrchestrator $fulfillment,
    ) {}

    /** ما يجوز من هذه الحالة — تُقرأ منها الواجهة ولا تخترع بديلًا. */
    public static function allowedFrom(string $status): array
    {
        return array_keys(array_filter(
            self::TRANSITIONS,
            static fn (array $from) => in_array($status, $from, true),
        ));
    }

    /**
     * ينقل الشحنة تحت قفلٍ صفّيّ.
     *
     * @throws ValidationException
     */
    public function transition(
        int $shipmentId,
        string $to,
        ?string $trackingNumber,
        AdminUser $actor,
        ?Request $request = null,
        ?string $reason = null,
    ): Shipment {
        return DB::transaction(function () use ($shipmentId, $to, $trackingNumber, $actor, $request, $reason) {
            /*
             * القفل أوّلًا. `findOrFail` قبل القفل كان يقرأ حالةً قد تتغيّر
             * قبل الكتابة — وهي الثغرة بعينها.
             */
            $shipment = Shipment::query()->whereKey($shipmentId)->lockForUpdate()->first();

            if ($shipment === null) {
                throw ValidationException::withMessages(['shipment' => ['لا شحنة بهذا المعرّف.']]);
            }

            // حالةٌ نهائيّة لا يُبنى عليها: التسليم والإلغاء والارتجاع نهايات.
            if ($shipment->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => ["الشحنة في حالةٍ نهائيّة «{$shipment->status}» — لا انتقال بعدها."],
                ]);
            }

            $allowedFrom = self::TRANSITIONS[$to] ?? null;

            if ($allowedFrom === null || ! in_array($shipment->status, $allowedFrom, true)) {
                throw ValidationException::withMessages([
                    'status' => ["لا انتقال من «{$shipment->status}» إلى «{$to}»."],
                ]);
            }

            $before = ['status' => $shipment->status, 'trackingNumber' => $shipment->tracking_number];

            $shipment->status = $to;

            if ($trackingNumber !== null) {
                $shipment->tracking_number = $trackingNumber;
            }

            /*
             * ما خرج من أيدينا يحتاج رقم تتبّع.
             *
             * القيد في المحرّك يفرضه أيضًا، لكنّ الفحص هنا يُنتج رسالةً
             * مفهومة و٤٢٢ بدل خطأ قاعدة بيانات و٥٠٠. القيد يبقى شبكةَ أمانٍ
             * لمن يكتب من خارج هذا المسار، لا بديلًا عن التحقّق.
             */
            if (in_array($to, Shipment::IN_FLIGHT, true) || $to === 'DELIVERED') {
                if (trim((string) $shipment->tracking_number) === '') {
                    throw ValidationException::withMessages([
                        'trackingNumber' => ['شحنةٌ في الطريق تحتاج رقم تتبّع — بدونه لا يتابعها الزبون ولا الدعم.'],
                    ]);
                }
            }

            /*
             * `shipped_at` و`delivered_at` يُكتبان مرّةً واحدة.
             *
             * الكتابةُ بلا شرط تجعل نداءً مكرّرًا يُزيح وقت التسليم إلى الآن،
             * فيبدو أنّ الشحنة سُلّمت متأخّرةً ساعاتٍ عن حقيقتها — ويفسد كلّ
             * قياسٍ لزمن التسليم بُنيت عليه وعودٌ للزبائن.
             */
            if ($to === 'PICKED_UP' && $shipment->shipped_at === null) {
                $shipment->shipped_at = now();
            }

            if ($to === 'DELIVERED' && $shipment->delivered_at === null) {
                $shipment->delivered_at = now();
            }

            $shipment->save();

            $this->audit->log(
                $actor, 'shipment.status.changed', 'shipment', $shipment->id,
                $before,
                ['status' => $shipment->status, 'trackingNumber' => $shipment->tracking_number],
                $reason, $request,
            );

            /*
             * الحدث في المعاملة نفسها (§١١٣): الفهرسة والإشعارات والتحليلات
             * تشترك فيه، ولا تعتمد على وحدة تحكّمٍ تتذكّر نداءها.
             *
             * ومفتاح الخمول من (الشحنة، الوجهة): إعادةُ إرسالٍ لا تُنتج
             * حدثًا ثانيًا لانتقالٍ واحد.
             */
            $this->outbox->write(
                aggregateType: 'shipment',
                aggregateId: (string) $shipment->id,
                eventType: $to === 'DELIVERED' ? 'shipment.delivered' : 'shipment.status.changed',
                payload: [
                    'shipmentId' => $shipment->id,
                    'orderId' => $shipment->order_id,
                    'from' => $before['status'],
                    'to' => $to,
                    'trackingNumber' => $shipment->tracking_number,
                ],
                idempotencyKey: "shipment:{$shipment->id}:{$to}",
            );

            /*
             * §٣٤ — التسليم يُغلق وحدة التنفيذ، والوحدةُ تُغلق الطلب.
             *
             * كانت الشحنة تحمل `fulfillment_unit_id` منذ إنشائها ولا يقرؤه
             * أحد عند التسليم: فتبقى وحدةُ الشحن `PENDING` إلى الأبد وقد وصل
             * الطرد إلى الزبون، ويبقى الطلب «مدفوعًا» لا «مكتملًا». أي أنّ
             * لوحة المشغّل تُريه عملًا مفتوحًا لا عمل فيه.
             */
            if ($to === 'DELIVERED' && $shipment->fulfillment_unit_id !== null) {
                /*
                 * ─── ترتيب القفل: الطلب قبل الوحدة، دائمًا ───
                 *
                 * `RefundService` يقفل `orders` ثمّ يمرّ على `fulfillment_units`.
                 * ولو قفلنا الوحدة هنا ثمّ الطلب (داخل `closeIfDelivered`)
                 * لَصار الترتيبان متعاكسين: استردادٌ وتسليمٌ يقعان معًا على
                 * الطلب نفسه يتشابكان، فيقتل PostgreSQL أحدهما بـ`deadlock
                 * detected`. وهو عطلٌ نادرٌ عشوائيّ المظهر لا يُعاد إنتاجه
                 * إلّا بالحمل.
                 *
                 * فيُقفل الطلب أوّلًا هنا، ويصير الترتيب واحدًا في المسارين.
                 */
                $order = Order::query()->whereKey($shipment->order_id)->lockForUpdate()->first();

                $unit = FulfillmentUnit::query()
                    ->whereKey($shipment->fulfillment_unit_id)
                    ->lockForUpdate()
                    ->first();

                if ($unit !== null && ! $unit->isTerminal()) {
                    $unit->update([
                        'status' => 'FULFILLED',
                        'fulfilled_at' => now(),
                        'pending_reason' => null,
                        'last_error' => null,
                    ]);
                }

                if ($order !== null) {
                    $this->fulfillment->closeIfDelivered($order);
                }
            }

            return $shipment;
        });
    }
}
