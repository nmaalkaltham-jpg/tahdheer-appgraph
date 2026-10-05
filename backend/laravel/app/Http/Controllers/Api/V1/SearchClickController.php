<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Search\Analytics\SearchTracking;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SearchQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/v1/search/click — يسجّل أوّل نقرة على نتيجة بحث.
 *
 * عمودا `clicked_product_id` و`position` وُجدا مع الجدول ولم يكتبهما شيء قطّ،
 * فكان CTR وموضع النقرة غير قابلَين للحساب أصلًا. هذا هو الكاتب.
 *
 * قيود متعمّدة:
 *  · **أوّل نقرة تفوز.** الصفّ يُحدَّث مرّة واحدة؛ التحديث المتكرّر يجعل
 *    آخر تنقيب في الصفحة يمحو إشارة الانخراط الأولى، وإعادة تصيير React
 *    تتحوّل نقرات مكرّرة.
 *  · **نافذة ساعة.** نقرة تصل بعد ساعات ليست سلوك بحث؛ هي إعادة فتح تبويب
 *    أو إعادة إرسال، وقبولها يلوّث متوسّط الموضع.
 *  · **204 دائمًا عند القبول الشكلي.** هذه قياسات لا معاملات: الواجهة تُرسل
 *    وتنسى، ولا تُغيّر شيئًا يراه الزائر بناءً على الردّ.
 */
class SearchClickController extends Controller
{
    public function __invoke(Request $request, SearchTracking $tracking): Response
    {
        $validated = $request->validate([
            'queryId' => 'required|integer|min:1',
            'productId' => 'required|integer|min:1',
            // صفحات عميقة تتجاوز 200: صفحة 9 × 24 لكل صفحة تبدأ من 193،
            // وكانت نقراتها تُسقَط صامتةً فيميل متوسّط الموضع نحو الأعلى زورًا.
            'position' => 'required|integer|min:1|max:24000',
            'token' => 'required|string|size:64',
            /* إلى أين قادت النقرة: صفحة المنتج، أو زرّ السلّة على البطاقة نفسها. */
            'destination' => 'sometimes|string|in:pdp,add_to_cart',
        ]);

        /*
         * الرمز يربط النقرة بحاملٍ تلقّى استجابة البحث فعلًا. بدونه المعرّف
         * التسلسلي يُعدّ تعدادًا، وقاعدة «أوّل نقرة تفوز» تجعل الحقن الأسبق
         * يمحو نقرة الزائر الحقيقية إلى الأبد.
         */
        $expected = hash_hmac('sha256', 'search-click:'.$validated['queryId'], (string) config('app.key'));
        if (! hash_equals($expected, $validated['token'])) {
            return response()->noContent();
        }

        // منتج غير موجود ⇒ حمولة مُلفَّقة؛ تُهمَل بصمت ولا تُسجَّل حقيقةً.
        if (! Product::query()->whereKey($validated['productId'])->exists()) {
            return response()->noContent();
        }

        /*
         * صفُّ النقرة (مرّةً لكلّ بحث ومنتج) — منه CTR بالرتبة والإسناد. والعمودان
         * القديمان (أوّل نقرة) يبقيان كما كانا: تقرؤهما مقاييس اللوحة القائمة.
         */
        if (($query = $tracking->verified((int) $validated['queryId'], $validated['token'])) !== null) {
            $tracking->click($request, $query, (int) $validated['productId'], (int) $validated['position'], $validated['destination'] ?? 'pdp');
        }

        SearchQuery::query()
            ->whereKey($validated['queryId'])
            ->whereNull('clicked_product_id')
            ->where('created_at', '>=', now()->subHour())
            ->update([
                'clicked_product_id' => $validated['productId'],
                'position' => $validated['position'],
            ]);

        return response()->noContent();
    }
}
