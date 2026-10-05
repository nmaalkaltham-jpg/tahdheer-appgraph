'use client';
// UNIT: NEW-STUDIO
// FILE: new-studio.tsx
// STYLE: studio.module.css
// PARENT: PAGE-STUDIO-CATALOG-NEW
// USES: ADMIN-SESSION, CATALOG-TERMS, LIB-ADMIN-API, LIB-FORMAT, PRODUCT-TYPES, RESOURCE +2
// API: POST /admin/products
// MAP: docs/PROJECT_MAP.md

/**
 * منتج جديد = استوديو المنتج — لا شاشةَ إنشاءٍ ثانية.
 *
 * ─── العيب الذي يزيله ───
 *
 * كان للمنتج سطحان: نموذج إنشاءٍ من ستّمئة سطر («اسم · سعر · ملف · نشر»)
 * واستوديو من إحدى عشرة خطوة. والسطحان يقولان الشيء نفسه بكلماتٍ مختلفة:
 * حقلُ السعر في أحدهما «السعر» وفي الآخر «السعر الأساسيّ»، والتصنيف قائمةٌ
 * هنا وشجرةٌ هناك. فيتعلّم المشغّل الإنشاء مرّة والتحرير مرّة، ويكتشف بعد
 * أوّل منتج أنّ ما تعلّمه لا ينفعه.
 *
 * وأخطر من التعلّم المزدوج: **قاعدتان للصحّة**. النموذج كان يقرّر بنفسه ما
 * يكفي لإنشاء منتج، والاستوديو يقرأ بوّابة الجاهزية من الخادم. فيُنشئ
 * المشغّل منتجًا يراه النموذج تامًّا ويراه الاستوديو ناقصًا.
 *
 * ─── الوحدة ───
 *
 * الإنشاء صار **الخطوة الأولى من الاستوديو**: الرأس نفسه، وشريط الخطوات
 * نفسه، والحقول نفسها. وما يلي الأساسيّات معروضٌ ومعطَّل بسببٍ مكتوب — لا
 * مخفيٌّ: المشغّل يرى الطريق كاملًا قبل أن يبدأه.
 *
 * وأوّل حفظٍ يُنشئ المنتج ثمّ **يُبدّل العنوان في مكانه** إلى
 * `/studio/catalog/{id}` بلا إعادة تحميل — فلا يشعر المشغّل بانتقال، ولا
 * يترك زرّ الرجوع خلفه عنوانًا يُعيد إنشاءً ثانيًا.
 */

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useMemo, useState } from 'react';
import { useCatalogTerms } from '../catalog-terms';
import { natureOf, strategiesOf, useProductTypes } from '../product-types';
import { useSession } from '../../../../../components/admin-session';
import { PageHeader } from '../../../../../components/resource';
import { api, firstError, type ProductRow } from '../../../../../lib/admin-api';
import { riyalsToMinor } from '../../../../../lib/format';
import { Field } from '../[id]/_field';
import styles from '../[id]/studio.module.css';
import { cx, STEPS } from '../studio-data';

/**
 * رابط عربي صالح للعنوان.
 *
 * الحروف العربية تمرّ كما هي — وهي المقصودة. وتُسقَط محارف العناوين ذات
 * المعنى (`# ? % & +` والشرطة العكسية والمسافة والشرطة المائلة) لأنّ الرابط
 * يُركَّب في عناوين المتجر كما هو: عنوانٌ فيه `#` يقطع المسار عند تلك النقطة.
 */
