'use client';
import { useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { formatNumber, formatMoney } from '@tahdheer/design-system/format';
import { buyNowFields, formatHref, resolveSelection, FORMAT_PARAM } from '../../lib/pdp-selection';
import { useApplePayAvailable } from '../../lib/apple-pay';
import { routes } from '../../lib/routes';
import { useWishlistItem, type WishlistItem } from '../../lib/wishlist';
import { labelFor, type VariantDto } from '../product/FormatSelector';
import { BUY_NOW_ACTION } from '../../lib/buy-now';
import { useAddToCart, useCartMembership } from '../product/useAddToCart';
import { useVariantSelection } from '../product/VariantSelection';
import { ApplePayIcon, CartIcon, HeartIcon } from './icons';
import { PdpToast } from './PdpToast';
import styles from './pdp.module.css';

/**
 * The buy panel's live part — price, «الصيغة» radios, the two CTA rows — and
 * the mobile sticky bar (Storefront Product / Product Mobile.dc.html).
 *
 * One selection feeds both: the format chosen in the panel is the price the
 * bar shows and the line the bar adds. Money comes from the server only:
 * the struck price renders when the server says `onSale` with a higher
 * `regularMinor`, and «وفّر N٪» only from its `discountPct` — the design's
 * `round(price × 1.3)` compare-at is sample arithmetic, never reproduced.
 *
 * القلب يقرأ مخزن المفضّلة الواحد (lib/wishlist.ts) الذي تقرؤه الترويسة
 * وصفحة /wishlist. وزرّ Apple Pay يُصيَّر بقاعدة lib/apple-pay.ts وحدها:
 * الخادم يعلن `applePay: true` **و**الجهاز يقدر عليه (بعد التركيب) — فلا
 * يُرسم على الخادم أبدًا. ضغطُه هو مسار «اشترِ الآن» نفسه (`buyNow`) بوجهة
 * `/checkout?item=&fmt=&pay=apple`: يُضاف السطر ثمّ يُنتقَل، والدفع يطلب
 * `paymentMethod: apple_pay` فتفتح صفحة Tap المستضافة على Apple Pay.
 */
export interface PdpPrice {
  priceMinor: number;
  regularMinor?: number;
  onSale?: boolean;
  discountPct?: number;
}

export interface PdpPurchaseProps {
  productId: number;
  /** سبيكة المنتج — لرابط Apple Pay (`?item=`). */
  slug: string;
  /** The product's own price — used when there is no format choice. */
  price: PdpPrice | null;
  /** Adds «· تحميل فوري» to the mobile tax line when the product downloads. */
  instantDownload: boolean;
  /** Only a product the server marks `editable: true` gets «قابل للتعديل» under Word. */
  editable: boolean;
  /** Query params to keep on the no-JS `?fmt=` links (e.g. the review token). */
  query?: [string, string][];
  /** Apple Pay offered by the checkout (server-declared); the device check is added client-side. */
  applePay: boolean;
  /** What the heart stores; null (no price) ⇒ no heart. */
  wishItem: WishlistItem | null;
}

/** The design's one-line note under each format name. */
const FORMAT_NOTE: Record<string, string> = {
  DOCX: 'قابل للتعديل',
  PPTX: 'عرض الدروس',
  PDF: 'للطباعة',
};

function noteFor(variant: VariantDto, editable: boolean): string | null {
  /* سطر الخادم أوّلًا؛ وبغيابه سطرُ التصميم لصيغةٍ معروفة. */
  if (variant.note?.trim()) return variant.note.trim();
  const format = variant.format?.toUpperCase();
  if (!format) return null;
  if (format === 'DOCX' && !editable) return null;
  return FORMAT_NOTE[format] ?? null;
}

function applePayHref(slug: string, variant: VariantDto | null): string {
  const params = new URLSearchParams({ item: slug });
  if (variant) params.set(FORMAT_PARAM, variant.slug);
  params.set('pay', 'apple');
  return `${routes.checkout}?${params.toString()}`;
}

function usePurchase(productId: number, fallback: PdpPrice | null) {
  const selection = useVariantSelection();
  const variants = selection?.variants ?? [];
  const state = resolveSelection(variants, selection?.selected?.id ?? null, fallback);
  const cart = useAddToCart(productId, state.variantId);
  const inCart = useCartMembership(productId, state.variantId);
  return { selection, variants, state, cart, inCart };
}

/** القلب — العنصر المحفوظ يحمل ثمن الصيغة المعروضة الآن. */
function useHeart(item: WishlistItem | null, priceMinor: number | null) {
  const stored = useMemo<WishlistItem | null>(
    () => (item ? { ...item, priceMinor: priceMinor ?? item.priceMinor } : null),
    [item, priceMinor],
  );
  const { wished, toggle } = useWishlistItem(stored ?? { slug: '', title: '', priceMinor: 0, currency: '' });
  return stored ? { wished, toggle } : null;
}

function Money({ minor, className, unitClassName }: { minor: number; className?: string; unitClassName?: string }) {
  const parts = formatMoney(minor);
  return (
    <b className={className} dir="ltr">
      {parts.amount} <span className={unitClassName}>{parts.currency}</span>
    </b>
  );
}

function HeartButton({ heart, className }: { heart: { wished: boolean; toggle: () => boolean }; className?: string }) {
  /* نبضة التأكيد للنقر وحده: الحالة المستعادة من المخزن عند التركيب لا تتحرّك. */
  const [popped, setPopped] = useState(false);
  return (
    <button
      type="button"
      className={className}
      aria-pressed={heart.wished}
      aria-label={heart.wished ? 'أزل من المفضلة' : 'أضف إلى المفضلة'}
      data-wished={heart.wished ? '' : undefined}
      data-pop={popped && heart.wished ? '' : undefined}
      onClick={() => {
        heart.toggle();
        setPopped(true);
      }}
    >
      <HeartIcon filled={heart.wished} />
    </button>
  );
}

/**
 * زرّ Apple Pay الرسميّ: `<button>` حقيقيّ يرسمه WebKit بمظهر Apple نفسه
 * (`-webkit-appearance: -apple-pay-button`، انظر pdp.module.css)، وحيث لا
 * يدعمه WebKit يُرسم البديل: أسود + الشعار + «Pay» بخطّ النظام — كما تصفه
 * إرشادات Apple التسويقيّة. لا يُصيَّر إلّا حين `useApplePayAvailable` صادقة.
 */
function ApplePayButton({
  href,
  onPay,
  disabled,
  loading,
  className,
}: {
  href: string;
  onPay: (to: string) => void;
  disabled: boolean;
  loading: boolean;
  className?: string;
}) {
  return (
    <button
      type="button"
      lang="ar"
      className={`${styles.applePay}${className ? ` ${className}` : ''}`}
      aria-label="الشراء عبر Apple Pay"
      aria-busy={loading || undefined}
      data-loading={loading ? '' : undefined}
      disabled={disabled}
      onClick={() => onPay(href)}
    >
      <span className={styles.applePayFallback} aria-hidden="true">
        <ApplePayIcon />
        <span className={styles.applePayWord} dir="ltr" lang="en">
          Pay
        </span>
      </span>
    </button>
  );
}

function BuyNowForm({
  productId,
  variantId,
  onBuy,
  disabled,
  loading = false,
  className,
  children,
}: {
  productId: number;
  variantId: number | null;
  onBuy: () => void;
  disabled: boolean;
  /** الزرّ الذي ضُغط ينتظر — دوّار الوصفة، والعرض ثابت. */
  loading?: boolean;
  className?: string;
  children: React.ReactNode;
}) {
  /* A real form: «اشترِ الآن» posts to the server handler without JS; with JS
     the fast client path runs and the submit is cancelled. */
  return (
    <form className={styles.buyNowForm} method="post" action={BUY_NOW_ACTION}>
      {buyNowFields(productId, variantId).map(([name, value]) => (
        <input key={name} type="hidden" name={name} value={value} />
      ))}
      <button
        type="submit"
        className={className}
        disabled={disabled}
        aria-busy={loading || undefined}
        onClick={(event) => {
          event.preventDefault();
          onBuy();
        }}
      >
        {children}
      </button>
    </form>
  );
}

export function PdpPurchase({
  productId,
  slug,
  price: fallback,
  instantDownload,
  editable,
  query = [],
  applePay,
  wishItem,
}: PdpPurchaseProps) {
  const { selection, variants, state, cart, inCart } = usePurchase(productId, fallback);
  const { hasChoice, variant: selected, variantId, price, purchasable, wasMinor: was, discountPct: discount } = state;
  const heart = useHeart(wishItem, price?.priceMinor ?? null);
  const optionRefs = useRef<(HTMLAnchorElement | null)[]>([]);
  const busy = cart.status === 'busy';
  const apple = useApplePayAvailable(applePay);
  const appleHref = applePayHref(slug, selected);
  const failed = cart.status === 'error' ? cart.message : null;
  /* أيّ الأزرار الثلاثة ضُغط: `pendingTo` فارغة ⇒ إضافةٌ مجرّدة، وإلّا وجهتها. */
  const addBusy = busy && cart.pendingTo === null;
  const buyBusy = busy && cart.pendingTo === routes.checkout;

  const choose = (variant: VariantDto) => {
    selection?.select(variant.id);
    const url = new URL(window.location.href);
    url.searchParams.set(FORMAT_PARAM, variant.slug);
    window.history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
  };

  const onKey = (event: KeyboardEvent<HTMLAnchorElement>, index: number) => {
    const forward = event.key === 'ArrowLeft' || event.key === 'ArrowDown';
    const back = event.key === 'ArrowRight' || event.key === 'ArrowUp';
    if (!forward && !back) return;
    event.preventDefault();
    const count = variants.length;
    for (let step = 1; step <= count; step += 1) {
      const next = (index + (forward ? step : -step) + count) % count;
      const target = variants[next];
      if (target?.purchasable) {
        choose(target);
        optionRefs.current[next]?.focus();
        return;
      }
    }
  };

  return (
    <>
      {price ? (
        <div className={styles.priceRow}>
          <Money minor={price.priceMinor} className={styles.price} unitClassName={styles.priceUnit} />
          {was !== null ? (
            <del className={styles.was} dir="ltr">
              {formatMoney(was).amount} {formatMoney(was).currency}
            </del>
          ) : null}
          {discount !== null ? <span className={styles.save}>وفّر {formatNumber(discount)}٪</span> : null}
          {/*
           * القلب هنا لا في صفّ الأزرار — انحرافٌ مقصود عن تصميم الصفّين: ثلاثة
           * أزرار شراءٍ (السلة · الآن · Apple Pay) تتقاسم صفًّا واحدًا، ولا
           * يتّسع معها مربّعٌ رابع. فيقف القلب عند نهاية سطر الثمن (44px)، وهو
           * المكوّن نفسه بحالته نفسها. يبقى مشروطًا بقابليّة الشراء كما كان.
           */}
          {heart && purchasable ? <HeartButton heart={heart} className={styles.wishBtn} /> : null}
          <span className={styles.tax}>شامل الضريبة</span>
        </div>
      ) : null}

      {/* Mobile: the price lives in the sticky bar; the body keeps the saving line. */}
      {price ? (
        <div className={styles.saveLine}>
          {discount !== null && was !== null ? (
            <span className={styles.save}>
              وفّر {formatNumber(discount)}٪ — بدل <bdi dir="ltr">{formatMoney(was).amount}</bdi>
            </span>
          ) : null}
          <span className={styles.saveNote}>شامل الضريبة{instantDownload ? ' · تحميل فوري' : ''}</span>
        </div>
      ) : null}

      {hasChoice ? (
        <div className={styles.formats}>
          <span className={styles.formatsLabel} id="pdp-format-label">
            الصيغة
          </span>
          <div className={styles.formatGroup} role="radiogroup" aria-labelledby="pdp-format-label">
            {variants.map((variant, index) => {
              const active = variant.id === selected?.id;
              const note = noteFor(variant, editable);
              return (
                <a
                  key={variant.id}
                  ref={(node) => {
                    optionRefs.current[index] = node;
                  }}
                  href={formatHref(query, variant)}
                  role="radio"
                  aria-checked={active}
                  aria-disabled={!variant.purchasable || undefined}
                  tabIndex={active ? 0 : -1}
                  className={styles.format}
                  onClick={(event) => {
                    event.preventDefault();
                    if (variant.purchasable) choose(variant);
                  }}
                  onKeyDown={(event) => onKey(event, index)}
                >
                  <b>{labelFor(variant)}</b>
                  {note ? <span>{note}</span> : null}
                </a>
              );
            })}
          </div>
        </div>
      ) : null}

      {purchasable ? (
        <div className={styles.ctas}>
          {/*
           * صفٌّ واحد بترتيب DOM = الترتيب المرئيّ (يمين←يسار) = ترتيب Tab:
           * السلة → الآن → Apple Pay. استعلام الحاوية في CSS يُسقط أيقونة
           * السلة أوّلًا حين يضيق، ثمّ يطوي الصفّ إلى سطرين.
           */}
          <div className={styles.ctaRow} data-apple={apple ? '' : undefined}>
            <button
              type="button"
              className={styles.addBtn}
              onClick={cart.add}
              disabled={busy}
              aria-busy={addBusy || undefined}
            >
              <CartIcon className={styles.addIcon} />
              {inCart === true ? 'في السلة ✓' : 'أضف إلى السلة'}
            </button>
            <BuyNowForm
              productId={productId}
              variantId={variantId}
              onBuy={() => cart.buyNow()}
              disabled={busy}
              loading={buyBusy}
              className={styles.buyNow}
            >
              اشترِ الآن
            </BuyNowForm>
            {apple ? (
              <ApplePayButton
                href={appleHref}
                onPay={cart.buyNow}
                disabled={busy}
                loading={cart.pendingTo === appleHref}
              />
            ) : null}
          </div>
          {/* فشلُ الإضافة (لأيّ زرٍّ من الثلاثة) يُقال تحت الصفّ، والأزرار تعود مفعّلة. */}
          {failed ? (
            <p className={styles.buyError} role="alert">
              {failed}
            </p>
          ) : null}
        </div>
      ) : null}

      <PdpToast message={failed ? null : cart.message} tone="success" />
    </>
  );
}

/** Mobile sticky buy bar — Storefront Product Mobile.dc.html. */
export function PdpStickyBar({
  productId,
  slug,
  price: fallback,
  applePay,
  wishItem,
}: Pick<PdpPurchaseProps, 'productId' | 'slug' | 'price' | 'applePay' | 'wishItem'>) {
  const { state, cart, inCart } = usePurchase(productId, fallback);
  const { hasChoice, variant: selected, variantId, price, purchasable } = state;
  const heart = useHeart(wishItem, price?.priceMinor ?? null);
  const apple = useApplePayAvailable(applePay);
  if (!price || !purchasable) return null;
  const busy = cart.status === 'busy';
  const appleHref = applePayHref(slug, selected);
  const addBusy = busy && cart.pendingTo === null;
  const buyBusy = busy && cart.pendingTo === routes.checkout;

  return (
    <div className={styles.stickyBar} data-bottom-chrome="fixed">
      <div className={styles.stickyRow}>
        <span className={styles.stickyPrice}>
          {hasChoice && selected ? <span className={styles.stickyFormat}>{labelFor(selected)}</span> : null}
          <Money minor={price.priceMinor} className={styles.stickyAmount} unitClassName={styles.stickyUnit} />
        </span>
        {heart ? <HeartButton heart={heart} className={styles.stickyHeart} /> : null}
        <button
          type="button"
          className={styles.stickyAdd}
          onClick={cart.add}
          disabled={busy}
          aria-busy={addBusy || undefined}
        >
          {inCart === true ? 'في السلة ✓' : 'أضف إلى السلة'}
        </button>
      </div>
      <div className={styles.stickyRow2}>
        <BuyNowForm
          productId={productId}
          variantId={variantId}
          onBuy={() => cart.buyNow()}
          disabled={busy}
          loading={buyBusy}
          className={styles.stickyBuy}
        >
          اشترِ الآن
        </BuyNowForm>
        {apple ? (
          <ApplePayButton
            href={appleHref}
            onPay={cart.buyNow}
            disabled={busy}
            loading={cart.pendingTo === appleHref}
            className={styles.stickyApple}
          />
        ) : null}
      </div>
      <PdpToast message={cart.message} tone={cart.status === 'error' ? 'error' : 'success'} mobile />
    </div>
  );
}
