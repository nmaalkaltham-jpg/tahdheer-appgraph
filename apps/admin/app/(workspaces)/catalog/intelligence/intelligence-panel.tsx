'use client';
// UNIT: CATALOG-INTELLIGENCE-PANEL
// FILE: intelligence-panel.tsx
// STYLE: intelligence.module.css
// PARENT: PAGE-CATALOG-INTELLIGENCE
// USES: OPS, RESOURCE
// API: /admin/catalog/intelligence
// MAP: docs/PROJECT_MAP.md

/**
 * ما استخرجه النظام من كلّ منتج، وأين توقّف خطّ الاستخراج.
 *
 * ─── ما تُجيب عنه هذه الشاشة ───
 *
 * خطّ الاستخراج يكتب في `asset_extractions` ولا أحد يقرؤه: النجاح صامت
 * والفشل صامت مثله. فمن يسأل «هل قُرئ ملفّ هذا المنتج؟» لا يملك إلّا
 * `psql`. هذه الشاشة هي الجواب.
 *
 * ─── لماذا الفشل بنصّه لا بنقطةٍ حمراء ───
 *
 * أصلان في هذا المتجر يفشلان بـ`storage_missing_object`. نقطةٌ حمراء تقول
 * «شيءٌ ما خطأ» فيُهمَل الصفّ؛ والسطر الذي يقول **أيّ أصلٍ** و**لماذا** و**ما
 * الأمر الذي يُعيد المحاولة** يُنجز العمل. ولذلك يُعرض `errorCode` و
 * `meta.note_ar` والعلاج معًا في الصفّ نفسه بلا نقرةٍ تفتحه.
 *
 * ─── ولماذا تُعرض الفجوات لا النجاحات وحدها ───
 *
 * أصلٌ بلا صفّ استخراجٍ البتّة، وصيغةٌ لا محوّل لها في السجلّ: هذان لا
 * يظهران في أيّ عدّاد نجاح، فيبدو الخطّ كاملًا وهو يتجاهل نوعًا كاملًا.
 * جدول «تغطية الصيغ» و`assetsWithoutAttempt` موجودان لهذا وحده.
 *
 * ─── اللون ───
 *
 * الأحمر للمعطوب وحده (قاعدة `ops.tsx`): `FAILED`. وما عداه منتظِرٌ أو
 * ناقصُ قدرة — رماديٌّ بسطر سببٍ يقرأه المشغّل. تلوينُ المنتظر تنبيهًا
 * يملأ الشاشة بما لا يُصلَح فتُهمل كلّها.
 */

import { useState } from 'react';
import { CountBar, OpsTable, ReasonLine, StatusPill, type Tone } from '../../operations/_shared/ops';
import { EmptyRows, ResourceView, useResource } from '../../../../components/resource';
import styles from './intelligence.module.css';

type Stage = 'COMPLETE' | 'PARTIAL' | 'FAILED' | 'STALE' | 'NOT_REQUIRED' | 'UNKNOWN';

interface Registry {
  extractor: string;
  version: string;
  catchAll: boolean;
}

interface Coverage {
  mime: string;
  assets: number;
  extractor: string;
  version: string;
  claimed: boolean;
}

interface ProductCard {
  id: number;
  sku: string;
  title: string;
  status: string;
  assetCount: number;
  stage: Stage;
  stageReason: string;
  assetStages: Stage[];
}

interface ListView {
  data: ProductCard[];
  stages: Record<Stage, number>;
  registry: Registry[];
  coverage: Coverage[];
  totals: { products: number; assets: number; assetsWithoutAttempt: number; extractionRows: number };
}

interface Attempt {
  id: number;
  extractor: string;
  version: string;
  status: string;
  errorCode: string | null;
  units: number | null;
  durationMs: number | null;
  bytesRead: number | null;
  updatedAt: string | null;
  meta?: Record<string, unknown> | null;
  textChars?: number | null;
  textTruncated?: boolean;
  text?: string | null;
}

interface AssetView {
  assetId: number;
  role: string;
  position: number;
  provider: string;
  originalName: string;
  customerLabel: string | null;
  mime: string;
  sizeBytes: number;
  visibility: string;
  sha256Prefix: string;
  expected: { extractor: string; version: string; claimed: boolean };
  storage: { state: string; observedAt: string | null; bytesRead: number | null; note: string };
  stage: Stage;
  stageReason: string;
  remedy: string | null;
  gap: { capability: string | null; requires: string[] } | null;
  current: Attempt | null;
  superseded: Attempt[];
}

