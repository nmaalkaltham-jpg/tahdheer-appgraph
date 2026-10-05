<?php

namespace App\Domain\Returns;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Returns\Models\ReturnRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * سياسة انتقال طلب الإرجاع — الجهة الوحيدة التي تغيّر حالته (§٣٣).
 *
 * العيب والترتيب نفسهما الموصوفان في `ShipmentTransitionService`: قراءةٌ
 * فتحقّقٌ فكتابةٌ خارج معاملة تجعل قرارين متزامنين يمرّان معًا. وهنا الثمن
 * أثقل: قرارٌ بالقبول وقرارٌ بالرفض على الطلب نفسه ينتهيان بحالةٍ واحدة
 * فائزة عشوائيًّا، وربّما بمالٍ يُردّ على بضاعةٍ رُفض إرجاعها.
 */
class ReturnTransitionService
{
    /**
     * خريطة الانتقال (§٣٣).
     *
     * الفحص يسبق ردّ المال في **الخريطة** لا في الإرشادات: `REFUNDED` لا
     * تُقبل إلّا من `INSPECTED`. بغير ذلك يُردّ ثمنُ صندوقٍ لم يصل ولم يُفحص.
     */
    public const TRANSITIONS = [
        'APPROVED' => ['REQUESTED'],
        'REJECTED' => ['REQUESTED', 'INSPECTED'],
        'IN_TRANSIT' => ['APPROVED'],
        'RECEIVED' => ['APPROVED', 'IN_TRANSIT'],
        'INSPECTED' => ['RECEIVED'],
        'REFUNDED' => ['INSPECTED'],
        'CLOSED' => ['REJECTED', 'REFUNDED', 'INSPECTED'],
    ];

    /** نهاياتٌ لا يُبنى عليها. */
    public const TERMINAL = ['CLOSED'];

    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @return list<string> */
    public static function allowedFrom(string $status): array
    {
        return array_keys(array_filter(
            self::TRANSITIONS,
            static fn (array $from) => in_array($status, $from, true),
        ));
    }

    /**
     * @throws ValidationException
     */
    public function transition(
        int $returnId,
        string $to,
        ?string $note,
        AdminUser $actor,
        ?Request $request = null,
    ): ReturnRequest {
        return DB::transaction(function () use ($returnId, $to, $note, $actor, $request) {
            $return = ReturnRequest::query()->whereKey($returnId)->lockForUpdate()->first();

            if ($return === null) {
                throw ValidationException::withMessages(['return' => ['لا طلب إرجاعٍ بهذا المعرّف.']]);
            }

            if (in_array($return->status, self::TERMINAL, true)) {
                throw ValidationException::withMessages([
                    'status' => ["طلب الإرجاع مُغلق — لا انتقال بعد «{$return->status}»."],
                ]);
            }

            $allowedFrom = self::TRANSITIONS[$to] ?? null;

            if ($allowedFrom === null || ! in_array($return->status, $allowedFrom, true)) {
                throw ValidationException::withMessages([
                    'status' => ["لا انتقال من «{$return->status}» إلى «{$to}»."],
                ]);
            }

            /*
             * الرفض يحتاج سببًا مكتوبًا. القيد في المحرّك يمنع الصفّ، والفحص
             * هنا يُعطي رسالةً مفهومة بدل خطأ قاعدة بيانات.
             */
            if ($to === 'REJECTED' && trim((string) $note) === '') {
                throw ValidationException::withMessages([
                    'note' => ['الرفض يحتاج سببًا مكتوبًا — يُشرح للزبون ويُراجَع لاحقًا.'],
                ]);
            }

            $before = ['status' => $return->status];

            $return->status = $to;
            $return->resolution_note = $note ?? $return->resolution_note;

            if ($to === 'REJECTED') {
                $return->rejection_reason = $note;
            }

            /* كلّ ختمٍ يُكتب مرّةً: نداءٌ مكرّر لا يُزيح تاريخًا وقع. */
            foreach ([
                'APPROVED' => 'approved_at',
                'RECEIVED' => 'received_at',
                'INSPECTED' => 'inspected_at',
                'REFUNDED' => 'refunded_at',
                'CLOSED' => 'closed_at',
            ] as $state => $column) {
                if ($to === $state && $return->{$column} === null) {
                    $return->{$column} = now();
                }
            }

            $return->save();

            $this->audit->log(
                $actor, 'return.status.changed', 'return_request', $return->id,
                $before, ['status' => $return->status], $note, $request,
            );

            $this->outbox->write(
                aggregateType: 'return_request',
                aggregateId: (string) $return->id,
                eventType: match ($to) {
                    'APPROVED' => 'return.approved',
                    'REJECTED' => 'return.rejected',
                    'REFUNDED' => 'return.refunded',
                    default => 'return.status.changed',
                },
                payload: [
                    'returnId' => $return->id,
                    'orderId' => $return->order_id,
                    'from' => $before['status'],
                    'to' => $to,
                ],
                idempotencyKey: "return:{$return->id}:{$to}",
            );

            return $return;
        });
    }
}
