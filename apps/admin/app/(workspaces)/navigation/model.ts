// UNIT: MODEL
// FILE: model.ts
// STYLE: none
// PARENT: DIALOGS, INSPECTOR, PAGE-NAVIGATION, VIEWS, _LIBRARY-RAIL, _MODEL-TRANSFER +3
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * استوديو القوائم — النموذج الخالص: البنية، عمليات الشجرة، محرّك التحقق،
 * عقد النشر، ومسودة المتصفح.
 *
 * عقيدة الصدق (MIP §1.3): لا رقم مُختلَق ولا قائمة «تجريبية» — يبدأ الاستوديو
 * بما تعيده مسودة الخادم، وكل عنصر يُضاف كتبه المشغّل أو اختاره من محتوى
 * حقيقي عبر /admin/products و /catalog/*.
 *
 * الخادم موجود: `GET/PATCH /admin/navigation/{location}` للمسودة،
 * `POST …/publish` و`…/rollback` للنشر والتراجع. مسودة المتصفح بقيت
 * استرجاعَ عطلٍ فقط، لا مصدرًا.
 *
 * لا شيء في هذا الملف يتصل بالشبكة.
 */

/* ═══════════════════ البنية ═══════════════════ */

export type NodeType = 'page' | 'product' | 'category' | 'custom';

export type NodeTarget = '_self' | '_blank';

export interface MenuNode {
  /** معرّف ثابت يسكّه المحرّر (`c_…`) أو الخادم (`n_…`) — انظر `newNodeId`. */
  id: string;
  parentId: string | null;
  label: string;
  url: string;
  type: NodeType;
  target: NodeTarget;
  rel: string;
  classes: string;
  cssId: string;
  aria: string;
  desc: string;
  badge: string;
  /** معرّف رمز من مجموعة الأيقونات المحلّية، أو سلسلة فارغة. */
  icon: string;
  /** حذف مؤجّل: العنصر يبقى في المسودة مشطوبًا حتى النشر. */
  deleted: boolean;
  /** معرّف المنتج/المصطلح الحقيقي حين اختير من مكتبة المحتوى، وإلا null. */
  sourceId: number | null;
  /** الواجهة الخلفية التي جاء منها العنصر (للإسناد)، أو null للرابط المخصص. */
  sourceApi: string | null;
}

export const MAX_DEPTH = 4;

/** سقف الخادم لعدد العقد (`MenuTree::MAX_NODES`) — ما زاد يُقصّ عند الحفظ. */
export const MAX_NODES = 200;

/**
 * ما يقبله تطبيع الخادم وجهةً (`MenuTree::url`) — نفس القاعدة حرفيًّا.
 *
 * تكرارها هنا مقصود: الخادم يُسقط ما لا يقبله بصمت، فبلا فحص محلّي مطابق
 * يختفي العنصر عند الحفظ ويبقى المحرّر «غير محفوظ» بلا سبب مقروء.
 */
function acceptableUrl(value: string): boolean {
  const clean = value.trim();
  if (clean === '' || [...clean].length > 500) return false;
  if (clean.startsWith('//')) return false;
  if (clean.startsWith('/') || clean.startsWith('#')) return true;
  return /^https?:\/\//i.test(clean);
}

/**
 * ألوان الأنواع — رموزُ السمة لا حرفيّات: الأزرق والتركوازيّ والرماديّ الأصليّة
 * كانت 2.6–3.3:1 على الأرض الداكنة. التمييز باقٍ (معلومة · أساسيّ · محايد).
 */
const TYPE_META: Record<NodeType, { name: string; color: string; icon: string }> = {
  page: { name: 'صفحة', color: 'var(--cc-info)', icon: 'doc' },
  category: { name: 'تصنيف', color: 'var(--cc-link)', icon: 'tag' },
  product: { name: 'منتج', color: 'var(--cc-link)', icon: 'cart' },
  custom: { name: 'رابط مخصص', color: 'var(--cc-muted)', icon: 'link' },
};

export function typeMeta(type: NodeType) {
  return TYPE_META[type] ?? TYPE_META.custom;
}

/* ═══════════════════ توليد المعرّفات ═══════════════════ */

/**
 * معرّف عقدة: عشوائية مشفّرة لا عدّاد متسلسل.
 *
 * العدّاد (`tmp_1`, `tmp_2`…) كان معرّفًا يخصّ متصفّحًا واحدًا: يتصادم بين
 * محرّرَين ويتغيّر معناه بين جلستين. والأهمّ أن الفحص المسبق على الخادم
 * (`NavigationPublishService::preflight`) يرفض نشر أي عقدة معرّفها يبدأ بـ
 * `tmp_`، فكانت كل مسودة تُبنى هنا غير قابلة للنشر إطلاقًا. البادئة `c_`
 * تميّز ما سكّه المحرّر عن `n_` الذي يسكّه الخادم، والمحارف كلها ضمن ما
 * يقبله تطبيع الخادم `[A-Za-z0-9_-]`.
 */
