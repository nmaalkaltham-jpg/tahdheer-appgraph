/**
 * رأس أقسام الرئيسة (عين · عنوان · رابط «الكل») — ملفٌّ مستقلّ بلا تبعيّات
 * خادم، فيرسمه `design.tsx` على الخادم وجزيرة «كل الملفّات» على المتصفّح
 * (رابطها يتبع المرشّح المختار).
 */
import type { ReactNode } from 'react';
import { DesignIcon } from './designIcons';
import styles from './design.module.css';

export function Head({
  eyebrow,
  title,
  action,
}: {
  eyebrow: string;
  title: ReactNode;
  action?: { label: string; short?: string; href: string };
}) {
  return (
    <div className={styles.head}>
      <div>
        <p className={styles.eyebrow}>{eyebrow}</p>
        <h2 className={styles.h2}>{title}</h2>
      </div>
      {action ? (
        <a className={styles.headLink} href={action.href}>
          {action.short ? (
            <>
              <span className={styles.deskOnly}>{action.label}</span>
              <span className={styles.mobOnly}>{action.short}</span>
            </>
          ) : (
            action.label
          )}
          <DesignIcon name="chevNext" size={14} strokeWidth={2.2} className={styles.deskOnly} />
        </a>
      ) : null}
    </div>
  );
}
