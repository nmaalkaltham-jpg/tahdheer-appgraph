<?php

declare(strict_types=1);

namespace App\Domain\I18n;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * أهلية المنتج لكل لغة — قائمة متطلّبات صريحة، لا شارة «جاهز» بلا سبب.
 *
 * كل متطلّب يحمل: هل تحقّق، وهل يحجب النشر، ولماذا. المشرف الذي يرى «غير
 * مؤهّل» يجب أن يرى في السطر نفسه ما ينقصه، وإلّا فالشارة تحكم ولا تُفسِّر.
 *
 * لا اختراع: النصوص تُقرأ من الجدول، والحالة تُقرأ من الأعمدة، ولا يُقدَّر
 * شيء. لغة بلا ترجمة تُقال «بلا ترجمة» لا «مكتملة تقريبًا».
 */
final class LanguageEligibility
{
    /**
     * سجلّ اللغات يُقرأ مرّة لكل طلب: صفحة قائمة تستدعي forProduct لكل صفّ،
     * فاستعلامٌ لكل صفّ يحوّل جدولًا من سطرين إلى N+1.
     *
     * @var Collection<int, Locale>|null
     */
    private ?Collection $locales = null;

    /** منشورة فعلًا بهذه اللغة. */
    public const PUBLISHED = 'published';

    /** كل الشروط تحقّقت والنشر ممكن الآن. */
    public const READY = 'ready';

    /** ينقص محتوى يكتبه المحرّر. */
    public const INCOMPLETE = 'incomplete';

    /** يحجبه شرط خارج يد المحرّر (لغة معطّلة أو غير موصولة أو مصدر غير منشور). */
    public const BLOCKED = 'blocked';

    /**
     * حالة كل لغات المتجر لهذا المنتج.
     *
     * @return list<array<string, mixed>>
     */
    public function forProduct(Product $product): array
    {
        $product->loadMissing('translations');

        return $this->locales()
            ->map(fn (Locale $locale): array => $this->forLocale($product, $locale))
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   locale: array<string, mixed>, status: string, translationExists: bool,
     *   canPublish: bool, hreflangEmitted: bool,
     *   requirements: list<array<string, mixed>>, missing: list<string>
     * }
     */
    public function forLocale(Product $product, Locale $locale): array
    {
        $translation = $locale->is_default
            ? null
            : $product->translations->firstWhere('locale_code', $locale->code);

        $requirements = $locale->is_default
            ? $this->defaultLocaleRequirements($product)
            : $this->translatedLocaleRequirements($product, $locale, $translation);

        $unmet = array_values(array_filter(
            $requirements,
            static fn (array $r): bool => ! $r['satisfied'],
        ));
        $blocking = array_values(array_filter($unmet, static fn (array $r): bool => $r['blocking']));

        $isPublished = $locale->is_default
            ? $product->status === 'published'
            : ($translation?->isPublished() ?? false);

        $externallyBlocked = array_values(array_filter(
            $blocking,
            static fn (array $r): bool => $r['scope'] === 'store',
        ));

        $status = match (true) {
            $externallyBlocked !== [] => self::BLOCKED,
            $blocking !== [] => self::INCOMPLETE,
            $isPublished => self::PUBLISHED,
            default => self::READY,
        };

        return [
            'locale' => [
                'code' => $locale->code,
                'nameAr' => $locale->name_ar,
                'hreflang' => $locale->hreflang,
                'direction' => $locale->direction,
                'isDefault' => $locale->is_default,
                'isActive' => $locale->is_active,
                'isRoutable' => $locale->is_routable,
            ],
            'status' => $status,
            'translationExists' => $locale->is_default || $translation !== null,
            'canPublish' => $blocking === [],
            /*
             * ما يُعلن للعالم فعلًا — لا ما يستوفي شروطه.
             * لغة مستوفية وحدها لا تُعلَن: hreflang بلا نظير لا يُكتب أصلًا،
             * فادّعاء «مُعلَنة» هنا يناقض ما يخرج في الصفحة.
             */
            'hreflangEmitted' => $this->emitsHreflang($product, $locale, $translation)
                && $this->alternatesFor($product) !== [],
            'requirements' => $requirements,
            'missing' => array_column($unmet, 'key'),
        ];
    }

