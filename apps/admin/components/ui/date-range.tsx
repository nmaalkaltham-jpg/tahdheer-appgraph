'use client';
// UNIT: DATE-RANGE
// FILE: date-range.tsx
// STYLE: date-range.module.css
// PARENT: PAGE-DASHBOARD
// USES: COMPONENTS-ICONS
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مدًى زمنيّ موحّد — يحكم كلّ مؤشّرات الصفحة، ويعيش في الرابط.
 *
 * ─── لماذا في الرابط ───
 *
 * المشغّل يرى رقمًا غريبًا فينسخ الرابط إلى زميله. ومدًى محفوظٌ في حالةٍ
 * محلّيّة يجعل الزميل يفتح «آخر ٣٠ يومًا» فلا يرى ما رآه الأوّل — ثمّ
 * يتناقشان في رقمين مختلفين وهما يظنّانهما واحدًا.
 *
 * ─── ولماذا التواريخ لا الأسماء ───
 *
 * `?range=7d` تعني شيئًا مختلفًا كلّ يوم. `?from=&to=` تُثبّت ما رآه صاحب
 * الرابط. والأزرار المختصرة تُحسب إلى تاريخين ثمّ تُكتب.
 */

import { useCallback, useMemo } from 'react';
import { useRouter, useSearchParams, usePathname } from 'next/navigation';
import { Icon } from '../icons';
import styles from './date-range.module.css';

interface DateRange {
  from: string;
  to: string;
}

type PresetId = 'today' | '7d' | '30d' | 'mtd';

const PRESETS: { id: PresetId; label: string }[] = [
  { id: 'today', label: 'اليوم' },
  { id: '7d', label: 'آخر ٧ أيّام' },
  { id: '30d', label: 'آخر ٣٠ يومًا' },
  { id: 'mtd', label: 'هذا الشهر' },
];

function iso(date: Date): string {
  /*
   * محلّيّ لا UTC: `toISOString` يحوّل إلى UTC، فيصير «اليوم» في الرياض
   * أمسَ بعد الثالثة زوالًا — ويرى المشغّل طلبات اليوم تختفي.
   */
  const y = date.getFullYear();
  const m = `${date.getMonth() + 1}`.padStart(2, '0');
  const d = `${date.getDate()}`.padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function rangeForPreset(preset: PresetId): DateRange {
  const today = new Date();
  const to = iso(today);

  if (preset === 'today') return { from: to, to };

  if (preset === 'mtd') {
    return { from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to };
  }

  const days = preset === '7d' ? 6 : 29;
  const from = new Date(today);
  from.setDate(from.getDate() - days);

  return { from: iso(from), to };
}

/** المدى الحاليّ من الرابط — أو آخر ٣٠ يومًا. */
export function useDateRange(): {
  range: DateRange;
  activePreset: PresetId | null;
  setRange: (next: DateRange) => void;
} {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();

  const fallback = useMemo(() => rangeForPreset('30d'), []);
  const from = params.get('from') ?? fallback.from;
  const to = params.get('to') ?? fallback.to;

  const setRange = useCallback(
    (next: DateRange) => {
      const search = new URLSearchParams(params.toString());
      search.set('from', next.from);
      search.set('to', next.to);
      // `replace` لا `push`: تبديل المدى ليس صفحةً جديدة في تاريخ التصفّح.
      router.replace(`${pathname}?${search.toString()}`, { scroll: false });
    },
    [params, pathname, router],
  );

  const activePreset =
    PRESETS.find((preset) => {
      const candidate = rangeForPreset(preset.id);
      return candidate.from === from && candidate.to === to;
    })?.id ?? null;

  return { range: { from, to }, activePreset, setRange };
}

export function DateRangePicker({
  range,
  activePreset,
  onChange,
  onRefresh,
  generatedAt,
}: {
  range: DateRange;
  activePreset: string | null;
  onChange: (next: DateRange) => void;
  onRefresh: () => void;
  generatedAt: string | null;
}) {
  return (
    <div className={styles.bar}>
      <div className={styles.presets} role="group" aria-label="المدى الزمنيّ">
        {PRESETS.map((preset) => (
          <button
            key={preset.id}
            type="button"
            className={styles.preset}
            aria-pressed={activePreset === preset.id}
            onClick={() => onChange(rangeForPreset(preset.id))}
          >
            {preset.label}
          </button>
        ))}
      </div>

      <div className={styles.custom}>
        <label className={styles.field}>
          <span>من</span>
          <input
            type="date"
            value={range.from}
            max={range.to}
            onChange={(e) => onChange({ ...range, from: e.target.value })}
          />
        </label>
        <label className={styles.field}>
          <span>إلى</span>
          <input
            type="date"
            value={range.to}
            min={range.from}
            onChange={(e) => onChange({ ...range, to: e.target.value })}
          />
        </label>
      </div>

      <button type="button" className={styles.refresh} onClick={onRefresh}>
        <Icon name="refresh" size={16} />
        <span>تحديث</span>
      </button>

      {/*
        * وقت القراءة يُقال صراحةً: لوحةٌ لا تقول متى قُرئت تُقرأ على أنّها
        * «الآن» دائمًا — فيُتّخذ قرارٌ على رقمٍ عمره ساعة.
        */}
      {generatedAt !== null ? (
        <p className={styles.stamp}>
          آخر قراءة: <bdi dir="ltr">{new Date(generatedAt).toLocaleTimeString('ar-SA-u-nu-latn', { hour: '2-digit', minute: '2-digit' })}</bdi>
        </p>
      ) : null}
    </div>
  );
}
