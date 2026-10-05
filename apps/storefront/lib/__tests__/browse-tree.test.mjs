/**
 * شجرة التصفّح (اللوحة الكبرى ودرج الجوّال): الصفّ يُنسب إلى مرحلته بحقل
 * `stage.slug` من الخادم، والعدد لا يُخترع، والمفردة الفارغة لا تُعرض.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { buildBrowseTree } from '../../components/chrome/browse-tree.ts';

const path = (d, slug) => `/${d}/${slug}`;

const stages = [
  { slug: 'p', name: 'ابتدائي', productsCount: 4 },
  { slug: 'm', name: 'متوسط', productsCount: 0 },
  { slug: 's', name: 'ثانوي' },
];
const grades = [
  { slug: 'g1', name: 'الأول', productsCount: 3, stage: { slug: 'p' } },
  { slug: 'g2', name: 'الثاني', productsCount: 0, stage: { slug: 'p' } },
  { slug: 'g3', name: 'العاشر', stage: { slug: 's' } },
  { slug: 'orphan', name: 'يتيم', productsCount: 2 },
];
const subjects = [{ slug: 'math', name: 'الرياضيات', productsCount: 1 }];

test('الصفوف تحت مراحلها بحقل stage.slug — واليتيم لا مكان له', () => {
  const tree = buildBrowseTree(stages, grades, subjects, path);
  assert.deepEqual(
    tree.stages.map((s) => [s.label, s.grades.map((g) => g.label)]),
    [
      ['ابتدائي', ['الأول']],
      ['ثانوي', ['العاشر']],
    ],
  );
});

test('العدد كما أرسله الخادم فقط، والروابط من دالّة المسار', () => {
  const tree = buildBrowseTree(stages, grades, subjects, path);
  const [primary, secondary] = tree.stages;
  assert.equal(primary.count, 4);
  assert.equal(primary.href, '/stages/p');
  assert.equal(primary.grades[0].href, '/grades/g1');
  assert.equal('count' in secondary, false);
  assert.deepEqual(tree.subjects, [{ label: 'الرياضيات', href: '/subjects/math', count: 1 }]);
});

test('لا مراحل ولا مواد ⇒ لا شجرة (يبقى البند رابطًا عاديًّا)', () => {
  assert.equal(buildBrowseTree([], [], [], path), null);
  assert.equal(buildBrowseTree([{ slug: 'x', name: 'x', productsCount: 0 }], [], [], path), null);
});
