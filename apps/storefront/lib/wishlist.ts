'use client';
/**
 * المفضّلة — مخزن واحد على المتصفّح (localStorage) يقرأه القلب في الترويسة،
 * وقلب لوحة الشراء في صفحة المنتج، وصفحة /wishlist.
 *
 * لا واجهة خلفيّة للمفضّلة بعد؛ فالمصدر الوحيد للحقيقة هو المتصفّح، ولذلك
 * يُقرأ عبر useSyncExternalStore حتّى يتطابق الخادم والعميل عند الإماهة
 * (لقطة الخادم فارغة دائمًا) ولا يُختلق عدد قبل أن يُعرف.
 *
 * العنصر المحفوظ هو ما تحتاجه صفحة المفضّلة لعرض بطاقة بلا طلب شبكة:
 * المعرّف الثابت (slug) والعنوان والسعر بالهللات والصورة إن وُجدت.
 */
import { useCallback, useSyncExternalStore } from 'react';

export interface WishlistItem {
  slug: string;
  title: string;
  priceMinor: number;
  currency: string;
  image?: { url: string; alt?: string | null } | null;
  subtitle?: string | null;
}

const KEY = 'tahdheer_wishlist';
export const WISHLIST_EVENT = 'tahdheer:wishlist';
const EMPTY: readonly WishlistItem[] = Object.freeze([]);
let cache: readonly WishlistItem[] | null = null;

function isItem(x: unknown): x is WishlistItem {
  if (typeof x !== 'object' || x === null) return false;
  const o = x as Record<string, unknown>;
  return (
    typeof o.slug === 'string' &&
    o.slug !== '' &&
    typeof o.title === 'string' &&
    Number.isSafeInteger(o.priceMinor) &&
    (o.priceMinor as number) >= 0
  );
}

/**
 * المخزن مدخلٌ خارجيّ (إصدارٌ أقدم، تبويبٌ آخر، عبث): يُقبل العنصر بشكله
 * الكامل وحده — سعرٌ صحيحٌ بالهللات لا «NaN» — والمعرّف مرّةً واحدة، أوّلُه.
 */
export function parseWishlist(raw: string | null): readonly WishlistItem[] {
  let parsed: unknown;
  try {
    parsed = raw ? JSON.parse(raw) : null;
  } catch {
    return EMPTY;
  }
  if (!Array.isArray(parsed)) return EMPTY;
  const seen = new Set<string>();
  return parsed.filter((x): x is WishlistItem => {
    if (!isItem(x) || seen.has(x.slug)) return false;
    seen.add(x.slug);
    return true;
  });
}

function read(): readonly WishlistItem[] {
  if (cache) return cache;
  if (typeof window === 'undefined') return EMPTY;
  try {
    cache = parseWishlist(window.localStorage.getItem(KEY));
  } catch {
    cache = EMPTY;
  }
  return cache;
}

function write(items: readonly WishlistItem[]): void {
  cache = items;
  try {
    window.localStorage.setItem(KEY, JSON.stringify(items));
  } catch {
    /* التخزين ممتلئ أو محجوب — تبقى القيمة في الذاكرة لهذه الجلسة. */
  }
  window.dispatchEvent(new CustomEvent(WISHLIST_EVENT));
}

function subscribe(cb: () => void): () => void {
  const onStorage = (e: StorageEvent) => {
    if (e.key === KEY) {
      cache = null;
      cb();
    }
  };
  window.addEventListener(WISHLIST_EVENT, cb);
  window.addEventListener('storage', onStorage);
  return () => {
    window.removeEventListener(WISHLIST_EVENT, cb);
    window.removeEventListener('storage', onStorage);
  };
}

export function getWishlist(): readonly WishlistItem[] {
  return read();
}

export function toggleWishlist(item: WishlistItem): boolean {
  const items = read();
  const has = items.some((x) => x.slug === item.slug);
  write(has ? items.filter((x) => x.slug !== item.slug) : [item, ...items]);
  return !has;
}

export function removeFromWishlist(slug: string): void {
  write(read().filter((x) => x.slug !== slug));
}

/** يعيد القائمة الحيّة؛ على الخادم وقبل الإماهة تكون فارغة. */
export function useWishlist(): readonly WishlistItem[] {
  return useSyncExternalStore(subscribe, read, () => EMPTY);
}

/** حالة عنصر واحد + مبدّل، للقلب في لوحة الشراء والشريط اللاصق. */
export function useWishlistItem(item: WishlistItem): { wished: boolean; toggle: () => boolean } {
  const items = useWishlist();
  const wished = items.some((x) => x.slug === item.slug);
  const toggle = useCallback(() => toggleWishlist(item), [item]);
  return { wished, toggle };
}
