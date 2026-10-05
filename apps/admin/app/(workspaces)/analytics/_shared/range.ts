// UNIT: RANGE
// FILE: range.ts
// STYLE: none
// PARENT: ABANDONED, PAGE-REVENUE, PURCHASE-FUNNEL
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مدًى افتراضيّ بتواريخ ISO قصيرة.
 *
 * ثلاثون يومًا لأنّه افتراض الخادم نفسه (`InsightWindow::fromRequest` يأخذ
 * `to->subDays(29)`), فلا تختلف الشاشة عن استدعاءٍ بلا وسائط. وتُكتب مرّةً
 * لأنّ نسختين تفترقان عند أوّل تعديل فيعرض تبويبان مدَيَين مختلفين ويبدوان
 * متناقضين.
 */
export function lastDays(days: number): { from: string; to: string } {
  const end = new Date();
  const start = new Date(end);
  start.setDate(start.getDate() - (days - 1));

  const iso = (d: Date): string => d.toISOString().slice(0, 10);

  return { from: iso(start), to: iso(end) };
}
