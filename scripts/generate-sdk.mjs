/**
 * يولّد `@tahdheer/api-sdk` من وثيقة OpenAPI.
 *
 * ─── لماذا ───
 *
 * الحزمة كانت ترمي عند الاستيراد وتقول «أنا مولَّدة من OpenAPI» — ولم يكن
 * ثمّة مولّد ولا وثيقة. فكُتب كلّ نداءٍ بيدٍ حرّة: مسارٌ نصّيّ في مكوّن،
 * وحقلٌ مخمَّن في آخر، ولا شيء يكشف الخطأ إلّا ٤٠٤ في الإنتاج.
 *
 * ─── ما يُولَّد وما لا يُولَّد ───
 *
 * تُولَّد: خريطة العمليّات (المسار، الطريقة، الصلاحيات) وأنواعُ **أجسام
 * الطلبات** — وكلاهما مشتقٌّ من الخادم لا مخمَّن.
 *
 * لا تُولَّد أنواع الاستجابات: الوثيقة لا تصفها، واختراعُ نوعٍ لها أسوأ من
 * غيابه لأنّه يجعل المحرّر يؤكّد حقلًا قد لا يُرسَل. تُعاد `unknown` ويُجبَر
 * المستدعي على التحقّق.
 *
 *   node scripts/generate-sdk.mjs           يكتب packages/api-sdk/src/generated.ts
 *   node scripts/generate-sdk.mjs --check   يفشل عند الانحراف
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SPEC = join(ROOT, 'docs/api/openapi.json');
const TARGET = join(ROOT, 'packages/api-sdk/src/generated.ts');

if (!existsSync(SPEC)) {
  console.error('× لا وثيقة OpenAPI. شغّل: node scripts/openapi.mjs');
  process.exit(1);
}

const spec = JSON.parse(readFileSync(SPEC, 'utf8'));

/** يحوّل مخطّط JSON إلى نوع TypeScript. الأنواع المجهولة تصير `unknown`. */
function toType(schema, indent = '  ') {
  if (schema === undefined || schema === null) return 'unknown';
  if (Array.isArray(schema.enum)) return schema.enum.map((v) => JSON.stringify(v)).join(' | ');

  const base = (() => {
    switch (schema.type) {
      case 'integer':
      case 'number':
        return 'number';
      case 'boolean':
        return 'boolean';
      case 'array':
        return 'unknown[]';
      case 'object': {
        const props = Object.entries(schema.properties ?? {});
        if (props.length === 0) return 'Record<string, unknown>';
        const required = new Set(schema.required ?? []);
        const lines = props.map(([name, value]) => {
          const optional = required.has(name) ? '' : '?';
          return `${indent}  ${JSON.stringify(name)}${optional}: ${toType(value, `${indent}  `)};`;
        });
        return `{\n${lines.join('\n')}\n${indent}}`;
      }
      default:
        return 'string';
    }
  })();

  return schema.nullable === true ? `${base} | null` : base;
}

const operations = [];

for (const [path, methods] of Object.entries(spec.paths)) {
  for (const [method, operation] of Object.entries(methods)) {
    operations.push({
      id: operation.operationId,
      method: method.toUpperCase(),
      path,
      permissions: operation['x-permissions'] ?? [],
      body: operation.requestBody?.content?.['application/json']?.schema,
      bodyIncomplete: operation['x-request-body-incomplete'] === true,
      parameters: (operation.parameters ?? []).filter((p) => p.in === 'path'),
    });
  }
}

const duplicates = operations
  .map((o) => o.id)
  .filter((id, i, all) => all.indexOf(id) !== i);

if (duplicates.length > 0) {
  console.error(`× معرّفات عمليّات مكرّرة: ${[...new Set(duplicates)].join(', ')}`);
  process.exit(1);
}

operations.sort((a, b) => a.id.localeCompare(b.id));

const bodyTypes = operations
  .filter((o) => o.body !== undefined)
  .map((o) => {
    const name = `${o.id.charAt(0).toUpperCase()}${o.id.slice(1)}Body`;
    const note = o.bodyIncomplete
      ? '/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */\n'
      : '';
    return `${note}export interface ${name} ${toType(o.body)}\n`;
  });

const entries = operations.map((o) => {
  const name = `${o.id.charAt(0).toUpperCase()}${o.id.slice(1)}Body`;
  const params = o.parameters.map((p) => JSON.stringify(p.name)).join(', ');
  return [
    `  ${o.id}: {`,
    `    method: ${JSON.stringify(o.method)},`,
    `    path: ${JSON.stringify(o.path)},`,
    `    params: [${params}],`,
    `    permissions: [${o.permissions.map((p) => JSON.stringify(p)).join(', ')}],`,
    o.body !== undefined ? `    body: null as unknown as ${name},` : '    body: null as never,',
    '  },',
  ].join('\n');
});

