// UNIT: MEDIA-ICONS
// FILE: media-icons.tsx
// STYLE: none
// PARENT: LIBRARY-PANEL, OPTIMIZE-PANEL, _INSPECT-DIALOG, _MEDIA-CARD, _SCAN-PANELS
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * أيقونات شاشتَي الوسائط — رسم يدوي بلا أي تبعية خارجية.
 *
 * المسارات منقولة حرفيًا من TahIcons.dc.html (المصدر المعتمد للوحة الرموز)
 * لكل رمز موجود فيها. ثلاثة رموز تستدعيها لوحتا التصميم ولا وجود لها في
 * TahIcons — table و copy و eye — رُسمت هنا بنفس اللغة: شبكة 24×24، حدّ
 * مفتوح بلا تعبئة، سماكة تأتي من موضع الاستعمال.
 */

export type MediaIconName =
  | 'grid'
  | 'blocks'
  | 'list'
  | 'table'
  | 'cart'
  | 'copy'
  | 'info'
  | 'shield'
  | 'files'
  | 'eye'
  | 'bolt'
  | 'clock'
  | 'refresh'
  | 'close'
  | 'check';

/**
 * لوحة الرموز. تُركَّب مرة واحدة في كل صفحة وتُخفى عن التخطيط والقارئ معًا،
 * ثم يشير إليها كل استعمال بـ `<use href="#…">`.
 */
export function MediaIconSprite() {
  return (
    <svg aria-hidden="true" focusable="false" style={{ position: 'absolute', inlineSize: 0, blockSize: 0 }}>
      <defs>
        {/* منقولة حرفيًا من TahIcons.dc.html */}
        <symbol id="mi-grid" viewBox="0 0 24 24">
          <rect x="4" y="4" width="7" height="7" rx="1.5" />
          <rect x="13" y="4" width="7" height="7" rx="1.5" />
          <rect x="4" y="13" width="7" height="7" rx="1.5" />
          <rect x="13" y="13" width="7" height="7" rx="1.5" />
        </symbol>
        <symbol id="mi-blocks" viewBox="0 0 24 24">
          <rect x="4" y="4" width="7" height="7" rx="1.5" />
          <rect x="13" y="4" width="7" height="7" rx="1.5" />
          <rect x="8.5" y="13" width="7" height="7" rx="1.5" />
        </symbol>
        <symbol id="mi-list" viewBox="0 0 24 24">
          <path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" />
        </symbol>
        <symbol id="mi-cart" viewBox="0 0 24 24">
          <path d="M3.6 4.6h2l2.15 10.3a1.85 1.85 0 0 0 1.8 1.45h7.15a1.85 1.85 0 0 0 1.8-1.42L21.2 8.4H6.3" />
          <circle cx="9.8" cy="19.6" r="1.4" />
          <circle cx="17.1" cy="19.6" r="1.4" />
        </symbol>
        <symbol id="mi-info" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="9" />
          <path d="M12 11v5M12 7.6h.01" />
        </symbol>
        <symbol id="mi-shield" viewBox="0 0 24 24">
          <path d="M12 3l7 3v5c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z" />
          <path d="M9.3 12l1.9 1.9 3.6-3.7" />
        </symbol>
        <symbol id="mi-files" viewBox="0 0 24 24">
          <path d="M8 3h6l4 4v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
          <path d="M14 3v4h4" />
        </symbol>
        <symbol id="mi-bolt" viewBox="0 0 24 24">
          <path d="M13 3L6 13h5l-1 8 7-10h-5z" />
        </symbol>
        <symbol id="mi-clock" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="9" />
          <path d="M12 7.5V12l3 2" />
        </symbol>
        <symbol id="mi-refresh" viewBox="0 0 24 24">
          <path d="M20 11a8 8 0 0 0-14-4.5L4 9" />
          <path d="M4 4v5h5" />
          <path d="M4 13a8 8 0 0 0 14 4.5L20 15" />
          <path d="M20 20v-5h-5" />
        </symbol>
        <symbol id="mi-close" viewBox="0 0 24 24">
          <path d="M6 6l12 12M18 6L6 18" />
        </symbol>
        <symbol id="mi-check" viewBox="0 0 24 24">
          <path d="M4.5 12.5l5 5 10-11" />
        </symbol>

        {/* غير موجودة في TahIcons — مرسومة هنا بنفس اللغة البصرية */}
        <symbol id="mi-table" viewBox="0 0 24 24">
          <rect x="4" y="5" width="16" height="14" rx="2" />
          <path d="M4 10h16M10.5 10v9" />
        </symbol>
        <symbol id="mi-copy" viewBox="0 0 24 24">
          <rect x="9" y="9" width="11" height="11" rx="2" />
          <path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3" />
        </symbol>
        <symbol id="mi-eye" viewBox="0 0 24 24">
          <path d="M2.6 12S6.1 5.6 12 5.6 21.4 12 21.4 12 17.9 18.4 12 18.4 2.6 12 2.6 12z" />
          <circle cx="12" cy="12" r="3" />
        </symbol>
      </defs>
    </svg>
  );
}

/** رمز مفرد. الحجم والسماكة يأتيان من صفّ CSS في موضع الاستعمال. */
export function MediaIcon({ name, className }: { name: MediaIconName; className?: string }) {
  return (
    <svg aria-hidden="true" focusable="false" className={className}>
      <use href={`#mi-${name}`} />
    </svg>
  );
}
