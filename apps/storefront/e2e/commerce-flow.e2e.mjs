#!/usr/bin/env node
/**
 * E2E — رحلة الشراء كاملةً ضدّ متجرٍ وواجهةٍ برمجيّة يعملان فعلًا.
 *
 * منتج → صيغة → إضافة للسلّة → السلّة (رمز خصم) → الدفع → اختيار الوسيلة →
 * بدء الدفع (المزوّد `fake`) → صفحة الطلب → حسابي/طلباتي → التنزيلات.
 *
 * كلّ طلبٍ إلى الواجهة البرمجيّة يُلتقط (page.on('response')) ويُقارَن:
 *  · سطر السلّة يحمل productId/variantId الصيغةَ المختارة وسعرَها وعملتَها وكمّيّتَها؛
 *  · الإجماليّات تساوي إجماليّات الخادم إلى الهللة؛
 *  · ما تعرضه الواجهة من أسعار = formatMoney(قيمة الخادم)؛
 *  · حمولة POST /checkout/orders تطابق عقد الخادم
 *    (backend/laravel/app/Http/Controllers/Api/V1/CheckoutController.php)؛
 *  · حالة الطلب تنتقل AWAITING_PAYMENT → COMPLETED، وتُفتح الاستحقاقات بعد الدفع.
 *
 * رمز الخروج = عدد التأكيدات الفاشلة (0 = نجاح).
 *
 *   node apps/storefront/e2e/commerce-flow.e2e.mjs \
 *     --base=http://127.0.0.1:3000 --api=http://127.0.0.1:58080 --slug=dev-commerce-002 --coupon=DEV15
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { chromium } from 'playwright';

const arg = (name, fallback) =>
  process.argv.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
const BASE = arg('base', 'http://127.0.0.1:3000');
const API = arg('api', 'http://127.0.0.1:58080');
const SLUG = arg('slug', 'dev-commerce-002');
const COUPON = arg('coupon', 'DEV15');

const here = dirname(fileURLToPath(import.meta.url));
const CONTROLLER = resolve(here, '../../../backend/laravel/app/Http/Controllers/Api/V1/CheckoutController.php');

/* نفس ‎formatMoney‎ في packages/design-system/src/format.ts — يُعاد بناؤه هنا كي لا يُستورد TS. */
const money = (minor) =>
  new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(minor / 100);
const norm = (s) => (s ?? '').replace(/\s+/g, ' ').trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/*
 * ٤٢٩ من الواجهة البرمجيّة = جلسةٌ مجاورة على ميزانيّة `throttle:write` نفسها،
 * لا عيبٌ في المتجر. طلبات المتصفّح تُعاد شفّافًا بعد `Retry-After` (حتّى ٣
 * مرّات) فيرى المتجر الردّ الناجح كما يراه زائرٌ وحده على الخادم.
 */
/*
 * وإغلاقُ الصفحة أثناء إعادة طلبٍ (نداء `/events` بعد تنقّلٍ مثلًا) يُبطل سياق
 * الطلب فيرمي `route.fetch` «Request context disposed» — رفضٌ داخل نداء المسار
 * لا يلتقطه أحد فيُسقط التشغيل كلّه قبل طباعة نتيجةٍ واحدة. طلبٌ لصفحةٍ أُغلقت
 * لا معنى لإكماله: يُترك بصمت. وأيّ خطأٍ آخر يسلّم الطلبَ للشبكة كما هو.
 */
const DISPOSED = /context disposed|Target (page, context or browser )?(has been )?closed|Browser has been closed/i;

let throttleRetries = 0;
async function shieldThrottle(context) {
  await context.route(`${API}/**`, async (route) => {
    try {
      let res = await route.fetch();
      for (let i = 0; i < 3 && res.status() === 429; i += 1) {
        throttleRetries += 1;
        await sleep((Number(res.headers()['retry-after'] ?? 20) + 1) * 1000);
        res = await route.fetch();
      }
      await route.fulfill({ response: res });
    } catch (error) {
      if (DISPOSED.test(String(error?.message))) return;
      await route.continue().catch(() => {});
    }
  });
}

/*
 * خادم التطوير قد يقطع تنقّلًا (ERR_ABORTED) أثناء إعادة تجميع — محاولةٌ ثانية.
 * وصفحةٌ مُصيَّرة على الخادم تحت ٤٢٩ تعرض بطاقة «تعذّر إكمال الطلب» — تُعاد
 * بعد النافذة (حتّى ٣ مرّات) للسبب نفسه أعلاه.
 */
