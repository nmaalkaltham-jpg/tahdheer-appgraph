// UNIT: PROPS
// FILE: props.ts
// STYLE: none
// PARENT: ENTRY-NODE, REGISTRY-INDEX, SYMBOLS
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مخططات خصائص الاستوديو — واصفات بيانات فقط (STUDIO-PHASE1-CONTRACT §2.3).
 *
 * كل ضابط في مفتّش الاستوديو يُرسم من هذه الواصفات: مفتاح الخاصية كما
 * يستهلكه المكوّن حرفيًّا (لا طبقة ترجمة تنحرف)، وتسمية عربية، وتبويب،
 * ونوع ضابط، وقيمة افتراضية. الحزمة تبقى مستقلّة عن React وعن @tahdheer/ui؛
 * فحص `satisfies` في ProductCollection.tsx يضمن أن مفاتيح مخطط «المجموعة»
 * ⊆ مفاتيح CollectionSettings فلا ينحرف الطرفان.
 *
 * ما لا يظهر هنا عمدًا:
 *   · `layout` — يحدّده مدخل السجل نفسه (التخطيط خاصية يمرّرها component-map
 *     من entry.layout، لا ضابط يجعل التسمية تكذب).
 *   · `hide` لكل جهاز — يعيش على العقدة (`StudioNode.hidden`) وتحرّكه عملية
 *     `set-hidden`، لا خاصية مكوّن.
 *   · `source/limit/ids` — تعيش في `StudioNode.query` وتحرّكها `set-query`
 *     (تبويب البيانات في المفتّش يتعامل معها مباشرة).
 */

export type PropTab = 'content' | 'layout' | 'data';

export type PropControlKind =
  | 'text'
  | 'textarea'
  | 'number'
  | 'select'
  | 'toggle'
  /** مجموعة مفاتيح منطقية داخل كائن واحد (مثل parts) — options تسمّي المفاتيح. */
  | 'toggle-group'
  /** قائمة عناصر قابلة للإضافة والحذف والترتيب — `fields` تصف حقول العنصر. */
  | 'list'
  /** صورة من مكتبة الاستوديو — القيمة StudioImage أو null (المرحلة الثانية). */
  | 'image';

export interface PropOption {
  value: string | number;
  label: string;
}

export interface PropControl {
  /** مفتاح الخاصية كما يستهلكه المكوّن حرفيًّا. */
  key: string;
  /** التسمية العربية المعروضة في المفتّش. */
  label: string;
  tab: PropTab;
  control: PropControlKind;
  /** select: الخيارات · toggle-group: المفاتيح الفرعية وتسمياتها. */
  options?: readonly PropOption[];
  min?: number;
  max?: number;
  step?: number;
  default: unknown;
  /** يقبل التحرير المباشر في الكانفس بالنقر المزدوج (نص خام). */
  inline?: boolean;
  /** control='list' فقط: واصفات حقول العنصر الواحد. */
  fields?: readonly PropControl[];
  /**
   * يقبل تجاوزًا لكل جهاز في node.responsive (tab/mob) — المرآة المشتقة
   * RESPONSIVE_KEYS يطابقها الخادم والحارس (المرحلة الثانية، قرار C17).
   */
  responsive?: true;
}

/**
 * صورة يملكها المستند (STUDIO-PHASE2-CONTRACT §2.5): رابط نسبي داخل
 * ‎/api/v1/media/‎ حصرًا (الـnormalizer يسقط ما سواه — يقتل javascript:
 * والروابط الخارجية)، وalt إلزامي عبر preflight IMAGE_MISSING_ALT،
 * والبؤرة 0-100 تُبثّ object-position — بلا أعمدة قاعدة بيانات.
 */
export interface StudioImage {
  url: string;
  alt: string;
  /** أبعاد طبيعية لتصيير بلا قفزة تخطيط. */
  w?: number | null;
  h?: number | null;
  /** بؤرة القص 0-100، الافتراض 50. */
  focalX?: number;
  focalY?: number;
}

/**
 * مجموعة المنتجات — مبذورة من CollectionSettings في
 * packages/ui/src/ProductCollection.tsx (المفاتيح حرفيًّا، والقيم الافتراضية
 * قيم COLLECTION_DEFAULTS نفسها).
 */