function newNodeId(): string {
  const bytes = new Uint8Array(6);
  crypto.getRandomValues(bytes);
  return `c_${Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')}`;
}

/** معرّف من الجيل القديم (عدّاد المتصفّح) — الخادم يرفض نشره. */
export function isTempId(id: string): boolean {
  return id.startsWith('tmp_');
}

/**
 * يستبدل كل معرّف مؤقّت قديم بمعرّف ثابت ويعيد ربط الأبناء به.
 *
 * مسودة قديمة (في تخزين المتصفّح أو محفوظة على الخادم بالبناء السابق) تحمل
 * `tmp_n` لا يمكن نشرها أبدًا. الإصلاح عند القراءة لا عند النشر: المشغّل
 * يرى الحالة «غير محفوظة» فيحفظ، فيصير النشر ممكنًا.
 */
export function adoptStableIds(nodes: readonly MenuNode[]): { nodes: MenuNode[]; remapped: number } {
  const map = new Map<string, string>();
  for (const node of nodes) if (isTempId(node.id)) map.set(node.id, newNodeId());
  if (map.size === 0) return { nodes: nodes.slice(), remapped: 0 };

  return {
    nodes: nodes.map((node) => ({
      ...node,
      id: map.get(node.id) ?? node.id,
      parentId: node.parentId === null ? null : (map.get(node.parentId) ?? node.parentId),
    })),
    remapped: map.size,
  };
}

export function makeNode(patch: Partial<MenuNode>): MenuNode {
  return {
    id: newNodeId(),
    parentId: null,
    label: '',
    url: '',
    type: 'custom',
    target: '_self',
    rel: '',
    classes: '',
    cssId: '',
    aria: '',
    desc: '',
    badge: '',
    icon: '',
    deleted: false,
    sourceId: null,
    sourceApi: null,
    ...patch,
  };
}

/* ═══════════════════ شكل الخادم ═══════════════════ */

/**
 * العقدة كما يخزّنها الخادم: نفس الحقول ناقص العلامات المحلّية.
 *
 * `deleted` علامة تحرير لا تُرسل: `MenuTree::normalize` لا يقرأها، فكانت
 * العقد المشطوبة تُحفظ ثم تُنشر عناصرَ حيّة في ترويسة المتجر.
 */
export type ServerNode = Omit<MenuNode, 'deleted'>;

/**
 * إسقاط معياري: الحيّ فقط، وبترتيب مفاتيح ثابت.
 *
 * الترتيب مثبّت لأن المقارنة تجري على `JSON.stringify`: ترتيب مفاتيح مختلف
 * يُنتج نصًّا مختلفًا لشجرة متطابقة، فتبقى الحالة «غير محفوظة» إلى الأبد
 * ويبقى النشر محجوبًا.
 */
export function toServerShape(nodes: readonly MenuNode[]): ServerNode[] {
  return nodes
    .filter((node) => !node.deleted)
    .map((node) => ({
      id: node.id,
      parentId: node.parentId,
      label: node.label,
      url: node.url,
      type: node.type,
      target: node.target,
      rel: node.rel,
      classes: node.classes,
      cssId: node.cssId,
      aria: node.aria,
      desc: node.desc,
      badge: node.badge,
      icon: node.icon,
      sourceId: node.sourceId,
      sourceApi: node.sourceApi,
    }));
}

export function fromServerShape(nodes: readonly ServerNode[]): MenuNode[] {
  return nodes.map((node) => ({ ...node, deleted: false }));
}

/**
 * يدمج ما طبّعه الخادم في العقد المحلّية.
 *
 * الخادم يعيد كتابة قيم (يقصّ الفراغات، ويضيف `noopener noreferrer` لكل
 * `_blank`، ويرفع أبًا مفقودًا إلى الجذر). بلا هذا الدمج تختلف النسختان بعد
 * كل حفظ ناجح فتبقى الشارة «تعديلات غير محفوظة». العقد التي أسقطها التطبيع
 * تبقى محلّيًا كما هي — إسقاطها من المحرّر حذفٌ صامت لعمل المشغّل، والعدد
 * المُسقَط يُعرض له صراحة.
 */
