/**
 * إعدادُ الهيرو — مفرداتٌ مغلقة يحرّرها المالك.
 *
 * ─── لماذا يُطبَّع هنا لا في الخلفيّة ───
 *
 * `HomeContent::HERO_FIELDS` يخزّن هذه الحقول نصًّا ويطبّعها نصًّا، بلا علمٍ
 * بمفرداتها. والقاعدة نفسها التي يمشي عليها `layout`: ما لا يعرفه المُصيِّر
 * يُسقَط ويقع على افتراضه. فمطبعةٌ في اللوحة أو قيمةٌ من إصدارٍ أقدم لا
 * تكسر الصفحة الرئيسة — تُعطي الهيرو الافتراضيّ.
 *
 * وأسماءُ المفردات جزءٌ من العقد مع اللوحة: تغييرُ واحدةٍ هنا يعني قيمةً
 * محفوظةً صارت مجهولة، فتقع على الافتراض صامتةً. تُضاف ولا تُعاد تسميتها.
 */

/**
 * تصاميم الهيرو الخمسة — كتل «Storefront Redesign.dc.html» واحدةً لواحدة:
 *   `slider` ← 3a (Storefront Home.dc.html، الافتراضيّ) · `institutional` ← 1a
 *   «مؤسسي» · `bold` ← 1b «جريء» · `luxe` ← 1c «داكن راقي» · `brand` ← 2a
 *   «هويّة الشعار». والقيم القديمة (`cinematic`/`discovery`/`story`) لا مُصيِّر
 *   لها بعد اليوم فتقع على `slider` — كأيّ مفردةٍ مجهولة.
 */
const HERO_VARIANTS = ['slider', 'institutional', 'bold', 'luxe', 'brand'] as const;
type HeroVariant = (typeof HERO_VARIANTS)[number];

/** محاذاةُ كتلة النصّ داخل المسرح. */
const HERO_ALIGNS = ['start', 'center'] as const;
type HeroAlign = (typeof HERO_ALIGNS)[number];

/** شدّةُ الحجاب فوق الوسيط — تحمي التباين حين تكون الصورة فاتحة أو مزدحمة. */
const HERO_OVERLAYS = ['none', 'soft', 'strong'] as const;
type HeroOverlay = (typeof HERO_OVERLAYS)[number];

/** أسلوبُ الحركة — تُقرأ وتُطبَّع كما كانت؛ كتل اللوحة الخمس ساكنةٌ بطبعها. */
const HERO_ANIMATIONS = ['none', 'fade', 'rise', 'parallax'] as const;
type HeroAnimation = (typeof HERO_ANIMATIONS)[number];

export interface HeroPresentation {
  variant: HeroVariant;
  align: HeroAlign;
  overlay: HeroOverlay;
  animation: HeroAnimation;
  mediaUrl: string | null;
  mediaAlt: string;
}

function pick<T extends string>(allowed: readonly T[], value: unknown, fallback: T): T {
  return typeof value === 'string' && (allowed as readonly string[]).includes(value)
    ? (value as T)
    : fallback;
}

export function heroPresentation(raw: {
  variant?: string;
  align?: string;
  overlay?: string;
  animation?: string;
  media_url?: string;
  media_alt?: string;
}): HeroPresentation {
  const mediaUrl = typeof raw.media_url === 'string' && raw.media_url.trim() !== ''
    ? raw.media_url.trim()
    : null;

  return {
    variant: pick(HERO_VARIANTS, raw.variant, 'slider'),
    align: pick(HERO_ALIGNS, raw.align, 'start'),
    overlay: pick(HERO_OVERLAYS, raw.overlay, 'soft'),
    animation: pick(HERO_ANIMATIONS, raw.animation, 'rise'),
    mediaUrl,
    /*
     * نصٌّ بديلٌ فارغ حين لا يكتبه المحرّر: الصورة زخرفيّة عندئذٍ
     * (`alt=""`) ولا تُخترع لها وصفًا. ووصفٌ مُخترع أسوأ من لا وصف.
     */
    mediaAlt: typeof raw.media_alt === 'string' ? raw.media_alt.trim() : '',
  };
}

