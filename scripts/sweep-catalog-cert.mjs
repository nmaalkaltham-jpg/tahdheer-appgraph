/**
 * مسحُ اعتماد الكتالوج — يقيس ما تدّعيه مصفوفة الاعتماد:
 *  · لا فيضان أفقيّ في عشرة عروض (‎320‎ → ‎1920‎).
 *  · كلّ هدفٍ تفاعليّ ‎≥44px‎ (مع احتساب العنصر الزائف الممدود) — إلّا شرائح ‎36px‎
 *    للمؤشّر الدقيق فوق ‎1200px‎ (WCAG 2.5.8) وروابط النصّ الجاري.
 *  · بطاقة المنتج الواحدة: ‎16px‎ استدارة، حدّ ‎1px‎، وسائط ‎128px‎، شارات لا
 *    تتصادم مع القلب ولا تُقصّ، وعنوان على سطرين.
 *  · لا صور مكسورة.
 *
 * الاستعمال:
 *   node scripts/sweep-catalog-cert.mjs --base=http://127.0.0.1:3000 "--routes=/shop,/stages" --no-discover
 */
import { chromium } from 'playwright';
import { readArg } from './lib/storefront-routes.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const WIDTHS = readArg('widths', '320,360,375,390,430,768,1024,1280,1440,1920').split(',').map(Number);
const ROUTES = readArg(
  'routes',
  '/shop,/stages,/grades,/subjects,/semesters,/kinds,/best-sellers,/new,/offers,/search,/samples,/bundles',
)
  .split(',')
  .filter(Boolean);

const AUDIT = () => {
  const qa = (s) => [...document.querySelectorAll(s)];
  const box = (el) => el.getBoundingClientRect();
  const hit = (a, b) => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
  const px = (v) => parseFloat(v) || 0;
  const fine = matchMedia('(pointer: fine)').matches && innerWidth >= 1200;
  const fails = [];

  const overflow = document.documentElement.scrollWidth - innerWidth;
  if (overflow > 0) fails.push(`فيضان أفقيّ ${overflow}px`);

  for (const el of qa('main a[href], main button, main summary, main input, main select')) {
    const bb = box(el);
    if (bb.width === 0 || bb.height === 0) continue;
    if (bb.width >= 44 && bb.height >= 44) continue;
    if (fine && bb.height >= 36 && bb.width >= 36) continue;
    if (el.tagName === 'A' && getComputedStyle(el).display === 'inline' && el.closest('p,li')) continue;
    let ok = false;
    for (const pseudo of ['::before', '::after']) {
      const ps = getComputedStyle(el, pseudo);
      if (ps.position !== 'absolute' || ps.content === 'none') continue;
      let anchor = el;
      while (anchor && getComputedStyle(anchor).position === 'static') anchor = anchor.parentElement;
      const ab = box(anchor || el);
      if (ab.width - px(ps.left) - px(ps.right) >= 44 && ab.height - px(ps.top) - px(ps.bottom) >= 44) ok = true;
    }
    if (!ok) fails.push(`هدف ${Math.round(bb.width)}×${Math.round(bb.height)} «${(el.getAttribute('aria-label') || el.textContent).trim().slice(0, 24)}»`);
  }

  const broken = qa('main img').filter((i) => i.complete && i.naturalWidth === 0).length;
  if (broken) fails.push(`${broken} صورة مكسورة`);

  for (const card of qa('article[data-theme-component="card"]')) {
    const cs = getComputedStyle(card);
    const media = card.firstElementChild;
    const sku = card.dataset.sku;
    if (cs.borderRadius !== '16px') fails.push(`بطاقة ${sku}: استدارة ${cs.borderRadius}`);
    if (cs.borderTopWidth !== '1px') fails.push(`بطاقة ${sku}: حدّ ${cs.borderTopWidth}`);
    if (Math.round(box(media).height) !== 128) fails.push(`بطاقة ${sku}: وسائط ${Math.round(box(media).height)}px`);
    const ov = media.querySelector('[class*=overlays]');
    const ac = media.querySelector('[class*=actions]');
    if (ov && ac && ov.children.length && hit(box(ov), box(ac))) fails.push(`بطاقة ${sku}: الشارات فوق القلب`);
    const mb = box(media);
    if ([...(ov?.children || [])].some((p) => box(p).right > mb.right + 0.5 || box(p).left < mb.left - 0.5 || box(p).bottom > mb.bottom + 0.5)) fails.push(`بطاقة ${sku}: شارة مقصوصة`);
    const h3 = card.querySelector('h3');
    if (h3 && getComputedStyle(h3).webkitLineClamp !== '2') fails.push(`بطاقة ${sku}: العنوان بلا قصّ سطرين`);
  }
  return [...new Set(fails)];
};

const browser = await chromium.launch();
const context = await browser.newContext({ locale: 'ar-SA' });
await context.addCookies([{ name: 'tahdheer_consent', value: 'rejected', url: BASE }]);
const page = await context.newPage();
let failures = 0;

for (const route of ROUTES) {
  await page.setViewportSize({ width: 1440, height: 900 });
  const res = await page.goto(BASE + route, { waitUntil: 'load', timeout: 120000 });
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  if (!res || res.status() >= 400) {
    console.log(`✗ ${route}: HTTP ${res?.status() ?? 'بلا ردّ'}`);
    failures += 1;
    continue;
  }
  const perWidth = [];
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 900 });
    await page.waitForTimeout(150);
    const fails = await page.evaluate(AUDIT);
    if (fails.length) perWidth.push(`  @${width}: ${fails.join(' | ')}`);
  }
  if (perWidth.length) {
    failures += 1;
    console.log(`✗ ${route}\n${perWidth.join('\n')}`);
  } else {
    console.log(`✓ ${route} (${WIDTHS.length} عروض)`);
  }
}

await browser.close();
if (failures) {
  console.log(`\n${failures} مسار فيه عيوب`);
  process.exit(1);
}
console.log('\nكلّ المسارات نظيفة');
