/**
 * أقسام الرئيسة — «Storefront Home.dc.html» (مكتب، ‎1440‎) و«Storefront Home
 * Mobile.dc.html» (‎390‎) في ترميزٍ واحد تفصله نقطة انكسار ‎767px‎.
 *
 * البنية والمقاسات والألوان من القالب حرفيًّا (design.module.css)؛ والبيانات
 * كلّها من الواجهة البرمجية. عقيدة الصدق:
 *   · قيم القالب عيّناتُ تخطيط لا بيانات: «١٨ ألف معلّم»، «٤٨٠٠ ملف»،
 *     «٣١٠ ملفات»، «٤.٩ (٢١٤)»، «٣٬٢٤٠ تقييمًا»، العدّاد «٠٢·١٤·٣٦»،
 *     «٢٢٠ ← ١٦٥ ر.س»، الاقتباسات وأسماؤها — لا يُنسخ منها شيء. كلّ رقمٍ
 *     هنا حقلٌ من الواجهة أو مشتقٌّ منه، وما لا مصدر له يُحذف عنصرُه.
 *   · ٢٠٠ + لا شيء منشور ⇒ القسم لا يُصيَّر. و`status != 200` ⇒ `<Failed />`.
 *   · النصّ الثابت الوحيد: عناوين الأقسام، وتسميات التنقّل إلى مساراتٍ حقيقيّة،
 *     وادّعاءات شريط الثقة الملتزَم بها (كلٌّ موثّق عند تعريفه).
 */
import type { CSSProperties, ReactNode } from 'react';
import Image from 'next/image';
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { formatNumber, formatMoney, qty, type PluralForms } from '@tahdheer/design-system/format';
import { ProductCard } from '@tahdheer/ui';
import { apiGet, apiGetCollection, toCardProps } from '../../lib/api';
import { fetchSearch, searchCardToProps } from '../../lib/search-api';
import { routes } from '../../lib/routes';
import { mediaSrc } from '../../lib/media-url';
import { gradeInStage, quoteInitials, stageKeyOf, type HomeCtx, type HomepageSectionId } from '../../lib/homepage';
import { termPath } from '../../app/(catalog)/_components/dimensions';
/* صيغةُ جمع المنتجات — مصدرها الوحيد. */
import { PRODUCT_FORMS } from '../../app/(catalog)/_components/query';
import { DesignIcon, type DesignIconName } from './designIcons';
import { quickFilters } from '../../lib/home-all-products';
import { AllProductsMore } from './AllProducts';
import { Head } from './DesignHead';
import { Failed, failed } from './Failed';
import { HeroSlider } from './HeroSlider';
import { CardAddToCart } from '../product/CardAddToCart';
import { cardFallbackArt } from '../product/CardArt';
import { cardPreview, cardWishlist } from '../../app/(catalog)/_components/ProductGrid';
import { CopyCode, Countdown } from './OfferBits';
import {
  fallbackSlides,
  heroPresentation,
  heroSlider,
  heroSlides,
  THEME_TONE,
  type HeroChip,
  type HeroSlide,
  type LegacyPromo,
} from './hero-config';
import { LegacyHeroSection } from './legacyHero';
import { ThemedArt } from './ThemedArt';
import { HERO_ART, STAGE_ART } from '../../lib/art';
import { ArtTile, stageTone } from './ArtTile';
import styles from './design.module.css';

/* ------------------------------------------------------------------ DTOs */

interface HeroDto {
  title: string;
  /** الكلمة الذهبيّة في آخر العنوان (‎«أفضل.»‎ في القالب). */
  title_accent?: string;
  subtitle?: string;
  /** شارة الشريحة الأولى — ادّعاء المتجر نفسه، لا يُخترع هنا. */
  eyebrow?: string;
  cta_label?: string;
  cta_url?: string;
  cta_secondary_label?: string;
  cta_secondary_url?: string;
  /** Owner-selected hero design (admin «تصميم الهيرو»); read via hero-config. */
  variant?: string;
  /** عقد الهيرو ٢٠٢٦-٠٩-٢٥ — يُطبَّعان في `hero-config.ts` (غير الموثوق يقع على الافتراض). */
  slider?: unknown;
  slides?: unknown;
}

interface CtaDto {
  title: string;
  subtitle?: string;
  cta_label?: string;
  cta_url?: string;
  /** الزرّ الثاني كما نشرته الإدارة — كائنٌ كامل أو `null`. */
  secondary?: { label: string; href: string } | null;
}

/** `badge` سطر الشارة فوق العنوان؛ `endsAt` (أو `ends_at`) موعد الانتهاء الحقيقيّ. */
type PromoDto = LegacyPromo;

interface TermDto {
  slug: string;
  name: string;
  productsCount?: number;
  subtitle?: string;
}

interface SamplesDto {
  items: {
    product: { id: number; title: string; format?: string };
    resourceType?: string | null;
    previews: { url: string; name: string; mime?: string }[];
  }[];
  resourceTypes: string[];
  count: number;
}

interface ReviewsDto {
  rating_avg: number;
  rating_count: number;
  all_reviews_url?: string;
  quotes: {
    id: number;
    text: string;
    author: string;
    product_slug: string;
    product_title: string;
    author_role?: string;
    author_city?: string;
    authorRole?: string;
    authorCity?: string;
    /** مراجعةٌ مربوطةٌ بطلبٍ مدفوع (`order_id`) — من الخادم، لا يُفترض. */
    verified?: boolean;
  }[];
}

