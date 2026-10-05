/**
 * سجلّ رسوم المتجر — المصدر الوحيد لمسارات `public/art/**` وأبعادها وإسنادها.
 *
 * الملفّات يولّدها `scripts/art/build.py` من صحائف Freepik (لا تُحرَّر يدويًّا)،
 * والأبعاد هنا تطابق `scripts/art/manifest.json` — الاختبار يقارنها بترويسة WebP.
 *
 * العقد (BRIEF3 §1):
 * - `src` هو ملفّ `@2x`، و`width`/`height` مقاس العرض `1x`؛ `next/image` يولّد
 *   مجموعة المصادر منه. `@2x` لا يتجاوز دقّة المصدر أبدًا (لا تكبير).
 * - `darkSrc` (إن وُجد) نسخةٌ لخطوطٍ داكنة قُلبت فاتحة لسطوح `[data-theme='dark']`؛
 *   ما لا نسخة له مقروءٌ على الداكن كما هو.
 * - `alt` فارغ للرسم الزخرفيّ (الحالات الفارغة، أجزاء البطل)؛ وإن جاور الرسمَ
 *   اسمُ المادّة أو المرحلة نصًّا فليمرّر المستهلك `alt=""` تجنّبًا للتكرار.
 * - لا رسمَ بلا `credit` — صفحة «مصادر الرسوم» تُبنى من `CREDITS`.
 */
import { normalizeArabic } from '../../../packages/registry/src/arabic.ts';

export type CreditKey =
  | 'macrovector'
  | 'macrovector_official'
  | 'katemangostar'
  | 'rawpixel'
  | 'freepik'
  | 'mada';

export type Art = {
  src: string;
  width: number;
  height: number;
  alt: string;
  credit: CreditKey;
  /** نسخة السطوح الداكنة — بالأبعاد نفسها. */
  darkSrc?: string;
};

export const CREDITS: Record<CreditKey, { author: string; source: string; url: string }> = {
  macrovector: { author: 'macrovector', source: 'Freepik', url: 'https://www.freepik.com' },
  macrovector_official: { author: 'macrovector_official', source: 'Freepik', url: 'https://www.freepik.com' },
  katemangostar: { author: 'Katemangostar', source: 'Freepik', url: 'https://www.freepik.com' },
  rawpixel: { author: 'rawpixel.com', source: 'Freepik', url: 'https://www.freepik.com' },
  freepik: { author: 'Freepik', source: 'Freepik', url: 'https://www.freepik.com' },
  // علامةٌ تجاريّة لا رسمٌ مرخَّص: تُعرض كما هي لبيان وسيلة الدفع.
  mada: { author: 'الشبكة السعوديّة للمدفوعات (مدى)', source: 'mada', url: 'https://www.mada.com.sa' },
};

function art(
  path: string,
  width: number,
  height: number,
  alt: string,
  credit: CreditKey,
  dark = false,
): Art {
  const item: Art = { src: `/art/${path}@2x.webp`, width, height, alt, credit };
  if (dark) item.darkSrc = `/art/${path}-on-dark@2x.webp`;
  return item;
}

const S = 192;

export const SUBJECT_ART = {
  math: art('subjects/math', S, S, 'آلة حاسبة', 'macrovector'),
  science: art('subjects/science', S, S, 'مجهر', 'macrovector'),
  chemistry: art('subjects/chemistry', S, S, 'دورق كيمياء بفقاعات', 'katemangostar'),
  physics: art('subjects/physics', S, S, 'ذرّة', 'macrovector', true),
  biology: art('subjects/biology', S, S, 'شريط الحمض النوويّ', 'macrovector', true),
  arabic: art('subjects/arabic', S, S, 'كتاب مفتوح', 'freepik'),
  english: art('subjects/english', S, S, 'سمّاعة بحروف إنجليزيّة', 'macrovector'),
  socialStudies: art('subjects/social-studies', S, S, 'مجسّم الكرة الأرضيّة', 'macrovector'),
  computer: art('subjects/computer', S, S, 'حاسوب محمول', 'macrovector_official'),
  criticalThinking: art('subjects/critical-thinking', S, S, 'دماغ', 'macrovector'),
} as const satisfies Record<string, Art>;

export type SubjectKey = keyof typeof SUBJECT_ART;
export type StageKey = 'kindergarten' | 'primary' | 'middle' | 'secondary';

export const STAGE_ART: Record<StageKey, Art> = {
  kindergarten: art('stages/kindergarten', S, S, 'صاروخ', 'macrovector'),
  primary: art('stages/primary', S, S, 'طفلٌ يقرأ كتابًا', 'macrovector'),
  middle: art('stages/middle', S, S, 'مقراب', 'macrovector'),
  secondary: art('stages/secondary', S, S, 'قبّعة التخرّج', 'macrovector', true),
};

const E = 300;

export const EMPTY_ART: Record<'cart' | 'library' | 'wishlist' | 'search' | 'orders' | 'downloads' | 'generic', Art> = {
  cart: art('empty/cart', E, E, '', 'macrovector'),
  library: art('empty/library', E, E, '', 'macrovector_official'),
  wishlist: art('empty/wishlist', E, E, '', 'macrovector'),
  search: art('empty/search', E, E, '', 'macrovector'),
  orders: art('empty/orders', E, E, '', 'macrovector_official'),
  downloads: art('empty/downloads', E, E, '', 'macrovector_official'),
  generic: art('empty/generic', E, E, '', 'macrovector_official'),
};

