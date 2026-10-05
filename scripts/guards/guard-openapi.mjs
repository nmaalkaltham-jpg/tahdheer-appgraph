/**
 * حارس عقد الواجهة البرمجيّة — بلا شبكةٍ ولا حاوية.
 *
 * ─── تقسيم المسؤوليّة ───
 *
 * مطابقةُ الوثيقة **للمسارات** تحتاج Laravel يعمل، فمحلُّها
 * `node scripts/openapi.mjs --check` ضمن البوّابة الكاملة.
 *
 * وهذا الحارس يفحص ما يمكن فحصه من الملفّات وحدها، ويعمل في أيّ بيئة:
 *
 *   ١ — الوثيقة موجودة وتُحلّل، ولها مسارات.
 *   ٢ — معرّفات العمليّات فريدة (التصادم يُسقط نصف العميل صامتًا).
 *   ٣ — كلّ `$ref` يُحلّ إلى مكوّنٍ موجود.
 *   ٤ — كلّ عمليّة إداريّة تغيّر حالة تحمل `x-permissions`.
 *   ٥ — الحزمة المولَّدة تطابق الوثيقة المحفوظة.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

const SPEC = join(ROOT, 'docs/api/openapi.json');

if (!existsSync(SPEC)) {
  fail('document', 'لا وثيقة docs/api/openapi.json — شغّل: node scripts/openapi.mjs');
  process.exit(report('حارس عقد الواجهة', results));
}

let spec;

try {
  spec = JSON.parse(readFileSync(SPEC, 'utf8'));
} catch (error) {
  fail('document', `الوثيقة ليست JSON صالحًا: ${error.message}`);
  process.exit(report('حارس عقد الواجهة', results));
}

const paths = Object.entries(spec.paths ?? {});

if (paths.length === 0) {
  fail('document', 'الوثيقة بلا مسارات');
  process.exit(report('حارس عقد الواجهة', results));
}

pass('document', `${paths.length} مسارًا · OpenAPI ${spec.openapi}`);

// ── ٢ — معرّفاتٌ فريدة ──────────────────────────────────────────────────
const ids = [];

for (const [, methods] of paths) {
  for (const [, operation] of Object.entries(methods)) {
    ids.push(operation.operationId);
  }
}

const duplicates = [...new Set(ids.filter((id, i) => ids.indexOf(id) !== i))];

if (duplicates.length > 0) {
  fail('operation-ids', `معرّفٌ مكرّر: ${duplicates.join('، ')}`);
} else {
  pass('operation-ids', `${ids.length} معرّفًا فريدًا`);
}

// ── ٣ — كلّ مرجعٍ يُحلّ ──────────────────────────────────────────────────
const broken = new Set();

const visit = (node) => {
  if (Array.isArray(node)) return node.forEach(visit);
  if (node === null || typeof node !== 'object') return;

  for (const [key, value] of Object.entries(node)) {
    if (key === '$ref' && typeof value === 'string') {
      const segments = value.replace(/^#\//, '').split('/');
      let cursor = spec;

      for (const segment of segments) {
        cursor = cursor?.[segment];
      }

      if (cursor === undefined) broken.add(value);

      continue;
    }

    visit(value);
  }
};

visit(spec.paths);
visit(spec.components);

if (broken.size > 0) {
  fail('refs', `مرجعٌ لا يُحلّ: ${[...broken].join('، ')}`);
} else {
  pass('refs', 'كلّ مرجعٍ يُحلّ إلى مكوّنٍ موجود');
}

// ── ٤ — كلّ عمليّة إداريّة تغيّر حالة تحمل صلاحية ───────────────────────
const MUTATING = new Set(['post', 'put', 'patch', 'delete']);
/*
 * الجلسة، وتفضيلات صاحبها: وثيقته هو لا كيانٌ في المتجر تحرسه صلاحية
 * (كما في `AdminRoutePermissionTest::SELF_SCOPED`) — والمصادقة تبقى مفروضة.
 */
const EXEMPT = /^\/api\/v1\/admin\/(auth\/|me\/preferences$)/;
const unguarded = [];

for (const [path, methods] of paths) {
  if (!path.startsWith('/api/v1/admin/') || EXEMPT.test(path)) continue;

  for (const [method, operation] of Object.entries(methods)) {
    if (!MUTATING.has(method)) continue;

    if (!Array.isArray(operation['x-permissions']) || operation['x-permissions'].length === 0) {
      unguarded.push(`${method.toUpperCase()} ${path}`);
    }
  }
}

if (unguarded.length > 0) {
  fail('permissions', `عمليّةٌ إداريّة بلا صلاحية في العقد: ${unguarded.join('، ')}`);
} else {
  pass('permissions', 'كلّ عمليّة إداريّة تغيّر حالةً تُعلن صلاحيتها');
}

// ── ٥ — الحزمة المولَّدة تطابق الوثيقة ──────────────────────────────────
try {
  execFileSync('node', [join(ROOT, 'scripts/generate-sdk.mjs'), '--check'], { stdio: 'pipe' });
  pass('sdk-drift', 'packages/api-sdk/src/generated.ts مطابق للوثيقة');
} catch (error) {
  const detail = `${error.stdout ?? ''}${error.stderr ?? ''}`.trim().split('\n')[0];
  fail('sdk-drift', detail || 'الحزمة المولَّدة تنحرف عن الوثيقة');
}

process.exit(report('حارس عقد الواجهة', results));
