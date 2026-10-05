/**
 * مظهر المتجر — فاتح / داكن / حسب النظام (عقد نظام التصميم §2 «الوضع الداكن»).
 *
 * ─── العقد ───
 *
 *  · `data-theme="light|dark"` على `<html>` هو الحالة **المحلولة** دائمًا؛ رموز
 *    `tokens.css` تقرأ `:root[data-theme='dark']` وحده. الاختيار `system` لا يصل
 *    إلى CSS أبدًا — يُحلّ هنا إلى أحد الاثنين.
 *  · الاختيار المحفوظ في `localStorage[THEME_KEY]`؛ غيابه (أو قيمةٌ فاسدة) = `system`.
 *  · السمة تُكتب **قبل الرسم** بسكربتٍ سطريّ في `<head>` (`THEME_BOOT_SCRIPT`)،
 *    فلا ومضة فاتحة قبل الداكن. والسكربت نصٌّ ثابت مشتقٌّ من الثوابت أدناه.
 */

export type ThemePref = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

export const THEME_KEY = 'tahdheer.theme';
export const THEME_ATTR = 'data-theme';
/** حدثٌ داخل النافذة: نسختا المفتاح (الترويسة والدرج) تتزامنان به. */
export const THEME_EVENT = 'tahdheer:theme';

const DARK_QUERY = '(prefers-color-scheme: dark)';

export const isThemePref = (v: unknown): v is ThemePref => v === 'light' || v === 'dark' || v === 'system';

export const resolveTheme = (pref: ThemePref, systemDark: boolean): ResolvedTheme =>
  pref === 'system' ? (systemDark ? 'dark' : 'light') : pref;

export function readThemePref(): ThemePref {
  try {
    const v = window.localStorage.getItem(THEME_KEY);
    return isThemePref(v) ? v : 'system';
  } catch {
    return 'system';
  }
}

export const systemPrefersDark = (): boolean => window.matchMedia(DARK_QUERY).matches;

/** يكتب السمة المحلولة على الجذر. */
export function applyTheme(pref: ThemePref): void {
  document.documentElement.setAttribute(THEME_ATTR, resolveTheme(pref, systemPrefersDark()));
}

/** يحفظ الاختيار ويطبّقه ويبلغ النسخ الأخرى. `system` يمحو المفتاح (الافتراض). */
export function setThemePref(pref: ThemePref): void {
  try {
    if (pref === 'system') window.localStorage.removeItem(THEME_KEY);
    else window.localStorage.setItem(THEME_KEY, pref);
  } catch {
    /* تخزينٌ محجوب (تصفّح خاصّ): يبقى الاختيار لهذه الصفحة وحدها. */
  }
  applyTheme(pref);
  window.dispatchEvent(new CustomEvent(THEME_EVENT, { detail: pref }));
}

/**
 * سكربت ما قبل الرسم — يُحقن في `<head>` كما هو. لا يعتمد على شيءٍ محمَّل،
 * ويسقط إلى الفاتح بصمت إن تعذّر التخزين.
 */
export const THEME_BOOT_SCRIPT = `(function(){try{var p=localStorage.getItem(${JSON.stringify(THEME_KEY)});if(p!=="light"&&p!=="dark"){p=matchMedia(${JSON.stringify(DARK_QUERY)}).matches?"dark":"light"}document.documentElement.setAttribute(${JSON.stringify(THEME_ATTR)},p)}catch(e){document.documentElement.setAttribute(${JSON.stringify(THEME_ATTR)},"light")}})();`;