export const HERO_ART = {
  /** مشهد رفوف المختبر — `vectorSrc` للعرض المتّجه (`<img>` أو `unoptimized`). */
  labScene: {
    ...art('hero/lab-scene', 600, 584, '', 'rawpixel', true),
    vectorSrc: '/art/hero/lab-scene.svg',
    darkVectorSrc: '/art/hero/lab-scene-on-dark.svg',
  },
  labBench: art('hero/lab-bench', 600, 342, '', 'rawpixel', true),
  globe: art('hero/globe', 480, 480, '', 'freepik'),
} as const;

export const TEACHER_ART = {
  man: art('people/teacher-man', 240, 320, 'معلّمٌ يكتب', 'macrovector'),
  woman: art('people/teacher-woman', 240, 320, 'معلّمةٌ أمام حاسوبها', 'macrovector'),
} as const;

export const PAYMENT_ART = {
  mada: art('payment/mada', 120, 40, 'مدى', 'mada', true),
} as const;

// ─── المطابقة ───

/** تطبيعٌ للمطابقة: مطبِّع السجلّ المشترك + فصل الشرطات + نزع «ال» من أوّل كلّ كلمة. */
function tokens(text: string): string[] {
  return normalizeArabic(text.replace(/[-_]+/g, ' '))
    .split(' ')
    .filter(Boolean)
    .map((t) => (t.startsWith('ال') && t.length >= 4 ? t.slice(2) : t));
}

type Rule<K> = readonly [K | null, readonly string[]];

/** أوّل قاعدةٍ تحوي إحدى عباراتها كلماتٍ متتالية كاملة — لا مطابقة جزئيّة داخل كلمة («معلومات» ليست «علوم»). */
function matchRules<K>(input: string, rules: readonly Rule<K>[]): K | null {
  const have = tokens(input);
  if (have.length === 0) return null;
  const joined = ` ${have.join(' ')} `;
  for (const [key, phrases] of rules) {
    for (const phrase of phrases) {
      const want = tokens(phrase);
      if (want.length && joined.includes(` ${want.join(' ')} `)) return key;
    }
  }
  return null;
}

/**
 * الترتيب مقصود: الموادّ الشرعيّة أوّلًا وتُرجع `null` (لا أيقونة مستعارة —
 * «العلوم الشرعيّة» ليست علومًا، و«الدراسات الإسلاميّة» ليست اجتماعيّات)،
 * ثمّ الخاصّ قبل العامّ (فيزياء قبل علوم).
 */
const SUBJECT_RULES: readonly Rule<SubjectKey>[] = [
  // «حديث» عمدًا غائبة: «التاريخ الحديث» اجتماعيّات؛ ومادّة «الحديث» لا تطابق شيئًا فتُرجع null أصلًا.
  [null, ['إسلامية', 'إسلامي', 'قرآن', 'تجويد', 'توحيد', 'فقه', 'تفسير', 'شرعية', 'دينية', 'islamic', 'quran', 'tajweed', 'fiqh', 'hadith', 'tawheed']],
  ['physics', ['فيزياء', 'physics']],
  ['chemistry', ['كيمياء', 'chemistry']],
  ['biology', ['أحياء', 'biology']],
  ['math', ['رياضيات', 'math', 'maths', 'mathematics']],
  ['criticalThinking', ['تفكير ناقد', 'تفكير', 'critical thinking']],
  ['computer', ['حاسب', 'حاسوب', 'مهارات رقمية', 'رقمية', 'تقنية', 'برمجة', 'computer', 'digital', 'ict', 'technology', 'coding']],
  ['english', ['إنجليزي', 'إنجليزية', 'انكليزي', 'english']],
  ['arabic', ['لغتي', 'عربي', 'عربية', 'arabic', 'lughati', 'lughaty']],
  ['socialStudies', ['اجتماعيات', 'اجتماعية', 'جغرافيا', 'تاريخ', 'وطنية', 'social', 'social studies', 'geography', 'history']],
  ['science', ['علوم', 'science', 'sciences']],
];

const STAGE_RULES: readonly Rule<StageKey>[] = [
  ['kindergarten', ['روضة', 'رياض أطفال', 'تمهيدي', 'طفولة مبكرة', 'kg', 'kindergarten', 'preschool']],
  ['primary', ['ابتدائي', 'ابتدائية', 'primary', 'elementary']],
  ['middle', ['متوسط', 'متوسطة', 'middle', 'intermediate']],
  ['secondary', ['ثانوي', 'ثانوية', 'secondary', 'high school']],
];

/** رسم المادّة من slug أو اسمٍ عربيّ؛ `null` حين لا يوجد رسمٌ صادق لها. */
export function subjectArt(subjectSlugOrName: string): Art | null {
  const key = matchRules(subjectSlugOrName ?? '', SUBJECT_RULES);
  return key ? SUBJECT_ART[key] : null;
}

/** رسم المرحلة من slug أو اسمٍ («الصف الأول المتوسط» ← المتوسطة). */
export function stageArt(stageSlugOrName: string): Art | null {
  const key = matchRules(stageSlugOrName ?? '', STAGE_RULES);
  return key ? STAGE_ART[key] : null;
}
