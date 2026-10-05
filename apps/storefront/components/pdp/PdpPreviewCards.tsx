'use client';
import Image from 'next/image';
import { tintOf, type GalleryPage } from './PdpGallery';
import { usePageSelection } from './PageSelection';
import styles from './pdp.module.css';

/**
 * «المعاينة» page cards — Storefront Product.dc.html: a horizontal strip of
 * 136px cards; picking one shows it on the gallery stage above.
 */
export function PdpPreviewCards({ pages }: { pages: GalleryPage[] }) {
  const { page, pick } = usePageSelection();
  return (
    <div className={styles.previewCards}>
      {pages.map((item, index) => (
        <button
          key={item.url}
          type="button"
          className={styles.previewCard}
          data-tint={tintOf(index)}
          aria-pressed={index === page}
          aria-label={item.label ?? `الصورة ${index + 1}`}
          onClick={() => pick(index)}
        >
          <span className={styles.previewCardImg}>
            <Image src={item.url} alt="" width={item.width ?? 1240} height={item.height ?? 1754} unoptimized />
          </span>
          <span className={styles.previewCardLabel}>{item.label ?? `الصورة ${index + 1}`}</span>
        </button>
      ))}
    </div>
  );
}
