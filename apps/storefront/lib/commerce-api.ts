/**
 * Commerce API fetchers — typed 1:1 against the pre-decided commerce contract
 * (cart / checkout / orders / payments / downloads).
 *
 * ملاحظة: حين تصل حزمة @tahdheer/api-sdk المولَّدة من OpenAPI تحلّ محلّ هذه
 * الأنواع والجالبات المكتوبة يدويًّا.
 *
 * Cart identity = HttpOnly encrypted cookie «tahdheer_cart» ⇒ every
 * cart/checkout call sends credentials:'include'. Never throws — network
 * absence surfaces as status 0 so callers render the honest error card.
 */
import { cartItemBody } from './pdp-selection';
import { apiBase } from './api';
import { budget } from './fetch-budget';

// ---------------------------------------------------------------- cart types

export interface CartLine {
  id: number | string;
  productId: number | string;
  title: string;
  slug: string;
  priceMinor: number;
  regularMinor?: number;
  discountPct?: number;
  /** Digital lines are qty-locked to 1 (MIP §5.3). */
  qty: number;
  categoryPath: string[];
  /** صيغة السطر — صيغة المتغيّر المشترى حين وُجد، وإلّا صيغة المنتج. */
  format: string;
  /**
   * الصيغة المشتراة. سطران للمنتج نفسه بصيغتين يحملان العنوان ذاته، فهذان
   * الحقلان هما ما يميّزهما — في العرض وفي تسمية زرّ الحذف لقارئ الشاشة.
   */
  variantId?: number;
  variantTitle?: string;
  /** Per-line «ماذا يتضمن؟» disclosure content — absent ⇒ disclosure hidden. */
  includes?: string[];
}

/** Derived pricing chain — server-authoritative; never recompute client-side. */
export interface CartTotals {
  grossMinor: number;
  productDiscountMinor: number;
  couponDiscountMinor: number;
  totalMinor: number;
  vatMinor: number;
  savingMinor: number;
}

export interface CartCoupon {
  code: string;
  label: string;
}

/**
 * ما تطلبه هذه السلّة بعينها — يقرّره الخادم لا الواجهة.
 *
 * ─── لماذا من الخادم ───
 *
 * المطلوب يُشتقّ من استراتيجيّات ما في السلّة عبر المحدِّد نفسه الذي ينفّذ
 * بعد السداد. فما يَعِد به الدفعُ هو ما يقع بالضبط. ولو قرّرت الواجهة بنفسها
 * «الماديّ يوجب عنوانًا» لَافترق الوعدُ عن التنفيذ عند أوّل اختلاف — وهو
 * أسوأ أنواع الاختلاف لأنّه يظهر بعد أخذ المال.
 */
export interface CartRequirements {
  strategies: string[];
  requiresShippingAddress: boolean;
  requiresBookingDetails: boolean;
  /** أكثر من استراتيجيّةٍ في السلّة — تنقسم إلى وحدات تنفيذٍ مستقلّة. */
  isMixed: boolean;
  canProceed: boolean;
  /** أسبابٌ تمنع الدفع، كما كتبها الخادم. لا تُطوى في «تعذّر». */
  blockedReasons: string[];
}

export interface CartPayload {
  items: CartLine[];
  totals: CartTotals;
  coupon: CartCoupon | null;
  count: number;
  /** غائبةٌ في ردٍّ من خادمٍ أقدم — تُعامَل «لا شيء مطلوب» لا تُفترض. */
  requirements?: CartRequirements;
}

// ------------------------------------------------------- checkout/order types

export interface TaxProfile {
  orgName: string;
  vatNumber: string;
}

/**
 * عنوان الشحن. يُرسَل حين تطلبه السلّة وحدها (§٣٢).
 *
 * المدينة وحدها إلزاميّة: هي ما تحتاجه شركات الشحن أدنى حدّ. وما يُترك
 * فارغًا يبقى فارغًا في السجلّ — عنوانٌ مُلفَّق يصل الناقل فيرتدّ الطرد.
 */
export interface ShippingAddressInput {
  recipientName?: string;
  phone?: string;
  countryCode?: string;
  region?: string;
  city: string;
  district?: string;
  street?: string;
  buildingNumber?: string;
  secondaryNumber?: string;
  postalCode?: string;
  notes?: string;
}

