<?php

namespace App\Domain\UrlPlatform;

use App\Domain\SEO\TaxonomyPath;

/**
 * بُناة المسارات — المكان الوحيد الذي يعرف شكل العنوان العامّ لكلّ نوع.
 *
 * المرآة في المتجر: `apps/storefront/lib/routes.ts` و`termPath()`. تغييرُ
 * شكلٍ هنا بلا مرآته يجعل خريطة الموقع تعلن ٤٠٤ — ولذلك يفحص
 * `RouteBuildersTest` العيّنات نفسها التي يفحصها اختبار المتجر.
 */
final class RouteBuilders
{
    /** @var array<string, string> نوع الكيان → المالك (مجموعة المسارات) */
    public const OWNERS = [
        'product' => 'products',
        'bundle' => 'bundles',
        'page' => 'pages',
        'stage' => 'stages',
        'grade' => 'grades',
        'subject' => 'subjects',
        'semester' => 'semesters',
        'kind' => 'kinds',
    ];

    public static function supports(string $entityType): bool
    {
        return isset(self::OWNERS[$entityType]);
    }

    public static function owner(string $entityType): string
    {
        return self::OWNERS[$entityType] ?? throw UrlPlatformError::invalidSlug("نوع كيانٍ بلا مسار: {$entityType}");
    }

    /** المسار العامّ لكيانٍ برابطه — بلا بادئة لغة: اللغة الافتراضيّة بلا بادئة. */
    public static function path(string $entityType, string $slug, string $locale = 'ar'): string
    {
        $base = match ($entityType) {
            'product' => '/products/'.$slug,
            'bundle' => '/bundles/'.$slug,
            'page' => '/pages/'.$slug,
            'stage', 'grade', 'subject', 'semester', 'kind' => TaxonomyPath::join('/'.self::OWNERS[$entityType], $slug),
            default => throw UrlPlatformError::invalidSlug("نوع كيانٍ بلا مسار: {$entityType}"),
        };

        return self::localePrefix($locale).$base;
    }

    /**
     * المقطع كما يظهر في العنوان: مصطلح التصنيف يحمل فراغًا في `slug` ويُكتب
     * بشرطةٍ في المسار (`TaxonomyPath`)، فالتحقّق والتفرّد يقعان على المقطع.
     */
    public static function segment(string $entityType, string $slug): string
    {
        return match ($entityType) {
            'stage', 'grade', 'subject', 'semester', 'kind' => TaxonomyPath::segment($slug),
            default => $slug,
        };
    }

    public static function product(string $slug, string $locale = 'ar'): string
    {
        return self::path('product', $slug, $locale);
    }

    public static function page(string $slug, string $locale = 'ar'): string
    {
        return self::path('page', $slug, $locale);
    }

    /** بادئة اللغة من جدول اللغات — الافتراضيّة بلا بادئة، وغيرها `/{prefix}`. */
    public static function localePrefix(string $locale): string
    {
        if ($locale === 'ar' || $locale === '') {
            return '';
        }

        return '/'.trim($locale, '/');
    }
}
