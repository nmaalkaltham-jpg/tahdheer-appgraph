// UNIT: CONDITIONS
// FILE: conditions.ts
// STYLE: none
// PARENT: BRIDGE, DOCUMENT, REGISTRY-INDEX
// USES: DOCUMENT
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * شروط الظهور — نحو «و» فقط بحدّ ٥ قواعد (STUDIO-PHASE3-CONTRACT §2.6،
 * قرار C34). المصادر المشحونة هي ما يُحسم على الخادم وقت الطلب حصرًا:
 * جدولة (لحظات UTC) · السلة (قيمة/عدد/محتوى/قسيمة) · UTM (رابط الصفحة
 * الحالي). الجهاز شرطٌ قائم فعلًا عبر node.hidden (تبويب التجاوب — تسوية
 * C11) فلا آلية ثانية هنا.
 *
 * المقيّم الواحد `evaluateConditions` تستدعيه الواجهتان: مسار الزائر يبني
 * السياق على الخادم **ويقصّ** العقد الساقطة قبل التصيير (صفر بايت مسوّر
 * يصل الزائر)، والكانفس يقيّم سياق PREVIEW_CONTEXT المحاكى **ويعلّم** بدل
 * القص. cart:null = تعذّر الجلب ⇒ قواعد السلة كلها false (مغلقٌ عند
 * الفشل — يُصرَّح به في المحرّر).
 *
 * نقي: بلا DOM وبلا قراءة ساعة — ctx.now يُحقن (اختبارات حتمية). نوع قاعدة
 * مجهول ⇒ false.
 */
import { isSymbolRef, walkNodes, type StudioDocument } from './document.ts';

export const COND_KINDS = ['schedule', 'cart', 'cartCount', 'cartHas', 'coupon', 'utm'] as const;
export type CondKind = (typeof COND_KINDS)[number];

export const UTM_PARAMS = ['source', 'medium', 'campaign'] as const;
export type UtmParam = (typeof UTM_PARAMS)[number];

/** نحو قيمة UTM — المرآة في PHP (normalizeCond). */
export const UTM_VALUE_PATTERN = /^[\w.\-]{1,64}$/;

/** حدّ منتجات قاعدة cartHas — أيٌّ منها يكفي (any-of). */
export const MAX_CART_HAS_PRODUCTS = 20;

export type NodeCondition =
  /** لحظتا UTC بصيغة ISO-8601؛ from شاملة وuntil حصرية. */
  | { t: 'schedule'; from?: string; until?: string }
  /** إجمالي السلة بالهللات (totals.totalMinor). */
  | { t: 'cart'; min?: number; max?: number }
  | { t: 'cartCount'; min?: number; max?: number }
  | { t: 'cartHas'; productIds: number[] }
  | { t: 'coupon'; state: 'any' | 'none' }
  | { t: 'utm'; param: UtmParam; value: string };

/** «و» فقط — تُعرض العقدة عند تحقق كل القواعد معًا (≤ MAX_CONDITIONS). */
export interface NodeConditions {
  all: NodeCondition[];
}

/** لقطة السلة كما يبنيها مسار الزائر (جلبٌ بالكوكي عند docNeedsCart فقط). */
export interface ConditionCart {
  /** الإجمالي بالهللات. */
  totalMinor: number;
  /** عدد القطع. */
  count: number;
  productIds: number[];
  hasCoupon: boolean;
}

export interface ConditionContext {
  /** لحظة التقييم (epoch ms بتوقيت UTC) — تُحقن ولا تُقرأ من ساعة. */
  now: number;
  /** معاملات UTM من رابط الصفحة الحالي حصرًا. */
  utm: Partial<Record<UtmParam, string>>;
  /** null = تعذّر الجلب ⇒ كل قواعد السلة false (مغلق عند الفشل). */
  cart: ConditionCart | null;
}

/**
 * لحظة التقييم من ساعة النظام — نقطةُ الحقن الوحيدة.
 *
 * العقد أعلاه يقول «تُحقن ولا تُقرأ من ساعة»، وكان الموضعان الوحيدان
 * اللذان يبنيان السياق يقرآن `Date.now()` حرفيًّا في مكانهما: صفحة الزائر
 * ‎/pages/[slug]‎ ومصيّر الكانفس. فكانت نقطة الحقن مكتوبةً في التعليق
 * وغير موجودة في الشيفرة — ولا موضع واحد يمكن تثبيته في اختبار.
 */
export function conditionNow(): number {
  return Date.now();
}

const CART_KINDS: ReadonlySet<string> = new Set(['cart', 'cartCount', 'cartHas', 'coupon']);

/** هل يحتاج المستند جلب السلة؟ يُمرَّر المستند **المتمدّد** (الأجسام قد تحمل شروط سلة). */
export function docNeedsCart(doc: Pick<StudioDocument, 'nodes'>): boolean {
  for (const { node } of walkNodes(doc)) {
    if (isSymbolRef(node)) continue; // المرجع لا يحمل cond بنيويًّا (C38)
    for (const rule of node.cond?.all ?? []) {
      if (CART_KINDS.has(rule.t)) return true;
    }
  }
  return false;
}

function within(value: number, min: number | undefined, max: number | undefined): boolean {
  if (min !== undefined && !(value >= min)) return false;
  if (max !== undefined && !(value <= max)) return false;
  return true;
}

function evaluateOne(rule: NodeCondition, ctx: ConditionContext): boolean {
  switch (rule.t) {
    case 'schedule': {
      // تاريخ مشوّه ⇒ NaN ⇒ المقارنة false ⇒ القاعدة تسقط (مغلق عند الفشل).
      if (rule.from !== undefined && !(ctx.now >= Date.parse(rule.from))) return false;
      if (rule.until !== undefined && !(ctx.now < Date.parse(rule.until))) return false;
      return true;
    }
    case 'cart':
      return ctx.cart !== null && within(ctx.cart.totalMinor, rule.min, rule.max);
    case 'cartCount':
      return ctx.cart !== null && within(ctx.cart.count, rule.min, rule.max);
    case 'cartHas': {
      const cart = ctx.cart;
      return cart !== null && rule.productIds.some((id) => cart.productIds.includes(id));
    }
    case 'coupon':
      if (ctx.cart === null) return false;
      return rule.state === 'any' ? ctx.cart.hasCoupon : !ctx.cart.hasCoupon;
    case 'utm':
      return ctx.utm[rule.param] === rule.value;
    default:
      return false; // نوع مجهول ⇒ إخفاء — لا عرض بقاعدة لا نفهمها
  }
}

/**
 * المقيّم الواحد للواجهتين — غياب الشروط أو فراغها = ظهور دائم؛ وإلا
 * تحقُّق **كل** القواعد معًا («و» فقط).
 */
export function evaluateConditions(cond: NodeConditions | undefined, ctx: ConditionContext): boolean {
  if (cond === undefined) return true;
  return cond.all.every((rule) => evaluateOne(rule, ctx));
}
