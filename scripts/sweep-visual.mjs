#!/usr/bin/env node
/**
 * مسحُ الانحدار البصريّ — لقطاتٌ ثابتة تُقارَن بخطٍّ أساسٍ مخزون.
 *
 * ─── لماذا وُجد ───
 *
 * كلُّ ماسحٍ آخر يقيس خاصّيّةً واحدة (فيض · تباين · لوحة مفاتيح). وما لا
 * يقيسه أحدها هو «هل ما زالت الصفحة تبدو كما اعتُمدت؟» — قسمٌ انزاح، ظلٌّ
 * ضاع، خطٌّ بُدّل. هذا الماسح يلتقط الأسطح المعتمَدة على الخادم الحتميّ
 * (بيانات التصميم العيّنيّة، لا القاعدة الحيّة) ويقارنها بكسلًا بكسلًا.
 *
 * ─── الخوادم ───
 *
 * يعمل على أيّ متجرٍ قائم يُعطى بـ `--base` (الافتراضيّ `http://127.0.0.1:3000`
 * كسائر الماسحات). الحتميّة شرطٌ على المشغِّل: بياناتٌ ثابتة خلف المتجر، وإلّا
 * تحرّك ما يُقارَن بالخطّ الأساس — راجع «الأسطح المقنَّعة» أدناه.
 *
 * ─── التحكّم في الرئيسة ───
 *
 * أشكال البطل وأقسام الرئيسة الاختياريّة تُبدَّل عبر خادم تحكّمٍ يُعطى بـ `--mock`
 * ويجيب نقطتين:
 *   POST /__mock/hero   {variant: slider|institutional|bold|luxe|brand}
 *   POST /__mock/layout {sections: [...]}
 * بلا `--mock` تُتخطّى هذه الأسطح صراحةً: لا تُحسب مطابقةً ولا فشلًا. ومعه يعيد
 * الماسح الرئيسة إلى أصلها عند نهايته.
 *
 * ─── التجميد ───
 *
 *   · `prefers-reduced-motion: reduce` + ‎`*{animation:none;transition:none}`‎
 *   · `Date` مثبّت على 2026-09-24T09:00+03 و`Math.random` ببذرةٍ ثابتة
 *     (`addInitScript` قبل أيّ سكربت للصفحة)
 *   · كعكة الموافقة `tahdheer_consent=granted` قبل التحميل
 *   · انتظار `document.fonts.ready` واكتمال كلّ الصور بعد تمريرٍ كامل
 *     (يُطلق الصور الكسولة)
 *
 * ─── الأوضاع ───
 *
 *   npm run test:visual                       # مقارنة (يفشل عند أيّ انحراف)
 *   npm run test:visual -- --update           # كتابة الخطّ الأساس
 *   npm run test:visual -- --only=pdp,home    # تصفية بالمعرّف (بادئة)
 *   npm run test:visual -- --base=http://127.0.0.1:3000 --mock=<خادم التحكّم>
 *
 * الخطّ الأساس: `scripts/__visual__/baselines/<id>@<w>.png` — مصغَّرٌ إلى
 * ‎720px‎ عرضًا ومكمَّمٌ بلوحة ألوان (صغير، يُلتزم به في git).
 * الفروق: `test-results/visual/` (خارج git).
 *
 * العتبات في `scripts/lib/visual-diff.mjs`: ‎≤0.5%‎ كلّيًّا **و** لا شريط ‎64px‎
 * يتجاوز ‎1%‎ — فالقسم المنزاح يسقط ولو صغرت نسبته الكلّيّة.
 */

import { chromium } from 'playwright';
import { mkdirSync, writeFileSync, readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readArg, gotoChecked } from './lib/storefront-routes.mjs';
import { shrinkPng, diffPng, DEFAULTS } from './lib/visual-diff.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = dirname(HERE);
const BASE = readArg('base', 'http://127.0.0.1:3000');
const MOCK = readArg('mock', '');
const UPDATE = process.argv.includes('--update');
const ONLY = readArg('only', '').split(',').filter(Boolean);
const BASELINES = join(ROOT, 'scripts', '__visual__', 'baselines');
const OUT = readArg('out', join(ROOT, 'test-results', 'visual'));
/* ‎--save-actual=<مجلّد>‎: تُكتب كلّ لقطةٍ مصغّرة هناك — لإثبات الحتميّة بمقارنة
   تشغيلين أحدهما بالآخر (`scripts/visual-determinism.mjs`) بمعزلٍ عن الخطّ الأساس. */
