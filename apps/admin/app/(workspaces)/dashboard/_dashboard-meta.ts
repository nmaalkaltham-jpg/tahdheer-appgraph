// UNIT: _DASHBOARD-META
// FILE: _dashboard-meta.ts
// STYLE: none
// PARENT: PAGE-DASHBOARD
// USES: COMMAND-CENTER, COMPONENTS-ICONS, LIB-FORMAT
// API: none
// MAP: docs/PROJECT_MAP.md

import { count, money } from '../../../lib/format';
import type { IconName } from '../../../components/icons';
import type { SeriesPoint, Tone } from './command-center';

export interface Metric {
  key: string;
  label: string;
  format: 'money' | 'count';
  value: number;
  previous: number;
  changePercent: number | null;
  formula: string;
  /** وجهة التحقيق في الرقم — تُبنى في الخادم. غيابها يعني «لا قائمة تقابله». */
  href?: string | null;
}

/**
 * ما تعرفه الواجهة عن كلّ عدّادٍ تشغيليّ: أثره، وجدوله، وما يُنصح به.
 *
 * الأثر ترتيبٌ لا رقم: «يمسّ عميلًا» يسبق «إيرادًا» يسبق «منصّةً» مهما كان
 * العدد — عطلٌ واحد يحرم عميلًا ممّا دفع ثمنه أخطر من عشرين تنبيهًا للمخزون.
 * والتوصية إرشادٌ تشغيليّ ثابت لا استنتاجٌ من بيانات.
 */
export const IMPACT_RANK = { customer: 0, revenue: 1, platform: 2, stock: 3, watch: 4 } as const;
export type Impact = keyof typeof IMPACT_RANK;
export const IMPACT_LABEL: Record<Impact, string> = {
  customer: 'عميل',
  revenue: 'إيراد',
  platform: 'منصّة',
  stock: 'مخزون',
  watch: 'متابعة',
};

export const COUNTER_META: Record<string, { impact: Impact; source: string; why: string; recommendation: string; primary: string }> = {
  undeliveredOrders: {
    impact: 'customer',
    source: 'entitlements',
    why: 'العميل دفع ولم يستلم — لا صفّ في entitlements.',
    recommendation: 'أعد إنشاء الصلاحيّة للطلبات ثمّ أرسل بريد التسليم.',
    primary: 'أعد التسليم',
  },
  unfulfilledOrders: {
    impact: 'customer',
    source: 'fulfillment_units',
    why: 'طلبٌ مدفوع بلا وحدة تنفيذٍ — لم يبدأ التسليم أصلًا.',
    recommendation: 'افتح الطلبات وأنشئ وحدات التنفيذ من صفحة كلّ طلب.',
    primary: 'افتح الطلبات',
  },
  failedFulfillment: {
    impact: 'customer',
    source: 'fulfillment_units',
    why: 'وحدةُ تنفيذٍ توقّفت بخطأ — العميل ينتظر ملفًّا لم يصل.',
    recommendation: 'أعد المحاولة معًا؛ إن فشل الأصل ذاته افتح مصدر الأصول.',
    primary: 'أعد المحاولة',
  },
  breachedCases: {
    impact: 'customer',
    source: 'cases',
    why: 'وعدُ ردٍّ انتهى والعميل ينتظر.',
    recommendation: 'عيّن مسؤولًا الآن؛ الردّ الأوّل يوقف عدّاد الخرق.',
    primary: 'افتح القضايا',
  },
  failedPayments: {
    impact: 'revenue',
    source: 'webhook_deliveries',
    why: 'حدثُ دفعٍ وصل ولم تُعالَج حمولته — مالٌ وصل ولا طلبَ يعلم.',
    recommendation: 'أعد الحدث من صفحة الخطّافات بعد قراءة سبب الفشل.',
    primary: 'أعد الحدث',
  },
  unmatchedWebhooks: {
    impact: 'revenue',
    source: 'webhook_deliveries',
    why: 'مال وصل ولا يُعرف لمن — لا طلبٌ يطابق مرجع البوّابة.',
    recommendation: 'ابحث عن الطلب بمرجع البوّابة واربطه يدويًّا.',
    primary: 'ابحث عن طلبه',
  },
  pendingRiskReviews: {
    impact: 'revenue',
    source: 'risk_assessments',
    why: 'المحرّك أحال الطلب إلى مراجعةٍ يدويّة ولم يُبتّ فيه.',
    recommendation: 'راجع القرار؛ الطلب معلّقٌ حتّى يُحسم.',
    primary: 'راجع',
  },
  uncertainOperations: {
    impact: 'platform',
    source: 'operation_records',
    why: 'أثرٌ خارجيّ لا يُعرف أوقع أم لا — لا يُعاد قبل سؤال المزوّد.',
    recommendation: 'دع «operations:recover» يسأل؛ لا تُعِد يدويًّا.',
    primary: 'افتح النظام',
  },
  queueFailures: {
    impact: 'platform',
    source: 'failed_jobs',
    why: 'مهمّةٌ في الطابور رُميت بعد استنفاد محاولاتها.',
    recommendation: 'اقرأ الاستثناء ثمّ أعد التشغيل من شاشة النظام.',
    primary: 'أعد التشغيل',
  },
  outOfStock: {
    impact: 'stock',
    source: 'stock_levels',
    why: 'متغيّرٌ مادّيّ نفد — يُباع ولا يُشحن.',
    recommendation: 'أضف حركة مخزونٍ أو أوقف البيع للمتغيّر.',
    primary: 'أضف حركة',
  },
  lowStock: {
    impact: 'stock',
    source: 'stock_levels',
    why: 'المتاح وصل نقطة إعادة الطلب.',
    recommendation: 'اطلب من المورّد قبل النفاد.',
    primary: 'افتح المخزون',
  },
  openCases: {
    impact: 'watch',
    source: 'cases',
    why: 'قضايا مفتوحة لم تُغلق بعد.',
    recommendation: 'افتح الطابور مرتَّبًا بالأقدم.',
    primary: 'افتح الطابور',
  },
  ordersNeedingAction: {
    impact: 'watch',
    source: 'orders',
    why: 'بانتظار الدفع أو قيد المعالجة.',
    recommendation: 'افتح القائمة مرشَّحةً بالحالة.',
    primary: 'افتح القائمة',
  },
  pendingFulfillment: {
    impact: 'watch',
    source: 'fulfillment_units',
    why: 'وحداتٌ جاهزة أو معلّقة ينفّذها المنسّق آليًّا.',
    recommendation: 'لا تدخّل ما لم تتراكم.',
    primary: 'افتح الطابور',
  },
  brokenLinks: {
    impact: 'watch',
    source: 'not_found_hits',
    why: 'عناوين طُلبت مرّتين فأكثر وردّت ٤٠٤.',
    recommendation: 'أضف تحويلًا أو أعد نشر الصفحة.',
    primary: 'افتح الظهور',
  },
};

