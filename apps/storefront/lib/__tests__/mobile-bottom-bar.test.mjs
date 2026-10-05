/**
 * الشريط السفليّ للجوّال — اللسان النشط يتبع المسار، ولا شريط فوق شريط
 * الشراء في صفحة المنتج.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { activeBottomTab, BOTTOM_TABS, hidesBottomBar } from '../../components/mobile-bottom-bar.ts';

test('خمسة ألسنة بترتيب القالب وبروابط حقيقيّة', () => {
  assert.deepEqual(
    BOTTOM_TABS.map((t) => t.id),
    ['home', 'browse', 'search', 'wishlist', 'account'],
  );
  for (const tab of BOTTOM_TABS) assert.match(tab.href, /^\/[a-z-]*$/);
});

test('اللسان النشط من المسار — ولا لسان لصفحةٍ لا تنتمي', () => {
  assert.equal(activeBottomTab('/'), 'home');
  assert.equal(activeBottomTab('/search'), 'search');
  assert.equal(activeBottomTab('/wishlist'), 'wishlist');
  assert.equal(activeBottomTab('/account/library'), 'account');
  assert.equal(activeBottomTab('/login'), 'account');
  assert.equal(activeBottomTab('/stages/primary'), 'browse');
  assert.equal(activeBottomTab('/subjects'), 'browse');
  assert.equal(activeBottomTab('/help'), null);
  assert.equal(activeBottomTab('/searchable'), null);
});

test('يختفي على صفحة المنتج وحدها', () => {
  assert.equal(hidesBottomBar('/products/abc'), true);
  assert.equal(hidesBottomBar('/products'), true);
  assert.equal(hidesBottomBar('/productsx'), false);
  assert.equal(hidesBottomBar('/'), false);
  assert.equal(hidesBottomBar('/cart'), false);
});
