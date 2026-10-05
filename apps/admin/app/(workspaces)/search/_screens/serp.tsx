'use client';
// UNIT: SERP
// FILE: serp.tsx
// STYLE: none
// PARENT: SEARCH-PANEL
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE
// API: /admin/crawl, /admin/search/queries
// MAP: docs/PROJECT_MAP.md

/**
 * ذكاء SERP والسلطة.
 *
 * التصميم: Tahdheer - Search Intelligence Center.dc.html (شاشة is.serp).
 * لا تكامل Search Console ولا مزوّد سلطة مرخّص في المنظومة، فكل صف يحمل شكل
 * التصميم مع حالة غياب مسمّاة بدل أرقام العيّنة — عدا صف «داخلي» الموصول
 * فعلًا بجولة الزحف (GET /admin/crawl) وموسوم «أصلي/مُقاس» كما يفرض التصميم.
 * الاستعلام المفحوص يُغذَّى من استعلامات البحث الداخلي الحقيقية المسجَّلة
 * (GET /admin/search/queries) — عنوان التصميم «ملزمة رياضيات أول ثانوي» عيّنة.
 */

import { useState, type CSSProperties, type ReactNode } from 'react';
import { useResource, type ResourceState } from '../../../../components/resource';
import { type SearchQueriesReport } from '../../../../lib/admin-api';
import { count, dateTime } from '../../../../lib/format';
import '../../../../../../.design-sync/tokens-3p/search-intel-b.css';

interface CrawlTotals {
  pages: number;
  links: number;
  broken: number;
  orphans: number;
  maxDepth: number;
}

interface CrawlEnvelope {
  data: {
    runId: number;
    startedAt: string | null;
    finishedAt: string | null;
    totals: CrawlTotals;
    genericAnchors: { from_path: string; to_path: string; anchor: string }[];
  } | null;
  message?: string;
}

/** وسم صدق المصدر — الشكلان الوحيدان اللذان يعرّفهما التصميم + حالة الغياب. */
type TagTone = 'native' | 'third' | 'absent';

const TAG_TONES: Record<TagTone, { bg: string; fg: string }> = {
  native: { bg: 'var(--success-chip-bg)', fg: 'var(--success-chip-fg)' },
  third: { bg: 'var(--warning-bg)', fg: 'var(--warning-text)' },
  absent: { bg: 'var(--sib-neutral-chip-bg)', fg: 'var(--text-muted)' },
};

const CARD: CSSProperties = {
  border: '1px solid var(--sib-card-border)',
  borderRadius: 14,
  background: 'var(--cc-glass)',
  padding: '14px 17px',
};

function Tag({ tone, children }: { tone: TagTone; children: ReactNode }) {
  const t = TAG_TONES[tone];
  return (
    <span
      style={{
        padding: '1px 8px',
        borderRadius: 999,
        background: t.bg,
        color: t.fg,
        fontSize: 8,
        fontWeight: 900,
        whiteSpace: 'nowrap',
      }}
    >
      {children}
    </span>
  );
}

/** ميزات SERP السبع من التصميم — الأسماء حقيقية، والملكية غير معروفة بلا GSC. */
const SERP_FEATURES = [
  'النتيجة العضوية',
  'نجوم المنتج Rich',
  'يسأل الناس أيضًا',
  'المقتطف المميز',
  'شريط الفيديو',
  'حزمة الصور',
  'AI Overview',
];

/**
 * ٤٠٣ ليس عطلًا يُعاد المحاولة عليه.
 *
 * مصدرا هذه الشاشة — سجلّ الاستعلامات وجولة الزحف — كلاهما خلف
 * `analytics.read` (routes/api-admin.php:205-206 و396-397)، ولوحاهما
 * منفصلان: من فقد المفتاح يُقفَل عليه اللوحان باسمه، وبقيّة الشاشة (بنود
 * «طرف ثالث تقديري» و«غير مُقاس») كانت غائبة أصلًا ولا تتغيّر.
 */
function isDenied(state: ResourceState<unknown>): boolean {
  return state.status === 'error' && state.code === 403;
}

function DeniedLine({ permission }: { permission: string }) {
  return (
    <>
      دورك لا يملك <bdi dir="ltr">{permission}</bdi> — هذا القياس مقفل عليك، ولا رقم يُعرض بدله.
    </>
  );
}

