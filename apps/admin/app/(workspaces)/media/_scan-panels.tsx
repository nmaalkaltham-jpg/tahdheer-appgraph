'use client';
// UNIT: _SCAN-PANELS
// FILE: _scan-panels.tsx
// STYLE: media-library.module.css
// PARENT: LIBRARY-PANEL
// USES: ASSET-LIBRARY, LIB-ADMIN-API, LIB-FORMAT, MEDIA-DATA, MEDIA-ICONS, MEDIA-STATE
// API: /admin/media
// MAP: docs/PROJECT_MAP.md

import { AssetsView, bytes } from './asset-library';
import type { AssetLibrary } from '../../../lib/admin-api';
import { count } from '../../../lib/format';
import { MISSING, duplicateAssets } from './media-data';
import { MediaIcon } from './media-icons';
import { HonestPanel } from './media-state';
import styles from './media-library.module.css';

/* ───── وضع «المكرّرة»: تكرار مُثبَت من صفوف الأصول ───── */

/**
 * سقف `perPage` في الخادم مئة (تحقّق `MediaLibraryController::index`)، فما
 * يُفحص هنا أحدث مئة أصل لا المكتبة كاملة. الفرق يُقال في اللوح ولا يُبتلع:
 * «لا تكرار» في مئةٍ ليس «لا تكرار» في المكتبة.
 */
const SCAN_LIMIT = 100;
const SCAN_PATH = `/admin/media?perPage=${SCAN_LIMIT}`;

export function DuplicateGroups() {
  return (
    <AssetsView<AssetLibrary> path={SCAN_PATH}>
      {(page) => {
        const groups = duplicateAssets(page.data);
        const partial = page.meta.total > page.data.length;

        return (
          <>
            <HonestPanel
              state={partial ? 'INSUFFICIENT_DATA' : 'NO_DATA'}
              title={
                partial
                  ? 'الفحص شمل أحدث مئة أصل، لا المكتبة كاملة'
                  : 'الفحص شمل كلّ أصلٍ في المكتبة'
              }
            >
              <p>
                التطابق هنا على <strong>بصمة المحتوى</strong> و<strong>حجم الملف بالبايت</strong> معًا، وكلاهما
                يأتي من <bdi dir="ltr">GET /api/v1/admin/media</bdi>. الاسم لا يدخل الحساب: المتطابق لا يُثبت
                تكرارًا والمختلف لا ينفيه.
              </p>
              <p>
                والبصمة تخرج من الخادم <strong>مقتطعة</strong> إلى اثني عشر محرفًا عمدًا. مع تطابق الحجم
                بالبايت فهذا دليل عمليّ قاطع، لا مساواة رياضيّة مُبرهَنة.
              </p>
              {partial ? (
                <p>
                  المكتبة فيها <bdi dir="ltr">{count(page.meta.total)}</bdi> أصلًا، وقُرئ منها{' '}
                  <bdi dir="ltr">{count(page.data.length)}</bdi>. ما لم يُقرأ لم يُفحص.
                </p>
              ) : null}
            </HonestPanel>

            {groups.length === 0 ? (
              <HonestPanel state="NO_DATA" title="لا أصلَين متطابقَين في المفحوص">
                <p>لا بصمة تتكرّر بالحجم نفسه في الصفوف المقروءة. هذه نتيجة قياس، لا تعذّر.</p>
              </HonestPanel>
            ) : (
              <div className={styles.groups}>
                {groups.map((group) => (
                  <section className={styles.group} key={group.key}>
                    <div className={styles.groupHead}>
                      <MediaIcon name="copy" className={styles.groupIcon} />
                      <h3 className={styles.groupTitle}>
                        بصمة وحجم متطابقان — <bdi dir="ltr">{group.fingerprint}</bdi> ·{' '}
                        {bytes(group.sizeBytes)}
                      </h3>
                      <span className={styles.groupChip}>
                        {bytes(group.wastedBytes)} يتحرّر بإبقاء نسخة
                      </span>
                    </div>
                    <div className={styles.groupBody}>
                      {group.rows.map((row) => (
                        <div className={styles.groupFile} key={row.id}>
                          <span className={styles.keepChip}>{row.orphan ? 'يتيم' : 'مستعمَل'}</span>
                          <bdi dir="ltr" className={styles.groupName}>
                            {row.name}
                          </bdi>
                          <span className={styles.groupUsage}>
                            {row.orphan ? (
                              'لا يستعمله منتج'
                            ) : (
                              <>
                                يستعمله <bdi dir="ltr">{count(row.usedBy)}</bdi>
                              </>
                            )}
                          </span>
                          <span className={styles.groupSize}>{bytes(row.sizeBytes)}</span>
                        </div>
                      ))}
                      <p className={styles.groupNote}>
                        لا يُوسَم صفٌّ بـ«تُبقى» وآخر بـ«تُحذف»: الاختيار قرار مشغّل، ولا مسار حذفٍ في المكتبة
                        أصلًا (<bdi dir="ltr">{MISSING.remove}</bdi>). ما يُعرض هو الحقيقة المقيسة وحدها.
                      </p>
                    </div>
                  </section>
                ))}
              </div>
            )}
          </>
        );
      }}
    </AssetsView>
  );
}

