'use client';
// UNIT: APPEARANCE-SYNC
// FILE: appearance-sync.ts
// STYLE: none
// PARENT: ADMIN-SHELL, APPEARANCE-PANEL
// USES: LIB-ADMIN-API, LIB-LAYOUT-PREFS
// API: GET/PUT /api/v1/admin/me/preferences
// MAP: docs/PROJECT_MAP.md

/**
 * مزامنة وثيقة التفضيلات مع حساب المشرف (عقد §3).
 *
 * `localStorage` نسخةٌ محلّيّة تعمل دائمًا؛ والخادم يُسأل مرّةً عند الدخول.
 * - يعيد وثيقة ⇐ يُكتب قسم `appearance` منها محلّيًّا (الحساب يغلب الجهاز:
 *   هو ما يتبع المشرف إلى متصفّحٍ آخر)، ثمّ كلّ تعديلٍ يُرسل بعد هدوءٍ قصير.
 *   إلّا إن بقي على الجهاز تعديلٌ لم يبلغ الخادم (أُغلقت الصفحة قبل الإرسال):
 *   علامة `admin.prefs.dirty` تجعل المحلّيّ هو الأحدث فيُرفع ولا يُمحى.
 * - الكتابة **بالقسم**: `{ appearance }` وحده — الخادم يستبدل ما أُرسل ويترك
 *   غيره، فلا يمحو المظهرُ تخطيطَ لوحة القيادة الذي يكتبه مالكه.
 * - يعيد ٤٠٤/٤٠٥ ⇐ النقطة غير مبنيّة بعد: تُعلَّم «غير متاحة» لبقيّة
 *   الجلسة فلا يتكرّر طلبٌ فاشل مع كلّ تنقّل، وتبقى النسخة المحلّيّة وحدها.
 * - أيّ فشلٍ آخر ⇐ لا شيء يُمسح؛ المحلّيّ يبقى، ويُعاد السؤال في جلسةٍ لاحقة.
 */

import { useEffect, useSyncExternalStore } from 'react';
import { api } from '../../lib/admin-api';
import {
  applyPrefsDocument,
  composePrefsDocument,
  readPrefsDocument,
  subscribePrefs,
  writePrefsSection,
  type PrefsDocument,
} from '../../lib/layout-prefs';

const PATH = '/admin/me/preferences';
const OFFLINE_FLAG = 'admin.prefs.remote';
const DIRTY_KEY = 'admin.prefs.dirty';

function setDirty(dirty: boolean): void {
  try {
    if (dirty) window.localStorage.setItem(DIRTY_KEY, '1');
    else window.localStorage.removeItem(DIRTY_KEY);
  } catch {
    // لا تخزين: لا علامة، والخادم يغلب عند التحميل التالي — أهون الفشلين.
  }
}

function isDirty(): boolean {
  try {
    return window.localStorage.getItem(DIRTY_KEY) === '1';
  } catch {
    return false;
  }
}

/** ما يُرسل: قسم المظهر وحده، بالثلاثة القديمة مرحَّلةً إليه. */
function outgoing(): PrefsDocument {
  return { appearance: composePrefsDocument().appearance };
}

/*
 * رقم نسخة الوثيقة في الخادم — يُرسل مع كلّ كتابة فلا تُكتب فوق نسخةٍ لم
 * نرها. آخر ما عرفناه من ‎GET‎ أو ‎PUT‎ أو ردّ ‎409‎.
 */
let version: number | null = null;

function versionOf(body: unknown): number | null {
  const meta = typeof body === 'object' && body !== null ? (body as Record<string, unknown>).meta : null;
  const value = typeof meta === 'object' && meta !== null ? (meta as Record<string, unknown>).version : null;
  return typeof value === 'number' && Number.isInteger(value) ? value : null;
}

function push(body: PrefsDocument, retried = false): void {
  const sent = JSON.stringify(body);
  const payload = version === null ? body : { ...body, version };
  void api.put(PATH, payload).then((result) => {
    if (result.ok) {
      version = versionOf(result.data) ?? version;
      /* تُرفع العلامة فقط إن لم يتغيّر شيءٌ منذ الإرسال — ردٌّ متأخّر لا يمحو تعديلًا أحدث. */
      if (JSON.stringify(outgoing()) === sent) setDirty(false);
      return;
    }

    /*
     * ‎409‎: نافذةٌ أخرى كتبت بعدنا. الردّ يحمل الوثيقة الحاليّة ونسختها.
     * قسم المظهر هنا هو نيّة المستعمل الأحدث في هذه النافذة، فيُعاد إرساله
     * فوق النسخة الجديدة — مرّةً واحدة، فلا حلقة بين نافذتين تتنازعان.
     * وما سواه (لوحة القيادة) لا يُرسل أصلًا فلا يُمسّ.
     */
    if (result.status === 409 && !retried) {
      version = versionOf(result.body) ?? version;
      push(outgoing(), true);
    }
  });
}

