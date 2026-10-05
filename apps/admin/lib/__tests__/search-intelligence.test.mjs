import { test } from 'node:test';
import assert from 'node:assert/strict';

import { formatRate, recoveryLabel } from '../search-intelligence.ts';

test('النسبة تُعرض ببسطها ومقامها', () => {
  assert.equal(formatRate({ numerator: 1, denominator: 2, rate: 0.5 }), '50% (1 / 2)');
  assert.equal(formatRate({ numerator: 2, denominator: 3, rate: 0.6667 }), '66.7% (2 / 3)');
});

test('مقامٌ صفر «لا بيانات» لا ٠٪', () => {
  assert.equal(formatRate({ numerator: 0, denominator: 0, rate: null }), 'لا بيانات');
  assert.equal(formatRate(undefined), 'لا بيانات');
});

test('نمطُ تعافٍ مجهول يُعرض باسمه', () => {
  assert.equal(recoveryLabel('exact'), 'مطابقة تامّة');
  assert.equal(recoveryLabel('new_mode'), 'new_mode');
});