export function mergeServerDraft(local: readonly MenuNode[], draft: readonly ServerNode[]): MenuNode[] {
  const server = new Map(draft.map((node) => [node.id, node]));
  const known = new Set(local.map((node) => node.id));

  const merged = local.map((node) => {
    const match = server.get(node.id);
    return match === undefined ? node : { ...match, deleted: node.deleted };
  });

  // عقدة منحها الخادم هوية لا نعرفها محلّيًا تُضاف بدل أن تختفي.
  for (const node of draft) if (!known.has(node.id)) merged.push({ ...node, deleted: false });

  return merged;
}

/** هل ما بين يدَي المحرّر مطابق لمسوّدة الخادم؟ */
export function sameAsServer(local: readonly MenuNode[], draft: readonly ServerNode[]): boolean {
  return (
    JSON.stringify(toServerShape(local)) === JSON.stringify(toServerShape(fromServerShape(draft)))
  );
}

/* ═══════════════════ عمليات الشجرة ═══════════════════ */

export interface Row {
  node: MenuNode;
  depth: number;
  /** عدد الأبناء المباشرين غير المحذوفين. */
  childCount: number;
}

export function byId(nodes: readonly MenuNode[], id: string | null): MenuNode | null {
  if (id === null) return null;
  return nodes.find((node) => node.id === id) ?? null;
}

export function childrenOf(nodes: readonly MenuNode[], parentId: string | null): MenuNode[] {
  return nodes.filter((node) => node.parentId === parentId);
}

/** ترتيب العرض: عمق أولًا، مع احترام ترتيب المصفوفة داخل كل مستوى. */
export function rows(nodes: readonly MenuNode[]): Row[] {
  const out: Row[] = [];
  const walk = (parentId: string | null, depth: number, hops: number): void => {
    if (hops > 32) return; // حماية من دائرية أفلتت من التحقق
    for (const node of childrenOf(nodes, parentId)) {
      out.push({ node, depth, childCount: childrenOf(nodes, node.id).length });
      walk(node.id, depth + 1, hops + 1);
    }
  };
  walk(null, 0, 0);
  return out;
}

/** الصفوف المرئية فقط: يُسقط أبناء كل عقدة مطوية. */
export function visibleRows(nodes: readonly MenuNode[], collapsed: ReadonlySet<string>): Row[] {
  const out: Row[] = [];
  const walk = (parentId: string | null, depth: number, hops: number): void => {
    if (hops > 32) return;
    for (const node of childrenOf(nodes, parentId)) {
      out.push({ node, depth, childCount: childrenOf(nodes, node.id).length });
      if (!collapsed.has(node.id)) walk(node.id, depth + 1, hops + 1);
    }
  };
  walk(null, 0, 0);
  return out;
}

export function depthOf(nodes: readonly MenuNode[], id: string): number {
  let depth = 0;
  let current = byId(nodes, id);
  const seen = new Set<string>();
  while (current && current.parentId !== null) {
    if (seen.has(current.id)) return depth; // دائرية — التحقق يبلّغ عنها
    seen.add(current.id);
    depth += 1;
    current = byId(nodes, current.parentId);
  }
  return depth;
}

/** كل نسل العقدة (بلا العقدة نفسها). */
export function descendantIds(nodes: readonly MenuNode[], id: string): Set<string> {
  const out = new Set<string>();
  const walk = (parentId: string, hops: number): void => {
    if (hops > 32) return;
    for (const child of childrenOf(nodes, parentId)) {
      if (out.has(child.id)) continue;
      out.add(child.id);
      walk(child.id, hops + 1);
    }
  };
  walk(id, 0);
  return out;
}

/** مسار العقدة من الجذر (للفتات). */
export function pathOf(nodes: readonly MenuNode[], id: string): MenuNode[] {
  const out: MenuNode[] = [];
  let current = byId(nodes, id);
  const seen = new Set<string>();
  while (current && !seen.has(current.id)) {
    seen.add(current.id);
    out.unshift(current);
    current = byId(nodes, current.parentId);
  }
  return out;
}

/**
 * إعادة بناء المصفوفة بترتيب عمق-أولًا حتى يبقى الترتيب المخزَّن = ترتيب العرض.
 *
 * العقد غير القابلة للوصول من الجذر (أب معلَّق أو دائرية في مسودة مستعادة)
 * تُلحق كما هي في النهاية ولا تُسقَط أبدًا: إسقاطها حذف صامت لبيانات المشغّل.
 * التحقق يبلّغ عنها، والمفتش يفتحها بالمعرّف فتُصلَّح يدويًا.
 */
function reflow(nodes: readonly MenuNode[]): MenuNode[] {
  const ordered = rows(nodes).map((row) => row.node);
  const seen = new Set(ordered.map((node) => node.id));
  const stranded = nodes.filter((node) => !seen.has(node.id));
  return stranded.length === 0 ? ordered : [...ordered, ...stranded];
}

