'use client';
// UNIT: VIEWS
// FILE: views.tsx
// STYLE: views.module.css
// PARENT: PAGE-NAVIGATION
// USES: MODEL, NAVIGATION-ICONS, _VIEW-KIT, _VIEW-MAP
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * العروض الأربعة فوق نموذج تحديد واحد: الشجرة · الخريطة · الجدول · المعاينة.
 *
 * التحديد والطيّ والتحرير الموضعي حالة واحدة يملكها الاستوديو، فأي عرض يعدّلها
 * تراه بقية العروض فورًا. كل إجراء سحب له زر مكافئ (أعلى/أسفل/تعشيش/إخراج)
 * حتى تعمل الشاشة كاملةً بلوحة المفاتيح وحدها.
 */

import { useState } from 'react';
import { Icon } from './icons';
import {
  childrenOf,
  descendantIds,
  type Row,
  typeMeta,
  visibleRows,
} from './model';
import styles from './views.module.css';
import {
  type DropPosition,
  MoveButtons,
  RenameField,
  SEVERITY_CLASS,
  STAGE_CLASS,
  matchesQuery,
  severityOf,
  type ViewProps,
} from './_view-kit';

export { MapView } from './_view-map';
export type { DropPosition, StudioCommands, ViewProps } from './_view-kit';

/* ═══════════════ الشجرة المنظَّمة ═══════════════ */

