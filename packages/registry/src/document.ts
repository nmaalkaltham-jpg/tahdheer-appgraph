// UNIT: DOCUMENT
// FILE: document.ts
// STYLE: none
// PARENT: BRIDGE, CONDITIONS, CSS-COMPILE, ENTRY-NODE, REGISTRY-ACTIONS, REGISTRY-INDEX +2
// USES: ANIM, CONDITIONS, REGISTRY-ACTIONS, STYLES
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مستند الاستوديو — schema v3 (STUDIO-PHASE3-CONTRACT §2.1، قرار C30:
 * ترقية **واحدة** تحمل حقول الشرائح الثلاث معًا).
 *
 * الترقية الوحيدة لهذه الجولة: صنف عقدة `symbol-ref` بمفاتيحه المغلقة
 * REF_NODE_KEYS (C38) + `interactions` (≤4، actions.ts) + `anim` (القوائم
 * المغلقة، anim.ts) + `cond` («و» فقط ≤5، conditions.ts) + مكوّن `popup`
 * (حاوية جذرية فقط). الخادم يقبل النسخ الثلاث (SUPPORTED_SCHEMAS=[1,2,3])
 * ويخزّن كما وصله؛ المحرّر يبثّ v3 دائمًا بعد migrateDocument عند **كل**
 * حافة تحميل (قرار C23 — المُرقّي يعيش هنا حصرًا ولا يُعاد تنفيذه في أي
 * مكان آخر).
 *
 * هذا الملف خالٍ من React؛ استيراده الوحيد وقت التشغيل قوائم anim.ts
 * المغلقة (ملف بلا استيرادات): تشاركه لوحة التحكم والمتجر وتشغّله
 * الاختبارات والحارس تحت Node مباشرة، ويطابقه الـnormalizer في الخادم
 * (PageDocument.php) مفهوميًّا.
 */

import { ANIM_DELAYS, ANIM_DURATIONS, ANIM_EASINGS, ANIM_ENTRANCES, ANIM_TRIGGERS, type NodeAnim } from './anim.ts';
import type { NodeInteraction } from './actions.ts';
import type { NodeConditions } from './conditions.ts';
import type { StudioClassDef, StudioStyleSheet, StyleLayer } from './styles';

export type StudioDevice = 'desk' | 'tab' | 'mob';

/** أجهزة التجاوزات — لا desk: الأساس هو سطح المكتب. */
export type ResponsiveDevice = 'tab' | 'mob';

/** النسخة الحالية للمخطط — المحرّر يبثّها بعد الترحيل عند التحميل. */
export const CURRENT_SCHEMA = 3;

/** أقصى عمق للشجرة: الجذر 1 وأعمق ورقة 4. */
export const MAX_DEPTH = 4;

/** ميزانية العقد — تُعدّ على الشجرة كاملة لا الجذور فقط (قرار C22). */
export const MAX_NODES = 60;

/** ميزانيتا الأصناف — مستقلتان عن ميزانية العقد (قرار C22). */
export const MAX_CLASSES = 40;
export const MAX_NODE_CLASS_IDS = 8;

/** ميزانيات الرموز (قرارا C32/C38) — مراياها في PHP يطابقها الحارس. */
export const SYMBOL_MAX_DEPTH = 3; // جسم الرمز: الجذر 1 وأعمق ورقة 3
export const MAX_REF_DEPTH = 2; // المرجع يقف في عمق الصفحة 1 أو 2
export const MAX_SYMBOL_NODES = 20; // ميزانية شجرة الجسم
export const MAX_REFS_PER_PAGE = 10;
export const MAX_OVERRIDE_NODES = 20;
export const SYMBOL_SCOPED_ID_MAX = 30; // معرّفات المراجع والأجسام؛ المركّب ≤62 < 64
// الثابت الحسابي — يفحصه الحارس على الجانبين:
// MAX_REF_DEPTH + SYMBOL_MAX_DEPTH - 1 === MAX_DEPTH   (2+3-1 = 4)

/** حدّا السلوك (قرار C30): تفاعلات العقدة وقواعد الشروط. */
export const MAX_NODE_INTERACTIONS = 4;
export const MAX_CONDITIONS = 5;

/** مفاتيح عقدة المرجع — مغلقة بنيويًّا (C38)؛ المرآة في PHP: REF_NODE_KEYS. */
export const REF_NODE_KEYS = ['id', 'kind', 'symbolId', 'overrides', 'hidden', 'slot'] as const;

/** مفاتيح slot المسموحة — المرآة في PHP: SLOT_KEYS. */
export const SLOT_KEYS = ['grow', 'colSpan'] as const;

/** حدود colSpan داخل أب grid. */
export const SLOT_COL_SPAN_MIN = 1;
export const SLOT_COL_SPAN_MAX = 12;

/**
 * مكوّنات الحاويات — القدرة مشتقّة من المكوّن لا حقل مدخل جديد
 * (registry.ts يشتق isContainerEntry من هذه القائمة نفسها).
 */
/*
 * ما يجوز له ‎children‎. الثلاث المؤثِّرة أغلفة بطبيعتها: بلا أبناء لا تصيّر
 * شيئًا، والمحرّر يحذّر من الحاوية الفارغة كما يحذّر من ‎container‎.
 */
export const CONTAINER_COMPONENTS = [
  'container',
  'popup',
  'spotlight-layer',
  'beam-backdrop',
  'tracing-beam',
] as const;

/** نحو معرّفات العقد والأصناف — يقفله الخادم؛ compileDocCss يرفض ما خالفه. */
export const STUDIO_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;

/**
 * استعلام مجموعة المنتجات — يطابق سطح ProductController العام حرفيًّا
 * (source/limit/ids): لا مصدر خامس ولا مفتاح إضافي قبل أن يوجد في الخادم.
 */
export interface StudioQuery {
  source: 'new' | 'manual' | 'best' | 'featured';
  limit: number;
  ids?: number[];
}

/** موضع العقدة داخل أبيها (flex: grow · grid: colSpan). */
export interface NodeSlot {
  grow?: true;
  colSpan?: number;
}

export interface StudioDocument {
  schema: 3;
  /** الأقسام الجذرية مرتّبة. */
  nodes: StudioNode[];
  /** مصفوفة مرتّبة (≤40): ترتيبها هو أولوية التتابع في compileDocCss. */
  classes?: StudioClassDef[];
}

