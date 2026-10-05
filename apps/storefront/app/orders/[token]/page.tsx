/**
 * Order-received page (MIP §3.2 + §5.2) — server component, SSR without JS:
 * number/status/items/totals render server-side from the token-authed API.
 * noindex,follow + outside the sitemap. Paid ⇒ files unlocked with the
 * client «حمّل» grant action; unpaid ⇒ locked-but-legible rows + honest
 * «لم يُخصم أي مبلغ بعد».
 */
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { getOrder, type OrderView } from '../../../lib/commerce-api';
import {
  closedCopyFor,
  isAwaitingStatus,
  isPaidStatus,
  lockedNoteFor,
} from '../../../lib/order-status';
import { Money, TotalsRows } from '../../../components/cart/TotalsRows';
import { DownloadButton } from '../../../components/order/DownloadButton';
import { PaymentPending } from '../../../components/order/PaymentPending';
import { ConnectionError } from '../../../components/search/ConnectionError';
import { routes } from '../../../lib/routes';
import styles from './page.module.css';

/** رموز الحالة — زخرفيّة؛ العنوان h1 يحمل المعنى. */
function StatusGlyph({ kind }: { kind: 'paid' | 'pending' | 'closed' }) {
  return (
    <svg
      viewBox="0 0 24 24"
      width="22"
      height="22"
      fill="none"
      stroke="currentColor"
      strokeWidth={2.2}
      strokeLinecap="round"
      strokeLinejoin="round"
      focusable="false"
    >
      {kind === 'paid' ? <path d="m5 12.5 4.5 4.5L19 7.5" /> : null}
      {kind === 'pending' ? (
        <>
          <circle cx="12" cy="12" r="8.5" />
          <path d="M12 7.5V12l3 2" />
        </>
      ) : null}
      {kind === 'closed' ? <path d="M12 7v6m0 3.5v.5" /> : null}
    </svg>
  );
}

export const metadata: Metadata = {
  title: 'طلبك',
  robots: { index: false, follow: true },
};

type Props = { params: Promise<{ token: string }>; searchParams?: Promise<Record<string, string | string[] | undefined>> };