/* أثرُ الفشل: آخر صفحةٍ وآخر تنقّل — يُفرَّغان عند رميٍ فيُشخَّص التعليق من دليل لا من ظنّ. */
let lastPage = null;
let lastNav = null;
async function go(page, url, opts = { waitUntil: 'load' }) {
  lastPage = page;
  let res = null;
  for (let attempt = 0; attempt < 4; attempt += 1) {
    try {
      const t0 = Date.now();
      res = await page.goto(url, opts);
      lastNav = { url, status: res?.status() ?? null, ms: Date.now() - t0 };
    } catch (error) {
      if (!/ERR_ABORTED|Timeout/.test(error.message)) throw error;
      await sleep(3000);
      continue;
    }
    const throttled = await page.locator('main').innerText().then((t) => t.includes('تعذّر إكمال الطلب')).catch(() => false);
    if (!throttled || attempt === 3) return res;
    await sleep(25000);
  }
  return res;
}

let passed = 0;
const failures = [];
const check = (label, ok, detail = '') => {
  if (ok) passed += 1;
  else failures.push(`${label}${detail ? ` — ${detail}` : ''}`);
};

// ── العقد من الخادم: مفاتيح المستوى الأعلى لقواعد التحقّق في CheckoutController::store
const controllerSrc = readFileSync(CONTROLLER, 'utf8');
/* مصفوفة القواعد وحدها — تنتهي حيث تبدأ مصفوفة الرسائل ('name.required'). */
const fromValidate = controllerSrc.slice(controllerSrc.indexOf('$request->validate('));
const validateBlock = fromValidate.slice(0, fromValidate.indexOf("'name.required'"));
const CONTRACT_KEYS = new Set(
  [...validateBlock.matchAll(/^\s*'([A-Za-z]+)'\s*=>/gm)].map((m) => m[1]),
);
const CONTRACT_REQUIRED = new Set(
  [...validateBlock.matchAll(/^\s*'([A-Za-z]+)'\s*=>\s*(?:\[)?'(?:required|accepted)/gm)].map((m) => m[1]),
);

/*
 * `throttle:write` (60/دقيقة لكلّ IP) يغطّي GET /cart أيضًا، والترويسة تستدعيه في كلّ
 * صفحة — فجلسةٌ أخرى على الجهاز نفسه تُفرغ الميزانيّة. يُنتظر حتّى تتّسع قبل البدء
 * بدل أن تُقرأ 429 عطلًا في الواجهة.
 */
async function waitForBudget(min = 40, maxMs = 180000) {
  const started = Date.now();
  for (;;) {
    const res = await fetch(`${API}/api/v1/cart`, { headers: { Accept: 'application/json' } });
    const remaining = Number(res.headers.get('x-ratelimit-remaining') ?? min);
    if (res.status !== 429 && remaining >= min) return remaining;
    if (Date.now() - started > maxMs) {
      console.error(`✗ write budget never recovered (remaining=${remaining})`);
      process.exit(1);
    }
    await new Promise((r) => setTimeout(r, 5000));
  }
}
console.log(`  write budget: ${await waitForBudget()} remaining`);

// ── بيانات المرجع من الواجهة البرمجيّة
const product = (await (await fetch(`${API}/api/v1/products/${encodeURIComponent(SLUG)}`)).json()).data;
const variants = (product.variants ?? []).filter((v) => v.purchasable !== false && typeof v.priceMinor === 'number');
if (variants.length < 2) {
  console.error(`✗ ${SLUG} needs ≥2 purchasable formats (has ${variants.length})`);
  process.exit(1);
}
/* الصيغة الثانية عمدًا: لا تُختار افتراضيًّا، فالمطابقة تُثبت أنّ الاختيار نُقل لا الافتراض. */
const chosen = variants[1];
const methods = await (await fetch(`${API}/api/v1/checkout/methods`)).json();

/** سجلّ الطلبات إلى الواجهة البرمجيّة: {method,path,status,reqBody,resBody}. */
const log = [];
const last = (method, re) => [...log].reverse().find((e) => e.method === method && re.test(e.path));
let pendingOrder = null;
let radioState = null;
let t = {};
let confirm = null;
const browser = await chromium.launch();
let thrown = null;
try {
const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'ar-SA' });
await shieldThrottle(context);
/* خادم التطوير يُجمِّع عند الطلب وقد يكون تحت ضغط — المهل سخيّة عمدًا. */
context.setDefaultNavigationTimeout(120000);
context.setDefaultTimeout(60000);
await context.addCookies([{ name: 'tahdheer_consent', value: 'rejected', url: BASE }]);

function record(page) {
  page.on('response', async (res) => {
    const req = res.request();
    const url = req.url();
    if (!url.startsWith(API)) return;
    let reqBody = null;
    try {
      reqBody = req.postData() ? JSON.parse(req.postData()) : null;
    } catch {
      reqBody = req.postData();
    }
    let resBody = null;
    try {
      resBody = await res.json();
    } catch {
      resBody = null;
    }
    log.push({ method: req.method(), path: url.slice(API.length), status: res.status(), reqBody, resBody });
  });
}

// ── ١ · صفحة المنتج → الصيغة → إضافة إلى السلّة
const page = await context.newPage();
record(page);
await go(page, `${BASE}/products/${SLUG}`, { waitUntil: 'load' });
await page.waitForSelector('[role="radio"]');
await page.locator('[role="radio"]', { hasText: chosen.title ?? chosen.format }).first().click();
check('pdp: chosen format is checked', await page.locator('[role="radio"][aria-checked="true"]').first().innerText().then((t) => t.includes(chosen.title)));
/* ٤٢٩ من جلسةٍ مجاورة على الميزانيّة نفسها: تُنتظر النافذة ويُعاد الضغط، لا يُعدّ عيبًا. */
let add = null;
for (let attempt = 0; attempt < 4; attempt += 1) {
  const addWait = page.waitForResponse((r) => r.url().includes('/api/v1/cart/items') && r.request().method() === 'POST');
  await page.getByRole('button', { name: /أضف إلى السلة|في السلة/ }).first().click();
  await addWait;
  await page.waitForTimeout(200);
  add = last('POST', /^\/api\/v1\/cart\/items$/);
  if (add?.status !== 429) break;
  await sleep(35000);
}
check('add-to-cart: request productId', add?.reqBody?.productId === product.id, JSON.stringify(add?.reqBody));
check('add-to-cart: request variantId', add?.reqBody?.variantId === chosen.id, JSON.stringify(add?.reqBody));
check('add-to-cart: 200', add?.status === 200, String(add?.status));
const line = add?.resBody?.items?.find((l) => l.variantId === chosen.id);
check('cart line: productId', line?.productId === product.id, JSON.stringify(line));
check('cart line: variantId', line?.variantId === chosen.id, JSON.stringify(line));
check('cart line: unit price = variant price', line?.priceMinor === chosen.priceMinor, `${line?.priceMinor} ≠ ${chosen.priceMinor}`);
check('cart line: regular price = variant regular', (line?.regularMinor ?? null) === (chosen.regularMinor ?? null));
check('cart line: qty 1 (digital lock)', line?.qty === 1, String(line?.qty));
check('cart line: currency', chosen.currency === 'SAR' && product.currency === 'SAR', `${chosen.currency}`);
check('cart: single line', add?.resBody?.items?.length === 1 && add?.resBody?.count === 1);

// ── ٢ · السلّة: السعر المعروض، الرمز، الإجماليّات
await go(page, `${BASE}/cart`, { waitUntil: 'load' });
/* بطاقة ٤٢٩ بدل السلّة ⇒ انتظار النافذة ثمّ «إعادة المحاولة» — حتّى ٤ مرّات. */
for (let attempt = 0; attempt < 4; attempt += 1) {
  const which = await Promise.race([
    page.waitForSelector('section[aria-label="محتويات السلة"]').then(() => 'cart'),
    page.getByRole('button', { name: 'إعادة المحاولة' }).waitFor().then(() => 'error'),
  ]);
  if (which === 'cart') break;
  await sleep(35000);
  await page.getByRole('button', { name: 'إعادة المحاولة' }).click();
}
/* قائمة السطور وحدها — لا قوائم الرقاقات داخل كلّ سطر. */
const cartLines = page.locator('section[aria-label="محتويات السلة"] > ul[role="list"] > li');
const cartGet = last('GET', /^\/api\/v1\/cart$/);
check('cart page: one line rendered', (await cartLines.count()) === 1, `${await cartLines.count()} rendered · GET /cart items=${JSON.stringify(cartGet?.resBody?.items?.map((l) => [l.id, l.productId, l.variantId]))} · POST /cart/items ×${log.filter((e) => e.method === 'POST' && e.path === '/api/v1/cart/items').length}`);
const lineText = norm(await cartLines.first().innerText());
check('cart page: line title', lineText.includes(product.title), lineText.slice(0, 80));
check('cart page: variant title in line', lineText.includes(chosen.title), lineText.slice(0, 120));
check('cart page: displayed price = formatMoney(variant)', lineText.includes(money(chosen.priceMinor)), lineText);
if (chosen.regularMinor > chosen.priceMinor) {
  check('cart page: struck regular price', lineText.includes(money(chosen.regularMinor)), lineText);
}

await page.getByRole('button', { name: /هل لديك رمز خصم/ }).click();
const couponForm = page.locator('form', { has: page.getByRole('button', { name: 'تطبيق' }) });
await couponForm.locator('input').fill('NOPE-INVALID');
await couponForm.getByRole('button', { name: 'تطبيق' }).click();
await page.waitForResponse((r) => r.url().includes('/api/v1/cart/coupon'));
await page.waitForTimeout(150);
const bad = last('POST', /^\/api\/v1\/cart\/coupon$/);
check('coupon invalid: 422', bad?.status === 422, String(bad?.status));
const alert = page.getByRole('alert');
check('coupon invalid: server message verbatim', norm(await alert.first().innerText()).includes(norm(bad?.resBody?.message)), await alert.first().innerText().catch(() => 'no alert'));

await couponForm.locator('input').fill(COUPON);
await couponForm.getByRole('button', { name: 'تطبيق' }).click();
await page.waitForResponse((r) => r.url().includes('/api/v1/cart/coupon') && r.status() === 200);
await page.waitForTimeout(200);
const ok = last('POST', /^\/api\/v1\/cart\/coupon$/);
const cart = ok?.resBody;
check('coupon valid: 200 + coupon echoed', ok?.status === 200 && cart?.coupon?.code === COUPON, JSON.stringify(cart?.coupon));
t = cart?.totals ?? {};
check('totals: chain to the minor unit', t.grossMinor - t.productDiscountMinor - t.couponDiscountMinor === t.totalMinor, JSON.stringify(t));
check('totals: saving = product + coupon discounts', t.savingMinor === t.productDiscountMinor + t.couponDiscountMinor, JSON.stringify(t));
check('totals: gross = Σ regular (or price)', t.grossMinor === cart.items.reduce((s, l) => s + (l.regularMinor ?? l.priceMinor) * l.qty, 0));
const summary = page.locator('aside[aria-label="ملخّص الطلب"]');
const grand = summary.locator('dt', { hasText: 'الإجمالي النهائي' }).locator('xpath=following-sibling::dd[1]');
check('cart page: grand total = formatMoney(totals.totalMinor)', norm(await grand.innerText()).startsWith(money(t.totalMinor)), await grand.innerText());
const couponRow = summary.locator('dt', { hasText: 'رمز الخصم' }).locator('xpath=following-sibling::dd[1]');
check('cart page: coupon row = formatMoney(couponDiscountMinor)', norm(await couponRow.innerText()).includes(money(t.couponDiscountMinor)), await couponRow.innerText());
const bar = page.locator('[data-bottom-chrome="fixed"]');
check('cart page: mobile bar total = server total', norm(await bar.innerText()).includes(money(t.totalMinor)), await bar.innerText());

// ── ٣ · الدفع: الحقول، الحمولة، اختيار الوسيلة، البدء
await page.getByRole('button', { name: 'إتمام الطلب' }).first().click();
await page.waitForURL('**/checkout', { waitUntil: 'load' });
await page.waitForSelector('#field-name');
const ckSummary = page.locator('aside[aria-labelledby="ck-summary-side-h"]');
const ckGrand = ckSummary.locator('dt', { hasText: 'الإجمالي النهائي' }).locator('xpath=following-sibling::dd[1]');
check('checkout: summary grand total = server total', norm(await ckGrand.innerText()).startsWith(money(t.totalMinor)), await ckGrand.innerText());
check('checkout: summary coupon applied', norm(await ckSummary.innerText()).includes(COUPON));
check('checkout: CTA carries the total', norm(await page.getByRole('button', { name: /ادفع/ }).first().innerText()).includes(money(t.totalMinor)));
check('checkout: CTA disabled while form incomplete', await page.getByRole('button', { name: /ادفع/ }).first().isDisabled());
check('checkout: methods not invented before the order exists', (await page.getByRole('radiogroup').count()) === 0);
// «mada» شعارٌ (صورة) لا نصّ — فالعلامة تُقرأ من ‎data-mark‎ الذي يحمله كلّ بند (PaymentMark).
const marksRow = await page.locator('main ul[aria-label="وسائل الدفع المقبولة"] li').evaluateAll((els) => els.map((el) => el.dataset.mark ?? ''));
check('checkout: marks row = GET /checkout/methods marks', JSON.stringify(marksRow.map(norm)) === JSON.stringify(methods.marks), `${marksRow} ≠ ${methods.marks}`);

await page.fill('#field-name', 'زائر الاختبار');
await page.fill('#field-phone', '512345678');
await page.fill('#field-email', 'e2e-commerce@example.com');
await page.locator('#field-policy-consent input[type="checkbox"]').check();
check('checkout: CTA enabled once valid', await page.getByRole('button', { name: /ادفع/ }).first().isEnabled());

/* بين إنشاء الطلب وتأكيد الدفع: تُلتقط حالة الطلب المعلّقة وحالة مجموعة الوسائل. */
pendingOrder = null;
radioState = null;
await page.route('**/api/v1/payments/fake/*/confirm', async (route) => {
  const token = route.request().url().split('/payments/fake/')[1].split('/')[0];
  pendingOrder = await (await context.request.get(`${API}/api/v1/orders/${token}`)).json();
  radioState = await page.evaluate(() => {
    const on = document.querySelector('[role="radiogroup"] [role="radio"][aria-checked="true"]');
    return { count: document.querySelectorAll('[role="radiogroup"] [role="radio"]').length, on: on?.textContent ?? null, tab: on?.getAttribute('tabindex') };
  });
  await route.continue();
});

await page.getByRole('button', { name: /ادفع/ }).first().click();
await page.waitForURL('**/orders/**', { timeout: 30000 });
await page.waitForSelector('h1');

const co = last('POST', /^\/api\/v1\/checkout\/orders$/);
check('checkout POST: 201', co?.status === 201, String(co?.status));
const payloadKeys = Object.keys(co?.reqBody ?? {});
check('checkout POST: payload keys ⊆ backend contract', payloadKeys.every((k) => CONTRACT_KEYS.has(k)), `${payloadKeys} vs ${[...CONTRACT_KEYS]}`);
check('checkout POST: every required key present', [...CONTRACT_REQUIRED].every((k) => k in (co?.reqBody ?? {})), `${[...CONTRACT_REQUIRED]}`);
check('checkout POST: shape', payloadKeys.join() === 'name,phone,email,taxProfile,policyConsent,marketingOptIn', payloadKeys.join());
check('checkout POST: values', co?.reqBody?.name === 'زائر الاختبار' && co?.reqBody?.phone === '512345678' && co?.reqBody?.policyConsent === true && co?.reqBody?.taxProfile === null && co?.reqBody?.marketingOptIn === false);
check('checkout POST: no shippingAddress for a digital cart', !('shippingAddress' in (co?.reqBody ?? {})));
const order = co?.resBody;
check('checkout response: provider offered by /checkout/methods', methods.providers.includes(order?.payment?.provider), `${order?.payment?.provider} ∉ ${methods.providers}`);
check('checkout response: requires_payment', order?.payment?.status === 'requires_payment');
check('payment selection: radiogroup lists exactly the offered provider', radioState?.count === 1 && radioState?.tab === '0', JSON.stringify(radioState));
check('payment selection: fake provider labelled honestly', norm(radioState?.on).includes('دفع تجريبي'), norm(radioState?.on));
check('order status: AWAITING_PAYMENT before confirm', pendingOrder?.status === 'AWAITING_PAYMENT', pendingOrder?.status);
check('order status: files locked before confirm', pendingOrder?.files?.every((f) => f.locked === true), JSON.stringify(pendingOrder?.files));
confirm = last('POST', /^\/api\/v1\/payments\/fake\/.+\/confirm$/);
check('fake confirm: 200 → COMPLETED', confirm?.status === 200 && confirm?.resBody?.status === 'COMPLETED', JSON.stringify(confirm?.resBody));

// ── ٤ · صفحة الطلب المدفوع
check('order page: URL carries the token', page.url().endsWith(`/orders/${order?.orderToken}`), page.url());
const orderView = await (await context.request.get(`${API}/api/v1/orders/${order.orderToken}`)).json();
check('order status: paid after confirm', ['PAID', 'PROCESSING', 'COMPLETED'].includes(orderView.status), orderView.status);
check('order totals = cart totals', JSON.stringify(orderView.totals) === JSON.stringify(t), JSON.stringify(orderView.totals));
check('order line price = variant price', orderView.items?.[0]?.priceMinor === chosen.priceMinor);
check('entitlement: unlocked after payment', orderView.files?.length > 0 && orderView.files.every((f) => f.locked === false && f.entitlementId != null), JSON.stringify(orderView.files));
const h1 = norm(await page.locator('h1').first().innerText());
check('order page: paid headline', h1.includes('تم الدفع'), h1);
check('order page: order number', norm(await page.locator('body').innerText()).includes(order.orderNumber));
const oGrand = page.locator('dt', { hasText: 'الإجمالي النهائي' }).locator('xpath=following-sibling::dd[1]');
check('order page: grand total = formatMoney(order.totals.totalMinor)', norm(await oGrand.innerText()).startsWith(money(orderView.totals.totalMinor)), await oGrand.innerText());
const dl = page.getByRole('button', { name: 'حمّل' });
check('order page: download button per unlocked file', (await dl.count()) === orderView.files.length, String(await dl.count()));
await dl.first().click();
await page.waitForResponse((r) => /\/api\/v1\/downloads\/\d+\/grant$/.test(r.url()));
await page.waitForTimeout(200);
const grant = last('POST', /^\/api\/v1\/downloads\/\d+\/grant$/);
check('download grant: 200 with a signed URL', grant?.status === 200 && typeof grant?.resBody?.url === 'string', JSON.stringify(grant?.resBody).slice(0, 120));
check('download grant: entitlement id from the order', grant?.path.includes(`/downloads/${orderView.files[0].entitlementId}/`), grant?.path);
if (grant?.resBody?.fileAvailable === false) {
  check('download grant: missing asset surfaced honestly', norm(await page.getByRole('status').last().innerText()).includes('لا يوجد ملف'), 'no status');
}

// ── ٥ · حسابي: الطلبات والتنزيلات بالرمز
await go(page, `${BASE}/account/orders?token=${order.orderToken}`, { waitUntil: 'load' });
const acc = norm(await page.locator('main').innerText());
check('account/orders: order number', acc.includes(order.orderNumber));
check('account/orders: grand total', acc.includes(money(orderView.totals.totalMinor)));
check('account/orders: no e-mail-sent claim (no mailer exists)', !acc.includes('أرسلنا'), 'claims an e-mail was sent');
await go(page, `${BASE}/account/downloads?token=${order.orderToken}`, { waitUntil: 'load' });
const dls = norm(await page.locator('main').innerText());
check('account/downloads: ready chip per unlocked file', (await page.getByText('جاهز', { exact: true }).count()) === orderView.files.length);
check('account/downloads: download buttons', (await page.getByRole('button', { name: 'حمّل' }).count()) === orderView.files.length, dls.slice(0, 100));

// ── ٦ · طلبٌ بانتظار الدفع، ورمزٌ مجهول
const api = context.request;
await api.post(`${API}/api/v1/cart/items`, { data: { productId: product.id, variantId: variants[0].id } });
const pendingRes = await (
  await api.post(`${API}/api/v1/checkout/orders`, {
    data: { name: 'معلّق', phone: '512345678', email: 'pending@example.com', taxProfile: null, policyConsent: true, marketingOptIn: false },
  })
).json();
await go(page, `${BASE}/orders/${pendingRes.orderToken}`, { waitUntil: 'load' });
check('pending order page: awaiting headline', norm(await page.locator('h1').first().innerText()).includes('بانتظار الدفع'));
check('pending order page: no charge yet', norm(await page.locator('body').innerText()).includes('لم يُخصم أي مبلغ بعد'));
check('pending order page: no download buttons', (await page.getByRole('button', { name: 'حمّل' }).count()) === 0);
const missing = await go(page, `${BASE}/orders/does-not-exist-token`, { waitUntil: 'load' });
check('unknown order: HTTP 404', missing?.status() === 404, String(missing?.status()));
await page.close();

// ── ٧ · حدّ الطلبات: ٤٢٩ (محاكى — لا يُستهلك من الميزانيّة) يُعرض برسالة الخادم ومهلته
{
  const ctx429 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  ctx429.setDefaultNavigationTimeout(120000);
  await ctx429.addCookies([{ name: 'tahdheer_consent', value: 'rejected', url: BASE }]);
  const p429 = await ctx429.newPage();
  const rate = { message: 'تجاوزت عدد الطلبات المسموح. أعد المحاولة بعد 17 ثانية.', code: 'RATE_LIMITED', retryAfter: 17 };
  await p429.route(`${API}/api/v1/cart`, (r) => r.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify(rate) }));
  for (const path of ['/cart', '/checkout']) {
    await go(p429, `${BASE}${path}`, { waitUntil: 'load' });
    await p429.getByRole('alert').first().waitFor();
    const text = norm(await p429.locator('main').innerText());
    check(`${path} 429: retryable error card`, (await p429.getByRole('button', { name: 'إعادة المحاولة' }).count()) === 1);
    check(`${path} 429: server message with retry-after shown`, text.includes(rate.message), text.slice(0, 160));
  }
  await ctx429.close();
}

