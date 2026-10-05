<?php

declare(strict_types=1);

namespace App\Domain\Theme;

/**
 * كتالوج رموز الاستوديو — النصف PHP من C27 (عقد الرموز — وثيقته محذوفة).
 *
 * كتالوج مُغلق: ٢٧ رمزًا (٢٠ لونًا · ٥ استدارات · ظلّان). المرآة الملزِمة في
 * TS هي packages/registry/src/tokens.ts — ولا حارس يطابقهما اليوم (حُذف
 * مع الاستوديو ٢٠٢٦-٠٩-١٧)؛ الضمان الوحيد عدد ٢٧ المكتوب
 * باليد في ThemeTokensTest::test_the_catalog_is_closed_at_twenty_seven_mirroring_ts.
 *
 * القيم الواردة تمرّ بقواعد نوعها قبل أن تبلغ CSS: لون = hex فقط (لا var()
 * — مرجعُ رمزٍ لرمز يصنع دورات)، استدارة = عدد بكسل داخل الحدود، ظلّ =
 * نحو box-shadow مقيَّد (أطوال px وألوان hex/rgba فقط — لا url ولا نص حرّ).
 */
final class TokenCatalog
{
    /**
     * الكتالوج بترتيب tokens.ts نفسه — وهو ترتيب البثّ في ThemeCss.
     *
     * @var array<string, array<string, mixed>>
     */
    public const CATALOG = [
        // ── ألوان العلامة (٤) ──
        'green-700' => ['kind' => 'color', 'label' => 'الأخضر الأساسي', 'group' => 'brand'],
        'green-deep' => ['kind' => 'color', 'label' => 'الأخضر العميق', 'group' => 'brand'],
        'green-hover' => ['kind' => 'color', 'label' => 'أخضر التمرير', 'group' => 'brand'],
        'green-bright' => ['kind' => 'color', 'label' => 'الأخضر الساطع', 'group' => 'brand'],
        // ── ألوان النص (٤) ──
        'ink' => ['kind' => 'color', 'label' => 'حبر العناوين', 'group' => 'text'],
        'text' => ['kind' => 'color', 'label' => 'نص المتن', 'group' => 'text'],
        'text-secondary' => ['kind' => 'color', 'label' => 'النص الثانوي', 'group' => 'text'],
        'text-muted' => ['kind' => 'color', 'label' => 'النص الخافت', 'group' => 'text'],
        // ── الذهبي (٣) ──
        'gold-500' => ['kind' => 'color', 'label' => 'الذهبي', 'group' => 'gold'],
        'gold-text' => ['kind' => 'color', 'label' => 'ذهبي النص', 'group' => 'gold'],
        'gold-light' => ['kind' => 'color', 'label' => 'الذهبي الفاتح', 'group' => 'gold'],
        // ── الأسطح (٦) ──
        'bg' => ['kind' => 'color', 'label' => 'الخلفية', 'group' => 'surface'],
        'bg-page' => ['kind' => 'color', 'label' => 'خلفية الصفحة', 'group' => 'surface'],
        'bg-2' => ['kind' => 'color', 'label' => 'الخلفية الثانية', 'group' => 'surface'],
        'bg-soft' => ['kind' => 'color', 'label' => 'الخلفية الرخوة', 'group' => 'surface'],
        'bg-mist' => ['kind' => 'color', 'label' => 'خلفية الضباب', 'group' => 'surface'],
        'bg-green-light' => ['kind' => 'color', 'label' => 'الخلفية الخضراء الفاتحة', 'group' => 'surface'],
        // ── الحدود (٣) ──
        'border' => ['kind' => 'color', 'label' => 'لون الحدود', 'group' => 'border'],
        'border-hairline' => ['kind' => 'color', 'label' => 'الحدود الشعرية', 'group' => 'border'],
        'border-strong' => ['kind' => 'color', 'label' => 'الحدود الواضحة', 'group' => 'border'],
        // ── الاستدارات (٥) ──
        'radius-sm' => ['kind' => 'radius', 'label' => 'استدارة صغيرة', 'group' => 'radius', 'min' => 0, 'max' => 48],
        'radius-md' => ['kind' => 'radius', 'label' => 'استدارة متوسطة', 'group' => 'radius', 'min' => 0, 'max' => 48],
        'radius-card' => ['kind' => 'radius', 'label' => 'استدارة البطاقات', 'group' => 'radius', 'min' => 0, 'max' => 48],
        'radius-lg' => ['kind' => 'radius', 'label' => 'استدارة كبيرة', 'group' => 'radius', 'min' => 0, 'max' => 48],
        'radius-hero' => ['kind' => 'radius', 'label' => 'استدارة الهيرو', 'group' => 'radius', 'min' => 0, 'max' => 48],
        // ── الظلال (٢) ──
        'shadow-card' => ['kind' => 'shadow', 'label' => 'ظل البطاقة', 'group' => 'shadow'],
        'shadow-float' => ['kind' => 'shadow', 'label' => 'الظل المرتفع', 'group' => 'shadow'],
    ];

