/**
 * قاعدة عرض Apple Pay (lib/apple-pay.ts): الخادم يعلنه **و**الجهاز يقدر عليه.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { APPLE_PAY_METHOD, applePayAvailable, deviceCanApplePay } from '../apple-pay.ts';

test('device: true only when canMakePayments() returns exactly true', () => {
  assert.equal(deviceCanApplePay({ ApplePaySession: { canMakePayments: () => true } }), true);
  assert.equal(deviceCanApplePay({ isSecureContext: true, ApplePaySession: { canMakePayments: () => true } }), true);
  assert.equal(deviceCanApplePay({ ApplePaySession: { canMakePayments: () => false } }), false);
  assert.equal(deviceCanApplePay({ ApplePaySession: { canMakePayments: () => 'yes' } }), false);
  assert.equal(deviceCanApplePay({ ApplePaySession: {} }), false);
  assert.equal(deviceCanApplePay({}), false);
  assert.equal(deviceCanApplePay(null), false);
  assert.equal(deviceCanApplePay(undefined), false);
});

test('device: insecure context or a throwing session is a no', () => {
  assert.equal(deviceCanApplePay({ isSecureContext: false, ApplePaySession: { canMakePayments: () => true } }), false);
  assert.equal(
    deviceCanApplePay({
      ApplePaySession: {
        canMakePayments: () => {
          throw new Error('InvalidAccessError');
        },
      },
    }),
    false,
  );
});

test('available: server AND device', () => {
  assert.equal(applePayAvailable(true, true), true);
  assert.equal(applePayAvailable(true, false), false);
  assert.equal(applePayAvailable(false, true), false);
  assert.equal(applePayAvailable(false, false), false);
});

test('the method name matches the checkout contract', () => {
  assert.equal(APPLE_PAY_METHOD, 'apple_pay');
});
