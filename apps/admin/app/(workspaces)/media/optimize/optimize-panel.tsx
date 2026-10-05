'use client';
// UNIT: OPTIMIZE-PANEL
// FILE: optimize-panel.tsx
// STYLE: image-ops.module.css
// PARENT: PAGE-MEDIA
// USES: LIB-FORMAT, MEDIA-DATA, MEDIA-ICONS, MEDIA-STATE, RESOURCE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * تحسين الصور — «Tahdheer - Image Ops.dc.html».
 *
 * لا نقطة تشغيل ولا نقطة قياس ولا سجلّ في الواجهة الخلفية بعد. فالشاشة تُبنى
 * كاملة، ويُملأ منها ما يُشتقّ من مصدر حقيقي — امتدادات أغلفة المنتجات
 * المنشورة عبر GET /api/v1/products — ويُعلَن الباقي بحالة صريحة (MIP §1.3).
 *
 * أعمدة «الحالي» و«المتوقّع» و«التوفير» فارغة عمدًا: لا نقطة نهاية تعيد حجمًا
 * بالبايت، وحساب «المتوقّع» بنسبة ثابتة اختلاق. الفراغ المُعلَن أصدق من رقم.
 *
 * وأربع قواعد تصميم تحملها الشاشة قبل أن تُوصَل:
 *   · المعاينة هي الوضع الافتراضي، وهي هنا مقفلة عليه لأن لا تنفيذ ممكنًا.
 *   · الأصل لا يُمسّ، والاسترجاع يقوم عليه.
 *   · لكل صفّ زرّ مفرد يحمل لقب حالته كما تعرّفه اللوحة — «تفاصيل» أو
 *     «السبب» — معطَّلًا معلَن السبب، و«استرجِع» محجوز لصفٍّ حُوِّل فعلًا.
 *   · كل ملف يُتجاوَز يعلن سببه نصًّا؛ والسببان المعروضان مشتقّان من امتداد
 *     الملف الحقيقي لا من قائمة مكتوبة يدويًا.
 */

import { useMemo } from 'react';
import { ResourceView, useResource } from '../../../../components/resource';
import { count } from '../../../../lib/format';
import {
  MISSING,
  PRODUCTS_PATH,
  SOURCE_SCOPE,
  filesFromProducts,
  skipReason,
  type MediaFile,
  type ProductCardPage,
} from '../media-data';
import { MediaIcon, MediaIconSprite } from '../media-icons';
import { HonestPanel, HonestTag, LockedReason } from '../media-state';
import styles from './image-ops.module.css';

/** ضمانة الأصل — مبدأ تشغيلي منقول كما هو من لوحة التصميم. */
const GUARANTEE =
  'التحويل يعمل على المقاسات الفرعية فقط، والملف المرفوع يبقى كما هو على القرص — وعليه يقوم الاسترجاع. ' +
  'لا كتابة فوق أصل ولا فوق نصّ بديل كتبته بيدك.';

/** عقد نقطة التشغيل الغائبة: ستّ قواعد مُلزِمة، لا وصف لسلوك قائم. */
const RUN_CONTRACT: { n: string; text: string }[] = [
  { n: '١', text: 'المعاينة هي الوضع الافتراضي، ولا يبدأ التنفيذ الفعلي إلا بإطفاء صريح لها.' },
  { n: '٢', text: 'الدفعة عشرون صورة، والتقدّم يُحفَظ بعد كل دفعة فيستأنف بعد إغلاق الصفحة.' },
  { n: '٣', text: 'الملف المرفوع لا يُكتب فوقه أبدًا: التحويل على المقاسات الفرعية وحدها.' },
  { n: '٤', text: 'لكل ملف استرجاع مفرد من الأصل المحفوظ، لا استرجاع جماعي وحده.' },
  { n: '٥', text: 'كل ملف يُتجاوَز يعلن سببه نصًّا — التجاوز الصامت أسوأ من الفشل المعلن.' },
  { n: '٦', text: 'المعاينة لا تُسجَّل، والتنفيذ يُسجَّل ملفًا ملفًا فور حدوثه لا في نهايته.' },
];

/** أزرار التشغيل الأربعة كما ترسمها لوحة التصميم — كلها مقفلة بلا نقطة نهاية. */
const RUN_BUTTONS = [
  { id: 'start', label: 'شغّل المعاينة', icon: 'bolt' },
  { id: 'pause', label: 'إيقاف مؤقّت', icon: 'clock' },
  { id: 'resume', label: 'استئناف', icon: 'refresh' },
  { id: 'cancel', label: 'إلغاء', icon: 'close' },
] as const;

