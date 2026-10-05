/**
 * تجريد أصل الوسائط — الحارس ضدّ عودة `ERR_BLOCKED_BY_RESPONSE.NotSameSite`.
 *
 * العيب المقيس حيًّا: `‎/shop‎` و`‎/products/a‎` كانا يبثّان
 * `src="http://127.0.0.1:58080/api/v1/media/products/27-….png"`، والمتصفّح
 * يحجبها لأنّ المصدر يردّ `Cross-Origin-Resource-Policy: same-site` وهو لا
 * يُطابَق عبر مضيفٍ رقميّ — فتبقى `naturalWidth === 0` بلا خطأ في سجلّ
 * الخادم. الاختبار يثبّت الحدّ: ما تحت `‎/api/v1/media/‎` يُجرَّد، وما
 * سواه يُترك كما ورد.
 *
 * والشقّ الثاني ألزم من الأوّل: تحويلُ رابطٍ لا وسيط له عندنا يستبدل صورةً
 * تعمل بـ404. ولهذا `‎/storage/*‎` — وهي تعمل اليوم بلا CORP — تمرّ سليمة.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mediaImage, mediaSrc } from '../media-url.ts';

test('يجرّد أصل الـAPI عن مسار الوسائط', () => {
  assert.equal(
    mediaSrc('http://127.0.0.1:58080/api/v1/media/products/27-7a1f0c5ad3ce.png'),
    '/api/v1/media/products/27-7a1f0c5ad3ce.png',
  );
});

test('يجرّد أيّ مضيف — بما فيه المضيف الداخليّ المخبوز في التصيير الخادميّ', () => {
  assert.equal(mediaSrc('https://api-web:8080/api/v1/media/x.png'), '/api/v1/media/x.png');
  assert.equal(mediaSrc('https://api.tahdheer.com/api/v1/media/x.png'), '/api/v1/media/x.png');
});

test('يحفظ سلسلة الاستعلام — قصٌّ لها يُسقط وسائط مُعلَّمة بنسخة', () => {
  assert.equal(mediaSrc('https://h/api/v1/media/x.png?v=3'), '/api/v1/media/x.png?v=3');
});

test('يترك ما لا وسيط له: /storage تعمل بلا CORP فتحويلها يكسرها', () => {
  const storage = 'http://127.0.0.1:58080/storage/design-preview/DESIGN-PREVIEW-02.svg';
  assert.equal(mediaSrc(storage), storage);
});

test('يترك المسار النسبيّ والرابط غير الصالح كما وردا', () => {
  assert.equal(mediaSrc('/api/v1/media/x.png'), '/api/v1/media/x.png');
  assert.equal(mediaSrc('not a url'), 'not a url');
});

test('mediaImage يُبقي بقيّة الحقول ولا يخلق صورةً من العدم', () => {
  const dto = { url: 'https://h/api/v1/media/a.png', alt: 'أ', w: 10, h: 20 };
  assert.deepEqual(mediaImage(dto), { url: '/api/v1/media/a.png', alt: 'أ', w: 10, h: 20 });
  assert.equal(mediaImage(undefined), undefined);
});

test('mediaImage يُعيد المرجع نفسه حين لا تغيير — لا إعادة تصيير بلا سبب', () => {
  const dto = { url: '/api/v1/media/a.png', alt: 'أ', w: 1, h: 1 };
  assert.equal(mediaImage(dto), dto);
});
