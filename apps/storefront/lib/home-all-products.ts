/**
 * «كل الملفّات» في الرئيسة — حساب الترقيم وحده، بلا React ولا شبكة
 * (يُختبر في `__tests__/home-all-products.test.mjs`).
 *
 * الصفحة الأولى تُصيَّر على الخادم، و«عرض المزيد» يجلب ما بعدها من
 * ‎GET /api/v1/search?sort=newest&page=N‎ — المسار نفسه الذي يقرؤه أرشيف
 * الكتالوج. كلّ رقمٍ هنا من الاستجابة (‎total · perPage‎)، لا ثابتٌ مكتوب.
 */

export interface AllProductsPaging {
  /** كم بطاقةً معروضة الآن. */
  shown: number;
  /** المجموع كما أعاده الخادم في آخر استجابة. */
  total: number;
  /** ما بقي — لا يقلّ عن صفر ولو زاد المعروض على مجموعٍ تناقص بين صفحتين. */
  remaining: number;
  /** رقم الصفحة التالية، أو ‎null‎ حين لا يبقى شيء. */
  nextPage: number | null;
  /** كم بطاقةً ستأتي بالخطوة التالية (حجم صفحة الخادم مقصوصًا بالباقي). */
  nextBatch: number;
  done: boolean;
}

export function allProductsPaging(shown: number, total: number, lastPage: number, perPage: number): AllProductsPaging {
  const remaining = Math.max(0, total - shown);
  const done = remaining === 0 || perPage <= 0;
  return {
    shown,
    total,
    remaining,
    nextPage: done ? null : lastPage + 1,
    nextBatch: done ? 0 : Math.min(perPage, remaining),
    done,
  };
}

/**
 * يضمّ صفحةً جديدة إلى المعروض بلا تكرار: ترتيب «الأحدث» يزيح البطاقات إن
 * نُشر منتجٌ بين صفحتين، فتعود بطاقةٌ من الصفحة السابقة — تُسقَط لا تُكرَّر.
 */
export function mergeById<T extends { id: number | string }>(current: readonly T[], incoming: readonly T[]): T[] {
  const seen = new Set(current.map((item) => String(item.id)));
  const fresh = incoming.filter((item) => {
    const key = String(item.id);
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
  return [...current, ...fresh];
}

/** رابط التصفّح بلا JavaScript: الصفحة التالية من أرشيف ‎/shop‎ نفسه. */
export function allProductsFallbackHref(shopPath: string, nextPage: number | null): string {
  return nextPage === null || nextPage <= 1 ? shopPath : `${shopPath}?page=${nextPage}`;
}

/* ── المرشّحات السريعة فوق الشبكة: «الكل» + المراحل + أنواع المنتج ── */

/** بُعدٌ واحدٌ في كلّ مرّة؛ اسم المفتاح هو نفسه معامل ‎/api/v1/search‎ ومعامل ‎/shop‎. */
export type QuickFilterKey = 'stage' | 'kind' | 'type';

export interface QuickFilter {
  key: QuickFilterKey;
  value: string;
  label: string;
  /** العدد كما أعاده الخادم في الاستجابة غير المرشَّحة — غائبٌ ⇒ لا رقم. */
  count?: number;
}

/** ما يلزم من ‎facets‎ استجابة البحث — بنيويٌّ فلا يربط هذا الملفّ بأنواع الشبكة. */
export interface QuickFacetsInput {
  taxonomy?: { key: string; values: { value: string; count: number }[] }[];
  types?: { key: string; label: string; count: number }[];
  kinds?: { slug: string; label: string; count?: number }[];
}

/**
 * الرقائق من ‎facets‎ الصفحة الأولى نفسها (بلا طلبٍ إضافيّ): المراحل من
 * ‎taxonomy.stage‎، وأنواع المنتج من ‎kinds‎ حين يرسلها الخادم (بُعد ‎/shop‎)،
 * وإلّا من ‎types‎ (صيغة الملفّ). قيمةٌ بعددٍ صفريّ لا تُعرض — رقاقةٌ تؤدّي إلى
 * شبكةٍ فارغة وعدٌ كاذب. و«الكل» في ‎types‎ ليست بُعدًا فتُسقط.
 */
export function quickFilters(facets: QuickFacetsInput | null | undefined): QuickFilter[] {
  if (!facets) return [];
  const out: QuickFilter[] = [];
  const stage = facets.taxonomy?.find((facet) => facet.key === 'stage');
  for (const { value, count } of stage?.values ?? []) {
    if (value && count > 0) out.push({ key: 'stage', value, label: value.replace(/-/g, ' '), count });
  }
  if (facets.kinds && facets.kinds.length > 0) {
    for (const kind of facets.kinds) {
      if (!kind.slug || kind.count === 0) continue;
      out.push({
        key: 'kind',
        value: kind.slug,
        label: kind.label,
        ...(typeof kind.count === 'number' ? { count: kind.count } : {}),
      });
    }
  } else {
    for (const type of facets.types ?? []) {
      if (type.key && type.key !== 'all' && type.count > 0)
        out.push({
          key: 'type',
          value: type.key,
          label: type.label,
          count: type.count,
        });
    }
  }
  return out;
}

/** معاملات الطلب للمرشّح (أو لا شيء لـ«الكل»). */
export function quickFilterParams(filter: QuickFilter | null): Partial<Record<QuickFilterKey, string>> {
  return filter ? { [filter.key]: filter.value } : {};
}

/**
 * رابط المرشّح نفسه (و«الكل» ⇒ ‎/shop‎ عارية) — «تصفّح مع الفلاتر» وبديل بلا JavaScript.
 * ‎/shop‎ لا يقرأ امتداد الملفّ (`type`)؛ صفحة البحث تقرؤه وتُظهر تبويبه نشطًا، فإليها يذهب.
 */
export function quickFilterHref(shopPath: string, filter: QuickFilter | null, searchPath = '/search'): string {
  if (!filter) return shopPath;
  const base = filter.key === 'type' ? searchPath : shopPath;
  return `${base}?${new URLSearchParams({ [filter.key]: filter.value }).toString()}`;
}
