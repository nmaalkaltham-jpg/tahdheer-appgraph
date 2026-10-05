'use client';
// UNIT: LIB-LAYOUT-PREFS
// FILE: layout-prefs.ts
// STYLE: none
// PARENT: ADMIN-SHELL, PAGE-DASHBOARD, PAGE-HEAD, PAGE-TABS, SIDEBAR
// USES: LIB-APPEARANCE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * تفضيلات التخطيط — ما يُخفيه المشغّل وما يعيد ترتيبه.
 *
 * ─── لماذا مخزنٌ واحد لا حالةٌ في كلّ شريط ───
 *
 * الإخفاء وإعادة الترتيب يقعان في موضعين: تبويبات الصفحة وشريط الملاحة.
 * ونسختان من المنطق تعنيان اختلافًا في التفاصيل التي تهمّ — أيّهما يُحفظ،
 * وماذا يحدث لعنصرٍ جديد لم يكن في الترتيب المحفوظ، ومتى يُعاد الضبط.
 *
 * ─── ولماذا مخزنٌ خارجيّ بباعثٍ خاصّ ───
 *
 * `useStoredItem` يستمع إلى حدث `storage` وحده، وهو **لا يُطلق في التبويب
 * الذي كتب**. فالكتابة ثمّ القراءة في الجلسة نفسها لا تُعيد التصيير — ولذلك
 * تحمل المكوّنات القائمة «تجاوزًا» في حالتها المحلّيّة إلى جانب المخزَّن.
 * هذا المخزن يبثّ لنفسه، فيكفي مصدرٌ واحد.
 *
 * ─── وما لا يفعله ───
 *
 * لا يُخفي شيئًا عن الخادم: الإخفاء **بصريّ** لا أمنيّ. الصلاحيات تُفرض في
 * `AdminAuthorize`، وما يُخفيه المشغّل لنفسه يبقى مفتوحًا برابطه المباشر.
 * الخلط بينهما يجعل «أخفيتُه» تُقرأ «مُنع» — وهما جوابان مختلفان تمامًا.
 */

import { useCallback, useMemo, useSyncExternalStore } from 'react';
import {
  DEFAULT_APPEARANCE,
  PREFS_KEY,
  appearanceAttributes,
  parseAppearance,
  parseDocument,
  type AdminEffects,
  type AppearanceCore,
  type PrefsDocument,
} from './appearance.ts';

export * from './appearance.ts';

const KEY = 'admin.layout.prefs.v1';

/** تفضيلُ نطاقٍ واحد: ما أُخفي، وبأيّ ترتيب يُعرض الباقي. */
interface ScopePrefs {
  hidden: string[];
  order: string[];
}

type Prefs = Record<string, ScopePrefs>;

const EMPTY_SCOPE: ScopePrefs = { hidden: [], order: [] };

let cache: Prefs | null = null;
const listeners = new Set<() => void>();

function isScope(value: unknown): value is ScopePrefs {
  if (typeof value !== 'object' || value === null) return false;
  const record = value as Record<string, unknown>;

  return (
    Array.isArray(record.hidden)
    && record.hidden.every((v) => typeof v === 'string')
    && Array.isArray(record.order)
    && record.order.every((v) => typeof v === 'string')
  );
}

/**
 * الترتيب والإخفاء — دالّةٌ خالصة، مُصدَّرة ليختبرها الاختبار نفسُه.
 *
 * كانت محبوسةً داخل الخطّاف، فنسخ الاختبار قواعدها حرفًا بحرف واختبر
 * النسخة: ستّ حالاتٍ تمرّ أبدًا مهما انحرف الأصل. التصدير هنا يجعل
 * الاختبار يمسّ الشيفرة التي تعمل فعلًا.
 */