/** نقل عنصر بين أشقائه (delta = -1 أعلى، +1 أسفل). يعيد null إن تعذّر. */
export function moveWithinSiblings(nodes: readonly MenuNode[], id: string, delta: number): MenuNode[] | null {
  const node = byId(nodes, id);
  if (!node) return null;
  const siblings = childrenOf(nodes, node.parentId);
  const at = siblings.findIndex((sibling) => sibling.id === id);
  const to = at + delta;
  if (at < 0 || to < 0 || to >= siblings.length) return null;
  // فهرس reparent محسوب على الأشقاء بعد نزع المنقول، وهو هنا نفسه `to`
  // في الاتجاهين: للأعلى يُدرَج قبل الشقيق الهدف، وللأسفل بعده.
  return reparent(nodes, id, node.parentId, to);
}

/** تعشيش العنصر تحت شقيقه السابق. */
export function nestUnderPrevious(nodes: readonly MenuNode[], id: string): MenuNode[] | null {
  const node = byId(nodes, id);
  if (!node) return null;
  const siblings = childrenOf(nodes, node.parentId);
  const at = siblings.findIndex((sibling) => sibling.id === id);
  const previous = at > 0 ? siblings[at - 1] : undefined;
  if (!previous) return null;
  if (depthOf(nodes, previous.id) + 1 >= MAX_DEPTH) return null;
  return reparent(nodes, id, previous.id, childrenOf(nodes, previous.id).length);
}

/** إخراج العنصر ليصبح الشقيق التالي لأبيه. */
export function unnest(nodes: readonly MenuNode[], id: string): MenuNode[] | null {
  const node = byId(nodes, id);
  if (!node || node.parentId === null) return null;
  const parent = byId(nodes, node.parentId);
  if (!parent) return null;
  const grandParentId = parent.parentId;
  const uncles = childrenOf(nodes, grandParentId);
  const at = uncles.findIndex((uncle) => uncle.id === parent.id);
  return reparent(nodes, id, grandParentId, at + 1);
}

/**
 * نقل عنصر (بفرعه) تحت أب جديد في موضع محدّد.
 * يرفض النقل داخل نسل العنصر نفسه — هذه هي الحماية من الدائرية عند التحرير.
 */
export function reparent(
  nodes: readonly MenuNode[],
  id: string,
  parentId: string | null,
  index: number,
): MenuNode[] | null {
  const node = byId(nodes, id);
  if (!node) return null;
  if (parentId === id) return null;
  if (parentId !== null && descendantIds(nodes, id).has(parentId)) return null;

  const parentDepth = parentId === null ? -1 : depthOf(nodes, parentId);
  const branchDepth = branchHeight(nodes, id);
  if (parentDepth + 1 + branchDepth >= MAX_DEPTH) return null;

  const moved: MenuNode = { ...node, parentId };
  const rest = nodes.filter((other) => other.id !== id);
  const siblings = rest.filter((other) => other.parentId === parentId);
  const anchor = siblings[Math.min(Math.max(index, 0), siblings.length)];

  const next: MenuNode[] = [];
  let placed = false;
  for (const other of rest) {
    if (anchor && other.id === anchor.id) {
      next.push(moved);
      placed = true;
    }
    next.push(other);
  }
  if (!placed) next.push(moved);
  return reflow(next);
}

/** ارتفاع الفرع (0 لورقة) — يمنع تجاوز الحد الأقصى للعمق عند النقل. */
function branchHeight(nodes: readonly MenuNode[], id: string, hops = 0): number {
  if (hops > 32) return hops; // مسودة معطوبة: نتوقف بدل الدوران
  const children = childrenOf(nodes, id);
  if (children.length === 0) return 0;
  return 1 + Math.max(...children.map((child) => branchHeight(nodes, child.id, hops + 1)));
}

/** إدراج عنصر جديد بعد مرجع، أو في نهاية المستوى الأعلى. */
export function insertAfter(nodes: readonly MenuNode[], node: MenuNode, afterId: string | null): MenuNode[] {
  if (afterId === null) return reflow([...nodes, node]);
  const at = nodes.findIndex((other) => other.id === afterId);
  if (at < 0) return reflow([...nodes, node]);
  const next = nodes.slice();
  next.splice(at + 1, 0, node);
  return reflow(next);
}

/** تكرار فرع كامل بمعرّفات جديدة. */
export function duplicateBranch(nodes: readonly MenuNode[], id: string): MenuNode[] | null {
  const root = byId(nodes, id);
  if (!root) return null;

  const working = nodes.slice();
  const created: MenuNode[] = [];

  const clone = (source: MenuNode, parentId: string | null): void => {
    // معرّف المصدر يُستبعد عمدًا: النسخة تأخذ معرّفًا جديدًا،
    // وإلا لتكرّر المعرّف نفسه وانهار التسلسل.
    const copy = makeNode({ ...source, id: newNodeId(), parentId });
    created.push(copy);
    for (const child of childrenOf(nodes, source.id)) clone(child, copy.id);
  };
  clone(root, root.parentId);

  const at = working.findIndex((other) => other.id === id);
  const tail = at < 0 ? working.length : at + 1;
  const next = working.slice();
  next.splice(tail, 0, ...created);
  return reflow(next);
}