export const IMPACT_TONE: Record<Impact, Tone> = { customer: 'red', revenue: 'orange', platform: 'violet', stock: 'amber', watch: 'teal' };
/** تحيّةٌ بحسب ساعة اليوم — كما كانت في لوحة التشغيل الأولى. */
export function greeting(): string {
  const hour = new Date().getHours();
  if (hour < 12) return 'صباح الخير';
  if (hour < 17) return 'طاب يومك';
  return 'مساء الخير';
}

export const IMPACT_SEVERITY: Record<Impact, string> = { customer: 'حرج', revenue: 'تحذير', platform: 'تحذير', stock: 'تحذير', watch: 'متابعة' };

/** ما تعرفه الواجهة عن كلّ مؤشّر: نبرته وأيقونته وما يرسمه — والقيم من الخادم. */
export const KPI_META: Record<string, { tone: Tone; icon: IconName; goodUp: boolean; unit: string; drill: string; span: 2 | 3; spark?: (p: SeriesPoint) => number }> = {
  orders: { tone: 'blue', icon: 'cart', goodUp: true, unit: 'طلب', drill: 'الطلبات المدفوعة', span: 2, spark: (p) => p.paidOrders },
  aov: { tone: 'violet', icon: 'gauge', goodUp: true, unit: 'ر.س', drill: 'الطلبات المدفوعة', span: 2, spark: (p) => (p.paidOrders === 0 ? 0 : Math.round(p.grossMinor / p.paidOrders)) },
  refunded: { tone: 'amber', icon: 'rotate', goodUp: false, unit: 'ر.س', drill: 'المستردّ', span: 2 },
  netSales: { tone: 'emerald', icon: 'pulse', goodUp: true, unit: 'ر.س', drill: 'الطلبات المدفوعة', span: 3, spark: (p) => p.grossMinor },
  allOrders: { tone: 'cyan', icon: 'list', goodUp: true, unit: 'طلب', drill: 'كلّ الطلبات', span: 3, spark: (p) => p.orders },
  needsAction: { tone: 'orange', icon: 'layers', goodUp: false, unit: 'طلب', drill: 'تحتاج معالجة', span: 3 },
  newCustomers: { tone: 'purple', icon: 'users', goodUp: true, unit: 'عميل', drill: 'العملاء', span: 3 },
};

export const PRODUCT_TONES: Tone[] = ['emerald', 'blue', 'lime', 'amber', 'rose'];

export type View = 'ops' | 'wall';
export const VIEWS: { id: View; label: string }[] = [
  { id: 'ops', label: 'اللوحة' },
  { id: 'wall', label: 'شاشة العرض' },
];

export function clock(iso: string): string {
  return new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

export function shown(metric: Metric, value: number): string {
  return metric.format === 'money' ? money(value) : count(value);
}

/**
 * صفٌّ من سجلّ النطاقات كما يبثّه `GET /admin/insights`.
 *
 * ─── لماذا لم تعد مكتوبةً هنا ───
 *
 * كانت قائمةً ثابتة تحمل تعليقًا يقول «النطاقات كما يسجّلها الخادم» — وهي
 * نسخةٌ لا مرآة. وقد تباعدت فعلًا: الخادم يسجّل ثمانية وكانت هنا سبعة
 * (سقط «إيراد الصفحات»)، ووصفُ «الاكتساب» صار جملتين مختلفتين. والنقطة
 * التي تبثّ السجلّ (`adminInsightsIndex`) كانت بلا مستهلكٍ واحد — مبنيّةٌ
 * لهذا الغرض بالذات ولا تُنادى.
 *
 * فالنطاق الجديد يظهر في اللوحة يوم يُسجَّل، ولا تُترجَم تسميتُه مرّتين.
 */
export interface InsightDomain {
  key: string;
  label: string;
  description: string;
}
