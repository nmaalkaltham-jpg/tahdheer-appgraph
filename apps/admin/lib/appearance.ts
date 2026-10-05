// UNIT: LIB-APPEARANCE
// FILE: appearance.ts
// STYLE: none
// PARENT: LIB-LAYOUT-PREFS, LAYOUT-HOME
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مظهر اللوحة — منطقٌ خالص بلا React ولا `'use client'`.
 *
 * ─── لماذا وحدةٌ منفصلة عن `layout-prefs.ts` ───
 *
 * سكربت ما قبل الرسم يُصيَّر في `app/layout.tsx` — مكوّن خادم. وما يُستورد
 * من وحدةٍ معلَّمة `'use client'` يصل إلى الخادم **مرجعَ عميل** لا قيمة، فلا
 * يُقرأ نصُّ السكربت منها. والخطّافات تبقى في `layout-prefs.ts` وتعيد تصدير
 * ما هنا، فلا يتغيّر مسار استيرادٍ قائم.
 *
 * ─── الوثيقة واحدة (عقد §3) ───
 *
 * `{ appearance: {...}, dashboard: { preset, mode, widgets } }` — الشكل نفسه
 * في `localStorage` (نسخةٌ محلّيّة) وفي `GET/PUT /admin/me/preferences` متى
 * وُجد. والمفاتيح القديمة (`admin.theme` · `admin.density` ·
 * `admin.layout.mode`) تبقى مصدرَ هذه الثلاثة: سكربت ما قبل الرسم والخطّافات
 * القائمة تقرؤها، والوثيقة تحمل نسخةً منها عند المزامنة.
 */

export const PREFS_KEY = 'admin.prefs.v1';

export type OnOff = 'on' | 'off';
export type EffectLevel = 'off' | 'low' | 'medium' | 'high';
export type MotionPref = 'off' | 'reduced' | 'full';
export type AccentId = 'teal' | 'navy' | 'gold';
export type RadiusId = 'compact' | 'default' | 'soft';

export interface AdminEffects {
  glow: OnOff;
  shine: OnOff;
  glass: OnOff;
  ambient: OnOff;
  shadows: EffectLevel;
  motion: MotionPref;
  intensity: EffectLevel;
}

/** ما تحمله الوثيقة في `appearance` سوى الثلاثة القديمة. */
export interface AppearanceCore {
  accent: AccentId;
  radius: RadiusId;
  effects: AdminEffects;
}

export const DEFAULT_EFFECTS: AdminEffects = {
  glow: 'on',
  shine: 'on',
  glass: 'on',
  ambient: 'on',
  shadows: 'medium',
  motion: 'full',
  intensity: 'medium',
};

export const DEFAULT_APPEARANCE: AppearanceCore = {
  accent: 'teal',
  radius: 'default',
  effects: DEFAULT_EFFECTS,
};

/*
 * الألوان من مقاييس الهويّة (تركواز · كحليّ · ذهبيّ)، وقيمها في `globals.css`
 * تحت `body[data-accent]` — واختبار `appearance.test.mjs` يقرأ تلك الكتلة
 * ويقيس تباينها، فلا يُضاف لونٌ لا يبلغ 4.5:1 للنصّ و3:1 للعنصر.
 */
export const ADMIN_ACCENTS: readonly { id: AccentId; label: string }[] = [
  { id: 'teal', label: 'تركواز' },
  { id: 'navy', label: 'كحليّ' },
  { id: 'gold', label: 'ذهبيّ' },
];

/* من سلّم الزوايا وحده (8/10/12/16/20) — لا قيمة خارجه. */
export const ADMIN_RADII: readonly { id: RadiusId; label: string; hint: string }[] = [
  { id: 'compact', label: 'مشدود', hint: 'بطاقات 12 · حقول 10' },
  { id: 'default', label: 'متوازن', hint: 'بطاقات 16 · حقول 12' },
  { id: 'soft', label: 'ليّن', hint: 'بطاقات 20 · حقول 16' },
];

export const ON_OFF: readonly { id: OnOff; label: string }[] = [
  { id: 'on', label: 'مفعّل' },
  { id: 'off', label: 'معطّل' },
];

export const EFFECT_LEVELS: readonly { id: EffectLevel; label: string }[] = [
  { id: 'off', label: 'بلا' },
  { id: 'low', label: 'خفيف' },
  { id: 'medium', label: 'متوسّط' },
  { id: 'high', label: 'قويّ' },
];

export const MOTION_PREFS: readonly { id: MotionPref; label: string; hint: string }[] = [
  { id: 'off', label: 'بلا حركة', hint: 'لا انتقال ولا حركة إطلاقًا.' },
  { id: 'reduced', label: 'مخفّفة', hint: 'انتقالاتٌ قصيرة تجيب الفعل، بلا حركةٍ زخرفيّة.' },
  { id: 'full', label: 'كاملة', hint: 'كما صُمّمت — ويبقى تفضيل النظام «تقليل الحركة» نافذًا.' },
];

const pick = <T extends string>(value: unknown, allowed: readonly T[], fallback: T): T =>
  typeof value === 'string' && (allowed as readonly string[]).includes(value) ? (value as T) : fallback;

const ONOFF_IDS = ['on', 'off'] as const;
const LEVEL_IDS = ['off', 'low', 'medium', 'high'] as const;
const MOTION_IDS = ['off', 'reduced', 'full'] as const;
const ACCENT_IDS = ['teal', 'navy', 'gold'] as const;
const RADIUS_IDS = ['compact', 'default', 'soft'] as const;

