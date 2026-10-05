/**
 * حارس مصائد الاستعلام في Laravel.
 *
 * ─── لماذا ───
 *
 * بعض الأخطاء في Eloquent لا تُصدر تحذيرًا ولا خطأً في التحرير، وتُنتج
 * استعلامًا **صحيحًا نحويًّا** يسأل عن شيءٍ آخر تمامًا. هذه الأخطاء تمرّ
 * المراجعة لأنّها تبدو سليمة، وتظهر بوصفها «سلوكٍ غريب» بعد أشهر.
 *
 * ١ — `DB::table(...)->whereKey($id)`
 *
 *     `whereKey` من Eloquent: يقرأ المفتاح الأوّليّ من النموذج. وعلى بانِي
 *     استعلامٍ خام لا نموذجَ يُسأل، فيصير حرفيًّا `where('key', $id)` —
 *     وعمودُ `key` إمّا غير موجود فيُرمى، وإمّا موجودٌ لمعنًى آخر فيُطابق
 *     صفًّا خاطئًا صامتًا. وقع هذا مرّتين في هذا المستودع.
 *
 * ٢ — `[$defaults] + $overrides`
 *
 *     اتّحاد المصفوفات في PHP يُبقي **اليسار**. فالافتراضات على اليسار
 *     تغلب ما يمرّره المستدعي، والدالّة تتجاهل وسائطها بصمت. وقع هذا
 *     مرّتين أيضًا، وكلتاهما في مُعِدّات اختبارٍ فأنتج اختبارًا يفحص غير ما
 *     يدّعي فحصه.
 */

import { join } from 'node:path';
import { ROOT, expandRoots, walk, read, rel, lineOf, makeResults, report } from './_lib.mjs';
import { whereKeyOnRawBuilder } from './query-traps-rules.mjs';

const { results, pass, fail } = makeResults();

/*
 * الهجرات مستثناة من فحص الاتّحاد: `$row` فيها أساسٌ محلّيّ لا وسيطُ
 * مستدعٍ، فلا شيء يُبتلع. وهي تاريخٌ نُفِّذ — تعديلُها لاحقًا يكذب على من
 * يقرأ ما جرى. أمّا `whereKey` فيُفحص فيها لأنّه خطأٌ مهما كان السياق.
 */
const roots = expandRoots(['backend/laravel/app', 'backend/laravel/tests', 'backend/laravel/database']);
const unionRoots = expandRoots(['backend/laravel/app', 'backend/laravel/tests']);
const files = roots.flatMap((dir) => walk(dir, ['.php']));

if (files.length === 0) {
  fail('files', 'لا ملفّات PHP — لا شيء يُفحص');
  process.exit(report('حارس مصائد الاستعلام', results));
}

// ── ١ — whereKey على بانِي استعلامٍ خام ─────────────────────────────────
const whereKey = [];

for (const file of files) {
  const src = read(file);

  // مسحٌ خطّيّ (query-traps-rules.mjs): التعبير السابق كان يتراجع أُسّيًّا.
  for (const index of whereKeyOnRawBuilder(src)) {
    whereKey.push(`${rel(file)}:${lineOf(src, index)}`);
  }
}

if (whereKey.length > 0) {
  fail('where-key', `whereKey على بانٍ خام — استعمل where('id', …): ${whereKey.join(' · ')}`);
} else {
  pass('where-key', `${files.length} ملفًّا · لا whereKey على بانٍ خام`);
}

// ── ٢ — اتّحادٌ يُبقي الافتراضات فيبتلع الوسائط ─────────────────────────
const union = [];

