// UNIT: _VIEW-MAP
// FILE: _view-map.tsx
// STYLE: views.module.css
// PARENT: VIEWS
// USES: MODEL, NAVIGATION-ICONS, _VIEW-KIT
// API: none
// MAP: docs/PROJECT_MAP.md

import { useCallback, useEffect, useRef, useState } from 'react';
import { Icon } from './icons';
import { childrenOf, descendantIds, pathOf, type Row, typeMeta, visibleRows } from './model';
import styles from './views.module.css';
import { MoveButtons, RenameField, SEVERITY_CLASS, matchesQuery, severityOf, type ViewProps } from './_view-kit';

/* ═══════════════ الخريطة البصرية ═══════════════ */

type MapLayout = 'h' | 'v' | 'c';

interface PlacedNode {
  row: Row;
  x: number;
  y: number;
}

/** أبعاد العقد والفجوات منقولة حرفيًا من الأصل البصري. */
function geometry(layout: MapLayout) {
  const vertical = layout === 'v';
  const compact = layout === 'c';
  return {
    vertical,
    W: vertical ? 188 : compact ? 210 : 238,
    H: 82,
    GX: vertical ? 18 : compact ? 36 : 78,
    GY: vertical ? 66 : 16,
  };
}

export function MapView(props: ViewProps) {
  const { nodes, selection, collapsed, editingId, issuesByNode, query, commands } = props;
  const [layout, setLayout] = useState<MapLayout>('h');
  const [zoom, setZoom] = useState(1);
  const [pan, setPan] = useState({ x: 0, y: 0 });
  const [dragId, setDragId] = useState<string | null>(null);
  const [dropTargetId, setDropTargetId] = useState<string | null>(null);
  const viewportRef = useRef<HTMLDivElement | null>(null);
  const panRef = useRef<{ x: number; y: number; startX: number; startY: number } | null>(null);

  const { vertical, W, H, GX, GY } = geometry(layout);
  const list = visibleRows(nodes, collapsed);

  /* تخطيط شجري: الورقة تأخذ خانة، والأب يتوسّط أبناءه. */
  const main = new Map<string, number>();
  const depth = new Map<string, number>();
  let slot = 0;
  let maxDepth = 0;
  const place = (id: string, d: number): number => {
    maxDepth = Math.max(maxDepth, d);
    const kids = collapsed.has(id) ? [] : childrenOf(nodes, id);
    let center: number;
    if (kids.length > 0) {
      const spans = kids.map((kid) => place(kid.id, d + 1));
      const first = spans[0] ?? 0;
      const last = spans[spans.length - 1] ?? 0;
      center = (first + last) / 2;
    } else {
      center = slot * (vertical ? W + GX : H + GY);
      slot += 1;
    }
    main.set(id, center);
    depth.set(id, d);
    return center;
  };
  for (const root of childrenOf(nodes, null)) place(root.id, 0);

  const spanW = vertical ? slot * (W + GX) - GX : (maxDepth + 1) * (W + GX) - GX;
  const spanH = vertical ? (maxDepth + 1) * (H + GY) - GY : slot * (H + GY) - GY;
  const canvasW = Math.max(760, spanW + 60);
  const canvasH = Math.max(430, spanH + 60);

  const placed: PlacedNode[] = list.map((row) => {
    const m = main.get(row.node.id) ?? 0;
    const d = depth.get(row.node.id) ?? 0;
    // العربية تقرأ من اليمين: الجذر على اليمين والفروع تتقدّم يسارًا.
    const x = vertical ? 30 + m : canvasW - 30 - W - d * (W + GX);
    const y = vertical ? 30 + d * (H + GY) : 30 + m;
    return { row, x, y };
  });

  const positions = new Map(placed.map((item) => [item.row.node.id, item]));

  const edges = placed.flatMap((item) => {
    const parentId = item.row.node.parentId;
    if (parentId === null) return [];
    const parent = positions.get(parentId);
    if (!parent) return [];
    let d: string;
    if (vertical) {
      const sx = parent.x + W / 2;
      const sy = parent.y + H;
      const ex = item.x + W / 2;
      const ey = item.y;
      d = `M${sx},${sy} C${sx},${sy + GY / 2} ${ex},${ey - GY / 2} ${ex},${ey}`;
    } else {
      const sx = parent.x;
      const sy = parent.y + H / 2;
      const ex = item.x + W;
      const ey = item.y + H / 2;
      d = `M${sx},${sy} C${sx - GX / 2},${sy} ${ex + GX / 2},${ey} ${ex},${ey}`;
    }
    const active = selection.includes(item.row.node.id) || selection.includes(parentId);
    return [
      {
        key: `${parentId}-${item.row.node.id}`,
        d,
        stroke: active ? 'var(--primary)' : 'var(--ms-edge)',
        width: active ? 2.4 : 1.5,
        dash: item.row.node.deleted ? '5 5' : '0',
      },
    ];
  });

  /* يعيد false إن لم يكن الإطار مقيسًا بعد، فتُعاد المحاولة بدل أن تُهدر. */
  const fit = useCallback((): boolean => {
    const box = viewportRef.current?.getBoundingClientRect();
    if (!box || box.width === 0 || box.height === 0) return false;
    // لا تكبير فوق ١:١ — الملء يصغّر ليُظهر كل شيء، ولا يضخّم شجرة صغيرة.
    const scale = Math.min(box.width / canvasW, box.height / canvasH, 1);
    setZoom(Math.max(0.3, Number(scale.toFixed(2))));
    setPan({ x: 0, y: 0 });
    return true;
  }, [canvasW, canvasH]);

  /*
   * ملء تلقائي عند أول رسم وعند تغيّر هوية المحتوى (تخطيط · عدد عقد · مقاس).
   * بدونه تفتح الخريطة على عقد خارج الإطار لأن الجذر في أقصى الجهة المقابلة.
   * المفتاح لا يشمل التكبير أو الإزاحة، فلا يُلغى ضبط المستخدم اليدوي.
   */
  const fitKey = `${layout}:${placed.length}:${canvasW}x${canvasH}`;
  const fittedRef = useRef('');
  useEffect(() => {
    if (fittedRef.current === fitKey) return;
    if (fit()) fittedRef.current = fitKey;
  }, [fitKey, fit]);

  const centerSelected = () => {
    const id = selection[0];
    if (!id) return;
    const target = positions.get(id);
    const box = viewportRef.current?.getBoundingClientRect();
    if (!target || !box) return;
    setPan({
      x: box.width / 2 - (target.x + W / 2) * zoom,
      y: box.height / 2 - (target.y + H / 2) * zoom,
    });
  };

  const crumbs = selection[0] ? pathOf(nodes, selection[0]) : [];
  const miniScale = Math.min(150 / canvasW, 86 / canvasH);

  return (
    <section className={styles.panel} aria-labelledby="nav-map-heading">
      <header className={styles.panelHead}>
        <span className={styles.panelIcon}>
          <Icon name="map" size={15} />
        </span>
        <h2 id="nav-map-heading" className={styles.panelTitle}>
          الخريطة البصرية القابلة للتحرير
        </h2>
        <span className={styles.countPill}>
          <bdi dir="ltr">{placed.length}</bdi> عقدة
        </span>
        <span className={styles.spacer} />
        <div className={styles.segmented} role="group" aria-label="اتجاه التخطيط">
          {(
            [
              ['h', 'أفقي'],
              ['v', 'عمودي'],
              ['c', 'مضغوط'],
            ] as [MapLayout, string][]
          ).map(([key, label]) => (
            <button
              key={key}
              type="button"
              className={styles.segBtn}
              aria-pressed={layout === key}
              onClick={() => setLayout(key)}
            >
              {label}
            </button>
          ))}
        </div>
        <button
          type="button"
          className={styles.iconBtn}
          onClick={() => setZoom((z) => Math.max(0.3, Number((z - 0.1).toFixed(2))))}
        >
          <span className={styles.srOnly}>تصغير الخريطة</span>
          <Icon name="zout" size={13} />
        </button>
        <span className={styles.zoomLabel}>
          <bdi dir="ltr">{Math.round(zoom * 100)}%</bdi>
        </span>
        <button
          type="button"
          className={styles.iconBtn}
          onClick={() => setZoom((z) => Math.min(2, Number((z + 0.1).toFixed(2))))}
        >
          <span className={styles.srOnly}>تكبير الخريطة</span>
          <Icon name="zin" size={13} />
        </button>
        <button type="button" className={styles.iconBtn} onClick={fit}>
          <span className={styles.srOnly}>ملء الإطار</span>
          <Icon name="fit" size={13} />
        </button>
        <button type="button" className={styles.ghostBtn} onClick={centerSelected} disabled={!selection[0]}>
          توسيط المحدد
        </button>
        <button
          type="button"
          className={styles.ghostBtn}
          onClick={() => {
            setZoom(1);
            setPan({ x: 0, y: 0 });
          }}
        >
          إعادة
        </button>
      </header>

      <div className={styles.mapCrumbs}>
        <span className={styles.crumbLabel}>المسار:</span>
        {crumbs.length === 0 ? (
          <span className={styles.crumbEmpty}>لا عنصر محدد</span>
        ) : (
          crumbs.map((node, index) => (
            <span key={node.id} className={styles.crumb}>
              {index > 0 ? <span aria-hidden="true">›</span> : null}
              <button type="button" className={styles.crumbBtn} onClick={() => commands.select(node.id, 'single')}>
                {node.label || '⟨بلا نص⟩'}
              </button>
            </span>
          ))
        )}
        <span className={styles.spacer} />
        {(['page', 'category', 'custom'] as const).map((type) => (
          <span key={type} className={styles.legend}>
            <span
              className={styles.legendDot}
              style={{ background: typeMeta(type).color }}
              aria-hidden="true"
            />
            {typeMeta(type).name}
          </span>
        ))}
      </div>

      <div
        ref={viewportRef}
        dir="ltr"
        className={styles.mapViewport}
        onPointerDown={(event) => {
          if ((event.target as HTMLElement).closest('[data-map-node]')) return;
          panRef.current = { x: pan.x, y: pan.y, startX: event.clientX, startY: event.clientY };
          event.currentTarget.setPointerCapture(event.pointerId);
        }}
        onPointerMove={(event) => {
          const origin = panRef.current;
          if (!origin) return;
          setPan({
            x: origin.x + (event.clientX - origin.startX),
            y: origin.y + (event.clientY - origin.startY),
          });
        }}
        onPointerUp={() => {
          panRef.current = null;
        }}
        onPointerLeave={() => {
          panRef.current = null;
        }}
      >
        {placed.length === 0 ? (
          <p className={styles.viewEmpty}>لا عقد ترسم — المسودة فارغة.</p>
        ) : (
          <div
            className={styles.mapCanvas}
            style={{
              inlineSize: `${canvasW}px`,
              blockSize: `${canvasH}px`,
              transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
            }}
          >
            <svg width={canvasW} height={canvasH} className={styles.mapEdges} aria-hidden="true">
              {edges.map((edge) => (
                <path
                  key={edge.key}
                  d={edge.d}
                  fill="none"
                  stroke={edge.stroke}
                  strokeWidth={edge.width}
                  strokeDasharray={edge.dash}
                  strokeLinecap="round"
                />
              ))}
            </svg>

            {placed.map(({ row, x, y }) => {
              const { node } = row;
              const selected = selection.includes(node.id);
              const meta = typeMeta(node.type);
              const severity = severityOf(issuesByNode.get(node.id));
              return (
                <div
                  key={node.id}
                  data-map-node="1"
                  className={[
                    styles.mapNode,
                    selected ? styles.mapNodeSelected : '',
                    node.deleted ? styles.rowDeleted : '',
                    matchesQuery(node, query) ? styles.mapNodeMatch : '',
                    dropTargetId === node.id ? styles.mapNodeDrop : '',
                  ]
                    .filter(Boolean)
                    .join(' ')}
                  style={{
                    insetInlineStart: `${x}px`,
                    insetBlockStart: `${y}px`,
                    inlineSize: `${W}px`,
                    borderBlockStartColor: meta.color,
                  }}
                  draggable
                  onDragStart={(event) => {
                    setDragId(node.id);
                    event.dataTransfer.effectAllowed = 'move';
                  }}
                  onDragOver={(event) => {
                    if (dragId === null || dragId === node.id) return;
                    if (descendantIds(nodes, dragId).has(node.id)) return;
                    event.preventDefault();
                    setDropTargetId(node.id);
                  }}
                  onDrop={(event) => {
                    event.preventDefault();
                    if (dragId) commands.drop(dragId, node.id, 'inside');
                    setDragId(null);
                    setDropTargetId(null);
                  }}
                  onDragEnd={() => {
                    setDragId(null);
                    setDropTargetId(null);
                  }}
                >
                  <div dir="rtl" className={styles.mapNodeHead}>
                    <span className={styles.typeChip} style={{ color: meta.color }} aria-hidden="true">
                      <Icon name={node.icon || meta.icon} size={12} />
                    </span>
                    {editingId === node.id ? (
                      <RenameField node={node} commands={commands} className={styles.renameInputSm} />
                    ) : (
                      <button
                        type="button"
                        className={styles.mapNodeLabel}
                        aria-pressed={selected}
                        onClick={(event) =>
                          commands.select(node.id, event.metaKey || event.ctrlKey ? 'toggle' : 'single')
                        }
                        onDoubleClick={() => commands.beginRename(node.id)}
                      >
                        {node.label || '⟨بلا نص تنقل⟩'}
                      </button>
                    )}
                    {row.childCount > 0 ? (
                      <button
                        type="button"
                        className={styles.mapPill}
                        aria-expanded={!collapsed.has(node.id)}
                        onClick={() => commands.toggleCollapse(node.id)}
                      >
                        <span className={styles.srOnly}>
                          {collapsed.has(node.id) ? 'توسيع' : 'طي'} «{node.label || 'عنصر'}»
                        </span>
                        <bdi dir="ltr">
                          {collapsed.has(node.id) ? '+' : ''}
                          {row.childCount}
                        </bdi>
                      </button>
                    ) : null}
                  </div>
                  <div dir="rtl" className={styles.mapNodeMeta}>
                    <span style={{ color: meta.color }} className={styles.rowType}>
                      {meta.name}
                    </span>
                    <bdi dir="ltr" className={styles.rowUrl}>
                      {node.url || '—'}
                    </bdi>
                    {severity ? (
                      <span className={`${styles.issueDot} ${SEVERITY_CLASS[severity]}`} aria-hidden="true">
                        <Icon name="warn" size={10} />
                      </span>
                    ) : null}
                  </div>
                  {selected ? (
                    <div dir="rtl" className={styles.mapNodeTools}>
                      <button
                        type="button"
                        className={styles.iconBtnSm}
                        onClick={() => commands.beginRename(node.id)}
                        title="تحرير"
                      >
                        <span className={styles.srOnly}>تحرير نص «{node.label || 'عنصر'}»</span>
                        <Icon name="pen" size={11} />
                      </button>
                      <button
                        type="button"
                        className={styles.iconBtnSm}
                        onClick={() => commands.addChild(node.id)}
                        title="إضافة ابن"
                      >
                        <span className={styles.srOnly}>إضافة عنصر داخل «{node.label || 'عنصر'}»</span>
                        <Icon name="plus" size={11} />
                      </button>
                      <button
                        type="button"
                        className={styles.iconBtnSm}
                        onClick={() => commands.duplicate(node.id)}
                        title="تكرار الفرع"
                      >
                        <span className={styles.srOnly}>تكرار فرع «{node.label || 'عنصر'}»</span>
                        <Icon name="copy" size={11} />
                      </button>
                      <MoveButtons node={node} commands={commands} compact />
                      <span className={styles.spacer} />
                      {node.deleted ? (
                        <button
                          type="button"
                          className={styles.restoreBtn}
                          onClick={() => commands.restore(node.id)}
                        >
                          استرجاع
                        </button>
                      ) : (
                        <button
                          type="button"
                          className={styles.deleteBtn}
                          onClick={() => commands.remove(node.id)}
                        >
                          حذف مؤجل
                        </button>
                      )}
                    </div>
                  ) : null}
                </div>
              );
            })}
          </div>
        )}

        {placed.length > 0 ? (
          <p className={styles.mapHint}>
            اسحب الخلفية للتنقل · أزرار التكبير أعلاه · اسحب عقدة وأفلتها على أخرى لتعشيشها، أو استعمل
            أزرار العقدة ومفاتيح Alt مع الأسهم
          </p>
        ) : null}

        {placed.length > 0 ? (
          <div className={styles.minimap}>
            <svg
              viewBox={`0 0 ${canvasW * miniScale} ${canvasH * miniScale}`}
              width={Math.round(canvasW * miniScale)}
              height={Math.round(canvasH * miniScale)}
              aria-hidden="true"
            >
              {placed.map(({ row, x, y }) => (
                <rect
                  key={row.node.id}
                  x={x * miniScale}
                  y={y * miniScale}
                  width={W * miniScale}
                  height={H * miniScale}
                  rx={1.6}
                  fill={
                    selection.includes(row.node.id)
                      ? 'var(--primary)'
                      : severityOf(issuesByNode.get(row.node.id)) === 'error'
                        ? 'var(--ms-danger)'
                        : severityOf(issuesByNode.get(row.node.id)) === 'warn'
                          ? 'var(--ms-warn)'
                          : 'var(--ms-mini)'
                  }
                />
              ))}
            </svg>
            <span className={styles.minimapNote}>خريطة مصغّرة</span>
          </div>
        ) : null}
      </div>
    </section>
  );
}
