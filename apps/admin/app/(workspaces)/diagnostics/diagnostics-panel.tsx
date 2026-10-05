'use client';
// UNIT: DIAGNOSTICS-PANEL
// FILE: diagnostics-panel.tsx
// STYLE: diagnostics.module.css, shared.module.css
// PARENT: PAGE-SYSTEM
// USES: ALERTS, LIB-BROWSER-STORE, MAPS, MOTION, REPORTS, SHARED-ICONS
// API: none
// API-VIA: ALERTS, REPORTS
// MAP: docs/PROJECT_MAP.md

/**
 * الخرائط التشخيصية · التنبيهات · التقارير.
 *
 * ثلاث شاشات خلف شريط واحد، وكل شاشة تُركَّب عند اختيارها وحدها.
 *
 * عقد الصدق نفسه المطبَّق في مركز التحليلات: الخريطة الوحيدة المرسومة هنا
 * مبنية على بيانات كتالوج حقيقية، والقواعد التي لا مسار لها تظهر معطّلة
 * بسببها، والتقرير الذي لا يمكن إصداره يقول ما ينقصه — لا زرّ يكتفي بإشعار.
 */

import { useCallback, useRef, useState, type CSSProperties, type KeyboardEvent } from 'react';
import { useStoredItem } from '../../../lib/browser-store';
import { EASINGS, MOTION_SCALE } from '@tahdheer/design-system/motion';
import { Icon } from '../analytics/_shared/icons';
import shared from '../analytics/_shared/shared.module.css';
import d from './diagnostics.module.css';
import AlertsScreen from './_screens/alerts';
import MapsScreen from './_screens/maps';
import ReportsScreen from './_screens/reports';

type ScreenId = 'maps' | 'alerts' | 'reports';

const SCREENS: { id: ScreenId; label: string }[] = [
  { id: 'maps', label: 'الخرائط التشخيصية' },
  { id: 'alerts', label: 'مركز التنبيهات' },
  { id: 'reports', label: 'التقارير' },
];

/** نفس مفتاح مركز التحليلات: السمة واحدة عبر المساحتين. */
const THEME_KEY = 'tahdheer.admin.analytics.theme';

const MOTION = MOTION_SCALE[3];
const MOTION_VARS = {
  '--an-motion-duration': `${MOTION.duration}ms`,
  '--an-motion-distance': `${MOTION.distance}px`,
  '--an-motion-ease': EASINGS[MOTION.easing],
} as unknown as CSSProperties;

export function DiagnosticsPanel() {
  const [screen, setScreen] = useState<ScreenId>('maps');
  // التخزين المحلي قد يكون محجوبًا — الافتراض يكفي. المخزَّن هو الأصل حتى أول تبديل في الجلسة.
  const storedTheme = useStoredItem(THEME_KEY);
  const [darkOverride, setDarkOverride] = useState<boolean | null>(null);
  const dark = darkOverride ?? storedTheme === 'dark';
  const barRef = useRef<HTMLDivElement | null>(null);

  const toggleTheme = useCallback(() => {
    const next = !dark;
    setDarkOverride(next);
    try {
      window.localStorage.setItem(THEME_KEY, next ? 'dark' : 'light');
    } catch {
      /* لا شيء يعتمد على نجاح الحفظ. */
    }
  }, [dark]);

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
    barRef.current?.querySelector<HTMLButtonElement>(`#diagnostics-tab-${target.id}`)?.focus();
  };

  const active = SCREENS.find((item) => item.id === screen) ?? SCREENS[0];
  if (active === undefined) throw new Error('قائمة الشاشات فارغة.');

  return (
    <section>

      <div
        className={[d.root, shared.theme].filter(Boolean).join(' ')}
        data-theme={dark ? 'dark' : 'light'}
        style={MOTION_VARS}
      >
        <div className={d.frame}>
          <div className={d.chrome}>
            <b className={d.chromeTitle}>الخرائط التشخيصية › التنبيهات › التقارير</b>
            <span className={d.spacer} />
            <div className={d.pills} role="tablist" aria-label="شاشات التشخيص" ref={barRef} onKeyDown={onKeyDown}>
              {SCREENS.map((item) => {
                const on = item.id === screen;
                return (
                  <button
                    key={item.id}
                    type="button"
                    role="tab"
                    id={`diagnostics-tab-${item.id}`}
                    aria-selected={on}
                    aria-controls={`diagnostics-panel-${item.id}`}
                    tabIndex={on ? 0 : -1}
                    className={[d.pill, on ? d.pillOn : ''].filter(Boolean).join(' ')}
                    onClick={() => setScreen(item.id)}
                  >
                    {item.label}
                  </button>
                );
              })}
            </div>
            <button
              type="button"
              className={d.pill}
              onClick={toggleTheme}
              aria-pressed={dark}
              aria-label={dark ? 'الوضع الفاتح' : 'الوضع الداكن'}
              title={dark ? 'الوضع الفاتح' : 'الوضع الداكن'}
            >
              <Icon name={dark ? 'sun' : 'moon'} size={12} />
            </button>
          </div>

          <div
            className={[d.body, shared.enter].filter(Boolean).join(' ')}
            role="tabpanel"
            id={`diagnostics-panel-${active.id}`}
            aria-labelledby={`diagnostics-tab-${active.id}`}
            tabIndex={0}
            key={active.id}
          >
            {screen === 'maps' ? <MapsScreen /> : null}
            {screen === 'alerts' ? <AlertsScreen /> : null}
            {screen === 'reports' ? <ReportsScreen /> : null}
          </div>
        </div>
      </div>
    </section>
  );
}
