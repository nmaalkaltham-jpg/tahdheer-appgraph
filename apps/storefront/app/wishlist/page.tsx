/**
 * /wishlist — «المفضّلة».
 *
 * لا ملفّ تصميمٍ لهذه الصفحة؛ تُبنى من مكوّنات الكتالوج نفسها (رأس القسم،
 * شبكة البطاقات، الحالة الفارغة). المخزن على المتصفّح (lib/wishlist.ts)
 * فالقائمة تُصيَّر في العميل، ويُهيَّأ لكلّ عنصرٍ بطاقته من الواجهة بمعرّفه
 * الثابت (slug) — لا يُخترع لها نوعُ ملفٍّ ولا تقييم.
 */
import type { Metadata } from 'next';
import { routes } from '../../lib/routes';
import { CatalogHero } from '../(catalog)/_components/CatalogHero';
import styles from '../(catalog)/_components/catalog.module.css';
import { WishlistView } from './WishlistView';

const TITLE = 'المفضلة';

export const metadata: Metadata = {
  title: TITLE,
  description: 'الملفات التي حفظتها في المفضلة على هذا المتصفح.',
  alternates: { canonical: routes.wishlist },
  /* قائمةٌ شخصيّة على المتصفّح — لا محتوى للفهرسة. */
  robots: { index: false, follow: true },
};

export default function WishlistPage() {
  return (
    <div className={styles.page}>
      <CatalogHero crumbs={[{ label: 'الرئيسية', href: routes.home }, { label: TITLE }]} title={TITLE} />
      <WishlistView />
    </div>
  );
}
