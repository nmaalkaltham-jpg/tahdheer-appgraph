#!/usr/bin/env node
/**
 * تنظيف مُخرَج `standalone` قبل البناء — وتشخيصُ من يمنعه.
 *
 * ## لماذا
 *
 * `next build` يحذف مُخرَج البناء السابق قبل الكتابة. وحين يفشل الحذف يخرج
 * برسالةٍ من نظام الملفّات لا من المشروع:
 *
 *   Error: ENOTEMPTY: directory not empty, rmdir
 *     '…/.next/standalone/node_modules/next'
 *
 * وهي رسالةٌ لا تدلّ على سببٍ ولا على علاج، والحالة **لزجة**: كلّ بناءٍ
 * تالٍ يفشل الفشل نفسه حتّى يُحذف المجلّد يدويًّا. وقد وقع هذا فعلًا في
 * ٢٠٢٦-٠٩-١٨ وعطّل البناء حتّى حُذف المجلّد.
 *
 * ## السبب — مقيسٌ لا مُرجَّح
 *
 * ليست العلّة عمليّةً تمسك ملفًّا. أُعيد إنتاج الفشل بـ`fs.rmSync` نفسها
 * (‏`recursive` و`force`) على هذه الشجرة بالذات ولا ماسك لها (‏`lsof` خالٍ):
 * الحذف يُزيل المحتوى ثمّ يفشل على المجلّد الأعلى وحده بـ`ENOTEMPTY` —
 * سباقُ نظام ملفّاتٍ معروف على macOS/APFS مع الأشجار الكبيرة، تُبطئ فيه
 * إزالةُ آلاف المدخلات تحديثَ بيانات المجلّد فيبدو غيرَ فارغٍ للحظة.
 *
 * ولذلك العلاج **إعادة المحاولة** لا القوّة: `maxRetries` و`retryDelay`
 * موجودتان في Node لهذه الحالة بعينها. و`next build` يستعمل الواجهة نفسها
 * بلا إعادة محاولة، فيتعثّر حيث نجحنا.
 *
 * ويبقى احتمال الماسك قائمًا كسببٍ ثانٍ — `scripts/preview/run.sh` يشغّل
 * الخادم **من داخل** `standalone` — ولذلك يُشخَّص أدناه حين تفشل المحاولات.
 *
 * ## ما يفعله هذا النصّ
 *
 * ١· يحذف الشجرة قبل البناء، فلا تبقى حالةٌ لزجة أيًّا كان سببها.
 * ٢· وإن تعذّر الحذف **سمّى العمليّة الماسكة** وخرج بفشلٍ مقروء، بدل أن
 *    يترك `next` يفشل برسالةٍ لا تُصلَح.
 *
 * والحذف بلا كلفة: الشجرة يعيد `next build` توليدها كاملةً في كلّ مرّة.
 */

import { existsSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const target = join(ROOT, process.argv[2] ?? 'apps/storefront/.next/standalone');
const shown = relative(ROOT, target);

if (!existsSync(target)) process.exit(0);

try {
  /*
   * ستّ محاولاتٍ بتباعد ١٠٠ مللي ثانية: تكفي السباقَ الوصوف أعلاه بفارقٍ
   * واسع، ولا تُطيل بناءً سليمًا (المحاولة الأولى تنجح فيه فورًا).
   */
  rmSync(target, { recursive: true, force: true, maxRetries: 6, retryDelay: 100 });
  process.exit(0);
} catch (error) {
  console.error(`\n  تعذّر حذف ${shown} قبل البناء.\n`);
  console.error(`  ${error.code ?? 'خطأ'}: ${error.message}\n`);

  /*
   * `lsof` يسمّي الماسك. غيابه أو فشله ليس خطأً في ذاته — أدواتٌ للتشخيص
   * لا للقرار، والفشل الأصليّ هو ما يُبلَّغ في الحالين.
   */
  let holders = '';
  try {
    holders = execFileSync('lsof', ['+D', target], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  } catch {
    holders = '';
  }

  const lines = holders.trim().split('\n').filter(Boolean).slice(0, 6);

  if (lines.length > 1) {
    console.error('  عمليّاتٌ تمسك ملفّاتٍ داخل الشجرة:\n');
    for (const line of lines) console.error(`    ${line}`);
    console.error('\n  أوقفها ثمّ أعد البناء (خادم المعاينة يعمل من داخل هذه الشجرة).\n');
  } else {
    console.error('  لا عمليّة ظاهرة تمسكها — راجع الأذونات، أو احذفها يدويًّا:\n');
    console.error(`    rm -rf ${shown}\n`);
  }

  process.exit(1);
}
