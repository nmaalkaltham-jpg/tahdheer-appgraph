'use client';
import { useState } from 'react';
import { ShareIcon } from './icons';
import { PdpToast } from './PdpToast';
import styles from './pdp.module.css';

/**
 * «شارك» — زرّ ‎44px‎ بجوار العنوان: ورقة المشاركة الأصليّة حيث وُجدت
 * (الجوّال غالبًا)، وإلّا نسخُ الرابط إلى الحافظة مع إشعار نجاح.
 *
 * الرابط المُشارَك هو مسار الصفحة وحده بلا استعلام: `?token=` رمزُ طلبٍ
 * يفتح نموذج التقييم لصاحبه، فلا يجوز أن يخرج في رابطٍ يُرسل لغيره.
 */
export function PdpShare({ title }: { title: string }) {
  const [toast, setToast] = useState<{ text: string; tone: 'success' | 'error' } | null>(null);

  async function share() {
    /* يُفرَّغ أوّلًا فيعود الإشعار نفسه عند النقرة الثانية. */
    setToast(null);
    const url = `${window.location.origin}${window.location.pathname}`;
    if (typeof navigator.share === 'function') {
      try {
        await navigator.share({ title, url });
        return;
      } catch (error) {
        /* الإلغاء اختيار الزائر لا عطل؛ غيره يسقط إلى النسخ. */
        if (error instanceof DOMException && error.name === 'AbortError') return;
      }
    }
    try {
      await navigator.clipboard.writeText(url);
      setToast({ text: 'نُسخ رابط المنتج — الصقه حيث تشاء', tone: 'success' });
    } catch {
      setToast({ text: 'تعذّر نسخ الرابط — انسخه من شريط العنوان', tone: 'error' });
    }
  }

  return (
    <>
      <button type="button" className={styles.shareBtn} onClick={share} aria-label="شارك رابط المنتج" title="شارك رابط المنتج">
        <ShareIcon />
      </button>
      {/* منطقة الإشعار نفسها ترتفع على الجوّال فوق الشريط اللاصق. */}
      <PdpToast message={toast?.text ?? null} tone={toast?.tone} cartLink={false} />
    </>
  );
}
