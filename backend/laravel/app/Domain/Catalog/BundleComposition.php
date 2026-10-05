<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\BundleComponent;
use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تأليف الحزم — ما تحتويه الحزمة، وما يستحيل أن تحتويه (§٤٣).
 *
 * ─── لماذا الحزمة ليست منتجًا آخر ───
 *
 * الحزمة `COMPOSITE`: ما تراه العين منتجًا واحدًا بثمنٍ واحد، وما يُسلَّم
 * مكوّناتُه كلٌّ بطريقته. فحزمةٌ فيها ملفٌّ رقميّ وكتابٌ مطبوع تُشترى مرّةً
 * وتُسلَّم مرّتين: تنزيلٌ فوريّ وشحنة. وهذا بالضبط ما يجعلها امتحانًا لنظام
 * الأنواع كلّه — لا حالةً خاصّة فيه.
 *
 * ─── وثلاثة شروطٍ يفرضها هذا الصنف لأنّ المحرّك لا يستطيع ───
 *
 * ١ — لا تحتوي الحزمةُ نفسَها. الشرط يمسّ جدولين (المكوّن صيغةٌ، وصيغتُه
 *     تتبع مجموعةً قد تكون الحزمة)، و`CHECK` لا يقرأ جدولًا آخر.
 * ٢ — ولا تحتوي حزمةً تحتويها. الدورة تُنتج توسيعًا لا نهائيًّا عند الدفع،
 *     أي تعليقًا لا خطأ — وهو أسوأ أنواع الأعطال.
 * ٣ — ولا يكون المكوّن حزمةً أصلًا. الحزمة داخل حزمة قابلةٌ للتمثيل نظريًّا،
 *     ولا يوجد ما يوسّعها توسيعًا متعدّد المستويات اليوم؛ فتُرفض صراحةً بدل
 *     أن تُقبل وتُسلَّم ناقصة.
 *
 * ─── وحدُّ الفرادة يُفحص هنا أيضًا ───
 *
 * الفهرس صار `NULLS NOT DISTINCT` فيمنع التكرار في المحرّك. والفحص هنا
 * يحوّل ٢٣٥٠٥ الخام إلى ٤٢٢ باسم حقلها (القاعدة ٣٨).
 */
final class BundleComposition
{
    /** @return Collection<int, BundleComponent> */
    public function componentsOf(ProductGroup $bundle): Collection
    {
        return BundleComponent::query()
            ->where('bundle_product_group_id', $bundle->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * يضيف مكوّنًا إلى حزمة.
     *
     * `selection_group` فارغةٌ تعني «ثابت»: المكوّن يدخل دائمًا. وغيرُ
     * الفارغة تعني «اختر واحدًا من هذه المجموعة» — وهي حزمةٌ قابلة للتهيئة
     * لا يوسّعها الدفع بعد، فتُرفض حتّى يوجد ما يوسّعها.
     */
    public function add(ProductGroup $bundle, int $variantId, int $quantity = 1, bool $required = true, ?int $position = null): BundleComponent
    {
        $this->assertBundle($bundle);

        $variant = ProductVariant::query()->with('group')->find($variantId);

        if ($variant === null) {
            throw ValidationException::withMessages([
                'componentVariantId' => ['لا صيغة بهذا المعرّف.'],
            ]);
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => ['الكميّة في الحزمة عددٌ موجب.'],
            ]);
        }

        $this->assertComposable($bundle, $variant);

        return DB::transaction(function () use ($bundle, $variant, $quantity, $required, $position): BundleComponent {
            $exists = BundleComponent::query()
                ->where('bundle_product_group_id', $bundle->id)
                ->where('component_variant_id', $variant->id)
                ->whereNull('selection_group')
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'componentVariantId' => ['هذه الصيغة مكوّنٌ في الحزمة أصلًا — زد كميّتها بدل تكرارها.'],
                ]);
            }

            $component = BundleComponent::create([
                'bundle_product_group_id' => $bundle->id,
                'component_variant_id' => $variant->id,
                'quantity' => $quantity,
                'required' => $required,
                'selection_group' => null,
                'position' => $position ?? ((int) BundleComponent::query()
                    ->where('bundle_product_group_id', $bundle->id)->max('position') + 1),
            ]);

            $this->syncFulfillments($bundle);

