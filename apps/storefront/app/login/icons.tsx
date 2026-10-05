/**
 * Auth-card glyphs. Stroke path data is copied verbatim from the design sprite
 * (TahIcons.dc.html: #logo-mark, #i-close, #i-bolt, #i-mail, #i-info, #i-clock,
 * #i-cart-bag, #i-link) and the two brand marks are the vendors' own multi-path
 * assets exactly as «Tahdheer - Auth Overlay.dc.html» ships them — nothing here
 * is redrawn or approximated.
 *
 * Every glyph is decorative (aria-hidden): each one sits beside real text, so
 * the accessible name always comes from that text, never from the glyph.
 */
import type { ReactNode } from 'react';

/** #logo-mark — the open-book mark with the gold accent (same tokenisation as SiteHeader). */
export function LogoMark({ size }: { size: number }) {
  return (
    <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none">
      <path
        d="M3 5.5A1.5 1.5 0 0 1 4.5 4H10a3 3 0 0 1 2 .9A3 3 0 0 1 14 4h5.5A1.5 1.5 0 0 1 21 5.5V18a1 1 0 0 1-1 1h-6a2 2 0 0 0-2 2 2 2 0 0 0-2-2H4a1 1 0 0 1-1-1V5.5Z"
        stroke="currentColor"
        strokeWidth="1.8"
      />
      <path d="M12 6v15" stroke="currentColor" strokeWidth="1.6" />
      <path
        d="M15.5 9.5c1.6 0 2.6 1.6 1.8 3-.5.9-1.8 1.3-1.8 2.5"
        stroke="var(--gold-400)"
        strokeWidth="1.7"
        strokeLinecap="round"
      />
      <circle cx="15.6" cy="17.4" r="0.9" fill="var(--gold-400)" />
    </svg>
  );
}

export type AuthIconName = 'close' | 'bolt' | 'mail' | 'info' | 'clock' | 'cart' | 'link';

const PATHS: Record<AuthIconName, string[]> = {
  close: ['M6 6l12 12M18 6L6 18'],
  bolt: ['M13 3L6 13h5l-1 8 7-10h-5z'],
  mail: ['M4 7l8 6 8-6'],
  info: ['M12 11v5M12 7.6h.01'],
  clock: ['M12 7.5V12l3 2'],
  cart: ['M5 8h14l-1 12.2a1.6 1.6 0 0 1-1.6 1.5H7.6a1.6 1.6 0 0 1-1.6-1.5L5 8Z', 'M8.6 8V6.2a3.4 3.4 0 0 1 6.8 0V8'],
  link: [
    'M10.2 13.8a3.6 3.6 0 0 0 5.1 0l3.1-3.1a3.6 3.6 0 0 0-5.1-5.1l-1 1',
    'M13.8 10.2a3.6 3.6 0 0 0-5.1 0l-3.1 3.1a3.6 3.6 0 0 0 5.1 5.1l1-1',
  ],
};

/** Glyphs whose design symbol also carries a <rect>/<circle> primitive. */
const EXTRAS: Partial<Record<AuthIconName, ReactNode>> = {
  mail: <rect x="3" y="5" width="18" height="14" rx="2" />,
  info: <circle cx="12" cy="12" r="9" />,
  clock: <circle cx="12" cy="12" r="9" />,
};

export interface AuthIconProps {
  name: AuthIconName;
  className?: string;
  /** Design stroke weights on this card: 2 · 2.2. */
  strokeWidth?: number;
}

export function AuthIcon({ name, className, strokeWidth = 2 }: AuthIconProps) {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
    >
      {EXTRAS[name]}
      {PATHS[name].map((d) => (
        <path key={d} d={d} />
      ))}
    </svg>
  );
}

/** #brand-google — Google's own four-colour mark, verbatim from the design. */
export function GoogleMark({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false" className={className}>
      <path
        fill="#4285F4"
        d="M45.1 24.5c0-1.6-.1-2.7-.4-4H24v7.3h12.1c-.2 2-1.6 5-4.5 7l-.1.3 6.5 5 .5.1c4.1-3.8 6.6-9.4 6.6-15.7z"
      />
      <path
        fill="#34A853"
        d="M24 46c5.9 0 10.9-2 14.5-5.3l-6.9-5.4c-1.8 1.3-4.3 2.2-7.6 2.2-5.8 0-10.7-3.8-12.5-9.1l-.3 0-6.7 5.2-.1.3C7.9 41 15.4 46 24 46z"
      />
      <path
        fill="#FBBC05"
        d="M11.5 28.4c-.5-1.4-.7-2.9-.7-4.4s.3-3 .7-4.4l0-.3-6.8-5.3-.2.1A22 22 0 0 0 2 24c0 3.5.9 6.9 2.5 9.9l7-5.5z"
      />
      <path
        fill="#EA4335"
        d="M24 10.4c4.1 0 6.9 1.8 8.5 3.3l6.2-6C34.9 4.2 29.9 2 24 2 15.4 2 7.9 7 4.5 14.1l7 5.5c1.8-5.3 6.7-9.2 12.5-9.2z"
      />
    </svg>
  );
}

/** #brand-apple — Apple's own mark, verbatim from the design. */
export function AppleMark({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false" className={className}>
      <path
        fill="currentColor"
        d="M33.6 25.5c0-4.2 3.4-6.2 3.6-6.3-2-2.9-5-3.3-6.1-3.3-2.6-.3-5 1.5-6.3 1.5-1.3 0-3.3-1.5-5.4-1.4-2.8 0-5.3 1.6-6.7 4.1-2.9 5-.7 12.4 2.1 16.4 1.4 2 3 4.2 5.1 4.1 2-.1 2.8-1.3 5.2-1.3s3.1 1.3 5.3 1.3c2.2 0 3.6-2 4.9-4 1.5-2.3 2.2-4.5 2.2-4.6-.1 0-4.2-1.6-4.2-6.5z"
      />
      <path
        fill="currentColor"
        d="M29.6 13.1c1.1-1.4 1.9-3.3 1.7-5.2-1.6.1-3.6 1.1-4.8 2.5-1 1.2-2 3.2-1.7 5 1.8.1 3.6-.9 4.8-2.3z"
      />
    </svg>
  );
}