/**
 * تحقّقٌ لا تأكيد — القيمة يملكها المستعمل ويحرّرها من أدوات المتصفّح،
 * ويعيدها الخادم من وثيقةٍ قد تكون من نسخةٍ أقدم. كلّ حقلٍ مجهول يعود إلى
 * افتراضه وحده، فلا يُسقط تفضيلٌ تالفٌ بقيّةَ التفضيلات السليمة.
 */
export function parseAppearance(raw: unknown): AppearanceCore {
  const record = typeof raw === 'object' && raw !== null ? (raw as Record<string, unknown>) : {};
  const fx = typeof record.effects === 'object' && record.effects !== null
    ? (record.effects as Record<string, unknown>)
    : {};

  return {
    accent: pick(record.accent, ACCENT_IDS, DEFAULT_APPEARANCE.accent),
    radius: pick(record.radius, RADIUS_IDS, DEFAULT_APPEARANCE.radius),
    effects: {
      glow: pick(fx.glow, ONOFF_IDS, DEFAULT_EFFECTS.glow),
      shine: pick(fx.shine, ONOFF_IDS, DEFAULT_EFFECTS.shine),
      glass: pick(fx.glass, ONOFF_IDS, DEFAULT_EFFECTS.glass),
      ambient: pick(fx.ambient, ONOFF_IDS, DEFAULT_EFFECTS.ambient),
      shadows: pick(fx.shadows, LEVEL_IDS, DEFAULT_EFFECTS.shadows),
      motion: pick(fx.motion, MOTION_IDS, DEFAULT_EFFECTS.motion),
      intensity: pick(fx.intensity, LEVEL_IDS, DEFAULT_EFFECTS.intensity),
    },
  };
}

/**
 * السمات التي تُكتب على `<body>` — **الحالة الفعليّة** لا التفضيل الخام.
 *
 * - `prefers-reduced-motion` يغلب دائمًا: «كاملة» تصير «مخفّفة» تحته.
 * - الشدّة «بلا» تُطفئ المؤثّرات الزخرفيّة الأربعة (توهّج · لمعة · زجاج ·
 *   إضاءة محيطة) — والظلّ يبقى لمفتاحه: الظلّ عمقٌ يفصل الطبقات لا زخرفة.
 *
 * فورقة الأنماط لا تحسب شيئًا: كلّ سمةٍ تعني حالةً واحدة.
 */
export function appearanceAttributes(core: AppearanceCore, systemReducedMotion: boolean): Record<string, string> {
  const fx = core.effects;
  const dim = fx.intensity === 'off';
  const motion = fx.motion === 'full' && systemReducedMotion ? 'reduced' : fx.motion;

  return {
    accent: core.accent,
    radius: core.radius,
    fxGlow: dim ? 'off' : fx.glow,
    fxShine: dim ? 'off' : fx.shine,
    fxGlass: dim ? 'off' : fx.glass,
    fxAmbient: dim ? 'off' : fx.ambient,
    fxShadows: fx.shadows,
    fxMotion: motion,
    fxIntensity: fx.intensity,
  };
}

/** أسماء السمات كما تُكتب في `dataset` — لإزالتها عند التفكيك. */
export const APPEARANCE_ATTRIBUTE_KEYS = Object.keys(
  appearanceAttributes(DEFAULT_APPEARANCE, false),
) as readonly string[];

/**
 * سكربت ما قبل الرسم — يضبط السمة والكثافة والمظهر قبل أوّل بكسل.
 *
 * مكتوبٌ نصًّا صريحًا لا `fn.toString()`: المُصغِّر يعيد تسمية ما يشاء،
 * والسكربت يجب أن يبقى كتلةً مستقلّة لا تشير إلى شيءٍ خارجها. والتحقّق
 * فيه مختصرٌ عمدًا — ما لا يُعرف يُترك لافتراض الورقة، والخطّاف يكتب
 * القيمة المحقَّقة بعد الإماهة.
 */
export const PREPAINT_SCRIPT =
  'try{var b=document.body,s=localStorage,p=s.getItem("admin.theme");'
  + 'b.dataset.theme=p==="light"||p==="dark"?p:p==="system"?(matchMedia("(prefers-color-scheme: light)").matches?"light":"dark"):"dark";'
  + 'var d=s.getItem("admin.density");if(d==="compact"||d==="comfortable")b.dataset.density=d;'
  + `var a={};try{a=(JSON.parse(s.getItem("${PREFS_KEY}"))||{}).appearance||{};}catch(e){}`
  + 'var f=a.effects||{},o=function(v,l,x){return l.indexOf(v)>-1?v:x},OF=["on","off"],LV=["off","low","medium","high"];'
  + 'var i=o(f.intensity,LV,"medium"),dim=i==="off",m=o(f.motion,["off","reduced","full"],"full");'
  + 'if(m==="full"&&matchMedia("(prefers-reduced-motion: reduce)").matches)m="reduced";'
  + 'b.dataset.accent=o(a.accent,["teal","navy","gold"],"teal");b.dataset.radius=o(a.radius,["compact","default","soft"],"default");'
  + '["glow","shine","glass","ambient"].forEach(function(k){b.dataset["fx"+k[0].toUpperCase()+k.slice(1)]=dim?"off":o(f[k],OF,"on")});'
  + 'b.dataset.fxShadows=o(f.shadows,LV,"medium");b.dataset.fxMotion=m;b.dataset.fxIntensity=i;'
  + '}catch(e){}';

/* ───── الوثيقة ───── */

export interface PrefsDocument {
  appearance?: Record<string, unknown>;
  dashboard?: Record<string, unknown>;
  [section: string]: unknown;
}

export function parseDocument(raw: string | null): PrefsDocument {
  if (raw === null) return {};
  try {
    const parsed: unknown = JSON.parse(raw);
    return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed) ? (parsed as PrefsDocument) : {};
  } catch {
    return {};
  }
}
