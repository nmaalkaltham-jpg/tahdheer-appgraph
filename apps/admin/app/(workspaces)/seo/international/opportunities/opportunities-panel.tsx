'use client';
// UNIT: OPPORTUNITIES-PANEL
// FILE: opportunities-panel.tsx
// STYLE: none
// PARENT: PAGE-SEO-INTERNATIONAL
// USES: LIB-FORMAT, RESOURCE, _I18N-KIT
// API: /admin/i18n/hreflang-audit, /admin/i18n/overview
// MAP: docs/PROJECT_MAP.md

/**
 * لوحة الفرص الإقليمية ورصد الشواذّ — /seo/international/opportunities.
 *
 * التصميم: Tahdheer - Regional Opportunity.dc.html.
 * كل رقم هنا من نقطتي النهاية القائمتين فعلًا:
 *   · GET /admin/i18n/overview        — اللغات والأعداد وميزانية الروابط.
 *   · GET /admin/i18n/hreflang-audit  — الشواذّ المُثبَتة منتجًا منتجًا.
 *
 * وما لا مصدر له لا يُرسم: الطلب والظهور (لا Search Console مربوط)
 * والتحويلات (لا إسناد لكل لغة) تُعرض «غير متاح» بالاسم — لا صفر يتنكّر
 * كقياس. درجة «الفرصة» في التصميم كانت تحتاج إشارات ملاءمة وقيمة تجارية
 * لا وجود لها في النظام، فالعمود الحقيقي هنا هو التغطية: ترجمات منشورة
 * مقابل منتجات منشورة — عدٌّ من الجداول لا تقدير.
 */

import { useMemo, useState } from 'react';
import { useResource, type ResourceState } from '../../../../../components/resource';
import { count, dateTime } from '../../../../../lib/format';
import { GatedView } from '../_i18n-kit';
import '../../../../../../../.design-sync/tokens-3p/agent-B-opportunities-preview.css';

/* ───────────── أنواع نقطتي النهاية (I18nController) ───────────── */

interface OverviewLocale {
  code: string;
  nameAr: string;
  nameEn: string;
  hreflang: string;
  direction: string;
  urlPrefix: string;
  isDefault: boolean;
  isActive: boolean;
  isRoutable: boolean;
  position: number;
  /** null للّغة الافتراضية: نصّها أعمدة المنتج نفسها. */
  translations: { draft: number; published: number } | null;
  alternateUrls: { expected: number; potentialMax: number };
}

interface Overview {
  publishedProducts: number;
  locales: OverviewLocale[];
}

interface AuditOffender {
  productId: number;
  slug: string | null;
  title: string;
  detail: string;
}

interface AuditCheck {
  key: string;
  label: string;
  pass: boolean;
  offenders: AuditOffender[];
  checkedAt: string;
}

interface AuditReport {
  scannedProducts: number;
  checkedAt: string;
  checks: AuditCheck[];
}

type SortKey = 'coverage' | 'elig' | 'attention' | 'name';

const SORTS: { key: SortKey; label: string }[] = [
  { key: 'coverage', label: 'الأعلى تغطية' },
  { key: 'elig', label: 'الأكثر ملاءمة' },
  { key: 'attention', label: 'يحتاج انتباهًا' },
  { key: 'name', label: 'الدولة' },
];

/** خطورة كل فحص من مفتاحه — تصنيف ثابت لا تقدير. */
const SEVERITY: Record<string, 'critical' | 'high'> = {
  eligibility_gate: 'critical',
  canonical_conflict: 'critical',
  self_reference: 'high',
  x_default: 'high',
  reciprocity: 'high',
};

const NA = 'غير متاح';

/* ───────────── عناصر مساعدة ───────────── */

function Glyph({ d, size = 12 }: { d: string; size?: number }) {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      style={{
        inlineSize: size,
        blockSize: size,
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 2,
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        flexShrink: 0,
      }}
    >
      <path d={d} />
    </svg>
  );
}

const GLOBE = 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3c3 3.4 3 14.6 0 18M12 3c-3 3.4-3 14.6 0 18';
const INFO = 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 11v6M12 7.6v.1';

interface EligibilityChip {
  label: string;
  bg: string;
  fg: string;
  rank: number;
}

