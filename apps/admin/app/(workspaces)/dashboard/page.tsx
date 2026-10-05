'use client';
// UNIT: PAGE-DASHBOARD
// FILE: page.tsx
// ROUTE: /dashboard
// STYLE: dashboard.module.css
// PARENT: none
// USES: ADMIN-SESSION, COMMAND-CENTER, COMPONENTS-ICONS, DATE-RANGE, INSIGHT-PANEL, LIB-ADMIN-API +7
// API: /admin/ops/summary, GET /admin/dashboard/summary, GET /admin/insights, GET /admin/system
// MAP: docs/PROJECT_MAP.md

/**
 * مركز القيادة — «ما حال المتجر، وما الذي يحتاج تدخّلي؟» في شاشةٍ واحدة.
 *
 * ─── ثلاثة مصادر، لا رقمَ يُخترع ───
 *
 * `/admin/dashboard/summary` للمؤشّرات والسلسلة، و`/admin/ops/summary`
 * للعدّادات التشغيليّة، و`/admin/system` لحال المنصّة والأمان. كلّ سطحٍ
 * هنا اشتقاقٌ من هذه الثلاثة، وكلّ اشتقاقٍ يقول صيغته. وما لا مصدر له —
 * عمر العطل، مسؤوله، الزوّار، طرق الدفع — يُعلَن باسمه ولا يُعرض صفرًا:
 * «قِسنا فلم نجد» و«لم نقس» جوابان مختلفان.
 *
 * ─── ثلاث طرق عرض ───
 *
 * العمليّات تبدأ بالانتباه، والتنفيذيّ يبدأ بالأداء، وشاشة العرض تُكبّر
 * أربعة أرقامٍ وما ينتظر تدخّلًا. الترتيب في الرابط لا في حالةٍ محلّيّة:
 * الرابط يُشارَك ويُعاد فتحه على شاشة الجدار كما تُرك.
 */

import Link from 'next/link';
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '../../../components/resource';
import { useSession } from '../../../components/admin-session';
import { Icon } from '../../../components/icons';
import { api } from '../../../lib/admin-api';
import { count, dateTime, money, ORDER_STATUS_LABELS } from '../../../lib/format';
import { DateRangePicker, useDateRange } from '../../../components/ui/date-range';
import { EmptyState, ErrorState, Skeleton } from '../../../components/ui/states';
import { orderTone } from '../../../components/ui/status-badge';
import { useOpsSummary, type OpsCounter } from '../../../components/use-nav-counts';
import { ADMIN_THEMES, useAdminTheme } from '../../../lib/layout-prefs';
import {
  DASHBOARD_PRESETS,
  KPI_METRICS,
  WIDGET_TYPES,
  duplicateBlocker,
  duplicateWidget,
  ensureMetricWidgets,
  metricOf,
  resetWidget,
  setHidden,
  setMetric,
  typeOf,
  useDashboardLayout,
  type WidgetState,
} from '../../../lib/dashboard-layout';
import { Workspace } from '../../../components/workspace/workspace';
import { EditToolbar } from '../../../components/workspace/edit-toolbar';
import type { WidgetMenuAction } from '../../../components/workspace/widget-frame';
import {
  IMPACT_RANK,
  type Impact,
  IMPACT_LABEL,
  COUNTER_META,
  IMPACT_TONE,
  greeting,
  IMPACT_SEVERITY,
  KPI_META,
  PRODUCT_TONES,
  type View,
  VIEWS,
  clock,
  shown,
  type InsightDomain,
  type Metric,
} from './_dashboard-meta';
import { InsightPanel } from './insight-panel';
import {
  Chip,
  Donut,
  Fact,
  Glass,
  GlassHead,
  HealthRow,
  HeroSpark,
  IncidentCard,
  KpiCard,
  SalesChart,
  SectionHead,
  Tag,
  dayMonth,
  percent,
  type HealthItem,
  type Incident,
  type KpiView,
  type SeriesPoint,
  type Tone,
} from './command-center';
import styles from './dashboard.module.css';

interface TopProduct {
  productId: number | null;
  title: string;
  orders: number;
  units: number | null;
  revenueMinor: number | null;
}

interface RecentOrder {
  id: number;
  number: string;
  email: string;
  status: string;
  totalMinor: number;
  createdAt: string;
}

/** حال النظام — نفس حمولة `/admin/system` التي تقرؤها شاشة النظام. */
interface SystemHealth {
  outbox: { pending: number; dead: number; oldestPendingSeconds: number | null; stalled: boolean };
  queue: { driver: string; status: 'MEASURED' | 'NOT_MEASURABLE'; pending: number | null; failed: number | null };
  operations: { uncertain: number; failed: number; expiredLeases: number };
  backups:
    | { state: 'MEASURED'; count: number; ageSeconds: number; stale: boolean; newest: { name: string; modifiedAt: string } }
    | { state: 'NOT_CONFIGURED' | 'NO_DATA'; reason: string };
  security: {
    admins: { total: number; owners: number; neverSignedIn: number };
    privilegedActions24h: { total: number; byAction: { action: string; count: number }[] };
    posture: {
      debug: boolean;
      environment: string;
      paymentProvider: { configured: string | null; resolved: string; live: boolean };
      secureCookies: boolean;
    };
    failedLogins: { state: 'NOT_CONFIGURED'; reason: string };
  };
}

interface Summary {
  generatedAt: string;
  range: { from: string; to: string; days: number; previousFrom: string; previousTo: string };
  currency: string;
  kpis: Metric[];
  series: SeriesPoint[];
  ordersByStatus: Record<string, number>;
  topProducts: TopProduct[];
  recentOrders: RecentOrder[];
  notConfigured: { key: string; label: string; reason: string }[];
}

