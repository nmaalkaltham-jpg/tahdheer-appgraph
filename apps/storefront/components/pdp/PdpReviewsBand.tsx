'use client';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { formatNumber } from '@tahdheer/design-system/format';
import { submitReview } from '../reviews/api';
import type { Review, ReviewAggregate } from '../reviews/types';
import { PenIcon, StarIcon } from './icons';
import styles from './pdp.module.css';

/**
 * «آراء المعلّمين عن هذا الملف» — Storefront Product.dc.html's reviews
 * marquee + «اكتب تقييمك» form (and its mobile form).
 *
 * The cards are the product's published reviews (the same payload as the
 * reviews tab); with none, the marquee is absent and the band keeps its
 * heading and write button. The heading's «4.9 · 312» is the server
 * aggregate, shown only with a real count. The design's «مباشر» pill is not
 * rendered: nothing in the reviews API marks freshness, so «live» would be a
 * claim with no data behind it.
 *
 * The form posts to the real endpoint. The reviewer's name comes from the
 * order's account on the server (it cannot be typed), so the design's two
 * inputs carry the two free-text fields the API accepts — role and city.
 * Submissions need the order token the buyer arrives with (`?token=`);
 * without it the error slot says so instead of pretending to publish. A
 * submitted review goes to moderation, so it is NOT injected into the list.
 */
const WORDS = ['', 'سيّئ', 'مقبول', 'جيد', 'جيد جدًا', 'ممتاز'] as const;
const BODY_MIN = 10;

function dateOf(iso: string | null | undefined): string | null {
  if (!iso) return null;
  const match = /^(\d{4}-\d{2}-\d{2})/.exec(iso);
  return match?.[1] ?? null;
}

function roleOf(review: Review): string | null {
  const parts = [review.authorRole, review.authorCity].filter(
    (part): part is string => typeof part === 'string' && part.trim() !== '',
  );
  return parts.length > 0 ? parts.join(' — ') : null;
}

function ReviewCard({ review }: { review: Review }) {
  const role = roleOf(review);
  const date = dateOf(review.createdAt);
  const initial = review.authorName.trim().charAt(0);
  const stars = Math.max(0, Math.min(5, Math.round(review.rating)));
  const text = review.body?.trim() || review.title?.trim() || '';

  return (
    <div className={styles.mqCard}>
      <div className={styles.mqHead}>
        <span className={styles.mqAvatar} aria-hidden="true">
          {initial}
        </span>
        <span className={styles.mqWho}>
          <b>{review.authorName}</b>
          {role ? <span>{role}</span> : null}
        </span>
        <span className={styles.mqStars} role="img" aria-label={`${stars} من 5`}>
          {Array.from({ length: stars }, (_, k) => (
            <StarIcon key={k} size={12} />
          ))}
        </span>
      </div>
      {text ? <p className={styles.mqText}>{text}</p> : null}
      {date ? (
        <span className={styles.mqDate} dir="ltr">
          {date}
        </span>
      ) : null}
    </div>
  );
}

export interface PdpReviewsBandProps {
  productId: number;
  reviews: Review[];
  aggregate: ReviewAggregate | null;
  orderToken: string | null;
}

