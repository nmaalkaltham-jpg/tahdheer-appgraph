// UNIT: STUDIO-DATA
// FILE: studio-data.ts
// STYLE: none
// PARENT: COMPOSER-CLIENT, MASTER-TREE, NEW-STUDIO, PAGE-STUDIO-CATALOG-ID, STUDIO-CLIENT, WIDGET-REGISTRY +4
// USES: LIB-ADMIN-API, LIB-FORMAT
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * استوديو المنتج — الثوابت المشتركة بين الخطوات الإحدى عشرة ومنشئ الصفحة.
 *
 * كل ثابت هنا مأخوذ من مصدر حقيقي في هذا المستودع، لا من لوحة التصميم:
 *   · مفردات الحقائق  ← App\Domain\SEO\EducationSchemaMapper::properties()
 *                        وapps/storefront/components/product/facts.ts
 *   · ميزانيات نصوص البحث ← App\Domain\SEO\TextBudget (60 / 155)
 *   · قائمة الجاهزية ← App\Domain\Admin\ProductReadiness::ITEMS (تصل من الخادم)
 *
 * كل سطح في الاستوديو موصول بنقطة نهاية مسجَّلة في routes/api-admin.php. ما
 * يعجز الخادم عنه فعلًا يُعلَن بردّه هو (٥٠٣ من مساعد الكتابة مثلًا) لا بلافتة
 * ثابتة في الواجهة — ولافتة «غير موصول» على قدرة موصولة كذبة بالاتجاه المعاكس.
 */

import type { ProductDetail } from '../../../../lib/admin-api';
import { dateTime } from '../../../../lib/format';

export { bytes, isoToLocalInput, localInputToIso } from '../../../../lib/format';

/** حمولة GET /admin/products/{id}: ProductCardResource + حقول التحرير. */
export interface StudioProduct extends ProductDetail {
  /** مسار المنتج في المتجر — يبنيه ProductCardResource من الـ slug. */
  url?: string;
  /**
   * البتّة القديمة. تبقى لأنّ لها مستهلكين، ولم تعد المصدر: ستّ طبائع لا
   * تُطوى في نعم/لا — فالهجين كان يقول «رقميّ» والخدمة تقول «ليست رقميّة».
   */
  digital?: boolean;
  /**
   * §٦ — الطبيعة كما يكتبها الخادم (`product_groups.product_nature`).
   *
   * `undefined` تعني منتجًا سبق النموذج الكونيّ، لا «رقميًّا» ضمنًا.
   */
  productNature?: string | null;
  resourceType?: string | null;
  format?: string | null;
  publishedAt?: string | null;
  currency?: string;
  discountPct?: number;
}

/** ضمّ أسماء أصناف CSS مع تجاهل الغائب (وحدات CSS تُرجع string | undefined). */
export function cx(...parts: (string | false | null | undefined)[]): string {
  return parts.filter((part): part is string => typeof part === 'string' && part !== '').join(' ');
}

/* ───── خطوات الاستوديو ───── */

export type StepKey =
  | 'master'
  | 'basics'
  | 'edu'
  | 'price'
  | 'variants'
  | 'files'
  | 'media'
  | 'search'
  | 'intl'
  | 'coverage'
  | 'ai'
  | 'publish';

export interface StepDef {
  key: StepKey;
  label: string;
}

export const STEPS: readonly StepDef[] = [
  { key: 'master', label: 'المنتج الأصل' },
  { key: 'basics', label: 'أساسيات المنتج' },
  { key: 'edu', label: 'التصنيف التعليمي' },
  { key: 'price', label: 'السعر' },
  { key: 'variants', label: 'صيغ الملفّ' },
  { key: 'files', label: 'الملفات' },
  { key: 'media', label: 'الوسائط' },
  { key: 'search', label: 'البحث والوصف' },
  { key: 'intl', label: 'الانتشار الدولي' },
  { key: 'coverage', label: 'تغطية التخصّصات' },
  { key: 'ai', label: 'مساعد الكتابة' },
  { key: 'publish', label: 'النشر' },
];

const STEP_KEYS: readonly string[] = STEPS.map((step) => step.key);

export function toStepKey(value: string | undefined): StepKey {
  return value !== undefined && STEP_KEYS.includes(value) ? (value as StepKey) : 'basics';
}

/* ───── مفردات الحقائق المعروفة ───── */

/**
 * المفاتيح التي يعرفها المتجر والـ Schema فعلًا. مفتاح خارج هذه القائمة
 * يُحفظ كما هو ويُعرض بمفتاحه — لا تُخترع له تسمية عربية.
 */
