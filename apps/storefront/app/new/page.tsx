/**
 * /new — «وصل حديثًا» (MIP §3.2 route matrix).
 *
 * GET /api/v1/products?source=new returns published products newest publish
 * date first, so the ordering on this page IS the recency claim it makes —
 * nothing here is curated by hand and no «جديد» badge is invented for a file
 * the catalog did not flag.
 */
import type { Metadata } from 'next';
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { qty } from '@tahdheer/design-system/format';
import { apiGetCollection, toCardProps } from '../../lib/api';
import { routes } from '../../lib/routes';
import { CatalogEmpty } from '../(catalog)/_components/CatalogEmpty';
import { CatalogFailure } from '../(catalog)/_components/CatalogFailure';
import { CatalogHero } from '../(catalog)/_components/CatalogHero';
import { ProductGrid } from '../(catalog)/_components/ProductGrid';
import { collectionRobots, PRODUCT_FORMS } from '../(catalog)/_components/query';
import styles from '../(catalog)/_components/catalog.module.css';

const TITLE = 'وصل حديثًا';
const DESCRIPTION =
  'أحدث ما نُشر في متجر التحاضير الحديثة — مرتّبًا من الأحدث إلى الأقدم حسب تاريخ النشر.';

/** The API ceiling for one list request. */
const LIMIT = 24;

const CRUMBS = [{ label: 'الرئيسية', href: routes.home }, { label: TITLE }];

function fetchNew() {
  return apiGetCollection<ProductCardDto>(`/api/v1/products?source=new&limit=${LIMIT}`);
}

export async function generateMetadata(): Promise<Metadata> {
  const { data } = await fetchNew();

  return {
    title: TITLE,
    description: DESCRIPTION,
    alternates: { canonical: routes.newArrivals },
    robots: collectionRobots(data.length, false),
  };
}

export default async function NewArrivalsPage() {
  const { data, status } = await fetchNew();

  if (status !== 200) {
    return <CatalogFailure crumbs={CRUMBS} title={TITLE} retryHref={routes.newArrivals} />;
  }

  return (
    <div className={styles.page}>
      <CatalogHero
        crumbs={CRUMBS}
        title={TITLE}
        intro="آخر الملفات التي أُضيفت إلى المتجر، مرتّبة حسب تاريخ النشر — الأحدث أولًا. تُحدَّث القائمة تلقائيًا مع كل ملف جديد."
        facts={[
          { icon: 'plus', text: 'مرتّبة حسب تاريخ النشر' },
          { icon: 'download', text: 'تحميل فوري بعد الدفع' },
        ]}
      />

      {data.length > 0 ? (
        <>
          <p className={styles.resultLine}>{qty(data.length, PRODUCT_FORMS)} في القائمة</p>
          <ProductGrid items={data.map(toCardProps)} priorityFirst={4} />
        </>
      ) : (
        <CatalogEmpty
          icon="files"
          title="لا توجد منتجات منشورة بعد"
          text="سيظهر كل ملف هنا لحظة نشره — لا نعرض عناصر تجريبية في الأثناء."
          actions={[{ label: 'تصفّح كل المنتجات', href: routes.shop, primary: true }]}
        />
      )}
    </div>
  );
}
