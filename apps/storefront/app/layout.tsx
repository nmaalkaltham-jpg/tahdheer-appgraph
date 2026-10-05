import type { Metadata, Viewport } from 'next';
import { Tajawal } from 'next/font/google';
import { headers } from 'next/headers';
import '@tahdheer/design-system/tokens.css';
import './globals.css';
import { SiteFooter } from '../components/SiteFooter';
import { getAnnouncements, SiteHeader } from '../components/SiteHeader';
import { MobileBottomBar } from '../components/MobileBottomBar';
import { siteUrl } from '../lib/seo';
import { getNavigation } from '../lib/navigation';
import { BottomChrome } from '../components/chrome/BottomChrome';
import { FocusScroll } from '../components/chrome/FocusScroll';
import { BackToTop } from '../components/chrome/BackToTop';
import { getBrowseTree } from '../components/chrome/browse-data';
import { ConsentBanner } from '../components/privacy/consent';
import { PageViewEmitter } from '../components/privacy/page-view';
import { THEME_PAGE_ATTR, themePageKey } from '../lib/theme-page';
import { THEME_BOOT_SCRIPT } from '../lib/theme';

/**
 * Tajawal via next/font/google for the skeleton. PRODUCTION RULE (MIP §2.4,
 * Production Fixes #8): self-host exactly 2 weights (700, 900), WOFF2, Arabic
 * subset, font-display swap, preload primary, size-adjust fallback → CLS 0.
 * next/font already subsets + self-serves at build; the dedicated self-hosted
 * WOFF2 pipeline replaces this in the design-system phase.
 */
const tajawal = Tajawal({
  /*
   * الأوزان المستعملة فعلًا في أنماط المتجر، مقيسة لا مقدَّرة:
   *   900 ×145 · 800 ×116 · 700 ×84 · 600 ×10 · 400 ×2
   * كان المحمَّل 700 و900 فقط، فمئة وثمانية عشر إعلانًا — أبرزها الوزن 800
   * الذي يفرضه التصميم — تُصيَّر ببديل اصطناعي أو تقفز إلى 900. لا خطأ في
   * الطرفية ولا تحذير: الصفحة تبدو معقولة وهي لا تطابق التصميم.
   *
   * الوزن 600 غائب هنا عمدًا: «Tajawal» لا يملكه (تحقّقنا وزنًا وزنًا من
   * المزوّد)، وخوارزمية مطابقة CSS تختار 700 مكانه — وهو ما يفعله
   * FontCatalog::snapWeight على الخادم أيضًا.
   */
  weight: ['400', '500', '700', '800', '900'],
  subsets: ['arabic'],
  display: 'swap',
  variable: '--font-tajawal',
  fallback: ['SF Arabic', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
});

/**
 * `viewport-fit=cover` — الجذر الذي بدونه كلّ مناطق الأمان أصفار.
 *
 * ثلاثة عشر موضعًا في هذا المتجر يحجز مساحةً بـ`env(safe-area-inset-bottom)`
 * (الشريط اللاصق، شريط الشراء، السلّة، الدفع، تنبيه السلّة، شريط الحساب…).
 * وبلا هذا الإعلان يبقى `viewport-fit` على `auto`، فتُحلّ الدالّة إلى **صفر**
 * على كلّ جهازٍ ذي حزّ أو شريط منزل — فتقع تلك الأشرطة تحت الإيماءة.
 * أي أنّ الحجز مكتوبٌ منذ زمن ولا يعمل.
 *
 * والعرض والمقياس يُعادان هنا صراحةً: تصدير `viewport` يستبدل افتراض Next
 * بالكامل، فإسقاطهما يُخرج `width=device-width` من الصفحة.
 *
 * ولا `maximum-scale` ولا `user-scalable=no`: منعُ التكبير خرقٌ لمعيار
 * الوصول ‎1.4.4‎، ولا يُشترى به شيء.
 */
export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
};

