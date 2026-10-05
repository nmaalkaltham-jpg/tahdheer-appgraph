<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * عقد تخطيط صفحة المنتج.
 *
 * أقسام مرتّبة، لكل قسم ودجة معروفة وإعدادات وظهور. لا HTML خام: تخزين
 * الترميز يجمّد التصميم ويجعل كل تغيير في الهوية البصرية تحريرًا يدويًّا لكل
 * منتج.
 *
 * الودجات قائمة سماح مغلقة — ودجة مجهولة تُسقَط بدل تصييرها، فلا يصل اسم
 * مكوّن من مُدخَل إلى مُصيِّر.
 */
final class ProductLayout
{
    /**
     * مناطق الصفحة. الترتيب حقيقي **داخل** المنطقة فقط.
     *
     * عرض قائمة واحدة تُرتَّب بحرّية يَعِد بما لا يقع: صندوق الشراء لا ينزل
     * تحت التقييمات لأنه في العمود الآخر من الشبكة. الوعد الكاذب أسوأ من
     * القيد المُعلن.
     *
     * @var array<string, string>
     */
    public const ZONES = [
        'media' => 'عمود الوسائط',
        'hero' => 'صندوق المعلومات',
        'body' => 'أقسام الصفحة',
    ];

    /**
     * قائمة السماح: ودجة ⇒ تسميتها ومنطقتها.
     *
     * كل ودجة هنا لها مُصيِّر فعلي في الواجهة. الودجة التي لا يقابلها مُصيِّر
     * زرٌّ لا يفعل شيئًا: يبدّله المشرف ويحفظ ولا يتغيّر في الصفحة شيء.
     *
     * @var array<string, array{label: string, zone: string}>
     */
    public const WIDGETS = [
        'gallery' => ['label' => 'معرض الصور', 'zone' => 'media'],
        'title' => ['label' => 'العنوان والسعر', 'zone' => 'hero'],
        'purchase' => ['label' => 'صندوق الشراء', 'zone' => 'hero'],
        'trust' => ['label' => 'شريط الثقة', 'zone' => 'hero'],
        'facts' => ['label' => 'محتويات المنتج', 'zone' => 'hero'],
        'description' => ['label' => 'الوصف الكامل', 'zone' => 'body'],
        'glance' => ['label' => 'جدول لمحة سريعة', 'zone' => 'body'],
        /*
         * الستّ أدناه تقرأ النموذج الكونيّ لا `products`: عيّنات الملفّ
         * (ProductPreviews)، وأقسام المحتوى المسمّاة (§٤٩) بأنواعها الثلاثة
         * — المحتويات والأهداف التعليمية والترخيص والاسترجاع — وجدول التوافق
         * (§٥٦)، وتوصيات «يُشترى معه» (§٥٧). وكلٌّ منها يغيب عن الصفحة حين لا
         * صفَّ له — فالتبديل هنا يقرّر «أتُعرض إن وُجدت» لا «أتُختلق».
         *
         * وفصل `goals` و`license` عن `content` لأنّ الثلاثة أنواعٌ مستقلّة في
         * المصدر نفسه: شرطُ الترخيص والاسترجاع لا يُقرأ داخل تبويبٍ اسمه
         * «محتويات الملفّ».
         *
         * والأسماء كلمةٌ واحدة بلا شرطة عمدًا: الاستخراج يعتمد
         * قائمة السماح بنمط `[a-z]+`، فاسمٌ مركَّب يسقط من المقارنة صامتًا
         * ويضيع التكافؤ الذي وُجد الحارس ليحفظه.
         */
        'content' => ['label' => 'أقسام المحتوى', 'zone' => 'body'],
        'goals' => ['label' => 'الأهداف التعليمية', 'zone' => 'body'],
        'compat' => ['label' => 'التوافق والمواصفات', 'zone' => 'body'],
        'preview' => ['label' => 'المعاينة', 'zone' => 'body'],
        'faqs' => ['label' => 'الأسئلة الشائعة', 'zone' => 'body'],
        'reviews' => ['label' => 'التقييمات', 'zone' => 'body'],
        'license' => ['label' => 'الترخيص والاسترجاع', 'zone' => 'body'],
        'together' => ['label' => 'يُشترى معه عادةً', 'zone' => 'body'],
    ];

    /** أقسام لا تُحذف: صفحة منتج بلا سعر أو بلا شراء ليست صفحة منتج. */
    public const REQUIRED = ['title', 'purchase'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'version' => 1,
            'sections' => array_values(array_map(
                static fn (string $w): array => self::section($w),
                array_keys(self::WIDGETS),
            )),
        ];
    }

    /** @return array<string, mixed> */
    private static function section(string $widget): array
    {
        return [
            'widget' => $widget,
            'variant' => 'default',
            'visible' => true,
            'hide' => ['desk' => false, 'tab' => false, 'mob' => false],
            'settings' => new \stdClass,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalize(mixed $input): array
    {
        if (! is_array($input) || ! is_array($input['sections'] ?? null)) {
            return self::defaults();
        }

        $seen = [];
        $sections = [];

        foreach (array_slice($input['sections'], 0, 30) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $widget = (string) ($raw['widget'] ?? '');
            // مجهولة تُسقَط؛ ومكرّرة تُسقَط لأن قسمين بالودجة نفسها يُصيَّران مرّتين.
            if (! isset(self::WIDGETS[$widget]) || isset($seen[$widget])) {
                continue;
            }
            $seen[$widget] = true;

            $visible = (bool) ($raw['visible'] ?? true);

            $sections[] = [
                'widget' => $widget,
                'variant' => self::slug($raw['variant'] ?? 'default'),
                // الأقسام الإلزامية تبقى ظاهرة مهما أُرسل.
                'visible' => in_array($widget, self::REQUIRED, true) ? true : $visible,
                'hide' => [
                    'desk' => (bool) ($raw['hide']['desk'] ?? false),
                    'tab' => (bool) ($raw['hide']['tab'] ?? false),
                    'mob' => (bool) ($raw['hide']['mob'] ?? false),
                ],
                'settings' => is_array($raw['settings'] ?? null) ? $raw['settings'] : new \stdClass,
            ];
        }

        // ما نقص من الإلزامي يُعاد بترتيبه الافتراضي.
        foreach (self::REQUIRED as $widget) {
            if (! isset($seen[$widget])) {
                $sections[] = self::section($widget);
            }
        }

        return ['version' => 1, 'sections' => $sections];
    }

    /**
     * أقسام منطقة واحدة بترتيبها المحفوظ.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    public static function inZone(array $sections, string $zone): array
    {
        return array_values(array_filter(
            $sections,
            static fn (array $s): bool => (self::WIDGETS[$s['widget']]['zone'] ?? null) === $zone,
        ));
    }

    private static function slug(mixed $v): string
    {
        $clean = is_string($v) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $v) : '';

        return ($clean === '' || $clean === null) ? 'default' : substr($clean, 0, 40);
    }
}