export const FACT_VOCABULARY: Record<string, string> = {
  slides_count: 'عدد الشرائح',
  pages_count: 'عدد الصفحات',
  worksheets_count: 'عدد أوراق العمل',
  activities_count: 'عدد الأنشطة',
  lessons_count: 'عدد الدروس',
  questions_count: 'عدد الأسئلة',
};

export const FACT_KEYS: readonly string[] = Object.keys(FACT_VOCABULARY);

/** ميزانيات نصوص البحث — App\Domain\SEO\TextBudget. */
export const SEO_BUDGET = { title: 60, description: 155 } as const;

/* ───── ملكية مخرجات البحث ───── */

export interface OwnershipRow {
  output: string;
  owner: string;
  ownerDir: 'ltr' | 'rtl';
  owned: boolean;
  note: string;
}

/**
 * مالك واحد لكل مخرَج (قرار تفقّد المرحلة الأولى): كاتب ثانٍ يُنتج وسمين.
 * الملاك أدناه أصناف حقيقية قُرئت في backend/laravel/app/Domain/SEO.
 */
export const SEO_OWNERSHIP: readonly OwnershipRow[] = [
  {
    output: 'العنوان والوصف',
    owner: 'Domain\\SEO\\MetadataService',
    ownerDir: 'ltr',
    owned: true,
    note: 'يبني العنوان والوصف من حقول المنتج وسلسلة مصادر مرتّبة. الاستوديو يقرأ ولا يكتب.',
  },
  {
    output: 'canonical و robots',
    owner: 'Domain\\SEO\\IndexabilityPolicy',
    ownerDir: 'ltr',
    owned: true,
    note: 'حكم الفهرسة يصدر من سياسة واحدة على الخادم — لا تجاوز من اللوحة.',
  },
  {
    output: 'Product Schema',
    owner: 'Domain\\SEO\\SchemaBuilder',
    ownerDir: 'ltr',
    owned: true,
    note: 'يبني الكيان من الحقائق نفسها التي تعرضها الصفحة، عبر EducationSchemaMapper.',
  },
  {
    output: 'hreflang',
    owner: 'Domain\\I18n\\LanguageEligibility',
    ownerDir: 'ltr',
    owned: true,
    note: 'يُخرج البدائل من MetadataService، ولا يُعلن لغةً إلّا إن كانت منشورة وقابلة للفهرسة ويخدمها المسار.',
  },
];

/* ───── عقود الخادم المشتركة بين الاستوديو ومنشئ الصفحة ───── */

/** صفّ من GET /admin/products/{id}/media — ProductMediaController::rows(). */
export interface MediaRow {
  id: number;
  url: string;
  alt: string | null;
  width: number | null;
  height: number | null;
  position: number;
  isCover: boolean;
}

/** صفّ من GET /admin/products/{id}/files — ProductFileController::rows(). */
export interface FileRow {
  id: number;
  role: string;
  position: number;
  asset: {
    id: number;
    originalName: string;
    label: string | null;
    mime: string;
    sizeBytes: number;
    sha256: string;
    visibility: string;
  } | null;
}

/** أدوار الملفّات كما يعرّفها ProductFileController::ROLES حرفيًّا. */
export const FILE_ROLES: Record<string, string> = {
  main: 'الملف الرئيسي',
  companion: 'ملف مرافق',
  sample: 'عيّنة مجّانية',
  teacher_guide: 'دليل المعلّم',
  answer_key: 'مفتاح الإجابات',
};

/** أنواع الصور التي يقبلها ProductMediaController::MIMES. */
export const MEDIA_ACCEPT = 'image/png,image/jpeg,image/webp,image/avif,image/svg+xml';

/**
 * حمولة GET/PATCH /admin/products/{id}/seo.
 *
 * `authored` ما كتبه المشرف، و`effective` ما سيُصيَّر فعلًا بعد قواعد
 * MetadataService — الفرق بينهما هو ما يمنع ظنّ ضبطٍ لم يقع.
 */
export interface SeoDoc {
  authored: {
    seoTitle: string | null;
    seoDesc: string | null;
    quickAnswer: string | null;
    synonyms: string | null;
    canonicalOverride: string | null;
  };
  effective: {
    renderedTitle: string;
    description: string;
    descriptionSource: string;
    canonical: string;
    robots: string;
    alternates: { hreflang: string; href: string; isDefault: boolean }[];
  };
}

/** بُعد واحد من CoverageEngine::DIMENSIONS. */
export interface CoverageDimension {
  label: string;
  present: boolean;
  value: string | null;
  blocking: boolean;
}

