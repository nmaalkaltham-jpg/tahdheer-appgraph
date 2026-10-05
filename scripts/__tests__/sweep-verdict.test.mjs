/**
 * حكم الماسحات (scripts/lib/sweep-verdict.mjs): لا نجاح بلا قياس، وعطلُ البنية
 * يُعلَن عطلَ بنية، ومحدِّد زرّ الإضافة يصيب الصيغتين ولا يصيب غيرهما.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ADD_TO_CART_NAME, describeLoadFailure, isAddToCartName, sweepVerdict } from '../lib/sweep-verdict.mjs';

test('add-to-cart: صيغة صفحة المنتج «أضف إلى السلة»', () => {
  assert.equal(isAddToCartName('أضف إلى السلة'), true);
  assert.equal(isAddToCartName('  أضف إلى السلة '), true);
});

test('add-to-cart: صيغة البطاقة «أضف للسلة» واسمها المتاح بالعنوان', () => {
  assert.equal(isAddToCartName('أضف للسلة'), true);
  assert.equal(isAddToCartName('أضف للسلة «ملف العلوم للصف الخامس»'), true);
});

test('add-to-cart: الأزرار الأخرى لا تطابق', () => {
  for (const name of ['أضف', 'أضف تقييمًا', 'أضف إلى المفضلة', 'في السلة', 'إتمام الطلب', 'أضف لالسلة', 'أضف إلى السلةX', 'اشترِ الآن', '']) {
    assert.equal(isAddToCartName(name), false, name);
  }
  assert.equal(isAddToCartName(undefined), false);
});

test('add-to-cart: الصيغة القديمة المعطوبة (نصٌّ واحد) كانت تُسقط زرّ صفحة المنتج', () => {
  const broken = /^أضف للسلة$/;
  assert.equal(broken.test('أضف إلى السلة'), false);
  assert.equal(ADD_TO_CART_NAME.test('أضف إلى السلة'), true);
});

test('verdict: صفرُ قياس يفشل ولا ينجح أبدًا', () => {
  assert.deepEqual(sweepVerdict({ measured: 0 }).code, 1);
  assert.match(sweepVerdict({ measured: 0 }).reason, /صفرُ قياس/);
  assert.equal(sweepVerdict({ measured: Number.NaN }).code, 1);
});

test('verdict: قياسٌ بلا خرق ينجح', () => {
  assert.equal(sweepVerdict({ measured: 6 }).code, 0);
});

test('verdict: الخرق وعطل القياس يفشلان بـ1، وعطل البنية بـ2', () => {
  assert.equal(sweepVerdict({ measured: 6, breaches: 1 }).code, 1);
  assert.equal(sweepVerdict({ measured: 6, instrumentErrors: 1 }).code, 1);
  assert.equal(sweepVerdict({ measured: 6, infraFailures: 1 }).code, 2);
  assert.equal(sweepVerdict({ measured: 0, infraFailures: 3 }).code, 2);
});

test('describeLoadFailure: الخنق عطلُ بنية، وما عداه تعذّر تحميل', () => {
  const throttled = Object.assign(new Error('متعذّر مؤقّتًا (429) على /cart'), { temporary: true });
  assert.match(describeLoadFailure(throttled), /^عطلُ بنية/);
  assert.match(describeLoadFailure(new Error('HTTP 500 على /cart\nstack')), /^تعذّر التحميل · HTTP 500 على \/cart$/);
});
