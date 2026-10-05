/**
 * The API-unreachable state for a catalog surface.
 *
 * An outage is NOT an empty catalog: showing «لا توجد منتجات» when the server
 * never answered would be a fabricated fact about the store. Every catalog
 * page therefore separates «the API said nothing exists» from «the API did not
 * answer», and routes the second one here — heading intact, one honest line,
 * and a retry link that works without JavaScript.
 */
import { ConnectionError } from '../../../components/search/ConnectionError';
import { CatalogHero, type Crumb } from './CatalogHero';
import styles from './catalog.module.css';

export function CatalogFailure({
  crumbs,
  title,
  retryHref,
}: {
  crumbs: Crumb[];
  title: string;
  retryHref: string;
}) {
  return (
    <div className={styles.page}>
      <CatalogHero crumbs={crumbs} title={title} />
      <ConnectionError href={retryHref} />
    </div>
  );
}
