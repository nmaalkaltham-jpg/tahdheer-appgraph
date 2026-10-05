#!/usr/bin/env node
/**
 * مسحُ التباين — WCAG 2.2 AA §1.4.3.
 *
 * ─── لماذا طريقتان لا واحدة ───
 *
 * حساب التباين من CSS يفشل صامتًا حين يكون خلف النصّ **تدرّجٌ أو صورة**:
 * `backgroundColor` تعود شفّافة، فيُقارَن النصّ بخلفيّة الصفحة. والنتيجة
 * المقيسة في أوّل تشغيل: أربعة «إخفاقات» بنسبٍ ‎1.00:1‎ و‎1.06:1‎ — كلّها
 * نصٌّ أبيض على بطلٍ أخضر داكن يُقرأ بلا عناء. ولو صُدِّقت لأُعيد تلوين
 * عناوين سليمة.
 *
 * فما يجلس على طبقةٍ مرسومة يُقاس من **البكسلات المرسومة**: تُلتقط صورة
 * العنصر ويُؤخذ طرفا السطوع فيها.
 *
 * وحتّى ذلك أخطأ أوّل مرّة: أخذتُ أشيع الألوان، فكان الأشيع هو الخلفيّة
 * مرّتين — بكسلات الحروف خطوطٌ رفيعة لا تبلغ قمّة التكرار أبدًا. والصواب
 * طرفا السطوع بعد استبعاد ما دون ‎0.5%‎ من المساحة (حوافّ التنعيم).
 *
 *   npm run dev
 *   npm run sweep:contrast
 *   npm run sweep:contrast -- --theme=dark      # المظهر الداكن (light افتراضًا)
 *   npm run sweep:contrast -- --theme=dark --all # كلّ خرقٍ لا أوّل خمسة
 */

import { chromium } from 'playwright';
import { resolveRoutes, readArg, gotoChecked } from './lib/storefront-routes.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const WIDTH = Number(readArg('width', 1440));
/*
 * المظهر: يُزرع اختيارُه في التخزين قبل أيّ سكربت (lib/theme.ts يقرأ المفتاح
 * نفسه قبل الرسم) ويُحاكى تفضيل النظام معه، فيُقاس ما يراه الزائر فعلًا.
 */
const THEME = readArg('theme', 'light');
if (!['light', 'dark'].includes(THEME)) throw new Error(`--theme=${THEME}: light | dark`);
const ALL = process.argv.includes('--all');
const ROUTES = await resolveRoutes();

/** النسبة بين سطوعين — صيغة WCAG. */
const ratio = (a, b) => {
  const lum = ([r, g, bl]) => {
    const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(bl);
  };
  const [x, y] = [lum(a), lum(b)];
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
};

/** يُقيَّم في الصفحة: يفصل ما يُحسب من CSS عمّا يحتاج بكسلات. */
function collect() {
  const parse = (s) => { const m = s.match(/[\d.]+/g); return m ? m.map(Number) : null; };
  const computable = [];
  const painted = [];

  document.querySelectorAll('main *, header *, footer *').forEach((el) => {
    if (el.children.length) return;
    if (!(el.textContent || '').trim()) return;
    if (!el.checkVisibility || !el.checkVisibility({ checkVisibilityCSS: true })) return;

    const cs = getComputedStyle(el);
    const fg = parse(cs.color);
    if (!fg || (fg[3] !== undefined && fg[3] < 0.95)) return;

    const px = parseFloat(cs.fontSize);
    const bold = Number(cs.fontWeight) >= 700;
    /* §1.4.3: «كبير» = ‎24px‎ عاديّ أو ‎18.66px‎ غليظ. */
    const need = px >= 24 || (bold && px >= 18.66) ? 3 : 4.5;
    const id = `${el.tagName.toLowerCase()}.${String(el.className).split(' ')[0].split('__').pop()}`;
    const text = (el.textContent || '').trim().slice(0, 18);

    let bg = null;
    for (let e = el; e; e = e.parentElement) {
      const s2 = getComputedStyle(e);
      if (s2.backgroundImage && s2.backgroundImage !== 'none') { bg = 'painted'; break; }
      const c = parse(s2.backgroundColor);
      if (c && (c[3] === undefined || c[3] > 0.95)) { bg = c.slice(0, 3); break; }
    }
    if (bg === null) bg = [255, 255, 255];

    if (bg === 'painted') {
      if (!painted.some((x) => x.id === id)) painted.push({ id, need, text, sel: null });
      el.setAttribute('data-contrast-probe', painted.length - 1);
    } else {
      computable.push({ id, fg: fg.slice(0, 3), bg, need, text });
    }
  });

  return { computable, painted };
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: WIDTH, height: 900 }, colorScheme: THEME });
await page.addInitScript((t) => { try { localStorage.setItem('tahdheer.theme', t); } catch {} }, THEME);

