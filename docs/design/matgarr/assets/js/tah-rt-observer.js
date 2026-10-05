/**
 * Tahdheer — زمن تشغيل مراقب التقاطع.
 *
 * يفتح ٩ اختيارات: الكشف عند التمرير لكل العناصر، والتنقّل الملتصق مع تفعيل
 * القسم الحالي، وتنقّل الأقسام والمراسي، وفهرس الصفحة، ومؤشّر التقدّم.
 *
 * القرارات:
 *  1) مراقب واحد لكل غرض لا مراقب لكل عنصر — عشرات المراقبات تُثقل الصفحة.
 *  2) الكشف يقرأ متغيّرات سلّم الحركة (--tah-mo-*) فلا يخترع أرقامه، ويكتب
 *     --tah-mo-i للتتالي على الأبناء.
 *  3) العنصر يُلغى تتبّعه بعد أول كشف (once افتراضيًا) — لا عمل متكرّر.
 *  4) prefers-reduced-motion يُظهر كل شيء فورًا ولا يسجّل أي مراقب كشف.
 *     المحتوى المخفيّ في انتظار حركة معطّلة هو أسوأ عطل وصولية ممكن.
 *
 * الاستخدام:
 *   <div data-tah-reveal>…</div>
 *   <div data-tah-reveal-group>…أبناء بتتالٍ…</div>
 *   <nav data-tah-sectionnav data-watch="[data-tah-section]">…</nav>
 *   <div data-tah-progress></div>
 */
