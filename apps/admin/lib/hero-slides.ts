// UNIT: LIB-HERO-SLIDES
// FILE: hero-slides.ts
// STYLE: none
// PARENT: HERO-SLIDES-EDITOR
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * شرائح الهيرو — منطق المحرّر الخالص (بلا React).
 *
 * ─── مرآةٌ للخادم لا بديلٌ عنه ───
 *
 * الخادم (`HomeContent::normalizeSlide`) هو المُطبِّع الوحيد: يُسقط ويقتطع
 * ويقع على الافتراض. وهذه الدوالّ تُري المالك ما سيُسقَط **قبل** الحفظ —
 * رابطٌ لا يبدأ بـ`/` أو `https://`، عنوانٌ فارغ — فلا يكتشف بعد الحفظ أنّ
 * زرّه اختفى بصمت. وأيّ خلافٍ بينهما يُحسم لصالح الخادم.
 *
 * ─── ولماذا ملفٌّ مستقلّ ───
 *
 * `node --test` يختبره مباشرةً (Node يجرّد الأنواع) بلا بيئة متصفّح.
 */

export interface SlideLinkDraft {
  label: string;
  href: string;
}

export interface SlideDraft {
  /** مُعرِّف الخادم (`s_…`)، أو null لشريحةٍ لم تُحفظ بعد. */
  id: string | null;
  /** مفتاحٌ محلّيّ ثابت للعرض والسحب — لا يُرسل. */
  key: string;
  kind: string;
  enabled: boolean;
  eyebrow: string;
  title: string;
  titleAccent: string;
  subtitle: string;
  primary: SlideLinkDraft;
  secondary: SlideLinkDraft;
  theme: string;
  align: string;
  overlay: string;
  mediaUrl: string;
  mediaAlt: string;
  mediaFocal: string;
  showProductCard: boolean;
  offerBadge: string;
  /** بصيغة `datetime-local` (توقيت المتصفّح)، أو فارغ. */
  offerEndsAt: string;
  offerCoupon: string;
  searchPlaceholder: string;
  chips: SlideLinkDraft[];
}

/** حدود الأطوال كما يرسلها الخادم في `schema.limits`. */
export type SlideLimits = Record<string, number>;

/**
 * خلفيّة المعاينة للمواضيع الستّة — رموز المتجر نفسها (`--hero-<موضوع>-bg` في
 * tokens.css) لا تقريبٌ حرفيّ: التقريب القديم كان قد انحرف (موضوع «الحبر»
 * أخضرُ الهويّة السابقة بينما المتجر كحليّ).
 */
export const THEME_SWATCHES: Record<string, string> = {
  'navy-teal': 'var(--hero-navy-teal-bg)',
  navy: 'var(--hero-navy-bg)',
  teal: 'var(--hero-teal-bg)',
  sand: 'var(--hero-sand-bg)',
  mint: 'var(--hero-mint-bg)',
  ink: 'var(--hero-ink-bg)',
};

const str = (value: unknown): string => (typeof value === 'string' ? value : '');

const record = (value: unknown): Record<string, unknown> =>
  value !== null && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};

/** وجهةٌ يقبلها الخادم: `/مسار` (لا `//` ولا `/\`) أو `https://`، بلا فراغات. */
export function isSafeHref(href: string): boolean {
  const value = href.trim();
  if (value === '' || /\s/u.test(value)) return false;
  return /^\/(?![/\\])/.test(value) || /^https:\/\/[^/\s]+/i.test(value);
}

/**
 * رابط وسيط يقبله الخادم: https، أو `/storage/…` و`/media/…`، أو أصل
 * القرص العامّ (`…/storage/…`) — والأخير يقرّره الخادم نهائيًّا.
 */
export function isMediaUrl(url: string): boolean {
  const value = url.trim();
  if (value === '' || /\s/u.test(value)) return false;
  return (
    /^https:\/\/[^/\s]+\//i.test(value) ||
    /^\/(storage|media)\/[^/]/.test(value) ||
    /^https?:\/\/[^/\s]+\/storage\/[^/]/i.test(value)
  );
}

