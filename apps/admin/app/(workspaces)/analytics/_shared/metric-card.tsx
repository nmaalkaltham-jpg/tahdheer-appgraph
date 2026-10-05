// UNIT: METRIC-CARD
// FILE: metric-card.tsx
// STYLE: shared.module.css
// PARENT: OVERVIEW, SEARCH-FUNNEL
// USES: SHARED-ICONS
// API: none
// MAP: docs/PROJECT_MAP.md

import {
  forwardRef,
  type HTMLAttributes,
  type ReactNode,
} from 'react';

import {
  Icon,
  type IconName,
} from './icons';

import s from './shared.module.css';

export type MetricTone =
  | 'neutral'
  | 'accent'
  | 'success'
  | 'warning'
  | 'danger'
  | 'muted';

export interface MetricCardProps
  extends Omit<
    HTMLAttributes<HTMLElement>,
    'children'
  > {
  label: string;

  icon: IconName;

  value: ReactNode;

  unit?: string;

  source?: string;

  hint?: ReactNode;

  meta?: ReactNode;

  actions?: ReactNode;

  tone?: MetricTone;
}

const TONE_CLASS:
  Record<MetricTone, string> = {
    neutral: '',
    accent:
      s.metricAccent ?? '',
    success:
      s.metricSuccess ?? '',
    warning:
      s.metricWarning ?? '',
    danger:
      s.metricDanger ?? '',
    muted:
      s.metricMuted ?? '',
  };

export const MetricCard =
  forwardRef<
    HTMLElement,
    MetricCardProps
  >(
    function MetricCard(
      {
        label,
        icon,
        value,
        unit,
        source,
        hint,
        meta,
        actions,
        tone = 'neutral',
        className,
        ...props
      },
      ref,
    ) {
      return (
        <article
          {...props}
          ref={ref}
          className={[
            s.metricCard,
            TONE_CLASS[tone],
            className ?? '',
          ]
            .filter(Boolean)
            .join(' ')}
        >
          <span
            aria-hidden="true"
            className={
              s.metricGlow
            }
          />

          <header
            className={
              s.metricHeader
            }
          >
            <span
              aria-hidden="true"
              className={
                s.metricIcon
              }
            >
              <Icon
                name={icon}
                size={16}
                strokeWidth={2.15}
              />
            </span>

            <span
              className={
                s.metricLabel
              }
            >
              {label}
            </span>

            {actions ? (
              <div
                className={
                  s.metricActions
                }
              >
                {actions}
              </div>
            ) : null}
          </header>

          <div
            className={
              s.metricMain
            }
          >
            <span
              className={
                s.metricValue
              }
            >
              {value}
            </span>

            {unit ? (
              <span
                className={
                  s.metricUnit
                }
              >
                {unit}
              </span>
            ) : null}
          </div>

          {hint ? (
            <div
              className={
                s.metricHint
              }
            >
              {hint}
            </div>
          ) : null}

          {source || meta ? (
            <footer
              className={
                s.metricFooter
              }
            >
              {source ? (
                <bdi
                  dir="ltr"
                  className={
                    s.metricSource
                  }
                  title={source}
                >
                  {source}
                </bdi>
              ) : null}

              {meta ? (
                <span
                  className={
                    s.metricMeta
                  }
                >
                  {meta}
                </span>
              ) : null}
            </footer>
          ) : null}
        </article>
      );
    },
  );
