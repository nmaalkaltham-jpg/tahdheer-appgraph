'use client';
// UNIT: COMMAND-CENTER
// FILE: command-center.tsx
// STYLE: dashboard.module.css
// PARENT: PAGE-DASHBOARD, _DASHBOARD-META
// USES: COMPONENTS-ICONS, LIB-FORMAT, STATUS-BADGE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * قطع مركز القيادة — عرضٌ محض، لا نداء ولا حالة خادم.
 *
 * ─── لماذا ملفّ عرضٍ منفصل ───
 *
 * الصفحة تجمع ثلاثة مصادر وتشتقّ منها اثني عشر سطحًا. ووضعُ الرسم والاشتقاق
 * في ملفٍّ واحد كان سيجعل «كيف يُرسم الخطّ» و«من أين جاء الرقم» سؤالًا
 * واحدًا. هنا الرسم وحده: كلّ قطعةٍ تأخذ أرقامًا مقيسةً وتعرضها، ولا تعرف
 * من أين جاءت.
 *
 * ─── ولماذا SVG مكتوب لا مكتبة ───
 *
 * أخفّ مكتبة رسومٍ شائعة تضيف عشرات الكيلوبايتات لأجل خطٍّ وأعمدةٍ وقرص.
 * والمقياسان مستقلّان: الريالات والطلبات لا يشتركان في وحدة.
 */

import { useId, type ReactNode } from 'react';
import Link from 'next/link';
import { Icon, type IconName } from '../../../components/icons';
import { count, money } from '../../../lib/format';
import type { StatusTone } from '../../../components/ui/status-badge';
import styles from './dashboard.module.css';

export type Tone =
  | 'emerald' | 'cyan' | 'blue' | 'violet' | 'purple' | 'teal'
  | 'amber' | 'orange' | 'red' | 'rose' | 'lime' | 'slate';

export interface SeriesPoint {
  date: string;
  orders: number;
  paidOrders: number;
  grossMinor: number;
}

/** ١٢ ثمّ ٠٩ — اليوم والشهر من تاريخٍ ISO، بلا منطقةٍ زمنيّة تُحوّله. */
export function dayMonth(iso: string): string {
  const [, m, d] = iso.slice(0, 10).split('-');
  return `${d}/${m}`;
}

export function percent(value: number): string {
  return `${value > 0 ? '+' : ''}${value}%`;
}

/* ───── الذرّات ───── */

export function Chip({ tone, children }: { tone: Tone; children: ReactNode }) {
  return (
    <span className={styles.chip} data-tone={tone}>
      {children}
    </span>
  );
}

export function Tag({ children }: { children: ReactNode }) {
  return <span className={styles.tag}>{children}</span>;
}

export function Glass({
  tone,
  band,
  span,
  className,
  children,
}: {
  tone?: Tone;
  /** خطٌّ علويّ متدرّج بنبرتين — لا خطُّ توهّجٍ من المنتصف. */
  band?: boolean;
  span?: 2 | 3;
  className?: string;
  children: ReactNode;
}) {
  return (
    <article className={`${styles.glass} ${className ?? ''}`} data-tone={tone} data-span={span}>
      <span className={styles.rule} data-band={band ? '' : undefined} aria-hidden="true" />
      {children}
    </article>
  );
}

export function GlassHead({
  title,
  note,
  end,
}: {
  title: string;
  note?: ReactNode;
  end?: ReactNode;
}) {
  return (
    <header className={styles.glassHead}>
      <div className={styles.glassHeadText}>
        <h3>{title}</h3>
        {note === undefined ? null : <p>{note}</p>}
      </div>
      {end === undefined ? null : <div className={styles.glassHeadEnd}>{end}</div>}
    </header>
  );
}

export function SectionHead({
  tone,
  icon,
  title,
  note,
  end,
}: {
  tone: Tone;
  icon: IconName;
  title: string;
  note: string;
  end?: ReactNode;
}) {
  return (
    <header className={styles.sectionHead}>
      <span className={styles.sectionIcon} data-tone={tone} aria-hidden="true">
        <Icon name={icon} size={13} />
      </span>
      <h2>{title}</h2>
      <p>{note}</p>
      {end === undefined ? null : <span className={styles.sectionEnd}>{end}</span>}
    </header>
  );
}

/**
 * زرّ الصيغة — يحمل تلميحه ويُفتح بالتركيز كما بالمرور.
 * `title` وحده لا يُفتح بلوحة المفاتيح ولا يقرؤه بعض القارئات.
 */
