#!/usr/bin/env node
/**
 * E2E — PDP variant integrity against a running storefront + API.
 *
 * Invariant: for the selected format, the visible price, the struck price /
 * discount badge, the checked radio, the URL (?fmt=), every format link, the
 * buy-now form fields, the add-to-cart request body and the mobile sticky bar
 * all name the SAME variant — with JS, after keyboard moves, after a reload,
 * for stale deep links, and with JavaScript disabled.
 *
 * The cart POST is intercepted and answered locally: the check never mutates
 * a real cart. Exit code = number of failed assertions (0 = pass). The
 * assertion inventory is derived from the product's variants and printed.
 *
 *   node apps/storefront/e2e/pdp-variant.e2e.mjs \
 *     --base=http://127.0.0.1:3100 --api=http://127.0.0.1:58080 --slug=dev-commerce-002
 */
import { chromium } from 'playwright';

const arg = (name, fallback) =>
  process.argv.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
const BASE = arg('base', 'http://127.0.0.1:3100');
const API = arg('api', 'http://127.0.0.1:58080');
const SLUG = arg('slug', 'dev-commerce-002');
const TOKEN = 'e2e-token';

const money = (minor) =>
  `${new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(minor / 100)} SAR`;

const product = (await (await fetch(`${API}/api/v1/products/${encodeURIComponent(SLUG)}`)).json()).data;
const variants = (product.variants ?? []).filter((v) => typeof v.priceMinor === 'number');
if (variants.length < 2) {
  console.error(`✗ ${SLUG} needs ≥2 priced formats to exercise the invariant (has ${variants.length})`);
  process.exit(1);
}
const sale = (v) => v.onSale === true && typeof v.regularMinor === 'number' && v.regularMinor > v.priceMinor;

let passed = 0;
const failures = [];
const check = (label, ok, detail = '') => {
  if (ok) passed += 1;
  else failures.push(`${label}${detail ? ` — ${detail}` : ''}`);
};

const browser = await chromium.launch();

async function state(page) {
  return page.evaluate(() => {
    const aside = document.querySelector('aside[aria-label="شراء المنتج"]');
    const price = [...(aside?.querySelectorAll('b[dir="ltr"]') ?? [])].find((b) => b.textContent.includes('SAR'));
    const radio = aside?.querySelector('[role="radio"][aria-checked="true"]');
    const form = aside?.querySelector('form[action="/api/cart/buy-now"]');
    const fields = form ? Object.fromEntries(new FormData(form)) : null;
    const figure = document.querySelector('figure');
    const badge = figure ? (figure.querySelector('span span')?.textContent ?? null) : undefined;
    const links = [...(aside?.querySelectorAll('[role="radio"]') ?? [])].map((a) => a.getAttribute('href'));
    const sticky = document.querySelector('[data-bottom-chrome="fixed"] b[dir="ltr"]')?.textContent ?? null;
    return {
      price: price?.textContent.replace(/\s+/g, ' ').trim() ?? null,
      radio: radio?.querySelector('b')?.textContent ?? null,
      fields,
      badge,
      links,
      search: location.search,
      sticky: sticky?.replace(/\s+/g, ' ').trim() ?? null,
    };
  });
}

function expectVariant(tag, s, v) {
  check(`${tag}: price`, s.price === money(v.priceMinor), `${s.price} ≠ ${money(v.priceMinor)}`);
  check(`${tag}: radio`, s.radio === v.title || s.radio === v.format, `${s.radio}`);
  check(`${tag}: buy-now variantId`, s.fields?.variantId === String(v.id), JSON.stringify(s.fields));
  check(`${tag}: buy-now productId`, s.fields?.productId === String(product.id), JSON.stringify(s.fields));
  /* The badge lives on the gallery stage; a product without media has none. */
  if (s.badge !== undefined) {
    const wantBadge = sale(v) && v.discountPct > 0 ? `خصم ${v.discountPct}٪` : null;
    check(`${tag}: gallery badge`, (s.badge ?? null) === wantBadge, `${s.badge} ≠ ${wantBadge}`);
  }
  check(`${tag}: format links keep token`, s.links.every((h) => h?.includes(`token=${TOKEN}`)), s.links.join(' '));
}

