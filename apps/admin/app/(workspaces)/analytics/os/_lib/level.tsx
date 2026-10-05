'use client';
// UNIT: LEVEL
// FILE: level.tsx
// STYLE: none
// PARENT: LAYOUT-ANALYTICS-OS, LIB-ACTIONS, OS-KIT, PAGE-ANALYTICS-OS-ACTIONS
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مستوى التنفيذ الخمسي — حالة متصفّح معلَنة، لا سياسة خادم.
 *
 * لا مشغّل أتمتة في النظام: المستوى هنا لا يشغّل شيئًا في الخلفية، وأثره
 * الوحيد حصرُ أزرار التنفيذ اليدوي في مركز الإجراءات (المستويان الأدنيان
 * يعطّلانها، و«مُساعد» يشترط موافقة لكل إجراء). يُحفظ في هذا المتصفّح وحده
 * — سابقة «التخطيط محلّي معلَن» في سجلّ قياس التحليلات.
 */

import { createContext, useContext, useMemo, useSyncExternalStore, type ReactNode } from 'react';

export type OsLevel = 'analytics' | 'recs' | 'assisted' | 'controlled' | 'auto';

export interface OsLevelSpec {
  key: OsLevel;
  name: string;
  hint: string;
}

/** الأسماء والتلميحات حرفيًا من اللوح. */
export const OS_LEVELS: OsLevelSpec[] = [
  { key: 'analytics', name: 'تحليل فقط', hint: 'لا عمليات كتابة إطلاقًا' },
  { key: 'recs', name: 'توصيات', hint: 'تشخيص واقتراحات دون تجهيز' },
  { key: 'assisted', name: 'مُساعد', hint: 'تجهيز التغييرات للموافقة' },
  { key: 'controlled', name: 'أتمتة منضبطة', hint: 'تنفيذ الآمن المعتمد تلقائيًا' },
  { key: 'auto', name: 'طيار آلي', hint: 'تنفيذ ما تجيزه السياسات — عالي الخطورة يبقى بموافقة' },
];

const STORAGE_KEY = 'analytics.os.level';

/* مخزن خارجيّ صغير: الذاكرة أوّلًا (تخزين معطّل)، ثمّ المحفوظ، ثمّ الافتراضي. */
let memoryLevel: OsLevel | null = null;
const levelListeners = new Set<() => void>();

function readLevel(): OsLevel {
  if (memoryLevel !== null) return memoryLevel;
  try {
    const saved = window.localStorage.getItem(STORAGE_KEY);
    if (saved !== null && isLevel(saved)) return saved;
  } catch {
    // تخزين معطّل — يبقى الافتراضي.
  }
  return 'controlled';
}

function subscribeLevel(listener: () => void): () => void {
  levelListeners.add(listener);
  const onStorage = (event: StorageEvent) => {
    if (event.key === STORAGE_KEY) listener();
  };
  window.addEventListener('storage', onStorage);
  return () => {
    levelListeners.delete(listener);
    window.removeEventListener('storage', onStorage);
  };
}

const isLevel = (value: string): value is OsLevel =>
  OS_LEVELS.some((spec) => spec.key === value);

interface LevelContextValue {
  level: OsLevel;
  setLevel: (level: OsLevel) => void;
}

const LevelContext = createContext<LevelContextValue | null>(null);

export function useOsLevel(): LevelContextValue {
  const value = useContext(LevelContext);
  if (value === null) throw new Error('useOsLevel يُستدعى داخل OsLevelProvider فقط.');
  return value;
}

export function OsLevelProvider({ children }: { children: ReactNode }) {
  /*
   * لقطة الخادم هي الافتراضي («أتمتة منضبطة») واللقطة المحفوظة تُقرأ من
   * المتصفّح عبر ‎useSyncExternalStore‎ — فلا يختلف الترطيب عن تصيير الخادم،
   * ولا حالة تُكتب داخل أثر. تخزين معطّل ⇒ يبقى المستوى داخل الجلسة في الذاكرة.
   */
  const level = useSyncExternalStore(subscribeLevel, readLevel, () => 'controlled' as OsLevel);

  const value = useMemo<LevelContextValue>(
    () => ({
      level,
      setLevel: (next) => {
        memoryLevel = next;
        try {
          window.localStorage.setItem(STORAGE_KEY, next);
        } catch {
          // التخزين رفاهية؛ المستوى يعمل داخل الجلسة على أي حال.
        }
        levelListeners.forEach((listener) => listener());
      },
    }),
    [level],
  );

  return <LevelContext.Provider value={value}>{children}</LevelContext.Provider>;
}

export type OsRisk = 'safe' | 'review' | 'high';

/** هل يحتاج الإجراء موافقةً صريحة قبل زرّ التنفيذ في هذا المستوى؟ */
export function needsApproval(level: OsLevel, risk: OsRisk): boolean {
  return risk !== 'safe' || level === 'assisted';
}

/**
 * بوابة التنفيذ من جهة المستوى وحدها (الصلاحية تُفحص على حدة من الجلسة):
 * «تحليل فقط» و«توصيات» يعطّلان كل تنفيذ؛ الباقي بحسب الموافقة والخطورة.
 */
export function levelAllowsExecute(level: OsLevel, risk: OsRisk, approved: boolean): boolean {
  if (level === 'analytics' || level === 'recs') return false;
  return needsApproval(level, risk) ? approved : true;
}

/** نص سبب القفل حين يمنع المستوى التنفيذ — حرفيًا من اللوح. */
export const LEVEL_LOCK_REASON = 'التنفيذ معطل في هذا المستوى — ارفعه أو نفّذ يدويًا';
