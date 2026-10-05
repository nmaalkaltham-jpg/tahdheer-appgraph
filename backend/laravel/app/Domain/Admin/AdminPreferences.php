<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use Illuminate\Validation\ValidationException;

/**
 * مخطّط وثيقة تفضيلات المشغّل — ما يُقبل، وما يُسقط، وما يُرفض.
 *
 * ─── ثلاثة أجوبةٍ لا جوابان ───
 *
 * المفتاح **المجهول** يُسقط صامتًا: واجهةٌ أحدث من الخادم ترسل حقلًا لم
 * يُعرَّف بعد، ورفضُ الوثيقة كلّها لأجله يُفقد المشغّل تخطيطه كلّه. والقيمة
 * **الخاطئة** لمفتاحٍ معروف تُرفض ٤٢٢ باسم حقلها: `w: "كبير"` ليس حقلًا
 * جديدًا بل عطلٌ عند المُرسِل، وقبولُه يجعل الخادم يحفظ ما لا تقرؤه واجهة.
 * والحجم يُحدّ قبل ذلك كلّه في المتحكّم (٤١٣).
 *
 * ─── لماذا لا يعرف الخادم أسماء البطاقات ───
 *
 * سجلّ البطاقات يعيش في الواجهة ويتغيّر معها. الخادم يفرض **الشكل** (معرّفٌ
 * بصيغةٍ محصورة، أرقامٌ في حدودها، وعددٌ أقصى) — والواجهة تُسقط عند القراءة
 * معرّفًا لم تعد تعرفه. نسخةٌ من السجلّ هنا كانت ستتباعد عن أصلها بعد أوّل
 * بطاقةٍ تُضاف.
 */
final class AdminPreferences
{
    public const MAX_BYTES = 16384;

    private const PRESETS = ['executive', 'sales', 'operations', 'content', 'analytics', 'minimal', 'custom'];

    private const MODES = ['grid', 'canvas'];

    private const DENSITIES = ['comfortable', 'compact'];

    private const MAX_WIDGETS = 64;

    private const WIDGET_ID = '/^[a-z][a-z0-9-]{0,47}$/';

    private const METRIC_KEY = '/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/';

    private const APPEARANCE_KEY = '/^[a-zA-Z][a-zA-Z0-9]{0,39}$/';

    private const MAX_APPEARANCE_KEYS = 32;

    /** المفاتيح المعروفة في المظهر وقيمها — ما سواها قيمةٌ بسيطة محدودة. */
    private const APPEARANCE_ENUMS = [
        'theme' => ['dark', 'light', 'system'],
        'density' => self::DENSITIES,
        'layout' => ['sidebar', 'rail', 'topbar', 'split', 'focus'],
        'accent' => ['teal', 'navy', 'gold'],
        'radius' => ['compact', 'default', 'soft'],
    ];

    /** مؤثّرات المظهر (`appearance.effects`) — كائنٌ بمستوًى واحد، والمجهول يُسقط. */
    private const EFFECT_ENUMS = [
        'glow' => ['on', 'off'],
        'shine' => ['on', 'off'],
        'glass' => ['on', 'off'],
        'ambient' => ['on', 'off'],
        'shadows' => ['off', 'low', 'medium', 'high'],
        'intensity' => ['off', 'low', 'medium', 'high'],
        'motion' => ['off', 'reduced', 'full'],
    ];

    /**
     * يُنقّي قسمًا واحدًا بحسب اسمه. `null` يعني «احذف القسم» ويمرّ كما هو.
     *
     * @return array<string, mixed>|null
     */
    public static function section(string $name, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return match ($name) {
            'appearance' => self::appearance($value),
            'dashboard' => self::dashboard($value),
        };
    }

    /** الأقسام المعروفة — ما سواها في جسم الطلب يُسقط. */
    public static function sections(): array
    {
        return ['appearance', 'dashboard'];
    }