export const COLLECTION_SCHEMA = [
  // ── المحتوى ──
  { key: 'heading', label: 'عنوان المجموعة', tab: 'content', control: 'text', default: '', inline: true },
  {
    key: 'headingLevel',
    label: 'مستوى العنوان',
    tab: 'content',
    control: 'select',
    options: [
      { value: 'h2', label: 'عنوان ثانٍ (h2)' },
      { value: 'h3', label: 'عنوان ثالث (h3)' },
      { value: 'none', label: 'بلا عنوان' },
    ],
    default: 'h2',
  },
  { key: 'emptyMsg', label: 'رسالة غياب المنتجات', tab: 'content', control: 'textarea', default: '' },
  { key: 'errorMsg', label: 'رسالة تعذّر التحميل', tab: 'content', control: 'textarea', default: '' },

  // ── التخطيط ──
  { key: 'hSize', label: 'حجم العنوان (px)', tab: 'layout', control: 'number', min: 10, max: 40, step: 1, default: 11 },
  {
    key: 'hWeight',
    label: 'وزن العنوان',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 700, label: 'عريض' },
      { value: 800, label: 'أعرض' },
      { value: 900, label: 'الأعرض' },
    ],
    default: 800,
  },
  { key: 'cols', label: 'عدد الأعمدة', tab: 'layout', control: 'number', min: 1, max: 6, step: 1, default: 3 },
  { key: 'gap', label: 'الفراغ بين البطاقات (px)', tab: 'layout', control: 'number', min: 0, max: 48, step: 1, default: 10 },
  {
    key: 'density',
    label: 'الكثافة',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'comfy', label: 'مريحة' },
      { value: 'mid', label: 'متوسطة' },
      { value: 'tight', label: 'مضغوطة' },
    ],
    default: 'comfy',
  },
  // يُرفع في تبويب «التجاوب» في المفتّش مع مفاتيح الإظهار لكل جهاز.
  { key: 'mobCols', label: 'أعمدة الجوال', tab: 'layout', control: 'number', min: 1, max: 3, step: 1, default: 2 },
  { key: 'radius', label: 'استدارة الزوايا (px)', tab: 'layout', control: 'number', min: 0, max: 32, step: 1, default: 6 },
  {
    key: 'border',
    label: 'الحدود',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'none', label: 'بلا' },
      { value: 'thin', label: 'رفيعة' },
      { value: 'solid', label: 'واضحة' },
    ],
    default: 'none',
  },
  {
    key: 'shadow',
    label: 'الظل',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'none', label: 'بلا' },
      { value: 'soft', label: 'ناعم' },
      { value: 'lift', label: 'مرتفع' },
    ],
    default: 'none',
  },
  {
    key: 'surface',
    label: 'السطح',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'plain', label: 'شفاف' },
      { value: 'soft', label: 'رخو' },
      { value: 'warm', label: 'دافئ' },
      { value: 'dark', label: 'داكن' },
    ],
    default: 'plain',
  },
  {
    key: 'ratio',
    label: 'نسبة الصورة',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'square', label: 'مربّعة' },
      { value: 'wide', label: 'عريضة' },
      { value: 'tall', label: 'طويلة' },
    ],
    default: 'square',
  },
  {
    key: 'fit',
    label: 'ملاءمة الصورة',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'cover', label: 'تعبئة' },
      { value: 'contain', label: 'احتواء' },
    ],
    default: 'cover',
  },
  {
    key: 'parts',
    label: 'أجزاء البطاقة',
    tab: 'layout',
    control: 'toggle-group',
    options: [
      { value: 'img', label: 'الصورة' },
      { value: 'title', label: 'العنوان' },
      { value: 'price', label: 'السعر' },
      { value: 'badge', label: 'الشارة' },
      { value: 'cta', label: 'زر السلة' },
    ],
    default: { img: true, title: true, price: true, badge: false, cta: false },
  },
  {
    key: 'hover',
    label: 'أثر التمرير',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'lift', label: 'ارتفاع' },
      { value: 'glow', label: 'توهّج' },
      { value: 'tilt', label: 'ميلان' },
      { value: 'none', label: 'بلا' },
    ],
    default: 'lift',
  },
  { key: 'spotlight', label: 'بؤرة المؤشّر', tab: 'layout', control: 'toggle', default: false },
  { key: 'motion', label: 'مستوى الحركة', tab: 'layout', control: 'number', min: 0, max: 10, step: 1, default: 5 },
  {
    key: 'entrance',
    label: 'حركة الدخول',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'up', label: 'صعود' },
      { value: 'fade', label: 'تلاشٍ' },
      { value: 'zoom', label: 'تقريب' },
      { value: 'none', label: 'بلا' },
    ],
    default: 'up',
  },
  { key: 'anchor', label: 'مرساة الرابط (#)', tab: 'layout', control: 'text', default: '' },
  { key: 'cls', label: 'صنف CSS مخصّص', tab: 'layout', control: 'text', default: '' },
] as const satisfies readonly PropControl[];

