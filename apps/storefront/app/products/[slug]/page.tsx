/**
 * PDP — Storefront Product.dc.html (desktop) and Storefront Product
 * Mobile.dc.html (below 768px), one responsive server-rendered page. H1,
 * price and JSON-LD are in the first HTML response.
 *
 * Composition, in the design's order: breadcrumbs › [gallery · buy panel ·
 * tabs] › teachers' reviews band › «قبل الشراء» FAQ › related rail; on mobile
 * an app bar above and a sticky buy bar below.
 *
 * HONESTY DOCTRINE (README non-negotiables, .design-sync/conventions.md):
 * every block is fed by a field the API returned and renders NOTHING without
 * it. The design's sample figures (4.9, 312, 1,840, 96 صفحة, 220 → 165 SAR,
 * «الإصدار 3.2», «38 MB», «48 ساعة», FL-1076-4382…) are layout samples.
 *
 * كلّ عنصرٍ في التصميم موجودٌ هنا، وكلٌّ منها يقرأ حقله: شارة «الأكثر طلبًا»
 * من `bestSeller`، وشريط «آخر تحديث · الإصدار · الترخيص» من حقوله الثلاثة،
 * وحزمة «وفّر أكثر» من `bundle`، و«يُشترى معه عادةً» من `related`، و«تصفّح
 * ذو صلة» من `internalLinks` في حمولة SEO، وزرّ Apple Pay وعلامات الدفع من
 * `GET /api/v1/checkout/methods`، والقلب من مخزن المفضّلة على المتصفّح.
 * ما غاب حقله غاب عنصره — لا رقمٌ من التصميم يُكتب هنا. وما بقي بلا مصدر
 * (لوحة «مهارات مستهدفة»، ووعد «نردّ خلال ساعة») يبقى غائبًا.
 *
 * The SEO graph and the visible breadcrumbs/FAQs come from ONE payload
 * (`/api/v1/seo/products/{slug}`), so visible = schema by construction; ONE
 * reviews payload feeds the rating row, the reviews tab and the band.
 */
import Image from 'next/image';
import { Fragment } from 'react';
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { formatMoney, formatNumber } from '@tahdheer/design-system/format';
import { getReviews } from '../../../components/reviews/api';
import type { ReviewAggregate } from '../../../components/reviews/types';
import { toFactViews, type FactView, type ProductFactDto } from '../../../components/product/facts';
import type { VariantDto } from '../../../components/product/FormatSelector';
import { FORMAT_PARAM, termCrumbUrl } from '../../../lib/pdp-selection';
import { hasInstantDownload } from '../../../lib/product-card-model';
import { VariantSelectionProvider } from '../../../components/product/VariantSelection';
import type { CompatibilityRowDto } from '../../../components/product/ProductCompatibility';
import {
  usableContentSections,
  type ContentSectionDto,
} from '../../../components/product/ProductContentSections';
import { CheckIcon, DownloadIcon, PenIcon, SearchIcon, BackIcon, ShieldIcon, StarIcon } from '../../../components/pdp/icons';
import { PageSelectionProvider } from '../../../components/pdp/PageSelection';
import { PdpGallery, type GalleryPage } from '../../../components/pdp/PdpGallery';
import { PdpPreviewCards } from '../../../components/pdp/PdpPreviewCards';
import { PdpPurchase, PdpStickyBar, type PdpPrice } from '../../../components/pdp/PdpPurchase';
import { PdpTabs, tabInputId, type PdpTab } from '../../../components/pdp/PdpTabs';
import { PdpFaq } from '../../../components/pdp/PdpFaq';
import { PdpReviewsBand } from '../../../components/pdp/PdpReviewsBand';
import { PdpRelated } from '../../../components/pdp/PdpRelated';
import { PdpRecent } from '../../../components/pdp/PdpRecent';
import { PdpSectionNav, type SectionLink } from '../../../components/pdp/PdpSectionNav';
import { PdpShare } from '../../../components/pdp/PdpShare';
import pdp from '../../../components/pdp/pdp.module.css';
import { CartLink } from '../../../components/SiteHeaderClient';
import { ConnectionError } from '../../../components/search/ConnectionError';
import { ProductSchema } from '../../../components/seo/ProductSchema';
import { encodeSlug, apiGet, apiGetCollection, apiGetResource } from '../../../lib/api';
import { mediaSrc } from '../../../lib/media-url';
import { formatsOf } from '../../../lib/product-format';
import { routes } from '../../../lib/routes';
import { truncateTitle, type BreadcrumbItem, type ProductSeoDto } from '../../../lib/seo';

/**
 * صورة منتج كما يصدرها الخادم: `w`/`h` هما البُعدان المخزَّنان، وغيابهما يعني
 * «غير معروف» فلا يُخترع بديل — العنصر يُصيَّر بلا سمة أبعاد بدل رقم كاذب.
 */
interface ProductMediumDto {
  url: string;
  alt?: string | null;
  w?: number | null;
  h?: number | null;
}

/**
 * Interim PDP DTO — camelCase, matching ProductResource (the generated OpenAPI
 * SDK supersedes this). Keys here MUST track the API: a silent casing drift
 * renders an empty page while the build stays green, which is exactly the bug
 * class the generated SDK exists to kill.
 */
/**
 * مصطلحُ تصنيفٍ واحد كما يصدره `ProductResource::term()` — معرّفٌ وسبيكةٌ
 * واسم. المفتاح غائبٌ كلّيًّا حين لا مصطلح، فلا اسم فارغ يُصيَّر.
 */
interface TermDto {
  id: number;
  slug: string;
  name: string;
}