function Help({ label, formula }: { label: string; formula: string }) {
  const id = useId();

  return (
    <button type="button" className={styles.help} aria-describedby={id} aria-label={`كيف يُحسب ${label}؟`}>
      ؟
      <span role="tooltip" id={id} className={styles.tip}>
        {formula}
      </span>
    </button>
  );
}

export function Fact({
  label,
  children,
  wide,
}: {
  label: string;
  children: ReactNode;
  wide?: boolean;
}) {
  return (
    <span className={styles.fact} data-wide={wide ? '' : undefined}>
      <em>{label}</em>
      {children}
    </span>
  );
}

/* ───── الرسوم الصغيرة ───── */

function Bars({ points }: { points: number[] }) {
  const max = Math.max(1, ...points);

  return (
    <span className={styles.bars} aria-hidden="true">
      {points.map((v, i) => (
        <i
          key={i}
          style={{
            blockSize: `${Math.max(3, Math.round((v / max) * 22))}px`,
            opacity: (0.45 + (i / points.length) * 0.55).toFixed(2),
          }}
        />
      ))}
    </span>
  );
}

function Radial({ ratio, text, label }: { ratio: number; text: string; label: string }) {
  const circ = 2 * Math.PI * 15;
  const on = Math.max(0, Math.min(1, ratio)) * circ;

  return (
    <svg viewBox="0 0 36 36" width="46" height="46" role="img" aria-label={label}>
      <circle cx="18" cy="18" r="15" fill="none" stroke="var(--cc-well)" strokeWidth="4" />
      <circle
        cx="18"
        cy="18"
        r="15"
        fill="none"
        stroke="var(--tone)"
        strokeWidth="4"
        strokeLinecap="round"
        strokeDasharray={`${on.toFixed(2)} ${(circ - on).toFixed(2)}`}
        transform="rotate(-90 18 18)"
      />
      <text x="18" y="21" textAnchor="middle" fontSize="9" fontWeight="900" fill="var(--cc-text)" fontFamily="var(--font-family)">
        {text}
      </text>
    </svg>
  );
}

export function HeroSpark({ points }: { points: number[] }) {
  if (points.length < 2) return null;

  const max = Math.max(...points);
  const min = Math.min(...points);
  const span = max - min || 1;
  const step = 100 / (points.length - 1);
  const line = points
    .map((v, i) => `${i === 0 ? 'M' : 'L'}${(i * step).toFixed(2)} ${(31 - ((v - min) / span) * 27).toFixed(2)}`)
    .join(' ');

  return (
    <svg viewBox="0 0 100 34" preserveAspectRatio="none" role="img" aria-label="اتّجاه المبيعات خلال المدى" className={styles.heroSpark}>
      <defs>
        <linearGradient id="cc-hero-fill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="currentColor" stopOpacity="0.45" />
          <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={`${line} L100 34 L0 34 Z`} fill="url(#cc-hero-fill)" />
      <path
        d={line}
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        vectorEffect="non-scaling-stroke"
        strokeLinejoin="round"
        className={styles.drawn}
      />
    </svg>
  );
}

/* ───── المخطّط الرئيس ───── */

/** تسمية محور الريالات — بالآلاف حين تكبر، فلا تتزاحم الأصفار. */
function axisMoney(minor: number): string {
  const riyals = minor / 100;
  if (riyals >= 1000) return `${Math.round(riyals / 1000)} ألف`;
  return `${Math.round(riyals)}`;
}

