/**
 * منطق الشريط السفليّ للجوّال — دوالّ نقيّة بلا React ولا CSS كي تُختبر
 * بـ`node --test` (lib/__tests__/mobile-bottom-bar.test.mjs).
 */
import { accountRoutes } from '../app/account/nav.ts';
import { routes } from '../lib/routes.ts';

export type BottomTabId = 'home' | 'browse' | 'search' | 'wishlist' | 'account';

export interface BottomTab {
  id: BottomTabId;
  label: string;
  href: string;
}

/** الخمسة كما يرسمها Storefront Home Mobile.dc.html — وبالترتيب نفسه. */
export const BOTTOM_TABS: readonly BottomTab[] = [
  { id: 'home', label: 'الرئيسية', href: routes.home },
  { id: 'browse', label: 'التصفّح', href: routes.stages },
  { id: 'search', label: 'البحث', href: routes.search },
  { id: 'wishlist', label: 'المفضلة', href: routes.wishlist },
  { id: 'account', label: 'حسابي', href: accountRoutes.dashboard },
];

/**
 * صفحة المنتج تحمل شريط شرائها اللاصق، فلا يُصيَّر هذا الشريط فوقه —
 * شريطان لاصقان على حافّةٍ واحدة يحجب أحدهما الآخر.
 */
export function hidesBottomBar(pathname: string): boolean {
  return pathname === '/products' || pathname.startsWith('/products/');
}

/**
 * اللسان النشط يتبع المسار الحقيقيّ. الرئيسة بالتطابق التامّ وحده؛ وسائر
 * الأقسام بالبادئة. «التصفّح» يضمّ أبعاد الكتالوج كلّها لا /stages وحدها،
 * و«حسابي» يضمّ الدخول. ولا لسان نشطًا في صفحةٍ لا تنتمي لأحدها — لا اختلاق.
 */
export function activeBottomTab(pathname: string): BottomTabId | null {
  const under = (base: string) => pathname === base || pathname.startsWith(`${base}/`);
  if (pathname === routes.home) return 'home';
  if (under(routes.search)) return 'search';
  if (under(routes.wishlist)) return 'wishlist';
  if (under(accountRoutes.dashboard) || under(routes.login)) return 'account';
  const browse = [routes.stages, routes.grades, routes.subjects, routes.semesters, routes.kinds, routes.shop, routes.bundles];
  if (browse.some(under)) return 'browse';
  return null;
}
