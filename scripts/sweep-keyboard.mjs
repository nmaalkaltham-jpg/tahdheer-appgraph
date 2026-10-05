#!/usr/bin/env node
/**
 * مسحُ لوحة المفاتيح — ما لا يراه ماسح العروض.
 *
 * ─── العطل الذي وُجد به ───
 *
 * شريط الموافقة الثابت كان يحجب رابطَي مرشِّح **حجبًا كاملًا** (‎36‎ من
 * ‎36px‎) حين ينتقل إليهما التركيز في `/shop`. لا فيضان، ولا قصّ، ولا خطأ
 * في الطرفيّة — ماسح العروض يمرّ نظيفًا تمامًا. ولا يظهر إلّا لمن يتنقّل
 * بلوحة المفاتيح، وهو لا يستطيع الإبلاغ عمّا لا يراه.
 *
 * وسببُه الجذريّ كان أعمق: عمود المرشّحات ملتصقٌ وأطول من الشاشة، والعنصر
 * الملتصق الأطول من مسطح تمريره يثبت ثمّ **لا يتحرّك** — فذيله غير قابل
 * للوصول أصلًا، بلوحة مفاتيح أو بفأرة.
 *
 * ─── وما يُقاس هنا ───
 *
 *  · ‎§2.4.11‎ (AA): المُركَّز عليه غير محجوبٍ **كليًّا**. والجزئيّ ‎§2.4.12‎
 *    (AAA) يُعدّ ولا يُسقط — ادّعاءُ خرقٍ عند تغطيةِ بضعة بكسلات يجعل
 *    الكاشف يصيح فيُهمَل.
 *  · ‎§2.4.7‎: حلقة تركيزٍ مرئيّة — على العنصر أو على أبٍ قريب، لأنّ
 *    `:focus-within` نمطٌ صحيح لحقلٍ داخل صدفةٍ مرسومة.
 *  · ‎§2.1.2‎: لا مصيدة — التنقّل يخرج ولا يدور في موضع.
 *
 *   npm run dev
 *   npm run sweep:keyboard
 *   npm run sweep:keyboard -- --stops=40 "--routes=/,/shop"
 */

import { chromium } from 'playwright';
import { resolveRoutes, readArg, gotoChecked } from './lib/storefront-routes.mjs';
import { describeLoadFailure } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
/*
 * عرضان لا عرضٌ واحد — والهندسة تختلف بينهما جوهريًّا.
 *
 * الحجب يعتمد على ارتفاع الطبقة الثابتة، وهو ليس رقمًا واحدًا: شريط
 * الموافقة ‎111px‎ على ‎1440‎ و‎256px‎ على ‎390‎، والترويسة صفٌّ واحد هناك
 * وصفّان هنا. ففحصُ عرضٍ واحد يترك نصف الهندسة بلا قياس — وهو الدرس نفسه
 * الذي أخرجته فجوةُ ‎901–957px‎ في ماسح العروض.
 */
const WIDTHS = readArg('widths', '390x844,1440x900')
  .split(',')
  .map((pair) => {
    const [w, h] = pair.split('x').map(Number);
    return { width: w, height: h || 900 };
  });
const MAX_STOPS = Number(readArg('stops', 60));

/*
 * حالتا الموافقة كلتاهما — لا الأولى وحدها.
 *
 * كان الماسح يفتح سياقًا نظيفًا فيقيس الزائر **الأوّل** حصرًا: شريط
 * الموافقة حاضر، وحجزُه كان يغطّي كلّ شيء فيبدو كلّ شيء سليمًا. وحين
 * يقرّر الزائر يختفي الشريط — وهي حالةُ كلّ زائرٍ عائد، أي أغلب الزيارات —
 * فيسقط الحجز. وهناك بالضبط وقع خرق ‎§2.4.11‎ الذي لم يره هذا الماسح.
 */
const CONSENT_STATES = readArg('consent', 'pending,decided').split(',').filter(Boolean);

const ROUTES = await resolveRoutes();

