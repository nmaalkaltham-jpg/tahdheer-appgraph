<?php

namespace App\Domain\Operations\Jobs;

use App\Domain\Assets\Exceptions\SourceNotConfigured;
use App\Domain\Assets\Exceptions\SourceOutcomeUnknown;
use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use App\Domain\Outbox\OutboxWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * إعادة محاولة تنفيذٍ — خارج دورة الطلب (§٩ §٢١).
 *
 * ─── لماذا لم تعد في وحدة التحكّم ───
 *
 * التنفيذ قد ينادي مزوّدًا خارجيًّا (Drive، مزوّد رخص، ناقل). ونداءٌ كهذا
 * داخل طلب HTTP إداريّ يربط زمن استجابة اللوحة بزمن استجابة طرفٍ ثالث:
 * تتعلّق الصفحة، ينتهي وقت الوكيل، يُعيد المشغّل النقر — فيُنفَّذ الفعل
 * مرّتين بينما هو يظنّه لم يقع مرّة.
 *
 * ─── الخمول ───
 *
 * المفتاح `fulfillment_retry:{unit}:{generation}` محجوزٌ في قاعدة البيانات
 * قبل الإرسال. وتسليمُ الطابور المكرّر — وهو أمرٌ عاديّ لا استثنائيّ —
 * يجد الحجز منتهيًا فيخرج بلا تنفيذ.
 *
 * ─── لماذا لا معاملة حول النداء الخارجيّ ───
 *
 * قفلُ صفٍّ طوال انتظار شبكةٍ يُجمّد كلّ من يلمس الصفّ ويستنزف تجمّع
 * الاتّصالات. فالمعاملة تُفتح للتوفيق بعد عودة النتيجة، لا حولها.
 */
class RetryFulfillmentUnitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** محاولاتٌ محدودة: ما يفشل ثلاثًا يحتاج إنسانًا لا محاولةً رابعة. */
    public int $tries = 3;

    public int $timeout = 120;

    /** تراجعٌ أسّيّ — والتشويش يمنع اصطفاف كلّ المحاولات على اللحظة نفسها. */
    public function backoff(): array
    {
        return [5 + random_int(0, 5), 30 + random_int(0, 15), 120 + random_int(0, 60)];
    }

    public function __construct(
        public readonly int $operationId,
        public readonly int $unitId,
    ) {}

    public function handle(
        OperationLedger $ledger,
        FulfillmentOrchestrator $orchestrator,
        OutboxWriter $outbox,
    ): void {
        $record = OperationRecord::query()->find($this->operationId);

        if ($record === null || $record->isFinished()) {
            // تسليمٌ مكرّر بعد حسم: لا شيء يُفعل، ولا خطأ يُرمى.
            return;
        }

        /*
         * مجهولةُ النتيجة لا تُعاد من هنا. `operations:recover` يسأل المزوّد
         * أوّلًا، وإعادتُها قبل ذلك هي بالضبط التنفيذ المزدوج الذي نمنعه.
         */
        if ($record->isUncertain()) {
            Log::info('fulfillment.retry.deferred_to_reconciliation', ['operationId' => $record->id]);

            return;
        }

        /*
         * الإيجار لا القفل: العامل قد يُقتل، والقفل يُحرَّر فورًا بموت
         * الاتّصال فيلتقط عاملٌ آخر العمليّة ويُعيد أثرًا قد يكون وقع.
         */
        $owner = 'job:'.Str::uuid();

        if ($ledger->acquire($record, $owner) === null) {
            Log::info('fulfillment.retry.lease_held', ['operationId' => $record->id]);

            return;
        }

        $ledger->markProcessing($record);

        $unit = FulfillmentUnit::query()->with('order.items')->find($this->unitId);

        if ($unit === null) {
            $ledger->fail($record, 'NOT_FOUND', 'وحدة التنفيذ لم تعد موجودة.');

            return;
        }

        /*
         * §٣٤ — لا تنفيذ بعد خروج المال.
         *
         * `revokeAll` يُلغي الوحدات المفتوحة عند الردّ، لكنّ محاولةً كانت
         * في الطابور حين وقع الردّ تصل بعده وتحمل وحدةً قرأتها قبل الإلغاء.
         * فتُنشأ شحنةٌ أو يُحرَق مفتاحٌ لطلبٍ استُرد ثمنه — وهو خسارةٌ صافية.
         * والفحص هنا لا في المنسّق: المنسّق يُنفّذ ما يُطلب منه، والقرار
         * «هل ما زال هذا الطلب يستحقّ تنفيذًا» قرارُ المستدعي.
         */
        $status = $unit->order?->status;

        if (in_array($status, ['REFUNDED', 'CANCELLED', 'FAILED'], true)) {
            $ledger->fail($record, 'ORDER_NOT_PAYABLE', "الطلب في حالة {$status} — لا يُنفَّذ ما رُدّ ثمنه أو أُلغي.");

            return;
        }

        try {
            $orchestrator->preflight($unit->order->fresh('items'));

            /*
             * المنسّق خاملُ التكرار بذاته: الوحدات المنجزة لا تُمسّ والمعلّقة
             * تُعاد محاولتها. والمعاملة هنا تحيط بالتوفيق وحده.
             */
            DB::transaction(function () use ($orchestrator, $unit, $outbox, $record) {
                $orchestrator->fulfill($unit->order->fresh('items'));

                $fresh = $unit->fresh();

                $outbox->write(
                    aggregateType: 'fulfillment_unit',
                    aggregateId: (string) $unit->id,
                    eventType: $fresh->status === 'FULFILLED'
                        ? 'fulfillment.succeeded'
                        : ($fresh->status === 'FAILED' ? 'fulfillment.failed' : 'fulfillment.pending'),
                    payload: [
                        'unitId' => $unit->id,
                        'orderId' => $unit->order_id,
                        'strategy' => $unit->strategy,
                        'status' => $fresh->status,
                        'reason' => $fresh->pending_reason ?? $fresh->last_error,
                    ],
                    idempotencyKey: "fulfillment:{$unit->id}:retry:{$record->id}",
                    correlationId: $record->correlation_id,
                );
            });

            $fresh = $unit->fresh();

            $ledger->succeed($record, [
                'unitId' => $unit->id,
                'status' => $fresh->status,
                'reason' => $fresh->pending_reason ?? $fresh->last_error,
            ]);
        } catch (SourceOutcomeUnknown $e) {
            /*
             * الجواب ضاع لا الطلب. تُترك مجهولةً ليسألها التوفيق، ولا
             * تُرمى ثانيةً: إعادةُ الطابور هنا تنفيذٌ أعمى.
             */
            $ledger->markUncertain($record, $e->getMessage(), $e->reference);
        } catch (ConnectionException $e) {
            $ledger->markUncertain($record, 'انقطع الاتّصال قبل معرفة النتيجة: '.$e->getMessage());
        } catch (SourceNotConfigured $e) {
            // إعدادٌ ناقص عندنا — إعادةُ المحاولة لن تُصلحه.
            $ledger->fail($record, 'NOT_CONFIGURED', $e->getMessage());
        } catch (SourceUnavailable $e) {
            $ledger->fail($record, 'PROVIDER_UNAVAILABLE', $e->getMessage());
            throw $e; // مؤقّت: يستحقّ محاولةً أخرى من الطابور
        } catch (Throwable $e) {
            Log::error('fulfillment.retry.failed', [
                'operationId' => $record->id,
                'unitId' => $this->unitId,
                'correlationId' => $record->correlation_id,
                'error' => $e->getMessage(),
            ]);

            $ledger->fail($record, 'TEMPORARY', $e->getMessage());
            throw $e;
        }
    }

    /**
     * آخر محاولةٍ فشلت.
     *
     * الحالة تُثبَّت فلا تبقى «قيد التنفيذ» إلى الأبد — لكنّ المجهولة تبقى
     * مجهولة: قلبُها فشلًا هنا يسمح بإعادةٍ لم يُسأل عنها المزوّد بعد.
     */
    public function failed(Throwable $e): void
    {
        $record = OperationRecord::query()->find($this->operationId);

        if ($record !== null && ! $record->isFinished() && ! $record->isUncertain()) {
            app(OperationLedger::class)->fail($record, 'TEMPORARY', $e->getMessage());
        }
    }
}
