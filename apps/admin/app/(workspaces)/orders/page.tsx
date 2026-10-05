'use client';
// UNIT: PAGE-ORDERS
// FILE: page.tsx
// ROUTE: /orders
// STYLE: orders.module.css
// PARENT: none
// USES: DATA-TABLE, EXPORT-CSV, FILTER-BAR, LIB-ADMIN-API, LIB-EXPORT-CSV, LIB-FORMAT +3
// API: /admin/orders
// MAP: docs/PROJECT_MAP.md

/**
 * قائمة الطلبات — على مكوّنات القوائم المشتركة.
 *
 * ─── ما تغيّر ───
 *
 * كانت المرشّحات تُطبَّق بزرّ «تطبيق» وتعيش في حالةٍ محلّيّة: رابطٌ يُشارَك
 * لا يحمل المرشّح، ورجوعُ المتصفّح لا يعيده. وصارت في الرابط، والبحث مؤجَّلًا
 * بلا زرّ، والفرز والتصفّح في **الخادم** — جدولٌ يفرز صفحته وحدها يقول
 * «الأعلى» وهو أعلى عشرين صفًّا لا أعلى الجميع.
 *
 * ─── وما بقي كما هو ───
 *
 * صدقُ نطاق المؤشّرات: الفهرس يُعيد `meta.total` ولا يُعيد مجاميع، فما
 * يُحسب من صفوف الصفحة يُسمّى «هذه الصفحة» صراحةً. رقمُ صفحةٍ يُقرأ رقمًا
 * للمتجر أسوأ من لا رقم.
 */

import Link from 'next/link';
import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '../../../components/resource';
import { ExportCsv } from '../../../components/export-csv';
import { DataTable, type Column } from '../../../components/ui/data-table';
import { FilterBar } from '../../../components/ui/filter-bar';
import { StatusBadge, orderTone } from '../../../components/ui/status-badge';
import { useListState } from '../../../lib/list-state';
import { api } from '../../../lib/admin-api';
import type { CsvColumn } from '../../../lib/export-csv';
import type { OrderRow, Paginated } from '../../../lib/admin-api';
import { count, dateTime, minorToRiyals, money, ORDER_STATUS_LABELS } from '../../../lib/format';
import styles from './orders.module.css';

/*
 * أعمدة التصدير — التواريخ بصيغة ISO لا بالعربية المنسّقة.
 *
 * الجداول الحسابية ترتّب ISO ترتيبًا صحيحًا وتفهمه تاريخًا، بينما «٤ سبتمبر
 * ٢٠٢٦، ٣:٤٥ م» نصٌّ يُرتَّب أبجديًّا فيختلط الشهر بالشهر. والمبالغ بالريال
 * كسريًّا لا بالهللة: من يفتح الملفّ يجمع عمودًا، ولا يقسم على مئة أوّلًا.
 */
const ORDER_CSV: readonly CsvColumn<OrderRow>[] = [
  { header: 'رقم الطلب', value: (o) => o.number },
  { header: 'الحالة', value: (o) => ORDER_STATUS_LABELS[o.status] ?? o.status },
  { header: 'البريد', value: (o) => o.email },
  { header: 'الإجمالي (ر.س)', value: (o) => minorToRiyals(o.totalMinor) },
  { header: 'الضريبة (ر.س)', value: (o) => minorToRiyals(o.vatMinor) },
  { header: 'أُنشئ (ISO)', value: (o) => o.createdAt },
  { header: 'دُفع (ISO)', value: (o) => o.paidAt },
];

interface OrdersPayload extends Paginated<OrderRow> {
  statusCounts?: Record<string, number>;
}

/** المرشّحات المعلَنة — ما لا يُذكر هنا لا يُقرأ من الرابط. */
const FILTER_KEYS = ['status', 'view', 'from', 'to'] as const;

/**
 * العدسات التي تهبط عليها بطاقات لوحة التشغيل — بأسمائها كما يعرفها الخادم.
 *
 * الشرح ليس زينة: المشغّل يضغط «صافي المبيعات» فيجد جدولًا مُقتطعًا بلا
 * عنوان يقول لماذا. والعدد الذي يراه يجب أن يُنسب إلى سؤالٍ مذكور، وإلّا
 * ظنّه كلّ الطلبات.
 */
