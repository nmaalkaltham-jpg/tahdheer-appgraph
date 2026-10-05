'use client';
// UNIT: SIDEBAR
// FILE: sidebar.tsx
// STYLE: sidebar.module.css
// PARENT: ADMIN-SHELL, USE-NAV-COUNTS
// USES: COMPONENTS-ICONS, LIB-BROWSER-STORE, LIB-FORMAT, LIB-LAYOUT-PREFS, LIB-NAV, LOCALE +1
// API: none
// API-VIA: LIB-NAV
// MAP: docs/PROJECT_MAP.md

/**
 * الشريط الجانبيّ — شجرةٌ كاملة، لا جذورٌ وحدها.
 *
 * ─── ما تغيّر ولماذا ───
 *
 * كان الشريط يعرض ثماني **مساحات** فقط، وتبقى وجهاتها التسع والعشرون خلف
 * شريط تبويباتٍ في الرأس. أي أنّ الوصول إلى «المخزون» يكلّف: نقرةٌ على
 * «العمليّات» · تحميل صفحةٍ وسيطة · قراءة شريطٍ أفقيّ · نقرةٌ ثانية. الشجرة
 * كاملةً تجعلها نقرةً واحدة — وهو معيار القبول المكتوب: مستويان على الأكثر.
 *
 * ─── الصلاحيات ───
 *
 * لا تُعرض وجهةٌ لا يملك المستعمل قراءتها. والمجموعة التي لا يملك منها شيئًا
 * تُعلَن «لا صلاحية» ولا تُخفى: الإخفاء يجعله يظنّ الميزة غير موجودة بدل أن
 * يعرف أنّها محجوبة عنه — وهذان جوابان مختلفان تمامًا حين يسأل زميله.
 *
 * ─── العدّادات ───
 *
 * تُمرَّر من الخارج ولا تُخترع هنا، ولا تُعرض إلّا حين تكون رقمًا مقيسًا.
 * وعدّادٌ صفريّ لا يُعرض: «٠» و«لم نقس» يبدوان سواءً، والفرق بينهما هو كلّ
 * الفرق.
 */

import Link from 'next/link';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Icon } from './icons';
import { useT } from './locale';
import { useStoredItem } from '../lib/browser-store';
import { useLayoutScope } from '../lib/layout-prefs';
import { useReorder } from './ui/reorderable';
import { allDestinations, workspaceFor, WORKSPACES, type NavDestination } from '../lib/nav';
import { count } from '../lib/format';
import styles from './sidebar.module.css';

/** المجموعات المطويّة — يبقى الطيّ بعد التنقّل وإعادة التحميل. */
const CLOSED_KEY = 'admin.nav.closed';
/** الوضع المصغّر — قرار مساحةٍ يملكه المستعمل، فيُحفظ. */
const MINI_KEY = 'admin.nav.mini';

/**
 * نطاقا التفضيلات: المجموعات، والوجهات داخلها.
 *
 * نطاقان لا واحد لأنّهما قراران مختلفان: «لا أعمل في الوسائط إطلاقًا»
 * تُخفي مجموعة، و«لا أفتح المراجعات» تُخفي وجهةً داخل مجموعةٍ أعمل فيها.
 * ودمجُهما كان سيجعل إخفاء وجهةٍ يُخفي أختها حين تتشابه المفاتيح.
 */
const GROUP_SCOPE = 'nav.groups';
const DEST_SCOPE = 'nav.destinations';

/** عدّادٌ واحد لكلّ وجهة، بمفتاح `href`. */
export type NavCounts = Readonly<Record<string, { value: number; alert?: boolean } | undefined>>;

function isStringArray(value: unknown): value is string[] {
  return Array.isArray(value) && value.every((v) => typeof v === 'string');
}

