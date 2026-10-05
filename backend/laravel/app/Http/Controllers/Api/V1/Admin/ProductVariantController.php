<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductCapabilities;
use App\Domain\Catalog\VariantAuthoring;
use App\Domain\Pricing\PricingService;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * صيغ المنتج — Word وPowerPoint وPDF للملفّ نفسه، ولكلٍّ سعرها وملفّها.
 *
 * صفحة المنتج تعرض المُبدِّل منذ ٢٠٢٦-٠٩-١٧، والسلّة والدفع والتسليم تعرف
 * الصيغة كلّها. ولم يكن في اللوحة ما يؤلّف صيغةً ثانية — فالمُبدِّل قدرةٌ
 * لا تُرى، لأنّ كلّ منتجٍ في الكتالوج صيغةٌ واحدة مردومة.
 *
 * المتحكّم رقيقٌ عمدًا: الثوابت في `VariantAuthoring` — أهمّها إعادةُ ربط
 * الملفّ العامّ بصيغته الأصليّة قبل ظهور الثانية — كي لا ينسخها مستهلكٌ
 * ثانٍ ولا ينساها.
 */
final class ProductVariantController
{
    public function __construct(
        private readonly VariantAuthoring $authoring,
        private readonly AdminAuditLogger $audit,
    ) {}

    public function index(int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);

