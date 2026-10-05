/**
 * اختيارُ الأوجه المتعدّد — القراءة من الرابط، والتبديل، والتسلسل للمتجر وللخادم.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  MAX_VALUES_PER_DIM,
  appendSelection,
  appendSelectionForApi,
  isSelected,
  readSelection,
  selectedCount,
  toggleValue,
} from '../facet-selection.ts';

test('يقرأ النصّ المفرد والمفاتيح المكرّرة ويُسقط الفارغ والمكرّر وما لا يُعرف', () => {
  const sel = readSelection({ subject: ['العلوم', 'الرياضيات', 'العلوم', ' '], grade: 'رابع-ابتدائي', q: 'x', nature: 'digital' });
  assert.deepEqual(sel, { subject: ['العلوم', 'الرياضيات'], grade: ['رابع-ابتدائي'] });
  assert.equal(selectedCount(sel), 3);
});

test('رابطٌ مصنوع لا يتجاوز سقف الخادم', () => {
  const many = Array.from({ length: 15 }, (_, i) => `s${i}`);
  assert.equal(readSelection({ subject: many }).subject.length, MAX_VALUES_PER_DIM);
});

test('التبديل يُضيف ويُزيل، والبُعد الفارغ يسقط', () => {
  let sel = toggleValue({}, 'subject', 'العلوم');
  sel = toggleValue(sel, 'subject', 'الرياضيات');
  assert.deepEqual(sel.subject, ['العلوم', 'الرياضيات']);
  assert.equal(isSelected(sel, 'subject', 'الرياضيات'), true);
  sel = toggleValue(toggleValue(sel, 'subject', 'العلوم'), 'subject', 'الرياضيات');
  assert.equal('subject' in sel, false);
});

test('رابط المتجر مفاتيح مكرّرة، وطلب الخادم مفردٌ أو dim[]', () => {
  const sel = { subject: ['العلوم', 'الرياضيات'], grade: ['رابع-ابتدائي'] };
  const store = new URLSearchParams();
  appendSelection(store, sel);
  assert.deepEqual(store.getAll('subject'), ['العلوم', 'الرياضيات']);

  const api = new URLSearchParams();
  appendSelectionForApi(api, sel);
  assert.equal(api.get('grade'), 'رابع-ابتدائي');
  assert.deepEqual(api.getAll('subject[]'), ['العلوم', 'الرياضيات']);
  assert.equal(api.has('subject'), false);
});
