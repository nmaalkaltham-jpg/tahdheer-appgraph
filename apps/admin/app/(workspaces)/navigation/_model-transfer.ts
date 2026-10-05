// UNIT: _MODEL-TRANSFER
// FILE: _model-transfer.ts
// STYLE: none
// PARENT: DIALOGS, PAGE-NAVIGATION
// USES: MODEL
// API: none
// MAP: docs/PROJECT_MAP.md

import { MAX_NODES, makeNode, toServerShape } from './model';
import type { MenuNode, NodeType, ServerNode } from './model';

/* ═══════════════════ مسودة المتصفح ═══════════════════ */

/**
 * مفتاح المسودة المحلّية — مُفهرس بالموقع.
 *
 * كان مفتاحًا واحدًا لكل المواقع: استرجاع مسودة الترويسة داخل التذييل يكتب
 * قائمةً في مكان لا تخصّه. الإصدار رُفع إلى v2 لأن مسودات v1 تحمل معرّفات
 * `tmp_` من البناء السابق.
 */
function draftKey(location: string): string {
  return `tahdheer.admin.navigation.draft.v2.${location}`;
}

export interface DraftEnvelope {
  nodes: MenuNode[];
  savedAt: number;
  edits: number;
}

export function readDraft(location: string): DraftEnvelope | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.localStorage.getItem(draftKey(location));
    if (!raw) return null;
    const parsed: unknown = JSON.parse(raw);
    if (typeof parsed !== 'object' || parsed === null) return null;
    const envelope = parsed as Partial<DraftEnvelope>;
    if (!Array.isArray(envelope.nodes) || envelope.nodes.length === 0) return null;
    return {
      nodes: envelope.nodes as MenuNode[],
      savedAt: typeof envelope.savedAt === 'number' ? envelope.savedAt : 0,
      edits: typeof envelope.edits === 'number' ? envelope.edits : 0,
    };
  } catch {
    return null;
  }
}

export function writeDraft(location: string, nodes: readonly MenuNode[], edits: number): void {
  if (typeof window === 'undefined') return;
  try {
    const envelope: DraftEnvelope = { nodes: nodes.slice(), savedAt: Date.now(), edits };
    window.localStorage.setItem(draftKey(location), JSON.stringify(envelope));
  } catch {
    /* الحفظ المحلي قد يكون معطّلًا — الاستوديو يعمل بلا مسودة، ولا يدّعي حفظًا. */
  }
}

export function clearDraft(location: string): void {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.removeItem(draftKey(location));
  } catch {
    /* لا شيء يُفعل — لا حالة تُدّعى. */
  }
}

/* ═══════════════════ التصدير والاستيراد (ملف JSON) ═══════════════════ */

/**
 * صيغة التبادل الموثّقة — بادئة `tahdheer.menu-studio` هي عقد الاستيراد.
 *
 * التصدير يحمل حقول عقد الخادم الخمسة عشر كما هي (شكل `ServerNode`).
 * لا حقل `meta` هنا: عقد الشجرة على الخادم (`MenuTree::normalize`) قائمة
 * بيضاء تُسقط أي حقل غريب عند الحفظ، فادّعاء حفظه في التصدير كذب. الاستيراد
 * يَعدّ الحقول الغريبة ويقولها صراحةً بدل إسقاطها بصمت.
 */
const EXPORT_SCHEMA = 'tahdheer.menu-studio/v1';

export interface ExportEnvelope {
  schema: string;
  exportedAt: string;
  menu: { location: string; name: string };
  items: ServerNode[];
}

/** نص JSON قابل للتنزيل والاستيراد — الحيّ فقط، كما يُرسَل للخادم تمامًا. */
export function buildExport(location: string, name: string, nodes: readonly MenuNode[]): string {
  const envelope: ExportEnvelope = {
    schema: EXPORT_SCHEMA,
    exportedAt: new Date().toISOString(),
    menu: { location, name },
    items: toServerShape(nodes),
  };
  return JSON.stringify(envelope, null, 2);
}

const NODE_TYPES: readonly NodeType[] = ['page', 'product', 'category', 'custom'];

/** حقول العقدة النصية التي تُقرأ من الملف كما هي (بعد التحقق من نوعها). */
const IMPORT_STRING_FIELDS = [
  'label',
  'url',
  'rel',
  'classes',
  'cssId',
  'aria',
  'desc',
  'badge',
  'icon',
] as const;

