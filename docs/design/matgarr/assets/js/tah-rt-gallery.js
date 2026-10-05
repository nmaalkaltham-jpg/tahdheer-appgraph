/**
 * Tahdheer — زمن تشغيل المعرض والكاروسيل والسلايدر.
 *
 * يفتح ٢١ اختيارًا في المكتبة بملف واحد: كاروسيل المنتجات والتصنيفات
 * والمجموعات والصفقات والمدوّنة، وسلايدر الصور، ومعرض الشبكة والمتراصّ،
 * ومعرض الفيديو، والكولاج.
 *
 * القرارات المعمارية:
 *  1) التمرير أصلًا لا محاكاةً — scroll-snap يعطي لمسًا طبيعيًا على الجوال
 *     وتمريرًا بالعجلة على المكتب، بلا مكتبة ولا حساب transform يدوي.
 *  2) لا حساب لـ scrollLeft بالإشارة — في RTL تختلف الإشارة بين المتصفّحات.
 *     نحسب هدف التمرير من offsetLeft للعنصر نفسه، فيعمل في الاتجاهين.
 *  3) لوحة المفاتيح منطقية لا فيزيائية: في RTL يكون السهم الأيسر «التالي».
 *  4) بلا تشغيل تلقائي افتراضيًا. وإن فُعِّل: يتوقّف عند التمرير أو التركيز
 *     أو خروج التبويب، ويتوقّف نهائيًا عند أول تفاعل من المستخدم.
 *
 * الاستخدام:
 *   <div data-tah-gallery data-autoplay="0" data-loop="1">
 *     <div data-tah-track>
 *       <div data-tah-slide>…</div>
 *     </div>
 *     <button data-tah-prev>…</button>
 *     <button data-tah-next>…</button>
 *     <div data-tah-dots></div>
 *   </div>
 */