/* ───── وضع «اليتيمة»: مقروء من الخادم، ولا حذف جماعي ───── */

export function OrphanAssets() {
  return (
    <AssetsView<AssetLibrary> path={`/admin/media?usage=orphan&perPage=${SCAN_LIMIT}`}>
      {(page) => (
        <>
          <HonestPanel state="NO_DATA" title="اليتيم يُقاس على الخادم لا يُشتقّ هنا">
            <p>
              الترشيح <bdi dir="ltr">?usage=orphan</bdi> يُنفَّذ على{' '}
              <bdi dir="ltr">whereDoesntHave(&apos;productFiles&apos;)</bdi>: صفوف الاستعمال هي الحقيقة، لا
              عدّاد <bdi dir="ltr">usage_count</bdi> الذي قد ينحرف.
            </p>
            <p>
              ويبقى الكشف <strong>قابلًا للخطأ</strong> بطبيعته: قالب بريد أو ودجة أو قاعدة CSS قد يشير إلى
              ملف لا يفحصه المسح. لهذا لا يوجد في هذه الشاشة زرّ «حذف كل اليتيمة» — لا معطَّلًا ولا مخفيًا.
              غيابه قاعدة لا نقص، ولا مسار حذفٍ في المكتبة أصلًا (<bdi dir="ltr">{MISSING.remove}</bdi>).
            </p>
          </HonestPanel>

          <p className={styles.scope}>
            <MediaIcon name="info" className={styles.noteIcon} />
            <span className={styles.noteText}>
              <bdi dir="ltr">{count(page.summary.orphans)}</bdi> أصلًا يتيمًا في المكتبة تشغل{' '}
              {bytes(page.summary.orphanBytes)}. المعروض أدناه{' '}
              <bdi dir="ltr">{count(page.data.length)}</bdi> منها — سقف الصفحة مئة.
            </span>
          </p>

          {page.data.length === 0 ? (
            <HonestPanel state="NO_DATA" title="لا أصل يتيم">
              <p>كلّ أصل مسجَّل يستعمله منتج واحد على الأقلّ. هذه نتيجة قياس، لا تعذّر.</p>
            </HonestPanel>
          ) : (
            <div className={styles.groups}>
              <section className={styles.group}>
                <div className={styles.groupBody}>
                  {page.data.map((row) => (
                    <div className={styles.groupFile} key={row.id}>
                      <span className={styles.keepChip}>يتيم</span>
                      <bdi dir="ltr" className={styles.groupName}>
                        {row.name}
                      </bdi>
                      <span className={styles.groupUsage}>
                        <bdi dir="ltr">{row.mime}</bdi>
                      </span>
                      <span className={styles.groupSize}>{bytes(row.sizeBytes)}</span>
                    </div>
                  ))}
                </div>
              </section>
            </div>
          )}
        </>
      )}
    </AssetsView>
  );
}