export function SalesChart({
  series,
  showSales,
  showOrders,
  large = false,
}: {
  series: SeriesPoint[];
  showSales: boolean;
  showOrders: boolean;
  large?: boolean;
}) {
  const sales = series.map((p) => p.grossMinor);
  const orders = series.map((p) => p.orders);
  const x0 = 110;
  const x1 = 872;
  const yTop = 30;
  const yBot = 288;
  const maxS = Math.max(1, ...sales);
  const maxO = Math.max(1, ...orders);
  const step = series.length < 2 ? 0 : (x1 - x0) / (series.length - 1);
  const sy = (v: number) => yTop + (1 - v / maxS) * (yBot - yTop);
  // من اليمين إلى اليسار: أوّل يومٍ في أقصى اليمين — قراءة عربيّة.
  const px = (i: number) => x1 - i * step;
  const line = sales.map((v, i) => `${i === 0 ? 'M' : 'L'}${px(i).toFixed(1)} ${sy(v).toFixed(1)}`).join(' ');
  const peakIdx = sales.indexOf(Math.max(...sales));
  const peak = series[peakIdx];
  const fontSize = large ? 17 : 14;
  if (peak === undefined) return null;
  const labelIdx = [0, 0.25, 0.5, 0.75, 1]
    .map((f) => Math.round(f * (series.length - 1)))
    .filter((v, i, arr) => arr.indexOf(v) === i);

  return (
    <div className={styles.chartArea}>
      <svg viewBox="0 0 900 340" role="img" aria-label={`مبيعات وطلبات على ${series.length} يومًا`} className={styles.chartSvg}>
        <defs>
          <linearGradient id="cc-fill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="var(--cc-emerald)" stopOpacity="0.38" />
            <stop offset="100%" stopColor="var(--cc-cyan)" stopOpacity="0" />
          </linearGradient>
          <linearGradient id="cc-line" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" stopColor="var(--cc-cyan)" />
            <stop offset="100%" stopColor="var(--cc-emerald-text)" />
          </linearGradient>
          <filter id="cc-glow" x="-10%" y="-40%" width="120%" height="180%">
            <feGaussianBlur stdDeviation="4" result="b" />
            <feMerge>
              <feMergeNode in="b" />
              <feMergeNode in="SourceGraphic" />
            </feMerge>
          </filter>
        </defs>

        {[0, 1, 2, 3, 4].map((n) => {
          const v = (maxS / 4) * (4 - n);
          const y = sy(v);
          return (
            <g key={n}>
              <line x1={x0} y1={y} x2={x1} y2={y} className={styles.gridLine} strokeWidth="1" />
              <text x={x1 + 6} y={y + 5} fontSize={fontSize} textAnchor="start">
                {axisMoney(v)}
              </text>
            </g>
          );
        })}

        {showOrders
          ? series.map((p, i) => (
              <rect
                key={p.date}
                x={(px(i) - step * 0.3).toFixed(1)}
                y={(yBot - (p.orders / maxO) * 70).toFixed(1)}
                width={Math.max(2, step * 0.6).toFixed(1)}
                height={((p.orders / maxO) * 70).toFixed(1)}
                rx="2"
                className={styles.chartBar}
              />
            ))
          : null}

        {showSales && series.length > 1 ? (
          <>
            <path d={`${line} L${px(series.length - 1).toFixed(1)} ${yBot} L${px(0).toFixed(1)} ${yBot} Z`} fill="url(#cc-fill)" />
            <path d={line} className={`${styles.chartLine} ${styles.drawn}`} filter="url(#cc-glow)" />
            {peak.grossMinor > 0 ? (
              <>
                <line x1={px(peakIdx)} y1="30" x2={px(peakIdx)} y2={yBot} className={styles.peakLine} strokeDasharray="3 4" />
                <circle cx={px(peakIdx)} cy={sy(peak.grossMinor)} r="6" className={styles.peakHalo} />
                <circle cx={px(peakIdx)} cy={sy(peak.grossMinor)} r="3.5" className={styles.peakDot} />
              </>
            ) : null}
          </>
        ) : null}

        <line x1={x0} y1="290" x2={x1} y2="290" className={styles.axis} strokeWidth="1" />
        {labelIdx.flatMap((i) => {
          const p = series[i];
          return p === undefined ? [] : [
            <text key={p.date} x={px(i)} y="312" fontSize={fontSize} textAnchor="middle">
              {dayMonth(p.date)}
            </text>,
          ];
        })}
      </svg>

      {/*
        * بطاقة الذروة ثابتةٌ على أعلى يوم: تُقرأ بلا مرور، وبلا مؤقّتٍ يُخفيها.
        * وموضعها يُحسب من موضع الذروة في الرسم لا من رقمٍ ثابت.
        */}
      {showSales && !large && peak.grossMinor > 0 ? (
        <div
          className={styles.chartTip}
          dir="rtl"
          style={{ insetInlineEnd: `calc(${(100 - (px(peakIdx) / 900) * 100).toFixed(1)}% + 12px)` }}
        >
          <strong>
            الذروة · <bdi dir="ltr">{dayMonth(peak.date)}</bdi>
          </strong>
          <span data-tone="emerald">
            <span><i aria-hidden="true" />المبيعات</span>
            <bdi dir="ltr" style={{ fontWeight: 900 }}>{money(peak.grossMinor)}</bdi>
          </span>
          <span data-tone="blue">
            <span><i aria-hidden="true" />الطلبات</span>
            <bdi dir="ltr" style={{ fontWeight: 900 }}>{count(peak.orders)}</bdi>
          </span>
        </div>
      ) : null}
    </div>
  );
}

