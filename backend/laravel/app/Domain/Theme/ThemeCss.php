<?php

declare(strict_types=1);

namespace App\Domain\Theme;

/**
 * يترجم وثيقة الثيم إلى متغيّرات CSS مخصّصة.
 *
 * لماذا متغيّرات لا أنماط جاهزة: المتغيّر يهبط على الشجرة بالوراثة الطبيعية،
 * فالتجاوز على قسم أو مكوّن يصير سطرًا واحدًا داخل نطاقه بدل قاعدة جديدة
 * بأولوية أعلى. ولا يحتاج المتجر أن يعرف من غيّر ماذا؛ يستهلك الرمز فقط.
 *
 * الحجم السائل يُحسب بـ clamp() لا بنقاط توقّف: قيمة واحدة تغطّي المدى كله
 * بلا قفزات، والحدّان الأدنى والأعلى يمنعان الانفجار خارج النافذة. من يطفئ
 * «سائل» يحصل على استعلامات وسائط صريحة بدلًا منها.
 *
 * كل قيمة تخرج من هنا مرّت بتطبيع ThemeDocument، فلا يوجد مسار يحقن نصًّا
 * حرًّا في CSS.
 */
final class ThemeCss
{
    /**
     * نسخة المُترجِم. تُرفَع مع أي تغيير في شكل المخرج.
     *
     * سببها عطل نشر حقيقي: الناتج مخزَّن مؤقّتًا لساعة بمفتاح ثابت، فنشر
     * مُترجِم جديد كان يترك CSS القديم يُقدَّم حتى تنتهي المدّة — بلا خطأ ولا
     * إشارة، فيبدو النشر ناجحًا والمتجر لم يتغيّر. المفتاح يحمل هذا الرقم،
     * فرفعه يُبطل النسخ المخزَّنة لحظة النشر.
     *
     * 2: بثّ رموز الاستوديو (C27) — الرفع إلزامي لا اختياري: المخرج تغيّر
     * شكلًا فبدون الرفع يبقى CSS قديم يُقدَّم حتى ساعة بلا خطأ.
     */
    public const VERSION = 2;

    /** الخصائص التي تُصدَّر كمتغيّرات لكل دور. */
    private const EMITTED = [
        'family' => 'family',
        'weight' => 'weight',
        'style' => 'style',
        'lineHeight' => 'leading',
        'letterSpacing' => 'tracking',
        'wordSpacing' => 'word',
        'align' => 'align',
        'transform' => 'transform',
        'decoration' => 'decoration',
        'color' => 'color',
    ];

    /**
     * @param  array<string, mixed>  $document  وثيقة مُطبَّعة
     */
    public static function compile(array $document): string
    {
        $root = [];
        $tabletOnly = [];
        $mobileOnly = [];

        $scale = $document['scale'];
        $root[] = '--tp-base-size: '.self::num($scale['baseSize']).'px';
        $root[] = '--tp-ratio: '.self::num($scale['ratio']);
        $root[] = '--tp-font-base: '.FontCatalog::stack((string) $document['baseFamily']);
        $root[] = '--tp-font-heading: '.FontCatalog::stack((string) $document['headingFamily']);

        foreach (ThemeDocument::ROLES as $role) {
            $spec = $document['roles'][$role] ?? null;
            if (! is_array($spec)) {
                continue;
            }

            $isHeading = str_starts_with($role, 'h') && strlen($role) === 2;
            $family = $spec['family'] !== null
                ? FontCatalog::stack((string) $spec['family'])
                : ($isHeading ? 'var(--tp-font-heading)' : 'var(--tp-font-base)');

            $prefix = '--tp-'.self::kebab($role);
            $root[] = $prefix.'-family: '.$family;
            $root[] = $prefix.'-weight: '.(int) $spec['weight'];
            $root[] = $prefix.'-style: '.$spec['style'];
            $root[] = $prefix.'-leading: '.self::num($spec['lineHeight']);
            $root[] = $prefix.'-tracking: '.self::num($spec['letterSpacing']).'em';
            $root[] = $prefix.'-word: '.self::num($spec['wordSpacing']).'em';
            $root[] = $prefix.'-align: '.($spec['align'] ?? 'inherit');
            $root[] = $prefix.'-transform: '.$spec['transform'];
            $root[] = $prefix.'-decoration: '.$spec['decoration'];
            $root[] = $prefix.'-color: '.($spec['color'] ?? 'inherit');

            $size = $spec['size'];
            if ($size['fluid']) {
                $root[] = $prefix.'-size: '.self::fluid(
                    (float) ($size['min'] ?? $size['mobile']),
                    (float) ($size['max'] ?? $size['desktop']),
                    (float) $scale['minViewport'],
                    (float) $scale['maxViewport'],
                );
            } else {
                // ثابت لكل جهاز: القيمة الأوسع في الجذر، والأضيق داخل استعلام.
                $root[] = $prefix.'-size: '.self::num($size['desktop']).'px';
                $tabletOnly[] = $prefix.'-size: '.self::num($size['tablet']).'px';
                $mobileOnly[] = $prefix.'-size: '.self::num($size['mobile']).'px';
            }
        }

        /*
         * رموز الاستوديو (C27): تجاوزات فوق افتراضات tokens.css داخل كتلة
         * ‎:root:root‎ نفسها — نفس آلية فوز الثيم البنيوية. الترتيب ترتيب
         * الكتالوج المُغلق (حتمي)، والقيم مرّت بقواعد نوعها في التطبيع.
         */
        $tokens = is_array($document['tokens'] ?? null) ? $document['tokens'] : [];
        foreach (TokenCatalog::CATALOG as $name => $def) {
            if (! array_key_exists($name, $tokens)) {
                continue;
            }
            $value = $tokens[$name];
            $root[] = '--'.$name.': '.($def['kind'] === 'radius' ? self::num((float) $value).'px' : (string) $value);
        }

        /*
         * ‎:root:root‎ لا ‎:root‎ — بخصوصية (0,2,0) مقابل (0,1,0).
         * الافتراضات في tokens.css تُعلَن على ‎:root‎ أيضًا، ولو تساوت
         * الخصوصية لحُسم التعارض بترتيب التحميل: وترتيب حَقن Next لأوراقه
         * ليس مضمونًا مقابل <link> مكتوب يدويًّا. الخصوصية الأعلى تجعل فوز
         * الثيم حقيقة بنيوية لا مصادفة ترتيب.
         */
        $css = ':root:root{'.implode(';', $root).'}';

        // الترتيب مقصود: اللوحي أوّلًا ثم الجوال، فالأضيق يفوز عند التداخل.
        if ($tabletOnly !== []) {
            $css .= '@media (max-width:'.(int) $document['breakpoints']['tablet'].'px){:root:root{'
                .implode(';', $tabletOnly).'}}';
            $css .= '@media (max-width:'.(int) $document['breakpoints']['mobile'].'px){:root:root{'
                .implode(';', $mobileOnly).'}}';
        }

        $css .= self::overrides($document);

        return $css;
    }

