#!/usr/bin/env node
/**
 * E2E — مسار Apple Pay من صفحة المنتج إلى الدفع (lib/apple-pay.ts).
 *
 * القاعدة: الزرّ يُعرض حين يعلن الخادم `applePay: true` **و**يقدر الجهاز
 * (`ApplePaySession.canMakePayments() === true`) — ولا يُصيَّر على الخادم أبدًا.
 * Chromium لا يملك Apple Pay، فيُحقن `window.ApplePaySession` بـaddInitScript
 * حيث يلزم، ويُترك غائبًا لإثبات الغياب.
 *
 *   --mock  متجر بيانات العيّنة (applePay:true):  --mock=http://127.0.0.1:3107
 *   --live  المتجر الحيّ (applePay:false):        --live=http://127.0.0.1:3000
 *   --api   واجهة الحيّ (للمنتج):                 --api=http://127.0.0.1:58080
 *   --mock-api واجهة العيّنة:                      --mock-api=http://127.0.0.1:58099
 *
 * لا طلب يغيّر حالةً حقيقيّة: POST السلّة وPOST الطلب و/payment وصفحة Tap
 * تُعترض كلّها بـpage.route وتُجاب بردودٍ معلّبة. رمز الخروج = عدد الإخفاقات.
 */
import { chromium } from 'playwright';

const arg = (name, fallback) =>
  process.argv.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
const MOCK = arg('mock', 'http://127.0.0.1:3107');
const MOCK_API = arg('mock-api', 'http://127.0.0.1:58099');
const LIVE = arg('live', 'http://127.0.0.1:3000');
const API = arg('api', 'http://127.0.0.1:58080');
const SLUG = arg('slug', 'science-5-t1-prep');
const LIVE_SLUG = arg('live-slug', 'dev-commerce-002');

const APPLE = 'الشراء عبر Apple Pay';
const PAY_TAP_CARD = 'https://checkout.tap.test/pay/card';
const PAY_TAP_APPLE = 'https://checkout.tap.test/pay/apple';

let passed = 0;
const failures = [];
const check = (label, ok, detail = '') => {
  if (ok) passed += 1;
  else failures.push(`${label}${detail ? ` — ${detail}` : ''}`);
  console.log(`${ok ? '✓' : '✗'} ${label}${!ok && detail ? ` — ${detail}` : ''}`);
};

const injectSession = (context) =>
  context.addInitScript(() => {
    window.ApplePaySession = { canMakePayments: () => true };
  });

const product = (await (await fetch(`${MOCK_API}/api/v1/products/${SLUG}`)).json()).data;
const methodsMock = await (await fetch(`${MOCK_API}/api/v1/checkout/methods`)).json();
const methodsLive = await (await fetch(`${API}/api/v1/checkout/methods`)).json();
check('precondition: mock declares applePay:true', methodsMock.applePay === true, JSON.stringify(methodsMock));
check('precondition: live declares applePay:false', methodsLive.applePay === false, JSON.stringify(methodsLive));

const cartLine = (variant) => ({
  id: 1,
  productId: product.id,
  title: product.title,
  slug: product.slug,
  priceMinor: variant?.priceMinor ?? 4500,
  qty: 1,
  categoryPath: [],
  format: variant?.format ?? 'DOCX',
  ...(variant ? { variantId: variant.id, variantTitle: variant.title ?? variant.format } : {}),
});
const cartBody = (lines) => {
  const total = lines.reduce((s, l) => s + l.priceMinor, 0);
  return {
    items: lines,
    totals: { grossMinor: total, productDiscountMinor: 0, couponDiscountMinor: 0, totalMinor: total, vatMinor: Math.round(total * 0.13), savingMinor: 0 },
    coupon: null,
    count: lines.length,
    requirements: { requiresShippingAddress: false },
  };
};

const browser = await chromium.launch();

// ── ١ · الخادم لا يرسم الزرّ أبدًا (HTML بلا JS)
{
  const html = await (await fetch(`${MOCK}/products/${SLUG}`)).text();
  check('SSR: no Apple Pay button in server HTML', !html.includes(APPLE));
}

