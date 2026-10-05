/**
 * «اشترِ الآن» بلا جافاسكربت — POST ⇒ إضافة السطر ⇒ ٣٠٣ إلى الدفع.
 *
 * ─── لماذا يوجد ───
 *
 * الشريط اللاصق أسفل الجوّال هو أداة الشراء الوحيدة دون ‎768px‎، وكان زرّه
 * `onClick` خالصًا: من عطّل الجافاسكربت — أو وصلته الصفحة قبل إماهتها —
 * يضغط «اشترِ الآن» فلا يحدث شيء. والتصميم يرسم ضابطًا ينتقل.
 *
 * ─── ولماذا وسيطٌ لا نداءٌ مباشر ───
 *
 * السعر يُحسب في `PricingService` خلف `POST /api/v1/cart/items` — القانون ٢:
 * لا يحسب هذا الملفّ ثمنًا ولا يقرؤه أصلًا. هو ينقل الطلب كما هو ثمّ يحوّل.
 *
 * ─── عقد الكعكة ───
 *
 * هويّة السلّة كعكة `tahdheer_cart` (HttpOnly · SameSite=Lax · بلا Domain
 * فهي مربوطة بالمضيف). المتصفّح لا يرسلها إلى Laravel هنا — يرسلها إلى
 * المتجر — فتُنقل يدويًّا في رأس `Cookie`، ويُعاد ما يضعه الخادم من
 * `Set-Cookie` إلى المتصفّح حرفيًّا.
 *
 * ويشترط هذا أن يشترك المتجر وواجهة Laravel في المضيف نفسه (منفذان في
 * التطوير · مضيفٌ واحد خلف وكيلٍ في النشر). ولو فُصلا إلى مضيفين، رأى هذا
 * المسار كعكةً غير كعكة مسار الجافاسكربت وأنشأ سلّةً ثانية — راجع
 * `INTERNAL_API_URL` في `apps/storefront/.env.example` قبل النشر.
 */
import { apiBase } from '../../../../lib/api';
import { routes } from '../../../../lib/routes';

/* كتابةٌ في السلّة: لا تُخزَّن ولا تُصيَّر مسبقًا بحال. */
export const dynamic = 'force-dynamic';

/** الحدّ الأعلى لجسم النموذج — حقلان صغيران، وما زاد فليس نموذجنا. */
const MAX_BODY_BYTES = 2048;

/**
 * معرّفٌ موجبٌ صحيح، أو `null`.
 *
 * `productId` في هذا المتجر عددٌ من قاعدة البيانات. وقيمةٌ غير ذلك تُردّ
 * ولا تُمرَّر إلى الخادم بأملٍ أن يرفضها.
 */
function positiveId(raw: FormDataEntryValue | null): number | null {
  if (typeof raw !== 'string') return null;
  const trimmed = raw.trim();
  if (!/^[0-9]{1,15}$/.test(trimmed)) return null;
  const value = Number(trimmed);
  return Number.isSafeInteger(value) && value > 0 ? value : null;
}

/**
 * ٣٠٣ لا ٣٠٢: بعد POST يجب أن يصير التالي GET، وإلّا أعاد «رجوع» في
 * المتصفّح إرسالَ النموذج فأُضيف السطر مرّتين.
 */
function seeOther(location: string, setCookies: string[]): Response {
  const headers = new Headers({ Location: location, 'Cache-Control': 'no-store' });
  for (const cookie of setCookies) headers.append('Set-Cookie', cookie);
  return new Response(null, { status: 303, headers });
}

export async function POST(request: Request): Promise<Response> {
  const base = apiBase();
  /*
   * لا عنوانَ للواجهة = عطل نشر. والتحويل إلى السلّة يُري الزائرَ حالته
   * الحقيقيّة (سلّةٌ بلا هذا السطر) بدل صفحة دفعٍ لا سطر فيها.
   */
  if (!base) return seeOther(routes.cart, []);

  const length = Number(request.headers.get('content-length') ?? '0');
  if (Number.isFinite(length) && length > MAX_BODY_BYTES) {
    return seeOther(routes.cart, []);
  }

  let form: FormData;
  try {
    form = await request.formData();
  } catch {
    return seeOther(routes.cart, []);
  }

  const productId = positiveId(form.get('productId'));
  if (productId === null) return seeOther(routes.cart, []);
  /* الصيغة اختياريّة: منتجٌ بصيغةٍ واحدة يُسعَّر من سعر المنتج كما كان. */
  const variantId = positiveId(form.get('variantId'));

  const cookie = request.headers.get('cookie');
  let response: Response;
  try {
    response = await fetch(`${base}/api/v1/cart/items`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(cookie !== null ? { Cookie: cookie } : {}),
      },
      body: JSON.stringify(variantId === null ? { productId } : { productId, variantId }),
      cache: 'no-store',
    });
  } catch {
    return seeOther(routes.cart, []);
  }

  /*
   * كعكة السلّة تُنقل في الحالتين — نجاحًا وفشلًا: الخادم ينشئ السلّة قبل
   * أن يفشل السطر أحيانًا، وإسقاط الكعكة حينئذ يبني سلّةً جديدة كلّ مرّة.
   */
  const setCookies = response.headers.getSetCookie();

  /*
   * الفشل لا يذهب إلى الدفع. ولا تُخترع هنا رسالةٌ عربيّة: الوجهة هي
   * السلّة، وهي تعرض ما فيها حقًّا — فيرى الزائر أنّ السطر لم يُضَف من
   * بياناتٍ صادقة لا من نصٍّ يقوله هذا الملفّ.
   */
  if (!response.ok) return seeOther(routes.cart, setCookies);

  return seeOther(routes.checkout, setCookies);
}