/** مصدرٌ واحد لقائمة الأكثر مبيعًا — الهيرو يقرأ أوّلها، ونداءٌ مطابق
 *  يُدمج في تصييرٍ واحد (ذاكرة طلبات `fetch` في Next). */
const BEST_PATH = '/api/v1/products?source=best&limit=4';

const REVIEW_FORMS: PluralForms = {
  zero: 'لا تقييمات',
  one: 'تقييم واحد',
  two: 'تقييمين',
  few: 'تقييمات',
  many: 'تقييمًا',
};

/* ------------------------------------------------------------ shell parts */

/**
 * غلاف القسم: `data-theme-section` نطاقُ تجاوزات الطباعة المحرَّرة في
 * الاستوديو (`/theme.css`) — يبقى كما كان على كلّ قسم. والقسم الفارغ ينهار.
 */
export function DesignSection({ id, children }: { id: HomepageSectionId; children: ReactNode }) {
  return (
    <section id={id} data-theme-section={id} className={styles.section}>
      {children}
    </section>
  );
}

function Fault() {
  return (
    <div className={styles.wrap}>
      <Failed />
    </div>
  );
}

function Price({ dto, className }: { dto: ProductCardDto; className?: string }) {
  const now = formatMoney(dto.priceMinor);
  const was =
    dto.onSale && typeof dto.regularMinor === 'number' && dto.regularMinor > dto.priceMinor
      ? formatMoney(dto.regularMinor)
      : null;
  /* «45.00 SAR» يُقرأ من اليسار كما يطبعه القالب؛ والسعر القديم خارج
     المجموعة فيقع على يسارها في RTL (مكتب) أو تحتها (جوّال). */
  return (
    <span className={`${styles.priceBox} ${className ?? ''}`}>
      <b className={styles.price} dir="ltr">
        {now.amount} <span className={styles.priceCur}>{now.currency}</span>
      </b>
      {was ? (
        <s className={styles.priceWas}>
          <span className={styles.srOnly}>بدل </span>
          <bdi dir="ltr">{was.amount}</bdi>
        </s>
      ) : null}
    </span>
  );
}

/* ------------------------------------------------------------------ hero */

/** عنوان الشريحة: الكلمة الذهبيّة (`titleAccent`) في آخره، والشريحة الأولى وحدها `h1`. */
function SlideHeading({ slide, h1, className }: { slide: HeroSlide; h1: boolean; className?: string }) {
  const Heading = h1 ? 'h1' : 'h2';
  return (
    <Heading className={className}>
      {slide.title}
      {slide.titleAccent ? (
        <>
          {' '}
          <span className={styles.brandAccent}>{slide.titleAccent}</span>
        </>
      ) : null}
    </Heading>
  );
}

/**
 * الدعوتان بوصفة الزرّ: على الأسطح الداكنة أساسيٌّ ذهبيّ وثانويٌّ محيطيّ
 * أبيض، وعلى الفاتحة أساسيٌّ كحليّ وثانويٌّ محيطيّ كحليّ. تسلسلٌ واحد: دعوةٌ
 * ممتلئة واحدة لكلّ شريحة.
 */
function SlideCtas({ slide, tone, className }: { slide: HeroSlide; tone: 'dark' | 'light'; className?: string }) {
  if (!slide.primary && !slide.secondary) return null;
  return (
    <div className={className}>
      {slide.primary ? (
        <a className={tone === 'dark' ? styles.btnGold : styles.btnNavy} href={slide.primary.href}>
          {slide.primary.label}
        </a>
      ) : null}
      {slide.secondary ? (
        <a className={tone === 'dark' ? styles.btnGhost : styles.btnOutlineInk} href={slide.secondary.href}>
          {slide.secondary.label}
        </a>
      ) : null}
    </div>
  );
}

/**
 * بطاقة المنتج — أوّل الأكثر مبيعًا بصيغته وسعره، و«تحميل فوري» لأنّه رقميّ.
 * الخطوط داخلها رسمُ ورقةٍ زخرفيّ من القالب لا محتوى.
 */
function HeroProductCard({ product }: { product: ProductCardDto }) {
  return (
    <a className={styles.brandCard} href={product.url}>
      <span className={styles.brandCardTop}>
        <b className={styles.brandCardTitle}>{product.title}</b>
        {product.format ? <span className={styles.fmtBadge}>{product.format}</span> : null}
      </span>
      <span className={styles.paperLines} aria-hidden="true">
        <span />
        <span />
        <span />
        <span />
      </span>
      <span className={styles.brandCardFoot}>
        <Price dto={product} className={styles.brandCardPrice} />
        {product.digital ? <span className={styles.brandCardNote}>تحميل فوري</span> : null}
      </span>
    </a>
  );
}

/**
 * العلامة: شارة، عنوانٌ بلمسة، وصف، دعوتان — وفي الطرف الآخر مسرحُ الرسم:
 * رفوف المختبر على لوحٍ هادئ (تركيبٌ لا حشو)، والكرة خلفه، وبطاقة الأكثر
 * مبيعًا الحيّة تتقدّمهما. المسرح صندوقٌ ثابت المقاس فلا إزاحة؛ والجوّال يضع
 * الرسم خلف النصّ خافتًا (النصّ أوّلًا) ويُسقط البطاقة والكرة.
 * `priority` لرسم الشريحة الأولى وحدها. وشريحةٌ بصورةٍ من المالك لا رسم لها.
 */
