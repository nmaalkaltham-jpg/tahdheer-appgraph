/**
 * يكتب وثيقة OpenAPI أو يتحقّق من مطابقتها.
 *
 * الأمر داخل الحاوية يطبع الوثيقة فحسب — لا يعرف جذر المستودع. هذا السكربت
 * يملك الملفّ: يكتبه، أو يقارن ويفشل عند الانحراف.
 *
 *   node scripts/openapi.mjs           يكتب docs/api/openapi.json
 *   node scripts/openapi.mjs --check   يفشل إن انحرفت الوثيقة عن المسارات
 */

import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const TARGET = join(ROOT, 'docs/api/openapi.json');
const CONTAINER = process.env.API_CONTAINER ?? 'tahdheer-local-api';

let generated;

try {
  generated = execFileSync('docker', ['exec', CONTAINER, 'php', 'artisan', 'openapi:generate'], {
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });
} catch (error) {
  console.error(`× تعذّر توليد الوثيقة من الحاوية ${CONTAINER}: ${error.message}`);
  process.exit(1);
}

// قد يسبق الإخراجَ سطرُ تحذيرٍ من الحاوية — تُقتطع الوثيقة من أوّل قوس.
const start = generated.indexOf('{');

if (start === -1) {
  console.error('× المخرج لا يحتوي وثيقة JSON.');
  process.exit(1);
}

let document;

try {
  document = JSON.parse(generated.slice(start));
} catch (error) {
  console.error(`× الوثيقة المولَّدة ليست JSON صالحًا: ${error.message}`);
  process.exit(1);
}

const serialised = `${JSON.stringify(document, null, 2)}\n`;
const count = Object.keys(document.paths).length;

if (process.argv.includes('--check')) {
  if (!existsSync(TARGET)) {
    console.error('× لا وثيقة محفوظة. شغّل: node scripts/openapi.mjs');
    process.exit(1);
  }

  if (readFileSync(TARGET, 'utf8') !== serialised) {
    console.error('× انحراف: docs/api/openapi.json لا تطابق المسارات الحاليّة.');
    console.error('  شغّل: node scripts/openapi.mjs');
    process.exit(1);
  }

  console.log(`✓ الوثيقة مطابقة · ${count} مسارًا`);
  process.exit(0);
}

mkdirSync(dirname(TARGET), { recursive: true });
writeFileSync(TARGET, serialised);
console.log(`✓ كُتبت ${count} مسارًا إلى docs/api/openapi.json`);
