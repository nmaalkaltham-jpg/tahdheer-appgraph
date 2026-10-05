#!/usr/bin/env node
/**
 * مسحٌ متّصل للعروض — لا عيّناتٌ متفرّقة.
 *
 * ─── لماذا وُجد ───
 *
 * فحصُ عروضٍ مختارة (‎320‎ · ‎768‎ · ‎1024‎ · ‎1440‎ …) يترك فجواتٍ بينها،
 * والعطل يسكن الفجوة. وقع هذا بالضبط: شريط تمريرٍ أفقيّ ‎56px‎ في **كلّ**
 * صفحة من صفحات المتجر بين ‎901‎ و‎957px‎ — ثمانية أدوارٍ من الفحص مرّت
 * نظيفةً لأنّ أحدًا لم يقس في ذلك النطاق. ومعه حقل بحثٍ بعرض ‎22px‎: ضابطٌ
 * مرئيّ لا يُكتب فيه شيء، ولا يُنتج شريط تمرير، فلا يصيح به شيء أبدًا.
 *
 * فالمسح هنا متّصل: صفحةٌ تُحمَّل مرّة، ثمّ يُغيَّر عرض النافذة خطوةً خطوة.
 * استعلامات الوسائط تُعاد حسبتها عند كلّ تغيير بلا إعادة تحميل، فيكلّف
 * ‎٢٨١‎ عرضًا تحميلةً واحدة.
 *
 * ─── وخمسة كواشف كاذبة أُزيلت قبل أن يُوثَق بهذا الملفّ ───
 *
 * كلّ واحدٍ منها أبلغ عن عطلٍ غير موجود، ولو صُدِّق لأنتج «إصلاحًا» يفسد
 * ما هو سليم:
 *
 *  ١· حجم الهدف دون ‎24px‎ بلا **استثناء التباعد** في WCAG 2.2 §2.5.8:
 *     روابط التذييل ‎74×14‎ لكنّ المسافة بين مركزيها ‎30px‎ — مطابِقة.
 *  ٢· الترويسة الملتصقة تعلو محتوى التدفّق: هذا عمل الطبقة العائمة لا عطل.
 *  ٣· الروابط السطريّة تتداخل مستطيلاتها مشروعًا حين يلتفّ السطر — و§2.5.8
 *     يستثني الهدف داخل جملة أصلًا.
 *  ٤· أبناء `<details>` المطويّ يحتفظون بمستطيلاتٍ كاملة وهم غير مرسومين
 *     ولا تفاعليّين. `checkVisibility` هو ما يفرّق المُخطَّط من المرسوم.
 *  ٥· ما يخرج من صندوق القاصّ وبينهما حاويةُ تمريرٍ عاملة ليس ضائعًا:
 *     `scrollLeft` يُدخله. ‎٩٦‎ بلاغًا في ثلاثة مسوحٍ كانت رقاقة تصنيفٍ
 *     واحدة في `<ul>` عرضه `auto`. التفصيل عند الكاشف نفسه.
 *
 * ─── ولماذا ليس مع الحرّاس ───
 *
 * الفحص الساكن يعمل في ثوانٍ بلا شبكة. وهذا يحتاج خادمًا يعمل
 * ومتصفّحًا، فيُنادى وحده:
 *
 *   npm run dev                       # أو أيّ خادمٍ على 3000
 *   npm run sweep
 *   npm run sweep -- --step=32 --routes=/,/shop
 *
 * ─── الحركة المصمَّمة ───
 *
 * حاويةٌ تعلن `role="region" aria-roledescription="carousel"` (أو
 * `aria-roledescription="marquee"`) ومعها زرُّ إيقافٍ بالغ (`aria-pressed`
 * أو اسمٌ يذكر الإيقاف) تُعفى من بلاغات القصّ، ويُعفى تداخلُ أهدافها
 * الداخليّة (سهما التنقّل فوق الشريحة). الشرائح غير الحاضرة `inert`
 * والنُّسخ `aria-hidden="true"`. مثبّتاتها: marquee-ok · marquee-no-pause ·
 * carousel-arrows-ok في scripts/__fixtures__/sweep.
 */

import { chromium } from 'playwright';
import { resolveRoutes, readArg, gotoChecked, isTemporary } from './lib/storefront-routes.mjs';
import { describeLoadFailure, sweepVerdict } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const FROM = Number(readArg('from', 320));
const TO = Number(readArg('to', 2560));
const STEP = Number(readArg('step', 8));
const HEIGHT = Number(readArg('height', 900));