export function ImageOpsPanel() {
  const products = useResource<ProductCardPage>(PRODUCTS_PATH);

  return (
    <section className={styles.page}>
      <MediaIconSprite />
      <p className={styles.tagline}>الملف الأصلي لا يُمسّ — التحويل يجري على المقاسات الفرعية وحدها</p>

      <section className={styles.guarantee}>
        <MediaIcon name="shield" className={styles.guaranteeIcon} />
        <span className={styles.guaranteeText}>{GUARANTEE}</span>
        <span className={styles.guaranteeChip}>لا تشغيل بعد</span>
      </section>

      <ResourceView state={products.state} reload={products.reload}>
        {(page) => <OpsBody products={page} />}
      </ResourceView>

      <section className={styles.log} aria-labelledby="io-log-title">
        <div className={styles.logHead}>
          <h2 className={styles.sectionTitle} id="io-log-title">
            سجلّ التغييرات
          </h2>
          <span className={styles.logNote}>فارغ</span>
        </div>
        <div className={styles.logEmpty}>
          <span aria-hidden="true" className={styles.logTile}>
            <MediaIcon name="clock" className={styles.logTileIcon} />
          </span>
          <p className={styles.logEmptyText}>
            لا تغييرات بعد. المعاينة لا تُسجَّل — يبدأ السجلّ عند أول تشغيل فعلي.
          </p>
        </div>
        <HonestPanel state="NOT_CONFIGURED" title="السجلّ فارغ بنيويًا لا مؤقّتًا" missing={[MISSING.optimizeLog]}>
          <p>
            لا نقطة نهاية تُخزّن عمليات التحويل ولا تقرؤها، فلا يمكن أن يمتلئ هذا السجلّ بأي حال. حين تُنفَّذ
            النقطة يجب أن يحمل كل سطر وقتَه واسمَ الملف وفرق الحجم وزرّ استرجاع يعمل.
          </p>
        </HonestPanel>
      </section>
    </section>
  );
}

