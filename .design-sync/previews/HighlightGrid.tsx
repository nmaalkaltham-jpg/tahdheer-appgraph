/**
 * HighlightGrid — شبكة إبرازٍ للوجهات.
 *
 * ─── القانون الذي تُظهره الخلايا ───
 *
 * `href` اختياريّة، وغيابُها يُصيَّر عنصرًا **غير تفاعليّ** — لا رابط بلا
 * وجهة. وهي القاعدة نفسها التي تحكم زرّ البطاقة في `ProductCard`، ولذلك
 * خُصّصت لها خليّة: عنصرٌ يبدو قابلًا للنقر ولا يقود إلى شيء يُعلّم الزائر
 * ألّا يثق بالشبكة كلّها.
 *
 * ─── والأيقونات من المستهلك لا من الحزمة ───
 *
 * `icon` عقدة React يمرّرها المستدعي: الحزمة **لا تشحن مكتبة أيقونات**
 * (القاعدة نفسها المكتوبة في `AdminNavItem.icon`). فالأيقونات أدناه مسارات
 * SVG مكتوبة هنا على شبكة 24×24 — وهو بالضبط ما سيفعله المستهلك.
 *
 * ─── والمحتوى وجهاتٌ حقيقيّة ───
 *
 * المراحل والمواد والصيغ مساراتٌ يخدمها المتجر فعلًا (`/stages`، `/subjects`،
 * `/kinds`)، لا عناوين مخترَعة.
 */

import { HighlightGrid, type HighlightGridItem } from '@tahdheer/ui';

/** أيقونة على شبكة 24×24 — يمرّرها المستهلك، ولا تشحنها الحزمة. */
function Glyph({ d }: { d: string }) {
  return (
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d={d} strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

const ITEMS: readonly HighlightGridItem[] = [
  {
    id: 'stages',
    title: 'المراحل الدراسيّة',
    description: 'ابتدائي ومتوسط وثانوي — ابدأ من مرحلتك ثمّ ضيّق بالصفّ والمادّة.',
    href: '/stages',
    icon: <Glyph d="M3 8l9-4 9 4-9 4-9-4zM7 11v4c0 1.5 2.2 3 5 3s5-1.5 5-3v-4" />,
  },
  {
    id: 'subjects',
    title: 'المواد الدراسيّة',
    description: 'الرياضيات واللغة العربيّة والعلوم والإنجليزيّة وغيرها، لكلّ صفّ.',
    href: '/subjects',
    icon: <Glyph d="M4 4h10a3 3 0 013 3v13H7a3 3 0 01-3-3V4zM7 8h7M7 12h7" />,
  },
  {
    id: 'kinds',
    title: 'أنواع الملفّات',
    description: 'تحاضير وعروض وأوراق عمل واختبارات — اختر ما تحتاجه للحصّة.',
    href: '/kinds',
    icon: <Glyph d="M6 3h8l4 4v14H6zM14 3v4h4M9 13h6M9 17h4" />,
  },
];

const cell: React.CSSProperties = { direction: 'rtl', maxWidth: 720 };

/** ثلاثة أعمدة — الشكل الافتراضيّ لشريط الوجهات في الرئيسة. */
export function ThreeColumns() {
  return (
    <div style={cell}>
      <HighlightGrid items={ITEMS} columns={3} />
    </div>
  );
}

/** عمودان — حين تُعرض الشبكة داخل عمودٍ أضيق. والجوّال عمودٌ واحد دائمًا. */
export function TwoColumns() {
  return (
    <div style={{ ...cell, maxWidth: 520 }}>
      <HighlightGrid items={ITEMS.slice(0, 2)} columns={2} />
    </div>
  );
}

/**
 * بلا `href` — عنصرٌ غير تفاعليّ.
 *
 * يُعرض كبطاقةٍ ساكنة لا رابط: هذا ما يفعله المكوّن حين لا وجهة، وهو أصدق
 * من رابطٍ يقود إلى لا شيء.
 */
export function NonInteractive() {
  return (
    <div style={{ ...cell, maxWidth: 520 }}>
      <HighlightGrid
        items={[
          {
            id: 'soon-lessons',
            title: 'بنك الدروس',
            description: 'قيد الإعداد — يُفتح حين تكتمل فهرسة الدروس، ولا رابط له اليوم.',
            icon: <Glyph d="M12 3v18M3 12h18" />,
          },
          {
            id: 'soon-plans',
            title: 'الخطط الفصليّة',
            description: 'قيد الإعداد — لا وجهة بعد، فالبطاقة تُعرض ساكنةً بلا نقر.',
            icon: <Glyph d="M4 5h16v14H4zM4 9h16" />,
          },
        ]}
        columns={2}
      />
    </div>
  );
}