/** حذف مؤجّل: يشمل الفرع كله حتى لا يبقى يتيم في المسودة. */
export function setDeleted(nodes: readonly MenuNode[], id: string, deleted: boolean): MenuNode[] {
  const branch = descendantIds(nodes, id);
  branch.add(id);
  return nodes.map((node) => (branch.has(node.id) ? { ...node, deleted } : node));
}

/**
 * حذف الأب وحده بعد ترقية أبنائه إلى مستواه.
 *
 * البديل الوحيد كان قتل الفرع كلّه بلا سؤال: مَن يحذف «العروض» ليُبقي أبناءها
 * كان يفقدها جميعًا بضغطة واحدة. الأبناء يأخذون موضع أبيهم بالترتيب فلا
 * ينقلبون، والحذف يبقى مؤجَّلًا قابلًا للتراجع كبقيّة الخيارات.
 */
export function deleteWithPromotion(nodes: readonly MenuNode[], id: string): MenuNode[] | null {
  const node = byId(nodes, id);
  if (node === null) return null;

  let next: MenuNode[] = nodes.slice();
  const promoted = childrenOf(next, id).filter((child) => !child.deleted);

  promoted.forEach((child, offset) => {
    const siblings = childrenOf(next, node.parentId).filter((sibling) => sibling.id !== child.id);
    const at = siblings.findIndex((sibling) => sibling.id === id);
    const moved = reparent(next, child.id, node.parentId, (at < 0 ? siblings.length : at) + offset + 1);
    if (moved !== null) next = moved;
  });

  return setDeleted(next, id, true);
}

export function patchNode(nodes: readonly MenuNode[], id: string, patch: Partial<MenuNode>): MenuNode[] {
  return nodes.map((node) => (node.id === id ? { ...node, ...patch } : node));
}

/* ═══════════════════ محرّك التحقق ═══════════════════ */

export type Severity = 'error' | 'warn' | 'info';

export interface Issue {
  severity: Severity;
  code: string;
  title: string;
  detail: string;
  /** العنصر المعني، أو null لملاحظة على مستوى القائمة. */
  nodeId: string | null;
}

/** نصوص روابط غير وصفية — إرشاد فقط، لا تعديل تلقائي أبدًا. */
const WEAK_ANCHORS = [
  'اضغط هنا',
  'هنا',
  'المزيد',
  'اقرأ المزيد',
  'تفاصيل',
  'رابط',
  'انقر',
  'click here',
  'more',
];

/**
 * الفحوص القابلة للحسم في المتصفح وحده.
 *
 * ما ليس هنا عمدًا: 404، سلاسل التحويل، انحراف الرابط الدائم، المحتوى
 * الخاص/المسودة. كلّها تحتاج فاحص روابط على الخادم؛ الواجهة تعلن غيابه
 * صراحةً بدل رسم علامات نجاح كاذبة.
 */
