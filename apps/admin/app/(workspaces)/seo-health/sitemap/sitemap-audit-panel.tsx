'use client';
// UNIT: SITEMAP-AUDIT-PANEL
// FILE: sitemap-audit-panel.tsx
// STYLE: sitemap.module.css
// PARENT: PAGE-SEO-HEALTH-SITEMAP
// USES: LIB-FORMAT, RESOURCE
// API: /admin/seo/sitemap/audit
// MAP: docs/PROJECT_MAP.md

/**
 * تدقيق خريطة الموقع — قياسٌ كان يجري كلّ ليلة ولا يراه أحد.
 *
 * ─── العطب الذي وُجدت له ───
 *
 * `sitemap:audit` مجدولٌ في ٠٣:٤٠ ويقيس النقاء والتغطية وما طُرد ولماذا،
 * ثمّ يطبع ذلك على المخرَج القياسيّ — وفي حاوية، المخرَج القياسيّ لا يقرؤه
 * أحد. وكانت هذه الشاشة مغلقةً ببوّابةٍ تسمّي الناقص حرفيًّا: «تخزين نتائج
 * `sitemap:audit` وعرضها». صار القياس يُحفَظ، وهذا عرضُه.
 *
 * ─── والنقاء ليس التغطية ───
 *
 * المدقّق يقيس اثنين لا واحدًا: **ما عُرض وطُرد** (نقاء)، و**ما كان يجب أن
 * يُعرض ولم يُعرض** (`missing`). الثاني لا يظهر في نسبة التغطية إطلاقًا —
 * منتجٌ منشور يفشل المولّد في عرضه أصلًا يترك التغطية ١٠٠٪ وهو غائب عن
 * الخريطة. ولذلك يُعرضان صفَّين منفصلين، ولا يُجمعان في رقمٍ واحد يطمئن.
 *
 * ─── و«غير مقيس» ليست صفرًا ───
 *
 * `coveragePct` تعود `null` حين لا روابط عُرضت: النقاء مجهول لا معدوم.
 * وطباعة «٠٪» مكانها تقول إنّ كلّ شيءٍ طُرد — وهو عكس الحقيقة تمامًا.
 */

import { ResourceView, useResource } from '../../../../components/resource';
import { dateTime } from '../../../../lib/format';
import styles from './sitemap.module.css';

interface ProviderRow {
  provider: string;
  label: string;
  offered: number;
  kept: number;
  expelled: number;
  coveragePct: number | null;
  samples: { loc: string; reason: string }[];
}

interface DormantRow {
  provider: string;
  label: string;
  reason: string;
}

interface MissingRow {
  type: string;
  id: number;
  path: string;
}

interface AuditRun {
  ranAt: string | null;
  clean: boolean;
  offered: number;
  kept: number;
  expelled: number;
  coveragePct: number | null;
  missingCount: number;
}

interface AuditReport {
  latest:
    | (AuditRun & {
        report: {
          providers: ProviderRow[];
          dormant: DormantRow[];
          missing: MissingRow[];
        };
      })
    | null;
  history: AuditRun[];
}

/** النسبة كما قِيست، و«غير مقيس» حين لا قياس — لا صفرٌ يُقرأ عطلًا تامًّا. */
function coverage(value: number | null) {
  if (value === null) return <span className="absent">غير مقيس</span>;

  return <bdi dir="ltr">{`${value}%`}</bdi>;
}

