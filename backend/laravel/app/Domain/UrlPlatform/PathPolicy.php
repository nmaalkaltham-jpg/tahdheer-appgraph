<?php

namespace App\Domain\UrlPlatform;

/**
 * سياسة المسار — صورةٌ واحدة لكلّ عنوان.
 *
 * كلّ صيغةٍ غير قانونيّة تتقارب إلى واحدة: شرطاتٌ مكرّرة، شرطةٌ لاحقة،
 * ترميزٌ نسبيّ لمحرفٍ لا يحتاجه، حالةُ أحرفٍ لاتينيّة، معاملاتُ تتبّعٍ لا
 * تغيّر المورد. العربيّة تبقى عربيّةً مفكوكةً لا `%D8%AA` — المستودع كلّه
 * يقارن المسارات مفكوكة.
 */
final class PathPolicy
{
    /** @var list<string> */
    public const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'gclid', 'fbclid', 'msclkid', 'ttclid', 'dclid', 'mc_cid', 'mc_eid', '_ga', '_gl', 'ref',
    ];

    /** تصنيف المعاملات (§19) — ما لا يُعرف يُعدّ داخليًّا ويُسقط من الهويّة. */
    public const PARAM_CLASSES = [
        'functional' => ['q', 'page_key', 'token'],
        'filter' => ['stage', 'grade', 'subject', 'semester', 'kind', 'price_min', 'price_max', 'format'],
        'sort' => ['sort', 'order'],
        'pagination' => ['page', 'per_page'],
        'tracking' => self::TRACKING_PARAMS,
        'experiment' => ['exp', 'variant', 'ab'],
        'internal' => ['t', 'preview', 'bare'],
        'forbidden' => ['__proto__', 'constructor', 'redirect', 'next', 'return_to'],
    ];

    public static function classify(string $name): string
    {
        $lower = mb_strtolower($name);
        foreach (self::PARAM_CLASSES as $class => $names) {
            if (in_array($lower, $names, true)) {
                return $class;
            }
        }
        if (str_starts_with($lower, 'utm_')) {
            return 'tracking';
        }

        return 'internal';
    }

    /**
     * المسار المُطبَّع — بلا استعلامٍ ولا شظيّة.
     *
     * يرمي `UrlPlatformError::invalidPath` على ما لا يُطبَّع: محارف تحكّم،
     * ترميز UTF-8 فاسد، مقاطع `..` — فهذه ليست «صيغةً أخرى» بل هجومٌ أو عطل.
     */
    public static function normalize(string $raw): string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw UrlPlatformError::invalidPath('ترميز UTF-8 فاسد');
        }
        $path = trim($raw);
        $path = preg_replace('/[#?].*$/u', '', $path) ?? '';

        // رابطٌ مطلق لهذا المضيف يُقبل مساره؛ مضيفٌ غريب يُرفض في طبقة التحويل.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            $parsed = parse_url($path);
            $path = (string) ($parsed['path'] ?? '/');
        }

        $decoded = rawurldecode($path);
        if (! mb_check_encoding($decoded, 'UTF-8')) {
            throw UrlPlatformError::invalidPath('ترميز UTF-8 فاسد');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $decoded) === 1) {
            throw UrlPlatformError::invalidPath('محارف تحكّم في المسار');
        }
        // شرطةٌ مرمَّزة `%2F` تعني مقطعًا مختبئًا — تُرفض لا تُفكّ.
        if (preg_match('/%2f/i', $path) === 1) {
            throw UrlPlatformError::invalidPath('شرطةٌ مرمَّزة في المسار');
        }

        $decoded = preg_replace('#/{2,}#', '/', $decoded) ?? '/';
        $segments = [];
        foreach (explode('/', trim($decoded, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw UrlPlatformError::invalidPath('اجتياز مسارٍ في العنوان');
            }
            $segments[] = self::foldSegment($segment);
        }

        return '/'.implode('/', $segments);
    }

    /** الحروف اللاتينيّة تُطوى صغيرةً؛ العربيّة وغيرها تبقى كما هي. */
    public static function foldSegment(string $segment): string
    {
        return preg_replace_callback('/[A-Z]+/', fn ($m) => strtolower($m[0]), $segment) ?? $segment;
    }

    /**
     * الاستعلام المُطبَّع: بلا تتبّعٍ ولا داخليّ ولا محظور، مرتّبًا بالاسم.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function normalizeQuery(array $query): array
    {
        $kept = [];
        foreach ($query as $name => $value) {
            $class = self::classify((string) $name);
            if (in_array($class, ['tracking', 'internal', 'forbidden', 'experiment'], true)) {
                continue;
            }
            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                continue;
            }
            // الافتراضات لا تُكتب: الصفحة الأولى هي الغياب.
            if ($class === 'pagination' && (string) $value === '1') {
                continue;
            }
            $kept[(string) $name] = is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;
        }
        ksort($kept, SORT_STRING);

        return $kept;
    }

    /** المسار مع استعلامه القانونيّ — الهويّة التي تُقارَن بها الروابط. */
    public static function canonicalPath(string $rawPath, array $query = []): string
    {
        $path = self::normalize($rawPath);
        $kept = self::normalizeQuery($query);

        return $kept === [] ? $path : $path.'?'.http_build_query($kept, '', '&', PHP_QUERY_RFC3986);
    }
}
