/**
 * Выводит CRM «Сделки» на всю ширину экрана: перенос в body + position:fixed
 * (обходит max-width/overflow у темы и блоков Gutenberg).
 * Подложка на весь экран + высокий z-index + скрытие подвала темы.
 */
(function () {
  'use strict';

  var Z_BACK = 2147483640;
  var Z_CRM = 2147483646;

  function headerTop() {
    var t = 0;
    var ab = document.getElementById('wpadminbar');
    if (ab && document.body.classList.contains('admin-bar')) {
      t = ab.offsetHeight || 32;
    }
    var bar = document.querySelector('.yvo-cab-topbar');
    if (bar) {
      t += Math.round(bar.getBoundingClientRect().height);
    }
    return t;
  }

  function hideThemeFooters() {
    var sel =
      'footer, [role="contentinfo"], .site-footer, #colophon, .wp-block-template-part.site-footer';
    document.querySelectorAll(sel).forEach(function (node) {
      if (node.closest('.yvo-deal-cabinet-embed')) {
        return;
      }
      if (node.dataset.yvoFooterHidden === '1') {
        return;
      }
      node.dataset.yvoFooterHidden = '1';
      node.dataset.yvoFooterPrevDisplay = node.style.display || '';
      node.style.setProperty('display', 'none', 'important');
      node.style.setProperty('visibility', 'hidden', 'important');
    });
  }

  function apply() {
    var el = document.querySelector('.yvo-deal-cabinet-embed');
    if (!el || el.dataset.yvoFullscreen === '1') {
      return;
    }
    if (el.closest('.yvo-deal-cabinet-gate')) {
      return;
    }
    if (!el.querySelector('.yvo-deal-cabinet-shell')) {
      return;
    }
    if (document.body.classList.contains('yvo-cabinet-virtual')) {
      return;
    }

    el.dataset.yvoFullscreen = '1';
    var anchor = document.createComment('yvo-deal-cabinet-placeholder');
    el.parentNode.insertBefore(anchor, el);

    var backdrop = document.createElement('div');
    backdrop.id = 'yvo-deal-cabinet-page-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.appendChild(backdrop);
    document.body.appendChild(el);
    el.classList.add('yvo-deal-cabinet-embed--fullscreen');

    function relayout() {
      var top = headerTop();
      var common =
        'position:fixed;left:0;right:0;width:100vw;max-width:none;margin:0;padding:0;box-sizing:border-box;top:' +
        top +
        'px;bottom:0;';
      backdrop.style.cssText =
        common +
        'z-index:' +
        Z_BACK +
        ';background:#f0f2f5;pointer-events:auto';
      el.style.cssText =
        common +
        'z-index:' +
        Z_CRM +
        ';overflow:auto;-webkit-overflow-scrolling:touch';
      hideThemeFooters();
    }

    relayout();
    document.body.style.overflow = 'hidden';
    document.body.classList.add('yvo-deal-cabinet-fullscreen-open');

    window.addEventListener('resize', relayout);
    window.addEventListener('orientationchange', function () {
      setTimeout(relayout, 200);
    });

    var topb = document.querySelector('.yvo-cab-topbar');
    if (topb && window.ResizeObserver) {
      var ro = new ResizeObserver(relayout);
      ro.observe(topb);
    }
  }

  function run() {
    apply();
    setTimeout(apply, 100);
    setTimeout(apply, 500);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
