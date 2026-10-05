/**
 * منظّف مُخرَج `standalone` — العقد الذي يحمي البناء.
 *
 * العطب الأصليّ: `next build` يفشل بـ`ENOTEMPTY` عند حذف مُخرَج بناءٍ سابق،
 * والحالة لزجة فيفشل كلّ بناءٍ تالٍ. وقياسٌ على المسار الحقيقيّ أظهر الفشل
 * ١ من ٦ بلا إعادة محاولة، و٠ من ١٠ معها.
 *
 * وما يُختبر هنا هو ما يمكن اختباره حتميًّا: الحذف، والصمت عند الغياب،
 * والفشل المقروء. أمّا السباق نفسه فاحتماليّ ولا يُثبَّت في اختبار.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const SCRIPT = join(ROOT, 'scripts/clean-standalone.mjs');

/** يُنادى كما يناديه `prebuild`: بمسارٍ نسبيّ إلى جذر المستودع. */
function run(relativePath) {
  try {
    execFileSync(process.execPath, [SCRIPT, relativePath], {
      cwd: ROOT,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    return { code: 0 };
  } catch (error) {
    return { code: error.status, stderr: String(error.stderr ?? '') };
  }
}

/** شجرةٌ متداخلة داخل المستودع — النظام نفسه الذي يقع عليه السباق. */
function tree() {
  const base = mkdtempSync(join(ROOT, '.tmp-standalone-'));
  const deep = join(base, 'node_modules', 'next', 'dist', 'server');
  mkdirSync(deep, { recursive: true });
  for (let i = 0; i < 40; i += 1) writeFileSync(join(deep, `chunk-${i}.js`), 'x'.repeat(512));

  return base;
}

test('يحذف شجرة المُخرَج كاملةً', () => {
  const base = tree();
  const relative = base.slice(ROOT.length + 1);

  assert.equal(run(relative).code, 0);
  assert.equal(existsSync(base), false, 'بقيت الشجرة بعد التنظيف');
});

test('غياب الشجرة نجاحٌ صامت لا فشل', () => {
  /* أوّل بناءٍ في شجرة عملٍ نظيفة: لا مُخرَج سابق، ولا يجوز أن يتعثّر. */
  const result = run('.tmp-standalone-does-not-exist');

  assert.equal(result.code, 0);
});

test('تشغيله مرّتين متتاليتين لا يفشل في الثانية', () => {
  const base = tree();
  const relative = base.slice(ROOT.length + 1);

  assert.equal(run(relative).code, 0);
  assert.equal(run(relative).code, 0, 'الاستدعاء الثاني فشل على شجرةٍ محذوفة');
});

test('يفشل بوضوح ويقترح علاجًا حين يتعذّر الحذف', { skip: process.getuid?.() === 0 ? 'الجذر يتجاوز أذونات المجلّد فينجح الحذف' : false },
  () => {
  /*
   * مجلّدٌ بلا إذن كتابةٍ لأبيه يمنع الحذف. والمطلوب أن يخرج بفشل **ويقول
   * ماذا يُفعل** — لا أن يمرّ صامتًا فيفشل `next` بعده برسالةٍ لا تُصلَح.
   */
  const base = tree();
  const relative = base.slice(ROOT.length + 1);
  const locked = join(base, 'locked');
  mkdirSync(locked, { recursive: true });
  writeFileSync(join(locked, 'file.txt'), 'x');

  execFileSync('chmod', ['555', locked]);

  try {
    const result = run(relative);

    assert.equal(result.code, 1, 'مرّ صامتًا رغم تعذّر الحذف');
    assert.match(result.stderr, /تعذّر حذف/);
    assert.match(result.stderr, /rm -rf|تمسك ملفّات/, 'فشل بلا علاجٍ مقترح');
  } finally {
    execFileSync('chmod', ['755', locked]);
    rmSync(base, { recursive: true, force: true, maxRetries: 6, retryDelay: 100 });
  }
});
