// UNIT: API-SDK-INDEX
// FILE: index.ts
// STYLE: none
// PARENT: CAPABILITY-GATE, LIB-CONTRACT
// USES: CAPABILITIES, GENERATED, TYPES
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * `@tahdheer/api-sdk` — عقدُ الخادم مولَّدًا: المسارات والعمليّات والأنواع.
 *
 * ─── ما تغيّر ───
 *
 * كان هذا الملفّ يرمي عند الاستيراد ويعد بمولّدٍ لم يكن موجودًا، فكُتبت
 * النداءات بأيدٍ حرّة في كلّ تطبيق: مسارٌ نصّيّ هنا، وحقلٌ مخمَّن هناك، ولا
 * شيء يكشف الخطأ قبل ٤٠٤ أو ٤٢٢ في الإنتاج.
 *
 * الآن `generated.ts` مشتقٌّ من `docs/api/openapi.json` المشتقّة من الموجّه.
 * مسارٌ يُحذف من Laravel يختفي من النوع، فيفشل `tsc` قبل أن يفشل المستخدم.
 *
 * ─── النقل مِلكُ المستدعي ───
 *
 * الحزمة لا تصدّر عميل HTTP، بل العقد وحده: المسارات والعمليّات والأنواع.
 * كلّ مستدعٍ يملك نقله — `apps/admin/lib/contract.ts` يلفّ `fetch` بنفسه
 * لأنّ نداءات اللوحة تمرّ عبر وسيطٍ من الأصل نفسه.
 */

import { operations } from './generated';
import type { OperationBody, OperationId, OperationParams } from './generated';

export { operations };
export type { OperationBody, OperationId, OperationParams };
export * from './types';
export * from './capabilities';

/** يملأ وسائط المسار. الوسيط الناقص خطأٌ فوريّ لا مسارٌ فيه `{id}` حرفيًّا. */
export function buildPath<Id extends OperationId>(id: Id, params?: OperationParams<Id>): string {
  const operation = operations[id];
  let path: string = operation.path;

  for (const name of operation.params as readonly string[]) {
    const value = (params as Record<string, string | number> | undefined)?.[name];

    if (value === undefined || value === null || value === '') {
      throw new Error(`وسيط المسار «${name}» مفقود في العمليّة «${id}».`);
    }

    path = path.replace(`{${name}}`, encodeURIComponent(String(value)));
  }

  return path;
}
