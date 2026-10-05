'use client';
// UNIT: PAGE-ANALYTICS-OS-OPPS
// FILE: page.tsx
// ROUTE: /analytics/os/opps
// STYLE: none
// PARENT: none
// USES: DERIVE, LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _FINDINGS, _KIT
// API: /admin/crawl, /admin/demand, /admin/products, /admin/search/queries, /admin/search/synonyms
// MAP: docs/PROJECT_MAP.md

/**
 * محرك الفرص — صفوف مشتقّة من قياسات قائمة فقط.
 *
 * المصادر الثلاثة الحقيقية: اشتقاق seo/_findings.ts فوق ردود الخادم،
 * وصندوق الطلب الفائت GET /admin/demand (مرتّب بعدد الطالبين فعلًا)،
 * والصفحات المدفونة من GET /admin/crawl. لا درجة مُركَّبة: 5 من 6 عوامل
 * الترجيح غير مقاسة، فالشارة «غير مقاس» والتفكيك يسمّي كل عامل وما ينقصه.
 * و«مكسب سريع» تعريف صادق: إصلاحه له نقطة نهاية قائمة اليوم، لا شعور.
 */

import Link from 'next/link';
import { useMemo, useState } from 'react';
import type { CSSProperties } from 'react';
import { PageHeader, useResource } from '../../../../../components/resource';
import type { Paginated, ProductRow, SearchQueriesReport, SynonymRow } from '../../../../../lib/admin-api';
import { count } from '../../../../../lib/format';
import { deriveFindings } from '../../../seo/_findings';
import { anyLoading, dataOf, firstProblem, type LlmsCatalog, type RobotsPolicy, type SitemapIndex } from '../../../seo/_kit';
import {
  deriveOpportunities,
  OPP_REVENUE_ABSENT,
  OsNav,
  type CrawlPayloadOs,
  type DemandPayloadOs,
} from '../_lib/derive';

const S: Record<string, CSSProperties> = {
  page: { display: 'flex', flexDirection: 'column', gap: 13 },
  row: { display: 'flex', alignItems: 'center', gap: 9, flexWrap: 'wrap' },
  h1: { margin: 0, fontSize: 20, fontWeight: 900, color: 'var(--ink)' },
  dim: { fontSize: 11.5, color: 'var(--apb-dim)' },
  card: {
    border: '1px solid var(--apb-border)',
    borderRadius: 14,
    background: 'var(--surface)',
    paddingBlock: 12,
    paddingInline: 16,
  },
  badge: {
    display: 'inline-grid',
    placeItems: 'center',
    inlineSize: 46,
    blockSize: 46,
    borderRadius: 12,
    background: 'var(--apb-neutral)',
    color: 'var(--text-muted)',
    fontSize: 11.5,
    fontWeight: 900,
    textAlign: 'center',
    paddingBlock: 2,
    paddingInline: 2,
  },
  title: { fontSize: 12.5, fontWeight: 900, color: 'var(--ink)' },
  desc: { display: 'block', fontSize: 11.5, color: 'var(--apb-soft)' },
  quickWin: {
    paddingBlock: 3,
    paddingInline: 10,
    borderRadius: 999,
    background: 'var(--warning-bg)',
    color: 'var(--warning-text)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  revenueAbsent: {
    paddingBlock: 3,
    paddingInline: 10,
    borderRadius: 999,
    background: 'var(--apb-neutral)',
    color: 'var(--text-muted)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  source: {
    paddingBlock: 1,
    paddingInline: 7,
    borderRadius: 5,
    background: 'var(--apb-neutral)',
    fontSize: 11.5,
    fontWeight: 900,
    color: 'var(--text-muted)',
  },
  breakBtn: {
    minBlockSize: 44,
    paddingInline: 12,
    border: '1px solid var(--apb-border)',
    borderRadius: 9,
    background: 'var(--apb-surface-soft)',
    fontSize: 11.5,
    fontWeight: 900,
    color: 'var(--apb-strong)',
    cursor: 'pointer',
  },
  actBtn: {
    display: 'inline-flex',
    alignItems: 'center',
    minBlockSize: 44,
    paddingInline: 13,
    border: 0,
    borderRadius: 9,
    background: 'var(--cc-text)',
    color: 'var(--cc-bg1)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  blockedBtn: {
    minBlockSize: 44,
    paddingInline: 13,
    border: '1px solid var(--apb-border)',
    borderRadius: 9,
    background: 'var(--apb-neutral)',
    color: 'var(--apb-dim)',
    fontSize: 11.5,
    fontWeight: 900,
    cursor: 'not-allowed',
  },
  blockedWhy: { fontSize: 11.5, fontWeight: 800, color: 'var(--warning-text)' },
  parts: {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
    gap: 6,
    marginBlockStart: 10,
    paddingBlockStart: 9,
    borderBlockStart: '1px dashed var(--apb-hairline)',
  },
  part: {
    paddingBlock: 8,
    paddingInline: 8,
    borderRadius: 10,
    background: 'var(--apb-surface-soft)',
    textAlign: 'center',
  },
  filterOn: {
    minBlockSize: 44,
    paddingInline: 12,
    border: '1px solid var(--cc-text)',
    borderRadius: 999,
    background: 'var(--cc-text)',
    color: 'var(--cc-bg1)',
    fontSize: 11.5,
    fontWeight: 900,
    cursor: 'pointer',
  },
  filterOff: {
    minBlockSize: 44,
    paddingInline: 12,
    border: '1px solid var(--apb-border)',
    borderRadius: 999,
    background: 'var(--surface)',
    color: 'var(--apb-strong)',
    fontSize: 11.5,
    fontWeight: 900,
    cursor: 'pointer',
  },
};

