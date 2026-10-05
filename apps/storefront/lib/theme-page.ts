/**
 * مفتاح الصفحة لنطاق تجاوزات الثيم — `data-theme-page`.
 *
 * ─── لماذا وُجد ───
 *
 * `ThemeCss::overrides` يبثّ تجاوزات مرتبطةً بالقسم أو بالمكوّن. والقسم لا
 * وجود له خارج الرئيسية، فكان كلّ ما عداها بلا نطاق: لا سبيل إلى «اجعل
 * طباعة صفحة الدفع أكبر» ولا إلى «بطاقة في صفحة المنتج تخالف بطاقة الأرشيف».
 * هذا المفتاح يعطي كلّ صفحةٍ نطاقًا كما للقسم نطاقه.
 *
 * ─── لماذا يُشتقّ من المسار لا يُكتب في كلّ صفحة ───
 *
 * الكتابة في كلّ صفحة تعني أربعًا وعشرين موضعًا تتقادم واحدًا واحدًا: صفحةٌ
 * جديدة تُنسى فتفقد نطاقها بصمت. والاشتقاق هنا موضعٌ واحد، والصفحة التي لا
 * تطابق قاعدةً **لا تحمل السمة أصلًا** — غيابٌ صادق بدل مفتاحٍ خاطئ يجعل
 * تجاوزًا يسري حيث لا يُقصد.
 *
 * ─── المفاتيح مفصولة عن معرّفات الأقسام ───
 *
 * لا مفتاح صفحةٍ هنا يطابق معرّف قسمٍ في `HOMEPAGE_SECTION_IDS` (hero, trust,
 * stages, featured, …). والفصل مقصود: محدّد الموضع يُبثّ للاثنين، فتطابقٌ
 * بالاسم كان يجعل تجاوز قسمٍ يسري على صفحةٍ كاملة.
 */

/** السمة التي يقرؤها `ThemeCss` في محدّداته. */
export const THEME_PAGE_ATTR = 'data-theme-page';

/**
 * المفاتيح المتاحة — قائمة مغلقة تُعرض في اللوحة كما هي.
 *
 * `catalog` يجمع أرشيفات التصنيف الخمسة (المراحل والصفوف والمواد والفصول
 * وأنواع الملفّات): هي قالبٌ واحد بخمسة مداخل، وتفريقها يعطي خمسة نطاقات
 * لتصميمٍ واحد.
 */
export const THEME_PAGES = [
  'home',
  'shop',
  'product',
  'catalog',
  'search',
  'cart',
  'checkout',
  'order',
  'account',
  'blog',
  'policy',
  'contact',
] as const;

export type ThemePageKey = (typeof THEME_PAGES)[number];

/**
 * المسار ⇐ مفتاحه، أو `null` لما لا قاعدة له.
 *
 * الترتيب يهمّ: `/products/…` قبل أيّ قاعدةٍ أعمّ، والجذر يُطابَق تطابقًا
 * تامًّا لا ببادئة وإلّا ابتلع كلّ شيء.
 */
export function themePageKey(pathname: string): ThemePageKey | null {
  const path = pathname.split('?')[0]?.replace(/\/+$/, '') ?? '';

  if (path === '' || path === '/') return 'home';

  const starts = (prefix: string): boolean => path === prefix || path.startsWith(`${prefix}/`);

  if (starts('/products')) return 'product';
  if (starts('/shop') || starts('/best-sellers') || starts('/new') || starts('/offers')) return 'shop';
  if (
    starts('/stages') ||
    starts('/grades') ||
    starts('/subjects') ||
    starts('/semesters') ||
    starts('/kinds') ||
    starts('/curriculum')
  ) {
    return 'catalog';
  }
  if (starts('/search')) return 'search';
  if (starts('/cart')) return 'cart';
  if (starts('/checkout')) return 'checkout';
  if (starts('/orders')) return 'order';
  if (starts('/account') || starts('/login')) return 'account';
  if (starts('/blog')) return 'blog';
  if (starts('/policies')) return 'policy';
  if (starts('/contact') || starts('/about') || starts('/help') || starts('/how-it-works')) {
    return 'contact';
  }

  return null;
}
