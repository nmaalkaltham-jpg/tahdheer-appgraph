/**
 * مفاتيح الخمول في اللوحة — الطرف غير المُختبَر من عقدٍ ذي طرفين.
 *
 * ─── لماذا هذا الملفّ ───
 *
 * `lib/idempotency.ts` يقول عن نفسه إنّ ترتيبه للمفاتيح «تمامًا كما يفعل
 * `RequestFingerprint` في الخادم»، وإنّ اختلاف الجهتين «كان سيُنتج ٤٠٩ على
 * طلبٍ لم يتغيّر منه شيء». والخادم مُختبَر (`LedgerRecoveryTest`)، وهذا
 * الطرف لم يكن. فكان نصفُ العقد مكتوبًا في تعليقٍ لا يفحصه شيء.
 *
 * ─── وما يُختبَر هنا بالضبط ───
 *
 * ليست مطابقةَ بايتاتٍ للبصمة: الجهتان تحسبان بصمتين مختلفتين بخوارزميّتين
 * مختلفتين (‏FNV-1a هنا · SHA-256 هناك) ولا تلتقيان أبدًا. المشترك بينهما
 * **قاعدةٌ واحدة**: ترتيب مفاتيح الخريطة ليس معنًى، وترتيب عناصر القائمة
 * معنًى. فإن خالفت الجهتان في هذه القاعدة اختلّ الخمول من طرفه الأضعف.
 *
 * ولذلك الحالات أدناه هي حالات `RequestFingerprint::normalise` نفسها،
 * مقروءةً من الطرف الآخر.
 */

import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { idempotencyKey, tabClientId } from '../idempotency.ts';

/**
 * ‏`sessionStorage` غير موجود في Node.
 *
 * وغيابُه ليس تفصيلًا في الاختبار: `tabClientId` يبتلع الغياب ويُعيد هويّةً
 * جديدة **لكلّ نداء**، فكلّ مفتاحٍ يصير فريدًا ويمرّ كلّ تأكيدٍ للاختلاف
 * ويسقط كلّ تأكيدٍ للتساوي — أي اختبارٌ يقيس العدم. فيُنصَب مخزنٌ حقيقيّ.
 */
function installStorage() {
  const map = new Map();
  globalThis.sessionStorage = {
    getItem: (k) => (map.has(k) ? map.get(k) : null),
    setItem: (k, v) => void map.set(k, String(v)),
  };
}

beforeEach(installStorage);

/* ───── القاعدة المشتركة مع الخادم ───── */

test('ترتيب مفاتيح الخريطة ليس معنًى — الجسم نفسه بترتيبٍ آخر مفتاحٌ واحد', () => {
  const a = idempotencyKey('orders', { status: 'PAID', note: 'تمّ', id: 7 });
  const b = idempotencyKey('orders', { id: 7, note: 'تمّ', status: 'PAID' });

  assert.equal(a, b);
});

test('الترتيب العميق أيضًا — خريطةٌ داخل خريطة', () => {
  const a = idempotencyKey('orders', { meta: { b: 2, a: 1 }, id: 1 });
  const b = idempotencyKey('orders', { id: 1, meta: { a: 1, b: 2 } });

  assert.equal(a, b);
});

test('ترتيب عناصر القائمة معنًى — فلا يُرتَّب', () => {
  const a = idempotencyKey('orders', { items: [1, 2] });
  const b = idempotencyKey('orders', { items: [2, 1] });

  assert.notEqual(a, b);
});

test('وخريطةٌ داخل قائمة تُرتَّب دون أن يتغيّر موضعها', () => {
  const a = idempotencyKey('orders', { items: [{ qty: 2, sku: 'A' }] });
  const b = idempotencyKey('orders', { items: [{ sku: 'A', qty: 2 }] });

  assert.equal(a, b);
});

/* ───── السببان اللذان وُلدت منهما البدائيّة ───── */

test('الطلب نفسه مرّتين مفتاحٌ واحد — وإلّا بطل الخمول عند إعادة الإرسال', () => {
  const payload = { status: 'REFUNDED', amount: 1500 };

  assert.equal(idempotencyKey('orders', payload), idempotencyKey('orders', payload));
});

test('الجسم المُصحَّح مفتاحٌ جديد — وإلّا رُدّ المشغّل بتعارضٍ بعد تصحيحه', () => {
  const a = idempotencyKey('orders', { amount: 1500 });
  const b = idempotencyKey('orders', { amount: 1600 });

  assert.notEqual(a, b);
});

test('النطاق جزءٌ من المفتاح — كتابتان متطابقتان في سطحين ليستا واحدة', () => {
  const a = idempotencyKey('orders', { id: 1 });
  const b = idempotencyKey('redirects', { id: 1 });

  assert.notEqual(a, b);
});

/* ───── هويّة التبويب ───── */

test('هويّة التبويب تُولَّد مرّةً وتُقرأ بعدها من المخزن', () => {
  const first = tabClientId('orders');

  assert.equal(first, tabClientId('orders'));
  assert.equal(sessionStorage.getItem('tahdheer:orders:client-id'), first);
  assert.match(first, /^tab-[0-9a-f-]{36}$/);
});

test('تبويبان محرّران مستقلّان — المخزن الجديد هويّةٌ جديدة', () => {
  const first = idempotencyKey('orders', { id: 1 });
  installStorage(); // تبويبٌ آخر: مخزنُ جلسةٍ آخر
  const second = idempotencyKey('orders', { id: 1 });

  assert.notEqual(first, second);
});

test('مخزنٌ محجوب لا يرمي — يُضعف الخمول ولا يكسر الكتابة', () => {
  globalThis.sessionStorage = {
    getItem: () => {
      throw new Error('blocked');
    },
    setItem: () => {
      throw new Error('blocked');
    },
  };

  assert.match(idempotencyKey('orders', { id: 1 }), /^tab-[0-9a-f-]{36}:[0-9a-f]{8}$/);
});

/* ───── شكل المفتاح ───── */