/** مفاتيح مخطط المجموعة — فحص ⊆ CollectionSettings يعيش مع المكوّن نفسه. */
export type CollectionSchemaKey = (typeof COLLECTION_SCHEMA)[number]['key'];

/**
 * شريط الثقة — مبذور من TrustStripProps/TrustItem. عقيدة الصدق: لا عناصر
 * افتراضية — القائمة تبدأ فارغة والمكوّن يصيّر لا شيء حتى يكتب المشرف
 * ادّعاءً حقيقيًّا (variant يحدّده مدخل السجل؛ icon عنصر React فليس بيانات
 * قابلة للتحرير هنا).
 */
export const TRUST_STRIP_SCHEMA = [
  {
    key: 'items',
    label: 'عناصر الثقة',
    tab: 'content',
    control: 'list',
    default: [],
    fields: [
      { key: 'title', label: 'الادّعاء', tab: 'content', control: 'text', default: '' },
      { key: 'description', label: 'السطر الثانوي', tab: 'content', control: 'text', default: '' },
      {
        key: 'tone',
        label: 'لون النقطة (النمط الذكي)',
        tab: 'content',
        control: 'select',
        options: [
          { value: 'primary', label: 'أساسي' },
          { value: 'bright', label: 'ساطع' },
          { value: 'gold', label: 'ذهبي' },
        ],
        default: 'primary',
      },
      {
        key: 'live',
        label: 'حلقة نابضة — فعّلها فقط لحالة حيّة فعلًا الآن',
        tab: 'content',
        control: 'toggle',
        default: false,
      },
    ],
  },
  { key: 'dark', label: 'زوج الألوان الداكن', tab: 'layout', control: 'toggle', default: false },
  { key: 'compact', label: 'مضغوط', tab: 'layout', control: 'toggle', default: false },
] as const satisfies readonly PropControl[];

/**
 * الأسئلة الشائعة — مبذور من FaqListProps (عقد المرحلة ٣٫٦).
 *
 * عقيدة الصدق نفسها التي في شريط الثقة: القائمة تبدأ **فارغة** والمكوّن
 * يصيّر لا شيء حتى يكتب المشرف سؤالًا حقيقيًّا — لا سؤال افتراضي يُنشر
 * سهوًا. ولا مفتاح `openMode` هنا: النسق من مدخل السجل (قرار C57).
 */
export const FAQ_SCHEMA = [
  { key: 'title', label: 'عنوان القسم', tab: 'content', control: 'text', default: 'الأسئلة الشائعة', inline: true },
  {
    key: 'items',
    label: 'الأسئلة',
    tab: 'content',
    control: 'list',
    default: [],
    fields: [
      { key: 'question', label: 'السؤال', tab: 'content', control: 'text', default: '' },
      { key: 'answer', label: 'الجواب', tab: 'content', control: 'textarea', default: '' },
    ],
  },
] as const satisfies readonly PropControl[];

/**
 * التبويبات — عقد المرحلة ٣٫١٠ (C110): بند = اسم + نصّ + بنود «سطر لكل بند»
 * (تقسمها component-map — لا مُحلِّل ترميز). النسق من المدخل لا من الخصائص (C57).
 */
export const TABS_SCHEMA = [
  {
    key: 'items',
    label: 'التبويبات',
    tab: 'content',
    control: 'list',
    default: [],
    fields: [
      { key: 'label', label: 'اسم التبويب', tab: 'content', control: 'text', default: '' },
      { key: 'text', label: 'النصّ', tab: 'content', control: 'textarea', default: '' },
      { key: 'points', label: 'بنود (سطر لكل بند)', tab: 'content', control: 'textarea', default: '' },
    ],
  },
] as const satisfies readonly PropControl[];

/**
 * الأكورديون — عقد المرحلة ٣٫١٠. `singleOpen` خاصّية لا مدخل (C112): محور المدخل
 * مصروف للنسق البصري، والفتح المفرد متعامد معه.
 */
