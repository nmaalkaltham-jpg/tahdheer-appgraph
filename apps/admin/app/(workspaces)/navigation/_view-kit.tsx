// UNIT: _VIEW-KIT
// FILE: _view-kit.tsx
// STYLE: views.module.css
// PARENT: VIEWS, _VIEW-MAP
// USES: MODEL, NAVIGATION-ICONS
// API: none
// MAP: docs/PROJECT_MAP.md

import { useEffect, useRef, useState } from 'react';
import { Icon } from './icons';
import { type Issue, type MenuNode } from './model';
import styles from './views.module.css';

export type DropPosition = 'before' | 'after' | 'inside';

export interface StudioCommands {
  select(id: string, mode: 'single' | 'toggle'): void;
  clearSelection(): void;
  beginRename(id: string): void;
  commitRename(id: string, label: string): void;
  cancelRename(): void;
  addChild(id: string): void;
  insertAfter(id: string | null): void;
  duplicate(id: string): void;
  move(id: string, delta: number): void;
  nest(id: string): void;
  unnest(id: string): void;
  remove(id: string): void;
  restore(id: string): void;
  toggleCollapse(id: string): void;
  drop(dragId: string, targetId: string | null, position: DropPosition): void;
}

export interface ViewProps {
  nodes: MenuNode[];
  selection: string[];
  collapsed: Set<string>;
  editingId: string | null;
  issuesByNode: Map<string, Issue[]>;
  query: string;
  commands: StudioCommands;
}

/* ═══════════════ أدوات مشتركة ═══════════════ */

export const SEVERITY_CLASS = {
  error: styles.sevError,
  warn: styles.sevWarn,
  info: styles.sevInfo,
} as const;

export const STAGE_CLASS = {
  desktop: styles.stageDesktop,
  tablet: styles.stageTablet,
  mobile: styles.stageMobile,
} as const;

export function severityOf(issues: Issue[] | undefined): 'error' | 'warn' | 'info' | null {
  if (!issues || issues.length === 0) return null;
  if (issues.some((issue) => issue.severity === 'error')) return 'error';
  if (issues.some((issue) => issue.severity === 'warn')) return 'warn';
  return 'info';
}

export function matchesQuery(node: MenuNode, query: string): boolean {
  const needle = query.trim().toLowerCase();
  if (needle === '') return false;
  return `${node.label} ${node.url}`.toLowerCase().includes(needle);
}

/** لوحة أزرار النقل — المكافئ الكامل للسحب بلوحة المفاتيح. */
export function MoveButtons({
  node,
  commands,
  compact,
}: {
  node: MenuNode;
  commands: StudioCommands;
  compact?: boolean;
}) {
  const size = compact ? 11 : 12;
  const cls = compact ? styles.iconBtnSm : styles.iconBtn;
  return (
    <>
      <button
        type="button"
        className={cls}
        onClick={() => commands.move(node.id, -1)}
        title="تحريك للأعلى بين الأشقاء"
      >
        <span className={styles.srOnly}>تحريك «{node.label || 'عنصر بلا نص'}» للأعلى</span>
        <span className={styles.rotUp} aria-hidden="true">
          <Icon name="chev-r" size={size} />
        </span>
      </button>
      <button
        type="button"
        className={cls}
        onClick={() => commands.move(node.id, 1)}
        title="تحريك للأسفل بين الأشقاء"
      >
        <span className={styles.srOnly}>تحريك «{node.label || 'عنصر بلا نص'}» للأسفل</span>
        <span className={styles.rotDown} aria-hidden="true">
          <Icon name="chev-r" size={size} />
        </span>
      </button>
      <button
        type="button"
        className={cls}
        onClick={() => commands.nest(node.id)}
        title="تعشيش تحت العنصر السابق"
      >
        <span className={styles.srOnly}>تعشيش «{node.label || 'عنصر بلا نص'}» تحت السابق</span>
        <Icon name="chev-l" size={size} />
      </button>
      <button
        type="button"
        className={cls}
        onClick={() => commands.unnest(node.id)}
        title="إخراج من الأب"
      >
        <span className={styles.srOnly}>إخراج «{node.label || 'عنصر بلا نص'}» من أبيه</span>
        <Icon name="chev-r" size={size} />
      </button>
    </>
  );
}

/** حقل التسمية الموضعي — يلتقط التركيز عند الفتح ويلتزم بـ Enter ويلغي بـ Escape. */
export function RenameField({
  node,
  commands,
  className,
}: {
  node: MenuNode;
  commands: StudioCommands;
  className: string | undefined;
}) {
  const [value, setValue] = useState(node.label);
  const ref = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    ref.current?.focus();
    ref.current?.select();
  }, []);

  return (
    <input
      ref={ref}
      type="text"
      className={className}
      value={value}
      aria-label="نص التنقل"
      onChange={(event) => setValue(event.target.value)}
      onKeyDown={(event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          commands.commitRename(node.id, value);
        } else if (event.key === 'Escape') {
          event.preventDefault();
          commands.cancelRename();
        }
      }}
      onBlur={() => commands.commitRename(node.id, value)}
    />
  );
}