export function TreeView(props: ViewProps) {
  const { nodes, selection, collapsed, editingId, issuesByNode, query, commands } = props;
  const list = visibleRows(nodes, collapsed);
  const [dragId, setDragId] = useState<string | null>(null);
  const [dropHint, setDropHint] = useState<{ id: string | null; position: DropPosition } | null>(null);

  const onDragOverRow = (event: React.DragEvent, row: Row) => {
    if (dragId === null || dragId === row.node.id) return;
    if (descendantIds(nodes, dragId).has(row.node.id)) return;
    event.preventDefault();
    const rect = event.currentTarget.getBoundingClientRect();
    const ratio = (event.clientY - rect.top) / rect.height;
    const position: DropPosition = ratio < 0.28 ? 'before' : ratio > 0.72 ? 'after' : 'inside';
    setDropHint({ id: row.node.id, position });
  };

  const finishDrag = () => {
    setDragId(null);
    setDropHint(null);
  };

  return (
    <section className={styles.panel} aria-labelledby="nav-tree-heading">
      <header className={styles.panelHead}>
        <span className={styles.panelIcon}>
          <Icon name="tree" size={15} />
        </span>
        <h2 id="nav-tree-heading" className={styles.panelTitle}>
          الشجرة المنظَّمة
        </h2>
        <span className={styles.panelHint}>
          اسحب للترتيب · أفلت على منتصف عنصر لتعشيشه · وكل ذلك متاح بالأزرار وبمفاتيح Alt مع الأسهم
        </span>
        <span className={styles.spacer} />
        <span className={styles.countPill}>
          <bdi dir="ltr">{list.length}</bdi> ظاهرة / <bdi dir="ltr">{nodes.length}</bdi>
        </span>
      </header>

      {list.length === 0 ? (
        <p className={styles.viewEmpty}>
          لا عناصر في هذه المسودة بعد. استعمل «إضافة عنصر» أو اختر محتوى حقيقيًا من مكتبة المحتوى.
        </p>
      ) : (
        <ul className={styles.treeList}>
          {list.map((row) => {
            const { node } = row;
            const selected = selection.includes(node.id);
            const severity = severityOf(issuesByNode.get(node.id));
            const hint = dropHint?.id === node.id ? dropHint.position : null;
            const meta = typeMeta(node.type);
            return (
              <li
                key={node.id}
                className={styles.treeItem}
                style={{ marginInlineStart: `${row.depth * 26}px` }}
              >
                <span
                  className={`${styles.dropLine} ${hint === 'before' ? styles.dropLineOn : ''}`}
                  aria-hidden="true"
                />
                <div
                  className={[
                    styles.treeRow,
                    selected ? styles.rowSelected : '',
                    node.deleted ? styles.rowDeleted : '',
                    matchesQuery(node, query) ? styles.rowMatch : '',
                    hint === 'inside' ? styles.rowDropInside : '',
                    dragId === node.id ? styles.rowDragging : '',
                  ]
                    .filter(Boolean)
                    .join(' ')}
                  draggable
                  onDragStart={(event) => {
                    setDragId(node.id);
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', node.id);
                  }}
                  onDragOver={(event) => onDragOverRow(event, row)}
                  onDrop={(event) => {
                    event.preventDefault();
                    if (dragId && dropHint) commands.drop(dragId, dropHint.id, dropHint.position);
                    finishDrag();
                  }}
                  onDragEnd={finishDrag}
                >
                  <span className={styles.grip} aria-hidden="true">
                    <Icon name="grip" size={14} />
                  </span>

                  {row.childCount > 0 ? (
                    <button
                      type="button"
                      className={styles.caretBtn}
                      aria-expanded={!collapsed.has(node.id)}
                      onClick={() => commands.toggleCollapse(node.id)}
                    >
                      <span className={styles.srOnly}>
                        {collapsed.has(node.id) ? 'توسيع' : 'طي'} فرع «{node.label || 'عنصر بلا نص'}»
                      </span>
                      <Icon name={collapsed.has(node.id) ? 'chev-l' : 'chev-d'} size={11} />
                    </button>
                  ) : (
                    <span className={styles.caretSpacer} aria-hidden="true" />
                  )}

                  <span className={styles.typeChip} style={{ color: meta.color }} aria-hidden="true">
                    <Icon name={node.icon || meta.icon} size={13} />
                  </span>

                  <div className={styles.rowBody}>
                    {editingId === node.id ? (
                      <RenameField node={node} commands={commands} className={styles.renameInput} />
                    ) : (
                      <>
                        <button
                          type="button"
                          className={styles.rowSelect}
                          aria-pressed={selected}
                          onClick={(event) =>
                            commands.select(node.id, event.metaKey || event.ctrlKey ? 'toggle' : 'single')
                          }
                          onDoubleClick={() => commands.beginRename(node.id)}
                        >
                          <span className={styles.rowLabel}>{node.label || '⟨بلا نص تنقل⟩'}</span>
                          {node.badge ? <span className={styles.badge}>{node.badge}</span> : null}
                        </button>
                        <span className={styles.rowMeta}>
                          <span style={{ color: meta.color }} className={styles.rowType}>
                            {meta.name}
                          </span>
                          <bdi dir="ltr" className={styles.rowUrl}>
                            {node.url || '—'}
                          </bdi>
                        </span>
                      </>
                    )}
                  </div>

                  {severity ? (
                    <span className={`${styles.issueChip} ${SEVERITY_CLASS[severity]}`}>
                      <Icon name="warn" size={11} />
                      <bdi dir="ltr">{issuesByNode.get(node.id)?.length ?? 0}</bdi>
                    </span>
                  ) : null}

                  {row.childCount > 0 ? (
                    <span className={styles.kidsPill}>
                      <bdi dir="ltr">{row.childCount}</bdi>
                    </span>
                  ) : null}

                  <div className={styles.rowActions}>
                    <button
                      type="button"
                      className={styles.iconBtn}
                      onClick={() => commands.beginRename(node.id)}
                      title="تحرير النص هنا"
                    >
                      <span className={styles.srOnly}>تحرير نص «{node.label || 'عنصر بلا نص'}»</span>
                      <Icon name="pen" size={12} />
                    </button>
                    <button
                      type="button"
                      className={styles.iconBtn}
                      onClick={() => commands.addChild(node.id)}
                      title="إضافة عنصر داخل هذا"
                    >
                      <span className={styles.srOnly}>إضافة عنصر داخل «{node.label || 'عنصر بلا نص'}»</span>
                      <Icon name="plus" size={12} />
                    </button>
                    <button
                      type="button"
                      className={styles.iconBtn}
                      onClick={() => commands.duplicate(node.id)}
                      title="تكرار الفرع"
                    >
                      <span className={styles.srOnly}>تكرار فرع «{node.label || 'عنصر بلا نص'}»</span>
                      <Icon name="copy" size={12} />
                    </button>
                    <MoveButtons node={node} commands={commands} />
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
                </div>
                <span
                  className={`${styles.dropLine} ${hint === 'after' ? styles.dropLineOn : ''}`}
                  aria-hidden="true"
                />
              </li>
            );
          })}
        </ul>
      )}

      <div
        className={`${styles.rootDrop} ${dropHint?.id === null ? styles.rootDropOn : ''}`}
        onDragOver={(event) => {
          if (dragId === null) return;
          event.preventDefault();
          setDropHint({ id: null, position: 'after' });
        }}
        onDrop={(event) => {
          event.preventDefault();
          if (dragId) commands.drop(dragId, null, 'after');
          finishDrag();
        }}
      >
        أفلت هنا لجعل العنصر في المستوى الأعلى — أو استعمل زر «إخراج» على العنصر
      </div>
    </section>
  );
}

