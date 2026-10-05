/**
 * مسارات لوحة التشغيل — مصدرٌ واحد لكلّ ماسح، وبوّابةٌ ترفض المسح بلا جلسة.
 *
 * ─── لماذا كُتبت ───
 *
 * الماسحات العشرة التي تستدعي `resolveRoutes` تأخذ مساراتها من
 * `storefront-routes.mjs` وتضع `BASE` على ‎127.0.0.1:3000‎، فاللوحة على ‎3001‎
 * بلا تغطيةٍ من أيّ بوّابة وصولٍ أو تخطيط. والخطر ليس النقص بل أنّ «كلّ
 * الماسحات خضراء» تُقرأ «الواجهة سليمة» ومعناها «المتجر سليم واللوحة لم
 * يُنظر إليها».
 *
 * ─── ولماذا لا تكفي قائمةٌ بأسماء المسارات ───
 *
 * اللوحة كلّها خلف بوّابة جلسة: `admin-shell.tsx:169-193` يعيد شاشة تحويلٍ
 * إلى `/login` متى كان `state.status === 'anonymous'`. وقياسٌ حيّ في
 * ‎٢٠٢٦-٠٩-٢٠‎ على ستّة مساراتٍ عميقة (`/`، `/dashboard`، `/orders`،
 * `/settings`، `/analytics/os`، `/login`) انتهى كلٌّ منها إلى `/login` نفسها:
 * عنوانٌ واحد، عنوانٌ واحد «تسجيل الدخول»، صفر عناصر ملاحة.
 *
 * فلو سُلّمت قائمةُ المسارات إلى الماسحات بلا جلسة لقاست ثلاثًا وخمسين مرّةً
 * صفحةَ الدخول ذاتها وأبلغت عنها «تغطية» — وهذا أسوأ من الصمت الحاليّ، لأنّ
 * الصمت يُعرف والتغطية الكاذبة تُصدَّق. لذلك `resolveRoutes` هنا **ترفض**
 * ولا تُرجع قائمةً ما لم تثبت الجلسة.
 *
 * ─── ولماذا تُشتقّ من القرص لا تُكتب بيد ───
 *
 * كانت هذه القائمة مكتوبةً بيدها. وقياسها في ‎٢٠٢٦-٠٩-٢٠‎ مقابل الشجرة:
 * ثلاثة عشر من اثنين وخمسين مدخلًا لم يعد لها `page.tsx`، وأربعة عشر مسارًا
 * قائمًا غائبٌ عنها — وفي شجرة العمل وقتَها اثنان وعشرون `page.tsx` محذوفًا
 * مقابل HEAD. أي أنّها بارت في ثلاثة أيّام. والاشتقاق من القرص يزيل هذا
 * الصنف من العطب كلّه، وهو عين الحجّة المكتوبة في `storefront-routes.mjs:23`.
 *
 * المقايضة المختارة ضدّها: قراءةُ القرص تكلّف نداءَ نظامِ ملفّاتٍ في كلّ
 * تشغيل، والقائمة الثابتة أرخص وتُراجَع في الـdiff. رُفضت لأنّ ثمنها أن
 * «كلّ المسارات مفحوصة» يصير كاذبًا بلا إشعار — وهو الخطأ الذي وقع فعلًا.
 */
import { readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

// `readArg` مصدرها واحد: لا تُنسخ هنا كي لا تفترق النسختان.
export { readArg } from './storefront-routes.mjs';
import { readArg } from './storefront-routes.mjs';

/** اللوحة على ‎3001‎ — والمتجر على ‎3000‎. الخلط بينهما يقيس التطبيق الخطأ. */
export const DEFAULT_BASE = 'http://127.0.0.1:3001';

const APP_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '../../apps/admin/app');

