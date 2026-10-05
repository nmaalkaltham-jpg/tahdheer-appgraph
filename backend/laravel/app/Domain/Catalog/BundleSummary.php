<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\BundleComponent;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Pricing\PricingService;
use App\Models\Product;

/**
 * ملخّص أوّل حزمةٍ منشورة تحتوي المنتج — لبطاقة «وفّر مع الحزمة».
 *
 * كلّ رقمٍ من مصدره: ثمنُ الحزمة من عرض صيغتها (أو سعر منتجها القديم)،
 * و«السعر قبل» مجموعُ أثمان المكوّنات المنتظمة. لا خصمَ يُكتب ولا حزمةَ تُخترع.
 *
 * يعمل على علاقاتٍ محمَّلةٍ مسبقًا فحسب (انظر `ProductController::show`):
 * علاقةٌ غير محمَّلة تعني «لا ملخّص» لا استعلامًا لكلّ بطاقة.
 */
final class BundleSummary
{
    /** العلاقات التي يلزم تحميلها كي يُبنى الملخّص بلا استعلامٍ إضافيّ. */
    public const EAGER = [
        'bundleComponents.bundle.legacyProduct.price',
        'bundleComponents.bundle.legacyProduct.cover',
        'bundleComponents.bundle.stage',
        'bundleComponents.bundle.subject',
        'bundleComponents.bundle.publishedVariants.offer',
        'bundleComponents.bundle.bundleComponents.variant.offer',
        'bundleComponents.bundle.bundleComponents.variant.legacyProduct.price',
    ];

    /** العلاقات التي تحتاجها بطاقة الحزمة نفسها (قوائم الحزم وصفحتها). */
    public const BUNDLE_EAGER = [
        'legacyProduct.price', 'legacyProduct.cover', 'stage', 'subject', 'publishedVariants.offer',
        'bundleComponents.variant.offer', 'bundleComponents.variant.legacyProduct.price',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function forProduct(Product $product): ?array
    {
        if (! $product->relationLoaded('bundleComponents')) {
            return null;
        }

        /** @var BundleComponent|null $membership */
        $membership = $product->bundleComponents
            ->filter(fn (BundleComponent $c) => $c->bundle !== null
                && $c->bundle->status === 'published'
                && $c->bundle->product_nature === 'COMPOSITE')
            ->sortBy('bundle_product_group_id')
            ->first();

        return $membership === null ? null : self::summarize($membership->bundle);
    }

    /**
     * بطاقة الحزمة: كلّ رقمٍ من مصدره — ثمنُها من عرضها، و«السعر قبل» مجموعُ
     * أثمان مكوّناتها. `itemCount`/`itemsTotalMinor`/`intro`/`cartProductId`
     * أسماءٌ يقرؤها DTO صفحة الحزم في المتجر، وتُصدَر بجانب الأسماء العامّة.
     *
     * @return array<string, mixed>|null
     */
    public static function summarize(ProductGroup $bundle): ?array
    {
        $price = self::bundlePrice($bundle);

        if ($price === null) {
            return null;
        }

        [$priceMinor, $currency] = $price;

        $regularMinor = 0;
        foreach ($bundle->bundleComponents as $component) {
            $regularMinor += self::unitPrice($component->variant) * max(1, (int) $component->quantity);
        }

        $legacy = $bundle->relationLoaded('legacyProduct') ? $bundle->legacyProduct : null;
        $description = $bundle->short_desc ?? $bundle->description;

        $out = [
            'id' => (int) $bundle->id,
            'slug' => (string) $bundle->slug,
            'title' => (string) $bundle->title,
            'description' => $description,
            'itemsCount' => $bundle->bundleComponents->count(),
            'itemCount' => $bundle->bundleComponents->count(),
            'priceMinor' => $priceMinor,
            'regularMinor' => $regularMinor,
            'itemsTotalMinor' => $regularMinor,
            'currency' => $currency,
            'url' => '/bundles/'.$bundle->slug,
        ];

        if ($description !== null) {
            $out['intro'] = $description;
        }

        /* المنتج القديم للحزمة هو ما تُضاف به إلى السلّة — غيابُه يعني «لا تُشترى بعد». */
        if ($legacy !== null && $legacy->status === 'published') {
            $out['cartProductId'] = (int) $legacy->id;
        }

        if ($legacy !== null && $legacy->relationLoaded('cover') && $legacy->cover !== null) {
            $out['image'] = [
                'url' => self::absoluteMediaUrl((string) $legacy->cover->url),
                'alt' => $legacy->cover->alt_ar ?? $bundle->title,
                'w' => $legacy->cover->width,
                'h' => $legacy->cover->height,
            ];
        }

        $path = [];
        foreach (['stage', 'subject'] as $relation) {
            if ($bundle->relationLoaded($relation) && $bundle->{$relation} !== null) {
                $path[] = (string) $bundle->{$relation}->name_ar;
            }
        }
        if ($path !== []) {
            $out['categoryPath'] = $path;
        }

        return $out;
    }

    /**
     * مكوّنات الحزمة بترتيبها. الصيغة التي لها منتجٌ قديم تحمل رابطه ونوعه؛
     * وغيرها (صيغةٌ إضافيّة) تحمل صيغتها وثمنها بلا رابطٍ يُخترع.
     *
     * @return list<array{id: int, slug: string, title: string, format: ?string, resourceType: ?string, priceMinor: int, url: ?string, meta?: string}>
     */
    public static function items(ProductGroup $bundle): array
    {
        $items = [];

        foreach ($bundle->bundleComponents as $component) {
            $variant = $component->variant;
            if ($variant === null) {
                continue;
            }

            $product = $variant->relationLoaded('legacyProduct') ? $variant->legacyProduct : null;

            $item = [
                'id' => (int) $variant->id,
                'slug' => (string) ($product?->slug ?? $variant->slug),
                'title' => (string) ($variant->title ?? $product?->title ?? $variant->slug),
                'format' => $variant->format ?? $product?->format,
                'resourceType' => $product?->resource_type,
                'priceMinor' => self::unitPrice($variant),
                'url' => $product !== null ? '/products/'.$product->slug : null,
            ];

            $meta = array_values(array_filter([$item['format'], $item['resourceType']]));
            if ($meta !== []) {
                $item['meta'] = implode(' · ', $meta);
            }

            $items[] = $item;
        }

        return $items;
    }

    /** ثمن المكوّن المنتظم: عرض صيغته أوّلًا، ثمّ سعر منتجه القديم. */
    private static function unitPrice(?ProductVariant $variant): int
    {
        return (int) ($variant?->offer?->regular_minor ?? $variant?->legacyProduct?->price?->regular_minor ?? 0);
    }

    private static function absoluteMediaUrl(string $url): string
    {
        if (preg_match('#^(https?:)?//#i', $url) === 1) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    /**
     * ثمن الحزمة النافذ الآن وعملته — من العرض النشط أوّلًا، ثمّ من السعر القديم.
     *
     * @return array{0: int, 1: string}|null
     */
    private static function bundlePrice(ProductGroup $bundle): ?array
    {
        foreach ($bundle->publishedVariants as $variant) {
            if ($variant->offer !== null) {
                return [$variant->offer->effectivePriceMinor(), (string) $variant->offer->currency];
            }
        }

        $legacy = $bundle->legacyProduct?->price;

        if ($legacy === null) {
            return null;
        }

        return [
            PricingService::activeSaleMinor($legacy) ?? (int) $legacy->regular_minor,
            (string) $legacy->currency,
        ];
    }
}
