'use client';
// UNIT: PAGE-HEAD
// FILE: page-head.tsx
// STYLE: page-head.module.css
// PARENT: ADMIN-SHELL, LAYOUT-HOME, RESOURCE
// USES: COMPONENTS-ICONS, LIB-FORMAT, LIB-LAYOUT-PREFS, LIB-NAV, LOCALE, STORE-URL +1
// API: /admin/ops/summary
// MAP: docs/PROJECT_MAP.md

/**
 * الرأس الموحّد — بديل ثلاث طبقات كانت تتكدّس أعلى كل صفحة.
 *
 * كان أعلى الشاشة يحمل: بطاقةً بيضاء كاملة العرض بارتفاع ٦٨px لا تحمل إلا
 * زرًّا واحدًا («مركز الاختصارات»)، ثمّ تبويبات المساحة، ثمّ `PageHeader`
 * بمساره وعنوانه وإجراءاته، ثمّ شريطًا داخليًّا للصفحة، ثمّ تبويبات داخلية.
 * خمس طبقات قبل أوّل معلومة.
 *
 * ─── لماذا لم أعدّل الصفحات الإحدى والأربعين؟ ───
 *
 * `PageHeader` يبقى بتوقيعه نفسه في كل صفحة، لكنّه **لا يصيّر شيئًا في
 * مكانه**: ينشر `{trail,title}` إلى هذا السياق، ويمرّر `actions` عبر بوّابة
 * DOM إلى فتحتها في الرأس. فالنتيجة رأسٌ واحد بلا لمس صفحة واحدة — ولو
 * نُقلت الوسوم يدويًّا لتفرّقت التسميات وتقادم بعضها.
 *
 * و`actions` تمرّ بوّابةً لا حالةً: عقدة React جديدة تُنشأ في كل تصيير، فحفظها
 * في `useState` يوقع حلقة تحديث لا تنتهي. البوّابة تنقل الشجرة كما هي بلا
 * مقارنة.
 */

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { createPortal } from 'react-dom';
import { LanguageSwitch, useT } from './locale';
import { Icon } from './icons';
import { useStoreUrl } from './store-url';
import { workspaceFor } from '../lib/nav';
import { count } from '../lib/format';
import { useOpsSummary } from './use-nav-counts';
import {
  ADMIN_DENSITIES,
  ADMIN_LAYOUTS,
  ADMIN_THEMES,
  useAdminDensity,
  useAdminLayout,
  useAdminTheme,
} from '../lib/layout-prefs';
import styles from './page-head.module.css';

interface Head {
  trail: string;
  title: string;
}

interface PageHeadValue {
  head: Head | null;
  publish: (head: Head | null) => void;
  /** فتحة الإجراءات في الرأس — البوّابة تحتاج عقدة DOM حقيقية. */
  slot: HTMLElement | null;
  setSlot: (el: HTMLElement | null) => void;
}

const Ctx = createContext<PageHeadValue | null>(null);

