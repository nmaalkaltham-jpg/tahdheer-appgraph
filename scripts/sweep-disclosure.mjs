/**
 * ماسح الكاشفات — ما لا يوجد في DOM إلّا بعد أن يُنقر.
 *
 * ─── الثغرة التي يسدّها ───
 *
 * كلّ ماسحٍ حتّى الآن يقرأ الصفحة **ساكنة**. ولوحاتُ الدفع والسلّة والمراجعات
 * والأسئلة مطويّةٌ عند التحميل، فما بداخلها لم يُقس قطّ: لا فيضُه، ولا
 * وصولُه بلوحة المفاتيح، ولا صدقُ `aria-expanded` عنه.
 *
 * ─── وما يُقاس ───
 *
 *  ١· الفتح: `aria-expanded` ينقلب، والهدف يصير ظاهرًا فعلًا.
 *  ٢· مجتمعةً: كلّ الكاشفات مفتوحة معًا — أقصى محتوًى تحمله الصفحة. فيُقاس
 *     الفيض الأفقيّ والقصّ هناك، لا في أخفّ حالاتها.
 *  ٣· الإغلاق: تعود مطويّةً، والسمة تصدق.
 *
 * ─── وما لا يُلمس ───
 *
 * الروابط وأزرار الإرسال. النقر هنا يفتح ويطوي فقط — لا يشتري، ولا يرسل
 * نموذجًا، ولا ينتقل. والماسح الذي يشتري شيئًا ليس ماسحًا.
 *
 *   npm run sweep:disclosure
 */
import { chromium } from 'playwright';
import { readArg, resolveRoutes, gotoChecked } from './lib/storefront-routes.mjs';
import { sweepVerdict } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const WIDTHS = readArg('widths', '390,1440').split(',').map(Number);

/*
 * سلّةٌ فيها منتج — وإلّا لم تُقس لوحات السلّة والدفع إطلاقًا.
 *
 * `/cart` و`/checkout` تُصيّران حالتَهما الفارغة الصادقة حين لا سلّة، فلا
 * لوحةَ قسيمةٍ ولا ملخّصَ طلبٍ ولا حقلَ ضريبة. وتلك اللوحات هي بالضبط ما
 * يُفترض أن يقيسه هذا الماسح. فيُضاف منتجٌ واحد مرّةً في كلّ سياق.
 */
const SEED_CART = !process.argv.includes('--no-seed');
/* مسار المنتج الذي يُبذر منه — يُؤخذ من الاكتشاف، أو يُمرَّر صراحةً حين
   يُشغَّل الماسح بـ`--no-discover` (وإلّا لم يكن في القائمة منتجٌ أصلًا). */
const SEED_PRODUCT = readArg('seed-product', '');

/*
 * ميزانيّة الخادم تُحترَم، لا تُتجاوَز.
 *
 * ─── العطل المقيس (٢٠٢٦-٠٩-٢٠) ───
 *
 * `GET /api/v1/cart` و`POST /api/v1/cart/items` كلاهما في دلو `write`:
 * ستّون طلبًا في الدقيقة لكلّ عنوان
 * (`backend/laravel/app/Providers/RateLimitServiceProvider.php:53`
 * و`backend/laravel/routes/api-commerce.php:13`). وتحميلةُ صفحةٍ واحدة في
 * المتجر تُطلق ٦ إلى ٧ نداءات `GET /api/v1/cart` (مقيسة بالتقاط الردود:
 * صفحة منتجٍ ٦، `/cart` ٧، `/checkout` ٤) — فتسعُ صفحاتٍ تستنفد الدقيقة،
 * والماسح يحمّل خمسًا وثلاثين في كلّ عرض.
 *
 * فكان يقع هذا بعينه: `POST /api/v1/cart/items` يُردّ ٤٢٩،
 * و`waitForResponse` لا ينظر في الحالة — فيُحسب الردّ «نجاحًا»؛ ثمّ صفحة
 * الدفع (مكوّن عميل: `apps/storefront/components/checkout/CheckoutFlow.tsx:1`)
 * تقرأ السلّة من المتصفّح فتُردّ ٤٢٩ بدورها، فتُصيّر بطاقة التعذّر بلا
 * `#field-name`. عطلٌ واحد بقناعين، وتشخيصٌ يقول «الطلب نجح» وهو لم ينجح.
 *
 * والعلاج ليس مهلةً أوسع — المهلة لا تصنع رصيدًا. بل يُسأل الخادم عن رصيده
 * ويُنتظر تصفيره بالمدّة التي يعلنها هو في `Retry-After`.
 */
