'use client';
// UNIT: LIST-PANEL
// FILE: list-panel.tsx
// STYLE: catalog-list.module.css
// PARENT: PAGE-STUDIO-CATALOG
// USES: ADMIN-SESSION, DATA-TABLE, EXPORT-CSV, FILTER-BAR, LIB-ADMIN-API, LIB-EXPORT-CSV +4
// API: PATCH /admin/products
// MAP: docs/PROJECT_MAP.md

/**
 * استوديو المنتج — القائمة الواحدة، على جدول القوائم المشترك.
 *
 * كانت مساحتا عمل على المسار نفسه: «المنتجات» (ترشيح وإنشاء ونشر وسحب)
 * و«استوديو المنتج» (فتح الخطوات ومنشئ الصفحة)، وكلتاهما تقرأ
 * `/admin/products` وتكتب `PATCH /admin/products/{id}`. اندمجتا فصار للمنتج
 * مدخلٌ واحد لا مدخلان يفترقان في القدرات.
 *
 * ─── ما تغيّر في هذه الخطوة ───
 *
 * الجدول صار `DataTable` المشترك: الفرز والتصفّح في **الخادم**، والمرشّحات
 * في الرابط، والتحديد المتعدّد يفتح نشرًا وسحبًا جماعيَّين. وجدولٌ يفرز
 * صفحته وحدها يقول «الأغلى» وهو أغلى عشرين صفًّا لا أغلى الكتالوج.
 *
 * ─── وما لم يتغيّر ───
 *
 * النشر يمرّ ببوابة الجاهزية على الخادم: إن رفض برمز ٤٢٢ عُرضت قائمة النواقص
 * الحقيقية التي أعادها، بندًا بندًا، ولم تتغيّر حالة المنتج محلّيًا (MIP §8.1).
 * وعمود الجاهزية هو ما أعاده الخادم — لا نسبة تُحسب في الواجهة.
 */

import Link from 'next/link';
import { useCallback, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useSession } from '../../../../components/admin-session';
import { publicProductUrl, useStoreUrl } from '../../../../components/store-url';
import { ExportCsv } from '../../../../components/export-csv';
import { DataTable, type BulkAction, type Column } from '../../../../components/ui/data-table';
import { FilterBar } from '../../../../components/ui/filter-bar';
import { StatusBadge, type StatusTone } from '../../../../components/ui/status-badge';
import { useListState } from '../../../../lib/list-state';
import type { CsvColumn } from '../../../../lib/export-csv';
import { api, type Paginated, type ProductRow, type Readiness } from '../../../../lib/admin-api';
import { count, minorToRiyals, money, PRODUCT_STATUS_LABELS } from '../../../../lib/format';
import styles from './catalog-list.module.css';

/*
 * الجاهزية تُصدَّر رقمًا مركّبًا «٧/١١» لا صحًّا وخطأً: من يفتح الملفّ ليخطّط
 * لنشر دفعة يحتاج بُعد الصفّ عن الاكتمال، لا كونه غير مكتمل فحسب.
 */
const PRODUCT_CSV: readonly CsvColumn<ProductRow>[] = [
  { header: 'الرمز', value: (p) => p.sku },
  { header: 'العنوان', value: (p) => p.title },
  { header: 'المسار', value: (p) => p.slug },
  { header: 'الحالة', value: (p) => PRODUCT_STATUS_LABELS[p.status] ?? p.status },
  { header: 'السعر (ر.س)', value: (p) => minorToRiyals(p.priceMinor) },
  { header: 'السعر الأصلي (ر.س)', value: (p) => minorToRiyals(p.regularMinor) },
  { header: 'تخفيض سارٍ', value: (p) => (p.onSale === true ? 'نعم' : 'لا') },
  { header: 'التصنيف', value: (p) => p.categoryPath.join(' › ') },
  { header: 'الجاهزية', value: (p) => `${p.readiness.completedCount}/${p.readiness.totalCount}` },
];

const STATUS_TONES: Record<string, StatusTone> = {
  draft: 'neutral',
  published: 'success',
  retired: 'warning',
};

const FILTER_KEYS = ['status'] as const;

interface ProductsPayload extends Paginated<ProductRow> {
  statusCounts?: Record<string, number>;
}

/** حصيلةُ فعلٍ جماعيّ — صفًّا صفًّا، لا رسالةً واحدة تُخفي المحجوب. */
interface BulkOutcome {
  verb: string;
  ok: string[];
  blocked: { title: string; missing: string[] }[];
  failed: { title: string; message: string }[];
}

type PublishOutcome =
  | { kind: 'blocked'; readiness: Readiness; message: string }
  | { kind: 'failed'; message: string }
  | { kind: 'published' };

