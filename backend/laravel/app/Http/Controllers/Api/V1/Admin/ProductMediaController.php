<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Models\Product;
use App\Models\ProductMedium;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * وسائط المنتج — القائمة والرفع والنصّ البديل واختيار الغلاف والترتيب والفكّ.
 *
 * الغلاف له مصدر حقيقة واحد بالفعل: `product_media.is_cover`، وعلاقة
 * `Product::cover()` تقرؤه. لم يُبنَ عمود جديد ولا مفهوم منافس — الناقص كان
 * الكتابة فقط.
 *
 * ضمانة الوحدة تُفرَض داخل معاملة: تعيين غلاف يُنزع من الباقي في العملية
 * نفسها، فلا لحظة يوجد فيها غلافان (تجعل `cover()` تختار بالترتيب لا بالقصد)
 * ولا لحظة بلا غلاف.
 *
 * الرفع يذهب إلى البادئة `media/` — الوحيدة التي يخدمها PublicMediaController.
 * الأصول المدفوعة خارجها، فلا يحوّل رفعُ صورةٍ مسارًا عامًّا إلى بابٍ عليها.
 */
final class ProductMediaController
{
    private const MEDIA_DIR = 'media/products';

    /** 10 ميغابايت — صورة عرض لا ملف مصدر. */
    private const MAX_KB = 10240;

    /** أنواع صور فقط، مُستنتَجة من المحتوى لا من الامتداد. */
    private const MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/avif', 'image/svg+xml'];

    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function index(int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);

