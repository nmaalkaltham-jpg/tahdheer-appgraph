/**
 * حارس الأسرار: لا اعتمادٌ حيّ في شجرةٍ مُتعقَّبة.
 *
 * ─── لماذا ───
 *
 * كلمةُ مرور المالك المحلّيّة وصلت التاريخ مرّةً في ملاحظة تدقيق
 * (ad92438). لم تُدفع لبعيد ودُوِّرت، لكنّ الدرس ثابت: الحارسُ يمنع، والتاريخُ
 * لا يُنسى.
 *
 * ─── ما يحرسه ───
 *
 * ١ — مفاتيح Tap الحيّة أو التجريبيّة (`sk_live_…`, `sk_test_…`) خارج الاختبارات
 *     التي تستعمل قيمًا صفريّة/وهميّة واضحة.
 * ٢ — `ADMIN_SEED_PASSWORD=<قيمة>` أو `TAP_SECRET_KEY=<قيمة>` أو `APP_KEY=base64:…`
 *     بقيمةٍ حقيقيّة في ملفٍّ متعقَّب (المثال `.env.example` يُترك فارغًا).
 * ٣ — رموزُ Google `ya29.` وتوكنات `xox[bp]-` وGitHub `ghp_`.
 * ٤ — ملفّات `.env` (غير `.env.example`) مُتعقَّبة أصلًا.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

const tracked = execFileSync('git', ['ls-files', '-z'], { cwd: ROOT })
  .toString('utf8')
  .split('\0')
  .filter(Boolean);

const envFiles = tracked.filter((f) => /(^|\/)\.env(\.[^/]+)?$/.test(f) && !/\.example$/.test(f));
if (envFiles.length === 0) pass('env-files', 'لا ملفّ .env متعقَّب');
else fail('env-files', `ملفّ بيئة متعقَّب: ${envFiles.join(', ')}`);

const TEXT = /\.(php|ts|tsx|js|mjs|cjs|json|yml|yaml|md|sh|env|example|txt|toml|ini|conf|xml)$/;
const PATTERNS = [
  // مفتاحُ Tap الحقيقيّ خليطٌ من أرقامٍ وحروف؛ «abcdefgh» و«0000» في الاختبارات ليست مفاتيح.
  ['tap-key', /\bsk_(live|test)_(?=[A-Za-z0-9]*[0-9])(?=[A-Za-z0-9]*[A-Za-z])(?!0{6,}|9{6,})[A-Za-z0-9]{16,}\b/g, 'مفتاح Tap يبدو حقيقيًّا'],
  ['seed-password', /^\s*(?:export\s+)?ADMIN_SEED_PASSWORD=(?!\s*$|\s*["']?\$)["']?[^\s"'#]{4,}/gm, 'كلمة بذر اللوحة بقيمةٍ حرفيّة'],
  ['tap-secret-env', /^\s*(?:export\s+)?TAP_SECRET_KEY=(?!\s*$|\s*["']?\$)["']?[^\s"'#]{8,}/gm, 'TAP_SECRET_KEY بقيمةٍ حرفيّة'],
  ['app-key', /^\s*APP_KEY=base64:[A-Za-z0-9+/=]{40,}/gm, 'APP_KEY حقيقيّ في ملفٍّ متعقَّب'],
  ['oauth-token', /\b(ya29\.[A-Za-z0-9_-]{20,}|xox[bp]-[A-Za-z0-9-]{20,}|ghp_[A-Za-z0-9]{30,})\b/g, 'رمز وصولٍ حيّ'],
];

const hits = [];
for (const file of tracked) {
  if (!TEXT.test(file)) continue;
  let text;
  try {
    text = readFileSync(join(ROOT, file), 'utf8');
  } catch {
    continue;
  }
  for (const [name, re, why] of PATTERNS) {
    re.lastIndex = 0;
    if (re.test(text)) hits.push(`${name} · ${why} — ${file}`);
  }
}

if (hits.length === 0) pass('tracked-secrets', `${tracked.length} ملفًّا · لا اعتماد حيّ`);
else for (const h of hits) fail('tracked-secrets', h);

/*
 * حدُّ المتصفّح: لا سرّ يعبر إلى حزمة Next. `NEXT_PUBLIC_*` يُضمَّن في الحزمة
 * وقت البناء، فاسمٌ يحمل SECRET/SK/PRIVATE فيه خطأٌ بالتعريف؛ وبادئة
 * `sk_` في شيفرة الواجهات خطأٌ بالتعريف أيضًا.
 */
const FRONT = /^(apps\/(storefront|admin)\/|packages\/)/;
const frontHits = [];
for (const file of tracked) {
  if (!FRONT.test(file) || !/\.(ts|tsx|js|mjs|cjs|json|env\.example)$/.test(file)) continue;
  let text;
  try {
    text = readFileSync(join(ROOT, file), 'utf8');
  } catch {
    continue;
  }
  if (/NEXT_PUBLIC_[A-Z0-9_]*(SECRET|_SK_|PRIVATE|SK_KEY)/.test(text)) frontHits.push(`اسم NEXT_PUBLIC_ يحمل سرًّا — ${file}`);
  if (/\bsk_(test|live)_/.test(text)) frontHits.push(`مفتاح Tap سرّيّ في شيفرة واجهة — ${file}`);
}
if (frontHits.length === 0) pass('browser-boundary', 'لا سرّ يعبر إلى حزم الواجهات');
else for (const h of frontHits) fail('browser-boundary', h);

report('حارس الأسرار', results);
