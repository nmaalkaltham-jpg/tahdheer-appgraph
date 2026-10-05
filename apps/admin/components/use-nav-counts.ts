'use client';
// UNIT: USE-NAV-COUNTS
// FILE: use-nav-counts.ts
// STYLE: none
// PARENT: ADMIN-SHELL, PAGE-DASHBOARD, PAGE-HEAD
// USES: LIB-ADMIN-API, SIDEBAR
// API: GET /admin/ops/summary
// MAP: docs/PROJECT_MAP.md

/**
 * عدّادات الشريط الجانبيّ ولوحة الانتباه — من مصدرٍ واحد.
 *
 * ─── لماذا مصدرٌ واحد ───
 *
 * الشارة في الشريط والسطر في لوحة «ما يحتاج انتباهي» يقولان الرقم نفسه.
 * ومصدران يعنيان لحظتَي قراءةٍ مختلفتين: يقول الشريط «٣» وتقول اللوحة «٤»،
 * فيفقد المشغّل الثقة في الاثنين معًا.
 *
 * ─── ولماذا لا يُطلب بلا صلاحية ───
 *
 * `/admin/ops/summary` محروسٌ بـ`orders.read`. ونداؤه بلا صلاحيةٍ يردّ ٤٠٣
 * في كلّ تحميل صفحة — ضجيجٌ في السجلّ ورحلةٌ ضائعة. البوّابة تُغلق هنا قبل
 * أن يُفتح اتّصال.
 */

import { useQuery } from '@tanstack/react-query';
import { api } from '../lib/admin-api';
import type { NavCounts } from './sidebar';

/** عدّادٌ مقيس، أو إعلانُ أنّ مصدره غير مبنيّ — لا صفرَ مُختلق. */
export type OpsCounter =
  | { label: string; state: 'MEASURED'; value: number; href: string; alert: boolean }
  | { label: string; state: 'NOT_CONFIGURED'; reason: string };

interface OpsSummary {
  generatedAt: string;
  counters: Record<string, OpsCounter | undefined>;
  notConfigured: { key: string; label: string; reason: string }[];
}

/** كلّ ما يُعرض شارةً في الشريط — والباقي يُعرض في لوحة الانتباه وحدها. */
const BADGED = ['ordersNeedingAction', 'openCases', 'failedPayments', 'failedFulfillment'] as const;

/** `refetchInterval` تطلبه اللوحة وحدها: الشريط يكتفي بالدقيقة الخاملة. */
export function useOpsSummary(enabled: boolean, refetchInterval?: number) {
  return useQuery<OpsSummary>({
    queryKey: ['admin-resource', '/admin/ops/summary'],
    enabled,
    refetchInterval,
    /*
     * دقيقةٌ واحدة: العدّادات تُقرأ في كلّ صفحة، وإعادةُ الطلب مع كلّ تنقّل
     * ثمنٌ بلا مقابل — الرقم لا يتغيّر في ثوانٍ. وما يُعرض مؤرَّخ بـ
     * `generatedAt` فلا يُدّعى أنّه اللحظة.
     */
    staleTime: 60_000,
    queryFn: async () => {
      const result = await api.get<OpsSummary>('/admin/ops/summary');
      if (!result.ok) throw new Error(result.message);
      return result.data;
    },
  });
}

/** شارات الشريط — تُشتقّ من الملخّص نفسه ولا تُطلب على حدة. */
export function useNavCounts(enabled: boolean): NavCounts | undefined {
  const { data } = useOpsSummary(enabled);

  if (data === undefined) return undefined;

  const out: Record<string, { value: number; alert?: boolean }> = {};

  for (const key of BADGED) {
    const counter = data.counters[key];
    if (counter === undefined || counter.state !== 'MEASURED') continue;

    const existing = out[counter.href];
    out[counter.href] = {
      // وجهةٌ واحدة قد تجمع عدّادين (التنفيذ الفاشل والمعلّق) — يُجمعان.
      value: (existing?.value ?? 0) + counter.value,
      alert: (existing?.alert ?? false) || counter.alert,
    };
  }

  return out;
}
