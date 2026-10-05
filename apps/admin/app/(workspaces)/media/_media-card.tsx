'use client';
// UNIT: _MEDIA-CARD
// FILE: _media-card.tsx
// STYLE: media-library.module.css
// PARENT: LIBRARY-PANEL
// USES: LIB-FORMAT, MEDIA-DATA, MEDIA-ICONS, MEDIA-STATE
// API: none
// MAP: docs/PROJECT_MAP.md

import { useState } from 'react';
import { count } from '../../../lib/format';
import { dimensionsOf, type MediaFile } from './media-data';
import { MediaIcon } from './media-icons';
import { HonestPanel } from './media-state';
import styles from './media-library.module.css';

/* ───── الشارة: مشتقّة من الصيغة وحدها، وهي المشتقّ الموثوق الوحيد ───── */

function badgeOf(file: MediaFile): { label: string; className: string } {
  if (file.format === 'modern') return { label: 'صيغة حديثة', className: styles.badgeOk ?? '' };
  if (file.format === 'vector') return { label: 'متجهي', className: styles.badgeNeutral ?? '' };
  if (file.format === 'legacy') return { label: 'صيغة قديمة', className: styles.badgeWarn ?? '' };
  return { label: 'صيغة مجهولة', className: styles.badgeBad ?? '' };
}

function UsagePhrase({ file }: { file: MediaFile }) {
  const n = file.references.length;
  if (n === 1) return <>غلاف منتج واحد</>;
  if (n === 2) return <>غلاف منتجَين</>;
  return (
    <>
      غلاف <bdi dir="ltr">{count(n)}</bdi> {n <= 10 ? 'منتجات' : 'منتجًا'}
    </>
  );
}

/* ───── بطاقة الشبكة ───── */

export function MediaCard({
  file,
  compact,
  onInspect,
}: {
  file: MediaFile;
  compact: boolean;
  onInspect: (file: MediaFile) => void;
}) {
  const [failed, setFailed] = useState(false);
  const badge = badgeOf(file);
  const dim = dimensionsOf(file);

  return (
    <button type="button" className={styles.card} onClick={() => onInspect(file)}>
      <span className={styles.cardMedia}>
        <Hatch />
        {failed ? (
          /* الاتجاه مشتقّ من المحتوى: الأبعاد لاتينية وبديلها الغائب عربي. */
          dim ? (
            <bdi dir="ltr" className={styles.dim}>
              {dim}
            </bdi>
          ) : (
            <span dir="rtl" className={`${styles.dim} ${styles.dimText}`}>
              أبعاد غير مخزَّنة
            </span>
          )
        ) : (
          /* الأبعاد المخزَّنة تحجز الصندوق فلا يقفز التخطيط أثناء التحميل. */
          // eslint-disable-next-line @next/next/no-img-element -- وسائط مرفوعة من أصل خارجي بلا أبعاد مضمونة؛ next/image يتطلّب remotePatterns وأبعادًا ثابتة
          <img
            className={styles.thumb}
            src={file.url}
            alt=""
            width={file.width ?? undefined}
            height={file.height ?? undefined}
            loading="lazy"
            decoding="async"
            onError={() => setFailed(true)}
          />
        )}
        <span className={`${styles.badge} ${badge.className}`}>{badge.label}</span>
      </span>
      <span className={`${styles.meta} ${compact ? styles.metaHidden : ''}`}>
        <bdi dir="ltr" className={styles.metaName}>
          {file.name}
        </bdi>
        <span className={styles.metaUsage}>
          <UsagePhrase file={file} />
        </span>
      </span>
    </button>
  );
}

/**
 * خلفية البطاقة: خطوط مائلة ٨×٨ بزاوية ٤٥° كما ترسمها لوحة التصميم — بديل
 * الصورة لا تزيينها. النمط يُعرَّف مرة واحدة للشبكة كلها فلا تتكرّر المعرّفات.
 */
const HATCH_ID = 'ml-hatch';

export function HatchDefs() {
  return (
    <svg aria-hidden="true" focusable="false" style={{ position: 'absolute', inlineSize: 0, blockSize: 0 }}>
      <defs>
        <pattern id={HATCH_ID} width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
          {/* لون الخطّ يتبع --ml-border: السمة stroke لا تقبل var()، وخاصية style تقبلها. */}
          <line x1="0" y1="0" x2="0" y2="8" style={{ stroke: 'var(--ml-border)' }} strokeWidth="2.5" />
        </pattern>
      </defs>
    </svg>
  );
}