// ── ٢ · صفحة المنتج على العيّنة، بجهازٍ قادر: الظهور، الترتيب، الأبعاد، النقر
{
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar' });
  await injectSession(context);
  const page = await context.newPage();
  const posts = [];
  let hold = null;
  let failNext = false;
  await page.route('**/api/v1/cart/items', async (route) => {
    const body = route.request().postDataJSON();
    posts.push(body);
    if (hold) await hold;
    if (failNext) {
      failNext = false;
      await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'هذه الصيغة غير متاحة حاليًّا' }) });
      return;
    }
    const variant = product.variants?.find((v) => v.id === body.variantId) ?? null;
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(cartBody([cartLine(variant)])) });
  });
  await page.route('**/api/v1/cart', (route) =>
    route.request().method() === 'GET'
      ? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(cartBody([])) })
      : route.continue(),
  );
  await page.goto(`${MOCK}/products/${SLUG}`, { waitUntil: 'networkidle' });

  const row = page.locator('[class*="ctaRow"]');
  const apple = row.getByRole('button', { name: APPLE });
  await apple.waitFor({ timeout: 15000 });
  check('PDP: Apple Pay button visible (server + device)', await apple.isVisible());
  check('PDP: real <button type=button lang=ar>', await apple.evaluate((b) => b.tagName === 'BUTTON' && b.type === 'button' && b.lang === 'ar'));

  const boxes = await row.evaluate((r) =>
    [...r.children].map((c) => {
      const el = c.tagName === 'FORM' ? c.querySelector('button') : c;
      const b = el.getBoundingClientRect();
      return { name: el.getAttribute('aria-label') ?? el.textContent.trim(), x: b.x, y: b.y, w: b.width, h: b.height, clipped: el.scrollWidth > el.clientWidth + 1 };
    }),
  );
  check('PDP: three purchase buttons in one row', boxes.length === 3 && boxes.every((b) => Math.abs(b.y - boxes[0].y) < 1), JSON.stringify(boxes));
  check(
    'PDP: visual order right→left = cart → buy now → Apple Pay',
    boxes[0]?.x > boxes[1]?.x && boxes[1]?.x > boxes[2]?.x && /أضف|في السلة/.test(boxes[0].name) && boxes[1].name === 'اشترِ الآن' && boxes[2].name === APPLE,
    JSON.stringify(boxes.map((b) => [b.name, Math.round(b.x)])),
  );
  check('PDP: equal heights (54px)', boxes.every((b) => Math.round(b.h) === 54), JSON.stringify(boxes.map((b) => b.h)));
  check('PDP: equal widths', boxes.every((b) => Math.abs(b.w - boxes[0].w) < 1), JSON.stringify(boxes.map((b) => b.w)));
  check('PDP: no label clipped', boxes.every((b) => !b.clipped));
  const tabOrder = await page.evaluate(() => {
    const all = [...document.querySelectorAll('button, a[href], input, [tabindex]')].filter((e) => e.tabIndex >= 0);
    return all.map((e) => e.getAttribute('aria-label') ?? e.textContent.trim()).filter((n) => /أضف إلى السلة|في السلة|اشترِ الآن|Apple Pay/.test(n));
  });
  check('PDP: DOM/tab order = visual order', JSON.stringify(tabOrder.slice(0, 3)) === JSON.stringify(boxes.map((b) => b.name)), JSON.stringify(tabOrder));
  const heart = page.locator('[class*="priceRow"] button[aria-pressed]');
  const hb = await heart.boundingBox();
  check('PDP: wishlist heart sits in the price row (44px)', hb !== null && Math.round(hb.width) === 44 && Math.round(hb.height) === 44, JSON.stringify(hb));
  check('PDP: heart left the purchase row', (await row.locator('button[aria-pressed]').count()) === 0);

  /* الصيغة المختارة: أوّل راديو محدَّد ⇒ سبيكتها ⇒ معرّفها */
  const fmt = await page.evaluate(() => {
    const on = document.querySelector('[role="radiogroup"] [role="radio"][aria-checked="true"]');
    return on ? new URL(on.href).searchParams.get('fmt') : null;
  });
  const variant = product.variants?.find((v) => v.slug === fmt) ?? null;

  // حالة الانتظار: طلب السلّة معلّق ⇒ aria-busy، وكلّ أزرار الشراء معطّلة
  let release;
  hold = new Promise((r) => (release = r));
  await apple.click();
  await page.waitForTimeout(150);
  check('loading: Apple Pay aria-busy', (await apple.getAttribute('aria-busy')) === 'true');
  check('loading: Apple Pay disabled', await apple.isDisabled());
  check('loading: add + buy-now disabled too (one purchase at a time)', (await row.getByRole('button', { name: /أضف إلى السلة|في السلة/ }).isDisabled()) && (await row.getByRole('button', { name: 'اشترِ الآن' }).isDisabled()));
  hold = null;
  let navTarget = null;
  page.on('framenavigated', (f) => {
    if (f === page.mainFrame() && f.url().includes('/checkout?') && navTarget === null) navTarget = new URL(f.url());
  });
  const nav = page.waitForURL(/\/checkout\?/, { timeout: 20000 });
  release();
  await nav;
  const post = posts.at(-1);
  check('click: cart POST productId', post?.productId === product.id, JSON.stringify(post));
  check('click: cart POST variantId = selected format', variant !== null && post?.variantId === variant.id, `${post?.variantId} vs ${variant?.id}`);
  check('click: one cart POST per click', posts.length === 1, String(posts.length));
  const url = new URL(page.url());
  check(
    'click: navigates to /checkout?item=&fmt=&pay=apple',
    url.pathname === '/checkout' && url.searchParams.get('pay') === 'apple' &&
      /* الدفع يستهلك item/fmt بعد القراءة؛ الوجهة المطلوبة تُقرأ من سجلّ التنقّل */
      navTarget?.searchParams.get('item') === SLUG && navTarget?.searchParams.get('fmt') === variant?.slug,
    page.url(),
  );

  // حالة الخطأ: الإضافة تفشل ⇒ تنبيهٌ تحت الصفّ، والزرّ يعود مفعّلًا
  await page.goto(`${MOCK}/products/${SLUG}`, { waitUntil: 'networkidle' });
  await apple.waitFor();
  failNext = true;
  await apple.click();
  const alert = page.locator('[class*="ctas"] [role="alert"]');
  await alert.waitFor({ timeout: 10000 });
  check('error: role=alert under the row with the server message', (await alert.innerText()).includes('هذه الصيغة غير متاحة حاليًّا'));
  check('error: Apple Pay re-enabled, not busy', (await apple.isEnabled()) && (await apple.getAttribute('aria-busy')) === null);
  check('error: stayed on the PDP', new URL(page.url()).pathname === `/products/${SLUG}`);

  // الجوال: الشريط اللاصق — الصفّ الثاني «اشترِ الآن» + Apple Pay بالزرّ المشترك نفسه
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(300);
  const sticky = page.locator('[data-bottom-chrome="fixed"][class*="stickyBar"]');
  const sApple = sticky.getByRole('button', { name: APPLE });
  check('mobile: sticky Apple Pay visible', await sApple.isVisible());
  const sb = await sticky.locator('[class*="stickyRow2"]').evaluate((r) =>
    [...r.querySelectorAll('button')].map((b) => { const x = b.getBoundingClientRect(); return { x: x.x, w: x.width, h: x.height }; }),
  );
  check('mobile: row 2 buy-now (right) + Apple Pay (left), equal size', sb.length === 2 && sb[0].x > sb[1].x && Math.abs(sb[0].w - sb[1].w) < 1 && Math.abs(sb[0].h - sb[1].h) < 1, JSON.stringify(sb));
  check('mobile: no horizontal overflow', await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth));
  await context.close();
}