/* ═════════════════════════════════════════ الشرائح المحرَّرة (العقد ٢٠٢٦-٠٩-٢٥) ═══
 *
 * `docs/architecture/HERO-SLIDER-CONTRACT-2026-09-25.md` §1 — الحقلان
 * `slider` و`slides` في `/home/hero`. القاعدة نفسها: «طبّع ولا ترفض» —
 * ما ليس في قائمةٍ مغلقة يقع على افتراضه، والرقم يُحصر في مداه، والرابط
 * الذي ليس مسارًا داخليًّا ولا `https://` يُسقط. شريحةٌ بلا عنوانٍ لا تُصيَّر.
 */

const SLIDE_KINDS = ['brand', 'offer', 'search', 'image'] as const;
type SlideKind = (typeof SLIDE_KINDS)[number];

const SLIDE_THEMES = ['navy-teal', 'navy', 'teal', 'sand', 'mint', 'ink'] as const;
export type SlideTheme = (typeof SLIDE_THEMES)[number];

/** نبرة الموضوع: الداكن نصّه أبيض ودعوته ذهبيّة، والفاتح نصّه حبر ودعوته كحليّة. */
export const THEME_TONE: Record<SlideTheme, 'dark' | 'light'> = {
  'navy-teal': 'dark',
  navy: 'dark',
  teal: 'dark',
  ink: 'dark',
  sand: 'light',
  mint: 'light',
};

const SLIDER_TRANSITIONS = ['slide', 'fade'] as const;
const SLIDER_HEIGHTS = ['compact', 'standard', 'tall'] as const;
const SLIDER_AMBIENTS = ['none', 'drift', 'kenburns'] as const;
const MEDIA_FOCALS = ['center', 'top', 'bottom'] as const;

const INTERVAL_MIN = 3000;
const INTERVAL_MAX = 15000;
export const SLIDES_MAX = 8;
const TITLE_MAX = 120;
const SUBTITLE_MAX = 240;
const CHIPS_MAX = 8;

export interface SliderSettings {
  autoplay: boolean;
  intervalMs: number;
  transition: (typeof SLIDER_TRANSITIONS)[number];
  continuous: boolean;
  pauseOnHover: boolean;
  showArrows: boolean;
  showDots: boolean;
  showProgress: boolean;
  height: (typeof SLIDER_HEIGHTS)[number];
  ambient: (typeof SLIDER_AMBIENTS)[number];
}

export const SLIDER_DEFAULTS: SliderSettings = {
  autoplay: true,
  intervalMs: 6000,
  transition: 'slide',
  continuous: true,
  /* العقد §1: الهيرو لا يتوقّف عند التحويم/التركيز افتراضًا (طلب المالك). */
  pauseOnHover: false,
  showArrows: true,
  showDots: true,
  showProgress: true,
  height: 'standard',
  ambient: 'drift',
};

interface HeroLink {
  label: string;
  href: string;
}

/** رقاقة بحث — `mobile: false` تُخفى تحت ‎767px‎، و`strong` رقاقةٌ داكنة. */
export interface HeroChip extends HeroLink {
  mobile: boolean;
  strong?: boolean;
}

export interface HeroSlide {
  id: string;
  kind: SlideKind;
  eyebrow: string | null;
  title: string;
  titleAccent: string | null;
  subtitle: string | null;
  primary: HeroLink | null;
  secondary: HeroLink | null;
  theme: SlideTheme;
  align: HeroAlign;
  media: { url: string; alt: string; focal: (typeof MEDIA_FOCALS)[number] } | null;
  overlay: HeroOverlay;
  showProductCard: boolean;
  offer: { badge: string | null; endsAt: string | null; couponCode: string | null } | null;
  search: { placeholder: string | null; chips: HeroChip[] } | null;
}

type Rec = Record<string, unknown>;
const rec = (v: unknown): Rec | null => (v && typeof v === 'object' && !Array.isArray(v) ? (v as Rec) : null);
const bool = (v: unknown, fallback: boolean) => (typeof v === 'boolean' ? v : fallback);

/** نصٌّ مشذَّب أو `null` — الفراغ المحض ليس نصًّا، والطويل يُقصّ عند حدّه. */
function text(v: unknown, max = 400): string | null {
  if (typeof v !== 'string') return null;
  const t = v.trim();
  return t === '' ? null : t.slice(0, max);
}

