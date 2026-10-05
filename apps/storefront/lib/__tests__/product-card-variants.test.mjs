/**
 * عقود بطاقة المنتج الواحدة (packages/ui/src/ProductCard) — تنفيذٌ واحد وتسلسلٌ
 * واحد (الغلاف ← العنوان ← البيانات ← التقييم/التسليم ← السعر ← الأزرار)،
 * وشاراتٌ من بياناتٍ حقيقيّة فقط، وأطقمٌ تغيّر المقاسات وحدها.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = resolve(import.meta.dirname, '../../../..');
const tsx = readFileSync(resolve(ROOT, 'packages/ui/src/ProductCard.tsx'), 'utf8');
const media = readFileSync(resolve(ROOT, 'packages/ui/src/ProductCardMedia.tsx'), 'utf8');
const css = readFileSync(resolve(ROOT, 'packages/ui/src/ProductCard.module.css'), 'utf8');

const block = (selector) => {
  const at = css.indexOf(selector + ' {');
  assert.ok(at >= 0, `لا قاعدة: ${selector}`);
  return css.slice(at, css.indexOf('}', at));
};

test('واجهة الخصائص: الأطقم الأربعة، والإشارات الجديدة اختياريّة، والافتراض catalog', () => {
  assert.match(tsx, /export type ProductCardVariant = 'catalog' \| 'featured' \| 'related' \| 'compact';/);
  assert.match(tsx, /export type ProductCardTint = 'teal' \| 'navy' \| 'sand' \| 'lilac';/);
  for (const prop of [
    'variant?: ProductCardVariant;',
    'tint?: ProductCardTint;',
    'metaSlot?: ReactNode;',
    'ctaLabel?: string;',
    'formats?: readonly string[];',
    'resourceType?: string;',
    'isNew?: boolean;',
    'hasSample?: boolean;',
    'instantDownload?: boolean;',
    'unavailable?: boolean;',
    'previewSlot?: ReactNode;',
    'priority?: boolean;',
  ]) {
    assert.ok(tsx.includes(prop), prop);
  }
  assert.ok(tsx.includes("variant = 'catalog'"));
  assert.ok(tsx.includes('data-variant={variant}'));
  assert.ok(tsx.includes('export const productCardCtaClass'));
  assert.ok(tsx.includes('export const productCardPreviewClass'));
});

test('الغلاف: نسبة 16:9 مقفلة لا ارتفاع ثابت، قصّ ثابت، وجزيرةٌ لحالات الصورة', () => {
  const m = block('.media');
  assert.ok(m.includes('aspect-ratio: var(--ct-media-ratio, 16 / 9)'));
  assert.ok(!m.includes('block-size'), 'لا ارتفاع ثابت للوسائط');
  const img = block('.img');
  assert.ok(img.includes('object-fit: cover') && img.includes('object-position: top'));
  /* الهيكل خلف الصورة لا فوقها (لا شفافيّة على الصورة: LCP). */
  assert.ok(css.includes(".frame[data-state='loading']::before"));
  assert.ok(!/\.img\s*\{[^}]*opacity/.test(css), 'الصورة لا تُخفى بشفافيّة');
  assert.match(media, /^'use client';/);
  assert.ok(media.includes("onError={() => setState('error')}") && media.includes("onLoad={() => setState('loaded')}"));
  assert.ok(media.includes('img.naturalWidth > 0'), 'صورةٌ اكتملت قبل الترطيب تُقرأ من complete');
  assert.ok(media.includes("loading={priority ? 'eager' : 'lazy'}"));
});

test('التسلسل: الغلاف ← العنوان ← البيانات ← التقييم/التسليم ← السعر ← الأزرار', () => {
  const order = [
    'className={styles.media}',
    'className={styles.title}',
    'className={styles.meta}',
    'className={styles.signals}',
    'className={styles.priceBlock}',
    'className={styles.was}',
    'className={styles.save}',
    'className={styles.ctaRow}',
  ];
  let last = -1;
  for (const mark of order) {
    const at = tsx.indexOf(mark);
    assert.ok(at > last, `خارج الترتيب: ${mark}`);
    last = at;
  }
});

