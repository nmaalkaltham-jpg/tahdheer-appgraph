<?php

namespace App\Domain\UrlPlatform;

use App\Domain\Navigation\Models\NavigationMenu;
use App\Domain\Navigation\NavigationCache;
use Illuminate\Support\Facades\DB;

/**
 * إصلاح الروابط الداخليّة — القوائم لا تمرّ عبر تحويلٍ لرابطٍ نحن غيّرناه.
 *
 * حين يتغيّر رابطٌ قانونيّ، عناصر القوائم التي تشير إلى المسار القديم
 * حرفيًّا تُعاد كتابتها إلى الجديد في المسودّة والمنشور معًا، ويُبطل مخزن
 * القائمة. التحويل يبقى للزائر الخارجيّ ومحرّك البحث؛ الداخليّ لا يقفز.
 *
 * المطابقة **حرفيّة** على المسار كاملًا: لا بادئات ولا أنماط — إعادةُ كتابة
 * `/products/a` لا تلمس `/products/ab`.
 */
final class InternalLinkRepair
{
    /** @return array{menus: int, links: int} */
    public function repair(string $previousPath, string $newPath): array
    {
        if ($previousPath === $newPath || $previousPath === '' || $newPath === '') {
            return ['menus' => 0, 'links' => 0];
        }

        $menus = 0;
        $links = 0;

        DB::transaction(function () use ($previousPath, $newPath, &$menus, &$links) {
            foreach (NavigationMenu::query()->lockForUpdate()->cursor() as $menu) {
                $changed = 0;
                $update = [];
                foreach (['draft_nodes', 'published_nodes'] as $column) {
                    $nodes = $menu->{$column};
                    if (! is_array($nodes)) {
                        continue;
                    }
                    [$rewritten, $n] = $this->rewrite($nodes, $previousPath, $newPath);
                    if ($n > 0) {
                        $update[$column] = $rewritten;
                        $changed += $n;
                    }
                }
                if ($changed === 0) {
                    continue;
                }
                $menu->forceFill($update)->save();
                $menus++;
                $links += $changed;
                if (is_string($menu->location) && $menu->location !== '') {
                    NavigationCache::invalidate($menu->location);
                }
            }
        });

        return ['menus' => $menus, 'links' => $links];
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private function rewrite(array $nodes, string $from, string $to): array
    {
        $count = 0;
        foreach ($nodes as $key => $node) {
            if (is_array($node) && isset($node['url']) && $node['url'] === $from) {
                $nodes[$key]['url'] = $to;
                $count++;
            }
        }

        return [$nodes, $count];
    }
}
