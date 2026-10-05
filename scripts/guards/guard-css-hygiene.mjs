#!/usr/bin/env node
/**
 * حارس نظافة CSS/TSX — لا ألوانَ خامّة، لا ‎!important‎ عشوائيّ، لا طبقاتٍ
 * حرفيّة، لا أنماطَ سطريّة للّون والمسافة، ولا علاماتِ تأجيل.
 *
 * النطاق:
 *   CSS  · apps/storefront/**\/*.module.css · apps/storefront/app/globals.css
 *        · packages/ui/src/*.css
 *   TSX  · apps/storefront/** · packages/ui/src/**  (style={{ … }})
 *   علامات · apps/** و packages/** (.ts .tsx .js .mjs .css)
 *
 * الاستثناءات المعلَنة في الشيفرة (السبب إلزاميّ):
 *   CSS  · ‎/* guard-allow: important — السبب *\/‎ قبل السطر أو فيه.
 *   TS   · صيغة ESLint الأصليّة: ‎// eslint-disable-next-line <قاعدة> -- السبب‎
 *          (أو ‎{/* … -- السبب *\/}‎). بلا قاعدةٍ أو بلا سببٍ أو بصيغة الكتلة يسقط.
 *
 * القواعد في `css-hygiene-rules.mjs` (مختبَرة). القائمةُ البيضاء
 * `css-hygiene-allowlist.json`: مصفوفةُ ‎{ "rule": "…", "path": "…" }‎ —
 * فارغةٌ عمدًا؛ كلّ إدخالٍ فيها قرارٌ يُراجَع لا مهربٌ.
 *
 *   node scripts/guards/guard-css-hygiene.mjs            # تقرير
 *   node scripts/guards/guard-css-hygiene.mjs --findings # + يكتب
 *       test-results/hygiene-findings.md مصنَّفًا بنطاق المالك
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, expandRoots, walk, read, rel, makeResults, report } from './_lib.mjs';
import { analyzeCss, analyzeTsx, findMarkers } from './css-hygiene-rules.mjs';

const { results, pass, fail } = makeResults();
const ALLOWLIST_FILE = join(ROOT, 'scripts', 'guards', 'css-hygiene-allowlist.json');
const allow = existsSync(ALLOWLIST_FILE) ? JSON.parse(readFileSync(ALLOWLIST_FILE, 'utf8')) : [];
const allowed = (rule, path) => allow.some((a) => a.rule === rule && (a.path === path || path.startsWith(a.path.replace(/\*$/, ''))));

const storefront = join(ROOT, 'apps', 'storefront');
const uiSrc = join(ROOT, 'packages', 'ui', 'src');
const cssFiles = [
  ...walk(storefront, ['.module.css']),
  join(storefront, 'app', 'globals.css'),
  ...walk(uiSrc, ['.css']),
].filter((f) => existsSync(f));
const tsxFiles = [...walk(storefront, ['.tsx']), ...walk(uiSrc, ['.tsx'])];
const markerFiles = expandRoots(['apps/*', 'packages/*']).flatMap((r) => walk(r, ['.ts', '.tsx', '.js', '.mjs', '.css']));

/** كلّ النتائج: ‎{ rule, path, line, text }‎ بعد القائمة البيضاء. */
const findings = [];
const push = (path, list) => {
  for (const f of list) if (!allowed(f.rule, path)) findings.push({ ...f, path });
};
for (const f of cssFiles) push(rel(f), analyzeCss(read(f)));
for (const f of tsxFiles) push(rel(f), analyzeTsx(read(f)));
const seen = new Set([...cssFiles, ...tsxFiles]);
for (const f of markerFiles) if (!seen.has(f)) push(rel(f), findMarkers(read(f)));

const RULES = [
  ['raw-colour', 'ألوان خامّة', 'لا #hex/rgb/hsl خارج tokens.css.'],
  ['important', '!important', 'لا !important خارج reduce أو guard-allow.'],
  ['z-index', 'z-index حرفيّ', 'لا z-index > 100 بغير رمز.'],
  ['inline-style', 'أنماط سطريّة', 'لا لون/مسافة في style={{ }}.'],
  ['eslint-disable', 'eslint-disable', 'كلّ إسكاتٍ يسمّي قاعدته ومعه -- سبب.'],
  ['test-skip', 'test.skip', 'لا اختبارات متخطّاة.'],
  ['todo', 'TODO/FIXME', 'لا تأجيل في الشيفرة.'],
];

for (const [rule, name, okNote] of RULES) {
  const hits = findings.filter((f) => f.rule === rule);
  if (!hits.length) { pass(name, okNote); continue; }
  const byFile = new Map();
  for (const h of hits) byFile.set(h.path, (byFile.get(h.path) ?? 0) + 1);
  const top = [...byFile].sort((a, b) => b[1] - a[1]).slice(0, 4).map(([p, n]) => `${p} (${n})`).join(' · ');
  fail(name, `${hits.length} في ${byFile.size} ملفًّا: ${top}`);
}

/* ─── ملفّ التوجيه بنطاق المالك ─── */
const SCOPES = [
  ['commerce', /(\/cart|\/checkout|\/account|\/orders|\/login|\/wishlist|commerce-api|components\/(cart|checkout|order)|payment)/],
  ['home-pdp', /(components\/(home|pdp|product|reviews)|app\/products|app\/page\.tsx|lib\/homepage)/],
  ['catalog', /(\(catalog\)|\/stages|\/grades|\/subjects|\/semesters|\/kinds|\/search|\/best-sellers|\/new\b|\/offers|\/bundles|components\/search|catalog)/],
  ['content-chrome', /(SiteHeader|SiteFooter|MobileBottomBar|components\/(chrome|privacy|seo)|app\/(about|blog|contact|credits|help|policies|how-it-works|request|layout\.tsx|globals\.css|error|global-error|not-found))/],
];
const scopeOf = (p) => (p.startsWith('apps/storefront/') ? SCOPES.find(([, re]) => re.test(p))?.[0] ?? 'other' : 'other');

if (process.argv.includes('--findings')) {
  const dir = join(ROOT, 'test-results');
  mkdirSync(dir, { recursive: true });
  const groups = new Map();
  for (const f of findings) {
    const s = scopeOf(f.path);
    if (!groups.has(s)) groups.set(s, []);
    groups.get(s).push(f);
  }
  const lines = ['# نتائج حارس النظافة — بنطاق المالك', '', `الإجماليّ: ${findings.length} · القائمة البيضاء: ${allow.length} إدخال`, ''];
  for (const scope of ['catalog', 'home-pdp', 'commerce', 'content-chrome', 'other']) {
    const list = groups.get(scope) ?? [];
    lines.push(`## ${scope} (${list.length})`, '');
    const byRule = new Map();
    for (const f of list) byRule.set(f.rule, (byRule.get(f.rule) ?? 0) + 1);
    if (list.length) lines.push([...byRule].map(([r, n]) => `${r}: ${n}`).join(' · '), '');
    for (const f of list.sort((a, b) => a.path.localeCompare(b.path) || a.line - b.line)) {
      lines.push(`- \`${f.path}:${f.line}\` **${f.rule}** — \`${f.text.slice(0, 110)}\``);
    }
    lines.push('');
  }
  writeFileSync(join(dir, 'hygiene-findings.md'), lines.join('\n'));
  console.log(`  كُتب ${rel(join(dir, 'hygiene-findings.md'))} (${findings.length} نتيجة)`);
}

process.exit(report(`حارس نظافة CSS/TSX — ${cssFiles.length} CSS · ${tsxFiles.length} TSX · ${markerFiles.length} ملفًّا للعلامات`, results));
