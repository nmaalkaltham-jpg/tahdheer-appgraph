// UNIT: LIB-REGISTRY
// FILE: registry.ts
// STYLE: none
// PARENT: GLOSSARY
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * سجل القاموس الشامل — المحتوى المرجعي الثلاثون حرفيًا من مصفوفة G في
 * «Tahdheer - Search Intelligence Center.dc.html» (الاختصار، الاسم، التصنيف،
 * التعريف، وسم التاريخية/الطرف الثالث).
 *
 * قيم اللوحة العيّنية (g[4]/g[5]) لم تُنسخ عمدًا — عقيدة الصدق:
 *   · live: مصدر حي حقيقي تجلبه شاشة القاموس من نقطة نهاية قائمة فعلًا.
 *   · absent: سبب مكتوب لغياب القيمة حين ادّعت اللوحة عيّنة بلا مصدر متصل.
 *   · لا live ولا absent: اللوحة نفسها لم تعرض قيمة (sc-if g.val).
 */

/** المصادر الحية الوحيدة — كلٌّ منها نقطة نهاية موجودة في routes/api-admin.php. */
export type LiveSlot =
  /** GET /admin/search/queries — CTR وبلا-نتائج للبحث الداخلي (لا SERP). */
  | 'queries'
  /** GET /admin/crawl — إجماليات آخر جولة زحف مكتملة. */
  | 'crawl'
  /** GET /admin/search/index — حقائق فهرس Meilisearch. */
  | 'index'
  /** GET /admin/dashboard — الإيراد ÷ الطلبات المدفوعة. */
  | 'aov'
  /** GET /admin/i18n/hreflang-audit — تدقيق hreflang الحي. */
  | 'hreflang';

export interface GlossaryEntry {
  abbr: string;
  name: string;
  category: string;
  definition: string;
  /** وسم تاريخي/طرف ثالث كما ورد حرفيًا في اللوحة («طرف» يلوّنه كهرمانيًا). */
  flag: string;
  live?: LiveSlot;
  absent?: string;
}

