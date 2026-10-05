// UNIT: REGISTRY-REGISTRY
// FILE: registry.ts
// STYLE: none
// PARENT: ENTRY-NODE, REGISTRY-INDEX
// USES: DOCUMENT, FAMILIES
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * @tahdheer/registry — component registry (MIP §2.5; port of
 * tahdheer-component-registry.php: 45 components × 300 admin-facing choices
 * across 20 families).
 *
 * LAW: layout is a prop, not a file — one React component serves many
 * registry choices. Entry ids: `tah/{component}.{family:02d}{item:02d}`.
 *
 * HONESTY:
 * - `status` is a real flag: 'exists' ONLY when the component is implemented
 *   in @tahdheer/ui today; everything else is 'planned'.
 * - All counts are DERIVED from the arrays below (deriveCounts) — never
 *   written as literals (derived counts, checked in CI).
 * - This is a STARTER set (~50 entries); the full 300-choice table is ported
 *   from tahdheer-component-registry.php in Phase 5.
 */
import { FAMILIES, type FamilyId } from './families.ts';
import { CONTAINER_COMPONENTS } from './document.ts';

/**
 * The 45 ported component ids (§2.5 law: 45 React components serve 300
 * choices) + Phase 2's `container` (STUDIO-PHASE2-CONTRACT §2.4).
 * Derived from the MIP §12.1 component matrix; reconcile exact legacy naming
 * against tahdheer-component-registry.php during the Phase 5 port.
 */
export const COMPONENTS = [
  'hero',
  'collection',
  'card',
  'facts',
  'purchase-bar',
  'gallery',
  'preview-stack',
  'share',
  'reviews',
  'review-form',
  'trust-band',
  'trust-strip',
  'visitor-pill',
  'order-summary',
  'promo',
  'timer',
  'cross-sell',
  'offer-state',
  'search-box',
  'typeahead',
  'facets',
  'filter-sheet',
  'sort-tabs',
  'quick-view',
  'dialog',
  'tabs',
  'accordion',
  'carousel',
  'image-compare',
  'hotspots',
  'hover-gallery',
  'faq',
  'breadcrumbs',
  // المرحلة ٣٫١١: قسمان انتقلا من منطقة المورّدين إلى @tahdheer/ui.
  'highlight-grid',
  'sticky-reveal',
  // المرحلة ٣٫١٢: ثلاث حاويات مؤثِّرة + عدسة وسائط.
  'spotlight-layer',
  'beam-backdrop',
  'tracing-beam',
  'lens',
  'floating-contact',
  'toast',
  'empty-state',
  'section-heading',
  'answer',
  'fit-grid',
  'qa',
  'evidence',
  'disclosure',
  'marquee',
  'story',
  'compare',
  // المرحلة الثانية: مكوّن الحاويات الواحد خلف محرّكات التخطيط الأربعة.
  'container',
  // المرحلة ٢٫٥+٣: النافذة المنبثقة — حاوية جذرية فقط (C35، عقد المرحلة الثالثة §2.7).
  'popup',
] as const;

export type ComponentId = (typeof COMPONENTS)[number];

/** Verified entrance keyframe names (MIP §2.4) + 'none'. */
export type EntranceName = 'tahFadeUp' | 'tahFloat' | 'tahPop' | 'tyPop' | 'tahBarGrow' | 'none';

export type EntryStatus = 'planned' | 'exists';

export interface RegistryEntry {
  /** `tah/{component}.{family:02d}{item:02d}` */
  id: `tah/${string}`;
  label: string;
  family: FamilyId;
  component: ComponentId;
  /** Layout/variant key passed as a PROP to the component (never a new file). */
  layout: string;
  status: EntryStatus;
  motion: {
    /** 'inherit' = follow the admin/device motion level. */
    level: 'inherit' | number;
    entrance: EntranceName;
  };
  /** Registry schema version. */
  schema: 1;
}

const familyN = new Map<FamilyId, number>(FAMILIES.map((f) => [f.id, f.n]));
const pad = (n: number) => String(n).padStart(2, '0');

function entry(
  component: ComponentId,
  family: FamilyId,
  item: number,
  label: string,
  layout: string,
  status: EntryStatus,
  entrance: EntranceName = 'tahFadeUp',
): RegistryEntry {
  const ff = familyN.get(family);
  if (ff === undefined) throw new Error(`unknown family: ${family}`);
  return {
    id: `tah/${component}.${pad(ff)}${pad(item)}`,
    label,
    family,
    component,
    layout,
    status,
    motion: { level: 'inherit', entrance },
    schema: 1,
  };
}