const SAVE_ACTUAL = readArg('save-actual', '');
const OPTS = {
  maxDiffPct: Number(readArg('max-pct', DEFAULTS.maxDiffPct)),
  bandPct: Number(readArg('band-pct', DEFAULTS.bandPct)),
};

const FIXED_NOW = Date.parse('2026-09-24T09:00:00+03:00');
const MOBILE = 390;
const TABLET = 768;
const DESKTOP = 1440;
const PDP = '/products/science-5-t1-prep';

/** أقسام الرئيسة غير المفعَّلة افتراضيًّا (`lib/homepage.ts`). */
const OPTIONAL_SECTIONS = [
  'smart-finder', 'stages-explorer', 'lesson-bundle', 'curriculum-navigator',
  'product-types', 'popular-by-grade', 'featured', 'subjects', 'promo', 'why', 'benefits', 'faq',
];
const HERO_VARIANTS = ['institutional', 'bold', 'luxe', 'brand'];
const DEFAULT_LAYOUT = ['hero', 'trust', 'stages', 'best', 'all-products', 'samples', 'reviews', 'cta'];

/**
 * السطح: معرّف + مسار + عروض. `mock` يضبط المحاكي قبل التحميل؛ `viewport`
 * يلتقط الإطار المرئيّ فقط (حالات الشريط السفليّ والقائمة)؛ `act` يعمل بعد
 * التحميل؛ `scroll` موضعٌ رأسيّ يُثبَّت قبل اللقطة؛ `storage` يُزرع في localStorage قبل أيّ سكربت؛ `mask` محدِّداتٌ
 * تُطمس بالأسود قبل اللقطة (محتوًى غيرُ حتميّ — وثّق السبب بجانبه).
 */
const SURFACES = [
  { id: 'home', route: '/', widths: [MOBILE, DESKTOP] },
  ...HERO_VARIANTS.map((v) => ({ id: `home-hero-${v}`, route: '/', widths: [MOBILE, DESKTOP], mock: { hero: v, layout: ['hero'] } })),
  ...OPTIONAL_SECTIONS.map((s) => ({ id: `home-section-${s}`, route: '/', widths: [MOBILE, DESKTOP], mock: { layout: [s] } })),
  { id: 'pdp', route: PDP, widths: [MOBILE, TABLET, DESKTOP] },
  { id: 'stages', route: '/stages', widths: [MOBILE, TABLET, DESKTOP] },
  { id: 'stage-primary', route: '/stages/primary', widths: [MOBILE, TABLET, DESKTOP] },
  { id: 'search', route: '/search?q=%D8%B9%D9%84%D9%88%D9%85', widths: [MOBILE, TABLET, DESKTOP] },
  { id: 'best-sellers', route: '/best-sellers', widths: [MOBILE, TABLET, DESKTOP] },
  { id: 'cart-empty', route: '/cart', widths: [MOBILE, DESKTOP] },
  { id: 'checkout-empty', route: '/checkout', widths: [MOBILE, DESKTOP] },
  { id: 'login', route: '/login', widths: [MOBILE, DESKTOP] },
  { id: 'wishlist-empty', route: '/wishlist', widths: [MOBILE, DESKTOP] },
  {
    id: 'wishlist-seeded', route: '/wishlist', widths: [MOBILE, DESKTOP],
    storage: {
      /* منتجٌ واحد يعرفه المحاكي: منتجٌ مجهول يُنعش من القاعدة الحيّة فيتقلّب
         بين «تعذّر التحديث» وبطاقةٍ منهارة بحسب التوقيت. */
      tahdheer_wishlist: JSON.stringify([
        { slug: 'science-5-t1-prep', title: 'تحضير العلوم كامل — الصف الخامس الابتدائي', priceMinor: 4500, currency: 'SAR', subtitle: 'DOCX' },
      ]),
    },
  },
  { id: 'help', route: '/help', widths: [MOBILE, DESKTOP] },
  { id: 'policy-refund', route: '/policies/refund', widths: [MOBILE, DESKTOP] },
  { id: 'not-found', route: '/nope-404', widths: [MOBILE, DESKTOP] },
  /* حالات الجوّال: الشريط السفليّ على الرئيسة بعد التمرير، شريط الشراء
     الملتصق في صفحة المنتج، ولوحة الملاحة المفتوحة في الترويسة. */
  { id: 'mobile-bottom-bar-home', route: '/', widths: [MOBILE], viewport: true, scroll: 700 },
  { id: 'mobile-sticky-purchase-pdp', route: PDP, widths: [MOBILE], viewport: true, scroll: 1400 },
  {
    id: 'mobile-app-bar-nav-open', route: '/', widths: [MOBILE], viewport: true,
    /* القائمة على الجوّال `<details>` ملخّصها «القائمة» — لا زرّ ‎aria-expanded‎
       (ذاك زرّ فروع الشريط المكتبيّ، مخفيٌّ هنا). غيابُ الملخّص خطأُ قياسٍ لا لقطة. */
    act: async (p) => {
      const toggle = p.locator('header summary:visible', { hasText: 'القائمة' }).first();
      if (!(await toggle.count())) throw new Error('لم يُعثر على ملخّص «القائمة» في الترويسة');
      if (!(await toggle.evaluate((el) => el.closest('details')?.open))) await toggle.click();
      await p.waitForTimeout(200);
    },
  },
];

