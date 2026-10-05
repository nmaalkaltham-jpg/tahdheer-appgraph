/**
 * كتل الهيرو الأربع من «Storefront Redesign.dc.html» (1a · 1b · 1c · 2a) —
 * حين يختار المالك واحدةً منها في اللوحة بدل الشرائح الافتراضيّة
 * (`design.tsx`)، تُصيَّر هنا. القيم القديمة (سينمائيّ/اكتشاف/حكاية) تقع
 * على الشرائح ولا تصل إلى هذا الملفّ أصلًا (`hero-config.ts`).
 *
 * الحمولة نفسها التي تقرؤها الشرائح: `home.hero` للنصّ، وأوّل الأكثر مبيعًا
 * لبطاقة المعاينة (نداءٌ مطابق يُدمج مع نداء قسم «الأكثر مبيعًا»)،
 * و`home.reviews` للإحصاءات — ولا شيء يُخترع.
 */
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { apiGet, apiGetCollection } from '../../lib/api';
import { routes } from '../../lib/routes';
import { Failed, failed } from './Failed';
import { heroPresentation } from './hero-config';
import { HeroBold, HeroBrand, HeroInstitutional, HeroLuxe, type HeroCopy, type HeroStats } from './heroes';

interface HeroDto {
  title: string;
  title_accent?: string;
  subtitle?: string;
  eyebrow?: string;
  cta_label?: string;
  cta_url?: string;
  cta_secondary_label?: string;
  cta_secondary_url?: string;
  /** شرائح الثقة تحت الدعوتين — غيابها يعني لا شرائح، لا ادّعاءاتٍ افتراضيّة. */
  trust?: string[];
  variant?: string;
}

interface ReviewsDto {
  rating_avg?: number;
  rating_count?: number;
}

export async function LegacyHeroSection() {
  const [heroRes, bestRes, reviewsRes] = await Promise.all([
    apiGet<HeroDto>('/api/v1/home/hero'),
    apiGetCollection<ProductCardDto>('/api/v1/products?source=best&limit=4'),
    apiGet<ReviewsDto>('/api/v1/home/reviews'),
  ]);
  if (failed(heroRes.status) || !heroRes.data) return <Failed />;
  const data = heroRes.data;

  /*
   * الدعوتان الاحتياطيّتان تسميتا تنقّلٍ إلى مسارين حقيقيّين — وتُقرآن فقط
   * حين لا تنشر الإدارة نصّها ووجهتها.
   */
  const copy: HeroCopy = {
    title: data.title,
    titleAccent: data.title_accent,
    subtitle: data.subtitle,
    eyebrow: data.eyebrow,
    trust: data.trust,
    primary:
      data.cta_label && data.cta_url
        ? { label: data.cta_label, href: data.cta_url }
        : { label: 'تصفّح الملفات', href: routes.shop },
    secondary:
      data.cta_secondary_label && data.cta_secondary_url
        ? { label: data.cta_secondary_label, href: data.cta_secondary_url }
        : { label: 'حمّل عيّنة مجانية', href: routes.samples },
  };

  /* عطلُ الإحصاءات أو المنتج لا يُسقط الهيرو: يبقى النصّ والدعوتان. */
  const product = bestRes.status === 200 ? bestRes.data[0] : undefined;
  const stats: HeroStats =
    reviewsRes.status === 200 && reviewsRes.data
      ? { ratingAvg: reviewsRes.data.rating_avg, ratingCount: reviewsRes.data.rating_count }
      : {};

  switch (heroPresentation(data).variant) {
    case 'institutional':
      return <HeroInstitutional copy={copy} product={product} stats={stats} />;
    case 'bold':
      return <HeroBold copy={copy} />;
    case 'luxe':
      return <HeroLuxe copy={copy} product={product} stats={stats} />;
    default:
      return <HeroBrand copy={copy} product={product} />;
  }
}
