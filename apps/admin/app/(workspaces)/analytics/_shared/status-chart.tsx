// UNIT: STATUS-CHART
// FILE: status-chart.tsx
// STYLE: shared.module.css
// PARENT: OVERVIEW
// USES: LIB-FORMAT
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مخطّط أشرطة أفقية مرسوم يدويًا — SVG خالص بلا أي مكتبة رسم.
 *
 * الأشرطة تنمو من حافة البداية (اليمين في RTL) نحو النهاية، لأن الإحداثيات
 * في SVG فيزيائية ولا تنقلب مع الاتجاه: نحسب موضع الشريط من الحافة اليمنى
 * صراحةً بدل الاعتماد على انعكاس تلقائي لا يحدث.
 *
 * المخطّط يُرسم من السلسلة نفسها المعروضة في الجدول المجاور، فلا يمكن أن
 * يناقض شكلُه أرقامَه.
 */

import { count } from '../../../../lib/format';
import s from './shared.module.css';

export interface ChartDatum {
  key: string;
  label: string;
  value: number;
}

const W = 700;
const LABEL_W = 168;
const VALUE_W = 62;
const GAP = 10;
const ROW_H = 26;
const ROW_GAP = 6;
const PAD_Y = 6;
const BAR_H = 13;

const TRACK_START = VALUE_W + GAP;
const TRACK_END = W - LABEL_W - GAP;
const TRACK_W = TRACK_END - TRACK_START;

export function StatusChart({
  data,
  unitLabel,
  ariaSummary,
}: {
  data: ChartDatum[];
  /** وحدة القيمة، تُستعمل في وصف المخطّط للقارئ الآلي. */
  unitLabel: string;
  ariaSummary?: string;
}) {
  const max = data.reduce((top, d) => (d.value > top ? d.value : top), 0);
  const height = PAD_Y * 2 + data.length * ROW_H + Math.max(0, data.length - 1) * ROW_GAP;

  const label =
    ariaSummary ??
    `${unitLabel}: ${data.map((d) => `${d.label} ${count(d.value)}`).join('، ')}`;

  return (
    <svg
      className={s.chart}
      viewBox={`0 0 ${W} ${height}`}
      height={height}
      role="img"
      aria-label={label}
      preserveAspectRatio="xMidYMid meet"
      /*
       * اتجاه صريح ltr على الجذر: text-anchor في SVG يتبع اتجاه السطر، فلو
       * ورث rtl من المستند لانقلب معنى start/end وخرج كل نصّ خارج اللوحة.
       * الإحداثيات هنا فيزيائية بالفعل، والعربية داخل السطر تُرتَّب بخوارزمية
       * الاتجاه ثنائي الاتجاه فتظهر سليمة.
       */
      style={{ direction: 'ltr' }}
    >
      {/* خطوط الشبكة: أرباع المدى مقيسة من حافة البداية. */}
      {[0.25, 0.5, 0.75, 1].map((fraction) => (
        <line
          key={fraction}
          x1={TRACK_END - TRACK_W * fraction}
          x2={TRACK_END - TRACK_W * fraction}
          y1={PAD_Y}
          y2={height - PAD_Y}
          strokeWidth={1}
          style={{ stroke: 'var(--an-hair)' }}
        />
      ))}

      {data.map((datum, index) => {
        const top = PAD_Y + index * (ROW_H + ROW_GAP);
        const mid = top + ROW_H / 2;
        const width = max > 0 ? (datum.value / max) * TRACK_W : 0;
        return (
          <g key={datum.key}>
            <rect
              x={TRACK_START}
              y={mid - BAR_H / 2}
              width={TRACK_W}
              height={BAR_H}
              rx={4}
              style={{ fill: 'var(--an-inset)' }}
            />
            {width > 0 ? (
              <rect
                x={TRACK_END - width}
                y={mid - BAR_H / 2}
                width={width}
                height={BAR_H}
                rx={4}
                style={{ fill: 'var(--an-line-ok)' }}
              />
            ) : null}
            <text
              x={W}
              y={mid}
              textAnchor="end"
              dominantBaseline="middle"
              fontSize={11.5}
              fontWeight={800}
              style={{ fill: 'var(--an-text)' }}
            >
              {datum.label}
            </text>
            <text
              x={0}
              y={mid}
              textAnchor="start"
              dominantBaseline="middle"
              fontSize={11}
              fontWeight={900}
              fontFamily="ui-monospace, Menlo, monospace"
              style={{
                fill: datum.value > 0 ? 'var(--an-text)' : 'var(--an-muted)',
                /* رقم لاتيني داخل SVG: <bdi> غير صالح في <text>، فالعزل بالـCSS. */
                direction: 'ltr',
                unicodeBidi: 'isolate',
              }}
            >
              {count(datum.value)}
            </text>
          </g>
        );
      })}

      {/* خطّ الأساس عند حافة البداية. */}
      <line
        x1={TRACK_END}
        x2={TRACK_END}
        y1={PAD_Y}
        y2={height - PAD_Y}
        strokeWidth={1.5}
        style={{ stroke: 'var(--an-border)' }}
      />
    </svg>
  );
}
