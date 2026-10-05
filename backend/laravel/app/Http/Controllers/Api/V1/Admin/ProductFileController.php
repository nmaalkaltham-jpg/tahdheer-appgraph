<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetImporter;
use App\Domain\Assets\Models\ProductFile;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ملفّات المنتج — القائمة والرفع والتعديل والحذف والترتيب.
 *
 * الفجوة كانت محدّدة: `/admin/products/{id}` يُرسل **عدد** الملفّات فقط، فلا
 * قائمة ولا رفع ولا حذف. الدلتا هنا هي نقاط النهاية وحدها.
 *
 * ما أُعيد استعماله بدل بنائه:
 *   · AssetImporter — يتولّى التخزين وإزالة التكرار بالبصمة وربط المنتج.
 *   · Entitlements  — بنية التسليم للمشتري لم تُلمَس.
 *   · AdminAuditLogger — لا سجلّ تدقيق ثانٍ.
 *
 * الملفّات المدفوعة لا تصير عامّة أبدًا: الرفع يمرّ بـ`paid_private`، ولا
 * يُصدَّر رابط تحميل من هنا — التسليم يبقى بمنحة موقّعة بعد شراء مُثبَت.
 */
final class ProductFileController
{
    /** أدوار الملفّات كما يعرّفها مخطّط الجدول. */
    private const ROLES = ['main', 'companion', 'sample', 'teacher_guide', 'answer_key'];

    /** 512 ميغابايت — سقف رفع واحد. */
    private const MAX_KB = 524288;

    public function __construct(
        private readonly AssetImporter $importer,
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

        $validated = $request->validate([
            /*
             * `file` لا `mimes:` وحدها: امتداد الاسم يملكه الرافع. القاعدة
             * تفحص النوع المُستنتَج من المحتوى، ثم نتحقّق من القائمة صراحةً.
             */
            'file' => 'required|file|max:'.self::MAX_KB,
            'role' => 'sometimes|in:'.implode(',', self::ROLES),
            'label' => 'sometimes|nullable|string|max:160',
        ], [
            'file.required' => 'الملف مطلوب.',
            'file.max' => 'حجم الملف يتجاوز الحد المسموح (512 ميغابايت).',
            'role.in' => 'دور الملف غير معروف.',
        ]);

        $upload = $request->file('file');
        $mime = (string) $upload->getMimeType();

        if (! $this->mimeAllowed($mime)) {
            return response()->json([
                'message' => 'نوع الملف غير مدعوم: '.$mime,
                'code' => 'UNSUPPORTED_MIME',
            ], 422);
        }

        $result = $this->importer->import(
            product: $product,
            sourcePath: $upload->getRealPath(),
            role: $validated['role'] ?? 'main',
            customerLabel: $validated['label'] ?? $upload->getClientOriginalName(),
            visibility: 'paid_private',
        );

        $this->audit->log(
            $this->actor(), 'product.file.uploaded', 'product', $product->id,
            null, ['role' => $validated['role'] ?? 'main', 'sha256' => $result->asset->sha256],
            null, $request,
        );

        return response()->json(['data' => $this->rows($product)], 201);
    }

    public function update(Request $request, int $productId, int $fileId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $file = $this->ownedFile($product, $fileId);

        $validated = $request->validate([
            'role' => 'sometimes|in:'.implode(',', self::ROLES),
            'position' => 'sometimes|integer|min:0|max:999',
            'label' => 'sometimes|nullable|string|max:160',
        ]);

        $before = ['role' => $file->role, 'position' => $file->position];

        DB::transaction(function () use ($file, $validated): void {
            if (array_key_exists('role', $validated)) {
                $file->role = $validated['role'];
            }
            if (array_key_exists('position', $validated)) {
                $file->position = (int) $validated['position'];
            }
            $file->save();

            // التسمية تعيش على الأصل لا على الرابط: الأصل قد يُشارَك بين منتجات.
            if (array_key_exists('label', $validated) && $file->asset !== null) {
                $file->asset->forceFill(['customer_label' => $validated['label']])->save();
            }
        });

        $this->audit->log(
            $this->actor(), 'product.file.updated', 'product', $product->id,
            $before, ['role' => $file->role, 'position' => $file->position], null, $request,
        );

        return response()->json(['data' => $this->rows($product->refresh())]);
    }

    public function destroy(Request $request, int $productId, int $fileId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $file = $this->ownedFile($product, $fileId);

        /*
         * تُفكّ العلاقة فقط؛ الأصل لا يُحذف من التخزين هنا.
         *
         * الأصل مُعرَّف ببصمته وقد يكون مرتبطًا بمنتج آخر، والأخطر: قد تكون
         * له استحقاقات مباعة. حذف البايتات يكسر تحميلًا اشتراه عميل — وهو
         * ضرر لا يُشفى بإعادة الرفع لأن البصمة نفسها تختلف عن المتوقَّع.
         */
        $before = ['role' => $file->role, 'asset_id' => $file->asset_id];
        $file->delete();

        $this->audit->log(
            $this->actor(), 'product.file.detached', 'product', $product->id,
            $before, null, null, $request,
        );

        return response()->json(['data' => $this->rows($product->refresh())]);
    }

    public function reorder(Request $request, int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);

        $validated = $request->validate([
            'order' => 'required|array|min:1|max:50',
            'order.*' => 'integer',
        ]);

        $owned = $product->files()->pluck('id')->all();
        $unknown = array_values(array_diff($validated['order'], $owned));
        if ($unknown !== []) {
            // معرّف من منتج آخر في القائمة يعني محاولة تعديل ما لا يملكه.
            return response()->json([
                'message' => 'ملفّات لا تخصّ هذا المنتج: '.implode('، ', array_map('strval', $unknown)),
                'code' => 'FOREIGN_FILE',
            ], 422);
        }

        DB::transaction(function () use ($validated, $product): void {
            foreach ($validated['order'] as $i => $id) {
                ProductFile::query()->where('product_id', $product->id)->where('id', $id)
                    ->update(['position' => $i]);
            }
        });

        return response()->json(['data' => $this->rows($product->refresh())]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(Product $product): array
    {
        return $product->files()->with('asset')->get()->map(static fn (ProductFile $f): array => [
            'id' => $f->id,
            'role' => $f->role,
            'position' => $f->position,
            'asset' => $f->asset === null ? null : [
                'id' => $f->asset->id,
                'originalName' => $f->asset->original_name,
                'label' => $f->asset->customer_label,
                'mime' => $f->asset->mime,
                'sizeBytes' => (int) $f->asset->size_bytes,
                'sha256' => $f->asset->sha256,
                'visibility' => $f->asset->visibility,
            ],
        ])->all();
    }

    /** ملف يخصّ هذا المنتج، وإلّا 404 — لا تسريب وجود عبر رمز الحالة. */
    private function ownedFile(Product $product, int $fileId): ProductFile
    {
        return ProductFile::query()
            ->where('product_id', $product->id)
            ->where('id', $fileId)
            ->firstOrFail();
    }

    private function mimeAllowed(string $mime): bool
    {
        $allowed = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/msword',
            'application/vnd.ms-powerpoint',
            'application/vnd.ms-excel',
            'application/zip',
            'image/png',
            'image/jpeg',
        ];

        return in_array($mime, $allowed, true);
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