/*
 * تباعد النصّ — WCAG 2.2 AA §1.4.12.
 *
 * المعيار يقول: لا يضيع محتوًى ولا وظيفةٌ حين يضبط القارئ ارتفاع السطر
 * ‎1.5×‎ وتباعد الحروف ‎0.12em‎ والكلمات ‎0.16em‎ وفواصل الفقرات ‎2em‎. وهي
 * إعداداتٌ حقيقيّة في إضافاتٍ وقارئاتٍ يستعملها من يقرأ بعُسر.
 *
 * ولم تُقس هنا قطّ. والمتجر مليءٌ بارتفاعاتٍ ثابتة (‎min-block-size: 44px‎
 * وأشرطةٍ بارتفاعٍ مكتوب) — وهي بالضبط ما يقصّ حين يرتفع السطر.
 *
 *   npm run sweep -- --text-spacing
 */
const TEXT_SPACING = process.argv.includes('--text-spacing');

/*
 * محتوًى متطرّف — كلمةٌ واحدة لا فاصل فيها.
 *
 * كلّ ما قيس حتّى الآن قيس على بيانات البذرة: عناوينُ معقولة الطول وفيها
 * مسافات، فينكسر السطر حيث يشاء المتصفّح. والبيانات الحقيقيّة ليست كذلك —
 * رمز منتجٍ طويل، أو اسمٌ أُدخل بلا مسافة، أو رابطٌ لُصق في حقل.
 *
 * والكلمة التي لا تنكسر تكشف ما لا يكشفه غيرها: عنصر شبكةٍ بلا
 * ‎min-inline-size: 0‎ يأخذ عرض محتواه الأدنى فيدفع الشبكة كلّها، وصندوقٌ
 * بلا ‎overflow-wrap‎ يمدّ سطره خارج حدّه. وكلاهما فيضٌ أفقيّ في كلّ صفحة.
 *
 *   npm run sweep -- --extreme
 */
const EXTREME = process.argv.includes('--extreme');
/*
 * عدديّةُ القوائم — صفرٌ وواحدٌ وستّون.
 *
 * كلّ قياسٍ حتّى الآن وقع على بيانات البذرة: ثمانية منتجات في كلّ شبكة،
 * وستّ رقاقاتٍ في كلّ مرشّح. والعدد في المتجر الحيّ ليس ثابتًا — تصنيفٌ
 * جديد فيه منتجٌ واحد، وتصنيفٌ ناضج فيه مئة.
 *
 * والطرفان يكشفان عطلين مختلفين: الواحد يكشف شبكةً تمدّ بطاقتها على عرض
 * الشاشة كلّه (محتوًى ضئيل في حاوية هائلة)، والستّون يكشف صفًّا لا يلتفّ
 * وطبقةً ملتصقة تصطدم بصفحةٍ طويلة.
 *
 *   npm run sweep -- --cardinality=1
 *   npm run sweep -- --cardinality=60
 */
const CARDINALITY = Number(readArg('cardinality', 0));
const CARDINALITY_JS = `
  ((target) => {
    const scope = document.querySelector('main');
    if (!scope) return;
    /* قائمةٌ حقيقيّة: ثلاثةُ أبناءٍ فأكثر يتشاركون الصنف نفسه. الصفُّ
       المختلطُ (عنوانٌ ثمّ نصٌّ ثمّ زرّ) ليس قائمةً ولا يُستنسخ. */
    for (const parent of [...scope.querySelectorAll('ul,ol,div')]) {
      const kids = [...parent.children];
      if (kids.length < 3) continue;
      /*
       * التوقيع وسمٌ وصنف — والصنف الفارغ مقبول.
       *
       * أوّل صياغةٍ اشترطت صنفًا غير فارغ، فتخطّت كلّ قائمةٍ أبناؤها
       * ‎<li>‎ بلا صنف (الأنماط على الأب). و«شريط المراحل» منها — وهو
       * بالضبط الضابط الذي يُفترض أن يقيسه هذا الوضع.
       */
      const cls = typeof kids[0].className === 'string' ? kids[0].className : '';
      const tag = kids[0].tagName;
      if (!kids.every((k) => (typeof k.className === 'string' ? k.className : '') === cls && k.tagName === tag)) continue;

      if (target < kids.length) {
        for (const extra of kids.slice(target)) extra.remove();
      } else {
        const seed = kids[0];
        for (let i = kids.length; i < target; i++) parent.append(seed.cloneNode(true));
      }
    }
  })(CARDINALITY_TARGET);
`;

