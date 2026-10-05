/**
 * مفرداتُ الهيرو المغلقة — قاعدةُ «ما لا يُعرف يُسقَط».
 *
 * ─── لماذا يُختبر هذا بعينه ───
 *
 * الخلفيّة تخزّن `variant` و`overlay` و`align` و`animation` **نصًّا** بلا
 * علمٍ بمفرداتها (`HomeContent::HERO_FIELDS`)، فالتحقّق كلّه هنا. وقيمةٌ
 * مجهولة — مطبعةٌ في اللوحة، أو مفردةٌ من إصدارٍ أقدم، أو حقلٌ حُرِّر
 * بنداءٍ مباشر — يجب أن تقع على الافتراض لا أن تُصيَّر هيرو بلا تصميم.
 *
 * وهذه هي القاعدة نفسها التي يمشي عليها `resolveSections` مع مُعرِّفات
 * الأقسام: المجهول يُسقَط ولا يُخترع له معنى.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { heroPresentation } from '../../components/home/hero-config.ts';

test('الغياب الكامل يقع على الافتراض — شرائح التصميم الجديد', () => {
  const p = heroPresentation({});

  assert.equal(p.variant, 'slider');
  assert.equal(p.align, 'start');
  assert.equal(p.overlay, 'soft');
  assert.equal(p.animation, 'rise');
  assert.equal(p.mediaUrl, null);
  assert.equal(p.mediaAlt, '');
});

test('المفردات المعروفة تُقرأ كما هي', () => {
  const p = heroPresentation({
    variant: 'luxe',
    align: 'center',
    overlay: 'strong',
    animation: 'parallax',
  });

  assert.equal(p.variant, 'luxe');
  assert.equal(p.align, 'center');
  assert.equal(p.overlay, 'strong');
  assert.equal(p.animation, 'parallax');
});

test('المجهول يُسقَط ويقع على الافتراض — لا هيرو بلا تصميم', () => {
  const p = heroPresentation({
    variant: 'cinematicc',
    align: 'left',
    overlay: 'medium',
    animation: 'zoom',
  });

  assert.equal(p.variant, 'slider');
  assert.equal(p.align, 'start');
  assert.equal(p.overlay, 'soft');
  assert.equal(p.animation, 'rise');
});

test('التصاميم الخمسة كلّها مفردات معروفة — لا خيار في اللوحة بلا مُصيِّر', () => {
  for (const variant of ['slider', 'institutional', 'bold', 'luxe', 'brand']) {
    assert.equal(heroPresentation({ variant }).variant, variant);
  }
});

test('القيم القديمة المحفوظة (سينمائيّ/اكتشاف/حكاية) تقع على الشرائح لا على عطل', () => {
  for (const variant of ['cinematic', 'discovery', 'story']) {
    assert.equal(heroPresentation({ variant }).variant, 'slider');
  }
});

test('رابط وسيطٍ فارغ أو فراغٌ محض ليس وسيطًا', () => {
  assert.equal(heroPresentation({ media_url: '' }).mediaUrl, null);
  assert.equal(heroPresentation({ media_url: '   ' }).mediaUrl, null);
});

test('رابط الوسيط يُشذَّب ويُقرأ', () => {
  assert.equal(heroPresentation({ media_url: '  /media/hero.webp ' }).mediaUrl, '/media/hero.webp');
});

test('نصٌّ بديلٌ غائب يعني صورةً زخرفيّة — لا وصفًا مُخترعًا', () => {
  /*
   * `alt=""` هو الإعلان الصحيح عن صورةٍ زخرفيّة. ووصفٌ مُخترع («صورة
   * الهيرو») أسوأ من لا وصف: يُقرأ على قارئ الشاشة ولا يحمل معلومة.
   */
  assert.equal(heroPresentation({ media_url: '/a.webp' }).mediaAlt, '');
  assert.equal(heroPresentation({ media_url: '/a.webp', media_alt: '  ' }).mediaAlt, '');
});

test('قيمةٌ ليست نصًّا لا تكسر شيئًا', () => {
  const p = heroPresentation({
    variant: 42,
    align: null,
    overlay: {},
    animation: [],
    media_url: 7,
    media_alt: false,
  });

  assert.equal(p.variant, 'slider');
  assert.equal(p.align, 'start');
  assert.equal(p.overlay, 'soft');
  assert.equal(p.animation, 'rise');
  assert.equal(p.mediaUrl, null);
  assert.equal(p.mediaAlt, '');
});