        return response()->json(['data' => $this->rows($product)]);
    }

    public function store(Request $request, int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);

        /*
         * القواعد مكتوبةٌ حرفيًّا لا مبنيّةً بـ`implode`: مولّد العقد يقرأ
         * مصفوفة `validate` **نصًّا**، وقاعدةٌ مركَّبة تُعلَّم
         * `x-request-body-incomplete` فيخرج العقد ناقصًا بصمت.
         */
        $validated = $request->validate([
            'sku' => 'required|string|max:120|unique:product_variants,sku',
            'slug' => 'required|string|max:160|unique:product_variants,slug',
            'title' => 'sometimes|nullable|string|max:160',
            'format' => 'sometimes|nullable|string|max:16',
            'status' => 'required|in:draft,published,retired',
            'position' => 'sometimes|integer|min:0|max:999',
            'sellingModel' => 'required|in:ONE_TIME,SUBSCRIPTION,RECURRING,PREORDER,BACKORDER,RENTAL,RESERVATION,BOOKING,QUOTE_REQUIRED,PAY_WHAT_YOU_WANT,FREE,LICENSE_PURCHASE,MEMBERSHIP',
            'offerStatus' => 'required|in:draft,active,paused,retired',
            'regularMinor' => 'required|integer|min:0',
            'saleMinor' => 'sometimes|nullable|integer|min:0|lt:regularMinor',
            /*
             * §٢٧ §٤٠ — الأبعاد الماديّة والمعرّفات التجاريّة.
             *
             * الأعمدة قائمةٌ منذ ترحيل النموذج الكونيّ، و`ShipmentExecutor`
             * يقرأ `weight_grams` ويُبقي الوحدة معلّقةً حين يغيب — أي أنّ
             * منتجًا ماديًّا بلا وزن يُباع ولا يُشحن. ولم يكن للمشغّل مدخلٌ
             * واحد يكتبه: كان يُطالَب بقيمةٍ لا سبيل له إلى إدخالها.
             *
             * وبالغرام والمليمتر صراحةً: الوحدة عمودٌ لا لاحقةٌ في نصّ،
             * فلا يُكتب «٢ كغ» في حقلٍ يُقرأ غرامات.
             */
            'weightGrams' => 'sometimes|nullable|integer|min:0|max:100000000',
            'lengthMm' => 'sometimes|nullable|integer|min:0|max:1000000',
            'widthMm' => 'sometimes|nullable|integer|min:0|max:1000000',
            'heightMm' => 'sometimes|nullable|integer|min:0|max:1000000',
            'gtin' => 'sometimes|nullable|string|max:14',
            'mpn' => 'sometimes|nullable|string|max:255',
            'isbn' => 'sometimes|nullable|string|max:17',
            'barcode' => 'sometimes|nullable|string|max:255',
            /*
             * §٢٨ — استراتيجيّة المخزون. كانت `UNLIMITED` مكتوبةً في الخدمة
             * لكلّ صيغة، فنسخةٌ ماديّة واحدة كانت تُباع بلا حدّ.
             */
            'inventoryStrategy' => 'sometimes|in:UNLIMITED,TRACKED,LICENSE_POOL,SEAT_CAPACITY,TIME_CAPACITY,MADE_TO_ORDER,PREORDER,BACKORDER,EXTERNAL',
            /*
             * §٢٣ — دورة الفوترة. مرآةُ القيد
             * `product_offers_recurring_requires_interval` (القاعدة ٣٨):
             * كان الاشتراك يُقبل بلا دورة ثمّ يُرفض رفضًا صلبًا في الخدمة.
             */
            'billingIntervalCount' => 'sometimes|nullable|integer|min:1|max:365',
            'billingIntervalUnit' => 'sometimes|nullable|in:DAY,WEEK,MONTH,YEAR',
            'trialDays' => 'sometimes|nullable|integer|min:0|max:365',
            /*
             * §٨ — استراتيجيّات التسليم لهذه الصيغة. كانت `DIGITAL_DOWNLOAD`
             * مكتوبةً في الخدمة، فكلّ صيغةٍ مؤلَّفة تُسلَّم تنزيلًا رقميًّا
             * مهما كانت طبيعة منتجها.
             */
            'fulfillmentStrategies' => 'sometimes|array|max:19',
            /*
             * الطريق النجميّ (`.*`) لا يقرؤه مولّد العقد أصلًا — يُسقطه
             * `ValidationRuleReader` قبل قراءة قواعده. فتسعَ عشرةَ قيمةً
             * مكتوبةً هنا نسخةٌ بلا مستهلك: لا تُنتج عقدًا ولا تفعل شيئًا
             * سوى أن تنحرف. والقاعدة تُبنى من سجلّ القدرات.
             */
            'fulfillmentStrategies.*' => ['string', Rule::in(ProductCapabilities::FULFILLMENT_STRATEGIES)],
        ], [
            'sku.required' => 'أدخل رمز الصيغة (SKU).',
            'sku.unique' => 'رمز الصيغة مستعمل في صيغةٍ أخرى — الرمز فريدٌ في المتجر كلّه.',
            'slug.required' => 'أدخل المُعرِّف اللفظيّ للصيغة.',
            'slug.unique' => 'المُعرِّف اللفظيّ مستعمل في صيغةٍ أخرى.',
            'status.required' => 'اختر حالة الصيغة.',
            'sellingModel.required' => 'اختر نموذج البيع.',
            'offerStatus.required' => 'اختر حالة العرض.',
            'regularMinor.required' => 'أدخل السعر الأصليّ بالهللات.',
            'saleMinor.lt' => 'سعر التخفيض يجب أن يقلّ عن السعر الأصليّ.',
        ]);

        $this->assertPriceModel($validated);

        $variant = $this->authoring->create($product, [
            'sku' => $validated['sku'],
            'slug' => $validated['slug'],
            'title' => $validated['title'] ?? null,
            'format' => $validated['format'] ?? null,
            'status' => $validated['status'],
            'position' => $validated['position'] ?? null,
            'sellingModel' => $validated['sellingModel'],
            'offerStatus' => $validated['offerStatus'],
            'regularMinor' => (int) $validated['regularMinor'],
            'saleMinor' => $validated['saleMinor'] ?? null,
            /*
             * وما استجدّ يُمرَّر كما وصل: الأبعادُ الماديّة والمعرّفاتُ
             * ودورةُ الفوترة واستراتيجيّاتُ التسليم. الخدمة تقرأ ما يعنيها
             * بـ`array_key_exists`، فالمرور هنا لا يكتب عمودًا لم يُرسَل.
             */
        ] + $validated);

        $this->audit->log(
            $this->actor(), 'product.variant.created', 'product', $product->id,
            null,
            ['variantId' => $variant->id, 'sku' => $variant->sku, 'format' => $variant->format],
            null, $request,
        );

        return response()->json(['data' => $this->rows($product->refresh())], 201);
    }

    public function update(Request $request, int $productId, int $variantId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $variant = $this->ownedVariant($product, $variantId);

        /*
         * القواعد حرفيّة بلا دمج — حتّى في التعديل.
         *
         * `unique:...,{id}` يحتاج معرّف الصفّ، فيُبنى بالدمج، فيقرأ مولّد
         * العقد المصفوفة نصًّا ويعجز عنها: يُسقط `sku` و`slug` من جسم الطلب
         * ويسم العمليّة `x-request-body-incomplete`. أي أنّ حقلين يقبلهما
         * الخادم لا يعرفهما العقد ولا الحزمة المولَّدة.
         *
         * فالتفرّد يُفحص أدناه باستعلامٍ صريح يستثني الصفّ نفسه، والقاعدة
         * هنا تبقى ثابتةً يقرؤها المولّد.
         */
        $validated = $request->validate([
            'sku' => 'sometimes|string|max:120',
            'slug' => 'sometimes|string|max:160',
            'title' => 'sometimes|nullable|string|max:160',
            'format' => 'sometimes|nullable|string|max:16',
            'status' => 'sometimes|in:draft,published,retired',
            'position' => 'sometimes|integer|min:0|max:999',
            'sellingModel' => 'sometimes|in:ONE_TIME,SUBSCRIPTION,RECURRING,PREORDER,BACKORDER,RENTAL,RESERVATION,BOOKING,QUOTE_REQUIRED,PAY_WHAT_YOU_WANT,FREE,LICENSE_PURCHASE,MEMBERSHIP',
            'offerStatus' => 'sometimes|in:draft,active,paused,retired',
            'regularMinor' => 'sometimes|integer|min:0',
            'saleMinor' => 'sometimes|nullable|integer|min:0',
            /*
             * §٢٧ §٤٠ — الأبعاد الماديّة والمعرّفات التجاريّة.
             *
             * الأعمدة قائمةٌ منذ ترحيل النموذج الكونيّ، و`ShipmentExecutor`
             * يقرأ `weight_grams` ويُبقي الوحدة معلّقةً حين يغيب — أي أنّ
             * منتجًا ماديًّا بلا وزن يُباع ولا يُشحن. ولم يكن للمشغّل مدخلٌ
             * واحد يكتبه: كان يُطالَب بقيمةٍ لا سبيل له إلى إدخالها.
             *
             * وبالغرام والمليمتر صراحةً: الوحدة عمودٌ لا لاحقةٌ في نصّ،
             * فلا يُكتب «٢ كغ» في حقلٍ يُقرأ غرامات.
             */
            'weightGrams' => 'sometimes|nullable|integer|min:0|max:100000000',
            'lengthMm' => 'sometimes|nullable|integer|min:0|max:1000000',
            'widthMm' => 'sometimes|nullable|integer|min:0|max:1000000',
            'heightMm' => 'sometimes|nullable|integer|min:0|max:1000000',
            'gtin' => 'sometimes|nullable|string|max:14',
            'mpn' => 'sometimes|nullable|string|max:255',
            'isbn' => 'sometimes|nullable|string|max:17',
            'barcode' => 'sometimes|nullable|string|max:255',
            /*
             * §٢٨ — استراتيجيّة المخزون. كانت `UNLIMITED` مكتوبةً في الخدمة
             * لكلّ صيغة، فنسخةٌ ماديّة واحدة كانت تُباع بلا حدّ.
             */
            'inventoryStrategy' => 'sometimes|in:UNLIMITED,TRACKED,LICENSE_POOL,SEAT_CAPACITY,TIME_CAPACITY,MADE_TO_ORDER,PREORDER,BACKORDER,EXTERNAL',
            /*
             * §٢٣ — دورة الفوترة. مرآةُ القيد
             * `product_offers_recurring_requires_interval` (القاعدة ٣٨):
             * كان الاشتراك يُقبل بلا دورة ثمّ يُرفض رفضًا صلبًا في الخدمة.
             */
            'billingIntervalCount' => 'sometimes|nullable|integer|min:1|max:365',
            'billingIntervalUnit' => 'sometimes|nullable|in:DAY,WEEK,MONTH,YEAR',
            'trialDays' => 'sometimes|nullable|integer|min:0|max:365',
            /*
             * §٨ — استراتيجيّات التسليم لهذه الصيغة. كانت `DIGITAL_DOWNLOAD`
             * مكتوبةً في الخدمة، فكلّ صيغةٍ مؤلَّفة تُسلَّم تنزيلًا رقميًّا
             * مهما كانت طبيعة منتجها.
             */
            'fulfillmentStrategies' => 'sometimes|array|max:19',
            /*
             * الطريق النجميّ (`.*`) لا يقرؤه مولّد العقد أصلًا — يُسقطه
             * `ValidationRuleReader` قبل قراءة قواعده. فتسعَ عشرةَ قيمةً
             * مكتوبةً هنا نسخةٌ بلا مستهلك: لا تُنتج عقدًا ولا تفعل شيئًا
             * سوى أن تنحرف. والقاعدة تُبنى من سجلّ القدرات.
             */
            'fulfillmentStrategies.*' => ['string', Rule::in(ProductCapabilities::FULFILLMENT_STRATEGIES)],
        ], [
            'sku.unique' => 'رمز الصيغة مستعمل في صيغةٍ أخرى — الرمز فريدٌ في المتجر كلّه.',
            'slug.unique' => 'المُعرِّف اللفظيّ مستعمل في صيغةٍ أخرى.',
        ]);

        $this->assertUniqueIdentifiers($validated, $variant->id);

        $offer = $variant->offer ?? $variant->offers()->orderBy('id')->first();

        /*
         * السعران يُفحصان معًا بقيمتهما **بعد** التعديل لا بما وصل وحده:
         * تعديل الأصليّ دون التخفيض قد يقلبه فوقه، والقيد في قاعدة البيانات
         * يردّه عندئذٍ خطأَ خادمٍ لا رسالةَ حقل.
         */
        $this->assertPriceModel([
            'sellingModel' => $validated['sellingModel'] ?? $offer?->selling_model ?? 'ONE_TIME',
            'regularMinor' => (int) ($validated['regularMinor'] ?? $offer?->regular_minor ?? 0),
            'saleMinor' => array_key_exists('saleMinor', $validated)
                ? $validated['saleMinor']
                : $offer?->sale_minor,
            /*
             * والدورة تُقرأ من العرض حين لا تُرسَل: تبديلُ نموذج البيع وحده
             * إلى اشتراكٍ على عرضٍ له دورةٌ محفوظة يجب أن يمرّ، وطلبُ إعادة
             * إرسال قيمةٍ لم تتغيّر يُفشل التعديل بلا سبب.
             */
            'billingIntervalUnit' => array_key_exists('billingIntervalUnit', $validated)
                ? $validated['billingIntervalUnit']
                : $offer?->billing_interval_unit,
            'billingIntervalCount' => array_key_exists('billingIntervalCount', $validated)
                ? $validated['billingIntervalCount']
                : $offer?->billing_interval_count,
        ]);

        $before = [
            'sku' => $variant->sku,
            'format' => $variant->format,
            'status' => $variant->status,
            'regularMinor' => $offer?->regular_minor,
            'saleMinor' => $offer?->sale_minor,
        ];

        $variant = $this->authoring->update($variant, $validated);
        $fresh = $variant->offer ?? $variant->offers()->orderBy('id')->first();

        $this->audit->log(
            $this->actor(), 'product.variant.updated', 'product', $product->id,
            $before,
            [
                'sku' => $variant->sku,
                'format' => $variant->format,
                'status' => $variant->status,
                'regularMinor' => $fresh?->regular_minor,
                'saleMinor' => $fresh?->sale_minor,
            ],
            null, $request,
        );

        return response()->json(['data' => $this->rows($product->refresh())]);
    }

    public function destroy(Request $request, int $productId, int $variantId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $variant = $this->ownedVariant($product, $variantId);

        $before = ['variantId' => $variant->id, 'sku' => $variant->sku, 'format' => $variant->format];

        $this->authoring->delete($variant);

        $this->audit->log(
            $this->actor(), 'product.variant.deleted', 'product', $product->id,
            $before, null, null, $request,
        );

        return response()->json(['data' => $this->rows($product->refresh())]);
    }

    /**
     * تفرّد الرمز والمُعرِّف، مستثنيًا الصيغة نفسها.
     *
     * يُفحص هنا لا في `validate` كي تبقى قواعد `validate` حرفيّةً يقرؤها
     * مولّد العقد (انظر التعليق في `update`). والرسالة واحدة في الموضعين.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertUniqueIdentifiers(array $input, int $exceptId): void
    {
        foreach (['sku' => 'رمز الصيغة مستعمل في صيغةٍ أخرى — الرمز فريدٌ في المتجر كلّه.',
            'slug' => 'المُعرِّف اللفظيّ مستعمل في صيغةٍ أخرى.'] as $column => $message) {
            if (! array_key_exists($column, $input)) {
                continue;
            }

            $taken = ProductVariant::query()
                ->where($column, $input[$column])
                ->whereKeyNot($exceptId)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([$column => [$message]]);
            }
        }
    }

    /**
     * قيودُ التسعير التي تفرضها قاعدة البيانات، مُقدَّمةً رسالةَ حقل.
     *
     * القيود موجودة على الجدول (`sale_minor < regular_minor`، و«المجّانيّ
     * ثمنه صفر»)، لكنّ خرقها يصل العميل خطأَ خادمٍ غامضًا — فيُفحص هنا
     * ليصل رسالةً على حقلها.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertPriceModel(array $input): void
    {
        $regular = (int) ($input['regularMinor'] ?? 0);
        $sale = $input['saleMinor'] ?? null;
        $model = (string) ($input['sellingModel'] ?? 'ONE_TIME');

        if ($sale !== null && (int) $sale >= $regular) {
            throw ValidationException::withMessages([
                'saleMinor' => ['سعر التخفيض يجب أن يقلّ عن السعر الأصليّ.'],
            ]);
        }

        if ($model === 'FREE' && $regular !== 0) {
            throw ValidationException::withMessages([
                'regularMinor' => ['المجّانيّ ثمنه صفر — غيّر نموذج البيع أو صفّر السعر.'],
            ]);
        }

        /*
         * §٢٣ — الاشتراك يوجب دورةً كاملة.
         *
         * كان الرفض هنا صلبًا: «لا تُهيَّأ من هذه الشاشة بعد» — وهو صادقٌ
         * حين لا حقلَ للدورة، وكاذبٌ بعد أن صار لها حقل. فصار الفحص مرآةَ
         * القيد `product_offers_recurring_requires_interval` نفسه (القاعدة ٣٨):
         * يُرفض الناقص باسم حقله، ويُقبل التامّ.
         */
        if (ProductCapabilities::requiresBillingInterval($model)) {
            $unit = $input['billingIntervalUnit'] ?? null;
            $count = $input['billingIntervalCount'] ?? null;

            if ($unit === null) {
                throw ValidationException::withMessages([
                    'billingIntervalUnit' => ['نموذج الاشتراك يوجب وحدة دورةٍ — يوم أو أسبوع أو شهر أو سنة.'],
                ]);
            }

            /* والعمود والوحدة معًا أو لا شيء — مرآةُ `billing_interval_complete`. */
            if ($count === null) {
                throw ValidationException::withMessages([
                    'billingIntervalCount' => ['أدخل عدد وحدات الدورة — «كلّ كم وحدة يُجدَّد».'],
                ]);
            }
        }
    }

    /**
     * صفوف الصيغ كما تعرضها اللوحة.
     *
     * `deliverableFiles` تُصدَّر لأنّها أساس الصدق: صيغةٌ منشورةٌ بلا ملفٍّ
     * مربوطٍ بها تُباع ويُسلَّم لها ملفُّ غيرها. اللوحة تُظهر ذلك تحذيرًا،
     * والخادم يعدّه ولا يخفيه.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Product $product): array
    {
        $generic = ProductFile::query()
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->whereIn('role', ProductFile::DELIVERABLE_ROLES)
            ->count();

        return $this->authoring->variantsFor($product)->map(function (ProductVariant $v) use ($product, $generic): array {
            $offer = $v->offer ?? $v->offers->sortBy('id')->first();
            $sale = $offer === null ? null : PricingService::activeSaleWithin(
                $offer->sale_minor, $offer->sale_from, $offer->sale_to,
            );

            return [
                'id' => $v->id,
                'sku' => $v->sku,
                'slug' => $v->slug,
                'title' => $v->title,
                'format' => $v->format,
                'status' => $v->status,
                'position' => (int) $v->position,
                'isBridge' => $v->legacy_product_id !== null,
                /*
                 * §٢٧ — الوزن يُعاد لأنّ بوّابة النشر تطلبه من الماديّ،
                 * ولوحةُ التأليف لم يكن لها مدخلٌ واحد يكتبه: يُطالَب المشغّل
                 * بقيمةٍ لا سبيل له إلى رؤيتها ولا إدخالها. و`null` تعني
                 * «غير مقيس» لا «صفر» (§١٥٨).
                 */
                'weightGrams' => $v->weight_grams === null ? null : (int) $v->weight_grams,
                'offer' => $offer === null ? null : [
                    'id' => $offer->id,
                    'sellingModel' => $offer->selling_model,
                    'status' => $offer->status,
                    'currency' => $offer->currency,
                    'regularMinor' => (int) $offer->regular_minor,
                    'saleMinor' => $offer->sale_minor === null ? null : (int) $offer->sale_minor,
                    'effectiveMinor' => $sale ?? (int) $offer->regular_minor,
                ],
                'deliverableFiles' => ProductFile::query()
                    ->where('product_id', $product->id)
                    ->where('product_variant_id', $v->id)
                    ->whereIn('role', ProductFile::DELIVERABLE_ROLES)
                    ->count(),
                /* ملفّاتٌ عامّة تُسلَّم لكلّ صيغة — صحيحةٌ بصيغةٍ واحدة، كاذبةٌ بأكثر. */
                'genericDeliverableFiles' => $generic,
            ];
        })->values()->all();
    }

    /** صيغةٌ تخصّ هذا المنتج، وإلّا 404 — لا تسريب وجود عبر رمز الحالة. */
    private function ownedVariant(Product $product, int $variantId): ProductVariant
    {
        $group = $this->authoring->groupFor($product);

        return ProductVariant::query()
            ->where('product_group_id', $group?->id ?? 0)
            ->whereKey($variantId)
            ->firstOrFail();
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
