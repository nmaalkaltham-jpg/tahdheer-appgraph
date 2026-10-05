/**
 * بطاقة الدخول — بصدق.
 *
 * الترتيب بحسب ما يعمل فعلًا: «المتابعة كزائر» (الشراء لا يحتاج حسابًا) ثمّ فتح
 * طلبٍ سابق برمزه — وهما المسلكان العاملان اليوم. ما لم يُربط بعد (Google ·
 * Apple · رابط البريد) يُذكر داخل كشفٍ واحدٍ هادئ بلا أزرارٍ ولا حقولٍ معطّلة:
 * لا جلسة عميل في الخادم، ولا مسار OAuth، ولا مُرسِل بريد. لا شيء هنا يحاكي دخولًا.
 *
 * مكوّن خادم — التفاعل الوحيد نموذج فتح الطلب، والكشف ‎<details>‎ أصيل.
 */
import type { ReactNode } from 'react';
import { routes } from '../../lib/routes';
import { OrderLookupForm } from './OrderLookupForm';
import { AppleMark, AuthIcon, GoogleMark, LogoMark, type AuthIconName } from './icons';
import styles from './AuthCard.module.css';

/** The notice band's five variants (Tahdheer - Auth Overlay §2). */
export type NoticeKey = 'checkout' | 'cancelled' | 'failed' | 'expired' | 'limited';

const NOTICE_KEYS: readonly NoticeKey[] = ['checkout', 'cancelled', 'failed', 'expired', 'limited'];

export function parseNotice(value: string | undefined): NoticeKey | null {
  return NOTICE_KEYS.find((key) => key === value) ?? null;
}

interface NoticeSpec {
  icon: AuthIconName;
  tone: 'soft' | 'green' | 'amber' | 'danger';
  title: string;
  body: string;
}

/*
 * Copy is the design's, with two edits the honesty doctrine forces:
 * · the checkout notice's «3 ملفات بقيمة 455.00 ر.س» is a SAMPLE — a count and
 *   a total we cannot know here — so the title carries no number;
 * · promises the backend cannot keep (linking a past order to a new account,
 *   re-sending an e-mail link) are replaced by what actually works.
 */
const NOTICES: Record<NoticeKey, NoticeSpec> = {
  checkout: {
    icon: 'cart',
    tone: 'green',
    title: 'سلّتك جاهزة — أكمل الشراء',
    // لا مُرسِل بريد في الخلفية — رابط صفحة الطلب هو المسلك الوحيد.
    body: 'أكمل الشراء دون حساب. تظهر ملفاتك في صفحة الطلب فور الدفع — احفظ رابطها.',
  },
  cancelled: {
    icon: 'info',
    tone: 'soft',
    title: 'أُلغيت العملية من نافذة المزوّد',
    body: 'لم يُنشأ أي حساب ولم تتغيّر سلّتك. تابع كزائر — الشراء لا يتطلّب حسابًا.',
  },
  failed: {
    icon: 'info',
    tone: 'danger',
    title: 'تعذّر الوصول إلى المزوّد',
    body: 'الخدمة لم تستجب. سلّتك محفوظة كما هي — تابع كزائر أو افتح طلبك برابط بريدك.',
  },
  expired: {
    icon: 'clock',
    tone: 'amber',
    title: 'انتهت صلاحية جلستك',
    body: 'سلّتك وموضعك في الصفحة محفوظان. تابع كزائر — الشراء لا يتطلّب حسابًا.',
  },
  limited: {
    icon: 'clock',
    tone: 'amber',
    title: 'طلبات كثيرة في وقت قصير',
    body: 'انتظر قليلًا قبل المحاولة مرة أخرى. هذا الحد يحمي بريدك من الإرسال المتكرّر.',
  },
};

/*
 * مسالك الدخول غير المتاحة بعد — تُذكر بصدقٍ داخل كشفٍ واحدٍ هادئ، لا أزرارًا
 * معطّلةً تزاحم المسلكين العاملين. لا زرّ هنا ولا حقل: ما لا يعمل لا يُعرض
 * كأنّه يعمل. الصياغة بلغة الزائر؛ السبب التقنيّ في الشيفرة لا على الشاشة
 * (لا مسار OAuth في الخادم، ولا مُرسِل بريد لرابط الدخول).
 */
