// UNIT: COHORT-GRID
// FILE: cohort-grid.tsx
// STYLE: shared.module.css
// PARENT: COHORTS
// USES: LIB-FORMAT
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * شبكة الأفواج — الصفّ فوج، والعمود شهر بعد الشراء الأول.
 *
 * القاعدة المُلزِمة: null ليس صفرًا. الخلية التي لم يمرّ شهرها بعد على الفوج
 * تُعرَض «—»، لأن صفرًا في خلية غير مرصودة يُقرأ تسرّبًا كاملًا وهو في
 * الحقيقة غياب رصد — وهذه أشهر أكاذيب جداول الأفواج.
 *
 * الشبكة مبنية ومحايدة للبيانات: حين يوجد مصدر أفواج تُمرَّر إليها الصفوف
 * فتكون صحيحة من أول يوم بلا تعديل.
 */

import { count } from '../../../../lib/format';
import s from './shared.module.css';

export interface CohortRow {
  id: string;
  label: string;
  /** حجم الفوج، أو null إن لم يُرصَد. */
  size: number | null;
  /** خلية لكل عمود: رقم مرصود أو null لغير المرصود. الطول = عدد الأعمدة. */
  cells: (number | null)[];
}

export interface CohortGridProps {
  columns: string[];
  rows: CohortRow[];
  caption: string;
  rowHeader: string;
  sizeHeader: string;
  /** تحويل القيمة المرصودة إلى نصّ الخلية (نسبة، مبلغ، عدد…). */
  formatCell: (value: number, columnIndex: number) => string;
  /** نص الخلية غير المرصودة عند التمرير. */
  nullHint: string;
  /** يُعرَض بدل الصفوف حين لا يوجد أي فوج مرصود. */
  emptyMessage: string;
}

function bandClass(value: number, columnIndex: number): string {
  if (columnIndex === 0) return s.cohortCellBase ?? '';
  if (value >= 45) return s.cohortCellOk ?? '';
  if (value >= 28) return s.cohortCellWarn ?? '';
  return s.cohortCellBad ?? '';
}

export function CohortGrid({
  columns,
  rows,
  caption,
  rowHeader,
  sizeHeader,
  formatCell,
  nullHint,
  emptyMessage,
}: CohortGridProps) {
  return (
    <div className={s.cohortScroll}>
      <table className={s.cohortTable}>
        <caption>{caption}</caption>
        <thead>
          <tr>
            <th scope="col">{rowHeader}</th>
            <th scope="col">{sizeHeader}</th>
            {columns.map((column) => (
              <th scope="col" className={s.cohortColHead} key={column}>
                <bdi dir="ltr">{column}</bdi>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td className={s.cohortEmpty} colSpan={columns.length + 2}>
                {emptyMessage}
              </td>
            </tr>
          ) : (
            rows.map((row) => (
              <tr key={row.id}>
                <th scope="row" className={s.cohortRowHead}>
                  {row.label}
                </th>
                <td className={s.cohortSize}>
                  {row.size === null ? (
                    <span title={nullHint}>—</span>
                  ) : (
                    <bdi dir="ltr">{count(row.size)}</bdi>
                  )}
                </td>
                {columns.map((column, index) => {
                  const value = row.cells[index] ?? null;
                  if (value === null) {
                    return (
                      <td className={s.cohortCellWrap} key={column}>
                        <span
                          className={[s.cohortCell, s.cohortCellNull].filter(Boolean).join(' ')}
                          title={nullHint}
                        >
                          —
                        </span>
                      </td>
                    );
                  }
                  return (
                    <td className={s.cohortCellWrap} key={column}>
                      <span className={[s.cohortCell, bandClass(value, index)].filter(Boolean).join(' ')}>
                        <bdi dir="ltr">{formatCell(value, index)}</bdi>
                      </span>
                    </td>
                  );
                })}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}
