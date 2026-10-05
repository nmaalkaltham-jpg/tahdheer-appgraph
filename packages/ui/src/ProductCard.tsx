// UNIT: PRODUCTCARD
// FILE: ProductCard.tsx
// STYLE: ProductCard.module.css
// PARENT: PRODUCTCOLLECTION, UI-INDEX
// USES: FORMAT, PRODUCTCARD-MEDIA, PRODUCTCARD-PRICE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * ProductCard — بطاقة المنتج الواحدة في المتجر كلّه (الرئيسة، الكتالوج، البحث،
 * العروض، الأكثر مبيعًا، المفضّلة، «يُشترى معه»، «شاهدتَ مؤخّرًا»).
 * مكوّن خادمٍ محض إلّا الغلاف (`ProductCardMedia`: حالات الصورة وحدها).
 *
 * التسلسل — تسلسلُ قرار الشراء لا تسلسلُ القالب:
 *   الغلاف (ما هو؟) ← العنوان ← المادّة والصفّ والنوع والصيغة (هل لصفّي؟ ماذا
 *   أحصل؟) ← التقييم والتسليم الفوريّ (هل أثق؟ هل أحصل عليه الآن؟) ← السعر
 *   والقديم والوفر ← «أضف للسلة» و«معاينة».
 *
 * عقيدة الصدق:
 * - كلّ شارةٍ من بياناتٍ حقيقيّة: الخصم من `discountPct`/السعرين، و«الأكثر
 *   مبيعًا» من `bestSeller` (الخادم يحسبه من طلباتٍ مدفوعة)، و«جديد» من تاريخ
 *   النشر، و«يتضمن نموذجًا» حين يقولها المستدعي عن بياناتٍ يملكها. شارتان
 *   على الأكثر بأولويّةٍ ثابتة.
 * - صفّ التقييم لا يُرسم إلّا بعددٍ حقيقيّ أكبر من صفر — لا صفر ولا اختلاق.
 * - السعر القديم والوفر من سعرَين حقيقيّين (`cardPrice`، مختبَرة)؛ والنسبة
 *   تُشتقّ حسابيًّا حين لا يرسلها الخادم. ولا فراغَ محجوز لخصمٍ لا وجود له.
 * - «تحميل فوري بعد الدفع» حين يؤكّد التنفيذ أنّ المنتج رقميّ (`instantDownload`).
 * - بلا صورة يُرسم بديلُ المستدعي (رسمُ المادّة) أو ورقةُ القالب — لا صورةٌ مدّعاة.
 * - زرّ السلة وزرّ المعاينة والقلب أفعالٌ يملكها المستدعي (`ctaSlot` /
 *   `previewSlot` / `actions`): هذا الملفّ لا يرسم زرًّا بلا فعلٍ وراءه.
 */
import type { ReactNode } from 'react';
import { formatMoney, formatNumber } from '@tahdheer/design-system/format';
import { ProductCardMedia } from './ProductCardMedia';
import { cardPrice } from './productCardPrice';
import styles from './ProductCard.module.css';

export interface ProductCardImage {
  url: string;
  alt: string;
  w: number;
  h: number;
}

export interface ProductCardBadge {
  label: string;
  bg: string;
  fg: string;
}

/** Footer call-to-action. Presentational only: a label and a real destination. */
export interface ProductCardCta {
  label: string;
  href: string;
}

/**
 * صنفُ زرّ «أضف للسلة».
 *
 * الإضافة إلى السلة POST لا تنقّل، فالزرّ الحقيقيّ لا يسكن هذا الملفّ الخادميّ؛
 * يمرّره المستدعي في `ctaSlot` ويلبس هذا الصنف، فيبقى تعريف الزرّ واحدًا.
 */
export const productCardCtaClass: string = styles.cta ?? '';

/** صنفُ زرّ «معاينة» — الزرّ الثانويّ بجوار السلة؛ يلبسه زرّ المعاينة الحقيقيّ في `previewSlot`. */
export const productCardPreviewClass: string = styles.preview ?? '';

