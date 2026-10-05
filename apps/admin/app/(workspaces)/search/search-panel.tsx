'use client';
// UNIT: SEARCH-PANEL
// FILE: search-panel.tsx
// STYLE: search.module.css
// PARENT: PAGE-SEO
// USES: GLOSSARY, INTERNAL-DATA, LIB-SHARED, SERP, URL-INSPECTOR
// API: none
// API-VIA: GLOSSARY, INTERNAL-DATA, SERP, URL-INSPECTOR
// MAP: docs/PROJECT_MAP.md

/**
 * مركز ذكاء البحث — الغلاف والمبدّل.
 *
 * أربع شاشات خلف شريط واحد، وكل شاشة تُركَّب عند اختيارها وحدها
 * (عرف لوحة التشخيص نفسه: أسهم يمين/يسار + Home/End على شريط tablist).
 *
 * لوحة التصميم تعرض حبة رابعة «التكاملات» تدّعي اتصالات حية
 * (GSC/GA4/CrUX/Merchant «متصل ✓») لا وجود لها في backend/laravel —
 * فلا تُرسم حبة ميتة لها، وعنوان الشريط يعدّد الشاشات الحقيقية فقط.
 * مكانها شاشة «البحث الداخلي»: لوحات الاستعلامات والفهرس والمرادفات
 * الموصولة سابقًا محفوظة كما هي في _screens/internal-data.
 *
 * مضيف الإشعارات صادق: لا إشعار إلا تغذيةً راجعة لفعل وقع فعلًا —
 * والمنطقة الحية مركّبة دائمًا كي تُعلن قارئات الشاشة محتواها.
 */

import { useRef, useState, type KeyboardEvent } from 'react';
import { ToastContext, useToasts } from './_lib/shared';
import GlossaryScreen from './_screens/glossary';
import InternalDataScreen from './_screens/internal-data';
import SearchIntelligenceScreen from './_screens/intelligence';
import SerpScreen from './_screens/serp';
import UrlInspectorScreen from './_screens/url-inspector';
import s from './search.module.css';

type ScreenId = 'glossary' | 'url' | 'serp' | 'internal' | 'intelligence';

/** قائمة الشاشات بيانات لا بنية ثابتة — الحبات والعنوان يُرسمان منها. */
const SCREENS: { id: ScreenId; label: string }[] = [
  { id: 'glossary', label: 'القاموس الشامل' },
  { id: 'url', label: 'مفتش URL' },
  { id: 'serp', label: 'SERP والسلطة' },
  { id: 'internal', label: 'البحث الداخلي' },
  { id: 'intelligence', label: 'ذكاء البحث' },
];

export function SearchIntelPanel() {
  const [screen, setScreen] = useState<ScreenId>('glossary');
  const { toasts, push } = useToasts();
  const barRef = useRef<HTMLDivElement | null>(null);

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const index = SCREENS.findIndex((item) => item.id === screen);
    let next = -1;
    if (event.key === 'ArrowLeft') next = (index + 1) % SCREENS.length;
    else if (event.key === 'ArrowRight') next = (index - 1 + SCREENS.length) % SCREENS.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = SCREENS.length - 1;
    if (next === -1) return;
    event.preventDefault();
    const target = SCREENS[next];
    if (target === undefined) return;
    setScreen(target.id);
    barRef.current?.querySelector<HTMLButtonElement>(`#search-intel-tab-${target.id}`)?.focus();
  };

  const active = SCREENS.find((item) => item.id === screen) ?? SCREENS[0];
  if (active === undefined) throw new Error('قائمة الشاشات فارغة.');

  return (
    <section className={s.root}>

      <div className={s.frame}>
        <div className={s.chrome}>
          <b className={s.chromeTitle}>
            مركز ذكاء البحث — {SCREENS.map((item) => item.label).join(' · ')}
          </b>
          <span className={s.spacer} />
          <div className={s.pills} role="tablist" aria-label="شاشات مركز ذكاء البحث" ref={barRef} onKeyDown={onKeyDown}>
            {SCREENS.map((item) => {
              const on = item.id === screen;
              return (
                <button
                  key={item.id}
                  type="button"
                  role="tab"
                  id={`search-intel-tab-${item.id}`}
                  aria-selected={on}
                  aria-controls={`search-intel-panel-${item.id}`}
                  tabIndex={on ? 0 : -1}
                  className={[s.pill, on ? s.pillOn : ''].filter(Boolean).join(' ')}
                  onClick={() => setScreen(item.id)}
                >
                  {item.label}
                </button>
              );
            })}
          </div>
        </div>

        <div
          className={[s.body, s.enter].join(' ')}
          role="tabpanel"
          id={`search-intel-panel-${active.id}`}
          aria-labelledby={`search-intel-tab-${active.id}`}
          tabIndex={0}
          key={active.id}
        >
          <ToastContext.Provider value={push}>
            {screen === 'glossary' ? <GlossaryScreen /> : null}
            {screen === 'url' ? <UrlInspectorScreen /> : null}
            {screen === 'serp' ? <SerpScreen /> : null}
            {screen === 'internal' ? <InternalDataScreen /> : null}
            {screen === 'intelligence' ? <SearchIntelligenceScreen /> : null}
          </ToastContext.Provider>
        </div>
      </div>

      <div className={s.toastHost} role="status" aria-live="polite">
        {toasts.map((toast) => (
          <div key={toast.id} className={s.toast}>
            {toast.message}
          </div>
        ))}
      </div>
    </section>
  );
}
