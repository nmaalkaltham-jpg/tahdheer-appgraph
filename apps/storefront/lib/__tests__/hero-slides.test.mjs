/**
 * شرائح الهيرو المحرَّرة — عقد ٢٠٢٦-٠٩-٢٥ §1 (`slider` + `slides`).
 *
 * القاعدة «طبّع ولا ترفض»: قيمةٌ خارج القائمة المغلقة تقع على الافتراض، والرقم
 * يُحصر في مداه، والرابط غير الآمن يُسقط، وشريحةٌ بلا عنوانٍ لا تُصيَّر. ومتجرٌ
 * لم يحفظ شرائح بعد (`slides: []`) يرى الشرائح الثلاث كما كانت حرفًا بحرف.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  heroSlider,
  heroSlides,
  fallbackSlides,
  safeHref,
  SLIDER_DEFAULTS,
  SLIDES_MAX,
} from '../../components/home/hero-config.ts';

test('slider: الغياب والقيم غير الصالحة تقع على افتراضات العقد', () => {
  assert.deepEqual(heroSlider(undefined), SLIDER_DEFAULTS);
  assert.deepEqual(heroSlider(null), SLIDER_DEFAULTS);
  assert.deepEqual(heroSlider([]), SLIDER_DEFAULTS);
  assert.deepEqual(
    heroSlider({ autoplay: 'yes', transition: 'zoom', height: 'huge', ambient: 'party', showDots: 1, intervalMs: 'fast' }),
    SLIDER_DEFAULTS,
  );
  assert.equal(SLIDER_DEFAULTS.intervalMs, 6000);
  assert.equal(SLIDER_DEFAULTS.ambient, 'drift');
});

test('slider: المدّة تُحصر في ‎3000–15000‎ وتُقرّب', () => {
  assert.equal(heroSlider({ intervalMs: 100 }).intervalMs, 3000);
  assert.equal(heroSlider({ intervalMs: 99_000 }).intervalMs, 15000);
  assert.equal(heroSlider({ intervalMs: 7000.4 }).intervalMs, 7000);
  assert.equal(heroSlider({ intervalMs: Number.NaN }).intervalMs, 6000);
  assert.equal(heroSlider({ intervalMs: Infinity }).intervalMs, 6000);
});

test('slider: القوائم المغلقة كلّها تُقرأ كما هي', () => {
  const s = heroSlider({
    autoplay: false, intervalMs: 9000, transition: 'fade', continuous: false, pauseOnHover: false,
    showArrows: false, showDots: false, showProgress: false, height: 'tall', ambient: 'kenburns',
  });
  assert.deepEqual(s, {
    autoplay: false, intervalMs: 9000, transition: 'fade', continuous: false, pauseOnHover: false,
    showArrows: false, showDots: false, showProgress: false, height: 'tall', ambient: 'kenburns',
  });
  for (const height of ['compact', 'standard', 'tall']) assert.equal(heroSlider({ height }).height, height);
  for (const ambient of ['none', 'drift', 'kenburns']) assert.equal(heroSlider({ ambient }).ambient, ambient);
});

test('slides: ليست مصفوفة ⇒ لا شرائح (فيعمل التراجع)', () => {
  for (const raw of [undefined, null, {}, 'x', 3]) assert.deepEqual(heroSlides(raw), []);
});

test('slides: بلا عنوانٍ لا تُصيَّر، والعنوان والوصف يُقصّان عند حدّيهما', () => {
  const out = heroSlides([
    { id: 'a', kind: 'brand' },
    { id: 'b', kind: 'brand', title: '   ' },
    { id: 'c', kind: 'brand', title: 'x'.repeat(300), subtitle: 'y'.repeat(500) },
  ]);
  assert.equal(out.length, 1);
  assert.equal(out[0].id, 'c');
  assert.equal(out[0].title.length, 120);
  assert.equal(out[0].subtitle.length, 240);
});

test('slides: ثماني شرائح بحدٍّ أقصى، ومعرّفاتٌ فريدة ومولَّدة عند الغياب', () => {
  const raw = Array.from({ length: 12 }, (_, i) => ({ id: i < 2 ? 'dup' : undefined, title: `t${i}` }));
  const out = heroSlides(raw);
  assert.equal(out.length, SLIDES_MAX);
  const ids = out.map((s) => s.id);
  assert.equal(new Set(ids).size, ids.length);
  assert.equal(out[2].id, 's_2');
});

test('slides: افتراضات كلّ نوع — موضوعٌ ومحاذاة كما تبدو الشرائح الافتراضيّة', () => {
  const [brand, offer, search, image, unknown] = heroSlides([
    { kind: 'brand', title: 'a' },
    { kind: 'offer', title: 'b' },
    { kind: 'search', title: 'c' },
    { kind: 'image', title: 'd' },
    { kind: 'video', title: 'e', theme: 'purple', align: 'left', overlay: 'max' },
  ]);
  assert.equal(brand.theme, 'navy-teal');
  assert.equal(offer.theme, 'sand');
  assert.equal(search.theme, 'mint');
  assert.equal(search.align, 'center');
  assert.equal(image.theme, 'ink');
  assert.equal(brand.showProductCard, true);
  assert.equal(unknown.kind, 'brand');
  assert.equal(unknown.theme, 'navy-teal');
  assert.equal(unknown.align, 'start');
  assert.equal(unknown.overlay, 'soft');
});

test('slides: الموضوعات الستّة والمحاذاتان والحجب الثلاثة مفرداتٌ معروفة', () => {
  for (const theme of ['navy-teal', 'navy', 'teal', 'sand', 'mint', 'ink']) {
    assert.equal(heroSlides([{ title: 't', theme }])[0].theme, theme);
  }
  for (const align of ['start', 'center']) assert.equal(heroSlides([{ title: 't', align }])[0].align, align);
  for (const overlay of ['none', 'soft', 'strong']) assert.equal(heroSlides([{ title: 't', overlay }])[0].overlay, overlay);
});

test('الروابط: مسارٌ داخليّ أو https فقط', () => {
  assert.equal(safeHref('/shop'), '/shop');
  assert.equal(safeHref('https://tahdheer.com/x'), 'https://tahdheer.com/x');
  for (const bad of ['//evil.com', 'javascript:alert(1)', 'http://127.0.0.1/x', 'shop', '', 7, null]) {
    assert.equal(safeHref(bad), null, String(bad));
  }
  const [s] = heroSlides([
    { title: 't', primary: { label: 'اذهب', href: 'javascript:alert(1)' }, secondary: { label: '', href: '/x' } },
  ]);
  assert.equal(s.primary, null);
  assert.equal(s.secondary, null);
});

test('الوسيط: رابطٌ فارغ ليس وسيطًا، والنصّ البديل الغائب زخرفيّ، ونقطة التركيز مغلقة', () => {
  const [a, b, c] = heroSlides([
    { title: 't', media: { url: '  ', alt: 'x' } },
    { title: 't', media: { url: '/api/v1/media/h.png', focal: 'left' } },
    { title: 't', media: { url: '/h.png', alt: ' طاولة ', focal: 'top' } },
  ]);
  assert.equal(a.media, null);
  assert.deepEqual(b.media, { url: '/api/v1/media/h.png', alt: '', focal: 'center' });
  assert.deepEqual(c.media, { url: '/h.png', alt: 'طاولة', focal: 'top' });
});

test('العرض: لا عدّاد بموعدٍ لا يُقرأ، والحقول تُطبَّع؛ ولا يُقرأ `offer` لغير نوعه', () => {
  const [o, notOffer] = heroSlides([
    { kind: 'offer', title: 't', offer: { badge: ' خصم ', endsAt: 'soon', couponCode: ' START25 ' } },
    { kind: 'brand', title: 't', offer: { badge: 'x' } },
  ]);
  assert.deepEqual(o.offer, { badge: 'خصم', endsAt: null, couponCode: 'START25' });
  assert.equal(notOffer.offer, null);
  const [ok] = heroSlides([{ kind: 'offer', title: 't', offer: { endsAt: '2030-01-01T00:00:00Z' } }]);
  assert.equal(ok.offer.endsAt, '2030-01-01T00:00:00Z');
});

test('البحث: رقائق صالحة فقط، وثلاثٌ منها على الجوّال', () => {
  const [s] = heroSlides([
    {
      kind: 'search',
      title: 't',
      search: {
        placeholder: '  ابحث ',
        chips: [
          { label: 'أ', href: '/a' },
          { label: 'ب', href: 'javascript:x' },
          { label: 'ج', href: '/c' },
          { label: 'د', href: '/d' },
          { label: 'هـ', href: '/e' },
        ],
      },
    },
  ]);
  assert.equal(s.search.placeholder, 'ابحث');
  assert.deepEqual(s.search.chips.map((c) => [c.label, c.mobile]), [['أ', true], ['ج', true], ['د', true], ['هـ', false]]);
  const [empty] = heroSlides([{ kind: 'search', title: 't' }]);
  assert.deepEqual(empty.search, { placeholder: null, chips: [] });
});

const ROUTES = { shop: '/shop', samples: '/samples', offers: '/offers' };

test('التراجع: الشرائح الثلاث من الحقول القديمة + promo + البحث — بالترتيب والنصّ نفسيهما', () => {
  const out = fallbackSlides({
    hero: { title: 'تعلّم أفضل', title_accent: 'أفضل.', subtitle: 'وصف', eyebrow: 'شارة' },
    promo: { campaign: { title: 'حملة', description: 'د', ends_at: '2030-01-01T00:00:00Z', url: '/bundles/x', badge: 'خصم' }, coupon: { code: 'C1' } },
    chips: [{ label: 'الابتدائي', href: '/stages/primary', mobile: true }],
    routes: ROUTES,
  });
  assert.deepEqual(out.map((s) => [s.kind, s.theme, s.align]), [
    ['brand', 'navy-teal', 'start'],
    ['offer', 'sand', 'start'],
    ['search', 'mint', 'center'],
  ]);
  const [brand, offer, search] = out;
  assert.deepEqual(brand.primary, { label: 'تصفّح الملفات', href: '/shop' });
  assert.deepEqual(brand.secondary, { label: 'عيّنة مجانية', href: '/samples' });
  assert.equal(brand.titleAccent, 'أفضل.');
  assert.equal(brand.showProductCard, true);
  /* مع الحملة: عدّادها وحده، لا القسيمة — كما كان. */
  assert.deepEqual(offer.offer, { badge: 'خصم', endsAt: '2030-01-01T00:00:00Z', couponCode: null });
  assert.deepEqual(offer.primary, { label: 'تصفّح العرض', href: '/bundles/x' });
  assert.equal(search.title, 'اكتب ما تحتاجه، ونعطيك الملف الجاهز.');
  assert.equal(search.search.chips.length, 1);
});