    /**
     * تجاوزات القسم والمكوّن. كلٌّ يعيد تعريف المتغيّر داخل نطاقه فقط، فما لم
     * يُذكر يبقى موروثًا من الجذر — وهذا هو معنى «إعادة للموروث» في اللوحة:
     * حذف السطر، لا كتابة قيمة مضادّة.
     *
     * @param  array<string, mixed>  $document
     */
    private static function overrides(array $document): string
    {
        $css = '';
        $selectors = [
            'section' => '[data-theme-section="%s"]',
            'component' => '[data-theme-component="%s"]',
            'page' => '[data-theme-page="%s"]',
            /* الموضع: محدّد متحدِّر يُبنى من شقّي المعرّف — انظر placementSelector. */
            'placement' => null,
        ];

        foreach ($selectors as $scope => $pattern) {
            foreach ($document['overrides'][$scope] ?? [] as $id => $roleMap) {
                $declarations = [];
                foreach ($roleMap as $role => $patch) {
                    /*
                     * `box` يُبثّ خصائص CSS حقيقية على الهدف نفسه، لا متغيّرات
                     * `--tp-*`: الخلفيّة والحشوة والاستدارة يملكها الصندوق ولا
                     * يرثها نصٌّ بداخله. والخصائص منطقيّة حصرًا (padding-block /
                     * padding-inline) — قاعدة RTL في هذا المستودع.
                     */
                    if ($role === 'box') {
                        foreach (self::boxDeclarations($patch) as $declaration) {
                            $declarations[] = $declaration;
                        }

                        continue;
                    }

                    $prefix = '--tp-'.self::kebab((string) $role);
                    foreach ($patch as $property => $value) {
                        $declaration = self::declaration($prefix, (string) $property, $value, $document);
                        if ($declaration !== null) {
                            $declarations[] = $declaration;
                        }
                    }
                }

                if ($declarations !== []) {
                    $selector = $pattern === null
                        ? self::placementSelector((string) $id)
                        : sprintf($pattern, $id);

                    if ($selector !== null) {
                        $css .= $selector.'{'.implode(';', $declarations).'}';
                    }
                }
            }
        }

        return $css;
    }

