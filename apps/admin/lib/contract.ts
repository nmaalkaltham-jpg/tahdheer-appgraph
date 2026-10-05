// UNIT: LIB-CONTRACT
// FILE: contract.ts
// STYLE: none
// PARENT: AI-REQUEST-LOG, HOME-CONTENT-PANEL, MOVEMENT-FORM, PAGE-OPERATIONS-CASES, PAGE-OPERATIONS-CASES-ID, PAGE-PAYMENTS-WEBHOOKS +4
// USES: API-SDK-INDEX
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * جسرٌ بين اللوحة وعقد الخادم المولَّد.
 *
 * ─── لماذا لا تُستعمل دالّة النداء في الحزمة ───
 *
 * `@tahdheer/api-sdk` تنادي مباشرةً، واللوحة تنادي عبر وسيطٍ من نفس الأصل
 * ويلزمها ترويسة حماية من التزوير تُحفظ في الذاكرة. فالمستعمل هنا هو
 * **المسار والنوع** لا الناقل: المسار يأتي من العقد فلا يُكتب نصًّا، والجسم
 * يأخذ نوعه من قواعد التحقّق في الخادم فلا يُخمَّن حقلٌ ولا يُنسى.
 *
 * ونتيجةُ ذلك عمليّة: مسارٌ يُحذف من Laravel يختفي من `OperationId`، فيفشل
 * `tsc` عند البناء بدل أن يفشل المشغّل عند النقر.
 */

import { buildPath, operations } from '@tahdheer/api-sdk';
import type { OperationBody, OperationId, OperationParams } from '@tahdheer/api-sdk';

export type { OperationBody, OperationId };
export { operations };

/** بادئة وسيط اللوحة — `api.post` تضيفها، فتُقتطع من مسار العقد. */
const PROXY_BASE = '/api/v1';

/**
 * مسار العمليّة كما تنتظره `lib/admin-api`.
 *
 * عدم تطابق البادئة خطأٌ فوريّ: مسارٌ مزدوج البادئة يعطي ٤٠٤ في الإنتاج
 * وحده، وهو أصعب ما يُشخَّص لأنّ الشيفرة تبدو سليمة.
 */
export function proxyPath<Id extends OperationId>(id: Id, params?: OperationParams<Id>): string {
  const path = buildPath(id, params);

  if (!path.startsWith(`${PROXY_BASE}/`)) {
    throw new Error(`العمليّة «${id}» خارج بادئة الوسيط (${path}).`);
  }

  return path.slice(PROXY_BASE.length);
}
