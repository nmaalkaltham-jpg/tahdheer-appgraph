/**
 * شجرة التصفّح — المراحل بصفوفها، والمواد — كما تبنيها اللوحة الكبرى في
 * الترويسة وأكورديون «تصفّح حسب المرحلة» في درج الجوّال.
 *
 * دالّةٌ نقيّة بلا جلب ولا مسارات: الجلب في `browse-data.ts` (خادم)، وبناء
 * الرابط يُمرَّر إليها (`termPath`) فتبقى قابلةً للاختبار بلا Next.
 *
 * الصدق:
 *  · الصفّ يُنسب إلى مرحلته بحقل `stage.slug` الذي يرسله
 *    GET /api/v1/catalog/grades — لا بمطابقة الاسم. صفٌّ بلا مرحلةٍ معروفة
 *    لا يُخترع له مكان.
 *  · العدد يُحمل فقط حين يرسله الخادم (`productsCount`)، ولا صفر بديلًا.
 *  · مفردةٌ عددها المعلَن صفر لا تُعرض: رابطٌ إلى أرشيفٍ فارغ طريقٌ مسدود
 *    في أداة تصفّح. وصفحات المحاور (/stages …) تبقى تعرض الكلّ.
 */

export type BrowseDimension = 'stages' | 'grades' | 'subjects';

export interface TermInput {
  slug: string;
  name: string;
  productsCount?: number;
  stage?: { slug: string } | null;
}

export interface BrowseLink {
  label: string;
  href: string;
  /** عدد المنتجات كما أرسله الخادم — غائبٌ حين لم يُرسَل. */
  count?: number;
}

interface BrowseStage extends BrowseLink {
  grades: BrowseLink[];
}

export interface BrowseTree {
  stages: BrowseStage[];
  subjects: BrowseLink[];
}

const known = (term: TermInput) => typeof term.productsCount !== 'number' || term.productsCount > 0;

function toLink(dimension: BrowseDimension, term: TermInput, path: (d: BrowseDimension, slug: string) => string): BrowseLink {
  return {
    label: term.name,
    href: path(dimension, term.slug),
    ...(typeof term.productsCount === 'number' ? { count: term.productsCount } : {}),
  };
}

export function buildBrowseTree(
  stages: readonly TermInput[],
  grades: readonly TermInput[],
  subjects: readonly TermInput[],
  path: (dimension: BrowseDimension, slug: string) => string,
): BrowseTree | null {
  const byStage = new Map<string, BrowseLink[]>();
  for (const grade of grades) {
    const owner = grade.stage?.slug;
    if (owner === undefined || !known(grade)) continue;
    const list = byStage.get(owner) ?? [];
    list.push(toLink('grades', grade, path));
    byStage.set(owner, list);
  }

  const tree: BrowseTree = {
    stages: stages.filter(known).map((stage) => ({
      ...toLink('stages', stage, path),
      grades: byStage.get(stage.slug) ?? [],
    })),
    subjects: subjects.filter(known).map((subject) => toLink('subjects', subject, path)),
  };

  // لا مراحل ولا مواد ⇒ لا لوحة: الرابط يبقى رابطًا عاديًّا إلى صفحة المحور.
  return tree.stages.length === 0 && tree.subjects.length === 0 ? null : tree;
}