// ── ٣ · العيّنة بلا جهازٍ قادر: لا زرّ في أيّ موضع
{
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  await page.goto(`${MOCK}/products/${SLUG}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  check('no device: Apple Pay absent (desktop)', (await page.getByRole('button', { name: APPLE }).count()) === 0);
  const two = await page.locator('[class*="ctaRow"]').evaluate((r) => [...r.children].map((c) => c.getBoundingClientRect().width));
  check('no device: the two remaining buttons fill the row equally', two.length === 2 && Math.abs(two[0] - two[1]) < 1, JSON.stringify(two));
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(300);
  check('no device: Apple Pay absent (mobile sticky)', (await page.getByRole('button', { name: APPLE }).count()) === 0);
  await context.close();
}

// ── ٤ · الحيّ (applePay:false) ولو بجهازٍ قادر: لا زرّ
{
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await injectSession(context);
  const page = await context.newPage();
  await page.goto(`${LIVE}/products/${LIVE_SLUG}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  check('live (server off): Apple Pay absent on the PDP', (await page.getByRole('button', { name: APPLE }).count()) === 0);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(300);
  check('live (server off): Apple Pay absent in the sticky bar', (await page.getByRole('button', { name: APPLE }).count()) === 0);
  await context.close();
}

// ── ٥ · الدفع على العيّنة: paymentMethod، الاختيار المسبق، التبديل، 422
async function checkoutPage({ device, orderReply, paymentReplies = [] }) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  if (device) await injectSession(context);
  const page = await context.newPage();
  const seen = { orders: [], payments: [] };
  const variant = product.variants?.[0] ?? null;
  await page.route('**/api/v1/cart', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(cartBody([cartLine(variant)])) }),
  );
  await page.route('**/api/v1/checkout/orders', async (route) => {
    seen.orders.push(route.request().postDataJSON());
    const [status, body] = orderReply(seen.orders.length);
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.route('**/api/v1/checkout/orders/*/payment', async (route) => {
    seen.payments.push(route.request().postDataJSON());
    const [status, body] = paymentReplies[seen.payments.length - 1] ?? [500, {}];
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.route('https://checkout.tap.test/**', (route) =>
    route.fulfill({ status: 200, contentType: 'text/html', body: '<title>tap</title>' }),
  );
  await page.goto(`${MOCK}/checkout?pay=apple`, { waitUntil: 'networkidle' });
  await page.waitForSelector('#field-name');
  await page.fill('#field-name', 'زائر الاختبار');
  await page.fill('#field-phone', '512345678');
  await page.fill('#field-email', 'e2e-apple@example.com');
  await page.locator('#field-policy-consent input[type="checkbox"]').check();
  return { context, page, seen };
}
const order = (method, payUrl) => [201, { orderNumber: 'TD-9001', orderToken: 'tok-apple', payment: { provider: 'tap', status: 'requires_payment', method, ...(payUrl ? { payUrl } : {}) } }];
const radios = (page) =>
  page.evaluate(() =>
    [...document.querySelectorAll('[role="radiogroup"] [role="radio"]')].map((r) => ({ label: r.querySelector('b')?.textContent, on: r.getAttribute('aria-checked') === 'true' })),
  );

{
  // ٥أ · pay=apple + جهازٌ قادر ⇒ Apple Pay محدَّد، والحمولة تحمله، والتحويل إلى رابطه
  const { context, page, seen } = await checkoutPage({ device: true, orderReply: () => order('apple_pay', PAY_TAP_APPLE) });
  const r = await radios(page);
  check('checkout: pre-order radiogroup = card + Apple Pay', r.length === 2 && r.some((x) => x.label === 'Apple Pay'), JSON.stringify(r));
  check('checkout: Apple Pay preselected from pay=apple', r.find((x) => x.label === 'Apple Pay')?.on === true, JSON.stringify(r));
  check('checkout: Apple Pay option carries the mark', (await page.locator('[role="radio"] [class*="appleMark"]').count()) === 1);
  await page.getByRole('button', { name: /ادفع/ }).first().click();
  await page.waitForURL(`${PAY_TAP_APPLE}**`, { timeout: 15000 });
  check('checkout: POST carries paymentMethod:apple_pay', seen.orders[0]?.paymentMethod === 'apple_pay', JSON.stringify(seen.orders[0]));
  check('checkout: other payload fields unchanged', Object.keys(seen.orders[0] ?? {}).join() === 'name,phone,email,taxProfile,policyConsent,marketingOptIn,paymentMethod', Object.keys(seen.orders[0] ?? {}).join());
  check('checkout: redirected to the returned payUrl', page.url().startsWith(PAY_TAP_APPLE), page.url());
  await context.close();
}

{
  // ٥ب · pay=apple بلا جهازٍ قادر ⇒ تُتجاهل النيّة بصمت
  const { context, page, seen } = await checkoutPage({ device: false, orderReply: () => order(null, PAY_TAP_CARD) });
  check('checkout (no device): no Apple Pay option', (await page.locator('[role="radio"] [class*="appleMark"]').count()) === 0);
  check('checkout (no device): no radiogroup invented before the order', (await page.getByRole('radiogroup').count()) === 0);
  await page.getByRole('button', { name: /ادفع/ }).first().click();
  await page.waitForURL(`${PAY_TAP_CARD}**`, { timeout: 15000 });
  check('checkout (no device): POST has no paymentMethod', !('paymentMethod' in (seen.orders[0] ?? {})), JSON.stringify(seen.orders[0]));
  await context.close();
}

{
  // ٥ج · التبديل بعد إنشاء الطلب: Apple Pay → البطاقة → Apple Pay، ثمّ الدفع بآخر رابط
  const { context, page, seen } = await checkoutPage({
    device: true,
    orderReply: () => order('apple_pay', null), // بلا رابط ⇒ تبقى الصفحة وتُعرض الوسائل
    paymentReplies: [
      [200, { orderNumber: 'TD-9001', payment: { provider: 'tap', status: 'requires_payment', method: null, payUrl: PAY_TAP_CARD } }],
      [200, { orderNumber: 'TD-9001', payment: { provider: 'tap', status: 'requires_payment', method: 'apple_pay', payUrl: PAY_TAP_APPLE } }],
      [200, { orderNumber: 'TD-9001', payment: { provider: 'tap', status: 'requires_payment', method: null, payUrl: PAY_TAP_CARD } }],
    ],
  });
  await page.getByRole('button', { name: /ادفع/ }).first().click();
  await page.getByText('رقم طلبك').waitFor({ timeout: 15000 });
  const card = page.getByRole('radio', { name: /بطاقة مدى أو ائتمانية/ });
  const applePay = page.getByRole('radio', { name: /Apple Pay/ });
  await card.click();
  await page.waitForTimeout(300);
  check('switch → card: POST /payment {paymentMethod:null}', seen.payments[0] !== undefined && seen.payments[0].paymentMethod === null, JSON.stringify(seen.payments));
  check('switch → card: card checked', (await card.getAttribute('aria-checked')) === 'true');
  await applePay.click();
  await page.waitForTimeout(300);
  check('switch → Apple Pay: POST /payment {paymentMethod:apple_pay}', seen.payments[1]?.paymentMethod === 'apple_pay', JSON.stringify(seen.payments));
  await card.click();
  await page.waitForTimeout(300);
  check('switch back to card: third POST /payment', seen.payments.length === 3 && seen.payments[2]?.paymentMethod === null, JSON.stringify(seen.payments));
  await page.getByRole('button', { name: /ادفع/ }).first().click();
  await page.waitForURL(`${PAY_TAP_CARD}**`, { timeout: 15000 });
  check('switch: submit redirects to the NEW payUrl', page.url().startsWith(PAY_TAP_CARD), page.url());
  check('switch: order created once', seen.orders.length === 1, String(seen.orders.length));
  await context.close();
}

{
  // ٥د · الخادم يرفض Apple Pay (422) ⇒ يعود الاختيار إلى البطاقة وتُقال الرسالة
  const msg = 'الدفع عبر Apple Pay غير متاح حاليًّا';
  const { context, page, seen } = await checkoutPage({
    device: true,
    orderReply: (n) => (n === 1 ? [422, { message: msg, errors: { paymentMethod: [msg] } }] : order(null, PAY_TAP_CARD)),
  });
  await page.getByRole('button', { name: /ادفع/ }).first().click();
  const alert = page.locator('#ck-pay [role="alert"], section [role="alert"]').filter({ hasText: msg });
  await alert.first().waitFor({ timeout: 10000 });
  check('422: Arabic message shown', await alert.first().isVisible());
  const r = await radios(page);
  check('422: switched to the default (card) option', r.find((x) => x.label !== 'Apple Pay')?.on === true, JSON.stringify(r));
  await page.getByRole('button', { name: /ادفع/ }).first().click();
  await page.waitForURL(`${PAY_TAP_CARD}**`, { timeout: 15000 });
  check('422: retry sends no paymentMethod (never a silent fallback)', seen.orders.length === 2 && !('paymentMethod' in seen.orders[1]), JSON.stringify(seen.orders));
  await context.close();
}

await browser.close();
console.log(`\n${passed} passed · ${failures.length} failed`);
for (const f of failures) console.log(`  ✗ ${f}`);
process.exit(failures.length);
