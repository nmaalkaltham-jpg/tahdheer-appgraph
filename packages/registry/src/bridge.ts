// UNIT: BRIDGE
// FILE: bridge.ts
// STYLE: none
// PARENT: REGISTRY-INDEX
// USES: CONDITIONS, DOCUMENT, STYLES, SYMBOLS
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * بروتوكول جسر الاستوديو — المحرّر ↔ إطار المعاينة (STUDIO-PHASE1-CONTRACT
 * §2.2، SPEC §68). خالٍ من React ومن DOM: أنواع وظرف رسائل فقط.
 *
 * الظرف `{v:1, t, …}`. الطرفان يتحقّقان من الأصل **الحرفي** — أبدًا `'*'`:
 *   · المعاينة تقبل رسائل من `STUDIO_EDITOR_ORIGIN` فقط.
 *   · المحرّر يقبل من `new URL(storeUrl).origin` فقط، ومن `contentWindow`
 *     إطاره هو لا غير.
 * المسوّدة تصل المعاينة عبر هذه الرسائل حصرًا — لا مسار URL لمسوّدة إطلاقًا
 * (قرار C4: العزل بنيوي).
 */
import type { ConditionContext } from './conditions';
import type { StudioDevice, StudioDocument } from './document';
import type { StyleState } from './styles';
import type { SymbolsMap } from './symbols';

/**
 * رقم البروتوكول الحالي — المعاينة تعلنه في READY والمحرّر يطابقه بصرامة.
 *
 * 5 في جولة القياس: رسالتان لا أكثر — MEASURE (محرّر→معاينة) وMEASURED
 * (معاينة→محرّر). السبب أنّ فحص ما قبل النشر القائم يقرأ **الوثيقة**
 * (`PageDocument::preflight`: مستند فارغ · منبثقة فارغة · مخطّط مختلف ·
 * نسخة قديمة · معرّف سجلّ مجهول) وهي خمس حقائق بنيويّة صحيحة، لكنّها لا تعرف
 * شيئًا عمّا **يظهر فعلًا**: صورةٌ لا تُحمَّل، وهدف لمسٍ ارتفاعه ٣٠px، وتجاوزٌ
 * أفقيّ يقصّ المحتوى على الجوّال. هذه لا تُقاس إلّا في مستندٍ مُصيَّر بمقاسٍ
 * حقيقيّ — وهو ما يملكه إطار المعاينة وحده. والقياس يجري هناك ويعود رقمًا،
 * فلا يعبر DOM النافذتين ولا يحتاج وصولًا عبر الأصول.
 * 4 في جولة الأسطح المتعدّدة (§12 · §19–§21): سطح المعاينة صار يجوز أن يكون
 * **نافذةً منفصلة** لا إطارًا فقط، وصارت الأسطح تُعرَض جنبًا إلى جنب (انقسام ·
 * جدار الاستجابة · مسوّدة مقابل منشور). ورسمُ ذلك رسالتان لا أكثر:
 * SCROLL_SYNC (محرّر→معاينة) وSCROLLED (معاينة→محرّر) — مزامنة تمرير بنسبةٍ
 * من المدى القابل للتمرير، لأن لوحين بعرضين مختلفين لهما ارتفاعان مختلفان
 * فالبكسل المطلق يزيغ بينما النسبة تصف الموضع نفسه من الصفحة. وهي **تقريب
 * مُعلَن** لا تطابق: قسم أطول في الجوال يزيح النسبة قليلًا، وهذا مقبول
 * للمقارنة ومكتوبٌ هنا بدل أن يُكتشف استعمالًا.
 * 3 في المرحلة ٢٫٥+٣ (قرار C31 — قانون C19 حرفيًّا: ترقية واحدة للجولة):
 * مستند v3 في INIT/DOC_REPLACE + `INIT.symbols` + الرسائل الأربع الجديدة
 * SYMBOLS_REPLACE · PREVIEW_CONTEXT · POPUP_PREVIEW · ANIM_REPLAY (شريحة
 * الإجراءات تضيف صفر رسائل). انحراف النشر يُظهر لافتة عدم التطابق
 * المكتوبة القائمة — لا كانفس نصف ميت ولا مراجع تُرسم ثقوبًا. ظرف الرسالة
 * يبقى `{v:1,…}` عمدًا: v نسخة شكل الظرف لا نسخة البروتوكول، وتغييره
 * يجعل الطرف القديم يُسقط الرسائل صامتًا بدل أن يعلن READY فتظهر اللافتة.
 */
export const BRIDGE_PROTOCOL = 5;

/** حالة حلّ استعلام عقدة داخل المعاينة (تغذّي تبويب البيانات في المفتّش). */
export type ResolveState = 'ready' | 'loading' | 'empty' | 'error';

