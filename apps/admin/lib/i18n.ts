// UNIT: LIB-I18N
// FILE: i18n.ts
// STYLE: none
// PARENT: LAYOUT-HOME, LOCALE
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * لغة اللوحة — عربية وإنجليزية.
 *
 * ─── لماذا المفتاح هو النصّ العربي نفسه؟ ───
 *
 * في اللوحة **٤٩٤٧ سلسلة عربية موزّعة على ١٤٩ ملفًّا** (مقيسة، لا مقدَّرة).
 * ونظام مفاتيح مجرّدة (`nav.orders`) يوجب لمس الملفّات كلّها قبل أن تعمل كلمة
 * واحدة، ويترك المستودع في حالة لا هي عربية ولا مترجمة حتى يكتمل. أمّا
 * المفتاح النصّي فيجعل التغطية **تراكمية**: كل سلسلة تُترجَم متى لُفّت، وما
 * لم يُلفّ بعد يبقى عاملًا بالعربية.
 *
 * والسقوط صريح: مفتاح بلا ترجمة يُعيد **النصّ العربي كما هو** — لا مفتاحًا
 * عاريًا ولا فراغًا. فالمستعمل الإنجليزي يرى إنجليزيةً حيث تُرجم وعربيةً حيث
 * لم يُترجَم، ويبقى كل زرّ مفهومًا. وهذا أصدق من واجهة تبدو مترجمة وتُخفي
 * نصفها خلف مفاتيح مكسورة.
 *
 * ─── ما ليس هنا عمدًا ───
 *
 * لا ترجمة آلية لخمسة آلاف سلسلة: منتجٌ عقيدته الدقّة لا تُكتب واجهته بترجمة
 * غير مراجَعة. التغطية تُقاس (`coverage()`) وتُعرض للمشغّل، ولا يُدّعى اكتمال.
 */

export type Locale = 'ar' | 'en';

export const LOCALES: readonly { id: Locale; label: string; native: string; dir: 'rtl' | 'ltr' }[] = [
  { id: 'ar', label: 'العربية', native: 'العربية', dir: 'rtl' },
  { id: 'en', label: 'الإنجليزية', native: 'English', dir: 'ltr' },
];

export const DEFAULT_LOCALE: Locale = 'ar';

/**
 * كوكي لا localStorage: التخطيط الجذري مكوّن **خادم**، ويجب أن يعرف اللغة
 * قبل أوّل رسم ليضبط `lang`/`dir`. قراءةٌ في العميل بعد التركيب تعني وميض
 * اتجاهٍ معكوس ثمّ قفزة — وهو أسوأ ما يظهر في واجهة RTL.
 */
export const LOCALE_COOKIE = 'tahdheer_admin_locale';

export function isLocale(value: unknown): value is Locale {
  return value === 'ar' || value === 'en';
}

export function dirOf(locale: Locale): 'rtl' | 'ltr' {
  return locale === 'ar' ? 'rtl' : 'ltr';
}

/**
 * القاموس: النصّ العربي ← الإنجليزي.
 *
 * الترتيب بحسب السطح لا أبجديًّا — من يترجم شريحةً جديدة يضيف كتلتها كاملة
 * ويرى ما نقص منها بالعين.
 */
const EN: Record<string, string> = {
  /* ── القشرة ── */
  'لوحة التشغيل': 'Console',
  'التحاضير الحديثة': 'Tahdheer',
  'زيارة المتجر': 'Visit store',
  '(يفتح في تبويب جديد)': '(opens in a new tab)',
  'مساحات العمل': 'Workspaces',
  'تسجيل الخروج': 'Sign out',
  'مركز الاختصارات': 'Command palette',
  'لم تُبْنَ بعد': 'not built yet',
  'لا صلاحية': 'no permission',
  'أقسام': 'Sections of',

  /* ── المساحات الستّ ── */
  'المبيعات والعملاء': 'Sales & customers',
  'الظهور والبحث': 'Visibility & search',
  'التحليلات والنمو': 'Analytics & growth',
  'استوديو المتجر': 'Store studio',
  'الوسائط': 'Media',
  'النظام': 'System',

  /* ── تبويبات المساحات ── */
  'المنتجات': 'Products',
  'الطلبات': 'Orders',
  'العملاء': 'Customers',
  'المراجعات': 'Reviews',
  'العروض والقسائم': 'Offers & coupons',
  'الطلب والفرص': 'Demand & opportunities',
  'نظرة عامة': 'Overview',
  'البحث الداخلي': 'Site search',
  'الزحف والروابط': 'Crawl & links',
  'SEO الدولي': 'International SEO',
  'المؤشرات': 'Metrics',
  'نظام تشغيل النمو': 'Growth OS',
  'الأتمتة والحوكمة': 'Automation & governance',
  'الصفحات': 'Pages',
  'الرئيسية': 'Homepage',
  'نظام التصميم': 'Design system',
  'المكوّنات': 'Components',
  'القوائم': 'Navigation',
  'المكتبة': 'Library',
  'التحسين': 'Optimization',
  'الإعدادات': 'Settings',
  'صحة الخدمات': 'Service health',
};

const DICTS: Record<Locale, Record<string, string>> = { ar: {}, en: EN };

/** النصّ باللغة المطلوبة، أو الأصل العربي إن لم يُترجَم بعد. */
export function translate(locale: Locale, source: string): string {
  if (locale === 'ar') return source;
  return DICTS[locale][source] ?? source;
}

/** عدد المدخلات المترجَمة — يُعرض للمشغّل بدل ادّعاء اكتمال. */
export function coverage(locale: Locale): number {
  return Object.keys(DICTS[locale]).length;
}