/**
 * عقدة مرجع رمز (C32/C38): مؤشّر عبر الحدود إلى studio_symbols. مفاتيحها
 * مغلقة (REF_NODE_KEYS) — لا children ولا style ولا classIds ولا query
 * ولا anim ولا cond ولا interactions **بنيويًّا**: السلوك والشكل يُحرَّران
 * في أصل الرمز ويسافران عبر التمديد (قانون النسخ بالانتشار في symbols.ts).
 */
export interface StudioSymbolRefNode {
  id: string;
  kind: 'symbol-ref';
  /** سلسلة رقمية → studio_symbols.id. */
  symbolId: string;
  /** bodyNodeId → propKey → value؛ مفاتيح tab:'content' فقط (OVERRIDABLE_KEYS). */
  overrides?: Record<string, Record<string, unknown>>;
  hidden?: Partial<Record<StudioDevice, boolean>>;
  slot?: NodeSlot;
}

/** العقدة العنصرية = شكل v2 (بلا kind) + حقول v3 السلوكية الثلاثة. */
export interface StudioElementNode {
  /** معرّف ثابت يولَّد عند الإدراج، فريد عبر الشجرة كاملها. */
  id: string;
  /** يجب أن يحلّ إلى مدخل سجلّ status:'exists' قابل للإدراج. */
  registryId: `tah/${string}`;
  /** قيم editableProps (مفاتيح PROP_SCHEMAS للمكوّن). */
  props: Record<string, unknown>;
  /** مجموعات فقط — الخادم يسقطه عن الحاويات. */
  query?: StudioQuery;
  hidden?: Partial<Record<StudioDevice, boolean>>;
  /** مدخلات الحاويات فقط؛ العمق ≤ MAX_DEPTH. فارغةً يُحذف الحقل (قانوني). */
  children?: StudioNode[];
  /** تجاوزات لكل جهاز — مفاتيح responsive:true فقط (RESPONSIVE_KEYS). */
  responsive?: Partial<Record<ResponsiveDevice, Record<string, unknown>>>;
  /** ذو معنى تحت أب flex/grid — يُبثّ inline على غلاف العقدة. */
  slot?: NodeSlot;
  /** أنماط العقدة (styles.ts) — تستهدف [data-studio-node="id"]. */
  style?: StudioStyleSheet;
  /** مراجع مرتّبة إلى doc.classes (≤8). */
  classIds?: string[];
  /** تفاعلات العقدة (≤ MAX_NODE_INTERACTIONS — actions.ts). */
  interactions?: NodeInteraction[];
  /** حركة الدخول من القوائم المغلقة (anim.ts). */
  anim?: NodeAnim;
  /** شروط الظهور «و» فقط (conditions.ts). */
  cond?: NodeConditions;
}

/** المميِّز: غياب kind = عقدة عنصرية — لا تغيير رجعيًّا على أي مستند v2. */
export type StudioNode = StudioElementNode | StudioSymbolRefNode;

export const isSymbolRef = (n: StudioNode): n is StudioSymbolRefNode =>
  (n as StudioSymbolRefNode).kind === 'symbol-ref';

/** أبناء عقدة — المراجع أوراق بنيويًّا (لا حقل children عليها). */
const childrenOf = (n: StudioNode): StudioNode[] | undefined => (n as StudioElementNode).children;

/** component من معرّف السجل tah/{component}.{ffii} — '' لغير الصالح. */
export function componentOf(registryId: string | undefined): string {
  if (typeof registryId !== 'string' || !registryId.startsWith('tah/')) return '';
  const dot = registryId.indexOf('.');
  return dot > 4 ? registryId.slice(4, dot) : '';
}

/** هل العقدة حاوية (يجوز لها children وتقبل الإدراج فيها)؟ مرجع الرمز ليس حاوية أبدًا. */
export function isContainerNode(node: Pick<StudioElementNode, 'registryId'> | StudioSymbolRefNode): boolean {
  return (CONTAINER_COMPONENTS as readonly string[]).includes(
    componentOf((node as Partial<StudioElementNode>).registryId),
  );
}

/**
 * ترحيل v1→v2→v3 — بلا فقد بديهيًّا: لا مستند v1/v2 يمكن أن يحمل أي حقل
 * جديد (TS لم يعرفها وNODE_KEYS_V2 في PHP كانت تسقطها)، فإعادة الوسم
 * `{...doc, schema:3}` كافية (C30). يُستدعى عند **كل** حافة تحميل في
 * العميل: تحميل المحرّر (مسوّدة ومنشور) · لقطة استرجاع localStorage قبل
 * مقارنة الاختلاف · مسار الزائر /pages/[slug] · الكانفس عند
 * INIT/DOC_REPLACE · مسارا القوالب والاستيراد (C23). الخادم لا يرحّل —
 * يقبل النسخ الثلاث ويخزّن كما وصله. ثابت التطبيق المتكرّر: مستند v3
 * يعود بمرجعه نفسه.
 */
export function migrateDocument(raw: unknown): StudioDocument {
  if (typeof raw !== 'object' || raw === null || !Array.isArray((raw as { nodes?: unknown }).nodes)) {
    throw new Error('مستند استوديو غير صالح البنية — لا حقل nodes مصفوفةً.');
  }
  const schema: unknown = (raw as { schema?: unknown }).schema;
  if (schema === 3) return raw as StudioDocument;
  if (schema === 2) return { ...(raw as object), schema: 3 } as StudioDocument;
  if (schema === 1) return { schema: 3, nodes: (raw as { nodes: StudioNode[] }).nodes };
  throw new Error(`نسخة مخطط استوديو غير معروفة: ${String(schema)}`);
}

/**
 * المشي الوحيد على الشجرة — يستعمله التطبيقان وresolve.ts (لا مشي ثانيًا
 * يُخترع). ترتيب pre-order بترتيب المستند؛ العمق يبدأ من 1 للجذور.
 * مراجع الرموز تُنتَج أوراقًا (لا children عليها بنيويًّا).
 */