const API = readArg('api', 'http://127.0.0.1:58080');
/** كلفة تحميلةٍ واحدة من الدلو — المقيس ٦ إلى ٧، والفرق هامش. */
const PAGE_COST = 12;
/** السطحان اللذان لا معنى لقياسهما بسلّةٍ فارغة. */
const CART_ROUTES = new Set(['/cart', '/checkout']);

/**
 * انتظارُ رصيدٍ يكفي تحميلةً — قبل كلّ ملاحةٍ تعتمد على السلّة، لا قبل غيرها.
 *
 * الجسّ برأسٍ فقط على أصل الواجهة الخلفيّة لا على أصل المتجر: الدلو يُحسب
 * هناك. وردُّ ٤٢٩ **لا** يستهلك منه — وسيط الخنق يرفض قبل `hit` — فحين يشحّ
 * الرصيد تُستنفد بقيّته عمدًا حتّى ينطق الخادم بـ`Retry-After` الدقيق. وهو
 * أرخص من انتظارٍ أعمى بطول النافذة كلّها، وأصدق من تخمينها.
 *
 * والمضيّ عند تعذّر الجسّ مقصود: غيابُ الخادم عطلٌ آخر يلتقطه الملاح بنفسه
 * ويُسمّيه، فلا يُخفى تحت «انتظار رصيد».
 *
 * والبوّابة لا تُوضع على كلّ مسار: ٤٢٩ على `/api/v1/cart` لا يمنع صفحةً
 * عاديّة من التصيير، فتعميمُها يضاعف زمن المسح بلا قياسٍ إضافيّ.
 */
async function awaitCartBudget(ctx) {
  for (let probe = 0; probe < 40; probe += 1) {
    const res = await ctx.request
      .head(`${API}/api/v1/cart`, { failOnStatusCode: false, timeout: 15_000 })
      .catch(() => null);
    if (res === null) return;
    if (res.status() !== 429) {
      if (Number(res.headers()['x-ratelimit-remaining'] ?? PAGE_COST) >= PAGE_COST) return;
      continue;
    }
    const after = Number(res.headers()['retry-after'] ?? 5);
    console.log(`  ⏲ رصيد الخادم نفد — انتظار ${after + 1}s`);
    await new Promise((resolve) => setTimeout(resolve, (after + 1) * 1000));
  }
}

/** الكاشفات الآمنة: زرٌّ يعلن التوسّع، أو ملخّص `<details>`. */
const collect = () =>
  [...document.querySelectorAll('button[aria-expanded], summary')].filter((el) => {
    if (el.tagName === 'BUTTON' && el.getAttribute('type') === 'submit') return false;
    return el.checkVisibility();
  }).length;

const openAt = (index) => {
  const list = [...document.querySelectorAll('button[aria-expanded], summary')].filter((el) => {
    if (el.tagName === 'BUTTON' && el.getAttribute('type') === 'submit') return false;
    return el.checkVisibility();
  });
  const el = list[index];
  if (!el) return null;
  const name = `${el.tagName.toLowerCase()}.${(el.className || '').toString().replace(/[a-z-]+-module__\w+__/g, '').split(' ')[0]}`;
  const before =
    el.tagName === 'SUMMARY'
      ? el.parentElement?.hasAttribute('open') === true
      : el.getAttribute('aria-expanded') === 'true';
  el.click();
  return { name, before };
};