/*
 * تمريرٌ حتميّ للّقطات المحصورة في الإطار: `scrollTo` قد يُبتلع إن أعاد
 * React التصيير أو أعاد المتصفّح موضع التمرير بعده، فيُعاد حتّى يثبت
 * ‎scrollY‎ على الهدف (أو على أقصى ما تسمح به الصفحة) ثمّ يُنتظر إطاران.
 */
async function scrollExactly(page, top) {
  for (let i = 0; i < 10; i++) {
    const at = await page.evaluate((y) => {
      window.scrollTo({ top: y, left: 0, behavior: 'instant' });
      return new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() =>
        r({ y: Math.round(window.scrollY), max: document.documentElement.scrollHeight - window.innerHeight }))));
    }, top);
    if (at.y === Math.min(top, Math.max(0, Math.round(at.max)))) break;
    await page.waitForTimeout(250);
  }
  await page.waitForTimeout(300);
}

const FREEZE_CSS = `
  *, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; scroll-behavior: auto !important; }
  nextjs-portal { display: none !important; } /* مؤشّر Next التطويريّ («Compiling…») ليس من الصفحة */
`;

/*
 * السلّة تُقرأ من القاعدة الحيّة: حالةٌ مشتركة مع جلساتٍ
 * أخرى ومحدودة بـ‎300‎ قراءة/دقيقة — فتظهر ‎429‎ وعدّادُ «أعد المحاولة بعد N
 * ثانية» عشوائيًّا. تُجاب طلبات ‎/api/v1/cart‎ من المتصفّح بسلّةٍ فارغة ثابتة.
 */
const EMPTY_CART = JSON.stringify({ items: [], totals: { grossMinor: 0, productDiscountMinor: 0, couponDiscountMinor: 0, totalMinor: 0, vatMinor: 0, savingMinor: 0 }, coupon: null, count: 0 });
const CART_ROUTE = /\/api\/v1\/cart(\/|\?|$)/;

function initScript({ storage }) {
  return `
    (() => {
      const FIXED = ${FIXED_NOW};
      const RealDate = Date;
      class FrozenDate extends RealDate {
        constructor(...a) { super(...(a.length ? a : [FIXED])); }
        static now() { return FIXED; }
      }
      FrozenDate.UTC = RealDate.UTC; FrozenDate.parse = RealDate.parse;
      window.Date = FrozenDate;
      let seed = 0x9e3779b9;
      Math.random = () => { seed = (seed + 0x6d2b79f5) | 0; let t = Math.imul(seed ^ (seed >>> 15), 1 | seed); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
      try {
        const entries = ${JSON.stringify(Object.entries(storage ?? {}))};
        for (const [k, v] of entries) window.localStorage.setItem(k, v);
      } catch {}
      /* التجميد يعيش مع إعادة التحميل (خادم التطوير قد يعيد تحميل الصفحة كاملةً
         بعد تعديلٍ من جلسةٍ مجاورة): النمط يُحقن عند كلّ DOMContentLoaded،
         ومؤشّر Next التطويريّ يُزال كلّما أُضيف. */
      const freeze = () => {
        if (!document.head) return;
        if (!document.getElementById('visual-freeze')) {
          const st = document.createElement('style');
          st.id = 'visual-freeze';
          st.textContent = ${JSON.stringify(FREEZE_CSS)};
          document.head.append(st);
        }
        document.querySelectorAll('nextjs-portal').forEach((n) => n.remove());
      };
      document.addEventListener('DOMContentLoaded', freeze);
      new MutationObserver(freeze).observe(document.documentElement, { childList: true, subtree: true });
    })();
  `;
}