function OpsBody({ products }: { products: ProductCardPage }) {
  const files = useMemo(() => filesFromProducts(products.data ?? []), [products]);
  const rows = useMemo(() => files.map((file) => ({ file, skip: skipReason(file) })), [files]);
  const convertible = rows.filter((row) => row.skip === null).length;
  const skipped = rows.length - convertible;

  return (
    <>
      <div className={styles.stats}>
        <article className={styles.stat}>
          <div className={styles.statLabel}>أغلفة محمَّلة</div>
          <div className={styles.statRow}>
            <b dir="ltr" className={styles.statValue}>
              {count(files.length)}
            </b>
            <span className={styles.statUnit}>ملفًا</span>
          </div>
          <bdi dir="ltr" className={styles.statSource}>
            GET /api/v1/products
          </bdi>
        </article>

        <article className={styles.stat}>
          <div className={styles.statLabel}>مرشَّحة للتحويل</div>
          <div className={styles.statRow}>
            <b dir="ltr" className={`${styles.statValue} ${styles.statValueOk}`}>
              {count(convertible)}
            </b>
            <span className={styles.statUnit}>ملفًا</span>
          </div>
          <p className={styles.statNote}>بحسب الامتداد وحده: ليست WebP ولا AVIF ولا SVG. ترشيح لا وعد بتوفير.</p>
        </article>

        <article className={styles.stat}>
          <div className={styles.statLabel}>تُتجاوَز</div>
          <div className={styles.statRow}>
            <b dir="ltr" className={`${styles.statValue} ${styles.statValueWarn}`}>
              {count(skipped)}
            </b>
            <span className={styles.statUnit}>ملفًا</span>
          </div>
          <p className={styles.statNote}>WebP أو AVIF أصلًا، أو SVG متجهي، أو بلا امتداد يُقرأ.</p>
        </article>

        <article className={styles.stat}>
          <div className={styles.statLabel}>الحجم الحالي</div>
          <div className={styles.statRow}>
            <HonestTag state="NOT_CONFIGURED" />
          </div>
          <p className={styles.statNote}>
            لا نقطة نهاية تعيد حجم الملف بالبايت. الصفر هنا ليس «صغيرًا» بل «لا قياس».
          </p>
          <bdi dir="ltr" className={styles.statSource}>
            {MISSING.optimizeStats}
          </bdi>
        </article>

        <article className={styles.stat}>
          <div className={styles.statLabel}>التوفير المتوقّع</div>
          <div className={styles.statRow}>
            <HonestTag state="INSUFFICIENT_DATA" />
          </div>
          <p className={styles.statNote}>
            التقدير يُشتقّ من قياس عيّنة فعلية بجودة WebP المضبوطة، لا من نسبة ثابتة. بلا أحجام حالية لا عيّنة
            ولا تقدير.
          </p>
        </article>
      </div>

      <section className={styles.section} aria-labelledby="io-run-title">
        <div className={styles.sectionHead}>
          <h2 className={styles.sectionTitle} id="io-run-title">
            التشغيل
          </h2>
          <button
            type="button"
            className={styles.dryBtn}
            aria-pressed="true"
            disabled
            aria-describedby="io-dry-reason"
          >
            <MediaIcon name="eye" className={styles.dryIcon} />
            معاينة فقط — مفعّلة
          </button>
        </div>

        <div className={styles.runRow}>
          {RUN_BUTTONS.map((button) => (
            <button
              type="button"
              key={button.id}
              className={styles.runBtn}
              disabled
              aria-describedby="io-run-reason"
            >
              <MediaIcon name={button.icon} className={styles.runIcon} />
              {button.label}
            </button>
          ))}
          <div className={styles.spacer} />
          <span className={styles.batchNote}>العقد المطلوب من نقطة التشغيل مذكور أدناه</span>
        </div>

        <div className={styles.progressHead}>
          <span className={styles.progressLabel}>لا معالجة جارية</span>
          <div className={styles.spacer} />
          <span dir="ltr" className={styles.progressCount}>
            0 / {convertible}
          </span>
        </div>
        <div
          role="progressbar"
          aria-valuenow={0}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label="تقدّم المعالجة"
          aria-describedby="io-progress-reason"
          className={styles.progressTrack}
        >
          <span aria-hidden="true" className={styles.progressFill} />
        </div>
        <LockedReason id="io-progress-reason">
          الشريط عند الصفر بنيويًا لا مؤقّتًا: لا معالجة يمكن أن تبدأ لغياب{' '}
          <bdi dir="ltr">{MISSING.optimize}</bdi>، فالعدّاد يقرأ «لم يبدأ شيء» لا «اكتمل شيء».
        </LockedReason>

        <LockedReason id="io-dry-reason">
          «معاينة فقط» مقفلة على التفعيل: إطفاؤها يعني تنفيذًا فعليًا، ولا نقطة تنفيذ موجودة —{' '}
          <bdi dir="ltr">{MISSING.optimize}</bdi>. هذا القفل هو الوضع الافتراضي نفسه لا حيلة عليه.
        </LockedReason>
        <LockedReason id="io-run-reason">
          أزرار التشغيل والإيقاف والاستئناف والإلغاء معطَّلة لغياب{' '}
          <bdi dir="ltr">{MISSING.optimize}</bdi>. لا يوجد زرّ هنا يكتفي بإظهار رسالة.
        </LockedReason>

        <ol className={styles.contract}>
          {RUN_CONTRACT.map((item) => (
            <li className={styles.contractItem} key={item.n}>
              <span aria-hidden="true" className={styles.contractNum}>
                {item.n}
              </span>
              <span className={styles.contractText}>{item.text}</span>
            </li>
          ))}
        </ol>
      </section>

      <section className={styles.tableSection} aria-labelledby="io-table-title">
        <div className={styles.tableHead}>
          <h2 className={styles.sectionTitle} id="io-table-title">
            المعاينة — ما سيحدث
          </h2>
          <span className={styles.tableChip}>لا كتابة على القرص</span>
        </div>

        {rows.length === 0 ? (
          <HonestPanel state="NO_DATA" title="لا ملف واحد في المصدر المتاح">
            <p>
              ردّت <bdi dir="ltr">GET /api/v1/products</bdi> بقائمة بلا أي حقل <bdi dir="ltr">image</bdi>: لا
              منتج منشور يحمل صف غلاف. لا مرشّحات إذًا، وهذا فراغ حقيقي لا فشل.
            </p>
          </HonestPanel>
        ) : (
          <>
            <div className={styles.rows}>
              <div className={styles.rowHead} aria-hidden="true">
                <span className={styles.rowHeadFile}>الملف</span>
                <span className={styles.rowHeadCell} style={{ flex: '0 0 62px' }}>
                  الحالي
                </span>
                <span className={styles.rowHeadCell} style={{ flex: '0 0 62px' }}>
                  المتوقّع
                </span>
                <span className={styles.rowHeadCell} style={{ flex: '0 0 58px' }}>
                  التوفير
                </span>
                <span className={styles.rowHeadCell} style={{ flex: '0 0 96px' }}>
                  الحالة
                </span>
                <span className={styles.rowHeadSpacer} />
              </div>

              {rows.map(({ file, skip }) => (
                <FileRow key={file.url} file={file} skip={skip} />
              ))}
            </div>

            <p className={styles.tableNote}>
              الأعمدة الثلاثة فارغة عن قصد. «الحالي» يحتاج حجم الملف بالبايت ولا نقطة نهاية تعيده،
              و«المتوقّع» يُحسب من جودة WebP المضبوطة على عيّنة فعلية لا من نسبة ثابتة، و«التوفير» فرق بينهما
              فلا يوجد بدونهما. رقم مُصطنع هنا يقود إلى قرار حذف خاطئ.
            </p>

            <LockedReason id="io-restore-reason">
              زرّ الصفّ يحمل لقب حالته كما ترسمه اللوحة، ومعطَّل في الحالتين: «تفاصيل» يحتاج قياسًا لا
              تعيده أي نقطة نهاية، و«السبب» معروض نصًّا تحت الصفّ نفسه فلا يضيف الزرّ شيئًا. أما «استرجِع»
              فمحجوز لصفٍّ حُوِّل فعلًا، ولا تحويل بعد ولا نقطة استرجاع —{' '}
              <bdi dir="ltr">{MISSING.restore}</bdi>. الاسترجاع المفرد يبقى شرطًا في العقد.
            </LockedReason>

            <HonestPanel
              state="NOT_CONFIGURED"
              title="ما يلزم لتشغيل هذه الشاشة فعليًا"
              missing={[MISSING.optimizeStats, MISSING.optimize, MISSING.restore, MISSING.optimizeLog]}
            >
              <p>{SOURCE_SCOPE}</p>
              <p>
                المشتقّ الموثوق الوحيد هنا هو <strong>الصيغة</strong>، لأنها تُقرأ من امتداد المسار الحقيقي.
                وعليها وحدها بُني سببا التجاوز المعروضان أدناه: لم يُكتب أيّهما يدويًا لملف بعينه.
              </p>
            </HonestPanel>
          </>
        )}
      </section>
    </>
  );
}

