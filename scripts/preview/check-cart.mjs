#!/usr/bin/env node
/**
 * تحقّق من مسار السلة كاملًا في متصفّح حقيقي.
 *
 *   node scripts/preview/check-cart.mjs [--base https://tahdheer.com]
 *
 * لماذا متصفّح لا curl: الفشل الذي كسر السلة على الإنتاج كان **غياب طلب**
 * لا فشل طلب. curl على ‎/cart‎ كان يردّ 200 وHTML سليمًا، وcurl على
 * ‎/api/v1/cart‎ كان يردّ 200 وسلّةً صالحة — والصفحة مع ذلك تعرض «تعذّر
 * الاتصال بالخادم»، لأن عنوان الـAPI لم يُخبز في حزمة المتصفّح فارتدّ العميل
 * قبل fetch. لا يُرى ذلك إلا من داخل صفحة تعمل.
 *
 * يفشل بحالة خروج ≠ 0 إن لم يُرسَل طلب سلة حقيقي، أو ظهرت رسالة تعذّر
 * الاتصال، أو انهار العرض باستثناء.
 */
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';

const CHROME = [
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
].find((p) => existsSync(p));

/**
 * وسيطٌ بصيغتيه: `--name value` و`--name=value`.
 *
 * كانت الثانية تسقط **صامتةً** إلى الافتراضيّ، فيُفحص خادمٌ غير المطلوب
 * ويُنسب فشلُه إليه. وقد وقع ذلك فعلًا: `--base=http://127.0.0.1:3000`
 * فحص ٣١٠٠ وأعلن السلّة معطّلة وهي سليمة. أداةٌ تُصدّق وسيطًا لم تقرأه
 * تكذب على مشغّلها، وكذبةُ أداةٍ أسوأ من غيابها.
 */
const arg = (name, fallback) => {
  const inline = process.argv.find((a) => a.startsWith(`--${name}=`));
  if (inline !== undefined) return inline.slice(name.length + 3);
  const i = process.argv.indexOf(`--${name}`);
  return i === -1 ? fallback : process.argv[i + 1];
};

const BASE = (arg('base', 'http://127.0.0.1:3100') ?? '').replace(/\/+$/, '');
const PORT = Number(arg('port', '9460'));

/**
 * فحصٌ قبليّ: هل هناك متجرٌ أصلًا على `BASE`؟
 *
 * بدونه يُشغَّل متصفّحٌ كامل على عنوانٍ لا يستجيب، فتُقرأ صفحةٌ فارغة
 * ويُعلَن «لم يُرسَل طلب سلة» — أي يُتَّهم كودُ السلّة بغياب خادم. وهذه
 * ليست حالةً نظريّة: الافتراضيّ `:3100` خادمُ معاينةٍ يُترك يتيمًا بعد
 * `npm run preview`، فيبقى يستمع بعد موت خلفيّته ويُجيب ٥٠٠.
 *
 * والتمييز يقرّر ما يفعله المشغّل: عطلُ سلّةٍ يُصلَح في الشيفرة، وخادمٌ
 * غائب يُشغَّل بـ`npm run preview`.
 */
async function assertServer() {
  /* ثلاث محاولات: خطأٌ عابر ليس غيابَ خادم، وتسميتُه كذلك كذبةٌ أخرى. */
  let res = null;
  let last = '';
  for (let i = 0; i < 3; i += 1) {
    try {
      res = await fetch(`${BASE}/cart`, { redirect: 'manual' });
      break;
    } catch (error) {
      last = error.message;
      await new Promise((r) => setTimeout(r, 1000));
    }
  }
  if (res === null) {
    throw new Error(
      `لا خادم على ${BASE} بعد ثلاث محاولات — ${last}. `
        + 'شغّل `npm run preview` أو مرّر `--base http://127.0.0.1:3000` لخادم التطوير.',
    );
  }
  if (res.status >= 500) {
    throw new Error(`الخادم على ${BASE} يردّ ${res.status} على /cart — ليس عطلًا في السلّة.`);
  }

  /*
   * والصفحة وحدها لا تكفي دليلًا على خادمٍ حيّ.
   *
   * خادم المعاينة اليتيم يردّ HTML سليمًا بحالة ٢٠٠ بينما حزم JS التي يشير
   * إليها تردّ ٥٠٠ — لأنّ `.next` استُبدل تحته ببناءٍ جديد وهو يحمل معرّف
   * البناء القديم. فلا يُرطَّب شيء، ولا يُرسَل طلب سلّة، ويبدو العطل عطلَ
   * سلّة وهو خادمٌ يقدّم بناءً لم يعد موجودًا. يُفحص أوّل حزمة.
   */
  const chunk = (await res.text()).match(/\/_next\/static\/chunks\/[\w.-]+\.js/)?.[0];
  if (chunk !== undefined) {
    const asset = await fetch(`${BASE}${chunk}`).catch(() => null);
    if (asset === null || !asset.ok) {
      throw new Error(
        `الخادم على ${BASE} يقدّم صفحةً تطلب ${chunk} وهو يردّ ${asset?.status ?? 'لا شيء'} — `
          + 'بناءٌ استُبدل تحت خادمٍ يعمل. أعِد تشغيله بـ`npm run preview`. ليس عطلًا في السلّة.',
      );
    }
  }
}

