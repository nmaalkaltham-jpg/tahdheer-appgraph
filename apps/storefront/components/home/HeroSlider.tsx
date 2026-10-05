'use client';
/**
 * HeroSlider — مسرح الشرائح المتحرّك باستمرار (عقد الهيرو ٢٠٢٦-٠٩-٢٥ §2).
 *
 * الشرائح تُصيَّر على الخادم (`design.tsx`) وتصل عقدًا جاهزة — الأولى في
 * أوّل HTML — وهذا المكوّن يملك الحركة وحدها:
 *  · الساعة: عنصرٌ خفيّ بحركة CSS مدّتها `intervalMs`؛ نهايتها (`animationend`)
 *    تُقدّم الشريحة. الإيقاف المؤقّت `animation-play-state: paused` فيُستأنف
 *    من حيث وقف، وشريط التقدّم في النقطة النشطة يتبع الساعة نفسها.
 *  · آلة الحالات في `hero-scheduler.ts`: الدوران دائم — التحويم والتركيز لا
 *    يوقفانه (إلّا بـ`pauseOnHover`)، والسحب يُمسكه ما دام الإصبع ثمّ يُكمل فورًا،
 *    والزرّ إيقافٌ نهائيّ حتّى «تشغيل»، والتبويب المخفيّ والهيرو خارج الشاشة
 *    يوقفان كلّ شيء، و`prefers-reduced-motion` يلغي الدوران والحركة الخلفيّة.
 *  · «slide»: الشرائح في خليّةٍ واحدة ولا يتحرّك إلّا الحاضرة ومن تغادر
 *    (`slotPlacement`)، فالأخيرة → الأولى تقدّمٌ في الاتّجاه نفسه بلا رجوع.
 *  · السحب باللمس بلا زخم: الحاضرة وجارتها تتبعان الإصبع ثمّ تنطبقان.
 *  · التركيز داخل شريحةٍ تغادر ينتقل إلى الحاضرة (لا يسقط مع `inert`).
 *
 * الوصول: `role=region` + `aria-roledescription=carousel`، والشريحة «n من m»،
 * وغير الحاضرة `inert`، والمنطقة الحيّة صامتة أثناء الدوران ومهذّبة حين يقود
 * المستخدم، والأسهم وHome/End داخل المسرح.
 */
import {
  useEffect,
  useLayoutEffect,
  useReducer,
  useRef,
  useState,
  type CSSProperties,
  type KeyboardEvent,
  type PointerEvent,
  type ReactNode,
} from 'react';
import { flushSync } from 'react-dom';
import { formatNumber } from '@tahdheer/design-system/format';
import { DesignIcon } from './designIcons';
import type { SliderSettings } from './hero-config';
import {
  INITIAL_STATE,
  ambientRunning,
  isRunning,
  navDirection,
  nextIndex,
  reduce,
  showsPauseButton,
  slotPlacement,
  type SchedulerEvent,
} from './hero-scheduler';
import styles from './design.module.css';

/** مسافة السحب التي تنقل الشريحة — لا زخم، فالقرار على الإفلات وحده. */
const SWIPE_MIN_PX = 48;
const SWIPE_RATIO = 0.12;
/** ما دون هذا حركةُ إصبعٍ عابرة، لا سحب. */
const DRAG_SLOP_PX = 8;

export interface HeroSlideNode {
  id: string;
  node: ReactNode;
}