export type SyncState = 'checking' | 'account' | 'device';

let state: SyncState = 'checking';
const stateListeners = new Set<() => void>();

function setState(next: SyncState): void {
  state = next;
  for (const listener of stateListeners) listener();
}

/** أين يُحفظ المظهر الآن — «في حسابك» أو «على هذا الجهاز». */
export function useSyncState(): SyncState {
  return useSyncExternalStore(
    (onChange) => {
      stateListeners.add(onChange);
      return () => {
        stateListeners.delete(onChange);
      };
    },
    () => state,
    () => 'checking',
  );
}

function unwrap(body: unknown): PrefsDocument | null {
  if (typeof body !== 'object' || body === null) return null;
  const record = body as Record<string, unknown>;
  const inner = typeof record.data === 'object' && record.data !== null ? record.data : record;
  return inner as PrefsDocument;
}

function hasContent(doc: PrefsDocument | null): doc is PrefsDocument {
  return doc !== null && (typeof doc.appearance === 'object' || typeof doc.dashboard === 'object');
}

export function useAppearanceSync(enabled: boolean): void {
  useEffect(() => {
    if (!enabled) return;

    /* ترحيل المفاتيح القديمة إلى الوثيقة مرّةً: من لم يملك وثيقةً بعد يُكتب له ما يستعمله فعلًا. */
    if (readPrefsDocument().appearance === undefined) {
      const composed = composePrefsDocument();
      if (composed.appearance) writePrefsSection('appearance', composed.appearance);
    }

    let alive = true;
    let available = false;
    let applying = false;
    /* خطّ الأساس ما على الجهاز الآن: إشعارٌ من مفتاحٍ آخر (ترتيب تبويب) لا يُعلِّم المظهر «معدَّلًا». */
    let lastSent = JSON.stringify(outgoing());
    let timer: ReturnType<typeof setTimeout> | undefined;

    let offline = false;
    try {
      offline = window.sessionStorage.getItem(OFFLINE_FLAG) === 'off';
    } catch {
      offline = false;
    }

    if (offline) {
      setState('device');
    } else {
      void api.get<unknown>(PATH).then((result) => {
        if (!alive) return;
        if (!result.ok) {
          if (result.status === 404 || result.status === 405) {
            try {
              window.sessionStorage.setItem(OFFLINE_FLAG, 'off');
            } catch {
              // لا جلسة تخزين: يُسأل الخادم مجدّدًا في التحميل التالي، ولا ضرر.
            }
          }
          setState('device');
          return;
        }

        available = true;
        version = versionOf(result.data);
        setState('account');
        const remote = unwrap(result.data);
        if (hasContent(remote) && typeof remote.appearance === 'object' && !isDirty()) {
          applying = true;
          applyPrefsDocument({ appearance: remote.appearance });
          applying = false;
          lastSent = JSON.stringify(outgoing());
        } else {
          /* حسابٌ بلا مظهر، أو تعديلٌ محلّيّ لم يبلغه: يُرفع ما على هذا الجهاز. */
          const body = outgoing();
          lastSent = JSON.stringify(body);
          push(body);
        }
      });
    }

    const unsubscribe = subscribePrefs(() => {
      if (applying) return;
      const serialized = JSON.stringify(outgoing());
      if (serialized === lastSent) return;
      /* العلامة قبل الإرسال لا بعده: صفحةٌ تُغلق خلال المهلة تترك أثرها. */
      setDirty(true);
      if (!available) return;
      if (timer !== undefined) clearTimeout(timer);
      timer = setTimeout(() => {
        const body = outgoing();
        const next = JSON.stringify(body);
        if (next === lastSent) return;
        lastSent = next;
        push(body);
      }, 600);
    });

    return () => {
      alive = false;
      unsubscribe();
      if (timer !== undefined) clearTimeout(timer);
    };
  }, [enabled]);
}