async function control(path, body) {
  const res = await fetch(MOCK + path, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) throw new Error(`مركز التحكّم ${path} أجاب ${res.status}: ${await res.text()}`);
}
/*
 * ‎`apiGet`‎ يخزّن ردود الرئيسة ‎60‎ ثانية (‎PUBLIC_REVALIDATE_SECONDS‎)، فتبديلُ
 * المحاكي وحده لا يصل إلى الصفحة. يُطلب إبطالُ ‎/‎ عبر ‎/api/revalidate‎ بسرّ
 * ‎REVALIDATE_SECRET‎ — يُقرأ من البيئة أو من ملفّ بيئة المتجر المحلّيّ.
 */
function revalidateSecret() {
  if (process.env.REVALIDATE_SECRET) return process.env.REVALIDATE_SECRET;
  const p = join(ROOT, 'apps/storefront/.env.local');
  if (!existsSync(p)) return '';
  const m = readFileSync(p, 'utf8').match(/^REVALIDATE_SECRET=(.+)$/m);
  return m ? m[1].trim() : '';
}
const SECRET = revalidateSecret();
async function revalidateHome(path = '/') {
  if (!SECRET) throw new Error('REVALIDATE_SECRET غير متاح — لا يمكن إبطال ذاكرة الرئيسة');
  const res = await fetch(`${BASE}/api/revalidate`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-revalidate-secret': SECRET },
    body: JSON.stringify({ paths: [path] }),
  });
  if (!res.ok) throw new Error(`/api/revalidate أجاب ${res.status}`);
}
/*
 * الإبطالُ كسول: أوّلُ طلبٍ بعده يُخدَم من القديم ويُطلق التصيير الجديد.
 * فتُقرأ بصمة الصفحة (عدد الأقسام والعناوين) قبل التبديل ويُنتظَر حتّى تتغيّر.
 */
async function fingerprint() {
  const html = await (await fetch(`${BASE}/`)).text();
  return `${(html.match(/<section/g) ?? []).length}:${(html.match(/<h2/g) ?? []).length}:${html.length}`;
}
async function waitForHomeChange(before) {
  for (let i = 0; i < 20; i++) {
    await new Promise((r) => setTimeout(r, 500));
    const now = await fingerprint();
    if (now !== before) {
      /* تغيّرٌ واحد قد يكون تصييرًا وسيطًا من الذاكرة القديمة: يُشترط ثباتُ
         البصمة في قراءتين متتاليتين قبل اللقطة. */
      for (let j = 0, prev = now; j < 10; j++) {
        await new Promise((r) => setTimeout(r, 700));
        const again = await fingerprint();
        if (again === prev) return;
        prev = again;
      }
      return;
    }
  }
  throw new Error('الرئيسة لم تتغيّر بعد إبطال الذاكرة — راجع REVALIDATE_SECRET والمحاكي');
}
let mockKey = JSON.stringify(null);
async function applyMock(mock) {
  const next = JSON.stringify(mock ?? null);
  if (next === mockKey) return;
  mockKey = next;
  const before = await fingerprint();
  await control('/__mock/hero', { variant: mock?.hero ?? 'slider' });
  await control('/__mock/layout', { sections: mock?.layout ?? DEFAULT_LAYOUT });
  await revalidateHome();
  await waitForHomeChange(before);
}

/*
 * تصييرٌ متدهور ليس انحدارًا بصريًّا: قسمٌ سقط إلى حدّ خطئه («تعذّر تحميل
 * هذا القسم») أو رسالةُ خنق ‎429‎ تعني أنّ جلبًا خادميًّا عبر المحاكي إلى
 * القاعدة الحيّة فشل — لا أنّ التصميم تغيّر. يُبطَل المسار ويُعاد التحميل
 * حتّى مرّتين؛ فإن بقي التدهور سقطت اللقطة «متدهورة» لا «مختلفة».
 */
const DEGRADED = /تعذّر تحميل هذا القسم|تجاوزت عدد الطلبات المسموح/;
async function degraded(page) {
  return page.evaluate((re) => new RegExp(re).test(document.body?.innerText ?? ''), DEGRADED.source).catch(() => false);
}

