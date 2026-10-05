/**
 * تشخيصُ الاستعلامات بلا نتائج — يصدره الخادم (`ZeroResultDiagnosis`) مع كلّ
 * صفٍّ في `zeroResultQueries`: سببٌ واحد وفعلٌ واحد. هنا تسميتُه ونبرتُه فقط؛
 * الفعلُ نصٌّ من الخادم يُعرض كما هو.
 *
 * دوالّ نقيّة بلا React — تُختبر بـ`node --test` (lib/__tests__/search-diagnosis.test.mjs).
 */
import type { StatusTone } from '../components/ui/status-badge';

export type DiagnosisReason = 'missing_inventory' | 'unknown_term' | 'off_catalog' | 'resolved';

export interface ZeroResultDiagnosis {
  reason: DiagnosisReason | (string & {});
  action: string;
}

/** ترتيبُ العرض بالأولويّة: ما يُعمل به أوّلًا. */
export const DIAGNOSIS_ORDER: readonly DiagnosisReason[] = ['missing_inventory', 'unknown_term', 'off_catalog', 'resolved'];

const BADGES: Record<DiagnosisReason, { label: string; tone: StatusTone }> = {
  missing_inventory: { label: 'منتج ناقص', tone: 'warning' },
  unknown_term: { label: 'مرادف ناقص', tone: 'info' },
  off_catalog: { label: 'لا صلة بالمتجر', tone: 'neutral' },
  resolved: { label: 'أُصلح منذ سُجّل', tone: 'success' },
};

/** سببٌ لا تعرفه الواجهة بعد يُعرض محايدًا باسمه: لا انهيار ولا تخمين. */
export function diagnosisBadge(reason: string): { label: string; tone: StatusTone } {
  return BADGES[reason as DiagnosisReason] ?? { label: reason, tone: 'neutral' };
}
