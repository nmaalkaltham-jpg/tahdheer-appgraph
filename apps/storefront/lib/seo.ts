/**
 * SEO helpers for the storefront.
 *
 * The Laravel `Domain/SEO` services are the AUTHORITY for titles, descriptions,
 * canonicals, robots and the JSON-LD graph (MIP §7 «one owner per capability»).
 * This module only carries the client-side budgets and URL helpers the App
 * Router needs, plus the response types of the SEO API.
 */

/** SEO title budget (MIP §7.6 editor input rule: seo_title ≤ 60 chars). */
export const TITLE_MAX = 60;

/*
 * ─── إنذار قطع العنوان المُصيَّر وميزانية الوصف: ليسا هنا ───
 *
 * كانا ثابتين مُصدَّرين (`TITLE_ALARM = 68` و`DESCRIPTION_MAX = 155`) ومعهما
 * `truncateDescription` و`isTitleOverAlarm` — **بلا مستهلكٍ واحد** في
 * المستودع كلّه. وهما في الوقت نفسه نسخةٌ ثانية من `TextBudget::TITLE_ALARM`
 * في الخادم، وصدر هذا الملفّ يقول إنّ خدمات `Domain/SEO` هي المرجع.
 *
 * ونسختان لعتبةٍ واحدة تتباعدان عند أوّل تعديل، فيقيس الخادم شيئًا وتعرض
 * الواجهة غيره. والحكم يصل جاهزًا أصلًا: `MetadataService` يبثّ
 * `renderedTitleAlarm` منطقًا محسوبًا، وتقرؤه لوحة الإدارة كما هو.
 *
 * فمن احتاج الإنذار يقرؤه من الحمولة، ولا يُعيد حسابه برقمٍ مكتوبٍ هنا.
 */

/** The production brand. «التحاضير الحديثة» is the only permitted name. */
export const BRAND = 'التحاضير الحديثة';

/** Truncate a title to the SEO budget on a word boundary, appending an ellipsis. */
export function truncateTitle(title: string, max: number = TITLE_MAX): string {
  if (title.length <= max) return title;
  const cut = title.slice(0, max - 1);
  const lastSpace = cut.lastIndexOf(' ');
  return `${(lastSpace > max / 2 ? cut.slice(0, lastSpace) : cut).trimEnd()}…`;
}

/**
 * metadataBase source — env-driven, honest localhost fallback for dev builds.
 *
 * `??` لا يكفي: Dockerfile يُعرّف `ARG NEXT_PUBLIC_SITE_URL=""`، فيصل المتغيّر
 * سلسلةً فارغة لا غيابًا، وهي ليست null ولا undefined. النتيجة كانت
 * `new URL('')` ⇒ ERR_INVALID_URL يُسقط البناء كلّه عند جمع بيانات الصفحات.
 * (نفس صنف العطل الذي أفرغ قائمة الوسطاء الموثوقين في الخادم.)
 */
export function siteUrl(): URL {
  const raw = process.env.NEXT_PUBLIC_SITE_URL?.trim();
  return new URL(raw !== undefined && raw !== '' ? raw : 'http://localhost:3000');
}

/** Site origin without a trailing slash, or null when unconfigured. */
export function siteOrigin(): string | null {
  const raw = process.env.NEXT_PUBLIC_SITE_URL?.trim();
  return raw ? raw.replace(/\/+$/, '') : null;
}

/**
 * Absolute URL for a root-relative path.
 *
 * With no configured origin the path is returned unchanged rather than being
 * bolted onto a guessed hostname — an invented absolute URL is a fabricated fact.
 */
export function canonicalUrl(path: string): string {
  if (path.startsWith('http')) return path;
  const origin = siteOrigin();
  const clean = `/${path.replace(/^\/+/, '')}`;
  return origin ? `${origin}${clean}` : clean;
}

/* ─── SEO API response types (GET /api/v1/seo/products/{slug}) ─── */

export interface BreadcrumbItem {
  name: string;
  url: string;
}

export interface SeoMetadataDto {
  title: string;
  renderedTitle: string;
  titleLength: number;
  overEditorBudget: boolean;
  renderedTitleAlarm: boolean;
  description: string | null;
  descriptionSource: string | null;
  canonical: string;
  canonicalIsSelf: boolean;
  robots: string;
  indexability: { verdict: string; reason: string; rule: string };
  /** بدائل اللغة — يُصدرها الخادم فقط لصفحات موجودة ومنشورة وقابلة للفهرسة. */
  alternates?: { hreflang: string; href: string; isDefault: boolean }[];
  openGraph: {
    type: string;
    title: string;
    description: string | null;
    url: string;
    siteName: string;
    locale: string;
    image: string | null;
    imageSource: string | null;
  };
  twitter: { card: string; title: string; description: string | null; image: string | null };
}

/** The arrays the page renders — the same ones that built the graph. */
export interface SeoVisibleDto {
  breadcrumbs: BreadcrumbItem[];
  faqs: { question: string; answer: string }[];
  facts: Record<string, string | number | null>;
  author: { name: string; url: string | null; bio: string | null } | null;
  description: string | null;
  image: string | null;
}

/** A schema.org graph: exactly one @context and one @graph. */
export interface SchemaGraph {
  '@context': string;
  '@graph': Record<string, unknown>[];
}

export interface ProductSeoDto {
  metadata: SeoMetadataDto;
  visible: SeoVisibleDto;
  schema: SchemaGraph;
}