/** استقرار الصفحة: خطوط + صور (بعد تمريرٍ كامل يُطلق الكسول) + هدوء الشبكة. */
async function settle(page) {
  await page.addStyleTag({ content: FREEZE_CSS });
  await page.evaluate(async () => {
    const step = Math.max(400, window.innerHeight - 100);
    /* سقفٌ للخطوات: صفحةٌ يطول ارتفاعها مع التمرير لا تُدخل المسح في حلقةٍ لا تنتهي. */
    for (let y = 0, i = 0; y < document.documentElement.scrollHeight && i < 120; y += step, i++) {
      window.scrollTo(0, y);
      await new Promise((r) => setTimeout(r, 40));
    }
    window.scrollTo(0, 0);
    await document.fonts.ready;
    await Promise.race([
      Promise.all([...document.images].filter((i) => !i.complete).map((i) => new Promise((r) => { i.onload = i.onerror = r; }))),
      new Promise((r) => setTimeout(r, 15000)),
    ]);
  }, undefined, { timeout: 45_000 }).catch(() => {});
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(300);
  /* الحركات المدفوعة بـJS (مكتبة motion عبر WAAPI) لا يوقفها CSS: تُنهى كلّها
     إلى حالتها الأخيرة فتتطابق اللقطة مهما كان الإطار الذي وصلت إليه. */
  await finishAnimations(page);
}
async function finishAnimations(page) {
  await page.evaluate(() => {
    for (const a of document.getAnimations()) { try { a.finish(); } catch { /* حركةٌ لا نهائيّة: تُلغى */ a.cancel(); } }
  }).catch(() => {});
  await page.waitForTimeout(100);
}

async function maskAll(page, selectors) {
  if (!selectors?.length) return;
  await page.addStyleTag({ content: selectors.map((s) => `${s}{visibility:hidden!important;background:#000!important}`).join('') });
}

function dirSize(dir) {
  if (!existsSync(dir)) return { files: 0, bytes: 0 };
  const files = readdirSync(dir).filter((f) => f.endsWith('.png'));
  return { files: files.length, bytes: files.reduce((n, f) => n + statSync(join(dir, f)).size, 0) };
}

const surfaces = SURFACES.filter((s) => !ONLY.length || ONLY.some((p) => s.id.startsWith(p)));
mkdirSync(BASELINES, { recursive: true });
mkdirSync(OUT, { recursive: true });

/* المتصفّح يُعاد إطلاقه إن سقط (ضغط الذاكرة يقتل كروم في منتصف المسح). */
/*
 * تنقيطٌ حتميّ: ظلٌّ ضبابيّ كبير (ظلّ الشريط السفليّ ‎0 -14px 34px‎) يُنقَّط على
 * المعالج الرسوميّ بفروقٍ ‎±1‎ بين تشغيلين، والترميز باللوحة يضخّمها إلى صفوفٍ
 * كاملة — فشل إثبات الحتميّة على `mobile-bottom-bar-home@390` لهذا وحده. تنقيط
 * البرمجيّات وملفّ ألوانٍ ثابت يُزيلان المصدر لا يُخفيانه (لا قناع).
 */
const LAUNCH = { args: ['--disable-gpu', '--disable-gpu-rasterization', '--force-color-profile=srgb', '--disable-lcd-text', '--font-render-hinting=none'] };
let browser = await chromium.launch(LAUNCH);
const results = [];
const skipped = [];
console.log(`\n  مسح الانحدار البصريّ — ${UPDATE ? 'كتابة الخطّ الأساس' : 'مقارنة'} · ${BASE}\n`);