export interface CheckoutInput {
  name: string;
  phone: string;
  email: string;
  taxProfile: TaxProfile | null;
  policyConsent: true;
  marketingOptIn: boolean;
  /**
   * يُرسَل فقط حين تُعلن السلّة أنّها تحتاجه.
   *
   * إرسالُه دائمًا كان سيطلب عنوانًا من مشتري ملفٍّ رقميّ — وجمعُ ما لا يلزم
   * مسألةُ خصوصيّة قبل أن يكون مسألة تحويل.
   */
  shippingAddress?: ShippingAddressInput;
  /**
   * `apple_pay` حين اختار المشتري Apple Pay وأكّده الخادم والجهاز
   * (lib/apple-pay.ts)؛ غيابه = صفحة المزوّد بكلّ طرقها كما كانت.
   */
  paymentMethod?: PaymentMethod;
}

/** الطريقة الوحيدة التي تُطلب بالاسم؛ `null` = الافتراضيّة. */
export type PaymentMethod = 'apple_pay';

export interface CheckoutPayment {
  provider: string;
  status: 'requires_payment';
  /** الطريقة التي أُنشئت لها النيّة (`null` = الافتراضيّة). */
  method?: PaymentMethod | null;
  payUrl?: string;
}

export interface CheckoutOrderResponse {
  orderNumber: string;
  orderToken: string;
  payment: CheckoutPayment;
}

export interface OrderFileRow {
  entitlementId: number | string;
  title: string;
  filename: string;
  locked: boolean;
}

export interface OrderView {
  orderNumber: string;
  status: string;
  paidAt?: string;
  emailMasked: string;
  items: { title: string; priceMinor: number }[];
  totals: CartTotals;
  files: OrderFileRow[];
  paymentMethodMasked?: string;
  /** حقيقة الدفع من الخادم (§١٤): رابطٌ حيّ يُعاد، وتحصيلٌ وصل يُعلَن. */
  payment: {
    provider: string | null;
    state: string | null;
    payUrl: string | null;
    canRetry: boolean;
  };
  /** `MISMATCH`: وصل مالٌ ولم يُموِّل الطلب — لا يُعرض الدفع مجدّدًا. */
  paymentIssue: 'MISMATCH' | null;
}

export type GrantDeniedReason = 'revoked' | 'expired' | 'limit' | 'unpaid' | 'forbidden';

export interface GrantResponse {
  url: string;
  expiresAt: string;
  /**
   * هل خلف هذا الرابط ملفٌّ فعلًا.
   *
   * الخادم يمنح الرابط ويردّ ٢٠٠ حتى حين لا أصل مرتبطًا بالمنتج
   * (`DownloadController::grant` → `'fileAvailable' => $asset !== null`)،
   * فكان `url` يُتبَع فيهبط المشتري على JSON خامّ بدل ملفّه.
   */
  fileAvailable?: boolean;
  filename?: string | null;
}

// ------------------------------------------------------------- result shape

/**
 * سبب الفشل، مصنَّفًا.
 *
 * كان `status: 0` يعني «غير مضبوط» و«غير متاح» معًا، فتعرض الواجهة رسالة
 * واحدة للحالتين. وهذا بالضبط ما أخفى عطل السلة على الإنتاج: العنوان لم
 * يُخبز في حزمة المتصفّح، فلم يُرسَل طلب واحد أبدًا — بينما بدت الرسالة وكأن
 * الخادم ساقط، والخادم كان يردّ 200 طوال الوقت.
 *
 *  · `unconfigured` — عطل بناء/نشر، لا عطل شبكة. لا يُصلحه إعادة المحاولة.
 *  · `network`      — الطلب أُرسل وفشل النقل. إعادة المحاولة منطقية.
 *  · `http`         — الخادم ردّ برمز خطأ. الرسالة منه.
 */
export type CommerceFailure = 'unconfigured' | 'network' | 'http';

export interface CommerceResult<T> {
  data: T | null;
  /** HTTP status; 0 when no response was ever received. */
  status: number;
  /** موجود عند الفشل فقط. */
  failure?: CommerceFailure;
  /** Server message (e.g. the 422 coupon error) — render verbatim. */
  message?: string;
  /** Laravel-style field errors on 422. */
  errors?: Record<string, string[]>;
  /** 403 download-grant denial reason. */
  reason?: GrantDeniedReason;
}

