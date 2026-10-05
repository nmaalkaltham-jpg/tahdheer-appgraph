'use client';
// UNIT: PAGE-ANALYTICS-OS-SIM
// FILE: page.tsx
// ROUTE: /analytics/os/sim
// STYLE: none
// PARENT: none
// USES: DERIVE, LIB-FORMAT, RESOURCE, _KIT
// API: /admin/crawl
// MAP: docs/PROJECT_MAP.md

/**
 * المحاكاة — «ماذا لو» بلا نطاقات مُختلَقة.
 *
 * ثلاثة سيناريوهات حقيقية التبديل، ولكل مدخل حالة صدق: سيناريو الروابط
 * المكسورة وحده يملك مدخلًا مقيسًا (GET /admin/crawl → totals.broken مع رقم
 * الجولة وتاريخها)؛ سيناريوهات CTR والمراكز صفر مدخلاتها المقاسة. بطاقات
 * المدى الثلاث لا تعرض أرقامًا: كل بطاقة تسمّي الخطّ الغائب الذي يمنع
 * حسابها (measurement.ts MISSING_FEEDS)، والثقة لا تُعرض لغياب أي نموذج
 * معايرة أو ذاكرة نتائج.
 */

import { useState } from 'react';
import type { CSSProperties } from 'react';
import { PageHeader, useResource } from '../../../../../components/resource';
import { count } from '../../../../../lib/format';
import { dataOf } from '../../../seo/_kit';
import { missingFeed, OsNav, SIM_SCENARIOS, simInputs, type CrawlPayloadOs, type SimKey } from '../_lib/derive';

const S: Record<string, CSSProperties> = {
  page: { display: 'flex', flexDirection: 'column', gap: 13 },
  row: { display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' },
  h1: { margin: 0, fontSize: 20, fontWeight: 900, color: 'var(--ink)' },
  h2: { margin: 0, fontSize: 12.5, fontWeight: 900, color: 'var(--ink)' },
  dim: { fontSize: 11.5, color: 'var(--apb-dim)' },
  faint: { fontSize: 11.5, color: 'var(--apb-faint)' },
  card: {
    border: '1px solid var(--apb-border)',
    borderRadius: 14,
    background: 'var(--surface)',
    paddingBlock: 14,
    paddingInline: 17,
  },
  tabOn: {
    minBlockSize: 44,
    paddingInline: 15,
    border: '1px solid var(--cc-text)',
    borderRadius: 11,
    background: 'var(--cc-text)',
    color: 'var(--cc-bg1)',
    fontSize: 11.5,
    fontWeight: 900,
    cursor: 'pointer',
  },
  tabOff: {
    minBlockSize: 44,
    paddingInline: 15,
    border: '1px solid var(--apb-border)',
    borderRadius: 11,
    background: 'var(--surface)',
    color: 'var(--apb-strong)',
    fontSize: 11.5,
    fontWeight: 900,
    cursor: 'pointer',
  },
  measuredChip: {
    paddingBlock: 2,
    paddingInline: 8,
    borderRadius: 999,
    background: 'var(--success-chip-bg)',
    color: 'var(--success-chip-fg)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  absentChip: {
    paddingBlock: 2,
    paddingInline: 8,
    borderRadius: 999,
    background: 'var(--apb-neutral)',
    color: 'var(--text-muted)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  inputRow: {
    display: 'flex',
    alignItems: 'center',
    gap: 8,
    flexWrap: 'wrap',
    paddingBlock: 6,
    borderBlockStart: '1px dashed var(--apb-hairline)',
    fontSize: 11.5,
  },
  cardsGrid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 11 },
  outcome: {
    border: '1px solid var(--apb-border)',
    borderRadius: 15,
    background: 'var(--surface)',
    paddingBlock: 15,
    paddingInline: 17,
    textAlign: 'center',
  },
  blockedBtn: {
    minBlockSize: 44,
    marginBlockStart: 8,
    paddingInline: 16,
    border: '1px solid var(--apb-border)',
    borderRadius: 10,
    background: 'var(--apb-neutral)',
    color: 'var(--apb-dim)',
    fontSize: 12,
    fontWeight: 900,
    cursor: 'not-allowed',
  },
};

/** ألوان تسمية البطاقات الثلاث كما في اللوح — الحدّ وحده يتغيّر لا المضمون. */
const OUTCOME_STYLES: { label: string; labelColor: string; border: string }[] = [
  { label: 'متحفظ', labelColor: 'var(--apb-dim)', border: 'var(--apb-border)' },
  { label: 'متوقع', labelColor: 'var(--success-chip-fg)', border: 'var(--green-700)' },
  { label: 'متفائل', labelColor: 'var(--info)', border: 'var(--apb-info-border)' },
];

