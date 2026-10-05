/**
 * Tahdheer — زمن تشغيل الحوار: معاينة سريعة ومعاينة ملف ودُرج.
 *
 * أُجِّل هذا الزمن آخرًا بقرار معلن: يفتح ٣ اختيارات فقط (الأقلّ) وهو الأعقد
 * وصوليًّا. والآن يُنفَّذ كاملًا لأن غيابه كان يحجز `preview` و`file_preview`.
 *
 * الوصولية هي كلّ العمل هنا، لا زينة فوقه:
 *  ١) حصر التركيز — Tab لا يخرج من الحوار، وShift+Tab يلتفّ للخلف.
 *  ٢) إعادة التركيز — يعود إلى الزرّ الذي فتح الحوار، لا إلى أعلى الصفحة.
 *     نسيان هذا يعني أن مستخدم لوحة المفاتيح يفقد موضعه في القائمة كلّها.
 *  ٣) Escape يُغلق · النقر على الخلفية يُغلق · النقر داخل المحتوى لا يُغلق.
 *  ٤) inert على ما وراء الحوار — فقارئ الشاشة لا يتسرّب إلى المحتوى المحجوب.
 *  ٥) قفل تمرير الصفحة مع تعويض عرض شريط التمرير، فلا يقفز التخطيط.
 *
 * الاستخدام:
 *   <button data-tah-dialog-open="pv-1">معاينة</button>
 *   <div data-tah-dialog id="pv-1" hidden>
 *     <div data-tah-dialog-backdrop></div>
 *     <div data-tah-dialog-panel role="dialog" aria-modal="true" aria-labelledby="…">
 *       <button data-tah-dialog-close>…</button>
 *       …
 *     </div>
 *   </div>
 */
