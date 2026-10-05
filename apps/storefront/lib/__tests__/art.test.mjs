/**
 * سجلّ الرسوم — المطابقة العربيّة، وصدق الأبعاد مع الملفّات، والإسناد.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import {
  subjectArt, stageArt, SUBJECT_ART, STAGE_ART, EMPTY_ART, HERO_ART, TEACHER_ART, PAYMENT_ART, CREDITS,
} from '../art.ts';

const PUBLIC = fileURLToPath(new URL('../../public', import.meta.url));

/** أبعاد WebP من الترويسة (VP8 / VP8L / VP8X). */
function webpSize(path) {
  const b = readFileSync(path);
  assert.equal(b.toString('ascii', 0, 4), 'RIFF');
  assert.equal(b.toString('ascii', 8, 12), 'WEBP');
  const kind = b.toString('ascii', 12, 16);
  if (kind === 'VP8X') return [1 + b.readUIntLE(24, 3), 1 + b.readUIntLE(27, 3)];
  if (kind === 'VP8L') {
    const v = b.readUInt32LE(21);
    return [1 + (v & 0x3fff), 1 + ((v >> 14) & 0x3fff)];
  }
  return [b.readUInt16LE(26) & 0x3fff, b.readUInt16LE(28) & 0x3fff];
}

test('المادّة: أسماءٌ عربيّة بصيغها وslugs إنجليزيّة', () => {
  assert.equal(subjectArt('لغتي'), SUBJECT_ART.arabic);
  assert.equal(subjectArt('لغتي الجميلة'), SUBJECT_ART.arabic);
  assert.equal(subjectArt('اللغة العربية'), SUBJECT_ART.arabic);
  assert.equal(subjectArt('اللغة العربيّة'), SUBJECT_ART.arabic);
  assert.equal(subjectArt('اللغة الإنجليزية'), SUBJECT_ART.english);
  assert.equal(subjectArt('english'), SUBJECT_ART.english);
  assert.equal(subjectArt('الاجتماعيات'), SUBJECT_ART.socialStudies);
  assert.equal(subjectArt('الدراسات الاجتماعية والمواطنة'), SUBJECT_ART.socialStudies);
  assert.equal(subjectArt('social-studies'), SUBJECT_ART.socialStudies);
  assert.equal(subjectArt('التاريخ الحديث'), SUBJECT_ART.socialStudies);
  assert.equal(subjectArt('الرياضيات'), SUBJECT_ART.math);
  assert.equal(subjectArt('math'), SUBJECT_ART.math);
  assert.equal(subjectArt('العلوم'), SUBJECT_ART.science);
  assert.equal(subjectArt('الفيزياء'), SUBJECT_ART.physics);
  assert.equal(subjectArt('الكيمياء'), SUBJECT_ART.chemistry);
  assert.equal(subjectArt('الأحياء'), SUBJECT_ART.biology);
  assert.equal(subjectArt('المهارات الرقمية'), SUBJECT_ART.computer);
  assert.equal(subjectArt('الحاسب الآلي'), SUBJECT_ART.computer);
  assert.equal(subjectArt('التقنية الرقمية'), SUBJECT_ART.computer);
  assert.equal(subjectArt('التفكير الناقد'), SUBJECT_ART.criticalThinking);
});

test('المادّة: لا أيقونة مستعارة — الشرعيّة وما لا رسم صادقًا له تُرجع null', () => {
  for (const name of ['التربية الإسلامية', 'الدراسات الإسلامية', 'العلوم الشرعية', 'القرآن الكريم', 'التجويد',
    'الحديث', 'الفقه', 'islamic-studies', 'التربية البدنية', 'التربية الفنية', '', '   ']) {
    assert.equal(subjectArt(name), null, name);
  }
});

test('المادّة: لا مطابقة جزئيّة داخل كلمة', () => {
  // «المعلومات» تحوي «علوم» حرفيًّا — ويجب ألّا تصير علومًا.
  assert.equal(subjectArt('المعلومات'), null);
  assert.equal(subjectArt('تقنية المعلومات'), SUBJECT_ART.computer);
});

test('المرحلة: أسماءٌ وslugs وأسماء صفوف', () => {
  assert.equal(stageArt('رياض الأطفال'), STAGE_ART.kindergarten);
  assert.equal(stageArt('kg'), STAGE_ART.kindergarten);
  assert.equal(stageArt('المرحلة الابتدائية'), STAGE_ART.primary);
  assert.equal(stageArt('الصف الأول المتوسط'), STAGE_ART.middle);
  assert.equal(stageArt('secondary'), STAGE_ART.secondary);
  assert.equal(stageArt('الثانوية العامة'), STAGE_ART.secondary);
  assert.equal(stageArt('الرياضيات'), null);
});

const ALL = [
  ...Object.values(SUBJECT_ART), ...Object.values(STAGE_ART), ...Object.values(EMPTY_ART),
  ...Object.values(HERO_ART), ...Object.values(TEACHER_ART), ...Object.values(PAYMENT_ART),
];

test('كلّ رسمٍ ملفُّه موجود، و@2x بضعف المقاس أو دونه بلا تكبير، والنسبة صادقة', () => {
  for (const a of ALL) {
    for (const src of [a.src, a.darkSrc].filter(Boolean)) {
      const path = PUBLIC + src;
      assert.ok(existsSync(path), src);
      const [w, h] = webpSize(path);
      assert.ok(w <= a.width * 2 && w >= a.width, `${src}: ${w} مقابل ${a.width}`);
      assert.ok(Math.abs(w / h - a.width / a.height) < 0.01, `${src}: نسبة ${w}/${h}`);
      const one = path.replace('@2x.webp', '.webp');
      assert.deepEqual(webpSize(one), [a.width, a.height], one);
    }
  }
  for (const v of [HERO_ART.labScene.vectorSrc, HERO_ART.labScene.darkVectorSrc]) {
    assert.ok(existsSync(PUBLIC + v), v);
  }
});

test('لا رسم بلا إسنادٍ معروف', () => {
  for (const a of ALL) {
    assert.ok(CREDITS[a.credit], a.src);
    assert.equal(typeof a.alt, 'string');
  }
});
