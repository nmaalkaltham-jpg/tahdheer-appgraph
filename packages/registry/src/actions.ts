// UNIT: REGISTRY-ACTIONS
// FILE: actions.ts
// STYLE: none
// PARENT: DOCUMENT, REGISTRY-INDEX
// USES: DOCUMENT
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * سجلّ الإجراءات المغلق (STUDIO-PHASE3-CONTRACT §2.4، قرار C35): صفوف
 * الكتالوج **بيانات** تُرسم منها تبويبة التفاعلات، والمرآة في PHP
 * (normalizeInteractions) يطابقها الحارس معرّفًا معرّفًا. ١١ إجراءً تشحن —
 * كلٌّ يستشهد بسنده الحي في المتجر؛ المؤجَّل يُعرض معطّلًا **مع سببه
 * المكتوب** (§76)، و«نداء API» غير مدرج إطلاقًا — الإغلاق هو الإنفاذ (§63):
 * التنفيذ switch مغلق على ActionId، لا eval ولا مسار نص→كود.
 *
 * `isExecutableInteraction` هو المسند الواحد الذي يسوّر **معًا** سماتِ
 * الإتاحة في DocumentRenderer والتنفيذَ في action-runtime — الزائر لا يرى
 * مؤشّر يدٍ فوق عقدة ميتة (§76 ممدودًا للزوّار). الحمولات الناقصة تُحفظ
 * (المسوّدة تنجو من الحفظ التلقائي) — الصدق يحمله المسند وpreflight.
 */
import {
  STUDIO_ID_PATTERN,
  componentOf,
  isSymbolRef,
  walkNodes,
  type StudioDocument,
  type StudioNode,
} from './document.ts';

/* ───── الأحداث ───── */

/** الحدث الوحيد المشحون هذه الجولة. */
export const EVENTS = ['click'] as const;
export type ActionEvent = (typeof EVENTS)[number];

/** أحداث مؤجَّلة — تُعرض معطّلة في قائمة الحدث مع سببها (§6). */
export const DEFERRED_EVENTS = [
  { id: 'hover', label: 'مرور المؤشر', reason: 'تفاعل التمرير الشكلي يُضبط من تبويب الأنماط — حالة hover' },
  { id: 'dblclick', label: 'نقر مزدوج', reason: 'لا فعل صادق خلف هذا الحدث بعد' },
  { id: 'scroll-into-view', label: 'دخول مجال الرؤية', reason: 'الكشف عند التمرير هو حركة الدخول نفسها — تبويب الحركة' },
  { id: 'scroll-progress', label: 'نسبة التمرير', reason: 'نسبة التمرير محفّزُ منبثقة (§2.7) — لا حدث تفاعل بعد' },
  { id: 'page-load', label: 'تحميل الصفحة', reason: 'الظهور عند التحميل من اختصاص المنبثقة ومحفّزاتها' },
  { id: 'page-exit', label: 'نيّة مغادرة الصفحة', reason: 'نيّة الخروج محفّزُ منبثقة — لا حدث تفاعل عام' },
  { id: 'form-submit', label: 'إرسال نموذج', reason: 'لا فعل صادق خلف هذا الحدث بعد' },
  { id: 'product-added', label: 'إضافة منتج للسلة', reason: 'لا ناقل أحداث للسلة على واجهة الزائر بعد' },
  { id: 'cart-updated', label: 'تحديث السلة', reason: 'لا ناقل أحداث للسلة على واجهة الزائر بعد' },
  { id: 'timer', label: 'مؤقّت', reason: 'لا فعل صادق خلف هذا الحدث بعد' },
  { id: 'breakpoint', label: 'تغيّر مقاس الشاشة', reason: 'لا فعل صادق خلف هذا الحدث بعد' },
] as const;

/* ───── الكتالوج ───── */

export interface ActionPayloadField {
  /** مفتاح الحمولة كما يُخزَّن في NodeInteraction.payload حرفيًّا. */
  key: string;
  label: string;
  kind: 'productId' | 'url' | 'text' | 'code' | 'slug' | 'nodeId' | 'popupId' | 'toggle';
  required: boolean;
  max?: number;
  /** يقبل ربطًا نصّيًّا كامل السلسلة من BINDING_PATHS فقط. */
  bindable?: true;
}