const FILTERS = [
  { key: 'all', label: 'كل الفرص' },
  { key: 'qw', label: '⚡ مكاسب سريعة' },
] as const;

export default function OpportunitiesPage() {
  const sitemap = useResource<SitemapIndex>('/sitemap/index');
  const llms = useResource<LlmsCatalog>('/llms-data');
  const robots = useResource<RobotsPolicy>('/robots');
  const queries = useResource<SearchQueriesReport>('/admin/search/queries?days=30');
  const synonyms = useResource<Paginated<SynonymRow>>('/admin/search/synonyms?perPage=100');
  const crawl = useResource<CrawlPayloadOs>('/admin/crawl');
  const products = useResource<Paginated<ProductRow>>('/admin/products?perPage=100');
  const demand = useResource<DemandPayloadOs>('/admin/demand?status=all');

  const [filter, setFilter] = useState<'all' | 'qw'>('all');
  const [open, setOpen] = useState<string | null>(null);

  const states = [sitemap.state, llms.state, robots.state, queries.state, synonyms.state, crawl.state, products.state, demand.state];
  const problem = firstProblem(states);
  const loading = anyLoading(states);

  const rows = useMemo(() => {
    const findings = deriveFindings({
      sitemap: dataOf(sitemap.state),
      llms: dataOf(llms.state),
      robots: dataOf(robots.state),
      queries: dataOf(queries.state),
      synonyms: dataOf(synonyms.state)?.data ?? null,
      crawl: dataOf(crawl.state),
      products: dataOf(products.state),
    });
    return deriveOpportunities({
      findings,
      demand: dataOf(demand.state),
      crawl: dataOf(crawl.state),
    });
  }, [sitemap.state, llms.state, robots.state, queries.state, synonyms.state, crawl.state, products.state, demand.state]);

  const visible = filter === 'qw' ? rows.filter((row) => row.quickWin) : rows;

  return (
    <div style={S.page}>
      <PageHeader trail="Analytics Pro — الفرص" title="محرك الفرص" />
      <OsNav current="opps" />

      <div style={S.row}>
        <h1 style={S.h1}>محرك الفرص</h1>
        <span style={S.dim}>
          الدرجة = طلب × إمكانية ترتيب × قيمة تجارية × تحويل × ثقة ÷ جهد — افتح أي درجة لتفكيكها. لا درجة
          مُركَّبة تُعرض: أغلب العوامل غير مقاسة، والتفكيك يسمّي كل عامل وما ينقصه.
        </span>
        <div style={{ marginInlineStart: 'auto', display: 'flex', gap: 5 }}>
          {FILTERS.map((entry) => (
            <button
              key={entry.key}
              type="button"
              aria-pressed={filter === entry.key}
              style={filter === entry.key ? S.filterOn : S.filterOff}
              onClick={() => setFilter(entry.key)}
            >
              {entry.label}
            </button>
          ))}
        </div>
      </div>

      {loading ? (
        <p className="state-line" role="status" aria-live="polite">
          جارٍ اشتقاق الفرص من نقاط النهاية القائمة…
        </p>
      ) : null}
      {problem ? (
        <div className="state-error" role="alert">
          <p>{problem}</p>
        </div>
      ) : null}

      <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
        {visible.map((row) => {
          const expanded = open === row.id;
          const partsId = `opp-parts-${row.id}`;
          return (
            <section key={row.id} style={S.card}>
              <div style={S.row}>
                <span style={S.badge} aria-label="الدرجة غير مقاسة">
                  غير مقاس
                </span>
                <span style={{ flex: 1, minInlineSize: 220 }}>
                  <b style={S.title}>{row.title}</b>
                  <span style={S.desc}>{row.desc}</span>
                </span>
                {row.quickWin ? <span style={S.quickWin}>⚡ مكسب سريع — له نقطة نهاية اليوم</span> : null}
                <span style={S.revenueAbsent}>{OPP_REVENUE_ABSENT}</span>
                <span style={S.source}>
                  <bdi dir="ltr">{row.source}</bdi>
                </span>
                <button
                  type="button"
                  style={S.breakBtn}
                  aria-expanded={expanded}
                  aria-controls={partsId}
                  onClick={() => setOpen((current) => (current === row.id ? null : row.id))}
                >
                  تفكيك الدرجة
                </button>
                {row.action.href ? (
                  <Link href={row.action.href} style={S.actBtn}>
                    {row.action.label}
                  </Link>
                ) : (
                  <>
                    <button type="button" style={S.blockedBtn} disabled aria-describedby={`opp-why-${row.id}`}>
                      {row.action.label}
                    </button>
                    <span id={`opp-why-${row.id}`} style={S.blockedWhy}>
                      {row.action.blockedReason}
                    </span>
                  </>
                )}
              </div>
              {expanded ? (
                <div id={partsId} style={S.parts}>
                  {row.factors.map((factor) => (
                    <div key={factor.name} style={S.part}>
                      <span
                        style={{
                          display: 'inline-block',
                          paddingBlock: 1,
                          paddingInline: 7,
                          borderRadius: 5,
                          background: factor.measured ? 'var(--success-chip-bg)' : 'var(--apb-neutral)',
                          color: factor.measured ? 'var(--success-chip-fg)' : 'var(--text-muted)',
                          fontSize: 11.5,
                          fontWeight: 900,
                        }}
                      >
                        {factor.measured ? 'مقاس' : 'غير مقاس'}
                      </span>
                      <span style={{ display: 'block', fontSize: 12, fontWeight: 900, color: 'var(--ink)', marginBlockStart: 3 }}>
                        {factor.name}
                      </span>
                      <span style={{ display: 'block', fontSize: 11.5, lineHeight: 1.7, color: 'var(--apb-dim)' }}>
                        {factor.note}
                      </span>
                    </div>
                  ))}
                </div>
              ) : null}
            </section>
          );
        })}
        {!loading && visible.length === 0 ? (
          <p className="state-line">
            {filter === 'qw'
              ? 'لا فرصة إصلاحُها مسنود بنقطة نهاية قائمة الآن — بدّل إلى «كل الفرص».'
              : `لا فرصة مشتقّة من القياسات الحالية — عُدّ الصفوف الحقيقي ${count(rows.length)}.`}
          </p>
        ) : null}
      </div>
    </div>
  );
}
