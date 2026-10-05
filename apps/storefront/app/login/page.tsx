/**
 * /login — the canonical auth card as a real route.
 *
 * «Tahdheer - Auth Overlay» presents this card as a layer over the homepage
 * (desktop: centred; mobile: docked to the thumb at the block-end edge). On a
 * dedicated route there is nothing to lay over, so the presentation is kept —
 * centred on desktop, docked at the block-end on mobile — without the scrim,
 * and the card is a <section>, not a modal dialog: there is no dialog to trap
 * focus in, and the design's ✕ becomes a real link back to browsing.
 *
 * noindex: a sign-in surface is not a search result (MIP §3.2).
 */
import type { Metadata } from 'next';
import { AuthCard, parseNotice } from './AuthCard';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'الدخول',
  robots: { index: false, follow: true },
};

type Props = {
  searchParams: Promise<{ notice?: string }>;
};

export default async function LoginPage({ searchParams }: Props) {
  // The notice band renders ONLY for a state the URL actually declares; there
  // is no default banner asserting something we did not observe.
  const { notice } = await searchParams;

  return (
    <div className={styles.page}>
      <AuthCard notice={parseNotice(notice)} />

      {/*
       * «Tahdheer - Login» closes the page with the encrypted-connection line.
       * It states only what is true of this route: the storefront is served over
       * HTTPS and no control on the card asks for a password. The glyph is
       * #i-lock, copied verbatim from TahIcons.dc.html — it sits here rather
       * than in ./icons.tsx because that file is outside this change's scope.
       */}
      <div className={styles.trust}>
        <svg
          aria-hidden="true"
          focusable="false"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth={2}
          className={styles.trustGlyph}
        >
          <rect x="5" y="11" width="14" height="9" rx="2" />
          <path d="M8 11V8a4 4 0 0 1 8 0v3" />
        </svg>
        اتصال مُشفَّر — لا نطلب كلمة مرور
      </div>
    </div>
  );
}
