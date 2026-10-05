/**
 * اختيار محاور المنهج — الحارس ضدّ إعلان عنوانٍ لم يقل الخادم إنّه مخدوم،
 * وضدّ سقوط الوصلة التي كانت مقطوعة أصلًا.
 *
 * الحال قبل هذا: لا رابط في المتجر كلّه يقود إلى `/curriculum/...`،
 * و`GET /api/v1/curriculum` بلا مستهلك. فصفحاتٌ مخدومة لا يبلغها إلّا قادمٌ
 * من محرّك بحث.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { hubsForTerm, siblingHubs } from '../curriculum.ts';

const HUBS = [
  { label: 'رياضيات — سادس', path: '/curriculum/subject-grade/math-6', subjectSlug: 'math', gradeSlug: 'g6' },
  { label: 'رياضيات — خامس', path: '/curriculum/subject-grade/math-5', subjectSlug: 'math', gradeSlug: 'g5' },
  { label: 'علوم — سادس', path: '/curriculum/subject-grade/sci-6', subjectSlug: 'sci', gradeSlug: 'g6' },
  { label: 'لغتي — أوّل', path: '/curriculum/subject-grade/ar-1', subjectSlug: 'ar', gradeSlug: 'g1' },
];

test('أرشيف المادّة يقود إلى محاورها وحدها', () => {
  assert.deepEqual(hubsForTerm('subjects', 'math', HUBS), [
    { label: 'رياضيات — سادس', href: '/curriculum/subject-grade/math-6' },
    { label: 'رياضيات — خامس', href: '/curriculum/subject-grade/math-5' },
  ]);
});

test('أرشيف الصفّ يقود إلى محاوره وحدها', () => {
  assert.deepEqual(hubsForTerm('grades', 'g6', HUBS), [
    { label: 'رياضيات — سادس', href: '/curriculum/subject-grade/math-6' },
    { label: 'علوم — سادس', href: '/curriculum/subject-grade/sci-6' },
  ]);
});

test('المرحلة والفصل والنوع ليست أبعاد محور — فلا قسم', () => {
  assert.deepEqual(hubsForTerm('stages', 'primary', HUBS), []);
  assert.deepEqual(hubsForTerm('semesters', 's1', HUBS), []);
  assert.deepEqual(hubsForTerm('kinds', 'pptx', HUBS), []);
});

test('سجلٌّ فارغ ⇒ لا روابط — وهو جواب الخادم حين لا عنقود مؤهَّل', () => {
  assert.deepEqual(hubsForTerm('subjects', 'math', []), []);
  assert.deepEqual(siblingHubs(HUBS[0], []), []);
});

test('الأشقّاء: ما يشارك المادّة أو الصفّ، ولا يُدرج المحور نفسه', () => {
  const links = siblingHubs(HUBS[0], HUBS);

  assert.deepEqual(links, [
    { label: 'رياضيات — خامس', href: '/curriculum/subject-grade/math-5' },
    { label: 'علوم — سادس', href: '/curriculum/subject-grade/sci-6' },
  ]);
  assert.ok(!links.some((link) => link.href === HUBS[0].path));
});

test('محورٌ لا يشارك أحدًا لا يُنتج قسمًا', () => {
  assert.deepEqual(siblingHubs(HUBS[3], HUBS), []);
});

/** العنوان من الخادم لا من التركيب — وهو ما يمنع إعلان رابطٍ يردّ ٤٠٤. */
test('المسار يُنقل كما بثّه الخادم', () => {
  const odd = [{ label: 'مادّة بمسافة — أوّل', path: '/curriculum/subject-grade/%20x', subjectSlug: 'x', gradeSlug: 'g1' }];

  assert.deepEqual(hubsForTerm('subjects', 'x', odd), [
    { label: 'مادّة بمسافة — أوّل', href: '/curriculum/subject-grade/%20x' },
  ]);
});

test('السقف ثمانية — شريطٌ بلا حدّ يصير جدارًا', () => {
  const many = Array.from({ length: 12 }, (_, i) => ({
    label: `رياضيات — ${i}`,
    path: `/curriculum/subject-grade/math-${i}`,
    subjectSlug: 'math',
    gradeSlug: `g${i}`,
  }));

  assert.equal(hubsForTerm('subjects', 'math', many).length, 8);
  assert.equal(siblingHubs(many[0], many).length, 8);
});