export interface ActionCatalogRow {
  id: string;
  label: string;
  /** 'exists' فقط عندما يقف خلفه فعل حي اليوم؛ وإلا سبب التأجيل المكتوب. */
  status: 'exists' | { readonly deferred: string };
  payload: readonly ActionPayloadField[];
}

/**
 * الصفوف الأحد عشر المشحونة + المؤجَّل بأسبابه. «نداء API» غير مدرج
 * إطلاقًا (§63 — لا مفتاح كتالوج يمكنه تسمية endpoint).
 */
export const ACTION_CATALOG = [
  {
    id: 'add_to_cart',
    label: 'أضِف إلى السلة',
    status: 'exists', // addCartItem في commerce-api + emitCartChanged → شارة الترويسة
    payload: [{ key: 'productId', label: 'المنتج', kind: 'productId', required: true }],
  },
  {
    id: 'buy_now',
    label: 'اشترِ الآن',
    status: 'exists', // مسار useAddToCart حرفيًّا + الانتقال إلى الدفع
    payload: [{ key: 'productId', label: 'المنتج', kind: 'productId', required: true }],
  },
  {
    id: 'apply_coupon',
    label: 'تطبيق قسيمة',
    status: 'exists', // applyCoupon + emitCartChanged؛ رسالة الخادم تُعرض حرفيًّا
    payload: [{ key: 'code', label: 'رمز القسيمة — يظهر في الصفحة للعامة', kind: 'code', required: true, max: 64 }],
  },
  {
    id: 'open_url',
    label: 'فتح رابط',
    status: 'exists', // نحو مطهّر PromoBanner: نسبي / أو https? أو mailto أو tel
    payload: [
      { key: 'url', label: 'الرابط', kind: 'url', required: true },
      { key: 'newTab', label: 'فتح في تبويب جديد', kind: 'toggle', required: false },
    ],
  },
  {
    id: 'open_product',
    label: 'فتح منتج',
    status: 'exists', // routes.product(slug)
    payload: [{ key: 'slug', label: 'معرّف المنتج (slug)', kind: 'slug', required: true, max: 160 }],
  },
  {
    id: 'open_search',
    label: 'فتح البحث',
    status: 'exists', // routes.search — صفحة قائمة؛ دعم معامل الاستعلام غير مؤكَّد فلا يُزيَّف
    payload: [],
  },
  {
    id: 'scroll_to',
    label: 'الانتقال إلى قسم',
    status: 'exists', // scrollIntoView مع حارس سلوك تقليل الحركة
    payload: [{ key: 'targetNodeId', label: 'القسم الهدف', kind: 'nodeId', required: true }],
  },
  {
    id: 'copy_text',
    label: 'نسخ نص',
    status: 'exists', // الحافظة + toast
    payload: [{ key: 'text', label: 'النص', kind: 'text', required: true, max: 500, bindable: true }],
  },
  {
    id: 'share',
    label: 'مشاركة',
    status: 'exists', // navigator.share بكشف الميزة، وإلا نسخ للحافظة بإشعار صادق
    payload: [{ key: 'title', label: 'عنوان المشاركة', kind: 'text', required: false, max: 160 }],
  },
  {
    id: 'open_modal',
    label: 'فتح منبثقة',
    status: 'exists', // C35: حدث window «tah:popup» بـ{nodeId: popupId, open:true}
    payload: [{ key: 'popupId', label: 'المنبثقة', kind: 'popupId', required: true }],
  },
  {
    id: 'close_modal',
    label: 'إغلاق منبثقة',
    status: 'exists', // الحدث نفسه بـopen:false — واجهة داخلية واحدة لا مسار ثانٍ
    payload: [{ key: 'popupId', label: 'المنبثقة', kind: 'popupId', required: true }],
  },
  // ── مؤجَّل — يُعرض معطّلًا مع سببه المكتوب (§6) ──
  {
    id: 'add_to_wishlist',
    label: 'أضِف إلى المفضّلة',
    status: { deferred: 'لا واجهة مفضّلة في المتجر بعد — يصبح الزر كذبًا' },
    payload: [],
  },
  {
    id: 'toggle_compare',
    label: 'أضِف إلى المقارنة',
    status: { deferred: 'لا نظام مقارنة في المتجر بعد' },
    payload: [],
  },
  {
    id: 'login',
    label: 'تسجيل الدخول/الخروج',
    status: { deferred: 'تسجيل الدخول رابط داخلي — استعمل «فتح رابط»؛ ولا واجهة خروج من صفحات الاستوديو' },
    payload: [],
  },
  {
    id: 'download_file',
    label: 'تنزيل ملف رقمي',
    status: { deferred: 'ملفات المنتجات مدفوعة ومحمية برموز الطلب — زرّ تنزيل عام إمّا تسريب وإمّا زرّ ميت' },
    payload: [],
  },
  {
    id: 'open_collection',
    label: 'فتح تصنيف',
    status: { deferred: 'لا لاقط تصنيفات بعد — «فتح رابط» بمسار داخلي يغطي الحاجة' },
    payload: [],
  },
] as const satisfies readonly ActionCatalogRow[];

