/**
 * ظهوراتُ البحث — مرّةً لكلّ منتج، ودفعاتٌ بحدّ الخادم.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { IMPRESSION_BATCH_MAX, ImpressionBuffer } from '../impression-buffer.ts';

test('المنتج يُحسب مرّةً واحدة مهما عاد إلى مجال الرؤية', () => {
  const b = new ImpressionBuffer();
  assert.equal(b.add(7), true);
  assert.equal(b.add(7), false);
  assert.deepEqual(b.drain(), [7]);
  assert.equal(b.add(7), false, 'after a flush it is still the same search');
  assert.deepEqual(b.drain(), []);
});

test('معرّفٌ غير صالح لا يُرسل', () => {
  const b = new ImpressionBuffer();
  for (const bad of [0, -1, 1.5, Number.NaN]) assert.equal(b.add(bad), false);
  assert.equal(b.size, 0);
});

test('الدفعة لا تتجاوز حدّ الخادم والباقي ينتظر', () => {
  const b = new ImpressionBuffer();
  for (let i = 1; i <= IMPRESSION_BATCH_MAX + 5; i += 1) b.add(i);
  assert.equal(b.drain().length, IMPRESSION_BATCH_MAX);
  assert.deepEqual(b.drain(), [49, 50, 51, 52, 53]);
});
