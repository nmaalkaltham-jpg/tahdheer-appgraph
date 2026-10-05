/**
 * Bundle data access — the ONE place the bundle pages read from.
 *
 * The bundle endpoints (`GET /api/v1/bundles`, `/bundles/{slug}`) are wired;
 * until the backend ships them the calls answer 404/0 and the pages keep
 * reporting absence honestly. Previously: `bundles` / `bundle_items` are a planned
 * schema; the Laravel API exposes nothing for them today. So both fetchers
 * below report ABSENCE honestly: /bundles renders its empty state and
 * /bundles/{slug} returns a REAL 404 instead of a fabricated page.
 *
 * WIRING IS A DATA SWAP. When `GET /api/v1/bundles` and
 * `GET /api/v1/bundles/{slug}` ship, replace ONLY the two marked bodies with
 * the calls written out in their comments. Every component, every price row
 * and the owned-item exclusion already read the DTOs defined here, so no page
 * and no stylesheet changes.
 *
 * WHY THE EXCLUSION MATH IS SERVER-STATED: which items a shopper already owns,
 * and what the remaining ones cost, is an entitlements + pricing decision. If
 * this file computed it, the page would show a total the checkout would not
 * honour. `BundleDto.partial` therefore carries the server's own numbers, and
 * with it absent the page shows no owned tags and no exclusion at all.
 *
 * Interim DTOs live next to their consumer; the
 * generated @tahdheer/api-sdk supersedes them.
 */

import { apiGetCollection, apiGetResource, encodeSlug } from '../../../lib/api';

export interface BundleItemDto {
  id: number | string;
  title: string;
  slug: string;
  /** Short meta line, e.g. «PDF · قابل للتعديل». Rendered only when present. */
  meta?: string;
  /** Individual price in halalas. */
  priceMinor: number;
}

interface BundleReason {
  title: string;
  body: string;
}

/** The server's own owned-item exclusion — never derived on the client. */
interface BundlePartialPricing {
  /** Item ids the shopper already owns, as the API resolved them. */
  ownedItemIds: readonly (number | string)[];
  /** Sum of the NOT-owned items' individual prices, in halalas. */
  remainingTotalMinor: number;
  /** What the shopper actually pays for the remaining items, in halalas. */
  payableMinor: number;
  /** Purchasable id for the remaining-items purchase; absent ⇒ not buyable. */
  cartProductId?: number | string;
}

export interface BundleDto {
  id: number | string;
  slug: string;
  title: string;
  /** Editorial intro — rendered only when the API supplies it. */
  intro?: string;
  /** Bundle price in halalas. */
  priceMinor: number;
  /**
   * Sum of the items' individual prices, when the API states it. Absent ⇒
   * derived from `items` — never guessed, never padded.
   */
  itemsTotalMinor?: number;
  items: BundleItemDto[];
  /** «لماذا الحزمة» reasons — rendered only when the API supplies them. */
  why?: BundleReason[];
  /** Owned-item exclusion. Absent ⇒ nothing is claimed about what you own. */
  partial?: BundlePartialPricing;
  /**
   * The purchasable product id for the whole bundle. Absent ⇒ the bundle
   * cannot be bought yet and the page renders a visibly DISABLED control with
   * the reason, never a button that pretends to work.
   */
  cartProductId?: number | string;
}

export interface BundleCardDto {
  id: number | string;
  slug: string;
  title: string;
  priceMinor: number;
  /**
   * عدد الملفّات. الخادم يصدر المفتاحين (`BundleSummary.php`)؛ مصدرٌ لا يحمل إلّا
   * `itemsCount` أظهر «NaN ملفًّا» — فكلاهما اختياريّ، والعدد لا يُرسم إلّا صحيحًا.
   */
  itemCount?: number;
  itemsCount?: number;
  /** مجموع أسعار الملفّات منفردة — يُشطب بجوار سعر الحزمة حين يزيد عليه فقط. */
  regularMinor?: number;
  /** سطرٌ تحريريّ من الخادم؛ غائبٌ ⇒ لا سطر. */
  description?: string | null;
}

/** عدد ملفّات الحزمة كما صرّح به الخادم، أو `null` حين لا عدد صحيح. */
export function bundleFileCount(bundle: BundleCardDto): number | null {
  const count = bundle.itemCount ?? bundle.itemsCount;
  return typeof count === 'number' && Number.isInteger(count) && count >= 0 ? count : null;
}

/**
 * `not-configured` = the store has no bundle API at all (today's truth).
 * `ok` with an empty array = the API answered and there are no bundles.
 * They are different facts and the empty state says which one it is.
 */
export type BundlesResult =
  | { state: 'not-configured' }
  | { state: 'ok'; bundles: BundleCardDto[] };

/**
 * List published bundles.
 *
 * SWAP TARGET — when the endpoint exists this body becomes:
 *   const res = await apiGetCollection<BundleCardDto>('/api/v1/bundles');
 *   if (res.status === 0 || res.status === 404) return { state: 'not-configured' };
 *   return { state: 'ok', bundles: res.data };
 */
export async function fetchBundles(): Promise<BundlesResult> {
  const res = await apiGetCollection<BundleCardDto>('/api/v1/bundles');
  if (res.status === 0 || res.status === 404) return { state: 'not-configured' };
  return { state: 'ok', bundles: res.data };
}

/**
 * One bundle by slug, or null when it does not exist — the caller calls
 * `notFound()` on null, which is a real 404 with a real status code.
 *
 * SWAP TARGET — when the endpoint exists this body becomes:
 *   const res = await apiGetResource<BundleDto>(`/api/v1/bundles/${encodeSlug(slug)}`);
 *   return res.data;
 * (`encodeSlug` from lib/api handles Next's pre-percent-encoded params — a raw
 * `encodeURIComponent` double-encodes Arabic slugs and 404s a row that exists.)
 */
export async function fetchBundle(slug: string): Promise<BundleDto | null> {
  const res = await apiGetResource<BundleDto>(`/api/v1/bundles/${encodeSlug(slug)}`);
  return res.data;
}

export interface BundlePriceView {
  /** What the shopper pays, in halalas. */
  nowMinor: number;
  /** Comparison total: the same items bought individually, in halalas. */
  listMinor: number;
  /** Saving in halalas, or null when there is none to claim. */
  savingMinor: number | null;
  /** Whole-percent discount, or null when it cannot be computed honestly. */
  savingPct: number | null;
  /** Purchasable id for the price shown, or undefined when not buyable yet. */
  cartProductId?: number | string;
}

/**
 * The one price computation the page renders. With `partial` present it is the
 * server's remaining-items figures; without it, the whole bundle. Every number
 * comes from the payload — the only arithmetic is the subtraction the shopper
 * could do themselves.
 */
export function priceView(bundle: BundleDto): BundlePriceView {
  const partial = bundle.partial;
  const nowMinor = partial ? partial.payableMinor : bundle.priceMinor;
  const listMinor = partial
    ? partial.remainingTotalMinor
    : (bundle.itemsTotalMinor ?? bundle.items.reduce((sum, item) => sum + item.priceMinor, 0));

  const rawSaving = listMinor - nowMinor;
  const savingMinor = rawSaving > 0 ? rawSaving : null;

  return {
    nowMinor,
    listMinor,
    savingMinor,
    savingPct: savingMinor !== null && listMinor > 0
      ? Math.round((savingMinor / listMinor) * 100)
      : null,
    cartProductId: partial ? partial.cartProductId : bundle.cartProductId,
  };
}
