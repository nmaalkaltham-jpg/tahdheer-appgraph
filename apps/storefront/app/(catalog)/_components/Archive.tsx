/**
 * The catalog archive — «Tahdheer - Category» in full: gradient hero + facts,
 * sticky facet rail (mobile: a <dialog> drawer — FilterDrawer.tsx), sort tabs
 * control, removable chips, load-more with «تعرض X من Y», and the
 * narrowest-filter empty state.
 *
 * Facets are LINK-BASED GET filters: crawlable, shareable, and fully working
 * without JavaScript. Counts render ONLY when the API ships them — the search
 * contract currently returns counts for type tabs only, so the dimension rails
 * render real taxonomy labels with no number beside them rather than a
 * plausible-looking guess.
 *
 * Used by /shop (all four dimensions) and /subjects/[slug] (the subject is
 * fixed by the route, so its rail is dropped).
 */
import { formatNumber, qty, type PluralForms } from '@tahdheer/design-system/format';
import { routes } from '../../../lib/routes';
import { searchCardToProps, type SearchProductCard, type SearchSort } from '../../../lib/search-api';
import { CatalogEmpty, type EmptyAction } from './CatalogEmpty';
import { CatalogHero, type CatalogHeroArt, type Crumb, type HeroFact } from './CatalogHero';
import { FilterDrawer } from './FilterDrawer';
import { PendingLink } from './PendingLink';
import { ProductGrid } from './ProductGrid';
import { StickyToolbar } from './StickyToolbar';
import { CatalogIcon } from './icons';
import {
  catalogHref,
  FACET_KEYS,
  FILE_FORMS,
  MAX_PAGES,
  PRODUCT_FORMS,
  SORT_KEYS,
  SORT_LABELS,
  type FacetKey,
  type FacetState,
} from './query';
import styles from './catalog.module.css';

interface ArchiveFacetOption {
  slug: string;
  label: string;
  /** Rendered only when the API supplied it — never derived, never faked. */
  count?: number;
}

export interface ArchiveFacetGroup {
  key: FacetKey;
  title: string;
  options: ArchiveFacetOption[];
}

export interface ArchiveProps {
  /** Clean archive path, e.g. `/shop` or `/subjects/رياضيات`. */
  basePath: string;
  crumbs: Crumb[];
  title: string;
  intro?: string;
  /** Completes the count phrases, e.g. «في المتجر» / «في الرياضيات». */
  scopeLabel: string;
  /** Extra hero facts appended after the file count and delivery promise. */
  extraFacts?: HeroFact[];
  /** رسم المفردة في الترويسة (أرشيفات المرحلة/الصفّ/المادّة) — يمرّ إلى CatalogHero كما هو. */
  heroArt?: CatalogHeroArt;
  facets: FacetState;
  sort?: string;
  page: number;
  groups: ArchiveFacetGroup[];
  items: SearchProductCard[];
  total: number;
  /**
   * Page size the API actually applied (`results.perPage`), used to say how
   * many products the next step brings. Absent ⇒ the button keeps the generic
   * label rather than naming a figure the response never reported.
   */
  perPage?: number;
  /** The sort the API actually applied (echoed by the search response). */
  activeSort: SearchSort;
  /** Shown when the archive itself is empty — no filter is to blame. */
  emptyTitle: string;
  emptyText: string;
  /** Real destinations offered by that empty state (never a dead control). */
  emptyActions?: EmptyAction[];
  seo?: { title: string; text: string };
  /**
   * روابط أرشيفات شقيقة — تُمرَّر جاهزةً من الخادم ولا تُركَّب هنا.
   *
   * كلّ عنصر عنوانه من مصدره (`hub.path` مثلًا)، فلا يُعلَن مسارٌ لم يقل
   * الخادمُ إنّه مخدوم. وقائمةٌ فارغة تعني لا قسم أصلًا لا قسمًا فارغًا.
   */
  related?: { title: string; links: readonly { label: string; href: string }[] };
}

/**
 * The load-more object, «حمّل __» — the noun AND its adjective decline
 * together, so «12 منتجًا آخر» (the design's own wording) has correct
 * counterparts at 1, 2 and 3–10 instead of a plural noun beside a singular
 * «آخر».
 */
