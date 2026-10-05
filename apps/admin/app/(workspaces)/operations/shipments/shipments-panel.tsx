'use client';
// UNIT: SHIPMENTS-PANEL
// FILE: shipments-panel.tsx
// STYLE: shipments.module.css
// PARENT: PAGE-OPERATIONS-FULFILLMENT
// USES: ADMIN-SESSION, DATA-TABLE, FILTER-BAR, LIB-ADMIN-API, LIB-CONTRACT, LIB-FORMAT +3
// API: /admin/operations/shipments
// MAP: docs/PROJECT_MAP.md

/**
 * الشحنات — ما خرج وما لم يخرج بعد، على جدول القوائم المشترك.
 *
 * رابط التتبّع مبنيٌّ من قالب الناقل، ويغيب حين لا رقم تتبّع. الرابط
 * المُلفَّق يقود الزبون إلى صفحة خطأ عند الناقل، وهو أسوأ من غيابه.
 *
 * و«نُفّذت» على وحدة التنفيذ تعني «سُلّمت إلى مسار الشحن» لا «وصلت»:
 * الوصول تتبعه حالة الشحنة نفسها. السؤالان مختلفان — الأوّل مسؤوليّتنا
 * والثاني مسؤوليّة الناقل — ولذلك يُعرضان منفصلين.
 *
 * ─── ما أُصلح في الترحيل ───
 *
 * كانت القائمة `limit(200)` بلا إجماليّ وبلا تصفّح: تُعرض مئتا صفٍّ ولا شيء
 * يقول إنّ خلفها المزيد. فيُقرأ الجدول على أنّه كلّ الشحنات، ويبحث المشغّل
 * عن شحنةٍ موجودة فلا يجدها ويستنتج أنّها لم تُنشأ — استنتاجٌ خاطئ يقود إلى
 * إنشاءٍ مكرّر. صارت مُصفَّحة بإجماليٍّ معلَن.
 *
 * ─── ولماذا صار للجدول عمود نقل ───
 *
 * الشحنة تتقدّم بحدثٍ من الناقل أو بيدٍ بشريّة، وما لا يصل من الناقل يبقى
 * معلّقًا إلى الأبد: كانت الشاشة قراءةً محضة، فلا سبيل لإنهاء شحنةٍ سُلّمت
 * فعلًا ولا لإلغاء أخرى لن تخرج. والقائمة هي الموضع الذي يُعرف فيه من يحتاج
 * تحريكًا، فالنقل حيث تُقرأ الحالة لا في صفحةٍ ثانية تُفتح صفًّا صفًّا.
 *
 * والعمود لا يُرسم إلّا لمن يملك `operations.manage` — وهي صلاحية المسار على
 * الخادم: عمودٌ يظهر ثمّ يردّ الخادمُ فعلَه بـ403 يعلّم المشغّل ألّا يثق بما
 * تعرضه الشاشة، وهو ضررٌ أبقى من غياب العمود.
 */

import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useMemo } from 'react';
import { TransitionForm } from '../_shared/ops';
import { useSession } from '../../../../components/admin-session';
import { DataTable, type Column } from '../../../../components/ui/data-table';
import { FilterBar } from '../../../../components/ui/filter-bar';
import { StatusBadge, type StatusTone } from '../../../../components/ui/status-badge';
import { useListState } from '../../../../lib/list-state';
import { proxyPath } from '../../../../lib/contract';
import { api, type Paginated } from '../../../../lib/admin-api';
import { count, dateTime, money } from '../../../../lib/format';
import styles from './shipments.module.css';

interface ShipmentRow {
  id: number;
  orderNumber: string | null;
  carrier: string | null;
  service: string | null;
  status: string;
  trackingNumber: string | null;
  trackingUrl: string | null;
  costMinor: number | null;
  weightGrams: number | null;
  shippedAt: string | null;
  deliveredAt: string | null;
  /* ما يجوز من هنا — يبثّه الخادم مع كلّ صفّ، ولا تشتقّه الواجهة. */
  allowedNext: string[];
}