    /** @return array<string, mixed> */
    private static function appearance(mixed $value): array
    {
        $input = self::object($value, 'appearance');
        $out = [];

        foreach ($input as $key => $item) {
            if (! is_string($key) || preg_match(self::APPEARANCE_KEY, $key) !== 1) {
                continue;
            }

            if ($key === 'effects') {
                $effects = self::object($item, 'appearance.effects');
                $out['effects'] = [];
                foreach (self::EFFECT_ENUMS as $effect => $allowed) {
                    if (array_key_exists($effect, $effects)) {
                        $out['effects'][$effect] = self::enum($effects[$effect], $allowed, "appearance.effects.{$effect}");
                    }
                }

                continue;
            }

            $enum = self::APPEARANCE_ENUMS[$key] ?? null;

            if ($enum !== null) {
                $out[$key] = self::enum($item, $enum, "appearance.{$key}");

                continue;
            }

            if (count($out) >= self::MAX_APPEARANCE_KEYS) {
                break;
            }

            /*
             * مفتاحٌ لم يُعرَّف بعد يُقبل **قيمةً بسيطة** فقط: شريحة المظهر
             * تُضيف خياراتها (لونٌ، حجمُ خطّ) دون انتظار الخادم. والكائن
             * المتداخل يُسقط — لا بنيةَ اعتباطيّة تُحفظ بلا مخطّط.
             */
            if (is_bool($item) || is_int($item) || (is_float($item) && is_finite($item))) {
                $out[$key] = $item;
            } elseif (is_string($item) && mb_strlen($item) <= 64) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function dashboard(mixed $value): array
    {
        $input = self::object($value, 'dashboard');
        $out = [];

        if (array_key_exists('preset', $input)) {
            $out['preset'] = self::enum($input['preset'], self::PRESETS, 'dashboard.preset');
        }

        if (array_key_exists('mode', $input)) {
            $out['mode'] = self::enum($input['mode'], self::MODES, 'dashboard.mode');
        }

        if (array_key_exists('density', $input)) {
            $out['density'] = self::enum($input['density'], self::DENSITIES, 'dashboard.density');
        }

        if (array_key_exists('snap', $input)) {
            $out['snap'] = self::bool($input['snap'], 'dashboard.snap');
        }

        foreach (['widgets', 'custom'] as $map) {
            if (array_key_exists($map, $input)) {
                $out[$map] = self::widgetMap($input[$map], "dashboard.{$map}", false);
            }
        }

        if (array_key_exists('canvas', $input)) {
            $out['canvas'] = self::widgetMap($input['canvas'], 'dashboard.canvas', true);
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private static function widgetMap(mixed $value, string $path, bool $canvas): array
    {
        $input = self::object($value, $path);

        if (count($input) > self::MAX_WIDGETS) {
            self::fail($path, 'عدد البطاقات يتجاوز الحدّ ('.self::MAX_WIDGETS.').');
        }

        $out = [];

        foreach ($input as $id => $state) {
            if (! is_string($id) || preg_match(self::WIDGET_ID, $id) !== 1) {
                self::fail($path, 'معرّف بطاقةٍ بصيغةٍ غير مقبولة.');
            }

            $out[$id] = $canvas
                ? self::canvasState($state, "{$path}.{$id}")
                : self::gridState($state, "{$path}.{$id}");
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function gridState(mixed $value, string $path): array
    {
        $input = self::object($value, $path);
        $out = [];

        foreach (['x' => [0, 11], 'y' => [0, 999], 'w' => [1, 12], 'h' => [1, 48], 'order' => [0, 999]] as $key => [$min, $max]) {
            if (array_key_exists($key, $input)) {
                $out[$key] = self::int($input[$key], $min, $max, "{$path}.{$key}");
            }
        }

        foreach (['hidden', 'locked'] as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = self::bool($input[$key], "{$path}.{$key}");
            }
        }

        if (array_key_exists('type', $input)) {
            $type = $input['type'];
            if (! is_string($type) || preg_match(self::WIDGET_ID, $type) !== 1) {
                self::fail("{$path}.type", 'نوع البطاقة بصيغةٍ غير مقبولة.');
            }
            $out['type'] = $type;
        }

        if (array_key_exists('settings', $input)) {
            $settings = self::object($input['settings'], "{$path}.settings");
            $clean = [];
            if (array_key_exists('metric', $settings)) {
                $metric = $settings['metric'];
                if (! is_string($metric) || preg_match(self::METRIC_KEY, $metric) !== 1) {
                    self::fail("{$path}.settings.metric", 'مفتاح المؤشّر بصيغةٍ غير مقبولة.');
                }
                $clean['metric'] = $metric;
            }
            $out['settings'] = $clean;
        }

        return $out;
    }

    /** @return array<string, int> */
    private static function canvasState(mixed $value, string $path): array
    {
        $input = self::object($value, $path);
        $out = [];

        foreach (['x' => [0, 8000], 'y' => [0, 20000], 'w' => [40, 8000], 'h' => [40, 8000]] as $key => [$min, $max]) {
            if (array_key_exists($key, $input)) {
                $out[$key] = self::int($input[$key], $min, $max, "{$path}.{$key}");
            }
        }

        return $out;
    }

    /** @return array<array-key, mixed> */
    private static function object(mixed $value, string $path): array
    {
        /* قائمةٌ JSON (`[]` غير الفارغة) ليست كائنًا — والفارغة تُقرأ كائنًا فارغًا. */
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            self::fail($path, 'يجب أن تكون القيمة كائنًا.');
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed, string $path): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            self::fail($path, 'القيمة المُرسلة غير مسموح بها.');
        }

        return $value;
    }

    private static function bool(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            self::fail($path, 'يجب أن تكون القيمة نعم أو لا.');
        }

        return $value;
    }

    private static function int(mixed $value, int $min, int $max, string $path): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            self::fail($path, "يجب أن تكون القيمة رقمًا صحيحًا بين {$min} و{$max}.");
        }

        return $value;
    }

    private static function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => [$message]]);
    }
}