/** معرّفات الإجراءات المشحونة — مشتقّة من الكتالوج لا مكتوبة (حارس الانحراف). */
export type ActionId = Extract<(typeof ACTION_CATALOG)[number], { readonly status: 'exists' }>['id'];
export const ACTIONS = ACTION_CATALOG.filter((row) => row.status === 'exists').map((row) => row.id) as readonly ActionId[];

/** مفاتيح حمولة كل إجراء — المرآة في PHP: ACTION_PAYLOAD_KEYS. */
export const ACTION_PAYLOAD_KEYS = Object.fromEntries(
  ACTION_CATALOG.filter((row) => row.status === 'exists').map((row) => [row.id, row.payload.map((f) => f.key)]),
) as unknown as Record<ActionId, readonly string[]>;

/**
 * تفاعل عقدة — id صفّي ثابت لمسارات preflight والتراجع؛ الحمولة قد تكون
 * ناقصة أثناء الصياغة (تُحفظ ولا تُنفَّذ — ACTION_INCOMPLETE يحمل الصدق).
 */
export interface NodeInteraction {
  id: string;
  event: ActionEvent;
  action: ActionId;
  payload?: Record<string, unknown>;
}

/* ───── الربط النصّي ───── */

/** نحو الربط كامل السلسلة — لا ربط جزئيًّا داخل نص. */
export const BINDING_PATTERN = /^\{\{[a-z]+(\.[a-z]+)*\}\}$/;

/** السياق الوحيد الذي تملكه صفحة استوديو مستقلّة فعلًا — ‎{{product.*}}‎ غائب لا مزيَّف. */
export const BINDING_PATHS = ['page.key', 'page.name', 'page.url'] as const;
export type BindingPath = (typeof BINDING_PATHS)[number];

export function isBinding(value: unknown): value is string {
  return typeof value === 'string' && BINDING_PATTERN.test(value);
}

/**
 * حلّ ربطٍ نقي: نص حرفي يعود كما هو؛ ربط بمسار معلوم يعود بقيمته؛ ربط
 * بمسار مجهول ⇒ null (يُحفظ غير قابل للتنفيذ — preflight يحذّر).
 */
export function resolveBindings(value: string, ctx: Readonly<Partial<Record<BindingPath, string>>>): string | null {
  if (!BINDING_PATTERN.test(value)) return value;
  const path = value.slice(2, -2);
  if (!(BINDING_PATHS as readonly string[]).includes(path)) return null;
  return ctx[path as BindingPath] ?? null;
}

/* ───── نحو الحمولات ───── */

/**
 * نحو مطهّر PromoBanner للروابط الحرّة: مخطط صريح ⇒ https?/mailto/tel
 * فقط (يقتل ‎javascript:‎ و‎data:‎ بنيويًّا)؛ وإلا مسار نسبي يبدأ بـ'/'
 * — و'//' يُرفض (نسبي البروتوكول = قفزة أصل). null = غير صالح.
 */
export function safeActionUrl(raw: string): string | null {
  const url = raw.trim();
  if (url === '') return null;
  if (/^[a-z][a-z0-9+.\-]*:/i.test(url)) {
    return /^(https?:|mailto:|tel:)/i.test(url) ? url : null;
  }
  if (url.startsWith('//')) return null;
  return url.startsWith('/') ? url : null;
}

