<?php

namespace App\Domain\Outbox;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes outbox rows. MUST be called inside an existing database transaction —
 * business state + outbox row commit atomically or not at all.
 */
class OutboxWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function write(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): OutboxEvent {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'OutboxWriter::write must be called inside a database transaction.'
            );
        }

        // Idempotent: a key already written is returned untouched, not duplicated.
        $existing = OutboxEvent::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        /*
         * القراءة أعلاه مسارٌ سريع لا ضمان.
         *
         * بين `first()` و`create()` نافذةٌ يكتب فيها طلبٌ آخر المفتاح نفسه،
         * فيرى الفحصُ لا شيء ويحاول الاثنان الإدراج. القيد الفريد على
         * `idempotency_key` هو الحَكَم الحقيقيّ (‏`000008:19`)، والفحص السابق
         * لا يحلّ محلّه — سباقٌ بعينه.
         *
         * ولماذا `DB::transaction` هنا وقد اشترطنا وجود معاملةٍ خارجيّة؟
         * لأنّ PostgreSQL يُجهض المعاملة كلَّها عند خرق القيد: أيّ استعلامٍ
         * بعده يردّ «current transaction is aborted». والمعاملة المتداخلة في
         * Laravel تُترجَم إلى SAVEPOINT، فالتراجع يقف عندها وتبقى معاملة
         * المُنادي صالحةً لتُكمل عملها. (ولذلك لا يصلح هنا نمط `UrlRegistry`
         * الذي يلتقط **خارج** معاملته ويعيد المحاولة من أوّلها.)
         */
        try {
            return DB::transaction(fn () => OutboxEvent::query()->create([
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'event_type' => $eventType,
                'payload' => $payload,
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
                'status' => 'PENDING',
                'attempts' => 0,
                'next_retry_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            /* سبقنا غيرُنا بالمفتاح نفسه — صفُّه هو الجواب، ولا يُكتب ثانٍ. */
            return OutboxEvent::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }
}