(function () {
  'use strict';

  var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var FOCUSABLE = [
    'a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])',
  ].join(',');

  var open = null;
  var opener = null;
  var scrollY = 0;

  function focusable(root) {
    return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (el) {
      return el.offsetParent !== null || el === document.activeElement;
    });
  }

  /** ما وراء الحوار يصير خارج شجرة الوصولية — لا مجرّد مخفيّ بصريًّا. */
  /**
   * عزل ما وراء الحوار — بمشي من الحوار صعودًا، لا بمرّ على أبناء body.
   *
   * الحوار مُصيّر داخل المكوّن لا منقولًا إلى الجسم، فعزل أبناء body يعزل سَلَف
   * الحوار — أي يعزل الحوار نفسه: لا يقبل تركيزًا ولا يراه قارئ الشاشة، فيموت
   * المودال نصف موت لأن Escape وإعادة التركيز على مستوى المستند فتبقى حيّة.
   *
   * المشي من الداخل للخارج يعزل الأشقّاء فقط في كل مستوى، فيعمل مهما كان عمق
   * التعشيش. ونوسِم ما عزلناه فقط، فلا تلمس الاستعادة عنصرًا كان معزولًا قبلنا.
   */
  function setInert(dialog, on) {
    if (!on) {
      document.querySelectorAll('[data-tah-inerted]').forEach(function (el) {
        el.removeAttribute('inert');
        el.removeAttribute('aria-hidden');
        el.removeAttribute('data-tah-inerted');
      });
      return;
    }

    var node = dialog;

    while (node && node.parentElement) {
      var parent = node.parentElement;
      var current = node;

      Array.prototype.forEach.call(parent.children, function (sib) {
        if (sib === current) return;
        if (sib.tagName === 'SCRIPT' || sib.tagName === 'STYLE' || sib.tagName === 'LINK') return;
        if (sib.hasAttribute('inert')) return;

        sib.setAttribute('inert', '');
        sib.setAttribute('aria-hidden', 'true');
        sib.setAttribute('data-tah-inerted', '');
      });

      if (parent === document.body) break;
      node = parent;
    }
  }

  function lockScroll(on) {
    var doc = document.documentElement;
    if (on) {
      scrollY = window.pageYOffset;
      // تعويض عرض شريط التمرير يمنع قفزة أفقية عند إخفائه.
      var gap = window.innerWidth - doc.clientWidth;
      doc.style.setProperty('--tah-sbw', gap + 'px');
      document.body.style.paddingInlineEnd = gap > 0 ? gap + 'px' : '';
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
      document.body.style.paddingInlineEnd = '';
      doc.style.removeProperty('--tah-sbw');
      window.scrollTo({ top: scrollY, behavior: 'auto' });
    }
  }

  function openDialog(id, trigger) {
    var dialog = document.getElementById(id);
    if (!dialog || !dialog.hasAttribute('data-tah-dialog')) return;
    if (open) closeDialog(true);

    opener = trigger || document.activeElement;
    open = dialog;

    dialog.hidden = false;
    dialog.setAttribute('data-tah-on', '1');

    var panel = dialog.querySelector('[data-tah-dialog-panel]') || dialog;
    panel.setAttribute('role', panel.getAttribute('role') || 'dialog');
    panel.setAttribute('aria-modal', 'true');
    if (!panel.hasAttribute('tabindex')) panel.setAttribute('tabindex', '-1');

    setInert(dialog, true);
    lockScroll(true);

    if (trigger) trigger.setAttribute('aria-expanded', 'true');

    // التركيز بعد العزل ورفع hidden: عنصر داخل سَلَف معزول لا يقبل التركيز،
    // وفشل focus() لا يرفع خطأ — فيبقى التركيز على body ويسقط حصر Tab بلا أثر
    // مرئي. لذلك نتحقّق من وصوله فعلًا ونرجع للوح نفسه عند الحاجة.
    var items = focusable(panel);
    (items.length ? items[0] : panel).focus({ preventScroll: true });

    if (!panel.contains(document.activeElement)) {
      panel.focus({ preventScroll: true });
    }
  }

  function closeDialog(silent) {
    if (!open) return;
    var dialog = open;
    var trigger = opener;

    dialog.setAttribute('data-tah-on', '0');
    setInert(dialog, false);
    lockScroll(false);

    var finish = function () {
      dialog.hidden = true;
    };
    if (REDUCED) finish();
    else window.setTimeout(finish, 160);

    open = null;
    opener = null;

    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
      if (!silent) trigger.focus({ preventScroll: true });
    }
  }

  function onKeydown(event) {
    if (!open) return;

    if (event.key === 'Escape') {
      event.preventDefault();
      closeDialog(false);
      return;
    }

    if (event.key !== 'Tab') return;

    var panel = open.querySelector('[data-tah-dialog-panel]') || open;
    var items = focusable(panel);
    if (!items.length) {
      event.preventDefault();
      panel.focus({ preventScroll: true });
      return;
    }

    // التركيز خارج اللوح أصلًا (نقرة في فراغ، أو تركيز لم يستقرّ): أعِده للداخل
    // بدل مقارنته بالطرفين — وإلا لم تتحقّق أي مقارنة وخرج Tab من الحوار صامتًا.
    if (!panel.contains(document.activeElement)) {
      event.preventDefault();
      (event.shiftKey ? items[items.length - 1] : items[0]).focus({ preventScroll: true });
      return;
    }

    var first = items[0];
    var last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus({ preventScroll: true });
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus({ preventScroll: true });
    }
  }

  function boot() {
    // تفويض واحد على المستند — لا مستمع لكل زرّ، فالبطاقات المُحمَّلة لاحقًا تعمل.
    document.addEventListener('click', function (event) {
      var trigger = event.target.closest ? event.target.closest('[data-tah-dialog-open]') : null;
      if (trigger) {
        event.preventDefault();
        openDialog(trigger.getAttribute('data-tah-dialog-open'), trigger);
        return;
      }

      if (!open) return;

      if (event.target.closest('[data-tah-dialog-close]')) {
        event.preventDefault();
        closeDialog(false);
        return;
      }

      // الخلفية تُغلق؛ والنقر داخل اللوح لا يُغلق.
      if (event.target.hasAttribute('data-tah-dialog-backdrop')) closeDialog(false);
    });

    document.addEventListener('keydown', onKeydown);

    document.querySelectorAll('[data-tah-dialog-open]').forEach(function (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
      trigger.setAttribute('aria-haspopup', 'dialog');
      var id = trigger.getAttribute('data-tah-dialog-open');
      if (id) trigger.setAttribute('aria-controls', id);
    });

    document.querySelectorAll('[data-tah-dialog]').forEach(function (dialog) {
      dialog.hidden = true;
      dialog.setAttribute('data-tah-ready', '1');
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  window.TahDialog = { open: openDialog, close: closeDialog };
})();
