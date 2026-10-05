/**
 * تصنيف حالة الطلب — الحارس ضدّ تتويج طلبٍ مسترد بـ«ملفاتك جاهزة الآن».
 *
 * العيب الذي أوجب هذه الاختبارات ظهر حيًّا: الفحص كان
 * `order.status === 'paid'` والخادم يبثّ `PAID`، فلا يطابق أبدًا وينهار
 * الشرط كلّه إلى `Boolean(order.paidAt)` — و`paid_at` يبقى مكتوبًا بعد
 * الاسترجاع. أي أنّ خطأ حالة أحرفٍ واحدًا كان يفتح «تم الدفع» على كلّ طلبٍ
 * دُفع ثمّ استُرجع أو أُلغي.
 *
 * التعداد المُختبَر هنا هو تعداد `orders.status` كاملًا كما تبنيه الهجرة
 * `2026_08_31_000005_create_commerce_tables.php` — تسعُ حالات، كلّها مغطّاة:
 * حالةٌ تُضاف إلى الهجرة بلا سطرٍ هنا تسقط في «المجهولة» ويكشفها الاختبار
 * الأخير.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  closedCopyFor,
  isAwaitingStatus,
  isPaidStatus,
  lockedNoteFor,
} from '../order-status.ts';

/** تعداد `orders.status` كاملًا — المصدر: الهجرة، لا الذاكرة. */
const ALL = [
  'PENDING',
  'AWAITING_PAYMENT',
  'PAID',
  'PROCESSING',
  'COMPLETED',
  'FAILED',
  'CANCELLED',
  'REFUNDED',
  'PARTIALLY_REFUNDED',
];

test('المدفوع هو ما يعدّه الخادم مدفوعًا — لا أكثر', () => {
  assert.deepEqual(ALL.filter(isPaidStatus), ['PAID', 'PROCESSING', 'COMPLETED']);
});

test('المنتظر حالتان، ولا تتقاطعان مع المدفوع', () => {
  assert.deepEqual(ALL.filter(isAwaitingStatus), ['PENDING', 'AWAITING_PAYMENT']);
  assert.equal(
    ALL.some((status) => isPaidStatus(status) && isAwaitingStatus(status)),
    false,
    'حالةٌ عُدَّت مدفوعةً ومنتظرةً معًا',
  );
});

test('المسترد ليس مدفوعًا — وهو العيب الذي أوجب الملفّ', () => {
  assert.equal(isPaidStatus('REFUNDED'), false);
  assert.equal(isPaidStatus('PARTIALLY_REFUNDED'), false);
  assert.equal(isPaidStatus('CANCELLED'), false);
  assert.equal(isPaidStatus('FAILED'), false);
});

test('حالة الأحرف لا تغيّر الحكم — وهي علّة الانهيار الأصلية', () => {
  for (const value of ['paid', 'Paid', '  PAID  ', 'pAiD']) {
    assert.equal(isPaidStatus(value), true, `فشل على «${value}»`);
  }
  assert.equal(isAwaitingStatus('awaiting_payment'), true);
});

test('الغائب والفارغ لا يُعَدّان دفعًا', () => {
  for (const value of [null, undefined, '', '   ', 'UNKNOWN_FUTURE_STATE']) {
    assert.equal(isPaidStatus(value), false, `عُدَّ «${String(value)}» مدفوعًا`);
    assert.equal(isAwaitingStatus(value), false);
    assert.equal(closedCopyFor(value), null);
  }
});

test('الحالات المُغلَقة وحدها تحمل نسخةً، والمدفوع والمنتظر لا يحملانها', () => {
  assert.deepEqual(
    ALL.filter((status) => closedCopyFor(status) !== null),
    ['FAILED', 'CANCELLED', 'REFUNDED', 'PARTIALLY_REFUNDED'],
  );
  for (const status of ['PAID', 'PROCESSING', 'COMPLETED', 'PENDING', 'AWAITING_PAYMENT']) {
    assert.equal(closedCopyFor(status), null, `حالة ${status} حملت نسخة إغلاق`);
  }
});

test('«لم يُخصم أي مبلغ» لا تُقال إلّا حيث تصحّ', () => {
  /* أُلغي أو فشل ⇒ لم يُخصم. استُرجع ⇒ خُصم ثمّ أُعيد، فلا تصحّ الجملة. */
  assert.match(closedCopyFor('CANCELLED').note, /لم يُخصم/);
  assert.match(closedCopyFor('FAILED').note, /لم يُخصم/);
  assert.doesNotMatch(closedCopyFor('REFUNDED').note, /لم يُخصم/);
  assert.doesNotMatch(closedCopyFor('PARTIALLY_REFUNDED').note, /لم يُخصم/);
});

test('نسخة الاسترجاع تصف سحب الصلاحية ولا تدّعي وجهة المبلغ', () => {
  /*
   * سحبُ الاستحقاق أثرٌ يفرضه المتجر فعلًا. أمّا «أُعيد المبلغ إلى وسيلة
   * الدفع نفسها» فادّعاءٌ عن البوّابة لا تعرفه هذه الصفحة — ولا يُقال.
   */
  assert.match(closedCopyFor('REFUNDED').note, /سُحبت صلاحية/);
  assert.doesNotMatch(closedCopyFor('REFUNDED').note, /وسيلة الدفع/);
});

test('وعدُ الفتح لا يُقال إلّا لطلبٍ ما زال الفتح ممكنًا فيه', () => {
  assert.equal(lockedNoteFor('PENDING'), 'يُفتح فور تأكيد الدفع');
  assert.equal(lockedNoteFor('AWAITING_PAYMENT'), 'يُفتح فور تأكيد الدفع');

  for (const status of ['REFUNDED', 'PARTIALLY_REFUNDED', 'CANCELLED', 'FAILED', 'PAID']) {
    assert.doesNotMatch(
      lockedNoteFor(status),
      /يُفتح فور تأكيد الدفع/,
      `وُعد بالفتح في حالة ${status}`,
    );
  }
});

test('صفٌّ مقفل في طلبٍ مدفوع لا يُتَّهم بأنّه غير مدفوع', () => {
  const note = lockedNoteFor('PAID');
  assert.doesNotMatch(note, /الدفع/, 'نسب القفل إلى الدفع في طلبٍ مدفوع');
  assert.ok(note.length > 0);
});

test('كلّ حالةٍ في التعداد تُنتج نصّ قفلٍ غير فارغ', () => {
  for (const status of ALL) {
    const note = lockedNoteFor(status);
    assert.equal(typeof note, 'string');
    assert.ok(note.trim().length > 0, `حالة ${status} بلا نصّ قفل`);
  }
});
