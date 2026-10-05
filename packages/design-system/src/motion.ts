// UNIT: MOTION
// FILE: motion.ts
// STYLE: none
// PARENT: DESIGN-SYSTEM-INDEX, DIAGNOSTICS-PANEL, PAGE-ANALYTICS
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * @tahdheer/design-system — motion.ts
 * Definitive 0–10 motion scale (MIP §2.4; port of tahdheer-motion-scale.php).
 * One admin number drives six values. Where the VCE audit table disagrees
 * (L10 scale 1.25), motion-scale.php is authoritative (0.86).
 *
 * VERIFIED anchors: L0 · L3 · L5 · L7 · L10.
 * Intermediate levels are DERIVED (linear interpolation between anchors,
 * rounded to sensible steps) and marked as such; re-extract the full 11-row
 * table verbatim from tahdheer-motion-scale.php in Phase 5.
 *
 * REDUCED MOTION — prefers-reduced-motion forces level 0 and overrides every
 * admin setting (MIP §2.4 — binding). Enforcement is NOT in this file: the
 * universal layer is the CSS kill block in tokens.css, and reduced motion must
 * REVEAL hidden content rather than leave it mid-entrance.
 */

export type EasingName = 'linear' | 'ease-out' | 'smooth' | 'spring' | 'soft-spring' | 'custom';

/** Registry-verified easing CSS (first-hand: tahdheer_ds_easing_css). */
export const EASINGS: Record<EasingName, string> = {
  linear: 'linear',
  /* كلمة CSS أصليّة — يستعملها المستويان ١ و٢ في الجدول الأصل. */
  'ease-out': 'ease-out',
  smooth: 'cubic-bezier(.22,.61,.36,1)',
  spring: 'cubic-bezier(.34,1.56,.64,1)',
  'soft-spring': 'cubic-bezier(.16,1,.3,1)',
  custom: 'cubic-bezier(.2,1.4,.3,1)', // custom fallback
};

export type MotionLevel = 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10;

export interface MotionLevelSpec {
  /** Entrance travel distance, px. */
  distance: number;
  /** Entrance duration, ms. */
  duration: number;
  easing: EasingName;
  /** Per-item stagger delay, ms. */
  stagger: number;
  /** Entrance blur, px. */
  blur: number;
  /** Entrance start scale (1.0 = none). */
  scale: number;
}

export const MOTION_SCALE: Record<MotionLevel, MotionLevelSpec> = {
/*
 * الأحد عشر صفًّا منقولة حرفيًّا من الجدول الأصل:
 * `docs/design/matgarr/inc/tahdheer-motion-scale.php:48-58`.
 *
 * وكانت ستّة منها **مشتقّة بالاستيفاء** — كُتب ذلك في تعليقاتها بصراحة
 * («DERIVED (L0→L3 interpolation)») يوم لم يكن الجدول في المستودع. وقد
 * صار فيه، فالاشتقاق اختراعٌ لا مسوّغ له: المقيس بعد المقابلة أنّ L1
 * كانت ‎110ms‎ والأصل ‎200‎، وL2 ‎210‎ والأصل ‎260‎، وL8 وL9 تختلفان في
 * المسافة والمدّة والتتابع والضباب والمقياس والتوقيت معًا.
 */
  0: { distance: 0, duration: 0, easing: 'linear', stagger: 0, blur: 0, scale: 1.0 },
  1: { distance: 4, duration: 200, easing: 'ease-out', stagger: 20, blur: 0, scale: 1.0 },
  2: { distance: 8, duration: 260, easing: 'ease-out', stagger: 30, blur: 0, scale: 1.0 },
  3: { distance: 12, duration: 320, easing: 'smooth', stagger: 40, blur: 0, scale: 1.0 },
  4: { distance: 18, duration: 400, easing: 'smooth', stagger: 50, blur: 2, scale: 1.0 },
  5: { distance: 24, duration: 480, easing: 'smooth', stagger: 60, blur: 4, scale: 1.0 },
  6: { distance: 34, duration: 560, easing: 'soft-spring', stagger: 80, blur: 6, scale: 0.98 },
  7: { distance: 46, duration: 640, easing: 'soft-spring', stagger: 100, blur: 8, scale: 0.96 },
  8: { distance: 62, duration: 760, easing: 'spring', stagger: 120, blur: 12, scale: 0.94 },
  9: { distance: 84, duration: 880, easing: 'spring', stagger: 150, blur: 16, scale: 0.90 },
  10: { distance: 110, duration: 1200, easing: 'custom', stagger: 200, blur: 24, scale: 0.86 },
};

/** Speed presets, ms (registry, first-hand: tahdheer_ds_speeds). */
export const SPEEDS = {
  verySlow: 1400,
  slow: 900,
  normal: 600,
  fast: 360,
  veryFast: 200,
} as const;