/* ───── قرص الحالات ───── */

export interface DonutSlice {
  key: string;
  label: string;
  value: number;
  tone: StatusTone;
}

export function Donut({ slices, centerText, centerNote }: { slices: DonutSlice[]; centerText: string; centerNote: string }) {
  const total = slices.reduce((s, r) => s + r.value, 0);
  const circ = 2 * Math.PI * 15.9;
  // الإزاحة التراكميّة تُحسب مرّةً قبل الرسم — لا متغيّرٌ يُعاد تعيينه أثناء التصيير.
  const arcs = slices.reduce<{ slice: DonutSlice; len: number; offset: number }[]>((acc, slice) => {
    const len = total === 0 ? 0 : (slice.value / total) * circ;
    const prev = acc[acc.length - 1];
    acc.push({ slice, len, offset: prev === undefined ? 0 : prev.offset - prev.len });
    return acc;
  }, []);

  return (
    <div className={styles.donutRow}>
      <svg viewBox="0 0 42 42" width="118" height="118" role="img" aria-label="الطلبات حسب الحالة">
        <circle cx="21" cy="21" r="15.9" className={styles.donutTrack} />
        {arcs.map(({ slice, len, offset }) => (
          <circle
            key={slice.key}
            cx="21"
            cy="21"
            r="15.9"
            className={styles.donutSeg}
            data-tone={slice.tone}
            strokeDasharray={`${len.toFixed(2)} ${(circ - len).toFixed(2)}`}
            strokeDashoffset={offset.toFixed(2)}
            transform="rotate(-90 21 21)"
          />
        ))}
        <text x="21" y="20" textAnchor="middle" fontSize="7.5" fontWeight="900" className={styles.donutText}>
          {centerText}
        </text>
        <text x="21" y="26" textAnchor="middle" fontSize="4.2" className={styles.donutSub}>
          {centerNote}
        </text>
      </svg>
      <ul className={styles.legend}>
        {slices.map((s) => (
          <li key={s.key} className={styles.legendRow}>
            <span className={styles.swatch} data-tone={s.tone} aria-hidden="true" />
            <span>{s.label}</span>
            <bdi dir="ltr" style={{ fontWeight: 900 }}>{count(s.value)}</bdi>
            <bdi dir="ltr">{total === 0 ? '—' : `${Math.round((s.value / total) * 100)}%`}</bdi>
          </li>
        ))}
      </ul>
    </div>
  );
}

/* ───── بطاقة حادثة ───── */

export interface Incident {
  key: string;
  label: string;
  value: number;
  href: string;
  tone: Tone;
  severity: string;
  impact: string;
  why: string;
  source: string;
  recommendation: string;
  primary: string;
}

export function IncidentCard({ incident, sweep }: { incident: Incident; sweep: boolean }) {
  return (
    <Glass tone={incident.tone} className={styles.incident}>
      <span className={styles.glow} data-fx="ambient glow" aria-hidden="true" />
      {sweep ? <span className={styles.sweep} data-fx="ambient" aria-hidden="true" /> : null}
      <div className={styles.incidentMeta}>
        <Chip tone={incident.tone}>{incident.severity}</Chip>
        <span>الأثر: <b style={{ color: 'var(--cc-text)' }}>{incident.impact}</b></span>
        <span style={{ marginInlineStart: 'auto' }}>
          <Tag>NOT MEASURED</Tag> العمر · المسؤول
        </span>
      </div>
      <div className={styles.incidentMain}>
        <bdi dir="ltr" className={styles.bigNumber}>{count(incident.value)}</bdi>
        <div className={styles.incidentText}>
          <strong>{incident.label}</strong>
          <span>{incident.why}</span>
        </div>
      </div>
      <div className={styles.facts}>
        <Fact label="المصدر" wide>
          <code dir="ltr">{incident.source}</code>
        </Fact>
      </div>
      <p className={styles.recommendation}>
        <Icon name="sparkle" size={13} />
        <span>
          <strong>توصية:</strong> {incident.recommendation}
        </span>
      </p>
      <div className={styles.actions}>
        <Link className={styles.action} data-primary="" href={incident.href}>
          {incident.primary}
        </Link>
      </div>
    </Glass>
  );
}

/* ───── سطر صحّة ───── */

