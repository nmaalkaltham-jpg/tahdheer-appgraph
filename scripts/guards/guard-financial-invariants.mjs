/**
 * حارس الثوابت الماليّة (§٧٠): لا مال يخرج مرّتين، ولا أكثر ممّا دخل.
 *
 * ─── لماذا يُفحص وجود القيود لا سلوك الشيفرة ───
 *
 * الشيفرة تتغيّر بمراجعةٍ واحدة. والقيد في المحرّك لا يُسقطه إلّا هجرةٌ
 * صريحة يراها الجميع. فالحارس يسأل: هل ما زالت الهجرات تُنشئ ما يمنع
 * الكارثة؟
 *
 * ─── ما يحرسه ───
 *
 * ١ — سقف الاسترداد: مجموع المستردّ ≤ المحصَّل، مفروضًا على الطلب.
 * ٢ — مبلغ استردادٍ موجب: الصفر والسالب خطأ إدخالٍ لا حركةٌ ماليّة.
 * ٣ — فرادة مفتاح الدفع: لا تحصيل مزدوج لنفس رسوم المزوّد.
 * ٤ — فرادة الاستحقاق ببند الطلب: لا استحقاق مزدوج من إعادة محاولة.
 * ٥ — الاسترداد يُطبَّق تحت قفل صفّ: «اقرأ ثمّ قرّر ثمّ اكتب» بلا قفل هو
 *     بعينه ما يسمح لطلبين بأن يمرّا معًا ثمّ يكتبا معًا.
 */

import { join } from 'node:path';
import { ROOT, expandRoots, walk, read, rel, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

const migrations = expandRoots(['backend/laravel/database/migrations'])
  .flatMap((dir) => walk(dir, ['.php']))
  .map((file) => read(file))
  .join('\n');

/** كلّ ثابتٍ ونصُّه الذي يجب أن يبقى قائمًا في الهجرات. */
const INVARIANTS = [
  ['refund-ceiling', /orders_refund_within_captured/, 'سقف الاسترداد على الطلب'],
  ['refund-positive', /refunds_amount_positive/, 'مبلغ استرداد موجب'],
  ['payment-unique', /payments.*idempotency_key.*unique|unique\(\s*\[?'idempotency_key'/s, 'فرادة مفتاح الدفع'],
  ['entitlement-unique', /order_item_id'?\s*\)?->unique\(\)|unique\(\s*\[?'order_item_id'/s, 'فرادة الاستحقاق ببند الطلب'],
  ['capture-positive', /payments_amount_positive/, 'مبلغ تحصيل موجب'],
  /*
   * مبلغٌ بلا عملة لا يُقارَن بشيء: ١٠٠٠٠ ريالٍ و١٠٠٠٠ دولار رقمان متطابقان
   * ومبلغان مختلفان. القيد يفرض اقترانهما فتصير التسوية ذات معنى.
   */
  ['capture-currency', /payments_amount_has_currency/, 'اقتران المبلغ بالعملة'],
];

const missing = INVARIANTS.filter(([, pattern]) => ! pattern.test(migrations));

if (missing.length > 0) {
  fail('constraints', `ثابتٌ ماليّ لا تُنشئه أيّ هجرة: ${missing.map(([, , label]) => label).join('، ')}`);
} else {
  pass('constraints', `${INVARIANTS.length} ثوابت ماليّة مفروضة في المحرّك`);
}

// ── الاسترداد تحت قفل ──────────────────────────────────────────────────
const service = join(ROOT, 'backend/laravel/app/Domain/Orders/RefundService.php');
const source = read(service);

const locked = /lockForUpdate\(\)/.test(source);
const guards = /RefundExceedsCaptured/.test(source);

if (! locked || ! guards) {
  fail('refund-path', `خدمة الاسترداد ${locked ? '' : 'بلا قفل صفّ '}${guards ? '' : 'بلا حارس سقف'}`);
} else {
  pass('refund-path', `${rel(service)} · قفلُ صفٍّ وحارسُ سقفٍ في النطاق`);
}

/*
 * ولا يُحسب السقف في وحدة التحكّم وحدها: كلّ مستدعٍ آخر يتجاوزها.
 * وجوده هناك رسالةٌ مبكّرة، وغيابه من النطاق ثغرة.
 */
const controllers = expandRoots(['backend/laravel/app/Http/Controllers/Api/V1/Admin'])
  .flatMap((dir) => walk(dir, ['.php']))
  .filter((file) => /refund/i.test(read(file)));

const unguarded = controllers.filter((file) => {
  const src = read(file);

  return /->refund\(/.test(src) && ! /RefundExceedsCaptured/.test(src);
});

if (unguarded.length > 0) {
  fail('controller-guard', `وحدة تحكّمٍ تستدعي الاسترداد ولا تعالج تجاوز السقف: ${unguarded.map(rel).join(' · ')}`);
} else {
  pass('controller-guard', `${controllers.length} وحدة تحكّمٍ تمسّ الاسترداد · كلّها تقرأ الرفض`);
}

// ── الاستحقاق لا يُمنح بلا إذنٍ تجاريّ (§٧١) ────────────────────────────

/*
 * المال المستردّ زيادةً يُطالَب به. والملفّ الرقميّ حين يُنزَّل لا يعود —
 * فحارسُ الاستحقاق أشدّ لزومًا من حارس الاسترداد، لا أقلّ.
 *
 * ويُفحص وجودُه في **النطاق**: وضعُه في المستدعي يتركه لكلّ مستدعٍ آخر.
 */
const entitlements = `${ROOT}/backend/laravel/app/Domain/Entitlements/EntitlementService.php`;
const entitlementSrc = read(entitlements);

const authorized = /assertAuthorized\(/.test(entitlementSrc)
  && /EntitlementNotAuthorized/.test(entitlementSrc)
  && /AUTHORIZED_STATUSES/.test(entitlementSrc);

if (! authorized) {
  fail('entitlement-authorization', 'خدمة الاستحقاق تمنح بلا فحص إذنٍ تجاريّ');
} else {
  pass('entitlement-authorization', `${rel(entitlements)} · لا منح بلا إذنٍ تجاريّ`);
}

process.exit(report('حارس الثوابت الماليّة', results));