(function () {
  'use strict';

  var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var HAS_IO = 'IntersectionObserver' in window;

  /* ─────────── الكشف عند التمرير ─────────── */

  var revealObserver = null;

  function showNow(el) {
    el.setAttribute('data-tah-shown', '1');
    el.style.removeProperty('opacity');
  }

  function revealGroup(el) {
    var children = Array.prototype.filter.call(el.children, function (child) {
      return child.nodeType === 1;
    });
    children.forEach(function (child, i) {
      child.style.setProperty('--tah-mo-i', String(i));
    });
    el.setAttribute('data-tah-mo-stagger', '');
  }

  function initReveal() {
    if (REDUCED) {
      document.querySelectorAll('[data-tah-reveal],[data-tah-reveal-group]').forEach(showNow);
      return;
    }

    if (!HAS_IO) {
      document.querySelectorAll('[data-tah-reveal],[data-tah-reveal-group]').forEach(showNow);
      return;
    }

    if (!revealObserver) {
      revealObserver = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;

            var el = entry.target;
            if (el.hasAttribute('data-tah-reveal-group')) revealGroup(el);
            else el.setAttribute('data-tah-mo', el.getAttribute('data-tah-reveal') || '');

            showNow(el);

            if (el.getAttribute('data-repeat') !== '1') revealObserver.unobserve(el);
          });
        },
        { rootMargin: '0px 0px -12% 0px', threshold: 0.08 }
      );
    }

    document
      .querySelectorAll('[data-tah-reveal]:not([data-tah-watched]),[data-tah-reveal-group]:not([data-tah-watched])')
      .forEach(function (el) {
        el.setAttribute('data-tah-watched', '1');
        el.style.opacity = '0';
        revealObserver.observe(el);
      });
  }

  /* ─────────── تنقّل الأقسام ─────────── */

  // حارس لكل سلوك على حِدة: الشريط الواحد يحمل غالبًا التنقّل واللزوجة معًا،
  // وحارس مشترك واحد يجعل السلوك الثاني يخرج صامتًا ولا يعمل أبدًا.
  function initSectionNav(nav) {
    if (nav.getAttribute('data-tah-ready-nav') === '1') return;
    nav.setAttribute('data-tah-ready-nav', '1');

    var selector = nav.getAttribute('data-watch') || '[data-tah-section]';
    var sections = Array.prototype.slice.call(document.querySelectorAll(selector));
    if (!sections.length) return;

    var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
    if (!links.length) return;

    function setActive(id) {
      links.forEach(function (link) {
        var on = link.getAttribute('href') === '#' + id;
        link.setAttribute('data-tah-on', on ? '1' : '0');
        if (on) link.setAttribute('aria-current', 'true');
        else link.removeAttribute('aria-current');
      });
      nav.setAttribute('data-tah-active', id);
    }

    if (!HAS_IO) return;

    // نتتبّع أي قسم يشغل أعلى الشاشة — لا أي قسم مرئي، فقد تُرى ثلاثة معًا.
    var visible = new Map();
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) visible.set(entry.target, entry.intersectionRatio);
          else visible.delete(entry.target);
        });

        var best = null;
        var bestTop = Infinity;
        visible.forEach(function (ratio, section) {
          var top = Math.abs(section.getBoundingClientRect().top);
          if (top < bestTop) {
            bestTop = top;
            best = section;
          }
        });

        if (best && best.id) setActive(best.id);
      },
      { rootMargin: '-15% 0px -70% 0px', threshold: [0, 0.25, 0.5] }
    );

    sections.forEach(function (section) {
      if (section.id) io.observe(section);
    });

    // التمرير الناعم بلا scrollIntoView — نحسب الموضع مع مراعاة رأس ملتصق.
    links.forEach(function (link) {
      link.addEventListener('click', function (event) {
        var id = link.getAttribute('href').slice(1);
        var target = document.getElementById(id);
        if (!target) return;

        event.preventDefault();
        var offset = parseInt(nav.getAttribute('data-offset') || '0', 10);
        var top = window.pageYOffset + target.getBoundingClientRect().top - offset;

        window.scrollTo({ top: top, behavior: REDUCED ? 'auto' : 'smooth' });
        setActive(id);
        target.setAttribute('tabindex', '-1');
        target.focus({ preventScroll: true });
      });
    });
  }

  /* ─────────── لزوجة الشريط ─────────── */

  function initSticky(el) {
    if (el.getAttribute('data-tah-ready-sticky') === '1') return;
    el.setAttribute('data-tah-ready-sticky', '1');

    if (!HAS_IO) return;

    // حارس بارتفاع صفر قبل العنصر: خروجه من الشاشة يعني أن الشريط التصق.
    var sentinel = document.createElement('div');
    sentinel.setAttribute('aria-hidden', 'true');
    sentinel.style.cssText = 'height:1px;pointer-events:none';
    el.parentNode.insertBefore(sentinel, el);

    new IntersectionObserver(
      function (entries) {
        el.setAttribute('data-tah-stuck', entries[0].isIntersecting ? '0' : '1');
      },
      { threshold: 0 }
    ).observe(sentinel);
  }

  /* ─────────── مؤشّر التقدّم ─────────── */

  function initProgress(el) {
    if (el.getAttribute('data-tah-ready-progress') === '1') return;
    el.setAttribute('data-tah-ready-progress', '1');

    el.setAttribute('role', 'progressbar');
    el.setAttribute('aria-label', el.getAttribute('data-label') || 'تقدّم القراءة');
    el.setAttribute('aria-valuemin', '0');
    el.setAttribute('aria-valuemax', '100');

    var raf = 0;
    function update() {
      raf = 0;
      var doc = document.documentElement;
      var max = doc.scrollHeight - doc.clientHeight;
      var pct = max > 0 ? Math.round((window.pageYOffset / max) * 100) : 0;
      pct = Math.max(0, Math.min(100, pct));

      el.style.setProperty('--tah-progress', pct + '%');
      el.setAttribute('aria-valuenow', String(pct));
    }

    window.addEventListener(
      'scroll',
      function () {
        if (raf) return;
        raf = window.requestAnimationFrame(update);
      },
      { passive: true }
    );
    window.addEventListener('resize', update, { passive: true });
    update();
  }

  /* ─────────── تهيئة ─────────── */

  function scan() {
    initReveal();
    document.querySelectorAll('[data-tah-sectionnav]:not([data-tah-ready-nav])').forEach(initSectionNav);
    document.querySelectorAll('[data-tah-sticky]:not([data-tah-ready-sticky])').forEach(initSticky);
    document.querySelectorAll('[data-tah-progress]:not([data-tah-ready-progress])').forEach(initProgress);
  }

  function boot() {
    scan();
    if (window.MutationObserver) {
      var timer = 0;
      new window.MutationObserver(function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(scan, 150);
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  window.TahObserver = { scan: scan };
})();