export const ACCORDION_SCHEMA = [
  { key: 'title', label: 'عنوان القسم', tab: 'content', control: 'text', default: '', inline: true },
  {
    key: 'items',
    label: 'البنود',
    tab: 'content',
    control: 'list',
    default: [],
    fields: [
      { key: 'title', label: 'العنوان', tab: 'content', control: 'text', default: '' },
      { key: 'text', label: 'النصّ', tab: 'content', control: 'textarea', default: '' },
      { key: 'points', label: 'بنود (سطر لكل بند)', tab: 'content', control: 'textarea', default: '' },
    ],
  },
  { key: 'singleOpen', label: 'فتح بند واحد فقط', tab: 'layout', control: 'toggle', default: false },
] as const satisfies readonly PropControl[];

/**
 * بطاقة منتج مفردة — مبذورة من ProductCardProps (عقد المرحلة ٣٫٧، قرار C62).
 *
 * مفتاح واحد فقط، والبقيّة **عمدًا** ليست هنا: كل ما في `ProductCard` تقريبًا
 * بيانات كتالوج (العنوان · السعر · الصورة · التقييم) تصل من الاستعلام. عرضُها
 * للتحرير يدعو المؤلّف إلى الكتابة فوق بيانات المتجر — بطاقة تعلن سعرًا غير
 * سعر المنتج. فالحدّ هنا حماية لا فقر.
 *
 * ووجهة الزرّ لا تُؤلَّف: هي `url` المنتج المحلول. «لا زرّ بلا وجهة» محفوظ
 * بلا أن يكتب المؤلّف رابطًا يتقادم مع أوّل تغيير في المسار.
 */
export const CARD_SCHEMA = [
  {
    key: 'ctaLabel',
    label: 'نص زرّ الإجراء',
    tab: 'content',
    control: 'text',
    default: '',
    inline: true,
  },
] as const satisfies readonly PropControl[];

/**
 * حقائق المنتج — مبذورة من ProductFactsProps (عقد المرحلة ٣٫٨).
 *
 * **بلا مفتاح محتوى واحد، عمدًا**: كل حقيقة تأتي من الكتالوج عبر مرجع المنتج
 * (`node.query`)، والنسق من مدخل السجل (قانون «التخطيط خاصية السجل»). وهذا
 * بالضبط ما يجعل هذا القسم مشروعًا بعد أن رفضته المرحلة ٣٫٦: حين كان المؤلّف
 * سيكتب الحقائق كان سطح تلفيق؛ وحين صارت تُقرأ صار غياب المفاتيح هو الأمان.
 *
 * المخطّط فارغ ولا يخالف الحارس: `PROP_SCHEMAS` مفاتيحه = `INSERTABLE_COMPONENTS`،
 * والحاوية سبقته بمخطّط بلا خصائص محتوى.
 */
export const FACTS_SCHEMA = [] as const satisfies readonly PropControl[];

/** عنوان القسم — مبذور من SectionHeadingProps. */
export const SECTION_HEADING_SCHEMA = [
  { key: 'title', label: 'العنوان', tab: 'content', control: 'text', default: 'عنوان القسم', inline: true },
  { key: 'eyebrow', label: 'السطر العلوي', tab: 'content', control: 'text', default: '', inline: true },
  { key: 'description', label: 'الوصف', tab: 'content', control: 'textarea', default: '' },
  {
    key: 'as',
    label: 'مستوى العنوان',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'h2', label: 'عنوان ثانٍ (h2)' },
      { value: 'h3', label: 'عنوان ثالث (h3)' },
    ],
    default: 'h2',
  },
  {
    key: 'align',
    label: 'المحاذاة',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'start', label: 'البداية' },
      { value: 'center', label: 'الوسط' },
    ],
    default: 'start',
  },
] as const satisfies readonly PropControl[];

/**
 * الحاوية — مكوّن LayoutContainer واحد يخدم المحرّكات الأربعة (section ·
 * flex · stack · grid؛ المحرّك من entry.layout — التخطيط خاصية لا ملف).
 * مخطط واحد لكل المفاتيح؛ المفتّش يرشّح بالمحرّك (مفاتيح flex/stack تظهر
 * تحتهما فقط، ومفاتيح grid تحته فقط). ✱responsive: يقبل تجاوز tab/mob.
 * فحص satisfies المقابل يعيش مع LayoutContainerSettings في packages/ui.
 */
