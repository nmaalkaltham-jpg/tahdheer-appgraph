/**
 * Tahdheer — زمن تشغيل الكشف: تبويبات وأكورديون ومفتاح تبديل.
 *
 * يفتح ١٤ اختيارًا: تبويبات المنتج والتصنيف والمجموعة والمحتوى، والأكورديون،
 * والأسئلة الشائعة، والبطاقات الموسّعة، ومفتاح التبديل.
 *
 * القرارات:
 *  1) الوصولية ليست إضافة — التبويبات تنفّذ نمط ARIA كاملًا: role=tablist
 *     وأسهم منطقية وHome/End وتركيز مُدار. والأكورديون يستخدم aria-expanded.
 *  2) المحتوى موجود في الشجرة دائمًا — لا حقن بعد النقر. فمحرّك البحث يقرأ
 *     كل الأسئلة، والطباعة تُخرج كل الأقسام، ولا قفزة تخطيط.
 *  3) ربط عميق: مرساة الرابط تفتح القسم المطلوب وتُمرّر إليه.
 *  4) بلا JS يظل المحتوى ظاهرًا — الإخفاء يُطبَّق بعد التهيئة لا قبلها.
 *
 * الاستخدام (تبويبات):
 *   <div data-tah-tabs>
 *     <div data-tah-tablist>
 *       <button data-tah-tab="a">…</button>
 *     </div>
 *     <div data-tah-panel="a">…</div>
 *   </div>
 *
 * الاستخدام (أكورديون):
 *   <div data-tah-accordion data-multi="0">
 *     <h3><button data-tah-summary="q1">…</button></h3>
 *     <div data-tah-details="q1">…</div>
 *   </div>
 */