// ── ٨ · مسار النيّة: /checkout?item=&fmt=&pay=apple على سلّةٍ فارغة (سياقٌ جديد = لا كعكة سلّة)
{
  const ctx2 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await shieldThrottle(ctx2);
  ctx2.setDefaultNavigationTimeout(120000);
  ctx2.setDefaultTimeout(60000);
  await ctx2.addCookies([{ name: 'tahdheer_consent', value: 'rejected', url: BASE }]);
  const p2 = await ctx2.newPage();
  const log2 = [];
  p2.on('response', async (res) => {
    const req = res.request();
    if (!req.url().startsWith(API)) return;
    log2.push({ method: req.method(), path: req.url().slice(API.length), status: res.status(), body: req.postData() ? JSON.parse(req.postData()) : null });
  });
  await go(p2, `${BASE}/checkout?item=${SLUG}&fmt=${chosen.slug}&pay=apple`, { waitUntil: 'load' });
  await p2.waitForSelector('#field-name');
  await p2.waitForTimeout(400);
  const intentAdd = log2.find((e) => e.method === 'POST' && e.path === '/api/v1/cart/items');
  check('intent: line added with the requested variant', intentAdd?.body?.productId === product.id && intentAdd?.body?.variantId === chosen.id, JSON.stringify(intentAdd?.body));
  check('intent: methods consulted for pay=apple', log2.some((e) => e.path === '/api/v1/checkout/methods'));
  check('intent: item/fmt consumed, pay kept', new URL(p2.url()).search === '?pay=apple', p2.url());
  const ckText = norm(await p2.locator('body').innerText());
  check('intent: checkout shows the line + variant price', ckText.includes(money(chosen.priceMinor)));
  /* لا Apple Pay ⇒ لا اختيارٌ مسبق ولا زرٌّ مُخترَع. */
  check('intent: no Apple Pay control when the server does not offer it', methods.applePay === true || !ckText.includes('Apple Pay'));

  /* إتمامٌ باللوحة وحدها على الجوّال (شريط الدفع اللاصق). */
  const tabTo = async (pred, max = 80) => {
    for (let i = 0; i < max; i += 1) {
      await p2.keyboard.press('Tab');
      if (await p2.evaluate(pred)) return true;
    }
    return false;
  };
  check('keyboard: reach name field', await tabTo(() => document.activeElement?.id === 'field-name'));
  await p2.keyboard.type('لوحة المفاتيح');
  await p2.keyboard.press('Tab');
  check('keyboard: phone next in DOM order', await p2.evaluate(() => document.activeElement?.id === 'field-phone'));
  await p2.keyboard.type('512345678');
  await p2.keyboard.press('Tab');
  check('keyboard: email next', await p2.evaluate(() => document.activeElement?.id === 'field-email'));
  await p2.keyboard.type('kb@example.com');
  check('keyboard: reach consent checkbox', await tabTo(() => document.activeElement?.closest('#field-policy-consent') !== null));
  await p2.keyboard.press('Space');
  check('keyboard: reach an enabled pay button', await tabTo(() => document.activeElement?.matches('button[type="submit"]:not([disabled])') === true));
  check('keyboard: focus ring visible on the CTA', await p2.evaluate(() => {
    const cs = getComputedStyle(document.activeElement);
    return cs.outlineStyle !== 'none' || cs.boxShadow !== 'none';
  }));
  await p2.keyboard.press('Enter');
  await p2.waitForURL('**/orders/**', { timeout: 30000 });
  const kbOrder = log2.find((e) => e.method === 'POST' && e.path === '/api/v1/checkout/orders');
  check('keyboard: order created from the sticky bar submit', kbOrder?.status === 201 && kbOrder?.body?.name === 'لوحة المفاتيح', JSON.stringify(kbOrder?.body));
  check('keyboard: landed on the paid order page', norm(await p2.locator('h1').first().innerText()).includes('تم الدفع'));
  await ctx2.close();
}

} catch (error) {
  thrown = error;
  const at = (error.stack ?? '').split('\n').find((l) => l.includes('commerce-flow.e2e.mjs')) ?? '';
  failures.push(`step threw: ${error.message.split('\n')[0]} @ ${at.trim()}`);
  if (lastPage && !lastPage.isClosed()) {
    const dir = resolve(here, '../../../test-results/e2e-failures');
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    try {
      const { mkdirSync, writeFileSync } = await import('node:fs');
      mkdirSync(dir, { recursive: true });
      await lastPage.screenshot({ path: `${dir}/${stamp}.png`, fullPage: true, timeout: 15000 }).catch(() => {});
      writeFileSync(`${dir}/${stamp}.html`, await lastPage.content().catch(() => ''));
      console.error(`  evidence: url=${lastPage.url()} lastNav=${JSON.stringify(lastNav)} → ${dir}/${stamp}.{png,html}`);
    } catch (e) {
      console.error(`  evidence capture failed: ${e.message}`);
    }
  }
}
await browser.close();

