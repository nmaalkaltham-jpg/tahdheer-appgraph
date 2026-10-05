/**
 * Tahdheer — زمن تشغيل المؤشّر: نقاط ساخنة وقبل/بعد ومعرض بالتمرير.
 *
 * يفتح ٤ اختيارات: الصورة التفاعلية، والصورة بنقاط، وقبل/بعد، والمعرض
 * بالتمرير.
 *
 * القرار الأهم هنا وصولي: مقارنة «قبل/بعد» تُبنى على input[type=range]
 * حقيقي لا على مستمع pointermove. النتيجة أنها تعمل بلوحة المفاتيح ومع
 * قارئ الشاشة وباللمس مجانًا، بلا كتابة سطر وصولية واحد — بدل تقليد شريط
 * تمرير بعنصر div ثم محاولة تعويض ما فُقد.
 *
 * الاستخدام:
 *   <div data-tah-compare>
 *     <img data-tah-before …><img data-tah-after …>
 *     <input type="range" data-tah-compare-input>
 *   </div>
 *
 *   <div data-tah-hotspots>
 *     <img …>
 *     <button data-tah-hotspot data-x="42" data-y="61" aria-controls="tip1">…</button>
 *     <div id="tip1" data-tah-tip>…</div>
 *   </div>
 *
 *   <div data-tah-hovergallery>
 *     <img data-tah-frame data-tah-on="1" …><img data-tah-frame …>
 *   </div>
 */
