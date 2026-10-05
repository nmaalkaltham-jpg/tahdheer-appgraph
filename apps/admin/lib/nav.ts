// UNIT: LIB-NAV
// FILE: nav.ts
// STYLE: none
// PARENT: ADMIN-SHELL, PAGE-HEAD, SIDEBAR, WORKSPACE-TABS
// USES: COMPONENTS-ICONS
// API: /admin/me, /admin/navigation*, /admin/system/outbox/dead
// MAP: docs/PROJECT_MAP.md

/**
 * سجلّ التنقّل — **المصدر الوحيد** للمساحات والوجهات والصلاحيات والتسميات.
 *
 * قبله كان التعريف مكرّرًا ثلاث مرّات: `NAV_GROUPS` في القشرة للشريط،
 * و`WORKSPACES` في تبويبات المساحة، و`paletteGroups` مشتقّة من الأولى. فكان
 * مركز الاختصارات يفهرس **الجذور السبع وحدها** ولا يعرف اثنتَي عشرة وجهة
 * فرعية موجودة فعلًا، وكان إبراز المساحة النشطة يقارن `href` مطابقةً حرفية
 * فيفشل في كل مسار لا يتفرّع من جذر مساحته (`/customers` · `/crawl` ·
 * `/growth` · `/navigation` · `/diagnostics` …).
 *
 * القاعدة: كل وجهة تُعرَّف **مرّة واحدة** هنا بصلاحيتها وتسميتها، وتُقرأ في
 * ثلاثة مواضع — الشريط والتبويبات ومركز الاختصارات. إضافة وجهة جديدة تظهر في
 * الثلاثة بلا لمس أيّ منها.
 *
 * والصلاحيات على **الوجهة** لا على المساحة: «العملاء» تحتاج `customers.read`
 * و«المراجعات» `reviews.moderate` وهما في مساحة واحدة. صلاحية المساحة هي
 * صلاحية أوّل وجهة يملكها المستعمل — لا صلاحية مستقلّة تُخترع.
 */

import type { IconName } from '../components/icons';

export interface NavDestination {
  label: string;
  href: string;
  /**
   * أيقونة الوجهة في الشريط الجانبيّ.
   *
   * ليست زينة: الشريط يحمل تسعًا وعشرين وجهة في ثماني مجموعات، والمسح
   * البصريّ لقائمةٍ بهذا الطول بالنصّ وحده بطيء. والأيقونة هي أيضًا ما
   * يبقى في الوضع المصغّر حين يُطوى النصّ.
   */
  icon?: IconName;
  /** الصلاحية اللازمة، إن وُجدت — تُنقل كما كانت قبل الدمج بلا تغيير. */
  permission?: string;
  /**
   * أقسام الوجهة — الطبقة الثالثة من التنقّل.
   *
   * لا تُستعمل إلّا حين تكون الوجهة **عائلة أسطح** لا سطحًا واحدًا: استوديو
   * التصميم يحمل الصفحات والرئيسية والمظهر والسجلّات والأطلس. وجهةٌ بقسمٍ
   * واحد لا تُعطى `sections` — شريطٌ بخيار وحيد ضجيجٌ لا تنقّل.
   *
   * القسم وجهةٌ كاملة بصلاحيتها ومسارها: لا يُشترط أن يتفرّع مساره من مسار
   * أبيه — وإن كان يقع تحته اليوم بعد نقل الشجرات. التجميع هنا
   * **معرفيّ** — ما يُفتح في جلسة تصميم واحدة — لا شجريّ حسب المسار.
   */
  sections?: readonly NavDestination[];
  /**
   * مسارات تفصيلٍ تنتمي إلى هذه الوجهة بلا أن تكون تبويبًا يُنقر.
   *
   * محرّر الصفحة `/studio/:pageId` سطحُ تركيزٍ ملء الشاشة: قائمتُه انتقلت
   * إلى وجهته ولم ينتقل هو، لئلّا يرث شريط الأقسام فوق
   * كانفس هو أجوع الأسطح إلى الارتفاع. وبلا هذا الحقل كان يفقد انتماءه
   * فلا يُبرز الشريط الجانبيّ شيئًا والمستعمل لا يعرف أين هو.
   *
   * والمطابقة بأطول بادئة كالمعتاد، فلا يبتلع `/studio` أخواته: `/studio/catalog`
   * (١٥ محرفًا) يغلبه.
   */
  alsoMatches?: readonly string[];
}