/** أهلية اللغة من علمَي الخادم الحقيقيَّين — لا اختراع تصنيف. */
function eligibilityOf(locale: OverviewLocale): EligibilityChip {
  if (!locale.isActive) {
    return { label: 'معطّلة', bg: 'var(--bg-mist)', fg: 'var(--text-muted)', rank: 0 };
  }
  if (!locale.isRoutable) {
    return { label: 'بلا مسارات واجهة', bg: 'var(--intl-tint-gold-20)', fg: 'var(--gold-text)', rank: 1 };
  }
  return { label: 'مؤهَّلة', bg: 'var(--intl-tint-green-14)', fg: 'var(--green-700)', rank: 2 };
}

/** التغطية الحقيقية: صفحات تجتاز بوّابة اللغة (الافتراضية) أو ترجمات منشورة. */
function coverageOf(locale: OverviewLocale): number {
  return locale.isDefault ? locale.alternateUrls.expected : (locale.translations?.published ?? 0);
}

function statusOf(locale: OverviewLocale): string {
  if (locale.isDefault) return 'اللغة الأساسية — x-default';
  if (!locale.isActive) return 'معطّلة من إعدادات اللغات';
  if (!locale.isRoutable) return 'الواجهة لا تخدم مسارها بعد';
  return `نشطة — ${count(locale.alternateUrls.expected)} صفحة تجتاز البوابة`;
}

const cellStyle = {
  paddingBlock: 10,
  paddingInline: 11,
  borderBlockEnd: '1px solid var(--cc-hair)',
  fontSize: 11.5,
  fontWeight: 700,
  textAlign: 'start',
  verticalAlign: 'middle',
} as const;

/* ───────────── الصفحة ───────────── */

export function OpportunitiesPanel() {
  const overview = useResource<{ data: Overview }>('/admin/i18n/overview');
  const audit = useResource<{ data: AuditReport }>('/admin/i18n/hreflang-audit');
  const [sort, setSort] = useState<SortKey>('coverage');

  return (
    <section>

      <p style={{ margin: 0, fontSize: 12.5, lineHeight: 1.75, color: 'var(--cc-muted)', maxWidth: '88ch' }}>
        كل عمود بيانات هنا إمّا رقم حقيقي أو <b>غير متاح</b> — لا أصفار تتنكّر كقياسات.
      </p>

      <div
        role="note"
        style={{
          paddingBlock: 14,
          paddingInline: 16,
          border: '1px solid var(--intl-danger-border-28)',
          borderRadius: 12,
          background: 'var(--danger-bg)',
          marginBlock: 16,
          display: 'flex',
          alignItems: 'flex-start',
          gap: 11,
        }}
      >
        <span style={{ color: 'var(--danger-text)', marginBlockStart: 2 }}>
          <Glyph d={INFO} size={17} />
        </span>
        <div style={{ minWidth: 0 }}>
          <div style={{ fontSize: 13, fontWeight: 900, color: 'var(--danger-text)', marginBlockEnd: 5 }}>
            لا مصدر بيانات بحث مربوط
          </div>
          <p style={{ margin: 0, fontSize: 11.5, lineHeight: 1.8, color: 'var(--text-secondary)', textAlign: 'start' }}>
            لا Search Console ولا تحليلات مربوطة بالمتجر، فأعمدة الطلب والظهور والتحويلات كلها <b>{NA}</b>.
            التغطية أدناه محسوبة من جداول المتجر فعلًا — ترجمات منشورة مقابل منتجات منشورة — ووزن الطلب
            <b> مستبعد</b> من الحساب بدل تقديره.
          </p>
        </div>
      </div>

      <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 9, marginBlockEnd: 14 }}>
        <span style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--text-muted)' }}>رتّب حسب</span>
        <div
          role="group"
          aria-label="ترتيب صفوف اللغات"
          style={{
            display: 'flex',
            flexWrap: 'wrap',
            gap: 4,
            padding: 3,
            borderRadius: 9,
            background: 'var(--cc-glass)',
            border: '1px solid var(--cc-edge)',
          }}
        >
          {SORTS.map((entry) => {
            const active = sort === entry.key;
            return (
              <button
                key={entry.key}
                type="button"
                aria-pressed={active}
                onClick={() => setSort(entry.key)}
                style={{
                  minBlockSize: 44,
                  paddingInline: 12,
                  border: 0,
                  borderRadius: 7,
                  background: active ? 'var(--green-700)' : 'transparent',
                  color: active ? 'var(--on-accent)' : 'var(--text-muted)',
                  fontFamily: 'inherit',
                  fontSize: 11.5,
                  fontWeight: 800,
                  cursor: 'pointer',
                }}
              >
                {entry.label}
              </button>
            );
          })}
        </div>
        <div style={{ flex: 1 }} />
        {/* لا GSC ولا تحليلات ⇒ لا شيء يُحاكى. زرّ التصميم يُعرض معطّلًا بسببه. */}
        <button
          type="button"
          disabled
          title="المحاكاة غير متاحة — لا مصدر بيانات مربوط"
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 7,
            minBlockSize: 44,
            paddingInline: 12,
            borderRadius: 8,
            background: 'var(--cc-glass)',
            border: '1px solid var(--cc-edge)',
            fontFamily: 'inherit',
            fontSize: 12,
            fontWeight: 800,
            color: 'var(--text-muted)',
            cursor: 'not-allowed',
            opacity: 0.7,
          }}
        >
          المحاكاة غير متاحة — لا مصدر بيانات مربوط
        </button>
      </div>

      <GatedView
        state={overview.state}
        reload={overview.reload}
        permission="analytics.read"
        reads="أعمدة الأهلية والتغطية كلّها من ‎GET /admin/i18n/overview‎ (routes/api-admin.php:226-227)"
      >
        {(payload) => (
          <OverviewBlock data={payload.data} sort={sort} />
        )}
      </GatedView>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 14, marginBlockStart: 18 }}>
        <AnomaliesPanel
          state={audit.state}
          reload={audit.reload}
        />
        <GatedView
          state={overview.state}
          reload={overview.reload}
          permission="analytics.read"
          reads="أعداد الترجمات المنشورة مصدرها ‎GET /admin/i18n/overview‎"
        >
          {(payload) => <BudgetPanel data={payload.data} />}
        </GatedView>
      </div>

      <WorkflowPanel />
    </section>
  );
}

