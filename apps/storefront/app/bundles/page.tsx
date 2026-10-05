/**
 * /bundles — the bundles index.
 *
 * TODAY IT IS AN HONEST EMPTY STATE. `bundles` / `bundle_items` are a planned
 * schema with NO API endpoint, so there is nothing to list and nothing is
 * invented to fill the page. The route exists (rather than 404-ing) because
 * the footer and the design both name «الحزم», and a page that explains the
 * absence and offers real onward paths beats a dead link.
 *
 * WIRING IS A DATA SWAP: `fetchBundles` (see _lib/bundles-api.ts) is the only
 * thing that has to change. The `ok` branch below already renders the grid.
 */
import type { Metadata } from 'next';
import { formatMoney, qty, type PluralForms } from '@tahdheer/design-system/format';
import { routes } from '../../lib/routes';
import { bundleFileCount, fetchBundles } from './_lib/bundles-api';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'الحزم',
  description: 'حزم ملفات «التحاضير الحديثة» — عدّة ملفات في عملية شراء واحدة.',
  alternates: { canonical: routes.bundles },
};

/** Arabic agreement for «ملف» — the ONE plural source (design-system qty). */
const FILE_FORMS: PluralForms = {
  zero: 'لا ملفات',
  one: 'ملف واحد',
  two: 'ملفان',
  few: 'ملفات',
  many: 'ملفًا',
};

/** Real routes only — every one of these exists. */
const ELSEWHERE = [
  { label: 'كل المنتجات', href: routes.shop },
  { label: 'المراحل التعليمية', href: routes.stages },
  { label: 'العروض الحالية', href: routes.offers },
  { label: 'الأكثر مبيعًا', href: routes.bestSellers },
] as const;

export default async function BundlesPage() {
  const result = await fetchBundles();

  return (
    <div className={styles.page}>
      <nav className={styles.breadcrumbNav} aria-label="مسار التنقّل">
        <ol className={styles.breadcrumb}>
          <li>
            <a className={styles.crumbLink} href={routes.home}>
              الرئيسية
            </a>
          </li>
          <li aria-current="page" className={styles.crumbCurrent}>
            الحزم
          </li>
        </ol>
      </nav>

      <header className={styles.head}>
        <h1 className={styles.h1}>الحزم</h1>
        <p className={styles.intro}>
          الحزمة عدّة ملفات في عملية شراء واحدة بسعر أقل من مجموعها منفردة.
        </p>
      </header>

      {result.state === 'ok' && result.bundles.length > 0 ? (
        <ul className={styles.grid} role="list">
          {result.bundles.map((bundle) => {
            const { amount, currency } = formatMoney(bundle.priceMinor);
            const count = bundleFileCount(bundle);
            /* المشطوب مجموعُ الأسعار منفردةً — حين يصرّح به الخادم ويزيد فعلًا. */
            const was =
              bundle.regularMinor !== undefined && bundle.regularMinor > bundle.priceMinor
                ? formatMoney(bundle.regularMinor).amount
                : null;
            return (
              <li key={bundle.slug} className={styles.card}>
                {count !== null ? <span className={styles.cardMeta}>{qty(count, FILE_FORMS)}</span> : null}
                <a className={styles.cardLink} href={routes.bundle(bundle.slug)}>
                  <span className={styles.cardTitle}>{bundle.title}</span>
                </a>
                {bundle.description ? <p className={styles.cardDesc}>{bundle.description}</p> : null}
                <span className={styles.cardPrice}>
                  <bdi dir="ltr">{amount}</bdi> <span className={styles.currency}>{currency}</span>
                  {was ? (
                    <>
                      <span className={styles.srOnly}> بدل </span>
                      <del className={styles.was}>
                        <bdi dir="ltr">{was}</bdi>
                      </del>
                    </>
                  ) : null}
                </span>
              </li>
            );
          })}
        </ul>
      ) : (
        <section className={styles.empty} aria-labelledby="bundles-empty">
          <h2 className={styles.emptyTitle} id="bundles-empty">
            لا توجد حزم منشورة بعد
          </h2>
          <p className={styles.emptyBody}>
            {result.state === 'not-configured'
              ? 'الحزم لم تُفعَّل في المتجر بعد، فلا يوجد ما يُعرض هنا. حين تُنشر ستظهر في هذه الصفحة مباشرة — ولن نعرض حزمة قبل أن تصبح قابلة للشراء فعلًا.'
              : 'لم تُنشر أي حزمة حتى الآن.'}
          </p>
          <p className={styles.emptyBody}>وحتى ذلك الحين، كل الملفات متاحة منفردة:</p>
          <ul className={styles.elsewhere} role="list">
            {ELSEWHERE.map((link) => (
              <li key={link.href}>
                <a className={styles.elsewhereLink} href={link.href}>
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
