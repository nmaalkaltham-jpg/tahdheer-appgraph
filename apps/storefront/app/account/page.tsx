/**
 * /account — the dashboard from «Tahdheer - My Account», rendering the only
 * state this backend can honestly produce.
 *
 * There is no customer-session endpoint: `/api/v1/orders/{token}` and the
 * download endpoints authenticate with the signed order token from the
 * confirmation e-mail. So the greeting, the profile-completion bar, the stat
 * values, the recent orders, the purchase-updates timeline and the library grid
 * have no source — and none of them is filled with the design's sample values.
 * The layout is the design's; the content is the truth: buying needs no account,
 * and the order link is the way in.
 */
import type { Metadata } from 'next';
import { routes } from '../../lib/routes';
import { AccountIcon, type AccountIconName } from './icons';
import { accountRoutes } from './nav';
import { EmptyCard, NoSessionPanel, NotConnectedPanel } from './parts';
import { EMPTY_ART } from '../../lib/art';
import shared from './shared.module.css';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'حسابي',
};

/**
 * The design's four stat tiles. A stat without a session has no number, so the
 * tile keeps its shape and states its absence — it never shows a placeholder
 * digit that a reader could mistake for a count.
 */
const STATS: readonly { label: string; icon: AccountIconName }[] = [
  { label: 'طلباتي', icon: 'cartBag' },
  { label: 'مكتبتي', icon: 'book' },
  { label: 'التنزيلات', icon: 'download' },
  { label: 'المفضلة', icon: 'heart' },
];

const QUICK_ACTIONS: readonly { label: string; icon: AccountIconName; href: string }[] = [
  { label: 'افتح طلبًا برابط بريدك', icon: 'link', href: routes.login },
  { label: 'تصفّح المراحل الدراسية', icon: 'grid', href: routes.stages },
  { label: 'ابحث في المتجر', icon: 'search', href: routes.search },
];

export default function AccountPage() {
  return (
    <>
      <section className={shared.section} aria-labelledby="acc-greet">
        <div className={shared.heroHead}>
          <span className={shared.heroIcon} aria-hidden="true">
            <AccountIcon name="userCircle" className={shared.emptyGlyph} strokeWidth={1.8} />
          </span>
          <div className={styles.heroText}>
            <h1 className={shared.pageTitle} id="acc-greet">
              حسابك
            </h1>
            <p className={shared.lead}>
              الشراء والتحميل في هذا المتجر لا يتطلّبان حسابًا. ولا يوجد تسجيل دخول للعملاء في
              الخادم بعد، فلا توجد جلسة نعرض بياناتها هنا — وما تراه أدناه هو هيكل الحساب بحالته
              الحقيقية، بلا أرقام متخيّلة.
            </p>
          </div>
        </div>
      </section>

      <NoSessionPanel body="طلبك وملفاته يفتحهما الرمز الموقَّع الموجود في رابط رسالة التأكيد — لا حساب ولا كلمة مرور." />

      <section aria-labelledby="acc-stats">
        <h2 className={shared.srOnly} id="acc-stats">
          ملخص الحساب
        </h2>
        <ul className={styles.stats}>
          {STATS.map((stat, index) => (
            <li
              key={stat.label}
              className={`${styles.stat} ${index % 2 === 1 ? styles.statGold : styles.statGreen}`}
            >
              <div className={styles.statHead}>
                <span className={styles.statIcon} aria-hidden="true">
                  <AccountIcon name={stat.icon} className={styles.statGlyph} strokeWidth={2} />
                </span>
                <span className={styles.statLabel}>{stat.label}</span>
              </div>
              <p className={styles.statValue} aria-hidden="true">
                —
              </p>
              <p className={styles.statHint}>غير متاح بلا جلسة حساب</p>
            </li>
          ))}
        </ul>
      </section>

      <section className={shared.section} aria-labelledby="acc-quick">
        <h2 className={`${shared.sectionTitle} ${shared.sectionTitleSpaced}`} id="acc-quick">
          إجراءات سريعة
        </h2>
        <div className={`${shared.actions} ${styles.quick}`}>
          {QUICK_ACTIONS.map((action) => (
            <a key={action.href} className={shared.action} href={action.href}>
              <AccountIcon name={action.icon} className={shared.actionIcon} strokeWidth={2} />
              {action.label}
            </a>
          ))}
        </div>
      </section>

      <div className={styles.split}>
        <section className={shared.section} aria-labelledby="acc-orders">
          <div className={shared.sectionHead}>
            <h2 className={shared.sectionTitle} id="acc-orders">
              أحدث الطلبات
            </h2>
            <a className={shared.headLink} href={accountRoutes.orders}>
              قسم الطلبات
            </a>
          </div>
          <p className={shared.lead}>
            لا تُعرض طلبات هنا: لا توجد جلسة حساب تربط الطلبات بشخص. افتح أي طلب برابطه من رسالة
            التأكيد.
          </p>
        </section>

        <section className={shared.section} aria-labelledby="acc-updates">
          <h2 className={`${shared.sectionTitle} ${shared.sectionTitleSpacedWide}`} id="acc-updates">
            تحديثات مشترياتك
          </h2>
          <p className={shared.lead}>
            لا يوجد في الواجهة البرمجية مصدر لتحديثات الملفات بعد، فلا خطّ زمني يُعرض هنا.
          </p>
        </section>
      </div>

      <section className={shared.section} aria-labelledby="acc-lib">
        <div className={shared.sectionHead}>
          <div>
            <h2 className={shared.libraryTitle} id="acc-lib">
              مكتبتي التعليمية
            </h2>
            <p className={shared.meta}>لا ملفات بعد</p>
          </div>
        </div>
        {/*
         * The design's search field and format filters belong to the populated
         * grid. With nothing to search they would be controls that cannot act,
         * so they are removed until there is a library to filter.
         */}
        <EmptyCard
          art={EMPTY_ART.library}
          title="مكتبتك التعليمية بانتظار أول إضافة."
          body="عند شراء أول ملف سيظهر هنا مع روابط تحميله. يمكنك أيضًا فتح ملفات طلب سابق برابطه."
          action={{ href: routes.stages, label: 'تصفّح المراحل الدراسية' }}
        />
      </section>

      <NotConnectedPanel />
    </>
  );
}