async function main() {
  if (!CHROME) throw new Error('لم يُعثر على Chrome.');

  await assertServer();

  const chrome = spawn(CHROME, [
    '--headless', '--disable-gpu', '--no-sandbox',
    '--window-size=1440,900',
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=/tmp/tahdheer-cartcheck-${PORT}`,
    'about:blank',
  ], { stdio: 'ignore' });

  const failures = [];

  try {
    /*
     * ثلاثون ثانية لا عشر.
     *
     * كانت ٤٠ محاولة × ٢٥٠م.ث = عشر ثوانٍ، وهي تكفي Chrome دافئًا ولا تكفيه
     * باردًا على macOS — فيُعلن الفاحص «لم يستجب Chrome» على نظامٍ سليم،
     * ويُقرأ ذلك عطلًا في السلّة. (قِيس: الإقلاع الأوّل يفشل والثاني يمرّ
     * بالوسائط نفسها.) والمهلة تُذكر في الرسالة لأنّ «لم يستجب» بلا مدّة لا
     * يقول للمشغّل هل يمدّها أم يبحث عن سببٍ آخر.
     */
    const BOOT_MS = 30_000;
    const startedAt = Date.now();
    let targets = null;
    while (Date.now() - startedAt < BOOT_MS) {
      try {
        targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
        break;
      } catch {
        await new Promise((r) => setTimeout(r, 250));
      }
    }
    if (!targets) {
      throw new Error(`لم يستجب Chrome على منفذ التنقيح ${PORT} خلال ${BOOT_MS / 1000} ثانية.`);
    }

    const ws = new WebSocket((targets.find((t) => t.type === 'page') ?? targets[0]).webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });

    let id = 0;
    const pending = new Map();
    const apiCalls = [];
    const errors = [];

    ws.onmessage = (event) => {
      const msg = JSON.parse(event.data);
      if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); return; }
      if (msg.method === 'Network.responseReceived' && /\/api\/v1\//.test(msg.params.response.url)) {
        apiCalls.push({ status: msg.params.response.status, url: msg.params.response.url });
      }
      if (msg.method === 'Runtime.exceptionThrown') {
        errors.push((msg.params.exceptionDetails?.exception?.description ?? '').split('\n')[0]);
      }
    };

    const send = (method, params = {}) =>
      new Promise((res) => { id += 1; pending.set(id, res); ws.send(JSON.stringify({ id, method, params })); });

    await send('Network.enable');
    await send('Page.enable');
    await send('Runtime.enable');

    const go = async (url) => {
      const loaded = new Promise((res) => {
        const h = (e) => {
          const m = JSON.parse(e.data);
          if (m.method === 'Page.loadEventFired') { ws.removeEventListener('message', h); res(); }
        };
        ws.addEventListener('message', h);
      });
      await send('Page.navigate', { url });
      await loaded;
      await new Promise((r) => setTimeout(r, 3000));
    };

    const evaluate = async (expression) =>
      (await send('Runtime.evaluate', { expression, returnByValue: true }))?.result?.result?.value;

    await go(`${BASE}/cart`);
    const text = (await evaluate("document.querySelector('main')?.innerText ?? ''")) ?? '';

    const cartCalls = apiCalls.filter((c) => c.url.includes('/api/v1/cart'));
    if (cartCalls.length === 0) {
      failures.push('لم يُرسَل أيّ طلب إلى /api/v1/cart — العميل ارتدّ قبل fetch (عنوان API غير مخبوز؟)');
    } else {
      console.log(`  ✓ طلب السلة: ${cartCalls.map((c) => c.status).join('، ')}`);
    }

    if (/تعذّر الاتصال بالخادم/.test(text)) {
      failures.push('الصفحة تعرض «تعذّر الاتصال بالخادم»');
    }
    if (/غير مضبوط للاتصال/.test(text)) {
      failures.push('الصفحة تعرض «غير مضبوط» — متغيّرات العميل لم تُخبز في البناء');
    }

    const badStatus = cartCalls.filter((c) => c.status >= 400);
    if (badStatus.length > 0) {
      failures.push(`طلب سلة بحالة خطأ: ${badStatus.map((c) => `${c.status} ${c.url}`).join('، ')}`);
    }

    if (errors.length > 0) {
      failures.push(`استثناء في المتصفّح: ${errors[0]}`);
    }

    console.log(`  حالة السلة: ${text.replace(/\n+/g, ' | ').slice(0, 120) || '(فارغ)'}`);
    ws.close();
  } finally {
    chrome.kill();
  }

  if (failures.length > 0) {
    failures.forEach((f) => console.error(`  × ${f}`));
    process.exit(1);
  }
  console.log('  ✓ السلة تتصل بالخادم فعليًّا');
}

main().catch((e) => { console.error(`× ${e.message}`); process.exit(1); });
