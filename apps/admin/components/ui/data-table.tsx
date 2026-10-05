'use client';
// UNIT: DATA-TABLE
// FILE: data-table.tsx
// STYLE: data-table.module.css
// PARENT: LIST-PANEL, PAGE-CUSTOMERS, PAGE-OPERATIONS-INVENTORY, PAGE-ORDERS, SHIPMENTS-PANEL
// USES: COMPONENTS-ICONS, LIB-FORMAT, STATES
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * جدول القوائم المشترك — بحثٌ وفرزٌ وتحديدٌ وتصفّح، مرّةً واحدة.
 *
 * ─── لماذا مكوّنٌ واحد ───
 *
 * كانت كلّ شاشة قائمةٍ تكتب جدولها بيدها: رأسًا غير لاصق هنا، وتمريرًا
 * أفقيًّا يبتلع الصفحة هناك، وحالة فراغٍ تقول «لا بيانات» بلا سبب. والنتيجة
 * أنّ المشغّل يتعلّم كلّ شاشةٍ من جديد.
 *
 * ─── وما لا يفعله ───
 *
 * لا يفرز محلّيًّا ولا يصفّح محلّيًّا: الفرز والتصفّح يقعان في الخادم، وهذا
 * يُرسل نيّتَهما في الرابط ويعرض ما يعود. جدولٌ يفرز الصفحة الحاليّة وحدها
 * يكذب: يقول «الأعلى» وهو أعلى خمسٍ وعشرين صفًّا لا أعلى الجميع.
 *
 * ─── ثلاثة عيوبٍ أُصلحت في هذا الدور ───
 *
 * ١ — الرأس اللاصق لم يكن يلصق. `overflow-x: auto` يجعل الحاوية مُمرِّرة
 *     رأسيًّا أيضًا، وبلا سقفِ ارتفاعٍ لا يقع تمريرٌ رأسيّ داخلها إطلاقًا —
 *     فـ`position: sticky` كان ترميزًا بلا أثر. صار للحاوية سقف.
 *
 * ٢ — خلفيّة الرأس كانت `--surface`، وهي شفّافةٌ في الطبقة الزجاجيّة
 *     (`rgba(255,255,255,.05)`). رأسٌ لاصقٌ شفّاف تمرّ الصفوف من تحته
 *     فيُقرأ سطران فوق بعضهما. صارت الخلفيّة معتمة على `--cc-bg1`.
 *
 * ٣ — الشاشة الضيّقة كانت تُمرَّر أفقيًّا، فيغيب العمود الأوّل — وهو الذي
 *     يُعرَّف به الصفّ (رقم الطلب · اسم العميل · الرمز). صار الصفّ بطاقةً
 *     مكدّسة تحت ‎720px‎: العمود الأوّل عنوانُها، وباقي الأعمدة أزواج
 *     «تسمية ← قيمة».
 *
 * ─── واختيار الأعمدة يُحفظ ───
 *
 * إخفاءُ عمودٍ يضيع عند كلّ تنقّل لا يُوفّر شيئًا: المشغّل يخفيه في كلّ مرّة
 * من جديد. صار محفوظًا في `localStorage` بمفتاحٍ لكلّ جدول.
 */

import {
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  useSyncExternalStore,
  type ReactNode,
} from 'react';
import { Icon } from '../icons';
import { count } from '../../lib/format';
import { EmptyState, ErrorState, Skeleton } from './states';
import styles from './data-table.module.css';

export interface Column<Row> {
  key: string;
  header: string;
  /** ما يُعرض في الخليّة — العقدة لا النصّ، فالشارات والروابط تمرّ. */
  cell: (row: Row) => ReactNode;
  /** مفتاح الفرز في الخادم؛ غيابُه يعني عمودًا لا يُفرز. */
  sortKey?: string;
  align?: 'start' | 'end';
  /** أعمدةٌ تُخفى افتراضيًّا وتُظهرها لوحة التحكّم بالأعمدة. */
  optional?: boolean;
}