        return response()->json(['data' => $this->rows($product)]);
    }

    public function store(Request $request, int $productId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);

        $validated = $request->validate([
            'image' => 'required|file|max:'.self::MAX_KB,
            'alt' => 'sometimes|nullable|string|max:200',
            'isCover' => 'sometimes|boolean',
        ], [
            'image.required' => 'الصورة مطلوبة.',
            'image.max' => 'حجم الصورة يتجاوز 10 ميغابايت.',
        ]);

        $upload = $request->file('image');
        $mime = (string) $upload->getMimeType();

        if (! in_array($mime, self::MIMES, true)) {
            return response()->json([
                'message' => 'نوع الصورة غير مدعوم: '.$mime,
                'code' => 'UNSUPPORTED_MIME',
            ], 422);
        }

        [$width, $height] = $this->dimensions($upload->getRealPath(), $mime);

        $bytes = (string) file_get_contents($upload->getRealPath());
        $ext = $this->extensionFor($mime);
        // البصمة في الاسم ⇒ رفع الصورة نفسها مرّتين لا يُنتج ملفًّا ثانيًا،
        // وتغييرها يُنتج اسمًا جديدًا فيصحّ التخزين المؤقّت الطويل.
        $key = self::MEDIA_DIR.'/'.$product->id.'-'.substr(hash('sha256', $bytes), 0, 12).'.'.$ext;

        Storage::disk(config('filesystems.default'))->put($key, $bytes, ['visibility' => 'private']);

        $wantsCover = (bool) ($validated['isCover'] ?? false);

        $medium = DB::transaction(function () use ($product, $key, $validated, $width, $height, $wantsCover) {
            $position = (int) ($product->media()->max('position') ?? -1) + 1;

            $medium = ProductMedium::query()->create([
                'product_id' => $product->id,
                'url' => '/api/v1/media/'.substr($key, strlen('media/')),
                'alt_ar' => $validated['alt'] ?? null,
                'width' => $width,
                'height' => $height,
                'position' => $position,
                // أوّل صورة تصير الغلاف تلقائيًّا: منتج بصورة واحدة وبلا غلاف
                // يعرض فراغًا رغم امتلاكه صورة.
                'is_cover' => $wantsCover || $product->media()->count() === 0,
            ]);

            if ($medium->is_cover) {
                $this->demoteOthers($product, $medium->id);
            }

            return $medium;
        });

        $this->audit->log(
            $this->actor(), 'product.media.attached', 'product', $product->id,
            null, ['media_id' => $medium->id, 'is_cover' => $medium->is_cover], null, $request,
        );

        return response()->json(['data' => $this->rows($product->refresh())], 201);
    }

    public function update(Request $request, int $productId, int $mediaId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $medium = $this->owned($product, $mediaId);

        $validated = $request->validate([
            'alt' => 'sometimes|nullable|string|max:200',
            'isCover' => 'sometimes|boolean',
            'position' => 'sometimes|integer|min:0|max:999',
        ]);

        $before = ['alt' => $medium->alt_ar, 'is_cover' => $medium->is_cover, 'position' => $medium->position];

        DB::transaction(function () use ($medium, $validated, $product): void {
            if (array_key_exists('alt', $validated)) {
                $medium->alt_ar = $validated['alt'];
            }
            if (array_key_exists('position', $validated)) {
                $medium->position = (int) $validated['position'];
            }

            if (array_key_exists('isCover', $validated)) {
                if ($validated['isCover']) {
                    $medium->is_cover = true;
                } elseif ($medium->is_cover) {
                    /*
                     * نزع الغلاف بلا بديل يترك المنتج بصور وبلا غلاف، وهي
                     * حالة تُصيَّر فراغًا في البطاقة. الغلاف يُنقَل لا يُلغى.
                     */
                    throw new ValidationException(
                        validator([], []),
                        response()->json([
                            'message' => 'لا يمكن نزع الغلاف دون تعيين بديل. عيّن صورة أخرى غلافًا بدلًا منه.',
                            'code' => 'COVER_REQUIRED',
                        ], 422),
                    );
                }
            }

            $medium->save();

            if ($medium->is_cover) {
                $this->demoteOthers($product, $medium->id);
            }
        });

        $this->audit->log(
            $this->actor(), 'product.media.updated', 'product', $product->id,
            $before, ['alt' => $medium->alt_ar, 'is_cover' => $medium->is_cover], null, $request,
        );

        return response()->json(['data' => $this->rows($product->refresh())]);
    }

    public function destroy(Request $request, int $productId, int $mediaId): JsonResponse
    {
        $product = Product::query()->findOrFail($productId);
        $medium = $this->owned($product, $mediaId);

        $wasCover = (bool) $medium->is_cover;

        DB::transaction(function () use ($medium, $product, $wasCover): void {
            $medium->delete();

            // حذف الغلاف يرقّي التالي بالترتيب — لا يترك المنتج بلا غلاف.
            if ($wasCover) {
                $next = $product->media()->orderBy('position')->first();
                $next?->forceFill(['is_cover' => true])->save();
            }
        });

        $this->audit->log(
            $this->actor(), 'product.media.detached', 'product', $product->id,
            ['media_id' => $mediaId, 'was_cover' => $wasCover], null, null, $request,
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

        $owned = $product->media()->pluck('id')->all();
        $unknown = array_values(array_diff($validated['order'], $owned));
        if ($unknown !== []) {
            return response()->json([
                'message' => 'وسائط لا تخصّ هذا المنتج: '.implode('، ', array_map('strval', $unknown)),
                'code' => 'FOREIGN_MEDIA',
            ], 422);
        }

        DB::transaction(function () use ($validated, $product): void {
            foreach ($validated['order'] as $i => $id) {
                ProductMedium::query()->where('product_id', $product->id)->where('id', $id)
                    ->update(['position' => $i]);
            }
        });

        return response()->json(['data' => $this->rows($product->refresh())]);
    }

    /** يُبقي غلافًا واحدًا — يُنفَّذ داخل المعاملة نفسها التي عيّنته. */
    private function demoteOthers(Product $product, int $keepId): void
    {
        ProductMedium::query()
            ->where('product_id', $product->id)
            ->where('id', '!=', $keepId)
            ->where('is_cover', true)
            ->update(['is_cover' => false]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(Product $product): array
    {
        return $product->media()->orderBy('position')->get()
            ->map(static fn (ProductMedium $m): array => [
                'id' => $m->id,
                'url' => $m->url,
                'alt' => $m->alt_ar,
                'width' => $m->width,
                'height' => $m->height,
                'position' => $m->position,
                'isCover' => (bool) $m->is_cover,
            ])->all();
    }

    private function owned(Product $product, int $mediaId): ProductMedium
    {
        return ProductMedium::query()
            ->where('product_id', $product->id)
            ->where('id', $mediaId)
            ->firstOrFail();
    }

    /**
     * أبعاد حقيقية من الملف. SVG لا يقرؤه getimagesize، فتُستخرج من viewBox
     * أو من width/height — وإلّا تبقى null بدل رقم مخترَع.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string $path, string $mime): array
    {
        if ($mime === 'image/svg+xml') {
            $head = (string) file_get_contents($path, false, null, 0, 2048);
            if (preg_match('/viewBox\s*=\s*"[\d.\s-]*?([\d.]+)\s+([\d.]+)\s*"/i', $head, $m) === 1) {
                return [(int) round((float) $m[1]), (int) round((float) $m[2])];
            }
            if (preg_match('/width\s*=\s*"(\d+)".*?height\s*=\s*"(\d+)"/is', $head, $m) === 1) {
                return [(int) $m[1], (int) $m[2]];
            }

            return [null, null];
        }

        $info = @getimagesize($path);

        return $info === false ? [null, null] : [(int) $info[0], (int) $info[1]];
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'svg',
        };
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
