'use client';
// UNIT: PREVIEW-PANEL
// FILE: preview-panel.tsx
// STYLE: none
// PARENT: PAGE-SEO-INTERNATIONAL
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _I18N-KIT, _INHERIT-TABLE
// API: /admin/products
// MAP: docs/PROJECT_MAP.md

/**
 * المعاينة والوراثة — /seo/international/preview.
 *
 * التصميم: شاشة «الأم والوراثة» في Tahdheer - International SEO.dc.html
 * وTahLocaleInherit.dc.html. كل لوح هنا فوق نقطة نهاية قائمة فعلًا:
 *   · GET /admin/products                          — منتقي المنتج.
 *   · GET /admin/products/{id}                     — قيم الأم المقفلة.
 *   · GET /admin/products/{id}/translations        — محتوى كل لغة ووراثته.
 *   · GET /admin/products/{id}/language-eligibility — المتطلّبات صفًّا صفًّا.
 *   · GET /admin/products/{id}/seo                 — وسوم hreflang التي
 *     تُكتب فعلًا (effective.alternates)؛ القائمة الفارغة تعني حرفيًّا
 *     «لا نظير — لا يُكتب hreflang»، وتُعرض كذلك لا كصفر مموَّه.
 */

import { useMemo, useState, type FormEvent } from 'react';
import { useResource, type ResourceState } from '../../../../../components/resource';
import type { Paginated, ProductDetail, ProductRow } from '../../../../../lib/admin-api';
import { PRODUCT_STATUS_LABELS } from '../../../../../lib/format';
import { GatedView } from '../_i18n-kit';
import InheritTable, { type EligibilityState, type TranslationRow } from './_inherit-table';
import '../../../../../../../.design-sync/tokens-3p/agent-B-opportunities-preview.css';

interface SeoEffective {
  canonical: string;
  canonicalIsSelf: boolean;
  alternates: { hreflang: string; href: string; isDefault: boolean }[];
}

interface SeoPayload {
  authored: Record<string, string | null>;
  effective: SeoEffective;
}

const STATUS_CHIP: Record<EligibilityState['status'], { label: string; className: string }> = {
  published: { label: 'منشورة', className: 'chip chip-published' },
  ready: { label: 'جاهزة للنشر', className: 'chip chip-active' },
  incomplete: { label: 'ناقصة', className: 'chip chip-draft' },
  blocked: { label: 'محجوبة', className: 'chip chip-failed' },
};

