'use client';
// UNIT: ADMIN-SHELL
// FILE: admin-shell.tsx
// STYLE: none
// PARENT: LAYOUT-HOME
// USES: ADMIN-SESSION, COMMAND-PALETTE, LIB-LAYOUT-PREFS, LIB-NAV, LOCALE, PAGE-HEAD +3
// API: none
// API-VIA: ADMIN-SESSION, COMMAND-PALETTE, LIB-NAV, PAGE-HEAD, USE-NAV-COUNTS
// MAP: docs/PROJECT_MAP.md

/**
 * كروم اللوحة الداكن + مجموعات العمل الخمس (MIP Part 8).
 *
 * صفحة الدخول تُعرض بلا كروم. أي مسار آخر لا يُعرض إلا بعد تأكيد الجلسة من
 * الخادم: قبل ذلك حالة تحميل صريحة، وعند تعذّر الاتصال رسالة خطأ صريحة —
 * لا واجهة فارغة تُوهم بأن لا بيانات.
 */

import { usePathname } from 'next/navigation';
import {
  useCallback,
  useEffect,
  useRef,
  useState,
  useSyncExternalStore,
  type ReactNode,
} from 'react';
import { useT } from './locale';
import { AdminTopbar } from './page-head';
import { allDestinations, WORKSPACES } from '../lib/nav';
import { WorkspaceTabs } from './workspace-tabs';
import { useSession } from './admin-session';
import CommandPalette from './command-palette';
import { Sidebar } from './sidebar';
import { useNavCounts } from './use-nav-counts';
import {
  APPEARANCE_ATTRIBUTE_KEYS,
  useAdminAppearance,
  useAdminDensity,
  useAdminLayout,
  useAdminTheme,
} from '../lib/layout-prefs';
import { useAppearanceSync } from './settings/appearance-sync';

/** نفس حدّ `globals.css:66` — الشريط يصير درجًا تحته. */
const DRAWER_MQ = '(max-width: 900px)';

/**
 * هل الشريط درجٌ الآن؟ — سؤالٌ عن المقاس لا عن الحالة.
 *
 * لازمٌ لأنّ الدرج المغلق يُخفى بـ`transform: translateX(100%)`
 * (`globals.css:81`)، و**ما خرج بالتحويل يبقى في ترتيب التبويب**: عشرة عناصر
 * قابلة للتركيز (الشعار · رابط المتجر · مبدّل اللغة · زرّ الطيّ · ستّ مساحات ·
 * تسجيل الخروج) في درجٍ لا يراه أحد. ولا حيلة في CSS لإخراجها من الترتيب من
 * هنا: القاعدة في `app/globals.css` خارج هذا النطاق، و`inert` هو الأداة التي
 * تُخرج الشجرة من التبويب ومن شجرة الوصول معًا.
 *
 * ولا يُطبَّق فوق 900px: هناك الشريط ملاحةٌ ظاهرة دائمًا، و`inert` عليها يشلّ
 * اللوحة كلّها.
 */
function useDrawerViewport(): boolean {
  return useSyncExternalStore(
    (onChange) => {
      const mq = window.matchMedia(DRAWER_MQ);
      mq.addEventListener('change', onChange);
      return () => mq.removeEventListener('change', onChange);
    },
    () => window.matchMedia(DRAWER_MQ).matches,
    /* الخادم لا يعرف عرض النافذة. «ليس درجًا» هو الفشل الآمن: شريطٌ ظاهر لا
       يُشلّ أبدًا، والشلّ يبدأ بعد الترطيب حين يُعرف المقاس. */
    () => false,
  );
}