const output = `/**
 * مولَّد — لا يُحرَّر.
 *
 * المصدر: docs/api/openapi.json (المولَّدة بدورها من موجّه Laravel).
 * التوليد: node scripts/generate-sdk.mjs
 *
 * أنواع الاستجابات غائبة عمدًا: الوثيقة لا تصفها، والنوع المخترع أسوأ من
 * غيابه لأنّه يؤكّد حقلًا قد لا يُرسَل.
 */

${bodyTypes.join('\n')}
export const operations = {
${entries.join('\n')}
} as const;

export type OperationId = keyof typeof operations;

export type OperationBody<Id extends OperationId> = (typeof operations)[Id]['body'];

export type OperationParams<Id extends OperationId> =
  (typeof operations)[Id]['params'][number] extends never
    ? Record<string, never>
    : Record<(typeof operations)[Id]['params'][number] & string, string | number>;
`;

/*
 * سجلّ القدرات يُولَّد أيضًا.
 *
 * ─── لماذا مولَّدٌ لا مقروءٌ عبر الشبكة ───
 *
 * العقد يقول: «إن كانت القدرة غير متاحة فلا تُرسل طلبًا». وقراءةُ التوفّر
 * عبر نداءٍ تُناقض ذلك — يبدأ الطلب قبل أن يُعرف أنّه لا يجوز. والسجلّ
 * ساكنٌ وقت البناء، فالبوّابة تُغلق قبل أن يُفتح اتّصال.
 *
 * ولا يُستعمل ٤٠٤ كاشفًا للتوفّر: الرمز يعني «غير موجود» لا «غير مبنيّ»،
 * والخلط بينهما يجعل عطلَ خادمٍ يبدو ميزةً مؤجَّلة.
 */
const capabilitiesPath = join(ROOT, 'docs/capabilities.json');
const capabilities = JSON.parse(readFileSync(capabilitiesPath, 'utf8')).capabilities;

const capabilityOutput = `/**
 * مولَّد — لا يُحرَّر.
 *
 * المصدر: docs/capabilities.json
 * التوليد: node scripts/generate-sdk.mjs
 *
 * القدرة غير المتاحة تُغلق بوّابتُها قبل أيّ نداء: لا طلب يُرسَل، ولا
 * يُستعمل ٤٠٤ كاشفًا للتوفّر.
 */

export type CapabilityStatus = 'COMPLETE' | 'ADMIN_UI' | 'API_ONLY' | 'SYSTEM_ONLY' | 'DEFERRED';

export const capabilities = {
${capabilities.map((c) => `  ${JSON.stringify(c.key)}: { status: ${JSON.stringify(c.classification)} as CapabilityStatus, title: ${JSON.stringify(c.title)} },`).join('\n')}
} as const;

export type CapabilityKey = keyof typeof capabilities;

/** متاحةٌ = مبنيّة فعلًا. \`DEFERRED\` وحدها غير متاحة. */
export function isCapabilityAvailable(key: CapabilityKey): boolean {
  return capabilities[key].status !== 'DEFERRED';
}

export function capabilityTitle(key: CapabilityKey): string {
  return capabilities[key].title;
}
`;

const capabilityTarget = join(ROOT, 'packages/api-sdk/src/capabilities.ts');

if (process.argv.includes('--check')) {
  if (!existsSync(capabilityTarget) || readFileSync(capabilityTarget, 'utf8') !== capabilityOutput) {
    console.error('× انحراف: packages/api-sdk/src/capabilities.ts لا يطابق سجلّ القدرات.');
    process.exit(1);
  }
}

if (!process.argv.includes('--check')) {
  writeFileSync(capabilityTarget, capabilityOutput);
}

if (process.argv.includes('--check')) {
  if (!existsSync(TARGET) || readFileSync(TARGET, 'utf8') !== output) {
    console.error('× انحراف: packages/api-sdk/src/generated.ts لا يطابق الوثيقة.');
    console.error('  شغّل: node scripts/generate-sdk.mjs');
    process.exit(1);
  }

  console.log(`✓ الحزمة مطابقة · ${operations.length} عمليّة`);
  process.exit(0);
}

writeFileSync(TARGET, output);
console.log(`✓ كُتبت ${operations.length} عمليّة إلى packages/api-sdk/src/generated.ts`);
