/**
 * Shared storefront product icons.
 *
 * Decorative SVGs inherit their colour from the surrounding UI.
 * Accessible controls must provide their own textual label.
 */
import type { SVGProps } from 'react';

export type IconProps = Omit<SVGProps<SVGSVGElement>, 'children' | 'viewBox'>;

function Icon({ path, ...rest }: IconProps & { path: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      {...rest}
    >
      <path d={path} />
    </svg>
  );
}

/** Shopping cart. */
export const CartIcon = (p: IconProps) => (
  <Icon
    {...p}
    path="M5.6 7.5h12.8l-1.1 12a1.7 1.7 0 0 1-1.7 1.5H8.4a1.7 1.7 0 0 1-1.7-1.5l-1.1-12ZM9 7.5V6a3 3 0 0 1 6 0v1.5"
  />
);

/** Confirmation / completed state. */
export const CheckIcon = (p: IconProps) => (
  <Icon {...p} path="m4.8 12.6 4.8 4.8L19.2 6.6" />
);

/** File information. */
export const FilesIcon = (p: IconProps) => (
  <Icon
    {...p}
    path="M8 3.5h6.5l5 5V19a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5.5a2 2 0 0 1 2-2ZM14.5 3.5v5h5"
  />
);

/** Instant download / digital delivery. */
export const DownloadIcon = (p: IconProps) => (
  <Icon {...p} path="M12 4v10.5M8 11.2l4 4 4-4M5 19.5h14" />
);

/**
 * Semantic product-fact icon identifiers.
 *
 * This is a data contract used by product facts; it does not imply that this
 * module must render every identifier itself.
 */
export type FactIconName =
  | 'grid'
  | 'files'
  | 'sheet'
  | 'pen'
  | 'sparkle'
  | 'check'
  | 'download'
  | 'cart';
