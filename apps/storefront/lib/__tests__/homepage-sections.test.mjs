/**
 * سجلّ أقسام الرئيسة — أقسام «Home Components.dc.html» مسجَّلةٌ لا مفعَّلة.
 *
 * ─── لماذا يُختبر هذا بعينه ───
 *
 * قسمٌ يختاره المالك من اللوحة ولا مُصيِّر له يسقط صامتًا (`resolveSections`
 * يُسقط المجهول)، وقسمٌ يُفعَّل افتراضًا بلا قرارٍ من المالك يغيّر الرئيسة
 * لكلّ زائر. فالاثنان يُثبَّتان: الأربعة معروفةٌ للمُحلِّل، والافتراض بتسلسل المالك وحده.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  DEFAULT_ENABLED_SECTIONS,
  HOMEPAGE_SECTION_IDS,
  isSectionId,
  resolveSections,
} from '../homepage.ts';

const WIDGETS = ['lesson-bundle', 'curriculum-navigator', 'product-types', 'popular-by-grade'];

test('أقسام المكوّنات الأربعة مُعرَّفاتٌ معروفة', () => {
  for (const id of WIDGETS) {
    assert.ok(isSectionId(id), id);
    assert.ok(HOMEPAGE_SECTION_IDS.includes(id), id);
  }
});

test('المتصفّح والأكثر طلبًا خارج المُفعَّل افتراضًا — يختارهما المالك من اللوحة', () => {
  for (const id of ['curriculum-navigator', 'popular-by-grade']) assert.ok(!DEFAULT_ENABLED_SECTIONS.includes(id), id);
});

/* طلب المالك ٢٠٢٦-٠٩-٢٥: «أنواع المحتوى» هي الفئات السريعة بعد الهيرو، و«حزمة الدرس» هي الحزم بعد العروض. */
test('الافتراض بتسلسل المالك: الهيرو ← الفئات ← المراحل ← المختارات ← الموادّ ← العروض ← الحزم ← الأكثر مبيعًا ← … ← للمعلّمين ← الثقة ← التقييمات ← الدعوة', () => {
  assert.deepEqual(DEFAULT_ENABLED_SECTIONS, [
    'hero',
    'product-types',
    'stages',
    'featured',
    'subjects',
    'promo',
    'lesson-bundle',
    'best',
    'all-products',
    'why',
    'trust',
    'reviews',
    'cta',
  ]);
  for (const id of DEFAULT_ENABLED_SECTIONS) assert.ok(isSectionId(id), id);
});

test('ترتيبٌ منشور يحملها يُحترم كما هو، والمجهول يُسقَط', () => {
  assert.deepEqual(resolveSections(['hero', 'popular-by-grade', 'nope', 'product-types']), [
    'hero',
    'popular-by-grade',
    'product-types',
  ]);
});
