/**
 * /account/downloads — «Tahdheer - Customer Completion Pack» download library.
 *
 * The row layout is the design's (inline-start status edge, format tile, state
 * chip, action). What is dropped is what has no endpoint: «تنزيل الكل ZIP», the
 * subject facets and the library search — a button that cannot act is worse
 * than a button that is not there.
 *
 * With `?token=` the rows are REAL, and the action is the existing grant →
 * redeem flow from the order page: POST /api/v1/downloads/{id}/grant with the
 * order token, then follow the short-lived signed URL.
 */
import type { Metadata } from 'next';
import { getOrder } from '../../../lib/commerce-api';
import { lockedNoteFor } from '../../../lib/order-status';
import { DownloadButton } from '../../../components/order/DownloadButton';
import { ConnectionError } from '../../../components/search/ConnectionError';
import { accountRoutes } from '../nav';
import { EmptyCard, NoSessionPanel, OrderContextLinks, UnknownToken, fileExtension } from '../parts';
import { EMPTY_ART } from '../../../lib/art';
import shared from '../shared.module.css';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'تنزيلاتي',
  robots: { index: false, follow: true },
};

type Props = { searchParams: Promise<{ token?: string }> };

export default async function AccountDownloadsPage({ searchParams }: Props) {
  const { token } = await searchParams;

  return (
    <>
      <header className={shared.section}>
        <h1 className={shared.pageTitle}>تنزيلاتي</h1>
        <p className={shared.lead}>
          روابط التحميل تُولَّد لحظة الطلب، موقَّعة وقصيرة الأجل، ومربوطة برمز الطلب لا بحساب.
        </p>
      </header>

      {token ? (
        <DownloadsPanel token={token} />
      ) : (
        <>
          <NoSessionPanel body="ألصق رابط طلبك لتظهر ملفاته القابلة للتحميل هنا." />
          <section className={shared.section} aria-labelledby="acc-dl-empty">
            <h2 className={shared.sectionTitle} id="acc-dl-empty">
              التنزيلات
            </h2>
            {/*
              «من رسالة التأكيد» أحالت المشتري إلى بريدٍ لا يُرسَل — لا
              `app/Mail` ولا مستهلك لحدث `order.paid` في الخلفية. الإحالة
              الآن إلى الرابط الذي يملكه فعلًا (نموذج فتح الطلب أعلى الصفحة).
            */}
            <EmptyCard
              art={EMPTY_ART.downloads}
              title="لا توجد تنزيلات معروضة الآن."
              body="التنزيلات تتبع الطلب: الصق رابط صفحة طلبك لتظهر ملفاته وروابطها."
              action={{ href: '#order-lookup', label: 'افتح طلبك برابطه' }}
            />
          </section>
        </>
      )}
    </>
  );
}

async function DownloadsPanel({ token }: { token: string }) {
  const res = await getOrder(token);

  /*
   * ٤٠٤ وحدها تعني «رمز غير معروف». كان كلّ ردٍّ غير ناجح — ٥٠٠ و٤٠٣ — يمرّ
   * إلى `UnknownToken` فيُتّهم رمزُ المشتري الصحيح بأنّه خطأ.
   */
  if (res.status !== 200 || !res.data) {
    if (res.status === 404) return <UnknownToken />;
    return (
      <ConnectionError
        href={`${accountRoutes.downloads}?token=${encodeURIComponent(token)}`}
        failure={res.status === 0 ? (res.failure ?? 'network') : 'http'}
      />
    );
  }

  const order = res.data;

  return (
    <section className={shared.section} aria-labelledby="acc-dl">
      <div className={shared.sectionHead}>
        <h2 className={shared.sectionTitle} id="acc-dl">
          ملفات الطلب <bdi dir="ltr">{order.orderNumber}</bdi>
        </h2>
        <OrderContextLinks token={token} current={accountRoutes.downloads} />
      </div>

      {order.files.length === 0 ? (
        <p className={shared.lead}>لا توجد ملفات مرتبطة بهذا الطلب.</p>
      ) : (
        <ul className={styles.rows}>
          {order.files.map((file, index) => {
            const ext = fileExtension(file.filename);
            const unlocked = !file.locked && file.entitlementId != null;
            return (
              <li
                key={`${file.title}-${index}`}
                className={unlocked ? `${styles.row} ${styles.ready}` : `${styles.row} ${styles.locked}`}
              >
                <span className={styles.tile} aria-hidden="true">
                  {ext ?? '—'}
                </span>
                <span className={styles.rowText}>
                  <b className={styles.rowTitle}>{file.title}</b>
                  {file.filename ? (
                    <span className={styles.rowMeta}>
                      <bdi dir="ltr">{file.filename}</bdi>
                    </span>
                  ) : null}
                </span>
                <span
                  className={
                    unlocked
                      ? `${shared.chip} ${shared.chipGreen}`
                      : `${shared.chip} ${shared.chipAmber}`
                  }
                >
                  {unlocked ? 'جاهز' : 'مقفل'}
                </span>
                <span className={styles.rowAction}>
                  {unlocked ? (
                    <DownloadButton entitlementId={file.entitlementId} orderToken={token} />
                  ) : (
                    /*
                     * كان كلّ صفٍّ مقفل يُوسَم «يُفتح فور تأكيد الدفع»، فيُعرض
                     * الاستحقاق المسحوب أو المسترد بوصفه طلبًا لم يُدفع —
                     * وعدٌ بفتحٍ لن يقع. الحالتان تُفصلان بحالة الطلب نفسها.
                     */
                    <span className={styles.rowNote}>{lockedNoteFor(order.status)}</span>
                  )}
                </span>
              </li>
            );
          })}
        </ul>
      )}

      <p className={styles.security}>
        <b>أمن التسليم:</b> يُولَّد رابط التحميل عند الضغط فقط، موقَّعًا وقصير الأجل، وتُفحص
        الأهلية لحظة الطلب لا لحظة العرض — فلا رابط قابل للتخمين ولا وصول بعد سحب الصلاحية.
      </p>
    </section>
  );
}
