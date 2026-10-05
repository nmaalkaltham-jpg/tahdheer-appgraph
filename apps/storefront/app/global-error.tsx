'use client';

/**
 * حدّ الخطأ الجذريّ — الطبقة التي لا يغطّيها `app/error.tsx`.
 *
 * ─── الثغرة التي وُلد منها ───
 *
 * `app/error.tsx` يقع **داخل** `app/layout.tsx`، فلا يلتقط إلّا رميات
 * الأبناء. والتخطيط نفسه ليس ساكنًا: `app/layout.tsx` ينتظر ثلاثة نداءات
 * تنقّل (`getNavigation` للترويسة والجوال والتذييل) ويقرأ ترويسة الطلب.
 * فرميةٌ في أيٍّ منها — أو في `SiteHeader`/`SiteFooter` — تصعد فوق
 * `error.tsx` إلى حدّ Next الافتراضيّ: صفحةٌ بيضاء بالإنجليزيّة، بلا
 * `lang="ar"` ولا `dir="rtl"` ولا هويّة ولا طريق عودة.
 *
 * وهذا الملفّ هو الحدّ الوحيد الذي يعلو التخطيط. ولأنّه يحلّ محلّه كاملًا،
 * يكتب `<html>` و`<body>` بنفسه ويستورد الرموز مباشرةً: لا شيء ممّا يحقنه
 * التخطيط متاحٌ هنا.
 *
 * ─── وما لا يُعرض ───
 *
 * لا نصّ الرمية ولا `digest` ولا الكومة — النمط نفسه في `app/error.tsx`
 * و`apps/admin/app/error.tsx`: نصّ الخطأ خادميّ وقد يحمل مسارًا أو مفتاحًا،
 * وهذه شاشةٌ يراها الزبون ويصوّرها. المعرّف إلى `console.error` للمشغّل وحده.
 *
 * ─── ولا خطّ `next/font` هنا ───
 *
 * حقنُ الخطّ يخصّ التخطيط، وهذه الشاشة تعمل حين لا يعمل. فالورقة تطلب
 * `--tp-font-base` وتسقط إلى خطّ النظام إن غاب — شاشة عطلٍ تقرأ بخطٍّ
 * احتياطيّ خيرٌ من شاشة عطلٍ لا تُصيَّر.
 */

import { useEffect } from 'react';
import '@tahdheer/design-system/tokens.css';
import styles from './global-error.module.css';

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error('storefront root error', error.digest ?? '(no digest)');
  }, [error]);

  return (
    <html lang="ar" dir="rtl">
      <body className={styles.body}>
        <div className={styles.page}>
          <div className={styles.panel} role="alert">
            <div className={styles.row}>
              <span className={styles.icon} aria-hidden="true">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  width="22"
                  height="22"
                >
                  <circle cx="12" cy="12" r="9" />
                  <path d="M12 7.5v5.25" strokeLinecap="round" />
                  <path d="M12 16.2v.01" strokeLinecap="round" />
                </svg>
              </span>

              <div className={styles.main}>
                <h1 className={styles.title}>تعذّر تحميل المتجر</h1>
                <p className={styles.text}>
                  انكسر شيءٌ عندنا أثناء بناء الصفحة — لا عندك. والسبب عابرٌ في
                  أكثر الأحيان، فالمحاولة مرّةً أخرى تنجح غالبًا.
                </p>
                <div className={styles.actions}>
                  <button
                    type="button"
                    onClick={reset}
                    className={`${styles.button} ${styles.primary}`}
                  >
                    أعد المحاولة
                  </button>
                  {/*
                    `<a>` لا `<Link>` عن قصد: هذه الشاشة تعني أنّ الشجرة
                    الجذريّة سقطت، و`<Link>` تنقّلٌ من جانب العميل يُعيد
                    التصيير داخل الشجرة المكسورة نفسها. المطلوب تحميلُ مستندٍ
                    كامل يبني التخطيط من جديد.
                  */}
                  {/* eslint-disable-next-line @next/next/no-html-link-for-pages -- الشجرة الجذريّة سقطت؛ المطلوب تحميل مستندٍ كامل لا تنقّل عميل */}
                  <a href="/" className={styles.button}>
                    العودة إلى الرئيسة
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </body>
    </html>
  );
}
