/**
 * The PDP's ONE source of «which format is being sold».
 *
 * Every surface that names a format reads this: the visible price and struck
 * «was» price, the «وفّر/خصم N٪» badges, the `?fmt=` link, the add-to-cart
 * body and the no-JS buy-now form fields. Before this module each surface
 * derived its own answer, and they could disagree (the gallery badge read the
 * product-level discount while the price read the selected format; a format
 * without a price fell back to the product price but still sent its own
 * variant id). The regression tests in lib/__tests__/pdp-selection.test.mjs
 * pin that they cannot.
 *
 * The server stays the authority: the cart line is priced by
 * CartService::addItem from the variant's offer; this module only decides
 * what the page SHOWS and which ids it SENDS, never an amount it charges.
 */

/** The fields of a variant this module reads (a subset of `VariantDto`). */
export interface SelectableVariant {
  id: number;
  slug: string;
  format?: string | null;
  purchasable: boolean;
  priceMinor?: number;
  regularMinor?: number;
  onSale?: boolean;
  discountPct?: number;
}

export interface SellPrice {
  priceMinor: number;
  regularMinor?: number;
  onSale?: boolean;
  discountPct?: number;
}

export interface Selection<V extends SelectableVariant = SelectableVariant> {
  /** More than one format — the page shows a format picker. */
  hasChoice: boolean;
  /** The chosen format, only when there is a choice. */
  variant: V | null;
  /** Sent with cart / buy-now requests; null ⇒ the product itself. */
  variantId: number | null;
  /** The price shown — the chosen format's own, or the product's without a choice. */
  price: SellPrice | null;
  /** The struck price, present only for a real sale (onSale + higher regular). */
  wasMinor: number | null;
  /** «وفّر / خصم N٪», present only alongside `wasMinor`. */
  discountPct: number | null;
  /** Add-to-cart / buy-now are offered. */
  purchasable: boolean;
}

export const FORMAT_PARAM = 'fmt';

/**
 * رابط رقاقة المصطلح (المادّة/الصفّ/الفصل) في لوحة الشراء: الفتاتُ الذي يسمّي
 * المصطلح نفسه، أو الذي ينتهي مساره بمعرّفه — أسماء الفتات تُختصر
 * («الخامس») بينما اسم المصطلح كامل («الصف الخامس الابتدائي»).
 */
export function termCrumbUrl(
  crumbs: readonly { name: string; url: string }[],
  term: { name: string; slug?: string | null } | null | undefined,
): string | undefined {
  if (!term || term.name.trim() === '') return undefined;
  const slug = term.slug?.trim();
  const hit = crumbs.slice(1, -1).find((crumb) => {
    if (crumb.url === '') return false;
    if (crumb.name === term.name) return true;
    if (!slug) return false;
    let path = crumb.url;
    try { path = decodeURIComponent(crumb.url); } catch { /* مسارٌ غير مشفَّر */ }
    return path.replace(/\/+$/, '').endsWith(`/${slug}`);
  });
  return hit?.url;
}

function isMinor(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0;
}

/** Initial selection: the `?fmt=` slug (or format code) when it names a purchasable format, else the first purchasable. */
export function initialVariantId(
  variants: readonly SelectableVariant[],
  param: string | null | undefined,
): number | null {
  const wanted = param?.trim().toLowerCase();
  const fromParam = wanted
    ? variants.find(
        (variant) =>
          variant.purchasable &&
          (variant.slug.toLowerCase() === wanted || variant.format?.toLowerCase() === wanted),
      )
    : undefined;
  return (fromParam ?? variants.find((variant) => variant.purchasable) ?? variants[0])?.id ?? null;
}

export function resolveSelection<V extends SelectableVariant>(
  variants: readonly V[],
  selectedId: number | null,
  productPrice: SellPrice | null,
): Selection<V> {
  const hasChoice = variants.length > 1;
  const variant = hasChoice ? (variants.find((candidate) => candidate.id === selectedId) ?? null) : null;

  /* With a choice, ONLY the chosen format's own price may be shown or sold. */
  const price: SellPrice | null = hasChoice
    ? variant && isMinor(variant.priceMinor)
      ? {
          priceMinor: variant.priceMinor,
          regularMinor: variant.regularMinor,
          onSale: variant.onSale,
          discountPct: variant.discountPct,
        }
      : null
    : productPrice && isMinor(productPrice.priceMinor)
      ? productPrice
      : null;

  const wasMinor =
    price !== null &&
    price.onSale === true &&
    isMinor(price.regularMinor) &&
    price.regularMinor > price.priceMinor
      ? price.regularMinor
      : null;

  const discountPct =
    wasMinor !== null && typeof price?.discountPct === 'number' && price.discountPct > 0
      ? price.discountPct
      : null;

  const purchasable = price !== null && (hasChoice ? variant?.purchasable === true : true);

  return { hasChoice, variant, variantId: variant?.id ?? null, price, wasMinor, discountPct, purchasable };
}

/** `?fmt=` link for a format, keeping every other query param (e.g. the review token). */
export function formatHref(query: readonly [string, string][], variant: SelectableVariant): string {
  const params = new URLSearchParams(query.filter(([key]) => key !== FORMAT_PARAM) as [string, string][]);
  params.set(FORMAT_PARAM, variant.slug);
  return `?${params.toString()}`;
}

/** Body of POST /api/v1/cart/items — ids only; the server prices the line. */
export function cartItemBody(
  productId: number | string,
  variantId: number | null | undefined,
): { productId: number | string; variantId?: number } {
  return variantId == null ? { productId } : { productId, variantId };
}

/** Hidden fields of the no-JS «اشترِ الآن» form (POST /api/cart/buy-now). */
export function buyNowFields(productId: number, variantId: number | null): [string, string][] {
  return variantId === null
    ? [['productId', String(productId)]]
    : [
        ['productId', String(productId)],
        ['variantId', String(variantId)],
      ];
}
