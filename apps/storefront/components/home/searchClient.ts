/**
 * نداءُ البحث من المتصفّح — مصدرُ «موادّ هذا الصفّ» و«منتجات هذا الصفّ».
 *
 * `/api/v1/search` هو الطرف الوحيد الذي يرشّح بالصفّ ويعيد بطاقات المنتجات
 * وتوزيعات التصنيف بأعدادٍ حقيقيّة. والقيمة المقبولة في كلّ مرشّح هي ما
 * بثّه الوجه نفسه (سبيكة للصفّ، اسمٌ للمادّة) — تُمرَّر كما جاءت.
 *
 * «آخر طلبٍ يفوز»: تسلسلُ الطلبات يحمله المستدعي (`seq`)، فردٌّ بطيء لاختيارٍ
 * سابق لا يحلّ محلّ ردّ الاختيار المعروض.
 */
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { apiBase } from '../../lib/api';

export interface FacetValue {
  value: string;
  count: number;
}

export interface SearchPayload {
  results?: { items?: ProductCardDto[]; total?: number };
  facets?: { taxonomy?: { key: string; values?: FacetValue[] }[] };
}

export type SearchResult = { ok: true; payload: SearchPayload } | { ok: false };

export async function searchCatalog(params: Record<string, string | undefined>): Promise<SearchResult> {
  const base = apiBase();
  if (!base) return { ok: false };
  const qs = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) if (value) qs.set(key, value);
  try {
    const res = await fetch(`${base}/api/v1/search?${qs.toString()}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    if (!res.ok) return { ok: false };
    return { ok: true, payload: (await res.json()) as SearchPayload };
  } catch {
    return { ok: false };
  }
}

/** قيم وجهٍ تصنيفيّ باسمه — أو قائمة فارغة حين لا يبثّه الردّ. */
export function facetValues(payload: SearchPayload, key: string): FacetValue[] {
  return payload.facets?.taxonomy?.find((group) => group.key === key)?.values ?? [];
}