const EXTREME_CSS = '';
/** رمزٌ واقعيّ: أربعون محرفًا لاتينيًّا بلا فاصل، ثمّ عربيّة بلا فاصل. */
const EXTREME_JS = `
  (() => {
    const LATIN = 'DEV-COMMERCE-000000000000000000000000002';
    const ARABIC = 'تحضيراتمادةالرياضياتللصفالثانيالثانويالفصلالأول';
    const seen = new Set();
    /*
     * داخل ‎<main>‎ حصرًا، وخارج أيّ ملاحة.
     *
     * أوّل صياغةٍ بدّلت نصّ كلّ رابطٍ وزرّ في الصفحة — بما فيها الترويسة
     * والتذييل — فأبلغت عن ‎119‎ خرقًا. وهي ليست أعطالًا: نصوص الملاحة
     * مكتوبةٌ في الشيفرة لا تأتي من بيانات، ولا يُنشئ أحدٌ عنصر قائمةٍ
     * بأربعين محرفًا بلا فاصل. والضغط يجب أن يقع حيث تدخل البيانات فعلًا.
     */
    const scope = document.querySelector('main');
    if (!scope) return;
    for (const el of scope.querySelectorAll('h1,h2,h3,h4,a[href],li,td,th,figcaption')) {
      if (seen.has(el)) continue;
      if (el.closest('nav,header,footer')) continue;
      /* «white-space: nowrap» إعلانٌ من كاتب الواجهة: هذا النصّ قصيرٌ
         ومكتوبٌ عندي ولا يأتي من بيانات. فحقنُه فيه ضغطٌ على ثابتٍ في
         الشيفرة لا على مدخلٍ حقيقيّ — وهو ما أبلغ عن رقاقة «عرض الكلّ». */
      if (getComputedStyle(el).whiteSpace === 'nowrap') continue;
      // آخرُ عقدةٍ نصّيّة فقط — لا تُمسح بنيةُ العنصر ولا أيقوناته.
      const text = [...el.childNodes].find((n) => n.nodeType === 3 && n.textContent.trim().length > 3);
      if (!text) continue;
      seen.add(el);
      text.textContent = text.textContent.trim().length % 2 === 0 ? LATIN : ARABIC;
    }
  })();
`;
const SPACING_CSS = `
  *, *::before, *::after {
    line-height: 1.5 !important;
    letter-spacing: 0.12em !important;
    word-spacing: 0.16em !important;
  }
  p { margin-block-end: 2em !important; }
`;

const ROUTES = await resolveRoutes();

/**
 * الكاشف — يعمل داخل الصفحة عند كلّ عرض.
 *
 * يُعيد أوّل خرقٍ يجده أو `null`. والعتبات كلّها من معيارٍ منشور لا من ذوق:
 * ‎24px‎ حدُّ حجم الهدف الأدنى في WCAG 2.2 AA، و‎120px‎ حدٌّ عمليّ لحقل
 * إدخالٍ يُكتب فيه نصّ عربيّ.
 */
