/**
 * ДОКИ: шаги, автозаполнение, синхронизация с вкладками участников.
 * Загружается после yvo-frontend-js (там window.yvoDokiRefreshParticipantTabStrip).
 */
jQuery(function($) {
    'use strict';

    function yvoDokiIsMobileOneScreen() {
        return window.matchMedia('(max-width: 768px)').matches;
    }

    function yvoDokiScroll(sel) {
        if (yvoDokiIsMobileOneScreen()) {
            var scrollRoot = document.getElementById('yvoDokiMosScroll');
            var el = document.querySelector(sel);
            if (scrollRoot && el && scrollRoot.contains(el)) {
                var top = el.getBoundingClientRect().top - scrollRoot.getBoundingClientRect().top + scrollRoot.scrollTop;
                scrollRoot.scrollTo({ top: Math.max(0, top - 8), behavior: 'smooth' });
                return;
            }
            if (scrollRoot) {
                scrollRoot.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }
        }
        var el = document.querySelector(sel);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function yvoDokiSyncMobileTypeLabel() {
        var $badge = $('#yvoDokiFormTypeBadge');
        var $toggleLabel = $('#yvoDokiMosTypeToggleLabel');
        if ($badge.length && $toggleLabel.length) {
            $toggleLabel.text($.trim($badge.text()) || $toggleLabel.text());
        }
    }

    $(document).on('click', '#yvoDokiMosTypeToggle', function(e) {
        e.preventDefault();
        if (!yvoDokiIsMobileOneScreen()) {
            return;
        }
        var $page = $('.yvo-doki-contract-page').first();
        var open = $page.hasClass('yvo-doki-mos-type-open');
        $page.toggleClass('yvo-doki-mos-type-open', !open);
        $(this).attr('aria-expanded', !open ? 'true' : 'false');
        if (!open) {
            yvoDokiScroll('#yvo-doki-contract-type-picker');
        }
    });

    $(document).on('click', '#yvo-doki-contract-type-picker .pbtn3d.type-option, #yvo-doki-contract-type-picker .yvo-doki-type-extra .pbtn3d', function() {
        if (!yvoDokiIsMobileOneScreen()) {
            return;
        }
        $('.yvo-doki-contract-page').removeClass('yvo-doki-mos-type-open');
        $('#yvoDokiMosTypeToggle').attr('aria-expanded', 'false');
        setTimeout(yvoDokiSyncMobileTypeLabel, 0);
    });

    $(document).on('click', '#yvo-doki-scroll-generate', function(e) {
        e.preventDefault();
        yvoDokiScroll('#yvo-fp-forms-section');
    });
    $(document).on('click', '#yvo-doki-scroll-autofill', function(e) {
        e.preventDefault();
        if (typeof window.yvoFpGuardPaidFeature === 'function' && window.yvoFpGuardPaidFeature('autofill')) {
            return;
        }
        yvoDokiScroll('#yvo-fp-forms-section');
        setTimeout(function() {
            var f = document.getElementById('yvo-fp-file');
            if (f) {
                f.click();
            }
        }, 400);
    });

    if (window.location.search.indexOf('yvo_autofill=1') !== -1) {
        setTimeout(function() {
            var f = document.getElementById('yvo-fp-file');
            if (f) {
                f.click();
            }
        }, 700);
    }

    var $steps = $('.yvo-doki-steps');
    if (!$steps.length) {
        if (typeof window.yvoDokiRefreshParticipantTabStrip === 'function') {
            window.yvoDokiRefreshParticipantTabStrip();
        }
        return;
    }

    var stepKeys = ['seller', 'buyer', 'property', 'generate'];
    var stepLabels = ['продавец', 'покупатель', 'объект', 'генерация'];

    function yvoDokiStepLabelsForUi() {
        var ct = typeof window.yvoGetCurrentContractType === 'function' ? window.yvoGetCurrentContractType() : 'sale';
        if (ct === 'share_allocation') {
            return ['участники (отчуждают)', 'участники (получают)', 'объект', 'генерация'];
        }
        if (ct === 'gift') {
            return ['даритель', 'одаряемый', 'объект', 'генерация'];
        }
        return stepLabels;
    }

    function yvoDokiFpTabStrip() {
        if (typeof window.yvoFpParticipantTabStripEl === 'function') {
            return window.yvoFpParticipantTabStripEl();
        }
        var $t = $('#yvo-fp-participant-tabs');
        if ($t.length) return $t;
        $t = $('.yvo-frontend-page .yvo-fp-tabs-wrap > .yvo-fp-tabs').first();
        return $t.length ? $t : $('.yvo-fp-tabs-wrap > .yvo-fp-tabs').first();
    }

    function yvoDokiSetFooter(i) {
        var labels = yvoDokiStepLabelsForUi();
        var lab = 'Шаг ' + (i + 1) + ' из 4 · ' + (labels[i] !== undefined ? labels[i] : '');
        $('#yvoDokiStepFooterLabel').text(lab);
    }

    function yvoDokiShowAutofill(tab) {
        $('.yvo-doki-autofill').attr('hidden', true);
        $('.yvo-doki-autofill--' + tab).removeAttr('hidden');
    }

    function yvoDokiUpdateParticipantCardTitle() {
        var $t = $('#yvoDokiParticipantCardTitle');
        var $h = $('#yvoDokiParticipantCardHint');
        if (!$t.length) {
            return;
        }
        var ct = typeof window.yvoGetCurrentContractType === 'function' ? window.yvoGetCurrentContractType() : 'sale';
        var share = (ct === 'share_allocation');
        var gift = (ct === 'gift');
        var $active = yvoDokiFpTabStrip().find('.yvo-fp-tab.active').first();
        if (!$active.length) {
            return;
        }
        var tabId = $active.attr('data-tab');
        if (tabId === 'property') {
            $t.text('Объект');
            if ($h.length) {
                $h.attr('hidden', true).text('');
            }
            if (typeof window.yvoFpUpdateApplySameGuardianButton === 'function') {
                window.yvoFpUpdateApplySameGuardianButton();
            }
            return;
        }
        var m = String(tabId).match(/^(seller|buyer)(\d*)$/);
        if (m) {
            var num = m[2] === '' ? 1 : (parseInt(m[2], 10) || 1);
            if (share) {
                $t.text($.trim($active.text()) || ('Участник ' + num));
                if ($h.length) {
                    $h.removeAttr('hidden').text(m[1] === 'seller' ? 'отчуждает доли' : 'получает доли');
                }
            } else if (gift) {
                var gw = m[1] === 'seller' ? 'Даритель' : 'Одаряемый';
                $t.text(num === 1 ? gw : gw + ' ' + num);
                if ($h.length) {
                    $h.attr('hidden', true).text('');
                }
            } else {
                var roleWord = m[1] === 'seller' ? 'Продавец' : 'Покупатель';
                $t.text(roleWord + ' (участник ' + num + ')');
                if ($h.length) {
                    $h.attr('hidden', true).text('');
                }
            }
            if (typeof window.yvoFpUpdateApplySameGuardianButton === 'function') {
                window.yvoFpUpdateApplySameGuardianButton();
            }
            return;
        }
        if (typeof window.yvoTabLabelForContractType === 'function') {
            $t.text(window.yvoTabLabelForContractType(tabId, ct) || $.trim($active.text()));
        } else {
            $t.text($.trim($active.text()));
        }
        if ($h.length) {
            $h.attr('hidden', true).text('');
        }
        if (typeof window.yvoFpUpdateApplySameGuardianButton === 'function') {
            window.yvoFpUpdateApplySameGuardianButton();
        }
    }

    function yvoDokiRefreshStepLabelsForContract() {
        var ct = typeof window.yvoGetCurrentContractType === 'function' ? window.yvoGetCurrentContractType() : 'sale';
        var $labels = $('.yvo-doki-steps .yvo-doki-step-label');
        if (!$labels.length) {
            return;
        }
        if (ct === 'share_allocation') {
            if ($labels.length > 0) {
                $labels.eq(0).text('участники (отчуждают)');
            }
            if ($labels.length > 1) {
                $labels.eq(1).text('участники (получают)');
            }
            if ($labels.length > 2) {
                $labels.eq(2).text('объект');
            }
        } else if (ct === 'gift') {
            if ($labels.length > 0) {
                $labels.eq(0).text('даритель');
            }
            if ($labels.length > 1) {
                $labels.eq(1).text('одаряемый');
            }
            if ($labels.length > 2) {
                $labels.eq(2).text('объект');
            }
        } else {
            if ($labels.length > 0) {
                $labels.eq(0).text('продавец');
            }
            if ($labels.length > 1) {
                $labels.eq(1).text('покупатель');
            }
            if ($labels.length > 2) {
                $labels.eq(2).text('объект');
            }
        }
        yvoDokiRefreshAutofillUploadLabels(ct);
    }

    /** Подписи кнопок загрузки документов по роли и типу договора. */
    function yvoDokiRefreshAutofillUploadLabels(ct) {
        ct = ct || (typeof window.yvoGetCurrentContractType === 'function' ? window.yvoGetCurrentContractType() : 'sale');
        var sellerRole = 'продавца';
        var buyerRole = 'покупателя';
        var sellerHint = 'Загрузите паспорт или документ продавца — сервис подставит ФИО, паспорт и адрес. Поля ниже можно править.';
        var buyerHint = 'Загрузите паспорт или документ покупателя — сервис подставит ФИО, паспорт и адрес.';
        if (ct === 'gift') {
            sellerRole = 'дарителя';
            buyerRole = 'одаряемого';
            sellerHint = 'Загрузите паспорт или документ дарителя — сервис подставит ФИО, паспорт и адрес. Поля ниже можно править.';
            buyerHint = 'Загрузите паспорт или документ одаряемого — сервис подставит ФИО, паспорт и адрес.';
        } else if (ct === 'share_allocation') {
            sellerRole = 'участника';
            buyerRole = 'участника';
            sellerHint = 'Загрузите паспорт или документ участника (кто отчуждает) — сервис подставит ФИО, паспорт и адрес.';
            buyerHint = 'Загрузите паспорт или документ участника (кто получает) — сервис подставит ФИО, паспорт и адрес.';
        }
        $('#yvo-doki-seller-upload').text('Загрузить паспорт / документ ' + sellerRole);
        $('#yvo-doki-buyer-upload').text('Загрузить паспорт / документ ' + buyerRole);
        $('#yvo-doki-property-upload').text('Загрузить документы по объекту');
        $('.yvo-doki-autofill--seller .autofill-hint-text').text(sellerHint);
        $('.yvo-doki-autofill--buyer .autofill-hint-text').text(buyerHint);
        $('.yvo-doki-autofill--property .autofill-hint-text').text('Загрузите выписку ЕГРН или другие документы по объекту — сервис подставит адрес и кадастр.');
    }
    window.yvoDokiRefreshAutofillUploadLabels = yvoDokiRefreshAutofillUploadLabels;

    function yvoDokiTabToStepIndex(t) {
        if (!t) {
            return -1;
        }
        var s = String(t);
        if (s === 'property') {
            return 2;
        }
        /* Опекун и представитель — на том же шаге, что несовершеннолетний / продавец / покупатель (не indexOf('seller_') — у guardian_seller смещение). */
        if (s === 'buyer' || /^buyer\d+$/.test(s) || s === 'minor_buyer' || /^minor_buyer\d*$/.test(s) ||
            /^guardian_buyer\d*$/.test(s) || /^buyer_representative/.test(s) || /^contributor\d*$/.test(s)) {
            return 1;
        }
        if (s === 'seller' || /^seller\d+$/.test(s) || s === 'minor_seller' || /^minor_seller\d*$/.test(s) ||
            /^guardian_seller\d*$/.test(s) || /^seller_representative/.test(s)) {
            return 0;
        }
        return -1;
    }

    function yvoDokiGetCurrentStepIndex() {
        var $p = $('.yvo-doki-steps .step-pill.active').first();
        if (!$p.length) {
            return 0;
        }
        var i = parseInt($p.attr('data-yvo-step-index'), 10);
        return isNaN(i) ? 0 : i;
    }

    /** Первая вкладка на шаге (если seller/buyer удалены — seller2/minor_buyer и т.д.). */
    function yvoDokiFirstTabIdForStep(stepIndex) {
        if (stepIndex < 0 || stepIndex > 2) {
            return null;
        }
        var $strip = yvoDokiFpTabStrip();
        var pick = null;
        if ($strip.length) {
            $strip.find('.yvo-fp-tab').each(function() {
                var id = $(this).attr('data-tab');
                if (!id || yvoDokiTabToStepIndex(id) !== stepIndex) {
                    return;
                }
                pick = id;
                return false;
            });
        }
        if (pick) {
            return pick;
        }
        return stepKeys[stepIndex] || null;
    }

    /** На шаге «продавец» показываем только вкладки стороны продавца; на «покупатель» — покупателя; на «объект» — объект (без дублей с верхней навигацией). */
    function yvoDokiFilterParticipantTabsForStep(stepIndex) {
        if (!$('.yvo-doki-form-skin').length) {
            return;
        }
        var $strip = yvoDokiFpTabStrip();
        if (!$strip.length) {
            return;
        }
        $strip.find('.yvo-fp-tab').each(function() {
            var id = $(this).attr('data-tab');
            var tabStep = yvoDokiTabToStepIndex(id);
            var show = stepIndex >= 3 || tabStep === stepIndex;
            $(this).toggleClass('yvo-doki-tab--filtered-out', !show);
        });
        var $active = $strip.find('.yvo-fp-tab.active').first();
        if ($active.length && $active.hasClass('yvo-doki-tab--filtered-out')) {
            var fallback = yvoDokiFirstTabIdForStep(stepIndex);
            if (fallback && typeof window.yvoFpActivateParticipantTab === 'function') {
                window.yvoFpActivateParticipantTab(fallback, { skipDokiStepSync: true });
            } else {
                var $first = $strip.find('.yvo-fp-tab').not('.yvo-doki-tab--filtered-out').first();
                if ($first.length) {
                    $first.trigger('click');
                }
            }
        }
    }

    /** Синхронизация шагов ДОКИ (пилюли, автозаполнение) без повторного переключения панели. */
    function yvoDokiSyncStepChrome(stepIndex, tabId) {
        if (stepIndex < 0 || stepIndex > 2) {
            return;
        }
        $('.yvo-doki-form-skin').attr('data-yvo-doki-step', String(stepIndex));
        $steps.find('.step-pill').removeClass('active').attr('aria-selected', 'false');
        $steps.find('.step-pill[data-yvo-step-index="' + stepIndex + '"]').addClass('active').attr('aria-selected', 'true');
        yvoDokiSetFooter(stepIndex);
        yvoDokiShowAutofill(stepKeys[stepIndex]);
        if (typeof window.yvoFpRefreshAddParticipantMenu === 'function') {
            window.yvoFpRefreshAddParticipantMenu();
        }
        setTimeout(function() {
            yvoDokiFilterParticipantTabsForStep(stepIndex);
            yvoDokiSyncContributorAddButtonVisibility();
            if (stepIndex === 2 && typeof window.yvoToggleShareAllocationUi === 'function') {
                window.yvoToggleShareAllocationUi();
            }
            yvoDokiUpdateParticipantCardTitle();
        }, 0);
    }

    /** Вноситель задатка — в меню «Добавить» только на шаге «покупатель». */
    function yvoDokiSyncContributorAddButtonVisibility() {
        var i = yvoDokiGetCurrentStepIndex();
        var show = (i === 1);
        $('#yvoDokiAddParticipantPanel .yvo-fp-add-contributor, #yvo-fp-add-participant-menu .yvo-fp-add-contributor').closest('button').toggle(show);
    }

    function yvoDokiApplyStepIndex(i) {
        if (i < 3) {
            yvoDokiFilterParticipantTabsForStep(i);
            var targetTab = yvoDokiFirstTabIdForStep(i);
            var $cur = yvoDokiFpTabStrip().find('.yvo-fp-tab.active').first();
            var curId = $cur.length ? $cur.attr('data-tab') : null;
            var curStep = yvoDokiTabToStepIndex(curId);
            if (targetTab && (curStep !== i || !$('#yvo-fp-panel-' + targetTab).hasClass('active'))) {
                if (typeof window.yvoFpActivateParticipantTab === 'function') {
                    window.yvoFpActivateParticipantTab(targetTab, { skipDokiStepSync: true });
                } else if ($('#yvo-fp-panel-' + targetTab).length) {
                    yvoDokiFpTabStrip().find('.yvo-fp-tab[data-tab="' + targetTab + '"]').first().trigger('click');
                }
            }
            yvoDokiSyncStepChrome(i, targetTab);
            yvoDokiScroll('.yvo-doki-steps-toolbar');
        } else {
            $('.yvo-doki-form-skin').attr('data-yvo-doki-step', String(i));
            $steps.find('.step-pill').removeClass('active').attr('aria-selected', 'false');
            $steps.find('.step-pill[data-yvo-step-index="' + i + '"]').addClass('active').attr('aria-selected', 'true');
            yvoDokiSetFooter(i);
            $('.yvo-doki-autofill').attr('hidden', true);
            yvoDokiScroll('#yvo-fp-section-generate');
            setTimeout(function() {
                yvoDokiFilterParticipantTabsForStep(i);
                yvoDokiSyncContributorAddButtonVisibility();
            }, 0);
        }
    }

    $(document).on('click', '.yvo-doki-steps .step-pill', function(e) {
        e.preventDefault();
        var i = parseInt($(this).attr('data-yvo-step-index'), 10);
        if (isNaN(i)) {
            return;
        }
        yvoDokiApplyStepIndex(i);
    });

    // Переключение панелей — в frontend.js (yvoFpActivateParticipantTab); здесь только подстраховка.
    $(document).on('click', '#yvo-fp-participant-tabs .yvo-fp-tab, .yvo-frontend-page .yvo-fp-tabs-wrap > .yvo-fp-tabs .yvo-fp-tab', function() {
        if ($(this).hasClass('yvo-doki-tab--filtered-out')) {
            return;
        }
        var t = $(this).attr('data-tab');
        if (typeof window.yvoFpActivateParticipantTab !== 'function' && t) {
            var i = yvoDokiTabToStepIndex(t);
            if (i >= 0 && i < 3) {
                yvoDokiSyncStepChrome(i, t);
            }
        }
    });

    $(document).on('click', '#yvoDokiParticipantCardMenu', function(e) {
        e.preventDefault();
        var $card = $(this).closest('.yvo-doki-participant-card');
        $card.toggleClass('yvo-doki-participant-card--collapsed');
        var collapsed = $card.hasClass('yvo-doki-participant-card--collapsed');
        $(this).attr('aria-expanded', collapsed ? 'false' : 'true');
    });

    $('#yvoDokiStepPrev').on('click', function() {
        var cur = $steps.find('.step-pill.active').attr('data-yvo-step-index');
        cur = parseInt(cur, 10);
        if (isNaN(cur) || cur <= 0) {
            return;
        }
        yvoDokiApplyStepIndex(cur - 1);
    });
    $('#yvoDokiStepNext').on('click', function() {
        var cur = $steps.find('.step-pill.active').attr('data-yvo-step-index');
        cur = parseInt(cur, 10);
        if (isNaN(cur)) {
            return;
        }
        if (cur >= 3) {
            return;
        }
        yvoDokiApplyStepIndex(cur + 1);
    });

    function yvoDokiUploadClick() {
        // Важно: выбор файла — сразу в обработчике клика (без setTimeout), иначе браузер блокирует dialog.
        if (typeof window.yvoFpOpenFilePicker === 'function') {
            window.yvoFpOpenFilePicker();
        } else {
            var f = document.getElementById('yvo-fp-file');
            if (f) {
                f.click();
            }
        }
        yvoDokiScroll('#yvo-fp-forms-section');
    }

    $(document).on('click', '.yvo-doki-autofill-upload', function(e) {
        e.preventDefault();
        if (typeof window.yvoFpGuardPaidFeature === 'function' && window.yvoFpGuardPaidFeature('autofill')) {
            return;
        }
        var tab = $(this).closest('.yvo-doki-autofill').attr('data-yvo-autofill-tab') || 'seller';
        var map = { seller: 'seller', buyer: 'buyer', property: 'property' };
        var uf = map[tab] || 'seller';
        $('#yvo-fp-upload-for').val(uf);
        yvoDokiUploadClick();
    });
    $(document).on('click', '.yvo-doki-autofill-manual', function(e) {
        e.preventDefault();
        var $p = $('.yvo-fp-panel.active');
        if ($p.length) {
            var el = $p.find('input[data-key], textarea[data-key]').filter(':visible').first();
            if (el.length) {
                el[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.focus();
            }
        }
    });

    yvoDokiSetFooter(0);
    yvoDokiShowAutofill('seller');
    yvoDokiRefreshStepLabelsForContract();
    yvoDokiUpdateParticipantCardTitle();
    if (typeof window.yvoDokiRefreshParticipantTabStrip === 'function') {
        window.yvoDokiRefreshParticipantTabStrip();
    }
    setTimeout(function() {
        yvoDokiFilterParticipantTabsForStep(yvoDokiGetCurrentStepIndex());
        yvoDokiSyncContributorAddButtonVisibility();
        yvoDokiRefreshAutofillUploadLabels();
        yvoDokiSyncMobileTypeLabel();
    }, 0);

    $(document).on('yvo-doki-participant-added', function() {
        setTimeout(function() {
            yvoDokiUpdateParticipantCardTitle();
            yvoDokiFilterParticipantTabsForStep(yvoDokiGetCurrentStepIndex());
            yvoDokiSyncContributorAddButtonVisibility();
        }, 0);
    });

    window.yvoDokiTabToStepIndex = yvoDokiTabToStepIndex;
    window.yvoDokiApplyStepIndex = yvoDokiApplyStepIndex;
    window.yvoDokiGetCurrentStepIndex = yvoDokiGetCurrentStepIndex;
    window.yvoDokiUpdateParticipantCardTitle = yvoDokiUpdateParticipantCardTitle;
    window.yvoDokiFilterParticipantTabsForStep = yvoDokiFilterParticipantTabsForStep;
    window.yvoDokiSyncStepChrome = yvoDokiSyncStepChrome;
    window.yvoDokiFirstTabIdForStep = yvoDokiFirstTabIdForStep;
    window.yvoDokiSyncMobileTypeLabel = yvoDokiSyncMobileTypeLabel;
});
