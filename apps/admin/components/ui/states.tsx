'use client';
// UNIT: STATES
// FILE: states.tsx
// STYLE: states.module.css
// PARENT: DATA-TABLE, INSIGHT-PANEL, INTEGRATIONS-PANEL, PAGE-DASHBOARD, PAGE-SYSTEM-AI, PRODUCT-COPILOT +1
// USES: COMPONENTS-ICONS
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * حالات الواجهة المشتركة — هيكلٌ وفراغٌ وعطلٌ ومنعُ صلاحية.
 *
 * ─── لماذا مكوّنٌ واحد لا نسخةٌ في كلّ صفحة ───
 *
 * كانت كلّ شاشةٍ تكتب فراغها وعطلها بيدها، فاختلفت في التفاصيل التي تهمّ:
 * `role="alert"` ناقصٌ هنا، زرّ «أعد المحاولة» باقٍ على ٤٠٣ هناك — وزرُّ
 * إعادةٍ على «ليس لك» يَعِد بما لن يقع مهما ضُغط.
 *
 * ─── والهيكل يطابق شكل المحتوى ───
 *
 * مستطيلٌ رماديّ واحد في مكان جدولٍ من عشرة صفوف يجعل الصفحة تقفز حين تصل
 * البيانات. الهيكل هنا يأخذ عدد الصفوف والأعمدة فيحجز المساحة نفسها.
 */

import Link from 'next/link';
import { Icon, type IconName } from '../icons';
import styles from './states.module.css';

/**
 * هيكل تحميلٍ بشكل المحتوى — لا مستطيلٌ واحد مكان جدول.
 *
 * ─── ولماذا صيغتان ───
 *
 * `block` هي الافتراض وما تستعمله البطاقات واللوحات: أسطرٌ حرّة بلا إطار.
 *
 * و`table` تحجز هندسة جدول القوائم **بالضبط**: إطارٌ وزاويةٌ ورأسٌ معتم،
 * وحشوُ صفٍّ يقرأ `--d-cell-y` نفسه الذي يقرؤه الجدول، وارتفاعُ خليّةٍ
 * يساوي سطر الخليّة الحقيقيّ. الهيكل الحرّ كان أقصر من الصفّ بستّة بكسلات،
 * فكانت الصفحة تنزلق تحت المؤشّر لحظةَ وصول البيانات.
 */
export function Skeleton({
  rows = 3,
  columns = 1,
  label = 'جارٍ التحميل…',
  variant = 'block',
}: {
  rows?: number;
  columns?: number;
  label?: string;
  variant?: 'block' | 'table';
}) {
  if (variant === 'table') {
    return (
      <div className={styles.tableSkeleton} role="status" aria-live="polite" aria-busy="true">
        <span className="sr-only">{label}</span>
        <div className={styles.tableSkeletonHead}>
          {Array.from({ length: columns }, (_, col) => (
            <span key={col} className={styles.skeletonCell} />
          ))}
        </div>
        {Array.from({ length: rows }, (_, row) => (
          <div key={row} className={styles.tableSkeletonRow}>
            {Array.from({ length: columns }, (_, col) => (
              <span key={col} className={styles.skeletonCell} />
            ))}
          </div>
        ))}
      </div>
    );
  }

  return (
    <div className={styles.skeleton} role="status" aria-live="polite" aria-busy="true">
      <span className="sr-only">{label}</span>
      {Array.from({ length: rows }, (_, row) => (
        <div key={row} className={styles.skeletonRow}>
          {Array.from({ length: columns }, (_, col) => (
            <span key={col} className={styles.skeletonCell} />
          ))}
        </div>
      ))}
    </div>
  );
}

/**
 * فراغٌ يشرح سببه ويعرض ما يُفعل.
 *
 * «لا بيانات» وحدها تترك المشغّل واقفًا: هل لم يُنشأ شيء بعد، أم أنّ المرشّح
 * ضيّق، أم أنّ المزامنة لم تعمل؟ ثلاثة أسبابٍ وثلاثة أفعالٍ مختلفة.
 */
export function EmptyState({
  icon = 'box',
  title,
  body,
  action,
}: {
  icon?: IconName;
  title: string;
  body: string;
  action?: { label: string; href?: string; onClick?: () => void };
}) {
  return (
    <div className={styles.empty}>
      <span className={styles.emptyIcon} aria-hidden="true">
        <Icon name={icon} size={22} />
      </span>
      <strong className={styles.emptyTitle}>{title}</strong>
      <p className={styles.emptyBody}>{body}</p>
      {action !== undefined ? (
        action.href !== undefined ? (
          <Link className="btn btn-ghost" href={action.href}>
            {action.label}
          </Link>
        ) : (
          <button type="button" className="btn btn-ghost" onClick={action.onClick}>
            {action.label}
          </button>
        )
      ) : null}
    </div>
  );
}

/**
 * عطلٌ — برسالة الخادم بعد تنقيتها، وبزرّ إعادةٍ حين تُفيد الإعادة وحدها.
 *
 * ٤٠٣ و٤٠٤ لا يُعاد عليهما: الأوّل يقول «ليس لك» والثاني «لم يعد موجودًا»،
 * وكلاهما لا يتغيّر بالضغط.
 */
export function ErrorState({
  message,
  code,
  onRetry,
}: {
  message: string;
  code?: number;
  onRetry?: () => void;
}) {
  const retryable = code !== 403 && code !== 404 && onRetry !== undefined;
  const title = code === 403 ? 'لا صلاحية' : code === 404 ? 'غير موجود' : 'تعذّر الجلب';

  return (
    <div className={styles.error} role="alert">
      <span className={styles.errorIcon} aria-hidden="true">
        <Icon name="alert" size={20} />
      </span>
      <div className={styles.errorText}>
        <strong>{title}</strong>
        <p>{message}</p>
      </div>
      {retryable ? (
        <button type="button" className="btn btn-ghost" onClick={onRetry}>
          أعد المحاولة
        </button>
      ) : null}
    </div>
  );
}