export function arrangeItems<T>(
  items: readonly T[],
  keyOf: (item: T) => string,
  prefs: { hidden?: readonly string[]; order?: readonly string[] } = {},
): T[] {
  const hiddenSet = new Set(prefs.hidden ?? []);
  const order = prefs.order ?? [];
  const visible = items.filter((item) => !hiddenSet.has(keyOf(item)));

  if (order.length === 0) return visible;

  const rank = new Map(order.map((key, index) => [key, index] as const));

  /*
   * المرتَّب أوّلًا بترتيبه المحفوظ، ثمّ غيرُ المرتَّب في موضعه الأصليّ.
   * الفرز المستقرّ يكفي: العنصر بلا رتبة يأخذ رتبة جاره الأصليّ فلا
   * يقفز إلى الذيل.
   */
  return visible
    .map((item, index) => ({ item, index, rank: rank.get(keyOf(item)) }))
    .sort((a, b) => {
      if (a.rank === undefined && b.rank === undefined) return a.index - b.index;
      if (a.rank === undefined) return 1;
      if (b.rank === undefined) return -1;

      return a.rank - b.rank;
    })
    .map((entry) => entry.item);
}

/**
 * تحقّقٌ لا تأكيد.
 *
 * القيمة يملكها المستعمِل ويستطيع تحريرها من أدوات المتصفّح. وقيمةٌ تالفة
 * كانت ستعبر إلى `includes()` فتنفجر أثناء التصيير — أي شاشةٌ بيضاء بسبب
 * تفضيلٍ تجميليّ.
 */
function parse(raw: string | null): Prefs {
  if (raw === null) return {};

  try {
    const parsed: unknown = JSON.parse(raw);
    if (typeof parsed !== 'object' || parsed === null) return {};

    const out: Prefs = {};
    for (const [scope, value] of Object.entries(parsed as Record<string, unknown>)) {
      if (isScope(value)) out[scope] = value;
    }

    return out;
  } catch {
    return {};
  }
}

function read(): Prefs {
  if (cache !== null) return cache;

  try {
    cache = parse(window.localStorage.getItem(KEY));
  } catch {
    // تخزينٌ محجوب: التفضيلات تعيش في الجلسة ولا تُحفظ. ولا عطل.
    cache = {};
  }

  return cache;
}

function write(next: Prefs): void {
  cache = next;

  try {
    window.localStorage.setItem(KEY, JSON.stringify(next));
  } catch {
    // كما أعلاه — الحفظ رفاهية، والتفضيل يعمل في الجلسة على أيّ حال.
  }

  for (const listener of listeners) listener();
}

function subscribe(onChange: () => void): () => void {
  listeners.add(onChange);

  /* تبويبٌ آخر عدّل التفضيلات: الذاكرة تُبطل ويُعاد القراءة. */
  const onStorage = (event: StorageEvent) => {
    if (event.key !== KEY) return;
    cache = null;
    onChange();
  };

  window.addEventListener('storage', onStorage);

  return () => {
    listeners.delete(onChange);
    window.removeEventListener('storage', onStorage);
  };
}

/* الخادم لا يعرف تفضيل المستعمل — «لا تفضيل» هو الفشل الآمن. */
function serverSnapshot(): Prefs {
  return {};
}

function scopeOf(prefs: Prefs, scope: string): ScopePrefs {
  return prefs[scope] ?? EMPTY_SCOPE;
}

interface LayoutScope {
  hidden: ReadonlySet<string>;
  /**
   * يرتّب عناصر النطاق ويُسقط المخفيّ.
   *
   * العنصر الجديد — الذي لم يكن موجودًا يوم حُفظ الترتيب — يظهر في موضعه
   * الأصليّ لا في آخر القائمة: ميزةٌ تُضاف في منتصف مجموعةٍ يجب أن تُرى،
   * ودفعُها إلى الذيل يجعلها غير موجودةٍ عمليًّا لمن خصّص شريطه مرّة.
   */
  arrange: <T>(items: readonly T[], keyOf: (item: T) => string) => T[];
  isHidden: (key: string) => boolean;
  toggleHidden: (key: string) => void;
  move: (key: string, direction: -1 | 1, keys: readonly string[]) => void;
  reorder: (keys: readonly string[]) => void;
  reset: () => void;
  /** هل خُصّص هذا النطاق أصلًا؟ — يُظهر زرّ «استعادة الافتراضي». */
  customized: boolean;
}

