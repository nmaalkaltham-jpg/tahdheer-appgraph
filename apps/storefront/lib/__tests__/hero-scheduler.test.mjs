/**
 * جدولة دوران الهيرو — العقد §2 آلةَ حالات: الدوران دائم (التحويم والتركيز
 * لا يوقفان إلّا بـ`pauseOnHover`)، السحب يُمسك الساعة ما دام الإصبع فقط،
 * الزرّ نهائيّ حتّى التشغيل، والتبويب المخفيّ والهيرو الغائب وتقليل الحركة توقف.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { SLIDER_DEFAULTS } from '../../components/home/hero-config.ts';
import {
  INITIAL_STATE,
  ambientRunning,
  isRunning,
  nextIndex,
  reduce,
  showsPauseButton,
} from '../../components/home/hero-scheduler.ts';

/* الافتراض كما يشحنه المتجر (العقد §1: ‎pauseOnHover: false‎). */
const CFG = { autoplay: true, continuous: true, pauseOnHover: SLIDER_DEFAULTS.pauseOnHover, count: 4 };
const HOVER = { ...CFG, pauseOnHover: true };
const run = (events, cfg = CFG) => events.reduce((s, e) => reduce(s, e, cfg), INITIAL_STATE);

test('nextIndex يلتفّ في الاتّجاهين', () => {
  assert.equal(nextIndex(3, 4), 0);
  assert.equal(nextIndex(0, 4, -1), 3);
  assert.equal(nextIndex(9, 4, 0), 1);
  assert.equal(nextIndex(-1, 4, 0), 3);
  assert.equal(nextIndex(0, 0), 0);
});

test('يدور افتراضًا، ولا يدور بشريحةٍ واحدة أو بلا autoplay', () => {
  assert.equal(isRunning(INITIAL_STATE, CFG), true);
  assert.equal(isRunning(INITIAL_STATE, { ...CFG, count: 1 }), false);
  assert.equal(isRunning(INITIAL_STATE, { ...CFG, autoplay: false }), false);
});

test('الافتراض: pauseOnHover=false — طلب المالك «لا يتوقّف»', () => {
  assert.equal(SLIDER_DEFAULTS.pauseOnHover, false);
});

test('افتراضًا لا يوقف التحويمُ ولا التركيزُ (ولا اللمس) الدوران', () => {
  assert.equal(isRunning(run(['hover-start']), CFG), true);
  assert.equal(isRunning(run(['focus-in']), CFG), true);
  assert.equal(isRunning(run(['hover-start', 'focus-in']), CFG), true);
  /* نقرةٌ باللمس بلا سحب: تنقّلٌ يدويّ على الأكثر، والدوران يكمل. */
  assert.equal(isRunning(run(['user-nav']), CFG), true);
});

test('pauseOnHover=true (اختيار المالك): التحويم والتركيز إيقافٌ مؤقّت يزول بزوال سببه', () => {
  assert.equal(isRunning(run(['hover-start'], HOVER), HOVER), false);
  assert.equal(isRunning(run(['hover-start', 'hover-end'], HOVER), HOVER), true);
  assert.equal(isRunning(run(['focus-in'], HOVER), HOVER), false);
  assert.equal(isRunning(run(['focus-in', 'focus-out'], HOVER), HOVER), true);
});

test('continuous: السحب يُمسك الساعة ما دام الإصبع وحده، ثمّ يُكمل فورًا', () => {
  assert.equal(isRunning(run(['drag-start']), CFG), false);
  assert.equal(isRunning(run(['drag-start', 'drag-end']), CFG), true);
  assert.equal(isRunning(run(['drag-start', 'drag-end', 'user-nav']), CFG), true);
});

test('continuous=false: أوّل تفاعلٍ صريح إيقافٌ نهائيّ، والتشغيل يعيده', () => {
  const cfg = { ...CFG, continuous: false };
  assert.equal(isRunning(run(['user-nav'], cfg), cfg), false);
  assert.equal(isRunning(run(['drag-start', 'drag-end'], cfg), cfg), false);
  assert.equal(isRunning(run(['user-nav', 'toggle'], cfg), cfg), true);
});