export function* walkNodes(
  doc: Pick<StudioDocument, 'nodes'>,
): IterableIterator<{ node: StudioNode; parent: StudioNode | null; depth: number }> {
  function* visit(
    list: readonly StudioNode[],
    parent: StudioNode | null,
    depth: number,
  ): IterableIterator<{ node: StudioNode; parent: StudioNode | null; depth: number }> {
    for (const node of list) {
      yield { node, parent, depth };
      const kids = childrenOf(node);
      if (kids) yield* visit(kids, node, depth + 1);
    }
  }
  yield* visit(doc.nodes, null, 1);
}

/** عقدة بمعرّفها في أي عمق — null إن غابت. */
export function findNode(doc: Pick<StudioDocument, 'nodes'>, id: string): StudioNode | null {
  for (const { node } of walkNodes(doc)) if (node.id === id) return node;
  return null;
}

export type DocOp =
  | { op: 'set-prop'; nodeId: string; key: string; value: unknown }
  | { op: 'set-query'; nodeId: string; query: StudioElementNode['query'] }
  | { op: 'set-hidden'; nodeId: string; device: StudioDevice; hidden: boolean }
  /** parentId غائبًا/null = الجذر. العقدة قد تحمل شجرة فرعية كاملة. */
  | { op: 'insert-node'; parentId?: string | null; index: number; node: StudioNode }
  /** يزيل الشجرة الفرعية كاملة — عكسه إدراج واحد يعيدها بمكانها. */
  | { op: 'remove-node'; nodeId: string }
  /** toParentId حاضرًا (ولو null) = إعادة تعليق؛ غائبًا = ترتيب داخل الأب الحالي. */
  | { op: 'move-node'; nodeId: string; toParentId?: string | null; toIndex: number }
  /** slot غائبًا/فارغًا ⇒ يُحذف الحقل. */
  | { op: 'set-slot'; nodeId: string; slot?: NodeSlot }
  /** قوانين الحذف القانونية تعكس set-hidden. */
  | { op: 'set-responsive'; nodeId: string; device: ResponsiveDevice; key: string; value?: unknown }
  /** `state` هنا طبقةٌ: حالة أو نطاق جهاز — الموضع في الورقة واحد. */
  | { op: 'set-style'; nodeId: string; state: StyleLayer; key: string; value?: unknown }
  | { op: 'set-class-style'; classId: string; state: StyleLayer; key: string; value?: unknown }
  | { op: 'create-class'; index: number; def: StudioClassDef }
  | { op: 'rename-class'; classId: string; name: string }
  /** بلا أثر ما دام الصنف مطبَّقًا على أي عقدة (قانوني). */
  | { op: 'delete-class'; classId: string }
  | { op: 'apply-class'; nodeId: string; classId: string; index: number }
  | { op: 'detach-class'; nodeId: string; classId: string }
  /** تجاوز محتوى نسخة رمز — undefined يحذف؛ قوانين setNested تعكس set-responsive. */
  | { op: 'set-symbol-override'; nodeId: string; bodyNodeId: string; key: string; value?: unknown }
  /** المصفوفة كاملة؛ []/undefined يحذفان الحقل؛ >4 أو معرّفات مكرّرة ⇒ بلا أثر. */
  | { op: 'set-interactions'; nodeId: string; interactions?: NodeInteraction[] }
  /** undefined يحذف؛ stagger للحاويات فقط (يُجرَّد)؛ قيم خارج القوائم المغلقة بلا أثر. */
  | { op: 'set-anim'; nodeId: string; anim?: NodeAnim }
  /** cond.all فارغةً ⇒ حذف الحقل؛ أكثر من MAX_CONDITIONS بلا أثر. */
  | { op: 'set-cond'; nodeId: string; cond?: NodeConditions };

/*
 * مفاتيح الدمج في محرّر التاريخ (history.ts يبقى صفريّ التغيير — محايد
 * للعمليات): `interactions:{id}` · `set-anim:{id}` · `set-cond:{id}` ·
 * `set-symbol-override:{id}:{bodyId}:{key}` — على نسق set-prop القائم.
 *
 * فكّ الارتباط **ليس عملية جديدة**: إيداعة واحدة بعمليتين
 * [{op:'remove-node', nodeId:refId}, {op:'insert-node', parentId, index,
 * node: expandedSubtree}] حيث الشجرة من expandSymbolBody نفسها التي
 * يستعملها مسار التصيير (لا مُمدِّد ثانيًا — قانون C23 مطبَّقًا على
 * التمديد). إيداعة واحدة = خطوة تاريخ واحدة = تراجع صادق يعيد المرجع.
 *
 * قوانين الشكل القانوني (تجعل التطبيق ثم العكس تطابقًا بنيويًّا تامًّا):
 *   · عمليات العقد العنصرية (set-prop/query/responsive/style/الأصناف/
 *     interactions/anim/cond) على مرجع رمز بنيويًّا بلا أثر وبلا عكس؛
 *     set-hidden/set-slot تعملان على الصنفين (عين النسخة وموضعها).
 *   · set-symbol-override على غير المرجع بلا أثر؛ undefined يحذف المفتاح،
 *     وسجلّ الجسم الفارغ يُحذف، وإن فرغ overrides حُذف الحقل؛ إضافة جسمٍ
 *     جديد فوق MAX_OVERRIDE_NODES بلا أثر.
 *   · set-interactions مصفوفةً كاملة: []/undefined يحذفان الحقل؛ أكثر من
 *     MAX_NODE_INTERACTIONS أو بمعرّفات مكرّرة ⇒ بلا أثر (حارس صامت).
 *   · set-anim: العضوية الحرفية في قوائم anim.ts وإلا بلا أثر؛ stagger
 *     يُجرَّد عن غير الحاويات؛ على عقدة popup بلا أثر (دخولها لمكوّنها).
 *   · set-cond: cond.all فارغةً تحذف الحقل؛ > MAX_CONDITIONS بلا أثر.
 *   · insert-node/move-node حرّاس v3 الصامتون: مرجع أعمق من MAX_REF_DEPTH ·
 *     مرجع حادي عشر (MAX_REFS_PER_PAGE) · منبثقة تحت الجذر · مرجع يحمل
 *     children — كلها بلا أثر ولا عكس.
 *   · set-prop بقيمة undefined يحذف المفتاح من props بدل تخزين undefined.
 *   · set-query بقيمة undefined يحذف الحقل query كاملًا.
 *   · set-hidden بقيمة false يحذف مفتاح الجهاز، وإن فرغ hidden حُذف الحقل.
 *   · set-slot بقيمة فارغة يحذف الحقل؛ colSpan يُقصّ إلى 1..12.
 *   · set-responsive/set-style بقيمة undefined يحذفان المفتاح؛ سجلّ الجهاز
 *     أو الحالة الفارغ يُحذف، وإن فرغ الحقل كله حُذف (style الصنف يبقى {}).
 *   · children فارغةً تُحذف؛ classes فارغةً تُحذف؛ classIds فارغةً تُحذف.
 *   · حرّاس بلا أثر: معرّف مكرّر في أي عمق · أب غير حاوية · تجاوز العمق
 *     (عمق الهدف + ارتفاع الشجرة الفرعية) · نقل إلى الذات/سليل (حلقة) ·
 *     قيمة مطابقة قانونيًّا · حذف صنف مطبَّق · تطبيق صنف غير معرَّف.
 *   · العمليات البنيوية العقيمة تُعاد بلا نسخ ولا يُنتج لها عكس؛ عمليات
 *     set على هدف موجود تُنتج عكسًا دائمًا (ولو تطابقت القيمة).
 */

