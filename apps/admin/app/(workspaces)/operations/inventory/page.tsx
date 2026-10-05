'use client';
// UNIT: PAGE-OPERATIONS-INVENTORY
// FILE: page.tsx
// ROUTE: /operations/inventory
// STYLE: inventory.module.css
// PARENT: none
// USES: DATA-TABLE, FILTER-BAR, LIB-ADMIN-API, LIB-FORMAT, LIB-LIST-STATE, MOVEMENT-FORM +3
// API: /admin/operations/inventory
// MAP: docs/PROJECT_MAP.md

/**
 * المخزون — ما يقترب من النفاد أوّلًا، على جدول القوائم المشترك.
 *
 * الترتيب الافتراضيّ بالمتاح تصاعديًّا لا بالاسم: القائمة تُفتح لتقرّر إعادة
 * طلبٍ، والمرتّبة أبجديًّا تُخفي المنتج الذي نفد في منتصف الصفحة الثالثة.
 *
 * ومخزون موقعٍ غير معروضٍ للبيع يُميَّز صراحةً (§٣٠): بضاعةُ المورّد موجودةٌ
 * ولا تُباع، وعدُّها متاحةً يَعِد بما لا يُشحن.
 *
 * ─── عيبان أُصلحا في الترحيل ───
 *
 * ١ — كانت القائمة `limit(300)` بلا إجماليّ وبلا تصفّح: ثلاثمئة صفٍّ تُعرض
 *     ولا شيء يقول إنّ خلفها المزيد.
 *
 * ٢ — «يحتاج إعادة طلب» كان يُحسب في الواجهة من الصفوف المحمّلة، فيقول «٣»
 *     وفي المخزن ثلاثون. وهو الرقم الذي يُبنى عليه أمر شراء — فصار يُحسب
 *     على المجموعة كلّها في الخادم.
 */

import { useQuery } from '@tanstack/react-query';
import { CountBar } from '../_shared/ops';
import { MovementForm } from './movement-form';
import { PageHeader } from '../../../../components/resource';
import { DataTable, type Column } from '../../../../components/ui/data-table';
import { FilterBar } from '../../../../components/ui/filter-bar';
import { StatusBadge } from '../../../../components/ui/status-badge';
import { useListState } from '../../../../lib/list-state';
import { api, type Paginated } from '../../../../lib/admin-api';
import { count, dateTime } from '../../../../lib/format';
import styles from './inventory.module.css';

interface StockRow {
  id: number;
  variantId: number;
  locationId: number;
  sku: string | null;
  product: string | null;
  location: string | null;
  sellableLocation: boolean;
  onHand: number;
  reserved: number;
  available: number;
  reorderPoint: number | null;
  needsReorder: boolean;
  countedAt: string | null;
}

interface InventoryView extends Paginated<StockRow> {
  needsReorder: number;
  heldReservations: number;
  expiredHolds: number;
}

const FILTER_KEYS = ['lowOnly'] as const;

const COLUMNS: readonly Column<StockRow>[] = [
  {
    key: 'sku',
    header: 'الرمز',
    cell: (row) => (row.sku === null ? <span className="absent">—</span> : <bdi dir="ltr">{row.sku}</bdi>),
  },
  {
    key: 'product',
    header: 'المنتج',
    cell: (row) => row.product ?? <span className="absent">—</span>,
  },
  {
    key: 'location',
    header: 'الموقع',
    cell: (row) => (
      <>
        {row.location ?? '—'}
        {/* موقعٌ غير معروضٍ للبيع: بضاعتُه موجودة ولا تُباع (§٣٠). */}
        {row.sellableLocation ? null : <div className={styles.sub}>غير معروض للبيع</div>}
      </>
    ),
  },
  {
    key: 'available',
    header: 'المتاح',
    sortKey: 'available',
    align: 'end',
    cell: (row) => (
      <StatusBadge
        label={String(row.available)}
        tone={row.needsReorder ? 'danger' : 'success'}
      />
    ),
  },
  {
    key: 'reserved',
    header: 'المحجوز',
    sortKey: 'reserved',
    align: 'end',
    cell: (row) => <bdi dir="ltr">{count(row.reserved)}</bdi>,
  },
  {
    key: 'onHand',
    header: 'الموجود',
    sortKey: 'onHand',
    align: 'end',
    cell: (row) => <bdi dir="ltr">{count(row.onHand)}</bdi>,
  },
  {
    key: 'reorderPoint',
    header: 'حدّ الطلب',
    sortKey: 'reorderPoint',
    align: 'end',
    cell: (row) =>
      row.reorderPoint === null ? <span className="absent">—</span> : <bdi dir="ltr">{count(row.reorderPoint)}</bdi>,
  },
  {
    key: 'countedAt',
    header: 'آخر جرد',
    sortKey: 'countedAt',
    optional: true,
    cell: (row) => (row.countedAt === null ? <span className="absent">لم يُجرد</span> : <bdi>{dateTime(row.countedAt)}</bdi>),
  },
];