try {
  for (const s of surfaces) {
    if (s.mock && !MOCK) {
      for (const width of s.widths) {
        const key = `${s.id}@${width}`;
        skipped.push(key);
        console.log(`  - ${key.padEnd(44)} متخطّاة — تحتاج خادم تحكّم (--mock)`);
      }
      continue;
    }
    await applyMock(s.mock);
    for (const width of s.widths) {
      const key = `${s.id}@${width}`;
      if (!browser.isConnected()) browser = await chromium.launch(LAUNCH);
      const context = await browser.newContext({
        viewport: { width, height: 900 },
        deviceScaleFactor: 1,
        locale: 'ar-SA',
        timezoneId: 'Asia/Riyadh',
        reducedMotion: 'reduce',
        colorScheme: 'light',
      });
      await context.addCookies([{ name: 'tahdheer_consent', value: 'granted', url: BASE }]);
      await context.route(CART_ROUTE, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: EMPTY_CART }));
      await context.addInitScript(initScript({ storage: s.storage }));
      const page = await context.newPage();
      let entry = { key, ok: false };
      try {
        /* خادم التطوير قد يعيد التحميل كاملًا وسط الاستقرار (تعديلٌ من جلسةٍ
           مجاورة يُدمَّر معه سياق التنفيذ) — تُعاد المحاولة مرّتين. */
        for (let attempt = 0; ; attempt++) {
          try {
            await gotoChecked(page, BASE, s.route, { waitUntil: 'networkidle', timeout: 60_000 });
            for (let r = 0; r < 2 && (await degraded(page)) && !s.allowDegraded; r++) {
              await page.waitForTimeout(6000);
              await revalidateHome(s.route.split('?')[0]).catch(() => {});
              await page.reload({ waitUntil: 'networkidle', timeout: 60_000 });
            }
            if (!s.allowDegraded && (await degraded(page))) throw new Error('تصييرٌ متدهور (فشل جلبٍ خادميّ) رغم إعادة المحاولة — لم تُقس');
            await settle(page);
            if (s.act) await s.act(page);
            if (s.scroll) await scrollExactly(page, s.scroll);
            break;
          } catch (e) {
            if (attempt >= 2 || !/context was destroyed|navigation/i.test(e.message)) throw e;
            await page.waitForTimeout(1500);
          }
        }
        await maskAll(page, s.mask);
        await finishAnimations(page);
        /* التمرير يُعاد مباشرةً قبل اللقطة: الأقنعة وإنهاء الحركات قد يغيّران الموضع. */
        if (s.scroll) await scrollExactly(page, s.scroll);
        const raw = await page.screenshot({ fullPage: !s.viewport, type: 'png', animations: 'disabled', caret: 'hide' });
        const small = await shrinkPng(raw);
        if (SAVE_ACTUAL) { mkdirSync(SAVE_ACTUAL, { recursive: true }); writeFileSync(join(SAVE_ACTUAL, `${key}.png`), small); }
        const file = join(BASELINES, `${key}.png`);
        if (UPDATE) {
          writeFileSync(file, small);
          entry = { key, ok: true, bytes: small.length };
        } else if (!existsSync(file)) {
          writeFileSync(join(OUT, `${key}.actual.png`), small);
          entry = { key, ok: false, note: 'لا خطّ أساس — شغّل --update' };
        } else {
          const r = await diffPng(readFileSync(file), small, OPTS);
          entry = { key, ok: r.ok, diffPct: r.diffPct, band: r.worstBand.pct, bandY: r.worstBand.y, size: r.sizeMismatch ? `${r.a.w}×${r.a.h}→${r.b.w}×${r.b.h}` : null };
          if (!r.ok) {
            writeFileSync(join(OUT, `${key}.actual.png`), small);
            writeFileSync(join(OUT, `${key}.diff.png`), r.diffPng);
          }
        }
      } catch (e) {
        entry = { key, ok: false, note: e.message.split('\n')[0] };
      } finally {
        await context.close().catch(() => {});
      }
      results.push(entry);
      const detail = entry.note ?? (UPDATE ? `${(entry.bytes / 1024).toFixed(0)} KB` : `diff ${entry.diffPct}% · شريط ${entry.band}%${entry.size ? ` · حجم ${entry.size}` : ''}`);
      console.log(`  ${entry.ok ? '✓' : '×'} ${key.padEnd(44)} ${detail}`);
    }
  }
} finally {
  await applyMock(null).catch(() => {});
  await browser.close().catch(() => {});
}

const failed = results.filter((r) => !r.ok);
const size = dirSize(BASELINES);
writeFileSync(join(OUT, 'report.json'), JSON.stringify({ base: BASE, update: UPDATE, opts: OPTS, results, skipped }, null, 2));
console.log(`\n  ${results.length - failed.length}/${results.length} لقطة مطابقة${skipped.length ? ` · ${skipped.length} متخطّاة (بلا --mock)` : ''} · الخطّ الأساس ${size.files} ملفًّا · ${(size.bytes / 1024 / 1024).toFixed(2)} MB`);
if (failed.length && !UPDATE) console.log(`  الفروق تحت ${OUT}/`);
console.log();
process.exit(failed.length ? 1 : 0);