export function analyze(nodes: readonly MenuNode[]): Issue[] {
  const live = nodes.filter((node) => !node.deleted);
  const out: Issue[] = [];
  const destinations = new Map<string, string[]>();
  const present = new Set(live.map((node) => node.id));

  for (const node of live) {
    const label = node.label.trim();
    const url = node.url.trim();

    if (label === '') {
      out.push({
        severity: 'error',
        code: 'empty_label',
        title: 'نص تنقل فارغ',
        detail: 'العنصر بلا نص ظاهر — النشر محجوب.',
        nodeId: node.id,
      });
    }

    if (url === '') {
      out.push({
        severity: 'error',
        code: 'empty_url',
        title: 'رابط فارغ',
        detail: 'لا وجهة للعنصر — النشر محجوب.',
        nodeId: node.id,
      });
    }

    if (/^\s*javascript:/i.test(node.url)) {
      out.push({
        severity: 'error',
        code: 'unsafe_scheme',
        title: 'بروتوكول غير آمن',
        detail: 'javascript: مرفوض هنا وسيُرفض على الخادم — لا يُحفظ ولا يُنشر.',
        nodeId: node.id,
      });
    } else if (url !== '' && !acceptableUrl(url)) {
      out.push({
        severity: 'error',
        code: 'rejected_url',
        title: 'وجهة يرفضها تطبيع الخادم',
        detail:
          'المقبول: مسار داخلي يبدأ بـ / (لا //)، أو مرساة تبدأ بـ #، أو رابط http/https مطلق بطول لا يتجاوز ٥٠٠ محرف. غير ذلك يُسقَط عند الحفظ بلا إشعار.',
        nodeId: node.id,
      });
    }

    if (node.parentId !== null && !present.has(node.parentId)) {
      out.push({
        severity: 'error',
        code: 'temp_ref',
        title: 'مرجع معرّف مؤقّت معلَّق',
        detail: `الأب «${node.parentId}» غير موجود في المسودة — خطوة تعيين المعرّفات لن تجد ما تربطه.`,
        nodeId: node.id,
      });
    }

    if (hasCycle(live, node.id)) {
      out.push({
        severity: 'error',
        code: 'cycle',
        title: 'دائرية في التسلسل',
        detail: 'سلسلة الآباء تعود إلى العنصر نفسه — بنية غير قابلة للعرض.',
        nodeId: node.id,
      });
    }

    if (url === '#') {
      out.push({
        severity: 'warn',
        code: 'placeholder',
        title: 'رابط بديل (#)',
        detail: 'الرابط لا يذهب لأي مكان — يظهر للزائر كرابط معطّل.',
        nodeId: node.id,
      });
    }

    if (/^http:\/\//i.test(url)) {
      out.push({
        severity: 'warn',
        code: 'insecure',
        title: 'رابط غير مشفَّر على موقع مشفَّر',
        detail: 'يسبّب تحذير محتوى مختلط في المتصفح.',
        nodeId: node.id,
      });
    }

    if (/^https?:\/\//i.test(url)) {
      if (node.target === '_blank' && !/noopener/.test(node.rel)) {
        out.push({
          severity: 'warn',
          code: 'rel',
          title: 'تبويب جديد بلا rel آمن',
          detail: 'رابط خارجي يفتح بتبويب جديد دون noopener.',
          nodeId: node.id,
        });
      } else if (node.target !== '_blank') {
        out.push({
          severity: 'info',
          code: 'external',
          title: 'رابط خارجي',
          detail: 'يخرج الزائر من الموقع دون إشارة.',
          nodeId: node.id,
        });
      }
    }

    if (depthOf(live, node.id) + 1 > MAX_DEPTH) {
      out.push({
        severity: 'warn',
        code: 'depth',
        title: 'تعشيش عميق',
        detail: `المستوى ${depthOf(live, node.id) + 1} يتجاوز ${MAX_DEPTH} مستويات.`,
        nodeId: node.id,
      });
    }

    if (WEAK_ANCHORS.includes(label)) {
      out.push({
        severity: 'info',
        code: 'weak_anchor',
        title: 'نص رابط غير وصفي',
        detail: `«${label}» لا يصف الوجهة — إرشاد فقط، لا تعديل تلقائي.`,
        nodeId: node.id,
      });
    }

    if (node.icon !== '' && label === '' && node.aria.trim() === '') {
      out.push({
        severity: 'warn',
        code: 'aria',
        title: 'اسم متاح مفقود',
        detail: 'أيقونة بلا نص ولا aria-label — قارئ الشاشة لن يقرأ شيئًا.',
        nodeId: node.id,
      });
    }

    const key = url.replace(/\/+$/, '');
    if (key !== '') destinations.set(key, [...(destinations.get(key) ?? []), node.id]);
  }

  for (const [key, ids] of destinations) {
    if (ids.length > 1) {
      out.push({
        severity: 'warn',
        code: 'duplicate',
        title: 'تعيين مكرّر لنفس الوجهة',
        detail: `${ids.length} عناصر تشير إلى ${key}.`,
        nodeId: ids[1] ?? null,
      });
    }
  }

  const top = childrenOf(live, null).length;
  if (top > 9) {
    out.push({
      severity: 'warn',
      code: 'too_many_top',
      title: `عناصر رئيسية كثيرة (${top})`,
      detail: 'أكثر من ٩ عناصر في الشريط العلوي تُربك الزائر وتكسر عرض الجوال.',
      nodeId: null,
    });
  }

  if (live.length > MAX_NODES) {
    out.push({
      severity: 'error',
      code: 'over_capacity',
      title: `عناصر أكثر من سقف الخادم (${live.length} من ${MAX_NODES})`,
      detail: `الحفظ يقصّ ما زاد عن ${MAX_NODES} عنصرًا بلا إشعار — احذف قبل الحفظ لتختار أنت ما يبقى.`,
      nodeId: null,
    });
  }

  if (live.length > 60) {
    out.push({
      severity: 'info',
      code: 'mobile_load',
      title: 'تعقيد الجوال مرتفع',
      detail: `${live.length} عنصرًا في قائمة جوال واحدة.`,
      nodeId: null,
    });
  }

  return out;
}

