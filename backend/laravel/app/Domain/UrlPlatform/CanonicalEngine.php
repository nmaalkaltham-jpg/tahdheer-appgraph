<?php

namespace App\Domain\UrlPlatform;

use App\Domain\Redirects\NotFoundHit;
use App\Domain\Redirects\RedirectRule;
use App\Domain\UrlPlatform\Models\UrlRegistryEntry;

/**
 * محرّك القانونيّ — يبني العنوان المرجعيّ ويفحصه.
 *
 * المضيف من `seo.site_url` وحده؛ حين يغيب يبقى القانونيّ مسارًا نسبيًّا لا
 * مضيفًا مخمَّنًا — المتجر يفعل الشيء نفسه في `canonicalUrl()`.
 */
final class CanonicalEngine
{
    public function build(string $path): string
    {
        $origin = rtrim((string) config('seo.site_url', ''), '/');
        if ($origin === '') {
            return $path;
        }
        if (preg_match('#^https?://#i', $origin) !== 1) {
            $origin = 'https://'.$origin;
        }

        return $origin.$path;
    }

    /**
     * علل القانونيّ لصفٍّ — فارغةٌ حين يمثّل المورد المرجعيّ فعلًا.
     *
     * @return list<array{code: string, message: string}>
     */
    public function issues(UrlRegistryEntry $entry): array
    {
        $issues = [];
        $canonical = $entry->canonical_url;

        if ($canonical === null || $canonical === '') {
            $issues[] = ['code' => 'MISSING', 'message' => 'لا قانونيّ مسجّل.'];

            return $issues;
        }

        $canonicalPath = $this->pathOf($canonical);

        if ($canonicalPath !== $entry->path) {
            $issues[] = ['code' => 'MISMATCH', 'message' => "القانونيّ «{$canonicalPath}» لا يطابق المسار «{$entry->path}»."];
        }
        if (RedirectRule::query()->where('from_path', $canonicalPath)->where('enabled', true)->exists()) {
            $issues[] = ['code' => 'TO_REDIRECT', 'message' => 'القانونيّ يشير إلى مسارٍ مُحوَّل.'];
        }
        if (NotFoundHit::query()->where('path', $canonicalPath)->exists() && ! $entry->status()->isPublic()) {
            $issues[] = ['code' => 'TO_404', 'message' => 'القانونيّ مسجَّلٌ ٤٠٤.'];
        }
        if ($entry->status() === UrlStatus::Tombstoned) {
            $issues[] = ['code' => 'TO_410', 'message' => 'القانونيّ لموردٍ مُزال (٤١٠).'];
        }
        if (! $entry->indexable && $entry->status()->isPublic()) {
            $issues[] = ['code' => 'TO_NOINDEX', 'message' => 'القانونيّ لصفحةٍ غير قابلة للفهرسة.'];
        }
        $prefix = RouteBuilders::localePrefix($entry->locale);
        if ($prefix !== '' && ! str_starts_with($canonicalPath, $prefix.'/')) {
            $issues[] = ['code' => 'CROSS_LOCALE', 'message' => 'القانونيّ خارج بادئة لغته.'];
        }
        if (str_contains($canonical, '?')) {
            $issues[] = ['code' => 'QUERY', 'message' => 'القانونيّ يحمل استعلامًا.'];
        }
        $site = (string) config('seo.site_url', '');
        if ($site !== '' && preg_match('#^https?://#i', $canonical) === 1 && ! str_starts_with($canonical, rtrim($site, '/'))) {
            $issues[] = ['code' => 'HOST_MISMATCH', 'message' => 'مضيف القانونيّ ليس مضيف المتجر.'];
        }

        return $issues;
    }

    private function pathOf(string $canonical): string
    {
        if (preg_match('#^https?://#i', $canonical) === 1) {
            return (string) (parse_url($canonical, PHP_URL_PATH) ?? '/');
        }

        return $canonical;
    }
}
