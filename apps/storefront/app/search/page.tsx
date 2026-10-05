/**
 * Search results (MIP §3.2 noindex · §6.4 canonical UX) — server component
 * driven entirely by GET /api/v1/search per the search contract:
 * type facet tabs with real counts, 5 fixed sorts, amber correction banner
 * (never auto-replace), zero-result recovery from API data only.
 *
 * Design: «Tahdheer - Search Results» — the three states (results · typo
 * correction · zero result). Sample numbers in the design are NOT data:
 * every count here comes from the API response or is absent.
 */
import type { Metadata } from 'next';
import { ProductCard, type ProductCardProps } from '@tahdheer/ui';
import {
  formatNumber,
  qty,
  type PluralForms,
} from '@tahdheer/design-system/format';
import {
  fetchSearch,
  searchCardToProps,
  type SearchSort,
} from '../../lib/search-api';
import { routes } from '../../lib/routes';
import { requestHref } from '../../lib/request-intent';
import { ConnectionError } from '../../components/search/ConnectionError';
import { ResultClick } from '../../components/search/ResultClick';
import { SearchImpressions } from '../../components/search/SearchImpressions';
import { AssistantPanel } from '../../components/search/AssistantPanel';
import { CardAddToCart } from '../../components/product/CardAddToCart';
import { cardActions, cardPreview } from '../(catalog)/_components/ProductGrid';
import { cardFallbackArt } from '../../components/product/CardArt';
import { PendingLink } from '../(catalog)/_components/PendingLink';
import {
  MULTI_DIMS,
  appendSelection,
  isSelected,
  readSelection,
  selectedCount,
  toggleValue,
  type FacetSelection,
  type MultiDim,
} from '../../lib/facet-selection';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'البحث',
  robots: { index: false, follow: true },
};

const SORT_LABELS: Record<SearchSort, string> = {
  relevance: 'الأكثر مطابقة',
  bestselling: 'الأكثر مبيعًا',
  newest: 'الأحدث',
  price_asc: 'السعر تصاعديًا',
  price_desc: 'السعر تنازليًا',
};

const SORT_KEYS = Object.keys(SORT_LABELS) as SearchSort[];

/** Arabic agreement for «نتيجة» — the ONE plural source (design-system qty). */
const RESULT_FORMS: PluralForms = {
  zero: 'لا نتائج',
  one: 'نتيجة واحدة',
  two: 'نتيجتان',
  few: 'نتائج',
  many: 'نتيجة',
};

/* ------------------------------------------------------------------ icons */

function SearchGlyph({ className }: { className?: string }) {
  return (
    <svg className={className} aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
      <circle cx="11" cy="11" r="7" />
      <path d="M20 20l-3.5-3.5" />
    </svg>
  );
}

function InfoIcon() {
  return (
    <svg className={styles.correctionIcon} aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
      <circle cx="12" cy="12" r="9" />
      <path d="M12 11v5M12 7.6v.1" />
    </svg>
  );
}

function BlocksIcon() {
  return (
    <svg className={styles.cardIcon} aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round">
      <rect x="3.5" y="3.5" width="7" height="7" rx="1.6" />
      <rect x="13.5" y="3.5" width="7" height="7" rx="1.6" />
      <rect x="3.5" y="13.5" width="7" height="7" rx="1.6" />
      <rect x="13.5" y="13.5" width="7" height="7" rx="1.6" />
    </svg>
  );
}

function CapIcon() {
  return (
    <svg className={styles.cardIcon} aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round">
      <path d="M3 9l9-4 9 4-9 4z" />
      <path d="M7 11v4c0 1.2 2.2 2.6 5 2.6s5-1.4 5-2.6v-4" />
    </svg>
  );
}

function BookIcon() {
  return (
    <svg className={styles.cardIcon} aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round">
      <path d="M19 4H8a2 2 0 0 0-2 2v13a2 2 0 0 1 2-2h11z" />
      <path d="M19 4v15" />
    </svg>
  );
}