function hasCycle(nodes: readonly MenuNode[], id: string): boolean {
  const seen = new Set<string>();
  let current = byId(nodes, id);
  while (current && current.parentId !== null) {
    if (seen.has(current.id)) return true;
    seen.add(current.id);
    if (current.parentId === id) return true;
    current = byId(nodes, current.parentId);
  }
  return false;
}

export interface HealthSummary {
  score: number;
  errors: number;
  warnings: number;
  infos: number;
}

/** خصم الأصل البصري: حاجب ١٤، تحذير ٥، إرشاد ١٫٥ — والحد الأدنى ٨. */
export function health(issues: readonly Issue[]): HealthSummary {
  let score = 100;
  let errors = 0;
  let warnings = 0;
  let infos = 0;
  for (const issue of issues) {
    if (issue.severity === 'error') {
      score -= 14;
      errors += 1;
    } else if (issue.severity === 'warn') {
      score -= 5;
      warnings += 1;
    } else {
      score -= 1.5;
      infos += 1;
    }
  }
  return { score: Math.max(8, Math.round(score)), errors, warnings, infos };
}

/* ═══════════════ فحوص الوجهة — قياس شبكي حقيقي ═══════════════ */

/** نقطة الفحص الفعلية: تقبل حتى عشرين وجهة في الطلب الواحد. */
const LINK_CHECK_ENDPOINT = 'POST /api/v1/admin/navigation/link-check';

/** الحدّ الذي يفرضه الخادم على حجم الدفعة (`urls` … `max:20`). */
export const LINK_CHECK_BATCH = 20;

export interface ServerCheck {
  key: string;
  label: string;
  needs: string;
}

export const LINK_PROBE_CHECKS: ServerCheck[] = [
  { key: 'status', label: 'حالة الوجهة (٢٠٠ / ٤٠٤ / ٥٠٠)', needs: LINK_CHECK_ENDPOINT },
  { key: 'chain', label: 'سلسلة التحويل وعدد القفزات', needs: LINK_CHECK_ENDPOINT },
  { key: 'canonical', label: 'انحراف الرابط الدائم عن المخزَّن', needs: LINK_CHECK_ENDPOINT },
  { key: 'indexing', label: 'حالة الفهرسة (noindex)', needs: LINK_CHECK_ENDPOINT },
  { key: 'visibility', label: 'محتوى خاص أو غير منشور خلف الرابط', needs: LINK_CHECK_ENDPOINT },
];

/** نتيجة فحص وجهة واحدة كما يعيدها `LinkChecker` على الخادم. */
export interface ProbeResult {
  url: string;
  status: 'OK' | 'BLOCKED' | 'UNREACHABLE';
  httpStatus: number | null;
  chain: { url: string; status: number }[];
  hops: number;
  finalUrl: string | null;
  canonical: string | null;
  robots: string | null;
  indexable: boolean | null;
  reason: string | null;
}

/* ═══════════════════ عقد النشر ═══════════════════ */

/**
 * خطوة نشر كما يعرّفها الخادم.
 *
 * ─── لماذا لم تعد تُكتب هنا ───
 *
 * كانت `PUBLISH_STEPS` و`ROLLBACK_STEPS` مصفوفتين حرفيّتين في هذا الملفّ، وفي
 * كلٍّ منهما `wired: boolean` مكتوبٌ باليد وتوثيقه يقول إنّه «يُقرأ من الخادم
 * لا من الرغبة» — ولا مسار كان يعطيه. القيم صحيحة يوم كُتبت، وهذا بعينه
 * الخطر: خطوة `verify` سند المشغّل في أنّ النشر يُتحقَّق ببصمة ما يُخدَم، فلو
 * حُذف `hash_equals` من الخادم لبقيت الشارة «منفَّذة» أبدًا.
 *
 * المصدر الآن `NavigationPublishContract` في الخادم، يصل في
 * `GET /admin/navigation` ضمن `meta.publishContract` — وهو نفسه الذي يسجّل به
 * المنسّق كلّ خطوة يُتمّها، واختبارٌ يُشغّل النشر الحقيقيّ يُسقط البناء إن
 * غابت خطوة واجبة عن الأثر.
 *
 * والحالات مفصولة: `implemented` قدرةٌ مبنيّة، و`required` أنّ النشر لا يصحّ
 * بدونها — وأنّها **نُفّذت** لا يُقرأ من هنا بل من أثر عمليّة بعينها.
 */
export interface PublishStep {
  id: string;
  label: string;
  detail: string;
  required: boolean;
  implemented: boolean;
  /** حالة العمليّة التي تكتبها الخطوة عند نجاحها، أو null. */
  produces: string | null;
  /** الصنف أو الدالّة التي تنفّذها فعلًا. */
  executor: string;
}

