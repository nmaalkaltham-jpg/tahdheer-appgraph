'use client';
// UNIT: WIDGET-FRAME
// FILE: widget-frame.tsx
// STYLE: workspace.module.css
// PARENT: WORKSPACE
// USES: WS-ICONS
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * إطار البطاقة — ما يظهر حولها في وضع التحرير وحده.
 *
 * خارج التحرير الإطار غلافٌ بلا زخرفة: البطاقة كما كانت. وفي التحرير يعلوه
 * شريطٌ رقيق: مقبضٌ يحمل الاسم (يُسحب بالمؤشّر ويُلتقط بالمسافة)، وقفل،
 * وقائمة أفعال. والمحتوى `inert`: لا نقرةَ تفتح رابطًا في منتصف ترتيب، ولا
 * يمرّ التبويب عبر عشرات الروابط الداخليّة — مقابض البطاقات وحدها.
 *
 * ─── لماذا قائمةٌ لا أزرارٌ مصفوفة ───
 *
 * بطاقة مؤشّرٍ بعرض عمودين ≈ ١٧٦ بكسل. خمسة أزرار بـ٤٤ بكسل لا تتّسع دون أن
 * تغطّي الاسم. المقبض والقفل ظاهران لأنّهما الأكثر استعمالًا؛ والبقيّة في
 * قائمة — والمعطّل منها يقول لماذا نصًّا لا تلميحًا.
 */

import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { WsIcon } from './ws-icons';
import styles from './workspace.module.css';

export interface WidgetMenuAction {
  key: string;
  label: string;
  /** سبب التعطيل — يُكتب تحت البند. */
  disabled?: string;
  onSelect?: () => void;
  /** بندٌ مخصّص (اختيار مقياس) بدل زرّ. */
  render?: () => ReactNode;
}

type ResizeAxis = 'resize-both' | 'resize-inline' | 'resize-block';

interface Props {
  id: string;
  style: React.CSSProperties;
  title: string;
  editing: boolean;
  locked: boolean;
  lifted: string | null;
  actions: WidgetMenuAction[];
  resizable: boolean;
  onGripPointerDown: (event: React.PointerEvent<HTMLElement>) => void;
  onResizePointerDown: (event: React.PointerEvent<HTMLElement>, axis: ResizeAxis) => void;
  onGripKeyDown: (event: React.KeyboardEvent<HTMLElement>) => void;
  onLock: () => void;
  children: ReactNode;
}

export function WidgetFrame({
  id,
  style,
  title,
  editing,
  locked,
  lifted,
  actions,
  resizable,
  onGripPointerDown,
  onResizePointerDown,
  onGripKeyDown,
  onLock,
  children,
}: Props) {
  const titleId = useId();

  return (
    <div
      className={styles.cell}
      data-widget={id}
      data-lifted={lifted ?? undefined}
      data-locked={locked ? '' : undefined}
      style={style}
      role={editing ? 'group' : undefined}
      aria-labelledby={editing ? titleId : undefined}
    >
      {editing ? (
        <div className={styles.frameBar}>
          <button
            type="button"
            className={styles.grip}
            data-grip=""
            aria-describedby={`${titleId}-hint`}
            aria-pressed={lifted === 'keyboard'}
            onPointerDown={onGripPointerDown}
            onKeyDown={onGripKeyDown}
          >
            <WsIcon name="grip" size={14} />
            <span id={titleId}>{title}</span>
          </button>
          <span id={`${titleId}-hint`} hidden>
            {locked ? 'مقفلة — لا تتحرّك ولا يتغيّر حجمها.' : 'مسافة لالتقاطها، ثمّ الأسهم للتحريك، وShift مع الأسهم للحجم.'}
          </span>
          <button
            type="button"
            className={`${styles.frameBtn} ${styles.lockBtn}`}
            aria-pressed={locked}
            aria-label={locked ? `فكّ قفل ${title}` : `اقفل ${title}`}
            onClick={onLock}
          >
            <WsIcon name={locked ? 'lock' : 'unlock'} size={14} />
          </button>
          <WidgetMenu
            title={title}
            actions={[
              /* القفل في القائمة أيضًا: البطاقة الضيّقة تُخفي زرّه لتتّسع للاسم. */
              { key: 'lock', label: locked ? 'فكّ القفل' : 'قفل الموضع والحجم', onSelect: onLock },
              ...actions,
            ]}
          />
        </div>
      ) : null}

      <div className={styles.body} inert={editing}>
        {children}
      </div>

      {editing && resizable ? (
        <>
          <span className={styles.resize} data-axis="inline" aria-hidden="true" onPointerDown={(e) => onResizePointerDown(e, 'resize-inline')} />
          <span className={styles.resize} data-axis="block" aria-hidden="true" onPointerDown={(e) => onResizePointerDown(e, 'resize-block')} />
          <span className={styles.resize} data-axis="both" aria-hidden="true" onPointerDown={(e) => onResizePointerDown(e, 'resize-both')} />
        </>
      ) : null}
    </div>
  );
}

function WidgetMenu({ title, actions }: { title: string; actions: WidgetMenuAction[] }) {
  const [open, setOpen] = useState(false);
  const menuId = useId();
  const buttonRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    panelRef.current?.querySelector<HTMLElement>('button:not([aria-disabled="true"]), select')?.focus();
    const onDown = (event: PointerEvent) => {
      if (panelRef.current?.contains(event.target as Node) || buttonRef.current?.contains(event.target as Node)) return;
      setOpen(false);
    };
    document.addEventListener('pointerdown', onDown);
    return () => document.removeEventListener('pointerdown', onDown);
  }, [open]);

  const close = () => {
    setOpen(false);
    buttonRef.current?.focus();
  };

  return (
    <div className={styles.menuWrap}>
      <button
        ref={buttonRef}
        type="button"
        className={styles.frameBtn}
        aria-label={`إعدادات ${title}`}
        aria-expanded={open}
        aria-controls={menuId}
        onClick={() => setOpen((v) => !v)}
      >
        <WsIcon name="more" size={16} />
      </button>
      {open ? (
        <div
          ref={panelRef}
          id={menuId}
          className={styles.menu}
          role="dialog"
          aria-label={`إعدادات ${title}`}
          onKeyDown={(event) => {
            if (event.key === 'Escape') {
              event.stopPropagation();
              close();
            }
          }}
        >
          {actions.map((action) =>
            action.render !== undefined ? (
              <div key={action.key} className={styles.menuField}>
                {action.render()}
              </div>
            ) : (
              <div key={action.key} className={styles.menuRow}>
                <button
                  type="button"
                  className={styles.menuItem}
                  aria-disabled={action.disabled !== undefined ? 'true' : undefined}
                  aria-describedby={action.disabled !== undefined ? `${menuId}-${action.key}` : undefined}
                  onClick={() => {
                    if (action.disabled !== undefined) return;
                    action.onSelect?.();
                    close();
                  }}
                >
                  {action.label}
                </button>
                {action.disabled !== undefined ? (
                  <small id={`${menuId}-${action.key}`} className={styles.menuReason}>{action.disabled}</small>
                ) : null}
              </div>
            ),
          )}
        </div>
      ) : null}
    </div>
  );
}
