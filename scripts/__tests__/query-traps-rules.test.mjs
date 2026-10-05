import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { whereKeyOnRawBuilder } from '../guards/query-traps-rules.mjs';

test('flags whereKey chained on a raw DB::table builder', () => {
  const src = "<?php\n$n = DB::table('orders')->where('x', 1)->whereKey($id)->count();\n";
  assert.equal(whereKeyOnRawBuilder(src).length, 1);
});

test('flags whereKey reached across lines and nested calls', () => {
  const src = "<?php\nDB::table('refunds')\n    ->where(fn ($q) => $q->whereIn('s', ['A', 'B']))\n    ->whereKey($id)\n    ->update(['a' => 1]);\n";
  assert.equal(whereKeyOnRawBuilder(src).length, 1);
});

test('does not flag Eloquent whereKey or a whereKey in the next statement', () => {
  const src = "<?php\nOrder::query()->whereKey($id)->first();\nDB::table('orders')->where('id', $id)->first();\n$o = Order::query()->whereKey(1);\n";
  assert.equal(whereKeyOnRawBuilder(src).length, 0);
});

test('a semicolon inside a string does not end the statement early', () => {
  const src = "<?php\nDB::table('x')->whereRaw('a = 1; b')->whereKey(1)->get();\n";
  assert.equal(whereKeyOnRawBuilder(src).length, 1);
});

test('the payment health queries that hung the old regex scan in linear time', () => {
  const src = readFileSync(new URL('../../backend/laravel/app/Domain/Payments/PaymentHealth.php', import.meta.url), 'utf8');
  const t0 = performance.now();
  assert.equal(whereKeyOnRawBuilder(src).length, 0);
  assert.ok(performance.now() - t0 < 100, 'scan must be linear, not backtracking');
});

test('a pathological 5 000-link builder chain without a semicolon stays fast', () => {
  const src = '<?php\nDB::table(\'t\')' + "->where('a', fn ($q) => $q->where('b', 1))".repeat(5000);
  const t0 = performance.now();
  whereKeyOnRawBuilder(src);
  assert.ok(performance.now() - t0 < 200);
});