(function () {
  'use strict';

  var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ─────────── قبل / بعد ─────────── */

  function initCompare(root) {
    if (root.getAttribute('data-tah-ready') === '1') return;
    root.setAttribute('data-tah-ready', '1');

    var input = root.querySelector('[data-tah-compare-input]');
    if (!input) {
      // نبنيه إن لم يوجد، فلا تعتمد الوصولية على أن يتذكّره كاتب القالب.
      input = document.createElement('input');
      input.type = 'range';
      input.setAttribute('data-tah-compare-input', '');
      root.appendChild(input);
    }

    input.min = '0';
    input.max = '100';
    input.step = '1';
    if (!input.value) input.value = root.getAttribute('data-start') || '50';
    if (!input.hasAttribute('aria-label')) {
      input.setAttribute('aria-label', root.getAttribute('data-label') || 'مقارنة قبل وبعد');
    }

    function apply() {
      var pct = Math.max(0, Math.min(100, parseFloat(input.value) || 0));
      root.style.setProperty('--tah-compare', pct + '%');
      root.setAttribute('data-tah-value', String(Math.round(pct)));
      input.setAttribute('aria-valuetext', Math.round(pct) + '٪ من الصورة بعد');
    }

    input.addEventListener('input', apply);
    apply();

    // السحب على الصورة نفسها راحةٌ إضافية — لا بديل عن الشريط.
    root.addEventListener(
      'pointerdown',
      function (event) {
        if (event.target === input) return;
        var move = function (e) {
          var rect = root.getBoundingClientRect();
          if (!rect.width) return;
          var ratio = (e.clientX - rect.left) / rect.width;
          if (window.getComputedStyle(root).direction === 'rtl') ratio = 1 - ratio;
          input.value = String(Math.round(Math.max(0, Math.min(1, ratio)) * 100));
          apply();
        };
        move(event);
        var up = function () {
          document.removeEventListener('pointermove', move);
          document.removeEventListener('pointerup', up);
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
      },
      { passive: true }
    );
  }

  /* ─────────── نقاط ساخنة ─────────── */

  function initHotspots(root) {
    if (root.getAttribute('data-tah-ready') === '1') return;
    root.setAttribute('data-tah-ready', '1');

    var spots = Array.prototype.slice.call(root.querySelectorAll('[data-tah-hotspot]'));
    if (!spots.length) return;

    function closeAll(except) {
      spots.forEach(function (spot) {
        if (spot === except) return;
        spot.setAttribute('aria-expanded', 'false');
        var tipId = spot.getAttribute('aria-controls');
        var tip = tipId ? document.getElementById(tipId) : null;
        if (tip) tip.hidden = true;
      });
    }

    spots.forEach(function (spot, i) {
      var x = parseFloat(spot.getAttribute('data-x') || '50');
      var y = parseFloat(spot.getAttribute('data-y') || '50');

      spot.setAttribute('type', 'button');
      spot.style.setProperty('--tah-x', Math.max(0, Math.min(100, x)) + '%');
      spot.style.setProperty('--tah-y', Math.max(0, Math.min(100, y)) + '%');
      spot.setAttribute('aria-expanded', 'false');

      if (!spot.hasAttribute('aria-label')) {
        spot.setAttribute('aria-label', 'نقطة ' + (i + 1));
      }

      var tipId = spot.getAttribute('aria-controls');
      var tip = tipId ? document.getElementById(tipId) : null;
      if (tip) tip.hidden = true;

      spot.addEventListener('click', function () {
        var open = spot.getAttribute('aria-expanded') !== 'true';
        closeAll(spot);
        spot.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (tip) tip.hidden = !open;
      });
    });

    root.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeAll(null);
    });

    document.addEventListener('click', function (event) {
      if (!root.contains(event.target)) closeAll(null);
    });
  }

  /* ─────────── معرض بالتمرير ─────────── */

  function initHoverGallery(root) {
    if (root.getAttribute('data-tah-ready') === '1') return;
    root.setAttribute('data-tah-ready', '1');

    var frames = Array.prototype.slice.call(root.querySelectorAll('[data-tah-frame]'));
    if (frames.length < 2) return;

    function show(index) {
      var i = Math.max(0, Math.min(frames.length - 1, index));
      frames.forEach(function (frame, j) {
        frame.setAttribute('data-tah-on', j === i ? '1' : '0');
      });
      root.setAttribute('data-tah-index', String(i));
    }

    root.setAttribute('tabindex', '0');
    root.setAttribute('role', 'group');
    if (!root.hasAttribute('aria-label')) {
      root.setAttribute('aria-label', root.getAttribute('data-label') || 'صور متعدّدة للمنتج');
    }

    // على اللمس لا وجود لتمرير — نعتمد النقر، فلا تُفقد الصور على الجوال.
    var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    if (fine && !REDUCED) {
      root.addEventListener(
        'pointermove',
        function (event) {
          var rect = root.getBoundingClientRect();
          if (!rect.width) return;
          var ratio = (event.clientX - rect.left) / rect.width;
          if (window.getComputedStyle(root).direction === 'rtl') ratio = 1 - ratio;
          show(Math.floor(Math.max(0, Math.min(0.999, ratio)) * frames.length));
        },
        { passive: true }
      );
      root.addEventListener('pointerleave', function () { show(0); });
    } else {
      root.addEventListener('click', function () {
        var current = parseInt(root.getAttribute('data-tah-index') || '0', 10);
        show((current + 1) % frames.length);
      });
    }

    root.addEventListener('keydown', function (event) {
      var current = parseInt(root.getAttribute('data-tah-index') || '0', 10);
      var rtl = window.getComputedStyle(root).direction === 'rtl';
      var forward = rtl ? 'ArrowLeft' : 'ArrowRight';
      var back = rtl ? 'ArrowRight' : 'ArrowLeft';

      if (event.key === forward) {
        event.preventDefault();
        show((current + 1) % frames.length);
      } else if (event.key === back) {
        event.preventDefault();
        show((current - 1 + frames.length) % frames.length);
      }
    });

    show(0);
  }

  /* ─────────── تهيئة ─────────── */

  function scan() {
    document.querySelectorAll('[data-tah-compare]:not([data-tah-ready])').forEach(initCompare);
    document.querySelectorAll('[data-tah-hotspots]:not([data-tah-ready])').forEach(initHotspots);
    document.querySelectorAll('[data-tah-hovergallery]:not([data-tah-ready])').forEach(initHoverGallery);
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

  window.TahPointer = { scan: scan };
})();
