/**
 * قاعدة صيغ الملفّ — الحارس ضدّ ادّعاءٍ بصيغةٍ واحدة لمنتجٍ يحمل عدّة.
 *
 * العيب الذي أوجب هذه الاختبارات ظهر حيًّا: مُبدِّلٌ مختارٌ فيه PowerPoint،
 * وتحته سطرٌ يقول «ملف DOCX قابل للتعديل بالكامل».
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatsOf, singleFormat } from '../product-format.ts';

test('منتجٌ بلا صيغٍ يقع على عموده القديم', () => {
  assert.deepEqual(formatsOf({ format: 'DOCX' }), ['DOCX']);
  assert.equal(singleFormat({ format: 'DOCX' }), 'DOCX');
});

test('الصيغ تغلب العمود القديم', () => {
  const product = { format: 'DOCX', variants: [{ format: 'PPTX' }, { format: 'PDF' }] };
  assert.deepEqual(formatsOf(product), ['PPTX', 'PDF']);
});

test('صيغةٌ واحدة تبقى ادّعاءً مشروعًا', () => {
  assert.equal(singleFormat({ variants: [{ format: 'DOCX' }] }), 'DOCX');
});

test('أكثر من صيغة تُسكِت كلّ ادّعاءٍ بواحدة', () => {
  const product = { format: 'DOCX', variants: [{ format: 'DOCX' }, { format: 'PPTX' }] };
  assert.equal(singleFormat(product), null, 'ادّعى صيغةً واحدة لمنتجٍ بصيغتين');
});

test('التكرار لا يصنع تعدّدًا كاذبًا', () => {
  /* صيغتان بامتدادٍ واحد (نسختان بترخيصين مثلًا) تبقيان صيغةً واحدة. */
  const product = { variants: [{ format: 'PDF' }, { format: 'PDF' }] };
  assert.deepEqual(formatsOf(product), ['PDF']);
  assert.equal(singleFormat(product), 'PDF');
});

test('الامتداد المجهول يُسقَط ولا يُخترع', () => {
  assert.deepEqual(formatsOf({ variants: [{ format: null }, { format: '  ' }] }), []);
  assert.equal(singleFormat({ variants: [{ format: null }] }), null);
  assert.equal(singleFormat({}), null);
  assert.equal(singleFormat({ format: '' }), null);
});

test('صيغةٌ بلا امتداد بين أخواتها تُسكِت الادّعاء ولا تُحسَب امتدادًا', () => {
  /*
   * هذا الاختبار كان يثبّت العكس — ويثبّت معه عطبًا: صيغتان إحداهما بلا
   * امتداد تعطيان قائمةً من عنصر، فتعود الصفحة إلى «ملف DOCX» وتحته
   * مُبدِّلٌ بخيارين. الحاكم عدد الصيغ لا عدد الامتدادات المعروفة.
   */
  const product = { variants: [{ format: 'DOCX' }, { format: null }] };
  assert.deepEqual(formatsOf(product), ['DOCX'], 'الامتداد المجهول لا يُخترع له اسم');
  assert.equal(singleFormat(product), null, 'ادّعى صيغةً واحدة لمنتجٍ فيه خياران');
});

test('صيغتان مختلفتان تُسكتان الادّعاء', () => {
  assert.equal(singleFormat({ variants: [{ format: 'DOCX' }, { format: 'PPTX' }] }), null);
});

test('خياران بالامتداد نفسه يبقى ادّعاؤهما صادقًا', () => {
  /* نسختان بترخيصين: كلاهما PDF فعلًا، فالادّعاء عن الصيغة صحيح. */
  assert.equal(singleFormat({ variants: [{ format: 'PDF' }, { format: 'PDF' }] }), 'PDF');
});
