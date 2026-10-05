'use client';
// UNIT: OS-KIT
// FILE: os-kit.tsx
// STYLE: os.module.css
// PARENT: LAYOUT-ANALYTICS-OS, PAGE-ANALYTICS-OS, PAGE-ANALYTICS-OS-ACTIONS
// USES: LEVEL, SCREENS
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * عُدّة Analytics Pro OS المشتركة: الكروم الداكن (الشعار + تبويبات الشاشات
 * السبع كروابط حقيقية) وشريط مستوى التنفيذ وشريحة المصدر.
 *
 * التبويبات تنقّل بين مسارات فعلية تحت ‎/analytics/os‏ — لا حالة شاشة
 * مخفية؛ والمستوى حالة متصفّح معلَنة (انظر ‎_lib/level.tsx‏).
 */

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import type { ReactNode } from 'react';
import styles from '../os.module.css';
import { OS_LEVELS, useOsLevel } from '../_lib/level';
import { OS_SCREENS } from '../_lib/screens';

/** مسارات الأيقونات حرفيًا من رموز اللوح (a4-*). */
const ICON_PATHS: Record<string, ReactNode> = {
  loop: (
    <>
      <path d="M4 12a8 8 0 1 0 3-6.2" />
      <path d="M4 4v5h5" />
    </>
  ),
  check: <path d="M5 12.5l4.5 4.5L19 7" />,
  eye: (
    <>
      <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" />
      <circle cx="12" cy="12" r="3" />
    </>
  ),
  warn: (
    <>
      <path d="M12 4l9 16H3z" />
      <path d="M12 10v5M12 17.6v.1" />
    </>
  ),
  bolt: <path d="M13 3L5 14h5l-1 7 8-11h-5z" />,
  shield: (
    <>
      <path d="M12 3l8 3v6c0 5-3.4 8.5-8 9.9C7.4 20.5 4 17 4 12V6z" />
      <path d="M8.8 12.3l2.2 2.2 4.2-4.4" />
    </>
  ),
};

export type OsIconName = 'loop' | 'check' | 'eye' | 'warn' | 'bolt' | 'shield';

export function OsIcon({ name }: { name: OsIconName }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.9}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {ICON_PATHS[name]}
    </svg>
  );
}

/** شاشات النظام السبع — أسماؤها حرفيًا من اللوح، ومساراتها حقيقية. */

export function OsChrome({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const { level, setLevel } = useOsLevel();
  const active = OS_LEVELS.find((spec) => spec.key === level) ?? OS_LEVELS[3]!;

  return (
    <div className={styles.os}>
      <header className={styles.chrome}>
        <div className={styles.chromeRow}>
          <span className={styles.brand}>
            <span className={styles.brandTile} aria-hidden="true">
              <OsIcon name="loop" />
            </span>
            <b>Analytics Pro — نظام تشغيل نمو البحث</b>
          </span>
          <span className={styles.goldBadge}>
            طبقة تنفيذية فوق المحرك الحالي — لا شيء أُزيل
          </span>
          <span className={styles.chromeSpacer} />
          <nav className={styles.tabs} aria-label="شاشات Analytics Pro OS">
            {OS_SCREENS.map((screen) => {
              const current =
                screen.href === '/analytics/os'
                  ? pathname === screen.href
                  : pathname === screen.href || pathname.startsWith(`${screen.href}/`);
              return (
                <Link
                  key={screen.key}
                  href={screen.href}
                  className={`${styles.tab} ${current ? styles.tabOn : ''}`}
                  aria-current={current ? 'page' : undefined}
                >
                  {screen.label}
                </Link>
              );
            })}
          </nav>
        </div>
        <div className={styles.levelBar}>
          <div className={styles.levelRow} role="group" aria-label="مستوى التنفيذ">
            <span className={styles.levelLabel}>مستوى التنفيذ:</span>
            {OS_LEVELS.map((spec) => (
              <button
                key={spec.key}
                type="button"
                aria-pressed={level === spec.key}
                title={spec.hint}
                className={`${styles.levelBtn} ${level === spec.key ? styles.levelOn : ''}`}
                onClick={() => setLevel(spec.key)}
              >
                {spec.name}
              </button>
            ))}
            <span className={styles.chromeSpacer} />
            <span className={styles.levelHint}>{active.hint}</span>
            <span className={styles.levelLocal}>
              يُحفظ المستوى في هذا المتصفّح وحده، ولا يشغّل منفّذًا خلفيًا — لا مشغّل
              أتمتة في النظام؛ أثره حصر أزرار التنفيذ اليدوي في مركز الإجراءات.
            </span>
          </div>
        </div>
      </header>
      {/* لا <main> هنا: كروم اللوحة يملك معلم main الوحيد (canvas). */}
      <div className={styles.main}>{children}</div>
    </div>
  );
}

/** شريحة مصدر رقم: اسم نقطة النهاية أو الجدول الذي جاء منه. */
export function SourceChip({ children }: { children: ReactNode }) {
  return (
    <span className={styles.srcChip}>
      <bdi dir="ltr">{children}</bdi>
    </span>
  );
}
