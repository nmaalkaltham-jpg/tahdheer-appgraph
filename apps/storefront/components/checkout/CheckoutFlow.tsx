'use client';
/**
 * Checkout flow (MIP §5.2 — guest-first, digital-only, no address).
 * One continuous page — «بياناتك» → «طريقة التسليم» → «وسيلة الدفع» →
 * «المراجعة والتأكيد» — exactly as Tahdheer - Checkout.dc.html composes it,
 * so the review rows can anchor back to the fields they summarise.
 *
 * HONESTY DOCTRINE: totals come from the server cart; 422 server messages
 * render verbatim; declined copy leads with «ولم يُخصم أي مبلغ»; the empty
 * cart refuses to render a payable zero.
 *
 * PAYMENT METHODS: the radiogroup lists ONLY what the checkout response
 * actually offers — `payment.provider` from POST /checkout/orders. There is no
 * hardcoded gateway list and no greyed-out «coming soon» row. The commerce API
 * exposes no pre-order payment-config endpoint, so before the order exists the
 * group has nothing truthful to list and is omitted rather than invented.
 *
 * APPLE PAY: الاستثناء الوحيد قبل الطلب. حين تُعلنه `GET /checkout/methods`
 * ويقدر عليه الجهاز (lib/apple-pay.ts — القاعدة نفسها في صفحة المنتج) يُعرض
 * خياران حقيقيّان: البطاقة (mada/VISA/MC — علاماتٌ يعلنها الخادم دائمًا)
 * وApple Pay. نيّة `pay=apple` تختاره مسبقًا؛ فيُرسَل `paymentMethod:
 * apple_pay` مع الطلب، والتبديل بعد إنشائه يطلب رابط الطريقة الأخرى من
 * `/payment`. وبلا الجهاز تُتجاهل النيّة بصمت.
 */
import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { useRouter } from 'next/navigation';
import { formatMoney, formatNumber } from '@tahdheer/design-system/format';
import { TrustStrip, type TrustItem } from '@tahdheer/ui';
import { apiGet, apiGetResource } from '../../lib/api';
import { APPLE_PAY_METHOD, useApplePayAvailable } from '../../lib/apple-pay';
import {
  applePayPreselect,
  cartHasLine,
  consumedSearch,
  parseCheckoutIntent,
  variantIdFor,
  type CheckoutMethods,
} from '../../lib/checkout-intent';
import {
  addCartItem,
  applyCoupon,
  choosePaymentMethod,
  confirmFakePayment,
  createOrder,
  getCart,
  removeCoupon,
  type CartPayload,
  type CheckoutOrderResponse,
  type CommerceFailure,
  type CommerceResult,
} from '../../lib/commerce-api';
import { routes } from '../../lib/routes';
import { emitCartChanged } from '../product/useAddToCart';
import { ConnectionError } from '../search/ConnectionError';
import { TotalsRows, Money } from '../cart/TotalsRows';
import { CartIcon, CheckIcon, DownloadIcon } from '../product/icons';
import { ApplePayIcon } from '../pdp/icons';
import { PaymentMark } from '../chrome/PaymentMark';
import styles from './CheckoutFlow.module.css';

/**
 * `unconfirmed` ليست `declined`. انقطاعُ الشبكة أو انتهاء المهلة أثناء تأكيد
 * الدفع لا يُخوّل المتجر أن ينطق بادّعاءين لا يملكهما: أنّ البنك رفض، وأنّ لا
 * مبلغ خُصم. المجهول يُسمّى مجهولًا ويُحال إلى صفحة الطلب.
 */
type Phase =
  | 'loading'
  | 'failed'
  | 'empty'
  | 'details'
  | 'payment'
  | 'processing'
  | 'declined'
  | 'unconfirmed';

const PHONE_RE = /^5\d{8}$/;
const VAT_RE = /^3\d{13}3$/;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

type FieldKey = 'name' | 'phone' | 'email' | 'orgName' | 'vatNumber' | 'policyConsent';

const FIELD_IDS: Record<FieldKey, string> = {
  name: 'field-name',
  phone: 'field-phone',
  email: 'field-email',
  orgName: 'field-org-name',
  vatNumber: 'field-vat-number',
  policyConsent: 'field-policy-consent',
};

const FIELD_LABELS: Record<FieldKey, string> = {
  name: 'الاسم الكامل',
  phone: 'رقم الجوال',
  email: 'البريد الإلكتروني',
  orgName: 'اسم المنشأة',
  vatNumber: 'الرقم الضريبي',
  policyConsent: 'الموافقة على السياسات',
};

const SEC_CONTACT = 'sec-contact';
const SEC_DELIVERY = 'sec-delivery';
const SEC_PAY = 'sec-pay';
const SEC_REVIEW = 'sec-review';
const TAX_PANEL_ID = 'ck-tax-panel';

/* Stable description targets: the hint element exists whether or not the field
   is in error, so aria-describedby never points at nothing. */
const EMAIL_HINT_ID = 'ck-email-hint';
const PHONE_HINT_ID = 'ck-phone-hint';

/**
 * الطلب المُنشأ في هذه الجلسة — رقمه ورمزه.
 *
 * الخادم يفرّغ السلّة داخل معاملة إنشاء الطلب، ورمز الطلب كان يعيش في ذاكرة
 * React وحدها. فأيّ إعادة تحميلٍ أو رجوعٍ بالمتصفّح إلى ‎/checkout‎ بعد
 * الإنشاء كان يُعيد سلّةً فارغة فتُعرض شاشة «لا يوجد ما يُدفع بعد · سلّتك
 * فارغة» — وهي كذبة: هناك طلبٌ قائم ورمزه هو المفتاح الوحيد إلى ملفّاته،
 * وقد ضاع للتوّ. الجلسة تحفظه، و`useSyncExternalStore` هي بوّابة المتجر
 * الخارجيّ في هذا المستودع.
 */
const SESSION_ORDER_KEY = 'tahdheer:session-order';

function subscribeSessionOrder(onChange: () => void): () => void {
  window.addEventListener('storage', onChange);
  return () => window.removeEventListener('storage', onChange);
}

/** سلسلة نصّية (أو null) — قيمةٌ أوّليّة مستقرّة، فلا تُعيد getSnapshot مرجعًا جديدًا. */
function readSessionOrder(): string | null {
  try {
    return window.sessionStorage.getItem(SESSION_ORDER_KEY);
  } catch {
    // الجلسة قد تكون محجوبة (وضع خاص صارم) — غيابها ليس خطأً يُعرض.
    return null;
  }
}

function serverSessionOrder(): string | null {
  return null;
}

function rememberSessionOrder(orderNumber: string, orderToken: string): void {
  try {
    window.sessionStorage.setItem(SESSION_ORDER_KEY, JSON.stringify({ orderNumber, orderToken }));
  } catch {
    /* الحفظ تحسينٌ لا شرط: فشلُه لا يُفشل الدفع. */
  }
}

/**
 * العلامة تُمحى حين تنتهي مهمّتها.
 *
 * بلا محوٍ تبقى للجلسة كلّها، فزائرٌ أتمّ طلبًا ثمّ أضاف منتجًا ثمّ أفرغ
 * سلّته بيده يرى «أُنشئ طلبك رقم …» على سلّةٍ فارغة لا علاقة لها به — أي
 * تُستبدل كذبةٌ بكذبة. تُمحى حالما تمتلئ السلّة من جديد: عندئذٍ صار للزائر
 * مسار شراءٍ آخر، ولوحةُ الطلب السابق لم تعد جواب هذه الصفحة.
 */
function forgetSessionOrder(): void {
  try {
    window.sessionStorage.removeItem(SESSION_ORDER_KEY);
  } catch {
    /* لا شيء يُعرض على فشل المحو. */
  }
}

function parseSessionOrder(raw: string | null): { orderNumber: string; orderToken: string } | null {
  if (!raw) return null;
  try {
    const value = JSON.parse(raw) as { orderNumber?: unknown; orderToken?: unknown };
    return typeof value.orderNumber === 'string' && typeof value.orderToken === 'string'
      ? { orderNumber: value.orderNumber, orderToken: value.orderToken }
      : null;
  } catch {
    return null;
  }
}

/** Map Laravel 422 error keys (incl. taxProfile.*) onto local field keys. */
function serverKeyToField(key: string): FieldKey | null {
  if (key === 'name' || key === 'phone' || key === 'email' || key === 'policyConsent') return key;
  if (key.endsWith('orgName')) return 'orgName';
  if (key.endsWith('vatNumber')) return 'vatNumber';
  return null;
}

// ------------------------------------------------------------------- icons

