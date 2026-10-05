import { getCheckoutMethods } from './SiteFooter';
import { MobileBottomBarClient } from './MobileBottomBarClient';

/**
 * الشريط السفليّ اللاصق للجوّال — Storefront Home Mobile.dc.html.
 *
 * غلافٌ خادميّ يقرأ GET /api/v1/checkout/methods مرّةً ويمرّر `applePay`
 * وحدها: بلاطة Apple Pay لا تُرسم إلّا حين يؤكّدها الخادم. الباقي (السلة،
 * المسار، اللسان النشط) حالةُ متصفّحٍ في المكوّن العميل.
 */
export async function MobileBottomBar() {
  const { applePay } = await getCheckoutMethods();
  return <MobileBottomBarClient applePay={applePay} />;
}
