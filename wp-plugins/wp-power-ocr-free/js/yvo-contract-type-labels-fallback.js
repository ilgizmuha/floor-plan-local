/**
 * Резервное обновление подписей вкладок/шагов по типу сделки (vanilla JS).
 * Работает даже если основной frontend.js остановился с ошибкой в try/catch.
 * Подключается из wp_footer (yvo_print_contract_type_labels_fallback_footer), не из контента шорткода.
 */
(function () {
    'use strict';

    if (window.__yvoContractLabelsFallbackBooted) {
        return;
    }
    window.__yvoContractLabelsFallbackBooted = true;

    if (typeof Element !== 'undefined' && Element.prototype && !Element.prototype.closest) {
        Element.prototype.closest = function (sel) {
            var el = this;
            while (el && el.nodeType === 1) {
                if (el.matches && el.matches(sel)) {
                    return el;
                }
                el = el.parentElement;
            }
            return null;
        };
    }

    function eventTargetElement(ev) {
        var t = ev.target;
        if (!t) {
            return null;
        }
        if (t.nodeType !== 1) {
            t = t.parentElement;
        }
        return t;
    }

    function applyParticipantTabs(ct) {
        var strip = document.getElementById('yvo-fp-participant-tabs');
        if (!strip) {
            return;
        }
        var tabBtns = strip.querySelectorAll('.yvo-fp-tab');
        var i;
        var tid;
        var m;
        var num;
        var idx;
        var idToNum = {};

        if (ct === 'share_allocation') {
            idx = 0;
            for (i = 0; i < tabBtns.length; i++) {
                tid = tabBtns[i].getAttribute('data-tab');
                if (tid && tid !== 'property' && /^(seller|buyer)\d*$/.test(tid)) {
                    idx++;
                    idToNum[tid] = idx;
                }
            }
            for (i = 0; i < tabBtns.length; i++) {
                var btn = tabBtns[i];
                tid = btn.getAttribute('data-tab');
                if (tid === 'property') {
                    btn.textContent = 'Объект';
                } else if (idToNum[tid]) {
                    btn.textContent = 'Участник ' + idToNum[tid];
                }
            }
            return;
        }

        for (i = 0; i < tabBtns.length; i++) {
            var b = tabBtns[i];
            tid = b.getAttribute('data-tab');
            if (!tid) {
                continue;
            }
            if (tid === 'property') {
                b.textContent = 'Объект';
                continue;
            }
            m = String(tid).match(/^(seller|buyer)(\d*)$/);
            if (m) {
                num = m[2] === '' ? 1 : parseInt(m[2], 10);
                if (ct === 'gift') {
                    if (m[1] === 'seller') {
                        b.textContent = num === 1 ? 'Даритель' : 'Даритель ' + num;
                    } else {
                        b.textContent = num === 1 ? 'Одаряемый' : 'Одаряемый ' + num;
                    }
                } else {
                    if (m[1] === 'seller') {
                        b.textContent = num === 1 ? 'Продавец' : 'Продавец ' + num;
                    } else {
                        b.textContent = num === 1 ? 'Покупатель' : 'Покупатель ' + num;
                    }
                }
                continue;
            }
            if (typeof window.yvoTabLabelForContractType === 'function') {
                var custom = window.yvoTabLabelForContractType(tid, ct);
                if (custom) {
                    b.textContent = custom;
                }
            }
        }
    }

    function applyStepPills(ct) {
        var labels = document.querySelectorAll('.yvo-doki-steps .yvo-doki-step-label');
        if (labels.length < 2) {
            return;
        }
        if (ct === 'gift') {
            labels[0].textContent = 'даритель';
            labels[1].textContent = 'одаряемый';
        } else if (ct === 'share_allocation') {
            labels[0].textContent = 'участники (отчуждают)';
            labels[1].textContent = 'участники (получают)';
        } else {
            labels[0].textContent = 'продавец';
            labels[1].textContent = 'покупатель';
        }
    }

    function applyIntro(ct) {
        var intro = document.querySelector('.yvo-doki-forms-intro');
        if (!intro) {
            return;
        }
        if (ct === 'gift') {
            intro.textContent = 'По умолчанию: один даритель и один одаряемый. В договор попадут только те, у кого заполнено ФИО.';
        } else if (ct === 'share_allocation') {
            intro.textContent = 'Укажите участников с обеих сторон и доли (блок «Доли участников» у объекта). В договор попадут только те, у кого заполнено ФИО.';
        } else {
            intro.textContent = 'По умолчанию: один продавец и один покупатель. В договор попадут только те, у кого заполнено ФИО.';
        }
    }

    function applyCardTitleFromActiveTab() {
        var strip = document.getElementById('yvo-fp-participant-tabs');
        var cardTitle = document.getElementById('yvoDokiParticipantCardTitle');
        if (!strip || !cardTitle) {
            return;
        }
        var active = strip.querySelector('.yvo-fp-tab.active');
        if (!active) {
            return;
        }
        var at = active.getAttribute('data-tab');
        if (at === 'property') {
            cardTitle.textContent = 'Объект';
            return;
        }
        cardTitle.textContent = (active.textContent || '').trim();
    }

    function applyAll(ct) {
        if (!ct) {
            ct = 'sale';
        }
        applyParticipantTabs(ct);
        applyStepPills(ct);
        applyIntro(ct);
        applyCardTitleFromActiveTab();
    }

    function scheduleApply(ct) {
        applyAll(ct);
        setTimeout(function () {
            applyAll(ct);
        }, 0);
        setTimeout(function () {
            applyAll(ct);
        }, 50);
    }

    function syncFromFrontendIfPossible() {
        if (typeof window.yvoGetCurrentContractType !== 'function') {
            return;
        }
        var ct = window.yvoGetCurrentContractType();
        if (ct) {
            scheduleApply(ct);
        }
    }

    document.addEventListener('click', function (e) {
        var t = eventTargetElement(e);
        if (!t || !t.closest) {
            return;
        }
        var pick = t.closest('.yvo-doki-type-pick');
        if (pick) {
            var ct = pick.getAttribute('data-contract-type');
            if (ct) {
                scheduleApply(ct);
            }
            return;
        }
        var dd = t.closest('#yvo-fp-contract-type-menu .yvo-fp-dropdown-item');
        if (dd) {
            var ct2 = dd.getAttribute('data-contract-type');
            if (ct2) {
                scheduleApply(ct2);
            }
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            scheduleApply('sale');
            syncFromFrontendIfPossible();
        });
    } else {
        scheduleApply('sale');
        syncFromFrontendIfPossible();
    }

    window.addEventListener('load', function () {
        syncFromFrontendIfPossible();
        setTimeout(syncFromFrontendIfPossible, 100);
        setTimeout(syncFromFrontendIfPossible, 400);
    });

    document.addEventListener('click', function (e) {
        var el = eventTargetElement(e);
        if (el && el.closest && el.closest('#yvo-fp-participant-tabs .yvo-fp-tab')) {
            setTimeout(applyCardTitleFromActiveTab, 0);
        }
    }, true);

    window.yvoApplyContractLabelsFallback = applyAll;
})();
