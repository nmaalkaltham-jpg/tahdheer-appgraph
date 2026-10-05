/**
 * Search API fetchers — typed 1:1 against the pre-decided search contract
 * (GET /api/v1/search, GET /api/v1/search/suggest).
 *
 * Successor: the generated @tahdheer/api-sdk (OpenAPI) replaces these hand-written
 * types + fetchers once the sdk generation pipeline lands.
 *
 * HONESTY DOCTRINE: every count/label/suggestion rendered from these types
 * comes from the API response or is ABSENT — no fabricated fallbacks.
 */
import type { ProductCardProps } from '@tahdheer/ui';
import { apiBase, apiGet, type ApiResponse } from './api';
import { mediaImage } from './media-url';
import { cardSignals } from './product-card-model';

/** ProductCard DTO per contract (camelCase; emitted by ProductCardResource). */
export interface SearchProductCard {
  id: number | string;
  sku: string;
  title: string;
  slug: string;
  url: string;
  categoryPath: string[];
  format: string;
  priceMinor: number;
  regularMinor?: number;
  discountPct?: number;
  onSale: boolean;
  saleTo?: string;
  ratingAvg?: number;
  ratingCount?: number;
  image?: { url: string; alt: string; w: number; h: number };
  badge?: { label: string };
  editable?: boolean;
  digital: true;
  /* البحث يُصدر `ProductCardResource` نفسه — فهذه تصل كما تصل للكتالوج (اختياريّة: قد تغيب). */
  resourceType?: string | null;
  publishedAt?: string | null;
  bestSeller?: boolean;
  productNature?: 'DIGITAL' | 'PHYSICAL' | 'SERVICE' | 'HYBRID' | 'ACCESS_BASED' | 'COMPOSITE' | null;
  gradeName?: string;
  semesterName?: string;
  formats?: string[];
  hasSample?: boolean;
}

export interface SearchCorrection {
  suggestion: string;
  display: string;
}

export interface SearchQueryEcho {
  raw: string;
  normalized: string;
  correction: SearchCorrection | null;
}

/** Slug or null for each extracted filter dimension. */
/** مفردةٌ، أو قائمةٌ حين اختار الزائر أكثر من قيمة في البُعد (`subject[]`). */
type FilterValue = string | string[] | null;

export interface FiltersExtracted {
  stage: FilterValue;
  grade: FilterValue;
  subject: FilterValue;
  semester: FilterValue;
  kind: FilterValue;
  format: string | null;
}

export interface SearchResults {
  items: SearchProductCard[];
  total: number;
  page: number;
  perPage: number;
}

/**
 * الطبقة الثانية — «قريبٌ ممّا تبحث عنه» (RelatedResults في الخادم).
 * `kind`: المادة والصف نفسهما بنوعٍ آخر · `grade`: المادة نفسها في صفوفٍ أخرى.
 */
export interface SearchRelatedGroup {
  reason: 'kind' | 'grade';
  label: string;
  items: SearchProductCard[];
}

export interface TypeFacet {
  key: string;
  label: string;
  count: number;
}

/** Attribute facet entry (category surfaces). Count absent ⇒ hidden, never faked. */
export interface TermFacet {
  slug: string;
  label: string;
  count?: number;
}

/**
 * توزيع أنواع المنتج — بُعدٌ مستقلّ عن `types`.
 *
 * `types` هي امتدادات الملفّ (عروض · PDF · Word) وتبقى كما هي. وهذه أنواع
 * المنتج نفسه (رقميّ · ماديّ · هجين …). خلطُهما في مفتاحٍ واحد كان يجعل
 * رابطًا محفوظًا يعني شيئًا آخر بعد النشر.
 */
export interface NatureFacet {
  key: string;
  label: string;
  count: number;
}

export interface SearchFacets {
  taxonomy: TaxonomyFacet[];
  types: TypeFacet[];
  /** غائبةٌ حين لا يوجد ما يُختار بينه — ومن خادمٍ أقدم تغيب أيضًا. */
  natures?: NatureFacet[];
  /** Optional attribute facets — rendered only when the API provides them. */
  subjects?: TermFacet[];
  semesters?: TermFacet[];
  kinds?: TermFacet[];
}

export type SearchSort = 'relevance' | 'bestselling' | 'newest' | 'price_asc' | 'price_desc';

