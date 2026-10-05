// UNIT: LIB-ADMIN-API
// FILE: admin-api.ts
// STYLE: none
// PARENT: ADMIN-SESSION, AI-REQUEST-LOG, ALERTS, ASSET-LIBRARY, CATALOG-TERMS, COMMAND-PALETTE +70
// USES: LIB-ADMIN-API-TYPES
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * طبقة الوصول لواجهة لوحة التشغيل.
 *
 * عقيدة الصدق (MIP §1.3): لا توجد بيانات احتياطية ولا قيم افتراضية. كل نداء
 * يعيد إمّا بيانات حقيقية من الخادم وإمّا خطأً صريحًا تعرضه الواجهة كما هو.
 *
 * الجلسة كوكي HttpOnly (غير مقروء من JS)، ورمز الحماية من التزوير يُحفظ في
 * الذاكرة فقط ويُرسل في ترويسة X-Admin-Csrf مع كل طلب يغيّر الحالة.
 */

import type { IntelGraph, IntelResponse, IntelUnavailable } from './admin-api-types';

/** نفس الأصل: الطلبات تمرّ عبر وسيط app/api/v1/[...path]. */
const BASE = '/api/v1';

const CSRF_HEADER = 'X-Admin-Csrf';

let csrfToken: string | null = null;

export function setCsrfToken(token: string | null): void {
  csrfToken = token;
}

type ApiOk<T> = { ok: true; data: T };
export type ApiErr = {
  ok: false;
  status: number;
  code: string | null;
  message: string;
  /** أخطاء تحقّق مفصّلة بالعربية من الخادم، إن وُجدت. */
  errors: Record<string, string[]> | null;
  body: unknown;
};
export type ApiResult<T> = ApiOk<T> | ApiErr;

type Payload = Record<string, unknown> | FormData | undefined;

/**
 * `signal` — إلغاء الطلب المتقادم.
 *
 * لم يكن للّوحة إلغاءٌ البتّة: لا `AbortController` في شيفرة التطبيق كلّها.
 * فمرشِّحٌ يُبدَّل مرّتين بسرعة يترك طلبَين طائرَين، وأيُّهما وصل أخيرًا كتب
 * على الشاشة — وقد يكون الأوّل. أي: نتيجةٌ تخصّ استعلامًا تخلّى عنه
 * المستعمل تُعرَض فوق نيّته الأحدث.
 *
 * و`react-query` يمرّر `signal` إلى `queryFn` ويُجهضه حين يتغيّر المفتاح أو
 * يُفكَّك المكوّن — فتمريره هنا يكفي ليرث كلّ مستدعي `useResource` الإلغاء.
 */
async function request<T>(
  method: string,
  path: string,
  payload?: Payload,
  signal?: AbortSignal,
): Promise<ApiResult<T>> {
  const isMultipart = typeof FormData !== 'undefined' && payload instanceof FormData;
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (payload !== undefined && !isMultipart) headers['Content-Type'] = 'application/json';
  if (method !== 'GET' && csrfToken) headers[CSRF_HEADER] = csrfToken;

  let response: Response;
  try {
    response = await fetch(`${BASE}${path}`, {
      method,
      headers,
      credentials: 'include',
      cache: 'no-store',
      signal,
      body: payload === undefined ? undefined : isMultipart ? payload : JSON.stringify(payload),
    });
  } catch (error) {
    /*
     * الإجهاض ليس عطلًا شبكيًّا.
     *
     * لو ابتُلع كـ`NETWORK_ERROR` لأظهرت كلّ كتابةِ حرفٍ في مرشِّحٍ رسالةَ
     * «تعذّر الاتصال بالخادم» عن طلبٍ ألغيناه نحن. الرمية تُعاد فيتعرّف
     * عليها `react-query` إلغاءً ويُسقطها بلا حالة خطأ.
     */
    if (signal?.aborted === true) throw error;

    return {
      ok: false,
      status: 0,
      code: 'NETWORK_ERROR',
      message: 'تعذّر الاتصال بالخادم.',
      errors: null,
      body: null,
    };
  }

  /*
   * قراءة الجسم داخل الحارس نفسه.
   *
   * كل مستدعٍ في اللوحة يعامل هذه الدالّة على أنها لا ترمي أبدًا — يفحص
   * `result.ok` ولا يضع `catch`. وكان `response.text()` خارج الحارس: انقطاعُ
   * الاتصال أثناء قراءة الجسم يرمي، فيبقى الزرّ معطَّلًا على «جارٍ…» بلا رسالة
   * ولا سبيل إلى إعادة المحاولة. الفشل هنا حالةٌ تُعاد لا استثناء يُطلَق.
   */
  let text: string;
  try {
    text = await response.text();
  } catch {
    return {
      ok: false,
      status: 0,
      code: 'NETWORK_ERROR',
      message: 'انقطع الاتصال أثناء قراءة ردّ الخادم — أعد المحاولة.',
      errors: null,
      body: null,
    };
  }

  let body: unknown = null;
  if (text) {
    try {
      body = JSON.parse(text) as unknown;
    } catch {
      body = text;
    }
  }

  if (response.ok) return { ok: true, data: body as T };

  const record = (body ?? {}) as Record<string, unknown>;
  return {
    ok: false,
    status: response.status,
    code: typeof record.code === 'string' ? record.code : null,
    message: typeof record.message === 'string' ? record.message : `فشل الطلب (${response.status}).`,
    errors: isValidationErrors(record.errors) ? record.errors : null,
    body,
  };
}

function isValidationErrors(value: unknown): value is Record<string, string[]> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export const api = {
  get: <T>(path: string, signal?: AbortSignal) => request<T>('GET', path, undefined, signal),
  post: <T>(path: string, payload?: Payload) => request<T>('POST', path, payload ?? {}),
  patch: <T>(path: string, payload?: Payload) => request<T>('PATCH', path, payload ?? {}),
  // PUT للاستبدال الكامل (وثيقة الثيم تُحفظ كاملة لا مُرقَّعة) و DELETE
  // للإعادة إلى الافتراضات. لم يكونا موجودَين، فلم يكن للوحة سبيل إلى أي
  // نقطة نهاية بهاتين الطريقتين.
  put: <T>(path: string, payload?: Payload) => request<T>('PUT', path, payload ?? {}),
  /*
   * الحذف بجسم: النسخة المتوقّعة تُرسَل معه. حذفٌ بلا فحص نسخةٍ يمحو
   * تحريرًا وقع قبل ثوانٍ ولا يُبقي منه إلّا صفًّا في سجلّ التدقيق.
   */
  delete: <T>(path: string, payload?: Payload) => request<T>('DELETE', path, payload),
  /*
   * الرفع بـmultipart: لا تُضبط Content-Type يدويًّا هنا — المتصفّح وحده يعرف
   * حدّ الأجزاء (boundary)، وضبطها يدويًّا يُنتج جسمًا لا يستطيع الخادم قراءته.
   */
  upload: <T>(path: string, form: FormData) => request<T>('POST', path, form),
};

/** أول رسالة تحقّق (بالعربية من الخادم) أو الرسالة العامة. */
export function firstError(error: ApiErr): string {
  if (error.errors) {
    for (const messages of Object.values(error.errors)) {
      if (messages && messages.length > 0 && messages[0]) return messages[0];
    }
  }
  return error.message;
}

/* ───── أنواع الاستجابات المستعملة في اللوحة ───── */

export type * from './admin-api-types';

export function intelAvailable(payload: IntelResponse): payload is IntelGraph {
  return (payload as IntelUnavailable).state !== 'NOT_GENERATED';
}
