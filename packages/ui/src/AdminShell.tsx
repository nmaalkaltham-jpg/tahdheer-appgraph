'use client';
// UNIT: ADMINSHELL
// FILE: AdminShell.tsx
// STYLE: AdminShell.module.css
// PARENT: UI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * قشرة لوحة الإدارة — خمسة تخطيطات في مكوّنٍ واحد.
 *
 * ─── القانون ───
 *
 * «التخطيط خاصّية لا ملفّ» (MIP §2.5). خمس قشراتٍ في خمسة ملفّات تتباعد بعد
 * أوّل تعديل: يُضاف عدّادٌ إلى إحداها وتُنسى الأربع، فيصير «التصميم الثاني»
 * نسخةً قديمة من الأوّل لا خيارًا عنه. القيمة الواحدة `nav` تُبدّل التخطيط
 * كلّه، والمنطق مشترك بحكم البناء لا بحكم الانضباط.
 *
 * ─── التخطيطات الخمسة ───
 *
 *   sidebar  شريطٌ جانبيّ كامل — مجموعاتٌ ووجهاتٌ ظاهرة. الكثافة العليا.
 *   rail     شريطٌ مصغّر بالأيقونات — يُفسح أقصى عرضٍ للجدول.
 *   topbar   تنقّلٌ علويّ — يُفسح أقصى **ارتفاع**، ويناسب الشاشات العريضة القصيرة.
 *   split    عمودان: المجموعات ثمّ وجهاتها — لمن يعمل في نطاقٍ واحد طويلًا.
 *   focus    بلا تنقّلٍ دائم — التبويبات وحدها، للعمل المركَّز على سطحٍ واحد.
 *
 * ─── إخفاء التبويبات وتحريكها ───
 *
 * `hiddenTabs` و`tabOrder` خاصّيتان مضبوطتان: المكوّن لا يملك التفضيل ولا
 * يحفظه — يعرضه ويُبلّغ بتغيّره. وحفظُه قرارُ التطبيق (تخزينٌ محلّيّ، أو
 * حساب مستخدم، أو رابط)، ومكوّنُ عرضٍ يقرّر أين يُحفظ التفضيل يفرض على كلّ
 * مستهلكٍ اختيارَه.
 *
 * والترتيب يقع بالسحب **وبلوحة المفاتيح** (`Ctrl` مع الأسهم): السحب وحده
 * يُقصي من لا يستعمل فأرة، و«التحريك الحرّ» يجب أن يكون حرًّا للجميع.
 *
 * ─── وما لا يفعله ───
 *
 * لا يعرف موجّهًا ولا مسارًا: `onSelect` تُبلّغ والتطبيق يوجّه. ومكوّنٌ في
 * حزمةٍ مشتركة يستورد موجّه إطارٍ بعينه يربط الحزمة بذلك الإطار إلى الأبد.
 */

