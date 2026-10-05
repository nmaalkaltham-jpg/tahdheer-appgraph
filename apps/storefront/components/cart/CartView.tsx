'use client';
/**
 * Cart view (MIP §5.3) — client-driven: HttpOnly cart cookie ⇒ all reads and
 * writes go through lib/commerce-api with credentials:'include'.
 *
 * HONESTY DOCTRINE: totals figures come ONLY from the server-authoritative
 * `totals` chain; coupon errors render the server message verbatim; fetch
 * failure renders the explicit error card — never fake data.
 */
import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type CSSProperties,
  type FormEvent,
  type ReactNode,
} from 'react';
import { useRouter } from 'next/navigation';
import { TrustStrip, type TrustItem } from '@tahdheer/ui';
import { formatMoney, formatNumber, qty } from '@tahdheer/design-system/format';
import {
  addCartItem,
  applyCoupon,
  getCart,
  removeCartItem,
  removeCoupon,
  type CartLine,
  type CartPayload,
  type CommerceFailure,
  type CommerceResult,
} from '../../lib/commerce-api';
import { routes } from '../../lib/routes';
import { emitCartChanged } from '../product/useAddToCart';
import { ConnectionError } from '../search/ConnectionError';
import { Icon } from '../home/icons';
import { ThemedArt } from '../home/ThemedArt';
import { EMPTY_ART } from '../../lib/art';
import { CheckIcon, FilesIcon } from '../product/icons';
import { PdpRecent } from '../pdp/PdpRecent';
import { TotalsRows } from './TotalsRows';
import styles from './CartView.module.css';

const CONNECTION_ERROR = 'تعذّر الاتصال بالخادم — أعد المحاولة';
const UNDO_MS = 6000;

/**
 * بنود بطاقة الثقة في عمود الملخّص (Tahdheer - Cart.dc.html).
 *
 * HONESTY DOCTRINE: كل بند هنا وعدٌ ملتزَم به منشور أصلًا في شريط ثقة الصفحة
 * الرئيسة (components/home/sections.tsx) — لا يُخترع في السلة ادّعاء جديد.
 * بند التصميم الثالث «سياسة استرجاع واضحة» محذوف عمدًا: صفحة سياسة الاسترجاع
 * (app/policies/content.ts) ما زالت مواضع قرارٍ غير محسومة، فوصفها بالوضوح
 * ادّعاء بلا مصدر.
 */
const TRUST_CLAIMS: TrustItem[] = [
  {
    id: 'secure-pay',
    title: 'دفع آمن ومشفّر',
    icon: <Icon name="shield" size={15} strokeWidth={2} />,
  },
  {
    id: 'instant-delivery',
    title: 'وصول فوري للملفات الرقمية',
    icon: <Icon name="download" size={15} strokeWidth={2} />,
  },
];

/** «{n} منتجات في سلتك» — صيغ التوافق العربي عبر qty() (MIP §2.4). */
const COUNT_FORMS = {
  zero: 'لا منتجات',
  one: 'منتج واحد',
  two: 'منتجان',
  few: 'منتجات',
  many: 'منتجًا',
};

interface UndoState {
  productId: CartLine['productId'];
  title: string;
  /** الصيغة المحذوفة — بدونها يُعيد التراجع منتجًا بسعرٍ آخر. */
  variantId?: number;
}

/**
 * ‎#i-close‎ · ‎#i-info‎ · ‎#i-lock‎ من TahIcons.dc.html — مساراتها منسوخة حرفيًّا
 * من الرمز، وسجلّا أيقونات المتجر (home/icons · product/icons) لا يحملانها بعد.
 * كلّها زخرفية: النصّ المجاور أو aria-label هو ما يحمل المعنى.
 */
function Glyph({
  size,
  strokeWidth = 2,
  children,
}: {
  size: number;
  strokeWidth?: number;
  children: ReactNode;
}) {
  return (
    <svg
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      {children}
    </svg>
  );
}

const CloseGlyph = () => (
  <Glyph size={14} strokeWidth={2.4}>
    <path d="M6 6l12 12M18 6L6 18" />
  </Glyph>
);

const InfoGlyph = () => (
  <Glyph size={16} strokeWidth={2.4}>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 11v5M12 7.6h.01" />
  </Glyph>
);

