'use client';
// UNIT: PAGE-ANALYTICS-OS-AI
// FILE: page.tsx
// ROUTE: /analytics/os/ai
// STYLE: none
// PARENT: none
// USES: AI-REQUEST-LOG, AI-USAGE-PANEL, DERIVE, LIB-ADMIN-API, LIB-FORMAT, PRODUCT-FACTORY-PANEL +4
// API: /admin/crawl, /admin/dashboard, /admin/demand, /admin/products, /admin/search/index, /admin/search/queries +1
// MAP: docs/PROJECT_MAP.md

/**
 * المساعد والتعلم — أربعة أسئلة محسوبة من بيانات حقيقية فقط.
 *
 * لا حقل سؤال حر (لا محرّك لغة)، ولا ثقة تُعرض (لا نموذج ولا حقيقة أرضية)،
 * ولا «نظام تعلم» يُدّعى: ذاكرة النتائج غير موجودة، فمكانها المعايرة
 * الحقيقية الوحيدة — بوابات حجم العيّنة (growth/_governance.ts SAMPLE_FLOORS)
 * مقابل أرقام لوحة القياس الحية. كل صف إجابة يحمل شارة مصدره، وسؤال
 * «لماذا هبط الإيراد» يُرفض بصدق مع تسمية الخطوط الغائبة.
 */

import Link from 'next/link';
import { useMemo, useState } from 'react';
import type { CSSProperties } from 'react';
import { AiRequestLog } from '../../../../../components/ai/ai-request-log';
import { AiUsagePanel } from '../../../../../components/ai/ai-usage-panel';
import { ProductFactoryPanel } from '../../../../../components/ai/product-factory-panel';
import { PageHeader, useResource } from '../../../../../components/resource';
import type { Dashboard, Paginated, ProductRow, SearchIndexStatus, SearchQueriesReport, SynonymRow } from '../../../../../lib/admin-api';
import { count, dateTime } from '../../../../../lib/format';
import { SAMPLE_FLOORS } from '../../../growth/_governance';
import { deriveFindings } from '../../../seo/_findings';
import { anyLoading, dataOf, firstProblem, type LlmsCatalog, type RobotsPolicy, type SitemapIndex } from '../../../seo/_kit';
import {
  ENDPOINT_ACTIONS,
  floorMeasure,
  missingFeed,
  OsNav,
  signed,
  type CrawlPayloadOs,
  type DemandPayloadOs,
} from '../_lib/derive';

const S: Record<string, CSSProperties> = {
  page: { display: 'flex', flexDirection: 'column', gap: 13 },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 12, alignItems: 'start' },
  card: {
    border: '1px solid var(--apb-border)',
    borderRadius: 16,
    background: 'var(--surface)',
    paddingBlock: 16,
    paddingInline: 19,
  },
  sideCard: {
    border: '1px solid var(--apb-border)',
    borderRadius: 15,
    background: 'var(--surface)',
    paddingBlock: 14,
    paddingInline: 17,
  },
  h1: { margin: 0, marginBlockEnd: 4, fontSize: 16, fontWeight: 900, color: 'var(--ink)' },
  h2: { margin: 0, marginBlockEnd: 8, fontSize: 13, fontWeight: 900, color: 'var(--ink)' },
  dim: { fontSize: 11.5, color: 'var(--apb-dim)' },
  faint: { fontSize: 11.5, color: 'var(--apb-faint)' },
  qOn: {
    minBlockSize: 44,
    paddingInline: 12,
    border: '1px solid var(--cc-text)',
    borderRadius: 999,
    background: 'var(--cc-text)',
    color: 'var(--cc-bg1)',
    fontSize: 11.5,
    fontWeight: 800,
    cursor: 'pointer',
  },
  qOff: {
    minBlockSize: 44,
    paddingInline: 12,
    border: '1px solid var(--apb-border)',
    borderRadius: 999,
    background: 'var(--surface)',
    color: 'var(--apb-strong)',
    fontSize: 11.5,
    fontWeight: 800,
    cursor: 'pointer',
  },
  answerBox: {
    paddingBlock: 14,
    paddingInline: 16,
    border: '1px solid var(--apb-hairline)',
    borderRadius: 13,
    background: 'var(--apb-surface-soft)',
  },
  answer: { margin: 0, marginBlockEnd: 9, fontSize: 12, lineHeight: 2, color: 'var(--ink)' },
  answerRow: {
    display: 'flex',
    alignItems: 'center',
    gap: 8,
    paddingBlock: 5,
    borderBlockStart: '1px dashed var(--apb-hairline)',
    flexWrap: 'wrap',
  },
  cta: {
    display: 'inline-flex',
    alignItems: 'center',
    minBlockSize: 44,
    paddingInline: 14,
    border: 0,
    borderRadius: 9,
    background: 'var(--green-700)',
    color: 'var(--adm-on-accent)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  ctaBlocked: {
    minBlockSize: 44,
    paddingInline: 14,
    border: '1px solid var(--apb-border)',
    borderRadius: 9,
    background: 'var(--apb-neutral)',
    color: 'var(--apb-dim)',
    fontSize: 11.5,
    fontWeight: 900,
    cursor: 'not-allowed',
  },
  briefLine: { display: 'flex', alignItems: 'center', gap: 7, paddingBlock: 3, fontSize: 12, color: 'var(--apb-strong)', flexWrap: 'wrap' },
  dot: { inlineSize: 7, blockSize: 7, borderRadius: '50%', display: 'inline-block', flexShrink: 0 },
  gate: {
    paddingBlock: 2,
    paddingInline: 9,
    borderRadius: 999,
    fontSize: 11.5,
    fontWeight: 900,
  },
};

