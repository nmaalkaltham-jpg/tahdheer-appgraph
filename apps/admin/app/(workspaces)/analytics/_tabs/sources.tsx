'use client';
// UNIT: SOURCES
// FILE: sources.tsx
// STYLE: shared.module.css
// PARENT: PAGE-ANALYTICS
// USES: LIB-ADMIN-API, LIB-FORMAT, MEASUREMENT, RESOURCE, UI
// API: /admin/dashboard
// MAP: docs/PROJECT_MAP.md

/**
 * المصادر — سجلّ ما يغذّي كل رقم في هذا المركز، وما لا مصدر له.
 *
 * قسم «ما يعلنه الخادم» ليس نصًّا مكتوبًا هنا: يأتي حرفيًا من حقل notConfigured
 * في /admin/dashboard، فيبقى مصدر السبب هو الخادم لا الواجهة.
 */

import { ResourceView, useResource } from '../../../../components/resource';
import type { Dashboard } from '../../../../lib/admin-api';
import { dateTime } from '../../../../lib/format';
import { MEASURED_FEEDS, MISSING_FEEDS } from '../_shared/measurement';
import { Chip, FeedRow, Panel } from '../_shared/ui';
import s from '../_shared/shared.module.css';

export default function SourcesTab() {
  const { state, reload } = useResource<Dashboard>('/admin/dashboard');

  return (
    <>
      <Panel
        title="مصادر متصلة"
        hint="مسارات قائمة مُتحقَّق منها"
        sub="كل مصدر هنا مسار موجود فعلًا في الخادم. لا يُضاف إلى هذه القائمة شيء قبل وجوده."
      >
        <div className={s.stack}>
          {MEASURED_FEEDS.map((feed) => (
            <div key={feed.id}>
              <FeedRow feed={feed} />
              <p className={s.panelSub}>{feed.note}</p>
            </div>
          ))}
        </div>
      </Panel>

      <div className={s.stackTop}>
        <Panel
          title="ما يعلنه الخادم غير مُهيأ"
          sub="هذه القائمة وأسبابها تأتيان من الخادم نفسه في حقل notConfigured — لا تُكتب في الواجهة ولا تُترجَم."
        >
          <ResourceView state={state} reload={reload}>
            {(dashboard) => (
              <>
                <table className={s.table}>
                  <thead>
                    <tr>
                      <th scope="col">المقياس</th>
                      <th scope="col">الحالة</th>
                      <th scope="col">السبب كما ورد من الخادم</th>
                    </tr>
                  </thead>
                  <tbody>
                    {dashboard.notConfigured.map((metric) => (
                      <tr key={metric.key}>
                        <th scope="row">
                          {metric.label}
                          <span className={s.sourceNote}>
                            <bdi dir="ltr">{metric.key}</bdi>
                          </span>
                        </th>
                        <td>
                          <Chip tone="warn">
                            {metric.state === 'NOT_CONFIGURED' ? 'غير مُهيأ' : metric.state}
                          </Chip>
                        </td>
                        <td>{metric.reason}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <p className={s.panelNote}>
                  قُرئت في: <bdi>{dateTime(dashboard.generatedAt)}</bdi>
                </p>
              </>
            )}
          </ResourceView>
        </Panel>

        <Panel
          title="خطوط أنابيب غائبة"
          sub="لا مسار لأي منها اليوم. «لا مسار» وصف للواقع لا اسم نقطة نهاية مستقبلية — لا يوجد ما يُنادى."
        >
          <div className={s.stack}>
            {MISSING_FEEDS.map((feed) => (
              <div key={feed.id}>
                <FeedRow feed={feed} />
                <p className={s.panelSub}>{feed.note}</p>
              </div>
            ))}
          </div>
        </Panel>
      </div>
    </>
  );
}
