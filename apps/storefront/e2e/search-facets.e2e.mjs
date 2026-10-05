#!/usr/bin/env node
/**
 * E2E — search facets against a running storefront + API.
 *
 * Invariants, on desktop and mobile:
 *   1. Every facet value's displayed count equals the result total after clicking it
 *      (and equals the API total for the same filters — the UI does not invent counts).
 *   2. Intersections: a second facet clicked inside the first narrows to the API's total
 *      for BOTH filters together.
 *   3. A selected dimension is shown as a removable chip; removing it restores the
 *      unfiltered total.
 *   4. Filter state lives in the URL: reload keeps it, Back/Forward restore it.
 *
 * The inventory of assertions is derived from the live facets, printed at the end.
 * Exit code 0 = all passed.
 *
 *   node apps/storefront/e2e/search-facets.e2e.mjs --base=http://127.0.0.1:3000 --api=http://127.0.0.1:58080 --q=تحضير
 */
import { chromium } from 'playwright';

const arg = (name, fallback) =>
  process.argv.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
const BASE = arg('base', 'http://127.0.0.1:3000');
const API = arg('api', 'http://127.0.0.1:58080');
const Q = arg('q', 'رياضيات');
const DIMS = ['stage', 'grade', 'subject', 'kind', 'semester'];

let passed = 0;
const failures = [];
const check = (label, ok, detail = '') => {
  if (ok) passed += 1;
  else failures.push(`${label}${detail ? ` — ${detail}` : ''}`);
};

/* The search API is rate-limited (429 + Retry-After): the check waits instead of failing. */
/** معاملاتُ رابط المتجر ⇐ طلب الخادم: المفتاح المكرّر يصير `dim[]` (اختيارٌ متعدّد). */
const apiQuery = (href) => {
  const sp = new URL(href, BASE).searchParams;
  const qs = new URLSearchParams();
  for (const key of new Set(sp.keys())) {
    const values = sp.getAll(key);
    if (values.length === 1) qs.set(key, values[0]);
    else for (const v of values) qs.append(`${key}[]`, v);
  }
  return qs;
};

const apiTotal = async (params) => {
  const qs = params instanceof URLSearchParams ? params : new URLSearchParams(params);
  for (let attempt = 0; attempt < 8; attempt += 1) {
    const res = await fetch(`${API}/api/v1/search?${qs}`, { headers: { Accept: 'application/json' } });
    if (res.status === 429) {
      await new Promise((r) => setTimeout(r, (Number(res.headers.get('retry-after')) || 2) * 1000));
      continue;
    }
    return (await res.json()).results.total;
  }
  throw new Error(`search API kept answering 429 for ${qs}`);
};

const browser = await chromium.launch();

/*
 * حدُّ البحث ٦٠/دقيقة لكلّ عنوان، وخادمُ المتجر وهذا الاختبار كلاهما 127.0.0.1.
 * صفحةٌ رُدّت بـ429 تعرض «أعد المحاولة» بلا نتائج — ذاك قيدُ البيئة لا عيبٌ في
 * الأوجه، فتُنتظر نافذةُ الحدّ وتُعاد الصفحة بدل أن يُعدّ صفرًا.
 */
async function settle(page) {
  for (let attempt = 0; attempt < 6; attempt += 1) {
    const limited = await page.locator('[role="alert"]', { hasText: 'أعد المحاولة' }).count();
    if (limited === 0) return;
    await new Promise((r) => setTimeout(r, 20_000));
    await page.reload({ waitUntil: 'networkidle', timeout: 180_000 });
  }
}

/** Facet rows as rendered: dimension label → [{text, count, href}]. */
async function facets(page) {
  await settle(page);
  return page.evaluate(() => {
    const out = {};
    for (const ul of document.querySelectorAll('main ul[role="list"][aria-label]')) {
      const links = [...ul.querySelectorAll('a[href*="/search?"]')];
      if (links.length === 0) continue;
      out[ul.getAttribute('aria-label')] = links.map((a) => ({
        count: Number((a.querySelector('[dir="ltr"]')?.textContent ?? '').replace(/[^0-9]/g, '')),
        href: a.getAttribute('href'),
        dim: a.getAttribute('data-facet'),
        active: a.getAttribute('aria-current') === 'true',
      }));
    }
    return out;
  });
}

const resultCount = async (page) => (await settle(page), page.evaluate(() => {
  const m = document.body.innerText.match(/تعرض\s+(\d+)\s+من\s+(\d+)/);
  return m ? Number(m[2]) : document.querySelectorAll('main ul[role="list"] article').length;
}));

const params = (href) => Object.fromEntries(new URL(href, BASE).searchParams);

