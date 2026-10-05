'use client';
// UNIT: OVERVIEW
// FILE: overview.tsx
// STYLE: analytics.module.css, shared.module.css
// PARENT: PAGE-ANALYTICS
// USES: LIB-ADMIN-API, LIB-BROWSER-STORE, LIB-FORMAT, MEASUREMENT, RANGE-BAR, RESOURCE +3
// API: /admin/dashboard
// MAP: docs/PROJECT_MAP.md

/**
 * الرئيسية — البطاقات الثماني والمخطّط ولوح المصادر.
 *
 * كل بطاقة هنا رقم مقيس من /admin/dashboard، ومعها الجدول الذي احتُسب منه.
 * ما لا يُقاس لا يظهر بينها كصفر: يظهر في شبكة منفصلة بقيمة «—» وسبب مسمّى
 * يأتي من الخادم نفسه (notConfigured).
 *
 * لا شارة تغيّر ولا سهم اتجاه على أي بطاقة: لا لقطات تاريخية محفوظة، فلا
 * فترة سابقة تُقارَن بها — والمقارنة معطّلة بسببها المكتوب لا مخفيّة.
 */

import { useCallback, useMemo, useRef, useState, type CSSProperties } from 'react';
import { useStoredItem } from '../../../../lib/browser-store';
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core';
import { SortableContext, rectSortingStrategy, sortableKeyboardCoordinates, useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ResourceView, useResource } from '../../../../components/resource';
import type { Dashboard } from '../../../../lib/admin-api';
import {
  count,
  dateTime,
  money,
  ENTITLEMENT_STATUS_LABELS,
  ORDER_STATUS_LABELS,
  PRODUCT_STATUS_LABELS,
} from '../../../../lib/format';
import { Icon, type IconName } from '../_shared/icons';
import { MetricCard } from '../_shared/metric-card';
import { MEASURED_FEEDS, MISSING_FEEDS } from '../_shared/measurement';
import { RangeBar } from '../_shared/range-bar';
import { StatusChart, type ChartDatum } from '../_shared/status-chart';
import { Chip, FeedRow, NotMeasured, Panel, Segmented, type SegmentOption } from '../_shared/ui';
import s from '../_shared/shared.module.css';
import shell from '../analytics.module.css';

const LAYOUT_KEY = 'tahdheer.admin.analytics.kpi';

interface Kpi {
  id: string;
  label: string;
  icon: IconName;
  /** النص المعروض، أو null حين لا مقام للحساب — فيُعرَض «—» مع سببه. */
  value: string | null;
  unit?: string;
  source: string;
  tip: string;
  absentReason?: string;
}

/** ترتيب البطاقات الافتراضي — يطابق ترتيب لوحة التصميم. */
const DEFAULT_ORDER: string[] = [
  'revenue',
  'orders',
  'aov',
  'vat',
  'products',
  'published',
  'entitlements',
  'queries',
  'zeroResult',
];

const NO_WINDOW_REASON =
  'المسار /admin/dashboard لا يقبل نافذة زمنية: تجميعاته عدٌّ لكل الصفوف بلا شرط تاريخ.';

const RANGES: SegmentOption[] = [
  { id: 'all', label: 'كل الصفوف' },
  { id: '7d', label: '٧ أيام', disabledReason: NO_WINDOW_REASON },
  { id: '30d', label: '٣٠ يومًا', disabledReason: NO_WINDOW_REASON },
  { id: '90d', label: '٩٠ يومًا', disabledReason: NO_WINDOW_REASON },
];

interface StoredLayout {
  order: string[];
  hidden: string[];
}

function parseLayout(raw: string | null): StoredLayout | null {
  try {
    if (raw === null) return null;
    const parsed: unknown = JSON.parse(raw);
    if (typeof parsed !== 'object' || parsed === null) return null;
    const record = parsed as Record<string, unknown>;
    const isText = (value: unknown): value is string => typeof value === 'string';
    return {
      order: Array.isArray(record.order) ? record.order.filter(isText) : [],
      hidden: Array.isArray(record.hidden) ? record.hidden.filter(isText) : [],
    };
  } catch {
    return null;
  }
}