const clamp = (n: number, lo: number, hi: number) => Math.min(Math.max(n, lo), hi);

/** استبدال عنصر واحد بنسخ ضحل — lib هنا ES2022 فلا Array.prototype.with. */
function replaceAt<T>(list: T[], i: number, item: T): T[] {
  const next = list.slice();
  next[i] = item;
  return next;
}

/** مساواة بنيوية عميقة لقيم JSON (كائنات الأنماط وslot). */
function deepEqual(a: unknown, b: unknown): boolean {
  if (Object.is(a, b)) return true;
  if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null) return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  if (Array.isArray(a) && Array.isArray(b)) {
    return a.length === b.length && a.every((v, i) => deepEqual(v, b[i]));
  }
  const ka = Object.keys(a);
  const kb = Object.keys(b as object);
  return (
    ka.length === kb.length &&
    ka.every(
      (k) =>
        Object.prototype.hasOwnProperty.call(b, k) &&
        deepEqual((a as Record<string, unknown>)[k], (b as Record<string, unknown>)[k]),
    )
  );
}

/** عقدة بمعرّفها داخل قائمة (بحث شجري). */
function findIn(list: readonly StudioNode[], id: string): StudioNode | null {
  for (const n of list) {
    if (n.id === id) return n;
    const kids = childrenOf(n);
    if (kids) {
      const hit = findIn(kids, id);
      if (hit) return hit;
    }
  }
  return null;
}

/** موضع عقدة: أبوها (null للجذر) وفهرسها بين إخوتها. */
function locate(
  list: readonly StudioNode[],
  id: string,
  parentId: string | null = null,
): { parentId: string | null; index: number } | null {
  for (let i = 0; i < list.length; i++) {
    const n = list[i] as StudioNode;
    if (n.id === id) return { parentId, index: i };
    const kids = childrenOf(n);
    if (kids) {
      const hit = locate(kids, id, n.id);
      if (hit) return hit;
    }
  }
  return null;
}

/** عمق عقدة (الجذر 1) — ‎-1 إن غابت. */
function depthOf(list: readonly StudioNode[], id: string, depth = 1): number {
  for (const n of list) {
    if (n.id === id) return depth;
    const kids = childrenOf(n);
    if (kids) {
      const d = depthOf(kids, id, depth + 1);
      if (d !== -1) return d;
    }
  }
  return -1;
}

/** ارتفاع شجرة فرعية (عقدة بلا أبناء = 1). */
function subtreeHeight(node: StudioNode): number {
  let h = 1;
  for (const c of childrenOf(node) ?? []) h = Math.max(h, 1 + subtreeHeight(c));
  return h;
}

/** كل معرّفات شجرة فرعية بترتيب pre-order. */
function collectIds(node: StudioNode, into: string[]): void {
  into.push(node.id);
  for (const c of childrenOf(node) ?? []) collectIds(c, into);
}

/**
 * إحصاءات حرّاس v3 لشجرة فرعية: أعمق مرجع رمز وأعمق منبثقة (نسبيًّا من 1)
 * وعدد المراجع وهل يحمل مرجعٌ children (فاسد بنيويًّا — C38).
 */
function placementStats(node: StudioNode, depth = 1): {
  refCount: number;
  maxRefDepth: number;
  maxPopupDepth: number;
  refWithChildren: boolean;
} {
  if (isSymbolRef(node)) {
    return {
      refCount: 1,
      maxRefDepth: depth,
      maxPopupDepth: 0,
      refWithChildren: (node as { children?: unknown }).children !== undefined,
    };
  }
  const acc = {
    refCount: 0,
    maxRefDepth: 0,
    maxPopupDepth: componentOf(node.registryId) === 'popup' ? depth : 0,
    refWithChildren: false,
  };
  for (const child of node.children ?? []) {
    const sub = placementStats(child, depth + 1);
    acc.refCount += sub.refCount;
    acc.maxRefDepth = Math.max(acc.maxRefDepth, sub.maxRefDepth);
    acc.maxPopupDepth = Math.max(acc.maxPopupDepth, sub.maxPopupDepth);
    acc.refWithChildren = acc.refWithChildren || sub.refWithChildren;
  }
  return acc;
}

/** حارس موضع v3 مشترك للإدراج وإعادة التعليق: منبثقة جذرية فقط · مرجع لا يغوص تحت MAX_REF_DEPTH. */
function violatesPlacement(stats: ReturnType<typeof placementStats>, baseDepth: number): boolean {
  if (stats.refWithChildren) return true;
  if (stats.refCount > 0 && baseDepth + stats.maxRefDepth - 1 > MAX_REF_DEPTH) return true;
  if (stats.maxPopupDepth > 0 && baseDepth + stats.maxPopupDepth - 1 > 1) return true;
  return false;
}