const LABELS: Record<string, string> = {
  PENDING: 'بانتظار',
  LABEL_CREATED: 'أُنشئت البوليصة',
  PICKED_UP: 'استلمها الناقل',
  IN_TRANSIT: 'في الطريق',
  OUT_FOR_DELIVERY: 'خرجت للتسليم',
  DELIVERED: 'سُلّمت',
  FAILED: 'فشلت',
  RETURNED: 'مرتجعة',
  CANCELLED: 'أُلغيت',
};

/* النبرة تُشتقّ من الحالة مرّةً — لا تتكرّر في كلّ خليّة. */
function tone(status: string): StatusTone {
  if (status === 'FAILED' || status === 'RETURNED') return 'danger';
  if (status === 'DELIVERED') return 'success';
  if (status === 'CANCELLED') return 'neutral';

  return 'warning';
}

const FILTER_KEYS = ['status'] as const;

const COLUMNS: readonly Column<ShipmentRow>[] = [
  {
    key: 'order',
    header: 'الطلب',
    cell: (row) => (row.orderNumber === null ? <span className="absent">—</span> : <bdi dir="ltr">{row.orderNumber}</bdi>),
  },
  {
    key: 'carrier',
    header: 'الناقل',
    cell: (row) => (
      <>
        {row.carrier ?? '—'}
        {row.service === null ? null : <div className={styles.sub}>{row.service}</div>}
      </>
    ),
  },
  {
    key: 'status',
    header: 'الحالة',
    sortKey: 'status',
    cell: (row) => <StatusBadge label={LABELS[row.status] ?? row.status} tone={tone(row.status)} />,
  },
  {
    key: 'tracking',
    header: 'التتبّع',
    /* رابطٌ مبنيّ من قالب الناقل، أو لا شيء — لا رابط مُلفَّق. */
    cell: (row) =>
      row.trackingUrl !== null ? (
        <a href={row.trackingUrl} target="_blank" rel="noreferrer">
          <bdi dir="ltr">{row.trackingNumber}</bdi>
        </a>
      ) : row.trackingNumber !== null ? (
        <bdi dir="ltr">{row.trackingNumber}</bdi>
      ) : (
        <span className="absent">—</span>
      ),
  },
  {
    key: 'cost',
    header: 'الكلفة',
    sortKey: 'cost',
    align: 'end',
    cell: (row) => (row.costMinor === null ? <span className="absent">—</span> : <bdi dir="ltr">{money(row.costMinor)}</bdi>),
  },
  {
    key: 'weight',
    header: 'الوزن',
    sortKey: 'weight',
    align: 'end',
    optional: true,
    cell: (row) => (row.weightGrams === null ? <span className="absent">—</span> : <bdi dir="ltr">{`${row.weightGrams} غ`}</bdi>),
  },
  {
    key: 'shippedAt',
    header: 'خرجت',
    sortKey: 'shippedAt',
    optional: true,
    cell: (row) => (row.shippedAt === null ? <span className="absent">—</span> : <bdi>{dateTime(row.shippedAt)}</bdi>),
  },
  {
    key: 'deliveredAt',
    header: 'سُلّمت',
    sortKey: 'deliveredAt',
    cell: (row) => (row.deliveredAt === null ? <span className="absent">—</span> : <bdi>{dateTime(row.deliveredAt)}</bdi>),
  },
];

/**
 * عمود النقل — عمودٌ مستقلّ في آخر الجدول.
 *
 * ليس `optional`: أعمدة `optional` مخفيّةٌ حتّى يُظهرها المشغّل من لوحة
 * الأعمدة، وعمودٌ مخفيّ هو غيابُ السطح نفسه الذي أُضيف العمود لسدّه.
 *
 * وحقل رقم التتبّع معروضٌ غير مُلزَم: الرقم يصل لحظةَ إنشاء البوليصة أو
 * استلام الناقل لا قبلهما، وإلزامُه يمنع «أُلغيت» و«فشلت» — وهما انتقالان
 * لا رقم لهما أصلًا. والفراغ لا يُرسَل فلا يمحو رقمًا قائمًا.
 *
 * وصفٌّ بلا انتقالٍ مسموح يُعلَّم غيابًا كبقيّة الخلايا الفارغة: خليّةٌ
 * بيضاء تُقرأ عطلًا في التصيير، والشرطة تقول «لا شيء هنا». ولا تُعدَّد
 * النهايات هنا — تعدادُها نسخةٌ ثانية من خريطةٍ تعيش في النطاق، تتباعد عند
 * أوّل تعديل ويبحث القارئ عن زرٍّ غائبٍ بحقّ.
 */