    /** مجموعات اللوحة — مرآة TOKEN_GROUPS في tokens.ts. @var array<string, string> */
    public const GROUPS = [
        'brand' => 'ألوان العلامة',
        'text' => 'ألوان النص',
        'gold' => 'الذهبي',
        'surface' => 'الأسطح',
        'border' => 'الحدود',
        'radius' => 'الاستدارات',
        'shadow' => 'الظلال',
    ];

    /** hex كامل فقط: ‎#rrggbb أو ‎#rrggbbaa — لا اختصار ولا أسماء ألوان. */
    private const HEX = '/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/';

    /** طبقة ظلّ واحدة: 2-4 أطوال px ثم لون hex أو rgb/rgba رقمي. */
    private const SHADOW_LEN = '(?:-?\d{1,4}(?:\.\d{1,2})?px|0)';

    private const SHADOW_COLOR = '(?:#[0-9a-f]{6}(?:[0-9a-f]{2})?|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d{1,3})\s*)?\))';

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function has(string $name): bool
    {
        return isset(self::CATALOG[$name]);
    }

    /** صحّة مرجع رمز: الاسم في الكتالوج ونوعه مطابق — مرآة isTokenOfKind. */
    public static function isKind(string $name, string $kind): bool
    {
        return (self::CATALOG[$name]['kind'] ?? null) === $kind;
    }

    /**
     * يطبّع قيمة رمز واردة وفق نوعه — null يعني قيمة مرفوضة (تُسقَط).
     */
    public static function normalizeValue(string $name, mixed $value): string|int|float|null
    {
        $def = self::CATALOG[$name] ?? null;
        if ($def === null) {
            return null;
        }

        return match ($def['kind']) {
            'color' => is_string($value) && preg_match(self::HEX, trim($value)) === 1
                ? strtolower(trim($value))
                : null,
            'radius' => is_numeric($value)
                ? round(max((float) ($def['min'] ?? 0), min((float) ($def['max'] ?? 48), (float) $value)), 2)
                : null,
            'shadow' => self::normalizeShadow($value),
            default => null,
        };
    }

    /**
     * ظلّ مقيَّد النحو: حتى 4 طبقات، كل طبقة أطوال px ولون مكتوب — لا نصّ
     * حرّ يمكن أن يبلغ CSS من هنا (نفس فلسفة styles.ts: الحقن غير قابل
     * للتعبير بنيويًّا).
     */
    private static function normalizeShadow(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
        if ($trimmed === '' || strlen($trimmed) > 300) {
            return null;
        }
        if ($trimmed === 'none') {
            return 'none';
        }

        $layer = self::SHADOW_LEN.'(?: '.self::SHADOW_LEN.'){1,3} '.self::SHADOW_COLOR;
        $pattern = '/^'.$layer.'(?:\s*,\s*'.$layer.'){0,3}$/';

        return preg_match($pattern, $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * وصف الكتالوج للوحة — تبني ضوابطها منه فلا تُكتب القائمة مرّتين.
     *
     * @return array{groups: list<array{id: string, label: string}>,
     *               tokens: list<array<string, mixed>>}
     */
    public static function describe(): array
    {
        $tokens = [];
        foreach (self::CATALOG as $name => $def) {
            $tokens[] = array_merge(['name' => $name], $def);
        }

        return [
            'groups' => array_map(
                static fn (string $id, string $label): array => ['id' => $id, 'label' => $label],
                array_keys(self::GROUPS),
                array_values(self::GROUPS),
            ),
            'tokens' => $tokens,
        ];
    }
}
