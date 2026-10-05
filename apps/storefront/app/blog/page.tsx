/**
 * المدوّنة — blog index.
 *
 * Design: «Tahdheer - Content Pages» (isBlog artboard: page header, topic
 * chips, «الأكثر قراءة» featured article, 16:9 card grid) with the declared
 * SEO intent of «Tahdheer - Content Hub» for this screen: «index · hub معرفي».
 *
 * HONESTY DOCTRINE — READ BEFORE ADDING CONTENT HERE:
 * the backend has no content/posts capability at all. `routes/api-content.php`
 * exposes homepage sections only (hero/promo/why/benefits/faq/cta/reviews) and
 * there is no Post/Article model in `backend/laravel/app/Models`. So every
 * article-driven region of the artboard — the topic chips, the featured post,
 * the card grid — has NO source, and the artboard's own sample articles
 * («كيف تُعدّ تحضيرًا أسبوعيًا في نصف الوقت؟» …) are samples, not content.
 * The page therefore states plainly that nothing is published yet instead of
 * shipping invented articles, invented authors or invented read times.
 * When a posts endpoint lands, the chips/featured/grid render from IT.
 */
import type { Metadata } from "next";
import Link from "next/link";
import { Icon } from "../../components/home/icons";
import { routes } from "../../lib/routes";
import styles from "./page.module.css";

export const metadata: Metadata = {
  title: "المدوّنة",
  description:
    "مقالات عمليّة للمعلّم: كيف تُعدّ تحضيرًا أسرع، وكيف تُدير حصّة أفضل، وما الجديد في المناهج.",
  alternates: { canonical: routes.blog },
  robots: { index: true, follow: true },
};

export default function BlogPage() {
  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <nav aria-label="مسار التنقّل">
          <ol className={styles.crumbs}>
            <li>
              <Link href={routes.home}>الرئيسية</Link>
            </li>
            <li aria-current="page">المدوّنة</li>
          </ol>
        </nav>

        <header className={styles.head}>
          <p className={styles.eyebrow}>للمعلّم</p>
          <h1 className={styles.h1}>المدوّنة</h1>
          <p className={styles.lede}>
            مقالات عمليّة للمعلّم: كيف تُعدّ تحضيرًا أسرع، وكيف تُدير حصّة أفضل،
            وما الجديد في المناهج. لا مقالات لأجل الأرشفة — كل مقال يحلّ مسألة
            نُسأل عنها فعلًا.
          </p>
        </header>

        {/* The one honest state this page can be in today. */}
        <section className={styles.empty} aria-labelledby="blog-empty-title">
          <span className={styles.emptyIcon} aria-hidden="true">
            <Icon name="pen" size={24} strokeWidth={1.9} />
          </span>
          <h2 className={styles.emptyTitle} id="blog-empty-title">
            لا توجد مقالات منشورة بعد
          </h2>
          <p className={styles.emptyHint}>
            لم يُنشر أول مقال حتى الآن، ولن نملأ الصفحة بعناوين لا وجود لها. حتى
            ذلك الحين، الملفات الجاهزة في المتجر هي المحتوى الفعلي.
          </p>
          <Link className={styles.emptyAction} href={routes.shop}>
            تصفّح ملفات المتجر
          </Link>
        </section>
      </div>
    </div>
  );
}
