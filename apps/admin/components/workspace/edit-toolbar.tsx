'use client';
// UNIT: EDIT-TOOLBAR
// FILE: edit-toolbar.tsx
// STYLE: workspace.module.css
// PARENT: PAGE-DASHBOARD
// USES: LIB-DASHBOARD-LAYOUT, WS-ICONS
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * شريط «تحرير اللوحة» — القالب والنمط والالتقاط والتراجع والمخفيّ.
 *
 * خارج التحرير زرٌّ واحد (وسطر حالة الحفظ). وفيه تظهر الأدوات، وتعمل
 * اختصارات التراجع والإعادة (⌘/Ctrl+Z · Shift+Z · Ctrl+Y) — خارجه لا تخطف
 * اختصارًا يملكه حقلٌ أو المتصفّح.
 */

import { useEffect, useId, useRef, useState } from 'react';
import {
  DASHBOARD_PRESETS,
  applyPreset,
  setHidden,
  type DashboardLayoutApi,
  type DashboardMode,
  type DashboardPresetId,
  type SyncStatus,
  type WidgetState,
} from '../../lib/dashboard-layout';
import { WsIcon } from './ws-icons';
import styles from './workspace.module.css';

const SYNC_TEXT: Record<SyncStatus, string> = {
  idle: 'محفوظٌ على هذا الجهاز',
  saving: 'يُحفظ في حسابك…',
  saved: 'محفوظٌ في حسابك',
  offline: 'بلا اتّصال — محفوظٌ محلّيًّا ويُرفع حين يعود',
  error: 'تعذّر الحفظ في الحساب — محفوظٌ محلّيًّا',
};

interface Props {
  layout: DashboardLayoutApi;
  editing: boolean;
  onEditingChange: (next: boolean) => void;
  /** هل يتّسع العرض للتحرير؟ — وإلّا يُعطَّل الزرّ بسببه. */
  editable: boolean;
  titleOf: (id: string, state: WidgetState) => string;
}

