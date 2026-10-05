'use client';
// UNIT: SEARCH-FUNNEL
// FILE: search-funnel.tsx
// STYLE: shared.module.css
// PARENT: PAGE-ANALYTICS
// USES: LIB-ADMIN-API, LIB-FORMAT, RANGE-BAR, RESOURCE, SHARED-ICONS, UI
// API: /admin/search/queries
// MAP: docs/PROJECT_MAP.md

/**
 * قِمَع البحث — من الاستعلام إلى الطلب.
 *
 * مرحلتان فقط مرصودتان اليوم، وكلتاهما من /admin/search/queries: عدد
 * الاستعلامات المسجَّلة، وكم منها أعاد نتائج. ما قبلهما (البحث الخارجي) وما
 * بعدهما (النقر ثم الشراء المنسوب) لا مصدر له، فيُرسَم شريطًا مشرَّطًا بقيمة
 * «—» لا بصفر، ولا تُحسَب أي نسبة انتقال تعبر حدًّا غير مرصود.
 *
 * هذا هو التبويب الوحيد الذي فيه نافذة زمنية حقيقية: الوسيط days مدعوم على
 * المسار فعلًا (١ إلى ٣٦٥ يومًا).
 */

import { useMemo, useState } from 'react';
import { ResourceView, useResource } from '../../../../components/resource';
import type { SearchQueriesReport } from '../../../../lib/admin-api';
import { count, dateTime } from '../../../../lib/format';
import { MetricCard } from '../_shared/metric-card';
import { RangeBar } from '../_shared/range-bar';
import { BarRows, Chip, NotMeasured, Panel, type StageBar, type SegmentOption } from '../_shared/ui';
import s from '../_shared/shared.module.css';

const RANGES: SegmentOption[] = [
  { id: '7', label: '٧ أيام' },
  { id: '30', label: '٣٠ يومًا' },
  { id: '90', label: '٩٠ يومًا' },
  { id: '365', label: 'سنة' },
  {
    id: 'custom',
    label: 'مخصّص',
    disabledReason: 'المسار يقبل عدد أيام واحدًا (days) لا تاريخين، فلا مدى مخصّص حتى يقبله الخادم.',
  },
];

/** نسبة مئوية بمنزلة واحدة، أو null إن كان المقام صفرًا. */
function ratio(part: number, whole: number): number | null {
  if (whole <= 0) return null;
  return Math.round((part / whole) * 1000) / 10;
}