async function request<T>(
  path: string,
  init: { method?: string; body?: unknown; headers?: Record<string, string> } = {},
): Promise<CommerceResult<T>> {
  const base = apiBase();
  if (!base) {
    /*
     * NEXT_PUBLIC_API_URL لم يُخبز في حزمة المتصفّح. عطل نشر لا عطل شبكة،
     * ولا تُصلحه إعادة المحاولة — ولذلك يُصنَّف صراحةً ويُسجَّل مرّة واحدة
     * في الطرفية ليراه من ينشر، لا ليُدفن تحت رسالة «تعذّر الاتصال».
     */
    warnUnconfiguredOnce();
    return { data: null, status: 0, failure: 'unconfigured' };
  }
  try {
    const res = await fetch(`${base}${path}`, {
      /* القراءة بميزانيّة صفحة، والكتابة بميزانيّة فعلٍ ينتظره الزائر بعينه. */
      signal: budget(init.method !== undefined && init.method !== 'GET' ? 'action' : 'page'),
      method: init.method ?? 'GET',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(init.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...init.headers,
      },
      body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
      cache: 'no-store',
    });
    let payload: unknown = null;
    try {
      payload = await res.json();
    } catch {
      payload = null;
    }
    if (!res.ok) {
      const err = (payload ?? {}) as {
        message?: string;
        errors?: Record<string, string[]>;
        reason?: GrantDeniedReason;
      };
      return {
        data: null,
        status: res.status,
        failure: 'http',
        message: err.message,
        errors: err.errors,
        reason: err.reason,
      };
    }
    return { data: payload as T, status: res.status };
  } catch {
    return { data: null, status: 0, failure: 'network' };
  }
}

/** تحذير واحد لكل جلسة: تكراره لكل طلب ضجيج لا معلومة. */
let warned = false;
function warnUnconfiguredOnce(): void {
  if (warned) return;
  warned = true;
  if (typeof console !== 'undefined') {
    console.error(
      '[tahdheer] NEXT_PUBLIC_API_URL غير مضبوط في حزمة المتصفّح. '
        + 'مرّره وسيطَ بناء (ARG) لا متغيّر تشغيل — انظر apps/storefront/Dockerfile.',
    );
  }
}

// ------------------------------------------------------------------ fetchers

/**
 * قراءةُ السلّة المتزامنة تُدمج في طلبٍ واحد (single-flight).
 *
 * كانت الصفحة الواحدة تُرسل ٤–١٠ نداءات `GET /cart` عند التحميل: الترويسة،
 * وكلّ `useCartMembership` في لوحة الشراء والشريط اللاصق والبطاقات، والوضع
 * الصارم يضاعفها — كلّها في اللحظة نفسها ولها الجواب نفسه، وكلّها من ميزانيّة
 * المعدّل. الآن يتشارك المتزامنون الطلب الجاري، ولا تخزين بعده: النداء التالي
 * بعد انتهائه طلبٌ جديد، وأيّ كتابةٍ على السلّة تفكّ الدمج فلا يُعطى أحدٌ
 * جوابًا سبق كتابته. في الخادم لا دمج (حالة الوحدة مشتركةٌ بين الزوّار).
 */
let cartInFlight: Promise<CommerceResult<CartPayload>> | null = null;

export function getCart(): Promise<CommerceResult<CartPayload>> {
  if (typeof window === 'undefined') return request<CartPayload>('/api/v1/cart');
  if (cartInFlight) return cartInFlight;
  const pending = request<CartPayload>('/api/v1/cart').finally(() => {
    if (cartInFlight === pending) cartInFlight = null;
  });
  cartInFlight = pending;
  return pending;
}

/** كتابةٌ على السلّة ⇒ لا يُشارَك جوابُ قراءةٍ بدأت قبلها. */
function cartWrite<T>(run: () => Promise<CommerceResult<T>>): Promise<CommerceResult<T>> {
  cartInFlight = null;
  return run().finally(() => {
    cartInFlight = null;
  });
}

