/**
 * اعتماد الرئيسة وصفحة المنتج — حراسة انحدارٍ لعيبين أصلحهما الاعتماد:
 *  · حرفا الاسم في بطاقة الرأي يُسقطان «ال» التعريف («نورة العتيبي» ⇒ «ن.ع»).
 *  · رقاقة الصفّ/الفصل في لوحة الشراء تجد فتاتها بالمعرّف لا بالاسم المختصر.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { quoteInitials } from '../homepage.ts';
import { termCrumbUrl } from '../pdp-selection.ts';

test('quoteInitials: يسقط «ال» التعريف ويأخذ أوّل كلمتين', () => {
  assert.equal(quoteInitials('نورة العتيبي'), 'ن.ع');
  assert.equal(quoteInitials('مشاري الشهري'), 'م.ش');
  assert.equal(quoteInitials('هيفاء القحطاني'), 'ه.ق');
  assert.equal(quoteInitials('عبدالله الحربي'), 'ع.ح');
  assert.equal(quoteInitials('  سارة  '), 'س');
  assert.equal(quoteInitials('ألف باء جيم'), 'أ.ب');
  /* «ال» وحدها أو كلمة قصيرة تبقى كما هي. */
  assert.equal(quoteInitials('ال علي'), 'ا.ع');
});

const CRUMBS = [
  { name: 'الرئيسية', url: '/' },
  { name: 'الابتدائي', url: '/stages/primary' },
  { name: 'الخامس', url: '/stages/primary/grades/5' },
  { name: 'الفصل الأول', url: '/stages/primary/grades/5/terms/t1' },
  { name: 'العلوم', url: '/stages/primary/grades/5/terms/t1/subjects/science' },
  { name: 'تحضير العلوم كامل', url: '/products/science-5-t1-prep' },
];

test('termCrumbUrl: بالاسم الكامل أو بالمعرّف في نهاية المسار — لا الفتات الأخير', () => {
  assert.equal(termCrumbUrl(CRUMBS, { name: 'العلوم', slug: 'science' }), CRUMBS[4].url);
  assert.equal(termCrumbUrl(CRUMBS, { name: 'الصف الخامس الابتدائي', slug: '5' }), CRUMBS[2].url);
  assert.equal(termCrumbUrl(CRUMBS, { name: 'الفصل الدراسي الأول', slug: 't1' }), CRUMBS[3].url);
  /* المسارات العربيّة المشفَّرة تُفكّ قبل المطابقة. */
  const live = [{ name: 'الرئيسية', url: '/' }, { name: 'الصف الثالث الثانوي', url: '/grades/%D8%AB%D8%A7%D9%84%D8%AB-%D8%AB%D8%A7%D9%86%D9%88%D9%8A' }, { name: 'x', url: '/products/x' }];
  assert.equal(termCrumbUrl(live, { name: 'ثالث ثانوي', slug: 'ثالث-ثانوي' }), live[1].url);
  /* معرّفٌ يطابق المنتج نفسه (الفتات الأخير) لا يُعدّ رقاقة. */
  assert.equal(termCrumbUrl(CRUMBS, { name: 'منتج', slug: 'science-5-t1-prep' }), undefined);
  assert.equal(termCrumbUrl(CRUMBS, { name: '', slug: '5' }), undefined);
  assert.equal(termCrumbUrl(CRUMBS, null), undefined);
});
