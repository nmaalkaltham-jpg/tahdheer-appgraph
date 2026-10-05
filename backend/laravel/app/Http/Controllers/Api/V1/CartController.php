<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cart\CartService;
use App\Domain\Cart\CartTotals;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Search\Analytics\SearchAttribution;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        /* إسنادُ الإضافة والإزالة إلى البحث (بموافقة القياس وحدها؛ لا يُسقط السلّة أبدًا). */
        private readonly SearchAttribution $searchAttribution,
    ) {}

    /** No cookie → explicit empty state; no cart row is created on read. */
    public function show(Request $request): JsonResponse
    {
        $cart = $this->cart->resolve($request);

        if ($cart === null) {
            return response()->json([
                'items' => [],
                'totals' => CartTotals::compute([])->toArray(),
                'coupon' => null,
                'count' => 0,
            ]);
        }

        return response()->json($this->cart->payload($cart))
            ->withCookie($this->cart->cookieFor($cart));
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate(
            [
                'productId' => 'required|integer',
                /*
                 * صيغة الملفّ المختارة. اختياريّة: منتجٌ بصيغةٍ واحدة يُضاف
                 * كما كان، ولا يُجبَر عميلٌ قديم على إرسال ما لا يعرفه.
                 * وانتماء الصيغة إلى المنتج يُفحص في الخدمة لا هنا — فحصُ
                 * شكلٍ لا يمنع تسعير منتجٍ بصيغة منتجٍ آخر.
                 */
                'variantId' => 'sometimes|nullable|integer',
                /*
                 * الكميّة. اختياريّة فيبقى العميل القديم يعمل كما كان، ويُحدّ
                 * إلى ١ في الخدمة للطبائع التي لا تحتمل أكثر — القرار قدرةٌ
                 * تُسأل لا شرطٌ يُكتب هنا.
                 */
                'qty' => 'sometimes|integer|min:1|max:99',
            ],
            ['productId.required' => 'حدد المنتج المطلوب إضافته', 'productId.integer' => 'معرّف المنتج غير صالح'],
        );

        $cart = $this->cart->resolveOrCreate($request);
        $this->cart->addItem(
            $cart,
            (int) $data['productId'],
            isset($data['variantId']) ? (int) $data['variantId'] : null,
            isset($data['qty']) ? (int) $data['qty'] : 1,
        );
        $this->searchAttribution->onCartAdd($request, (int) $data['productId']);

        return response()->json($this->cart->payload($cart))
            ->withCookie($this->cart->cookieFor($cart));
    }

    public function removeItem(Request $request, int $itemId): JsonResponse
    {
        $cart = $this->cart->resolve($request);
        /* المنتجُ يُقرأ قبل الحذف: بعده لا يبقى ما يُسند. */
        $productId = $cart === null ? null : CartItem::query()->where('cart_id', $cart->id)->whereKey($itemId)->value('product_id');

        if ($cart === null || ! $this->cart->removeItem($cart, $itemId)) {
            return response()->json(['message' => 'العنصر غير موجود في السلة'], 404);
        }
        if ($productId !== null) {
            $this->searchAttribution->onCartRemove($request, (int) $productId);
        }

        return response()->json($this->cart->payload($cart))
            ->withCookie($this->cart->cookieFor($cart));
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['code' => 'required|string|max:64'],
            ['code.required' => 'أدخل رمز الخصم'],
        );

        $cart = $this->cart->resolveOrCreate($request);
        $this->cart->applyCoupon($cart, $data['code']);

        return response()->json($this->cart->payload($cart))
            ->withCookie($this->cart->cookieFor($cart));
    }

    public function removeCoupon(Request $request): JsonResponse
    {
        $cart = $this->cart->resolveOrCreate($request);
        $this->cart->removeCoupon($cart);

        return response()->json($this->cart->payload($cart))
            ->withCookie($this->cart->cookieFor($cart));
    }
}
