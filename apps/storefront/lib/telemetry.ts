/**
 * قياس أوّل الطرف — الطابور الذي يقف بين الصفحة والـAPI.
 *
 * ─── لماذا طابورٌ لا نداءٌ لكلّ حدث ───
 *
 * الحدث الواحد رخيص، وعشرون نداءً في جلسة تصفّحٍ واحدة ليست كذلك: كلّ واحد
 * منها رحلةُ شبكةٍ تزاحم جلب الصفحة التالية على وصلة الزائر. فتُجمع الأحداث
 * وتُرسل دفعةً — والدفعة نفسها هي ما يقبله الخادم (`MAX_BATCH = 20`).
 *
 * ─── ومتى تُفرَغ ───
 *
 *  · عند امتلاء الدفعة — فلا ينمو الطابور بلا حدّ في جلسةٍ طويلة.
 *  · بعد خمول — فحدثٌ وحيدٌ في صفحةٍ يُقرأ ببطء لا ينتظر إلى الأبد.
 *  · عند إخفاء اللسان أو مغادرة الصفحة — وهي اللحظة التي يُفقد فيها أكثر
 *    القياس في التطبيقات التي تنتظر `unload` وحده: المتصفّحات الجوّالة لا
 *    تُطلقه أصلًا حين يُغلق اللسان من مبدّل التطبيقات.
 *
 * ─── ولماذا `keepalive` لا `sendBeacon` ───
 *
 * `sendBeacon` لا يحمل `credentials` ولا يقبل نوع محتوًى يتطلّب فحصًا
 * مسبقًا — فإمّا أن تُرسل الكعكة فلا يُعرف الزائر، أو يُرسل النصّ الخام
 * فيحتاج الخادم محلّلًا ثانيًا لجسمٍ يعرف أنّه JSON. و`fetch` بـ`keepalive`
 * يبقى حيًّا بعد إغلاق الصفحة ويحمل الكعكة وترويسة النوع كأيّ نداءٍ آخر.
 *
 * ─── وما لا يُرسل أبدًا ───
 *
 * إشارة «لا تتبّعني» تُفحص هنا أيضًا، لا اتّكالًا على الخادم: الوعد المنشور
 * يقول «يتوقّف القياس كلّيًا»، وطلبٌ يُرسل ثمّ يُرفض قياسٌ وقع ثمّ أُتلف.
 * والمتصفّح يرسل `Sec-GPC` من نفسه، فالخادم يرفض في الحالين — وهذا الفحص
 * يجعل الوعد صادقًا على الشبكة لا في قاعدة البيانات وحدها.
 */
import { apiBase } from './api';

/** ما يقبله الخادم من أنواع — القائمة نفسها في `EventIngest::TYPES`. */
export type TelemetryType =
  | 'page_view'
  | 'product_view'
  | 'add_to_cart'
  | 'checkout_start'
  | 'search'
  | 'purchase';

export interface TelemetryEvent {
  type: TelemetryType;
  path?: string;
  referrer?: string;
  productId?: number;
  valueMinor?: number;
  isLoggedIn?: boolean;
}

/** سقف الدفعة — مطابقٌ لسقف الخادم، فلا يُبتر ما يُرسل. */
const MAX_BATCH = 20;

/** مهلة الخمول قبل الإرسال. */
const IDLE_MS = 5000;

interface Queued extends TelemetryEvent {
  /** معرّف الحدث لدى العميل — به يعرف الخادم إعادة الإرسال من التكرار. */
  id: string;
}

let queue: Queued[] = [];
let timer: ReturnType<typeof setTimeout> | null = null;
let listening = false;

/**
 * هل يرفض هذا المتصفّح القياس؟
 *
 * `globalPrivacyControl` هو المعيار الحديث، و`doNotTrack` أقدم وله ثلاثة
 * مواضع عبر تاريخ المتصفّحات — والثلاثة تُقرأ لأنّ الوعد لم يستثنِ متصفّحًا.
 */
export function refusesTracking(): boolean {
  if (typeof navigator === 'undefined') return false;

  const nav = navigator as Navigator & {
    globalPrivacyControl?: boolean;
    msDoNotTrack?: string;
  };

  if (nav.globalPrivacyControl === true) return true;

  const dnt =
    nav.doNotTrack ??
    nav.msDoNotTrack ??
    (typeof window !== 'undefined'
      ? (window as Window & { doNotTrack?: string }).doNotTrack
      : undefined);

  return dnt === '1' || dnt === 'yes';
}

/** إضافة حدثٍ إلى الطابور. لا يرمي أبدًا: القياس لا يُسقط صفحة. */
export function track(event: TelemetryEvent): void {
  if (typeof window === 'undefined' || refusesTracking() || apiBase() === null) return;

  queue.push({ ...event, id: eventId() });
  listen();

  if (queue.length >= MAX_BATCH) {
    flush();
    return;
  }

  if (timer !== null) clearTimeout(timer);
  timer = setTimeout(flush, IDLE_MS);
}

/**
 * إفراغ الطابور الآن.
 *
 * الطابور يُفرَّغ **قبل** الإرسال لا بعده: إخفاءٌ ثمّ إظهارٌ سريع يستدعي
 * `flush` مرّتين، وقراءةُ الطابور بعد `await` ترسل الدفعة نفسها مرّتين.
 */
export function flush(): void {
  if (timer !== null) {
    clearTimeout(timer);
    timer = null;
  }

  if (queue.length === 0) return;

  const base = apiBase();
  if (base === null) {
    queue = [];
    return;
  }

  const batch = queue.slice(0, MAX_BATCH);
  queue = queue.slice(MAX_BATCH);

  void fetch(`${base}/api/v1/events`, {
    method: 'POST',
    // الكعكة هي هويّة الزائر — وبلا `include` يصير كلّ زائرٍ زائرًا جديدًا.
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ events: batch }),
    keepalive: true,
  }).catch(() => {
    /*
     * الفشل يُبتلع — وهذا الموضع الوحيد الذي يجوز فيه ذلك في المشروع.
     *
     * الردّ ٢٠٤ بلا جسم بالتصميم، فلا شيء يُقرأ. وإعادة المحاولة تضاعف
     * الحدث في جدولٍ الدقّةُ فيه أهمّ من الاكتمال. وسطرٌ في طرفيّة الزائر
     * عن فشل قياسٍ لا يعنيه ضجيجٌ يُخفي أخطاء المتجر الحقيقيّة.
     */
  });

  // بقيّةُ طابورٍ تجاوز السقف تُرسل في الدفعة التالية لا تُهمَل.
  if (queue.length > 0) flush();
}

function eventId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  // متصفّحٌ بلا `randomUUID`: معرّفٌ أضعف يكفي لكشف إعادة الإرسال وحدها.
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

function listen(): void {
  if (listening || typeof document === 'undefined') return;
  listening = true;

  /*
   * الحدثان معًا لا أحدهما: `visibilitychange` يقع عند التبديل بين اللسان
   * وغيره، و`pagehide` عند المغادرة الفعليّة — ومتصفّحات الجوّال قد تُطلق
   * الأوّل وحده حين يُقتل اللسان من مبدّل التطبيقات.
   */
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush();
  });
  window.addEventListener('pagehide', flush);
}