export interface SearchRecovery {
  popular: { query: string; count: number }[];
  browse: { label: string; url: string }[];
}

export interface TaxonomyFacetValue {
  /** المصطلح كما فُهرس — وهو نفسه قيمة المرشِّح المقبولة في الطلب. */
  value: string;
  count: number;
}

export interface TaxonomyFacet {
  key: 'stage' | 'grade' | 'subject' | 'semester';
  label: string;
  values: TaxonomyFacetValue[];
}

export interface SearchResponse {
  query: SearchQueryEcho;
  filtersExtracted: FiltersExtracted;
  results: SearchResults;
  /** فارغةٌ حين تكفي النتائج، أو في غير الصفحة الأولى، أو فوق إجابةٍ مُخفَّفة. */
  related: { groups: SearchRelatedGroup[] };
  facets: SearchFacets;
  sort: SearchSort;
  zeroResult: boolean;
  recovery: SearchRecovery | null;
  meta: {
    /** يربط نقرة النتيجة بهذا الاستعلام في POST /search/click. */
    queryId: number;
    /** توقيع HMAC يثبت أن حامله تلقّى هذه الاستجابة — يمنع حقن نقرات الغرباء. */
    clickToken: string;
    /** مرشّحات أسقطها الخادم لإنقاذ صفر نتائج — تُعرض للزائر لا تُخفى. */
    relaxed: string[];
  };
}

export interface SuggestItem {
  kind: 'product';
  /** معرّف المنتج — يُرسل مع اختيار الاقتراح (`sendSuggestSelect`). */
  id?: number;
  title: string;
  url: string;
  priceMinor?: number;
}

export interface SuggestResponse {
  items: SuggestItem[];
}

/** Query params accepted by /api/v1/search (facet slugs ride as extra GET params). */
export interface SearchParams {
  q?: string;
  type?: string;
  /** §٦ — نوع المنتج، بحروفٍ صغيرة كبقيّة الأبعاد. */
  nature?: string;
  sort?: string;
  page?: number;
  /* قيمةٌ أو أكثر («أو» داخل البُعد) — انظر `lib/facet-selection`. */
  stage?: string | string[];
  grade?: string | string[];
  subject?: string | string[];
  semester?: string | string[];
  kind?: string | string[];
}

function toQueryString(params: SearchParams): string {
  const qs = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === '' || value === null) continue;
    if (Array.isArray(value)) {
      const values = value.filter((v) => v !== '');
      if (values.length === 1) qs.set(key, values[0]);
      else for (const v of values) qs.append(`${key}[]`, v);
      continue;
    }
    qs.set(key, String(value));
  }
  return qs.toString();
}

/** GET /api/v1/search — never throws; API absence is an honest, render-able state. */
export async function fetchSearch(params: SearchParams): Promise<ApiResponse<SearchResponse>> {
  /*
   * لا تخزين. كلُّ استجابة بحثٍ حدثٌ فريد (`meta.queryId` + رمزٌ موقَّع) تُربط به
   * الظهورات والنقرات والإسناد. تخزينُها ٦٠ ثانية (افتراضُ `apiGet`) كان يُعيد
   * المعرّف نفسه لزياراتٍ مختلفة — وزوّارٍ مختلفين — فتختلط أحداثهم على بحثٍ
   * واحد، ولا يُسجَّل البحث المخدوم من المخزن أصلًا (حجمُ البحث ناقص).
   */
  return apiGet<SearchResponse>(`/api/v1/search?${toQueryString(params)}`, { cache: 'no-store', next: {} });
}

/**
 * إشارة نقرة نتيجة — قياس لا معاملة.
 *
 * sendBeacon تنجو من مغادرة الصفحة (النقرة تنقل فورًا)؛ fetch keepalive
 * احتياط حيث لا beacon. الفشل يُبتلع: القياس لا يعطّل تصفّحًا أبدًا.
 */
/** إلى أين قادت نقرة النتيجة — صفحة المنتج أو زرّ السلّة على البطاقة. */
export type SearchClickDestination = 'pdp' | 'add_to_cart';

export function sendSearchClick(
  queryId: number,
  token: string,
  productId: number,
  position: number,
  destination: SearchClickDestination = 'pdp',
): void {
  beacon('/api/v1/search/click', { queryId, token, productId, position, destination });
}