/*
 * ٤٢٩ أثناء الرحلة يعني أنّ جلسةً أخرى على الجهاز نفسه استهلكت الميزانيّة — لا عيبًا
 * في المتجر. تُعاد الرحلة كلّها بعد انقضاء النافذة (حتّى --retries، الافتراضيّ 3).
 */
const RETRIES = Number(arg('retries', '3'));
const ATTEMPT = Number(arg('attempt', '1'));
if (log.some((e) => e.status === 429) && ATTEMPT < RETRIES) {
  console.log(`  ⚠ throttled (429) mid-run — attempt ${ATTEMPT}/${RETRIES}; retrying after the window`);
  await new Promise((r) => setTimeout(r, 65000));
  const { spawnSync } = await import('node:child_process');
  const child = spawnSync(process.execPath, [process.argv[1], ...process.argv.slice(2).filter((x) => !x.startsWith('--attempt=')), `--attempt=${ATTEMPT + 1}`], { stdio: 'inherit' });
  process.exit(child.status ?? 1);
}

/* الخنق لا يُحتسب نجاحًا: كلّ إعادةٍ تُطبع، و‎429‎ يبلغ الواجهة يُسقط الفحص. */
check('no 429 reached the UI (after transparent retries)', log.every((e) => e.status !== 429), JSON.stringify(log.filter((e) => e.status === 429).map((e) => e.path)));
console.log(`  throttle retries (429 absorbed before the UI): ${throttleRetries}`);

const total = passed + failures.length;
for (const f of failures) console.log(`  ✗ ${f}`);
console.log(`\n  captured API calls: ${log.length}`);
console.log(`  chosen variant: #${chosen.id} ${chosen.title} (${chosen.format}) ${money(chosen.priceMinor)} SAR`);
console.log(`  checkout payload keys: ${Object.keys(last('POST', /checkout\/orders$/)?.reqBody ?? {}).join(', ')}`);
console.log(`  backend contract keys: ${[...CONTRACT_KEYS].join(', ')}`);
console.log(`  totals: ${JSON.stringify(t)}`);
console.log(`  order: ${last('POST', /checkout\/orders$/)?.resBody?.orderNumber} · ${pendingOrder?.status} → ${confirm?.resBody?.status}`);
console.log(`\n  commerce-flow e2e · ${total} assertions · ${passed} passed · ${failures.length} failed`);
if (thrown) console.log(`  (aborted: ${thrown.message.split('\n')[0]})`);
process.exit(Math.min(failures.length, 255));
