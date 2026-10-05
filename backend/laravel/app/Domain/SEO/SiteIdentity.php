<?php

namespace App\Domain\SEO;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * The one place the public site origin and the brand are read from.
 *
 * Brand is «التحاضير الحديثة» exclusively (MIP §1.2 CI gate). The origin comes
 * from configuration; when it is unset the services degrade to root-relative
 * URLs rather than inventing a host — an absolute URL to a guessed domain is a
 * fabricated fact.
 */
final class SiteIdentity
{
    public const BRAND = 'التحاضير الحديثة';

    private ?string $resolved = null;

    private bool $didResolve = false;

    /**
     * Absolute site origin without a trailing slash, or null when unconfigured.
     *
     * Resolution order — the PUBLIC storefront origin, which is not necessarily
     * the API's own host:
     *   1. صفّ `seo.site_url` في الإعدادات — تجاوز المشرف الصريح.
     *   2. `config('seo.site_url')` — من SEO_SITE_URL/FRONTEND_URL، افتراضُ النشر.
     *
     * الصفّ أوّلًا لا الإعداد: حقلٌ في اللوحة يقبل التحرير ولا يتغلّب على
     * افتراض النشر ضابطٌ وهمي — يكتب فيه المشرف ولا يتغيّر شيء.
     *
     * `config('app.url')` كان الحلقة الثالثة، وأُزيل عمدًا. `app.url` في
     * الإنتاج هو مضيف الـAPI، وهو **ليس** أصل المتجر أبدًا: الرجوع إليه حوّل
     * إعدادًا مفقودًا إلى رابط معياري خاطئ على كل صفحة — يخبر محرّك البحث أن
     * النسخة المعيارية على مضيف يخدم JSON. بلا أصل مضبوط تُعاد `null`،
     * فتُصدر `url()` مسارًا نسبيًّا: رابط معياري صحيح بالحذف، لا ادّعاء خاطئ.
     *
     * `env()` لا تُقرأ هنا: بعد `config:cache` تُعيد null فتنهار كل الروابط
     * المطلقة صامتةً. القراءة تحدث في config/seo.php وقت بناء الإعداد.
     */
    public function origin(): ?string
    {
        if ($this->didResolve) {
            return $this->resolved;
        }

        $this->didResolve = true;
        $this->resolved = $this->normalize($this->settingValue())
            ?? $this->normalize(config('seo.site_url'));

        return $this->resolved;
    }

    /** The admin-editable override, read defensively before the table exists. */
    private function settingValue(): mixed
    {
        if (! Schema::hasTable('settings')) {
            return null;
        }

        $value = Setting::query()->where('key', 'seo.site_url')->value('value');

        // A settings row may store a bare string, `{"url":…}` or `[…]`.
        if (is_array($value)) {
            return $value['url'] ?? ($value[0] ?? null);
        }

        return $value;
    }

    private function normalize(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $raw = rtrim(trim($raw), '/');

        return $raw === '' ? null : $raw;
    }

    /** True when an absolute public origin is configured. */
    public function isConfigured(): bool
    {
        $origin = $this->origin();

        return $origin !== null && ! str_starts_with($origin, 'http://localhost');
    }

    /**
     * Absolute URL for a root-relative path, or the path itself when no origin
     * is configured. Never guesses a hostname.
     */
    public function url(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $origin = $this->origin();

        return $origin === null ? $path : $origin.$path;
    }

    /** Stable schema.org @id for the publisher node. */
    public function organizationId(): string
    {
        return $this->url('/#organization');
    }

    /** Stable schema.org @id for the website node. */
    public function websiteId(): string
    {
        return $this->url('/#website');
    }
}
