/**
 * تواصل معنا — contact page.
 *
 * Design: the `is.contact` screen of «Tahdheer - Content Hub» (channel rows
 * with a 38px icon tile + the «أرسل رسالة» form), with the channel identities
 * of «Tahdheer - Contact Hub» — whose own binding note is quoted here because
 * it is the rule this page implements: «لا SDK خارجي — لا اتصال بأي منصة قبل
 * نقرة المستخدم». Every channel below is a plain link; nothing is loaded from
 * WhatsApp or any other platform until the reader clicks.
 * Declared SEO intent for this screen: «index · NAP متسق».
 *
 * HONESTY DOCTRINE:
 *  · A channel renders ONLY when it is really configured —
 *    NEXT_PUBLIC_WHATSAPP_NUMBER for wa.me, NEXT_PUBLIC_SUPPORT_EMAIL for
 *    mailto. With neither set the page says so instead of showing dead rows.
 *  · The artboard's «المقر» row (a street address) and its «ساعات الدعم» table
 *    are absent: no source configures them, and an invented address or an
 *    invented response window is exactly the kind of NAP data that must never
 *    be guessed.
 *  · The message form has NO endpoint behind it (there is no contact/messages
 *    route in the API). It is rendered DISABLED with the reason stated in
 *    text, never as a control that swallows a message and fakes a success
 *    toast the way the artboard's prototype does.
 */
import type { Metadata } from "next";
import Link from "next/link";
import { routes } from "../../lib/routes";
import styles from "./page.module.css";

export const metadata: Metadata = {
  title: "تواصل معنا",
  description: "قنوات التواصل المتاحة مع خدمة عملاء «التحاضير الحديثة».",
  alternates: { canonical: routes.contact },
  robots: { index: true, follow: true },
};

/** #c2-chat — copied verbatim from the «Tahdheer - Content Hub» sprite. */
function ChatIcon() {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.9"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M4 6h16v11H9l-5 4z" />
      <path d="M8 10h8M8 13h5" />
    </svg>
  );
}

/** #c2-mail — copied verbatim from the «Tahdheer - Content Hub» sprite. */
function MailIcon() {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.9"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="M3 7l9 6 9-6" />
    </svg>
  );
}

export default function ContactPage() {
  const whatsapp = process.env.NEXT_PUBLIC_WHATSAPP_NUMBER;
  const email = process.env.NEXT_PUBLIC_SUPPORT_EMAIL;
  const hasChannel = Boolean(whatsapp || email);

  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <nav aria-label="مسار التنقّل">
          <ol className={styles.crumbs}>
            <li>
              <Link href={routes.home}>الرئيسية</Link>
            </li>
            <li aria-current="page">تواصل معنا</li>
          </ol>
        </nav>
        <header className={styles.head}>
          <p className={styles.eyebrow}>الدعم</p>
          <h1 className={styles.h1}>تواصل معنا</h1>
        </header>
        <div className={styles.grid}>
          <section
            className={styles.channels}
            aria-labelledby="contact-channels"
          >
            <h2 className={styles.srOnly} id="contact-channels">
              قنوات التواصل
            </h2>

            {whatsapp ? (
              <a
                className={styles.channel}
                href={`https://wa.me/${whatsapp}`}
                rel="noopener noreferrer"
                target="_blank"
              >
                <span className={styles.channelIcon} aria-hidden="true">
                  <ChatIcon />
                </span>
                <span className={styles.channelText}>
                  <b className={styles.channelName}>واتساب</b>
                  <span className={styles.channelHint}>
                    راسلنا مباشرة على <bdi dir="ltr">{whatsapp}</bdi>
                  </span>
                </span>
              </a>
            ) : null}

            {email ? (
              <a className={styles.channel} href={`mailto:${email}`}>
                <span className={styles.channelIcon} aria-hidden="true">
                  <MailIcon />
                </span>
                <span className={styles.channelText}>
                  <b className={styles.channelName}>البريد الإلكتروني</b>
                  <span className={styles.channelHint}>
                    <bdi dir="ltr">{email}</bdi>
                  </span>
                </span>
              </a>
            ) : null}

            {hasChannel ? null : (
              <div className={styles.empty}>
                <h3 className={styles.emptyTitle}>
                  لا توجد قناة تواصل مُفعّلة بعد
                </h3>
                <p className={styles.emptyHint}>
                  لم يُضبط رقم واتساب ولا بريد دعم لهذا المتجر، ولن نعرض قناة لا
                  تصل إلى أحد. إن كان لديك طلب قائم فصفحة الطلب نفسها تعرض حالة
                  كل ملف فيه.
                </p>
                <Link className={styles.emptyAction} href={routes.help}>
                  الأسئلة الشائعة
                </Link>
              </div>
            )}
          </section>

          <section
            className={styles.formCard}
            aria-labelledby="contact-form-title"
          >
            <h2 className={styles.formTitle} id="contact-form-title">
              المراسلة
            </h2>

            {/*
              كان هنا نموذجٌ كامل معطّل (حقول وزرّ لا وجهة لها). لا خدمة استقبال
              رسائل في الخادم؛ فيُقال ذلك مرّةً بوضوح، ويُعرض بدله مسلكان يعملان
              فعلًا: طلب ملفٍّ (يُسجَّل في الخادم) والأسئلة الشائعة.
            */}
            <p className={styles.formReason}>
              نموذج المراسلة غير متاح بعد — لا نعرض حقولًا تبتلع رسالتك بلا وجهة.
              {hasChannel ? " استخدم القنوات المتاحة بجانب هذا." : ""}
            </p>
            <div className={styles.formActions}>
              <Link className={styles.formAction} href={routes.request}>
                اطلب ملفًّا غير متوفّر
              </Link>
              {/* بلا قناة، بطاقة الغياب أعلاه تحمل رابط الأسئلة — لا تكرار. */}
              {hasChannel ? (
                <Link className={styles.formActionQuiet} href={routes.help}>
                  الأسئلة الشائعة
                </Link>
              ) : null}
            </div>
          </section>
        </div>
      </div>
    </div>
  );
}