test('الشارات: اثنتان على الأكثر من بياناتٍ حقيقيّة، في زاوية البداية العليا، والقلب في زاوية النهاية', () => {
  assert.ok(tsx.includes('const MAX_BADGES = 2') && tsx.includes('badges.slice(0, MAX_BADGES)'));
  /* الأولويّة: الخصم ← الأكثر مبيعًا ← جديد ← نموذج ← شارة المستدعي. */
  const keys = ['key="discount"', 'key="best"', 'key="new"', 'key="sample"', 'key="badge"'];
  let last = -1;
  for (const key of keys) {
    const at = tsx.indexOf(key);
    assert.ok(at > last, key);
    last = at;
  }
  const badges = block('.badges');
  assert.ok(badges.includes('inset-block-start: 10px') && badges.includes('inset-inline-start: 10px'));
  const actions = block('.actions');
  assert.ok(actions.includes('inset-block-start: 10px') && actions.includes('inset-inline-end: 10px'));
  assert.ok(!/\[data-variant='[a-z]+'\][^{]*\.badges/.test(css), 'لا طقم يحرّك الشارات');
  /* لا ادّعاء: الشارات مشروطة بالبيانات، ولا نصّ مبيعاتٍ مكتوب بلا شرط. */
  assert.ok(tsx.includes('if (bestSeller) {') && tsx.includes('if (isNew) {') && tsx.includes('if (hasSample) {'));
});

test('السعر: البطاقة تعرض ما تقوله cardPrice (المختبَرة)، بلا فراغٍ محجوز', () => {
  assert.ok(tsx.includes("import { cardPrice } from './productCardPrice';"));
  assert.ok(tsx.includes('cardPrice({ priceMinor, regularMinor, discountPct, onSale, unavailable })'));
  assert.ok(!/regularMinor - priceMinor|priceMinor \/ regularMinor/.test(tsx), 'لا حسابٌ ثانٍ في JSX');
  /* لا فراغَ محجوز لخصمٍ لا وجود له: العناصر مشروطة لا مخفيّة. */
  assert.ok(tsx.includes('{was ? (') && tsx.includes('{savings ? ('));
  const d = block('.pillDiscount');
  assert.ok(d.includes('background: var(--color-accent)') && d.includes('color: var(--color-accent-fg)'));
  /* أدوار الاستوديو تسري: السعر والعنوان والشارات بـ`--tp-*` وافتراضُ البطاقة بعد الفاصلة. */
  assert.ok(block('.price').includes('font-size: var(--tp-price-size, 19px)'));
  assert.ok(block('.title').includes('font-size: var(--tp-product-title-size, 15px)'));
  assert.ok(block('.pill').includes('font-size: var(--tp-badge-size, 11px)'));
});

test('التقييم والتسليم: لا صفّ بلا أحدهما، ونجمةٌ واحدة، وتحميلٌ فوريّ بشرط التنفيذ', () => {
  assert.ok(tsx.includes('ratingCount !== undefined && ratingCount > 0 && ratingAvg !== undefined'));
  assert.ok(tsx.includes('{showRating || instantDownload ? ('));
  assert.ok(tsx.includes('تحميل فوري بعد الدفع'));
  assert.equal((tsx.match(/<StarIcon \/>/g) ?? []).length, 1, 'نجمة واحدة');
});

test('غير متاح: لا سعر؛ الصورة وحدها تخفت (الشارات والقلب بتباينهما وفوق الرابط)', () => {
  assert.ok(tsx.includes('غير متاح حاليًا'));
  assert.ok(block(".card[data-unavailable] .frame").includes('opacity: 0.72'));
  assert.ok(!css.includes(".card[data-unavailable] .media {"), 'لا شفافيّة على صندوق الوسائط');
  assert.ok(!/data-unavailable\][^{]*\{[^}]*pointer-events/.test(css), 'الزرّ يُعطَّل حقًّا لا يُحجب عن المؤشّر');
});

test('سطر البيانات: لا فاصل «•» مولَّد (يُقرأ ويبدأ به السطر الملتفّ) — الأيقونة والفجوة تفصلان', () => {
  assert.ok(!css.includes("content: '•'"));
  assert.ok(block('.meta').includes('gap: 2px 10px'));
});

test('الشارات لا تبتلع النقر، والانشغال لا يُفرغ اسم الزرّ', () => {
  assert.ok(block('.badges').includes('pointer-events: none'));
  assert.ok(css.includes(".ctaRow .cta[aria-busy='true'] > * {"));
});

test('الأزرار: أضف للسلة يملأ العمود المرن، معاينة بجواره بارتفاعٍ واحد وهدف لمس 46', () => {
  assert.ok(block('.ctaRow').includes('grid-template-columns: minmax(0, 1fr) auto'));
  const cta = block('.cta');
  assert.ok(cta.includes('block-size: 40px') && cta.includes('inline-size: 100%'));
  assert.ok(block('.cta::before').includes('inset-block: -3px'));
  const preview = block('.preview');
  assert.ok(preview.includes('block-size: 40px'));
  assert.ok(block('.preview::before').includes('inset-block: -3px'));
  assert.ok(tsx.includes('export const productCardCartIcon') && tsx.includes('export const productCardEyeIcon'));
});

test('الداكن: الغلاف على لوحٍ أخفض ومُعتَّم، لا مقلوب', () => {
  assert.ok(css.includes(":global(:root[data-theme='dark']) .img"));
  assert.ok(/:global\(:root\[data-theme='dark'\]\) \.img \{[^}]*filter: brightness\(/.test(css));
  assert.ok(!/invert\(/.test(css));
});

test('compact: 188 عرضًا، عنوان 13، زرّ 36 بهدف لمس 46؛ والمرتبطة تأخذها تحت 767', () => {
  /* الرفّ يملك العرض (أعمدة ‎188px‎ فأكثر) والبطاقة تملأ عمودها. */
  const card = block(".card[data-variant='compact']");
  assert.ok(card.includes('inline-size: 100%'));
  assert.ok(block(".card[data-variant='compact'] .title").includes('font-size: 13px'));
  assert.ok(block(".card[data-variant='compact'] .cta,\n.card[data-variant='compact'] .preview").includes('block-size: 36px'));
  assert.ok(block(".card[data-variant='compact'] .cta::before,\n.card[data-variant='compact'] .preview::before").includes('inset-block: -5px'));
  const at = css.indexOf('@media (max-width: 767px) {');
  assert.ok(at >= 0, 'لا استعلام جوّال');
  const mq = css.slice(at);
  assert.ok(/\.card\[data-variant='related'\] \{[^}]*inline-size: 188px/.test(mq));
  for (const tint of ['teal', 'navy', 'sand', 'lilac']) {
    assert.ok(block(`.media[data-tint='${tint}']`).includes(`var(--tint-${tint})`));
  }
});

test('رموز فقط، وحركة مخفَّضة، وخصائص منطقيّة', () => {
  assert.ok(!/:\s*#[0-9a-fA-F]{3,8}\b/.test(css), 'لون خامّ');
  assert.ok(css.includes('@media (prefers-reduced-motion: reduce)'));
  assert.ok(!/\b(left|right|margin-left|margin-right|padding-left|padding-right)\s*:/.test(css), 'خاصّيّة فيزيائيّة');
});

test('البطاقة تملأ خليّتها بنفسها — لا تعتمد على قاعدة كلّ شبكة', () => {
  const base = block('.card');
  assert.ok(base.includes('inline-size: 100%') && base.includes('block-size: 100%'));
});

test('البطاقة الضيّقة تطوي نوع الملفّ و«قابل للتعديل» وتُبقي المادّة والصفّ والفصل والصيغة', () => {
  assert.ok(tsx.includes('data-key={item.key}'));
  const at = css.indexOf('@container (max-width: 230px) {');
  assert.ok(at >= 0);
  const cq = css.slice(at);
  assert.ok(/\.metaItem\[data-key='type'\],\n\s*\.metaItem\[data-key='editable'\] \{\n\s*display: none;/.test(cq));
  assert.ok(!/data-key='(subject|level|semester|format)'\]/.test(css), 'البنود الأربعة لا تُطوى');
});