export function useLayoutScope(scope: string): LayoutScope {
  const prefs = useSyncExternalStore(subscribe, read, serverSnapshot);
  const current = scopeOf(prefs, scope);

  /*
   * `useMemo` لا `new Set` في كلّ تصيير: المجموعة الجديدة تُبطل كلّ
   * `useCallback` يعتمد عليها، فيُعاد بناء `arrange` مع كلّ رسم — وهو
   * بالضبط ما يجعل شريطًا من ستّة تبويبات يُعيد فرز نفسه بلا سبب.
   */
  const hidden = useMemo(() => new Set(current.hidden), [current.hidden]);

  const setScope = useCallback(
    (mutate: (was: ScopePrefs) => ScopePrefs) => {
      const all = read();
      write({ ...all, [scope]: mutate(scopeOf(all, scope)) });
    },
    [scope],
  );

  const arrange = useCallback(
    <T,>(items: readonly T[], keyOf: (item: T) => string): T[] =>
      arrangeItems(items, keyOf, { hidden: [...hidden], order: current.order }),
    [current.order, hidden],
  );

  const toggleHidden = useCallback(
    (key: string) => {
      setScope((was) => ({
        ...was,
        hidden: was.hidden.includes(key)
          ? was.hidden.filter((k) => k !== key)
          : [...was.hidden, key],
      }));
    },
    [setScope],
  );

  const reorder = useCallback(
    (keys: readonly string[]) => {
      setScope((was) => ({ ...was, order: [...keys] }));
    },
    [setScope],
  );

  /**
   * نقلٌ بخطوةٍ واحدة — وهو ما تستعمله لوحة المفاتيح.
   *
   * السحب بالمؤشّر وحده يُقصي من لا يستطيعه. والاتّجاه منطقيّ لا فيزيائيّ:
   * `-1` يعني «إلى البداية» أيًّا كان اتّجاه الكتابة.
   */
  const move = useCallback(
    (key: string, direction: -1 | 1, keys: readonly string[]) => {
      const order = [...keys];
      const from = order.indexOf(key);
      if (from === -1) return;

      const to = from + direction;
      if (to < 0 || to >= order.length) return;

      const [moved] = order.splice(from, 1);
      if (moved === undefined) return;
      order.splice(to, 0, moved);

      reorder(order);
    },
    [reorder],
  );

  const reset = useCallback(() => {
    const all = read();
    /* الحذف لا الإفراغ: نطاقٌ غائب يعني «الافتراضي» لا «كلّه مخفيّ». */
    const rest: Prefs = {};
    for (const [key, value] of Object.entries(all)) {
      if (key !== scope) rest[key] = value;
    }

    write(rest);
  }, [scope]);

  return {
    hidden,
    arrange,
    isHidden: (key: string) => hidden.has(key),
    toggleHidden,
    move,
    reorder,
    reset,
    customized: current.hidden.length > 0 || current.order.length > 0,
  };
}

/* ───── تخطيط اللوحة — التصاميم الخمسة ───── */

/**
 * التخطيط قيمةٌ واحدة تُبدّل شكل اللوحة كلّها.
 *
 * خمس قشراتٍ في خمسة ملفّات تتباعد بعد أوّل تعديل: تُضاف شارةٌ إلى إحداها
 * وتُنسى الأربع، فيصير «التصميم الثاني» نسخةً قديمة من الأوّل لا خيارًا
 * عنه. والقيمة هنا تُعلَن على الجسم، فالهندسة كلّها في `globals.css` بلا
 * تفرّعٍ في الترميز.
 */
type AdminLayout = 'sidebar' | 'rail' | 'topbar' | 'split' | 'focus';

export const ADMIN_LAYOUTS: readonly { id: AdminLayout; label: string; hint: string }[] = [
  { id: 'sidebar', label: 'الشريط الكامل', hint: 'أعلى كثافةٍ للملاحة — المجموعات ووجهاتها ظاهرة.' },
  { id: 'rail', label: 'شريط مصغّر', hint: 'أيقوناتٌ وحدها — أقصى عرضٍ للجداول.' },
  { id: 'topbar', label: 'تنقّل علويّ', hint: 'صفٌّ فوق المحتوى — أقصى ارتفاعٍ للشاشات العريضة.' },
  { id: 'split', label: 'عمودان', hint: 'مجموعةٌ واحدة مفتوحة — لمن يعمل في نطاقٍ واحد طويلًا.' },
  { id: 'focus', label: 'تركيز', hint: 'بلا شريطٍ دائم — الملاحة من زرّ القائمة.' },
];