/** Duplicate digital product ⇒ server returns the unchanged cart with 200. */
/**
 * `variantId` هو صيغة الملفّ المختارة (Word · PowerPoint · PDF).
 *
 * يُرسل فقط حين يُختار: منتجٌ بصيغةٍ واحدة يُضاف كما كان، والخادم يسعّره من
 * سعر المنتج. وحين يُرسل يصير العرضُ مصدرَ السعر هناك — فالذي عُرض في الصفحة
 * هو الذي يُحصَّل.
 */
export function addCartItem(
  productId: number | string,
  variantId?: number | null,
): Promise<CommerceResult<CartPayload>> {
  return cartWrite(() =>
    request<CartPayload>('/api/v1/cart/items', {
      method: 'POST',
      body: cartItemBody(productId, variantId),
    }),
  );
}

export function removeCartItem(itemId: number | string): Promise<CommerceResult<CartPayload>> {
  return cartWrite(() =>
    request<CartPayload>(`/api/v1/cart/items/${encodeURIComponent(String(itemId))}`, {
      method: 'DELETE',
    }),
  );
}

/** Invalid/expired ⇒ 422 with the Arabic server message (render verbatim). */
export function applyCoupon(code: string): Promise<CommerceResult<CartPayload>> {
  return cartWrite(() => request<CartPayload>('/api/v1/cart/coupon', { method: 'POST', body: { code } }));
}

export function removeCoupon(): Promise<CommerceResult<CartPayload>> {
  return cartWrite(() => request<CartPayload>('/api/v1/cart/coupon', { method: 'DELETE' }));
}

/** إنشاء الطلب يُفرغ السلّة على الخادم — فهو كتابةٌ عليها. */
export function createOrder(input: CheckoutInput): Promise<CommerceResult<CheckoutOrderResponse>> {
  return cartWrite(() =>
    request<CheckoutOrderResponse>('/api/v1/checkout/orders', {
      method: 'POST',
      body: input,
    }),
  );
}

/** DEV-ONLY fake charge (server aborts 404 in production). */
export function confirmFakePayment(orderToken: string): Promise<CommerceResult<unknown>> {
  return request<unknown>(
    `/api/v1/payments/fake/${encodeURIComponent(orderToken)}/confirm`,
    { method: 'POST' },
  );
}

/**
 * §٢٢ — استعادة رابط الدفع لطلبٍ بانتظاره. الخادم يُعيد النيّة القائمة أو
 * يُنشئها بمفتاحها؛ 409 حين لا يجوز الدفع (مدفوع، أو مالٌ وصل ولم يُطابَق).
 */
export function retryPayment(
  orderToken: string,
): Promise<CommerceResult<{ orderNumber: string; payment: OrderView['payment'] }>> {
  return request(`/api/v1/checkout/orders/${encodeURIComponent(orderToken)}/payment`, { method: 'POST' });
}

/**
 * تبديل طريقة الدفع لطلبٍ قائم — المسار نفسه (`/payment`) بطريقةٍ مسمّاة.
 * لكلّ طريقةٍ نيّتها عند الخادم، فالعائد رابطُ الطريقة المطلوبة لا غيرها؛
 * و422 حين لا يقبل المزوّد Apple Pay (الرسالة العربيّة تُعرض كما هي).
 */
export function choosePaymentMethod(
  orderToken: string,
  paymentMethod: PaymentMethod | null,
): Promise<CommerceResult<{ orderNumber: string; payment: CheckoutPayment }>> {
  return request(`/api/v1/checkout/orders/${encodeURIComponent(orderToken)}/payment`, {
    method: 'POST',
    body: { paymentMethod },
  });
}

/** Token-authed order view — usable from server components (no cookie needed). */
export function getOrder(token: string): Promise<CommerceResult<OrderView>> {
  return request<OrderView>(`/api/v1/orders/${encodeURIComponent(token)}`);
}

/** Grant a short-lived download URL; 403 carries the honest denial reason. */
export function grantDownload(
  entitlementId: number | string,
  orderToken: string,
): Promise<CommerceResult<GrantResponse>> {
  return request<GrantResponse>(
    `/api/v1/downloads/${encodeURIComponent(String(entitlementId))}/grant`,
    { method: 'POST', headers: { 'X-Order-Token': orderToken } },
  );
}
