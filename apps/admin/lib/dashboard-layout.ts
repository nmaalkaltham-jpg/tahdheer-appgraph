'use client';
// UNIT: LIB-DASHBOARD-LAYOUT
// FILE: dashboard-layout.ts
// STYLE: none
// PARENT: PAGE-DASHBOARD, WORKSPACE
// USES: LIB-ADMIN-API, LIB-LAYOUT-PREFS
// API: GET /admin/me/preferences, PUT /admin/me/preferences
// MAP: docs/PROJECT_MAP.md

/**
 * مساحة عمل اللوحة — سجلّ البطاقات، والقوالب، ومحرّك الشبكة، والحفظ.
 *
 * ─── لماذا ملفٌّ واحد ───
 *
 * أربعة أشياء تعرف شكل الوثيقة نفسها: السجلّ (ما البطاقات وحدودها)، والمحرّك
 * (أين تقع)، والقوالب (ما يُعرض افتراضًا)، والحفظ (ما يذهب إلى الخادم). تفريقُها
 * في أربعة ملفّات يجعل «أضفتُ حقلًا» تعديلًا في أربعة أماكن يُنسى أحدها.
 *
 * ─── وما لا يمسّه ───
 *
 * البيانات. القالب يضبط الظهور والترتيب والحجم والكثافة — لا مدًى ولا مرشّحًا
 * ولا رقمًا. «تنفيذي» يُخفي طابور التشغيل ولا يغيّر ما يقوله الطابور.
 *
 * ─── والدوالّ خالصة حيث أمكن ───
 *
 * التعبئة والتحريك والتحجيم والأدلّة والتنقية والترحيل دوالّ بلا React ولا
 * متصفّح، يختبرها `lib/__tests__/dashboard-layout.test.mjs` مباشرةً. والخطّافات
 * في الذيل طبقةٌ رقيقة فوقها.
 */

import { useCallback, useSyncExternalStore } from 'react';
import { PREFS_KEY, arrangeItems, readPrefsDocument, subscribePrefs, writePrefsSection } from './layout-prefs.ts';

/* ───── الأبعاد ───── */

export const GRID_COLS = 12;
/** ارتفاع الصفّ ومسافته — الخطوة الرأسيّة = الصفّ + المسافة. */
export const ROW_PX = { comfortable: 24, compact: 20 } as const;
export const GAP_PX = { comfortable: 16, compact: 12 } as const;
/** خطوة المحاذاة في اللوحة الحرّة. */
export const CANVAS_SNAP = 8;
/** مسافة الالتقاط إلى دليل محاذاة، بالبكسل. */
export const GUIDE_THRESHOLD = 6;

export type DashboardMode = 'grid' | 'canvas';
export type DashboardDensity = 'comfortable' | 'compact';
export type DashboardPresetId = 'executive' | 'sales' | 'operations' | 'content' | 'analytics' | 'minimal' | 'custom';

export interface WidgetState {
  x: number;
  y: number;
  w: number;
  h: number;
  order: number;
  hidden: boolean;
  locked: boolean;
  /** نوعٌ غير المعرّف — للنسخ المكرّرة وحدها (`kpi-3` نوعه `kpi`). */
  type?: string;
  settings?: { metric?: string };
}

export interface CanvasRect {
  x: number;
  y: number;
  w: number;
  h: number;
}

export interface DashboardDoc {
  preset: DashboardPresetId;
  mode: DashboardMode;
  density: DashboardDensity;
  snap: boolean;
  widgets: Record<string, WidgetState>;
  canvas: Record<string, CanvasRect>;
  /** آخر تخطيطٍ خاصّ — يُستعاد حين يعود المشغّل إلى «مخصّص» من قالب. */
  custom?: Record<string, WidgetState>;
}

/* ───── سجلّ البطاقات ───── */

export interface WidgetDef {
  type: string;
  title: string;
  minW: number;
  maxW: number;
  minH: number;
  maxH: number;
  /** `true` أو سببُ التعطيل مكتوبًا — الزرّ المعطّل يقول لماذا. */
  duplicable: true | string;
}

const SINGLE = 'بطاقةٌ واحدة تكفي — نسختها الثانية تعرض الأرقام نفسها.';

export const WIDGET_TYPES: Record<string, WidgetDef> = {
  attention: { type: 'attention', title: 'مركز الانتباه', minW: 4, maxW: 12, minH: 6, maxH: 30, duplicable: SINGLE },
  health: { type: 'health', title: 'صحّة الأعمال', minW: 3, maxW: 8, minH: 6, maxH: 24, duplicable: SINGLE },
  hero: { type: 'hero', title: 'المؤشّر الأوّل', minW: 3, maxW: 12, minH: 7, maxH: 16, duplicable: 'المؤشّر الأوّل واحدٌ بطبيعته — كرّر بطاقة مؤشّر بمقياسٍ آخر.' },
  kpi: { type: 'kpi', title: 'مؤشّر', minW: 2, maxW: 6, minH: 5, maxH: 12, duplicable: true },
  'sales-chart': { type: 'sales-chart', title: 'المبيعات والطلبات', minW: 4, maxW: 12, minH: 8, maxH: 24, duplicable: SINGLE },
  lifecycle: { type: 'lifecycle', title: 'دورة حياة الطلب', minW: 3, maxW: 8, minH: 6, maxH: 20, duplicable: SINGLE },
  stream: { type: 'stream', title: 'البثّ المباشر', minW: 3, maxW: 8, minH: 6, maxH: 24, duplicable: SINGLE },
  queue: { type: 'queue', title: 'طابور التشغيل', minW: 5, maxW: 12, minH: 6, maxH: 36, duplicable: SINGLE },
  insights: { type: 'insights', title: 'رؤى مشتقّة', minW: 3, maxW: 12, minH: 4, maxH: 16, duplicable: SINGLE },
  products: { type: 'products', title: 'ذكاء المنتجات', minW: 3, maxW: 8, minH: 6, maxH: 24, duplicable: SINGLE },
  customers: { type: 'customers', title: 'ذكاء العملاء', minW: 3, maxW: 8, minH: 5, maxH: 20, duplicable: SINGLE },
  payments: { type: 'payments', title: 'ذكاء المدفوعات', minW: 3, maxW: 8, minH: 6, maxH: 20, duplicable: SINGLE },
  security: { type: 'security', title: 'الأمان والوصول', minW: 3, maxW: 8, minH: 6, maxH: 20, duplicable: SINGLE },
  unmeasured: { type: 'unmeasured', title: 'ما ليس مُقاسًا', minW: 4, maxW: 12, minH: 2, maxH: 10, duplicable: SINGLE },
  domains: { type: 'domains', title: 'تحليل النطاقات', minW: 6, maxW: 12, minH: 8, maxH: 40, duplicable: SINGLE },
};

