// UNIT: API-API-V1-PATH
// FILE: route.ts
// ROUTE: /api/v1/[...path]
// STYLE: none
// PARENT: none
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * وسيط الواجهة الخلفية للوحة التشغيل.
 *
 * المتصفّح يخاطب أصلًا واحدًا (نفس أصل اللوحة) فيصل كوكي الجلسة HttpOnly
 * ذهابًا وإيابًا دون CORS ودون كشف الكوكي لأي سكربت. الطلب يُمرَّر كما هو
 * إلى Laravel: الترويسات الضرورية فقط، والجسم كما ورد، و«set-cookie» يُعاد
 * كاملًا (قد يكون أكثر من ترويسة).
 *
 * الوجهة تُضبط بـ ADMIN_API_URL على الخادم فقط — لا يُسرَّب العنوان للعميل.
 */

export const dynamic = 'force-dynamic';

const TARGET = (process.env.ADMIN_API_URL ?? 'http://localhost:8000').replace(/\/+$/, '');

/** ترويسات الطلب المسموح تمريرها — قائمة سماح لا حجب. */
const FORWARD_REQUEST_HEADERS = ['accept', 'accept-language', 'content-type', 'cookie', 'x-admin-csrf'];

/** ترويسات الاستجابة المنقولة (عدا set-cookie الذي يُلحق منفصلًا). */
const FORWARD_RESPONSE_HEADERS = ['content-type', 'cache-control'];

async function proxy(request: Request, context: { params: Promise<{ path: string[] }> }): Promise<Response> {
  const { path } = await context.params;

  /*
   * قطعةُ مسارٍ نقطيّة تُرفض قبل البناء.
   *
   * Next يفكّ ترميز القطع قبل تسليمها (`route-matcher` سطر ١٩)، فـ
   * `‎/api/v1/%2e%2e/%2e%2e/horizon‎` يصل هنا `['..','..','horizon']`.
   * و`encodeURIComponent('..') === '..'` — النقطة حرفٌ غير محجوز فلا يُرمَّز —
   * ثمّ يطوي محلّل العناوين في `fetch` القطع فيخرج الطلب من `‎/api/v1‎`
   * كلّه إلى جذر الواجهة الخلفيّة. الوسيط يَعِد بتمريرٍ إلى `‎/api/v1/*‎`
   * وحدها؛ وهذا يجعل الوعد صحيحًا لا مرجوًّا.
   */
  if (path.some((segment) => segment === '.' || segment === '..' || segment === '')) {
    return Response.json({ message: 'مسار غير صالح.', code: 'INVALID_PATH' }, { status: 400 });
  }

  const search = new URL(request.url).search;
  const target = `${TARGET}/api/v1/${path.map(encodeURIComponent).join('/')}${search}`;

  const headers = new Headers();
  for (const name of FORWARD_REQUEST_HEADERS) {
    const value = request.headers.get(name);
    if (value) headers.set(name, value);
  }

  /*
   * الجسم بايتاتٍ لا نصًّا: `text()` يفكّ الترميز بـUTF-8، وكلّ بايتٍ غير
   * صالحٍ فيه يصير U+FFFD بلا رجعة. ورفعُ ملفّات المنتجات ووسائطها يمرّ من
   * هنا (`lib/admin-api.ts:123`) بـ`multipart/form-data`، فكان الملفّ يصل
   * الخادم مشوَّهًا وأطولَ من أصله. مقيسٌ: ٩٢ بايتًا دخلت و١٠٢ خرجت،
   * وتوقيع PNG لم يَعُد موجودًا في الناتج.
   */
  const method = request.method.toUpperCase();
  const body = method === 'GET' || method === 'HEAD' ? undefined : await request.arrayBuffer();

  let upstream: Response;
  try {
    upstream = await fetch(target, { method, headers, body, redirect: 'manual', cache: 'no-store' });
  } catch {
    // حالة صادقة: الواجهة الخلفية غير متاحة — لا بيانات بديلة ولا أصفار.
    return Response.json(
      {
        message: 'تعذّر الوصول إلى الواجهة الخلفية. تحقّق من تشغيل الخادم ومن ADMIN_API_URL.',
        code: 'API_UNREACHABLE',
      },
      { status: 502 },
    );
  }

  const outgoing = new Headers();
  for (const name of FORWARD_RESPONSE_HEADERS) {
    const value = upstream.headers.get(name);
    if (value) outgoing.set(name, value);
  }

  const response = new Response(await upstream.arrayBuffer(), { status: upstream.status, headers: outgoing });
  for (const cookie of upstream.headers.getSetCookie()) {
    response.headers.append('set-cookie', cookie);
  }

  return response;
}

export const GET = proxy;
export const POST = proxy;
export const PATCH = proxy;
export const PUT = proxy;
export const DELETE = proxy;
