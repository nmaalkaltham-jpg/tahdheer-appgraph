/**
 * وسيط الوسائط — الصورة تُقدَّم من أصل المتجر لا من أصل الـAPI.
 *
 * العطل المقيس: `‎/api/v1/media/*‎` في لارافيل يردّ
 * `Cross-Origin-Resource-Policy: same-site`، والمتصفّح يعدّ مضيفًا رقميًّا
 * (127.0.0.1) غير مطابقٍ لأيّ «موقع»، فيحجب الصورة بـ
 * `net::ERR_BLOCKED_BY_RESPONSE.NotSameSite`. و`Access-Control-Allow-Origin`
 * لا يُبطل CORP: الفحصان مستقلّان وCORP يسبق. المخرج الوحيد الذي يملكه
 * المتجر أن يصير هو مصدر الصورة — وطلبٌ من الأصل نفسه يعبر CORP دائمًا.
 *
 * النمط مأخوذ من `apps/admin/app/api/v1/[...path]/route.ts` — ولكن مقصورًا
 * على الوسائط عمدًا: بقيّة نداءات المتجر تعمل من المتصفّح عبر CORS بضبطٍ
 * موثَّق («تصفّح المتجر من 127.0.0.1:3000»)، فتحويلها كلّها
 * إلى وسيطٍ تغييرٌ معماريّ لا يقتضيه هذا العطل.
 *
 * **لا كوكي يُمرَّر.** وسائط المنتجات عامّة (يردّ المصدر 200 بلا اعتماد)،
 * وتمريرُ اعتمادٍ عبر وسيطٍ قابلٍ للتخزين يجعل ملفًّا خاصًّا يُقدَّم لزائرٍ
 * آخر. وحين يردّ المصدر ٤٠١ أو ٤٠٣ يُنقل كما هو: الدلالة تُحفظ حرفيًّا ولا
 * تُخفَّض إلى «ملفّ مفقود».
 */

import { apiBase } from '../../../../../lib/api';

/** ترويسات الطلب المُمرَّرة — قائمة سماح لا حجب، وبلا `cookie` قصدًا. */
const FORWARD_REQUEST_HEADERS = ['accept', 'accept-language', 'if-none-match', 'if-modified-since'];

/**
 * ترويسات الاستجابة المنقولة.
 *
 * `cross-origin-resource-policy` ليس فيها: الصورة صارت على أصل المتجر
 * فالترويسة بلا أثر، ونقلُ قيمةٍ لا نتحكّم بها يُعيد العطل إن شُدِّدت يومًا.
 * و`content-security-policy` و`x-content-type-options` يُنقلان لأنّهما
 * دفاعُ المصدر عن ملفٍّ يرفعه مستخدم (SVG خبيث خاصّةً) — وإسقاطهما يفتح
 * ثغرةً لم تكن.
 */
const FORWARD_RESPONSE_HEADERS = [
  'content-type',
  'content-length',
  'content-disposition',
  'cache-control',
  'etag',
  'last-modified',
  'content-security-policy',
  'x-content-type-options',
];

export async function GET(
  request: Request,
  context: { params: Promise<{ path: string[] }> },
): Promise<Response> {
  const { path } = await context.params;

  /*
   * قطعةٌ نقطيّة تُرفض قبل البناء — نفس علّة وسيط اللوحة: Next يفكّ ترميز
   * القطع قبل تسليمها، و`encodeURIComponent('..') === '..'`، فيطوي محلّل
   * العناوين في `fetch` القطع ويخرج الطلب من `‎/api/v1/media‎` إلى جذر
   * الواجهة الخلفيّة. الوعد بالتمرير إلى الوسائط وحدها يصير صحيحًا لا مرجوًّا.
   */
  if (path.some((segment) => segment === '.' || segment === '..' || segment === '')) {
    return new Response('مسار وسائط غير صالح.', { status: 400 });
  }

  const base = apiBase();
  /*
   * لا عنوان ⇒ خطأ صريح لا صورة نائبة. «عقيدة الأمانة»: غياب الضبط عطلُ
   * نشرٍ يجب أن يُرى، لا نقصٌ يُرمَّم بصمت.
   */
  if (!base) return new Response('عنوان الواجهة الخلفيّة غير مضبوط.', { status: 502 });

  const search = new URL(request.url).search;
  const target = `${base}/api/v1/media/${path.map(encodeURIComponent).join('/')}${search}`;

  const headers = new Headers();
  for (const name of FORWARD_REQUEST_HEADERS) {
    const value = request.headers.get(name);
    if (value) headers.set(name, value);
  }

  let upstream: Response;
  try {
    // `manual` ليس صحيحًا هنا: تحويلَ المصدر يجب أن يُتبع على الخادم، وإلّا
    // وصل المتصفّح إلى عنوانٍ عبر الأصول فعاد العطل نفسه من بابٍ آخر.
    upstream = await fetch(target, { headers, redirect: 'follow', cache: 'no-store' });
  } catch {
    // تعذّر الوصول إلى المصدر: بوّابةٌ عاطلة، لا «ملفّ مفقود».
    return new Response('تعذّر الوصول إلى مصدر الوسائط.', { status: 502 });
  }

  const responseHeaders = new Headers();
  for (const name of FORWARD_RESPONSE_HEADERS) {
    const value = upstream.headers.get(name);
    if (value) responseHeaders.set(name, value);
  }

  // ٣٠٤ و٢٠٤ بلا جسم بحكم المواصفة؛ تمريرُ جسمٍ معهما يرمي `TypeError`.
  const body = upstream.status === 204 || upstream.status === 304 ? null : upstream.body;

  return new Response(body, { status: upstream.status, headers: responseHeaders });
}
