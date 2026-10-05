#!/usr/bin/env node
/**
 * جرد استدعاءات إجراءات AJAX و REST — يفتح دمج مراكز الإدارة بأمان.
 *
 * يعمل بـ Node وحده، بلا تبعيات:
 *   node scripts/ajax-usage.mjs              # من جذر القالب
 *   node scripts/ajax-usage.mjs . --json     # مخرج JSON للأتمتة
 *
 * لا يحذف شيئًا ولا يعدّل ملفًا. مخرجه قائمة قرار:
 *   REGISTERED  مسجَّل ومستدعى         → يُحفظ حرفيًا في أي دمج
 *   ORPHAN      مسجَّل ولا استدعاء له  → مرشّح حذف بعد دورة تحقّق، لا قبلها
 *   DUPLICATE   مسجَّل من موضعين       → معالج ميت محتمل
 *   GHOST       مستدعى ولا تسجيل له    → استدعاء مكسور: أخطر حالة
 *
 * @package Tahdheer
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

const ROOT = process.argv[2] && !process.argv[2].startsWith('--') ? process.argv[2] : '.';
const AS_JSON = process.argv.includes('--json');
const SKIP = new Set(['vendor', 'node_modules', '.git', '.phpstan-cache', 'dist', 'build']);

function walk(dir, exts, out = []) {
  let entries;
  try {
    entries = readdirSync(dir);
  } catch {
    return out;
  }
  for (const name of entries) {
    if (SKIP.has(name) || name.startsWith('.')) continue;
    const full = join(dir, name);
    let st;
    try {
      st = statSync(full);
    } catch {
      continue;
    }
    if (st.isDirectory()) walk(full, exts, out);
    else if (exts.some((e) => name.endsWith(e))) out.push(full);
  }
  return out;
}

const rel = (f) => relative(ROOT, f).split(sep).join('/');
const lineOf = (t, i) => t.slice(0, i).split('\n').length;

/* ملفات المصدر: PHP للتسجيل، وكل شيء للاستدعاء. */
const phpFiles = walk(join(ROOT, 'inc'), ['.php']).concat(
  walk(join(ROOT, 'template-parts'), ['.php']),
  walk(join(ROOT, 'woocommerce'), ['.php'])
);
const callFiles = walk(join(ROOT, 'assets'), ['.js']).concat(phpFiles);

/* ------------------------------------------------------------------ *
 * 1) التسجيلات
 * ------------------------------------------------------------------ */
const registered = new Map(); // action -> [{file, line, nopriv, checked}]

