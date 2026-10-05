'use client';
// UNIT: ALERTS
// FILE: alerts.tsx
// STYLE: diagnostics.module.css, shared.module.css
// PARENT: DIAGNOSTICS-PANEL
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE, UI
// API: /admin/dashboard
// MAP: docs/PROJECT_MAP.md

/**
 * مركز التنبيهات — القواعد وإشاراتها.
 *
 * لا قاعدة هنا «مفعَّلة»: لا مسار يحفظ قاعدة ولا مجدول ينفّذها، فمفاتيح
 * التفعيل معطّلة وسبب التعطيل مكتوب بجانبها. مفتاح يقلب حالته في المتصفّح
 * ثم لا يُنبِّه أحدًا أسوأ من غياب المفتاح.
 *
 * وما يمكن عرضه بصدق يُعرض: القيمة المرصودة الآن لكل قاعدة إشارتها موجودة،
 * ورابط إلى المكان الذي يُعالَج فيه الأمر فعلًا.
 */

import Link from 'next/link';
import { ResourceView, useResource, type ResourceState } from '../../../../components/resource';
import type { Dashboard } from '../../../../lib/admin-api';
import { count, dateTime } from '../../../../lib/format';
import { Chip, Notice, Panel } from '../../analytics/_shared/ui';
import s from '../../analytics/_shared/shared.module.css';
import d from '../diagnostics.module.css';

type Severity = 'critical' | 'warn' | 'info';

interface Rule {
  id: string;
  severity: Severity;
  title: string;
  body: string;
  /** الإشارة المرصودة الآن، أو null إن لم يكن لها مصدر. */
  value: number | null;
  unit: string;
  /** تعبير الإشارة المرصودة، أو null إن لم يكن لها مصدر. */
  signal: string | null;
  /** وجهة حقيقية يُعالَج فيها الأمر. */
  href: string | null;
  hrefLabel: string;
}

const SEVERITY_LABEL: Record<Severity, string> = {
  critical: 'حرج',
  warn: 'تحذير',
  info: 'معلومة',
};

const SEVERITY_CLASS: Record<Severity, string> = {
  critical: d.ruleCritical ?? '',
  warn: d.ruleWarn ?? '',
  info: d.ruleInfo ?? '',
};

const SEVERITY_TONE: Record<Severity, 'bad' | 'warn' | 'ok'> = {
  critical: 'bad',
  warn: 'warn',
  info: 'ok',
};

const NO_RULE_STORE =
  'لا مسار يحفظ قاعدة تنبيه ولا مجدول ينفّذها — التفعيل هنا لن يُنبِّه أحدًا.';

function buildRules(dashboard: Dashboard): Rule[] {
  const m = dashboard.measured;
  return [
    {
      id: 'failed-orders',
      severity: 'critical',
      title: 'طلبات فاشلة',
      body: 'ارتفاعها المفاجئ يعني عادةً عطبًا في الدفع لا عزوف عملاء. الإشارة مرصودة من جدول الطلبات.',
      value: m.orders.byStatus.FAILED ?? 0,
      unit: 'طلب',
      signal: 'orders.status = FAILED',
      href: '/orders',
      hrefLabel: 'افتح الطلبات',
    },
    {
      id: 'awaiting-payment',
      severity: 'warn',
      title: 'طلبات بانتظار الدفع',
      body: 'تراكمها قد يعني انقطاعًا في العودة من بوّابة الدفع. الإشارة مرصودة.',
      value: m.orders.byStatus.AWAITING_PAYMENT ?? 0,
      unit: 'طلب',
      signal: 'orders.status = AWAITING_PAYMENT',
      href: '/orders',
      hrefLabel: 'افتح الطلبات',
    },
    {
      id: 'zero-result',
      severity: 'warn',
      title: 'استعلامات بحث بلا نتائج',
      body: 'أوضح إشارة على فجوة في الكتالوج أو في المرادفات، وهي مرصودة بالكامل.',
      value: m.search.zeroResultQueries,
      unit: 'استعلام',
      signal: 'search_queries.zero_result',
      href: '/search',
      hrefLabel: 'افتح ذكاء البحث',
    },
    {
      id: 'draft-products',
      severity: 'info',
      title: 'منتجات مسوّدة',
      body: 'ليست عطبًا بذاتها؛ لكن مسوّدة قديمة غالبًا عمل توقّف. الإشارة مرصودة.',
      value: m.products.byStatus.draft ?? 0,
      unit: 'منتج',
      signal: 'products.status = draft',
      href: '/studio/catalog',
      hrefLabel: 'افتح المنتجات',
    },
    {
      id: 'revoked-entitlements',
      severity: 'info',
      title: 'صلاحيات وصول مسحوبة',
      body: 'ارتفاعها يستحق مراجعة سبب السحب. الإشارة مرصودة.',
      value: m.entitlements.byStatus.REVOKED ?? 0,
      unit: 'صلاحية',
      signal: 'entitlements.status = REVOKED',
      href: '/customers',
      hrefLabel: 'افتح العملاء',
    },
    {
      id: 'ctr-drop',
      severity: 'warn',
      title: 'هبوط نسبة النقر في البحث الخارجي',
      body: 'يحتاج ربطًا بمصدر بحث خارجي ولقطات سابقة تُقارَن بها. لا واحد منهما موجود.',
      value: null,
      unit: '',
      signal: null,
      href: null,
      hrefLabel: '',
    },
    {
      id: 'not-found-spike',
      severity: 'critical',
      title: 'انهيار صفحات غير موجودة',
      body: 'يحتاج سجلّ استجابات ٤٠٤ مع عدّاد زمني. لا سجلّ ولا عدّاد.',
      value: null,
      unit: '',
      signal: null,
      href: null,
      hrefLabel: '',
    },
    {
      id: 'traffic-drop',
      severity: 'critical',
      title: 'هبوط الزيارات',
      body: 'يحتاج سلسلة زيارات يومية. لا جدول أحداث ولا سلسلة تاريخية.',
      value: null,
      unit: '',
      signal: null,
      href: null,
      hrefLabel: '',
    },
  ];
}

