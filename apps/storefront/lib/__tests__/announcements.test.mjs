/** شريط الإعلانات — التطبيع يُسقط المجهول ولا يخترع شريطًا من لا شيء. */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { normalizeAnnouncements } from '../../components/announcements.ts';

test('الغياب والتشوّه والفراغ = لا شريط', () => {
  assert.equal(normalizeAnnouncements(null), null);
  assert.equal(normalizeAnnouncements('x'), null);
  assert.equal(normalizeAnnouncements({ mode: 'static', items: [], link: null, style: null }), null);
  assert.equal(normalizeAnnouncements({ items: ['', '  '], link: { label: 'x', href: 'https://evil' } }), null);
});

test('الرسائل والرابط كما جاءا — والوضع المجهول ثابت', () => {
  const a = normalizeAnnouncements({ mode: 'rotate', items: [' أ ', 'ب', 3], link: { label: 'العروض', href: '/offers' }, style: null });
  assert.deepEqual(a, { mode: 'static', items: ['أ', 'ب'], link: { label: 'العروض', href: '/offers' }, style: null });
});

test('الشريط المتحرّك يحتاج رسائل؛ والرابط وحده شريطٌ ثابت', () => {
  assert.equal(normalizeAnnouncements({ mode: 'marquee', items: ['أ'], link: null }).mode, 'marquee');
  assert.equal(normalizeAnnouncements({ mode: 'marquee', items: [], link: { label: 'x', href: '/x' } }).mode, 'static');
});

test('النمط: قيمٌ مأمونة وارتفاعٌ ضمن مدى التصميم (30–56)', () => {
  const a = normalizeAnnouncements({ items: ['أ'], style: { bg: '#1E3A6E', fg: 'url(x);}', height: '44' } });
  assert.deepEqual(a.style, { bg: '#1E3A6E', fg: undefined, height: 44 });
  assert.equal(normalizeAnnouncements({ items: ['أ'], style: { height: 90 } }).style, null);
});
