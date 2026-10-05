<?php

namespace App\Domain\UrlPlatform;

/**
 * الأسماء المحجوزة — مقطعٌ أوّل لا يجوز لكيانٍ عامّ أن يحمله.
 *
 * ميزةٌ مقبلة تنشئ `/account` لا تكتشف تصادمها مع منتجٍ رابطُه `account`
 * إلّا بعد النشر. القائمة هنا مصدرٌ واحد يقرؤه محرّك الروابط قبل الحجز،
 * ويقرؤه المدقّق عند الفحص.
 */
final class ReservedRoutes
{
    /** @var list<string> */
    public const NAMES = [
        'admin', 'api', '_next', 'login', 'logout', 'account', 'cart', 'checkout',
        'search', 'robots.txt', 'sitemap.xml', 'favicon.ico', 'health', 'webhooks',
        'internal', 'llms.txt', 'theme.css', 'studio-preview', 'orders', 'preview',
        'static', 'assets', 'media', 'auth', 'graphql', 'rss', 'feed',
    ];

    public static function isReserved(string $segment): bool
    {
        return in_array(mb_strtolower(trim($segment, '/')), self::NAMES, true);
    }

    /** المقطع الأوّل من مسارٍ — أو الفراغ للجذر. */
    public static function firstSegment(string $path): string
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return '';
        }

        return explode('/', $trimmed, 2)[0];
    }
}
