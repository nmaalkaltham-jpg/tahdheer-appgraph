/**
 * علامة وسيلة دفع واحدة — المصدر الوحيد لرسمها في التذييل والدفع وصفحة طرق الدفع.
 *
 * «mada» شعارها الرسميّ من السجلّ (`PAYMENT_ART.mada`، نسختان فاتحة وداكنة)
 * لا رقاقةٌ نصّيّة؛ وما لا شعار له في السجلّ يبقى نصًّا لاتينيًّا داخل `<bdi>`
 * بالارتفاع نفسه — لا يُرسم شعارٌ تجاريّ باليد. الاسم المسموع كاملٌ دائمًا
 * («MC» تُقرأ «Mastercard»).
 *
 * المحتوى وحده: الحاوية (رقاقة ‎28px‎ أو خانة شعار) لمستهلكها.
 */
import { PAYMENT_ART } from '../../lib/art';
import { ThemedArt } from '../home/ThemedArt';
import styles from './PaymentMark.module.css';

const SPOKEN: Record<string, string> = {
  mada: 'مدى',
  visa: 'Visa',
  mc: 'Mastercard',
  mastercard: 'Mastercard',
  amex: 'American Express',
};

export function PaymentMark({
  mark,
  decorative = false,
}: {
  mark: string;
  /** داخل عنصرٍ يحمل اسمه نصًّا (خيار الدفع) ⇒ بلا اسمٍ مسموع مكرَّر. */
  decorative?: boolean;
}) {
  const key = mark.trim().toLowerCase();
  const spoken = SPOKEN[key] ?? mark;

  if (key === 'mada') {
    return (
      <ThemedArt
        art={PAYMENT_ART.mada}
        alt={decorative ? '' : spoken}
        sizes="48px"
        className={styles.logo}
      />
    );
  }

  if (decorative || spoken === mark) return <bdi dir="ltr">{mark}</bdi>;
  return (
    <>
      <bdi dir="ltr" aria-hidden="true">
        {mark}
      </bdi>
      <span className={styles.srOnly}>{spoken}</span>
    </>
  );
}