export function SitemapAuditPanel() {
  const { state, reload } = useResource<AuditReport>('/admin/seo/sitemap/audit');

  return (
    <ResourceView
      state={state}
      reload={reload}
      forbidden={<p>لا تملك صلاحية قراءة التحليلات، وتدقيق الخريطة يُقرأ بها.</p>}
    >
      {(view) =>
        view.latest === null ? (
          /*
             لا قياسَ بعد ≠ خريطةٌ نظيفة. المجدوِل يملأ هذا في أوّل ٠٣:٤٠،
             والقول صراحةً أصدق من أصفارٍ تُقرأ نتيجةً.
          */
          <div className="state-line" role="status">
            <p>لم يجرِ تدقيقٌ بعد. يعمل المدقّق كلّ ليلة في ٠٣:٤٠، وأوّل نتيجةٍ تظهر هنا بعدها.</p>
          </div>
        ) : (
          <>
            <div className="cards">
              <article className={view.latest.clean ? 'card' : 'card card-alert'}>
                <h3>حالة الخريطة</h3>
                <b>{view.latest.clean ? 'نظيفة' : 'فيها خلل'}</b>
                <p className="page-note">
                  آخر قياس{' '}
                  {view.latest.ranAt === null ? (
                    <span className="absent">—</span>
                  ) : (
                    <bdi>{dateTime(view.latest.ranAt)}</bdi>
                  )}
                </p>
              </article>

              <article className="card">
                <h3>النقاء</h3>
                <b>{coverage(view.latest.coveragePct)}</b>
                <p className="page-note">
                  عُرض <bdi dir="ltr">{view.latest.offered}</bdi> · بقي{' '}
                  <bdi dir="ltr">{view.latest.kept}</bdi> · طُرد{' '}
                  <bdi dir="ltr">{view.latest.expelled}</bdi>
                </p>
              </article>

              <article className={view.latest.missingCount > 0 ? 'card card-alert' : 'card'}>
                <h3>غائبٌ عن الخريطة</h3>
                <b>
                  <bdi dir="ltr">{view.latest.missingCount}</bdi>
                </b>
                {/* هذا ما لا تقيسه نسبة النقاء: كيانٌ مؤهَّل لم يُعرض أصلًا. */}
                <p className="page-note">كيانٌ عامّ مؤهَّل لا يظهر في أيّ مولّد — لا يُحسب في النقاء أعلاه.</p>
              </article>
            </div>

            <section className="panel">
              <h2>المولّدات</h2>
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th scope="col">المولّد</th>
                      <th scope="col">عُرض</th>
                      <th scope="col">بقي</th>
                      <th scope="col">طُرد</th>
                      <th scope="col">النقاء</th>
                    </tr>
                  </thead>
                  <tbody>
                    {view.latest.report.providers.map((provider) => (
                      <tr key={provider.provider}>
                        <td>{provider.label}</td>
                        <td>
                          <bdi dir="ltr">{provider.offered}</bdi>
                        </td>
                        <td>
                          <bdi dir="ltr">{provider.kept}</bdi>
                        </td>
                        <td>
                          <bdi dir="ltr">{provider.expelled}</bdi>
                        </td>
                        <td>{coverage(provider.coveragePct)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/*
                 العيّنات مع سببها: «طُرد ٤» رقمٌ لا يُصلَح به شيء، و«هذا
                 العنوان طُرد لأنّه noindex» يقول ما يُفعل.
              */}
              {view.latest.report.providers.some((provider) => provider.samples.length > 0) ? (
                <dl className={styles.samples}>
                  {view.latest.report.providers
                    .filter((provider) => provider.samples.length > 0)
                    .map((provider) => (
                      <div key={provider.provider}>
                        <dt>{provider.label}</dt>
                        {provider.samples.map((sample) => (
                          <dd key={sample.loc}>
                            <bdi dir="ltr">{sample.loc}</bdi> — {sample.reason}
                          </dd>
                        ))}
                      </div>
                    ))}
                </dl>
              ) : null}
            </section>

            {view.latest.report.dormant.length > 0 ? (
              <section className="panel">
                <h2>مولّدات خاملة</h2>
                {/* خاملٌ بسببه: «معطّل» وحدها تُقرأ عطلًا، والسبب يقول إن كان مقصودًا. */}
                <dl className={styles.samples}>
                  {view.latest.report.dormant.map((row) => (
                    <div key={row.provider}>
                      <dt>{row.label}</dt>
                      <dd>{row.reason}</dd>
                    </div>
                  ))}
                </dl>
              </section>
            ) : null}

            {view.latest.report.missing.length > 0 ? (
              <section className="panel">
                <h2>كيانات غائبة عن الخريطة</h2>
                <p className="page-note">
                  مؤهَّلةٌ للظهور ولا يعرضها أيّ مولّد — يتيمة الاكتشاف حتى لو كانت الخريطة نظيفة تمامًا.
                </p>
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th scope="col">النوع</th>
                        <th scope="col">المعرّف</th>
                        <th scope="col">المسار</th>
                      </tr>
                    </thead>
                    <tbody>
                      {view.latest.report.missing.map((row) => (
                        <tr key={`${row.type}-${row.id}`}>
                          <td>{row.type}</td>
                          <td>
                            <bdi dir="ltr">{row.id}</bdi>
                          </td>
                          <td>
                            <bdi dir="ltr">{row.path}</bdi>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            ) : null}

            {view.history.length > 1 ? (
              <section className="panel">
                <h2>الليالي السابقة</h2>
                {/* الاتّجاه لا اللقطة: ليلةٌ بطردٍ أقلّ من سابقتها دليلُ إصلاح. */}
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th scope="col">الليلة</th>
                        <th scope="col">عُرض</th>
                        <th scope="col">طُرد</th>
                        <th scope="col">غائب</th>
                        <th scope="col">النقاء</th>
                      </tr>
                    </thead>
                    <tbody>
                      {view.history.map((run) => (
                        <tr key={run.ranAt ?? String(run.offered)}>
                          <td>
                            {run.ranAt === null ? <span className="absent">—</span> : <bdi>{dateTime(run.ranAt)}</bdi>}
                          </td>
                          <td>
                            <bdi dir="ltr">{run.offered}</bdi>
                          </td>
                          <td>
                            <bdi dir="ltr">{run.expelled}</bdi>
                          </td>
                          <td>
                            <bdi dir="ltr">{run.missingCount}</bdi>
                          </td>
                          <td>{coverage(run.coveragePct)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            ) : null}
          </>
        )
      }
    </ResourceView>
  );
}