interface NavWorkspace {
  id: string;
  label: string;
  /** أيقونة المجموعة — تبقى ظاهرةً وقت الطيّ. */
  icon: IconName;
  /** الوجهة الافتراضية عند اختيار المساحة من الشريط. */
  href: string;
  destinations: readonly NavDestination[];
}

export const WORKSPACES: readonly NavWorkspace[] = [
  {
    /*
     * لوحة التشغيل أوّل ما يُفتح — ومجموعةٌ بوجهةٍ واحدة عمدًا.
     *
     * لا صلاحية عليها: الصفحة تتدرّج بنفسها. من يملك `orders.read` يرى لوحة
     * الانتباه، ومن يملك `analytics.read` يرى المؤشّرات، ومن لا يملك واحدةً
     * منهما يرى سببًا مكتوبًا. وحجبُ الصفحة كلّها لفقد إحداهما يمنع مشغّلًا
     * من عملٍ يستطيعه — وهو معيار القبول المكتوب نصًّا.
     */
    id: 'home',
    icon: 'home',
    label: 'الرئيسيّة',
    href: '/dashboard',
    destinations: [{ label: 'لوحة التشغيل', icon: 'home', href: '/dashboard' }],
  },
  {
    id: 'catalog',
    icon: 'box',
    label: 'الكتالوج',
    href: '/studio/catalog',
    destinations: [
      /*
       * المسار يبقى `/studio/catalog` ولا يتغيّر: التجميع هنا **معرفيّ** لا
       * شجريّ حسب المسار (كما ينصّ عقد هذا السجلّ أعلاه)، ونقلُ المسار كان
       * يكسر كلّ رابطٍ محفوظ أو مُشارَك مقابل ترتيبٍ أجمل في شريط العنوان.
       *
       * `products.read`: القائمة تنادي `GET /admin/products`
       * (routes/api-admin.php:128-129). وزرّا النشر والسحب في الصفّ يطلبان
       * `products.publish` (140-143) — والقراءة لا تُرفَع إليهما.
       */
      { label: 'المنتجات', icon: 'box', href: '/studio/catalog', permission: 'products.read' },
    ],
  },
  {
    id: 'sales',
    icon: 'cart',
    label: 'المبيعات والعملاء',
    href: '/orders',
    destinations: [
      { label: 'الطلبات', icon: 'cart', href: '/orders', permission: 'orders.read' },
      /*
       * القضايا أوّل ما يُفتح في النوبة: «ما الذي يحتاج نظري الآن؟».
       * ولذلك تسبق ما يُتصفّح بعدها.
       */
      { label: 'القضايا', icon: 'alert', href: '/operations/cases', permission: 'orders.read' },
      { label: 'الشحن', icon: 'truck', href: '/shipping', permission: 'orders.read' },
      {
        label: 'المدفوعات',
        icon: 'card', href: '/payments',
        permission: 'orders.read',
        /*
         * الأحداث الواردة قسمٌ لا وجهةٌ مستقلّة: تُفتح من داخل المدفوعات
         * حين يُسأل «هل وصلنا هذا الحدث؟» — وهو سؤال نزاعٍ لا تصفّح.
         */
        sections: [
          { label: 'نظرة', href: '/payments', permission: 'orders.read' },
          { label: 'الأحداث الواردة', href: '/payments/webhooks', permission: 'orders.read' },
        ],
      },
      { label: 'العملاء', icon: 'users', href: '/customers', permission: 'customers.read' },
      { label: 'المراجعات', icon: 'star', href: '/reviews', permission: 'reviews.moderate' },
      { label: 'العروض والقسائم', icon: 'tag', href: '/promotions', permission: 'promotions.manage' },
    ],
  },
  {
    id: 'operations',
    icon: 'layers',
    label: 'العمليّات',
    href: '/operations/fulfillment',
    destinations: [
      /*
       * ═══ مساحةٌ جديدة: ما بين «دُفع» و«وصل» ═══
       *
       * اللوحة كلّها كانت مبنيّةً لمتجرٍ رقميّ بحت: طلبٌ يُدفع فيُمنح
       * استحقاقٌ وينتهي الأمر، فلا شيء «قيد التنفيذ» أصلًا. ولذلك لم تكن
       * هناك مساحةُ عمليّات — لا لأنّها نُسيت بل لأنّ العمل لم يكن موجودًا.
       *
       * وبعد بناء التنفيذ والمخزون والشحن والرخص والإرجاع والاشتراكات صار
       * بين الدفع والتسليم عملٌ حقيقيّ بلا سطح: يبقى غير مرئيّ حتّى يسأل
       * زبون عن طلبٍ لم يصله. ستّ وجهاتٍ تكفي لتغطيته، وهي أوّل ما يُفتح
       * صباحًا في متجرٍ يشحن.
       *
       * الترتيب ترتيب الإلحاح لا الأبجديّة: الطابور أوّلًا (ما يحتاج قرارًا
       * اليوم)، ثمّ الشحنات (ما خرج)، ثمّ المخزون (ما سينفد)، ثمّ الإرجاع
       * والاشتراكات (دوراتٌ أبطأ)، ثمّ مصادر الأصول (تُفتح عند عطل).
       *
       * والصلاحيات تتبع النطاق الأقرب ولا تُستحدث: `operations.read` جديدة
       * كانت توجب ترحيل الأدوار القائمة وتحجب المساحة عن كلّ مشغّلٍ حتّى
       * تُمنح — فالطلبات للتنفيذ والشحن والإرجاع والاشتراكات
       * (routes/api-admin.php:113-122)، والمنتجات للمخزون ومصادر الأصول.
       */
      /*
       * وجهتان بدل ستّ.
       *
       * «الشحنات» و«الإرجاع» و«المخاطر» و«مصادر الأصول» كانت وجهاتٍ مستقلّة،
       * وكلٌّ منها جدولٌ واحد يجيب السؤال نفسه: «ما العالق؟» — ويفتحها
       * المشغّل الخمس بالترتيب في كلّ نوبة. صارت تبويبات `?t=`.
       *
       * والمخزون يبقى مستقلًّا: فيه نموذج حركةٍ **يكتب**، وليس قراءةً لما علق.
       */
      { label: 'طابور العمليّات', icon: 'layers', href: '/operations/fulfillment', permission: 'orders.read' },
      { label: 'المخزون', icon: 'boxes', href: '/operations/inventory', permission: 'products.read' },
    ],
  },
  {
    id: 'visibility',
    icon: 'search',
    label: 'الظهور والبحث',
    href: '/seo',
    destinations: [
      /*
       * وجهتان بدل سبع.
       *
       * كانت «نظرة عامّة» و«البحث الداخلي» و«الزحف» و«التحويلات» و«الروابط
       * المكسورة» و«خريطة الموقع» وجهاتٍ مستقلّة تجيب سؤالًا واحدًا: «هل
       * يجدنا الناس، وهل يصلون؟». وأسوأ من العدد أنّ «منصّة SEO» كانت تُعلن
       * في نفسها قسمَي «الفهرسة والزحف» و«المحتوى والروابط» — وهما بالضبط ما
       * تفعله شاشتا «الزحف» و«الروابط المكسورة»، فيُقرأ الموضوع نفسه في
       * سطحين يُحدَّثان في لحظتين مختلفتين.
       *
       * صارت تبويبات `?t=` في سطحٍ واحد، والمسارات القديمة تحوّل إليها فلا
       * ينكسر رابطٌ محفوظ. والصلاحيات كما كانت: `analytics.read` لكلّ لوح.
       */
      { label: 'الظهور والبحث', icon: 'search', href: '/seo', permission: 'analytics.read' },
      { label: 'SEO الدولي', icon: 'globe', href: '/seo/international', permission: 'analytics.read' },
      { label: 'خريطة الموقع', icon: 'map', href: '/seo-health/sitemap', permission: 'analytics.read' },
    ],
  },
  {
    id: 'growth',
    icon: 'chart',
    label: 'التحليلات والنمو',
    href: '/analytics',
    destinations: [
      { label: 'المؤشرات', icon: 'chart', href: '/analytics', permission: 'analytics.read' },
      { label: 'نظام تشغيل النمو', icon: 'cpu', href: '/analytics/os', permission: 'analytics.read' },
      { label: 'الأداء', icon: 'gauge', href: '/performance', permission: 'analytics.read' },
    ],
  },
  {
    id: 'studio',
    icon: 'palette',
    label: 'استوديو المتجر',
    /*
     * الوجهة الافتراضية صارت «القوائم»: هي الوجهة الوحيدة الباقية في هذه
     * المساحة بعد حذف استوديو التصميم (٢٠٢٦-٠٩-١٧). `/studio/design` كان
     * يقود إلى ٤٠٤.
     */
    href: '/navigation',
    destinations: [
      /*
       * «المنتجات» عادت إلى مساحةٍ مستقلّة في ٢٠٢٦-٠٩-١٥.
       *
       * كانت هنا لسببٍ صحيحٍ وقتَه: استوديو المنتج سطحُ تحرير كإخوته، ومساحةٌ
       * بوجهةٍ واحدة تكلّف مدخلًا في الشريط بلا تبويبات. لكنّ الكتالوج بعد
       * النموذج الكونيّ لم يعد سطحَ تحريرٍ واحدًا: صيغٌ وعروضٌ وسماتٌ وحزمٌ
       * وعلاقاتٌ واكتمالُ بيانات. فصار له من الوجهات ما يملأ شريطه، وبقاؤه
       * تبويبًا في «كيف يبدو المتجر» كان يُخفي أنّه «ماذا يبيع المتجر».
       */
      /*
       * ═══ الدمج: خمسة أسطح تحت مدخلٍ واحد ═══
       *
       * كانت «الصفحات» و«الرئيسية» و«المظهر» و«أطلس الصفحات» مداخل مستقلّة
       * في شريط المساحة إلى جانب «استوديو التصميم». وهي في العمل الواقعيّ
       * سطحٌ واحد مُجزَّأ: من يحرّر صفحةً يفتح السجلّ ليختار مكوّنًا، ويفتح
       * الأطلس ليعرف هل المكوّن جاهز للاتّجاهين، ويفتح المظهر ليضبط رمزًا،
       * ثمّ يعود. خمسة مداخل متجاورة في الشريط تُخفي أنّها رحلةٌ واحدة،
       * وتجعل الشريط يقيس التنفيذ (كم صفحةً بنينا) لا العمل (ماذا يفعل
       * المصمّم).
       *
       * فصارت أقسامًا في «استوديو التصميم»، والشريط الأعلى ثلاثة مداخل:
       * ماذا تبيع (المنتجات) · كيف يبدو (استوديو التصميم) · كيف يُتنقّل فيه
       * (القوائم).
       *
       * الترتيب ترتيب الجلسة لا الأبجديّة: الصفحات أوّلًا لأنّها المقصد
       * الأكثر، ثمّ الرئيسية (صفحةٌ واحدة لها مدير خاصّ)، ثمّ المظهر
       * (الضبط)، ثمّ السجلّات (المادّة)، ثمّ الأطلس (المرجع الذي يُستشار
       * ولا يُحرَّر).
       *
       * ولا مسار تغيّر: كلّ رابطٍ محفوظٍ أو مُشارَك يبقى عاملًا. الدمج
       * تجميعُ تنقّلٍ لا إعادةَ توجيه.
       */
      /*
       * `appearance.manage` — وهي أوضح حالات العيب في هذا الملف: **كل** مسار
       * تحت `/admin/navigation*` يقع داخل مجموعة `appearance.manage` الواحدة
       * (routes/api-admin.php:407-408 وما بعدها حتى `navigation/{location}/…`)،
       * والصفحة تفتح بـ`GET /admin/navigation/{location}` فورًا. وكانت بلا
       * `permission` — والشريط يعرض كلّ وجهة بلا صلاحية للجميع
       * (admin-shell.tsx:194) — فكان مدير الطلبات ومدير المنتجات ودور
       * التحليلات يرون «القوائم» في الشريط وفي مركز الاختصارات، ولا يرون
       * منها إلّا ٤٠٣. وهي الآن أخت «استوديو التصميم» في صلاحيتها كما هي
       * أخته في الخادم.
       */
      /*
       * «الرئيسية» تعود إلى مساحتها.
       *
       * التعليق أعلاه يذكرها ضمن خمسة أسطحٍ دُمجت في «استوديو التصميم» —
       * ثمّ حُذف الاستوديو (٢٠٢٦-٠٩-١٧) فذهب السطح معه، وبقي محتوى الرئيسة
       * بلا محرّر: يُقرأ من `settings` ولا يكتبه أحد إلّا البذّار.
       *
       * و`content.manage` لا `appearance.manage`: من يضبط رموز التصميم
       * مصمّم، ومن يكتب عنوان الهيرو محرّر.
       */
      { label: 'الرئيسية', icon: 'list', href: '/home-content', permission: 'content.manage' },
      { label: 'القوائم', icon: 'list', href: '/navigation', permission: 'appearance.manage' },
    ],
  },
  {
    id: 'media',
    icon: 'image',
    label: 'الوسائط',
    href: '/media',
    destinations: [
      /*
       * `products.read`: لوح المكتبة ينادي `GET /admin/media` وهو محروس بها
       * (routes/api-admin.php:191-192) — «الأصل ملفُّ منتجٍ أينما نُظر إليه»
       * كما يقول تعليق المسار. وكانت بلا `permission` فتُعرض لدور التحليلات
       * — وهو الدور الوحيد بلا `products.read` — ثمّ يفشل نداؤها.
       */
      { label: 'المكتبة', icon: 'image', href: '/media', permission: 'products.read' },
    ],
  },
  {
    id: 'system',
    icon: 'cog',
    label: 'النظام',
    href: '/settings',
    destinations: [
      /*
       * بلا `permission` عمدًا: الصفحة تعرض هويّة المشغّل وصلاحياته من
       * `/admin/me` وحدها — ولا صلاحية على `/admin/me` (routes/api-admin.php:68)،
       * فهي تعمل لكلّ من دخل. ولوح بوّابة الدفع المضمَّن فيها ينادي
       * `GET /admin/payments/provider` بـ`orders.read`
       * (routes/api-admin.php:171-172): لوحٌ واحد يُخفى لا وجهةٌ تُحجب.
       */
      { label: 'الإعدادات', icon: 'cog', href: '/settings' },
      /*
       * مفاتيح الربط بجوار المستخدمين لا بجوار الإعدادات: كلاهما سلطةُ
       * حسابٍ لا سلطةُ تشغيل، وكلاهما للمالك وحده.
       */
      /*
       * `audit.read` لا `analytics.read`: اللوحة تسمّي أنواع الأحداث وأعدادها
       * — خريطة ما يفعله النظام، لا أرقام مبيعات. وهي القراءة المُثبَتة:
       * `GET /admin/system` و`/admin/system/outbox/dead` محروسان بها
       * (routes/api-admin.php:114-117). وزرّ إعادة الحدث
       * `POST /admin/system/outbox/{id}/replay` يطلب `users.manage` —
       * المالك وحده (routes/api-admin.php:118-119) — لأنّ الإعادة تُرسل
       * بريدًا حقيقيًّا.
       */
      { label: 'النظام', icon: 'server', href: '/system', permission: 'audit.read' },
      /*
       * الذكاء تحت «النظام» لا تحت «النمو»: هذه شاشة بنية تشغيلٍ — ذاكرةٌ
       * وطابورٌ وقاطع دائرةٍ وأزرار إيقاف — لا شاشة تحليلات. وبالصلاحية نفسها
       * التي تحرس صندوق الصادر: `audit.read` للقراءة، وأفعالها تطلب
       * `system.manage` داخلها (routes/api-admin.php).
       */
      { label: 'الذكاء', icon: 'cpu', href: '/system/ai', permission: 'audit.read' },
    ],
  },
];

