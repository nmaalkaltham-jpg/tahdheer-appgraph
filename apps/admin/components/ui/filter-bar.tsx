'use client';
// UNIT: FILTER-BAR
// FILE: filter-bar.tsx
// STYLE: filter-bar.module.css
// PARENT: LIST-PANEL, PAGE-CUSTOMERS, PAGE-OPERATIONS-INVENTORY, PAGE-ORDERS, SHIPMENTS-PANEL
// USES: COMPONENTS-ICONS, LIB-FORMAT
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * شريط المرشّحات — بحثٌ وتبويباتُ حالةٍ ومرشّحاتٌ مُعلنة.
 *
 * البحث مؤجَّل (`DEBOUNCE_MS`) ويعيش في الرابط: الكتابة لا تُرسل طلبًا لكلّ
 * حرف، والنتيجة قابلةٌ للمشاركة.
 */

import { Icon } from '../icons';
import { count } from '../../lib/format';
import styles from './filter-bar.module.css';

interface FilterTab {
  id: string;
  label: string;
  /** عددٌ مقيس، أو `undefined` حين لا يُقاس — ولا يُعرض صفرًا مخترعًا. */
  total?: number;
}

interface FilterSelect {
  key: string;
  label: string;
  options: { value: string; label: string }[];
}

export function FilterBar({
  searchPlaceholder,
  draftQuery,
  onQuery,
  tabs,
  activeTab,
  onTab,
  selects,
  values,
  onSelect,
  onClear,
  hasFilters,
  actions,
}: {
  searchPlaceholder: string;
  draftQuery: string;
  onQuery: (value: string) => void;
  tabs?: readonly FilterTab[];
  activeTab?: string | null;
  onTab?: (id: string | null) => void;
  selects?: readonly FilterSelect[];
  values?: Readonly<Record<string, string>>;
  onSelect?: (key: string, value: string | null) => void;
  onClear?: () => void;
  hasFilters?: boolean;
  actions?: React.ReactNode;
}) {
  return (
    <div className={styles.bar}>
      {tabs !== undefined && tabs.length > 0 ? (
        <div className={styles.tabs} role="tablist" aria-label="تصفية بالحالة">
          <button
            type="button"
            role="tab"
            className={styles.tab}
            aria-selected={activeTab === null || activeTab === undefined}
            onClick={() => onTab?.(null)}
          >
            الكلّ
          </button>
          {tabs.map((tab) => (
            <button
              key={tab.id}
              type="button"
              role="tab"
              className={styles.tab}
              aria-selected={activeTab === tab.id}
              onClick={() => onTab?.(tab.id)}
            >
              {tab.label}
              {tab.total !== undefined ? (
                <bdi dir="ltr" className={styles.tabCount}>
                  {count(tab.total)}
                </bdi>
              ) : null}
            </button>
          ))}
        </div>
      ) : null}

      <div className={styles.controls}>
        <label className={styles.search}>
          <Icon name="search" size={16} />
          <span className="sr-only">{searchPlaceholder}</span>
          <input
            type="search"
            value={draftQuery}
            placeholder={searchPlaceholder}
            onChange={(e) => onQuery(e.target.value)}
          />
        </label>

        {(selects ?? []).map((select) => (
          <label key={select.key} className={styles.select}>
            <span>{select.label}</span>
            <select
              value={values?.[select.key] ?? ''}
              onChange={(e) => onSelect?.(select.key, e.target.value === '' ? null : e.target.value)}
            >
              <option value="">الكلّ</option>
              {select.options.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>
        ))}

        {hasFilters === true && onClear !== undefined ? (
          <button type="button" className={styles.clear} onClick={onClear}>
            <Icon name="close" size={14} />
            مسح المرشّحات
          </button>
        ) : null}

        {actions !== undefined ? <div className={styles.actions}>{actions}</div> : null}
      </div>
    </div>
  );
}
