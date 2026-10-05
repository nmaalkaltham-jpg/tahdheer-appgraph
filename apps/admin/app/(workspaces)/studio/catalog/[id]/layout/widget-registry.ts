// UNIT: WIDGET-REGISTRY
// FILE: widget-registry.ts
// STYLE: none
// PARENT: COMPOSER-CLIENT
// USES: LIB-FORMAT, STUDIO-DATA
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * سجلّ ودجات صفحة المنتج — خمسون ودجة بمجموعاتها وملاءمتها لنوع المنتج،
 * منقولة من «Tahdheer - Product Builder».
 *
 * الفارق الجوهري عن اللوحة: اللوحة تملأ كل ودجة ببيانات عرض (سعر، تقييم،
 * مراجعات، إحصاءات). هنا لا تُرسم قيمة واحدة لا مصدر لها. كل ودجة تُربط
 * بحمولة /admin/products/{id} فتعيد إحدى أربع حالات:
 *
 *   data      — بيانات حقيقية من المنتج المعروض.
 *   empty     — الحقل موجود في النموذج وفارغ لهذا المنتج تحديدًا.
 *   behaviour — سلوك واجهة لا يستهلك بيانات منتج (شريط لاصق، مشاركة…).
 *   unwired   — لا مصدر أصلًا؛ يُسمّى الناقص بالاسم.
 *
 * هكذا يقول المنشئ للمشغّل أي ودجة تستطيع فعلًا أن تعرض شيئًا لهذا المنتج.
 */

import { money, PRODUCT_STATUS_LABELS } from '../../../../../../lib/format';
import { FACT_VOCABULARY, type StudioProduct } from '../../studio-data';

export type WidgetGroup = 'media' | 'buy' | 'digital' | 'physical' | 'info' | 'recs' | 'trust' | 'layout';

/** d رقمي · p مادي · h هجين — نفس ترميز اللوحة. */
export type ProductKind = 'd' | 'p' | 'h';

export interface WidgetDef {
  id: number;
  name: string;
  group: WidgetGroup;
  types: readonly ProductKind[];
}

export interface GroupDef {
  key: WidgetGroup;
  name: string;
  color: string;
}

export type Binding =
  | { state: 'data'; rows: { label: string; value: string }[] }
  | { state: 'empty'; missing: string; endpoint?: string }
  | { state: 'behaviour'; note: string }
  /** `missing` نصّ عربي، و`endpoint` اسم النقطة الناقصة يُعرض في سطر LTR مستقل. */
  | { state: 'unwired'; missing: string; endpoint?: string };

/**
 * المجموعات الثماني بعائلات ألوان اللوحة نفسها (بنفسجيّ · أخضر · أزرق · برتقاليّ ·
 * تركوازيّ · ذهبيّ · أحمر · رماديّ)، لكن من أحبار السمة المشتقّة لا حرفيّاتٍ ثابتة:
 * الدرجات الداكنة الأصل كانت نقاطًا شبه غائبة على السطح الداكن.
 */
export const GROUPS: readonly GroupDef[] = [
  { key: 'media', name: 'الوسائط', color: 'var(--cc-violet-text)' },
  { key: 'buy', name: 'الشراء', color: 'var(--cc-emerald-text)' },
  { key: 'digital', name: 'رقمي', color: 'var(--cc-blue-text)' },
  { key: 'physical', name: 'مادي', color: 'var(--cc-orange-text)' },
  { key: 'info', name: 'المعلومات', color: 'var(--cc-teal)' },
  { key: 'recs', name: 'التوصيات', color: 'var(--gold-text)' },
  { key: 'trust', name: 'الثقة', color: 'var(--cc-red-text)' },
  { key: 'layout', name: 'التخطيط', color: 'var(--cc-muted)' },
];

