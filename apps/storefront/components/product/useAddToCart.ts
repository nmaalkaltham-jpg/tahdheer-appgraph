'use client';
/**
 * The ONE add-to-cart action for the PDP, shared by the inline CTA row and the
 * sticky purchase bar so both report identical state and neither duplicates the
 * request logic.
 *
 * Contract (lib/commerce-api, imported, never modified): cart identity is the
 * HttpOnly `tahdheer_cart` cookie, so `addCartItem` sends
 * `credentials:'include'` and NEVER throws — an unreachable API surfaces as
 * status 0 and we render the honest failure instead of pretending it worked.
 * A duplicate digital line returns the unchanged cart with 200, which is a
 * success: the product is in the cart either way.
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { addCartItem, getCart, type CartLine } from '../../lib/commerce-api';
import { routes } from '../../lib/routes';

type AddToCartStatus = 'idle' | 'busy' | 'added' | 'error';

/**
 * The storewide cart-change signal. Every successful server-side cart mutation
 * (add · line remove · coupon apply/remove) emits it, and the header's CartLink
 * listens so the badge updates on same-page mutations, not just navigation.
 */
export const CART_EVENT = 'tahdheer:cart';

/** SSR-safe by contract: only ever called from user-event/promise handlers. */
export function emitCartChanged(): void {
  window.dispatchEvent(new CustomEvent(CART_EVENT));
}

/**
 * Same wording as the cart view — one connection-failure sentence storewide.
 * Exported additively for the studio action runtime (STUDIO-PHASE3-CONTRACT §5 WP3-S):
 * its toasts show server messages verbatim or THIS sentence — never new copy.
 */
export const CONNECTION_ERROR = 'تعذّر الاتصال بالخادم — أعد المحاولة';
const ADDED = 'أُضيف المنتج إلى سلّتك';

export interface AddToCartController {
  status: AddToCartStatus;
  /** Server message verbatim when the server sent one; never invented copy. */
  message: string | null;
  add: () => void;
  /** يضيف ثمّ ينتقل إلى الدفع؛ `to` وجهةٌ بديلة (مثل `/checkout?pay=apple`). */
  buyNow: (to?: string) => void;
  /**
   * وجهةُ الطلب الجاري أثناء `busy` (`null` لإضافةٍ مجرّدة أو بلا طلب) — ليعرف
   * الزرّ الذي ضُغط أنّه هو المنتظِر، وتبقى الأزرار الأخرى معطّلةً فحسب.
   */
  pendingTo: string | null;
}

/**
 * `variantId` يتغيّر مع مُبدِّل الصيغ. تمريره هنا لا في `add` يُبقي واجهة
 * المُستدعِي كما هي: الشريط اللاصق وصفّ الأزرار كلاهما ينادي `add()` بلا
 * وسيط، فلا يستطيع أحدهما أن ينسى الصيغة ويضيف الخاطئة.
 */
export function useAddToCart(
  productId: number | string,
  variantId?: number | null,
): AddToCartController {
  const [status, setStatus] = useState<AddToCartStatus>('idle');
  const [message, setMessage] = useState<string | null>(null);
  const [target, setTarget] = useState<string | null>(null);
  const router = useRouter();

  const run = useCallback(
    (thenCheckout: string | null) => {
      setStatus('busy');
      setTarget(thenCheckout);
      setMessage(null);
      void addCartItem(productId, variantId).then((res) => {
        if (res.data) {
          setStatus('added');
          setMessage(ADDED);
          emitCartChanged();
          if (thenCheckout !== null) router.push(thenCheckout);
          return;
        }
        setStatus('error');
        setMessage(res.message ?? CONNECTION_ERROR);
      });
    },
    [productId, variantId, router],
  );

  const add = useCallback(() => run(null), [run]);
  const buyNow = useCallback((to?: string) => run(to ?? routes.checkout), [run]);

  return { status, message, add, buyNow, pendingTo: status === 'busy' ? target : null };
}

/**
 * هل هذا السطر في السلّة **الآن** — لا «هل أضفتُه في هذه الجلسة».
 *
 * `status === 'added'` يعرف نتيجة طلبِ هذه الصفحة وحدها، فالعائد الذي أضاف
 * الملفّ أمس يرى «أضف» ويضيفه ثانية. فتُقرأ السلّة نفسها التي يقرأها رأس
 * الموقع (`getCart`) مرّةً عند التركيب، ثمّ عند كلّ `CART_EVENT` — فإضافةٌ
 * ناجحة في الصفحة نفسها تقلب الوسم بلا تنقّل.
 *
 * والعائد ثلاثيّ عمدًا: `null` تعني «لا نعرف» — سلّةٌ لم تُقرأ بعد أو قراءةٌ
 * فشلت. ولا تُقلب إلى `false` بحال: من يرى «أضف» وهو في السلّة أهونُ من
 * «في السلة ✓» كاذبة، والمُستدعي يُبقي وسمه العاديّ عند `null`.
 */
export function useCartMembership(
  productId: number | string,
  variantId?: number | null,
): boolean | null {
  const [lines, setLines] = useState<CartLine[] | null>(null);

  useEffect(() => {
    let alive = true;
    const read = () => {
      void getCart().then((res) => {
        if (!alive) return;
        /* `data` غائبة = تعذّرت القراءة ⇒ «لا نعرف»، لا «ليست في السلّة». */
        setLines(res.data === null ? null : res.data.items);
      });
    };
    read();
    window.addEventListener(CART_EVENT, read);
    return () => {
      alive = false;
      window.removeEventListener(CART_EVENT, read);
    };
  }, []);

  return useMemo(() => {
    if (lines === null) return null;
    return lines.some(
      (line) =>
        String(line.productId) === String(productId) &&
        /*
         * بلا صيغةٍ مطلوبة يكفي المنتج: صفّ الأزرار لا يُصيَّر إلّا لمنتجٍ
         * بصيغةٍ واحدة (قاعدة «زرُّ إضافةٍ واحد لكلّ حالة عرض»).
         */
        (variantId == null || line.variantId === variantId),
    );
  }, [lines, productId, variantId]);
}