export const metadata: Metadata = {
  metadataBase: siteUrl(),
  title: {
    default: 'التحاضير الحديثة',
    template: '%s | التحاضير الحديثة',
  },
  description: 'متجر التحاضير الحديثة — تحاضير وعروض واختبارات رقمية جاهزة للتحميل الفوري.',
  /*
   * OpenGraph/Twitter في الجذر لا في صفحة المنتج وحدها.
   *
   * كانت صفحة المنتج هي الوحيدة التي تُصدّرهما، فمشاركة الرئيسة أو المتجر أو
   * أيّ صفحة قائمة تُنتج بطاقةً بلا عنوان ولا وصف. وترثها كلّ الصفحات هنا،
   * وتتجاوزها صفحة المنتج بما يحسبه `MetadataService` كما تفعل الآن.
   *
   * ولا `images`: لا صورة مشاركة مؤكَّدة في `public/`، وإعلان صورةٍ لا وجود
   * لها أسوأ من إغفالها — وهي العلّة نفسها التي تُصلَح في og:image الخادميّ.
   */
  /*
   * ولا `title` ولا `description` هنا — عمدًا.
   *
   * Next يملأ `og:title` و`og:description` من عنوان الصفحة ووصفها **فقط حين
   * يغيبان** عن هذه الكتلة (`inheritFromMetadata`). فكتابتهما هنا تُجمّد
   * بطاقة المشاركة على نصّ الجذر في كلّ مسارٍ لا يُصدّر كتلته الخاصّة — أي
   * كلّ المتجر عدا صفحة المنتج. قِيس: ‎/shop‎ عنوانه «المتجر | التحاضير
   * الحديثة» و`og:title` كان «التحاضير الحديثة». الهويّة وحدها ترث؛ والنصّ
   * يأتي من الصفحة.
   */
  openGraph: {
    type: 'website',
    siteName: 'التحاضير الحديثة',
    locale: 'ar_SA',
  },
  twitter: {
    card: 'summary',
  },
};


