<?php

declare(strict_types=1);

namespace App\Domain\Theme;

/**
 * وثيقة الثيم — المصدر الوحيد لتيبوغرافيا المنصّة ومقاييسها وحركتها.
 *
 * لماذا وثيقة واحدة لا جدول لكل خاصية: الطلب صريح بألّا تتفرّق الإعدادات ولا
 * تتضارب. القيمة هنا تُقرأ مرّة، تُترجَم إلى متغيّرات CSS مخصّصة، وتنزل على
 * الشجرة كلها بالوراثة الطبيعية للـ CSS. لا نسخة ثانية في مكان آخر.
 *
 * الوراثة ثلاثية الطبقات، والأخصّ يفوز:
 *   مكوّن  ›  قسم  ›  دور عام
 * أي خاصية غائبة من طبقة تُورَّث من التي تحتها — ولذلك تُخزَّن التجاوزات
 * جزئية عمدًا: `null` تعني «ورِث»، لا «اجعلها صفرًا».
 *
 * التطبيع دفاعي بالكامل: كل مدخل يمرّ بقائمة سماح وحدود قصوى، فلا تصل قيمة
 * غير صالحة إلى CSS المُولَّد. وثيقة قادمة من نسخة أقدم تُملأ بالافتراضات بدل
 * أن تُرفض.
 */
final class ThemeDocument
{
    public const SETTING_KEY = 'theme.document';

    public const VERSION = 1;

