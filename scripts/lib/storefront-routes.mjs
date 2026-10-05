/**
 * مجموعة مسارات المتجر — مصدرٌ واحد لكلّ ماسح.
 *
 * كانت القائمة مكتوبةً داخل `sweep-viewports.mjs`. وإضافةُ ماسحٍ ثانٍ
 * (لوحة المفاتيح) كانت ستنسخها، فتفترق النسختان عند أوّل مسارٍ يُضاف —
 * ويصير «كلّ المسارات مفحوصة» صحيحًا في أحدهما وكاذبًا في الآخر بلا إشعار.
 */

/** المسارات الساكنة — أنماطٌ لا تحتاج معرّفًا من البيانات. */
export const STATIC_ROUTES = [
  '/', '/shop', '/cart', '/checkout', '/search?q=%D9%84%D8%BA%D8%AA%D9%8A',
  '/policies/privacy', '/policies/terms', '/blog', '/about', '/contact',
  '/help', '/how-it-works', '/payment-methods', '/request', '/credits',
  '/stages', '/grades', '/subjects', '/semesters', '/kinds',
  '/offers', '/best-sellers', '/new', '/bundles',
  '/account/orders', '/account/downloads', '/account/library',
  '/login', '/orders/zzz', '/nope-404',
];

/**
 * الأبناء الديناميكيّون — معرّفاتهم تُجلب وقت التشغيل.
 *
 * قائمةٌ مكتوبةٌ بيدها تبور مع أوّل تغيير في البيانات، ثمّ تفحص صفحات ‎404‎
 * وهي تحسب أنّها تفحص صفحاتٍ حقيقيّة. والمعرّفات عربيّة، وهي بذاتها حالة
 * حدٍّ تستحقّ الفحص: ترميز النسبة في الرابط والاتّجاه في فتات الخبز.
 */
export async function discoverRoutes(api) {
  const found = [];

  const pick = async (path, map) => {
    try {
      const res = await fetch(api + path, { signal: AbortSignal.timeout(8000) });
      if (!res.ok) return;
      const body = await res.json();
      const first = (body?.data ?? [])[0];
      if (first?.slug) found.push(map(encodeURIComponent(first.slug)));
    } catch {
      /* الخادم غير جاهز أو النقطة غير موجودة — يُمضى بلا هذا المسار. */
    }
  };

  for (const dim of ['stages', 'grades', 'subjects', 'semesters', 'kinds']) {
    await pick(`/api/v1/catalog/${dim}?perPage=1`, (slug) => `/${dim}/${slug}`);
  }
  await pick('/api/v1/products?perPage=1', (slug) => `/products/${slug}`);

  return found;
}

/** القراءة الموحَّدة لوسائط سطر الأوامر. */
export const readArg = (name, fallback) => {
  const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.slice(name.length + 3) : fallback;
};

/** المجموعة النهائيّة: الساكنة + المكتشَفة، بلا تكرار. */
export async function resolveRoutes() {
  const api = readArg('api', 'http://127.0.0.1:58080');
  const routes = readArg('routes', STATIC_ROUTES.join(',')).split(',').filter(Boolean);

  if (!process.argv.includes('--no-discover')) {
    const extra = (await discoverRoutes(api)).filter((r) => !routes.includes(r));
    if (extra.length) console.log(`  اكتُشف ${extra.length} مسارًا ديناميكيًّا من ${api}\n`);
    routes.push(...extra);
  }

  return routes;
}

/*
 * مساراتٌ غيابُها هو المقيس: صفحة «غير موجود» نفسها تُمسح. كلُّ ما عداها
 * يجب أن يُحمَّل بنجاح.
 */
export const EXPECTED_NOT_FOUND = new Set(['/orders/zzz', '/nope-404']);

/**
 * `page.goto` بفحص الحالة. كانت الماسحات تمسح ما يُحمَّل كائنًا ما كان:
 * صفحةُ ٤٠٤ أو ٥٠٠ تمرّ «✓» لأنّ تخطيطها سليم — وحُقّق ذلك فعلًا حين
 * أجاب مكوّنٌ آخر على المنفذ فمُسحت صفحتُه الخطأ ثلاث مرّات بنجاح.
 * يرمي عند كلّ حالةٍ غير المتوقَّعة، فيسقط المسح بدل أن يكذب.
 */
export async function gotoChecked(page, base, route, options) {
  let res = await page.goto(base + route, options);
  /*
   * ‎429‎ أثناء التصيير الخادميّ ليس خرقًا في الصفحة بل إنهاكٌ للـAPI (ثلاثة
   * ماسحاتٍ متتابعة تكفي). يُنتظَر ‎Retry-After‎ (بسقف ‎30‎ ثانية) ويُعاد
   * التحميل مرّةً واحدة؛ فإن بقي الخنق رُمي خطأٌ موسومٌ `temporary` ليبلّغه
   * الماسح «متعذّرًا مؤقّتًا» لا خرقًا.
   */
  if (throttled(res) || (await throttledBody(page))) {
    const after = Math.min(30, Number(res?.headers()['retry-after']) || (await throttledBody(page)) || 5);
    await page.waitForTimeout(after * 1000 + 500);
    res = await page.goto(base + route, options);
    if (throttled(res) || (await throttledBody(page))) {
      const err = new Error(`متعذّر مؤقّتًا (429) على ${route} بعد إعادة المحاولة`);
      err.temporary = true;
      throw err;
    }
  }
  const status = res?.status() ?? 0;
  const path = route.split('#')[0];
  const ok = EXPECTED_NOT_FOUND.has(path) ? status === 404 : status >= 200 && status < 400;
  if (!ok) {
    const want = EXPECTED_NOT_FOUND.has(path) ? '404' : '2xx/3xx';
    throw new Error(`HTTP ${status || 'بلا ردّ'} على ${route} — المتوقَّع ${want}`);
  }
  return res;
}

const throttled = (res) => res?.status() === 429 || (res?.status() === 503 && !!res.headers()['retry-after']);
/** رسالة الخنق المصيَّرة في الصفحة («… أعد المحاولة بعد N ثانية») ⇒ الثواني، وإلّا ‎0‎. */
async function throttledBody(page) {
  const secs = await page
    .evaluate(() => document.body?.innerText.match(/تجاوزت عدد الطلبات المسموح[^\d]*(\d+)/)?.[1] ?? '')
    .catch(() => '');
  return secs ? Number(secs) : 0;
}

/** هل الخطأ «متعذّر مؤقّتًا» (خنق ‎429‎) لا خرقًا؟ */
export const isTemporary = (err) => err?.temporary === true;