function BrandSlide({ slide, tone, product, h1, first }: SlideProps) {
  const card = slide.showProductCard && product && slide.align === 'start' ? <HeroProductCard product={product} /> : null;
  const art = slide.align === 'start' && slide.media === null;
  return (
    <>
      <div className={styles.slideCopy}>
        {slide.eyebrow ? <p className={styles.brandBadge}>{slide.eyebrow}</p> : null}
        <SlideHeading slide={slide} h1={h1} className={styles.brandTitle} />
        {slide.subtitle ? <p className={styles.brandLede}>{slide.subtitle}</p> : null}
        <SlideCtas slide={slide} tone={tone} className={styles.brandCtas} />
      </div>
      {art ? (
        <div className={styles.brandStage} data-card={card ? 'true' : undefined}>
          <span className={styles.brandArt} aria-hidden="true">
            <span className={styles.brandGlobe}>
              <ThemedArt art={HERO_ART.globe} alt="" sizes="148px" />
            </span>
            <span className={styles.brandPlate}>
              <ThemedArt
                art={HERO_ART.labScene}
                alt=""
                surface={tone === 'dark' ? 'dark' : 'page'}
                priority={first}
                sizes="(max-width: 767px) 132px, (max-width: 1099px) 232px, 308px"
              />
            </span>
          </span>
          {card}
        </div>
      ) : (
        card
      )}
    </>
  );
}

/**
 * العرض: الشارة، والعدّاد إلى `endsAt` الحقيقيّ وحده (لا عدّاد مزيّف)، ورمز
 * القسيمة بنسخٍ حقيقيّ. أوراق القالب الثلاث تُسمّى بأنواع الملفّات التي لها
 * عيّنات منشورة فعلًا، أو تبقى خطوطًا.
 */
function OfferSlide({ slide, tone, kinds, h1 }: SlideProps) {
  const papers = [
    { cls: styles.paperA, color: styles.paperTeal, lines: [100, 80, 62] },
    { cls: styles.paperB, color: styles.paperGold, lines: [100, 74, 88, 56] },
    { cls: styles.paperC, color: styles.paperNavy, lines: [100, 66, 84] },
  ];
  const offer = slide.offer;
  return (
    <>
      <div className={styles.slideCopy}>
        {offer?.badge ? <p className={styles.offerBadge}>{offer.badge}</p> : null}
        {slide.eyebrow ? <p className={styles.offerEyebrow}>{slide.eyebrow}</p> : null}
        <SlideHeading slide={slide} h1={h1} className={styles.offerTitle} />
        {slide.subtitle ? <p className={styles.offerLede}>{slide.subtitle}</p> : null}
        {offer?.endsAt || offer?.couponCode ? (
          /* العدّاد والقسيمة صفٌّ واحد — لا عمودٌ يطيل الشريحة فيرفع المسرح كلّه. */
          <div className={styles.offerMeta}>
            {offer.endsAt ? <Countdown endsAt={offer.endsAt} /> : null}
            {offer.couponCode ? <CopyCode code={offer.couponCode} /> : null}
          </div>
        ) : null}
        <SlideCtas slide={slide} tone={tone} className={styles.offerCtas} />
      </div>
      {slide.align === 'start' ? (
        <div className={styles.papers} aria-hidden="true">
          {papers.map((paper, i) => (
            <span key={i} className={`${styles.paper} ${paper.cls}`}>
              {kinds[i] ? <span className={`${styles.paperKind} ${paper.color}`}>{kinds[i]}</span> : null}
              {paper.lines.map((w, j) => (
                <span key={j} className={styles.paperLine} style={{ inlineSize: `${w}%` }} />
              ))}
            </span>
          ))}
        </div>
      ) : null}
    </>
  );
}

/**
 * البحث: نموذج GET حقيقيّ إلى `/search` بوصفة الحقل (هالة التركيز على
 * الصدفة كلّها) ورقائق إلى مصطلحاتٍ منشورة.
 */
function SearchSlide({ slide, h1 }: SlideProps) {
  const chips = slide.search?.chips ?? [];
  return (
    <>
      {slide.eyebrow ? <p className={styles.searchEyebrow}>{slide.eyebrow}</p> : null}
      <SlideHeading slide={slide} h1={h1} className={styles.searchTitle} />
      {slide.subtitle ? <p className={styles.searchLede}>{slide.subtitle}</p> : null}
      <form className={styles.searchForm} action={routes.search} role="search">
        <input
          className={styles.searchInput}
          type="search"
          name="q"
          aria-label="ابحث في المتجر"
          placeholder={slide.search?.placeholder ?? 'ابحث عن ملف، مادة أو صف'}
        />
        <button className={styles.searchBtn} type="submit">
          ابحث
        </button>
      </form>
      {chips.length > 0 ? (
        <div className={styles.searchChips}>
          {chips.map((chip) => (
            <a
              key={chip.href}
              className={`${styles.searchChip} ${chip.strong ? styles.searchChipDark : ''} ${chip.mobile ? '' : styles.deskOnly}`}
              href={chip.href}
            >
              {chip.label}
            </a>
          ))}
        </div>
      ) : null}
    </>
  );
}

