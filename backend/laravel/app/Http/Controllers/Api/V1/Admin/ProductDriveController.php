<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\DriveAssetIngest;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Assets\Sources\GoogleDriveSource;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ربط ملفّات Google Drive بالمنتجات — سطح المشغّل الذي لم يكن (§١٣ §١٥).
 *
 * ─── ما يظهر وما لا يظهر ───
 *
 * لا يُعاد من هنا رمز وصولٍ ولا اعتماد خدمة ولا بنية مجلّدات: المشغّل يرى
 * **ما يخصّ ملفّه** — اسمَه ونوعه وحجمه ومراجعته وحالة مزامنته — ولا يرى
 * شيئًا عن الحساب الذي يقرأ به النظام. وهو الشرط الصريح في المواصفة: وصولٌ
 * آمن للزبون بلا كشف اعتمادات Drive الخاصّة.
 *
 * ─── ولماذا لا يُخزَّن رابط ───
 *
 * المدخل يقبل رابطًا تسهيلًا، ويُستخرَج منه المعرّف فورًا. الرابط لا يُحفظ:
 * روابط Drive تحمل معاملات مشاركةٍ تنتهي، فحفظُها يُنتج مصدرًا يبدو سليمًا
 * ولا يُفتح.
 */
class ProductDriveController extends Controller
{
    public function __construct(
        private readonly DriveAssetIngest $ingest,
        private readonly AdminAuditLogger $audit,
    ) {}

    /** هل هذه الصيغة تحت مجموعة هذا المنتج؟ الانتماء يُقطع به لا يُرجَّح. */
    private function ownsVariant(Product $product, int $variantId): bool
    {
        return ProductVariant::query()
            ->whereKey($variantId)
            ->whereIn('product_group_id', ProductGroup::query()
                ->where('legacy_product_id', $product->id)->select('id'))
            ->exists();
    }

    /**
     * صحّة محوّل Drive — لا صحّة ملفٍّ بعينه.
     *
     * يُقرأ قبل محاولة الربط: «لا اعتماد مضبوط» جوابٌ يفهمه المشغّل، أمّا
     * فشلُ ربطٍ بلا سببٍ فيجعله يجرّب معرّفاتٍ سليمة ويظنّها خاطئة.
     */
    public function health(GoogleDriveSource $drive): JsonResponse
    {
        $health = $drive->health();

        return response()->json(['data' => [
            'provider' => $health->provider,
            'configured' => $health->configured,
            'reachable' => $health->reachable,
            'detail' => $health->detail,
        ]]);
    }

    /** مصادر Drive المرتبطة بهذا المنتج. */
    public function index(int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);

