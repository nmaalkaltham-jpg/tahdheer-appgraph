<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\Assistant\StoreAssistant;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * مساعد المتجر — نقطةٌ عامّة، وهي الوحيدة التي تصل النموذج من خارج اللوحة.
 *
 * ─── لماذا `throttle:expensive` ───
 *
 * عشرون طلبًا في الدقيقة، وهو أشدّ مُحدِّدٍ في المشروع. كلّ طلبٍ هنا ثانيتان
 * من معالجٍ على خادمٍ بخمسة أنوية، فالإغراق يُسقط المتجر لا المساعد. والبوّابة
 * تحدّ ثانيةً بحصّةٍ مشتركة لكلّ الزوّار — طبقتان تحرسان بعضهما.
 *
 * ─── ولماذا ٥٠٣ لا ٥٠٠ ───
 *
 * الذكاء غير المتاح ليس عطلًا في المتجر: الرمز يقول «حاول لاحقًا» والواجهة
 * تعرض البحث المعتاد. ولا رميةَ تصعد إلى الزائر.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly StoreAssistant $assistant) {}

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            // الحدّ قبل النموذج: مدخلٌ طويل يلتهم النافذة ويُرَدّ بخطأ بعد أن كلّف.
            'question' => 'required|string|min:2|max:500',
        ]);

        try {
            return response()->json($this->assistant->ask($data['question']));
        } catch (AiUnavailable $e) {
            return response()->json([
                'answer' => 'المساعد غير متاح الآن. يمكنك البحث في المتجر أو التواصل مع الدعم.',
                'products' => [],
                'grounded' => false,
                'refusal' => 'AI_UNAVAILABLE',
                'code' => $e->reason,
            ], 503);
        } catch (Throwable) {
            /*
             * أيّ عطلٍ آخر يُبتلع أيضًا: صفحةٌ عامّة لا يجوز أن تُظهر أثر
             * استثناء، ولا أن تنكسر لأجل ميزةٍ اختياريّة.
             */
            return response()->json([
                'answer' => 'تعذّر الجواب الآن. جرّب البحث في المتجر.',
                'products' => [],
                'grounded' => false,
                'refusal' => 'ASSISTANT_FAILED',
            ], 503);
        }
    }
}
