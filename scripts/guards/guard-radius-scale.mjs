#!/usr/bin/env node
/**
 * حارس سلّم الزوايا — نظام التصميم الموحّد (docs/architecture/DESIGN-SYSTEM-2026-09-25.md §1).
 *
 * في ‎apps/storefront/**\/*.module.css‎ و ‎packages/ui/src/*.module.css‎: كلّ
 * ‎border-radius‎ (والطويلة ‎border-*-*-radius‎ منطقيّةً وفيزيائيّة) قيمُه من السلّم
 * ‎{8,10,12,16,20,24,999}px · 50%‎ أو ‎var(--r-*)‎. ‎var(--x)‎ آخر يُحلّ من tokens.css
 * ويُقبل إن وقعت قيمته على السلّم. ‎0‎ و ‎calc()‎ (قاعدة التداخل) و ‎inherit‎ لا تُعدّ.
 *
 * سقّاطةٌ (ratchet): ‎scripts/guards/radius-allowlist.json‎ يحمل عدد المخالفات لكلّ ملفّ
 * اليوم. يفشل الحارس إن زاد عددُ ملفٍّ أو خالف ملفٌّ جديد؛ النقصان يُطبع «قابلًا للتضييق»
 * ليُنزِل مَن يهاجر الأسطحَ القيمةَ (أو يحذف المدخل) حتّى الصفر.
 *
 *   node scripts/guards/guard-radius-scale.mjs [--list]
 */

import { join } from 'node:path';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { ROOT, walk, read, rel, makeResults, report } from './_lib.mjs';

export const SCALE_PX = new Set([8, 10, 12, 16, 20, 24, 999]);
const PROP = /(?:^|[{;\s])(border(?:-(?:start|end|top|bottom)-(?:start|end|left|right))?-radius)\s*:\s*([^;}]+)/g;

const stripComments = (s) => s.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));

/** قيم الرموز من tokens.css (‎--x: v‎) — تُحلّ الإحالات ‎var()‎ المتسلسلة. */
export function tokenValues(css) {
  const raw = new Map();
  for (const m of stripComments(css).matchAll(/(--[\w-]+)\s*:\s*([^;}]+)/g)) if (!raw.has(m[1])) raw.set(m[1], m[2].trim());
  const resolve = (v, depth = 0) =>
    depth > 8 ? v : v.replace(/var\(\s*(--[\w-]+)\s*(?:,[^)]*)?\)/g, (_, n) => (raw.has(n) ? resolve(raw.get(n), depth + 1) : `var(${n})`));
  return new Map([...raw].map(([k, v]) => [k, resolve(v)]));
}

/** هل مكوّنٌ واحد من قيمة الزاوية على السلّم؟ */
export function partOk(part, tokens = new Map()) {
  if (/^(0|0px|inherit|initial|unset|revert)$/.test(part)) return true;
  if (part === '50%') return true;
  const px = part.match(/^(\d+(?:\.\d+)?)px$/);
  if (px) return SCALE_PX.has(Number(px[1]));
  const v = part.match(/^var\(\s*(--[\w-]+)/);
  if (v) {
    if (v[1].startsWith('--r-')) return true;
    const resolved = tokens.get(v[1]);
    return resolved !== undefined && !resolved.includes('var(') && resolved.split(/\s+/).every((p) => partOk(p, tokens));
  }
  return false;
}

/** مخالفات ملفّ: ‎[{ line, prop, value }]‎. */
export function findRadiusViolations(css, tokens = new Map()) {
  const src = stripComments(css);
  const out = [];
  for (const m of src.matchAll(PROP)) {
    const value = m[2].trim();
    if (/calc\(|min\(|max\(|clamp\(/.test(value)) continue;
    const parts = value.replace(/\s*\/\s*/g, ' ').split(/\s+(?![^(]*\))/).filter(Boolean);
    if (!parts.every((p) => partOk(p, tokens))) {
      out.push({ line: src.slice(0, m.index).split('\n').length, prop: m[1], value });
    }
  }
  return out;
}

/** مقارنة السقّاطة: ‎counts/allow‎ خرائط ملفّ ← عدد. */
export function ratchet(counts, allow) {
  const worse = [];
  const tighter = [];
  for (const [f, n] of Object.entries(counts)) {
    const cap = allow[f] ?? 0;
    if (n > cap) worse.push({ file: f, count: n, cap });
  }
  for (const [f, cap] of Object.entries(allow)) {
    const n = counts[f] ?? 0;
    if (n < cap) tighter.push({ file: f, count: n, cap });
  }
  return { worse, tighter };
}

function main() {
  const { results, pass, fail } = makeResults();
  const tokensFile = join(ROOT, 'packages', 'design-system', 'src', 'tokens.css');
  const tokens = existsSync(tokensFile) ? tokenValues(read(tokensFile)) : new Map();
  const files = [
    ...walk(join(ROOT, 'apps', 'storefront'), ['.module.css']),
    ...walk(join(ROOT, 'packages', 'ui', 'src'), ['.module.css']),
  ];
  const counts = {};
  const detail = {};
  for (const f of files) {
    const v = findRadiusViolations(read(f), tokens);
    if (v.length) {
      counts[rel(f)] = v.length;
      detail[rel(f)] = v;
    }
  }
  const allowFile = join(ROOT, 'scripts', 'guards', 'radius-allowlist.json');
  const allow = existsSync(allowFile) ? JSON.parse(read(allowFile)).files ?? {} : {};
  const { worse, tighter } = ratchet(counts, allow);
  const total = Object.values(counts).reduce((a, b) => a + b, 0);
  const cap = Object.values(allow).reduce((a, b) => a + b, 0);

  if (process.argv.includes('--list')) {
    for (const [f, list] of Object.entries(detail)) for (const v of list) console.log(`  ${f}:${v.line}  ${v.prop}: ${v.value}`);
  }

  if (worse.length) {
    fail('سقّاطة الزوايا', `${worse.length} ملفًّا زاد أو جديد: ${worse.slice(0, 5).map((w) => `${w.file} (${w.count} > ${w.cap})`).join(' · ')} — استعمل var(--r-*)`);
  } else {
    pass('سقّاطة الزوايا', `لا ملفّ زاد عن حدّه — ${Object.keys(counts).length} ملفًّا ما زال يخالف.`);
  }
  pass('المتبقّي', `${total} زاويةً خارج السلّم (الحدّ ${cap}) — الهدف صفر.`);
  if (tighter.length) {
    pass('قابل للتضييق', `${tighter.length}: ${tighter.slice(0, 5).map((t) => `${t.file} ${t.cap}→${t.count}`).join(' · ')} — أنزِل radius-allowlist.json.`);
  }
  return report(`حارس سلّم الزوايا — ${files.length} وحدة CSS · ${total} مخالفة`, results);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) process.exit(main());
