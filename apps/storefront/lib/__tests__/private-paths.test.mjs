/**
 * Order tokens never leave the browser inside a measured path or referrer.
 *
 * The case table mirrors backend/laravel/tests/Unit/Privacy/CredentialPathsTest.php —
 * the server redacts again (the browser is not trusted), but the two must agree or the
 * same page is counted under two names.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { externalReferrer, isCredentialPath, redactPath, redactUrl } from '../private-paths.ts';

const T = 'Al0vVqN_FYriiSDOtyC_pQWH0CnDFWRbBtmfQKd_gyI';

const PATHS = [
  [`/orders/${T}`, '/orders/[token]'],
  [`/orders/${T}/`, '/orders/[token]/'],
  [`/orders/${T}/receipt`, '/orders/[token]/receipt'],
  [`/en/orders/${T}`, '/en/orders/[token]'],
  [`/ORDERS/${T}`, '/ORDERS/[token]'],
  [`/checkout/orders/${T}/payment`, '/checkout/orders/[token]/payment'],
  [`/orders/${T}?tap_id=chg_1`, '/orders/[token]'],
  [`/orders/${T}#files`, '/orders/[token]'],
  [`/account/orders?token=${T}`, '/account/orders'],
  [`/orders%2F${T}`, '/orders/[token]'],
  [`/orders/%2F${T}`, '/orders/[token]'],
  [`/orders//${T}`, '/orders/[token]'],
  [`/orders%252F${T}`, '/orders/[token]'],
  [`/orders%5C${T}`, '/orders/[token]'],
  [`/orders%2F${T}/%ZZ`, '/orders/[token]/%ZZ'],
  [`/orders;x/${T}`, '/orders;x/[token]'],
  [`/orders/./${T}`, '/orders/[token]/[token]'],
  [`/anything/${T}/x`, '/anything/[token]/x'],
  ['/products/grade-five-science-lesson-plans-term-one-v2', '/products/grade-five-science-lesson-plans-term-one-v2'],
  ['/orders/[token]', '/orders/[token]'],
  ['/account/orders', '/account/orders'],
  ['/orders', '/orders'],
  ['/products/orders-guide', '/products/orders-guide'],
  ['/products/تحضير-لغتي', '/products/تحضير-لغتي'],
  ['/', '/'],
];

for (const [raw, expected] of PATHS) {
  test(`redactPath ${raw.slice(0, 40)}`, () => {
    assert.equal(redactPath(raw), expected);
    assert.ok(!redactPath(raw).includes(T));
  });
}

test('isCredentialPath is true only when a token would be redacted', () => {
  assert.equal(isCredentialPath(`/orders/${T}`), true);
  assert.equal(isCredentialPath(`/orders/%2F${T}`), true);
  assert.equal(isCredentialPath(`/orders%2F${T}/%ZZ`), true);
  assert.equal(isCredentialPath('/orders/[token]'), false);
  assert.equal(isCredentialPath('/products/x'), false);
});

test('redactUrl keeps origin and redacted path only', () => {
  assert.equal(redactUrl(`https://store.example/orders/${T}`), 'https://store.example/orders/[token]');
  assert.equal(redactUrl(`http://127.0.0.1:3000/orders/${T}?x=1`), 'http://127.0.0.1:3000/orders/[token]');
  assert.equal(redactUrl(`https://store.example/account/orders?token=${T}`), 'https://store.example/account/orders');
  assert.equal(redactUrl('https://user:pass@evil.example/x'), 'https://evil.example/x');
  assert.equal(redactUrl(`/orders/${T}`), '/orders/[token]');
  assert.equal(redactUrl(''), null);
  assert.equal(redactUrl(null), null);
  assert.equal(redactUrl('javascript:alert(1)'), null);
});

test('externalReferrer sends only another site origin, never our own pages', () => {
  const origin = 'https://store.example';
  assert.equal(externalReferrer(`https://store.example/orders/${T}`, origin), undefined);
  assert.equal(externalReferrer(`https://store.example/account/orders?token=${T}`, origin), undefined);
  assert.equal(externalReferrer('https://www.google.com/search?q=name', origin), 'https://www.google.com');
  assert.equal(externalReferrer(`https://evil.example/orders/${T}`, origin), 'https://evil.example');
  assert.equal(externalReferrer('', origin), undefined);
  assert.equal(externalReferrer('not a url', origin), undefined);
});

/*
 * The guard: a new route whose dynamic segment is a credential ([token], [key], …) must be
 * covered by redactPath, or its secret reaches the measurement tables the day it ships.
 */
const CREDENTIAL_PARAM = /^\[(?:\.\.\.)?(token|key|secret|code|grant|signature|otp|hash)\]$/i;
const appDir = fileURLToPath(new URL('../../app/', import.meta.url));

function* routeDirs(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory() && !entry.name.startsWith('_')) {
      const full = join(dir, entry.name);
      yield full;
      yield* routeDirs(full);
    }
  }
}

test('every credential-named dynamic route is redacted', () => {
  const credentialRoutes = [...routeDirs(appDir)]
    .map((dir) => relative(appDir, dir).split(sep))
    .filter((segments) => CREDENTIAL_PARAM.test(segments.at(-1)))
    .map((segments) =>
      '/' +
      segments
        .filter((s) => !/^\(.*\)$/.test(s)) // route groups are not in the URL
        .map((s) => (CREDENTIAL_PARAM.test(s) ? T : s.startsWith('[') ? 'x' : s))
        .join('/'),
    );

  assert.ok(credentialRoutes.includes(`/orders/${T}`), 'the order page is discovered');
  for (const path of credentialRoutes) {
    assert.ok(!redactPath(path).includes(T), `${path.replace(T, '[token]')} is not redacted by lib/private-paths.ts`);
  }
});