/**
 * Starter entries. status:'exists' matches @tahdheer/ui exports today
 * (ProductCollection×5 layouts, ProductCard, TrustStrip×4, EmptyState,
 * Toast, SectionHeading). filmstrip/spot are NOT ProductCollection layouts
 * yet — they stay 'planned' until the component grows those branches.
 */
export const REGISTRY: readonly RegistryEntry[] = [
  // ── المنتجات: collection layouts (layout is a prop of one component) ──
  entry('collection', 'products', 1, 'شبكة المنتجات', 'grid', 'exists'),
  entry('collection', 'products', 2, 'قائمة المنتجات', 'list', 'exists'),
  entry('collection', 'products', 3, 'كاروسيل المنتجات', 'carousel', 'exists'),
  entry('collection', 'products', 4, 'منزلق المنتجات', 'slider', 'exists'),
  entry('collection', 'products', 5, 'شريط سينمائي', 'filmstrip', 'planned'),
  entry('collection', 'products', 6, 'منتج مميّز', 'spot', 'planned'),
  entry('collection', 'products', 7, 'تخطيط بينتو', 'bento', 'exists'),
  entry('card', 'products', 8, 'بطاقة المنتج الأساسية', 'default', 'exists'),
  entry('card', 'products', 9, 'بطاقة مدمجة', 'compact', 'planned'),

  // ── التحويل: hero pointers (10 skeletons × 30 objectives live in hero_registry)
  entry('hero', 'conversion', 1, 'هيرو المنتجات الأفضل', 'skeleton-classic', 'planned'),
  entry('hero', 'conversion', 2, 'هيرو العرض الموسمي', 'skeleton-split', 'planned'),
  entry('hero', 'conversion', 3, 'هيرو البحث السريع', 'skeleton-search', 'planned'),
  entry('purchase-bar', 'conversion', 4, 'شريط الشراء', 'inline', 'planned'),

  // ── الثقة: trust + reviews ──
  entry('trust-strip', 'trust', 1, 'شريط الثقة الكلاسيكي', 'classic', 'exists'),
  entry('trust-strip', 'trust', 2, 'شريط الثقة البسيط', 'minimal', 'exists'),
  entry('trust-strip', 'trust', 3, 'شريط الثقة الزجاجي', 'glass', 'exists'),
  entry('trust-strip', 'trust', 4, 'شريط الثقة الذكي', 'smart', 'exists'),
  entry('trust-band', 'trust', 5, 'حزام الثقة', 'band', 'exists'),
  entry('reviews', 'trust', 6, 'التقييمات المميزة', 'premium', 'planned'),
  entry('reviews', 'trust', 7, 'لوحة التقييمات', 'dashboard', 'planned'),
  entry('reviews', 'trust', 8, 'التقييمات التحريرية', 'editorial', 'planned'),
  entry('reviews', 'trust', 9, 'التقييمات البسيطة', 'simple', 'planned'),
  entry('evidence', 'trust', 10, 'قسم الأدلة الموثّقة', 'default', 'planned'),

  // ── الإجابة والذكاء: faq / answer / qa ──
  entry('faq', 'answer-ai', 1, 'أسئلة شائعة — فتح مفرد', 'single-open', 'exists'),
  entry('faq', 'answer-ai', 2, 'أسئلة شائعة — فتح متعدد', 'multi-open', 'exists'),
  entry('answer', 'answer-ai', 3, 'كتلة الإجابة السريعة', 'quick-answer', 'planned'),
  entry('qa', 'answer-ai', 4, 'سؤال وجواب', 'accordion', 'planned'),
  entry('fit-grid', 'answer-ai', 5, 'شبكة «هل يناسبك؟»', 'default', 'planned'),

  // ── المعلومات: product facts variants + empty state ──
  entry('facts', 'information', 1, 'حقائق المنتج — كلاسيكي', 'classic', 'exists'),
  entry('facts', 'information', 2, 'حقائق المنتج — مدمج', 'compact', 'exists'),
  entry('facts', 'information', 3, 'حقائق المنتج — مفصّل', 'detailed', 'planned'),
  entry('empty-state', 'information', 4, 'الحالة الفارغة (للمشرف)', 'admin-dashed', 'exists', 'none'),

  // ── التفاعلي: runtimes + disclosure + toast ──
  entry('disclosure', 'interactive', 1, 'كشف تدريجي', 'default', 'planned'),
  entry('dialog', 'interactive', 2, 'حوار', 'modal', 'planned', 'tahPop'),
  // المرحلة ٣٫١٠ (C107/C114): لوح «Tabs & Accordion» — 1a و1c نسقان لمكوّن واحد.
  entry('tabs', 'interactive', 3, 'تبويبات — خطّ تحتي', 'underline', 'exists', 'none'),
  entry('accordion', 'interactive', 4, 'أكورديون — بطاقات', 'cards', 'exists', 'none'),
  entry('carousel', 'interactive', 5, 'كاروسيل', 'scroll-snap', 'planned'),
  entry('toast', 'interactive', 6, 'التنبيهات العائمة', 'stack', 'exists', 'tahPop'),
  // المرحلة ٢٫٥+٣ (C35): PopupShell في @tahdheer/ui — حاوية جذرية فقط.
  entry('popup', 'interactive', 7, 'نافذة منبثقة', 'modal', 'exists', 'tahPop'),
  entry('tabs', 'interactive', 8, 'تبويبات — جانبية', 'side', 'exists', 'none'),
  entry('accordion', 'interactive', 9, 'أكورديون — مطويّات ناعمة', 'soft', 'exists', 'none'),

  // ── المحتوى ──
  entry('section-heading', 'content', 1, 'عنوان القسم', 'default', 'exists'),
  /*
   * المرحلة ٣٫١١ — قسمان كانا حبيسَي الأطلس. لا مكوّن جديد يُكتب هنا: نفس
   * الملفّ الذي يعرضه الأطلس هو الذي يصيّره المتجر بعد انتقاله إلى الحزمة.
   */
  entry('sticky-reveal', 'content', 2, 'كشف بالتمرير', 'default', 'exists'),
  entry('highlight-grid', 'categories', 1, 'شبكة إبراز', 'default', 'exists'),
  /*
   * المرحلة ٣٫١٢ — حاويات مؤثِّرة: تلفّ أبناءها ولا تحمل محتوى. ولذلك هي
   * حاويات لا أقسام: مؤثّرٌ يتتبّع المؤشّر أو التمرير لا يُعبَّر عنه تصريحَ
   * CSS في جدول الأنماط، ويحتاج شجرةً يلفّها.
   */
  entry('spotlight-layer', 'layout', 5, 'طبقة ضوء تتبع المؤشّر', 'default', 'exists', 'none'),
  entry('beam-backdrop', 'layout', 6, 'خلفيّة أشعّة', 'default', 'exists', 'none'),
  entry('tracing-beam', 'layout', 7, 'خيط تقدّم القراءة', 'default', 'exists', 'none'),
  entry('lens', 'media', 4, 'عدسة تكبير', 'default', 'exists', 'none'),

  // ── البحث + التصفية ──
  entry('search-box', 'search', 1, 'صندوق البحث', 'header', 'planned', 'none'),
  entry('typeahead', 'search', 2, 'الاقتراح الفوري', 'default', 'planned', 'none'),
  entry('facets', 'filtering', 1, 'شريط التصفية الجانبي', 'sidebar', 'planned', 'none'),
  entry('filter-sheet', 'filtering', 2, 'ورقة التصفية (جوال)', 'sheet', 'planned', 'tahPop'),

  // ── العروض ──
  // قُلبت exists في المرحلة الثانية (قرار C26): PromoBanner في @tahdheer/ui
  // هو المستهلك الأول لضابط الصورة.
  entry('promo', 'offers', 1, 'كتلة العرض', 'banner', 'exists'),
  entry('timer', 'offers', 2, 'عدّاد العرض', 'inline', 'planned', 'none'),

  // ── التخطيط: LayoutContainer واحد خلف 4 محرّكات (قرار C29) ──
  entry('container', 'layout', 1, 'حاوية قسم', 'section', 'exists', 'none'),
  entry('container', 'layout', 2, 'حاوية مرنة', 'flex', 'exists', 'none'),
  entry('container', 'layout', 3, 'حاوية رصّ', 'stack', 'exists', 'none'),
  entry('container', 'layout', 4, 'حاوية شبكة', 'grid', 'exists', 'none'),

  // ── التنقّل + الاجتماعي + قصّة العلامة ──
  entry('breadcrumbs', 'navigation', 1, 'مسار التنقّل', 'default', 'planned', 'none'),
  entry('share', 'social', 1, 'أزرار المشاركة', 'row', 'planned', 'none'),
  entry('story', 'brand-story', 1, 'قصّة العلامة', 'default', 'planned'),

  // ── الوسائط ──
  entry('gallery', 'media', 1, 'معرض المنتج', 'classic-thumbs', 'planned'),
  entry('hover-gallery', 'media', 2, 'معرض التمرير', 'scrub', 'planned', 'none'),
  entry('image-compare', 'media', 3, 'مقارنة الصور', 'range', 'planned', 'none'),

  // ── المنتج الرقمي + النماذج + الذكي ──
  entry('order-summary', 'digital-product', 1, 'ملخص الطلب', 'sidebar', 'planned', 'none'),
  entry('review-form', 'forms', 1, 'نموذج التقييم', 'simple', 'planned', 'none'),
  entry('visitor-pill', 'smart', 1, 'مؤشر الزوّار', 'compact', 'planned', 'tahPop'),
];

