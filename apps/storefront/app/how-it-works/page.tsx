import type { Metadata } from "next";
import Link from "next/link";
import { routes } from "../../lib/routes";
import styles from "./page.module.css";

export const metadata: Metadata = {
  title: "كيف نعمل",
  description:
    "مسار الطلب كاملًا: كيف تختار الملف، وكيف تُتمّ الدفع، وأين تجد روابط التحميل وتعيد التحميل لاحقًا.",
  alternates: { canonical: routes.howItWorks },
  robots: { index: true, follow: true },
};

interface Step {
  title: string;
  body: string;
}

const STEPS: readonly Step[] = [
  {
    title: "اختر الملف",
    body: "تصفّح المتجر أو ابحث بالمادة والصف والفصل. صفحة كل ملف تعرض محتواه وصيغته وسعره المعلن قبل أي التزام منك.",
  },
  {
    title: "أضِفه إلى السلة وأكمل الطلب",
    body: "تراجع السلة، ثم تكتب في صفحة الدفع اسمك ورقم جوالك وبريدك الإلكتروني. البريد هو ما يُربط به طلبك، فاكتبه صحيحًا.",
  },
  {
    title: "تفتح صفحة طلبك",
    body: "بمجرد اكتمال الطلب تنتقل إلى صفحة الطلب الخاصة به. هذه الصفحة هي مرجعك الدائم: تعرض ما اشتريته وحالة كل ملف فيه.",
  },
  {
    title: "حمّل، وأعد التحميل من الصفحة نفسها",
    body: "لكل ملف زرّ تحميل في صفحة الطلب، ومنه تعيد التحميل لاحقًا. إن تعذّر التحميل فالصفحة تذكر السبب صراحةً — انتهاء المدة أو استنفاد مرات التحميل أو إيقاف الصلاحية.",
  },
] as const;

export default function HowItWorksPage() {
  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <nav aria-label="مسار التنقّل">
          <ol className={styles.crumbs}>
            <li>
              <Link href={routes.home}>الرئيسية</Link>
            </li>
            <li aria-current="page">كيف نعمل</li>
          </ol>
        </nav>

        <header className={styles.head}>
          <p className={styles.eyebrow}>الرحلة كاملة</p>
          <h1 className={styles.h1}>كيف نعمل</h1>
          <p className={styles.lede}>
            كل ما نبيعه ملفّات رقمية، فلا شحن ولا انتظار. هذه هي الرحلة كاملة من
            اختيار الملف إلى تحميله وإعادة تحميله، مكتوبة في مكان واحد حتى
            تعرفها قبل الشراء لا بعده.
          </p>
        </header>

        {/* role="list" keeps the semantics VoiceOver drops when list-style is none. */}
        <ol className={styles.steps} role="list">
          {STEPS.map((step, index) => (
            <li className={styles.step} key={step.title}>
              <span className={styles.stepNumber} aria-hidden="true">
                <bdi dir="ltr">{index + 1}</bdi>
              </span>
              <b className={styles.stepTitle}>{step.title}</b>
              <span className={styles.stepBody}>{step.body}</span>
            </li>
          ))}
        </ol>

        <section className={styles.cta} aria-labelledby="how-support">
          <div className={styles.ctaCopy}>
            <h2 className={styles.ctaTitle} id="how-support">
              تعثّر شيء في الطريق؟
            </h2>
            <p className={styles.ctaLede}>
              ابدأ بالأسئلة الشائعة — أكثر ما يُسأل عنه مُجاب هناك. وإن لم تجد
              إجابتك فراسلنا مباشرة.
            </p>
          </div>
          <div className={styles.ctaBtns}>
            <Link className={styles.ctaPrimary} href={routes.help}>
              الأسئلة الشائعة
            </Link>
            <Link className={styles.ctaGhost} href={routes.contact}>
              تواصل معنا
            </Link>
          </div>
        </section>
      </div>
    </div>
  );
}