const LAYOUT_KEY = 'admin.layout.mode';

function isLayout(value: string | null): value is AdminLayout {
  return ADMIN_LAYOUTS.some((entry) => entry.id === value);
}

/**
 * التخطيط المختار — ويُعلَن على `<body>`.
 *
 * على الجسم لا على القشرة: الدرج يُعلن حالته بالطريقة نفسها
 * (`data-drawer`)، والشبكة في `globals.css` تملك العمودين. ورفعُ الحالة إلى
 * مكوّنٍ يجعل مكوّنَين يملكان تفضيلًا واحدًا، ويكفي أن يُقرأ من مكانين
 * ليختلفا.
 */
export function useAdminLayout(): { layout: AdminLayout; setLayout: (next: AdminLayout) => void } {
  const raw = useSyncExternalStore(
    subscribe,
    () => {
      try {
        return window.localStorage.getItem(LAYOUT_KEY);
      } catch {
        return null;
      }
    },
    /* الخادم لا يعرف التفضيل — «الشريط الكامل» هو الفشل الآمن. */
    () => null,
  );

  const layout: AdminLayout = isLayout(raw) ? raw : 'sidebar';

  const setLayout = useCallback((next: AdminLayout) => {
    try {
      window.localStorage.setItem(LAYOUT_KEY, next);
    } catch {
      // التخزين رفاهية؛ التبديل يعمل في الجلسة على أيّ حال.
    }

    /* المخزن يبثّ لنفسه: `storage` لا يُطلق في التبويب الذي كتب. */
    for (const listener of listeners) listener();
  }, []);

  return { layout, setLayout };
}

/* ───── سمة اللوحة — داكنٌ زجاجيّ أو فاتح أو تَبَعُ النظام ───── */

/**
 * ثلاثة خيارات لا اثنان، وتمييزٌ بين ما يختاره المستعمل وما يُرسَم فعلًا.
 *
 * `AdminThemePreference` ما يُحفَظ: `dark` أو `light` أو `system`.
 * `ResolvedTheme` ما يُكتب على `<body data-theme>`: داكنٌ أو فاتح لا ثالث —
 * فورقة الأنماط لا تعرف «النظام»، وإنّما تعرف محدّدًا واحدًا
 * (`body[data-theme='light']`).
 *
 * و`system` ليس تفضيلًا ساكنًا: من اختاره يتبع تبديل نظامه **في الجلسة
 * نفسها**، فالاشتراك أدناه يصغي لـ`matchMedia` لا للتخزين وحده.
 *
 * والافتراضيّ يبقى الداكن لمن لم يختر — لا تبديلَ صامتًا لتفضيلٍ قائم.
 */
export type AdminThemePreference = 'dark' | 'light' | 'system';
export type ResolvedTheme = 'dark' | 'light';

export const ADMIN_THEMES: readonly { id: AdminThemePreference; label: string }[] = [
  { id: 'dark', label: 'داكن' },
  { id: 'light', label: 'فاتح' },
  { id: 'system', label: 'النظام' },
];

/** مفتاح التخزين ومَحرف الوسيط — يقرؤهما سكربت ما قبل الرسم في `app/layout.tsx`. */
export const THEME_KEY = 'admin.theme';
export const THEME_QUERY = '(prefers-color-scheme: light)';

function readPreference(): AdminThemePreference {
  try {
    const raw = window.localStorage.getItem(THEME_KEY);
    if (raw === 'light' || raw === 'dark' || raw === 'system') return raw;
  } catch {
    // التخزين رفاهية؛ الافتراضيّ يكفي.
  }
  return 'dark';
}

/**
 * اشتراكٌ يجمع مصدرَي تغيّر: التخزين (تبويبٌ آخر بدّل التفضيل) و`matchMedia`
 * (النظام نفسه بدّل سمته). إغفال الثاني يجعل `system` تفضيلًا يُقرأ مرّةً
 * عند الإماهة ثمّ يتجمّد.
 */
