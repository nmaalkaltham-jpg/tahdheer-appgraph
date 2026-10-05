/**
 * أيقونات الرئيسة كما يرسمها «Storefront Home.dc.html» حرفيًّا — المسارات
 * منقولة من القالب بلا تعديل، وكلّها زخرفيّة (`aria-hidden`): الاسم المقروء
 * يأتي دائمًا من النصّ المجاور.
 */
import type { ReactNode } from 'react';

export type DesignIconName =
  | 'download'
  | 'pen'
  | 'shield'
  | 'headset'
  | 'blocks'
  | 'book'
  | 'cap'
  | 'trophy'
  | 'chevPrev'
  | 'chevNext'
  | 'star'
  | 'cart'
  | 'check'
  | 'file';

const PATHS: Record<DesignIconName, ReactNode> = {
  download: (
    <>
      <path d="M12 4v10" />
      <path d="M8 11l4 4 4-4" />
      <path d="M5 19h14" />
    </>
  ),
  pen: (
    <>
      <path d="M15 4l5 5L9 20l-5 1 1-5z" />
      <path d="M13 6l5 5" />
    </>
  ),
  shield: (
    <>
      <path d="M12 3l7 3v5c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z" />
      <path d="M9.3 12l1.9 1.9 3.6-3.7" />
    </>
  ),
  headset: (
    <>
      <path d="M4 13v-1a8 8 0 0 1 16 0v1" />
      <rect x="3" y="13" width="4" height="6" rx="1.4" />
      <rect x="17" y="13" width="4" height="6" rx="1.4" />
      <path d="M20 19a4 4 0 0 1-4 3h-2" />
    </>
  ),
  blocks: (
    <>
      <rect x="4" y="4" width="7" height="7" rx="1.5" />
      <rect x="13" y="4" width="7" height="7" rx="1.5" />
      <rect x="8.5" y="13" width="7" height="7" rx="1.5" />
    </>
  ),
  book: (
    <>
      <path d="M19 4H8a2 2 0 0 0-2 2v13a2 2 0 0 1 2-2h11z" />
      <path d="M19 4v15" />
    </>
  ),
  cap: (
    <>
      <path d="M3 9l9-4 9 4-9 4z" />
      <path d="M7 11v4c0 1.2 2.2 2.6 5 2.6s5-1.4 5-2.6v-4" />
    </>
  ),
  trophy: (
    <>
      <path d="M8 4h8v4.5a4 4 0 0 1-8 0z" />
      <path d="M8 6H5.5a2 2 0 0 0 2.2 3M16 6h2.5a2 2 0 0 1-2.2 3" />
      <path d="M12 12.5V16M9 20h6M10 20l.6-4M14 20l-.6-4" />
    </>
  ),
  /* «السابق» في RTL يشير يمينًا، و«التالي» يسارًا — كما في القالب. */
  chevPrev: <path d="M10 6l6 6-6 6" />,
  chevNext: <path d="M14 6l-6 6 6 6" />,
  star: <path d="M12 3.6l2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9L3.5 9.8l5.9-.8z" />,
  cart: (
    <>
      <path d="M3 4h2l2.4 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L21 8H6" />
      <circle cx="9.5" cy="20" r="1.2" />
      <circle cx="17" cy="20" r="1.2" />
    </>
  ),
  /* خارج القالب: علامة «شراء موثّق» ورابط الملفّ في بطاقة الرأي. */
  check: <path d="M5 12.5l4.5 4.5L19 7.5" />,
  file: (
    <>
      <path d="M14 3H7.5A1.5 1.5 0 0 0 6 4.5v15A1.5 1.5 0 0 0 7.5 21h9a1.5 1.5 0 0 0 1.5-1.5V7z" />
      <path d="M14 3v4h4" />
    </>
  ),
};

export function DesignIcon({
  name,
  size,
  strokeWidth = 1.9,
  className,
}: {
  name: DesignIconName;
  size: number;
  strokeWidth?: number;
  className?: string;
}) {
  const filled = name === 'star';
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      className={className}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill={filled ? 'currentColor' : 'none'}
      stroke={filled ? undefined : 'currentColor'}
      strokeWidth={filled ? undefined : strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {PATHS[name]}
    </svg>
  );
}