export const CONTAINER_SCHEMA = [
  // ── مشترك ──
  {
    key: 'width',
    label: 'عرض المحتوى',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'full', label: 'كامل العرض' },
      { value: 'wide', label: 'عريض' },
      { value: 'content', label: 'قياسي' },
      { value: 'narrow', label: 'ضيّق' },
    ],
    default: 'content',
    responsive: true,
  },
  { key: 'pad', label: 'الحشوة الرأسية (px)', tab: 'layout', control: 'number', min: 0, max: 160, step: 4, default: 24, responsive: true },
  { key: 'padInline', label: 'الحشوة الجانبية (px)', tab: 'layout', control: 'number', min: 0, max: 160, step: 4, default: 16, responsive: true },
  { key: 'gap', label: 'الفراغ بين الأبناء (px)', tab: 'layout', control: 'number', min: 0, max: 64, step: 2, default: 16, responsive: true },
  {
    key: 'bg',
    label: 'الخلفية',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'none', label: 'بلا' },
      { value: 'surface', label: 'السطح' },
      { value: 'page', label: 'الصفحة' },
      { value: 'soft', label: 'رخوة' },
      { value: 'mist', label: 'ضبابية' },
      { value: 'green-light', label: 'خضراء فاتحة' },
      { value: 'deep', label: 'داكنة' },
    ],
    default: 'none',
  },
  {
    key: 'radius',
    label: 'الاستدارة',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'none', label: 'بلا' },
      { value: 'sm', label: 'صغيرة' },
      { value: 'md', label: 'متوسطة' },
      { value: 'card', label: 'بطاقة' },
      { value: 'lg', label: 'كبيرة' },
      { value: 'hero', label: 'هيرو' },
    ],
    default: 'none',
  },
  {
    key: 'border',
    label: 'الحدود',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'none', label: 'بلا' },
      { value: 'hairline', label: 'شعرية' },
      { value: 'thin', label: 'رفيعة' },
      { value: 'strong', label: 'واضحة' },
    ],
    default: 'none',
  },
  { key: 'minH', label: 'أدنى ارتفاع (px)', tab: 'layout', control: 'number', min: 0, max: 1200, step: 10, default: 0, responsive: true },
  { key: 'anchor', label: 'مرساة الرابط (#)', tab: 'layout', control: 'text', default: '' },
  { key: 'cls', label: 'صنف CSS مخصّص', tab: 'layout', control: 'text', default: '' },

  // ── flex/stack (قيم منطقية — RTL أصيل) ──
  {
    key: 'direction',
    label: 'الاتجاه',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'row', label: 'صفّ' },
      { value: 'row-reverse', label: 'صفّ معكوس' },
      { value: 'column', label: 'عمود' },
      { value: 'column-reverse', label: 'عمود معكوس' },
    ],
    default: 'row',
    responsive: true,
  },
  { key: 'wrap', label: 'التفاف الأبناء', tab: 'layout', control: 'toggle', default: true, responsive: true },
  {
    key: 'justify',
    label: 'توزيع المحور الرئيس',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'start', label: 'البداية' },
      { value: 'center', label: 'الوسط' },
      { value: 'end', label: 'النهاية' },
      { value: 'between', label: 'تباعد بيني' },
    ],
    default: 'start',
    responsive: true,
  },
  {
    key: 'align',
    label: 'محاذاة المحور المتقاطع',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'stretch', label: 'تمدّد' },
      { value: 'start', label: 'البداية' },
      { value: 'center', label: 'الوسط' },
      { value: 'end', label: 'النهاية' },
    ],
    default: 'stretch',
    responsive: true,
  },

  // ── grid ──
  { key: 'cols', label: 'عدد الأعمدة', tab: 'layout', control: 'number', min: 1, max: 6, step: 1, default: 3, responsive: true },
  {
    key: 'colMode',
    label: 'نمط الأعمدة',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'fixed', label: 'عدد ثابت' },
      { value: 'auto', label: 'تلقائي بأدنى عرض' },
    ],
    default: 'fixed',
  },
  { key: 'minCol', label: 'أدنى عرض عمود (px)', tab: 'layout', control: 'number', min: 120, max: 480, step: 10, default: 220, responsive: true },
  { key: 'rowGap', label: 'فراغ الصفوف (px)', tab: 'layout', control: 'number', min: 0, max: 64, step: 2, default: 16, responsive: true },
] as const satisfies readonly PropControl[];