const LockGlyph = () => (
  <Glyph size={18}>
    <rect x="5" y="11" width="14" height="9" rx="2" />
    <path d="M8 11V8a4 4 0 0 1 8 0v3" />
  </Glyph>
);

function LineSaving({ line }: { line: CartLine }) {
  // Per-line saving derives from the line's own server prices (presentation only).
  if (line.discountPct === undefined || line.regularMinor === undefined) return null;
  const savedMinor = line.regularMinor - line.priceMinor;
  if (savedMinor <= 0) return null;
  const saved = formatMoney(savedMinor);
  return (
    <p className={styles.lineSaving}>
      وفّرت <bdi dir="ltr">{saved.amount}</bdi> {saved.currency} · خصم{' '}
      <bdi dir="ltr">{formatNumber(line.discountPct)}%</bdi>
    </p>
  );
}

/**
 * كتلة سعر السطر: الشطب أوّلًا، ثمّ السعر الحالي، ثمّ «ر.س» على خطّ قاعدة واحد
 * (Tahdheer - Cart.dc.html). سعر الشطب بلا عملة تمامًا كما يرسمه التصميم.
 */
function LinePrice({ line }: { line: CartLine }) {
  const price = formatMoney(line.priceMinor);
  const was =
    line.regularMinor !== undefined && line.regularMinor > line.priceMinor
      ? formatMoney(line.regularMinor)
      : null;
  return (
    <div className={styles.linePrice}>
      <p className={styles.priceRow}>
        {was ? (
          <del className={styles.wasPrice}>
            <bdi dir="ltr">{was.amount}</bdi>
          </del>
        ) : null}
        <b className={styles.nowPrice}>
          <bdi dir="ltr">{price.amount}</bdi>
        </b>
        <span className={styles.priceCurrency}>{price.currency}</span>
      </p>
      <LineSaving line={line} />
    </div>
  );
}

