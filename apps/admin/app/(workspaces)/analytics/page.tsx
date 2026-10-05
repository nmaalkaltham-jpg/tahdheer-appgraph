'use client';
// UNIT: PAGE-ANALYTICS
// FILE: page.tsx
// ROUTE: /analytics
// STYLE: analytics.module.css, shared.module.css
// PARENT: none
// USES: ABANDONED, ADMIN-SESSION, ATTENTION-MAP, COHORTS, CONTRACT, DEMAND-PANEL +9
// API: none
// API-VIA: ADMIN-SESSION, DEMAND-PANEL, OVERVIEW, SEARCH-FUNNEL, SOURCES
// MAP: docs/PROJECT_MAP.md

/**
 * مركز التحليلات — الكروم وستّة تبويبات.
 *
 * الكروم يملك السمة وشريط التبويبات معًا؛ التبويب غير النشط لا DOM له ولا
 * طلب شبكة (تركيب كسول)، فلا يُحمَّل ما لا يُنظَر إليه.
 *
 * عقد الصدق (MIP §1.3 و§9.1): «الرئيسية» و«قِمَع البحث» يقرآن أرقامًا حقيقية
 * من /admin/dashboard و/admin/search/queries. وكل ما عداهما لا مصدر له اليوم،
 * فيُعرَض بحالة «غير مُقاس» تسمّي خطّ الأنابيب الغائب — لا بصفر ولا بمحاكاة.
 */

import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useRef, type CSSProperties, type KeyboardEvent } from 'react';
import { useStoredItem } from '../../../lib/browser-store';
import { EASINGS, MOTION_SCALE } from '@tahdheer/design-system/motion';
import { useSession } from '../../../components/admin-session';
import { PageHeader } from '../../../components/resource';
import { Icon, type IconName } from './_shared/icons';
import shared from './_shared/shared.module.css';
import s from './analytics.module.css';
import AttentionMapTab from './_tabs/attention-map';
import CohortsTab from './_tabs/cohorts';
import ContractTab from './_tabs/contract';
import OverviewTab from './_tabs/overview';
import PageRevenueTab from './_tabs/page-revenue';
import AbandonedTab from './_tabs/abandoned';
import { DemandPanel } from '../demand/demand-panel';
import PurchaseFunnelTab from './_tabs/purchase-funnel';
import SearchFunnelTab from './_tabs/search-funnel';
import SourcesTab from './_tabs/sources';

type TabId = 'overview' | 'purchase' | 'pages' | 'abandoned' | 'funnel' | 'attention' | 'cohorts' | 'sources' | 'demand' | 'contract';

interface TabDef {
  id: TabId;
  label: string;
  icon: IconName;
  sub: string;
  /** وسم حالة القياس — لا عدّاد مُختلَق. */
  chip: string | null;
}

const TABS: TabDef[] = [
  {
    id: 'overview',
    label: 'الرئيسية',
    icon: 'gauge',
    sub: 'كل رقم من جداول متجرك — وما لا يُقاس يقول ذلك صراحةً',
    chip: null,
  },
  {
    id: 'purchase',
    label: 'قِمَع الشراء',
    icon: 'funnel',
    sub: 'مشاهدة ← سلّة ← دفع ← شراء، بزوّار مميّزين من جدول الأحداث',
    chip: null,
  },
  {
    /*
     * الإسناد بعد القمع مباشرةً: القمع يقول «كم سقط»، وهذا يقول «من أين جاء
     * من لم يسقط» — والسؤالان يُقرآن معًا أو لا يفيد أحدهما.
     */
    id: 'pages',
    label: 'إيراد الصفحات',
    icon: 'chart',
    sub: 'أيّ صفحةٍ جلبت الشراء — لمسةٌ أولى، وتغطيةٌ معلَنة',
    chip: null,
  },
  {
    id: 'abandoned',
    label: 'السلال المتروكة',
    icon: 'cart',
    sub: 'ما اختير ولم يُشترَ — والقابل للوصول مفصولٌ عمّا لا عنوان له',
    chip: null,
  },
  {
    id: 'funnel',
    label: 'قِمَع البحث',
    icon: 'funnel',
    sub: 'من الاستعلام إلى الطلب — والمراحل غير المرصودة معلَنة لا مُقدَّرة',
    chip: 'جزئي',
  },
  {
    id: 'attention',
    label: 'خريطة الانتباه',
    icon: 'eye',
    sub: 'تفاعل حقيقي من طرف أول أو لا شيء — لا محاكاة ولا تقدير',
    chip: 'غير مُقاس',
  },
  {
    id: 'cohorts',
    label: 'الاحتفاظ والأفواج',
    icon: 'chart',
    sub: 'هل يعود المعلّم بعد أول ملف؟ — الشبكة جاهزة وبياناتها غائبة',
    chip: 'غير مُقاس',
  },
  {
    id: 'sources',
    label: 'المصادر',
    icon: 'plug',
    sub: 'ما يغذّي كل رقم، وما لا مصدر له بعد',
    chip: null,
  },
  {
    /*
     * «الطلب الفائت» تقريرٌ لا مساحة عمل: ما طُلب ولم يوجد، مرتَّبًا بعدد
     * من طلبه. وكان وجهةً مستقلّة في الشريط لصفحةٍ تُقرأ مرّةً في الأسبوع.
     */
    id: 'demand',
    label: 'الطلب الفائت',
    icon: 'gauge',
    sub: 'ما بحث عنه الزوّار ولم يجدوه',
    chip: null,
  },
  {
    id: 'contract',
    label: 'عقد القياس',
    icon: 'contract',
    sub: 'القواعد التي يلتزم بها كل رقم في هذا المركز',
    chip: null,
  },
];

