/**
 * Icons of Storefront Product.dc.html — paths copied from the design, sizes
 * passed per use so each instance matches its spot (14 in the rating row,
 * 15 in the reviews summary, 12 on cards…).
 */
interface IconProps {
  size?: number;
  className?: string;
}

const stroke = {
  fill: 'none',
  stroke: 'currentColor',
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
};

export function StarIcon({ size = 14, className, filled = true }: IconProps & { filled?: boolean }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill={filled ? 'currentColor' : 'none'}
      stroke={filled ? undefined : 'currentColor'}
      strokeWidth={filled ? undefined : 1.6}
    >
      <path d="M12 3.6l2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9L3.5 9.8l5.9-.8z" />
    </svg>
  );
}

export function CartIcon({ size = 20, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2" {...stroke}>
      <path d="M3 4h2l2.4 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L21 8H6" />
      <circle cx="9.5" cy="20" r="1.4" />
      <circle cx="17" cy="20" r="1.4" />
    </svg>
  );
}

export function EyeIcon({ size = 16, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="1.9" {...stroke}>
      <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z" />
      <circle cx="12" cy="12" r="2.6" />
    </svg>
  );
}

export function DownloadIcon({ size = 17, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2" {...stroke}>
      <path d="M12 4v10" />
      <path d="M8 11l4 4 4-4" />
      <path d="M5 19h14" />
    </svg>
  );
}

export function PenIcon({ size = 17, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2" {...stroke}>
      <path d="M15 4l5 5L9 20l-5 1 1-5z" />
      <path d="M13 6l5 5" />
    </svg>
  );
}

export function ShieldIcon({ size = 17, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2" {...stroke}>
      <path d="M12 3l7 3v5c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z" />
      <path d="M9.3 12l1.9 1.9 3.6-3.7" />
    </svg>
  );
}

export function CheckIcon({ size = 14, strokeWidth = 3, className }: IconProps & { strokeWidth?: number }) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth={strokeWidth} {...stroke}>
      <path d="M4.5 12.5l5 5 10-11" />
    </svg>
  );
}

export function ChevronDownIcon({ size = 17, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2.2" {...stroke}>
      <path d="M6 9l6 6 6-6" />
    </svg>
  );
}

export function BackIcon({ size = 20, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2.2" {...stroke}>
      <path d="M10 6l6 6-6 6" />
    </svg>
  );
}

/** القلب — 22 في لوحة الشراء، 20 في الشريط اللاصق؛ يمتلئ حين يُحفظ العنصر. */
export function HeartIcon({ size = 22, className, filled = false }: IconProps & { filled?: boolean }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      strokeWidth="1.9"
      {...stroke}
      fill={filled ? 'currentColor' : 'none'}
    >
      <path d="M12 20s-7-4.4-7-9.5A3.9 3.9 0 0 1 12 8a3.9 3.9 0 0 1 7 2.5C19 15.6 12 20 12 20z" />
    </svg>
  );
}

/** شعار Apple كما في زرّ Apple Pay بالتصميم (16×20، أبيض). */
export function ApplePayIcon({ className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width="16" height="20" viewBox="0 0 17 21" fill="currentColor">
      <path d="M13.94 11.15c.01-2.02 1.65-2.99 1.72-3.03-.94-1.37-2.4-1.56-2.92-1.58-1.24-.13-2.42.73-3.05.73-.64 0-1.61-.71-2.65-.69-1.36.02-2.62.79-3.32 2-1.42 2.46-.36 6.1 1.02 8.1.68.98 1.48 2.07 2.53 2.03 1.02-.04 1.4-.66 2.63-.66 1.22 0 1.57.66 2.64.64 1.09-.02 1.79-.99 2.46-1.98.77-1.13 1.09-2.22 1.11-2.28-.03-.01-2.12-.81-2.14-3.23M11.9 4.66c.55-.67.92-1.6.82-2.53-.82.03-1.81.55-2.38 1.21-.51.59-.95 1.54-.83 2.44.91.07 1.84-.46 2.39-1.12" />
    </svg>
  );
}

export function SearchIcon({ size = 19, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2" {...stroke}>
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4-4" />
    </svg>
  );
}

/** مشاركة — ثلاث عقدٍ موصولة (لا سهمُ خروجٍ يُقرأ «مغادرة» في الاتّجاه العربيّ). */
export function ShareIcon({ size = 18, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2" {...stroke}>
      <circle cx="18" cy="5" r="3" />
      <circle cx="6" cy="12" r="3" />
      <circle cx="18" cy="19" r="3" />
      <path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4" />
    </svg>
  );
}

/** «التالية» في اتّجاه القراءة العربيّ — سهمٌ إلى اليسار (توأم `BackIcon`). */
export function NextIcon({ size = 20, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2.2" {...stroke}>
      <path d="M14 6l-6 6 6 6" />
    </svg>
  );
}

/** تكبير — عدسةٌ بعلامة زائد. */
export function ZoomIcon({ size = 18, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2" {...stroke}>
      <circle cx="11" cy="11" r="7" />
      <path d="M20 20l-4-4M11 8v6M8 11h6" />
    </svg>
  );
}

export function CloseIcon({ size = 18, className }: IconProps) {
  return (
    <svg aria-hidden="true" className={className} width={size} height={size} viewBox="0 0 24 24" strokeWidth="2.4" {...stroke}>
      <path d="M6 6l12 12M18 6 6 18" />
    </svg>
  );
}
