/**
 * Bundle detail composition — «Tahdheer - Bundle» design, in full:
 * breadcrumb › gradient hero with the item-count badge › «ماذا تتضمّن الحزمة؟»
 * contents list with the owned-item exclusion › «لماذا الحزمة لا الملفات
 * منفردة؟» › sticky price card (price · discount pills · math rows · CTA ·
 * trust list) › the gold owned-items panel.
 *
 * IT IS NOT RENDERED TODAY. There is no bundle endpoint, so `fetchBundle`
 * returns null and /bundles/[slug] 404s before reaching this component. It
 * exists finished so that turning bundles on is a DATA SWAP: the moment
 * `fetchBundle` returns a `BundleDto` this page is live, unchanged.
 *
 * HONESTY DOCTRINE:
 *  · Every number is from the payload; the only arithmetic is the subtraction
 *    a shopper could do themselves (see `priceView`).
 *  · The intro, the reasons, the item meta line and the whole owned-item
 *    exclusion render ONLY when the API supplies them — absent means absent,
 *    never a placeholder.
 *  · The CTA is a real add-to-cart when the payload carries a purchasable id,
 *    and a visibly disabled control with its reason when it does not.
 *  · The trust list carries only claims verified against the backend; the
 *    design's «تحديث المنهج مجاني مدى العام» and its «استرجاع خلال 7 أيام» are
 *    sample copy — the first is unverifiable and the second states a refund
 *    window the store has not approved, so it links to the policy instead.
 */
import { formatMoney, qty, type PluralForms } from '@tahdheer/design-system/format';
import { routes } from '../../../lib/routes';
import { priceView, type BundleDto, type BundleItemDto } from '../_lib/bundles-api';
import { BundleAddToCart } from './BundleAddToCart';
import styles from './BundleDetail.module.css';

/** Arabic agreement for «ملف» — the ONE plural source (design-system qty). */
const FILE_FORMS: PluralForms = {
  zero: 'لا ملفات',
  one: 'ملف واحد',
  two: 'ملفان',
  few: 'ملفات',
  many: 'ملفًا',
};

export interface BundleDetailProps {
  bundle: BundleDto;
}

function Money({ minor }: { minor: number }) {
  const { amount, currency } = formatMoney(minor);
  return (
    <>
      <bdi dir="ltr">{amount}</bdi> <span className={styles.currency}>{currency}</span>
    </>
  );
}

function LayersIcon() {
  return (
    <svg
      aria-hidden="true"
      className={styles.badgeIcon}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.4"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M12 3 3 7.5 12 12l9-4.5L12 3Z" />
      <path d="m3 12.5 9 4.5 9-4.5" />
      <path d="m3 17 9 4.5 9-4.5" />
    </svg>
  );
}

function CheckIcon({ className }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.4"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="m5 12.5 4.5 4.5L19 7" />
    </svg>
  );
}

function FileIcon() {
  return (
    <svg
      aria-hidden="true"
      className={styles.itemIcon}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" />
      <path d="M14 3v5h5" />
    </svg>
  );
}

function ItemRow({ item, owned }: { item: BundleItemDto; owned: boolean }) {
  return (
    <li className={owned ? `${styles.item} ${styles.itemOwned}` : styles.item}>
      <span className={owned ? `${styles.itemTile} ${styles.itemTileOwned}` : styles.itemTile}>
        {owned ? <CheckIcon className={styles.itemIcon} /> : <FileIcon />}
      </span>
      <span className={styles.itemText}>
        <a className={styles.itemTitle} href={routes.product(item.slug)}>
          {item.title}
        </a>
        {item.meta ? <span className={styles.itemMeta}>{item.meta}</span> : null}
      </span>
      <span className={styles.itemPrice}>
        <Money minor={item.priceMinor} />
      </span>
      {owned ? <span className={styles.ownedTag}>تملكه</span> : null}
    </li>
  );
}