/** مسارٌ داخليّ (`/x` لا `//x`) أو `https://` — وما سواهما (`javascript:`، `http:`) يُسقط. */
export function safeHref(v: unknown): string | null {
  const t = text(v, 2048);
  if (!t) return null;
  if (t.startsWith('/') && !t.startsWith('//')) return t;
  return /^https:\/\/[^\s/]+/i.test(t) ? t : null;
}

function link(v: unknown): HeroLink | null {
  const r = rec(v);
  if (!r) return null;
  const label = text(r.label, 60);
  const href = safeHref(r.href);
  return label && href ? { label, href } : null;
}

export function heroSlider(raw: unknown): SliderSettings {
  const r = rec(raw) ?? {};
  const d = SLIDER_DEFAULTS;
  const ms = typeof r.intervalMs === 'number' && Number.isFinite(r.intervalMs) ? Math.round(r.intervalMs) : d.intervalMs;
  return {
    autoplay: bool(r.autoplay, d.autoplay),
    intervalMs: Math.min(INTERVAL_MAX, Math.max(INTERVAL_MIN, ms)),
    transition: pick(SLIDER_TRANSITIONS, r.transition, d.transition),
    continuous: bool(r.continuous, d.continuous),
    pauseOnHover: bool(r.pauseOnHover, d.pauseOnHover),
    showArrows: bool(r.showArrows, d.showArrows),
    showDots: bool(r.showDots, d.showDots),
    showProgress: bool(r.showProgress, d.showProgress),
    height: pick(SLIDER_HEIGHTS, r.height, d.height),
    ambient: pick(SLIDER_AMBIENTS, r.ambient, d.ambient),
  };
}

/** موضوعُ كلّ نوعٍ حين لا يُختار: كما تبدو الشرائح الثلاث الافتراضيّة اليوم. */
const KIND_THEME: Record<SlideKind, SlideTheme> = { brand: 'navy-teal', offer: 'sand', search: 'mint', image: 'ink' };

function slide(raw: unknown, index: number): HeroSlide | null {
  const r = rec(raw);
  if (!r) return null;
  const title = text(r.title, TITLE_MAX);
  if (!title) return null;
  const kind = pick(SLIDE_KINDS, r.kind, 'brand');
  const m = rec(r.media);
  const mediaUrl = m ? text(m.url, 2048) : null;
  const o = kind === 'offer' ? rec(r.offer) : null;
  const s = kind === 'search' ? rec(r.search) : null;
  const endsAt = o ? text(o.endsAt, 64) : null;

  return {
    id: text(r.id, 64) ?? `s_${index}`,
    kind,
    eyebrow: text(r.eyebrow, 80),
    title,
    titleAccent: text(r.titleAccent, 60),
    subtitle: text(r.subtitle, SUBTITLE_MAX),
    primary: link(r.primary),
    secondary: link(r.secondary),
    theme: pick(SLIDE_THEMES, r.theme, KIND_THEME[kind]),
    align: pick(HERO_ALIGNS, r.align, kind === 'search' ? 'center' : 'start'),
    media: m && mediaUrl ? { url: mediaUrl, alt: text(m.alt, 200) ?? '', focal: pick(MEDIA_FOCALS, m.focal, 'center') } : null,
    overlay: pick(HERO_OVERLAYS, r.overlay, 'soft'),
    showProductCard: bool(r.showProductCard, true),
    offer: o
      ? {
          badge: text(o.badge, 60),
          /* تاريخٌ لا يُقرأ ليس موعدًا — لا عدّاد مزيّف. */
          endsAt: endsAt && !Number.isNaN(Date.parse(endsAt)) ? endsAt : null,
          couponCode: text(o.couponCode, 40),
        }
      : null,
    search:
      kind === 'search'
        ? {
            placeholder: text(s?.placeholder, 120),
            chips: (Array.isArray(s?.chips) ? (s.chips as unknown[]) : [])
              .map(link)
              .filter((c): c is HeroLink => c !== null)
              .slice(0, CHIPS_MAX)
              /* ثلاث رقائق على الجوّال كما في القالب؛ والبقيّة للمكتب. */
              .map((c, i) => ({ ...c, mobile: i < 3 })),
          }
        : null,
  };
}