function subscribeTheme(onChange: () => void): () => void {
  listeners.add(onChange);

  const onStorage = (event: StorageEvent) => {
    if (event.key !== THEME_KEY) return;
    onChange();
  };

  window.addEventListener('storage', onStorage);

  const media = window.matchMedia(THEME_QUERY);
  media.addEventListener('change', onChange);

  return () => {
    listeners.delete(onChange);
    window.removeEventListener('storage', onStorage);
    media.removeEventListener('change', onChange);
  };
}

export function useAdminTheme(): {
  theme: AdminThemePreference;
  resolved: ResolvedTheme;
  setTheme: (next: AdminThemePreference) => void;
} {
  /*
   * لقطةٌ واحدة تحمل الاثنين مفصولين بفاصلة: `useSyncExternalStore` يقارن
   * اللقطات بالتساوي المرجعيّ، فكائنٌ جديد في كلّ نداء يُدخلها حلقة تصيير.
   */
  const snapshot = useSyncExternalStore(
    subscribeTheme,
    () => {
      const preference = readPreference();
      const resolved: ResolvedTheme =
        preference === 'system'
          ? (window.matchMedia(THEME_QUERY).matches ? 'light' : 'dark')
          : preference;
      return `${preference},${resolved}`;
    },
    /* الخادم لا يعرف تخزين المتصفّح ولا سمة نظامه: يرسم الافتراضيّ،
       وسكربت ما قبل الرسم يصحّح السمة قبل أوّل بكسل. */
    () => 'dark,dark',
  );

  const [preference, resolvedValue] = snapshot.split(',');
  const theme = (preference ?? 'dark') as AdminThemePreference;
  const resolved = (resolvedValue ?? 'dark') as ResolvedTheme;

  const setTheme = useCallback((next: AdminThemePreference) => {
    try {
      window.localStorage.setItem(THEME_KEY, next);
    } catch {
      // التخزين رفاهية؛ التبديل يعمل في الجلسة على أيّ حال.
    }
    for (const listener of listeners) listener();
  }, []);

  return { theme, resolved, setTheme };
}

/* ───── كثافة العرض — مريحٌ أو مضغوط ───── */

/**
 * الكثافة تفضيلُ **جسدٍ** لا تفضيلُ ذوق.
 *
 * من يقضي نهاره في جداول الطلبات يريد صفوفًا أكثر في الشاشة، ومن يدقّق
 * محتوًى يريد مسافةً يستريح فيها البصر. وهما مطلبان متعارضان لا وسط بينهما
 * يرضي الاثنين — فالاختيار للمستعمل.
 *
 * وتُنفَّذ **برموزٍ لا بشجرتين**: `data-density` على الجسم يُبدّل طبقة
 * `--d-*` في `globals.css`، فتتبع الارتفاعاتُ والحشواتُ ودرجاتُ الخطّ معًا.
 * ونسخةُ ترميزٍ ثانية «مضغوطة» كانت ستتباعد عن أختها بعد أوّل تعديل — وهو
 * العيب نفسه الموصوف أعلاه في التخطيطات الخمسة.
 */
export type AdminDensity = 'comfortable' | 'compact';

export const ADMIN_DENSITIES: readonly { id: AdminDensity; label: string; hint: string }[] = [
  { id: 'comfortable', label: 'مريح', hint: 'مسافةٌ أوسع — للقراءة والتدقيق.' },
  { id: 'compact', label: 'مضغوط', hint: 'صفوفٌ أقصر — عددٌ أكبر في الشاشة.' },
];

/** مفتاح التخزين — يُقرأ في `admin-shell.tsx` ويُعلَن على `<body data-density>`. */
export const DENSITY_KEY = 'admin.density';

function isDensity(value: string | null): value is AdminDensity {
  return value === 'comfortable' || value === 'compact';
}

/**
 * اشتراكٌ لمفتاحٍ مخزَّنٍ بعينه: باعثُ المخزن نفسه (كتابةٌ في هذا التبويب)
 * و`storage` (كتابةٌ في تبويبٍ آخر) — لأنّ الثاني **لا يُطلق في التبويب الذي
 * كتب**، والأوّل لا يعبر التبويبات.
 *
 * ومرجعٌ ثابت على مستوى الوحدة: `useSyncExternalStore` يُعيد الاشتراك كلّما
 * تغيّر مرجع `subscribe`، ودالّةٌ تُنشأ في التصيير تفكّ الاشتراك وتعيده مع
 * كلّ رسم.
 */
