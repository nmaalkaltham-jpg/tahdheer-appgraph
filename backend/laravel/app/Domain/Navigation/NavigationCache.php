<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Domain\Navigation\Models\NavigationMenu;
use Illuminate\Support\Facades\Cache;

/**
 * كاش القائمة المنشورة — وإبطاله الموجَّه.
 *
 * القائمة تُقرأ في **كل صفحة** من المتجر، فقراءتها من القاعدة لكل طلب هي
 * استعلام لكل زيارة مقابل صفر تغيّر. لكن الكاش هنا مشتقّ لا مصدر: الإبطال
 * موجَّه بمكان واحد، ولا مسح شامل (`flush`) أبدًا — مسحُ كل شيء لأجل قائمة
 * يُسقط كاش السلة والتسعير معها.
 */
final class NavigationCache
{
    private const TTL = 600;

    /** اسم المخطّط في `meta.schema` — يتغيّر حين يتغيّر شكل العقدة. */
    public const SCHEMA = 'tahdheer.navigation/v1';

    /**
     * الظرف المنشور لمكان: العقد ومعها هويّة الإصدار.
     *
     * الرقم والبصمة يُكاشان **مع** العقد لا بجانبها: ظرفٌ واحد يعني أنّ ما
     * يُخدَم وما يُقال عنه لا يفترقان أبدًا — لو كُوشا منفصلَين لأمكن أن
     * يُعيد الكاش شجرة الإصدار ١١ ورقمَ الإصدار ١٢، وهو بالضبط الانحراف
     * الذي وُضع هذا الحقل ليكشفه.
     *
     * @return array{nodes: list<array<string, mixed>>, revision: int|null, publishedAt: string|null, fingerprint: string}
     */
    public static function envelope(string $location): array
    {
        $cached = Cache::remember(
            self::key($location),
            self::TTL,
            static function () use ($location): array {
                $menu = NavigationMenu::query()
                    ->where('location', $location)
                    ->whereNotNull('published_at')
                    ->first(['published_nodes', 'published_version', 'published_at']);

                $nodes = is_array($menu?->published_nodes) ? $menu->published_nodes : [];

                return [
                    'nodes' => $nodes,
                    'revision' => $menu === null ? null : (int) $menu->published_version,
                    'publishedAt' => $menu?->published_at?->toIso8601String(),
                    'fingerprint' => MenuTree::fingerprint($nodes),
                ];
            },
        );

        // ظرفٌ بشكلٍ قديم (ترقيةٌ أثناء التشغيل) يُقرأ فارغًا لا يُصيَّر خطأً.
        return is_array($cached) && array_key_exists('nodes', $cached)
            ? $cached
            : ['nodes' => [], 'revision' => null, 'publishedAt' => null, 'fingerprint' => MenuTree::fingerprint([])];
    }

    /**
     * العقد المنشورة لمكان — الشكل القديم، يقرأ الظرف نفسه.
     *
     * @return list<array<string, mixed>>
     */
    public static function published(string $location): array
    {
        return self::envelope($location)['nodes'];
    }

    /**
     * يُبطل مكانًا واحدًا — والمواقع التي ترثه.
     *
     * `mobile` يسقط على `header` حين لا يُنشر له شيء، فنشرُ الترويسة يغيّر ما
     * يخدمه الجوّال. إبطال الترويسة وحدها يترك الجوّال على نسخة ميتة.
     */
    public static function invalidate(string $location): void
    {
        Cache::forget(self::key($location));

        foreach (MenuTree::FALLS_BACK_TO as $child => $parent) {
            if ($parent === $location) {
                Cache::forget(self::key($child));
            }
        }
    }

    /**
     * المفتاح يحمل جيله.
     *
     * شكل القيمة تغيّر من «مصفوفة عقد» إلى ظرف؛ مفتاحٌ واحد للشكلَين يجعل
     * نشرًا بعد الترقية يقرأ مصفوفةً حيث يُنتظر ظرف، لعشر دقائق.
     */
    private static function key(string $location): string
    {
        return "navigation:published:v2:{$location}";
    }
}
