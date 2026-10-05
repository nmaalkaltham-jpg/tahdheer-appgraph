'use client';
/**
 * «كل الملفّات» — جزيرة «عرض المزيد» حول شبكة الكتالوج نفسها (`ProductGrid`
 * ببطاقته الواحدة: القلب و«أضف للسلة»). الصفحة الأولى تصل من الخادم في أوّل
 * ‎HTML‎، وما بعدها يُجلب من ‎/api/v1/search?sort=newest&page=N‎ حتّى المجموع.
 *
 *  · بلا JavaScript: الزرّ رابطٌ إلى ‎/shop?page=2‎ (الأرشيف يعرض الصفحات حتّى N).
 *  · التحميل: ‎aria-busy‎ + دوّار الوصفة والعرض ثابت، وهياكل بطاقات صفٍّ واحد.
 *  · العطل: تنبيهٌ بسبب واضح وزرّ «أعد المحاولة» — المعروض يبقى كما هو.
 *  · بعد الإضافة ينتقل التركيز إلى أوّل بطاقةٍ جديدة، و«يُعرض N من M» يُعلَن.
 *  · عند بلوغ المجموع يحلّ سطرٌ هادئ محلّ الزرّ، ومعه «تصفّح مع الفلاتر».
 *  · المرشّحات السريعة (رقائق ‎aria-pressed‎): «الكل» + المراحل + أنواع المنتج من
 *    ‎facets‎ الصفحة الأولى؛ الاختيار يجلب الصفحة ١ بالمرشّح، و«عرض المزيد» يبقى
 *    داخله، ورابط «تصفّح مع الفلاتر» يحمله إلى ‎/shop‎. وبلا JavaScript الرقائق
 *    روابطُ إلى ‎/shop‎ المرشَّح نفسه.
 */
import { useEffect, useRef, useState, useSyncExternalStore, type ReactNode } from 'react';
import type { ProductCardProps } from '@tahdheer/ui';
import { formatNumber } from '@tahdheer/design-system/format';
import { ProductGrid } from '../../app/(catalog)/_components/ProductGrid';
import {
  allProductsFallbackHref,
  allProductsPaging,
  mergeById,
  quickFilterHref,
  quickFilterParams,
  type QuickFilter,
} from '../../lib/home-all-products';
import { routes } from '../../lib/routes';
import { fetchSearch, searchCardToProps } from '../../lib/search-api';
import { Head } from './DesignHead';
import styles from './design.module.css';

const subscribeNever = (): (() => void) => () => undefined;

export interface AllProductsMoreProps {
  initial: ProductCardProps[];
  total: number;
  perPage: number;
  /** رقائق المرشّح (بلا «الكل») — فارغةٌ ⇒ لا صفّ رقائق. */
  filters: QuickFilter[];
  /** سطر الوصف كما يحسبه الخادم للكتالوج كلّه. */
  lede: ReactNode;
}

const sameFilter = (a: QuickFilter | null, b: QuickFilter | null) => a?.key === b?.key && a?.value === b?.value;

