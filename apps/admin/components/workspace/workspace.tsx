'use client';
// UNIT: WORKSPACE
// FILE: workspace.tsx
// STYLE: workspace.module.css
// PARENT: PAGE-DASHBOARD
// USES: LIB-DASHBOARD-LAYOUT, WIDGET-FRAME
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * محرّك مساحة العمل — شبكةٌ من اثني عشر عمودًا أو لوحةٌ حرّة، بسحبٍ وتحجيمٍ
 * بالمؤشّر وبلوحة المفاتيح.
 *
 * ─── لماذا لا يُعاد التصيير مع كلّ حركة ───
 *
 * البطاقة المسحوبة تتبع المؤشّر بـ`transform` يُكتب في `requestAnimationFrame`
 * مباشرةً على عنصرها — لا حالة React ولا قراءة تخطيط. وحالة React تتغيّر فقط
 * حين تتغيّر **الخليّة الهدف**، فيُرسم الموضع المقترح (العلامة) وتُفسح الجارات.
 * والبطاقات مذكَّرة (`memo`)، ومحتواها عنصرٌ يصنعه الأب مرّة: الإفساح لا يُعيد
 * رسم مخطّطٍ ولا جدول.
 *
 * ─── لماذا المعاينة محلّيّة لا في المخزن ───
 *
 * المخزن يُعيد تصيير الصفحة كلّها (كلّ الاشتقاقات والمخطّطات). المعاينة تعيش
 * هنا حتّى الإفلات، ثمّ تُسلَّم خطوةً واحدة في التاريخ — فالتراجع يُلغي سحبًا
 * كاملًا لا خليّةً خليّة.
 *
 * ─── الاتّجاه ───
 *
 * `x` منطقيّ يبدأ من بداية السطر (اليمين في العربيّة). حركةُ المؤشّر فيزيائيّة،
 * فتُعكس مرّةً واحدة عند الحساب (`logicalDx`)، والتحويل البصريّ يبقى فيزيائيًّا.
 * ومقابض التحجيم على نهاية السطر وأسفل البطاقة بخصائص منطقيّة.
 */