function buildKpis(dashboard: Dashboard): Record<string, Kpi> {
  const m = dashboard.measured;
  const paid = m.revenue.paidStatuses.join(' · ');
  const aovMinor =
    m.revenue.ordersCount > 0 ? Math.round(m.revenue.totalMinor / m.revenue.ordersCount) : null;

  return {
    revenue: {
      id: 'revenue',
      label: 'الإيراد المحصَّل',
      icon: 'coins',
      value: money(m.revenue.totalMinor),
      unit: 'ر.س',
      source: 'orders.total_minor',
      tip: `مجموع total_minor للطلبات ذات الحالات: ${paid}. لا يُشتقّ من أي حدث متصفّح.`,
    },
    orders: {
      id: 'orders',
      label: 'الطلبات',
      icon: 'cart',
      value: count(m.orders.total),
      unit: 'طلب',
      source: 'orders',
      tip: 'عدّ كل صفوف جدول الطلبات بكل حالاتها — والتفصيل حسب الحالة في المخطّط أدناه.',
    },
    aov: {
      id: 'aov',
      label: 'متوسط قيمة الطلب',
      icon: 'chart',
      value: aovMinor === null ? null : money(aovMinor),
      unit: 'ر.س',
      source: 'مشتق من orders',
      tip: 'الإيراد المحصَّل ÷ عدد الطلبات المحتسَبة — قسمة على أرقام مقيسة لا مُقدَّرة.',
      absentReason: 'لا طلبات محتسَبة بعد، فلا مقام للقسمة — وصفر هنا سيكون كذبًا.',
    },
    vat: {
      id: 'vat',
      label: 'ضريبة القيمة المضافة',
      icon: 'percent',
      value: money(m.revenue.vatMinor),
      unit: 'ر.س',
      source: 'orders.vat_minor',
      tip: 'مستخرَجة من صفوف الطلبات نفسها، لا محسوبة بنسبة مفترضة على الإجمالي.',
    },
    products: {
      id: 'products',
      label: 'إجمالي المنتجات',
      icon: 'grid',
      value: count(m.products.total),
      unit: 'منتج',
      source: 'products',
      tip: 'عدّ كل صفوف جدول المنتجات بكل حالاتها — والتفصيل حسب الحالة في المخطّط أدناه.',
    },
    published: {
      id: 'published',
      label: 'المنتجات المنشورة',
      icon: 'grid',
      value: count(m.products.byStatus.published ?? 0),
      unit: 'منتج',
      source: 'products.status',
      tip: 'صفر هنا صفر مرصود: الجدول استُعلم فعلًا ولم يوجد منشور.',
    },
    entitlements: {
      id: 'entitlements',
      label: 'صلاحيات الوصول الفعّالة',
      icon: 'user',
      value: count(m.entitlements.byStatus.ACTIVE ?? 0),
      unit: 'صلاحية',
      source: 'entitlements.status',
      tip: 'الحالة ACTIVE وحدها — المنتهية والمسحوبة والموقوفة في المخطّط أدناه.',
    },
    queries: {
      id: 'queries',
      label: 'استعلامات البحث المسجَّلة',
      icon: 'search',
      value: count(m.search.queriesLogged),
      unit: 'استعلام',
      source: 'search_queries',
      /*
       * ─── الوصف كان يَعِد بتشذيبٍ لا يقع ───
       *
       * كان يقول «الصفوف داخل مدّة الاحتفاظ (كذا يومًا) — السجلّ يُشذَّب
       * ليلًا». والخادم لا يبثّ `retentionDays` أصلًا، فكان يُطبع
       * `undefined`. وأسوأ منه أنّ التشذيب نفسه غير موجود: لا أمر تشذيبٍ في
       * المشروع ولا مهمّةٍ مجدولة له.
       *
       * فالوصف يقول ما يقع فعلًا: كلّ صفٍّ منذ النشأة. ووعدٌ بدورة حياةٍ لا
       * وجود لها يجعل المشغّل يفسّر نموًّا مطّردًا تشذيبًا معطّلًا.
       */
      tip: 'كلّ الصفوف منذ النشأة — لا تشذيب مجدول للسجلّ. للنافذة الزمنية انظر تبويب «قِمَع البحث».',
    },
    zeroResult: {
      id: 'zeroResult',
      label: 'استعلامات بلا نتائج',
      icon: 'alert',
      value: count(m.search.zeroResultQueries),
      unit: 'استعلام',
      source: 'search_queries.zero_result',
      tip: 'استعلامات نُفّذت ولم تُرجِع نتيجة — أدقّ إشارة على فجوة في الكتالوج أو المرادفات. كلّ الصفوف منذ النشأة.',
    },
  };
}