function subscribeStored(key: string): (onChange: () => void) => () => void {
  return (onChange) => {
    listeners.add(onChange);

    const onStorage = (event: StorageEvent) => {
      if (event.key === key) onChange();
    };

    window.addEventListener('storage', onStorage);

    return () => {
      listeners.delete(onChange);
      window.removeEventListener('storage', onStorage);
    };
  };
}

const subscribeDensity = subscribeStored(DENSITY_KEY);

export function useAdminDensity(): {
  density: AdminDensity;
  setDensity: (next: AdminDensity) => void;
} {
  const raw = useSyncExternalStore(
    subscribeDensity,
    () => {
      try {
        return window.localStorage.getItem(DENSITY_KEY);
      } catch {
        return null;
      }
    },
    /* الخادم لا يعرف التفضيل — «مريح» هو الفشل الآمن: لا شيء يُقصّ قبل الإماهة. */
    () => null,
  );

  const density: AdminDensity = isDensity(raw) ? raw : 'comfortable';

  const setDensity = useCallback((next: AdminDensity) => {
    try {
      window.localStorage.setItem(DENSITY_KEY, next);
    } catch {
      // التخزين رفاهية؛ التبديل يعمل في الجلسة على أيّ حال.
    }

    for (const listener of listeners) listener();
  }, []);

  return { density, setDensity };
}

/* ───── المظهر والمؤثّرات — وثيقة التفضيلات (عقد §3) ───── */

/**
 * اشتراكٌ عامّ في كلّ تفضيلات هذا المخزن — تستعمله المزامنة مع الخادم
 * (`components/settings/appearance-sync.ts`) لتعرف متى تغيّر شيءٌ يُحفظ.
 */
export function subscribePrefs(onChange: () => void): () => void {
  listeners.add(onChange);
  return () => {
    listeners.delete(onChange);
  };
}

function notify(): void {
  for (const listener of listeners) listener();
}

function readRaw(key: string): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeRaw(key: string, value: string | null): void {
  try {
    if (value === null) window.localStorage.removeItem(key);
    else window.localStorage.setItem(key, value);
  } catch {
    // التخزين رفاهية؛ التفضيل يعمل في الجلسة على أيّ حال.
  }
}

/** الوثيقة المحلّيّة كما هي — أقسامٌ لا يملكها هذا الملفّ (`dashboard`) تُحفظ كما وصلت. */
export function readPrefsDocument(): PrefsDocument {
  return parseDocument(readRaw(PREFS_KEY));
}

/**
 * يستبدل قسمًا واحدًا ويُبقي البقيّة — فمن يكتب `dashboard` لا يمحو
 * `appearance`، والعكس. وهي الواجهة التي يكتب بها مالكُ لوحة القيادة قسمَه.
 */
export function writePrefsSection(section: string, value: Record<string, unknown>): void {
  const doc = readPrefsDocument();
  writeRaw(PREFS_KEY, JSON.stringify({ ...doc, [section]: value }));
  notify();
}

/**
 * الوثيقة كاملةً للمزامنة: الأقسام المحفوظة + الثلاثة القديمة من مفاتيحها.
 * هذا هو «ترحيل» المفاتيح القديمة إلى الوثيقة — تُقرأ منها ولا تُنقل عنها،
 * فسكربت ما قبل الرسم والخطّافات القائمة لا تتغيّر.
 */
export function composePrefsDocument(): PrefsDocument {
  const doc = readPrefsDocument();
  return {
    ...doc,
    appearance: {
      ...(doc.appearance ?? {}),
      ...parseAppearance(doc.appearance),
      theme: readPreference(),
      density: isDensity(readRaw(DENSITY_KEY)) ? readRaw(DENSITY_KEY) : 'comfortable',
      layout: isLayout(readRaw(LAYOUT_KEY)) ? readRaw(LAYOUT_KEY) : 'sidebar',
    },
  };
}

