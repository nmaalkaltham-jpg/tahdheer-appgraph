'use client';
/**
 * نقرةُ نتيجة البحث — تحسينٌ تدريجيّ فوق روابط وأزرار حقيقيّة.
 *
 * تُحسب النقرة على عنصرٍ تفاعليّ داخل البطاقة وحده: رابطٌ (صفحة المنتج) أو زرُّ
 * السلّة على البطاقة نفسها. وزرُّ المفضّلة ليس نقرة نتيجة. نقرٌ على فراغ البطاقة ليس نقرة. و`data-search-product`
 * يُعلّم البطاقة لمراقب الظهور (`SearchImpressions`).
 */
import type { MouseEvent, ReactNode } from 'react';
import { sendSearchClick } from '../../lib/search-api';

export function ResultClick({
  queryId,
  token,
  productId,
  position,
  children,
}: {
  queryId: number;
  token: string;
  productId: number;
  position: number;
  children: ReactNode;
}) {
  const onClick = (event: MouseEvent<HTMLDivElement>) => {
    const target = (event.target as Element).closest('a, button');
    if (!target) return;
    if (target.tagName === 'A') {
      /*
       * رابطٌ إلى صفحة منتجٍ وحده نقرةُ نتيجة. المعاينة تُفتح داخل البطاقة، ورابط
       * «اذهب إلى السلة» فيها ليس زيارةً لصفحة المنتج.
       */
      if ((target.getAttribute('href') ?? '').startsWith('/products/')) {
        sendSearchClick(queryId, token, productId, position, 'pdp');
      }
      return;
    }
    /*
     * زرُّ السلّة على البطاقة (`CardAddToCart` يعلن حالته بـ`data-state`) نقرةُ
     * شراء. وأزرارُ التبديل (قلب المفضّلة: `aria-pressed`) وغيرها ليست نقرةَ نتيجة.
     */
    if (target.matches('[data-state]') && !target.hasAttribute('aria-pressed')) {
      sendSearchClick(queryId, token, productId, position, 'add_to_cart');
    }
  };

  return (
    // `onClickCapture` وحده ليس معالج تفاعلٍ معلَنًا: القياس يُراقب ولا يتصرّف.
    <div onClickCapture={onClick} data-search-product={productId}>
      {children}
    </div>
  );
}