export function EditToolbar({ layout, editing, onEditingChange, editable, titleOf }: Props) {
  const { doc, update, undo, redo, canUndo, canRedo, sync } = layout;
  const presetId = useId();
  const reasonId = useId();

  useEffect(() => {
    if (!editing) return;
    const onKey = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      if (target !== null && target.closest('input, textarea, select, [contenteditable="true"]') !== null) return;
      const mod = event.metaKey || event.ctrlKey;
      if (!mod) return;
      const key = event.key.toLowerCase();
      if (key === 'z' && !event.shiftKey) {
        event.preventDefault();
        undo();
      } else if ((key === 'z' && event.shiftKey) || key === 'y') {
        event.preventDefault();
        redo();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [editing, undo, redo]);

  const hidden = Object.entries(doc.widgets).filter(([, s]) => s.hidden);
  const setMode = (mode: DashboardMode) => update((d) => ({ ...d, mode }));

  return (
    <div className={styles.toolbar} role="toolbar" aria-label="تحرير اللوحة" data-editing={editing ? '' : undefined}>
      <button
        type="button"
        className={styles.toolBtn}
        data-primary={editing ? '' : undefined}
        aria-pressed={editing}
        aria-disabled={!editable && !editing ? 'true' : undefined}
        aria-describedby={!editable ? reasonId : undefined}
        onClick={() => {
          if (!editable && !editing) return;
          onEditingChange(!editing);
        }}
      >
        <WsIcon name="edit" />
        {editing ? 'تمّ' : 'تحرير اللوحة'}
      </button>
      {!editable ? (
        <small id={reasonId} className={styles.toolNote}>التحرير يحتاج عرضًا أوسع (اثنا عشر عمودًا).</small>
      ) : null}

      {editing ? (
        <>
          <label className={styles.toolField} htmlFor={presetId}>
            <span>القالب</span>
            <select
              id={presetId}
              className={styles.select}
              value={doc.preset}
              onChange={(e) => update((d) => applyPreset(d, e.target.value as DashboardPresetId))}
            >
              {DASHBOARD_PRESETS.map((p) => (
                <option key={p.id} value={p.id}>{p.label}</option>
              ))}
            </select>
          </label>

          <div className={styles.segmented} role="group" aria-label="نمط التخطيط">
            <button type="button" className={styles.segment} aria-pressed={doc.mode === 'grid'} onClick={() => setMode('grid')}>
              شبكة
            </button>
            <button type="button" className={styles.segment} aria-pressed={doc.mode === 'canvas'} onClick={() => setMode('canvas')}>
              لوحة حرّة
            </button>
          </div>

          <button
            type="button"
            className={styles.toolBtn}
            aria-pressed={doc.mode === 'grid' ? true : doc.snap}
            aria-disabled={doc.mode === 'grid' ? 'true' : undefined}
            title={doc.mode === 'grid' ? 'الشبكة تلتقط دائمًا إلى أعمدتها' : undefined}
            onClick={() => {
              if (doc.mode === 'grid') return;
              update((d) => ({ ...d, snap: !d.snap }));
            }}
          >
            <WsIcon name="magnet" />
            التقاط ٨ بكسل
          </button>

          <div className={styles.toolGroup}>
            <button type="button" className={styles.iconTool} aria-label="تراجع (Ctrl/⌘+Z)" aria-disabled={!canUndo ? 'true' : undefined} onClick={() => canUndo && undo()}>
              <WsIcon name="undo" />
            </button>
            <button type="button" className={styles.iconTool} aria-label="إعادة (Shift+Ctrl/⌘+Z)" aria-disabled={!canRedo ? 'true' : undefined} onClick={() => canRedo && redo()}>
              <WsIcon name="redo" />
            </button>
          </div>

          <HiddenMenu
            items={hidden.map(([id, s]) => ({ id, title: titleOf(id, s) }))}
            onShow={(id) => update((d) => setHidden(d, id, false))}
          />

          <button type="button" className={styles.toolBtn} onClick={() => layout.reset()}>
            استعادة الافتراضيّ
          </button>
        </>
      ) : null}

      <span className={styles.syncNote} role="status" data-state={sync}>
        {SYNC_TEXT[sync]}
      </span>
    </div>
  );
}

function HiddenMenu({ items, onShow }: { items: { id: string; title: string }[]; onShow: (id: string) => void }) {
  const [open, setOpen] = useState(false);
  const panelId = useId();
  const buttonRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    panelRef.current?.querySelector<HTMLElement>('button')?.focus();
    const onDown = (event: PointerEvent) => {
      if (panelRef.current?.contains(event.target as Node) || buttonRef.current?.contains(event.target as Node)) return;
      setOpen(false);
    };
    document.addEventListener('pointerdown', onDown);
    return () => document.removeEventListener('pointerdown', onDown);
  }, [open]);

  return (
    <div className={styles.menuWrap}>
      <button
        ref={buttonRef}
        type="button"
        className={styles.toolBtn}
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((v) => !v)}
      >
        <WsIcon name="eye" />
        المخفيّة <bdi dir="ltr">({items.length})</bdi>
      </button>
      {open ? (
        <div
          ref={panelRef}
          id={panelId}
          className={styles.menu}
          role="dialog"
          aria-label="البطاقات المخفيّة"
          onKeyDown={(event) => {
            if (event.key === 'Escape') {
              setOpen(false);
              buttonRef.current?.focus();
            }
          }}
        >
          {items.length === 0 ? (
            <p className={styles.menuReason}>كلّ البطاقات ظاهرة. أخفِ بطاقةً من قائمتها لتظهر هنا.</p>
          ) : (
            items.map((item) => (
              <div key={item.id} className={styles.menuRow}>
                <button
                  type="button"
                  className={styles.menuItem}
                  onClick={() => {
                    onShow(item.id);
                    if (items.length === 1) {
                      setOpen(false);
                      buttonRef.current?.focus();
                    }
                  }}
                >
                  أظهر «{item.title}»
                </button>
              </div>
            ))
          )}
        </div>
      ) : null}
    </div>
  );
}
