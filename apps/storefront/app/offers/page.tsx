/**
 * /offers — «العروض الحالية» (MIP §3.2 route matrix).
 *
 * Composition: the offers header of «Tahdheer - Store Pages» (eyebrow pill,
 * discs, headline, one honest line of terms) over the shared catalog grid.
 * That artboard's brand and shipping model are NOT canonical and are not
 * ported; neither are its two data-shaped blocks — the WELCOME coupon strip
 * and the «شروط العروض» list — because no endpoint backs a coupon or offer
 * terms today, and hardcoding them would dress editorial invention as data.
 *
 * There is no «products on sale» endpoint, so this page BROWSES the catalog
 * through GET /api/v1/search (no q, sort=newest for a stable window) and keeps
 * the rows the pricing service already marked `onSale` — a flag the server
 * sets only while a sale is really active. Consequences of that, stated on
 * screen rather than hidden:
 *   · the discount ceiling is shown only when the scan covered the WHOLE
 *     catalog, otherwise it could understate the real maximum;
 *   · a partial scan says how much it looked at and offers to look further.
 *
 * The design's «تسري حتى ١٥ سبتمبر» is a sample: the date here is the earliest
 * real `saleTo` among the offers on screen, and disappears entirely when no
 * offer carries an end date. No countdown is ever rendered.
 */
import type { Metadata } from 'next';
import { formatNumber, qty } from '@tahdheer/design-system/format';
import {
  fetchSearch,
  searchCardToProps,
  type SearchProductCard,
  type SearchResponse,
} from '../../lib/search-api';
import { longDateOf } from '../../lib/date-format';
import { routes } from '../../lib/routes';
import { CatalogEmpty } from '../(catalog)/_components/CatalogEmpty';
import { CatalogFailure } from '../(catalog)/_components/CatalogFailure';
import { CatalogHero, type HeroFact } from '../(catalog)/_components/CatalogHero';
import { ProductGrid } from '../(catalog)/_components/ProductGrid';
import { catalogHref, collectionRobots, OFFER_FORMS } from '../(catalog)/_components/query';
import { PendingLink } from '../(catalog)/_components/PendingLink';
import styles from '../(catalog)/_components/catalog.module.css';

const TITLE = 'العروض الحالية';
const DESCRIPTION =
  'الملفات التي عليها خصم ساري الآن في التحاضير الحديثة — السعر قبل الخصم وبعده على كل بطاقة.';

/**
 * Scan ceiling. Each step re-reads pages 1..n of the catalog server-side, so
 * the depth is bounded; the page says when it stopped short.
 */
const MAX_SCAN = 4;

const CRUMBS = [{ label: 'الرئيسية', href: routes.home }, { label: TITLE }];

type Search = Promise<{ page?: string }>;

interface OfferScan {
  offers: SearchProductCard[];
  /** How many products the scan actually read. */
  scanned: number;
  /** How many published products exist in total (from the API). */
  total: number;
}

/** Read pages 1..depth of the catalog and keep the rows on a live sale. */
async function scanOffers(depth: number): Promise<OfferScan | null> {
  const responses = await Promise.all(
    Array.from({ length: depth }, (_, index) => fetchSearch({ sort: 'newest', page: index + 1 })),
  );

  const pages = responses.map((response) => response.data);
  const last = pages.at(-1);
  // A hole in the scan would silently hide live offers — refuse to guess.
  if (pages.some((data) => data === null) || !last) return null;

  const items = pages
    .filter((data): data is SearchResponse => data !== null)
    .flatMap((data) => data.results.items);

  return {
    offers: items.filter((item) => item.onSale),
    scanned: items.length,
    total: last.results.total,
  };
}

function parseDepth(raw: string | undefined): number {
  return Math.min(MAX_SCAN, Math.max(1, Number.parseInt(raw ?? '1', 10) || 1));
}

/** The earliest real end date among the offers on screen, or null. */
function earliestEnd(offers: SearchProductCard[]): string | null {
  const times = offers
    .map((offer) => (offer.saleTo ? new Date(offer.saleTo).getTime() : Number.NaN))
    .filter((time) => !Number.isNaN(time));
  if (times.length === 0) return null;

  return longDateOf(new Date(Math.min(...times)));
}

