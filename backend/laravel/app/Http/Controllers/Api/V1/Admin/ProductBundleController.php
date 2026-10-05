<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Catalog\BundleComposition;
use App\Domain\Catalog\Models\BundleComponent;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductGroupAuthoring;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * مكوّنات الحزمة — سطح المشغّل (§٤٣).
 *
 * الحزمة ما يراه الزبون منتجًا واحدًا بثمنٍ واحد، وما يُسلَّم مكوّناتُه كلٌّ
 * بطريقته. وثمنُها ثمنُ المنتج نفسه لا مجموع المكوّنات: تُباع بخصمٍ عادةً،
 * والمجموع كان سيُحصّل أكثر ممّا عُرض. فالسعر يُحرَّر من «السعر» كأيّ منتج،
 * ويُوزَّع على المكوّنات عند الدفع.
 */
class ProductBundleController extends Controller
{
    public function __construct(
        private readonly BundleComposition $bundles,
        private readonly ProductGroupAuthoring $groups,
        private readonly AdminAuditLogger $audit,
    ) {}

    public function index(int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $group = $this->groups->groupFor($product);

        if ($group === null) {
            return response()->json(['data' => [], 'isBundle' => false]);
        }

        $rows = $this->bundles->componentsOf($group)->map(function (BundleComponent $c) {
            $variant = ProductVariant::query()->with('group')->find($c->component_variant_id);

            return [
                'id' => $c->id,
                'variantId' => $c->component_variant_id,
                'sku' => $variant?->sku,
                /* عنوان الصيغة فارغٌ يعني «خذ عنوان المجموعة» — لا «بلا عنوان». */
                'title' => $variant?->title ?? $variant?->group?->title,
                'nature' => $variant?->group?->product_nature,
                'quantity' => (int) $c->quantity,
                'required' => (bool) $c->required,
                'position' => (int) $c->position,
            ];
        })->all();

        return response()->json([
            'data' => $rows,
            'isBundle' => $group->product_nature === 'COMPOSITE',
        ]);
    }

    public function store(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $group = $this->groups->groupFor($product);

        if ($group === null) {
            throw ValidationException::withMessages([
                'product' => ['هذا المنتج بلا مجموعة — اختر له نوعًا أوّلًا.'],
            ]);
        }

        $data = $request->validate([
            /*
             * الوجود يُفحص في القاعدة (`exists`) والانتماء في النطاق: الأوّل
             * يمنع ٥٠٠ من المفتاح الأجنبيّ، والثاني يمنع حزمةً تحتوي نفسها —
             * وهو شرطٌ يمسّ جدولين فلا يُفرض بـ`CHECK`.
             */
            'componentVariantId' => 'required|integer|exists:product_variants,id',
            'quantity' => 'sometimes|integer|min:1|max:99',
            'required' => 'sometimes|boolean',
        ], [
            'componentVariantId.required' => 'اختر الصيغة التي تُضاف إلى الحزمة.',
            'componentVariantId.exists' => 'لا صيغة بهذا المعرّف.',
        ]);

        $component = $this->bundles->add(
            $group,
            (int) $data['componentVariantId'],
            (int) ($data['quantity'] ?? 1),
            (bool) ($data['required'] ?? true),
        );

        $this->audit->log(
            $actor, 'product.bundle.component.added', 'product', $product->id, null,
            ['componentId' => $component->id, 'variantId' => $component->component_variant_id,
                'quantity' => $component->quantity],
            null, $request,
        );

        return $this->index($id)->setStatusCode(201);
    }

    public function destroy(Request $request, AdminUser $actor, int $id, int $componentId): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $group = $this->groups->groupFor($product);

        if ($group === null) {
            return response()->json(['message' => 'هذا المنتج بلا مجموعة.'], 404);
        }

        $this->bundles->remove($group, $componentId);

        $this->audit->log(
            $actor, 'product.bundle.component.removed', 'product', $product->id,
            ['componentId' => $componentId], null, null, $request,
        );

        return $this->index($id);
    }
}
