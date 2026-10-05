// UNIT: LIB-NAVIGATION
// ROUTE: none
// STYLE: none
// PARENT: app/layout.tsx, components/SiteHeader.tsx, components/SiteFooter.tsx
// USES: lib/api
// API: /api/v1/navigation/{location}

/**
 * مصدر التنقّل الوحيد في المتجر.
 *
 * كان الجلب سطرًا في `app/layout.tsx` يقرأ `header` وحده ويُسقط كلّ عقدةٍ
 * لها أب، والتذييل مكتوبًا في الشيفرة، والفشل يعود إلى خمسة روابط مخترَعة لا
 * يميّزها شيءٌ عن النجاح. هذا الملفّ يجمع الثلاثة في مدخلٍ واحد: شجرةٌ
 * حقيقيّة بأبنائها، ووسمُ إبطالٍ يجعل النشر مرئيًّا فورًا، وحالةٌ صريحة حين
 * يتعذّر الجلب.
 */
import { apiGet, PUBLIC_REVALIDATE_SECONDS } from './api';

export const NAV_LOCATIONS = ['header', 'mobile', 'footer'] as const;
export type NavLocation = (typeof NAV_LOCATIONS)[number];

/** حدّ العمق — مرآةُ `MenuTree::MAX_DEPTH` في الخادم. */
export const NAV_MAX_DEPTH = 4;

/**
 * وسم الإبطال لمكان.
 *
 * المرآة في الخادم `NavigationRevalidator::tagsFor`. تغييرُ الصيغة هنا بلا
 * مرآتها يجعل النشر يُبطل وسمًا لا يطلبه أحد، فلا يتغيّر شيء ولا يُعلن خطأ.
 */
export function navTag(location: NavLocation): string {
  return `navigation:${location}`;
}

/** العقدة كما يرسلها الخادم — مسطّحة، الأبوّة بـ`parentId`. */
export interface NavigationNodeDto {
  id: string;
  parentId: string | null;
  label: string;
  url: string;
  type: 'page' | 'product' | 'category' | 'custom';
  target: '_self' | '_blank';
  rel: string;
  classes: string;
  cssId: string;
  aria: string;
  desc: string;
  badge: string;
  icon: string;
}

export interface NavigationMeta {
  schema: string;
  location: string;
  revision: number | null;
  fingerprint: string;
  publishedAt: string | null;
  inherited: boolean;
}

/** العقدة كما يستهلكها المصيِّر — مشجَّرة. */
export interface NavigationItem {
  id: string;
  label: string;
  href: string;
  target: '_self' | '_blank';
  rel: string;
  classes: string;
  cssId: string;
  aria: string;
  desc: string;
  badge: string;
  icon: string;
  children: NavigationItem[];
}

/** من أين جاءت الشجرة المعروضة — تُقال، لا تُخمَّن. */
export type NavigationSource = 'live' | 'last-known-good' | 'unavailable';

export interface NavigationTree {
  items: NavigationItem[];
  meta: NavigationMeta | null;
  source: NavigationSource;
}

/*
 * آخر نسخةٍ سليمة — في ذاكرة العمليّة.
 *
 * الغرض: انقطاعٌ لحظيّ في الواجهة لا يُفرغ ترويسة كلّ زائر. والحدّ معلَنٌ
 * لا مُخفى: الذاكرة تموت بموت العمليّة، فأوّل طلبٍ بعد إعادة تشغيلٍ وواجهةٍ
 * معطّلة يُصيَّر `unavailable` — وهو الصدق: لا شيء سليمٌ معروف بعد. البديل
 * (روابط مكتوبة في الشيفرة) كان يعرض قائمةً لم تُنشر قطّ ويسمّيها نجاحًا.
 */
const lastKnownGood = new Map<NavLocation, { items: NavigationItem[]; meta: NavigationMeta }>();

