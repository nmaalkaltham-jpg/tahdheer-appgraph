<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Models\AiRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * دفتر النداءات — دورة حياةٍ واحدة يملكها موضعٌ واحد.
 *
 * ─── لماذا الصفّ يُنشأ قبل التنفيذ ───
 *
 * كان الأثر يُكتب بعد انتهاء النداء، فالمهمّة المنتظرة في الطابور لا وجود لها
 * في أيّ جدول: لا تُرى ولا تُلغى. والمشغّل الذي جدول تحليل مئة منتجٍ ثمّ ندم
 * لم يكن يملك إلّا إطفاء الذكاء كلّه.
 *
 * فالصفّ يُحجز عند الجدولة بحالة `QUEUED`، وتقرؤه المهمّة قبل أن تبدأ. والإلغاء
 * تغييرُ حالةٍ لا محاولةَ انتزاع مهمّةٍ من طابور — وهو ما يعمل مهما كان
 * السائق.
 *
 * ─── ولا مسار تنفيذٍ ثانٍ ───
 *
 * الدفتر لا يستدلّ ولا ينادي النموذج. يحجز ويُحدّث، والاستدلال يبقى في
 * البوّابة وحدها بكلّ ما تفرضه من رايات وحدودٍ وقاطع.
 */
final class AiRequestLedger
{
    /** ما يمكن جدولته اليوم — كلّ مدخلٍ له مهمّةٌ تنفّذه فعلًا. */
    public const SCHEDULABLE = ['product' => 'إثراء منتج'];

    /**
     * حجزُ نداءٍ في الطابور.
     *
     * يُنشأ قبل الإرسال لا بعده: مهمّةٌ تُرسَل ثمّ يُكتب صفّها قد تبدأ قبل أن
     * يوجد ما تقرؤه، فتتجاوز فحص الإلغاء.
     */
    public function queue(
        AiTask $task,
        string $promptId,
        string $entityType,
        int $entityId,
        ?int $actorId,
        ?string $field = null,
        ?int $retryOfId = null,
    ): AiRun {
        if (! array_key_exists($entityType, self::SCHEDULABLE)) {
            throw new RuntimeException("لا مهمّة تنفّذ نداءً على «{$entityType}».");
        }

        return AiRun::query()->create([
            'task' => $task->value,
            'prompt_id' => $promptId,
            'prompt_version' => 0,
            'model' => (string) config('ai.engine.model'),
            'provider' => 'local',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field' => $field,
            'actor_admin_user_id' => $actorId,
            /*
             * البصمة تُملأ عند التنفيذ حين يُعرف المدخل. وصفرُ محارفٍ هنا
             * مؤقّت: العمود `char(64)` فيُملأ بأصفار لا يُترك فارغًا.
             */
            'input_hash' => str_repeat('0', 64),
            'status' => 'QUEUED',
            'attempts' => 1,
            'retry_of_id' => $retryOfId,
            'cost_minor' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** بدء التنفيذ — تُنادى من المهمّة، وتردّ `false` إن أُلغي النداء. */
    public function start(int $runId): bool
    {
        /*
         * تحديثٌ شرطيّ ذرّيّ: إلغاءٌ يقع بين القراءة والكتابة كان سيُداس لو
         * قُرئت الحالة ثمّ كُتبت في خطوتين.
         */
        return DB::table('ai_runs')
            ->where('id', $runId)
            ->where('status', 'QUEUED')
            ->update(['status' => 'RUNNING', 'started_at' => now(), 'updated_at' => now()]) === 1;
    }

    /**
     * إلغاءُ نداءٍ لم يبدأ.
     *
     * الجاري لا يُلغى: قطعُ استدلالٍ في منتصفه يترك المقعد محجوزًا ولا يوفّر
     * شيئًا — الحساب وقع. والمنتهي لا يُلغى بداهةً.
     */
    public function cancel(AiRun $run): bool
    {
        return DB::table('ai_runs')
            ->where('id', $run->id)
            ->where('status', 'QUEUED')
            ->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]) === 1;
    }

    /**
     * هل يمكن إعادة هذا النداء؟
     *
     * الإعادة تعني إعادة تنفيذ المهمّة نفسها على الكيان نفسه. ولا يُخزَّن
     * المدخل نصًّا — بصمته وحدها — فنداءٌ بلا كيانٍ لا يمكن إعادة بنائه.
     * وهذا قيدُ خصوصيّةٍ مقصود لا نقص: سجلٌّ يقرؤه كلّ من يملك `audit.read`
     * لا يجوز أن يحمل نصّ منتجٍ مدفوع أو سؤال عميل.
     */
    public function canRetry(AiRun $run): bool
    {
        return in_array($run->status, ['FAILED', 'REJECTED', 'CANCELLED'], true)
            && $run->entity_type !== null
            && $run->entity_id !== null
            && array_key_exists((string) $run->entity_type, self::SCHEDULABLE);
    }

    /** سببُ تعذّر الإعادة — يُعرض للمشغّل بدل زرٍّ يفشل بصمت. */
    public function retryBlocker(AiRun $run): ?string
    {
        if (in_array($run->status, ['QUEUED', 'RUNNING'], true)) {
            return 'النداء لم ينتهِ بعد.';
        }

        if ($run->status === 'SUCCEEDED') {
            return 'النداء نجح — لا شيء يُعاد.';
        }

        if ($run->entity_type === null || $run->entity_id === null) {
            return 'لا كيان مرتبط بهذا النداء، والمدخل غير مخزَّن نصًّا (سياسة خصوصيّة) فلا يمكن إعادة بنائه.';
        }

        if (! array_key_exists((string) $run->entity_type, self::SCHEDULABLE)) {
            return "لا مهمّة تنفّذ نداءً على «{$run->entity_type}».";
        }

        return null;
    }
}
