'use client';
// UNIT: EXPORT-CSV
// FILE: export-csv.tsx
// STYLE: none
// PARENT: LIST-PANEL, PAGE-CUSTOMERS, PAGE-ORDERS, PAGE-PROMOTIONS, PAGE-REVIEWS
// USES: LIB-ADMIN-API, LIB-EXPORT-CSV
// API: none
// API-VIA: LIB-EXPORT-CSV
// MAP: docs/PROJECT_MAP.md

/**
 * زرّ تصدير CSV — يعمل على أحد نمطَي مصدر، وكلاهما حقيقي.
 *
 * `paged`: يُرقّم الصفحات بنفسه على نقطة النهاية نفسها التي تعرضها الشاشة،
 * بمرشّحاتها كما هي. فالمُصدَّر **هو ما تراه على الشاشة**، لا كل الجدول: من
 * رشّح على «مدفوع» يتوقّع ملفًّا بالمدفوع وحده.
 *
 * `rows`: للشاشات التي لا تُرقّم نقاطها أصلًا (المراجعات، القسائم) — الصفوف
 * المحمَّلة هي كلّ الصفوف، فلا داعي لنداء ثانٍ.
 *
 * ─── لماذا العدّاد والإلغاء ───
 *
 * الترقيم ≤١٠٠ صفًّا للنداء، فكتالوج بألفَي منتج = عشرون نداءً متتابعًا. زرٌّ
 * يجمد بلا خبر يبدو معطّلًا فيُنقر ثانيةً — فيُعرض التقدّم مقيسًا (المجموع من
 * `meta.total` الخادم لا تقديرًا)، ويبقى الإلغاء متاحًا.
 *
 * ولا تصدير جزئي صامت: أيّ صفحة تفشل تُلغي العملية برسالة الخادم كما هي.
 * ملفٌّ ناقص بلا إنذار أسوأ من لا ملفّ — يُتَّخذ قرارٌ على بيانات مبتورة.
 */

import { useRef, useState } from 'react';
import { api, firstError, type Paginated } from '../lib/admin-api';
import { downloadCsv, toCsv, withParams, type CsvColumn } from '../lib/export-csv';

/** ١٠٠ هو سقف `perPage` في تحقّق الخادم — تجاوزه يردّ ٤٢٢ لا صفحةً أكبر. */
const PAGE_SIZE = 100;

type CsvSource<T> =
  | { kind: 'paged'; path: string; total: number }
  | { kind: 'rows'; rows: readonly T[] };

export function ExportCsv<T>({
  filename,
  columns,
  source,
  label = 'تصدير CSV',
}: {
  filename: string;
  columns: readonly CsvColumn<T>[];
  source: CsvSource<T>;
  label?: string;
}) {
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const cancelled = useRef(false);

  const total = source.kind === 'paged' ? source.total : source.rows.length;

  const run = async () => {
    setError(null);

    if (source.kind === 'rows') {
      downloadCsv(filename, toCsv(columns, source.rows));
      return;
    }

    cancelled.current = false;
    setBusy(true);
    setDone(0);

    const rows: T[] = [];
    let page = 1;
    let lastPage = 1;

    do {
      const result = await api.get<Paginated<T>>(
        withParams(source.path, { page: String(page), perPage: String(PAGE_SIZE) }),
      );
      if (!result.ok) {
        setError(firstError(result));
        setBusy(false);
        return;
      }
      if (cancelled.current) {
        setBusy(false);
        return;
      }
      rows.push(...result.data.data);
      setDone(rows.length);
      lastPage = result.data.meta.lastPage;
      page += 1;
    } while (page <= lastPage);

    setBusy(false);
    downloadCsv(filename, toCsv(columns, rows));
  };

  const empty = total === 0;

  return (
    <span className="export-csv">
      <button
        type="button"
        className="btn btn-ghost"
        onClick={() => void run()}
        disabled={busy || empty}
        title={empty ? 'لا صفوف مطابقة للمرشّح الحالي — لا شيء يُصدَّر.' : undefined}
      >
        {busy ? `جارٍ التصدير… ${done}/${total}` : label}
      </button>

      {busy ? (
        <button
          type="button"
          className="btn btn-ghost"
          onClick={() => {
            cancelled.current = true;
          }}
        >
          إلغاء
        </button>
      ) : null}

      {/* الحالة تُنطق لقارئ الشاشة: الزرّ وحده لا يخبر أن شيئًا يجري. */}
      <span className="sr-only" role="status" aria-live="polite">
        {busy ? `جُمع ${done} من ${total} صفًّا` : ''}
      </span>

      {error !== null ? (
        <span className="export-csv-error" role="alert">
          تعذّر التصدير: {error}
        </span>
      ) : null}
    </span>
  );
}
