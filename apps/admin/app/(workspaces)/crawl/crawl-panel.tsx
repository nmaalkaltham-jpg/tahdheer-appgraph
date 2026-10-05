'use client';
// UNIT: CRAWL-PANEL
// FILE: crawl-panel.tsx
// STYLE: none
// PARENT: PAGE-SEO
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-FORMAT, RESOURCE
// API: /admin/crawl, POST /admin/crawl/run
// MAP: docs/PROJECT_MAP.md

/**
 * رسم الروابط الداخلية — /crawl.
 *
 * الشاشة التي كانت واجهتها الخلفية تعمل بلا شاشة: الزاحف يبني الرسم ويسجّله،
 * ولا سبيل لأحد إلى قراءته إلا من قاعدة البيانات.
 *
 * لا درجة صحّة ولا نسبة مئوية. حالاتٌ بأسبابها: صفحة إمّا يشير إليها رابط أو
 * لا، وإمّا تُجيب 200 أو لا. «صحّة الروابط ٨٧٪» رقمٌ يُقرأ حكمًا ولا يُصلَح منه
 * شيء؛ أمّا «هذه الصفحات الأربع لا يشير إليها رابط» فقائمة عمل.
 */

import { useCallback, useState } from 'react';
import { useSession } from '../../../components/admin-session';
import { EmptyRows, ResourceView, useResource, type ResourceState } from '../../../components/resource';
import { api, firstError } from '../../../lib/admin-api';
import { count, dateTime } from '../../../lib/format';

interface CrawlReport {
  runId: number;
  startedAt: string | null;
  finishedAt: string | null;
  totals: { pages: number; links: number; broken: number; orphans: number; maxDepth: number };
  orphans: { path: string; title: string | null; discovered_from: string }[];
  broken: { path: string; status: number }[];
  deep: { path: string; depth: number; inbound: number }[];
  redirects: { path: string; status: number; redirect_to: string | null }[];
  genericAnchors: { from_path: string; to_path: string; anchor: string }[];
}

interface CrawlPayload {
  data: CrawlReport | null;
  message?: string;
}

type TotalKey = 'pages' | 'links' | 'broken' | 'orphans' | 'maxDepth';

const TOTALS: { key: TotalKey; label: string }[] = [
  { key: 'pages', label: 'صفحة زُحف إليها' },
  { key: 'links', label: 'رابط داخلي' },
  { key: 'broken', label: 'صفحة مكسورة' },
  { key: 'orphans', label: 'صفحة يتيمة' },
  { key: 'maxDepth', label: 'أقصى عمق نقر' },
];

/**
 * الحرمان يُقرأ برمزه لا برسالةٍ عامّة.
 *
 * `GET admin/crawl` محروسة بـ`analytics.read` (`routes/api-admin.php:396-397`)،
 * ودورٌ بلا هذه الصلاحية يصله 403 نصُّه عامّ لا يُسمّي ما ينقصه. الصلاحية
 * تُسمّى كما يسمّيها الخادم ليطلبها الدور من مالك اللوحة، ولا يُعرض زرّ
 * «إعادة المحاولة»: الإعادة لا تمنح صلاحية.
 */
function CrawlState({ state, reload, children }: {
  state: ResourceState<CrawlPayload>;
  reload: () => void;
  children: (payload: CrawlPayload) => React.ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>
          لا تملك صلاحية <bdi dir="ltr">analytics.read</bdi> لقراءة تقرير الزحف.
        </p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {children}
    </ResourceView>
  );
}

export function CrawlPanel() {
  /*
   * القراءة والتشغيل صلاحيتان لا واحدة: القائمة تفتح ‎/crawl‎ بـ`analytics.read`
   * (`lib/nav.ts`) وقراءة التقرير تكتفي بها، أمّا `POST admin/crawl/run` ففرضه
   * `search.index.manage` (`routes/api-admin.php`). ثلاثة أدوار تملك الأولى دون
   * الثانية — مدير SEO والتحليلات وقراءة فقط — وكانت ترى زرًّا يردّ ٤٠٣ في كلّ
   * ضغطة. الزرّ المعروض دعوى بأنّ الفعل ممكن، فيُقاس هنا قبل عرضه لا بعده.
   */
  const { can } = useSession();
  const canRun = can('search.index.manage');
  const { state, reload } = useResource<CrawlPayload>('/admin/crawl');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const start = useCallback(async () => {
    setBusy(true);
    setFailure(null);
    setNotice(null);

    const result = await api.post<{ message?: string }>('/admin/crawl/run');
    setBusy(false);

    if (!result.ok) {
      setFailure(firstError(result));

      return;
    }

    /*
     * الزحف يعمل في الخلفية، فالشاشة تقول ذلك ولا تُظهر نتيجة لم تصل. إعادة
     * التحميل هنا كانت ستعرض الجولة السابقة كأنها ثمرة الضغطة.
     */
    setNotice(result.data.message ?? 'بدأ الزحف في الخلفية.');
  }, []);

  return (
    <section>
      {/*
        * زرّ التشغيل داخل اللوح لا في رأس الصفحة.
        *
        * الرأس صار للغلاف، وهو يحمل خمسة ألواح: زرٌّ يخصّ لوحًا واحدًا في
        * رأسٍ مشترك يظهر فوق الأربعة الأخرى ويبدو فعلًا عليها.
        */}
      {canRun ? (
        <p className="list-toolbar">
          <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void start()}>
            {busy ? 'يبدأ…' : 'شغّل جولة زحف'}
          </button>
        </p>
      ) : null}

      {/*
        ولا زرّ معطَّل بلا سبب: المعطَّل يقول «لاحقًا» وهذا ليس وقتًا بل صلاحية.
        الصلاحية تُسمّى كما يسمّيها الخادم ليطلبها الدور من مالك اللوحة.
      */}
      {canRun ? null : (
        <p className="absent" role="note">
          التقرير للقراءة — تشغيل جولة جديدة يحتاج صلاحية إدارة فهرس البحث{' '}
          <bdi dir="ltr">search.index.manage</bdi> التي لا يملكها دورك.
        </p>
      )}

      {notice ? (
        <p className="state-line" role="status">
          {notice} حدّث الصفحة بعد دقائق لقراءة الجولة الجديدة.
        </p>
      ) : null}
      {failure ? (
        <div className="state-error" role="alert">
          <p>{failure}</p>
        </div>
      ) : null}

      <CrawlState state={state} reload={reload}>
        {(payload) =>
          payload.data === null ? (
            /* والتعليمة تتبع القدرة: «شغّل جولة» أمرٌ لمن لا يستطيع تشغيلها طريق مسدود. */
            <p className="state-line">
              {payload.message ?? 'لم تُشغَّل جولة زحف بعد.'}{' '}
              {canRun ? 'شغّل جولة لتُبنى خريطة الروابط.' : 'وتشغيلها بيد دورٍ يملك إدارة فهرس البحث.'}
            </p>
          ) : (
            <Report report={payload.data} />
          )
        }
      </CrawlState>
    </section>
  );
}

