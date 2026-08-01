/**
 * Мобильное меню шапки Dokii (бургер + выезжающая панель).
 * При старой разметке без бургера — дописывает кнопку и backdrop.
 */
(function () {
    'use strict';

    var MQ = '(max-width: 767px)';

    function isMobile() {
        return window.matchMedia(MQ).matches;
    }

    function createBurger() {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'yvo-cab-topbar-burger';
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-label', 'Открыть меню');
        for (var i = 0; i < 3; i++) {
            var bar = document.createElement('span');
            bar.className = 'yvo-cab-topbar-burger__bar';
            bar.setAttribute('aria-hidden', 'true');
            btn.appendChild(bar);
        }
        return btn;
    }

    function ensureMobileChrome(bar) {
        var inner = bar.querySelector('.yvo-cab-topbar-inner');
        var panel = bar.querySelector('.yvo-cab-topbar-right');
        if (!inner || !panel) {
            return null;
        }

        var burger = bar.querySelector('.yvo-cab-topbar-burger');
        if (!burger) {
            burger = createBurger();
            var panelId = panel.id || ('yvo-cab-topbar-panel-' + Math.random().toString(36).slice(2, 8));
            panel.id = panelId;
            burger.setAttribute('aria-controls', panelId);
            inner.insertBefore(burger, panel);
        }

        if (!bar.querySelector('.yvo-cab-topbar-backdrop')) {
            var backdrop = document.createElement('div');
            backdrop.className = 'yvo-cab-topbar-backdrop';
            backdrop.setAttribute('hidden', 'hidden');
            backdrop.setAttribute('aria-hidden', 'true');
            bar.appendChild(backdrop);
        }

        if (!panel.querySelector('.yvo-cab-topbar-close')) {
            var head = panel.querySelector('.yvo-cab-topbar-mobile-head');
            if (!head) {
                head = document.createElement('div');
                head.className = 'yvo-cab-topbar-mobile-head';
                var title = document.createElement('span');
                title.className = 'yvo-cab-topbar-mobile-head__title';
                title.textContent = 'Меню';
                head.appendChild(title);
                panel.insertBefore(head, panel.firstChild);
            }
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'yvo-cab-topbar-close';
            closeBtn.setAttribute('aria-label', 'Закрыть меню');
            closeBtn.innerHTML = '&times;';
            head.appendChild(closeBtn);
        }

        var user = panel.querySelector('.yvo-cab-topbar-user');
        if (user && !user.classList.contains('yvo-cab-topbar-user--desktop')) {
            user.classList.add('yvo-cab-topbar-user--desktop');
        }

        bar.classList.add('yvo-cab-topbar--compact');
        return burger;
    }

    function initBar(bar) {
        if (!bar || bar.getAttribute('data-yvo-topbar-init') === '1') {
            return;
        }
        bar.setAttribute('data-yvo-topbar-init', '1');

        ensureMobileChrome(bar);

        var burger = bar.querySelector('.yvo-cab-topbar-burger');
        var panel = bar.querySelector('.yvo-cab-topbar-right');
        var backdrop = bar.querySelector('.yvo-cab-topbar-backdrop');
        var closeBtn = bar.querySelector('.yvo-cab-topbar-close');

        if (!burger || !panel) {
            return;
        }

        function closeMenu() {
            bar.classList.remove('is-menu-open');
            burger.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('yvo-cab-topbar-open');
            if (backdrop) {
                backdrop.setAttribute('hidden', 'hidden');
                backdrop.setAttribute('aria-hidden', 'true');
            }
            bar.querySelectorAll('.yvo-cab-topbar-dropdown.is-open').forEach(function (dd) {
                dd.classList.remove('is-open');
            });
        }

        function openMenu() {
            if (!isMobile()) {
                return;
            }
            bar.classList.add('is-menu-open');
            burger.setAttribute('aria-expanded', 'true');
            document.body.classList.add('yvo-cab-topbar-open');
            if (backdrop) {
                backdrop.removeAttribute('hidden');
                backdrop.setAttribute('aria-hidden', 'false');
            }
        }

        function toggleMenu() {
            if (bar.classList.contains('is-menu-open')) {
                closeMenu();
            } else {
                openMenu();
            }
        }

        burger.addEventListener('click', toggleMenu);
        if (closeBtn) {
            closeBtn.addEventListener('click', closeMenu);
        }
        if (backdrop) {
            backdrop.addEventListener('click', closeMenu);
        }

        panel.querySelectorAll('a[href]').forEach(function (link) {
            link.addEventListener('click', function () {
                if (isMobile()) {
                    closeMenu();
                }
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && bar.classList.contains('is-menu-open')) {
                closeMenu();
            }
        });

        window.addEventListener('resize', function () {
            if (!isMobile() && bar.classList.contains('is-menu-open')) {
                closeMenu();
            }
        });

        bar.querySelectorAll('.yvo-cab-topbar-dropdown__trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                if (!isMobile()) {
                    return;
                }
                e.preventDefault();
                var dd = trigger.closest('.yvo-cab-topbar-dropdown');
                if (dd) {
                    dd.classList.toggle('is-open');
                }
            });
        });

        if (isMobile()) {
            closeMenu();
        }

        bindScrollHide(bar);
    }

    function bindScrollHide(bar) {
        if (bar.getAttribute('data-yvo-scroll-hide') === '1') {
            return;
        }
        if (bar.closest('.yvo-cabinet-virtual-head') || bar.closest('.yvo-cab-contract-head')) {
            return;
        }
        bar.setAttribute('data-yvo-scroll-hide', '1');
        var lastY = window.scrollY || 0;
        var threshold = 48;
        window.addEventListener('scroll', function () {
            if (!isMobile() || bar.classList.contains('is-menu-open')) {
                bar.classList.remove('yvo-cab-topbar--scroll-hidden');
                return;
            }
            var y = window.scrollY || 0;
            if (y < threshold) {
                bar.classList.remove('yvo-cab-topbar--scroll-hidden');
            } else if (y > lastY + 4) {
                bar.classList.add('yvo-cab-topbar--scroll-hidden');
            } else if (y < lastY - 4) {
                bar.classList.remove('yvo-cab-topbar--scroll-hidden');
            }
            lastY = y;
        }, { passive: true });
    }

    function initAll() {
        document.querySelectorAll('.yvo-cab-topbar').forEach(initBar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
    setTimeout(initAll, 120);
})();
