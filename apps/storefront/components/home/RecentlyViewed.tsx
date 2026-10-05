'use client';
/**
 * «شاهدتَ مؤخّرًا» في الرئيسة — ذاكرة الزائر من `lib/recently-viewed.ts`
 * (المخزن الواحد)، ببطاقة المتجر الواحدة بطقم `compact` في رفٍّ أفقيّ.
 *
 *  · لقطة الخادم فارغة ⇒ لا شيء في أوّل HTML؛ الرفّ يظهر بعد الإماهة تحت
 *    «الأكثر مبيعًا» (أسفل الطيّة) فلا إزاحة فيما يراه الزائر، ولا حجز فراغٍ لمن لا سجلّ له.
 *  · «امسح السجلّ» يُفرغ المخزن؛ والتركيز ينتقل إلى مرساةٍ ثابتة مع إعلانٍ مهذّب
 *    بدل أن يسقط إلى body مع اختفاء القسم.
 */
import { useRef, useState } from 'react';
import { ProductCard, type ProductCardProps } from '@tahdheer/ui';
import { mediaSrc } from '../../lib/media-url';
import { clearRecent, useRecentlyViewed, type RecentItem } from '../../lib/recently-viewed';
import { routes } from '../../lib/routes';
import { Head } from './DesignHead';
import styles from './design.module.css';

/** العنصر المحفوظ ببطاقة المتجر — ما لم يُحفظ يغيب (لا تقييم ولا صيغة مختلقة). */
function toCard(item: RecentItem): ProductCardProps {
  return {
    id: item.slug,
    sku: item.slug,
    title: item.title,
    slug: item.slug,
    url: routes.product(item.slug),
    categoryPath: item.subtitle ? [item.subtitle] : [],
    format: '',
    priceMinor: item.priceMinor,
    onSale: false,
    ...(item.image?.url
      ? { image: { url: mediaSrc(item.image.url), alt: item.image.alt ?? item.title, w: 376, h: 192 } }
      : {}),
  };
}

export function RecentlyViewed() {
  const items = useRecentlyViewed();
  const hostRef = useRef<HTMLDivElement>(null);
  const [cleared, setCleared] = useState(false);

  const clear = () => {
    clearRecent();
    setCleared(true);
    hostRef.current?.focus();
  };

  return (
    <div ref={hostRef} tabIndex={-1} className={styles.recentHost}>
      <p className={styles.srOnly} role="status">
        {cleared && items.length === 0 ? 'مُسح سجلّ المشاهدة.' : ''}
      </p>
      {items.length > 0 ? (
        <section className={`${styles.section} ${styles.wrap} ${styles.recent}`} aria-labelledby="recent-title">
          <div className={styles.recentHead}>
            <Head eyebrow="من تصفّحك" title={<span id="recent-title">شاهدتَ مؤخّرًا</span>} />
            <button type="button" className={styles.recentClear} onClick={clear}>
              امسح السجلّ
            </button>
          </div>
          <ul className={styles.recentRail} role="list">
            {items.map((item) => (
              <li key={item.slug}>
                <ProductCard {...toCard(item)} variant="compact" />
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  );
}
