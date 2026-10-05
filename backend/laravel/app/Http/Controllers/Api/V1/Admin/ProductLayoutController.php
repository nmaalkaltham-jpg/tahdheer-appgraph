<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Catalog\CoverageEngine;
use App\Domain\Catalog\ProductLayout;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Writing\WritingAssistantProvider;
use App\Domain\Writing\WritingAssistantUnavailable;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * تخطيط المنتج، وتغطيته، والمساعدة الكتابية.
 *
 * ثلاث فجوات في متحكّم واحد لأنها تخدم الشاشة نفسها (استوديو المنتج) وتشترك
 * في جلب المنتج والتحقّق من ملكيته.
 *
 * لا نظام نشر ثانٍ: التخطيط مسوّدة ومنشور
 * عمودان ونسخة تفاؤلية — ويكتب حدثه في صندوق الصادر القائم.
 */
final class ProductLayoutController
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly CoverageEngine $coverage,
        private readonly OutboxWriter $outbox,
        private readonly WritingAssistantProvider $writer,
    ) {}

    public function show(int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $row = $this->row($product);

        return response()->json(['data' => $this->payload($row)]);
    }

    public function update(Request $request, int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);

        $validated = $request->validate([
            'layout' => 'required|array',
            'version' => 'required|integer|min:1',
        ], [
            'layout.required' => 'وثيقة التخطيط مطلوبة.',
            'version.required' => 'رقم النسخة مطلوب لكشف التعارض.',
        ]);

        $normalized = ProductLayout::normalize($validated['layout']);

        $row = DB::transaction(function () use ($product, $normalized, $validated) {
            $row = DB::table('product_layouts')->lockForUpdate()
                ->where('product_id', $product->id)->first();

            if ($row === null) {
                $id = DB::table('product_layouts')->insertGetId([
                    'product_id' => $product->id,
                    'draft_layout' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                    'version' => 2,
                    'updated_by' => $this->actor()?->getKey(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return DB::table('product_layouts')->find($id);
            }

            if ((int) $row->version !== (int) $validated['version']) {
                abort(response()->json([
                    'message' => 'عدّل مشرف آخر التخطيط بينما كنت تحرّره.',
                    'code' => 'VERSION_CONFLICT',
                    'currentVersion' => (int) $row->version,
                ], 409));
            }

            DB::table('product_layouts')->where('id', $row->id)->update([
                'draft_layout' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                'version' => (int) $row->version + 1,
                'updated_by' => $this->actor()?->getKey(),
                'updated_at' => now(),
            ]);

            return DB::table('product_layouts')->find($row->id);
        });

        $this->audit->log($this->actor(), 'product.layout.saved', 'product', $product->id, null, null, null, $request);

        return response()->json(['data' => $this->payload($row)]);
    }

    public function publish(Request $request, int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);

        $row = DB::transaction(function () use ($product) {
            $row = DB::table('product_layouts')->lockForUpdate()
                ->where('product_id', $product->id)->first();

            abort_if($row === null, 404);

            DB::table('product_layouts')->where('id', $row->id)->update([
                'published_layout' => $row->draft_layout,
                'published_version' => $row->version,
                'published_at' => now(),
                'updated_at' => now(),
            ]);

            // الحدث داخل المعاملة: نشرٌ بلا إبطال كاش تغييرٌ لا يراه أحد.
            $this->outbox->write(
                aggregateType: 'product', aggregateId: (string) $product->id,
                eventType: 'product.layout.published',
                payload: ['product_id' => $product->id, 'version' => (int) $row->version],
                idempotencyKey: "product:{$product->id}:layout:{$row->version}",
            );

            return DB::table('product_layouts')->find($row->id);
        });

        $this->audit->log($this->actor(), 'product.layout.published', 'product', $product->id, null, null, null, $request);

        return response()->json(['data' => $this->payload($row)]);
    }

    /** تغطية التخصّص — حكم مفسَّر من الكتالوج، لا درجة مخترَعة. */
    public function coverage(int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);

        return response()->json(['data' => $this->coverage->forProduct($product)]);
    }

    /**
     * اقتراح نصّ. لا يكتب شيئًا: التطبيق فعل صريح لاحق عبر PATCH المنتج/SEO.
     */
    public function suggest(Request $request, int $productId): JsonResponse
    {
        $product = Product::query()->with('seo')->findOrFail($productId);

        $validated = $request->validate([
            'field' => 'required|in:title,shortDesc,description,seoTitle,seoDesc,altText,faq',
            'count' => 'sometimes|integer|min:1|max:5',
        ]);

        if (! $this->writer->isConfigured()) {
            // حالة صريحة لا ردّ مُلفَّق: الواجهة تعرض «غير مهيَّأ».
            return response()->json([
                'message' => 'المساعد الكتابي غير مهيَّأ.',
                'code' => WritingAssistantUnavailable::NOT_CONFIGURED,
                'provider' => $this->writer->name(),
            ], 503);
        }

        try {
            $suggestions = $this->writer->suggest($validated['field'], [
                'title' => $product->title,
                'subtitle' => $product->subtitle,
                'shortDesc' => $product->short_desc,
                'format' => $product->format,
            ], (int) ($validated['count'] ?? 3));
        } catch (WritingAssistantUnavailable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
                'provider' => $this->writer->name(),
            ], 503);
        }

        return response()->json(['data' => [
            'field' => $validated['field'],
            'provider' => $this->writer->name(),
            // اقتراحات فقط — لم يُكتب شيء في قاعدة البيانات.
            'suggestions' => $suggestions,
            'applied' => false,
        ]]);
    }

    private function row(Product $product): object
    {
        $row = DB::table('product_layouts')->where('product_id', $product->id)->first();
        if ($row !== null) {
            return $row;
        }

        return (object) [
            'draft_layout' => json_encode(ProductLayout::defaults(), JSON_UNESCAPED_UNICODE),
            'published_layout' => null,
            'version' => 1,
            'published_version' => null,
            'published_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function payload(object $row): array
    {
        return [
            'draft' => json_decode((string) $row->draft_layout, true),
            'published' => $row->published_layout === null
                ? null
                : json_decode((string) $row->published_layout, true),
            'version' => (int) $row->version,
            'publishedVersion' => $row->published_version === null ? null : (int) $row->published_version,
            'publishedAt' => $row->published_at,
            'widgets' => ProductLayout::WIDGETS,
            'zones' => ProductLayout::ZONES,
            'required' => ProductLayout::REQUIRED,
        ];
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