/**
 * شدّة ملاحظة القياس.
 *
 * `blocker` لما يكسر الصفحة على قارئها فعلًا (قصٌّ أفقيّ · صورة لا تُحمَّل ·
 * مرساة بلا هدف)، و`warning` لما يخالف حدًّا مُعلَنًا ويبقى مقروءًا (هدف لمس ·
 * حجم نصّ · صورة بلا `alt`). والتقسيم **ليس** هو تقسيم `preflight` الخادميّ
 * ولا يُدمج به: ذاك يمنع النشر، وهذا يُبلغ ولا يمنع — قياسٌ على مقاسٍ واحد
 * لا يصحّ أن يوقف نشرًا.
 */
export type QaSeverity = 'blocker' | 'warning';

/** رمز القاعدة المقيسة. مغلق: رمزٌ مجهول يعني قاعدةً بلا نصٍّ يشرحها. */
export type QaCode =
  | 'OVERFLOW_X'
  | 'IMAGE_BROKEN'
  | 'IMAGE_NO_ALT'
  | 'TAP_TARGET'
  | 'TEXT_TOO_SMALL'
  | 'ANCHOR_DEAD';

/**
 * ملاحظةٌ **مقيسة** — كلّ حقلٍ فيها رقمٌ قرأه المتصفّح، لا تقدير.
 *
 * `count` عدد العناصر المطابقة فعلًا، و`detail` يصف أوّلها بأرقامه. وبلا
 * مطابقٍ واحد لا تُبنى الملاحظة أصلًا: القائمة الفارغة تعني «قِيس ولم يُوجد»،
 * وهو ما تقوله الواجهة صراحةً بدل أن تصمت.
 */
export interface QaFinding {
  code: QaCode;
  severity: QaSeverity;
  count: number;
  detail: string;
  /** عقدة الاستوديو الحاوية إن عُرفت — تسمح بالتحديد من التقرير. */
  nodeId: string | null;
}

/** المحرّر → المعاينة. */
export type EditorToPreviewMessage =
  /** ردًّا على READY: المستند كاملًا + خريطة أجسام الرموز المنشورة + الجهاز + التحديد. */
  | { v: 1; t: 'INIT'; doc: StudioDocument; symbols: SymbolsMap; device: StudioDevice; selection: string | null }
  /**
   * المستند كاملًا مع عدّاد صاعد رتيب — المعاينة تتجاهل rev أقدم من آخر
   * ما طبّقت (قرار C6: لا رقعًا دقيقة، فلا نمط فشل في ترتيب العمليات).
   */
  | { v: 1; t: 'DOC_REPLACE'; doc: StudioDocument; rev: number }
  | { v: 1; t: 'SELECT'; nodeId: string | null }
  | { v: 1; t: 'SCROLL_TO'; nodeId: string | null }
  /** المحرّر يغيّر مقاس الإطار أيضًا — استعلامات الوسائط الحقيقية تتكفّل بالعرض. */
  | { v: 1; t: 'DEVICE'; device: StudioDevice }
  /** إعادة حلّ الاستعلامات (بعد إغلاق درج المنتج مثلًا). */
  | { v: 1; t: 'REFRESH_DATA' }
  /**
   * معاينة حالة قسرًا: تضبط data-studio-state على غلاف العقدة (زوج
   * المحدّد القسري في compileDocCss §2.3 — المعاينة هي CSS المنشور نفسه).
   * null = مسح الحالة المفروضة.
   */
  | { v: 1; t: 'FORCE_STATE'; nodeId: string; state: StyleState | null }
  /**
   * معاينة رموز الثيم: حقن/استبدال <style id="studio-theme-preview"> آخر
   * عنصر في <head> (تخصيص مساوٍ لـ/theme.css والمتأخر مصدرًا يغلب حتميًّا).
   * null = إزالة المعاينة.
   */
  | { v: 1; t: 'THEME_PREVIEW'; css: string | null }
  /**
   * استبدال خريطة الرموز كاملة بعدّاد صاعد رتيب (قانون DOC_REPLACE نفسه —
   * قرار C6: لا رقعًا دقيقة): بعد نشر رمز أو جلب جسمٍ للوحة الرموز.
   */
  | { v: 1; t: 'SYMBOLS_REPLACE'; symbols: SymbolsMap; rev: number }
  /**
   * سياق شروط محاكى (§2.6): الكانفس يقيّم به **ويعلّم** بدل القص —
   * null = العودة إلى السياق الحقيقي (ساعة المعاينة، بلا UTM، بلا سلة محاكاة).
   */
  | { v: 1; t: 'PREVIEW_CONTEXT'; ctx: ConditionContext | null }
  /**
   * فتح/إغلاق المنبثقة الحقيقية داخل الإطار للمعاينة والتحرير في الموضع —
   * المعالج يبثّ حدث window «tah:popup» نفسه الذي يستعمله open_modal (C35).
   */
  | { v: 1; t: 'POPUP_PREVIEW'; nodeId: string; open: boolean }
  /** «إعادة تشغيل الحركة» — null = كل عقد الحركة في المستند. */
  | { v: 1; t: 'ANIM_REPLAY'; nodeId: string | null }
  /**
   * مزامنة تمرير سطحٍ آخر (§19–§21): `ratio` نسبةٌ في [0,1] من المدى القابل
   * للتمرير، لا بكسلات — لوحان بعرضين مختلفين ارتفاعاهما مختلفان.
   * المعاينة تكبح بثّ SCROLLED أثناء تطبيقها فلا تنشأ حلقة صدى.
   */
  | { v: 1; t: 'SCROLL_SYNC'; ratio: number }
  /**
   * اقْسِ الصفحة المُصيَّرة الآن وأعِد MEASURED. بلا حمل: القياس يجري على ما
   * في الإطار وقتها بمقاسه وقتها — وهو بالضبط ما يُراد قياسه.
   */
  | { v: 1; t: 'MEASURE' };