    /**
     * `قسم__مكوّن` ⇐ `[data-theme-section="قسم"] [data-theme-component="مكوّن"]`.
     *
     * محدّدٌ متحدِّر لا مركّب: البطاقة ليست هي القسم، بل بداخله. وتخصّصه أعلى
     * من تخصّص نطاق المكوّن وحده (سمتان لا سمة)، فتجاوز الموضع يغلب تجاوز
     * المكوّن حتميًّا بلا `!important` — وهو الترتيب الذي يتوقّعه من خصّص
     * موضعًا بعد أن ضبط العامّ.
     */
    private static function placementSelector(string $id): ?string
    {
        $parts = explode('__', $id);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        /*
         * محدّدان لا واحد: الشقّ الأوّل قد يكون قسمًا أو صفحة، والموجود منهما
         * هو الذي يطابق. وكتابةُ الاثنين أرخص من نطاقٍ خامس يفرّقهما بمعرّفٍ
         * مُعلَّم — والمجموعتان مفصولتان بالبناء فلا يطابقان معًا.
         */
        return '[data-theme-section="'.$parts[0].'"] [data-theme-component="'.$parts[1].'"],'
            .'[data-theme-page="'.$parts[0].'"] [data-theme-component="'.$parts[1].'"]';
    }

    /**
     * إعلانات الصندوق بترتيبٍ ثابت — لا ترتيبِ مفاتيح الكائن.
     *
     * الترتيب هنا هو ترتيب الإخراج: `json_encode` في PHP قد يعيد ترتيب
     * المفاتيح، فمخرَجٌ يتبعه يتغيّر بايتُه بلا أن يتغيّر معناه — وهو ما يكسر
     * مطابقة ETag ويُبطل التخزين المؤقّت بلا سبب.
     *
     * @param  array<string, mixed>  $box
     * @return list<string>
     */
    private static function boxDeclarations(array $box): array
    {
        $out = [];

        if (isset($box['background']) && is_string($box['background'])) {
            $out[] = 'background: '.$box['background'];
        }

        $lengths = [
            'radius' => 'border-radius',
            'padBlock' => 'padding-block',
            'padInline' => 'padding-inline',
        ];

        foreach ($lengths as $key => $property) {
            if (! isset($box[$key]) || ! is_numeric($box[$key])) {
                continue;
            }

            $out[] = $property.': '.self::num((float) $box[$key]).'px';
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function declaration(string $prefix, string $property, mixed $value, array $document): ?string
    {
        if ($property === 'size') {
            if (! is_array($value)) {
                return null;
            }

            $scale = $document['scale'];
            if ($value['fluid'] ?? true) {
                return $prefix.'-size: '.self::fluid(
                    (float) ($value['min'] ?? $value['mobile'] ?? 14),
                    (float) ($value['max'] ?? $value['desktop'] ?? 16),
                    (float) $scale['minViewport'],
                    (float) $scale['maxViewport'],
                );
            }

            return $prefix.'-size: '.self::num((float) ($value['desktop'] ?? 16)).'px';
        }

        if ($property === 'family') {
            return is_string($value) ? $prefix.'-family: '.FontCatalog::stack($value) : null;
        }

        $suffix = self::EMITTED[$property] ?? null;
        if ($suffix === null) {
            return null;
        }

        return match ($property) {
            'weight' => $prefix.'-weight: '.(int) $value,
            'lineHeight' => $prefix.'-leading: '.self::num((float) $value),
            'letterSpacing' => $prefix.'-tracking: '.self::num((float) $value).'em',
            'wordSpacing' => $prefix.'-word: '.self::num((float) $value).'em',
            'align', 'color' => $prefix.'-'.$suffix.': '.(is_string($value) ? $value : 'inherit'),
            default => is_string($value) ? $prefix.'-'.$suffix.': '.$value : null,
        };
    }

    /**
     * clamp() خطّي بين حجمين عند عرضَي نافذة.
     *
     * الميل بالوحدة vw، والمقطع ثابت بالبكسل. تُستعمل rem في الحدّين حتى
     * يحترم التصميم تكبير الخطّ في إعدادات المتصفّح — حدٌّ بالبكسل يلغيه.
     */
    private static function fluid(float $minPx, float $maxPx, float $minVw, float $maxVw): string
    {
        if ($maxVw <= $minVw || abs($maxPx - $minPx) < 0.01) {
            return self::num($maxPx).'px';
        }

        if ($minPx > $maxPx) {
            [$minPx, $maxPx] = [$maxPx, $minPx];
        }

        $slope = ($maxPx - $minPx) / ($maxVw - $minVw);
        $intercept = $minPx - $slope * $minVw;

        return 'clamp('
            .self::num($minPx / 16).'rem, '
            .self::num($intercept / 16).'rem + '.self::num($slope * 100).'vw, '
            .self::num($maxPx / 16).'rem)';
    }

    /** رقم مختصر بلا أصفار زائدة — يقلّل حجم CSS المُولَّد بلا فقد دقّة. */
    private static function num(float|int $value): string
    {
        $rounded = round((float) $value, 4);

        return rtrim(rtrim(number_format($rounded, 4, '.', ''), '0'), '.') ?: '0';
    }

    /** productTitle ⇒ product-title */
    private static function kebab(string $value): string
    {
        return strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $value));
    }
}