/** يبني الشجرة من القائمة المسطّحة، بحارسِ عمقٍ ودورة. */
export function buildTree(nodes: readonly NavigationNodeDto[]): NavigationItem[] {
  const byId = new Map<string, NavigationItem>();
  const order: NavigationNodeDto[] = [];

  for (const node of nodes) {
    if (typeof node?.id !== 'string' || node.id === '') continue;
    if (byId.has(node.id)) continue; // معرّفٌ مكرّر: الأوّل يفوز ولا يُبنى ابنان بهويّةٍ واحدة
    byId.set(node.id, {
      id: node.id,
      label: String(node.label ?? ''),
      href: String(node.url ?? ''),
      target: node.target === '_blank' ? '_blank' : '_self',
      rel: String(node.rel ?? ''),
      classes: String(node.classes ?? ''),
      cssId: String(node.cssId ?? ''),
      aria: String(node.aria ?? ''),
      desc: String(node.desc ?? ''),
      badge: String(node.badge ?? ''),
      icon: String(node.icon ?? ''),
      children: [],
    });
    order.push(node);
  }

  const roots: NavigationItem[] = [];

  for (const node of order) {
    const item = byId.get(node.id);
    if (item === undefined) continue;

    const parentId = typeof node.parentId === 'string' ? node.parentId : null;
    const parent = parentId === null ? undefined : byId.get(parentId);

    // أبٌ مفقود أو أبوّةٌ ذاتيّة ⇒ جذر. لا تُسقَط العقدة: إسقاطها يحذف رابطًا نُشر.
    if (parent === undefined || parent === item) {
      roots.push(item);
      continue;
    }
    parent.children.push(item);
  }

  // حارس الدورة والعمق يُطبَّق على الشجرة المبنيّة: مدخلٌ خبيث لا يُنفد المكدّس.
  const seen = new Set<string>();
  const prune = (items: NavigationItem[], depth: number): NavigationItem[] => {
    if (depth >= NAV_MAX_DEPTH) {
      for (const item of items) item.children = [];
      return items;
    }
    for (const item of items) {
      if (seen.has(item.id)) {
        item.children = [];
        continue;
      }
      seen.add(item.id);
      prune(item.children, depth + 1);
    }
    return items;
  };

  return prune(roots, 1);
}

/**
 * يجلب شجرة مكان — بوسمٍ يُبطله النشر، وبآخر نسخةٍ سليمة عند الفشل.
 *
 * لا يرمي أبدًا: غياب القائمة حالةٌ مشروعة تُصيَّر ترويسةً بلا روابط، لا
 * استثناءً يُسقط كلّ صفحة في المتجر.
 */
export async function getNavigation(location: NavLocation): Promise<NavigationTree> {
  const res = await apiGet<{ data?: NavigationNodeDto[]; meta?: NavigationMeta }>(
    `/api/v1/navigation/${location}`,
    { next: { revalidate: PUBLIC_REVALIDATE_SECONDS, tags: [navTag(location)] } },
  );

  const nodes = Array.isArray(res.data?.data) ? res.data.data : null;

  if (nodes === null) {
    const remembered = lastKnownGood.get(location);
    if (remembered !== undefined) {
      report(location, 'serving last-known-good', res.status);
      return { items: remembered.items, meta: remembered.meta, source: 'last-known-good' };
    }
    report(location, 'no last-known-good to serve', res.status);
    /*
     * `source: 'unavailable'` لا `'live'`: لم يُسأل الخادم — أو سُئل فلم
     * يُجب — فادّعاء أنّ هذه القائمة الحيّة كذبٌ يُفسد كلّ كشف انحرافٍ
     * يقرأ هذا الحقل.
     */
    return { items: [], meta: null, source: 'unavailable' };
  }

  const items = buildTree(nodes);
  const meta = res.data?.meta ?? null;

  /*
   * القائمة الفارغة المنشورة ليست فشلًا — ولا تُحفظ نسخةً سليمة.
   *
   * لو حُفظت لصار «لا روابط» هو المتذكَّر، فيخدم انقطاعٌ لاحق فراغًا بدل
   * آخر قائمةٍ حقيقيّة.
   */
  if (items.length > 0 && meta !== null) {
    lastKnownGood.set(location, { items, meta });
  }

  return { items, meta, source: 'live' };
}

/** حادثةُ تنقّلٍ تُسجَّل — لا تُبتلع. */
function report(location: NavLocation, what: string, status: number): void {
  console.error(
    JSON.stringify({
      event: 'navigation.fetch_failed',
      location,
      outcome: what,
      upstreamStatus: status,
      at: new Date().toISOString(),
    }),
  );
}