/** مقاييس بطاقة «مؤشّر» بترتيب عرضها الافتراضيّ — المفتاح كما يبثّه الخادم. */
export const KPI_METRICS: readonly { metric: string; id: string; label: string }[] = [
  { metric: 'orders', id: 'kpi-orders', label: 'الطلبات المدفوعة' },
  { metric: 'aov', id: 'kpi-aov', label: 'متوسّط قيمة الطلب' },
  { metric: 'sla', id: 'kpi-sla', label: 'الالتزام بمهلة القضايا' },
  { metric: 'refunded', id: 'kpi-refunded', label: 'المستردّ' },
  { metric: 'netSales', id: 'kpi-net-sales', label: 'صافي المبيعات' },
  { metric: 'allOrders', id: 'kpi-all-orders', label: 'كلّ الطلبات' },
  { metric: 'needsAction', id: 'kpi-needs-action', label: 'طلبات تحتاج معالجة' },
  { metric: 'newCustomers', id: 'kpi-new-customers', label: 'عملاء جدد' },
];

interface Instance {
  id: string;
  type: string;
  w: number;
  h: number;
  metric?: string;
}

/**
 * النسخ الافتراضيّة بترتيب «العمليّات» — أي اللوحة كما كانت قبل مساحة العمل.
 * الأحجام قيست على الصفحة القديمة بعرض ١٤٤٠.
 */
const INSTANCES: readonly Instance[] = [
  { id: 'attention', type: 'attention', w: 8, h: 13 },
  { id: 'health', type: 'health', w: 4, h: 13 },
  { id: 'hero', type: 'hero', w: 4, h: 9 },
  /* الأربعة الأولى بعرضين بجوار المؤشّر الأوّل، والأربعة التالية بثلاثة — كما كانت. */
  ...KPI_METRICS.map((k, i): Instance => ({ id: k.id, type: 'kpi', w: i < 4 ? 2 : 3, h: i < 4 ? 9 : 7, metric: k.metric })),
  { id: 'sales-chart', type: 'sales-chart', w: 8, h: 12 },
  { id: 'lifecycle', type: 'lifecycle', w: 4, h: 12 },
  { id: 'queue', type: 'queue', w: 8, h: 19 },
  { id: 'stream', type: 'stream', w: 4, h: 11 },
  { id: 'insights', type: 'insights', w: 4, h: 8 },
  { id: 'products', type: 'products', w: 3, h: 12 },
  { id: 'customers', type: 'customers', w: 3, h: 12 },
  { id: 'payments', type: 'payments', w: 3, h: 12 },
  { id: 'security', type: 'security', w: 3, h: 12 },
  { id: 'unmeasured', type: 'unmeasured', w: 12, h: 3 },
  { id: 'domains', type: 'domains', w: 12, h: 18 },
];

const INSTANCE_BY_ID = new Map(INSTANCES.map((i) => [i.id, i] as const));

export function typeOf(id: string, state?: Pick<WidgetState, 'type'>): string | null {
  if (state?.type !== undefined && state.type in WIDGET_TYPES) return state.type;
  const base = INSTANCE_BY_ID.get(id);
  if (base !== undefined) return base.type;
  return null;
}

export function defOf(id: string, state?: Pick<WidgetState, 'type'>): WidgetDef | null {
  const type = typeOf(id, state);
  return type === null ? null : (WIDGET_TYPES[type] ?? null);
}

/** مقياس بطاقة المؤشّر: من إعدادها، وإلّا من نسختها الافتراضيّة. */
export function metricOf(id: string, state?: WidgetState): string | null {
  return state?.settings?.metric ?? INSTANCE_BY_ID.get(id)?.metric ?? null;
}

/* ───── القوالب ───── */

interface PresetSpec {
  id: DashboardPresetId;
  label: string;
  hint: string;
  density: DashboardDensity;
  /** الظاهر بترتيبه — وما سواه يُخفى. `null` = كلّ البطاقات (القالب الأصليّ). */
  show: readonly string[] | null;
  /** أحجامٌ تخالف الافتراضيّ في هذا القالب. */
  sizes?: Readonly<Record<string, readonly [number, number]>>;
}

const KPI_IDS = KPI_METRICS.map((k) => k.id);

export const DASHBOARD_PRESETS: readonly PresetSpec[] = [
  {
    id: 'executive',
    label: 'تنفيذي',
    hint: 'الأداء أوّلًا — المؤشّرات والمخطّط وما يحتاج قرارًا.',
    density: 'comfortable',
    show: ['hero', 'kpi-net-sales', 'kpi-orders', 'kpi-aov', 'kpi-refunded', 'sales-chart', 'health', 'attention', 'products', 'customers', 'insights'],
    sizes: { hero: [6, 9], 'kpi-net-sales': [3, 9], 'kpi-orders': [3, 9], 'kpi-aov': [3, 9], 'kpi-refunded': [3, 9], 'sales-chart': [8, 12], health: [4, 12], attention: [12, 12], products: [6, 13], customers: [3, 13], insights: [3, 13] },
  },
  {
    id: 'sales',
    label: 'المبيعات',
    hint: 'الإيراد والطلبات والمنتجات الأعلى مبيعًا والبثّ المباشر.',
    density: 'comfortable',
    show: ['hero', 'kpi-orders', 'kpi-aov', 'kpi-net-sales', 'kpi-refunded', 'sales-chart', 'stream', 'products', 'lifecycle', 'payments', 'customers', 'insights'],
    sizes: { hero: [6, 9], 'sales-chart': [8, 12], stream: [4, 12], products: [4, 13], lifecycle: [4, 13], payments: [4, 13], customers: [6, 11], insights: [6, 11] },
  },
  {
    id: 'operations',
    label: 'العمليّات',
    hint: 'الانتباه والطابور أوّلًا — ثمّ كلّ شيء. اللوحة كما بُنيت.',
    density: 'comfortable',
    show: null,
  },
  {
    id: 'content',
    label: 'المحتوى',
    hint: 'المنتجات والنطاقات والرؤى — لمن يدقّق الكتالوج لا الطلبات.',
    density: 'comfortable',
    show: ['products', 'insights', 'kpi-new-customers', 'customers', 'domains', 'unmeasured'],
    sizes: { products: [6, 13], insights: [6, 6], 'kpi-new-customers': [3, 7], customers: [3, 7], domains: [12, 18], unmeasured: [12, 3] },
  },
  {
    id: 'analytics',
    label: 'التحليلات',
    hint: 'كلّ المؤشّرات والمخطّطات والنطاقات — أعلى كثافة.',
    density: 'compact',
    show: ['hero', ...KPI_IDS, 'sales-chart', 'lifecycle', 'customers', 'products', 'insights', 'domains', 'unmeasured'],
    sizes: { hero: [6, 9], 'sales-chart': [8, 12], lifecycle: [4, 12], customers: [4, 13], products: [4, 13], insights: [4, 13] },
  },
  {
    id: 'minimal',
    label: 'بسيط',
    hint: 'ما ينتظر تدخّلًا والرقم الأوّل والمخطّط — لا أكثر.',
    density: 'comfortable',
    show: ['attention', 'hero', 'kpi-orders', 'kpi-refunded', 'sales-chart'],
    sizes: { attention: [12, 12], hero: [6, 9], 'kpi-orders': [3, 9], 'kpi-refunded': [3, 9], 'sales-chart': [12, 12] },
  },
  {
    id: 'custom',
    label: 'مخصّص',
    hint: 'ترتيبك أنت — يُحفظ في حسابك ويتبعك بين الأجهزة.',
    density: 'comfortable',
    show: null,
  },
];