function FileRow({ file, skip }: { file: MediaFile; skip: string | null }) {
  const references = file.references.length;

  return (
    <div className={`${styles.rowShell} ${skip ? styles.rowShellSkip : ''}`}>
      <div className={styles.row}>
        <span className={styles.rowFile}>
          <span aria-hidden="true" className={styles.rowTile}>
            <MediaIcon name="files" className={styles.rowTileIcon} />
          </span>
          <span className={styles.rowText}>
            <bdi dir="ltr" className={styles.rowName}>
              {file.name}
            </bdi>
            <span className={styles.rowUsage}>
              {references === 1 ? (
                <>غلاف منتج واحد</>
              ) : references === 2 ? (
                <>غلاف منتجَين</>
              ) : (
                <>
                  غلاف <bdi dir="ltr">{count(references)}</bdi> {references <= 10 ? 'منتجات' : 'منتجًا'}
                </>
              )}
            </span>
          </span>
        </span>

        <span className={styles.cell} style={{ flex: '0 0 62px' }}>
          —
        </span>
        <span className={styles.cell} style={{ flex: '0 0 62px' }}>
          —
        </span>
        <span className={styles.cell} style={{ flex: '0 0 58px' }}>
          —
        </span>
        <span className={`${styles.stateChip} ${skip ? styles.stateChipSkip : ''}`}>
          {skip ? 'يُتجاوَز' : 'تحتاج قياسًا'}
        </span>

        <button type="button" className={styles.actionBtn} disabled aria-describedby="io-restore-reason">
          {skip ? 'السبب' : 'تفاصيل'}
        </button>
      </div>

      {skip ? (
        <div className={styles.errWrap}>
          <div className={styles.err}>
            <MediaIcon name="info" className={styles.errIcon} />
            <span className={styles.errText}>{skip}</span>
          </div>
        </div>
      ) : null}
    </div>
  );
}
