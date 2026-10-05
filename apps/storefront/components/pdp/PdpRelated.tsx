import { ProductCard, productCardCtaClass, type ProductCardProps } from '@tahdheer/ui';
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { cardFallbackArt } from '../product/CardArt';
import { cardPreview, cardWishlist } from '../../app/(catalog)/_components/ProductGrid';
import { toCardProps } from '../../lib/api';
import { routes } from '../../lib/routes';
import { PdpAddButton } from './PdpAddButton';
import styles from './pdp.module.css';

/**
 * Related rail — Storefront Product.dc.html «يُشترى معه عادةً» (4-up grid) and
 * its mobile horizontal rail. Heading, eyebrow and «see all» link come from
 * the caller so the same cards serve the real bought-together relation or,
 * without one, the store's paid best-sellers under an honest title.
 *
 * Card: the stored cover fills the thumb (the design's grey lines stand in
 * for a page image; with no image the tinted thumb stays empty). The type
 * chip is the product's own resource type; the rating renders only with both
 * a real average and a real count.
 */
type CardDto = ProductCardDto & { resourceType?: string | null };

type Hue = 'navy' | 'gold' | 'lilac' | 'teal';

/* صبغة ورقة البديل في البطاقة المشتركة — ذهبيّ القالب = رمل الرموز. */
const TINT: Record<Hue, NonNullable<ProductCardProps['tint']>> = { navy: 'navy', gold: 'sand', lilac: 'lilac', teal: 'teal' };

/** The design's four chip/thumb hues, chosen by the kind of file. */
function hueOf(type: string): Hue {
  if (/أوراق|ورقة/.test(type)) return 'gold';
  if (/اختبار|أسئلة|تقويم/.test(type)) return 'lilac';
  if (/ملخ|تحضير/.test(type)) return 'teal';
  return 'navy';
}

export interface PdpRelatedProps {
  id: string;
  /** معرّف القسم نفسه — مرساة شريط الأقسام. */
  sectionId?: string;
  eyebrow: string;
  title: string;
  more?: { href: string; label: string; short: string };
  items: CardDto[];
}

export function PdpRelated({ id, sectionId, eyebrow, title, more, items }: PdpRelatedProps) {
  if (items.length === 0) return null;

  return (
    <section className={styles.related} id={sectionId} aria-labelledby={id}>
      <div className={styles.relatedInner}>
        <div className={styles.relatedHead}>
          <div>
            <p className={styles.eyebrow}>{eyebrow}</p>
            <h2 className={styles.sectionTitle} id={id}>
              {title}
            </h2>
          </div>
          {more ? (
            <a className={styles.relatedMore} href={more.href}>
              <span className={styles.relatedMoreLong}>{more.label}</span>
              <span className={styles.relatedMoreShort}>{more.short}</span>
            </a>
          ) : null}
        </div>
        <div className={styles.cards}>
          {items.map((item) => {
            const type = item.resourceType?.trim() ?? '';
            /* البطاقة الواحدة: المسار والنوع والصيغة من الردّ نفسه — لا نوعٌ يتنكّر صيغةً. */
            const props = toCardProps(item);
            return (
              <ProductCard
                key={item.id}
                {...props}
                url={item.url || routes.product(item.slug)}
                variant="related"
                tint={TINT[hueOf(type)]}
                mediaSlot={cardFallbackArt(item.categoryPath, Boolean(item.image))}
                actions={cardWishlist(props)}
                previewSlot={props.unavailable ? undefined : cardPreview(props)}
                ctaSlot={<PdpAddButton productId={item.id} title={item.title} className={productCardCtaClass} disabled={props.unavailable} />}
              />
            );
          })}
        </div>
      </div>
    </section>
  );
}