        $sources = AssetSource::query()
            ->where('provider', DriveAssetIngest::PROVIDER)
            ->whereIn('asset_id', ProductFile::query()
                ->where('product_id', $product->id)->select('asset_id'))
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $sources->map(fn (AssetSource $source) => [
            'id' => $source->id,
            /* المعرّف يُعرض لأنّه هويّة الملفّ — وبه يبحث المشغّل في Drive. */
            'fileId' => $source->external_id,
            'fileName' => $source->file_name,
            'mimeType' => $source->mime_type,
            'sizeBytes' => $source->size_bytes,
            'revision' => $source->imported_revision,
            'syncState' => $source->sync_state,
            'lastError' => $source->last_error,
            'lastVerifiedAt' => $source->last_verified_at?->toIso8601String(),
            'deliverable' => $source->isDeliverable(),
            'servingFrozenCopy' => $source->isServingFrozenCopy(),
        ])->all()]);
    }

    public function store(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);

        $data = $request->validate([
            /*
             * معرّفٌ أو رابط. الرابط تسهيلٌ يُستخرج منه المعرّف ولا يُخزَّن —
             * الهويّة الدائمة هي المعرّف وحده (§١٣).
             */
            'fileId' => 'required|string|max:512',
            /*
             * الأدوار هي `ProductFile::ROLES` نفسها لا قائمةٌ مخترَعة هنا.
             * كانت `bonus` و`preview` مقبولتين ولا وجود لهما، و`companion`
             * و`teacher_guide` و`answer_key` مرفوضةً وهي قائمة — فتعرض
             * اللوحة خياراتٍ يردّها الخادم ٤٢٢ وتُخفي ما يقبله.
             */
            'role' => 'sometimes|string|in:main,companion,sample,teacher_guide,answer_key',
            /*
             * والصيغة تُفحص وجودًا وانتماءً: معرّفٌ لا وجود له يسقط على قيد
             * المفتاح الأجنبيّ فيعطي ٥٠٠ بلا اسم حقل (القاعدة ٣٨)، وصيغةُ
             * منتجٍ آخر تُربط بصمتٍ فيُسلَّم ملفٌّ لمن لم يشترِه.
             */
            'variantId' => 'sometimes|nullable|integer|exists:product_variants,id',
            'customerLabel' => 'sometimes|nullable|string|max:255',
        ], [
            'fileId.required' => 'أدخل معرّف ملفّ Drive أو رابطه.',
        ]);

        $variantId = isset($data['variantId']) ? (int) $data['variantId'] : null;

        if ($variantId !== null && ! $this->ownsVariant($product, $variantId)) {
            throw ValidationException::withMessages([
                'variantId' => ['هذه الصيغة ليست لهذا المنتج.'],
            ]);
        }

        $source = $this->ingest->attach(
            $product,
            $data['fileId'],
            $data['role'] ?? 'main',
            $variantId,
            $data['customerLabel'] ?? null,
        );

        $this->audit->log(
            $actor, 'product.drive.attached', 'product', $product->id, null,
            ['assetSourceId' => $source->id, 'fileId' => $source->external_id, 'revision' => $source->imported_revision],
            null, $request,
        );

        return response()->json(['data' => [
            'id' => $source->id,
            'fileId' => $source->external_id,
            'fileName' => $source->file_name,
            'revision' => $source->imported_revision,
            'syncState' => $source->sync_state,
        ]], 201);
    }

    /**
     * يستبدل ملفّ المصدر دون إعادة بناء المنتج.
     *
     * اللوحة كانت تطلب من المشغّل «استبدال المصدر» وهي لا تملك فعلًا يفعله:
     * تعليماتٌ بلا زرّ. والبديل الوحيد كان حذف المنتج وإعادة إنشائه، فتضيع
     * مراجعاتُه وروابطه وتاريخه — ثمنٌ باهظ لتبديل ملفّ.
     */
    public function replace(Request $request, AdminUser $actor, int $id, int $sourceId): JsonResponse
    {
        $product = Product::query()->findOrFail($id);

        /*
         * المصدر يُقيَّد بالمنتج في المسار.
         *
         * بلا التقييد يقبل `PATCH /products/7/drive/42` مصدرًا يخصّ المنتج
         * ٩٩: فيُستبدل ملفُّ منتجٍ آخر ويُربط أصلُه بالمنتج ٧، ويبقى ٩٩
         * مشيرًا إلى معرّفٍ لم يعد له. والتدقيق يُسجّل ذلك تحت المنتج ٧ فلا
         * يُفسَّر أبدًا. معرّفٌ في المسار لا يُقيَّد هو ثغرةُ مرجعٍ مباشر.
         */
        $source = AssetSource::query()
            ->where('provider', DriveAssetIngest::PROVIDER)
            ->whereKey($sourceId)
            ->whereIn('asset_id', ProductFile::query()
                ->where('product_id', $product->id)->select('asset_id'))
            ->firstOrFail();

        $data = $request->validate([
            'fileId' => 'required|string|max:512',
        ], ['fileId.required' => 'أدخل معرّف الملفّ البديل أو رابطه.']);

        /*
         * الدور لا يُرسَل في الاستبدال — يُقرأ من الربط القائم.
         *
         * قبولُه من العميل كان يجعل لوحةَ الإدارة تُرسل دورَ نموذج الإضافة
         * (وهو `main` افتراضًا) فيُعاد توصيفُ ملفٍّ كان «عيّنة» إلى «أساسيّ»
         * بصمت — فتصير عيّنةٌ مجّانيّة بضاعةً تُسلَّم، أو العكس. والاستبدال
         * يُبدّل الملفّ لا معناه.
         */
        $existingRole = ProductFile::query()
            ->where('product_id', $product->id)
            ->where('asset_id', $source->asset_id)
            ->value('role');

        $before = ['fileId' => $source->external_id, 'revision' => $source->imported_revision];
        $updated = $this->ingest->replace($source, $data['fileId'], $product, $existingRole ?? 'main');

        $this->audit->log(
            $actor, 'product.drive.replaced', 'product', $product->id, $before,
            ['fileId' => $updated->external_id, 'revision' => $updated->imported_revision],
            null, $request,
        );

        return response()->json(['data' => [
            'id' => $updated->id,
            'fileId' => $updated->external_id,
            'fileName' => $updated->file_name,
            'revision' => $updated->imported_revision,
            'syncState' => $updated->sync_state,
        ]]);
    }
}