/**
 * بطاقة مؤشّر قابلة للترتيب — dnd-kit لا سحب HTML5:
 * سحب HTML5 لا يعمل باللمس أصلًا ولا يعرف لوحة المفاتيح، فكان زرّا التقديم
 * والتأخير هما التكافؤ الوحيد. المستشعران هنا (المؤشّر واللوحة) يعطيان السحب
 * نفسه بالإصبع وبالمسافة والأسهم، والزرّان باقيان لمن يفضّلهما.
 * عتبة الـ٦ بكسل تمنع السحب من ابتلاع نقرات أزرار البطاقة.
 */
function KpiCard({
  kpi,
  editing,
  canMoveEarlier,
  canMoveLater,
  onHide,
  onMove,
}: {
  kpi: Kpi;
  editing: boolean;
  canMoveEarlier: boolean;
  canMoveLater: boolean;
  onHide: () => void;
  onMove: (
    direction: -1 | 1,
  ) => void;
}) {
  const absent =
    kpi.value === null;

  const {
    attributes,
    listeners,
    setNodeRef,
    setActivatorNodeRef,
    transform,
    transition,
    isDragging,
    isOver,
  } = useSortable({
    id: kpi.id,
    disabled: !editing,
  });

  const style:
    CSSProperties = {
      transform:
        CSS.Transform.toString(
          transform,
        ),

      transition,
    };

  const actions =
    editing ? (
      <div
        className={
          s.metricToolbar
        }
      >
        <button
          ref={
            setActivatorNodeRef
          }
          type="button"
          className={[
            s.metricAction,
            s.metricDragHandle,
          ]
            .filter(Boolean)
            .join(' ')}
          aria-label={
            `اسحب بطاقة ${kpi.label}`
          }
          title={
            `اسحب بطاقة ${kpi.label}`
          }
          {...attributes}
          {...listeners}
        >
          <Icon
            name="grip"
            size={15}
            strokeWidth={2.5}
          />
        </button>

        <button
          type="button"
          className={
            s.metricAction
          }
          disabled={
            !canMoveEarlier
          }
          aria-label={
            `قدِّم بطاقة ${kpi.label}`
          }
          title={
            `قدِّم بطاقة ${kpi.label}`
          }
          onClick={() => {
            onMove(-1);
          }}
        >
          <Icon
            name="up"
            size={14}
            strokeWidth={2.2}
          />
        </button>

        <button
          type="button"
          className={
            s.metricAction
          }
          disabled={
            !canMoveLater
          }
          aria-label={
            `أخِّر بطاقة ${kpi.label}`
          }
          title={
            `أخِّر بطاقة ${kpi.label}`
          }
          onClick={() => {
            onMove(1);
          }}
        >
          <Icon
            name="down"
            size={14}
            strokeWidth={2.2}
          />
        </button>

        <button
          type="button"
          className={[
            s.metricAction,
            s.metricActionDanger,
          ]
            .filter(Boolean)
            .join(' ')}
          aria-label={
            `أخفِ بطاقة ${kpi.label}`
          }
          title={
            `أخفِ بطاقة ${kpi.label}`
          }
          onClick={onHide}
        >
          <Icon
            name="close"
            size={14}
            strokeWidth={2.2}
          />
        </button>
      </div>
    ) : undefined;

  return (
    <MetricCard
      ref={setNodeRef}
      style={style}
      className={[
        isOver
        && !isDragging
          ? s.metricOver
          : '',

        isDragging
          ? s.metricDragging
          : '',
      ]
        .filter(Boolean)
        .join(' ')}
      icon={kpi.icon}
      label={kpi.label}
      value={
        absent ? (
          '—'
        ) : (
          <bdi dir="ltr">
            {kpi.value}
          </bdi>
        )
      }
      unit={
        absent
          ? undefined
          : kpi.unit
      }
      source={kpi.source}
      tone={
        absent
          ? 'muted'
          : 'accent'
      }
      hint={
        absent
          ? kpi.absentReason
          : undefined
      }
      meta={
        <span
          title={kpi.tip}
        >
          لحظي · تفاصيل
        </span>
      }
      actions={actions}
    />
  );
}

type Dataset = 'orders' | 'products' | 'entitlements';

