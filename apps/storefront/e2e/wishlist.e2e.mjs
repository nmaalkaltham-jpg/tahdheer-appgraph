#!/usr/bin/env node
/**
 * E2E — wishlist hydration against a running storefront + API.
 *
 * Invariant: every saved slug resolves to exactly one terminal row — a full
 * catalog card or an honest "failed" row — no matter how the list changes
 * while a fetch is in flight. `aria-busy` on the grid must return to false.
 *
 * The regression this guards: removing item A while item B's card request
 * was still pending left B in "loading" forever (the dedup set remembered the
 * slug, the effect cleanup discarded the answer, nothing ever re-asked).
 *
 *   node apps/storefront/e2e/wishlist.e2e.mjs --base=http://127.0.0.1:3000 --api=http://127.0.0.1:58080
 */
import { chromium } from 'playwright';

const arg = (name, fallback) =>
  process.argv.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
const BASE = arg('base', 'http://127.0.0.1:3100');
const API = arg('api', 'http://127.0.0.1:58080');
const KEY = 'tahdheer_wishlist';

const list = (await (await fetch(`${API}/api/v1/products?per_page=2`)).json()).data ?? [];
if (list.length < 2) {
  console.error(`✗ need ≥2 published products to exercise the race (have ${list.length})`);
  process.exit(1);
}
const [A, B] = list.map((p) => ({ slug: p.slug, title: p.title, priceMinor: p.priceMinor ?? 0, currency: 'SAR' }));

let passed = 0;
const failures = [];
const check = (label, ok, detail = '') => {
  if (ok) passed += 1;
  else failures.push(`${label}${detail ? ` — ${detail}` : ''}`);
};

const browser = await chromium.launch();
/* Dev servers compile routes on first hit; give navigation room. */
const NAV = 90_000;

const grid = () =>
  ({
    busy: document.querySelector('main ul[role="list"]')?.getAttribute('aria-busy') ?? null,
    cards: document.querySelectorAll('main ul[role="list"] article').length,
    rows: [...document.querySelectorAll('main li[data-status]')].map((li) => li.dataset.status),
  });

async function open(page, items) {
  await page.goto(`${BASE}/policies/privacy`, { waitUntil: 'domcontentloaded' });
  await page.evaluate(([k, v]) => localStorage.setItem(k, JSON.stringify(v)), [KEY, items]);
  await page.goto(`${BASE}/wishlist`, { waitUntil: 'domcontentloaded' });
}

/** Settles when no row is still loading; returns the final grid or null on timeout. */
async function settled(page, ms) {
  try {
    await page.waitForFunction(() => document.querySelector('main ul[role="list"]')?.getAttribute('aria-busy') === 'false', null, { timeout: ms });
    return await page.evaluate(grid);
  } catch {
    return null;
  }
}

// ── Two saved items resolve to two catalog cards
{
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  page.setDefaultNavigationTimeout(NAV);
  await open(page, [A, B]);
  const g = await settled(page, 15_000);
  check('initial: grid settles', g !== null, 'aria-busy never returned to false');
  check('initial: two full cards', g?.cards === 2, JSON.stringify(g));
  await page.close();
}

// ── Removing A while B is still in flight must not strand B
{
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  page.setDefaultNavigationTimeout(NAV);
  await page.route(`**/api/v1/products/${encodeURIComponent(B.slug)}`, async (route) => {
    await new Promise((r) => setTimeout(r, 2_000));
    await route.continue();
  });
  await open(page, [A, B]);
  await page.getByRole('button', { name: `إزالة «${A.title}» من المفضلة` }).click();
  const g = await settled(page, 15_000);
  check('race: grid settles after removal', g !== null, 'aria-busy stuck on true');
  check('race: surviving item renders its card', g?.cards === 1 && g?.rows.length === 0, JSON.stringify(g));
  await page.close();
}

// ── Removing the last item shows the explicit empty state
{
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  page.setDefaultNavigationTimeout(NAV);
  await open(page, [A]);
  await settled(page, 15_000);
  await page.getByRole('button', { name: `إزالة «${A.title}» من المفضلة` }).click();
  const empty = await page.getByText('لا شيء في المفضلة بعد').isVisible().catch(() => false);
  check('empty: explicit empty state', empty);
  await page.close();
}

await browser.close();

const total = passed + failures.length;
for (const f of failures) console.log(`  ✗ ${f}`);
console.log(`\n  wishlist e2e · ${total} assertions · ${passed} passed · ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