/** الشرائح المحرَّرة بترتيب المالك — بحدٍّ أقصى ٨، ومعرّفاتٌ فريدة (مفاتيح React). */
export function heroSlides(raw: unknown): HeroSlide[] {
  if (!Array.isArray(raw)) return [];
  const seen = new Set<string>();
  const out: HeroSlide[] = [];
  for (const [i, item] of raw.entries()) {
    if (out.length === SLIDES_MAX) break;
    const s = slide(item, i);
    if (!s) continue;
    if (seen.has(s.id)) s.id = `${s.id}_${i}`;
    seen.add(s.id);
    out.push(s);
  }
  return out;
}

/* ─── التراجع: متجرٌ لم يحفظ شرائح بعد ⇒ الشرائح الثلاث من الحقول القديمة ─── */

export interface LegacyHeroFields {
  title?: string;
  title_accent?: string;
  subtitle?: string;
  eyebrow?: string;
  cta_label?: string;
  cta_url?: string;
  cta_secondary_label?: string;
  cta_secondary_url?: string;
}

export interface LegacyPromo {
  campaign?: { title: string; description?: string; badge?: string; endsAt?: string; ends_at?: string; url: string };
  coupon?: { code: string; description?: string };
}

const base = (id: string, kind: SlideKind, title: string): HeroSlide => ({
  id,
  kind,
  eyebrow: null,
  title,
  titleAccent: null,
  subtitle: null,
  primary: null,
  secondary: null,
  theme: KIND_THEME[kind],
  align: kind === 'search' ? 'center' : 'start',
  media: null,
  overlay: 'soft',
  showProductCard: kind === 'brand',
  offer: null,
  search: null,
});

/**
 * الشرائح الثلاث كما صُيِّرت قبل العقد — حرفًا بحرف: العلامة من الحقول
 * القديمة (بدعوتين احتياطيّتين إلى مسارين حقيقيّين)، والعرض من `/home/promo`
 * (العدّاد للحملة، والقسيمة حين لا حملة)، والبحث بنصّه الثابت ورقائق مصطلحاتٍ منشورة.
 */
export function fallbackSlides(input: {
  hero: LegacyHeroFields | null;
  promo: LegacyPromo | null;
  chips: HeroChip[];
  routes: { shop: string; samples: string; offers: string };
}): HeroSlide[] {
  const { hero, promo, chips, routes } = input;
  const out: HeroSlide[] = [];

  if (hero && text(hero.title)) {
    out.push({
      ...base('legacy-brand', 'brand', hero.title!.trim()),
      eyebrow: text(hero.eyebrow),
      titleAccent: text(hero.title_accent),
      subtitle: text(hero.subtitle),
      primary:
        hero.cta_label && hero.cta_url
          ? { label: hero.cta_label, href: hero.cta_url }
          : { label: 'تصفّح الملفات', href: routes.shop },
      secondary:
        hero.cta_secondary_label && hero.cta_secondary_url
          ? { label: hero.cta_secondary_label, href: hero.cta_secondary_url }
          : { label: 'عيّنة مجانية', href: routes.samples },
    });
  }

  const campaign = promo?.campaign;
  const coupon = promo?.coupon;
  if (campaign || coupon) {
    out.push({
      ...base('legacy-offer', 'offer', campaign?.title ?? coupon?.description ?? 'قسيمة خصم'),
      subtitle: text(campaign?.description),
      primary: { label: campaign ? 'تصفّح العرض' : 'تصفّح العروض', href: campaign?.url ?? routes.offers },
      offer: {
        badge: campaign ? (campaign.badge ?? coupon?.description ?? null) : 'قسيمة خصم',
        endsAt: campaign ? (campaign.endsAt ?? campaign.ends_at ?? null) : null,
        /* مع الحملة يُعرض عدّادها وحده، كما كان. */
        couponCode: campaign ? null : (coupon?.code ?? null),
      },
    });
  }

  out.push({
    ...base('legacy-search', 'search', 'اكتب ما تحتاجه، ونعطيك الملف الجاهز.'),
    eyebrow: 'ملفات مرتّبة حسب المرحلة والمادة والفصل',
    search: { placeholder: 'مثال: تحضير علوم سادس ابتدائي الفصل الثاني', chips },
  });

  return out;
}