/** السجلّ: الترقيم والأسماء والمجموعات والملاءمة كما في اللوحة حرفيًا. */
export const WIDGETS: readonly WidgetDef[] = [
  { id: 1, name: 'معرض الوسائط', group: 'media', types: ['d', 'p', 'h'] },
  { id: 2, name: 'معاينة المنتج الرقمي', group: 'media', types: ['d', 'h'] },
  { id: 3, name: 'معاينة فيديو', group: 'media', types: ['d', 'p', 'h'] },
  { id: 4, name: 'عرض تفاعلي', group: 'media', types: ['d', 'p', 'h'] },
  { id: 5, name: 'بطاقة الشراء', group: 'buy', types: ['d', 'p', 'h'] },
  { id: 6, name: 'شريط لاصق (سطح المكتب)', group: 'buy', types: ['d', 'p', 'h'] },
  { id: 7, name: 'شريط شراء الجوال', group: 'buy', types: ['d', 'p', 'h'] },
  { id: 8, name: 'السعر والتوفير', group: 'buy', types: ['d', 'p', 'h'] },
  { id: 9, name: 'اختيار الترخيص', group: 'buy', types: ['d', 'h'] },
  { id: 10, name: 'اختيار المتغيرات', group: 'buy', types: ['p', 'h'] },
  { id: 11, name: 'محدد الكمية', group: 'buy', types: ['p', 'h'] },
  { id: 12, name: 'صيغ الملفات', group: 'digital', types: ['d', 'h'] },
  { id: 13, name: 'التوافق البرمجي', group: 'digital', types: ['d', 'h'] },
  { id: 14, name: 'متطلبات التشغيل', group: 'digital', types: ['d', 'h'] },
  { id: 15, name: 'التنزيل الفوري', group: 'digital', types: ['d', 'h'] },
  { id: 16, name: 'الشحن والتوصيل', group: 'physical', types: ['p', 'h'] },
  { id: 17, name: 'حالة التوفر', group: 'buy', types: ['d', 'p', 'h'] },
  { id: 18, name: 'ماذا تتضمن', group: 'info', types: ['d', 'p', 'h'] },
  { id: 19, name: 'شبكة المميزات', group: 'info', types: ['d', 'p', 'h'] },
  { id: 20, name: 'الفوائد الرئيسية', group: 'info', types: ['d', 'p', 'h'] },
  { id: 21, name: 'حالات الاستخدام', group: 'info', types: ['d', 'p', 'h'] },
  { id: 22, name: 'لمن هذا المنتج؟', group: 'info', types: ['d', 'p', 'h'] },
  { id: 23, name: 'ليس مناسبًا لـ', group: 'info', types: ['d', 'p', 'h'] },
  { id: 24, name: 'قبل / بعد', group: 'info', types: ['d', 'h'] },
  { id: 25, name: 'عينة مجانية', group: 'digital', types: ['d', 'h'] },
  { id: 26, name: 'المواصفات', group: 'info', types: ['d', 'p', 'h'] },
  { id: 27, name: 'الأبعاد والخامات', group: 'physical', types: ['p', 'h'] },
  { id: 28, name: 'معلومات الإصدار', group: 'digital', types: ['d', 'h'] },
  { id: 29, name: 'سجل التحديثات', group: 'digital', types: ['d', 'h'] },
  { id: 30, name: 'خارطة الطريق', group: 'digital', types: ['d', 'h'] },
  { id: 31, name: 'ملخص الترخيص', group: 'digital', types: ['d', 'h'] },
  { id: 32, name: 'جدول المقارنة', group: 'recs', types: ['d', 'p', 'h'] },
  { id: 33, name: 'منشئ الحزم', group: 'recs', types: ['d', 'p', 'h'] },
  { id: 34, name: 'يُشترى معًا', group: 'recs', types: ['d', 'p', 'h'] },
  { id: 35, name: 'منتجات ذات صلة', group: 'recs', types: ['d', 'p', 'h'] },
  { id: 36, name: 'بدائل مقترحة', group: 'recs', types: ['d', 'p', 'h'] },
  { id: 37, name: 'شوهدت مؤخرًا', group: 'recs', types: ['d', 'p', 'h'] },
  { id: 38, name: 'ملخص التقييمات', group: 'trust', types: ['d', 'p', 'h'] },
  { id: 39, name: 'التقييمات المتقدمة', group: 'trust', types: ['d', 'p', 'h'] },
  { id: 40, name: 'شهادات مميزة', group: 'trust', types: ['d', 'p', 'h'] },
  { id: 41, name: 'أسئلة وأجوبة', group: 'trust', types: ['d', 'p', 'h'] },
  { id: 42, name: 'أسئلة شائعة', group: 'trust', types: ['d', 'p', 'h'] },
  { id: 43, name: 'الثقة والأمان', group: 'trust', types: ['d', 'p', 'h'] },
  { id: 44, name: 'الضمان والاسترجاع', group: 'trust', types: ['d', 'p', 'h'] },
  { id: 45, name: 'ملف المُعِدّ', group: 'trust', types: ['d', 'p', 'h'] },
  { id: 46, name: 'الدعم بعد الشراء', group: 'trust', types: ['d', 'p', 'h'] },
  { id: 47, name: 'إثبات اجتماعي', group: 'trust', types: ['d', 'p', 'h'] },
  { id: 48, name: 'مشاركة وحفظ', group: 'buy', types: ['d', 'p', 'h'] },
  { id: 49, name: 'تنقل الصفحة الذكي', group: 'layout', types: ['d', 'p', 'h'] },
  { id: 50, name: 'CTA ختامي ذكي', group: 'layout', types: ['d', 'p', 'h'] },
];