export function Sidebar({
  can,
  pathname,
  counts,
  onNavigate,
  onCloseDrawer,
  footer,
  singleOpenGroup = false,
}: {
  can: (permission: string) => boolean;
  pathname: string;
  counts?: NavCounts;
  /** يُغلق الدرج بعد الانتقال — على الجوال يبقى مفتوحًا فوق الصفحة لولاه. */
  onNavigate: () => void;
  onCloseDrawer: () => void;
  footer: React.ReactNode;
  /** تخطيط «عمودان»: مجموعةٌ واحدة مفتوحة — وهو كلّ تمايزه. */
  singleOpenGroup?: boolean;
}) {
  const t = useT();
  const storedClosed = useStoredItem(CLOSED_KEY);
  const storedMini = useStoredItem(MINI_KEY);

  const [closedOverride, setClosedOverride] = useState<ReadonlySet<string> | null>(null);
  const [miniOverride, setMiniOverride] = useState<boolean | null>(null);
  const [editing, setEditing] = useState(false);

  const groupPrefs = useLayoutScope(GROUP_SCOPE);
  const destPrefs = useLayoutScope(DEST_SCOPE);

  const parsedClosed = useMemo<ReadonlySet<string>>(() => {
    try {
      if (storedClosed === null) return new Set();
      /*
       * تحقّق لا تأكيد: القيمة يملكها المستعمل ويستطيع تحريرها، وقيمةٌ تالفة
       * كانت تعبر إلى `Set` ثمّ تنفجر عند أوّل `has()` في التصيير.
       */
      const parsed: unknown = JSON.parse(storedClosed);
      return isStringArray(parsed) ? new Set(parsed) : new Set();
    } catch {
      return new Set();
    }
  }, [storedClosed]);

  const closed = closedOverride ?? parsedClosed;
  /* الخادم لا يعرف تفضيل المستعمل؛ «موسَّع» هو الفشل الآمن وقت الترطيب. */
  const mini = miniOverride ?? storedMini === '1';

  const toggleGroup = useCallback(
    (id: string) => {
      setClosedOverride((was) => {
        const next = new Set(was ?? parsedClosed);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        try {
          window.localStorage.setItem(CLOSED_KEY, JSON.stringify([...next]));
        } catch {
          // التخزين رفاهية؛ الطيّ يعمل داخل الجلسة على أي حال.
        }
        return next;
      });
    },
    [parsedClosed],
  );

  const toggleMini = useCallback(() => {
    setMiniOverride((was) => {
      const next = !(was ?? storedMini === '1');
      try {
        window.localStorage.setItem(MINI_KEY, next ? '1' : '0');
      } catch {
        // كما أعلاه.
      }
      return next;
    });
  }, [storedMini]);

  /*
   * عرض العمود يُعلَن على الجسم لا يُمرَّر خاصّيةً إلى القشرة.
   *
   * الشبكة في `globals.css` تملك العمودين، والدرج يُعلن حالته بالطريقة نفسها
   * (`data-drawer`). ورفعُ الحالة إلى القشرة كان سيجعل مكوّنَين يملكان
   * تفضيلًا واحدًا، ويكفي أن يُقرأ من مكانين ليختلفا.
   */
  useEffect(() => {
    const { body } = document;
    if (mini) body.dataset.navMini = '1';
    else delete body.dataset.navMini;
    return () => {
      delete body.dataset.navMini;
    };
  }, [mini]);

  const match = workspaceFor(pathname);

  /* الوجهات المسموحة لكلّ مجموعة — تُحسب مرّة لا مرّةً لكلّ صفّ. */
  const reachable = useMemo(() => {
    const map = new Map<string, NavDestination[]>();
    for (const { ws, dest } of allDestinations()) {
      if (dest.permission !== undefined && !can(dest.permission)) continue;
      const list = map.get(ws.id);
      if (list === undefined) map.set(ws.id, [dest]);
      else list.push(dest);
    }
    return map;
  }, [can]);

  /*
   * المجموعات المعروضة: المسموحة، ثمّ مرتَّبةً ومُنقّاةً بالتفضيل.
   * وفي وضع الترتيب تُعرض المخفيّة أيضًا — وإلّا تعذّر إرجاعها.
   */
  const groups = editing ? WORKSPACES : groupPrefs.arrange(WORKSPACES, (ws) => ws.id);

  const groupReorder = useReorder({
    keys: (editing ? WORKSPACES : groupPrefs.arrange(WORKSPACES, (ws) => ws.id)).map((ws) => ws.id),
    onReorder: groupPrefs.reorder,
    attribute: 'data-group-key',
  });

  const destReorder = useReorder({
    /* مفتاح الوجهة مسارُها: فريدٌ عبر المجموعات كلّها. */
    keys: allDestinations().map(({ dest }) => dest.href),
    onReorder: destPrefs.reorder,
    attribute: 'data-dest-key',
  });

  return (
    <div className={mini ? `${styles.rail} ${styles.mini}` : styles.rail}>
      <div className={styles.brand}>
        {/*
          العلامة الحقيقية بدل الحرف «ت» النائب. مخفيّة عن القارئ الآليّ لأنّ
          اسم المتجر مكتوب نصًّا في الرابط المجاور.
        */}
        <span className={styles.mark} aria-hidden="true">
          {/* eslint-disable-next-line @next/next/no-img-element -- علامة ثابتة محسَّنة سلفًا بحجمٍ واحد؛ لا فائدة من مُحسِّن الصور */}
          <img src="/brand/logo-mark.png" alt="" width={256} height={227} />
        </span>
        <Link className={styles.brandText} href="/dashboard" onClick={onNavigate}>
          <strong>{t('التحاضير الحديثة')}</strong>
          <small>{t('لوحة التشغيل')}</small>
        </Link>
        <button
          type="button"
          className={styles.drawerClose}
          onClick={onCloseDrawer}
          aria-label={t('إغلاق القائمة')}
        >
          <Icon name="close" size={20} />
        </button>
      </div>

      <nav className={styles.tree} aria-label={t('ملاحة اللوحة')}>
        {groups.map((ws) => {
          const allowed = reachable.get(ws.id) ?? [];
          const destinations = editing ? allowed : destPrefs.arrange(allowed, (d) => d.href);
          /*
           * في «عمودان» المجموعة النشطة وحدها مفتوحة، ولا يُقرأ الطيّ
           * المحفوظ: تفضيلُ الطيّ يخصّ الشريط الكامل، وقراءتُه هنا كانت
           * ستُغلق المجموعة التي يقف فيها المستعمل.
           */
          const open = singleOpenGroup ? match?.ws.id === ws.id : !closed.has(ws.id);
          const groupHidden = groupPrefs.isHidden(ws.id);

          return (
            <div
              key={ws.id}
              className={styles.group}
              data-group-key={ws.id}
              data-hidden={groupHidden ? '' : undefined}
              data-over={
                groupReorder.over === ws.id && groupReorder.dragging !== ws.id ? '' : undefined
              }
            >
              {editing ? (
                <div className={styles.editRow}>
                  <button
                    type="button"
                    className={styles.grip}
                    aria-label={`حرّك مجموعة «${t(ws.label)}»`}
                    onKeyDown={(event) => {
                      if (!event.ctrlKey && !event.metaKey) return;
                      const up = event.key === 'ArrowUp';
                      const down = event.key === 'ArrowDown';
                      if (!up && !down) return;
                      event.preventDefault();
                      groupPrefs.move(ws.id, up ? -1 : 1, WORKSPACES.map((w) => w.id));
                    }}
                    {...groupReorder.handleProps(ws.id)}
                  >
                    <Icon name="grid" size={12} />
                  </button>
                  <span className={styles.editLabel}>{t(ws.label)}</span>
                  <button
                    type="button"
                    className={styles.eye}
                    aria-pressed={!groupHidden}
                    aria-label={groupHidden ? `أظهر «${t(ws.label)}»` : `أخفِ «${t(ws.label)}»`}
                    onClick={() => groupPrefs.toggleHidden(ws.id)}
                  >
                    <Icon name={groupHidden ? 'unlink' : 'check'} size={12} />
                  </button>
                </div>
              ) : null}
              <button
                type="button"
                className={styles.groupToggle}
                aria-expanded={open}
                aria-controls={`nav-${ws.id}`}
                /*
                 * «الصفحة الحاليّة داخل هذه المجموعة» — إشارةٌ لازمة لا زينة.
                 *
                 * ثماني مجموعات وتسعٌ وعشرون وجهة: من طوى المجموعة التي يعمل
                 * فيها كان يفقد كلّ أثرٍ لموضعه، لأنّ `aria-current` يقع على
                 * رابطٍ مطويّ لا يُرى. والعلامة هنا **بصريّة فقط**
                 * (`data-`)، فلا تُعلن «الصفحة الحاليّة» مرّتين لقارئ الشاشة.
                 */
                data-here={match?.ws.id === ws.id ? '' : undefined}
                onClick={() => toggleGroup(ws.id)}
                title={mini ? t(ws.label) : undefined}
              >
                <Icon name={ws.icon} size={16} className={styles.groupIcon} />
                <span className={styles.groupLabel}>{t(ws.label)}</span>
                <Icon name="chevron" size={14} className={styles.chevron} />
              </button>

              {open ? (
                <ul id={`nav-${ws.id}`} className={styles.links}>
                  {destinations.length === 0 ? (
                    <li className={styles.denied}>
                      <Icon name="shield" size={14} />
                      {t('لا صلاحية')}
                    </li>
                  ) : (
                    destinations.map((dest) => {
                      const badge = counts?.[dest.href];
                      const destHidden = destPrefs.isHidden(dest.href);

                      /*
                       * في وضع الترتيب يُستبدل الرابط بصفٍّ فيه مقبضٌ وعين:
                       * رابطٌ يُسحب ويُفتح في آنٍ واحد يجعل كلّ سحبةٍ قصيرة
                       * تنقّلًا — وهو ما يجعل الترتيب مستحيلًا على اللمس.
                       */
                      if (editing) {
                        return (
                          <li
                            key={dest.href}
                            className={styles.editRow}
                            data-dest-key={dest.href}
                            data-hidden={destHidden ? '' : undefined}
                            data-over={
                              destReorder.over === dest.href && destReorder.dragging !== dest.href
                                ? ''
                                : undefined
                            }
                          >
                            <button
                              type="button"
                              className={styles.grip}
                              aria-label={`حرّك «${t(dest.label)}»`}
                              onKeyDown={(event) => {
                                if (!event.ctrlKey && !event.metaKey) return;
                                const up = event.key === 'ArrowUp';
                                const down = event.key === 'ArrowDown';
                                if (!up && !down) return;
                                event.preventDefault();
                                destPrefs.move(
                                  dest.href,
                                  up ? -1 : 1,
                                  allowed.map((d) => d.href),
                                );
                              }}
                              {...destReorder.handleProps(dest.href)}
                            >
                              <Icon name="grid" size={12} />
                            </button>
                            <Icon name={dest.icon ?? 'grid'} size={14} className={styles.linkIcon} />
                            <span className={styles.editLabel}>{t(dest.label)}</span>
                            <button
                              type="button"
                              className={styles.eye}
                              aria-pressed={!destHidden}
                              aria-label={
                                destHidden ? `أظهر «${t(dest.label)}»` : `أخفِ «${t(dest.label)}»`
                              }
                              onClick={() => destPrefs.toggleHidden(dest.href)}
                            >
                              <Icon name={destHidden ? 'unlink' : 'check'} size={12} />
                            </button>
                          </li>
                        );
                      }

                      return (
                        <li key={dest.href}>
                          <Link
                            className={styles.link}
                            data-icon={dest.icon ?? 'grid'}
                            href={dest.href}
                            /*
                             * أطول مطابقة وحدها تحمل `aria-current`: «المؤشّرات»
                             * و«نظام تشغيل النمو» يتشاركان البادئة، ووسمُ
                             * الاثنين يُعلن «الصفحة الحاليّة» مرّتين.
                             */
                            aria-current={match?.active === dest.href ? 'page' : undefined}
                            onClick={onNavigate}
                            title={mini ? t(dest.label) : undefined}
                          >
                            <Icon
                              name={dest.icon ?? 'grid'}
                              size={16}
                              className={styles.linkIcon}
                            />
                            <span className={styles.linkLabel}>{t(dest.label)}</span>
                            {badge !== undefined && badge.value > 0 ? (
                              <span
                                className={
                                  badge.alert === true
                                    ? `${styles.badge} ${styles.badgeAlert}`
                                    : styles.badge
                                }
                              >
                                <bdi dir="ltr">{count(badge.value)}</bdi>
                              </span>
                            ) : null}
                          </Link>
                        </li>
                      );
                    })
                  )}
                </ul>
              ) : null}
            </div>
          );
        })}
      </nav>

      <div className={styles.footer}>
        <button
          type="button"
          className={styles.miniToggle}
          aria-pressed={editing}
          onClick={() => setEditing((was) => !was)}
        >
          <Icon name={editing ? 'check' : 'cog'} size={16} />
          <span>{editing ? t('تمّ الترتيب') : t('ترتيب الشريط')}</span>
        </button>

        {editing && (groupPrefs.customized || destPrefs.customized) ? (
          <button
            type="button"
            className={styles.miniToggle}
            onClick={() => {
              groupPrefs.reset();
              destPrefs.reset();
            }}
          >
            <Icon name="refresh" size={16} />
            <span>{t('استعادة الافتراضي')}</span>
          </button>
        ) : null}

        <button
          type="button"
          className={styles.miniToggle}
          onClick={toggleMini}
          aria-pressed={mini}
          title={mini ? t('توسيع الشريط') : t('تصغير الشريط')}
        >
          <Icon name="chevron" size={16} className={styles.chevron} />
          <span>{mini ? t('توسيع الشريط') : t('تصغير الشريط')}</span>
        </button>
        {footer}
      </div>
    </div>
  );
}
