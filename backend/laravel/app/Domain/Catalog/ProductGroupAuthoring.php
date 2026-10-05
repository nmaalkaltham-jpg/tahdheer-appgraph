<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تأليف مجموعة المنتج — الباب الذي لم يكن للنموذج الكونيّ.
 *
 * ─── العيب الذي يزيله ───
 *
 * النموذج الكونيّ قائمٌ منذ ٢٠٢٦-٠٩-١٥ بجداوله وقيوده وطبائعه الستّ، ويقرؤه
 * البحثُ والسلّةُ والدفعُ والتسليم. ولم يكن في التطبيق كلّه سطرٌ واحد يُنشئ
 * `product_groups`: الصفوف الستّةُ والعشرون جاءت من ترحيل الردم وحده. فكانت
 * `product_nature` **مجمَّدةً على ما كتبه الردم**، وكلّ منتجٍ أُنشئ بعد ذلك
 * التاريخ يولد بلا مجموعةٍ أصلًا — لا طبيعةَ له، ولا عرضَ، ولا استراتيجيّة
 * تسليم. ومعنى ذلك أنّ «اختيار نوع المنتج» كان مستحيلًا لا ناقصًا: لا عمود
 * يُكتب، ولا نقطة نهاية تكتبه.
 *
 * ─── لماذا خدمةُ نطاقٍ لا سطرٌ في المتحكّم ───
 *
 * الكتابة الواحدة تمسّ أربعة جداول (`product_groups` ← `product_variants` ←
 * `product_offers` ← `offer_fulfillments`) وتحمل ثوابت لا تُرى من واجهة
 * HTTP: أنّ للمجموعة صيغةً أولى دائمًا، وأنّ للصيغة عرضًا، وأنّ للعرض
 * استراتيجيّة تسليمٍ واحدة على الأقلّ. مجموعةٌ بلا عرضٍ تُنتج منتجًا
 * يُعرَض ولا يُشترى، وعرضٌ بلا تسليمٍ يُنتج طلبًا يُدفَع ولا يُسلَّم —
 * وكلاهما لا يظهر إلّا بعد أخذ المال.
 *
 * ─── والطبيعة إلزاميّة ولا افتراض لها ───
 *
 * `create()` توجب `nature` صراحةً. الافتراض إلى `DIGITAL` كان سيعيد العيب
 * نفسه بصورةٍ ألطف: منتجٌ ماديّ يُنشأ رقميًّا بالصمت، فيمرّ من بوّابة نشرٍ
 * تطلب ملفًّا، ثمّ يُرفض عند الشحن لأنّه بلا وزن. الصمت هنا أغلى من السؤال.
 */
final class ProductGroupAuthoring
{
    /**
     * المجموعة المرتبطة بمنتجٍ قديم، إن وُجدت.
     *
     * الجسر `legacy_product_id` فريد، فالجواب واحدٌ أو لا شيء.
     */
    public function groupFor(Product $product): ?ProductGroup
    {
        return ProductGroup::query()->where('legacy_product_id', $product->id)->first();
    }

