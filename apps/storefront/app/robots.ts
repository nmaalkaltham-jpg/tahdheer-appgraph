import type { MetadataRoute } from 'next';
import { apiGet } from '../lib/api';
import { siteOrigin } from '../lib/seo';

/** GET /api/v1/robots — derived from the shared IndexabilityPolicy. */
interface RobotsPolicyDto {
  userAgent: string;
  disallow: string[];
  sitemap: string | null;
  host: string | null;
}

/**
 * FAIL-CLOSED floor. The API is the authority (MIP §7 «one owner per
 * capability»), but robots.txt is generated at build time: if the API is
 * unreachable then, publishing a permissive robots.txt would expose cart,
 * checkout, account and download URLs to crawlers. So an outage degrades to
 * MORE restrictive, never less. These are the same private surfaces
 * IndexabilityPolicy excludes.
 */
const FAIL_CLOSED_DISALLOW = [
  '/cart',
  '/checkout',
  '/account',
  '/orders',
  '/search',
  '/pay',
  '/wishlist',
  '/compare',
  '/login',
  '/admin',
  '/api',
  '/downloads',
  '/files',
];

/**
 * robots.txt sourced from the real indexability policy.
 *
 * The sitemap line appears only when a real origin is configured — a sitemap
 * URL on a guessed host is worse than no line at all.
 */
export default async function robots(): Promise<MetadataRoute.Robots> {
  const { data } = await apiGet<RobotsPolicyDto>('/api/v1/robots');

  const disallow = data?.disallow?.length ? data.disallow : FAIL_CLOSED_DISALLOW;
  const origin = siteOrigin();

  /*
   * الأصل الخاصّ يتقدّم على ما يقوله الـAPI عن المضيف.
   *
   * هذا الملفّ يُقدَّم من tahdheer.com — فهو المضيف، ولا يأخذ اسمه من طرف
   * آخر. حين كان الـAPI يُقدَّم، أعلن robots.txt الحيّ:
   *   Host: https://api.tahdheer.com
   *   Sitemap: https://api.tahdheer.com/sitemap.xml   ← 404
   * أي أخبر الزواحف أن المضيف المعياري مكانٌ آخر، وأشار إلى خريطة غير موجودة.
   *
   * سياسة المنع تبقى من الـAPI (هي قرار سياسة)، أمّا الهوية فمن الصفحة نفسها.
   */
  /*
   * وسطر الخريطة يتبع `siteOrigin()` وحده — لا `data?.sitemap` احتياطيًّا.
   * `sitemap.ts` يُعيد قائمةً فارغة حين يغيب الأصل (أوّل سطرٍ فيه:
   * `if (!siteOrigin()) return []`)، فكان الاحتياطيّ يوجّه الزواحف إلى خريطةٍ
   * حيّة بصفر رابط — وهي إشارةٌ أسوأ من غياب السطر. البوّابة واحدة للملفّين.
   */
  const sitemap = origin ? `${origin}/sitemap.xml` : null;
  const host = origin ?? data?.host ?? null;

  return {
    rules: [{ userAgent: '*', disallow }],
    ...(sitemap ? { sitemap } : {}),
    ...(host ? { host } : {}),
  };
}