export default function SearchFunnelTab() {
  const [days, setDays] = useState('30');
  const path = useMemo(() => `/admin/search/queries?days=${days}&limit=20`, [days]);
  const { state, reload } = useResource<SearchQueriesReport>(path);

  return (
    <ResourceView state={state} reload={reload}>
      {(report) => {
        const logged = report.totals.queriesLogged;
        const zero = report.totals.zeroResultQueries;
        const answered = logged - zero;
        const answeredRate = ratio(answered, logged);
        const zeroRate = ratio(zero, logged);

        const stages: StageBar[] = [
          {
            id: 'impressions',
            label: 'انطباعات البحث الخارجي',
            value: null,
            display: '—',
            width: 0,
            note: 'لا ربط بأي مصدر بحث خارجي ولا مزامنة — أعلى القِمَع غير مرصود.',
            chip: 'غير مُقاس',
          },
          {
            id: 'logged',
            label: 'استعلامات داخلية مسجَّلة',
            value: logged,
            display: count(logged),
            width: 100,
            note: 'عدّ صفوف search_queries داخل النافذة المختارة.',
          },
          {
            id: 'answered',
            label: 'استعلامات أعادت نتائج',
            value: answered,
            display: count(answered),
            width: answeredRate ?? 0,
            tone: answeredRate !== null && answeredRate < 80 ? 'warn' : 'ok',
            note:
              answeredRate === null
                ? 'لا استعلامات في النافذة، فلا نسبة تُحسب.'
                : `${answeredRate}٪ من المسجَّل — وهي النسبة الوحيدة هنا التي طرفاها مقيسان من الجدول نفسه.`,
            chip: answeredRate === null ? undefined : `${answeredRate}٪`,
          },
          {
            id: 'clicks',
            label: 'نقرات على النتائج',
            value: null,
            display: '—',
            width: 0,
            note: 'العمود clicked_product_id موجود في الجدول ولا مسار يكتبه — فهو فارغ لا صفر.',
            chip: 'غير مُقاس',
          },
          {
            id: 'attributed',
            label: 'طلبات منسوبة إلى البحث',
            value: null,
            display: '—',
            width: 0,
            note: 'عدد الطلبات الحقيقي في تبويب «الرئيسية»؛ ونسبته إلى البحث غير محسوبة لأن لا نسْب يربط الاستعلام بالطلب.',
            chip: 'غير منسوب',
          },
        ];

        return (
          <>
            <RangeBar
              label="النافذة"
              options={RANGES}
              value={days}
              onChange={setDays}
              scope={`${dateTime(report.window.from)} — ${dateTime(report.window.to)}`}
              reason="الأزرار المفعَّلة تُرسل days إلى المسار فعلًا، وكل رقم أدناه يُعاد حسابه على الخادم لهذه النافذة."
            />

            <div className={s.grid4}>
              <MetricCard
                icon="search"
                label="استعلامات مسجَّلة"
                value={
                  <bdi dir="ltr">
                    {count(logged)}
                  </bdi>
                }
                unit="استعلام"
                source="search_queries"
                tone="accent"
                hint="جميع الاستعلامات المسجَّلة داخل النافذة الحالية."
              />

              <MetricCard
                icon="layers"
                label="استعلامات مميّزة"
                value={
                  <bdi dir="ltr">
                    {count(
                      report.totals
                        .distinctQueries,
                    )}
                  </bdi>
                }
                unit="عبارة"
                source="distinct normalized_query"
                hint="عدد العبارات المختلفة بعد التطبيع داخل النافذة."
              />

              <MetricCard
                icon="alert"
                label="استعلامات بلا نتائج"
                value={
                  <bdi dir="ltr">
                    {count(zero)}
                  </bdi>
                }
                unit="استعلام"
                source="zero_result = true"
                tone={
                  zero > 0
                    ? 'warning'
                    : 'success'
                }
                hint="استعلامات نُفذت ولم تُرجع نتيجة."
              />

              <MetricCard
                icon="percent"
                label="نسبة بلا نتائج"
                value={
                  zeroRate === null ? (
                    '—'
                  ) : (
                    <bdi dir="ltr">
                      {zeroRate}
                    </bdi>
                  )
                }
                unit={
                  zeroRate === null
                    ? undefined
                    : '٪'
                }
                source="derived / search_queries"
                tone={
                  zeroRate === null
                    ? 'muted'
                    : zeroRate >= 20
                      ? 'warning'
                      : 'success'
                }
                hint={
                  zeroRate === null
                    ? 'لا يوجد مقام كافٍ لحساب النسبة.'
                    : 'نسبة الاستعلامات التي انتهت بلا نتائج من إجمالي المسجّل.'
                }
              />
            </div>

            <div className={s.stackTop}>
              <Panel
                title="القِمَع الموحَّد"
                hint="المرحلة غير المرصودة تُرسَم مشرَّطة بقيمة «—»"
                note="لا نسبة انتقال بين مرحلتين إحداهما غير مرصودة: نسبة كهذه تبدو دقيقة وهي بلا مقام. النسبة الوحيدة المعروضة هنا طرفاها من الجدول نفسه."
              >
                <BarRows rows={stages} />
              </Panel>

              <div className={s.split}>
                <Panel title="الأكثر تكرارًا" hint={`أعلى ${count(report.topQueries.length)} عبارة`}>
                  <table className={s.table}>
                    <thead>
                      <tr>
                        <th scope="col">العبارة</th>
                        <th scope="col">التكرار</th>
                        <th scope="col">منها بلا نتائج</th>
                        <th scope="col">آخر ظهور</th>
                      </tr>
                    </thead>
                    <tbody>
                      {report.topQueries.length === 0 ? (
                        <tr>
                          <td className={s.emptyCell} colSpan={4}>
                            لا استعلامات مسجَّلة في هذه النافذة. جرّب نافذة أوسع قبل أن تفترض خللًا.
                          </td>
                        </tr>
                      ) : (
                        report.topQueries.map((row) => (
                          <tr key={row.query}>
                            <th scope="row">
                              <bdi>{row.query}</bdi>
                            </th>
                            <td className={s.num}>
                              <bdi dir="ltr">{count(row.count)}</bdi>
                            </td>
                            <td className={s.num}>
                              {row.zeroResultCount > 0 ? (
                                <Chip tone="warn">
                                  <bdi dir="ltr">{count(row.zeroResultCount)}</bdi>
                                </Chip>
                              ) : (
                                <bdi dir="ltr">0</bdi>
                              )}
                            </td>
                            <td className={s.num}>
                              <bdi>{dateTime(row.lastSeenAt)}</bdi>
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </Panel>

                <Panel title="بلا نتائج" hint="أوضح فجوات الكتالوج والمرادفات">
                  <table className={s.table}>
                    <thead>
                      <tr>
                        <th scope="col">العبارة</th>
                        <th scope="col">التكرار</th>
                        <th scope="col">آخر ظهور</th>
                      </tr>
                    </thead>
                    <tbody>
                      {report.zeroResultQueries.length === 0 ? (
                        <tr>
                          <td className={s.emptyCell} colSpan={3}>
                            لا استعلام بلا نتائج في هذه النافذة — وهذا صفر مرصود لا غياب بيانات.
                          </td>
                        </tr>
                      ) : (
                        report.zeroResultQueries.map((row) => (
                          <tr key={row.query}>
                            <th scope="row">
                              <bdi>{row.query}</bdi>
                            </th>
                            <td className={s.num}>
                              <bdi dir="ltr">{count(row.count)}</bdi>
                            </td>
                            <td className={s.num}>
                              <bdi>{dateTime(row.lastSeenAt)}</bdi>
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </Panel>
              </div>

              <Panel title="نسْب الإيراد إلى البحث">
                <NotMeasured
                  icon="coins"
                  title="لا إيراد منسوب لأي عبارة بحث"
                  body="ربط عبارة بطلب يحتاج سلسلة كاملة: نقرة مسجَّلة على نتيجة، ثم جلسة، ثم طلب يحمل مرجع تلك الجلسة. لا حلقة من هذه الحلقات مبنية اليوم."
                  requirements={[
                    'مسار يسجّل النقرة على نتيجة البحث ويملأ clicked_product_id الموجود أصلًا في الجدول.',
                    'مُعرّف جلسة يُحمَل من البحث إلى الطلب، وإلا فكل نسْب تخمين.',
                    'قاعدة نسْب معلنة (آخر نقرة أو مُعان) لا تُجمَع مرتين على الطلب نفسه.',
                  ]}
                />
              </Panel>
            </div>
          </>
        );
      }}
    </ResourceView>
  );
}