/** حمولة GET /admin/products/{id}/coverage — حكم مفسَّر لا درجة. */
export interface CoveragePayload {
  status: string;
  covered: string[];
  missing: string[];
  reasonCodes: string[];
  dimensions: Record<string, CoverageDimension>;
  siblings: { sameSubjectGrade: number; sameSubjectStage: number } | null;
}

export const COVERAGE_LABELS: Record<string, string> = {
  covered: 'مغطّى بالكامل',
  partial: 'مغطّى جزئيًّا',
  not_covered: 'غير مغطّى',
  insufficient_data: 'بيانات غير كافية للحكم',
};

/** البُعد ← خطوة الاستوديو التي تُصلحه؛ لا زرّ يَعِد بتغطية لا يملكها. */
export const COVERAGE_STEP: Record<string, StepKey> = {
  stage: 'edu',
  grade: 'edu',
  subject: 'edu',
  semester: 'edu',
};

export interface Requirement {
  key: string;
  label: string;
  satisfied: boolean;
  blocking: boolean;
  scope: 'store' | 'content';
  detail: string | null;
}

/** عنصر من GET /admin/products/{id}/language-eligibility. */
export interface LanguageState {
  locale: {
    code: string;
    nameAr: string;
    hreflang: string;
    direction: string;
    isDefault: boolean;
    isActive: boolean;
    isRoutable: boolean;
  };
  status: 'published' | 'ready' | 'incomplete' | 'blocked';
  translationExists: boolean;
  canPublish: boolean;
  hreflangEmitted: boolean;
  requirements: Requirement[];
  missing: string[];
}

export const LANGUAGE_LABELS: Record<LanguageState['status'], string> = {
  published: 'منشورة',
  ready: 'جاهزة للنشر',
  incomplete: 'ينقصها محتوى',
  blocked: 'محجوبة',
};

/** صفّ من GET /admin/products/{id}/translations. */
export interface TranslationRow {
  locale: string;
  isDefault: boolean;
  content: {
    title: string | null;
    subtitle: string | null;
    shortDesc: string | null;
    description: string | null;
    slug: string | null;
  };
  status: string;
  isIndexable: boolean;
  editable: boolean;
}

/** قسم من وثيقة التخطيط كما تُطبّعه App\Domain\Design\ProductLayout. */
export interface LayoutSection {
  widget: string;
  variant: string;
  visible: boolean;
  hide: { desk: boolean; tab: boolean; mob: boolean };
  settings: Record<string, unknown>;
}

/** حمولة GET/PATCH /admin/products/{id}/layout. */
export interface LayoutPayload {
  draft: { version: number; sections: LayoutSection[] };
  published: { version: number; sections: LayoutSection[] } | null;
  version: number;
  publishedVersion: number | null;
  publishedAt: string | null;
  widgets: Record<string, { label: string; zone: string }>;
  zones: Record<string, string>;
  required: string[];
}

/** حمولة POST /admin/products/{id}/writing/suggest — `applied` دائمًا false. */
export interface SuggestPayload {
  field: string;
  provider: string;
  suggestions: string[];
  applied: boolean;
}

/* ───── نافذة التخفيض ───── */

/**
 * حالة النافذة المحفوظة — تُقرأ من استجابة الخادم لا من المسوّدة: `onSale`
 * محسوبة في `PricingService::activeSaleMinor()`، وهي وحدها تقرّر إن كان
 * التخفيض معروضًا في المتجر الآن.
 */
export function saleWindowNotice(product: StudioProduct): string | null {
  const from = product.saleFrom ?? null;
  const to = product.saleTo ?? null;

  if (product.saleMinor === null) {
    return from === null && to === null
      ? null
      : 'لا سعر تخفيض محفوظ — التاريخان مسجَّلان ولا أثر لهما حتى يُضبط سعر التخفيض.';
  }
  if (product.onSale) {
    return to === null
      ? 'التخفيض فعّال الآن، وبلا تاريخ انتهاء.'
      : `التخفيض فعّال الآن، وينتهي في ${dateTime(to)}.`;
  }

  const now = Date.now();
  if (from !== null && new Date(from).getTime() > now) {
    return `التخفيض مجدول ولم يبدأ بعد — يبدأ في ${dateTime(from)}. المتجر يعرض السعر الأساسي.`;
  }
  if (to !== null && new Date(to).getTime() < now) {
    return `انتهت نافذة التخفيض في ${dateTime(to)} — المتجر يعرض السعر الأساسي.`;
  }
  return null;
}
