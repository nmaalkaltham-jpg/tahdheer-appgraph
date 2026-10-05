/**
 * اشتقاقات بطاقة المنتج (lib/product-card-model) — يمرّ بها الكتالوج والبحث معًا:
 * «جديد» من تاريخٍ حقيقيّ، والصيغ من صيغ المجموعة أو العمود الواحد، و«تحميل
 * فوري» بقاعدة صفحة المنتج، والصفّ والفصل والعيّنة كما أرسلها الخادم.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { NEW_WINDOW_DAYS, cardSignals, formatLabel, formatLabels, hasInstantDownload, isNewProduct } from '../product-card-model.ts';

const NOW = Date.parse('2026-09-27T12:00:00Z');
const daysAgo = (days) => new Date(NOW - days * 24 * 60 * 60 * 1000).toISOString();

test('«جديد»: داخل النافذة نعم، خارجها لا، والغائب والمستقبل والمشوَّه لا', () => {
  assert.equal(isNewProduct(daysAgo(1), NOW), true);
  assert.equal(isNewProduct(daysAgo(NEW_WINDOW_DAYS), NOW), true);
  assert.equal(isNewProduct(daysAgo(NEW_WINDOW_DAYS + 1), NOW), false);
  assert.equal(isNewProduct(undefined, NOW), false);
  assert.equal(isNewProduct(null, NOW), false);
  assert.equal(isNewProduct('', NOW), false);
  assert.equal(isNewProduct('not-a-date', NOW), false);
  assert.equal(isNewProduct(new Date(NOW + 60_000).toISOString(), NOW), false);
});

test('أسماء الصيغ: كلمات صفحة المنتج (DOCX ← Word، PPTX ← PowerPoint) والمجهول كما ورد', () => {
  assert.equal(formatLabel('DOCX'), 'Word');
  assert.equal(formatLabel('docx'), 'Word');
  assert.equal(formatLabel('PPTX'), 'PowerPoint');
  assert.equal(formatLabel('PPT'), 'PowerPoint');
  assert.equal(formatLabel('PDF'), 'PDF');
  assert.equal(formatLabel('ZIP'), 'ZIP');
  assert.equal(formatLabel('epub'), 'epub');
  assert.deepEqual(formatLabels(['PDF', 'DOCX', 'DOC', '', 'pdf']), ['PDF', 'Word']);
});

test('التحميل الفوريّ: قاعدة صفحة المنتج — الطبيعة أوّلًا ثمّ العلَم الرقميّ', () => {
  assert.equal(hasInstantDownload({ productNature: 'HYBRID', digital: false }), true);
  assert.equal(hasInstantDownload({ productNature: 'PHYSICAL', digital: true }), false);
  assert.equal(hasInstantDownload({ productNature: 'SERVICE', digital: true }), false);
  assert.equal(hasInstantDownload({ productNature: 'DIGITAL', digital: true }), true);
  assert.equal(hasInstantDownload({ productNature: null, digital: true }), true);
  assert.equal(hasInstantDownload({ digital: false }), false);
  assert.equal(hasInstantDownload({}), false);
});

test('إشارات البطاقة: الحقيقيّ فقط، والغائب لا يُملأ', () => {
  const full = cardSignals(
    {
      digital: true,
      productNature: 'DIGITAL',
      publishedAt: daysAgo(3),
      bestSeller: true,
      format: 'DOCX',
      formats: ['PDF', 'DOCX'],
      resourceType: ' تحضير ',
      gradeName: 'الصف الخامس الابتدائي',
      semesterName: ' الفصل الدراسي الأول ',
      hasSample: true,
      priceMinor: 25000,
    },
    NOW,
  );
  assert.deepEqual(full, {
    instantDownload: true,
    isNew: true,
    bestSeller: true,
    hasSample: true,
    unavailable: false,
    formats: ['PDF', 'Word'],
    resourceType: 'تحضير',
    grade: 'الصف الخامس الابتدائي',
    semester: 'الفصل الدراسي الأول',
  });

  const bare = cardSignals({ priceMinor: 100 }, NOW);
  assert.deepEqual(bare, {
    instantDownload: false,
    isNew: false,
    bestSeller: false,
    hasSample: false,
    unavailable: false,
    formats: [],
    resourceType: undefined,
    grade: undefined,
    semester: undefined,
  });
});

test('الصيغ: صيغ المجموعة تعلو العمود الواحد، والعمود يبقى حين لا صيغ', () => {
  assert.deepEqual(cardSignals({ format: 'PDF', formats: ['DOCX', 'PPTX'], priceMinor: 1 }, NOW).formats, ['Word', 'PowerPoint']);
  assert.deepEqual(cardSignals({ format: 'PDF', formats: [], priceMinor: 1 }, NOW).formats, ['PDF']);
  assert.deepEqual(cardSignals({ format: '', priceMinor: 1 }, NOW).formats, []);
});

test('غير متاح: لا سعرَ في الردّ (أو سعرٌ غير عدديّ) ⇒ unavailable', () => {
  assert.equal(cardSignals({}, NOW).unavailable, true);
  assert.equal(cardSignals({ priceMinor: Number.NaN }, NOW).unavailable, true);
  assert.equal(cardSignals({ priceMinor: 0 }, NOW).unavailable, false, 'صفرٌ حقيقيّ (مجّانيّ) سعرٌ لا غياب');
});
