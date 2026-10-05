'use client';
// UNIT: LOOPS-PANEL
// FILE: loops-panel.tsx
// STYLE: growth.module.css
// PARENT: PAGE-ANALYTICS-OS-FLOWS
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _GOVERNANCE, _KIT
// API: /admin/dashboard
// MAP: docs/PROJECT_MAP.md

/**
 * حلقات النمو والحاكمية.
 *
 * لا حلقة هنا «مفعَّلة»: لا يوجد مشغّل أتمتة في هذا النظام — لا مجدول مهام
 * ولا قناة إرسال ولا مخزن لقطات تراجع ولا سجل ذاتي. فكل حلقة تُعرض بمستوى
 * استقلاليتها وبما ينقصها، ومفتاحها معطَّل ظاهرًا بسببه؛ مفتاح لا يشغّل
 * شيئًا أسوأ من غيابه.
 *
 * الجزء المقيس الوحيد صادق تمامًا: بوابة حجم العيّنة تقارن أرقام لوحة
 * القياس الحقيقية بحدود الحاكمية المعلنة، فتقول لكل حلقة تعلّم أين تقف.
 */

import { ResourceView, useResource } from '../../../components/resource';
import type { Dashboard } from '../../../lib/admin-api';
import { count } from '../../../lib/format';
import { Box, Icon, Kpi, NotConfigured } from '../seo/_kit';
import {
  FORBIDDEN,
  LOOP_GROUPS,
  SAMPLE_FLOORS,
  TECH_LIMITS,
  TIERS,
  TIER_LABELS,
  type FloorMetric,
} from './_governance';
import styles from './growth.module.css';

const TIER_CLASS: Record<string, string> = {
  auto: '',
  threshold: styles.threshold ?? '',
  proposal: styles.proposal ?? '',
};

/** لون شارة المستوى يتبع المستوى نفسه — لا لون واحد يوحّد ما ليس واحدًا. */
const TIER_CHIP: Record<string, string> = {
  auto: styles.tierauto ?? '',
  threshold: styles.tierthreshold ?? '',
  proposal: styles.proposal ?? '',
};

/** قياسات حقيقية من لوحة القياس، أو null حين لا مصدر لها في هذا النظام. */
function measure(dashboard: Dashboard, metric: FloorMetric): { value: number | null; source: string } {
  switch (metric) {
    case 'products':
      return { value: dashboard.measured.products.byStatus.published ?? 0, source: 'products.byStatus.published' };
    case 'orders':
      return { value: dashboard.measured.orders.total, source: 'orders.total' };
    case 'searches':
      return { value: dashboard.measured.search.queriesLogged, source: 'search.queriesLogged' };
    case 'impressions':
      return { value: null, source: 'لا مصدر ظهور — Search Console غير موصول' };
  }
}

