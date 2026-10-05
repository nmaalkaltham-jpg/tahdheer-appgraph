// UNIT: MASTER-TREE
// FILE: master-tree.tsx
// STYLE: master-tree.module.css
// PARENT: STUDIO-CLIENT, _FORM-STATE
// USES: STUDIO-DATA
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * شجرة المنتج الأصل (TahMasterTree) — مصدر واحد للحقيقة.
 *
 * كل فرع يعلن أين يعيش فعلًا، وحالته محسوبة من حمولة المنتج القادمة من
 * /admin/products/{id} لا من قائمة ثابتة.
 *
 * فرعان يعيشان خارج هذه الحمولة (تهيئة البحث والترجمات) ولهما نقاط نهاية
 * قائمة. كانا معلَّمَين «غير موصول» وهو خطأ في الاتجاه المعاكس: القدرة
 * موجودة، والشجرة وحدها لا تقرؤها. فيُعلَنان «تُقاس في خطوتها» ولا يدخلان
 * عدّ هذه الشجرة — لا يُدّعى غياب بنية قائمة، ولا يُدّعى قياس لم يقع.
 */

import { cx, type StudioProduct } from '../studio-data';
import styles from './master-tree.module.css';

type BranchState = 'complete' | 'partial' | 'missing' | 'external';

interface Branch {
  label: string;
  source: string;
  sourceDir: 'ltr' | 'rtl';
  state: BranchState;
  detail: string;
}

const STATE_LABEL: Record<BranchState, string> = {
  complete: 'مكتملة',
  partial: 'جزئية',
  missing: 'ناقصة',
  external: 'تُقاس في خطوتها',
};

const STATE_MARK: Record<BranchState, string> = {
  complete: '✓',
  partial: '◐',
  missing: '—',
  external: '↗',
};

export function branchesFor(product: StudioProduct): Branch[] {
  const classified =
    product.stageId !== null &&
    product.gradeId !== null &&
    product.subjectId !== null &&
    product.semesterId !== null;
  const classifiedCount = [product.stageId, product.gradeId, product.subjectId, product.semesterId].filter(
    (value) => value !== null,
  ).length;

  const hasFacts = product.facts.length > 0;
  const hasBody = (product.description ?? '').trim() !== '';
  const hasShort = (product.shortDesc ?? '').trim() !== '';
  const hasPrice = (product.regularMinor ?? 0) > 0;

  return [
    {
      label: 'الحقائق الموثّقة',
      source: 'product_facts',
      sourceDir: 'ltr',
      state: hasFacts ? 'complete' : 'missing',
      detail: hasFacts ? `${product.facts.length} حقيقة مسجَّلة` : 'لا حقيقة واحدة مسجَّلة',
    },
    {
      label: 'بيانات المتجر',
      source: 'products.sku · product_prices',
      sourceDir: 'ltr',
      state: hasPrice ? 'complete' : 'partial',
      detail: hasPrice ? 'الرمز والسعر الأساسي مضبوطان' : 'الرمز موجود والسعر الأساسي غير مضبوط',
    },
    {
      label: 'التصنيف التعليمي',
      source: 'stages · grades · subjects · semesters',
      sourceDir: 'ltr',
      state: classified ? 'complete' : classifiedCount > 0 ? 'partial' : 'missing',
      detail: `${classifiedCount} من 4 مستويات محدَّدة`,
    },
    {
      label: 'المحتوى الأساسي',
      source: 'products.description · short_desc',
      sourceDir: 'ltr',
      state: hasBody && hasShort ? 'complete' : hasBody || hasShort ? 'partial' : 'missing',
      detail: hasBody && hasShort ? 'الوصفان المختصر والكامل مكتوبان' : 'أحد الوصفين ناقص',
    },
    {
      label: 'نيّة البحث',
      source: 'GET/PATCH /admin/products/{id}/seo',
      sourceDir: 'ltr',
      state: 'external',
      detail: 'تُحرَّر في خطوة «البحث والوصف»؛ حمولة هذه الشجرة لا تحملها',
    },
    {
      label: 'الوسائط',
      source: 'product_media',
      sourceDir: 'ltr',
      state: product.imageCount > 0 ? 'complete' : 'missing',
      detail: `${product.imageCount} صورة مرتبطة`,
    },
    {
      label: 'مصدر Schema',
      source: 'Domain\\SEO\\SchemaBuilder',
      sourceDir: 'ltr',
      state: classified && hasFacts ? 'complete' : 'partial',
      detail: classified && hasFacts ? 'الكيان يجد تصنيفًا وحقائق' : 'الكيان يُبنى من مدخلات ناقصة',
    },
    {
      label: 'ملفّات اللغات',
      source: 'GET /admin/products/{id}/translations',
      sourceDir: 'ltr',
      state: 'external',
      detail: 'تُحرَّر في خطوة «الانتشار الدولي»؛ حمولة هذه الشجرة لا تحملها',
    },
  ];
}

export default function MasterTree({ product, help }: { product: StudioProduct; help: boolean }) {
  const branches = branchesFor(product);
  const complete = branches.filter((branch) => branch.state === 'complete').length;
  const measurable = branches.filter((branch) => branch.state !== 'external').length;

  return (
    <section className={styles.tree} aria-labelledby="master-tree-title">
      <div className={styles.head}>
        <h2 id="master-tree-title" className={styles.title}>
          المنتج الأصل — مصدر واحد للحقيقة
        </h2>
        <span className={styles.tally}>
          <bdi dir="ltr">{complete}</bdi> من <bdi dir="ltr">{measurable}</bdi> فرعًا مقيسًا مكتمل
        </span>
      </div>

      {help ? (
        <p className={styles.help}>
          كل فرع يُقرأ من مكانه الحقيقي في حمولة المنتج. الفرعان اللذان يعيشان خلف نقطتَي نهاية
          أخريين معلَّمان «تُقاس في خطوتها» ولا يدخلان في هذا العدّ — قياسهما هناك لا هنا.
        </p>
      ) : null}

      <div className={styles.body}>
        <div className={styles.root}>
          <b className={styles.rootLabel}>المنتج الأصل</b>
          <span dir="auto" className={styles.rootName}>
            {product.title}
          </span>
          <bdi dir="ltr" className={styles.rootSku}>
            {product.sku}
          </bdi>
        </div>

        <span aria-hidden="true" className={styles.connector} />

        <ul className={styles.branches}>
          {branches.map((branch) => (
            <li key={branch.label} className={cx(styles.branch, styles[branch.state])}>
              <span aria-hidden="true" className={styles.mark}>
                {STATE_MARK[branch.state]}
              </span>
              <span className={styles.branchText}>
                <b className={styles.branchLabel}>{branch.label}</b>
                <code dir={branch.sourceDir} className={styles.branchSource} title={branch.source}>
                  {branch.source}
                </code>
                <span className={styles.branchDetail}>{branch.detail}</span>
              </span>
              <span className={styles.branchState}>{STATE_LABEL[branch.state]}</span>
            </li>
          ))}
        </ul>
      </div>

      <p className={styles.footNote}>
        <span>
          الفروع المقيسة أعلاه هي مدخلات بوابة النشر ومصدر Schema معًا. وما يظهر «تُقاس في خطوتها»
          له نقطته وزرّ حفظه في خطوته.
        </span>
        <span className={styles.footTag}>تخطيط لا نشر</span>
      </p>
    </section>
  );
}