export default function SimulationPage() {
  const crawl = useResource<CrawlPayloadOs>('/admin/crawl');
  const [tab, setTab] = useState<SimKey>('ctr');

  const externalSearch = missingFeed('external-search');
  const visitorEvents = missingFeed('visitor-events');
  const productRevenue = missingFeed('product-revenue');

  return (
    <div style={S.page}>
      <PageHeader trail="Analytics Pro — المحاكاة" title="المحاكاة" />
      <OsNav current="sim" />

      <div style={S.row}>
        <h1 style={S.h1}>محاكاة «ماذا لو» — قبل أي تنفيذ</h1>
        <span style={{ ...S.dim, marginInlineStart: 'auto' }}>نطاقات لا وعود — الافتراضات معلنة</span>
      </div>

      <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap' }} role="group" aria-label="سيناريوهات المحاكاة">
        {SIM_SCENARIOS.map((scenario) => (
          <button
            key={scenario.key}
            type="button"
            aria-pressed={tab === scenario.key}
            style={tab === scenario.key ? S.tabOn : S.tabOff}
            onClick={() => setTab(scenario.key)}
          >
            {scenario.name}
          </button>
        ))}
      </div>

      {crawl.state.status === 'loading' && tab === 'links' ? (
        <p className="state-line" role="status" aria-live="polite">
          جارٍ قراءة تقرير الزحف — المدخل المقيس الوحيد في هذا السيناريو…
        </p>
      ) : null}
      {crawl.state.status === 'error' && tab === 'links' ? (
        <div className="state-error" role="alert">
          <p>{crawl.state.message}</p>
          <button type="button" className="btn btn-ghost" onClick={crawl.reload}>
            إعادة المحاولة
          </button>
        </div>
      ) : null}

      {(() => {
        const inputs = simInputs(tab, dataOf(crawl.state));
        const measuredInputs = inputs.filter((input) => input.measured);
        return (
          <>
              <section style={S.card} aria-label="مدخلات السيناريو وحالة قياس كل واحد">
                <div style={S.row}>
                  <h2 style={S.h2}>مدخلات السيناريو — حالة قياس كل مدخل</h2>
                  <span style={{ ...S.dim, marginInlineStart: 'auto' }}>
                    المقاس فعلًا: <bdi dir="ltr">{count(measuredInputs.length)}</bdi> من <bdi dir="ltr">{count(inputs.length)}</bdi>
                  </span>
                </div>
                <div style={{ marginBlockStart: 8 }}>
                  {inputs.map((input) => (
                    <div key={input.label} style={S.inputRow}>
                      <span style={input.measured ? S.measuredChip : S.absentChip}>
                        {input.measured ? 'مقاس' : 'غائب'}
                      </span>
                      <b style={{ color: 'var(--apb-strong)', flex: 1, minInlineSize: 180 }}>{input.label}</b>
                      {input.value !== null ? (
                        <b style={{ color: 'var(--ink)' }}>
                          <bdi dir="ltr">{input.value}</bdi>
                        </b>
                      ) : null}
                      <span style={{ ...S.faint }}>{input.source}</span>
                    </div>
                  ))}
                </div>
              </section>

              <div style={S.cardsGrid}>
                {OUTCOME_STYLES.map((outcome) => (
                  <section key={outcome.label} style={{ ...S.outcome, borderColor: outcome.border }}>
                    <span style={{ fontSize: 11.5, fontWeight: 900, color: outcome.labelColor }}>{outcome.label}</span>
                    <span
                      style={{ display: 'block', marginBlock: 5, fontSize: 25, fontWeight: 900, color: 'var(--apb-faint)' }}
                      aria-hidden="true"
                    >
                      —
                    </span>
                    <span style={{ fontSize: 11.5, color: 'var(--apb-soft)' }}>
                      لا يُحتسب مدى — المدخل الغائب: {externalSearch?.name ?? 'البحث الخارجي (Search Console)'}
                    </span>
                    <div
                      style={{
                        marginBlockStart: 8,
                        paddingBlockStart: 8,
                        borderBlockStart: '1px dashed var(--apb-hairline)',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 3,
                        fontSize: 11.5,
                        color: 'var(--text-muted)',
                        textAlign: 'start',
                      }}
                    >
                      <span>تحويلات: لا تُحتسب — المدخل الغائب: {visitorEvents?.name ?? 'أحداث الزيارات (طرف أول)'}</span>
                      <span>إيراد: لا يُحتسب — المدخل الغائب: {productRevenue?.name ?? 'الإيراد المنسوب لكل منتج'}</span>
                    </div>
                  </section>
                ))}
              </div>

              <section style={S.card}>
                <div style={S.row}>
                  <h2 style={S.h2}>الافتراضات — المعلن منها فقط</h2>
                  <span style={{ ...S.faint, marginInlineStart: 'auto' }}>
                    الثقة لا تُعرض — لا نموذج معايرة ولا ذاكرة نتائج تعايره
                  </span>
                </div>
                {measuredInputs.length === 0 ? (
                  <p style={{ margin: 0, marginBlockStart: 6, fontSize: 11.5, lineHeight: 1.8, color: 'var(--text-muted)' }}>
                    لا افتراض يُعلن — لا مدخل مقاس واحد في هذا السيناريو. الافتراض يُبنى على قياس، وكل مدخلات
                    هذا السيناريو تنتظر خطوطها المسمّاة أعلاه.
                  </p>
                ) : (
                  <div style={{ marginBlockStart: 4 }}>
                    {measuredInputs.map((input) => (
                      <span
                        key={input.label}
                        style={{ display: 'block', paddingBlock: 4, fontSize: 11.5, lineHeight: 1.7, color: 'var(--text-muted)' }}
                      >
                        • {input.label}: <bdi dir="ltr">{input.value ?? '—'}</bdi> — من{' '}
                        <bdi dir="ltr">{input.source}</bdi>، وإعادة القياس بعد الإصلاح متاحة فعلًا عبر{' '}
                        <bdi dir="ltr">POST /admin/crawl/run</bdi>.
                      </span>
                    ))}
                  </div>
                )}
                <div style={{ ...S.row, marginBlockStart: 6 }}>
                  <button type="button" style={S.blockedBtn} disabled aria-describedby="sim-wf-why">
                    حوّل السيناريو إلى Workflow تنفيذي
                  </button>
                  <span
                    id="sim-wf-why"
                    style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--warning-text)', alignSelf: 'end', paddingBlockEnd: 4 }}
                  >
                    معطَّل — لا محرّك Workflows في هذا النظام؛ السيناريو لا يتحوّل إلى شيء يُنفَّذ.
                  </span>
                </div>
              </section>
          </>
        );
      })()}
    </div>
  );
}