function HeadsetIcon() {
  return (
    <svg className={styles.cardIcon} aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M4 13v-1a8 8 0 0 1 16 0v1" />
      <path d="M4 13h2.5a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z" />
      <path d="M20 13h-2.5a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1H19a1 1 0 0 0 1-1z" />
    </svg>
  );
}

/* ------------------------------------------------------------------ hrefs */

/** أبعاد التصنيف التي تركب الرابط — نفس مفاتيح معاملات الطلب. */
/** بطاقة النتيجة بزرّ السلة الحقيقيّ وقلب المفضّلة — كبقيّة الكتالوج. */
function SearchCard(item: ProductCardProps) {
  return (
    <ProductCard
      {...item}
      ctaSlot={<CardAddToCart productId={item.id} title={item.title} disabled={item.unavailable} />}
      previewSlot={cardPreview(item)}
      actions={cardActions(item)}
      mediaSlot={cardFallbackArt(item.categoryPath, Boolean(item.image))}
    />
  );
}

/** الأبعاد المتعدّدة قوائم («أو» داخل البُعد)؛ نوع المنتج اختيارٌ واحد. */
type FacetDims = FacetSelection & { nature?: string };

/** Build a /search href, omitting defaults (type=all, sort=relevance, page=1). */
function searchHref(
  params: { q: string; type?: string; sort?: string; page?: number } & FacetDims,
): string {
  const qs = new URLSearchParams({ q: params.q });
  if (params.type && params.type !== 'all') qs.set('type', params.type);
  if (params.sort && params.sort !== 'relevance') qs.set('sort', params.sort);
  if (params.page && params.page > 1) qs.set('page', String(params.page));
  appendSelection(qs, params);
  if (params.nature) qs.set('nature', params.nature);
  return `${routes.search}?${qs.toString()}`;
}

type Props = {
  searchParams: Promise<{
    q?: string;
    type?: string;
    sort?: string;
    page?: string;
    /* مفاتيحُ مكرّرة ⇐ قائمة: اختيارٌ متعدّد في البُعد. */
    stage?: string | string[];
    grade?: string | string[];
    subject?: string | string[];
    /** نوع الملف (تحضير، أوراق عمل…) — slug النوع، لا امتداد الملفّ (`type`). */
    kind?: string | string[];
    semester?: string | string[];
    /** §٦ — نوع المنتج. بُعدٌ مستقلّ عن `type` وهي امتداد الملفّ. */
    nature?: string;
  }>;
};

/*
 * وصفُ التوسيع من مفاتيحه — لا جملةٌ ثابتة تَعِد بما قد لا يكون.
 *
 * المفاتيح يُصدرها الخادم في `meta.relaxed`: اسمُ بُعدٍ أُسقط، أو
 * `token:<كلمة>` لكلمةٍ حُذفت، أو `typo` لتصحيحٍ إملائيّ طُبِّق.
 */
const RELAXED_LABELS: Record<string, string> = {
  stage: 'المرحلة',
  grade: 'الصف',
  subject: 'المادة',
  semester: 'الفصل',
  kind: 'النوع',
  format: 'الصيغة',
};

function relaxationNotice(relaxed: string[]): string {
  const dropped = relaxed
    .filter((key) => key !== 'typo' && !key.startsWith('token:'))
    .map((key) => RELAXED_LABELS[key] ?? key);
  const words = relaxed
    .filter((key) => key.startsWith('token:'))
    .map((key) => key.slice('token:'.length));

  const parts: string[] = [];
  if (relaxed.includes('typo')) parts.push('صحّحنا الإملاء');
  if (words.length > 0) parts.push(`أسقطنا ${words.map((w) => `«${w}»`).join('، ')}`);
  if (dropped.length > 0) parts.push(`تجاهلنا ${dropped.join('، ')}`);

  return parts.length > 0 ? parts.join(' و') : 'وسّعنا نطاق المطابقة';
}

