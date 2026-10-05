/**
 * بطاقة فشل الاتصال بالـAPI.
 *
 * الرسالة تتبع صنف الفشل ولا تُوحَّد: «تعذّر الاتصال — أعد المحاولة» على عطل
 * ضبط تُخفي السبب وتَعِد المستخدم بحلٍّ لا يملكه. سلة الإنتاج ظلّت تعرض هذه
 * الجملة بينما لم يُرسَل طلب واحد قط — العنوان لم يُخبز في حزمة المتصفّح
 * والخادم كان يردّ 200. زرّ «إعادة المحاولة» هناك لا يصلح شيئًا مهما ضُغط.
 *
 * لذلك: زرّ الإعادة يظهر فقط حين تكون الإعادة قابلة لأن تنجح.
 */
import styles from './ConnectionError.module.css';

type ConnectionFailure = 'unconfigured' | 'network' | 'http';

export interface ConnectionErrorProps {
  onRetry?: () => void;
  href?: string;
  /** غيابه يعني انقطاع شبكة — السلوك السابق نفسه. */
  failure?: ConnectionFailure;
}

const COPY: Record<ConnectionFailure, { text: string; retryable: boolean }> = {
  network: { text: 'تعذّر الاتصال بالخادم — أعد المحاولة', retryable: true },
  http: { text: 'تعذّر إكمال الطلب — أعد المحاولة', retryable: true },
  unconfigured: {
    // لا وعد بإعادة محاولة: العطل في النشر، ولا شيء يتغيّر بالضغط.
    text: 'المتجر غير مضبوط للاتصال بالخادم. أبلغ المسؤول — الإعداد ينقصه عنوان الواجهة البرمجية.',
    retryable: false,
  },
};

export function ConnectionError({ onRetry, href, failure = 'network' }: ConnectionErrorProps) {
  const { text, retryable } = COPY[failure];

  return (
    <div className={styles.card} role="alert">
      <p className={styles.text}>{text}</p>
      {!retryable ? null : onRetry ? (
        <button type="button" className={styles.retry} onClick={onRetry}>
          إعادة المحاولة
        </button>
      ) : href ? (
        <a className={styles.retry} href={href}>
          إعادة المحاولة
        </a>
      ) : null}
    </div>
  );
}