import { memo, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import {
  CANVAS_SNAP,
  GAP_PX,
  GRID_COLS,
  ROW_PX,
  alignmentGuides,
  clampSize,
  defOf,
  layoutForCols,
  moveWidget,
  nudgeWidget,
  resizeWidget,
  snapTo,
  type CanvasRect,
  type DashboardDoc,
  type DashboardLayoutApi,
  type WidgetState,
} from '../../lib/dashboard-layout';
import { WidgetFrame, type WidgetMenuAction } from './widget-frame';
import styles from './workspace.module.css';

type Kind = 'move' | 'resize-both' | 'resize-inline' | 'resize-block';

interface Box {
  id: string;
  x: number;
  y: number;
  w: number;
  h: number;
}

interface PointerDrag {
  id: string;
  kind: Kind;
  pointerId: number;
  startX: number;
  startY: number;
  startScroll: number;
  lastX: number;
  lastY: number;
  el: HTMLElement;
  origin: WidgetState;
  originRect: CanvasRect;
}

interface KeyDrag {
  id: string;
  before: DashboardDoc;
}

export interface WorkspaceProps {
  layout: DashboardLayoutApi;
  editing: boolean;
  /** محتوى كلّ بطاقة — عنصرٌ يصنعه الأب مرّةً لكلّ تصيير له، لا في كلّ حركة. */
  content: Readonly<Record<string, ReactNode>>;
  titleOf: (id: string, state: WidgetState) => string;
  /** أفعال القائمة لكلّ بطاقة (إخفاء، تكرار، إعدادات…) — يملكها الأب. */
  actionsOf: (id: string, state: WidgetState) => WidgetMenuAction[];
  /** يُبلَّغ الأب هل يتّسع العرض للتحرير (اثنا عشر عمودًا). */
  onEditableChange?: (editable: boolean) => void;
}

function logicalDx(dx: number, rtl: boolean): number {
  return rtl ? -dx : dx;
}

function scrollParent(el: HTMLElement): HTMLElement | Window {
  for (let node = el.parentElement; node !== null; node = node.parentElement) {
    const overflow = getComputedStyle(node).overflowY;
    if ((overflow === 'auto' || overflow === 'scroll') && node.scrollHeight > node.clientHeight) return node;
  }
  return window;
}

function scrollTopOf(target: HTMLElement | Window): number {
  return target instanceof Window ? target.scrollY : target.scrollTop;
}

function colsFor(width: number): number {
  if (width >= 900) return GRID_COLS;
  if (width >= 560) return 6;
  return 1;
}

export function Workspace({ layout, editing, content, titleOf, actionsOf, onEditableChange }: WorkspaceProps) {
  const { doc, update } = layout;
  const rootRef = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(0);
  const [rtl, setRtl] = useState(true);
  const [preview, setPreview] = useState<Record<string, WidgetState> | null>(null);
  const [canvasPreview, setCanvasPreview] = useState<Record<string, CanvasRect> | null>(null);
  const [activeState, setActive] = useState<{ id: string; kind: Kind | 'keyboard' } | null>(null);
  const [guides, setGuides] = useState<{ x: number[]; y: number[] }>({ x: [], y: [] });
  const [announcement, setAnnouncement] = useState('');
  const dragRef = useRef<PointerDrag | null>(null);
  const keyRef = useRef<KeyDrag | null>(null);
  const frameRef = useRef<number | null>(null);
  const scrollerRef = useRef<HTMLElement | Window | null>(null);
  const refocusRef = useRef<string | null>(null);
  const lastCellRef = useRef('');

  const density = doc.density;
  const row = ROW_PX[density];
  const gap = GAP_PX[density];
  const cols = width === 0 ? GRID_COLS : colsFor(width);
  const editable = cols === GRID_COLS;
  const canEdit = editing && editable;
  const mode = editable ? doc.mode : 'grid';
  /* خارج التحرير لا حمل — مشتقٌّ لا حالةٌ تُصفَّر في أثر. */
  const active = canEdit ? activeState : null;

  useLayoutEffect(() => {
    const el = rootRef.current;
    if (el === null) return;
    setRtl(getComputedStyle(el).direction === 'rtl');
    const observer = new ResizeObserver(([entry]) => {
      if (entry !== undefined) setWidth(Math.round(entry.contentRect.width));
    });
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    onEditableChange?.(editable);
  }, [editable, onEditableChange]);

  const colStep = width === 0 ? 0 : (width + gap) / cols;
  const rowStep = row + gap;

  const widgets = preview ?? doc.widgets;

  /* المستطيل الحرّ: المحفوظ، وإلّا المشتقّ من الشبكة — نقطة بدءٍ لا تُحفظ حتّى تُعدَّل. */
  const rectOf = useCallback(
    (id: string, source: Record<string, CanvasRect> = canvasPreview ?? doc.canvas): CanvasRect => {
      const saved = source[id];
      if (saved !== undefined) return saved;
      const s = doc.widgets[id];
      if (s === undefined || colStep === 0) return { x: 0, y: 0, w: 320, h: 240 };
      return {
        x: snapTo(s.x * colStep),
        y: snapTo(s.y * rowStep),
        w: Math.max(40, snapTo(s.w * colStep - gap)),
        h: Math.max(40, snapTo(s.h * rowStep - gap)),
      };
    },
    [canvasPreview, doc.canvas, doc.widgets, colStep, rowStep, gap],
  );

  const boxes: Box[] = useMemo(() => layoutForCols(widgets, cols), [widgets, cols]);
  /* ترتيب DOM من المحفوظ لا من المعاينة: نقل عقدةٍ في أثناء السحب يُفقدها التركيز. */
  const domOrder = useMemo(
    () =>
      Object.entries(doc.widgets)
        .filter(([, s]) => !s.hidden)
        .sort(([, a], [, b]) => a.y - b.y || a.x - b.x)
        .map(([id]) => id),
    [doc.widgets],
  );
  const boxById = useMemo(() => new Map(boxes.map((b) => [b.id, b] as const)), [boxes]);
  const depth = boxes.reduce((max, b) => Math.max(max, b.y + b.h), 0);

  const canvasHeight = useMemo(() => {
    if (mode !== 'canvas') return 0;
    return domOrder.reduce((max, id) => {
      const r = rectOf(id);
      return Math.max(max, r.y + r.h);
    }, 0) + 160;
  }, [mode, domOrder, rectOf]);

  const describe = useCallback(
    (id: string, s: WidgetState, rect?: CanvasRect) => {
      const title = titleOf(id, s);
      if (mode === 'canvas' && rect !== undefined) {
        return `${title}: الموضع ${rect.x}×${rect.y}، الحجم ${rect.w}×${rect.h} بكسل.`;
      }
      return `${title}: العمود ${s.x + 1} الصفّ ${s.y + 1}، العرض ${s.w} من ${GRID_COLS} والارتفاع ${s.h}.`;
    },
    [mode, titleOf],
  );

  /* ───── السحب بالمؤشّر ───── */

  const tick = useCallback(() => {
    frameRef.current = null;
    const drag = dragRef.current;
    if (drag === null) return;
    const scrollDelta = scrollTopOf(scrollerRef.current ?? window) - drag.startScroll;
    const dx = drag.lastX - drag.startX;
    const dy = drag.lastY - drag.startY + scrollDelta;
    const ldx = logicalDx(dx, rtl);

    /* تمريرٌ آليّ قرب حافّة النافذة — سحبٌ إلى ما تحت الطيّة ممكن. */
    const edge = 56;
    const scroller = scrollerRef.current ?? window;
    if (drag.lastY < edge) scroller.scrollBy(0, -12);
    else if (drag.lastY > window.innerHeight - edge) scroller.scrollBy(0, 12);

    if (mode === 'canvas') {
      const o = drag.originRect;
      let next: CanvasRect =
        drag.kind === 'move'
          ? { ...o, x: Math.max(0, o.x + ldx), y: Math.max(0, o.y + dy) }
          : {
              ...o,
              w: drag.kind === 'resize-block' ? o.w : Math.max(120, o.w + ldx),
              h: drag.kind === 'resize-inline' ? o.h : Math.max(96, o.h + dy),
            };
      if (doc.snap) next = { x: snapTo(next.x), y: snapTo(next.y), w: snapTo(next.w), h: snapTo(next.h) };
      const others = domOrder.filter((id) => id !== drag.id).map((id) => rectOf(id, doc.canvas));
      const guided = alignmentGuides(next, others, undefined, !doc.snap);
      next = drag.kind === 'move' ? guided.rect : next;
      next = { ...next, x: Math.min(next.x, Math.max(0, width - next.w)) };
      /* المسحوبة تتبع المؤشّر حرفيًّا؛ الالتقاط يظهر عند الإفلات وفي الأدلّة. */
      if (drag.kind === 'move') {
        drag.el.style.transform = `translate(${rtl ? -(next.x - o.x) : next.x - o.x}px, ${next.y - o.y}px)`;
      } else {
        drag.el.style.inlineSize = `${next.w}px`;
        drag.el.style.blockSize = `${next.h}px`;
      }
      setGuides(drag.kind === 'move' ? { x: guided.x, y: guided.y } : { x: [], y: [] });
      setCanvasPreview((was) => ({ ...(was ?? doc.canvas), [drag.id]: next }));
      return;
    }

    if (colStep === 0) return;
    const o = drag.origin;
    if (drag.kind === 'move') {
      drag.el.style.transform = `translate(${dx}px, ${dy}px)`;
      const targetX = Math.max(0, Math.min(GRID_COLS - o.w, o.x + Math.round(ldx / colStep)));
      const targetY = Math.max(0, o.y + Math.round(dy / rowStep));
      const cell = `${targetX}:${targetY}`;
      /* حالة React تتغيّر حين تتغيّر الخليّة الهدف فقط — لا مع كلّ بكسل. */
      if (cell !== lastCellRef.current) {
        lastCellRef.current = cell;
        setPreview(moveWidget(doc.widgets, drag.id, targetX, targetY));
      }
    } else {
      const w = drag.kind === 'resize-block' ? o.w : o.w + Math.round(ldx / colStep);
      const h = drag.kind === 'resize-inline' ? o.h : o.h + Math.round(dy / rowStep);
      const size = clampSize(drag.id, o, w, h);
      const cell = `${size.w}:${size.h}`;
      if (cell !== lastCellRef.current) {
        lastCellRef.current = cell;
        setPreview(resizeWidget(doc.widgets, drag.id, size.w, size.h));
      }
    }
  }, [rtl, mode, doc.snap, doc.canvas, doc.widgets, domOrder, rectOf, width, colStep, rowStep]);

  const tickRef = useRef(tick);
  useLayoutEffect(() => {
    tickRef.current = tick;
  }, [tick]);

  const onPointerDown = useCallback(
    (event: React.PointerEvent<HTMLElement>, id: string, kind: Kind) => {
      if (!canEdit || event.button !== 0) return;
      const state = doc.widgets[id];
      if (state === undefined || state.locked) return;
      const el = rootRef.current?.querySelector<HTMLElement>(`[data-widget="${CSS.escape(id)}"]`);
      if (el == null) return;
      event.preventDefault();
      (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
      scrollerRef.current = scrollParent(rootRef.current as HTMLElement);
      dragRef.current = {
        id,
        kind,
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        lastX: event.clientX,
        lastY: event.clientY,
        startScroll: scrollTopOf(scrollerRef.current),
        el,
        origin: state,
        originRect: rectOf(id, doc.canvas),
      };
      lastCellRef.current = '';
      setActive({ id, kind });
      setAnnouncement(`التُقطت ${titleOf(id, state)}. حرّك ثمّ أفلت.`);
    },
    [canEdit, doc.widgets, doc.canvas, rectOf, titleOf],
  );

  const onPointerMove = useCallback((event: React.PointerEvent<HTMLElement>) => {
    const drag = dragRef.current;
    if (drag === null || event.pointerId !== drag.pointerId) return;
    drag.lastX = event.clientX;
    drag.lastY = event.clientY;
    if (frameRef.current === null) frameRef.current = requestAnimationFrame(() => tickRef.current());
  }, []);

  /** كلّ المستطيلات الظاهرة — المحفوظ والمشتقّ — لتُحفظ اللوحة متّسقة من أوّل تعديل. */
  const rectsWithDefaults = useCallback(
    (d: DashboardDoc): Record<string, CanvasRect> => {
      const out: Record<string, CanvasRect> = { ...d.canvas };
      for (const id of domOrder) if (out[id] === undefined) out[id] = rectOf(id, d.canvas);
      return out;
    },
    [domOrder, rectOf],
  );

  const endPointer = useCallback(
    (event: React.PointerEvent<HTMLElement>, cancelled: boolean) => {
      const drag = dragRef.current;
      if (drag === null || event.pointerId !== drag.pointerId) return;
      if (frameRef.current !== null) cancelAnimationFrame(frameRef.current);
      frameRef.current = null;
      drag.el.style.transform = '';
      drag.el.style.inlineSize = '';
      drag.el.style.blockSize = '';
      dragRef.current = null;
      const title = titleOf(drag.id, drag.origin);
      if (!cancelled && mode === 'canvas' && canvasPreview !== null) {
        const rect = canvasPreview[drag.id];
        update((d) => ({ ...d, preset: 'custom', canvas: { ...rectsWithDefaults(d), ...canvasPreview } }));
        setAnnouncement(`أُفلتت ${title}.${rect === undefined ? '' : ` ${describe(drag.id, drag.origin, rect)}`}`);
      } else if (!cancelled && mode === 'grid' && preview !== null) {
        const next = preview;
        update((d) => ({ ...d, preset: 'custom', widgets: next }));
        const s = next[drag.id];
        setAnnouncement(`أُفلتت ${title}. ${s === undefined ? '' : describe(drag.id, s)}`);
      } else {
        setAnnouncement(`أُلغي نقل ${title}.`);
      }
      setPreview(null);
      setCanvasPreview(null);
      setGuides({ x: [], y: [] });
      setActive(null);
    },
    [mode, preview, canvasPreview, update, describe, titleOf, rectsWithDefaults],
  );

  /* ───── لوحة المفاتيح ───── */

  const onGripKey = useCallback(
    (event: React.KeyboardEvent<HTMLElement>, id: string) => {
      if (!canEdit) return;
      const state = doc.widgets[id];
      if (state === undefined) return;
      const picked = keyRef.current?.id === id;
      const key = event.key;

      if (!picked) {
        if (key === ' ' || key === 'Enter') {
          event.preventDefault();
          if (state.locked) {
            setAnnouncement(`${titleOf(id, state)} مقفلة — افتح قفلها لتحريكها.`);
            return;
          }
          keyRef.current = { id, before: doc };
          setActive({ id, kind: 'keyboard' });
          setAnnouncement(
            `التُقطت ${titleOf(id, state)}. الأسهم للتحريك، وShift مع الأسهم لتغيير الحجم، وEnter أو مسافة للإفلات، وEscape للإلغاء. ${describe(id, state, mode === 'canvas' ? rectOf(id) : undefined)}`,
          );
        }
        return;
      }

      if (key === 'Escape') {
        event.preventDefault();
        const before = keyRef.current?.before;
        keyRef.current = null;
        setActive(null);
        if (before !== undefined) update(() => before, { record: false });
        setAnnouncement(`أُلغي — عادت ${titleOf(id, state)} إلى موضعها.`);
        return;
      }
      if (key === ' ' || key === 'Enter') {
        event.preventDefault();
        const before = keyRef.current?.before;
        keyRef.current = null;
        setActive(null);
        /* خطوةٌ واحدة في التاريخ للحمل كلّه: من «قبل» إلى ما انتهى إليه. */
        if (before !== undefined) {
          const finalDoc = doc;
          update(() => before, { record: false });
          update(() => finalDoc);
        }
        setAnnouncement(`أُفلتت ${titleOf(id, state)}. ${describe(id, state, mode === 'canvas' ? rectOf(id) : undefined)}`);
        refocusRef.current = id;
        return;
      }

      const arrows: Record<string, [number, number]> = {
        ArrowLeft: [rtl ? 1 : -1, 0],
        ArrowRight: [rtl ? -1 : 1, 0],
        ArrowUp: [0, -1],
        ArrowDown: [0, 1],
      };
      const step = arrows[key];
      if (step === undefined) return;
      event.preventDefault();
      const [sx, sy] = step;

      if (mode === 'canvas') {
        const r = rectOf(id);
        const next = event.shiftKey
          ? { ...r, w: Math.max(120, r.w + sx * CANVAS_SNAP), h: Math.max(96, r.h + sy * CANVAS_SNAP) }
          : { ...r, x: Math.max(0, Math.min(width - r.w, r.x + sx * CANVAS_SNAP)), y: Math.max(0, r.y + sy * CANVAS_SNAP) };
        update((d) => ({ ...d, preset: 'custom', canvas: { ...rectsWithDefaults(d), [id]: next } }), { record: false });
        setAnnouncement(describe(id, state, next));
        return;
      }

      update(
        (d) => {
          const s = d.widgets[id];
          if (s === undefined) return d;
          const widgetsNext = event.shiftKey ? resizeWidget(d.widgets, id, s.w + sx, s.h + sy) : nudgeWidget(d.widgets, id, sx, sy);
          return { ...d, preset: 'custom', widgets: widgetsNext };
        },
        { record: false },
      );
    },
    [canEdit, doc, mode, rtl, width, rectOf, rectsWithDefaults, update, describe, titleOf],
  );

  /* الإعلان بعد أن يستقرّ الموضع الجديد — من الوثيقة لا من التوقّع. */
  useEffect(() => {
    const picked = keyRef.current;
    if (picked === null || mode === 'canvas') return;
    const s = doc.widgets[picked.id];
    if (s !== undefined) setAnnouncement(describe(picked.id, s));
  }, [doc.widgets, mode, describe]);

  /* بعد الإفلات يتغيّر ترتيب DOM: التركيز يعود إلى مقبض البطاقة نفسها. */
  useEffect(() => {
    const id = refocusRef.current;
    if (id === null) return;
    refocusRef.current = null;
    rootRef.current?.querySelector<HTMLElement>(`[data-widget="${CSS.escape(id)}"] [data-grip]`)?.focus();
  }, [domOrder]);

  /* الخروج من التحرير يُسقط أيّ حملٍ معلّق — بلا حفظٍ لنصف حركة. */
  useEffect(() => {
    if (canEdit) return;
    const before = keyRef.current?.before;
    keyRef.current = null;
    if (before !== undefined) update(() => before, { record: false });
  }, [canEdit, update]);

  const cellStyle = (id: string): React.CSSProperties => {
    if (mode === 'canvas') {
      const r = rectOf(id, active?.kind === 'move' ? doc.canvas : (canvasPreview ?? doc.canvas));
      return { insetInlineStart: r.x, insetBlockStart: r.y, inlineSize: r.w, blockSize: r.h };
    }
    /* المسحوبة تبقى في خليّتها الأصليّة وتتحرّك بالتحويل؛ العلامة تُري الهدف. */
    const box = active?.kind === 'move' && active.id === id ? doc.widgets[id] : boxById.get(id);
    if (box === undefined) return { display: 'none' };
    return { gridColumn: `${box.x + 1} / span ${box.w}`, gridRow: `${box.y + 1} / span ${box.h}` };
  };

  const target = active !== null && active.kind === 'move' && mode === 'grid' ? boxById.get(active.id) : undefined;
  const canvasTarget = active !== null && active.kind === 'move' && mode === 'canvas' ? canvasPreview?.[active.id] : undefined;

  return (
    <div
      ref={rootRef}
      className={styles.workspace}
      data-mode={mode}
      data-editing={canEdit ? '' : undefined}
      data-dragging={active !== null && active.kind !== 'keyboard' ? '' : undefined}
      data-density={density}
      style={
        mode === 'canvas'
          ? { blockSize: canvasHeight, ['--ws-gap' as string]: `${gap}px` }
          : {
              gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))`,
              gridAutoRows: `${row}px`,
              gap: `${gap}px`,
              ['--ws-gap' as string]: `${gap}px`,
              ['--ws-row' as string]: `${row}px`,
              ['--ws-cols' as string]: String(cols),
              minBlockSize: canEdit ? (depth + 4) * rowStep : undefined,
            }
      }
      onPointerMove={onPointerMove}
      onPointerUp={(e) => endPointer(e, false)}
      onPointerCancel={(e) => endPointer(e, true)}
    >
      {canEdit && mode === 'grid' ? (
        <div className={styles.overlay} aria-hidden="true" style={{ gridRow: `1 / span ${depth + 4}` }}>
          {Array.from({ length: GRID_COLS }, (_, i) => <span key={i} />)}
        </div>
      ) : null}

      {target !== undefined ? (
        <div
          className={styles.placeholder}
          aria-hidden="true"
          style={{ gridColumn: `${target.x + 1} / span ${target.w}`, gridRow: `${target.y + 1} / span ${target.h}` }}
        />
      ) : null}
      {canvasTarget !== undefined ? (
        <div
          className={styles.placeholder}
          aria-hidden="true"
          style={{ insetInlineStart: canvasTarget.x, insetBlockStart: canvasTarget.y, inlineSize: canvasTarget.w, blockSize: canvasTarget.h }}
        />
      ) : null}

      {domOrder.map((id) => {
        const state = widgets[id] ?? doc.widgets[id];
        if (state === undefined) return null;
        return (
          <Cell
            key={id}
            id={id}
            style={cellStyle(id)}
            state={state}
            title={titleOf(id, state)}
            editing={canEdit}
            lifted={active?.id === id ? active.kind : null}
            content={content[id]}
            actions={canEdit ? actionsOf(id, state) : EMPTY_ACTIONS}
            resizable={defOf(id, state) !== null}
            onPointerDown={onPointerDown}
            onGripKey={onGripKey}
            onLock={() => update((d) => ({ ...d, widgets: { ...d.widgets, [id]: { ...state, locked: !state.locked } } }))}
          />
        );
      })}

      {mode === 'canvas' && canEdit ? (
        <div className={styles.guides} aria-hidden="true">
          {guides.x.map((x) => <span key={`x${x}`} data-axis="x" style={{ insetInlineStart: x }} />)}
          {guides.y.map((y) => <span key={`y${y}`} data-axis="y" style={{ insetBlockStart: y }} />)}
        </div>
      ) : null}

      <p className={styles.live} role="status" aria-live="polite">{announcement}</p>
    </div>
  );
}

const EMPTY_ACTIONS: WidgetMenuAction[] = [];

interface CellProps {
  id: string;
  style: React.CSSProperties;
  state: WidgetState;
  title: string;
  editing: boolean;
  lifted: Kind | 'keyboard' | null;
  content: ReactNode;
  actions: WidgetMenuAction[];
  resizable: boolean;
  onPointerDown: (event: React.PointerEvent<HTMLElement>, id: string, kind: Kind) => void;
  onGripKey: (event: React.KeyboardEvent<HTMLElement>, id: string) => void;
  onLock: () => void;
}

/*
 * المقارنة على ما يُرى فعلًا: `style` كائنٌ جديد في كلّ تصيير، فيُقارَن بقيمه.
 * و`actions`/`onLock` تتغيّر مع كلّ تصيير للأب في التحرير وحده — خارجه ثابتة.
 */
const Cell = memo(
  function Cell({ id, style, state, title, editing, lifted, content, actions, resizable, onPointerDown, onGripKey, onLock }: CellProps) {
    return (
      <WidgetFrame
        id={id}
        style={style}
        title={title}
        editing={editing}
        locked={state.locked}
        lifted={lifted}
        actions={actions}
        resizable={resizable && !state.locked}
        onGripPointerDown={(e) => onPointerDown(e, id, 'move')}
        onResizePointerDown={(e, axis) => onPointerDown(e, id, axis)}
        onGripKeyDown={(e) => onGripKey(e, id)}
        onLock={onLock}
      >
        {content}
      </WidgetFrame>
    );
  },
  (a, b) =>
    a.id === b.id
    && a.title === b.title
    && a.editing === b.editing
    && a.lifted === b.lifted
    && a.content === b.content
    && a.resizable === b.resizable
    && a.state.locked === b.state.locked
    && JSON.stringify(a.style) === JSON.stringify(b.style)
    && (!a.editing || (a.actions === b.actions && a.onGripKey === b.onGripKey && a.onPointerDown === b.onPointerDown)),
);