    /** الأدوار الدلالية. الترتيب هو ترتيب عرضها في اللوحة. */
    public const ROLES = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'body', 'lead',
        'nav', 'button',
        'productTitle', 'productDescription', 'price', 'category',
        'input', 'label', 'badge', 'alert',
        'footer',
    ];

    /** تسميات عربية للأدوار — تُرسَل للوحة فلا تُكتب مرّتين. */
    public const ROLE_LABELS = [
        'h1' => 'عنوان H1', 'h2' => 'عنوان H2', 'h3' => 'عنوان H3',
        'h4' => 'عنوان H4', 'h5' => 'عنوان H5', 'h6' => 'عنوان H6',
        'body' => 'النصّ الأساسي', 'lead' => 'النصّ التمهيدي',
        'nav' => 'التنقّل والقوائم', 'button' => 'الأزرار',
        'productTitle' => 'اسم المنتج', 'productDescription' => 'وصف المنتج',
        'price' => 'السعر', 'category' => 'التصنيف',
        'input' => 'الحقول والنماذج', 'label' => 'التسميات',
        'badge' => 'الشارات', 'alert' => 'التنبيهات والإشعارات',
        'footer' => 'التذييل',
    ];

    public const DEVICES = ['desktop', 'tablet', 'mobile'];

    public const ALIGNS = ['start', 'center', 'end', 'justify'];

    public const TRANSFORMS = ['none', 'uppercase', 'lowercase', 'capitalize'];

    public const DECORATIONS = ['none', 'underline', 'overline', 'line-through'];

    public const STYLES = ['normal', 'italic'];

    /** حدود الأمان. خارجها لا يبقى التصميم مقروءًا مهما أراد المشرف. */
    private const LIMITS = [
        'size' => [8.0, 96.0],
        'lineHeight' => [0.9, 3.0],
        'letterSpacing' => [-0.15, 0.5],   // em
        'wordSpacing' => [-0.2, 1.0],      // em
        'baseSize' => [12.0, 24.0],
        'ratio' => [1.0, 2.0],
        'viewport' => [280.0, 2560.0],
    ];

    /**
     * الافتراضات — مأخوذة من القيم الفعلية السائدة في أنماط المتجر، لا مخترعة.
     * الوزن 800 حاضر لأن التصميم يفرضه؛ ولذلك يجب أن يُحمَّل فعلًا.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $role = static fn (
            float $desktop, int $weight, float $lineHeight,
            ?float $tablet = null, ?float $mobile = null, float $tracking = 0.0,
        ): array => [
            'family' => null,               // null ⇒ يرث عائلة الأساس
            'size' => [
                'desktop' => $desktop,
                'tablet' => $tablet ?? $desktop,
                'mobile' => $mobile ?? $tablet ?? $desktop,
                'min' => null,              // null ⇒ يُشتقّ من قيمة الجوال
                'max' => null,              // null ⇒ يُشتقّ من قيمة سطح المكتب
                'fluid' => true,
            ],
            'weight' => $weight,
            'style' => 'normal',
            'lineHeight' => $lineHeight,
            'letterSpacing' => $tracking,
            'wordSpacing' => 0.0,
            'align' => null,                // null ⇒ يرث اتجاه السياق
            'transform' => 'none',
            'decoration' => 'none',
            'color' => null,                // null ⇒ يرث لون النصّ
        ];

        return [
            'version' => self::VERSION,
            'baseFamily' => FontCatalog::DEFAULT_ID,
            'headingFamily' => FontCatalog::DEFAULT_ID,
            'scale' => [
                'baseSize' => 16.0,
                'ratio' => 1.2,
                'minViewport' => 360.0,
                'maxViewport' => 1440.0,
            ],
            'breakpoints' => ['tablet' => 1024, 'mobile' => 640],
            'roles' => [
                'h1' => $role(34.0, 900, 1.25, 30.0, 26.0, -0.01),
                'h2' => $role(26.0, 900, 1.3, 24.0, 21.0, -0.01),
                'h3' => $role(21.0, 800, 1.35, 20.0, 18.0),
                'h4' => $role(18.0, 800, 1.4, 17.0, 16.0),
                'h5' => $role(16.0, 800, 1.45, 15.0, 14.5),
                'h6' => $role(14.0, 800, 1.5, 13.5, 13.0),
                'body' => $role(15.0, 500, 1.8, 15.0, 14.0),
                'lead' => $role(17.0, 500, 1.75, 16.0, 15.0),
                'nav' => $role(13.5, 700, 1.5),
                'button' => $role(13.0, 800, 1.4),
                'productTitle' => $role(15.0, 800, 1.5, 14.5, 14.0),
                'productDescription' => $role(12.5, 500, 1.7),
                'price' => $role(17.0, 900, 1.3, 16.0, 15.5),
                'category' => $role(12.0, 700, 1.5),
                'input' => $role(13.5, 500, 1.5),
                'label' => $role(12.0, 700, 1.5),
                'badge' => $role(10.5, 800, 1.4, null, null, 0.01),
                'alert' => $role(13.0, 700, 1.65),
                'footer' => $role(12.5, 500, 1.9),
            ],
            'overrides' => ['section' => [], 'component' => [], 'page' => [], 'placement' => []],
            'presets' => [],
            // رموز الاستوديو (C27): خريطة اسم → قيمة. الغياب = وراثة من
            // tokens.css؛ «إعادة للموروث» = حذف المدخل لا كتابة قيمة مضادّة.
            'tokens' => [],
        ];
    }

    /**
     * تطبيع وثيقة واردة إلى وثيقة صالحة بالكامل.
     *
     * @return array<string, mixed>
     */
    public static function normalize(mixed $input): array
    {
        $defaults = self::defaults();
        if (! is_array($input)) {
            return $defaults;
        }

        $baseFamily = self::familyId($input['baseFamily'] ?? null, $defaults['baseFamily']);
        $headingFamily = self::familyId($input['headingFamily'] ?? null, $baseFamily);

        $scale = is_array($input['scale'] ?? null) ? $input['scale'] : [];
        $breakpoints = is_array($input['breakpoints'] ?? null) ? $input['breakpoints'] : [];

        $out = [
            'version' => self::VERSION,
            'baseFamily' => $baseFamily,
            'headingFamily' => $headingFamily,
            'scale' => [
                'baseSize' => self::clamp($scale['baseSize'] ?? null, 'baseSize', $defaults['scale']['baseSize']),
                'ratio' => self::clamp($scale['ratio'] ?? null, 'ratio', $defaults['scale']['ratio']),
                'minViewport' => self::clamp($scale['minViewport'] ?? null, 'viewport', $defaults['scale']['minViewport']),
                'maxViewport' => self::clamp($scale['maxViewport'] ?? null, 'viewport', $defaults['scale']['maxViewport']),
            ],
            'breakpoints' => [
                'tablet' => self::intInRange($breakpoints['tablet'] ?? null, 480, 1600, $defaults['breakpoints']['tablet']),
                'mobile' => self::intInRange($breakpoints['mobile'] ?? null, 320, 1024, $defaults['breakpoints']['mobile']),
            ],
            'roles' => [],
            'overrides' => ['section' => [], 'component' => [], 'page' => [], 'placement' => []],
            'presets' => [],
        ];

        // نافذة اللزوجة يجب أن تكون مرتّبة، وإلّا صارت clamp() بلا معنى.
        if ($out['scale']['minViewport'] >= $out['scale']['maxViewport']) {
            $out['scale']['minViewport'] = $defaults['scale']['minViewport'];
            $out['scale']['maxViewport'] = $defaults['scale']['maxViewport'];
        }
        // نقطة الجوال تحت نقطة اللوحي دائمًا.
        if ($out['breakpoints']['mobile'] >= $out['breakpoints']['tablet']) {
            $out['breakpoints'] = $defaults['breakpoints'];
        }

        $roles = is_array($input['roles'] ?? null) ? $input['roles'] : [];
        foreach (self::ROLES as $role) {
            $fallbackFamily = str_starts_with($role, 'h') && strlen($role) === 2
                ? $headingFamily
                : $baseFamily;

            $out['roles'][$role] = self::normalizeRole(
                is_array($roles[$role] ?? null) ? $roles[$role] : [],
                $defaults['roles'][$role],
                $fallbackFamily,
            );
        }

        $out['overrides'] = self::normalizeOverrides($input['overrides'] ?? null, $out['roles']);
        $out['presets'] = self::normalizePresets($input['presets'] ?? null, $out['roles']);
        $out['tokens'] = self::normalizeTokens($input['tokens'] ?? null);

        return $out;
    }

    /**
     * رموز الاستوديو: الكتالوج المُغلق وحده يمرّ، وكل قيمة تمرّ بقواعد
     * نوعها في TokenCatalog. الأسماء المجهولة تُسقَط بصمت (كتالوج مُغلق)،
     * والقيم الفاسدة تعود للموروث بدل أن تبلغ CSS.
     *
     * @return array<string, string|int|float>
     */
    private static function normalizeTokens(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $out = [];
        foreach (TokenCatalog::CATALOG as $name => $def) {
            if (! array_key_exists($name, $input)) {
                continue; // الغياب = وراثة.
            }
            $value = TokenCatalog::normalizeValue($name, $input[$name]);
            if ($value !== null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    private static function normalizeRole(array $input, array $default, string $fallbackFamily): array
    {
        $family = self::familyIdOrNull($input['family'] ?? null);
        $effectiveFamily = $family ?? $fallbackFamily;

        $size = is_array($input['size'] ?? null) ? $input['size'] : [];
        $normalizedSize = [];
        foreach (self::DEVICES as $device) {
            $normalizedSize[$device] = self::clamp(
                $size[$device] ?? null, 'size', $default['size'][$device],
            );
        }

        // min/max اختياريان: null يعني «اشتقّهما»، وقيمة تعني حدًّا صريحًا.
        $min = isset($size['min']) && is_numeric($size['min'])
            ? self::clamp($size['min'], 'size', $normalizedSize['mobile']) : null;
        $max = isset($size['max']) && is_numeric($size['max'])
            ? self::clamp($size['max'], 'size', $normalizedSize['desktop']) : null;
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $normalizedSize['min'] = $min;
        $normalizedSize['max'] = $max;
        $normalizedSize['fluid'] = (bool) ($size['fluid'] ?? $default['size']['fluid']);

        $weight = is_numeric($input['weight'] ?? null)
            ? (int) $input['weight']
            : $default['weight'];

        return [
            'family' => $family,
            'size' => $normalizedSize,
            // الوزن يُقنَص إلى ما يوفّره الخطّ فعلًا — لا ضابط بلا تنفيذ خلفه.
            'weight' => FontCatalog::snapWeight($effectiveFamily, $weight),
            'style' => self::oneOf($input['style'] ?? null, self::STYLES, $default['style']),
            'lineHeight' => self::clamp($input['lineHeight'] ?? null, 'lineHeight', $default['lineHeight']),
            'letterSpacing' => self::clamp($input['letterSpacing'] ?? null, 'letterSpacing', $default['letterSpacing']),
            'wordSpacing' => self::clamp($input['wordSpacing'] ?? null, 'wordSpacing', $default['wordSpacing']),
            'align' => self::oneOfOrNull($input['align'] ?? null, self::ALIGNS),
            'transform' => self::oneOf($input['transform'] ?? null, self::TRANSFORMS, $default['transform']),
            'decoration' => self::oneOf($input['decoration'] ?? null, self::DECORATIONS, $default['decoration']),
            'color' => self::color($input['color'] ?? null),
        ];
    }

    /**
     * تجاوزات جزئية: تُحفظ الخصائص المذكورة فقط، فيبقى الباقي موروثًا.
     *
     * ─── النطاق الثالث: الموضع ───
     *
     * `section` يطال القسم، و`component` يطال المكوّن **أينما وُضع** — وهو ما
     * يجعل بطاقة الرئيسية وبطاقة الأرشيف شيئًا واحدًا لا ينفصلان. و`placement`
     * يقاطعهما: معرّفه `قسم__مكوّن`، ويُبثّ محدّدًا متحدِّرًا فيطال المكوّن
     * داخل ذلك القسم وحده.
     *
     * والفاصل `__` لا `@` ولا `/`: `identifier()` يقبل الشرطة السفليّة، فالمعرّف
     * المركّب يبقى صالحًا في محدّد CSS وفي مفتاح تخزين بلا ترميزٍ ثانٍ.
     *
     * @param  array<string, array<string, mixed>>  $roles
     *                                                      ─── والنطاق الرابع: الصفحة ───
     *
     * القسم لا وجود له خارج الرئيسية، فكان كلّ ما عداها بلا نطاق. و`page`
     * يعطي كلّ صفحةٍ نطاقًا كما للقسم نطاقه، ومعرّفه من قائمة مغلقة يشتقّها
     * المتجر من المسار.
     *
     * وشقّ الموضع الأوّل يقبل الاثنين — قسمًا أو صفحة — فيُبثّ له محدّدان
     * ويطابق الموجود منهما. ولا التباس: مفاتيح الصفحات مفصولة عن معرّفات
     * الأقسام بالبناء.
     * @return array{section: array<string, mixed>, component: array<string, mixed>, page: array<string, mixed>, placement: array<string, mixed>}
     */
    private static function normalizeOverrides(mixed $input, array $roles): array
    {
        $out = ['section' => [], 'component' => [], 'page' => [], 'placement' => []];
        if (! is_array($input)) {
            return $out;
        }

        foreach (['section', 'component', 'page', 'placement'] as $scope) {
            $scoped = $input[$scope] ?? null;
            if (! is_array($scoped)) {
                continue;
            }

            foreach ($scoped as $targetId => $roleMap) {
                $id = $scope === 'placement'
                    ? self::placementId((string) $targetId)
                    : self::identifier((string) $targetId);
                if ($id === null || ! is_array($roleMap)) {
                    continue;
                }

                $entry = [];
                foreach ($roleMap as $role => $patch) {
                    if (! is_array($patch)) {
                        continue;
                    }

                    /*
                     * `box` ليس دورًا: هو صندوق الهدف نفسه — خلفيّته وحشوته
                     * واستدارته. أُضيف لأنّ الوثيقة كانت تيبوغرافية بحتة،
                     * فكان محرّر القسم يرسم «نوع الخلفية» و«استدارة الحواف»
                     * و«الحشوة العلوية والسفلية» معطّلةً بسببٍ مكتوب واحد:
                     * لا مفتاح لها. وهذه مفاتيحها.
                     *
                     * ولا تصادم مع الأدوار: `ROLES` قائمة مغلقة ليس فيها
                     * `box`، فالمفتاح مميَّز بنيويًّا لا بالاصطلاح.
                     */
                    if ($role === 'box') {
                        $box = self::normalizeBox($patch);
                        if ($box !== []) {
                            $entry['box'] = $box;
                        }

                        continue;
                    }

                    if (! in_array($role, self::ROLES, true)) {
                        continue;
                    }

                    // يُطبَّع مقابل الدور العام ثم يُقلَّم إلى المذكور فقط.
                    $full = self::normalizeRole($patch, $roles[$role], FontCatalog::DEFAULT_ID);
                    $partial = array_intersect_key($full, $patch);
                    if ($partial !== []) {
                        $entry[$role] = $partial;
                    }
                }

                if ($entry !== []) {
                    $out[$scope][$id] = $entry;
                }
            }
        }

        return $out;
    }

    /**
     * حدود صندوق الهدف — أربعة مفاتيح، كلٌّ منها محروس.
     *
     * لا قيمة حرّة تصل CSS: الخلفيّة تمرّ بـ`color()` نفسها (سداسيّ أو إحالة
     * إلى رمزٍ موجود، لا تعبير حرّ)، والأرقام تُقصّ إلى مدًى مُعلَن. قيمةٌ حرّة
     * هنا تعني حقن أنماط عبر لوحة الإدارة.
     *
     * والمفتاح الغائب يبقى غائبًا — «موروث» يعني ألّا يُكتب إعلانٌ أصلًا، لا
     * أن يُكتب بقيمةٍ افتراضيّة تُجمّد نسخةً تتقادم.
     *
     * @return array<string, string|float>
     */
    private static function normalizeBox(array $input): array
    {
        $out = [];

        if (array_key_exists('background', $input)) {
            $background = self::color($input['background']);
            if ($background !== null) {
                $out['background'] = $background;
            }
        }

        /** المدى مُعلَن هنا لا في الواجهة: الواجهة تعرضه، والخادم يفرضه. */
        $ranges = [
            'radius' => [0.0, 64.0],
            'padBlock' => [0.0, 160.0],
            'padInline' => [0.0, 160.0],
        ];

        foreach ($ranges as $key => [$min, $max]) {
            if (! array_key_exists($key, $input) || ! is_numeric($input[$key])) {
                continue;
            }

            $out[$key] = max($min, min($max, (float) $input[$key]));
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $roles
     * @return list<array{id:string, name:string, roles:array<string, mixed>}>
     */
    private static function normalizePresets(mixed $input, array $roles): array
    {
        if (! is_array($input)) {
            return [];
        }

        $out = [];
        foreach (array_slice($input, 0, 24) as $preset) {
            if (! is_array($preset)) {
                continue;
            }

            $id = self::identifier((string) ($preset['id'] ?? ''));
            $name = trim((string) ($preset['name'] ?? ''));
            if ($id === null || $name === '') {
                continue;
            }

            $presetRoles = [];
            $source = is_array($preset['roles'] ?? null) ? $preset['roles'] : [];
            foreach (self::ROLES as $role) {
                if (is_array($source[$role] ?? null)) {
                    $presetRoles[$role] = self::normalizeRole(
                        $source[$role], $roles[$role], FontCatalog::DEFAULT_ID,
                    );
                }
            }

            if ($presetRoles !== []) {
                $out[] = ['id' => $id, 'name' => mb_substr($name, 0, 60), 'roles' => $presetRoles];
            }
        }

        return $out;
    }

    /**
     * الخطوط والأوزان المستعملة فعلًا في الوثيقة — يُبنى منها رابط المزوّد،
     * فلا يُحمَّل وزن لا يظهر على الشاشة ولا يُترك وزن يظهر بلا تحميل.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, list<int>>
     */
    public static function fontUsage(array $document): array
    {
        $usage = [];
        $add = static function (string $family, int $weight) use (&$usage): void {
            $usage[$family] ??= [];
            if (! in_array($weight, $usage[$family], true)) {
                $usage[$family][] = $weight;
            }
        };

        foreach (self::ROLES as $role) {
            $spec = $document['roles'][$role] ?? null;
            if (! is_array($spec)) {
                continue;
            }

            $isHeading = str_starts_with($role, 'h') && strlen($role) === 2;
            $family = $spec['family'] ?? ($isHeading ? $document['headingFamily'] : $document['baseFamily']);
            $add((string) $family, (int) $spec['weight']);
        }

        // التجاوزات قد تُدخل عائلة أو وزنًا لا يظهران في الأدوار العامة.
        foreach (['section', 'component'] as $scope) {
            foreach ($document['overrides'][$scope] ?? [] as $roleMap) {
                foreach ($roleMap as $role => $patch) {
                    $isHeading = str_starts_with((string) $role, 'h') && strlen((string) $role) === 2;
                    $family = $patch['family']
                        ?? $document['roles'][$role]['family']
                        ?? ($isHeading ? $document['headingFamily'] : $document['baseFamily']);
                    $weight = $patch['weight'] ?? $document['roles'][$role]['weight'] ?? 400;
                    $add((string) $family, (int) $weight);
                }
            }
        }

        foreach ($usage as &$weights) {
            sort($weights);
        }

        return $usage;
    }

    // ───── مساعدات التطبيع ─────

    private static function familyId(mixed $value, string $fallback): string
    {
        return is_string($value) && FontCatalog::has($value) ? $value : $fallback;
    }

    private static function familyIdOrNull(mixed $value): ?string
    {
        return is_string($value) && FontCatalog::has($value) ? $value : null;
    }

    private static function clamp(mixed $value, string $limit, float $fallback): float
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        [$min, $max] = self::LIMITS[$limit];

        return round(max($min, min($max, (float) $value)), 3);
    }

    private static function intInRange(mixed $value, int $min, int $max, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return (int) max($min, min($max, (int) $value));
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    /** @param list<string> $allowed */
    private static function oneOfOrNull(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * لون صالح فقط. `null` يعني «ورِث»، وأي شيء آخر يُرفض بدل حقنه في CSS:
     * قيمة حرّة هنا تعني حقن أنماط عبر لوحة الإدارة.
     */
    private static function color(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        // ‎#rgb / #rrggbb / #rrggbbaa
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $trimmed) === 1) {
            return strtolower($trimmed);
        }

        // إحالة إلى رمز تصميم موجود فقط — لا تعبير CSS حرّ.
        if (preg_match('/^var\(--[a-z0-9-]{1,40}\)$/', $trimmed) === 1) {
            return $trimmed;
        }

        return null;
    }

    /**
     * معرّف موضع: `قسم__مكوّن`، وكلا شقّيه معرّفٌ صالح.
     *
     * النصفان يُفحصان لا الكلّ: `identifier()` وحده يقبل `a__b` لأن الشرطة
     * السفليّة مسموحة فيه، فيمرّ `__card` و`featured__` و`a__b__c` بلا معنًى —
     * وثلاثتها تُنتج محدّدًا لا يطابق شيئًا أو يطابق أكثر ممّا يُقصد.
     */
    private static function placementId(string $value): ?string
    {
        $parts = explode('__', trim($value));
        if (count($parts) !== 2) {
            return null;
        }

        $section = self::identifier($parts[0]);
        $component = self::identifier($parts[1]);

        return $section === null || $component === null ? null : $section.'__'.$component;
    }

    /** معرّف آمن للاستعمال في محدّد CSS ومفتاح تخزين. */
    private static function identifier(string $value): ?string
    {
        $trimmed = trim($value);

        return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $trimmed) === 1 ? $trimmed : null;
    }
}
