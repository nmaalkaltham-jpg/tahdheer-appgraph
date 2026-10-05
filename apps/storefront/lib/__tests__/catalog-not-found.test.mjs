/**
 * أرشيفات المفردات ومحاور المنهج: مقطعٌ لا تعرفه المفردات = 404 حقيقيّ.
 *
 * مع `loading.tsx` يبثّ Next الهيكل بحالة 200 قبل أن تبلغ الصفحة `notFound()`،
 * فالحالة تُقرَّر في `generateMetadata` (قبل أوّل بايت) وتُعاد في الصفحة احتياطًا.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = resolve(import.meta.dirname, '../../../..');
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');

test('TermArchive: notFound قبل البثّ (في البيانات الوصفيّة) وفي الصفحة', () => {
  const src = read('apps/storefront/app/(catalog)/_components/TermArchive.tsx');
  const guard = 'if (termsRes.status === 200 && !term) notFound();';
  const metaStart = src.indexOf('export async function termArchiveMetadata');
  const pageStart = src.indexOf('export async function TermArchive(');
  assert.ok(metaStart > 0 && pageStart > metaStart);
  assert.ok(src.slice(metaStart, pageStart).includes(guard), 'البيانات الوصفيّة بلا notFound');
  assert.ok(src.slice(pageStart).includes(guard), 'الصفحة بلا notFound');
});

test('assertTermRoute: كلّ صفحة مقطعٍ تناديه قبل أن تُعيد عنصرًا', () => {
  const src = read('apps/storefront/app/(catalog)/_components/TermArchive.tsx');
  assert.ok(src.includes('export async function assertTermRoute('));
  assert.ok(src.includes('if (termsRes.status === 200 && !findTerm(dimension, termsRes.data, segment)) notFound();'));
  for (const d of ['stages', 'grades', 'subjects', 'semesters', 'kinds']) {
    const page = read(`apps/storefront/app/${d}/[slug]/page.tsx`);
    assert.ok(page.includes(`await assertTermRoute('${d}', slug);`), `${d}/[slug]`);
  }
});

test('CurriculumHub: 404 الخادم يُقرَّر في البيانات الوصفيّة أيضًا', () => {
  const src = read('apps/storefront/app/curriculum/[type]/[slug]/page.tsx');
  const metaStart = src.indexOf('export async function generateMetadata');
  const pageStart = src.indexOf('export default async function CurriculumHubPage');
  assert.ok(src.slice(metaStart, pageStart).includes('if (status === 404) notFound();'));
  assert.ok(src.slice(pageStart).includes('if (hubRes.status === 404) notFound();'));
});
