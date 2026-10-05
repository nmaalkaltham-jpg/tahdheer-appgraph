'use client';
// UNIT: _INHERIT-TABLE
// FILE: _inherit-table.tsx
// STYLE: none
// PARENT: PREVIEW-PANEL
// USES: LIB-ADMIN-API, LIB-FORMAT
// API: POST /admin/products
// MAP: docs/PROJECT_MAP.md

/**
 * جدول الوراثة والتجاوز — قلب شاشة «المعاينة والوراثة».
 *
 * التصميم: شاشة «الأم والوراثة» في Tahdheer - International SEO.dc.html
 * وTahLocaleInherit.dc.html. المبدأ حرفيًّا من التصميم: سجل واحد لا نسخ —
 * السعر والمعرّفات والملفات مقفلة من الأم، والنصوص تُتجاوز بترجمة بشرية،
 * والناقص يسقط للأم تلقائيًّا.
 *
 * الكتابة عبر نقطتي النهاية القائمتين فعلًا:
 *   · PATCH /admin/products/{id}/translations/{locale}          — الحفظ.
 *   · POST  /admin/products/{id}/translations/{locale}/publish  — النشر؛
 *     رفض 422 يعيد قائمة المتطلّبات الناقصة وتُعرض هنا كما وردت.
 *
 * ملاحظة تركيب: الأب يمرّر key={productId:locale} فتُعاد تهيئة حالة الحقول
 * عند تبديل اللغة أو المنتج — لا مزامنة يدوية للحالة مع الخصائص.
 */

import { useState, type CSSProperties } from 'react';
import { api, firstError, type ProductDetail } from '../../../../../lib/admin-api';
import { count, money } from '../../../../../lib/format';

/* ───── أنواع نقاط النهاية (ProductTranslationController / LanguageEligibility) ───── */

export interface TranslationContent {
  title: string | null;
  subtitle: string | null;
  shortDesc: string | null;
  description: string | null;
  slug: string | null;
}

export interface TranslationRow {
  locale: string;
  isDefault: boolean;
  content: TranslationContent;
  status: string;
  isIndexable: boolean;
  editable: boolean;
}

export interface Requirement {
  key: string;
  label: string;
  satisfied: boolean;
  blocking: boolean;
  scope: 'store' | 'content';
  detail: string | null;
}

export interface EligibilityState {
  locale: {
    code: string;
    nameAr: string;
    hreflang: string;
    direction: string;
    isDefault: boolean;
    isActive: boolean;
    isRoutable: boolean;
  };
  status: 'published' | 'ready' | 'incomplete' | 'blocked';
  translationExists: boolean;
  canPublish: boolean;
  hreflangEmitted: boolean;
  requirements: Requirement[];
  missing: string[];
}

type FieldKey = keyof TranslationContent;

const FIELDS: { key: FieldKey; label: string; kind: 'input' | 'textarea'; dir?: 'ltr' }[] = [
  { key: 'title', label: 'العنوان', kind: 'input' },
  { key: 'subtitle', label: 'العنوان الفرعي', kind: 'input' },
  { key: 'shortDesc', label: 'الوصف المختصر', kind: 'textarea' },
  { key: 'description', label: 'الوصف الكامل', kind: 'textarea' },
  { key: 'slug', label: 'الرابط', kind: 'input', dir: 'ltr' },
];

/** ألوان علاقات التصميم حرفيًّا (REL في Tahdheer - International SEO.dc.html). */
const REL = {
  locked: { label: 'مقفل من الأم', bg: 'var(--intl-locked-bg)', fg: 'var(--intl-locked-fg)' },
  override: { label: 'مُتجاوَز — ترجمة بشرية', bg: 'var(--info-bg)', fg: 'var(--info)' },
  fallback: { label: 'يسقط للأم عند النقص', bg: 'var(--warning-bg)', fg: 'var(--warning-text)' },
} as const;

const th: CSSProperties = {
  paddingBlock: 9,
  paddingInline: 12,
  textAlign: 'start',
  fontSize: 11.5,
  fontWeight: 900,
  color: 'var(--text-muted)',
};

const td: CSSProperties = {
  paddingBlock: 9,
  paddingInline: 12,
  borderBlockStart: '1px solid var(--intl-border-row)',
  fontSize: 11.5,
  color: 'var(--intl-body)',
  textAlign: 'start',
  verticalAlign: 'top',
};