function Hatch() {
  return (
    <svg className={styles.hatch} aria-hidden="true" focusable="false">
      <rect width="100%" height="100%" fill={`url(#${HATCH_ID})`} />
    </svg>
  );
}

/* ───── الصفوف والجدول ───── */

export function MediaRows({
  files,
  table,
  onInspect,
}: {
  files: MediaFile[];
  table: boolean;
  onInspect: (file: MediaFile) => void;
}) {
  const columns = table
    ? [
        { label: 'الأبعاد', width: '74px' },
        { label: 'الحجم', width: '58px' },
        { label: 'نصّ بديل', width: '52px' },
        { label: 'الصيغة', width: '54px' },
      ]
    : [
        { label: 'الأبعاد', width: '74px' },
        { label: 'الحجم', width: '58px' },
        { label: 'الحالة', width: '86px' },
      ];

  return (
    <>
      <div className={styles.rows}>
        <div className={styles.rowHead} aria-hidden="true">
          <span className={styles.rowHeadFile}>الملف</span>
          {columns.map((column) => (
            <span className={styles.rowHeadCell} key={column.label} style={{ flex: `0 0 ${column.width}` }}>
              {column.label}
            </span>
          ))}
          <span className={styles.rowHeadSpacer} />
        </div>

        {files.map((file) => {
          const badge = badgeOf(file);
          const dim = dimensionsOf(file);
          return (
            <div className={styles.row} key={file.url}>
              <span className={styles.rowFile}>
                <span aria-hidden="true" className={styles.rowTile}>
                  <MediaIcon name="files" className={styles.rowTileIcon} />
                </span>
                <span className={styles.rowText}>
                  <bdi dir="ltr" className={styles.rowName}>
                    {file.name}
                  </bdi>
                  <span className={styles.rowUsage}>
                    <UsagePhrase file={file} />
                  </span>
                </span>
              </span>

              <span
                dir={dim ? 'ltr' : 'rtl'}
                className={`${styles.cell} ${dim ? '' : styles.cellAbsent}`}
                style={{ flex: '0 0 74px' }}
              >
                {dim ?? 'غير مخزَّنة'}
              </span>
              <span className={`${styles.cell} ${styles.cellAbsent}`} style={{ flex: '0 0 58px' }}>
                —
              </span>

              {table ? (
                <>
                  <span className={`${styles.cell} ${styles.cellAbsent}`} style={{ flex: '0 0 52px' }}>
                    غير محدَّد
                  </span>
                  <span dir="ltr" className={styles.cell} style={{ flex: '0 0 54px' }}>
                    {file.ext === '' ? '—' : file.ext.toUpperCase()}
                  </span>
                </>
              ) : (
                <span
                  className={`${styles.cellState} ${
                    file.format === 'modern' ? styles.cellOk : file.format === 'legacy' ? styles.cellWarn : ''
                  }`}
                  style={{ flex: '0 0 86px' }}
                >
                  {badge.label}
                </span>
              )}

              <button type="button" className={styles.inspectBtn} onClick={() => onInspect(file)}>
                افحص
              </button>
            </div>
          );
        })}
      </div>

      <HonestPanel state="INSUFFICIENT_DATA" title="عمودان لا يمكن ملؤهما من هذا المصدر">
        <p>
          <strong>الحجم:</strong> فارغ في كل صفّ. الحجم بالبايت موجود ومقروء في «مكتبة الأصول» أعلى الشاشة، لكنّ
          صفّ الغلاف الذي تُبنى منه هذه القائمة لا يحمل معرّف الأصل، فلا مفتاح يصل بينهما. الصفر هنا كذب لا
          قياس.
        </p>
        <p>
          <strong>نصّ بديل:</strong> «غير محدَّد» لا «ناقص». الواجهة العامة تخدم{' '}
          <bdi dir="ltr">alt_ar</bdi> أو عنوان المنتج بديلًا عنه، فالنصّ المُعاد لا يفرّق بين المكتوب
          والمُستبدَل. الحكم يحتاج قراءة العمود نفسه.
        </p>
      </HonestPanel>
    </>
  );
}
