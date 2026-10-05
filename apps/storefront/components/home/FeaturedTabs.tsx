'use client';
/**
 * FeaturedTabs — the segmented control from TahHomeBody over the REAL featured
 * list returned by /api/v1/products?source=featured.
 *
 * HONESTY DOCTRINE: the tab labels are the categories that actually occur in
 * the fetched products — never an invented taxonomy. With fewer than two
 * distinct categories there is nothing to segment, so no control renders and
 * the grid stands alone. Filtering is client-side over data the server already
 * sent, so no tab can show a list the server did not provide.
 */
import { useMemo, useState } from 'react';
import { ProductCard, type ProductCardProps } from '@tahdheer/ui';
import { CardAddToCart } from '../product/CardAddToCart';
import { cardFallbackArt } from '../product/CardArt';
import { cardPreview, cardWishlist } from '../../app/(catalog)/_components/ProductGrid';
import { SectionHead } from './SectionHead';
import styles from './sections.module.css';

/** Sentinel for the «الكل» tab (a real category name could be any string). */
const ALL = ' all';

export interface FeaturedTabsProps {
  items: ProductCardProps[];
  eyebrow: string;
  title: string;
}

export function FeaturedTabs({ items, eyebrow, title }: FeaturedTabsProps) {
  const categories = useMemo(() => {
    const seen: string[] = [];
    for (const item of items) {
      const key = item.categoryPath[0];
      if (key && !seen.includes(key)) seen.push(key);
    }
    return seen;
  }, [items]);

  const [active, setActive] = useState<string>(ALL);
  const shown = active === ALL ? items : items.filter((p) => p.categoryPath[0] === active);

  return (
    <>
      <SectionHead eyebrow={eyebrow} title={title}>
        {categories.length > 1 ? (
          <div className={styles.tabs} role="group" aria-label="تصفية المنتجات المختارة">
            <button
              type="button"
              className={styles.tab}
              aria-pressed={active === ALL}
              onClick={() => setActive(ALL)}
            >
              الكل
            </button>
            {categories.map((category) => (
              <button
                key={category}
                type="button"
                className={styles.tab}
                aria-pressed={active === category}
                onClick={() => setActive(category)}
              >
                {category}
              </button>
            ))}
          </div>
        ) : null}
      </SectionHead>

      <ul className={styles.productGrid} role="list">
        {shown.map((product) => (
          <li key={product.id}>
            <ProductCard
              {...product}
              ctaSlot={<CardAddToCart productId={product.id} title={product.title} disabled={product.unavailable} />}
              previewSlot={product.unavailable ? undefined : cardPreview(product)}
              actions={cardWishlist(product)}
              mediaSlot={cardFallbackArt(product.categoryPath, Boolean(product.image))}
            />
          </li>
        ))}
      </ul>
    </>
  );
}