export function GrowthLoopsPanel() {
  const dashboard = useResource<Dashboard>('/admin/dashboard');

  return (
    <div className={styles.workspace}>

      <header className={styles.topbar}>
        <b>الطيار الآلي — يعمل وحده ضمن حدودك</b>
        <span className={styles.topgold}>لا مشغّل أتمتة في هذا النظام — كل الحلقات في وضع اقتراح</span>
        <div className={styles.topspacer} />
        <button type="button" className={styles.stopped} disabled>
          إيقاف طوارئ — لا حلقة تعمل لتُوقَف
        </button>
      </header>

      <div className={styles.body}>
        <section className={styles.lead}>
          <h2>الفكرة الحاكمة</h2>
          <p>
            الاستقلالية ليست ميزة تُضاف، بل حلقة تُغلق. و«بلا تدخّل منّي» ليست «بلا رؤية»: نظام يتحرّك وحده
            وأنت لا ترى ما فعله ليس مستقلًا بل غير قابل للمحاسبة. الاستقلالية الصحيحة أن يتحرّك وحده، ويسجّل
            كل حركة، ويُعكس بنقرة، ويصمت إلا عند الشذوذ. هذه الصفحة تعرض الحلقات المخطَّط لها وحدودها
            الحاكمة — ولا تُظهر أي حلقة عاملة، لأن لا شيء هنا ينفّذ اليوم.
          </p>
        </section>

        <h2 className={styles.groupname}>
          <span className={styles.groupdot} aria-hidden="true" />
          مستويات الاستقلالية الثلاثة
        </h2>
        <div className={styles.grid}>
          {TIERS.map((tier) => (
            <section className={`${styles.tier} ${TIER_CLASS[tier.key]}`} key={tier.key}>
              <h3>{tier.name}</h3>
              <p>{tier.description}</p>
              <ul>
                {tier.items.map((item) => (
                  <li key={item}>{item}</li>
                ))}
              </ul>
            </section>
          ))}
        </div>

        <section className={styles.forbidden}>
          <h2>ممنوع على الأتمتة — بلا استثناء</h2>
          <ul className={styles.forbiddenlist}>
            {FORBIDDEN.map((item) => (
              <li key={item}>
                <span aria-hidden="true">×</span>
                <span>{item}</span>
              </li>
            ))}
          </ul>
          <p>
            السبب واحد: هذه بنود إمّا لا تُعكس، أو أثرها يظهر بعد أسابيع، أو تلمس صحّة التجارة والدفع. تبقى
            «اقتراحًا» دائمًا مهما ارتفعت الثقة، ولا يرفعها أي إعداد ولا أي عتبة.
          </p>
        </section>

        <section className={styles.floorbox}>
          <h2>بوابة حجم العيّنة — قياس حقيقي مقابل حدّ معلن</h2>
          <p>
            كل حلقة تعلّم تعلن حدًّا أدنى للعيّنة وتبقى في وضع «اقتراح» حتى تبلغه، وتقول ذلك صراحةً بدل
            الصمت. الحدود أدناه <b>سياسة حاكمية معلنة لا قياس</b>؛ عمود «المقيس» وحده يأتي من الخادم.
          </p>

          <ResourceView state={dashboard.state} reload={dashboard.reload}>
            {(data) => (
              <>
                <table className={styles.table}>
                  <caption className="sr-only">حلقات التعلّم وحدودها الدنيا مقابل القياس الفعلي</caption>
                  <thead>
                    <tr>
                      <th scope="col">حلقة التعلّم</th>
                      <th scope="col">الحد الأدنى (سياسة)</th>
                      <th scope="col">المقيس الآن</th>
                      <th scope="col">البوابة</th>
                      <th scope="col">لماذا هذا الحد</th>
                    </tr>
                  </thead>
                  <tbody>
                    {SAMPLE_FLOORS.map((floor) => {
                      const measured = measure(data, floor.metric);
                      const passes = measured.value !== null && measured.value >= floor.floor;
                      return (
                        <tr key={floor.name}>
                          <th scope="row">{floor.name}</th>
                          <td className={styles.num}>
                            <bdi dir="ltr">{count(floor.floor)}</bdi>
                          </td>
                          <td className={styles.num}>
                            {measured.value === null ? (
                              <span className={`${styles.state} ${styles.blocked}`}>غير مقيس</span>
                            ) : (
                              <bdi dir="ltr">{count(measured.value)}</bdi>
                            )}
                            <span style={{ display: 'block', fontSize: 9, color: 'var(--faint)' }}>
                              <bdi dir="ltr">{measured.source}</bdi>
                            </span>
                          </td>
                          <td>
                            <span className={`${styles.state} ${passes ? '' : styles.proposal}`}>
                              {passes ? 'بلغت الحد' : 'دون الحد — اقتراح فقط'}
                            </span>
                          </td>
                          <td>{floor.rationale}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
                <p className={styles.note} style={{ marginBlockStart: 10 }}>
                  بلوغ الحد لا يعني التفعيل: لا مشغّل أتمتة أصلًا. البوابة تمنع التعلّم المبكّر فقط، ولا
                  تمنح إذنًا بالتنفيذ.
                </p>
              </>
            )}
          </ResourceView>
        </section>

        {LOOP_GROUPS.map((group) => (
          <div key={group.name}>
            <h2 className={styles.groupname}>
              <span className={styles.groupdot} aria-hidden="true" />
              {group.name}
            </h2>
            <div className={styles.grid2}>
              {group.loops.map((loop) => {
                const reasonId = `growth-reason-${loop.key}`;

                return (
                  <section className={styles.loop} key={loop.key}>
                    <div className={styles.loophead}>
                      {/*
                        المفتاح يعلن حالته ولا يزيّنها. كان قبلًا ‎<span aria-hidden>‎
                        يبدو قابلًا للضغط ولا يُنطق لقارئ الشاشة أصلًا — أي وعدٌ
                        بصريّ بلا مقابل. وهو معطَّل حقًّا لا مجازًا: لا مشغّل أتمتة
                        يقلبه، فحالته «مطفأ» صادقة لكل حلقة، وسببها مقروءٌ بجانبه.
                        النمط منقول كما هو من ‎/analytics/os/flows‎ لا مخترعًا هنا:
                        role="switch" + aria-checked=false + aria-disabled +
                        aria-describedby إلى سطر «ينقصها».
                      */}
                      <button
                        type="button"
                        role="switch"
                        aria-checked={false}
                        aria-disabled={true}
                        aria-label={loop.name}
                        aria-describedby={reasonId}
                        className={styles.switch}
                      >
                        <span className={styles.knob} aria-hidden="true" />
                      </button>
                      <b>{loop.name}</b>
                      <span className={`${styles.state} ${TIER_CHIP[loop.tier]}`}>{TIER_LABELS[loop.tier]}</span>
                    </div>
                    <p>{loop.description}</p>
                    <div className={styles.loopmeta}>
                      <span className={`${styles.state} ${styles.blocked}`}>غير مفعَّلة</span>
                      <span id={reasonId} style={{ flex: 1, minInlineSize: 160 }}>
                        ينقصها: {loop.blockedBy}
                      </span>
                    </div>
                  </section>
                );
              })}
            </div>
          </div>
        ))}

        <section className={styles.limits}>
          <h2>حدود التشغيل التقنية — شرط قبل أي حلقة</h2>
          <ul>
            {TECH_LIMITS.map((limit) => (
              <li key={limit}>{limit}</li>
            ))}
          </ul>
          <p className={styles.note} style={{ marginBlockStart: 10 }}>
            المحاكاة الجافة هي الوضع الافتراضي: أي مشغّل يُضاف مستقبلًا يعلن «ما كنت سأفعله» قبل أن يفعل،
            وصفحات المال لا يمسّها إلا بموافقة صريحة لكل تغيير على حدة.
          </p>
        </section>

        <div className={styles.grid2}>
          <Box title="ما الذي يُقاس اليوم فعلًا" icon="chart" note="من لوحة القياس">
            <ResourceView state={dashboard.state} reload={dashboard.reload}>
              {(data) => (
                <div style={{ padding: 13 }}>
                  <div
                    style={{
                      display: 'grid',
                      gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
                      gap: 9,
                    }}
                  >
                    <Kpi
                      label="منتجات منشورة"
                      value={count(data.measured.products.byStatus.published ?? 0)}
                      source="GET /admin/dashboard"
                      icon="cart"
                    />
                    <Kpi label="طلبات" value={count(data.measured.orders.total)} source="orders.total" icon="tag" />
                    <Kpi
                      label="استعلامات بحث مسجَّلة"
                      value={count(data.measured.search.queriesLogged)}
                      source="search.queriesLogged"
                      icon="search"
                    />
                    <Kpi
                      label="استحقاقات"
                      value={count(data.measured.entitlements.total)}
                      source="entitlements.total"
                      icon="check"
                    />
                  </div>
                </div>
              )}
            </ResourceView>
          </Box>

          <NotConfigured
            title="السجل الذاتي — فعل ← دليل ← نتيجة ← تراجع"
            reason="لا شيء نفّذ شيئًا، فلا سجل يُعرض. حين يوجد مشغّل أتمتة يجب أن يسبقه هذا السجل لا أن يتبعه: كل فعل بدليله ونتيجته وزرّ تراجعه، وإلّا صار النظام صندوقًا أسود."
            needs={['GET /admin/automation/log', 'POST /admin/automation/rollback', 'مخزن لقطات تراجع']}
          />
        </div>

        <section className={styles.lead}>
          <h2>
            <Icon name="shield" /> شرط تقني غير قابل للتفاوض
          </h2>
          <p>
            كل حلقة تعمل في مهمة مجدولة، وصفر كتابة داخل طلب الزائر. أي حلقة تعلّم تُضاف فوق مسار كتابة داخل
            العرض تضاعف كلفة كل صفحة على كل زائر — فتصحيح مسار الكتابة يسبق أي حلقة ذاتية، وإلّا صار «النظام
            الذكي» أثقل بند أداء في المتجر.
          </p>
        </section>
      </div>
    </div>
  );
}
