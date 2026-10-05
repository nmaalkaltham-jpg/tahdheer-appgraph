'use client';
// UNIT: WORKSPACE-TABS
// FILE: workspace-tabs.tsx
// STYLE: workspace-tabs.module.css
// PARENT: ADMIN-SHELL
// USES: ADMIN-SESSION, LIB-NAV, LOCALE
// API: none
// API-VIA: ADMIN-SESSION, LIB-NAV
// MAP: docs/PROJECT_MAP.md

/**
 * تبويبات المساحة — الطبقة الثانية من التنقّل بعد ضغط الشريط إلى سبع مساحات،
 * والثالثة داخل الوجهات ذات الأقسام.
 *
 * لماذا وُجدت: الشريط كان يحمل تسعة عشر مدخلًا في ستّ مجموعات، كثيرٌ منها
 * وجوهٌ لعملٍ واحد («منصة SEO» و«ذكاء البحث» و«رسم الروابط» كلّها ظهور).
 * وضغطه إلى سبعة بلا طبقة ثانية كان **سيقطع الوصول** إلى اثنتَي عشرة شاشة
 * مبنيّة وعاملة — وهذا حذفُ قدرة لا تبسيطُ تنقّل.
 *
 * فالقاعدة هنا: **كل مسار كان في الشريط يبقى مقصودًا من تبويب.** أي مدخل
 * يُزال من الأعلى يظهر هنا، وإلّا فقد ضاع. وهي القاعدة نفسها التي فرضت
 * شريطًا ثالثًا حين دُمجت أربعة أسطح في «استوديو التصميم»: نزلت درجةً ولم
 * تختفِ.
 *
 * موضعها في القشرة لا في كل صفحة: `AdminShell` وحده يعرف المسار الحالي ويلفّ
 * كل المساحات، فتعريفٌ واحد يخدم السبع. ولو وُزّعت على الصفحات لتفرّقت
 * التسميات وتقادم بعضها بصمت.
 *
 * ولا يُرسم شريطٌ بخيار واحد: شريطٌ بخيار وحيد ضجيجٌ لا تنقّل.
 */

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useT } from './locale';
import { useSession } from './admin-session';
import { workspaceFor, type NavDestination } from '../lib/nav';
import styles from './workspace-tabs.module.css';

/* التعريف انتقل إلى `lib/nav.ts` — مصدر واحد للشريط والتبويبات والفهرس. */

export function WorkspaceTabs() {
  const pathname = usePathname() ?? '';
  const t = useT();
  const { can } = useSession();
  const match = workspaceFor(pathname);

  if (match === null) return null;

  /*
   * الترشيح بالصلاحية هنا كما في الشريط الجانبي.
   *
   * كان الشريط يعرض كلّ وجهة بلا فحص بينما يفحصها العمود — فيرى من لا يملك
   * `products.read` تبويب «المنتجات» ويصل منه إلى ٤٠٣. ومدخلٌ يُعرض ولا يعمل
   * أسوأ من مدخلٍ محجوب: الأوّل يُعلّم المستعمل ألّا يثق بالشريط.
   */
  const reachable = (items: readonly NavDestination[]) =>
    items.filter((d) => d.permission === undefined || can(d.permission));

  const top = reachable(match.ws.destinations);
  if (top.length < 2) return null;

  return (
    <nav className={styles.tabs} aria-label={`${t('أقسام')} ${t(match.ws.label)}`}>
      {top.map((tab) => (
        <Link
          key={tab.href}
          className={styles.tab}
          href={tab.href}
          aria-current={tab.href === match.active ? 'page' : undefined}
        >
          {t(tab.label)}
        </Link>
      ))}
    </nav>
  );
}
