'use client';
// UNIT: GLOSSARY
// FILE: glossary.tsx
// STYLE: search.module.css
// PARENT: SEARCH-PANEL
// USES: LIB-ADMIN-API, LIB-FORMAT, LIB-REGISTRY, LIB-SHARED, RESOURCE
// API: /admin/crawl, /admin/dashboard, /admin/i18n/hreflang-audit, /admin/search/index, /admin/search/queries
// MAP: docs/PROJECT_MAP.md

/**
 * القاموس الشامل — ٣٠ مفهومًا مرجعيًا من سجل _lib/registry مع بحث وتصفية
 * كما في اللوحة حرفيًا.
 *
 * «قيمتك الآن» تُملأ فقط من مصادر حية قائمة فعلًا:
 *   CTR ← /admin/search/queries (بحث داخلي، لا SERP — موسوم كذلك) ·
 *   Crawl Budget ← /admin/crawl · PDP/PLP ← /admin/search/index ·
 *   AOV ← /admin/dashboard · hreflang ← /admin/i18n/hreflang-audit.
 * كل قيمة عيّنية أخرى في اللوحة مصدرها GSC/CrUX/GBP/مزوّدو طرف ثالث غير
 * متصلين — تُعرض بسطر غياب مكتوب بأسلوب وسم الصدق، لا برقم مُختلَق.
 */

import { useMemo, useState } from 'react';
import { useResource, type ResourceState } from '../../../../components/resource';
import type { Dashboard, SearchIndexStatus, SearchQueriesReport } from '../../../../lib/admin-api';
import { count, dateTime, money } from '../../../../lib/format';
import { GLOSSARY, GLOSSARY_CATEGORIES, type LiveSlot } from '../_lib/registry';
import { arDigits, percent } from '../_lib/shared';
import s from '../search.module.css';

/** الحقول المستهلكة فقط من GET /admin/crawl — الشكل من CrawlController::show. */
interface CrawlSummary {
  data: {
    finishedAt: string | null;
    totals: { pages: number; links: number; broken: number; orphans: number; maxDepth: number };
  } | null;
  message?: string;
}

/** الشكل من App\Domain\I18n\HreflangAuditor::run(). */
interface HreflangAudit {
  data: {
    scannedProducts: number;
    checkedAt: string;
    checks: { key: string; label: string; pass: boolean }[];
  };
}

type Resolved =
  | { kind: 'loading' }
  | { kind: 'error'; message: string }
  | { kind: 'empty'; reason: string }
  | { kind: 'value'; value: string; hint: string };

/**
 * الخطأ يُقرأ برمزه: ٤٠٣ صلاحيةٌ ناقصة تُسمّى، وما دونه رسالة الخادم كما هي.
 *
 * الشرائح الحيّة الخمس كلّها خلف `analytics.read` (routes/api-admin.php:71
 * و205-206 و215-216 و228 و396) — لكنّ القاموس نفسه نصٌّ ثابت لا يحتاجها،
 * فلا يُقفَل السطح كلّه على غيابها: يُقفَل السطر الذي كان سيعرض رقمًا.
 */
function slotError(state: ResourceState<unknown>): Resolved {
  if (state.status !== 'error') return { kind: 'loading' };
  return {
    kind: 'error',
    message:
      state.code === 403
        ? 'دورك لا يملك analytics.read — هذا القياس مقفل عليك، وتعريف المفهوم أعلاه يبقى مقروءًا.'
        : state.message,
  };
}