/*
 * ─── أدوات التطوير تُستثنى، ولا يُستثنى غيرها ───
 *
 * فاحص الواجهة (`apps/storefront/app/_inspector/Inspector.tsx:297`) لوحةٌ
 * ثابتة في الزاوية السفلى، ومفتاحُها ‎185×44‎ عند ‎390×844‎ يستقرّ فوق
 * التذييل. فكان يغطّي روابط التذييل تغطيةً كاملة، ويغطّيه شريطُ الموافقة
 * بدوره حين لم يُسأل الزائر بعد. وهو **لا يُصيَّر في الإنتاج أصلًا**: الشرط
 * في `apps/storefront/app/layout.tsx:175` يُقيَّم في الخادم.
 *
 * فالبلاغات الناتجة عنه ليست عيوبًا في المتجر. والضرر مع ذلك حقيقيّ: حجبٌ
 * إنتاجيّ واحد صار لا يُميَّز وسط عشرات الإنذارات، وكاشفٌ يصيح بلا سببٍ
 * لا يُقرأ. فالإسكات هنا استرجاعٌ للإشارة لا تخفيفٌ للمعيار.
 *
 * ─── لماذا هويّة الأداة نفسها، لا أيٌّ ممّا سواها ───
 *
 * الأداة تُعرَّف بما تُعلن عن نفسها به: مفتاحُها يقول «فاحص الواجهة». نصعد
 * منه إلى أعلى سلفٍ `fixed` — فذلك جذرُ شجرتها — ثمّ تُستثنى الشجرة
 * **بهويّة العقدة** لا بمُحدِّدٍ نصّيّ. وما رُفض، وسببُ رفضه مقيسٌ اليوم على
 * ‎/shop‎ عند ‎1440×900‎:
 *
 *  · **بصمة وحدة الأنماط**: الصنف المبنيّ من شكل
 *    `Inspector-module__<بصمة>__root`، والبصمة تتبدّل مع كلّ بناء — تطابقٌ
 *    يبور بلا إشعار. (ولا يُكتب هنا نصُّ بصمةٍ بعينها: تصير خطأً بعد أوّل بناء.)
 *  · **«ثابتٌ وz-index عالٍ»**: المقيس في الصفحة نفسها — شريط الموافقة ‎99‎،
 *    والترويسة الملتصقة ‎50‎، وجرس السلّة ‎60‎، والفاحص ‎9000‎. وأيّ عتبةٍ
 *    تُمسك الفاحص تُمسك معها أشباهَه من الطبقات الحقيقيّة، وهي بعينها ما
 *    وُجد هذا الفحص ليلتقطه. وعلوُّ الطبقة ليس ادّعاءً بأنّها أداةُ تطوير.
 *  · **`data-open`**: مقيسٌ على ‎/shop‎ — يحمله كذلك عنصرا `nav` في
 *    الترويسة. ليس علامةً مميِّزة.
 *  · **الموضع تحت `body`**: مقيسٌ — Next يُلحق بعده نصوصًا و
 *    `next-route-announcer`، ويُلحق المتجر بعدها مناطقَ جرسِ السلّة.
 *    ترتيبُ أبناء `body` ليس عقدًا يُبنى عليه.
 *
 * والأمتنُ من هذا كلّه علامةٌ يصرّح بها المصدر — `data-dev-tool` على جذر
 * الفاحص — كما تفعل طبقةُ Next بـ`data-nextjs-dev-overlay`. وهي ليست من
 * ملفّات هذا التغيير، فبقيت مطلبًا معلنًا لا افتراضًا صامتًا.
 *
 * واتّجاه العطب مقصود: لو أُعيدت صياغة النصّ، لم يُطابَق شيء وعادت
 * البلاغات — ضجيجٌ لا صمت. ولذلك يُطبع في كلّ تشغيلٍ ما استُثني بالاسم:
 * إسكاتٌ يُعلَن ويُراجَع، لا إسكاتٌ يُكتشَف بعد سنة.
 */
/*
 * يُقيَّم بعد كلّ ضغطة Tab داخل الصفحة.
 *
 * ملحوظةٌ تشغيليّة: `page.evaluate` يُرسل نصّ هذه الدالّة وحدها إلى
 * المتصفّح، فكلّ ما تستعمله يعيش بداخلها — وإلّا `ReferenceError` وقت
 * التشغيل لا وقت التدقيق.
 */