export interface ImportCheck {
  ok: boolean;
  /** سبب الرفض حين لا يكون الملف صالحًا، وإلا null. */
  error: string | null;
  schema: string;
  name: string;
  count: number;
  /** عناصر بلا نص أو بلا رابط — تُستورد ويُبلَّغ عنها التحقق المحلي. */
  missing: number;
  /** حقول غريبة في الملف ستُسقط: عقد الخادم قائمة بيضاء لا تحفظها. */
  unknownKeys: number;
  nodes: MenuNode[];
}

function rejected(error: string, schema = '—'): ImportCheck {
  return { ok: false, error, schema, name: '—', count: 0, missing: 0, unknownKeys: 0, nodes: [] };
}

const KNOWN_ITEM_KEYS = new Set([
  'id',
  'parentId',
  'parent',
  ...IMPORT_STRING_FIELDS,
  'type',
  'target',
  'sourceId',
  'sourceApi',
]);

/**
 * يتحقق من ملف استيراد ويبني عقدًا جديدة — لا يُنفَّذ أي كود من الملف.
 *
 * المعرّفات تُسكّ من جديد (`newNodeId`) حتى لا تتصادم مع عقد المحرّر ولا
 * تحمل بادئة يرفضها الفحص المسبق؛ العلاقات تُعاد بالخريطة، والأب المفقود
 * يصبح في المستوى الأعلى — نفس ما يفعله تطبيع الخادم.
 */
export function checkImport(text: string): ImportCheck {
  let parsed: unknown;
  try {
    parsed = JSON.parse(text);
  } catch (error) {
    return rejected(`JSON غير صالح: ${error instanceof Error ? error.message : String(error)}`);
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    return rejected('الملف ليس كائن JSON.');
  }

  const envelope = parsed as Partial<ExportEnvelope> & { items?: unknown };
  const schema = typeof envelope.schema === 'string' ? envelope.schema : 'مفقود';
  if (!schema.startsWith('tahdheer.menu-studio')) {
    return rejected('الصيغة غير معروفة — schema يجب أن يبدأ بـ tahdheer.menu-studio.', schema);
  }
  if (!Array.isArray(envelope.items) || envelope.items.length === 0) {
    return rejected('لا عناصر في الملف (items فارغة أو مفقودة).', schema);
  }
  if (envelope.items.length > MAX_NODES) {
    return rejected(`الملف يحمل ${envelope.items.length} عنصرًا وسقف الخادم ${MAX_NODES}.`, schema);
  }

  const idMap = new Map<string, string>();
  let missing = 0;
  let unknownKeys = 0;

  const drafts = envelope.items.map((raw) => {
    const item = (typeof raw === 'object' && raw !== null ? raw : {}) as Record<string, unknown>;
    for (const key of Object.keys(item)) if (!KNOWN_ITEM_KEYS.has(key)) unknownKeys += 1;

    const node = makeNode({});
    for (const field of IMPORT_STRING_FIELDS) {
      const value = item[field];
      if (typeof value === 'string') node[field] = value.trim();
    }
    if (typeof item.type === 'string' && (NODE_TYPES as readonly string[]).includes(item.type)) {
      node.type = item.type as NodeType;
    }
    if (item.target === '_blank') node.target = '_blank';
    if (typeof item.sourceId === 'number' && Number.isInteger(item.sourceId)) {
      node.sourceId = item.sourceId;
    }
    if (typeof item.sourceApi === 'string') node.sourceApi = item.sourceApi;
    if (node.label === '' || node.url === '') missing += 1;

    const rawId = typeof item.id === 'string' || typeof item.id === 'number' ? String(item.id) : null;
    if (rawId !== null) idMap.set(rawId, node.id);
    const rawParent = item.parentId ?? item.parent;
    const parentKey =
      typeof rawParent === 'string' || typeof rawParent === 'number' ? String(rawParent) : null;
    return { node, parentKey };
  });

  const nodes = drafts.map(({ node, parentKey }) => {
    const parentId = parentKey === null ? null : (idMap.get(parentKey) ?? null);
    // عنصر أبوه نفسه لا يبني دائرة — يُرفع إلى المستوى الأعلى كما يفعل الخادم.
    return { ...node, parentId: parentId === node.id ? null : parentId };
  });

  return {
    ok: true,
    error: null,
    schema,
    name: typeof envelope.menu?.name === 'string' ? envelope.menu.name : '—',
    count: nodes.length,
    missing,
    unknownKeys,
    nodes,
  };
}
