<?php

namespace App\Domain\UrlPlatform;

/**
 * أمان الوجهات — يُفحص كلّ هدف تحويلٍ قبل أن يُحفظ.
 *
 * التحويل المفتوح يجعل المتجر بوّابةَ تصيّد: `/go?to=https://evil` يحمل
 * اسم النطاق وثقته إلى مكانٍ لا يملكه. والاجتياز والشرطة المرمَّزة ومحارف
 * التحكّم أساليبُ الهروب من الفحص السطحيّ نفسها. الخارجيّ لا يُقبل إلّا من
 * قائمة سماحٍ صريحة.
 */
final class RedirectSafety
{
    /**
     * يعيد الوجهة مُطبَّعة أو يرمي `UNSAFE_TARGET`.
     *
     * @param  list<string>  $allowedHosts
     */
    public static function assertSafe(string $target, array $allowedHosts = []): string
    {
        $target = trim($target);

        if ($target === '') {
            throw UrlPlatformError::unsafeTarget('وجهةٌ فارغة');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $target) === 1) {
            throw UrlPlatformError::unsafeTarget('محارف تحكّم');
        }
        if (! mb_check_encoding($target, 'UTF-8')) {
            throw UrlPlatformError::unsafeTarget('ترميز UTF-8 فاسد');
        }
        // `//host` و`\\host` و`/\host` كلّها تُقرأ عنوانًا مطلقًا عند المتصفّح.
        if (preg_match('#^[/\\\\]{2}#', $target) === 1 || preg_match('#^/\\\\#', $target) === 1) {
            throw UrlPlatformError::unsafeTarget('مسارٌ يبدأ بشرطتين — عنوانٌ مطلق متنكّر');
        }
        if (preg_match('/%2f|%5c|%00/i', $target) === 1) {
            throw UrlPlatformError::unsafeTarget('شرطةٌ أو صفرٌ مرمَّز');
        }
        if (preg_match('#(^|/)\.\.(/|$)#', rawurldecode($target)) === 1) {
            throw UrlPlatformError::unsafeTarget('اجتياز مسار');
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $target, $m) === 1) {
            $scheme = strtolower($m[1]);
            if ($scheme !== 'https' && $scheme !== 'http') {
                throw UrlPlatformError::unsafeTarget("مخطّط «{$scheme}» غير مسموح");
            }
            $host = strtolower((string) parse_url($target, PHP_URL_HOST));
            if ($host === '' || preg_match('/[@\s]/', $target) === 1) {
                throw UrlPlatformError::unsafeTarget('مضيفٌ غير صالح');
            }
            $allowed = array_map(fn ($h) => strtolower(trim((string) $h)), $allowedHosts);
            if (! in_array($host, $allowed, true)) {
                throw UrlPlatformError::unsafeTarget("المضيف «{$host}» خارج قائمة السماح");
            }

            return $target;
        }

        if (! str_starts_with($target, '/')) {
            throw UrlPlatformError::unsafeTarget('الوجهة الداخليّة تبدأ بشرطة');
        }

        return PathPolicy::normalize($target).self::queryOf($target);
    }

    private static function queryOf(string $target): string
    {
        $pos = strpos($target, '?');
        if ($pos === false) {
            return '';
        }
        parse_str(substr($target, $pos + 1), $query);
        $kept = PathPolicy::normalizeQuery($query);

        return $kept === [] ? '' : '?'.http_build_query($kept, '', '&', PHP_QUERY_RFC3986);
    }
}
