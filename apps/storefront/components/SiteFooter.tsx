/**
 * SiteFooter — port of the TahHomeBody.dc.html footer (the canonical
 * «التحاضير الحديثة» composition: brand block + three link columns
 * التسوّق / روابط مهمة / السياسات + socials + payment marks + copyright).
 *
 * HONESTY DOCTRINE:
 *  · Every link comes from the one route map — zero href="#".
 *  · Social accounts are a PROP: we never invent a profile URL, so with none
 *    supplied the socials row simply does not render.
 *  · Payment marks: mada is its official logo from `PAYMENT_ART` (light/dark);
 *    marks without supplied artwork (VISA / MC …) stay LABELLED text chips of
 *    the same height — a trademark is never hand-drawn.
 *  · The year is evaluated once at build time.
 */
import Image from 'next/image';
import { apiGetResource } from '../lib/api';
import { routes } from '../lib/routes';
import type { NavigationTree } from '../lib/navigation';
import styles from './SiteFooter.module.css';
import { PrivacySettingsLink } from './privacy/consent';
import { PaymentMark } from './chrome/PaymentMark';

/**
 * جسر المتجر إلى لوحة التشغيل. يُقرأ وقت الطلب لا وقت البناء، وغيابه يخفي
 * الرابط — فالمتجر لا يعلن عنوان لوحة لم يُصرَّح به. الرابط يَعبر فقط؛ اللوحة
 * نفسها هي من يتحقّق من الجلسة، فلا حالة دخول مُدَّعاة هنا.
 */
function adminUrl(): string | null {
  const raw = process.env.ADMIN_URL?.trim();
  if (!raw) return null;
  try {
    const url = new URL(raw);
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.origin : null;
  } catch {
    return null;
  }
}

/*
 * وسائل الدفع المقبولة فعلًا — لا صفّ شعاراتٍ من لوح التصميم.
 *
 * كانت «Apple Pay» و«STC Pay» معروضتين هنا في تذييل كلّ صفحة، بينما
 * صفحة `/payment-methods` في المتجر نفسه تصنّفهما **غير متاحتين** وتشرح
 * السبب: الأولى «غير مؤكَّد تفعيلها في حساب البوابة»، والثانية «لم تُربط
 * بالمتجر بعد». فالمتجر كان يَعِد في تذييله بما ينفيه في صفحته.
 *
 * وشعارُ وسيلةِ دفعٍ ليس زينة: المشتري يقرؤه عقدًا قبل أن يُدخل بطاقته.
 */
const PAYMENT_MARKS: readonly string[] = ['mada', 'VISA', 'MC'];

/** GET /api/v1/checkout/methods — ما يقبله المتجر فعلًا، كما يقوله الخادم. */
export interface CheckoutMethods {
  marks: readonly string[];
  applePay: boolean;
}

/*
 * تُقرأ مرّةً لكلّ طلب (التذييل والشريط السفليّ معًا). الغياب (404 قبل نشر
 * المسار، أو واجهةٌ معطّلة) يقع على الثابت أعلاه ولا يُعلن Apple Pay:
 * وعدٌ بوسيلة دفعٍ لم يؤكّدها الخادم أسوأ من إغفالها.
 */
export async function getCheckoutMethods(): Promise<CheckoutMethods> {
  const { data } = await apiGetResource<Partial<CheckoutMethods>>('/api/v1/checkout/methods');
  const marks = Array.isArray(data?.marks) ? data.marks.filter((m): m is string => typeof m === 'string' && m !== '') : [];
  return { marks: marks.length > 0 ? marks : PAYMENT_MARKS, applePay: data?.applePay === true };
}

/** GET /api/v1/home/identity — الشبكات الاجتماعيّة ووثيقة العمل الحرّ، كما تحرّرهما اللوحة. */
export interface StoreIdentity {
  document: { label: string; number: string } | null;
  socials: readonly FooterSocial[];
}

/** رموز البلاطات كما يرسمها Storefront Home.dc.html (X · IG · WA). */
const SOCIAL_GLYPHS: Record<string, string> = { x: 'X', instagram: 'IG', whatsapp: 'WA', tiktok: 'TT', snapchat: 'SC' };

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null;
const text = (v: unknown): string => (typeof v === 'string' ? v.trim() : '');

/*
 * 404 (قبل نشر المسار) أو ردٌّ مشوَّه ⇒ لا شبكات ولا وثيقة: رقمُ تسجيلٍ
 * مختلَق أسوأُ بياناتٍ تخترعها صفحة، ورابطُ حسابٍ لا يُعرف مثله.
 */
export async function getIdentity(): Promise<StoreIdentity> {
  const { data } = await apiGetResource<unknown>('/api/v1/home/identity');
  if (!isRecord(data)) return { document: null, socials: [] };
  const doc = isRecord(data.document) && text(data.document.label) !== '' && text(data.document.number) !== ''
    ? { label: text(data.document.label), number: text(data.document.number) }
    : null;
  const socials = (Array.isArray(data.socials) ? data.socials : []).flatMap((raw): FooterSocial[] => {
    if (!isRecord(raw)) return [];
    const glyph = SOCIAL_GLYPHS[text(raw.network)];
    const href = text(raw.href);
    return glyph !== undefined && text(raw.label) !== '' && /^https:\/\//.test(href) ? [{ label: text(raw.label), href, glyph }] : [];
  });
  return { document: doc, socials };
}