/**
 * الوسيط — لنوع «image» أو لأيّ شريحةٍ حمل المالكُ لها صورة (العقد §1 `overlay`):
 * صورةٌ بنقطة تركيزها، وحجابٌ بدرجته، وحجابٌ للنصّ تحته دائمًا — فيبقى الأبيض
 * مقروءًا مهما كانت الصورة. النصّ البديل من البيانات؛ فراغُه يعني صورةً
 * زخرفيّة (`alt=""`).
 */
function SlideMedia({ slide, first }: { slide: HeroSlide; first: boolean }) {
  return (
    <>
      {slide.media ? (
        <span className={styles.slideMedia} data-focal={slide.media.focal}>
          {/* `unoptimized`: الوسائط عبر وسيط المتجر بلا محسِّن صور (اصطلاح PdpGallery). */}
          <Image
            className={styles.slideMediaImg}
            src={mediaSrc(slide.media.url)}
            alt={slide.media.alt}
            fill
            sizes="(max-width: 767px) 100vw, 1360px"
            priority={first}
            unoptimized
          />
        </span>
      ) : null}
      <span className={styles.slideOverlay} data-overlay={slide.overlay} aria-hidden="true" />
    </>
  );
}

/** الصورة: نصّ العلامة (شارة، عنوان، وصف، دعوة) فوق الوسيط. */
function ImageSlide({ slide, h1 }: SlideProps) {
  return (
    <div className={styles.slideCopy}>
      {slide.eyebrow ? <p className={styles.brandBadge}>{slide.eyebrow}</p> : null}
      <SlideHeading slide={slide} h1={h1} className={styles.brandTitle} />
      {slide.subtitle ? <p className={styles.brandLede}>{slide.subtitle}</p> : null}
      <SlideCtas slide={slide} tone="dark" className={styles.brandCtas} />
    </div>
  );
}

interface SlideProps {
  slide: HeroSlide;
  tone: 'dark' | 'light';
  product?: ProductCardDto;
  kinds: string[];
  h1: boolean;
  first: boolean;
}

const KIND_CLASS: Record<HeroSlide['kind'], string | undefined> = {
  brand: styles.slideBrand,
  offer: styles.slideOffer,
  search: styles.slideSearch,
  image: styles.slideImage,
};

/** كتلٌ زخرفيّة لكلّ نوع — طبقة `aria-hidden` تحملها حركة «drift» الهادئة. */
const KIND_BLOB: Record<HeroSlide['kind'], string | undefined> = {
  brand: styles.slideBrandBlob,
  offer: styles.slideOfferBlob,
  search: styles.slideSearchBlob,
  image: undefined,
};

function SlideView(props: Omit<SlideProps, 'tone'>) {
  const { slide } = props;
  /* فوق الوسيط النصّ أبيض على حجابٍ داكن أيًّا كان الموضوع (الموضوع سطحُه قبل التحميل). */
  const onMedia = slide.kind === 'image' || slide.media !== null;
  const tone = onMedia ? 'dark' : THEME_TONE[slide.theme];
  const blob = onMedia ? undefined : KIND_BLOB[slide.kind];
  const Body = {
    brand: BrandSlide,
    offer: OfferSlide,
    search: SearchSlide,
    image: ImageSlide,
  }[slide.kind];
  return (
    <div
      className={`${styles.slide} ${KIND_CLASS[slide.kind] ?? ''}`}
      data-theme={slide.theme}
      data-tone={tone}
      data-align={slide.align}
      data-media={onMedia ? 'true' : undefined}
    >
      {onMedia ? <SlideMedia slide={slide} first={props.first} /> : null}
      {blob ? (
        <span className={styles.slideAmbient} aria-hidden="true">
          <span className={blob} />
          <span className={styles.slideGlow} />
        </span>
      ) : null}
      <Body {...props} tone={tone} />
    </div>
  );
}

async function HeroSection() {
  const [heroRes, bestRes, promoRes, stagesRes, subjectsRes, kindsRes, samplesRes] = await Promise.all([
    apiGet<HeroDto>('/api/v1/home/hero'),
    apiGetCollection<ProductCardDto>(BEST_PATH),
    apiGet<PromoDto>('/api/v1/home/promo'),
    apiGetCollection<TermDto>('/api/v1/catalog/stages'),
    apiGetCollection<TermDto>('/api/v1/catalog/subjects'),
    apiGetCollection<TermDto>('/api/v1/catalog/kinds'),
    apiGet<SamplesDto>('/api/v1/samples'),
  ]);
  if (failed(heroRes.status)) return <Fault />;

  /* The owner picked one of the pre-redesign hero designs in the admin. */
  if (heroPresentation(heroRes.data ?? {}).variant !== 'slider') return <LegacyHeroSection />;

  const hero = heroRes.data;
  const settings = heroSlider(hero?.slider);
  let slides = heroSlides(hero?.slides);

  /* التراجع (العقد §1): لا شرائح محفوظة ⇒ الثلاث من الحقول القديمة + promo + البحث. */
  if (slides.length === 0) {
    const promo = promoRes.status === 200 ? promoRes.data : null;
    const samples = samplesRes.status === 200 && (samplesRes.data?.count ?? 0) > 0;
    const chips: HeroChip[] = [
      ...stagesRes.data.slice(0, 2).map((t) => ({
        label: t.name,
        href: termPath('stages', t.slug),
        mobile: true,
      })),
      ...subjectsRes.data.slice(0, 2).map((t) => ({
        label: t.name,
        href: termPath('subjects', t.slug),
        mobile: false,
      })),
      ...kindsRes.data.slice(0, 1).map((t) => ({
        label: t.name,
        href: termPath('kinds', t.slug),
        mobile: false,
      })),
      ...(samples
        ? [
            {
              label: 'عيّنات مجانية',
              href: routes.samples,
              mobile: true,
              strong: true,
            },
          ]
        : []),
    ];
    slides = fallbackSlides({ hero, promo, chips, routes });
  }

  const kinds = samplesRes.data?.resourceTypes ?? [];
  const product = bestRes.status === 200 ? bestRes.data[0] : undefined;

  return (
    <div className={styles.heroOuter}>
      <HeroSlider
        label="عروض المتجر"
        settings={settings}
        slides={slides.map((slide, i) => ({
          id: slide.id,
          /* عنوانٌ رئيس واحد في الصفحة: أوّل شريحةٍ تحمله. */
          node: <SlideView slide={slide} product={product} kinds={kinds} h1={i === 0} first={i === 0} />,
        }))}
      />
    </div>
  );
}