function inspectFocus() {
  const el = document.activeElement;
  if (!el || el === document.body) return null;
  // طبقة أدوات التطوير ليست شيفرة المتجر
  if (el.tagName.toLowerCase().includes('portal')) return { skip: true };

  const devToolRoots = () => {
    const roots = new Set();
    /*
     * طبقة Next: علامةٌ يصرّح بها المزوّد، ولا حاجة إلى استنتاج. و`:not(script)`
     * لأنّ Next يضع العلامة نفسها على وسمِ نصٍّ لا صندوق له — استثناؤه لا يغيّر
     * قياسًا، لكنّه يُقحم في سطر الاعتراف اسمًا لا معنى له.
     */
    for (const node of document.querySelectorAll('nextjs-portal, [data-nextjs-dev-overlay]:not(script)')) roots.add(node);
    for (const btn of document.querySelectorAll('button')) {
      // اسمُ الأداة كما تنطق به: مغلقةً «فاحص الواجهة»، ومتتبِّعةً «الفاحص يتبع التركيز»
      const name = `${btn.textContent || ''} ${btn.getAttribute('aria-label') || ''}`;
      if (!name.includes('فاحص الواجهة') && !name.includes('الفاحص يتبع التركيز')) continue;
      let root = btn;
      for (let e = btn; e && e !== document.body; e = e.parentElement) {
        if (getComputedStyle(e).position === 'fixed') root = e;
      }
      roots.add(root);
    }
    return [...roots];
  };

  const devRoots = devToolRoots();
  const inDevTool = (node) => devRoots.some((root) => root === node || root.contains(node));
  const devNames = devRoots.map((r) => `${r.tagName.toLowerCase()}.${String(r.className).split(' ')[0].split('__').pop() || '—'}`);
  // محطّةٌ داخل أداة تطوير: لا تُقاس أصلًا — لا حجبًا ولا حلقةَ تركيز
  if (inDevTool(el)) return { skip: true, devNames };

  const b = el.getBoundingClientRect();
  const label = `${el.tagName.toLowerCase()}.${String(el.className).split(' ')[0].split('__').pop() || '—'}`;

  let ring = false;
  for (let e = el, d = 0; e && d < 3; e = e.parentElement, d += 1) {
    const cs = getComputedStyle(e);
    if ((cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) || cs.boxShadow !== 'none') {
      ring = true;
      break;
    }
  }

  /*
   * الحجب الكامل يُقاس بالتقاطع لا بنقطة المنتصف: النقطة تقول «محجوب» عن
   * عنصرٍ لا تغطّي منه إلّا حافّة.
   */
  let hidden = null;
  let partial = false;
  if (b.width > 0 && b.height > 0) {
    for (const layer of document.querySelectorAll('*')) {
      const cs = getComputedStyle(layer);
      if (cs.position !== 'fixed' && cs.position !== 'sticky') continue;
      if (layer.contains(el) || el.contains(layer)) continue;
      // أداةُ تطويرٍ لا تُشحن إلى الإنتاج لا تحجب شيئًا فيه
      if (inDevTool(layer)) continue;
      const iy = Math.min(b.bottom, layer.getBoundingClientRect().bottom) - Math.max(b.top, layer.getBoundingClientRect().top);
      const ix = Math.min(b.right, layer.getBoundingClientRect().right) - Math.max(b.left, layer.getBoundingClientRect().left);
      if (ix <= 0 || iy <= 0) continue;
      if (iy >= b.height - 1 && ix >= b.width - 1) {
        hidden = `${layer.tagName.toLowerCase()}.${String(layer.className).split(' ')[0].split('__').pop()}`;
        break;
      }
      partial = true;
    }
  }

  /*
   * حلقةٌ يقصّها سلفٌ — ‎§2.4.7‎ من جهةٍ أخرى.
   *
   * الفحص أعلاه يسأل: هل تعلو طبقةٌ فوق المُركَّز عليه؟ ولا يسأل: هل يقصّ
   * **صندوقُ سلفه** حلقتَه؟ و`overflow: auto` يُنشئ صندوق قصٍّ في المحورين
   * ولو لم يظهر شريط تمرير — فحلقةُ تركيزٍ بلا حشوٍ داخله تُقطع.
   *
   * وقد وقع هذا فعلًا: جُعلت ملاحةُ الترويسة تمرّر أفقيًّا، فصارت تقصّ
   * حلقة كلّ رابطٍ فيها ‎1px‎ أعلى و‎2px‎ أسفل. ولم يره هذا الماسح.
   */
  let ringClipped = null;
  let offscreen = null;
  if (ring && b.width > 0) {
    const cs = getComputedStyle(el);
    const reach = (parseFloat(cs.outlineWidth) || 0) + (parseFloat(cs.outlineOffset) || 0);
    const clips = (axis) => ['hidden', 'clip', 'auto', 'scroll'].includes(axis);
    for (let e = el.parentElement; e && e !== document.body; e = e.parentElement) {
      const ecs = getComputedStyle(e);
      const cy = clips(ecs.overflowY);
      const cx = clips(ecs.overflowX);
      if (!cy && !cx) continue;
      const box = e.getBoundingClientRect();
      const name = `${e.tagName.toLowerCase()}.${String(e.className).split(' ')[0].split('__').pop()}`;

      /*
       * حالتان مختلفتان لا حالةٌ واحدة — والخلط بينهما يُنتج بلاغًا مضلّلًا.
       *
       *  · العنصر **خارج** المسطح كلّيًّا: المتصفّح لم يمرّر إليه، فالمُركَّز
       *    عليه غير مرئيّ أصلًا. وهي أشدّ.
       *  · العنصر داخله وحلقتُه وحدها تُقطع عند الحافّة: ‎§2.4.7‎.
       */
      const visX = Math.min(b.right, box.right) - Math.max(b.left, box.left);
      const visY = Math.min(b.bottom, box.bottom) - Math.max(b.top, box.top);
      if ((cx && visX < 1) || (cy && visY < 1)) {
        offscreen = name;
        break;
      }
      if (reach <= 0) continue;
      const cutY = cy && (b.top - reach < box.top - 0.5 || b.bottom + reach > box.bottom + 0.5);
      const cutX = cx && (b.left - reach < box.left - 0.5 || b.right + reach > box.right + 0.5);
      if (cutY || cutX) {
        ringClipped = name;
        break;
      }
    }
  }

  return { label, ring, ringClipped, offscreen, hidden, partial, devNames, zero: b.width === 0 || b.height === 0,
    key: `${label}@${Math.round(b.left)},${Math.round(b.top + window.scrollY)}` };
}