const PRESET_IDS = DASHBOARD_PRESETS.map((p) => p.id);

/* ───── المحرّك: التعبئة بلا تصادم ───── */

interface Box {
  id: string;
  x: number;
  y: number;
  w: number;
  h: number;
}

function collides(a: Box, b: Box): boolean {
  return a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
}

/**
 * تعبئةٌ بالجاذبية: كلّ بطاقةٍ — مرتّبةً بالصفّ ثمّ العمود — تنزل إلى أعلى
 * موضعٍ حرّ في أعمدتها. لا تصادم ممكنٌ بعدها، ولا فجوةٌ رأسيّة تبقى.
 *
 * `priority` تُقدَّم عند التعادل في الصفّ: البطاقة المسحوبة إلى صفٍّ يشغله غيرها
 * تأخذه، ويُدفع الآخر تحتها — وهذا هو معنى «أفلتُّها هنا».
 *
 * خالصةٌ ومتساوية القوّة: تعبئةُ تخطيطٍ معبّأ تُعيده كما هو.
 */
export function pack<T extends Box>(items: readonly T[], cols: number = GRID_COLS, priority?: string): T[] {
  const sorted = [...items].sort((a, b) => {
    if (a.y !== b.y) return a.y - b.y;
    if (priority !== undefined) {
      if (a.id === priority) return -1;
      if (b.id === priority) return 1;
    }
    return a.x - b.x;
  });

  const placed: T[] = [];
  for (const item of sorted) {
    const w = Math.max(1, Math.min(cols, item.w));
    const x = Math.max(0, Math.min(cols - w, item.x));
    let y = 0;
    const probe = { ...item, x, w, y };
    for (;;) {
      probe.y = y;
      const hit = placed.find((p) => collides(p, probe));
      if (hit === undefined) break;
      y = hit.y + hit.h;
    }
    placed.push({ ...item, x, w, y });
  }

  return placed;
}

function visibleBoxes(widgets: Record<string, WidgetState>): (Box & { state: WidgetState })[] {
  return Object.entries(widgets)
    .filter(([, s]) => !s.hidden)
    .map(([id, s]) => ({ id, x: s.x, y: s.y, w: s.w, h: s.h, state: s }));
}

/** يكتب نتيجة التعبئة في الخريطة، ويعيد ترقيم `order` بالترتيب البصريّ. */
function commitBoxes(widgets: Record<string, WidgetState>, boxes: readonly Box[]): Record<string, WidgetState> {
  const next: Record<string, WidgetState> = { ...widgets };
  const ordered = [...boxes].sort((a, b) => a.y - b.y || a.x - b.x);
  ordered.forEach((b, order) => {
    const was = next[b.id];
    if (was !== undefined) next[b.id] = { ...was, x: b.x, y: b.y, w: b.w, h: b.h, order };
  });
  let tail = ordered.length;
  for (const [id, s] of Object.entries(next)) {
    if (s.hidden) next[id] = { ...s, order: tail++ };
  }
  return next;
}

export function packWidgets(widgets: Record<string, WidgetState>, priority?: string): Record<string, WidgetState> {
  return commitBoxes(widgets, pack(visibleBoxes(widgets), GRID_COLS, priority));
}

/** الحجم داخل حدود نوعه وحدود الشبكة. */
export function clampSize(id: string, state: WidgetState, w: number, h: number, cols: number = GRID_COLS): { w: number; h: number } {
  const def = defOf(id, state);
  const minW = Math.min(def?.minW ?? 1, cols);
  const maxW = Math.min(def?.maxW ?? cols, cols);
  return {
    w: Math.max(minW, Math.min(maxW, Math.round(w))),
    h: Math.max(def?.minH ?? 1, Math.min(def?.maxH ?? 48, Math.round(h))),
  };
}

/**
 * نقلُ بطاقةٍ إلى خليّة. المقفلة لا تتحرّك، ولا تُدفع: تُعامَل صخرةً تلتفّ
 * حولها البطاقات. والباقي يُعاد تعبئته بلا تصادم.
 */
export function moveWidget(widgets: Record<string, WidgetState>, id: string, x: number, y: number): Record<string, WidgetState> {
  const was = widgets[id];
  if (was === undefined || was.locked) return widgets;
  const moved = { ...widgets, [id]: { ...was, x: Math.max(0, Math.min(GRID_COLS - was.w, Math.round(x))), y: Math.max(0, Math.round(y)) } };
  return packLocked(moved, id);
}

/**
 * خطوةٌ بلوحة المفاتيح. الأفقيّة عمودٌ واحد؛ والرأسيّة **تبادلٌ مع الجارة**:
 * في شبكةٍ بالجاذبية لا فراغ تنزل إليه البطاقة سطرًا، فـ«أسفل» يعني «بعد
 * البطاقة التي تحتها» و«أعلى» يعني «قبل التي فوقها». بلا جارةٍ في الاتّجاه
 * لا حركة — والإعلان يقول الموضع كما هو.
 */
