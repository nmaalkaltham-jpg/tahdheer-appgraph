'use client';
// UNIT: PAGE-CUSTOMERS
// FILE: page.tsx
// ROUTE: /customers
// STYLE: customers.module.css
// PARENT: none
// USES: DATA-TABLE, EXPORT-CSV, FILTER-BAR, LIB-ADMIN-API, LIB-EXPORT-CSV, LIB-FORMAT +2
// API: /admin/customers
// MAP: docs/PROJECT_MAP.md

/**
 * العملاء — على جدول القوائم المشترك.
 *
 * الأعداد (الطلبات وصلاحيات الوصول) تأتي محسوبة من الخادم بعدٍّ حقيقي؛
 * الصفر هنا يعني «لا سجلات» فعلًا لأنّه ناتج عدّ، لا قيمة بديلة.
 *
 * ─── الرابط هو مصدر الترشيح، لا حالةٌ محلّية ───
 *
 * السبب مباشر: «مركز الاختصارات» يقصد `‎/customers?q=<البريد>‎` بعد اختيار
 * عميل من نتائجه — ولو كان الترشيح حالةً داخلية لَفتحت القائمةَ كاملةً غيرَ
 * مرشَّحة بعد نتيجةٍ صحيحة، وهو أسوأ من ألّا يظهر العميل في النتائج أصلًا.
 * وهذا العقد محفوظٌ كما كان: `?q=` يبقى مفتاح البحث نفسه.
 *
 * ─── ولا تحديدَ متعدّد هنا ───
 *
 * لا نقطة نهايةٍ تُعدّل عميلًا من هذه القائمة. ومربّعُ اختيارٍ بلا فعلٍ
 * يُنفَّذ عليه وعدٌ فارغ: يحدّد المشغّل عشرين صفًّا ثمّ يبحث عن زرٍّ لا وجود له.
 *
 * و`Suspense` ليس زينة: `useSearchParams` في مكوّن عميل يوجبه وقت البناء،
 * وبدونه يفشل البناء لا التشغيل — أي بعد فوات الملاحظة محلّيًّا.
 */

import Link from 'next/link';
import { Suspense, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '../../../components/resource';
import { DataTable, type Column } from '../../../components/ui/data-table';
import { FilterBar } from '../../../components/ui/filter-bar';
import { useListState } from '../../../lib/list-state';
import { api, type CustomerRow, type Paginated } from '../../../lib/admin-api';
import { count, dateTime } from '../../../lib/format';
import { ExportCsv } from '../../../components/export-csv';
import type { CsvColumn } from '../../../lib/export-csv';
import styles from './customers.module.css';

const CUSTOMER_CSV: readonly CsvColumn<CustomerRow>[] = [
  { header: 'الاسم', value: (c) => c.name },
  { header: 'البريد', value: (c) => c.email },
  { header: 'الجوال', value: (c) => c.phone },
  { header: 'عدد الطلبات', value: (c) => c.ordersCount },
  { header: 'الصلاحيات', value: (c) => c.entitlementsCount },
  { header: 'الفعّالة منها', value: (c) => c.activeEntitlementsCount },
  { header: 'أُنشئ (ISO)', value: (c) => c.createdAt },
];

const COLUMNS: readonly Column<CustomerRow>[] = [
  {
    key: 'name',
    header: 'الاسم',
    sortKey: 'name',
    /* البطاقة تعرض طلباته وصلاحياته وعدّادات تنزيله. */
    cell: (customer) => <Link href={`/customers/${customer.id}`}>{customer.name}</Link>,
  },
  {
    key: 'email',
    header: 'البريد',
    sortKey: 'email',
    cell: (customer) => <bdi dir="ltr">{customer.email}</bdi>,
  },
  {
    key: 'phone',
    header: 'الجوال',
    optional: true,
    cell: (customer) =>
      customer.phone === null || customer.phone === '' ? (
        <span className="absent">—</span>
      ) : (
        <bdi dir="ltr">{customer.phone}</bdi>
      ),
  },
  {
    key: 'orders',
    header: 'الطلبات',
    sortKey: 'orders',
    align: 'end',
    cell: (customer) => <bdi dir="ltr">{count(customer.ordersCount)}</bdi>,
  },
  {
    key: 'activeEntitlements',
    header: 'صلاحيات فعّالة',
    sortKey: 'activeEntitlements',
    align: 'end',
    cell: (customer) => <bdi dir="ltr">{count(customer.activeEntitlementsCount)}</bdi>,
  },
  {
    key: 'entitlements',
    header: 'إجمالي الصلاحيات',
    sortKey: 'entitlements',
    align: 'end',
    cell: (customer) => <bdi dir="ltr">{count(customer.entitlementsCount)}</bdi>,
  },
  {
    key: 'createdAt',
    header: 'تاريخ التسجيل',
    sortKey: 'createdAt',
    cell: (customer) => <bdi>{dateTime(customer.createdAt)}</bdi>,
  },
];

export default function CustomersPage() {
  return (
    <Suspense fallback={null}>
      <CustomersScreen />
    </Suspense>
  );
}

function CustomersScreen() {
  const list = useListState();
  const path = `/admin/customers?${list.queryString}`;

  const query = useQuery<Paginated<CustomerRow>>({
    queryKey: ['admin-resource', path],
    queryFn: async () => {
      const result = await api.get<Paginated<CustomerRow>>(path);
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

  const total = useMemo(() => page?.meta.total ?? null, [page]);

  return (
    <section className={styles.page}>
      <PageHeader trail="العملاء" title="العملاء" />

      <FilterBar
        searchPlaceholder="ابحث بالاسم أو البريد"
        draftQuery={list.draftQuery}
        onQuery={list.setQuery}
        onClear={list.clearFilters}
        hasFilters={list.state.q !== ''}
        actions={
          page === undefined ? null : (
            <ExportCsv
              filename="customers"
              columns={CUSTOMER_CSV}
              source={{ kind: 'paged', path, total: page.meta.total }}
            />
          )
        }
      />

      <p className="page-note">
        <bdi dir="ltr">{count(total)}</bdi> عميلًا مطابقًا للبحث على الخادم.
      </p>

      <DataTable
        caption="قائمة العملاء"
        /*
         * مفتاح حفظ الأعمدة — ثابتٌ لا مشتقٌّ من العنوان.
         *
         * الجدول يسقط إلى `caption` حين يغيب، وتغييرُ كلمةٍ في العنوان
         * كان سينسي المشغّلَ أعمدتَه بلا سببٍ يراه. والمفتاح هنا اسم
         * الشاشة نفسه، فلا يتبدّل بتحرير نصّ.
         */
        storageKey="customers"
        columns={COLUMNS}
        rows={page?.data ?? []}
        rowKey={(customer) => customer.id}
        loading={query.isPending}
        error={
          query.isError
            ? {
                /*
                 * القائمة محروسة بـ`customers.read` — عملاءُ لا أرقام، ولذلك
                 * ليست `analytics.read`. و`nav.ts` يُخفي المدخل عمّن لا يملكها،
                 * غير أنّ الرابط المباشر يصل: فتُسمّى الصلاحية باسمها ويُنزع
                 * زرّ الإعادة الذي لن ينجح مهما كُرّر.
                 */
                message:
                  errorCode === 403
                    ? 'لا تملك صلاحية customers.read لقراءة العملاء.'
                    : query.error.message,
                code: errorCode,
              }
            : undefined
        }
        onRetry={() => void query.refetch()}
        empty={{
          title: 'لا عملاء مطابقون',
          body: 'لا عميل يطابق البحث. امسح البحث لرؤية القائمة كاملة.',
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
