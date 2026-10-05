'use client';
/**
 * The product card's «أضف للسلة» — the real POST, not a link.
 *
 * ProductCard is presentational and server-component-safe, so it cannot own an
 * action: it exposes the footer's CTA position (`ctaSlot`) and the class that
 * position is drawn with (`productCardCtaClass`). This button wears that exact
 * class, so the design's pill is styled in ONE stylesheet and a slotted control
 * is pixel-identical to the specced one.
 *
 * It runs the same `useAddToCart` as the PDP row and the sticky bar, so every
 * add-to-cart in the storefront shares one request path and one set of states.
 *
 * HONESTY DOCTRINE: nothing here invents an outcome. «أُضيف ✓» appears only
 * after the API confirmed the line, and a failure prints the hook's message
 * verbatim — the server's own words, or the storewide connection-failure line.
 */
import { useSyncExternalStore } from 'react';
import { createPortal } from 'react-dom';
import { productCardCartIcon, productCardCtaClass } from '@tahdheer/ui/ProductCard';
import { CartToast } from './CartToast';
import { useAddToCart } from './useAddToCart';
import styles from './CardAddToCart.module.css';

export interface CardAddToCartProps {
  productId: number | string;
  /**
   * The product title, appended to the accessible name: a grid of otherwise
   * identical «أضف للسلة» buttons is unusable from a screen reader's list.
   */
  title?: string;
  /** لا عرضَ فعّال (البطاقة `unavailable`): الزرّ معطَّل فلا يُرسل طلبًا سيُرفض. */
  disabled?: boolean;
}

/** The design's label (TahProductCard), and the confirmed-state label. */
const IDLE = 'أضف للسلة';
const ADDED = 'أُضيف ✓';

/*
 * «هل نحن في المتصفّح؟» — عبر بوّابة المتجر الخارجيّ الواحدة.
 * `document` هو المتجر هنا: لا يتغيّر أبدًا، فالاشتراك لا يفعل شيئًا، ولقطة
 * الخادم `false` لأنّ `createPortal` لا وجود لها في تصيير الخادم.
 */
const subscribeNever = (): (() => void) => () => undefined;
const clientHydrated = (): boolean => true;
const serverHydrated = (): boolean => false;

export function CardAddToCart({ productId, title, disabled = false }: CardAddToCartProps) {
  const cart = useAddToCart(productId);
  const busy = cart.status === 'busy';
  const failed = cart.status === 'error';

  /*
   * التنبيه يُسكب في `body` لا في البطاقة: البطاقة `overflow: hidden`،
   * وأيّ سلفٍ محوَّل (`transform`) يصير كتلةً حاوية لكلّ `position: fixed`
   * بداخله — فتنبيهٌ مُركَّب هنا يُوضع بالنسبة إلى البطاقة ثمّ تقصّه، ولحظةُ
   * ظهوره لحظةَ الضغط والمؤشّر ما يزال فوقها. و`hydrated` يُبقي تصيير الخادم مطابقًا
   * لأوّل تصييرٍ في المتصفّح، والمنطقة الحيّة تدخل الشجرة عند الإماهة — قبل
   * أيّ ضغطةٍ بوقتٍ طويل، وهو شرط أن تُقرأ (CartToast.tsx:53-56).
   */
  const hydrated = useSyncExternalStore(subscribeNever, clientHydrated, serverHydrated);

  return (
    <div className={styles.slot}>
      <button
        type="button"
        className={productCardCtaClass}
        /*
         * الانشغال `aria-disabled` لا `disabled`: الزرّ المعطَّل يفقد التركيز لحظة
         * الضغط فيضيع موضع مستعمل لوحة المفاتيح في الشبكة ولا يسمع «أُضيف ✓».
         * والحارس في المعالج يمنع الطلب الثاني (نقرتان ⇒ طلبٌ واحد).
         * `disabled` الحقيقيّ للمنتج غير المتاح وحده.
         */
        onClick={() => {
          if (!busy) cart.add();
        }}
        disabled={disabled}
        aria-disabled={busy || undefined}
        aria-busy={busy}
        data-state={cart.status}
      >
        {/* الانشغال يرسمه دوّار وصفة الزرّ (`aria-busy`) والتسمية مخفيّةٌ لونًا
            فيبقى العرض ثابتًا — لا كلمةٌ أطول تقفز بالتذييل تحت المؤشّر. */}
        {/* التسميتان مكدّستان في خليّةٍ واحدة: الزرّ بعرض أطولهما، فلا ينكمش عند «أُضيف ✓». */}
        {productCardCartIcon}
        <span className={styles.labels}>
          <span data-on={cart.status !== 'added' ? '' : undefined}>{IDLE}</span>
          <span data-on={cart.status === 'added' ? '' : undefined}>{ADDED}</span>
        </span>
        {title ? <span className={styles.srOnly}> «{title}»</span> : null}
      </button>

      {/*
        سطر الفشل وحده، وبلا `role="status"`: التنبيه أدناه هو المنطقة الحيّة
        الواحدة لهذا الفعل (CartToast.tsx:57-60)، ومنطقةٌ ثانية تحمل الرسالة
        نفسها تجعل كلّ إضافةٍ تُنطق مرّتين. ويبقى السطر لأنّه — في شبكةٍ من
        البطاقات — يقول أيُّ بطاقةٍ أخفقت، والتنبيه لا يقولها.
      */}
      {failed && cart.message !== null ? (
        <p className={styles.statusError}>{cart.message}</p>
      ) : null}

      {/*
        التأكيد المرئيّ نفسه الذي تعطيه لوحة الشراء (ProductCta.tsx:81): من
        أضاف بطاقةً من «يُشترى معه عادةً» كان يرى تغيّر وسم الزرّ فقط، بينما
        الفعل نفسه من لوحة الشراء يرفع تنبيهًا. والنصّ نصّ الخادم حرفيًّا.
      */}
      {hydrated
        ? createPortal(
            <CartToast message={cart.message} tone={failed ? 'error' : 'success'} />,
            document.body,
          )
        : null}
    </div>
  );
}

