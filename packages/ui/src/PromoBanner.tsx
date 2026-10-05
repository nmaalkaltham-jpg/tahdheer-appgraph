// UNIT: PROMOBANNER
// FILE: PromoBanner.tsx
// STYLE: PromoBanner.module.css
// PARENT: UI-INDEX
// USES: REGISTRY-INDEX
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * PromoBanner — «كتلة العرض» tah/promo.0601 (STUDIO-PHASE2-CONTRACT §2.5،
 * قرار C26): أول مستهلك حقيقي لضابط الصورة، بصورة يملكها المستند
 * (StudioImage) وبؤرة قص 0-100 تُبثّ ‎object-position‎ — بلا أعمدة قاعدة.
 *
 * قوانين مُلزِمة:
 *   · ‎<img>‎ فقط — لا SVG مضمّنًا أبدًا (قانون سلامة رفع SVG؛ خطر §7).
 *   · بلا صورة لا يُصيَّر للعامة شيء إطلاقًا؛ العنصر النائب الموسوم يظهر
 *     تحت المعاينة فقط (placeholder يمرّره مضيف الكانفس وحده — المسار
 *     المنشور لا يمرّره فلا يصل الزائرَ كودُ محرّر).
 *   · الرابط يمرّ بفحص بروتوكول (يقتل ‎javascript:‎ ونحوه) — تطبيع الخادم
 *     حدُّ الحقن للصور، وهذا حدّه للروابط الحرّة.
 *   · حركة الدخول عبر طبقة الحركة المشتركة (‎data-reveal/data-enter‎ في
 *     design-system/motion.css) — نفس keyframes المستودع خلف حارس كشف
 *     ‎prefers-reduced-motion‎ القائم، لا نسخة ثانية هنا.
 *
 * المكوّن خادمي صِرف — لا توجيهات ولا خطافات (شرط عمله على الوجهين).
 */
import type { PROMO_SCHEMA, StudioImage } from '@tahdheer/registry';
import styles from './PromoBanner.module.css';

export type PromoHeight = 'sm' | 'md' | 'lg';
export type PromoOverlay = 'none' | 'soft' | 'strong';
export type PromoAlign = 'start' | 'center';

/** خصائص الكتلة — مفاتيح PROMO_SCHEMA حرفيًّا (لا طبقة ترجمة). */
export interface PromoBannerSettings {
  image: StudioImage | null;
  heading: string;
  text: string;
  ctaLabel: string;
  ctaHref: string;
  height: PromoHeight;
  overlay: PromoOverlay;
  align: PromoAlign;
}

/*
 * فحص الانجراف وقت الترجمة (العقد §2.5 — استيراد نوعي، صفر أثر تشغيلي):
 * مفتاح في PROMO_SCHEMA بلا خاصية هنا يكسر البناء.
 */
type PromoSchemaKey = (typeof PROMO_SCHEMA)[number]['key'];
void (null as unknown as PromoSchemaKey satisfies keyof PromoBannerSettings);

/** القيم الافتراضية — قيم default في PROMO_SCHEMA نفسها. */
export const PROMO_DEFAULTS: PromoBannerSettings = {
  image: null,
  heading: '',
  text: '',
  ctaLabel: '',
  ctaHref: '',
  height: 'md',
  overlay: 'soft',
  align: 'start',
};

export interface PromoBannerProps {
  /** ‎node.props‎ كما وصلت (جزئية) — الدمج فوق PROMO_DEFAULTS يحدث هنا. */
  settings: Partial<PromoBannerSettings>;
  /**
   * المعاينة فقط: عند غياب الصورة يُصيَّر عنصر نائب موسوم بدل لا شيء —
   * المسار المنشور لا يمرّره أبدًا فيبقى الغياب صادقًا أمام الزائر.
   */
  placeholder?: boolean;
  /** يعيد تشغيل حركة الدخول عند تغيّره (يستعمله مضيف الكانفس). */
  replayKey?: number | string;
}

const HEIGHTS: readonly PromoHeight[] = ['sm', 'md', 'lg'];
const OVERLAYS: readonly PromoOverlay[] = ['none', 'soft', 'strong'];
const ALIGNS: readonly PromoAlign[] = ['start', 'center'];

const HEIGHT_CLASS: Record<PromoHeight, string> = {
  sm: styles.hSm ?? '',
  md: styles.hMd ?? '',
  lg: styles.hLg ?? '',
};