export function HeroSlider({
  slides,
  settings,
  label,
}: {
  slides: HeroSlideNode[];
  settings: SliderSettings;
  label: string;
}) {
  const count = slides.length;
  const config = {
    autoplay: settings.autoplay,
    continuous: settings.continuous,
    pauseOnHover: settings.pauseOnHover,
    count,
  };
  const [index, setIndex] = useState(0);
  /* من غادرت واتّجاهها، وطور التحضير (الداخلة في موضع انطلاقها قبل إطار الحركة). */
  const [leaving, setLeaving] = useState<{ i: number; dir: 1 | -1 } | null>(null);
  const [prep, setPrep] = useState(false);
  /* يُعاد تشغيل الساعة وشريط التقدّم من الصفر عند كلّ اختيارٍ يدويّ. */
  const [cycle, setCycle] = useState(0);
  const [sched, dispatch] = useReducer(
    (state: typeof INITIAL_STATE, event: SchedulerEvent) => reduce(state, event, config),
    INITIAL_STATE,
  );
  /* الاتّجاه يُقرأ بعد التركيب؛ المتجر RTL، فالافتراض يطابق أوّل HTML. */
  const [ltr, setLtr] = useState(false);
  const [dragDx, setDragDx] = useState(0);
  /* المستخدم يقود (التركيز داخل الدوّار) ⇒ المنطقة الحيّة تعلن الشريحة الجديدة. */
  const [focusWithin, setFocusWithin] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const drag = useRef<{
    id: number;
    x: number;
    y: number;
    active: boolean;
    width: number;
  } | null>(null);
  const justDragged = useRef(false);
  /* التركيز كان داخل الشريحة المغادرة ⇒ يُنقل إلى الحاضرة بعد الرسم. */
  const refocus = useRef(false);

  const running = isRunning(sched, config);
  const ambient = ambientRunning(sched);
  const pauseVisible = showsPauseButton(sched, config);

  /* تقليل الحركة — يُتابَع حيًّا لا عند التحميل وحده. */
  useEffect(() => {
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    const sync = () => dispatch(mq.matches ? 'reduce-on' : 'reduce-off');
    sync();
    mq.addEventListener('change', sync);
    return () => mq.removeEventListener('change', sync);
  }, [dispatch]);

  /* التبويب المخفيّ لا يدور ولا يحرّك خلفيّته. */
  useEffect(() => {
    const sync = () => dispatch(document.hidden ? 'hidden' : 'visible');
    sync();
    document.addEventListener('visibilitychange', sync);
    return () => document.removeEventListener('visibilitychange', sync);
  }, [dispatch]);

  /* والهيرو خارج الشاشة كذلك — توفيرًا للمعالج. */
  useEffect(() => {
    const node = rootRef.current;
    if (!node || typeof IntersectionObserver === 'undefined') return undefined;
    const io = new IntersectionObserver(([entry]) => dispatch(entry?.isIntersecting ? 'onscreen' : 'offscreen'));
    io.observe(node);
    return () => io.disconnect();
  }, [dispatch]);

  const slideMode = settings.transition === 'slide';

  /**
   * الانتقال إلى الفهرس الخام `n` (‎index±1‎ أو نقطة أو Home/End): الاتّجاه من
   * الخام قبل الالتفاف، فالأخيرة → الأولى تقدّم. `fromDrag`: الداخلة في موضعها
   * تحت الإصبع أصلًا فلا طور تحضير. والساعة تبدأ من الصفر بعد كلّ انتقال.
   */
  const go = (n: number, { user = true, fromDrag = false }: { user?: boolean; fromDrag?: boolean } = {}) => {
    const target = nextIndex(n, count, 0);
    if (target !== index) {
      const current = rootRef.current?.querySelector('[aria-roledescription="slide"][data-active="true"]');
      refocus.current = Boolean(current?.contains(document.activeElement));
      const staged = slideMode && !fromDrag;
      /* طور التحضير يُرسم متزامنًا (الداخلة في موضع انطلاقها بلا انتقال)، وقراءة
         التخطيط تثبّته، ثمّ تُطلق الحركة — داخل معالج الحدث لا في أثرٍ لاحق. */
      flushSync(() => {
        setLeaving({ i: index, dir: navDirection(n, index) });
        setPrep(staged);
        setIndex(target);
      });
      if (staged) {
        void rootRef.current?.offsetWidth;
        setPrep(false);
      }
    }
    setCycle((c) => c + 1);
    if (user) dispatch('user-nav');
  };

  const advance = () => go(index + 1, { user: false });

  useLayoutEffect(() => {
    if (!refocus.current) return;
    refocus.current = false;
    rootRef.current
      ?.querySelector<HTMLElement>('[aria-roledescription="slide"][data-active="true"]')
      ?.focus({ preventScroll: true });
  }, [index]);

  useEffect(() => {
    if (rootRef.current) setLtr(getComputedStyle(rootRef.current).direction === 'ltr');
  }, []);
  const rtl = () => !ltr;

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    /* الأسهم داخل حقلٍ تحرّك مؤشّره — لا الشرائح. */
    const target = event.target as HTMLElement;
    if (target.closest('input, textarea, select, [contenteditable="true"]')) return;
    const forward = rtl() ? 'ArrowLeft' : 'ArrowRight';
    const back = rtl() ? 'ArrowRight' : 'ArrowLeft';
    let n: number | null = null;
    if (event.key === forward) n = index + 1;
    else if (event.key === back) n = index - 1;
    else if (event.key === 'Home') n = 0;
    else if (event.key === 'End') n = count - 1;
    if (n === null) return;
    event.preventDefault();
    go(n);
  };

  /* ── السحب باللمس (والقلم) — الفأرة تنقر الأسهم والنقاط ── */
  const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
    if (event.pointerType === 'mouse' || count < 2) return;
    drag.current = {
      id: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      active: false,
      width: event.currentTarget.clientWidth,
    };
  };

  const onPointerMove = (event: PointerEvent<HTMLDivElement>) => {
    const d = drag.current;
    if (!d || d.id !== event.pointerId) return;
    const dx = event.clientX - d.x;
    const dy = event.clientY - d.y;
    if (!d.active) {
      if (Math.abs(dy) > DRAG_SLOP_PX && Math.abs(dy) > Math.abs(dx)) {
        drag.current = null; // تمريرٌ عموديّ — للصفحة.
        return;
      }
      if (Math.abs(dx) < DRAG_SLOP_PX) return;
      d.active = true;
      event.currentTarget.setPointerCapture(event.pointerId);
      dispatch('drag-start');
    }
    setDragDx(dx);
  };

  const endDrag = (event: PointerEvent<HTMLDivElement>) => {
    const d = drag.current;
    drag.current = null;
    if (!d || !d.active || d.id !== event.pointerId) return;
    const dx = event.clientX - d.x;
    const threshold = Math.min(SWIPE_MIN_PX * 2, Math.max(SWIPE_MIN_PX, d.width * SWIPE_RATIO));
    /* في RTL التالي على اليسار: السحب يمينًا يجلبه. */
    const toNext = rtl() ? dx > 0 : dx < 0;
    setDragDx(0);
    justDragged.current = true;
    window.setTimeout(() => {
      justDragged.current = false;
    }, 0);
    dispatch('drag-end');
    const step = toNext ? 1 : -1;
    if (Math.abs(dx) >= threshold) go(index + step, { user: false, fromDrag: true });
    /* دون العتبة: الحاضرة تنطبق عائدة، والجارة المكشوفة تنسحب إلى جهتها معها. */
    else setLeaving({ i: nextIndex(index, count, step), dir: toNext ? -1 : 1 });
  };

  if (count === 0) return null;

  /* موضع «التالية» فيزيائيًّا: يسار الحاضرة في RTL، ويمينها في LTR. */
  const nextSide = ltr ? 100 : -100;
  const motion = {
    index,
    leaving,
    prep,
    dragging: dragDx !== 0,
    toNext: ltr ? dragDx < 0 : dragDx > 0,
  };
  const slotStyle = (i: number): { role: string; style?: CSSProperties } => {
    const place = slotPlacement(i, count, motion);
    /* المركونة بلا تحويل: تُزاح بخاصّيّةٍ منطقيّة في CSS (خارج المسرح في جهة «التالية»). */
    if (!slideMode || place.role === 'parked')
      return { role: slideMode ? place.role : i === index ? 'active' : 'parked' };
    const pct = place.offset * nextSide;
    return {
      role: place.role,
      style: {
        transform: place.follows ? `translateX(calc(${pct}% + ${dragDx}px))` : `translateX(${pct}%)`,
      },
    };
  };
  const clock = {
    animationDuration: `${settings.intervalMs}ms`,
  } as CSSProperties;
  const multi = count > 1;

  return (
    <div
      ref={rootRef}
      className={styles.heroRoot}
      role="region"
      aria-roledescription="carousel"
      aria-label={label}
      data-transition={settings.transition}
      data-height={settings.height}
      data-ambient={settings.ambient}
      data-running={running ? 'true' : 'false'}
      data-ambient-running={ambient ? 'true' : 'false'}
      data-many={count > 4 ? 'true' : undefined}
      /* التحويم للفأرة وحدها: النقر باللمس يحاكي «دخولًا» لا خروج له فيُعلّق الدوران. */
      onPointerEnter={(event) => {
        if (event.pointerType === 'mouse') dispatch('hover-start');
      }}
      onPointerLeave={(event) => {
        if (event.pointerType === 'mouse') dispatch('hover-end');
      }}
      onFocus={(event) => {
        setFocusWithin(true);
        /* التركيز على الضوابط لا يوقف أبدًا؛ وداخل شريحةٍ يوقف مع `pauseOnHover` وحده. */
        if ((event.target as HTMLElement).closest('[aria-roledescription="slide"]')) dispatch('focus-in');
      }}
      onBlur={(event) => {
        const next = event.relatedTarget as HTMLElement | null;
        if (!event.currentTarget.contains(next)) setFocusWithin(false);
        if (!next?.closest?.('[aria-roledescription="slide"]') || !event.currentTarget.contains(next)) {
          dispatch('focus-out');
        }
      }}
      onKeyDown={onKeyDown}
    >
      <div
        className={styles.heroStage}
        data-dragging={dragDx !== 0 ? 'true' : undefined}
        data-prep={prep ? 'true' : undefined}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={endDrag}
        onPointerCancel={endDrag}
        onClickCapture={(event) => {
          if (justDragged.current) {
            event.preventDefault();
            event.stopPropagation();
          }
        }}
      >
        <div className={styles.heroTrack} aria-live={running && !focusWithin ? 'off' : 'polite'}>
          {slides.map((slide, i) => {
            const slot = slotStyle(i);
            return (
              <div
                key={slide.id}
                className={styles.heroSlot}
                style={slot.style}
                role="group"
                aria-roledescription="slide"
                aria-label={`${formatNumber(i + 1)} من ${formatNumber(count)}`}
                data-active={i === index ? 'true' : 'false'}
                data-role={slot.role}
                /* انتهت المغادرة ⇒ تُركن (تسقط إزاحتها) حتّى الانتقال التالي. */
                onTransitionEnd={(event) => {
                  if (
                    slot.role === 'leaving' &&
                    event.target === event.currentTarget &&
                    event.propertyName === 'transform'
                  ) {
                    setLeaving(null);
                  }
                }}
                tabIndex={-1}
                inert={i !== index}
              >
                {slide.node}
              </div>
            );
          })}
        </div>
        {multi && pauseVisible ? (
          <span
            key={`clock-${index}-${cycle}`}
            className={styles.heroClock}
            style={clock}
            aria-hidden="true"
            onAnimationEnd={advance}
          />
        ) : null}
      </div>

      {multi && (settings.showArrows || settings.showDots || pauseVisible) ? (
        <div className={styles.heroControls}>
          {settings.showArrows ? (
            <button
              type="button"
              className={styles.heroArrow}
              aria-label="الشريحة السابقة"
              onClick={() => go(index - 1)}
            >
              <DesignIcon name="chevPrev" size={18} strokeWidth={2.2} />
            </button>
          ) : null}
          {settings.showDots ? (
            <div className={styles.heroDots}>
              {slides.map((slide, i) => (
                <button
                  key={slide.id}
                  type="button"
                  className={styles.heroDot}
                  aria-label={`الشريحة ${formatNumber(i + 1)} من ${formatNumber(count)}`}
                  aria-current={i === index ? 'true' : undefined}
                  onClick={() => go(i)}
                >
                  {i === index && settings.showProgress && pauseVisible ? (
                    <span
                      key={`fill-${index}-${cycle}`}
                      className={styles.heroDotFill}
                      style={clock}
                      aria-hidden="true"
                    />
                  ) : null}
                </button>
              ))}
            </div>
          ) : null}
          {settings.showArrows ? (
            <button
              type="button"
              className={styles.heroArrow}
              aria-label="الشريحة التالية"
              onClick={() => go(index + 1)}
            >
              <DesignIcon name="chevNext" size={18} strokeWidth={2.2} />
            </button>
          ) : null}
          {pauseVisible ? (
            <button
              type="button"
              className={styles.heroPause}
              aria-pressed={sched.stopped}
              aria-label={sched.stopped ? 'تشغيل التمرير التلقائي' : 'إيقاف التمرير التلقائي'}
              onClick={() => dispatch('toggle')}
            >
              {sched.stopped ? (
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M8 5.5v13l11-6.5z" />
                </svg>
              ) : (
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                  <rect x="6" y="5" width="4" height="14" rx="1.2" />
                  <rect x="14" y="5" width="4" height="14" rx="1.2" />
                </svg>
              )}
            </button>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
