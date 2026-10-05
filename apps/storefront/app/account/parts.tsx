/**
 * Shared account pieces: the order-status chip, the «no session» explainer that
 * every account route falls back to, the design's «ميزات غير مربوطة بعد» panel,
 * and the cross-links that keep an order token alive while moving between
 * sections.
 *
 * Server components. Nothing here invents data: every value shown comes from
 * `/api/v1/orders/{token}` or is absent.
 */
import { OrderLookupForm } from '../login/OrderLookupForm';
import { routes } from '../../lib/routes';
import { accountRoutes, NOT_CONNECTED } from './nav';
import { AccountIcon, type AccountIconName } from './icons';
import { ThemedArt } from '../../components/home/ThemedArt';
import { EMPTY_ART, type Art } from '../../lib/art';
import styles from './shared.module.css';

/* ────────────────────────────────────────────────────────────── status ──── */

type ChipTone = 'chipGreen' | 'chipAmber' | 'chipNeutral';

interface StatusSpec {
  label: string;
  tone: ChipTone;
  icon: AccountIconName;
}

/** The order-status enum as the commerce migration declares it. */
const STATUS: Record<string, StatusSpec> = {
  PENDING: { label: 'بانتظار الدفع', tone: 'chipAmber', icon: 'clock' },
  AWAITING_PAYMENT: { label: 'بانتظار الدفع', tone: 'chipAmber', icon: 'clock' },
  PAID: { label: 'مدفوع', tone: 'chipGreen', icon: 'check' },
  PROCESSING: { label: 'قيد المعالجة', tone: 'chipGreen', icon: 'check' },
  COMPLETED: { label: 'مكتمل', tone: 'chipGreen', icon: 'check' },
  FAILED: { label: 'تعذّر الدفع', tone: 'chipAmber', icon: 'info' },
  CANCELLED: { label: 'ملغى', tone: 'chipNeutral', icon: 'info' },
  REFUNDED: { label: 'مسترد', tone: 'chipNeutral', icon: 'refresh' },
  PARTIALLY_REFUNDED: { label: 'مسترد جزئيًا', tone: 'chipNeutral', icon: 'refresh' },
};

/** Unknown status ⇒ the server's own value, verbatim — never a guessed label. */
export function StatusChip({ status }: { status: string }) {
  const spec = STATUS[status.toUpperCase()];
  if (!spec) {
    return (
      <span className={`${styles.chip} ${styles.chipNeutral}`}>
        <bdi dir="ltr">{status}</bdi>
      </span>
    );
  }
  return (
    <span className={`${styles.chip} ${styles[spec.tone]}`}>
      <AccountIcon name={spec.icon} className={styles.chipIcon} strokeWidth={2.6} />
      {spec.label}
    </span>
  );
}

const DATE_FORMAT = new Intl.DateTimeFormat('ar', {
  calendar: 'gregory',
  numberingSystem: 'latn',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
});

/** Absent or unparsable timestamp ⇒ null, and the caller renders no date. */
export function orderDate(iso: string | undefined): { iso: string; text: string } | null {
  if (!iso) return null;
  const parsed = new Date(iso);
  if (Number.isNaN(parsed.getTime())) return null;
  return { iso: parsed.toISOString(), text: DATE_FORMAT.format(parsed) };
}

/** Uppercase extension of a real filename, or null when no file is attached. */
export function fileExtension(filename: string | null | undefined): string | null {
  if (!filename) return null;
  const match = /\.([a-z0-9]{1,8})$/i.exec(filename);
  return match?.[1] ? match[1].toUpperCase() : null;
}


/* ───────────────────────────────────────────────────────────────── empty ── */

export interface EmptyCardProps {
  /** رسمٌ من ‎EMPTY_ART‎ — زخرفيّ (‎alt=""‎)، فالعنوان يحمل المعنى. */
  art: Art;
  title: string;
  body: string;
  /** الخطوة التالية — حالةٌ فارغة بلا مخرجٍ طريقٌ مسدود. */
  action: { href: string; label: string };
}

