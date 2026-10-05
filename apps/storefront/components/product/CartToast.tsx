'use client';
/**
 * تنبيه «أُضيف إلى السلّة» — التغذية الراجعة الواحدة لكلّ إضافة.
 *
 * التصميم يرسمه ثابتًا أسفل الشاشة مع رابطٍ إلى السلّة، لا سطرًا تحت الزرّ:
 * من ضغط «أضف» وهو عند أسفل الصفحة لا يرى سطرًا عند أعلاها، فيضغط ثانيةً —
 * والرابط هو ما يحوّل الإخبار إلى خطوةٍ تالية.
 *
 * والنصّ لا يُخترع هنا: هو رسالة الخادم حرفيًّا أو جملة تعذّر الاتّصال
 * الواحدة في المتجر (`useAddToCart`). هذا المكوّن يعرض ولا يؤلّف.
 *
 * `role="status"` لا `alert`: الإضافة نجاحٌ متوقَّع لا طارئ، فتُقرأ بلطفٍ
 * ولا تقاطع ما يقرؤه المستخدم.
 */
import { useEffect, useState } from 'react';
import { routes } from '../../lib/routes';
import { CheckIcon } from './icons';
import styles from './CartToast.module.css';

/** مدّة البقاء كما يرسمها التصميم. */
const DISMISS_MS = 2800;

export interface CartToastProps {
  /** رسالة الخادم؛ `null` يعني «لا شيء ليُعرض». */
  message: string | null;
  /** الخطأ يبقى حتّى يُقرأ: لا يُخفى تلقائيًّا ولا يعرض رابط السلّة. */
  tone?: 'success' | 'error';
}

export function CartToast({ message, tone = 'success' }: CartToastProps) {
  /*
   * الحالة تُشتقّ من الرسالة لا تُزامَن معها في أثرٍ جانبيّ (قاعدة المستودع
   * ٢٢): `seen` يحفظ آخر رسالةٍ عُرضت، فوصول رسالةٍ جديدة — ولو كانت النصّ
   * نفسه بعد إضافةٍ ثانية — يُعيد إظهار التنبيه أثناء التصيير مباشرةً.
   */
  const [seen, setSeen] = useState<string | null>(null);
  const [hidden, setHidden] = useState(false);

  if (message !== seen) {
    setSeen(message);
    setHidden(false);
  }

  useEffect(() => {
    if (message === null || tone === 'error') return;

    const timer = window.setTimeout(() => setHidden(true), DISMISS_MS);

    return () => window.clearTimeout(timer);
  }, [message, tone]);

  return (
    /*
     * العنصر مُصيَّر دائمًا وفارغًا حين لا رسالة: منطقةٌ حيّة تُضاف إلى
     * الشجرة بعد امتلائها لا يقرؤها كثيرٌ من مُدرِّجات الشاشة.
     */
    <div
      className={styles.region}
      role="status"
      aria-live="polite"
      data-tone={tone}
      data-open={message !== null && !hidden ? '' : undefined}
    >
      {message !== null && !hidden ? (
        <div className={styles.toast}>
          {tone === 'success' ? <CheckIcon className={styles.icon} /> : null}
          <span className={styles.text}>{message}</span>
          {tone === 'success' ? (
            <a className={styles.link} href={routes.cart}>
              عرض السلة
            </a>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

