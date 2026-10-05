/**
 * نيّة الشراء من صفحة المنتج إلى الدفع (lib/checkout-intent.ts):
 * `?item=&fmt=&pay=apple` تُقرأ كما هي، وتُطابَق على السلّة والصيغ، ولا
 * تختار Apple Pay إلّا حين يعرضه الخادم.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  applePayPreselect,
  cartHasLine,
  consumedSearch,
  parseCheckoutIntent,
  variantIdFor,
} from '../checkout-intent.ts';

const V = [
  { id: 10, slug: 'science-5-t1-prep-docx', format: 'DOCX', purchasable: true },
  { id: 11, slug: 'science-5-t1-prep-pdf', format: 'PDF', purchasable: false },
];

test('parse: item + fmt + pay=apple', () => {
  assert.deepEqual(parseCheckoutIntent('?item=science-5-t1-prep&fmt=science-5-t1-prep-docx&pay=apple'), {
    item: 'science-5-t1-prep',
    format: 'science-5-t1-prep-docx',
    pay: 'apple',
  });
});

test('parse: empty / absent / unknown pay', () => {
  assert.deepEqual(parseCheckoutIntent(''), { item: null, format: null, pay: null });
  assert.deepEqual(parseCheckoutIntent(null), { item: null, format: null, pay: null });
  assert.deepEqual(parseCheckoutIntent('?pay=card&item= '), { item: null, format: null, pay: null });
  /* fmt بلا item لا معنى له. */
  assert.deepEqual(parseCheckoutIntent('?fmt=docx'), { item: null, format: null, pay: null });
  assert.deepEqual(parseCheckoutIntent(new URLSearchParams('pay=APPLE&item=x')), { item: 'x', format: null, pay: 'apple' });
});

test('variantIdFor: by slug or format code, purchasable only', () => {
  assert.equal(variantIdFor(V, 'science-5-t1-prep-docx'), 10);
  assert.equal(variantIdFor(V, 'docx'), 10);
  assert.equal(variantIdFor(V, 'pdf'), null);
  assert.equal(variantIdFor(V, null), null);
  assert.equal(variantIdFor(V, 'zip'), null);
});

test('cartHasLine: product alone, or product + exact variant', () => {
  const lines = [{ productId: 9001, variantId: 10 }, { productId: '77' }];
  assert.equal(cartHasLine(lines, 9001, null), true);
  assert.equal(cartHasLine(lines, 9001, 10), true);
  assert.equal(cartHasLine(lines, 9001, 11), false);
  assert.equal(cartHasLine(lines, 77, null), true);
  assert.equal(cartHasLine(lines, 5, null), false);
});

test('applePayPreselect: only pay=apple AND Apple Pay available (server + device)', () => {
  const apple = parseCheckoutIntent('?pay=apple').pay;
  assert.equal(applePayPreselect(apple, true), true);
  assert.equal(applePayPreselect(apple, false), false);
  assert.equal(applePayPreselect(parseCheckoutIntent('').pay, true), false);
  assert.equal(applePayPreselect(parseCheckoutIntent('?pay=card').pay, true), false);
});

test('consumedSearch drops item/fmt and keeps the rest', () => {
  assert.equal(consumedSearch('?item=a&fmt=b&pay=apple'), '?pay=apple');
  assert.equal(consumedSearch('?item=a'), '');
});