export function AllProductsMore({
  initial,
  total: initialTotal,
  perPage: initialPerPage,
  filters,
  lede,
}: AllProductsMoreProps) {
  const [items, setItems] = useState(initial);
  const [total, setTotal] = useState(initialTotal);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(initialPerPage);
  /* ‎filtering‎/‎filter-error‎: تبديل المرشّح (الشبكة كلّها)؛ ‎busy‎/‎error‎: «عرض المزيد». */
  const [status, setStatus] = useState<'idle' | 'busy' | 'error' | 'filtering' | 'filter-error'>('idle');
  const [filter, setFilter] = useState<QuickFilter | null>(null);
  /* آخر طلب مرشّحٍ وحده يكتب النتيجة — نقراتٌ متتابعة لا تتسابق. */
  const seq = useRef(0);
  const allChipRef = useRef<HTMLButtonElement | null>(null);
  const gridRef = useRef<HTMLDivElement | null>(null);
  /* فهرس أوّل بطاقةٍ جديدة ينتظر التركيز بعد أن تُرسم. */
  const focusFrom = useRef<number | null>(null);
  const hydrated = useSyncExternalStore(
    subscribeNever,
    () => true,
    () => false,
  );

  const paging = allProductsPaging(items.length, total, page, perPage);

  useEffect(() => {
    const from = focusFrom.current;
    if (from === null) return;
    focusFrom.current = null;
    const card = gridRef.current?.querySelectorAll(':scope > ul > li')[from];
    card?.querySelector<HTMLElement>('a[href]')?.focus();
  }, [items]);

  const loadFilter = async (next: QuickFilter | null) => {
    const my = ++seq.current;
    setFilter(next);
    if (!next) {
      /* «الكل» هو ما رسمه الخادم أصلًا — يعود بلا طلب. */
      setItems(initial);
      setTotal(initialTotal);
      setPage(1);
      setPerPage(initialPerPage);
      setStatus('idle');
      return;
    }
    setStatus('filtering');
    const res = await fetchSearch({
      sort: 'newest',
      page: 1,
      ...quickFilterParams(next),
    });
    if (my !== seq.current) return;
    const results = res.status === 200 ? res.data?.results : undefined;
    if (!results) {
      setStatus('filter-error');
      return;
    }
    setItems(results.items.map(searchCardToProps));
    setTotal(results.total);
    setPage(results.page || 1);
    setPerPage(results.perPage || initialPerPage);
    setStatus('idle');
  };

  const choose = (next: QuickFilter | null) => {
    if (sameFilter(next, filter) && status !== 'filter-error') return;
    void loadFilter(next);
  };

  const clearFilter = () => {
    void loadFilter(null);
    /* الزرّ يختفي مع المرشّح — التركيز إلى «الكل» لا إلى body. */
    allChipRef.current?.focus();
  };

  const loadMore = async () => {
    if (paging.nextPage === null || status === 'busy' || status === 'filtering') return;
    setStatus('busy');
    const my = seq.current;
    const res = await fetchSearch({
      sort: 'newest',
      page: paging.nextPage,
      ...quickFilterParams(filter),
    });
    if (my !== seq.current) return;
    const results = res.status === 200 ? res.data?.results : undefined;
    if (!results) {
      setStatus('error');
      return;
    }
    const merged = mergeById(items, results.items.map(searchCardToProps));
    focusFrom.current = merged.length > items.length ? items.length : null;
    setItems(merged);
    setPage(results.page || paging.nextPage);
    /* المجموع كما يقوله الخادم الآن؛ وصفحةٌ فارغة تعني أنّه لا شيء بعدها. */
    setTotal(results.items.length === 0 ? merged.length : results.total);
    setStatus('idle');
  };

  const busy = status === 'busy';
  const filtering = status === 'filtering';
  const skeletons = busy ? Math.min(paging.nextBatch, 4) : 0;
  const shopHref = quickFilterHref(routes.shop, filter, routes.search);

  const chip = (value: QuickFilter | null, label: string, count: number | undefined) => {
    const inner = (
      <>
        <span>{label}</span>
        {typeof count === 'number' ? (
          <bdi dir="ltr" className={styles.allChipCount}>
            {formatNumber(count)}
          </bdi>
        ) : null}
      </>
    );
    const key = value ? `${value.key}:${value.value}` : 'all';
    /* بلا JavaScript: رابطٌ إلى ‎/shop‎ المرشَّح نفسه؛ و«الكل» هو المعروض الآن. */
    if (!hydrated) {
      return (
        <a
          key={key}
          className={styles.allChip}
          href={quickFilterHref(routes.shop, value, routes.search)}
          aria-current={value ? undefined : 'true'}
        >
          {inner}
        </a>
      );
    }
    return (
      <button
        key={key}
        ref={value ? undefined : allChipRef}
        type="button"
        className={styles.allChip}
        aria-pressed={sameFilter(value, filter)}
        onClick={() => choose(value)}
      >
        {inner}
      </button>
    );
  };

  return (
    <>
      <Head
        eyebrow="الكتالوج كاملًا"
        title="كل الملفّات"
        action={{ label: 'تصفّح مع الفلاتر', short: 'الفلاتر', href: shopHref }}
      />
      <p className={styles.allLede}>{lede}</p>

      {filters.length > 0 ? (
        <div className={styles.allFilterBar}>
          <div className={styles.allFilters} role="group" aria-label="رشّح كل الملفّات">
            {chip(null, 'الكل', initialTotal)}
            {filters.map((f) => chip(f, f.label, f.count))}
          </div>
          {hydrated && filter ? (
            <button
              type="button"
              className={styles.allClear}
              onClick={clearFilter}
              aria-label={`امسح المرشّح «${filter.label}»`}
            >
              امسح
            </button>
          ) : null}
        </div>
      ) : null}

      <div className={styles.allBody}>
        {status === 'filter-error' ? (
          <div className={styles.allError} role="alert">
            <span>تعذّر تحميل «{filter?.label}» — تحقّق من الاتصال. ما يُعرض أدناه هو الاختيار السابق.</span>
            <button type="button" className={styles.allRetry} onClick={() => void loadFilter(filter)}>
              أعد المحاولة
            </button>
          </div>
        ) : null}

        {items.length === 0 && !filtering ? (
          <p className={styles.allEmpty}>
            لا ملفّات في «{filter?.label}» بعد.{' '}
            <a className={styles.allShopLink} href={routes.shop}>
              تصفّح المتجر كلّه
            </a>
          </p>
        ) : (
          <div ref={gridRef} className={styles.allGrid} aria-busy={filtering || undefined}>
            <ProductGrid items={items} />
          </div>
        )}

        {skeletons > 0 ? (
          <ul className={`${styles.allGrid} ${styles.allSkeletons}`} role="list" aria-hidden="true">
            {Array.from({ length: skeletons }, (_, i) => (
              <li key={i} className={styles.allSkeleton}>
                <span className={styles.allSkeletonMedia} />
                <span className={styles.allSkeletonLine} />
                <span className={`${styles.allSkeletonLine} ${styles.allSkeletonShort}`} />
              </li>
            ))}
          </ul>
        ) : null}

        <div className={styles.allFoot}>
          <p className={styles.allCount} aria-live="polite">
            {filtering ? (
              'جارٍ التحديث…'
            ) : (
              <>
                يُعرض <bdi dir="ltr">{formatNumber(items.length)}</bdi> من <bdi dir="ltr">{formatNumber(total)}</bdi>
                {filter ? <> في «{filter.label}»</> : null}
              </>
            )}
          </p>

          {paging.done ? (
            <p className={styles.allDone}>
              <span>
                {filter ? <>عُرضت كل ملفّات «{filter.label}»</> : 'عُرضت كل الملفّات'} (
                <bdi dir="ltr">{formatNumber(total)}</bdi>)
              </span>
              <a className={styles.allShopLink} href={shopHref}>
                تصفّح مع الفلاتر
              </a>
            </p>
          ) : (
            <>
              {status === 'error' ? (
                <p className={styles.allError} role="alert">
                  تعذّر تحميل المزيد — تحقّق من الاتصال ثم أعد المحاولة. ما عُرض يبقى كما هو.
                </p>
              ) : null}
              {hydrated ? (
                <button
                  type="button"
                  className={styles.allMore}
                  onClick={loadMore}
                  aria-busy={busy}
                  aria-disabled={busy || filtering}
                >
                  <span>{status === 'error' ? 'أعد المحاولة' : busy ? 'جارٍ التحميل…' : 'عرض المزيد'}</span>
                </button>
              ) : (
                <a className={styles.allMore} href={allProductsFallbackHref(routes.shop, paging.nextPage)}>
                  <span>عرض المزيد</span>
                </a>
              )}
            </>
          )}
        </div>
      </div>
    </>
  );
}