export function nudgeWidget(widgets: Record<string, WidgetState>, id: string, dx: number, dy: number): Record<string, WidgetState> {
  const s = widgets[id];
  if (s === undefined || s.locked) return widgets;
  if (dy === 0) return moveWidget(widgets, id, s.x + dx, s.y);
  const overlapping = Object.entries(widgets).filter(
    ([other, o]) => other !== id && !o.hidden && o.x < s.x + s.w && s.x < o.x + o.w,
  );
  if (dy > 0) {
    const below = overlapping.filter(([, o]) => o.y >= s.y + s.h).sort(([, a], [, b]) => a.y - b.y)[0];
    return below === undefined ? widgets : moveWidget(widgets, id, s.x, below[1].y + 1);
  }
  const above = overlapping.filter(([, o]) => o.y + o.h <= s.y).sort(([, a], [, b]) => b.y - a.y)[0];
  return above === undefined ? widgets : moveWidget(widgets, id, s.x, above[1].y);
}

export function resizeWidget(widgets: Record<string, WidgetState>, id: string, w: number, h: number): Record<string, WidgetState> {
  const was = widgets[id];
  if (was === undefined || was.locked) return widgets;
  const size = clampSize(id, was, w, h);
  const x = Math.min(was.x, GRID_COLS - size.w);
  return packLocked({ ...widgets, [id]: { ...was, ...size, x } }, id);
}

/**
 * تعبئةٌ تحترم الأقفال: المقفلة توضع أوّلًا في مواضعها، ثمّ البقيّة بالجاذبية
 * حولها. تعبئةٌ لا تعرف القفل كانت ستُزيح «ما قفلتُه» لأنّ جارًا كبر.
 */
function packLocked(widgets: Record<string, WidgetState>, priority?: string): Record<string, WidgetState> {
  const boxes = visibleBoxes(widgets);
  const locked = boxes.filter((b) => b.state.locked);
  const free = boxes.filter((b) => !b.state.locked);
  const placed: Box[] = [...locked.map(({ id, x, y, w, h }) => ({ id, x, y, w, h }))];
  const sorted = [...free].sort((a, b) => {
    if (a.y !== b.y) return a.y - b.y;
    if (priority !== undefined) {
      if (a.id === priority) return -1;
      if (b.id === priority) return 1;
    }
    return a.x - b.x;
  });
  for (const item of sorted) {
    const w = Math.max(1, Math.min(GRID_COLS, item.w));
    const x = Math.max(0, Math.min(GRID_COLS - w, item.x));
    const probe: Box = { id: item.id, x, w, h: item.h, y: 0 };
    for (;;) {
      const hit = placed.find((p) => collides(p, probe));
      if (hit === undefined) break;
      probe.y = hit.y + hit.h;
    }
    placed.push(probe);
  }
  return commitBoxes(widgets, placed);
}

/**
 * الملء الأوّل: كلّ بطاقةٍ — بترتيبها — في أعلى ثمّ أقرب موضعٍ تتّسع له.
 *
 * هو ما يبني تخطيط القالب والترحيل والشاشات الضيّقة. ومزيّته على «صفًّا صفًّا»
 * أنّ بطاقةً قصيرة تقع تحت جارتها القصيرة بدل أن تفتح صفًّا تحت الطويلة —
 * فلا يبقى عمودٌ فارغ بجوار طابورٍ طويل.
 */
export function flow(items: readonly { id: string; w: number; h: number }[], cols: number = GRID_COLS): Box[] {
  const placed: Box[] = [];
  for (const item of items) {
    const w = Math.max(1, Math.min(cols, item.w));
    const probe: Box = { id: item.id, x: 0, y: 0, w, h: item.h };
    search: for (let y = 0; ; y += 1) {
      for (let x = 0; x <= cols - w; x += 1) {
        probe.x = x;
        probe.y = y;
        if (!placed.some((p) => collides(p, probe))) break search;
      }
    }
    placed.push({ ...probe });
  }
  return placed;
}

/**
 * التخطيط لعددٍ أقلّ من الأعمدة (شاشةٌ أضيق) — مشتقٌّ لا محفوظ.
 *
 * الترتيب البصريّ نفسه، والعرض يتناسب مع الأعمدة. وعمودٌ واحد يعني تكديسًا
 * بالترتيب: ما كان يمين الصفّ الأوّل يأتي أوّلًا.
 */
export function layoutForCols(widgets: Record<string, WidgetState>, cols: number): Box[] {
  const boxes = visibleBoxes(widgets).sort((a, b) => a.state.order - b.state.order);
  if (cols >= GRID_COLS) return boxes.map(({ id, x, y, w, h }) => ({ id, x, y, w, h }));
  return flow(
    boxes.map((b) => ({ id: b.id, h: b.h, w: cols === 1 ? 1 : Math.max(1, Math.min(cols, Math.round((b.w * cols) / GRID_COLS))) })),
    cols,
  );
}

/** أدنى عمقٍ تحتاجه الشبكة (بالصفوف) — لرسم التراكب وحجز الارتفاع. */
export function gridDepth(boxes: readonly Box[]): number {
  return boxes.reduce((max, b) => Math.max(max, b.y + b.h), 0);
}

/* ───── اللوحة الحرّة: الالتقاط والأدلّة ───── */

export function snapTo(value: number, step: number = CANVAS_SNAP): number {
  return Math.round(value / step) * step;
}

export interface Guides {
  /** مواضع الأدلّة الرأسيّة (بالإحداثيّ المنطقيّ `x`). */
  x: number[];
  /** مواضع الأدلّة الأفقيّة. */
  y: number[];
  rect: CanvasRect;
}

/**
 * أدلّة المحاذاة: الحافّتان والوسط للمسحوبة مقابل الحافّتين والوسط لكلّ
 * بطاقةٍ أخرى. ما وقع ضمن العتبة يُلتقط إليه ويُرسم دليلُه — فالمحاذاة تحدث
 * بلا شبكةٍ ظاهرة، وتُرى حين تحدث.
 */
export function alignmentGuides(
  rect: CanvasRect,
  others: readonly CanvasRect[],
  threshold: number = GUIDE_THRESHOLD,
  /* مع الالتقاط الأوساطُ لا تُعتمد: وسطُ بطاقةٍ عرضُها ٣٦٠ يقع على ١٨٠ + ٤ فيكسر شبكة الثمانية. الحوافّ كلّها على الشبكة. */
  centers: boolean = true,
): Guides {
  const out = { ...rect };
  const guidesX: number[] = [];
  const guidesY: number[] = [];

  const axis = (start: number, size: number, targets: number[]): { delta: number; at: number } | null => {
    const mine = centers ? [start, start + size / 2, start + size] : [start, start + size];
    let best: { delta: number; at: number } | null = null;
    for (const m of mine) {
      for (const t of targets) {
        const delta = t - m;
        if (Math.abs(delta) <= threshold && (best === null || Math.abs(delta) < Math.abs(best.delta))) best = { delta, at: t };
      }
    }
    return best;
  };

  const xs = others.flatMap((o) => (centers ? [o.x, o.x + o.w / 2, o.x + o.w] : [o.x, o.x + o.w]));
  const ys = others.flatMap((o) => (centers ? [o.y, o.y + o.h / 2, o.y + o.h] : [o.y, o.y + o.h]));
  const bx = axis(rect.x, rect.w, xs);
  const by = axis(rect.y, rect.h, ys);
  if (bx !== null) {
    out.x = Math.round(rect.x + bx.delta);
    guidesX.push(bx.at);
  }
  if (by !== null) {
    out.y = Math.round(rect.y + by.delta);
    guidesY.push(by.at);
  }
  return { x: guidesX, y: guidesY, rect: out };
}