export default function InventoryPage() {
  const list = useListState(FILTER_KEYS);
  const path = `/admin/operations/inventory?${list.queryString}`;

  const query = useQuery<InventoryView>({
    queryKey: ['admin-resource', path],
    queryFn: async () => {
      const result = await api.get<InventoryView>(path);
      if (!result.ok) {
        const error = new Error(result.message) as Error & { code?: number };
        error.code = result.status;
        throw error;
      }
      return result.data;
    },
  });

  const view = query.data;
  const errorCode = (query.error as (Error & { code?: number }) | null)?.code;

  return (
    <section className={styles.page}>
      <PageHeader trail="العمليّات" title="المخزون" />

      {/*
        النموذج فوق الجدول لا تحته: الصفحة تُفتح لتصحيح جردٍ خاطئ، وإخفاءُ
        الفعل أسفل ثلاثمئة صفٍّ يجعله غير موجودٍ عمليًّا.

        وأهدافه من **الصفحة المعروضة** وحدها: القائمة صارت مُصفَّحة، ودفعُ
        كلّ المخزون إلى قائمةٍ منسدلة يجعلها بآلاف الخيارات لا تُقرأ. ومن
        يريد صفًّا آخر يبحث عنه ثمّ يصحّحه.
      */}
      <MovementForm
        targets={(view?.data ?? []).map((row) => ({
          variantId: row.variantId,
          locationId: row.locationId,
          sku: row.sku,
          product: row.product,
          location: row.location,
          available: row.available,
        }))}
        onDone={() => void query.refetch()}
      />

      {view === undefined ? null : (
        <CountBar
          items={[
            /* المجموعة كلّها لا الصفحة — الرقم يُبنى عليه أمر شراء. */
            { label: 'يحتاج إعادة طلب', value: view.needsReorder, tone: 'danger' },
            { label: 'حجوزات قائمة', value: view.heldReservations, tone: 'wait' },
            { label: 'حجوزات منتهية', value: view.expiredHolds, tone: view.expiredHolds > 0 ? 'danger' : 'done' },
            { label: 'صفوف مخزون', value: view.meta.total },
          ]}
        />
      )}

      <FilterBar
        searchPlaceholder="ابحث برمز المتغيّر أو اسم المنتج"
        draftQuery={list.draftQuery}
        onQuery={list.setQuery}
        tabs={[{ id: '1', label: 'ما يحتاج إعادة طلب' }]}
        activeTab={list.state.filters.lowOnly ?? null}
        onTab={(id) => list.setFilter('lowOnly', id)}
        onClear={list.clearFilters}
        hasFilters={list.state.q !== '' || Object.keys(list.state.filters).length > 0}
      />

      <DataTable
        caption="مستويات المخزون"
        /*
         * مفتاح حفظ الأعمدة — ثابتٌ لا مشتقٌّ من العنوان.
         *
         * الجدول يسقط إلى `caption` حين يغيب، وتغييرُ كلمةٍ في العنوان
         * كان سينسي المشغّلَ أعمدتَه بلا سببٍ يراه. والمفتاح هنا اسم
         * الشاشة نفسه، فلا يتبدّل بتحرير نصّ.
         */
        storageKey="operations-inventory"
        columns={COLUMNS}
        rows={view?.data ?? []}
        rowKey={(row) => row.id}
        loading={query.isPending}
        error={
          query.isError
            ? {
                message:
                  errorCode === 403
                    ? 'لا تملك صلاحية قراءة المنتجات، والمخزون يُقرأ بها.'
                    : query.error.message,
                code: errorCode,
              }
            : undefined
        }
        onRetry={() => void query.refetch()}
        empty={{
          title: 'لا مخزون مطابق',
          body: 'لا صفّ يطابق البحث والمرشّح — أو لا منتج متعقَّب بعد.',
        }}
        sort={list.state.sort}
        direction={list.state.direction}
        onSort={list.setSort}
        pagination={
          view === undefined
            ? undefined
            : {
                page: list.state.page,
                perPage: list.state.perPage,
                total: view.meta.total,
                onPage: list.setPage,
              }
        }
      />
    </section>
  );
}
