<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Models\AiSuggestion;
use Illuminate\Support\Facades\DB;

/**
 * مستودع الاقتراحات — يكتب مرشَّحاتٍ ولا يمسّ بيانات المتجر.
 *
 * ─── الحدّ الذي لا يُعبر ───
 *
 * هذا الصنف لا يعرف جدول المنتجات ولا يكتب فيه. الاقتراح صفٌّ في
 * `ai_suggestions` بحالة `PENDING` ومصدرٍ خارج `AttributeValue::CANONICAL_SOURCES`،
 * ولا يصير بياناتٍ إلّا بفعل اعتمادٍ صريحٍ من مشرفٍ مسمًّى. القاعدة مفروضة
 * بقيدٍ في قاعدة البيانات لا بانضباط الكاتب.
 *
 * ─── والخمول ───
 *
 * المفتاح الفريد (الكيان، الحقل، بصمة المدخل) يعني أنّ إعادة تشغيل الإثراء
 * على محتوًى لم يتغيّر لا تُنتج صفًّا ثانيًا. والمشرف لا يرى الاقتراح نفسه
 * مرّتين، ولا يُنقَض قرارٌ سابق برفضه.
 */
final class AiSuggestionStore
{
    /**
     * @param  array<string, mixed>  $value
     */
    public function put(
        string $entityType,
        int $entityId,
        string $field,
        AiTask $task,
        array $value,
        AiResponse $response,
        string $inputHash,
    ): ?AiSuggestion {
        if ($value === []) {
            /*
             * قيمةٌ فارغة ليست اقتراحًا. تخزينُها يملأ صندوق المشرف بأسطرٍ لا
             * فعل لها، ويجعل عدّاد «ينتظر المراجعة» كذبًا.
             */
            return null;
        }

        return DB::transaction(function () use ($entityType, $entityId, $field, $task, $value, $response, $inputHash): ?AiSuggestion {
            $existing = AiSuggestion::query()
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('field', $field)
                ->where('input_hash', $inputHash)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // مبتوتٌ فيه أو معلّق: لا يُلمس. القرار السابق يبقى.
                return $existing->isPending() ? $existing : null;
            }

            /*
             * اقتراحٌ أقدم للحقل نفسه بمدخلٍ مختلف يصير `SUPERSEDED`: المحتوى
             * تغيّر فالاقتراح القديم صار عن نصٍّ لم يعد موجودًا. تركُه معلّقًا
             * يجعل المشرف يعتمد وصفًا لمنتجٍ آخر.
             */
            AiSuggestion::query()
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('field', $field)
                ->where('status', 'PENDING')
                ->update(['status' => 'SUPERSEDED', 'updated_at' => now()]);

            return AiSuggestion::query()->create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'field' => $field,
                'task' => $task->value,
                'value' => $value,
                'model' => $response->model,
                'prompt_id' => $response->promptId,
                'prompt_version' => $response->promptVersion,
                'input_hash' => $inputHash,
                'confidence_pct' => $response->confidence === null ? null : (int) round($response->confidence * 100),
                'source' => 'AI_SUGGESTED',
                'status' => 'PENDING',
            ]);
        });
    }
}
