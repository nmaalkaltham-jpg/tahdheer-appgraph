#!/usr/bin/env node
/**
 * تصدير كل صفحة إلى ملف HTML مستقل بذاته.
 * Export every route as a self-contained .html file.
 *
 * الناتج يطابق شكل ملفات التصميم المرجعية: ملف واحد يفتح بنقرة، أنماطه وصوره
 * بداخله، ويُقرأ كوده مباشرة. الأنماط تُدمج في <style> والصور إلى data: URI،
 * فلا يعتمد الملف على خادم ولا على شبكة.
 *
 * لقطة ساكنة عمدًا: سكربتات Next تُزال لأنها تحتاج خادمًا وتُخرج أخطاء 404 في
 * وحدة التحكم بلا فائدة. البنية والنصوص والأنماط — وهي المطلوب للمراجعة —
 * كلها موجودة لأن الصفحات مُصيَّرة من الخادم أصلًا.
 *
 *   node scripts/preview/export-html.mjs [--base http://127.0.0.1:3100] [--admin http://127.0.0.1:3200]
 */
import { mkdir, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = join(ROOT, 'ملفات HTML');

const STORE_PAGES = [
  ['الرئيسية', '/'],
  ['المتجر', '/shop'],
  ['صفحة المنتج', '/products/%D8%AA%D8%AD%D8%B6%D9%8A%D8%B1-%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA-%D8%B3%D8%A7%D8%AF%D8%B3-%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A-%D8%A7%D9%84%D9%81%D8%B5%D9%84-%D8%A7%D9%84%D8%A3%D9%88%D9%84'],
  ['صفحة المرحلة', '/stages/%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A'],
  ['البحث', '/search?q=%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA'],
  ['البحث — بلا نتائج', '/search?q=zzzqqq'],
  ['السلة', '/cart'],
  ['الدفع', '/checkout'],
  ['العروض', '/offers'],
  ['الأكثر مبيعًا', '/best-sellers'],
  ['وصل حديثًا', '/new'],
  ['المراحل', '/stages'],
  ['تسجيل الدخول', '/login'],
  ['حسابي', '/account'],
  ['المدونة', '/blog'],
  ['من نحن', '/about'],
  ['تواصل معنا', '/contact'],
  ['كيف يعمل المتجر', '/how-it-works'],
  ['مركز المساعدة', '/help'],
  ['طرق الدفع', '/payment-methods'],
  ['سياسة الاسترجاع', '/policies/refund'],
  ['سياسة الخصوصية', '/policies/privacy'],
  ['الشروط والأحكام', '/policies/terms'],
  ['الحزم', '/bundles'],
  ['صفحة غير موجودة', '/%D9%84%D8%A7-%D8%B4%D9%8A%D8%A1'],
];

const ADMIN_PAGES = [
  ['لوحة القيادة', '/'],
  ['استوديو المظهر', '/appearance'],
  ['استوديو المنتج', '/catalog-studio'],
  ['مكتبة الوسائط', '/media'],
  ['تحسين الصور', '/media/optimize'],
  ['استوديو القوائم', '/navigation'],
  ['التحليلات', '/analytics'],
  ['التشخيص', '/diagnostics'],
  ['محرك SEO', '/seo'],
  ['الحوكمة الذاتية', '/growth'],
  ['المنتجات', '/products'],
  ['الطلبات', '/orders'],
  ['تسجيل الدخول', '/login'],
];

const ADMIN_LOGIN = {
  email: process.env.ADMIN_EMAIL ?? 'owner@localhost',
  password: process.env.ADMIN_SEED_PASSWORD ?? 'PreviewAdmin!2026',
};

function arg(flag, fallback) {
  const i = process.argv.indexOf(flag);
  return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const MIME = { svg: 'image/svg+xml', png: 'image/png', jpg: 'image/jpeg', webp: 'image/webp', woff2: 'font/woff2', woff: 'font/woff' };

/** يحمّل موردًا ويحوّله إلى data: URI؛ الفشل يُرجع null فيبقى الرابط كما هو. */
async function toDataUri(url, cookie) {
  try {
    const res = await fetch(url, { headers: cookie ? { cookie } : {} });
    if (!res.ok) return null;
    const ext = (new URL(url).pathname.split('.').pop() ?? '').toLowerCase();
    const buf = Buffer.from(await res.arrayBuffer());
    return `data:${MIME[ext] ?? res.headers.get('content-type') ?? 'application/octet-stream'};base64,${buf.toString('base64')}`;
  } catch {
    return null;
  }
}

async function exportPage(base, path, cookie) {
  const res = await fetch(base + path, { headers: cookie ? { cookie } : {}, redirect: 'follow' });
  const status = res.status;
  let html = await res.text();

  // ١) ادمج ملفات الأنماط داخل <style> — بدونها يفتح الملف بلا تنسيق إطلاقًا.
  const sheets = [...html.matchAll(/<link[^>]+rel="stylesheet"[^>]*href="([^"]+)"[^>]*>/g)];
  for (const [tag, href] of sheets) {
    try {
      const sheetUrl = new URL(href, base).href;
      let css = await (await fetch(sheetUrl, { headers: cookie ? { cookie } : {} })).text();

      // url(...) داخل ملف أنماط يُحلّ نسبةً إلى ملف الأنماط نفسه، لا إلى الصفحة.
      // حلّه من جذر الصفحة يعطي مسارًا خاطئًا فيسقط الخط بصمت إلى خط النظام.
      const assets = new Set(
        [...css.matchAll(/url\((['"]?)([^)'"]+)\1\)/g)]
          .map((m) => m[2])
          .filter((u) => !u.startsWith('data:')),
      );
      for (const asset of assets) {
        const uri = await toDataUri(new URL(asset, sheetUrl).href, cookie);
        if (uri) css = css.split(asset).join(uri);
      }

      html = html.replace(tag, `<style data-from="${href}">\n${css}\n</style>`);
    } catch {
      html = html.replace(tag, `<!-- تعذّر تضمين: ${href} -->`);
    }
  }

  // ٢) ادمج الخطوط: Tajawal جزء من الهوية، وتركها كمسار خادم يعني السقوط إلى
  //    خط النظام عند فتح الملف بلا خادم — أي فقدان الشكل المقصود.
  const fonts = new Set(
    [...html.matchAll(/href="([^"]*\.woff2?)"/g)].map((m) => m[1]).filter((u) => !u.startsWith('data:')),
  );
  for (const font of fonts) {
    const uri = await toDataUri(new URL(font, base).href, cookie);
    if (uri) html = html.split(font).join(uri);
  }

  // روابط التحميل المسبق للخطوط صارت data: — احذفها، فلا معنى لتحميل مسبق لها.
  html = html.replace(/<link[^>]+rel="preload"[^>]+as="font"[^>]*>/g, '');

  // ٣) صور <img> إلى data: URI ليكون الملف مستقلًا فعلًا.
  const imgs = [...html.matchAll(/<img[^>]+src="([^"]+)"/g)];
  const seen = new Map();
  for (const [, src] of imgs) {
    if (src.startsWith('data:')) continue;
    if (!seen.has(src)) seen.set(src, await toDataUri(new URL(src, base).href, cookie));
    const uri = seen.get(src);
    if (uri) html = html.split(`src="${src}"`).join(`src="${uri}"`);
  }

  // ٤) أزل سكربتات Next: تحتاج خادمًا، وبدونه 404 وأخطاء وحدة تحكم بلا فائدة.
  html = html
    .replace(/<script\b[^>]*\bsrc="[^"]*_next[^"]*"[^>]*>[\s\S]*?<\/script>/g, '')
    .replace(/<script\b[^>]*>[\s\S]*?self\.__next_f[\s\S]*?<\/script>/g, '')
    .replace(/<link[^>]+rel="(?:preload|prefetch|modulepreload)"[^>]+_next[^>]*>/g, '');

  const banner = `<!--
  لقطة HTML ساكنة من «التحاضير الحديثة» — ${path}
  رمز الاستجابة: ${status}
  مُولَّدة بـ: npm run export:html
  الأنماط مدمجة والصور بصيغة data: URI، فالملف يفتح بلا خادم ولا شبكة.
  سكربتات Next مُزالة عمدًا: هذه لقطة للبنية والتنسيق، لا نسخة تفاعلية.
-->\n`;

  return { html: banner + html, status };
}

async function adminCookie(adminBase) {
  try {
    const res = await fetch(`${adminBase}/api/v1/admin/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(ADMIN_LOGIN),
    });
    if (!res.ok) return null;
    return (res.headers.getSetCookie?.() ?? [])
      .map((c) => c.split(';')[0])
      .join('; ') || null;
  } catch {
    return null;
  }
}

async function run(label, base, pages, dir, cookie) {
  const target = join(OUT, dir);
  await mkdir(target, { recursive: true });
  let ok = 0;
  for (const [name, path] of pages) {
    try {
      const { html, status } = await exportPage(base, path, cookie);
      await writeFile(join(target, `${name}.html`), html, 'utf8');
      console.log(`  ✓ ${name} (${status})`);
      ok += 1;
    } catch (e) {
      console.error(`  × ${name}: ${e.message}`);
    }
  }
  console.log(`${label}: ${ok}/${pages.length}\n`);
  return ok;
}

/** فهرس يربط كل الملفات — يُبنى مع التصدير فلا يشير يومًا إلى ملف محذوف. */
async function writeIndex(storeCount, adminCount) {
  const { readdir } = await import('node:fs/promises');
  const list = async (dir) => {
    try {
      const files = (await readdir(join(OUT, dir))).filter((f) => f.endsWith('.html')).sort((a, b) => a.localeCompare(b, 'ar'));
      const { stat } = await import('node:fs/promises');
      const items = [];
      for (const f of files) {
        const kb = Math.round((await stat(join(OUT, dir, f))).size / 1024);
        items.push(`<li><a href="${encodeURIComponent(dir)}/${encodeURIComponent(f)}">${f.slice(0, -5)}</a><span>${kb} ك.ب</span></li>`);
      }
      return items.join('\n');
    } catch { return ''; }
  };

  const doc = `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>ملفات HTML — التحاضير الحديثة</title>
<style>
 body{font-family:Tajawal,"SF Arabic",system-ui,sans-serif;background:#f7f9f6;color:#14261d;margin:0;padding:28px;line-height:1.9}
 h1{font-size:24px;margin:0 0 6px} h2{font-size:17px;margin:30px 0 10px;padding-inline-start:10px;border-inline-start:4px solid #1e7355}
 p{color:#5f7167;max-inline-size:80ch;margin:0 0 6px}
 ul{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(min(260px,100%),1fr));gap:8px}
 li{background:#fff;border:1px solid #e6e9e2;border-radius:11px;padding:11px 14px;display:flex;justify-content:space-between;gap:10px}
 li span{color:#5f7167;font-size:12px;font-variant-numeric:tabular-nums}
 a{color:#14261d;text-decoration:none;font-weight:700} a:hover{color:#1e7355}
 code{background:#eef1ec;padding:2px 6px;border-radius:6px;font-size:13px}
</style></head><body>
<h1>ملفات HTML — «التحاضير الحديثة»</h1>
<p>كل ملف <strong>مستقل بذاته</strong>: الأنماط داخل <code>&lt;style&gt;</code>، والخطوط والصور مدمجة بصيغة <code>data:</code>.
يفتح بنقرة بلا خادم ولا شبكة، ويُقرأ كوده كما هو.</p>
<p>لقطة ساكنة عمدًا — سكربتات Next مُزالة لأنها تحتاج خادمًا. البنية والنصوص والتنسيق كاملة لأن الصفحات مُصيَّرة من الخادم.</p>
<p>لإعادة التوليد: <code>npm run preview -- keep</code> ثم <code>npm run export:html</code></p>
<h2>واجهة المتجر (${storeCount})</h2><ul>${await list('المتجر')}</ul>
<h2>لوحة التحكم (${adminCount})</h2><ul>${await list('لوحة التحكم')}</ul>
</body></html>`;
  await writeFile(join(OUT, 'افتحني.html'), doc, 'utf8');
}

async function main() {
  const base = arg('--base', 'http://127.0.0.1:3100').replace(/\/$/, '');
  const admin = arg('--admin', 'http://127.0.0.1:3200').replace(/\/$/, '');

  try {
    await fetch(base, { redirect: 'manual' });
  } catch {
    console.error(`× المتجر لا يستجيب على ${base} — شغّل: npm run preview -- keep`);
    process.exit(1);
  }

  await rm(OUT, { recursive: true, force: true });
  await mkdir(OUT, { recursive: true });

  console.log('واجهة المتجر:');
  const a = await run('المتجر', base, STORE_PAGES, 'المتجر', null);

  console.log('لوحة التحكم:');
  const cookie = await adminCookie(admin);
  if (!cookie) console.warn('  (تعذّر تسجيل الدخول — ستُصدَّر صفحة الدخول بدل الاستوديوهات)');
  const b = await run('لوحة التحكم', admin, ADMIN_PAGES, 'لوحة التحكم', cookie);

  await writeIndex(a, b);
  console.log(`المجموع: ${a + b} ملف في ${OUT}`);
  console.log(`افتح: ${join(OUT, 'افتحني.html')}`);
  if (a + b === 0) process.exit(1);
}

main().catch((e) => { console.error(e.message); process.exit(1); });
