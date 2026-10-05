<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Assets\Models\ProductFile;
use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تأليف صيغ المنتج — أوّل مسار كتابةٍ إلى النموذج الكونيّ.
 *
 * صفحة المنتج تعرض مُبدِّل الصيغ منذ ٢٠٢٦-٠٩-١٧، والسلّة والدفع والتسليم
 * كلّها تعرف الصيغة. ولم يكن في اللوحة ما يؤلّف صيغةً ثانية، فبقيت القدرة
 * بلا بيانات: ستّةٌ وعشرون منتجًا، لكلٍّ صيغةٌ واحدة مردومة.
 *
 * وخدمةُ نطاقٍ لا منطقٌ في متحكّم: الكتابة هنا تمسّ أربعة جداول وتحمل ثابتًا
 * لا يُرى من واجهة HTTP (§الصدق أدناه)، ومتحكّمٌ يكتبها مباشرةً يجعل كلّ
 * مستهلكٍ ثانٍ — أمرُ استيراد، مهمّةٌ مجدوَلة — ينسخ الثابت أو ينساه.
 *
 * ## ثابت الصدق الذي تحرسه هذه الخدمة
 *
 * `product_files.product_variant_id` الفارغ يعني «لكلّ الصيغ» لا «بلا صيغة».
 * وهو صحيحٌ ما دام للمنتج صيغةٌ واحدة. فإذا أُضيفت الثانية صار ملفّ Word
 * القديم — وهو عامٌّ — **قابلًا للتسليم لمشتري PowerPoint**: يختار الصيغة،
 * ويدفع ثمنها، ويستلم غيرها. وهو عيبٌ لا يظهر في أيّ اختبارٍ للصيغة وحدها
 * لأنّه لا يقع إلّا لحظة وجود صيغتين.
 *
 * ولذلك تُعيد `create()` ربطَ كلّ ملفٍّ قابلٍ للتسليم بالصيغة الأصليّة قبل
 * أن تُنشئ الثانية. والعيّنات (`sample`) لا تُمسّ: هي معاينةٌ عامّة للمنتج
 * لا بضاعةٌ تُسلَّم، وحصرُها في صيغةٍ يُخفيها عن مشتري الأخرى بلا سبب.
 */
final class VariantAuthoring
{
    /*
     * ─── أربعة ثوابتٍ ميّتة زالت ───
     *
     * `SELLING_MODELS` و`RECURRING_MODELS` و`VARIANT_STATUSES` و`OFFER_STATUSES`
     * كانت مكتوبةً هنا ولا يقرؤها أحد — ولا هذا الصنف نفسه. وثابتٌ ميّت
     * يُكرّر ثابتًا حيًّا فخٌّ لا فوضى: من احتاج قائمة نماذج البيع كان أمامه
     * نسختان، إحداهما لا يحرسها شيء. المفردات في `ProductCapabilities`.
     */

    /**
     * استراتيجيّة التسليم الافتراضيّة لمتجر ملفّات.
     *
     * تُكتب صراحةً ولا تُترك للتدرّج: `FulfillmentStrategyResolver` يتدبّر
     * غيابها — يهبط إلى جسر المنتج القديم ثمّ إلى عموده — لكنّه يسجّل
     * النسبة `legacy_bridge.offer` أو أضعف. والصيغة المؤلَّفة اليوم يجب أن
     * تُنسب إلى عرضها هي (`line.offer`)، وإلّا صارت كلّ الصيغ تُسلَّم
     * باستراتيجيّة الصيغة الأولى مهما اختلفت.
     */
    public const DEFAULT_STRATEGY = 'DIGITAL_DOWNLOAD';

    /** أدوارٌ تُسلَّم للمشتري — وهي وحدها ما يُعاد ربطه بالصيغة. */
    private const DELIVERABLE_ROLES = ProductFile::DELIVERABLE_ROLES;

    /**
     * مجموعة المنتج في النموذج الكونيّ.
     *
     * `Product` بلا علاقة `variants()` بالتصميم: الصيغة تتبع المجموعة،
     * والمجموعة تصل المنتج القديم بجسرٍ فريد. فالمرور إجباريٌّ بالمجموعة.
     */
    public function groupFor(Product $product): ?ProductGroup
    {
        return ProductGroup::query()->where('legacy_product_id', $product->id)->first();
    }

