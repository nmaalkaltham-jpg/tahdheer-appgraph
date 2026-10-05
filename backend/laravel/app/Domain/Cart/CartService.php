<?php

namespace App\Domain\Cart;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\Models\Coupon;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductCapabilities;
use App\Domain\Checkout\CheckoutRequirementsResolver;
use App\Domain\Pricing\PricingService;
use App\Models\Product;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Stateless-cookie cart: the HttpOnly encrypted cookie carries only the
 * cart uuid; all state lives in carts/cart_items. Digital lines are
 * qty-locked to 1 (duplicate add ⇒ no-op).
 */
class CartService
{
    /**
     * سقفُ الكميّة للسطر الواحد.
     *
     * سقفٌ لا لأنّ العدد الأكبر خطأ، بل لأنّ حقلًا مفتوحًا في السلّة يُمرَّر
     * منه عددٌ ضخم فيفيض حسابُ الإجمالي أو يُنشئ طلبًا لا يُنفَّذ. والمخزون
     * يُفحص في الدفع لا هنا — هذا حدُّ صحّةِ مدخلٍ لا حدُّ توفّر.
     */
    private const MAX_QTY = 99;

    public const COOKIE_NAME = 'tahdheer_cart';

    public const COOKIE_MINUTES = 60 * 24 * 30;

    public function resolve(Request $request): ?Cart
    {
        $raw = $request->cookie(self::COOKIE_NAME);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($raw);
        } catch (DecryptException) {
            return null;
        }

