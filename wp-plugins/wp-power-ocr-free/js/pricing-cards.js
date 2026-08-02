/**
 * Тарифы АРР: 3D-наклон карточек, scroll-reveal, shine на hover.
 */
(function () {
    'use strict';

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function initTilt() {
        if (prefersReducedMotion() || !window.matchMedia('(hover: hover)').matches) {
            return;
        }

        document.querySelectorAll('.yvo-pr-tier.card3d, .yvo-pr-compare.card3d, .yvo-tpl-card.card3d').forEach(function (card) {
            card.addEventListener('mousemove', function (event) {
                var rect = card.getBoundingClientRect();
                var px = (event.clientX - rect.left) / rect.width - 0.5;
                var py = (event.clientY - rect.top) / rect.height - 0.5;
                var power = card.classList.contains('yvo-pr-tier--featured') ? 12 : 10;
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

    function initScrollReveal() {
        if (prefersReducedMotion()) {
            document.querySelectorAll('.yvo-pr-compare.card3d').forEach(function (el) {
                el.classList.add('is-visible');
            });
            return;
        }

        var compare = document.querySelector('.yvo-pr-compare.card3d');
        if (!compare || !('IntersectionObserver' in window)) {
            if (compare) {
                compare.classList.add('is-visible');
            }
            return;
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
            { threshold: 0.15, rootMargin: '0px 0px -40px 0px' }
        );

        observer.observe(compare);
    }

    function initButtonRipple() {
        if (prefersReducedMotion()) {
            return;
        }

        document.querySelectorAll('.yvo-pr-btn, .yvo-pr-tier__foot .yvo-cabinet-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.style.transform = 'scale(0.97)';
                window.setTimeout(function () {
                    btn.style.transform = '';
                }, 150);
            });
        });
    }

    function boot() {
        initTilt();
        initScrollReveal();
        initButtonRipple();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