interface DetailView {
  product: Record<string, string | number | null>;
  pipeline: { stage: Stage; reason: string };
  assets: AssetView[];
  registry: Registry[];
}

/** مفردات §١٨٣ بالعربيّة. الترجمة هنا وحدها؛ الخادم يُعيد المفتاح الآليّ. */
const STAGE_LABEL: Record<Stage, string> = {
  COMPLETE: 'مكتمل',
  PARTIAL: 'جزئيّ',
  FAILED: 'فاشل',
  STALE: 'قديم',
  NOT_REQUIRED: 'غير مطلوب',
  UNKNOWN: 'غير معروف',
};

const STAGE_TONE: Record<Stage, Tone> = {
  COMPLETE: 'done',
  PARTIAL: 'wait',
  FAILED: 'danger',
  STALE: 'wait',
  NOT_REQUIRED: 'wait',
  UNKNOWN: 'wait',
};

const STORAGE_LABEL: Record<string, string> = {
  readable: 'قُرئ',
  missing: 'غائب عن التخزين',
  unverified: 'بلا شهادة',
};

/** الترتيب مقروءٌ عربيًّا: «٤٫٢ ك.ب» لا «4280». */
function bytes(value: number): string {
  if (value < 1024) return `${value} بايت`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} ك.ب`;

  return `${(value / (1024 * 1024)).toFixed(1)} م.ب`;
}

function when(iso: string | null): string {
  if (iso === null) return '—';

  return new Date(iso).toLocaleString('ar-SA-u-nu-latn', { dateStyle: 'short', timeStyle: 'short' });
}

/** صفّ محاولةٍ مُتجاوَزة — يُعرض ولا يُخفى: يشرح كيف صارت المرحلة «قديمًا». */
function SupersededRow({ attempt }: { attempt: Attempt }) {
  return (
    <li>
      <code>{attempt.extractor}</code> · نسخة {attempt.version} · حالة <code>{attempt.status}</code>
      {attempt.errorCode === null ? null : (
        <>
          {' '}
          · رمز <code>{attempt.errorCode}</code>
        </>
      )}{' '}
      · {when(attempt.updatedAt)}
    </li>
  );
}

function AssetCard({ asset }: { asset: AssetView }) {
  const current = asset.current;

  return (
    <article className={styles.asset}>
      <header className={styles.assetHead}>
        <div>
          <h3 className={styles.assetName}>{asset.customerLabel ?? asset.originalName}</h3>
          <ReasonLine>
            {asset.mime} · {bytes(asset.sizeBytes)} · دور «{asset.role}» · أصل #{asset.assetId} ·{' '}
            {asset.visibility === 'paid_private' ? 'مدفوع' : 'عيّنة عامّة'}
          </ReasonLine>
        </div>
        <StatusPill tone={STAGE_TONE[asset.stage]} label={STAGE_LABEL[asset.stage]} />
      </header>

      <p className={styles.reasonStrong}>{asset.stageReason}</p>

      {/* العلاج نصًّا في الصفّ: «ماذا أفعل الآن» لا «شيءٌ ما خطأ». */}
      {asset.remedy === null ? null : (
        <p className={styles.remedy} role="note">
          {asset.remedy}
        </p>
      )}

      {asset.gap === null ? null : (
        <p className={styles.gap}>
          القدرة الناقصة: <code>{asset.gap.capability ?? 'غير مسمّاة'}</code>
          {asset.gap.requires.length === 0 ? null : <> — يلزمها: {asset.gap.requires.join('، ')}</>}
        </p>
      )}

      <dl className={styles.facts}>
        <div>
          <dt>التخزين</dt>
          <dd>
            {STORAGE_LABEL[asset.storage.state] ?? asset.storage.state}
            <ReasonLine>
              {asset.storage.note} {asset.storage.observedAt === null ? '' : `(${when(asset.storage.observedAt)})`}
            </ReasonLine>
          </dd>
        </div>
        <div>
          <dt>المحوّل المنتظَر</dt>
          <dd>
            <code>{asset.expected.extractor}</code> · نسخة {asset.expected.version}
            {asset.expected.claimed ? null : (
              <ReasonLine>لا محوّل يدّعي هذه الصيغة — تولّاها المحوّل الشامل.</ReasonLine>
            )}
          </dd>
        </div>
        <div>
          <dt>المحاولة الحاليّة</dt>
          <dd>
            {current === null ? (
              'لا صفّ'
            ) : (
              <>
                <code>{current.status}</code>
                {current.errorCode === null ? null : (
                  <>
                    {' '}
                    · رمز <code>{current.errorCode}</code>
                  </>
                )}
                <ReasonLine>
                  {current.units ?? 0} وحدة · {current.durationMs ?? 0} م.ث ·{' '}
                  {current.bytesRead === null ? 'لم تُقرأ بايتات' : bytes(current.bytesRead)} · {when(current.updatedAt)}
                </ReasonLine>
              </>
            )}
          </dd>
        </div>
      </dl>

      {current === null || current.text === null || current.text === undefined ? null : (
        <details className={styles.textBlock}>
          <summary className={styles.summary}>
            النصّ المستخرَج ({current.textChars ?? 0} محرفًا{current.textTruncated ? ' — مقطوع' : ''})
          </summary>
          {/* داخليّ: محتوى ملفٍّ مدفوع بصيغةٍ أخرى (§٥٨ §١٢٦). لا مسار عامّ يعيده. */}
          <pre className={styles.text}>{current.text}</pre>
        </details>
      )}

      {asset.superseded.length === 0 ? null : (
        <details className={styles.textBlock}>
          <summary className={styles.summary}>محاولات متجاوَزة ({asset.superseded.length})</summary>
          <ul className={styles.superseded}>
            {asset.superseded.map((a) => (
              <SupersededRow key={a.id} attempt={a} />
            ))}
          </ul>
        </details>
      )}
    </article>
  );
}

/**
 * التفصيل يُركَّب عند الاختيار وحده.
 *
 * مكوّنٌ مستقلّ لا نداءٌ مشروط داخل الأب: `useResource` خطّافٌ لا يُستدعى
 * شرطًا، وتركيبُ المكوّن عند الحاجة هو الطريق المسموح — ولا يُحمَّل المنتج
 * الأوّل تلقائيًّا: طلبٌ لا يطلبه المشغّل على شاشةِ تشخيص.
 */
function ProductDetail({ productId }: { productId: number }) {
  const { state, reload } = useResource<DetailView>(`/admin/catalog/intelligence/${productId}`);

  return (
    <ResourceView
      state={state}
      reload={reload}
      forbidden={<p>لا تملك صلاحية قراءة المنتجات، وفاحص الذكاء يُقرأ بها.</p>}
      notFound={<p>لا منتج بهذا المعرّف — قد يكون حُذف بعد فتح القائمة.</p>}
    >
      {(view) => (
        <section className={styles.detail} aria-label="تفصيل المنتج">
          <header className={styles.detailHead}>
            <div>
              <h2 className={styles.detailTitle}>{String(view.product.title ?? '')}</h2>
              <ReasonLine>
                {String(view.product.sku ?? '')} · {String(view.product.slug ?? '')}
              </ReasonLine>
            </div>
            <StatusPill tone={STAGE_TONE[view.pipeline.stage]} label={STAGE_LABEL[view.pipeline.stage]} />
          </header>

          <p className={styles.reasonStrong}>{view.pipeline.reason}</p>

          {/* بيانات الكتالوج المرجعيّة — ما يقوله الكتالوج، لا ما استنتجه محوّل. */}
          <dl className={styles.facts}>
            <div>
              <dt>الحالة</dt>
              <dd>{String(view.product.status ?? '—')}</dd>
            </div>
            <div>
              <dt>المرحلة الدراسيّة</dt>
              <dd>{view.product.stage === null ? '—' : String(view.product.stage)}</dd>
            </div>
            <div>
              <dt>الصفّ</dt>
              <dd>{view.product.grade === null ? '—' : String(view.product.grade)}</dd>
            </div>
            <div>
              <dt>المادّة</dt>
              <dd>{view.product.subject === null ? '—' : String(view.product.subject)}</dd>
            </div>
            <div>
              <dt>الفصل</dt>
              <dd>{view.product.semester === null ? '—' : String(view.product.semester)}</dd>
            </div>
            <div>
              <dt>الصيغة المعلنة</dt>
              <dd>{view.product.format === null ? '—' : String(view.product.format)}</dd>
            </div>
            <div>
              <dt>نوع المورد</dt>
              <dd>{view.product.resourceType === null ? '—' : String(view.product.resourceType)}</dd>
            </div>
            <div>
              <dt>طريقة التسليم</dt>
              <dd>{String(view.product.fulfillmentType ?? '—')}</dd>
            </div>
          </dl>

          {view.assets.length === 0 ? (
            <p className="state-line">لا أصل مرتبط بهذا المنتج، فلا شيء يُستخرج.</p>
          ) : (
            view.assets.map((asset) => <AssetCard key={asset.assetId} asset={asset} />)
          )}
        </section>
      )}
    </ResourceView>
  );
}

export function CatalogIntelligencePanel() {
  const { state, reload } = useResource<ListView>('/admin/catalog/intelligence');
  const [selected, setSelected] = useState<number | null>(null);

  return (
    <ResourceView
      state={state}
      reload={reload}
      forbidden={<p>لا تملك صلاحية قراءة المنتجات، وفاحص الذكاء يُقرأ بها.</p>}
    >
      {(view) => (
        <div className={styles.root}>
          <CountBar
            items={[
              { label: 'فاشل', value: view.stages.FAILED, tone: view.stages.FAILED > 0 ? 'danger' : 'done' },
              { label: 'غير معروف', value: view.stages.UNKNOWN },
              { label: 'قديم', value: view.stages.STALE },
              { label: 'جزئيّ', value: view.stages.PARTIAL },
              { label: 'غير مطلوب', value: view.stages.NOT_REQUIRED },
              { label: 'مكتمل', value: view.stages.COMPLETE, tone: 'done' },
              { label: 'أصول بلا محاولة', value: view.totals.assetsWithoutAttempt },
              { label: 'صفوف الاستخراج', value: view.totals.extractionRows },
            ]}
          />

          {/* الصمت عن الفجوة هو نمط العطب: التغطية تُعرض دائمًا لا عند الخطأ. */}
          <section className={styles.block} aria-label="تغطية الصيغ">
            <h2 className={styles.heading}>تغطية الصيغ في الكتالوج</h2>
            <OpsTable columns={['الصيغة', 'أصول', 'المحوّل', 'النسخة', 'مدّعاة؟']}>
              {view.coverage.length === 0 ? (
                <EmptyRows colSpan={5} message="لا أصل في الكتالوج بعد." />
              ) : (
                view.coverage.map((row) => (
                  <tr key={row.mime}>
                    <td>
                      <code>{row.mime}</code>
                    </td>
                    <td>{row.assets}</td>
                    <td>
                      <code>{row.extractor}</code>
                    </td>
                    <td>{row.version}</td>
                    <td>
                      <StatusPill tone={row.claimed ? 'done' : 'wait'} label={row.claimed ? 'نعم' : 'لا محوّل'} />
                      {row.claimed ? null : <ReasonLine>يتولّاها المحوّل الشامل: لا نصّ يُستخرج منها.</ReasonLine>}
                    </td>
                  </tr>
                ))
              )}
            </OpsTable>
          </section>

          <section className={styles.block} aria-label="المنتجات">
            <h2 className={styles.heading}>المنتجات — الأسوأ أوّلًا</h2>
            <OpsTable columns={['المنتج', 'المرحلة', 'أصول', 'السبب']}>
              {view.data.length === 0 ? (
                <EmptyRows colSpan={4} message="لا منتج في الكتالوج بعد." />
              ) : (
                view.data.map((card) => (
                  <tr key={card.id} className={selected === card.id ? styles.selected : undefined}>
                    <td>
                      <button
                        type="button"
                        className={styles.pick}
                        aria-pressed={selected === card.id}
                        onClick={() => setSelected(selected === card.id ? null : card.id)}
                      >
                        {card.title}
                      </button>
                      <ReasonLine>{card.sku}</ReasonLine>
                    </td>
                    <td>
                      <StatusPill tone={STAGE_TONE[card.stage]} label={STAGE_LABEL[card.stage]} />
                    </td>
                    <td>{card.assetCount}</td>
                    <td>
                      <ReasonLine>{card.stageReason}</ReasonLine>
                    </td>
                  </tr>
                ))
              )}
            </OpsTable>
          </section>

          {selected === null ? (
            <p className="state-line">اختر منتجًا لترى أصوله وما استُخرج منها.</p>
          ) : (
            <ProductDetail productId={selected} />
          )}
        </div>
      )}
    </ResourceView>
  );
}