/** مستطيلات اللوحة الحرّة من الشبكة — نقطة البدء لمن يبدّل النمط أوّل مرّة. */
export function canvasFromGrid(
  widgets: Record<string, WidgetState>,
  colStep: number,
  rowStep: number,
  gap: number,
): Record<string, CanvasRect> {
  const out: Record<string, CanvasRect> = {};
  for (const [id, s] of Object.entries(widgets)) {
    out[id] = {
      x: snapTo(s.x * colStep),
      y: snapTo(s.y * rowStep),
      w: Math.max(40, snapTo(s.w * colStep - gap)),
      h: Math.max(40, snapTo(s.h * rowStep - gap)),
    };
  }
  return out;
}

/* ───── بناء الوثيقة ───── */

function baseState(inst: Instance, order: number): WidgetState {
  return { x: 0, y: order * 100, w: inst.w, h: inst.h, order, hidden: false, locked: false };
}

/** تخطيط قالب: الظاهر بترتيبه وأحجامه، ثمّ المخفيّ — معبّأً. */
export function presetWidgets(presetId: DashboardPresetId, extras: Record<string, WidgetState> = {}): Record<string, WidgetState> {
  const spec = DASHBOARD_PRESETS.find((p) => p.id === presetId) ?? DASHBOARD_PRESETS[2];
  const show = spec?.show ?? INSTANCES.map((i) => i.id);
  const widgets: Record<string, WidgetState> = {};

  const ordered = show.flatMap((id) => {
    const inst = INSTANCE_BY_ID.get(id);
    if (inst === undefined) return [];
    const [w, h] = spec?.sizes?.[id] ?? [inst.w, inst.h];
    return [{ id, w, h, inst }];
  });
  flow(ordered).forEach((box, order) => {
    const inst = INSTANCE_BY_ID.get(box.id);
    if (inst !== undefined) widgets[box.id] = { ...baseState(inst, order), x: box.x, y: box.y, w: box.w, h: box.h };
  });

  let tail = show.length;
  for (const inst of INSTANCES) {
    if (!(inst.id in widgets)) widgets[inst.id] = { ...baseState(inst, tail++), hidden: true };
  }
  /* النسخ المكرّرة لا تُمحى بتبديل القالب — تُخفى، وتعود بإظهارها. */
  for (const [id, s] of Object.entries(extras)) {
    if (!(id in widgets)) widgets[id] = { ...s, hidden: true, order: tail++ };
  }

  return packWidgets(widgets);
}

export function defaultDoc(): DashboardDoc {
  return { preset: 'operations', mode: 'grid', density: 'comfortable', snap: true, widgets: presetWidgets('operations'), canvas: {} };
}

/* ───── التنقية: ما يُقرأ من التخزين أو الخادم ───── */

const ID = /^[a-z][a-z0-9-]{0,47}$/;
const METRIC = /^[a-zA-Z][a-zA-Z0-9_]{0,39}$/;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function int(value: unknown, min: number, max: number): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value >= min && value <= max ? value : null;
}

function cleanWidgets(raw: unknown): Record<string, WidgetState> | null {
  if (!isRecord(raw)) return null;
  const out: Record<string, WidgetState> = {};
  for (const [id, value] of Object.entries(raw)) {
    if (!ID.test(id) || !isRecord(value)) continue;
    const type = typeof value.type === 'string' && value.type in WIDGET_TYPES ? value.type : undefined;
    /* معرّفٌ لم يعد في السجلّ (بطاقةٌ أُزيلت) يُسقط — لا بطاقةً فارغة تُرسم. */
    if (typeOf(id, { type }) === null) continue;
    const base = INSTANCE_BY_ID.get(id);
    const state: WidgetState = {
      x: int(value.x, 0, GRID_COLS - 1) ?? 0,
      y: int(value.y, 0, 999) ?? 999,
      w: int(value.w, 1, GRID_COLS) ?? base?.w ?? 3,
      h: int(value.h, 1, 48) ?? base?.h ?? 8,
      order: int(value.order, 0, 999) ?? 999,
      hidden: value.hidden === true,
      locked: value.locked === true,
    };
    if (type !== undefined && base === undefined) state.type = type;
    if (isRecord(value.settings) && typeof value.settings.metric === 'string' && METRIC.test(value.settings.metric)) {
      state.settings = { metric: value.settings.metric };
    }
    const size = clampSize(id, state, state.w, state.h);
    out[id] = { ...state, ...size, x: Math.min(state.x, GRID_COLS - size.w) };
  }
  return out;
}

function cleanCanvas(raw: unknown): Record<string, CanvasRect> {
  if (!isRecord(raw)) return {};
  const out: Record<string, CanvasRect> = {};
  for (const [id, value] of Object.entries(raw)) {
    if (!ID.test(id) || !isRecord(value)) continue;
    const x = int(value.x, 0, 8000);
    const y = int(value.y, 0, 20000);
    const w = int(value.w, 40, 8000);
    const h = int(value.h, 40, 8000);
    if (x !== null && y !== null && w !== null && h !== null) out[id] = { x, y, w, h };
  }
  return out;
}

/**
 * تحقّقٌ لا تأكيد: الوثيقة يملكها المستعمل ويحرّرها من أدوات المتصفّح، والخادم
 * قد يكون أقدم أو أحدث من الواجهة. وما يُفقد من البطاقات (أُضيفت بعد الحفظ)
 * يُلحق ظاهرًا في آخر الشبكة — ميزةٌ جديدة يجب أن تُرى.
 */