function Report({ report }: { report: CrawlReport }) {
  return (
    <>
      <p className="state-line">
        الجولة #{report.runId} — انتهت {report.finishedAt ? dateTime(report.finishedAt) : '—'}.
      </p>

      {/* الطوق منطقةُ بلوغٍ بلوحة المفاتيح: كروم لا يبئّر منطقة تمرير من تلقائه (globals.css). */}
      <div className="table-scroll" tabIndex={0} role="region" aria-label="إجماليات جولة الزحف">
      <table className="data-table">
        <caption className="sr-only">إجماليات الجولة</caption>
        <thead>
          <tr>
            {TOTALS.map((total) => (
              <th scope="col" key={total.key}>
                {total.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          <tr>
            {TOTALS.map((total) => (
              <td key={total.key}>{count(report.totals[total.key])}</td>
            ))}
          </tr>
        </tbody>
      </table>
      </div>

      <Section
        title="صفحات يتيمة — تُجيب ٢٠٠ ولا يشير إليها رابط"
        hint="لا يصلها زائر ولا زاحف إلا من خريطة الموقع. إمّا تُربط من صفحة قائمة، وإمّا لا داعي لها."
        empty="لا صفحة يتيمة في هذه الجولة."
        head={['المسار', 'العنوان', 'اكتُشفت من']}
        rows={report.orphans.map((row) => [row.path, row.title ?? '—', row.discovered_from])}
      />

      <Section
        title="صفحات مكسورة"
        hint="رابط داخلي يقود إلى حالة ٤٠٠ فأعلى — يُصلَح الرابط أو تُنشأ قاعدة تحويل."
        empty="لا رابط داخلي مكسور."
        head={['المسار', 'الحالة']}
        rows={report.broken.map((row) => [row.path, String(row.status)])}
      />

      <Section
        title="صفحات مدفونة — ثلاث نقرات فأكثر"
        hint="بعيدة عن كل مدخل. الروابط الواردة تقول كم بابًا يقود إليها فعلًا."
        empty="لا صفحة على عمق ثلاث نقرات فأكثر."
        head={['المسار', 'العمق', 'روابط واردة']}
        rows={report.deep.map((row) => [row.path, String(row.depth), String(row.inbound)])}
      />

      <Section
        title="تحويلات داخلية"
        hint="رابط يمرّ بتحويل يهدر نقرة ويُضعف الإشارة — يُشار إلى الوجهة مباشرة."
        empty="لا رابط داخلي يمرّ بتحويل."
        head={['المسار', 'الحالة', 'الوجهة']}
        rows={report.redirects.map((row) => [row.path, String(row.status), row.redirect_to ?? '—'])}
      />

      <Section
        title="مراسٍ عامّة"
        hint="«اضغط هنا» لا تقول للقارئ ولا لمحرّك البحث ما الوجهة."
        empty="لا مرساة عامّة."
        head={['من', 'إلى', 'نصّ المرساة']}
        rows={report.genericAnchors.map((row) => [row.from_path, row.to_path, row.anchor])}
      />
    </>
  );
}

function Section({
  title,
  hint,
  empty,
  head,
  rows,
}: {
  title: string;
  hint: string;
  empty: string;
  head: string[];
  rows: string[][];
}) {
  return (
    <>
      <h2>{title}</h2>
      <p className="state-line">{hint}</p>
      <div className="table-scroll" tabIndex={0} role="region" aria-label={title}>
      <table className="data-table">
        <caption className="sr-only">{title}</caption>
        <thead>
          <tr>
            {head.map((cell) => (
              <th scope="col" key={cell}>
                {cell}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <EmptyRows colSpan={head.length} message={empty} />
          ) : (
            rows.map((row) => (
              <tr key={row.join('|')}>
                {row.map((cell, index) => (
                  <td key={index} dir="auto">
                    {cell}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
      </div>
    </>
  );
}