interface ProductDetailDto {
  id: number;
  sku: string;
  slug: string;
  title: string;
  subtitle?: string;
  categoryPath?: string[];
  /**
   * مصطلحات التصنيف الأربعة كما يصدرها ProductResource.php:29-32. كانت
   * الصفحة تقرأ `categoryPath` المسطَّح وحده — سلسلةَ أسماءٍ بلا دلالة
   * لأيٍّ منها — فلا سبيل إلى تسمية «الصفّ» أو «الفصل» في بلاطةٍ مُعنونة.
   */
  stage?: TermDto | null;
  grade?: TermDto | null;
  subject?: TermDto | null;
  semester?: TermDto | null;
  /**
   * نوع المورد كما يصنّفه الكتالوج («أوراق عمل»، «عرض بوربوينت»…). يصدره
   * ProductCardResource ولم تكن الصفحة تقرؤه، فتُصيَّر شريحة النوع التي
   * يرسمها التصميم فوق العنوان من حقل موجود لا من تخمين.
   */
  resourceType?: string;
  priceMinor?: number;
  regularMinor?: number;
  /** رمز العملة كما يصدره الخادم («SAR»). */
  currency?: string;
  onSale?: boolean;
  /** Server-computed (PricingService::discountPct) — present only on a real sale. */
  discountPct?: number;
  /** e.g. «PPTX» — the stored file format. */
  format?: string;
  /** Three-state in storage; the key is OMITTED when undecided. */
  editable?: boolean;
  digital?: boolean;
  /**
   * §٦ — طبيعة المنتج كما يصدرها `ProductCardResource`.
   *
   * البتّة `digital` تبقى لأنّ لها مستهلكين، ولم تعد تكفي: ستّ طبائع لا
   * تُطوى في نعم/لا، فالهجين يقول «ليس رقميًّا» فيُخفي وعد التنزيل الذي
   * يستحقّه، والماديّ لا يقول للمشتري إنّه يُشحن. غيابُ المفتاح يعني منتجًا
   * سبق النموذج الكونيّ — لا «رقميًّا» ضمنًا.
   */
  productNature?: string | null;
  shortDesc?: string;
  description?: string;
  /** الغلاف كما يصدره ProductCardResource — بأبعاده كي يُحجز الصندوق. */
  image?: ProductMediumDto;
  /** بقية صور المعرض بترتيبها المخزَّن؛ المفتاح غائب حين لا تُحمَّل العلاقة. */
  images?: ProductMediumDto[];
  /**
   * التخطيط المنشور من اللوحة. غيابه يعني «الترتيب الافتراضي» لا «صفحة فارغة»:
   * منتج لم يُحرَّر تخطيطه يجب أن يظهر كاملًا كما كان.
   */
  layout?: { widget: string; visible: boolean }[] | null;
  /** Typed product facts (slides/pages/worksheets/activities…). */
  facts?: ProductFactDto[];
  /**
   * صيغ الملفّ القابلة للبيع، لكلٍّ سعرها. المفتاح غائب لمنتجٍ خارج النموذج
   * الكونيّ، ومصفوفةٌ من عنصرٍ واحد لمنتجٍ بصيغةٍ واحدة — وحينئذ لا يُصيَّر
   * المُبدِّل، لأنّ «اختر من بين واحد» ليس اختيارًا.
   */
  variants?: VariantDto[] | null;
  /** عدد عمليّات الشراء المدفوعة. غائبٌ قبل أوّل عملية — لا صفر يُعرض. */
  purchaseCount?: number;
  /** جدول التوافق (§٥٦). غائبٌ حين لا صفَّ له. */
  compatibility?: CompatibilityRowDto[];
  /** أقسام المحتوى المسمّاة (§٤٩). غائبةٌ حين لا صفَّ لها. */
  sections?: ContentSectionDto[];
  /** «يُشترى معه عادةً» — بطاقاتٌ لها مصدر، لا «منتجات مشابهة». */
  boughtTogether?: ProductCardDto[];
  /**
   * عيّنات مجّانيّة يجوز لأيّ زائر فتحها — ملفٌّ بدور `sample` ومرئيّته
   * `public_sample`، ورابطٌ أمكن إصداره فعلًا. المفتاح غائب حين لا عيّنة،
   * فيغيب زرّ «معاينة المنتج قبل الشراء» ولا يَعِد بما لا يُفتح.
   */
  previews?: { url: string; name: string; mime?: string | null }[];
  seo?: { seoTitle?: string; seoDesc?: string; quickAnswer?: string };
  /*
   * الحقول أدناه يضيفها الخادم الآن (ProductResource)؛ تُعلَن اختياريّةً
   * هنا لا في حزمة SDK المولَّدة، ويُصيَّر عنصرُ كلٍّ منها حين يصل فقط.
   */
  /** شارة «الأكثر طلبًا» على المعرض. */
  bestSeller?: boolean;
  /** شريط «آخر تحديث · الإصدار · الترخيص» — كلّ زوجٍ يُعرض حين يصل وحده. */
  updatedAt?: string | null;
  version?: string | null;
  licenseLabel?: string | null;
  /** حزمة «وفّر أكثر». */
  bundle?: BundleDto | null;
  /** «يُشترى معه عادةً» — الاسم الجديد لـ`boughtTogether`؛ كلاهما مقبول. */
  related?: ProductCardDto[];
}

interface BundleDto {
  slug: string;
  title: string;
  description?: string | null;
  itemsCount?: number | null;
  priceMinor: number;
  regularMinor?: number | null;
  currency?: string;
  url?: string | null;
}

/** «تصفّح ذو صلة» — أعمدة روابط من حمولة SEO. */
interface InternalLinkGroup {
  title: string;
  links: { label: string; href: string }[];
}

/** `GET /api/v1/checkout/methods` — وسائل الدفع كما يعلنها الخادم. */
interface CheckoutMethodsDto {
  providers?: unknown[];
  applePay?: boolean;
  marks?: string[];
}

/**
 * بطاقة المشاركة من الخادم — لا حقل يُخترع هنا.
 *
 * الحقل الغائب يُحذف لا يُملأ بقيمة قريبة: صورة نائبة في `og:image` تجعل كل
 * منتج بلا غلاف يبدو كأنّ له غلافًا.
 */
function openGraphFrom(seo: ProductSeoDto | null): Pick<Metadata, 'openGraph' | 'twitter'> {
  const og = seo?.metadata.openGraph;
  const tw = seo?.metadata.twitter;
  if (!og) return {};

  /*
   * `og.type` كان يُتجاهَل ويُكتب 'website' حرفيًّا، خلافًا للتعليق أعلاه
   * وللخادم الذي يحسبه 'product'. واتّحاد أنواع Next لا يضمّ 'product'، فيُبثّ
   * من `other` — وهو المخرج الذي يقبله Next لوسمٍ خارج اتّحاده.
   */
  return {
    openGraph: {
      /*
       * `og.type` من الخادم يكون 'product'، ولا سبيل إلى بثّه هنا:
       * اتّحاد أنواع Next لا يضمّه فيُسقطه التصيير صامتًا حين يُمرَّر مؤكَّدًا
       * (قِيس: صفر وسم og:type)، وبثّه عبر `other` يُخرج `name="og:type"`
       * بجانب `property="og:type"` فيتناقض وسمان. 'website' قيمةٌ صحيحة
       * وواحدة — أقلّ دقّة، ولا تكذب.
       */
      type: 'website',
      title: og.title,
      ...(og.description ? { description: og.description } : {}),
      url: og.url,
      siteName: og.siteName,
      locale: og.locale,
      ...(og.image ? { images: [{ url: og.image }] } : {}),
    },
    ...(tw
      ? {
          twitter: {
            card: tw.card === 'summary_large_image' ? 'summary_large_image' : 'summary',
            title: tw.title,
            ...(tw.description ? { description: tw.description } : {}),
            ...(tw.image ? { images: [tw.image] } : {}),
          },
        }
      : {}),
  };
}

/**
 * يحوّل توجيه الخادم («index, follow» / «noindex, nofollow») إلى عقد Next.
 *
 * الغياب يعني «لم يقل الخادم شيئًا» فلا نخترع توجيهًا: الافتراضي في الويب هو
 * الفهرسة، وادّعاء noindex بلا مصدر يُخفي صفحة سليمة.
 */
function robotsFromDirective(directive: string | undefined): { robots?: Metadata['robots'] } {
  if (!directive) return {};

  const parts = directive.toLowerCase().split(',').map((p) => p.trim());

  return {
    robots: {
      index: !parts.includes('noindex'),
      follow: !parts.includes('nofollow'),
    },
  };
}

type Props = { params: Promise<{ slug: string }> };

/**
 * الصفحة تقرأ `?token=` أيضًا — رمز الطلب الذي يُصدَر مرّةً عند الدفع.
 *
 * بدونه يُصيَّر قسم التقييمات للقراءة وحدها: `ReviewsSection` يبني عليه
 * `canVote` ونموذج «اكتب تقييمك»، فبلا تمريره لا يظهر أيّهما أبدًا في صفحة
 * المنتج — والمشتري الذي وصل من بريد طلبه لا يجد أين يكتب.
 *
 * وتقرأ `?fmt=` أيضًا — سبيكةُ الصيغة المختارة. بدونها كان الاختيار يعيش في
 * `useState` وحده: رابطٌ مُشارَك يفتح دائمًا على أوّل صيغةٍ قابلة للشراء، وزائرٌ
 * بلا جافاسكربت لا يرى إلّا ثمنها. والقيمة تُمرَّر **خامًا** كما وردت —
 * `variantForParam` داخل المُبدِّل هو مالك التحقّق والسقوط الصامت، فلا تحقّقان
 * يفترقان. واسم المُعامل من `FORMAT_PARAM` لا حرفيًّا: مصدرٌ واحد للاسم.
 *
 * وهو منفصل عن `Props` لأنّ `generateMetadata` لا يحتاجه.
 */
type PageProps = Props & {
  searchParams: Promise<Partial<Record<'token' | typeof FORMAT_PARAM, string>>>;
};

const getProduct = (slug: string) =>
  apiGetResource<ProductDetailDto>(`/api/v1/products/${encodeSlug(slug)}`);