function probe({ minTarget, minField }) {
  const label = (el) =>
    `${el.tagName.toLowerCase()}.${String(el.className).split(' ')[0].split('__').pop() || '—'}`;

  /*
   * حركةٌ مصمَّمة — استثناءٌ بشرطٍ يُقاس لا يُفترض.
   *
   * الدوّار (WAI-ARIA carousel) وشريط المرور يقصّان محتواهما بالتصميم:
   * الشرائح غير الحاضرة خارج الصندوق، والنُّسخ المكرَّرة تُخفى عن قارئ
   * الشاشة. فيُعفى ما داخل حاويةٍ تعلن نفسها —
   *   `role="region" aria-roledescription="carousel"` أو
   *   `aria-roledescription="marquee"`
   * — **بشرط** زرِّ إيقافٍ بالغٍ: `button` داخلها (أو يشير إليها بـ
   * `aria-controls`) مرئيٌّ غير معطَّل وليس تحت `[inert]`، يحمل
   * `aria-pressed` أو اسمًا يذكر الإيقاف/التشغيل. بلا زرٍّ لا استثناء:
   * حركةٌ لا تُوقَف ليست تصميمًا بل خرقٌ لـ WCAG §2.2.2.
   */
  const motionHost = (el) => {
    const host = el.closest('[role="region"][aria-roledescription="carousel"],[aria-roledescription="marquee"]');
    if (!host) return null;
    const pause = [...document.querySelectorAll('button,[role="button"]')].some((b) =>
      (host.contains(b) || (host.id && b.getAttribute('aria-controls') === host.id))
      && !b.closest('[inert]') && !b.disabled && b.getAttribute('aria-disabled') !== 'true'
      && (b.checkVisibility ? b.checkVisibility() : b.offsetParent !== null)
      && (b.hasAttribute('aria-pressed') || /pause|play|إيقاف|تشغيل|توق/i.test(`${b.getAttribute('aria-label') ?? ''} ${b.textContent ?? ''}`)));
    return pause ? host : null;
  };

  const overflow = document.documentElement.scrollWidth - window.innerWidth;
  if (overflow > 1) return { kind: 'overflow', detail: `${overflow}px` };

  /*
   * قصٌّ رأسيّ أو أفقيّ: محتوًى لا يُرى ولا يُمرَّر إليه.
   *
   * `overflow: hidden` مع محتوًى أطول من الصندوق يعني نصًّا مقطوعًا بلا
   * سبيلٍ إليه — وهو ما يقع حين يرتفع السطر فوق ارتفاعٍ مكتوب. والحالات
   * الثلاث المستثناة مقصودةٌ بذاتها لا أعطال:
   *
   *  · النصّ المخفيّ لقارئ الشاشة (صندوق ‎1px‎ بقصٍّ متعمَّد).
   *  · `-webkit-line-clamp` — قطعٌ معلن بعدد أسطر.
   *  · `nowrap` مع `ellipsis` — قطعٌ معلن بثلاث نقاط.
   */
  for (const el of document.querySelectorAll('*')) {
    const cs = getComputedStyle(el);
    const hiddenY = cs.overflowY === 'hidden' || cs.overflowY === 'clip';
    const hiddenX = cs.overflowX === 'hidden' || cs.overflowX === 'clip';
    if (!hiddenY && !hiddenX) continue;
    if (el.clientHeight <= 4 || el.clientWidth <= 4) continue;
    if (motionHost(el)) continue;
    if (cs.webkitLineClamp !== 'none' && cs.webkitLineClamp !== '') continue;
    if (cs.whiteSpace === 'nowrap' && cs.textOverflow === 'ellipsis') continue;
    if ((el.textContent ?? '').trim().length === 0) continue;

    const cutY = hiddenY ? el.scrollHeight - el.clientHeight : 0;
    const cutX = hiddenX ? el.scrollWidth - el.clientWidth : 0;
    if (cutY <= 2 && cutX <= 2) continue;

    /*
     * والصندوق الذي يقصّ ليس عطلًا بذاته — العطل ما يُقصّ.
     *
     * ثلاثة من أربعة مواضع وجدها هذا الكاشف أوّل مرّة كانت `span` زخرفيّة
     * فارغة (هالةٌ متدرّجة تنزّ خارج الصندوق عمدًا، والقصّ هو الغرض).
     * فيُفتَّش عمّا يتجاوز الحدّ: إن كان كلّه بلا نصٍّ وبلا تفاعل فالقصّ
     * مقصود، وإن كان فيه نصٌّ أو هدفٌ منقور فهو محتوًى ضاع.
     */
    const box = el.getBoundingClientRect();
    let lost = null;
    for (const c of el.querySelectorAll('*')) {
      const b = c.getBoundingClientRect();
      if (b.width < 1 || b.height < 1) continue;
      const outX = Math.round(Math.max(box.left - b.left, b.right - box.right));
      const outY = Math.round(b.bottom - box.bottom);
      if (outX <= 2 && outY <= 2) continue;

      /*
       * وما يُمرَّر إليه ليس ضائعًا — وإن خرج من صندوق القاصّ.
       *
       * رقاقات التصنيف في صفحة المنتج تسكن `<ul>` عرضه `overflow-x: auto`
       * داخل `.hero` الذي يقصّ أفقيًّا عمدًا (السبب موثَّق في
       * `apps/storefront/app/products/[slug]/page.module.css:53-67`).
       * فالرقاقة الأخيرة تقع خارج صندوق الهيرو، و`scrollLeft` يُدخلها
       * كاملة. أبلغ هذا الكاشف عنها **٩٦** مرّة في ثلاثة مسوح (١٩ + ٢١ + ٥٦،
       * مقيسة على ‎/products/a‎)، وكلّها بلاغٌ واحد مكرّر.
       *
       * والمحكّ ترتيبُ الأسلاف: مُمرِّرٌ **أقرب** من القاصّ يعني أنّ
       * الخروج وقع داخل حاويةِ تمريرٍ تسبق القاصّ، فالمحتوى مبلوغٌ
       * بالتمرير. ويُقاس التمرير ولا يُفترض — `auto` وحدها إعلانٌ لا
       * دليل، وصندوقٌ محتواه بقدره لا يتمرّر مهما أُعلن.
       *
       * والمحور يُطابَق: مُمرِّرٌ أفقيّ لا يُنقذ ابنًا خرج من الأسفل.
       *
       * وأمّا المُمرِّر الذي هو نفسه خارج صندوق القاصّ — فتمريره يُدخل
       * المحتوى إلى منطقةٍ مقصوصة — فلا يحتاج استثناءً هنا: هذه الحلقة
       * تمرّ عليه قبل أبنائه (ترتيب المستند) وتبلّغ عنه هو.
       */
      const reachable = (axis) => {
        for (let p = c.parentElement; p && p !== el; p = p.parentElement) {
          const ps = getComputedStyle(p);
          const mode = axis === 'x' ? ps.overflowX : ps.overflowY;
          if (mode !== 'auto' && mode !== 'scroll') continue;
          const room = axis === 'x' ? p.scrollWidth - p.clientWidth : p.scrollHeight - p.clientHeight;
          if (room <= 1) continue;

          /*
           * وجودُ فسحةٍ ليس بلوغًا — والجهة هي الفرق.
           *
           * المستند `dir="rtl"`، وفسحة المُمرِّر تقع في جهةٍ واحدة. فابنٌ خرج
           * من الجهة المقابلة لا يعود مهما مُرِّر: لا إزاحة تُدخله. وقياسُ
           * «فسحة > 0» وحده يُسكت هذا الضياع الحقيقيّ — أي يستبدل إيجابيّةً
           * كاذبة بسلبيّةٍ كاذبة، وهي أسوأ: الأولى تُزعج، والثانية تُخفي.
           *
           * ولا تُحسب الجهة من إشارة `scrollLeft`: المحرّكات تختلف في اصطلاح
           * RTL (مدًى سالب في بعضها، موجب في غيرها)، وحسابٌ كهذا يصحّ في
           * متصفّحٍ ويكذب في آخر. فتُجرَّب الإزاحات ويُقاس الناتج ثمّ تُعاد
           * الحالة كما كانت — الملفّ يشترط على نفسه هذا أصلًا: «ويُقاس
           * التمرير ولا يُفترض».
           */
          const key = axis === 'x' ? 'scrollLeft' : 'scrollTop';
          const before = p[key];
          /* الطرفان، ثمّ الإزاحتان اللتان تُحاذيان الابن بحافّتَي القاصّ. */
          const shiftLo = axis === 'x' ? box.left - b.left : box.top - b.top;
          const shiftHi = axis === 'x' ? box.right - b.right : box.bottom - b.bottom;
          const tries = [before - room, before + room, before - shiftLo, before - shiftHi];
          let ok = false;
          for (const target of tries) {
            p[key] = target;
            const nb = c.getBoundingClientRect();
            const inside = axis === 'x'
              ? nb.left >= box.left - 2 && nb.right <= box.right + 2
              : nb.top >= box.top - 2 && nb.bottom <= box.bottom + 2;
            if (inside) { ok = true; break; }
          }
          p[key] = before;
          if (ok) return true;
        }
        return false;
      };
      if ((outX <= 2 || reachable('x')) && (outY <= 2 || reachable('y'))) continue;

      /*
       * المحتوى يُحسب بنصّه **المباشر** لا بنصّ ذرّيّته: الحلقة تزور كلّ ذرّيّةٍ
       * على حدة، فنصٌّ مقصوصٌ فعلًا يُبلَّغ عنه عنصرُه نفسه. و`textContent`
       * كان يجعل الغلافَ «حاملًا» لنصٍّ يقع داخل الصندوق — فأُبلغ عن مسار
       * الهيرو الدوّار كلّه لأنّ شريحته الحاضرة فيه.
       *
       * وما تحت `[inert]` ليس محتوًى ضائعًا: خارجٌ عن التركيز وعن شجرة
       * الإتاحة عمدًا — شرائح الدوّار غير الحاضرة. الحاضرةُ ليست inert
       * فتبقى مقيسة كغيرها.
       */
      if (c.closest('[inert]')) continue;
      const ownText = [...c.childNodes].some(
        (n) => n.nodeType === Node.TEXT_NODE && (n.textContent ?? '').trim().length > 0,
      );
      const carries =
        ownText ||
        c.matches('a,button,input,select,textarea,summary,[tabindex]:not([tabindex="-1"])');
      if (carries) {
        lost = `${label(c)} ${outY > 2 ? `↕${outY}px` : `↔${outX}px`}`;
        break;
      }
    }
    if (lost === null) continue;

    return { kind: 'clipped', detail: `${label(el)} يقصّ ${lost}` };
  }

  for (const input of document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio])')) {
    const b = input.getBoundingClientRect();
    if (b.width > 0 && b.width < minField) {
      return { kind: 'crushed-field', detail: `${label(input)} ${Math.round(b.width)}px` };
    }
  }

  const floating = (el) => {
    for (let e = el; e && e !== document.body; e = e.parentElement) {
      const p = getComputedStyle(e).position;
      if (p === 'fixed' || p === 'sticky') return true;
    }
    return false;
  };

  const targets = [...document.querySelectorAll('a,button,input,select,summary,[role=button],[role=link]')]
    .filter((el) => (el.checkVisibility ? el.checkVisibility({ checkVisibilityCSS: true, contentVisibilityAuto: true }) : el.offsetParent !== null))
    .map((el) => ({ el, b: el.getBoundingClientRect(), cs: getComputedStyle(el) }))
    .filter((t) => t.b.width > 0 && t.b.height > 0 && t.cs.display !== 'inline'
      && t.el.getClientRects().length === 1 && !floating(t.el) && !t.el.closest('[inert]'))
    .map((t) => ({ ...t, cx: t.b.left + t.b.width / 2, cy: t.b.top + t.b.height / 2 }));

  /*
   * «الرابط الممدود»: رابطٌ بلا نصّ، مسمّى (aria-label)، مطلقُ الموضع يغطّي
   * حاويته كلّها، وفوقه زرٌّ مرفوع — نمطُ البطاقة التي تُنقر كلُّها وفيها
   * زرّ «أضف». التقاطعُ هنا تصميمٌ لا تصادم: الزرّ يملك مساحته والرابط ما
   * بقي. يُعفى الزوج بشرطين يُقاسان لا يُفترضان: الرابط يطابق حاويته، والآخرُ
   * هو العنصرُ الأعلى عند مركزه بعد إدخاله في النافذة. رابطٌ بلا اسمٍ أو
   * زرٌّ تحت الرابط يبقيان خرقًا.
   */
  const overlay = (t) => {
    if (t.el.tagName !== 'A' || t.cs.position !== 'absolute') return false;
    if ((t.el.textContent ?? '').trim() !== '') return false;
    const name = (t.el.getAttribute('aria-label') ?? '').trim() || t.el.getAttribute('aria-labelledby');
    if (!name) return false;
    const host = t.el.offsetParent?.getBoundingClientRect();
    const own = t.el.getBoundingClientRect();
    return !!host && ['left', 'right', 'top', 'bottom'].every((k) => Math.abs(host[k] - own[k]) <= 2);
  };
  const onTop = (t) => {
    // كلُّ حاويةٍ يمرّرها `scrollIntoView` تُعاد إلى موضعها — لا النافذة وحدها،
    // وإلّا انزاح صفٌّ أفقيّ فخالفت مستطيلاتُ القياس اللاحقة ما قيس قبلها.
    const scrolled = [];
    for (let e = t.el.parentElement; e; e = e.parentElement) scrolled.push([e, e.scrollLeft, e.scrollTop]);
    const x = window.scrollX;
    const y = window.scrollY;
    t.el.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' });
    const r = t.el.getBoundingClientRect();
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    for (const [e, left, top] of scrolled) {
      e.scrollLeft = left;
      e.scrollTop = top;
    }
    window.scrollTo({ left: x, top: y, behavior: 'instant' });
    return hit === t.el || t.el.contains(hit);
  };
  const stretchedPair = (a, c) => (overlay(a) && onTop(c)) || (overlay(c) && onTop(a));

  for (let i = 0; i < targets.length; i += 1) {
    const a = targets[i];

    for (let j = i + 1; j < targets.length; j += 1) {
      const c = targets[j];
      if (a.el.contains(c.el) || c.el.contains(a.el)) continue;
      /* سهما الدوّار فوق محتوى شريحته: تصميمٌ لا تصادم — داخل الحاوية نفسها فقط. */
      const host = motionHost(a.el);
      if (host && host === motionHost(c.el)) continue;
      const ix = Math.min(a.b.right, c.b.right) - Math.max(a.b.left, c.b.left);
      const iy = Math.min(a.b.bottom, c.b.bottom) - Math.max(a.b.top, c.b.top);
      if (ix > 2 && iy > 2 && !stretchedPair(a, c)) {
        return { kind: 'overlap', detail: `${label(a.el)} ∩ ${label(c.el)}` };
      }
    }

    if (a.b.width >= minTarget && a.b.height >= minTarget) continue;

    // §2.5.8 — استثناء التباعد: دائرةٌ قطرها ‎24px‎ لا تلامس دائرة هدفٍ آخر.
    const crowded = targets.some((c) => c !== a
      && !a.el.contains(c.el) && !c.el.contains(a.el)
      && Math.hypot(c.cx - a.cx, c.cy - a.cy) < minTarget);

    if (crowded) {
      return { kind: 'target-size', detail: `${label(a.el)} ${Math.round(a.b.width)}×${Math.round(a.b.height)}` };
    }
  }

  return null;
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: FROM, height: HEIGHT } });

