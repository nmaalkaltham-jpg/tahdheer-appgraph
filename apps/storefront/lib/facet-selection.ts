/**
 * اختيارُ الأوجه المتعدّد — حالتُه كلّها في الرابط.
 *
 * «أو» داخل البُعد و«و» بين الأبعاد (الخادم يطبّقها). في رابط المتجر تتكرّر
 * المفاتيح (`subject=أ&subject=ب`)، وإلى الواجهة الخلفيّة تُرسل `subject[]`
 * حين تكثر، وقيمةً مفردة حين تكون واحدة — فالروابط القديمة تعمل كما هي.
 */

export const MULTI_DIMS = ['stage', 'grade', 'subject', 'kind', 'semester'] as const;
export type MultiDim = (typeof MULTI_DIMS)[number];
export type FacetSelection = Partial<Record<MultiDim, string[]>>;

/** سقفُ الخادم لكلّ بُعد — رابطٌ مصنوع لا يتجاوزه. */
export const MAX_VALUES_PER_DIM = 10;

/** قيمُ المعاملات كما يُسلّمها Next (نصّ، أو قائمة عند التكرار) ⇐ اختيارٌ نظيف. */
export function readSelection(params: Partial<Record<string, string | string[] | undefined>>): FacetSelection {
  const out: FacetSelection = {};
  for (const dim of MULTI_DIMS) {
    const raw = params[dim];
    const list = (Array.isArray(raw) ? raw : raw === undefined ? [] : [raw])
      .map((v) => v.trim())
      .filter((v) => v !== '');
    const unique = [...new Set(list)].slice(0, MAX_VALUES_PER_DIM);
    if (unique.length > 0) out[dim] = unique;
  }
  return out;
}

export function isSelected(selection: FacetSelection, dim: MultiDim, value: string): boolean {
  return selection[dim]?.includes(value) ?? false;
}

/** يُضيف القيمة أو يُزيلها؛ البُعد الفارغ يسقط من الاختيار. */
export function toggleValue(selection: FacetSelection, dim: MultiDim, value: string): FacetSelection {
  const current = selection[dim] ?? [];
  const next = current.includes(value) ? current.filter((v) => v !== value) : [...current, value];
  const out: FacetSelection = { ...selection };
  if (next.length === 0) delete out[dim];
  else out[dim] = next.slice(0, MAX_VALUES_PER_DIM);
  return out;
}

export function selectedCount(selection: FacetSelection): number {
  return MULTI_DIMS.reduce((n, dim) => n + (selection[dim]?.length ?? 0), 0);
}

/** رابطُ المتجر: مفاتيحُ مكرّرة بترتيبٍ ثابت. */
export function appendSelection(qs: URLSearchParams, selection: FacetSelection): void {
  for (const dim of MULTI_DIMS) {
    for (const value of selection[dim] ?? []) qs.append(dim, value);
  }
}

/** طلبُ الواجهة الخلفيّة: قيمةٌ واحدة كما هي، وأكثر ⇐ `dim[]`. */
export function appendSelectionForApi(qs: URLSearchParams, selection: FacetSelection): void {
  for (const dim of MULTI_DIMS) {
    const values = selection[dim] ?? [];
    const [only] = values;
    if (values.length === 1 && only !== undefined) qs.set(dim, only);
    else for (const value of values) qs.append(`${dim}[]`, value);
  }
}
