'use client';
/**
 * «شاهدتَ مؤخّرًا» — مخزنٌ واحد على المتصفّح (localStorage) تكتبه صفحة المنتج
 * وتقرؤه الرئيسة وصفحة المنتج. لا واجهة خلفيّة: تاريخ التصفّح ملك الزائر وحده.
 *
 * على نمط `wishlist.ts`: `useSyncExternalStore` بلقطة خادمٍ فارغة، فلا يُختلق
 * شريطٌ قبل أن يُعرف ما في المتصفّح، ولا تختلف الإماهة عن الخادم.
 *
 * القواعد: الأحدث أوّلًا، المعرّف مرّةً واحدة (زيارةٌ ثانية تنقله إلى الأوّل)،
 * واثنا عشر عنصرًا على الأكثر. والعنصر يُقبل بشكله الكامل وحده (سعرٌ صحيح
 * بالهللات)، فبيانات تبويبٍ قديم أو عبثٌ في المخزن لا تكسر الصفحة.
 */
import { useSyncExternalStore } from 'react';

export interface RecentItem {
  slug: string;
  title: string;
  priceMinor: number;
  currency: string;
  image?: { url: string; alt?: string | null } | null;
  subtitle?: string | null;
  viewedAt: number;
}

const KEY = 'tahdheer_recent';
export const RECENT_EVENT = 'tahdheer:recent';
export const RECENT_MAX = 12;
const EMPTY: readonly RecentItem[] = Object.freeze([]);
let cache: readonly RecentItem[] | null = null;

function isItem(x: unknown): x is RecentItem {
  if (typeof x !== 'object' || x === null) return false;
  const o = x as Record<string, unknown>;
  return (
    typeof o.slug === 'string' &&
    o.slug !== '' &&
    typeof o.title === 'string' &&
    Number.isSafeInteger(o.priceMinor) &&
    (o.priceMinor as number) >= 0 &&
    typeof o.currency === 'string' &&
    typeof o.viewedAt === 'number'
  );
}

/** يُصدَّر للاختبار: يقرأ نصّ المخزن ويُرجع قائمةً سليمة أو فارغة. */
export function parseRecent(raw: string | null): readonly RecentItem[] {
  let parsed: unknown;
  try {
    parsed = raw ? JSON.parse(raw) : null;
  } catch {
    return EMPTY;
  }
  if (!Array.isArray(parsed)) return EMPTY;
  const seen = new Set<string>();
  return parsed
    .filter((x): x is RecentItem => {
      if (!isItem(x) || seen.has(x.slug)) return false;
      seen.add(x.slug);
      return true;
    })
    .slice(0, RECENT_MAX);
}

/** يُصدَّر للاختبار: العنصر الجديد أوّلًا، بلا تكرار، بسقف الحدّ. */
export function pushRecent(items: readonly RecentItem[], item: RecentItem): readonly RecentItem[] {
  return [item, ...items.filter((x) => x.slug !== item.slug)].slice(0, RECENT_MAX);
}

function read(): readonly RecentItem[] {
  if (cache) return cache;
  if (typeof window === 'undefined') return EMPTY;
  try {
    cache = parseRecent(window.localStorage.getItem(KEY));
  } catch {
    cache = EMPTY;
  }
  return cache;
}

function write(items: readonly RecentItem[]): void {
  cache = items;
  try {
    window.localStorage.setItem(KEY, JSON.stringify(items));
  } catch {
    /* التخزين ممتلئ أو محجوب — تبقى القائمة في الذاكرة لهذه الجلسة. */
  }
  window.dispatchEvent(new CustomEvent(RECENT_EVENT));
}

function subscribe(cb: () => void): () => void {
  const onStorage = (e: StorageEvent) => {
    if (e.key === KEY) {
      cache = null;
      cb();
    }
  };
  window.addEventListener(RECENT_EVENT, cb);
  window.addEventListener('storage', onStorage);
  return () => {
    window.removeEventListener(RECENT_EVENT, cb);
    window.removeEventListener('storage', onStorage);
  };
}

/** تسجيل زيارة منتج — يُنادى من صفحة المنتج بعد الإماهة فقط. */
export function recordView(item: Omit<RecentItem, 'viewedAt'>): void {
  write(pushRecent(read(), { ...item, viewedAt: Date.now() }));
}

export function removeRecent(slug: string): void {
  write(read().filter((x) => x.slug !== slug));
}

export function clearRecent(): void {
  write(EMPTY);
}

/** القائمة الحيّة؛ على الخادم وقبل الإماهة فارغة. `exclude` لإسقاط المنتج الحاضر. */
export function useRecentlyViewed(exclude?: string): readonly RecentItem[] {
  const items = useSyncExternalStore(subscribe, read, () => EMPTY);
  return exclude ? items.filter((x) => x.slug !== exclude) : items;
}