const OVERLAY_CLASS: Record<PromoOverlay, string | undefined> = {
  none: undefined,
  soft: styles.ovSoft,
  strong: styles.ovStrong,
};

const pickEnum = <T extends string>(v: unknown, allowed: readonly T[], fb: T): T =>
  typeof v === 'string' && (allowed as readonly string[]).includes(v) ? (v as T) : fb;

const str = (v: unknown): string => (typeof v === 'string' ? v : '');

/** بؤرة 0-100 — خارج المدى أو غير الرقمي يعود للمنتصف (50). */
const focal = (v: unknown): number =>
  typeof v === 'number' && Number.isFinite(v) ? Math.min(100, Math.max(0, Math.round(v))) : 50;

/**
 * رابط الإجراء: نسبي/مرساة كما هو؛ المطلق ببروتوكول مسموح فقط
 * (https/http/mailto/tel) — ‎javascript:‎ و‎data:‎ وأشباهها تُسقَط فيغيب
 * الزرّ بدل أن يكذب.
 */
function safeHref(raw: string): string | null {
  const href = raw.trim();
  if (href === '') return null;
  if (/^[a-z][a-z0-9+.-]*:/i.test(href)) {
    return /^(https?:|mailto:|tel:)/i.test(href) ? href : null;
  }
  return href;
}

/** صورة صالحة للتصيير: كائن برابط غير فارغ — ما دون ذلك «بلا صورة». */
function usableImage(image: Partial<PromoBannerSettings>['image']): StudioImage | null {
  if (typeof image !== 'object' || image === null) return null;
  const url = typeof image.url === 'string' ? image.url.trim() : '';
  if (url === '') return null;
  return image;
}

export function PromoBanner({ settings, placeholder = false, replayKey }: PromoBannerProps) {
  const s = { ...PROMO_DEFAULTS, ...settings };
  const height = pickEnum(s.height, HEIGHTS, PROMO_DEFAULTS.height);
  const overlay = pickEnum(s.overlay, OVERLAYS, PROMO_DEFAULTS.overlay);
  const align = pickEnum(s.align, ALIGNS, PROMO_DEFAULTS.align);
  const image = usableImage(s.image);

  /* بلا صورة: لا شيء للعامة — عنصر نائب موسوم تحت المعاينة فقط (§76). */
  if (image === null) {
    if (!placeholder) return null;
    return (
      <section className={[styles.promo, styles.empty, HEIGHT_CLASS[height]].filter(Boolean).join(' ')} role="note">
        <p className={styles.emptyLabel}>كتلة العرض بلا صورة — لن تُعرض للزوّار حتى تُختار صورة من المكتبة.</p>
      </section>
    );
  }

  const heading = str(s.heading).trim();
  const text = str(s.text).trim();
  const ctaLabel = str(s.ctaLabel).trim();
  const href = safeHref(str(s.ctaHref));
  const alt = typeof image.alt === 'string' ? image.alt : '';
  const w = typeof image.w === 'number' && Number.isFinite(image.w) && image.w > 0 ? Math.round(image.w) : undefined;
  const h = typeof image.h === 'number' && Number.isFinite(image.h) && image.h > 0 ? Math.round(image.h) : undefined;

  const classes = [
    styles.promo,
    HEIGHT_CLASS[height],
    OVERLAY_CLASS[overlay],
    align === 'center' ? styles.alignCenter : undefined,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <section
      className={classes}
      style={{ ['--pb-focal' as string]: `${focal(image.focalX)}% ${focal(image.focalY)}%` }}
      data-reveal="in"
      data-enter="up"
      data-theme-component="promo"
      key={replayKey}
    >
      {/* ‎<img>‎ حصرًا — البؤرة object-position والأبعاد تمنع قفزة التخطيط. */}
      <img className={styles.img} src={image.url} alt={alt} width={w} height={h} decoding="async" />
      {overlay !== 'none' ? <span className={styles.scrim} aria-hidden="true" /> : null}
      <div className={styles.content}>
        {heading !== '' ? <h2 className={styles.heading}>{heading}</h2> : null}
        {text !== '' ? <p className={styles.text}>{text}</p> : null}
        {ctaLabel !== '' && href !== null ? (
          <a className={styles.cta} href={href}>
            {ctaLabel}
          </a>
        ) : null}
      </div>
    </section>
  );
}