/** حالةٌ فارغة واحدة لصفحات الحساب: رسم + رسالة واحدة + فعل. */
export function EmptyCard({ art, title, body, action }: EmptyCardProps) {
  return (
    <div className={styles.empty}>
      <ThemedArt art={art} alt="" sizes="(max-width: 767px) 112px, 144px" className={styles.emptyArt} />
      <p className={styles.emptyTitle}>{title}</p>
      <p className={styles.emptyBody}>{body}</p>
      <a className={styles.primary} href={action.href}>
        {action.label}
      </a>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────── no session ── */

export interface NoSessionPanelProps {
  /** What this particular section would show once a session exists. */
  body: string;
}

export function NoSessionPanel({ body }: NoSessionPanelProps) {
  return (
    <section className={styles.section} aria-labelledby="acc-nosession">
      <div className={styles.heroHead}>
        <span className={styles.heroIcon} aria-hidden="true">
          <AccountIcon name="link" className={styles.emptyGlyph} strokeWidth={2} />
        </span>
        <div>
          <h2 className={styles.sectionTitle} id="acc-nosession">
            افتح طلبك برابط بريدك
          </h2>
          <p className={styles.lead}>{body}</p>
        </div>
      </div>

      {/* مرساة «افتح طلبك برابطه» في الحالات الفارغة. */}
      <div className={styles.lookupBox} id="order-lookup">
        <OrderLookupForm />
      </div>

      <p className={styles.meta}>
        الشراء لا يتطلّب حسابًا.{' '}
        <a className={styles.inlineLink} href={routes.login}>
          صفحة الدخول
        </a>{' '}
        تشرح طرق الدخول المتاحة وحالة كل واحدة منها.
      </p>
    </section>
  );
}

/* ────────────────────────────────────────────── not-connected features ──── */

export function NotConnectedPanel() {
  return (
    <section className={styles.notConnected} aria-labelledby="acc-missing">
      <div className={styles.notConnectedHead}>
        <AccountIcon name="info" className={styles.notConnectedIcon} strokeWidth={2} />
        <div>
          <h2 className={styles.notConnectedTitle} id="acc-missing">
            ميزات غير مربوطة بعد
          </h2>
          <p className={styles.notConnectedBody}>
            هذه الأقسام مطلوبة في التصميم لكن لا يوجد لها مصدر بيانات في الواجهة البرمجية حاليًا.
            لم تُرسم بأرقام متخيّلة — تبقى معروضة هكذا حتى تُبنى فعليًا.
          </p>
        </div>
      </div>
      <ul className={styles.notConnectedList}>
        {NOT_CONNECTED.map((item) => (
          <li key={item.label} className={styles.notConnectedItem}>
            <AccountIcon name={item.icon} className={styles.notConnectedItemIcon} strokeWidth={2} />
            <span className={styles.notConnectedLabel}>{item.label}</span>
            <span className={styles.notConnectedTag}>يحتاج ربطًا</span>
          </li>
        ))}
      </ul>
    </section>
  );
}

/* ─────────────────────────────────────────────────── order context links ── */

const CONTEXT_SECTIONS = [
  { href: accountRoutes.orders, label: 'الطلب' },
  { href: accountRoutes.library, label: 'المكتبة' },
  { href: accountRoutes.downloads, label: 'التنزيلات' },
] as const;

/**
 * The token lives in the query string, so moving between sections has to carry
 * it — otherwise a reader loses the order by clicking the nav.
 */
export function OrderContextLinks({ token, current }: { token: string; current: string }) {
  const query = `?token=${encodeURIComponent(token)}`;
  return (
    <p className={styles.contextLinks}>
      <span>هذا الطلب أيضًا في:</span>
      {CONTEXT_SECTIONS.filter((section) => section.href !== current).map((section) => (
        <a key={section.href} className={styles.contextLink} href={`${section.href}${query}`}>
          {section.label}
        </a>
      ))}
      <a className={styles.contextLink} href={`/orders/${encodeURIComponent(token)}`}>
        صفحة الطلب الكاملة
      </a>
    </p>
  );
}

/* ──────────────────────────────────────────────────────── token failure ─── */

/** A token that resolves to nothing: say so, and offer the form again. */
export function UnknownToken() {
  return (
    <section className={styles.section} aria-labelledby="acc-badtoken">
      <h2 className={styles.sectionTitle} id="acc-badtoken">
        لم نجد طلبًا بهذا الرمز
      </h2>
      <div className={styles.badTokenHead}>
        <ThemedArt art={EMPTY_ART.search} alt="" sizes="96px" className={styles.badTokenArt} />
        <p className={styles.lead}>
          تأكّد من نسخ الرابط كاملًا من رسالة تأكيد الطلب — الرمز طويل ويُلصق كما هو.
        </p>
      </div>
      <div className={styles.lookupBox}>
        <OrderLookupForm />
      </div>
    </section>
  );
}