const getSeo = (slug: string) =>
  apiGet<ProductSeoDto & { internalLinks?: InternalLinkGroup[] }>(`/api/v1/seo/products/${encodeSlug(slug)}`);

/**
 * وسائل الدفع. المسار يهبط الآن على الخادم؛ حتّى يهبط يُعامَل 404 (وأيّ
 * تعذّر) كـ«لا Apple Pay ولا علامات» — فيغيب الزرّ والصفّ، ولا يُخترعان.
 */
async function getCheckoutMethods(): Promise<{ applePay: boolean; marks: string[] }> {
  const { data } = await apiGet<CheckoutMethodsDto>('/api/v1/checkout/methods');
  return {
    applePay: data?.applePay === true,
    marks: (data?.marks ?? []).filter((mark): mark is string => typeof mark === 'string' && mark.trim() !== ''),
  };
}

/** التاريخ كما يكتبه التصميم (YYYY-MM-DD) من طابع ISO؛ null لطابعٍ لا يُقرأ. */
function dayOf(iso: string | null | undefined): string | null {
  return /^\d{4}-\d{2}-\d{2}/.exec(iso ?? '')?.[0] ?? null;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const [product, { data: seo }] = await Promise.all([getProduct(slug), getSeo(slug)]);

  /*
   * نفس فصل الدلالة الذي في جسم الصفحة — وكان هذا الموضع قد
   * بقي خلفه: عطلٌ عابر في الواجهة البرمجية كان يُصدِّر `<title>المنتج غير
   * موجود</title>` لصفحةٍ منتجُها قائم، فيقرؤها الزاحف والمشارِك حُكمًا
   * بالحذف. المفقود وحده يُسمّى مفقودًا؛ والعطل يُسمّى عطلًا ولا يُفهرس.
   */
  if (product.status !== 200 || !product.data) {
    return product.status === 404
      ? { title: 'المنتج غير موجود' }
      : { title: 'تعذّر تحميل المنتج', robots: { index: false, follow: true } };
  }

  const data = product.data;

  // The SEO domain owns the precedence chain; the page only falls back to the
  // product's own fields when that service is unreachable.
  const title = seo?.metadata.title ?? data.seo?.seoTitle ?? data.title;
  const description = seo?.metadata.description ?? data.seo?.seoDesc ?? data.shortDesc;

  return {
    title: truncateTitle(title),
    ...(description ? { description } : {}),
    /*
     * توجيه الفهرسة الذي حسبته IndexabilityPolicy على الخادم.
     *
     * كان يُحسب ولا يُصدَر: منتج تقرّر السياسة ألّا يُفهرس كان يُفهرس، وكل
     * قرار عدم فهرسة زينةً. الغياب هنا لا يُنتج خطأً — يُنتج صفحة مفهرسة
     * خلافًا للسياسة، ولا شيء في المسار يكشفه.
     */
    ...robotsFromDirective(seo?.metadata.robots),
    /*
     * OpenGraph وTwitter كما تحسبهما MetadataService.
     *
     * كانت تُحسب على الخادم كاملة — النوع والعنوان المُصيَّر والوصف والرابط
     * واسم الموقع واللغة والصورة — ولا تُصدَر في الصفحة إطلاقًا. النتيجة:
     * كل رابط يُشارَك في واتساب أو تويتر يظهر بلا عنوان ولا صورة ولا وصف،
     * وتُصيَّر البطاقة من تخمين المنصّة.
     */
    ...openGraphFrom(seo),
    alternates: {
      canonical: seo?.metadata.canonical ?? routes.product(data.slug),
      // اللغات تأتي من الخادم بعد التحقّق من وجود الصفحة فعلًا؛ الواجهة لا تخمّن.
      ...(seo?.metadata.alternates && seo.metadata.alternates.length > 0
        ? {
            languages: Object.fromEntries(
              seo.metadata.alternates.map((alternate) => [alternate.hreflang, alternate.href]),
            ),
          }
        : {}),
    },
  };
}

/**
 * The product layout contract (backend `ProductLayout::WIDGETS`, guarded by
 * scripts/guards/guard-layout-parity.mjs): the admin publishes an ORDER and a
 * VISIBILITY per widget. Order drives the buy-panel blocks (hero zone) and the
 * tabs (body zone); a hidden widget renders nowhere; `title` and `purchase`
 * can never be hidden. Default order = Storefront Product.dc.html, and the
 * server's WIDGETS list uses the same order so an untouched admin layout and
 * «no layout» render the same page.
 */
const DEFAULT_LAYOUT = [
  'gallery',
  'title',
  'purchase',
  'trust',
  'facts',
  'description',
  'glance',
  'content',
  'goals',
  'compat',
  'preview',
  'faqs',
  'reviews',
  'license',
  'together',
] as const;

type Widget = (typeof DEFAULT_LAYOUT)[number];

const REQUIRED_WIDGETS = new Set(['title', 'purchase']);

/* Buy panel: chips + h1 + rating · price/formats/CTAs · trust + payment marks · spec tiles. */
const HERO_ZONE = ['title', 'purchase', 'trust', 'facts'] as const;
/* Tabs (and the FAQ band / related rail below them). `glance` = the spec tables. */
const BODY_ZONE = [
  'description',
  'glance',
  'content',
  'goals',
  'compat',
  'preview',
  'faqs',
  'reviews',
  'license',
  'together',
] as const;

/**
 * Published layout → ordered widgets with visibility. Unknown or duplicate
 * widgets are dropped; widgets missing from an older layout come back in
 * their default place instead of vanishing; required ones are forced visible.
 */
function resolveLayout(layout: ProductDetailDto['layout']): { widget: Widget; visible: boolean }[] {
  const known = new Set<string>(DEFAULT_LAYOUT);
  const seen = new Set<string>();
  const resolved: { widget: Widget; visible: boolean }[] = [];
  for (const section of layout ?? []) {
    if (!known.has(section.widget) || seen.has(section.widget)) continue;
    seen.add(section.widget);
    resolved.push({
      widget: section.widget as Widget,
      visible: REQUIRED_WIDGETS.has(section.widget) ? true : section.visible !== false,
    });
  }
  for (const widget of DEFAULT_LAYOUT) {
    if (!seen.has(widget)) resolved.push({ widget, visible: true });
  }
  return resolved;
}

const GOAL_KINDS = new Set(['LEARNING_OBJECTIVES']);
/** «مهارات مستهدفة» — لوحة الشرائح في تبويب الأهداف، من قسم FEATURES. */
const SKILL_KINDS = new Set(['FEATURES']);
const LICENSE_KINDS = new Set(['LICENSE', 'RETURN_INFORMATION']);
/** جملة «التوافق» التمهيديّة — نصّ قسم COMPATIBILITY حين كتبه المحرّر. */
const COMPAT_KINDS = new Set(['COMPATIBILITY']);

/** Does any part of this product download? — the ONE rule, shared with the product card. */
function hasDownload(product: ProductDetailDto): boolean {
  return hasInstantDownload(product);
}

/** «التحضير» for a lesson-prep product, «الملف» otherwise — the design's noun follows the product. */
function nounOf(product: ProductDetailDto): string {
  return /تحضير/.test(product.resourceType ?? '') ? 'التحضير' : 'الملف';
}

/** Format labels as the design writes them («Word وPowerPoint»). */
const FORMAT_WORD: Record<string, string> = { DOCX: 'Word', PPTX: 'PowerPoint', PDF: 'PDF', XLSX: 'Excel' };

const EDITABLE_FORMATS = new Set(['DOCX', 'PPTX', 'XLSX']);

function formatWords(formats: string[]): string {
  const words = formats.map((format) => FORMAT_WORD[format.toUpperCase()] ?? format);
  return words.length <= 1 ? (words[0] ?? '') : `${words.slice(0, -1).join('، ')} و${words.at(-1)}`;
}

function factText(facts: FactView[], key: string): string | null {
  return facts.find((fact) => fact.key === key)?.text ?? null;
}