export interface PresetDef {
  key: string;
  name: string;
  type: ProductKind;
  widgets: readonly number[];
}

/** القوالب الستة بترتيب ودجاتها كما في اللوحة. */
export const PRESETS: readonly PresetDef[] = [
  { key: 'ppt', name: 'قالب عرض — مصغّر', type: 'd', widgets: [1, 2, 5, 8, 12, 13, 15, 18, 19, 25, 31, 38, 42, 50] },
  {
    key: 'edu',
    name: 'مورد تعليمي غني',
    type: 'd',
    widgets: [
      1, 2, 3, 5, 8, 9, 12, 15, 18, 19, 20, 21, 22, 24, 25, 26, 29, 31, 32, 34, 38, 39, 41, 42, 45, 46, 49, 50,
    ],
  },
  {
    key: 'soft',
    name: 'برمجية / إضافة',
    type: 'd',
    widgets: [1, 3, 5, 8, 9, 13, 14, 15, 18, 19, 26, 28, 29, 30, 31, 32, 38, 39, 41, 42, 46, 49, 50],
  },
  {
    key: 'phys',
    name: 'منتج مادي',
    type: 'p',
    widgets: [1, 5, 8, 10, 11, 16, 17, 18, 19, 26, 27, 32, 34, 35, 38, 39, 42, 43, 44, 50],
  },
  {
    key: 'hybrid',
    name: 'هجين (مطبوع + رقمي)',
    type: 'h',
    widgets: [1, 2, 5, 8, 10, 12, 15, 16, 17, 18, 19, 26, 29, 31, 34, 38, 39, 42, 44, 45, 50],
  },
  { key: 'conv', name: 'تحويل عالٍ', type: 'd', widgets: [1, 2, 5, 8, 17, 19, 20, 24, 34, 38, 40, 42, 43, 47, 50] },
];

export const TYPE_LABELS: Record<ProductKind, string> = {
  d: 'رقمي',
  p: 'مادي',
  h: 'هجين',
};

export function widgetById(id: number): WidgetDef | undefined {
  return WIDGETS.find((widget) => widget.id === id);
}

/** سبب التعطيل يُشتقّ من الأنواع المسجَّلة للودجة، لا من نصّ ثابت. */
export function relevanceReason(widget: WidgetDef): string {
  const names = widget.types.map((type) => TYPE_LABELS[type]);
  return `${names.join(' أو ')} فقط`;
}