/**
 * أطقمُ البطاقة — تنفيذٌ واحد وتسلسلٌ واحد، والطقم يغيّر المقاسات وحدها:
 * - `catalog` (الافتراض) و`featured`: بطاقة الشبكة كاملةً.
 * - `related`: «يُشترى معه» في صفحة المنتج — رفٌّ ‎188px‎ على الجوّال.
 * - `compact`: الرفّ الأفقيّ (شاهدتَ مؤخّرًا) — ‎188px‎ عرضًا دائمًا.
 */
export type ProductCardVariant = 'catalog' | 'featured' | 'related' | 'compact';

/** صبغةُ ورقة البديل (بلا صورة) — رموز ‎--tint-*‎ فقط. */
export type ProductCardTint = 'teal' | 'navy' | 'sand' | 'lilac';

export interface ProductCardProps {
  id: number | string;
  sku: string;
  title: string;
  slug: string;
  url: string;
  /** مسار التصنيف كما يرسله الخادم: [المرحلة/الصفّ، المادّة]. الأضيق آخرًا. */
  categoryPath: string[];
  /** صيغة الملفّ الواحدة كما يرسلها الخادم («PDF» / «DOCX»)؛ `formats` تعلوها حين تُمرَّر. */
  format: string;
  /** صيغ الملفّ بأسمائها المعروضة («PDF»، «Word») — تُعرض «PDF + Word». */
  formats?: readonly string[];
  /** نوع الملفّ («تحضير»، «أوراق عمل»…) من `resourceType`. */
  resourceType?: string;
  /** الصفّ الدراسيّ حين يعرفه الخادم؛ يحلّ محلّ المرحلة (أوّل `categoryPath`) في سطر البيانات. */
  grade?: string;
  /** الفصل الدراسيّ حين يعرفه المستدعي؛ لا يُشتقّ من العنوان. */
  semester?: string;
  priceMinor: number;
  regularMinor?: number;
  discountPct?: number;
  onSale: boolean;
  ratingAvg?: number;
  ratingCount?: number;
  image?: ProductCardImage;
  badge?: ProductCardBadge;
  rank?: number;
  editable?: boolean;
  /** «الأكثر مبيعًا» — من ترتيبٍ حقيقيّ بطلباتٍ مدفوعة فقط. */
  bestSeller?: boolean;
  /** «جديد» — يقرّره المستدعي من تاريخ النشر الحقيقيّ. */
  isNew?: boolean;
  /** «يتضمن نموذجًا» — حين تؤكّد بيانات المستدعي وجود عيّنة. */
  hasSample?: boolean;
  /** «تحميل فوري بعد الدفع» — حين يؤكّد التنفيذ أنّ المنتج رقميّ. */
  instantDownload?: boolean;
  /** لا عرضَ فعّال: يُخفى السعر ويُعطَّل الزرّ بدل «٠٫٠٠». */
  unavailable?: boolean;
  /** Footer CTA («أضف للسلة»); omitted entirely when the caller has no destination. */
  cta?: ProductCardCta;
  /** زرّ السلة الحقيقيّ (POST). يعلو `cta`. يلبس `productCardCtaClass`. */
  ctaSlot?: ReactNode;
  /** زرّ «معاينة» الحقيقيّ (يفتح المعاينة السريعة). يلبس `productCardPreviewClass`. */
  previewSlot?: ReactNode;
  /** أزرار الوسائط (المفضّلة) — عقدةٌ يملكها المستدعي في الزاوية المقابلة للشارات. */
  actions?: ReactNode;
  /** بديلُ الوسائط حين لا صورة أو حين تنكسر (يحلّ محلّ ورقة القالب المرسومة هنا). */
  mediaSlot?: ReactNode;
  /** الطقم — انظر `ProductCardVariant`. الافتراض `catalog`. */
  variant?: ProductCardVariant;
  /** صبغة ورقة البديل (المرتبطة تدوّرها بالموضع؛ الافتراض تدرّج كحليّ→تركوازيّ). */
  tint?: ProductCardTint;
  /** سطرٌ يملكه المستدعي («N عملية شراء») — لا يُختلق هنا. */
  metaSlot?: ReactNode;
  /** نصّ زرّ التذييل حين يُمرَّر `cta` رابطًا — يعلو `cta.label`. */
  ctaLabel?: string;
  /** فوق الطيّة: الغلاف يُجلب فورًا بأولويّةٍ عالية. */
  priority?: boolean;
}