/** أيقونة الوثيقة — Storefront Product.dc.html (15px سطح المكتب · 14px الجوّال). */
function DocIcon({ size }: { size: number }) {
  return (
    <svg aria-hidden="true" className={styles.docIcon} width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M8 3h6l4 4v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
      <path d="M14 3v4h4" />
      <path d="M9.5 13.5l1.6 1.6 3.4-3.4" />
    </svg>
  );
}

/** «{label} رقم {number}» — الرقم بأرقامٍ غربيّة وباتّجاه ltr كما في التصميم. */
function DocumentLine({ document, size }: { document: NonNullable<StoreIdentity['document']>; size: number }) {
  return (
    <span className={styles.docLine}>
      <DocIcon size={size} />
      <span dir="ltr" className={styles.docText}>
        {document.label} رقم {document.number}
      </span>
    </span>
  );
}

const BUILD_YEAR = new Date().getFullYear();

export interface FooterSocial {
  /** Accessible name, e.g. «إكس» — rendered as the link's label. */
  label: string;
  /** The real profile URL. */
  href: string;
  /** Short glyph or initials shown inside the 38px tile. */
  glyph: string;
}

export interface SiteFooterProps {
  /** Override for the social profiles; absent ⇒ GET /api/v1/home/identity. */
  socials?: readonly FooterSocial[];
  /** Override for the payment marks; absent ⇒ GET /api/v1/checkout/methods. */
  paymentMarks?: readonly string[];
  /**
   * شجرة التذييل المنشورة — الجذور أعمدة وأبناؤها روابط.
   *
   * كانت عشرين رابطًا مكتوبةً هنا، فموقع «التذييل» في اللوحة يُنشر بنجاح ولا
   * يغيّر حرفًا عند زائر. لا قائمةَ متجرٍ مكتوبةٌ في الشيفرة بعد اليوم.
   */
  nav: NavigationTree;
}

/*
 * تذييلٌ واحد للموقع كلّه — Storefront Home.dc.html. الصفّ الواحد الذي
 * يرسمه Storefront Product.dc.html لا يُبنى: تذييلان لموقعٍ واحد تذييلٌ زائد.
 *
 * The mobile footer's links (Storefront Home Mobile.dc.html) — the design's
 * own list. Every entry is a real route of this storefront. The
 * privacy-settings control is appended because reopening consent must stay
 * reachable from every page.
 */
const MOBILE_LINKS: readonly { label: string; href: string }[] = [
  { label: 'كيف يعمل المتجر', href: routes.howItWorks },
  { label: 'اطلب ملفًا', href: routes.request },
  { label: 'المساعدة', href: routes.help },
  { label: 'الاسترجاع', href: routes.policy('refund') },
  { label: 'الترخيص', href: routes.policy('license') },
  { label: 'مصادر الرسوم', href: routes.credits },
];

/* سطر الوثيقة يُصيَّر فقط حين تحمله الهويّة من الخادم — لا رقم مخترَع. */
function MobileFooter({ document }: { document: StoreIdentity['document'] }) {
  return (
    <div className={styles.mobile}>
      <nav className={styles.mobileLinks} aria-label="روابط المتجر">
        {MOBILE_LINKS.map((link) => (
          <a key={link.href} href={link.href}>
            {link.label}
          </a>
        ))}
        <PrivacySettingsLink className={styles.mobileButton} />
      </nav>
      <span className={styles.mobileCopy}>
        <span dir="ltr">© {BUILD_YEAR} التحاضير الحديثة</span>
        {document ? <DocumentLine document={document} size={14} /> : null}
      </span>
    </div>
  );
}

/** عنوان العمود الذي يحمل وعد الخصوصيّة المنصوص عليه في سياسة الكوكيز. */
const POLICY_COLUMN = 'السياسات';

/*
 * إسناد الرسوم شرطُ ترخيصها (Freepik) لا محتوى يحرّره مشرف — فيُلحق بعمود
 * الخصوصية كرابط إعداداتها، ويبقى ولو خلا التذييل من الأعمدة.
 */
const CREDITS_LABEL = 'مصادر الرسوم والشعارات';

/** فوق هذا العدد من الروابط ينقسم العمود عمودين. */
const LONG_COLUMN = 7;

