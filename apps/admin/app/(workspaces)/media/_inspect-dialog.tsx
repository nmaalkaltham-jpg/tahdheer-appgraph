'use client';
// UNIT: _INSPECT-DIALOG
// FILE: _inspect-dialog.tsx
// STYLE: media-library.module.css
// PARENT: LIBRARY-PANEL
// USES: MEDIA-DATA, MEDIA-ICONS, MEDIA-STATE
// API: none
// MAP: docs/PROJECT_MAP.md

import Link from 'next/link';
import { useCallback, useEffect, useRef, useState } from 'react';
import { MISSING, dimensionsOf, type MediaFile } from './media-data';
import { MediaIcon } from './media-icons';
import { HonestTag, LockedReason } from './media-state';
import styles from './media-library.module.css';

/* ───── نافذة الفحص: مواضع الاستخدام أولًا، ثمّ حذف مقفل بسببه ───── */

export function InspectDialog({ file, onClose }: { file: MediaFile; onClose: () => void }) {
  const dialogRef = useRef<HTMLDivElement>(null);
  const [failed, setFailed] = useState(false);
  const dim = dimensionsOf(file);

  const focusables = useCallback((): HTMLElement[] => {
    const node = dialogRef.current;
    if (!node) return [];
    const list = node.querySelectorAll<HTMLElement>('a[href], button, input, select, textarea, [tabindex]');
    return [...list].filter((element) => !element.hasAttribute('disabled') && element.tabIndex !== -1);
  }, []);

  useEffect(() => {
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    focusables()[0]?.focus();

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.preventDefault();
        onClose();
        return;
      }
      if (event.key !== 'Tab') return;
      const list = focusables();
      const first = list[0];
      const last = list[list.length - 1];
      if (!first || !last) {
        event.preventDefault();
        return;
      }
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', onKeyDown, true);
    return () => {
      document.removeEventListener('keydown', onKeyDown, true);
      opener?.focus();
    };
  }, [focusables, onClose]);

  return (
    <div
      className={styles.backdrop}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <div
        className={styles.dialog}
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="ml-inspect-title"
      >
        <div className={styles.dialogHead}>
          <h2 className={styles.dialogTitle} id="ml-inspect-title">
            فحص الملف
          </h2>
          <button type="button" className={styles.dialogClose} onClick={onClose} aria-label="إغلاق نافذة الفحص">
            <MediaIcon name="close" className={styles.dialogCloseIcon} />
          </button>
        </div>

        <div className={styles.preview}>
          {failed ? (
            <p className={styles.previewFail}>
              تعذّر تحميل المعاينة من هذا المسار. المسار كما تعيده الواجهة قد يكون نسبيًا إلى أصل المتجر، وأصل
              اللوحة يختلف عنه — وهذا وصف لما حدث، لا حكم على الملف.
            </p>
          ) : (
            // eslint-disable-next-line @next/next/no-img-element -- معاينة ملف مرفوع من أصل خارجي؛ next/image يتطلّب remotePatterns وأبعادًا ثابتة
            <img
              className={styles.previewImg}
              src={file.url}
              alt={`معاينة ${file.name}`}
              width={file.width ?? undefined}
              height={file.height ?? undefined}
              onError={() => setFailed(true)}
            />
          )}
        </div>

        <div className={styles.facts}>
          <div className={styles.fact}>
            <span className={styles.factLabel}>المسار</span>
            <bdi dir="ltr" className={styles.factValue}>
              {file.url}
            </bdi>
          </div>
          <div className={styles.fact}>
            <span className={styles.factLabel}>الأبعاد</span>
            {dim ? (
              <bdi dir="ltr" className={styles.factValue}>
                {dim}
              </bdi>
            ) : (
              <HonestTag state="NO_DATA" note="لا أبعاد مخزَّنة لهذا الصف" />
            )}
          </div>
          <div className={styles.fact}>
            <span className={styles.factLabel}>الصيغة</span>
            <bdi dir="ltr" className={styles.factValue}>
              {file.ext === '' ? '—' : file.ext.toUpperCase()}
            </bdi>
          </div>
          <div className={styles.fact}>
            <span className={styles.factLabel}>حجم الملف وبصمته</span>
            <HonestTag state="INSUFFICIENT_DATA" />
            <span className={styles.factNote}>
              كلاهما مقروء في «مكتبة الأصول» أعلى الشاشة من{' '}
              <bdi dir="ltr">GET /api/v1/admin/media</bdi>. لكنّ صفّ الغلاف هنا لا يحمل معرّف الأصل، فلا
              مفتاح يربطه بصفّه هناك — والربط بالاسم ليس ربطًا. ابحث عن الملفّ بالاسم في اللوح أعلاه.
            </span>
          </div>
          <div className={styles.fact}>
            <span className={styles.factLabel}>النصّ المخدوم</span>
            <span className={`${styles.factValue} ${styles.factValueText}`}>{file.servedAlt}</span>
            <span className={styles.factNote}>
              الواجهة العامة تخدم <bdi dir="ltr">alt_ar</bdi> أو عنوان المنتج بديلًا عنه، فلا يُعرف من هنا
              أهو نصّ بديل مكتوب أم عنوانٌ حلّ محلّه.
            </span>
          </div>
        </div>

        <h3 className={styles.sectionTitle}>مواضع الاستخدام المعروفة</h3>
        <ul className={styles.refs}>
          {file.references.map((reference) => (
            <li className={styles.refItem} key={reference.productId}>
              <Link href={`/studio/catalog/${reference.productId}`}>{reference.title}</Link>
              <bdi dir="ltr" className={styles.refSku}>
                {reference.sku}
              </bdi>
              <span>غلاف المنتج</span>
            </li>
          ))}
        </ul>
        <p className={styles.groupNote}>
          هذه المواضع مُثبَتة، وليست كل المواضع: المفحوص هو أغلفة المنتجات المنشورة وحدها. صور المعرض
          والصفحات والمقالات وقوالب البريد والودجات وقواعد CSS خارج هذا المسح.
        </p>

        <section className={styles.deleteBox} aria-labelledby="ml-delete-title">
          <h3 className={styles.sectionTitle} id="ml-delete-title">
            الحذف
          </h3>
          <ol className={styles.deleteSteps}>
            <li>تُعرض كل مواضع الاستخدام المعروفة أعلاه ويُفكّ ارتباطها أولًا.</li>
            <li>يُكتب عدد الملفات المطلوب حذفها تأكيدًا — لا زرّ تأكيد بنقرة واحدة.</li>
            <li>يُسجَّل الحذف في سجلّ التغييرات ويبقى الرجوع ممكنًا.</li>
          </ol>
          <label className={styles.deleteField} htmlFor="ml-delete-confirm">
            <span>
              اكتب العدد <bdi dir="ltr">1</bdi> لتأكيد حذف هذا الملف
            </span>
            <input
              id="ml-delete-confirm"
              type="text"
              inputMode="numeric"
              disabled
              aria-describedby="ml-delete-reason"
              placeholder="مقفل"
            />
          </label>
          <button type="button" className={styles.deleteBtn} disabled aria-describedby="ml-delete-reason">
            حذف الملف
          </button>
          <LockedReason id="ml-delete-reason">
            مقفل لأن النقطة غير موجودة: <bdi dir="ltr">{MISSING.remove}</bdi>. لن يُفتح هذا الحقل قبل أن
            تُنفَّذ النقطة ومعها فكّ الارتباط والتسجيل والرجوع.
          </LockedReason>
        </section>
      </div>
    </div>
  );
}
