'use client';
import { useEffect } from 'react';
import { ProductCard } from '@tahdheer/ui';
import { clearRecent, recordView, useRecentlyViewed, type RecentItem } from '../../lib/recently-viewed';
import { routes } from '../../lib/routes';
import styles from './pdp.module.css';

/** ما تسجّله الصفحة عن نفسها — شكل `recordView` نفسه. */
export type RecentEntry = Omit<RecentItem, 'viewedAt'>;

/* الحدّ المعروض: صفٌّ واحد من بطاقات «يُشترى معه» (أربعٌ على سطح المكتب). */
const SHOWN = 4;

/**
 * «شاهدتَ مؤخّرًا» — يسجّل زيارة هذا المنتج بعد الإماهة مرّةً، ويعرض ما
 * سبقها من المخزن المحلّيّ (lib/recently-viewed.ts) بلا المنتج الحاضر.
 *
 * لقطة الخادم فارغة، فلا يُصيَّر شيءٌ قبل الإماهة ولا يُحجز له مكان: الشريط
 * آخر الصفحة تحت الطيّة، فظهوره لا يُزيح ما يقرؤه الزائر. وبطاقته هي بطاقة
 * المتجر المشتركة بطقم «المرتبطة»، بلا زرّ سلّة: المخزن لا يحفظ معرّف
 * المنتج ولا صيغه، والعنوان رابطٌ إلى صفحته حيث يُختار ويُشترى.
 */
export function PdpRecent({
  current = null,
  exclude,
  embedded = false,
  className,
}: {
  current?: RecentEntry | null;
  /** ما يُسقط فوق المنتج الحاضر — في السلة: ما فيها أصلًا. */
  exclude?: readonly string[];
  /** داخل عمودٍ (السلة) لا شريطًا بعرض الصفحة. */
  embedded?: boolean;
  /** موضع الشريط في شبكة المستدعي. */
  className?: string;
}) {
  const items = useRecentlyViewed(current?.slug)
    .filter((item) => !exclude?.includes(item.slug))
    .slice(0, SHOWN);

  /* الخاصّيّة تصل من الخادم مرّةً فهويّتها ثابتة: يُسجَّل مرّةً لكلّ منتج. */
  useEffect(() => {
    if (current) recordView(current);
  }, [current]);

  if (items.length === 0) return null;

  return (
    <section
      className={[embedded ? styles.recentEmbedded : `${styles.related} ${styles.recent}`, className].filter(Boolean).join(' ')}
      aria-labelledby="pdp-recent-title"
    >
      <div className={styles.relatedInner}>
        <div className={styles.relatedHead}>
          <div>
            <p className={styles.eyebrow}>{embedded ? 'قبل أن تُتمّ الطلب' : 'تكمل من حيث توقّفت'}</p>
            <h2 className={embedded ? styles.recentTitle : styles.sectionTitle} id="pdp-recent-title">
              شاهدتَ مؤخّرًا
            </h2>
          </div>
          <button type="button" className={styles.recentClear} onClick={clearRecent}>
            امسح السجلّ
          </button>
        </div>
        <div className={styles.cards}>
          {items.map((item) => (
            <ProductCard
              key={item.slug}
              id={item.slug}
              sku={item.slug}
              slug={item.slug}
              title={item.title}
              url={routes.product(item.slug)}
              categoryPath={[]}
              format=""
              priceMinor={item.priceMinor}
              onSale={false}
              image={item.image?.url ? { url: item.image.url, alt: item.image.alt ?? item.title, w: 600, h: 800 } : undefined}
              variant="related"
            />
          ))}
        </div>
      </div>
    </section>
  );
}