export function CartView() {
  const [cart, setCart] = useState<CartPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState<CommerceFailure | null>(null);
  /** رسالة الخادم عند فشل الجلب (كالحدّ ٤٢٩ ومهلته) — تُعرض لا تُبتلع. */
  const [failMessage, setFailMessage] = useState<string | null>(null);
  const [undo, setUndo] = useState<UndoState | null>(null);
  const [couponCode, setCouponCode] = useState('');
  const [couponError, setCouponError] = useState<string | null>(null);
  /** فشل فعلٍ على سطر — يُعرض داخل السلّة ولا يُخفيها. */
  const [lineError, setLineError] = useState<string | null>(null);
  const [couponBusy, setCouponBusy] = useState(false);
  /** null = «لم يلمسه المستخدم بعد» ⇒ الطيّ يتبع حالة الكوبون الفعلية. */
  const [couponOpen, setCouponOpen] = useState<boolean | null>(null);
  const [checkoutBusy, setCheckoutBusy] = useState(false);
  /** «ماذا يتضمن؟» — مفتوح لكل سطر على حدة، مفتاحه معرّف السطر. */
  const [expanded, setExpanded] = useState<Record<string, boolean>>({});
  const undoTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const router = useRouter();

  // setState happens inside the promise callback (async), never synchronously
  // within the effect body.
  const applyCart = useCallback((res: CommerceResult<CartPayload>) => {
    if (res.data) {
      setCart(res.data);
      setFailed(null);
      setFailMessage(null);
    } else {
      // يُحفَظ صنف الفشل لا مجرّد «فشل»: «غير مضبوط» عطل نشر لا تُصلحه إعادة
      // المحاولة، وعرضه كانقطاع شبكة هو ما أخفى السبب أسبوعًا.
      setFailed(res.failure ?? 'network');
      setFailMessage(res.message ?? null);
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    void getCart().then(applyCart);
    return () => {
      if (undoTimer.current) clearTimeout(undoTimer.current);
    };
  }, [applyCart]);

  const retry = () => {
    setLoading(true);
    setFailed(null);
    void getCart().then(applyCart);
  };

  const scheduleUndoExpiry = () => {
    if (undoTimer.current) clearTimeout(undoTimer.current);
    undoTimer.current = setTimeout(() => setUndo(null), UNDO_MS);
  };

  /*
   * فشل فعلٍ على سطرٍ واحد ليس انقطاع اتصال بالسلّة كلّها.
   *
   * كان كلّ فشل — بما فيه ٤٠٤ الصريحة على سطرٍ حُذف من تبويبٍ آخر — يُترجم
   * `setFailed('network')` فيُستبدل عرض السلّة كلّه ببطاقة «تعذّر الاتصال
   * بالخادم» ورسالةُ الخادم العربية تُرمى. الآن: انقطاعٌ حقيقيّ (status 0)
   * وحده يُسقط العرض؛ وما عداه تنبيهٌ داخل الصفحة وإعادة جلبٍ للسلّة، لأنّ
   * حالة الخادم تغيّرت فعلًا وما يُعرض صار بائتًا.
   */
  const handleLineFailure = (res: { status: number; failure?: CommerceFailure; message?: string }) => {
    if (res.status === 0) {
      setFailed(res.failure ?? 'network');
      return;
    }
    setLineError(res.message ?? 'تعذّر تنفيذ هذا الإجراء — حُدِّثت السلّة.');
    void getCart().then(applyCart);
  };

  const handleRemove = async (line: CartLine) => {
    setLineError(null);
    const res = await removeCartItem(line.id);
    if (res.data) {
      setCart(res.data);
      emitCartChanged();
      setUndo({ productId: line.productId, title: line.title, variantId: line.variantId });
      scheduleUndoExpiry();
    } else {
      handleLineFailure(res);
    }
  };

  const handleUndo = async () => {
    if (!undo) return;
    if (undoTimer.current) clearTimeout(undoTimer.current);
    setLineError(null);
    /*
     * التراجع يُعيد الصيغة المحذوفة نفسها. كان يُنادى `addCartItem(productId)`
     * بلا `variantId`، فيُسعَّر السطر من `product_prices` لا من عرض الصيغة —
     * أي منتجٌ آخر بسعرٍ آخر تحت زرٍّ اسمه «تراجع».
     */
    const res = await addCartItem(undo.productId, undo.variantId);
    setUndo(null);
    if (res.data) {
      setCart(res.data);
      emitCartChanged();
    } else {
      handleLineFailure(res);
    }
  };

  const handleApplyCoupon = async () => {
    const code = couponCode.trim();
    if (!code || couponBusy) return;
    setCouponBusy(true);
    setCouponError(null);
    const res = await applyCoupon(code);
    if (res.data) {
      setCart(res.data);
      emitCartChanged();
      setCouponCode('');
    } else if (res.status === 0) {
      setCouponError(CONNECTION_ERROR);
    } else {
      // Server validation message verbatim (e.g. «رمز الخصم غير صالح أو منتهي»).
      setCouponError(res.message ?? CONNECTION_ERROR);
    }
    setCouponBusy(false);
  };

  const submitCoupon = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    void handleApplyCoupon();
  };

  const handleRemoveCoupon = async () => {
    if (couponBusy) return;
    setCouponBusy(true);
    setCouponError(null);
    const res = await removeCoupon();
    if (res.data) {
      setCart(res.data);
      emitCartChanged();
    } else {
      setCouponError(res.message ?? CONNECTION_ERROR);
    }
    setCouponBusy(false);
  };

  const goCheckout = () => {
    if (checkoutBusy) return;
    setCheckoutBusy(true);
    router.push(routes.checkout);
  };

  if (loading) {
    /*
     * بطاقةٌ بهيئة بطاقات الرحلة لا سطرٌ عارٍ: كان الانتقال من نصٍّ حرّ إلى
     * عمودَي السلّة قفزةً تُزيح ما تحتها. `role="status"` باقٍ كما هو، والنصّ
     * وحده يحمل المعنى — الدوّارة `aria-hidden` وتتوقّف مع تقليل الحركة.
     */
    return (
      <div className={styles.loadingCard} role="status">
        <span className={styles.loadingSpinner} aria-hidden="true" />
        جارٍ تحميل السلة…
      </div>
    );
  }

  if (failed !== null || !cart) {
    return (
      <div className={styles.emptyWrap}>
        <ConnectionError onRetry={retry} failure={failed ?? 'network'} />
        {failMessage ? (
          <p className={styles.failNote} role="status">
            {failMessage}
          </p>
        ) : null}
      </div>
    );
  }

  /*
   * نبرة الحذف ذهبية لا خضراء: الأخضر (‎#i-check‎) محجوز للنجاح — الحذف إشعار
   * تنبيه قابل للتراجع، وهذا ما يفرّق بين النبرتين في التصميم.
   */
  const lineNotice = lineError ? (
    <div className={`${styles.couponMsg} ${styles.couponMsgError}`} role="alert">
      <span className={styles.couponMsgText}>{lineError}</span>
    </div>
  ) : null;

  const undoNotice = undo ? (
    <div className={styles.undo} role="status" data-reveal="in">
      <InfoGlyph />
      <span className={styles.undoText}>أُزيل «{undo.title}» من السلة.</span>
      <button type="button" className={styles.undoBtn} onClick={() => void handleUndo()}>
        تراجع
      </button>
    </div>
  ) : null;

  if (cart.items.length === 0) {
    return (
      <div className={styles.emptyWrap}>
        {lineNotice}
        {undoNotice}
        <div className={styles.empty}>
          <ThemedArt art={EMPTY_ART.cart} alt="" sizes="160px" className={styles.emptyArt} />
          <h2 className={styles.emptyTitle}>سلتك فارغة حاليًا</h2>
          <p className={styles.emptyText}>
            ابدأ بتصفّح الملفات التعليمية — ستجد ما يوافق مرحلتك ومادتك، وتصلك فور الدفع.
          </p>
          <div className={styles.emptyActions}>
            <a className={styles.emptyCta} href={routes.shop}>
              تصفّح المنتجات
            </a>
            {/* مخرجٌ ثانٍ لمن لا يعرف من أين يبدأ — لا طريقٌ مسدود. */}
            <a className={styles.emptyAlt} href={routes.bestSellers}>
              الأكثر مبيعًا
            </a>
          </div>
        </div>
      </div>
    );
  }

  // الكوبون المطبَّق أو رسالة الخطأ يجب أن تُرى دون فتح الطيّ يدويًا.
  const couponPanelOpen = couponOpen ?? (cart.coupon !== null || couponError !== null);
  // A support channel is only offered when it actually exists (NEXT_PUBLIC_WHATSAPP_NUMBER).
  const whatsapp = process.env.NEXT_PUBLIC_WHATSAPP_NUMBER;
  const total = formatMoney(cart.totals.totalMinor);

  return (
    <div className={styles.layout}>
      <section className={styles.linesCol} aria-label="محتويات السلة">
        {lineNotice}
        {undoNotice}
        <ul className={styles.lines} role="list">
          {cart.items.map((line, index) => (
            <li
              key={line.id}
              className={styles.line}
              /* دخول متتابع من motion.css — تقليل الحركة يكشف المحتوى ولا يخفيه. */
              data-reveal="in"
              style={{ '--mo-index': index } as CSSProperties}
            >
              {/*
               * Thumb = link to the product. The cart API returns no image for a
               * line, so the tile stays an explicit empty surface — never a fake
               * photo and never a stand-in illustration.
               */}
              <a
                className={styles.thumb}
                href={routes.product(line.slug)}
                aria-label={`افتح صفحة ${line.title}`}
              />
              <div className={styles.lineBody}>
                <div className={styles.lineHead}>
                  <div className={styles.lineHeadText}>
                    {line.categoryPath.length > 0 ? (
                      <p className={styles.lineCategory}>{line.categoryPath.join(' › ')}</p>
                    ) : null}
                    <h2 className={styles.lineTitle}>
                      <a href={routes.product(line.slug)}>{line.title}</a>
                      {/*
                       * الصيغة المشتراة داخل العنوان لا بجواره: سطران للمنتج
                       * نفسه بصيغتين يتطابق عنوانهما، فبلا هذا يقرأ مُدرِّج
                       * الشاشة عنوانين متماثلين ولا يعرف أيّهما يحذف.
                       */}
                      {line.variantTitle ? (
                        <span className={styles.lineVariant}> — {line.variantTitle}</span>
                      ) : null}
                    </h2>
                  </div>
                  <button
                    type="button"
                    className={styles.remove}
                    onClick={() => void handleRemove(line)}
                    aria-label={`أزل «${line.variantTitle ? `${line.title} — ${line.variantTitle}` : line.title}» من السلة`}
                    title="حذف"
                  >
                    <CloseGlyph />
                  </button>
                </div>
                {/*
                 * Attribute chips. The design draws four (slides · format ·
                 * licence · delivery) but the cart line carries only `format`
                 * and the locked quantity — the rest have no field, so they are
                 * omitted rather than invented.
                 */}
                <ul className={styles.attrs} role="list">
                  {line.format ? (
                    <li className={`${styles.attr} ${styles.attrLead}`}>
                      <FilesIcon className={styles.attrIcon} strokeWidth={2.2} />
                      {line.format}
                    </li>
                  ) : null}
                  {/* Quantity is display-only: digital lines are qty-locked (MIP §5.3). */}
                  <li className={styles.attr}>
                    الكمية: <bdi dir="ltr">{formatNumber(line.qty)}</bdi>
                  </li>
                </ul>
                {/*
                 * صفّ التحكّم: زرّ الإفصاح ثمّ السعر في نهايته. التصميم يضع
                 * هنا أيضًا عدّاد الكمية و«حفظ لوقت لاحق»؛ الأوّل محذوف لأن
                 * السطور الرقمية مقفلة على 1 (MIP §5.3)، والثاني لأن الواجهة
                 * لا تملك مسارًا حقيقيًّا للحفظ — قلبٌ يبدّل حالة محلية وعد كاذب.
                 */}
                <div className={styles.controls}>
                  {line.includes && line.includes.length > 0 ? (
                    <button
                      type="button"
                      className={styles.includesToggle}
                      aria-expanded={expanded[String(line.id)] === true}
                      onClick={() =>
                        setExpanded((prev) => ({
                          ...prev,
                          [String(line.id)]: !prev[String(line.id)],
                        }))
                      }
                    >
                      <Icon
                        name="chev"
                        size={12}
                        strokeWidth={2.4}
                        className={`${styles.includesChev} ${
                          expanded[String(line.id)] === true ? styles.includesChevOpen : ''
                        }`}
                      />
                      ماذا يتضمن؟
                    </button>
                  ) : null}
                  <LinePrice line={line} />
                </div>
                {line.includes && expanded[String(line.id)] === true ? (
                  <ul className={styles.includesPanel} role="list">
                    {line.includes.map((entry) => (
                      <li key={entry} className={styles.includesItem}>
                        <CheckIcon className={styles.includesCheck} strokeWidth={2.6} />
                        {entry}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            </li>
          ))}
        </ul>

        {/* Coupon lives under the lines, not in the summary (Tahdheer - Cart.dc.html). */}
        <section className={styles.couponCard}>
          <button
            type="button"
            className={styles.couponToggle}
            aria-expanded={couponPanelOpen}
            onClick={() => setCouponOpen(!couponPanelOpen)}
          >
            <Icon name="tag" size={16} strokeWidth={2} className={styles.couponTag} />
            <span className={styles.couponLabel}>هل لديك رمز خصم؟</span>
            <Icon
              name="chev"
              size={13}
              strokeWidth={2.4}
              className={`${styles.couponChev} ${couponPanelOpen ? styles.couponChevOpen : ''}`}
            />
          </button>

          {couponPanelOpen ? (
            <div className={styles.couponPanel}>
              {cart.coupon ? null : (
                <form className={styles.couponForm} onSubmit={submitCoupon} noValidate>
                  <label className={styles.srOnly} htmlFor="cart-coupon">
                    رمز الخصم
                  </label>
                  <input
                    id="cart-coupon"
                    type="text"
                    dir="ltr"
                    className={styles.couponInput}
                    value={couponCode}
                    onChange={(e) => {
                      setCouponCode(e.target.value);
                      setCouponError(null);
                    }}
                    autoComplete="off"
                    aria-invalid={couponError !== null}
                    aria-describedby={couponError ? 'cart-coupon-error' : undefined}
                  />
                  <button
                    type="submit"
                    className={styles.couponApply}
                    disabled={couponBusy || couponCode.trim() === ''}
                    aria-busy={couponBusy || undefined}
                  >
                    {couponBusy ? 'جارٍ التحقق…' : 'تطبيق'}
                  </button>
                </form>
              )}

              {cart.coupon ? (
                <div className={`${styles.couponMsg} ${styles.couponMsgOk}`} role="status">
                  <Icon name="check" size={13} strokeWidth={2.6} className={styles.couponMsgIcon} />
                  <span className={styles.couponMsgText}>
                    تم تطبيق رمز الخصم <bdi dir="ltr">{cart.coupon.code}</bdi> — {cart.coupon.label}
                  </span>
                  <button
                    type="button"
                    className={styles.couponRemove}
                    onClick={() => void handleRemoveCoupon()}
                    disabled={couponBusy}
                  >
                    إزالة
                  </button>
                </div>
              ) : null}

              {couponError ? (
                <p className={styles.couponError} id="cart-coupon-error" role="alert">
                  {couponError}
                </p>
              ) : null}
            </div>
          ) : null}
        </section>
      </section>

      <aside className={styles.summary} aria-label="ملخّص الطلب">
        <section className={styles.summaryCard} aria-labelledby="cart-summary-heading">
          <h2 className={styles.summaryTitle} id="cart-summary-heading">
            ملخّص الطلب
          </h2>

          <TotalsRows totals={cart.totals} coupon={cart.coupon} variant="hero" />

          <p className={styles.taxNote}>
            الأسعار شاملة ضريبة القيمة المضافة · كل المنتجات رقمية وتصلك فور الدفع
          </p>

          <button
            type="button"
            className={styles.checkout}
            onClick={goCheckout}
            disabled={checkoutBusy}
            aria-busy={checkoutBusy || undefined}
          >
            {/* الدوّار من وصفة الزرّ (aria-busy) والعرض ثابت؛ تحت reduce تبقى التسمية وحدها. */}
            <LockGlyph />
            {checkoutBusy ? 'جارٍ التحويل…' : 'إتمام الطلب'}
          </button>

          <p className={styles.legalNote}>
            دفع آمن ومشفّر · بإتمام الطلب توافق على{' '}
            <a href={routes.policy('terms')}>الشروط</a> و
            <a href={routes.policy('privacy')}>سياسة الخصوصية</a>
          </p>
        </section>

        <section className={styles.trustCard}>
          <TrustStrip variant="classic" compact items={TRUST_CLAIMS} />
        </section>

        {whatsapp ? (
          <section className={styles.helpCard}>
            <p className={styles.helpTitle}>تحتاج مساعدة قبل إتمام الطلب؟</p>
            <a
              className={styles.helpLink}
              href={`https://wa.me/${whatsapp}`}
              target="_blank"
              rel="noopener noreferrer"
            >
              <Icon name="headset" size={15} strokeWidth={2} />
              تواصل على واتساب
            </a>
          </section>
        ) : null}
      </aside>

      {/* «شاهدتَ مؤخّرًا» تحت السطور (سطح المكتب يملأ به ما تحت عمودٍ قصير)،
          وبعد الملخّص في ترتيب التبويب فلا يسبق زرَّ الإتمام. ما في السلة يُسقط. */}
      <PdpRecent embedded exclude={cart.items.map((line) => line.slug)} className={styles.recentSlot} />

      {/* Sticky mobile total bar (safe-area + blur) — MIP §5.3. */}
      <div className={styles.mobileBar} data-bottom-chrome="fixed">
        <div className={styles.mobileMeta}>
          <span className={styles.mobileCount}>{qty(cart.count, COUNT_FORMS)} في سلتك</span>
          <span className={styles.mobileTotal}>
            <bdi dir="ltr">{total.amount}</bdi>
            <span className={styles.mobileCurrency}>{total.currency}</span>
          </span>
        </div>
        <button
          type="button"
          className={styles.mobileCheckout}
          onClick={goCheckout}
          disabled={checkoutBusy}
          aria-busy={checkoutBusy || undefined}
        >
          {checkoutBusy ? 'جارٍ التحويل…' : 'إتمام الطلب'}
        </button>
      </div>
    </div>
  );
}