export default function SerpScreen() {
  const queries = useResource<SearchQueriesReport>('/admin/search/queries');
  const crawl = useResource<CrawlEnvelope>('/admin/crawl');
  const [selected, setSelected] = useState<string | null>(null);

  const topQueries = queries.state.status === 'ready' ? queries.state.data.topQueries : [];
  const activeQuery = selected ?? topQueries[0]?.query ?? null;

  const crawlData = crawl.state.status === 'ready' ? crawl.state.data.data : null;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      <div className="sib-fade" style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <h1 style={{ margin: 0, fontSize: 18, fontWeight: 900 }}>ذكاء SERP والسلطة</h1>
        {/* الضابط الوحيد: استعلام مفحوص من السجل الداخلي الحقيقي. */}
        <label
          style={{
            marginInlineStart: 'auto',
            display: 'flex',
            alignItems: 'center',
            gap: 7,
            fontSize: 11.5,
            fontWeight: 900,
            color: 'var(--text-muted)',
          }}
        >
          <span>الاستعلام المفحوص (بحث داخلي مسجَّل)</span>
          <select
            aria-label="الاستعلام المفحوص"
            value={activeQuery ?? ''}
            onChange={(event) => setSelected(event.target.value)}
            disabled={topQueries.length === 0}
            style={{
              minHeight: 36,
              padding: '0 11px',
              border: '1px solid var(--sib-input-border)',
              borderRadius: 10,
              background: 'var(--cc-glass)',
              fontWeight: 800,
              fontSize: 12,
              fontFamily: 'inherit',
            }}
          >
            {topQueries.length === 0 ? (
              <option value="">لا استعلامات مسجَّلة</option>
            ) : (
              topQueries.map((row) => (
                <option key={row.query} value={row.query}>
                  {row.query} ({count(row.count)})
                </option>
              ))
            )}
          </select>
        </label>
      </div>

      {queries.state.status === 'error' ? (
        <p style={{ margin: 0, fontSize: 11.5, color: 'var(--sib-danger)' }} role="alert">
          {isDenied(queries.state) ? (
            <DeniedLine permission="analytics.read" />
          ) : (
            <>
              تعذّر تحميل الاستعلامات: {queries.state.message}{' '}
              <button
                type="button"
                style={{
                  minHeight: 32,
                  padding: '0 12px',
                  border: '1px solid var(--sib-action-border)',
                  borderRadius: 7,
                  background: 'var(--sib-action-bg)',
                  fontSize: 11.5,
                  fontWeight: 900,
                  color: 'var(--success-chip-fg)',
                  cursor: 'pointer',
                }}
                onClick={queries.reload}
              >
                إعادة المحاولة
              </button>
            </>
          )}
        </p>
      ) : null}
      {queries.state.status === 'ready' && topQueries.length === 0 ? (
        <p style={{ margin: 0, fontSize: 11.5, color: 'var(--sib-hint)' }}>
          لا استعلامات بحث داخلي مسجَّلة بعد — عنوان التصميم «ملزمة رياضيات أول ثانوي» عيّنة لا بيانات.
        </p>
      ) : null}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, alignItems: 'start' }}>
        {/* تكوين النتيجة — شكل التصميم بحالات غياب صادقة. */}
        <section style={CARD}>
          <h2 style={{ margin: '0 0 4px', fontSize: 12.5, fontWeight: 900 }}>
            {activeQuery === null ? 'تكوين النتيجة — لا استعلام مفحوص بعد' : `تكوين نتيجة «${activeQuery}»`}
          </h2>
          <span style={{ fontSize: 11.5, color: 'var(--sib-hint)' }}>
            ما تملكه وما هو فرصة — لكل ميزة SERP · بلا Search Console لا مالك يُعرف
          </span>
          <div style={{ marginTop: 9, display: 'flex', flexDirection: 'column', gap: 5 }}>
            {SERP_FEATURES.map((feature) => (
              <div
                key={feature}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 9,
                  padding: '7px 10px',
                  borderRadius: 9,
                  background: 'var(--sib-row)',
                }}
              >
                <b style={{ flex: 1, fontSize: 11.5 }}>{feature}</b>
                <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>—</span>
                <Tag tone="absent">يتطلب Search Console — غير متصل</Tag>
              </div>
            ))}
          </div>
          <p style={{ margin: '9px 0 0', fontSize: 11.5, lineHeight: 1.7, color: 'var(--sib-hint)' }}>
            الاستعلام أعلاه حقيقي من بحث متجرك الداخلي؛ أمّا تكوين نتيجته في جوجل (مملوكة/فرصة) فيتطلب
            تكامل Search Console غير الموجود — فلا حالة تُخترع لأي ميزة.
          </p>
        </section>

        {/* السلطة والروابط — وسم صدق المصدر كما يفرضه التصميم. */}
        <section style={CARD}>
          <h2 style={{ margin: '0 0 4px', fontSize: 12.5, fontWeight: 900 }}>
            السلطة والروابط — بتمييز صادق للمصدر
          </h2>
          <span style={{ fontSize: 11.5, color: 'var(--sib-hint)' }}>
            مقاييس الطرف الثالث ليست درجات جوجل رسمية — موسومة كذلك
          </span>
          <div style={{ marginTop: 9, display: 'flex', flexDirection: 'column', gap: 5 }}>
            <AuthorityRow k="GSC" d="روابط واردة يعرفها جوجل" v={null} tag="absent" tagText="يتطلب Search Console — غير متصل" />

            {/* الصف الوحيد الموصول: جولة الزحف الحقيقية. */}
            <AuthorityRow
              k="داخلي"
              d="تدفق القوة بين صفحاتك"
              v={
                crawl.state.status === 'ready' && crawlData !== null
                  ? `${count(crawlData.totals.links)} رابطًا · ${count(crawlData.totals.orphans)} يتيمة · مراسٍ عامة ${
                      crawlData.genericAnchors.length >= 30 ? '30+' : count(crawlData.genericAnchors.length)
                    }`
                  : null
              }
              tag={crawl.state.status === 'ready' && crawlData !== null ? 'native' : 'absent'}
              tagText={
                crawl.state.status === 'loading'
                  ? 'يُقرأ…'
                  : crawl.state.status === 'error'
                    ? 'تعذّرت القراءة'
                    : crawlData === null
                      ? 'لا جولة زحف بعد'
                      : 'أصلي/مُقاس'
              }
            />

            <AuthorityRow k="DR" d="تقدير Ahrefs للنطاق" v={null} tag="third" tagText="طرف ثالث تقديري — لا مزوّد مرخّص" />
            <AuthorityRow k="DA" d="تقدير Moz" v={null} tag="third" tagText="طرف ثالث تقديري — لا مزوّد مرخّص" />
            <AuthorityRow k="TF/CF" d="تقدير Majestic" v={null} tag="third" tagText="طرف ثالث تقديري — لا مزوّد مرخّص" />
            <AuthorityRow k="سمّية" d="فحص الروابط المشبوهة" v={null} tag="absent" tagText="لا فاحص — غير مُقاس" />
          </div>

          {crawl.state.status === 'error' ? (
            <p style={{ margin: '9px 0 0', fontSize: 11.5, color: 'var(--sib-danger)' }} role="alert">
              {isDenied(crawl.state) ? (
                <DeniedLine permission="analytics.read" />
              ) : (
                <>
                  {crawl.state.message}{' '}
                  <button
                    type="button"
                    style={{
                      minHeight: 32,
                      padding: '0 12px',
                      border: '1px solid var(--sib-action-border)',
                      borderRadius: 7,
                      background: 'var(--sib-action-bg)',
                      fontSize: 11.5,
                      fontWeight: 900,
                      color: 'var(--success-chip-fg)',
                      cursor: 'pointer',
                    }}
                    onClick={crawl.reload}
                  >
                    إعادة المحاولة
                  </button>
                </>
              )}
            </p>
          ) : null}
          {crawlData !== null ? (
            <p style={{ margin: '9px 0 0', fontSize: 11.5, color: 'var(--sib-hint)' }}>
              صف «داخلي» من جولة الزحف {count(crawlData.runId)} · {dateTime(crawlData.finishedAt)} — صفوف
              InternalLink وCrawledPage الحقيقية.
            </p>
          ) : null}

          {/* بديل نصّ العيّنة «روابط جديدة هذا الشهر…» — سطر غياب واحد. */}
          <div
            style={{
              marginTop: 9,
              padding: '8px 11px',
              border: '1px dashed var(--sib-action-border)',
              borderRadius: 10,
              background: 'var(--sib-well)',
              fontSize: 11.5,
              lineHeight: 1.7,
              color: 'var(--text-muted)',
            }}
          >
            تتبّع الروابط الجديدة والمفقودة والسامة شهريًّا يتطلب Search Console — غير متصل، فلا أرقام
            تُعرض هنا.
          </div>
        </section>
      </div>
    </div>
  );
}

function AuthorityRow({
  k,
  d,
  v,
  tag,
  tagText,
}: {
  k: string;
  d: string;
  v: string | null;
  tag: TagTone;
  tagText: string;
}) {
  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: 9,
        padding: '7px 10px',
        borderRadius: 9,
        background: 'var(--sib-row)',
      }}
    >
      <code dir="ltr" style={{ flex: '0 0 52px', fontSize: 11.5, fontWeight: 800, color: 'var(--ink)' }}>
        {k}
      </code>
      <span style={{ flex: 1, fontSize: 11.5, color: 'var(--text-muted)' }}>{d}</span>
      <b
        style={{
          fontSize: 12,
          fontWeight: 900,
          color: v === null ? 'var(--text-muted)' : 'var(--success-chip-fg)',
        }}
      >
        {v ?? '—'}
      </b>
      <Tag tone={tag}>{tagText}</Tag>
    </div>
  );
}