for (const f of phpFiles) {
  const src = readFileSync(f, 'utf8');
  for (const m of src.matchAll(/add_action\(\s*['"]wp_ajax_(nopriv_)?([a-z0-9_]+)['"]/gi)) {
    const name = m[2];
    const before = src.slice(Math.max(0, m.index - 400), m.index);
    const list = registered.get(name) || [];
    list.push({
      file: rel(f),
      line: lineOf(src, m.index),
      nopriv: Boolean(m[1]),
      checked: /function_exists\s*\(|has_action\s*\(|did_action\s*\(/.test(before),
    });
    registered.set(name, list);
  }
}

/* مسارات REST مسجَّلة. */
const restRoutes = [];
for (const f of phpFiles) {
  const src = readFileSync(f, 'utf8');
  for (const m of src.matchAll(/register_rest_route\(\s*([^,]+),\s*['"]([^'"]+)['"]/g)) {
    const tail = src.slice(m.index, m.index + 1200);
    restRoutes.push({
      file: rel(f),
      line: lineOf(src, m.index),
      ns: m[1].trim().replace(/^['"]|['"]$/g, ''),
      route: m[2],
      permission: /'permission_callback'\s*=>\s*([^,\n]+)/.exec(tail)?.[1]?.trim() || '— غائب —',
    });
  }
}

/* ------------------------------------------------------------------ *
 * 2) الاستدعاءات
 * ------------------------------------------------------------------ */
const called = new Map(); // action -> [{file, line}]

function noteCall(action, file, src, index) {
  const list = called.get(action) || [];
  list.push({ file: rel(file), line: lineOf(src, index) });
  called.set(action, list);
}

for (const f of callFiles) {
  const src = readFileSync(f, 'utf8');

  // action: 'x'  |  action=x  |  'action', 'x'  |  data-action="x"
  const patterns = [
    /\baction\s*:\s*['"]([a-z0-9_]+)['"]/gi,
    /\baction=([a-z0-9_]+)/gi,
    /['"]action['"]\s*,\s*['"]([a-z0-9_]+)['"]/gi,
    /data-action=['"]([a-z0-9_]+)['"]/gi,
    /append\(\s*['"]action['"]\s*,\s*['"]([a-z0-9_]+)['"]/gi,
  ];
  for (const re of patterns) {
    for (const m of src.matchAll(re)) noteCall(m[1], f, src, m.index);
  }

  // أي ذكر حرفي لاسم إجراء مسجَّل، لالتقاط الأنماط غير القياسية.
  for (const name of registered.keys()) {
    const re = new RegExp(`['"\`]${name}['"\`]`, 'g');
    for (const m of src.matchAll(re)) {
      const ctx = src.slice(Math.max(0, m.index - 60), m.index);
      if (/add_action\(|wp_ajax_/.test(ctx)) continue; // هذا تسجيل لا استدعاء
      noteCall(name, f, src, m.index);
    }
  }
}

/* ------------------------------------------------------------------ *
 * 3) التصنيف
 * ------------------------------------------------------------------ */
const rows = [];
for (const [action, regs] of registered) {
  const calls = called.get(action) || [];
  const unchecked = regs.filter((r) => !r.checked);
  let verdict = calls.length ? 'REGISTERED' : 'ORPHAN';
  if (unchecked.length > 1) verdict = 'DUPLICATE';
  rows.push({ action, verdict, regs, calls: calls.length, callSites: calls.slice(0, 3) });
}
for (const [action, calls] of called) {
  if (registered.has(action)) continue;
  if (!/^tahdheer/i.test(action)) continue; // نتجاهل إجراءات ووردمريس وووكومرس
  rows.push({ action, verdict: 'GHOST', regs: [], calls: calls.length, callSites: calls.slice(0, 3) });
}

const ORDER = { GHOST: 0, DUPLICATE: 1, ORPHAN: 2, REGISTERED: 3 };
rows.sort((a, b) => ORDER[a.verdict] - ORDER[b.verdict] || a.action.localeCompare(b.action));

if (AS_JSON) {
  console.log(JSON.stringify({ generated_at: new Date().toISOString(), ajax: rows, rest: restRoutes }, null, 2));
  process.exit(0);
}

/* ------------------------------------------------------------------ *
 * 4) التقرير
 * ------------------------------------------------------------------ */
const pad = (s, n) => String(s) + ' '.repeat(Math.max(0, n - String(s).length));
const count = (v) => rows.filter((r) => r.verdict === v).length;

console.log('\n  جرد إجراءات AJAX — ' + new Date().toISOString().slice(0, 16).replace('T', ' '));
console.log(
  `  مسجَّل: ${registered.size}   ·   مستدعى: ${count('REGISTERED')}` +
    `   ·   بلا استدعاء: ${count('ORPHAN')}   ·   مكرّر: ${count('DUPLICATE')}   ·   مكسور: ${count('GHOST')}\n`
);

let section = '';
for (const r of rows) {
  if (r.verdict !== section) {
    section = r.verdict;
    const note = {
      GHOST: 'استدعاء بلا تسجيل — يُصلَح فورًا',
      DUPLICATE: 'تسجيل من موضعين بلا حاجز — معالج ميت محتمل',
      ORPHAN: 'بلا استدعاء — مرشّح حذف بعد دورة تحقّق كاملة، لا قبلها',
      REGISTERED: 'مسجَّل ومستدعى — يُحفظ حرفيًا في أي دمج',
    }[section];
    console.log(`\n  ── ${section} · ${note}\n`);
  }
  const where = r.regs.length ? r.regs.map((x) => `${x.file}:${x.line}`).join(' · ') : '—';
  console.log(`  ${pad(r.action, 34)} ${pad(r.calls + ' استدعاء', 14)} ${where}`);
  if (r.verdict === 'GHOST' || r.verdict === 'ORPHAN') {
    for (const c of r.callSites) console.log(`      ↳ ${c.file}:${c.line}`);
  }
}

console.log(`\n  ── مسارات REST · ${restRoutes.length} مسارًا\n`);
for (const r of restRoutes) {
  const flag = r.permission === '— غائب —' ? '  ⚠ بلا permission_callback' : '';
  console.log(`  ${pad(r.ns + r.route, 44)} ${pad(r.permission, 34)} ${r.file}:${r.line}${flag}`);
}

console.log('\n  لا شيء حُذف ولا عُدِّل. هذا جرد قرار فقط.\n');
