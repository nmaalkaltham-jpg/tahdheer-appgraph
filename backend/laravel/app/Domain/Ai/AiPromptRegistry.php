<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Models\AiPromptVersion;
use RuntimeException;

/**
 * سجلّ الموجّهات — لا موجّه إنتاجيّ خارج هذا الجدول.
 *
 * ─── لماذا في قاعدة البيانات لا في الشيفرة ───
 *
 * موجّهٌ مبعثرٌ في وحدات التحكّم يعني أنّ تحسينه نشرٌ كامل، وأنّ لا أحد يعرف
 * أيّ نصٍّ أنتج أيّ اقتراح. وهنا نصٌّ واحدٌ لكلّ نسخة، والاقتراح يحمل رقم
 * نسخته، فيُنسب التدهور إلى سببه.
 *
 * ─── والتخزين المؤقّت ───
 *
 * الموجّه يُقرأ مع كلّ استدلال. قراءتُه من القاعدة في كلّ مرّة استعلامٌ زائد
 * في مسارٍ حارّ، والنسخة المفعّلة لا تتغيّر إلّا بفعلٍ إداريّ. المفتاح يحمل
 * المعرّف وحده، ويُنسى عند نشر نسخةٍ جديدة.
 */
final class AiPromptRegistry
{
    /** @var array<string, AiPromptVersion> */
    private array $memo = [];

    /**
     * النسخة المفعّلة الأحدث لموجّهٍ.
     *
     * الأحدث لا الأولى: نشرُ نسخةٍ جديدة يعطّل السابقة، لكنّ تعطيلًا منسيًّا
     * كان سيجعل نسختين مفعّلتين. الترتيب يحسم بلا غموض.
     */
    public function active(string $promptId): AiPromptVersion
    {
        if (isset($this->memo[$promptId])) {
            return $this->memo[$promptId];
        }

        $prompt = AiPromptVersion::query()
            ->where('prompt_id', $promptId)
            ->where('enabled', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null) {
            /*
             * الرمي لا السقوط إلى موجّهٍ افتراضيّ: موجّهٌ غير مسجَّل خطأُ نشرٍ،
             * وتغطيتُه بموجّهٍ عامّ تُنتج خرجًا لا يطابق المخطَّط المنتظر فيُرفض
             * لاحقًا بسببٍ مضلّل.
             */
            throw new RuntimeException("لا نسخة مفعّلة للموجّه «{$promptId}».");
        }

        return $this->memo[$promptId] = $prompt;
    }

    public function has(string $promptId): bool
    {
        return AiPromptVersion::query()
            ->where('prompt_id', $promptId)
            ->where('enabled', true)
            ->exists();
    }

    /** @return list<string> */
    public function ids(): array
    {
        return AiPromptVersion::query()
            ->where('enabled', true)
            ->distinct()
            ->orderBy('prompt_id')
            ->pluck('prompt_id')
            ->all();
    }
}
