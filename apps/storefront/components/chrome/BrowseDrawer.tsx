/**
 * «تصفّح حسب المرحلة» — أكورديون داخل درج الجوّال: المراحل ← صفوفها.
 *
 * ‎<details>/<summary>‎ أصليّان: يعملان بلا JavaScript، و‎summary‎ زرٌّ
 * بحالة توسّعٍ يعلنها المتصفّح نفسه. والمرحلة تُفتح مستقلّةً عن أخواتها —
 * لا ‎name‎ مشترك — فمن يقارن صفّين من مرحلتين لا تُطوى عليه الأولى.
 */
import { routes } from '../../lib/routes';
import type { BrowseTree } from './browse-tree';
import styles from './BrowseDrawer.module.css';

function Chevron() {
  return (
    <svg aria-hidden="true" className={styles.chev} width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M14 6l-6 6 6 6" />
    </svg>
  );
}

export function BrowseDrawer({ tree }: { tree: BrowseTree }) {
  if (tree.stages.length === 0) return null;
  return (
    <details className={styles.root}>
      <summary className={styles.summary}>
        تصفّح حسب المرحلة
        <Chevron />
      </summary>
      <ul className={styles.stages} role="list">
        {tree.stages.map((stage) => (
          <li key={stage.href}>
            {stage.grades.length > 0 ? (
              <details className={styles.stage}>
                <summary className={styles.stageSummary}>
                  {stage.label}
                  <Chevron />
                </summary>
                <ul className={styles.grades} role="list">
                  <li>
                    <a className={`${styles.link} ${styles.linkAll}`} href={stage.href}>
                      {`كل ${stage.label}`}
                    </a>
                  </li>
                  {stage.grades.map((grade) => (
                    <li key={grade.href}>
                      <a className={styles.link} href={grade.href}>
                        {grade.label}
                      </a>
                    </li>
                  ))}
                </ul>
              </details>
            ) : (
              <a className={styles.stageSummary} href={stage.href}>
                {stage.label}
              </a>
            )}
          </li>
        ))}
        <li>
          <a className={`${styles.link} ${styles.linkAll}`} href={routes.stages}>
            كل المراحل
          </a>
        </li>
      </ul>
    </details>
  );
}
