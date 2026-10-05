#!/usr/bin/env node
/**
 * اختبارُ الماسح نفسه: مثبّتاتٌ معلومة الحكم في scripts/__fixtures__/sweep
 * تُخدَم على منفذٍ عشوائيّ، ويُشغَّل عليها sweep-viewports، ويُقارَن حكمُه
 * بالمتوقَّع. ماسحٌ لا يُختبر يكذب بصمت — وقد كذب: شرائحُ الدوّار الخاملة
 * عُدّت نصًّا مقصوصًا، والرابطُ الممدود عُدّ تصادمًا، وصفحةُ ٤٠٤ مرّت «✓».
 *
 *   npm run sweep:selftest
 */
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const HERE = dirname(fileURLToPath(import.meta.url));
const FIXTURES = join(HERE, '__fixtures__/sweep');

/** المثبّت ⇒ هل يجب أن يسقط. */
const EXPECT = {
  'carousel-ok.html': false, // شرائح غير حاضرة inert — ليست محتوًى ضائعًا
  'clipped-bad.html': true, // نصٌّ مقصوصٌ فعلًا
  'carousel-active-clipped.html': true, // الشريحة الحاضرة نفسها مقصوصة
  'marquee-ok.html': false, // شريط مرور معلَن مع زرّ إيقاف بالغ — قصٌّ مصمَّم
  'marquee-no-pause.html': true, // الشريط نفسه بلا زرّ إيقاف: لا استثناء
  'carousel-arrows-ok.html': false, // سهما الدوّار فوق شريحته — تداخلٌ مصمَّم
  'overlay-ok.html': false, // رابطٌ ممدود مسمّى وزرٌّ فوقه
  'overlay-covers-button.html': true, // الرابط يغطّي الزرّ
  'overlay-unlabeled.html': true, // رابطٌ ممدود بلا اسم
  'plain-overlap.html': true, // هدفان متصادمان
  'missing-page.html': true, // غير موجود: ٤٠٤ يجب أن يُسقط
};

const server = createServer((req, res) => {
  const file = join(FIXTURES, decodeURIComponent((req.url ?? '/').split('?')[0]));
  if (!file.startsWith(FIXTURES) || !existsSync(file)) {
    res.writeHead(404, { 'content-type': 'text/html' }).end('<!doctype html><title>404</title>');
    return;
  }
  res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }).end(readFileSync(file));
});
await new Promise((ok) => server.listen(0, '127.0.0.1', ok));
const base = `http://127.0.0.1:${server.address().port}`;

let wrong = 0;
for (const [fixture, shouldFail] of Object.entries(EXPECT)) {
  // غير متزامن عمدًا: `spawnSync` يحجز حلقة الأحداث فلا يُجيب الخادمُ
  // المُضمَّن هنا، فيسقط كلُّ مثبّتٍ بمهلة التحميل — حكمٌ كاذبٌ بالعكس.
  const run = await new Promise((resolve) => {
    const child = spawn(
      process.execPath,
      [join(HERE, 'sweep-viewports.mjs'), `--base=${base}`, '--no-discover', '--from=320', '--to=640', '--step=320', `--routes=/${fixture}`],
      { stdio: 'ignore' },
    );
    const timer = setTimeout(() => child.kill('SIGKILL'), 180_000);
    child.on('exit', (status, signal) => {
      clearTimeout(timer);
      resolve({ status, signal });
    });
  });
  const failed = run.status !== 0;
  const ok = failed === shouldFail;
  if (!ok) wrong += 1;
  console.log(`  ${ok ? '✓' : '×'} ${fixture.padEnd(30)} ${failed ? 'يسقط' : 'ينجح'}${ok ? '' : ` — المتوقَّع ${shouldFail ? 'يسقط' : 'ينجح'}`}`);
  if (run.status === null) console.log(`      انتهت المهلة أو قُتل: ${run.signal}`);
}
server.close();
console.log(`\n  ${Object.keys(EXPECT).length} مثبّتًا · ${wrong ? `${wrong} حكمًا خاطئًا` : 'كلّ الأحكام صحيحة'}\n`);
process.exit(wrong ? 1 : 0);