const VIEW_LABELS: Record<string, string> = {
  paid: 'الطلبات التي وصل مالها',
  needsAction: 'الطلبات التي تنتظر معالجة',
  refunded: 'الطلبات التي استُردّ منها شيء',
  undelivered: 'طلبات مدفوعة لم تُنشأ لها صلاحية وصول',
  unfulfilled: 'طلبات مدفوعة بلا وحدة تنفيذ',
};

const COLUMNS: readonly Column<OrderRow>[] = [
  {
    key: 'number',
    header: 'رقم الطلب',
    sortKey: 'number',
    cell: (order) => (
      <Link href={`/orders/${order.id}`}>
        <bdi dir="ltr">{order.number}</bdi>
      </Link>
    ),
  },
  {
    key: 'email',
    header: 'البريد',
    cell: (order) => <bdi dir="ltr">{order.email}</bdi>,
  },
  {
    key: 'status',
    header: 'الحالة',
    sortKey: 'status',
    cell: (order) => (
      <StatusBadge
        label={ORDER_STATUS_LABELS[order.status] ?? order.status}
        tone={orderTone(order.status)}
      />
    ),
  },
  {
    key: 'total',
    header: 'الإجمالي',
    sortKey: 'total',
    align: 'end',
    cell: (order) => (
      <>
        <bdi dir="ltr">{money(order.totalMinor)}</bdi> ر.س
      </>
    ),
  },
  {
    key: 'vat',
    header: 'الضريبة',
    align: 'end',
    // عمودٌ اختياريّ: يُجلب أصلًا ولا يُعرض افتراضيًّا كي يبقى الصفّ مقروءًا.
    optional: true,
    cell: (order) => (
      <>
        <bdi dir="ltr">{money(order.vatMinor)}</bdi> ر.س
      </>
    ),
  },
  {
    key: 'createdAt',
    header: 'تاريخ الإنشاء',
    sortKey: 'createdAt',
    cell: (order) => <bdi>{dateTime(order.createdAt)}</bdi>,
  },
  {
    key: 'paidAt',
    header: 'تاريخ الدفع',
    sortKey: 'paidAt',
    cell: (order) =>
      order.paidAt === null ? (
        <span className="absent">لم يُدفع</span>
      ) : (
        <bdi>{dateTime(order.paidAt)}</bdi>
      ),
  },
];