export default function AdminShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const t = useT();
  const { state, can, signOut } = useSession();
  /*
   * العدّادات تُجلب مرّة للقشرة كلّها لا لكلّ وجهة: طلبٌ واحد يخدم الشريط
   * ولوحة «ما يحتاج انتباهي» معًا. ولا تُعرض إلّا حين تُقاس — راجع
   * `use-nav-counts`.
   */
  const navCounts = useNavCounts(can('orders.read'));

  /*
   * التخطيط المختار — قيمةٌ واحدة تُبدّل شكل اللوحة كلّها.
   *
   * تُعلَن على `<body>` لا تُمرَّر خاصّيةً: الشبكة في `globals.css` تملك
   * العمودين، والدرج يُعلن حالته بالطريقة نفسها. وتمريرُها خاصّيةً كان
   * سيوجب على كلّ مكوّنٍ في الطريق أن يعرفها.
   */
  const { layout } = useAdminLayout();
  const { resolved: theme } = useAdminTheme();
  /*
   * الكثافة تُعلَن على الجسم كالسمة والتخطيط تمامًا: طبقة `--d-*` في
   * `globals.css` تملك الارتفاعات والحشوات ودرجات الخطّ، فلا شجرةَ ترميزٍ
   * ثانية «مضغوطة» تتباعد عن أختها بعد أوّل تعديل.
   */
  const { density } = useAdminDensity();
  /*
   * المظهر والمؤثّرات — سماتٌ على الجسم كالسابقات: `globals.css` يقرأ
   * `data-accent` و`data-radius` و`data-fx-*` فيُطفئ كلّ مؤثّرٍ من جذره.
   * والوثيقة تُزامَن مع الحساب بعد تأكيد الجلسة وحده.
   */
  const { attributes } = useAdminAppearance();
  useAppearanceSync(state.status === 'authenticated');

  /*
   * الدرج تحت 900px — لا شريطٌ في التدفّق.
   *
   * ثلاثة سلوكيات ليست تحسينات بل شروط استعمال: Escape يغلق (المفتاح الذي
   * يجرّبه كل مستعمل أوّلًا)، والنقر خارجه يغلق، و**التركيز يعود إلى زرّ
   * الفتح** — بدونها يقفز التركيز إلى أوّل الصفحة فيضيع مستعمل لوحة المفاتيح.
   * وقفل تمرير الجسم في CSS لا هنا: تغييرُ `overflow` من JS يتنازع مع أي
   * منسّق آخر يلمسه.
   */
  const [drawer, setDrawer] = useState(false);
  const menuRef = useRef<HTMLElement | null>(null);
  const asideRef = useRef<HTMLElement>(null);
  const isDrawer = useDrawerViewport();

  const openDrawer = useCallback(() => {
    // زرّ الفتح هو العنصر النشط لحظة النقر — يُحفظ لإعادة التركيز إليه.
    menuRef.current = document.activeElement as HTMLElement | null;
    setDrawer(true);
  }, []);

  /*
   * الإغلاق يضبط الحالة وحدها، وإعادة التركيز في تأثيرٍ بعده — لا داخل
   * مُحدِّث الحالة.
   *
   * كانت `focus()` تُنادى داخل المُحدِّث، أي **قبل** أن يلتزم React بالشجرة
   * الجديدة. وبعد أن صار اللوح الرئيسيّ `inert` ما دام الدرج مفتوحًا، زرُّ
   * القائمة الواقع فيه لا يقبل التركيز إلّا بعد رفع `inert` — فكان النداء
   * المبكّر يسقط صامتًا ويضيع مستعمل لوحة المفاتيح في أوّل الصفحة.
   */
  const closeDrawer = useCallback(() => setDrawer(false), []);

  useEffect(() => {
    document.body.dataset.adminLayout = layout;
    document.body.dataset.theme = theme;
    document.body.dataset.density = density;
    Object.assign(document.body.dataset, attributes);

    return () => {
      delete document.body.dataset.adminLayout;
      delete document.body.dataset.theme;
      delete document.body.dataset.density;
      for (const key of APPEARANCE_ATTRIBUTE_KEYS) delete document.body.dataset[key];
    };
  }, [layout, theme, density, attributes]);

  useEffect(() => {
    const { body } = document;
    if (drawer) body.dataset.drawer = 'open';
    else delete body.dataset.drawer;
    return () => {
      delete body.dataset.drawer;
    };
  }, [drawer]);

  /*
   * التركيز يدخل الدرج عند فتحه — النصف الغائب من الدورة.
   *
   * العودة إلى زرّ الفتح عند الإغلاق كانت مبنيّة، والدخول لا. وزرّ القائمة يقع
   * في `<main>` **بعد** `<aside>` في ترتيب المستند، فمن فتح الدرج بلوحة
   * المفاتيح كان أوّل ‎Tab‎ يقوده إلى محتوى الصفحة تحت الغشاء لا إلى الملاحة
   * التي طلبها؛ الدرج لا يُبلغ إلّا بـ‎Shift+Tab‎ رجوعًا — وهو ما لا يجرّبه أحد.
   *
   * والتركيز على الحاوية (`tabIndex={-1}`) لا على أوّل رابط: يُقرأ اسم الملاحة
   * أوّلًا فيعرف المستعمل أين وقع، ثمّ ينزل بـ‎Tab‎.
   */
  useEffect(() => {
    if (drawer) {
      asideRef.current?.focus();
      return;
    }

    /*
     * والعودة إلى زرّ الفتح تقع هنا أيضًا: هذا التأثير يلي التزام React،
     * فـ`inert` قد رُفع عن اللوح الرئيسيّ فعلًا وصار الزرّ يقبل التركيز.
     * و`menuRef` فارغٌ عند أوّل تركيب، فلا تُخطف بؤرةُ التركيز من الصفحة.
     */
    menuRef.current?.focus();
    menuRef.current = null;
  }, [drawer]);

  useEffect(() => {
    if (!drawer) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') closeDrawer();
    };
    // الغشاء عنصر زائف (::after) فلا يستقبل نقرًا — يُلتقط من الجسم، ويُستثنى
    // ما وقع داخل الشريط نفسه.
    const onDown = (e: PointerEvent) => {
      const el = e.target as HTMLElement | null;
      if (el?.closest('.sidebar') === null) closeDrawer();
    };
    window.addEventListener('keydown', onKey);
    window.addEventListener('pointerdown', onDown);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('pointerdown', onDown);
    };
  }, [drawer, closeDrawer]);

  if (pathname === '/login') return <>{children}</>;

  if (state.status === 'loading') {
    return (
      <div className="boot" role="status" aria-live="polite">
        جارٍ التحقّق من الجلسة…
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div className="boot boot-error" role="alert">
        <strong>تعذّر التحقّق من الجلسة</strong>
        <p>{state.message}</p>
      </div>
    );
  }

  if (state.status === 'anonymous') {
    return (
      <div className="boot" role="status" aria-live="polite">
        جارٍ التحويل إلى صفحة الدخول…
      </div>
    );
  }

  const { user } = state.identity;

  /*
   * فهرس «مركز الاختصارات»: سجلّ التنقّل نفسه بعد ترشيح الصلاحيات وغير
   * المبنيّ — أي وجهة تُضاف إلى `lib/nav.ts` تظهر في اللوحة تلقائيًا.
   */
  /*
   * كل وجهة، لا الجذور السبع.
   *
   * كان الفهرس يحمل سبعة مداخل بينما في اللوحة **اثنتان وعشرون وجهة**: من
   * يبحث عن «الزحف» أو «الحوكمة» أو «صحة الخدمات» لا يجدها، فيتصفّح الشريط
   * يدويًّا — ومركزُ اختصاراتٍ لا يعرف نصف الوجهات أسوأ من لا مركز، لأنّه
   * يُعلّم المستعمل ألّا يثق به.
   */
  /*
   * `allDestinations()` لا `ws.destinations`: بعد دمج استوديو التصميم صارت
   * «الصفحات» و«الرئيسية» و«المظهر» و«أطلس الصفحات» **أقسامًا** لا وجهات
   * عليا، وقراءةُ الطبقة العليا وحدها كانت ستُسقط أربعة أسطح مبنيّة من
   * الفهرس — العيب نفسه الموصوف أعلاه، عائدًا من باب الدمج.
   */
  const paletteGroups = WORKSPACES.map((ws) => ({
    label: ws.label,
    items: allDestinations().flatMap(({ ws: owner, dest }) =>
      owner.id === ws.id && (dest.permission === undefined || can(dest.permission))
        ? [{ label: dest.label, href: dest.href }]
        : [],
    ),
  })).filter((group) => group.items.length > 0);

  /*
   * المساحة النشطة تُحسب بالسجلّ لا بمطابقة `href` حرفية.
   *
   * قِسته: `/customers` و`/reviews` و`/promotions` و`/demand` و`/search` و
   * `/crawl` و`/growth` و`/navigation` و`/diagnostics` **لا يتفرّع أيٌّ منها
   * من جذر مساحته**، فكان الشريط لا يُبرز شيئًا في تسع شاشات — والمستعمل لا
   * يعرف أين هو.
   */




  /*
   * aria-current على أطول مدخل مطابق وحده: «التحليلات» و«نظام تشغيل النمو»
   * يتشاركان البادئة /analytics، ووسمُ الاثنين معًا يعلن «الصفحة الحالية»
   * مرتين — المشكلة نفسها الموثّقة أعلاه في مدخل الوسائط.
   */

  /*
   * الدرج **حوارٌ نمطيّ** لا شريطُ ملاحةٍ ظاهر — متى كان طافيًا فوق المحتوى.
   *
   * وهما حالتان: تحت 900px، وتخطيط «تركيز» على كلّ المقاسات. وفي غيرهما
   * الشريط ملاحةٌ مجاورة، وإعلانُه حوارًا هناك يسلب اللوحةَ معلمَها الملاحيّ.
   */
  const modalDrawer = drawer && (isDrawer || layout === 'focus');

  return (
    <div className="admin-shell">
      <aside
        ref={asideRef}
        className="sidebar"
        /* هدف تركيزٍ برمجيّ عند فتح الدرج، وخارج ترتيب التبويب دائمًا. */
        tabIndex={-1}
        /*
         * `dialog` + `aria-modal` وقت الطفوّ: بدونهما يُعلَن الدرج «ملاحةً»
         * مجاورة، فيظنّ قارئ الشاشة أنّ بقيّة الصفحة ما زالت متاحةً خلفه —
         * وهي ليست كذلك: الغشاء يحجبها بصريًّا و`inert` يحجبها فعلًا.
         */
        role={modalDrawer ? 'dialog' : undefined}
        aria-modal={modalDrawer ? true : undefined}
        aria-label={t('ملاحة اللوحة')}
        /* درجٌ مغلق = شجرةٌ خارج التبويب والوصول. فوق 900px لا شيء يُشلّ. */
        inert={(isDrawer || layout === 'focus') && !drawer}
      >
        <Sidebar
          can={can}
          pathname={pathname ?? ''}
          counts={navCounts}
          /* تخطيط العمودين يفتح مجموعةً واحدة: هذا هو تمايزه عن الشريط الكامل. */
          singleOpenGroup={layout === 'split'}
          onNavigate={closeDrawer}
          onCloseDrawer={closeDrawer}
          footer={null}
        />
      </aside>
      {/*
        * اللوح الرئيسيّ يُشلّ ما دام الدرج مفتوحًا — وهو نصف المصيدة الغائب.
        *
        * الغشاء يحجب النقر ولا يحجب ‎Tab‎: من فتح الدرج بلوحة المفاتيح كان
        * تبويبه يخرج من خلفه إلى روابط صفحةٍ لا يراها، فيتصفّح شاشةً مغطّاة.
        * و`inert` وحده يُخرج الشجرة من ترتيب التبويب ومن شجرة الوصول معًا،
        * فلا حاجة إلى مصيدة تركيزٍ مكتوبة باليد تتقادم مع كلّ عنصرٍ يُضاف.
        */}
      <main className="canvas" inert={modalDrawer}>
        <AdminTopbar
          onMenu={openDrawer}
          palette={<CommandPalette groups={paletteGroups} can={can} />}
          user={user}
          onSignOut={() => void signOut()}
          canReadOps={can('orders.read')}
        />
        <WorkspaceTabs />
        <div className="page-body">{children}</div>
      </main>
    </div>
  );
}