    /**
     * ينشئ المجموعة وصيغتها الأولى وعرضها وتسليمها في معاملةٍ واحدة.
     *
     * تُستدعى داخل معاملة المتحكّم نفسها التي تُنشئ المنتج القديم، فلا تبقى
     * لحظةٌ يوجد فيها منتجٌ بلا مجموعة. والمعاملة المتداخلة في Laravel نقطةُ
     * حفظ، فاستدعاؤها من داخل معاملةٍ قائمة سليم.
     *
     * @param  list<string>  $strategies  استراتيجيّات التسليم. فارغة = خذ اقتراح الطبيعة.
     */
    public function create(Product $product, string $nature, array $strategies = []): ProductGroup
    {
        if (! ProductCapabilities::isNature($nature)) {
            throw ValidationException::withMessages([
                'productNature' => ["طبيعة منتجٍ غير معروفة: {$nature}."],
            ]);
        }

        $strategies = $this->resolveStrategies($nature, $strategies);

        return DB::transaction(function () use ($product, $nature, $strategies): ProductGroup {
            /*
             * الجسر فريدٌ في المخطّط. فحصُه هنا يحوّل خطأ فرادةٍ خامًا (٥٠٠،
             * بلا اسم حقل) إلى رفضٍ مفهوم — وهو ما توجبه القاعدة ٣٨.
             */
            if ($this->groupFor($product) !== null) {
                throw ValidationException::withMessages([
                    'product' => ['لهذا المنتج مجموعةٌ قائمة. النوع يُبدَّل ولا يُنشأ ثانيةً.'],
                ]);
            }

            /*
             * §القاعدة ٣٨ — ما تمنعه قاعدة البيانات يمنعه التحقّق.
             *
             * `products.slug` و`products.sku` يُفحصان في المتحكّم ضدّ جدول
             * `products` وحده. وهذه الكتابة تُدرج القيمتين نفسيهما في
             * `product_groups.slug` و`product_variants.sku`/`slug` — ولكلٍّ
             * منها فهرسٌ فريدٌ خاصّ. فمنتجٌ جديد باسمٍ يصادف مجموعةً قائمة
             * (وهي قد تكون لمنتجٍ حُذف صفُّه القديم، أو من ردمٍ سابق) كان
             * يسقط على ٢٣٥٠٥ فيصير ٥٠٠ بلا اسم حقل — بينما الصواب ٤٢٢ تقول
             * أيّ حقلٍ يُبدَّل.
             */
            $clashes = [];

            if (ProductGroup::query()->where('slug', $product->slug)->exists()) {
                $clashes['slug'][] = 'المُعرِّف اللفظيّ مستعمل في مجموعة منتجٍ أخرى.';
            }

            if (ProductVariant::query()->where('slug', $product->slug)->exists()) {
                $clashes['slug'][] = 'المُعرِّف اللفظيّ مستعمل في صيغةٍ أخرى.';
            }

            if (ProductVariant::query()->where('sku', $product->sku)->exists()) {
                $clashes['sku'][] = 'الرمز مستعمل في صيغةٍ أخرى.';
            }

            if ($clashes !== []) {
                throw ValidationException::withMessages($clashes);
            }

            $group = ProductGroup::create([
                'slug' => $product->slug,
                'title' => $product->title,
                'subtitle' => $product->subtitle,
                'short_desc' => $product->short_desc,
                'description' => $product->description,
                'product_nature' => $nature,
                'status' => $product->status,
                'stage_id' => $product->stage_id,
                'grade_id' => $product->grade_id,
                'subject_id' => $product->subject_id,
                'semester_id' => $product->semester_id,
                'legacy_product_id' => $product->id,
                'published_at' => $product->published_at,
            ]);

            $variant = ProductVariant::create([
                'product_group_id' => $group->id,
                'sku' => $product->sku,
                'slug' => $product->slug,
                'title' => null, // فارغٌ يعني: خذ عنوان المجموعة
                'format' => $product->format,
                'position' => 0,
                'status' => $product->status,
                /* الصيغة الأولى وحدها تحمل الجسر — والثانية لا تنازعها عليه. */
                'legacy_product_id' => $product->id,
            ]);

            $offer = ProductOffer::create([
                'product_variant_id' => $variant->id,
                'selling_model' => 'ONE_TIME',
                'inventory_strategy' => ProductCapabilities::defaultInventoryFor($nature),
                'regular_minor' => 0,
                'currency' => 'SAR',
                'status' => 'draft',
                'tax_class_id' => $this->defaultTaxClassId(),
            ]);

            $this->writeFulfillments($offer, $strategies);

            return $group->refresh();
        });
    }