            return $component;
        });
    }

    public function remove(ProductGroup $bundle, int $componentId): void
    {
        DB::transaction(function () use ($bundle, $componentId): void {
            BundleComponent::query()
                ->where('bundle_product_group_id', $bundle->id)
                ->whereKey($componentId)
                ->delete();

            $this->syncFulfillments($bundle);
        });
    }

    /**
     * يجعل تسليم الحزمة اتّحادَ تسليم مكوّناتها.
     *
     * ─── لماذا تحمل الحزمة استراتيجيّاتٍ أصلًا ───
     *
     * بند الحزمة يُوسَّع عند الدفع فلا يبقى في الطلب. لكنّه **يبقى في
     * السلّة**، و`CheckoutRequirementsResolver` يسأل عنه: هل تحتاج هذه السلّة
     * عنوان شحن؟ فحزمةٌ بلا استراتيجيّات تهبط إلى العمود القديم («رقميّ»)
     * فلا يُطلب عنوان — ثمّ تُوسَّع إلى مكوّنٍ ماديّ يقف عند «لا عنوان شحن»
     * بعد أخذ المال.
     *
     * والاتّحاد يُعاد حسابُه عند كلّ تغيير، فلا يبقى أثرُ مكوّنٍ حُذف.
     */
    private function syncFulfillments(ProductGroup $bundle): void
    {
        $variantIds = $this->componentsOf($bundle)->pluck('component_variant_id');

        $strategies = OfferFulfillment::query()
            ->whereIn('product_offer_id', ProductOffer::query()
                ->whereIn('product_variant_id', $variantIds)->select('id'))
            ->pluck('strategy')
            ->unique()
            ->values()
            ->all();

        $offers = ProductOffer::query()
            ->whereIn('product_variant_id', ProductVariant::query()
                ->where('product_group_id', $bundle->id)->select('id'))
            ->get();

        foreach ($offers as $offer) {
            OfferFulfillment::query()->where('product_offer_id', $offer->id)->delete();

            foreach ($strategies as $position => $strategy) {
                OfferFulfillment::create([
                    'product_offer_id' => $offer->id,
                    'strategy' => $strategy,
                    'position' => $position,
                ]);
            }
        }
    }

    /**
     * الصيغ التي تُسلَّم فعلًا حين تُشترى هذه الحزمة.
     *
     * تُقرأ عند الدفع لتوسيع بند الحزمة. وحزمةٌ فارغة تُعيد قائمةً فارغة،
     * ويقرّر النداء ما يفعل بها — الدفع يرفض، والنشر يمنع.
     *
     * @return list<array{variant: ProductVariant, quantity: int}>
     */
    public function expand(ProductGroup $bundle): array
    {
        return $this->componentsOf($bundle)
            ->map(fn (BundleComponent $c) => [
                'variant' => ProductVariant::query()->with('group')->find($c->component_variant_id),
                'quantity' => max(1, (int) $c->quantity),
            ])
            ->filter(fn (array $row) => $row['variant'] !== null)
            ->values()
            ->all();
    }

    private function assertBundle(ProductGroup $bundle): void
    {
        if ($bundle->product_nature !== 'COMPOSITE') {
            throw ValidationException::withMessages([
                'productNature' => ['المكوّنات تُضاف إلى حزمة مركَّبة وحدها — بدّل نوع المنتج أوّلًا.'],
            ]);
        }
    }

    /**
     * يرفض ما لا يصلح مكوّنًا، بسببٍ مكتوب لكلّ حالة.
     *
     * التمييز بين الأسباب ليس ترفًا: «هذه الحزمة نفسها» يُصلَح باختيارٍ آخر،
     * و«هذه حزمةٌ أخرى» يُصلَح بتسطيح مكوّناتها، و«دورة» تُصلَح بحذف رابطٍ
     * في الاتّجاه المعاكس. ورسالةٌ واحدة تقول «غير صالح» تترك المشغّل يخمّن.
     */
    private function assertComposable(ProductGroup $bundle, ProductVariant $variant): void
    {
        $componentGroup = $variant->group;

        if ($componentGroup === null) {
            throw ValidationException::withMessages([
                'componentVariantId' => ['هذه الصيغة بلا مجموعة — لا يُعرف ما تُسلّمه.'],
            ]);
        }

        if ((int) $componentGroup->id === (int) $bundle->id) {
            throw ValidationException::withMessages([
                'componentVariantId' => ['الحزمة لا تحتوي نفسها.'],
            ]);
        }

        if ($componentGroup->product_nature === 'COMPOSITE') {
            throw ValidationException::withMessages([
                'componentVariantId' => [
                    'المكوّن حزمةٌ أخرى، ولا يوجد ما يوسّع الحزم المتداخلة بعد — '
                    .'أضف مكوّناتها مباشرةً.',
                ],
            ]);
        }

        /*
         * والدورة تُفحص رغم ما سبق: منعُ الحزمة داخل حزمةٍ يمنع الدورة اليوم،
         * لكنّ الشرطين مستقلّان — يُرفع الأوّل يومًا فتبقى الدورة ممنوعة.
         */
        $containsBundle = BundleComponent::query()
            ->where('bundle_product_group_id', $componentGroup->id)
            ->whereIn('component_variant_id', ProductVariant::query()
                ->where('product_group_id', $bundle->id)->select('id'))
            ->exists();

        if ($containsBundle) {
            throw ValidationException::withMessages([
                'componentVariantId' => ['دورة: تلك المجموعة تحتوي هذه الحزمة بالفعل.'],
            ]);
        }
    }
}