/**
 * لوحة Analytics Pro تنشر خمسة مداخل في شريط واحد؛ ثلاثة منها مساحات مستقلة
 * في هذه اللوحة، فتُعرَض روابط حقيقية إليها بأسمائها في اللوحة — لا تبويبات
 * مكرَّرة ولا أزرار فارغة.
 */
const CROSS_LINKS: { href: string; label: string; icon: IconName }[] = [
  { href: '/seo', label: 'SEO والتحليلات', icon: 'search' },
  { href: '/media', label: 'مكتبة الوسائط', icon: 'files' },
  { href: '/media/optimize', label: 'تكبير صورة', icon: 'sparkle' },
];

const TAB_KEY = 'tahdheer.admin.analytics.tab';

/** الحركة من نظام التصميم — المستوى ٣ (مرساة موثّقة في motion.ts). */
const MOTION = MOTION_SCALE[3];
const MOTION_VARS = {
  '--an-motion-duration': `${MOTION.duration}ms`,
  '--an-motion-distance': `${MOTION.distance}px`,
  '--an-motion-ease': EASINGS[MOTION.easing],
} as unknown as CSSProperties;

function isTabId(value: string): value is TabId {
  return TABS.some((tab) => tab.id === value);
}

export default function AnalyticsPage() {
  /* `useSearchParams` في مكوّن عميل يوجب `Suspense` وقت البناء. */
  return (
    <Suspense fallback={null}>
      <AnalyticsWorkspace />
    </Suspense>
  );
}

