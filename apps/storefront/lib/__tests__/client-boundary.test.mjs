/**
 * Server ⇄ client module boundary.
 *
 * A Server Component that imports a plain VALUE (a constant, a helper
 * function) from a `'use client'` module does not get the value: Next hands
 * it a client reference. It type-checks, builds and renders — and the value is
 * silently wrong. This is how `?fmt=` deep links were dead on the PDP: the
 * page read `FORMAT_PARAM` from the client FormatSelector, so
 * `query[FORMAT_PARAM]` was always undefined.
 *
 * Rule enforced here: a module WITHOUT 'use client' may import from a
 * 'use client' module only components (PascalCase, not SCREAMING_CASE) and types.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const APP = join(dirname(fileURLToPath(import.meta.url)), '../..');
const ROOT = join(APP, '../..');

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    if (name === 'node_modules' || name === '.next' || name === '__tests__' || name === 'e2e') continue;
    const full = join(dir, name);
    if (statSync(full).isDirectory()) walk(full, out);
    else if (/\.tsx?$/.test(name) && !name.endsWith('.d.ts')) out.push(full);
  }
  return out;
}

/** True only when the module's FIRST statement is the 'use client' directive. */
function isClient(text) {
  let rest = text;
  for (;;) {
    rest = rest.replace(/^\s+/, '');
    if (rest.startsWith('//')) rest = rest.slice(rest.indexOf('\n') + 1 || rest.length);
    else if (rest.startsWith('/*')) rest = rest.slice(rest.indexOf('*/') + 2);
    else break;
  }
  return /^['"]use client['"]/.test(rest);
}

function resolveImport(from, spec) {
  let base;
  if (spec.startsWith('.')) base = resolve(dirname(from), spec);
  else if (spec.startsWith('@tahdheer/ui')) base = join(ROOT, 'packages/ui/src', spec.slice('@tahdheer/ui'.length) || 'index');
  else return null;
  for (const candidate of [base, `${base}.ts`, `${base}.tsx`, join(base, 'index.ts'), join(base, 'index.tsx')]) {
    if (existsSync(candidate) && statSync(candidate).isFile()) return candidate;
  }
  return null;
}

const IMPORT = /import\s+(type\s+)?([^'";]+?)\s+from\s+['"]([^'"]+)['"]/g;

/**
 * The module that actually DEFINES `name` when imported from `file` —
 * following `export { … } from` and `export * from` through plain barrels
 * (a barrel without 'use client' can still hand out a client module's value).
 */
function definingModule(file, name, depth = 0) {
  const text = readFileSync(file, 'utf8');
  if (depth > 4 || isClient(text)) return file;
  for (const m of text.matchAll(/export\s+(type\s+)?\{([^}]*)\}\s+from\s+['"]([^'"]+)['"]/g)) {
    if (m[1]) continue;
    for (const part of m[2].split(',')) {
      const item = part.trim();
      if (!item || item.startsWith('type ')) continue;
      const [orig, alias] = item.split(/\s+as\s+/).map((x) => x.trim());
      if ((alias ?? orig) === name) {
        const next = resolveImport(file, m[3]);
        return next ? definingModule(next, orig, depth + 1) : file;
      }
    }
  }
  for (const m of text.matchAll(/export\s+\*\s+from\s+['"]([^'"]+)['"]/g)) {
    const next = resolveImport(file, m[1]);
    if (!next) continue;
    const src = readFileSync(next, 'utf8');
    if (new RegExp(`export\\s+(const|function|let|class|async function)\\s+${name}\\b`).test(src)) {
      return definingModule(next, name, depth + 1);
    }
  }
  return file;
}

test('server modules import only components and types from client modules', () => {
  const files = [...walk(join(APP, 'app')), ...walk(join(APP, 'components')), ...walk(join(APP, 'lib'))];
  assert.ok(files.length > 100, `scanned ${files.length} files`);
  const offenders = [];

  for (const file of files) {
    const text = readFileSync(file, 'utf8');
    if (isClient(text)) continue;
    for (const [, typeOnly, clause, spec] of text.matchAll(IMPORT)) {
      if (typeOnly) continue;
      const target = resolveImport(file, spec);
      if (!target) continue;

      const names = [];
      const named = clause.match(/\{([^}]*)\}/);
      if (named) {
        for (const part of named[1].split(',')) {
          const item = part.trim();
          if (!item || item.startsWith('type ')) continue;
          names.push(item.split(/\s+as\s+/)[0].trim());
        }
      }
      const def = clause.replace(/\{[^}]*\}/, '').replace(/,/g, '').trim();
      if (def && !def.startsWith('*')) names.push(def);

      for (const name of names) {
        /* Components are PascalCase; SCREAMING_CASE is a constant — a value. */
        if (/^[A-Z]/.test(name) && !/^[A-Z0-9_]+$/.test(name)) continue;
        const origin = definingModule(target, name);
        if (isClient(readFileSync(origin, 'utf8'))) {
          offenders.push(`${relative(APP, file)}: «${name}» from client module ${relative(APP, origin)}`);
        }
      }
    }
  }

  assert.deepEqual(offenders, [], offenders.join('\n'));
});