(function () {
  'use strict';

  var uid = 0;

  function nextId(prefix) {
    uid += 1;
    return prefix + '-' + uid;
  }

  function isRtl(el) {
    return window.getComputedStyle(el).direction === 'rtl';
  }

  /* ─────────── تبويبات ─────────── */

  function tabsIn(root) {
    var list = root.querySelector('[data-tah-tablist]');
    if (!list) return [];
    return Array.prototype.filter.call(list.querySelectorAll('[data-tah-tab]'), function (tab) {
      return tab.closest('[data-tah-tabs]') === root;
    });
  }

  function panelFor(root, key) {
    return root.querySelector('[data-tah-panel="' + key + '"]');
  }

  function selectTab(root, key, focus) {
    var tabs = tabsIn(root);
    if (!tabs.length) return;

    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-tah-tab') === key;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.setAttribute('tabindex', on ? '0' : '-1');
      tab.setAttribute('data-tah-on', on ? '1' : '0');

      var panel = panelFor(root, tab.getAttribute('data-tah-tab'));
      if (panel) {
        panel.hidden = !on;
        panel.setAttribute('data-tah-on', on ? '1' : '0');
      }

      if (on && focus) tab.focus({ preventScroll: true });
    });

    root.setAttribute('data-tah-active', key);
  }

  function initTabs(root) {
    if (root.getAttribute('data-tah-ready') === '1') return;
    root.setAttribute('data-tah-ready', '1');

    var list = root.querySelector('[data-tah-tablist]');
    var tabs = tabsIn(root);
    if (!list || !tabs.length) return;

    list.setAttribute('role', 'tablist');
    if (!list.hasAttribute('aria-label')) {
      list.setAttribute('aria-label', root.getAttribute('data-label') || 'تبويبات');
    }

    tabs.forEach(function (tab) {
      var key = tab.getAttribute('data-tah-tab');
      var panel = panelFor(root, key);

      var tabId = tab.id || nextId('tah-tab');
      tab.id = tabId;
      tab.setAttribute('role', 'tab');
      tab.setAttribute('type', 'button');

      if (panel) {
        var panelId = panel.id || nextId('tah-panel');
        panel.id = panelId;
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', tabId);
        panel.setAttribute('tabindex', '0');
        tab.setAttribute('aria-controls', panelId);
      }

      tab.addEventListener('click', function () { selectTab(root, key, false); });
    });

    list.addEventListener('keydown', function (event) {
      var all = tabsIn(root);
      var index = all.indexOf(document.activeElement);
      if (index < 0) return;

      var rtl = isRtl(root);
      var forward = rtl ? 'ArrowLeft' : 'ArrowRight';
      var back = rtl ? 'ArrowRight' : 'ArrowLeft';
      var target = -1;

      if (event.key === forward) target = (index + 1) % all.length;
      else if (event.key === back) target = (index - 1 + all.length) % all.length;
      else if (event.key === 'Home') target = 0;
      else if (event.key === 'End') target = all.length - 1;

      if (target > -1) {
        event.preventDefault();
        selectTab(root, all[target].getAttribute('data-tah-tab'), true);
      }
    });

    var initial = root.getAttribute('data-active') || tabs[0].getAttribute('data-tah-tab');
    var hash = (window.location.hash || '').replace('#', '');
    if (hash && panelFor(root, hash)) initial = hash;

    selectTab(root, initial, false);
  }

  /* ─────────── أكورديون ─────────── */

  function itemsIn(root) {
    return Array.prototype.filter.call(root.querySelectorAll('[data-tah-summary]'), function (button) {
      return button.closest('[data-tah-accordion]') === root;
    });
  }

  function detailsFor(root, key) {
    return root.querySelector('[data-tah-details="' + key + '"]');
  }

  function toggleItem(root, key, force) {
    var multi = root.getAttribute('data-multi') === '1';
    var button = root.querySelector('[data-tah-summary="' + key + '"]');
    var panel = detailsFor(root, key);
    if (!button || !panel) return;

    var open = typeof force === 'boolean' ? force : button.getAttribute('aria-expanded') !== 'true';

    if (open && !multi) {
      itemsIn(root).forEach(function (other) {
        var otherKey = other.getAttribute('data-tah-summary');
        if (otherKey === key) return;
        other.setAttribute('aria-expanded', 'false');
        var otherPanel = detailsFor(root, otherKey);
        if (otherPanel) {
          otherPanel.hidden = true;
          // لا يكفي hidden: أي CSS مربوط بـ data-tah-on سينسّق لوحًا مغلقًا كأنه مفتوح.
          otherPanel.setAttribute('data-tah-on', '0');
        }
      });
    }

    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.hidden = !open;
    panel.setAttribute('data-tah-on', open ? '1' : '0');
  }

  function initAccordion(root) {
    if (root.getAttribute('data-tah-ready') === '1') return;
    root.setAttribute('data-tah-ready', '1');

    var items = itemsIn(root);
    if (!items.length) return;

    var openFirst = root.getAttribute('data-open-first') === '1';
    var hash = (window.location.hash || '').replace('#', '');

    items.forEach(function (button, i) {
      var key = button.getAttribute('data-tah-summary');
      var panel = detailsFor(root, key);

      var buttonId = button.id || nextId('tah-sum');
      button.id = buttonId;
      button.setAttribute('type', 'button');

      if (panel) {
        var panelId = panel.id || nextId('tah-det');
        panel.id = panelId;
        panel.setAttribute('role', 'region');
        panel.setAttribute('aria-labelledby', buttonId);
        button.setAttribute('aria-controls', panelId);
      }

      button.addEventListener('click', function () { toggleItem(root, key); });

      button.addEventListener('keydown', function (event) {
        var all = itemsIn(root);
        var index = all.indexOf(button);
        var target = -1;

        if (event.key === 'ArrowDown') target = (index + 1) % all.length;
        else if (event.key === 'ArrowUp') target = (index - 1 + all.length) % all.length;
        else if (event.key === 'Home') target = 0;
        else if (event.key === 'End') target = all.length - 1;

        if (target > -1) {
          event.preventDefault();
          all[target].focus({ preventScroll: true });
        }
      });

      // Boolean() إلزامي: بلا مرساة تُنتج '' نصًّا، فيسقط حارس typeof في
      // toggleItem ويتحوّل الأمر إلى تبديل — فيُفتح آخر عنصر لا أوّله.
      var shouldOpen = Boolean((openFirst && i === 0) || (hash && hash === key));
      toggleItem(root, key, shouldOpen);
    });
  }

  /* ─────────── مفتاح تبديل ─────────── */

  function initToggle(root) {
    if (root.getAttribute('data-tah-ready') === '1') return;
    root.setAttribute('data-tah-ready', '1');

    var button = root.querySelector('[data-tah-toggle-btn]') || root;
    var targetSel = root.getAttribute('data-target');
    var target = targetSel ? document.querySelector(targetSel) : root.querySelector('[data-tah-toggle-body]');
    if (!target) return;

    var open = root.getAttribute('data-open') === '1';
    var targetId = target.id || nextId('tah-toggle');
    target.id = targetId;

    button.setAttribute('type', 'button');
    button.setAttribute('aria-controls', targetId);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    target.hidden = !open;

    button.addEventListener('click', function () {
      var next = button.getAttribute('aria-expanded') !== 'true';
      button.setAttribute('aria-expanded', next ? 'true' : 'false');
      target.hidden = !next;
    });
  }

  /* ─────────── تهيئة ─────────── */

  function scan() {
    document.querySelectorAll('[data-tah-tabs]:not([data-tah-ready])').forEach(initTabs);
    document.querySelectorAll('[data-tah-accordion]:not([data-tah-ready])').forEach(initAccordion);
    document.querySelectorAll('[data-tah-toggle]:not([data-tah-ready])').forEach(initToggle);
  }

  function boot() {
    scan();

    window.addEventListener('hashchange', function () {
      var hash = (window.location.hash || '').replace('#', '');
      if (!hash) return;

      document.querySelectorAll('[data-tah-accordion]').forEach(function (root) {
        if (detailsFor(root, hash)) toggleItem(root, hash, true);
      });
      document.querySelectorAll('[data-tah-tabs]').forEach(function (root) {
        if (panelFor(root, hash)) selectTab(root, hash, true);
      });
    });

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

  window.TahDisclosure = { selectTab: selectTab, toggleItem: toggleItem, scan: scan };
})();
