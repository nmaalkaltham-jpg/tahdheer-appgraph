<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * فاحص وجهات الروابط — مع حماية SSRF.
 *
 * الخطر الذي يبرّر كل سطر هنا: نقطة نهاية تجلب أي عنوان يرسله المستخدم هي
 * ثغرة تزوير طلب من الخادم. الخادم داخل شبكة Docker يرى `postgres` و`valkey`
 * و`meilisearch` و`garage` وبيانات اعتماد السحابة على 169.254.169.254. رابط
 * قائمة يشير إلى أيّ منها يحوّل «افحص الرابط» إلى قارئ للشبكة الداخلية.
 *
 * الدفاع أربع طبقات:
 *  ١· المخطَّط: http/https فقط.
 *  ٢· المنفذ: 80/443 فقط.
 *  ٣· عنوان الوجهة يُحلّ **قبل** الطلب، وكل عنوان خاص أو محلّي أو مرتبط
 *     بالوصلة يُرفض. الحلّ مرّة والطلب مرّة يترك نافذة إعادة ربط DNS، وهي
 *     مقبولة هنا لأن النتيجة تُعرض لمشرف مصادَق ولا تُخزَّن ولا تُنفَّذ.
 *  ٤· التحويلات لا تُتبَع تلقائيًّا: تُتتبَّع خطوةً خطوة وكل قفزة تُفحص من
 *     جديد، فلا يقفز 302 من عنوان عام إلى عنوان داخلي.
 */
final class LinkChecker
{
    private const MAX_HOPS = 5;

    private const TIMEOUT = 8;

    public const BLOCKED = 'BLOCKED';

    public const UNREACHABLE = 'UNREACHABLE';

    public const OK = 'OK';

    /**
     * @return array{
     *   url: string, status: string, httpStatus: int|null,
     *   chain: list<array{url: string, status: int}>, hops: int,
     *   finalUrl: string|null, canonical: string|null, robots: string|null,
     *   indexable: bool|null, reason: string|null
     * }
     */
    public function check(string $url, string $siteOrigin): array
    {
        // النسبي يُقاس على أصل المتجر: هو ما سيراه الزائر فعلًا.
        $absolute = str_starts_with($url, '/') ? rtrim($siteOrigin, '/').$url : $url;

        $chain = [];
        $current = $absolute;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $denial = $this->denyReason($current);
            if ($denial !== null) {
                return $this->result($url, self::BLOCKED, null, $chain, null, $denial);
            }

            try {
                $response = Http::withoutRedirecting()
                    ->timeout(self::TIMEOUT)
                    ->withHeaders(['user-agent' => 'TahdheerLinkCheck/1.0'])
                    ->get($current);
            } catch (ConnectionException $e) {
                return $this->result($url, self::UNREACHABLE, null, $chain, null, $e->getMessage());
            }

            $status = $response->status();
            $chain[] = ['url' => $current, 'status' => $status];

            $location = $response->header('location');
            if ($status >= 300 && $status < 400 && $location !== '') {
                // النسبي في ترويسة التحويل يُحلّ على الحالي لا على أصل المتجر.
                $current = $this->resolve($current, $location);

                continue;
            }

            $body = $status < 400 ? $response->body() : '';

            return $this->result(
                $url,
                $status >= 200 && $status < 400 ? self::OK : self::UNREACHABLE,
                $status,
                $chain,
                [
                    'finalUrl' => $current,
                    'canonical' => $this->canonical($body),
                    'robots' => $this->robots($body),
                ],
                null,
            );
        }

        return $this->result($url, self::UNREACHABLE, null, $chain, null, 'تجاوز عدد التحويلات المسموح.');
    }

    /** سبب المنع، أو null حين يكون العنوان صالحًا للجلب. */
    private function denyReason(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return 'عنوان غير صالح.';
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return "مخطَّط غير مسموح: {$scheme}.";
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            return "منفذ غير مسموح: {$port}.";
        }

        $host = $parts['host'];
        $addresses = $this->resolveHost($host);
        if ($addresses === []) {
            return "تعذّر حلّ المضيف: {$host}.";
        }

        foreach ($addresses as $ip) {
            if (! $this->isPublic($ip)) {
                // لا يُذكر العنوان المحلّي في الرسالة: لا نؤكّد ما خلف الجدار.
                return 'الوجهة تشير إلى عنوان داخلي أو محجوز.';
            }
        }

        return null;
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $out = [];
        foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $key => $type) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $out[] = $ip;
                }
            }
        }

        return $out;
    }

    private function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        return str_starts_with($location, '/')
            ? $origin.$location
            : $origin.'/'.ltrim($location, '/');
    }

    private function canonical(string $html): ?string
    {
        return preg_match(
            '#<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']#i',
            $html,
            $m,
        ) === 1 ? $m[1] : null;
    }

    private function robots(string $html): ?string
    {
        return preg_match(
            '#<meta[^>]+name=["\']robots["\'][^>]*content=["\']([^"\']+)["\']#i',
            $html,
            $m,
        ) === 1 ? $m[1] : null;
    }

    /**
     * @param  list<array{url: string, status: int}>  $chain
     * @param  array{finalUrl: string, canonical: string|null, robots: string|null}|null  $page
     * @return array<string, mixed>
     */
    private function result(
        string $url,
        string $status,
        ?int $httpStatus,
        array $chain,
        ?array $page,
        ?string $reason,
    ): array {
        $robots = $page['robots'] ?? null;

        return [
            'url' => $url,
            'status' => $status,
            'httpStatus' => $httpStatus,
            'chain' => $chain,
            // القفزات = طول السلسلة ناقص الطلب الأوّل.
            'hops' => max(0, count($chain) - 1),
            'finalUrl' => $page['finalUrl'] ?? null,
            'canonical' => $page['canonical'] ?? null,
            'robots' => $robots,
            // بلا صفحة مقروءة لا حكم على الفهرسة — null لا «مفهرَس».
            'indexable' => $robots === null
                ? ($page === null ? null : true)
                : ! str_contains(strtolower($robots), 'noindex'),
            'reason' => $reason,
        ];
    }
}
