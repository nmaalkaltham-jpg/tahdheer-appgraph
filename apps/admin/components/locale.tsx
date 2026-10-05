'use client';
// UNIT: LOCALE
// FILE: locale.tsx
// STYLE: locale.module.css
// PARENT: ADMIN-SHELL, LAYOUT-HOME, PAGE-HEAD, SIDEBAR, WORKSPACE-TABS
// USES: LIB-I18N
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * سياق اللغة ومبدّلها.
 *
 * اللغة تُقرأ في **الخادم** من الكوكي وتنزل هنا خاصّيةً — لا تُقرأ في العميل.
 * فلو قُرئت بعد التركيب لرُسمت الصفحة عربيةً RTL ثمّ قفزت إلى LTR، وهو أسوأ
 * وميض ممكن في واجهة ثنائية الاتجاه: يقفز كل شيء أفقيًّا أمام العين.
 *
 * والتبديل يعيد التحميل عمدًا (`location.reload`) لا يبدّل الحالة وحدها:
 * `lang` و`dir` على `<html>` يملكهما التخطيط الجذري وهو مكوّن خادم، وتبديلهما
 * من العميل يترك المستند يعلن لغةً ويعرض أخرى — يكذب على قارئ الشاشة وعلى
 * محرّك تشكيل النصّ معًا.
 */

import { createContext, useCallback, useContext, useMemo, type ReactNode } from 'react';
import {
  coverage,
  DEFAULT_LOCALE,
  dirOf,
  LOCALE_COOKIE,
  LOCALES,
  translate,
  type Locale,
} from '../lib/i18n';
import styles from './locale.module.css';

interface LocaleValue {
  locale: Locale;
  dir: 'rtl' | 'ltr';
  t: (source: string) => string;
  setLocale: (locale: Locale) => void;
}

const Ctx = createContext<LocaleValue | null>(null);

export function LocaleProvider({ locale, children }: { locale: Locale; children: ReactNode }) {
  const setLocale = useCallback((next: Locale) => {
    // سنة كاملة، ومتاح لكل مسارات اللوحة. SameSite=Lax يكفي: لا طلب عابر
    // للمواقع يحتاج قراءته، وهو تفضيل عرض لا رمز وصول.
    document.cookie = `${LOCALE_COOKIE}=${next}; path=/; max-age=31536000; samesite=lax`;
    window.location.reload();
  }, []);

  const value = useMemo<LocaleValue>(
    () => ({
      locale,
      dir: dirOf(locale),
      t: (source: string) => translate(locale, source),
      setLocale,
    }),
    [locale, setLocale],
  );

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

/**
 * `t` خارج المزوّد تُعيد الأصل بدل أن ترمي.
 *
 * مكوّنٌ يُصيَّر في اختبار أو في شجرة بلا مزوّد يجب أن يعرض نصًّا عربيًّا
 * سليمًا لا أن يُسقط الشاشة — الترجمة تحسينٌ لا شرط تشغيل.
 */
function useLocale(): LocaleValue {
  return (
    useContext(Ctx) ?? {
      locale: DEFAULT_LOCALE,
      dir: dirOf(DEFAULT_LOCALE),
      t: (source: string) => source,
      setLocale: () => undefined,
    }
  );
}

/** اختصار الاستعمال الغالب. */
export function useT(): (source: string) => string {
  return useLocale().t;
}

/**
 * مبدّل اللغة.
 *
 * يقول التغطية صراحةً: اللوحة عربية المنشأ وترجمتها تراكمية، فزرٌّ يَعِد
 * «English» ثمّ يعرض شاشةً نصفها عربي يُفقد الثقة. الرقم يقول ما تمّ فعلًا.
 */
export function LanguageSwitch() {
  const { locale, setLocale } = useLocale();

  return (
    <div className={styles.switch} role="group" aria-label="لغة اللوحة — Panel language">
      {LOCALES.map((l) => (
        <button
          key={l.id}
          type="button"
          className={styles.opt}
          aria-pressed={locale === l.id}
          lang={l.id}
          onClick={() => (locale === l.id ? undefined : setLocale(l.id))}
          title={
            l.id === 'en'
              ? `English — ${coverage('en')} strings translated so far; the rest stay in Arabic.`
              : 'العربية — لغة اللوحة الأصلية.'
          }
        >
          {l.native}
        </button>
      ))}
    </div>
  );
}