export default async function SearchPage({ searchParams }: Props) {
  const params = await searchParams;
  const { q, type, sort, page, nature } = params;
  const query = q?.trim() ?? '';
  const pageNum = Math.max(1, Number.parseInt(page ?? '1', 10) || 1);
  const selection = readSelection(params);
  const dims: FacetDims = { ...selection, nature };
  const chosen = selectedCount(selection) + (nature ? 1 : 0);
  const hasDims = Boolean(chosen > 0 || (type && type !== 'all'));

  // مرشِّح بلا نصّ طلبٌ صالح: النقر على مادة هو الطلب كلّه.
  const res =
    query || hasDims
      ? await fetchSearch({
          q: query,
          type,
          sort,
          page: pageNum,
          ...selection,
          nature,
        })
      : null;
  const data = res?.data ?? null;

  /*
   * روابط التصفّح تصل مسطّحة: كل مرحلة وكل مادة رابطٌ مستقل. التصميم يعرض
   * مسارَي تصفّح لا قائمة روابط، فتُجمَع بالبادئة إلى بطاقتين نصُّ كلٍّ منهما
   * تسميات الواجهة نفسها — لا عدد مُختلق ولا وعد لا تدعمه البيانات. وما لا
   * تعرف بادئته يبقى بطاقةً كما وصل بدل أن يسقط صامتًا.
   */
  const browse = data?.recovery?.browse ?? [];
  const stageBrowse = browse.filter((entry) => entry.url.startsWith('/stages/'));
  const subjectBrowse = browse.filter((entry) => entry.url.startsWith('/subjects/'));
  const otherBrowse = browse.filter(
    (entry) => !entry.url.startsWith('/stages/') && !entry.url.startsWith('/subjects/'),
  );

  const whatsapp = process.env.NEXT_PUBLIC_WHATSAPP_NUMBER;
  const correction = data?.query.correction ?? null;
  const total = data?.results.total ?? 0;

  // The correction SUGGESTS: the heading stays honest about the weak match
  // and the shopper's own words are never replaced behind their back.
  const heading = !query && !data
    ? 'البحث'
    : !data
      ? `نتائج البحث عن «${query}»`
      : data.zeroResult
        ? 'لا نتائج'
        : correction
          ? `${qty(total, RESULT_FORMS)} ضعيفة المطابقة`
          : query
            ? `${qty(total, RESULT_FORMS)} لـ «${query}»`
            : qty(total, RESULT_FORMS);

  return (
    <div className={styles.page}>
      <form className={styles.form} role="search" action={routes.search} method="get">
        <label className={styles.formLabel} htmlFor="search-q">
          ابحث في المتجر
        </label>
        <div className={styles.field}>
          <SearchGlyph className={styles.fieldIcon} />
          <input
            id="search-q"
            className={styles.input}
            type="search"
            name="q"
            dir="auto"
            defaultValue={query}
            placeholder="مثال: تحضير رياضيات رابع"
          />
          <button className={styles.submit} type="submit">
            بحث
          </button>
        </div>
      </form>

      <h1 className={styles.title}>{heading}</h1>

      {!query && !hasDims ? (
        <p className={styles.hint}>اكتب كلمة في صندوق البحث بالأعلى للعثور على تحضير أو عرض أو اختبار.</p>
      ) : null}

      {(query || hasDims) && !data ? (
        <ConnectionError href={searchHref({ q: query, type, sort, page: pageNum, ...dims })} />
      ) : null}

      {data ? (
        <>
          {/* Correction banner — server-provided only; NEVER auto-replace the query. */}
          {correction ? (
            <div className={styles.correction} role="status" aria-live="polite">
              <InfoIcon />
              <p className={styles.correctionText}>
                هل تقصد «{correction.display}»؟ نتائجك الحالية ضعيفة المطابقة لأنها لا تطابق
                كلمات الملفات المنشورة.
              </p>
              <a
                className={styles.correctionAction}
                href={searchHref({ q: correction.suggestion })}
              >
                ابحث عن «{correction.suggestion}»
              </a>
            </div>
          ) : null}

          {!data.zeroResult ? (
            <div className={styles.toolbar}>
              {/* رقائق النوع — أعدادٌ حقيقيّة من الاستجابة. */}
              {data.facets.types.length > 0 ? (
                <div className={styles.facetRow}>
                  <span className={styles.toolbarLabel}>النوع</span>
                  <ul className={styles.segmented} role="list" aria-label="نوع النتائج">
                    {data.facets.types.map((facet) => {
                      const active = facet.key === (type ?? 'all');
                      return (
                        <li key={facet.key}>
                          <a
                            className={active ? `${styles.segment} ${styles.segmentActive}` : styles.segment}
                            href={searchHref({ q: query, type: facet.key, sort, ...dims })}
                            aria-current={active ? 'true' : undefined}
                          >
                            {facet.label}
                            <span className={styles.segmentCount} dir="ltr">
                              {formatNumber(facet.count)}
                            </span>
                          </a>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              ) : null}
            </div>
          ) : null}

          {/*
            التوسيع يُعلَن بما أُسقط بعينه.
            كانت هنا جملةٌ ثابتة تَعِد بأن النتائج «تطابق المادة والصف والصيغة»،
            وقد صدقت يوم كان النوعُ وحده قابلًا للإسقاط. ومذ صار الصفُّ والمرحلة
            يُسقطان عند الإفراط في التحديد صارت الجملة تَعِد بما لا تفي به —
            فتُشتقّ الآن من `meta.relaxed` نفسه.
          */}
          {data.meta.relaxed.length > 0 ? (
            <p className={styles.hint} role="status">
              لا نتائج تطابق كل التفاصيل حرفيًّا، فوسّعنا البحث — {relaxationNotice(data.meta.relaxed)}.
            </p>
          ) : null}

          {/* المرشّحات النشطة — كل شريحة رابط إزالة، فالحالة كلها في الرابط. */}
          {hasDims ? (
            <ul
              className={styles.activeFilters}
              role="list"
              aria-label="المرشّحات النشطة"
            >
              {[
                ...MULTI_DIMS.flatMap((dim) =>
                  (selection[dim] ?? []).map((value) => ({
                    key: `${dim}:${value}`,
                    label: value,
                    href: searchHref({
                      q: query,
                      type,
                      sort,
                      ...toggleValue(selection, dim, value),
                      nature,
                    }),
                  })),
                ),
                ...(nature
                  ? [
                      {
                        key: 'nature',
                        label: nature,
                        href: searchHref({
                          q: query,
                          type,
                          sort,
                          ...selection,
                        }),
                      },
                    ]
                  : []),
              ].map((chip) =>
                chip ? (
                  <li key={chip.key}>
                    <a className={styles.activeFilter} href={chip.href}>
                      {chip.label.replace(/-/g, ' ')}
                      <svg
                        className={styles.chipIcon}
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.6"
                        strokeLinecap="round"
                      >
                        <path d="M6 6l12 12M18 6L6 18" />
                      </svg>
                      <span className={styles.srOnly}>إزالة هذا المرشّح</span>
                    </a>
                  </li>
                ) : null,
              )}
              {/* مرشِّحان فأكثر: «مسح الكل» يُبقي النصّ والترتيب ويُسقط الأبعاد كلّها. */}
              {chosen >= 2 ? (
                <li>
                  <a
                    className={styles.clearAll}
                    href={searchHref({ q: query, type, sort })}
                  >
                    مسح الكل
                  </a>
                </li>
              ) : null}
            </ul>
          ) : null}

          {/*
            توزيعات التصنيف بأعداد حقيقية من الاستجابة نفسها. البُعد المختار
            يبقى معروضًا: عدُّه محسوبٌ بلا مرشِّحه (الخادم)، فكلّ قيمةٍ رابطُ
            تبديل — تُضاف إلى الاختيار («أو» داخل البُعد) أو تُزال منه.
          */}
          {/*
            §٦ — رفُّ نوع المنتج.

            يُعرض حين يقدّم الخادم أكثر من نوع، ويختفي حين لا يوجد ما يُختار
            بينه: مرشِّحٌ بخيارٍ واحد يُوهم بتصفيةٍ لا تُغيّر شيئًا. والبُعد
            المرشَّح بالفعل لا يُعرض ثانيةً، كما في بقيّة الأبعاد.
          */}
          {!data.zeroResult && !nature && (data.facets.natures?.length ?? 0) > 0 ? (
            <div className={styles.facetRows}>
              <div className={styles.facetRow}>
                <span className={styles.toolbarLabel}>نوع المنتج</span>
                <ul className={styles.segmented} role="list" aria-label="نوع المنتج">
                  {data.facets.natures!.map((facet) => (
                    <li key={facet.key}>
                      <a
                        className={styles.segment}
                        href={searchHref({
                          q: query,
                          type,
                          sort,
                          ...dims,
                          nature: facet.key.toLowerCase(),
                        })}
                      >
                        {/* التسمية عربيّةٌ من الخادم — لا تُشتقّ من الرمز هنا. */}
                        {facet.label}
                        <span className={styles.segmentCount} dir="ltr">
                          {formatNumber(facet.count)}
                        </span>
                      </a>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          ) : null}

          {!data.zeroResult && data.facets.taxonomy.length > 0 ? (
            <div className={styles.facetRows}>
              {data.facets.taxonomy
                .filter((facet) =>
                  (MULTI_DIMS as readonly string[]).includes(facet.key),
                )
                .map((facet) => {
                  const dim = facet.key as MultiDim;
                  /* المختار أوّلًا، فلا يختفي خلف سقف الثماني. */
                  const rows = [...facet.values].sort(
                    (a, b) =>
                      Number(isSelected(selection, dim, b.value)) -
                      Number(isSelected(selection, dim, a.value)),
                  );
                  return (
                    <div className={styles.facetRow} key={facet.key}>
                      <span className={styles.toolbarLabel}>{facet.label}</span>
                      <ul
                        className={styles.segmented}
                        role="list"
                        aria-label={facet.label}
                      >
                        {rows.slice(0, 8).map((row) => {
                          const on = isSelected(selection, dim, row.value);
                          return (
                            <li key={row.value}>
                              <a
                                className={
                                  on
                                    ? `${styles.segment} ${styles.segmentActive}`
                                    : styles.segment
                                }
                                href={searchHref({
                                  q: query,
                                  type,
                                  sort,
                                  ...toggleValue(selection, dim, row.value),
                                  nature,
                                })}
                                aria-current={on ? 'true' : undefined}
                                data-facet={dim}
                              >
                                {/* الرمز هو القيمة؛ الشرطة فيه فاصل تركيب لا نصّ عربي. */}
                                {row.value.replace(/-/g, ' ')}
                                {on ? (
                                  <span className={styles.srOnly}>
                                    {' '}
                                    (مختار — انقر للإزالة)
                                  </span>
                                ) : null}
                                <span className={styles.segmentCount} dir="ltr">
                                  {formatNumber(row.count)}
                                </span>
                              </a>
                            </li>
                          );
                        })}
                      </ul>
                    </div>
                  );
                })}
            </div>
          ) : null}

          {!data.zeroResult && data.results.items.length > 0 ? (
            <>
              {/* تبويبات الترتيب — الخمسة الثابتة في العقد، بوصفة التبويب كالأرشيف. */}
              <nav className={styles.sortScroller} aria-label="ترتيب النتائج">
                <ul className={styles.sortTabs} role="list">
                  {SORT_KEYS.map((key) => (
                    <li key={key}>
                      <a
                        className={styles.sortTab}
                        href={searchHref({
                          q: query,
                          type,
                          sort: key,
                          ...dims,
                        })}
                        aria-current={data.sort === key ? 'page' : undefined}
                      >
                        {SORT_LABELS[key]}
                      </a>
                    </li>
                  ))}
                </ul>
              </nav>
              {/* تسلسلُ العناوين: `h1` ثمّ بطاقاتٌ عناوينُها `h3` — والقفزة تُسدّ هنا. */}
              <h2 className={styles.srOnly}>نتائج البحث</h2>
              {/* الظهور = بطاقةٌ بلغ نصفُها مجالَ الرؤية، مرّةً لكلّ بحث (SearchImpressions). */}
              <SearchImpressions
                queryId={data.meta.queryId}
                token={data.meta.clickToken}
              >
                <ul className={styles.grid} role="list">
                  {data.results.items.map((dto, index) => (
                    <li key={dto.id}>
                      {/* الموضع 1-مبني على الصفحة الحالية — كما يُقرأ في التقرير. */}
                      <ResultClick
                        queryId={data.meta.queryId}
                        token={data.meta.clickToken}
                        productId={Number(dto.id)}
                        position={
                          (data.results.page - 1) * data.results.perPage +
                          index +
                          1
                        }
                      >
                        <SearchCard {...searchCardToProps(dto)} priority={index < 4} />
                      </ResultClick>
                    </li>
                  ))}
                </ul>
              </SearchImpressions>
              <div className={styles.pager}>
                {data.results.page * data.results.perPage <
                data.results.total ? (
                  <PendingLink
                    className={styles.pagerLink}
                    href={searchHref({ q: query, type, sort, page: data.results.page + 1, ...dims })}
                  >
                    الصفحة التالية
                  </PendingLink>
                ) : null}
                <span className={styles.pagerCount}>
                  تعرض <bdi dir="ltr">{formatNumber(data.results.items.length)}</bdi> من{' '}
                  <bdi dir="ltr">{formatNumber(data.results.total)}</bdi>
                </span>
              </div>
            </>
          ) : null}

          {/*
           * الطبقة الثانية — بعد النتائج المطابقة وعدّادها، لا بينها: العدّاد
           * أعلاه يعدّ المطابق وحده، وهذه مجموعاتٌ مُسمّاة بسبب قربها.
           * نقراتها لا تمرّ بـ`ResultClick`: موضعُها ليس موضعًا في نتائج
           * السؤال، وعدُّها معها يُفسد CTR وموضع النقرة في التقرير.
           */}
          {!data.zeroResult && (data.related?.groups.length ?? 0) > 0 ? (
            <section
              className={styles.related}
              aria-labelledby="search-related-title"
            >
              <h2 id="search-related-title" className={styles.relatedTitle}>
                قريبٌ ممّا تبحث عنه
              </h2>
              {data.related.groups.map((group) => (
                <div key={group.reason} className={styles.relatedGroup}>
                  <p className={styles.relatedLabel}>{group.label}</p>
                  <ul
                    className={styles.grid}
                    role="list"
                    aria-label={group.label}
                  >
                    {group.items.map((dto) => (
                      <li key={dto.id}>
                        <SearchCard {...searchCardToProps(dto)} />
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </section>
          ) : null}

          {/* Zero-result recovery — API data only, never fabricated products. */}
          {data.zeroResult ? (
            <section className={styles.recovery}>
              <div className={styles.recoveryHead}>
                <span className={styles.recoveryBadge} aria-hidden="true">
                  <SearchGlyph className={styles.recoveryIcon} />
                </span>
                <h2 className={styles.zeroTitle}>لا نتائج لـ «{data.query.raw}»</h2>
                <p className={styles.zeroText}>
                  لم نجد ملفًا بهذه الكلمات. هذا لا يعني أن ما تبحث عنه غير موجود — قد يكون باسم
                  مختلف. جرّب واحدة من هذه المسارات.
                </p>
              </div>

              <div className={styles.recoveryGrid}>
                {stageBrowse.length > 0 ? (
                  <a className={styles.recoveryCard} href={routes.stages}>
                    <span className={styles.recoveryCardIcon} aria-hidden="true">
                      <CapIcon />
                    </span>
                    <b className={styles.recoveryCardTitle}>تصفّح حسب المرحلة</b>
                    <span className={styles.recoveryCardBody}>
                      {stageBrowse.map((entry) => entry.label).join(' · ')}
                    </span>
                  </a>
                ) : null}

                {subjectBrowse.length > 0 ? (
                  <a className={styles.recoveryCard} href={routes.subjects}>
                    <span className={styles.recoveryCardIcon} aria-hidden="true">
                      <BookIcon />
                    </span>
                    <b className={styles.recoveryCardTitle}>تصفّح حسب المادة</b>
                    <span className={styles.recoveryCardBody}>
                      {subjectBrowse.map((entry) => entry.label).join(' · ')}
                    </span>
                  </a>
                ) : null}

                {otherBrowse.map((entry) => (
                  <a key={entry.url} className={styles.recoveryCard} href={entry.url}>
                    <span className={styles.recoveryCardIcon} aria-hidden="true">
                      <BlocksIcon />
                    </span>
                    <b className={styles.recoveryCardTitle}>{entry.label}</b>
                  </a>
                ))}

                {/*
                 * الطلب المفقود يُسجَّل لا يُنسى: هذه البطاقة تُعرض دائمًا،
                 * لا حين يُضبط واتساب وحده. الاستعلام يُحمَل كما كتبه الزائر
                 * فيصل النموذج مملوءًا، ويصير خروجه بلا نتيجة بيانَ طلبٍ
                 * يُرتَّب عليه ما يُنتَج.
                 */}
                <a
                  className={styles.recoveryCard}
                  /*
                   * ما فهمه البحث (المادّة والصفّ والنوع…) يُحمل مع النصّ، فتصل
                   * القوائم مختارةً لا فارغة (`lib/request-intent`).
                   */
                  href={requestHref(data.filtersExtracted, data.query.raw)}
                >
                  <span className={styles.recoveryCardIcon} aria-hidden="true">
                    <HeadsetIcon />
                  </span>
                  <b className={styles.recoveryCardTitle}>اطلب هذا الملف</b>
                  <span className={styles.recoveryCardBody}>
                    سجّل طلبك في دقيقة — نُرتّب الإنتاج بعدد من طلبوا الملف نفسه.
                  </span>
                </a>

                {whatsapp ? (
                  <a
                    className={styles.recoveryCard}
                    href={`https://wa.me/${whatsapp}?text=${encodeURIComponent(
                      `أبحث عن ملف: ${data.query.raw}`,
                    )}`}
                  >
                    <span className={styles.recoveryCardIcon} aria-hidden="true">
                      <HeadsetIcon />
                    </span>
                    <b className={styles.recoveryCardTitle}>راسلنا على واتساب</b>
                  </a>
                ) : null}
              </div>

              {data.recovery && data.recovery.popular.length > 0 ? (
                <div className={styles.popular}>
                  <span className={styles.popularLabel}>
                    أو ابحث بهذه الكلمات — وهي الأكثر بحثًا فعلًا في المتجر
                  </span>
                  <ul className={styles.popularChips} role="list">
                    {data.recovery.popular.map((entry) => (
                      <li key={entry.query}>
                        <a className={styles.popularChip} href={searchHref({ q: entry.query })}>
                          {entry.query}
                        </a>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
            </section>
          ) : null}

          {/*
           * مساعد المتجر — الطرف المستهلك لـ`POST /api/v1/assistant/ask`،
           * وكانت النقطة مبنيّةً ومُختبَرةً بلا مُنادٍ واحد. موضعه بعد
           * النتائج لا قبلها: البحث المعتاد هو الجواب الأوّل، والمساعد
           * يُطلَب حين لا يكفي. ويُصيَّر في الحالين — النتائج والصفر —
           * لأنّ الصفر هو أحوج الحالتين إلى سؤالٍ بكلماتٍ أخرى. وفي الصفر يأتي
           * بعد مسارات النجاة: الخطوة التالية الواضحة أوّلًا، ثمّ السؤال.
           */}
          <AssistantPanel question={data.query.raw} />
        </>
      ) : null}
    </div>
  );
}
