/**
 * اعتماد الكتالوج — حراسة انحدارٍ لما أصلحه الاعتماد:
 *  · تحويم الزرّ التركوازيّ الممتلئ (‎--ch-checkout-hover‎) يُعتِم لا يُضيء: الأبيض عليه ‎≥4.5:1‎
 *    (القالب ‎#1a9b87‎ كان ‎3.45:1‎).
 *  · هيكل الانتظار يطابق هندسة الأرشيف رقمًا برقم (رفّ ‎264‎ من ‎1200‎، فجوات ‎12/20‎،
 *    عمودان ثمّ ‎240px‎ من ‎768‎، حشوة ‎16‎/‎--sf-gutter‎).
 *  · كلّ مسار كتالوجيّ يحمل `loading.tsx`.
 *  · هدف اللمس للفتات ‎≥44px‎ عبر عنصرٍ زائف، وغلاف تتبّع النقر في البحث لا يورّث عرض المحتوى.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = resolve(import.meta.dirname, '../../../..');
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');

const CATALOG_CSS = 'apps/storefront/app/(catalog)/_components/catalog.module.css';
const SKELETON_CSS = 'apps/storefront/app/(catalog)/_components/CatalogSkeleton.module.css';

const luminance = (hex) => {
  const [r, g, b] = hex.match(/[0-9a-f]{2}/gi).map((h) => parseInt(h, 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const contrastOnWhite = (hex) => 1.05 / (luminance(hex) + 0.05);
const token = (css, name) => css.match(new RegExp(`${name}:\\s*(#[0-9a-f]{6})`, 'i'))?.[1];

test('tokens: تحويم الزرّ التركوازيّ يحفظ ‎4.5:1‎ مع الأبيض ويُعتِم', () => {
  const tokens = read('packages/design-system/src/tokens.css');
  const hover = token(tokens, '--ch-checkout-hover');
  assert.ok(hover, '--ch-checkout-hover مفقود');
  assert.ok(contrastOnWhite(hover) >= 4.5, `--ch-checkout-hover ${hover} = ${contrastOnWhite(hover).toFixed(2)}:1`);
  const base = token(tokens, '--teal-on');
  assert.ok(contrastOnWhite(base) >= 4.5);
  /* الرمز الأعتم من الافتراض: تحويمٌ يُعتِم لا يُضيء. */
  assert.ok(luminance(hover) < luminance(base));
});

