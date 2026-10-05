"use client";

/**
 * حدّ الخطأ للمتجر — المقابل الغائب لـ`app/not-found.tsx`.
 *
 * ─── العطل الذي وُلد منه ───
 *
 * التطبيقان فيهما ملفّا حدٍّ اثنان لمئةٍ ووجهتَي مسار: `not-found.tsx` في
 * المتجر و`error.tsx` في اللوحة. فرميةٌ في تصيير أيّ صفحة متجر — بطاقة
 * منتجٍ بحقلٍ غير متوقّع، أو سلّةٌ بحمولةٍ مشوّهة — كانت تصعد إلى حدّ Next
 * الافتراضيّ: صفحةٌ بيضاء بالإنجليزيّة، بلا `dir="rtl"` ولا هويّة ولا
 * طريقٍ للعودة. في متجرٍ عربيّ هذا عطلٌ مرئيّ لا نقصُ ترفيه.
 *
 * ─── ولماذا في جذر `app/` ───
 *
 * الجذر يشمل كلّ مجموعات المسارات — `(catalog)` و`account` و`shop` — بملفٍّ
 * واحد، ويقع **داخل** `app/layout.tsx` فتبقى الترويسة والتذييل و`dir`.
 *
 * ─── وما لا يُعرض ───
 *
 * لا نصّ الرمية ولا `digest` ولا الكومة: نصّ الخطأ خادميّ وقد يحمل مسارًا
 * أو مفتاحًا، وصفحة المتجر يراها الزبون ويصوّرها. المعرّف إلى
 * `console.error` للمشغّل وحده — وهو النمط نفسه في `apps/admin/app/error.tsx`.
 *
 * وحدُّ **رميات التصيير** لا أخطاء الجلب: طبقة `lib/api` تُعيد الحالة
 * والبيانات فتُعرض حالةٌ صريحة في موضعها، ولا تصل إلى هنا.
 */

import { useEffect } from "react";
import { routes } from "../lib/routes";
import { ThemedArt } from "../components/home/ThemedArt";
import { EMPTY_ART } from "../lib/art";
import styles from "./error.module.css";

export default function StorefrontError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    /* للمشغّل لا للزبون: المعرّف وحده يربط هذه الشاشة بسطر سجلّ الخادم. */
    console.error("storefront render error", error.digest ?? "(no digest)");
  }, [error]);

  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <div className={styles.panel} role="alert">
          <div className={styles.row}>
            <ThemedArt
              art={EMPTY_ART.generic}
              alt=""
              sizes="(max-width: 767px) 88px, 120px"
              className={styles.art}
            />

            <div className={styles.main}>
              <h1 className={styles.title}>تعذّر عرض هذه الصفحة</h1>
              <p className={styles.body}>
                انكسر شيءٌ عندنا أثناء بناء الصفحة — لا عندك. المحاولة مرّةً
                أخرى تنجح غالبًا، فالسبب عابرٌ في أكثر الأحيان.
              </p>
              <p className={styles.body}>
                وإن كنت في منتصف شراء فلا تُعِد الدفع قبل أن تفتح{" "}
                <a href={routes.cart}>سلّتك</a>: الانكسار قد يقع بعد أن يصل
                الطلب، فيكون ما تريده قد تمّ.
              </p>

              <div className={styles.actions}>
                <button
                  type="button"
                  className={styles.primary}
                  onClick={reset}
                >
                  أعِد المحاولة
                </button>
                <a className={styles.ghost} href={routes.home}>
                  العودة إلى الرئيسة
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
