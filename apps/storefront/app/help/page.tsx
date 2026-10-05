import type { Metadata } from "next";
import Link from "next/link";
import { qty, type PluralForms } from "@tahdheer/design-system/format";
import { ConnectionError } from "../../components/search/ConnectionError";
import { apiGet } from "../../lib/api";
import { routes } from "../../lib/routes";
import { FaqSearch, type FaqItem } from "./FaqSearch";
import styles from "./page.module.css";

export const metadata: Metadata = {
  title: "مركز المساعدة",
  description:
    "إجابات الأسئلة الأكثر شيوعًا عن الطلب والدفع والتحميل، وقنوات التواصل مع الدعم.",
  alternates: { canonical: routes.help },
  robots: { index: true, follow: true },
};

const QUESTION_FORMS: PluralForms = {
  zero: "لا أسئلة",
  one: "سؤال واحد",
  two: "سؤالان",
  few: "أسئلة",
  many: "سؤالًا",
};

/* مسالك قائمة فعلًا في المتجر — لا أقسام مقالاتٍ مخترعة. */
const QUICK_LINKS: readonly { title: string; hint: string; href: string }[] = [
  { title: "افتح طلبًا سابقًا", hint: "برابط الطلب أو رمزه", href: routes.login },
  { title: "كيف يعمل المتجر", hint: "من الاختيار إلى التحميل", href: routes.howItWorks },
  { title: "طرق الدفع", hint: "الوسائل المتاحة وأمانها", href: routes.paymentMethods },
  { title: "الاستبدال والاسترجاع", hint: "متى وكيف", href: routes.policy("refund") },
];

/*
 * لغة التصميم: رأسٌ بسيط (مسار + عنوان + وصف) بلا شريطٍ داكن، ثمّ شريط
 * الأسئلة على سطحٍ بارد بتخطيط «قبل الشراء» من صفحة المنتج، ثمّ شريط
 * الدعوة المتدرّج في آخر الصفحة. النسخ والحالات الفارغة كما كانت.
 */
export default async function HelpPage() {
  const { data, status } = await apiGet<FaqItem[]>("/api/v1/home/faq");
  const faq = data ?? [];
  /*
   * ‎status !== 200‎ يعني أنّ الخادم لم يُجب أصلًا، وهو غير «لا أسئلة» —
   * فيُعرض خطأ الاتصال لا حالة فارغة تُوهم بأنّ القائمة خاوية.
   */
  const unreachable = status !== 200;
  const whatsapp = process.env.NEXT_PUBLIC_WHATSAPP_NUMBER;

  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <nav aria-label="مسار التنقّل">
          <ol className={styles.crumbs}>
            <li>
              <Link href={routes.home}>الرئيسية</Link>
            </li>
            <li aria-current="page">مركز المساعدة</li>
          </ol>
        </nav>

        <header className={styles.head}>
          <h1 className={styles.h1}>كيف نساعدك؟</h1>
          <p className={styles.lede}>
            أكثر ما يُسأل عنه مُجاب هنا: الطلب والدفع وروابط التحميل. وإن لم تجد
            إجابتك فالتواصل المباشر في آخر الصفحة.
          </p>
        </header>

        {/*
          كان هنا صندوقٌ متقطّع كبير «لم تُنشر أقسام مساعدة بعد» يتصدّر الصفحة.
          لا فهرس مقالات في الخادم؛ فبدل الإعلان عن الغياب تُعرض مسالك حقيقيّة
          قائمة فعلًا، ثمّ الأسئلة الشائعة مباشرة.
        */}
        <nav className={styles.quick} aria-label="روابط سريعة">
          <ul className={styles.quickList}>
            {QUICK_LINKS.map((link) => (
              <li key={link.href}>
                <Link className={styles.quickLink} href={link.href}>
                  <span className={styles.quickTitle}>{link.title}</span>
                  <span className={styles.quickHint}>{link.hint}</span>
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      </div>

      <section className={styles.faqBand} aria-labelledby="help-faq-title">
        <div className={styles.faqBandInner}>
          <div>
            <p className={styles.eyebrow}>الأسئلة الشائعة</p>
            <h2 className={styles.faqTitle} id="help-faq-title">
              الأسئلة الأكثر شيوعًا
              {faq.length > 0 ? (
                <span className={styles.faqCount}>
                  {" "}
                  ({qty(faq.length, QUESTION_FORMS)})
                </span>
              ) : null}
            </h2>
            <p className={styles.faqNote}>
              لم تجد إجابتك؟ <Link href={routes.contact}>راسلنا</Link> وسنردّ
              عليك.
            </p>
          </div>

          <div className={styles.faqMain}>
            {faq.length > 0 ? (
              <FaqSearch items={faq} />
            ) : unreachable ? (
              <ConnectionError href={routes.help} />
            ) : (
              <div className={styles.empty}>
                <p className={styles.emptyTitle}>
                  لا توجد أسئلة شائعة منشورة بعد
                </p>
                <p className={styles.emptyHint}>
                  لم يُضف أي سؤال إلى قائمة الأسئلة الشائعة حتى الآن. راسلنا
                  مباشرة وسنجيبك.
                </p>
              </div>
            )}
          </div>
        </div>
      </section>

      <div className={styles.wrap}>
        <section className={styles.cta} aria-labelledby="help-contact-title">
          <div className={styles.ctaCopy}>
            <h2 className={styles.ctaTitle} id="help-contact-title">
              لم تجد إجابتك؟
            </h2>
            <p className={styles.ctaLede}>راسلنا وسنردّ عليك.</p>
          </div>
          <div className={styles.ctaBtns}>
            {whatsapp ? (
              <a
                className={styles.ctaPrimary}
                href={`https://wa.me/${whatsapp}`}
                rel="noopener noreferrer"
                target="_blank"
              >
                تحدث معنا على واتساب
              </a>
            ) : (
              <Link className={styles.ctaPrimary} href={routes.contact}>
                تواصل معنا
              </Link>
            )}
            <Link className={styles.ctaGhost} href={routes.howItWorks}>
              كيف نعمل
            </Link>
          </div>
        </section>
      </div>
    </div>
  );
}