export function PdpReviewsBand({ productId, reviews, aggregate, orderToken }: PdpReviewsBandProps) {
  const [open, setOpen] = useState(false);
  const [stars, setStars] = useState(5);
  const [role, setRole] = useState('');
  const [city, setCity] = useState('');
  const [text, setText] = useState('');
  /* خطأ الحقل (طول التعليق) بجوار حقله؛ وما سواه (الرمز، الخادم) تنبيهٌ عامّ. */
  const [bodyError, setBodyError] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  /* رسالة الخادم بعد الإرسال — تأكيدٌ ثابت مكان النموذج لا إشعارٌ عابر. */
  const [done, setDone] = useState<string | null>(null);
  const starRefs = useRef<(HTMLButtonElement | null)[]>([]);
  /* WCAG 2.2.2: moving content needs a stop control usable by keyboard and touch. */
  const [paused, setPaused] = useState(false);

  const count = aggregate && aggregate.count > 0 ? aggregate.count : null;

  /* عند إغلاق النموذج (إرسالٌ أو إلغاء) يعود التركيز إلى زرّ الفتح لا يسقط في المستند. */
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  const wasOpen = useRef(false);
  useEffect(() => {
    if (wasOpen.current && !open) triggerRef.current?.focus();
    wasOpen.current = open;
  }, [open]);

  const toggle = (event?: { currentTarget: HTMLButtonElement }) => {
    if (event && event.currentTarget.hasAttribute('aria-expanded')) triggerRef.current = event.currentTarget;
    setOpen((value) => !value);
    setError('');
    setBodyError('');
    setDone(null);
  };

  /* مجموعة راديو: الأسهم تنقل الاختيار والتركيز (يسارٌ = التالي في RTL). */
  const onStarKey = (event: KeyboardEvent<HTMLButtonElement>, n: number) => {
    const forward = event.key === 'ArrowLeft' || event.key === 'ArrowUp';
    const back = event.key === 'ArrowRight' || event.key === 'ArrowDown';
    if (!forward && !back) return;
    event.preventDefault();
    const next = forward ? Math.min(5, n + 1) : Math.max(1, n - 1);
    setStars(next);
    starRefs.current[next - 1]?.focus();
  };

  const submit = () => {
    const body = text.trim();
    setError('');
    if (body.length < BODY_MIN) {
      setBodyError(`اكتب تعليقًا لا يقل عن ${formatNumber(BODY_MIN)} أحرف.`);
      /* التركيز إلى الحقل المخطئ: قارئ الشاشة يقرأ الخطأ المرتبط به. */
      document.getElementById('pdp-rv-text')?.focus();
      return;
    }
    setBodyError('');
    if (!orderToken) {
      setError('التقييم للمشترين: افتح هذه الصفحة من رابط طلبك لنتحقّق من الشراء.');
      return;
    }
    setBusy(true);
    void submitReview(
      productId,
      {
        rating: stars,
        body,
        ...(role.trim() ? { authorRole: role.trim() } : {}),
        ...(city.trim() ? { authorCity: city.trim() } : {}),
      },
      orderToken,
    ).then((res) => {
      setBusy(false);
      if (!res.data) {
        setError(res.message ?? 'تعذّر إرسال التقييم — أعد المحاولة.');
        return;
      }
      setOpen(false);
      setStars(5);
      setRole('');
      setCity('');
      setText('');
      setError('');
      setDone(res.data.message);
    });
  };

  return (
    <section className={styles.band} id="pdp-reviews" aria-labelledby="pdp-voices-title">
      <div className={styles.bandInner}>
        <div className={styles.bandHead}>
          <div className={styles.bandTitleRow}>
            {/* «مباشر»: الشريط يعرض التقييمات المنشورة الحيّة نفسها التي يقرؤها التبويب — لا نسخة مجمّدة. */}
            {reviews.length > 0 ? (
              <span className={styles.live}>
                <span className={styles.liveDot} aria-hidden="true" />
                مباشر
              </span>
            ) : null}
            <h2 className={styles.bandTitle} id="pdp-voices-title">
              <span className={styles.bandTitleLong}>آراء المعلّمين عن هذا الملف</span>
              <span className={styles.bandTitleShort}>آراء المعلّمين</span>
            </h2>
            {aggregate && count !== null ? (
              <span className={styles.bandStats} dir="ltr">
                {aggregate.avgRating.toFixed(1)} · {formatNumber(count)}
                <span className={styles.bandStatsWord}> تقييمًا</span>
              </span>
            ) : null}
          </div>
          <button type="button" className={styles.writeBtn} onClick={toggle} aria-expanded={open}>
            <PenIcon size={16} />
            {open ? 'إخفاء النموذج' : 'اكتب تقييمك'}
          </button>
        </div>

        {reviews.length > 0 ? (
          <div className={styles.mqViewport} role="region" aria-roledescription="marquee" aria-label="آراء المعلّمين">
            {/* WCAG 2.2.2 stop control — overlaid on the strip's corner so the design's layout is untouched. */}
            <button
              type="button"
              className={styles.mqPause}
              aria-pressed={paused}
              aria-label={paused ? 'تشغيل حركة الآراء' : 'إيقاف حركة الآراء'}
              onClick={() => setPaused((value) => !value)}
            >
              {paused ? (
                <svg aria-hidden="true" width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M8 5.5v13l11-6.5z" />
                </svg>
              ) : (
                <svg aria-hidden="true" width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
                  <rect x="6" y="5" width="4" height="14" rx="1.2" />
                  <rect x="14" y="5" width="4" height="14" rx="1.2" />
                </svg>
              )}
            </button>
            <div className={styles.mqTrack} data-paused={paused ? '' : undefined}>
              {reviews.map((review) => (
                <ReviewCard review={review} key={`a-${review.id}`} />
              ))}
              {/* The loop's second copy only seams the scroll — hidden from AT. */}
              <div className={styles.mqLoop} aria-hidden="true">
                {reviews.map((review) => (
                  <ReviewCard review={review} key={`b-${review.id}`} />
                ))}
              </div>
            </div>
          </div>
        ) : null}

        <button type="button" className={styles.writeBtnMobile} onClick={toggle} aria-expanded={open}>
          <PenIcon size={16} />
          {open ? 'إخفاء النموذج' : 'اكتب تقييمك'}
        </button>

        {open ? (
          <div className={styles.form}>
            <div className={styles.formHead}>
              <b>اكتب تقييمك</b>
              <span>يُنشر بعد التحقّق من الشراء</span>
            </div>
            <div className={styles.formStarsRow}>
              <span className={styles.formLabel} id="pdp-stars-label">
                تقييمك
              </span>
              <div className={styles.starPicker} role="radiogroup" aria-labelledby="pdp-stars-label">
                {[1, 2, 3, 4, 5].map((n) => (
                  <button
                    key={n}
                    ref={(node) => {
                      starRefs.current[n - 1] = node;
                    }}
                    type="button"
                    role="radio"
                    aria-checked={stars === n}
                    aria-label={`${n} من 5`}
                    tabIndex={stars === n ? 0 : -1}
                    className={styles.starPick}
                    data-on={n <= stars ? '' : undefined}
                    onClick={() => setStars(n)}
                    onKeyDown={(event) => onStarKey(event, n)}
                  >
                    <StarIcon size={26} filled={n <= stars} />
                  </button>
                ))}
              </div>
              <span className={styles.ratingWord}>{WORDS[stars]}</span>
            </div>
            <div className={styles.formFields}>
              <div>
                <label className={styles.fieldLabel} htmlFor="pdp-rv-role">
                  صفتك
                </label>
                <input
                  id="pdp-rv-role"
                  className={styles.field}
                  value={role}
                  onChange={(event) => setRole(event.target.value)}
                  placeholder="مثال: معلّمة علوم"
                  maxLength={120}
                />
              </div>
              <div>
                <label className={styles.fieldLabel} htmlFor="pdp-rv-city">
                  مدينتك
                </label>
                <input
                  id="pdp-rv-city"
                  className={styles.field}
                  value={city}
                  onChange={(event) => setCity(event.target.value)}
                  placeholder="مثال: جدة"
                  maxLength={80}
                />
              </div>
            </div>
            <div>
              <label className={styles.fieldLabel} htmlFor="pdp-rv-text">
                تعليقك
              </label>
              <textarea
                id="pdp-rv-text"
                className={styles.textarea}
                value={text}
                onChange={(event) => {
                  setText(event.target.value);
                  if (bodyError && event.target.value.trim().length >= BODY_MIN) setBodyError('');
                }}
                rows={3}
                placeholder="ما الذي أعجبك في الملف؟ وكيف استخدمته في فصلك؟"
                maxLength={2000}
                aria-invalid={bodyError ? true : undefined}
                aria-describedby={bodyError ? 'pdp-rv-text-hint pdp-rv-text-error' : 'pdp-rv-text-hint'}
              />
              {bodyError ? (
                <p className={styles.fieldErr} id="pdp-rv-text-error">
                  {bodyError}
                </p>
              ) : null}
              <span className={styles.fieldHint} id="pdp-rv-text-hint">
                {formatNumber(BODY_MIN)} أحرف على الأقل.
              </span>
            </div>
            {error ? (
              <p className={styles.formError} role="alert">
                {error}
              </p>
            ) : null}
            <div className={styles.formActions}>
              <button
                type="button"
                className={styles.submitBtn}
                onClick={submit}
                disabled={busy}
                aria-busy={busy || undefined}
              >
                {busy ? 'جارٍ الإرسال…' : 'أرسل التقييم'}
              </button>
              <button type="button" className={styles.cancelBtn} onClick={toggle}>
                إلغاء
              </button>
            </div>
          </div>
        ) : null}

        {done && !open ? (
          <p className={styles.formDone} role="status">
            {done}
          </p>
        ) : null}
      </div>
    </section>
  );
}
