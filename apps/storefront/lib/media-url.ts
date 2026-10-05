/**
 * تحويل روابط الوسائط المطلقة القادمة من الـAPI إلى مسارٍ على أصل المتجر.
 *
 * الـAPI يُصدر رابطًا مطلقًا للوسائط (`image.url` في بطاقة المنتج مثلًا):
 * `http://127.0.0.1:58080/api/v1/media/products/27-….png`. والمتجر يعمل على
 * `127.0.0.1:3000`، فالصورة تُطلب عبر الأصول. واستجابة `‎/api/v1/media/*‎`
 * تحمل `Cross-Origin-Resource-Policy: same-site`، والمتصفّح يعدّ المضيف
 * الرقميّ (IP) غير مطابقٍ لأيّ موقع — فيحجب الصورة بـ
 * `net::ERR_BLOCKED_BY_RESPONSE.NotSameSite` وتبقى `naturalWidth === 0`.
 * لا CORS يُنقذ هنا: CORP سابقٌ على CORS ولا تُبطله `Access-Control-*`.
 *
 * ولهذا لا تسقط صور `‎/storage/*‎` في الصفحة نفسها: لا CORP عليها أصلًا.
 * فالعطل في هذا المسار وحده، والعلاج مقصورٌ عليه.
 *
 * الحلّ: يُجرَّد الأصل ويبقى المسار، فيمرّ الطلب عبر
 * `app/api/v1/media/[...path]/route.ts` من أصل المتجر نفسه — وطلبٌ من الأصل
 * نفسه يعبر CORP دائمًا مهما كانت قيمته.
 *
 * ومكسبٌ ثانٍ غير مقصود أوّلًا: `apiBase()` يُفضّل `INTERNAL_API_URL` على
 * الخادم، فأيّ رابطٍ مطلق يُخبز في HTML المُصيَّر قد يحمل مضيفًا داخليًّا
 * (`api-web:8080`) لا يعرفه المتصفّح. المسار النسبيّ لا يحمل مضيفًا فلا يقع
 * هذا الالتباس.
 */

/** بادئة وسائط الـAPI — الوحيدة التي يخدمها وسيطُ المتجر. */
const MEDIA_PATH_PREFIX = '/api/v1/media/';

/**
 * يُعيد رابطًا يصلح لـ`src` على أصل المتجر.
 *
 * ما لا نملك له وسيطًا يُترك كما ورد: تحويلُ رابطٍ لا مسار له عندنا يستبدل
 * صورةً تعمل بـ404. القاعدة: لا نغيّر إلّا ما نضمن تقديمه.
 */
export function mediaSrc(url: string): string {
  // نسبيٌّ أصلًا ⇒ هو على أصلنا بالفعل.
  if (url.startsWith('/')) return url;

  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    // ليس رابطًا صالحًا — يُعاد كما ورد ولا يُخترع بديل.
    return url;
  }

  if (!parsed.pathname.startsWith(MEDIA_PATH_PREFIX)) return url;
  return `${parsed.pathname}${parsed.search}`;
}

/** شكل الوسيط في عقود البطاقات: `{ url, alt, w, h }` وما يشبهه. */
interface WithUrl {
  url: string;
}

/**
 * النسخة الكائنيّة: تُبقي كلّ الحقول وتُصحّح `url` وحده.
 *
 * تُمرَّر `undefined` كما هي لأنّ «لا صورة» حالةٌ صريحة في `ProductCard`
 * (صندوقٌ مخطّط، لا صورة نائبة) — ولا يجوز أن يخلقها هذا التحويل ولا أن
 * يمحوها.
 */
export function mediaImage<T extends WithUrl>(image: T | undefined): T | undefined {
  if (!image) return undefined;
  const url = mediaSrc(image.url);
  return url === image.url ? image : { ...image, url };
}