/** وثيقةٌ وصلت من الخادم: تُكتب محلّيًّا، والثلاثة القديمة إلى مفاتيحها. */
export function applyPrefsDocument(remote: PrefsDocument): void {
  const appearance = remote.appearance ?? {};
  const theme = appearance.theme;
  if (theme === 'dark' || theme === 'light' || theme === 'system') writeRaw(THEME_KEY, theme);
  if (typeof appearance.density === 'string' && isDensity(appearance.density)) writeRaw(DENSITY_KEY, appearance.density);
  if (typeof appearance.layout === 'string' && isLayout(appearance.layout)) writeRaw(LAYOUT_KEY, appearance.layout);
  writeRaw(PREFS_KEY, JSON.stringify({ ...readPrefsDocument(), ...remote }));
  notify();
}

const REDUCE_QUERY = '(prefers-reduced-motion: reduce)';

function subscribeAppearance(onChange: () => void): () => void {
  listeners.add(onChange);
  const onStorage = (event: StorageEvent) => {
    if (event.key === PREFS_KEY) onChange();
  };
  window.addEventListener('storage', onStorage);
  /* تفضيل النظام يتبدّل في الجلسة نفسها — «كاملة» تنقلب «مخفّفة» فورًا. */
  const media = window.matchMedia(REDUCE_QUERY);
  media.addEventListener('change', onChange);

  return () => {
    listeners.delete(onChange);
    window.removeEventListener('storage', onStorage);
    media.removeEventListener('change', onChange);
  };
}

export interface AdminAppearanceApi {
  appearance: AppearanceCore;
  /** السمات الفعليّة على `<body>` — بعد تفضيل النظام وشدّة «بلا». */
  attributes: Record<string, string>;
  systemReducedMotion: boolean;
  setAccent: (next: AppearanceCore['accent']) => void;
  setRadius: (next: AppearanceCore['radius']) => void;
  setEffect: <K extends keyof AdminEffects>(key: K, value: AdminEffects[K]) => void;
  /** يعيد المظهر كلّه — السمة والكثافة والتخطيط معه — إلى الافتراضيّ. */
  reset: () => void;
}

export function useAdminAppearance(): AdminAppearanceApi {
  /*
   * لقطةٌ نصّيّة لا كائن: `useSyncExternalStore` يقارن بالمرجع، وكائنٌ جديد
   * في كلّ نداء يُدخله حلقة تصيير.
   */
  const snapshot = useSyncExternalStore(
    subscribeAppearance,
    () => `${window.matchMedia(REDUCE_QUERY).matches ? 1 : 0}${readRaw(PREFS_KEY) ?? ''}`,
    /* الخادم لا يعرف التخزين: الافتراضيّ، وسكربت ما قبل الرسم يصحّح قبل أوّل بكسل. */
    () => '0',
  );

  const systemReducedMotion = snapshot.startsWith('1');
  const appearance = useMemo(
    () => parseAppearance(parseDocument(snapshot.slice(1) || null).appearance),
    [snapshot],
  );
  const attributes = useMemo(
    () => appearanceAttributes(appearance, systemReducedMotion),
    [appearance, systemReducedMotion],
  );

  const update = useCallback((mutate: (was: AppearanceCore) => AppearanceCore) => {
    const doc = readPrefsDocument();
    const was = parseAppearance(doc.appearance);
    writePrefsSection('appearance', { ...(doc.appearance ?? {}), ...mutate(was) });
  }, []);

  const setAccent = useCallback((accent: AppearanceCore['accent']) => update((was) => ({ ...was, accent })), [update]);
  const setRadius = useCallback((radius: AppearanceCore['radius']) => update((was) => ({ ...was, radius })), [update]);
  const setEffect = useCallback(
    <K extends keyof AdminEffects>(key: K, value: AdminEffects[K]) =>
      update((was) => ({ ...was, effects: { ...was.effects, [key]: value } })),
    [update],
  );

  const reset = useCallback(() => {
    writeRaw(THEME_KEY, null);
    writeRaw(DENSITY_KEY, null);
    writeRaw(LAYOUT_KEY, null);
    writePrefsSection('appearance', { ...DEFAULT_APPEARANCE });
  }, []);

  return { appearance, attributes, systemReducedMotion, setAccent, setRadius, setEffect, reset };
}
