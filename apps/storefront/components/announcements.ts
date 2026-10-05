/**
 * شريط الإعلانات — GET /api/v1/home/announcements (كتلة إعداداتٍ تحرّرها اللوحة).
 *
 * التطبيع دالّةٌ نقيّة بلا React كي تُختبر (lib/__tests__/announcements.test.mjs):
 * ما لا يُفهم يُسقَط، ولا يُخترع له معنى — والغياب كلّه (404، ردٌّ مشوَّه،
 * لا رسائل ولا رابط) يعني «لا شريط».
 */
type AnnouncementMode = 'static' | 'marquee';

interface AnnouncementLink {
  label: string;
  href: string;
}

interface AnnouncementStyle {
  bg?: string;
  fg?: string;
  /** بالبكسل. */
  height?: number;
}

export interface Announcements {
  mode: AnnouncementMode;
  items: readonly string[];
  link: AnnouncementLink | null;
  style: AnnouncementStyle | null;
}

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null;
const text = (v: unknown): string => (typeof v === 'string' ? v.trim() : '');
/** لونٌ أو تدرّجٌ كما تكتبه اللوحة — حروفٌ مأمونة لا تُغلق خاصيّة CSS. */
const cssValue = (v: unknown): string | undefined => {
  const s = text(v);
  return s !== '' && s.length <= 200 && /^[\w#%(),.\s-]+$/.test(s) ? s : undefined;
};

/** `null` = لا شريط. */
export function normalizeAnnouncements(raw: unknown): Announcements | null {
  if (!isRecord(raw)) return null;
  const items = Array.isArray(raw.items) ? raw.items.map(text).filter((s) => s !== '') : [];
  const link =
    isRecord(raw.link) && text(raw.link.label) !== '' && /^\/(?!\/)/.test(text(raw.link.href))
      ? { label: text(raw.link.label), href: text(raw.link.href) }
      : null;
  if (items.length === 0 && link === null) return null;

  let style: AnnouncementStyle | null = null;
  if (isRecord(raw.style)) {
    const bg = cssValue(raw.style.bg);
    const fg = cssValue(raw.style.fg);
    const h = Number(raw.style.height);
    const height = Number.isFinite(h) && h >= 30 && h <= 56 ? Math.round(h) : undefined;
    if (bg !== undefined || fg !== undefined || height !== undefined) style = { bg, fg, height };
  }

  return { mode: raw.mode === 'marquee' && items.length > 0 ? 'marquee' : 'static', items, link, style };
}
