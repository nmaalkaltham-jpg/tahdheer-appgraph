/**
 * عقد الإلغاء في `admin-api`.
 *
 * ─── العطل الذي وُلد منه ───
 *
 * لم يكن في شيفرة اللوحة `AbortController` واحد. فمرشِّحٌ أو بحثٌ يُبدَّل
 * مرّتين بسرعة يترك طلبَين طائرَين، ولا ضمان أنّ الأوّل يصل أوّلًا: نتيجةٌ
 * تخصّ استعلامًا تخلّى عنه المستعمل كانت تُكتب فوق نيّته الأحدث.
 *
 * ─── ولماذا تُعاد الرمية ولا تُبتلع ───
 *
 * `request` لا ترمي أبدًا بالتصميم — تُعيد `ApiResult` — ومئةُ موضعٍ تعتمد
 * ذلك. لكنّ الإجهاض ليس عطلًا: لو عاد `NETWORK_ERROR` لأظهرت كلّ كتابةِ
 * حرفٍ في مرشِّح رسالةَ «تعذّر الاتصال بالخادم» عن طلبٍ ألغيناه نحن.
 * فالإجهاض وحده يُعاد رميةً ليعرفه `react-query` إلغاءً ويُسقطه صامتًا،
 * وكلّ عطلٍ آخر يبقى `ApiResult` كما كان.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { api } from '../admin-api.ts';

/** يستبدل `fetch` ويُعيد ما يُرجعه إلى ما كان. */
async function withFetch(stub, run) {
  const original = globalThis.fetch;
  globalThis.fetch = stub;
  try {
    return await run();
  } finally {
    globalThis.fetch = original;
  }
}

test('الإجهاض يُعاد رميةً ولا يُبتلع حالةَ عطلٍ شبكيّ', async () => {
  const controller = new AbortController();

  await withFetch(
    (_url, init) => {
      /* ما يفعله المتصفّح فعلًا: يرمي حين يُجهَض الإشارة. */
      controller.abort();
      const error = new Error('aborted');
      error.name = 'AbortError';
      void init;
      return Promise.reject(error);
    },
    async () => {
      await assert.rejects(
        () => api.get('/admin/anything', controller.signal),
        (error) => error.name === 'AbortError',
        'الإجهاض ابتُلع بدل أن يُعاد — فيُعرض خطأ اتّصالٍ عن إلغاءٍ طلبناه نحن',
      );
    },
  );
});

test('عطلٌ شبكيّ حقيقيّ يبقى نتيجةً لا رمية', async () => {
  await withFetch(
    () => Promise.reject(new TypeError('Failed to fetch')),
    async () => {
      const result = await api.get('/admin/anything');

      assert.equal(result.ok, false);
      assert.equal(result.code, 'NETWORK_ERROR');
      assert.equal(result.status, 0);
    },
  );
});

test('إشارةٌ لم تُجهَض: العطل نتيجةٌ لا رمية', async () => {
  const controller = new AbortController();

  await withFetch(
    () => Promise.reject(new TypeError('Failed to fetch')),
    async () => {
      const result = await api.get('/admin/anything', controller.signal);

      assert.equal(result.ok, false);
      assert.equal(result.code, 'NETWORK_ERROR');
    },
  );
});

test('الإشارة تصل إلى `fetch` فعلًا', async () => {
  const controller = new AbortController();
  let seen = null;

  await withFetch(
    (_url, init) => {
      seen = init.signal;
      return Promise.resolve(
        new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } }),
      );
    },
    async () => {
      await api.get('/admin/anything', controller.signal);
    },
  );

  assert.equal(seen, controller.signal, 'الإشارة لم تُمرَّر — فالإلغاء زينةٌ لا أثر لها');
});
