/**
 * /request — «اطلب ملفًا غير متوفّر».
 *
 * الصفحة التي كان مسارها معلنًا في خريطة المسارات منذ البداية بلا صفحة خلفه.
 * أثرها مزدوج: تُبقي الزائر الذي لم يجد طلبه، وتحوّل كل طلب فائت إلى بيانات
 * تقول أي ملف يُنتَج بعد ذلك — وهو قرارٌ كان يُتّخذ اليوم بالتخمين.
 *
 * `noindex, follow`: صفحة نموذج لا محتوى فيها يُبحَث عنه، ولا يجوز أن تنافس
 * أرشيفًا حقيقيًّا على استعلام. الروابط متبوعة فيبقى ما تحتها موصولًا.
 */
import type { Metadata } from "next";
import Link from "next/link";
import { apiBase } from "../../lib/api";
import { routes } from "../../lib/routes";
import { fetchTerms } from "../(catalog)/_components/catalog-api";
import { initialSelection } from "../../lib/request-intent";
import { RequestForm, type TermOption } from "./RequestForm";
import styles from "./page.module.css";

const TITLE = "اطلب ملفًا غير متوفّر";

export const metadata: Metadata = {
  title: TITLE,
  description:
    "لم تجد الملف الذي تحتاجه؟ حدّد المرحلة والصف والمادة والفصل ونوع الملف، وسنرتّب إنتاجه حسب عدد من طلبوه.",
  alternates: { canonical: "/request" },
  robots: { index: false, follow: true },
};

/** استعلام جاء من بحث بلا نتائج — يُملأ في الملاحظة كما كتبه الزائر. */
type Search = Promise<{ q?: string; stage?: string; grade?: string; subject?: string; semester?: string; kind?: string }>;

export default async function RequestPage({
  searchParams,
}: {
  searchParams: Search;
}) {
  const [params, stages, grades, subjects, semesters, kinds] = await Promise.all(
    [
      searchParams,
      fetchTerms("stages"),
      fetchTerms("grades"),
      fetchTerms("subjects"),
      fetchTerms("semesters"),
      fetchTerms("kinds"),
    ],
  );

  const options = (list: {
    data: { slug: string; name: string }[];
  }): TermOption[] =>
    list.data.map((term) => ({ slug: term.slug, name: term.name }));

  /* صفحة «لا نتائج» تحمل ما فهمه البحث؛ يُقبل منه ما في القوائم وحده. */
  const initial = initialSelection(params, {
    stages: stages.data,
    grades: grades.data,
    subjects: subjects.data,
    semesters: semesters.data,
    kinds: kinds.data,
  });

  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <nav aria-label="مسار التنقّل">
          <ol className={styles.crumbs}>
            <li>
              <Link href={routes.home}>الرئيسية</Link>
            </li>
            <li aria-current="page">{TITLE}</li>
          </ol>
        </nav>
        <header className={styles.head}>
          <p className={styles.eyebrow}>حسب الطلب</p>
          <h1 className={styles.h1}>{TITLE}</h1>
          <p className={styles.lede}>
            نُنتج الملفات حسب الطلب الفعلي لا حسب التخمين. حدّد ما تحتاجه بدقّة،
            وسيُحتسب طلبك مع كل من طلب الشيء نفسه — والأكثر طلبًا يُنتَج أوّلًا.
          </p>
        </header>
        <div className={styles.grid}>
          <section
            className={styles.formCard}
            aria-labelledby="request-form-title"
          >
            <h2 className={styles.formTitle} id="request-form-title">
              تفاصيل الملف المطلوب
            </h2>

            <RequestForm
              apiBase={apiBase()}
              stages={options(stages)}
              grades={options(grades)}
              subjects={options(subjects)}
              semesters={options(semesters)}
              kinds={options(kinds)}
              initialNote={params.q}
              initial={initial}
            />
          </section>

          <aside className={styles.aside}>
            <h2 className={styles.asideTitle}>قبل أن تطلب</h2>
            <p className={styles.asideText}>
              كثير ممّا يبدو غير متوفّر موجودٌ باسم آخر. جرّب البحث بصيغة أخرى،
              أو تصفّح المادة أو الصف مباشرة — فقد يكون الملف منشورًا تحت نوع
              مختلف.
            </p>
            <ul className={styles.asideLinks}>
              <li>
                <Link href={routes.search}>ابحث في المتجر</Link>
              </li>
              <li>
                <Link href={routes.grades}>تصفّح حسب الصف</Link>
              </li>
              <li>
                <Link href={routes.subjects}>تصفّح حسب المادة</Link>
              </li>
              <li>
                <Link href={routes.shop}>كل الملفات المنشورة</Link>
              </li>
            </ul>
          </aside>
        </div>
      </div>
    </div>
  );
}
