'use client';
// UNIT: PAGE-ANALYTICS-OS-CAUSE
// FILE: page.tsx
// ROUTE: /analytics/os/cause
// STYLE: none
// PARENT: none
// USES: DERIVE, LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _KIT
// API: /admin/crawl, /admin/search/index, /admin/search/queries
// MAP: docs/PROJECT_MAP.md

/**
 * السبب الجذري — مسح إشارات حقيقي، وتحقيق هبوط مُعلَن الغياب.
 *
 * لا كشف هبوط آليًا: لا سلاسل زمنية محفوظة ولا لقطات تاريخية، فالترويسة
 * حالة غياب مسمّاة لا رقم «↓٢٢٪» مُختلَق. النواة الصادقة هي المسح: ست
 * نقاط نهاية قائمة تُفحص فعلًا، وكل شارة تحمل مسارها، والمُعلَّم يُرتَّب
 * بقوة دليله المقيس — بلا أشرطة نسب، لأن الإسناد يحتاج إيرادًا لكل صفحة
 * وهو غير موصول (MISSING_FEEDS.product-revenue).
 */

import { useMemo } from 'react';
import type { CSSProperties } from 'react';
import { PageHeader, useResource } from '../../../../../components/resource';
import type { SearchIndexStatus, SearchQueriesReport } from '../../../../../lib/admin-api';
import { count } from '../../../../../lib/format';
import { anyLoading, dataOf, firstProblem, type LlmsCatalog, type RobotsPolicy, type SitemapIndex } from '../../../seo/_kit';
import { missingFeed, OsNav, sweep, type CrawlPayloadOs, type SweepCheck } from '../_lib/derive';

const S: Record<string, CSSProperties> = {
  page: { display: 'flex', flexDirection: 'column', gap: 13 },
  card: {
    border: '1px solid var(--apb-border)',
    borderRadius: 16,
    background: 'var(--surface)',
    paddingBlock: 15,
    paddingInline: 19,
  },
  row: { display: 'flex', alignItems: 'center', gap: 9, flexWrap: 'wrap' },
  h1: { margin: 0, fontSize: 19, fontWeight: 900, color: 'var(--ink)' },
  h2: { margin: 0, fontSize: 12.5, fontWeight: 900, color: 'var(--ink)' },
  dim: { fontSize: 11.5, color: 'var(--apb-dim)' },
  chipNoData: {
    paddingBlock: 3,
    paddingInline: 11,
    borderRadius: 999,
    background: 'var(--warning-bg)',
    color: 'var(--warning-text)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  chipFlagged: {
    paddingBlock: 3,
    paddingInline: 11,
    borderRadius: 999,
    background: 'var(--apb-danger-bg)',
    color: 'var(--apb-danger)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  chipUnavailable: {
    paddingBlock: 3,
    paddingInline: 11,
    borderRadius: 999,
    background: 'var(--apb-neutral)',
    color: 'var(--text-muted)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  chipShare: {
    marginInlineStart: 'auto',
    paddingBlock: 2,
    paddingInline: 9,
    borderRadius: 6,
    background: 'var(--apb-neutral)',
    fontSize: 11.5,
    fontWeight: 900,
    color: 'var(--text-muted)',
  },
  endpoint: {
    paddingBlock: 1,
    paddingInline: 7,
    borderRadius: 5,
    background: 'var(--apb-neutral)',
    fontSize: 11.5,
    fontWeight: 900,
    color: 'var(--text-muted)',
  },
  evidence: { margin: 0, marginBlockStart: 7, fontSize: 11.5, lineHeight: 1.8, color: 'var(--text-muted)' },
  needList: { margin: 0, marginBlockStart: 8, paddingInlineStart: 18, fontSize: 11.5, lineHeight: 1.9, color: 'var(--text-muted)' },
  clearedChip: {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 5,
    paddingBlock: 4,
    paddingInline: 11,
    borderRadius: 999,
    background: 'var(--apb-neutral-2)',
    fontSize: 11.5,
    fontWeight: 800,
    color: 'var(--text-muted)',
  },
};

const STATUS_META: Record<SweepCheck['status'], { label: string; style: CSSProperties }> = {
  flagged: { label: 'مُعلَّم — دليل مقيس', style: S.chipFlagged ?? {} },
  unavailable: { label: 'تعذّر الفحص', style: S.chipUnavailable ?? {} },
  clear: { label: 'فُحص واستُبعد', style: S.chipUnavailable ?? {} },
};

