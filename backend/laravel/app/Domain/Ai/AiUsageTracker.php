<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Models\AiRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * أثر التشغيل — يُكتب دائمًا، نجح النداء أم فشل.
 *
 * ─── لماذا النجاح والفشل في الجدول نفسه ───
 *
 * «كم نداءً نجح» بلا «كم فشل» نصف حقيقة: نسبة الفشل هي المؤشّر الذي يقول إنّ
 * النموذج تدهور أو أنّ الموجّه كُسر. وفصلُهما جدولين يجعل حساب النسبة وصلةً
 * لا لزوم لها.
 *
 * ─── ولماذا لا يُسقط الكتابةُ النداء ───
 *
 * فشلُ كتابة أثرٍ لا يجوز أن يُفشل استدلالًا نجح. يُسجَّل في السجلّ ويُمضى:
 * فقدُ سطرٍ من المحاسبة أرخص من فقد جواب.
 */
final class AiUsageTracker
{
    public function succeeded(AiRequest $request, AiResponse $response): void
    {
        $this->write($request, $response->promptVersion, $response->model, 'SUCCEEDED', [
            'latency_ms' => $response->latencyMs,
            'input_units' => $response->inputTokens,
            'output_units' => $response->outputTokens,
            'cached' => $response->cached,
        ]);

        /*
         * سطرُ سجلٍّ باسمٍ منقّط — هذا هو سطح المقاييس في هذا المشروع، كما
         * في `outbox.event_dead` و`search.provider_fallback`.
         */
        Log::info('ai.request.completed', [
            'task' => $request->task->value,
            'promptId' => $request->promptId,
            'latencyMs' => $response->latencyMs,
            'cached' => $response->cached,
        ]);
    }

    public function failed(AiRequest $request, int $promptVersion, string $model, string $code, string $message, int $latencyMs): void
    {
        $this->write($request, $promptVersion, $model, $code === 'AI_OUTPUT_INVALID' ? 'REJECTED' : 'FAILED', [
            'latency_ms' => $latencyMs,
            'error_code' => $code,
            // النصّ مقصوصٌ: رسالة خطأٍ طويلة قد تحمل جزءًا من المدخل.
            'error_message' => mb_substr($message, 0, 500),
        ]);

        Log::warning('ai.request.failed', [
            'task' => $request->task->value,
            'promptId' => $request->promptId,
            'code' => $code,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function write(AiRequest $request, int $promptVersion, string $model, string $status, array $extra): void
    {
        try {
            /*
             * صفٌّ محجوز من الدفتر يُغلَق ولا يُضاعف.
             *
             * وإلّا ظهر النداء المُجدوَل مرّتين: مرّةً «في الطابور» تبقى إلى
             * الأبد، ومرّةً بنتيجته. فيقرأ المشغّل ضعف ما جرى، ويبقى صفٌّ
             * معلّقٌ لا يُغلقه شيء.
             */
            $queued = AiRun::query()
                ->where('entity_type', $request->entityType)
                ->where('entity_id', $request->entityId)
                ->whereIn('status', ['QUEUED', 'RUNNING'])
                ->orderByDesc('id')
                ->first();

            if ($queued !== null) {
                $queued->forceFill(array_merge([
                    'task' => $request->task->value,
                    'prompt_id' => $request->promptId,
                    'prompt_version' => $promptVersion,
                    'model' => $model,
                    'input_hash' => $request->inputHash(),
                    'status' => $status,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ], $extra))->save();

                return;
            }

            /*
             * `array_merge` لا اتّحادُ مصفوفات: الاتّحاد يُبقي اليسار عند
             * تكرار المفتاح، فحقلٌ يُمرَّر في `$extra` باسمٍ موجودٍ في الأساس
             * كان يُهمَل بصمت.
             */
            AiRun::query()->create(array_merge([
                'task' => $request->task->value,
                'prompt_id' => $request->promptId,
                'prompt_version' => $promptVersion,
                'model' => $model,
                'entity_type' => $request->entityType,
                'entity_id' => $request->entityId,
                'actor_admin_user_id' => $request->actorId,
                // بصمةٌ لا نصّ: السجلّ يقرؤه كلّ من يملك `audit.read`.
                'input_hash' => $request->inputHash(),
                'status' => $status,
            ], $extra));
        } catch (Throwable $e) {
            Log::error('ai.usage.write_failed', ['error' => $e->getMessage()]);
        }
    }
}
