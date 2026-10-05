'use client';
/**
 * «افتح طلبي» — the ONE genuinely working access path today.
 *
 * The backend has no customer session: `/api/v1/orders/{token}` and the
 * download endpoints authenticate with the signed ORDER TOKEN that ships in the
 * confirmation e-mail, not with a login. So this form takes that link (or the
 * bare token at its end) and navigates to the real order page — no account, no
 * fake session, no invented endpoint.
 *
 * Reused verbatim by the /account sections, which face the same truth.
 */
import { useId, useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import { encodeSlug } from '../../lib/api';
import styles from './OrderLookupForm.module.css';

/**
 * Accept either a pasted order link (…/orders/&lt;token&gt;?x=1) or the bare
 * token. Anything with whitespace or a path separator left in it is not a
 * token, so it is rejected instead of being sent to a 404.
 */
export function extractOrderToken(raw: string): string | null {
  const value = raw.trim();
  if (value === '') return null;
  const fromLink = /\/orders\/([^/?#\s]+)/.exec(value);
  const token = fromLink?.[1] ?? value;
  if (token === '' || /[\s/?#]/.test(token)) return null;
  return token;
}

export interface OrderLookupFormProps {
  /** Visible heading for the block; omitted when the caller already has one. */
  heading?: string;
  /** Extra class for the wrapper so each surface can set its own spacing. */
  className?: string;
  /** ‎secondary‎ حين يجاور النموذجَ فعلٌ أساسيّ آخر (بطاقة الدخول) — أساسيٌّ واحد لكلّ سطح. */
  tone?: 'primary' | 'secondary';
}

export function OrderLookupForm({ heading, className, tone = 'primary' }: OrderLookupFormProps) {
  const router = useRouter();
  const uid = useId();
  const [value, setValue] = useState('');
  const [error, setError] = useState<string | null>(null);
  /**
   * حماية من الإرسال المزدوج — نفس نمط `checkoutBusy` في السلّة.
   *
   * `router.push` تنقّلٌ غير فوريّ: بين الضغطة ورسم صفحة الطلب ثوانٍ يبقى فيها
   * الزرّ حيًّا، فكلّ ضغطةٍ إضافية تُكدّس تنقّلًا آخر على المكدّس نفسه ويعود
   * الزائر بعدها خطواتٍ لا خطوة. لا تُصفَّر الحالة: نجاحُها يعني أنّ هذه الشاشة
   * تُستبدل، وفشلُ التنقّل يُعيد تركيب المكوّن من جديد.
   */
  const [pending, setPending] = useState(false);

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (pending) return;
    const token = extractOrderToken(value);
    if (token === null) {
      setError('ألصق رابط الطلب من رسالة التأكيد، أو الرمز الموجود في آخره.');
      return;
    }
    setError(null);
    setPending(true);
    router.push(`/orders/${encodeSlug(token)}`);
  };

  return (
    <form
      className={className ? `${styles.form} ${className}` : styles.form}
      onSubmit={handleSubmit}
      noValidate
    >
      {heading ? <h2 className={styles.heading}>{heading}</h2> : null}
      <label className={styles.label} htmlFor={`${uid}-token`}>
        رابط الطلب أو رمزه
      </label>
      <input
        id={`${uid}-token`}
        className={styles.input}
        type="text"
        dir="ltr"
        name="order"
        autoComplete="off"
        spellCheck={false}
        placeholder="/orders/…"
        value={value}
        onChange={(event) => {
          setValue(event.target.value);
          if (error !== null) setError(null);
        }}
        aria-describedby={error !== null ? `${uid}-hint ${uid}-error` : `${uid}-hint`}
        aria-invalid={error !== null}
      />
      <p id={`${uid}-hint`} className={styles.hint}>
        تجده في رسالة تأكيد طلبك — الرابط ينتهي بالرمز. لا يحتاج حسابًا.
      </p>
      {/* منطقةٌ حيّة قائمة دائمًا، فيُعلَن الخطأ لحظة ظهوره تحت الحقل الذي يشير إليه. */}
      <div aria-live="polite">
        {error !== null ? (
          <p id={`${uid}-error`} className={styles.error}>
            {error}
          </p>
        ) : null}
      </div>
      <button
        className={tone === 'secondary' ? styles.submitSecondary : styles.submit}
        type="submit"
        disabled={pending}
        aria-busy={pending}
      >
        {pending ? 'جارٍ فتح طلبك…' : 'افتح طلبي'}
      </button>
    </form>
  );
}
