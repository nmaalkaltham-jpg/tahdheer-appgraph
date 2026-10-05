<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Search\Explain\RelevanceExplainer;
use App\Domain\Search\QueryPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مصحّح الصلة (§٣٧ و§١٥٠) — «لماذا ظهر هذا المنتج؟ ولماذا هو فوق ذاك؟».
 *
 * السؤال كان بلا جواب: الترتيب يخرج من دالّةٍ خاصّةٍ واحدة، ولا شاشة ولا
 * نقطةَ نهايةٍ تُظهر ماذا فعلت. فكان التشخيص تخمينًا يُجرَّب بتعديل العناوين.
 *
 * قراءةٌ محضة: لا كتابة، ولا حتّى صفّ `search_queries` الذي يكتبه خطّ
 * الأنابيب عادةً — يُلغى في المُفسِّر حتّى لا يصنع الفحصُ طلبًا لم يطلبه زائر.
 */
final class SearchExplainController
{
    public function __construct(private readonly RelevanceExplainer $explainer) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:'.QueryPipeline::MAX_CHARS],
            'sort' => ['nullable', 'string', 'in:'.implode(',', QueryPipeline::SORTS)],
            /*
             * السقف صفحةُ المزوِّد نفسها (٢٤). طلبُ أكثر يُوهم بنتائج خارج
             * الصفحة الأولى بينما المفسِّر لا يرى إلّا ما ردّه الطلب الحقيقيّ.
             */
            'limit' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        return response()->json(['data' => $this->explainer->explain(
            (string) $validated['q'],
            (string) ($validated['sort'] ?? 'relevance'),
            (int) ($validated['limit'] ?? 10),
        )]);
    }
}
