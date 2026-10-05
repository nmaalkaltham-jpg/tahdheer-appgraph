#!/usr/bin/env node
/**
 * حارس تقليل الحركة — «تقليل الحركة يكشف، لا يجمّد فحسب» (MIP §2.4).
 *
 * لكل ملف CSS تحت apps/* و packages/*\/src:
 *   · إن أخفى محتوى بانتظار حركة (opacity:0 أو max-block-size:0 مع
 *     transition/animation) وجب أن يحتوي كتلة prefers-reduced-motion: reduce
 *     تكشف المحتوى صراحةً (opacity:1 / max-block-size:none / transform:none…).
 *   · إن عرّف @keyframes وجب أن يحتوي كتلة التعطيل داخل reduce
 *     (animation: none أو animation-duration شبه صفرية).
 *
 * التشغيل: node scripts/guards/guard-reduced-motion.mjs · الخروج: 0 سليم · 1 فشل.
 */

import { expandRoots, walk, read, rel, makeResults, report } from './_lib.mjs';
import { analyze } from './reduced-motion-rules.mjs';

const { results, pass, fail } = makeResults();

const ROOTS = expandRoots(['apps/*', 'packages/*/src']);
const files = ROOTS.flatMap((r) => walk(r, ['.css', '.scss']));

const noReveal = [];
const noKill = [];
const orphans = [];
let hidingFiles = 0;
let keyframeFiles = 0;

for (const f of files) {
  const verdict = analyze(read(f));
  if (verdict.hidesPendingMotion) {
    hidingFiles += 1;
    if (!verdict.revealsUnderReduce) noReveal.push(`${rel(f)} (${verdict.gatedSelectors.slice(0, 3).join('، ')})`);
  }
  if (verdict.hasKeyframes) {
    keyframeFiles += 1;
    if (!verdict.killsUnderReduce) noKill.push(rel(f));
  }
  for (const name of verdict.orphanKeyframes) orphans.push(`${rel(f)} → ${name}`);
}

if (noReveal.length) fail('كشف المخفي', `ملفات تخفي محتوى بلا كتلة كشف: ${noReveal.slice(0, 5).join(' · ')}`);
else pass('كشف المخفي', `${hidingFiles} ملفًا يخفي بانتظار حركة وكلها تكشف تحت reduce.`);

if (noKill.length) fail('تعطيل keyframes', `ملفات @keyframes بلا كتلة تعطيل: ${noKill.slice(0, 5).join(' · ')}`);
else pass('تعطيل keyframes', `${keyframeFiles} ملفًا يعرّف @keyframes وكلها تُعطَّل تحت reduce.`);

// مفاتيحُ حركةٍ لا يستعملها ملفّها: أسماء الوحدات محلّيّة، فلا يستعملها غيرُه
// أيضًا — كودٌ ميّت يُرضي «التعطيل» شكلًا ولا يحرّك شيئًا.
if (orphans.length) fail('keyframes يتيمة', `مُعرَّفة ولا يستعملها ملفّها: ${orphans.slice(0, 5).join(' · ')}`);
else pass('keyframes يتيمة', 'كلّ @keyframes مستعملة في ملفّها.');

const suffix = files.length === 0 ? ' — لا ملفات CSS بعد، الحارس ساكن' : ` عبر ${files.length} ملف CSS`;
process.exit(report(`حارس تقليل الحركة${suffix}`, results));
