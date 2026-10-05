/**
 * Homepage section config (MIP §3.3).
 * Canonical enum = the Customizer's 13 ids; the 11-id array is the default
 * ENABLED order (`benefits` and `cta` exist but ship toggled off).
 * Later phases replace DEFAULT_ENABLED with the admin-managed
 * `homepage_sections` config (draft → preview → publish, per device).
 */
import { STAGE_ART, stageArt, type StageKey } from './art.ts';

export const HOMEPAGE_SECTION_IDS = [
  'hero',
  /*
   * قسمان منقولان عن «إعادة تصميم واجهة المتجر» (Home Components.dc.html):
   * `smart-finder` عن HeroSmartFinder، و`stages-explorer` عن
   * HeroEducationStages. وهما بديلان للهيرو القائم لا إضافةً فوقه —
   * ولذلك يُسجَّلان هنا ويبقيان **خارج** المُفعَّل حتّى يختار المالك.
   */
  'smart-finder',
  'stages-explorer',
  /*
   * أربعةٌ أخرى من «Home Components.dc.html» (widgets.tsx): حزمة الدرس،
   * ومتصفّح المناهج، وأنواع المحتوى، والأكثر طلبًا لصفّك — مسجَّلة يختارها
   * المالك من اللوحة، وخارج المُفعَّل افتراضًا.
   */
  'lesson-bundle',
  'curriculum-navigator',
  'product-types',
  'popular-by-grade',
  'trust',
  'stages',
  'featured',
  'subjects',
  'best',
  /* «كل الملفّات» — الكتالوج كلّه بالأحدث، بأوّل صفحةٍ من الخادم و«عرض المزيد». */
  'all-products',
  'samples',
  'promo',
  'why',
  'benefits',
  'reviews',
  'faq',
  'cta',
] as const;

export type HomepageSectionId = (typeof HOMEPAGE_SECTION_IDS)[number];

/** ما يعرفه كلّ قسمٍ عن الصفحة: الترتيب المحلول نفسه (ليتجنّب تكرار قسمٍ يليه). */
export interface HomeCtx {
  sections: readonly HomepageSectionId[];
}

export const DEFAULT_ENABLED_SECTIONS: readonly HomepageSectionId[] = [
  /*
   * تسلسل المالك (٢٠٢٦-٠٩-٢٥): الهيرو ← الفئات السريعة (أنواع المحتوى) ←
   * المراحل ← المختارات ← الموادّ ← العروض ← الحزم ← الأكثر مبيعًا ← (كل
   * الملفّات، قرارٌ سابق للمالك يبقى ذيلَ المنتجات) ← للمعلّمين («لماذا نحن»
   * بشخصيّتيه) ← الثقة ← التقييمات ← الدعوة الختاميّة.
   * هذا افتراضُ ما قبل النشر وحده؛ ترتيبٌ ينشره المالك من اللوحة يغلبه كما هو.
   * القسم الذي لا بيانات له (حزمةٌ لم تُنشر، حملةٌ منتهية) ينهار ولا يترك فراغًا.
   * المسجَّل خارج هذا (samples, smart-finder, stages-explorer, curriculum-navigator,
   * popular-by-grade, benefits, faq) متاحٌ للّوحة.
   */
  'hero',
  'product-types',
  'stages',
  'featured',
  'subjects',
  'promo',
  'lesson-bundle',
  'best',
  'all-products',
  'why',
  'trust',
  'reviews',
  'cta',
];

/** مُعرِّفٌ معروفٌ للمُصيِّرات — ما عداه يُسقَط ولا يُصيَّر. */
export function isSectionId(value: unknown): value is HomepageSectionId {
  return typeof value === 'string' && (HOMEPAGE_SECTION_IDS as readonly string[]).includes(value);
}

/**
 * الترتيب المنشور من اللوحة، أو الافتراضيّ.
 *
 * ─── ثلاث حالات لا حالتان ───
 *
 * `null` (٢٠٤ أو عطل) ⇒ لم يُضبط الترتيب، فالمتجر على افتراضه. وقائمةٌ
 * فارغة محفوظة ⇒ قرارُ إخلاءٍ صريح، ويُحترم. والخلط بينهما يعني أنّ عطل
 * شبكةٍ عابرًا يُفرغ الصفحة الرئيسة — أو أنّ إخلاءً مقصودًا لا يقع.
 *
 * والمجهول يُسقَط: مُعرِّفٌ بلا مُصيِّر قسمٌ يختاره المالك ولا يظهر له شيء.
 * (والتطابق لازم، فالإسقاط هنا شبكةُ أمانٍ ثانية.)
 */
/**
 * حرفا الاسم في بطاقة الرأي («نورة العتيبي» ⇒ «ن.ع»): الحرف الأوّل من أوّل
 * كلمتين، بعد إسقاط «ال» التعريف كما يكتبها القالب — «ن.ا» ليست اختصارًا.
 */
