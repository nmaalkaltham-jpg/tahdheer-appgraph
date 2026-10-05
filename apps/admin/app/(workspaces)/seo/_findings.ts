// UNIT: _FINDINGS
// FILE: _findings.ts
// STYLE: none
// PARENT: DERIVE, FEEDS, PAGE-ANALYTICS-OS-AI, PAGE-ANALYTICS-OS-OPPS, _SECTION-OVERVIEW
// USES: LIB-ADMIN-API, LIB-FORMAT, _KIT
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * اشتقاق الفرص والمخاطر من قياسات حقيقية فقط.
 *
 * كل «فرصة» هنا مشتقّة من ردّ نقطة نهاية قائمة: مزوّد خريطة خامل بسببه
 * المعلن من الخادم، منتجات استبعدتها سياسة الفهرسة، استعلامات بلا نتائج
 * في السجل، صفحات يتيمة قاسها الزاحف، أو أصل موقع غير مضبوط. لا فرصة
 * مُختلَقة ولا درجة مُركَّبة: ترجيح الأثر يحتاج نقرات وإيرادًا لكل صفحة،
 * وهما غير موصولين — فتُعلن الدرجة «بيانات غير كافية» بدل رقم يوهم بالدقة.
 *
 * وثلاثة صفوف خطورة لا اثنان: «حرِج» و«تحذير» و«لا ينطبق». الأخير لبند
 * لا مصدر له أصلًا؛ يُعرض عدده «—» لا صفرًا، لأن الصفر يُقرأ «لا أخطاء»
 * والحقيقة «لا بيانات».
 */

import type { Paginated, ProductRow, SearchQueriesReport, SynonymRow } from '../../../lib/admin-api';
import { dateTime } from '../../../lib/format';
import type {
  CrawlPayload,
  IconName,
  LlmsCatalog,
  RobotsPolicy,
  SeoSectionKey,
  SitemapIndex,
} from './_kit';

export interface ScoreFactor {
  name: string;
  /** هل هذا العامل مقيس محليًا؟ غير المقيس يُسمّى ما ينقصه. */
  measured: boolean;
  note: string;
}

/**
 * إجراء مباشر على بند: إمّا وجهة حقيقية موجودة في هذه اللوحة (مسار أو قسم)،
 * وإمّا زرّ معطَّل يقول لماذا لا وجهة له. لا زرّ ثالث يبدو صالحًا ولا يفعل شيئًا.
 */
export interface FindingAction {
  label: string;
  icon: IconName;
  /** مسار داخل اللوحة — تحقّق من وجوده قبل إضافته. */
  href?: string;
  /** قسم داخل مساحة SEO نفسها. */
  section?: SeoSectionKey;
  /** وجوده يعني أن الإجراء معطَّل، ونصّه هو السبب المعروض للمشغّل. */
  blockedReason?: string;
}

export interface Finding {
  id: string;
  title: string;
  severity: 'risk' | 'gap' | 'na';
  category: string;
  /** مصدر القياس كما في اللوح: «فحص داخلي» أو تكامل مسمّى أو «لا مصدر». */
  source: string;
  /**
   * عدد الوحدات المتأثرة. null للبند بلا مصدر — يُعرض «—» ولا يُعرض صفرًا،
   * فالصفر هنا كذبة: يقول «لا أخطاء» بينما الحقيقة «لا قياس».
   */
  count: number | null;
  /** الحقيقة المرصودة — وحدها تُعرض بشارة «مقاس». */
  measured: string;
  /** جُمل قياس حقيقية — كل واحدة رقم جاء من الخادم. */
  evidence: string[];
  /** القاعدة التي أطلقت الاكتشاف، بصيغة قابلة للتدقيق. */
  rule: string;
  action: string;
  actions: FindingAction[];
  risks: string;
  urls: string[];
  factors: ScoreFactor[];
  /** سبب استبعاد البند من أي حساب صحّة — يُعرض للبنود بلا مصدر. */
  blocked?: string;
}

export const SEVERITY_LABELS: Record<Finding['severity'], string> = {
  risk: 'حرِج',
  gap: 'تحذير',
  na: 'لا ينطبق',
};

/** العوامل الخمسة التي يُفكَّك بها التقدير في اللوح — بحقيقة قياس كل واحد. */
function factorsFor(localEvidence: string, measured = true): ScoreFactor[] {
  return [
    { name: 'الدليل المحلي', measured, note: localEvidence },
    { name: 'الأثر على النقرات', measured: false, note: 'يحتاج Search Console — لا مصدر نقرات مرتبطة بصفحة' },
    { name: 'القيمة التجارية', measured: false, note: 'يحتاج ربط الإيراد بالصفحة — لا تحليلات موصولة' },
    { name: 'إشارة الطلب', measured: false, note: 'يحتاج حجم البحث الخارجي — غير متوفر محليًا' },
    { name: 'الجهد', measured: false, note: 'تقدير بشري — لا يُحسب آليًا ولا يُخترع' },
  ];
}