let failures = 0;
let sampled = 0;

/*
 * ─── «لم تُقَس» ليست «سقطت» ───
 *
 * كان تعذّرُ التحميل يزيد `failures`، فأعلن الماسح «‎3‎ خرقًا» ولم يجد خرقًا
 * واحدًا. والمسارات المتعثّرة تتبدّل بين تشغيلٍ وآخر — مرّةً ‎/shop‎ و‎/cart‎
 * و‎/best-sellers‎، ومرّةً ‎/subjects/…‎ و‎/semesters/…‎ — وكلّها تُجلب ‎200‎
 * مباشرةً. وهذا التبدّلُ ذاته البرهان: مهلةٌ عابرة لا عيبُ لون. والعدّ المشترك
 * يكذب مرّتين: يُحمّر نتيجةً نظيفة، ويُخفي خرق تباينٍ حقيقيًّا لو وقع داخل
 * الرقم ذاته. فعدّان منفصلان وعنوانان منفصلان.
 *
 * وهل تُسقط الصفحةُ غيرُ المقيسة الأمر؟ نعم. قاعدةُ «تُخُطِّي ولم يُحسب نجاحًا»
 * يطبع «تُخُطِّي ولم يُحسب نجاحًا» ثمّ يخرج بصفر، لأنّ تخطّيه مُعلَنُ الاختيار:
 * لا خادم على ‎3001‎ ⇒ لا فحص حيّ أصلًا. وهنا الخادم شرطُ التشغيل والصفحة كان
 * يجب أن تُقاس، فالمبدأ نفسه — لا يُحسب نجاحًا — يعني السقوط، كما في
 * `sweep-aria.mjs:133` و`sweep-forms.mjs:210`: خضرةٌ على صفحاتٍ لم تُقس ليست
 * نتيجة. الفرق أنّ السقوط يقع باسمه: ثغرةُ تغطية، لا خرقُ تباين.
 */
const unmeasured = [];

/**
 * تحميلٌ بمحاولةٍ ثانية واحدة — يعيد `null` عند النجاح، وسببَ التعذّر نصًّا.
 *
 * المهلة تبقى ‎45s‎ عمدًا: توسيعُها يُخفي التلكّؤ بدل أن يُعلنه. والمحاولة
 * الثانية لأنّ التلكّؤ عابر — ترجمةُ مسارٍ أوّل مرّة، أو ماسحٌ آخر يزاحم على
 * الخادم نفسه (رُصد `sweep-viewports` يعمل بالتوازي أثناء القياس) — والمسار
 * يُجلب ‎200‎ بعد ثوانٍ. وواحدة تكفي هنا حيث احتاج `sweep-forms.mjs:146` ثلاثًا
 * لتسلسلٍ أطول (بذرُ سلّة ثمّ دفع). وإن بقي التعذّر بعدها فهو يُعلَن لا يُبتلع.
 */