/** #i-lock — the encrypted-payment glyph. */
function LockIcon({ className }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      focusable="false"
    >
      <rect x="4.5" y="10.5" width="15" height="10" rx="2.4" />
      <path d="M8.2 10.5V7.6a3.8 3.8 0 0 1 7.6 0v2.9" />
    </svg>
  );
}

/** #i-chev — a start-pointing chevron (rotated to face down when expanded). */
function ChevronIcon({ className }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.4"
      strokeLinecap="round"
      strokeLinejoin="round"
      focusable="false"
    >
      <path d="M14 6l-6 6 6 6" />
    </svg>
  );
}

/** #i-info — the alert/notice glyph. */
function InfoIcon({ className }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.2"
      strokeLinecap="round"
      strokeLinejoin="round"
      focusable="false"
    >
      <circle cx="12" cy="12" r="9" />
      <path d="M12 11v5.2M12 7.7v.1" />
    </svg>
  );
}

/** #i-headset — the support claim in the trust row. */
function HeadsetIcon({ className }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.2"
      strokeLinecap="round"
      strokeLinejoin="round"
      focusable="false"
    >
      <path d="M4.5 15v-3a7.5 7.5 0 0 1 15 0v3" />
      <path d="M4.5 14.2h1.9a1.4 1.4 0 0 1 1.4 1.4v2.6a1.4 1.4 0 0 1-1.4 1.4H5.9a1.4 1.4 0 0 1-1.4-1.4v-4ZM19.5 14.2h-1.9a1.4 1.4 0 0 0-1.4 1.4v2.6a1.4 1.4 0 0 0 1.4 1.4h.5a1.4 1.4 0 0 0 1.4-1.4v-4Z" />
    </svg>
  );
}

/** #i-user — the sign-in nudge glyph. */
function UserIcon({ className }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      focusable="false"
    >
      <circle cx="12" cy="8" r="3.4" />
      <path d="M5.9 19.4a6.2 6.2 0 0 1 12.2 0" />
    </svg>
  );
}

/** #i-refresh — the «إعادة المحاولة» glyph on the declined alert. */
function RefreshIcon({ className }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.2"
      strokeLinecap="round"
      strokeLinejoin="round"
      focusable="false"
    >
      <path d="M20 11a8 8 0 0 0-14-4.5L4 9" />
      <path d="M4 4v5h5" />
      <path d="M4 13a8 8 0 0 0 14 4.5L20 15" />
      <path d="M20 20v-5h-5" />
    </svg>
  );
}

/** The CTA's busy glyph — an arc that spins (frozen by prefers-reduced-motion). */
function SpinnerIcon({ className }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.4"
      strokeLinecap="round"
      strokeDasharray="44"
      strokeDashoffset="14"
      focusable="false"
    >
      <circle cx="12" cy="12" r="9" />
    </svg>
  );
}

// ------------------------------------------------------------- progress bar

interface StepState {
  label: string;
  /** Screen-reader suffix that names *why* the step counts as done. */
  sr: string;
  done: boolean;
}

/**
 * Completion is derived from the data, never from the phase: step 1 is done once
 * both contact fields validate, step 2 only once the shopper has actually chosen
 * a method — a preselected default is not a decision the shopper made.
 */
function Progress({ contactDone, methodDone }: { contactDone: boolean; methodDone: boolean }) {
  const steps: StepState[] = [
    { label: 'بياناتك', sr: ' — مكتملة', done: contactDone },
    { label: 'الدفع', sr: ' — مختارة', done: methodDone },
    { label: 'التأكيد', sr: '', done: false },
  ];
  const current = steps.findIndex((step) => !step.done);

  return (
    <nav className={styles.steps} aria-label="مراحل إتمام الطلب">
      <ol className={styles.stepList}>
        {steps.map((step, index) => (
          <li
            key={step.label}
            className={`${styles.step} ${step.done ? styles.stepDone : ''}`}
            aria-current={index === current ? 'step' : undefined}
          >
            <span className={styles.stepMain}>
              <span className={styles.stepDot} aria-hidden="true">
                {step.done ? '✓' : index + 1}
              </span>
              <span className={styles.stepLabel}>
                {step.label}
                {step.done ? <span className={styles.srOnly}>{step.sr}</span> : null}
              </span>
            </span>
            {/* Connector to the next step — the last item has none. */}
            <span className={styles.stepBar} aria-hidden="true" />
          </li>
        ))}
      </ol>
    </nav>
  );
}

// ---------------------------------------------------------- payment methods

interface PaymentOption {
  id: string;
  label: string;
  desc: string;
  /** Short provider mark — the design's logo slot; no vendor artwork is faked. */
  logo: string;
}

/**
 * Arabic labels for the providers the backend can return (`fake`, `tap`).
 * This is localisation, NOT an offer list: a provider only reaches the UI when
 * the checkout response names it, and an unknown name renders verbatim.
 */
const PROVIDER_COPY: Record<string, { label: string; desc: string; logo: string }> = {
  tap: {
    label: 'بطاقة مدى أو ائتمانية',
    desc: 'تُعالَج البيانات عند مزوّد الدفع Tap داخل صفحته الآمنة',
    logo: 'Tap',
  },
  fake: {
    label: 'دفع تجريبي — بيئة تطوير',
    desc: 'لا يُخصم أي مبلغ حقيقي. متاح في بيئة التطوير وحدها.',
    logo: 'DEV',
  },
};

function toPaymentOption(provider: string): PaymentOption {
  const copy = PROVIDER_COPY[provider];
  return copy ? { id: provider, ...copy } : { id: provider, label: provider, desc: '', logo: provider };
}

/**
 * خيار البطاقة قبل إنشاء الطلب — لا يُعرض إلّا بجوار Apple Pay (فلا خيار
 * واحد مُلزِم). بعد الطلب يحلّ محلّه خيار المزوّد الذي سمّاه الخادم.
 */
const CARD_OPTION: PaymentOption = {
  id: 'card',
  label: 'بطاقة مدى أو ائتمانية',
  desc: 'تُعالَج البيانات عند مزوّد الدفع داخل صفحته الآمنة',
  logo: 'mada',
};

const APPLE_PAY_OPTION: PaymentOption = {
  id: APPLE_PAY_METHOD,
  label: 'Apple Pay',
  desc: 'تُكمل الدفع من جهازك داخل صفحة مزوّد الدفع الآمنة',
  logo: 'Apple Pay',
};

const APPLE_PAY_UNAVAILABLE = 'الدفع عبر Apple Pay غير متاح حاليًّا';

// ------------------------------------------------------------ order summary

/*
 * قناة الدعم تُعرض حين توجد وحدها. كان عنصر «دعم واتساب» ثابتًا في المصفوفة
 * فيُصيَّر داخل ملخّص الطلب — أعلى سطحٍ حساسيةً في المتجر — بلا قراءة
 * `NEXT_PUBLIC_WHATSAPP_NUMBER`، بينما ‎/contact‎ يقول «لا توجد قناة تواصل
 * مُفعّلة بعد». هذا هو النمط نفسه المتّبع في CartView.
 */
const TRUST_ITEMS: TrustItem[] = [
  { id: 'secure', title: 'دفع آمن عبر مزوّد مُرخَّص', icon: <LockIcon /> },
  { id: 'no-fees', title: 'لا رسوم مخفية', icon: <CheckIcon /> },
  ...(process.env.NEXT_PUBLIC_WHATSAPP_NUMBER
    ? [{ id: 'support', title: 'دعم واتساب', icon: <HeadsetIcon /> }]
    : []),
];

const POLICY_LINKS: { label: string; href: string }[] = [
  { label: 'سياسة الاسترجاع', href: routes.policy('refund') },
  { label: 'سياسة الخصوصية', href: routes.policy('privacy') },
  { label: 'الشروط والأحكام', href: routes.policy('terms') },
  { label: 'تواصل معنا', href: routes.contact },
];

interface OrderSummaryProps {
  /** Two summaries render (top-collapsible on mobile, aside on desktop) — ids must not collide. */
  idPrefix: string;
  cart: CartPayload;
  couponOpen: boolean;
  onToggleCoupon: () => void;
  couponInput: string;
  onCouponInput: (value: string) => void;
  onApplyCoupon: () => void;
  onRemoveCoupon: () => void;
  couponBusy: boolean;
  couponError: string | null;
}