export function PageHeadProvider({ children }: { children: ReactNode }) {
  const [head, setHead] = useState<Head | null>(null);
  const [slot, setSlot] = useState<HTMLElement | null>(null);
  const value = useMemo<PageHeadValue>(
    () => ({ head, publish: setHead, slot, setSlot }),
    [head, slot],
  );
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

function usePageHead(): PageHeadValue | null {
  return useContext(Ctx);
}

/**
 * ناشر رأس الصفحة — يُستدعى كما كان `PageHeader` تمامًا.
 *
 * خارج المزوّد (اختبار، أو شجرة بلا قشرة) يصيّر رأسًا محلّيًّا كما كان قبل
 * الدمج: الصفحة يجب أن تبقى مفهومة بلا القشرة، لا أن تفقد عنوانها.
 */
export function PageHeadPublisher({
  trail,
  title,
  actions,
}: {
  trail: string;
  title: string;
  actions?: ReactNode;
}) {
  const ctx = usePageHead();
  const publish = ctx?.publish;

  useEffect(() => {
    if (publish === undefined) return;
    publish({ trail, title });
    // التنظيف عند التفكيك: صفحةٌ بلا رأس يجب ألّا ترث عنوان سابقتها.
    return () => publish(null);
  }, [publish, trail, title]);

  if (ctx === null) {
    return (
      <header className="page-head">
        <nav className="crumbs" aria-label="مسار التنقل">
          لوحة التشغيل › {trail}
        </nav>
        <div className="page-head-row">
          <h1>{title}</h1>
          {actions ? <div className="page-actions">{actions}</div> : null}
        </div>
      </header>
    );
  }

  return actions && ctx.slot ? createPortal(actions, ctx.slot) : null;
}

/** ما يقبل التركيز داخل لوحٍ منسدل — قائمةُ مصيدة التبويب. */
const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * دورةُ لوحٍ منسدل: Escape يُغلق ويعيد التركيز، والنقر خارجه يُغلق فحسب.
 *
 * كانت منسوخةً حرفًا بحرف في الجرس وقائمة الحساب، فكان إصلاح إحداهما يترك
 * الأخرى — وهو ما وقع فعلًا: اللوح المُعلَن `dialog` بقي بلا `aria-modal`
 * وبلا مصيدةٍ للتركيز، فكان ‎Tab‎ يخرج منه إلى صفحةٍ ما زال اللوح فوقها.
 *
 * و`modal` ليس زينةً دلاليّة: من أعلن نفسه حوارًا نمطيًّا وجب أن **يستقبل**
 * التركيز عند فتحه وأن يدور داخله — وإلّا فالإعلان كذبٌ على قارئ الشاشة.
 */
function useDismissable({
  open,
  setOpen,
  panelRef,
  buttonRef,
  modal = false,
}: {
  open: boolean;
  setOpen: (next: boolean) => void;
  panelRef: React.RefObject<HTMLDivElement | null>;
  buttonRef: React.RefObject<HTMLButtonElement | null>;
  modal?: boolean;
}) {
  useEffect(() => {
    if (!open) return;

    /* التركيز على الحاوية لا على أوّل عنصر: يُقرأ اسم اللوح أوّلًا. */
    if (modal) panelRef.current?.focus();

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false);
        buttonRef.current?.focus();
        return;
      }

      if (!modal || event.key !== 'Tab') return;

      const panel = panelRef.current;
      if (panel === null) return;

      const focusable = [...panel.querySelectorAll<HTMLElement>(FOCUSABLE)];
      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      /*
       * لوحٌ بلا عنصرٍ قابل للتركيز (جرسٌ لا إنذار فيه): التبويب يبقى عليه.
       * وإطلاقُه إلى الصفحة تحته يُبطل ما أعلنّاه بـ`aria-modal` — ويترك
       * المستعمل خلف لوحٍ ما زال مفتوحًا.
       */
      if (first === undefined || last === undefined) {
        event.preventDefault();
        return;
      }

      const here = document.activeElement;
      if (event.shiftKey && (here === first || here === panel)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && here === last) {
        event.preventDefault();
        first.focus();
      }
    };

    /* نقرٌ خارج اللوح يُغلق بلا خطف تركيز: المستعمل ذهب إلى شيءٍ آخر قصده. */
    const onDown = (event: PointerEvent) => {
      const el = event.target as HTMLElement | null;
      const panel = panelRef.current;
      if (panel !== null && el !== null && !panel.contains(el) && el !== buttonRef.current) {
        setOpen(false);
      }
    };

    window.addEventListener('keydown', onKey);
    window.addEventListener('pointerdown', onDown);

    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('pointerdown', onDown);
    };
  }, [open, setOpen, modal, panelRef, buttonRef]);
}

/**
 * جرس التنبيهات — عدّاداتُ تشغيلٍ مقيسة، لا صندوق رسائل.
 *
 * ─── لماذا لا «غير مقروء» بالمعنى المعتاد ───
 *
 * لا نظام إشعاراتٍ في الخادم: لا جدول، ولا حالة «قُرئ». واختراعُ عدّادٍ
 * يتناقص بالنقر كان سيَعِد بذاكرةٍ لا وجود لها — يُطفأ الرقم ويبقى العطل.
 * فالعدد هنا هو **عدد العدّادات المنذِرة التي قيمتها فوق الصفر**: يهبط حين
 * يُعالَج العطل لا حين يُنقر الجرس. وهذا هو الصدق الممكن اليوم.
 */
