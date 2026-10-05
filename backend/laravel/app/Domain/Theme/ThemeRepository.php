<?php

declare(strict_types=1);

namespace App\Domain\Theme;

use App\Domain\Admin\Models\AdminUser;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * قراءة وكتابة وثيقة الثيم.
 *
 * تُخزَّن تحت مفتاح واحد في جدول `settings` القائم — لا جدول جديد. الطلب كان
 * صريحًا بألّا تتفرّق الإعدادات، وإضافة جدول موازٍ لآلية مفاتيح/قيم تعمل
 * أصلًا هي بالضبط التفرّق الذي طُلب تجنّبه.
 *
 * الكاش يحمل الوثيقة المُطبَّعة و CSS المُترجَم معًا: صفحة المتجر تطلبهما في
 * كل تصيير، والترجمة عملية حسابية بحتة لا داعي لتكرارها. أي كتابة تُبطلهما
 * فورًا، فلا نافذة يُقدَّم فيها CSS قديم بعد حفظ ناجح.
 */
final class ThemeRepository
{
    /**
     * المفتاح يحمل نسختَي المخطّط والمُترجِم معًا، فنشر أيّ منهما يُبطل النسخ
     * المخزَّنة فورًا بدل انتظار انتهاء الساعة. مفتاح ثابت كان يعني نشرًا
     * ناجحًا ومتجرًا لا يتغيّر — بلا خطأ يشي بالسبب.
     */
    private static function cacheKey(): string
    {
        return 'theme.compiled.d'.ThemeDocument::VERSION.'.c'.ThemeCss::VERSION;
    }

    private const CACHE_TTL = 3600;

    /**
     * الوثيقة المُطبَّعة. غياب الصفّ يعني الافتراضات — لا خطأ: متجر لم
     * يُخصَّص بعد متجرٌ صالح.
     *
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        return self::compiled()['document'];
    }

    public static function css(): string
    {
        return self::compiled()['css'];
    }

    /**
     * الحمولة الكاملة التي يحتاجها المتجر في تصيير واحد.
     *
     * @return array{document: array<string, mixed>, css: string, fontsUrl: string|null, etag: string}
     */
    public static function compiled(): array
    {
        /** @var array{document: array<string, mixed>, css: string, fontsUrl: string|null, etag: string} */
        return Cache::remember(self::cacheKey(), self::CACHE_TTL, static function (): array {
            $raw = Setting::query()->where('key', ThemeDocument::SETTING_KEY)->value('value');
            $document = ThemeDocument::normalize(self::decode($raw));
            $css = ThemeCss::compile($document);

            return [
                'document' => $document,
                'css' => $css,
                'fontsUrl' => FontCatalog::googleFontsUrl(ThemeDocument::fontUsage($document)),
                // بصمة المحتوى: تتيح للمتجر ردّ 304 وللوحة كشف تعديل متزامن.
                'etag' => substr(hash('sha256', $css), 0, 16),
            ];
        });
    }

    /** أقصى ما يُحتفظ به من لقطات — يُقلَّم داخل معاملة الحفظ نفسها (C27). */
    public const VERSIONS_KEEP = 50;

    /**
     * يحفظ وثيقة واردة بعد تطبيعها، ويعيد الحمولة المُترجَمة الجديدة.
     *
     * لقطات `theme_versions` (C27): إن كان الجدول فارغًا تُلتقط الوثيقة
     * قبل الحفظ صفًّا kind='baseline' أولًا — فأوّل حفظ بعد الترحيل لا
     * يمحو نقطة العودة الوحيدة؛ ثم تُلتقط الوثيقة المحفوظة، ويُقلَّم إلى
     * أحدث 50 — كل ذلك في معاملة واحدة.
     *
     * @return array{document: array<string, mixed>, css: string, fontsUrl: string|null, etag: string}
     */
    public static function save(mixed $input, ?AdminUser $actor = null, string $kind = 'publish', ?string $label = null): array
    {
        $document = ThemeDocument::normalize($input);

        DB::transaction(static function () use ($document, $actor, $kind, $label): void {
            if (DB::table('theme_versions')->count() === 0) {
                $raw = Setting::query()->where('key', ThemeDocument::SETTING_KEY)->value('value');
                self::snapshot(ThemeDocument::normalize(self::decode($raw)), 'baseline', null, null);
            }

            Setting::query()->updateOrCreate(
                ['key' => ThemeDocument::SETTING_KEY],
                ['value' => $document],
            );

            self::snapshot($document, $kind, $label, $actor?->getKey());

            $stale = DB::table('theme_versions')
                ->orderByDesc('id')
                ->skip(self::VERSIONS_KEEP)
                ->take(1000)
                ->pluck('id');
            if ($stale->isNotEmpty()) {
                DB::table('theme_versions')->whereIn('id', $stale)->delete();
            }
        });

        Cache::forget(self::cacheKey());

        return self::compiled();
    }

    /**
     * أحدث اللقطات للوحة — {id, kind, label, createdBy, createdAt}.
     *
     * @return list<array<string, mixed>>
     */
    public static function versions(int $limit = 30): array
    {
        return DB::table('theme_versions')
            ->leftJoin('admin_users', 'admin_users.id', '=', 'theme_versions.created_by')
            ->orderByDesc('theme_versions.id')
            ->limit($limit)
            ->get(['theme_versions.id', 'theme_versions.kind', 'theme_versions.label', 'admin_users.name as created_by_name', 'theme_versions.created_at'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'kind' => $row->kind,
                'label' => $row->label,
                'createdBy' => $row->created_by_name,
                'createdAt' => $row->created_at,
            ])->all();
    }

    /**
     * وثيقة لقطة بعينها — null إن لم توجد.
     *
     * @return array<string, mixed>|null
     */
    public static function versionDocument(int $id): ?array
    {
        $raw = DB::table('theme_versions')->where('id', $id)->value('document');
        if (! is_string($raw)) {
            return is_array($raw) ? $raw : null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** يكتب لقطة واحدة — بصمتها بصمة CSS المُترجَم من وثيقتها. */
    private static function snapshot(array $document, string $kind, ?string $label, int|string|null $actorId): void
    {
        DB::table('theme_versions')->insert([
            'document' => json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'etag' => substr(hash('sha256', ThemeCss::compile($document)), 0, 16),
            'kind' => $kind,
            'label' => $label,
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    /** يعيد كل شيء للافتراضات — بحذف الصفّ لا بكتابة نسخة منها. */
    public static function reset(): array
    {
        Setting::query()->where('key', ThemeDocument::SETTING_KEY)->delete();
        Cache::forget(self::cacheKey());

        return self::compiled();
    }

    public static function flush(): void
    {
        Cache::forget(self::cacheKey());
    }

    /** القيمة قد تعود نصًّا من بعض المشغّلات ومصفوفةً من غيرها. */
    private static function decode(mixed $raw): mixed
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return $raw;
    }
}