function OrderSummary({
  idPrefix,
  cart,
  couponOpen,
  onToggleCoupon,
  couponInput,
  onCouponInput,
  onApplyCoupon,
  onRemoveCoupon,
  couponBusy,
  couponError,
}: OrderSummaryProps) {
  const applied = cart.coupon;
  const inputId = `${idPrefix}-coupon`;
  const msgId = `${idPrefix}-coupon-msg`;
  const bodyId = `${idPrefix}-coupon-body`;
  const saving = formatMoney(cart.totals.couponDiscountMinor);

  return (
    <div className={styles.summaryBody}>
      <ul className={styles.summaryItems} role="list">
        {cart.items.map((line) => (
          <li key={line.id} className={styles.summaryItem}>
            {/* Decorative tile — CartLine carries no image field, so nothing is faked inside it. */}
            <span className={styles.summaryThumb} aria-hidden="true">
              <span className={styles.summaryQty}>
                <bdi dir="ltr">{formatNumber(line.qty)}</bdi>
              </span>
            </span>

            <span className={styles.summaryItemMain}>
              <span className={styles.summaryItemTitle}>{line.title}</span>
              <span className={styles.summaryItemMeta}>
                {line.format ? <>{line.format} · </> : null}
                الكمية <bdi dir="ltr">{formatNumber(line.qty)}</bdi>
              </span>
            </span>

            <span className={styles.summaryItemPrice}>
              <b className={styles.summaryItemLine}>
                <Money minor={line.priceMinor} />
              </b>
              {line.regularMinor !== undefined && line.regularMinor > line.priceMinor ? (
                <del className={styles.summaryItemWas}>
                  <bdi dir="ltr">{formatMoney(line.regularMinor).amount}</bdi>
                </del>
              ) : null}
            </span>
          </li>
        ))}
      </ul>

      <div className={styles.couponBox}>
        <button
          type="button"
          className={styles.couponToggle}
          aria-expanded={couponOpen}
          aria-controls={bodyId}
          onClick={onToggleCoupon}
        >
          <ChevronIcon className={`${styles.couponChevron} ${couponOpen ? styles.chevronOpen : ''}`} />
          لديك رمز خصم؟
        </button>

        <div id={bodyId} className={styles.couponFields} hidden={!couponOpen}>
          <div className={styles.couponRow}>
            <label className={styles.srOnly} htmlFor={inputId}>
              رمز الخصم
            </label>
            <input
              id={inputId}
              className={styles.couponInput}
              type="text"
              dir="ltr"
              autoComplete="off"
              value={applied ? applied.code : couponInput}
              onChange={(e) => onCouponInput(e.target.value.toUpperCase())}
              readOnly={applied !== null}
              aria-invalid={couponError !== null || undefined}
              aria-describedby={applied || couponError ? msgId : undefined}
            />
            <button
              type="button"
              className={styles.couponApply}
              onClick={applied ? onRemoveCoupon : onApplyCoupon}
              disabled={couponBusy || (!applied && couponInput.trim() === '')}
            >
              {applied ? 'إزالة' : 'تطبيق'}
            </button>
          </div>

          {applied || couponError ? (
            <div
              id={msgId}
              role="status"
              aria-live="polite"
              className={`${styles.couponMsg} ${applied ? styles.couponMsgOk : styles.couponMsgBad}`}
            >
              {applied ? <CheckIcon className={styles.couponMsgIcon} /> : <InfoIcon className={styles.couponMsgIcon} />}
              <span className={styles.couponMsgText}>
                {applied ? (
                  <>
                    طُبِّق الخصم <bdi dir="ltr">{applied.code}</bdi> — وفّرت{' '}
                    <bdi dir="ltr">{saving.amount}</bdi> {saving.currency} على هذا الطلب.
                  </>
                ) : (
                  couponError
                )}
              </span>
            </div>
          ) : null}
        </div>
      </div>

      {/*
       * كانت صفوف الإجماليات تُصيَّر عاريةً هنا فتلتصق بحافّتَي البطاقة، وكانت
       * بتشكيل `default` — أي إجماليٌّ نهائيٌّ بحجم ‎17px‎ في أهمّ رقمٍ على
       * الشاشة، بينما السلّة تعرض الرقم نفسه بحجم ‎26px‎. الغلاف يعيدها إلى
       * شبكة البطاقة، و`hero` يجعل السلّة والدفع يعرضان الإجمالي بالهيئة ذاتها.
       * تشكيل `default` يبقى كما هو لصفحة الطلب التي لا تملك هذه الشاشة حقّ
       * إعادة رسمها.
       */}
      <div className={styles.summaryTotals}>
        <TotalsRows totals={cart.totals} coupon={cart.coupon} variant="hero" />
      </div>

      <p className={styles.summaryNote}>
        الأسعار شاملة ضريبة القيمة المضافة · كل المنتجات رقمية وتصلك فور الدفع
      </p>
    </div>
  );
}

// ---------------------------------------------------------------- the flow

