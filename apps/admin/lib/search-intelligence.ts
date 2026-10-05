/**
 * ذكاءُ البحث — أنواع الاستجابة (`GET /admin/search/intelligence`) وعرضُ النِّسَب.
 *
 * كلُّ نسبةٍ تصل ببسطها ومقامها، وتُعرض بهما: «٥٠٪ (١ من ٢)». ومقامٌ صفر يُعرض
 * «لا بيانات» لا ٠٪ — نسبةٌ بلا مقام ليست أداءً سيّئًا، هي غير معرَّفة.
 * دوالّ نقيّة بلا React — تُختبر بـnode --test.
 */
export interface Rate {
  numerator: number;
  denominator: number;
  rate: number | null;
}

export interface QueryRow {
  query: string;
  searches: number;
  zeroResult: Rate;
  recovered: number;
  ctr: Rate;
  toCart: Rate;
  toPurchase: Rate;
  revenueMinor: number;
}

export interface ProductRow {
  productId: number;
  title: string;
  impressions: number;
  averageRank: number | null;
  ctr: Rate;
  clickToCart: Rate;
  clickToPurchase: Rate;
}

export interface SearchIntelligenceReport {
  window: { from: string; to: string };
  overview: {
    searches: number;
    uniqueSessions: number;
    consentedShare: Rate;
    zeroResult: Rate;
    impressionTracked: Rate;
    ctr: Rate;
    searchToPdp: Rate;
    noClickSearches: Rate;
    recoveryUsage: Rate;
    recoveredZeroResults: Rate;
    reformulation: Rate;
    abandonment: Rate;
    latencyMs: { avg: number | null; p50: number | null; p95: number | null; p99: number | null };
  };
  funnel: {
    consentedSearches: number;
    searchToClick: Rate;
    searchToCart: Rate;
    searchToCheckout: Rate;
    searchToPurchase: Rate;
    cartRemovals: number;
    revenueInfluencedMinor: number;
    revenueRefundedMinor: number;
    revenuePerConsentedSearchMinor: number | null;
  };
  rankCtr: (Rate & { rank: number })[];
  queries: { top: QueryRow[]; zeroResult: QueryRow[]; noClick: QueryRow[]; topConverting: QueryRow[]; highVolumeLowConversion: QueryRow[] };
  products: { highImpressionLowCtr: ProductRow[]; highClickLowPurchase: ProductRow[] };
  filtersUsed: (Rate & { dimension: string })[];
  recovery: { mode: string; searches: number; zeroResult: Rate; ctr: Rate }[];
  engines: { provider: string; searches: number; degraded: Rate; zeroResult: Rate; p50: number | null; p95: number | null }[];
  latency: { bucketMs: string; searches: number }[];
}

const pct = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });
const int = new Intl.NumberFormat('en-US');

/** «50% (1 / 2)» — أو «لا بيانات» حين لا مقام. */
export function formatRate(r: Rate | null | undefined): string {
  if (!r || r.denominator === 0 || r.rate === null) return 'لا بيانات';
  return `${pct.format(r.rate * 100)}% (${int.format(r.numerator)} / ${int.format(r.denominator)})`;
}

/** أسماءُ أنماط التعافي كما يُصدرها الخادم (`QueryPipeline::recoveryLabel`). */
export const RECOVERY_LABELS: Record<string, string> = {
  exact: 'مطابقة تامّة',
  typo: 'تصحيح إملائيّ',
  entity_typo: 'تصحيح كيان',
  unknown_token: 'إسقاط كلمة مجهولة',
  over_specified: 'تخفيف بُعدٍ زائد',
  mixed: 'تعافٍ مركّب',
  lexical_fallback: 'رجوعٌ نصّيّ',
  ai: 'فهمٌ بالذكاء',
};

export function recoveryLabel(mode: string): string {
  return RECOVERY_LABELS[mode] ?? mode;
}