export function BundleDetail({ bundle }: BundleDetailProps) {
  const price = priceView(bundle);
  const partial = bundle.partial;
  const ownedIds = new Set(partial?.ownedItemIds ?? []);
  const ownedCount = bundle.items.filter((item) => ownedIds.has(item.id)).length;

  return (
    <div className={styles.page}>
      <nav className={styles.breadcrumbNav} aria-label="مسار التنقّل">
        <ol className={styles.breadcrumb}>
          <li>
            <a className={styles.crumbLink} href={routes.home}>
              الرئيسية
            </a>
          </li>
          <li>
            <a className={styles.crumbLink} href={routes.bundles}>
              الحزم
            </a>
          </li>
          <li aria-current="page" className={styles.crumbCurrent}>
            {bundle.title}
          </li>
        </ol>
      </nav>

      <div className={styles.grid}>
        <div className={styles.main}>
          <header className={styles.hero}>
            <span className={styles.badge}>
              <LayersIcon />
              حزمة — {qty(bundle.items.length, FILE_FORMS)}
            </span>
            <h1 className={styles.h1}>{bundle.title}</h1>
            {bundle.intro ? <p className={styles.intro}>{bundle.intro}</p> : null}
          </header>

          <section className={styles.card} aria-labelledby="bundle-contents">
            <div className={styles.cardHead}>
              <h2 className={styles.cardTitle} id="bundle-contents">
                ماذا تتضمّن الحزمة؟
              </h2>
              {partial ? (
                <span className={styles.ownedPill}>
                  {qty(ownedCount, FILE_FORMS)} في حسابك — مستثنى
                </span>
              ) : null}
            </div>
            <p className={styles.cardNote}>
              {partial
                ? 'ما تملكه مستثنى من السعر ولن تُشترى نسخة ثانية منه. يبقى في مكتبتك كما هو.'
                : 'كل ملف في الحزمة يُشترى منفردًا أيضًا — والمقارنة في بطاقة السعر.'}
            </p>
            <ul className={styles.items} role="list">
              {bundle.items.map((item) => (
                <ItemRow key={item.id} item={item} owned={ownedIds.has(item.id)} />
              ))}
            </ul>
          </section>

          {bundle.why && bundle.why.length > 0 ? (
            <section className={styles.whyCard} aria-labelledby="bundle-why">
              <h2 className={styles.cardTitle} id="bundle-why">
                لماذا الحزمة لا الملفات منفردة؟
              </h2>
              <ul className={styles.whyGrid} role="list">
                {bundle.why.map((reason) => (
                  <li key={reason.title}>
                    <span className={styles.whyHead}>
                      <span className={styles.whyTile} aria-hidden="true">
                        <CheckIcon className={styles.whyIcon} />
                      </span>
                      <b className={styles.whyTitle}>{reason.title}</b>
                    </span>
                    <span className={styles.whyBody}>{reason.body}</span>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
        </div>

        <aside className={styles.aside}>
          <section className={styles.priceCard} aria-label="سعر الحزمة">
            <p className={styles.priceRow}>
              <b className={styles.priceNow}>
                <Money minor={price.nowMinor} />
              </b>
              {price.savingMinor !== null ? (
                <del className={styles.priceWas}>
                  <Money minor={price.listMinor} />
                </del>
              ) : null}
            </p>

            {price.savingMinor !== null ? (
              <p className={styles.pills}>
                {price.savingPct !== null ? (
                  <span className={styles.pctPill}>
                    خصم <bdi dir="ltr">{price.savingPct}%</bdi>
                  </span>
                ) : null}
                <span className={styles.savePill}>
                  توفير <Money minor={price.savingMinor} />
                </span>
              </p>
            ) : null}

            <dl className={styles.math}>
              <div className={styles.mathRow}>
                <dt className={styles.mathLabel}>
                  {partial ? 'مجموع الملفات غير المملوكة' : 'مجموع الملفات منفردة'}
                </dt>
                <dd className={styles.mathValue}>
                  <Money minor={price.listMinor} />
                </dd>
              </div>
              {price.savingMinor !== null ? (
                <div className={styles.mathRow}>
                  <dt className={`${styles.mathLabel} ${styles.mathDiscount}`}>خصم الحزمة</dt>
                  <dd className={`${styles.mathValue} ${styles.mathDiscount}`}>
                    <bdi dir="ltr">−</bdi>
                    <Money minor={price.savingMinor} />
                  </dd>
                </div>
              ) : null}
              <div className={styles.mathRow}>
                <dt className={`${styles.mathLabel} ${styles.mathTotal}`}>الإجمالي</dt>
                <dd className={`${styles.mathValue} ${styles.mathTotal}`}>
                  <Money minor={price.nowMinor} />
                </dd>
              </div>
            </dl>

            {price.cartProductId !== undefined ? (
              <BundleAddToCart productId={price.cartProductId} />
            ) : (
              <>
                <button
                  type="button"
                  className={styles.ctaDisabled}
                  disabled
                  aria-describedby="bundle-cta-reason"
                >
                  أضف الحزمة إلى السلة
                </button>
                <p className={styles.ctaReason} id="bundle-cta-reason">
                  شراء الحزم غير مفعّل بعد. اشترِ الملفات منفردة من صفحاتها أعلاه، أو تصفّح{' '}
                  <a className={styles.link} href={routes.shop}>
                    المتجر
                  </a>
                  .
                </p>
              </>
            )}

            <ul className={styles.trust} role="list">
              <li className={styles.trustItem}>
                <CheckIcon className={styles.trustIcon} />
                الأسعار شاملة ضريبة القيمة المضافة
              </li>
              <li className={styles.trustItem}>
                <CheckIcon className={styles.trustIcon} />
                الملفات تصلك فور تأكيد الدفع وتبقى في حسابك
              </li>
              <li className={styles.trustItem}>
                <CheckIcon className={styles.trustIcon} />
                <span>
                  الاسترجاع وفق{' '}
                  <a className={styles.link} href={routes.policy('refund')}>
                    سياسة الاسترجاع
                  </a>
                </span>
              </li>
            </ul>
          </section>

          {partial ? (
            <section className={styles.partialCard} aria-label="ملفات تملكها من هذه الحزمة">
              <b className={styles.partialTitle}>لديك ملفات من هذه الحزمة</b>
              <p className={styles.partialBody}>
                ما تملكه من هذه الحزمة ({qty(ownedCount, FILE_FORMS)}) مستثنى من الحساب أعلاه، فلا
                تدفع ثمنه مرّتين.
              </p>
            </section>
          ) : null}
        </aside>
      </div>
    </div>
  );
}
