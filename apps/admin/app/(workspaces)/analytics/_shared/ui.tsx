// UNIT: UI
// FILE: ui.tsx
// STYLE: shared.module.css
// PARENT: ALERTS, ATTENTION-MAP, COHORTS, CONTRACT, MAPS, OVERVIEW +4
// USES: MEASUREMENT, SHARED-ICONS
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * عناصر العرض المشتركة لمساحتَي «التحليلات» و«الخرائط التشخيصية».
 *
 * كلها عناصر عرض صرفة بلا حالة: لا تجلب بيانات ولا تخترعها. الحالة الوحيدة
 * التي تعرفها هي التمييز بين «مقيس» و«غير مُقاس» — وهو تمييز يأتيها من
 * المُستدعي ولا تستنتجه.
 */

import type { ReactNode } from 'react';
import { Icon, type IconName } from './icons';
import type { Feed } from './measurement';
import s from './shared.module.css';

export type Tone = 'ok' | 'warn' | 'bad' | 'muted';

const CHIP_TONE: Record<Tone, string> = {
  ok: s.chipOk ?? '',
  warn: s.chipWarn ?? '',
  bad: s.chipBad ?? '',
  muted: '',
};

const DOT_TONE: Record<Tone, string> = {
  ok: s.dotOk ?? '',
  warn: s.dotWarn ?? '',
  bad: s.dotBad ?? '',
  muted: '',
};

export function Chip({
  tone = 'muted',
  large = false,
  children,
}: {
  tone?: Tone;
  large?: boolean;
  children: ReactNode;
}) {
  return (
    <span className={[s.chip, CHIP_TONE[tone], large ? s.chipLg : ''].filter(Boolean).join(' ')}>
      {children}
    </span>
  );
}

export function Dot({ tone = 'muted' }: { tone?: Tone }) {
  return <span aria-hidden="true" className={[s.dot, DOT_TONE[tone]].filter(Boolean).join(' ')} />;
}

export function Panel({
  title,
  hint,
  actions,
  sub,
  note,
  children,
}: {
  title: string;
  hint?: string;
  actions?: ReactNode;
  sub?: string;
  note?: string;
  children: ReactNode;
}) {
  return (
    <section className={s.panel}>
      <div className={s.panelHead}>
        <h3 className={s.panelTitle}>{title}</h3>
        {hint ? <span className={s.hint}>{hint}</span> : null}
        {actions}
      </div>
      {sub ? <p className={s.panelSub}>{sub}</p> : null}
      {children}
      {note ? <p className={s.panelNote}>{note}</p> : null}
    </section>
  );
}

/**
 * حالة «غير مُقاس» الصريحة: تسمّي خطّ الأنابيب الغائب وتعدّد ما يلزم لبنائه.
 * لا تعرض صفرًا ولا شكلًا وهميًّا مكان البيانات.
 */
export function NotMeasured({
  icon = 'info',
  title,
  body,
  requirements,
  footer,
}: {
  icon?: IconName;
  title: string;
  body: ReactNode;
  requirements?: string[];
  footer?: ReactNode;
}) {
  return (
    <section className={s.empty} role="status" aria-live="polite">
      <span aria-hidden="true" className={s.emptyIcon}>
        <Icon name={icon} size={22} />
      </span>
      <h3 className={s.emptyTitle}>{title}</h3>
      <p className={s.emptyBody}>{body}</p>
      {requirements && requirements.length > 0 ? (
        <ul className={s.reasons}>
          {requirements.map((text, index) => (
            <li className={s.reason} key={text}>
              <span aria-hidden="true" className={s.reasonMark}>
                {index + 1}
              </span>
              <span className={s.reasonText}>{text}</span>
            </li>
          ))}
        </ul>
      ) : null}
      {footer}
    </section>
  );
}