interface Chip {
  tone: 'teal' | 'navy' | 'neutral' | 'gold';
  name: string;
  url?: string;
}

/** Buy-panel chips: subject (teal) · grade (navy) · semester (neutral) · type (gold). */
function buildChips(product: ProductDetailDto, crumbs: BreadcrumbItem[]): Chip[] {
  const chips: Chip[] = [];
  const add = (tone: Chip['tone'], term: TermDto | null | undefined) => {
    if (term && term.name.trim() !== '') chips.push({ tone, name: term.name, url: termCrumbUrl(crumbs, term) });
  };
  add('teal', product.subject);
  add('navy', product.grade);
  add('neutral', product.semester);
  const type = product.resourceType?.trim();
  if (type) chips.push({ tone: 'gold', name: type });
  return chips;
}

interface Tile {
  key: string;
  label: string;
  value: string;
  tone: 'teal' | 'navy' | 'sand' | 'lilac' | 'clay' | 'steel';
  ltr?: boolean;
  /** Also on the mobile 2×2 grid (Storefront Product Mobile.dc.html). */
  mobile?: boolean;
}

/** «مواصفات المنتج» tiles — each from a real term, fact or format. */
function buildTiles(product: ProductDetailDto, facts: FactView[], formats: string[]): Tile[] {
  const tiles: Tile[] = [];
  if (product.subject?.name) tiles.push({ key: 'subject', label: 'المادة', value: product.subject.name, tone: 'teal', mobile: true });
  if (product.grade?.name) tiles.push({ key: 'grade', label: 'الصف', value: product.grade.name, tone: 'navy', mobile: true });
  if (product.semester?.name) tiles.push({ key: 'semester', label: 'الفصل', value: product.semester.name, tone: 'sand' });
  if (product.resourceType?.trim()) tiles.push({ key: 'type', label: 'النوع', value: product.resourceType.trim(), tone: 'lilac' });
  const size = [factText(facts, 'pages_count'), factText(facts, 'lessons_count') ?? factText(facts, 'slides_count')]
    .filter((part): part is string => part !== null)
    .join(' · ');
  if (size) tiles.push({ key: 'size', label: 'الحجم', value: size, tone: 'clay', mobile: true });
  if (formats.length > 0) {
    tiles.push({
      key: 'formats',
      label: formats.length > 1 ? 'الصيغ' : 'الصيغة',
      value: formats.join(' · '),
      tone: 'steel',
      ltr: true,
      mobile: true,
    });
  }
  return tiles;
}

interface SpecRow {
  label: string;
  value: string;
  ltr?: boolean;
}

/** The «المواصفات» box of the description tab — terms, type, pages, formats. */
function buildSpecs(product: ProductDetailDto, facts: FactView[], formats: string[]): SpecRow[] {
  const rows: SpecRow[] = [];
  if (product.subject?.name) rows.push({ label: 'المادة', value: product.subject.name });
  if (product.grade?.name) rows.push({ label: 'الصف', value: product.grade.name });
  if (product.semester?.name) rows.push({ label: 'الفصل', value: product.semester.name });
  if (product.resourceType?.trim()) rows.push({ label: 'النوع', value: product.resourceType.trim() });
  const pages = facts.find((fact) => fact.key === 'pages_count');
  if (pages) rows.push({ label: 'الصفحات', value: pages.text.replace(/\s*صفح.*$/, '') || pages.text, ltr: true });
  if (formats.length > 0) rows.push({ label: 'الصيغ', value: formats.join(' · ') });
  const updated = dayOf(product.updatedAt);
  if (updated) rows.push({ label: 'آخر تحديث', value: updated, ltr: true });
  return rows;
}

/** «المواصفات الكاملة» — every typed fact, then terms, formats and delivery. */
function buildFullSpecs(product: ProductDetailDto, facts: FactView[], formats: string[]): SpecRow[] {
  const rows: SpecRow[] = [];
  if (product.subject?.name) rows.push({ label: 'المادة', value: product.subject.name });
  if (product.grade?.name) rows.push({ label: 'الصف', value: product.grade.name });
  if (product.semester?.name) rows.push({ label: 'الفصل الدراسي', value: product.semester.name });
  if (product.resourceType?.trim()) rows.push({ label: 'نوع المحتوى', value: product.resourceType.trim() });
  for (const fact of facts) rows.push({ label: fact.label, value: fact.text });
  if (formats.length > 0) rows.push({ label: 'الصيغ', value: formats.join(' · ') });
  if (product.editable !== undefined) rows.push({ label: 'قابل للتعديل', value: product.editable ? 'نعم — بالكامل' : 'لا' });
  if (hasDownload(product)) rows.push({ label: 'التسليم', value: 'تحميل فوري بعد الدفع' });
  if (product.version?.trim()) rows.push({ label: 'الإصدار', value: product.version.trim(), ltr: true });
  const updated = dayOf(product.updatedAt);
  if (updated) rows.push({ label: 'آخر تحديث', value: updated, ltr: true });
  if (product.licenseLabel?.trim()) rows.push({ label: 'الترخيص', value: product.licenseLabel.trim() });
  return rows;
}

/** Gallery pages: the cover first («الغلاف»), then the stored media, SEO image last resort. */
function buildPages(product: ProductDetailDto, seo: ProductSeoDto | null): GalleryPage[] {
  const pages: GalleryPage[] = [];
  const seen = new Set<string>();
  const push = (medium: ProductMediumDto | undefined, label?: string) => {
    if (!medium || medium.url === '' || seen.has(medium.url)) return;
    seen.add(medium.url);
    const railLabel = label ?? (medium.alt && medium.alt !== product.title ? medium.alt : undefined);
    pages.push({
      url: mediaSrc(medium.url),
      alt: medium.alt || product.title,
      ...(railLabel ? { label: railLabel } : {}),
      ...(typeof medium.w === 'number' ? { width: medium.w } : {}),
      ...(typeof medium.h === 'number' ? { height: medium.h } : {}),
    });
  };
  push(product.image, 'الغلاف');
  for (const medium of product.images ?? []) push(medium);
  if (pages.length === 0 && seo?.visible.image) push({ url: seo.visible.image });
  return pages;
}

function Stars({ value, size, className }: { value: number; size: number; className?: string }) {
  const full = Math.round(value);
  return (
    <span className={className} aria-hidden="true">
      {[1, 2, 3, 4, 5].map((n) => (
        <StarIcon key={n} size={size} filled={n <= full} />
      ))}
    </span>
  );
}

function ReviewsSummary({ aggregate }: { aggregate: ReviewAggregate }) {
  return (
    <div className={pdp.revSummary}>
      <b className={pdp.revAvg} dir="ltr">
        {aggregate.avgRating.toFixed(1)}
      </b>
      <Stars value={aggregate.avgRating} size={15} className={pdp.revStars} />
      <span className={pdp.revCount}>
        {/* Not «موثّقًا بالشراء»: verification is per review; the aggregate has no verified count. */}
        <bdi dir="ltr">{formatNumber(aggregate.count)}</bdi> تقييمًا
      </span>
      <div className={pdp.revBars}>
        {[...aggregate.distribution]
          .sort((a, b) => b.stars - a.stars)
          .map((bar) => (
            <div className={pdp.revBar} key={bar.stars}>
              <span dir="ltr">{bar.stars}</span>
              <span className={pdp.revTrack}>
                <span style={{ inlineSize: `${bar.pct}%` }} />
              </span>
              <span dir="ltr">{formatNumber(bar.pct)}%</span>
            </div>
          ))}
      </div>
    </div>
  );
}

/**
 * «يُشترى معه عادةً» reads the real relation. Without one the rail shows the
 * store's paid best-sellers under an honest title — never «bought together».
 */
const RELATED_FETCH = 8;
const RELATED_SHOWN = 4;

function fetchBest() {
  return apiGetCollection<ProductCardDto>(`/api/v1/products?source=best&limit=${RELATED_FETCH}`);
}

