/**
 * «كل الملفّات» في الرئيسة — الترقيم من أرقام الخادم وحدها، والضمّ بلا تكرار.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { allProductsFallbackHref, allProductsPaging, mergeById } from '../home-all-products.ts';
import { DEFAULT_ENABLED_SECTIONS, HOMEPAGE_SECTION_IDS, isSectionId } from '../homepage.ts';

test('الصفحة التالية وحجمها من total/perPage', () => {
  assert.deepEqual(allProductsPaging(24, 27, 1, 24), {
    shown: 24,
    total: 27,
    remaining: 3,
    nextPage: 2,
    nextBatch: 3,
    done: false,
  });
  assert.equal(allProductsPaging(24, 100, 1, 24).nextBatch, 24);
});

test('عند بلوغ المجموع (أو تجاوزه بعد حذفٍ بين صفحتين) لا صفحة تالية', () => {
  for (const [shown, total] of [
    [27, 27],
    [30, 27],
    [0, 0],
  ]) {
    const p = allProductsPaging(shown, total, 2, 24);
    assert.equal(p.done, true);
    assert.equal(p.nextPage, null);
    assert.equal(p.remaining, 0);
    assert.equal(p.nextBatch, 0);
  }
});

test('حجم صفحةٍ غير صالح من الخادم لا يَعِد بصفحةٍ لا تأتي', () => {
  assert.equal(allProductsPaging(0, 10, 1, 0).done, true);
});

test('الضمّ يُسقط ما عُرض من قبل ويحفظ الترتيب', () => {
  const merged = mergeById([{ id: 1 }, { id: 2 }], [{ id: '2' }, { id: 3 }, { id: 3 }, { id: 4 }]);
  assert.deepEqual(
    merged.map((x) => String(x.id)),
    ['1', '2', '3', '4'],
  );
});

test('رابط الاحتياط بلا JavaScript: /shop?page=N', () => {
  assert.equal(allProductsFallbackHref('/shop', 2), '/shop?page=2');
  assert.equal(allProductsFallbackHref('/shop', null), '/shop');
});

test('القسم مسجَّلٌ ومُفعَّلٌ افتراضًا بعد «الأكثر مبيعًا» مباشرة', () => {
  assert.ok(isSectionId('all-products'));
  assert.ok(HOMEPAGE_SECTION_IDS.includes('all-products'));
  const i = DEFAULT_ENABLED_SECTIONS.indexOf('best');
  assert.equal(DEFAULT_ENABLED_SECTIONS[i + 1], 'all-products');
});

test('المرشّحات السريعة: المراحل ثمّ أنواع المنتج من facets، بلا «الكل» ولا أصفار', async () => {
  const { quickFilters, quickFilterHref, quickFilterParams } = await import('../home-all-products.ts');
  const facets = {
    taxonomy: [
      { key: 'grade', values: [{ value: 'أول-ابتدائي', count: 3 }] },
      { key: 'stage', values: [{ value: 'ابتدائي', count: 11 }, { value: 'رياض-أطفال', count: 0 }, { value: 'ثانوي', count: 8 }] },
    ],
    types: [{ key: 'all', label: 'الكل', count: 27 }, { key: 'pdf', label: 'PDF', count: 12 }, { key: 'pptx', label: 'عروض', count: 0 }],
  };
  assert.deepEqual(quickFilters(facets), [
    { key: 'stage', value: 'ابتدائي', label: 'ابتدائي', count: 11 },
    { key: 'stage', value: 'ثانوي', label: 'ثانوي', count: 8 },
    { key: 'type', value: 'pdf', label: 'PDF', count: 12 },
  ]);
  /* ‎kinds‎ حين يرسلها الخادم تحلّ محلّ ‎types‎ (بُعد ‎/shop‎)، والعدد الغائب يبقى غائبًا. */
  const withKinds = quickFilters({ ...facets, kinds: [{ slug: 'تحضير', label: 'تحضير' }, { slug: 'x', label: 'x', count: 0 }] });
  assert.deepEqual(withKinds.at(-1), { key: 'kind', value: 'تحضير', label: 'تحضير' });
  assert.equal(withKinds.length, 3);
  assert.deepEqual(quickFilters(null), []);
  assert.deepEqual(quickFilterParams(null), {});
  assert.deepEqual(quickFilterParams({ key: 'stage', value: 'ثانوي', label: 'ثانوي' }), { stage: 'ثانوي' });
  assert.equal(quickFilterHref('/shop', null), '/shop');
  assert.equal(quickFilterHref('/shop', { key: 'type', value: 'pdf', label: '' }), '/search?type=pdf');
  assert.equal(quickFilterHref('/shop', { key: 'stage', value: 'ابتدائي', label: '' }), `/shop?stage=${encodeURIComponent('ابتدائي')}`);
});

test('«شاهدتَ مؤخّرًا» بعد الأكثر مبيعًا، وإلّا بعد الهيرو، ولا شيء في صفحةٍ مُخلاة', async () => {
  const { recentAnchor } = await import('../homepage.ts');
  assert.equal(recentAnchor(DEFAULT_ENABLED_SECTIONS), 'best');
  assert.equal(recentAnchor(['hero', 'trust']), 'hero');
  assert.equal(recentAnchor(['trust']), null);
  assert.equal(recentAnchor([]), undefined);
});