    /**
     * يُبدّل طبيعة مجموعةٍ قائمة، ويُصحّح ما لم يعد يليق بها.
     *
     * تبديلُ الطبيعة وحدها كان سيترك استراتيجيّاتٍ لا تليق: منتجٌ صار ماديًّا
     * وتسليمه ما يزال تنزيلًا رقميًّا. فإمّا أن تُمرَّر استراتيجيّاتٌ جديدة،
     * وإمّا أن يُرفض التبديل بسببٍ مكتوب — ولا يُقبَل بصمتٍ ويُصحَّح لاحقًا.
     *
     * @param  list<string>  $strategies
     */
    public function changeNature(ProductGroup $group, string $nature, array $strategies = []): ProductGroup
    {
        if (! ProductCapabilities::isNature($nature)) {
            throw ValidationException::withMessages([
                'productNature' => ["طبيعة منتجٍ غير معروفة: {$nature}."],
            ]);
        }

        return DB::transaction(function () use ($group, $nature, $strategies): ProductGroup {
            $group = ProductGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            $offers = ProductOffer::query()
                ->whereIn('product_variant_id', ProductVariant::query()
                    ->where('product_group_id', $group->id)->select('id'))
                ->get();

            $current = OfferFulfillment::query()
                ->whereIn('product_offer_id', $offers->pluck('id'))
                ->pluck('strategy')
                ->unique()
                ->values()
                ->all();

            /*
             * حين لا تُمرَّر استراتيجيّاتٌ نُبقي القائمة إن كانت تليق بالطبيعة
             * الجديدة، وإلّا نطلبها صراحةً. الاشتقاق الصامت هنا كان سيمحو
             * اختيارًا سابقًا للمشغّل بلا أن يعلم.
             */
            $target = $strategies !== []
                ? $this->resolveStrategies($nature, $strategies)
                : ($current !== [] && ProductCapabilities::satisfiesNature($nature, $current)
                    ? $current
                    : $this->resolveStrategies($nature, []));

            /*
             * ─── عرضٌ مباع لا تُعاد كتابة تسليمه ───
             *
             * `FulfillmentStrategyResolver` يشتقّ الاستراتيجيّات **حيًّا** من
             * `offer_fulfillments` وقت التنفيذ. فحذفُها وإعادةُ كتابتها على
             * عرضٍ له بنودُ طلبٍ قائمة يُعيد توجيه طلبٍ مدفوعٍ بأثرٍ رجعيّ:
             * مشترٍ دفع ثمن تنزيلٍ رقميّ يُطلب منه فجأةً عنوانُ شحن، أو تقف
             * وحدتُه عند استراتيجيّةٍ لم يشترِها. وهو أسوأ ما يقع لأنّه يقع
             * **بعد** أخذ المال وبفعل تحريرٍ لا علاقة له بذلك الطلب.
             *
             * فيُرفض التبديل ويُقال السبب. والمخرج للمشغّل واضح: يُتقاعَد
             * العرض ويُؤلَّف غيره، فلا يُمسّ ما بِيع.
             */
            $soldOfferIds = DB::table('order_items')
                ->whereIn('product_offer_id', $offers->pluck('id'))
                ->distinct()
                ->pluck('product_offer_id')
                ->all();

            if ($soldOfferIds !== [] && $current !== $target) {
                throw ValidationException::withMessages([
                    'productNature' => [
                        'لهذا المنتج عروضٌ مباعة ('.count($soldOfferIds).')، وتبديل النوع يُعيد كتابة '
                        .'طريقة تسليمها فيُعاد توجيه طلبٍ مدفوع. تقاعَد العرض وألّف غيره.',
                    ],
                ]);
            }

            $group->product_nature = $nature;
            $group->save();

            foreach ($offers as $offer) {
                $offer->inventory_strategy = ProductCapabilities::defaultInventoryFor($nature);
                $offer->save();

                OfferFulfillment::query()->where('product_offer_id', $offer->id)->delete();
                $this->writeFulfillments($offer, $target);
            }

            return $group->refresh();
        });
    }

