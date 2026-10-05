// UNIT: LIB-BROWSER-STORE
// FILE: browser-store.ts
// STYLE: none
// PARENT: DIAGNOSTICS-PANEL, OVERVIEW, PAGE-ANALYTICS, PAGE-NAVIGATION, SIDEBAR
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * قراءة التخزين المحلّي كمصدر خارجي عبر `useSyncExternalStore`:
 * الترميز الأول (الخادم/الإماهة) يرى `null`، ثم يُعاد الرسم بالقيمة المخزَّنة —
 * بلا `setState` داخل أثر، وبلا اختلاف بين ترميز الخادم والعميل.
 */
import { useSyncExternalStore } from 'react';

function subscribe(onChange: () => void): () => void {
  window.addEventListener('storage', onChange);
  return () => window.removeEventListener('storage', onChange);
}

function serverSnapshot(): null {
  return null;
}

/** القيمة الخام للمفتاح، أو `null` إن غابت أو كان التخزين محجوبًا. */
export function useStoredItem(key: string): string | null {
  return useSyncExternalStore(
    subscribe,
    () => {
      try {
        return window.localStorage.getItem(key);
      } catch {
        return null;
      }
    },
    serverSnapshot,
  );
}

function noopSubscribe(): () => void {
  return () => {};
}

/** `false` أثناء ترميز الخادم والإماهة، `true` بعدها — لقراءات المتصفّح الواحدة. */
export function useHydrated(): boolean {
  return useSyncExternalStore(
    noopSubscribe,
    () => true,
    () => false,
  );
}