/**
 * المكوّنات التي يجوز إدراجها في مستند استوديو (عقد المرحلة الثانية §2.4):
 * أقسام المرحلة الأولى الثلاثة + الحاويات + كتلة العرض. card/toast/
 * empty-state «موجودة» لكنها ليست أقسام صفحة — تُعرض في المكتبة معطّلة مع
 * سببها.
 */
export const INSERTABLE_COMPONENTS = [
  'collection',
  'trust-strip',
  // المرحلة ٣٫٦: النسق الخامس من TrustStrip نفسه — لا مكوّن جديد (قرار C53).
  'trust-band',
  'section-heading',
  'container',
  'promo',
  'popup',
  'faq',
  // المرحلة ٣٫٧: بطاقة منتج مفردة — المكوّن مبنيّ منذ زمن، والوصل وحده كان ناقصًا.
  'card',
  // المرحلة ٣٫٨: حقائق المنتج — مربوطة بمرجع منتج، لا مؤلَّفة.
  'facts',
  // المرحلة ٣٫١٠: عائلة الكشف — بعد لوح Claude Design لا قبله (C107).
  'tabs',
  'accordion',
  // المرحلة ٣٫١١: قسمان حقيقيّان — مكوّناهما في @tahdheer/ui ومصيّرهما في الخريطة.
  'highlight-grid',
  'sticky-reveal',
  // المرحلة ٣٫١٢ — ثلاث حاويات + عدسة.
  'spotlight-layer',
  'beam-backdrop',
  'tracing-beam',
  'lens',
] as const satisfies readonly ComponentId[];