function AnalyticsWorkspace() {
  const { can } = useSession();
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  // التفضيلات تُقرأ بعد التركيب حتى لا يختلف الترميز الأول عن الحالة المحفوظة.
  // التخزين المحلي قد يكون محجوبًا — الافتراضات تكفي. المخزَّن هو الأصل حتى أول اختيار في الجلسة.
  const storedTab = useStoredItem(TAB_KEY);
  const tabsRef = useRef<HTMLDivElement | null>(null);

  /*
   * ─── `?t=` هو الأصل، والمخزَّن احتياطُه ───
   *
   * `PageTabs` تقول القاعدة صراحةً وتسمّي هذه الشاشة استثناءً باقيًا: الرابط
   * يُشارَك ويُحفظ في المفضّلة ويعيده زرّ الرجوع، والحالة المحلّيّة لا تفعل
   * شيئًا من ذلك. وكان الاستثناء يقطع وصلة قائمة: مسارٌ قديم يحوّل إلى
   * `/analytics?t=demand`، وهذه الصفحة لا تقرأ `t` أصلًا — فيصل المشغّل
   * إلى «نظرة عامّة» ويظنّ «الطلب الفائت» قد حُذف. الرابط كان يُكتب ولا
   * يُقرأ أحدٌ طرفه الآخر. (صفحات التحويل حُذفت بعدُ؛ وقراءة `t` تبقى لأنّ
   * الرابط المُشارَك هو السبب الأصل.)
   *
   * والمخزَّن يبقى: من يفتح `/analytics` بلا وسيط يعود إلى آخر تبويبٍ اختاره.
   */
  const requested = params.get('t');
  const tab: TabId =
    requested !== null && isTabId(requested)
      ? requested
      : storedTab !== null && isTabId(storedTab)
        ? storedTab
        : 'overview';

  const selectTab = useCallback(
    (next: TabId) => {
      const search = new URLSearchParams(params.toString());
      search.set('t', next);
      /* `replace` لا `push`: تبديل تبويبٍ ليس صفحةً جديدة في تاريخ التصفّح. */
      router.replace(`${pathname}?${search.toString()}`, { scroll: false });
      try {
        window.localStorage.setItem(TAB_KEY, next);
      } catch {
        /* لا شيء يعتمد على نجاح الحفظ. */
      }
    },
    [params, pathname, router],
  );

  /*
   * حارس الصلاحية — بعد كل الخطّافات لا قبلها، فترتيبها ثابت في كل تصيير.
   * بلا هذا الحارس يفتح صاحب الدور المحدود المركز فتنطلق طلبات التبويب وتردّ
   * ٤٠٣، فيرى خطأ خامًا بدل جملة تقول له إنّ دوره لا يملك قراءة التحليلات.
   */
  if (!can('analytics.read')) {
    return (
      <section>
        <PageHeader trail="التحليلات" title="التحليلات" />
        <div className="state-error" role="status">
          <p>دورك لا يملك صلاحية «قراءة التحليلات»، فلا تُعرض أي أرقام هنا.</p>
        </div>
      </section>
    );
  }

  const active = TABS.find((item) => item.id === tab) ?? TABS[0];
  if (active === undefined) throw new Error('قائمة التبويبات فارغة.');

  /** تنقّل لوحة المفاتيح داخل شريط التبويبات (نمط ARIA للتبويبات، باتجاه RTL). */
  const onTabKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const index = TABS.findIndex((item) => item.id === tab);
    let next = -1;
    if (event.key === 'ArrowLeft') next = (index + 1) % TABS.length;
    else if (event.key === 'ArrowRight') next = (index - 1 + TABS.length) % TABS.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = TABS.length - 1;
    if (next === -1) return;
    event.preventDefault();
    const target = TABS[next];
    if (target === undefined) return;
    selectTab(target.id);
    tabsRef.current?.querySelector<HTMLButtonElement>(`#analytics-tab-${target.id}`)?.focus();
  };

  return (
    <section>
      <PageHeader
        trail="التحليلات"
        title="التحليلات"
        actions={
          <Link className="btn btn-ghost" href="/diagnostics">
            الخرائط التشخيصية
          </Link>
        }
      />

      <div
        className={[s.root, shared.theme].filter(Boolean).join(' ')}
        style={MOTION_VARS}
      >
        <div className={s.frame}>
          <div className={s.chrome}>
            <span aria-hidden="true" className={s.chromeMark}>
              <Icon name="gauge" size={16} />
            </span>
            <b className={s.chromeTitle}>مركز التحليلات</b>
            <span className={s.chromeSub}>التحاضير الحديثة — لوحة التشغيل</span>
            <span className={s.spacer} />


            <button
              type="button"
              className={s.badge}
              onClick={() => selectTab('sources')}
              title="افتح تبويب المصادر"
            >
              <Icon name="info" size={13} strokeWidth={2.2} />
              أرقام مقيسة من جداولك · الزيارات والقمع من جدول الأحداث
            </button>
          </div>

          <div className={s.head}>
            <h2 className={s.headTitle}>{active.label}</h2>
            <span className={s.headSub}>{active.sub}</span>
          </div>

          <div className={s.tabs}>
            <div
              className={s.tabsGroup}
              role="tablist"
              aria-label="أقسام مركز التحليلات"
              ref={tabsRef}
              onKeyDown={onTabKeyDown}
            >
              {TABS.map((item) => {
                const on = item.id === tab;
                return (
                  <button
                    key={item.id}
                    type="button"
                    role="tab"
                    id={`analytics-tab-${item.id}`}
                    aria-selected={on}
                    aria-controls={`analytics-panel-${item.id}`}
                    tabIndex={on ? 0 : -1}
                    className={[s.tab, on ? s.tabOn : ''].filter(Boolean).join(' ')}
                    onClick={() => selectTab(item.id)}
                  >
                    <Icon name={item.icon} size={15} strokeWidth={1.9} />
                    {item.label}
                    {item.chip ? <span className={s.tabChip}>{item.chip}</span> : null}
                  </button>
                );
              })}
            </div>

            <nav className={s.crossNav} aria-label="مداخل التصميم في مساحات أخرى من اللوحة">
              {CROSS_LINKS.map((link) => (
                <Link
                  key={link.href}
                  href={link.href}
                  className={s.crossLink}
                  title={`ينتقل إلى ${link.label} — مساحة مستقلة في اللوحة`}
                >
                  <Icon name={link.icon} size={15} strokeWidth={1.9} />
                  {link.label}
                  <Icon name="external" size={11} strokeWidth={2.2} />
                </Link>
              ))}
            </nav>
          </div>

          <div
            className={[s.body, shared.enter].filter(Boolean).join(' ')}
            role="tabpanel"
            id={`analytics-panel-${active.id}`}
            aria-labelledby={`analytics-tab-${active.id}`}
            tabIndex={0}
            key={active.id}
          >
            {tab === 'overview' ? <OverviewTab /> : null}
            {tab === 'purchase' ? <PurchaseFunnelTab /> : null}
            {tab === 'pages' ? <PageRevenueTab /> : null}
            {tab === 'abandoned' ? <AbandonedTab /> : null}
            {tab === 'funnel' ? <SearchFunnelTab /> : null}
            {tab === 'attention' ? <AttentionMapTab /> : null}
            {tab === 'cohorts' ? <CohortsTab /> : null}
            {tab === 'sources' ? <SourcesTab /> : null}
            {tab === 'demand' ? <DemandPanel /> : null}
            {tab === 'contract' ? <ContractTab /> : null}
          </div>
        </div>

        <p className={s.footNote}>
          التبويب غير النشط لا يُركَّب ولا يُطلق طلبًا — يُحمَّل عند اختياره وحده. وترتيب البطاقات
          وإخفاؤها يُحفظان في هذا المتصفّح فقط؛ لا يوجد مسار لحفظ التخطيط في حسابك، فلا يتغيّر ما
          يراه غيرك.
        </p>
      </div>
    </section>
  );
}
