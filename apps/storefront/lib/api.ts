/**
 * Minimal API access layer for the storefront skeleton.
 *
 * HONESTY DOCTRINE: every number/list on the storefront comes from the real
 * API (NEXT_PUBLIC_API_URL) or is ABSENT. When the API is unconfigured,
 * unreachable, or returns nothing, callers render the explicit empty state —
 * never fabricated fallback data.
 */
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import type { ProductCardProps } from '@tahdheer/ui';
import { budget } from './fetch-budget';
import { mediaImage } from './media-url';
import { cardSignals } from './product-card-model';

/** Base URL of the Laravel API (no trailing slash), or null when unconfigured. */
export function apiBase(): string | null {
  /*
   * على الخادم يُفضَّل INTERNAL_API_URL حين يوجد: داخل الحاوية يشير
   * NEXT_PUBLIC_API_URL إلى عنوان المتصفّح (127.0.0.1 المنشور على المضيف)
   * وهو داخلها الحاوية نفسها، فيفشل كل نداء خادميّ. العنوان الداخلي
   * (api-web:8080) يعمل داخل الشبكة، والمتصفّح يبقى على العنوان المخبوز.
   */
  const raw =
    typeof window === 'undefined'
      ? (process.env.INTERNAL_API_URL ?? process.env.NEXT_PUBLIC_API_URL)
      : process.env.NEXT_PUBLIC_API_URL;
  if (!raw) return null;
  return raw.replace(/\/+$/, '');
}

/**
 * Encode a dynamic route segment for an API path.
 *
 * Next hands route params through PERCENT-ENCODED, so calling
 * `encodeURIComponent` on them double-encodes any non-ASCII slug: an Arabic
 * product or stage slug then 404s even though the row exists. Decoding first is
 * idempotent for plain-ASCII slugs, so this is safe for every segment.
 */
export function encodeSlug(segment: string): string {
  let raw = segment;
  try {
    raw = decodeURIComponent(segment);
  } catch {
    // Malformed percent sequence — treat the value as literal.
  }
  return encodeURIComponent(raw);
}

/**
 * Decode a route param back to the raw value stored in the database.
 *
 * The mirror of `encodeSlug`: Next hands params through percent-encoded, so an
 * Arabic slug must be decoded before it is COMPARED against a DB value or shown
 * to a reader. Idempotent for plain-ASCII slugs.
 */
export function decodeSlug(segment: string): string {
  try {
    return decodeURIComponent(segment);
  } catch {
    return segment;
  }
}

/**
 * Revalidation window for PUBLIC catalog reads. Short enough that a published
 * product or a corrected count appears without a redeploy, long enough that the
 * storefront still serves prerendered HTML.
 */
export const PUBLIC_REVALIDATE_SECONDS = 60;

export interface ApiResponse<T> {
  data: T | null;
  /** HTTP status; 0 = API unconfigured or unreachable. */
  status: number;
}

/** GET a JSON resource. Never throws — absence is a normal, honest state. */
export async function apiGet<T>(path: string, init?: RequestInit): Promise<ApiResponse<T>> {
  const base = apiBase();
  if (!base) return { data: null, status: 0 };
  try {
    const res = await fetch(`${base}${path}`, {
      signal: budget('page'),
      headers: { Accept: 'application/json' },
      // EXPLICIT cache policy (MIP §3.5: cache only with explicit invalidation).
      // Without one, Next's Data Cache froze catalog responses into the
      // prerendered HTML and kept serving them across rebuilds — new fields
      // (stage grade ranges, product counts) silently never appeared. A short
      // revalidate window keeps public pages statically fast while letting real
      // catalog changes surface; outbox-driven tag invalidation replaces this
      // window later. Private calls (cart, checkout, account) pass
      // `cache: 'no-store'` and MUST keep doing so.
      next: { revalidate: PUBLIC_REVALIDATE_SECONDS },
      ...init,
    });
    if (!res.ok) return { data: null, status: res.status };
    return { data: (await res.json()) as T, status: res.status };
  } catch {
    return { data: null, status: 0 };
  }
}

/**
 * GET a collection endpoint that wraps its payload in Laravel's `{ data: [...] }`
 * resource envelope (the catalog dimensions do; the flat home endpoints do not).
 * Returns an empty array rather than null so callers render an honest empty
 * state instead of branching on two different "nothing" shapes.
 */
export async function apiGetCollection<T>(path: string): Promise<{ data: T[]; status: number }> {
  const res = await apiGet<{ data?: T[] } | T[]>(path);
  if (!res.data) return { data: [], status: res.status };
  const payload = Array.isArray(res.data) ? res.data : (res.data.data ?? []);
  return { data: payload, status: res.status };
}

/**
 * GET a single-resource endpoint that wraps its payload in Laravel's
 * `{ data: {...} }` resource envelope (every JsonResource does). Using plain
 * `apiGet` on those returns the WRAPPER, which is truthy — so a page renders
 * its shell with every field undefined instead of 404-ing. That failure is
 * silent, which is why this helper exists.
 */
export async function apiGetResource<T>(path: string): Promise<ApiResponse<T>> {
  const res = await apiGet<{ data?: T } | T>(path);
  if (!res.data) return { data: null, status: res.status };
  const payload = res.data as { data?: T };
  const unwrapped = payload && typeof payload === 'object' && 'data' in payload ? payload.data : (res.data as T);
  return { data: unwrapped ?? null, status: res.status };
}

/** Map the API DTO onto ProductCard props (both camelCase — MIP §4.2). */
export function toCardProps(dto: ProductCardDto): ProductCardProps {
  /*
   * ما يُشتقّ يُشتقّ في `lib/product-card-model` (يُختبر وحده، ويمرّ به البحث
   * أيضًا): «جديد» من تاريخ النشر، والصيغ من صيغ المجموعة، و«تحميل فوري»
   * بقاعدة صفحة المنتج، والصفّ والفصل والعيّنة من الخادم. وما غاب يبقى غائبًا.
   */
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
    /* لا سعرَ في الردّ = لا عرضَ فعّال: البطاقة تُخفي السعر وتعطّل الزرّ (`unavailable`). */
    priceMinor: signals.unavailable ? 0 : dto.priceMinor,
    regularMinor: dto.regularMinor,
    discountPct: dto.discountPct,
    onSale: dto.onSale === true,
    ratingAvg: dto.ratingAvg,
    ratingCount: dto.ratingCount,
    // الرابط المطلق للوسائط يُجرَّد إلى مسارٍ على أصل المتجر — وإلّا حجبته
    // سياسة CORP في المتصفّح. التفصيل في lib/media-url.ts.
    image: mediaImage(dto.image),
    badge: dto.badge,
    editable: dto.editable,
    bestSeller: signals.bestSeller,
    isNew: signals.isNew,
    hasSample: signals.hasSample,
    instantDownload: signals.instantDownload,
    unavailable: signals.unavailable,
  };
}

