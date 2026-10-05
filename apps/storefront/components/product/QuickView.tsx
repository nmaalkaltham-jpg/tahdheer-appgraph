'use client';
/**
 * «معاينة سريعة» — نافذةٌ فوق الشبكة تعرض المنتج وتضيفه إلى السلّة دون مغادرة الأرشيف.
 *
 * العقد (BRIEF2 «Quick view»): `<dialog>` أصليّ بـ`showModal()` — المتصفّح يجعل ما
 * خلفه خاملًا (حبسُ التركيز)، ويغلقه بـEsc، ويعيد التركيز إلى زرّ الفتح. وصفتا
 * `dialog` و`scrim` من controls.module.css. الزرّ ‎44px‎ في فتحة `actions` بجوار القلب.
 *
 * البيانات: ما تعرفه البطاقة يُرسم فورًا (العنوان، الغلاف، السعر، التقييم)، ثمّ
 * `GET /api/v1/products/{slug}` من المتصفّح يجلب الصيغ والوصف القصير. لا شيء
 * يُختلق: الصيغ مجموعةُ اختيارٍ فقط حين يصدر الخادم أكثر من صيغة، والسعر المعروض
 * سعرُ الصيغة المختارة (`resolveSelection` نفسه الذي تحكم به صفحة المنتج).
 * الإضافة `useAddToCart(productId, variantId)` — مسار الطلب الواحد في المتجر.
 *
 * النافذة لا تُركَّب إلّا عند الفتح: شبكةٌ من أربعٍ وعشرين بطاقة لا تحمل أربعًا
 * وعشرين نافذةً خاملة. والإغلاق يُعيد التركيز أوّلًا ثمّ يُزيلها.
 */
import Image from 'next/image';
import { useCallback, useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { formatMoney, formatNumber } from '@tahdheer/design-system/format';
import { apiGetResource, encodeSlug } from '../../lib/api';
import { initialVariantId, resolveSelection } from '../../lib/pdp-selection';
import { routes } from '../../lib/routes';
import { labelFor, type VariantDto } from './FormatSelector';
import { useAddToCart } from './useAddToCart';
import { productCardEyeIcon, productCardPreviewClass } from '@tahdheer/ui/ProductCard';
import styles from './QuickView.module.css';

/** ما تعرفه البطاقة سلفًا — يُرسم قبل وصول التفاصيل. */
export interface QuickViewProduct {
  id: number | string;
  slug: string;
  title: string;
  url: string;
  categoryPath: string[];
  priceMinor: number;
  regularMinor?: number;
  discountPct?: number;
  onSale: boolean;
  ratingAvg?: number;
  ratingCount?: number;
  image?: { url: string; alt: string; w: number; h: number };
}

/** الحقول التي تقرؤها المعاينة من `ProductResource` — لا أكثر. */
interface QuickDetail {
  id: number;
  priceMinor?: number;
  regularMinor?: number;
  onSale?: boolean;
  discountPct?: number;
  shortDesc?: string;
  ratingAvg?: number;
  ratingCount?: number;
  variants?: VariantDto[] | null;
}

type Load = { state: 'loading' } | { state: 'error' } | { state: 'ok'; detail: QuickDetail };

const ADD = 'أضف للسلة';
const ADDED = 'أُضيف ✓';

function EyeIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.9"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  );
}

/**
 * زرّ الفتح + النافذة (تُركَّب عند الفتح فقط).
 *
 * مظهران: `button` — «معاينة» بعينٍ ونصّ في صفّ أزرار البطاقة (يلبس
 * `productCardPreviewClass` فيبقى الزرّ واحد الشكل مع «أضف للسلة»)؛ و`icon` —
 * القرص الصغير في فتحة `actions` لمن ما زال يستعمله.
 */
