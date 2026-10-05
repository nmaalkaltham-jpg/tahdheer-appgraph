/**
 * عطلٌ يُقال، لا فراغٌ يُصمَت عليه.
 *
 * `apiGet` تُعيد `data: null` على ٥٠٠ كما تُعيدها على «لا شيء منشور»، فكان
 * انقطاع الواجهة البرمجية يُترجَم إلى إعلانٍ واثق بالفراغ. الآن: «لم يُنشر
 * بعد» مقصورةٌ على ٢٠٠ + لا شيء (القسم لا يُصيَّر)، و`status != 200` يُقال
 * عطلًا للزائر: البقيّة تعمل والتحديث يكفي.
 *
 * ولا `role="alert"`: القسم يصل مُصيَّرًا مع أوّل بايت لا بعد تفاعل؛
 * و`role="status"` يُعلِن بلا مقاطعة.
 */
import styles from './sections.module.css';

export function failed(status: number): boolean {
  return status !== 200;
}

export function Failed() {
  return (
    <div className={styles.failure} role="status">
      <svg
        className={styles.failureIcon}
        viewBox="0 0 20 20"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        aria-hidden="true"
      >
        <circle cx="10" cy="10" r="7.25" />
        <path d="M10 6.5v4.25" strokeLinecap="round" />
        <path d="M10 13.6v.01" strokeLinecap="round" />
      </svg>
      <div>
        <p className={styles.failureTitle}>تعذّر تحميل هذا القسم</p>
        <p className={styles.failureHint}>
          بقيّة الصفحة تعمل. حدِّث الصفحة بعد لحظات — وإن تكرّر العطل فالخلل عندنا لا عندك.
        </p>
      </div>
    </div>
  );
}
