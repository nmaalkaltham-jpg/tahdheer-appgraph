/**
 * The catalog product grid — 2 columns on the artboard's 390px width,
 * 3 from 900px, exactly as «Tahdheer - Category» draws it.
 *
 * Every card is the shared @tahdheer/ui ProductCard, so rating rows, discount
 * pills and was-prices obey ONE honesty implementation across the storefront.
 *
 * The footer CTA is the real add-to-cart POST (a client island inside this
 * server component), never a link dressed up as a button.
 */
import { ProductCard, type ProductCardProps } from '@tahdheer/ui';
import { CardAddToCart } from '../../../components/product/CardAddToCart';
import { CardWishlist } from '../../../components/product/CardWishlist';
import { cardFallbackArt } from '../../../components/product/CardArt';
import { QuickView } from '../../../components/product/QuickView';
import styles from './catalog.module.css';

/**
 * `grid` هو الافتراض ولا يتغيّر.
 *
 * `rail` شريطٌ أفقيّ يُطلب صراحةً من موضعٍ واحد (المرتبطة في صفحة المنتج)،
 * ولا يسري إلّا دون ‎641px‎؛ فما لم يُمرَّر، كلّ سطح كتالوجٍ كما كان.
 */
export type ProductGridLayout = 'grid' | 'rail';

/**
 * قلبُ المفضّلة للبطاقة — يُشتقّ من بيانات البطاقة نفسها فلا يُختلق شيء.
 * ولا قلب لمنتجٍ بلا عرضٍ فعّال: عنصر المفضّلة يحفظ سعرًا، وسعرُ البطاقة هنا
 * صفرٌ اصطلاحيّ لا سعر — حفظُه يعرض «0.00» لاحقًا في صفحة المفضّلة.
 */
export function cardWishlist(item: ProductCardProps) {
  if (item.unavailable) return null;
  return (
    <CardWishlist
      slug={item.slug}
      title={item.title}
      priceMinor={item.priceMinor}
      image={item.image ? { url: item.image.url, alt: item.image.alt } : null}
      subtitle={[...item.categoryPath].reverse().join(' · ') || null}
    />
  );
}

/**
 * أفعال الغلاف في بطاقة الكتالوج: القلب وحده في زاوية النهاية (هدف لمس ‎44px‎)؛
 * «معاينة» صارت زرًّا مسمًّى في صفّ الأزرار (`cardPreview`) لا عينًا عائمة.
 */
export function cardActions(item: ProductCardProps) {
  return cardWishlist(item);
}

/** زرّ «معاينة» المسمّى بجوار «أضف للسلة» — المعاينة السريعة نفسها (نافذة `<dialog>`). */
export function cardPreview(item: ProductCardProps) {
  return (
    <QuickView
      appearance="button"
      product={{
        id: item.id,
        slug: item.slug,
        title: item.title,
        url: item.url,
        categoryPath: item.categoryPath,
        priceMinor: item.priceMinor,
        regularMinor: item.regularMinor,
        discountPct: item.discountPct,
        onSale: item.onSale,
        ratingAvg: item.ratingAvg,
        ratingCount: item.ratingCount,
        image: item.image,
      }}
    />
  );
}

/**
 * `priorityFirst`: عدد البطاقات الأولى التي يُجلب غلافها فورًا بأولويّةٍ عالية —
 * للشبكات التي تفتح بها الصفحة (الأرشيف، الأكثر مبيعًا، وصل حديثًا). الافتراض
 * صفر: شبكةٌ تحت الطيّة (الرئيسة) تبقى كسولة ولا تزاحم صورة الهيرو.
 */
export function ProductGrid({
  items,
  layout = 'grid',
  priorityFirst = 0,
}: {
  items: ProductCardProps[];
  layout?: ProductGridLayout;
  priorityFirst?: number;
}) {
  return (
    <ul className={layout === 'rail' ? `${styles.grid} ${styles.rail}` : styles.grid} role="list">
      {items.map((item, index) => (
        <li key={item.id}>
          <ProductCard
            {...item}
            priority={index < priorityFirst}
            ctaSlot={<CardAddToCart productId={item.id} title={item.title} disabled={item.unavailable} />}
            previewSlot={item.unavailable ? undefined : cardPreview(item)}
            actions={cardActions(item)}
            mediaSlot={cardFallbackArt(item.categoryPath, Boolean(item.image))}
          />
        </li>
      ))}
    </ul>
  );
}