/** المعاينة → المحرّر. */
export type PreviewToEditorMessage =
  /** المصافحة؛ غيابها ضمن المهلة ⇒ لافتة صريحة في المحرّر، لا كانفس ميت صامت. */
  | { v: 1; t: 'READY'; protocol: number }
  /** نقرة تحديد داخل الكانفس (null = نقرة خارج كل العقد). */
  | { v: 1; t: 'SELECTED'; nodeId: string | null }
  /** إيداع تحرير نص مباشر (نص خام لا غني — «تحرير نص مباشر»). */
  | { v: 1; t: 'TEXT_EDIT'; nodeId: string; key: string; value: string }
  /** نقرة بطاقة منتج (SPEC §29) — يفتح درج تحرير المنتج. */
  | { v: 1; t: 'OPEN_PRODUCT'; productId: number }
  | { v: 1; t: 'RESOLVE_STATE'; nodeId: string; state: ResolveState }
  /**
   * موضع تمرير هذا السطح نسبةً في [0,1] — يبثّه السطح **الذي يقوده المستخدم**
   * فيعيده المحرّر SCROLL_SYNC إلى بقية الأسطح. مكبوح زمنيًّا في المعاينة.
   */
  | { v: 1; t: 'SCROLLED'; ratio: number }
  /**
   * نتيجة القياس: القائمة كاملةً وعرضُ الإطار الذي قِيس عليه. العرض جزءٌ من
   * النتيجة لا زينة — «تجاوز أفقيّ» بلا العرض الذي ظهر عنده ملاحظةٌ لا تُعاد.
   */
  | { v: 1; t: 'MEASURED'; findings: QaFinding[]; viewport: number };

export type BridgeMessage = EditorToPreviewMessage | PreviewToEditorMessage;

// Record<union, true> يفرض الاكتمال والحصر معًا: نوع رسالة ناقص أو زائد
// هنا يكسر الترجمة بدل أن يُسقط الحارس رسائل صحيحة بصمت.
const MESSAGE_TYPE_MAP = {
  INIT: true,
  DOC_REPLACE: true,
  SELECT: true,
  SCROLL_TO: true,
  DEVICE: true,
  REFRESH_DATA: true,
  FORCE_STATE: true,
  THEME_PREVIEW: true,
  SYMBOLS_REPLACE: true,
  PREVIEW_CONTEXT: true,
  POPUP_PREVIEW: true,
  ANIM_REPLAY: true,
  SCROLL_SYNC: true,
  MEASURE: true,
  READY: true,
  SELECTED: true,
  TEXT_EDIT: true,
  OPEN_PRODUCT: true,
  RESOLVE_STATE: true,
  SCROLLED: true,
  MEASURED: true,
} satisfies Record<BridgeMessage['t'], true>;

const MESSAGE_TYPES: ReadonlySet<string> = new Set(Object.keys(MESSAGE_TYPE_MAP));

/**
 * حارس شكل الظرف. **لا يغني عن فحص الأصل**: افحص `event.origin` حرفيًّا
 * (ومصدر النافذة في المحرّر) قبل استدعائه — هذا يفحص الشكل فقط.
 */
export function isBridgeMessage(data: unknown): data is BridgeMessage {
  if (typeof data !== 'object' || data === null) return false;
  const m = data as { v?: unknown; t?: unknown };
  return m.v === 1 && typeof m.t === 'string' && MESSAGE_TYPES.has(m.t);
}
