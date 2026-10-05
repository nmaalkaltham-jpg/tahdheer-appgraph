/**
 * جالبات التجارة (lib/commerce-api.ts): رسالة الخادم على الفشل لا تُبتلع — كحدّ
 * الطلبات ٤٢٩ ومهلته — وحمولة إنشاء الطلب تحمل مفاتيح عقد الخادم بعينها
 * (CheckoutController::store) لا أكثر ولا أقلّ.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { register } from 'node:module';

/*
 * `commerce-api.ts` يستورد جيرانه بلا امتداد (كما يفعل Next)، وnode لا يحلّ ذلك
 * وحده — خطّاف حلٍّ صغير يجرّب «.ts» للمسارات النسبيّة، محصورٌ بهذا الاختبار.
 */
const HOOK = String.raw`
  export async function resolve(specifier, context, next) {
    if (/^\.{1,2}\//.test(specifier) && !/\.[a-z]+$/i.test(specifier)) {
      try { return await next(specifier + '.ts', context); } catch {}
    }
    return next(specifier, context);
  }
`;
register(`data:text/javascript,${encodeURIComponent(HOOK)}`);

process.env.NEXT_PUBLIC_API_URL = 'https://api.test';
const { getCart, createOrder, addCartItem } = await import('../commerce-api.ts');

const CONTRACT = ['name', 'phone', 'email', 'taxProfile', 'policyConsent', 'marketingOptIn'];

function stubFetch(status, body, calls) {
  globalThis.fetch = async (url, init) => {
    calls.push({ url, init });
    return { ok: status >= 200 && status < 300, status, json: async () => body };
  };
}

test('429: يُصنَّف http وتُحفظ رسالة الخادم ومهلته', async () => {
  const calls = [];
  stubFetch(429, { message: 'تجاوزت عدد الطلبات المسموح. أعد المحاولة بعد 17 ثانية.', code: 'RATE_LIMITED', retryAfter: 17 }, calls);
  const res = await getCart();
  assert.equal(res.status, 429);
  assert.equal(res.failure, 'http');
  assert.equal(res.data, null);
  assert.match(res.message, /17 ثانية/);
  assert.equal(calls[0].init.credentials, 'include');
});

test('422: أخطاء الحقول تُمرَّر حرفيًّا', async () => {
  const calls = [];
  stubFetch(422, { message: 'الاسم مطلوب', errors: { name: ['الاسم مطلوب'] } }, calls);
  const res = await createOrder({ name: '', phone: '512345678', email: 'a@b.co', taxProfile: null, policyConsent: true, marketingOptIn: false });
  assert.equal(res.status, 422);
  assert.deepEqual(res.errors, { name: ['الاسم مطلوب'] });
});

test('createOrder: الحمولة = مفاتيح عقد الخادم بلا عنوانٍ لسلّةٍ رقميّة', async () => {
  const calls = [];
  stubFetch(201, { orderNumber: 'TD-1', orderToken: 't', payment: { provider: 'fake', status: 'requires_payment' } }, calls);
  await createOrder({ name: 'زائر', phone: '512345678', email: 'a@b.co', taxProfile: null, policyConsent: true, marketingOptIn: false });
  const body = JSON.parse(calls[0].init.body);
  assert.deepEqual(Object.keys(body), CONTRACT);
  assert.equal(calls[0].init.method, 'POST');
  assert.equal(calls[0].url, 'https://api.test/api/v1/checkout/orders');
});

test('createOrder: عنوان الشحن يُرسَل حين تطلبه السلّة وحدها', async () => {
  const calls = [];
  stubFetch(201, {}, calls);
  await createOrder({ name: 'زائر', phone: '512345678', email: 'a@b.co', taxProfile: null, policyConsent: true, marketingOptIn: false, shippingAddress: { city: 'الرياض' } });
  const body = JSON.parse(calls[0].init.body);
  assert.deepEqual(Object.keys(body), [...CONTRACT, 'shippingAddress']);
  assert.deepEqual(body.shippingAddress, { city: 'الرياض' });
});

test('انقطاع النقل: status 0 وfailure network', async () => {
  globalThis.fetch = async () => { throw new TypeError('fetch failed'); };
  const res = await getCart();
  assert.deepEqual(res, { data: null, status: 0, failure: 'network' });
});

test('getCart (متصفّح): المتزامنون يتشاركون طلبًا واحدًا، ولا تخزين بعده', async () => {
  globalThis.window = globalThis;
  try {
    const calls = [];
    let release;
    globalThis.fetch = (url, init) => {
      calls.push({ url, init });
      return new Promise((ok) => {
        release = () => ok({ ok: true, status: 200, json: async () => ({ items: [], count: 0 }) });
      });
    };
    const a = getCart();
    const b = getCart();
    const c = getCart();
    assert.equal(calls.length, 1, 'ثلاث قراءاتٍ متزامنة = طلبٌ واحد');
    release();
    const [ra, rb, rc] = await Promise.all([a, b, c]);
    assert.equal(ra.status, 200);
    assert.equal(ra, rb);
    assert.equal(rb, rc);
    const d = getCart();
    assert.equal(calls.length, 2, 'بعد انتهاء الطلب: قراءةٌ جديدة لا جوابٌ مخزَّن');
    release();
    await d;
  } finally {
    delete globalThis.window;
  }
});

test('getCart (متصفّح): كتابةٌ أثناء قراءةٍ جارية تفكّ الدمج', async () => {
  globalThis.window = globalThis;
  try {
    const calls = [];
    const releases = [];
    globalThis.fetch = (url, init) => {
      calls.push({ url, method: init.method });
      return new Promise((ok) => releases.push(() => ok({ ok: true, status: 200, json: async () => ({ items: [], count: 0 }) })));
    };
    const before = getCart();
    const write = addCartItem(2, 34);
    const after = getCart();
    assert.deepEqual(calls.map((c) => c.method), ['GET', 'POST', 'GET'], 'القراءة بعد الكتابة طلبٌ جديد');
    for (const r of releases) r();
    await Promise.all([before, write, after]);
  } finally {
    delete globalThis.window;
  }
});