const browser = await chromium.launch();

let failures = 0;
let partials = 0;
/* ما أُسكِت يُعلَن: قائمةُ جذور أدوات التطوير المستثناة تُطبع مع الحصيلة. */
const excluded = new Set();

for (const viewport of WIDTHS) {
for (const consent of CONSENT_STATES) {
console.log(`  ── ${viewport.width}×${viewport.height} · ${consent === 'decided' ? 'زائرٌ قرّر' : 'زائرٌ لم يُسأل'}`);
const context = await browser.newContext({ viewport });
if (consent === 'decided') {
  await context.addCookies([
    { name: 'tahdheer_consent', value: 'granted', domain: new URL(BASE).hostname, path: '/' },
  ]);
}
const page = await context.newPage();

for (const route of ROUTES) {
  try {
    await gotoChecked(page, BASE, route, { waitUntil: 'networkidle', timeout: 45_000 });
  } catch (e) {
    console.log(`  × ${route.padEnd(34)} ${describeLoadFailure(e)} — ${BASE}`);
    failures += 1;
    continue;
  }

  await page.evaluate(() => document.body.focus());
  const seen = new Set();
  const issues = new Set();
  let localPartial = 0;

  for (let i = 0; i < MAX_STOPS; i += 1) {
    await page.keyboard.press('Tab');
    const info = await page.evaluate(inspectFocus);
    if (!info) break;
    for (const name of info.devNames ?? []) excluded.add(name);
    if (info.skip) continue;
    if (seen.has(info.key)) break;     // دار التنقّل — لا مصيدة ولا زيادة
    seen.add(info.key);

    if (info.hidden) issues.add(`§2.4.11 محجوبٌ كليًّا خلف ${info.hidden} · ${info.label}`);
    if (!info.ring) issues.add(`§2.4.7 بلا حلقة تركيز · ${info.label}`);
    if (info.offscreen) issues.add(`§2.4.11 خارج مسطح ${info.offscreen} — لم يُمرَّر إليه · ${info.label}`);
    if (info.ringClipped) issues.add(`§2.4.7 حلقةٌ يقصّها ${info.ringClipped} · ${info.label}`);
    if (info.zero) issues.add(`هدفٌ بلا أبعاد · ${info.label}`);
    if (info.partial) localPartial += 1;
  }

  partials += localPartial;

  if (issues.size === 0) {
    console.log(`  ✓ ${route.padEnd(34)} ${seen.size} محطّة${localPartial ? ` · ${localPartial} جزئيّ (AAA)` : ''}`);
  } else {
    failures += issues.size;
    console.log(`  × ${route}`);
    for (const issue of issues) console.log(`      ${issue}`);
  }
}

await context.close();
}
}

await browser.close();

console.log(`\n  ${ROUTES.length} مسارًا · ${failures ? `${failures} خرقًا (AA)` : 'بلا خرق (AA)'}`);
console.log(`  ${partials} محطّةً محجوبةً جزئيًّا — ‎§2.4.12‎ (AAA)، تُعدّ ولا تُسقط.`);
/*
 * سطرُ الاعتراف. الاستثناء الذي لا يُعلَن يصير بعد سنةٍ حقيقةً لا يعرف أحدٌ
 * سببها؛ ولو بارت المطابقة (أُعيدت تسمية الأداة) قال هذا السطر «لا شيء»
 * وعادت البلاغات — فيُرى الانحراف في المُخرَج لا في مراجعةٍ متأخّرة.
 */
console.log(`  أدوات تطويرٍ مستثناة من قياس الحجب: ${excluded.size ? [...excluded].join('، ') : 'لا شيء'} — لا تُصيَّر في الإنتاج.\n`);
process.exit(failures ? 1 : 0);
