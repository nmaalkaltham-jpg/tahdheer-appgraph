<?php

namespace App\Domain\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polls due PENDING outbox rows with FOR UPDATE SKIP LOCKED (PostgreSQL;
 * a plain no-op lock on sqlite), dispatches to registered consumers, and
 * applies exponential backoff: 1, 5, 25, 125 minutes. DEAD after 5 attempts.
 */
class OutboxProcessor
{
    public const MAX_ATTEMPTS = 5;

    /**
     * صفٌّ «قيد المعالجة» أطول من هذا عاملُه مات: يُستعاد ويُعدّ محاولةً.
     * المستهلكون هنا يُرسلون مهامَّ ويُبطلون ذاكرةً — لا يعملون دقائق.
     */
    public const CLAIM_TIMEOUT_MINUTES = 15;

    /**
     * @return array{processed: int, failed: int, dead: int}
     */
    public function process(int $limit = 50): array
    {
        $stats = ['processed' => 0, 'failed' => 0, 'dead' => 0];

        // Claim due rows atomically so parallel workers never double-consume.
        $claimed = DB::transaction(function () use ($limit) {
            $query = OutboxEvent::query()
                ->where(fn ($q) => $q
                    ->where(fn ($due) => $due->where('status', 'PENDING')
                        ->where(fn ($r) => $r->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now())))
                    /*
                     * كان الصفّ يُعلَّم «قيد المعالجة» ثمّ يموت العامل فيبقى
                     * كذلك إلى الأبد: لا يُلتقط ولا يموت ولا يُعاد. الإيجار
                     * هنا زمنيّ لا اتّصاليّ (كما في سجلّ العمليّات).
                     */
                    ->orWhere(fn ($stale) => $stale->where('status', 'PROCESSING')
                        ->where('updated_at', '<', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES))))
                ->orderBy('created_at')
                ->limit($limit);

            if (DB::getDriverName() === 'pgsql') {
                $query->lock('for update skip locked');
            } else {
                $query->lockForUpdate();
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                if ($row->status === 'PROCESSING') {
                    Log::warning('outbox.claim_reclaimed', [
                        'event_id' => $row->id, 'event_type' => $row->event_type,
                        'attempt' => $row->attempts + 1, 'claimed_at' => $row->updated_at?->toJSON(),
                    ]);
                    // استعادةٌ = محاولةٌ فاشلة، فلا يدور حدثٌ سامّ إلى الأبد.
                    $row->attempts = $row->attempts + 1;
                }

                $row->forceFill(['status' => 'PROCESSING', 'attempts' => $row->attempts, 'updated_at' => now()])->save();
            }

            return $rows;
        });

        foreach ($claimed as $event) {
            if ($event->attempts >= self::MAX_ATTEMPTS) {
                Log::error('outbox.event_dead', ['event_id' => $event->id, 'event_type' => $event->event_type, 'reason' => 'reclaimed_too_often']);
                $event->update(['status' => 'DEAD']);
                $stats['dead']++;

                continue;
            }

            try {
                $consumers = OutboxRegistry::consumersFor($event->event_type);

                if ($consumers === []) {
                    // أهدأ أنواع الانقطاع: حدثٌ يُكتب ويُعلَّم «معالَجًا» ولا يقرؤه أحد.
                    Log::warning('outbox.no_consumer', ['event_id' => $event->id, 'event_type' => $event->event_type]);
                }

                foreach ($consumers as $consumer) {
                    $consumer($event);
                }

                $event->update(['status' => 'PROCESSED', 'processed_at' => now()]);
                $stats['processed']++;
            } catch (Throwable $e) {
                $attempts = $event->attempts + 1;

                /*
                 * السبب يُسجَّل قبل أي قرار.
                 *
                 * كان الاستثناء يُبتلَع كاملًا: يُحدَّث الصفّ إلى PENDING أو
                 * DEAD ولا يُكتب سطر واحد. حدثٌ يموت بعد أربع محاولات كان
                 * يترك عمودًا رقميًّا فقط — بلا صنف الاستثناء ولا رسالته ولا
                 * موضعه — فيصير سؤال «لماذا لم يصل بريد تأكيد الطلب؟» بلا
                 * جواب ممكن.
                 *
                 * الموت يُسجَّل بمستوى error لأنه فقدان حدث نهائي؛ وإعادة
                 * المحاولة بمستوى warning لأنها قد تنجح.
                 */
                $context = [
                    'event_id' => $event->id,
                    'event_type' => $event->event_type,
                    'aggregate' => $event->aggregate_type.':'.$event->aggregate_id,
                    'attempt' => $attempts,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'at' => basename($e->getFile()).':'.$e->getLine(),
                ];

                if ($attempts >= self::MAX_ATTEMPTS) {
                    Log::error('outbox.event_dead', $context);
                } else {
                    Log::warning('outbox.event_retry', $context);
                }

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $event->update(['status' => 'DEAD', 'attempts' => $attempts]);
                    $stats['dead']++;
                } else {
                    $event->update([
                        'status' => 'PENDING',
                        'attempts' => $attempts,
                        'next_retry_at' => now()->addMinutes(5 ** ($attempts - 1)), // 1, 5, 25, 125
                    ]);
                    $stats['failed']++;
                }
            }
        }

        return $stats;
    }
}