/* ───────────── الجدول ───────────── */

function OverviewBlock({ data, sort }: { data: Overview; sort: SortKey }) {
  const rows = useMemo(() => {
    const list = data.locales.map((locale) => ({
      locale,
      elig: eligibilityOf(locale),
      coverage: coverageOf(locale),
    }));

    const byName = (a: (typeof list)[number], b: (typeof list)[number]) =>
      a.locale.nameAr.localeCompare(b.locale.nameAr, 'ar');

    if (sort === 'coverage') list.sort((a, b) => b.coverage - a.coverage || byName(a, b));
    if (sort === 'elig') list.sort((a, b) => b.elig.rank - a.elig.rank || b.coverage - a.coverage);
    if (sort === 'attention') list.sort((a, b) => a.elig.rank - b.elig.rank || a.coverage - b.coverage);
    if (sort === 'name') list.sort(byName);

    return list;
  }, [data, sort]);

  const denominator = data.publishedProducts;

  return (
    <div
      style={{
        border: '1px solid var(--cc-edge)',
        borderRadius: 12,
        overflow: 'hidden',
        background: 'var(--cc-glass)',
      }}
    >
      {/*
       * الطوق داخل بطاقةٍ محدودة أصلًا، فحدّه وخلفيّته يسقطان كي لا يزدوج
       * الإطار. الباقي هو المقصود: تمريرٌ أفقيّ محبوس، وبلوغٌ بلوحة المفاتيح.
       */}
      <div
        className="table-scroll"
        tabIndex={0}
        role="region"
        aria-label="اللغات المسجَّلة وفرصها الإقليمية"
        style={{ border: 0, borderRadius: 0, background: 'transparent' }}
      >
        <table style={{ inlineSize: '100%', borderCollapse: 'collapse' }}>
          <caption className="sr-only">اللغات المسجَّلة وفرصها الإقليمية</caption>
          <thead>
            <tr>
              {['الدولة', 'الأهلية', 'الطلب', 'الظهور', 'التحويلات', 'التغطية', 'الحالة'].map((head) => (
                <th
                  key={head}
                  scope="col"
                  style={{
                    paddingBlock: 10,
                    paddingInline: 11,
                    background: 'var(--cc-hair)',
                    borderBlockEnd: '1px solid var(--border-hairline)',
                    fontSize: 11.5,
                    fontWeight: 900,
                    color: 'var(--text-muted)',
                    textAlign: 'start',
                  }}
                >
                  {head}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td className="empty-cell" colSpan={7}>
                  لا لغات مسجَّلة في جدول اللغات بعد.
                </td>
              </tr>
            ) : (
              rows.map(({ locale, elig, coverage }, index) => {
                const rowBg = elig.rank === 2 ? 'var(--intl-tint-green-04)' : 'var(--bg)';
                const ratio = denominator > 0 ? Math.round((coverage / denominator) * 100) : 0;
                return (
                  <tr key={locale.code} style={{ animation: `intl-fade-up .25s ease ${index * 40}ms both` }}>
                    <th scope="row" style={{ ...cellStyle, background: rowBg, fontWeight: 800 }}>
                      <span style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 }}>
                        <span
                          aria-hidden="true"
                          style={{
                            inlineSize: 22,
                            blockSize: 22,
                            borderRadius: 6,
                            background: elig.rank === 2 ? 'var(--tint-green-12)' : 'var(--bg-mist)',
                            color: elig.rank === 2 ? 'var(--green-700)' : 'var(--text-muted)',
                            display: 'grid',
                            placeItems: 'center',
                            flexShrink: 0,
                          }}
                        >
                          <Glyph d={GLOBE} />
                        </span>
                        <span style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--ink)' }}>{locale.nameAr}</span>
                        <bdi
                          dir="ltr"
                          style={{ fontFamily: 'var(--font-mono)', fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)' }}
                        >
                          {locale.code}
                        </bdi>
                      </span>
                    </th>
                    <td style={{ ...cellStyle, background: rowBg }}>
                      <span
                        style={{
                          display: 'inline-flex',
                          paddingBlock: 2,
                          paddingInline: 8,
                          borderRadius: 5,
                          background: elig.bg,
                          color: elig.fg,
                          fontSize: 11.5,
                          fontWeight: 900,
                          whiteSpace: 'nowrap',
                        }}
                      >
                        {elig.label}
                      </span>
                    </td>
                    <td title="غير متاح — Search Console غير مربوط" style={{ ...cellStyle, background: rowBg, color: 'var(--text-muted)' }}>
                      {NA}
                    </td>
                    <td title="غير متاح — Search Console غير مربوط" style={{ ...cellStyle, background: rowBg, color: 'var(--text-muted)' }}>
                      {NA}
                    </td>
                    <td title="غير متاح — لا إسناد لكل لغة" style={{ ...cellStyle, background: rowBg, color: 'var(--text-muted)' }}>
                      {NA}
                    </td>
                    <td style={{ ...cellStyle, background: rowBg, minWidth: 120 }}>
                      <span style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
                        <span
                          aria-hidden="true"
                          style={{
                            flex: 1,
                            minWidth: 40,
                            blockSize: 6,
                            borderRadius: 999,
                            background: 'var(--border-hairline)',
                            overflow: 'hidden',
                          }}
                        >
                          <span
                            style={{
                              display: 'block',
                              blockSize: '100%',
                              inlineSize: `${ratio}%`,
                              borderRadius: 999,
                              background:
                                ratio >= 70 ? 'var(--green-700)' : ratio >= 45 ? 'var(--gold-muted)' : 'var(--border-strong)',
                              animation: `intl-bar-grow .8s cubic-bezier(.22,1,.36,1) ${index * 50}ms both`,
                            }}
                          />
                        </span>
                        <bdi
                          dir="ltr"
                          style={{ fontFamily: 'var(--font-mono)', fontSize: 11.5, fontWeight: 800, color: 'var(--ink)', flexShrink: 0 }}
                        >
                          {count(coverage)}/{count(denominator)}
                        </bdi>
                      </span>
                    </td>
                    <td style={{ ...cellStyle, background: rowBg, color: 'var(--text-muted)', fontWeight: 500 }}>
                      {statusOf(locale)}
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
      <p style={{ margin: 0, paddingBlock: 9, paddingInline: 11, fontSize: 11.5, lineHeight: 1.7, color: 'var(--text-muted)', borderBlockStart: '1px solid var(--border-hairline)' }}>
        الطلب والظهور: {NA} — Search Console غير مربوط · التحويلات: {NA} — لا إسناد لكل لغة · التغطية: ترجمات
        منشورة (وللأساسية: صفحات تجتاز البوابة) من أصل {count(denominator)} منتجًا منشورًا.
      </p>
    </div>
  );
}

/* ───────────── رصد الشواذّ ───────────── */

function AnomaliesPanel({
  state,
  reload,
}: {
  state: ResourceState<{ data: AuditReport }>;
  reload: () => void;
}) {
  return (
    <section
      aria-label="رصد الشواذّ"
      style={{ paddingBlock: 16, paddingInline: 18, border: '1px solid var(--cc-edge)', borderRadius: 12, background: 'var(--cc-glass)' }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <h2 style={{ margin: 0, fontSize: 14, fontWeight: 900, color: 'var(--ink)', flex: 1 }}>رصد الشواذّ</h2>
        <button type="button" className="btn btn-ghost" onClick={reload}>
          أعد تشغيل التدقيق
        </button>
      </div>
      <p style={{ margin: '4px 0 12px', fontSize: 12, lineHeight: 1.7, color: 'var(--text-muted)' }}>
        المصدر: تدقيق hreflang عند الطلب. لا إصلاح ذاتي في النظام — كل مخالفة تُعالَج يدويًا. سجل المهام
        الخلفية: <b>لا مهام مجدولة</b> — التدقيق يعمل عند الطلب فقط.
      </p>

      <GatedView
        state={state}
        reload={reload}
        permission="analytics.read"
        reads="الفحوص الخمسة تُحسب في ‎GET /admin/i18n/hreflang-audit‎ (routes/api-admin.php:228-229)"
      >
        {(payload) => {
          const report = payload.data;
          const failing = report.checks.filter((check) => !check.pass);
          return (
            <>
              <p style={{ margin: '0 0 10px', fontSize: 11.5, color: 'var(--text-muted)' }}>
                آخر تشغيل: {dateTime(report.checkedAt)} · فُحص {count(report.scannedProducts)} منتجًا منشورًا.
              </p>
              {failing.length === 0 ? (
                <p className="state-ok" style={{ marginBlock: 0 }}>
                  لا شواذّ مرصودة — نجحت الفحوص الخمسة كلها.
                </p>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 7 }}>
                  {failing.map((check) => {
                    const critical = SEVERITY[check.key] === 'critical';
                    return (
                      <div
                        key={check.key}
                        style={{
                          display: 'flex',
                          alignItems: 'flex-start',
                          gap: 9,
                          paddingBlock: 10,
                          paddingInline: 11,
                          border: `1px solid ${critical ? 'var(--intl-danger-border-24)' : 'var(--intl-tint-gold-border-34)'}`,
                          borderRadius: 9,
                          background: critical ? 'var(--danger-bg)' : 'var(--tint-gold-07)',
                        }}
                      >
                        <span
                          style={{
                            flexShrink: 0,
                            paddingBlock: 2,
                            paddingInline: 7,
                            borderRadius: 5,
                            background: critical ? 'var(--intl-tint-danger-12)' : 'var(--intl-tint-gold-22)',
                            color: critical ? 'var(--danger-text)' : 'var(--gold-text)',
                            fontSize: 11.5,
                            fontWeight: 900,
                            whiteSpace: 'nowrap',
                          }}
                        >
                          {critical ? 'حرِج' : 'عالٍ'}
                        </span>
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--ink)', marginBlockEnd: 2, textAlign: 'start' }}>
                            {check.label}
                          </div>
                          <ul style={{ margin: 0, paddingInlineStart: 16, fontSize: 11.5, lineHeight: 1.6, color: 'var(--text-muted)' }}>
                            {check.offenders.slice(0, 3).map((offender) => (
                              <li key={offender.productId}>
                                {offender.title} — {offender.detail}
                              </li>
                            ))}
                          </ul>
                          {check.offenders.length > 3 ? (
                            <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginBlockStart: 3 }}>
                              و{count(check.offenders.length - 3)} مخالفات أخرى في هذا الفحص.
                            </div>
                          ) : null}
                        </div>
                        <span style={{ flexShrink: 0, fontSize: 11.5, fontWeight: 800, color: 'var(--danger-text)' }}>يدوي</span>
                      </div>
                    );
                  })}
                </div>
              )}
            </>
          );
        }}
      </GatedView>
    </section>
  );
}

