/**
 * مساعدات المراحل والموادّ (lib/homepage.ts) — مفتاح لهجة المرحلة، وانتماء
 * الصفّ، والحرف البديل لمادّةٍ بلا رسم، ورقم الصفّ، واسمه المختصر.
 *
 * لماذا: بطاقة المرحلة تعدّ صفوفها من `/catalog/grades` — وخادم الإنتاج يكتب
 * slug المرحلة عربيًّا («ابتدائي») بينما يحمل الصفّ اسمها بصيغٍ أخرى («الابتدائي»)؛
 * مطابقةٌ خاطئة تُظهر «٠ صفوف» أو تعدّ صفوف مرحلةٍ لأخرى. والحرف البديل هو
 * ضمان «لا أيقونة مستعارة» للتربية الإسلاميّة وأمثالها.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { gradeGlyph, gradeInStage, gradeShortName, monogram, stageKeyOf } from '../homepage.ts';
import { subjectArt } from '../art.ts';

test('مفتاح المرحلة من الاسم أو الـslug أو اسم الصفّ', () => {
  assert.equal(stageKeyOf('رياض الأطفال'), 'kindergarten');
  assert.equal(stageKeyOf('الابتدائي'), 'primary');
  assert.equal(stageKeyOf('middle'), 'middle');
  assert.equal(stageKeyOf('الصف الثالث الثانوي'), 'secondary');
  assert.equal(stageKeyOf('مسارات'), null);
});

test('انتماء الصفّ: بالـslug، ثمّ بمفتاح الاسم، ولا انتماء بلا مرحلة', () => {
  const primary = { slug: 'ابتدائي', name: 'ابتدائي' };
  assert.ok(gradeInStage({ stage: { slug: 'ابتدائي', name: 'x' } }, primary));
  assert.ok(gradeInStage({ stage: { slug: 'p', name: 'الابتدائي' } }, { slug: 'primary', name: 'الابتدائي' }));
  assert.ok(!gradeInStage({ stage: { slug: 'متوسط', name: 'متوسط' } }, primary));
  assert.ok(!gradeInStage({ stage: null }, primary));
  assert.ok(!gradeInStage({}, primary));
});

test('الحرف البديل: أوّل حرفٍ بعد «ال»، ولمادّةٍ لا رسم لها فعلًا', () => {
  assert.equal(subjectArt('دراسات إسلامية'), null);
  assert.equal(monogram('دراسات إسلامية'), 'د');
  assert.equal(monogram('التربية البدنية'), 'ت');
  assert.equal(monogram('  فن  '), 'ف');
  assert.equal(monogram(''), '');
});

test('رقم الصفّ من ترتيبه، و«الثاني عشر» قبل «الثاني»', () => {
  assert.equal(gradeGlyph('الصف الأول الابتدائي'), '1');
  assert.equal(gradeGlyph('الصف الثالث المتوسط'), '3');
  assert.equal(gradeGlyph('أول ابتدائي'), '1');
  assert.equal(gradeGlyph('الصف الثاني عشر'), '12');
  assert.equal(gradeGlyph('المستوى التمهيدي'), 'م');
});

test('اسم الصفّ مختصرًا داخل مرحلته، وكاملًا حين لا يبقى شيء', () => {
  assert.equal(gradeShortName('الصف الأول الابتدائي', 'الابتدائي'), 'الأول');
  assert.equal(gradeShortName('الصف الثالث المتوسط', 'متوسط'), 'الثالث');
  assert.equal(gradeShortName('الصف الأول الابتدائي', 'مسارات'), 'الأول الابتدائي');
  assert.equal(gradeShortName('الابتدائي', 'الابتدائي'), 'الابتدائي');
});