export function sanitizeDoc(raw: unknown): DashboardDoc | null {
  if (!isRecord(raw)) return null;
  const widgets = cleanWidgets(raw.widgets);
  if (widgets === null) return null;

  let tail = 1000;
  for (const inst of INSTANCES) {
    if (!(inst.id in widgets)) widgets[inst.id] = { ...baseState(inst, tail), y: tail++ };
  }

  const custom = cleanWidgets(raw.custom);
  const doc: DashboardDoc = {
    preset: PRESET_IDS.includes(raw.preset as DashboardPresetId) ? (raw.preset as DashboardPresetId) : 'custom',
    mode: raw.mode === 'canvas' ? 'canvas' : 'grid',
    density: raw.density === 'compact' ? 'compact' : 'comfortable',
    snap: raw.snap !== false,
    widgets: packLocked(widgets),
    canvas: cleanCanvas(raw.canvas),
  };
  if (custom !== null && Object.keys(custom).length > 0) doc.custom = custom;
  return doc;
}

/**
 * ترحيل التفضيل القديم: `admin.layout.prefs.v1` بنطاق `dashboard` (إخفاءٌ
 * وترتيب) إلى وثيقةٍ كاملة. من خصّص لوحته قبل مساحة العمل يجدها كما تركها.
 */
export function migrateLegacy(raw: string | null): DashboardDoc | null {
  if (raw === null) return null;
  try {
    const parsed: unknown = JSON.parse(raw);
    if (!isRecord(parsed) || !isRecord(parsed.dashboard)) return null;
    const scope = parsed.dashboard;
    const hidden = Array.isArray(scope.hidden) ? scope.hidden.filter((v): v is string => typeof v === 'string') : [];
    const order = Array.isArray(scope.order) ? scope.order.filter((v): v is string => typeof v === 'string') : [];
    if (hidden.length === 0 && order.length === 0) return null;

    const doc = defaultDoc();
    /* قاعدة الترتيب نفسها التي تحكم التبويبات والشريط — لا نسخةٌ ثانية منها. */
    const ids = arrangeItems(
      Object.keys(doc.widgets).sort((x, y) => (doc.widgets[x]?.order ?? 0) - (doc.widgets[y]?.order ?? 0)),
      (id) => id,
      { order },
    );
    const visible = ids.filter((id) => !hidden.includes(id));
    const widgets: Record<string, WidgetState> = {};
    const placed = flow(visible.map((id) => ({ id, w: doc.widgets[id]?.w ?? 3, h: doc.widgets[id]?.h ?? 8 })));
    for (const box of placed) {
      const s = doc.widgets[box.id];
      if (s !== undefined) widgets[box.id] = { ...s, x: box.x, y: box.y };
    }
    for (const id of ids) {
      const s = doc.widgets[id];
      if (s !== undefined && !(id in widgets)) widgets[id] = { ...s, hidden: true };
    }
    return { ...doc, preset: 'custom', widgets: packWidgets(widgets) };
  } catch {
    return null;
  }
}

/* ───── عمليّاتٌ على الوثيقة ───── */

/** كلّ تعديلٍ يدويّ يجعل التخطيط «مخصّصًا» — القالب لم يعد يصفه. */
function customized(doc: DashboardDoc, widgets: Record<string, WidgetState>): DashboardDoc {
  return { ...doc, preset: 'custom', widgets };
}

export function applyPreset(doc: DashboardDoc, presetId: DashboardPresetId): DashboardDoc {
  const extras = Object.fromEntries(Object.entries(doc.widgets).filter(([id]) => !INSTANCE_BY_ID.has(id)));
  const stash = doc.preset === 'custom' ? doc.widgets : doc.custom;
  if (presetId === 'custom') {
    return { ...doc, preset: 'custom', widgets: stash ?? doc.widgets };
  }
  const spec = DASHBOARD_PRESETS.find((p) => p.id === presetId);
  const next: DashboardDoc = { ...doc, preset: presetId, density: spec?.density ?? doc.density, widgets: presetWidgets(presetId, extras) };
  if (stash !== undefined) next.custom = stash;
  return next;
}

export function setHidden(doc: DashboardDoc, id: string, hidden: boolean): DashboardDoc {
  const was = doc.widgets[id];
  if (was === undefined) return doc;
  const widgets = { ...doc.widgets, [id]: { ...was, hidden, y: hidden ? was.y : 999 } };
  return customized(doc, packLocked(widgets));
}

export function setLocked(doc: DashboardDoc, id: string, locked: boolean): DashboardDoc {
  const was = doc.widgets[id];
  if (was === undefined) return doc;
  return { ...doc, widgets: { ...doc.widgets, [id]: { ...was, locked } } };
}

export function setMetric(doc: DashboardDoc, id: string, metric: string): DashboardDoc {
  const was = doc.widgets[id];
  if (was === undefined || !METRIC.test(metric)) return doc;
  return customized(doc, { ...doc.widgets, [id]: { ...was, settings: { ...was.settings, metric } } });
}

/** سبب تعطيل النسخ — `null` يعني أنّه ممكن. */
export function duplicateBlocker(doc: DashboardDoc, id: string, availableMetrics: readonly string[]): string | null {
  const state = doc.widgets[id];
  const def = defOf(id, state);
  if (def === null) return 'بطاقةٌ مجهولة.';
  if (def.duplicable !== true) return def.duplicable;
  if (Object.keys(doc.widgets).length >= 64) return 'بلغت اللوحة حدّها من البطاقات (٦٤).';
  if (nextMetric(doc, availableMetrics) === null) return 'كلّ مقياسٍ متاح معروضٌ في بطاقةٍ ظاهرة.';
  return null;
}

/** أوّل مقياسٍ متاح لا تعرضه بطاقةٌ ظاهرة — النسخة تُظهر شيئًا جديدًا. */
function nextMetric(doc: DashboardDoc, available: readonly string[]): string | null {
  const shown = new Set(
    Object.entries(doc.widgets)
      .filter(([id, s]) => !s.hidden && typeOf(id, s) === 'kpi')
      .map(([id, s]) => metricOf(id, s)),
  );
  return available.find((m) => !shown.has(m)) ?? null;
}

export function duplicateWidget(doc: DashboardDoc, id: string, availableMetrics: readonly string[]): { doc: DashboardDoc; id: string } | null {
  if (duplicateBlocker(doc, id, availableMetrics) !== null) return null;
  const was = doc.widgets[id];
  const metric = nextMetric(doc, availableMetrics);
  if (was === undefined || metric === null) return null;
  let n = 2;
  while (`kpi-${n}` in doc.widgets) n += 1;
  const newId = `kpi-${n}`;
  const copy: WidgetState = { ...was, type: 'kpi', settings: { metric }, locked: false, hidden: false, x: was.x + was.w, y: was.y };
  const widgets = moveWidget({ ...doc.widgets, [newId]: copy }, newId, copy.x >= GRID_COLS ? 0 : copy.x, copy.x >= GRID_COLS ? was.y + was.h : was.y);
  return { doc: customized(doc, widgets), id: newId };
}