/* ───────────── ميزانية تضخّم الروابط ───────────── */

function BudgetPanel({ data }: { data: Overview }) {
  const nonDefaultRoutable = data.locales.filter((locale) => !locale.isDefault && locale.isRoutable && locale.isActive).length;
  const expectedTotal = data.locales.reduce((sum, locale) => sum + locale.alternateUrls.expected, 0);
  const potentialMax = data.publishedProducts * data.locales.length;

  const rows: { label: string; value: number; tone: 'plain' | 'good' | 'bad' }[] = [
    { label: 'منتجات منشورة', value: data.publishedProducts, tone: 'plain' },
    { label: 'لغات مفعّلة وقابلة للتوجيه (غير الافتراضية)', value: nonDefaultRoutable, tone: 'good' },
    { label: 'روابط إقليمية متوقّعة', value: expectedTotal, tone: 'good' },
    { label: `لو فُعِّلت الـ${count(data.locales.length)} لغة بلا تصفية`, value: potentialMax, tone: 'bad' },
  ];

  const tones = {
    plain: { fg: 'var(--ink)', border: 'var(--border-hairline)', bg: 'var(--bg-soft)' },
    good: { fg: 'var(--green-700)', border: 'var(--intl-tint-green-border-22)', bg: 'var(--tint-green-06)' },
    bad: { fg: 'var(--danger-text)', border: 'var(--intl-danger-border-24)', bg: 'var(--danger-bg)' },
  } as const;

  return (
    <section
      aria-label="ميزانية تضخّم الروابط"
      style={{ paddingBlock: 16, paddingInline: 18, border: '1px solid var(--cc-edge)', borderRadius: 12, background: 'var(--cc-glass)' }}
    >
      <h2 style={{ margin: '0 0 4px', fontSize: 14, fontWeight: 900, color: 'var(--ink)' }}>ميزانية تضخّم الروابط</h2>
      <p style={{ margin: '0 0 12px', fontSize: 12, lineHeight: 1.7, color: 'var(--text-muted)' }}>
        تُرصد المضاعفة قبل حدوثها. الأرقام أدناه من كتالوج المتجر الفعلي.
      </p>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        {rows.map((row) => {
          const tone = tones[row.tone];
          return (
            <div
              key={row.label}
              style={{
                display: 'flex',
                alignItems: 'baseline',
                gap: 9,
                paddingBlock: 9,
                paddingInline: 11,
                border: `1px solid ${tone.border}`,
                borderRadius: 9,
                background: tone.bg,
              }}
            >
              <span style={{ flex: 1, minWidth: 0, fontSize: 11.5, fontWeight: 800, color: 'var(--ink)', textAlign: 'start' }}>
                {row.label}
              </span>
              <bdi dir="ltr" style={{ flexShrink: 0, fontFamily: 'var(--font-mono)', fontSize: 12.5, fontWeight: 900, color: tone.fg }}>
                {count(row.value)}
              </bdi>
            </div>
          );
        })}
      </div>
      <p style={{ margin: '11px 0 0', fontSize: 11.5, lineHeight: 1.75, color: 'var(--gold-text)', textAlign: 'start' }}>
        لو فُعِّلت كل اللغات على كل منتج دون تصفية لبلغت الروابط <bdi dir="ltr">{count(potentialMax)}</bdi> بدل{' '}
        <bdi dir="ltr">{count(expectedTotal)}</bdi> — هذا ما تمنعه بوّابات الأهلية.
      </p>
    </section>
  );
}