const stateAt = (index) => {
  const list = [...document.querySelectorAll('button[aria-expanded], summary')].filter((el) => {
    if (el.tagName === 'BUTTON' && el.getAttribute('type') === 'submit') return false;
    return el.checkVisibility();
  });
  const el = list[index];
  if (!el) return null;
  if (el.tagName === 'SUMMARY') {
    const host = el.parentElement;
    const open = host?.hasAttribute('open') === true;
    /* محتوى `<details>` هو كلّ أبنائه عدا الملخّص. */
    const body = [...(host?.children ?? [])].filter((c) => c.tagName !== 'SUMMARY');
    return { open, targetVisible: body.some((c) => c.checkVisibility()), hasTarget: body.length > 0 };
  }
  const open = el.getAttribute('aria-expanded') === 'true';
  const id = el.getAttribute('aria-controls');
  const target = id ? document.getElementById(id) : null;
  return { open, targetVisible: target ? target.checkVisibility() : null, hasTarget: target !== null };
};

const geometry = () => {
  const vw = document.documentElement.clientWidth;
  const overflow = document.documentElement.scrollWidth - vw;
  const clipped = [];
  /* الحركة المصمَّمة (دوّار/شريط مرور مع زرّ إيقاف بالغ) تقصّ بالتصميم —
     الشرط نفسه المشروح في رأس sweep-viewports.mjs. */
  const designedMotion = (el) => {
    const host = el.closest('[role="region"][aria-roledescription="carousel"],[aria-roledescription="marquee"]');
    if (!host) return false;
    return [...document.querySelectorAll('button,[role="button"]')].some((b) =>
      (host.contains(b) || (host.id && b.getAttribute('aria-controls') === host.id))
      && !b.closest('[inert]') && !b.disabled && b.getAttribute('aria-disabled') !== 'true' && b.checkVisibility()
      && (b.hasAttribute('aria-pressed') || /pause|play|إيقاف|تشغيل|توق/i.test(`${b.getAttribute('aria-label') ?? ''} ${b.textContent ?? ''}`)));
  };
  /*
   * قفل التمرير تحت نافذةٍ مشروطة (درج القائمة، درج الفلاتر، حوار) يخفي فيض
   * الجذر **بالتصميم**: الصفحة خلف النافذة لا تُمرَّر. يُستثنى `html`/`body`
   * وحدهما، وفقط حين نافذةٌ مشروطة حقيقيّة مفتوحة ومرئيّة — `dialog:modal` أو
   * `[aria-modal="true"]` — فقصٌّ حقيقيّ في أيّ عنصرٍ آخر يبقى خرقًا.
   */
  const modalOpen = [...document.querySelectorAll('dialog, [aria-modal="true"]')].some(
    (m) => (m.matches('dialog') ? m.matches(':modal') || (m.open && m.getAttribute('aria-modal') === 'true') : true) && m.checkVisibility(),
  );
  for (const el of document.querySelectorAll('*')) {
    if (modalOpen && (el === document.documentElement || el === document.body)) continue;
    const cs = getComputedStyle(el);
    const hiddenY = cs.overflowY === 'hidden' || cs.overflowY === 'clip';
    const hiddenX = cs.overflowX === 'hidden' || cs.overflowX === 'clip';
    if (!hiddenY && !hiddenX) continue;
    if (el.clientHeight <= 4 || el.clientWidth <= 4) continue;
    if (designedMotion(el)) continue;
    if (cs.webkitLineClamp !== 'none' && cs.webkitLineClamp !== '') continue;
    if (cs.whiteSpace === 'nowrap' && cs.textOverflow === 'ellipsis') continue;
    const cutY = hiddenY ? el.scrollHeight - el.clientHeight : 0;
    const cutX = hiddenX ? el.scrollWidth - el.clientWidth : 0;
    if (cutY <= 2 && cutX <= 2) continue;
    const box = el.getBoundingClientRect();
    for (const c of el.querySelectorAll('*')) {
      const b = c.getBoundingClientRect();
      if (b.width < 1 || b.height < 1) continue;
      const outX = Math.round(Math.max(box.left - b.left, b.right - box.right));
      const outY = Math.round(b.bottom - box.bottom);
      if (outX <= 2 && outY <= 2) continue;
      const carries =
        (c.textContent ?? '').trim().length > 0 ||
        c.matches('a,button,input,select,textarea,summary,[tabindex]:not([tabindex="-1"])');
      if (carries) {
        clipped.push(
          `${el.tagName.toLowerCase()}.${(el.className || '').toString().replace(/[a-z-]+-module__\w+__/g, '').split(' ')[0]} يقصّ محتواه`,
        );
        break;
      }
    }
    if (clipped.length > 3) break;
  }
  return { overflow, clipped: [...new Set(clipped)] };
};