    /** @return Collection<int, ProductVariant> */
    public function variantsFor(Product $product): Collection
    {
        $group = $this->groupFor($product);

        if ($group === null) {
            return new Collection;
        }

        return ProductVariant::query()
            ->where('product_group_id', $group->id)
            ->with(['offer', 'offers'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * ينشئ صيغةً وعرضها وتسليمها في معاملةٍ واحدة.
     *
     * @param  array{sku: string, slug: string, title: ?string, format: ?string, status: string, position: int, sellingModel: string, regularMinor: int, saleMinor: ?int, offerStatus: string}  $input
     */
    public function create(Product $product, array $input): ProductVariant
    {
        $group = $this->groupFor($product);

        if ($group === null) {
            throw ValidationException::withMessages([
                'product' => ['هذا المنتج خارج النموذج الكونيّ، فلا مجموعة تُضاف إليها صيغة.'],
            ]);
        }

        return DB::transaction(function () use ($product, $group, $input): ProductVariant {
            $existing = ProductVariant::query()
                ->where('product_group_id', $group->id)
                ->lockForUpdate()
                ->get();

            /*
             * إعادة الربط تسبق الإنشاء لا تتلوه: بين الكتابتين لحظةٌ يكون
             * فيها للمنتج صيغتان وملفٌّ عامّ — وهي بالضبط الحالة الكاذبة.
             * والمعاملة تحمي القارئ الخارجيّ، والترتيب يحمي من انقطاعٍ
             * يترك الإنشاء بلا إعادة ربط.
             */
            if ($existing->count() === 1) {
                $this->bindGenericDeliverablesTo($product, $existing->first());
            }

            $variant = ProductVariant::create([
                'product_group_id' => $group->id,
                'sku' => $input['sku'],
                'slug' => $input['slug'],
                'title' => $input['title'] ?? null,
                'format' => $input['format'] ?? null,
                'position' => $input['position'] ?? ($existing->max('position') + 1),
                'status' => $input['status'],
                /* الجسر فريد ويملكه المردوم — والثانية لا تنازعه عليه. */
                'legacy_product_id' => null,
            ] + $this->physicalColumns($input));

            $offer = ProductOffer::create([
                'product_variant_id' => $variant->id,
                'selling_model' => $input['sellingModel'],
                /*
                 * استراتيجيّة المخزون تتبع طبيعة المجموعة حين لا تُمرَّر.
                 * كانت `UNLIMITED` مكتوبةً هنا لكلّ صيغة، فصيغةٌ ماديّة من
                 * نسخةٍ واحدة كانت تُباع بلا حدّ — عيبٌ لا يظهر إلّا بعد
                 * أن يدفع الثاني ثمن ما لا يوجد.
                 */
                'inventory_strategy' => $input['inventoryStrategy']
                    ?? ProductCapabilities::defaultInventoryFor((string) $group->product_nature),
                'regular_minor' => $input['regularMinor'],
                'sale_minor' => $input['saleMinor'] ?? null,
                'currency' => 'SAR',
                'status' => $input['offerStatus'],
                'tax_class_id' => $this->defaultTaxClassId(),
            ] + $this->billingColumns($input));

            $this->writeFulfillments($offer, $this->strategiesFor($group, $input));

            return $variant->refresh();
        });
    }

    /**
     * يعدّل الصيغة وعرضها الفعّال.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(ProductVariant $variant, array $input): ProductVariant
    {
        return DB::transaction(function () use ($variant, $input): ProductVariant {
            foreach (['sku', 'slug', 'title', 'format', 'status'] as $key) {
                if (array_key_exists($key, $input)) {
                    $variant->{$key} = $input[$key];
                }
            }

            if (array_key_exists('position', $input)) {
                $variant->position = (int) $input['position'];
            }

            foreach ($this->physicalColumns($input) as $column => $value) {
                $variant->{$column} = $value;
            }

            $variant->save();

            $offer = $variant->offer()->lockForUpdate()->first()
                ?? $variant->offers()->orderBy('id')->lockForUpdate()->first();

            if ($offer !== null) {
                foreach (['sellingModel' => 'selling_model', 'offerStatus' => 'status'] as $from => $to) {
                    if (array_key_exists($from, $input)) {
                        $offer->{$to} = $input[$from];
                    }
                }
                if (array_key_exists('regularMinor', $input)) {
                    $offer->regular_minor = (int) $input['regularMinor'];
                }
                if (array_key_exists('saleMinor', $input)) {
                    $offer->sale_minor = $input['saleMinor'] === null ? null : (int) $input['saleMinor'];
                }
                if (array_key_exists('inventoryStrategy', $input)) {
                    $offer->inventory_strategy = $input['inventoryStrategy'];
                }
                foreach ($this->billingColumns($input) as $column => $value) {
                    $offer->{$column} = $value;
                }
                $offer->save();

                /*
                 * استراتيجيّات التسليم تُعاد كتابتها حين تُطلب صراحةً، ولا
                 * تُمسّ حين لا تُطلب: صيغةٌ رُفع سعرُها لا يتغيّر تسليمُها.
                 */
                if (array_key_exists('fulfillmentStrategies', $input)
                    && $input['fulfillmentStrategies'] !== null) {
                    $group = ProductGroup::query()->whereKey($variant->product_group_id)->first();
                    $target = $this->strategiesFor($group, $input);

                    OfferFulfillment::query()->where('product_offer_id', $offer->id)->delete();
                    $this->writeFulfillments($offer, $target);
                }

                /* عرضٌ بلا تسليمٍ يهبط إلى أضعف نسبة — يُستدرك هنا لا يُترك. */
                $this->ensureFulfillment($offer);
            }

            return $variant->refresh();
        });
    }

    /**
     * يحذف صيغةً — ويرفض ما يكسر شيئًا.
     *
     * والرفض معلَّل في كلّ حال: الصيغة الأخيرة تُبقي المنتج قابلًا للبيع،
     * والمجسورة هي هويّته في النموذج القديم، والمباعة لها استحقاقاتٌ تشير
     * إليها. وحذفُ صيغةٍ يُفرِّغ `product_variant_id` من ملفّاتها بحكم
     * `nullOnDelete` فتعود عامّةً — ولذلك يُمنع الحذف وللمنتج صيغٌ أخرى
     * ما لم تُنقل ملفّاتها أوّلًا.
     */
    public function delete(ProductVariant $variant): void
    {
        DB::transaction(function () use ($variant): void {
            /*
             * تُجلب المعرّفات ثمّ تُعدّ، ولا يُعدّ مع القفل مباشرةً:
             * PostgreSQL يرفض `FOR UPDATE` مع دالّة تجميع
             * (`0A000: FOR UPDATE is not allowed with aggregate functions`).
             * والقفل مقصود — يمنع حذفين متزامنين يتركان المجموعة بلا صيغة.
             */
            $siblings = ProductVariant::query()
                ->where('product_group_id', $variant->product_group_id)
                ->lockForUpdate()
                ->pluck('id')
                ->count();

            if ($siblings <= 1) {
                throw ValidationException::withMessages([
                    'variant' => ['لا تُحذف الصيغة الأخيرة — المنتج بلا صيغةٍ لا يُباع.'],
                ]);
            }

            if ($variant->legacy_product_id !== null) {
                throw ValidationException::withMessages([
                    'variant' => ['هذه الصيغة هي جسر المنتج إلى النموذج القديم، فلا تُحذف.'],
                ]);
            }

            $sold = DB::table('order_items')->where('product_variant_id', $variant->id)->exists();

            if ($sold) {
                throw ValidationException::withMessages([
                    'variant' => ['هذه الصيغة مُباعة في طلباتٍ قائمة — أوقفها بدل حذفها.'],
                ]);
            }

            $bound = ProductFile::query()->where('product_variant_id', $variant->id)->count();

            if ($bound > 0) {
                throw ValidationException::withMessages([
                    'variant' => ["مرتبطٌ بها {$bound} ملفًّا — انقلها إلى صيغةٍ أخرى قبل الحذف."],
                ]);
            }

            $variant->offers()->each(static function (ProductOffer $offer): void {
                OfferFulfillment::query()->where('product_offer_id', $offer->id)->delete();
                $offer->delete();
            });

            $variant->delete();
        });
    }

    /**
     * يربط ملفّات المنتج القابلة للتسليم — وهي العامّة اليوم — بصيغةٍ بعينها.
     *
     * تُنادى لحظةَ ظهور الصيغة الثانية، لأنّ العموم قبلها صحيح.
     *
     * @return int عدد الملفّات التي أُعيد ربطها
     */
    public function bindGenericDeliverablesTo(Product $product, ProductVariant $variant): int
    {
        return ProductFile::query()
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->whereIn('role', self::DELIVERABLE_ROLES)
            ->update(['product_variant_id' => $variant->id]);
    }

    /** يضمن للعرض استراتيجيّة تسليمٍ واحدة على الأقلّ. */
    public function ensureFulfillment(ProductOffer $offer): void
    {
        $exists = OfferFulfillment::query()->where('product_offer_id', $offer->id)->exists();

        if (! $exists) {
            OfferFulfillment::create([
                'product_offer_id' => $offer->id,
                'strategy' => self::DEFAULT_STRATEGY,
                'position' => 0,
            ]);
        }
    }

    /**
     * أعمدة الصيغة الماديّة والمعرّفات التجاريّة، لما وصل منها وحده.
     *
     * `array_key_exists` لا `isset`: `null` قيمةٌ مقصودة تعني «امحُ ما كان»،
     * و`isset` كانت ستبتلعها فيستحيل مسحُ وزنٍ كُتب خطأً.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function physicalColumns(array $input): array
    {
        $map = [
            'weightGrams' => 'weight_grams', 'lengthMm' => 'length_mm',
            'widthMm' => 'width_mm', 'heightMm' => 'height_mm',
            'gtin' => 'gtin', 'mpn' => 'mpn', 'isbn' => 'isbn', 'barcode' => 'barcode',
        ];

        $columns = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $input)) {
                $columns[$to] = $input[$from];
            }
        }

        return $columns;
    }

    /**
     * أعمدة دورة الفوترة.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function billingColumns(array $input): array
    {
        $map = [
            'billingIntervalCount' => 'billing_interval_count',
            'billingIntervalUnit' => 'billing_interval_unit',
            'trialDays' => 'trial_days',
        ];

        $columns = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $input)) {
                $columns[$to] = $input[$from];
            }
        }

        return $columns;
    }

    /**
     * استراتيجيّات التسليم لهذه الصيغة، مُتحقَّقًا منها ضدّ طبيعة المجموعة.
     *
     * الافتراض الأخير `DEFAULT_STRATEGY` يبقى للمجموعة التي لا طبيعة لها —
     * وهي المنتجات التي سبقت مسار الكتابة. وما له طبيعةٌ يأخذ اقتراحها،
     * فلا تُسلَّم صيغةٌ ماديّة تنزيلًا رقميًّا.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function strategiesFor(?ProductGroup $group, array $input): array
    {
        $nature = $group?->product_nature;
        $requested = array_values(array_unique(array_filter((array) ($input['fulfillmentStrategies'] ?? []))));

        if ($requested === []) {
            $default = $nature === null
                ? self::DEFAULT_STRATEGY
                : (ProductCapabilities::defaultStrategyFor($nature) ?? self::DEFAULT_STRATEGY);

            return [$default];
        }

        if ($nature !== null) {
            $unfit = array_values(array_diff($requested, ProductCapabilities::strategiesFor($nature)));

            if ($unfit !== []) {
                throw ValidationException::withMessages([
                    'fulfillmentStrategies' => [
                        'استراتيجيّة لا تليق بـ'.ProductCapabilities::NATURE_LABELS[$nature].': '
                        .implode('، ', $unfit).'.',
                    ],
                ]);
            }
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

    /**
     * صنف الضريبة القياسيّ، أو `null` حين لا صنف مُهيّأ.
     *
     * `null` ولا اختراع: صنفٌ مخترَع يحسب ضريبةً خاطئة على فاتورةٍ حقيقيّة.
     */
    private function defaultTaxClassId(): ?int
    {
        $id = DB::table('tax_classes')->where('code', 'SA_VAT_STANDARD')->value('id');

        return $id === null ? null : (int) $id;
    }
}
