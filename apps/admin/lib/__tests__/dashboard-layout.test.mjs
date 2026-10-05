/**
 * مساحة عمل اللوحة — المحرّك الخالص: تعبئةٌ بلا تصادم، وأقفالٌ تُحترم،
 * وقوالب لا تمسّ البيانات، وتنقيةٌ تُسقط المجهول، وترحيلٌ من التفضيل القديم.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  DASHBOARD_PRESETS,
  GRID_COLS,
  alignmentGuides,
  applyPreset,
  defaultDoc,
  duplicateBlocker,
  duplicateWidget,
  flow,
  layoutForCols,
  migrateLegacy,
  moveWidget,
  nudgeWidget,
  pack,
  packWidgets,
  presetWidgets,
  pushHistory,
  redoHistory,
  resizeWidget,
  sanitizeDoc,
  setHidden,
  setLocked,
  snapTo,
  undoHistory,
} from '../dashboard-layout.ts';

const visible = (widgets) => Object.entries(widgets).filter(([, s]) => !s.hidden);

function assertNoCollisions(widgets) {
  const boxes = visible(widgets);
  for (let i = 0; i < boxes.length; i += 1) {
    const [ia, a] = boxes[i];
    assert.ok(a.x >= 0 && a.x + a.w <= GRID_COLS, `${ia} خارج الشبكة`);
    for (let j = i + 1; j < boxes.length; j += 1) {
      const [ib, b] = boxes[j];
      const hit = a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
      assert.equal(hit, false, `${ia} يصطدم بـ${ib}`);
    }
  }
}

test('كلّ قالبٍ يبني تخطيطًا بلا تصادم', () => {
  for (const preset of DASHBOARD_PRESETS) assertNoCollisions(presetWidgets(preset.id));
});

test('العمليّات يطابق اللوحة القديمة: الانتباه والصحّة صفٌّ واحد، والمؤشّر الأوّل بجوار أربعة مؤشّرات', () => {
  const w = presetWidgets('operations');
  assert.deepEqual([w.attention.y, w.health.y, w.attention.x, w.health.x], [0, 0, 0, 8]);
  assert.equal(w.hero.y, w['kpi-orders'].y);
  assert.equal(w['kpi-refunded'].x + w['kpi-refunded'].w, GRID_COLS);
  /* البثّ والرؤى يتكدّسان بجوار الطابور، وصفّ الذكاء الأربعة يبدأ بعده معًا. */
  assert.equal(w.stream.x, 8);
  assert.equal(w.stream.y, w.queue.y);
  assert.equal(w.insights.y, w.stream.y + w.stream.h);
  assert.equal(w.products.y, w.queue.y + w.queue.h);
  assert.equal(new Set(['products', 'customers', 'payments', 'security'].map((id) => w[id].y)).size, 1);
});

test('التعبئة متساوية القوّة: تعبئة المعبّأ تعيده كما هو', () => {
  const once = presetWidgets('operations');
  assert.deepEqual(packWidgets(once), once);
});

test('الجاذبية تملأ الفجوة الرأسيّة', () => {
  const out = pack([{ id: 'a', x: 0, y: 5, w: 4, h: 2 }]);
  assert.equal(out[0].y, 0);
});

test('الملء الأوّل يضع القصيرة تحت جارتها لا تحت الطويلة', () => {
  const out = flow([{ id: 'tall', w: 8, h: 10 }, { id: 's1', w: 4, h: 3 }, { id: 's2', w: 4, h: 3 }]);
  assert.deepEqual(out.map((b) => [b.id, b.x, b.y]), [['tall', 0, 0], ['s1', 8, 0], ['s2', 8, 3]]);
});

test('النقل إلى صفٍّ مشغول يأخذه ويدفع الآخر تحته — بلا تصادم', () => {
  const start = presetWidgets('operations');
  const out = moveWidget(start, 'queue', 0, 0);
  assert.equal(out.queue.y, 0);
  assert.ok(out.attention.y >= out.queue.h);
  assertNoCollisions(out);
});

test('المقفلة لا تتحرّك ولا تُدفع', () => {
  let widgets = presetWidgets('operations');
  widgets = { ...widgets, attention: { ...widgets.attention, locked: true } };
  assert.equal(moveWidget(widgets, 'attention', 4, 20), widgets);
  const out = moveWidget(widgets, 'queue', 0, 0);
  assert.deepEqual([out.attention.x, out.attention.y], [0, 0]);
  assert.notEqual(out.queue.y, 0);
  assertNoCollisions(out);
});

test('التحجيم يلزم حدود النوع والشبكة', () => {
  const start = presetWidgets('operations');
  const out = resizeWidget(start, 'kpi-orders', 40, 1);
  assert.equal(out['kpi-orders'].w, 6);
  assert.equal(out['kpi-orders'].h, 5);
  assertNoCollisions(out);
  assert.equal(resizeWidget(start, 'hero', 1, 99).hero.w, 3);
});

test('الإخفاء يُسقط من الشبكة، والإظهار يعيد بلا تصادم', () => {
  const doc = defaultDoc();
  const hidden = setHidden(doc, 'queue', true);
  assert.equal(hidden.widgets.queue.hidden, true);
  assert.equal(hidden.preset, 'custom');
  const back = setHidden(hidden, 'queue', false);
  assertNoCollisions(back.widgets);
});

