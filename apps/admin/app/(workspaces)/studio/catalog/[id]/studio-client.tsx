'use client';
// UNIT: STUDIO-CLIENT
// FILE: studio-client.tsx
// STYLE: studio.module.css
// PARENT: PAGE-STUDIO-CATALOG-ID
// USES: ADMIN-SESSION, AI-INDEX, CATALOG-TERMS, LIB-ADMIN-API, LIB-FORMAT, MASTER-TREE +7
// API: PATCH /admin/products
// MAP: docs/PROJECT_MAP.md

/**
 * استوديو المنتج — إحدى عشرة خطوة على عنوان واحد قابل للمشاركة (?step=).
 *
 * ما يُحفظ يمرّ على PATCH /admin/products/{id} وحده، والنشر على
 * PATCH /admin/products/{id}/publish الذي يعيد 422 بقائمة الجاهزية الحقيقية.
 * قائمة الجاهزية المعروضة هي ما أعاده الخادم حرفيًا — لا نسبة مرسومة يدويًا.
 *
 * الأسعار تُدخل بالريالات وتُرسل هللات صحيحة. الخصم مشتقّ من الحقلين ولا
 * يُكتب، فلا يمكن أن تعرض الشاشة نسبة تناقض أرقامها.
 *
 * الملفات والوسائط والبحث واللغات والتغطية ومساعد الكتابة موصولة كلّها بنقاط
 * نهاية مسجَّلة في routes/api-admin.php (ألواحها في wired-panels.tsx). ما كان
 * معلَّمًا «غير موصول» كان موصولًا فعلًا، واللافتة وحدها هي التي منعت العمل.
 *
 * ما يبقى معلَنًا هو ما يقوله الخادم لحظتَه: مساعد الكتابة يردّ 503 برمز سببه
 * حين لا يكون مهيَّأً، فتُعرض رسالته لا لافتة ثابتة تسبق الطلب.
 */

import Link from 'next/link';
import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { CATALOG_TERMS_KEY, useCatalogTerms } from '../catalog-terms';
import { useSession } from '../../../../../components/admin-session';
import { PageHeader, ResourceView, useResource, type ResourceState } from '../../../../../components/resource';
import { api, firstError, type Readiness } from '../../../../../lib/admin-api';
import { count, money, riyalsToMinor, PRODUCT_STATUS_LABELS } from '../../../../../lib/format';
import MasterTree from './master-tree';
import styles from './studio.module.css';
import {
  cx,
  FACT_KEYS,
  FACT_VOCABULARY,
  localInputToIso,
  saleWindowNotice,
  SEO_BUDGET,
  SEO_OWNERSHIP,
  STEPS,
  type StepKey,
  type StudioProduct,
} from '../studio-data';
import {
  CoveragePanel,
  BundlePanel,
  DrivePanel,
  FilesPanel,
  IntlPanel,
  MediaPanel,
  SearchFields,
  useSeoDoc,
  WritingAssistant,
  VariantsPanel,
} from './wired-panels';
import { ProductCopilot } from '../../../../../components/ai';
import { Field } from './_field';
import { EDITABLE_OPTIONS, EMPTY_GRADES, EMPTY_TERMS, stepStatesFor, toForm, type FormState } from './_form-state';
import { natureOf, strategiesOf, useProductTypes } from '../product-types';
import type { WritingField } from './_panel-vocabulary';

/**
 * الخطأ يُقرأ برمزه لا بنصّ الخادم وحده.
 *
 * `findOrFail` يردّ 404 و`AdminAuthorize` يردّ 403، وكلاهما كان يصل هنا رسالةً
 * عامّة ومعها زرّ «إعادة المحاولة». ومنتجٌ حُذف لن يعود مهما أُعيد الطلب: الزرّ
 * عليه وعدٌ كاذب. من فتح رابطًا قديمًا يحتاج أن يعرف أن المنتج غير موجود وأن
 * يعود إلى القائمة، ومن نقصته الصلاحية يحتاج اسمها لا رسالةً مبهمة.
 */
function ProductState({ state, reload, children }: {
  state: ResourceState<{ data: StudioProduct }>;
  reload: () => void;
  children: (product: StudioProduct) => ReactNode;
}) {
  if (state.status === 'error' && state.code === 404) {
    return (
      <div className="state-error" role="alert">
        <p>لا منتج بهذا المعرّف. قد يكون حُذف، أو يكون الرابط قديمًا.</p>
        <Link className="btn btn-ghost" href="/studio/catalog">
          العودة إلى قائمة المنتجات
        </Link>
      </div>
    );
  }

  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>
          لا تملك صلاحية <bdi dir="ltr">products.read</bdi> لقراءة المنتجات.
        </p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {(payload) => children(payload.data)}
    </ResourceView>
  );
}

export default function StudioClient({
  productId,
  initialStep,
  embedded = false,
}: {
  productId: string;
  initialStep: StepKey;
  /**
   * داخل درج استوديو المتجر (WP-E3): يكبت PageHeader — الدرج يملك رأسه —
   * ولا يُكتب ?step= في عنوان صفحة مضيفة لا تملك هذه الخطوات.
   */
  embedded?: boolean;
}) {
  const { can } = useSession();
  const { state, reload } = useResource<{ data: StudioProduct }>(`/admin/products/${productId}`);

  return (
    <section>
      {embedded ? null : (
        <PageHeader
          trail="المتجر › استوديو المنتج"
          title="استوديو المنتج"
          actions={
            <>
              <Link className="btn btn-ghost" href={`/studio/catalog/${productId}/layout`}>
                منشئ صفحة المنتج
              </Link>
              <Link className="btn btn-ghost" href="/studio/catalog">
                رجوع للقائمة
              </Link>
            </>
          }
        />
      )}
      <ProductState state={state} reload={reload}>
        {(product) => (
          <Studio
            product={product}
            initialStep={initialStep}
            canEdit={can('products.update')}
            canPublish={can('products.publish')}
            onSaved={reload}
            embedded={embedded}
          />
        )}
      </ProductState>
    </section>
  );
}

