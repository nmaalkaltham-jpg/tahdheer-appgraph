'use client';
// UNIT: RANGE-BAR
// FILE: range-bar.tsx
// STYLE: range-bar.module.css, shared.module.css
// PARENT: OVERVIEW, SEARCH-FUNNEL
// USES: SHARED-ICONS, UI
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * شريط المدى والمقارنة.
 *
 * الشريط نفسه في كل تبويب، وصدقه في اختلاف حالته: حيث يوجد وسيط زمني حقيقي
 * على المسار (‏days على /admin/search/queries) تعمل الأزرار فعلًا؛ وحيث لا
 * يوجد (‏/admin/dashboard يجمع كل الصفوف بلا تاريخ) تظهر معطّلة وبجانبها
 * سبب التعطيل مكتوبًا — لا زرّ يوهم بتصفية لا تحدث.
 */

import type { ReactNode } from 'react';
import { Icon } from './icons';
import { Segmented, type SegmentOption } from './ui';
import bar from './range-bar.module.css';
import s from './shared.module.css';

export interface CompareControl {
  pressed: boolean;
  label: string;
  /** وجوده يعطّل الزرّ ويعلن السبب. */
  disabledReason?: string;
  onToggle?: () => void;
}

export function RangeBar({
  label,
  options,
  value,
  onChange,
  scope,
  compare,
  actions,
  reason,
}: {
  label: string;
  options: SegmentOption[];
  value: string;
  onChange: (id: string) => void;
  scope: string;
  compare?: CompareControl;
  actions?: ReactNode;
  /** سطر يشرح ما لا يعمل في هذا الشريط ولماذا. */
  reason?: string;
}) {
  return (
    <div className={bar.bar}>
      <span className={bar.label}>{label}</span>
      <Segmented label={label} options={options} value={value} onChange={onChange} />

      {compare ? (
        <>
          <span aria-hidden="true" className={bar.divider} />
          <button
            type="button"
            className={[s.btn, s.btnCompact, compare.pressed ? s.btnPrimary : '']
              .filter(Boolean)
              .join(' ')}
            aria-pressed={compare.pressed}
            disabled={compare.disabledReason !== undefined}
            title={compare.disabledReason}
            onClick={compare.onToggle}
          >
            <Icon name="chart" size={12} strokeWidth={2.2} />
            {compare.label}
          </button>
        </>
      ) : null}

      <span className={bar.spacer} />
      <span className={bar.scope}>{scope}</span>
      {actions}
      {reason ? <p className={bar.reason}>{reason}</p> : null}
    </div>
  );
}