export default async function OrderPage({ params, searchParams }: Props) {
  const { token } = await params;
  /*
   * عودةُ Tap تحمل `tap_id` في الاستعلام. إشارةُ ملاحةٍ لا دليلُ دفع: تُشغّل
   * الاستطلاع فقط، والحقيقة من الخادم.
   */
  const query = (await searchParams) ?? {};
  const justReturned = typeof query.tap_id === 'string' || typeof query.returned === 'string';
  const res = await getOrder(token);

  /*
   * دلالة المصدر تُحفظ حرفيًّا. كان كلّ ردّ غير ناجح — 500 و403
   * و401 — يسقط في `if (!order) notFound()` فيُقال للمشتري الذي دفع فعلًا إنّ
   * طلبه «غير موجود». المفقود وحده ٤٠٤؛ وما عداه عطلٌ يُعرض عطلًا.
   */
  if (res.status === 404) notFound();

  if (res.status !== 200 || !res.data) {
    return (
      <div className={styles.page}>
        <ConnectionError
          href={`/orders/${encodeURIComponent(token)}`}
          failure={res.status === 0 ? (res.failure ?? 'network') : 'http'}
        />
      </div>
    );
  }

  const order: OrderView = res.data;
  /* التصنيف من `lib/order-status` وحده — تشاركه ‎/account/downloads‎. */
  const paid = isPaidStatus(order.status);
  const awaiting = isAwaitingStatus(order.status);
  const closed = closedCopyFor(order.status);
  const heroClass = paid ? styles.heroPaid : awaiting ? styles.heroPending : styles.heroClosed;

  return (
    <div className={styles.page}>
      <header className={heroClass}>
        {/* شارة الحالة: الشكل يحمل المعنى مع اللون (✓ · ساعة · !) — لا اللون وحده. */}
        <span className={styles.heroBadge} aria-hidden="true">
          <StatusGlyph kind={paid ? 'paid' : awaiting || order.paymentIssue ? 'pending' : 'closed'} />
        </span>
        <h1 className={styles.heroTitle}>
          {paid
            ? 'تم الدفع — ملفاتك جاهزة الآن'
            : order.paymentIssue === 'MISMATCH'
              ? 'وصلتنا دفعتك — قيد المراجعة'
              : awaiting
                ? 'سُجّل طلبك — بانتظار الدفع'
                : (closed?.title ?? 'حالة هذا الطلب غير مؤكَّدة')}
        </h1>
        <p className={styles.heroMeta}>
          رقم الطلب: <bdi dir="ltr">{order.orderNumber}</bdi>
        </p>
        <p className={styles.heroMeta}>
          بريد الطلب: <bdi dir="ltr">{order.emailMasked}</bdi>
        </p>
        {/*
         * كان هنا «أرسلنا تفاصيل الطلب إلى …» بصيغة الماضي وبلا شرط — ولا
         * مُرسِل بريد في الخلفية إطلاقًا (لا app/Mail ولا app/Notifications
         * ولا مستهلك لحدث order.paid). فكان المشتري يغلق التبويب منتظرًا
         * رسالةً لا تأتي، ورابط هذه الصفحة هو مفتاح ملفّاته الوحيد.
         */}
        <p className={styles.keepLink}>
          احفظ رابط هذه الصفحة — هو مفتاح ملفّاتك، ولا تُرسَل نسخةٌ منه على البريد.
        </p>
        {awaiting || order.paymentIssue ? (
          <PaymentPending
            token={token}
            payUrl={order.payment?.payUrl ?? null}
            canRetry={order.payment?.canRetry ?? false}
            lastAttemptFailed={order.payment?.state === 'FAILED'}
            issue={order.paymentIssue ?? null}
            justReturned={justReturned}
          />
        ) : null}
        {closed && !order.paymentIssue ? <p className={styles.noCharge}>{closed.note}</p> : null}
        {paid ? (
          <div className={styles.heroActions}>
            <a
              className={styles.heroAction}
              href={`/account/downloads?token=${encodeURIComponent(token)}`}
            >
              كل ملفاتي
            </a>
            <a className={styles.heroActionQuiet} href={routes.shop}>
              تابع التسوّق
            </a>
          </div>
        ) : null}
      </header>

      {/*
        تقدّم الطلب — ثلاث مراحل حقيقيّة بالترتيب (سُجّل ← الدفع ← الملفات)، من
        الحالة التي يعيدها الخادم لا من افتراض. الطلب المغلق (مسترد/ملغى) لا
        يُعرض له تقدّم: ليس في طريقه إلى الاكتمال.
      */}
      {paid || awaiting ? (
        <ol className={styles.progress} aria-label="تقدّم الطلب">
          <li className={styles.stepDone}>
            <span className={styles.stepDot} aria-hidden="true" />
            سُجّل الطلب
          </li>
          <li
            className={paid ? styles.stepDone : styles.stepNow}
            aria-current={paid ? undefined : 'step'}
          >
            <span className={styles.stepDot} aria-hidden="true" />
            {paid ? 'تمّ الدفع' : 'بانتظار الدفع'}
          </li>
          <li
            className={paid ? styles.stepDone : styles.stepNext}
            aria-current={paid ? 'step' : undefined}
          >
            <span className={styles.stepDot} aria-hidden="true" />
            الملفات جاهزة
          </li>
        </ol>
      ) : null}

      <div className={styles.layout}>
        <section className={styles.filesCol} aria-label="ملفات الطلب">
          <h2 className={styles.sectionTitle}>الملفات</h2>

          {order.files.length === 0 ? (
            <p className={styles.noFiles}>لا توجد ملفات مرتبطة بهذا الطلب.</p>
          ) : (
            <ul className={styles.files} role="list">
              {order.files.map((file) => {
                const unlocked = paid && !file.locked;
                return (
                  <li
                    key={file.entitlementId}
                    className={unlocked ? styles.fileRow : `${styles.fileRow} ${styles.fileLocked}`}
                  >
                    <div className={styles.fileBody}>
                      <p className={styles.fileTitle}>{file.title}</p>
                      <p className={styles.fileName}>
                        <bdi dir="ltr">{file.filename}</bdi>
                      </p>
                    </div>
                    {unlocked ? (
                      <DownloadButton entitlementId={file.entitlementId} orderToken={token} />
                    ) : (
                      <span className={styles.lockedChip}>مقفل</span>
                    )}
                  </li>
                );
              })}
            </ul>
          )}

          {/*
           * الشرح يتبع القفل لا الحالة: صفٌّ مقفل في طلبٍ مدفوع (استحقاقٌ
           * مسحوب) كان يُترك بلا سبب، وصفٌّ مقفل في طلبٍ مسترد كان يُوعَد
           * بالفتح «فور تأكيد الدفع» — والدفع تمّ ثمّ استُرجع.
           */}
          {order.files.some((file) => file.locked || !paid) ? (
            <details className={styles.whyLocked}>
              <summary className={styles.whyLockedSummary}>
                لماذا مقفلة؟
                <span className={styles.chevron} aria-hidden="true" />
              </summary>
              {/*
                السبب من `lockedNoteFor` لا من سطرٍ محلّيّ.
                كانت النسخة المحلّيّة تقول لطلبٍ مدفوعٍ فيه صفٌّ مقفل «سُحبت
                صلاحية التحميل» — وهو اختراعُ سبب: القفل يقع أيضًا حين لا صفَّ
                استحقاقٍ بعد (`OrderController`: `$entitlement === null`)، وهي
                حالةُ تسليمٍ فشل ويعيده `RetryFulfillmentUnitJob`. فكان
                المشتري يُدفع إلى نزاع «سحبتم ما دفعت ثمنه» بدل إعادة تسليم.
                والصفحة و‎/account/downloads‎ تنطقان الآن بسببٍ واحد.
              */}
              <p className={styles.whyLockedBody}>{lockedNoteFor(order.status)}</p>
            </details>
          ) : null}
        </section>

        <aside className={styles.summary} aria-label="ملخّص الطلب">
          <h2 className={styles.sectionTitle}>ملخّص الطلب</h2>
          <ul className={styles.summaryItems} role="list">
            {order.items.map((item) => (
              <li key={item.title} className={styles.summaryItem}>
                <span>{item.title}</span>
                <Money minor={item.priceMinor} />
              </li>
            ))}
          </ul>
          <TotalsRows totals={order.totals} />
          {order.paymentMethodMasked ? (
            <p className={styles.paymentMethod}>
              وسيلة الدفع: <bdi dir="ltr">{order.paymentMethodMasked}</bdi>
            </p>
          ) : null}
        </aside>
      </div>
    </div>
  );
}