/**
 * كتلة العرض — أول مستهلك حقيقي لضابط الصورة (قرار C26): صورة يملكها
 * المستند ببؤرة قص، لا صورة فارغة تُنشر (المكوّن لا يصيّر شيئًا للعامة
 * بلا صورة). فحص satisfies المقابل يعيش مع PromoBanner في packages/ui.
 */
export const PROMO_SCHEMA = [
  { key: 'image', label: 'صورة العرض', tab: 'content', control: 'image', default: null },
  { key: 'heading', label: 'عنوان العرض', tab: 'content', control: 'text', default: '', inline: true },
  { key: 'text', label: 'نص العرض', tab: 'content', control: 'textarea', default: '' },
  { key: 'ctaLabel', label: 'نص زرّ الإجراء', tab: 'content', control: 'text', default: '', inline: true },
  { key: 'ctaHref', label: 'رابط زرّ الإجراء', tab: 'content', control: 'text', default: '' },
  {
    key: 'height',
    label: 'الارتفاع',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'sm', label: 'قصير' },
      { value: 'md', label: 'متوسط' },
      { value: 'lg', label: 'طويل' },
    ],
    default: 'md',
  },
  {
    key: 'overlay',
    label: 'التعتيم فوق الصورة',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'none', label: 'بلا' },
      { value: 'soft', label: 'خفيف' },
      { value: 'strong', label: 'قوي' },
    ],
    default: 'soft',
  },
  {
    key: 'align',
    label: 'محاذاة المحتوى',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'start', label: 'البداية' },
      { value: 'center', label: 'الوسط' },
    ],
    default: 'start',
  },
] as const satisfies readonly PropControl[];

/**
 * النافذة المنبثقة (STUDIO-PHASE3-CONTRACT §2.7، قرار C35) — حاوية جذرية
 * فقط؛ المحتوى أبناؤها والإعداد هنا: المحفّز والتكرار سلوك المستند وحده
 * (لا أعمدة قاعدة بيانات — سقوف التكرار في تخزين المتصفح، وإعادة ضبط
 * التصفح الخاص مُصرَّح بها في المفتّش). exit لسطح المكتب فقط —
 * التحذير يُعرض غير قابل للإغلاق بجوار الضابط.
 */
export const POPUP_SCHEMA = [
  {
    key: 'trigger',
    label: 'المحفّز',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'delay', label: 'بعد مهلة' },
      { value: 'scroll', label: 'عند نسبة تمرير' },
      { value: 'exit', label: 'نيّة الخروج (سطح المكتب فقط)' },
    ],
    default: 'delay',
  },
  { key: 'delaySec', label: 'المهلة (ثوانٍ)', tab: 'layout', control: 'number', min: 0, max: 60, step: 1, default: 3 },
  { key: 'scrollPct', label: 'نسبة التمرير (٪)', tab: 'layout', control: 'number', min: 10, max: 90, step: 5, default: 50 },
  {
    key: 'frequency',
    label: 'تكرار الظهور',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'always', label: 'كل زيارة' },
      { value: 'session', label: 'مرة كل جلسة' },
      { value: 'daily', label: 'مرة كل يوم' },
      { value: 'once', label: 'مرة واحدة' },
    ],
    default: 'session',
  },
  {
    key: 'width',
    label: 'العرض',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 'sm', label: 'ضيّق' },
      { value: 'md', label: 'متوسط' },
      { value: 'lg', label: 'عريض' },
    ],
    default: 'md',
  },
  { key: 'dismissLabel', label: 'نص زرّ الإغلاق', tab: 'content', control: 'text', default: 'إغلاق' },
  { key: 'ariaLabel', label: 'التسمية الوصفية للقارئ الصوتي', tab: 'content', control: 'text', default: 'نافذة منبثقة' },
] as const satisfies readonly PropControl[];

/** المكوّنات القابلة للإدراج ومخطط كلٍّ منها. */

/**
 * شبكة الإبراز — بطاقات روابط بخلفيّة تنزلق إلى المُحوَّم عليه.
 *
 * ‎icon‎ في المكوّن عقدة React ولا مكان لها هنا: المستند يحمل نصًّا لا شجرة،
 * وخاصيّة لا يستطيع المؤلّف تحريرها ولا الخادم التحقّق منها لا تدخل المخطّط.
 *
 * ‎columns‎ استجابيّة: ثلاثة أعمدة على سطح المكتب قد تصير عمودين على اللوحيّ.
 */
