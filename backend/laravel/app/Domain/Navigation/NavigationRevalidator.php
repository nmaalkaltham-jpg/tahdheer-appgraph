<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

/**
 * وسوم إبطال التنقّل — الجهة الوحيدة التي تعرف صيغتها.
 *
 * المرآة في المتجر `apps/storefront/lib/navigation.ts::navTag`. الصيغتان
 * يجب أن تتطابقا حرفيًّا: نشرٌ يُبطل وسمًا لا يطلبه أحد لا يُصدر خطأً — يعود
 * المتجر ٢٠٠ وقد أبطل لا شيء، فيبقى الزائر على القائمة القديمة بلا أثرٍ
 * يقول لماذا. `NavigationTagParityTest` يفحص الملفَّين معًا.
 */
final class NavigationRevalidator
{
    /**
     * وسوم مكانٍ نُشر — ومعها المواقع التي ترثه.
     *
     * نشر الترويسة يغيّر ما يُخدَم للجوّال (`FALLS_BACK_TO`)، فإبطال وسم
     * الترويسة وحده يترك الجوّال على نسخةٍ ميتة — وهي العلّة نفسها التي
     * يعالجها `NavigationCache::invalidate` في طبقة Laravel.
     *
     * @return list<string>
     */
    public static function tagsFor(string $location): array
    {
        $tags = [self::tag($location)];

        foreach (MenuTree::FALLS_BACK_TO as $child => $parent) {
            if ($parent === $location) {
                $tags[] = self::tag($child);
            }
        }

        return array_values(array_unique($tags));
    }

    public static function tag(string $location): string
    {
        return "navigation:{$location}";
    }
}