export function QuickView({ product, appearance = 'icon' }: { product: QuickViewProduct; appearance?: 'icon' | 'button' }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      {appearance === 'button' ? (
        <button
          type="button"
          className={productCardPreviewClass}
          aria-haspopup="dialog"
          aria-expanded={open}
          onClick={() => setOpen(true)}
        >
          {productCardEyeIcon}
          معاينة
          <span className={styles.srOnly}> «{product.title}»</span>
        </button>
      ) : (
        <button
          type="button"
          className={styles.trigger}
          aria-haspopup="dialog"
          aria-expanded={open}
          aria-label={`معاينة سريعة: «${product.title}»`}
          title="معاينة سريعة"
          onClick={() => setOpen(true)}
        >
          <EyeIcon />
        </button>
      )}
      {open ? <QuickViewDialog product={product} onClosed={() => setOpen(false)} /> : null}
    </>
  );
}

function QuickViewDialog({ product, onClosed }: { product: QuickViewProduct; onClosed: () => void }) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const titleId = useId();
  const [load, setLoad] = useState<Load>({ state: 'loading' });
  const [attempt, setAttempt] = useState(0);
  const [selectedId, setSelectedId] = useState<number | null>(null);

  /* الفتح: `showModal` ثمّ التركيز على اللوحة — قارئ الشاشة يسمع العنوان أوّلًا. */
  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;
    if (!dialog.open) dialog.showModal();
    panelRef.current?.focus({ preventScroll: true });
    /* قفل تمرير الصفحة خلف النافذة؛ موضع التمرير لا يُمسّ فيعود كما كان. */
    const root = document.documentElement;
    const previous = root.style.overflow;
    root.style.overflow = 'hidden';
    return () => {
      root.style.overflow = previous;
    };
  }, []);

  useEffect(() => {
    let alive = true;
    void apiGetResource<QuickDetail>(`/api/v1/products/${encodeSlug(product.slug)}`).then((res) => {
      if (!alive) return;
      if (!res.data) {
        setLoad({ state: 'error' });
        return;
      }
      setLoad({ state: 'ok', detail: res.data });
      setSelectedId(initialVariantId(res.data.variants ?? [], null));
    });
    return () => {
      alive = false;
    };
  }, [product.slug, attempt]);

  const close = useCallback(() => dialogRef.current?.close(), []);

  const detail = load.state === 'ok' ? load.detail : null;
  const variants = detail?.variants ?? [];
  const productPrice = {
    priceMinor: detail?.priceMinor ?? product.priceMinor,
    regularMinor: detail ? detail.regularMinor : product.regularMinor,
    onSale: detail?.onSale ?? product.onSale,
    discountPct: detail ? detail.discountPct : product.discountPct,
  };
  const selection = resolveSelection(variants, selectedId, productPrice);
  const productId = detail?.id ?? product.id;
  const cart = useAddToCart(productId, selection.variantId);
  const busy = cart.status === 'busy';

  const ratingAvg = detail?.ratingAvg ?? product.ratingAvg;
  const ratingCount = detail?.ratingCount ?? product.ratingCount;
  const showRating = ratingCount !== undefined && ratingCount > 0 && ratingAvg !== undefined;
  const eyebrow = [...product.categoryPath].reverse().join(' · ');

  const price = selection.price ? formatMoney(selection.price.priceMinor) : null;
  const was = selection.wasMinor !== null ? formatMoney(selection.wasMinor) : null;
  const canAdd = detail !== null && selection.purchasable;

  return (
    <dialog
      ref={dialogRef}
      className={styles.host}
      aria-labelledby={titleId}
      onClose={onClosed}
    >
      {/* الستارة (وصفة `scrim`) — النقر عليها يغلق؛ `::backdrop` الأصليّ شفّاف فلا ستارتان. */}
      <div className={styles.scrim} aria-hidden="true" onClick={close} />
      <div ref={panelRef} className={styles.panel} tabIndex={-1}>
        <div className={styles.head}>
          <h2 id={titleId} className={styles.title}>
            {product.title}
          </h2>
          <button type="button" className={styles.close} onClick={close} aria-label="أغلق المعاينة">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round">
              <path d="M6 6l12 12M18 6 6 18" />
            </svg>
          </button>
        </div>

        <div className={styles.body}>
          <div className={styles.media} data-cover={product.image ? '' : undefined}>
            {product.image ? (
              /* `unoptimized`: الوسائط عبر وسيط المتجر بلا محسِّن صور (كمسرح صفحة المنتج). */
              <Image
                src={product.image.url}
                alt={product.image.alt}
                width={product.image.w}
                height={product.image.h}
                sizes="(max-width: 767px) 100vw, 360px"
                unoptimized
                className={styles.img}
              />
            ) : (
              <span className={styles.paper} aria-hidden="true">
                <span />
                <span />
                <span />
              </span>
            )}
          </div>

          <div className={styles.info}>
            {eyebrow ? <p className={styles.eyebrow}>{eyebrow}</p> : null}

            {showRating ? (
              <p className={styles.rating}>
                <svg className={styles.star} aria-hidden="true" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.5 1.2 6.4L12 17.3l-5.7 3.1 1.2-6.4L2.8 9.5l6.4-.8z" />
                </svg>
                <span className={styles.srOnly}>التقييم </span>
                <bdi dir="ltr">{formatNumber(Math.round(ratingAvg * 10) / 10)}</bdi>
                <span className={styles.srOnly}> من 5،</span>
                <span className={styles.ratingCount}>
                  (<bdi dir="ltr">{formatNumber(ratingCount)}</bdi>
                  <span className={styles.srOnly}> تقييم</span>)
                </span>
              </p>
            ) : null}

            {/* السعر: سعر الصيغة المختارة، والمشطوب لتخفيضٍ حقيقيّ فقط. */}
            <p className={styles.priceRow} aria-live="polite">
              {price ? (
                <>
                  <span className={styles.price}>
                    <bdi dir="ltr">{price.amount}</bdi>
                  </span>{' '}
                  <span className={styles.currency}>{price.currency}</span>
                  {was ? (
                    <>
                      <span className={styles.srOnly}> بدل </span>
                      <del className={styles.was}>
                        <bdi dir="ltr">{was.amount}</bdi>
                      </del>
                    </>
                  ) : null}
                  {selection.discountPct !== null ? (
                    <span className={styles.discount}>
                      خصم <bdi dir="ltr">{formatNumber(selection.discountPct)}٪</bdi>
                    </span>
                  ) : null}
                </>
              ) : (
                <span className={styles.unpriced}>السعر غير متاح لهذه الصيغة</span>
              )}
            </p>

            {load.state === 'loading' ? (
              <div className={styles.loading} role="status">
                <span className={styles.srOnly}>جارٍ تحميل التفاصيل…</span>
                <span className={styles.skel} data-w="s" aria-hidden="true" />
                <span className={styles.skel} data-w="l" aria-hidden="true" />
                <span className={styles.skel} data-w="m" aria-hidden="true" />
              </div>
            ) : null}

            {load.state === 'error' ? (
              <div className={styles.failure} role="alert">
                <p className={styles.failureText}>تعذّر تحميل تفاصيل المنتج.</p>
                <button type="button" className={styles.retry} onClick={() => {
                    setLoad({ state: 'loading' });
                    setAttempt((n) => n + 1);
                  }}>
                  أعد المحاولة
                </button>
              </div>
            ) : null}

            {detail && selection.hasChoice ? (
              <FormatGroup
                variants={variants}
                selectedId={selectedId}
                /* أثناء الطلب تثبت الصيغة: ما يُضاف هو ما كان مختارًا عند الضغط. */
                onSelect={(id) => {
                  if (!busy) setSelectedId(id);
                }}
              />
            ) : null}

            {detail?.shortDesc ? <p className={styles.desc}>{detail.shortDesc}</p> : null}

            <div className={styles.actions}>
              <button
                type="button"
                className={styles.add}
                onClick={() => {
                  if (!busy) cart.add();
                }}
                /* الانشغال `aria-disabled` لا `disabled`: الزرّ المركَّز لا يُسقط التركيز من النافذة. */
                disabled={!canAdd}
                aria-disabled={busy || undefined}
                aria-busy={busy}
                data-state={cart.status}
              >
                <span className={styles.labels}>
                  <span data-on={cart.status !== 'added' ? '' : undefined}>{ADD}</span>
                  <span data-on={cart.status === 'added' ? '' : undefined}>{ADDED}</span>
                </span>
              </button>
              <a className={styles.full} href={product.url}>
                صفحة المنتج الكاملة
              </a>
            </div>

            {/* المنطقة الحيّة داخل النافذة: ما خلفها خاملٌ فلا يُقرأ منه تنبيه. */}
            <div className={styles.status} role="status">
              {cart.status === 'added' && cart.message ? (
                <p className={styles.success}>
                  {cart.message} —{' '}
                  <a className={styles.cartLink} href={routes.cart}>
                    اذهب إلى السلة
                  </a>
                </p>
              ) : null}
              {cart.status === 'error' && cart.message ? <p className={styles.error}>{cart.message}</p> : null}
            </div>
          </div>
        </div>
      </div>
    </dialog>
  );
}

