#!/usr/bin/env node
/**
 * حارس السجل — packages/registry: قانون «التخطيط خاصية لا ملف» (MIP §2.5).
 *
 *   · كل معرّف بصيغة tah/{component}.{٤ أرقام} (رقمان للعائلة ورقمان للاختيار).
 *   · لا معرّف مكرّرًا.
 *   · عائلة كل مدخل موجودة في تعريف العائلات (عند توفر استيراد منظّم).
 *   · الأعداد تُشتقّ ولا تُكتب: الهدف الموثّق عائلات×15 = 300 اختيار.
 *
 * يحاول استيراد packages/registry/src ديناميكيًا (Node يجرّد الأنواع)،
 * وإن تعذّر يحلّل النص. إن لم يوجد السجل بعد فالحارس ساكن (نجاح صريح).
 *
 * التشغيل: node scripts/guards/guard-registry.mjs · الخروج: 0 سليم · 1 فشل.
 */

import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { ROOT, walk, read, rel, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

const SRC = join(ROOT, 'packages', 'registry', 'src');
const ID_FORMAT = /^tah\/[a-z0-9_-]+\.\d{4}$/;
const TARGET_PER_FAMILY = 15; // موثّق في MIP §2.5: 20 عائلة × 15 = 300

if (!existsSync(SRC)) {
  pass('وجود السجل', 'packages/registry/src لم يُبْنَ بعد — الحارس ساكن حتى يوجد.');
  process.exit(report('حارس السجل — tah/{component}.{family:02d}{item:02d}', results));
}

/** محاولة استيراد منظّم: نبحث عن تصدير مصفوفة مدخلات وتعريف عائلات. */
async function structuredImport() {
  for (const candidate of ['index.ts', 'index.mts', 'index.mjs', 'index.js', 'registry.ts']) {
    const f = join(SRC, candidate);
    if (!existsSync(f)) continue;
    try {
      const mod = await import(pathToFileURL(f).href);
      const entries = mod.registry ?? mod.REGISTRY ?? mod.entries ?? mod.default;
      const families = mod.families ?? mod.FAMILIES ?? null;
      if (Array.isArray(entries) && entries.length && entries[0]?.id) return { entries, families };
    } catch {
      return null; // TS غير قابل للتجريد المباشر — نلجأ للتحليل النصي.
    }
  }
  return null;
}

const structured = await structuredImport();
let ids = [];
let familiesDefined = null; // مصفوفة معرّفات عائلات إن توفّرت منظّمة
let entryFamilies = null; // family لكل مدخل إن توفّرت منظّمة

if (structured) {
  ids = structured.entries.map((e) => String(e.id));
  entryFamilies = structured.entries.map((e) => e.family).filter((f) => f !== undefined);
  if (Array.isArray(structured.families)) {
    familiesDefined = structured.families.map((f) => (typeof f === 'string' ? f : f?.id ?? f?.slug));
  }
} else {
  // ‎__tests__‎ تُستبعد: معرّفات الاختبارات عيّنات مكرّرة عمدًا لا مدخلات سجل.
  const files = walk(SRC, ['.ts', '.tsx', '.mts', '.mjs', '.js', '.json']).filter(
    (f) => !f.includes('__tests__'),
  );
  const seen = [];
  for (const f of files) {
    for (const m of read(f).matchAll(/['"](tah\/[^'"]+)['"]/g)) seen.push(m[1]);
  }
  ids = seen;
}

if (ids.length === 0) {
  pass('مدخلات السجل', 'السجل موجود لكن بلا معرّفات tah/ بعد — الحارس ساكن.');
  process.exit(report('حارس السجل — tah/{component}.{family:02d}{item:02d}', results));
}

/* ─── فحص ١: صيغة المعرّف ─── */
{
  const malformed = [...new Set(ids.filter((id) => !ID_FORMAT.test(id)))];
  if (malformed.length) fail('صيغة المعرّف', `${malformed.length} معرّفًا مخالفًا: ${malformed.slice(0, 5).join(' · ')}`);
  else pass('صيغة المعرّف', `كل المعرّفات (${ids.length}) على صيغة tah/{component}.{٤ أرقام}.`);
}

/* ─── فحص ٢: التفرّد ─── */
{
  const seen = new Set();
  const dupes = new Set();
  for (const id of ids) (seen.has(id) ? dupes : seen).add(id);
  if (dupes.size) fail('تفرّد المعرّفات', `مكرّرة: ${[...dupes].slice(0, 5).join(' · ')}`);
  else pass('تفرّد المعرّفات', `${seen.size} معرّفًا فريدًا بلا تكرار.`);
}

/* ─── فحص ٣: وجود عائلة كل مدخل ─── */
{
  if (familiesDefined && entryFamilies && entryFamilies.length) {
    const famSet = new Set(familiesDefined);
    const unknown = [...new Set(entryFamilies.filter((f) => !famSet.has(f)))];
    if (unknown.length) fail('عائلات المدخلات', `مدخلات تشير لعائلة غير معرّفة: ${unknown.slice(0, 5).join(' · ')}`);
    else pass('عائلات المدخلات', `كل المدخلات تشير إلى عائلات معرّفة (${famSet.size} عائلة).`);
  } else {
    // نصّيًا: نشتق أرقام العائلات من الخانتين الأوليين ونتأكد أنها متعاقبة من 01.
    const famNums = [...new Set(ids.filter((id) => ID_FORMAT.test(id)).map((id) => id.slice(-4, -2)))].sort();
    const gaps = famNums.filter((f, i) => Number(f) !== i + 1);
    if (gaps.length) fail('عائلات المدخلات', `أرقام عائلات غير متعاقبة من 01: ${famNums.join('،')}`);
    else pass('عائلات المدخلات', `${famNums.length} عائلة مشتقة من المعرّفات، متعاقبة 01..${famNums.at(-1)}.`);
  }
}

/* ─── فحص ٤: الأعداد مشتقة والهدف موثّق ─── */
{
  const validIds = ids.filter((id) => ID_FORMAT.test(id));
  const famCount = new Set(validIds.map((id) => id.slice(-4, -2))).size;
  const target = famCount * TARGET_PER_FAMILY;
  const perFamily = {};
  for (const id of validIds) {
    const f = id.slice(-4, -2);
    perFamily[f] = (perFamily[f] ?? 0) + 1;
  }
  const over = Object.entries(perFamily).filter(([, n]) => n > TARGET_PER_FAMILY);
  if (over.length) {
    fail('الأعداد المشتقة', `عائلات تجاوزت ${TARGET_PER_FAMILY}: ${over.map(([f, n]) => `${f}=${n}`).join(' · ')}`);
  } else {
    pass('الأعداد المشتقة', `${validIds.length} اختيارًا فعليًا · ${famCount} عائلة · الهدف ${famCount}×${TARGET_PER_FAMILY}=${target}${validIds.length === target ? ' (مكتمل)' : ' (قيد البناء)'}.`);
  }
}

process.exit(report('حارس السجل — tah/{component}.{family:02d}{item:02d}', results));