function Studio({
  product,
  initialStep,
  canEdit,
  canPublish,
  onSaved,
  embedded,
}: {
  product: StudioProduct;
  initialStep: StepKey;
  canEdit: boolean;
  canPublish: boolean;
  onSaved: () => void;
  embedded: boolean;
}) {
  const [step, setStep] = useState<StepKey>(initialStep);
  const [advanced, setAdvanced] = useState(true);
  const [form, setForm] = useState<FormState>(() => toForm(product));
  const types = useProductTypes();
  /*
   * الطبيعة الأصليّة تُحفظ للمقارنة: `productNature` تُرسَل عند التبديل وحده.
   * إرسالُها في كلّ حفظٍ كان يُعيد كتابة استراتيجيّات التسليم عند كلّ تعديلِ
   * عنوان — أي يمحو اختيارًا صريحًا للمشغّل بلا أن يطلبه.
   */
  const originalNature = product.productNature ?? '';
  const natureChanged = form.productNature !== originalNature && form.productNature !== '';
  const chosenNature = natureOf(types.data, form.productNature);
  const [natureStrategies, setNatureStrategies] = useState<string[]>([]);
  const natureOptions = strategiesOf(types.data, form.productNature);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const [readiness, setReadiness] = useState<Readiness>(product.readiness);

  /*
   * قوائم التصنيف عبر React Query — لا خمس حالاتٍ يضبطها أثر.
   *
   * الجلب كلٌّ لا يتجزّأ كما كان: أيّ ردٍّ فاشل يرمي قبل أن تُكتب قائمةٌ
   * واحدة، فلا بقايا محاولةٍ فاشلة تختلط بالجديدة. والفرق أنّ الحالة صارت
   * **مشتقّة** من نتيجةٍ واحدة بدل خمس `useState` تُضبط بالترتيب.
   *
   * والمفتاح مشترك: المحرّر ونموذج الإنشاء يقرآن القوائم نفسها، وكانا
   * يجلبانها مرّتين في الجلسة الواحدة.
   */
  const catalogQuery = useCatalogTerms();

  const catalogClient = useQueryClient();

  /*
   * `EMPTY` ثابتٌ وحيد لا `[]` في كلّ تصيير: المصفوفة الجديدة تُبطل كلّ
   * `useMemo` يعتمد عليها، فتُعاد الترشيحات الثقيلة قبل وصول البيانات.
   */
  const stages = catalogQuery.data?.stages ?? EMPTY_TERMS;
  const grades = catalogQuery.data?.grades ?? EMPTY_GRADES;
  const subjects = catalogQuery.data?.subjects ?? EMPTY_TERMS;
  const semesters = catalogQuery.data?.semesters ?? EMPTY_TERMS;
  const kinds = catalogQuery.data?.kinds ?? EMPTY_TERMS;
  const catalogError = catalogQuery.isError ? catalogQuery.error.message : null;
  /* عدّاد المحاولة: زيادته وحدها تُعيد تشغيل جلب قوائم الكتالوج أدناه. */

  /*
   * وثيقة SEO ترتفع إلى هنا لا إلى لوح خطوة البحث: مساعد الكتابة يعيش في
   * خطوة أخرى، ومسوّدة تموت بتبديل الخطوة تُضيّع اعتمادًا وقع فعلًا.
   */
  const seoDoc = useSeoDoc(product.id);

  /* مفتاح المساعد تفضيل واجهة صريح: مطفأً لا يُرسَل أي نداء توليد. */
  const [qwenOnline, setQwenOnline] = useState(true);

  /*
   * قوائم التصنيف من الكتالوج — والعطب نفسه الذي في `new/page.tsx`.
   *
   * هما موضعان بعلّةٍ واحدة: انقطاعٌ عابر يُقفل الأبعاد الأربعة التي تشترطها
   * بوابة النشر ويُعلن الخطأ بلا سبيل إلى إعادة الطلب. وهنا أسوأ منه هناك:
   * شاشة الإنشاء تُفتح فارغة، أمّا هذه فقد يكون المشغّل في وسط تحرير منتجٍ
   * قائم — فإعادة تحميل الصفحة، وهي المخرج الوحيد بلا زرّ، تمحو ما كتبه.
   * فليبقَ الموضعان بالشكل نفسه: عدّاد محاولة، ومسحُ الخطأ عند كلّ محاولة،
   * وزرّ «إعادة المحاولة» صريح. من «يبسّط» أحدهما يُعيد العلّة إليه وحده.
   *
   * والجلب هنا كلٌّ لا يتجزّأ: أيّ ردٍّ فاشل يُنهي الدالة قبل أيّ `set…`،
   * فلا تُكتب قائمةٌ واحدة من محاولةٍ فاشلة. والنجاح يستبدل القوائم الخمس
   * كاملةً ولا يضيف إليها — فلا بقايا محاولةٍ سابقة تختلط بالجديدة.
   */

  const goToStep = useCallback(
    (next: StepKey) => {
      setStep(next);
      /* داخل الدرج لا عنوان لهذه الخطوات: الكتابة تلوّث رابط المحرّر المضيف. */
      if (!embedded && typeof window !== 'undefined') {
        const url = new URL(window.location.href);
        url.searchParams.set('step', next);
        window.history.replaceState(null, '', url.toString());
      }
    },
    [embedded],
  );

  const gradesForStage = useMemo(
    () => (form.stageId === '' ? grades : grades.filter((grade) => String(grade.stage.id) === form.stageId)),
    [grades, form.stageId],
  );

  const stepStates = useMemo(() => stepStatesFor(product, form, readiness), [product, form, readiness]);

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  /** القيمة الحالية للحقل الذي يقترح له المساعد — من المسوّدة المعروضة لا من الخادم. */
  function currentWriting(field: WritingField): string {
    switch (field) {
      case 'title':
        return form.title;
      case 'shortDesc':
        return form.shortDesc;
      case 'description':
        return form.description;
      case 'seoTitle':
        return seoDoc.draft?.seoTitle ?? '';
      case 'seoDesc':
        return seoDoc.draft?.seoDesc ?? '';
    }
  }

  /*
   * الاعتماد يملأ المسوّدة ولا يحفظ: الخادم يردّ `applied:false` أصلًا، وكتابة
   * اقتراح في قاعدة البيانات بلا ضغطة حفظ تجعل نموذجًا يحرّر منتجًا منشورًا.
   */
  function acceptSuggestion(field: WritingField, value: string) {
    switch (field) {
      case 'title':
        update('title', value);
        return;
      case 'shortDesc':
        update('shortDesc', value);
        return;
      case 'description':
        update('description', value);
        return;
      case 'seoTitle':
        seoDoc.setField('seoTitle', value);
        return;
      case 'seoDesc':
        seoDoc.setField('seoDesc', value);
    }
  }

  async function save() {
    const regularMinor = riyalsToMinor(form.regularRiyals);
    if (form.regularRiyals.trim() !== '' && regularMinor === null) {
      setMessage({ tone: 'error', text: 'السعر الأساسي يجب أن يكون رقمًا موجبًا بالريالات.' });
      return;
    }
    const saleMinor = riyalsToMinor(form.saleRiyals);
    if (form.saleRiyals.trim() !== '' && saleMinor === null) {
      setMessage({ tone: 'error', text: 'سعر العرض يجب أن يكون رقمًا موجبًا بالريالات.' });
      return;
    }

    const payload: Record<string, unknown> = {
      ...(natureChanged
        ? {
            productNature: form.productNature,
            ...(natureStrategies.length === 0 ? {} : { fulfillmentStrategies: natureStrategies }),
          }
        : {}),
      title: form.title,
      slug: form.slug,
      sku: form.sku,
      subtitle: form.subtitle === '' ? null : form.subtitle,
      shortDesc: form.shortDesc === '' ? null : form.shortDesc,
      description: form.description === '' ? null : form.description,
      stageId: form.stageId === '' ? null : Number(form.stageId),
      gradeId: form.gradeId === '' ? null : Number(form.gradeId),
      subjectId: form.subjectId === '' ? null : Number(form.subjectId),
      semesterId: form.semesterId === '' ? null : Number(form.semesterId),
      resourceType: form.resourceType === '' ? null : form.resourceType,
      format: form.format === '' ? null : form.format,
      editable: form.editable,
      facts: form.facts
        .filter((fact) => fact.key.trim() !== '')
        .map((fact) => {
          const numeric = Number(fact.value);
          return Number.isFinite(numeric) && fact.value.trim() !== ''
            ? { key: fact.key.trim(), intValue: Math.round(numeric) }
            : { key: fact.key.trim(), textValue: fact.value };
        }),
    };
    if (regularMinor !== null) {
      /*
       * النافذة تُرسَل مع السعر لا وحدها: الخادم يتحقّق من ترتيب الحدّين
       * مقابل المحفوظ (ProductController)، والتحقّق هنا يمنع رحلةً بلا طائل
       * ويشرح السبب بالعربية عند الحقل نفسه.
       */
      const saleFrom = localInputToIso(form.saleFrom);
      const saleTo = localInputToIso(form.saleTo);

      if (saleFrom === false || saleTo === false) {
        setMessage({ tone: 'error', text: 'تاريخ التخفيض غير صالح. استخدم منتقي التاريخ والوقت.' });
        return;
      }
      if (saleFrom !== null && saleTo !== null && new Date(saleFrom) >= new Date(saleTo)) {
        setMessage({ tone: 'error', text: 'تاريخ انتهاء التخفيض يجب أن يكون بعد تاريخ بدايته.' });
        return;
      }
      if (saleMinor === null && (saleFrom !== null || saleTo !== null)) {
        setMessage({
          tone: 'error',
          text: 'حدِّد سعر التخفيض أو امسح تاريخَي النافذة — نافذة بلا سعر لا أثر لها.',
        });
        return;
      }

      payload.regularMinor = regularMinor;
      payload.saleMinor = saleMinor;
      payload.saleFrom = saleFrom;
      payload.saleTo = saleTo;
    }

    setSaving(true);
    setMessage(null);
    const result = await api.patch<{ data: StudioProduct }>(`/admin/products/${product.id}`, payload);
    setSaving(false);

    if (!result.ok) {
      setMessage({ tone: 'error', text: firstError(result) });
      return;
    }
    setReadiness(result.data.data.readiness);
    setMessage({ tone: 'ok', text: 'حُفظت التعديلات.' });
    onSaved();
  }

  async function publish() {
    setSaving(true);
    setMessage(null);
    const result = await api.patch<{ data: StudioProduct; readiness: Readiness }>(
      `/admin/products/${product.id}/publish`,
    );
    setSaving(false);

    if (result.ok) {
      setReadiness(result.data.readiness);
      setMessage({ tone: 'ok', text: 'نُشر المنتج.' });
      onSaved();
      return;
    }

    // 422 من بوابة النشر يحمل قائمة الجاهزية الحقيقية — تُعرض كما وصلت.
    const body = (result.body ?? {}) as { readiness?: Readiness };
    if (body.readiness) setReadiness(body.readiness);
    setMessage({ tone: 'error', text: result.message });
  }

  const pct = readiness.totalCount === 0 ? 0 : Math.round((readiness.completedCount / readiness.totalCount) * 100);
  const regular = riyalsToMinor(form.regularRiyals);
  const sale = riyalsToMinor(form.saleRiyals);
  const savingMinor = regular !== null && sale !== null && sale < regular ? regular - sale : 0;
  // حالة النافذة المحفوظة — من ردّ الخادم لا من المسوّدة قيد التحرير.
  const savedWindowNotice = saleWindowNotice(product);
  const discountPct = savingMinor > 0 && regular !== null && regular > 0 ? Math.round((savingMinor / regular) * 100) : 0;

  return (
    <>
      <div className={styles.head}>
        <div className={styles.headText}>
          <div className={styles.headRow}>
            <span className={styles.badge}>PRODUCT STUDIO</span>
            <span className={styles.headTitle}>{product.title}</span>
            <span className={cx('chip', `chip-${product.status}`)}>
              {PRODUCT_STATUS_LABELS[product.status] ?? product.status}
            </span>
            <bdi dir="ltr" className="mono">
              {product.sku}
            </bdi>
          </div>
          <p className={styles.headNote}>
            أساسيات المنتج تكتب في <code dir="ltr">PATCH /admin/products/{'{id}'}</code>، ولكلٍّ من
            الملفات والوسائط والبحث واللغات نقطته وزرّ حفظه في خطوته. لا يُعرض هنا حكم لا يصدر عن
            الخادم.
          </p>
        </div>
        <div className={styles.modes} role="group" aria-label="كثافة الشرح">
          {[
            { key: false, label: 'وضع سريع' },
            { key: true, label: 'وضع متقدّم' },
          ].map((mode) => (
            <button
              key={mode.label}
              type="button"
              className={cx(styles.mode, advanced === mode.key && styles.modeOn)}
              aria-pressed={advanced === mode.key}
              onClick={() => setAdvanced(mode.key)}
            >
              {mode.label}
            </button>
          ))}
        </div>
      </div>

      <div className={styles.grid}>
        <nav className={styles.steps} aria-label="أقسام المنتج">
          {STEPS.map((definition, index) => {
            const current = step === definition.key;
            const stepState = stepStates[definition.key];
            return (
              <button
                key={definition.key}
                type="button"
                className={cx(styles.step, current && styles.stepOn)}
                aria-current={current ? 'step' : undefined}
                onClick={() => goToStep(definition.key)}
              >
                <span aria-hidden="true" className={cx(styles.stepDot, styles[`dot_${stepState}`])}>
                  {index + 1}
                </span>
                <span className={styles.stepLabel}>{definition.label}</span>
                <span aria-hidden="true" className={cx(styles.stepState, styles[`state_${stepState}`])}>
                  {stepState === 'done' ? '✓' : '!'}
                </span>
                <span className="sr-only">
                  {stepState === 'done' ? 'مكتمل' : 'ناقص'}
                </span>
              </button>
            );
          })}
        </nav>

        <main className={styles.main}>
          {message ? (
            <p
              className={message.tone === 'ok' ? 'state-ok' : 'state-error'}
              role={message.tone === 'ok' ? 'status' : 'alert'}
              aria-live="polite"
            >
              {message.text}
            </p>
          ) : null}

          {step === 'master' ? <MasterTree product={product} help={advanced} /> : null}

          {step === 'basics' ? (
            <section className={styles.panel}>
              <h2 className={styles.panelTitle}>أساسيات المنتج</h2>
              <div className={styles.fields}>
                {/*
                  * §٦ — نوع المنتج. مبدَّلٌ هنا لأنّ أثره يُرى هنا: هو يقرّر
                  * ما توجبه بوّابة النشر وكيف يُسلَّم. وكان عرضًا للقراءة في
                  * منشئ الصفحة بحجّة أنّ «لا نقطة نهاية تغيّره» — وقد صارت
                  * له نقطة.
                  */}
                <Field
                  id="s-nature"
                  label="نوع المنتج"
                  hint={
                    form.productNature === ''
                      ? 'هذا المنتج سبق النموذج الكونيّ ولا طبيعة له. اختيارُ نوعٍ يكتبها.'
                      : natureChanged
                        ? 'التبديل يُعيد كتابة طريقة التسليم واستراتيجيّة المخزون عند الحفظ.'
                        : undefined
                  }
                  wide
                >
                  <select
                    id="s-nature"
                    value={form.productNature}
                    disabled={!canEdit || types.isPending}
                    onChange={(event) => {
                      update('productNature', event.target.value);
                      setNatureStrategies([]);
                    }}
                  >
                    <option value="">— بلا نوع —</option>
                    {(types.data?.natures ?? []).map((item) => (
                      <option key={item.value} value={item.value}>
                        {item.label}
                      </option>
                    ))}
                  </select>
                </Field>

                {natureChanged && chosenNature !== undefined ? (
                  <Field
                    id="s-delivery"
                    label="طريقة التسليم بعد التبديل"
                    hint={
                      chosenNature.defaultStrategy === null
                        ? 'الهجين يوجب اختيارًا صريحًا: تسليمًا رقميًّا وماديًّا معًا.'
                        : 'يُترك فارغًا فيأخذ اقتراح النوع من الخادم.'
                    }
                    wide
                  >
                    <div role="group" aria-labelledby="s-delivery">
                      {natureOptions.map((strategy) => (
                        <label key={strategy.value} className={styles.checkRow}>
                          <input
                            type="checkbox"
                            checked={natureStrategies.includes(strategy.value)}
                            disabled={!canEdit}
                            onChange={(event) =>
                              setNatureStrategies((current) =>
                                event.target.checked
                                  ? [...current, strategy.value]
                                  : current.filter((value) => value !== strategy.value),
                              )
                            }
                          />
                          <span>{strategy.label}</span>
                          {!strategy.executable ? (
                            <span className={styles.checkNote}>لا منفّذ بعد — يبقى معلّقًا</span>
                          ) : null}
                        </label>
                      ))}
                    </div>
                  </Field>
                ) : null}

                <Field id="s-title" label="اسم المنتج">
                  <input
                    id="s-title"
                    dir="auto"
                    value={form.title}
                    disabled={!canEdit}
                    onChange={(event) => update('title', event.target.value)}
                  />
                </Field>
                <Field id="s-subtitle" label="العنوان الفرعي">
                  <input
                    id="s-subtitle"
                    dir="auto"
                    value={form.subtitle}
                    disabled={!canEdit}
                    onChange={(event) => update('subtitle', event.target.value)}
                  />
                </Field>
                <Field id="s-slug" label="المُعرّف في الرابط">
                  <input
                    id="s-slug"
                    dir="ltr"
                    value={form.slug}
                    disabled={!canEdit}
                    onChange={(event) => update('slug', event.target.value)}
                  />
                </Field>
                <Field id="s-short" label="وصف قصير" wide>
                  <textarea
                    id="s-short"
                    dir="auto"
                    rows={2}
                    value={form.shortDesc}
                    disabled={!canEdit}
                    onChange={(event) => update('shortDesc', event.target.value)}
                  />
                </Field>
                <Field id="s-desc" label="الوصف الكامل" wide>
                  <textarea
                    id="s-desc"
                    dir="auto"
                    rows={7}
                    value={form.description}
                    disabled={!canEdit}
                    onChange={(event) => update('description', event.target.value)}
                  />
                </Field>
              </div>
            </section>
          ) : null}

          {step === 'edu' ? (
            <>
              <section className={styles.panel}>
                <div className={styles.panelHead}>
                  <h2 className={styles.panelTitle}>التصنيف التعليمي</h2>
                  <span className={styles.tagOk}>
                    <bdi dir="ltr">
                      {
                        [form.stageId, form.gradeId, form.subjectId, form.semesterId].filter((v) => v !== '')
                          .length
                      }
                    </bdi>{' '}
                    من <bdi dir="ltr">4</bdi> محدَّد
                  </span>
                </div>
                {advanced ? (
                  <p className={styles.panelNote}>
                    اكتمال المستويات الأربعة شرط في بوابة النشر على الخادم
                    (<code dir="ltr">ProductReadiness</code>)، وهو ما يبني مسار الفئة و
                    Schema معًا — لا يُملأ للأرشفة.
                  </p>
                ) : null}
                {catalogError ? (
                  <div className="state-error" role="alert">
                    <p>{catalogError} لا يمكن تحديد التصنيف حتى تعود القوائم.</p>
                    <button
                      type="button"
                      className="btn btn-ghost"
                      onClick={() => void catalogClient.invalidateQueries({ queryKey: CATALOG_TERMS_KEY })}
                    >
                      إعادة المحاولة
                    </button>
                  </div>
                ) : null}
                <div className={styles.fields}>
                  <Field id="s-stage" label="المرحلة">
                    <select
                      id="s-stage"
                      value={form.stageId}
                      disabled={!canEdit}
                      onChange={(event) => {
                        update('stageId', event.target.value);
                        update('gradeId', '');
                      }}
                    >
                      <option value="">— غير محدَّد —</option>
                      {stages.map((stage) => (
                        <option key={stage.id} value={String(stage.id)}>
                          {stage.name}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field id="s-grade" label="الصف">
                    <select
                      id="s-grade"
                      value={form.gradeId}
                      disabled={!canEdit}
                      onChange={(event) => update('gradeId', event.target.value)}
                    >
                      <option value="">— غير محدَّد —</option>
                      {gradesForStage.map((grade) => (
                        <option key={grade.id} value={String(grade.id)}>
                          {grade.name}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field id="s-subject" label="المادة">
                    <select
                      id="s-subject"
                      value={form.subjectId}
                      disabled={!canEdit}
                      onChange={(event) => update('subjectId', event.target.value)}
                    >
                      <option value="">— غير محدَّد —</option>
                      {subjects.map((subject) => (
                        <option key={subject.id} value={String(subject.id)}>
                          {subject.name}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field id="s-semester" label="الفصل">
                    <select
                      id="s-semester"
                      value={form.semesterId}
                      disabled={!canEdit}
                      onChange={(event) => update('semesterId', event.target.value)}
                    >
                      <option value="">— غير محدَّد —</option>
                      {semesters.map((semester) => (
                        <option key={semester.id} value={String(semester.id)}>
                          {semester.name}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field id="s-kind" label="نوع المورد">
                    <select
                      id="s-kind"
                      value={form.resourceType}
                      disabled={!canEdit}
                      onChange={(event) => update('resourceType', event.target.value)}
                    >
                      <option value="">— غير محدَّد —</option>
                      {kinds.map((kind) => (
                        <option key={kind.id} value={kind.name}>
                          {kind.name}
                        </option>
                      ))}
                      {form.resourceType !== '' && !kinds.some((kind) => kind.name === form.resourceType) ? (
                        <option value={form.resourceType}>{form.resourceType} (قيمة مخزَّنة خارج الكتالوج)</option>
                      ) : null}
                    </select>
                  </Field>
                  <Field id="s-format" label="صيغة الملف" hint="نصّ حرّ على الخادم (٣٢ محرفًا كحدّ أقصى) — لا قائمة مغلقة.">
                    <input
                      id="s-format"
                      dir="ltr"
                      value={form.format}
                      disabled={!canEdit}
                      onChange={(event) => update('format', event.target.value)}
                    />
                  </Field>
                </div>

                <fieldset className={styles.chipset}>
                  <legend>قابل للتعديل</legend>
                  <div className={styles.chips}>
                    {EDITABLE_OPTIONS.map((option) => (
                      <button
                        key={option.value}
                        type="button"
                        className={cx(styles.chipBtn, form.editable === option.value && styles.chipBtnOn)}
                        aria-pressed={form.editable === option.value}
                        disabled={!canEdit}
                        onClick={() => update('editable', option.value)}
                      >
                        {option.label}
                      </button>
                    ))}
                  </div>
                  <p className={styles.hint}>
                    ثلاث حالات في التخزين: <bdi dir="ltr">yes | no | unset</bdi>. «غير محدَّد» يبقى صامتًا
                    في المتجر ولا يُنشر كـ«لا».
                  </p>
                </fieldset>
              </section>

              <section className={styles.panel}>
                <div className={styles.panelHead}>
                  <h2 className={styles.panelTitle}>الحقائق</h2>
                  <span className={styles.tagOk}>
                    <bdi dir="ltr">{count(form.facts.length)}</bdi> مسجَّلة
                  </span>
                </div>
                {advanced ? (
                  <p className={styles.panelNote}>
                    المفاتيح المعروفة تُعرض بتسميتها العربية المعتمدة في المتجر وفي
                    <code dir="ltr"> EducationSchemaMapper</code>. أي مفتاح آخر يُحفظ ويُعرض بمفتاحه —
                    لا تُخترع له تسمية.
                  </p>
                ) : null}
                {form.facts.length === 0 ? (
                  <p className="absent">لا حقائق مسجَّلة لهذا المنتج.</p>
                ) : (
                  <div className={styles.factGrid}>
                    {form.facts.map((fact, index) => {
                      const known = Object.prototype.hasOwnProperty.call(FACT_VOCABULARY, fact.key);
                      const label = FACT_VOCABULARY[fact.key] ?? fact.key;
                      return (
                        <div key={`fact-${index}`} className={cx(styles.factCard, !known && styles.factUnknown)}>
                          <div className={styles.factHead}>
                            <span className={styles.factLabel}>{label.trim() === '' ? 'مفتاح فارغ' : label}</span>
                            <span className={cx(styles.factTag, known ? styles.tagKnown : styles.tagNew)}>
                              {known ? 'معروف' : 'غير معروف'}
                            </span>
                          </div>
                          <div className={styles.factRow}>
                            <input
                              aria-label={`مفتاح الحقيقة ${index + 1}`}
                              dir="ltr"
                              list="studio-fact-keys"
                              value={fact.key}
                              disabled={!canEdit}
                              onChange={(event) =>
                                update(
                                  'facts',
                                  form.facts.map((row, i) =>
                                    i === index ? { ...row, key: event.target.value } : row,
                                  ),
                                )
                              }
                            />
                            <input
                              aria-label={`قيمة الحقيقة ${index + 1}`}
                              dir="auto"
                              value={fact.value}
                              disabled={!canEdit}
                              onChange={(event) =>
                                update(
                                  'facts',
                                  form.facts.map((row, i) =>
                                    i === index ? { ...row, value: event.target.value } : row,
                                  ),
                                )
                              }
                            />
                            <button
                              type="button"
                              className="btn btn-ghost"
                              disabled={!canEdit}
                              onClick={() =>
                                update(
                                  'facts',
                                  form.facts.filter((_, i) => i !== index),
                                )
                              }
                            >
                              حذف
                            </button>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
                <datalist id="studio-fact-keys">
                  {FACT_KEYS.map((key) => (
                    <option key={key} value={key}>
                      {FACT_VOCABULARY[key]}
                    </option>
                  ))}
                </datalist>
                <button
                  type="button"
                  className="btn btn-ghost"
                  disabled={!canEdit}
                  onClick={() => update('facts', [...form.facts, { key: '', value: '' }])}
                >
                  إضافة حقيقة
                </button>
              </section>
            </>
          ) : null}

          {step === 'price' ? (
            <section className={styles.panel}>
              <h2 className={styles.panelTitle}>السعر</h2>
              <div className={styles.fields}>
                <Field id="s-regular" label="السعر الأساسي" hint="بالريالات — السعر نهائي، ويُرسل هللات صحيحة.">
                  <div className={styles.money}>
                    <input
                      id="s-regular"
                      dir="ltr"
                      inputMode="decimal"
                      value={form.regularRiyals}
                      disabled={!canEdit}
                      onChange={(event) => update('regularRiyals', event.target.value)}
                    />
                    <span className={styles.unit}>ر.س</span>
                  </div>
                </Field>
                <Field id="s-sale" label="سعر العرض" hint="اختياري، ويجب أن يقلّ عن السعر الأساسي.">
                  <div className={styles.money}>
                    <input
                      id="s-sale"
                      dir="ltr"
                      inputMode="decimal"
                      value={form.saleRiyals}
                      disabled={!canEdit}
                      onChange={(event) => update('saleRiyals', event.target.value)}
                    />
                    <span className={styles.unit}>ر.س</span>
                  </div>
                </Field>
                <Field
                  id="s-sale-from"
                  label="بداية التخفيض"
                  hint="اختياري — اتركه فارغًا ليبدأ فورًا. بتوقيتك المحلّي."
                >
                  <input
                    id="s-sale-from"
                    dir="ltr"
                    type="datetime-local"
                    value={form.saleFrom}
                    disabled={!canEdit}
                    onChange={(event) => update('saleFrom', event.target.value)}
                  />
                </Field>
                <Field
                  id="s-sale-to"
                  label="نهاية التخفيض"
                  hint="اختياري — اتركه فارغًا ليستمرّ بلا نهاية. بتوقيتك المحلّي."
                >
                  <input
                    id="s-sale-to"
                    dir="ltr"
                    type="datetime-local"
                    value={form.saleTo}
                    disabled={!canEdit}
                    onChange={(event) => update('saleTo', event.target.value)}
                  />
                </Field>
                <Field id="s-sku" label="رمز المنتج (SKU)">
                  <input
                    id="s-sku"
                    dir="ltr"
                    value={form.sku}
                    disabled={!canEdit}
                    onChange={(event) => update('sku', event.target.value)}
                  />
                </Field>
              </div>

              <div className={cx(styles.derived, savingMinor > 0 && styles.derivedOn)}>
                <span className={styles.derivedText}>
                  {savingMinor > 0
                    ? `خصم ${discountPct}% — توفير ${money(savingMinor)} ر.س`
                    : 'لا خصم — لن تظهر أي شارة في المتجر'}
                </span>
                <code dir="ltr" className={styles.derivedMath}>
                  {savingMinor > 0
                    ? `(${money(regular)} − ${money(sale)}) ÷ ${money(regular)}`
                    : `${money(regular)} = ${money(sale)}`}
                </code>
              </div>
              <p className={styles.hint}>
                النسبة مشتقّة من حقلَي السعر ولا تُكتب. والنافذة تُرسَل مع السعر: التاريخان
                يُحفظان بالتوقيت العالمي ويُعرضان بتوقيتك.
              </p>
              {savedWindowNotice === null ? null : (
                <p className={styles.panelNote} role="status">
                  {savedWindowNotice}
                </p>
              )}
            </section>
          ) : null}

          {step === 'variants' ? (
            <section className={styles.panel}>
              <h2 className={styles.panelTitle}>صيغ الملفّ</h2>
              {advanced ? (
                <p className={styles.panelNote}>
                  الصيغة وحدة البيع: لكلٍّ سعرها وملفّها المُسلَّم. وصيغةٌ ثانية تُلزم ربط كلّ ملفٍّ
                  قابلٍ للتسليم بصيغته، وإلّا سُلِّم ملفُّ غيرها لمشتريها.
                </p>
              ) : null}
              <VariantsPanel productId={product.id} canEdit={canEdit} />
            </section>
          ) : null}

          {/*
            * §٤٣ — مكوّنات الحزمة، تحت «صيغ الملفّ» ولحزمةٍ وحدها.
            *
            * عرضُها لكلّ منتج كان يُري المشغّل سطحًا لا يخصّه ويُوهم أنّ كلّ
            * منتجٍ قد يكون حزمة. وهي تلي الصيغ لأنّ المكوّن **صيغةٌ**: يُنسخ
            * معرّفُها من هناك.
            */}
          {step === 'variants' && form.productNature === 'COMPOSITE' ? (
            <section className={styles.panel}>
              <h2 className={styles.panelTitle}>مكوّنات الحزمة</h2>
              {advanced ? (
                <p className={styles.panelNote}>
                  الحزمة تُشترى مرّةً وتُوسَّع عند الدفع إلى بندٍ لكلّ مكوّن، فيسلك كلٌّ منها مسار
                  نوعه: الرقميّ يُنزَّل والماديّ يُشحن. وتسليمُ الحزمة اتّحادُ تسليم مكوّناتها،
                  فيُحسب تلقائيًّا عند كلّ تغيير.
                </p>
              ) : null}
              <BundlePanel productId={product.id} canEdit={canEdit} />
            </section>
          ) : null}

          {step === 'files' ? (
            <section className={styles.panel}>
              <h2 className={styles.panelTitle}>الملفات</h2>
              {advanced ? (
                <p className={styles.panelNote}>
                  الرفع يمرّ بـ<code dir="ltr">AssetImporter</code> ويُخزَّن خاصًّا؛ لا رابط تحميل يُصدَّر من
                  اللوحة — التسليم يبقى بمنحة موقّعة بعد شراء مُثبَت.
                </p>
              ) : null}
              <FilesPanel productId={product.id} canEdit={canEdit} />
            </section>
          ) : null}

          {/*
            * §١٣ — مصادر Drive تحت الملفّات لا بدلًا عنها.
            *
            * المرفوع بايتاتٌ نملكها، وملفّ Drive يملكه غيرنا فله حالةُ
            * مزامنةٍ ليست للمرفوع. قسمان في خطوةٍ واحدة لأنّ السؤال واحد
            * («ما الذي يُسلَّم؟») والعلاج مختلف.
            */}
          {step === 'files' ? (
            <section className={styles.panel}>
              <h2 className={styles.panelTitle}>ملفّات Google Drive</h2>
              {advanced ? (
                <p className={styles.panelNote}>
                  يُخزَّن معرّف الملفّ لا رابطه: الروابط تحمل معاملات مشاركةٍ تنتهي، والمعرّف يبقى عبر
                  إعادة التسمية والنقل. وتُجلب نسخةٌ إلى تخزيننا عند الربط، فلا يتوقّف تسليم ما بِيع
                  على بقاء الملفّ عندهم.
                </p>
              ) : null}
              <DrivePanel productId={product.id} canEdit={canEdit} />
            </section>
          ) : null}

          {step === 'media' ? (
            <section className={styles.panel}>
              <h2 className={styles.panelTitle}>الوسائط</h2>
              {advanced ? (
                <p className={styles.panelNote}>
                  النصّ البديل يوصف الصورة لا يحشوها بالكلمات — والحقل فارغ أصدق من وصف مخترع. الغلاف
                  يُنقَل ولا يُلغى: منتج بصور وبلا غلاف يُصيَّر فراغًا في البطاقة.
                </p>
              ) : null}
              <MediaPanel productId={product.id} canEdit={canEdit} />
            </section>
          ) : null}

          {step === 'search' ? (
            <section className={styles.panel}>
              <div className={styles.panelHead}>
                <h2 className={styles.panelTitle}>البحث والوصف</h2>
                <span className={styles.tagNew}>المخرَج يملكه الخادم</span>
              </div>
              {advanced ? (
                <p className={styles.panelNote}>
                  الاستوديو لا يطبع وسمًا: يكتب في <code dir="ltr">product_seo</code> عبر{' '}
                  <code dir="ltr">PATCH /admin/products/{'{id}'}/seo</code>، ويبقى{' '}
                  <code dir="ltr">MetadataService</code> مالك المخرَج — لذلك يُعرض ما كُتب وما سيُصيَّر
                  معًا: المكتوب قد يُقصّ أو يُستبدَل بسلسلة احتياطية.
                </p>
              ) : null}

              <SearchFields
                seo={seoDoc}
                canEdit={canEdit}
                slug={form.slug}
                onSlugChange={(value) => update('slug', value)}
              />

              {/*
                المعاينة تتبع سلسلة المصادر نفسها التي يتبعها الخادم: العنوان
                المخصَّص ثم عنوان المنتج، والوصف المخصَّص ثم المختصر ثم الكامل.
                معاينة تتجاهل ما كُتب في تهيئة البحث تناقض الجدول الذي فوقها.
              */}
              {(() => {
                const previewTitle = (seoDoc.draft?.seoTitle ?? '') !== '' ? seoDoc.draft!.seoTitle! : form.title;
                const previewDesc =
                  (seoDoc.draft?.seoDesc ?? '') !== ''
                    ? seoDoc.draft!.seoDesc!
                    : form.shortDesc !== ''
                      ? form.shortDesc
                      : form.description;
                return (
                  <div className={styles.serp}>
                    <span className={styles.serpCaption}>
                      معاينة من المسوّدة المعروضة — المُصيَّر الفعلي في الجدول أعلاه
                    </span>
                    <span className={styles.serpPath}>
                      {product.categoryPath.length > 0 ? product.categoryPath.join(' › ') : 'لا مسار فئة بعد'} ›{' '}
                      <bdi dir="ltr">/products/{form.slug}</bdi>
                    </span>
                    <span className={styles.serpTitle}>
                      {previewTitle === '' ? '— بلا عنوان —' : previewTitle}
                    </span>
                    <span className={styles.serpDesc}>
                      {previewDesc === ''
                        ? 'لا وصف بحث ولا مختصر ولا كامل — الخادم لن يجد نصًّا يبني منه وصف البحث.'
                        : previewDesc}
                    </span>
                    <span className={styles.serpCounts}>
                      العنوان <bdi dir="ltr">{previewTitle.length}</bdi>/
                      <bdi dir="ltr">{SEO_BUDGET.title}</bdi> · الوصف{' '}
                      <bdi dir="ltr">{previewDesc.length}</bdi>/
                      <bdi dir="ltr">{SEO_BUDGET.description}</bdi> — الميزانيتان من
                      <code dir="ltr"> TextBudget</code> على الخادم.
                    </span>
                  </div>
                );
              })()}

            </section>
          ) : null}

          {step === 'intl' ? (
            <section className={styles.panel}>
              <h2 className={styles.panelTitle}>الانتشار الدولي</h2>
              {advanced ? (
                <p className={styles.panelNote}>
                  الأهلية والعدد يحسبهما <code dir="ltr">Domain\I18n\LanguageEligibility</code> من صفوف
                  حقيقية. وإخراج <bdi dir="ltr">hreflang</bdi> يشترط نظيرًا منشورًا يخدمه المسار — لذلك
                  قد تكون اللغة مستوفية ولا تُعلَن بعد، وهذا معروض صراحةً لا مُخفى.
                </p>
              ) : null}
              <IntlPanel productId={product.id} canEdit={canEdit} canPublish={canPublish} />
            </section>
          ) : null}

          {step === 'coverage' ? (
            <section className={styles.panel}>
              <h2 className={styles.panelTitle}>تغطية التخصّصات</h2>
              {advanced ? (
                <p className={styles.panelNote}>
                  الحكم من <code dir="ltr">Domain\Catalog\CoverageEngine</code>: أبعاد الكتالوج نفسها لا
                  درجة مخترَعة. بلا مرحلة أو مادة لا يصدر حكم أصلًا — «بيانات غير كافية» أصدق من
                  «غير مغطّى».
                </p>
              ) : null}
              <CoveragePanel productId={product.id} onGoTo={goToStep} />
            </section>
          ) : null}

          {step === 'ai' ? (
            <section className={styles.panel}>
              <div className={styles.panelHead}>
                <h2 className={styles.panelTitle}>مساعد الكتابة</h2>
                <span className={cx(styles.tagNew, qwenOnline && styles.tagKnown)}>
                  {qwenOnline ? 'مفعَّل' : 'متوقّف'}
                </span>
              </div>
              <WritingAssistant
                productId={product.id}
                canEdit={canEdit}
                online={qwenOnline}
                currentValue={(field) => currentWriting(field)}
                onAccept={acceptSuggestion}
              />
            </section>
          ) : null}

          {/*
            * مساعد المنتج بجوار مساعد الكتابة لا بديلًا عنه: الأوّل محرّكٌ
            * محلّيّ يقترح تصنيفًا وعبارات بحثٍ ونصَّ ظهورٍ في الخلفيّة، والثاني
            * يقترح صياغاتٍ للحقل الذي يحرّره المشرف الآن. خطوةٌ ثالثة لهما
            * كانت ستُفرّق ما يفعله المشرف في لحظةٍ واحدة.
            */}
          {step === 'ai' ? (
            <section className={styles.panel}>
              <ProductCopilot productId={product.id} canEdit={canEdit} />
            </section>
          ) : null}

          {step === 'publish' ? (
            <>
              <section className={styles.panel}>
                <div className={styles.panelHead}>
                  <h2 className={styles.panelTitle}>ملكية مخرجات البحث</h2>
                  {/*
                    مفتاح المساعد تفضيل واجهة لا إعداد خادم: مطفأً لا يُرسَل نداء
                    توليد أصلًا. لا يدّعي تعطيل مزوّد على الخادم — لا نقطة نهاية
                    لذلك، وادّعاؤه يجعل مشرفًا يظنّ أنه أوقف ما لم يوقفه.
                  */}
                  <button
                    type="button"
                    className={styles.qwenToggle}
                    aria-pressed={qwenOnline}
                    onClick={() => setQwenOnline((current) => !current)}
                  >
                    {qwenOnline ? 'إيقاف مساعد الكتابة في هذه الجلسة' : 'تشغيل مساعد الكتابة'}
                  </button>
                </div>
                {advanced ? (
                  <p className={styles.panelNote}>
                    مالك واحد لكل مخرَج. الاستوديو يغذّي المالك القائم ولا يطبع وسمًا بنفسه — كاتب
                    ثانٍ يُنتج وسمين متعارضين.
                  </p>
                ) : null}
                {/*
                  طوق التمرير: ثلاثة أعمدة نصّيّة أحدها مسار صنف PHP كامل، وبلا
                  الطوق يدفع الجدولُ الوثيقةَ كلَّها على الشاشات الضيّقة
                  (globals.css §.table-scroll). و`tabIndex` ترميزٌ لا نمط.
                */}
                <div className="table-scroll" tabIndex={0} role="region" aria-label="جدول ملكيّة مخرَجات تهيئة البحث">
                  <table className={styles.owners}>
                    <thead>
                      <tr>
                        <th scope="col">المخرَج</th>
                        <th scope="col">المالك</th>
                        <th scope="col">الملاحظة</th>
                      </tr>
                    </thead>
                    <tbody>
                      {SEO_OWNERSHIP.map((row) => (
                        <tr key={row.output} className={row.owned ? undefined : styles.ownerBlocked}>
                          <th scope="row">{row.output}</th>
                          <td>
                            <code dir={row.ownerDir}>{row.owner}</code>
                          </td>
                          <td>{row.note}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>

              <section className={styles.panel}>
                <h2 className={styles.panelTitle}>بوابة النشر</h2>
                <p className={styles.panelNote}>
                  البوابة تُحسب على الخادم عند كل نداء من صفوف حقيقية. إن رُفض النشر يعود 422 بقائمة
                  الناقص، وهي القائمة المعروضة في اللوح الجانبي.
                </p>
                <ul className="checklist">
                  {readiness.items.map((item) => (
                    <li key={item.key} className={item.ok ? 'done' : 'todo'}>
                      <span aria-hidden="true">{item.ok ? '✓' : '×'}</span>
                      <span>{item.label}</span>
                      <span className="sr-only">{item.ok ? 'مكتمل' : 'ناقص'}</span>
                    </li>
                  ))}
                </ul>
              </section>
            </>
          ) : null}

          <div className={styles.actions}>
            <button type="button" className="btn btn-primary" disabled={!canEdit || saving} onClick={() => void save()}>
              {saving ? 'جارٍ الحفظ…' : 'حفظ'}
            </button>
            {canEdit ? null : <span className="absent">دورك لا يملك صلاحية التعديل.</span>}
          </div>
        </main>

        <aside className={styles.aside}>
          <section className={styles.card}>
            <h2 className={styles.cardTitle}>جاهزية النشر</h2>
            <div className={styles.meter}>
              <span className={styles.meterTrack}>
                <span
                  className={cx(styles.meterFill, readiness.ready && styles.meterReady)}
                  style={{ inlineSize: `${pct}%` }}
                />
              </span>
              <bdi dir="ltr" className={cx(styles.meterValue, readiness.ready && styles.meterValueReady)}>
                {pct}%
              </bdi>
            </div>
            <p className={styles.meterNote}>
              <bdi dir="ltr">{count(readiness.completedCount)}</bdi> من{' '}
              <bdi dir="ltr">{count(readiness.totalCount)}</bdi> بندًا — من الخادم لا من الواجهة.
            </p>
            <ul className={styles.gates}>
              {readiness.items.map((item) => (
                <li key={item.key} className={item.ok ? styles.gateOk : styles.gateBad}>
                  <span aria-hidden="true">{item.ok ? '✓' : '×'}</span>
                  <span>{item.label}</span>
                  <span className="sr-only">{item.ok ? 'مكتمل' : 'ناقص'}</span>
                </li>
              ))}
            </ul>

            {product.status === 'published' ? (
              <p className={styles.publishedNote} role="status">
                المنتج منشور بالفعل.
              </p>
            ) : (
              <>
                <button
                  type="button"
                  className={styles.publish}
                  disabled={!canPublish || !readiness.ready || saving}
                  onClick={() => void publish()}
                >
                  {readiness.ready ? 'نشر المنتج' : 'أكمل الحقول المطلوبة'}
                </button>
                {!canPublish ? (
                  <p className={styles.publishReason}>دورك لا يملك صلاحية النشر.</p>
                ) : !readiness.ready ? (
                  <p className={styles.publishReason}>
                    الناقص: {readiness.missingLabels.join('، ')}.
                  </p>
                ) : null}
              </>
            )}

            {/*
              الحفظ بلا نشر: PATCH /admin/products/{id} لا يغيّر الحالة، فالمنتج
              يبقى مسوّدة. الزرّ يقول ما يفعل تمامًا — لا «حفظ» غامض في عمود آخر.
            */}
            <button
              type="button"
              className={styles.draftSave}
              disabled={!canEdit || saving}
              onClick={() => void save()}
            >
              {saving ? 'جارٍ الحفظ…' : 'حفظ كمسوّدة'}
            </button>
            <p className={styles.publishReason}>
              يحفظ حقول المنتج دون تغيير حالته. تهيئة البحث والترجمات لكلٍّ زرّ حفظه في خطوته.
            </p>
          </section>
        </aside>
      </div>
    </>
  );
}

/* ───── عناصر مساعدة ───── */

export { Field } from './_field';
