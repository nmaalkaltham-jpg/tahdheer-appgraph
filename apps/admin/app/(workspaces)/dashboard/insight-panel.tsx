'use client';
// UNIT: INSIGHT-PANEL
// FILE: insight-panel.tsx
// STYLE: insight-panel.module.css
// PARENT: ABANDONED, PAGE-DASHBOARD, PAGE-REVENUE, PURCHASE-FUNNEL
// USES: COMPONENTS-ICONS, LIB-ADMIN-API, LIB-FORMAT, STATES
// API: /admin/insights
// MAP: docs/PROJECT_MAP.md

/**
 * لوح نطاق — مُصيِّرٌ واحد لنطاقات طبقة التجميع كلّها.
 *
 * ─── لماذا مكوّنٌ واحد لسبعة نطاقات ───
 *
 * العقد الخادميّ واحد: `metrics` و`lists` و`notConfigured`. فسبعة مكوّناتٍ
 * كانت ستكون سبع نسخٍ من المنطق نفسه تتباعد، وأوّل نسخةٍ تنسى حالة الغياب
 * تعرض فراغًا مكان «غير مهيّأ» — وهو بالضبط الكذب الذي تمنعه القاعدة.
 *
 * ─── والتحميل كسول ───
 *
 * اللوح المطويّ لا يُرسل طلبًا. سبعة نطاقاتٍ تُجلب في أوّل تحميلٍ للصفحة كانت
 * ستُبطئ الشاشة الأولى لأجل أرقامٍ لم يُطلب أكثرها. الفتح هو ما يُطلق النداء،
 * وبعده يبقى محفوظًا دقيقةً فلا يُعاد عند كلّ طيٍّ وفتح.
 */

import { useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../../lib/admin-api';
import { count, dateTime, money } from '../../../lib/format';
import { ErrorState, Skeleton } from '../../../components/ui/states';
import { Icon } from '../../../components/icons';
import styles from './insight-panel.module.css';

interface InsightMetric {
  key: string;
  label: string;
  state: 'MEASURED' | 'NOT_CONFIGURED' | 'NO_DATA';
  format?: 'money' | 'count' | 'percent' | 'duration' | 'text';
  value?: number | string;
  formula?: string;
  reason?: string;
  href?: string;
  previous?: number;
  alert?: boolean;
}

interface InsightList {
  label: string;
  note?: string;
  /**
   * لماذا القائمة فارغة — من الخادم لا من الواجهة.
   *
   * «لا صفّ في هذا المدى» صحيحةٌ ولا تفيد: قائمةُ إيراد الصفحات تفرغ إمّا
   * لأنّ لا مبيعات، وإمّا لأنّ لا أحد وافق على القياس — وهما حالتان علاجهما
   * مختلف تمامًا. والنطاق وحده يعرف أيّهما.
   */
  empty?: string;
  rows: Record<string, unknown>[];
}

interface InsightPayload {
  generatedAt: string;
  domain: { key: string; label: string; description: string };
  metrics: InsightMetric[];
  lists: Record<string, InsightList>;
  notConfigured: InsightMetric[];
}

/** الصيغة تحدّد العرض — و`duration` ثوانٍ تُقرأ بالدقائق حين تطول. */
function present(metric: InsightMetric): string {
  const value = metric.value;
  if (value === undefined) return '';

  switch (metric.format) {
    case 'money':
      return money(Number(value));
    case 'percent':
      return `${value}%`;
    case 'duration': {
      const seconds = Number(value);
      return seconds < 90 ? `${seconds} ثانية` : `${Math.round(seconds / 60)} دقيقة`;
    }
    case 'count':
      return count(Number(value));
    default:
      return String(value);
  }
}

/**
 * أيّ مفتاحٍ لا يُعرض للقارئ.
 *
 * المعرّفات تقنيّةٌ لا بيان: تُستعمل في الوجهة وتُخفى من الصفّ. و`id` المجرّد
 * لا ينتهي بـ`Id`، فكان يُعرض عمودًا أوّلَ من أرقامٍ لا معنى لها للمشغّل.
 */
function hidden(key: string): boolean {
  return key === 'href' || key === 'id' || key.endsWith('Id');
}

/**
 * صفٌّ واحد في قائمةٍ — أعمدةٌ مشتقّة من المفاتيح لا مكتوبةٌ لكلّ نطاق.
 *
 * العقد يسمّي حقوله بلواحق تقول نوعها: `…Minor` مالٌ بالهللة، و`…At` طابع
 * وقتٍ ISO. فالعرض يُشتقّ من الاسم ولا يحتاج خريطة أعمدةٍ لكلّ نطاق —
 * والطابع الخام كان يُعرض كما هو: سلسلةٌ لاتينيّة طويلة وسط نصٍّ عربيّ.
 */
function ListRow({ row }: { row: Record<string, unknown> }) {
  const href = typeof row.href === 'string' ? row.href : null;
  const cells = Object.entries(row).filter(([key]) => !hidden(key));

  const body = cells.map(([key, value]) => (
    <span key={key} className={styles.cell}>
      {key.endsWith('Minor') ? (
        <bdi dir="ltr">{money(Number(value))}</bdi>
      ) : key.endsWith('At') ? (
        <bdi dir="ltr">{dateTime(typeof value === 'string' ? value : null)}</bdi>
      ) : typeof value === 'number' ? (
        <bdi dir="ltr">{count(value)}</bdi>
      ) : (
        String(value ?? '—')
      )}
    </span>
  ));

  return (
    <li className={styles.listRow}>
      {href === null ? <span className={styles.listInner}>{body}</span> : (
        <Link className={styles.listInner} href={href}>
          {body}
        </Link>
      )}
    </li>
  );
}

export function InsightPanel({
  domain,
  title,
  description,
  from,
  to,
  /*
   * مطويٌّ في اللوحة لأنّ سبعة ألواحٍ تتشاركها، ومفتوحٌ في تبويبٍ مخصَّص
   * لأنّه وحده هناك. والطيّ يبقى الافتراض: ما لم يُطلب لا يُجلب.
   */
  defaultOpen = false,
}: {
  domain: string;
  title: string;
  description: string;
  from: string;
  to: string;
  defaultOpen?: boolean;
}) {
  const [open, setOpen] = useState(defaultOpen);

  const path = `/admin/insights/${domain}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;

  const query = useQuery<InsightPayload>({
    queryKey: ['admin-resource', path],
    // مطويٌّ يعني لا نداء — لا جلبٌ استباقيّ لأرقامٍ لم تُطلب.
    enabled: open,
    staleTime: 60_000,
    queryFn: async () => {
      const result = await api.get<InsightPayload>(path);
      if (!result.ok) throw new Error(result.message);
      return result.data;
    },
  });

  const measured = query.data?.metrics.filter((m) => m.state === 'MEASURED') ?? [];
  const absent = query.data?.notConfigured ?? [];

  return (
    <section className={styles.panel} data-open={open ? 'true' : 'false'}>
      <h2 className={styles.head}>
        <button type="button" className={styles.toggle} onClick={() => setOpen((was) => !was)} aria-expanded={open}>
          <Icon name="chevron" size={14} className={styles.caret} />
          <span className={styles.headText}>
            <strong>{title}</strong>
            <em>{description}</em>
          </span>
        </button>
      </h2>

      {!open ? null : (
        <div className={styles.body}>
          {query.isPending ? (
            <Skeleton rows={3} columns={3} />
          ) : query.isError ? (
            <ErrorState message={query.error.message} onRetry={() => void query.refetch()} />
          ) : (
            <>
              <ul className={styles.metrics}>
                {measured.map((metric) => (
                  <li key={metric.key} className={styles.metric} data-alert={metric.alert === true ? 'true' : 'false'}>
                    <span className={styles.metricLabel}>
                      {metric.href === undefined ? (
                        metric.label
                      ) : (
                        <Link className={styles.metricLink} href={metric.href}>
                          {metric.label}
                        </Link>
                      )}
                    </span>
                    <bdi dir="ltr" className={styles.metricValue}>
                      {present(metric)}
                    </bdi>
                    {/* الصيغة تحت الرقم لا في تلميح: رقمٌ بلا تعريفه يُقارَن بما لا يشبهه. */}
                    <em className={styles.metricFormula}>{metric.formula}</em>
                  </li>
                ))}
              </ul>

              {Object.entries(query.data.lists).map(([key, list]) => (
                <div key={key} className={styles.list}>
                  <h3 className={styles.listHead}>{list.label}</h3>
                  {list.note === undefined ? null : <p className={styles.listNote}>{list.note}</p>}
                  {list.rows.length === 0 ? (
                    <p className={styles.listEmpty}>{list.empty ?? 'لا صفّ في هذا المدى.'}</p>
                  ) : (
                    <ul className={styles.listRows}>
                      {list.rows.map((row, index) => (
                        <ListRow key={index} row={row} />
                      ))}
                    </ul>
                  )}
                </div>
              ))}

              {absent.length === 0 ? null : (
                <ul className={styles.absent}>
                  {absent.map((metric) => (
                    <li key={metric.key}>
                      <span className={styles.absentTag}>{metric.state.replace('_', ' ')}</span>
                      <strong>{metric.label}</strong>
                      <p>{metric.reason}</p>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </div>
      )}
    </section>
  );
}