/**
 * قدرة الحاوية مشتقّة من المكوّن (CONTAINER_COMPONENTS في document.ts —
 * مصدر واحد يستهلكه محرّك العمليات أيضًا)، لا حقل مدخل جديدًا: تحليل
 * الحارس الموضعي لـentry(...) يبقى كما هو.
 */
export function isContainerEntry(entry: Pick<RegistryEntry, 'component'>): boolean {
  return (CONTAINER_COMPONENTS as readonly string[]).includes(entry.component);
}

/**
 * معرّفات السجل القابلة للإدراج — مشتقّة لا مكتوبة: status:'exists' ∧
 * المكوّن قسمُ صفحة. ولا قائمة PHP تقابلها اليوم: PageDocument ومرآةُ القائمة
 * حُذفا مع الاستوديو (٢٠٢٦-٠٩-١٧).
 */
/**
 * المكوّنات التي تحمل استعلام بيانات — **مصدر واحد** (عقد المرحلة ٣٫٨).
 *
 * كانت هذه المعرفة مكرّرة في ثلاثة مواضع: الحالّ في المتجر، وبوّابة تبويب
 * البيانات في المفتّش، ومرآة PHP. وأوّل ما أضيفت «الحقائق» نسي الموضع الثاني
 * فظهر القسم بلا منتقي منتج — عطلٌ لا يكشفه اختبار خلفي واحد. فالقائمة هنا،
 * والمواضع تستوردها.
 */
export const QUERY_COMPONENTS = ['collection', 'card', 'facts'] as const satisfies readonly ComponentId[];

/**
 * ومنها ما يصف **منتجًا واحدًا**: `limit` مقسور إلى 1 على الطرفين، ولا ضابط
 * عدد يُرسم للمؤلّف (قرار C61).
 */
export const SINGLE_QUERY_COMPONENTS = ['card', 'facts'] as const satisfies readonly ComponentId[];

export const carriesQuery = (component: string): boolean =>
  (QUERY_COMPONENTS as readonly string[]).includes(component);

export const isSingleQuery = (component: string): boolean =>
  (SINGLE_QUERY_COMPONENTS as readonly string[]).includes(component);

export function insertableRegistryIds(): string[] {
  const sections: readonly string[] = INSERTABLE_COMPONENTS;
  return REGISTRY.filter((e) => e.status === 'exists' && sections.includes(e.component)).map((e) => e.id);
}

/**
 * All counts DERIVED from the arrays — never hand-written literals
 * (الأعداد مشتقّة، MIP §2.5).
 */
export function deriveCounts() {
  const byStatus: Record<EntryStatus, number> = { planned: 0, exists: 0 };
  for (const e of REGISTRY) byStatus[e.status] += 1;
  return {
    families: FAMILIES.length,
    components: COMPONENTS.length,
    entries: REGISTRY.length,
    byStatus,
  };
}
