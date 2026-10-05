'use client';
/**
 * قلبُ المفضّلة فوق وسائط بطاقة المنتج.
 *
 * ProductCard في @tahdheer/ui خادميّ محض ولا يستورد من المتجر، فيعرض فتحة
 * `actions` ويملأها هذا المكوّن بالفعل الحقيقيّ: `useWishlistItem` نفسه الذي
 * تستعمله لوحة الشراء والشريط اللاصق في صفحة المنتج — مخزنٌ واحد، حالةٌ واحدة.
 */
import { formatMoney } from '@tahdheer/design-system/format';
import { useWishlistItem, type WishlistItem } from '../../lib/wishlist';
import styles from './CardWishlist.module.css';

export interface CardWishlistProps {
  slug: string;
  title: string;
  priceMinor: number;
  image?: { url: string; alt?: string | null } | null;
  subtitle?: string | null;
}

export function CardWishlist({ slug, title, priceMinor, image, subtitle }: CardWishlistProps) {
  const item: WishlistItem = {
    slug,
    title,
    priceMinor,
    currency: formatMoney(priceMinor).currency,
    image: image ?? null,
    subtitle: subtitle ?? null,
  };
  const { wished, toggle } = useWishlistItem(item);

  return (
    <button
      type="button"
      className={styles.heart}
      aria-pressed={wished}
      /* اسمٌ ثابت و`aria-pressed` يحمل الحالة (نمط زرّ التبديل): اسمٌ يتقلّب مع الحالة يُسمَع حالتين متناقضتين. */
      aria-label={`المفضلة: «${title}»`}
      data-wished={wished ? '' : undefined}
      onClick={() => toggle()}
    >
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        fill={wished ? 'currentColor' : 'none'}
        stroke="currentColor"
        strokeWidth="1.9"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M12 20s-7-4.4-7-9.5A3.9 3.9 0 0 1 12 8a3.9 3.9 0 0 1 7 2.5C19 15.6 12 20 12 20z" />
      </svg>
    </button>
  );
}