export default function OrdersPage() {
  const list = useListState(FILTER_KEYS);

  const path = `/admin/orders?${list.queryString}`;

  const query = useQuery<OrdersPayload>({
    queryKey: ['admin-resource', path],
    queryFn: async () => {
      const result = await api.get<OrdersPayload>(path);
      if (!result.ok) {
        const error = new Error(result.message) as Error & { code?: number };
        error.code = result.status;
        throw error;
      }
      return result.data;
    },
  });

  const page = query.data;

  /*
   * تُحسب من الصفوف المحمّلة لا من الخادم — ولذلك تُسمّى «هذه الصفحة».
   * الفهرس لا يُعيد مجاميع، وادّعاء مجموع المتجر من صفحةٍ واحدة كذب يزداد
   * سوءًا كلّما كبر الكتالوج.
   */
  const stats = useMemo(() => {
    const rows = page?.data ?? [];
    return {
      paid: rows.filter((o) => o.paidAt !== null).length,
      totalMinor: rows.reduce((sum, o) => sum + o.totalMinor, 0),
      vatMinor: rows.reduce((sum, o) => sum + o.vatMinor, 0),
      shown: rows.length,
    };
  }, [page]);

  const tabs = useMemo(
    () =>
      (page?.filters?.statuses ?? []).map((status) => ({
        id: status,
        label: ORDER_STATUS_LABELS[status] ?? status,
        total: page?.statusCounts?.[status],
      })),
    [page],
  );

  const errorCode = (query.error as (Error & { code?: number }) | null)?.code;

  return (
    <section className={styles.page}>
      <PageHeader trail="الطلبات" title="الطلبات" />

      <FilterBar
        searchPlaceholder="ابحث برقم الطلب أو البريد"
        draftQuery={list.draftQuery}
        onQuery={list.setQuery}
        tabs={tabs}
        activeTab={list.state.filters.status ?? null}
        onTab={(id) => list.setFilter('status', id)}
        onClear={list.clearFilters}
        hasFilters={list.state.q !== '' || Object.keys(list.state.filters).length > 0}
        actions={
          page === undefined ? null : (
            <ExportCsv
              filename="orders"
              columns={ORDER_CSV}
              /* يُصدَّر المرشَّح لا الجدول كلّه: `path` هو نفسه مسار الشاشة. */
              source={{ kind: 'paged', path, total: page.meta.total }}
            />
          )
        }
      />

      {/*
        * العدسة تُعلَن فوق الجدول — لا تُترك تقصّ الصفوف بصمت.
        */}
      {list.state.filters.view === undefined &&
      list.state.filters.from === undefined &&
      list.state.filters.to === undefined ? null : (
        <p className={styles.lens} role="status">
          <strong>
            {VIEW_LABELS[list.state.filters.view ?? ''] ?? 'كلّ الطلبات'}
          </strong>
          {list.state.filters.from === undefined && list.state.filters.to === undefined ? null : (
            <span>
              {' '}
              من <bdi dir="ltr">{list.state.filters.from ?? '—'}</bdi> إلى{' '}
              <bdi dir="ltr">{list.state.filters.to ?? '—'}</bdi>
            </span>
          )}
          <button type="button" className="btn btn-ghost" onClick={list.clearFilters}>
            إزالة العدسة
          </button>
        </p>
      )}

      {/*
        * مؤشّرات صادقة النطاق: كلٌّ يقول مداه صراحةً.
        */}
      <dl className={styles.stats}>
        <div className={styles.stat}>
          <dt>المطابق للمرشِّح</dt>
          <dd>
            <bdi dir="ltr">{count(page?.meta.total ?? null)}</bdi>
            <span className={styles.statSub}> طلبًا على الخادم</span>
          </dd>
        </div>
        <div className={styles.stat}>
          <dt>مدفوعة في هذه الصفحة</dt>
          <dd>
            <bdi dir="ltr">{count(stats.paid)}</bdi>
            <span className={styles.statSub}> من {count(stats.shown)}</span>
          </dd>
        </div>
        <div className={styles.stat}>
          <dt>مجموع هذه الصفحة</dt>
          <dd>
            <bdi dir="ltr">{money(stats.totalMinor)}</bdi>
            <span className={styles.statSub}> ر.س</span>
          </dd>
        </div>
        <div className={styles.stat}>
          <dt>ضريبة هذه الصفحة</dt>
          <dd>
            <bdi dir="ltr">{money(stats.vatMinor)}</bdi>
            <span className={styles.statSub}> ر.س</span>
          </dd>
        </div>
      </dl>

      <DataTable
        caption="قائمة الطلبات"
        /*
         * مفتاح حفظ الأعمدة — ثابتٌ لا مشتقٌّ من العنوان.
         *
         * الجدول يسقط إلى `caption` حين يغيب، وتغييرُ كلمةٍ في العنوان
         * كان سينسي المشغّلَ أعمدتَه بلا سببٍ يراه. والمفتاح هنا اسم
         * الشاشة نفسه، فلا يتبدّل بتحرير نصّ.
         */
        storageKey="orders"
        columns={COLUMNS}
        rows={page?.data ?? []}
        rowKey={(order) => order.id}
        loading={query.isPending}
        error={
          query.isError
            ? {
                message:
                  errorCode === 403
                    ? 'لا تملك صلاحية orders.read لقراءة الطلبات.'
                    : query.error.message,
                code: errorCode,
              }
            : undefined
        }
        onRetry={() => void query.refetch()}
        empty={{
          title: 'لا طلبات مطابقة',
          body: 'لا طلب يطابق البحث والحالة المختارة. امسح المرشّحات لرؤية الكلّ.',
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