        return Cart::query()->where('token', $token)->first();
    }

    public function resolveOrCreate(Request $request): Cart
    {
        return $this->resolve($request)
            ?? Cart::query()->create(['token' => (string) Str::uuid()]);
    }

    /** HttpOnly + SameSite=Lax + encrypted value, per contract. */
    public function cookieFor(Cart $cart): Cookie
    {
        return cookie(
            name: self::COOKIE_NAME,
            value: Crypt::encryptString($cart->token),
            minutes: self::COOKIE_MINUTES,
            path: '/',
            domain: null,
            secure: app()->environment('production'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    /**
     * Duplicate digital line ⇒ unchanged (qty locked to 1).
     *
     * `$variantId` هو صيغة الملفّ المختارة (Word · PowerPoint · PDF). وحين
     * تُمرَّر، يصير **العرض** مصدر السعر لا `product_prices`: الصيغة التي
     * عُرض سعرها في الصفحة هي التي تُسعَّر في السلّة، وإلّا اختار المشتري
     * PowerPoint ودفع ثمن Word.
     *
     * والصيغتان سطران مستقلّان في السلّة لا سطرٌ يُستبدل: من أراد Word
     * وPowerPoint معًا يشتريهما معًا، وهو ما ترسمه صفحة المنتج حين تعرض
     * «في السلة ✓» لصيغةٍ دون أختها.
     */
    public function addItem(Cart $cart, int $productId, ?int $variantId = null, int $qty = 1): void
    {
        /*
         * ─── البوّابة التي زالت ───
         *
         * كان هنا `->where('fulfillment_type', 'digital')`: شرطٌ واحد يجعل
         * **كلّ** منتجٍ غير رقميّ مستحيل الإضافة إلى السلّة، فلا يبلغ الدفع
         * ولا الطلب ولا التسليم. وكان في النظام منفّذُ شحنٍ كامل، وآلةُ
         * حالاتٍ للشحنات، وستّ طبائع في المخطّط — كلّها غير قابلةٍ للبلوغ
         * خلف سطرٍ واحد. وحذفُه وحده لا يكفي: المنشور وحده يُباع، وذاك ما
         * يبقى مفروضًا.
         */
        $product = Product::query()->published()
            ->where('id', $productId)
            ->with('price')
            ->first();

        if ($product === null) {
            throw ValidationException::withMessages(['productId' => ['المنتج غير متاح']]);
        }

        $nature = $this->natureOf($product);

        $variant = $variantId === null ? null : $this->resolveVariant($product, $variantId);
        $offer = $variant?->offer;

        $effective = $offer !== null
            ? PricingService::activeSaleWithin($offer->sale_minor, $offer->sale_from, $offer->sale_to)
                ?? (int) $offer->regular_minor
            : $this->effectiveMinor($product);

        /*
         * §الكميّة — قدرةٌ لا ثابت.
         *
         * `1` كانت مكتوبةً هنا لكلّ منتج، وهي صحيحةٌ للرقميّ (نسختان من ملفٍّ
         * واحد لا معنى لهما) وخاطئةٌ للماديّ: من أراد ثلاث نسخٍ مطبوعة لم
         * يكن له سبيل. فصار الحدّ يُسأل من سجلّ القدرات بدل أن يُفترض.
         */
        $limited = $nature !== null && ProductCapabilities::limitsQuantityToOne($nature);
        $qty = $limited ? 1 : max(1, min($qty, self::MAX_QTY));

        CartItem::query()->firstOrCreate(
            [
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
            ],
            [
                'qty' => $qty,
                'unit_price_snapshot_minor' => $effective,
                'product_offer_id' => $offer?->id,
            ],
        );
    }

    /**
     * طبيعة المنتج — من المجموعة أوّلًا، ثمّ من العمود القديم.
     *
     * والترجمة تمرّ بـ`ProductCapabilities` لا بـ`match` مكتوبٍ هنا: كانت
     * هذه الترجمة مكتوبةً في أربعة مواضع، كلٌّ منها يفترض «رقميّ» عند
     * الغياب. و`null` هنا تعني «غير معروفة» فعلًا، ويقرّر النداء ما يفعل
     * بجهله بدل أن يخترع له جوابًا.
     */
    private function natureOf(Product $product): ?string
    {
        $nature = ProductGroup::query()
            ->where('legacy_product_id', $product->id)
            ->value('product_nature');

        return $nature ?? ProductCapabilities::natureOfLegacy($product->fulfillment_type);
    }

    /**
     * الصيغة المطلوبة، بشرط أن تكون **لهذا المنتج** وأن تكون قابلة للشراء.
     *
     * فحص الانتماء ليس تزيّدًا: بلا فحصٍ يمرّر المشتري معرّف صيغةِ منتجٍ
     * رخيص فيُسعَّر بها منتجٌ غالٍ — ثغرة تسعيرٍ من وسيطٍ واحد. وقد بُني
     * الجسر على `legacy_product_id` الفريد، فالانتماء مقطوع به لا مُرجَّح.
     */
    private function resolveVariant(Product $product, int $variantId): ProductVariant
    {
        $group = ProductGroup::query()
            ->where('legacy_product_id', $product->id)
            ->where('status', 'published')
            ->first();

        $variant = $group === null ? null : ProductVariant::query()
            ->where('product_group_id', $group->id)
            ->where('status', 'published')
            ->whereKey($variantId)
            ->with('offer')
            ->first();

        if ($variant === null || $variant->offer === null) {
            throw ValidationException::withMessages(['variantId' => ['الصيغة المطلوبة غير متاحة']]);
        }

        return $variant;
    }

    public function removeItem(Cart $cart, int $itemId): bool
    {
        return CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('id', $itemId)
            ->delete() > 0;
    }

    public function applyCoupon(Cart $cart, string $code): void
    {
        $coupon = Coupon::query()->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])->first();

        if ($coupon === null || ! CouponRules::usable($coupon)) {
            throw ValidationException::withMessages(['code' => [CouponRules::INVALID_MESSAGE]]);
        }

        $cart->update(['coupon_id' => $coupon->id]);
    }

    public function removeCoupon(Cart $cart): void
    {
        $cart->update(['coupon_id' => null]);
    }

    /**
     * The full API cart payload — items and totals derive from the same
     * price rows so the summary can never contradict the lines.
     *
     * @return array<string, mixed>
     */
    public function payload(Cart $cart): array
    {
        $cart->refresh();
        $items = [];
        $lines = [];

        $rows = $cart->items()
            ->with(['product.price', 'product.stage', 'product.grade', 'product.subject', 'variant', 'offer'])
            ->get();

        foreach ($rows as $item) {
            $product = $item->product;
            $minors = $product === null ? null : self::lineMinors($item);
            if ($product === null || $minors === null) {
                continue;
            }

            $regular = $minors['regular'];
            $effective = $minors['effective'];
            /*
             * الصفّ يعرض سعر الوحدة، والإجمالي يجمع قيمة السطر كاملةً.
             * الخلطُ بينهما يُري المشتري سعرًا ويُحصّل غيره.
             */
            $lines[] = self::lineTotals($item) ?? ['regular' => $regular, 'effective' => $effective];

            $row = [
                'id' => $item->id,
                'productId' => $product->id,
                'title' => $product->title,
                'slug' => $product->slug,
                'priceMinor' => $effective,
                'qty' => max(1, (int) ($item->qty ?? 1)),
                'categoryPath' => self::categoryPath($product),
                /*
                 * صيغة السطر لا صيغة المنتج: سلّةٌ فيها Word وPowerPoint من
                 * المنتج نفسه يجب أن تميّز سطريهما، وإلّا ظهر السطران
                 * متطابقين تمامًا ولا سبيل إلى معرفة أيّهما يُحذف.
                 *
                 * ولا سقوطَ إلى عمود المنتج حين يحمل السطر صيغةً بلا امتداد
                 * مسجَّل: ذلك يُلصق «DOCX» بسطرٍ عنوانه «PowerPoint»، فيسمّي
                 * السطرُ صيغةً ويصفها بأخرى — وهو آخر ما يراه المشتري قبل
                 * الدفع. المجهول يُترك فارغًا، والواجهة لا تعرض شريحة.
                 */
                'format' => $item->variant !== null ? $item->variant->format : $product->format,
            ];
            if ($item->variant !== null) {
                $row['variantId'] = (int) $item->variant->id;
                if (filled($item->variant->title)) {
                    $row['variantTitle'] = $item->variant->title;
                }
            }
            if ($effective < $regular) {
                $row['regularMinor'] = $regular;
                $row['discountPct'] = PricingService::discountPct($regular, $effective);
            }
            $items[] = $row;
        }

        $coupon = $cart->coupon !== null && CouponRules::usable($cart->coupon) ? $cart->coupon : null;
        $totals = CartTotals::compute($lines, $coupon);

        /*
         * ما تطلبه هذه السلّة بعينها — من الخادم لا من علمٍ في الواجهة.
         *
         * `CheckoutRequirementsResolver` كان مكتوبًا ومُختبَرًا ولا يستدعيه
         * شيءٌ في الإنتاج: صفرُ مستدعين خارج اختباره. ومعناه أنّ الواجهة كانت
         * تقرّر بنفسها ما تطلبه من المشتري، وهو القرار الذي يجب أن يطابق ما
         * يُنفَّذ بعد السداد حرفًا بحرف. وهو يستعمل محدِّد الاستراتيجيّات
         * نفسه الذي يستعمله المنسّق، فما يَعِد به الدفع هو ما يقع.
         */
        $requirements = app(CheckoutRequirementsResolver::class)->for($cart);

        return [
            'items' => $items,
            'totals' => $totals->toArray(),
            'coupon' => $coupon !== null ? ['code' => $coupon->code, 'label' => $coupon->label()] : null,
            'count' => count($items),
            'requirements' => [
                'strategies' => $requirements->strategies,
                'requiresShippingAddress' => $requirements->requiresShippingAddress,
                'requiresBookingDetails' => $requirements->requiresBookingDetails,
                'isMixed' => $requirements->isMixed,
                'canProceed' => $requirements->canProceed(),
                /* الأسباب تُعرض كما كتبها النطاق — لا تُطوى في «تعذّر». */
                'blockedReasons' => $requirements->unresolvable,
            ],
        ];
    }

    /**
     * أسماء المنهج الحقيقية فقط؛ مصفوفة فارغة إن لم يكن للمنتج مسار.
     *
     * مصفوفة لا نصًّا مدموجًا: كانت هذه الدالّة تُعيد `implode(' / ', …)`
     * بينما ProductCardResource يُعيد مصفوفة والواجهة تكتب
     * `categoryPath.join(' › ')` في كل موضع. النتيجة انهيار عرض السلة فور
     * إضافة أوّل منتج — `categoryPath.join is not a function` — بعد استجابة
     * 200 سليمة تمامًا. عقد واحد للحقل نفسه، والفاصل قرار عرض يخصّ الواجهة
     * لا الخادم.
     *
     * @return list<string>
     */
    public static function categoryPath(Product $product): array
    {
        return array_values(array_filter([
            $product->stage?->name_ar,
            $product->grade?->name_ar,
            $product->subject?->name_ar,
        ]));
    }

    /**
     * ثمنا سطر السلّة — الأصليّ والنافذ — من مصدره الصحيح.
     *
     * سطرٌ يحمل عرضًا يُسعَّر **بعرضه**: هو الذي عُرض سعره في الصفحة، وهو
     * الذي يحمل صيغة الملفّ. وسطرٌ بلا عرض (كلّ ما سبق النموذج الكونيّ)
     * يُسعَّر بسعر المنتج كما كان.
     *
     * ودالّةٌ واحدة لأنّ عرض السلّة والدفع كانا يحسبان كلٌّ على حدة — وكلاهما
     * من `product_prices` وحدها. فمن اختار PowerPoint كان يرى ثمن Word في
     * السلّة ويدفعه في الفاتورة، والصيغة وحدها تنتقل إلى الطلب. حسابان
     * متوازيان لثمنٍ واحد يفترقان دائمًا؛ هذا موضع الالتقاء.
     *
     * @return array{regular: int, effective: int}|null فارغٌ حين لا ثمن أصلًا
     */
    public static function lineMinors(CartItem $item): ?array
    {
        $offer = $item->offer;

        if ($offer !== null) {
            $regular = (int) $offer->regular_minor;

            return ['regular' => $regular, 'effective' => $offer->effectivePriceMinor()];
        }

        $price = $item->product?->price;

        if ($price === null) {
            return null;
        }

        $regular = (int) $price->regular_minor;

        return [
            'regular' => $regular,
            'effective' => PricingService::activeSaleMinor($price) ?? $regular,
        ];
    }

    /**
     * قيمة السطر كاملةً — سعرُ الوحدة مضروبًا في الكميّة.
     *
     * ─── لماذا تابعٌ مشترك لا ضربٌ في كلّ مستدعٍ ───
     *
     * `lineMinors` تُعيد سعر الوحدة، وكانت الكميّة `1` دائمًا فاستوى
     * الأمران. وبعد أن صارت الكميّة حقيقيّة افترقا: السلّة تجمع أسعار
     * الوحدات، و`CheckoutService` يكتب `quantity` الحقيقيّة على بند الطلب.
     * أي أنّ الطلب كان سيقول «ثلاث نسخ» ويُحصّل ثمن نسخةٍ واحدة — عيبٌ
     * ماليّ صامت لا يشكو منه قيدٌ ولا اختبار.
     *
     * فالضرب يقع هنا وحده، ويقرؤه الحاسبان معًا.
     *
     * @return array{regular: int, effective: int}|null
     */
    public static function lineTotals(CartItem $item): ?array
    {
        $unit = self::lineMinors($item);

        if ($unit === null) {
            return null;
        }

        $qty = max(1, (int) ($item->qty ?? 1));

        return [
            'regular' => $unit['regular'] * $qty,
            'effective' => $unit['effective'] * $qty,
        ];
    }

    private function effectiveMinor(Product $product): ?int
    {
        $price = $product->price;
        if ($price === null) {
            return null;
        }

        return PricingService::activeSaleMinor($price) ?? (int) $price->regular_minor;
    }
}