function transitionColumn(reload: () => void): Column<ShipmentRow> {
  return {
    key: 'transition',
    header: 'نقل الحالة',
    cell: (row) =>
      row.allowedNext.length === 0 ? (
        <span className="absent">—</span>
      ) : (
        <div className={styles.transitionCell}>
          <TransitionForm
            path={proxyPath('adminOperationsActionUpdateShipment', { id: row.id })}
            options={row.allowedNext}
            labels={LABELS}
            label="نقل الحالة"
            subject={row.orderNumber ?? `شحنة ${row.id}`}
            text={{ name: 'trackingNumber', placeholder: 'رقم التتبّع', maxLength: 120 }}
            onDone={reload}
          />
        </div>
      ),
  };
}

const STATUS_TABS = Object.entries(LABELS).map(([id, label]) => ({ id, label }));

export function ShipmentsPanel() {
  const { can } = useSession();
  const queryClient = useQueryClient();
  const list = useListState(FILTER_KEYS);
  const path = `/admin/operations/shipments?${list.queryString}`;

  /* مفتاحٌ واحد يقرأ به الاستعلام ويُبطله النقل — مفتاحان يفترقان يتركان الجدول على حالةٍ سابقة. */
  const queryKey = useMemo(() => ['admin-resource', path] as const, [path]);

  const query = useQuery<Paginated<ShipmentRow>>({
    queryKey,
    queryFn: async () => {
      const result = await api.get<Paginated<ShipmentRow>>(path);
      if (!result.ok) {
        const error = new Error(result.message) as Error & { code?: number };
        error.code = result.status;
        throw error;
      }
      return result.data;
    },
  });

  const page = query.data;
  const errorCode = (query.error as (Error & { code?: number }) | null)?.code;

  /*
   * بعد النقل يُبطَل المفتاح لا الصفّ وحده: الانتقال قد يُخرج الشحنة من
   * التصفية القائمة («بانتظار» بعد نقلها إلى «في الطريق»)، فتحديثُ الخليّة
   * مكانها يُبقي صفًّا لم يعد من القائمة.
   */
  const reload = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey });
  }, [queryClient, queryKey]);

  const canManage = can('operations.manage');
  const columns = useMemo(
    () => (canManage ? [...COLUMNS, transitionColumn(reload)] : COLUMNS),
    [canManage, reload],
  );

  return (
    <section className={styles.page}>

      <FilterBar
        searchPlaceholder="ابحث برقم التتبّع أو رقم الطلب"
        draftQuery={list.draftQuery}
        onQuery={list.setQuery}
        tabs={STATUS_TABS}
        activeTab={list.state.filters.status ?? null}
        onTab={(id) => list.setFilter('status', id)}
        onClear={list.clearFilters}
        hasFilters={list.state.q !== '' || Object.keys(list.state.filters).length > 0}
      />

      {page === undefined ? null : (
        <p className="page-note">
          <bdi dir="ltr">{count(page.meta.total)}</bdi> شحنةً مطابقةً على الخادم.
        </p>
      )}

      <DataTable
        caption="قائمة الشحنات"
        columns={columns}
        rows={page?.data ?? []}
        rowKey={(row) => row.id}
        loading={query.isPending}
        error={
          query.isError
            ? {
                message:
                  errorCode === 403
                    ? 'لا تملك صلاحية قراءة الطلبات، والشحنات تُقرأ بها.'
                    : query.error.message,
                code: errorCode,
              }
            : undefined
        }
        onRetry={() => void query.refetch()}
        empty={{
          title: 'لا شحنة مطابقة',
          body: 'لا شحنة تطابق البحث والحالة — أو لا طلب ماديّ نُفّذ بعد.',
        }}
        sort={list.state.sort}
        direction={list.state.direction}
        onSort={list.setSort}
        pagination={
          page === undefined
            ? undefined
            : {
                page: list.state.page,
                perPage: list.state.perPage,
                total: page.meta.total,
                onPage: list.setPage,
              }
        }
      />
    </section>
  );
}
