/**
 * المفضّلة — ما يُقرأ من localStorage مدخلٌ خارجيّ: قد يكون من إصدارٍ أقدم
 * أو معبثًا به. يُقبل عنصرٌ بشكله الكامل وحده، ومعرّفٌ واحد مرّةً واحدة.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { parseWishlist } from '../wishlist.ts';

const ok = { slug: 'a', title: 'أ', priceMinor: 1000, currency: 'SAR' };

test('فارغ أو تالف أو ليس مصفوفة ⇒ قائمة فارغة', () => {
  for (const raw of [null, '', '{', '{}', '"x"', '42', 'null']) assert.deepEqual(parseWishlist(raw), [], String(raw));
});

test('عنصرٌ سليم يمرّ كما هو', () => {
  assert.deepEqual(parseWishlist(JSON.stringify([ok])), [ok]);
});

test('سعرٌ غائب أو غير عدديّ أو كسريّ أو سالب يُسقط العنصر — لا «NaN» في الواجهة', () => {
  const bad = [
    { slug: 'b', title: 'ب', currency: 'SAR' },
    { slug: 'c', title: 'ج', priceMinor: '1000', currency: 'SAR' },
    { slug: 'd', title: 'د', priceMinor: 10.5, currency: 'SAR' },
    { slug: 'e', title: 'هـ', priceMinor: -1, currency: 'SAR' },
    { slug: '', title: 'فارغ', priceMinor: 1, currency: 'SAR' },
    null,
    'a',
  ];
  assert.deepEqual(parseWishlist(JSON.stringify([ok, ...bad])), [ok]);
});

test('المعرّف المكرّر يُحفظ أوّلُه فقط — مفاتيح React فريدة', () => {
  const dup = { ...ok, title: 'نسخة' };
  assert.deepEqual(parseWishlist(JSON.stringify([ok, dup])), [ok]);
});