/* ───────────── مسارات العمل — غائبة بالاسم ───────────── */

function WorkflowPanel() {
  return (
    <section
      aria-label="التوصيات ومسارات العمل"
      style={{
        marginBlockStart: 14,
        paddingBlock: 14,
        paddingInline: 18,
        border: '1px dashed var(--border-strong)',
        borderRadius: 12,
        background: 'var(--bg-soft)',
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        gap: 10,
      }}
    >
      <div style={{ flex: 1, minWidth: 240 }}>
        <div style={{ fontSize: 12.5, fontWeight: 900, color: 'var(--ink)', marginBlockEnd: 3 }}>التوصيات ومسارات العمل</div>
        <p style={{ margin: 0, fontSize: 12, lineHeight: 1.7, color: 'var(--text-muted)' }}>
          لا توصيات تُعرض — لا مصدر طلب مربوط يقيس الفرص الإقليمية، والزرّان معطّلان لأنه{' '}
          <b>لا محرّك مسارات عمل في النظام</b>.
        </p>
      </div>
      {['أنشئ Workflow', 'ادرس الجدوى'].map((label) => (
        <button
          key={label}
          type="button"
          disabled
          title="لا محرّك مسارات عمل في النظام"
          style={{
            minBlockSize: 44,
            paddingInline: 14,
            border: '1px solid var(--cc-edge)',
            borderRadius: 8,
            background: 'var(--cc-glass)',
            fontFamily: 'inherit',
            fontSize: 12,
            fontWeight: 900,
            color: 'var(--text-muted)',
            cursor: 'not-allowed',
            opacity: 0.7,
          }}
        >
          {label}
        </button>
      ))}
    </section>
  );
}