/**
 * الحرمان يُقرأ برمزه.
 *
 * هذه الشاشة وحدها بين شاشات التشخيص تقرأ مسارًا محروسًا:
 * `GET /dashboard` خلف `analytics.read` (`routes/api-admin.php:71-72`). شاشة
 * الخرائط تقرأ `/catalog/{dimension}` العامّ (`routes/api.php:35-36`) فلا
 * تخويل لها ولا 403 يُختلق لها.
 */
function AlertsState({ state, reload, children }: {
  state: ResourceState<Dashboard>;
  reload: () => void;
  children: (dashboard: Dashboard) => React.ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>
          لا تملك صلاحية <bdi dir="ltr">analytics.read</bdi> لقراءة إشارات التنبيهات.
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

export default function AlertsScreen() {
  const { state, reload } = useResource<Dashboard>('/admin/dashboard');

  return (
    <AlertsState state={state} reload={reload}>
      {(dashboard) => {
        const rules = buildRules(dashboard);
        const observed = rules.filter((rule) => rule.value !== null).length;
        const blind = rules.length - observed;

        return (
          <>
            <div className={d.mapHead}>
              <h2 className={d.mapTitle}>مركز التنبيهات</h2>
              <span className={d.mapSub}>القواعد وإشاراتها — والتفعيل غير مبنيّ</span>
            </div>

            <Notice tone="warn" icon="bell">
              القواعد أدناه غير مفعَّلة ولن تُفعَّل من هنا: {NO_RULE_STORE} ما يُعرَض هو القيمة المرصودة
              الآن لكل قاعدة إشارتها موجودة — وهي معلومة حقيقية تُغني عن تنبيه لم يُبنَ.
            </Notice>

            <div className={s.stackTop}>
              <Panel
                title="القواعد وإشاراتها"
                actions={
                  <>
                    <Chip tone="ok">
                      إشارة مرصودة: <bdi dir="ltr">{count(observed)}</bdi>
                    </Chip>
                    <Chip tone="warn">
                      بلا إشارة: <bdi dir="ltr">{count(blind)}</bdi>
                    </Chip>
                  </>
                }
                note={`القيم مقروءة في ${dateTime(dashboard.generatedAt)}. العتبة والفحص الدوري والإشعار كلها غير مبنية، فلا «حالة تنبيه» تُعلَن هنا — رقم مرصود فقط.`}
              >
                <div className={s.stack}>
                  {rules.map((rule) => (
                    <section
                      className={[d.rule, SEVERITY_CLASS[rule.severity]].filter(Boolean).join(' ')}
                      key={rule.id}
                    >
                      <Chip tone={SEVERITY_TONE[rule.severity]}>{SEVERITY_LABEL[rule.severity]}</Chip>

                      <span className={d.ruleMain}>
                        <b className={d.ruleTitle}>{rule.title}</b>
                        <span className={d.ruleBody}>{rule.body}</span>
                        {rule.signal === null ? (
                          <Chip tone="warn">لا إشارة مرصودة</Chip>
                        ) : (
                          <bdi dir="ltr" className={[s.chip, s.num].filter(Boolean).join(' ')}>
                            {rule.signal}
                          </bdi>
                        )}
                      </span>

                      {rule.value === null ? (
                        <span className={[d.ruleValue, s.absent].filter(Boolean).join(' ')}>—</span>
                      ) : (
                        <span className={d.ruleValue}>
                          <bdi dir="ltr">{count(rule.value)}</bdi>{' '}
                          <span className={s.kpiUnit}>{rule.unit}</span>
                        </span>
                      )}

                      {rule.href === null ? (
                        <Chip tone="warn">غير مُقاس</Chip>
                      ) : (
                        <Link className={[s.btn, s.btnCompact].filter(Boolean).join(' ')} href={rule.href}>
                          {rule.hrefLabel}
                        </Link>
                      )}

                      <button
                        type="button"
                        role="switch"
                        aria-checked={false}
                        disabled
                        className={d.switch}
                        aria-label={`تفعيل قاعدة ${rule.title} — غير متاح: ${NO_RULE_STORE}`}
                        title={NO_RULE_STORE}
                      >
                        <span aria-hidden="true" className={d.switchKnob} />
                      </button>
                    </section>
                  ))}
                </div>
              </Panel>
            </div>
          </>
        );
      }}
    </AlertsState>
  );
}