export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const requestHeaders = await headers();

  /*
   * الترويسة والتذييل يُجلبان معًا من المصدر الواحد `lib/navigation`.
   *
   * كان التذييل عشرين رابطًا مكتوبةً في `SiteFooter`، فنشرُ موقع «التذييل»
   * في اللوحة لا يغيّر حرفًا عند زائر. وكانت الترويسة تُسطَّح إلى مستوًى
   * واحد. المصدر الآن واحد، والشجرة كاملة بأبنائها.
   */
  /*
   * ثلاثة أماكن لا اثنان: `mobile` مكانٌ منشور في السجلّ (`NAV_LOCATIONS`)
   * وله صفٌّ في القاعدة، ولم يكن يستهلكه أحد — فلوحة الجوال كانت تُصيَّر من
   * شجرة الترويسة، وكلّ نشرٍ لقائمة الجوال في اللوحة لا يغيّر حرفًا عند زائر.
   */
  const [headerNav, mobileNav, footerNav, announcements, browse] = await Promise.all([
    getNavigation('header'),
    getNavigation('mobile'),
    getNavigation('footer'),
    getAnnouncements(),
    getBrowseTree(),
  ]);

  /*
   * نطاق الصفحة لتجاوزات الثيم. المسار يصل في ترويسةٍ يكتبها الوسيط أصلًا
   * (`x-tahdheer-path`) — التخطيط في App Router لا يعرف مسار ابنه.
   *
   * وغيابُ المفتاح غيابُ السمة: صفحةٌ لا قاعدة لها تبقى بلا نطاق بدل أن
   * تُنسب إلى نطاقٍ خاطئ يسري عليه تجاوزٌ لم يُقصد لها.
   */
  const pageKey = themePageKey(requestHeaders.get('x-tahdheer-path') ?? '');
  const pageScope = pageKey === null ? {} : { [THEME_PAGE_ATTR]: pageKey };

  return (
    /* ‎data-theme‎ يكتبه سكربت ما قبل الرسم أدناه — فالخادم والعميل يختلفان عليه عمدًا. */
    <html lang="ar" dir="rtl" className={tajawal.variable} suppressHydrationWarning>
      <head>
        {/*
          المظهر قبل الرسم (lib/theme.ts): الاختيار المحفوظ أو تفضيل النظام يُكتب
          ‎data-theme‎ على الجذر قبل أوّل إطار، فلا ومضة فاتحة لمن اختار الداكن.
        */}
        <script
          /* sanitized: ثابتٌ مبنيّ من ثوابت lib/theme.ts — لا مدخل مستخدم فيه */
          dangerouslySetInnerHTML={{ __html: THEME_BOOT_SCRIPT }}
        />
        {/*
          رموز التيبوغرافيا المحرَّرة من اللوحة. ورقة مستقلّة عمدًا: صفحات هذا
          المتجر تُصيَّر مسبقًا، فقيمة تُقرأ هنا تُخبز في HTML ولا يحرّكها حفظٌ
          في اللوحة. هي حاجبة للتصيير فلا ومضة، وتُخزَّن ب ETag فلا تكلفة تكرار.
          غيابها لا يكسر شيئًا: tokens.css يحمل الافتراضات وحده.
        */}
        {/*
          لا `preconnect` إلى نطاقات Google: `next/font/google` يجزّئ Tajawal
          ويستضيفه ذاتيًّا وقت البناء (لا مرجع لـ`gstatic` في المُخرَج كلّه)،
          فكان السطران يفتحان مصافحتَي DNS/TLS إلى أصلٍ لا تلمسه الصفحة أبدًا
          — في المسار الحرج لكلّ صفحة، وعلى كلّ زائر.
        */}
        {/*
          مسارٌ ديناميكيّ (`app/theme.css/route.ts`) لا ملفٌّ في الحزمة:
          `import` كان سيخبز قيم اللوحة وقت البناء ويُبطل تحريرها.
        */}
        {/* eslint-disable-next-line @next/next/no-css-tags -- /theme.css مسارٌ ديناميكيّ تحرّره اللوحة؛ الاستيراد يخبزه وقت البناء */}
        <link rel="stylesheet" href="/theme.css" />
      </head>
      <body {...pageScope}>
        {/* شريط الإعلانات من GET /api/v1/home/announcements — ولا شريط بلا بيانات.
            والقائمة من الخادم وحده: لا روابط مكتوبة في المكوّن. */}
        {/* رابط التخطّي — أوّل محطّة Tab، مخفيٌّ حتّى يُركَّز عليه (WCAG 2.4.1). وهدفه
            نفسه وجهةُ التركيز بعد «العودة إلى الأعلى». */}
        <a className="skip-link" href="#main">
          تخطَّ إلى المحتوى
        </a>
        <SiteHeader announcements={announcements} nav={headerNav} mobileNav={mobileNav} browse={browse} />
        <main id="main" tabIndex={-1}>
          {children}
        </main>
        {/* تذييلٌ واحد للموقع كلّه — Storefront Home.dc.html. */}
        <SiteFooter nav={footerNav} />
        {/* الشريط السفليّ اللاصق للجوّال — يختفي على /products/* (لها شريط شرائها). */}
        <MobileBottomBar />
        {/*
          القياس والموافقة خارج الفرع «العاري» عمدًا: سطح معاينة العنصر يُصيَّر
          داخل إطارٍ في لوحة الإدارة عشراتِ المرّات في الشاشة الواحدة — فقياسه
          يعدّ المشرف زوّارًا، وشريطُ الموافقة يظهر في صورةٍ مصغّرة بارتفاع
          ٩٨ بكسل. الفرع العاري يعود قبل هذا السطر أصلًا.
        */}
        <PageViewEmitter />
        <ConsentBanner />
        <BottomChrome />
        <FocusScroll />
        <BackToTop />
        {/*
          * فاحص الواجهة: أداة تطوير لا ميزة منتَج، وهذا متجرٌ عامّ. الشرط
          * يُقيَّم في الخادم — لا `NEXT_PUBLIC_` لأنّه يُخبز في حزمة المتصفّح
          * فيقرؤه كلّ زائر — فلا يُصيَّر المِعلاق في الإنتاج أصلًا، ولا تُشحن
          * لوحته ولا خريطة المستودع إلى مشترٍ. والمِعلاق وقارئ الخريطة
          * يحرسان الإنتاج كذلك، فالحارس هنا أوّلُ ثلاثة لا وحيدُها.
          */}
      </body>
    </html>
  );
}