/**
 * مقياسٌ جديد من الخادم — لا تعرفه القائمة ولا تعرضه بطاقة — يصير بطاقةً
 * ظاهرة في آخر الشبكة. يُعيد الوثيقة نفسها إن لم يكن جديد (فلا تصيير).
 */
export function ensureMetricWidgets(doc: DashboardDoc, metrics: readonly string[]): DashboardDoc {
  const known = new Set(KPI_METRICS.map((k) => k.metric));
  const covered = new Set(Object.entries(doc.widgets).map(([id, s]) => metricOf(id, s)));
  const fresh = metrics.filter((m) => METRIC.test(m) && !known.has(m) && !covered.has(m));
  if (fresh.length === 0) return doc;
  const widgets = { ...doc.widgets };
  let n = 2;
  for (const metric of fresh) {
    while (`kpi-${n}` in widgets) n += 1;
    widgets[`kpi-${n}`] = { x: 0, y: 999, w: 3, h: 7, order: 999, hidden: false, locked: false, type: 'kpi', settings: { metric } };
  }
  return { ...doc, widgets: packLocked(widgets) };
}

/** إعادة بطاقةٍ واحدة إلى حجم قالبها — وموضعُها يُعبّأ من جديد. */
export function resetWidget(doc: DashboardDoc, id: string): DashboardDoc {
  const was = doc.widgets[id];
  if (was === undefined) return doc;
  const inst = INSTANCE_BY_ID.get(id);
  const def = defOf(id, was);
  const w = inst?.w ?? def?.minW ?? 3;
  const h = inst?.h ?? def?.minH ?? 8;
  const canvas = { ...doc.canvas };
  delete canvas[id];
  return { ...customized(doc, packLocked({ ...doc.widgets, [id]: { ...was, w, h, locked: false } })), canvas };
}

/* ───── التاريخ: تراجعٌ وإعادة ───── */

export interface History {
  past: DashboardDoc[];
  future: DashboardDoc[];
}

const HISTORY_LIMIT = 50;

export function pushHistory(history: History, previous: DashboardDoc): History {
  return { past: [...history.past, previous].slice(-HISTORY_LIMIT), future: [] };
}

export function undoHistory(history: History, current: DashboardDoc): { history: History; doc: DashboardDoc } | null {
  const previous = history.past.at(-1);
  if (previous === undefined) return null;
  return { history: { past: history.past.slice(0, -1), future: [current, ...history.future] }, doc: previous };
}

export function redoHistory(history: History, current: DashboardDoc): { history: History; doc: DashboardDoc } | null {
  const next = history.future[0];
  if (next === undefined) return null;
  return { history: { past: [...history.past, current], future: history.future.slice(1) }, doc: next };
}

/* ───── المخزن: ذاكرةٌ + وثيقةٌ محلّيّة + خادم ───── */

/*
 * النسخة المحلّيّة هي قسم `dashboard` من وثيقة التفضيلات (`admin.prefs.v1`)
 * التي تملكها شريحة المظهر — وثيقةٌ واحدة لا اثنتان (عقد §3). ومزامنةُ المظهر
 * ترسل الوثيقة كلّها، فبقاءُ القسم هنا محدَّثًا هو ما يمنعها من إرسال تخطيطٍ
 * قديم. وما يخصّ الحفظ وحده (النسخة، وهل بقي ما لم يصل) في مفتاحٍ صغير مستقلّ.
 */
const SYNC_KEY = 'admin.dashboard.sync';
const LEGACY_KEY = 'admin.layout.prefs.v1';
const SAVE_DELAY_MS = 700;

export type SyncStatus = 'idle' | 'saving' | 'saved' | 'offline' | 'error';

interface StoreState {
  doc: DashboardDoc;
  history: History;
  sync: SyncStatus;
  /** هل وصلت وثيقة الخادم؟ قبلها يعمل المخزن محلّيًّا. */
  loaded: boolean;
}

let state: StoreState | null = null;
let serverVersion: number | null = null;
let dirty = false;
let lastWritten = '';
let saveTimer: ReturnType<typeof setTimeout> | null = null;
let syncStarted = false;
const listeners = new Set<() => void>();

const SERVER_STATE: StoreState = { doc: defaultDoc(), history: { past: [], future: [] }, sync: 'idle', loaded: false };

function emit(): void {
  for (const listener of listeners) listener();
}

function readLocal(): DashboardDoc | null {
  return sanitizeDoc(readPrefsDocument().dashboard);
}

function writeLocal(): void {
  if (state === null) return;
  lastWritten = JSON.stringify(state.doc);
  writePrefsSection('dashboard', state.doc as unknown as Record<string, unknown>);
  try {
    window.localStorage.setItem(SYNC_KEY, JSON.stringify({ version: serverVersion, dirty }));
  } catch {
    // التخزين رفاهية — الوثيقة تعيش في الذاكرة والخادم.
  }
}

function readSync(): void {
  try {
    const raw = window.localStorage.getItem(SYNC_KEY);
    const parsed: unknown = raw === null ? null : JSON.parse(raw);
    if (isRecord(parsed)) {
      serverVersion = typeof parsed.version === 'number' ? parsed.version : null;
      dirty = parsed.dirty === true;
    }
  } catch {
    // لا شيء محفوظ — يُسأل الخادم.
  }
}

function current(): StoreState {
  if (state !== null) return state;
  let doc: DashboardDoc | null = null;
  if (typeof window !== 'undefined') {
    readSync();
    doc = readLocal();
    if (doc === null) {
      try {
        doc = migrateLegacy(window.localStorage.getItem(LEGACY_KEY));
        if (doc !== null) dirty = true;
      } catch {
        doc = null;
      }
    }
  }
  state = { doc: doc ?? defaultDoc(), history: { past: [], future: [] }, sync: 'idle', loaded: false };
  lastWritten = JSON.stringify(state.doc);
  return state;
}

function set(next: Partial<StoreState>): void {
  state = { ...current(), ...next };
  emit();
}

interface PreferencesPayload {
  data: { dashboard?: unknown; appearance?: unknown };
  meta: { version: number };
}

async function put(): Promise<'ok' | 'conflict' | 'offline' | 'error'> {
  if (state === null) return 'error';
  const { api } = await import('./admin-api');
  const body: Record<string, unknown> = { dashboard: state.doc };
  if (serverVersion !== null) body.version = serverVersion;
  const result = await api.put<PreferencesPayload>('/admin/me/preferences', body);
  if (result.ok) {
    serverVersion = result.data.meta.version;
    return 'ok';
  }
  if (result.status === 409) {
    const payload = result.body as Partial<PreferencesPayload> | null;
    if (typeof payload?.meta?.version === 'number') serverVersion = payload.meta.version;
    return 'conflict';
  }
  return result.status === 0 ? 'offline' : 'error';
}