export default function CausePage() {
  const robots = useResource<RobotsPolicy>('/robots');
  const sitemap = useResource<SitemapIndex>('/sitemap/index');
  const llms = useResource<LlmsCatalog>('/llms-data');
  const index = useResource<SearchIndexStatus>('/admin/search/index');
  const crawl = useResource<CrawlPayloadOs>('/admin/crawl');
  const queries = useResource<SearchQueriesReport>('/admin/search/queries?days=30');

  const states = [robots.state, sitemap.state, llms.state, index.state, crawl.state, queries.state];
  const problem = firstProblem(states);
  const loading = anyLoading(states);

  const checks = useMemo(
    () =>
      sweep({
        robots: dataOf(robots.state),
        sitemap: dataOf(sitemap.state),
        llms: dataOf(llms.state),
        index: dataOf(index.state),
        crawl: dataOf(crawl.state),
        queries: dataOf(queries.state),
      }),
    [robots.state, sitemap.state, llms.state, index.state, crawl.state, queries.state],
  );

  const rescan = () => {
    robots.reload();
    sitemap.reload();
    llms.reload();
    index.reload();
    crawl.reload();
    queries.reload();
  };

  const flagged = checks.filter((check) => check.status === 'flagged');
  const unavailable = checks.filter((check) => check.status === 'unavailable');
  const cleared = checks.filter((check) => check.status === 'clear');
  const executed = checks.length - unavailable.length;
  const investigationNeeds = ['timeseries', 'product-revenue', 'external-search']
    .map((id) => missingFeed(id))
    .filter((feed) => feed !== null);

  return (
    <div style={S.page}>
      <PageHeader trail="Analytics Pro — السبب الجذري" title="السبب الجذري" />
      <OsNav current="cause" />

      <section style={S.card} aria-labelledby="cause-head">
        <div style={S.row}>
          <h1 id="cause-head" style={S.h1}>
            تحقيق آلي في هبوط الإيراد
          </h1>
          <span style={S.chipNoData}>لا يُنفَّذ — الغياب مسمّى</span>
          <span style={{ ...S.dim, marginInlineStart: 'auto' }}>ارتباط لا سببية حتى يُثبت</span>
        </div>
        <p style={S.evidence}>
          لا سلاسل زمنية محفوظة — لا يُكشف هبوط آليًا ولا تُنسب له أسباب. الرقم «↓٪ خلال ٧ أيام» يحتاج لقطات
          تاريخية تُقارن، ولا لقطة واحدة محفوظة في هذا النظام. ما يحتاجه تحقيق حقيقي، بالاسم:
        </p>
        <ul style={S.needList}>
          {investigationNeeds.map((feed) => (
            <li key={feed.id}>
              <b style={{ color: 'var(--apb-strong)' }}>{feed.name}</b> — {feed.note}
            </li>
          ))}
        </ul>
      </section>

      <div style={S.row}>
        <h2 style={{ ...S.h2, fontSize: 13.5 }}>مسح الإشارات — يُنفَّذ فعلًا الآن</h2>
        <span style={S.dim}>
          نُفِّذ <bdi dir="ltr">{count(executed)}</bdi> من <bdi dir="ltr">{count(checks.length)}</bdi> فحوص · كل شارة تحمل نقطة نهايتها
        </span>
        <button
          type="button"
          className="btn btn-ghost"
          style={{ marginInlineStart: 'auto', minBlockSize: 44 }}
          onClick={rescan}
        >
          أعد الفحص
        </button>
      </div>

      {loading ? (
        <p className="state-line" role="status" aria-live="polite">
          جارٍ تنفيذ المسح على نقاط النهاية الست…
        </p>
      ) : null}
      {problem ? (
        <div className="state-error" role="alert">
          <p>{problem}</p>
          <button type="button" className="btn btn-ghost" onClick={rescan}>
            إعادة المحاولة
          </button>
        </div>
      ) : null}

      <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
        {[...flagged, ...unavailable].map((check) => {
          const meta = STATUS_META[check.status];
          return (
            <section key={check.id} style={{ ...S.card, borderRadius: 14, paddingBlock: 12, paddingInline: 16 }}>
              <div style={S.row}>
                <span style={meta.style}>{meta.label}</span>
                <b style={{ fontSize: 12.5, color: 'var(--ink)' }}>{check.name}</b>
                <span style={S.endpoint}>
                  <bdi dir="ltr">{check.endpoint}</bdi>
                </span>
                <span style={S.chipShare}>لا نسبة — لا إسناد إيراد لكل صفحة</span>
              </div>
              {check.evidence.map((line) => (
                <p key={line} style={S.evidence}>
                  <b style={{ color: 'var(--apb-strong)' }}>الدليل:</b> {line}
                </p>
              ))}
              {check.fix ? (
                <p style={{ ...S.evidence, marginBlockStart: 4 }}>
                  <b style={{ color: 'var(--success-chip-fg)' }}>— الإجراء:</b> {check.fix}
                </p>
              ) : null}
            </section>
          );
        })}
        {!loading && flagged.length === 0 && unavailable.length === 0 ? (
          <p className="state-line">لا إشارة مُعلَّمة في هذا المسح — كل الفحوص الست استُبعدت أدناه.</p>
        ) : null}
      </div>

      <section style={{ ...S.card, borderRadius: 14, paddingBlock: 13, paddingInline: 16 }}>
        <h2 style={{ ...S.h2, marginBlockEnd: 7 }}>الإشارات التي فُحصت واستُبعدت</h2>
        {cleared.length === 0 ? (
          <p className="state-line" style={{ marginBlock: 0 }}>
            لا إشارة استُبعدت بعد — الاستبعاد يُعلن فقط لفحص نُفِّذ ورجع سليمًا.
          </p>
        ) : (
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            {cleared.map((check) => (
              <span key={check.id} style={S.clearedChip}>
                <svg
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                  focusable="false"
                  style={{
                    inlineSize: 10,
                    blockSize: 10,
                    fill: 'none',
                    stroke: 'var(--success-chip-fg)',
                    strokeWidth: 2.4,
                    strokeLinecap: 'round',
                    strokeLinejoin: 'round',
                  }}
                >
                  <path d="M5 12.5l4.5 4.5L19 7" />
                </svg>
                {check.name}
                <bdi dir="ltr" style={{ fontSize: 11.5, color: 'var(--apb-faint)' }}>
                  {check.endpoint}
                </bdi>
              </span>
            ))}
          </div>
        )}
        <p style={{ ...S.evidence, marginBlockStart: 8 }}>
          كل شارة أعلاه فحصٌ نُفِّذ فعلًا في هذه الجلسة عبر نقطة النهاية المذكورة — لا بند «سليم» بلا قياس.
        </p>
      </section>
    </div>
  );
}