const routes = await resolveRoutes();
/*
 * تعذّر التحميل عطلُ قياسٍ لا نتيجة.
 *
 * كان الاستثناء يُبتلع و`continue` يمضي، فلو سقط الخادم لأبلغ الماسح
 * «‎36‎ مسارًا · بلا خرق» وهو لم يقس واحدًا. والخضرةُ على لا شيء أخطر من
 * الحمرة على شيء — فتُعدّ الإخفاقات وتُعلن، ويسقط التشغيل بها.
 */
const browser = await chromium.launch();
let breaches = 0;
let instrumentErrors = 0;
let opened = 0;
/** أسماء الأسطح التي لم تُقس — تُعلَن في الخلاصة بأسمائها لا بعددها فقط. */
const unmeasured = [];

for (const width of WIDTHS) {
  console.log(`\n  ── ${width}px`);
  const ctx = await browser.newContext({ viewport: { width, height: 900 } });
  const page = await ctx.newPage();
  /* عدّاد ٤٢٩ لهذه الصفحة: يفرّق «الخادم خنقنا» عن «الصفحة بلا حقل». */
  let throttled = 0;
  page.on('response', (res) => {
    if (res.status() === 429) throttled += 1;
  });
  let seeded = false;

  if (SEED_CART) {
    /*
     * البذر ينجح أو يُعلَن فاشلًا — لا صمت بينهما.
     *
     * أوّل صياغةٍ التقطت أخطاء الانتقال وحدها، فمسارُ منتجٍ لا وجود له كان
     * يمرّ بلا استثناء: الزرّ غائب، والسلّة فارغة، وصفحةُ الدفع تُصيّر حالتها
     * الفارغة — فيمرّ الفحص أخضر على صفحةٍ لم يُقس فيها شيء. والخضرةُ على
     * لا شيء أسوأ من الحمرة على شيء.
     */
    const product = SEED_PRODUCT !== '' ? SEED_PRODUCT : routes.find((r) => r.startsWith('/products/'));
    if (product === undefined) {
      instrumentErrors += 1;
      console.log('  ⚠ عطلُ قياس · لا مسار منتجٍ للبذر — لوحات السلّة والدفع لا تُقاس');
    } else {
      /*
       * سبب السقوط يُسمّى.
       *
       * «لم تُبذر السلّة» وحدها تخفي ثلاثة أعطال مختلفة تمامًا: زرٌّ لم
       * يُوجد، أو نقرةٌ لم تُصدر طلبًا (تركيبٌ متأخّر)، أو طلبٌ نجح وصفحة
       * الدفع لم تُصيّر حقلها. من يقرأ السطر يحتاج أن يعرف أيّها ليصلحه.
       */
      let stage = 'الصفحة لم تُفتح';
      try {
        /*
         * `domcontentloaded` لا `networkidle`.
         *
         * خادم التطوير يُبقي وصلة HMR مفتوحة، فـ«سكون الشبكة» قد لا يتحقّق
         * أبدًا تحت الحمل ويرمي `goto` مهلةً — فيُبلَّغ عن عطل بذرٍ والمتجر
         * سليم. المقيس: نفس الشيفرة تسقط مرّةً عند ‎390‎ ومرّةً عند ‎1440‎
         * وتنجح منفردةً — تذبذبٌ خالص.
         *
         * والشرط الحقيقيّ هو ظهور الزرّ وصلاحيته للنقر، وهو ما يُنتظَر صراحةً.
         */
        await awaitCartBudget(ctx);
        await gotoChecked(page, BASE, product, { waitUntil: 'domcontentloaded', timeout: 45_000 });
        /*
         * الزرّ يُطابَق بنمطٍ لا بنصٍّ حرفيّ.
         *
         * منتجٌ بأكثر من صيغة يحمل زرًّا يسمّي صيغته («أضف نسخة Word إلى
         * السلة») بدل «أضف للسلة»، فكان المطابِق الحرفيّ يعمى عنه ويُعلن
         * عطلَ قياسٍ على متجرٍ سليم — أداةٌ تنكسر مع أوّل منتجٍ حقيقيّ
         * متعدّد الصيغ.
         */
        const add = page.locator('button:visible', { hasText: /^أضف .+سلة$/ }).first();
        stage = 'زرّ الإضافة لم يظهر';
        await add.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => {});
        if ((await add.count()) > 0) {
          stage = 'النقر لم يُصدر طلب إضافة';
          /*
           * النقرة تُنتظَر باستجابة الخادم لا بمهلةٍ ثابتة.
           *
           * زرّ الإضافة مكوّن عميل؛ نقرةٌ قبل التركيب لا تُصدر طلبًا ولا
           * تُخطئ — فكانت المهلة الثابتة (‎2000ms‎) تنجح حين تكون الآلة
           * فارغة وتسقط حين تزدحم، فيتذبذب الماسح بين «بلا عطل» و«عطلان»
           * على الشيفرة نفسها. والتذبذب في أداة قياسٍ أسوأ من عطلٍ ثابت:
           * لا يُصدَّق أخضرها ولا أحمرها.
           *
           * وخمس محاولات لأنّ التركيب قد يتأخّر، ولأنّ ٤٢٩ انتظارٌ لا فشل.
           */
          for (let attempt = 0; attempt < 5 && !seeded; attempt += 1) {
            try {
              const [res] = await Promise.all([
                page.waitForResponse(
                  (r) => r.url().includes('/api/v1/cart/items') && r.request().method() === 'POST',
                  { timeout: 10_000 },
                ),
                add.click({ timeout: 10_000 }),
              ]);
              /*
               * الحالة تُقرأ لا وصولُ الردّ وحده.
               *
               * `waitForResponse` يُرضيه أيّ ردّ، فكان ٤٢٩ يُحسب بذرًا ناجحًا
               * ويُبنى عليه تشخيصٌ يقول «الطلب نجح وصفحة الدفع بلا حقل» —
               * وهو يسمّي عطلًا غير الواقع، فيرسل من يقرأه إلى التصيير
               * والتركيب بينما السبب حدُّ معدّلٍ في الخلفيّة. تشخيصٌ خاطئ
               * أغلى من لا تشخيص: يُنفق وقتًا في المكان الخطأ.
               */
              if (res.ok()) {
                seeded = true;
                break;
              }
              stage = `طلب الإضافة رُدّ ${res.status()}`;
              /* ما سوى ٤٢٩ عطلٌ لا تصلحه إعادة — يُعلَن بحالته ويُتوقّف. */
              if (res.status() !== 429) break;
              await awaitCartBudget(ctx);
            } catch {
              /* الزرّ مكوّن عميل: نقرةٌ قبل التركيب لا تُصدر طلبًا ولا تُخطئ. */
              await page.waitForTimeout(1200);
            }
          }

          if (seeded) {
            /*
             * تُنتظر الكعكة لا الزمن.
             *
             * `waitForResponse` يعود عند وصول الترويسات، وقد يسبق ذلك
             * تثبيتَ `Set-Cookie` في جرّة السياق. وصفحة الدفع تقرأ السلّة
             * **من المتصفّح** بـ`credentials:'include'`
             * (`apps/storefront/lib/commerce-api.ts:221`) — فملاحةٌ أسبق من
             * الكعكة تُرسل طلبًا بلا هويّة سلّة، فتُصيَّر الحالة الفارغة
             * الصادقة ويُقرأ ذلك «لم تُبذر السلّة».
             */
            for (let wait = 0; wait < 25; wait += 1) {
              const jar = await page.context().cookies();
              if (jar.some((c) => c.name === 'tahdheer_cart')) break;
              await page.waitForTimeout(200);
            }

            seeded = false;
            stage = 'الطلب نجح وصفحة الدفع بلا حقل';
            /*
             * الشاهد هو حقل الدفع نفسه — وهو ما يقيسه الماسح بعد قليل.
             *
             * ولا يُقرأ `/api/v1/cart` من أصل المتجر: ذلك المسار يخدمه
             * Laravel على أصلٍ آخر، فطلبه هنا يعود بصفحة HTML ويُفسَّر
             * «سلّة فارغة» دائمًا — إعلانُ عطلٍ ثابتٍ على متجرٍ سليم.
             *
             * وصفحة الدفع مكوّن عميل
             * (`apps/storefront/components/checkout/CheckoutFlow.tsx:1`):
             * تجلب السلّة بعد التركيب بميزانيّة ‎2.5s‎
             * (`apps/storefront/lib/fetch-budget.ts:40`)، وأيّ فشلٍ في ذلك
             * الجلب — انقطاعُ ميزانيّة أو ٤٢٩ — يُصيّر بطاقة التعذّر بلا
             * `#field-name`. فالتكرار هنا انتظارٌ لخادمٍ مشغول، وبوّابةُ
             * الرصيد قبله تمنع السبب الأغلب من الوقوع أصلًا.
             *
             * والسبب يُفرَّق: ٤٢٩ خلال الملاحة يُسمّى حدَّ معدّل، وغيابُه
             * يُبقي التشخيص على التصيير. اسمان لعطلين، لا اسمٌ لاثنين.
             */
            for (let look = 0; look < 3 && !seeded; look += 1) {
              await awaitCartBudget(ctx);
              const before = throttled;
              await gotoChecked(page, BASE, '/checkout', {
                waitUntil: 'domcontentloaded',
                timeout: 45_000,
              });
              await page
                .locator('#field-name')
                .waitFor({ state: 'attached', timeout: 20_000 })
                .catch(() => {});
              seeded = (await page.locator('#field-name').count()) > 0;
              if (!seeded && throttled > before) {
                stage = 'صفحة الدفع رُدّت ٤٢٩ فصيّرت بطاقة التعذّر';
              }
            }
          }
        }
      } catch {
        seeded = false;
      }
      if (!seeded) {
        instrumentErrors += 1;
        console.log(
          `  ⚠ عطلُ قياس · لم تُبذر السلّة من «${product}» (${stage}) — لوحات السلّة والدفع لا تُقاس`,
        );
      }
    }
  }

  for (const route of routes) {
    /*
     * سطحٌ تجاريّ بسلّةٍ لم تُبذر لا يُقاس — ولا يُكتب عنه سطرٌ يوهم بالعكس.
     *
     * كان يمضي فيُطبع «· لا كاشف»، وهي جملةٌ عن الصفحة بينما الحقيقة عن
     * الأداة: `/cart` و`/checkout` تُصيّران حالتهما الفارغة الصادقة بلا
     * سلّة، فلا لوحةَ قسيمةٍ ولا حقلَ ضريبة. فيقرأ القارئ «لا كاشف هناك»
     * وصوابُه «لم يُقس هنا شيء» — وهذان لا يستويان في تقرير تغطية.
     */
    if (SEED_CART && CART_ROUTES.has(route) && !seeded) {
      instrumentErrors += 1;
      unmeasured.push(`${width}px ${route}`);
      console.log(`  ⚠ عطلُ قياس · ${route.padEnd(34)} لم يُقَس — السلّة لم تُبذر`);
      continue;
    }
    /* الرصيد يُنتظر للسطحين وحدهما: تعميمه يضاعف الزمن بلا قياسٍ إضافيّ. */
    if (CART_ROUTES.has(route)) await awaitCartBudget(ctx);
    try {
      await gotoChecked(page, BASE, route, { waitUntil: 'networkidle', timeout: 45_000 });
    } catch {
      instrumentErrors += 1;
      console.log(`  ⚠ عطلُ قياس · تعذّر تحميل ${route}`);
      continue;
    }
    await page.waitForTimeout(250);

    const count = await page.evaluate(collect);
    /*
     * صفرُ كاشفٍ على سلّةٍ مبذورة ليس «لا كاشف» — بل قياسٌ لم يقع.
     *
     * المقيس عند ‎1440px‎ بسلّةٍ مبذورة وخادمٍ غير مخنوق: `/cart` كاشفٌ
     * واحد («هل لديك رمز خصم؟»)، و`/checkout` كاشفان («أحتاج فاتورة
     * ضريبية باسم منشأة» و«لديك رمز خصم؟»). فصفرٌ هنا يعني أنّ الصفحة
     * صيّرت بطاقة التعذّر أو الحالة الفارغة رغم البذر.
     *
     * وهذا الحارس لا يبتلع عيبًا حقيقيًّا: لو حُذفت لوحةُ القسيمة من
     * المتجر فعلًا لصرخ هو أيضًا. أن يصرخ على عيب واجهةٍ حقيقيّ مع عطل
     * القياس أهونُ من أن يسكت عن كليهما بسطرٍ يقول «لا كاشف».
     */
    if (SEED_CART && CART_ROUTES.has(route) && count === 0) {
      instrumentErrors += 1;
      unmeasured.push(`${width}px ${route}`);
      console.log(`  ⚠ عطلُ قياس · ${route.padEnd(34)} صفر كاشف والسلّة مبذورة — لم يُقَس`);
      continue;
    }
    if (count === 0) {
      console.log(`  · ${route.padEnd(34)} لا كاشف`);
      continue;
    }

    const bad = [];
    for (let i = 0; i < count; i += 1) {
      const hit = await page.evaluate(openAt, i);
      if (hit === null) continue;
      await page.waitForTimeout(120);
      const after = await page.evaluate(stateAt, i);
      if (after === null) continue;
      opened += 1;

      if (after.open === hit.before) {
        bad.push(`${hit.name} لم تتغيّر حاله بالنقر`);
        continue;
      }
      if (after.open && after.hasTarget && after.targetVisible === false) {
        bad.push(`${hit.name} معلَنٌ مفتوحًا ومحتواه غير ظاهر`);
      }
    }

    /* كلّها مفتوحةٌ الآن: أقصى محتوًى تحمله الصفحة. */
    const geo = await page.evaluate(geometry);
    if (geo.overflow > 1) bad.push(`فيض أفقيّ ${geo.overflow}px والكاشفات مفتوحة`);
    for (const c of geo.clipped) bad.push(`${c} والكاشفات مفتوحة`);

    if (bad.length > 0) {
      breaches += bad.length;
      console.log(`  × ${route}`);
      for (const b of [...new Set(bad)]) console.log(`      ${b}`);
    } else {
      console.log(`  ✓ ${route.padEnd(34)} ${count} كاشفًا · فُتحت وقيست`);
    }
  }
  await ctx.close();
}

await browser.close();
console.log(
  `\n  ${routes.length} مسارًا · ${opened} كاشفًا شُغِّل · ${breaches ? `${breaches} خرقًا` : 'بلا خرق'}` +
    `${instrumentErrors > 0 ? ` · ${instrumentErrors} عطلَ قياس` : ''}`,
);
/*
 * التغطية الناقصة تُسمّى بأسمائها — على سابقة
 * قاعدةُ «تُخُطِّي ولم يُحسب نجاحًا».
 * «بلا خرق» وحدها تُقرأ تزكيةً لأسطحٍ لم تُفتح فيها لوحةٌ واحدة.
 */
if (unmeasured.length > 0) {
  console.log(`  لم تُقس ولم تُحسب نجاحًا: ${unmeasured.join(' · ')}`);
}
console.log('');
/* صفرُ قياسٍ ليس نجاحًا (lib/sweep-verdict) — كان يخرج بـ0 حين لا يُقاس شيء. */
const verdict = sweepVerdict({ measured: opened, breaches, instrumentErrors });
if (verdict.code !== 0) console.log(`  × ${verdict.reason}\n`);
process.exit(verdict.code);
