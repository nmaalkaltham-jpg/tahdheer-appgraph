<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\SEO\MetadataService;
use App\Models\Product;
use App\Models\ProductSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تحرير SEO المنتج.
 *
 * الفجوة كانت الكتابة وحدها: `MetadataService` موجودة وتُحسب منها بيانات
 * الصفحة، و`product_seo` موجود — ولا نقطة نهاية تكتب فيه. لم تُنشأ خدمة SEO
 * ثانية؛ هذا المتحكّم يكتب في الجدول ويعرض **الناتج المحسوب من الخدمة
 * القائمة** حتى يرى المشرف ما سيُصيَّر فعلًا لا ما كتبه فقط.
 *
 * الفرق مهمّ: العنوان المكتوب قد يُقصّ أو يُستبدل بسلسلة احتياطية حسب قواعد
 * الخدمة. عرض المُدخَل وحده يجعل المشرف يظنّ أنه ضبط ما لم يُضبط.
 */
final class ProductSeoController
{
    public function __construct(
        private readonly MetadataService $metadata,
        private readonly AdminAuditLogger $audit,
    ) {}

    public function show(int $productId): JsonResponse
    {
        $product = Product::query()->with('seo')->findOrFail($productId);

        return response()->json(['data' => $this->payload($product)]);
    }

    public function update(Request $request, int $productId): JsonResponse
    {
        $product = Product::query()->with('seo')->findOrFail($productId);

        $validated = $request->validate([
            // الحدود من أعمدة الجدول لا من تقدير: seo_desc محدود بـ500.
            'seoTitle' => 'sometimes|nullable|string|max:255',
            'seoDesc' => 'sometimes|nullable|string|max:500',
            'quickAnswer' => 'sometimes|nullable|string|max:1000',
            'synonyms' => 'sometimes|nullable|string|max:1000',
            'canonicalOverride' => 'sometimes|nullable|string|max:255|url',
        ], [
            'seoDesc.max' => 'وصف SEO يتجاوز الحد (500 حرف).',
            'canonicalOverride.url' => 'الرابط المعياري يجب أن يكون عنوانًا كاملًا.',
        ]);

        $seo = ProductSeo::query()->firstOrNew(['product_id' => $product->id]);
        $before = $seo->exists ? $seo->only([
            'seo_title', 'seo_desc', 'quick_answer', 'synonyms', 'canonical_override',
        ]) : null;

        $map = [
            'seoTitle' => 'seo_title',
            'seoDesc' => 'seo_desc',
            'quickAnswer' => 'quick_answer',
            'synonyms' => 'synonyms',
            'canonicalOverride' => 'canonical_override',
        ];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $validated)) {
                // نصّ فارغ يعني «امحُ التخصيص» لا «خزّن سلسلة فارغة»: الفارغة
                // تحجب السلسلة الاحتياطية في MetadataService وتُنتج عنوانًا فارغًا.
                $value = $validated[$input];
                $seo->{$column} = is_string($value) && trim($value) === '' ? null : $value;
            }
        }

        $seo->product_id = $product->id;
        $seo->save();

        $this->audit->log(
            $this->actor(), 'product.seo.updated', 'product', $product->id,
            $before, $seo->only(array_values($map)), null, $request,
        );

        return response()->json(['data' => $this->payload($product->refresh()->load('seo'))]);
    }

    /** @return array<string, mixed> */
    private function payload(Product $product): array
    {
        $seo = $product->seo;

        return [
            // ما كتبه المشرف.
            'authored' => [
                'seoTitle' => $seo->seo_title ?? null,
                'seoDesc' => $seo->seo_desc ?? null,
                'quickAnswer' => $seo->quick_answer ?? null,
                'synonyms' => $seo->synonyms ?? null,
                'canonicalOverride' => $seo->canonical_override ?? null,
            ],
            // وما سيُصيَّر فعلًا بعد قواعد الخدمة القائمة.
            'effective' => $this->metadata->forProduct($product),
        ];
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
