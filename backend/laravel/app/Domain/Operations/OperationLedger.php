<?php

namespace App\Domain\Operations;

use App\Domain\Operations\Exceptions\IdempotencyConflict;
use App\Domain\Operations\Models\OperationRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * حجزُ عمليّةٍ ذات أثرٍ خارجيّ، مرّةً واحدة (§٢١).
 *
 * ─── العقد ───
 *
 *   reserve()  ⇦ [السجلّ، هل هو جديد؟]   ← ٤٠٩ إن اختلف الطلب
 *   acquire()  ⇦ السجلّ أو null           ← مالكٌ واحد لا غير
 *   heartbeat()                            ← «ما زلتُ حيًّا»
 *   succeed() · fail() · markUncertain()
 *
 * الجديد يُنفَّذ. وغير الجديد **لا يُنفَّذ** ويُعاد جوابه المحفوظ.
 *
 * ─── لماذا لا يُبقى قفلٌ أثناء نداءٍ شبكيّ ───
 *
 * نداءُ مزوّدٍ قد يستغرق ثوانيَ أو يتعلّق حتّى المهلة، وقفلُ صفٍّ طوالها
 * يُجمّد كلّ من يلمس الصفّ ويستنزف تجمّع الاتّصالات. فالنمط ثلاثيّ:
 *   ١) معاملةٌ قصيرة: اقفل · تحقّق · احجز · التزم.
 *   ٢) التنفيذ الخارجيّ **خارج** أيّ معاملة، تحت عقد إيجارٍ لا قفل.
 *   ٣) معاملةٌ قصيرة: اقفل · وفّق النتيجة · دقّق · أصدر حدثًا · التزم.
 *
 * ─── لماذا السباق يُلتقط بالقيد لا بالفحص ───
 *
 * «افحص ثمّ أدرج» سباقٌ بعينه. الاعتماد على فرادة المفتاح في المحرّك يجعل
 * الخاسر يصطدم بالقيد فيُقرأ سجلّ الفائز — وهو ما لا يمكن أن يُخطئ مهما
 * تزامن الطلبان.
 *
 * ─── الإيجار ليس قفلًا ───
 *
 * القفل يموت بموت الاتّصال، والإيجار ينتهي بمرور الوقت. الفرق حاسم: عاملٌ
 * يُقتل فجأةً يترك قفلًا يُحرَّر فورًا فيلتقط عاملٌ آخر العمليّة ويُعيد
 * تنفيذ أثرٍ قد يكون وقع. الإيجار يبقى إلى أن ينتهي، وانتهاؤه يُحيل
 * العمليّة إلى التوفيق لا إلى إعادةٍ عمياء.
 */
class OperationLedger
{
    /** مهلة الإيجار الافتراضيّة: أطول من أبطأ مزوّدٍ نقبله. */
    public const LEASE_SECONDS = 180;

    /**
     * يحجز المفتاح أو يُعيد الحجز القائم.
     *
     * @param  string|null  $fingerprint  بصمة الطلب؛ اختلافُها عن المحفوظ ٤٠٩.
     * @return array{0: OperationRecord, 1: bool} [السجلّ، جديد؟]
     *
     * @throws IdempotencyConflict
     */
    public function reserve(
        string $key,
        string $kind,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $actorId = null,
        ?string $fingerprint = null,
    ): array {
        $existing = OperationRecord::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            $this->assertSameRequest($existing, $fingerprint);

            return [$existing, false];
        }