export function quoteInitials(name: string): string {
  return name
    .replace(/\./g, ' ')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => (part.length > 2 && part.startsWith('ال') ? part.slice(2) : part)[0])
    .join('.');
}

export function resolveSections(published: readonly unknown[] | null): readonly HomepageSectionId[] {
  if (published === null) return DEFAULT_ENABLED_SECTIONS;

  return published.filter(isSectionId);
}

/**
 * «شاهدتَ مؤخّرًا» ليس قسمًا يرتّبه المالك بل ذاكرة الزائر نفسه (تُرسم على
 * المتصفّح وحده)، فمكانه يُشتقّ من الترتيب: بعد «الأكثر مبيعًا»، وإلّا بعد
 * الهيرو، وإلّا أوّل الصفحة (‎null‎). وصفحةٌ أُخليت عمدًا لا يُزرع فيها شيء.
 */
export function recentAnchor(sections: readonly HomepageSectionId[]): HomepageSectionId | null | undefined {
  if (sections.length === 0) return undefined;
  if (sections.includes('best')) return 'best';
  if (sections.includes('hero')) return 'hero';
  return null;
}

// ─── المراحل والموادّ: مفتاح اللهجة، وعدّ الصفوف، والحرف البديل ───

/** مفتاح المرحلة (للرسم ولهجة `--stage-*`) من slug أو اسم — `null` حين لا تُعرف. */
export function stageKeyOf(text: string): StageKey | null {
  const hit = stageArt(text);
  if (!hit) return null;
  return (Object.keys(STAGE_ART) as StageKey[]).find((key) => STAGE_ART[key] === hit) ?? null;
}

interface StageRef {
  slug: string;
  name: string;
}

/** هل ينتمي الصفّ إلى المرحلة؟ بالـslug أوّلًا، ثمّ بمفتاح الاسم («الابتدائي» = «ابتدائي»). */
export function gradeInStage(grade: { stage?: StageRef | null }, stage: StageRef): boolean {
  if (!grade.stage) return false;
  if (grade.stage.slug === stage.slug) return true;
  const a = stageKeyOf(grade.stage.name) ?? stageKeyOf(grade.stage.slug);
  return a !== null && a === (stageKeyOf(stage.name) ?? stageKeyOf(stage.slug));
}

/**
 * الحرف البديل لمادّةٍ بلا رسمٍ صادق («دراسات إسلامية» ⇒ «د»، «التربية البدنية» ⇒ «ت»):
 * أوّل حرفٍ من أوّل كلمة بعد «ال» التعريف. لا أيقونة مستعارة.
 */
export function monogram(name: string): string {
  const first = name.trim().split(/\s+/)[0] ?? '';
  const bare = first.length > 3 && first.startsWith('ال') ? first.slice(2) : first;
  return Array.from(bare)[0] ?? '';
}

/**
 * اسم الصفّ مختصرًا داخل بطاقة مرحلته («الصف الأول الابتدائي» تحت «الابتدائي» ⇒ «الأول»):
 * تُسقط كلمة «الصف» وكلّ كلمةٍ تسمّي المرحلة نفسها. ما لا يبقى منه شيء يعود كاملًا.
 * المستهلك يضع الاسم الكامل في `aria-label` (الظاهر جزءٌ منه — ‎2.5.3‎).
 */
export function gradeShortName(gradeName: string, stageName: string): string {
  const stage = stageKeyOf(stageName);
  const kept = gradeName
    .trim()
    .split(/\s+/)
    .filter((word) => !/^(ال)?صف$/.test(word) && (stage === null || stageKeyOf(word) !== stage));
  return kept.length > 0 ? kept.join(' ') : gradeName;
}

const ORDINALS = ['أول', 'ثاني', 'ثالث', 'رابع', 'خامس', 'سادس', 'سابع', 'ثامن', 'تاسع', 'عاشر', 'حادي عشر', 'ثاني عشر'];

/**
 * رقم الصفّ لبلاطته («الصف الثالث المتوسط» ⇒ «٣»): رسم المرحلة واحدٌ لكلّ
 * صفوفها، فتكراره ستّ مرّاتٍ حشو — الرقم يميّز الصفّ، والمرحلة لهجتُه ورأسُ
 * مجموعته. بلا ترتيبٍ معروف ⇒ الحرف البديل.
 */
export function gradeGlyph(gradeName: string): string {
  const words = gradeName
    .trim()
    .split(/\s+/)
    .map((w) => (w.startsWith('ال') && w.length > 3 ? w.slice(2) : w))
    .map((w) => w.replace(/[ةه]$/, ''));
  const joined = ` ${words.join(' ')} `;
  for (let i = ORDINALS.length - 1; i >= 0; i--) {
    const want = ORDINALS[i]!.split(' ').map((w) => w.replace(/[ةه]$/, '')).join(' ');
    if (joined.includes(` ${want} `)) return String(i + 1); // أرقامٌ غربيّة كبقيّة أرقام المتجر (formatNumber)
  }
  return monogram(gradeName);
}
