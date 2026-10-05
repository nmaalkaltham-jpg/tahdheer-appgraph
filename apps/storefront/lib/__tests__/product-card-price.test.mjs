/**
 * حساب سعر بطاقة المنتج (packages/ui/src/productCardPrice) — الدالّة التي تعرض
 * بها البطاقة القديمَ والوفرَ والنسبة. الوفر والنسبة من سعرين حقيقيّين فقط.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cardPrice } from '../../../../packages/ui/src/productCardPrice.ts';

test('خصمٌ حقيقيّ: 300 ← 250 = وفر 50 ونسبة الخادم 17', () => {
  assert.deepEqual(cardPrice({ priceMinor: 25000, regularMinor: 30000, discountPct: 17, onSale: true }), {
    discounted: true,
    wasMinor: 30000,
    savingsMinor: 5000,
    pct: 17,
  });
});

test('النسبة تُشتقّ حسابيًّا حين لا يرسلها الخادم (أو يرسل صفرًا)', () => {
  assert.equal(cardPrice({ priceMinor: 25000, regularMinor: 30000, onSale: true }).pct, 17);
  assert.equal(cardPrice({ priceMinor: 30000, regularMinor: 40000, onSale: true }).pct, 25);
  assert.equal(cardPrice({ priceMinor: 25000, regularMinor: 30000, discountPct: 0, onSale: true }).pct, 17);
});

test('لا خصم ⇒ لا قديم ولا وفر ولا نسبة (ولا فراغ محجوز)', () => {
  const none = { discounted: false, wasMinor: null, savingsMinor: null, pct: 0 };
  assert.deepEqual(cardPrice({ priceMinor: 40000, onSale: false }), none);
  assert.deepEqual(cardPrice({ priceMinor: 25000, regularMinor: 30000, onSale: false }), none, 'لا بيع فعّال');
  assert.deepEqual(cardPrice({ priceMinor: 25000, onSale: true, discountPct: 40 }), none, 'لا سعر معتاد');
  assert.deepEqual(cardPrice({ priceMinor: 25000, regularMinor: 25000, onSale: true }), none, 'لا فرق');
  assert.deepEqual(cardPrice({ priceMinor: 25000, regularMinor: 20000, onSale: true }), none, 'المعتاد أقلّ');
  assert.deepEqual(cardPrice({ priceMinor: 25000, regularMinor: 30000, onSale: true, unavailable: true }), none, 'غير متاح');
  assert.deepEqual(cardPrice({ priceMinor: Number.NaN, regularMinor: 30000, onSale: true }), none);
});