const DATASETS: { id: Dataset; label: string; labels: Record<string, string>; unit: string }[] = [
  { id: 'orders', label: 'الطلبات', labels: ORDER_STATUS_LABELS, unit: 'طلب' },
  { id: 'products', label: 'المنتجات', labels: PRODUCT_STATUS_LABELS, unit: 'منتج' },
  { id: 'entitlements', label: 'الصلاحيات', labels: ENTITLEMENT_STATUS_LABELS, unit: 'صلاحية' },
];

export default function OverviewTab() {
  const { state, reload } = useResource<Dashboard>('/admin/dashboard');
  const [editing, setEditing] = useState(false);
  const [dataset, setDataset] = useState<Dataset>('orders');

  // المخزَّن هو الأصل حتى أول تعديل في الجلسة، ثم يسود تعديل الجلسة.
  const storedLayout = useStoredItem(LAYOUT_KEY);
  const [layoutOverride, setLayoutOverride] = useState<StoredLayout | null>(null);
  const { order, hidden } = useMemo(() => {
    const stored = layoutOverride ?? parseLayout(storedLayout);
    if (stored === null) return { order: DEFAULT_ORDER, hidden: [] as string[] };
    const known = new Set(DEFAULT_ORDER);
    const kept = stored.order.filter((id) => known.has(id));
    return {
      order: [...kept, ...DEFAULT_ORDER.filter((id) => !kept.includes(id))],
      hidden: stored.hidden.filter((id) => known.has(id)),
    };
  }, [layoutOverride, storedLayout]);

  const apply = useCallback((nextOrder: string[], nextHidden: string[]) => {
    setLayoutOverride({ order: nextOrder, hidden: nextHidden });
    try {
      window.localStorage.setItem(LAYOUT_KEY, JSON.stringify({ order: nextOrder, hidden: nextHidden }));
    } catch {
      /* التخزين المحلي قد يكون محجوبًا — الترتيب يبقى لهذه الجلسة وحدها. */
    }
  }, []);

  const visible = order.filter((id) => !hidden.includes(id));

  const move = useCallback(
    (id: string, direction: -1 | 1) => {
      const from = visible.indexOf(id);
      const swapWith = visible[from + direction];
      if (from < 0 || swapWith === undefined) return;
      const next = order.slice();
      const a = next.indexOf(id);
      const b = next.indexOf(swapWith);
      next[a] = swapWith;
      next[b] = id;
      apply(next, hidden);
    },
    [apply, hidden, order, visible],
  );

  /* الإفلات يحرّك داخل الترتيب الكامل لا المرئيّ فقط: المخفيّة تحتفظ بموضعها. */
  const onDragEnd = useCallback(
    (event: DragEndEvent) => {
      const activeId = String(event.active.id);
      const overId = event.over === null ? null : String(event.over.id);
      if (overId === null || overId === activeId) return;
      const next = order.slice();
      const from = next.indexOf(activeId);
      const to = next.indexOf(overId);
      if (from < 0 || to < 0) return;
      const [moved] = next.splice(from, 1);
      if (moved === undefined) return;
      next.splice(to, 0, moved);
      apply(next, hidden);
    },
    [apply, hidden, order],
  );

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  /* التسميات تُبنى مع لوحة البيانات داخل التصيير؛ المرجع يجعلها متاحة للإعلانات بلا خطّاف داخل الاستدعاء. */
  const kpisRef = useRef<Record<string, Kpi>>({});
  const labelOf = useCallback((id: string | number) => kpisRef.current[String(id)]?.label ?? String(id), []);
  const announcements = useMemo(
    () => ({
      onDragStart: ({ active }: { active: { id: string | number } }) => `أُمسكت بطاقة ${labelOf(active.id)}.`,
      onDragOver: ({ active, over }: { active: { id: string | number }; over: { id: string | number } | null }) =>
        over ? `بطاقة ${labelOf(active.id)} فوق موضع ${labelOf(over.id)}.` : `بطاقة ${labelOf(active.id)} خارج الشبكة.`,
      onDragEnd: ({ active, over }: { active: { id: string | number }; over: { id: string | number } | null }) =>
        over ? `أُفلتت بطاقة ${labelOf(active.id)} في موضع ${labelOf(over.id)}.` : `أُلغي نقل بطاقة ${labelOf(active.id)}.`,
      onDragCancel: ({ active }: { active: { id: string | number } }) => `أُلغي نقل بطاقة ${labelOf(active.id)} وعاد الترتيب كما كان.`,
    }),
    [labelOf],
  );

  return (
    <ResourceView state={state} reload={reload}>
      {(dashboard) => {
        const kpis = buildKpis(dashboard);
        kpisRef.current = kpis;
        const activeSet = DATASETS.find((item) => item.id === dataset);
        if (activeSet === undefined) throw new Error('لا مجموعة بيانات للمخطّط.');

        const source =
          dataset === 'orders'
            ? dashboard.measured.orders
            : dataset === 'products'
              ? dashboard.measured.products
              : dashboard.measured.entitlements;

        const chartData: ChartDatum[] = Object.entries(source.byStatus).map(([key, value]) => ({
          key,
          label: activeSet.labels[key] ?? key,
          value,
        }));

        return (
          <>
            <RangeBar
              label="المدى"
              options={RANGES}
              value="all"
              onChange={() => undefined}
              scope={`حُسب في ${dateTime(dashboard.generatedAt)}`}
              compare={{
                pressed: false,
                label: 'بلا مقارنة',
                disabledReason:
                  'لا لقطات تاريخية محفوظة، فلا فترة سابقة تُقارَن بها — ولن تظهر أي نسبة تغيّر على أي بطاقة.',
              }}
              reason="أزرار المدى معطّلة لأن /admin/dashboard يُرجع تجميعًا واحدًا لكل الصفوف بلا نافذة زمنية. النافذة الزمنية الحقيقية الوحيدة اليوم في تبويب «قِمَع البحث»."
              actions={
                <button
                  type="button"
                  className={[s.btn, s.btnCompact, editing ? s.btnOn : ''].filter(Boolean).join(' ')}
                  aria-pressed={editing}
                  onClick={() => setEditing((previous) => !previous)}
                >
                  <Icon name="sliders" size={12} strokeWidth={2.2} />
                  {editing ? 'إنهاء التخصيص' : 'خصّص اللوحة'}
                </button>
              }
            />

            {editing ? (
              <div className={shell.editNotice} role="status" aria-live="polite">
                <span aria-hidden="true" className={s.noticeIcon}>
                  <Icon name="info" size={14} strokeWidth={2.2} />
                </span>
                <span className={shell.editText}>
                  رتِّب البطاقات بالسحب أو بزرَّي «قدِّم/أخِّر»، أو أخفِ ما لا يعنيك. التخطيط يُحفظ في هذا
                  المتصفّح وحده — لا مسار لحفظه في حسابك، فلا يتغيّر ما يراه غيرك.
                </span>
                <span className={s.hint}>
                  <bdi dir="ltr">{count(hidden.length)}</bdi> مخفيّة
                </span>
                <button
                  type="button"
                  className={[s.btn, s.btnCompact].filter(Boolean).join(' ')}
                  onClick={() => apply(order, [])}
                  disabled={hidden.length === 0}
                >
                  استعِد الكل
                </button>
              </div>
            ) : null}

            <DndContext
              sensors={sensors}
              collisionDetection={closestCenter}
              onDragEnd={onDragEnd}
              accessibility={{
                announcements,
                screenReaderInstructions: {
                  draggable:
                    'لإعادة ترتيب البطاقة: اضغط مسافة للإمساك، ثم الأسهم للنقل، ثم مسافة للإفلات. Escape يلغي.',
                },
              }}
            >
              <SortableContext items={visible} strategy={rectSortingStrategy}>
                <div className={s.grid4}>
                  {visible.map((id, index) => {
                    const kpi = kpis[id];
                    if (kpi === undefined) return null;
                    return (
                      <KpiCard
                        key={id}
                        kpi={kpi}
                        editing={editing}
                        canMoveEarlier={index > 0}
                        canMoveLater={index < visible.length - 1}
                        onHide={() => apply(order, [...hidden, id])}
                        onMove={(direction) => move(id, direction)}
                      />
                    );
                  })}
                </div>
              </SortableContext>
            </DndContext>

            <div className={s.stackTop}>
              <Panel
                title="مقاييس غير مُقاسة"
                sub="هذه ليست أصفارًا: لا مصدر بيانات لها أصلًا. الصفر يعني «قِسنا ولم نجد»، وهذه لم تُقَس — والسبب يأتي من الخادم كما هو."
              >
                <div className={s.grid4}>
                  {dashboard.notConfigured.map((metric) => (
                    <article className={s.kpi} key={metric.key}>
                      <div className={s.kpiTop}>
                        <span
                          aria-hidden="true"
                          className={[s.kpiIcon, s.kpiIconMuted].filter(Boolean).join(' ')}
                        >
                          <Icon name="info" size={13} />
                        </span>
                        <span className={s.kpiLabel}>{metric.label}</span>
                      </div>
                      <div className={s.kpiValueRow}>
                        <b className={[s.kpiValue, s.kpiValueAbsent].filter(Boolean).join(' ')}>—</b>
                        <Chip tone="warn">
                          {metric.state === 'NOT_CONFIGURED' ? 'غير مُهيأ' : metric.state}
                        </Chip>
                      </div>
                      <p className={s.panelSub}>{metric.reason}</p>
                    </article>
                  ))}
                </div>
              </Panel>

              <div className={s.split}>
                <Panel
                  title="التوزيع حسب الحالة"
                  actions={
                    <Segmented
                      label="مجموعة البيانات"
                      options={DATASETS.map((item) => ({ id: item.id, label: item.label }))}
                      value={dataset}
                      onChange={(id) => setDataset(id as Dataset)}
                    />
                  }
                  note="المخطّط مرسوم من الأرقام نفسها المكتوبة عليه — بلا مكتبة رسم وبلا تنعيم. ولا يوجد مخطّط زمني «إيراد مقابل زيارات»: ذلك يحتاج جدول أحداث وسلسلة تاريخية، وكلاهما غير موجود."
                >
                  <StatusChart
                    data={chartData}
                    unitLabel={activeSet.label}
                    ariaSummary={`${activeSet.label} حسب الحالة: ${chartData
                      .map((datum) => `${datum.label} ${count(datum.value)}`)
                      .join('، ')}. الإجمالي ${count(source.total)}.`}
                  />
                  <div className={s.chartLegend}>
                    <span className={s.legendItem}>
                      <span aria-hidden="true" className={s.legendSwatch} />
                      {activeSet.label} — الإجمالي <bdi dir="ltr">{count(source.total)}</bdi>{' '}
                      {activeSet.unit}
                    </span>
                    <span className={s.spacer} />
                    <span className={s.hint}>الطول نسبي لأعلى شريط · القيمة المطلقة مكتوبة على كل شريط</span>
                  </div>
                </Panel>

                <Panel title="حالة المصادر" hint="ما يغذّي هذه الشاشة">
                  <div className={s.stack}>
                    {MEASURED_FEEDS.slice(0, 3).map((feed) => (
                      <FeedRow feed={feed} key={feed.id} />
                    ))}
                    {MISSING_FEEDS.slice(0, 2).map((feed) => (
                      <FeedRow feed={feed} key={feed.id} />
                    ))}
                  </div>
                  <p className={s.panelNote}>
                    القائمة الكاملة — وما يلزم لبناء كل مصدر غائب — في تبويب «المصادر».
                  </p>
                </Panel>
              </div>

              <Panel
                title="أعلى المنتجات"
                actions={
                  <button
                    type="button"
                    className={[s.btn, s.btnCompact].filter(Boolean).join(' ')}
                    disabled
                    title="لا شيء يُصدَّر: لا يوجد تجميع إيراد لكل منتج."
                  >
                    <Icon name="download" size={12} strokeWidth={2.2} />
                    تصدير CSV
                  </button>
                }
              >
                <NotMeasured
                  icon="coins"
                  title="لا إيراد منسوب لكل منتج"
                  body="ترتيب المنتجات بالإيراد يحتاج تجميعًا خادميًا لأسطر الطلبات حسب المنتج، ولا يوجد اليوم مسار يُرجعه: ‏/admin/products يُرجع المنتجات بلا مبيعات، و/admin/orders يُرجع الطلبات ولا يفصّل أسطرها إلا للطلب الواحد."
                  requirements={[
                    'تجميع خادمي لأسطر الطلبات حسب المنتج، محصور بالحالات المدفوعة نفسها التي يعلنها الخادم للإيراد.',
                    'عمود «معدّل التحويل» يحتاج مقامًا من الزيارات وهو غير مرصود — فلا عمود له هنا حتى يُبنى.',
                    'ولا يُجمَّع شيء في المتصفّح: رقم يُبنى بعشرات النداءات لا يمكن تدقيقه ولا إعادة إنتاجه.',
                  ]}
                />
              </Panel>
            </div>

            <p className={s.panelNote}>
              حُسبت هذه الأرقام في: <bdi>{dateTime(dashboard.generatedAt)}</bdi> — بلا تخزين وسيط،
              مع كل فتح للتبويب.
            </p>
          </>
        );
      }}
    </ResourceView>
  );
}
