/**
 * من «لا نتائج» إلى طلبٍ مملوء — ما فهمه البحث يُحمل إلى /request.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { initialSelection, requestHref } from '../request-intent.ts';

test('الرابط يحمل ما فُهم ونصَّ الزائر، ويُسقط الفارغ', () => {
  const href = requestHref({ stage: 'متوسط', grade: 'أول-متوسط', subject: 'العلوم', semester: null, kind: 'عرض بوربوينت', format: 'PPTX' }, '  بوربوينت علوم اول متوسط الجهاز الهضمي ');
  const url = new URL(href, 'https://store.test');
  assert.equal(url.pathname, '/request');
  assert.equal(url.searchParams.get('grade'), 'أول-متوسط');
  assert.equal(url.searchParams.get('subject'), 'العلوم');
  assert.equal(url.searchParams.get('kind'), 'عرض بوربوينت');
  assert.equal(url.searchParams.get('semester'), null);
  assert.equal(url.searchParams.get('format'), null, 'format is not a request field');
  assert.equal(url.searchParams.get('q'), 'بوربوينت علوم اول متوسط الجهاز الهضمي');
});

test('نصٌّ طويل يُقتطع — لا رابط بلا حدّ', () => {
  const q = new URL(requestHref({}, 'س'.repeat(500)), 'https://store.test').searchParams.get('q');
  assert.equal(q.length, 200);
});

test('الاختيار المبدئيّ يقبل ما في القوائم وحده — رابطٌ مزوَّر لا يحقن قيمة', () => {
  const options = { stages: [{ slug: 'متوسط' }], grades: [{ slug: 'أول-متوسط' }], subjects: [{ slug: 'العلوم' }], semesters: [], kinds: [] };
  assert.deepEqual(
    initialSelection({ stage: 'متوسط', grade: 'أول-متوسط', subject: 'غير-موجود', kind: ['x'] }, options),
    { stage: 'متوسط', grade: 'أول-متوسط' },
  );
});