/** أقصى عدد شارات فوق الغلاف — أكثر منها ضجيجٌ يزاحم المنتج. */
const MAX_BADGES = 2;

/* ── الأيقونات — خطّيّة ‎24‎، بلون النصّ، مخفيّةٌ عن قارئ الشاشة (النصّ بجوارها يقول كلّ شيء). */
const STROKE = { fill: 'none', stroke: 'currentColor', strokeWidth: 1.9, strokeLinecap: 'round', strokeLinejoin: 'round' } as const;

function BookIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" {...STROKE}>
      <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H10a2 2 0 0 1 2 2v14a1.5 1.5 0 0 0-1.5-1.5H4z" />
      <path d="M20 5.5A1.5 1.5 0 0 0 18.5 4H14a2 2 0 0 0-2 2v14a1.5 1.5 0 0 1 1.5-1.5H20z" />
    </svg>
  );
}

function GradeIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" {...STROKE}>
      <path d="M2.5 9.5 12 5l9.5 4.5L12 14z" />
      <path d="M6.5 11.5v4.2c0 1.3 2.5 2.8 5.5 2.8s5.5-1.5 5.5-2.8v-4.2M21.5 9.5v5" />
    </svg>
  );
}

function CalendarIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" {...STROKE}>
      <rect x="3.5" y="5" width="17" height="15" rx="2" />
      <path d="M3.5 10h17M8 3v4M16 3v4" />
    </svg>
  );
}

function FileIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" {...STROKE}>
      <path d="M6.5 3.5h7l5 5v11a1.5 1.5 0 0 1-1.5 1.5h-10.5A1.5 1.5 0 0 1 5 19.5v-14.5a1.5 1.5 0 0 1 1.5-1.5z" />
      <path d="M13.5 3.5v5h5" />
    </svg>
  );
}

function FormatIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" {...STROKE}>
      <path d="M6.5 3.5h7l5 5v11a1.5 1.5 0 0 1-1.5 1.5h-10.5A1.5 1.5 0 0 1 5 19.5v-14.5a1.5 1.5 0 0 1 1.5-1.5z" />
      <path d="M8.5 13h7M8.5 16.5h5" />
    </svg>
  );
}

function PencilIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" {...STROKE}>
      <path d="M4 20l4.2-1 10.3-10.3a2 2 0 0 0 0-2.8l-.4-.4a2 2 0 0 0-2.8 0L5 15.8z" />
      <path d="M13.5 7.5l3 3" />
    </svg>
  );
}

function DownloadIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" {...STROKE}>
      <path d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14" />
    </svg>
  );
}

function StarIcon() {
  return (
    <svg className={styles.star} aria-hidden="true" viewBox="0 0 24 24" fill="currentColor">
      <path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.5 1.2 6.4L12 17.3l-5.7 3.1 1.2-6.4L2.8 9.5l6.4-.8z" />
    </svg>
  );
}

/** أيقونة السلة لزرّ «أضف للسلة» — يرسمها المستدعي داخل زرّه فيبقى الزرّ واحد الشكل في كلّ مكان. */
export const productCardCartIcon: ReactNode = (
  <svg aria-hidden="true" viewBox="0 0 24 24" {...STROKE} width="17" height="17">
    <path d="M3 4h2l2.4 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L21 8H6" />
    <circle cx="9.5" cy="20" r="1.4" />
    <circle cx="17" cy="20" r="1.4" />
  </svg>
);

/** أيقونة العين لزرّ «معاينة» — الشكل نفسه في كلّ مستدعٍ. */
export const productCardEyeIcon: ReactNode = (
  <svg aria-hidden="true" viewBox="0 0 24 24" {...STROKE} width="17" height="17">
    <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
    <circle cx="12" cy="12" r="3" />
  </svg>
);

