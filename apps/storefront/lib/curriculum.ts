/**
 * اختيار محاور المنهج — قاعدةٌ واحدة يقرؤها الأرشيف والمحور معًا.
 *
 * ─── لماذا خرجت من الصفحتين ───
 *
 * `GET /api/v1/curriculum` كان بلا مستهلك، والصفحات المحوريّة
 * (`/curriculum/[type]/[slug]`) لا يقود إليها رابطٌ واحد في المتجر كلّه —
 * منتِج عناوينها الوحيد خريطة الموقع. فوُصلت من موضعين: أرشيف المادّة/الصفّ،
 * وأشقّاء المحور نفسه. وقاعدتان للاختيار في موضعين تتباعدان، فهنا واحدة
 * مُختبَرة.
 *
 * ─── والقاعدة الصلبة ───
 *
 * الأهليّة قرارُ الخادم وحده: القائمة التي يبثّها **هي** المؤهَّلة. ولا
 * يُركَّب مسارٌ هنا — `hub.path` كما جاء. وهذا ما يمنع إعلان عنوانٍ يردّ ٤٠٤،
 * وهو ثابت الوحدة المكتوب في `CurriculumHubs`.
 */

/** ما تحتاجه الروابط من صفّ المحور — لا أكثر. */
export interface CurriculumHub {
  label: string;
  path: string;
  subjectSlug: string;
  gradeSlug: string;
}

export interface HubLink {
  label: string;
  href: string;
}

/** سقفٌ للعرض: شريطُ روابطٍ بلا حدّ يصير جدارًا لا تنقّلًا. */
const MAX_LINKS = 8;

/**
 * محاور مصطلحٍ في أرشيفه.
 *
 * المادّة والصفّ وحدهما بُعدا المحور، فأرشيف المرحلة أو الفصل أو النوع لا
 * محور له — وقائمةٌ فارغة تعني لا قسم أصلًا لا قسمًا فارغًا.
 */
export function hubsForTerm(
  dimension: string,
  slug: string,
  hubs: readonly CurriculumHub[],
): HubLink[] {
  if (dimension !== 'subjects' && dimension !== 'grades') return [];

  return hubs
    .filter((hub) => (dimension === 'subjects' ? hub.subjectSlug === slug : hub.gradeSlug === slug))
    .slice(0, MAX_LINKS)
    .map((hub) => ({ label: hub.label, href: hub.path }));
}

/**
 * أشقّاء محورٍ قائم: ما يشاركه مادّته أو صفّه.
 *
 * وهو المعنى الذي يطلبه المعلّم — «المادّة نفسها لصفٍّ آخر» — ويستثني نفسه
 * بالمسار لا بالتسمية: تسميتان متطابقتان لمحورين مختلفين ممكنتان، والمسار
 * هويّة.
 */
export function siblingHubs(hub: CurriculumHub, hubs: readonly CurriculumHub[]): HubLink[] {
  return hubs
    .filter((other) => other.path !== hub.path)
    .filter((other) => other.subjectSlug === hub.subjectSlug || other.gradeSlug === hub.gradeSlug)
    .slice(0, MAX_LINKS)
    .map((other) => ({ label: other.label, href: other.path }));
}
