'use client';
// UNIT: CONTRACT
// FILE: contract.tsx
// STYLE: shared.module.css
// PARENT: PAGE-ANALYTICS
// USES: MEASUREMENT, UI
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * عقد القياس — القواعد التي تحكم كل رقم في هذا المركز، وما لا يُنفَّذ وسببه.
 *
 * ليست وثيقة نوايا: كل بند هنا وصف لسلوك قائم في هذه الشاشات فعلًا، ويمكن
 * التحقّق منه بفتح التبويبات.
 */

import { DELIBERATE_GAPS, MEASUREMENT_RULES } from '../_shared/measurement';
import { Chip, Panel } from '../_shared/ui';
import s from '../_shared/shared.module.css';

/** المسارات التي تناديها هذه المساحة فعلًا، والصلاحية التي يفرضها الخادم. */
const CALLS: { method: string; path: string; permission: string; used: string }[] = [
  {
    method: 'GET',
    path: '/api/v1/admin/dashboard',
    permission: 'analytics.read',
    used: 'الرئيسية · المصادر',
  },
  {
    method: 'GET',
    path: '/api/v1/admin/search/queries',
    permission: 'analytics.read',
    used: 'قِمَع البحث',
  },
  {
    method: 'GET',
    path: '/api/v1/catalog/{dimension}',
    permission: '—',
    used: 'الخرائط التشخيصية — مسار عام لا يفرض صلاحية',
  },
];

export default function ContractTab() {
  return (
    <>
      <Panel
        title="قواعد القياس"
        sub="كل قاعدة منها مطبَّقة في هذه الشاشات الآن، لا موعودة."
      >
        <div className={s.grid2}>
          {MEASUREMENT_RULES.map((rule) => (
            <article className={s.sourceRow} key={rule.title}>
              <span className={s.sourceMain}>
                <b className={s.sourceName}>{rule.title}</b>
                <span className={s.sourceNote}>{rule.body}</span>
              </span>
            </article>
          ))}
        </div>
      </Panel>

      <div className={s.stackTop}>
        <Panel
          title="ما لا يُنفَّذ — وسببه"
          sub="غياب مقصود لا نقص تنفيذ: كل بند هنا كان يمكن ملؤه برقم يبدو معقولًا، وذلك بالضبط ما يجعله خطرًا."
        >
          <div className={s.grid2}>
            {DELIBERATE_GAPS.map((gap) => (
              <article className={s.sourceRow} key={gap.title}>
                <span className={s.sourceMain}>
                  <b className={s.sourceName}>{gap.title}</b>
                  <span className={s.sourceNote}>{gap.body}</span>
                </span>
                <Chip tone="warn">غير منفَّذ</Chip>
              </article>
            ))}
          </div>
        </Panel>

        <Panel
          title="النداءات والصلاحيات"
          note="لا نداء آخر من هذه المساحة. الصلاحية يفرضها الخادم على كل مسار مرة أخرى — إخفاء زرّ في الواجهة ليس حماية."
        >
          <table className={s.table}>
            <thead>
              <tr>
                <th scope="col">المسار</th>
                <th scope="col">الصلاحية</th>
                <th scope="col">يُستعمل في</th>
              </tr>
            </thead>
            <tbody>
              {CALLS.map((call) => (
                <tr key={call.path}>
                  <th scope="row">
                    <bdi dir="ltr" className={s.num}>
                      {call.method} {call.path}
                    </bdi>
                  </th>
                  <td>
                    <bdi dir="ltr" className={s.num}>
                      {call.permission}
                    </bdi>
                  </td>
                  <td>{call.used}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      </div>
    </>
  );
}
