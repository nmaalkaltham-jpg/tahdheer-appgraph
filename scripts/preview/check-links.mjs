#!/usr/bin/env node
/**
 * يتحقّق من جسور التنقّل بين اللوحة والمتجر — بعد التركيب في متصفّح حقيقي.
 * Verify the admin ↔ storefront navigation bridges, after client hydration.
 *
 * السبب: شِل اللوحة مكوّن عميل خلف بوّابة جلسة، فلا يظهر في HTML الخادم. طلب
 * curl يُعيد 200 ومعه صفحة بلا شريط جانبي — فيبدو الفحص ناجحًا وهو لم يرَ شيئًا.
 * هنا نسجّل الدخول فعلًا، ننتظر التركيب، ثم نقرأ الـ DOM المُركَّب.
 *
 *   node scripts/preview/check-links.mjs --admin http://127.0.0.1:3200 \
 *        --store http://127.0.0.1:3100
 */
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';

const CHROME = [
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
].find((p) => existsSync(p));

/** وسيطٌ بصيغتيه — انظر التعليل في `check-cart.mjs`. */
function arg(name, fallback) {
  const inline = process.argv.find((a) => a.startsWith(`--${name}=`));
  if (inline !== undefined) return inline.slice(name.length + 3);
  const i = process.argv.indexOf(`--${name}`);
  return i === -1 ? fallback : process.argv[i + 1];
}

const ADMIN = arg('admin', 'http://127.0.0.1:3200');
const EMAIL = process.env.ADMIN_SEED_EMAIL ?? 'owner@localhost';
const PASSWORD = process.env.ADMIN_SEED_PASSWORD ?? 'PreviewAdmin!2026';

/** كوكي جلسة حقيقي من نقطة الدخول نفسها التي تستخدمها الواجهة. */
async function login() {
  const res = await fetch(`${ADMIN}/api/v1/admin/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: EMAIL, password: PASSWORD }),
  });
  if (!res.ok) throw new Error(`فشل الدخول: HTTP ${res.status}`);
  const jar = res.headers.getSetCookie?.() ?? [];
  if (jar.length === 0) throw new Error('الدخول نجح بلا كوكي جلسة.');
  return jar.map((c) => {
    const [pair] = c.split(';');
    const eq = pair.indexOf('=');
    return { name: pair.slice(0, eq), value: pair.slice(eq + 1) };
  });
}

async function cdp(port, path) {
  const res = await fetch(`http://127.0.0.1:${port}/json/${path}`);
  return res.json();
}

async function main() {
  if (!CHROME) throw new Error('لم يُعثر على Chrome.');
  const port = 9432;
  const chrome = spawn(CHROME, [
    '--headless', '--disable-gpu', '--no-sandbox',
    `--remote-debugging-port=${port}`,
    '--user-data-dir=/tmp/tahdheer-linkcheck-profile',
    'about:blank',
  ], { stdio: 'ignore' });

  try {
    // انتظر إنصات المنفذ بدل تخمين مهلة ثابتة.
    let targets = null;
    for (let i = 0; i < 40; i += 1) {
      try { targets = await cdp(port, 'list'); break; } catch { await new Promise((r) => setTimeout(r, 250)); }
    }
    if (!targets) throw new Error('لم يستجب Chrome على منفذ التنقيح.');

    const cookies = await login();
    const { webSocketDebuggerUrl } = targets.find((t) => t.type === 'page') ?? targets[0];
    const ws = new WebSocket(webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });

    let id = 0;
    const pending = new Map();
    ws.onmessage = (e) => {
      const msg = JSON.parse(e.data);
      if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); }
    };
    const send = (method, params = {}) =>
      new Promise((res) => { id += 1; pending.set(id, res); ws.send(JSON.stringify({ id, method, params })); });

    await send('Network.enable');
    await send('Page.enable');
    await send('Runtime.enable');
    const url = new URL(ADMIN);
    for (const c of cookies) {
      await send('Network.setCookie', { name: c.name, value: c.value, domain: url.hostname, path: '/' });
    }

    const results = [];
    for (const [label, path, selector] of [
      ['زرّ «زيارة المتجر» في الشريط', '/products', '.store-link'],
      ['رابط «عرض في المتجر» في صف منتج', '/products', '.cell-out'],
    ]) {
      await send('Page.navigate', { url: `${ADMIN}${path}` });
      // انتظر ظهور العنصر بدل مهلة ثابتة: التركيب يعتمد على ردّ /admin/me.
      let found = null;
      for (let i = 0; i < 30; i += 1) {
        await new Promise((r) => setTimeout(r, 400));
        const msg = await send('Runtime.evaluate', {
          expression: `(() => { const e = document.querySelector(${JSON.stringify(selector)});
            return e ? JSON.stringify({ text: e.textContent.trim(), href: e.getAttribute('href') }) : null; })()`,
          returnByValue: true,
        });
        // ردّ CDP متداخل: msg.result هو الغلاف، وداخله result.value القيمة.
        const value = msg?.result?.result?.value;
        if (value) { found = JSON.parse(value); break; }
      }
      results.push({ label, selector, found });
    }

    ws.close();
    let bad = 0;
    for (const r of results) {
      if (r.found) console.log(`  ✓ ${r.label} → ${r.found.href}`);
      else { console.log(`  × ${r.label} (${r.selector}) لم يظهر`); bad += 1; }
    }
    process.exitCode = bad === 0 ? 0 : 1;
  } finally {
    chrome.kill();
  }
}

main().catch((e) => { console.error(`× ${e.message}`); process.exit(1); });