test('كلّ سطح كتالوجيّ يحوّم بالرمز الواحد لا بلونٍ خاصّ', () => {
  for (const file of [
    CATALOG_CSS,
    'packages/ui/src/ProductCard.module.css',
    'apps/storefront/app/samples/page.module.css',
    'apps/storefront/app/bundles/_components/BundleAddToCart.module.css',
    'apps/storefront/components/search/AssistantPanel.module.css',
  ]) {
    /* الرمز نفسه مباشرةً، أو عبر وصفة الزرّ الأساسيّ (controls.module.css تحوّم به). */
    assert.ok(read(file).includes('--ch-checkout-hover') || /composes:[^;]*btnPrimary/.test(read(file)), `${file} لا يستعمل --ch-checkout-hover`);
    assert.ok(!/:hover\s*\{[^}]*#[0-9a-f]{3,6}/i.test(read(file)), `${file} يحوّم بلونٍ خامّ`);
  }
});

test('هيكل الانتظار يطابق هندسة الأرشيف', () => {
  const catalog = read(CATALOG_CSS);
  const skeleton = read(SKELETON_CSS);
  for (const rule of [
    'grid-template-columns: 264px minmax(0, 1fr)',
    'grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr))',
    'grid-template-columns: minmax(0, 1fr) minmax(0, 1fr)',
    'grid-template-columns: repeat(auto-fit, minmax(240px, 1fr))',
    'max-inline-size: var(--sf-max)',
    'padding-inline: var(--sf-gutter)',
    'padding: 16px 16px 56px',
    'padding-block: 16px 72px',
    '@media (min-width: 1200px)',
    '@media (min-width: 768px)',
    '@media (min-width: 600px)',
  ]) {
    assert.ok(catalog.includes(rule), `الأرشيف بلا: ${rule}`);
    assert.ok(skeleton.includes(rule), `الهيكل بلا: ${rule}`);
  }
  /* نسبة الوسائط رمزُ البطاقة نفسه. */
  assert.ok(read('packages/ui/src/ProductCard.module.css').includes('var(--ct-media-ratio, 16 / 9)'));
  assert.ok(skeleton.includes('var(--ct-media-ratio, 16 / 9)'));
  /* الهيكل بارتفاع الشاشة. */
  assert.ok(/\.page \{[^}]*min-block-size: 100vh/.test(skeleton));
  /* والتذييل مخفيّ (visibility لا display) ما دام الهيكل قائمًا — حركته لا تُحسب إزاحة. */
  assert.ok(/body:has\(\[data-catalog-skeleton\]\) footer\[[^\]]+\]\) \{\n  visibility: hidden;/.test(skeleton));
  assert.ok(read('apps/storefront/app/(catalog)/_components/CatalogSkeleton.tsx').includes('data-catalog-skeleton'));
  /* الرفّ خارج ‎1200px‎ مخفيّ في الاثنين. */
  assert.match(skeleton, /\.rail \{\n  display: none;/);
  assert.match(catalog, /\.sidebar \{\n  display: none;/);
});

test('حدود الانتظار: loading.tsx للمحاور والمجموعات في نطاقها فقط، وSuspense بعد قرار 404 للمقاطع', () => {
  const expect = {
    'shop': 'archive',
    'stages/(hub)': 'tiles', 'grades/(hub)': 'tiles', 'subjects/(hub)': 'tiles', 'semesters/(hub)': 'tiles', 'kinds/(hub)': 'tiles',
    'best-sellers': 'grid', 'new': 'grid', 'offers': 'grid',
  };
  for (const [route, variant] of Object.entries(expect)) {
    const file = `apps/storefront/app/${route}/loading.tsx`;
    assert.ok(existsSync(resolve(ROOT, file)), `${file} مفقود`);
    const src = read(file);
    assert.ok(src.includes('CatalogSkeleton'), `${file} لا يستورد CatalogSkeleton`);
    if (variant === 'archive') assert.ok(!src.includes('variant='), `${file}: الأرشيف هو الافتراض`);
    else assert.ok(src.includes(`variant="${variant}"`), `${file}: المتوقَّع variant="${variant}"`);
  }
  /*
   * مقاطع الشرائح قد تكون 404: `loading.tsx` فوقها (أو فوق المحور الأب) يبثّ
   * الهيكل بحالة 200 قبل `notFound()`. فلا `loading.tsx` هناك ولا في المحور
   * خارج مجموعة المسار `(hub)`، والصفحة تقرّر الوجود ثمّ تعلّق الجسم بـ Suspense.
   */
  for (const d of ['stages', 'grades', 'subjects', 'semesters', 'kinds']) {
    assert.ok(!existsSync(resolve(ROOT, `apps/storefront/app/${d}/loading.tsx`)), `${d}/loading.tsx يغلّف [slug]`);
    assert.ok(!existsSync(resolve(ROOT, `apps/storefront/app/${d}/[slug]/loading.tsx`)), `${d}/[slug]/loading.tsx يصنع 404 ناعمًا`);
    const page = read(`apps/storefront/app/${d}/[slug]/page.tsx`);
    assert.ok(page.indexOf('await assertTermRoute(') < page.indexOf('<Suspense fallback={<CatalogSkeleton />}>'), `${d}/[slug]: القرار قبل Suspense`);
  }
  assert.ok(!existsSync(resolve(ROOT, 'apps/storefront/app/curriculum/[type]/[slug]/loading.tsx')));
  const hub = read('apps/storefront/app/curriculum/[type]/[slug]/page.tsx');
  assert.ok(hub.indexOf('if (hubRes.status === 404) notFound();') < hub.indexOf('<Suspense fallback={<CatalogSkeleton />}>'));
});

test('أهداف اللمس: الفتات ممدود إلى ‎44px‎ وغلاف البحث لا يفيض', () => {
  const catalog = read(CATALOG_CSS);
  assert.match(catalog, /\.crumbLink::before \{\n  content: '';\n  position: absolute;\n  inset-block: -13px;/);
  assert.match(catalog, /\.crumbLink \{\n  position: relative;/);
  /* ‎36px‎ للمؤشّر الدقيق وحده. */
  assert.match(catalog, /@media \(min-width: 1200px\) and \(pointer: fine\)/);
  const search = read('apps/storefront/app/search/page.module.css');
  assert.match(search, /\.grid > li > \* \{\n  flex: 1;\n  min-inline-size: 0;/);
  assert.match(search, /@media \(min-width: 1200px\) and \(pointer: fine\)/);
  assert.match(search, /\.input \{[^}]*min-block-size: 44px/);
  const samples = read('apps/storefront/app/samples/page.module.css');
  assert.match(samples, /\.fileLink::after \{\n  content: '';\n  position: absolute;\n  inset: 0;/);
  const bundles = read('apps/storefront/app/bundles/page.module.css');
  assert.match(bundles, /\.cardLink::after \{\n  content: '';\n  position: absolute;\n  inset: 0;/);
});

test('الأرشيف الفارغ بمرشّحٍ واحد لا يكرّر الوجهة نفسها بزرّين', () => {
  const archive = read('apps/storefront/app/(catalog)/_components/Archive.tsx');
  assert.match(archive, /chips\.length > 1 \? \[\{ label: 'امسح الكل'/);
  const empty = read('apps/storefront/app/(catalog)/_components/CatalogEmpty.tsx');
  assert.ok(empty.includes('key={action.label}'));
});
