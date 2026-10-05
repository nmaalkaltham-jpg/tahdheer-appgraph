#!/usr/bin/env node
/**
 * تسمية اللقطات بالعربية وبناء صفحة الفهرس «افتحني.html».
 * Rename captures to Arabic labels and build the index page.
 *
 * يعمل بعد capture/compare مباشرة، فالتسمية والفهرسة جزء من الأنبوب لا خطوة
 * يدوية تُنسى — وإلا صارت صفحة الفهرس تشير إلى ملفات لم تعد موجودة.
 *
 *   node scripts/preview/index.mjs
 */
import { readdir, rename, writeFile, access } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = join(ROOT, 'معاينة الصفحات');

const DIRS = {
  'المتجر': 'واجهة المتجر',
  'لوحة التحكم': 'لوحة التحكم',
  'مقارنة التصميم بالتطبيق': 'مقارنة التصميم بالتطبيق',
};

/** اسم الصفحة بالعربية. أي مفتاح غير معروف يبقى كما هو بدل أن يُخترع له اسم. */
const PAGES = {
  home: 'الرئيسية', product: 'صفحة المنتج', pdp: 'صفحة المنتج', shop: 'المتجر',
  stage: 'صفحة المرحلة', category: 'التصنيف', search: 'البحث', cart: 'السلة',
  checkout: 'الدفع', offers: 'العروض', login: 'تسجيل الدخول', account: 'حسابي',
  blog: 'المدونة', about: 'من نحن', 'policies-refund': 'سياسة الاسترجاع',
  'payment-methods': 'طرق الدفع', 'not-found': 'صفحة غير موجودة', notfound: 'صفحة غير موجودة',
  dashboard: 'لوحة القيادة', appearance: 'استوديو المظهر', homepage: 'مدير أقسام الرئيسية',
  components: 'محرر المكوّنات', media: 'مكتبة الوسائط', navigation: 'استوديو القوائم',
  analytics: 'التحليلات', seo: 'محرك SEO', growth: 'الحوكمة الذاتية',
  'catalog-studio': 'استوديو المنتج', diagnostics: 'التشخيص',
};

const VARIANTS = {
  desktop: 'سطح المكتب', mobile: 'جوال',
  app: 'التطبيق', design: 'التصميم المرجعي',
  'home-mobile': null, // يُعالَج كصفحة كاملة
};

function arabicName(stem) {
  const cut = stem.lastIndexOf('-');
  if (cut > 0) {
    const base = stem.slice(0, cut);
    const variant = stem.slice(cut + 1);
    if (VARIANTS[variant]) return `${PAGES[base] ?? base} — ${VARIANTS[variant]}`;
  }
  return PAGES[stem] ?? stem;
}

const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

async function collect(dir) {
  const path = join(OUT, dir);
  try { await access(path); } catch { return []; }

  const files = (await readdir(path)).filter((f) => f.endsWith('.png'));
  const named = [];
  for (const file of files) {
    const stem = file.slice(0, -4);
    // مسمّى بالعربية من تشغيل سابق؟ اتركه.
    const target = /[؀-ۿ]/.test(stem) ? file : `${arabicName(stem)}.png`;
    if (target !== file) {
      try { await rename(join(path, file), join(path, target)); } catch { /* اسم مأخوذ */ }
    }
    named.push(target);
  }
  return named.sort((a, b) => a.localeCompare(b, 'ar'));
}

function section(dir, title, files) {
  if (files.length === 0) return '';
  const cards = files.map((f) => {
    const src = `${encodeURIComponent(dir)}/${encodeURIComponent(f)}`;
    const label = esc(f.slice(0, -4));
    return `<figure><a href="${src}" target="_blank"><img loading="lazy" src="${src}" alt="${label}"></a><figcaption>${label}</figcaption></figure>`;
  }).join('\n');
  return `<h2>${esc(title)} (${files.length})</h2>\n<div class="grid">${cards}</div>`;
}

async function main() {
  const sections = [];
  for (const [dir, title] of Object.entries(DIRS)) {
    sections.push(section(dir, title, await collect(dir)));
  }

  let compareLink = '';
  try {
    await access(join(OUT, 'مقارنة التصميم بالتطبيق', 'index.html'));
    compareLink = '<p class="note"><a class="cta" href="%D9%85%D9%82%D8%A7%D8%B1%D9%86%D8%A9%20%D8%A7%D9%84%D8%AA%D8%B5%D9%85%D9%8A%D9%85%20%D8%A8%D8%A7%D9%84%D8%AA%D8%B7%D8%A8%D9%8A%D9%82/index.html">افتح المقارنة جنبًا إلى جنب ←</a></p>';
  } catch { /* لم تُولَّد بعد */ }

  const doc = `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>معاينة صفحات التحاضير الحديثة</title>
<style>
 body{font-family:Tajawal,"SF Arabic",system-ui,sans-serif;background:#f7f9f6;color:#14261d;margin:0;padding:28px}
 h1{font-size:24px;margin:0 0 6px}
 h2{font-size:18px;margin:36px 0 14px;padding-inline-start:10px;border-inline-start:4px solid #1e7355}
 p.note{color:#5f7167;line-height:1.9;margin:0 0 10px;max-inline-size:80ch}
 .cta{color:#1e7355;font-weight:700}
 .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(260px,100%),1fr));gap:16px}
 figure{margin:0;background:#fff;border:1px solid #e6e9e2;border-radius:14px;padding:10px;box-shadow:0 2px 12px rgba(20,45,33,.05)}
 img{inline-size:100%;block-size:200px;object-fit:cover;object-position:top;border-radius:9px;border:1px solid #eef1ec;display:block}
 figcaption{font-size:13px;font-weight:700;margin-block-start:8px;text-align:center}
 a{color:inherit;text-decoration:none}
</style></head><body>
<h1>معاينة صفحات «التحاضير الحديثة»</h1>
<p class="note">لقطات حقيقية من المتجر ولوحة التحكم وهما يعملان ببيانات معاينة. كل لقطة تتحقّق من رمز حالة الصفحة قبل التقاطها،
فلا تُسجَّل صفحة خطأ على أنها صفحة عاملة. اضغط أي صورة لفتحها بالحجم الكامل.</p>
<p class="note">لإعادة التوليد بعد أي تعديل: <code>npm run preview</code></p>
${compareLink}
${sections.filter(Boolean).join('\n')}
</body></html>`;

  await writeFile(join(OUT, 'افتحني.html'), doc, 'utf8');
  const total = sections.filter(Boolean).length;
  console.log(`صفحة الفهرس جاهزة (${total} أقسام) — معاينة الصفحات/افتحني.html`);
}

main().catch((e) => { console.error(e.message); process.exit(1); });
