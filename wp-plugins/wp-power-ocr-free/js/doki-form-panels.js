/**
 * Форма договоров: 3D-наклон внешних панелей (тип сделки + загрузка).
 */
(function () {
    'use strict';

    var PANEL_SELECTOR =
        '.yvo-doki-form-skin .form-column.card3d, #yvo-doki-contract-type-picker.contract-types-wrap';

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function initTilt() {
        if (prefersReducedMotion() || !window.matchMedia('(hover: hover)').matches) {
            return;
        }

        document.querySelectorAll(PANEL_SELECTOR).forEach(function (panel) {
            panel.addEventListener('mousemove', function (event) {
                var rect = panel.getBoundingClientRect();
                var px = (event.clientX - rect.left) / rect.width - 0.5;
                var py = (event.clientY - rect.top) / rect.height - 0.5;
                panel.style.setProperty('--tilt-x', (-py * 8).toFixed(2) + 'deg');
                panel.style.setProperty('--tilt-y', (px * 8).toFixed(2) + 'deg');
                panel.classList.add('is-tilting');
            });

            panel.addEventListener('mouseleave', function () {
                panel.classList.remove('is-tilting');
                panel.style.removeProperty('--tilt-x');
                panel.style.removeProperty('--tilt-y');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTilt);
    } else {
        initTilt();
    }
})();