export interface HealthItem {
  key: string;
  label: string;
  status: string;
  tone: Tone;
  icon: IconName;
  cause: string;
  href: string | null;
}

export function HealthRow({ item }: { item: HealthItem }) {
  const body = (
    <>
      <span className={styles.healthTile} data-tone={item.tone} aria-hidden="true">
        <Icon name={item.icon} size={12} />
      </span>
      <span className={styles.healthText}>
        <strong>{item.label}</strong>
        <em title={item.cause}>{item.cause}</em>
      </span>
      <Chip tone={item.tone}>
        <span className={styles.dot} data-tone={item.tone} aria-hidden="true" />
        {item.status}
      </Chip>
    </>
  );

  return (
    <li>
      {item.href === null ? (
        <span className={styles.healthRow} data-tone={item.tone === 'red' ? 'red' : undefined}>{body}</span>
      ) : (
        <Link className={styles.healthRow} data-tone={item.tone === 'red' ? 'red' : undefined} href={item.href}>
          {body}
        </Link>
      )}
    </li>
  );
}

/* ───── بطاقة مؤشّر ───── */

export interface KpiView {
  key: string;
  label: string;
  tone: Tone;
  icon: IconName;
  value: string;
  unit: string;
  previous: string;
  abs: string;
  absLabel: string;
  changePercent: number | null;
  /** لماذا لا مقارنة — «المدى السابق صفر» و«لم يُقَس» جوابان مختلفان. */
  noCompare?: string;
  /** هل الزيادة خيرٌ؟ المستردّ يزيد فيسوء، والطلبات تزيد فتحسن. */
  goodUp: boolean;
  sub: string;
  formula: string;
  href: string | null;
  drill: string;
  span: 2 | 3;
  viz:
    | { kind: 'bars'; points: number[] }
    | { kind: 'radial'; ratio: number; text: string; note: string }
    | { kind: 'status'; note: string }
    | { kind: 'none' };
}

export function KpiCard({ kpi, rangeLabel }: { kpi: KpiView; rangeLabel: string }) {
  const change = kpi.changePercent;
  const direction = change === null ? 'flat' : change > 0 ? 'up' : change < 0 ? 'down' : 'flat';

  return (
    <Glass tone={kpi.tone} span={kpi.span} className={styles.kpi}>
      <span className={styles.glow} data-fx="ambient glow" aria-hidden="true" />
      <div className={styles.kpiHead}>
        <span className={styles.kpiTile} aria-hidden="true">
          <Icon name={kpi.icon} size={14} />
        </span>
        <h3>{kpi.label}</h3>
        <Help label={kpi.label} formula={kpi.formula} />
      </div>
      <p className={styles.kpiValue}>
        <bdi dir="ltr">{kpi.value}</bdi>
        {kpi.unit === '' ? null : <small>{kpi.unit}</small>}
      </p>
      <div className={styles.kpiChange}>
        {change === null ? (
          <span className={styles.delta}>{kpi.noCompare ?? 'لا مقارنة — المدى السابق صفر'}</span>
        ) : (
          <bdi dir="ltr" className={styles.delta} data-direction={direction} data-good={kpi.goodUp ? 'true' : 'false'}>
            {percent(change)}
          </bdi>
        )}
        <span>{kpi.sub}</span>
      </div>
      <div className={styles.kpiFacts}>
        <Fact label="المدى السابق">
          <bdi dir="ltr">{kpi.previous}</bdi>
        </Fact>
        <Fact label={kpi.absLabel}>
          <bdi dir="ltr">{kpi.abs}</bdi>
        </Fact>
      </div>
      {kpi.viz.kind === 'bars' ? <Bars points={kpi.viz.points} /> : null}
      {kpi.viz.kind === 'radial' ? (
        <div className={styles.radial}>
          <Radial ratio={kpi.viz.ratio} text={kpi.viz.text} label={kpi.label} />
          <span>{kpi.viz.note}</span>
        </div>
      ) : null}
      {kpi.viz.kind === 'status' ? (
        <span className={styles.statusNote}>
          <span className={styles.dot} aria-hidden="true" />
          {kpi.viz.note}
        </span>
      ) : null}
      <div className={styles.kpiFoot}>
        <span>{rangeLabel}</span>
        {kpi.href === null ? (
          <Tag>لا قائمة تقابله</Tag>
        ) : (
          <Link className={styles.drill} href={kpi.href}>
            {kpi.drill} ←
          </Link>
        )}
      </div>
    </Glass>
  );
}