export interface BulkAction<Row> {
  label: string;
  /** يُنفَّذ على المحدَّد؛ يُعطَّل أثناء التنفيذ فلا ينقر مرّتين. */
  run: (rows: Row[]) => Promise<void> | void;
  tone?: 'default' | 'danger';
  /** غيابُ الصلاحية يُخفي الفعل ولا يحجب الجدول. */
  enabled?: boolean;
}

/*
 * بادئةٌ واحدة لكلّ الجداول: مفتاحٌ عامّ مثل `columns` يصطدم بأيّ مفتاحٍ
 * آخر في النطاق نفسه، ومفتاحٌ بلا بادئة لا يُعرف مالكُه عند التنظيف.
 */
const COLUMNS_STORE = 'tahdheer.admin.table-columns.';

/*
 * التخزين مخزنٌ خارجيّ يُشترَك فيه — لا حالةٌ داخل المكوّن.
 *
 * ولهذا `useSyncExternalStore` لا `useState` + `useEffect`: المكوّن يُصيَّر
 * على الخادم حيث لا `localStorage`، ولا يجوز أن يخالف أوّلُ تصييرٍ في
 * المتصفّح خرجَ الخادم. هذا الخطّاف وحده يعرف لقطتين — لقطةَ خادمٍ ولقطةَ
 * عميل — فيُرطِّب بالافتراض ثمّ ينتقل إلى المحفوظ بلا خطأ ترطيب.
 *
 * والحدث `storage` لا يقع في المستند الذي كتب — لذلك يُبلَّغ المشتركون يدًا.
 */
const storeListeners = new Set<() => void>();
/* بديلٌ في الذاكرة: وضع الخصوصيّة الصارمة يرمي عند الكتابة، والجلسة الحاليّة تبقى. */
const storeFallback = new Map<string, string>();

function subscribeStore(onChange: () => void): () => void {
  storeListeners.add(onChange);
  window.addEventListener('storage', onChange);
  return () => {
    storeListeners.delete(onChange);
    window.removeEventListener('storage', onChange);
  };
}

/* اللقطة نصٌّ لا مجموعة: `useSyncExternalStore` يقارن بالمطابقة، ومجموعةٌ جديدة في كلّ نداءٍ حلقةٌ لا تنتهي. */
function readStore(storeKey: string): string | null {
  try {
    const raw = window.localStorage.getItem(storeKey);
    if (raw !== null) return raw;
  } catch {
    // يُقرأ من البديل أدناه.
  }
  return storeFallback.get(storeKey) ?? null;
}

function writeStore(storeKey: string, value: string): void {
  storeFallback.set(storeKey, value);
  try {
    window.localStorage.setItem(storeKey, value);
  } catch {
    // الحفظ عبر الجلسات زينة، والإخفاء في هذه الجلسة هو الوظيفة.
  }
  for (const notify of storeListeners) notify();
}

/** ما يُقرأ من التخزين قد يكون تالفًا أو من نسخةٍ سابقة — يُنقّى ولا يُوثق به. */
function parseHidden(raw: string | null, known: ReadonlySet<string>): ReadonlySet<string> | null {
  if (raw === null) return null;

  let parsed: unknown = null;
  try {
    parsed = JSON.parse(raw);
  } catch {
    // قيمةٌ تالفة: تُهمل ويعود الافتراض، ولا تُسقط الشاشة.
    return null;
  }
  if (!Array.isArray(parsed)) return null;

  /* مفاتيحُ اختفت من التعريف تُسقَط: عمودٌ حُذف لا يجوز أن يُخفي عمودًا باقيًا. */
  const next = new Set(parsed.filter((key): key is string => typeof key === 'string' && known.has(key)));
  /* إخفاءُ الكلّ يترك جدولًا بلا أعمدة — حالةٌ لا مخرج منها إلّا بمسح التخزين. */
  return next.size >= known.size ? null : next;
}