let counter = 0;
/** مفتاح محلّيّ فريد في الجلسة — للعرض والسحب لا للخادم. */
export function localKey(): string {
  counter += 1;
  return `k${Date.now().toString(36)}${counter}`;
}

function link(value: unknown): SlideLinkDraft {
  const object = record(value);
  return { label: str(object.label), href: str(object.href) };
}

/** ISO ← قيمة `datetime-local` في توقيت المتصفّح. */
export function isoToLocalInput(iso: string): string {
  if (iso === '') return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** قيمة `datetime-local` ← ISO بإزاحةٍ صريحة (UTC)، أو null. */
export function localInputToIso(local: string): string | null {
  if (local.trim() === '') return null;
  const date = new Date(local);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/** شريحةٌ جديدة بنوعها وافتراضات العقد. */
export function emptySlide(kind: string): SlideDraft {
  return {
    id: null,
    key: localKey(),
    kind,
    enabled: true,
    eyebrow: '',
    title: '',
    titleAccent: '',
    subtitle: '',
    primary: { label: '', href: '' },
    secondary: { label: '', href: '' },
    theme: 'navy-teal',
    align: 'start',
    overlay: 'soft',
    mediaUrl: '',
    mediaAlt: '',
    mediaFocal: 'center',
    showProductCard: kind === 'brand',
    offerBadge: '',
    offerEndsAt: '',
    offerCoupon: '',
    searchPlaceholder: '',
    chips: [],
  };
}

/** المخزَّن (شكل الكاتب) ← مسودّات. وما ليس كائنًا يُتجاهل. */
export function slidesFromStored(value: unknown): SlideDraft[] {
  if (!Array.isArray(value)) return [];
  return value
    .filter((row) => row !== null && typeof row === 'object')
    .map((row) => {
      const object = record(row);
      const media = record(object.media);
      const offer = record(object.offer);
      const search = record(object.search);
      const base = emptySlide(str(object.kind) || 'brand');
      return {
        ...base,
        id: str(object.id) || null,
        enabled: object.enabled !== false,
        eyebrow: str(object.eyebrow),
        title: str(object.title),
        titleAccent: str(object.titleAccent),
        subtitle: str(object.subtitle),
        primary: link(object.primary),
        secondary: link(object.secondary),
        theme: str(object.theme) || base.theme,
        align: str(object.align) || base.align,
        overlay: str(object.overlay) || base.overlay,
        mediaUrl: str(media.url),
        mediaAlt: str(media.alt),
        mediaFocal: str(media.focal) || base.mediaFocal,
        showProductCard: object.showProductCard === true,
        offerBadge: str(offer.badge),
        offerEndsAt: isoToLocalInput(str(offer.endsAt)),
        offerCoupon: str(offer.couponCode),
        searchPlaceholder: str(search.placeholder),
        chips: Array.isArray(search.chips) ? search.chips.map(link) : [],
      };
    });
}

const linkOut = (value: SlideLinkDraft) =>
  value.label.trim() === '' && value.href.trim() === '' ? null : { label: value.label, href: value.href };

/** مسودّات ← حمولة الكاتب. ولا تطبيع هنا: الخادم هو المُطبِّع. */
export function slidesToPayload(slides: readonly SlideDraft[]): Record<string, unknown>[] {
  return slides.map((slide) => ({
    ...(slide.id !== null ? { id: slide.id } : {}),
    kind: slide.kind,
    enabled: slide.enabled,
    eyebrow: slide.eyebrow,
    title: slide.title,
    titleAccent: slide.titleAccent,
    subtitle: slide.subtitle,
    primary: linkOut(slide.primary),
    secondary: linkOut(slide.secondary),
    theme: slide.theme,
    align: slide.align,
    overlay: slide.overlay,
    media: slide.mediaUrl.trim() === '' ? null : { url: slide.mediaUrl, alt: slide.mediaAlt, focal: slide.mediaFocal },
    showProductCard: slide.showProductCard,
    offer: { badge: slide.offerBadge, endsAt: localInputToIso(slide.offerEndsAt), couponCode: slide.offerCoupon },
    search: { placeholder: slide.searchPlaceholder, chips: slide.chips.map((chip) => ({ ...chip })) },
  }));
}

/** نسخةٌ بلا مُعرِّف — الخادم يولّد للنسخة مُعرِّفها. */
export function duplicateSlide(slide: SlideDraft): SlideDraft {
  return {
    ...slide,
    id: null,
    key: localKey(),
    primary: { ...slide.primary },
    secondary: { ...slide.secondary },
    chips: slide.chips.map((chip) => ({ ...chip })),
  };
}

/** نقل عنصرٍ من موضعٍ إلى آخر — نسخةٌ جديدة، والمواضع خارج الحدود لا تغيّر شيئًا. */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
  if (from < 0 || from >= list.length || to < 0 || to >= list.length || from === to) return [...list];
  const next = [...list];
  const [item] = next.splice(from, 1);
  if (item !== undefined) next.splice(to, 0, item);
  return next;
}

/** إعادة ترتيبٍ بقائمة مفاتيح (نتيجة السحب). */
export function reorderByKeys(slides: readonly SlideDraft[], keys: readonly string[]): SlideDraft[] {
  const byKey = new Map(slides.map((slide) => [slide.key, slide]));
  const ordered = keys.map((key) => byKey.get(key)).filter((slide): slide is SlideDraft => slide !== undefined);
  return ordered.length === slides.length ? ordered : [...slides];
}

/**
 * أخطاء شريحةٍ بمفتاح الحقل — مرآةُ ما سيُسقطه الخادم أو يقتطعه.
 *
 * `fields` قائمة حقول نوعها (من `schema`)، فلا يُحاسَب حقلٌ لا يظهر.
 */
export function slideIssues(
  slide: SlideDraft,
  fields: readonly string[],
  limits: SlideLimits,
  chipsMax: number,
): Record<string, string> {
  const issues: Record<string, string> = {};
  const has = (name: string) => fields.includes(name);
  const tooLong = (name: string, value: string, limit: string = name) => {
    const max = limits[limit];
    if (max !== undefined && value.trim().length > max) issues[name] = `الحدّ ${max} حرفًا — سيُقتطع الزائد.`;
  };

  if (slide.title.trim() === '') issues.title = 'العنوان إلزاميّ — بدونه تُسقَط الشريحة.';
  for (const name of ['title', 'eyebrow', 'titleAccent', 'subtitle'] as const) {
    if (has(name) && issues[name] === undefined) tooLong(name, slide[name]);
  }

  const checkLink = (name: string, value: SlideLinkDraft) => {
    const label = value.label.trim();
    const href = value.href.trim();
    if (label === '' && href === '') return;
    if (label === '' || href === '') issues[name] = 'الزرّ يحتاج نصًّا ووجهةً معًا — وإلّا لا يظهر.';
    else if (!isSafeHref(href)) issues[name] = 'الوجهة تبدأ بـ / (صفحة في المتجر) أو https://';
  };
  if (has('primary')) checkLink('primary', slide.primary);
  if (has('secondary')) checkLink('secondary', slide.secondary);

  if (has('media') && slide.mediaUrl.trim() !== '' && !isMediaUrl(slide.mediaUrl)) {
    issues.media = 'رابط الصورة من مكتبة المتجر (/storage/…) أو يبدأ بـ https://';
  }

  if (has('offer') && slide.offerEndsAt !== '' && localInputToIso(slide.offerEndsAt) === null) {
    issues.offer = 'موعد الانتهاء غير صالح.';
  }

  if (has('search')) {
    if (slide.chips.length > chipsMax) issues.search = `${chipsMax} اختصارات على الأكثر.`;
    else if (slide.chips.some((chip) => chip.label.trim() === '' || !isSafeHref(chip.href))) {
      issues.search = 'كلّ اختصار يحتاج نصًّا ووجهةً تبدأ بـ / أو https://';
    }
  }

  return issues;
}
