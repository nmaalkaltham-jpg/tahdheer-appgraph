'use client';
/**
 * قائمة المفضّلة — تُقرأ من مخزن المتصفّح، وتُهيَّأ كلّ بطاقةٍ من
 * GET /api/v1/products/{slug} كي تُصيَّر بطاقةُ الكتالوج نفسها (النوع،
 * التقييم، زرّ الإضافة) لا بطاقةٌ مخترَعة الحقول. وما فشل جلبه يبقى
 * صفًّا صادقًا: العنوان والسعر المحفوظان وزرّ الإزالة.
 */
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { ProductCard } from '@tahdheer/ui';
import { formatMoney } from '@tahdheer/design-system/format';
import { CardAddToCart } from '../../components/product/CardAddToCart';
import { cardPreview } from '../(catalog)/_components/ProductGrid';
import { apiBase, encodeSlug, toCardProps } from '../../lib/api';
import { routes } from '../../lib/routes';
import { removeFromWishlist, useWishlist, type WishlistItem } from '../../lib/wishlist';
import { CatalogEmpty } from '../(catalog)/_components/CatalogEmpty';
import gridStyles from '../(catalog)/_components/catalog.module.css';
import styles from './page.module.css';

/** غياب المفتاح = لم يُجلب بعد. */
type Card = { status: 'ready'; dto: ProductCardDto } | { status: 'failed' };

const noop = () => () => {};

async function fetchCard(slug: string): Promise<Card> {
  const base = apiBase();
  if (!base) return { status: 'failed' };
  try {
    const res = await fetch(`${base}/api/v1/products/${encodeSlug(slug)}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    if (!res.ok) return { status: 'failed' };
    const payload = (await res.json()) as { data?: ProductCardDto } | ProductCardDto;
    const dto = 'data' in payload && payload.data ? payload.data : (payload as ProductCardDto);
    return typeof dto.id === 'number' && typeof dto.title === 'string' ? { status: 'ready', dto } : { status: 'failed' };
  } catch {
    return { status: 'failed' };
  }
}

function HeartFilled() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" strokeWidth="1.9" strokeLinejoin="round">
      <path d="M12 20s-7-4.4-7-9.5A3.9 3.9 0 0 1 12 8a3.9 3.9 0 0 1 7 2.5C19 15.6 12 20 12 20z" />
    </svg>
  );
}

function RemoveButton({ item }: { item: WishlistItem }) {
  return (
    <button type="button" className={styles.remove} onClick={() => removeFromWishlist(item.slug)}>
      <HeartFilled />
      <span className={styles.srOnly}>إزالة «{item.title}» من المفضلة</span>
    </button>
  );
}

export function WishlistView() {
  const items = useWishlist();
  const [cards, setCards] = useState<Record<string, Card>>({});
  /* الطلبات المرسَلة — مرجعٌ لا حالة: لا إعادة تصيير ولا تكرار جلبٍ للمعرّف نفسه. */
  const requested = useRef(new Set<string>());
  /* لقطة الخادم فارغة دائمًا؛ الحالة الفارغة لا تُعلَن قبل قراءة المخزن. */
  const hydrated = useSyncExternalStore(noop, () => true, () => false);

  /*
   * الجواب يخصّ المعرّف لا دورة الأثر: تغيّرُ القائمة أثناء الجلب (حذفُ عنصرٍ
   * آخر مثلًا) لا يُلغي جوابًا ما زال مطلوبًا. إلغاؤه مع بقاء المعرّف في
   * `requested` كان يترك العنصر «يُحمَّل» إلى الأبد. وبعد الفكّ لا أثر لـ
   * setState — React يتجاهله — فلا حاجة لحارس.
   */
  useEffect(() => {
    for (const item of items) {
      if (requested.current.has(item.slug)) continue;
      requested.current.add(item.slug);
      void fetchCard(item.slug).then((card) => setCards((prev) => ({ ...prev, [item.slug]: card })));
    }
  }, [items]);

  if (!hydrated) return null;

  if (items.length === 0) {
    return (
      <CatalogEmpty
        icon="files"
        title="لا شيء في المفضلة بعد"
        text="اضغط القلب على أيّ ملفٍّ لتحفظه هنا على هذا المتصفح."
        actions={[
          { label: 'تصفّح المتجر', href: routes.shop, primary: true },
          { label: 'الأكثر مبيعًا', href: routes.bestSellers },
        ]}
      />
    );
  }

  return (
    <ul className={gridStyles.grid} role="list" aria-busy={items.some((i) => cards[i.slug] === undefined)}>
      {items.map((item) => {
        const card = cards[item.slug];
        if (card?.status === 'ready') {
          const props = toCardProps(card.dto);
          return (
            <li key={item.slug}>
              <ProductCard
                {...props}
                actions={<RemoveButton item={item} />}
                ctaSlot={<CardAddToCart productId={props.id} title={props.title} disabled={props.unavailable} />}
                previewSlot={props.unavailable ? undefined : cardPreview(props)}
              />
            </li>
          );
        }
        const price = formatMoney(item.priceMinor);
        return (
          <li key={item.slug} className={styles.thin} data-status={card?.status ?? 'loading'}>
            <a className={styles.thinTitle} href={routes.product(item.slug)}>
              {item.title}
            </a>
            <span className={styles.thinPrice}>
              <bdi dir="ltr">{price.amount}</bdi> {price.currency}
            </span>
            {card?.status === 'failed' ? <span className={styles.thinNote}>تعذّر تحديث بيانات الملفّ</span> : null}
            <RemoveButton item={item} />
          </li>
        );
      })}
    </ul>
  );
}