const CHIP: Record<'ok' | 'warn' | 'info', CSSProperties> = {
  ok: { background: 'var(--success-chip-bg)', color: 'var(--success-chip-fg)' },
  warn: { background: 'var(--warning-bg)', color: 'var(--warning-text)' },
  info: { background: 'var(--info-bg)', color: 'var(--info)' },
};

interface AnswerRow {
  label: string;
  value: string;
  source: string;
  kind: 'ok' | 'warn' | 'info';
}

interface Question {
  name: string;
  answer: string;
  rows: AnswerRow[];
  cta: { label: string; href?: string; blockedReason?: string };
}

export default function AssistantPage() {
  const dashboard = useResource<Dashboard>('/admin/dashboard');
  const sitemap = useResource<SitemapIndex>('/sitemap/index');
  const llms = useResource<LlmsCatalog>('/llms-data');
  const robots = useResource<RobotsPolicy>('/robots');
  const queries = useResource<SearchQueriesReport>('/admin/search/queries?days=30');
  const synonyms = useResource<Paginated<SynonymRow>>('/admin/search/synonyms?perPage=100');
  const crawl = useResource<CrawlPayloadOs>('/admin/crawl');
  const products = useResource<Paginated<ProductRow>>('/admin/products?perPage=100');
  const demand = useResource<DemandPayloadOs>('/admin/demand?status=all');
  const index = useResource<SearchIndexStatus>('/admin/search/index');

  const [selected, setSelected] = useState(0);

  const states = [dashboard.state, sitemap.state, llms.state, robots.state, queries.state, synonyms.state, crawl.state, products.state, demand.state, index.state];
  const loading = anyLoading(states);
  const problem = firstProblem(states);

  const findings = useMemo(
    () =>
      deriveFindings({
        sitemap: dataOf(sitemap.state),
        llms: dataOf(llms.state),
        robots: dataOf(robots.state),
        queries: dataOf(queries.state),
        synonyms: dataOf(synonyms.state)?.data ?? null,
        crawl: dataOf(crawl.state),
        products: dataOf(products.state),
      }),
    [sitemap.state, llms.state, robots.state, queries.state, synonyms.state, crawl.state, products.state],
  );

  const dashboardData = dataOf(dashboard.state);
  const crawlReport = dataOf(crawl.state)?.data ?? null;
  const indexData = dataOf(index.state);
  const topDemand = dataOf(demand.state)?.data.filter((row) => row.status === 'new')[0] ?? null;
  const riskCount = findings.filter((finding) => finding.severity === 'risk').length;
  const gapCount = findings.filter((finding) => finding.severity === 'gap').length;

  const questions: Question[] = [
    {
      name: 'لماذا هبط الإيراد العضوي؟',
      answer:
        'لا يُجاب من بيانات غير موجودة: لا سلاسل زمنية للإيراد ولا لقطات تاريخية تُقارن، فلا هبوط يُكشف آليًا ولا يُنسب له سبب. الخطوط الغائبة مسمّاة أدناه — وشاشة السبب الجذري تعرض المسح الذي يُنفَّذ فعلًا اليوم.',
      rows: ['timeseries', 'product-revenue', 'external-search']
        .map((id) => missingFeed(id))
        .filter((feed) => feed !== null)
        .map((feed) => ({ label: feed.name, value: 'غير مُهيأ', source: 'لا مسار', kind: 'warn' as const })),
      cta: { label: 'افتح تحقيق السبب الجذري', href: '/analytics/os/cause' },
    },
    {
      name: 'أين أعلى فرص القيمة الآن؟',
      answer: `الاشتقاق المحلي يعلّم ${count(riskCount)} بندًا حرِجًا و${count(gapCount)} تحذيرًا فوق ردود الخادم${
        topDemand
          ? `، وأعلى طلب فائت حقيقي «${topDemand.query ?? ([topDemand.subject, topDemand.grade].filter(Boolean).join(' · ') || 'بلا تصنيف')}» بواقع ${count(topDemand.hits)} طلبًا`
          : ''
      } — الترتيب بقوة الدليل المقيس، لا بدرجة مُركَّبة.`,
      rows: [
        { label: 'بنود حرِجة (اشتقاق محلي)', value: count(riskCount), source: 'seo/_findings.ts', kind: 'ok' as const },
        { label: 'تحذيرات (اشتقاق محلي)', value: count(gapCount), source: 'seo/_findings.ts', kind: 'ok' as const },
        ...(topDemand
          ? [
              {
                label: 'أعلى طلب فائت مسجَّل',
                value: `${count(topDemand.hits)} طلبًا`,
                source: 'GET /admin/demand',
                kind: 'ok' as const,
              },
            ]
          : []),
      ],
      cta: { label: 'افتح محرك الفرص', href: '/analytics/os/opps' },
    },
    {
      name: 'ما الذي يمكن تنفيذه آليًا بأمان الآن؟',
      answer: `${count(ENDPOINT_ACTIONS.length)} إجراءات لها نقطة نهاية فعلية في الخادم اليوم — هذه وحدها «قابلة للتنفيذ»، وكل ما عداها دليل بلا زر. التنفيذ من مركز الإجراءات، وكل إجراء بصلاحيته المسمّاة وسجل تدقيقه.`,
      rows: ENDPOINT_ACTIONS.map((action) => ({
        label: action.label,
        value: action.method,
        source: action.endpoint,
        kind: 'ok' as const,
      })),
      cta: { label: 'افتح مركز الإجراءات', href: '/analytics/os/actions' },
    },
    crawlReport?.previousRun
      ? {
          name: 'ماذا تغير بعد آخر نشر؟',
          answer: `لا حارس ما بعد النشر يربط التغيّر بنشرة بعينها. المتاح فعلًا: فرق آخر جولتي زحف مكتملتين (#${count(crawlReport.previousRun.runId)} ← #${count(crawlReport.runId)}) — الموجب في المكسورة واليتامى تدهور.`,
          rows: [
            {
              label: 'صفحات مزحوفة',
              value: signed(crawlReport.previousRun.deltas.pages),
              source: 'GET /admin/crawl → previousRun.deltas',
              kind: 'info' as const,
            },
            {
              label: 'روابط مكسورة',
              value: signed(crawlReport.previousRun.deltas.broken),
              source: 'GET /admin/crawl → previousRun.deltas',
              kind: crawlReport.previousRun.deltas.broken > 0 ? ('warn' as const) : ('ok' as const),
            },
            {
              label: 'صفحات يتيمة',
              value: signed(crawlReport.previousRun.deltas.orphans),
              source: 'GET /admin/crawl → previousRun.deltas',
              kind: crawlReport.previousRun.deltas.orphans > 0 ? ('warn' as const) : ('ok' as const),
            },
          ],
          cta: { label: 'افتح خريطة الزحف', href: '/crawl' },
        }
      : {
          name: 'ماذا تغير بعد آخر نشر؟',
          answer:
            'لا حارس ما بعد النشر في هذا النظام، ولا جولة زحف سابقة تُقارن بها الجولة الحالية — فالسؤال بلا إجابة صادقة اليوم. بعد جولتين مكتملتين يعرض هذا السؤال فرق المكسور واليتيم بينهما من الخادم نفسه.',
          rows: [
            {
              label: 'الجولة المكتملة السابقة',
              value: 'لا توجد',
              source: 'GET /admin/crawl → previousRun = null',
              kind: 'warn' as const,
            },
          ],
          cta: {
            label: 'قارن قبل/بعد',
            blockedReason: 'معطَّل — يحتاج جولتي زحف مكتملتين؛ شغّل جولة بعد التغيير ثم عُد.',
          },
        },
  ];

  const active = questions[selected] ?? questions[0];

  return (
    <div style={S.page}>
      <PageHeader trail="Analytics Pro — المساعد والتعلم" title="المساعد والتعلم" />
      <OsNav current="ai" />
      {/* الإنفاق أوّلًا: سؤال «كم يكلّفنا هذا» يسبق «ماذا يقترح». */}
      <AiUsagePanel />
      <AiRequestLog />
      <ProductFactoryPanel />

      {loading ? (
        <p className="state-line" role="status" aria-live="polite">
          جارٍ قراءة المصادر العشرة التي تُجيب منها الأسئلة…
        </p>
      ) : null}
      {problem ? (
        <div className="state-error" role="alert">
          <p>{problem}</p>
        </div>
      ) : null}

      <div style={S.grid}>
        <section style={S.card} aria-labelledby="ai-head">
          <h1 id="ai-head" style={S.h1}>
            اسأل Analytics Pro
          </h1>
          <span style={S.dim}>
            يستعلم بياناتك الفعلية فقط — كل إجابة بمصدرها. لا حقل سؤال حر: لا محرّك لغة، أربعة أسئلة محسوبة
            فقط.
          </span>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBlock: 11 }}>
            {questions.map((question, questionIndex) => (
              <button
                key={question.name}
                type="button"
                aria-pressed={selected === questionIndex}
                style={selected === questionIndex ? S.qOn : S.qOff}
                onClick={() => setSelected(questionIndex)}
              >
                {question.name}
              </button>
            ))}
          </div>
          {active ? (
            <div style={S.answerBox}>
              <p style={S.answer}>{active.answer}</p>
              {active.rows.map((row) => (
                <div key={row.label} style={S.answerRow}>
                  <span style={{ flex: 1, minInlineSize: 180, fontSize: 12, fontWeight: 800, color: 'var(--apb-strong)' }}>
                    {row.label}
                  </span>
                  <b style={{ fontSize: 11.5, fontWeight: 900, color: 'var(--success-chip-fg)' }}>
                    <bdi dir="ltr">{row.value}</bdi>
                  </b>
                  <span style={{ paddingBlock: 1, paddingInline: 7, borderRadius: 5, fontSize: 11.5, fontWeight: 900, ...CHIP[row.kind] }}>
                    <bdi dir="ltr">{row.source}</bdi>
                  </span>
                </div>
              ))}
              <div style={{ display: 'flex', gap: 8, marginBlockStart: 9, flexWrap: 'wrap', alignItems: 'center' }}>
                {active.cta.href ? (
                  <Link href={active.cta.href} style={S.cta}>
                    {active.cta.label}
                  </Link>
                ) : (
                  <>
                    <button type="button" style={S.ctaBlocked} disabled aria-describedby="ai-cta-why">
                      {active.cta.label}
                    </button>
                    <span id="ai-cta-why" style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--warning-text)' }}>
                      {active.cta.blockedReason}
                    </span>
                  </>
                )}
                <span style={S.faint}>
                  ثقة الإجابة: لا تُعرض — لا نموذج ولا حقيقة أرضية · آخر توليد بيانات اللوحة:{' '}
                  {dashboardData ? dateTime(dashboardData.generatedAt) : 'لم يصل بعد'}
                </span>
              </div>
            </div>
          ) : null}
        </section>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <section style={S.sideCard} aria-labelledby="learn-head">
            <h2 id="learn-head" style={S.h2}>
              التعلم — لا ذاكرة نتائج بعد
            </h2>
            <p style={{ margin: 0, marginBlockEnd: 6, fontSize: 11.5, lineHeight: 1.8, color: 'var(--apb-soft)' }}>
              لا سجل «نجح/فشل» لكل إجراء (لا مخزن نتائج)، فلا أوزان تُعاير من نتائجك. المعايرة الحقيقية
              الموجودة: بوابات حجم العيّنة — حدّ حاكمية معلن مقابل قياس حي من لوحة القياس.
            </p>
            {dashboardData ? (
              SAMPLE_FLOORS.map((floor) => {
                const measured = floorMeasure(dashboardData, floor.metric);
                const passes = measured.value !== null && measured.value >= floor.floor;
                return (
                  <div key={floor.name} style={{ paddingBlock: 8, borderBlockStart: '1px dashed var(--apb-hairline)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 7, flexWrap: 'wrap' }}>
                      <b style={{ fontSize: 12, color: 'var(--ink)' }}>{floor.name}</b>
                      <span
                        style={{
                          marginInlineStart: 'auto',
                          ...S.gate,
                          ...(measured.value === null
                            ? CHIP.warn
                            : passes
                              ? CHIP.ok
                              : CHIP.info),
                        }}
                      >
                        {measured.value === null ? 'غير مقيس' : passes ? 'بلغت الحد' : 'دون الحد — اقتراح فقط'}
                      </span>
                    </div>
                    <span style={{ display: 'block', marginBlockStart: 2, fontSize: 11.5, lineHeight: 1.7, color: 'var(--apb-soft)' }}>
                      الحد <bdi dir="ltr">{count(floor.floor)}</bdi> · المقيس الآن{' '}
                      {measured.value === null ? 'غير مقيس' : <bdi dir="ltr">{count(measured.value)}</bdi>} —{' '}
                      <bdi dir="ltr">{measured.source}</bdi>
                    </span>
                  </div>
                );
              })
            ) : (
              <p className="state-line" style={{ marginBlock: 0 }}>
                عمود «المقيس الآن» ينتظر ردّ GET /admin/dashboard.
              </p>
            )}
          </section>

          <section style={S.sideCard} aria-labelledby="brief-head">
            <h2 id="brief-head" style={S.h2}>
              الموجز اليومي — من ردود هذه الجلسة
            </h2>
            <span style={S.briefLine}>
              <span style={{ ...S.dot, background: riskCount > 0 ? 'var(--apb-danger)' : 'var(--success-chip-fg)' }} aria-hidden="true" />
              الاشتقاق المحلي: <bdi dir="ltr">{count(riskCount)}</bdi> حرِج · <bdi dir="ltr">{count(gapCount)}</bdi> تحذير
              <span style={{ ...S.faint }}>seo/_findings.ts</span>
            </span>
            {crawlReport ? (
              <span style={S.briefLine}>
                <span style={{ ...S.dot, background: 'var(--ink)' }} aria-hidden="true" />
                الزحف: <bdi dir="ltr">{count(crawlReport.totals.pages)}</bdi> صفحة · <bdi dir="ltr">{count(crawlReport.totals.broken)}</bdi> مكسورة ·{' '}
                <bdi dir="ltr">{count(crawlReport.totals.orphans)}</bdi> يتيمة — جولة #<bdi dir="ltr">{count(crawlReport.runId)}</bdi>
                <span style={{ ...S.faint }}>GET /admin/crawl</span>
              </span>
            ) : (
              <span style={S.briefLine}>
                <span style={{ ...S.dot, background: 'var(--apb-faint)' }} aria-hidden="true" />
                الزحف: {dataOf(crawl.state)?.message ?? 'الردّ غير متاح'}
                <span style={{ ...S.faint }}>GET /admin/crawl</span>
              </span>
            )}
            {indexData ? (
              <span style={S.briefLine}>
                <span style={{ ...S.dot, background: 'var(--info)' }} aria-hidden="true" />
                فهرس البحث: الجيل <bdi dir="ltr">{count(indexData.generation)}</bdi> · التقديم عبر{' '}
                <bdi dir="ltr">{indexData.servingPath}</bdi>
                <span style={{ ...S.faint }}>GET /admin/search/index</span>
              </span>
            ) : null}
            {topDemand ? (
              <span style={S.briefLine}>
                <span style={{ ...S.dot, background: 'var(--gold)' }} aria-hidden="true" />
                أعلى طلب فائت بلا قرار: <bdi dir="ltr">{count(topDemand.hits)}</bdi> طلبًا · آخر ظهور {dateTime(topDemand.lastSeenAt)}
                <span style={{ ...S.faint }}>GET /admin/demand</span>
              </span>
            ) : null}
            <p style={{ margin: 0, marginBlockStart: 8, ...S.faint, lineHeight: 1.7 }}>
              كل سطر أعلاه من ردّ وصل في هذه الجلسة، بمصدره — لا «قبل ٩ دقائق» مُختلَقة: وقت التوليد الوحيد
              المعلن من الخادم هو {dashboardData ? dateTime(dashboardData.generatedAt) : '—'} (dashboard.generatedAt).
            </p>
          </section>
        </div>
      </div>
    </div>
  );
}