export default function GlossaryScreen() {
  const queries = useResource<SearchQueriesReport>('/admin/search/queries?days=30');
  const crawl = useResource<CrawlSummary>('/admin/crawl');
  const index = useResource<{ data: SearchIndexStatus }>('/admin/search/index');
  const dashboard = useResource<Dashboard>('/admin/dashboard');
  const hreflang = useResource<HreflangAudit>('/admin/i18n/hreflang-audit');

  const [query, setQuery] = useState('');
  const [category, setCategory] = useState('all');

  const rows = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return GLOSSARY.filter(
      (entry) =>
        (category === 'all' || entry.category === category) &&
        (needle === '' || (entry.abbr + entry.name + entry.definition).toLowerCase().includes(needle)),
    );
  }, [query, category]);

  function resolve(slot: LiveSlot): Resolved {
    switch (slot) {
      case 'queries': {
        if (queries.state.status === 'loading') return { kind: 'loading' };
        if (queries.state.status === 'error') return slotError(queries.state);
        const metrics = queries.state.data.metrics;
        const ctr = percent(metrics.ctr);
        const zero = percent(metrics.zeroResultRate);
        if (ctr === null && zero === null) {
          return { kind: 'empty', reason: 'لا بيانات كافية في سجل search_queries (آخر ٣٠ يومًا)' };
        }
        const parts: string[] = [];
        if (ctr !== null) parts.push(`CTR البحث الداخلي ${ctr}`);
        if (zero !== null) parts.push(`«بلا نتائج» ${zero}`);
        return {
          kind: 'value',
          value: parts.join(' · '),
          hint: 'بحث داخلي مُقاس من سجل متجرك — CTR نتائج جوجل يتطلب Search Console غير المتصل',
        };
      }
      case 'crawl': {
        if (crawl.state.status === 'loading') return { kind: 'loading' };
        if (crawl.state.status === 'error') return slotError(crawl.state);
        const report = crawl.state.data.data;
        if (report === null) {
          return {
            kind: 'empty',
            reason: crawl.state.data.message ?? 'لا جولة زحف مكتملة بعد — شغّلها من شاشة رسم الروابط الداخلية',
          };
        }
        return {
          kind: 'value',
          value: `${count(report.totals.pages)} صفحة مزحوفة · ${count(report.totals.links)} رابط داخلي · ${count(report.totals.orphans)} يتيمة`,
          hint: `آخر جولة اكتملت: ${dateTime(report.finishedAt)}`,
        };
      }
      case 'index': {
        if (index.state.status === 'loading') return { kind: 'loading' };
        if (index.state.status === 'error') return slotError(index.state);
        const status = index.state.data.data;
        return {
          kind: 'value',
          value: `${count(status.publishedProducts)} منتجًا منشورًا في فهرس البحث الداخلي`,
          hint:
            status.servingPath === 'meilisearch'
              ? 'يُخدم عبر Meilisearch'
              : 'يُخدم عبر احتياطي PostgreSQL الآن — التفاصيل في شاشة البحث الداخلي',
        };
      }
      case 'aov': {
        if (dashboard.state.status === 'loading') return { kind: 'loading' };
        if (dashboard.state.status === 'error') return slotError(dashboard.state);
        const revenue = dashboard.state.data.measured.revenue;
        if (revenue.ordersCount === 0) {
          return { kind: 'empty', reason: 'لا طلبات مدفوعة بعد — لا متوسط يُحسب' };
        }
        return {
          kind: 'value',
          value: `${money(Math.round(revenue.totalMinor / revenue.ordersCount))} ر.س`,
          hint: `الإيراد المدفوع ÷ ${count(revenue.ordersCount)} طلبًا مدفوعًا`,
        };
      }
      case 'hreflang': {
        if (hreflang.state.status === 'loading') return { kind: 'loading' };
        if (hreflang.state.status === 'error') return slotError(hreflang.state);
        const audit = hreflang.state.data.data;
        const failed = audit.checks.filter((check) => !check.pass).length;
        return {
          kind: 'value',
          value: `${count(audit.scannedProducts)} منتجًا مفحوصًا · ${
            failed === 0 ? 'كل الفحوص تجتاز' : `${count(failed)} من ${count(audit.checks.length)} فحوص تخفق`
          }`,
          hint: `تدقيق hreflang حي: ${dateTime(audit.checkedAt)}`,
        };
      }
    }
  }

  return (
    <div className={s.gloss}>
      <div className={s.glossHead}>
        <h1 className={s.glossTitle}>القاموس الشامل — {arDigits(GLOSSARY.length)} مفهومًا مرتبطًا ببياناتك</h1>
        <div className={s.searchWrap}>
          <input
            type="search"
            className={s.searchInput}
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="اكتب INP أو CTR أو hreflang…"
            aria-label="بحث القاموس"
          />
        </div>
        {['all', ...GLOSSARY_CATEGORIES].map((cat) => {
          const on = category === cat;
          return (
            <button
              key={cat}
              type="button"
              aria-pressed={on}
              className={[s.catPill, on ? s.catPillOn : ''].filter(Boolean).join(' ')}
              onClick={() => setCategory(cat)}
            >
              {cat === 'all' ? 'الكل' : cat}
            </button>
          );
        })}
      </div>

      {rows.length === 0 ? (
        <p className={s.empty}>لا مفاهيم تطابق بحثك — جرّب اختصارًا آخر أو صنّف بـ«الكل».</p>
      ) : (
        <div className={s.grid}>
          {rows.map((entry) => {
            const third = entry.flag.includes('طرف');
            return (
              <section key={entry.abbr} className={s.card}>
                <div className={s.cardHead}>
                  <code dir="ltr" className={s.abbr}>
                    {entry.abbr}
                  </code>
                  <b className={s.term}>{entry.name}</b>
                  <span className={s.catChip}>{entry.category}</span>
                  {entry.flag !== '' ? (
                    <span className={[s.flagChip, third ? s.flagThird : ''].filter(Boolean).join(' ')}>
                      {entry.flag}
                    </span>
                  ) : null}
                </div>
                <p className={s.def}>{entry.definition}</p>
                {entry.live !== undefined ? <ValueRow resolved={resolve(entry.live)} /> : null}
                {entry.absent !== undefined ? (
                  <div className={s.absentRow}>
                    <span className={s.absentChip}>{entry.absent}</span>
                  </div>
                ) : null}
              </section>
            );
          })}
        </div>
      )}
    </div>
  );
}

/** صف «قيمتك الآن» — الحالات الأربع صريحة، والغياب سبب مكتوب لا شرطة غامضة. */
function ValueRow({ resolved }: { resolved: Resolved }) {
  return (
    <div className={s.valRow}>
      <span className={s.valLabel}>قيمتك الآن:</span>
      {resolved.kind === 'loading' ? <span className={s.valHint}>يُجلب من الخادم…</span> : null}
      {resolved.kind === 'error' ? <span className={s.valHint}>تعذّر الجلب — {resolved.message}</span> : null}
      {resolved.kind === 'empty' ? (
        <>
          <b className={s.valText}>—</b>
          <span className={s.valHint}>{resolved.reason}</span>
        </>
      ) : null}
      {resolved.kind === 'value' ? (
        <>
          <b className={s.valText}>{resolved.value}</b>
          <span className={s.valHint}>{resolved.hint}</span>
        </>
      ) : null}
    </div>
  );
}