/**
 * يمشي شجرة `app/` ويجمع كلّ مجلّدٍ فيه `page.tsx`.
 *
 * مجموعات المسار `(workspaces)` تُحذف من العنوان — فهي تنظيمٌ للملفّات لا
 * جزءٌ من الرابط. ولا مسارات متوازية `@slot` ولا معترِضة `(.)` في اللوحة
 * (بُحث عنها في ‎٢٠٢٦-٠٩-٢٠‎ فلم تُوجد)، فلا تُعالَج هنا: معالجةُ حالةٍ غير
 * موجودة شيفرةٌ لا يُثبت أحدٌ صحّتها.
 */
function walk(dir, urlPath, out, root) {
  let entries;
  try {
    entries = readdirSync(dir, { withFileTypes: true });
  } catch (e) {
    /*
     * الجذر يرمي، والفرعُ يُتجاوَز — والفرق هو الفرق بين «لا مسار» و«لم أقرأ».
     *
     * ابتلاعُ كلّ خطأ كان يجعل الوحدة تُعيد `[]` حين يغيب `apps/admin/app`
     * أصلًا (مقيس: شجرةٌ بلا المجلّد ⇒ «٠ مسارًا ساكنًا» بلا اعتراض). وماسحٌ
     * يتسلّم قائمةً فارغة لا يفشل: يمرّ بصفر قياسات ويُقرأ «اللوحة مغطّاة
     * وسليمة» — وهو الادّعاء الكاذب الذي وُجدت هذه الوحدة لتمنعه.
     *
     * فالجذر المفقود عطبٌ يُرمى صراحةً؛ ومجلّدٌ فرعيّ اختفى أثناء المشي يُتجاوَز.
     */
    if (dir === root) {
      throw new Error(`تعذّرت قراءة جذر مسارات اللوحة «${dir}»: ${e.message} — لا تُعاد قائمةٌ فارغة مكان خطأ`);
    }
    return out;
  }

  if (entries.some((e) => e.isFile() && e.name === 'page.tsx')) {
    out.push(urlPath === '' ? '/' : urlPath);
  }

  for (const e of entries) {
    if (!e.isDirectory()) continue;
    const isGroup = e.name.startsWith('(') && e.name.endsWith(')');
    walk(join(dir, e.name), isGroup ? urlPath : `${urlPath}/${e.name}`, out, root);
  }

  return out;
}

/** كلّ مسارات اللوحة كما هي على القرص الآن — الساكنة والديناميكيّة معًا. */
export function allAdminRoutes() {
  return walk(APP_DIR, '', [], APP_DIR).sort();
}

/** الساكنة وحدها: ما لا يحتاج معرّفًا من البيانات، فيصلح للمسح مباشرةً. */
export function staticAdminRoutes() {
  return allAdminRoutes().filter((r) => !r.includes('['));
}

/**
 * الديناميكيّة: أنماطٌ لا تُخمَّن معرّفاتها.
 *
 * نظيرتها في المتجر تُكتشف من واجهةٍ عامّة (`discoverRoutes`)، أمّا هنا فكلّ
 * نقطةٍ تُعطي معرّفًا هي نفسها خلف الجلسة — فاكتشافها فرعٌ عن حلّ البوّابة لا
 * مسألةٌ مستقلّة. تُعرض كأنماطٍ صريحة بدل تخمينٍ يفحص ‎404‎ ويحسبها صفحة.
 */
export function dynamicAdminRoutes() {
  return allAdminRoutes().filter((r) => r.includes('['));
}

/** المسار الوحيد الذي يُقاس بلا جلسة — وكلّ ما سواه يُحوَّل إليه. */
export const ADMIN_PUBLIC_ROUTES = ['/login'];

/**
 * يسأل الخادمَ نفسَه: أهناك جلسة؟
 *
 * `/api/v1/admin/me` هي عين النقطة التي يستفتيها `admin-shell`، فجوابها هو
 * الحقيقة التي سيبني عليها المتصفّح — لا تخمينٌ عنها. ‎200‎ جلسةٌ قائمة،
 * و‎401‎ لا جلسة، وأيّ شيءٍ آخر خادمٌ لا يُفهم فيُعامَل معاملةَ المنع.
 *
 * تعمد الدالّة إلى الفشل المغلق: عند انقطاع الشبكة تمنع ولا تسمح. ماسحٌ لا
 * يعمل يُلاحَظ في الحال، وماسحٌ يقيس الشيء الخطأ لا يُلاحَظ أبدًا.
 */