/* ═══════════════ الجدول المختصر ═══════════════ */

export function TableView(props: ViewProps) {
  const { nodes, selection, issuesByNode, commands } = props;
  const list = visibleRows(nodes, new Set());

  return (
    <section className={styles.panel} aria-labelledby="nav-table-heading">
      <header className={styles.panelHead}>
        <span className={styles.panelIcon}>
          <Icon name="table" size={15} />
        </span>
        <h2 id="nav-table-heading" className={styles.panelTitle}>
          الجدول المختصر
        </h2>
        <span className={styles.panelHint}>كل ما يحمله العنصر في سطر واحد</span>
      </header>

      <div className={styles.tableScroll}>
        <table className={styles.table}>
          <caption className={styles.srOnly}>عناصر مسودة القائمة</caption>
          <thead>
            <tr>
              <th scope="col">تحديد</th>
              <th scope="col">نص التنقل</th>
              <th scope="col">النوع</th>
              <th scope="col">الوجهة</th>
              <th scope="col">العمق</th>
              <th scope="col">الهدف</th>
              <th scope="col">فئات CSS</th>
              <th scope="col">الملاحظات</th>
              <th scope="col">ترتيب</th>
            </tr>
          </thead>
          <tbody>
            {list.length === 0 ? (
              <tr>
                <td className={styles.tableEmpty} colSpan={9}>
                  لا عناصر في هذه المسودة بعد.
                </td>
              </tr>
            ) : (
              list.map((row) => {
                const { node } = row;
                const meta = typeMeta(node.type);
                const issues = issuesByNode.get(node.id) ?? [];
                const severity = severityOf(issues);
                return (
                  <tr key={node.id} className={selection.includes(node.id) ? styles.trSelected : undefined}>
                    <td>
                      <label className={styles.checkCell}>
                        <span className={styles.srOnly}>تحديد «{node.label || 'عنصر بلا نص'}»</span>
                        <input
                          type="checkbox"
                          checked={selection.includes(node.id)}
                          onChange={() => commands.select(node.id, 'toggle')}
                        />
                      </label>
                    </td>
                    <td>
                      <span
                        className={styles.tableLabel}
                        style={{ paddingInlineStart: `${row.depth * 16}px` }}
                      >
                        <span
                          className={styles.typeDot}
                          style={{ background: meta.color }}
                          aria-hidden="true"
                        />
                        <span className={node.deleted ? styles.struck : undefined}>
                          {node.label || '⟨بلا نص تنقل⟩'}
                        </span>
                      </span>
                    </td>
                    <td style={{ color: meta.color }}>{meta.name}</td>
                    <td>
                      <bdi dir="ltr" className={styles.mono}>
                        {node.url || '—'}
                      </bdi>
                    </td>
                    <td>
                      <bdi dir="ltr">{row.depth + 1}</bdi>
                    </td>
                    <td>{node.target === '_blank' ? 'تبويب جديد' : 'نفس التبويب'}</td>
                    <td>
                      <bdi dir="ltr" className={styles.mono}>
                        {node.classes || '—'}
                      </bdi>
                    </td>
                    <td>
                      {severity ? (
                        <span className={`${styles.issueChip} ${SEVERITY_CLASS[severity]}`}>
                          {issues[0]?.title}
                          {issues.length > 1 ? ` +${issues.length - 1}` : ''}
                        </span>
                      ) : (
                        <span className={styles.tableOk}>سليم</span>
                      )}
                    </td>
                    <td>
                      <div className={styles.rowActions}>
                        <MoveButtons node={node} commands={commands} />
                        <button
                          type="button"
                          className={styles.ghostBtn}
                          onClick={() => commands.select(node.id, 'single')}
                        >
                          المفتش
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}

/* ═══════════════ المعاينة الحيّة ═══════════════ */

export function LiveView(props: ViewProps) {
  const { nodes, selection, commands } = props;
  const [device, setDevice] = useState<'desktop' | 'tablet' | 'mobile'>('desktop');
  const [openId, setOpenId] = useState<string | null>(null);
  const top = childrenOf(nodes, null).filter((node) => !node.deleted);

  return (
    <section className={styles.panel} aria-labelledby="nav-live-heading">
      <header className={styles.panelHead}>
        <span className={styles.panelIcon}>
          <Icon name="monitor" size={15} />
        </span>
        <h2 id="nav-live-heading" className={styles.panelTitle}>
          معاينة شكل القائمة
        </h2>
        <span className={styles.spacer} />
        <div className={styles.segmented} role="group" aria-label="حجم الجهاز">
          {(
            [
              ['desktop', 'سطح المكتب', 'monitor'],
              ['tablet', 'لوحي', 'tablet'],
              ['mobile', 'جوال', 'phone'],
            ] as const
          ).map(([key, label, icon]) => (
            <button
              key={key}
              type="button"
              className={styles.segBtn}
              aria-pressed={device === key}
              onClick={() => setDevice(key)}
            >
              <Icon name={icon} size={12} />
              {label}
            </button>
          ))}
        </div>
      </header>

      <div className={styles.liveNotice} role="note">
        <Icon name="info" size={14} />
        <p>
          هذه معاينة لبنية <strong>المسوّدة</strong> برسم اللوحة، لا واجهة المتجر الحقيقية. ما يراه
          الزائر فعلًا هو المنشور، ويُقرأ من{' '}
          <bdi dir="ltr">
            <code>GET /api/v1/navigation/{'{location}'}</code>
          </bdi>
          . إطار المتجر (الخطوط والألوان والسلوك) لا يُحاكى هنا عمدًا: محاكاته توهم بأن ما تراه هو
          المنشور بينما هو مسوّدة لم تصل زائرًا بعد.
        </p>
      </div>

      <div className={`${styles.liveStage} ${STAGE_CLASS[device]}`}>
        {top.length === 0 ? (
          <p className={styles.viewEmpty}>لا عناصر في المستوى الأعلى.</p>
        ) : device === 'mobile' ? (
          <ul className={styles.liveDrawer}>
            {top.map((node) => {
              const kids = childrenOf(nodes, node.id).filter((kid) => !kid.deleted);
              return (
                <li key={node.id}>
                  <button
                    type="button"
                    className={styles.liveDrawerItem}
                    aria-pressed={selection.includes(node.id)}
                    aria-expanded={kids.length > 0 ? openId === node.id : undefined}
                    onClick={() => {
                      commands.select(node.id, 'single');
                      if (kids.length > 0) setOpenId(openId === node.id ? null : node.id);
                    }}
                  >
                    <span>{node.label || '⟨بلا نص تنقل⟩'}</span>
                    {node.badge ? <span className={styles.badge}>{node.badge}</span> : null}
                    {kids.length > 0 ? <Icon name="chev-d" size={13} /> : null}
                  </button>
                  {kids.length > 0 && openId === node.id ? (
                    <ul className={styles.liveDrawerKids}>
                      {kids.map((kid) => (
                        <li key={kid.id}>
                          <button
                            type="button"
                            className={styles.liveDrawerKid}
                            aria-pressed={selection.includes(kid.id)}
                            onClick={() => commands.select(kid.id, 'single')}
                          >
                            {kid.label || '⟨بلا نص تنقل⟩'}
                          </button>
                        </li>
                      ))}
                    </ul>
                  ) : null}
                </li>
              );
            })}
          </ul>
        ) : (
          <nav className={styles.liveBar} aria-label="معاينة القائمة الرئيسية">
            <ul className={styles.liveBarList}>
              {top.map((node) => {
                const kids = childrenOf(nodes, node.id).filter((kid) => !kid.deleted);
                return (
                  <li key={node.id} className={styles.liveBarItem}>
                    <button
                      type="button"
                      className={styles.liveBarLink}
                      aria-pressed={selection.includes(node.id)}
                      aria-expanded={kids.length > 0 ? openId === node.id : undefined}
                      onClick={() => {
                        commands.select(node.id, 'single');
                        if (kids.length > 0) setOpenId(openId === node.id ? null : node.id);
                      }}
                    >
                      {node.icon ? <Icon name={node.icon} size={13} /> : null}
                      {node.label || '⟨بلا نص تنقل⟩'}
                      {node.badge ? <span className={styles.badge}>{node.badge}</span> : null}
                      {kids.length > 0 ? <Icon name="chev-d" size={11} /> : null}
                    </button>
                    {kids.length > 0 && openId === node.id ? (
                      <ul className={styles.liveMenu}>
                        {kids.map((kid) => (
                          <li key={kid.id}>
                            <button
                              type="button"
                              className={styles.liveMenuLink}
                              aria-pressed={selection.includes(kid.id)}
                              onClick={() => commands.select(kid.id, 'single')}
                            >
                              {kid.label || '⟨بلا نص تنقل⟩'}
                            </button>
                          </li>
                        ))}
                      </ul>
                    ) : null}
                  </li>
                );
              })}
            </ul>
          </nav>
        )}
      </div>
    </section>
  );
}