const load = async (route) => {
  /* لا يبقى «UNKNOWN» إلّا لو رمى Playwright خطأً بلا رسالة — لا نخترع سببًا. */
  let why = 'UNKNOWN';
  for (let attempt = 1; attempt <= 2; attempt += 1) {
    try {
      await gotoChecked(page, BASE, route, { waitUntil: 'networkidle', timeout: 45_000 });
      return null;
    } catch (err) {
      why = String(err?.message ?? err).split('\n')[0].trim().slice(0, 72);
    }
  }
  return `${why} (بعد محاولتين)`;
};

for (const route of ROUTES) {
  const why = await load(route);
  if (why) {
    unmeasured.push({ route, why });
    console.log(`  ∅ ${route.padEnd(34)} لم تُقَس · ${why}`);
    continue;
  }
  await page.waitForTimeout(500);

  const { computable, painted } = await page.evaluate(collect);
  const bad = [];

  for (const c of computable) {
    const r = ratio(c.fg, c.bg);
    if (r < c.need - 0.05) bad.push(`${c.id} ${r.toFixed(2)}:1 (يحتاج ${c.need}) «${c.text}»`);
  }

  /* الطبقات المرسومة: تُقاس من الصورة لا من الإعلان. */
  for (let i = 0; i < painted.length; i += 1) {
    const handle = await page.$(`[data-contrast-probe="${i}"]`);
    if (!handle) continue;
    let shot;
    try { shot = await handle.screenshot(); } catch { continue; }
    sampled += 1;
    const extremes = await page.evaluate(async (url) => {
      const img = new Image();
      await new Promise((r) => { img.onload = r; img.onerror = r; img.src = url; });
      if (!img.width) return null;
      const cv = document.createElement('canvas');
      cv.width = img.width; cv.height = img.height;
      const ctx = cv.getContext('2d');
      ctx.drawImage(img, 0, 0);
      const d = ctx.getImageData(0, 0, cv.width, cv.height).data;
      const m = new Map();
      for (let j = 0; j < d.length; j += 4) {
        const k = `${d[j]},${d[j + 1]},${d[j + 2]}`;
        m.set(k, (m.get(k) || 0) + 1);
      }
      const total = d.length / 4;
      const lum = ([r, g, b]) => { const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
      const solid = [...m].filter(([, n]) => n / total >= 0.005)
        .map(([k]) => k.split(',').map(Number))
        .sort((a, b) => lum(a) - lum(b));
      return solid.length >= 2 ? [solid[0], solid[solid.length - 1]] : null;
    }, `data:image/png;base64,${shot.toString('base64')}`);

    if (!extremes) continue;
    const r = ratio(extremes[0], extremes[1]);
    if (r < painted[i].need - 0.05) {
      bad.push(`${painted[i].id} ${r.toFixed(2)}:1 (يحتاج ${painted[i].need}, بالبكسلات) «${painted[i].text}»`);
    }
  }

  if (bad.length) {
    failures += bad.length;
    console.log(`  × ${route} (${bad.length})`);
    for (const x of ALL ? bad : bad.slice(0, 5)) console.log(`      ${x}`);
  } else {
    console.log(`  ✓ ${route.padEnd(34)} ${computable.length} محسوبًا · ${painted.length} بالبكسلات`);
  }
}

await browser.close();

if (unmeasured.length) {
  console.log('\n  ── لم تُقَس (ثغرةُ تغطية، لا خرقُ تباين)');
  for (const u of unmeasured) console.log(`     ∅ ${u.route.padEnd(34)} ${u.why}`);
}

console.log(
  `\n  ${ROUTES.length} مسارًا · ${ROUTES.length - unmeasured.length} مقيسًا · ${sampled} عنصرًا قيس بالبكسلات`
    + ` · ${failures ? `${failures} خرق تباين` : 'بلا خرق تباين'}`
    + ` · ${unmeasured.length} لم تُقَس\n`,
);
/* كلاهما يُسقط الأمر، وكلٌّ يُعلَن باسمه: الخرق تباينٌ، والتعذّر تغطيةٌ ناقصة. */
process.exit(failures > 0 || unmeasured.length > 0 ? 1 : 0);
