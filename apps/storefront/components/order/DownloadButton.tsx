'use client';
/**
 * «حمّل» action for an unlocked entitlement row: POST grant (X-Order-Token) →
 * redeem the short-lived URL. 403 denial reasons render honestly per the
 * contract enum; 501 (storage not configured) renders as an amber notice
 * with the server message verbatim when provided.
 */
import { useState } from 'react';
import { grantDownload, type GrantDeniedReason } from '../../lib/commerce-api';
import styles from './DownloadButton.module.css';

const REASON_COPY: Record<GrantDeniedReason, string> = {
  revoked: 'أُوقفت صلاحية تحميل هذا الملف.',
  expired: 'انتهت مدة صلاحية التحميل.',
  limit: 'استُنفد الحد الأقصى لمرات التحميل.',
  unpaid: 'لم يُؤكَّد الدفع بعد — تُفتح الملفات فور التأكيد.',
  forbidden: 'لا تملك صلاحية الوصول إلى هذا الملف.',
};

export interface DownloadButtonProps {
  entitlementId: number | string;
  orderToken: string;
}

export function DownloadButton({ entitlementId, orderToken }: DownloadButtonProps) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<{ text: string; tone: 'error' | 'amber' } | null>(null);

  const handleDownload = async () => {
    if (busy) return;
    setBusy(true);
    setError(null);
    const res = await grantDownload(entitlementId, orderToken);
    if (res.data) {
      /*
       * منحةٌ بلا ملفّ ليست منحة. الخادم يردّ ٢٠٠ ومعه `fileAvailable: false`
       * حين لا أصل مرتبطًا بالمنتج، وكان الرابط يُتبَع على أيّ حال فيرى
       * المشتري صفحة JSON خامًّا بدل ملفّه. السبب نفسه الذي يُعرض على ٥٠١.
       */
      if (res.data.fileAvailable === false) {
        setError({
          text: 'لا يوجد ملف مرتبط بهذا المنتج بعد — تواصل معنا وسنجهّزه.',
          tone: 'amber',
        });
        setBusy(false);
        return;
      }
      window.location.assign(res.data.url);
      setBusy(false);
      return;
    }
    if (res.status === 403 && res.reason && REASON_COPY[res.reason]) {
      setError({ text: REASON_COPY[res.reason], tone: 'error' });
    } else if (res.status === 501) {
      /*
       * 501 هنا يعني «لا ملف مرتبط بهذا المنتج» لا «التخزين معطّل» — وهما
       * سببان مختلفان يُصلحهما شخصان مختلفان. الرسالة كانت تتّهم التخزين،
       * فيبحث الفريق في الجهة الخطأ. نصّ الخادم هو المصدر حين يصل.
       */
      setError({
        text: res.message ?? 'لا يوجد ملف مرتبط بهذا المنتج بعد — تواصل معنا وسنجهّزه.',
        tone: 'amber',
      });
    } else if (res.status === 0) {
      setError({ text: 'تعذّر الاتصال بالخادم — أعد المحاولة', tone: 'error' });
    } else {
      setError({ text: res.message ?? 'تعذّر تجهيز رابط التحميل — أعد المحاولة', tone: 'error' });
    }
    setBusy(false);
  };

  return (
    <div className={styles.wrap}>
      <button
        type="button"
        className={styles.button}
        onClick={() => void handleDownload()}
        disabled={busy}
        aria-busy={busy || undefined}
      >
        {busy ? 'جارٍ التجهيز…' : 'حمّل'}
      </button>
      {error ? (
        <p
          className={error.tone === 'amber' ? styles.amber : styles.error}
          role={error.tone === 'amber' ? 'status' : 'alert'}
        >
          {error.text}
        </p>
      ) : null}
    </div>
  );
}