interface MethodSpec {
  id: 'google' | 'apple' | 'email';
  label: string;
  mark: ReactNode;
}

const PENDING_METHODS: readonly MethodSpec[] = [
  { id: 'google', label: 'حساب Google', mark: <GoogleMark className={styles.brandMark} /> },
  { id: 'apple', label: 'حساب Apple', mark: <AppleMark className={styles.brandMark} /> },
  {
    id: 'email',
    label: 'رابط دخول إلى بريدك',
    mark: <AuthIcon name="mail" className={styles.brandMark} />,
  },
];

function Divider({ label }: { label: string }) {
  return (
    <div className={styles.divider} aria-hidden="true">
      <span className={styles.dividerRule} />
      <span className={styles.dividerLabel}>{label}</span>
      <span className={styles.dividerRule} />
    </div>
  );
}

const TONE_CLASS: Record<NoticeSpec['tone'], string | undefined> = {
  soft: styles.noticeInfo,
  green: styles.noticeSuccess,
  amber: styles.noticeWarning,
  danger: styles.noticeDanger,
};

export interface AuthCardProps {
  /** Rendered only when the URL actually carries `?notice=` — never assumed. */
  notice: NoticeKey | null;
}

export function AuthCard({ notice }: AuthCardProps) {
  const spec = notice ? NOTICES[notice] : null;

  return (
    <section className={styles.card} aria-labelledby="auth-title">
      <span className={styles.topBar} aria-hidden="true" />

      <a className={styles.close} href={routes.home} aria-label="إغلاق ومتابعة التصفّح">
        <AuthIcon name="close" className={styles.closeGlyph} strokeWidth={2.2} />
      </a>

      <div className={styles.head}>
        <span className={styles.logoTile} aria-hidden="true">
          <LogoMark size={25} />
        </span>
        <div className={styles.headText}>
          <h1 className={styles.title} id="auth-title">
            الدخول إلى حسابك
          </h1>
          <p className={styles.subtitle}>بلا كلمة مرور وبلا رقم جوال — اختر ما يناسبك.</p>
        </div>
      </div>

      {spec ? (
        <div role="status" aria-live="polite">
          <div className={`${styles.notice} ${TONE_CLASS[spec.tone]}`}>
            <AuthIcon name={spec.icon} className={styles.noticeIcon} strokeWidth={2.2} />
            <div className={styles.noticeText}>
              <b className={styles.noticeTitle}>{spec.title}</b>
              <span className={styles.noticeBody}>{spec.body}</span>
            </div>
          </div>
        </div>
      ) : null}

      {/* المسلك الأوّل: الشراء لا يحتاج حسابًا. */}
      <a className={styles.guest} href={routes.cart}>
        <AuthIcon name="bolt" className={styles.guestGlyph} />
        المتابعة كزائر
      </a>
      <p className={styles.guestNote}>الأسرع — بلا حساب. تظهر ملفاتك في صفحة الطلب فور الدفع.</p>

      <Divider label="أو افتح طلبًا سابقًا" />

      {/* المسلك الثاني العامل: رمز الطلب هو مفتاح الملفات اليوم. */}
      <OrderLookupForm heading="عندك طلب سابق؟ افتحه مباشرة" tone="secondary" />

      <details className={styles.more}>
        <summary className={styles.moreSummary}>
          <span className={styles.moreTitle}>طرق دخول أخرى</span>
          <span className={styles.soon}>قريبًا</span>
          <span className={styles.chevron} aria-hidden="true" />
        </summary>
        <div className={styles.moreBody}>
          <p className={styles.moreLead}>
            الدخول بهذه الطرق غير متاح بعد. لا تحتاجه للشراء ولا لتحميل ملفاتك — رابط طلبك يكفي.
          </p>
          <ul className={styles.methods}>
            {PENDING_METHODS.map((method) => (
              <li key={method.id} className={styles.method}>
                {method.mark}
                <span>{method.label}</span>
                <span className={styles.methodState}>غير متاح بعد</span>
              </li>
            ))}
          </ul>
        </div>
      </details>

      <p className={styles.legal}>
        بالمتابعة أنت توافق على{' '}
        <a href={routes.policy('terms')}>الشروط والأحكام</a> و
        <a href={routes.policy('privacy')}>سياسة الخصوصية</a>. لا نرسل رسائل تسويقية دون موافقتك.
      </p>
    </section>
  );
}
