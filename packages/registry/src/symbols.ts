// UNIT: SYMBOLS
// FILE: symbols.ts
// STYLE: none
// PARENT: BRIDGE, REGISTRY-INDEX
// USES: DOCUMENT, PROPS
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * تمديد الرموز — دالة نقية بلا DOM (STUDIO-PHASE3-CONTRACT §2.2، قرار C32:
 * الحلّ وقت التصيير). الصفحات تحتفظ بعقد `symbol-ref`، وGET العامة تضمّن
 * خريطة أجسام **منشورة**؛ `resolveSymbols` الواحدة تُستدعى عند حافتين
 * حصرًا (مسار الزائر + الكانفس) — مُمدِّد واحد لا ثانٍ (قانون C23 مطبَّقًا
 * على التمديد؛ ومنه تُبنى إيماءة فكّ الارتباط بعمليتين).
 *
 * القوانين (كلٌّ مختبَر خاصيةً):
 *   ١) معرّفات مركّبة `${refId}__${bodyId}` — النصفان ≤30 ⇒ المركّب ≤62
 *      يطابق STUDIO_ID_PATTERN؛ compileDocCss وdata-studio-node يعملان بلا مسّ.
 *   ٢) جذر الجسم الواحد يحمل hidden/slot المرجعِ (المرجع يغلب حين يعرّفهما).
 *   ٣) دمج التجاوزات: {...props, ...validEntries} — الصالح مفتاح ∈
 *      OVERRIDABLE_KEYS[component]؛ استبدال قيمة كاملة (عقلية set-prop).
 *   ٤) **قانون النسخ بالانتشار (C38 — حجر التشغيل البيني)**: كل حقول عقدة
 *      الجسم الأخرى — style وclassIds وinteractions وanim وcond وأي حقل
 *      **مستقبلي** — تعبر حرفيًّا عبر {...node}؛ التمديد لا يعدّد قائمة سماح.
 *   ٥) رمز غائب/غير منشور ⇒ null — غياب صادق للزائر؛ الكانفس يرسم بديلًا
 *      موسومًا («رمز غير منشور/محذوف»).
 *   ٦) ثابت العمق: تمديد مستند مطبَّع لا يتجاوز MAX_DEPTH
 *      (MAX_REF_DEPTH + SYMBOL_MAX_DEPTH - 1 = MAX_DEPTH — حساب خالص).
 *   ٧) لا مراجع داخل الأجسام ⇒ ممرّ واحد بلا حلقات بنيويًّا (والمخالف
 *      يُسقَط حزامَ أمان فوق الـnormalizer).
 *   ٨) doc.classes تعبر كما هي؛ compileDocCss يجري على المستند المتمدّد.
 */
import {
  componentOf,
  isSymbolRef,
  walkNodes,
  type StudioDocument,
  type StudioElementNode,
  type StudioNode,
  type StudioSymbolRefNode,
} from './document.ts';
import { OVERRIDABLE_KEYS } from './props.ts';

/** خريطة الرموز المضمّنة — أجسام **منشورة** فقط (GET العامة وINIT/SYMBOLS_REPLACE). */
export interface SymbolsMap {
  [symbolId: string]: { name: string; document: StudioDocument };
}

/** نسخ عقدة جسم تكراريًّا بمعرّف مركّب وتجاوزات المحتوى — انتشارٌ لا تعداد. */
function cloneBodyNode(
  refId: string,
  node: StudioNode,
  overrides: StudioSymbolRefNode['overrides'],
): StudioElementNode | null {
  if (isSymbolRef(node)) return null; // «لا رموز داخل الرموز» — حزام فوق الـnormalizer
  const out: StudioElementNode = { ...node, id: `${refId}__${node.id}` };
  const entries = overrides?.[node.id];
  if (entries !== undefined) {
    const allowed =
      (OVERRIDABLE_KEYS as Record<string, readonly string[] | undefined>)[componentOf(node.registryId)] ?? [];
    let props: Record<string, unknown> | null = null;
    for (const [key, value] of Object.entries(entries)) {
      if (allowed.includes(key)) {
        props = props ?? { ...node.props };
        props[key] = value; // استبدال قيمة كاملة — عقلية set-prop؛ المجهول يُتجاهَل (preflight يحذّر)
      }
    }
    if (props !== null) out.props = props;
  }
  if (node.children !== undefined) {
    const children = node.children
      .map((child) => cloneBodyNode(refId, child, overrides))
      .filter((child): child is StudioElementNode => child !== null);
    if (children.length > 0) out.children = children;
    else delete out.children;
  }
  return out;
}

/**
 * تمديد مرجع واحد على جسم رمز: الجسم جذرٌ واحد قانونًا (normalize-symbol)
 * — ما خالفه فاسد ⇒ null (غياب صادق، لا تخمين).
 */
export function expandSymbolBody(ref: StudioSymbolRefNode, body: StudioDocument): StudioNode | null {
  if (body.nodes.length !== 1) return null;
  const expanded = cloneBodyNode(ref.id, body.nodes[0] as StudioNode, ref.overrides);
  if (expanded === null) return null;
  if (ref.hidden !== undefined) expanded.hidden = ref.hidden; // المرجع يغلب — عين النسخة تعمل كأي عقدة
  if (ref.slot !== undefined) expanded.slot = ref.slot;
  return expanded;
}

/**
 * الممرّ الواحد قبل المصيّر (ترتيب الأنبوب المُلزم §2.2): يستبدل كل
 * `symbol-ref` بجسمه الممدّد ويعيد خريطة `instanceOf` (معرّف ممدّد →
 * معرّف المرجع — بها يعيد الكانفس توجيه التحديد). مستند بلا مراجع يعود
 * **بمرجعه نفسه**. الرمز الغائب من الخريطة يُحذف موضعه (غياب صادق).
 */
export function resolveSymbols(
  doc: StudioDocument,
  symbols: SymbolsMap,
): { doc: StudioDocument; instanceOf: Record<string, string> } {
  const instanceOf: Record<string, string> = {};

  const mapList = (list: StudioNode[]): StudioNode[] => {
    let out: StudioNode[] | null = null;
    for (let i = 0; i < list.length; i++) {
      const node = list[i] as StudioNode;
      if (isSymbolRef(node)) {
        if (out === null) out = list.slice(0, i);
        const body = symbols[node.symbolId]?.document;
        const expanded = body === undefined ? null : expandSymbolBody(node, body);
        if (expanded !== null) {
          for (const { node: part } of walkNodes({ nodes: [expanded] })) instanceOf[part.id] = node.id;
          out.push(expanded);
        }
        continue;
      }
      if (node.children !== undefined) {
        const mapped = mapList(node.children);
        if (mapped !== node.children) {
          if (out === null) out = list.slice(0, i);
          const next: StudioElementNode = { ...node };
          if (mapped.length === 0) delete next.children;
          else next.children = mapped;
          out.push(next);
          continue;
        }
      }
      if (out !== null) out.push(node);
    }
    return out ?? list;
  };

  const nodes = mapList(doc.nodes);
  if (nodes === doc.nodes) return { doc, instanceOf };
  return { doc: { ...doc, nodes }, instanceOf };
}