export const GLOSSARY: readonly GlossaryEntry[] = [
  {
    abbr: 'CTR',
    name: 'معدل النقر',
    category: 'أداء',
    definition: 'النقرات ÷ الانطباعات في نتائج البحث — أول مؤشر على جاذبية عنوانك.',
    flag: '',
    live: 'queries',
  },
  {
    abbr: 'INP',
    name: 'Interaction to Next Paint',
    category: 'CWV',
    definition: 'زمن استجابة التفاعل — خلَف FID رسميًا في CWV منذ 2024.',
    flag: 'FID تاريخي',
    absent: 'يتطلب CrUX (بيانات ميدانية) — غير متصل',
  },
  {
    abbr: 'LCP',
    name: 'أكبر رسم محتوى',
    category: 'CWV',
    definition: 'زمن ظهور أكبر عنصر — هدفه ≤ ٢٫٥ث.',
    flag: '',
    absent: 'يتطلب CrUX (بيانات ميدانية) — غير متصل',
  },
  {
    abbr: 'CLS',
    name: 'انزياح التخطيط التراكمي',
    category: 'CWV',
    definition: 'ثبات الصفحة بصريًا — هدفه ≤ ٠٫١.',
    flag: '',
    absent: 'يتطلب CrUX (بيانات ميدانية) — غير متصل',
  },
  {
    abbr: 'TTFB',
    name: 'زمن أول بايت',
    category: 'أداء',
    definition: 'استجابة الخادم قبل أي عرض.',
    flag: '',
    absent: 'يتطلب CrUX (بيانات ميدانية) — غير متصل',
  },
  {
    abbr: 'SERP',
    name: 'صفحة نتائج البحث',
    category: 'بحث',
    definition: 'تكوين النتائج لاستعلامك: عضوي + ميزات + إعلانات.',
    flag: '',
    absent: 'يتطلب Search Console — غير متصل',
  },
  {
    abbr: 'E-E-A-T',
    name: 'خبرة·تجربة·سلطة·ثقة',
    category: 'جودة',
    definition: 'إطار تقييم الجودة — يُبنى بمؤلفين حقيقيين ومصادر لا بحشو.',
    flag: '',
    absent: 'يتطلب وحدة مُعِدّين — غير مبنية',
  },
  {
    abbr: 'hreflang',
    name: 'وسم اللغة/الإقليم',
    category: 'دولي',
    definition: 'يوجه النسخة الصحيحة للجمهور الصحيح — تبادلي وذاتي إلزاميًا.',
    flag: '',
    live: 'hreflang',
  },
  {
    abbr: 'GBP',
    name: 'Google Business Profile',
    category: 'محلي',
    definition: 'الملف التجاري (الاسم السابق GMB) — نجومك في النتائج العلامية.',
    flag: 'GMB سابقًا',
    absent: 'يتطلب ربط Google Business Profile — غير متصل',
  },
  {
    abbr: 'AEO',
    name: 'تحسين محركات الإجابة',
    category: 'AI بحث',
    definition: 'هيكلة إجابات مباشرة قابلة للاقتباس — كتل الـ ٤٥ كلمة.',
    flag: '',
    absent: 'يتطلب مولّد كتل الإجابة — غير مبني',
  },
  {
    abbr: 'GEO',
    name: 'تحسين المحركات التوليدية',
    category: 'AI بحث',
    definition: 'جعل محتواك قابلًا للاسترجاع والاستشهاد في إجابات AI.',
    flag: '',
    absent: 'لا مقياس جاهزية GEO — غير مبني',
  },
  {
    abbr: 'RAG',
    name: 'التوليد المعزز بالاسترجاع',
    category: 'AI بحث',
    definition: 'آلية AI: يسترجع مقاطع ثم يجيب — مقاطعك المستقلة هي وحدات الاسترجاع.',
    flag: '',
  },
  {
    abbr: 'LLMO',
    name: 'تحسين نماذج اللغة',
    category: 'AI بحث',
    definition: 'مظلة جهود الظهور في مساعدي AI — كيان واضح ومعلومات أصلية.',
    flag: '',
  },
  {
    abbr: 'TF-IDF',
    name: 'تكرار المصطلح المعكوس',
    category: 'محتوى',
    definition: 'وزن إحصائي لتميز مصطلح في وثيقة — أداة تحليل لا حشو.',
    flag: '',
  },
  {
    abbr: 'LSI',
    name: 'الفهرسة الدلالية الكامنة',
    category: 'محتوى',
    definition: 'تقنية قديمة — ليست نظام ترتيب حديثًا لدى جوجل؛ استخدم الكيانات والتغطية.',
    flag: 'تاريخي — لا تعتمد عليه',
  },
  {
    abbr: 'DR/DA',
    name: 'تقييم النطاق',
    category: 'سلطة',
    definition: 'مقاييس Ahrefs/Moz التقديرية — ليست درجات جوجل رسمية.',
    flag: 'طرف ثالث',
    absent: 'طرف ثالث تقديري — لا مزوّد مرخّص',
  },
  {
    abbr: 'PageRank',
    name: 'تدفق قوة الروابط',
    category: 'سلطة',
    definition: 'مفهوم جوجل الأصلي — داخليًا نوزعه بالروابط الداخلية الموجهة.',
    flag: '',
  },
  {
    abbr: 'Canonical',
    name: 'الرابط القانوني',
    category: 'تقني',
    definition: 'النسخة المعتمدة من الصفحة — يمنع تشتت الفهرسة بين المتغيرات.',
    flag: '',
    absent: 'يتطلب فاحص canonical شاملًا — غير مبني',
  },
  {
    abbr: 'Crawl Budget',
    name: 'ميزانية الزحف',
    category: 'تقني',
    definition: 'طاقة الزاحف لموقعك — تُهدر على روابط المعاملات إن لم تُضبط.',
    flag: '',
    live: 'crawl',
  },
  {
    abbr: 'GSC',
    name: 'Search Console',
    category: 'تكامل',
    definition: 'مصدر الحقيقة للاستعلامات والانطباعات والفهرسة.',
    flag: '',
    absent: 'Search Console — غير متصل',
  },
  {
    abbr: 'CrUX',
    name: 'تجربة كروم الميدانية',
    category: 'أداء',
    definition: 'بيانات مستخدمين حقيقيين — الحكم النهائي على CWV.',
    flag: '',
    absent: 'CrUX — غير متصل',
  },
  {
    abbr: 'ROAS',
    name: 'عائد الإنفاق الإعلاني',
    category: 'تسويق',
    definition: 'الإيراد ÷ الإنفاق — للحملات المدفوعة عند تفعيلها.',
    flag: '',
    absent: 'يتطلب تكامل حملات مدفوعة — غير متصل',
  },
  {
    abbr: 'AOV',
    name: 'متوسط قيمة الطلب',
    category: 'تجارة',
    definition: 'الإيراد ÷ عدد الطلبات — الحزم ترفعه.',
    flag: '',
    live: 'aov',
  },
  {
    abbr: 'LTV',
    name: 'قيمة العميل الدائمة',
    category: 'تجارة',
    definition: 'إجمالي ما ينفقه العميل عبر علاقته بك — الولاء والتحديثات يرفعانه.',
    flag: '',
    absent: 'يتطلب تحليلات أفواج العملاء — غير مبنية',
  },
  {
    abbr: 'PDP/PLP',
    name: 'صفحة المنتج/القائمة',
    category: 'تجارة',
    definition: 'قالبا التجارة الأساسيان — لكلٍّ متطلبات فهرسة مختلفة.',
    flag: '',
    live: 'index',
  },
  {
    abbr: 'GTIN',
    name: 'المعرّف التجاري العالمي',
    category: 'تجارة',
    definition: 'رقم المنتج العالمي — إلزامي عمليًا لـ Merchant الغني.',
    flag: '',
    absent: 'لا حقل GTIN في بيانات المنتج — غير متتبَّع',
  },
  {
    abbr: 'Schema',
    name: 'البيانات المنظمة',
    category: 'تقني',
    definition: 'JSON-LD يشرح صفحتك للمحرك — يطابق المرئي أو لا يكون.',
    flag: '',
    absent: 'لا تجميع لأنواع Schema على مستوى المتجر — الفحص لكل رابط في مفتش URL',
  },
  {
    abbr: 'YMYL',
    name: 'مالك أو حياتك',
    category: 'جودة',
    definition: 'صفحات المال/الصحة تُحاسب أشد — التعليم قريب منها: مصادر ومؤلفون إلزاميًا.',
    flag: '',
  },
  {
    abbr: 'SXO',
    name: 'تحسين تجربة البحث',
    category: 'تجربة',
    definition: 'ما بعد النقرة: هل وجد الزائر ما وُعد به؟ يقاس بالرضا لا بالترتيب فقط.',
    flag: '',
  },
  {
    abbr: 'PAA',
    name: 'يسأل الناس أيضًا',
    category: 'بحث',
    definition: 'أسئلة SERP المتوسعة — أسئلتك الشائعة المرئية تلتقطها.',
    flag: '',
    absent: 'يتطلب Search Console — غير متصل',
  },
];

/** التصنيفات الأربعة عشر مشتقة من السجل — لا قائمة مكرَّرة تتعفن. */
export const GLOSSARY_CATEGORIES: readonly string[] = GLOSSARY.map((entry) => entry.category).filter(
  (category, index, all) => all.indexOf(category) === index,
);