/**
 * الحفظ: القسم كاملًا بنسخته. التعارض (تبويبٌ آخر كتب، أو مزامنة المظهر)
 * يُحلّ بإعادة الكتابة فوق النسخة الجديدة مرّةً واحدة — الوثيقة لمالكٍ واحد،
 * وآخرُ ما رتّبه هنا هو ما يراه هنا. والمظهر لا يُمسّ: القسم وحده يُرسل.
 */
async function flush(): Promise<void> {
  if (state === null) return;
  set({ sync: 'saving' });
  let outcome = await put();
  if (outcome === 'conflict') outcome = await put();
  dirty = outcome !== 'ok';
  writeLocal();
  set({ sync: outcome === 'ok' ? 'saved' : outcome === 'offline' ? 'offline' : 'error' });
}

function scheduleSave(): void {
  dirty = true;
  writeLocal();
  if (saveTimer !== null) clearTimeout(saveTimer);
  saveTimer = setTimeout(() => {
    saveTimer = null;
    if (state?.loaded === true) void flush();
  }, SAVE_DELAY_MS);
}

/** أوّل قراءةٍ من الخادم، ومصالحتها مع المحلّيّ. مرّةً لكلّ صفحة. */
async function startSync(): Promise<void> {
  if (syncStarted || typeof window === 'undefined') return;
  syncStarted = true;
  current();
  const { api } = await import('./admin-api');
  const result = await api.get<PreferencesPayload>('/admin/me/preferences');
  if (!result.ok || !isRecord(result.data?.data)) {
    set({ loaded: true, sync: !result.ok && result.status === 0 ? 'offline' : 'error' });
    return;
  }
  serverVersion = result.data.meta.version;
  const remote = sanitizeDoc(result.data.data.dashboard);
  if (dirty || remote === null) {
    /* تعديلٌ محلّيّ لم يصل، أو خادمٌ بلا وثيقة: المحلّيّ يُرفع. */
    set({ loaded: true });
    await flush();
    return;
  }
  set({ doc: remote, loaded: true, sync: 'saved' });
  writeLocal();
}

/**
 * تغيّرٌ في الوثيقة المحلّيّة لم يأتِ من هنا: تبويبٌ آخر، أو مزامنة المظهر
 * طبّقت وثيقة الخادم، أو لوحة المظهر اختارت قالبًا. القالب وحده تغيّر؟ يُطبَّق
 * تخطيطه — الاختيار من هناك يعني ما يعنيه من هنا. وتعديلٌ هنا لم يصل بعد
 * (`dirty`) لا يُمحى بنسخةٍ أقدم.
 */
function onExternal(): void {
  if (state === null || dirty) return;
  const raw = readPrefsDocument().dashboard;
  if (raw === undefined || JSON.stringify(raw) === lastWritten) return;
  const incoming = sanitizeDoc(raw);
  if (incoming === null) return;
  const was = state.doc;
  const onlyPreset = incoming.preset !== was.preset && JSON.stringify(incoming.widgets) === JSON.stringify(was.widgets);
  const next = onlyPreset ? applyPreset(was, incoming.preset) : incoming;
  set({ doc: next, history: pushHistory(state.history, was) });
  if (onlyPreset) scheduleSave();
  else lastWritten = JSON.stringify(raw);
}

function subscribe(onChange: () => void): () => void {
  listeners.add(onChange);
  void startSync();
  const unsubscribePrefs = subscribePrefs(onExternal);
  const onStorage = (event: StorageEvent) => {
    if (event.key === PREFS_KEY) onExternal();
  };
  const onOnline = () => {
    if (dirty) void flush();
  };
  window.addEventListener('storage', onStorage);
  window.addEventListener('online', onOnline);
  return () => {
    listeners.delete(onChange);
    unsubscribePrefs();
    window.removeEventListener('storage', onStorage);
    window.removeEventListener('online', onOnline);
  };
}

function serverSnapshot(): StoreState {
  return SERVER_STATE;
}

export interface DashboardLayoutApi {
  doc: DashboardDoc;
  sync: SyncStatus;
  loaded: boolean;
  canUndo: boolean;
  canRedo: boolean;
  /** يستبدل الوثيقة. `record: false` للمعاينة أثناء السحب — لا تاريخ ولا حفظ. */
  update: (mutate: (doc: DashboardDoc) => DashboardDoc, options?: { record?: boolean }) => void;
  undo: () => void;
  redo: () => void;
  reset: () => void;
}

export function useDashboardLayout(): DashboardLayoutApi {
  const snap = useSyncExternalStore(subscribe, current, serverSnapshot);

  const update = useCallback((mutate: (doc: DashboardDoc) => DashboardDoc, options?: { record?: boolean }) => {
    const was = current();
    const next = mutate(was.doc);
    if (next === was.doc) return;
    const record = options?.record !== false;
    set({ doc: next, history: record ? pushHistory(was.history, was.doc) : was.history });
    if (record) scheduleSave();
  }, []);

  const undo = useCallback(() => {
    const was = current();
    const step = undoHistory(was.history, was.doc);
    if (step === null) return;
    set({ doc: step.doc, history: step.history });
    scheduleSave();
  }, []);

  const redo = useCallback(() => {
    const was = current();
    const step = redoHistory(was.history, was.doc);
    if (step === null) return;
    set({ doc: step.doc, history: step.history });
    scheduleSave();
  }, []);

  const reset = useCallback(() => {
    update(() => defaultDoc());
  }, [update]);

  return {
    doc: snap.doc,
    sync: snap.sync,
    loaded: snap.loaded,
    canUndo: snap.history.past.length > 0,
    canRedo: snap.history.future.length > 0,
    update,
    undo,
    redo,
    reset,
  };
}

/**
 * القالب المختار — لشريحة المظهر ولشريط التحرير معًا، من مخزنٍ واحد.
 * اختيار القالب خطوةٌ في التاريخ: يُتراجع عنه كما يُتراجع عن سحب.
 */
export function useDashboardPreset(): {
  preset: DashboardPresetId;
  presets: typeof DASHBOARD_PRESETS;
  setPreset: (id: DashboardPresetId) => void;
} {
  const { doc, update } = useDashboardLayout();
  const setPreset = useCallback((id: DashboardPresetId) => update((d) => applyPreset(d, id)), [update]);
  return { preset: doc.preset, presets: DASHBOARD_PRESETS, setPreset };
}
