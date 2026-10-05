<?php

namespace App\Domain\SEO;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * أي مسارات المتجر مبنية فعلًا اليوم.
 *
 * HONESTY DOCTRINE: a sitemap URL or a breadcrumb link pointing at a route the
 * storefront has not built yet is a fabricated URL — it returns a real 404. So
 * both the sitemap providers and the breadcrumb builder ask this registry
 * first, and a dormant route class is reported with an explicit reason rather
 * than silently emitted.
 *
 * Defaults mirror the routes that exist in `apps/storefront/app` right now.
 * When a route class ships, flip it with a `seo.routes.{key}` setting — no code
 * change and no redeploy of this class is required.
 */
final class RouteRegistry
{
    /** @var array<string, bool> route class => built today */
    private const DEFAULTS = [
        'home' => true,
        'stages' => true,
        'products' => true,
        'grades' => true,
        'subjects' => true,
        'semesters' => true,
        'kinds' => true,
        'curriculum' => true,
        'content' => false,
    ];

    /**
     * Arabic reason a dormant class is not emitted.
     *
     * The four taxonomy classes above are LIVE as of the hub/archive build, so
     * their reasons stay here only for the case an operator turns one off
     * again through `seo.routes.{key}`.
     *
     * @var array<string, string>
     */
    private const DORMANT_REASON = [
        'grades' => 'مسار /grades معطَّل بإعداد',
        'subjects' => 'مسار /subjects معطَّل بإعداد',
        'semesters' => 'مسار /semesters معطَّل بإعداد',
        'kinds' => 'مسار /kinds معطَّل بإعداد',
        'curriculum' => 'مسارات /curriculum معطَّلة بإعداد',
        'content' => 'صفحات المحتوى غير مبنية في المتجر بعد',
    ];

    /** @var array<string, bool>|null */
    private ?array $overrides = null;

    public function isLive(string $key): bool
    {
        $overrides = $this->overrides();

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return self::DEFAULTS[$key] ?? false;
    }

    /** Why a route class produces no URLs, or null when it is live. */
    public function dormantReason(string $key): ?string
    {
        if ($this->isLive($key)) {
            return null;
        }

        return self::DORMANT_REASON[$key] ?? 'مسار غير مفعَّل: '.$key;
    }

    /** @return array<string, bool> */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        $this->overrides = [];

        if (! Schema::hasTable('settings')) {
            return $this->overrides;
        }

        foreach (Setting::query()->where('key', 'like', 'seo.routes.%')->get() as $setting) {
            $key = substr((string) $setting->key, strlen('seo.routes.'));
            $value = $setting->value;

            // A settings row may store `true`, `{"enabled":true}` or `[true]`.
            if (is_array($value)) {
                $value = $value['enabled'] ?? ($value[0] ?? null);
            }

            if (is_bool($value)) {
                $this->overrides[$key] = $value;
            }
        }

        return $this->overrides;
    }
}