export async function SiteFooter({ socials, paymentMarks, nav }: SiteFooterProps) {
  const admin = adminUrl();
  const columns = nav.items;
  const [methods, identity] = await Promise.all([
    paymentMarks === undefined ? getCheckoutMethods() : null,
    getIdentity(),
  ]);
  const marks = paymentMarks ?? methods?.marks ?? [];
  const socialLinks = socials ?? identity.socials;

  /*
   * وعد «رابط إعدادات الخصوصية أسفل كل صفحة» التزامٌ منشور، فلا يجوز أن
   * يتعلّق ببقاء عمودٍ اسمه «السياسات» في قائمةٍ يحرّرها مشرف. يُوضع في
   * عموده إن وُجد، وفي آخر عمودٍ إن لم يوجد، ووحده حين لا عمود أصلًا.
   */
  const policyIndex = columns.findIndex((col) => col.label === POLICY_COLUMN);
  const privacyHome = policyIndex === -1 ? columns.length - 1 : policyIndex;

  return (
    <footer className={styles.footer} data-theme-component="site-footer">
      <MobileFooter document={identity.document} />
      <div className={styles.full}>
      <div className={styles.inner}>
        <div className={styles.columns}>
          <div className={styles.brand}>
            <a className={styles.brandLink} href={routes.home}>
              {/*
                الشعار الكامل — العلامة والكلمة معًا من ملفّ الهوية. موضعه هنا
                لا في الترويسة: التذييل سطحٌ فاتح (--bg) تظهر عليه تدرّجات
                الشعار بكامل أمانتها، بينما ترويسة --ink الداكنة تبتلع طرفه
                الكحليّ. والنصّ البديل يحمل اسم المتجر لأنّ الصورة هي الاسم.
              */}
              <span className={styles.brandRow}>
                <Image
                  className={styles.brandLogo}
                  src="/brand/logo-full.png"
                  alt="التحاضير الحديثة"
                  width={512}
                  height={289}
                />
              </span>
            </a>
            <p className={styles.about}>
              متجر عربي متخصّص في الملفات والقوالب التعليمية الجاهزة والقابلة للتعديل، لكل المراحل الدراسية.
            </p>
            {socialLinks.length > 0 ? (
              <ul className={styles.socials} role="list">
                {socialLinks.map((social) => (
                  <li key={social.href}>
                    <a
                      className={styles.social}
                      href={social.href}
                      aria-label={social.label}
                      rel="noopener noreferrer"
                      target="_blank"
                    >
                      <span aria-hidden="true">{social.glyph}</span>
                    </a>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>

          {columns.map((col, index) => (
            /*
             * عمودٌ طويل (أكثر من سبعة روابط) يأخذ مسارين وتنقسم قائمته عمودين:
             * كان «التسوّق» بتسعة روابط ينزل ثلاثة أسطر تحت جيرانه ويترك فراغًا
             * تحت العلامة وتحت كلّ عمودٍ آخر.
             */
            <nav
              key={col.id}
              aria-label={col.label}
              className={col.children.length + (index === privacyHome ? 2 : 0) > LONG_COLUMN ? styles.colLong : undefined}
            >
              <h2 className={styles.colTitle}>{col.label}</h2>
              <ul className={styles.colList} role="list">
                {col.children.map((link) => (
                  <li key={link.id}>
                    <a
                      className={styles.colLink}
                      href={link.href}
                      aria-label={link.aria !== '' ? link.aria : undefined}
                      {...(link.target === '_blank'
                        ? { target: '_blank', rel: link.rel !== '' ? link.rel : 'noopener noreferrer' }
                        : link.rel !== ''
                          ? { rel: link.rel }
                          : {})}
                    >
                      {link.label}
                    </a>
                  </li>
                ))}
                {index === privacyHome ? (
                  <>
                    <li>
                      <PrivacySettingsLink className={styles.colButton} />
                    </li>
                    <li>
                      <a className={styles.colLink} href={routes.credits}>
                        {CREDITS_LABEL}
                      </a>
                    </li>
                  </>
                ) : null}
              </ul>
            </nav>
          ))}

          {/* لا عمود منشور بعد: الوعد يبقى مُوفًّى ولو خلا التذييل. */}
          {columns.length === 0 ? (
            <nav aria-label="الخصوصية">
              <ul className={styles.colList} role="list">
                <li>
                  <PrivacySettingsLink className={styles.colButton} />
                </li>
                <li>
                  <a className={styles.colLink} href={routes.credits}>
                    {CREDITS_LABEL}
                  </a>
                </li>
              </ul>
            </nav>
          ) : null}
        </div>

      </div>
      <div className={styles.bottomBar}>
        <div className={styles.bottom}>
          <ul className={styles.payments} role="list" aria-label="وسائل الدفع المقبولة">
            {marks.map((mark) => (
              <li key={mark} className={styles.paymentMark} data-mark={mark}>
                <PaymentMark mark={mark} />
              </li>
            ))}
          </ul>
          <p className={styles.copy}>
            <span>
              © <bdi dir="ltr">{BUILD_YEAR}</bdi> التحاضير الحديثة — جميع الحقوق محفوظة
              {admin !== null && (
                <>
                  {' · '}
                  <a className={styles.adminLink} href={admin} rel="nofollow noopener">
                    لوحة التشغيل
                  </a>
                </>
              )}
            </span>
            {identity.document ? <DocumentLine document={identity.document} size={15} /> : null}
          </p>
        </div>
      </div>
      </div>
    </footer>
  );
}