import { useCallback, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import styles from './AdminShell.module.css';

export type AdminNavLayout = 'sidebar' | 'rail' | 'topbar' | 'split' | 'focus';

export interface AdminNavItem {
  id: string;
  label: string;
  /** مسار أيقونة على شبكة 24×24 — الحزمة لا تشحن مكتبة أيقونات. */
  icon?: string;
  /** عدّادٌ مقيس. الصفر لا يُعرض: «٠» و«لم نقس» يبدوان سواءً. */
  badge?: number;
  /** إنذارٌ يستحقّ لونًا — لا كلّ عدّاد. */
  alert?: boolean;
}

export interface AdminNavGroup {
  id: string;
  label: string;
  icon?: string;
  items: AdminNavItem[];
}

export interface AdminTab {
  id: string;
  label: string;
  badge?: number;
}

export interface AdminShellProps {
  /** التخطيط — القيمة الواحدة التي تُبدّل الشكل كلّه. */
  nav?: AdminNavLayout;
  brand: { name: string; tagline?: string };
  groups: AdminNavGroup[];
  /** الوجهة النشطة — بمعرّفها لا بمسارها: القشرة لا تعرف موجّهًا. */
  activeItem?: string;
  onSelectItem?: (id: string) => void;

  title: string;
  /** مسار التنقّل — أجزاءٌ لا نصٌّ واحد، فيُصيَّر الفاصل بالاتّجاه الصحيح. */
  trail?: string[];
  actions?: ReactNode;

  tabs?: AdminTab[];
  activeTab?: string;
  onSelectTab?: (id: string) => void;

  /** ما يُخفيه المستعمل — مضبوطٌ من الخارج، والمكوّن لا يحفظه. */
  hiddenTabs?: string[];
  tabOrder?: string[];
  onHiddenTabsChange?: (next: string[]) => void;
  onTabOrderChange?: (next: string[]) => void;
  /** المجموعات المخفيّة — نفس العقد، للتنقّل الرئيس. */
  hiddenGroups?: string[];
  onHiddenGroupsChange?: (next: string[]) => void;

  children?: ReactNode;
}

const EMPTY: string[] = [];

function Glyph({ path, size = 16 }: { path?: string; size?: number }) {
  if (path === undefined) return null;

  return (
    <svg
      className={styles.glyph}
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <path d={path} />
    </svg>
  );
}

/** يرتّب ويُسقط المخفيّ. العنصر خارج الترتيب يبقى في موضعه الأصليّ. */
function arrange<T>(items: readonly T[], keyOf: (item: T) => string, hidden: readonly string[], order: readonly string[]): T[] {
  const hiddenSet = new Set(hidden);
  const visible = items.filter((item) => !hiddenSet.has(keyOf(item)));

  if (order.length === 0) return visible;

  const rank = new Map(order.map((key, index) => [key, index]));

  return visible
    .map((item, index) => ({ item, index, rank: rank.get(keyOf(item)) }))
    .sort((a, b) => {
      if (a.rank === undefined && b.rank === undefined) return a.index - b.index;
      if (a.rank === undefined) return 1;
      if (b.rank === undefined) return -1;

      return a.rank - b.rank;
    })
    .map((entry) => entry.item);
}

export function AdminShell({
  nav = 'sidebar',
  brand,
  groups,
  activeItem,
  onSelectItem,
  title,
  trail = EMPTY,
  actions,
  tabs = [],
  activeTab,
  onSelectTab,
  hiddenTabs = EMPTY,
  tabOrder = EMPTY,
  onHiddenTabsChange,
  onTabOrderChange,
  hiddenGroups = EMPTY,
  onHiddenGroupsChange,
  children,
}: AdminShellProps) {
  const [editingTabs, setEditingTabs] = useState(false);
  const [editingNav, setEditingNav] = useState(false);
  const [openGroup, setOpenGroup] = useState<string | null>(groups[0]?.id ?? null);
  const dragging = useRef<string | null>(null);
  const [over, setOver] = useState<string | null>(null);

  const shownGroups = useMemo(
    () => (editingNav ? groups : arrange(groups, (g) => g.id, hiddenGroups, EMPTY)),
    [groups, hiddenGroups, editingNav],
  );

  const shownTabs = useMemo(
    () => (editingTabs ? tabs : arrange(tabs, (t) => t.id, hiddenTabs, tabOrder)),
    [tabs, hiddenTabs, tabOrder, editingTabs],
  );

  const toggleTab = useCallback(
    (id: string) => {
      onHiddenTabsChange?.(
        hiddenTabs.includes(id) ? hiddenTabs.filter((k) => k !== id) : [...hiddenTabs, id],
      );
    },
    [hiddenTabs, onHiddenTabsChange],
  );

  const moveTab = useCallback(
    (id: string, direction: -1 | 1) => {
      const base = tabOrder.length > 0 ? [...tabOrder] : tabs.map((t) => t.id);
      const from = base.indexOf(id);
      const to = from + direction;
      if (from === -1 || to < 0 || to >= base.length) return;

      const [moved] = base.splice(from, 1);
      if (moved === undefined) return;
      base.splice(to, 0, moved);
      onTabOrderChange?.(base);
    },
    [tabOrder, tabs, onTabOrderChange],
  );

  const dropOn = useCallback(
    (target: string) => {
      const source = dragging.current;
      dragging.current = null;
      setOver(null);
      if (source === null || source === target) return;

      const base = tabOrder.length > 0 ? [...tabOrder] : tabs.map((t) => t.id);
      const from = base.indexOf(source);
      const to = base.indexOf(target);
      if (from === -1 || to === -1) return;

      const [moved] = base.splice(from, 1);
      if (moved === undefined) return;
      base.splice(to, 0, moved);
      onTabOrderChange?.(base);
    },
    [tabOrder, tabs, onTabOrderChange],
  );

  const onTabKeys = (event: KeyboardEvent<HTMLDivElement>) => {
    if (editingTabs || shownTabs.length === 0) return;

    const index = shownTabs.findIndex((t) => t.id === activeTab);
    let next = -1;
    /* الأسهم معكوسة: في RTL يمينُ الشريط أوّلُه. */
    if (event.key === 'ArrowLeft') next = (index + 1) % shownTabs.length;
    else if (event.key === 'ArrowRight') next = (index - 1 + shownTabs.length) % shownTabs.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = shownTabs.length - 1;
    if (next === -1) return;

    event.preventDefault();
    const target = shownTabs[next];
    if (target !== undefined) onSelectTab?.(target.id);
  };

  const mini = nav === 'rail';

  const navTree = (
    <>
      {shownGroups.map((group) => {
        const groupOpen = nav === 'split' ? openGroup === group.id : true;
        const groupHidden = hiddenGroups.includes(group.id);

        return (
          <div key={group.id} className={styles.group} data-hidden={groupHidden ? '' : undefined}>
            <div className={styles.groupHead}>
              <button
                type="button"
                className={styles.groupLabel}
                aria-expanded={nav === 'split' ? groupOpen : undefined}
                onClick={() => nav === 'split' && setOpenGroup(group.id)}
                title={mini ? group.label : undefined}
              >
                <Glyph path={group.icon} size={15} />
                {mini ? null : <span>{group.label}</span>}
              </button>

              {editingNav && !mini ? (
                <button
                  type="button"
                  className={styles.eye}
                  aria-pressed={!groupHidden}
                  aria-label={groupHidden ? `أظهر ${group.label}` : `أخفِ ${group.label}`}
                  onClick={() =>
                    onHiddenGroupsChange?.(
                      groupHidden
                        ? hiddenGroups.filter((k) => k !== group.id)
                        : [...hiddenGroups, group.id],
                    )
                  }
                >
                  {groupHidden ? '␡' : '✓'}
                </button>
              ) : null}
            </div>

            {groupOpen ? (
              <ul className={styles.items}>
                {group.items.map((item) => (
                  <li key={item.id}>
                    <button
                      type="button"
                      className={styles.item}
                      aria-current={activeItem === item.id ? 'page' : undefined}
                      onClick={() => onSelectItem?.(item.id)}
                      title={mini ? item.label : undefined}
                    >
                      <Glyph path={item.icon} />
                      {mini ? null : <span className={styles.itemLabel}>{item.label}</span>}
                      {item.badge !== undefined && item.badge > 0 ? (
                        <span
                          className={styles.badge}
                          data-alert={item.alert === true ? '' : undefined}
                        >
                          <bdi dir="ltr">{item.badge}</bdi>
                        </span>
                      ) : null}
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        );
      })}
    </>
  );

  return (
    /*
     * الغلاف حاويةُ الاستعلام — والقشرة حفيدُه.
     *
     * `container-type` على القشرة نفسها لا يعمل: الحاوية لا تستعلم عن
     * نفسها، فتبقى قواعد الضيق مكتوبةً ولا تُطابَق أبدًا.
     */
    <div className={styles.frame}>
      <div className={styles.shell} data-nav={nav}>
      {nav === 'focus' ? null : (
        <aside className={styles.nav} aria-label="ملاحة اللوحة">
          <div className={styles.brand}>
            <span className={styles.mark} aria-hidden="true">
              {brand.name.trim().slice(0, 1)}
            </span>
            {mini ? null : (
              <span className={styles.brandText}>
                <strong>{brand.name}</strong>
                {brand.tagline === undefined ? null : <small>{brand.tagline}</small>}
              </span>
            )}
          </div>

          <nav className={styles.tree}>{navTree}</nav>

          {mini ? null : (
            <button
              type="button"
              className={styles.navEdit}
              aria-pressed={editingNav}
              onClick={() => setEditingNav((was) => !was)}
            >
              {editingNav ? 'تمّ' : 'إخفاء أقسام'}
            </button>
          )}
        </aside>
      )}

      <main className={styles.canvas}>
        <header className={styles.head}>
          <div className={styles.headText}>
            {trail.length === 0 ? null : (
              <nav className={styles.trail} aria-label="مسار التنقل">
                {/* الفاصل › وحده: ‹ و← ينعكسان في RTL فيقلبان المعنى. */}
                {trail.join(' › ')}
              </nav>
            )}
            <h1 className={styles.title}>{title}</h1>
          </div>
          {actions === undefined ? null : <div className={styles.actions}>{actions}</div>}
        </header>

        {tabs.length === 0 ? null : (
          <div className={styles.tabRow}>
            <div
              className={styles.tabs}
              role={editingTabs ? 'group' : 'tablist'}
              aria-label={editingTabs ? 'ترتيب التبويبات' : 'أقسام الصفحة'}
              onKeyDown={onTabKeys}
            >
              {shownTabs.map((tab) => {
                const on = tab.id === activeTab;
                const tabHidden = hiddenTabs.includes(tab.id);

                if (editingTabs) {
                  return (
                    <span
                      key={tab.id}
                      className={styles.chip}
                      data-hidden={tabHidden ? '' : undefined}
                      data-over={over === tab.id ? '' : undefined}
                      onDragOver={(event) => {
                        event.preventDefault();
                        setOver(tab.id);
                      }}
                      onDrop={() => dropOn(tab.id)}
                    >
                      <button
                        type="button"
                        className={styles.grip}
                        draggable
                        aria-label={`حرّك ${tab.label} — أو Ctrl مع الأسهم`}
                        onDragStart={() => {
                          dragging.current = tab.id;
                        }}
                        onDragEnd={() => {
                          dragging.current = null;
                          setOver(null);
                        }}
                        onKeyDown={(event) => {
                          if (!event.ctrlKey && !event.metaKey) return;
                          const back = event.key === 'ArrowRight';
                          const forward = event.key === 'ArrowLeft';
                          if (!back && !forward) return;
                          event.preventDefault();
                          moveTab(tab.id, back ? -1 : 1);
                        }}
                      >
                        ⠿
                      </button>
                      <span className={styles.chipLabel}>{tab.label}</span>
                      <button
                        type="button"
                        className={styles.eye}
                        aria-pressed={!tabHidden}
                        aria-label={tabHidden ? `أظهر ${tab.label}` : `أخفِ ${tab.label}`}
                        onClick={() => toggleTab(tab.id)}
                      >
                        {tabHidden ? '␡' : '✓'}
                      </button>
                    </span>
                  );
                }

                return (
                  <button
                    key={tab.id}
                    type="button"
                    role="tab"
                    className={styles.tab}
                    aria-selected={on}
                    tabIndex={on ? 0 : -1}
                    onClick={() => onSelectTab?.(tab.id)}
                  >
                    {tab.label}
                    {tab.badge !== undefined && tab.badge > 0 ? (
                      <span className={styles.tabBadge}>
                        <bdi dir="ltr">{tab.badge}</bdi>
                      </span>
                    ) : null}
                  </button>
                );
              })}
            </div>

            <button
              type="button"
              className={styles.tabEdit}
              aria-pressed={editingTabs}
              onClick={() => setEditingTabs((was) => !was)}
            >
              {editingTabs ? 'تمّ' : 'ترتيب'}
            </button>
          </div>
        )}

          <div className={styles.body}>{children}</div>
        </main>
      </div>
    </div>
  );
}