for (const [device, viewport] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
  const page = await browser.newPage({ viewport });
  await page.goto(`${BASE}/search?q=${encodeURIComponent(Q)}`, { waitUntil: 'networkidle', timeout: 180_000 });
  const baseTotal = await resultCount(page);
  const rows = await facets(page);
  const dimRows = Object.entries(rows).filter(([label]) => /المرحلة|الصف|المادة|نوع الملف|الفصل/.test(label));
  check(`${device}: facet rows present`, dimRows.length > 0, JSON.stringify(Object.keys(rows)));

  // 1. Count correctness, value by value.
  for (const [label, values] of dimRows) {
    for (const v of values) {
      await page.goto(`${BASE}${v.href}`, { waitUntil: 'networkidle', timeout: 180_000 });
      const shown = await resultCount(page);
      const api = await apiTotal(params(v.href));
      check(`${device}: ${label} ${decodeURIComponent(v.href)} count ${v.count} = results`, shown === v.count, `results ${shown}`);
      check(`${device}: ${label} ${decodeURIComponent(v.href)} count ${v.count} = API`, api === v.count, `api ${api}`);
    }
  }

  // 2 + 3 + 4. One intersection path with chips, reload, Back/Forward.
  const first = dimRows[0]?.[1]?.[0];
  if (first) {
    await page.goto(`${BASE}/search?q=${encodeURIComponent(Q)}`, { waitUntil: 'networkidle', timeout: 180_000 });
    await page.click(`a[href="${first.href}"]`);
    await page.waitForLoadState('networkidle');
    const afterFirst = await resultCount(page);
    /* البُعد المختار يبقى معروضًا (عدٌّ مفصول) — التقاطعُ قيمةٌ غيرُ مختارة من بُعدٍ آخر. */
    const inner = Object.values(await facets(page)).flat().find((v) => v.dim && v.dim !== first.dim && !v.active);
    if (inner) {
      await page.click(`a[href="${inner.href}"]`);
      await page.waitForLoadState('networkidle');
      const both = params(page.url());
      const shown = await resultCount(page);
      check(`${device}: intersection ${decodeURIComponent(new URL(page.url()).search)} = API`, shown === (await apiTotal(both)), `shown ${shown}`);
      check(`${device}: intersection keeps both filters`, DIMS.filter((d) => both[d]).length >= 2, JSON.stringify(both));

      await page.reload({ waitUntil: 'networkidle' });
      check(`${device}: reload keeps the filtered state`, (await resultCount(page)) === shown);

      await page.goBack({ waitUntil: 'networkidle' });
      check(`${device}: Back restores the first filter`, (await resultCount(page)) === afterFirst);
      await page.goForward({ waitUntil: 'networkidle' });
      check(`${device}: Forward restores the intersection`, (await resultCount(page)) === shown);
    }

    const chips = await page.$$eval('ul[aria-label="المرشّحات النشطة"] a', (as) => as.map((a) => a.getAttribute('href')));
    check(`${device}: selected filters shown as removable chips`, chips.length >= 1, String(chips.length));
    const clearAll = page.locator('ul[aria-label="المرشّحات النشطة"] a', { hasText: 'مسح الكل' });
    if ((await clearAll.count()) > 0) {
      await clearAll.click();
      await page.waitForLoadState('networkidle');
      check(`${device}: «مسح الكل» drops every dimension`, DIMS.every((d) => !new URL(page.url()).searchParams.get(d)), page.url());
    } else {
      await page.goto(`${BASE}/search?q=${encodeURIComponent(Q)}`, { waitUntil: 'networkidle', timeout: 180_000 });
    }
    check(`${device}: removing all filters restores the base total`, (await resultCount(page)) === baseTotal);
  }

  // 5. اختيارٌ متعدّد في بُعدٍ واحد: «أو» — المجموع = مجموع العدّين (بُعدٌ وحيدُ القيمة لكلّ منتج).
  await page.goto(`${BASE}/search?q=${encodeURIComponent(Q)}`, { waitUntil: 'networkidle', timeout: 180_000 });
  const multiRow = Object.values(await facets(page)).find((vs) => vs.filter((v) => v.dim).length >= 2);
  if (multiRow) {
    const [a, b] = multiRow;
    await page.click(`a[href="${a.href}"]`);
    await page.waitForLoadState('networkidle');
    const again = Object.values(await facets(page)).flat();
    check(`${device}: chosen value stays listed and marked`, again.some((v) => v.dim === a.dim && v.active));
    const second = again.find((v) => v.dim === a.dim && !v.active && params(v.href)[a.dim] !== undefined && new URL(v.href, BASE).searchParams.getAll(a.dim).length === 2);
    check(`${device}: a second value of the same dimension is offered`, Boolean(second));
    if (second) {
      await page.click(`a[href="${second.href}"]`);
      await page.waitForLoadState('networkidle');
      const chosen = new URL(page.url()).searchParams.getAll(a.dim);
      const shown = await resultCount(page);
      check(`${device}: URL keeps both ${a.dim} values`, chosen.length === 2, page.url());
      check(`${device}: OR total ${shown} = API`, shown === (await apiTotal(apiQuery(page.url()))), `shown ${shown}`);
      check(`${device}: OR total = sum of the two counts`, shown === a.count + b.count || shown === a.count + second.count, `${shown} vs ${a.count}+${second.count}`);
      check(`${device}: both values marked chosen`, (await facets(page)) && Object.values(await facets(page)).flat().filter((v) => v.dim === a.dim && v.active).length === 2);
      await page.reload({ waitUntil: 'networkidle' });
      check(`${device}: reload keeps the multi-select`, (await resultCount(page)) === shown);
      const chip = page.locator('ul[aria-label="المرشّحات النشطة"] a').first();
      await chip.click();
      await page.waitForLoadState('networkidle');
      check(`${device}: a chip removes one value only`, new URL(page.url()).searchParams.getAll(a.dim).length === 1, page.url());
    }
  }

  check(`${device}: no horizontal overflow`, await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth));
  await page.close();
}

await browser.close();

const total = passed + failures.length;
for (const f of failures) console.log(`  ✗ ${f}`);
console.log(`\n  search-facets e2e · q=«${Q}» · ${total} assertions · ${passed} passed · ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
