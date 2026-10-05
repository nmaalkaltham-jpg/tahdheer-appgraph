/**
 * /best-sellers — «الأكثر مبيعًا» (MIP §3.2 route matrix).
 *
 * The list is GET /api/v1/products?source=best, whose ranking is the number of
 * units actually sold on orders whose money was really collected. A product
 * with zero paid lines is EXCLUDED by the API rather than ranked last, so an
 * empty grid here is the truthful answer before the first sale — it renders
 * the empty state, never filler and never «newest» wearing a bestseller label.
 *
 * The rank badge on each card IS real: it is the position in the ranked list
 * the API returned, not a decoration.
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

const TITLE = 'الأكثر مبيعًا';
const DESCRIPTION =
  'الملفات الأكثر مبيعًا في التحاضير الحديثة — الترتيب من عدد النسخ المُباعة فعليًا على طلبات مدفوعة.';

/** The API ceiling for one list request. */
const LIMIT = 24;

const CRUMBS = [{ label: 'الرئيسية', href: routes.home }, { label: TITLE }];

function fetchBest() {
  return apiGetCollection<ProductCardDto>(`/api/v1/products?source=best&limit=${LIMIT}`);
}

export async function generateMetadata(): Promise<Metadata> {
  const { data } = await fetchBest();

  return {
    title: TITLE,
    description: DESCRIPTION,
    alternates: { canonical: routes.bestSellers },
    robots: collectionRobots(data.length, false),
  };
}

export default async function BestSellersPage() {
  const { data, status } = await fetchBest();

  if (status !== 200) {
    return <CatalogFailure crumbs={CRUMBS} title={TITLE} retryHref={routes.bestSellers} />;
  }

  return (
    <div className={styles.page}>
      <CatalogHero
        crumbs={CRUMBS}
        title={TITLE}
        intro="الترتيب مبنيّ على عدد النسخ المُباعة فعليًا على طلبات اكتمل دفعها — لا ترتيب تحريري ولا تخمين. الملف الذي لم يُبَع بعد لا يظهر في هذه القائمة."
        facts={[
          { icon: 'trophy', text: 'الترتيب من طلبات مدفوعة' },
          { icon: 'download', text: 'تحميل فوري بعد الدفع' },
        ]}
      />

      {data.length > 0 ? (
        <>
          <p className={styles.resultLine}>{qty(data.length, PRODUCT_FORMS)} في القائمة</p>
          <ProductGrid
            priorityFirst={4}
            items={data.map((dto, index) => ({
              ...toCardProps(dto),
              rank: index + 1,
              // «الأكثر طلبًا» للأوّل وحده: الترتيب من وحداتٍ مُباعة على طلباتٍ مدفوعة.
              bestSeller: index === 0,
            }))}
          />
        </>
      ) : (
        <CatalogEmpty
          icon="trophy"
          title="لا توجد مبيعات مدفوعة بعد"
          text="تُبنى هذه القائمة من الطلبات المدفوعة وحدها، فتبقى فارغة حتى أول عملية شراء — ولا نملؤها بملفات أخرى في الأثناء."
          actions={[
            { label: 'تصفّح كل المنتجات', href: routes.shop, primary: true },
            { label: 'وصل حديثًا', href: routes.newArrivals },
          ]}
        />
      )}
    </div>
  );
}