test('زرّ الإيقاف نهائيّ: لا يستأنف بالتحويم ولا بالتنقّل — حتّى «تشغيل»', () => {
  const stopped = run(['toggle', 'hover-start', 'hover-end', 'focus-in', 'focus-out', 'user-nav', 'drag-start', 'drag-end']);
  assert.equal(stopped.stopped, true);
  assert.equal(isRunning(stopped, CFG), false);
  assert.equal(isRunning(reduce(stopped, 'toggle', CFG), CFG), true);
});

test('التبويب المخفيّ والهيرو خارج الشاشة يوقفان الدوران والحركة الخلفيّة معًا', () => {
  for (const [off, on] of [['hidden', 'visible'], ['offscreen', 'onscreen']]) {
    const s = run([off]);
    assert.equal(isRunning(s, CFG), false);
    assert.equal(ambientRunning(s), false);
    assert.equal(isRunning(reduce(s, on, CFG), CFG), true);
  }
  /* والتحويم لا يوقف الحركة الخلفيّة — هي ليست محتوى. */
  assert.equal(ambientRunning(run(['hover-start'])), true);
});

test('تقليل الحركة: لا دوران، ولا حركة خلفيّة، ولا زرّ إيقاف (لا شيء يُوقَف)', () => {
  const s = run(['reduce-on']);
  assert.equal(isRunning(s, CFG), false);
  assert.equal(ambientRunning(s), false);
  assert.equal(showsPauseButton(s, CFG), false);
  assert.equal(showsPauseButton(INITIAL_STATE, CFG), true);
  /* والزرّ يبقى ظاهرًا وهو متوقّف — ليُشغَّل. */
  assert.equal(showsPauseButton(run(['toggle']), CFG), true);
});

test('الالتفاف من الأخيرة إلى الأولى تقدّمٌ في الاتّجاه نفسه — لا رجوع عبر الشرائح', async () => {
  const { slotPlacement, navDirection } = await import('../../components/home/hero-scheduler.ts');
  const count = 4;
  /* من الأخيرة (3) «التالي» ⇒ الفهرس الخام 4 ⇒ تقدّم. */
  const dir = navDirection(4, 3);
  assert.equal(dir, 1);
  const prep = { index: nextIndex(4, count, 0), leaving: { i: 3, dir }, prep: true, dragging: false, toNext: true };
  const at = (m) => Array.from({ length: count }, (_, i) => slotPlacement(i, count, m));
  /* التحضير: الأولى في موضع «التالية» (‎+1‎)، والأخيرة في مكانها. */
  assert.deepEqual(at(prep).map((p) => [p.role, p.offset]), [['active', 1], ['parked', 1], ['parked', 1], ['leaving', 0]]);
  /* الحركة: الأولى إلى الصفر والأخيرة إلى موضع «السابقة» — شريحتان فقط تتحرّكان. */
  const run = at({ ...prep, prep: false });
  assert.deepEqual(run.map((p) => [p.role, p.offset]), [['active', 0], ['parked', 1], ['parked', 1], ['leaving', -1]]);
  assert.equal(run.filter((p) => p.role !== 'parked').length, 2);
  /* والرجوع من الأولى إلى الأخيرة مرآةٌ له. */
  assert.equal(navDirection(-1, 0), -1);
  const back = at({ index: 3, leaving: { i: 0, dir: -1 }, prep: false, dragging: false, toNext: false });
  assert.deepEqual([back[3].offset, back[0].offset], [0, 1]);
});

test('السحب يكشف الجارة في جهة الإصبع ويتبعانه معًا', async () => {
  const { slotPlacement } = await import('../../components/home/hero-scheduler.ts');
  const m = { index: 0, leaving: null, prep: false, dragging: true, toNext: false };
  const at = Array.from({ length: 3 }, (_, i) => slotPlacement(i, 3, m));
  assert.deepEqual(at.map((p) => [p.role, p.offset, p.follows]), [['active', 0, true], ['parked', 1, false], ['peek', -1, true]]);
});