const MORE_FORMS: PluralForms = {
  zero: 'كل المنتجات', // unreachable: the button renders only while more remain
  one: 'منتجًا واحدًا آخر',
  two: 'منتجين آخرين',
  few: 'منتجات أخرى',
  many: 'منتجًا آخر',
};

function labelFor(slug: string, options: ArchiveFacetOption[]): string {
  return options.find((option) => option.slug === slug)?.label ?? slug;
}

function countFor(slug: string, options: ArchiveFacetOption[]): number | undefined {
  return options.find((option) => option.slug === slug)?.count;
}

/** كم خيارًا يظهر قبل «عرض المزيد» — ما بعده خلف إفصاحٍ أصليّ. */
const VISIBLE_OPTIONS = 5;

/** «اعرض __» في درج الجوّال — العدد عدد الخادم لهذه المرشّحات. */
const RESULT_FORMS: PluralForms = {
  zero: '',
  one: 'النتيجة',
  two: 'النتيجتين',
  few: 'نتائج',
  many: 'نتيجة',
};

function FacetOption({
  basePath,
  facets,
  sort,
  groupKey,
  option,
}: {
  basePath: string;
  facets: FacetState;
  sort?: string;
  groupKey: FacetKey;
  option: ArchiveFacetOption;
}) {
  const active = facets[groupKey] === option.slug;
  return (
    <li>
      <a
        className={styles.facetLink}
        href={catalogHref(basePath, {
          facets: { ...facets, [groupKey]: active ? undefined : option.slug },
          sort,
        })}
        aria-current={active ? 'true' : undefined}
      >
        {/* مؤشّر اختيارٍ مفرد: مرشّحٌ واحد لكلّ بُعد، والنقر على المختار يلغيه. */}
        <span className={styles.facetMark} aria-hidden="true">
          {active ? <CatalogIcon name="check" strokeWidth={3.2} className={styles.tick} /> : null}
        </span>
        <span className={styles.facetLabel}>{option.label}</span>
        {option.count !== undefined ? (
          <span className={styles.facetCount} dir="ltr">
            {formatNumber(option.count)}
          </span>
        ) : null}
      </a>
    </li>
  );
}

/**
 * لوحة الترشيح: مجموعاتٌ قابلة للطيّ (`<details>` أصليّ — يعمل بلا JavaScript
 * ويُعلن حالته)، وكلّ مجموعةٍ تعرض أوّل خمسة خيارات وتطوي الباقي خلف «عرض
 * المزيد (N)». المختارُ المطويّ يفتح طيّته فلا يختفي اختيارٌ عن صاحبه.
 * تُرسم مرّتين — الرفّ العريض ودرج الجوّال — وواحدةٌ منهما فقط معروضة.
 */
function FacetPanel({
  basePath,
  facets,
  sort,
  groups,
  activeCount,
}: {
  basePath: string;
  facets: FacetState;
  sort?: string;
  groups: ArchiveFacetGroup[];
  activeCount: number;
}) {
  return (
    <div className={styles.panel}>
      <div className={styles.panelHead}>
        <h2 className={styles.panelTitle}>تصفية</h2>
        {activeCount > 0 ? (
          <a className={styles.clearBtn} href={catalogHref(basePath, { sort })}>
            امسح (<bdi dir="ltr">{formatNumber(activeCount)}</bdi>)
          </a>
        ) : null}
      </div>

      <div className={styles.facetGroups}>
        {groups.map((group) => {
          const selected = group.options.find((option) => option.slug === facets[group.key]);
          const visible = group.options.slice(0, VISIBLE_OPTIONS);
          const rest = group.options.slice(VISIBLE_OPTIONS);
          const selectedInRest = selected !== undefined && rest.includes(selected);
          return (
            <details key={group.key} className={styles.facetGroup} open>
              <summary className={styles.facetSummary}>
                <span className={styles.facetTitle}>{group.title}</span>
                {selected ? <span className={styles.facetPicked}>{selected.label}</span> : null}
                <CatalogIcon name="chev" strokeWidth={2.4} className={styles.facetChev} />
              </summary>
              <ul className={styles.facetList} role="list" aria-label={group.title}>
                {visible.map((option) => (
                  <FacetOption
                    key={option.slug}
                    basePath={basePath}
                    facets={facets}
                    sort={sort}
                    groupKey={group.key}
                    option={option}
                  />
                ))}
              </ul>
              {rest.length > 0 ? (
                <details className={styles.facetMore} open={selectedInRest}>
                  <summary className={styles.facetMoreBtn}>
                    <span className={styles.moreShow}>
                      عرض المزيد (<bdi dir="ltr">{formatNumber(rest.length)}</bdi>)
                    </span>
                    <span className={styles.moreHide}>عرض أقل</span>
                  </summary>
                  <ul className={styles.facetList} role="list" aria-label={`${group.title} — المزيد`}>
                    {rest.map((option) => (
                      <FacetOption
                        key={option.slug}
                        basePath={basePath}
                        facets={facets}
                        sort={sort}
                        groupKey={group.key}
                        option={option}
                      />
                    ))}
                  </ul>
                </details>
              ) : null}
            </details>
          );
        })}
      </div>
    </div>
  );
}

