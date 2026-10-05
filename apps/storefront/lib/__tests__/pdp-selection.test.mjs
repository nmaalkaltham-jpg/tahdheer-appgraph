/**
 * Variant-integrity invariant of the PDP (lib/pdp-selection.ts):
 * the shown price, the «was» price, the discount badge, the ?fmt= link, the
 * add-to-cart body and the buy-now form fields all name the SAME format.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  buyNowFields,
  cartItemBody,
  formatHref,
  initialVariantId,
  resolveSelection,
} from '../pdp-selection.ts';

const PRODUCT_PRICE = { priceMinor: 4500, regularMinor: 5900, onSale: true, discountPct: 24 };
const V = [
  { id: 100, slug: 'docx', format: 'DOCX', purchasable: true, priceMinor: 4500, regularMinor: 5900, onSale: true, discountPct: 24 },
  { id: 101, slug: 'pptx', format: 'PPTX', purchasable: true, priceMinor: 5500, regularMinor: 7200, onSale: true, discountPct: 24 },
  { id: 102, slug: 'pdf', format: 'PDF', purchasable: true, priceMinor: 3500 },
  { id: 103, slug: 'all', format: null, purchasable: true, priceMinor: 8900, regularMinor: 8900, onSale: true, discountPct: 10 },
  { id: 104, slug: 'xlsx', format: 'XLSX', purchasable: false, priceMinor: 2000 },
  { id: 105, slug: 'noprice', format: 'DOCX', purchasable: true },
  { id: 106, slug: 'free', format: 'PDF', purchasable: true, priceMinor: 0 },
];

test('every surface names the selected format — for each format in turn', () => {
  for (const variant of V) {
    const s = resolveSelection(V, variant.id, PRODUCT_PRICE);
    assert.equal(s.variant?.id, variant.id);
    assert.equal(s.variantId, variant.id);
    if (typeof variant.priceMinor === 'number') assert.equal(s.price?.priceMinor, variant.priceMinor);
    assert.deepEqual(cartItemBody(9001, s.variantId), { productId: 9001, variantId: variant.id });
    assert.deepEqual(buyNowFields(9001, s.variantId), [
      ['productId', '9001'],
      ['variantId', String(variant.id)],
    ]);
    assert.match(formatHref([], variant), new RegExp(`fmt=${variant.slug}$`));
  }
});

test('a format without a price is neither priced from the product nor sold', () => {
  const s = resolveSelection(V, 105, PRODUCT_PRICE);
  assert.equal(s.price, null, 'no fallback to the product price');
  assert.equal(s.purchasable, false);
  assert.equal(s.discountPct, null);
});

test('an unpurchasable format is shown but not sold', () => {
  const s = resolveSelection(V, 104, PRODUCT_PRICE);
  assert.equal(s.price?.priceMinor, 2000);
  assert.equal(s.purchasable, false);
});

test('a zero price is a real price (free file), not a missing one', () => {
  const s = resolveSelection(V, 106, PRODUCT_PRICE);
  assert.equal(s.price?.priceMinor, 0);
  assert.equal(s.purchasable, true);
});

test('discount and struck price come from the selected format only, and only for a real sale', () => {
  assert.equal(resolveSelection(V, 101, PRODUCT_PRICE).discountPct, 24);
  assert.equal(resolveSelection(V, 101, PRODUCT_PRICE).wasMinor, 7200);
  // PDF has no sale — the product-level 24% must not leak onto it.
  assert.equal(resolveSelection(V, 102, PRODUCT_PRICE).discountPct, null);
  assert.equal(resolveSelection(V, 102, PRODUCT_PRICE).wasMinor, null);
  // onSale but regular == price ⇒ no strike, no badge.
  assert.equal(resolveSelection(V, 103, PRODUCT_PRICE).wasMinor, null);
  assert.equal(resolveSelection(V, 103, PRODUCT_PRICE).discountPct, null);
  // discountPct without onSale ⇒ nothing.
  const single = resolveSelection([], null, { priceMinor: 100, regularMinor: 200, discountPct: 50 });
  assert.equal(single.discountPct, null);
});

test('without a format choice the product itself is sold (no variant id)', () => {
  for (const variants of [[], [V[0]]]) {
    const s = resolveSelection(variants, variants[0]?.id ?? null, PRODUCT_PRICE);
    assert.equal(s.hasChoice, false);
    assert.equal(s.variantId, null);
    assert.equal(s.price?.priceMinor, 4500);
    assert.equal(s.discountPct, 24);
    assert.deepEqual(cartItemBody(1, s.variantId), { productId: 1 });
    assert.deepEqual(buyNowFields(1, s.variantId), [['productId', '1']]);
  }
  assert.equal(resolveSelection([], null, null).purchasable, false);
});

test('an id that is not one of the product formats sells nothing (stale selection)', () => {
  const s = resolveSelection(V, 999, PRODUCT_PRICE);
  assert.equal(s.variant, null);
  assert.equal(s.purchasable, false);
  assert.equal(s.variantId, null);
});

test('?fmt= deep links: slug or format code, purchasable only, fallback to the first purchasable', () => {
  assert.equal(initialVariantId(V, 'pptx'), 101);
  assert.equal(initialVariantId(V, ' PPTX '), 101);
  assert.equal(initialVariantId(V, 'xlsx'), 100, 'unpurchasable deep link falls back');
  assert.equal(initialVariantId(V, '<script>'), 100);
  assert.equal(initialVariantId(V, undefined), 100);
  assert.equal(initialVariantId([], 'pptx'), null);
});

test('format links keep every other query param and replace an old fmt', () => {
  assert.equal(formatHref([['token', 'a b&c'], ['fmt', 'docx']], V[1]), '?token=a+b%26c&fmt=pptx');
  assert.equal(formatHref([], V[2]), '?fmt=pdf');
});