/**
 * هل في هذا المنتج جزءٌ يُنزَّل؟
 *
 * يُشتقّ من `kindOf` فلا يبقى فرعٌ ثانٍ يقرأ البتّة وحدها. والهجين رقميٌّ
 * **وماديّ** معًا، فبتّةُ `digital === true` كانت تُخفي عنه نصفه الرقميّ
 * وتقول «غير رقميّ — لا تنزيل فوري».
 */
export function hasDigitalPart(product: StudioProduct): boolean {
  return kindOf(product) !== 'p';
}

/** وهل يُشحن منه شيء؟ */
export function hasPhysicalPart(product: StudioProduct): boolean {
  return kindOf(product) !== 'd';
}

/**
 * نوع المنتج الحقيقيّ — من `productNature` لا من بتّة `digital`.
 *
 * كانت القسمة `digital === false ? 'p' : 'd'`، فالهجين **غير قابل للبلوغ
 * بنيويًّا**: لا قيمة للبتّة تُنتج `'h'`، فتبقى قوالبه وودجاته معطّلةً إلى
 * الأبد بلا سببٍ يُصلَح. والطبيعة تصل الآن من الخادم، فالقسمة تُقرأ منها.
 *
 * والسقوط إلى البتّة يبقى للمنتجات التي سبقت النموذج الكونيّ — ولا مجموعة
 * لها تُقرأ منها طبيعة.
 */
export function kindOf(product: StudioProduct): ProductKind {
  switch (product.productNature) {
    case 'PHYSICAL':
      return 'p';
    case 'HYBRID':
      return 'h';
    case 'DIGITAL':
    case 'ACCESS_BASED':
      return 'd';
    default:
      return product.digital === false ? 'p' : 'd';
  }
}

/* ───── الربط بالبيانات ───── */

const NO_MEDIA = 'صفوف الوسائط لا تصل في حمولة المنتج — العدد وحده مقيس';
const MEDIA_ENDPOINT = 'GET /admin/products/{id}/media';
const NO_FILES = 'أسماء الملفات لا تصل في حمولة المنتج — العدد وحده مقيس';
const FILES_ENDPOINT = 'GET /admin/products/{id}/files';
const NO_CATALOG_QUERY = 'نقطة توصيات/منتجات مرتبطة في نطاق admin';
const NO_REVIEWS = 'تجميعات المراجعات — الواجهة الخلفية تحذف حقول التقييم عمدًا حتى توجد';
const NO_STORE_CONTENT = 'نصوص المتجر (ضمان، دعم، أمان) لا مصدر لها في نطاق admin';
const STORE_ENDPOINT = 'GET /admin/settings/store-content';
const NO_LICENSING = 'نموذج تراخيص/متغيّرات في الكتالوج';
const NO_VERSIONING = 'حقول الإصدار وسجل التحديثات في الكتالوج';
const NO_EDITORIAL = 'حقول تحريرية (مميزات، فوائد، حالات استخدام، قبل/بعد)';
const FAQ_SOURCE = 'لا نقطة أسئلة في نطاق admin؛ الأسئلة تصل للمنتج المنشور وحده';
const FAQ_ENDPOINT = 'GET /api/v1/seo/products/{slug}';

function priceRows(product: StudioProduct): { label: string; value: string }[] {
  const rows = [{ label: 'السعر الأساسي', value: `${money(product.regularMinor)} ر.س` }];
  if (product.onSale === true && product.saleMinor !== null) {
    rows.push({ label: 'سعر العرض', value: `${money(product.saleMinor)} ر.س` });
    if (product.discountPct !== undefined) {
      rows.push({ label: 'نسبة الخصم', value: `${product.discountPct}%` });
    }
  }
  return rows;
}

/**
 * ربط ودجة واحدة بحمولة المنتج. `activeNames` تُمرَّر لودجة التنقّل وحدها
 * لأنها تشتقّ محتواها من التركيب نفسه لا من المنتج.
 */
