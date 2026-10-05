'use client';
/**
 * حالة الدفع لطلبٍ بانتظاره — من حقيقة الخادم لا من عودة المزوّد.
 *
 * ─── لماذا ───
 *
 * العودة من Tap تهبط هنا قبل أن يصل خطّاف الدفع في أغلب الأحيان. كانت
 * الصفحة تقول «لم يُخصم أي مبلغ بعد» لمن خُصم منه للتوّ، ولا تتحرّك حتّى
 * يضغط تحديثًا. وكان الطلب الذي فشلت نيّتُه ميتًا: لا رابط ولا زرّ.
 *
 * ─── القواعد ───
 *
 *   · الاستطلاع محدود: يبدأ كلّ ثانيتين ويتباطأ حتّى عشر، ويتوقّف بعد
 *     ثلاث دقائق أو عند أوّل حالةٍ نهائيّة (مدفوع/مغلق/فرق).
 *   · انتهاء المهلة ليس فشلًا: «لم نتمكّن من التأكّد بعد» مع زرّ تحديث.
 *   · `paymentIssue = MISMATCH` من الخادم: وصل مالٌ ولم يُطابَق — لا يُعرض
 *     الدفع مجدّدًا ولا يُقال «لم يُخصم».
 *   · «ادفع الآن» يطلب الرابط من الخادم (`POST …/payment`) الذي يُعيد النيّة
 *     القائمة نفسها — لا يُنشئ رسمًا ثانيًا فوق رسمٍ حيّ.
 */
import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { getOrder, retryPayment, type OrderView } from '../../lib/commerce-api';
import { isAwaitingStatus } from '../../lib/order-status';
import styles from './PaymentPending.module.css';

const FIRST_DELAY_MS = 2000;
const MAX_DELAY_MS = 10000;
const TOTAL_MS = 3 * 60 * 1000;

export interface PaymentPendingProps {
  token: string;
  payUrl: string | null;
  canRetry: boolean;
  issue: OrderView['paymentIssue'];
  /** آخر محاولة انتهت بلا مال عند المزوّد (رُفضت، أُلغيت، انتهت مهلتها). */
  lastAttemptFailed?: boolean;
  /** هل عاد المشتري للتوّ من صفحة الدفع؟ (يبدأ الاستطلاع فورًا) */
  justReturned: boolean;
}

type Phase = 'polling' | 'unresolved' | 'idle';

export function PaymentPending({ token, payUrl, canRetry, issue, justReturned, lastAttemptFailed = false }: PaymentPendingProps) {
  const router = useRouter();
  /*
   * محاولةٌ انتهت بلا مال حالةٌ نهائيّة معروفة: لا استطلاع — «لم تكتمل،
   * لم يُخصم شيء، حاول من جديد».
   */
  const [phase, setPhase] = useState<Phase>(issue || lastAttemptFailed ? 'idle' : justReturned ? 'polling' : 'idle');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const startedAt = useRef<number | null>(null);

  useEffect(() => {
    if (phase !== 'polling') return;
    let cancelled = false;
    let delay = FIRST_DELAY_MS;
    if (startedAt.current === null) startedAt.current = Date.now();
    const began = startedAt.current;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const tick = async () => {
      if (cancelled) return;
      const res = await getOrder(token);
      if (cancelled) return;
      if (res.data) {
        const terminal =
          !isAwaitingStatus(res.data.status) || res.data.paymentIssue !== null || res.data.payment?.state === 'FAILED';
        if (terminal) {
          router.refresh();
          return;
        }
      }
      if (Date.now() - began >= TOTAL_MS) {
        setPhase('unresolved');
        return;
      }
      delay = Math.min(MAX_DELAY_MS, Math.round(delay * 1.5));
      timer = setTimeout(() => void tick(), delay);
    };

    timer = setTimeout(() => void tick(), delay);
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [phase, token, router]);

  const pay = async () => {
    if (busy) return;
    setBusy(true);
    setError(null);
    /*
     * الخادم يُسأل دائمًا ولا يُتبع الرابط المحفوظ مباشرةً: رسمٌ ألغاه Tap أو
     * قبضه (ولم يصل خطّافه) يبدو «قائمًا» في الصفحة، والخادم يسأل Tap عنه
     * قبل أن يعيده — رسمٌ جديد للملغى، وتمويلٌ بلا دفعٍ ثانٍ للمقبوض.
     */
    void payUrl;
    const res = await retryPayment(token);
    if (res.data?.payment.payUrl) {
      window.location.assign(res.data.payment.payUrl);
      return;
    }
    if (res.status === 409) {
      // الخادم يعرف أكثر: حالةٌ تغيّرت أو مالٌ وصل. أعِد قراءة الصفحة.
      router.refresh();
      setBusy(false);
      return;
    }
    setError(
      res.status === 0
        ? 'تعذّر الاتصال بالخادم — أعد المحاولة.'
        : (res.message ?? 'لم يتوفّر رابط دفع الآن — أعد المحاولة بعد قليل.'),
    );
    setBusy(false);
  };

  if (issue === 'MISMATCH') {
    return (
      <div className={styles.wrap} role="status">
        <p className={styles.notice}>
          وصلتنا دفعةٌ على هذا الطلب ولم نتمكّن من مطابقتها تلقائيًّا. لا تدفع مرّةً أخرى — سيراجعها الفريق
          ويتواصل معك على بريد الطلب.
        </p>
      </div>
    );
  }

  return (
    <div className={styles.wrap}>
      {phase === 'polling' ? (
        <p className={styles.notice} role="status" aria-live="polite">
          <span className={styles.spinner} aria-hidden="true" /> نتحقّق من حالة الدفع لدى مزوّد الدفع…
        </p>
      ) : null}

      {phase === 'unresolved' ? (
        <p className={styles.notice} role="status" aria-live="polite">
          لم نتمكّن بعد من تأكيد نتيجة الدفع لدى المزوّد. إن كنت أتممت الدفع فلا تُعِده — حدّث الصفحة بعد
          قليل، وإن استمرّ ذلك تواصل معنا.
        </p>
      ) : null}

      {phase === 'idle' && lastAttemptFailed ? (
        <p className={styles.notice} role="status">
          لم تكتمل محاولة الدفع السابقة (رُفضت أو أُلغيت أو انتهت مهلتها) ولم يُخصم أي مبلغ. يمكنك المحاولة من جديد.
        </p>
      ) : null}
      {phase === 'idle' && !lastAttemptFailed ? <p className={styles.noCharge}>لم يُخصم أي مبلغ بعد</p> : null}

      <div className={styles.actions}>
        {phase === 'unresolved' ? (
          <button type="button" className={styles.secondary} onClick={() => router.refresh()}>
            تحديث الحالة
          </button>
        ) : null}
        {canRetry && phase !== 'polling' ? (
          <button
            type="button"
            className={styles.button}
            onClick={() => void pay()}
            disabled={busy}
            aria-busy={busy || undefined}
          >
            {busy ? 'جارٍ التحويل إلى صفحة الدفع…' : 'ادفع الآن'}
          </button>
        ) : null}
      </div>

      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}
