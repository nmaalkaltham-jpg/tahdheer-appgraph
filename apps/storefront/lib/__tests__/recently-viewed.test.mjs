/** «شاهدتَ مؤخّرًا» — القواعد الخالصة: الأحدث أوّلًا، بلا تكرار، بسقف، ومدخلٌ عابث لا يكسر شيئًا. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseRecent, pushRecent, RECENT_MAX } from '../recently-viewed.ts';

const item = (slug, viewedAt = 1) => ({ slug, title: `عنوان ${slug}`, priceMinor: 3500, currency: 'SAR', viewedAt });

test('الزيارة الجديدة أوّلًا، والمكرّرة تنتقل إلى الأوّل بلا تكرار', () => {
  const list = pushRecent(pushRecent(pushRecent([], item('a')), item('b')), item('a', 9));
  assert.deepEqual(list.map((x) => x.slug), ['a', 'b']);
  assert.equal(list[0].viewedAt, 9);
});

test(`السقف ${RECENT_MAX} عنصرًا — الأقدم يسقط`, () => {
  let list = [];
  for (let i = 0; i < RECENT_MAX + 5; i += 1) list = pushRecent(list, item(`p${i}`));
  assert.equal(list.length, RECENT_MAX);
  assert.equal(list[0].slug, `p${RECENT_MAX + 4}`);
});

test('المخزن العابث يُقرأ قائمةً فارغة أو سليمة', () => {
  assert.deepEqual(parseRecent(null), []);
  assert.deepEqual(parseRecent('{not json'), []);
  assert.deepEqual(parseRecent('{"a":1}'), []);
  const mixed = JSON.stringify([item('x'), { slug: 'y', title: 't', priceMinor: 'NaN', currency: 'SAR', viewedAt: 1 }, item('x'), item('z')]);
  assert.deepEqual(parseRecent(mixed).map((x) => x.slug), ['x', 'z']);
});