test('التراجع: قسيمةٌ بلا حملة ⇒ رمزها للنسخ و«تصفّح العروض»؛ ولا هيرو ولا عرض ⇒ البحث وحده', () => {
  const [offer] = fallbackSlides({ hero: null, promo: { coupon: { code: 'C1', description: 'خصم 15٪' } }, chips: [], routes: ROUTES });
  assert.deepEqual(offer.offer, { badge: 'قسيمة خصم', endsAt: null, couponCode: 'C1' });
  assert.equal(offer.title, 'خصم 15٪');
  assert.deepEqual(offer.primary, { label: 'تصفّح العروض', href: '/offers' });

  const only = fallbackSlides({ hero: null, promo: null, chips: [], routes: ROUTES });
  assert.deepEqual(only.map((s) => s.kind), ['search']);
});

test('التراجع: الدعوتان المنشورتان تغلبان الاحتياطيّتين', () => {
  const [brand] = fallbackSlides({
    hero: { title: 't', cta_label: 'تسوّق', cta_url: '/x', cta_secondary_label: 'كيف', cta_secondary_url: '/how' },
    promo: null,
    chips: [],
    routes: ROUTES,
  });
  assert.deepEqual(brand.primary, { label: 'تسوّق', href: '/x' });
  assert.deepEqual(brand.secondary, { label: 'كيف', href: '/how' });
});