let failures = 0;
let widths = 0;
let infraFailures = 0;

for (const route of ROUTES) {
  try {
    await gotoChecked(page, BASE, route, { waitUntil: 'networkidle', timeout: 45_000 });
  } catch (error) {
    if (isTemporary(error)) {
      /* لم يُقس ⇒ ليس نجاحًا: عطلُ بنية يُعدّ ويُسقط الفحص بـ2 (lib/sweep-verdict). */
      infraFailures += 1;
      console.log(`  ⛔ ${route.padEnd(34)} ${describeLoadFailure(error)} — لم يُقس.`);
      continue;
    }
    console.log(`  × ${route.padEnd(34)} تعذّر التحميل: ${String(error?.message ?? error).split('\n')[0]}`);
    failures += 1;
    continue;
  }

  /* يُحقن بعد كلّ انتقال: `addStyleTag` لا ينجو من تحميلة جديدة. */
  if (TEXT_SPACING) await page.addStyleTag({ content: SPACING_CSS });
  if (EXTREME) await page.evaluate(EXTREME_JS);
  if (CARDINALITY > 0) {
    await page.evaluate(CARDINALITY_JS.replace('CARDINALITY_TARGET', String(CARDINALITY)));
  }

  /* النطاقات تُدمج: عطلٌ ممتدٌّ على مئة عرض سطرٌ واحد لا مئة. */
  const bands = [];
  for (let w = FROM; w <= TO; w += STEP) {
    await page.setViewportSize({ width: w, height: HEIGHT });
    const hit = await page.evaluate(probe, { minTarget: 24, minField: 120 });
    widths += 1;

    const last = bands[bands.length - 1];
    if (hit) {
      if (last && last.kind === hit.kind && last.detail === hit.detail && last.to === w - STEP) last.to = w;
      else bands.push({ ...hit, from: w, to: w });
    }
  }

  if (bands.length === 0) {
    console.log(`  ✓ ${route.padEnd(34)} ${FROM}→${TO}px`);
  } else {
    failures += bands.length;
    console.log(`  × ${route}`);
    for (const b of bands) console.log(`      ${b.kind} · ${b.detail} · ${b.from}–${b.to}px`);
  }
}

await browser.close();

console.log(
  `\n  ${ROUTES.length} مسارًا · ${widths} قياسًا${TEXT_SPACING ? ' · بتباعد §1.4.12' : ''}${EXTREME ? ' · بمحتوًى متطرّف' : ''}${CARDINALITY ? ` · بعدديّة ${CARDINALITY}` : ''} · ${failures ? `${failures} خرقًا` : 'بلا خرق'}\n`,
);
const verdict = sweepVerdict({ measured: widths, breaches: failures, infraFailures });
if (verdict.code !== 0) console.log(`  × ${verdict.reason}\n`);
process.exit(verdict.code);