// ── JS on, desktop: every deep link, then clicks, keyboard, reload, cart body
{
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  const bodies = [];
  await page.route('**/api/v1/cart/items', async (route) => {
    bodies.push(JSON.parse(route.request().postData() ?? '{}'));
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ items: [], count: 0, totals: { totalMinor: 0 } }) });
  });

  for (const v of variants) {
    await page.goto(`${BASE}/products/${SLUG}?token=${TOKEN}&fmt=${v.slug}`, { waitUntil: 'networkidle' });
    expectVariant(`deep-link ${v.slug}`, await state(page), v);
  }

  await page.goto(`${BASE}/products/${SLUG}?token=${TOKEN}&fmt=%3Cscript%3E`, { waitUntil: 'networkidle' });
  const first = variants.find((v) => v.purchasable) ?? variants[0];
  expectVariant('stale deep link falls back', await state(page), first);

  for (const v of [...variants].reverse()) {
    await page.locator(`[role="radio"]`, { hasText: v.title ?? v.format }).first().click();
    const s = await state(page);
    expectVariant(`click ${v.slug}`, s, v);
    check(`click ${v.slug}: URL`, new URLSearchParams(s.search).get('fmt') === v.slug && s.search.includes(`token=${TOKEN}`), s.search);
    bodies.length = 0;
    await page.getByRole('button', { name: /أضف إلى السلة|في السلة/ }).first().click();
    await page.waitForTimeout(300);
    check(`click ${v.slug}: cart body`, bodies.length === 1 && bodies[0].variantId === v.id && bodies[0].productId === product.id, JSON.stringify(bodies));
  }

  await page.reload({ waitUntil: 'networkidle' });
  const afterReload = await state(page);
  const last = [...variants].reverse().at(-1);
  expectVariant('reload keeps selection', afterReload, last);

  await page.locator('[role="radio"][aria-checked="true"]').focus();
  await page.keyboard.press('ArrowLeft');
  const idx = variants.findIndex((v) => v.id === last.id);
  const next = variants.slice(idx + 1).concat(variants.slice(0, idx)).find((v) => v.purchasable);
  expectVariant('keyboard ArrowLeft', await state(page), next);
  await page.close();
}

// ── JS on, mobile: sticky bar agrees with the selection
{
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  for (const v of variants) {
    await page.goto(`${BASE}/products/${SLUG}?fmt=${v.slug}`, { waitUntil: 'networkidle' });
    const s = await state(page);
    check(`mobile ${v.slug}: sticky price`, s.sticky === money(v.priceMinor), `${s.sticky}`);
  }
  await page.close();
}

// ── JS off: the server render alone names the requested variant
{
  const ctx = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  for (const v of variants) {
    await page.goto(`${BASE}/products/${SLUG}?token=${TOKEN}&fmt=${v.slug}`, { waitUntil: 'domcontentloaded' });
    const html = await page.content();
    check(`no-JS ${v.slug}: price`, html.includes(money(v.priceMinor).replace(' SAR', '')), money(v.priceMinor));
    check(`no-JS ${v.slug}: buy-now variantId`, html.includes(`name="variantId" value="${v.id}"`), '');
    check(`no-JS ${v.slug}: radio checked`, new RegExp(`aria-checked="true"[^>]*href="[^"]*fmt=${v.slug}"|href="[^"]*fmt=${v.slug}"[^>]*aria-checked="true"`).test(html), '');
  }
  await ctx.close();
}

await browser.close();

const total = passed + failures.length;
for (const f of failures) console.log(`  ✗ ${f}`);
console.log(`\n  pdp-variant e2e · ${variants.length} formats · ${total} assertions · ${passed} passed · ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