        try {
            $record = OperationRecord::create([
                'idempotency_key' => $key,
                'kind' => $kind,
                'request_fingerprint' => $fingerprint,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'status' => 'RESERVED',
                'correlation_id' => (string) Str::uuid(),
                'actor_admin_user_id' => $actorId,
            ]);

            return [$record, true];
        } catch (QueryException $e) {
            /*
             * خسر السباق: طلبٌ متزامن حجز المفتاح بين فحصنا وإدراجنا.
             * القيد الفريد هو ما التقطه — لا فحصٌ كان سيفوته.
             */
            $winner = OperationRecord::query()->where('idempotency_key', $key)->first();

            if ($winner === null) {
                throw $e; // فشلٌ آخر غير التصادم
            }

            $this->assertSameRequest($winner, $fingerprint);

            return [$winner, false];
        }
    }

    /**
     * بصمةٌ محفوظة تختلف عن المرسلة ⇦ ٤٠٩.
     *
     * الغياب لا يُقارن: سجلّاتٌ سبقت العمود، وطلباتٌ لا جسم لها. واختراع
     * بصمةٍ للغائب كان سيرفض إعادة إرسالٍ مشروعة.
     */
    private function assertSameRequest(OperationRecord $record, ?string $fingerprint): void
    {
        if ($fingerprint === null || $record->request_fingerprint === null) {
            return;
        }

        if (! hash_equals((string) $record->request_fingerprint, $fingerprint)) {
            throw new IdempotencyConflict($record);
        }
    }

    /**
     * يستحوذ على الإيجار — أو لا يستحوذ.
     *
     * تحديثٌ شرطيّ واحد لا «اقرأ ثمّ اكتب»: الشرط يُقيَّم داخل المحرّك، فلا
     * فجوة بين الفحص والكتابة يتسلّل منها عاملٌ ثانٍ. وعدد الصفوف المتأثّرة
     * هو الجواب: واحدٌ يعني فزنا، وصفرٌ يعني أنّ لها مالكًا حيًّا.
     */
    public function acquire(OperationRecord $record, string $owner, int $seconds = self::LEASE_SECONDS): ?OperationRecord
    {
        $now = now();

        $claimed = OperationRecord::query()
            ->whereKey($record->id)
            ->whereIn('status', ['RESERVED', 'PROCESSING', 'UNCERTAIN'])
            ->where(function ($q) use ($now, $owner) {
                $q->whereNull('lease_owner')
                    ->orWhere('lease_owner', $owner)       // تجديدُ المالك نفسه
                    ->orWhere('lease_expires_at', '<', $now); // إيجارٌ مات صاحبه
            })
            ->update([
                'lease_owner' => $owner,
                'lease_expires_at' => $now->copy()->addSeconds($seconds),
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

        return $claimed === 1 ? $record->fresh() : null;
    }

    /** ينتقل بالعمليّة إلى «قيد التنفيذ» ويزيد عدّاد المحاولات. */
    public function markProcessing(OperationRecord $record): OperationRecord
    {
        return DB::transaction(function () use ($record) {
            $locked = OperationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            if ($locked->isFinished()) {
                return $locked; // النهائيّ نهائيّ — كما في succeed/fail/markUncertain.
            }

            $locked->update([
                'status' => 'PROCESSING',
                'attempts' => $locked->attempts + 1,
                'started_at' => $locked->started_at ?? now(),
            ]);

            return $locked;
        });
    }

    /**
     * «ما زلتُ حيًّا» — يمدّ الإيجار ولا يُنشئه.
     *
     * المالك الآخر لا يُمدَّد له: نبضٌ من عاملٍ فقد إيجاره يجب أن يفشل، لا
     * أن يسرقه ممّن التقطه بعده.
     */
    public function heartbeat(OperationRecord $record, string $owner, int $seconds = self::LEASE_SECONDS): bool
    {
        $now = now();

        return OperationRecord::query()
            ->whereKey($record->id)
            ->where('lease_owner', $owner)
            ->update([
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds($seconds),
                'updated_at' => $now,
            ]) === 1;
    }

    /**
     * ينهي العمليّة بنجاح ويحفظ جوابها للتكرار.
     *
     * @param  array<string, mixed>  $result
     */
    public function succeed(OperationRecord $record, array $result, ?string $providerReference = null): OperationRecord
    {
        return DB::transaction(function () use ($record, $result, $providerReference) {
            $locked = OperationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            // نهائيّةٌ لا يُكتب فوقها: تسليمٌ مكرّر من الطابور لا يُعيد التنفيذ.
            if ($locked->status === 'SUCCEEDED') {
                return $locked;
            }

            $locked->update([
                'status' => 'SUCCEEDED',
                'result' => $result,
                'completed_at' => now(),
                'provider_reference' => $providerReference ?? $locked->provider_reference,
                'provider_state' => 'CONFIRMED',
                'lease_owner' => null,
                'lease_expires_at' => null,
            ]);

            return $locked;
        });
    }

    /**
     * ينهي العمليّة فشلًا — ويحفظ جوابها إن كان الفشل جوابًا يُعاد.
     *
     * الرفض المصنَّف (مصدرٌ مكرّر، حلقة، هدفٌ ٤٠٤) نتيجةٌ حتميّة للطلب نفسه
     * لا عطلٌ عابر. وحفظُه يجعل إعادة الإرسال بالمفتاح نفسه تُعيد **الرفض
     * ذاته** بدل «عمليّةٌ قيد التنفيذ» — وهي رسالةٌ لا تقول للمشغّل شيئًا
     * عمّا يجب تغييره.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function fail(
        OperationRecord $record,
        string $failureClass,
        string $message,
        ?array $result = null,
    ): OperationRecord {
        return DB::transaction(function () use ($record, $failureClass, $message, $result) {
            $locked = OperationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'SUCCEEDED') {
                return $locked; // نجاحٌ سابق لا يُقلب فشلًا بتسليمٍ متأخّر
            }

            $locked->update([
                'status' => 'FAILED',
                'failure_class' => $failureClass,
                // الرسالة تُقصّ ولا تُطمس: الحقل محدود والسبب يجب أن يبقى مقروءًا.
                'last_error' => mb_substr($message, 0, 1000),
                'result' => $result ?? $locked->result,
                'completed_at' => now(),
                'lease_owner' => null,
                'lease_expires_at' => null,
            ]);

            return $locked;
        });
    }

    /**
     * النتيجة مجهولة — وهذا **ليس** فشلًا.
     *
     * انتهت المهلة، أو مات العامل، أو أجاب المزوّد بما لا يُفهم. الأثر قد
     * يكون وقع. تسجيلُه فشلًا يدعو إلى إعادةٍ تُنفّذه مرّتين، وتسجيلُه
     * نجاحًا يَعِد بما لم يقع. فالحقيقة الوحيدة الصادقة: لا نعرف بعد.
     */
    public function markUncertain(
        OperationRecord $record,
        string $message,
        ?string $providerReference = null,
    ): OperationRecord {
        return DB::transaction(function () use ($record, $message, $providerReference) {
            $locked = OperationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            if ($locked->isFinished()) {
                return $locked;
            }

            $locked->update([
                'status' => 'UNCERTAIN',
                'last_error' => mb_substr($message, 0, 1000),
                'provider_reference' => $providerReference ?? $locked->provider_reference,
                'provider_state' => 'UNKNOWN',
                'lease_owner' => null,
                'lease_expires_at' => null,
            ]);

            return $locked;
        });
    }

    /** يسجّل ما قاله المزوّد عن الأثر بعد السؤال، ويعدّ محاولات التوفيق. */
    public function recordProviderState(OperationRecord $record, string $state): OperationRecord
    {
        return DB::transaction(function () use ($record, $state) {
            $locked = OperationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'provider_state' => $state,
                'reconciled_at' => now(),
                'reconcile_attempts' => $locked->reconcile_attempts + 1,
            ]);

            return $locked;
        });
    }

    /**
     * عمليّاتٌ فقدت عاملها: «قيد التنفيذ» بإيجارٍ منتهٍ، أو «مجهولة».
     *
     * @return Collection<int, OperationRecord>
     */
    public const RESERVED_GRACE_MINUTES = 10;

    public function stale(int $limit = 100): Collection
    {
        return OperationRecord::query()
            ->where(function ($q) {
                $q->where('status', 'UNCERTAIN')
                    ->orWhere(fn ($inner) => $inner->where('status', 'PROCESSING')
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<', now()))
                    /*
                     * «محجوزة» أطول من المهلة: حُجزت ثمّ ضاعت مهمّتها أو مات
                     * الطلب قبل الاستحواذ. بلا هذا تبقى إلى الأبد ويُحرَق
                     * مفتاحها — كلّ إعادةٍ تردّ «مكرّر» بلا نتيجة.
                     */
                    ->orWhere(fn ($inner) => $inner->where('status', 'RESERVED')
                        ->whereNull('lease_owner')
                        ->where('created_at', '<', now()->subMinutes(self::RESERVED_GRACE_MINUTES)));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