    /**
     * روابط hreflang الفعلية لصفحة المنتج.
     *
     * تُعلَن اللغة فقط إذا كانت الصفحة موجودة ومنشورة وقابلة للفهرسة
     * وواجهتها تخدم المسار. أي شرط أقلّ يُعلن للعالم روابط تُعيد 404 أو
     * صفحات فارغة، وهو ضرر مباشر لا تحسين.
     *
     * @return list<array{hreflang: string, path: string, isDefault: bool}>
     */
    public function alternatesFor(Product $product): array
    {
        $product->loadMissing('translations');

        $out = [];
        foreach ($this->locales() as $locale) {
            $translation = $locale->is_default
                ? null
                : $product->translations->firstWhere('locale_code', $locale->code);

            if (! $this->emitsHreflang($product, $locale, $translation)) {
                continue;
            }

            $slug = $locale->is_default ? $product->slug : (string) $translation->slug;

            $out[] = [
                'hreflang' => $locale->hreflang,
                'path' => $locale->path('/products/'.$slug),
                'isDefault' => $locale->is_default,
            ];
        }

        // بديل واحد فقط (اللغة الحالية) ليس بديلًا: hreflang بلا نظير لا معنى له.
        return count($out) < 2 ? [] : $out;
    }

    /** @return Collection<int, Locale> */
    private function locales(): Collection
    {
        return $this->locales ??= Locale::ordered();
    }

    private function emitsHreflang(Product $product, Locale $locale, ?ProductTranslation $translation): bool
    {
        if (! $locale->is_active || ! $locale->is_routable || $product->status !== 'published') {
            return false;
        }

        if ($locale->is_default) {
            return $product->slug !== null && $product->slug !== '';
        }

        return $translation !== null
            && $translation->isPublished()
            && $translation->is_indexable
            && $translation->slug !== null
            && $translation->slug !== '';
    }

    /**
     * اللغة الافتراضية تُقاس على أعمدة المنتج نفسه — لا صفّ ترجمة لها.
     *
     * @return list<array<string, mixed>>
     */
    private function defaultLocaleRequirements(Product $product): array
    {
        return [
            $this->req('title', 'العنوان', $this->filled($product->title), true, 'content'),
            $this->req('slug', 'الرابط', $this->filled($product->slug), true, 'content'),
            $this->req('short_desc', 'الوصف المختصر', $this->filled($product->short_desc), false, 'content'),
            $this->req('description', 'الوصف الكامل', $this->filled($product->description), false, 'content'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function translatedLocaleRequirements(
        Product $product,
        Locale $locale,
        ?ProductTranslation $translation,
    ): array {
        return [
            $this->req(
                'locale_active', 'اللغة مفعّلة في المتجر', $locale->is_active, true, 'store',
                $locale->is_active ? null : 'اللغة مسجَّلة وغير مفعّلة. فعّلها من إعدادات اللغات.',
            ),
            $this->req(
                'locale_routable', 'الواجهة تخدم مسار اللغة', $locale->is_routable, true, 'store',
                $locale->is_routable ? null : 'لا توجد مسارات واجهة لهذه اللغة بعد؛ نشرها يُعلن صفحات غير موجودة.',
            ),
            $this->req(
                'source_published', 'المنتج الأصل منشور', $product->status === 'published', true, 'store',
                $product->status === 'published' ? null : 'ترجمة منتج غير منشور لا تظهر لأحد.',
            ),
            $this->req(
                'translation_exists', 'الترجمة موجودة', $translation !== null, true, 'content',
                $translation !== null ? null : 'لا ترجمة محفوظة لهذه اللغة.',
            ),
            $this->req('title', 'العنوان المترجَم', $this->filled($translation?->title), true, 'content'),
            $this->req('slug', 'الرابط المترجَم', $this->filled($translation?->slug), true, 'content'),
            $this->req('short_desc', 'الوصف المختصر المترجَم', $this->filled($translation?->short_desc), false, 'content'),
            $this->req('description', 'الوصف الكامل المترجَم', $this->filled($translation?->description), false, 'content'),
        ];
    }

    /** @return array<string, mixed> */
    private function req(
        string $key,
        string $label,
        bool $satisfied,
        bool $blocking,
        string $scope,
        ?string $detail = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'satisfied' => $satisfied,
            'blocking' => $blocking,
            // store: خارج يد المحرّر. content: يُصلَح بالكتابة.
            'scope' => $scope,
            'detail' => $detail,
        ];
    }

    private function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