interface NavMatch {
  ws: NavWorkspace;
  /** `href` الوجهة العليا النشطة — أطول مطابقة، لا أوّل بادئة. */
  active: string;
  /** الوجهة العليا نفسها — يقرأ منها الشريط الثاني أقسامَها. */
  destination: NavDestination;
  /** `href` القسم النشط داخلها، أو `null` إن لم تكن ذات أقسام. */
  activeSection: string | null;
}

/**
 * المساحة والوجهة والقسم التي ينتمي إليها مسار.
 *
 * المطابقة على **الوجهات والأقسام** لا على جذر المساحة: `/customers` ينتمي
 * إلى «المبيعات والعملاء» وجذرها `/orders` — فمطابقة الجذر وحدها كانت
 * تُسقطه. وأطول مطابقة تفوز وإلّا ابتلع `/studio` كلّ ما تحته،
 * و`/analytics` ابتلع `/analytics/os`.
 *
 * والقسم يُطابَق كوجهةٍ كاملة ثمّ يُنسب إلى أبيه. والأب يفوز
 * بطول مطابقة **ابنه** لا بطول مساره هو.
 */
export function workspaceFor(pathname: string): NavMatch | null {
  let best: {
    ws: NavWorkspace;
    dest: NavDestination;
    section: string | null;
    len: number;
  } | null = null;

  const consider = (
    ws: NavWorkspace,
    dest: NavDestination,
    section: NavDestination | null,
  ): void => {
    const target = section ?? dest;
    for (const prefix of [target.href, ...(target.alsoMatches ?? [])]) {
      const hit = pathname === prefix || pathname.startsWith(`${prefix}/`);
      if (!hit) continue;
      if (best !== null && prefix.length <= best.len) continue;
      best = { ws, dest, section: section === null ? null : section.href, len: prefix.length };
    }
  };

  for (const ws of WORKSPACES) {
    for (const dest of ws.destinations) {
      consider(ws, dest, null);
      for (const section of dest.sections ?? []) consider(ws, dest, section);
    }
  }

  if (best === null) return null;
  const hit: { ws: NavWorkspace; dest: NavDestination; section: string | null } = best;
  return {
    ws: hit.ws,
    active: hit.dest.href,
    destination: hit.dest,
    /*
     * الأقسام صارت تبويبات استعلاميّة (`?t=`) في صفحةٍ واحدة، و`usePathname`
     * لا يحمل الاستعلام — فلا قسم يُطابَق بالمسار. الحقل يبقى للوجهات ذات
     * الأقسام المسارية إن وُجدت يومًا، ويبقى `null` هنا صراحةً.
     */
    activeSection: hit.section,
  };
}

/**
 * كلّ الوجهات مسطَّحةً مع مساحتها — مركز الاختصارات يفهرسها كلّها.
 *
 * الأقسام مضمَّنة: بعد الدمج صارت أربعة أسطح مبنيّة أقسامًا لا وجهات عليا،
 * وفهرسٌ يقرأ الطبقة العليا وحدها كان سيفقدها — وهو العيب نفسه الذي وُجد
 * هذا السجلّ لإصلاحه.
 *
 * والقسم الذي يشارك أباه مساره يُذكر مرّة واحدة: مدخلان
 * بمسارٍ واحد في قائمة الأوامر يجعلان المستعمل يتردّد بين متطابقين.
 */
export function allDestinations(): readonly { ws: NavWorkspace; dest: NavDestination }[] {
  return WORKSPACES.flatMap((ws) => {
    const seen = new Set<string>();
    const out: { ws: NavWorkspace; dest: NavDestination }[] = [];
    for (const dest of ws.destinations) {
      for (const item of dest.sections === undefined ? [dest] : [dest, ...dest.sections]) {
        if (seen.has(item.href)) continue;
        seen.add(item.href);
        out.push({ ws, dest: item });
      }
    }
    return out;
  });
}
