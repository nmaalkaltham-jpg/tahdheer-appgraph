/**
 * من نحن — about page.
 *
 * Design: «Tahdheer - Content Pages» (isAbout artboard: gradient hero, stats
 * strip, «كيف نعمل» principle cards). Declared SEO intent for this screen in
 * «Tahdheer - Content Hub»: «index · Organization entity».
 *
 * HONESTY DOCTRINE:
 *  · The artboard's stats strip (سنة التأسيس · ملزمة منشورة · معلمًا مُعِدًّا ·
 *    أسرة تعتمدنا) is SAMPLE data with no endpoint behind it and no way for a
 *    reader to audit it, so the whole strip is absent rather than fabricated.
 *    It returns the day a real counts endpoint exists.
 *  · The «من يقف خلف الملازم» team strip of the Content Hub artboard is absent
 *    for the same reason: inventing named people is the worst version of this.
 *  · The three principles below are the design's own explanatory copy, kept to
 *    statements this store can actually stand behind: the delivery promise is
 *    the flow implemented in checkout → /orders/{token} → downloads, and the
 *    review claim is the verified-purchase rule the reviews domain enforces.
 */
import type { Metadata } from "next";
import Link from "next/link";
import { Icon, type IconName } from "../../components/home/icons";
import { routes } from "../../lib/routes";
import styles from "./page.module.css";

export const metadata: Metadata = {
  title: "من نحن",
  description:
    "من نحن ولماذا بدأنا: ملفات تعليمية جاهزة يُعدّها معلّمون ميدانيون، وتُسلَّم فور إتمام الطلب.",
  alternates: { canonical: routes.about },
  robots: { index: true, follow: true },
};

interface Principle {
  icon: IconName;
  title: string;
  body: string;
}

/** «كيف نعمل» — three cards, exactly the artboard's structure. */
const PRINCIPLES: readonly Principle[] = [
  {
    icon: "pen",
    title: "من الفصل لا من المكتب",
    body: "الملفات يُعدّها معلّمون ميدانيون يعملون على المنهج نفسه الذي تدرّسه، لا محرّرون بعيدون عن الصف.",
  },
  {
    icon: "download",
    title: "تسليم فوري بعد الدفع",
    body: "كل ما نبيعه ملفّ رقمي: ما إن يُتمّ الطلب حتى تفتح صفحة الطلب وفيها روابط التحميل، وتبقى الصفحة مرجعك لإعادة التحميل.",
  },
  {
    icon: "shield",
    title: "لا رقم بلا مصدر",
    body: "التقييمات المعروضة من مشترين موثّقين، وأي رقم لا نستطيع إثباته لا يُعرض أصلًا — الفراغ أصدق من رقم مُجمَّل.",
  },
] as const;

export default function AboutPage() {
  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <nav aria-label="مسار التنقّل">
          <ol className={styles.crumbs}>
            <li>
              <Link href={routes.home}>الرئيسية</Link>
            </li>
            <li aria-current="page">من نحن</li>
          </ol>
        </nav>

        <header className={styles.head}>
          <p className={styles.eyebrow}>قصّتنا</p>
          <h1 className={styles.h1}>من نحن</h1>
          <p className={styles.lede}>
            بدأنا لأن التحضير كان يستهلك من وقت المعلّم أكثر مما يستهلكه التدريس
            نفسه. نُعدّ ملفات جاهزة يكتبها معلّمون ميدانيون، وقابلة للتعديل حتى
            تشبه صفّك أنت.
          </p>
        </header>

        {/*
          The stats strip of the artboard sits here in the design. It stays out
          until real, auditable counts have an endpoint — see the file header.
        */}

        <section className={styles.section} aria-labelledby="about-how">
          <p className={styles.eyebrow}>منهجنا</p>
          <h2 className={styles.h2} id="about-how">
            كيف نعمل
          </h2>
          <ul className={styles.cards} role="list">
            {PRINCIPLES.map((principle) => (
              <li className={styles.card} key={principle.title}>
                <span className={styles.cardIcon} aria-hidden="true">
                  <Icon name={principle.icon} size={20} strokeWidth={2} />
                </span>
                <b className={styles.cardTitle}>{principle.title}</b>
                <span className={styles.cardBody}>{principle.body}</span>
              </li>
            ))}
          </ul>
        </section>

        <section className={styles.cta} aria-labelledby="about-cta">
          <div className={styles.ctaCopy}>
            <h2 className={styles.ctaTitle} id="about-cta">
              ابدأ من الملفات نفسها
            </h2>
            <p className={styles.ctaLede}>
              صفحة كل ملف تعرض محتواه وصيغته وسعره المعلن قبل أي التزام منك.
            </p>
          </div>
          <div className={styles.ctaBtns}>
            <Link className={styles.ctaPrimary} href={routes.shop}>
              تصفّح ملفات المتجر
            </Link>
            <Link className={styles.ctaGhost} href={routes.howItWorks}>
              كيف نعمل
            </Link>
          </div>
        </section>
      </div>
    </div>
  );
}