/**
 * ظهوراتُ النتائج — دفعةٌ من المعرّفات التي بلغ نصفُ بطاقتها مجالَ الرؤية
 * (`SearchImpressions`). الخادم يقبل منها ما كان ضمن نتائج هذا البحث ويحسب الرتبة.
 */
export function sendSearchImpressions(queryId: number, token: string, productIds: number[]): void {
  if (productIds.length > 0) beacon('/api/v1/search/impressions', { queryId, token, productIds });
}

/**
 * اختيارُ اقتراحٍ من الإكمال التلقائيّ — يُسجَّل بحثًا من مصدر `autocomplete` ونقرةً
 * عليه. الخادم يُعيد حساب الاقتراح للنصّ نفسه ويرفض منتجًا لم يُقترح.
 */
export function sendSuggestSelect(q: string, productId: number): void {
  if (q.trim() !== '') beacon('/api/v1/search/suggest/select', { q: q.trim(), productId });
}

/** sendBeacon تنجو من مغادرة الصفحة؛ fetch keepalive احتياط. القياس لا يرمي أبدًا. */
function beacon(path: string, body: unknown): void {
  const base = apiBase();
  if (!base) return;

  const payload = JSON.stringify(body);
  const url = `${base}${path}`;

  try {
    if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
      navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
      return;
    }
    void fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload,
      keepalive: true,
      /* كعكتا الزائر والجلسة تربطان الحدث بالجلسة (بموافقة القياس وحدها). */
      credentials: 'include',
    });
  } catch {
    /* قياس فقط. */
  }
}

/**
 * GET /api/v1/search/suggest — max 3 items; empty below 2 chars (server rule).
 *
 * `init` يمرّر إلى `apiGet` فيَغلِب ميزانيّة الصفحة الافتراضيّة — وبه
 * يُمرَّر `signal` المُجهِض. وبدونه كان `SearchBox` يكتب نداءه الخام بيده
 * لأنّ الإجهاض شرطُ صحّة لا تحسين: استجابةُ «علو» البطيئة تصل بعد «علوم»
 * السريعة فتستبدل الاقتراحات الصحيحة بأقدم منها. فصار للنقطة مستهلكان
 * ينسخان مسارها ويتباعدان.
 */
export async function fetchSuggest(q: string, init?: RequestInit): Promise<ApiResponse<SuggestResponse>> {
  return apiGet<SuggestResponse>(`/api/v1/search/suggest?q=${encodeURIComponent(q)}`, init);
}

/**
 * Map the contract card DTO onto @tahdheer/ui ProductCard props — through the
 * SAME derivation as the catalog (`cardSignals`): a search hit and a catalog
 * card of the same product show the same badges, metadata and availability.
 */
export function searchCardToProps(dto: SearchProductCard): ProductCardProps {
  const signals = cardSignals(dto);
  return {
    id: dto.id,
    sku: dto.sku,
    title: dto.title,
    slug: dto.slug,
    url: dto.url,
    categoryPath: dto.categoryPath,
    format: dto.format,
    formats: signals.formats,
    resourceType: signals.resourceType,
    grade: signals.grade,
    semester: signals.semester,
    /* لا سعرَ في الردّ = لا عرضَ فعّال (لا «NaN» ولا زرّ شراءٍ حيّ). */
    priceMinor: signals.unavailable ? 0 : dto.priceMinor,
    regularMinor: dto.regularMinor,
    discountPct: dto.discountPct,
    onSale: dto.onSale === true,
    ratingAvg: dto.ratingAvg,
    ratingCount: dto.ratingCount,
    // مثل toCardProps: الأصل يُجرَّد ليعبر CORP (lib/media-url.ts).
    image: mediaImage(dto.image),
    // الشارة من العقد نصًّا فقط؛ اللون عرضٌ — كحليّ القالب على أبيض.
    badge: dto.badge
      ? { label: dto.badge.label, bg: 'var(--navy-700)', fg: 'var(--on-accent)' }
      : undefined,
    editable: dto.editable,
    bestSeller: signals.bestSeller,
    isNew: signals.isNew,
    hasSample: signals.hasSample,
    instantDownload: signals.instantDownload,
    unavailable: signals.unavailable,
  };
}