/** عدد مراجع الرموز في المستند كله (ميزانية MAX_REFS_PER_PAGE). */
function countRefs(nodes: readonly StudioNode[]): number {
  let count = 0;
  for (const { node } of walkNodes({ nodes: nodes as StudioNode[] })) if (isSymbolRef(node)) count++;
  return count;
}

/**
 * تحديث عقدة بمعرّفها في أي عمق بمشاركة بنيوية: يُنسخ العمود المؤدي إليها
 * فقط. fn تعيد المرجع نفسه = لا تغيير في الشجرة كلها.
 */
function mapNode(list: StudioNode[], id: string, fn: (node: StudioNode) => StudioNode): StudioNode[] {
  for (let i = 0; i < list.length; i++) {
    const n = list[i] as StudioNode;
    if (n.id === id) {
      const next = fn(n);
      return next === n ? list : replaceAt(list, i, next);
    }
    const kids = childrenOf(n);
    if (kids) {
      const children = mapNode(kids, id, fn);
      if (children !== kids) return replaceAt(list, i, { ...(n as StudioElementNode), children });
    }
  }
  return list;
}

/** إزالة عقدة (بشجرتها) من أي عمق — تعيد القائمة الجديدة وموضع المزال. */
function removeById(
  list: StudioNode[],
  id: string,
  parentId: string | null = null,
): { list: StudioNode[]; hit: { node: StudioNode; parentId: string | null; index: number } | null } {
  const i = list.findIndex((n) => n.id === id);
  if (i !== -1) {
    return {
      list: [...list.slice(0, i), ...list.slice(i + 1)],
      hit: { node: list[i] as StudioNode, parentId, index: i },
    };
  }
  for (let j = 0; j < list.length; j++) {
    const n = list[j] as StudioNode;
    const kids = childrenOf(n);
    if (!kids) continue;
    const r = removeById(kids, id, n.id);
    if (r.hit) {
      const next = { ...(n as StudioElementNode) };
      if (r.list.length === 0) delete next.children;
      else next.children = r.list;
      return { list: replaceAt(list, j, next), hit: r.hit };
    }
  }
  return { list, hit: null };
}

/**
 * إدراج تحت أب (null = الجذر) بفهرس مقصوص — null إن غاب الأب أو لم يكن
 * حاوية (الحارس البنيوي، لا استثناء).
 */
function insertUnder(
  list: StudioNode[],
  parentId: string | null,
  index: number,
  node: StudioNode,
): StudioNode[] | null {
  if (parentId === null) {
    const at = clamp(Math.trunc(index), 0, list.length);
    return [...list.slice(0, at), node, ...list.slice(at)];
  }
  for (let j = 0; j < list.length; j++) {
    const n = list[j] as StudioNode;
    if (n.id === parentId) {
      if (!isContainerNode(n)) return null;
      const parent = n as StudioElementNode;
      const kids = parent.children ?? [];
      const at = clamp(Math.trunc(index), 0, kids.length);
      return replaceAt(list, j, { ...parent, children: [...kids.slice(0, at), node, ...kids.slice(at)] });
    }
    const kids = childrenOf(n);
    if (kids) {
      const sub = insertUnder(kids, parentId, index, node);
      if (sub) return replaceAt(list, j, { ...(n as StudioElementNode), children: sub });
    }
  }
  return null;
}

/**
 * الشكل القانوني لحركة العقدة: قيمة خارج القوائم المغلقة ⇒ null (حارس
 * صامت — لا تقريب أبدًا: 350ms ليست 320ms)؛ stagger يُجرَّد عن غير
 * الحاويات (كما يُقصّ colSpan)؛ undefined = حذف الحقل.
 */
function canonicalAnim(anim: NodeAnim | undefined, container: boolean): NodeAnim | undefined | null {
  if (anim === undefined) return undefined;
  if (typeof anim !== 'object' || anim === null) return null;
  if (!(ANIM_ENTRANCES as readonly string[]).includes(anim.entrance)) return null;
  if (!(ANIM_TRIGGERS as readonly string[]).includes(anim.trigger)) return null;
  if (anim.duration !== undefined && !(ANIM_DURATIONS as readonly number[]).includes(anim.duration)) return null;
  if (anim.delay !== undefined && !(ANIM_DELAYS as readonly number[]).includes(anim.delay)) return null;
  if (anim.easing !== undefined && !(ANIM_EASINGS as readonly string[]).includes(anim.easing)) return null;
  const out: NodeAnim = { entrance: anim.entrance, trigger: anim.trigger };
  if (anim.duration !== undefined) out.duration = anim.duration;
  if (anim.delay !== undefined) out.delay = anim.delay;
  if (anim.easing !== undefined) out.easing = anim.easing;
  if (anim.stagger === true && container) out.stagger = true;
  return out;
}

/** الشكل القانوني لـslot — undefined إن فرغ. */
function canonicalSlot(slot: NodeSlot | undefined): NodeSlot | undefined {
  if (typeof slot !== 'object' || slot === null) return undefined;
  const out: NodeSlot = {};
  if (slot.grow === true) out.grow = true;
  if (typeof slot.colSpan === 'number' && Number.isFinite(slot.colSpan)) {
    out.colSpan = clamp(Math.trunc(slot.colSpan), SLOT_COL_SPAN_MIN, SLOT_COL_SPAN_MAX);
  }
  return out.grow === true || out.colSpan !== undefined ? out : undefined;
}

/** ضبط مفتاح داخل سجلّ متداخل مع قوانين الحذف (يعكس set-hidden). */
function setNested<R extends Record<string, Record<string, unknown>>>(
  outer: R | undefined,
  section: string,
  key: string,
  value: unknown,
  equal: (a: unknown, b: unknown) => boolean,
): { changed: boolean; next: R | undefined } {
  const record = outer?.[section];
  if (value === undefined) {
    if (!record || !Object.prototype.hasOwnProperty.call(record, key)) return { changed: false, next: outer };
    const nextRecord = { ...record };
    delete nextRecord[key];
    const next = { ...outer } as R;
    if (Object.keys(nextRecord).length === 0) delete next[section];
    else (next as Record<string, Record<string, unknown>>)[section] = nextRecord;
    return { changed: true, next: Object.keys(next).length === 0 ? undefined : next };
  }
  if (record && Object.prototype.hasOwnProperty.call(record, key) && equal(record[key], value)) {
    return { changed: false, next: outer };
  }
  const next = { ...outer } as R;
  (next as Record<string, Record<string, unknown>>)[section] = { ...record, [key]: value };
  return { changed: true, next };
}