export async function probeAdminSession(base = DEFAULT_BASE, cookie = '') {
  try {
    const res = await fetch(`${base}/api/v1/admin/me`, {
      headers: cookie ? { cookie } : {},
      signal: AbortSignal.timeout(8000),
    });
    if (res.status !== 200) {
      return { ok: false, status: res.status, reason: `‎/api/v1/admin/me‎ أعادت ${res.status}` };
    }
    /*
     * ٢٠٠ ليست جلسة — والهويّة هي.
     *
     * قبولُ الحالة وحدها يمرّ على ‎٢٠٠‎ جسمُها `null` (مقيس بخادمٍ بديل:
     * البوّابة سمحت وأعادت ٥٣ مسارًا). واللوحة نفسها تقرأ `data.csrfToken`
     * في `components/admin-session.tsx:52-55`، فجوابٌ بلا هويّةٍ يُمرِّر
     * الماسحَ إلى صفحاتٍ تُصيَّر شاشةَ دخول — أي قياسُ الشيء الخطأ، وهو ما
     * يقول رأسُ هذه الدالّة إنّه لا يُلاحَظ أبدًا.
     */
    let body = null;
    try { body = await res.json(); } catch { /* جسمٌ غير صالح ⇒ لا هويّة */ }
    const identity = body && typeof body === 'object' ? (body.data ?? null) : null;
    if (identity === null || typeof identity !== 'object') {
      return { ok: false, status: 200, reason: '‎٢٠٠‎ بلا هويّةٍ في `data` — ليست جلسة' };
    }
    return { ok: true, status: 200, reason: 'جلسةٌ قائمة' };
  } catch (e) {
    return { ok: false, status: 0, reason: `تعذّر بلوغ ${base}: ${e.message}` };
  }
}

/**
 * المجموعة النهائيّة — بالشكل الذي تستهلكه الماسحات العشرة نفسه.
 *
 * ترفض ولا تُرجع قائمةً بلا جلسة. الوسيط `--public-only` هو المخرج الأمين:
 * يقيس `/login` وحدها ويقول إنّها وحدها ما قيس.
 */
export async function resolveRoutes() {
  const base = readArg('base', DEFAULT_BASE);
  const cookie = readArg('cookie', process.env.ADMIN_SWEEP_COOKIE ?? '');

  if (process.argv.includes('--public-only')) {
    console.log(`  اللوحة: السطح العامّ وحده — ${ADMIN_PUBLIC_ROUTES.join('، ')}\n`);
    return [...ADMIN_PUBLIC_ROUTES];
  }

  const session = await probeAdminSession(base, cookie);
  if (!session.ok) {
    const n = staticAdminRoutes().length;
    throw new Error(
      `لا جلسةَ لوحةٍ على ${base} — ${session.reason}.\n` +
        `  رُفض المسح: بلا جلسة يُحوَّل كلٌّ من ${n} مسارًا إلى ‎/login‎، فتقيس\n` +
        `  الماسحة صفحةَ الدخول ${n} مرّة وتبلّغ عنها «تغطية». راجع\n` +
        `  docs/audits/sweep-coverage-2026-09-20.md.\n` +
        `  للمسح العامّ الأمين: ‎--public-only‎. وبجلسة: ‎--cookie="…"‎ أو ‎ADMIN_SWEEP_COOKIE‎.`,
    );
  }

  const routes = readArg('routes', staticAdminRoutes().join(',')).split(',').filter(Boolean);
  console.log(`  اللوحة: ${routes.length} مسارًا ساكنًا بجلسةٍ مثبتة\n`);
  return routes;
}