export interface PublishContract {
  publish: PublishStep[];
  rollback: PublishStep[];
}

/**
 * ما يبلغه النشر فعلًا في المتجر اليوم.
 *
 * **قيمٌ مكتوبة يدويًّا ومُراجَعة مقابل شيفرة المتجر، لا مقروءةٌ من الخادم** —
 * كما `CacheTier.wired`. المرجع `apps/storefront/app/layout.tsx`: لا يجلب
 * إلّا `/api/v1/navigation/header`، ولا يصيّر إلّا عناصر المستوى الأوّل
 * (`parentId === null`)، والأبناء يُعلَنون بعلامة شيفرون لا بقائمة منسدلة.
 *
 * ولماذا يُقال هذا في الواجهة: المشغّل كان ينشر «التذييل» فيقول له النظام
 * «نُشرت» — وهو صادق: القائمة منشورة في القاعدة — ثمّ يفتح المتجر فلا يجد
 * أثرًا، فيظنّ النشر معطّلًا ويعيده مرارًا. الحدّ يُعلَن قبل النشر لا بعده.
 *
 * راجِع هذه القيم عند تعديل ترويسة المتجر أو إضافة قائمة منسدلة.
 */
export interface StorefrontReach {
  /** هل يقرأ المتجر هذا الموقع أصلًا؟ */
  consumed: boolean;
  /** ما الذي يصل منه، أو ما الذي يمنعه — جملةٌ تُعرض كما هي. */
  note: string;
}

export const STOREFRONT_REACH: Record<string, StorefrontReach> = {
  header: {
    consumed: true,
    note: 'يصل المتجر: المستوى الأوّل فقط. الأبناء تُعلَن بعلامة شيفرون ولا تُصيَّر قائمةً منسدلة بعد.',
  },
  mobile: {
    consumed: false,
    note: 'لا يقرأ المتجر هذا الموقع بعد؛ ترويسة الجوّال تَرِث الترويسة. النشر هنا يحفظ ولا يُغيّر ما يراه الزائر.',
  },
  footer: {
    consumed: false,
    note: 'لا يقرأ المتجر هذا الموقع بعد؛ التذييل مكتوبٌ في المتجر. النشر هنا يحفظ ولا يُغيّر ما يراه الزائر.',
  },
};

export interface CacheTier {
  key: string;
  name: string;
  scope: string;
  needs: string;
  /**
   * **قيمةٌ مكتوبة يدويًّا ومُراجَعة مقابل الشيفرة، لا مقروءةٌ من الخادم.**
   *
   * بخلاف خطوات النشر — التي انتقل تعريفها إلى `NavigationPublishContract`
   * ويصل في `meta.publishContract` — لا مسار يعطي حالة طبقات الكاش، وأربعٌ
   * من الستّ أمانٍ لم تُبنَ أصلًا. اشتقاق اثنتين من ستّ آلةٌ بلا مقابل.
   *
   * والمبنيّتان محروستان بتنفيذٍ حقيقيّ لا بهذا الحقل:
   * `object` بخطوة `invalidate` في اختبار سلامة النشر، و`fallback` باختبار
   * وراثة الجوّال للترويسة. راجِع هذه القيم عند تعديل `NavigationCache`.
   */
  wired: boolean;
}

/** الطبقات بترتيب الإبطال — من الأضيق نطاقًا إلى الأوسع. */
export const CACHE_TIERS: CacheTier[] = [
  {
    key: 'object',
    name: 'كاش القائمة المنشورة',
    scope: 'navigation:published:{location}',
    needs: 'NavigationCache::invalidate',
    wired: true,
  },
  {
    key: 'fallback',
    name: 'المواقع الوارثة',
    scope: 'الجوّال حين يتبع الترويسة',
    needs: 'MenuTree::FALLS_BACK_TO',
    wired: true,
  },
  {
    key: 'fragment',
    name: 'أجزاء القالب (الترويسة/التذييل)',
    scope: 'جزء واحد',
    needs: 'fragment cache key',
    wired: false,
  },
  {
    key: 'route',
    name: 'كاش مسارات المتجر',
    scope: 'المسارات المتأثرة',
    needs: 'Next revalidateTag',
    wired: false,
  },
  {
    key: 'edge',
    name: 'شبكة التوصيل / الحافة',
    scope: 'وسم nav:{location}',
    needs: 'purge-by-tag API',
    wired: false,
  },
  {
    key: 'sw',
    name: 'عامل الخدمة',
    scope: 'إصدار التنقل',
    needs: 'nav-version bump',
    wired: false,
  },
];