/* ----------------------------------------------------------------- trust */

interface BenefitDto {
  title: string;
  description?: string;
}

/*
 * شريط الثقة يقرأ `/home/benefits` — البطاقات الأربع التي يحرّرها المالك
 * (عنوانٌ ووصف)، وهي ادّعاؤه لا ادّعاء الشيفرة. والأيقونة والصبغة بحسب
 * العنوان كما في القالب (تحميل · تعديل · دفع · دعم)، وما عداها بترتيبه.
 *
 * وحين لا يُنشر شيء (‎200‎ فارغة) تبقى الادّعاءات الملتزَم بها (قرارات
 * موثّقة سابقًا وتبقى):
 *  · «قابل للتعديل» ⇒ «صيغ جاهزة»: القابلية للتعديل حقلٌ لكلّ منتج
 *    (`editable`)، والكتالوج يشحن ملفّاتٍ للطباعة فقط.
 *  · «مدى، Apple Pay، والبطاقات» ⇒ «مدى والبطاقات»: `/payment-methods`
 *    تصنّف Apple Pay غير متاحة.
 *  · «دعم سريع — واتساب خلال ساعة»: يظهر فقط مع `NEXT_PUBLIC_WHATSAPP_NUMBER`،
 *    وبلا «خلال ساعة» (لا مصدر لزمن الردّ).
 */
const TRUST_LOOKS: { match: RegExp; icon: DesignIconName; tone: string }[] = [
  { match: /تحميل|فوري/, icon: 'download', tone: styles.toneTeal ?? '' },
  { match: /تعديل|صيغ/, icon: 'pen', tone: styles.toneGold ?? '' },
  { match: /دفع|آمن/, icon: 'shield', tone: styles.toneNavy ?? '' },
  { match: /دعم|واتساب/, icon: 'headset', tone: styles.toneLilac ?? '' },
];

const TRUST: BenefitDto[] = [
  { title: 'تحميل فوري', description: 'يصلك الملف بعد الدفع مباشرة' },
  { title: 'صيغ جاهزة', description: 'ملفات Word وPowerPoint وPDF' },
  { title: 'دفع آمن', description: 'مدى والبطاقات' },
  ...(process.env.NEXT_PUBLIC_WHATSAPP_NUMBER ? [{ title: 'دعم مباشر', description: 'تواصل معنا على واتساب' }] : []),
];

