/**
 * يقارن مخطّط قاعدة التطوير بالمخطّط الذي تبنيه الهجرات.
 *
 * ─── العيب الذي يكشفه ───
 *
 * قاعدة التطوير تتراكم: عمودٌ يُضاف بيدٍ لتجربةٍ سريعة، ويُستعمل، ويُنسى.
 * وقاعدة الاختبار تُبنى بالهجرات وحدها فلا تعرفه. فيعمل كلّ شيء حيث يُنظر
 * إليه، وينهار النشر النظيف في الإنتاج عند أوّل طلبٍ يلمس العمود — بعد أن
 * تُعلَن الترقية ناجحة.
 *
 * وُجد بهذا الفحص `refunds.notified_at`: موجودٌ في التطوير، ولا هجرة تُنشئه.
 *
 * ─── لماذا سكربتٌ لا حارس ───
 *
 * يحتاج قاعدتين تعملان. الحرّاس تعمل بلا بنيةٍ تحتيّة بالتصميم، وحارسٌ
 * يفشل حين لا تعمل الحاويات يُدرَّب المشغّل على تجاهله.
 *
 *   node scripts/check-schema-drift.mjs
 */

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const CONTAINER = process.env.PG_CONTAINER ?? 'tahdheer-local-postgres';
const USER = process.env.PG_USER ?? 'tahdheer';
const DEV = process.env.DEV_DB ?? 'tahdheer';
const API = process.env.API_CONTAINER ?? 'tahdheer-local-api';

/*
 * كان يقرأ `tahdheer_test` كما تركه آخرُ تشغيلٍ للاختبارات — أي حالةً سابقة
 * لا مخطّطًا مُثبتًا: قاعدةٌ لم تُهاجَر بعد أو تُسقَط في منتصف تشغيلٍ متزامن
 * تُنتج «انحرافًا» لا وجود له. الآن يُهاجِر قاعدةً جديدةً خاصّةً به من
 * الهجرات وحدها ثمّ يُسقطها. `TEST_DB=...` صريحًا يقرأ قاعدةً قائمة كما هي.
 */
const OWN_TEST = !process.env.TEST_DB;
const TEST = process.env.TEST_DB ?? `tahdheer_test_schema_${Date.now()}_${process.pid}`;

function psqlAdmin(sql) {
  execFileSync('docker', ['exec', CONTAINER, 'psql', '-U', USER, '-d', 'postgres', '-tAq', '-c', sql], { stdio: 'pipe' });
}

function dropOwn() {
  if (OWN_TEST) {
    try {
      psqlAdmin(`DROP DATABASE IF EXISTS ${TEST} WITH (FORCE)`);
    } catch {
      /* الإسقاطُ تنظيف — فشلُه لا يغيّر حكم الانحراف */
    }
  }
}

if (OWN_TEST) {
  try {
    psqlAdmin(`CREATE DATABASE ${TEST} OWNER ${USER}`);
    execFileSync(
      'docker',
      ['exec', '-e', `DB_DATABASE=${TEST}`, '-e', 'CACHE_STORE=array', API, 'php', 'artisan', 'migrate', '--force', '--no-interaction'],
      { stdio: 'pipe', maxBuffer: 32 * 1024 * 1024 },
    );
  } catch (error) {
    dropOwn();
    console.error(`× تعذّر تهجير قاعدةٍ جديدة للمقارنة: ${String(error.stderr ?? error.message).slice(0, 600)}`);
    process.exit(1);
  }
}

const QUERY = `select table_name || '.' || column_name
  from information_schema.columns
  where table_schema = 'public'
  order by 1`;

function columns(database) {
  const out = execFileSync(
    'docker',
    ['exec', CONTAINER, 'psql', '-U', USER, '-d', database, '-tAc', QUERY],
    { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 },
  );

  return new Set(out.split('\n').map((line) => line.trim()).filter(Boolean));
}

let dev;
let test;

try {
  dev = columns(DEV);
  test = columns(TEST);
} catch (error) {
  dropOwn();
  console.error(`× تعذّر قراءة المخطّطين: ${error.message}`);
  console.error('  تحتاج الحاويات أن تعمل، وقاعدة الاختبار أن تكون مُهاجَرة.');
  process.exit(1);
}
dropOwn();

/*
 * جداول الهجرات نفسها تختلف بطبيعتها (سجلّ ما نُفّذ)، ولا تُقارن.
 */
const IGNORED = /^(migrations)\./;

/*
 * سجلّ الرواسب: ما خلّفته شيفرةٌ حُذفت عند دمج المستودع. يُحصى ولا يُتجاهل —
 * فالانحراف الجديد يفشل، والمدخل الذي صارت له هجرة يفشل أيضًا.
 */
const LEDGER = join(dirname(fileURLToPath(import.meta.url)), 'known-schema-residue.json');

const residue = new Set();

if (existsSync(LEDGER)) {
  const { tables } = JSON.parse(readFileSync(LEDGER, 'utf8'));

  for (const [table, columns] of Object.entries(tables)) {
    for (const column of columns) residue.add(`${table}.${column}`);
  }
}

const rawMissingInTest = [...dev].filter((c) => ! test.has(c) && ! IGNORED.test(c));
const missingInTest = rawMissingInTest.filter((c) => ! residue.has(c));
const missingInDev = [...test].filter((c) => ! dev.has(c) && ! IGNORED.test(c));

// مدخلٌ في السجلّ لم يعد منحرفًا: صارت له هجرة، فيُحذف وإلّا تضخّم الإعفاء.
const stale = [...residue].filter((c) => ! rawMissingInTest.includes(c));

if (missingInTest.length === 0 && missingInDev.length === 0 && stale.length === 0) {
  console.log(`✓ لا انحراف · ${dev.size} عمودًا · ${residue.size} في سجلّ الرواسب`);
  process.exit(0);
}

if (stale.length > 0) {
  console.error('× مدخلٌ في سجلّ الرواسب لم يعد منحرفًا — احذفه:');
  for (const column of stale) console.error(`    ${column}`);
}

if (missingInTest.length > 0) {
  // الأخطر: عمودٌ تستعمله الشيفرة ولا هجرة تُنشئه. النشر النظيف ينهار.
  console.error('× أعمدة في التطوير ولا هجرة تُنشئها:');
  for (const column of missingInTest) console.error(`    ${column}`);
}

if (missingInDev.length > 0) {
  // أقلّ خطرًا: هجرةٌ لم تُشغَّل على التطوير بعد.
  console.error('× أعمدة تُنشئها الهجرات وليست في التطوير (شغّل migrate):');
  for (const column of missingInDev) console.error(`    ${column}`);
}

process.exit(1);