export function PreviewPanel() {
  const [draft, setDraft] = useState('');
  const [query, setQuery] = useState('');
  const [productId, setProductId] = useState<number | null>(null);

  const path = useMemo(() => {
    const params = new URLSearchParams({ perPage: '20' });
    if (query.trim() !== '') params.set('q', query.trim());
    return `/admin/products?${params.toString()}`;
  }, [query]);

  const products = useResource<Paginated<ProductRow>>(path);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setQuery(draft);
  };

  return (
    <section>

      <p style={{ margin: 0, fontSize: 12.5, lineHeight: 1.75, color: 'var(--cc-muted)', maxWidth: '88ch' }}>
        المنتج الأم والوراثة — سجل واحد لا نسخ. اختر منتجًا لترى ما يرثه كل فرع لغوي من الأم، وما تجاوزته
        ترجمة بشرية، وما يُقفَل من الأم بلا نظير في الترجمات.
      </p>

      <div className="panel">
        <h2>اختر المنتج</h2>
        <form className="filters" onSubmit={submit}>
          <input
            type="search"
            aria-label="ابحث باسم المنتج أو SKU أو الرابط"
            placeholder="اسم المنتج أو SKU…"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            style={{
              minBlockSize: 44,
              paddingInline: 12,
              border: '1px solid var(--intl-border-soft)',
              borderRadius: 9,
              background: 'var(--cc-glass)',
              fontFamily: 'inherit',
              fontSize: 13,
              color: 'var(--ink)',
              flex: 1,
              minWidth: 200,
            }}
          />
          <button type="submit" className="btn btn-ghost">
            ابحث
          </button>
        </form>

        <GatedView
          state={products.state}
          reload={products.reload}
          permission="products.read"
          reads="قائمة المنتجات تُقرأ من ‎GET /admin/products‎ (routes/api-admin.php:129-130)"
        >
          {(page) =>
            page.data.length === 0 ? (
              <p className="state-line">لا منتجات تطابق البحث{query ? ` «${query}»` : ''}.</p>
            ) : (
              <>
                <ul style={{ listStyle: 'none', margin: '12px 0 0', padding: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {page.data.map((product) => {
                    const selected = product.id === productId;
                    return (
                      <li key={product.id}>
                        <button
                          type="button"
                          aria-pressed={selected}
                          onClick={() => setProductId(product.id)}
                          style={{
                            inlineSize: '100%',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            minBlockSize: 44,
                            paddingBlock: 8,
                            paddingInline: 12,
                            border: `1px solid ${selected ? 'var(--green-700)' : 'var(--intl-border-soft)'}`,
                            borderRadius: 10,
                            background: selected ? 'var(--intl-tint-green-04)' : 'var(--bg)',
                            fontFamily: 'inherit',
                            cursor: 'pointer',
                            textAlign: 'start',
                          }}
                        >
                          <span style={{ flex: 1, minWidth: 0, fontSize: 12.5, fontWeight: 800, color: 'var(--ink)' }}>
                            {product.title}
                          </span>
                          <bdi dir="ltr" style={{ fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--text-muted)' }}>
                            {product.sku}
                          </bdi>
                          <span className={`chip chip-${product.status}`}>
                            {PRODUCT_STATUS_LABELS[product.status] ?? product.status}
                          </span>
                        </button>
                      </li>
                    );
                  })}
                </ul>
                {page.meta.total > page.data.length ? (
                  <p className="state-line" style={{ marginBlockEnd: 0 }}>
                    يُعرض {page.data.length} من {page.meta.total} — ضيّق البحث للوصول إلى الباقي.
                  </p>
                ) : null}
              </>
            )
          }
        </GatedView>
      </div>

      {productId === null ? (
        <p className="state-line">اختر منتجًا من القائمة أعلاه لعرض وراثته اللغوية ووسومه الفعلية.</p>
      ) : (
        <ProductPreview productId={productId} />
      )}
    </section>
  );
}

/* ───────────── معاينة المنتج المختار ───────────── */

function ProductPreview({ productId }: { productId: number }) {
  const detail = useResource<{ data: ProductDetail }>(`/admin/products/${productId}`);
  const translations = useResource<{ data: TranslationRow[] }>(`/admin/products/${productId}/translations`);
  const eligibility = useResource<{ data: EligibilityState[] }>(`/admin/products/${productId}/language-eligibility`);
  const seo = useResource<{ data: SeoPayload }>(`/admin/products/${productId}/seo`);
  const [chosenLocale, setChosenLocale] = useState<string | null>(null);

  // الحفظ أو النشر يغيّر الترجمات والأهلية والوسوم معًا — تُعاد قراءتها من الخادم.
  const onMutated = () => {
    translations.reload();
    eligibility.reload();
    seo.reload();
  };

  return (
    <>
      <GatedView
        state={translations.state}
        reload={translations.reload}
        permission="products.read"
        reads="ترجمات المنتج تُقرأ من ‎GET /admin/products/{id}/translations‎ (routes/api-admin.php:271 و278)"
      >
        {(payload) => {
          const rows = payload.data;
          const master = rows.find((row) => row.isDefault);
          if (!master) {
            return <p className="state-line">لا لغة افتراضية في سجل اللغات — لا يمكن رسم الوراثة.</p>;
          }
          const selectedCode = chosenLocale ?? rows.find((row) => !row.isDefault)?.locale ?? master.locale;
          const branch = rows.find((row) => row.locale === selectedCode) ?? master;
          const eligibilityRow =
            eligibility.state.status === 'ready'
              ? (eligibility.state.data.data.find((entry) => entry.locale.code === branch.locale) ?? null)
              : null;

          return (
            <div className="panel">
              <div className="tabs" role="group" aria-label="لغة المعاينة">
                {rows.map((row) => {
                  const active = row.locale === selectedCode;
                  return (
                    <button
                      key={row.locale}
                      type="button"
                      className={active ? 'tab tab-active' : 'tab'}
                      aria-pressed={active}
                      onClick={() => setChosenLocale(row.locale)}
                    >
                      <bdi dir="ltr">{row.locale}</bdi>
                      {row.isDefault ? ' — الأم' : ''}
                    </button>
                  );
                })}
              </div>

              <GatedView
                state={detail.state}
                reload={detail.reload}
                permission="products.read"
                reads="بيانات المنتج الأم تُقرأ من ‎GET /admin/products/{id}‎ (routes/api-admin.php:134)"
              >
                {(detailPayload) => (
                  <InheritTable
                    key={`${productId}:${branch.locale}`}
                    productId={productId}
                    master={master}
                    branch={branch}
                    detail={detailPayload.data}
                    eligibility={eligibilityRow}
                    onMutated={onMutated}
                  />
                )}
              </GatedView>

              <RequirementsPanel eligibility={eligibilityRow} localeCode={branch.locale} />
            </div>
          );
        }}
      </GatedView>

      <AlternatesPanel
        seoState={seo.state}
        reload={seo.reload}
      />
    </>
  );
}

/* ───────────── متطلّبات الأهلية ───────────── */

function RequirementsPanel({ eligibility, localeCode }: { eligibility: EligibilityState | null; localeCode: string }) {
  if (!eligibility) {
    return (
      <p className="state-line" style={{ marginBlockEnd: 0 }}>
        جارٍ تحميل متطلّبات الأهلية…
      </p>
    );
  }

  const chip = STATUS_CHIP[eligibility.status];

  return (
    <div className="panel-inset" style={{ marginBlockStart: 16, background: 'var(--bg-soft)', border: '1px solid var(--intl-border-soft)', borderRadius: 12, paddingBlock: 14, paddingInline: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginBlockEnd: 8 }}>
        <h3 style={{ margin: 0, fontSize: 13, fontWeight: 900, color: 'var(--ink)' }}>
          متطلّبات الأهلية — {eligibility.locale.nameAr} <bdi dir="ltr">({localeCode})</bdi>
        </h3>
        <span className={chip.className}>{chip.label}</span>
        <span style={{ marginInlineStart: 'auto', fontSize: 11.5, fontWeight: 800, color: eligibility.hreflangEmitted ? 'var(--success-chip-fg)' : 'var(--text-muted)' }}>
          {eligibility.hreflangEmitted
            ? 'تُعلَن ضمن وسوم hreflang فعلًا'
            : 'لا تُعلَن — لا يُكتب hreflang لهذه اللغة الآن'}
        </span>
      </div>
      <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 5 }}>
        {eligibility.requirements.map((requirement) => (
          <li
            key={requirement.key}
            style={{ display: 'flex', alignItems: 'baseline', gap: 8, fontSize: 11.5, lineHeight: 1.7, color: 'var(--intl-body)' }}
          >
            <span
              aria-hidden="true"
              style={{ fontWeight: 900, color: requirement.satisfied ? 'var(--success-chip-fg)' : 'var(--danger-text)', flexShrink: 0 }}
            >
              {requirement.satisfied ? '✓' : '✕'}
            </span>
            <span className="sr-only">{requirement.satisfied ? 'تحقّق:' : 'لم يتحقّق:'}</span>
            <span style={{ fontWeight: 800, color: 'var(--ink)' }}>{requirement.label}</span>
            {requirement.blocking && !requirement.satisfied ? (
              <span
                style={{
                  paddingBlock: 1,
                  paddingInline: 7,
                  borderRadius: 5,
                  background: 'var(--tint-danger-08)',
                  color: 'var(--danger-strong)',
                  fontSize: 11.5,
                  fontWeight: 900,
                  flexShrink: 0,
                }}
              >
                حاجب
              </span>
            ) : null}
            <span style={{ fontSize: 11.5, color: 'var(--intl-muted)', flexShrink: 0 }}>
              {requirement.scope === 'store' ? 'شرط متجر' : 'شرط محتوى'}
            </span>
            {requirement.detail ? <span style={{ color: 'var(--text-muted)' }}>{requirement.detail}</span> : null}
          </li>
        ))}
      </ul>
    </div>
  );
}