async function TrustSection() {
  const { data, status } = await apiGet<BenefitDto[]>('/api/v1/home/benefits');
  if (failed(status)) return <Fault />;
  const items = (Array.isArray(data) && data.length > 0 ? data : TRUST).slice(0, 4);

  return (
    <ul className={styles.trust} role="list" aria-label="لماذا تشتري منّا">
      {items.map((item, index) => {
        const look = TRUST_LOOKS.find((l) => l.match.test(item.title)) ?? TRUST_LOOKS[index % TRUST_LOOKS.length]!;
        return (
          <li key={item.title} className={styles.trustCard}>
            <span className={`${styles.trustIcon} ${look.tone}`}>
              <DesignIcon name={look.icon} size={19} className={styles.deskOnly} />
              <DesignIcon name={look.icon} size={16} strokeWidth={2} className={styles.mobOnly} />
            </span>
            <span>
              <b className={styles.trustTitle}>{item.title}</b>
              {item.description ? <span className={styles.trustDesc}>{item.description}</span> : null}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

/* ---------------------------------------------------------------- stages */

interface GradeDto {
  slug: string;
  name: string;
  stage?: { slug: string; name: string } | null;
}

const GRADE_FORMS: PluralForms = { zero: 'لا صفوف', one: 'صف واحد', two: 'صفّان', few: 'صفوف', many: 'صفًّا' };

/**
 * بطاقة كلّ مرحلة: رسمها (`STAGE_ART`) على لهجتها `--stage-*`، واسمها، ووصفها،
 * وسطرُ أرقامٍ حقيقيّة فقط — عدد المنتجات إن بثّه الكتالوج، وعدد الصفوف
 * المنشورة تحتها من `/catalog/grades` — ثمّ الدعوة. مرحلةٌ بلا رسمٍ معروف
 * تأخذ الحرف البديل بلا لهجة (لا تُزاح ألوان البقيّة).
 *
 * «أو حسب المادة» يبقى اختصارًا نصّيًّا حين لا يحمل الترتيبُ قسمَ الموادّ؛
 * ومعه يسقط — القسم نفسه بعد قليل برسومه، فلا تكرار.
 */
async function StagesSection({ sections }: HomeCtx) {
  const withSubjects = !sections.includes('subjects');
  const [stages, grades, subjects] = await Promise.all([
    apiGetCollection<TermDto>('/api/v1/catalog/stages'),
    apiGetCollection<GradeDto>('/api/v1/catalog/grades'),
    withSubjects ? apiGetCollection<TermDto>('/api/v1/catalog/subjects') : null,
  ]);
  if (failed(stages.status)) return <Fault />;
  if (stages.data.length === 0) return null;
  const gradesKnown = grades.status === 200;

  return (
    <div className={`${styles.wrap} ${styles.stages}`}>
      <Head
        eyebrow="تصفّح"
        title="ابدأ من مرحلتك"
        action={{ label: 'جميع المراحل', short: 'الكل', href: routes.stages }}
      />
      <ul className={styles.stageGrid} role="list">
        {stages.data.map((stage) => {
          const key = stageKeyOf(stage.name) ?? stageKeyOf(stage.slug);
          const count = typeof stage.productsCount === 'number' ? qty(stage.productsCount, PRODUCT_FORMS) : null;
          const gradeCount = gradesKnown ? grades.data.filter((g) => gradeInStage(g, stage)).length : 0;
          const facts = [count, gradeCount > 0 ? qty(gradeCount, GRADE_FORMS) : null].filter(Boolean);
          return (
            <li key={stage.slug}>
              <a className={`${styles.stageCard} ${stageTone(key)}`} href={termPath('stages', stage.slug)}>
                <ArtTile
                  art={key ? STAGE_ART[key] : null}
                  name={stage.name}
                  sizes="(max-width: 767px) 38px, 64px"
                  className={styles.stageTile}
                />
                <span className={styles.stageText}>
                  <b className={styles.stageName}>{stage.name}</b>
                  {stage.subtitle ? <span className={styles.stageSub}>{stage.subtitle}</span> : null}
                  {facts.length > 0 ? (
                    <span className={styles.stageMeta}>
                      {facts.map((fact, i) => (
                        <bdi key={i}>{fact}</bdi>
                      ))}
                    </span>
                  ) : null}
                </span>
                <span className={styles.stageGo}>
                  <span className={styles.deskOnly}>تصفّح المرحلة</span>
                  <DesignIcon name="chevNext" size={16} strokeWidth={2.2} />
                </span>
              </a>
            </li>
          );
        })}
      </ul>
      {subjects && subjects.status === 200 && subjects.data.length > 0 ? (
        <div className={styles.subjectRow}>
          <span className={styles.subjectLabel}>أو حسب المادة:</span>
          {subjects.data.slice(0, 6).map((subject, i) => (
            <a
              key={subject.slug}
              className={`${styles.subjectPill} ${i >= 4 ? styles.deskOnly : ''}`}
              href={termPath('subjects', subject.slug)}
            >
              {subject.name}
            </a>
          ))}
          <a className={`${styles.subjectPill} ${styles.subjectAll}`} href={routes.subjects}>
            كل المواد
          </a>
        </div>
      ) : null}
    </div>
  );
}

/* ------------------------------------------------------------ bestsellers */

/**
 * بطاقة القالب بعينها. الصورة الحقيقيّة تملأ مساحة الخطوط الزخرفيّة؛ وبلا
 * صورة تبقى الخطوط (رسمُ ورقة، لا ادّعاء). السطر الملوّن = مسار التصنيف
 * (المادّة · المرحلة)، والتقييم فقط بمتوسّطٍ وعددٍ حقيقيّين.
 *
 * «الأكثر طلبًا» على الأولى وحدها وليست ادّعاءً: `source=best` يرتّب
 * بالوحدات المباعة على طلباتٍ مدفوعة. وشارة الخصم من `discountPct`.
 */
function BestCard({ dto, first }: { dto: ProductCardDto; first: boolean }) {
  /*
   * البطاقة الواحدة بأفعالها الواحدة (القلب · «معاينة» · «أضف للسلة») كبقيّة
   * المتجر. و«الأكثر مبيعًا» على الأولى وحدها: القسم كلّه أكثرُ مبيعًا، فالشارة
   * على كلّ بطاقةٍ ضجيجٌ لا معلومة.
   */
  const props = toCardProps(dto);
  return (
    <ProductCard
      {...props}
      variant="featured"
      bestSeller={first}
      actions={cardWishlist(props)}
      previewSlot={props.unavailable ? undefined : cardPreview(props)}
      mediaSlot={cardFallbackArt(props.categoryPath, Boolean(props.image))}
      ctaSlot={<CardAddToCart productId={dto.id} title={dto.title} disabled={props.unavailable} />}
    />
  );
}

async function BestSection() {
  const { data, status } = await apiGetCollection<ProductCardDto>(BEST_PATH);
  if (failed(status)) return <Fault />;
  if (data.length === 0) return null;

  return (
    <div className={styles.bestBand}>
      <div className={`${styles.wrap} ${styles.bestInner}`}>
        {/* «مبيعات موثّقة» لا «هذا الأسبوع»: `source=best` يعدّ الوحدات
            المدفوعة منذ أوّل يوم بلا نافذةٍ زمنيّة. */}
        <Head
          eyebrow="مبيعات موثّقة"
          title="الأكثر مبيعًا"
          action={{ label: 'القائمة كاملة', href: routes.bestSellers }}
        />
        <ul className={styles.bestGrid} role="list">
          {data.map((dto, index) => (
            <li key={dto.id}>
              <BestCard dto={dto} first={index === 0} />
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

/* ----------------------------------------------------------- all products */

/**
 * «كل الملفّات» — الكتالوج كلّه بترتيب الأحدث، من مسار الأرشيف نفسه
 * (‎GET /api/v1/search‎) وبشبكته وبطاقته (`ProductGrid`). الصفحة الأولى
 * (حجمها ما يطبّقه الخادم، ‎results.perPage‎) في أوّل ‎HTML‎، والبقيّة بـ«عرض المزيد».
 * العدد في الوصف ‎results.total‎ كما هو؛ وصفرٌ منشور ⇒ لا قسم.
 */
async function AllProductsSection() {
  const { data, status } = await fetchSearch({ sort: 'newest', page: 1 });
  if (failed(status) || !data?.results) return <Fault />;
  const { items, total, perPage } = data.results;
  if (total === 0 || items.length === 0) return null;

  /* الرأس والرقائق داخل الجزيرة: رابط «تصفّح مع الفلاتر» يتبع المرشّح المختار. */
  return (
    <div className={`${styles.wrap} ${styles.allProducts}`}>
      <AllProductsMore
        initial={items.map(searchCardToProps)}
        total={total}
        perPage={perPage}
        filters={quickFilters(data.facets)}
        lede={<>{qty(total, PRODUCT_FORMS)} في المتجر، الأحدث أوّلًا.</>}
      />
    </div>
  );
}

/* ---------------------------------------------------------------- samples */

/**
 * بطاقة لكلّ نوعٍ له عيّنة منشورة فعلًا (أوّل ملفٍّ منه)، حتّى ثلاث — لا
 * الأنواع التي يرسمها القالب. «PDF · ٦ صفحات» ⇒ صيغة المنتج وحدها: عدد
 * الصفحات لا يعيده أيّ حقل. والرابط تحميلٌ مباشر للملفّ نفسه كما في `/samples`.
 */
async function SamplesSection() {
  const { data, status } = await apiGet<SamplesDto>('/api/v1/samples');
  if (failed(status)) return <Fault />;
  if (!data || data.count === 0) return null;

  const picks: {
    type: string;
    item: SamplesDto['items'][number];
    file: SamplesDto['items'][number]['previews'][number];
  }[] = [];
  for (const type of data.resourceTypes) {
    const item = data.items.find((entry) => entry.resourceType === type && entry.previews.length > 0);
    if (item && item.previews[0]) picks.push({ type, item, file: item.previews[0] });
    if (picks.length === 3) break;
  }
  if (picks.length === 0) return null;

  const tones = [styles.sampleTone0, styles.sampleTone1, styles.sampleTone2];

  return (
    <div className={`${styles.wrap} ${styles.samples}`}>
      <div className={styles.samplesCopy}>
        <p className={styles.eyebrow}>قبل أن تدفع</p>
        <h2 className={`${styles.h2} ${styles.samplesTitle}`}>
          <span className={styles.deskOnly}>جرّب عيّنة مجانية من كل نوع</span>
          <span className={styles.mobOnly}>عيّنات مجانية</span>
        </h2>
        <p className={styles.samplesLede}>
          <span className={styles.deskOnly}>لن تشتري ملفًّا لم تره. </span>
          حمّل عيّنة كاملة بلا حساب وبلا بريد.
        </p>
      </div>
      <ul className={styles.sampleGrid} role="list" style={{ '--samples': picks.length } as CSSProperties}>
        {picks.map(({ type, item, file }, i) => (
          <li key={type}>
            <a className={styles.sampleCard} href={file.url} download>
              <span className={`${styles.sampleFmt} ${tones[i] ?? ''}`}>{item.product.format ?? ''}</span>
              <span className={styles.sampleText}>
                <b className={styles.sampleName}>عيّنة {type}</b>
                <span className={styles.sampleDesc}>{item.product.title}</span>
              </span>
              <span className={styles.sampleCta}>
                <span className={styles.deskOnly}>تحميل مجاني</span>
                <DesignIcon name="download" size={15} strokeWidth={2} className={styles.deskOnly} />
                <DesignIcon name="download" size={18} strokeWidth={2} className={styles.mobOnly} />
              </span>
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}

/* ---------------------------------------------------------------- reviews */

/**
 * المتوسّط والعدد من `/home/reviews`؛ النجوم تُملأ بالمتوسّط الحقيقيّ جزئيًّا
 * (‎4.3‎ لا تُرسم خمسًا). «موثّقًا بالشراء» في القالب تُحذف: العدد يشمل كلّ
 * التقييمات المعتمدة لا المشتريات الموثّقة وحدها. والاقتباسات حقيقيّة بأسماء
 * أصحابها؛ السطر الثاني صفتُه ومدينته إن كتبهما، وإلّا المنتج الذي قيّمه.
 */
async function ReviewsSection() {
  const { data, status } = await apiGet<ReviewsDto>('/api/v1/home/reviews');
  if (failed(status)) return <Fault />;
  if (!data || data.rating_count === 0) return null;

  const avg = Math.round(data.rating_avg * 10) / 10;
  const fill = `${(Math.max(0, Math.min(5, data.rating_avg)) / 5) * 100}%`;

  return (
    <div className={`${styles.wrap} ${styles.reviews}`}>
      <h2 className={styles.srOnly}>تقييمات المعلّمين</h2>
      <div className={styles.rateRow}>
        <b className={styles.rateBig} dir="ltr">
          {formatNumber(avg)}
        </b>
        <div className={styles.rateSide}>
          <span className={styles.rateStars} role="img" aria-label={`${formatNumber(avg)} من 5`}>
            <span className={styles.rateTrack} aria-hidden="true">
              {[0, 1, 2, 3, 4].map((i) => (
                <DesignIcon key={i} name="star" size={16} />
              ))}
            </span>
            <span className={styles.rateFill} style={{ inlineSize: fill }} aria-hidden="true">
              {[0, 1, 2, 3, 4].map((i) => (
                <DesignIcon key={i} name="star" size={16} />
              ))}
            </span>
          </span>
          <span className={styles.rateCount}>
            من <bdi>{qty(data.rating_count, REVIEW_FORMS)}</bdi>
          </span>
        </div>
        <span className={styles.rateSpacer} />
        {data.all_reviews_url ? (
          <a className={styles.rateAll} href={data.all_reviews_url}>
            كل التقييمات
          </a>
        ) : null}
      </div>
      {data.quotes.length > 0 ? (
        <ul className={styles.quoteGrid} role="list">
          {data.quotes.slice(0, 3).map((quote) => {
            const who = [quote.author_role ?? quote.authorRole, quote.author_city ?? quote.authorCity]
              .filter(Boolean)
              .join(' — ');
            return (
              <li key={quote.id} className={styles.quote}>
                <svg
                  className={styles.quoteMark}
                  aria-hidden="true"
                  width="28"
                  height="22"
                  viewBox="0 0 28 22"
                  fill="currentColor"
                >
                  <path d="M16 22V12.4C16 5.6 19.6 1.4 26.6 0l1.2 2.6c-3.6 1.1-5.5 3.3-5.8 6.4H28V22H16ZM0 22V12.4C0 5.6 3.6 1.4 10.6 0l1.2 2.6C8.2 3.7 6.3 5.9 6 9h6v13H0Z" />
                </svg>
                <blockquote className={styles.quoteText}>«{quote.text}»</blockquote>
                <div className={styles.quoteBy}>
                  <span className={styles.avatar} aria-hidden="true">
                    {quoteInitials(quote.author)}
                  </span>
                  <span className={styles.quoteWho}>
                    <span className={styles.quoteNameRow}>
                      <b className={styles.quoteName}>{quote.author}</b>
                      {quote.verified === true ? (
                        <span className={styles.quoteVerified}>
                          <DesignIcon name="check" size={11} strokeWidth={2.6} />
                          شراء موثّق
                        </span>
                      ) : null}
                    </span>
                    {who ? <span className={styles.quoteRole}>{who}</span> : null}
                  </span>
                </div>
                <a className={styles.quoteProduct} href={routes.product(quote.product_slug)}>
                  <DesignIcon name="file" size={14} strokeWidth={2} />
                  <span>{quote.product_title}</span>
                </a>
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}

/* -------------------------------------------------------------------- cta */

/**
 * اللوحة الداكنة. العنوان والوصف والدعوتان من `/home/cta` (نصّ المتجر
 * المحرَّر؛ `secondary` كائنٌ أو `null`). وبلا ثانيةٍ منشورة: «اطلب ملفًا»
 * تنقّلٌ إلى `/request` — سطحٌ حقيقيّ لا وعد.
 */
async function CtaSection() {
  const { data, status } = await apiGet<CtaDto>('/api/v1/home/cta');
  if (failed(status)) return <Fault />;
  if (!data) return null;

  return (
    <div className={`${styles.wrap} ${styles.ctaOuter}`}>
      <div className={styles.cta}>
        <div className={styles.ctaCopy}>
          <h2 className={styles.ctaTitle}>{data.title}</h2>
          {data.subtitle ? <p className={styles.ctaLede}>{data.subtitle}</p> : null}
        </div>
        <div className={styles.ctaBtns}>
          {data.cta_label && data.cta_url ? (
            <a className={styles.ctaPrimary} href={data.cta_url}>
              {data.cta_label}
            </a>
          ) : null}
          <a className={styles.ctaGhost} href={data.secondary?.href ?? routes.request}>
            {data.secondary?.label ?? 'اطلب ملفًا'}
          </a>
        </div>
      </div>
    </div>
  );
}

/* --------------------------------------------------------------- registry */

export const DESIGN_SECTIONS = {
  hero: () => <HeroSection />,
  trust: () => <TrustSection />,
  stages: (ctx: HomeCtx) => <StagesSection {...ctx} />,
  best: () => <BestSection />,
  'all-products': () => <AllProductsSection />,
  samples: () => <SamplesSection />,
  reviews: () => <ReviewsSection />,
  cta: () => <CtaSection />,
} satisfies Partial<Record<HomepageSectionId, (ctx: HomeCtx) => ReactNode>>;
