// UNIT: _URL-INSPECTOR-KIT
// FILE: _url-inspector-kit.tsx
// STYLE: none
// PARENT: URL-INSPECTOR
// USES: RESOURCE
// API: none
// MAP: docs/PROJECT_MAP.md

import type { CSSProperties, ReactNode } from 'react';
import type { ResourceState } from '../../../../components/resource';

/**
 * ٤٠٣ يُقرأ برمزه لا برسالته.
 *
 * هذه الشاشة تجمع خمسة مصادر خلف ثلاث صلاحيات مختلفة، فالمشغّل الذي يملك
 * بعضها يرى ألواحًا تعمل وألواحًا «تعذّرت» بلا سبب مقروء — ويضغط «إعادة
 * المحاولة» فيعود الردّ نفسه. الرمز ٤٠٣ يقول إنّ الخادم ردّ ولم يتعطّل،
 * والصلاحية الناقصة تُسمّى، ويُسحب الزرّ الذي لا يفتح شيئًا.
 */
export function isDenied(state: ResourceState<unknown>): boolean {
  return state.status === 'error' && state.code === 403;
}

export function ErrorNote({ state, permission }: { state: ResourceState<unknown>; permission: string }) {
  if (state.status !== 'error') return null;
  if (state.code !== 403) return <>{state.message}</>;
  return (
    <>
      دورك لا يملك <bdi dir="ltr">{permission}</bdi> — هذا اللوح مُقفَل عليك، ولا رقم يُعرض بدله.
    </>
  );
}

/* ───── أنواع الاستجابات (مُتحقَّق منها في المتحكّمات المذكورة أعلاه) ───── */

export interface PageOption {
  url: string;
  label: string;
}

export interface IndexabilityReport {
  path: string;
  robots: string;
  verdict: 'eligible' | 'monitor' | 'excluded' | string;
  reason: string;
  rule: string;
}

export interface RobotsPolicy {
  userAgent: string;
  disallow: string[];
  sitemap: string | null;
  host: string | null;
}

export interface CrawlPageRow {
  path: string;
  status: number;
  inbound: number;
  depth: number;
  redirectTo: string | null;
  title: string | null;
  discoveredFrom: string | null;
}

export interface CrawlPageEnvelope {
  data: { runId: number; finishedAt: string | null; page: CrawlPageRow | null } | null;
  message?: string;
}

export interface LinkCheckResult {
  url: string;
  status: 'OK' | 'BLOCKED' | 'UNREACHABLE' | string;
  httpStatus: number | null;
  chain: { url: string; status: number }[];
  hops: number;
  finalUrl: string | null;
  canonical: string | null;
  robots: string | null;
  indexable: boolean | null;
  reason: string | null;
}

export interface ProductSeoPayload {
  metadata: Record<string, unknown>;
  visible: unknown;
  schema: unknown;
}

export interface LlmsData {
  brand: string;
  items: { title: string; url: string; oneLiner: string | null }[];
  coverage: { published: number; included: number; pct: number | null; withOneLiner: number };
}

/* ───── ألوان الألواح من التصميم عبر الرموز ───── */

export type Tone = 'ok' | 'warn' | 'bad' | 'absent';

export const TONES: Record<Tone, { bg: string; fg: string; edge: string }> = {
  ok: { bg: 'var(--success-chip-bg)', fg: 'var(--success-chip-fg)', edge: 'var(--green-700)' },
  warn: { bg: 'var(--warning-bg)', fg: 'var(--warning-text)', edge: 'var(--warning-text)' },
  bad: { bg: 'var(--sib-danger-bg)', fg: 'var(--sib-danger)', edge: 'var(--sib-danger)' },
  absent: { bg: 'var(--sib-neutral-chip-bg)', fg: 'var(--text-muted)', edge: 'var(--sib-card-border)' },
};

export const ACTION_BTN: CSSProperties = {
  minHeight: 32,
  marginTop: 6,
  padding: '0 12px',
  border: '1px solid var(--sib-action-border)',
  borderRadius: 7,
  background: 'var(--sib-action-bg)',
  fontSize: 11.5,
  fontWeight: 900,
  color: 'var(--success-chip-fg)',
  cursor: 'pointer',
};

export function Panel({
  tone,
  title,
  chip,
  children,
  action,
}: {
  tone: Tone;
  title: string;
  chip: string;
  children: ReactNode;
  action?: ReactNode;
}) {
  const t = TONES[tone];
  return (
    <section
      style={{
        border: '1px solid var(--sib-card-border)',
        borderInlineStart: `3px solid ${t.edge}`,
        borderRadius: 12,
        background: 'var(--cc-glass)',
        padding: '10px 13px',
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
        <b style={{ fontSize: 11.5 }}>{title}</b>
        <span
          style={{
            marginInlineStart: 'auto',
            padding: '1px 8px',
            borderRadius: 999,
            background: t.bg,
            color: t.fg,
            fontSize: 11.5,
            fontWeight: 900,
          }}
        >
          {chip}
        </span>
      </div>
      <div style={{ marginTop: 4, fontSize: 11.5, lineHeight: 1.7, color: 'var(--text-muted)' }}>{children}</div>
      {action}
    </section>
  );
}

/** يجمع كل قيم @type في رسم JSON-LD كما أعاده الخادم — لا استنتاج. */
export function collectTypes(node: unknown, out: Set<string> = new Set<string>()): Set<string> {
  if (Array.isArray(node)) {
    for (const item of node) collectTypes(item, out);
    return out;
  }
  if (node !== null && typeof node === 'object') {
    const record = node as Record<string, unknown>;
    const type = record['@type'];
    if (typeof type === 'string') out.add(type);
    else if (Array.isArray(type)) {
      for (const value of type) if (typeof value === 'string') out.add(value);
    }
    for (const value of Object.values(record)) collectTypes(value, out);
  }
  return out;
}

export type SchemaState =
  | { status: 'na' }
  | { status: 'loading' }
  | { status: 'error'; message: string; code: number }
  | { status: 'ready'; types: string[]; canonical: string | null };

export type LiveState =
  | { status: 'idle' }
  | { status: 'pending' }
  | { status: 'error'; message: string }
  | { status: 'ready'; result: LinkCheckResult };

export type LlmsState =
  | { status: 'idle' }
  | { status: 'pending' }
  | { status: 'error'; message: string }
  | { status: 'ready'; data: LlmsData };

export interface HealthCheck {
  key: string;
  label: string;
  evaluable: boolean;
  passed: boolean;
}
