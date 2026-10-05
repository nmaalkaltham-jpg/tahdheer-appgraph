<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\I18n\HreflangAuditor;
use App\Domain\I18n\Locale;
use App\Domain\I18n\ProductTranslation;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * سجلّ لغات المتجر: نظرة عامّة، وتدقيق hreflang، وتعديل واحد صادق.
 *
 * الكتابة الوحيدة هنا هي التفعيل والترتيب. `is_routable` و`is_default`
 * حقيقتا خادم (Locale): إعلان مسار لا تخدمه الواجهة يُدخل روابط 404 إلى
 * الفهرس، فإرسالهما يُرفض 422 ولا يُتجاهل بصمت.
 */
final class I18nController
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly HreflangAuditor $auditor,
    ) {}

    /** اللغات كما هي، مع أعداد محسوبة من الجداول لا مُقدَّرة. */
    public function overview(): JsonResponse
    {
        $publishedProducts = Product::query()->published()->count();

        // ترجمات كل لغة بحالتها — استعلام تجميعي واحد لكل الجدول.
        $translationCounts = ProductTranslation::query()
            ->selectRaw('locale_code, status, count(*) as c')
            ->groupBy('locale_code', 'status')
            ->get()
            ->groupBy('locale_code');

        return response()->json(['data' => [
            'publishedProducts' => $publishedProducts,
            'locales' => Locale::ordered()->map(function (Locale $locale) use ($publishedProducts, $translationCounts): array {
                $byStatus = $translationCounts->get($locale->code)?->pluck('c', 'status') ?? collect();

                return $this->localeRow($locale) + [
                    // الافتراضية ليست ترجمة: نصّها أعمدة المنتج نفسها.
                    'translations' => $locale->is_default ? null : [
                        'draft' => (int) $byStatus->get('draft', 0),
                        'published' => (int) $byStatus->get('published', 0),
                    ],
                    /*
                     * expected: صفحات تجتاز بوّابة هذه اللغة الآن. قاعدة
                     * «أقل من نظيرين ⇒ لا إعلان» تُطبَّق عند الإصدار
                     * ويُثبتها /i18n/hreflang-audit لكل منتج — العدّ هنا
                     * بوّابة اللغة وحدها، بلا ادّعاء إعلانٍ فعلي.
                     */
                    'alternateUrls' => [
                        'expected' => $this->gatePassing($locale, $publishedProducts),
                        'potentialMax' => $publishedProducts,
                    ],
                ];
            })->values()->all(),
        ]]);
    }

    /** ثوابت hreflang مُثبَتة منتجًا منتجًا — انظر HreflangAuditor. */
    public function hreflangAudit(): JsonResponse
    {
        return response()->json(['data' => $this->auditor->run()]);
    }

    public function updateLocale(Request $request, string $code): JsonResponse
    {
        $locale = Locale::query()->findOrFail($code);

        $validated = $request->validate([
            'isActive' => 'required_without:position|boolean',
            'position' => 'required_without:isActive|integer|min:0|max:1000',
            // حقيقتا خادم — تُرفضان صراحةً لا تُتجاهلان بصمت.
            'isRoutable' => 'prohibited',
            'isDefault' => 'prohibited',
        ], AdminValidation::MESSAGES + [
            'prohibited' => 'هذا الحقل حقيقة خادم ولا يُعدَّل من اللوحة.',
        ]);

        if ($locale->is_default && array_key_exists('isActive', $validated) && ! $validated['isActive']) {
            // تعطيل الافتراضية يُطفئ لغة المتجر الوحيدة المضمونة.
            return response()->json([
                'message' => 'اللغة الافتراضية لا تُعطَّل — هي لغة المتجر الأصلية.',
                'code' => 'DEFAULT_LOCALE_ALWAYS_ACTIVE',
            ], 422);
        }

        $before = $locale->only(['is_active', 'position']);

        if (array_key_exists('isActive', $validated)) {
            $locale->is_active = (bool) $validated['isActive'];
        }
        if (array_key_exists('position', $validated)) {
            $locale->position = (int) $validated['position'];
        }
        $locale->save();

        $this->audit->log(
            $this->actor(), 'i18n.locale.updated', 'locale', $locale->code,
            $before, $locale->only(['is_active', 'position']), null, $request,
        );

        return response()->json(['data' => $this->localeRow($locale)]);
    }

    /** @return array<string, mixed> */
    private function localeRow(Locale $locale): array
    {
        return [
            'code' => $locale->code,
            'nameAr' => $locale->name_ar,
            'nameEn' => $locale->name_en,
            'hreflang' => $locale->hreflang,
            'direction' => $locale->direction,
            'urlPrefix' => $locale->url_prefix,
            'isDefault' => $locale->is_default,
            'isActive' => $locale->is_active,
            'isRoutable' => $locale->is_routable,
            'position' => $locale->position,
        ];
    }

    /**
     * كم منتجًا منشورًا يجتاز بوّابة هذه اللغة الآن — عدٌّ من الجداول
     * بنفس شروط LanguageEligibility::emitsHreflang، لا تقدير.
     */
    private function gatePassing(Locale $locale, int $publishedProducts): int
    {
        if (! $locale->is_active || ! $locale->is_routable) {
            return 0;
        }

        if ($locale->is_default) {
            return Product::query()->published()
                ->whereNotNull('slug')->where('slug', '!=', '')->count();
        }

        return ProductTranslation::query()
            ->where('locale_code', $locale->code)
            ->where('status', 'published')
            ->where('is_indexable', true)
            ->whereNotNull('slug')->where('slug', '!=', '')
            ->join('products', 'products.id', '=', 'product_translations.product_id')
            ->where('products.status', 'published')
            ->count();
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