/** صف مصدر بيانات: الاسم، دوره، المسار المغذّي، وحالته الحقيقية. */
export function FeedRow({ feed }: { feed: Feed }) {
  const connected = feed.state === 'CONNECTED';
  return (
    <div className={s.sourceRow}>
      <Dot tone={connected ? 'ok' : 'warn'} />
      <span className={s.sourceMain}>
        <b className={s.sourceName}>{feed.name}</b>
        <span className={s.sourceNote}>{feed.role}</span>
      </span>
      {feed.endpoint ? (
        <bdi dir="ltr" className={[s.chip, s.num].filter(Boolean).join(' ')}>
          {feed.endpoint}
        </bdi>
      ) : (
        <Chip tone="warn">لا مسار</Chip>
      )}
      <Chip tone={connected ? 'ok' : 'warn'}>{connected ? 'متصل' : 'غير مُقاس'}</Chip>
    </div>
  );
}

export interface SegmentOption {
  id: string;
  label: string;
  /** سبب التعطيل — وجوده يعطّل الخيار ويعلن السبب. */
  disabledReason?: string;
}

/**
 * مجموعة أزرار مقسومة. الخيار المعطّل يحمل سبب تعطيله في title و
 * aria-description حتى لا يكون تعطيلًا صامتًا.
 */
export function Segmented({
  label,
  options,
  value,
  onChange,
}: {
  label: string;
  options: SegmentOption[];
  value: string;
  onChange: (id: string) => void;
}) {
  return (
    <div className={s.segmented} role="group" aria-label={label}>
      {options.map((option) => {
        const on = option.id === value;
        const disabled = option.disabledReason !== undefined;
        return (
          <button
            key={option.id}
            type="button"
            className={[s.segBtn, on ? s.segBtnOn : ''].filter(Boolean).join(' ')}
            aria-pressed={on}
            disabled={disabled}
            title={option.disabledReason}
            onClick={() => onChange(option.id)}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

export function Notice({
  tone = 'muted',
  icon = 'info',
  children,
  actions,
}: {
  tone?: Tone;
  icon?: IconName;
  children: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <div
      className={[s.notice, tone === 'warn' ? s.noticeWarn : '', tone === 'ok' ? s.noticeOk : '']
        .filter(Boolean)
        .join(' ')}
    >
      <span aria-hidden="true" className={s.noticeIcon}>
        <Icon name={icon} size={15} strokeWidth={2.2} />
      </span>
      <span className={s.noticeText}>{children}</span>
      {actions}
    </div>
  );
}

export interface StageBar {
  id: string;
  label: string;
  /** null = غير مُقاس. لا يُستبدل بصفر أبدًا. */
  value: number | null;
  display: string;
  /** نسبة العرض من 0 إلى 100؛ تُتجاهل حين value = null. */
  width: number;
  tone?: Tone;
  note?: string;
  chip?: string;
}

/** صف شريط أفقي — الشريط المشرَّط يعني «غير مرصود» لا «صفر». */
export function BarRows({ rows }: { rows: StageBar[] }) {
  return (
    <div className={s.stack}>
      {rows.map((row) => {
        const measured = row.value !== null;
        return (
          <div className={s.barRow} key={row.id}>
            <div className={s.barHead}>
              <span className={s.barLabel}>{row.label}</span>
              {measured ? (
                <bdi dir="ltr" className={s.barValue}>
                  {row.display}
                </bdi>
              ) : (
                <span className={[s.barValue, s.absent].filter(Boolean).join(' ')}>—</span>
              )}
              {row.chip ? <Chip tone={measured ? (row.tone ?? 'ok') : 'warn'}>{row.chip}</Chip> : null}
            </div>
            <div className={s.barTrack}>
              <span
                aria-hidden="true"
                className={[
                  s.barFill,
                  !measured ? s.barFillAbsent : '',
                  row.tone === 'warn' ? s.barFillWarn : '',
                ]
                  .filter(Boolean)
                  .join(' ')}
                style={{ inlineSize: measured ? `${Math.max(2, row.width)}%` : '100%' }}
              />
            </div>
            {row.note ? <span className={s.sourceNote}>{row.note}</span> : null}
          </div>
        );
      })}
    </div>
  );
}