function slugify(title: string): string {
  return title
    .trim()
    .replace(/[\s/#?%&+\\]+/g, '-')
    .replace(/-{2,}/g, '-')
    .replace(/^-|-$/g, '');
}

export default function NewProductStudio() {
  const router = useRouter();
  const { can } = useSession();
  const canEdit = can('products.create');

  const [title, setTitle] = useState('');
  const [slugTouched, setSlugTouched] = useState(false);
  const [slug, setSlug] = useState('');
  const [sku, setSku] = useState('');
  const [subtitle, setSubtitle] = useState('');
  const [shortDesc, setShortDesc] = useState('');
  const [regular, setRegular] = useState('');
  const [stageId, setStageId] = useState('');
  const [gradeId, setGradeId] = useState('');
  const [subjectId, setSubjectId] = useState('');
  const [semesterId, setSemesterId] = useState('');
  /*
   * §٦ — النوع. لا افتراضَ له.
   *
   * قيمةٌ ابتدائيّة («رقميّ» مثلًا) كانت ستُنتج منتجًا ماديًّا مُنشأً رقميًّا
   * لمن لم ينتبه — وهو أسوأ من حقلٍ فارغ يوقف الحفظ، لأنّه يمرّ بصمتٍ ثمّ
   * يُكتشف عند الشحن. والحقل جزءٌ من شرط `ready` أدناه.
   */
  const [nature, setNature] = useState('');
  const [strategies, setStrategies] = useState<string[]>([]);

  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  /*
   * القوائم من الخطّاف المشترك — لا نسخةٌ ثانية.
   *
   * كان هنا جلبٌ محلّيّ بثلاث قوائم يكتب في مفتاح الذاكرة نفسه الذي يكتب
   * فيه الاستوديو بخمس. فأيّهما يُركَّب أوّلًا يملأ الذاكرة، ويقرأ الآخر
   * شكلًا لا يتوقّعه — قائمةٌ تظهر فارغةً بحسب ترتيب الزيارة.
   */
  const terms = useCatalogTerms();

  /* الرابط يتبع الاسم حتّى يلمسه المشغّل — بعدها هو صاحبه. */
  const effectiveSlug = slugTouched ? slug : slugify(title);
  const types = useProductTypes();
  const chosen = natureOf(types.data, nature);
  const available = strategiesOf(types.data, nature);

  /*
   * الهجين وحده يوجب اختيارًا صريحًا: تسليمٌ رقميّ وآخر ماديّ معًا، وإلّا
   * فهو ليس هجينًا. وما عداه يأخذ اقتراح طبيعته من الخادم.
   */
  const needsExplicitDelivery = chosen?.defaultStrategy === null;
  const deliveryChosen = !needsExplicitDelivery || strategies.length > 0;

  const ready = title.trim() !== '' && effectiveSlug !== '' && nature !== '' && deliveryChosen;

  const create = useCallback(async () => {
    if (!ready || busy) return;

    setBusy(true);
    setFailure(null);
    setFieldErrors({});

    const regularMinor = regular.trim() === '' ? null : riyalsToMinor(regular);

    const result = await api.post<{ data: ProductRow }>('/admin/products', {
      ...(sku.trim() === '' ? {} : { sku: sku.trim() }),
      slug: effectiveSlug,
      title: title.trim(),
      ...(subtitle.trim() === '' ? {} : { subtitle: subtitle.trim() }),
      ...(shortDesc.trim() === '' ? {} : { shortDesc: shortDesc.trim() }),
      ...(regularMinor === null ? {} : { regularMinor }),
      productNature: nature,
      ...(strategies.length === 0 ? {} : { fulfillmentStrategies: strategies }),
      ...(stageId === '' ? {} : { stageId: Number(stageId) }),
      /*
       * الصفّ كان غائبًا عن هذا النموذج وتطلبه بوّابة الجاهزية — فكلّ منتجٍ
       * يُنشأ هنا كان يفشل في بندٍ لم تعرضه عليه شاشةُ إنشائه.
       */
      ...(gradeId === '' ? {} : { gradeId: Number(gradeId) }),
      ...(subjectId === '' ? {} : { subjectId: Number(subjectId) }),
      ...(semesterId === '' ? {} : { semesterId: Number(semesterId) }),
    });

    if (!result.ok) {
      setBusy(false);
      setFieldErrors(result.errors ?? {});
      setFailure(result.errors ? null : firstError(result));

      return;
    }

    /*
     * `replace` لا `push`: عنوان «جديد» يجب ألّا يبقى في تاريخ التصفّح.
     * زرُّ الرجوع إليه بعد الإنشاء يفتح نموذجًا فارغًا فيظنّه المشغّل ضياعًا
     * لما كتبه — وقد أُنشئ المنتج فعلًا. ونقطة الدخول التالية «الملفّات»:
     * بوّابة الجاهزية تشترط ملفًا، وهي أقرب خطوةٍ ناقصة بعد الإنشاء.
     */
    router.replace(`/studio/catalog/${result.data.data.id}?step=files`);
  }, [
    ready, busy, sku, effectiveSlug, title, subtitle, shortDesc,
    regular, stageId, gradeId, subjectId, semesterId, nature, strategies, router,
  ]);

  const stages = terms.data?.stages ?? [];
  /* الصفوف تتبع المرحلة — عرضُ صفوف كلّ المراحل معًا يجعل الاختيار ركامًا. */
  const grades = (terms.data?.grades ?? []).filter(
    (grade) => stageId === '' || String(grade.stage.id) === stageId,
  );
  const subjects = terms.data?.subjects ?? [];
  const semesters = terms.data?.semesters ?? [];

  const firstFieldError = useMemo(
    () => (key: string) => fieldErrors[key]?.[0] ?? null,
    [fieldErrors],
  );

  return (
    <section>
      <PageHeader
        trail="المتجر › استوديو المنتج"
        title="استوديو المنتج"
        actions={
          <Link className="btn btn-ghost" href="/studio/catalog">
            رجوع للقائمة
          </Link>
        }
      />

      <div className={styles.head}>
        <div className={styles.headText}>
          <div className={styles.headRow}>
            <span className={styles.badge}>PRODUCT STUDIO</span>
            <span className={styles.headTitle}>{title.trim() === '' ? 'منتج جديد' : title}</span>
            <span className="chip chip-draft">مسوّدة — لم يُنشأ بعد</span>
          </div>
          <p className={styles.headNote}>
            الإنشاء هو الخطوة الأولى من الاستوديو لا شاشةٌ أخرى. أوّل حفظٍ يكتب في{' '}
            <code dir="ltr">POST /admin/products</code> ويفتح بقيّة الخطوات على المنتج نفسه.
          </p>
        </div>
      </div>

      <div className={styles.grid}>
        {/*
          * شريط الخطوات كاملًا — والمعطَّل يبقى مرئيًّا.
          *
          * إخفاؤه كان سيجعل المشغّل يظنّ الاستوديو خطوتين، ثمّ تظهر تسعٌ
          * بعد الإنشاء. ورؤية الطريق قبل بدئه جزءٌ من فهمه.
          */}
        <nav className={styles.steps} aria-label="أقسام المنتج">
          {STEPS.map((definition, index) => {
            const first = definition.key === 'basics';

            return (
              <button
                key={definition.key}
                type="button"
                className={cx(styles.step, first && styles.stepOn)}
                aria-current={first ? 'step' : undefined}
                disabled={!first}
                title={first ? undefined : 'تُفتح بعد إنشاء المنتج'}
              >
                <span aria-hidden="true" className={cx(styles.stepDot, styles.dot_todo)}>
                  {index + 1}
                </span>
                <span className={styles.stepLabel}>{definition.label}</span>
                <span className="sr-only">
                  {first ? 'الخطوة الحاليّة' : 'تُفتح بعد إنشاء المنتج'}
                </span>
              </button>
            );
          })}
        </nav>

        <main className={styles.main}>
          {failure !== null ? (
            <p className="state-error" role="alert">
              {failure}
            </p>
          ) : null}

          {/*
            * §٦ — نوع المنتج، أوّل قرارٍ لا آخره.
            *
            * موضعُه قبل الأساسيّات مقصود: هو الذي يقرّر ما يلي — أيّ الحقول
            * تلزم، وما توجبه بوّابة النشر، وكيف يُسلَّم. واختيارُه بعد ملء
            * النموذج كان سيُبدّل ما تحته بعد كتابته.
            *
            * والقائمة تأتي من `GET /admin/product-types` لا من جدولٍ هنا:
            * طبيعةٌ تُضاف في الخادم تظهر هنا بلا تعديل واجهة.
            */}
          <section className={styles.panel}>
            <h2 className={styles.panelTitle}>نوع المنتج</h2>
            <p className={styles.panelNote}>
              يقرّر ما يلزم هذا المنتج وكيف يصل المشتري. يُبدَّل لاحقًا من الاستوديو، وتبديله
              يُعيد كتابة طريقة التسليم — فاختره الآن ولا تؤجّله.
            </p>

            {types.isError ? (
              <p className="state-error" role="alert">
                تعذّر تحميل أنواع المنتج من الخادم. لا يمكن الإنشاء قبل اختيار النوع.
              </p>
            ) : null}

            <div className={styles.fields}>
              <Field
                id="n-nature"
                label="النوع"
                hint={firstFieldError('productNature') ?? chosen?.label ?? 'مطلوب.'}
                wide
              >
                <select
                  id="n-nature"
                  value={nature}
                  disabled={!canEdit || busy || types.isPending}
                  onChange={(event) => {
                    setNature(event.target.value);
                    /* استراتيجيّةٌ من نوعٍ سابق لا تليق بالجديد — تُمسح لا تُترك. */
                    setStrategies([]);
                  }}
                >
                  <option value="">— اختر النوع —</option>
                  {(types.data?.natures ?? []).map((item) => (
                    <option key={item.value} value={item.value}>
                      {item.label}
                    </option>
                  ))}
                </select>
              </Field>
            </div>

            {chosen !== undefined ? (
              <>
                <p className={styles.panelNote}>
                  {chosen.requiresDeliverableFile
                    ? 'يوجب ملفًّا قابلًا للتنزيل قبل النشر.'
                    : 'لا يوجب ملفًّا — بوّابة النشر لا تطلبه.'}{' '}
                  {chosen.requiresPhysicalAttributes
                    ? 'ويوجب وزن الصيغة، لأنّ الشحن يرفض ما لا وزن له.'
                    : ''}{' '}
                  {chosen.limitsQuantityToOne ? 'والكميّة محدودةٌ بواحد.' : ''}
                </p>

                <div className={styles.fields}>
                  <Field
                    id="n-delivery"
                    label="طريقة التسليم"
                    hint={
                      firstFieldError('fulfillmentStrategies')
                      ?? (needsExplicitDelivery
                        ? 'الهجين يوجب اختيارًا صريحًا: تسليمًا رقميًّا وماديًّا معًا.'
                        : 'يُترك فارغًا فيأخذ اقتراح النوع من الخادم.')
                    }
                    wide
                  >
                    <div role="group" aria-labelledby="n-delivery">
                      {available.map((strategy) => (
                        <label key={strategy.value} className={styles.checkRow}>
                          <input
                            type="checkbox"
                            checked={strategies.includes(strategy.value)}
                            disabled={!canEdit || busy}
                            onChange={(event) =>
                              setStrategies((current) =>
                                event.target.checked
                                  ? [...current, strategy.value]
                                  : current.filter((value) => value !== strategy.value),
                              )
                            }
                          />
                          <span>{strategy.label}</span>
                          {/*
                            * استراتيجيّةٌ بلا منفّذٍ مسجَّل تبقى وحدتُها معلّقةً
                            * بسببٍ مكتوب بعد الدفع. قولُها هنا أصدق من اكتشافها
                            * بعد أن يدفع الزبون ولا يصله شيء.
                            */}
                          {!strategy.executable ? (
                            <span className={styles.checkNote}>لا منفّذ بعد — يبقى معلّقًا</span>
                          ) : null}
                        </label>
                      ))}
                    </div>
                  </Field>
                </div>
              </>
            ) : null}
          </section>

          <section className={styles.panel}>
            <h2 className={styles.panelTitle}>أساسيات المنتج</h2>
            <p className={styles.panelNote}>
              الاسم والرابط وحدهما مطلوبان للإنشاء. وما عداهما يُكمَل في خطوات الاستوديو — ولا
              يُنشر المنتج قبل أن تقبله بوّابة الجاهزية على الخادم.
            </p>

            <div className={styles.fields}>
              <Field id="n-title" label="اسم المنتج" hint={firstFieldError('title') ?? undefined}>
                <input
                  id="n-title"
                  dir="auto"
                  value={title}
                  disabled={!canEdit || busy}
                  onChange={(event) => setTitle(event.target.value)}
                />
              </Field>

              <Field
                id="n-slug"
                label="المُعرّف في الرابط"
                hint={firstFieldError('slug') ?? 'يتبع الاسم حتّى تُعدّله بنفسك.'}
              >
                <input
                  id="n-slug"
                  dir="ltr"
                  value={effectiveSlug}
                  disabled={!canEdit || busy}
                  onChange={(event) => {
                    setSlugTouched(true);
                    setSlug(event.target.value);
                  }}
                />
              </Field>

              <Field
                id="n-sku"
                label="الرمز"
                hint={firstFieldError('sku') ?? 'يُشتقّ من الخادم إن تُرك فارغًا.'}
              >
                <input
                  id="n-sku"
                  dir="ltr"
                  value={sku}
                  disabled={!canEdit || busy}
                  onChange={(event) => setSku(event.target.value)}
                />
              </Field>

              <Field id="n-subtitle" label="العنوان الفرعي">
                <input
                  id="n-subtitle"
                  dir="auto"
                  value={subtitle}
                  disabled={!canEdit || busy}
                  onChange={(event) => setSubtitle(event.target.value)}
                />
              </Field>

              <Field
                id="n-regular"
                label="السعر الأساسي (ر.س)"
                hint={firstFieldError('regularMinor') ?? undefined}
              >
                <input
                  id="n-regular"
                  dir="ltr"
                  inputMode="decimal"
                  value={regular}
                  disabled={!canEdit || busy}
                  onChange={(event) => setRegular(event.target.value)}
                />
              </Field>

              <Field id="n-short" label="وصف قصير" wide>
                <textarea
                  id="n-short"
                  dir="auto"
                  rows={2}
                  value={shortDesc}
                  disabled={!canEdit || busy}
                  onChange={(event) => setShortDesc(event.target.value)}
                />
              </Field>
            </div>
          </section>

          <section className={styles.panel}>
            <div className={styles.panelHead}>
              <h2 className={styles.panelTitle}>التصنيف التعليمي</h2>
            </div>
            <p className={styles.panelNote}>
              اختياريّ الآن ومطلوبٌ للنشر. القوائم هي نفسها التي يقرؤها الاستوديو — لا نسخةٌ ثانية
              تتباعد عنها.
            </p>

            {terms.isError ? (
              <p className="state-error" role="alert">
                تعذّر جلب قوائم التصنيف. يمكنك الإنشاء الآن وإكمال التصنيف في الاستوديو.
              </p>
            ) : null}

            <div className={styles.fields}>
              <Field id="n-stage" label="المرحلة">
                <select
                  id="n-stage"
                  value={stageId}
                  disabled={!canEdit || busy || terms.isPending}
                  onChange={(event) => {
                    setStageId(event.target.value);
                    /* صفٌّ من مرحلةٍ أخرى يبقى مختارًا فيُرسَل تصنيفٌ متناقض. */
                    setGradeId('');
                  }}
                >
                  <option value="">— بلا تحديد —</option>
                  {stages.map((term) => (
                    <option key={term.id} value={term.id}>
                      {term.name}
                    </option>
                  ))}
                </select>
              </Field>

              <Field
                id="n-grade"
                label="الصف"
                hint={stageId === '' ? 'اختر المرحلة أوّلًا.' : undefined}
              >
                <select
                  id="n-grade"
                  value={gradeId}
                  disabled={!canEdit || busy || terms.isPending || stageId === ''}
                  onChange={(event) => setGradeId(event.target.value)}
                >
                  <option value="">— بلا تحديد —</option>
                  {grades.map((term) => (
                    <option key={term.id} value={term.id}>
                      {term.name}
                    </option>
                  ))}
                </select>
              </Field>

              <Field id="n-subject" label="المادّة">
                <select
                  id="n-subject"
                  value={subjectId}
                  disabled={!canEdit || busy || terms.isPending}
                  onChange={(event) => setSubjectId(event.target.value)}
                >
                  <option value="">— بلا تحديد —</option>
                  {subjects.map((term) => (
                    <option key={term.id} value={term.id}>
                      {term.name}
                    </option>
                  ))}
                </select>
              </Field>

              <Field id="n-semester" label="الفصل">
                <select
                  id="n-semester"
                  value={semesterId}
                  disabled={!canEdit || busy || terms.isPending}
                  onChange={(event) => setSemesterId(event.target.value)}
                >
                  <option value="">— بلا تحديد —</option>
                  {semesters.map((term) => (
                    <option key={term.id} value={term.id}>
                      {term.name}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
          </section>

          <div className={styles.panel}>
            {!canEdit ? (
              <p className="state-error" role="alert">
                لا تملك صلاحية <bdi dir="ltr">products.create</bdi> لإنشاء منتج.
              </p>
            ) : (
              <button
                type="button"
                className="btn btn-primary"
                /* المنع أثناء الإنشاء: نقرتان تُنشئان منتجين. */
                disabled={!ready || busy}
                onClick={() => void create()}
              >
                {busy ? 'جارٍ الإنشاء…' : 'أنشئ وتابع في الاستوديو'}
              </button>
            )}
            <p className={styles.panelNote}>
              يُنشأ المنتج مسوّدةً ثمّ تُفتح خطوة «الملفات» — وهي أقرب شرطٍ ناقصٍ للنشر.
            </p>
          </div>
        </main>
      </div>
    </section>
  );
}