    /**
     * يُبقي المجموعة على ما كتبه المنتج القديم (§الانحراف).
     *
     * النموذجان يُقرآن معًا: البحث يأخذ العنوان من المجموعة، والمتجر يأخذه
     * من المنتج. فتحريرُ العنوان في اللوحة — وهي تكتب القديم وحده — كان
     * يترك وثيقة البحث تعرض عنوانًا قديمًا إلى الأبد. لا نسخةَ ثانيةٍ من
     * الحقيقة تُترك بلا مزامنة.
     */
    public function syncFromLegacy(Product $product): ?ProductGroup
    {
        $group = $this->groupFor($product);

        if ($group === null) {
            return null;
        }

        $group->fill([
            'slug' => $product->slug,
            'title' => $product->title,
            'subtitle' => $product->subtitle,
            'short_desc' => $product->short_desc,
            'description' => $product->description,
            'status' => $product->status,
            'stage_id' => $product->stage_id,
            'grade_id' => $product->grade_id,
            'subject_id' => $product->subject_id,
            'semester_id' => $product->semester_id,
            'published_at' => $product->published_at,
        ]);

        if ($group->isDirty()) {
            $group->save();
        }

        /*
         * ─── الصيغة الجسر: تُسحب ولا تُنشر، ولا تُدهَس صيغتُها ───
         *
         * سحبُ المنتج يجب أن يسحبها — وإلّا بقيت «منشورة» في مُبدِّل الصيغ
         * بعد سحب منتجها. أمّا نشرُ المنتج فلا ينشرها: قد يكون المحرّر
         * أوقفها عمدًا (نفدت، أو تُراجَع) والمنتج يُباع بصيغه الأخرى.
         * والنسخ في الاتّجاهين كان يُلغي ذلك القرار عند كلّ حفظ.
         *
         * و`format` لا يُنسخ إطلاقًا: الصيغة الثانية تُعرَّف بصيغتها هي
         * (`docx` مثلًا) وعمودُ المنتج يحمل صيغةً واحدة. نسخُه كان يجعل كلّ
         * الصيغ تُسمّى باسمٍ واحد فيستحيل تمييزُها في السلّة.
         */
        if (in_array($product->status, ['draft', 'retired'], true)) {
            ProductVariant::query()
                ->where('legacy_product_id', $product->id)
                ->where('status', 'published')
                ->update(['status' => $product->status]);
        }

        return $group->refresh();
    }

    /**
     * يشتقّ الاستراتيجيّات ويرفض ما لا يليق بالطبيعة.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function resolveStrategies(string $nature, array $requested): array
    {
        $requested = array_values(array_unique(array_filter($requested)));

        if ($requested === []) {
            /*
             * §٤٣ — الحزمة تُسلَّم بمكوّناتها، فلا تسليمَ لها وهي فارغة.
             *
             * مطالبتُها باستراتيجيّةٍ عند الإنشاء كانت تُجبر المشغّل على
             * اختيار طريقةٍ لا معنى لها ثمّ تُدهَس حين يُضاف أوّل مكوّن.
             * والقائمة الفارغة هنا حالةٌ مُعلَنة لا نسيان: `BundleComposition`
             * تملؤها اتّحادَ مكوّناتها، وبوّابة النشر تمنع نشر حزمةٍ فارغة.
             */
            if ($nature === 'COMPOSITE') {
                return [];
            }

            $default = ProductCapabilities::defaultStrategyFor($nature);

            if ($default === null) {
                throw ValidationException::withMessages([
                    'fulfillmentStrategies' => [
                        ProductCapabilities::NATURE_LABELS[$nature]
                        .' لا اقتراح افتراضيّ له — اختر استراتيجيّات التسليم صراحةً.',
                    ],
                ]);
            }

            return [$default];
        }

        $unknown = array_values(array_diff($requested, ProductCapabilities::FULFILLMENT_STRATEGIES));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'fulfillmentStrategies' => ['استراتيجيّة تسليمٍ غير معروفة: '.implode('، ', $unknown).'.'],
            ]);
        }

        $unfit = array_values(array_diff($requested, ProductCapabilities::strategiesFor($nature)));

        if ($unfit !== []) {
            throw ValidationException::withMessages([
                'fulfillmentStrategies' => [
                    'استراتيجيّة لا تليق بـ'.ProductCapabilities::NATURE_LABELS[$nature].': '
                    .implode('، ', $unfit).'.',
                ],
            ]);
        }

        if (! ProductCapabilities::satisfiesNature($nature, $requested)) {
            throw ValidationException::withMessages([
                'fulfillmentStrategies' => [
                    'الهجين يوجب تسليمًا رقميًّا وماديًّا معًا — وإلّا فهو ليس هجينًا.',
                ],
            ]);
        }

        return $requested;
    }

    /** @param list<string> $strategies */
    private function writeFulfillments(ProductOffer $offer, array $strategies): void
    {
        foreach (array_values($strategies) as $position => $strategy) {
            OfferFulfillment::create([
                'product_offer_id' => $offer->id,
                'strategy' => $strategy,
                'position' => $position,
            ]);
        }
    }

    private function defaultTaxClassId(): ?int
    {
        $id = DB::table('tax_classes')->where('code', 'SA_VAT_STANDARD')->value('id');

        return $id === null ? null : (int) $id;
    }
}