export function ProductCard(props: ProductCardProps) {
  const {
    title,
    url,
    categoryPath,
    format,
    formats,
    resourceType,
    grade,
    semester,
    priceMinor,
    regularMinor,
    discountPct,
    onSale,
    ratingAvg,
    ratingCount,
    image,
    badge,
    rank,
    editable,
    bestSeller,
    isNew,
    hasSample,
    instantDownload,
    unavailable = false,
    cta,
    ctaSlot,
    previewSlot,
    actions,
    mediaSlot,
    variant = 'catalog',
    tint,
    metaSlot,
    ctaLabel,
    priority,
  } = props;

  /* ── السعر: القديم والوفر من سعرَين حقيقيّين وحدهما. */
  const price = formatMoney(priceMinor);
  const view = cardPrice({ priceMinor, regularMinor, discountPct, onSale, unavailable });
  const was = view.wasMinor !== null ? formatMoney(view.wasMinor) : undefined;
  const savings = view.savingsMinor !== null ? formatMoney(view.savingsMinor) : undefined;
  const pct = view.pct;
  const showRating = ratingCount !== undefined && ratingCount > 0 && ratingAvg !== undefined;

  /* ── سطر البيانات: المادّة ← الصفّ (أو المرحلة حين لا صفّ) ← الفصل ← نوع الملفّ ← الصيغة ← قابل للتعديل. */
  const subject = categoryPath.length > 0 ? categoryPath[categoryPath.length - 1] : undefined;
  const level = grade ?? (categoryPath.length > 1 ? categoryPath[0] : undefined);
  const formatList = (formats ?? (format ? [format] : [])).filter((f) => f.trim() !== '');
  const formatText = formatList.join(' + ');
  const meta: Array<{ key: string; icon: ReactNode; text: string; className?: string }> = [];
  if (subject) meta.push({ key: 'subject', icon: <BookIcon />, text: subject });
  if (level) meta.push({ key: 'level', icon: <GradeIcon />, text: level });
  if (semester) meta.push({ key: 'semester', icon: <CalendarIcon />, text: semester });
  if (resourceType) meta.push({ key: 'type', icon: <FileIcon />, text: resourceType });
  if (formatText) meta.push({ key: 'format', icon: <FormatIcon />, text: formatText, className: styles.metaFormat });
  if (editable) meta.push({ key: 'editable', icon: <PencilIcon />, text: 'قابل للتعديل' });

  /*
   * الشارات: شارتان على الأكثر بأولويّةٍ ثابتة — الخصم ← الأكثر مبيعًا (أو
   * الترتيب رقمًا، فهما قولٌ واحد) ← جديد ← يتضمن نموذجًا ← شارة المستدعي.
   */
  const badges: ReactNode[] = [];
  if (pct > 0) {
    badges.push(
      <span key="discount" className={`${styles.pill} ${styles.pillDiscount}`}>
        خصم <bdi dir="ltr">{formatNumber(pct)}٪</bdi>
      </span>,
    );
  }
  if (bestSeller) {
    badges.push(
      <span key="best" className={`${styles.pill} ${styles.pillBest}`}>
        الأكثر مبيعًا
      </span>,
    );
  } else if (rank !== undefined) {
    badges.push(
      <span key="rank" className={`${styles.pill} ${styles.pillBest}`}>
        <bdi dir="ltr">#{formatNumber(rank)}</bdi>
      </span>,
    );
  }
  if (isNew) {
    badges.push(
      <span key="new" className={`${styles.pill} ${styles.pillNew}`}>
        جديد
      </span>,
    );
  }
  if (hasSample) {
    badges.push(
      <span key="sample" className={`${styles.pill} ${styles.pillSample}`}>
        يتضمن نموذجًا
      </span>,
    );
  }
  if (badge) {
    badges.push(
      <span key="badge" className={styles.pill} style={{ background: badge.bg, color: badge.fg }}>
        {badge.label}
      </span>,
    );
  }

  /* بديل الغلاف — رسمُ المستدعي أو ورقة القالب. يُستعمل بلا صورة وعند انكسارها. */
  const fallback = mediaSlot ?? (
    <span className={styles.paper} aria-hidden="true">
      <span />
      <span />
      <span />
      <span />
    </span>
  );

  const ctaNode =
    ctaSlot ??
    (cta && !unavailable ? (
      <a className={styles.cta} href={cta.href}>
        {productCardCartIcon}
        {ctaLabel ?? cta.label}
        <span className={styles.srOnly}> «{title}»</span>
      </a>
    ) : null);

  return (
    <article
      className={styles.card}
      data-sku={props.sku}
      data-theme-component="card"
      data-variant={variant}
      data-unavailable={unavailable ? '' : undefined}
    >
      <div className={styles.media} data-cover={image ? '' : undefined} data-tint={tint}>
        {image ? (
          <ProductCardMedia
            src={image.url}
            alt={image.alt}
            width={image.w}
            height={image.h}
            priority={priority}
            frameClass={styles.frame ?? ''}
            imgClass={styles.img ?? ''}
            fallback={fallback}
          />
        ) : (
          <span className={styles.frame} data-state="none">
            {fallback}
          </span>
        )}

        {badges.length > 0 ? <div className={styles.badges}>{badges.slice(0, MAX_BADGES)}</div> : null}
        {actions ? <div className={styles.actions}>{actions}</div> : null}
      </div>

      <div className={styles.body}>
        {/* رابط العنوان ممدودٌ على البطاقة كلّها (‎.link::after‎)؛ الأزرار تعلوه بـz-index. */}
        <h3 className={styles.title}>
          <a href={url} className={styles.link}>
            {title}
          </a>
        </h3>

        {meta.length > 0 ? (
          <ul className={styles.meta} role="list">
            {meta.map((item) => (
              <li key={item.key} className={item.className ? `${styles.metaItem} ${item.className}` : styles.metaItem} data-key={item.key}>
                <span className={styles.metaIcon}>{item.icon}</span>
                <span className={styles.metaText}>{item.text}</span>
              </li>
            ))}
          </ul>
        ) : null}

        {showRating || instantDownload ? (
          <div className={styles.signals}>
            {showRating ? (
              <p className={styles.rating}>
                <StarIcon />
                <span className={styles.srOnly}>التقييم </span>
                <span className={styles.ratingText}>
                  <bdi dir="ltr">{formatNumber(Math.round(ratingAvg * 10) / 10)}</bdi>
                  <span className={styles.srOnly}> من 5، </span> (<bdi dir="ltr">{formatNumber(ratingCount)}</bdi>
                  <span className={styles.srOnly}> تقييم</span>)
                </span>
              </p>
            ) : null}
            {instantDownload ? (
              <span className={styles.delivery}>
                <span className={styles.deliveryIcon}>
                  <DownloadIcon />
                </span>
                تحميل فوري بعد الدفع
              </span>
            ) : null}
          </div>
        ) : null}

        {metaSlot ? <p className={styles.metaSlot}>{metaSlot}</p> : null}

        <p className={styles.priceBlock}>
          {unavailable ? (
            <span className={styles.unavailable}>غير متاح حاليًا</span>
          ) : (
            <>
              <span className={styles.priceNow}>
                <span className={styles.price}>
                  <bdi dir="ltr">{price.amount}</bdi>
                </span>
                <span className={styles.currency}>{price.currency}</span>
              </span>
              {was ? (
                <del className={styles.was}>
                  <span className={styles.srOnly}>السعر قبل الخصم </span>
                  <bdi dir="ltr">{was.amount}</bdi> {was.currency}
                </del>
              ) : null}
              {savings ? (
                <span className={styles.save}>
                  وفّر <bdi dir="ltr">{savings.amount}</bdi> {savings.currency}
                </span>
              ) : null}
            </>
          )}
        </p>

        {ctaNode || previewSlot ? (
          <div className={styles.ctaRow} data-cols={ctaNode && previewSlot ? '2' : '1'}>
            {ctaNode}
            {previewSlot}
          </div>
        ) : null}
      </div>
    </article>
  );
}
