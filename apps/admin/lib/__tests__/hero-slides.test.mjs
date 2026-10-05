/**
 * منطق محرّر شرائح الهيرو — مرآةُ قواعد الخادم قبل الحفظ.
 *
 * الخطر هنا انحرافٌ صامت: اللوحة تقبل وجهةً يُسقطها الخادم، فيضغط المالك
 * «احفظ» ويختفي زرّه بلا رسالة. فالحالات أدناه هي حالات
 * `HomeContent::safeHref` و`safeMediaUrl` نفسها في اختبارات الخادم.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  duplicateSlide,
  emptySlide,
  isMediaUrl,
  isSafeHref,
  localInputToIso,
  isoToLocalInput,
  moveItem,
  reorderByKeys,
  slideIssues,
  slidesFromStored,
  slidesToPayload,
} from '../hero-slides.ts';

const LIMITS = { title: 120, subtitle: 240, eyebrow: 60, titleAccent: 80 };
const BRAND = ['eyebrow', 'title', 'titleAccent', 'subtitle', 'primary', 'secondary', 'showProductCard', 'theme', 'align', 'media', 'overlay'];

test('الوجهات: مسار داخليّ أو https فقط', () => {
  for (const ok of ['/shop', '/shop?grade=1', 'https://example.com/x']) assert.ok(isSafeHref(ok), ok);
  for (const bad of ['', 'shop', '//evil.example', '/\\evil.example', 'http://localhost/x', 'javascript:alert(1)', '/with space']) {
    assert.ok(!isSafeHref(bad), bad);
  }
});

test('رابط الصورة: https أو مكتبة المتجر', () => {
  for (const ok of ['https://cdn.example.com/a.jpg', '/storage/hero/a.webp', '/media/a.jpg', 'http://localhost/storage/a.jpg']) {
    assert.ok(isMediaUrl(ok), ok);
  }
  for (const bad of ['http://localhost/a.jpg', '/etc/passwd', 'data:image/png;base64,AAAA']) assert.ok(!isMediaUrl(bad), bad);
});

test('العنوان إلزاميّ، والزرّ نصفه خطأ، والوجهة الخارجيّة غير الآمنة خطأ', () => {
  const slide = { ...emptySlide('brand'), primary: { label: 'تصفّح', href: '' }, secondary: { label: 'x', href: 'http://localhost/a' } };
  const issues = slideIssues(slide, BRAND, LIMITS, 6);
  assert.ok(issues.title);
  assert.ok(issues.primary);
  assert.match(issues.secondary, /https/);

  const fine = { ...slide, title: 'عنوان', primary: { label: '', href: '' }, secondary: { label: 'x', href: '/y' } };
  assert.deepEqual(slideIssues(fine, BRAND, LIMITS, 6), {});
});

test('حقلٌ ليس من حقول النوع لا يُحاسَب', () => {
  const slide = { ...emptySlide('search'), title: 'ابحث', primary: { label: 'x', href: 'bad' } };
  assert.deepEqual(slideIssues(slide, ['title', 'search'], LIMITS, 6), {});
});

test('اختصارات البحث: الحدّ وصحّة كلٍّ منها', () => {
  const chip = (href) => ({ label: 'صف', href });
  const base = { ...emptySlide('search'), title: 'ابحث' };
  assert.ok(slideIssues({ ...base, chips: Array.from({ length: 7 }, () => chip('/g')) }, ['title', 'search'], LIMITS, 6).search);
  assert.ok(slideIssues({ ...base, chips: [chip('g')] }, ['title', 'search'], LIMITS, 6).search);
  assert.deepEqual(slideIssues({ ...base, chips: [chip('/g')] }, ['title', 'search'], LIMITS, 6), {});
});

test('ذهابٌ وإياب: المخزَّن ← مسودّة ← حمولة يحفظ المُعرِّف والحقول', () => {
  const stored = [{
    id: 's_8f2c', kind: 'offer', enabled: false, title: 'عرض', primary: { label: 'اشترِ', href: '/shop' },
    secondary: null, theme: 'sand', align: 'center', overlay: 'strong', media: null, showProductCard: false,
    offer: { badge: '٢٥٪', endsAt: '2026-10-01T00:00:00+00:00', couponCode: 'BACK25' }, search: null,
  }];
  const [draft] = slidesFromStored(stored);
  assert.equal(draft.id, 's_8f2c');
  assert.equal(draft.enabled, false);
  const [payload] = slidesToPayload([draft]);
  assert.equal(payload.id, 's_8f2c');
  assert.deepEqual(payload.primary, { label: 'اشترِ', href: '/shop' });
  assert.equal(payload.secondary, null);
  assert.equal(payload.theme, 'sand');
  assert.equal(payload.offer.couponCode, 'BACK25');
  assert.equal(new Date(payload.offer.endsAt).getTime(), new Date('2026-10-01T00:00:00Z').getTime());
});

test('شريحةٌ جديدة لا تُرسل مُعرِّفًا، والنسخة تفقد مُعرِّف أصلها', () => {
  assert.ok(!('id' in slidesToPayload([emptySlide('image')])[0]));
  const original = { ...emptySlide('brand'), id: 's_abcd', title: 'أصل' };
  const copy = duplicateSlide(original);
  assert.equal(copy.id, null);
  assert.notEqual(copy.key, original.key);
  assert.equal(copy.title, 'أصل');
});

test('النقل وإعادة الترتيب بالمفاتيح', () => {
  assert.deepEqual(moveItem(['a', 'b', 'c'], 0, 2), ['b', 'c', 'a']);
  assert.deepEqual(moveItem(['a', 'b'], 0, 5), ['a', 'b']);
  const a = { ...emptySlide('brand'), key: 'a' };
  const b = { ...emptySlide('brand'), key: 'b' };
  assert.deepEqual(reorderByKeys([a, b], ['b', 'a']).map((s) => s.key), ['b', 'a']);
  assert.deepEqual(reorderByKeys([a, b], ['b']).map((s) => s.key), ['a', 'b']);
});

test('datetime-local ↔ ISO', () => {
  assert.equal(localInputToIso(''), null);
  assert.equal(localInputToIso('غدًا'), null);
  const iso = localInputToIso('2026-10-01T09:30');
  assert.equal(isoToLocalInput(iso), '2026-10-01T09:30');
});