function OpsBell({ enabled }: { enabled: boolean }) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const panelRef = useRef<HTMLDivElement | null>(null);
  const buttonRef = useRef<HTMLButtonElement | null>(null);
  const { data } = useOpsSummary(enabled);

  const alerts = useMemo(() => {
    if (data === undefined) return [];
    return Object.entries(data.counters).flatMap(([key, counter]) =>
      counter !== undefined && counter.state === 'MEASURED' && counter.alert && counter.value > 0
        ? [{ key, ...counter }]
        : [],
    );
  }, [data]);

  const close = useCallback(() => {
    setOpen(false);
    buttonRef.current?.focus();
  }, []);

  useDismissable({ open, setOpen, panelRef, buttonRef, modal: true });

  if (!enabled) return null;

  return (
    <div className={styles.pop}>
      <button
        ref={buttonRef}
        type="button"
        className={styles.tool}
        aria-expanded={open}
        aria-haspopup="dialog"
        onClick={() => setOpen((was) => !was)}
        aria-label={
          alerts.length === 0
            ? t('تنبيهات التشغيل')
            : `${t('تنبيهات التشغيل')} — ${count(alerts.length)}`
        }
      >
        <Icon name="bell" size={18} />
        {alerts.length > 0 ? (
          <span className={styles.dot}>
            <bdi dir="ltr">{count(alerts.length)}</bdi>
          </span>
        ) : null}
      </button>

      {open ? (
        <div
          ref={panelRef}
          className={styles.panel}
          role="dialog"
          aria-modal="true"
          /* هدف التركيز عند الفتح — الاسم يُقرأ قبل أوّل تنبيه. */
          tabIndex={-1}
          aria-label={t('تنبيهات التشغيل')}
        >
          <h2 className={styles.panelTitle}>{t('تنبيهات التشغيل')}</h2>
          {alerts.length === 0 ? (
            <p className={styles.panelEmpty}>
              {t('لا إنذار مفتوح. العدّادات المُقاسة كلّها عند الصفر.')}
            </p>
          ) : (
            <ul className={styles.panelList}>
              {alerts.map((alert) => (
                <li key={alert.key}>
                  <Link href={alert.href} className={styles.panelItem} onClick={close}>
                    <Icon name="alert" size={16} />
                    <span>{t(alert.label)}</span>
                    <bdi dir="ltr" className={styles.panelCount}>
                      {count(alert.value)}
                    </bdi>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : null}
    </div>
  );
}

/**
 * لوح الحساب — الهويّة وتفضيلات العرض والخروج.
 *
 * ─── لماذا لا `role="menu"` ───
 *
 * كان اللوح يُعلن `menu` وأبناؤه `menuitem`، و**نمط القائمة يوجب لوحة
 * مفاتيح كاملة**: الأسهم تنقل التركيز، وHome/End يقفزان إلى الطرفين، ولا
 * يدخل ‎Tab‎ بين العناصر أصلًا. ولم يكن من ذلك سطرٌ واحد — فمن يصل باللوحة
 * يسمع «قائمة، عنصر ١ من ٢» ثمّ لا يجد الأسهم تعمل.
 *
 * والبديل الصحيح هنا ليس كتابة ذلك المنطق: اللوح **ليس قائمة أوامر** بل
 * كشفٌ يحوي بطاقة هويّة وثلاث مجموعات تفضيلات ورابطًا وزرًّا. فالأدقّ —
 * والأبسط — كشفٌ عاديّ (`aria-expanded` على الزرّ) بمحتوًى يتصفّح بـ‎Tab‎
 * كما يتوقّع كلّ مستعمل.
 */
function UserMenu({
  user,
  onSignOut,
}: {
  user: { name: string; email: string; roleLabel: string };
  onSignOut: () => void;
}) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const panelRef = useRef<HTMLDivElement | null>(null);
  const buttonRef = useRef<HTMLButtonElement | null>(null);
  const { layout, setLayout } = useAdminLayout();
  const { theme, setTheme } = useAdminTheme();
  const { density, setDensity } = useAdminDensity();

  const close = useCallback(() => {
    setOpen(false);
    buttonRef.current?.focus();
  }, []);

  useDismissable({ open, setOpen, panelRef, buttonRef });

  /* أوّل حرفٍ من الاسم — لا صورة: لا مصدر صورٍ للمشرفين في الخادم. */
  const initial = user.name.trim().slice(0, 1);

  return (
    <div className={styles.pop}>
      <button
        ref={buttonRef}
        type="button"
        className={styles.avatar}
        aria-expanded={open}
        onClick={() => setOpen((was) => !was)}
        aria-label={`${t('حساب')} ${user.name}`}
      >
        <span aria-hidden="true">{initial}</span>
      </button>

      {open ? (
        <div ref={panelRef} className={styles.panel} aria-label={t('لوح الحساب')}>
          <div className={styles.identity}>
            <strong>{user.name}</strong>
            <bdi dir="ltr" className={styles.identityMail}>
              {user.email}
            </bdi>
            <span className={styles.identityRole}>{user.roleLabel}</span>
          </div>
          {/*
            * اختيار التصميم داخل قائمة الحساب.
            *
            * تفضيلٌ يخصّ هذا المستعمل في هذا المتصفّح — لا إعدادَ متجرٍ يراه
            * غيره. ووضعُه في «الإعدادات» كان سيوحي بأنّه يغيّر ما يراه
            * الجميع، وهو لا يفعل.
            */}
          <div className={styles.layoutGroup} role="group" aria-label={t('تصميم اللوحة')}>
            <span className={styles.panelTitle}>{t('تصميم اللوحة')}</span>
            {ADMIN_LAYOUTS.map((option) => (
              <button
                key={option.id}
                type="button"
                className={styles.layoutItem}
                aria-pressed={layout === option.id}
                onClick={() => setLayout(option.id)}
              >
                <span className={styles.layoutName}>{t(option.label)}</span>
                <span className={styles.layoutHint}>{t(option.hint)}</span>
              </button>
            ))}
          </div>

          {/*
            * السمة والكثافة خياران قصيران من ثلاثةٍ واثنين — فصفٌّ مقسَّم لا
            * قائمةٌ رأسيّة: المقارنة بينها بصريّةٌ فوريّة، والقائمة الرأسيّة
            * كانت تأخذ من ارتفاع اللوح بقدر ما يأخذه التخطيط بخياراته الخمسة
            * وشروحها — وهي لا تستحقّه.
            */}
          <div className={styles.prefRow} role="group" aria-label={t('السمة')}>
            <span className={styles.prefLabel}>{t('السمة')}</span>
            <div className={styles.seg}>
              {ADMIN_THEMES.map((option) => (
                <button
                  key={option.id}
                  type="button"
                  className={styles.segItem}
                  aria-pressed={theme === option.id}
                  onClick={() => setTheme(option.id)}
                >
                  {t(option.label)}
                </button>
              ))}
            </div>
          </div>

          {/*
            * الكثافة إلى جوار السمة: كلاهما تفضيلُ **عرضٍ** يخصّ هذا المتصفّح،
            * ويُبدَّلان في اللحظة نفسها حين ينتقل المشغّل من التدقيق إلى
            * مسح الجداول.
            */}
          <div className={styles.prefRow} role="group" aria-label={t('كثافة العرض')}>
            <span className={styles.prefLabel}>{t('الكثافة')}</span>
            <div className={styles.seg}>
              {ADMIN_DENSITIES.map((option) => (
                <button
                  key={option.id}
                  type="button"
                  className={styles.segItem}
                  aria-pressed={density === option.id}
                  title={t(option.hint)}
                  onClick={() => setDensity(option.id)}
                >
                  {t(option.label)}
                </button>
              ))}
            </div>
          </div>

          {/* بقيّة التفضيلات (اللون · الزوايا · المؤثّرات) في صفحتها بمعاينةٍ حيّة. */}
          <Link href="/settings?t=appearance" className={styles.panelItem} onClick={close}>
            <Icon name="palette" size={16} />
            <span>{t('المظهر والمؤثّرات')}</span>
          </Link>
          <Link href="/settings" className={styles.panelItem} onClick={close}>
            <Icon name="cog" size={16} />
            <span>{t('الإعدادات')}</span>
          </Link>
          <button
            type="button"
            className={styles.panelItem}
            onClick={() => {
              close();
              onSignOut();
            }}
          >
            <Icon name="logout" size={16} flip />
            <span>{t('تسجيل الخروج')}</span>
          </button>
        </div>
      ) : null}
    </div>
  );
}

/**
 * الرأس نفسه — لاصق أعلى العمود، ارتفاعه ٥٦–٦٤px.
 *
 * `position: sticky` لا `fixed`: العمود الرئيسي هو ما يمرّر، فرأسٌ مثبَّت
 * بالنافذة كان سيحتاج حشوًا يدويًّا يتقادم مع كل تغيير ارتفاع.
 *
 * والمسار يُبنى من سجلّ التنقّل لا من نصٍّ تكتبه كلّ صفحة: «لوحة التشغيل ›
 * المساحة › الوجهة». وما تنشره الصفحة يبقى آخر جزء، فلا تسمية تتقادم في
 * واحدٍ وأربعين ملفًّا حين تتغيّر تسميةُ مساحة.
 */
export function AdminTopbar({
  onMenu,
  palette,
  user,
  onSignOut,
  canReadOps,
}: {
  /** زرّ قائمة الجوال — يظهر تحت 900px وحده. */
  onMenu: () => void;
  /** مُشغّل مركز الاختصارات — يأتي جاهزًا من القشرة. */
  palette: ReactNode;
  user: { name: string; email: string; roleLabel: string };
  onSignOut: () => void;
  /** `/admin/ops/summary` محروسٌ بـ`orders.read` — بلا صلاحيةٍ لا يُطلب. */
  canReadOps: boolean;
}) {
  const ctx = usePageHead();
  const t = useT();
  const pathname = usePathname();
  const storeUrl = useStoreUrl();
  const match = workspaceFor(pathname ?? '');

  return (
    <header className={styles.bar}>
      <button
        type="button"
        className={styles.menu}
        data-menu-button=""
        onClick={onMenu}
        aria-label={t('فتح قائمة المساحات')}
      >
        <Icon name="grid" size={20} />
      </button>

      <div className={styles.text}>
        <nav className={styles.crumbs} aria-label={t('مسار التنقل')}>
          <Link href="/dashboard">{t('لوحة التشغيل')}</Link>
          {match !== null ? (
            <>
              <span aria-hidden="true">›</span>
              <span>{t(match.ws.label)}</span>
              <span aria-hidden="true">›</span>
              <Link href={match.destination.href}>{t(match.destination.label)}</Link>
            </>
          ) : null}
          {ctx?.head ? (
            <>
              <span aria-hidden="true">›</span>
              <span>{t(ctx.head.trail)}</span>
            </>
          ) : null}
        </nav>
        {ctx?.head ? <h1 className={styles.title}>{t(ctx.head.title)}</h1> : null}
      </div>

      {/* فتحة إجراءات الصفحة — تُملأ ببوّابة من `PageHeader` الخاصّ بها. */}
      <div className={styles.actions} ref={ctx?.setSlot} />

      {/*
        * فاصلٌ بين إجراءات **الصفحة** وأدوات **اللوحة** — ترتيبُ سُلطةٍ لا زينة.
        *
        * كانا صفًّا واحدًا متلاصقًا، فيبدو «حذف» و«تسجيل الخروج» من طبقةٍ
        * واحدة. والخطّ يُخفى حين لا إجراء (`:empty` في ورقة الأنماط) فلا
        * يبقى فاصلٌ يفصل شيئًا عن لا شيء.
        */}
      <span className={styles.split} aria-hidden="true" />

      <div className={styles.tools}>
        {palette}
        <OpsBell enabled={canReadOps} />
        <LanguageSwitch />
        {storeUrl !== null ? (
          <a
            className={styles.tool}
            href={storeUrl}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={`${t('زيارة المتجر')} ${t('(يفتح في تبويب جديد)')}`}
          >
            <Icon name="home" size={18} />
          </a>
        ) : null}
        <UserMenu user={user} onSignOut={onSignOut} />
      </div>
    </header>
  );
}