/**
 * سطر الاسترجاع في قائمة الثقة — من سياسة الاسترجاع نفسها
 * (app/policies/content.ts، «خطأ تقني من جانبنا»). السياسة لا تذكر مهلةً
 * زمنيّة، فلا «48 ساعة» هنا: يُكتب وعدُ السياسة بصياغتها.
 */
const REFUND_PROMISE = {
  long: 'استرجاع كامل إن كان الملف تالفًا أو لا يطابق وصفه',
  short: 'استرجاع كامل إن لم يطابق الملف وصفه',
} as const;

export default async function ProductPage({ params, searchParams }: PageProps) {
  const { slug } = await params;
  const query = await searchParams;
  const orderToken = query.token ?? null;
  const initialFormat = query[FORMAT_PARAM];
  const [product, { data: seo }] = await Promise.all([getProduct(slug), getSeo(slug)]);

  /* Only 404 means «not found»; any other failure is a failure, not a deletion. */
  if (product.status === 404) notFound();
  if (product.status !== 200 || !product.data) {
    return (
      <div className={pdp.page}>
        <ConnectionError href={routes.product(slug)} failure={product.status === 0 ? 'network' : 'http'} />
      </div>
    );
  }
  const data = product.data;

  /* `related` أوّلًا؛ وحين تصل فارغةً يُقرأ `boughtTogether` القديم. */
  const together = (data.related?.length ? data.related : data.boughtTogether) ?? [];
  const [{ data: reviews }, best, payment] = await Promise.all([
    getReviews(data.id),
    together.length > 0 ? Promise.resolve(null) : fetchBest(),
    getCheckoutMethods(),
  ]);

  const crumbs = seo?.visible.breadcrumbs ?? [];
  const facts = toFactViews(data.facts);
  const formats = formatsOf(data);
  const pages = buildPages(data, seo);
  const chips = buildChips(data, crumbs);
  const tiles = buildTiles(data, facts, formats);
  const specs = buildSpecs(data, facts, formats);
  const fullSpecs = buildFullSpecs(data, facts, formats);
  const noun = nounOf(data);
  const download = hasDownload(data);
  const layout = resolveLayout(data.layout);
  const hidden = new Set<string>(layout.filter((section) => !section.visible).map((section) => section.widget));
  /* Body-zone order drives the tabs; the hero zone drives the buy panel. */
  const bodyOrder = layout
    .filter((section) => (BODY_ZONE as readonly string[]).includes(section.widget))
    .map((section) => section.widget as string);
  const order = (widget: string) => bodyOrder.indexOf(widget);
  const heroOrder = layout
    .filter((section) => section.visible && (HERO_ZONE as readonly string[]).includes(section.widget))
    .map((section) => section.widget);
  /* The mobile design puts the tiles before the trust box; only the default
     order is re-sequenced for phones — a published order is honoured as is. */
  const defaultHero = data.layout == null;
  const showGlance = !hidden.has('glance');
  const variants: VariantDto[] = data.variants ?? [];
  const previews = data.previews ?? [];
  const faqs = seo?.visible.faqs ?? [];
  const aggregate = reviews?.aggregate && reviews.aggregate.count > 0 ? reviews.aggregate : null;
  const reviewItems = reviews?.items ?? [];

  const price: PdpPrice | null =
    typeof data.priceMinor === 'number'
      ? {
          priceMinor: data.priceMinor,
          regularMinor: data.regularMinor,
          onSale: data.onSale,
          discountPct: data.discountPct,
        }
      : null;

  const sections = data.sections ?? [];
  const goalSections = usableContentSections(sections.filter((s) => GOAL_KINDS.has(s.kind)));
  const skillSections = usableContentSections(sections.filter((s) => SKILL_KINDS.has(s.kind)));
  const skills = skillSections.flatMap((section) => section.lines);
  /* جملة التصميم تحت «الأهداف التعليمية» — نصّ القسم فقط؛ لا تُخترع. */
  const goalNotes = goalSections.map((section) => section.text).filter((text) => text !== '');
  const compatNotes = usableContentSections(sections.filter((s) => COMPAT_KINDS.has(s.kind)))
    .map((section) => section.text)
    .filter((text) => text !== '');
  const licenseSections = usableContentSections(sections.filter((s) => LICENSE_KINDS.has(s.kind)));
  const contentSections = usableContentSections(
    sections.filter((s) => !GOAL_KINDS.has(s.kind) && !LICENSE_KINDS.has(s.kind) && !SKILL_KINDS.has(s.kind)),
  );
  const compat: CompatibilityRowDto[] = data.compatibility ?? [];

  /* The mobile back arrow goes to the parent crumb (the subject archive). */
  const parent = crumbs.length >= 2 ? crumbs[crumbs.length - 2] : undefined;
  const back = parent && parent.url !== '' ? parent : null;
  const subjectCrumb = data.subject?.name
    ? crumbs.find((crumb) => crumb.name === data.subject?.name && crumb.url !== '')
    : undefined;

  const lessons = factText(facts, 'lessons_count');
  const description = (data.description?.trim() ?? '')
    .split(/\n{2,}/)
    .map((paragraph) => paragraph.trim())
    .filter((paragraph) => paragraph !== '');

  /* ── tabs, in the design's order; each only with its data ── */
  const tabs: PdpTab[] = [];

  const descSpecs = showGlance ? specs : [];
  if (!hidden.has('description') && (description.length > 0 || descSpecs.length > 0)) {
    tabs.push({
      id: 'desc',
      label: 'الوصف',
      /* Mobile shows the paragraphs only — no paragraphs, no mobile tab. */
      desktopOnly: description.length === 0,
      content: (
        <div className={pdp.descGrid}>
          {description.length > 0 ? (
            <div>
              <h2 className={pdp.h2}>عن هذا {noun}</h2>
              {description.map((paragraph, index) => (
                <p className={pdp.prose} key={index}>
                  {paragraph}
                </p>
              ))}
            </div>
          ) : null}
          {descSpecs.length > 0 ? (
            <div className={pdp.specBox}>
              <b className={pdp.specBoxTitle}>المواصفات</b>
              <div className={pdp.specGrid}>
                {descSpecs.map((row) => (
                  <Spec key={row.label} row={row} />
                ))}
              </div>
            </div>
          ) : null}
        </div>
      ),
    });
  }

  if (!hidden.has('content') && contentSections.length > 0) {
    tabs.push({
      id: 'contents',
      label: 'محتويات الملف',
      short: 'المحتويات',
      content: (
        <div>
          <h2 className={pdp.h2Tight}>
            محتويات الملف{lessons ? ` — ${lessons}` : ''}
          </h2>
          <div className={pdp.unitGrid}>
            {contentSections.map((section, index) => (
              <div className={pdp.unit} key={`${section.kind}-${index}`}>
                {section.heading ? <b className={pdp.unitTitle}>{section.heading}</b> : null}
                {section.text ? <p className={pdp.unitText}>{section.text}</p> : null}
                {section.lines.length > 0 ? (
                  <ul className={pdp.unitList}>
                    {section.lines.map((line, i) => (
                      <li key={i}>
                        <span className={pdp.dot} aria-hidden="true" />
                        <span>{line}</span>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            ))}
          </div>
        </div>
      ),
    });
  }

  if (!hidden.has('goals') && (goalSections.length > 0 || skills.length > 0)) {
    tabs.push({
      id: 'goals',
      label: 'الأهداف التعليمية',
      short: 'الأهداف',
      content: (
        <div>
          <h2 className={pdp.h2Goals}>الأهداف التعليمية</h2>
          {goalNotes.map((note, index) => (
            <p className={pdp.h2Note} key={index}>
              {note}
            </p>
          ))}
          <div className={pdp.unitGrid}>
            {goalSections.map((section, index) => (
              <div className={pdp.unit} key={`${section.kind}-${index}`}>
                {section.heading ? <b className={pdp.goalTitle}>{section.heading}</b> : null}
                {section.lines.length > 0 ? (
                  <ul className={pdp.goalList}>
                    {section.lines.map((line, i) => (
                      <li key={i}>
                        <span className={pdp.goalCheck} aria-hidden="true">
                          <CheckIcon size={11} />
                        </span>
                        <span>{line}</span>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            ))}
          </div>
          {skills.length > 0 ? (
            <div className={pdp.skills}>
              <b className={pdp.skillsTitle}>{skillSections[0]?.heading?.trim() || 'مهارات مستهدفة'}</b>
              <div className={pdp.skillsList}>
                {skills.map((skill) => (
                  <span className={pdp.skill} key={skill}>
                    {skill}
                  </span>
                ))}
              </div>
            </div>
          ) : null}
        </div>
      ),
    });
  }

  const compatSpecs = showGlance ? fullSpecs : [];
  if (!hidden.has('compat') && (compat.length > 0 || compatSpecs.length > 0)) {
    const COMPAT_TONES = ['navy', 'clay', 'teal', 'lilac', 'steel', 'sand'] as const;
    tabs.push({
      id: 'compat',
      label: 'التوافق والمواصفات',
      short: 'التوافق',
      /* Mobile shows the compatibility rows only. */
      desktopOnly: compat.length === 0,
      content: (
        <div className={pdp.compatGrid}>
          {compat.length > 0 ? (
            <div>
              <h2 className={pdp.h2Sub}>التوافق</h2>
              {compatNotes.map((note, index) => (
                <p className={pdp.h2Note} key={index}>
                  {note}
                </p>
              ))}
              <div className={pdp.compatList}>
                {compat.map((row, index) => (
                  <div className={pdp.compatRow} data-tone={COMPAT_TONES[index % COMPAT_TONES.length]} key={`${row.name}-${index}`}>
                    <span className={pdp.compatCheck} aria-hidden="true">
                      <CheckIcon size={14} />
                    </span>
                    <span className={pdp.compatName}>
                      <b>{row.name}</b>
                      {row.note ? <span>{row.note}</span> : null}
                    </span>
                    {row.minVersion ? (
                      <span className={pdp.compatVer}>
                        <span className={pdp.compatVerLong}>
                          <bdi dir="ltr">{row.minVersion}</bdi> وأحدث
                        </span>
                        <span className={pdp.compatVerShort} dir="ltr">
                          {row.minVersion}+
                        </span>
                      </span>
                    ) : null}
                  </div>
                ))}
              </div>
            </div>
          ) : null}
          {compatSpecs.length > 0 ? (
            <div className={pdp.desktopOnly}>
              <h2 className={pdp.h2Sub}>المواصفات الكاملة</h2>
              <p className={pdp.h2Note}>كل ما تحتاج معرفته قبل التحميل.</p>
              <div className={pdp.specTable}>
                {compatSpecs.map((row) => (
                  <div className={pdp.specTableRow} key={row.label}>
                    <span>{row.label}</span>
                    <b dir="auto">{row.value}</b>
                  </div>
                ))}
              </div>
            </div>
          ) : null}
        </div>
      ),
    });
  }

  const previewPart = !hidden.has('preview') && (pages.length > 1 || previews.length > 0);
  const faqPart = !hidden.has('faqs') && faqs.length > 0;
  if (previewPart || faqPart) {
    tabs.push({
      id: 'previewfaq',
      label: previewPart && faqPart ? 'المعاينة والأسئلة الشائعة' : previewPart ? 'المعاينة' : 'الأسئلة الشائعة',
      short: faqPart ? 'أسئلة شائعة' : undefined,
      desktopOnly: !faqPart,
      content: (
        <div>
          {previewPart ? (
            <div className={pdp.desktopOnly}>
              <h2 className={pdp.h2Sub}>المعاينة</h2>
              {pages.length > 1 ? (
                <p className={pdp.h2Note}>اضغط أي صفحة لعرضها في المعرض بالأعلى.</p>
              ) : null}
              {pages.length > 1 ? <PdpPreviewCards pages={pages} /> : null}
              {previews[0] ? (
                <a className={pdp.sampleBtn} href={previews[0].url} target="_blank" rel="noopener">
                  <DownloadIcon />
                  حمّل عيّنة مجانية
                </a>
              ) : null}
            </div>
          ) : null}
          {faqPart ? (
            <>
              <h2 className={previewPart ? pdp.h2Faq : pdp.h2FaqFirst}>أسئلة شائعة</h2>
              <div className={pdp.desktopOnly}>
                <PdpFaq items={faqs} name="pdp-faq-tab" variant="rows" />
              </div>
              <div className={pdp.mobileOnly}>
                <PdpFaq items={faqs} name="pdp-faq-tab-m" variant="cards" />
              </div>
            </>
          ) : null}
        </div>
      ),
    });
  }

  if (!hidden.has('reviews') && aggregate) {
    tabs.push({
      id: 'reviews',
      label: `التقييمات (${formatNumber(aggregate.count)})`,
      desktopOnly: true,
      content: (
        <div className={pdp.revGrid}>
          <ReviewsSummary aggregate={aggregate} />
          <div className={pdp.revList}>
            {reviewItems.slice(0, 3).map((review) => {
              const role = [review.authorRole, review.authorCity].filter(Boolean).join('، ');
              const date = review.createdAt?.slice(0, 10);
              const text = review.body?.trim() || review.title?.trim();
              return (
                <div className={pdp.revCard} key={review.id}>
                  <div className={pdp.revHead}>
                    <b>
                      {review.authorName}
                      {role ? <span> — {role}</span> : null}
                    </b>
                    {date ? <span dir="ltr">{date}</span> : null}
                  </div>
                  {text ? <p>{text}</p> : null}
                </div>
              );
            })}
          </div>
        </div>
      ),
    });
  }

  if (!hidden.has('license') && licenseSections.length > 0) {
    tabs.push({
      id: 'license',
      label: 'الترخيص والاسترجاع',
      desktopOnly: true,
      content: (
        <div className={pdp.license}>
          <h2 className={pdp.h2}>ترخيص الاستخدام</h2>
          {licenseSections.flatMap((section, index) => [
            ...(section.text ? [<p key={`t-${index}`}>{section.text}</p>] : []),
            ...section.lines.map((line, i) => <p key={`l-${index}-${i}`}>{line}</p>),
          ])}
          <p>
            <a href={routes.policy('license')}>نصّ الترخيص كاملًا</a> ·{' '}
            <a href={routes.policy('refund')}>سياسة الاسترجاع</a>
          </p>
        </div>
      ),
    });
  }

  /* `glance` visible while both tabs that host its tables are hidden/empty:
     it keeps its own tab so a visible widget never renders nowhere. */
  if (showGlance && fullSpecs.length > 0 && !tabs.some((tab) => tab.id === 'desc' || tab.id === 'compat')) {
    tabs.push({
      id: 'glance',
      label: 'المواصفات',
      desktopOnly: true,
      content: (
        <div className={pdp.specTable}>
          {fullSpecs.map((row) => (
            <div className={pdp.specTableRow} key={row.label}>
              <span>{row.label}</span>
              <b dir="auto">{row.value}</b>
            </div>
          ))}
        </div>
      ),
    });
  }

  /* Tab order = the published body order (preview+faqs share one tab, placed
     where the earlier of the two sits). */
  const TAB_WIDGETS: Record<string, readonly string[]> = {
    desc: ['description'],
    contents: ['content'],
    goals: ['goals'],
    compat: ['compat'],
    previewfaq: ['preview', 'faqs'],
    reviews: ['reviews'],
    license: ['license'],
    glance: ['glance'],
  };
  const tabRank = (id: string) => Math.min(...(TAB_WIDGETS[id] ?? []).map(order).filter((n) => n >= 0));
  tabs.sort((a, b) => tabRank(a.id) - tabRank(b.id));

  /* Other query params (e.g. the review `token`) survive a no-JS format change. */
  const carryQuery = Object.entries(query)
    .filter(([key, value]) => key !== FORMAT_PARAM && typeof value === 'string')
    .map(([key, value]) => [key, value as string] as [string, string]);

  const whatsapp = process.env.NEXT_PUBLIC_WHATSAPP_NUMBER?.replace(/\D/g, '');
  const trust: { key: string; icon: 'download' | 'pen' | 'shield'; long: string; short: string }[] = [];
  if (download) {
    trust.push({
      key: 'download',
      icon: 'download',
      /* Not «يبقى … دائمًا»: entitlements can carry an expiry or a download cap. */
      long: 'تحميل فوري بعد الدفع — ويظهر في مكتبتك',
      short: 'تحميل فوري بعد الدفع — يظهر في مكتبتك',
    });
  }
  /* Only the editable formats (PDF is print-ready, not open for editing). */
  const editableFormats = formats.filter((format) => EDITABLE_FORMATS.has(format.toUpperCase()));
  if (data.editable === true && editableFormats.length > 0) {
    const words = formatWords(editableFormats);
    const many = editableFormats.length > 1;
    trust.push({
      key: 'editable',
      icon: 'pen',
      long: `${many ? 'ملفات' : 'ملف'} ${words} ${many ? 'مفتوحة' : 'مفتوح'} بالكامل للتعديل`,
      short: `${many ? 'ملفات' : 'ملف'} ${words} ${many ? 'مفتوحة' : 'مفتوح'} للتعديل`,
    });
  }
  trust.push({ key: 'refund', icon: 'shield', ...REFUND_PROMISE });

  const relatedItems = (together.length > 0 ? together : (best?.data ?? []).filter((card) => card.id !== data.id)).slice(
    0,
    RELATED_SHOWN,
  );

  /* شريط «آخر تحديث · الإصدار · الترخيص» — الأزواج التي وصلت فقط. */
  const meta: { label: string; value: string; ltr?: boolean }[] = [];
  const updatedDay = dayOf(data.updatedAt);
  if (updatedDay) meta.push({ label: 'آخر تحديث', value: updatedDay, ltr: true });
  if (data.version?.trim()) meta.push({ label: 'الإصدار', value: data.version.trim(), ltr: true });
  if (data.licenseLabel?.trim()) meta.push({ label: 'الترخيص', value: data.licenseLabel.trim() });

  const bundle = data.bundle && typeof data.bundle.priceMinor === 'number' ? data.bundle : null;
  const bundleWas =
    bundle && typeof bundle.regularMinor === 'number' && bundle.regularMinor > bundle.priceMinor
      ? bundle.regularMinor
      : null;
  const bundleHref = bundle ? bundle.url || routes.bundle(bundle.slug) : null;

  const internalLinks = (seo?.internalLinks ?? []).filter(
    (group) => group.title?.trim() && Array.isArray(group.links) && group.links.length > 0,
  );

  /* «كل ملفات {المادة} {الصف}» — الرابط التالي للشبكة، من مسار التصفّح. */
  const relatedMore = subjectCrumb
    ? {
        href: subjectCrumb.url,
        label: `كل ملفات ${[data.subject?.name, data.grade?.name].filter(Boolean).join(' ')}`,
        short: 'الكل',
      }
    : undefined;

  /* عنصر المفضّلة: الصفحة تحفظ ما تحتاجه بطاقة /wishlist بلا طلب شبكة. */
  const wishItem =
    typeof data.priceMinor === 'number'
      ? {
          slug: data.slug,
          title: data.title,
          priceMinor: data.priceMinor,
          currency: data.currency ?? formatMoney(data.priceMinor).currency,
          image: data.image?.url ? { url: mediaSrc(data.image.url), alt: data.image.alt ?? null } : null,
          subtitle: data.subtitle ?? null,
        }
      : null;

  /* شريط الأقسام (سطح المكتب): ما صُيِّر فعلًا من الأقسام، بترتيب الصفحة. */
  const showFaqBand = faqs.length > 0 && !hidden.has('faqs');
  const showRelated = !hidden.has('together') && relatedItems.length > 0;
  const sectionLinks: SectionLink[] = [
    ...tabs
      .filter((tab) => tab.id === 'desc' || tab.id === 'contents')
      .map((tab) => ({ key: tab.id, label: tab.id === 'desc' ? 'الوصف' : 'المحتويات', target: 'pdp-details', tab: tab.id })),
    { key: 'reviews', label: 'التقييمات', target: 'pdp-reviews' },
    ...(showFaqBand ? [{ key: 'faq', label: 'الأسئلة', target: 'pdp-faq' }] : []),
    ...(showRelated ? [{ key: 'related', label: 'المرتبطة', target: 'pdp-related' }] : []),
  ];

  return (
    <VariantSelectionProvider variants={variants} initialFormat={initialFormat}>
      <PageSelectionProvider>
        <article className={pdp.page}>
          <ProductSchema graph={seo?.schema} />

          {/* Mobile app bar — Storefront Product Mobile.dc.html (the site header hides on mobile PDPs). */}
          <div className={pdp.appBar}>
            {back ? (
              <a className={pdp.appBtn} href={back.url} aria-label={`رجوع إلى ${back.name}`}>
                <BackIcon />
              </a>
            ) : (
              <span className={pdp.appSpacer} aria-hidden="true" />
            )}
            <a className={pdp.appLogo} href={routes.home}>
              <Image src="/brand/logo-full.png" alt="التحاضير الحديثة" width={512} height={289} unoptimized />
            </a>
            <a className={pdp.appBtn} href={routes.search} aria-label="البحث">
              <SearchIcon />
            </a>
            <CartLink />
          </div>

          {crumbs.length > 0 ? (
            <nav className={pdp.crumbs} aria-label="مسار التصفح">
              {crumbs.map((crumb, index) => {
                const last = index === crumbs.length - 1;
                return (
                  <span className={last ? pdp.crumbLast : pdp.crumb} key={`${index}-${crumb.name}`}>
                    {last || crumb.url === '' ? (
                      <b aria-current={last ? 'page' : undefined}>{crumb.name}</b>
                    ) : (
                      <a href={crumb.url}>{crumb.name}</a>
                    )}
                  </span>
                );
              })}
            </nav>
          ) : null}

          <div className={pdp.product}>
            {/* بلا وسائط يُصيَّر المسرح بورقته الزخرفيّة (PdpGallery) — لا فراغٌ ميّت بجوار لوحة الشراء. */}
            {!hidden.has('gallery') ? (
              <div className={pdp.galleryArea}>
                <PdpGallery
                  pages={pages}
                  bestSeller={data.bestSeller === true}
                  preview={previews[0] ? { href: previews[0].url, count: previews.length } : undefined}
                />
              </div>
            ) : null}

            <aside className={pdp.buy} aria-label="شراء المنتج" data-default-order={defaultHero ? '' : undefined}>
              {heroOrder.map((widget) => {
                switch (widget) {
                  case 'title':
                    return (
                      <Fragment key="title">
              {chips.length > 0 ? (
                <div className={pdp.chips}>
                  {chips.map((chip) =>
                    chip.url ? (
                      <a className={pdp.chip} data-tone={chip.tone} href={chip.url} key={chip.name}>
                        {chip.name}
                      </a>
                    ) : (
                      <span className={pdp.chip} data-tone={chip.tone} key={chip.name}>
                        {chip.name}
                      </span>
                    ),
                  )}
                </div>
              ) : null}

              <div className={pdp.titleRow}>
                <h1 className={pdp.title}>{data.title}</h1>
                <PdpShare title={data.title} />
              </div>

              {aggregate || (typeof data.purchaseCount === 'number' && data.purchaseCount > 0) ? (
                <div className={pdp.rating}>
                  {aggregate ? (
                    <>
                      <Stars value={aggregate.avgRating} size={14} className={pdp.ratingStars} />
                      <b dir="ltr" className={pdp.ratingAvg}>
                        {aggregate.avgRating.toFixed(1)}
                      </b>
                      {tabs.some((tab) => tab.id === 'reviews') ? (
                        <label className={pdp.ratingLink} htmlFor={tabInputId('reviews')}>
                          <bdi dir="ltr">{formatNumber(aggregate.count)}</bdi> تقييمًا
                        </label>
                      ) : (
                        <span className={pdp.ratingCount}>
                          <bdi dir="ltr">{formatNumber(aggregate.count)}</bdi> تقييمًا
                        </span>
                      )}
                      <span className={pdp.ratingCountShort} dir="ltr">
                        ({formatNumber(aggregate.count)})
                      </span>
                    </>
                  ) : null}
                  {typeof data.purchaseCount === 'number' && data.purchaseCount > 0 ? (
                    <>
                      {aggregate ? (
                        <span className={pdp.ratingSep} aria-hidden="true" />
                      ) : null}
                      <span>
                        <bdi dir="ltr">{formatNumber(data.purchaseCount)}</bdi> عملية شراء
                      </span>
                    </>
                  ) : null}
                </div>
              ) : null}

                      </Fragment>
                    );
                  case 'purchase':
                    return (
                      <Fragment key="purchase">
              <PdpPurchase
                productId={data.id}
                slug={data.slug}
                price={price}
                instantDownload={download}
                editable={data.editable === true}
                query={carryQuery}
                applePay={payment.applePay}
                wishItem={wishItem}
              />

                      </Fragment>
                    );
                  case 'trust':
                    return (
                      <Fragment key="trust">
              {trust.length > 0 ? (
                <ul className={pdp.trust}>
                  {trust.map((item) => (
                    <li key={item.key}>
                      <span className={pdp.trustIcon}>
                        {item.icon === 'download' ? <DownloadIcon /> : item.icon === 'pen' ? <PenIcon /> : <ShieldIcon />}
                      </span>
                      <span className={pdp.trustLong}>{item.long}</span>
                      <span className={pdp.trustShort}>{item.short}</span>
                    </li>
                  ))}
                </ul>
              ) : null}

              {payment.marks.length > 0 ? (
                <div className={pdp.marks}>
                  <span>ادفع عبر</span>
                  {payment.marks.map((mark) => (
                    <span className={pdp.mark} key={mark} dir="ltr">
                      {mark}
                    </span>
                  ))}
                </div>
              ) : null}
                      </Fragment>
                    );
                  case 'facts':
                    return <Fragment key="facts">{tiles.length > 0 ? (
                <div className={pdp.tilesBlock}>
                  <b className={pdp.tilesTitle}>مواصفات المنتج</b>
                  <div className={pdp.tiles}>
                    {tiles.map((tile) => (
                      <div
                        className={pdp.tile}
                        data-tone={tile.tone}
                        data-mobile={tile.mobile ? '' : undefined}
                        key={tile.key}
                      >
                        <span>{tile.label}</span>
                        <b dir={tile.ltr ? 'ltr' : undefined}>{tile.value}</b>
                      </div>
                    ))}
                  </div>
                  {meta.length > 0 ? (
                    <div className={pdp.meta}>
                      {meta.map((pair) => (
                        <span className={pdp.metaPair} key={pair.label}>
                          <span>{pair.label}</span>
                          <b dir={pair.ltr ? 'ltr' : undefined}>{pair.value}</b>
                        </span>
                      ))}
                    </div>
                  ) : null}
                </div>
              ) : null}</Fragment>;
                  default:
                    return null;
                }
              })}
            </aside>

            {tabs.length > 0 ? (
              <div className={pdp.tabsArea} id="pdp-details">
                <PdpTabs tabs={tabs} />
              </div>
            ) : null}
          </div>

          <PdpSectionNav links={sectionLinks} title={data.title} />

          <PdpReviewsBand
            productId={data.id}
            reviews={reviewItems}
            aggregate={aggregate}
            orderToken={orderToken}
          />

          {bundle && bundleHref ? (
            <section className={pdp.bundleWrap} aria-labelledby="pdp-bundle-title">
              <div className={pdp.bundle}>
                <div className={pdp.bundleText}>
                  <p className={pdp.bundleEyebrow}>وفّر أكثر</p>
                  <h2 className={pdp.bundleTitle} id="pdp-bundle-title">
                    {bundle.title}
                  </h2>
                  {bundle.description?.trim() ? <p className={pdp.bundleNote}>{bundle.description.trim()}</p> : null}
                </div>
                <div className={pdp.bundleSide}>
                  <span className={pdp.bundlePrice}>
                    {bundleWas !== null ? (
                      <del dir="ltr" className={pdp.bundleWas}>
                        {formatMoney(bundleWas).amount}
                        <span className={pdp.bundleWasUnit}> {bundle.currency ?? formatMoney(bundleWas).currency}</span>
                      </del>
                    ) : null}
                    <b dir="ltr" className={pdp.bundleNow}>
                      {formatMoney(bundle.priceMinor).amount}{' '}
                      <span>{bundle.currency ?? formatMoney(bundle.priceMinor).currency}</span>
                    </b>
                  </span>
                  <a className={pdp.bundleBtn} href={bundleHref}>
                    عرض الحزمة
                  </a>
                </div>
              </div>
            </section>
          ) : null}

          {showFaqBand ? (
            <section className={pdp.faqBand} id="pdp-faq" aria-labelledby="pdp-faq-band-title">
              <div className={pdp.faqBandInner}>
                <div>
                  <p className={pdp.eyebrow}>قبل الشراء</p>
                  <h2 className={pdp.sectionTitle} id="pdp-faq-band-title">
                    أسئلة شائعة عن هذا {noun}
                  </h2>
                  <p className={pdp.faqBandNote}>
                    لم تجد إجابتك؟{' '}
                    {whatsapp ? (
                      <a href={`https://wa.me/${whatsapp}`} target="_blank" rel="noopener noreferrer">
                        راسلنا على واتساب
                      </a>
                    ) : (
                      <a href={routes.contact}>راسلنا</a>
                    )}
                    .
                  </p>
                </div>
                <PdpFaq items={faqs} name="pdp-faq-band" variant="cards" />
              </div>
            </section>
          ) : null}

          {!hidden.has('together') ? (
            together.length > 0 ? (
              <PdpRelated
                id="pdp-related-title"
                sectionId="pdp-related"
                eyebrow={data.subject && data.grade ? 'نفس الصف والمادة' : 'مع هذا الملف'}
                title="يُشترى معه عادةً"
                more={relatedMore}
                items={relatedItems}
              />
            ) : (
              <PdpRelated
                id="pdp-related-title"
                sectionId="pdp-related"
                eyebrow="يشتريها المعلّمون كثيرًا"
                title="الأكثر مبيعًا في المتجر"
                more={{ href: routes.bestSellers, label: 'القائمة كاملة', short: 'الكل' }}
                items={relatedItems}
              />
            )
          ) : null}

          <PdpRecent current={wishItem} />

          {internalLinks.length > 0 ? (
            <nav className={pdp.links} aria-label="تصفّح ذو صلة">
              {internalLinks.map((group) => (
                <div key={group.title}>
                  <b className={pdp.linksTitle}>{group.title}</b>
                  <div className={pdp.linksList}>
                    {group.links.map((link) => (
                      <a href={link.href} key={`${link.href}-${link.label}`}>
                        {link.label}
                      </a>
                    ))}
                  </div>
                </div>
              ))}
            </nav>
          ) : null}

          <PdpStickyBar productId={data.id} slug={data.slug} price={price} applePay={payment.applePay} wishItem={wishItem} />
        </article>
      </PageSelectionProvider>
    </VariantSelectionProvider>
  );
}

function Spec({ row }: { row: SpecRow }) {
  return (
    <>
      <span className={pdp.specLabel}>{row.label}</span>
      <b className={pdp.specValue} dir={row.ltr ? 'ltr' : undefined}>
        {row.value}
      </b>
    </>
  );
}
