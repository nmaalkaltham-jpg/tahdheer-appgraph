/**
 * تشخيصُ الاستعلامات بلا نتائج — السببُ من الخادم (`ZeroResultDiagnosis`)
 * يُعرض شارةً بنبرةٍ ثابتة، والسببُ الذي لا تعرفه الواجهة بعدُ لا يُسقطها.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { diagnosisBadge, DIAGNOSIS_ORDER } from '../search-diagnosis.ts';

test('كلّ سببٍ يُصدره الخادم له تسميةٌ ونبرة', () => {
  assert.deepEqual(DIAGNOSIS_ORDER, ['missing_inventory', 'unknown_term', 'off_catalog', 'resolved']);
  assert.deepEqual(diagnosisBadge('missing_inventory'), { label: 'منتج ناقص', tone: 'warning' });
  assert.deepEqual(diagnosisBadge('unknown_term'), { label: 'مرادف ناقص', tone: 'info' });
  assert.deepEqual(diagnosisBadge('off_catalog'), { label: 'لا صلة بالمتجر', tone: 'neutral' });
  assert.deepEqual(diagnosisBadge('resolved'), { label: 'أُصلح منذ سُجّل', tone: 'success' });
});

test('سببٌ جديد لا تعرفه الواجهة يُعرض محايدًا باسمه — لا انهيار ولا تخمين', () => {
  assert.deepEqual(diagnosisBadge('future_reason'), { label: 'future_reason', tone: 'neutral' });
});