export function CatalogListPanel() {
  const { can } = useSession();
  const storeUrl = useStoreUrl();
  const list = useListState(FILTER_KEYS);

  const [outcome, setOutcome] = useState<{ id: number; result: PublishOutcome } | null>(null);
  const [bulkOutcome, setBulkOutcome] = useState<BulkOutcome | null>(null);
  const [busyRow, setBusyRow] = useState<number | null>(null);
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set());

  const path = `/admin/products?${list.queryString}`;

  const query = useQuery<ProductsPayload>({
    queryKey: ['admin-resource', path],
    queryFn: async () => {
      const result = await api.get<ProductsPayload>(path);
      if (!result.ok) {
        const error = new Error(result.message) as Error & { code?: number };
        error.code = result.status;
        throw error;
      }
      return result.data;
    },
  });

  const page = query.data;
  const reload = useCallback(() => void query.refetch(), [query]);

  /*
   * السحب — الاتجاه المعاكس للنشر.
   *
   * `retired` حالةٌ مصمَّمة بالكامل في الخادم (لها حكمٌ خاصّ في سياسة الفهرسة،
   * وهذه القائمة تُرشِّح بها): ملفٌّ نُشر بالخطأ أو بطل مع تغيّر المنهج يُنزَل بها.
   * الحالة لا تتغيّر محلّيًّا قبل أن يقبل الخادم — تمامًا كالنشر.
   */
  const withdraw = useCallback(
    async (product: ProductRow, to: 'retired' | 'draft') => {
      setBusyRow(product.id);
      setOutcome(null);
      setBulkOutcome(null);

      const result = await api.patch<{ data: ProductRow }>(
        `/admin/products/${product.id}/withdraw`,
        { to },
      );
      setBusyRow(null);

      if (!result.ok) {
        setOutcome({ id: product.id, result: { kind: 'failed', message: result.message } });

        return;
      }

      reload();
    },
    [reload],
  );

  const publish = useCallback(
    async (product: ProductRow) => {
      setBusyRow(product.id);
      setOutcome(null);
      setBulkOutcome(null);

      const result = await api.patch<{ data: ProductRow; readiness: Readiness }>(
        `/admin/products/${product.id}/publish`,
      );
      setBusyRow(null);

      if (result.ok) {
        setOutcome({ id: product.id, result: { kind: 'published' } });
        reload();

        return;
      }

      const body = (result.body ?? {}) as { readiness?: Readiness };
      setOutcome({
        id: product.id,
        result:
          result.code === 'READINESS_INCOMPLETE' && body.readiness
            ? { kind: 'blocked', readiness: body.readiness, message: result.message }
            : { kind: 'failed', message: result.message },
      });
    },
    [reload],
  );

  /**
   * فعلٌ جماعيّ — بالتسلسل لا بالتوازي، وبحصيلةٍ صفًّا صفًّا.
   *
   * ─── لماذا بالتسلسل ───
   *
   * عشرون طلبَ نشرٍ متوازيًا تصطدم بحدّ المعدّل على الخادم (`throttle:admin`)
   * فيفشل نصفها بـ٤٢٩ — وهو فشلٌ لا علاقة له بالمنتج، ويبدو في الحصيلة
   * وكأنّ المنتج مرفوض.
   *
   * ─── ولماذا الحصيلة صفًّا صفًّا ───
   *
   * «نُشر ٢٠» كذبٌ حين يُحجب ثلاثة بالجاهزية. والمشغّل يحتاج أن يعرف **أيّها**
   * وما الناقص فيه، وإلّا أعاد المحاولة على العشرين كلّها.
   */
  const runBulk = useCallback(
    async (rows: ProductRow[], verb: 'publish' | 'draft' | 'retired') => {
      setOutcome(null);

      const result: BulkOutcome = {
        verb: verb === 'publish' ? 'نُشر' : verb === 'draft' ? 'أُعيد مسوّدة' : 'سُحب',
        ok: [],
        blocked: [],
        failed: [],
      };

      for (const product of rows) {
        const response =
          verb === 'publish'
            ? await api.patch<{ data: ProductRow; readiness: Readiness }>(
                `/admin/products/${product.id}/publish`,
              )
            : await api.patch<{ data: ProductRow }>(`/admin/products/${product.id}/withdraw`, {
                to: verb,
              });

        if (response.ok) {
          result.ok.push(product.title);

          continue;
        }

        const body = (response.body ?? {}) as { readiness?: Readiness };

        if (response.code === 'READINESS_INCOMPLETE' && body.readiness) {
          result.blocked.push({ title: product.title, missing: body.readiness.missingLabels });

          continue;
        }

        result.failed.push({ title: product.title, message: response.message });
      }

      setBulkOutcome(result);
      setSelected(new Set());
      reload();
    },
    [reload],
  );

  const columns = useMemo<readonly Column<ProductRow>[]>(
    () => [
      {
        key: 'title',
        header: 'المنتج',
        sortKey: 'title',
        cell: (product) => (
          <>
            <Link href={`/studio/catalog/${product.id}`}>{product.title}</Link>
            <div className={styles.sub}>{product.categoryPath.join(' › ') || '—'}</div>
            {/* يظهر للمنشور فقط: صفحة غير المنشور تُعيد ٤٠٤. */}
            {(() => {
              const url = publicProductUrl(storeUrl, product.slug, product.status);

              return url === null ? null : (
                <a className={styles.out} href={url} target="_blank" rel="noopener noreferrer">
                  عرض في المتجر
                  <span aria-hidden="true">↗</span>
                  <span className="sr-only">(يفتح في تبويب جديد)</span>
                </a>
              );
            })()}
          </>
        ),
      },
      {
        key: 'sku',
        header: 'الرمز',
        sortKey: 'sku',
        cell: (product) => <bdi dir="ltr">{product.sku}</bdi>,
      },
      {
        key: 'status',
        header: 'الحالة',
        sortKey: 'status',
        cell: (product) => (
          <StatusBadge
            label={PRODUCT_STATUS_LABELS[product.status] ?? product.status}
            tone={STATUS_TONES[product.status] ?? 'neutral'}
          />
        ),
      },
      {
        key: 'price',
        header: 'السعر',
        sortKey: 'price',
        align: 'end',
        cell: (product) =>
          product.priceMinor === undefined ? (
            <span className="absent">بلا سعر</span>
          ) : (
            <>
              <bdi dir="ltr">{money(product.priceMinor)}</bdi> ر.س
            </>
          ),
      },
      {
        key: 'readiness',
        header: 'الجاهزية',
        cell: (product) => (
          <>
            <bdi dir="ltr">
              {count(product.readiness.completedCount)}/{count(product.readiness.totalCount)}
            </bdi>
            {product.readiness.ready ? null : (
              <div className={styles.sub}>ينقص: {product.readiness.missingLabels.join('، ')}</div>
            )}
          </>
        ),
      },
      {
        key: 'actions',
        header: 'إجراءات',
        cell: (product) => (
          <span className={styles.rowActions}>
            <Link className="btn btn-ghost" href={`/studio/catalog/${product.id}`}>
              الاستوديو
            </Link>
            <Link className="btn btn-ghost" href={`/studio/catalog/${product.id}/layout`}>
              منشئ الصفحة
            </Link>
            {!can('products.publish') ? (
              <span className="absent">لا صلاحية نشر</span>
            ) : product.status === 'published' ? (
              <>
                {/* «مسوّدة» للعودة إلى التحرير — النشر ثانيةً يمرّ بالبوابة.
                    و«اسحب» للسحب النهائي: يخرج من الخريطة ومن الفهرسة بحكمٍ
                    خاصّ في الخادم. */}
                <button
                  type="button"
                  className="btn btn-ghost"
                  disabled={busyRow === product.id}
                  onClick={() => void withdraw(product, 'draft')}
                >
                  أعِده مسوّدة
                </button>
                <button
                  type="button"
                  className="btn btn-ghost"
                  disabled={busyRow === product.id}
                  onClick={() => void withdraw(product, 'retired')}
                >
                  {busyRow === product.id ? 'جارٍ…' : 'اسحبه'}
                </button>
              </>
            ) : (
              <button
                type="button"
                className="btn btn-ghost"
                disabled={busyRow === product.id}
                onClick={() => void publish(product)}
              >
                {busyRow === product.id ? 'جارٍ النشر…' : 'نشر'}
              </button>
            )}
          </span>
        ),
      },
    ],
    [can, storeUrl, busyRow, publish, withdraw],
  );

  /* الأفعال الجماعيّة تُخفى بلا صلاحية النشر — لا تُعرض معطَّلة بلا سبب. */
  const bulkActions = useMemo<readonly BulkAction<ProductRow>[]>(
    () => [
      {
        label: 'نشر المحدَّد',
        enabled: can('products.publish'),
        run: (rows) => runBulk(rows, 'publish'),
      },
      {
        label: 'إعادة إلى مسوّدة',
        enabled: can('products.publish'),
        run: (rows) => runBulk(rows, 'draft'),
      },
      {
        label: 'سحب المحدَّد',
        tone: 'danger',
        enabled: can('products.publish'),
        run: (rows) => runBulk(rows, 'retired'),
      },
    ],
    [can, runBulk],
  );

  const tabs = useMemo(
    () =>
      (page?.filters?.statuses ?? []).map((status) => ({
        id: status,
        label: PRODUCT_STATUS_LABELS[status] ?? status,
        total: page?.statusCounts?.[status],
      })),
    [page],
  );

  const errorCode = (query.error as (Error & { code?: number }) | null)?.code;

  return (
    <section className={styles.page}>

      <p className="page-note">
        اختر منتجًا لفتح خطواته الإحدى عشرة، أو افتح منشئ صفحته. والنشر والسحب من هنا مباشرةً.
      </p>

      <FilterBar
        searchPlaceholder="ابحث باسم المنتج أو رمزه أو مساره"
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
              filename="products"
              columns={PRODUCT_CSV}
              source={{ kind: 'paged', path, total: page.meta.total }}
            />
          )
        }
      />

      <DataTable
        caption="المنتجات القابلة للتحرير في الاستوديو"
        columns={columns}
        rows={page?.data ?? []}
        rowKey={(product) => product.id}
        loading={query.isPending}
        error={
          query.isError
            ? {
                message:
                  errorCode === 403
                    ? 'لا تملك صلاحية products.read لقراءة المنتجات.'
                    : query.error.message,
                code: errorCode,
              }
            : undefined
        }
        onRetry={reload}
        empty={{
          title: 'لا منتجات مطابقة',
          body: 'لا منتج يطابق البحث والحالة المختارة. أنشئ منتجًا أو امسح المرشّحات.',
        }}
        sort={list.state.sort}
        direction={list.state.direction}
        onSort={list.setSort}
        selection={can('products.publish') ? { selected, onChange: setSelected } : undefined}
        bulkActions={bulkActions}
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

      {bulkOutcome ? <BulkOutcomePanel outcome={bulkOutcome} /> : null}
      {outcome ? <PublishOutcomePanel outcome={outcome.result} /> : null}
    </section>
  );
}