export function DataTable<Row>({
  columns,
  rows,
  rowKey,
  loading = false,
  error,
  onRetry,
  empty,
  sort,
  direction,
  onSort,
  selection,
  bulkActions,
  pagination,
  caption,
  storageKey,
}: {
  columns: readonly Column<Row>[];
  rows: readonly Row[];
  rowKey: (row: Row) => string | number;
  loading?: boolean;
  error?: { message: string; code?: number };
  onRetry?: () => void;
  empty?: { title: string; body: string };
  sort?: string | null;
  direction?: 'asc' | 'desc';
  onSort?: (sortKey: string) => void;
  selection?: { selected: ReadonlySet<string>; onChange: (next: ReadonlySet<string>) => void };
  bulkActions?: readonly BulkAction<Row>[];
  pagination?: { page: number; perPage: number; total: number; onPage: (page: number) => void };
  caption: string;
  /** مفتاح حفظ الأعمدة؛ غيابُه يجعل العنوان هو المفتاح — وهو فريدٌ لكلّ شاشة. */
  storageKey?: string;
}) {
  const tableId = useId();
  const panelId = useId();

  const knownKeys = useMemo(() => new Set(columns.map((c) => c.key)), [columns]);
  const defaultHidden = useMemo(
    () => new Set(columns.filter((c) => c.optional === true).map((c) => c.key)),
    [columns],
  );
  /* أيّ فعلٍ جماعيّ يجري الآن — لا `true` مشترك يجعل كلّ الأزرار تقول «جارٍ…». */
  const [running, setRunning] = useState<string | null>(null);
  const [columnsOpen, setColumnsOpen] = useState(false);
  const columnsButtonRef = useRef<HTMLButtonElement | null>(null);
  const columnsPanelRef = useRef<HTMLDivElement | null>(null);

  const storeKey = `${COLUMNS_STORE}${storageKey ?? caption}`;

  /* لقطة الخادم `null` دائمًا: لا تخزين هناك، والافتراض هو ما يُرطَّب به. */
  const storedRaw = useSyncExternalStore(
    subscribeStore,
    () => readStore(storeKey),
    () => null,
  );

  const hidden = useMemo(
    () => parseHidden(storedRaw, knownKeys) ?? defaultHidden,
    [storedRaw, knownKeys, defaultHidden],
  );

  const toggleColumn = useCallback(
    (key: string) => {
      const next = new Set(hidden);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      /* آخر عمودٍ ظاهر لا يُخفى: جدولٌ بلا أعمدة لا يُستعاد من واجهته. */
      if (next.size >= knownKeys.size) return;
      writeStore(storeKey, JSON.stringify([...next]));
    },
    [hidden, knownKeys, storeKey],
  );

  const showAllColumns = useCallback(() => {
    writeStore(storeKey, '[]');
  }, [storeKey]);

  /*
   * لوحة الأعمدة تُغلق بـEscape وبالنقر خارجها، ويعود التركيز إلى زرّها.
   *
   * بدون ذلك يفتحها مستعمل لوحة المفاتيح ثمّ لا يجد سبيلًا إلى إغلاقها إلّا
   * بالتبويب عبر كلّ مربّعاتها — وهي عشرة أو أكثر.
   */
  useEffect(() => {
    if (!columnsOpen) return;

    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      setColumnsOpen(false);
      columnsButtonRef.current?.focus();
    };
    const onDown = (e: PointerEvent) => {
      const el = e.target as HTMLElement | null;
      if (
        el !== null &&
        columnsPanelRef.current !== null &&
        !columnsPanelRef.current.contains(el) &&
        el !== columnsButtonRef.current
      ) {
        setColumnsOpen(false);
      }
    };

    window.addEventListener('keydown', onKey);
    window.addEventListener('pointerdown', onDown);

    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('pointerdown', onDown);
    };
  }, [columnsOpen]);

  /*
   * فتحُ اللوحة ينقل التركيز إلى أوّل مربّعٍ فيها.
   *
   * بدونه يضغط مستعمل لوحة المفاتيح «الأعمدة» فتُفتح لوحةٌ **خلفه** في ترتيب
   * التبويب، فيبدو الزرّ كأنّه لم يفعل شيئًا.
   */
  useEffect(() => {
    if (!columnsOpen) return;
    columnsPanelRef.current?.querySelector('input')?.focus();
  }, [columnsOpen]);

  const visible = useMemo(() => columns.filter((c) => !hidden.has(c.key)), [columns, hidden]);
  const hiddenCount = columns.length - visible.length;

  const selectedRows = useMemo(
    () => (selection === undefined ? [] : rows.filter((row) => selection.selected.has(String(rowKey(row))))),
    [rows, rowKey, selection],
  );

  const allOnPageSelected =
    selection !== undefined && rows.length > 0 && selectedRows.length === rows.length;

  const toggleAll = useCallback(() => {
    if (selection === undefined) return;
    const next = new Set(selection.selected);
    for (const row of rows) {
      const key = String(rowKey(row));
      if (allOnPageSelected) next.delete(key);
      else next.add(key);
    }
    selection.onChange(next);
  }, [selection, rows, rowKey, allOnPageSelected]);

  const toggleRow = useCallback(
    (key: string) => {
      if (selection === undefined) return;
      const next = new Set(selection.selected);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      selection.onChange(next);
    },
    [selection],
  );

  if (error !== undefined) {
    return <ErrorState message={error.message} code={error.code} onRetry={onRetry} />;
  }

  const lastPage =
    pagination === undefined ? 1 : Math.max(1, Math.ceil(pagination.total / pagination.perPage));

  /* مدى الصفحة يُقال بالأرقام: «١–٢٥ من ٧٤٠» أوضح من «صفحة ١ من ٣٠». */
  const firstRow =
    pagination === undefined ? 0 : Math.min((pagination.page - 1) * pagination.perPage + 1, pagination.total);
  const lastRow =
    pagination === undefined ? 0 : Math.min(pagination.page * pagination.perPage, pagination.total);

  const sortable = onSort !== undefined;

  return (
    <div className={styles.wrap}>
      {/* ───── شريط الأدوات ───── */}
      <div className={styles.toolbar}>
        {selection !== undefined && selectedRows.length > 0 ? (
          <div className={styles.bulk} role="status">
            <span className={styles.bulkCount}>
              حُدِّد <bdi dir="ltr">{count(selectedRows.length)}</bdi>
            </span>
            {(bulkActions ?? [])
              .filter((action) => action.enabled !== false)
              .map((action) => (
                <button
                  key={action.label}
                  type="button"
                  className={action.tone === 'danger' ? styles.bulkDanger : styles.bulkAction}
                  /* المنع أثناء التنفيذ: النقر مرّتين يُنفّذ الفعل مرّتين. */
                  disabled={running !== null}
                  onClick={() => {
                    setRunning(action.label);
                    void Promise.resolve(action.run(selectedRows)).finally(() => setRunning(null));
                  }}
                >
                  {running === action.label ? 'جارٍ…' : action.label}
                </button>
              ))}
            <button
              type="button"
              className={styles.bulkClear}
              disabled={running !== null}
              onClick={() => selection.onChange(new Set())}
            >
              إلغاء التحديد
            </button>
          </div>
        ) : null}

        <div className={styles.toolbarEnd}>
          <div className={styles.pop}>
            <button
              ref={columnsButtonRef}
              type="button"
              className={styles.columnsButton}
              aria-expanded={columnsOpen}
              aria-haspopup="true"
              aria-controls={panelId}
              onClick={() => setColumnsOpen((was) => !was)}
            >
              <Icon name="grid" size={14} />
              الأعمدة
              {/* عددُ المخفيّ معلَنٌ على الزرّ: جدولٌ ناقصُ عمودٍ بصمتٍ يُقرأ بيانًا ناقصًا. */}
              {hiddenCount > 0 ? (
                <span className={styles.columnsBadge}>
                  <bdi dir="ltr">{count(hiddenCount)}</bdi>
                </span>
              ) : null}
            </button>
            {columnsOpen ? (
              <div
                ref={columnsPanelRef}
                id={panelId}
                className={styles.columnsPanel}
                role="group"
                aria-label="الأعمدة الظاهرة"
              >
                {columns.map((column) => {
                  const shown = !hidden.has(column.key);
                  /* آخر عمودٍ ظاهر يُعطَّل مربّعُه: منعٌ مرئيّ خيرٌ من نقرةٍ لا تفعل. */
                  const locked = shown && visible.length === 1;

                  return (
                    <label key={column.key} className={styles.columnsRow}>
                      <input
                        type="checkbox"
                        checked={shown}
                        disabled={locked}
                        onChange={() => toggleColumn(column.key)}
                      />
                      <span>{column.header}</span>
                    </label>
                  );
                })}
                <button
                  type="button"
                  className={styles.columnsReset}
                  disabled={hiddenCount === 0}
                  onClick={showAllColumns}
                >
                  إظهار الكلّ
                </button>
              </div>
            ) : null}
          </div>
        </div>
      </div>

      {/* ───── الجدول ───── */}
      {loading ? (
        /* هيكلٌ بهندسة الجدول نفسها — الإطار والرأس وإيقاع الصفوف — فلا تقفز الصفحة. */
        <Skeleton variant="table" rows={10} columns={Math.min(7, Math.max(2, visible.length))} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon="box"
          title={empty?.title ?? 'لا نتائج'}
          body={empty?.body ?? 'لا صفوف تطابق البحث والمرشّحات الحاليّة. جرّب توسيعها.'}
        />
      ) : (
        <>
          {/*
            * شريط الفرز — لا يظهر إلّا في العرض المكدّس (تحت ‎720px‎).
            *
            * الرأس يُخفى هناك، ومعه تختفي أزرار الفرز. وقائمةٌ لا تُفرز على
            * الجوّال ليست «نسخةً مصغّرة» بل وظيفةٌ ناقصة. و`display:none`
            * على الشاشة العريضة يُخرجه من شجرة الوصول وترتيب التبويب معًا،
            * فلا يتكرّر الزرّ مرّتين لقارئ الشاشة.
            */}
          {sortable ? (
            <div className={styles.sortBar} role="group" aria-label={`فرز ${caption}`}>
              {visible
                .filter((column) => column.sortKey !== undefined)
                .map((column) => {
                  const active = sort === column.sortKey;
                  return (
                    <button
                      key={column.key}
                      type="button"
                      className={styles.sortChip}
                      aria-pressed={active}
                      onClick={() => onSort(column.sortKey ?? column.key)}
                    >
                      {column.header}
                      {active ? (
                        <Icon
                          name="chevron"
                          size={12}
                          style={{ transform: direction === 'asc' ? 'rotate(-90deg)' : 'rotate(90deg)' }}
                        />
                      ) : null}
                    </button>
                  );
                })}
            </div>
          ) : null}

          {/*
           * الطوق منطقةُ بلوغٍ بلوحة المفاتيح: كروم لا يبئّر منطقة تمرير من
           * تلقائه، فجدولٌ أعرض من الشاشة يصير غير قابلٍ للتصفّح بلوحة
           * المفاتيح إطلاقًا. `tabIndex` هنا ترميزٌ لا نمط.
           */}
          <div className={styles.scroll} tabIndex={0} role="region" aria-label={caption}>
            <table className={styles.table} id={tableId}>
              <caption className="sr-only">{caption}</caption>
              <thead>
                <tr>
                  {selection !== undefined ? (
                    <th scope="col" className={styles.checkCell}>
                      <input
                        type="checkbox"
                        checked={allOnPageSelected}
                        onChange={toggleAll}
                        aria-label="تحديد كلّ صفوف الصفحة"
                      />
                    </th>
                  ) : null}
                  {visible.map((column) => {
                    const canSort = column.sortKey !== undefined && sortable;
                    const active = canSort && sort === column.sortKey;

                    return (
                      <th
                        key={column.key}
                        scope="col"
                        data-align={column.align ?? 'start'}
                        data-sorted={active ? '' : undefined}
                        /*
                         * `aria-sort` على العمود النشط وحده: وسمُ الجميع
                         * «none» ضجيجٌ، ووسمُ اثنين «تصاعديّ» كذب.
                         */
                        aria-sort={
                          active ? (direction === 'asc' ? 'ascending' : 'descending') : undefined
                        }
                      >
                        {canSort ? (
                          <button
                            type="button"
                            className={styles.sortButton}
                            onClick={() => onSort(column.sortKey ?? column.key)}
                          >
                            <span>{column.header}</span>
                            <Icon
                              name="chevron"
                              size={12}
                              className={active ? styles.sortActive : styles.sortIdle}
                              style={{
                                transform:
                                  active && direction === 'asc' ? 'rotate(-90deg)' : 'rotate(90deg)',
                              }}
                            />
                          </button>
                        ) : (
                          column.header
                        )}
                      </th>
                    );
                  })}
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const key = String(rowKey(row));
                  const checked = selection?.selected.has(key) ?? false;

                  return (
                    <tr key={key} data-selected={checked ? '' : undefined}>
                      {selection !== undefined ? (
                        <td className={styles.checkCell}>
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={() => toggleRow(key)}
                            aria-label={`تحديد الصفّ ${key}`}
                          />
                        </td>
                      ) : null}
                      {visible.map((column, index) => (
                        <td
                          key={column.key}
                          data-align={column.align ?? 'start'}
                          /* العمود الأوّل هو معرّف الصفّ — فيصير عنوان البطاقة في العرض المكدّس. */
                          data-primary={index === 0 ? '' : undefined}
                        >
                          {/*
                            * التسمية عنصرٌ حقيقيّ لا `::before`.
                            *
                            * المحتوى المولَّد لا يُنطق نطقًا موثوقًا، وفي العرض
                            * المكدّس يُخفى الرأس كلّه — فلولا هذه التسمية لَقُرئت
                            * القيمة بلا اسمٍ لها. وهي `display:none` على الشاشة
                            * العريضة، فتخرج من شجرة الوصول ويبقى `<th scope>`
                            * هو المصدر الوحيد. مصدرٌ واحد في كلّ حال.
                            */}
                          <span className={styles.cellLabel}>{column.header}</span>
                          <span className={styles.cellValue}>{column.cell(row)}</span>
                        </td>
                      ))}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </>
      )}

      {/* ───── التصفّح ───── */}
      {pagination !== undefined && pagination.total > 0 ? (
        <nav className={styles.pager} aria-label="تصفّح الصفحات">
          <p className={styles.pagerText} role="status">
            <bdi dir="ltr">
              {count(firstRow)}–{count(lastRow)}
            </bdi>{' '}
            من <bdi dir="ltr">{count(pagination.total)}</bdi>
            <span className={styles.pagerPage}>
              {' '}
              · صفحة <bdi dir="ltr">{count(pagination.page)}</bdi> من{' '}
              <bdi dir="ltr">{count(lastPage)}</bdi>
            </span>
          </p>
          <div className={styles.pagerButtons}>
            <button
              type="button"
              className={styles.pagerButton}
              disabled={pagination.page <= 1}
              onClick={() => pagination.onPage(pagination.page - 1)}
            >
              <Icon name="chevron" size={14} flip />
              السابق
            </button>
            <button
              type="button"
              className={styles.pagerButton}
              disabled={pagination.page >= lastPage}
              onClick={() => pagination.onPage(pagination.page + 1)}
            >
              التالي
              <Icon name="chevron" size={14} />
            </button>
          </div>
        </nav>
      ) : null}
    </div>
  );
}
