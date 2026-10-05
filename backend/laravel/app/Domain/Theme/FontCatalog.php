<?php

declare(strict_types=1);

namespace App\Domain\Theme;

/**
 * كتالوج الخطوط المتاحة — مصدر الحقيقة لما يُسمح للمشرف باختياره.
 *
 * عقيدة الصدق: الوزن الذي لا يوفّره الخطّ لا يُعرض في اللوحة. المتصفّح لا
 * يشتكي من وزن مفقود؛ يصنع بديلًا اصطناعيًّا أو يقفز لأقرب وزن موجود، فيبدو
 * الضابط عاملًا وهو ليس كذلك. قياس فعلي على المشروع كشف هذا:
 *
 *   · المتجر يستخدم الوزن 800 في 116 إعلانًا ولا يحمّل إلا 700 و900.
 *   · 10 إعلانات تطلب الوزن 600، و«Tajawal» لا يملك 600 أصلًا (تحقّقنا من
 *     Google Fonts وزنًا وزنًا: 200 300 400 500 700 800 900 — بلا 600).
 *   · لوحة التحكم تُعلن font-family:'Tajawal' ولا تحمّل أي خطّ على الإطلاق.
 *
 * لذلك: `weights()` تُستخدم لبناء خيارات الوزن في المحرّر، و`snapWeight()`
 * تُصحّح أي قيمة قديمة إلى أقرب وزن حقيقي بدل تمريرها كما هي.
 */
final class FontCatalog
{
    /**
     * الخطوط المعروفة. `weights` مُتحقَّق منها مقابل مزوّد الخط لا مُقدَّرة.
     *
     * @var array<string, array{
     *     label: string, family: string, source: 'google'|'system'|'local',
     *     weights: list<int>, scripts: list<string>, fallbacks: list<string>
     * }>
     */
    private const FONTS = [
        'tajawal' => [
            'label' => 'Tajawal',
            'family' => 'Tajawal',
            'source' => 'google',
            'weights' => [200, 300, 400, 500, 700, 800, 900],
            'scripts' => ['arabic', 'latin'],
            'fallbacks' => ['SF Arabic', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
        ],
        'ibm-plex-sans-arabic' => [
            'label' => 'IBM Plex Sans Arabic',
            'family' => 'IBM Plex Sans Arabic',
            'source' => 'google',
            'weights' => [100, 200, 300, 400, 500, 600, 700],
            'scripts' => ['arabic', 'latin'],
            'fallbacks' => ['SF Arabic', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
        ],
        'noto-kufi-arabic' => [
            'label' => 'Noto Kufi Arabic',
            'family' => 'Noto Kufi Arabic',
            'source' => 'google',
            'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900],
            'scripts' => ['arabic'],
            'fallbacks' => ['SF Arabic', 'Tahoma', 'Arial', 'sans-serif'],
        ],
        'cairo' => [
            'label' => 'Cairo',
            'family' => 'Cairo',
            'source' => 'google',
            'weights' => [200, 300, 400, 500, 600, 700, 800, 900],
            'scripts' => ['arabic', 'latin'],
            'fallbacks' => ['SF Arabic', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
        ],
        'system' => [
            'label' => 'خطّ النظام',
            'family' => 'system-ui',
            'source' => 'system',
            // خطوط النظام متغيّرة عمليًّا؛ الأوزان التسعة كلها مقبولة.
            'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900],
            'scripts' => ['arabic', 'latin'],
            'fallbacks' => ['SF Arabic', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
        ],
        'mono' => [
            'label' => 'أحادي المسافة',
            'family' => 'ui-monospace',
            'source' => 'system',
            'weights' => [400, 500, 600, 700],
            'scripts' => ['latin'],
            'fallbacks' => ['SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
        ],
    ];

    public const DEFAULT_ID = 'tajawal';

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::FONTS);
    }

    public static function has(string $id): bool
    {
        return isset(self::FONTS[$id]);
    }

    /**
     * تعريف خطّ، أو تعريف الخطّ الافتراضي إن كان المعرّف مجهولًا.
     *
     * @return array{label:string, family:string, source:string, weights:list<int>, scripts:list<string>, fallbacks:list<string>}
     */
    public static function get(string $id): array
    {
        return self::FONTS[$id] ?? self::FONTS[self::DEFAULT_ID];
    }

    /**
     * الأوزان التي يوفّرها الخطّ فعلًا.
     *
     * @return list<int>
     */
    public static function weights(string $id): array
    {
        return self::get($id)['weights'];
    }

    /**
     * أقرب وزن موجود فعلًا. عند التساوي في البُعد يفوز الأثقل، لأن هبوط
     * العنوان درجةً أوضح ضررًا من صعوده درجةً.
     */
    public static function snapWeight(string $id, int $weight): int
    {
        $available = self::weights($id);
        if (in_array($weight, $available, true)) {
            return $weight;
        }

        $best = $available[0];
        $bestDistance = abs($best - $weight);
        foreach ($available as $candidate) {
            $distance = abs($candidate - $weight);
            if ($distance < $bestDistance || ($distance === $bestDistance && $candidate > $best)) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * مكدّس `font-family` جاهزًا للـ CSS، مع اقتباس الأسماء التي تحتاجه.
     */
    public static function stack(string $id): string
    {
        $font = self::get($id);
        $names = array_merge([$font['family']], $font['fallbacks']);

        return implode(', ', array_map(static function (string $name): string {
            // الكلمات المفتاحية العامة والمعرّفات البسيطة تُترك بلا اقتباس.
            $bare = preg_match('/^[a-zA-Z-]+$/', $name) === 1;

            return $bare ? $name : '"'.$name.'"';
        }, $names));
    }

    /**
     * وصف الكتالوج للوحة: ما تعرضه قوائم الاختيار.
     *
     * @return list<array{id:string, label:string, source:string, weights:list<int>, scripts:list<string>, stack:string}>
     */
    public static function describe(): array
    {
        $out = [];
        foreach (self::FONTS as $id => $font) {
            $out[] = [
                'id' => $id,
                'label' => $font['label'],
                'source' => $font['source'],
                'weights' => $font['weights'],
                'scripts' => $font['scripts'],
                'stack' => self::stack($id),
            ];
        }

        return $out;
    }

    /**
     * رابط Google Fonts لمجموعة الخطوط والأوزان المستعملة فعلًا، أو null إن
     * لم يكن أيٌّ منها من Google. الأوزان تُرشَّح إلى المتوفّر منها فقط، فلا
     * يُطلب من المزوّد وزن يردّ عليه بـ 400 صامتة.
     *
     * @param  array<string, list<int>>  $usage  معرّف الخطّ ⇒ الأوزان المطلوبة
     */
    public static function googleFontsUrl(array $usage): ?string
    {
        $families = [];
        foreach ($usage as $id => $weights) {
            $font = self::FONTS[$id] ?? null;
            if ($font === null || $font['source'] !== 'google') {
                continue;
            }

            $wanted = array_values(array_unique(array_intersect($weights, $font['weights'])));
            sort($wanted);
            if ($wanted === []) {
                continue;
            }

            $families[] = 'family='.str_replace(' ', '+', $font['family'])
                .':wght@'.implode(';', $wanted);
        }

        if ($families === []) {
            return null;
        }

        sort($families); // ترتيب ثابت ⇒ رابط قابل للتخزين المؤقّت

        return 'https://fonts.googleapis.com/css2?'.implode('&', $families).'&display=swap';
    }
}