export const HIGHLIGHT_GRID_SCHEMA = [
  {
    key: 'items',
    label: 'البطاقات',
    tab: 'content',
    control: 'list',
    default: [],
    fields: [
      { key: 'title', label: 'العنوان', tab: 'content', control: 'text', default: '' },
      { key: 'description', label: 'الوصف', tab: 'content', control: 'textarea', default: '' },
      { key: 'href', label: 'الوجهة', tab: 'content', control: 'text', default: '' },
    ],
  },
  {
    key: 'columns',
    label: 'الأعمدة',
    tab: 'layout',
    control: 'select',
    options: [
      { value: 1, label: 'عمود' },
      { value: 2, label: 'عمودان' },
      { value: 3, label: 'ثلاثة' },
    ],
    default: 2,
    responsive: true,
  },
] as const satisfies readonly PropControl[];

/**
 * الكشف بالتمرير — أقسام تتبدّل لوحتها مع التمرير.
 *
 * ‎panel‎ في المكوّن عقدة اختياريّة، وغيابها يُصيَّر رقم الخطوة — فلا يحتاج
 * المستند إلى تمثيل شجرة، ولا ينشقّ مكوّن ثانٍ «نسخة الاستوديو».
 */
export const STICKY_REVEAL_SCHEMA = [
  {
    key: 'sections',
    label: 'الخطوات',
    tab: 'content',
    control: 'list',
    default: [],
    fields: [
      { key: 'title', label: 'العنوان', tab: 'content', control: 'text', default: '' },
      { key: 'body', label: 'الشرح', tab: 'content', control: 'textarea', default: '' },
    ],
  },
] as const satisfies readonly PropControl[];


/**
 * طبقة الضوء — حاوية تلفّ أبناءها وتضع فوقهم ضوءًا يتبع المؤشّر.
 *
 * لا ضابط لونٍ حرّ: اللون رمز العلامة، ولوحةٌ حرّة في يد المؤلّف تكسر انضباط
 * الرموز الذي يقوم عليه النظام كلّه. المقاس والشدّة وحدهما قابلان للتحرير.
 */
export const SPOTLIGHT_LAYER_SCHEMA = [
  {
    key: 'size',
    label: 'نصف قطر الضوء',
    tab: 'layout',
    control: 'number',
    min: 120,
    max: 520,
    step: 20,
    default: 340,
    responsive: true,
  },
  {
    key: 'opacity',
    label: 'شدّة الضوء',
    tab: 'layout',
    control: 'number',
    min: 0,
    max: 1,
    step: 0.02,
    default: 0.16,
  },
] as const satisfies readonly PropControl[];

/**
 * خلفيّة الأشعّة — حاوية تضع خلف أبنائها ثلاث طبقات تدرّج تتحرّك بـtransform.
 * ‎duration‎ بالثواني: أبطأ = أهدأ، وصفر يوقفها عمليًّا.
 */
export const BEAM_BACKDROP_SCHEMA = [
  {
    key: 'duration',
    label: 'مدّة الدورة (ثانية)',
    tab: 'layout',
    control: 'number',
    min: 0,
    max: 40,
    step: 1,
    default: 18,
  },
  {
    key: 'intensity',
    label: 'شدّة الأشعّة',
    tab: 'layout',
    control: 'number',
    min: 0,
    max: 1,
    step: 0.05,
    default: 0.5,
  },
] as const satisfies readonly PropControl[];

/**
 * خيط تقدّم القراءة — حاوية بلا خصائص مؤلَّفة.
 *
 * المخطّط فارغ عن قصد لا سهوًا: التقدّم يُشتقّ من تمرير الحاوية نفسها، فلا
 * قيمة يحرّرها المؤلّف. وضابطٌ لا يغيّر شيئًا أسوأ من غيابه.
 */
export const TRACING_BEAM_SCHEMA = [] as const satisfies readonly PropControl[];

/**
 * عدسة التكبير — ليست حاوية: تلفّ صورةً مملوكة للمستند لا أبناءً.
 * تُعطَّل على اللمس داخل المكوّن نفسه، فلا ضابط لذلك.
 */