/** حصيلةٌ صفًّا صفًّا — «نُشر ٢٠» كذبٌ حين يُحجب ثلاثة. */
function BulkOutcomePanel({ outcome }: { outcome: BulkOutcome }) {
  return (
    <section
      className="panel"
      role={outcome.blocked.length + outcome.failed.length > 0 ? 'alert' : 'status'}
    >
      <h2>حصيلة الفعل الجماعيّ</h2>
      <p className="page-note">
        {outcome.verb} <bdi dir="ltr">{count(outcome.ok.length)}</bdi>
        {outcome.blocked.length > 0 ? (
          <>
            {' '}
            · محجوب بالجاهزية <bdi dir="ltr">{count(outcome.blocked.length)}</bdi>
          </>
        ) : null}
        {outcome.failed.length > 0 ? (
          <>
            {' '}
            · فشل <bdi dir="ltr">{count(outcome.failed.length)}</bdi>
          </>
        ) : null}
      </p>

      {outcome.blocked.length > 0 ? (
        <ul className="checklist">
          {outcome.blocked.map((row) => (
            <li key={row.title} className="todo">
              <span aria-hidden="true">×</span>
              <span>
                {row.title} — ينقص: {row.missing.join('، ')}
              </span>
            </li>
          ))}
        </ul>
      ) : null}

      {outcome.failed.length > 0 ? (
        <ul className="checklist">
          {outcome.failed.map((row) => (
            <li key={row.title} className="todo">
              <span aria-hidden="true">×</span>
              <span>
                {row.title} — {row.message}
              </span>
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}

function PublishOutcomePanel({ outcome }: { outcome: PublishOutcome }) {
  if (outcome.kind === 'published') {
    return (
      <p className="state-ok" role="status">
        نُشر المنتج.
      </p>
    );
  }

  if (outcome.kind === 'failed') {
    return (
      <p className="state-error" role="alert">
        {outcome.message}
      </p>
    );
  }

  return (
    <section className="panel panel-blocked" role="alert">
      <h2>النشر محجوب — قائمة الجاهزية</h2>
      <p>{outcome.message}</p>
      <ul className="checklist">
        {outcome.readiness.items.map((item) => (
          <li key={item.key} className={item.ok ? 'done' : 'todo'}>
            <span aria-hidden="true">{item.ok ? '✓' : '×'}</span>
            <span>{item.label}</span>
            <span className="sr-only">{item.ok ? 'مكتمل' : 'ناقص'}</span>
          </li>
        ))}
      </ul>
      <p className="page-note">
        مكتمل <bdi dir="ltr">{count(outcome.readiness.completedCount)}</bdi> من{' '}
        <bdi dir="ltr">{count(outcome.readiness.totalCount)}</bdi> — النشر لا يتم إلا عند اكتمال الكل.
      </p>
    </section>
  );
}
