/**
 * Review types — transcribed 1:1 from the Reviews API
 * (GET/POST /api/v1/products/{id}/reviews, POST /api/v1/reviews/{id}/votes).
 *
 * حدٌّ موثّق: هذه الأنواع منسوخةٌ يدويًّا؛ حين تُولَّد @tahdheer/api-sdk من OpenAPI
 * تحلّ محلّها (تُحذف من هنا لا تُنسخ ثانيةً).
 *
 * HONESTY DOCTRINE: optional fields are optional because the API OMITS them
 * when the data does not exist. Never default them to a placeholder — an
 * absent field must render as absent, not as zero or an invented string.
 */

/** One of the 6 sanctioned criteria (MIP §4.7). Labels come from the API. */
interface ReviewCategoryScore {
  criterion: string;
  label: string;
  score: number;
}

interface ReviewReply {
  body: string;
  /** Always the brand — replies are signed by the store, not by a person. */
  author: string;
  publishedAt?: string | null;
}

interface ReviewMedia {
  url: string;
  alt: string;
}

export interface Review {
  id: number | string;
  rating: number;
  /** Derived from the account (family name reduced to an initial). */
  authorName: string;
  /**
   * صفة المراجِع ومدينته كما كتبهما لحظةَ التقييم — «معلّمة علوم» و«الرياض».
   *
   * مستقلّان ويُسقَط كلٌّ منهما وحده: الخادم يبثّ ما كُتب فقط، فتركيبهما
   * («معلّمة علوم، الرياض») قرارُ تصيير لا حقيقةٌ مخزَّنة. وقد كانا يصلان
   * من الخادم ولا يقرؤهما أحد، فيكتبهما المراجِع ولا يراهما قارئ.
   */
  authorRole?: string;
  authorCity?: string;
  /** True ONLY when a real paid order backs the review. */
  verifiedPurchase: boolean;
  helpfulCount: number;
  createdAt?: string | null;
  title?: string;
  body?: string;
  /** The edition the reviewer actually bought, when recorded. */
  variantPurchased?: string;
  categoryScores?: ReviewCategoryScore[];
  reply?: ReviewReply;
  media?: ReviewMedia[];
}

/** One distribution bar. Always all five, high to low. */
interface ReviewBar {
  stars: number;
  count: number;
  /** Largest-remainder percentage; the five always sum to exactly 100. */
  pct: number;
}

interface ReviewAggregateCategory {
  criterion: string;
  label: string;
  avg: number;
  count: number;
}

export interface ReviewAggregate {
  avgRating: number;
  count: number;
  recommendPct: number | null;
  /** AggregateRating structured data is permitted only when true (≥3 reviews). */
  schemaEligible: boolean;
  distribution: ReviewBar[];
  categoryScores?: ReviewAggregateCategory[];
}

type ReviewSort = 'helpful' | 'newest' | 'highest' | 'lowest';

export interface ReviewsPayload {
  items: Review[];
  /** null ⇒ no approved reviews yet. NOT a zeroed summary. */
  aggregate: ReviewAggregate | null;
  /** Always describes the whole approved set, never the filtered list. */
  distribution: ReviewBar[];
  sort: ReviewSort;
  pageInfo: { nextCursor: string | null };
}

export interface ReviewsQuery {
  sort?: ReviewSort;
  stars?: number | null;
  q?: string;
  cursor?: string | null;
}

export interface SubmitReviewInput {
  rating: number;
  title?: string;
  body: string;
  /** The reviewer's own words — ReviewController accepts both (max 120 / 80). */
  authorRole?: string;
  authorCity?: string;
  categoryScores?: Record<string, number>;
}

export interface SubmittedReview {
  status: 'pending' | 'approved' | 'rejected';
  message: string;
  review: {
    id: number | string;
    rating: number;
    title: string | null;
    body: string | null;
    verifiedPurchase: boolean;
    createdAt?: string | null;
  };
}

export interface VoteResult {
  reviewId: number | string;
  helpfulCount: number;
  voted: true;
}

/**
 * Every fetcher returns this. `status: 0` means the API is unconfigured or
 * unreachable — the caller renders the honest error/NOT_CONFIGURED state
 * rather than substituting data.
 */
export interface ReviewsResult<T> {
  data: T | null;
  status: number;
  /** Server-supplied Arabic message on a refusal; never invented client-side. */
  message?: string;
  /** Typed refusal reason from the domain (not_purchased, already_voted, …). */
  reason?: string;
}