/** صلاحية قيمة حقل حمولة واحدة — يعاد فحصها عند التنفيذ أيضًا (دفاع متعمّق). */
export function isValidPayloadValue(field: ActionPayloadField, value: unknown): boolean {
  switch (field.kind) {
    case 'productId':
      return typeof value === 'number' && Number.isInteger(value) && value > 0;
    case 'url':
      return typeof value === 'string' && safeActionUrl(value) !== null;
    case 'code':
      return (
        typeof value === 'string' &&
        value.length >= 1 &&
        value.length <= (field.max ?? 64) &&
        !/[\u0000-\u001f\u007f]/.test(value)
      );
    case 'slug':
      return (
        typeof value === 'string' && value.length >= 1 && value.length <= (field.max ?? 160) && !/[:/]/.test(value)
      );
    case 'text': {
      if (typeof value !== 'string' || value.length === 0 || value.length > (field.max ?? 500)) return false;
      if (field.bindable === true && BINDING_PATTERN.test(value)) {
        return (BINDING_PATHS as readonly string[]).includes(value.slice(2, -2));
      }
      return true;
    }
    case 'nodeId':
    case 'popupId':
      return typeof value === 'string' && STUDIO_ID_PATTERN.test(value);
    case 'toggle':
      return typeof value === 'boolean';
  }
}

/**
 * المسند الواحد الذي يسوّر سماتِ الإتاحة والتنفيذَ معًا: حدث مشحون +
 * إجراء status:'exists' + كل الحقول المطلوبة حاضرة صالحة (الفارغ = غائب).
 * وجود الهدف داخل المستند (scroll_to/open_modal) شأن preflight — التنفيذ
 * على هدف غائب لا-عملية صامتة.
 */
export function isExecutableInteraction(interaction: NodeInteraction): boolean {
  if (!(EVENTS as readonly string[]).includes(interaction.event)) return false;
  const row = ACTION_CATALOG.find((r) => r.id === interaction.action);
  if (row === undefined || row.status !== 'exists') return false;
  const payload = interaction.payload ?? {};
  for (const field of row.payload) {
    const value = payload[field.key];
    if (value === undefined || value === '') {
      if (field.required) return false;
      continue;
    }
    if (!isValidPayloadValue(field, value)) return false;
  }
  return true;
}

/**
 * خريطة العقدة → تفاعلاتها القابلة للتنفيذ — يبنيها action-runtime مرّةً
 * ويشتق منها DocumentRenderer سمة data-studio-actions (المصدر الواحد
 * للواجهتين). تُمرَّر المستند **المتمدّد** المقصوص.
 */
export function executableInteractionsOf(doc: Pick<StudioDocument, 'nodes'>): Record<string, NodeInteraction[]> {
  const out: Record<string, NodeInteraction[]> = {};
  for (const { node } of walkNodes(doc)) {
    if (isSymbolRef(node)) continue; // المرجع لا يحمل تفاعلات بنيويًّا (C38)
    const executable = (node.interactions ?? []).filter(isExecutableInteraction);
    if (executable.length > 0) out[node.id] = executable;
  }
  return out;
}

/**
 * مسند التداخل التفاعلي الساكن (§2.4): يمنع role="button"/tabIndex على
 * غلافٍ يضم عناصر تفاعلية حقيقية (مخالفة WCAG المتداخلة) — المجموعة
 * بطاقاتها روابط، وpromo مع CTA يرسم رابطًا، والحاويات تُفحص تكراريًّا.
 * مرجع رمز غير متمدّد ⇒ true تحفّظًا (يمنع الدور لا يمنحه).
 */
export function nodeHasInteractiveDescendants(node: StudioNode): boolean {
  if (isSymbolRef(node)) return true;
  const component = componentOf(node.registryId);
  if (component === 'collection') return true;
  if (component === 'promo') {
    const props = node.props as { ctaLabel?: unknown; ctaHref?: unknown };
    return (
      typeof props.ctaLabel === 'string' &&
      props.ctaLabel.trim() !== '' &&
      typeof props.ctaHref === 'string' &&
      props.ctaHref.trim() !== ''
    );
  }
  return (node.children ?? []).some(nodeHasInteractiveDescendants);
}
