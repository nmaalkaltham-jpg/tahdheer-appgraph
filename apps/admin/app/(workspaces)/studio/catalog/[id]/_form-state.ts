// UNIT: _FORM-STATE
// FILE: _form-state.ts
// STYLE: none
// PARENT: STUDIO-CLIENT
// USES: CATALOG-TERMS, LIB-ADMIN-API, LIB-FORMAT, MASTER-TREE, STUDIO-DATA
// API: none
// MAP: docs/PROJECT_MAP.md

import type { CatalogTerm, Readiness } from '../../../../../lib/admin-api';
import { minorToRiyals, riyalsToMinor } from '../../../../../lib/format';
import type { GradeTerm } from '../catalog-terms';
import { isoToLocalInput, type StepKey, type StudioProduct } from '../studio-data';
import { branchesFor } from './master-tree';

/** قوائم فارغة ثابتة المرجع — انظر شرح الاشتقاق أدناه. */
export const EMPTY_TERMS: readonly CatalogTerm[] = [];
export const EMPTY_GRADES: readonly GradeTerm[] = [];

export interface FactRow {
  key: string;
  value: string;
}

export interface FormState {
  title: string;
  /**
   * §٦ — طبيعة المنتج. فارغةٌ تعني «منتجٌ سبق النموذج الكونيّ».
   *
   * تُرسَل عند التبديل وحده: إرسالُها في كلّ حفظٍ كان يُعيد كتابة
   * استراتيجيّات التسليم كلّها عند كلّ تعديلِ عنوان.
   */
  productNature: string;
  slug: string;
  sku: string;
  subtitle: string;
  shortDesc: string;
  description: string;
  stageId: string;
  gradeId: string;
  subjectId: string;
  semesterId: string;
  resourceType: string;
  format: string;
  editable: string;
  regularRiyals: string;
  saleRiyals: string;
  saleFrom: string;
  saleTo: string;
  facts: FactRow[];
}

export type StepState = 'done' | 'todo';

export const EDITABLE_OPTIONS: { value: string; label: string }[] = [
  { value: 'yes', label: 'نعم' },
  { value: 'no', label: 'لا' },
  { value: 'unset', label: 'غير محدَّد' },
];

/* ───── اشتقاقات ───── */

export function stepStatesFor(
  product: StudioProduct,
  form: FormState,
  readiness: Readiness,
): Record<StepKey, StepState> {
  const branches = branchesFor(product);
  const measurable = branches.filter((branch) => branch.state !== 'external');
  const masterDone = measurable.length > 0 && measurable.every((branch) => branch.state === 'complete');
  const classified =
    form.stageId !== '' && form.gradeId !== '' && form.subjectId !== '' && form.semesterId !== '';
  const regular = riyalsToMinor(form.regularRiyals);

  return {
    master: masterDone ? 'done' : 'todo',
    basics: form.title.trim() !== '' && form.slug.trim() !== '' ? 'done' : 'todo',
    edu: classified ? 'done' : 'todo',
    price: regular !== null && regular > 0 ? 'done' : 'todo',
    /*
     * الصيغ: الحكم يعيش على الخادم (كم صيغةً وكم ملفًّا مربوطًا بها) ويصل
     * بنداءٍ مستقلّ للوحه. عرضه هنا يكلّف نداءً إضافيًّا عند فتح الشاشة
     * لأجل نقطةٍ في الشريط — كما في `intl` و`coverage` و`ai` أدناه.
     */
    variants: 'todo',
    files: product.fileCount > 0 ? 'done' : 'todo',
    media: product.imageCount > 0 ? 'done' : 'todo',
    search:
      form.slug.trim() !== '' && (form.shortDesc.trim() !== '' || form.description.trim() !== '')
        ? 'done'
        : 'todo',
    /*
     * الحكم على هذه الثلاث يعيش على الخادم ويصل بنداء مستقلّ لكل لوح. عرضه
     * هنا يحتاج ثلاثة نداءات إضافية عند فتح الشاشة لأجل ثلاث نقاط ملوّنة —
     * فتبقى «مفتوحة» حتى يفتحها المشغّل، ولا تدّعي اكتمالًا لم يُقَس.
     */
    intl: 'todo',
    coverage: 'todo',
    ai: 'todo',
    publish: readiness.ready ? 'done' : 'todo',
  };
}

export function toForm(product: StudioProduct): FormState {
  return {
    productNature: product.productNature ?? '',
    title: product.title,
    slug: product.slug,
    sku: product.sku,
    subtitle: product.subtitle ?? '',
    shortDesc: product.shortDesc ?? '',
    description: product.description ?? '',
    stageId: product.stageId === null ? '' : String(product.stageId),
    gradeId: product.gradeId === null ? '' : String(product.gradeId),
    subjectId: product.subjectId === null ? '' : String(product.subjectId),
    semesterId: product.semesterId === null ? '' : String(product.semesterId),
    resourceType: product.resourceType ?? '',
    format: product.format ?? '',
    editable: product.editableState,
    regularRiyals: minorToRiyals(product.regularMinor ?? null),
    saleRiyals: minorToRiyals(product.saleMinor),
    saleFrom: isoToLocalInput(product.saleFrom),
    saleTo: isoToLocalInput(product.saleTo),
    facts: product.facts.map((fact) => ({
      key: fact.key,
      value: fact.intValue !== null ? String(fact.intValue) : (fact.textValue ?? ''),
    })),
  };
}