(function () {
  'use strict';

  var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function behavior() {
    return REDUCED ? 'auto' : 'smooth';
  }

  function isRtl(el) {
    return window.getComputedStyle(el).direction === 'rtl';
  }

  /**
   * المسافة بين طرف البداية المنطقيّ للشريحة وطرف بداية المسار.
   *
   * هذا المقياس الوحيد لـ«أين نحن» في الملف كلّه: يستخدمه goTo و currentIndex
   * و edgeGaps معًا. وجود مقياسين — طرف ومركز، أو منطقيّ وفيزيائيّ — هو ما جعل
   * النقاط تخالف الأزرار والواجهة تدّعي حركة لم تحدث. صفر = محاذاة تامّة.
   *
   * @param {DOMRect} box مستطيل المسار.
   * @param {DOMRect} rect مستطيل الشريحة.
   * @param {boolean} rtl هل الاتجاه من اليمين؟
   */
  function startGap(box, rect, rtl) {
    return rtl ? box.right - rect.right : rect.left - box.left;
  }

  function slides(root) {
    var track = root.querySelector('[data-tah-track]');
    if (!track) return [];
    return Array.prototype.filter.call(track.children, function (child) {
      return child.hasAttribute('data-tah-slide');
    });
  }

  /**
   * الشريحة الحالية = أقرب شريحة لطرف البداية المنطقيّ للمسار.
   *
   * لا لمركزه: محاذاة الالتقاط scroll-snap-align: start، و goTo() يوازي الطرف
   * لا المركز. قياس المركز يعطي موقعًا متقدّمًا بشريحة أو أكثر، فتختلف النقاط
   * مع الأزرار عن موضع المستخدم. تعريف واحد لـ«أين نحن» يخدم الثلاثة.
   */
  function currentIndex(root) {
    var track = root.querySelector('[data-tah-track]');
    var items = slides(root);
    if (!track || !items.length) return 0;

    var box = track.getBoundingClientRect();
    var rtl = isRtl(root);
    var best = 0;
    var bestGap = Infinity;

    // عند الطرفين تُحكم حالة الطرف لا أقرب مسافة: في أقصى تمرير لا تستطيع أي
    // شريحة أن تحاذي طرف البداية، فتفوز شريحة وسطى للأبد وتخالف حالة الأزرار.
    var edges = edgeGaps(root);
    if (edges.end > -2) return items.length - 1;
    if (edges.start > -2) return 0;

    for (var i = 0; i < items.length; i++) {
      var rect = items[i].getBoundingClientRect();
      var gap = Math.abs(startGap(box, rect, rtl));
      if (gap < bestGap) {
        bestGap = gap;
        best = i;
      }
    }
    return best;
  }

  /** المسافة المتبقّية عند طرفَي المسار، بالمنطق لا بالفيزياء. */
  function edgeGaps(root) {
    var track = root.querySelector('[data-tah-track]');
    var items = slides(root);
    if (!track || !items.length) return { start: 0, end: 0 };

    var box = track.getBoundingClientRect();
    var first = items[0].getBoundingClientRect();
    var last = items[items.length - 1].getBoundingClientRect();

    var rtl = isRtl(root);
    return {
      start: startGap(box, first, rtl),
      end: rtl ? last.left - box.left : box.right - last.right,
    };
  }

  function goTo(root, index, focus) {
    var track = root.querySelector('[data-tah-track]');
    var items = slides(root);
    if (!track || !items.length) return;

    var loop = root.getAttribute('data-loop') === '1';
    var i = index;

    if (i < 0) i = loop ? items.length - 1 : 0;
    if (i > items.length - 1) i = loop ? 0 : items.length - 1;

    // نفس المقياس المنطقيّ؛ والإشارة تُقلب في RTL لأن scrollLeft يسير نحو السالب.
    var rtl = isRtl(root);
    var gap = startGap(track.getBoundingClientRect(), items[i].getBoundingClientRect(), rtl);

    if (Math.abs(gap) > 1) {
      track.scrollBy({ left: rtl ? -gap : gap, behavior: behavior() });
    }

    if (focus) {
      var focusable = items[i].querySelector('a,button,[tabindex]');
      if (focusable) focusable.focus({ preventScroll: true });
    }

    sync(root, i);
  }

  /** تحديث الأزرار والنقاط وحالة الشرائح لقارئات الشاشة. */
  function sync(root, forced) {
    var items = slides(root);
    if (!items.length) return;

    var track = root.querySelector('[data-tah-track]');
    var index = typeof forced === 'number' ? forced : currentIndex(root);
    var loop = root.getAttribute('data-loop') === '1';

    root.setAttribute('data-tah-index', String(index));

    var prev = root.querySelector('[data-tah-prev]');
    var next = root.querySelector('[data-tah-next]');
    var gaps = edgeGaps(root);
    var atStart = gaps.start > -2;
    var atEnd = gaps.end > -2;

    if (prev && !loop) prev.disabled = atStart;
    if (next && !loop) next.disabled = atEnd;

    var dots = root.querySelector('[data-tah-dots]');
    if (dots) {
      Array.prototype.forEach.call(dots.children, function (dot, i) {
        var on = i === index;
        dot.setAttribute('aria-selected', on ? 'true' : 'false');
        dot.setAttribute('tabindex', on ? '0' : '-1');
        dot.setAttribute('data-tah-on', on ? '1' : '0');
      });
    }

    items.forEach(function (slide, i) {
      // الشريحة خارج النطاق المرئي تبقى في الشجرة لكن تُعلَم لقارئ الشاشة.
      slide.setAttribute('aria-hidden', 'false');
      slide.setAttribute('data-tah-current', i === index ? '1' : '0');
    });
  }

  function buildDots(root) {
    var dots = root.querySelector('[data-tah-dots]');
    var items = slides(root);
    if (!dots || !items.length || dots.children.length) return;

    dots.setAttribute('role', 'tablist');
    dots.setAttribute('aria-label', root.getAttribute('data-dots-label') || 'الانتقال بين الشرائح');

    items.forEach(function (slide, i) {
      var dot = document.createElement('button');
      dot.type = 'button';
      dot.setAttribute('role', 'tab');
      dot.setAttribute('data-tah-dot', String(i));
      dot.setAttribute('aria-label', 'الشريحة ' + (i + 1) + ' من ' + items.length);
      dot.addEventListener('click', function () {
        stopAuto(root);
        goTo(root, i, false);
      });
      dots.appendChild(dot);
    });
  }

  function startAuto(root) {
    var ms = parseInt(root.getAttribute('data-autoplay') || '0', 10);
    if (!ms || ms < 1500 || REDUCED) return;

    stopAuto(root);
    root._tahTimer = window.setInterval(function () {
      if (document.hidden) return;
      goTo(root, currentIndex(root) + 1, false);
    }, ms);
  }

  function stopAuto(root) {
    if (root._tahTimer) {
      window.clearInterval(root._tahTimer);
      root._tahTimer = null;
    }
  }

  function init(root) {
    if (root.getAttribute('data-tah-ready') === '1') return;
    root.setAttribute('data-tah-ready', '1');

    var track = root.querySelector('[data-tah-track]');
    if (!track) return;

    var items = slides(root);
    if (!items.length) return;

    track.setAttribute('tabindex', '0');
    track.setAttribute('role', 'group');
    track.setAttribute('aria-roledescription', 'معرض');
    if (!track.hasAttribute('aria-label')) {
      track.setAttribute('aria-label', root.getAttribute('data-label') || 'معرض قابل للتمرير');
    }

    buildDots(root);

    var prev = root.querySelector('[data-tah-prev]');
    var next = root.querySelector('[data-tah-next]');

    if (prev) {
      if (!prev.hasAttribute('aria-label')) prev.setAttribute('aria-label', 'السابق');
      prev.addEventListener('click', function () {
        stopAuto(root);
        goTo(root, currentIndex(root) - 1, false);
      });
    }
    if (next) {
      if (!next.hasAttribute('aria-label')) next.setAttribute('aria-label', 'التالي');
      next.addEventListener('click', function () {
        stopAuto(root);
        goTo(root, currentIndex(root) + 1, false);
      });
    }

    // السهم المنطقي: في RTL الأيسر هو التالي.
    track.addEventListener('keydown', function (event) {
      var rtl = isRtl(root);
      var forward = rtl ? 'ArrowLeft' : 'ArrowRight';
      var back = rtl ? 'ArrowRight' : 'ArrowLeft';
      var handled = true;

      if (event.key === forward) goTo(root, currentIndex(root) + 1, true);
      else if (event.key === back) goTo(root, currentIndex(root) - 1, true);
      else if (event.key === 'Home') goTo(root, 0, true);
      else if (event.key === 'End') goTo(root, slides(root).length - 1, true);
      else handled = false;

      if (handled) {
        stopAuto(root);
        event.preventDefault();
      }
    });

    var raf = 0;
    track.addEventListener(
      'scroll',
      function () {
        if (raf) return;
        raf = window.requestAnimationFrame(function () {
          raf = 0;
          sync(root);
        });
      },
      { passive: true }
    );

    ['pointerdown', 'wheel', 'touchstart'].forEach(function (type) {
      track.addEventListener(type, function () { stopAuto(root); }, { passive: true, once: true });
    });

    root.addEventListener('mouseenter', function () { stopAuto(root); });
    root.addEventListener('focusin', function () { stopAuto(root); });

    window.addEventListener('resize', function () { sync(root); }, { passive: true });

    // أوّل sync يقع قبل استقرار التخطيط (التهيئة تسبق الرؤية بـ320px)، فيقرأ
    // مستطيلات منهارة ويُعطّل «التالي» — ومع تعطّله لا يقع تمرير يُعيد المزامنة.
    sync(root);
    window.requestAnimationFrame(function () { sync(root); });

    if ('ResizeObserver' in window) {
      new window.ResizeObserver(function () { sync(root); }).observe(track);
    }

    startAuto(root);
  }

  /** التهيئة عند الاقتراب فقط — معرض أسفل الصفحة لا يكلّف شيئًا قبل رؤيته. */
  function observe() {
    var roots = document.querySelectorAll('[data-tah-gallery]:not([data-tah-ready])');
    if (!roots.length) return;

    if (!('IntersectionObserver' in window)) {
      Array.prototype.forEach.call(roots, init);
      return;
    }

    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            init(entry.target);
            io.unobserve(entry.target);
          }
        });
      },
      { rootMargin: '320px' }
    );

    Array.prototype.forEach.call(roots, function (root) { io.observe(root); });
  }

  function boot() {
    observe();
    if (window.MutationObserver) {
      var timer = 0;
      new window.MutationObserver(function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(observe, 150);
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  window.TahGallery = { init: init, goTo: goTo, sync: sync };
})();
