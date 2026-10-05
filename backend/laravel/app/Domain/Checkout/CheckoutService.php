<?php

namespace App\Domain\Checkout;

use App\Domain\Cart\CartService;
use App\Domain\Cart\CartTotals;
use App\Domain\Cart\CouponRules;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\Coupon;
use App\Domain\Catalog\BundleComposition;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfillment\FulfillmentStrategyResolver;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Orders\OrderToken;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Creates AWAITING_PAYMENT orders with an immutable totals snapshot.
 * Prices and coupon are RE-computed from the database at creation —
 * «لا سعر يُقبل بلا إعادة حساب». Cart is cleared only after the order exists.
 */
class CheckoutService
{
    public function __construct(
        private readonly FulfillmentStrategyResolver $strategies,
        private readonly InventoryService $inventory,
        private readonly CheckoutRequirementsResolver $requirements,
        private readonly BundleComposition $bundles,
    ) {}

    /**
     * @param  array{name: string, email: string, phone: string, taxProfile?: array{orgName: string, vatNumber: string}|null, marketingOptIn?: bool, shippingAddress?: array<string, mixed>}  $data
     * @return array{order: Order, token: string}
     */
    public function createOrder(Cart $cart, array $data): array
    {
        $items = $cart->items()
            ->with(['product' => fn ($q) => $q->published()->with('price')])
            ->get()
            ->filter(fn ($item) => $item->product?->price !== null)
            ->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['السلة فارغة — لا يمكن إنشاء طلب بدون منتجات']]);
        }

        /*
         * §١٠٠ §٣٢ — ما تطلبه هذه السلّة، من المحدِّد نفسه الذي ينفّذ بعد السداد.
         *
         * ─── لماذا هنا ───
         *
         * بوّابة السلّة كانت ترفض كلّ ما ليس رقميًّا، فلم يكن لسؤال «هل يلزم
         * عنوان؟» معنى: لا طلبَ ماديّ يوجد. وبعد رفع البوّابة صار الطلب
         * الماديّ ممكنًا **بلا عنوان** — فيُقبَل ويُدفَع ثمنه ثمّ تقف وحدةُ
         * الشحن عند «لا عنوان شحنٍ على الطلب» إلى الأبد. وذاك أسوأ من رفضٍ
         * عند الدفع لأنّه يقع بعد أخذ المال.
         *
         * ─── ولماذا المحدِّد نفسه ───
         *
         * لو قرّرت هذه الخدمة بنفسها «الماديّ يوجب عنوانًا» لَافترق ما يَعِد
         * به الدفعُ عمّا ينفّذه المنسّق عند أوّل اختلاف. والمصدر واحد.
         */
        $requirements = $this->requirements->for($cart);

        if (! $requirements->canProceed()) {
            throw ValidationException::withMessages([
                'cart' => $requirements->unresolvable,
            ]);
        }

        if ($requirements->requiresShippingAddress && ! isset($data['shippingAddress'])) {
            throw ValidationException::withMessages([
                'shippingAddress' => ['هذه السلّة تحتوي ما يُشحن — أدخل عنوان الشحن.'],
            ]);
        }

        $email = mb_strtolower(trim($data['email']));

        $coupon = $cart->coupon;
        if ($coupon !== null && ! CouponRules::usableFor($coupon, $email)) {
            throw ValidationException::withMessages(['coupon' => [CouponRules::INVALID_MESSAGE]]);
        }

        [$order, $rawToken] = DB::transaction(function () use ($cart, $data, $email, $coupon) {
            /*
             * الزبون يُنشأ **داخل** المعاملة: كان يُلتزم قبلها فيبقى صفًّا يتيمًا
             * بموافقةٍ تسويقيّة عن دفعٍ لم يقع. وصفُّه يُقفل هنا فيتسلسل عليه
             * طلبان متزامنان بالبريد نفسه — وقاعدةُ «أوّل طلبٍ للحساب» تُعاد
             * تحت القفل لا قبله.
             */
            $customer = $this->resolveCustomer($email, $data);

            if ($coupon !== null && ! CouponRules::usableFor($coupon, $email)) {
                throw ValidationException::withMessages(['coupon' => [CouponRules::INVALID_MESSAGE]]);
            }

            /*
             * القفل على صفّ السلّة هو ما يجعل الطلب واحدًا.
             *
             * كانت البنود تُقرأ **خارج** المعاملة وتُفرَّغ السلّة **بعدها**،
             * فنداءان متزامنان يقرآن السلّة نفسها مملوءةً ويُنشئان طلبين
             * مدفوعين من سلّةٍ واحدة — يدفع الزبون مرّتين. والقفل هنا
             * يجعل الثاني ينتظر، فيجد السلّة فارغةً ويُرفض برسالةٍ صحيحة.
             */
            $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            $items = $locked->items()
                ->with(['product' => fn ($q) => $q->published()->with('price'), 'variant', 'offer'])
                ->get()
                ->filter(fn ($item) => $item->product?->price !== null || $item->offer !== null)
                ->values();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['السلة فارغة — لا يمكن إنشاء طلب بدون منتجات'],
                ]);
            }

            /*
             * عرضٌ لم يعد فعّالًا لا يُحصَّل منه.
             *
             * `CartItem::offer()` لا تصفّي الحالة — وهو صحيح للعرض: السطر
             * يجب أن يُظهر ما اختاره الزبون. لكنّ التحصيل شيءٌ آخر: عرضٌ
             * أوقفه المحرّر بعد الإضافة يعني صيغةً لم تعد تُباع، وتسعيرُها
             * منه يبيع ما سُحب. والسقوط الصامت إلى سعر المنتج أسوأ: يُحصَّل
             * مبلغٌ غير الذي رآه الزبون.
             */
            $withdrawn = $items->first(
                fn ($item) => $item->offer !== null && $item->offer->status !== 'active',
            );

            if ($withdrawn !== null) {
                throw ValidationException::withMessages([
                    'cart' => ['أحد المنتجات في سلّتك لم يعد متاحًا بالصيغة المختارة — أزِله أو اختر صيغةً أخرى.'],
                ]);
            }

            $coupon = $this->consumeCoupon($coupon);

            /*
             * Recompute every price from the DB rows NOW — وبالحاسب نفسه الذي
             * يعرض به السلّة (`CartService::lineTotals`). كان هنا حسابٌ ثانٍ
             * يقرأ `product_prices` وحدها، فيدفع مشتري PowerPoint ثمن Word.
             */
            /*
             * ─── قيمتان لكلّ سطر، ولا تُخلطان ───
             *
             * `$lines` قيمُ السطر كاملةً (وحدة × كميّة) — منها يُحسب الإجمالي.
             * و`$units` سعرُ الوحدة — وهو ما يُلقَّط في `price_snapshot_minor`.
             *
             * الخلطُ بينهما عيبٌ ماليّ صامت: العمود يُقرأ في لوحة المشغّل
             * بـ`sum(price_snapshot_minor * quantity)`، فلو حمل قيمة السطر
             * لَصار الإيراد مضروبًا في الكميّة مرّتين — ثلاث نسخٍ بتسعين تُعلَن
             * ثمانمئةً وعشرًا وقد حُصّل مئتان وسبعون. والعمود كان سعرَ وحدةٍ
             * منذ أوّل ترحيل، فتبديل معناه كان سيكسر كلّ قارئٍ له بصمت.
             */
            $lines = [];
            $units = [];
            foreach ($items as $item) {
                $minors = CartService::lineTotals($item);
                $unit = CartService::lineMinors($item);
                if ($minors === null || $unit === null) {
                    throw new RuntimeException('Cart line '.$item->id.' has no price to charge.');
                }
                $lines[] = $minors;
                $units[] = $unit;
            }
            $totals = CartTotals::compute($lines, $coupon);

            $order = Order::query()->create([
                'number' => 'TD-PENDING-'.uniqid(),
                'customer_id' => $customer->id,
                'email' => $email,
                'phone' => $data['phone'],
                'status' => 'AWAITING_PAYMENT',
                'totals_gross_minor' => $totals->grossMinor,
                'discount_minor' => $totals->productDiscountMinor,
                'coupon_minor' => $totals->couponDiscountMinor,
                'total_minor' => $totals->totalMinor,
                'vat_minor' => $totals->vatMinor,
                'tax_org_name' => $data['taxProfile']['orgName'] ?? null,
                'tax_vat_number' => $data['taxProfile']['vatNumber'] ?? null,
                'marketing_opt_in' => (bool) ($data['marketingOptIn'] ?? false),
                'policy_consent_at' => now(),
            ]);

            $number = 'TD-'.(1000 + $order->id);
            $rawToken = OrderToken::make($number, $email);
            $order->update(['number' => $number, 'order_key_hash' => OrderToken::hash($rawToken)]);

            foreach ($items as $index => $item) {
                $line = $lines[$index];
                $unit = $units[$index];

                /*
                 * §٤٣ — الحزمة تُوسَّع إلى بنودها هنا، وهنا وحدها.
                 *
                 * ─── لماذا التوسيع عند الدفع لا عند التسليم ───
                 *
                 * كلُّ ما بعد بند الطلب يعمل **لكلّ بندٍ** أصلًا: الاستحقاق
                 * فريدٌ ببند، والرخصة الممنوحة فريدةٌ ببند، ووحدة التنفيذ
                 * فريدةٌ بـ(بند، استراتيجيّة). فحزمةٌ من ثلاثة ملفّاتٍ لو
                 * بقيت بندًا واحدًا لَانهارت إلى استحقاقٍ واحد ووحدةٍ واحدة
                 * وملفٍّ واحد — يدفع المشتري ثمن ثلاثةٍ ويستلم واحدًا.
                 *
                 * والتوسيع هنا يجعل كلّ مكوّنٍ بندًا كامل الحقوق، فيسلك مسار
                 * نوعه بلا حرفٍ جديد في المنسّق: الرقميّ يُنزَّل والماديّ
                 * يُشحن، من الحزمة نفسها.
                 *
                 * والثمن ثمنُ الحزمة موزَّعًا، لا مجموعَ مكوّناتها: الحزمة
                 * تُباع بخصم، وجمعُ الأسعار كان يُحصّل أكثر ممّا عُرض.
                 */
                $expansion = $this->bundleLines($item, $line, $unit);

                if ($expansion !== null) {
                    foreach ($expansion as $componentLine) {
                        $this->writeOrderItem($order, $componentLine);
                    }

                    continue;
                }

                /*
                 * §١٠١ — لقطة البند تُكتب **الآن** لا تُشتقّ لاحقًا. الصيغة
                 * والعرض كانا يضيعان بين السلّة والطلب، فيعود المنسّق إلى
                 * جسر المنتج القديم ولا يجد وزنًا ولا شروط رخصة.
                 */
                $offer = $item->product_offer_id === null
                    ? null
                    : ProductOffer::query()->find($item->product_offer_id);

                $plan = $this->strategies->resolve(
                    $item->product_offer_id === null ? null : (int) $item->product_offer_id,
                    (int) $item->product_id,
                );

                $orderItem = OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_offer_id' => $item->product_offer_id,
                    'title_snapshot' => $item->product->title,
                    'sku_snapshot' => $item->variant?->sku ?? $item->product->sku,
                    'selling_model_snapshot' => $offer?->selling_model,
                    'fulfillment_strategies_snapshot' => $plan->strategies,
                    'selected_options_snapshot' => $item->selected_options,
                    /* سعر الوحدة — و`quantity` بجانبه يُكمل الصورة. */
                    'price_snapshot_minor' => $unit['effective'],
                    'quantity' => (int) ($item->qty ?? 1),
                ]);

                $this->holdStock($orderItem, $item, $offer);
            }

            /*
             * §٢٦ — عنوان الشحن يُكتب في معاملة الطلب.
             *
             * `order_addresses` كان يقرؤه `ShipmentExecutor` ولا يكتبه أحد في
             * التطبيق كلّه: فكلّ طلبٍ ماديّ — لو أمكن إنشاؤه — كانت وحدةُ
             * شحنه تقف عند «لا عنوان شحنٍ على الطلب». والكتابة هنا لا بعد
             * المعاملة: بعدها تبقى نافذةٌ يُثبَّت فيها الدفع على طلبٍ بلا
             * عنوان، فتُنشأ وحدةٌ معلّقة بلا سببٍ يملكه الزبون.
             */
            if (isset($data['shippingAddress'])) {
                $this->writeShippingAddress($order, $data);
            }

            /*
             * التفريغ داخل المعاملة لا بعدها: بعدها تبقى نافذةٌ يقرأ فيها
             * نداءٌ ثانٍ السلّةَ مملوءةً وقد صار لها طلب.
             */
            $locked->items()->delete();
            $locked->update(['coupon_id' => null]);

            return [$order, $rawToken];
        });

        return ['order' => $order->fresh(), 'token' => $rawToken];
    }

    /**
     * يحجز المخزون للبنود المتعقَّبة وحدها (§٩ «reserve required inventory»).
     *
     * الحجز يقع **قبل** الدفع لا بعده: بعده يكون المتجر قد باع ما لا يملك،
     * ولا يبقى إلّا الاعتذار وردّ المال. وفشلُ الحجز يُسقط المعاملة كلّها،
     * فلا يُنشأ طلبٌ لا يمكن تنفيذه.
     *
     * والاستراتيجيّات غير المتعقَّبة تُتخطّى صراحةً: المنتج الرقميّ لا ينفد،
     * وحجزُه يُنشئ صفوفًا لا معنى لها ويُبطئ كلّ عمليّة شراء.
     */
    /**
     * بنودُ الحزمة، أو `null` حين ليس البند حزمةً.
     *
     * @param  array{regular: int, effective: int}  $line  قيمة السطر كاملةً
     * @param  array{regular: int, effective: int}  $unit  سعر الوحدة
     * @return list<array<string, mixed>>|null
     */
    private function bundleLines($item, array $line, array $unit): ?array
    {
        $group = ProductGroup::query()
            ->where('legacy_product_id', $item->product_id)
            ->first();

        if ($group === null || $group->product_nature !== 'COMPOSITE') {
            return null;
        }

        $components = $this->bundles->expand($group);

        if ($components === []) {
            /*
             * حزمةٌ فارغة تُرفض عند الدفع لا تُقبل فارغة: بندٌ بلا تسليم
             * يُقفل الطلب على لا شيء، والمشتري دفع.
             */
            throw ValidationException::withMessages([
                'cart' => ["الحزمة «{$item->product->title}» بلا مكوّنات — لا شيء يُسلَّم."],
            ]);
        }

        $qty = max(1, (int) ($item->qty ?? 1));
        $shares = $this->allocate($unit['effective'], $components);

        $out = [];
        foreach ($components as $index => $component) {
            /** @var ProductVariant $variant */
            $variant = $component['variant'];
            $offer = ProductOffer::query()
                ->where('product_variant_id', $variant->id)
                ->orderByRaw("case when status = 'active' then 0 else 1 end")
                ->orderBy('id')
                ->first();

            $legacyProductId = $variant->group?->legacy_product_id ?? $item->product_id;

            $out[] = [
                'productId' => (int) $legacyProductId,
                'variantId' => (int) $variant->id,
                'offerId' => $offer?->id === null ? null : (int) $offer->id,
                'offer' => $offer,
                'title' => trim(($item->product->title ?? '').' — '.($variant->title ?? $variant->sku)),
                'sku' => $variant->sku,
                'selectedOptions' => $item->selected_options,
                /*
                 * حصّةُ هذا المكوّن من ثمن الحزمة، لسعر الوحدة. والكميّة
                 * حاصلُ ضرب كميّة المكوّن في كميّة الحزمة المشتراة.
                 */
                'unitMinor' => $shares[$index],
                'quantity' => $qty * $component['quantity'],
                'cartItem' => $item,
            ];
        }

        return $out;
    }

    /**
     * يوزّع ثمن الحزمة على مكوّناتها بحيث يُساوي المجموعُ الثمنَ بالضبط.
     *
     * ─── لماذا «أكبر باقٍ» لا قسمةٌ ساذجة ───
     *
     * ثمنٌ ١٠٠ على ثلاثة مكوّنات يعطي ٣٣.٣٣ لكلٍّ، ومجموعُها ٩٩.٩٩ — هللةٌ
     * تضيع في كلّ طلب. وتقريبُ كلٍّ لأعلى يعطي ١٠٢، فيُحصَّل أكثر ممّا عُرض.
     * وطريقة «أكبر باقٍ» تُعطي الكسور الضائعة للمكوّنات ذات أكبر باقٍ، فيَخرج
     * المجموع مساويًا للأصل حرفًا — وهو شرطٌ ماليّ لا تجميل.
     *
     * والتوزيع بالتساوي لا بأسعار المكوّنات: ثمنُ الحزمة قرارٌ تحريريّ
     * مستقلّ، واشتقاقُ الحصص من أسعارٍ قد تتغيّر بعد البيع يجعل لقطة البند
     * تتحرّك.
     *
     * @param  list<array{variant: ProductVariant, quantity: int}>  $components
     * @return list<int>
     */
    private function allocate(int $totalMinor, array $components): array
    {
        $count = count($components);
        $base = intdiv($totalMinor, $count);
        $remainder = $totalMinor - ($base * $count);

        $shares = array_fill(0, $count, $base);

        for ($i = 0; $i < $remainder; $i++) {
            $shares[$i]++;
        }

        return $shares;
    }

    /**
     * يكتب بند طلبٍ واحدًا من وصفٍ محسوب — للحزمة ولغيرها.
     *
     * @param  array<string, mixed>  $line
     */
    private function writeOrderItem(Order $order, array $line): void
    {
        $plan = $this->strategies->resolve($line['offerId'], $line['productId']);

        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $line['productId'],
            'product_variant_id' => $line['variantId'],
            'product_offer_id' => $line['offerId'],
            'title_snapshot' => $line['title'],
            'sku_snapshot' => $line['sku'],
            'selling_model_snapshot' => $line['offer']?->selling_model,
            'fulfillment_strategies_snapshot' => $plan->strategies,
            'selected_options_snapshot' => $line['selectedOptions'],
            'price_snapshot_minor' => $line['unitMinor'],
            'quantity' => $line['quantity'],
        ]);

        $this->holdStock($orderItem, $line['cartItem'], $line['offer']);
    }

    /**
     * يكتب عنوان الشحن كما أدخله الزبون — بلا اختراع ولا تطبيع صامت.
     *
     * الحقول الاختياريّة تبقى فارغةً حين تُترك: حيٌّ لم يُكتب يبقى `null`
     * ولا يُملأ بالمدينة، ومنطقةٌ لم تُختر لا تُستنتج. عنوانٌ مُلفَّق يصل
     * الناقلَ فيرتدّ الطرد، والارتداد أغلى من حقلٍ فارغ (§١٥٨).
     *
     * @param  array<string, mixed>  $data
     */
    private function writeShippingAddress(Order $order, array $data): void
    {
        $address = $data['shippingAddress'];

        DB::table('order_addresses')->insert([
            'order_id' => $order->id,
            'kind' => 'SHIPPING',
            /* المستلِم قد يكون غير المشتري — هديّةٌ مثلًا. والافتراض اسمُ المشتري. */
            'recipient_name' => $address['recipientName'] ?? $data['name'],
            'phone' => $address['phone'] ?? $data['phone'],
            'country_code' => $address['countryCode'] ?? 'SA',
            'region' => $address['region'] ?? null,
            'city' => $address['city'],
            'district' => $address['district'] ?? null,
            'street' => $address['street'] ?? null,
            'building_number' => $address['buildingNumber'] ?? null,
            'secondary_number' => $address['secondaryNumber'] ?? null,
            'postal_code' => $address['postalCode'] ?? null,
            'notes' => $address['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCustomer(string $email, array $data): Customer
    {
        $existing = Customer::query()->where('email', $email)->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        $attributes = [
            'email' => $email,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'marketing_consent' => (bool) ($data['marketingOptIn'] ?? false),
        ];

        try {
            // نقطة حفظ: خرقُ القيد الفريد يُجهض المعاملة كلّها في PostgreSQL بدونها.
            return DB::transaction(fn () => Customer::query()->create($attributes));
        } catch (QueryException $e) {
            $winner = Customer::query()->where('email', $email)->lockForUpdate()->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    private function holdStock(OrderItem $orderItem, $cartItem, ?ProductOffer $offer): void
    {
        if ($offer === null || $orderItem->product_variant_id === null) {
            return; // بندٌ قديم بلا صيغة — لا مخزون يُحجز
        }

        if ($offer->inventory_strategy !== 'TRACKED') {
            return;
        }

        $location = InventoryLocation::query()->sellable()->first();
        if ($location === null) {
            throw ValidationException::withMessages([
                'inventory' => ['لا موقع مخزونٍ معروضٌ للبيع — عرّف موقعًا قبل بيع منتجٍ متعقَّب (§٣٠).'],
            ]);
        }

        try {
            $this->inventory->reserve(
                variantId: (int) $orderItem->product_variant_id,
                locationId: (int) $location->id,
                quantity: (int) $orderItem->quantity,
                holderType: 'order',
                holderId: (int) $orderItem->order_id,
                lineId: (int) $orderItem->id,
            );
        } catch (RuntimeException $e) {
            // رسالة المخزون تُعرض كما هي: «المتاح ٢ والمطلوب ٥» أوضح من «تعذّر».
            throw ValidationException::withMessages(['inventory' => [$e->getMessage()]]);
        }
    }

    /**
     * Atomically consume one coupon use; loses the usage-limit race honestly.
     */
    private function consumeCoupon(?Coupon $coupon): ?Coupon
    {
        if ($coupon === null) {
            return null;
        }

        $affected = Coupon::query()
            ->whereKey($coupon->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when(
                $coupon->usage_limit !== null,
                fn ($q) => $q->whereColumn('usage_count', '<', 'usage_limit'),
            )
            ->increment('usage_count');

        if ($affected === 0) {
            throw ValidationException::withMessages(['coupon' => [CouponRules::INVALID_MESSAGE]]);
        }

        return $coupon;
    }
}