export function CheckoutFlow() {
  const [phase, setPhase] = useState<Phase>('loading');
  const [cart, setCart] = useState<CartPayload | null>(null);
  const [cartFailure, setCartFailure] = useState<CommerceFailure>('network');
  /** رسالة الخادم عند فشل جلب السلّة (كالحدّ ٤٢٩ ومهلته) — تُعرض لا تُبتلع. */
  const [cartMessage, setCartMessage] = useState<string | null>(null);
  const [order, setOrder] = useState<CheckoutOrderResponse | null>(null);

  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [needTax, setNeedTax] = useState(false);
  const [orgName, setOrgName] = useState('');
  const [vatNumber, setVatNumber] = useState('');
  const [policyConsent, setPolicyConsent] = useState(false);
  const [marketingOptIn, setMarketingOptIn] = useState(false);
  /*
   * §٢٦ §٣٢ — عنوان الشحن، يُطلب حين تُعلن السلّة أنّها تحتاجه لا قبل ذلك.
   *
   * كانت هذه الشاشة تقول «تحميل فوري — الطريقة الوحيدة» عن كلّ سلّة، وهو
   * صحيحٌ حين كانت بوّابة السلّة ترفض كلّ ما ليس رقميًّا. وبعد رفعها صار
   * الطلب الماديّ ممكنًا، فيُدفَع ثمنُه ثمّ تقف شحنتُه عند «لا عنوان».
   */
  const [shipCity, setShipCity] = useState('');
  const [shipDistrict, setShipDistrict] = useState('');
  const [shipStreet, setShipStreet] = useState('');
  const [shipRecipient, setShipRecipient] = useState('');
  const [shipNotes, setShipNotes] = useState('');

  const [touched, setTouched] = useState<Partial<Record<FieldKey, boolean>>>({});
  const [submitted, setSubmitted] = useState(false);
  const [serverErrors, setServerErrors] = useState<Partial<Record<FieldKey, string>>>({});
  const [connectionFailed, setConnectionFailed] = useState(false);
  /** خطأ خادمٍ لا يقابل حقلًا — يُعرض عامًّا بدل أن يُبتلع. */
  const [submitError, setSubmitError] = useState<string | null>(null);
  const sessionOrder = parseSessionOrder(
    useSyncExternalStore(subscribeSessionOrder, readSessionOrder, serverSessionOrder),
  );

  const [summaryOpen, setSummaryOpen] = useState(false);
  const [couponOpen, setCouponOpen] = useState(false);
  const [couponInput, setCouponInput] = useState('');
  const [couponBusy, setCouponBusy] = useState(false);
  const [couponError, setCouponError] = useState<string | null>(null);

  const [method, setMethod] = useState<string | null>(null);
  /* تبديل طريقة الدفع لطلبٍ قائم جارٍ — والإرسال ينتظره. */
  const [switching, setSwitching] = useState(false);
  const [methodError, setMethodError] = useState<string | null>(null);
  const switchSeq = useRef(0);
  const [intentPay] = useState(() => parseCheckoutIntent(typeof window === 'undefined' ? '' : window.location.search).pay);

  const router = useRouter();

  // setState happens inside the promise callback (async), never synchronously
  // within the effect body.
  const applyCart = useCallback((res: CommerceResult<CartPayload>) => {
    const next = res.data;
    if (!next) {
      setCartFailure(res.failure ?? 'network');
      setCartMessage(res.message ?? null);
      setPhase('failed');
      return;
    }
    setCart(next);
    /* سلّةٌ ممتلئة ⇒ مسار شراءٍ جديد، فعلامةُ الطلب السابق لم تعد جوابًا. */
    if (next.items.length > 0) forgetSessionOrder();
    // A coupon refresh must not knock a created order back to the details step.
    setPhase((prev) =>
      next.items.length === 0 ? 'empty' : prev === 'loading' || prev === 'failed' ? 'details' : prev,
    );
  }, []);

  /*
   * نيّة صفحة المنتج (`?item=&fmt=&pay=apple` — lib/checkout-intent.ts):
   * تُقرأ مرّةً عند التركيب. إن لم تحمل السلّة السطر (مسار بلا JS: الرابط
   * وحده وصل) يُضاف عبر واجهة السلّة نفسها قبل العرض، ثمّ يُحذف `item`/`fmt`
   * من العنوان كي لا يُعاد عند التحديث. `pay` يبقى حتّى يُنشأ الطلب.
   */
  const intentRef = useRef(parseCheckoutIntent(typeof window === 'undefined' ? '' : window.location.search));
  const [methods, setMethods] = useState<CheckoutMethods | null>(null);

  useEffect(() => {
    const intent = intentRef.current;
    let alive = true;
    void getCart().then(async (res) => {
      if (!alive) return;
      const item = intent.item;
      if (!res.data || !item) {
        applyCart(res);
        return;
      }
      const product = await apiGetResource<{
        id: number;
        variants?: { id: number; slug: string; format?: string | null; purchasable: boolean }[] | null;
      }>(`/api/v1/products/${encodeURIComponent(item)}`);
      if (!alive) return;
      const variantId = variantIdFor(product.data?.variants ?? [], intent.format);
      const needsAdd =
        product.data !== null &&
        !cartHasLine(res.data.items, product.data.id, variantId) &&
        (intent.format === null || variantId !== null);
      const added = needsAdd && product.data ? await addCartItem(product.data.id, variantId) : null;
      if (!alive) return;
      if (added?.data) emitCartChanged();
      window.history.replaceState(
        null,
        '',
        `${window.location.pathname}${consumedSearch(window.location.search)}${window.location.hash}`,
      );
      /* فشلُ الإضافة لا يُسقط الصفحة: تُعرض السلّة كما هي. */
      applyCart(added?.data ? added : res);
    });
    /*
     * علامات الدفع (mada · VISA · MC …) من الخادم لا من قائمةٍ مكتوبة — الصفّ
     * نفسه الذي ترسمه لوحة الشراء والتذييل؛ ونيّة Apple Pay تُقرأ منه أيضًا.
     */
    void apiGet<CheckoutMethods>('/api/v1/checkout/methods', { cache: 'no-store' }).then((res) => {
      if (alive && res.data) setMethods(res.data);
    });
    return () => {
      alive = false;
    };
  }, [applyCart]);

  const retry = () => {
    setPhase('loading');
    void getCart().then(applyCart);
  };

  // Client-side validation errors (server 422 messages override verbatim).
  const errors: Partial<Record<FieldKey, string>> = {};
  if (name.trim() === '') errors.name = 'اكتب اسمك الكامل';
  if (!PHONE_RE.test(phone))
    errors.phone = 'رقم سعودي من 9 أرقام يبدأ بـ 5، بدون صفر ولا مفتاح الدولة';
  if (!EMAIL_RE.test(email)) errors.email = 'اكتب بريدًا إلكترونيًا صحيحًا';
  if (needTax && orgName.trim() === '') errors.orgName = 'اكتب اسم المنشأة';
  if (needTax && !VAT_RE.test(vatNumber))
    errors.vatNumber = 'الرقم الضريبي 15 رقمًا يبدأ وينتهي بـ 3';
  if (!policyConsent) errors.policyConsent = 'الموافقة على السياسات مطلوبة قبل المتابعة';
  for (const [key, message] of Object.entries(serverErrors)) {
    if (message) errors[key as FieldKey] = message;
  }

  const errorKeys = (Object.keys(errors) as FieldKey[]).filter((k) => errors[k]);
  const showFieldError = (key: FieldKey) => Boolean((touched[key] || submitted) && errors[key]);
  const markTouched = (key: FieldKey) => setTouched((prev) => ({ ...prev, [key]: true }));

  /* The summary lists what the shopper can already see is wrong — one blur is
     enough, it does not wait for a submit attempt. */
  const visibleErrorKeys = errorKeys.filter(showFieldError);

  /* Step 1 is «مكتملة» when the two fields the files depend on both validate. */
  const contactDone = !errors.email && !errors.phone;

  /* Only what the checkout response offers — never a catalogue of gateways. */
  const applePay = useApplePayAvailable(methods?.applePay === true);
  const baseOption = order ? toPaymentOption(order.payment.provider) : applePay ? CARD_OPTION : null;
  const paymentOptions: PaymentOption[] = baseOption
    ? applePay
      ? [baseOption, APPLE_PAY_OPTION]
      : [baseOption]
    : [];
  /* نيّة `pay=apple` اختيارٌ مسبق — لا يقع إلّا حين يكون الخيار معروضًا فعلًا. */
  const wanted = method ?? (applePayPreselect(intentPay, applePay) ? APPLE_PAY_METHOD : null);
  const selectedMethod =
    wanted !== null && paymentOptions.some((o) => o.id === wanted)
      ? wanted
      : paymentOptions[0]?.id ?? null;
  const selectedOption = paymentOptions.find((o) => o.id === selectedMethod) ?? null;
  const appleChosen = selectedMethod === APPLE_PAY_METHOD;
  /* `method` is only ever set from a click or an arrow key (or the pay=apple
     intent), so a non-null value means the choice is made — the bare fallback
     does not count. */
  const methodDone = wanted !== null && paymentOptions.some((o) => o.id === wanted);

  const processing = phase === 'processing';
  /* صفّ العلامات من `GET /checkout/methods` وحده — لا قائمة مكتوبة. */
  const marks = (methods?.marks ?? []).filter((mark) => typeof mark === 'string' && mark.trim() !== '');
  /* ما تطلبه السلّة — من الخادم. غيابُ المفتاح يعني «لا شيء إضافيّ». */
  const requirements = cart?.requirements;
  const needsAddress = requirements?.requiresShippingAddress === true;
  const addressMissing = needsAddress && shipCity.trim() === '';

  const blocked = errorKeys.length > 0 || addressMissing;

  /* ARIA radiogroup: one tab stop, arrows move (and select) within it. */
  const methodRefs = useRef<(HTMLButtonElement | null)[]>([]);
  const onMethodKeyDown = (e: React.KeyboardEvent<HTMLButtonElement>, index: number) => {
    const forward = e.key === 'ArrowDown' || e.key === 'ArrowLeft'; // RTL: start→end is leftwards
    const back = e.key === 'ArrowUp' || e.key === 'ArrowRight';
    if (!forward && !back) return;
    e.preventDefault();
    const count = paymentOptions.length;
    const next = (index + (forward ? 1 : -1) + count) % count;
    const option = paymentOptions[next];
    if (!option) return;
    chooseMethod(option.id);
    methodRefs.current[next]?.focus();
  };

  /**
   * اختيار الطريقة. قبل الطلب يكفي الاختيار (يُرسَل مع الإنشاء)؛ وبعده يطلب
   * رابط الطريقة الجديدة من `/payment` — لكلّ طريقةٍ نيّتها عند الخادم، فلا
   * يُعاد رابط الأخرى. آخر اختيارٍ هو الذي يُعتمد (`switchSeq`).
   */
  function chooseMethod(id: string) {
    setMethod(id);
    setMethodError(null);
    if (!order) return;
    const want = id === APPLE_PAY_METHOD ? APPLE_PAY_METHOD : null;
    if ((order.payment.method ?? null) === want) return;
    const seq = ++switchSeq.current;
    const current = order;
    setSwitching(true);
    void choosePaymentMethod(current.orderToken, want).then((res) => {
      if (seq !== switchSeq.current) return;
      setSwitching(false);
      if (res.data) {
        setOrder({ ...current, payment: res.data.payment });
        return;
      }
      /* رفضٌ أو انقطاع: يعود الاختيار إلى ما يملك الطلبُ رابطَه فعلًا. */
      setMethod(want !== null && res.status === 422 ? current.payment.provider : current.payment.method ?? current.payment.provider);
      setMethodError(
        res.errors?.paymentMethod?.[0] ?? res.message ?? (res.status === 422 ? APPLE_PAY_UNAVAILABLE : 'تعذّر الاتصال بالخادم — أعد المحاولة'),
      );
    });
  }

  const pay = useCallback(
    async (target: CheckoutOrderResponse) => {
      if (target.payment.payUrl) {
        window.location.assign(target.payment.payUrl);
        return;
      }
      if (target.payment.provider === 'fake') {
        const res = await confirmFakePayment(target.orderToken);
        if (res.status >= 200 && res.status < 300) {
          router.push(`/orders/${encodeURIComponent(target.orderToken)}`);
          return;
        }
        // انقطاعٌ أو مهلة ⇒ لا نعلم. رفضٌ صريح من المزوّد ⇒ declined.
        setPhase(res.status === 0 ? 'unconfirmed' : 'declined');
        return;
      }
      // Provider named but no payable route — say so instead of spinning forever.
      setPhase('payment');
    },
    [router],
  );

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitted(true);
    setConnectionFailed(false);
    setSubmitError(null);
    if (blocked || processing || switching) return;

    if (order) {
      setPhase('processing');
      await pay(order);
      return;
    }

    setPhase('processing');
    setServerErrors({});
    const res = await createOrder({
      name: name.trim(),
      phone,
      email: email.trim(),
      taxProfile: needTax ? { orgName: orgName.trim(), vatNumber } : null,
      policyConsent: true,
      marketingOptIn,
      /*
       * يُرسَل حين تطلبه السلّة وحدها. إرسالُه دائمًا كان سيجمع عنوانًا من
       * مشتري ملفٍّ رقميّ — وجمعُ ما لا يلزم مسألةُ خصوصيّة لا ذوق.
       */
      ...(needsAddress
        ? {
            shippingAddress: {
              city: shipCity.trim(),
              ...(shipDistrict.trim() === '' ? {} : { district: shipDistrict.trim() }),
              ...(shipStreet.trim() === '' ? {} : { street: shipStreet.trim() }),
              ...(shipRecipient.trim() === '' ? {} : { recipientName: shipRecipient.trim() }),
              ...(shipNotes.trim() === '' ? {} : { notes: shipNotes.trim() }),
            },
          }
        : {}),
      /* يُرسَل حين يُختار Apple Pay وحده — وإلّا تبقى الحمولة كما كانت. */
      ...(appleChosen ? { paymentMethod: APPLE_PAY_METHOD } : {}),
    });
    if (res.data) {
      setOrder(res.data);
      /* الخيار المعروض يطابق ما أُنشئت له النيّة. */
      setMethod(res.data.payment.method === APPLE_PAY_METHOD ? APPLE_PAY_METHOD : res.data.payment.provider);
      rememberSessionOrder(res.data.orderNumber, res.data.orderToken);
      await pay(res.data);
      return;
    }
    setPhase('details');
    /* Apple Pay رُفض (الخادم لم يعد يقبله): يعود الاختيار إلى البطاقة وتُقال الرسالة — لا سقوط صامت. */
    const appleRefused = res.status === 422 ? res.errors?.paymentMethod?.[0] : undefined;
    if (appleRefused) {
      setMethod(CARD_OPTION.id);
      setMethodError(appleRefused);
      return;
    }
    if (res.status === 422 && res.errors) {
      const mapped: Partial<Record<FieldKey, string>> = {};
      const unmapped: string[] = [];
      for (const [key, messages] of Object.entries(res.errors)) {
        const field = serverKeyToField(key);
        if (field && messages[0]) mapped[field] = messages[0];
        else if (messages[0]) unmapped.push(messages[0]);
      }
      setServerErrors(mapped);
      /*
       * مفاتيح الخادم `cart` و`coupon` و`inventory` لا تقابل حقلًا في هذا
       * النموذج، فكان `mapped` يخرج فارغًا ويعود التنفيذ بلا أيّ عرض: الزرّ
       * يعود مفعَّلًا بالنصّ نفسه ولا شيء يحدث مهما ضُغط، ورسالة الخادم
       * العربية («أحد المنتجات لم يعد متاحًا» · «المتاح ٢ والمطلوب ٥») تُرمى.
       * لا خطأ يُبتلع: ما لا يجد حقلًا يُعرض عامًّا.
       */
      if (Object.keys(mapped).length === 0) {
        setSubmitError(unmapped[0] ?? res.message ?? 'تعذّر إنشاء الطلب — راجع سلّتك وأعد المحاولة.');
      }
      return;
    }
    if (res.status !== 0 && (res.message ?? '') !== '') {
      setSubmitError(res.message as string);
      return;
    }
    setConnectionFailed(true);
  };

  const runCoupon = async (run: () => Promise<CommerceResult<CartPayload>>) => {
    setCouponBusy(true);
    setCouponError(null);
    const res = await run();
    setCouponBusy(false);
    if (res.data) {
      applyCart(res);
      emitCartChanged();
      setCouponInput('');
      return;
    }
    setCouponError(res.message ?? 'تعذّر تطبيق الرمز — لم يتغيّر إجماليك.');
  };

  if (phase === 'loading') {
    return (
      <div className={styles.loading} role="status">
        <span className={styles.loadingSpinner} aria-hidden="true" />
        جارٍ تحميل بيانات الطلب…
      </div>
    );
  }

  if (phase === 'failed' || !cart) {
    return (
      <div className={styles.failWrap}>
        <ConnectionError onRetry={retry} failure={cartFailure} />
        {cartMessage ? (
          <p className={styles.failNote} role="status">
            {cartMessage}
          </p>
        ) : null}
      </div>
    );
  }

  // Empty-cart check — refuse to render a payable zero.
  if (phase === 'empty') {
    // سلّةٌ فارغة بعد إنشاء طلبٍ في هذه الجلسة ليست «لا يوجد ما يُدفع».
    if (sessionOrder) {
      return (
        <div className={styles.empty}>
          <span className={styles.emptyTile} aria-hidden="true">
            <CartIcon className={styles.emptyTileIcon} />
          </span>
          <h2 className={styles.emptyTitle}>
            أُنشئ طلبك رقم <bdi dir="ltr">{sessionOrder.orderNumber}</bdi>
          </h2>
          {/* لا ادّعاء عن حالة الدفع هنا: الصفحة لا تعرفها، وصفحة الطلب تعرفها. */}
          <p className={styles.emptyText}>
            انتقلت سلّتك إلى هذا الطلب، فصارت فارغة. افتح صفحة الطلب لمعرفة حالته وملفّاته —
            رابطها هو مفتاح ملفّاتك، فاحفظه.
          </p>
          <a
            className={styles.emptyCta}
            href={`/orders/${encodeURIComponent(sessionOrder.orderToken)}`}
          >
            افتح صفحة طلبك
          </a>
        </div>
      );
    }

    return (
      <div className={styles.empty}>
        <span className={styles.emptyTile} aria-hidden="true">
          <CartIcon className={styles.emptyTileIcon} />
        </span>
        {/* h2, not the design's h1: the route keeps its own «إتمام الطلب» h1
            because the full site chrome stays mounted here. */}
        <h2 className={styles.emptyTitle}>لا يوجد ما يُدفع بعد</h2>
        <p className={styles.emptyText}>
          سلّتك فارغة، فلا حاجة لإكمال الدفع. تصفّح المكتبة واختر ما يناسب صفّك.
        </p>
        <a className={styles.emptyCta} href={routes.shop}>
          تصفّح المنتجات
        </a>
      </div>
    );
  }

  const total = formatMoney(cart.totals.totalMinor);
  const ctaLabel = processing ? 'جارٍ تأكيد طلبك بأمان…' : `ادفع ${total.amount} ${total.currency} وأكمل الطلب`;
  const ctaShort = processing ? 'جارٍ التأكيد…' : `ادفع ${total.amount} ${total.currency}`;
  const ctaNote = processing
    ? 'لا تُحدّث الصفحة ولا تضغط مرة أخرى — قد يستغرق التأكيد حتى 30 ثانية.'
    : blocked
      ? !policyConsent
        ? 'وافق على الشروط وسياسة الاسترجاع لتتمكّن من إتمام الطلب.'
        : 'أصلح الحقول المميّزة أعلاه لتتمكّن من إتمام الطلب.'
      : selectedOption
        ? `بالضغط يُنشأ الطلب ويُخصم المبلغ عبر ${selectedOption.label}. تُعرض روابط التحميل فورًا بعد نجاح الدفع.`
        : 'بالضغط يُنشأ الطلب ويُخصم المبلغ عبر مزوّد الدفع المُفعَّل للمتجر. تُعرض روابط التحميل فورًا بعد نجاح الدفع.';

  const summaryProps = {
    cart,
    couponOpen,
    onToggleCoupon: () => setCouponOpen((v) => !v),
    couponInput,
    onCouponInput: setCouponInput,
    onApplyCoupon: () => void runCoupon(() => applyCoupon(couponInput.trim())),
    onRemoveCoupon: () => void runCoupon(() => removeCoupon()),
    couponBusy,
    couponError,
  };

  const reviewRows: { label: string; value: React.ReactNode; href: string }[] = [
    ...(name.trim() !== '' ? [{ label: 'الاسم', value: name.trim(), href: `#${FIELD_IDS.name}` }] : []),
    ...(email.trim() !== ''
      ? [
          {
            label: 'البريد',
            value: <bdi dir="ltr">{email.trim()}</bdi>,
            href: `#${FIELD_IDS.email}`,
          },
        ]
      : []),
    {
      label: 'التسليم',
      value: cart?.requirements?.requiresShippingAddress === true
        ? 'شحن إلى عنوانك'
        : 'تحميل فوري بعد الدفع',
      href: `#${SEC_DELIVERY}`,
    },
    ...(selectedOption
      ? [{ label: 'وسيلة الدفع', value: selectedOption.label, href: `#${SEC_PAY}` }]
      : []),
    {
      label: 'الإجمالي',
      value: (
        <>
          <bdi dir="ltr">{total.amount}</bdi> {total.currency} (شامل الضريبة)
        </>
      ),
      href: routes.cart,
    },
  ];

  const ctaGlyph = processing ? (
    <SpinnerIcon className={styles.ctaSpinner} />
  ) : (
    <LockIcon className={styles.ctaGlyph} />
  );

  return (
    <div className={styles.flow}>
      <Progress contactDone={contactDone} methodDone={methodDone} />

      {/* Mobile-only: the total stays visible while the summary is collapsed. */}
      <section className={styles.summaryTop} aria-label="ملخّص الطلب">
        <button
          type="button"
          className={styles.summaryToggle}
          aria-expanded={summaryOpen}
          aria-controls="ck-summary-top"
          onClick={() => setSummaryOpen((v) => !v)}
        >
          <CartIcon className={styles.summaryToggleIcon} />
          <span className={styles.summaryToggleLabel}>
            ملخّص الطلب <span className={styles.summaryToggleCount}>({formatNumber(cart.count)})</span>
          </span>
          <b className={styles.summaryToggleTotal}>
            <bdi dir="ltr">{total.amount}</bdi>{' '}
            <span className={styles.summaryToggleCurrency}>{total.currency}</span>
          </b>
          <ChevronIcon
            className={`${styles.summaryToggleChevron} ${summaryOpen ? styles.chevronOpen : ''}`}
          />
        </button>
        <div id="ck-summary-top" className={styles.summaryTopBody} hidden={!summaryOpen}>
          <OrderSummary idPrefix="ck-top" {...summaryProps} />
        </div>
      </section>

      <form onSubmit={(e) => void handleSubmit(e)} noValidate>
        <div className={styles.layout}>
          <main className={styles.mainCol}>
            {visibleErrorKeys.length > 0 ? (
              <div className={styles.errorSummary} role="alert">
                <p className={styles.errorSummaryTitle}>
                  <InfoIcon className={styles.errorSummaryIcon} />
                  تحقّق من <bdi dir="ltr">{formatNumber(visibleErrorKeys.length)}</bdi> حقل قبل
                  المتابعة
                </p>
                <ul className={styles.errorSummaryList}>
                  {visibleErrorKeys.map((key) => (
                    <li key={key}>
                      <a href={`#${FIELD_IDS[key]}`}>{FIELD_LABELS[key]}</a> — {errors[key]}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}

            <section className={styles.section} id={SEC_CONTACT} aria-labelledby={`${SEC_CONTACT}-h`}>
              <div className={styles.sectionIntro}>
                <div className={styles.sectionHead}>
                  <span
                    className={`${styles.sectionBadge} ${contactDone ? styles.sectionBadgeDone : ''}`}
                    aria-hidden="true"
                  >
                    {contactDone ? '✓' : '1'}
                  </span>
                  <h2 className={styles.sectionTitle} id={`${SEC_CONTACT}-h`}>
                    بياناتك
                  </h2>
                  {contactDone ? (
                    <span className={styles.sectionDone}>
                      <CheckIcon className={styles.sectionDoneIcon} />
                      مكتمل
                    </span>
                  ) : null}
                </div>
                {/*
                  كان هنا «نحتاج بريدك لإرسال الملفات والفاتورة» — ولا مُرسِل
                  بريد في الخلفية ولا مولّد فواتير. والبريد يُطلب فعلًا لسببٍ
                  حقيقيّ: منه يُشتقّ رمز الطلب (OrderToken::make على
                  `number|email`)، فهو نصفُ مفتاح استرجاع الطلب.
                */}
                <p className={styles.sectionLead}>
                  نحتاج بريدك لربط الطلب بك ولاسترجاعه عند الحاجة، ورقم جوالك لتحديثات الطلب. لا
                  نطلب عنوانًا — المنتجات رقمية.
                </p>
              </div>

              {/*
                كان هنا «سجّل الدخول لتُملأ بياناتك تلقائيًا» — ولا جلسة عميل في
                الخادم تملأ شيئًا. يبقى السطر معلومةً صادقة بلا زرّ.
              */}
              <p className={styles.signIn}>
                <UserIcon className={styles.signInIcon} />
                <span className={styles.signInText}>
                  لا تحتاج حسابًا — طلبك يُحفظ برابطه، ومنه تُحمّل ملفاتك.
                </span>
              </p>

              <div className={styles.fieldGrid}>
                <div className={styles.field}>
                  <label className={styles.label} htmlFor={FIELD_IDS.name}>
                    الاسم الكامل
                  </label>
                  <input
                    id={FIELD_IDS.name}
                    className={styles.input}
                    type="text"
                    /*
                     * الحقل مطلوبٌ في قواعد التحقّق أعلاه (`errors.name`)، ولم
                     * يكن معلَنًا كذلك — فيسمعه قارئ الشاشة اختياريًّا حتّى
                     * يُخطئ. و`required` هنا لا تُشغّل فقاعة المتصفّح: النموذج
                     * `noValidate`، والتحقّق كلّه للتطبيق.
                     */
                    required
                    autoComplete="name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    onBlur={() => markTouched('name')}
                    aria-invalid={showFieldError('name') || undefined}
                    aria-describedby={showFieldError('name') ? 'error-name' : undefined}
                  />
                  {showFieldError('name') ? (
                    <p id="error-name" className={styles.fieldError}>
                      {errors.name}
                    </p>
                  ) : null}
                </div>

                <div className={styles.field}>
                  <label className={styles.label} htmlFor={FIELD_IDS.phone}>
                    رقم الجوال
                  </label>
                  <div
                    className={`${styles.phoneRow} ${
                      showFieldError('phone') ? styles.phoneRowInvalid : ''
                    }`}
                  >
                    <span className={styles.phonePrefix} aria-hidden="true">
                      <bdi dir="ltr">+966</bdi>
                    </span>
                    <input
                      id={FIELD_IDS.phone}
                      className={`${styles.input} ${styles.phoneInput}`}
                      type="tel"
                      required
                      dir="ltr"
                      inputMode="numeric"
                      autoComplete="tel-national"
                      maxLength={9}
                      placeholder="5XXXXXXXX"
                      value={phone}
                      onChange={(e) => setPhone(e.target.value.replace(/\D/g, '').slice(0, 9))}
                      onBlur={() => markTouched('phone')}
                      aria-invalid={showFieldError('phone') || undefined}
                      aria-describedby={PHONE_HINT_ID}
                    />
                  </div>
                  <p
                    id={PHONE_HINT_ID}
                    className={`${styles.hint} ${showFieldError('phone') ? styles.hintBad : ''}`}
                  >
                    {showFieldError('phone') ? <InfoIcon className={styles.hintIcon} /> : null}
                    {showFieldError('phone')
                      ? errors.phone
                      : 'لتحديثات الطلب عبر الرسائل أو واتساب.'}
                  </p>
                </div>

                <div className={`${styles.field} ${styles.fieldWide}`}>
                  <label className={styles.label} htmlFor={FIELD_IDS.email}>
                    البريد الإلكتروني
                  </label>
                  <input
                    id={FIELD_IDS.email}
                    className={styles.input}
                    type="email"
                    required
                    dir="ltr"
                    inputMode="email"
                    autoComplete="email"
                    placeholder="name@example.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    onBlur={() => markTouched('email')}
                    aria-invalid={showFieldError('email') || undefined}
                    aria-describedby={EMAIL_HINT_ID}
                  />
                  <p
                    id={EMAIL_HINT_ID}
                    className={`${styles.hint} ${showFieldError('email') ? styles.hintBad : ''}`}
                  >
                    {showFieldError('email') ? <InfoIcon className={styles.hintIcon} /> : null}
                    {showFieldError('email')
                      ? errors.email
                      : 'رابط صفحة الطلب هو مسلك ملفّاتك — احفظه بعد الدفع.'}
                  </p>
                </div>
              </div>

              <div className={styles.taxBox}>
                <button
                  type="button"
                  className={styles.taxToggle}
                  aria-expanded={needTax}
                  aria-controls={TAX_PANEL_ID}
                  onClick={() => setNeedTax((v) => !v)}
                >
                  <ChevronIcon
                    className={`${styles.taxChevron} ${needTax ? styles.chevronOpen : ''}`}
                  />
                  أحتاج فاتورة ضريبية باسم منشأة
                </button>

                <div id={TAX_PANEL_ID} className={styles.taxFields} hidden={!needTax}>
                  <div className={styles.taxGrid}>
                    <div className={styles.field}>
                      <label className={styles.label} htmlFor={FIELD_IDS.orgName}>
                        اسم المنشأة
                      </label>
                      <input
                        id={FIELD_IDS.orgName}
                        className={styles.input}
                        type="text"
                        /* مطلوبٌ متى فُتحت لوحة الفاتورة الضريبيّة، لا قبلها. */
                        required={needTax}
                        autoComplete="organization"
                        value={orgName}
                        onChange={(e) => setOrgName(e.target.value)}
                        onBlur={() => markTouched('orgName')}
                        aria-invalid={showFieldError('orgName') || undefined}
                        aria-describedby={showFieldError('orgName') ? 'error-org-name' : undefined}
                      />
                      {showFieldError('orgName') ? (
                        <p id="error-org-name" className={styles.fieldError}>
                          {errors.orgName}
                        </p>
                      ) : null}
                    </div>

                    <div className={styles.field}>
                      <label className={styles.label} htmlFor={FIELD_IDS.vatNumber}>
                        الرقم الضريبي
                      </label>
                      <input
                        id={FIELD_IDS.vatNumber}
                        className={styles.input}
                        type="text"
                        required={needTax}
                        dir="ltr"
                        inputMode="numeric"
                        maxLength={15}
                        placeholder="3XXXXXXXXXXXXX3"
                        value={vatNumber}
                        onChange={(e) =>
                          setVatNumber(e.target.value.replace(/\D/g, '').slice(0, 15))
                        }
                        onBlur={() => markTouched('vatNumber')}
                        aria-invalid={showFieldError('vatNumber') || undefined}
                        aria-describedby={
                          showFieldError('vatNumber') ? 'error-vat-number' : undefined
                        }
                      />
                      {showFieldError('vatNumber') ? (
                        <p id="error-vat-number" className={styles.fieldError}>
                          {errors.vatNumber}
                        </p>
                      ) : null}
                    </div>
                  </div>

                  <p className={styles.taxHint}>
                    يُضاف الرقم الضريبي إلى الفاتورة كما تُدخله. اتركه فارغًا إن كنت تشتري بصفة
                    شخصية.
                  </p>
                </div>
              </div>
            </section>

            <section className={styles.section} id={SEC_DELIVERY} aria-labelledby={`${SEC_DELIVERY}-h`}>
              <div className={styles.sectionIntro}>
                <div className={styles.sectionHead}>
                  <span className={styles.sectionBadge} aria-hidden="true">
                    <DownloadIcon className={styles.sectionBadgeIcon} />
                  </span>
                  <h2 className={styles.sectionTitle} id={`${SEC_DELIVERY}-h`}>
                    طريقة التسليم
                  </h2>
                </div>

                <div className={styles.sectionBody}>
                  {/*
                    * «الطريقة الوحيدة» كانت صادقةً حين كان المتجر رقميًّا بحتًا
                    * ولا يقبل غيره. وصارت تُقال عن سلّةٍ فيها شحنة، فتكذب.
                    * والوصف الآن يتبع ما تُعلنه السلّة.
                    */}
                  <p className={styles.deliveryOnly}>
                    <b className={styles.deliveryName}>
                      {needsAddress ? 'شحن إلى عنوانك' : 'تحميل فوري'}
                    </b>
                    <span className={styles.deliveryChip}>
                      {requirements?.isMixed === true ? 'وتحميل فوري لما يُنزَّل' : 'الطريقة الوحيدة'}
                    </span>
                  </p>
                  {/*
                   * كان هنا وعدان لا يملكهما المتجر: «تصلك نسخة على بريدك»
                   * ولا مُرسِل بريد في الخلفية إطلاقًا، و«تبقى في حسابي ←
                   * تنزيلاتي دائمًا» ولا جلسة حساب تربط التنزيلات بمشترٍ —
                   * التنزيل مربوطٌ برمز الطلب وحده. يبقى ما يمكن الوفاء به.
                   */}
                  <p className={styles.sectionLead}>
                    {needsAddress
                      ? 'يُشحن ما يحتاج شحنًا إلى العنوان أدناه. وما يُنزَّل تظهر روابطه في صفحة الطلب فور نجاح الدفع.'
                      : 'تظهر روابط التحميل في صفحة الطلب فور نجاح الدفع. احفظ رابط صفحة الطلب — هو مفتاح ملفّاتك. لا شحن ولا انتظار.'}
                  </p>
                </div>
              </div>

              {needsAddress ? (
                <div className={styles.taxGrid}>
                  <div className={styles.field}>
                    <label className={styles.label} htmlFor="ship-city">
                      المدينة
                    </label>
                    <input
                      id="ship-city"
                      className={styles.input}
                      dir="auto"
                      autoComplete="address-level2"
                      value={shipCity}
                      required
                      aria-invalid={(submitted && addressMissing) || undefined}
                      /* الخطأ مربوطٌ بالحقل لا مجاورٌ له فحسب — كبقيّة حقول النموذج. */
                      aria-describedby={submitted && addressMissing ? 'error-ship-city' : undefined}
                      onChange={(event) => setShipCity(event.target.value)}
                    />
                    {submitted && addressMissing ? (
                      <p id="error-ship-city" className={styles.fieldError} role="alert">
                        المدينة مطلوبة لشحن طلبك.
                      </p>
                    ) : null}
                  </div>

                  <div className={styles.field}>
                    <label className={styles.label} htmlFor="ship-district">
                      الحي
                    </label>
                    <input
                      id="ship-district"
                      className={styles.input}
                      dir="auto"
                      autoComplete="address-level3"
                      value={shipDistrict}
                      onChange={(event) => setShipDistrict(event.target.value)}
                    />
                  </div>

                  <div className={styles.field}>
                    <label className={styles.label} htmlFor="ship-street">
                      الشارع
                    </label>
                    <input
                      id="ship-street"
                      className={styles.input}
                      dir="auto"
                      autoComplete="street-address"
                      value={shipStreet}
                      onChange={(event) => setShipStreet(event.target.value)}
                    />
                  </div>

                  <div className={styles.field}>
                    <label className={styles.label} htmlFor="ship-recipient">
                      اسم المستلِم
                    </label>
                    <input
                      id="ship-recipient"
                      className={styles.input}
                      dir="auto"
                      autoComplete="name"
                      placeholder="اتركه فارغًا إن كنت المستلِم"
                      value={shipRecipient}
                      onChange={(event) => setShipRecipient(event.target.value)}
                    />
                  </div>

                  <div className={styles.field}>
                    <label className={styles.label} htmlFor="ship-notes">
                      ملاحظات للمندوب
                    </label>
                    <input
                      id="ship-notes"
                      className={styles.input}
                      dir="auto"
                      value={shipNotes}
                      onChange={(event) => setShipNotes(event.target.value)}
                    />
                  </div>
                </div>
              ) : null}
            </section>

            <section className={styles.section} id={SEC_PAY} aria-labelledby={`${SEC_PAY}-h`}>
              <div className={styles.sectionIntro}>
                <div className={styles.sectionHead}>
                  <span className={styles.sectionBadge} aria-hidden="true">
                    2
                  </span>
                  <h2 className={styles.sectionTitle} id={`${SEC_PAY}-h`}>
                    وسيلة الدفع
                  </h2>
                </div>
                <p className={styles.sectionLead}>
                  لا تُضاف أي رسوم على أي وسيلة. تُعالَج البيانات عند مزوّد الدفع، ولا يحتفظ المتجر
                  ببيانات بطاقتك.
                </p>
                {marks.length > 0 ? (
                  <ul className={styles.marks} role="list" aria-label="وسائل الدفع المقبولة">
                    {marks.map((mark) => (
                      <li className={styles.mark} key={mark} data-mark={mark}>
                        <PaymentMark mark={mark} />
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>

              {paymentOptions.length > 0 ? (
                <div className={styles.methods} role="radiogroup" aria-labelledby={`${SEC_PAY}-h`}>
                  {paymentOptions.map((option, index) => {
                    const on = option.id === selectedMethod;
                    return (
                      <div key={option.id}>
                        <button
                          type="button"
                          role="radio"
                          aria-checked={on}
                          tabIndex={on ? 0 : -1}
                          ref={(el) => {
                            methodRefs.current[index] = el;
                          }}
                          className={`${styles.method} ${on ? styles.methodOn : ''}`}
                          aria-busy={(on && switching) || undefined}
                          onClick={() => chooseMethod(option.id)}
                          onKeyDown={(e) => onMethodKeyDown(e, index)}
                        >
                          <span className={styles.methodRadio} aria-hidden="true">
                            <span className={styles.methodRadioDot} />
                          </span>
                          <span className={styles.methodMain}>
                            <b className={styles.methodLabel}>{option.label}</b>
                            {option.desc ? (
                              <span className={styles.methodDesc}>{option.desc}</span>
                            ) : null}
                          </span>
                          <span className={styles.methodLogo} aria-hidden="true">
                            {option.id === APPLE_PAY_METHOD ? (
                              <span className={styles.appleMark} dir="ltr">
                                <ApplePayIcon />
                                Pay
                              </span>
                            ) : (
                              <PaymentMark mark={option.logo} decorative />
                            )}
                          </span>
                        </button>
                        {on ? (
                          <div className={styles.methodPanel}>
                            <p className={styles.methodPanelText}>
                              <LockIcon className={styles.methodPanelIcon} />
                              تُدخل بيانات الدفع داخل صفحة المزوّد المؤمَّنة — لا يحتفظ المتجر بأي
                              منها.
                            </p>
                          </div>
                        ) : null}
                      </div>
                    );
                  })}
                </div>
              ) : (
                <p className={styles.methodPending}>
                  تُحدَّد وسيلة الدفع من مزوّد الدفع المُفعَّل للمتجر لحظة إنشاء الطلب.
                </p>
              )}

              {methodError ? (
                <p className={styles.fieldError} role="alert">
                  {methodError}
                </p>
              ) : null}

              {phase === 'payment' && order && !order.payment.payUrl && order.payment.provider !== 'fake' ? (
                <p className={styles.payUnavailable}>
                  لم يتوفّر رابط الدفع لهذا الطلب. طلبك محفوظ —{' '}
                  <a href={`/orders/${encodeURIComponent(order.orderToken)}`}>افتح صفحة الطلب</a> لإتمام
                  الدفع منها، أو أعد المحاولة بعد قليل.
                </p>
              ) : null}

              {order ? (
                <p className={styles.orderNumber}>
                  رقم طلبك: <bdi dir="ltr">{order.orderNumber}</bdi>
                </p>
              ) : null}
            </section>

            <section className={styles.section} id={SEC_REVIEW} aria-labelledby={`${SEC_REVIEW}-h`}>
              <div className={styles.sectionHead}>
                <span className={styles.sectionBadge} aria-hidden="true">
                  3
                </span>
                <h2 className={styles.sectionTitle} id={`${SEC_REVIEW}-h`}>
                  المراجعة والتأكيد
                </h2>
              </div>

              <dl className={styles.review}>
                {reviewRows.map((row) => (
                  <div key={row.label} className={styles.reviewRow}>
                    <dt className={styles.reviewLabel}>{row.label}</dt>
                    <dd className={styles.reviewValue}>{row.value}</dd>
                    <a className={styles.reviewEdit} href={row.href} aria-label={`تعديل ${row.label}`}>
                      تعديل
                    </a>
                  </div>
                ))}
              </dl>

              <div className={styles.field} id={FIELD_IDS.policyConsent}>
                <label className={styles.checkboxRow}>
                  <input
                    type="checkbox"
                    required
                    checked={policyConsent}
                    onChange={(e) => {
                      setPolicyConsent(e.target.checked);
                      markTouched('policyConsent');
                    }}
                    aria-invalid={showFieldError('policyConsent') || undefined}
                    aria-describedby={
                      showFieldError('policyConsent') ? 'error-policy-consent' : undefined
                    }
                  />
                  <span>
                    أوافق على <a href={routes.policy('terms')}>الشروط والأحكام</a> و
                    <a href={routes.policy('refund')}>سياسة الاسترجاع</a> للمنتجات الرقمية.
                  </span>
                </label>
                {showFieldError('policyConsent') ? (
                  <p id="error-policy-consent" className={styles.fieldError}>
                    {errors.policyConsent}
                  </p>
                ) : null}
              </div>

              <label className={styles.checkboxRow}>
                <input
                  type="checkbox"
                  checked={marketingOptIn}
                  onChange={(e) => setMarketingOptIn(e.target.checked)}
                />
                <span>
                  أرغب باستلام العروض والملفات المجانية على بريدي.
                  <span className={styles.checkboxHint}>
                    (اختياري — تحديثات الطلب تصلك في كل الأحوال)
                  </span>
                </span>
              </label>

              {phase === 'declined' ? (
                <div className={styles.declined} role="alert">
                  <p className={styles.declinedLead}>
                    <InfoIcon className={styles.declinedIcon} />
                    تعذّر إتمام الدفع — ولم يُخصم أي مبلغ
                  </p>
                  <p className={styles.declinedBody}>
                    رفض البنك العملية دون توضيح السبب. بياناتك محفوظة كما هي. جرّب مرة أخرى، أو
                    اختر وسيلة دفع أخرى.
                  </p>
                  <button
                    type="button"
                    className={styles.retryPay}
                    onClick={() => setPhase(order ? 'payment' : 'details')}
                  >
                    <RefreshIcon className={styles.retryIcon} />
                    إعادة المحاولة
                  </button>
                </div>
              ) : null}

              {phase === 'unconfirmed' && order ? (
                <div className={styles.declined} role="alert">
                  <p className={styles.declinedLead}>
                    <InfoIcon className={styles.declinedIcon} />
                    تعذّر تأكيد حالة الدفع
                  </p>
                  <p className={styles.declinedBody}>
                    انقطع الاتصال قبل أن يصلنا جواب المزوّد، فلا نعرف هل تمّ الخصم أم لا. افتح
                    صفحة طلبك للتحقّق قبل إعادة المحاولة — لا تدفع مرّتين.
                  </p>
                  <a
                    className={styles.retryPay}
                    href={`/orders/${encodeURIComponent(order.orderToken)}`}
                  >
                    افتح صفحة طلبك
                  </a>
                </div>
              ) : null}

              {submitError ? (
                <div className={styles.declined} role="alert">
                  <p className={styles.declinedLead}>
                    <InfoIcon className={styles.declinedIcon} />
                    تعذّر إنشاء الطلب
                  </p>
                  {/* نصّ الخادم حرفيًّا — هو وحده يعرف ما الذي منع الإنشاء. */}
                  <p className={styles.declinedBody}>{submitError}</p>
                  <a className={styles.retryPay} href={routes.cart}>
                    راجع سلّتك
                  </a>
                </div>
              ) : null}

              {connectionFailed ? <ConnectionError /> : null}

              <div className={styles.ctaBlock}>
                <button
                  type="submit"
                  className={`${styles.cta} ${styles.ctaInline}`}
                  disabled={processing || blocked}
                  aria-busy={processing || undefined}
                  aria-describedby="ck-cta-note"
                >
                  {ctaGlyph}
                  {ctaLabel}
                </button>
                {/* The note stays rendered below the breakpoint: it describes the
                    sticky-bar CTA too, and an aria-describedby target inside
                    display:none would expose nothing. */}
                <p
                  id="ck-cta-note"
                  role="status"
                  aria-live="polite"
                  className={`${styles.ctaNote} ${blocked && !processing ? styles.ctaNoteBlocked : ''}`}
                >
                  {ctaNote}
                </p>
              </div>

              <div className={styles.trust}>
                <TrustStrip variant="minimal" items={TRUST_ITEMS} compact />
              </div>

              <ul className={styles.policyLinks} role="list">
                {POLICY_LINKS.map((link) => (
                  <li key={link.href}>
                    <a href={link.href}>{link.label}</a>
                  </li>
                ))}
              </ul>
            </section>
          </main>

          <aside className={styles.summarySide} aria-labelledby="ck-summary-side-h">
            <div className={styles.summaryHead}>
              <h2 className={styles.summaryTitle} id="ck-summary-side-h">
                ملخّص الطلب
              </h2>
              <a className={styles.summaryEdit} href={routes.cart}>
                تعديل السلة
              </a>
            </div>
            <OrderSummary idPrefix="ck-side" {...summaryProps} />
          </aside>
        </div>

        {/* Mobile-only sticky pay bar — the inline CTA is hidden below the breakpoint. */}
        <div className={styles.payBar}>
          <div className={styles.payBarTotal}>
            <span className={styles.payBarLabel}>الإجمالي شامل الضريبة</span>
            <b className={styles.payBarAmount}>
              <bdi dir="ltr">{total.amount}</bdi>{' '}
              <span className={styles.payBarCurrency}>{total.currency}</span>
            </b>
          </div>
          <button
            type="submit"
            className={`${styles.cta} ${styles.payBarCta}`}
            disabled={processing || blocked}
            aria-busy={processing || undefined}
            aria-describedby="ck-cta-note"
          >
            {ctaGlyph}
            {ctaShort}
          </button>
        </div>
      </form>
    </div>
  );
}