for (const file of unionRoots.flatMap((dir) => walk(dir, ['.php']))) {
  const src = read(file);
  const lines = src.split('\n');

  lines.forEach((line, index) => {
    /*
     * النمط: سطرٌ يُغلق مصفوفةً ثمّ `+ $something`. الافتراضات مكتوبةٌ
     * حرفيًّا على اليسار والمتغيّر على اليمين — أي أنّ اليمين مُهمَل.
     */
    /*
     * نمطان: اتّحادٌ يمتدّ سطورًا (يبدأ السطر بـ`]`)، واتّحادٌ في سطرٍ واحد.
     * الأوّل وحده كان يُفحص — والثاني أشيع، وقد أُصيب به اختبارٌ كُتب
     * **بعد** هذا الحارس.
     *
     * والجانب الأيمن يجب أن يكون **مدخل مستدعٍ**: `$overrides` و`$attributes`
     * وأخواتها. فـ`['name' => $x] + $context` نمطٌ مشروع — اليسار حقيقةٌ
     * تُثبَّت لا افتراضٌ يُتجاوَز — وتبليغُه تبليغٌ كاذب.
     */
    const CALLER_INPUT = /\$(overrides|attributes|extra|props|data|payload|input|fields|values)\b/;

    if ((/^\s*\]\s*\+\s*\$[a-zA-Z_]\w*\s*[,);]/.test(line)
      || /\[[^\[\]]*=>[^\[\]]*\]\s*\+\s*\$[a-zA-Z_]\w*(?![\w>(])/.test(line))
      && CALLER_INPUT.test(line)) {
      // التعليق الذي يشرح المصيدة ليس وقوعًا فيها.
      const previous = lines[index - 1] ?? '';

      if (! /^\s*(\*|\/\/)/.test(previous)) {
        union.push(`${rel(file)}:${index + 1}`);
      }
    }
  });
}

if (union.length > 0) {
  fail('array-union', `اتّحادٌ يُبقي الافتراضات فتُهمَل الوسائط — اقلبه: ${union.join(' · ')}`);
} else {
  pass('array-union', `${files.length} ملفًّا · لا اتّحادٍ يبتلع وسائطه`);
}

// ── ٣ — لا اسم يحجب دالّةً نهائيّة في PHPUnit ──────────────────────────

/*
 * `TestCase::run()` و`status()` و`name()` نهائيّة في PHPUnit. وتسميةُ مساعدٍ
 * في صنف اختبارٍ بأحدها **خطأٌ قاتل وقت التحميل** يُسقط الملفّ كلّه قبل أن
 * يُنفَّذ اختبارٌ واحد — والرسالة تُقرأ خطأً في المهيّئ لا في التسمية.
 *
 * وقع هذا مرّتين في هذا المستودع، وكلتاهما كلّفت دورة تشغيلٍ كاملة.
 */
const PHPUNIT_FINAL = ['run', 'status', 'name', 'size', 'groups', 'sortId'];

/*
 * ومساعدات HTTP في `Illuminate\Foundation\Testing\TestCase` — الفخّ نفسه
 * بوجهٍ آخر.
 *
 * مساعدٌ خاصّ باسم `put()` أو `get()` يُخفّض مستوى الوصول لدالّةٍ عامّة في
 * الأب: **خطأٌ قاتل وقت التحميل** يُسقط ملفّ الاختبار كلّه، ورسالتُه تتحدّث
 * عن «مستوى الوصول» لا عن الاسم — فتُقرأ عطلًا في المهيّئ.
 *
 * وقع هذا في `IntegrationCredentialsTest` بمساعدٍ اسمه `put()`.
 */
const PHPUNIT_HTTP = ['get', 'post', 'put', 'patch', 'delete', 'json', 'call', 'options', 'head'];

const shadowed = [];

for (const file of expandRoots(['backend/laravel/tests']).flatMap((dir) => walk(dir, ['.php']))) {
  const src = read(file);

  // الحجبُ لا يقع إلّا في وارثٍ من TestCase أو سمةٍ يستعملها — والمزيّفاتُ
  // التي تُنفّذ واجهةً (`implements Store`) أسماؤها عقدُ واجهتها لا حجب.
  if (!/\bextends\s+\\?(?:\w+\\)*\w*TestCase\b|^\s*trait\s+\w+/m.test(src)) continue;

  for (const match of src.matchAll(/(?:private|protected|public)\s+function\s+(\w+)\s*\(/g)) {
    if (PHPUNIT_FINAL.includes(match[1]) || PHPUNIT_HTTP.includes(match[1])) {
      shadowed.push(`${rel(file)}:${lineOf(src, match.index)} → ${match[1]}()`);
    }
  }
}

if (shadowed.length > 0) {
  fail('phpunit-final', `اسمٌ يحجب دالّةً نهائيّة في PHPUnit: ${shadowed.join(' · ')}`);
} else {
  pass(
    'phpunit-final',
    `لا مساعد اختبارٍ يحجب ${PHPUNIT_FINAL.length + PHPUNIT_HTTP.length} دالّةً في PHPUnit`,
  );
}

process.exit(report('حارس مصائد الاستعلام', results));
