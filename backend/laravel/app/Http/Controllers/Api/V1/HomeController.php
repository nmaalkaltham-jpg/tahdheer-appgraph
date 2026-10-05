<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Content\HomeContent;
use App\Domain\Content\HomeReviews;
use App\Domain\Content\PromoResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Homepage section content (MIP §3.3), read by
 * apps/storefront/components/home/sections.tsx.
 *
 * TWO conventions this controller follows deliberately, both dictated by the
 * shipped client:
 *
 * 1. snake_case keys and NO `data` envelope. Every other endpoint here answers
 *    camelCase inside `{"data": …}`; the homepage DTOs were declared
 *    snake_case and unwrapped in the storefront, and the storefront is what
 *    ships. Matching it is the whole point of these routes.
 * 2. Absence is a first-class answer. Nothing below has a default, a fallback
 *    headline or a sample number: a section the store never configured returns
 *    204 (objects) or an empty array (lists), and the storefront renders
 *    nothing at all for it. The only content that can come out of here is
 *    content a human put in — or, for /reviews, arithmetic over real reviews.
 */
class HomeController extends Controller
{
    /** بطل الصفحة الرئيسية من الإعدادات — مصدره الوحيد بعد حذف استوديو التصميم. */
    public function hero(): JsonResponse|Response
    {
        return self::object(HomeContent::hero());
    }

    public function cta(): JsonResponse|Response
    {
        return self::object(HomeContent::cta());
    }

    /** هويّة المتجر للتذييل — ٢٠٠ دائمًا؛ الفراغ صادق لا ٢٠٤. */
    public function identity(): JsonResponse
    {
        return response()->json(HomeContent::identity());
    }

    /** شريط الإعلانات — ٢٠٠ دائمًا: `items: []` يعني «لا شريط» والمتجر يرسم لا شيء. */
    public function announcements(): JsonResponse
    {
        return response()->json(HomeContent::announcements());
    }

    /** Campaign and coupon are independent — either half may be absent. */
    public function promo(): JsonResponse|Response
    {
        $payload = PromoResolver::payload();

        return self::object($payload === [] ? null : $payload);
    }

    public function why(): JsonResponse
    {
        return response()->json(HomeContent::cards(HomeContent::WHY));
    }

    public function benefits(): JsonResponse
    {
        return response()->json(HomeContent::cards(HomeContent::BENEFITS));
    }

    public function faq(): JsonResponse
    {
        return response()->json(HomeContent::faq());
    }

    /** Computed from the reviews domain only — see App\Domain\Content\HomeReviews. */
    public function reviews(Request $request): JsonResponse|Response
    {
        return self::object(HomeReviews::payload($request));
    }

    /**
     * 200 with the configured payload, or 204 when the section has no content.
     * 204 rather than `{}` so «unconfigured» is visible in the access log and
     * to any client that never parses a body.
     *
     * @param  array<string, mixed>|null  $payload
     */
    /**
     * ترتيب الأقسام المُفعَّلة، أو ٢٠٤ حين لم يُضبط بعد.
     *
     * و٢٠٤ ليست «لا أقسام»: المتجر يقع عندها على ترتيبه الافتراضيّ. أمّا
     * قائمةٌ فارغة فقرارُ إخلاءٍ صريح — ولذلك لا يُحفظ الفراغ أصلًا، فلا
     * تصل هذه النقطة مصفوفةً فارغة إلّا إن أُفرغ الترتيب عمدًا.
     */
    public function layout(): JsonResponse|Response
    {
        $layout = HomeContent::layout();

        return $layout === null ? response()->noContent() : response()->json(['sections' => $layout]);
    }

    private static function object(?array $payload): JsonResponse|Response
    {
        return $payload === null ? response()->noContent() : response()->json($payload);
    }
}