export function Archive({
  basePath,
  crumbs,
  title,
  intro,
  scopeLabel,
  extraFacts,
  heroArt,
  facets,
  sort,
  page,
  groups,
  items,
  total,
  perPage,
  activeSort,
  related,
  emptyTitle,
  emptyText,
  emptyActions,
  seo,
}: ArchiveProps) {
  const shown = groups.filter((group) => group.options.length > 0);

  // Chips run narrowest-dimension-first (kind › semester › subject › stage),
  // which is the reverse of the URL order.
  const chips = [...FACET_KEYS]
    .reverse()
    .flatMap((key) => {
      const value = facets[key];
      const group = shown.find((candidate) => candidate.key === key);
      if (!value || !group) return [];
      return [
        {
          key,
          label: labelFor(value, group.options),
          count: countFor(value, group.options),
        },
      ];
    });

  // The narrowest active filter = the smallest REAL count; with no counts the
  // fixed dimension order above stands in for it.
  const narrowest = [...chips].sort(
    (a, b) => (a.count ?? Number.POSITIVE_INFINITY) - (b.count ?? Number.POSITIVE_INFINITY),
  )[0];
  const hasFilters = chips.length > 0;

  // «حمّل 12 منتجًا آخر» — the button names what the NEXT step actually
  // brings: the API's own page size, capped by what is left to load. Both
  // numbers come from the response; neither is a constant from the artboard.
  const remaining = Math.max(0, total - items.length);
  const nextBatch = perPage && perPage > 0 ? Math.min(perPage, remaining) : undefined;
  const moreLabel =
    nextBatch === undefined
      ? 'حمّل المزيد من المنتجات'
      : `حمّل ${qty(nextBatch, MORE_FORMS)}`;

  const resultLabel =
    total === 0
      ? 'لا منتج يطابق المرشّحات'
      : hasFilters
        ? `${qty(total, PRODUCT_FORMS)} يطابق مرشّحاتك`
        : `${qty(total, PRODUCT_FORMS)} ${scopeLabel}`;

  const panel = (
    <FacetPanel
      basePath={basePath}
      facets={facets}
      sort={sort}
      groups={shown}
      activeCount={chips.length}
    />
  );

  return (
    <div className={styles.page}>
      <CatalogHero
        crumbs={crumbs}
        title={title}
        intro={intro}
        art={heroArt}
        facts={[
          { icon: 'files', text: `${qty(total, FILE_FORMS)} ${scopeLabel}` },
          { icon: 'download', text: 'تحميل فوري بعد الدفع' },
          ...(extraFacts ?? []),
        ]}
      />

      <div className={shown.length > 0 ? styles.layout : `${styles.layout} ${styles.layoutFull}`}>
        {shown.length > 0 ? (
          <aside className={styles.sidebar} aria-label="تصفية النتائج">
            {panel}
          </aside>
        ) : null}

        <div className={styles.main}>
          <StickyToolbar>
            {/* الجوّال: الرفّ درجٌ جانبيّ بستارة وحبس تركيز وزرّ «اعرض N نتيجة». */}
            {shown.length > 0 ? (
              <div className={styles.sheet}>
                <FilterDrawer
                  activeCount={chips.length}
                  applyLabel={total === 0 ? 'أغلق' : `اعرض ${qty(total, RESULT_FORMS)}`}
                  clearHref={catalogHref(basePath, { sort })}
                >
                  {panel}
                </FilterDrawer>
              </div>
            ) : null}

            <p className={styles.resultLabel}>{resultLabel}</p>

            <nav className={styles.sortScroller} aria-label="ترتيب النتائج">
              <ul className={styles.sortTabs} role="list">
                {SORT_KEYS.map((key) => (
                  <li key={key}>
                    <a
                      className={styles.sortTab}
                      href={catalogHref(basePath, { facets, sort: key })}
                      aria-current={activeSort === key ? 'page' : undefined}
                    >
                      {SORT_LABELS[key]}
                    </a>
                  </li>
                ))}
              </ul>
            </nav>
          </StickyToolbar>

          {hasFilters ? (
            <div className={styles.chips} role="list" aria-label="الفلاتر المفعّلة">
              {chips.map((chip) => (
                <a
                  key={chip.key}
                  role="listitem"
                  className={styles.chip}
                  href={catalogHref(basePath, {
                    facets: { ...facets, [chip.key]: undefined },
                    sort,
                  })}
                  aria-label={`أزل مرشّح «${chip.label}»`}
                >
                  {chip.label}
                  <CatalogIcon name="close" strokeWidth={2.6} className={styles.chipIcon} />
                </a>
              ))}
              <a className={styles.clearAll} href={catalogHref(basePath, { sort })}>
                امسح الكل
              </a>
            </div>
          ) : null}

          {items.length > 0 ? (
            <>
              <ProductGrid items={items.map(searchCardToProps)} priorityFirst={4} />
              <div className={styles.pager}>
                {/* تقدّمٌ مرئيّ لما حُمّل من الكلّ — رقما الخادم، والنصّ يحمل المعنى. */}
                <span className={styles.pagerCount}>
                  تعرض <bdi dir="ltr">{formatNumber(items.length)}</bdi> من{' '}
                  <bdi dir="ltr">{formatNumber(total)}</bdi>
                </span>
                <span className={styles.pagerTrack} aria-hidden="true">
                  <span
                    className={styles.pagerFill}
                    style={{ inlineSize: `${total > 0 ? Math.min(100, (items.length / total) * 100) : 0}%` }}
                  />
                </span>
                {items.length < total && page < MAX_PAGES ? (
                  <PendingLink
                    className={styles.pagerLink}
                    href={catalogHref(basePath, { facets, sort, page: page + 1 })}
                  >
                    {moreLabel}
                  </PendingLink>
                ) : null}
              </div>
            </>
          ) : narrowest ? (
            /* Empty with filters: name the NARROWEST one and remove it in a tap. */
            <CatalogEmpty
              title="لا نتائج بهذه المرشّحات"
              text={
                <>
                  المرشّحات المختارة لا تتقاطع في أي منتج. أزل أضيقها بدل مسحها كلها —{' '}
                  <b className={styles.emptyStrong}>{narrowest.label}</b> هو الأكثر تقييدًا هنا.
                </>
              }
              actions={[
                {
                  label: `أزل «${narrowest.label}»`,
                  href: catalogHref(basePath, {
                    facets: { ...facets, [narrowest.key]: undefined },
                    sort,
                  }),
                  primary: true,
                },
                // بمرشّحٍ واحد «أزل» و«امسح الكل» وجهةٌ واحدة — فيُعرض الأوّل وحده.
                ...(chips.length > 1 ? [{ label: 'امسح الكل', href: catalogHref(basePath, { sort }) }] : []),
                // مخرجٌ ثالث لا يعتمد على المرشّحات: ما يشتريه المعلّمون فعلًا.
                { label: 'الأكثر مبيعًا', href: routes.bestSellers },
              ]}
            />
          ) : (
            <CatalogEmpty title={emptyTitle} text={emptyText} actions={emptyActions} />
          )}
        </div>
      </div>

      {/* Sibling archives — real, server-declared destinations only. */}
      {related && related.links.length > 0 ? (
        <nav className={styles.related} aria-label={related.title}>
          <h2 className={styles.relatedTitle}>{related.title}</h2>
          <ul className={styles.relatedList}>
            {related.links.map((link) => (
              <li key={link.href}>
                <a className={styles.relatedLink} href={link.href}>
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>
      ) : null}

      {/* SEO block — editorial text from the taxonomy API only, never invented. */}
      {seo ? (
        <section className={styles.seo}>
          <h2 className={styles.seoTitle}>{seo.title}</h2>
          <p className={styles.seoText}>{seo.text}</p>
        </section>
      ) : null}
    </div>
  );
}