export default function DashboardPage() {
  const { can, state } = useSession();
  // التحيّة الشخصيّة كانت في اللوحة قبل «مركز القيادة» وسقطت في إعادة البناء — تُعاد.
  const operatorName = state.status === 'authenticated' ? state.identity.user.name.trim() : '';
  const { range, activePreset, setRange } = useDateRange();
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const mayReadAnalytics = can('analytics.read');
  const mayReadOrders = can('orders.read');
  const mayReadAudit = can('audit.read');

  const viewParam = params.get('view');
  /*
   * `exec` كان ترتيبًا ثانيًا للأقسام؛ صار قالبًا («تنفيذي») يختاره المشغّل
   * ويُحفظ في حسابه. الرابط القديم يفتح اللوحة كما رتّبها صاحبها — لا يدهس
   * ترتيبه رابطٌ شاركه غيره.
   */
  const view: View = viewParam === 'wall' ? 'wall' : 'ops';
  const layout = useDashboardLayout();
  const [editing, setEditing] = useState(false);
  const [editable, setEditable] = useState(true);
  const { theme, setTheme } = useAdminTheme();

  const setParam = useCallback(
    (key: string, value: string | null) => {
      const search = new URLSearchParams(params.toString());
      if (value === null) search.delete(key);
      else search.set(key, value);
      const query = search.toString();
      router.replace(query === '' ? pathname : `${pathname}?${query}`, { scroll: false });
    },
    [params, pathname, router],
  );

  /*
   * دقيقةٌ واحدة بين قراءتين: «مباشر» تعني أنّ الرقم لا يتجاوز عمرَه دقيقة،
   * والشارة تقول ذلك حرفيًّا. أقصر من ذلك ضغطٌ على تجميعاتٍ ثقيلة بلا فائدة.
   */
  const summary = useQuery<Summary>({
    queryKey: ['admin-resource', '/admin/dashboard/summary', range.from, range.to],
    enabled: mayReadAnalytics,
    refetchInterval: 60_000,
    queryFn: async () => {
      const result = await api.get<Summary>(
        `/admin/dashboard/summary?from=${encodeURIComponent(range.from)}&to=${encodeURIComponent(range.to)}`,
      );
      if (!result.ok) throw new Error(result.message);
      return result.data;
    },
  });

  const ops = useOpsSummary(mayReadOrders, 60_000);

  const system = useQuery<SystemHealth>({
    queryKey: ['admin-resource', '/admin/system'],
    enabled: mayReadAudit,
    staleTime: 60_000,
    refetchInterval: 60_000,
    queryFn: async () => {
      const result = await api.get<SystemHealth>('/admin/system');
      if (!result.ok) throw new Error(result.message);
      return result.data;
    },
  });

  /*
   * سجلّ نطاقات التحليل — من الخادم لا من نسخةٍ في اللوحة.
   *
   * السجلّ لا يتغيّر بين لحظةٍ وأخرى (يُشتقّ من ثوابت الخادم)، فمدّةُ طزاجةٍ
   * طويلة تمنع نداءً في كلّ زيارة. ومشروطٌ بالصلاحية نفسها التي تُظهر القسم
   * — نداءٌ يُردّ ٤٠٣ لمن لا يرى القسم أصلًا ضجيجٌ في السجلّ لا أكثر.
   */
  const insightDomains = useQuery<{ generatedAt: string; domains: InsightDomain[] }>({
    queryKey: ['admin-resource', '/admin/insights'],
    enabled: mayReadAnalytics,
    staleTime: 600_000,
    queryFn: async () => {
      const result = await api.get<{ generatedAt: string; domains: InsightDomain[] }>('/admin/insights');
      if (!result.ok) throw new Error(result.message);
      return result.data;
    },
  });

  const [showSales, setShowSales] = useState(true);
  const [showOrders, setShowOrders] = useState(true);
  const [queueFilter, setQueueFilter] = useState<'all' | 'customer' | 'nonzero'>('all');

  const kpiOf = useCallback(
    (key: string): Metric | undefined => summary.data?.kpis.find((k) => k.key === key),
    [summary.data],
  );

  /* ───── العدّادات التشغيليّة → حوادث وطابور ───── */
  const measured = useMemo(() => {
    if (ops.data === undefined) return [];
    return Object.entries(ops.data.counters).flatMap(([key, counter]) =>
      counter !== undefined && counter.state === 'MEASURED' ? [{ key, ...counter }] : [],
    );
  }, [ops.data]);

  const queue = useMemo(() => {
    const rows = measured.map((row) => {
      const meta = COUNTER_META[row.key];
      const impact: Impact = meta?.impact ?? (row.alert ? 'platform' : 'watch');
      return { ...row, impact, meta };
    });
    return rows.sort((a, b) => IMPACT_RANK[a.impact] - IMPACT_RANK[b.impact] || b.value - a.value);
  }, [measured]);

  const incidents = useMemo<Incident[]>(
    () =>
      queue
        .filter((row) => row.alert && row.value > 0)
        .slice(0, 3)
        .map((row) => ({
          key: row.key,
          label: row.label,
          value: row.value,
          href: row.href,
          tone: IMPACT_TONE[row.impact],
          severity: IMPACT_SEVERITY[row.impact],
          impact: IMPACT_LABEL[row.impact],
          why: row.meta?.why ?? 'عدّادٌ مقيس من جدوله.',
          source: row.meta?.source ?? '—',
          recommendation: row.meta?.recommendation ?? 'افتح مكان معالجته.',
          primary: row.meta?.primary ?? 'افتح',
        })),
    [queue],
  );

  const severityCounts = useMemo(() => {
    const live = queue.filter((row) => row.value > 0);
    return {
      crit: live.filter((row) => row.alert && row.impact === 'customer').length,
      warn: live.filter((row) => row.alert && row.impact !== 'customer').length,
      watch: live.filter((row) => !row.alert).length,
      customerHits: live.filter((row) => row.impact === 'customer').reduce((s, row) => s + row.value, 0),
    };
  }, [queue]);

  const counterValue = useCallback(
    (key: string): number | null => {
      const counter: OpsCounter | undefined = ops.data?.counters[key];
      return counter !== undefined && counter.state === 'MEASURED' ? counter.value : null;
    },
    [ops.data],
  );

  /* ───── الالتزام بالمهلة — من عدّادَين مقيسَين ───── */
  const sla = useMemo(() => {
    const open = counterValue('openCases');
    const breached = counterValue('breachedCases');
    if (open === null || breached === null) return null;
    return { open, breached, ratio: open === 0 ? 1 : (open - breached) / open };
  }, [counterValue]);

  /* ───── المؤشّرات ───── */
  const kpiCards = useMemo<KpiView[]>(() => {
    if (summary.data === undefined) return [];
    const series = summary.data.series;
    const cards: KpiView[] = [];

    for (const [key, meta] of Object.entries(KPI_META)) {
      const metric = kpiOf(key);
      if (metric === undefined) continue;
      const diff = metric.value - metric.previous;
      const absText = `${diff > 0 ? '+' : diff < 0 ? '−' : ''}${shown(metric, Math.abs(diff))}`;
      let viz: KpiView['viz'] = { kind: 'none' };
      if (meta.spark !== undefined) viz = { kind: 'bars', points: series.map(meta.spark) };
      if (key === 'refunded' && metric.changePercent !== null && metric.changePercent >= 50) {
        viz = {
          kind: 'status',
          note: `تحذير: ${metric.changePercent >= 100 ? 'تضاعف' : 'ارتفع'} المستردّ ${percent(metric.changePercent)} مقابل السابق.`,
        };
      }
      const gross = kpiOf('grossSales');
      const sub =
        key === 'refunded' && gross !== undefined && gross.value > 0
          ? `${((metric.value / gross.value) * 100).toFixed(1)}% من الإجمالي`
          : key === 'aov'
            ? 'لكلّ طلبٍ مدفوع'
            : 'في المدى';

      cards.push({
        key,
        label: metric.label,
        tone: meta.tone,
        icon: meta.icon,
        value: shown(metric, metric.value),
        unit: meta.unit,
        previous: shown(metric, metric.previous),
        abs: absText,
        absLabel: 'الفرق المطلق',
        changePercent: metric.changePercent,
        goodUp: meta.goodUp,
        sub,
        formula: metric.formula,
        href: metric.href ?? null,
        drill: meta.drill,
        span: meta.span,
        viz,
      });
    }

    /*
     * ما لا تعرفه الواجهة يُعرض لا يُسقَط.
     *
     * الحلقة أعلاه تمشي على `KPI_META` — قائمةٌ مكتوبة هنا — فمؤشّرٌ يضيفه
     * الخادم غدًا كان يختفي بلا خطأ. الإصدار السابق من اللوحة كان يمشي على
     * ردّ الخادم نفسه فيعرض كلّ شيء؛ هذا يُعيد تلك الخاصّية ببطاقةٍ محايدة.
     */
    for (const metric of summary.data.kpis) {
      if (metric.key === 'grossSales' || metric.key in KPI_META) continue;
      const diff = metric.value - metric.previous;
      cards.push({
        key: metric.key,
        label: metric.label,
        tone: 'emerald',
        icon: 'pulse',
        value: shown(metric, metric.value),
        unit: '',
        previous: shown(metric, metric.previous),
        abs: `${diff > 0 ? '+' : diff < 0 ? '−' : ''}${shown(metric, Math.abs(diff))}`,
        absLabel: 'الفرق المطلق',
        changePercent: metric.changePercent,
        goodUp: true,
        sub: 'في المدى',
        formula: metric.formula,
        href: metric.href ?? null,
        drill: metric.label,
        span: 2,
        viz: { kind: 'none' },
      });
    }

    if (sla !== null) {
      cards.splice(2, 0, {
        key: 'sla',
        label: 'الالتزام بمهلة القضايا',
        tone: 'teal',
        icon: 'shield',
        value: `${Math.round(sla.ratio * 100)}%`,
        unit: '',
        previous: 'غير مقيس',
        abs: count(sla.breached),
        absLabel: 'قضايا خرقت المهلة',
        changePercent: null,
        noCompare: 'لا مدًى سابق — يُقاس الآن',
        goodUp: true,
        sub: `من ${count(sla.open)} قضايا مفتوحة`,
        formula: 'القضايا المفتوحة التي لم يتجاوز موعدها ÷ كلّ المفتوحة — الآن، لا في المدى.',
        href: '/operations/cases',
        drill: 'القضايا',
        span: 2,
        viz: {
          kind: 'radial',
          ratio: sla.ratio,
          text: `${count(sla.open - sla.breached)}/${count(sla.open)}`,
          note: sla.breached === 0 ? 'لا قضيّة تجاوزت مهلتها.' : `${count(sla.breached)} تجاوزت المهلة — المسؤول غير مقيس.`,
        },
      });
    }

    return cards;
  }, [summary.data, kpiOf, sla]);

  /* ───── صحّة الأعمال — حالةٌ مشتقّة من عدّادات مقيسة ───── */
  const health = useMemo<HealthItem[]>(() => {
    const items: HealthItem[] = [];
    const gross = kpiOf('grossSales');
    const orders = kpiOf('orders');
    const all = kpiOf('allOrders');
    const fresh = kpiOf('newCustomers');

    if (gross !== undefined) {
      items.push(
        gross.changePercent === null
          ? { key: 'sales', label: 'المبيعات', status: 'مستقرّ', tone: 'cyan', icon: 'pulse', cause: 'لا مقارنة — المدى السابق صفر', href: gross.href ?? null }
          : gross.changePercent >= 0
            ? { key: 'sales', label: 'المبيعات', status: 'سليم', tone: 'emerald', icon: 'pulse', cause: `${percent(gross.changePercent)} مقابل السابق`, href: gross.href ?? null }
            : { key: 'sales', label: 'المبيعات', status: 'تحذير', tone: 'orange', icon: 'pulse', cause: `${percent(gross.changePercent)} مقابل السابق`, href: gross.href ?? null },
      );
    }

    const failedPay = counterValue('failedPayments');
    const unmatched = counterValue('unmatchedWebhooks');
    if (failedPay !== null || unmatched !== null) {
      const bad = (failedPay ?? 0) + (unmatched ?? 0) > 0;
      items.push({
        key: 'payments',
        label: 'المدفوعات',
        status: bad ? 'تحذير' : 'سليم',
        tone: bad ? 'orange' : 'emerald',
        icon: 'card',
        cause: `${failedPay === null ? 'الفشل غير مقيس' : `${count(failedPay)} أحداث فشلت`} · ${unmatched === null ? 'المطابقة غير مقيسة' : `${count(unmatched)} بلا طلب`}`,
        href: '/payments/webhooks',
      });
    }

    if (orders !== undefined && all !== undefined) {
      const pending = counterValue('ordersNeedingAction');
      items.push({
        key: 'orders',
        label: 'الطلبات',
        status: pending !== null && pending > 0 ? 'متابعة' : 'سليم',
        tone: pending !== null && pending > 0 ? 'teal' : 'emerald',
        icon: 'cart',
        cause: all.value === 0 ? 'لا طلبات في المدى' : `${Math.round((orders.value / all.value) * 100)}% من المُنشأ دُفع`,
        href: all.href ?? null,
      });
    }

    if (fresh !== undefined) {
      items.push({
        key: 'customers',
        label: 'العملاء',
        status: 'مستقرّ',
        tone: 'cyan',
        icon: 'users',
        cause: `${count(fresh.value)} جديدًا · ${fresh.changePercent === null ? 'لا مقارنة' : percent(fresh.changePercent)}`,
        href: null,
      });
    }

    const undelivered = counterValue('undeliveredOrders');
    const failedFul = counterValue('failedFulfillment');
    if (undelivered !== null || failedFul !== null) {
      const bad = (undelivered ?? 0) + (failedFul ?? 0) > 0;
      items.push({
        key: 'fulfillment',
        label: 'التنفيذ',
        status: bad ? 'حرج' : 'سليم',
        tone: bad ? 'red' : 'emerald',
        icon: 'layers',
        cause: `${undelivered === null ? 'التسليم غير مقيس' : `${count(undelivered)} بلا صلاحيّة`} · ${failedFul === null ? 'الفشل غير مقيس' : `${count(failedFul)} فاشلة`}`,
        href: '/operations/fulfillment',
      });
    }

    if (!mayReadAudit) {
      items.push(
        { key: 'platform', label: 'المنصّة', status: 'محجوب', tone: 'slate', icon: 'server', cause: 'يحتاج صلاحية audit.read', href: null },
        { key: 'security', label: 'الأمان', status: 'محجوب', tone: 'slate', icon: 'shield', cause: 'يحتاج صلاحية audit.read', href: null },
      );
    } else if (system.data !== undefined) {
      const s = system.data;
      const backupsBad = s.backups.state !== 'MEASURED' || s.backups.stale;
      const outboxBad = s.outbox.stalled || s.outbox.dead > 0;
      items.push({
        key: 'platform',
        label: 'المنصّة',
        status: outboxBad || backupsBad ? 'تحذير' : 'سليم',
        tone: outboxBad || backupsBad ? 'orange' : 'emerald',
        icon: 'server',
        cause: `الصادر ${s.outbox.stalled ? 'متوقّف' : `${count(s.outbox.pending)} معلّق`} · النسخ ${s.backups.state === 'MEASURED' ? `${Math.floor(s.backups.ageSeconds / 3600)} س` : s.backups.state.replace('_', ' ')}`,
        href: '/system',
      });
      const allOwners = s.security.admins.owners === s.security.admins.total;
      const secBad = allOwners || s.security.admins.neverSignedIn > 0 || !s.security.posture.paymentProvider.live;
      items.push({
        key: 'security',
        label: 'الأمان',
        status: secBad ? 'تحذير' : 'سليم',
        tone: secBad ? 'orange' : 'emerald',
        icon: 'shield',
        cause: [
          allOwners ? 'كلّ الحسابات مالكة' : `${count(s.security.admins.owners)} من ${count(s.security.admins.total)} مالك`,
          s.security.admins.neverSignedIn > 0 ? `${count(s.security.admins.neverSignedIn)} لم يُستعمل قطّ` : null,
          s.security.posture.paymentProvider.live ? null : 'بوّابة وهميّة',
        ]
          .filter((v) => v !== null)
          .join(' · '),
        href: '/audit',
      });
    }

    const jobs = counterValue('queueFailures');
    const uncertain = counterValue('uncertainOperations');
    if (jobs !== null || uncertain !== null) {
      const bad = (jobs ?? 0) + (uncertain ?? 0) > 0;
      items.push({
        key: 'automation',
        label: 'الآليّات',
        status: bad ? 'تحذير' : 'مستقرّ',
        tone: bad ? 'orange' : 'cyan',
        icon: 'cpu',
        cause: `${jobs === null ? 'الطابور غير مقيس' : `${count(jobs)} مهمّة فاشلة`} · ${uncertain === null ? 'العمليّات غير مقيسة' : `${count(uncertain)} مجهولة`}`,
        href: '/system',
      });
    }

    return items;
  }, [kpiOf, counterValue, mayReadAudit, system.data]);

  /* ───── المخطّط: الذروة والوسط ───── */
  const chartFacts = useMemo(() => {
    if (summary.data === undefined) return null;
    const { series, range: win } = summary.data;
    const gross = kpiOf('grossSales');
    const net = kpiOf('netSales');
    const orders = kpiOf('orders');
    const all = kpiOf('allOrders');
    const refunded = kpiOf('refunded');
    const peak = series.reduce<SeriesPoint | null>((best, p) => (best === null || p.grossMinor > best.grossMinor ? p : best), null);
    return {
      peak,
      perDay: gross === undefined || win.days === 0 ? null : Math.round(gross.value / win.days),
      net,
      orders,
      all,
      refunded,
      gross,
    };
  }, [summary.data, kpiOf]);

  /* ───── القرص ───── */
  const donut = useMemo(() => {
    const rows = Object.entries(summary.data?.ordersByStatus ?? {}).sort((a, b) => b[1] - a[1]);
    return rows.map(([status, value]) => ({
      key: status,
      label: ORDER_STATUS_LABELS[status] ?? status,
      value,
      tone: orderTone(status),
    }));
  }, [summary.data]);

  /* ───── رؤى مشتقّة — من أرقام هذه الصفحة وحدها ───── */
  const insights = useMemo(() => {
    const out: { kind: string; tone: Tone; text: string }[] = [];
    if (chartFacts === null) return out;
    const { gross, orders, refunded, peak } = chartFacts;

    if (gross !== undefined && gross.changePercent !== null && peak !== null && peak.grossMinor > 0) {
      out.push({
        kind: 'حقيقة مقيسة',
        tone: 'emerald',
        text: `المبيعات ${gross.changePercent >= 0 ? 'أعلى' : 'أدنى'} بـ ${Math.abs(gross.changePercent)}% من المدى السابق، والذروة يوم ${dayMonth(peak.date)} بـ ${money(peak.grossMinor)} ر.س.`,
      });
    }

    const top = summary.data?.topProducts ?? [];
    const first = top[0];
    if (gross !== undefined && gross.value > 0 && first !== undefined && top.every((p) => p.revenueMinor !== null)) {
      const share = top.reduce((s, p) => s + (p.revenueMinor ?? 0), 0) / gross.value;
      out.push({
        kind: 'حقيقة مقيسة',
        tone: 'emerald',
        text: `أعلى ${count(top.length)} منتجات ولّدت ${Math.round(share * 100)}% من إيراد المدى — و«${first.title}» وحده ${Math.round(((first.revenueMinor ?? 0) / gross.value) * 100)}%.`,
      });
    }

    if (refunded !== undefined && orders !== undefined && refunded.changePercent !== null && orders.changePercent !== null) {
      const faster = refunded.changePercent > orders.changePercent + 25;
      out.push({
        kind: faster ? 'استنتاج' : 'حقيقة مقيسة',
        tone: faster ? 'blue' : 'emerald',
        text: faster
          ? `المستردّ تغيّر ${percent(refunded.changePercent)} بينما الطلبات المدفوعة ${percent(orders.changePercent)} — الردّ ينمو أسرع من البيع؛ راجع أسباب الاسترداد.`
          : `المستردّ تغيّر ${percent(refunded.changePercent)} والطلبات المدفوعة ${percent(orders.changePercent)}.`,
      });
    }

    if (sla !== null && sla.breached > 0) {
      out.push({
        kind: 'توصية',
        tone: 'violet',
        text: `${count(sla.breached)} ${sla.breached === 1 ? 'قضيّة تجاوزت' : 'قضايا تجاوزت'} المهلة؛ الردّ الأوّل الآن يوقف عدّاد الخرق.`,
      });
    }

    return out;
  }, [chartFacts, summary.data, sla]);

  const unmeasured = useMemo(() => {
    const items = [...(summary.data?.notConfigured ?? []), ...(ops.data?.notConfigured ?? [])];
    if (system.data !== undefined) {
      items.push({ key: 'failedLogins', label: 'محاولات الدخول الفاشلة', reason: system.data.security.failedLogins.reason });
    }
    return items;
  }, [summary.data, ops.data, system.data]);

  const rangeLabel = summary.data === undefined ? `${range.from} → ${range.to}` : `${count(summary.data.range.days)} يومًا`;
  const refresh = () => {
    void summary.refetch();
    void ops.refetch();
    void system.refetch();
  };

  const gross = kpiOf('grossSales');

  /* ───── مساحة العمل: الأسماء والأفعال والمقاييس المتاحة ───── */

  /** المقاييس التي يمكن أن تعرضها بطاقة «مؤشّر» — ما أرجعه الخادم فعلًا (والمهلة إن قيست). */
  const availableMetrics = useMemo(() => kpiCards.map((k) => k.key), [kpiCards]);
  const metricLabel = useCallback(
    (metric: string | null) =>
      kpiCards.find((k) => k.key === metric)?.label ?? KPI_METRICS.find((k) => k.metric === metric)?.label ?? 'مؤشّر',
    [kpiCards],
  );

  const titleOf = useCallback(
    (id: string, state: WidgetState) => {
      const type = typeOf(id, state);
      if (type === 'kpi') return metricLabel(metricOf(id, state));
      return type === null ? id : (WIDGET_TYPES[type]?.title ?? id);
    },
    [metricLabel],
  );

  /*
   * ما لا تعرفه الواجهة يُعرض لا يُسقَط — القاعدة نفسها التي كانت في شبكة
   * المؤشّرات: مقياسٌ يضيفه الخادم غدًا يصير بطاقةً ظاهرة في آخر الشبكة.
   */
  const { update: updateLayout } = layout;
  useEffect(() => {
    if (availableMetrics.length > 0) updateLayout((d) => ensureMetricWidgets(d, availableMetrics), { record: false });
  }, [availableMetrics, updateLayout]);

  const actionsOf = (id: string, state: WidgetState): WidgetMenuAction[] => {
    const blocker = duplicateBlocker(layout.doc, id, availableMetrics);
    const actions: WidgetMenuAction[] = [];
    if (typeOf(id, state) === 'kpi') {
      const selectId = `metric-${id}`;
      actions.push({
        key: 'metric',
        label: 'المقياس',
        render: () => (
          <>
            <label htmlFor={selectId}>المقياس</label>
            <select
              id={selectId}
              className="field"
              value={metricOf(id, state) ?? ''}
              onChange={(e) => layout.update((d) => setMetric(d, id, e.target.value))}
            >
              {availableMetrics.map((m) => <option key={m} value={m}>{metricLabel(m)}</option>)}
            </select>
          </>
        ),
      });
    }
    actions.push(
      {
        key: 'duplicate',
        label: 'تكرار بمقياسٍ آخر',
        disabled: blocker ?? undefined,
        onSelect: () => layout.update((d) => duplicateWidget(d, id, availableMetrics)?.doc ?? d),
      },
      { key: 'reset', label: 'إعادة حجمها الافتراضيّ', onSelect: () => layout.update((d) => resetWidget(d, id)) },
      { key: 'hide', label: 'إخفاء البطاقة', onSelect: () => layout.update((d) => setHidden(d, id, true)) },
    );
    return actions;
  };

  /* ───── شاشة العرض ───── */
  if (view === 'wall') {
    const wallKpis = [gross, kpiOf('orders'), kpiOf('refunded')].filter((m): m is Metric => m !== undefined);
    return (
      <>
        <PageHeader trail="لوحة التشغيل" title="مركز القيادة" />
        <div className={styles.page} data-theme={theme} dir="rtl">
          <div className={styles.mesh} data-fx="ambient" aria-hidden="true" />
          <span className={styles.ambient} data-fx="ambient" aria-hidden="true" />
          <div className={styles.wall}>
            <header className={styles.wallHead}>
              {/*
                علامةٌ ثابتة بحجمٍ واحد على شاشة جدارٍ لا تتغيّر — `next/image`
                هنا رحلةُ تحسينٍ لأصلٍ محسَّنٍ سلفًا. العرف نفسه في
                `components/sidebar.tsx`، ومخفيّةٌ عن القارئ الآليّ لأنّ الاسم
                مكتوب نصًّا في العنوان المجاور.
              */}
              {/* eslint-disable-next-line @next/next/no-img-element -- علامة ثابتة محسَّنة سلفًا بحجمٍ واحد؛ لا فائدة من مُحسِّن الصور */}
              <img src="/brand/logo-mark.png" alt="" width="256" height="227" />
              <div style={{ flex: 1, minInlineSize: 0 }}>
                <h1>مركز القيادة — الآن</h1>
                <p>
                  {operatorName === '' ? null : <>{greeting()}، {operatorName} · </>}
                  التحاضير الحديثة · {rangeLabel} · تحديث آليّ كلّ ٦٠ ثانية
                </p>
              </div>
              {ops.data === undefined ? null : (
                <Link className={styles.wallAlarm} data-calm={severityCounts.customerHits === 0 ? '' : undefined} href="/operations/cases">
                  <i aria-hidden="true" />
                  {severityCounts.customerHits === 0 ? (
                    'لا عطل يمسّ عميلًا'
                  ) : (
                    <>
                      <bdi dir="ltr">{count(severityCounts.customerHits)}</bdi> أعطال تمسّ عميلًا
                    </>
                  )}
                </Link>
              )}
              <button type="button" className={styles.iconBtn} aria-label="عودة إلى لوحة العمليّات" onClick={() => setParam('view', null)}>
                <Icon name="close" size={16} />
              </button>
            </header>

            {summary.isPending ? (
              <Skeleton rows={2} columns={4} />
            ) : summary.isError ? (
              <ErrorState message={summary.error.message} onRetry={refresh} />
            ) : (
              <div className={styles.wallKpis}>
                {wallKpis.map((m) => {
                  const meta = KPI_META[m.key];
                  const tone: Tone = meta?.tone ?? 'emerald';
                  return (
                    <Glass key={m.key} tone={tone} band className={styles.wallKpi}>
                      <span className={styles.glow} data-fx="ambient glow" aria-hidden="true" />
                      <p className={styles.wallLabel}>{m.label}</p>
                      <p className={styles.wallValue}>
                        <bdi dir="ltr">{shown(m, m.value)}</bdi>
                      </p>
                      <p className={styles.wallChange}>
                        {m.changePercent === null ? <span>لا مقارنة</span> : (
                          <>
                            <bdi dir="ltr">{percent(m.changePercent)}</bdi> <span>مقابل السابق</span>
                          </>
                        )}
                      </p>
                    </Glass>
                  );
                })}
                {sla === null ? null : (
                  <Glass tone="teal" band className={styles.wallKpi}>
                    <span className={styles.glow} data-fx="ambient glow" aria-hidden="true" />
                    <p className={styles.wallLabel}>الالتزام بالمهلة</p>
                    <p className={styles.wallValue}>
                      <bdi dir="ltr">{Math.round(sla.ratio * 100)}%</bdi>
                    </p>
                    <p className={styles.wallChange}>
                      <bdi dir="ltr">{count(sla.breached)}</bdi> <span>خرق من {count(sla.open)} مفتوحة</span>
                    </p>
                  </Glass>
                )}
              </div>
            )}

            <div className={styles.wallGrid}>
              <Glass className={styles.wallCard}>
                <h2>المبيعات اليوميّة</h2>
                {summary.data === undefined ? null : summary.data.series.length === 0 ? (
                  <EmptyState icon="chart" title="لا بيانات" body="المدى المختار بلا أيّام." />
                ) : (
                  <SalesChart series={summary.data.series} showSales showOrders large />
                )}
              </Glass>
              <Glass className={styles.wallCard}>
                <h2>ما ينتظر تدخّلًا</h2>
                {ops.isPending ? <Skeleton rows={4} columns={1} /> : ops.isError ? (
                  <ErrorState message={ops.error.message} onRetry={refresh} />
                ) : (
                  <>
                    {incidents.map((f) => (
                      <Link key={f.key} className={styles.wallIncident} data-tone={f.tone} href={f.href}>
                        <span className={styles.dot} aria-hidden="true" />
                        <span>{f.label}</span>
                        <bdi dir="ltr">{count(f.value)}</bdi>
                      </Link>
                    ))}
                    {queue
                      .filter((row) => !incidents.some((f) => f.key === row.key))
                      .slice(0, 4)
                      .map((row) => (
                        <Link key={row.key} className={styles.wallIncident} data-tone={IMPACT_TONE[row.impact]} data-rest="" href={row.href}>
                          <span className={styles.dot} aria-hidden="true" />
                          <span>{row.label}</span>
                          <bdi dir="ltr">{count(row.value)}</bdi>
                        </Link>
                      ))}
                  </>
                )}
              </Glass>
            </div>
          </div>
        </div>
      </>
    );
  }


  /* ───── البطاقات — محتوى كلّ واحدةٍ كما كان، وموضعُها للمحرّك ───── */

  const perfGate = !mayReadAnalytics ? (
    <div className={styles.gated}>
      <ErrorState code={403} message="قراءة المؤشّرات تحتاج صلاحية analytics.read. بقيّة اللوحة تعمل." />
    </div>
  ) : summary.isError ? (
    <div className={styles.gated}><ErrorState message={summary.error.message} onRetry={refresh} /></div>
  ) : summary.isPending || gross === undefined || chartFacts === null ? (
    <Glass><div className={styles.state}><Skeleton rows={3} columns={2} /></div></Glass>
  ) : null;

  const kpiWidget = (id: string, state: WidgetState) => {
    if (perfGate !== null) return perfGate;
    const metric = metricOf(id, state);
    const kpi = kpiCards.find((k) => k.key === metric);
    if (kpi === undefined) {
      return (
        <Glass>
          <div className={styles.gated}>
            <EmptyState icon="chart" title="المقياس غير متاح" body={metric === 'sla' ? 'الالتزام بالمهلة يحتاج عدّادَي القضايا مقيسَين.' : 'الخادم لم يُرجع هذا المقياس في المدى المختار. اختر مقياسًا آخر من إعدادات البطاقة.'} />
          </div>
        </Glass>
      );
    }
    return <KpiCard kpi={kpi} rangeLabel={rangeLabel} />;
  };

  const widgetContent: Record<string, ReactNode> = {
    attention: (
      <section className={styles.section}>
        <SectionHead
          tone="red"
          icon="alert"
          title="مركز الانتباه"
          note="مرتَّب بأثر العميل ثمّ الإيراد ثمّ المنصّة — لا بالعدد"
          end={
            ops.data === undefined ? null : (
              <>
                <Chip tone="red"><bdi dir="ltr">{count(severityCounts.crit)}</bdi> حرج</Chip>
                <Chip tone="orange"><bdi dir="ltr">{count(severityCounts.warn)}</bdi> تحذير</Chip>
                <Chip tone="slate"><bdi dir="ltr">{count(severityCounts.watch)}</bdi> متابعة</Chip>
              </>
            )
          }
        />
          <div className={styles.incidents}>
            {!mayReadOrders ? (
              <div className={styles.gated}>
                <EmptyState icon="shield" title="لا صلاحية" body="هذه اللوحة تحتاج صلاحية orders.read." />
              </div>
            ) : ops.isPending ? (
              <Glass><div className={styles.state}><Skeleton rows={4} columns={1} /></div></Glass>
            ) : ops.isError ? (
              <div className={styles.gated}><ErrorState message={ops.error.message} onRetry={refresh} /></div>
            ) : incidents.length === 0 ? (
              <div className={styles.gated}>
                <EmptyState icon="check" title="لا عطل ينتظر" body="كلّ عدّادات الإنذار المقيسة عند الصفر. ما دونها في طابور التشغيل." />
              </div>
            ) : (
              incidents.map((f, i) => <IncidentCard key={f.key} incident={f} sweep={i === 0} />)
            )}
          </div>
      </section>
    ),
    health: (
        <Glass className={styles.health} tone="teal" band>
          <header>
            <h3>صحّة الأعمال</h3>
            <span>حالةٌ مشتقّة من عدّادات مقيسة — لا درجة تُخترع</span>
          </header>
          {health.length === 0 ? (
            <div className={styles.state}>بانتظار المصادر…</div>
          ) : (
            <ul className={styles.healthList}>
              {health.map((item) => <HealthRow key={item.key} item={item} />)}
            </ul>
          )}
        </Glass>
    ),
    hero:
      perfGate ?? (gross === undefined || chartFacts === null || summary.data === undefined ? null : (
        <article className={styles.hero}>
          <span className={styles.heroGlow} data-fx="ambient glow" aria-hidden="true" />
          <span className={styles.sweep} data-fx="ambient" aria-hidden="true" />
          <div className={styles.heroHead}>
            <span className={styles.heroTile} aria-hidden="true"><Icon name="pulse" size={15} /></span>
            <h3>{gross.label}</h3>
            <span className={styles.heroBadge}>المؤشّر الأوّل</span>
            {gross.href ? <Link className={styles.heroLink} href={gross.href}>الطلبات المدفوعة ←</Link> : null}
          </div>
          <p className={styles.heroValue}>
            <bdi dir="ltr">{money(gross.value)}</bdi>
            <small>ر.س</small>
          </p>
          <div className={styles.heroChange}>
            {gross.changePercent === null ? (
              <span className={styles.heroPill}>لا مقارنة — المدى السابق صفر</span>
            ) : (
              <>
                <span className={styles.heroPill}>
                  <Icon name="trend" size={12} flip={gross.changePercent < 0} />
                  <bdi dir="ltr">{percent(gross.changePercent)}</bdi>
                </span>
                <span>
                  {gross.value >= gross.previous ? '▲' : '▼'} <bdi dir="ltr" style={{ fontWeight: 800 }}>{money(Math.abs(gross.value - gross.previous))}</bdi> ر.س مقابل <bdi dir="ltr">{money(gross.previous)}</bdi>
                </span>
              </>
            )}
          </div>
          <HeroSpark points={summary.data.series.map((p) => p.grossMinor)} />
          <div className={styles.heroFacts}>
            <span className={styles.heroFact}>
              <em>الذروة</em>
              <bdi dir="ltr">{chartFacts.peak === null ? '—' : money(chartFacts.peak.grossMinor)}</bdi>
              <em data-dim=""><bdi dir="ltr">{chartFacts.peak === null ? '—' : dayMonth(chartFacts.peak.date)}</bdi></em>
            </span>
            <span className={styles.heroFact}>
              <em>الوسط اليوميّ</em>
              <bdi dir="ltr">{chartFacts.perDay === null ? '—' : money(chartFacts.perDay)}</bdi>
              <em data-dim="">ر.س يوميًّا</em>
            </span>
            <span className={styles.heroFact} data-dashed="">
              <em>الهدف</em>
              <strong style={{ fontSize: 11 }}>لم يُضبط</strong>
              <em data-dim="">لا هدفٌ محفوظ في الخادم</em>
            </span>
          </div>
        </article>
      )),
    'sales-chart': (
      <Glass className={styles.chartCard} tone="emerald" band>
        <GlassHead
          title={`المبيعات والطلبات — ${rangeLabel}`}
          note="من صفوف الطلبات. الأعمدة: عدد الطلبات اليوميّ. المدى السابق مجمَّعٌ لا سلسلة — فلا خطّ له."
          end={
            <>
              <button type="button" className={styles.toggle} data-tone="emerald" aria-pressed={showSales} onClick={() => setShowSales((v) => !v)}>المبيعات</button>
              <button type="button" className={styles.toggle} data-tone="blue" aria-pressed={showOrders} onClick={() => setShowOrders((v) => !v)}>الطلبات</button>
            </>
          }
        />
        {!mayReadAnalytics ? null : summary.isPending ? (
          <div className={styles.state}><Skeleton rows={4} columns={6} /></div>
        ) : summary.isError ? (
          <div className={styles.gated}><ErrorState message={summary.error.message} onRetry={refresh} /></div>
        ) : summary.data.series.length === 0 ? (
          <div className={styles.gated}><EmptyState icon="chart" title="لا بيانات" body="المدى المختار بلا أيّام." /></div>
        ) : (
          <>
            <SalesChart series={summary.data.series} showSales={showSales} showOrders={showOrders} />
            <details className={styles.dataTable}>
              <summary>القيم رقمًا</summary>
              <table className={styles.table}>
                <thead>
                  <tr>
                    <th scope="col">اليوم</th>
                    <th scope="col">الطلبات</th>
                    <th scope="col">المدفوعة</th>
                    <th scope="col">المبيعات</th>
                  </tr>
                </thead>
                <tbody>
                  {summary.data.series.map((p) => (
                    <tr key={p.date}>
                      <td><bdi dir="ltr">{p.date}</bdi></td>
                      <td><bdi dir="ltr">{count(p.orders)}</bdi></td>
                      <td><bdi dir="ltr">{count(p.paidOrders)}</bdi></td>
                      <td><bdi dir="ltr">{money(p.grossMinor)}</bdi></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </details>
            {chartFacts === null ? null : (
              <footer className={styles.chartFoot}>
                <span className={styles.chartFact} data-tone="emerald"><em>صافي المبيعات</em><bdi dir="ltr">{chartFacts.net === undefined ? '—' : money(chartFacts.net.value)}</bdi></span>
                <span className={styles.chartFact} data-tone="blue"><em>الطلبات المدفوعة</em><bdi dir="ltr">{chartFacts.orders === undefined ? '—' : count(chartFacts.orders.value)}</bdi></span>
                <span className={styles.chartFact}><em>الإيراد/اليوم</em><bdi dir="ltr">{chartFacts.perDay === null ? '—' : money(chartFacts.perDay)}</bdi></span>
                <span className={styles.chartFact}><em>كلّ الطلبات</em><bdi dir="ltr">{chartFacts.all === undefined ? '—' : count(chartFacts.all.value)}</bdi></span>
                <span className={styles.chartFact} data-tone="amber"><em>المستردّ</em><bdi dir="ltr">{chartFacts.refunded === undefined ? '—' : money(chartFacts.refunded.value)}</bdi></span>
                <span className={styles.chartFact} data-tone="violet"><em>يوم الذروة</em><bdi dir="ltr">{chartFacts.peak === null ? '—' : dayMonth(chartFacts.peak.date)}</bdi></span>
              </footer>
            )}
          </>
        )}
      </Glass>
    ),
    lifecycle: (
      <Glass tone="blue" band>
        <GlassHead
          title="دورة حياة الطلب"
          note={chartFacts?.all === undefined ? undefined : <><bdi dir="ltr">{count(chartFacts.all.value)}</bdi> طلبًا في المدى</>}
          end={<Link className={styles.drill} data-tone="blue" href="/orders">الطلبات ←</Link>}
        />
        <div className={styles.glassBody}>
          {summary.isPending ? <Skeleton rows={5} columns={2} /> : summary.isError ? (
            <ErrorState message={summary.error.message} onRetry={refresh} />
          ) : donut.length === 0 ? (
            <EmptyState icon="cart" title="لا طلبات في هذا المدى" body="جرّب مدًى أوسع." />
          ) : (
            <>
              <Donut
                slices={donut}
                centerText={chartFacts?.all === undefined || chartFacts.orders === undefined || chartFacts.all.value === 0 ? '—' : `${Math.round((chartFacts.orders.value / chartFacts.all.value) * 100)}%`}
                centerNote="دُفع من المُنشأ"
              />
              <div className={styles.miniFacts}>
                <Fact label="تحتاج معالجة"><bdi dir="ltr" data-tone="orange" style={{ color: 'var(--cc-orange-text)' }}>{(() => { const m = kpiOf('needsAction'); return m === undefined ? '—' : count(m.value); })()}</bdi></Fact>
                <Fact label="المستردّ"><bdi dir="ltr">{chartFacts?.refunded === undefined ? '—' : money(chartFacts.refunded.value)}</bdi></Fact>
                <Fact label="وسيط زمن المعالجة"><Tag>NOT MEASURED</Tag></Fact>
              </div>
            </>
          )}
        </div>
      </Glass>
    ),
    stream: (
      <Glass tone="cyan" band>
        <GlassHead
          title="البثّ المباشر"
          note={<><span className={styles.pulse} style={{ display: 'inline-block', background: 'var(--cc-cyan-text)', marginInlineEnd: 6 }} aria-hidden="true" />أحدث ثمانية طلبات — بلا قيد مدًى</>}
          end={<Link className={styles.drill} data-tone="cyan" href="/orders">كلّ الطلبات ←</Link>}
        />
        {summary.isPending ? <div className={styles.state}><Skeleton rows={5} columns={2} /></div> : summary.isError ? (
          <div className={styles.gated}><ErrorState message={summary.error.message} onRetry={refresh} /></div>
        ) : summary.data.recentOrders.length === 0 ? (
          <div className={styles.gated}><EmptyState icon="cart" title="لا طلبات بعد" body="أوّل طلبٍ سيظهر هنا فور وصوله." /></div>
        ) : (
          <ul className={styles.stream}>
            {summary.data.recentOrders.map((o) => {
              const tone = orderTone(o.status);
              const cc: Tone = tone === 'success' ? 'emerald' : tone === 'danger' ? 'red' : tone === 'warning' ? 'amber' : tone === 'info' ? 'blue' : 'slate';
              return (
                <li key={o.id}>
                  <Link className={styles.streamRow} href={`/orders/${o.id}`}>
                    <span className={styles.streamTile} data-tone={cc} aria-hidden="true"><Icon name="cart" size={12} /></span>
                    <span className={styles.streamText}>
                      <b>{ORDER_STATUS_LABELS[o.status] ?? o.status} · <bdi dir="ltr">{o.number}</bdi></b>
                      <small><bdi dir="ltr">{money(o.totalMinor)}</bdi> ر.س · <bdi dir="ltr">{o.email}</bdi></small>
                    </span>
                    <bdi dir="ltr" className={styles.streamTime}>{dateTime(o.createdAt)}</bdi>
                  </Link>
                </li>
              );
            })}
          </ul>
        )}
      </Glass>
    ),
    queue: (
      <Glass tone="red" band>
        <GlassHead
          title="طابور التشغيل"
          note={ops.data === undefined ? undefined : `${count(measured.length)} عدّادًا مقيسًا · إجراء في كلّ صفّ · العمر والمسؤول غير مقيسَين`}
          end={
            <>
              <button type="button" className={styles.toggle} aria-pressed={queueFilter === 'all'} onClick={() => setQueueFilter('all')}>الكلّ</button>
              <button type="button" className={styles.toggle} aria-pressed={queueFilter === 'customer'} onClick={() => setQueueFilter('customer')}>يمسّ عميلًا</button>
              <button type="button" className={styles.toggle} aria-pressed={queueFilter === 'nonzero'} onClick={() => setQueueFilter('nonzero')}>غير الصفريّ</button>
            </>
          }
        />
        {!mayReadOrders ? (
          <div className={styles.gated}><EmptyState icon="shield" title="لا صلاحية" body="الطابور يحتاج صلاحية orders.read." /></div>
        ) : ops.isPending ? (
          <div className={styles.state}><Skeleton rows={6} columns={4} /></div>
        ) : ops.isError ? (
          <div className={styles.gated}><ErrorState message={ops.error.message} onRetry={refresh} /></div>
        ) : (
          <table className={styles.table}>
            <thead>
              <tr>
                <th scope="col">العطل</th>
                <th scope="col">العدد</th>
                <th scope="col">الأثر</th>
                <th scope="col">المصدر</th>
                <th scope="col">إجراء</th>
              </tr>
            </thead>
            <tbody>
              {queue
                .filter((row) => queueFilter === 'all' || (queueFilter === 'customer' ? row.impact === 'customer' : row.value > 0))
                .map((row) => {
                  const tone = row.value === 0 ? 'slate' : IMPACT_TONE[row.impact];
                  return (
                    <tr key={row.key} data-tone={row.alert && row.impact === 'customer' && row.value > 0 ? 'red' : undefined}>
                      <td>
                        <Link className={styles.queueLabel} data-tone={tone} href={row.href}>
                          <span className={styles.dot} aria-hidden="true" />
                          {row.label}
                        </Link>
                      </td>
                      <td><bdi dir="ltr" className={styles.queueValue} data-tone={tone}>{count(row.value)}</bdi></td>
                      <td><Chip tone={tone}>{IMPACT_LABEL[row.impact]}</Chip></td>
                      <td className={styles.queueMuted}><code dir="ltr">{row.meta?.source ?? '—'}</code></td>
                      <td><Link className={styles.action} data-small="" href={row.href}>{row.meta?.primary ?? 'افتح'}</Link></td>
                    </tr>
                  );
                })}
            </tbody>
          </table>
        )}
      </Glass>
    ),
    insights: (
      <Glass className={styles.insightsCard}>
        <GlassHead
          title="رؤى مشتقّة"
          note="من أرقام هذه الصفحة وحدها — لا نموذج ولا تخمين"
          end={<span className={styles.sectionIcon} data-tone="violet" aria-hidden="true"><Icon name="sparkle" size={12} /></span>}
        />
        {insights.length === 0 ? (
          <div className={styles.state}>لا رؤية تُشتقّ من هذا المدى بعد — تحتاج مدًى سابقًا غير صفريّ.</div>
        ) : (
          <ul className={styles.insights}>
            {insights.map((i, n) => (
              <li key={n} className={styles.insight}>
                <Chip tone={i.tone}>{i.kind}</Chip>
                <span>{i.text}</span>
              </li>
            ))}
          </ul>
        )}
      </Glass>
    ),
    products: (
      <Glass tone="lime" band>
        <GlassHead title="ذكاء المنتجات" note="مرتَّبة بعدد الطلبات المدفوعة التي ضمّتها" />
        {summary.isPending ? <div className={styles.state}><Skeleton rows={5} columns={2} /></div> : summary.isError ? (
          <div className={styles.gated}><ErrorState message={summary.error.message} onRetry={refresh} /></div>
        ) : summary.data.topProducts.length === 0 ? (
          <div className={styles.gated}><EmptyState icon="box" title="لا مبيعات في هذا المدى" body="لا طلب مدفوع في المدى المختار، فلا ترتيب يُحسب." /></div>
        ) : (
          <ol className={styles.ranked}>
            {summary.data.topProducts.map((p, i) => {
              const tone = PRODUCT_TONES[i % PRODUCT_TONES.length];
              const share = gross === undefined || gross.value === 0 || p.revenueMinor === null ? null : Math.round((p.revenueMinor / gross.value) * 100);
              return (
                <li key={`${p.productId ?? 'x'}-${i}`} className={styles.rankedRow} data-tone={tone}>
                  <span className={styles.thumb} aria-hidden="true"><span className={styles.rank}>{i + 1}</span></span>
                  <span className={styles.rankedText}>
                    <b title={p.title}>{p.title}</b>
                    <span className={styles.share}>
                      {share === null ? null : (
                        <span className={styles.shareTrack}><span className={styles.shareFill} style={{ inlineSize: `${share}%` }} /></span>
                      )}
                      <small>
                        {share === null ? 'الإيراد غير مُقاس' : <><bdi dir="ltr">{share}%</bdi> من الإيراد</>} · <bdi dir="ltr">{count(p.orders)}</bdi> طلب
                      </small>
                    </span>
                  </span>
                  <span className={styles.rankedEnd}>
                    <bdi dir="ltr">{p.revenueMinor === null ? '—' : money(p.revenueMinor)}</bdi>
                    <small>{p.units === null ? 'الكمّيّة غير مقيسة' : <><bdi dir="ltr">{count(p.units)}</bdi> وحدة</>}</small>
                  </span>
                </li>
              );
            })}
          </ol>
        )}
      </Glass>
    ),
    customers: (
      <Glass tone="purple" band>
        <GlassHead title="ذكاء العملاء" note={(() => { const m = kpiOf('newCustomers'); return m === undefined ? undefined : <><bdi dir="ltr">{count(m.value)}</bdi> عميلًا جديدًا في المدى</>; })()} />
        <div className={styles.glassBody}>
          {summary.isPending ? <Skeleton rows={3} columns={2} /> : summary.isError ? (
            <ErrorState message={summary.error.message} onRetry={refresh} />
          ) : (() => {
            const m = kpiOf('newCustomers');
            if (m === undefined) return <div className={styles.state}>لا مؤشّر عملاء في الحمولة.</div>;
            return (
              <>
                <ul className={styles.segList}>
                  <li className={styles.fact} data-tone="purple">
                    <em>جدد</em>
                    <bdi dir="ltr">{count(m.value)}</bdi>
                    <small>في المدى</small>
                  </li>
                  <li className={styles.fact}>
                    <em>المدى السابق</em>
                    <bdi dir="ltr">{count(m.previous)}</bdi>
                    <small>{m.changePercent === null ? 'لا مقارنة' : percent(m.changePercent)}</small>
                  </li>
                  <li className={styles.fact}>
                    <em>الشرائح</em>
                    <Tag>في لوح العملاء</Tag>
                    <small>أدناه</small>
                  </li>
                </ul>
                <ul className={styles.factList}>
                  <li><span>عملاء جدد</span><bdi dir="ltr" data-tone="purple">{count(m.value)}</bdi></li>
                  <li><span>التغيّر مقابل السابق</span><bdi dir="ltr">{m.changePercent === null ? 'لا مقارنة' : percent(m.changePercent)}</bdi></li>
                  <li><span>العائدون · الخاملون · الضيوف</span><bdi dir="ltr"><Link className={styles.drill} data-tone="purple" href="#insight-customers">لوح العملاء ←</Link></bdi></li>
                </ul>
              </>
            );
          })()}
        </div>
      </Glass>
    ),
    payments: (
      <Glass tone="amber" band>
        <GlassHead
          title="ذكاء المدفوعات"
          note={!mayReadAudit ? 'المزوّد محجوب — audit.read' : system.data === undefined ? undefined : `${system.data.security.posture.paymentProvider.resolved} · ${system.data.security.posture.paymentProvider.live ? 'حيّ' : 'وهميّ'}`}
        />
        <div className={styles.glassBody}>
          {!mayReadOrders ? (
            <EmptyState icon="shield" title="لا صلاحية" body="عدّادات الدفع تحتاج orders.read." />
          ) : ops.isPending ? <Skeleton rows={2} columns={2} /> : ops.isError ? (
            <ErrorState message={ops.error.message} onRetry={refresh} />
          ) : (
            <>
              <div className={styles.payFacts}>
                {(['failedPayments', 'unmatchedWebhooks'] as const).map((key) => {
                  const c = ops.data.counters[key];
                  return (
                    <span key={key} className={styles.fact} data-tone={c !== undefined && c.state === 'MEASURED' && c.value > 0 ? (key === 'failedPayments' ? 'red' : 'orange') : undefined}>
                      <em>{c?.label ?? key}</em>
                      {c === undefined ? <Tag>غير موجود</Tag> : c.state === 'MEASURED' ? <bdi dir="ltr">{count(c.value)}</bdi> : <Tag>NOT CONFIGURED</Tag>}
                      <small>{key === 'failedPayments' ? 'الآن · webhook_deliveries' : 'مال وصل ولا يُعرف لمن'}</small>
                    </span>
                  );
                })}
              </div>
              <div className={styles.dashed}>
                <span><Tag>NOT MEASURED</Tag><strong>الأحداث الواصلة · نسبة النجاح · طرق الدفع · زمن التفويض</strong></span>
                <small>حمولة البوّابة لا تُحفظ فيها الطريقة ولا زمن التفويض، ولا عدّاد للواصل. تُقاس حين يُوسَّع <code dir="ltr">WebhookIntake</code>.</small>
              </div>
              <Link className={styles.drill} data-tone="amber" href="/payments/webhooks">الخطّافات ←</Link>
            </>
          )}
        </div>
      </Glass>
    ),
    security: (
      <Glass tone="violet" band>
        <GlassHead title="الأمان والوصول" note="من يملك المفاتيح، وما فُعل بها في آخر يوم" end={<Link className={styles.drill} data-tone="violet" href="/audit">سجلّ التدقيق ←</Link>} />
        <div className={styles.glassBody}>
          {!mayReadAudit ? (
            <EmptyState icon="shield" title="لا صلاحية" body="وضع الأمان محجوز لصلاحية audit.read." />
          ) : system.isPending ? <Skeleton rows={4} columns={2} /> : system.isError ? (
            <ErrorState message={system.error.message} onRetry={refresh} />
          ) : (
            <>
              <div className={styles.payFacts}>
                <span className={styles.fact} data-tone={system.data.security.admins.owners === system.data.security.admins.total ? 'orange' : undefined}>
                  <em>مالكون كاملو الصلاحية</em>
                  <bdi dir="ltr">{count(system.data.security.admins.owners)}</bdi>
                  <small>من {count(system.data.security.admins.total)} حساب لوحة</small>
                </span>
                <span className={styles.fact} data-tone={system.data.security.admins.neverSignedIn > 0 ? 'orange' : undefined}>
                  <em>حسابات لم تُستعمل قطّ</em>
                  <bdi dir="ltr">{count(system.data.security.admins.neverSignedIn)}</bdi>
                  <small>كلمة المرور الأوّليّة ما زالت صالحة</small>
                </span>
                <span className={styles.fact}>
                  <em>أفعال ذات امتياز (٢٤ س)</em>
                  <bdi dir="ltr">{count(system.data.security.privilegedActions24h.total)}</bdi>
                  <small>استرداد · سحب · تغيير دور · تدوير</small>
                </span>
                <span className={styles.fact} data-tone={system.data.security.posture.paymentProvider.live ? 'emerald' : 'red'}>
                  <em>بوّابة الدفع</em>
                  <bdi dir="ltr">{system.data.security.posture.paymentProvider.resolved}</bdi>
                  <small>{system.data.security.posture.paymentProvider.live ? 'سائق حيّ — المال يصل' : 'سائق وهميّ — لا مال يُحصَّل'}</small>
                </span>
              </div>
              <div className={styles.dashed}>
                <span><Tag>NOT CONFIGURED</Tag><strong>محاولات الدخول الفاشلة</strong></span>
                <small>{system.data.security.failedLogins.reason}</small>
              </div>
            </>
          )}
        </div>
      </Glass>
    ),
    unmeasured: (
    <div className={styles.unmeasured}>
      <Tag>NOT MEASURED</Tag>
      <span>
        {unmeasured.length === 0 ? 'كلّ ما تعلنه المصادر مقيس.' : `${count(unmeasured.length)} أرقامٍ لا مصدر لها، فلا تُعرض صفرًا ولا تُخترع:`}
      </span>
      {/* السبب نصٌّ مقروء لا تلميحَ تحويم: قارئ الشاشة واللمس لا يريان `title`. */}
      {unmeasured.map((u) => (
        <span key={u.key} className={styles.unmeasuredItem}>
          <strong>{u.label}</strong>
          {u.reason ? <span className={styles.unmeasuredReason}> — {u.reason}</span> : null}
        </span>
      ))}
    </div>
    ),
    domains: !mayReadAnalytics ? (
      <div className={styles.gated}><ErrorState code={403} message="تحليل النطاقات يحتاج صلاحية analytics.read." /></div>
    ) : (
    <section className={styles.domains}>
      <header className={styles.domainsHead}>
        <h2>تحليل النطاقات</h2>
        <p>افتح نطاقًا ليُجلب. كلّ رقمٍ يقول صيغته، وما لا مصدر له يُعلَن باسمه.</p>
      </header>
      {/*
        * السجلّ من الخادم لا من نسخةٍ هنا. وحين لا يُجاب: لا ألواح —
        * وقائمةٌ محفوظةٌ احتياطًا كانت ستعرض نطاقًا أُلغي أو تُخفي نطاقًا
        * أُضيف، وكلاهما يُقرأ حقيقةً وهو ليس كذلك.
        */}
      {insightDomains.isError ? (
        <p className="state-line" role="alert">
          تعذّر جلب سجلّ النطاقات: {insightDomains.error.message}
        </p>
      ) : (
        (insightDomains.data?.domains ?? []).map((item) => (
          <div key={item.key} id={`insight-${item.key}`}>
            <InsightPanel
              domain={item.key}
              title={item.label}
              description={item.description}
              from={range.from}
              to={range.to}
            />
          </div>
        ))
      )}
      <p className={styles.domainsElsewhere}>
        البحث والظهور يُقاسان في شاشتيهما: <Link href="/search">ذكاء البحث</Link> و<Link href="/seo">صحّة الظهور</Link>.
      </p>
    </section>
    ),
  };
  for (const [id, state] of Object.entries(layout.doc.widgets)) {
    if (typeOf(id, state) === 'kpi') widgetContent[id] = kpiWidget(id, state);
  }

  /* ───── لوحة العمليّات / التنفيذيّ ───── */
  return (
    <>
      <PageHeader trail="لوحة التشغيل" title="مركز القيادة" />

      <div className={styles.page} data-theme={theme} dir="rtl">
        <div className={styles.mesh} data-fx="ambient" aria-hidden="true" />
        <span className={styles.ambient} data-fx="ambient" aria-hidden="true" />

        <header className={styles.bar}>
          <div className={styles.barRow}>
            <div className={styles.crumbs}>
              <small>لوحة التشغيل › الرئيسيّة</small>
              <h1 className={styles.title}>
                مركز القيادة — {DASHBOARD_PRESETS.find((p) => p.id === layout.doc.preset)?.label ?? 'مخصّص'}
                <span className={styles.live}>
                  <span className={styles.pulse} aria-hidden="true" />
                  مباشر
                </span>
              </h1>
            </div>
            <div className={styles.segmented} role="group" aria-label="طريقة العرض">
              {VIEWS.map((v) => (
                <button
                  key={v.id}
                  type="button"
                  className={styles.segment}
                  aria-pressed={view === v.id}
                  onClick={() => setParam('view', v.id === 'ops' ? null : v.id)}
                >
                  {v.label}
                </button>
              ))}
            </div>
            <div className={styles.barEnd}>
              {/*
                الخيارات من `ADMIN_THEMES` لا مكتوبةً هنا: كان هذا الشريط
                يسرد خيارين بينما يسرد شريطُ الحساب ما في السجلّ، فإضافة
                «النظام» كانت تظهر في أحدهما دون الآخر.
              */}
              <div className={styles.segmented} role="group" aria-label="السمة">
                {ADMIN_THEMES.map((option) => (
                  <button
                    key={option.id}
                    type="button"
                    className={styles.segment}
                    aria-pressed={theme === option.id}
                    onClick={() => setTheme(option.id)}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
              <button type="button" className={styles.iconBtn} aria-label="تحديث الآن" onClick={refresh}>
                <Icon name="refresh" size={16} />
              </button>
            </div>
          </div>

          <div className={`${styles.barRow} ${styles.range}`}>
            <DateRangePicker
              range={range}
              activePreset={activePreset}
              onChange={setRange}
              onRefresh={refresh}
              generatedAt={summary.data?.generatedAt ?? null}
            />
            {summary.data === undefined ? null : (
              <>
                <span className={styles.divider} aria-hidden="true" />
                <span className={styles.compare}>
                  مقارنةً بـ <b><bdi dir="ltr">{dayMonth(summary.data.range.previousFrom)} – {dayMonth(summary.data.range.previousTo)}</bdi></b>
                </span>
                <span className={styles.stamp}>
                  <Icon name="refresh" size={13} />
                  آخر قراءة <b><bdi dir="ltr">{clock(summary.data.generatedAt)}</bdi></b> · تُحدَّث كلّ ٦٠ ث
                </span>
              </>
            )}
          </div>
        </header>

        <div className={styles.body}>
          <EditToolbar
            layout={layout}
            editing={editing}
            onEditingChange={setEditing}
            editable={editable}
            titleOf={titleOf}
          />
          <Workspace
            layout={layout}
            editing={editing}
            content={widgetContent}
            titleOf={titleOf}
            actionsOf={actionsOf}
            onEditableChange={setEditable}
          />
        </div>
      </div>
    </>
  );
}
