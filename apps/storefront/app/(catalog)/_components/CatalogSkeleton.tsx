/**
 * هيكلُ انتظار الكتالوج — يُصيَّر من `loading.tsx` لمسارات الكتالوج.
 *
 * ─── لماذا هنا لا في كلّ مسار ───
 *
 * ثلاثة عشر مسارًا ترسم إطارًا واحدًا (فتاتٌ، هيروٌ متدرّج، ثمّ الجسم).
 * هيكلٌ منسوخٌ في كلٍّ منها ينحرف عن الهندسة عند أوّل تعديل، فيصير الهيكلُ
 * نفسه مصدرَ إزاحةٍ في التخطيط. فواحدٌ يُستورَد بثلاثة أجسام:
 *
 * - `archive` (الافتراض): رفُّ الترشيح وشريط الأدوات وشبكة البطاقات — /shop
 *   وأرشيفات المفردات و/curriculum.
 * - `grid`: سطرُ النتائج وشبكة البطاقات بلا رفّ — /best-sellers و/new و/offers.
 * - `tiles`: بلاطات المحاور — /stages و/grades و/subjects و/semesters و/kinds.
 *
 * ─── وما لا يُقال ───
 *
 * لا عدد نتائج ولا اسم قسم: الصفحة لم تصل بعد ولا نعرف شيئًا عنها، ورقمٌ
 * مُخترع في هيكلٍ يقرؤه الزائر رقمًا.
 *
 * وهو خارج شجرة الوصول كاملًا (`aria-hidden`) مع إعلانٍ نصّيّ واحد: قارئ
 * الشاشة يحتاج «يُحمّل» لا وصفَ اثنتي عشرة مستطيلًا رماديًّا.
 */

import styles from './CatalogSkeleton.module.css';

export type CatalogSkeletonVariant = 'archive' | 'grid' | 'tiles';

const FACET_GROUPS = 4;
/* كما في اللوحة الحقيقيّة: خمسة خيارات قبل «عرض المزيد». */
const FACET_ROWS = 5;
const CARDS = 12;
const TILES = 6;

function Cards() {
  return (
    <div className={styles.grid}>
      {Array.from({ length: CARDS }, (_, card) => (
        <div key={card} className={styles.card}>
          <div className={styles.cardMedia} />
          <div className={styles.cardBody}>
            <div className={`${styles.bar} ${styles.cardTitle}`} />
            <div className={`${styles.bar} ${styles.cardLine} ${styles.cardLineShort}`} />
            <div className={`${styles.bar} ${styles.cardSignal}`} />
            <div className={`${styles.bar} ${styles.cardPrice}`} />
            <div className={styles.cardFoot}>
              <div className={`${styles.bar} ${styles.cardCta}`} />
              <div className={`${styles.bar} ${styles.cardPreview}`} />
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

export function CatalogSkeleton({ variant = 'archive' }: { variant?: CatalogSkeletonVariant }) {
  return (
    <div className={styles.page} data-catalog-skeleton="">
      <p className={styles.srOnly} role="status">
        يُحمّل الكتالوج…
      </p>

      <div className={styles.stack} aria-hidden="true">
        <div className={`${styles.bar} ${styles.crumbs}`} />
        <div className={styles.hero} />

        {variant === 'tiles' ? (
          <div className={styles.tiles}>
            {Array.from({ length: TILES }, (_, tile) => (
              <div key={tile} className={styles.tile} />
            ))}
          </div>
        ) : variant === 'grid' ? (
          <div className={styles.main}>
            <div className={`${styles.bar} ${styles.line}`} />
            <Cards />
          </div>
        ) : (
          <div className={styles.layout}>
            <aside className={styles.rail}>
              <div className={`${styles.bar} ${styles.railHead}`} />
              {Array.from({ length: FACET_GROUPS }, (_, group) => (
                <div key={group} className={styles.railGroup}>
                  <div className={`${styles.bar} ${styles.railTitle}`} />
                  {Array.from({ length: FACET_ROWS }, (_, row) => (
                    <div key={row} className={`${styles.bar} ${styles.railRow}`} />
                  ))}
                </div>
              ))}
            </aside>

            <div className={styles.main}>
              <div className={styles.toolbar}>
                <div className={`${styles.bar} ${styles.filterBtn}`} />
                <div className={`${styles.bar} ${styles.count}`} />
                <div className={`${styles.bar} ${styles.sorts}`} />
              </div>
              <Cards />
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
