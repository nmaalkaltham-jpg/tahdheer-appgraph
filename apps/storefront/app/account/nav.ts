/**
 * The account area's own route map and nav model.
 *
 * The «zero href="#"» law asks for one route-map module; these four paths live
 * here rather than in lib/routes.ts because that file is shared by every
 * storefront surface and this area owns its own tree. Same law, narrower scope:
 * no literal account href exists anywhere else.
 *
 * «Tahdheer - My Account» draws thirteen sidebar entries. Four of them have a
 * real destination in this codebase; the rest have no data source at all
 * (wallet, rewards, coupons, wishlist, preferences, support, account details,
 * security) and are rendered as visibly disabled entries carrying the design's
 * own «يحتاج ربطًا» tag rather than as links into a 404.
 *
 * «تسجيل الخروج» is dropped outright: with no customer session there is nothing
 * to end, and a disabled log-out would be theatre. Its absence is stated in the
 * dashboard's «ميزات غير مربوطة بعد» panel instead.
 */
import type { AccountIconName } from './icons';

export const accountRoutes = {
  dashboard: '/account',
  library: '/account/library',
  orders: '/account/orders',
  downloads: '/account/downloads',
} as const;

export interface AccountNavItem {
  label: string;
  icon: AccountIconName;
  /** Absent ⇒ the section has no backend; the entry renders disabled. */
  href?: string;
}

export const ACCOUNT_NAV: readonly AccountNavItem[] = [
  { label: 'لوحة الحساب', icon: 'grid', href: accountRoutes.dashboard },
  { label: 'مكتبتي التعليمية', icon: 'book', href: accountRoutes.library },
  { label: 'طلباتي', icon: 'cartBag', href: accountRoutes.orders },
  { label: 'التنزيلات', icon: 'download', href: accountRoutes.downloads },
  { label: 'المفضلة', icon: 'heart' },
  { label: 'المحفظة', icon: 'wallet' },
  { label: 'النقاط والمكافآت', icon: 'medal' },
  { label: 'كوبوناتي', icon: 'tag' },
  { label: 'تفضيلاتي التعليمية', icon: 'sliders' },
  { label: 'الدعم والمساعدة', icon: 'headset' },
  { label: 'تفاصيل الحساب', icon: 'userCircle' },
  { label: 'الأمان', icon: 'lock' },
];

/** The block-end bar on small screens — real destinations only. */
export const ACCOUNT_MOBILE_NAV: readonly Required<AccountNavItem>[] = [
  { label: 'الحساب', icon: 'grid', href: accountRoutes.dashboard },
  { label: 'مكتبتي', icon: 'book', href: accountRoutes.library },
  { label: 'طلباتي', icon: 'cartBag', href: accountRoutes.orders },
  { label: 'التنزيلات', icon: 'download', href: accountRoutes.downloads },
];

/** The design's own honesty panel: what the spec asks for and the API lacks. */
export const NOT_CONNECTED: readonly { label: string; icon: AccountIconName }[] = [
  { label: 'جلسة الحساب (الدخول والخروج)', icon: 'userCircle' },
  { label: 'المحفظة والرصيد', icon: 'wallet' },
  { label: 'النقاط والمكافآت', icon: 'medal' },
  { label: 'الكوبونات', icon: 'tag' },
  { label: 'الفواتير PDF', icon: 'files' },
  { label: 'مركز الإشعارات', icon: 'bell' },
  { label: 'المفضلة', icon: 'heart' },
  { label: 'تحديثات المشتريات', icon: 'refresh' },
  { label: 'المصادقة الثنائية', icon: 'lock' },
];
