'use client';
// UNIT: CATALOG-TERMS
// FILE: catalog-terms.ts
// STYLE: none
// PARENT: NEW-STUDIO, STUDIO-CLIENT, _FORM-STATE
// USES: LIB-ADMIN-API
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * قوائم التصنيف التعليميّ — جلبٌ واحد، شكلٌ واحد، مفتاحٌ واحد.
 *
 * ─── لماذا خرجت من الاستوديو ───
 *
 * كان وضع الإنشاء يجلبها بنفسه بثلاث قوائم، والاستوديو يجلبها بخمس، وكلاهما
 * يكتب في مفتاح الذاكرة نفسه (`CATALOG_TERMS_KEY`). فأيّهما يُركَّب أوّلًا
 * يملأ الذاكرة، ويقرأ الآخر شكلًا لا يتوقّعه: قائمةٌ تظهر فارغةً في شاشةٍ
 * ومملوءةً في أختها بحسب ترتيب الزيارة — عطلٌ يبدو عشوائيًّا ولا يُعاد
 * إنتاجه إلّا بمعرفة السبب.
 *
 * والمصدر واحد الآن: خطّافٌ واحد يعرفه الاثنان.
 *
 * ─── ولماذا الجلب كلٌّ لا يتجزّأ ───
 *
 * أيّ ردٍّ فاشل يرمي قبل أن تُكتب قائمةٌ واحدة، فلا بقايا محاولةٍ فاشلة
 * تختلط بالجديدة — ولا يرى المشغّل «المراحل» مملوءةً و«الموادّ» فارغة
 * فيظنّ الموادّ غير مُعرَّفة.
 */

import { useQuery } from '@tanstack/react-query';
import { api, type CatalogTerm } from '../../../../lib/admin-api';

/**
 * قوائم التصنيف التعليميّ: خمسة نداءاتٍ تُقرأ معًا أو لا تُقرأ.
 *
 * مفتاحٌ واحد لأنّها وحدةٌ منطقيّة: عرضُ ثلاثٍ منها ونصفِ رابعة يُنتج نموذجًا
 * يبدو سليمًا ويرفضه الخادم عند النشر.
 */
export const CATALOG_TERMS_KEY = ['admin-resource', '/catalog/terms'] as const;

export interface GradeTerm extends CatalogTerm {
  stage: { id: number; slug: string; name: string };
}

interface CatalogResponse<T> {
  data: T[];
}

export interface CatalogTerms {
  stages: CatalogTerm[];
  grades: GradeTerm[];
  subjects: CatalogTerm[];
  semesters: CatalogTerm[];
  kinds: CatalogTerm[];
}

export function useCatalogTerms() {
  return useQuery<CatalogTerms>({
    queryKey: CATALOG_TERMS_KEY,
    queryFn: async () => {
      const [stages, grades, subjects, semesters, kinds] = await Promise.all([
        api.get<CatalogResponse<CatalogTerm>>('/catalog/stages'),
        api.get<CatalogResponse<GradeTerm>>('/catalog/grades'),
        api.get<CatalogResponse<CatalogTerm>>('/catalog/subjects'),
        api.get<CatalogResponse<CatalogTerm>>('/catalog/semesters'),
        api.get<CatalogResponse<CatalogTerm>>('/catalog/kinds'),
      ]);

      if (!stages.ok || !grades.ok || !subjects.ok || !semesters.ok || !kinds.ok) {
        throw new Error('تعذّر تحميل قوائم التصنيف التعليمي من الكتالوج.');
      }

      return {
        stages: stages.data.data,
        grades: grades.data.data,
        subjects: subjects.data.data,
        semesters: semesters.data.data,
        kinds: kinds.data.data,
      };
    },
  });
}