export async function generateMetadata({
  searchParams,
}: {
  searchParams: Search;
}): Promise<Metadata> {
  const { page } = await searchParams;
  // A deeper scan is a paged URL: it canonicalises to /offers and is noindex,
  // so only the default view is ever weighed for indexation.
  const scan = page ? null : await scanOffers(1);

  return {
    title: TITLE,
    description: DESCRIPTION,
    alternates: { canonical: routes.offers },
    robots: collectionRobots(scan?.offers.length ?? 0, Boolean(page)),
  };
}

export default async function OffersPage({ searchParams }: { searchParams: Search }) {
  const sp = await searchParams;
  const depth = parseDepth(sp.page);
  const scan = await scanOffers(depth);

  if (!scan) {
    return (
      <CatalogFailure
        crumbs={CRUMBS}
        title={TITLE}
        retryHref={catalogHref(routes.offers, { page: depth })}
      />
    );
  }

  const { offers, scanned, total } = scan;
  const complete = scanned >= total;
  const ceiling = offers.reduce((max, offer) => Math.max(max, offer.discountPct ?? 0), 0);
  const endsAt = earliestEnd(offers);

  const facts: HeroFact[] = [];
  // A ceiling from a partial scan could understate the real maximum, so it is
  // claimed only once the scan has covered the whole catalog.
  if (complete && ceiling > 0) {
    facts.push({
      icon: 'tag',
      text: (
        <>
          الخصم الأعلى الآن <bdi dir="ltr">{formatNumber(ceiling)}٪</bdi>
        </>
      ),
    });
  }
  if (endsAt) facts.push({ icon: 'clock', text: `أقرب عرض ينتهي في ${endsAt}` });
  facts.push({ icon: 'download', text: 'تحميل فوري بعد الدفع' });

  return (
    <div className={styles.page}>
      <CatalogHero
        ornate
        crumbs={CRUMBS}
        title={TITLE}
        eyebrow={offers.length > 0 ? { icon: 'bolt', label: 'خصومات سارية الآن' } : undefined}
        intro="كل خصم هنا محسوب على السعر المعتاد المسجَّل للملف نفسه، ويختفي من الصفحة فور انتهاء مدته. لا عدّادات تنازلية ولا سعر «قبل» مصنوع لإظهار خصم."
        facts={facts}
      />

      {offers.length > 0 ? (
        <>
          <p className={styles.resultLine}>
            {complete ? (
              `${qty(offers.length, OFFER_FORMS)} الآن`
            ) : (
              <>
                {qty(offers.length, OFFER_FORMS)} ضمن أول <bdi dir="ltr">{formatNumber(scanned)}</bdi>{' '}
                من <bdi dir="ltr">{formatNumber(total)}</bdi> منتجًا
              </>
            )}
          </p>

          {/* تسلسلُ العناوين: `h1` ثمّ بطاقاتٌ عناوينُها `h3` — والقفزة تُسدّ هنا. */}
          <h2 className={styles.srOnly}>قائمة العروض</h2>
          <ProductGrid items={offers.map(searchCardToProps)} />

          {!complete && depth < MAX_SCAN ? (
            <div className={styles.pager}>
              <PendingLink
                className={styles.pagerLink}
                href={catalogHref(routes.offers, { page: depth + 1 })}
              >
                ابحث عن عروض في منتجات أقدم
              </PendingLink>
            </div>
          ) : null}
        </>
      ) : (
        <CatalogEmpty
          icon="tag"
          title="لا توجد عروض سارية الآن"
          text={
            complete
              ? 'كل الأسعار المعروضة حاليًا هي الأسعار المعتادة. حين يبدأ خصم حقيقي سيظهر هنا بسعره قبل وبعد.'
              : 'لم نجد خصمًا ساريًا ضمن المنتجات التي فحصناها. جرّب الفحص أعمق أو تصفّح المتجر كاملًا.'
          }
          actions={[
            { label: 'تصفّح كل المنتجات', href: routes.shop, primary: true },
            ...(!complete && depth < MAX_SCAN
              ? [{ label: 'ابحث في منتجات أقدم', href: catalogHref(routes.offers, { page: depth + 1 }) }]
              : [{ label: 'الأكثر مبيعًا', href: routes.bestSellers }]),
          ]}
        />
      )}
    </div>
  );
}