test('القالب يضبط الظهور والحجم والكثافة — والعودة إلى «مخصّص» تستعيد الترتيب الخاصّ', () => {
  const custom = { ...setHidden(defaultDoc(), 'domains', true) };
  const exec = applyPreset(custom, 'analytics');
  assert.equal(exec.preset, 'analytics');
  assert.equal(exec.density, 'compact');
  assert.equal(exec.widgets.queue.hidden, true);
  const back = applyPreset(exec, 'custom');
  assert.equal(back.widgets.domains.hidden, true);
  assert.equal(back.widgets.queue.hidden, false);
});

test('النسخ: مؤشّرٌ بمقياسٍ جديد، ومعطّلٌ بسببٍ مكتوب لما لا معنى لنسخه', () => {
  const doc = defaultDoc();
  assert.match(duplicateBlocker(doc, 'queue', []), /واحدة/);
  assert.equal(duplicateBlocker(doc, 'kpi-orders', ['orders', 'aov']), 'كلّ مقياسٍ متاح معروضٌ في بطاقةٍ ظاهرة.');
  const hidden = setHidden(doc, 'kpi-aov', true);
  const result = duplicateWidget(hidden, 'kpi-orders', ['orders', 'aov']);
  assert.ok(result);
  assert.equal(result.id, 'kpi-2');
  assert.equal(result.doc.widgets['kpi-2'].settings.metric, 'aov');
  assertNoCollisions(result.doc.widgets);
});

test('التنقية تُسقط المجهول وتلحق الجديد وتصحّح الحدود', () => {
  const doc = sanitizeDoc({
    preset: 'nope',
    mode: 'canvas',
    widgets: {
      queue: { x: 11, y: 0, w: 12, h: 40, order: 0, locked: 'yes', color: 'red' },
      gone: { x: 0, y: 0, w: 3, h: 3 },
      'kpi-7': { x: 0, y: 0, w: 2, h: 5, type: 'kpi', settings: { metric: 'aov', evil: 1 } },
    },
    canvas: { queue: { x: 10, y: 10, w: 400, h: 300 }, bad: { x: -1 } },
  });
  assert.equal(doc.preset, 'custom');
  assert.equal(doc.mode, 'canvas');
  assert.equal('gone' in doc.widgets, false);
  assert.equal(doc.widgets.queue.x, 0);
  assert.equal(doc.widgets.queue.h, 36);
  assert.equal(doc.widgets.queue.locked, false);
  assert.deepEqual(doc.widgets['kpi-7'].settings, { metric: 'aov' });
  assert.ok('attention' in doc.widgets, 'البطاقة الغائبة تُلحق');
  assert.deepEqual(Object.keys(doc.canvas), ['queue']);
  assertNoCollisions(doc.widgets);
  assert.equal(sanitizeDoc('x'), null);
  assert.equal(sanitizeDoc({ widgets: [] }), null);
});

test('الترحيل من admin.layout.prefs.v1 يحفظ الإخفاء والترتيب', () => {
  const doc = migrateLegacy(JSON.stringify({ dashboard: { hidden: ['domains'], order: ['queue', 'attention'] }, sidebar: { hidden: [], order: [] } }));
  assert.equal(doc.preset, 'custom');
  assert.equal(doc.widgets.domains.hidden, true);
  assert.equal(doc.widgets.queue.y, 0);
  assert.equal(doc.widgets.queue.x, 0);
  assertNoCollisions(doc.widgets);
  assert.equal(migrateLegacy(JSON.stringify({ sidebar: { hidden: ['x'], order: [] } })), null);
  assert.equal(migrateLegacy('{bad'), null);
  assert.equal(migrateLegacy(null), null);
});

test('الشاشة الضيّقة: عمودٌ واحد يكدّس بالترتيب البصريّ', () => {
  const boxes = layoutForCols(presetWidgets('operations'), 1);
  assert.equal(boxes[0].id, 'attention');
  assert.equal(boxes[1].id, 'health');
  assert.ok(boxes.every((b) => b.w === 1 && b.x === 0));
});

test('الأدلّة تلتقط الحافّة والوسط ضمن العتبة', () => {
  const g = alignmentGuides({ x: 104, y: 3, w: 100, h: 50 }, [{ x: 0, y: 0, w: 100, h: 200 }]);
  assert.deepEqual(g.x, [100]);
  assert.equal(g.rect.x, 100);
  assert.deepEqual(g.y, [0]);
  assert.equal(g.rect.y, 0);
  assert.deepEqual(alignmentGuides({ x: 300, y: 300, w: 10, h: 10 }, [{ x: 0, y: 0, w: 10, h: 10 }]).x, []);
  assert.equal(snapTo(13), 16);
});

test('التاريخ: تراجعٌ ثمّ إعادة، وفعلٌ جديد يمحو المستقبل', () => {
  const a = defaultDoc();
  const b = setLocked(a, 'queue', true);
  let history = pushHistory({ past: [], future: [] }, a);
  const undone = undoHistory(history, b);
  assert.equal(undone.doc, a);
  const redone = redoHistory(undone.history, undone.doc);
  assert.equal(redone.doc, b);
  history = pushHistory(undone.history, a);
  assert.equal(history.future.length, 0);
  assert.equal(undoHistory({ past: [], future: [] }, a), null);
});

test('لوحة المفاتيح: «أسفل» تبادلٌ مع الجارة التي تحتها، و«أعلى» مع التي فوقها', () => {
  const start = presetWidgets('operations');
  const down = nudgeWidget(start, 'health', 0, 1);
  assert.ok(down.health.y > start.health.y, 'نزلت');
  assertNoCollisions(down);
  const up = nudgeWidget(down, 'health', 0, -1);
  assert.equal(up.health.y, 0);
  assertNoCollisions(up);
  assert.equal(nudgeWidget(start, 'health', 0, -1), start, 'لا جارة فوقها — لا حركة');
});
