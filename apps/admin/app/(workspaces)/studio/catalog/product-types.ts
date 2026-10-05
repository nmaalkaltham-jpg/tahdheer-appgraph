'use client';
// UNIT: PRODUCT-TYPES
// FILE: product-types.ts
// STYLE: none
// PARENT: NEW-STUDIO, QUEUE-PANEL, STUDIO-CLIENT, WIRED-PANELS
// USES: LIB-ADMIN-API, LIB-CONTRACT
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * أنواع المنتج — تُقرأ من الخادم، ولا تُكتب هنا.
 *
 * ─── لماذا لا جدولَ في الواجهة ───
 *
 * البديل أن تحمل اللوحة نسختها: ستّ طبائع وتسع عشرة استراتيجيّةً وخريطةَ ما
 * يليق بأيّ. وهي النسخة التي تنحرف أوّلًا لأنّها الأبعد عن الترحيل — تُضاف
 * طبيعةٌ في الخادم فتبقى اللوحة تعرض خمسًا، بلا خطأٍ يشكو. وقد كانت اللوحة
 * تحمل بالفعل خريطةَ تسع عشرة تسميةً مكتوبةً بيدها في لوحة طابور التنفيذ،
 * بلا أيّ رابطٍ نوعيٍّ بالخادم.
 *
 * والمسار من العقد لا نصًّا (القاعدة ١٨): حذفُه من Laravel يُفشل `tsc` هنا.
 */

import { useQuery } from '@tanstack/react-query';
import { api } from '../../../../lib/admin-api';
import { proxyPath } from '../../../../lib/contract';

/** طبيعةٌ واحدة كما يصفها الخادم. */
export interface ProductNature {
  value: string;
  label: string;
  strategies: string[];
  defaultStrategy: string | null;
  defaultInventory: string;
  requiredVariantFields: string[];
  requiresPhysicalAttributes: boolean;
  requiresDeliverableFile: boolean;
  limitsQuantityToOne: boolean;
  /** بنود النشر الخاصّة بالطبيعة: مفتاح ← تسمية. */
  publishItems: Record<string, string>;
}

export interface FulfillmentStrategy {
  value: string;
  label: string;
  requiresShipping: boolean;
  requiresSchedule: boolean;
  instant: boolean;
  /**
   * هل لها منفّذٌ مسجَّل فعلًا؟
   *
   * `false` ليست عطلًا بل حالةٌ معروفة: الوحدة تبقى معلّقةً بسببٍ مكتوب.
   * وعرضُها قبل الاختيار أصدق من اكتشافها بعد أن يدفع الزبون.
   */
  executable: boolean;
}

export interface ProductTypeRegistry {
  natures: ProductNature[];
  strategies: FulfillmentStrategy[];
  sellingModels: string[];
  inventoryStrategies: string[];
  recurringModels: string[];
}

export const PRODUCT_TYPES_KEY = ['admin-resource', 'product-types'] as const;

export function useProductTypes() {
  return useQuery<ProductTypeRegistry>({
    queryKey: PRODUCT_TYPES_KEY,
    queryFn: async () => {
      const result = await api.get<{ data: ProductTypeRegistry }>(
        proxyPath('adminProductTypeIndex'),
      );

      if (!result.ok) {
        throw new Error('تعذّر تحميل أنواع المنتج من الخادم.');
      }

      return result.data.data;
    },
    /*
     * المفردات تتغيّر بترحيلٍ لا بنقرة، فإعادة الجلب عند كلّ تركيبٍ هدرٌ.
     * وساعةٌ قصيرةٌ بما يكفي لأن يرى المشغّل طبيعةً جديدة في جلسته.
     */
    staleTime: 60 * 60 * 1000,
  });
}

/** الاستراتيجيّات التي تليق بطبيعةٍ بعينها، بتسمياتها. */
export function strategiesOf(
  registry: ProductTypeRegistry | undefined,
  nature: string | '',
): FulfillmentStrategy[] {
  if (registry === undefined || nature === '') {
    return [];
  }

  const found = registry.natures.find((n) => n.value === nature);

  if (found === undefined) {
    return [];
  }

  return registry.strategies.filter((s) => found.strategies.includes(s.value));
}

export function natureOf(
  registry: ProductTypeRegistry | undefined,
  nature: string | '',
): ProductNature | undefined {
  return nature === '' ? undefined : registry?.natures.find((n) => n.value === nature);
}