type State = [nodes: StudioNode[], classes: StudioClassDef[] | undefined];

function applyOne(nodes: StudioNode[], classes: StudioClassDef[] | undefined, op: DocOp): State {
  const unchanged: State = [nodes, classes];
  switch (op.op) {
    case 'set-prop': {
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (isSymbolRef(node)) return node; // المحتوى داخل النسخة عبر set-symbol-override حصرًا
        const has = Object.prototype.hasOwnProperty.call(node.props, op.key);
        if (op.value === undefined) {
          if (!has) return node;
          const props = { ...node.props };
          delete props[op.key];
          return { ...node, props };
        }
        if (has && Object.is(node.props[op.key], op.value)) return node;
        return { ...node, props: { ...node.props, [op.key]: op.value } };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'set-query': {
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (isSymbolRef(node)) return node;
        if (op.query === undefined) {
          if (node.query === undefined) return node;
          const bare = { ...node };
          delete bare.query;
          return bare;
        }
        return { ...node, query: op.query };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'set-hidden': {
      const next = mapNode(nodes, op.nodeId, (node) => {
        const cur = node.hidden?.[op.device] === true;
        if (cur === op.hidden) return node;
        const hidden: Partial<Record<StudioDevice, boolean>> = { ...node.hidden };
        if (op.hidden) hidden[op.device] = true;
        else delete hidden[op.device];
        if (Object.keys(hidden).length === 0) {
          const bare = { ...node };
          delete bare.hidden;
          return bare;
        }
        return { ...node, hidden };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'insert-node': {
      const ids: string[] = [];
      collectIds(op.node, ids);
      const seen = new Set<string>();
      for (const id of ids) {
        if (seen.has(id) || findIn(nodes, id)) return unchanged; // معرّف مكرّر في أي عمق
        seen.add(id);
      }
      const parentId = op.parentId ?? null;
      if (parentId !== null) {
        const parent = findIn(nodes, parentId);
        if (!parent || !isContainerNode(parent)) return unchanged;
        if (depthOf(nodes, parentId) + subtreeHeight(op.node) > MAX_DEPTH) return unchanged;
      } else if (subtreeHeight(op.node) > MAX_DEPTH) {
        return unchanged;
      }
      // حرّاس v3 الصامتون: موضع المراجع والمنبثقات + ميزانية المراجع.
      const stats = placementStats(op.node);
      const baseDepth = parentId === null ? 1 : depthOf(nodes, parentId) + 1;
      if (violatesPlacement(stats, baseDepth)) return unchanged;
      if (stats.refCount > 0 && countRefs(nodes) + stats.refCount > MAX_REFS_PER_PAGE) return unchanged;
      const next = insertUnder(nodes, parentId, op.index, op.node);
      return next === null ? unchanged : [next, classes];
    }

    case 'remove-node': {
      const r = removeById(nodes, op.nodeId);
      return r.hit === null ? unchanged : [r.list, classes];
    }

    case 'move-node': {
      const removed = removeById(nodes, op.nodeId);
      if (removed.hit === null) return unchanged;
      const { node, parentId: fromParent, index: fromIndex } = removed.hit;
      const toParent = op.toParentId === undefined ? fromParent : op.toParentId;
      if (toParent === op.nodeId) return unchanged; // نقل إلى الذات

      if (toParent === fromParent) {
        // دلالة v1 داخل قائمة الإخوة نفسها — العمق لا يتغيّر فلا حرّاس v3.
        const siblings =
          fromParent === null ? nodes : childrenOf(findIn(nodes, fromParent) as StudioNode) ?? [];
        if (siblings.length < 2) return unchanged;
        const to = clamp(Math.trunc(op.toIndex), 0, siblings.length - 1);
        if (to === fromIndex) return unchanged;
        const next = insertUnder(removed.list, fromParent, to, node);
        return next === null ? unchanged : [next, classes];
      }

      if (toParent !== null) {
        // البحث في الشجرة بعد الإزالة: سليل المنقول أُزيل معه ⇒ حارس الحلقة.
        const parent = findIn(removed.list, toParent);
        if (!parent || !isContainerNode(parent)) return unchanged;
        if (depthOf(removed.list, toParent) + subtreeHeight(node) > MAX_DEPTH) return unchanged;
      } else if (subtreeHeight(node) > MAX_DEPTH) {
        return unchanged;
      }
      // حرّاس v3: إعادة التعليق إدراجٌ مقنَّع — منبثقة جذرية فقط ومرجع لا يغوص.
      const baseDepth = toParent === null ? 1 : depthOf(removed.list, toParent) + 1;
      if (violatesPlacement(placementStats(node), baseDepth)) return unchanged;
      const next = insertUnder(removed.list, toParent, op.toIndex, node);
      return next === null ? unchanged : [next, classes];
    }

    case 'set-slot': {
      const clean = canonicalSlot(op.slot);
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (clean === undefined) {
          if (node.slot === undefined) return node;
          const bare = { ...node };
          delete bare.slot;
          return bare;
        }
        if (node.slot !== undefined && deepEqual(node.slot, clean)) return node;
        return { ...node, slot: clean };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'set-responsive': {
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (isSymbolRef(node)) return node;
        const r = setNested(
          node.responsive as Record<string, Record<string, unknown>> | undefined,
          op.device,
          op.key,
          op.value,
          Object.is,
        );
        if (!r.changed) return node;
        if (r.next === undefined) {
          const bare = { ...node };
          delete bare.responsive;
          return bare;
        }
        return { ...node, responsive: r.next as StudioElementNode['responsive'] };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'set-style': {
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (isSymbolRef(node)) return node;
        const r = setNested(
          node.style as Record<string, Record<string, unknown>> | undefined,
          op.state,
          op.key,
          op.value,
          deepEqual,
        );
        if (!r.changed) return node;
        if (r.next === undefined) {
          const bare = { ...node };
          delete bare.style;
          return bare;
        }
        return { ...node, style: r.next as StudioStyleSheet };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'set-class-style': {
      const list = classes ?? [];
      const i = list.findIndex((c) => c.id === op.classId);
      const def = list[i];
      if (!def) return unchanged;
      const r = setNested(
        def.style as Record<string, Record<string, unknown>>,
        op.state,
        op.key,
        op.value,
        deepEqual,
      );
      if (!r.changed) return unchanged;
      // style حقل مطلوب على الصنف — يبقى {} إن فرغ (شكله القانوني).
      const style = (r.next ?? {}) as StudioStyleSheet;
      return [nodes, replaceAt(list, i, { ...def, style })];
    }

    case 'create-class': {
      const list = classes ?? [];
      if (list.some((c) => c.id === op.def.id)) return unchanged;
      const at = clamp(Math.trunc(op.index), 0, list.length);
      return [nodes, [...list.slice(0, at), op.def, ...list.slice(at)]];
    }

    case 'rename-class': {
      const list = classes ?? [];
      const i = list.findIndex((c) => c.id === op.classId);
      const def = list[i];
      if (!def || def.name === op.name) return unchanged;
      return [nodes, replaceAt(list, i, { ...def, name: op.name })];
    }

    case 'delete-class': {
      const list = classes ?? [];
      const i = list.findIndex((c) => c.id === op.classId);
      if (i === -1) return unchanged;
      for (const { node } of walkNodes({ nodes })) {
        if (!isSymbolRef(node) && node.classIds?.includes(op.classId)) return unchanged; // مطبَّق ⇒ بلا أثر
      }
      const next = [...list.slice(0, i), ...list.slice(i + 1)];
      return [nodes, next.length === 0 ? undefined : next];
    }

    case 'apply-class': {
      if (!(classes ?? []).some((c) => c.id === op.classId)) return unchanged; // لا مراجع معلّقة
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (isSymbolRef(node)) return node; // أصناف الصفحة لا تسافر مع الرمز (C38)
        const cur = node.classIds ?? [];
        if (cur.includes(op.classId)) return node;
        const at = clamp(Math.trunc(op.index), 0, cur.length);
        return { ...node, classIds: [...cur.slice(0, at), op.classId, ...cur.slice(at)] };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'detach-class': {
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (isSymbolRef(node)) return node;
        const cur = node.classIds;
        if (!cur || !cur.includes(op.classId)) return node;
        const left = cur.filter((id) => id !== op.classId);
        if (left.length === 0) {
          const bare = { ...node };
          delete bare.classIds;
          return bare;
        }
        return { ...node, classIds: left };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'set-symbol-override': {
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (!isSymbolRef(node)) return node; // التجاوز لمراجع الرموز حصرًا
        if (
          op.value !== undefined &&
          node.overrides?.[op.bodyNodeId] === undefined &&
          Object.keys(node.overrides ?? {}).length >= MAX_OVERRIDE_NODES
        ) {
          return node; // ميزانية أجسام التجاوز — حارس صامت
        }
        const r = setNested(node.overrides, op.bodyNodeId, op.key, op.value, deepEqual);
        if (!r.changed) return node;
        if (r.next === undefined) {
          const bare = { ...node };
          delete bare.overrides;
          return bare;
        }
        return { ...node, overrides: r.next };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'set-interactions': {
      const clean = op.interactions === undefined || op.interactions.length === 0 ? undefined : op.interactions;
      if (clean !== undefined) {
        if (clean.length > MAX_NODE_INTERACTIONS) return unchanged;
        if (new Set(clean.map((i) => i.id)).size !== clean.length) return unchanged; // معرّفات فريدة
      }
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (isSymbolRef(node)) return node; // السلوك يُحرَّر في أصل الرمز (C38)
        if (clean === undefined) {
          if (node.interactions === undefined) return node;
          const bare = { ...node };
          delete bare.interactions;
          return bare;
        }
        if (node.interactions !== undefined && deepEqual(node.interactions, clean)) return node;
        return { ...node, interactions: clean };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'set-anim': {
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (isSymbolRef(node)) return node;
        if (componentOf(node.registryId) === 'popup') return node; // لدخول المنبثقة مكوّنها المحروس
        const clean = canonicalAnim(op.anim, isContainerNode(node));
        if (clean === null) return node; // خارج القوائم المغلقة — حارس صامت
        if (clean === undefined) {
          if (node.anim === undefined) return node;
          const bare = { ...node };
          delete bare.anim;
          return bare;
        }
        if (node.anim !== undefined && deepEqual(node.anim, clean)) return node;
        return { ...node, anim: clean };
      });
      return next === nodes ? unchanged : [next, classes];
    }

    case 'set-cond': {
      const clean = op.cond === undefined || op.cond.all.length === 0 ? undefined : op.cond;
      if (clean !== undefined && clean.all.length > MAX_CONDITIONS) return unchanged;
      const next = mapNode(nodes, op.nodeId, (node) => {
        if (isSymbolRef(node)) return node;
        if (clean === undefined) {
          if (node.cond === undefined) return node;
          const bare = { ...node };
          delete bare.cond;
          return bare;
        }
        if (node.cond !== undefined && deepEqual(node.cond, clean)) return node;
        return { ...node, cond: clean };
      });
      return next === nodes ? unchanged : [next, classes];
    }
  }
}

/**
 * تطبيق قائمة عمليات — دالة نقية بمشاركة بنيوية: لا يُنسخ إلا العمود
 * المؤدي إلى العقدة المتأثرة، وإن لم تؤثّر أي عملية عاد المستند نفسه
 * بالمرجع.
 */
export function applyOps(doc: StudioDocument, ops: readonly DocOp[]): StudioDocument {
  let nodes = doc.nodes;
  let classes = doc.classes;
  for (const op of ops) [nodes, classes] = applyOne(nodes, classes, op);
  if (nodes === doc.nodes && classes === doc.classes) return doc;
  const next: StudioDocument = { ...doc, nodes };
  if (classes === undefined) delete next.classes;
  else next.classes = classes;
  return next;
}

/** عكس عملية واحدة محسوبًا على حالة ما قبلها. */
function invertOne(
  nodes: StudioNode[],
  classes: StudioClassDef[] | undefined,
  op: DocOp,
  changed: boolean,
): DocOp | null {
  switch (op.op) {
    case 'set-prop': {
      const node = findIn(nodes, op.nodeId);
      if (!node || isSymbolRef(node)) return null; // على مرجع رمز: بلا أثر بنيويًّا ⇒ بلا عكس
      const has = Object.prototype.hasOwnProperty.call(node.props, op.key);
      return { op: 'set-prop', nodeId: op.nodeId, key: op.key, value: has ? node.props[op.key] : undefined };
    }
    case 'set-query': {
      const node = findIn(nodes, op.nodeId);
      if (!node || isSymbolRef(node)) return null;
      return { op: 'set-query', nodeId: op.nodeId, query: node.query };
    }
    case 'set-hidden': {
      const node = findIn(nodes, op.nodeId);
      if (!node) return null;
      return { op: 'set-hidden', nodeId: op.nodeId, device: op.device, hidden: node.hidden?.[op.device] === true };
    }
    case 'set-slot': {
      const node = findIn(nodes, op.nodeId);
      if (!node) return null;
      return { op: 'set-slot', nodeId: op.nodeId, slot: node.slot };
    }
    case 'set-responsive': {
      const node = findIn(nodes, op.nodeId);
      if (!node || isSymbolRef(node)) return null;
      const record = node.responsive?.[op.device];
      const has = record !== undefined && Object.prototype.hasOwnProperty.call(record, op.key);
      return {
        op: 'set-responsive',
        nodeId: op.nodeId,
        device: op.device,
        key: op.key,
        value: has ? record[op.key] : undefined,
      };
    }
    case 'set-style': {
      const node = findIn(nodes, op.nodeId);
      if (!node || isSymbolRef(node)) return null;
      const record = node.style?.[op.state] as Record<string, unknown> | undefined;
      const has = record !== undefined && Object.prototype.hasOwnProperty.call(record, op.key);
      return { op: 'set-style', nodeId: op.nodeId, state: op.state, key: op.key, value: has ? record[op.key] : undefined };
    }
    case 'set-class-style': {
      const def = (classes ?? []).find((c) => c.id === op.classId);
      if (!def) return null;
      const record = def.style[op.state] as Record<string, unknown> | undefined;
      const has = record !== undefined && Object.prototype.hasOwnProperty.call(record, op.key);
      return {
        op: 'set-class-style',
        classId: op.classId,
        state: op.state,
        key: op.key,
        value: has ? record[op.key] : undefined,
      };
    }
    case 'rename-class': {
      const def = (classes ?? []).find((c) => c.id === op.classId);
      if (!def) return null;
      return { op: 'rename-class', classId: op.classId, name: def.name };
    }
    case 'insert-node':
      return changed ? { op: 'remove-node', nodeId: op.node.id } : null;
    case 'remove-node': {
      if (!changed) return null;
      const loc = locate(nodes, op.nodeId) as { parentId: string | null; index: number };
      const node = findIn(nodes, op.nodeId) as StudioNode;
      return { op: 'insert-node', parentId: loc.parentId, index: loc.index, node };
    }
    case 'move-node': {
      if (!changed) return null;
      const loc = locate(nodes, op.nodeId) as { parentId: string | null; index: number };
      return { op: 'move-node', nodeId: op.nodeId, toParentId: loc.parentId, toIndex: loc.index };
    }
    case 'create-class':
      return changed ? { op: 'delete-class', classId: op.def.id } : null;
    case 'delete-class': {
      if (!changed) return null;
      const list = classes as StudioClassDef[];
      const index = list.findIndex((c) => c.id === op.classId);
      return { op: 'create-class', index, def: list[index] as StudioClassDef };
    }
    case 'apply-class':
      return changed ? { op: 'detach-class', nodeId: op.nodeId, classId: op.classId } : null;
    case 'detach-class': {
      if (!changed) return null;
      const node = findIn(nodes, op.nodeId) as StudioElementNode;
      const index = (node.classIds as string[]).indexOf(op.classId);
      return { op: 'apply-class', nodeId: op.nodeId, classId: op.classId, index };
    }
    case 'set-symbol-override': {
      const node = findIn(nodes, op.nodeId);
      if (!node || !isSymbolRef(node)) return null;
      const record = node.overrides?.[op.bodyNodeId];
      const has = record !== undefined && Object.prototype.hasOwnProperty.call(record, op.key);
      return {
        op: 'set-symbol-override',
        nodeId: op.nodeId,
        bodyNodeId: op.bodyNodeId,
        key: op.key,
        value: has ? record[op.key] : undefined,
      };
    }
    case 'set-interactions': {
      const node = findIn(nodes, op.nodeId);
      if (!node || isSymbolRef(node)) return null;
      return { op: 'set-interactions', nodeId: op.nodeId, interactions: node.interactions };
    }
    case 'set-anim': {
      const node = findIn(nodes, op.nodeId);
      if (!node || isSymbolRef(node)) return null;
      return { op: 'set-anim', nodeId: op.nodeId, anim: node.anim };
    }
    case 'set-cond': {
      const node = findIn(nodes, op.nodeId);
      if (!node || isSymbolRef(node)) return null;
      return { op: 'set-cond', nodeId: op.nodeId, cond: node.cond };
    }
  }
}

/**
 * عكس قائمة عمليات، محسوبًا على الحالة *قبل* كل عملية بترتيبها ثم يُقلب
 * الناتج: `applyOps(applyOps(doc, ops), invertOps(doc, ops))` يطابق `doc`
 * بنيويًّا (وفق قوانين الشكل القانوني أعلاه). عمود undo/redo في المحرّر
 * يقوم عليها (قرار C14)؛ عكس remove-node إدراجٌ واحد يعيد الشجرة الفرعية
 * كاملة — حذف حاوية خطوة تراجع واحدة صادقة.
 */
export function invertOps(doc: StudioDocument, ops: readonly DocOp[]): DocOp[] {
  const inverses: DocOp[] = [];
  let nodes = doc.nodes;
  let classes = doc.classes;
  for (const op of ops) {
    const [nextNodes, nextClasses] = applyOne(nodes, classes, op);
    const changed = nextNodes !== nodes || nextClasses !== classes;
    const inv = invertOne(nodes, classes, op, changed);
    if (inv) inverses.push(inv);
    nodes = nextNodes;
    classes = nextClasses;
  }
  return inverses.reverse();
}