export function bindWidget(id: number, product: StudioProduct, activeNames: string[]): Binding {
  const hasPrice = (product.regularMinor ?? 0) > 0;
  const classified =
    product.stageId !== null && product.subjectId !== null && product.gradeId !== null;

  switch (id) {
    case 1:
    case 3:
    case 4:
      return product.imageCount > 0
        ? { state: 'unwired', missing: `${NO_MEDIA} (${product.imageCount} صورة).`, endpoint: MEDIA_ENDPOINT }
        : { state: 'empty', missing: 'لا صورة واحدة مرتبطة بهذا المنتج.' };

    case 2:
      return product.fileCount > 0
        ? { state: 'unwired', missing: `${NO_FILES} (${product.fileCount} ملف).`, endpoint: FILES_ENDPOINT }
        : { state: 'empty', missing: 'لا ملف واحد مرتبط بهذا المنتج.' };

    case 5:
      return hasPrice
        ? {
            state: 'data',
            rows: [
              { label: 'العنوان', value: product.title },
              ...priceRows(product),
              { label: 'الرمز', value: product.sku },
            ],
          }
        : { state: 'empty', missing: 'لا سعر أساسي مضبوط — البطاقة بلا زرّ شراء صالح' };

    case 6:
      return { state: 'behaviour', note: 'شريط لاصق يظهر بعد مغادرة بطاقة الشراء — سلوك تخطيط لا بيانات.' };

    case 7:
      return { state: 'behaviour', note: 'شريط سفلي على الجوال: السعر وزرّ الإضافة — سلوك تخطيط لا بيانات.' };

    case 8:
      return hasPrice
        ? { state: 'data', rows: priceRows(product) }
        : { state: 'empty', missing: 'لا سعر أساسي مضبوط' };

    case 9:
    case 10:
      return { state: 'unwired', missing: NO_LICENSING };

    case 11:
      return { state: 'behaviour', note: 'محدد كمية يحترم حدّ المخزون — لا حقل مخزون في الكتالوج بعد.' };

    case 12:
      return product.format !== null && product.format !== undefined && product.format !== ''
        ? { state: 'data', rows: [{ label: 'الصيغة', value: product.format }] }
        : { state: 'empty', missing: 'حقل الصيغة فارغ لهذا المنتج' };

    case 13:
    case 14:
      return { state: 'unwired', missing: 'حقول التوافق ومتطلّبات التشغيل في الكتالوج' };

    case 15:
      return hasDigitalPart(product)
        ? {
            state: 'data',
            rows: [
              {
                label: 'نوع التسليم',
                value: hasPhysicalPart(product)
                  ? 'هجين — تنزيلٌ فوريّ وشحنةٌ معًا'
                  : 'رقمي — الوصول يُفتح بعد الدفع',
              },
              { label: 'الملفات المرتبطة', value: String(product.fileCount) },
            ],
          }
        : { state: 'empty', missing: 'المنتج غير رقمي — لا تنزيل فوري' };

    case 16:
      return { state: 'unwired', missing: 'حقول الشحن في الكتالوج' };

    case 17:
      return {
        state: 'data',
        rows: [
          {
            label: 'التوفّر',
            /*
             * الماديّ يُتتبَّع مخزونه الآن (`inventory_strategy` يتبع الطبيعة)،
             * فقولُ «لا حقل مخزون في الكتالوج» صار بائتًا. والعدد نفسه ليس
             * في حمولة المنتج، فلا يُخترع رقم — تُقال الحالة ويُشار إلى موضعها.
             */
            value: hasPhysicalPart(product)
              ? 'يُتتبَّع مخزونه — العدد في «صيغ الملفّ»'
              : 'رقمي — متاح دائمًا، لا نفاد',
          },
          { label: 'حالة المنتج', value: PRODUCT_STATUS_LABELS[product.status] ?? product.status },
        ],
      };

    case 18:
      return product.fileCount > 0 || product.facts.length > 0
        ? {
            state: 'data',
            rows: [
              { label: 'ملفات مرتبطة', value: String(product.fileCount) },
              ...product.facts.map((fact) => ({
                label: FACT_VOCABULARY[fact.key] ?? fact.key,
                value: fact.intValue !== null ? String(fact.intValue) : (fact.textValue ?? '—'),
              })),
            ],
          }
        : { state: 'empty', missing: 'لا ملفات ولا حقائق مسجَّلة' };

    case 19:
    case 20:
    case 21:
    case 23:
    case 24:
      return { state: 'unwired', missing: NO_EDITORIAL };

    case 22:
      return classified
        ? {
            state: 'data',
            rows: [{ label: 'الجمهور من التصنيف', value: product.categoryPath.join(' › ') }],
          }
        : { state: 'empty', missing: 'التصنيف التعليمي ناقص — لا جمهور يُشتقّ منه' };

    case 25:
      return { state: 'unwired', missing: 'نقطة عيّنة مجانية (ملف عيّنة في نموذج الملفات)' };

    case 26: {
      const rows = [
        ...product.facts.map((fact) => ({
          label: FACT_VOCABULARY[fact.key] ?? fact.key,
          value: fact.intValue !== null ? String(fact.intValue) : (fact.textValue ?? '—'),
        })),
      ];
      if (product.format) rows.push({ label: 'الصيغة', value: product.format });
      if (product.resourceType) rows.push({ label: 'نوع المورد', value: product.resourceType });
      if (product.editableState === 'yes' || product.editableState === 'no') {
        rows.push({ label: 'قابل للتعديل', value: product.editableState === 'yes' ? 'نعم' : 'لا' });
      }
      return rows.length > 0
        ? { state: 'data', rows }
        : { state: 'empty', missing: 'لا حقائق ولا صيغة ولا نوع مورد مسجَّل' };
    }

    case 27:
      return { state: 'unwired', missing: 'حقول الأبعاد والخامات في الكتالوج' };

    case 28:
    case 29:
    case 30:
      return { state: 'unwired', missing: NO_VERSIONING };

    case 31:
      return { state: 'unwired', missing: NO_LICENSING };

    case 32:
    case 33:
    case 34:
    case 35:
    case 36:
      return { state: 'unwired', missing: NO_CATALOG_QUERY };

    case 37:
      return { state: 'behaviour', note: 'سجلّ تصفّح في المتصفّح — لا يقرأ من الواجهة الخلفية.' };

    case 38:
    case 39:
    case 40:
    case 47:
      return { state: 'unwired', missing: NO_REVIEWS };

    case 41:
    case 42:
      return { state: 'unwired', missing: FAQ_SOURCE, endpoint: FAQ_ENDPOINT };

    case 43:
    case 44:
    case 46:
      return { state: 'unwired', missing: NO_STORE_CONTENT, endpoint: STORE_ENDPOINT };

    case 45:
      return { state: 'unwired', missing: 'نقطة مُعِدّ المحتوى في نطاق admin' };

    case 48:
      return { state: 'behaviour', note: 'مشاركة ونسخ رابط المنتج — سلوك متصفّح لا بيانات.' };

    case 49:
      return activeNames.length > 0
        ? { state: 'data', rows: [{ label: 'أقسام الصفحة النشطة', value: activeNames.join(' · ') }] }
        : { state: 'empty', missing: 'لا ودجة نشطة يُبنى منها تنقّل' };

    case 50:
      return hasPrice
        ? {
            state: 'data',
            rows: [
              { label: 'العنوان', value: product.title },
              { label: 'السعر المعروض', value: `${money(product.priceMinor ?? product.regularMinor)} ر.س` },
            ],
          }
        : { state: 'empty', missing: 'لا سعر أساسي مضبوط — لا نداء شراء صالح' };

    default:
      return { state: 'unwired', missing: 'ودجة خارج السجلّ' };
  }
}