/** تطبيع العنوان للمقارنة: فراغات موحّدة وتشكيل محذوف وحالة موحّدة. */
function normalizeTitle(title: string): string {
  return title
    .replace(/[ً-ْٰ]/g, '')
    .replace(/[أإآ]/g, 'ا')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();
}

export function deriveFindings(input: {
  sitemap: SitemapIndex | null;
  llms: LlmsCatalog | null;
  robots: RobotsPolicy | null;
  queries: SearchQueriesReport | null;
  synonyms: SynonymRow[] | null;
  crawl: CrawlPayload | null;
  products: Paginated<ProductRow> | null;
}): Finding[] {
  const out: Finding[] = [];

  if (input.robots && input.robots.sitemap === null) {
    out.push({
      id: 'site-origin',
      title: 'أصل الموقع العام غير مضبوط',
      severity: 'risk',
      category: 'الفهرسة',
      source: 'فحص داخلي',
      count: 1,
      measured: 'سياسة الخادم تعيد sitemap = null و host = null',
      evidence: [
        'ردّ /api/v1/robots يعيد sitemap = null و host = null.',
        'كل رابط مطلق يصدره نطاق SEO يعود مسارًا نسبيًا بدل عنوان كامل.',
        // القياس عن سياسة الخادم لا عن الملفّ المخدوم: المتجر يكتب هويّته بنفسه.
        'الملفّ المخدوم قد يحمل مضيفًا وخريطةً رغم ذلك: apps/storefront/app/robots.ts يتقدّم بأصله (NEXT_PUBLIC_SITE_URL).',
      ],
      rule: 'SiteIdentity::isConfigured() === false',
      action:
        'اضبط الإعداد seo.site_url بأصل المتجر العام: بدونه تبقى كلّ روابط الخادم المطلقة نسبيةً، وهذا الصمت متعمَّد لأن تخمين النطاق يفسد كل رابط قانوني. ولا يعني هذا أنّ ‎/robots.txt‎ المخدوم بلا مضيف — المتجر يكتبه من أصله؛ افتحه على نطاق المتجر للتحقّق.',
      actions: [
        { label: 'افحص سياسة robots', icon: 'shield', section: 'index' },
        {
          label: 'اضبط أصل الموقع',
          icon: 'globe',
          blockedReason: 'لا حقل في اللوحة يكتب seo.site_url — يُضبط من إعدادات الخادم لا من هنا.',
        },
      ],
      risks: 'ضبط أصل خاطئ أسوأ من غيابه: canonical مطلق خاطئ يمكن أن يُخرج صفحات من الفهرس.',
      urls: ['/api/v1/robots'],
      factors: factorsFor('حقل واحد غير مضبوط يعطّل كل الروابط المطلقة'),
    });
  }

  const dormant = input.sitemap?.providers.filter((provider) => !provider.enabled) ?? [];
  for (const provider of dormant) {
    out.push({
      id: `sitemap-${provider.provider}`,
      title: `مزوّد خريطة خامل: ${provider.label}`,
      severity: 'gap',
      category: 'خرائط الموقع',
      source: 'فحص داخلي',
      count: 1,
      measured: `الخادم يعلن enabled = false للمزوّد ${provider.provider}`,
      evidence: [
        `الخادم يعلن enabled = false للمزوّد ${provider.provider}.`,
        `السبب المسجَّل: ${provider.reason ?? 'لم يُصرَّح به'}`,
        'خريطة هذا المزوّد تعود مصفوفة فارغة بصدق، لا روابط بديلة.',
      ],
      rule: `GET /api/v1/sitemap/index → providers[provider="${provider.provider}"].enabled === false`,
      action:
        'لا يُفعَّل المزوّد من هنا: تفعيله نتيجة بناء مسارات نطاقه، لا مفتاحًا في اللوحة. عالج السبب المعلن أعلاه ثم يُفعّل نفسه.',
      actions: [
        { label: 'افتح مركز الخرائط', icon: 'map', section: 'index' },
        {
          label: 'فعّل المزوّد',
          icon: 'bolt',
          blockedReason:
            'التفعيل ليس مفتاحًا: المزوّد يُفعّل نفسه حين تُبنى مسارات نطاقه، ولا نقطة نهاية تقلبه يدويًا.',
        },
      ],
      risks: 'إدراج روابط لمسارات غير مبنية يصنع 404 داخل خريطة الموقع، وهو أسوأ من غياب المزوّد.',
      urls: [provider.url],
      factors: factorsFor(`مزوّد واحد خامل من ${input.sitemap?.providers.length ?? 0}`),
    });
  }

  const coverage = input.llms?.coverage;
  if (coverage && coverage.published > 0 && coverage.included < coverage.published) {
    const excluded = coverage.published - coverage.included;
    out.push({
      id: 'llms-coverage',
      title: `${excluded} منتجًا منشورًا خارج فهرس الكتالوج`,
      severity: 'risk',
      category: 'قابلية الفهرسة',
      source: 'فحص داخلي',
      count: excluded,
      measured: `منشور ${coverage.published} · مُدرَج ${coverage.included}`,
      evidence: [
        `منشور: ${coverage.published} · مُدرَج: ${coverage.included}`,
        `التغطية المعلنة من الخادم: ${coverage.pct === null ? 'غير مقيسة' : `${coverage.pct}%`}`,
        'الاستبعاد قرار سياسة الفهرسة نفسها التي تحكم الخرائط — ليس خللًا في الفهرس.',
      ],
      rule: 'GET /api/v1/llms-data → coverage.included < coverage.published',
      action:
        'افحص كل منتج مستبعَد في قسم «الفهرسة والزحف» عبر مفتّش الروابط: الحكم والسبب والقاعدة تُعرض كما سجّلها الخادم.',
      actions: [
        { label: 'افتح مفتّش الفهرسة', icon: 'search', section: 'index' },
        { label: 'افتح استوديو المنتج', icon: 'cart', href: '/studio/catalog' },
      ],
      risks: 'رفع منتج ناقص إلى الفهرس لا يرفع ترتيبه؛ السياسة تستبعده لسبب مذكور، ومعالجة السبب هي العمل.',
      urls: ['/api/v1/llms-data'],
      factors: factorsFor(`${excluded} منتجًا مستبعدًا من ${coverage.published}`),
    });
  }

  if (coverage && coverage.included > 0 && coverage.withOneLiner < coverage.included) {
    const missing = coverage.included - coverage.withOneLiner;
    out.push({
      id: 'llms-oneliner',
      title: `${missing} منتجًا بلا ملخّص سطر واحد`,
      severity: 'gap',
      category: 'المحتوى',
      source: 'فحص داخلي',
      count: missing,
      measured: `مُدرَج ${coverage.included} · بملخّص ${coverage.withOneLiner}`,
      evidence: [
        `مُدرَج: ${coverage.included} · بملخّص: ${coverage.withOneLiner}`,
        'الملخّص يُقرأ من short_desc أو من الإجابة السريعة، وأيّهما فارغ يترك المنتج بلا سطر تعريف.',
      ],
      rule: 'GET /api/v1/llms-data → coverage.withOneLiner < coverage.included',
      action: 'أضف وصفًا مختصرًا للمنتجات الناقصة من مساحة المنتجات — الحقل نفسه يغذّي llms.txt والوصف التعريفي.',
      actions: [
        { label: 'افتح ذكاء المنتجات', icon: 'cart', section: 'products' },
        { label: 'أعد كتابة الوصف', icon: 'doc', href: '/studio/catalog' },
      ],
      risks: 'الملخّص يجب أن يصف ما في الملف فعلًا؛ ملخّص جذّاب لمحتوى غير موجود يخالف حواجز الأمان.',
      urls: ['/api/v1/llms-data'],
      factors: factorsFor(`${missing} منتجًا بلا ملخّص من ${coverage.included}`),
    });
  }

  if (input.queries && input.synonyms) {
    const mapped = new Set(input.synonyms.map((row) => row.term.trim()));
    const unmapped = input.queries.zeroResultQueries.filter((row) => !mapped.has(row.query.trim()));

    for (const row of unmapped.slice(0, 5)) {
      out.push({
        id: `zero-${row.query}`,
        title: `استعلام بلا نتائج ولا مرادف: «${row.query}»`,
        severity: 'risk',
        category: 'البحث الداخلي',
        source: 'فحص داخلي',
        count: row.count,
        measured: `${row.count} بحثًا فاشلًا في ${input.queries.window.days} يومًا`,
        evidence: [
          `تكرّر ${row.count} مرة في نافذة ${input.queries.window.days} يومًا.`,
          `آخر ظهور: ${dateTime(row.lastSeenAt)}`,
          'لا مدخل مرادفات يطابق هذا المصطلح في سجل المرادفات.',
        ],
        rule: 'search_queries.zero_result = true AND NOT EXISTS synonym(term = query)',
        action:
          'إمّا إضافة مرادف يربطه بمصطلح الكتالوج الصحيح، وإمّا الإقرار بأن الطلب على محتوى غير موجود — وكلاهما قرار صريح.',
        actions: [
          { label: 'افتح جسر المرادفات', icon: 'link', section: 'search' },
          { label: 'افتح سجل البحث', icon: 'search', href: '/search' },
        ],
        risks: 'المرادف يوسّع الاسترجاع لكل الزوار؛ مرادف خاطئ يلوّث نتائج صحيحة، والمصدر «يدوي» لا يُبدَّل آليًا.',
        urls: [],
        factors: factorsFor(`${row.count} بحثًا فاشلًا في ${input.queries.window.days} يومًا`),
      });
    }
  }

  /*
   * الصفحات اليتيمة: قياس حقيقي من مخزن الزحف الموصول (GET /admin/crawl).
   * صفحة تُجيب 200 ولا يشير إليها رابط داخلي واحد لا يصلها زائر، ويصلها
   * الزاحف من خريطة الموقع وحدها.
   */
  const crawl = input.crawl?.data ?? null;
  if (crawl && crawl.totals.orphans > 0) {
    out.push({
      id: 'crawl-orphans',
      title: `${crawl.totals.orphans} صفحة يتيمة بلا روابط داخلية`,
      severity: 'risk',
      category: 'الروابط الداخلية',
      source: 'فحص داخلي',
      count: crawl.totals.orphans,
      measured: `${crawl.totals.orphans} صفحة يتيمة من ${crawl.totals.pages} صفحة زُحفت`,
      evidence: [
        `جولة الزحف #${crawl.runId} قاست ${crawl.totals.pages} صفحة و${crawl.totals.links} رابطًا داخليًا.`,
        `منها ${crawl.totals.orphans} صفحة تُجيب 200 وعدد روابطها الواردة صفر.`,
        crawl.finishedAt
          ? `انتهت الجولة: ${dateTime(crawl.finishedAt)}`
          : 'لم يسجّل الخادم وقت انتهاء لهذه الجولة.',
      ],
      rule: 'GET /admin/crawl → crawled_pages.status = 200 AND inbound = 0',
      action:
        'افتح خريطة الروابط الداخلية واربط كل صفحة يتيمة من صفحة ذات صلة. الربط قرار تحريري: رابط من سياق غير ذي صلة يضرّ القارئ ولا ينفع الفهرسة.',
      actions: [
        { label: 'افتح خريطة الروابط', icon: 'link', href: '/crawl' },
        {
          label: 'اقترح روابط',
          icon: 'bolt',
          blockedReason: 'لا محرّك اقتراح روابط: لا نقطة نهاية تقترح مراسي داخلية، والاقتراح الآلي هنا سيكون تخمينًا.',
        },
      ],
      risks: 'الربط لأجل الربط يصنع مراسي عامّة بلا معنى؛ الزاحف يقرأ الرابط، والقارئ يقرأ سياقه.',
      urls: crawl.orphans.slice(0, 10).map((row) => row.path),
      factors: factorsFor(`${crawl.totals.orphans} صفحة يتيمة من ${crawl.totals.pages} مزحوفة`),
    });
  }

  /*
   * عناوين مكرّرة: اشتقاق محلي بحت من صفوف المنتجات المحمّلة أصلًا. لا يمنع
   * الفهرسة، لكنه يُضعف التمييز في النتائج.
   */
  if (input.products) {
    const groups = new Map<string, ProductRow[]>();
    for (const product of input.products.data) {
      const key = normalizeTitle(product.title);
      if (key === '') continue;
      const bucket = groups.get(key);
      if (bucket) bucket.push(product);
      else groups.set(key, [product]);
    }
    const collisions = [...groups.values()].filter((rows) => rows.length > 1);
    const affected = collisions.reduce((sum, rows) => sum + rows.length, 0);

    if (collisions.length > 0) {
      out.push({
        id: 'duplicate-titles',
        title: 'عناوين مكرّرة',
        severity: 'gap',
        category: 'المحتوى',
        source: 'فحص داخلي',
        count: affected,
        measured: `${affected} منتجًا في ${collisions.length} مجموعة عنوان متطابق`,
        evidence: [
          `${affected} منتجًا يتقاسم ${collisions.length} عنوانًا بعد تطبيع الفراغات والتشكيل والهمزات.`,
          `أمثلة: ${collisions
            .slice(0, 3)
            .map((rows) => `«${rows[0]?.title ?? '—'}» ×${rows.length}`)
            .join(' · ')}`,
          `المقارنة على ${input.products.data.length} صفًا حُمّلت من قائمة المنتجات — لا على الكتالوج كاملًا إن تجاوز الصفحة.`,
        ],
        rule: 'GET /admin/products?perPage=100 → GROUP BY normalize(title) HAVING COUNT(*) > 1',
        action:
          'ميّز كل عنوان بما يفرّقه فعلًا (الصف أو المادة أو الفصل). لا يمنع التكرار الفهرسة، لكنه يترك محرك البحث يختار نيابةً عنك أيّها يعرض.',
        actions: [
          { label: 'افتح القائمة', icon: 'cart', href: '/studio/catalog' },
          { label: 'افتح ذكاء المنتجات', icon: 'doc', section: 'products' },
        ],
        risks: 'لا تُفرّق العناوين بحشو كلمات مفتاحية: العنوان يصف المنتج للقارئ أولًا، والتمييز المصطنع يضرّ الاثنين.',
        urls: collisions.flatMap((rows) => rows.map((row) => `/products/${row.slug}`)).slice(0, 10),
        factors: factorsFor(`${affected} منتجًا بعنوان متكرّر من ${input.products.data.length}`),
      });
    }
  }

  /*
   * بنود بلا مصدر: تُعرض ولا تُخفى، وعدّادها «—». إخفاؤها يجعل القائمة تبدو
   * أنظف مما هي، وعرضها صفرًا يجعلها تبدو سليمة — وكلاهما كذب مختلف.
   */
  out.push({
    id: 'merchant-listing',
    title: 'أخطاء Merchant Listing',
    severity: 'na',
    category: 'تكاملات خارجية',
    source: 'لا مصدر',
    count: null,
    measured: 'لا قياس — لا تكامل Merchant Center في هذا النظام',
    evidence: [
      'لا تكامل مع Merchant Center: لا نقطة نهاية ولا بيانات اعتماد في هذا النظام.',
      'العدد يُعرض «—» لا صفرًا، لأن الصفر يُقرأ «لا أخطاء» والحقيقة «لا بيانات».',
    ],
    rule: 'لا مصدر — لا قاعدة تُقيَّم',
    action:
      'ربط Merchant Center يحتاج حساب تاجر وبيانات OAuth ونقطة نهاية تقرأ حالة العروض. لا شيء من ذلك موجود، فلا رقم يُعرض.',
    actions: [
      {
        label: 'كيف يُربَط؟',
        icon: 'info',
        blockedReason: 'لا دليل ربط في هذا النظام ولا نقطة نهاية تكامل — الربط عمل خادم لا زرّ لوحة.',
      },
    ],
    risks: 'أي رقم يُكتب هنا سيكون مُختلَقًا: لا يوجد ما يُقاس.',
    urls: [],
    factors: factorsFor('لا مصدر لهذا البند — لا يُقاس محليًا', false),
    blocked: 'لا يُحتسَب في درجة الصحة حتى يوجد مصدر.',
  });

  out.push({
    id: 'hreflang-errors',
    title: 'أخطاء hreflang',
    severity: 'na',
    category: 'الدولية',
    source: 'لا مصدر',
    count: null,
    measured: 'لا قياس — لا مدقّق hreflang مجدول',
    evidence: [
      'الوسوم البديلة تُبنى لكل منتج على حدة، ولا وظيفة مجدولة تفحص التبادل والإشارة الذاتية و x-default.',
      'لا سجل لغات على مستوى المتجر: لا نقطة نهاية GET /admin/locales في هذا النظام.',
    ],
    rule: 'لا مصدر — لا مدقّق يُنتج عدد أخطاء',
    action:
      'قياس أخطاء hreflang يحتاج مدقّقًا يقرأ كل الصفحات البديلة ويقارنها متبادلةً. حتى ذلك، راجع ترجمات كل منتج من محرّره.',
    actions: [
      { label: 'راجع ترجمات المنتجات', icon: 'globe', href: '/studio/catalog' },
      {
        label: 'شغّل المدقّق الليلي',
        icon: 'shield',
        blockedReason: 'لا وظيفة تدقيق hreflang مجدولة ولا نقطة نهاية تشغّلها.',
      },
    ],
    risks: 'إعلان صفر أخطاء بلا مدقّق يمنح ثقة زائفة في أخطر جزء من البناء الدولي.',
    urls: [],
    factors: factorsFor('لا مدقّق hreflang — لا يُقاس محليًا', false),
    blocked: 'محجوب حتى يوجد مدقّق يقرأ الصفحات البديلة.',
  });

  return out;
}
