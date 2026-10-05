/**
 * جدولةُ دوران الهيرو — آلة حالاتٍ نقيّة (بلا DOM ولا مؤقّتات) يختبرها
 * `lib/__tests__/hero-scheduler.test.mjs`، ويقودها `HeroSlider.tsx`.
 *
 * العقد §2: الدوران دائم — التحويم والتركيز لا يوقفانه (إلّا إن فعّل المالك
 * `pauseOnHover` فيصيران إيقافًا مؤقّتًا يزول بزوال سببه)؛ والسحب يُمسك الساعة
 * ما دام الإصبع على المسرح فقط ثمّ يُكمل فورًا؛ زرّ الإيقاف إيقافٌ نهائيّ حتّى
 * «تشغيل»؛ والتبويب المخفيّ والهيرو خارج الشاشة يوقفان كلّ شيء توفيرًا للمعالج؛
 * و`prefers-reduced-motion` يلغي الدوران أصلًا. ومع `continuous: false` يصير
 * أوّل تفاعلٍ صريح (تنقّلٌ يدويّ أو سحب) إيقافًا نهائيًّا — السلوك الكلاسيكيّ.
 */

export interface SchedulerState {
  /** إيقافٌ بالزرّ (أو بتفاعلٍ مع `continuous: false`) — لا يزول إلّا بالتشغيل. */
  stopped: boolean;
  hover: boolean;
  focus: boolean;
  drag: boolean;
  hidden: boolean;
  offscreen: boolean;
  reduced: boolean;
}

export type SchedulerEvent =
  | 'toggle'
  | 'hover-start'
  | 'hover-end'
  | 'focus-in'
  | 'focus-out'
  | 'drag-start'
  | 'drag-end'
  | 'user-nav'
  | 'hidden'
  | 'visible'
  | 'offscreen'
  | 'onscreen'
  | 'reduce-on'
  | 'reduce-off';

export interface SchedulerConfig {
  autoplay: boolean;
  continuous: boolean;
  pauseOnHover: boolean;
  count: number;
}

export const INITIAL_STATE: SchedulerState = {
  stopped: false,
  hover: false,
  focus: false,
  drag: false,
  hidden: false,
  offscreen: false,
  reduced: false,
};

/** الفهرس التالي بالتفاف — `delta` سالبٌ للسابق. */
export function nextIndex(index: number, count: number, delta = 1): number {
  if (count <= 0) return 0;
  return (((index + delta) % count) + count) % count;
}

export function reduce(state: SchedulerState, event: SchedulerEvent, config: SchedulerConfig): SchedulerState {
  const interaction = () => (config.continuous ? state.stopped : true);
  switch (event) {
    case 'toggle':
      return { ...state, stopped: !state.stopped };
    case 'hover-start':
      return { ...state, hover: true };
    case 'hover-end':
      return { ...state, hover: false };
    case 'focus-in':
      return { ...state, focus: true };
    case 'focus-out':
      return { ...state, focus: false };
    case 'drag-start':
      return { ...state, drag: true };
    case 'drag-end':
      return { ...state, drag: false, stopped: interaction() };
    case 'user-nav':
      return { ...state, stopped: interaction() };
    case 'hidden':
      return { ...state, hidden: true };
    case 'visible':
      return { ...state, hidden: false };
    case 'offscreen':
      return { ...state, offscreen: true };
    case 'onscreen':
      return { ...state, offscreen: false };
    case 'reduce-on':
      return { ...state, reduced: true };
    case 'reduce-off':
      return { ...state, reduced: false };
    default:
      return state;
  }
}

/** هل يتقدّم المؤقّت الآن؟ (والحركة الخلفيّة تتبع `ambientRunning`.) */
export function isRunning(state: SchedulerState, config: SchedulerConfig): boolean {
  if (!config.autoplay || config.count < 2) return false;
  if (state.reduced || state.stopped || state.hidden || state.offscreen || state.drag) return false;
  /* التحويم والتركيز لا يوقفان إلّا باختيار المالك؛ والتركيز الذي تغادر شريحته
     ينقله `HeroSlider` إلى الشريحة الحاضرة فلا يسقط في `inert`. */
  return !(config.pauseOnHover && (state.hover || state.focus));
}

/** الحركة الخلفيّة الهادئة: تقف مع التبويب المخفيّ والهيرو الغائب وتقليل الحركة — لا مع التحويم. */
export function ambientRunning(state: SchedulerState): boolean {
  return !state.reduced && !state.hidden && !state.offscreen;
}

/** زرّ الإيقاف ظاهرٌ ما دام الدوران ممكنًا (WCAG 2.2.2). */
export function showsPauseButton(state: SchedulerState, config: SchedulerConfig): boolean {
  return config.autoplay && config.count > 1 && !state.reduced;
}

/* ── مكان كلّ شريحة في وضع «slide» — بلا مسارٍ طويل يُعاد لفّه ──
 *
 * كلّ الشرائح في خليّةٍ واحدة، ولا يتحرّك إلّا اثنتان: الحاضرة ومن تغادر
 * (أو الجارة التي يكشفها السحب). فالعبور من الأخيرة إلى الأولى تقدّمٌ في
 * الاتّجاه نفسه كأيّ تقدّم — لا رجوعٌ عكسيّ عبر الشرائح كلّها.
 * الإزاحة بوحدة «عرض شريحة» في اتّجاه القراءة: ‎+1‎ موضع التالية، ‎−1‎ موضع السابقة.
 */
export type SlotRole = 'active' | 'peek' | 'leaving' | 'parked';

export interface SlideMotion {
  index: number;
  /** الشريحة التي غادرت واتّجاه الحركة (‎1‎ تقدّم، ‎−1‎ رجوع). */
  leaving: { i: number; dir: 1 | -1 } | null;
  /** طور التحضير: الداخلة في موضع انطلاقها بلا انتقال، قبل إطارٍ واحد من الحركة. */
  prep: boolean;
  /** السحب جارٍ، وأيّ جارةٍ يكشف (‎toNext‎). */
  dragging: boolean;
  toNext: boolean;
}

export interface SlotPlacement {
  role: SlotRole;
  offset: number;
  /** يتبع الإصبع (تُضاف إزاحة السحب بالبكسل). */
  follows: boolean;
}

export function slotPlacement(i: number, count: number, m: SlideMotion): SlotPlacement {
  if (i === m.index) {
    if (m.prep && m.leaving) return { role: 'active', offset: m.leaving.dir, follows: false };
    return { role: 'active', offset: 0, follows: m.dragging };
  }
  if (m.dragging && count > 1 && i === nextIndex(m.index, count, m.toNext ? 1 : -1)) {
    return { role: 'peek', offset: m.toNext ? 1 : -1, follows: true };
  }
  if (m.leaving && i === m.leaving.i && !m.dragging) {
    return {
      role: 'leaving',
      offset: m.prep ? 0 : -m.leaving.dir,
      follows: false,
    };
  }
  return { role: 'parked', offset: 1, follows: false };
}

/** اتّجاه الانتقال إلى فهرسٍ خامٍ (قبل الالتفاف): ما بعد الحاضرة تقدّم، وما قبلها رجوع. */
export function navDirection(rawTarget: number, index: number): 1 | -1 {
  return rawTarget >= index ? 1 : -1;
}
