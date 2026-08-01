/**
 * Главная АРР: 3D-наклон карточек «Как формируется договор» + УТП (scroll-reveal).
 */
(function () {
    'use strict';

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function initTilt(selector, power) {
        if (prefersReducedMotion() || !window.matchMedia('(hover: hover)').matches) {
            return;
        }

        document.querySelectorAll(selector).forEach(function (card) {
            card.addEventListener('mousemove', function (event) {
                var rect = card.getBoundingClientRect();
                var px = (event.clientX - rect.left) / rect.width - 0.5;
                var py = (event.clientY - rect.top) / rect.height - 0.5;
                card.style.setProperty('--tilt-x', (-py * power).toFixed(2) + 'deg');
                card.style.setProperty('--tilt-y', (px * power).toFixed(2) + 'deg');
                card.classList.add('is-tilting');
            });

            card.addEventListener('mouseleave', function () {
                card.classList.remove('is-tilting');
                card.style.removeProperty('--tilt-x');
                card.style.removeProperty('--tilt-y');
            });
        });
    }

    function initUspReveal() {
        var root = document.querySelector('.yvo-doki-home-page');
        var nodes = document.querySelectorAll('.doki-usp-reveal');
        if (!nodes.length) {
            return;
        }

        if (prefersReducedMotion() || !('IntersectionObserver' in window)) {
            nodes.forEach(function (el) {
                el.classList.add('is-visible');
            });
            return;
        }

        if (root) {
            root.classList.add('js-usp-animate');
        }

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.12, rootMargin: '0px 0px -30px 0px' }
        );

        nodes.forEach(function (el) {
            observer.observe(el);
        });
    }

    function boot() {
        initTilt('.doki-flow-guide__card.card3d', 9);
        initTilt('.doki-usp__tile.card3d', 7);
        initUspReveal();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
