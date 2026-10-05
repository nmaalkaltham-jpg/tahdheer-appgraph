/**
 * طبقةُ اشتقاق عرض بطاقة المنتج — من ردّ الخادم إلى ما تعرضه البطاقة، بلا React
 * ولا متصفّح (تُختبر بـ`node --test`). يمرّ بها الكتالوج والبحث معًا
 * (`toCardProps` و`searchCardToProps`)، فلا تختلف بطاقتان على المنتج نفسه.
 *
 * القاعدة: كلّ ما يُعرض مشتقٌّ من حقلٍ حقيقيّ في الردّ، ولا يُختلق ما غاب.
 *  · «جديد» من `publishedAt` داخل نافذةٍ معلَنة (`NEW_WINDOW_DAYS`)، لا من وسمٍ يدويّ.
 *  · الصيغ من صيغ المجموعة المنشورة (`formats`) وإلّا من العمود الواحد (`format`)،
 *    بأسماء الجدول نفسه الذي تكتب به صفحة المنتج (`FORMAT_WORD`).
 *  · «تحميل فوري» بقاعدة صفحة المنتج نفسها: الطبيعة أوّلًا ثمّ العلَم الرقميّ.
 *  · الصفّ والفصل وعلَم العيّنة كما أرسلها الخادم، أو لا شيء.
 *  · لا سعرَ في الردّ ⇒ `unavailable` (البطاقة تُخفي السعر وتعطّل الزرّ).
 *
 * حساب السعر (القديم والوفر والنسبة) في `@tahdheer/ui/productCardPrice` — البطاقة نفسها.
 */

/** نافذة «جديد»: منتجٌ نُشر خلال هذه الأيّام يحمل الشارة. */
export const NEW_WINDOW_DAYS = 30;

const DAY_MS = 24 * 60 * 60 * 1000;

/** هل المنتج جديد؟ تاريخٌ غائب أو غير صالح أو في المستقبل ⇒ لا. */
export function isNewProduct(publishedAt: string | null | undefined, now: number = Date.now()): boolean {
  if (typeof publishedAt !== 'string' || publishedAt === '') return false;
  const at = Date.parse(publishedAt);
  if (Number.isNaN(at) || at > now) return false;
  return now - at <= NEW_WINDOW_DAYS * DAY_MS;
}

/**
 * أسماء الصيغ المعروضة — الكلمات نفسها التي تكتب بها صفحة المنتج صيغها
 * (`FORMAT_WORD` في `app/products/[slug]/page.tsx`) مع أخواتها القديمة
 * (‎DOC/PPT/XLS‎) والأرشيف. الامتداد المجهول يبقى كما ورد — لا يُخترع له اسم.
 */
const FORMAT_LABELS: Readonly<Record<string, string>> = {
  DOCX: 'Word',
  DOC: 'Word',
  PPTX: 'PowerPoint',
  PPT: 'PowerPoint',
  PDF: 'PDF',
  XLSX: 'Excel',
  XLS: 'Excel',
  ZIP: 'ZIP',
};

/** اسم الصيغة المعروض؛ امتدادٌ غير مسجَّل يبقى كما ورد. */
export function formatLabel(code: string): string {
  const key = code.trim().toUpperCase();
  return FORMAT_LABELS[key] ?? code.trim();
}

/** قائمة الصيغ المعروضة بلا تكرار وبترتيب ورودها («PDF + Word» تُركَّب في البطاقة). */
export function formatLabels(codes: readonly string[]): string[] {
  const out: string[] = [];
  for (const code of codes) {
    const label = formatLabel(code);
    if (label !== '' && !out.includes(label)) out.push(label);
  }
  return out;
}

/** طبيعة المنتج كما يرسلها الخادم (‎DIGITAL/PHYSICAL/SERVICE/HYBRID/ACCESS_BASED/COMPOSITE‎) أو غيابها. */
type Nature = string | null | undefined;

/**
 * هل يُسلَّم شيءٌ من المنتج تحميلًا فور الدفع؟ القاعدة الواحدة لصفحة المنتج
 * والبطاقة: الهجين نعم، والملموس والخدمة لا، وما عداهما بالعلَم الرقميّ.
 */
export function hasInstantDownload(product: { productNature?: Nature; digital?: boolean }): boolean {
  if (product.productNature === 'HYBRID') return true;
  if (product.productNature === 'PHYSICAL' || product.productNature === 'SERVICE') return false;
  return product.digital === true;
}

/** الحقول التي تقرؤها الاشتقاقات من الردّ — لا الـDTO كاملًا. */
export interface CardSignalSource {
  digital?: boolean;
  productNature?: Nature;
  publishedAt?: string | null;
  bestSeller?: boolean;
  format?: string;
  formats?: readonly string[] | null;
  resourceType?: string | null;
  gradeName?: string | null;
  semesterName?: string | null;
  hasSample?: boolean;
  priceMinor?: number;
}

export interface CardSignals {
  instantDownload: boolean;
  isNew: boolean;
  bestSeller: boolean;
  hasSample: boolean;
  unavailable: boolean;
  formats: string[];
  resourceType: string | undefined;
  grade: string | undefined;
  semester: string | undefined;
}

const text = (value: string | null | undefined): string | undefined => {
  const trimmed = value?.trim();
  return trimmed !== undefined && trimmed !== '' ? trimmed : undefined;
};

/** اشتقاقات البطاقة من ردّ الخادم — الحقيقيّ فقط، والغائب يبقى غائبًا. */
export function cardSignals(dto: CardSignalSource, now: number = Date.now()): CardSignals {
  const codes =
    Array.isArray(dto.formats) && dto.formats.length > 0
      ? dto.formats
      : dto.format && dto.format.trim() !== ''
        ? [dto.format]
        : [];
  return {
    instantDownload: hasInstantDownload(dto),
    isNew: isNewProduct(dto.publishedAt, now),
    bestSeller: dto.bestSeller === true,
    hasSample: dto.hasSample === true,
    unavailable: typeof dto.priceMinor !== 'number' || !Number.isFinite(dto.priceMinor),
    formats: formatLabels(codes),
    resourceType: text(dto.resourceType),
    grade: text(dto.gradeName),
    semester: text(dto.semesterName),
  };
}