export const LENS_SCHEMA = [
  { key: 'image', label: 'الصورة', tab: 'content', control: 'image', default: null },
  {
    key: 'zoom',
    label: 'قوّة التكبير',
    tab: 'layout',
    control: 'number',
    min: 1.5,
    max: 4,
    step: 0.1,
    default: 2,
  },
  {
    key: 'size',
    label: 'قطر العدسة',
    tab: 'layout',
    control: 'number',
    min: 80,
    max: 320,
    step: 10,
    default: 150,
  },
] as const satisfies readonly PropControl[];

export const PROP_SCHEMAS = {
  collection: COLLECTION_SCHEMA,
  'trust-strip': TRUST_STRIP_SCHEMA,
  /*
   * حزام الثقة يتقاسم مخطّط شريط الثقة **بالمرجع لا بالنسخ** (قرار C53):
   * المكوّن واحد (`TrustStrip`) والنسق يأتي من مدخل السجل، فنسخةٌ ثانية من
   * المخطّط كانت ستخلق مصدرَي حقيقة لشيء واحد ينحرفان عند أوّل تعديل.
   */
  'trust-band': TRUST_STRIP_SCHEMA,
  'section-heading': SECTION_HEADING_SCHEMA,
  container: CONTAINER_SCHEMA,
  'highlight-grid': HIGHLIGHT_GRID_SCHEMA,
  'sticky-reveal': STICKY_REVEAL_SCHEMA,
  'spotlight-layer': SPOTLIGHT_LAYER_SCHEMA,
  'beam-backdrop': BEAM_BACKDROP_SCHEMA,
  'tracing-beam': TRACING_BEAM_SCHEMA,
  lens: LENS_SCHEMA,
  promo: PROMO_SCHEMA,
  popup: POPUP_SCHEMA,
  faq: FAQ_SCHEMA,
  card: CARD_SCHEMA,
  facts: FACTS_SCHEMA,
  tabs: TABS_SCHEMA,
  accordion: ACCORDION_SCHEMA,
} as const satisfies Record<string, readonly PropControl[]>;

export type SchemaComponentId = keyof typeof PROP_SCHEMAS;

/**
 * مفاتيح التجاوز لكل مكوّن — مشتقّة من أعلام responsive:true لا مكتوبة.
 * لا مرآة PHP اليوم: PageDocument ومرآةُ القائمة حُذفا مع الاستوديو (٢٠٢٦-٠٩-١٧)،
 * فهذا الجدول مصدرٌ وحيد بلا نظير.
 * فارغة لغير الحاويات هذه المرحلة (mobCols للمجموعات آلية قائمة تتعايش).
 */
export const RESPONSIVE_KEYS = Object.fromEntries(
  (Object.keys(PROP_SCHEMAS) as SchemaComponentId[]).map((component) => [
    component,
    (PROP_SCHEMAS[component] as readonly PropControl[])
      .filter((control) => control.responsive === true)
      .map((control) => control.key),
  ]),
) as unknown as Record<SchemaComponentId, readonly string[]>;

/**
 * مفاتيح تجاوز نسخة الرمز لكل مكوّن — **مشتقّة** من أعلام tab:'content'
 * لا مكتوبة (نمط اشتقاق RESPONSIVE_KEYS نفسه — STUDIO-PHASE3-CONTRACT
 * §2.3). المرآة في PHP: PageDocument::OVERRIDABLE_KEYS (يطابقها الحارس).
 * ما ليس محتوى — style/classIds/query/anim/cond/interactions ومفاتيح
 * التخطيط — يُحرَّر في أصل الرمز ويُعرض معطّلًا مع سببه المكتوب.
 */
export const OVERRIDABLE_KEYS = Object.fromEntries(
  (Object.keys(PROP_SCHEMAS) as SchemaComponentId[]).map((component) => [
    component,
    (PROP_SCHEMAS[component] as readonly PropControl[])
      .filter((control) => control.tab === 'content')
      .map((control) => control.key),
  ]),
) as unknown as Record<SchemaComponentId, readonly string[]>;

/**
 * القيم الافتراضية لعقدة جديدة عند الإدراج من المكتبة. نسخ عميق حتى لا
 * تتشارك عقدتان كائن parts أو مصفوفة items واحدة فتتحوّر معًا.
 */
export function defaultPropsFor(component: SchemaComponentId): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const control of PROP_SCHEMAS[component]) {
    const d: unknown = control.default;
    out[control.key] = typeof d === 'object' && d !== null ? structuredClone(d) : d;
  }
  return out;
}