/**
 * الصيغ مجموعةَ اختيارٍ مفرد (`radiogroup`) بوصفة الرقاقة: Tab يدخل المجموعة إلى
 * المختار، والأسهم تنتقل وتختار (اليسار «التالي» في RTL)، وHome/End للطرفين.
 */
function FormatGroup({
  variants,
  selectedId,
  onSelect,
}: {
  variants: VariantDto[];
  selectedId: number | null;
  onSelect: (id: number) => void;
}) {
  const labelId = useId();
  const refs = useRef<(HTMLButtonElement | null)[]>([]);
  const enabled = variants.map((variant) => variant.purchasable);
  /* نقطة الدخول بـTab: المختار، أو أوّل صيغةٍ متاحة حين لا مختار. */
  const selectedIndex = variants.findIndex((variant) => variant.id === selectedId);
  const entry = selectedIndex >= 0 ? selectedIndex : enabled.indexOf(true);

  const move = (from: number, step: 1 | -1) => {
    for (let i = 1; i <= variants.length; i += 1) {
      const next = (from + step * i + variants.length) % variants.length;
      if (enabled[next]) return next;
    }
    return from;
  };

  const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
    let target: number | null = null;
    if (event.key === 'ArrowDown' || event.key === (rtl ? 'ArrowLeft' : 'ArrowRight')) target = move(index, 1);
    if (event.key === 'ArrowUp' || event.key === (rtl ? 'ArrowRight' : 'ArrowLeft')) target = move(index, -1);
    if (event.key === 'Home') target = move(variants.length - 1, 1);
    if (event.key === 'End') target = move(0, -1);
    if (target === null) return;
    event.preventDefault();
    const variant = variants[target];
    if (!variant) return;
    onSelect(variant.id);
    refs.current[target]?.focus();
  };

  return (
    <div className={styles.formats}>
      <p id={labelId} className={styles.formatsLabel}>
        الصيغة
      </p>
      <div role="radiogroup" aria-labelledby={labelId} className={styles.formatList}>
        {variants.map((variant, index) => {
          const checked = variant.id === selectedId;
          return (
            <button
              key={variant.id}
              ref={(node) => {
                refs.current[index] = node;
              }}
              type="button"
              role="radio"
              aria-checked={checked}
              tabIndex={index === entry ? 0 : -1}
              disabled={!enabled[index]}
              className={styles.format}
              onClick={() => onSelect(variant.id)}
              onKeyDown={(event) => onKeyDown(event, index)}
            >
              {labelFor(variant)}
              {variant.note ? <span className={styles.formatNote}>{variant.note}</span> : null}
            </button>
          );
        })}
      </div>
    </div>
  );
}