/* ───────────── الوسوم الفعلية ───────────── */

function AlternatesPanel({
  seoState,
  reload,
}: {
  seoState: ResourceState<{ data: SeoPayload }>;
  reload: () => void;
}) {
  return (
    <div className="panel">
      <h2>وسوم hreflang الفعلية للصفحة</h2>
      <p style={{ margin: '0 0 10px', fontSize: 12, lineHeight: 1.7, color: 'var(--text-muted)' }}>
        ما تُصدره الصفحة حقًّا من <bdi dir="ltr">link rel=&quot;alternate&quot;</bdi> — محسوب من خدمة الوسوم
        نفسها التي تُصيّر صفحة المنتج، لا من ادّعاء اللوحة.
      </p>
      <GatedView
        state={seoState}
        reload={reload}
        permission="products.read"
        reads="الوسوم الفعليّة تُقرأ من ‎GET /admin/products/{id}/seo‎ (routes/api-admin.php:271 و275)"
      >
        {(payload) => {
          const effective = payload.data.effective;
          return (
            <>
              {effective.alternates.length === 0 ? (
                <p className="state-line" style={{ marginBlock: 0 }}>
                  لا نظير — لا يُكتب hreflang. بديل واحد ليس بديلًا: تُعلَن المجموعة فقط حين تجتاز لغتان
                  فأكثر بوّابة الأهلية.
                </p>
              ) : (
                <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {effective.alternates.map((alternate) => (
                    <li
                      key={alternate.hreflang}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        paddingBlock: 8,
                        paddingInline: 12,
                        border: '1px solid var(--intl-border-row)',
                        borderRadius: 9,
                        background: 'var(--intl-bg-row)',
                        flexWrap: 'wrap',
                      }}
                    >
                      <code
                        dir="ltr"
                        style={{
                          paddingBlock: 3,
                          paddingInline: 10,
                          borderRadius: 8,
                          background: 'var(--ink)',
                          color: 'var(--intl-code-mint)',
                          fontFamily: 'var(--font-mono)',
                          fontSize: 11.5,
                          fontWeight: 800,
                        }}
                      >
                        {alternate.hreflang}
                      </code>
                      <bdi dir="ltr" style={{ flex: 1, minWidth: 200, fontFamily: 'var(--font-mono)', fontSize: 12, color: 'var(--intl-body)', overflowWrap: 'anywhere' }}>
                        {alternate.href}
                      </bdi>
                      {alternate.isDefault ? (
                        <span className="chip chip-active">x-default — الأساس</span>
                      ) : null}
                    </li>
                  ))}
                </ul>
              )}
              {!effective.canonicalIsSelf ? (
                <p className="state-error" role="alert" style={{ marginBlockEnd: 0 }}>
                  canonical لهذه الصفحة يشير بعيدًا عنها (<bdi dir="ltr">{effective.canonical}</bdi>) — تعارضٌ
                  مع إعلان بدائل لغوية، يظهر أيضًا في تدقيق hreflang.
                </p>
              ) : null}
            </>
          );
        }}
      </GatedView>
    </div>
  );
}
