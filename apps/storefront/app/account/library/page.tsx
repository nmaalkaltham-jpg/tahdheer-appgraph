/**
 * /account/library — the design's library grid.
 *
 * Without a session there is no library, so the grid renders the design's own
 * empty state. With `?token=` the grid is REAL: one card per file on that
 * order, with the file's own name and format, and the existing grant → redeem
 * download action reused from the order page. Grade/subject chips and the
 * «محدّث» badge are NOT drawn: the order endpoint carries neither, and a
 * category invented for a card is still an invention.
 */
import type { Metadata } from 'next';
import { qty, type PluralForms } from '@tahdheer/design-system/format';
import { getOrder } from '../../../lib/commerce-api';
import { DownloadButton } from '../../../components/order/DownloadButton';
import { ConnectionError } from '../../../components/search/ConnectionError';
import { routes } from '../../../lib/routes';
import { accountRoutes } from '../nav';
import { EmptyCard, NoSessionPanel, OrderContextLinks, UnknownToken, fileExtension } from '../parts';
import { EMPTY_ART } from '../../../lib/art';
import shared from '../shared.module.css';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'مكتبتي التعليمية',
  robots: { index: false, follow: true },
};

const FILE_FORMS: PluralForms = {
  zero: 'لا ملفات بعد',
  one: 'ملف واحد',
  two: 'ملفان',
  few: 'ملفات',
  many: 'ملفًا',
};

type Props = { searchParams: Promise<{ token?: string }> };

export default async function AccountLibraryPage({ searchParams }: Props) {
  const { token } = await searchParams;

  return (
    <>
      <header className={shared.section}>
        <h1 className={shared.pageTitle}>مكتبتي التعليمية</h1>
        <p className={shared.lead}>
          ملفاتك مرتبطة بطلباتها، لا بحساب: افتح طلبًا برمزه لتظهر ملفاته هنا مع روابط تحميلها.
        </p>
      </header>

      {token ? (
        <LibraryPanel token={token} />
      ) : (
        <>
          <NoSessionPanel body="ألصق رابط طلبك لتفتح ملفاته في هذه المكتبة." />
          <section className={shared.section} aria-labelledby="acc-lib-empty">
            <div className={shared.sectionHead}>
              <div>
                <h2 className={shared.libraryTitle} id="acc-lib-empty">
                  المكتبة
                </h2>
                <p className={shared.meta}>{qty(0, FILE_FORMS)}</p>
              </div>
            </div>
            <EmptyCard
              art={EMPTY_ART.library}
              title="مكتبتك التعليمية بانتظار أول إضافة."
              body="عند شراء أول ملف سيظهر هنا مع روابط تحميله."
              action={{ href: routes.stages, label: 'تصفّح المراحل الدراسية' }}
            />
          </section>
        </>
      )}
    </>
  );
}

async function LibraryPanel({ token }: { token: string }) {
  const res = await getOrder(token);

  if (res.status === 0) {
    return <ConnectionError href={`${accountRoutes.library}?token=${encodeURIComponent(token)}`} />;
  }

  const order = res.data;
  if (!order) return <UnknownToken />;

  const anyLocked = order.files.some((file) => file.locked);

  return (
    <section className={shared.section} aria-labelledby="acc-lib">
      <div className={shared.sectionHead}>
        <div>
          <h2 className={shared.libraryTitle} id="acc-lib">
            ملفات الطلب <bdi dir="ltr">{order.orderNumber}</bdi>
          </h2>
          <p className={shared.meta}>{qty(order.files.length, FILE_FORMS)}</p>
        </div>
        <OrderContextLinks token={token} current={accountRoutes.library} />
      </div>

      {order.files.length === 0 ? (
        <p className={shared.lead}>لا توجد ملفات مرتبطة بهذا الطلب.</p>
      ) : (
        <ul className={styles.grid}>
          {order.files.map((file, index) => {
            const ext = fileExtension(file.filename);
            const unlocked = !file.locked && file.entitlementId != null;
            return (
              <li key={`${file.title}-${index}`} className={styles.card}>
                <div className={styles.thumb} aria-hidden="true">
                  <span className={styles.thumbText}>{ext ?? 'ملف'}</span>
                </div>
                <div className={styles.body}>
                  {ext ? (
                    <div className={styles.chips}>
                      <span className={styles.formatChip}>{ext}</span>
                    </div>
                  ) : null}
                  <b className={styles.title}>{file.title}</b>
                  {file.filename ? (
                    <p className={styles.file}>
                      <bdi dir="ltr">{file.filename}</bdi>
                    </p>
                  ) : null}
                  <div className={styles.cardAction}>
                    {unlocked ? (
                      <DownloadButton entitlementId={file.entitlementId} orderToken={token} />
                    ) : (
                      <span className={`${shared.chip} ${shared.chipAmber}`}>
                        مقفل — بانتظار تأكيد الدفع
                      </span>
                    )}
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {anyLocked ? (
        <p className={shared.meta}>الملفات المقفلة تُفتح تلقائيًا فور تأكيد الدفع.</p>
      ) : null}
    </section>
  );
}