function Pill({ rel }: { rel: { label: string; bg: string; fg: string } }) {
  return (
    <span
      style={{
        display: 'inline-flex',
        paddingBlock: 2,
        paddingInline: 10,
        borderRadius: 999,
        background: rel.bg,
        color: rel.fg,
        fontSize: 11.5,
        fontWeight: 900,
        whiteSpace: 'nowrap',
      }}
    >
      {rel.label}
    </span>
  );
}

const inputStyle: CSSProperties = {
  inlineSize: '100%',
  minBlockSize: 44,
  paddingBlock: 8,
  paddingInline: 10,
  border: '1px solid var(--intl-border-soft)',
  borderRadius: 8,
  background: 'var(--cc-glass)',
  fontFamily: 'inherit',
  fontSize: 12,
  color: 'var(--ink)',
  boxSizing: 'border-box',
};

export default function InheritTable({
  productId,
  master,
  branch,
  detail,
  eligibility,
  onMutated,
}: {
  productId: number;
  master: TranslationRow;
  branch: TranslationRow;
  detail: ProductDetail;
  eligibility: EligibilityState | null;
  onMutated: () => void;
}) {
  const [form, setForm] = useState<Record<FieldKey, string>>({
    title: branch.content.title ?? '',
    subtitle: branch.content.subtitle ?? '',
    shortDesc: branch.content.shortDesc ?? '',
    description: branch.content.description ?? '',
    slug: branch.content.slug ?? '',
  });
  const [indexable, setIndexable] = useState(branch.isIndexable);
  const [busy, setBusy] = useState<'save' | 'publish' | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [unmet, setUnmet] = useState<Requirement[]>([]);

  const isDefault = branch.isDefault;

  const save = async () => {
    setBusy('save');
    setNotice(null);
    setFailure(null);
    setUnmet([]);

    const result = await api.patch(`/admin/products/${productId}/translations/${branch.locale}`, {
      title: form.title,
      subtitle: form.subtitle,
      shortDesc: form.shortDesc,
      description: form.description,
      slug: form.slug,
      isIndexable: indexable,
    });
    setBusy(null);

    if (!result.ok) {
      setFailure(firstError(result));
      return;
    }
    setNotice('حُفظت الترجمة.');
    onMutated();
  };

  const publish = async () => {
    setBusy('publish');
    setNotice(null);
    setFailure(null);
    setUnmet([]);

    const result = await api.post(`/admin/products/${productId}/translations/${branch.locale}/publish`);
    setBusy(null);

    if (!result.ok) {
      setFailure(result.message);
      // 422 NOT_ELIGIBLE يعيد المتطلّبات الناقصة — تُعرض كما وردت من الخادم.
      const body = result.body as { requirements?: Requirement[] } | null;
      if (Array.isArray(body?.requirements)) setUnmet(body.requirements);
      return;
    }
    setNotice('نُشرت الترجمة بهذه اللغة.');
    onMutated();
  };

  const lockedRows: { label: string; value: string; dir?: 'ltr' }[] = [
    { label: 'السعر', value: detail.priceMinor === undefined ? '—' : `${money(detail.priceMinor)} ر.س` },
    { label: 'الملفات والصور', value: `${count(detail.fileCount)} ملف · ${count(detail.imageCount)} صورة` },
    { label: 'SKU / المعرّف', value: detail.sku, dir: 'ltr' },
  ];

  return (
    <section
      aria-label={`الوراثة والتجاوز — ${branch.locale}`}
      style={{ border: '1px solid var(--intl-border-soft)', borderRadius: 14, background: 'var(--cc-glass)', overflow: 'hidden' }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 9, flexWrap: 'wrap', paddingBlock: 12, paddingInline: 16 }}>
        <h3 style={{ margin: 0, fontSize: 14, fontWeight: 900, color: 'var(--ink)' }}>
          الوراثة والتجاوز — <bdi dir="ltr">{branch.locale}</bdi>
        </h3>
        <span style={{ marginInlineStart: 'auto', fontSize: 11.5, color: 'var(--intl-muted)' }}>
          الأم <bdi dir="ltr">{master.locale}</bdi> ← الفرع <bdi dir="ltr">{branch.locale}</bdi>
        </span>
        {eligibility && !eligibility.canPublish && !isDefault ? (
          <span
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 6,
              paddingBlock: 4,
              paddingInline: 10,
              borderRadius: 7,
              background: 'var(--tint-danger-08)',
              border: '1px solid var(--intl-danger-border-20)',
              fontSize: 11.5,
              fontWeight: 900,
              color: 'var(--danger-strong)',
              whiteSpace: 'nowrap',
            }}
          >
            النشر محجوب — تنقصه {count(eligibility.requirements.filter((r) => !r.satisfied && r.blocking).length)} متطلّبات
          </span>
        ) : null}
      </div>

      {isDefault ? (
        <p className="state-line" style={{ marginBlock: 0, paddingInline: 16, paddingBlockEnd: 10 }}>
          اللغة الافتراضية تُحرَّر من بيانات المنتج نفسها، لا من الترجمات.
        </p>
      ) : null}

      {/*
       * الطوق داخل بطاقةٍ محدودة أصلًا، فحدّه وخلفيّته يسقطان كي لا يزدوج
       * الإطار. الباقي هو المقصود: تمريرٌ أفقيّ محبوس، وبلوغٌ بلوحة المفاتيح.
       */}
      <div
        className="table-scroll"
        tabIndex={0}
        role="region"
        aria-label="حقول المنتج بين اللغة الأم والفرع"
        style={{ border: 0, borderRadius: 0, background: 'transparent' }}
      >
        <table style={{ inlineSize: '100%', borderCollapse: 'collapse' }}>
          <caption className="sr-only">حقول المنتج بين الأم والفرع وعلاقة كل حقل بالأم</caption>
          <thead>
            <tr style={{ background: 'var(--intl-bg-head)' }}>
              <th scope="col" style={{ ...th, inlineSize: '16%' }}>
                الحقل
              </th>
              <th scope="col" style={{ ...th, inlineSize: '30%' }}>
                قيمة الأم <bdi dir="ltr">({master.locale})</bdi>
              </th>
              <th scope="col" style={{ ...th, inlineSize: '34%' }}>
                قيمة الفرع <bdi dir="ltr">({branch.locale})</bdi>
              </th>
              <th scope="col" style={th}>
                العلاقة بالأم
              </th>
            </tr>
          </thead>
          <tbody>
            {FIELDS.map((field) => {
              const masterValue = master.content[field.key];
              const branchValue = form[field.key];
              const overridden = branchValue.trim() !== '';
              const rel = isDefault ? null : overridden ? REL.override : REL.fallback;
              const id = `inherit-${branch.locale}-${field.key}`;
              return (
                <tr key={field.key}>
                  <th
                    scope="row"
                    style={{ ...td, background: 'var(--intl-bg-row)', fontSize: 12, fontWeight: 800, color: 'var(--ink)' }}
                  >
                    <label htmlFor={isDefault ? undefined : id}>{field.label}</label>
                  </th>
                  <td style={td}>
                    {masterValue && masterValue.trim() !== '' ? (
                      <bdi dir={field.dir ?? 'auto'} style={field.dir === 'ltr' ? { fontFamily: 'var(--font-mono)' } : undefined}>
                        {masterValue}
                      </bdi>
                    ) : (
                      <span className="absent">— فارغ في الأم</span>
                    )}
                  </td>
                  <td style={td}>
                    {isDefault ? (
                      <span className="absent">هي قيمة الأم نفسها</span>
                    ) : field.kind === 'textarea' ? (
                      <textarea
                        id={id}
                        rows={field.key === 'description' ? 5 : 2}
                        value={branchValue}
                        onChange={(event) => setForm((prev) => ({ ...prev, [field.key]: event.target.value }))}
                        style={{ ...inputStyle, resize: 'vertical' }}
                      />
                    ) : (
                      <input
                        id={id}
                        type="text"
                        dir={field.dir}
                        value={branchValue}
                        onChange={(event) => setForm((prev) => ({ ...prev, [field.key]: event.target.value }))}
                        style={field.dir === 'ltr' ? { ...inputStyle, fontFamily: 'var(--font-mono)' } : inputStyle}
                      />
                    )}
                  </td>
                  <td style={td}>
                    {rel ? <Pill rel={rel} /> : <span className="absent">المصدر — أعمدة المنتج</span>}
                    {!isDefault && !overridden && masterValue && masterValue.trim() !== '' ? (
                      <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginBlockStart: 4 }}>
                        يُعرض نصّ الأم حتى تُكتب ترجمة.
                      </div>
                    ) : null}
                  </td>
                </tr>
              );
            })}
            {lockedRows.map((row) => (
              <tr key={row.label}>
                <th
                  scope="row"
                  style={{ ...td, background: 'var(--intl-bg-row)', fontSize: 12, fontWeight: 800, color: 'var(--ink)' }}
                >
                  {row.label}
                </th>
                <td style={td}>
                  <bdi dir={row.dir ?? 'auto'} style={row.dir === 'ltr' ? { fontFamily: 'var(--font-mono)' } : undefined}>
                    {row.value}
                  </bdi>
                </td>
                <td style={{ ...td, color: 'var(--text-muted)' }}>لا نظير لهذا الحقل في جدول الترجمات — يُقرأ من الأم وحدها.</td>
                <td style={td}>
                  <Pill rel={REL.locked} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* سلسلة الاقتراحات: لا مخزن اقتراحات للترجمات في النظام — يُقال بالاسم. */}
      <div
        style={{
          display: 'flex',
          flexWrap: 'wrap',
          alignItems: 'center',
          gap: 8,
          marginBlockStart: 2,
          marginInline: 16,
          marginBlockEnd: 12,
          paddingBlock: 10,
          paddingInline: 12,
          border: '1px dashed var(--intl-border-mint)',
          borderRadius: 10,
          background: 'var(--intl-bg-mint)',
        }}
      >
        <span style={{ flex: 1, minWidth: 220, fontSize: 11.5, lineHeight: 1.7, color: 'var(--text-muted)' }}>
          سلسلة الاقتراحات: <b>لا اقتراحات — لا محرّك اقتراحات مربوط</b>.
        </span>
        {['أعد توليد القديم', 'اعتمد الملفّ'].map((label) => (
          <button
            key={label}
            type="button"
            disabled
            title="لا اقتراحات — لا محرّك اقتراحات مربوط"
            style={{
              minBlockSize: 44,
              paddingInline: 14,
              border: '1px solid var(--intl-border-soft)',
              borderRadius: 9,
              background: 'var(--cc-glass)',
              fontFamily: 'inherit',
              fontSize: 12,
              fontWeight: 800,
              color: 'var(--text-muted)',
              cursor: 'not-allowed',
              opacity: 0.7,
            }}
          >
            {label}
          </button>
        ))}
      </div>

      {!isDefault ? (
        <div style={{ paddingInline: 16, paddingBlockEnd: 16 }}>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 8, fontSize: 11.5, color: 'var(--intl-body)', minBlockSize: 44 }}>
            <input
              type="checkbox"
              checked={indexable}
              onChange={(event) => setIndexable(event.target.checked)}
              style={{ inlineSize: 16, blockSize: 16 }}
            />
            قابلة للفهرسة — تدخل الفهرس ووسوم hreflang حين تُنشر
          </label>

          <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 8, marginBlockStart: 8 }}>
            <button type="button" className="btn btn-primary" disabled={busy !== null} onClick={() => void save()}>
              {busy === 'save' ? 'يحفظ…' : 'احفظ الترجمة'}
            </button>
            <button type="button" className="btn btn-ghost" disabled={busy !== null} onClick={() => void publish()}>
              {busy === 'publish' ? 'ينشر…' : 'انشر الترجمة'}
            </button>
            <span className="absent">
              الحالة الحالية: {branch.status === 'published' ? 'منشورة' : 'مسوّدة'}
            </span>
          </div>

          {notice ? (
            <p className="state-ok" role="status" style={{ marginBlockEnd: 0 }}>
              {notice}
            </p>
          ) : null}
          {failure ? (
            <div className="state-error" role="alert" style={{ marginBlockEnd: 0 }}>
              <div>
                <p>{failure}</p>
                {unmet.length > 0 ? (
                  <ul style={{ margin: '6px 0 0', paddingInlineStart: 18 }}>
                    {unmet.map((requirement) => (
                      <li key={requirement.key}>
                        {requirement.label}
                        {requirement.detail ? ` — ${requirement.detail}` : ''}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            </div>
          ) : null}
        </div>
      ) : null}

      <p
        style={{
          margin: 0,
          paddingBlock: 10,
          paddingInline: 16,
          borderBlockStart: '1px dashed var(--intl-border-mint)',
          background: 'var(--intl-bg-mint)',
          fontSize: 11.5,
          lineHeight: 1.7,
          color: 'var(--text-muted)',
          textAlign: 'start',
        }}
      >
        <b style={{ color: 'var(--success-chip-fg)' }}>المبدأ:</b> السعر والمعرّفات مقفلة من الأم (مصدر حقيقة واحد)،
        النصوص تُتجاوز بترجمة بشرية بحالة مراجعة، والناقص يسقط للأم تلقائيًا — لا تكرار لسجل المنتج أبدًا.
      </p>
    </section>
  );
}
