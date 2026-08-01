/**
 * Фронтенд: загрузка документов, OCR, парсинг, генерация договора на отдельной странице
 */
(function($) {
    'use strict';

    /** Метка сборки: в консоли F12 выполните window.YVO_FP_BUILD — если undefined или старое значение, грузится не та копия frontend.js */
    window.YVO_FP_BUILD = 'dkp-obychnyy-sber-20260731';

    var YVO_FP_REG_SYNC_KEYS = ['property_right_info', 'ownership_basis_documents', 'property_right_date'];
    var yvoFpRegSyncLock = false;
    /** Защита от рекурсии при синхронизации долей (таблица ↔ карточки участников). */
    var yvoFpGiftShareSyncLock = 0;

    function yvoFpSyncRegFields(sourceEl) {
        if (yvoFpRegSyncLock) return;
        var key = $(sourceEl).data('key');
        if (YVO_FP_REG_SYNC_KEYS.indexOf(key) === -1) return;
        var val = $(sourceEl).val();
        yvoFpRegSyncLock = true;
        $('.yvo-fp-reg-sync[data-key="' + key + '"]').not(sourceEl).val(val);
        yvoFpRegSyncLock = false;
        yvoFpUpdateRegFieldsMissingState();
    }

    function yvoFpUpdateRegFieldsMissingState() {
        $('.yvo-fp-reg-sync').each(function() {
            var empty = String($(this).val() || '').trim() === '';
            $(this).closest('.yvo-fp-field').toggleClass('yvo-fp-field-missing', empty);
        });
    }

    $(document).on('input change', '.yvo-fp-reg-sync', function() {
        yvoFpSyncRegFields(this);
    });

    function yvoFpLooksLikeEgrn(text) {
        var t = String(text || '');
        return /кадастров|егрн|единый\s+государственн|02:\d{2}:\d{6,}|02-\d{2}-\d{6,}\/\d{4}/i.test(t);
    }

    function yvoFpMergeRegFieldsIntoForm(prop) {
        if (!prop) return;
        YVO_FP_REG_SYNC_KEYS.forEach(function(k) {
            var v = prop[k];
            if (v === undefined || v === null || String(v).trim() === '') return;
            yvoFpRegSyncLock = true;
            $('.yvo-fp-reg-sync[data-key="' + k + '"]').val(String(v).trim());
            yvoFpRegSyncLock = false;
        });
        yvoFpUpdateRegFieldsMissingState();
    }

    function yvoFpExtractEgrnFromText(text, callback) {
        if (!text || String(text).length < 200) {
            if (callback) callback(null);
            return;
        }
        if (typeof yvo_frontend_ajax === 'undefined' || !yvo_frontend_ajax.ajax_url) {
            if (callback) callback(null);
            return;
        }
        $.ajax({
            url: yvo_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'yvo_extract_egrn_data',
                nonce: yvo_frontend_ajax.nonce,
                text: text,
                local_only: '0',
                quality: '1'
            },
            dataType: 'json',
            timeout: 60000
        }).done(function(res) {
            if (res && res.success && res.data && res.data.extracted_data) {
                if (callback) callback(res.data.extracted_data);
            } else if (callback) {
                callback(null);
            }
        }).fail(function() {
            if (callback) callback(null);
        });
    }

    function yvoFpFinishDocumentStudy(statusMsg, isError) {
        var $st = $('#yvo-fp-parse-status');
        if (!$st.length) return;
        $st.show().text(statusMsg).removeClass('error ok').addClass(isError ? 'error' : 'ok');
        if (!isError) {
            setTimeout(function() { $st.fadeOut(); }, 6000);
        }
    }

    function yvoFpApplyEgrnExtracted(ed, text) {
        if (!ed) return false;
        var fillFormFn = window.yvoFpFillForm;
        var prepFn = window.yvoFpPrepareParsedForFormFn;
        var fillEgrnFn = window.yvoFpFillEgrnCheckBlockFn;
        try {
            var hasProp = !!(ed.property && typeof ed.property === 'object' && Object.keys(ed.property).length);
            var hasPersons = !!(ed.participants && ed.participants.length);
            var hasCheck = !!(ed.egrn_check && typeof ed.egrn_check === 'object' && Object.keys(ed.egrn_check).length);
            if (hasProp) {
                yvoFpApplyExtractedProperty($.extend({}, ed.property), ed.egrn_check || null);
            } else if (hasCheck) {
                if (typeof fillEgrnFn === 'function') {
                    fillEgrnFn(ed.egrn_check);
                }
                yvoFpApplyMortgageFromEgrnCheck(ed.egrn_check);
            }
            if (typeof window.yvoFpFillOwnershipHistoryFn === 'function') {
                window.yvoFpFillOwnershipHistoryFn(ed.ownership_history || []);
            }
            return !!(hasProp || hasPersons || hasCheck || (ed.ownership_history && ed.ownership_history.length));
        } catch (e) {
            if (typeof console !== 'undefined' && console.error) {
                console.error('yvoFpApplyEgrnExtracted', e);
            }
            return false;
        }
    }

    var yvoFpExtractedSummaryCache = { persons: [], property: null, property_label: '' };
    var yvoFpExtractedClipboard = null;

    function yvoFpFormatShortName(fullName) {
        var name = String(fullName || '').trim().replace(/\s+/g, ' ');
        if (!name) return '';
        if (/^(?:ООО|ОАО|ПАО|АО|ЗАО|НАО|НКО|ГУП|МУП|ФГУП|ИП\b|Акционерное\s+общество|Общество\s+с\s+ограниченной|Публичное\s+акционерное|Непубличное\s+акционерное)/i.test(name)) {
            return name.length > 72 ? (name.slice(0, 69) + '…') : name;
        }
        var parts = name.split(/\s+/);
        if (parts.length >= 3) {
            return parts[0] + ' ' + (parts[1].charAt(0) || '') + '.' + (parts[2].charAt(0) || '') + '.';
        }
        if (parts.length === 2) {
            return parts[0] + ' ' + (parts[1].charAt(0) || '') + '.';
        }
        return name;
    }

    function yvoFpShowExtractedMsg(msg, isError) {
        if (typeof window.yvoFpShowError === 'function' && isError) {
            window.yvoFpShowError(msg);
            return;
        }
        if (typeof window.yvoFpShowToastOk === 'function' && !isError) {
            window.yvoFpShowToastOk(msg);
            return;
        }
        var $err = $('#yvo-fp-error');
        if ($err.length) {
            $err.toggleClass('yvo-fp-toast-success', !isError).text(msg).show();
        }
    }

    function yvoFpQueryParticipantTabButtons() {
        if (typeof window.yvoFpParticipantTabButtons === 'function') {
            var $fromFn = window.yvoFpParticipantTabButtons();
            if ($fromFn && $fromFn.length) {
                return $fromFn;
            }
        }
        var $tabs = $('#yvo-fp-participant-tabs .yvo-fp-tab');
        if ($tabs.length) {
            return $tabs;
        }
        return $('.yvo-fp-tabs-wrap > .yvo-fp-tabs .yvo-fp-tab');
    }

    function yvoFpLabelForParticipantTab(id) {
        id = String(id || '').trim();
        if (!id) {
            return '';
        }
        var $btn = yvoFpQueryParticipantTabButtons().filter('[data-tab="' + id + '"]');
        var label = $btn.length ? $.trim($btn.first().text()) : '';
        if (label) {
            return label;
        }
        if (typeof window.yvoFpBuildParticipantTabLabelFn === 'function') {
            return window.yvoFpBuildParticipantTabLabelFn(id, {});
        }
        if (/^seller\d*$/.test(id)) {
            var ns = id === 'seller' ? 1 : (parseInt(id.replace('seller', ''), 10) || 1);
            return 'Участник ' + ns;
        }
        if (/^buyer\d*$/.test(id)) {
            var nb = id === 'buyer' ? 1 : (parseInt(id.replace('buyer', ''), 10) || 1);
            return 'Покупатель' + (nb > 1 ? ' ' + nb : '');
        }
        if (/^seller_representative/.test(id)) {
            return 'Довер. продавца';
        }
        if (/^buyer_representative/.test(id)) {
            return 'Довер. покупателя';
        }
        return id;
    }

    /** Открытые вкладки участников (доступна до полной инициализации формы). */
    function yvoFpBuildExtractedParticipantOptions() {
        var roleOptions = [];
        var seen = {};
        yvoFpQueryParticipantTabButtons().each(function() {
            var id = $(this).attr('data-tab');
            if (!id || id === 'property' || seen[id]) {
                return;
            }
            seen[id] = true;
            roleOptions.push({ id: id, label: yvoFpLabelForParticipantTab(id) });
        });
        if (!roleOptions.length) {
            $('#yvo-fp-tab-panels .yvo-fp-panel, .yvo-fp-tab-panels .yvo-fp-panel').each(function() {
                var fullId = this.id || '';
                var pre = 'yvo-fp-panel-';
                if (fullId.indexOf(pre) !== 0) {
                    return;
                }
                var id = fullId.slice(pre.length);
                if (!id || id === 'property' || seen[id]) {
                    return;
                }
                seen[id] = true;
                roleOptions.push({ id: id, label: yvoFpLabelForParticipantTab(id) });
            });
        }
        return roleOptions;
    }

    function yvoFpRefreshExtractedParticipantTargets() {
        if (!yvoFpExtractedSummaryCache || !(yvoFpExtractedSummaryCache.persons || []).length) {
            return;
        }
        var opts = yvoFpBuildExtractedParticipantOptions();
        var hasTabs = opts.length > 0;
        var $warn = $('#yvo-fp-extracted-warn');
        if (hasTabs) {
            $warn.remove();
        } else if (!$warn.length) {
            $('#yvo-fp-extracted-list').prepend(
                '<div class="yvo-fp-extracted-warn" id="yvo-fp-extracted-warn">'
                + 'Сначала добавьте участников через кнопку «Добавить участника», затем нажмите нужную кнопку вкладки.'
                + '</div>'
            );
        }
        $('.yvo-fp-extracted-row--person').each(function() {
            var personData = $(this).data('extractedPerson');
            yvoFpFillExtractedTargetButtons($(this).find('.yvo-fp-extracted-targets'), personData, opts, hasTabs);
        });
    }

    function yvoFpFillExtractedTargetButtons($container, personData, participantOptions, hasParticipantTabs) {
        if (!$container.length) {
            return;
        }
        $container.empty();
        if (!hasParticipantTabs || !participantOptions.length) {
            $container.append('<span class="yvo-fp-extracted-no-tabs">Сначала добавьте участников</span>');
            return;
        }
        participantOptions.forEach(function(opt) {
            var $btn = $('<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-extracted-target-btn"></button>')
                .text(opt.label)
                .attr('data-target-tab', opt.id)
                .attr('title', 'Подставить в «' + opt.label + '»');
            $btn.on('click', function() {
                yvoFpApplyExtractedPersonToTab(opt.id, personData, 'Данные подставлены');
                $container.find('.yvo-fp-extracted-target-btn').removeClass('is-active');
                $btn.addClass('is-active');
            });
            $container.append($btn);
        });
    }

    function yvoFpRefreshExtractedTabSelects() {
        yvoFpRefreshExtractedParticipantTargets();
    }
    window.yvoFpBuildExtractedParticipantOptions = yvoFpBuildExtractedParticipantOptions;
    window.yvoFpRefreshExtractedTabSelects = yvoFpRefreshExtractedTabSelects;
    window.yvoFpRefreshExtractedParticipantTargets = yvoFpRefreshExtractedParticipantTargets;

    function yvoFpExtractedRoleButtons() {
        var labs = (typeof yvoParticipantRoleShortLabels === 'function') ? yvoParticipantRoleShortLabels() : {};
        var isGift = (typeof currentContractType !== 'undefined' && currentContractType === 'gift');
        return [
            { role: 'seller', label: labs.seller || (isGift ? 'Даритель' : 'Продавец') },
            { role: 'buyer', label: labs.buyer || (isGift ? 'Одаряемый' : 'Покупатель') },
            { role: 'seller_representative', label: isGift ? 'Предст. дарителя' : 'Довер. продавца' },
            { role: 'buyer_representative', label: isGift ? 'Предст. одаряемого' : 'Довер. покупателя' }
        ];
    }

    function yvoFpTabForExtractedRoleFill(roleKey) {
        var parseFn = window.yvoFpTabIdAndMinorOptsFromCompositeRoleFn;
        var parsed = (typeof parseFn === 'function')
            ? parseFn(roleKey)
            : { tabId: String(roleKey || 'seller'), minorOpts: undefined };
        var role = parsed.tabId;
        if ($('#yvo-fp-panel-' + role).length) {
            return role;
        }
        if (/^seller/.test(role)) {
            var firstSeller = window.yvoFpFirstSellerSideTabFn;
            if (typeof firstSeller === 'function') {
                var sid = firstSeller(null);
                if (sid && $('#yvo-fp-panel-' + sid).length) {
                    return sid;
                }
            }
        }
        if (/^buyer/.test(role)) {
            var firstBuyer = window.yvoFpFirstBuyerSideTabFn;
            if (typeof firstBuyer === 'function') {
                var bid = firstBuyer(null);
                if (bid && $('#yvo-fp-panel-' + bid).length) {
                    return bid;
                }
            }
        }
        if (roleKey === 'seller_representative' || roleKey === 'buyer_representative' || roleKey === 'contributor') {
            if ($('#yvo-fp-panel-' + roleKey).length) {
                return roleKey;
            }
        }
        var ensureTab = window.yvoFpEnsureParticipantTab;
        if (typeof ensureTab === 'function') {
            return ensureTab(roleKey, parsed.minorOpts ? parsed.minorOpts.minorAgeGroup : undefined);
        }
        return null;
    }

    function yvoFpApplyMortgageFromEgrnCheck(check) {
        if (!check || String(check.has_mortgage || '').toLowerCase() !== 'да') {
            return;
        }
        var $pt = $('#property_payment_type');
        if ($pt.length) {
            $pt.val('mortgage').trigger('change');
        }
        var bankRaw = String(check.bank_info || check.bank_name || '').trim();
        if (!bankRaw) {
            return;
        }
        var bank = bankRaw.replace(/,\s*ИНН[\s\S]*$/i, '').trim();
        var m = bank.match(/«([^»]+)»/);
        if (m) {
            bank = 'ПАО ' + m[1];
        }
        $('[data-key="bank_name"]').each(function() {
            if (!String($(this).val() || '').trim()) {
                $(this).val(bank);
            }
        });
    }

    function yvoFpApplyExtractedPersonToTab(tabCompositeId, personData, toastPrefix) {
        if (!personData || !tabCompositeId) return false;
        var fillFormFn = window.yvoFpFillForm;
        var prepFn = window.yvoFpPrepareParsedForFormFn;
        var parseFn = window.yvoFpTabIdAndMinorOptsFromCompositeRoleFn;
        if (typeof fillFormFn !== 'function' || typeof prepFn !== 'function' || typeof parseFn !== 'function') {
            return false;
        }
        var parsed = parseFn(tabCompositeId);
        if (!parsed || !parsed.tabId) return false;
        if (!$('#yvo-fp-panel-' + parsed.tabId).length) {
            var ensureById = window.yvoFpEnsureParticipantTabById;
            if (typeof ensureById === 'function') {
                ensureById(parsed.tabId, parsed.minorOpts);
            }
        }
        if (!$('#yvo-fp-panel-' + parsed.tabId).length) {
            yvoFpShowExtractedMsg('Не найдена форма участника «' + parsed.tabId + '». Добавьте участника.', true);
            return false;
        }
        var data = prepFn(personData);
        fillFormFn(parsed.tabId, data);
        var tabId = parsed.tabId;
        if ($('.yvo-doki-form-skin').length && typeof window.yvoFpDokiFocusParticipantFn === 'function') {
            window.yvoFpDokiFocusParticipantFn(tabId);
        } else if (typeof window.yvoFpActivateParticipantTab === 'function') {
            window.yvoFpActivateParticipantTab(tabId);
        } else {
            $('.yvo-fp-tab').removeClass('active');
            $('.yvo-fp-panel').removeClass('active');
            yvoFpQueryParticipantTabButtons().filter('[data-tab="' + tabId + '"]').addClass('active');
            $('#yvo-fp-panel-' + tabId).addClass('active');
        }
        if (typeof window.yvoFpUpdateDeleteButtonVisibilityFn === 'function') {
            window.yvoFpUpdateDeleteButtonVisibilityFn();
        }
        var tabLabel = yvoFpLabelForParticipantTab(tabId);
        if (typeof window.yvoFpShowToastOk === 'function') {
            var msg = (toastPrefix || 'Данные подставлены') + (tabLabel ? ': «' + tabLabel + '»' : '');
            window.yvoFpShowToastOk(msg);
        }
        if (typeof window.yvoFpRunFieldReview === 'function') {
            window.yvoFpRunFieldReview(tabId);
        }
        return true;
    }

    function yvoFpApplyExtractedPersonRole(roleKey, personData, targetTabOverride) {
        if (!personData || !roleKey) return;
        var tabCompositeId = String(targetTabOverride || '').trim();
        if (!tabCompositeId) {
            yvoFpShowExtractedMsg('Выберите вкладку участника (Уч. 1, Уч. 2 …) в списке.', true);
            return;
        }
        yvoFpApplyExtractedPersonToTab(tabCompositeId, personData, 'Данные подставлены');
    }

    function yvoFpCopyExtractedPerson(personData) {
        var prepFn = window.yvoFpPrepareParsedForFormFn;
        if (!personData || typeof prepFn !== 'function') return;
        yvoFpExtractedClipboard = prepFn(personData);
        if (typeof window.yvoFpShowToastOk === 'function') {
            window.yvoFpShowToastOk('Данные скопированы — нажмите кнопку участника или «Вставить в открытую вкладку»');
        }
    }

    function yvoFpApplyExtractedProperty(prop, egrnCheck) {
        var fillFormFn = window.yvoFpFillForm;
        var fillAddrFn = window.yvoFpFillAddressFromFullFn;
        var fillEgrnFn = window.yvoFpFillEgrnCheckBlockFn;
        if (!prop || typeof fillFormFn !== 'function') return;
        if (egrnCheck && egrnCheck.basis_documents && !prop.ownership_basis_documents) {
            prop.ownership_basis_documents = String(egrnCheck.basis_documents).trim();
        } else if (egrnCheck && egrnCheck.basis_documents && prop.ownership_basis_documents) {
            var basis = String(prop.ownership_basis_documents);
            String(egrnCheck.basis_documents).split(',').forEach(function(part) {
                part = String(part || '').trim();
                if (part && basis.indexOf(part) === -1) {
                    basis = basis ? (basis + ', ' + part) : part;
                }
            });
            prop.ownership_basis_documents = basis;
        }
        if (egrnCheck && egrnCheck.bank_info && !prop.bank_name) {
            var bankRaw = String(egrnCheck.bank_info).replace(/,\s*ИНН[\s\S]*$/i, '').trim();
            var bm = bankRaw.match(/«([^»]+)»/);
            prop.bank_name = bm ? ('ПАО ' + bm[1]) : bankRaw;
        }
        fillFormFn('property', prop);
        if (prop.area != null && String(prop.area).trim() !== '') {
            var areaVal = String(prop.area).replace(',', '.');
            $('#yvo-fp-panel-property .yvo-fp-object-type-block.active [data-key="area"]').val(areaVal);
            if (!String($('#property_share_calc_area').val() || '').trim()) {
                $('#property_share_calc_area').val(areaVal);
            }
        }
        yvoFpMergeRegFieldsIntoForm(prop);
        if (typeof fillAddrFn === 'function') {
            fillAddrFn();
        }
        if (egrnCheck) {
            if (typeof fillEgrnFn === 'function') {
                fillEgrnFn(egrnCheck);
            }
            yvoFpApplyMortgageFromEgrnCheck(egrnCheck);
        }
        if ($('.yvo-doki-form-skin').length && typeof window.yvoFpDokiFocusParticipantFn === 'function') {
            window.yvoFpDokiFocusParticipantFn('property');
        } else {
            $('.yvo-fp-tab').removeClass('active');
            $('.yvo-fp-panel').removeClass('active');
            $('.yvo-fp-tab[data-tab="property"]').addClass('active');
            $('#yvo-fp-panel-property').addClass('active');
        }
        if (typeof window.yvoFpRunFieldReview === 'function') {
            window.yvoFpRunFieldReview('property');
        }
    }

    function yvoFpRenderExtractedSummary(summary) {
        var $box = $('#yvo-fp-extracted-summary');
        var $list = $('#yvo-fp-extracted-list');
        if (!$box.length || !$list.length) return;
        $list.empty();
        if (!summary || ((!summary.persons || !summary.persons.length) && !summary.property_label)) {
            $box.hide();
            return;
        }
        yvoFpExtractedSummaryCache = summary;
        var participantOptions = yvoFpBuildExtractedParticipantOptions();
        var hasParticipantTabs = participantOptions.length > 0;

        if ((summary.persons || []).length && !hasParticipantTabs) {
            $list.append(
                '<div class="yvo-fp-extracted-warn" id="yvo-fp-extracted-warn">'
                + 'Сначала добавьте участников через кнопку «Добавить участника», затем нажмите кнопку нужной вкладки.'
                + '</div>'
            );
        }

        if (summary.property_label) {
            var $obj = $('<div class="yvo-fp-extracted-row yvo-fp-extracted-row--object"></div>');
            $obj.append('<span class="yvo-fp-extracted-label">Объект:</span>');
            $obj.append('<span class="yvo-fp-extracted-name">' + $('<div>').text(summary.property_label).html() + '</span>');
            var $objAct = $('<div class="yvo-fp-extracted-actions"></div>');
            var $useObj = $('<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-extracted-use-object">Использовать</button>');
            $useObj.on('click', function() {
                if (summary.property) yvoFpApplyExtractedProperty(summary.property, summary.egrn_check);
            });
            $objAct.append($useObj);
            $obj.append($objAct);
            $list.append($obj);
        }

        (summary.persons || []).forEach(function(person, idx) {
            var shortName = person.short_name || yvoFpFormatShortName(person.full_name);
            var personData = person.data || person;
            var status = person.ownership_status || (personData && personData.ownership_status) || '';
            var $row = $('<div class="yvo-fp-extracted-row yvo-fp-extracted-row--person"></div>');
            if (status === 'current') {
                $row.addClass('is-current-owner');
            } else if (status === 'previous') {
                $row.addClass('is-previous-owner');
            }
            $row.data('extractedPerson', personData);
            var $nameWrap = $('<span class="yvo-fp-extracted-name-wrap"></span>');
            $nameWrap.append('<span class="yvo-fp-extracted-name">' + $('<div>').text(shortName || ('Участник ' + (idx + 1))).html() + '</span>');
            if (status === 'current') {
                $nameWrap.append('<span class="yvo-fp-extracted-owner-badge is-current">Текущий</span>');
            } else if (status === 'previous') {
                $nameWrap.append('<span class="yvo-fp-extracted-owner-badge is-previous">История</span>');
            }
            var isLegal = !!(personData && (personData.is_legal_entity === '1' || personData.is_legal_entity === 1 || personData.is_legal_entity === true || personData.person_type === 'legal_entity'));
            if (isLegal) {
                $nameWrap.append('<span class="yvo-fp-extracted-owner-badge is-legal">Юрлицо</span>');
            }
            $row.append($nameWrap);
            if (person.hint) {
                $row.append('<span class="yvo-fp-extracted-meta">' + $('<div>').text(person.hint).html() + '</span>');
            }
            var $targets = $('<div class="yvo-fp-extracted-targets"></div>');
            yvoFpFillExtractedTargetButtons($targets, personData, participantOptions, hasParticipantTabs);
            $row.append($targets);
            var $actions = $('<div class="yvo-fp-extracted-actions"></div>');
            var $copyBtn = $('<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-extracted-copy">Копировать</button>');
            $copyBtn.on('click', function() {
                yvoFpCopyExtractedPerson(personData);
            });
            $actions.append($copyBtn);
            $row.append($actions);
            $list.append($row);
        });

        if ((summary.persons || []).length && hasParticipantTabs) {
            var $tools = $('<div class="yvo-fp-extracted-tools"></div>');
            var $pasteActive = $('<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-extracted-paste-active">Вставить в открытую вкладку</button>');
            $pasteActive.on('click', function() {
                if (!yvoFpExtractedClipboard) {
                    yvoFpShowExtractedMsg('Сначала нажмите «Копировать» у нужного участника.', true);
                    return;
                }
                var tab = '';
                if (typeof window.yvoFpGetActiveParticipantTab === 'function') {
                    tab = window.yvoFpGetActiveParticipantTab();
                }
                if (!tab || tab === 'property') {
                    yvoFpShowExtractedMsg('Переключитесь на вкладку участника (Уч. 1, Уч. 2 …).', true);
                    return;
                }
                yvoFpApplyExtractedPersonToTab(tab, yvoFpExtractedClipboard, 'Данные вставлены');
            });
            $tools.append($pasteActive);
            $list.append($tools);
        }

        $box.show();
        if (summary.property) {
            yvoFpApplyExtractedProperty(summary.property, summary.egrn_check);
        } else if (summary.egrn_check && typeof window.yvoFpFillEgrnCheckBlockFn === 'function') {
            window.yvoFpFillEgrnCheckBlockFn(summary.egrn_check);
            yvoFpApplyMortgageFromEgrnCheck(summary.egrn_check);
        }
        if (typeof window.yvoFpFillOwnershipHistoryFn === 'function') {
            window.yvoFpFillOwnershipHistoryFn(summary.ownership_history || []);
        }
    }

    function yvoFpRefreshExtractedSummary(text) {
        text = String(text || '').trim();
        if (typeof window.yvoFpSetLastOcrText === 'function') {
            window.yvoFpSetLastOcrText(text);
        } else if (text) {
            window.yvoFpLastOcrText = text;
        }
        var $box = $('#yvo-fp-extracted-summary');
        var $list = $('#yvo-fp-extracted-list');
        if (!$box.length || text.length < 20) {
            if ($box.length) $box.hide();
            return;
        }
        $box.show();
        $list.html('<div class="yvo-fp-extracted-loading">Анализ документа…</div>');
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_frontend_extract_summary',
            nonce: yvo_frontend_ajax.nonce,
            text: text
        }, 'json').done(function(res) {
            if (res.success && res.data) {
                yvoFpRenderExtractedSummary(res.data);
            } else {
                $box.hide();
            }
        }).fail(function() {
            $box.hide();
        });
    }

    /** ДОКИ: при 4+ вкладках показываем строку участников (иначе она спрятана как дубль шагов) */
    function yvoFpSyncDokiParticipantTabStrip() {
        if (!$('.yvo-doki-form-skin').length) return;
        var $tabs = $('#yvo-fp-participant-tabs');
        if (!$tabs.length) {
            $tabs = $('.yvo-frontend-page .yvo-fp-tabs-wrap > .yvo-fp-tabs').first();
        }
        if (!$tabs.length) {
            $tabs = $('.yvo-fp-tabs-wrap > .yvo-fp-tabs').first();
        }
        if (!$tabs.length) return;
        var n = $tabs.find('.yvo-fp-tab').length;
        var $wrap = $tabs.closest('.yvo-fp-tabs-wrap');
        if (n > 3) {
            $tabs.addClass('yvo-doki-tabs-multi yvo-doki-tabs-reveal').removeClass('yvo-doki-tabs-hidden');
            $wrap.addClass('yvo-doki-tabs-wrap--multi');
            $tabs.css({
                display: 'flex',
                flexWrap: 'wrap',
                position: 'relative',
                width: '100%',
                height: 'auto',
                overflow: 'visible',
                clip: 'auto',
                visibility: 'visible',
                opacity: 1
            });
            var $hint = $('#yvoDokiMultiParticipantHint');
            if ($hint.length) {
                $hint.removeAttr('hidden');
            }
            try {
                var el = document.getElementById('yvo-fp-participant-tabs');
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            } catch (err) {}
        } else {
            $tabs.removeClass('yvo-doki-tabs-multi yvo-doki-tabs-reveal').addClass('yvo-doki-tabs-hidden');
            $wrap.removeClass('yvo-doki-tabs-wrap--multi');
            $tabs.removeAttr('style');
            var $hintHide = $('#yvoDokiMultiParticipantHint');
            if ($hintHide.length) {
                $hintHide.attr('hidden', true);
            }
        }
    }
    window.yvoDokiRefreshParticipantTabStrip = yvoFpSyncDokiParticipantTabStrip;
    $(document).on('yvo-doki-participant-added', yvoFpSyncDokiParticipantTabStrip);
    $(document).on('yvo-doki-participant-added', function() {
        yvoFpRefreshExtractedParticipantTargets();
    });

    $(function() {
    try {

    var $dropzone = $('#yvo-fp-dropzone');
    var $fileInput = $('#yvo-fp-file');
    var $progress = $('#yvo-fp-progress');
    var $resultSection = $('#yvo-fp-result-section');
    var $text = $('#yvo-fp-text');
    var $parseStatus = $('#yvo-fp-parse-status');
    var $generateBtn = $('#yvo-fp-generate-btn');
    var $generateProgress = $('#yvo-fp-generate-progress');
    var $contractResult = $('#yvo-fp-contract-result');
    var $downloadLink = $('#yvo-fp-download-link');
    var $error = $('#yvo-fp-error');

    var isPro = !!(typeof yvo_frontend_ajax !== 'undefined' && yvo_frontend_ajax && yvo_frontend_ajax.is_pro);
    window.yvoIsPro = isPro;

    var yvoFpErrorFadeTimer = null;

    function yvoFpTariffInfo() {
        if (typeof yvo_frontend_ajax === 'undefined' || !yvo_frontend_ajax || !yvo_frontend_ajax.tariff) {
            return null;
        }
        return yvo_frontend_ajax.tariff;
    }

    function yvoFpIsFreeDkpOnly() {
        var t = yvoFpTariffInfo();
        return !!(t && parseInt(t.free_dkp_apartment_only, 10) === 1);
    }

    function yvoFpFreePlanBlocksAutofill() {
        var t = yvoFpTariffInfo();
        if (!t) {
            return false;
        }
        if (parseInt(t.free_no_autofill, 10) === 1) {
            return true;
        }
        if (parseInt(t.billing_enforced, 10) === 1 && parseInt(t.can_autofill, 10) !== 1) {
            return true;
        }
        return false;
    }

    function yvoFpFreePlanBlocksContractType(type) {
        if (!yvoFpIsFreeDkpOnly()) {
            return false;
        }
        type = String(type || '');
        // Бесплатно только обычный ДКП (не ипотека и не другие типы).
        return type !== 'sale';
    }

    function yvoFpFreePlanBlocksObjectType(type) {
        if (!yvoFpIsFreeDkpOnly()) {
            return false;
        }
        type = String(type || 'apartment');
        return type !== 'apartment';
    }

    function yvoFpPricingUrl() {
        var t = yvoFpTariffInfo() || {};
        return t.pricing_url || (typeof yvo_frontend_ajax !== 'undefined' && yvo_frontend_ajax.pricing_url) || '/?yvo_cabinet=pricing';
    }

    function yvoFpFreePlanTipMessage(type) {
        var kind = 'этот договор';
        if (type === 'deposit_agreement') {
            kind = 'договор задатка';
        } else if (type === 'advance_agreement') {
            kind = 'договор аванса';
        } else if (type === 'gift') {
            kind = 'договор дарения';
        } else if (type === 'share_allocation') {
            kind = 'соглашение о выделении долей';
        } else if (type === 'sale_mortgage') {
            kind = 'куплю-продажу с ипотекой';
        } else if (type === 'assignment') {
            kind = 'уступку прав';
        }
        return {
            text: 'На бесплатном тарифе можно сделать только договор купли-продажи квартиры. Чтобы оформить «' + kind + '», нужна оплата.',
            pricingUrl: yvoFpPricingUrl()
        };
    }

    function yvoFpTariffLockMessage(feature, detail) {
        var pricing = yvoFpPricingUrl();
        var title = 'Это платный тариф';
        var text = 'После оплаты вы сможете пользоваться всеми видами договоров и удобным заполнением по документам.';
        var benefit = 'Вы получите нужный тип договора и сэкономите время на заполнении.';
        var recommend = 'onetime';

        if (feature === 'contract') {
            var tip = yvoFpFreePlanTipMessage(detail);
            var label = 'Этот договор';
            if (detail === 'deposit_agreement') label = 'Договор задатка';
            else if (detail === 'advance_agreement') label = 'Договор аванса';
            else if (detail === 'gift') label = 'Договор дарения';
            else if (detail === 'share_allocation') label = 'Выделение долей';
            else if (detail === 'sale_mortgage') label = 'Купля-продажа с ипотекой';
            else if (detail === 'assignment') label = 'Уступка прав';
            title = label + ' — после оплаты';
            text = tip.text;
            benefit = 'Вы получите готовый документ нужного типа и сможете заполнять данные по фото паспорта и выписки — без ручного переписывания.';
            return { text: text, pricingUrl: tip.pricingUrl, title: title, benefit: benefit, recommend: 'onetime' };
        }
        if (feature === 'object') {
            var otLabel = (typeof objectTypeLabels !== 'undefined' && objectTypeLabels[detail]) ? objectTypeLabels[detail] : (detail || 'этот объект');
            title = '«' + otLabel + '» — после оплаты';
            text = 'На бесплатном тарифе доступна только квартира. Дом, участок, доля, комната и другие объекты открываются после оплаты.';
            benefit = 'Вы сможете оформить договор на любой тип недвижимости и сразу получить готовый текст.';
            return { text: text, pricingUrl: pricing, title: title, benefit: benefit, recommend: 'onetime' };
        }
        if (feature === 'autofill') {
            title = 'Заполнение по документам — после оплаты';
            text = 'На бесплатном тарифе поля заполняются вручную. После оплаты можно загрузить фото паспорта или выписки — сервис сам подставит данные в форму.';
            benefit = 'Вы экономите время и меньше ошибаетесь: не нужно переписывать серию, номер и адрес руками.';
            return { text: text, pricingUrl: pricing, title: title, benefit: benefit, recommend: 'onetime' };
        }
        if (feature === 'check') {
            title = 'Проверка договора — в подписке';
            text = 'Сервис проверит текст договора на типичные ошибки и подскажет, что поправить. Доступно на тарифах «Про» и «Бизнес».';
            benefit = 'Вы получите более спокойную сделку: меньше риска пропустить важную ошибку в тексте.';
            return { text: text, pricingUrl: pricing, title: title, benefit: benefit, recommend: 'pro' };
        }
        if (feature === 'generate') {
            var g = yvoFpFreePlanTipMessage(detail || currentContractType);
            return {
                text: g.text,
                pricingUrl: g.pricingUrl,
                title: 'Готовый договор — после оплаты',
                benefit: 'Оплатите один раз или оформите подписку — и продолжите с уже введённых данных.',
                recommend: 'onetime'
            };
        }
        return { text: text, pricingUrl: pricing, title: title, benefit: benefit, recommend: recommend };
    }

    function yvoFpEnsureUpgradeModal() {
        var $m = $('#yvo-fp-upgrade-modal');
        if ($m.length) {
            return $m;
        }
        var html = ''
            + '<div class="yvo-fp-modal yvo-fp-upgrade-modal" id="yvo-fp-upgrade-modal" role="dialog" aria-modal="true" aria-labelledby="yvo-fp-upgrade-title" style="display:none;">'
            + '  <div class="yvo-fp-modal-backdrop yvo-fp-upgrade-backdrop"></div>'
            + '  <div class="yvo-fp-modal-content yvo-fp-upgrade-content">'
            + '    <div class="yvo-fp-modal-header yvo-fp-upgrade-header">'
            + '      <div class="yvo-fp-upgrade-header-text">'
            + '        <p class="yvo-fp-upgrade-kicker" id="yvo-fp-upgrade-kicker">Платный тариф</p>'
            + '        <h3 class="yvo-fp-modal-title" id="yvo-fp-upgrade-title">Доступно после оплаты</h3>'
            + '      </div>'
            + '      <button type="button" class="yvo-fp-modal-close yvo-fp-upgrade-close" aria-label="Закрыть">&times;</button>'
            + '    </div>'
            + '    <div class="yvo-fp-modal-body yvo-fp-upgrade-body">'
            + '      <div class="yvo-fp-upgrade-promo" id="yvo-fp-upgrade-promo" style="display:none;"></div>'
            + '      <p class="yvo-fp-upgrade-lead" id="yvo-fp-upgrade-lead"></p>'
            + '      <p class="yvo-fp-upgrade-benefit" id="yvo-fp-upgrade-benefit"></p>'
            + '      <div class="yvo-fp-upgrade-plans" id="yvo-fp-upgrade-plans"></div>'
            + '      <p class="yvo-fp-upgrade-free-note">Сейчас бесплатно: договор купли-продажи квартиры, поля заполняете вручную.</p>'
            + '    </div>'
            + '    <div class="yvo-fp-upgrade-footer">'
            + '      <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-upgrade-dismiss">Остаться на бесплатном</button>'
            + '      <a class="yvo-fp-btn yvo-fp-btn-primary yvo-fp-upgrade-all-plans" id="yvo-fp-upgrade-all-plans" href="#">Все тарифы</a>'
            + '    </div>'
            + '  </div>'
            + '</div>';
        $('body').append(html);
        $m = $('#yvo-fp-upgrade-modal');
        $m.on('click', '.yvo-fp-upgrade-backdrop, .yvo-fp-upgrade-close, .yvo-fp-upgrade-dismiss', function(e) {
            e.preventDefault();
            yvoFpHideUpgradeModal();
        });
        $(document).on('keydown.yvoUpgradeModal', function(e) {
            if (e.key === 'Escape' && $m.is(':visible')) {
                yvoFpHideUpgradeModal();
            }
        });
        return $m;
    }

    function yvoFpHideUpgradeModal() {
        $('#yvo-fp-upgrade-modal').hide();
        $('body').removeClass('yvo-fp-upgrade-open');
    }

    function yvoFpFormatRub(n) {
        n = Math.round(Number(n) || 0);
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽';
    }

    function yvoFpShowUpgradeModal(feature, detail) {
        var info = yvoFpTariffLockMessage(feature, detail);
        var t = yvoFpTariffInfo() || {};
        var $m = yvoFpEnsureUpgradeModal();
        var pricing = info.pricingUrl || yvoFpPricingUrl();
        var recommend = info.recommend || 'onetime';
        var priceStd = t.price_standard || 200;
        var pricePro = t.price_pro || 690;
        var priceBiz = t.price_business || 1200;
        var creditsPro = t.credits_pro || 10;
        var creditsBiz = t.credits_business || 30;

        $('#yvo-fp-upgrade-title').text(info.title || 'Доступно на платном тарифе');
        $('#yvo-fp-upgrade-lead').text(info.text || '');
        $('#yvo-fp-upgrade-benefit').text(info.benefit || '');
        $('#yvo-fp-upgrade-all-plans').attr('href', pricing);

        var $promo = $('#yvo-fp-upgrade-promo');
        if (parseInt(t.promo_active, 10) === 1) {
            $('#yvo-fp-upgrade-kicker').text('Акция');
            $promo.html('<strong>' + (t.promo_title || 'Акция') + '</strong><span>' + (t.promo_note || 'Цены временно снижены.') + '</span>').show();
        } else {
            $('#yvo-fp-upgrade-kicker').text('Платный тариф');
            $promo.hide().empty();
        }

        var priceStdWas = t.price_standard_was || 0;
        var priceProWas = t.price_pro_was || 0;
        var priceBizWas = t.price_business_was || 0;
        var promoOn = parseInt(t.promo_active, 10) === 1;

        var plans = [
            {
                id: 'onetime',
                name: 'Разово',
                price: yvoFpFormatRub(priceStd),
                was: promoOn && priceStdWas ? yvoFpFormatRub(priceStdWas) : '',
                desc: 'Один готовый договор · любые типы · заполнение по фото документов',
                cta: 'Оплатить ' + yvoFpFormatRub(priceStd),
                href: pricing
            },
            {
                id: 'pro',
                name: 'Про',
                price: yvoFpFormatRub(pricePro) + '/мес',
                was: promoOn && priceProWas ? yvoFpFormatRub(priceProWas) + '/мес' : '',
                desc: creditsPro + ' договоров · проверка текста · заполнение по фото',
                cta: 'Выбрать Про',
                href: pricing
            },
            {
                id: 'business',
                name: 'Бизнес',
                price: yvoFpFormatRub(priceBiz) + '/мес',
                was: promoOn && priceBizWas ? yvoFpFormatRub(priceBizWas) + '/мес' : '',
                desc: creditsBiz + ' договоров · кабинет сделок · для риелторов',
                cta: 'Выбрать Бизнес',
                href: pricing
            }
        ];

        var $plans = $('#yvo-fp-upgrade-plans').empty();
        plans.forEach(function(p) {
            var isRec = p.id === recommend;
            var $card = $('<a/>', {
                href: p.href,
                class: 'yvo-fp-upgrade-plan' + (isRec ? ' is-recommended' : ''),
                html: ''
                    + (isRec ? '<span class="yvo-fp-upgrade-plan-badge">Подходит</span>' : '')
                    + '<span class="yvo-fp-upgrade-plan-name">' + p.name + '</span>'
                    + (p.was ? '<span class="yvo-fp-upgrade-plan-was">' + p.was + '</span>' : '')
                    + '<span class="yvo-fp-upgrade-plan-price">' + p.price + '</span>'
                    + '<span class="yvo-fp-upgrade-plan-desc">' + p.desc + '</span>'
                    + '<span class="yvo-fp-upgrade-plan-cta">' + p.cta + '</span>'
            });
            $plans.append($card);
        });

        // Лёгкий toast не показываем — модалка вместо него.
        hideError();
        $m.css('display', 'flex').show();
        $('body').addClass('yvo-fp-upgrade-open');
        try {
            $m.find('.yvo-fp-upgrade-close').trigger('focus');
        } catch (eFocus) {}
    }

    /** Показать окно тарифов и вернуть true, если действие нужно заблокировать. */
    function yvoFpGuardPaidFeature(feature, detail) {
        var blocked = false;
        if (feature === 'contract') {
            blocked = yvoFpFreePlanBlocksContractType(detail);
        } else if (feature === 'object') {
            blocked = yvoFpFreePlanBlocksObjectType(detail);
        } else if (feature === 'autofill') {
            blocked = yvoFpFreePlanBlocksAutofill();
        } else if (feature === 'check') {
            var t = yvoFpTariffInfo();
            blocked = !!(t && parseInt(t.billing_enforced, 10) === 1 && parseInt(t.can_contract_check, 10) !== 1);
        } else if (feature === 'generate') {
            blocked = yvoFpFreePlanBlocksContractType(detail || currentContractType)
                || yvoFpFreePlanBlocksObjectType(($('#property_object_type').val() || 'apartment'));
        }
        if (!blocked) {
            return false;
        }
        yvoFpShowUpgradeModal(feature, detail);
        return true;
    }

    /** Визуально пометить недоступные кнопки на бесплатном тарифе. */
    function yvoFpApplyFreePlanUiLocks() {
        var freeDkp = yvoFpIsFreeDkpOnly();
        var noAutofill = yvoFpFreePlanBlocksAutofill();
        var t = yvoFpTariffInfo();
        var noCheck = !!(t && parseInt(t.billing_enforced, 10) === 1 && parseInt(t.can_contract_check, 10) !== 1);

        $('.yvo-doki-type-pick, #yvo-fp-contract-type-menu .yvo-fp-dropdown-item').each(function() {
            var type = $(this).attr('data-contract-type') || $(this).data('contractType') || '';
            var lock = freeDkp && yvoFpFreePlanBlocksContractType(type);
            $(this).toggleClass('yvo-fp-tariff-locked', lock)
                .attr('aria-disabled', lock ? 'true' : 'false')
                .attr('title', lock ? 'Платный тариф — нажмите, чтобы посмотреть варианты' : null);
        });
        $('#yvo-fp-object-type-menu .yvo-fp-dropdown-item, .yvo-doki-object-type-pick').each(function() {
            var type = $(this).attr('data-object-type') || $(this).data('objectType') || $(this).data('object-type') || '';
            var lock = freeDkp && yvoFpFreePlanBlocksObjectType(type);
            $(this).toggleClass('yvo-fp-tariff-locked', lock)
                .attr('aria-disabled', lock ? 'true' : 'false')
                .attr('title', lock ? 'Платный тариф — нажмите, чтобы посмотреть варианты' : null);
        });
        $('.yvo-doki-autofill-upload, #yvo-doki-scroll-autofill, #yvo-fp-autofill-btn, #yvo-fp-dropzone')
            .toggleClass('yvo-fp-tariff-locked', noAutofill)
            .attr('aria-disabled', noAutofill ? 'true' : 'false');
        if (noAutofill) {
            $('#yvo-fp-autofill-dropdown').addClass('yvo-fp-tariff-locked').attr('title', 'Платный тариф — нажмите, чтобы посмотреть варианты');
            $('.yvo-doki-autofill-upload').attr('title', 'Платный тариф — нажмите, чтобы посмотреть варианты');
        } else {
            $('#yvo-fp-autofill-dropdown').removeClass('yvo-fp-tariff-locked').removeAttr('title');
        }
        $('.yvo-fp-check-contract-standalone, #yvo-fp-check-contract-btn')
            .toggleClass('yvo-fp-tariff-locked', noCheck)
            .attr('aria-disabled', noCheck ? 'true' : 'false')
            .attr('title', noCheck ? 'Платный тариф — нажмите, чтобы посмотреть варианты' : null);
        if (freeDkp) {
            $('body').addClass('yvo-fp-free-plan');
            // Если уже выбран недоступный тип/объект — тихо вернуть к ДКП квартиры.
            if (typeof currentContractType !== 'undefined' && yvoFpFreePlanBlocksContractType(currentContractType)) {
                yvoFpDraftRestoring = true;
                try {
                    yvoApplyFrontendContractType('sale');
                } catch (eResetCt) {}
                yvoFpDraftRestoring = false;
            }
            var curOt = ($('#property_object_type').val() || 'apartment');
            if (yvoFpFreePlanBlocksObjectType(curOt) && typeof yvoFpApplyPropertyObjectType === 'function') {
                yvoFpApplyPropertyObjectType('apartment');
            }
        } else {
            $('body').removeClass('yvo-fp-free-plan');
        }
    }

    window.yvoFpGuardPaidFeature = yvoFpGuardPaidFeature;
    window.yvoFpApplyFreePlanUiLocks = yvoFpApplyFreePlanUiLocks;
    window.yvoFpFreePlanBlocksAutofill = yvoFpFreePlanBlocksAutofill;
    window.yvoFpShowUpgradeModal = yvoFpShowUpgradeModal;

    function showError(msg, opts) {
        opts = opts || {};
        if (!$error.length) {
            $error = $('#yvo-fp-error');
        }
        if (!$error.length) {
            if (window.console && console.warn) {
                console.warn('[YVO]', msg);
            }
            if (opts.alertFallback) {
                window.alert(msg);
            }
            return;
        }
        if (yvoFpErrorFadeTimer) {
            clearTimeout(yvoFpErrorFadeTimer);
            yvoFpErrorFadeTimer = null;
        }
        $error.removeClass('yvo-fp-toast-success').css({
            background: '',
            color: '',
            borderColor: '',
            display: 'block',
            visibility: 'visible',
            opacity: '1',
            zIndex: '2147483646'
        });
        if (opts.linkUrl && opts.linkLabel) {
            $error.empty().append(document.createTextNode(String(msg) + ' ')).append(
                $('<a/>', {
                    href: opts.linkUrl,
                    text: opts.linkLabel,
                    css: { color: '#991b1b', fontWeight: '700', textDecoration: 'underline' }
                })
            );
        } else {
            $error.text(msg);
        }
        $error.show();
        if (!opts.persist) {
            yvoFpErrorFadeTimer = setTimeout(function() { $error.fadeOut(); }, opts.duration || 10000);
        }
        if (opts.inlineGenerate !== false) {
            var $genVal = $('#yvo-fp-generate-validation');
            if ($genVal.length) {
                $genVal.show().text(msg).addClass('error').removeClass('ok');
            }
        }
    }

    function showFpToastOk(msg) {
        if (yvoFpErrorFadeTimer) {
            clearTimeout(yvoFpErrorFadeTimer);
            yvoFpErrorFadeTimer = null;
        }
        $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text(msg).show();
        yvoFpErrorFadeTimer = setTimeout(function() { $error.fadeOut(); }, 5000);
        $('#yvo-fp-generate-validation').hide().text('').removeClass('error ok');
    }

    function hideError() {
        if (yvoFpErrorFadeTimer) {
            clearTimeout(yvoFpErrorFadeTimer);
            yvoFpErrorFadeTimer = null;
        }
        $error.hide();
        $('#yvo-fp-generate-validation').hide().text('').removeClass('error ok');
    }

    function yvoFpFocusPropertyShares() {
        if ($('.yvo-doki-form-skin').length && typeof yvoFpDokiFocusParticipant === 'function') {
            yvoFpDokiFocusParticipant('property');
        } else {
            $('.yvo-fp-tab').removeClass('active');
            $('.yvo-fp-panel').removeClass('active');
            $('.yvo-fp-tab[data-tab="property"]').addClass('active');
            $('#yvo-fp-panel-property').addClass('active');
        }
        setTimeout(function() {
            var el = document.getElementById('yvo-fp-share-allocation-block')
                || document.getElementById('yvo-fp-share-validation')
                || document.getElementById('yvo-fp-panel-property');
            if (el && el.scrollIntoView) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 250);
    }

    function yvoFpAbortGenerate(msg, opts) {
        opts = opts || {};
        if (typeof yvoFpHighlightEmptyRequired === 'function') {
            yvoFpHighlightEmptyRequired({ scroll: true, toast: false });
        }
        showError(msg, { persist: true, scroll: true, inlineGenerate: true });
        if (opts.shareMsg) {
            yvoSetShareValidation(opts.shareMsg, false);
        }
        if (opts.focusTab === 'property') {
            setTimeout(function() {
                yvoFpFocusPropertyShares();
            }, 0);
        }
        if (opts.focusTab === 'buyer') {
            setTimeout(function() {
                if (typeof yvoDokiGoToStep === 'function') {
                    yvoDokiGoToStep(1);
                } else {
                    switchTab('buyer');
                }
            }, 0);
        }
    }

    var yvoFpMissingHintsActive = false;

    function yvoFpFieldLooksRequired($field) {
        var $label = $field.children('label').first();
        if (!$label.length) {
            $label = $field.find('label').first();
        }
        var labelText = ($label.text() || '').replace(/\s+/g, ' ');
        if (/\*/.test(labelText) || /\bобязательн/i.test(labelText)) {
            return true;
        }
        var $ctrl = $field.find('input, textarea, select').filter(':visible').first();
        return !!($ctrl.length && $ctrl.is('[required]'));
    }

    function yvoFpFieldControlValue($field) {
        var $ctrl = $field.find('input, textarea, select').filter(':visible').not('[type="hidden"]').not('[type="checkbox"]').not('[type="radio"]').first();
        if (!$ctrl.length) {
            return null;
        }
        // placeholder alone doesn't count as filled
        var v = String($ctrl.val() || '').trim();
        return { $ctrl: $ctrl, value: v };
    }

    function yvoFpClearFieldNeedsFill($field) {
        $field.removeClass('yvo-fp-field-needs-fill');
        $field.find('.yvo-fp-field-hint-fill').remove();
    }

    function yvoFpMarkFieldNeedsFill($field) {
        if ($field.hasClass('yvo-fp-field-needs-fill')) {
            return;
        }
        $field.addClass('yvo-fp-field-needs-fill');
        if (!$field.find('.yvo-fp-field-hint-fill').length) {
            $field.append('<span class="yvo-fp-field-hint-fill">Заполните это поле</span>');
        }
    }

    /**
     * Подсветить пустые обязательные поля (метка с * или required).
     * @param {{scope?:JQuery, scroll?:boolean, toast?:boolean}} opts
     * @return {number} количество пустых
     */
    function yvoFpHighlightEmptyRequired(opts) {
        opts = opts || {};
        yvoFpMissingHintsActive = true;
        var $scope = opts.scope && opts.scope.length
            ? opts.scope
            : $('.yvo-fp-panel.active, .yvo-fp-panel:visible, #yvo-fp-panel-property .yvo-fp-object-type-block.active, #yvo-fp-panel-property .yvo-fp-price-section').add(
                yvoFpAllParticipantPanels ? yvoFpAllParticipantPanels() : $('.yvo-fp-panel')
            );
        // Узкий надёжный scope: активная вкладка + объект + все панели участников с видимыми полями
        $scope = $('.yvo-frontend-page .yvo-fp-field, .yvo-doki-form-skin .yvo-fp-field').filter(function() {
            var $f = $(this);
            if (!$f.is(':visible')) {
                return false;
            }
            // скрытые блоки (доля и т.п.)
            if ($f.closest('[hidden]').length || $f.is('[hidden]')) {
                return false;
            }
            return true;
        });

        var missing = [];
        $scope.each(function() {
            var $field = $(this);
            yvoFpClearFieldNeedsFill($field);
            if (!yvoFpFieldLooksRequired($field)) {
                return;
            }
            var info = yvoFpFieldControlValue($field);
            if (!info) {
                return;
            }
            if (info.value === '') {
                yvoFpMarkFieldNeedsFill($field);
                missing.push(info.$ctrl.get(0));
            }
        });

        if (opts.scroll && missing.length) {
            var el = missing[0];
            try {
                if (el && el.scrollIntoView) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                if (el && el.focus) {
                    setTimeout(function() { el.focus(); }, 280);
                }
            } catch (eScroll) {}
        }
        if (opts.toast !== false && missing.length) {
            showError('Осталось заполнить ' + missing.length + ' обязательных ' + (missing.length === 1 ? 'поле' : (missing.length < 5 ? 'поля' : 'полей')) + ' — они подсвечены.', {
                persist: false,
                duration: 8000,
                inlineGenerate: true
            });
        }
        return missing.length;
    }

    function yvoFpRefreshMissingHintsForField($field) {
        if (!yvoFpMissingHintsActive || !$field || !$field.length) {
            return;
        }
        if (!yvoFpFieldLooksRequired($field)) {
            yvoFpClearFieldNeedsFill($field);
            return;
        }
        var info = yvoFpFieldControlValue($field);
        if (!info) {
            return;
        }
        if (info.value === '') {
            yvoFpMarkFieldNeedsFill($field);
        } else {
            yvoFpClearFieldNeedsFill($field);
        }
    }

    function yvoFpSyncEmptyRequiredSoftMarkers($root) {
        var $fields = ($root && $root.length) ? $root.find('.yvo-fp-field').addBack().filter('.yvo-fp-field') : $('.yvo-fp-field');
        if ($root && $root.hasClass && $root.hasClass('yvo-fp-field')) {
            $fields = $root;
        } else if ($root && $root.length && !$root.find('.yvo-fp-field').length && $root.closest('.yvo-fp-field').length) {
            $fields = $root.closest('.yvo-fp-field');
        }
        $fields.each(function() {
            var $field = $(this);
            if (!$field.is(':visible') || $field.closest('[hidden]').length) {
                $field.removeClass('yvo-fp-field-empty-required');
                return;
            }
            if (!yvoFpFieldLooksRequired($field)) {
                $field.removeClass('yvo-fp-field-empty-required');
                return;
            }
            var info = yvoFpFieldControlValue($field);
            if (!info) {
                $field.removeClass('yvo-fp-field-empty-required');
                return;
            }
            $field.toggleClass('yvo-fp-field-empty-required', info.value === '');
        });
    }

    $(document).on('input change blur', '.yvo-fp-field input, .yvo-fp-field textarea, .yvo-fp-field select', function() {
        var $field = $(this).closest('.yvo-fp-field');
        yvoFpSyncEmptyRequiredSoftMarkers($field);
        yvoFpRefreshMissingHintsForField($field);
        // Лёгкая подсветка при уходе с пустого обязательного поля
        if (yvoFpFieldLooksRequired($field)) {
            var info = yvoFpFieldControlValue($field);
            if (info && info.value === '' && document.activeElement !== info.$ctrl.get(0)) {
                yvoFpMissingHintsActive = true;
                yvoFpMarkFieldNeedsFill($field);
            }
        }
    });

    window.yvoFpHighlightEmptyRequired = yvoFpHighlightEmptyRequired;
    window.yvoFpSyncEmptyRequiredSoftMarkers = yvoFpSyncEmptyRequiredSoftMarkers;

    setTimeout(function() {
        try {
            yvoFpSyncEmptyRequiredSoftMarkers();
        } catch (eSoft) {}
    }, 400);

    function yvoFpPrincipalPartiesLookLikeDuplicate(sellers, buyers) {
        if (!sellers || !sellers.length || !buyers || !buyers.length) {
            return false;
        }
        var s = sellers[0];
        var b = buyers[0];
        if (!s || !b) {
            return false;
        }
        var sn = String(s.full_name || '').trim().toLowerCase();
        var bn = String(b.full_name || '').trim().toLowerCase();
        if (!sn || !bn || sn !== bn) {
            return false;
        }
        var sps = String(s.passport_series || '').trim();
        var spn = String(s.passport_number || '').trim();
        var bps = String(b.passport_series || '').trim();
        var bpn = String(b.passport_number || '').trim();
        return !!(sps && spn && sps === bps && spn === bpn);
    }

    /** Текст похож на кракозябру выписки ЕГРН (как yvo_text_looks_like_egrn_garbled в PHP). */
    function yvoFpTextLooksLikeEgrnGarbled(text) {
        if (!text || text.length < 30) return false;
        var cyr = (text.match(/[а-яА-ЯёЁ]/g) || []).length;
        if (/паспорт|PASSPORT|личность|гражданин|серия\s+\d{2}\s*\d{2}|код\s+подразделения|удостоверяющ|дата\s+рождения/ui.test(text) && cyr >= 15) return false;
        return /кадастр|ЕГРН|росреестр|выписк\s+из\s+ЕГРН|объект\s+недвижимости|правообладател|кадастровый\s+номер/i.test(text);
    }

    function yvoFpIsDemoFullName(name) {
        var lower = String(name || '').trim().toLowerCase().replace(/\s+/g, ' ');
        if (!lower) return false;
        if (/^(иванов|петров|сидоров)\s+(иван|петр|сидор)(\s+(иванович|петрович|сидорович))?$/.test(lower)) {
            return true;
        }
        return /^(фио|ф\.?\s*и\.?\s*о\.?|фамилия|имя|отчество)(\s|$)/.test(lower);
    }

    function yvoFpLooksLikePlaceholder(v, key) {
        var lower = String(v || '').trim().toLowerCase();
        if (!lower || lower === 'null') return true;
        if (lower.indexOf('например') !== -1 || lower.indexOf('фио полностью') !== -1) return true;
        if (/\(\d|\d\s*цифр|формат\s+дд|полное название|серия паспорта|номер паспорта|код подразделения|кем выдан паспорт/i.test(lower)) return true;
        return false;
    }

    function yvoFpNormalizeParsedData(data) {
        if (!data || typeof data !== 'object') return {};
        var out = {};
        var k;
        for (k in data) {
            if (Object.prototype.hasOwnProperty.call(data, k)) out[k] = data[k];
        }
        if (out.passport_series) {
            var s = String(out.passport_series).replace(/\D/g, '');
            if (s.length === 4) out.passport_series = s;
        }
        if (out.passport_number) {
            var n = String(out.passport_number).replace(/\D/g, '');
            if (n.length === 6) out.passport_number = n;
        }
        if (out.department_code) {
            var dc = String(out.department_code).replace(/\D/g, '');
            if (dc.length === 6) out.department_code = dc.substr(0, 3) + '-' + dc.substr(3);
        }
        if (out.full_name) out.full_name = String(out.full_name).trim().replace(/\s+/g, ' ');
        if (out.birth_cert_series) out.birth_cert_series = String(out.birth_cert_series).trim().toUpperCase();
        if (out.birth_cert_number) out.birth_cert_number = String(out.birth_cert_number).replace(/\D/g, '');
        return out;
    }

    function yvoFpPrepareParsedForForm(raw) {
        var normalized = yvoFpNormalizeParsedData(raw);
        var strict = yvoFpSanitizeParsedData(normalized, '');
        if (yvoFpParsedDataHasValues(strict)) return strict;
        return normalized;
    }

    /** Убирает демо-ФИО и подсказки из промпта парсера. */
    function yvoFpSanitizeParsedData(data, sourceText) {
        if (!data || typeof data !== 'object') return {};
        var stringFields = [
            'full_name', 'passport_series', 'passport_number', 'department_code',
            'passport_issued_by', 'passport_date', 'birth_date', 'birth_place',
            'birth_cert_series', 'birth_cert_number', 'birth_cert_date', 'birth_cert_issued_by',
            'registration', 'inn', 'snils', 'phone', 'email', 'requisites', 'bank_details'
        ];
        var out = {};
        var k;
        for (k in data) {
            if (Object.prototype.hasOwnProperty.call(data, k)) out[k] = data[k];
        }
        stringFields.forEach(function(key) {
            if (out[key] === undefined || out[key] === null) return;
            var v = String(out[key]).trim();
            if (v === '' || v.toLowerCase() === 'null') {
                delete out[key];
            return;
        }
            if (yvoFpLooksLikePlaceholder(v, key)) {
                delete out[key];
                return;
            }
            if (key === 'full_name' && yvoFpIsDemoFullName(v)) {
                delete out[key];
            }
        });
        return out;
    }

    function yvoFpParsedDataHasValues(data) {
        if (!data || typeof data !== 'object') return false;
        var k;
        for (k in data) {
            if (!Object.prototype.hasOwnProperty.call(data, k)) continue;
            var v = data[k];
            if (v !== undefined && v !== null && String(v).trim() !== '' && String(v).trim().toLowerCase() !== 'null') {
                return true;
            }
        }
        return false;
    }

    function yvoFpMaybeFixEgrnThen(text, onReady) {
        if (typeof onReady !== 'function') return;
        if (!yvoFpTextLooksLikeEgrnGarbled(text) || typeof yvo_frontend_ajax === 'undefined') {
            onReady(text);
            return;
        }
                        $parseStatus.show().text('Исправление кодировки (подождите до 5 мин)...').removeClass('error ok');
                        $.ajax({
                            url: yvo_frontend_ajax.ajax_url,
                            type: 'POST',
            data: { action: 'yvo_fix_egrn_text', nonce: yvo_frontend_ajax.nonce, text: text },
                            dataType: 'json',
                            timeout: 300000
                        }).done(function(fx) {
            var finalText = text;
                            if (fx.success && fx.data) {
                                if (fx.data.text) {
                    finalText = fx.data.text;
                    $text.val(finalText);
                                    $parseStatus.text('Кодировка исправлена.').addClass('ok').show();
                    setTimeout(function() { $parseStatus.fadeOut(); }, 2500);
                    onReady(finalText);
                    return;
                }
                if (fx.data.key) {
                                    $parseStatus.text('Загрузка полного текста...').removeClass('error ok');
                                    $.ajax({
                                        url: yvo_frontend_ajax.ajax_url,
                                        type: 'POST',
                                        data: { action: 'yvo_get_fix_egrn_result', nonce: yvo_frontend_ajax.nonce, key: fx.data.key },
                                        dataType: 'text',
                                        timeout: 60000
                                    }).done(function(body) {
                                        if (body && typeof body === 'string' && body.charAt(0) !== '{') {
                            finalText = body;
                            $text.val(finalText);
                                        }
                                        $parseStatus.text('Кодировка исправлена.').addClass('ok').show();
                                        setTimeout(function() { $parseStatus.fadeOut(); }, 2500);
                        onReady(finalText);
                                    }).fail(function() {
                                        $parseStatus.hide();
                        onReady(text);
                                    });
                                    return;
                                }
                            }
            $parseStatus.hide();
            onReady(text);
        }).fail(function(xhr, status) {
                            $parseStatus.hide();
                            if (status === 'timeout') {
                showError('Таймаут исправления кодировки. Можно нажать «Исправить кодировку (ИИ)» вручную.');
            }
            onReady(text);
        });
    }

    function yvoFpAfterUploadRecognized(text, extracted, uploadForSelect) {
        text = String(text || '').trim();
        if (typeof window.yvoFpSetLastOcrText === 'function') {
            window.yvoFpSetLastOcrText(text);
        } else {
            window.yvoFpLastOcrText = text;
        }
        if (text.length < 12) {
            $parseStatus.show().text('Слишком мало текста после OCR. Загрузите файл снова или вставьте текст вручную.').addClass('error');
            return;
        }

        if (yvoFpLooksLikeEgrn(text)) {
            $parseStatus.show().text('Изучение документа...').removeClass('error ok');
            yvoFpExtractEgrnFromText(text, function(ed) {
                try {
                    if (!ed && extracted) {
                        ed = extracted;
                    }
                    var applied = ed && yvoFpApplyEgrnExtracted(ed, text);
                    yvoFpRefreshExtractedSummary(text);
                    if (typeof window.yvoFpRunFieldReview === 'function') {
                        window.yvoFpRunFieldReview();
                    }
                    if (applied) {
                        yvoFpFinishDocumentStudy('Данные подставлены: объект, правообладатель, справка ЕГРН. Проверьте замечания ниже, если есть.', false);
                    } else if (ed) {
                        yvoFpFinishDocumentStudy('Документ изучен. Проверьте поля или блок «Извлечённые данные».', false);
                    } else {
                        yvoFpFinishDocumentStudy('Не удалось извлечь данные из документа.', true);
                    }
                } catch (err) {
                    if (typeof console !== 'undefined' && console.error) {
                        console.error('yvoFpAfterUploadRecognized egrn', err);
                    }
                    yvoFpFinishDocumentStudy('Ошибка при подстановке данных. Попробуйте «Извлечь данные выписки ЕГРН» в доп. опциях.', true);
                }
            });
            return;
        }

        var roleLabsShort = yvoParticipantRoleShortLabels();
        var uploadLabels = {
            seller: roleLabsShort.seller, buyer: roleLabsShort.buyer, property: 'Объект недвижимости',
            seller_requisites: currentContractType === 'gift' ? 'Реквизиты дарителя' : 'Реквизиты продавца',
            buyer_requisites: currentContractType === 'gift' ? 'Реквизиты одаряемого' : 'Реквизиты покупателя',
            seller_representative: currentContractType === 'gift' ? 'Представитель дарителя' : 'Доверенное лицо продавца',
            buyer_representative: currentContractType === 'gift' ? 'Представитель одаряемого' : 'Доверенное лицо покупателя',
            contributor: 'Вноситель задатка',
            minor_seller: roleLabsShort.minor_seller || 'Несовершеннолетний продавец',
            minor_buyer: roleLabsShort.minor_buyer || 'Несовершеннолетний покупатель',
            'minor_seller|u14': roleLabsShort['minor_seller|u14'] || 'Несовершеннолетний продавец (до 14 лет)',
            'minor_seller|a14_18': roleLabsShort['minor_seller|a14_18'] || 'Несовершеннолетний продавец (от 14 лет)',
            'minor_buyer|u14': roleLabsShort['minor_buyer|u14'] || 'Несовершеннолетний покупатель (до 14 лет)',
            'minor_buyer|a14_18': roleLabsShort['minor_buyer|a14_18'] || 'Несовершеннолетний покупатель (от 14 лет)'
        };
        var docTypeMap = {
            seller: 'seller', buyer: 'buyer', property: 'property',
            seller_requisites: 'seller_requisites', buyer_requisites: 'buyer_requisites',
            seller_representative: 'seller', buyer_representative: 'buyer',
            contributor: 'buyer', minor_seller: 'seller', minor_buyer: 'buyer'
        };
        var fromActiveTab = false;
        var uploadFor;
        if (uploadForSelect && uploadLabels[uploadForSelect]) {
            uploadFor = uploadForSelect;
        } else if (!uploadForSelect) {
            uploadFor = getActiveTab();
            fromActiveTab = true;
        } else {
            uploadFor = getActiveTab();
            fromActiveTab = true;
        }
        if (!uploadFor || uploadFor === 'generate') {
            yvoFpRefreshExtractedSummary(text);
            return;
        }
        uploadFor = yvoFpResolveUploadRole(uploadFor);
        var uploadPr = fromActiveTab ? { tabId: uploadFor, minorOpts: undefined } : yvoFpTabIdAndMinorOptsFromCompositeRole(uploadFor);
        var uploadPanelTabId = yvoFpResolveParticipantPanelTab(uploadPr.tabId);
        uploadPr.tabId = uploadPanelTabId;
        var mDoc = /^(minor_seller|minor_buyer)/.exec(uploadPanelTabId);
        var docMapKey = mDoc ? mDoc[1] : uploadPanelTabId;
        var $tabBtn = yvoFpParticipantTabButtons().filter(function() {
            return String($(this).attr('data-tab')) === String(uploadPanelTabId);
        }).first();
        var statusLabel = uploadLabels[uploadFor] || ($tabBtn.length ? $tabBtn.text().trim() : uploadFor);

        function runLegacyParseForTab() {
            $parseStatus.show().text('Извлечение данных для «' + statusLabel + '»...').removeClass('error ok');
            var docType = docTypeMap[docMapKey] || getParseDocumentType(uploadPanelTabId);
            var parsePayload = {
                action: 'yvo_frontend_parse',
                nonce: yvo_frontend_ajax.nonce,
                text: text,
                document_type: docType
            };
            if (docType === 'property') {
                parsePayload.object_type_hint = $('#property_object_type').val() || '';
            }
            $.post(yvo_frontend_ajax.ajax_url, parsePayload, 'json').done(function(parseRes) {
                if (parseRes.success && parseRes.data && parseRes.data.parsed_data) {
                    var data = yvoFpPrepareParsedForForm(parseRes.data.parsed_data);
                    if (!yvoFpParsedDataHasValues(data)) {
                        var errMsg = (parseRes.data && parseRes.data.message) ? parseRes.data.message : '';
                        $parseStatus.text(errMsg || ('Не удалось извлечь данные для «' + statusLabel + '». Проверьте OCR-текст ниже или заполните вручную.')).addClass('error');
                        return;
                    }
                    if (uploadFor === 'seller_requisites') {
                        var req = (data.requisites || '').trim() || (data.bank_details || '').trim();
                        if (req) {
                            $('#yvo-fp-panel-property').find('[data-key="seller_details"]').val(req);
                            $('#yvo-fp-panel-property').find('[data-key="seller_details_mortgage"]').val(req);
                        }
                        $('.yvo-fp-tab').removeClass('active'); $('.yvo-fp-panel').removeClass('active');
                        $('.yvo-fp-tab[data-tab="property"]').addClass('active'); $('#yvo-fp-panel-property').addClass('active');
                    } else if (uploadFor === 'buyer_requisites') {
                        var reqB = (data.requisites || '').trim() || (data.bank_details || '').trim();
                        if (reqB) $('#yvo-fp-panel-property').find('[data-key="buyer_details"]').val(reqB);
                        $('.yvo-fp-tab').removeClass('active'); $('.yvo-fp-panel').removeClass('active');
                        $('.yvo-fp-tab[data-tab="property"]').addClass('active'); $('#yvo-fp-panel-property').addClass('active');
                    } else if (!fromActiveTab && (uploadFor === 'seller_representative' || uploadFor === 'buyer_representative' || uploadFor === 'contributor' || uploadPr.minorOpts || uploadFor === 'minor_seller' || uploadFor === 'minor_buyer')) {
                        var tabId;
                        if (uploadPr.minorOpts) {
                            var brm = /^(minor_seller|minor_buyer)/.exec(uploadPanelTabId);
                            tabId = brm ? ensureParticipantTabForRole(brm[1], uploadPr.minorOpts.minorAgeGroup) : ensureParticipantTabForRole(uploadPanelTabId);
                        } else {
                            tabId = ensureParticipantTabForRole(uploadFor);
                        }
                        if (tabId) {
                            fillForm(tabId, data);
                            if (uploadFor === 'contributor') {
                                currentContractType = 'deposit_agreement';
                                $('#yvo-fp-contract-type-btn').contents().first().replaceWith(document.createTextNode(contractTypeLabels.deposit_agreement + ' '));
                                updateActReceiptVisibility();
                                updateContractTemplateOptions('deposit_agreement');
                            }
                            $('.yvo-fp-tab').removeClass('active'); $('.yvo-fp-panel').removeClass('active');
                            $('.yvo-fp-tab[data-tab="' + tabId + '"]').addClass('active'); $('#yvo-fp-panel-' + tabId).addClass('active');
                        }
                    } else {
                        if (!$('#yvo-fp-panel-' + uploadPanelTabId).length) {
                            $parseStatus.text('Данные извлечены, но вкладка «' + statusLabel + '» не найдена. Добавьте участника или выберите другую вкладку.').addClass('error');
                            return;
                        }
                        fillForm(uploadPanelTabId, data);
                        if (uploadPanelTabId === 'property') {
                            yvoFpMergeRegFieldsIntoForm(data);
                        }
                        if (uploadFor === 'contributor') {
                            currentContractType = 'deposit_agreement';
                            $('#yvo-fp-contract-type-btn').contents().first().replaceWith(document.createTextNode(contractTypeLabels.deposit_agreement + ' '));
                            updateActReceiptVisibility();
                            updateContractTemplateOptions('deposit_agreement');
                        }
                        $('.yvo-fp-tab').removeClass('active'); $('.yvo-fp-panel').removeClass('active');
                        var activeTabAttr = fromActiveTab ? uploadPanelTabId : uploadFor;
                        $('.yvo-fp-tab[data-tab="' + activeTabAttr + '"]').addClass('active');
                        $('#yvo-fp-panel-' + uploadPanelTabId).addClass('active');
                    }
                    if (typeof window.yvoFpRunFieldReview === 'function') {
                        window.yvoFpRunFieldReview(uploadPanelTabId);
                    }
                    $parseStatus.text('Данные подставлены в форму «' + statusLabel + '». Проверьте замечания, если появились.').addClass('ok');
                } else {
                    $parseStatus.text(parseRes.data && parseRes.data.message ? parseRes.data.message : 'Не удалось извлечь данные').addClass('error');
                }
            }).fail(function() {
                $parseStatus.text('Ошибка извлечения данных').addClass('error');
            });
        }

        yvoFpRefreshExtractedSummary(text);
        if (extracted && (extracted.property || (extracted.participants && extracted.participants.length))) {
            yvoFpApplyEgrnExtracted(extracted, text);
        } else {
            runLegacyParseForTab();
        }
    }

    // ——— Загрузка и OCR ———
    function yvoFpPdConsentRequired() {
        return !!(typeof yvo_frontend_ajax !== 'undefined' && yvo_frontend_ajax && parseInt(yvo_frontend_ajax.pd_consent_required, 10) === 1);
    }
    function yvoFpPdConsentGiven() {
        if (!yvoFpPdConsentRequired()) return true;
        var $cb = $('#yvo-legal-pd-consent-cb');
        if ($cb.length && $cb.is(':checked')) return true;
        if (typeof yvo_frontend_ajax !== 'undefined' && parseInt(yvo_frontend_ajax.pd_consent_given, 10) === 1) return true;
        return false;
    }
    function yvoFpSyncPdConsentUi() {
        var $wrap = $('#yvo-legal-pd-consent');
        if (!$wrap.length) return;
        if (yvoFpPdConsentGiven()) {
            $wrap.removeClass('is-blocked');
        } else {
            $wrap.addClass('is-blocked');
        }
    }
    function yvoFpSavePdConsent(cb) {
        if (!cb || typeof yvo_frontend_ajax === 'undefined' || !yvo_frontend_ajax.ajax_url) return;
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_save_pd_consent',
            yvo_pd_consent: '1'
        });
        if (typeof yvo_frontend_ajax !== 'undefined') {
            yvo_frontend_ajax.pd_consent_given = 1;
        }
        yvoFpSyncPdConsentUi();
    }
    $(document).on('change', '#yvo-legal-pd-consent-cb', function() {
        if (this.checked) {
            yvoFpSavePdConsent(this);
        } else {
            if (typeof yvo_frontend_ajax !== 'undefined') {
                yvo_frontend_ajax.pd_consent_given = 0;
            }
            yvoFpSyncPdConsentUi();
        }
    });
    yvoFpSyncPdConsentUi();

    function uploadFile(file) {
        if (!file) return;
        if (yvoFpGuardPaidFeature('autofill')) {
            return;
        }
        if (typeof yvo_frontend_ajax === 'undefined' || !yvo_frontend_ajax || !yvo_frontend_ajax.ajax_url) {
            showError('Форма не инициализирована. Обновите страницу (Ctrl+F5).');
            return;
        }
        if (!yvoFpPdConsentGiven()) {
            showError('Отметьте согласие на обработку персональных данных перед загрузкой документа.');
            var $cons = $('#yvo-legal-pd-consent');
            if ($cons.length && $cons[0].scrollIntoView) {
                $cons[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }
        var maxSize = typeof yvo_frontend_ajax !== 'undefined' ? yvo_frontend_ajax.max_size : 20 * 1024 * 1024;
        if (file.size > maxSize) {
            showError('Файл слишком большой. Максимум ' + (yvo_frontend_ajax.max_size_mb || 20) + ' МБ');
            return;
        }
        hideError();
        $progress.show();
        $resultSection.hide();
        $dropzone.addClass('loading');
        $parseStatus.show().text('Распознавание документа…').removeClass('error ok');

        var formData = new FormData();
        formData.append('action', 'yvo_frontend_upload');
        formData.append('nonce', yvo_frontend_ajax.nonce);
        formData.append('yvo_pd_consent', '1');
        formData.append('file', file);

        $.ajax({
            url: yvo_frontend_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: (typeof yvo_frontend_ajax !== 'undefined' && yvo_frontend_ajax.upload_timeout) ? yvo_frontend_ajax.upload_timeout : 120000,
            success: function(res) {
                if (res && res.success && res.data && res.data.text) {
                    var rawText = res.data.text;
                    var uploadForSelect = $('#yvo-fp-upload-for').val();
                    var extracted = res.data.extracted_data;
                    $text.val(rawText);
                    $resultSection.show();
                    $('#yvo-fp-upload-target').show();
                    $resultSection[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                    yvoFpMaybeFixEgrnThen(rawText, function(fixedText) {
                        yvoFpAfterUploadRecognized(fixedText, extracted, uploadForSelect);
                    });
                } else {
                    var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Ошибка распознавания';
                    $parseStatus.text(errMsg).addClass('error').show();
                    showError(errMsg);
                }
            },
            error: function(xhr, status, err) {
                var msg = 'Ошибка сервера';
                if (status === 'timeout') {
                    msg = 'Превышено время ожидания. На хостинге связь может быть медленной — попробуйте ещё раз или уменьшите размер файла.';
                } else if (xhr && xhr.responseText) {
                    try {
                        var j = JSON.parse(xhr.responseText);
                        if (j.data && j.data.message) msg = j.data.message;
                    } catch (e) {
                        if (xhr.responseText.indexOf('Ошибка безопасности') !== -1) {
                            msg = 'Ошибка безопасности. Обновите страницу (Ctrl+F5) и попробуйте снова.';
                        }
                    }
                }
                $parseStatus.text(msg).addClass('error').show();
                showError(msg);
            },
            complete: function() {
                $progress.hide();
                $dropzone.removeClass('loading');
            }
        });
    }

    function yvoFpOpenFilePicker() {
        if (yvoFpGuardPaidFeature('autofill')) {
            return;
        }
        if (!yvoFpPdConsentGiven()) {
            showError('Отметьте согласие на обработку персональных данных перед загрузкой документа.');
            return;
        }
        var el = document.getElementById('yvo-fp-file');
        if (el) {
            el.click();
        }
    }
    window.yvoFpUploadFile = uploadFile;
    window.yvoFpOpenFilePicker = yvoFpOpenFilePicker;

    if ($dropzone.length) {
    $dropzone.on('click', function(e) {
            if (!$(e.target).is('input')) {
                yvoFpOpenFilePicker();
            }
    });
    $dropzone.on('dragover dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('dragover');
    });
    $dropzone.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('dragover');
    });
    $dropzone.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('dragover');
        var f = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files[0];
        if (f) uploadFile(f);
    });
    }
    $(document).on('change', '#yvo-fp-file', function() {
        var f = this.files[0];
        if (f) uploadFile(f);
        this.value = '';
    });
    if ($fileInput.length && !$dropzone.length) {
        $fileInput.on('change', function() {
            var f = this.files[0];
            if (f) uploadFile(f);
            this.value = '';
        });
    }

    // ——— Выпадающие кнопки: закрытие по клику снаружи ———
    $(document).on('click', function(e) {
        // Кнопка #yvo-fp-tab-add не внутри .yvo-fp-dropdown — иначе тот же клик закрывает только что открываемое меню.
        if ($(e.target).closest('#yvo-fp-tab-add').length) {
            return;
        }
        if ($(e.target).closest('.yvo-doki-add-participant-panel').length) {
            return;
        }
        if (!$(e.target).closest('.yvo-fp-dropdown').length) {
            $('.yvo-fp-dropdown').removeClass('open');
            $('#yvo-fp-quick-actions').removeClass('yvo-doki-quick-actions-visible');
            $('#yvoDokiAddParticipantPanel').attr('hidden', true).removeClass('is-open');
        }
        if (!$(e.target).closest('.yvo-fp-cabinet-inline-dropdown').length) {
            $('.yvo-fp-cabinet-inline-menu').attr('hidden', true);
            $('.yvo-fp-cabinet-inline-toggle').attr('aria-expanded', 'false');
            if (typeof yvoResetCabinetInlineMenus === 'function') {
                yvoResetCabinetInlineMenus();
            }
        }

        // Закрытие меню "Опции" в блоке результатов
        if (!$(e.target).closest('#yvo-fp-contract-options').length) {
            $('#yvo-fp-contract-options-menu').hide();
            $('#yvo-fp-contract-options-toggle').attr('aria-expanded', 'false');
        }
    });

    // Опции в блоке "Договор создан"
    $(document).on('click', '#yvo-fp-contract-options-toggle', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $m = $('#yvo-fp-contract-options-menu');
        var open = $m.is(':visible');
        $m.toggle(!open);
        $(this).attr('aria-expanded', open ? 'false' : 'true');
    });

    $(document).on('click', '#yvo-fp-contract-options-menu .yvo-fp-download-link', function() {
        $('#yvo-fp-contract-options-menu').hide();
        $('#yvo-fp-contract-options-toggle').attr('aria-expanded', 'false');
    });

    // ——— Тип объекта: выпадающий список и переключение блоков ———
    var objectTypeLabels = { apartment: 'Квартира', share: 'Доля (в квартире)', room: 'Комната', land: 'Участок', house_with_plot: 'Дом с участком', garage: 'Гараж', parking: 'Паркинг' };
    function yvoSyncDokiObjectTypePills() {
        var type = $('#property_object_type').val() || 'apartment';
        $('.yvo-doki-object-type-pick').removeClass('active');
        $('.yvo-doki-object-type-pick[data-object-type="' + type + '"]').addClass('active');
    }
    $('#yvo-fp-object-type-btn').on('click', function(e) {
        e.stopPropagation();
        $('#yvo-fp-object-type-dropdown').toggleClass('open');
        $('#yvo-fp-autofill-dropdown, #yvo-fp-add-participant-dropdown, #yvo-fp-contract-type-dropdown').removeClass('open');
    });

    // ——— Тип договора: выпадающий список и видимость опций акта/расписки ———
    var contractTypeLabels = {
        sale: '1. Договоры купли-продажи',
        gift: '2. Договоры дарения',
        share_allocation: '3. Соглашение выделения долей',
        deposit_agreement: 'Задаток',
        advance_agreement: 'Аванс',
        preliminary: 'Предварительный ДКП'
    };
    var currentContractType = 'sale';
    window.yvoGetCurrentContractType = function() {
        return currentContractType;
    };
    function yvoParticipantRoleShortLabels() {
        if (currentContractType === 'gift') {
            return {
                seller: 'Даритель', buyer: 'Одаряемый', property: 'Объект',
                minor_seller: 'Даритель', minor_buyer: 'Одаряемый',
                'minor_seller|u14': 'Даритель (до 14 лет)',
                'minor_seller|a14_18': 'Даритель (от 14 лет)',
                'minor_buyer|u14': 'Одаряемый (до 14 лет)',
                'minor_buyer|a14_18': 'Одаряемый (от 14 лет)'
            };
        }
        if (currentContractType === 'share_allocation') {
            return { seller: 'Участник (отчуждает)', buyer: 'Участник (получает)', property: 'Объект' };
        }
        return { seller: 'Продавец', buyer: 'Покупатель', property: 'Объект' };
    }
    function updateActReceiptVisibility() {
        var show = (currentContractType === 'sale' || currentContractType === 'sale_mortgage' || currentContractType === 'assignment' || currentContractType === 'deposit_agreement' || currentContractType === 'advance_agreement');
        var isDepositAdvance = (currentContractType === 'deposit_agreement' || currentContractType === 'advance_agreement');
        var isPreliminary = (currentContractType === 'preliminary');
        $('#yvo-fp-act-receipt-options').toggle(show);
        if (!show || isPreliminary) {
            if (isPreliminary) {
                $('#yvo-fp-act-receipt-options').hide();
            }
            if (!show) {
                $('#yvo-fp-generate-act, #yvo-fp-generate-receipt').prop('checked', false);
            }
        }
        $('#yvo-fp-act-option-row').toggle(show && !isDepositAdvance && !isPreliminary);
        if (isDepositAdvance || isPreliminary) {
            $('#yvo-fp-generate-act').prop('checked', false);
        }
        if (currentContractType === 'advance_agreement') {
            $('#yvo-fp-label-receipt').text('Создать расписку в получении аванса');
            $('#yvo-fp-label-deposit-amount').text('Сумма аванса (руб., цифрами)');
            $('#yvo-fp-label-deposit-amount-words').text('Сумма аванса прописью');
        } else if (currentContractType === 'deposit_agreement') {
            $('#yvo-fp-label-receipt').text('Создать расписку в получении задатка');
            $('#yvo-fp-label-deposit-amount').text('Сумма задатка (руб., цифрами)');
            $('#yvo-fp-label-deposit-amount-words').text('Сумма задатка прописью');
        } else {
            $('#yvo-fp-label-act').text('Создать акт приёма-передачи недвижимости');
            $('#yvo-fp-label-receipt').text('Создать расписку в получении денежных средств');
        }
        $('.yvo-fp-price-preliminary').toggle(currentContractType === 'deposit_agreement' || currentContractType === 'advance_agreement');
        $('.yvo-fp-deposit-advance-only').toggle(currentContractType === 'deposit_agreement' || currentContractType === 'advance_agreement');
        if ((currentContractType === 'deposit_agreement' || currentContractType === 'advance_agreement') && !$('#property_main_contract_deadline').val()) {
            var d = new Date();
            d.setDate(d.getDate() + 30);
            var dd = String(d.getDate()).padStart(2, '0');
            var mm = String(d.getMonth() + 1).padStart(2, '0');
            $('#property_main_contract_deadline').val(dd + '.' + mm + '.' + d.getFullYear());
        }
    }
    $('#yvo-fp-contract-type-btn').on('click', function(e) {
        e.stopPropagation();
        $('#yvo-fp-contract-type-dropdown').toggleClass('open');
        $('#yvo-fp-autofill-dropdown, #yvo-fp-add-participant-dropdown, #yvo-fp-object-type-dropdown').removeClass('open');
    });
    var dokiBadgeLabels = {
        sale: 'Купля-продажа',
        gift: 'Дарение',
        share_allocation: 'Доли',
        deposit_agreement: 'Задаток',
        advance_agreement: 'Аванс',
        preliminary: 'Предварительный'
    };
    function yvoDokiSetMoreToggleArrow(isOpen) {
        var $btn = $('#yvoDokiTypeMoreToggle');
        if (!$btn.length) {
            return;
        }
        var $caret = $btn.find('.yvo-doki-btn-caret');
        if ($caret.length) {
            $caret.text(isOpen ? '▲' : '▾');
            return;
        }
        var base = $btn.text().replace(/\s*[▾▲]\s*$/, '');
        $btn.text(base + (isOpen ? ' ▲' : ' ▾'));
    }
    function yvoDokiSetExtrasVisible(show) {
        $('.yvo-doki-type-extra').each(function() {
            if (show) {
                this.removeAttribute('hidden');
            } else {
                this.setAttribute('hidden', 'hidden');
            }
        });
        $('#yvoDokiTypeMoreToggle').attr('aria-expanded', show ? 'true' : 'false');
        yvoDokiSetMoreToggleArrow(!!show);
    }
    function syncDokiContractTypeUI(type) {
        if (!$('body').hasClass('yvo-contract-form-doki-page') && !$('.yvo-doki-contract-page').length) return;
        $('.yvo-doki-type-pick').removeClass('active');
        $('.yvo-doki-type-pick[data-contract-type="' + type + '"]').addClass('active');
        var short = dokiBadgeLabels[type] || (contractTypeLabels[type] ? String(contractTypeLabels[type]).replace(/^\d+\.\s*/, '') : type);
        var $badge = $('#yvoDokiFormTypeBadge');
        if ($badge.length) $badge.text(short);
        yvoDokiSetExtrasVisible(false);
    }

    /** Единая точка: тип договора, шаблоны, вкладки участников, бейдж ДОКИ. Не полагаться на trigger('click') по скрытому меню — в части браузеров он не вызывает обработчики. */
    function yvoApplyFrontendContractType(type) {
        if (!type) {
            return;
        }
        if (!yvoFpDraftRestoring && yvoFpGuardPaidFeature('contract', type)) {
            return;
        }
        var prevType = currentContractType;
        if (prevType && prevType !== type && !yvoFpDraftRestoring) {
            yvoFpSaveDraft(true);
        }
        currentContractType = type;
        var label = contractTypeLabels[type] || type;
        var $ctBtn = $('#yvo-fp-contract-type-btn');
        if ($ctBtn.length && $ctBtn.contents().length) {
            try {
                $ctBtn.contents().first().replaceWith(document.createTextNode(label + ' '));
            } catch (err) {}
        }
        $('#yvo-fp-contract-type-dropdown').removeClass('open');
        updateActReceiptVisibility();
        updateContractTemplateOptions(type);
        syncDokiContractTypeUI(type);
        yvoUpdateDokiFormsIntroForContractType();
        try {
            yvoUpdateTabLabelsForContractType();
        } catch (yvoTabLabErr) {}
        yvoSyncDokiVisibleChromeForContractType();
        try {
            yvoFpEnsureGuardiansForAllMinors();
            yvoFpNormalizeAllMinorPanels();
        } catch (yvoMinorsSyncErr) {}
        $(document).trigger('yvo-contract-type-changed', [type]);
        if (!yvoFpDraftRestoring) {
            yvoFpScheduleDraftSave();
        }
    }

    // Для всех типов, кроме выделения долей, доли продавцов/покупателей автозаполняются и показываются только при необходимости.
    $(document).on('yvo-contract-type-changed', function(e, type) {
        type = type || currentContractType;
        if (type === 'gift') {
            var $sel = $('#yvo-fp-contract-template');
            if ($sel.length && $sel.find('option[value="shablon-darenie-dogovor"]').length) {
                var curGift = $sel.val();
                if (!curGift || curGift === 'default' || curGift === 'gift-kvartira-apartment') {
                    $sel.val('shablon-darenie-dogovor');
                }
            }
        }
        yvoUpdateDefaultSharesForContract();
    });

    function yvoUpdateDokiFormsIntroForContractType() {
        var ct = currentContractType;
        var $intro = $('.yvo-doki-forms-intro');
        if ($intro.length) {
            if (ct === 'gift') {
                $intro.text('По умолчанию: один даритель и один одаряемый. В договор попадут только те, у кого заполнено ФИО.');
            } else if (ct === 'share_allocation') {
                $intro.text('Укажите участников с обеих сторон и доли (блок «Доли участников» в карточке объекта). В договор попадут только те, у кого заполнено ФИО.');
            } else {
                $intro.text('По умолчанию: один продавец и один покупатель. В договор попадут только те, у кого заполнено ФИО.');
            }
        }
        var $mh = $('#yvoDokiMultiParticipantHint');
        if ($mh.length) {
            if (ct === 'gift') {
                $mh.text('Несколько участников: переключайтесь вкладками «Даритель», «Даритель 2»… сразу под шагами (не путать с кнопками шагов сверху).');
            } else if (ct === 'share_allocation') {
                $mh.text('Несколько участников: вкладки «Участник 1», «Участник 2»… — сразу под шагами.');
            } else {
                $mh.text('Несколько участников: переключайтесь вкладками «Продавец», «Продавец 2»… сразу под шагами (не путать с кнопками шагов сверху).');
            }
        }
    }

    $(document).on('click', '#yvo-fp-contract-type-menu .yvo-fp-dropdown-item', function(e) {
        e.stopPropagation();
        var type = $(this).attr('data-contract-type') || $(this).data('contractType');
        if (!type) {
            return;
        }
        yvoApplyFrontendContractType(type);
    });
    $(document).on('click', '.yvo-doki-type-pick', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var type = $(this).attr('data-contract-type') || $(this).data('contractType');
        if (!type) {
            return;
        }
        yvoApplyFrontendContractType(type);
    });
    $(document).on('click', '#yvoDokiTypeMoreToggle', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var el = $('.yvo-doki-type-extra').get(0);
        var open = el && el.hasAttribute('hidden');
        yvoDokiSetExtrasVisible(open);
    });
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#yvo-doki-contract-type-picker').length) {
            yvoDokiSetExtrasVisible(false);
        }
    });
    updateActReceiptVisibility();
    syncDokiContractTypeUI(currentContractType);
    yvoUpdateDokiFormsIntroForContractType();
    setTimeout(function() {
        try {
            yvoUpdateTabLabelsForContractType();
        } catch (yvoInitLab) {}
        yvoSyncDokiVisibleChromeForContractType();
    }, 0);

    // Шаблоны по типу договора: фильтрация по категориям (категории заданы на бэкенде)
    var allowedCategoriesByType = {
        sale: ['sale', 'sale_mortgage'],
        sale_mortgage: ['sale_mortgage', 'sale'],
        assignment: ['assignment'],
        gift: ['gift'],
        share_allocation: ['share_allocation'],
        deposit_agreement: ['deposit_agreement'],
        advance_agreement: ['advance_agreement'],
        preliminary: ['preliminary']
    };
    var dkpPaymentNeedles = {
        'mortgage|accreditive': ['ДКП_ипотека_аккредитив', 'ipoteka_akkreditiv'],
        'mortgage|cell': ['ДКП_ипотека_ячейка', 'ipoteka_yacheyka', 'ipoteka_yaweika'],
        'mortgage|day_of_deal': ['ДКП_ипотека_в_день_сделки', 'ipoteka_v_den', 'ipoteka_den'],
        'cash|accreditive': ['ДКП_наличные_аккредитив', 'nalichnye_akkreditiv'],
        'cash|cell': ['ДКП_наличные_ячейка', 'nalichnye_yacheyka'],
        'cash|day_of_deal': ['ДКП_наличные_в_день_сделки', 'nalichnye_den', 'nalichnye_v_den']
    };
    var dkpPaymentFallbackIds = {
        'mortgage|accreditive': ['default', 'dkp-kvartira-ipoteka'],
        'mortgage|cell': ['default'],
        'mortgage|day_of_deal': ['default'],
        'cash|accreditive': ['default', 'dkp-nalichnye-akkreditiv-podpisi'],
        'cash|cell': ['default'],
        'cash|day_of_deal': ['default']
    };
    function yvoFpGetDkpPaymentParams() {
        var pt = $('#property_payment_type').val() || 'cash';
        var pm = pt === 'mortgage'
            ? ($('#property_payment_method_mortgage').val() || 'day_of_deal')
            : ($('#property_payment_method_cash').val() || 'day_of_deal');
        return { pt: pt, pm: pm };
    }
    function yvoFpTemplateIsHiddenFromDkpPicker(templateId) {
        var id = String(templateId || '');
        var idLower = id.toLowerCase();
        if (id === 'act-sale-standard') return true;
        if (/акт_приема|акт приема|act[-_ ]sale/i.test(id) || /akt[-_ ]priema/i.test(idLower)) return true;
        if (/^ПОЛНЫЙ_ТЕКСТ_|^ТЕКСТ_/i.test(id)) return true;
        return false;
    }
    function yvoFpTemplateMatchesDkpPayment(templateId, pt, pm) {
        var id = String(templateId || '');
        if (yvoFpTemplateIsHiddenFromDkpPicker(id)) return false;
        var key = String(pt || 'cash') + '|' + String(pm || 'day_of_deal');
        var needles = dkpPaymentNeedles[key] || [];
        var fallbacks = dkpPaymentFallbackIds[key] || [];
        if (fallbacks.indexOf(id) !== -1) return true;
        for (var i = 0; i < needles.length; i++) {
            if (id.indexOf(needles[i]) !== -1) return true;
        }
        return false;
    }
    function yvoFpDedupeTemplateOptionsByLabel(list) {
        var seen = {};
        var out = [];
        (list || []).forEach(function(o) {
            var k = String(o.label || o.value || '').trim().toLowerCase();
            if (!k || seen[k]) return;
            seen[k] = true;
            out.push(o);
        });
        return out;
    }
    var contractTemplateOptions = {
        gift: [
            { value: 'shablon-darenie-dogovor', label: 'Договор дарения (квартира, дом, земля и др.)' },
            { value: 'shablon-darenie-dolya-kvartira', label: 'Дарение доли в квартире' }
        ],
        share_allocation: [{ value: 'shablon-vydelenie-doley-kvartira', label: 'Соглашение о выделении долей в квартире несовершеннолетним' }],
        deposit_agreement: [
            { value: 'deposit-agreement', label: 'Договор задатка (квартира)' },
            { value: 'deposit-agreement-house', label: 'Договор задатка (дом с землёй)' },
            { value: 'deposit-agreement-room', label: 'Договор задатка (комната)' },
            { value: 'deposit-agreement-land', label: 'Договор задатка (земля)' }
        ],
        advance_agreement: [
            { value: 'advance-agreement', label: 'Договор аванса (квартира)' },
            { value: 'advance-agreement-house', label: 'Договор аванса (дом с землёй)' },
            { value: 'advance-agreement-room', label: 'Договор аванса (комната)' },
            { value: 'advance-agreement-land', label: 'Договор аванса (земля)' }
        ],
        preliminary: [
            { value: 'preliminary', label: 'Предварительный договор купли-продажи' }
        ]
    };
    var builtinTemplatesFallback = { default: 'ДКП вариант 2', 'dkp-sale-standard': 'ДКП стандартный' };
    var builtinCategoriesFallback = { default: 'sale' };
    function getTemplateOptionsForType(contractType) {
        var all = (typeof window.yvo_contract_templates === 'object' && window.yvo_contract_templates && Object.keys(window.yvo_contract_templates).length > 0) ? window.yvo_contract_templates : builtinTemplatesFallback;
        var categories = (typeof window.yvo_template_categories === 'object' && window.yvo_template_categories && Object.keys(window.yvo_template_categories).length > 0) ? window.yvo_template_categories : builtinCategoriesFallback;
        var allowed = categories && allowedCategoriesByType[contractType];
        if (contractType === 'gift') {
            return contractTemplateOptions.gift || [];
        }
        if (contractType === 'deposit_agreement') {
            return contractTemplateOptions.deposit_agreement || [];
        }
        if (contractType === 'advance_agreement') {
            return contractTemplateOptions.advance_agreement || [];
        }
        if (contractType === 'preliminary') {
            return contractTemplateOptions.preliminary || [];
        }
        if (allowed) {
            var list = [];
            for (var id in all) {
                if (!all.hasOwnProperty(id) || allowed.indexOf(categories[id]) === -1) continue;
                if (id.indexOf('ПРОЧТИ_МЕНЯ') !== -1) continue;
                var lab = all[id];
                if (lab === 'ПРОЧТИ МЕНЯ' || lab === 'МЕНЯ') continue;
                list.push({ value: id, label: lab });
            }
            if (list.length) {
                if (contractType === 'sale' || contractType === 'sale_mortgage') {
                    var pay = yvoFpGetDkpPaymentParams();
                    var effectivePt = (contractType === 'sale_mortgage') ? 'mortgage' : pay.pt;
                    list = list.filter(function(o) {
                        return yvoFpTemplateMatchesDkpPayment(o.value, effectivePt, pay.pm);
                    });
                    list = yvoFpDedupeTemplateOptionsByLabel(list);
                }
                return list;
            }
        }
        var opts = contractTemplateOptions[contractType];
        if (Array.isArray(opts)) {
            return opts.map(function(o) {
                // Для gift / share_allocation value «default» — плейсхолдер: не подменять подписью «ДКП» из каталога
                var lab = (o.value === 'default' && o.label) ? o.label : (all[o.value] || o.label);
                return { value: o.value, label: lab };
            });
        }
        var list = [];
        for (var id in all) {
            if (all.hasOwnProperty(id)) { list.push({ value: id, label: all[id] }); }
        }
        list = list.filter(function(o) { return o.value.indexOf('ПРОЧТИ_МЕНЯ') === -1 && o.label !== 'ПРОЧТИ МЕНЯ' && o.label !== 'МЕНЯ'; });
        return list.length ? list : [{ value: 'default', label: 'ДКП вариант 2' }];
    }
    function updateContractTemplateOptions(contractType) {
        var opts = getTemplateOptionsForType(contractType);
        var $sel = $('#yvo-fp-contract-template');
        var cur = $sel.val();
        $sel.empty();
        if (!opts || opts.length === 0) {
            opts = [{ value: 'default', label: 'ДКП вариант 2' }];
        }
        opts.forEach(function(o) {
            $sel.append($('<option>').attr('value', o.value).text(o.label));
        });
        if (opts.some(function(o) { return o.value === cur; })) {
            $sel.val(cur);
        } else if (opts[0]) {
            $sel.val(opts[0].value);
        }
        if (contractType === 'gift') {
            yvoFpSyncGiftTemplateForObjectType();
        }
        if (contractType === 'deposit_agreement') {
            yvoFpSyncDepositTemplateForObjectType();
        }
        if (contractType === 'advance_agreement') {
            yvoFpSyncAdvanceTemplateForObjectType();
        }
        if (contractType === 'preliminary') {
            var $selP = $('#yvo-fp-contract-template');
            if ($selP.find('option[value="preliminary"]').length) {
                $selP.val('preliminary');
            }
        }
        updateBankRowVisibility();
        yvoFpSyncRelatedContractButtons(contractType);
    }
    function yvoFpSyncRelatedContractButtons(contractType) {
        var $row = $('#yvo-fp-related-contracts');
        if (!$row.length) return;
        var family = {
            sale: true,
            sale_mortgage: true,
            preliminary: true,
            deposit_agreement: true,
            advance_agreement: true
        };
        var show = !!family[contractType || currentContractType];
        $row.toggle(show);
        if (!show) return;
        var ct = contractType || currentContractType;
        var map = {
            sale: 'sale',
            sale_mortgage: 'sale',
            preliminary: 'preliminary',
            deposit_agreement: 'deposit_agreement',
            advance_agreement: 'advance_agreement'
        };
        var active = map[ct] || 'sale';
        $row.find('.yvo-fp-related-contract-btn').each(function() {
            var t = $(this).attr('data-contract-type') || '';
            $(this).toggleClass('is-active', t === active);
        });
    }
    $(document).on('click', '#yvo-fp-related-contracts .yvo-fp-related-contract-btn', function(e) {
        e.preventDefault();
        var type = $(this).attr('data-contract-type') || 'sale';
        var tpl = $(this).attr('data-template-id') || '';
        yvoApplyFrontendContractType(type);
        if (tpl) {
            var $sel = $('#yvo-fp-contract-template');
            if ($sel.find('option[value="' + tpl + '"]').length) {
                $sel.val(tpl);
            }
        }
        yvoFpSyncRelatedContractButtons(type);
        // Прокрутка к выбору шаблона — пользователь сразу видит актуальный список
        var el = document.getElementById('yvo-fp-contract-template');
        if (el && typeof el.scrollIntoView === 'function') {
            try { el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (err) {}
        }
    });
    $(document).on('yvo-contract-type-changed', function(e, type) {
        yvoFpSyncRelatedContractButtons(type || currentContractType);
    });
    function updateBankRowVisibility() {
        var tid = $('#yvo-fp-contract-template').val();
        var cat = (typeof window.yvo_template_categories === 'object' && window.yvo_template_categories) ? window.yvo_template_categories[tid] : '';
        var isMortgage = (cat === 'sale_mortgage');
        $('#yvo-fp-bank-row').toggle(isMortgage);
    }
    $(document).on('change', '#yvo-fp-contract-template', updateBankRowVisibility);
    updateContractTemplateOptions(currentContractType);
    yvoFpSyncRelatedContractButtons(currentContractType);
    $(document).on('click', '#yvo-fp-object-type-menu .yvo-fp-dropdown-item, .yvo-doki-object-type-pick', function(e) {
        e.stopPropagation();
        var type = $(this).data('object-type') || $(this).attr('data-object-type');
        if (yvoFpGuardPaidFeature('object', type)) {
            return;
        }
        yvoFpApplyPropertyObjectType(type);
        $('#yvo-fp-object-type-dropdown').removeClass('open');
        yvoUpdateDefaultSharesForContract();
    });

    // ——— Сворачиваемые блоки (Объект: Параметры, Цена, Условия) ———
    $(document).on('click', '.yvo-fp-collapse-header', function() {
        var id = $(this).data('collapse');
        var $body = $('#' + id);
        var open = !$body.is(':visible');
        $body.toggle(open);
        $(this).attr('aria-expanded', open);
        $(this).closest('.yvo-fp-collapse-block').toggleClass('collapsed', !open);
        if (id === 'yvo-fp-cabinet-body' && open && typeof loadCabinetList === 'function') loadCabinetList();
    });

    // ——— Адрес: скрываем детали и помогаем заполнить из полного адреса ———
    function setAddressDetailsVisible(scope, show) {
        var $panel = $('#yvo-fp-panel-property');
        var $details = $panel.find('.yvo-fp-address-details[data-address-scope="' + scope + '"]');
        var $toggleBtn = $panel.find('.yvo-fp-address-toggle[data-address-scope="' + scope + '"]');
        $details.toggle(!!show);
        $toggleBtn.text(show ? 'Скрыть поля адреса' : 'Показать поля адреса');
    }

    function parseAddressSimple(address) {
        var a = String(address || '').replace(/\s+/g, ' ').trim();
        a = a.replace(/\бород\s+/gi, 'город ').replace(/\бица\s+/gi, 'улица ');
        var out = {};
        if (!a) return out;

        function pick(re) {
            var m = a.match(re);
            var v = m && m[1] ? String(m[1]).trim() : '';
            if (v && /^[оО]род\s/u.test(v)) v = v.replace(/^[оО]род\s*/u, '').trim();
            if (v && /^ородской/ui.test(v)) v = 'г' + v;
            if (v && /^[иИ]ца\s/u.test(v)) v = v.replace(/^[иИ]ца\s*/u, '').trim();
            if (v && /^[оО]м$/u.test(v)) v = '';
            return v;
        }

        var cityMatches = a.match(/(?:^|,\s*)г\s+([^,]+)/gi);
        if (cityMatches && cityMatches.length) {
            var lastCity = cityMatches[cityMatches.length - 1].replace(/^(?:^|,\s*)г\s+/i, '').trim();
            if (lastCity) out.city = lastCity;
        }
        if (!out.city) {
            var okrug = pick(/(?:^|,\s*)городской\s+округ\s+([^,]+)/i);
            if (okrug) {
                out.city = ('городской округ ' + okrug).replace(/\s+/g, ' ').trim();
            } else {
                out.city = pick(/(?:^|,\s*)г\.о\.\s+город\s+([^,]+)/i)
                    || pick(/(?:^|,\s*)г\.\s+([^,]+)/i)
                    || pick(/(?:^|,\s*)город\s+([^,]+)/i)
                    || pick(/(?:^|,\s*)г\s+([^,]+)/i);
            }
        }
        out.street = pick(/(?:^|,\s*)(?:ул\.?\s*|улица\s+|ул\s+|пр-кт\.?\s*|проспект\s+|пр\.\s*|проезд\s+|пер\.?\s*|переулок\s+)([^,]+)/i);
        out.house = pick(/(?:^|,\s*)(?:д\.?\s*|дом\s+|д\s+)([0-9A-Za-zА-Яа-яЁё\/\-]+)/i);
        out.building = pick(/(?:^|,\s*)(?:корп\.?\s*|корпус\s+)([0-9A-Za-zА-Яа-яЁё\/\-]+)/i);
        out.apartment = pick(/(?:^|,\s*)(?:кв\.?\s*|квартира\s+)([0-9A-Za-zА-Яа-яЁё\/\-]+)/i);
        if (!out.building) {
            var hm = a.match(/(?:^|,\s*)(?:д\.?\s*|дом\s+)(\d+)\s*(?:к|корп\.?)\s*([0-9A-Za-zА-Яа-яЁё\/\-]+)/i);
            if (hm && hm[2]) out.building = String(hm[2]).trim();
        }
        if (!out.house) {
            var h2 = a.match(/(?:^|,\s*)дом\s*[:\s]*([0-9A-Za-zА-Яа-яЁё\/\-]+)/i);
            if (h2 && h2[1]) out.house = String(h2[1]).trim();
        }
        return out;
    }

    function fillAddressFieldsFromFull(scope) {
        var $panel = $('#yvo-fp-panel-property');
        var $block = $panel.find('.yvo-fp-object-type-block.active');
        if (!$block.length) return;
        var addr = $block.find('[data-key="address"]').val() || '';
        addr = String(addr).trim();
        var $details = $panel.find('.yvo-fp-address-details[data-address-scope="' + scope + '"]');
        if (!$details.length) return;

        if (!addr) {
            showError('Введите полный адрес в поле «Адрес (полный)» выше, затем нажмите «Заполнить из полного адреса».');
            return;
        }

        var parsed = parseAddressSimple(addr);
        var didFill = false;
        ['city', 'street', 'house', 'building', 'apartment'].forEach(function(k) {
            var v = parsed[k];
            if (!v) return;
            var $inp = $details.find('[data-key="' + k + '"]');
            if ($inp.length) {
                $inp.val(v);
                didFill = true;
            }
        });

        setAddressDetailsVisible(scope, true);
        if (didFill) {
            $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Данные подставлены в форму «Объект недвижимости».').show();
            setTimeout(function() { $error.fadeOut(); }, 3500);
        } else {
            showError('Не удалось разобрать адрес из строки. Проверьте формат (например: город Уфа, улица Ленина, дом 1, кв. 5) или заполните поля вручную.');
        }
    }

    $(document).on('click', '.yvo-fp-address-toggle', function(e) {
        e.preventDefault();
        var scope = $(this).data('address-scope') || 'apartment';
        var $panel = $('#yvo-fp-panel-property');
        var $details = $panel.find('.yvo-fp-address-details[data-address-scope="' + scope + '"]');
        setAddressDetailsVisible(scope, !$details.is(':visible'));
    });
    $(document).on('click', '.yvo-fp-address-parse', function(e) {
        e.preventDefault();
        var scope = $(this).data('address-scope') || 'apartment';
        fillAddressFieldsFromFull(scope);
    });

    // ——— Автоподбор адреса (подсказки) через Nominatim (OSM) ———
    var $addrSuggest = $('<div class="yvo-fp-address-suggest" id="yvo-fp-address-suggest" style="display:none;"></div>');
    $('body').append($addrSuggest);
    var addrSuggestAbort = null;
    var addrSuggestTimer = null;
    var lastAddrQuery = '';
    var activeAddrInput = null;

    function positionAddrSuggest($input) {
        var off = $input.offset();
        if (!off) return;
        $addrSuggest.css({
            left: off.left,
            top: off.top + $input.outerHeight(),
            width: $input.outerWidth()
        });
    }

    function closeAddrSuggest() {
        $addrSuggest.hide().empty();
        activeAddrInput = null;
        lastAddrQuery = '';
        if (addrSuggestAbort && typeof addrSuggestAbort.abort === 'function') {
            try { addrSuggestAbort.abort(); } catch (e) {}
        }
        addrSuggestAbort = null;
    }

    function nominatimToParts(addr) {
        // addr = address{} от Nominatim reverse/search
        var a = addr || {};
        var city = a.city || a.town || a.village || a.hamlet || a.municipality || '';
        var street = a.road || a.pedestrian || a.footway || a.street || a.residential || '';
        var house = a.house_number || '';
        var building = a.block || a.building || '';
        return {
            city: city,
            street: street,
            house: house,
            building: building
        };
    }

    function applyAddressPartsToActiveObject(parts) {
        var $panel = $('#yvo-fp-panel-property');
        var scope = ($('#property_object_type').val() === 'room') ? 'room' : 'apartment';
        var $details = $panel.find('.yvo-fp-address-details[data-address-scope="' + scope + '"]');
        if ($details.length) {
            if (parts.city) $details.find('[data-key="city"]').val(parts.city);
            if (parts.street) $details.find('[data-key="street"]').val(parts.street);
            if (parts.house) $details.find('[data-key="house"]').val(parts.house);
            if (parts.building) $details.find('[data-key="building"]').val(parts.building);
            setAddressDetailsVisible(scope, true);
        }
    }

    function fetchAddressSuggest(query, $input) {
        if (!query || query.length < 4) {
            closeAddrSuggest();
            return;
        }
        if (query === lastAddrQuery) return;
        lastAddrQuery = query;

        if (addrSuggestAbort && typeof addrSuggestAbort.abort === 'function') {
            try { addrSuggestAbort.abort(); } catch (e) {}
        }

        addrSuggestAbort = $.ajax({
            url: 'https://nominatim.openstreetmap.org/search',
            method: 'GET',
            dataType: 'json',
            data: {
                format: 'json',
                addressdetails: 1,
                limit: 6,
                q: query
            },
            headers: {
                'Accept-Language': 'ru'
            },
            timeout: 8000
        }).done(function(list) {
            if (!$input || !$input.length) return;
            if (!list || !list.length) {
                closeAddrSuggest();
                return;
            }
            $addrSuggest.empty();
            list.forEach(function(item) {
                var label = item && item.display_name ? String(item.display_name) : '';
                if (!label) return;
                var $b = $('<button type="button"></button>').text(label);
                $b.data('item', item);
                $addrSuggest.append($b);
            });
            if (!$addrSuggest.children().length) {
                closeAddrSuggest();
                return;
            }
            activeAddrInput = $input[0];
            positionAddrSuggest($input);
            $addrSuggest.show();
        }).fail(function() {
            // молча
        });
    }

    // адрес (полный) в активном блоке объекта
    $(document).on('input', '#yvo-fp-panel-property .yvo-fp-object-type-block.active [data-key="address"]', function() {
        var $input = $(this);
        var q = String($input.val() || '').trim();
        clearTimeout(addrSuggestTimer);
        addrSuggestTimer = setTimeout(function() {
            fetchAddressSuggest(q, $input);
        }, 250);
    });

    $(document).on('focus', '#yvo-fp-panel-property .yvo-fp-object-type-block.active [data-key="address"]', function() {
        var $input = $(this);
        var q = String($input.val() || '').trim();
        if (q.length >= 4) {
            positionAddrSuggest($input);
        }
    });

    $(window).on('resize scroll', function() {
        if (!activeAddrInput) return;
        var $input = $(activeAddrInput);
        if ($input.length) positionAddrSuggest($input);
    });

    $(document).on('click', function(e) {
        if ($(e.target).closest('#yvo-fp-address-suggest').length) return;
        if ($(e.target).closest('#yvo-fp-panel-property .yvo-fp-object-type-block.active [data-key="address"]').length) return;
        closeAddrSuggest();
    });

    $(document).on('click', '#yvo-fp-address-suggest button', function(e) {
        e.preventDefault();
        var item = $(this).data('item') || null;
        if (!item) return;
        var label = item.display_name ? String(item.display_name) : '';
        if (activeAddrInput) {
            $(activeAddrInput).val(label);
        }
        applyAddressPartsToActiveObject(nominatimToParts(item.address));
        closeAddrSuggest();
    });

    // ——— Цена: свои средства / ипотека ———
    function yvoDokiFinancePillActiveClasses() {
        if (!$('.yvo-doki-finance-pills').length) return;
        $('.yvo-doki-finance-pills .yvo-doki-choice-pill').removeClass('active');
        $('.yvo-doki-finance-pills .yvo-doki-choice-pill input:checked').closest('label').addClass('active');
    }
    function yvoDokiSyncFinancePillsFromSelects() {
        if (!$('.yvo-doki-finance-pills').length) return;
        var pt = $('#property_payment_type').val() || 'cash';
        $('.yvo-doki-finance-pills input[name="yvo_doki_pt"][value="' + pt + '"]').prop('checked', true);
        var pm = $('#property_payment_type').val() === 'mortgage'
            ? ($('#property_payment_method_mortgage').val() || 'day_of_deal')
            : ($('#property_payment_method_cash').val() || 'day_of_deal');
        var $set = $('.yvo-doki-finance-pills input[name="yvo_doki_settlement"][value="' + pm + '"]');
        if ($set.length) $set.prop('checked', true);
        yvoDokiFinancePillActiveClasses();
    }
    $(document).on('change', '#property_payment_type', function() {
        var v = $(this).val();
        $('.yvo-fp-price-cash').toggle(v === 'cash');
        $('.yvo-fp-price-mortgage').toggle(v === 'mortgage');
        yvoDokiSyncFinancePillsFromSelects();
    });
    $(document).on('change', '.yvo-doki-finance-pills input[name="yvo_doki_pt"]', function() {
        $('#property_payment_type').val($(this).val()).trigger('change');
        yvoFpRefreshTemplatesForPayment();
    });
    $(document).on('change', '.yvo-doki-finance-pills input[name="yvo_doki_settlement"]', function() {
        var v = $(this).val();
        $('#property_payment_method_cash').val(v);
        $('#property_payment_method_mortgage').val(v);
        yvoDokiFinancePillActiveClasses();
        yvoFpRefreshTemplatesForPayment();
    });
    $('#property_payment_type').trigger('change');
    yvoFpRefreshTemplatesForPayment();

    // ——— Сумма прописью: авто по числу (рубли) ———
    function numberToWordsRu(n) {
        var num = Math.floor(Number(n));
        if (isNaN(num) || num < 0) return '';
        if (num === 0) return 'ноль рублей';
        var ones = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
        var onesF = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
        var teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        var tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        var hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
        function triad(t, female) {
            var o = t % 10, d = Math.floor(t / 10) % 10, h = Math.floor(t / 100);
            var str = hundreds[h];
            if (d === 1) str += (str ? ' ' : '') + teens[o];
            else {
                if (tens[d]) str += (str ? ' ' : '') + tens[d];
                str += (str ? ' ' : '') + (female ? onesF[o] : ones[o]);
            }
            return str.trim();
        }
        var r = num % 1000, th = Math.floor(num / 1000) % 1000, m = Math.floor(num / 1000000) % 1000, b = Math.floor(num / 1000000000);
        var s = '';
        if (b) s = triad(b, false) + ' миллиард' + (b === 1 ? ' ' : b >= 2 && b <= 4 ? 'а ' : 'ов ');
        if (m) s += triad(m, false) + ' миллион' + (m === 1 ? ' ' : m >= 2 && m <= 4 ? 'а ' : 'ов ');
        if (th) {
            s += triad(th, true) + ' ';
            if (th % 100 >= 11 && th % 100 <= 14) s += 'тысяч ';
            else if (th % 10 === 1) s += 'тысяча ';
            else if (th % 10 >= 2 && th % 10 <= 4) s += 'тысячи ';
            else s += 'тысяч ';
        }
        s += triad(r, false);
        if (r % 100 >= 11 && r % 100 <= 14) s += ' рублей';
        else if (r % 10 === 1) s += ' рубль';
        else if (r % 10 >= 2 && r % 10 <= 4) s += ' рубля';
        else s += ' рублей';
        return s.trim();
    }
    function fillWordsFromNumber(numInputId, wordsInputId) {
        var n = $(numInputId).val();
        if (n === '' || isNaN(parseFloat(n))) return;
        $(wordsInputId).val(numberToWordsRu(n));
    }
    $(document).on('input change', '#property_price', function() { fillWordsFromNumber('#property_price', '#property_price_words'); });
    $(document).on('input change', '#property_deposit_amount', function() { fillWordsFromNumber('#property_deposit_amount', '#property_deposit_amount_words'); });
    $(document).on('input change', '#property_loan_amount', function() { fillWordsFromNumber('#property_loan_amount', '#property_loan_amount_words'); });
    $(document).on('input change', '#property_loan_own_amount', function() { fillWordsFromNumber('#property_loan_own_amount', '#property_loan_own_amount_words'); });

    function yvoFpResolveDkpTemplateIdFromPayment(property) {
        if (currentContractType !== 'sale' && currentContractType !== 'sale_mortgage') return null;
        property = property || {};
        var pt = property.payment_type || $('#property_payment_type').val() || 'cash';
        var pm = pt === 'mortgage'
            ? (property.payment_method_mortgage || $('#property_payment_method_mortgage').val() || 'day_of_deal')
            : (property.payment_method_cash || $('#property_payment_method_cash').val() || 'day_of_deal');
        var needles = {
            'mortgage|accreditive': 'ДКП_ипотека_аккредитив',
            'mortgage|cell': 'ДКП_ипотека_ячейка',
            'mortgage|day_of_deal': 'ДКП_ипотека_в_день_сделки',
            'cash|accreditive': 'ДКП_наличные_аккредитив',
            'cash|cell': 'ДКП_наличные_ячейка',
            'cash|day_of_deal': 'ДКП_наличные_в_день_сделки'
        };
        var needle = needles[pt + '|' + pm];
        if (!needle) return null;
        var $sel = $('#yvo-fp-contract-template');
        var found = null;
        $sel.find('option').each(function() {
            var v = String($(this).val() || '');
            if (v.indexOf(needle) !== -1) { found = v; return false; }
        });
        if (!found && pt === 'mortgage' && pm === 'accreditive' && $sel.find('option[value="default"]').length) found = 'default';
        if (!found && pt === 'mortgage' && $sel.find('option[value="dkp-kvartira-ipoteka"]').length) found = 'dkp-kvartira-ipoteka';
        if (!found && pt === 'cash' && pm === 'accreditive' && $sel.find('option[value="dkp-nalichnye-akkreditiv-podpisi"]').length) {
            found = 'dkp-nalichnye-akkreditiv-podpisi';
        }
        return found;
    }
    function yvoFpRefreshTemplatesForPayment() {
        if (currentContractType !== 'sale' && currentContractType !== 'sale_mortgage') return;
        updateContractTemplateOptions(currentContractType);
        yvoFpSyncDkpTemplateFromPayment();
    }
    function yvoFpSyncDkpTemplateFromPayment(property) {
        if (currentContractType !== 'sale' && currentContractType !== 'sale_mortgage') return;
        var tid = yvoFpResolveDkpTemplateIdFromPayment(property);
        if (tid) {
            $('#yvo-fp-contract-template').val(tid);
            updateBankRowVisibility();
        }
    }
    $(document).on('change', '#property_payment_type, #property_payment_method_cash, #property_payment_method_mortgage', function() {
        yvoFpRefreshTemplatesForPayment();
    });

    var fixedTabs = ['seller', 'buyer', 'property'];
    var copiedTabData = null;
    window.yvoCabinetCopiedData = null;
    function yvoSetCabinetCopiedData(data) { window.yvoCabinetCopiedData = data; }

    /** Вкладки участников — только в полоске #yvo-fp-participant-tabs (fallback: .yvo-fp-tabs-wrap > .yvo-fp-tabs). Не завязываемся на id секции #yvo-fp-forms-section — он может отсутствовать. */
    function yvoFpParticipantTabStripEl() {
        var $t = $('#yvo-fp-participant-tabs');
        if ($t.length) return $t;
        $t = $('.yvo-frontend-page .yvo-fp-tabs-wrap > .yvo-fp-tabs').first();
        if ($t.length) return $t;
        return $('.yvo-fp-tabs-wrap > .yvo-fp-tabs').first();
    }
    function yvoFpParticipantTabButtons() {
        var $byId = $('#yvo-fp-participant-tabs');
        if ($byId.length) {
            return $byId.find('.yvo-fp-tab');
        }
        var $strip = yvoFpParticipantTabStripEl();
        return $strip.length ? $strip.find('.yvo-fp-tab') : $();
    }
    function yvoFpTabPanelsEl() {
        var $p = $('#yvo-fp-tab-panels');
        return $p.length ? $p : $('.yvo-fp-tab-panels').first();
    }
    function yvoFpAllParticipantPanels() {
        return yvoFpTabPanelsEl().find('.yvo-fp-panel');
    }
    window.yvoFpParticipantTabStripEl = yvoFpParticipantTabStripEl;
    window.yvoFpParticipantTabButtons = yvoFpParticipantTabButtons;

    /** Вкладка относится к стороне продавца (включая доверенных и несовершеннолетних продавцов). */
    function yvoFpTabIsSellerSide(tab) {
        if (!tab || tab === 'property') return false;
        // Жёсткое взаимное исключение, чтобы «buyer_*» никогда не уехало в продавцов
        if (/^buyer/.test(tab) || /^contributor/.test(tab) || /^minor_buyer/.test(tab) || /^guardian_buyer/.test(tab)) return false;
        if (/^seller_representative/.test(tab)) return true;
        if (/^minor_seller/.test(tab)) return true;
        if (/^guardian_seller/.test(tab)) return true;
        // seller, seller2 … seller10 (не seller_representative — там после «seller» не только цифры)
        return tab === 'seller' || /^seller\d+$/.test(tab);
    }
    /** Вкладка относится к стороне покупателя (включая доверенных, вносителя, несовершеннолетнего). */
    function yvoFpTabIsBuyerSide(tab) {
        if (!tab) return false;
        // Жёсткое взаимное исключение, чтобы «seller_*» никогда не уехало в покупателей
        if (/^seller/.test(tab) || /^minor_seller/.test(tab) || /^guardian_seller/.test(tab)) return false;
        if (/^buyer_representative/.test(tab)) return true;
        if (/^contributor/.test(tab)) return true;
        if (/^minor_buyer/.test(tab)) return true;
        if (/^guardian_buyer/.test(tab)) return true;
        return tab === 'buyer' || /^buyer\d+$/.test(tab);
    }
    /** Сбор продавцов и покупателей в порядке вкладок (количество и состав совпадают с формой). */
    function yvoFpCollectSellersAndBuyersInTabOrder() {
        var sellers = [];
        var buyers = [];
        yvoFpParticipantTabButtons().each(function() {
            var tab = $(this).attr('data-tab');
            if (yvoFpTabIsSellerSide(tab)) sellers.push(collectFormData(tab));
            else if (yvoFpTabIsBuyerSide(tab)) buyers.push(collectFormData(tab));
        });
        return {
            sellers: yvoFpResolveGuardianLinksInRows(sellers),
            buyers: yvoFpResolveGuardianLinksInRows(buyers)
        };
    }

    /**
     * Видимые подписи ДОКИ (шаги, футер шага, карточка, загрузка) — не только скрытая полоска #yvo-fp-participant-tabs.
     * Раньше они жили во втором файле (doki-contract-form.js) и могли не сработать; держим здесь одну точку правды.
     */
    function yvoSyncDokiVisibleChromeForContractType() {
        var ct = currentContractType;
        var $stepLabels = $('.yvo-doki-steps .yvo-doki-step-label');
        if ($stepLabels.length >= 2) {
            if (ct === 'share_allocation') {
                $stepLabels.eq(0).text('участники (отчуждают)');
                $stepLabels.eq(1).text('участники (получают)');
            } else if (ct === 'gift') {
                $stepLabels.eq(0).text('даритель');
                $stepLabels.eq(1).text('одаряемый');
            } else {
                $stepLabels.eq(0).text('продавец');
                $stepLabels.eq(1).text('покупатель');
            }
            if ($stepLabels.length > 2) {
                $stepLabels.eq(2).text('объект');
            }
            if ($stepLabels.length > 3) {
                $stepLabels.eq(3).text('генерация');
            }
        }
        var $footer = $('#yvoDokiStepFooterLabel');
        if ($footer.length && $('.yvo-doki-steps').length) {
            var $ap = $('.yvo-doki-steps .step-pill.active').first();
            var idx = parseInt($ap.attr('data-yvo-step-index'), 10);
            if (isNaN(idx)) {
                idx = 0;
            }
            var words;
            if (ct === 'share_allocation') {
                words = ['участники (отчуждают)', 'участники (получают)', 'объект', 'генерация'];
            } else if (ct === 'gift') {
                words = ['даритель', 'одаряемый', 'объект', 'генерация'];
            } else {
                words = ['продавец', 'покупатель', 'объект', 'генерация'];
            }
            $footer.text('Шаг ' + (idx + 1) + ' из 4 · ' + (words[idx] !== undefined ? words[idx] : ''));
        }
        var $cardTitle = $('#yvoDokiParticipantCardTitle');
        var $cardHint = $('#yvoDokiParticipantCardHint');
        if ($cardTitle.length) {
            var $activeTab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab.active').first();
            if ($activeTab.length) {
                var tabId = $activeTab.attr('data-tab');
                if (tabId === 'property') {
                    $cardTitle.text('Объект');
                    if ($cardHint.length) {
                        $cardHint.attr('hidden', true).text('');
                    }
                } else {
                    var m = String(tabId || '').match(/^(seller|buyer)(\d*)$/);
                    if (m) {
                        var num = m[2] === '' ? 1 : (parseInt(m[2], 10) || 1);
                        if (ct === 'share_allocation') {
                            $cardTitle.text($.trim($activeTab.text()) || ('Участник ' + num));
                            if ($cardHint.length) {
                                $cardHint.removeAttr('hidden').text(m[1] === 'seller' ? 'отчуждает доли' : 'получает доли');
                            }
                        } else if (ct === 'gift') {
                            var gw = m[1] === 'seller' ? 'Даритель' : 'Одаряемый';
                            $cardTitle.text(num === 1 ? gw : gw + ' ' + num);
                            if ($cardHint.length) {
                                $cardHint.attr('hidden', true).text('');
                            }
                        } else {
                            var roleWord = m[1] === 'seller' ? 'Продавец' : 'Покупатель';
                            $cardTitle.text(roleWord + ' (участник ' + num + ')');
                            if ($cardHint.length) {
                                $cardHint.attr('hidden', true).text('');
                            }
                        }
                    } else {
                        $cardTitle.text(yvoTabLabelForContractType(tabId, ct) || $.trim($activeTab.text()));
                        if ($cardHint.length) {
                            $cardHint.attr('hidden', true).text('');
                        }
                    }
                }
            }
            if (typeof yvoFpUpdateApplySameGuardianButton === 'function') {
                yvoFpUpdateApplySameGuardianButton();
            }
        }
        var $optMinorSellerU14 = $('#yvo-fp-upload-for option[value="minor_seller|u14"]');
        var $optMinorSellerA18 = $('#yvo-fp-upload-for option[value="minor_seller|a14_18"]');
        var $optMinorBuyerU14 = $('#yvo-fp-upload-for option[value="minor_buyer|u14"]');
        var $optMinorBuyerA18 = $('#yvo-fp-upload-for option[value="minor_buyer|a14_18"]');
        if (ct === 'gift') {
            if ($optMinorSellerU14.length) $optMinorSellerU14.text('Даритель (до 14 лет)');
            if ($optMinorSellerA18.length) $optMinorSellerA18.text('Даритель (от 14 лет)');
            if ($optMinorBuyerU14.length) $optMinorBuyerU14.text('Одаряемый (до 14 лет)');
            if ($optMinorBuyerA18.length) $optMinorBuyerA18.text('Одаряемый (от 14 лет)');
        }
        var $optSeller = $('#yvo-fp-upload-for option[value="seller"]');
        var $optBuyer = $('#yvo-fp-upload-for option[value="buyer"]');
        if ($optSeller.length && $optBuyer.length) {
            if (ct === 'gift') {
                $optSeller.text('Даритель');
                $optBuyer.text('Одаряемый');
            } else if (ct === 'share_allocation') {
                $optSeller.text('Участник (отчуждает)');
                $optBuyer.text('Участник (получает)');
            } else {
                $optSeller.text('Продавец');
                $optBuyer.text('Покупатель');
            }
        }
        var labSeller = ct === 'gift' ? 'Даритель' : (ct === 'share_allocation' ? 'Участник (отчуждает)' : 'Продавец');
        var labBuyer = ct === 'gift' ? 'Одаряемый' : (ct === 'share_allocation' ? 'Участник (получает)' : 'Покупатель');
        $('#yvo-fp-fill-seller').text(labSeller);
        $('#yvo-fp-fill-buyer').text(labBuyer);
        var $hdr = $('.yvo-frontend-page.yvo-doki-form-skin .yvo-fp-desc').first();
        if ($hdr.length) {
            if (ct === 'gift') {
                $hdr.text('Заполните данные дарителя, одаряемого и объекта — затем сгенерируйте договор. Документы можно загрузить вверху страницы: сервис подставит поля автоматически.');
            } else if (ct === 'share_allocation') {
                $hdr.text('Заполните участников с обеих сторон, объект и доли — затем сгенерируйте документ. Документы можно загрузить вверху страницы: сервис подставит поля автоматически.');
            } else {
                $hdr.text('Заполните данные продавца, покупателя и объекта — затем сгенерируйте договор. Документы можно загрузить вверху страницы: сервис подставит поля автоматически.');
            }
        }
    }
    window.yvoSyncDokiVisibleChromeForContractType = yvoSyncDokiVisibleChromeForContractType;

    // Тип документа для парсера: добавленные вкладки используют ту же структуру, что и основные
    function getParseDocumentType(tabId) {
        if (tabId === 'property') return 'property';
        if (tabId && String(tabId).indexOf('contributor') === 0) return 'buyer';
        if (tabId && tabId.indexOf('buyer') === 0) return 'buyer';
        return 'seller'; // seller, seller2, seller_representative и т.д.
    }

    // ——— Автозаполнить: при открытии меню подставляем все текущие вкладки ———
    $('#yvo-fp-autofill-btn').on('click', function(e) {
        e.stopPropagation();
        var $menu = $('#yvo-fp-autofill-menu');
        $menu.empty();
        yvoFpParticipantTabButtons().each(function() {
            var tabId = $(this).attr('data-tab');
            var label = $(this).text().trim();
            $menu.append($('<button type="button" class="yvo-fp-dropdown-item" data-type="' + tabId + '" role="menuitem">' + label + '</button>'));
        });
        $('#yvo-fp-autofill-dropdown').toggleClass('open');
        $('#yvo-fp-add-participant-dropdown').removeClass('open');
    });
    function yvoFpCloseUploadExtraDropdown() {
        $('#yvo-fp-upload-target-extra-dropdown').removeClass('open');
        $('#yvo-fp-upload-target-extra-toggle').attr('aria-expanded', 'false');
    }

    $('#yvo-fp-upload-target-extra-toggle').on('click', function(e) {
        e.stopPropagation();
        var $dd = $('#yvo-fp-upload-target-extra-dropdown');
        var willOpen = !$dd.hasClass('open');
        $('.yvo-fp-dropdown').not($dd).removeClass('open');
        $dd.toggleClass('open', willOpen);
        $(this).attr('aria-expanded', willOpen ? 'true' : 'false');
    });

    $(document).on('click', '.yvo-doki-participant-icon-btn[data-yvo-doki-mirror]', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var sel = $(this).attr('data-yvo-doki-mirror');
        if (sel && $(sel).length) {
            $(sel).trigger('click');
        }
    });
    function runParseAndFill(type, label) {
        var text = $text.val().trim();
        if (!text) {
            showError('Сначала загрузите документ и получите текст');
            return;
        }
        type = yvoFpResolveParticipantPanelTab(type);
        $parseStatus.show().text('Извлечение данных...').removeClass('error ok');
        var docType = getParseDocumentType(type);
        var parseReq = {
            action: 'yvo_frontend_parse',
            nonce: yvo_frontend_ajax.nonce,
            text: text,
            document_type: docType
        };
        if (docType === 'property') {
            parseReq.object_type_hint = $('#property_object_type').val() || '';
        }
        $.post(yvo_frontend_ajax.ajax_url, parseReq, 'json').done(function(res) {
            if (res.success && res.data && res.data.parsed_data) {
                var parsed = yvoFpPrepareParsedForForm(res.data.parsed_data);
                if (!yvoFpParsedDataHasValues(parsed)) {
                    $parseStatus.text((res.data && res.data.message) ? res.data.message : ('Не удалось извлечь данные для «' + label + '». Проверьте OCR-текст или заполните вручную.')).addClass('error');
                    return;
                }
                fillForm(type, parsed);
                $parseStatus.text('Данные подставлены в форму «' + label + '».').addClass('ok');
                $resultSection.show();
                $('.yvo-fp-tab').removeClass('active');
                $('.yvo-fp-panel').removeClass('active');
                $('.yvo-fp-tab[data-tab="' + type + '"]').addClass('active');
                $('#yvo-fp-panel-' + type).addClass('active');
                if ($parseStatus[0]) $parseStatus[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                $parseStatus.text(res.data && res.data.message ? res.data.message : 'Не удалось извлечь данные').addClass('error');
            }
        }).fail(function() {
            $parseStatus.text('Ошибка запроса').addClass('error');
        });
    }

    $(document).on('click', '#yvo-fp-autofill-menu .yvo-fp-dropdown-item', function(e) {
        e.stopPropagation();
        var type = $(this).data('type');
        var label = $(this).text().trim();
        $('#yvo-fp-autofill-dropdown').removeClass('open');
        var $item = $(this);
        $item.prop('disabled', true);
        runParseAndFill(type, label);
        $item.prop('disabled', false);
    });

    // ——— Исправить кодировку выписки ЕГРН (DeepSeek) ———
    $('#yvo-fp-fix-egrn-btn').on('click', function() {
        yvoFpCloseUploadExtraDropdown();
        var text = $text.val().trim();
        if (!text || text.length < 100) {
            showError('Слишком короткий текст. Вставьте или загрузите выписку ЕГРН (от 100 символов).');
            return;
        }
        var $btn = $(this).prop('disabled', true).text('Исправление...');
        $parseStatus.show().text('Исправление кодировки (подождите до 5 мин)...').removeClass('error ok');
        $.ajax({
            url: yvo_frontend_ajax.ajax_url,
            type: 'POST',
            data: { action: 'yvo_fix_egrn_text', nonce: yvo_frontend_ajax.nonce, text: text },
            dataType: 'json',
            timeout: 300000
        }).done(function(res) {
            if (!res.success || !res.data) {
                $btn.prop('disabled', false).text('Исправить кодировку (ИИ)');
                $parseStatus.hide();
                showError((res.data && res.data.message) ? res.data.message : 'Не удалось исправить текст. Проверьте API ключ DeepSeek в настройках.');
                return;
            }
            if (res.data.text) {
                $text.val(res.data.text);
                $btn.prop('disabled', false).text('Исправить кодировку (ИИ)');
                $parseStatus.text('Кодировка исправлена.').addClass('ok').show();
                setTimeout(function() { $parseStatus.fadeOut(); }, 3000);
                return;
            }
            if (!res.data.key) {
                $btn.prop('disabled', false).text('Исправить кодировку (ИИ)');
                $parseStatus.hide();
                showError('Нет данных в ответе.');
                return;
            }
            $parseStatus.text('Загрузка полного текста...').removeClass('error ok');
            $.ajax({
                url: yvo_frontend_ajax.ajax_url,
                type: 'POST',
                data: { action: 'yvo_get_fix_egrn_result', nonce: yvo_frontend_ajax.nonce, key: res.data.key },
                dataType: 'text',
                timeout: 60000
            }).done(function(body) {
                $btn.prop('disabled', false).text('Исправить кодировку (ИИ)');
                $parseStatus.hide();
                if (body && typeof body === 'string' && body.charAt(0) !== '{') {
                    $text.val(body);
                    $parseStatus.text('Кодировка исправлена.').addClass('ok').show();
                    setTimeout(function() { $parseStatus.fadeOut(); }, 3000);
                } else {
                    showError('Ошибка загрузки результата.');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Исправить кодировку (ИИ)');
                $parseStatus.hide();
                showError('Таймаут загрузки. Попробуйте в админке: Настройки → Исправить кодировку ЕГРН.');
            });
        }).fail(function(xhr, status) {
            $btn.prop('disabled', false).text('Исправить кодировку (ИИ)');
            $parseStatus.hide();
            showError(status === 'timeout' ? 'Таймаут. Текст длинный — попробуйте в админке.' : 'Ошибка запроса. Проверьте ключ DeepSeek в настройках.');
        });
    });

    // ——— Извлечь данные выписки ЕГРН (объект + участники) ———
    $('#yvo-fp-extract-egrn-btn').on('click', function() {
        yvoFpCloseUploadExtraDropdown();
        var text = $text.val().trim();
        if (!text || text.length < 200) {
            showError('Слишком короткий текст. Загрузите выписку ЕГРН.');
            return;
        }
        var $btn = $(this).prop('disabled', true).text('Извлечение...');
        $parseStatus.show().text('Извлечение данных из выписки (до 1 мин)...').removeClass('error ok');
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_extract_egrn_data',
            nonce: yvo_frontend_ajax.nonce,
            text: text
        }, 'json').done(function(res) {
            $btn.prop('disabled', false).text('Извлечь данные выписки ЕГРН');
            $parseStatus.hide();
            if (res.success && res.data && res.data.extracted_data) {
                var ed = res.data.extracted_data;
                yvoFpRefreshExtractedSummary(text);
                if (yvoFpApplyEgrnExtracted(ed, text)) {
                    var okMsg = 'Данные объекта подставлены. Участники — в блоке «Извлечённые данные».';
                    if (ed.egrn_check && Object.keys(ed.egrn_check).length) okMsg += ' Справка ЕГРН — в блоке проверки.';
                    if (ed.ownership_history && ed.ownership_history.length) okMsg += ' История собственников — во вкладке.';
                    $parseStatus.text(okMsg).addClass('ok').show();
                    setTimeout(function() { $parseStatus.fadeOut(); }, 5000);
                } else {
                    showError('Не удалось извлечь данные из выписки');
                }
            } else {
                showError(res.data && res.data.message ? res.data.message : 'Не удалось извлечь данные');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Извлечь данные выписки ЕГРН');
            $parseStatus.hide();
            showError('Ошибка запроса или таймаут.');
        });
    });

    function yvoFpFillEgrnCheckBlock(check) {
        if (!check || typeof check !== 'object') return;
        var $block = $('.yvo-fp-egrn-check-block');
        if (!$block.length) return;
        $block.find('[data-egrn-check-key]').each(function() {
            var key = $(this).data('egrn-check-key');
            var val = check[key];
            if (val !== undefined && val !== null && String(val).trim() !== '') {
                $(this).val(String(val).trim());
            }
        });
        var hasAny = false;
        $block.find('[data-egrn-check-key]').each(function() {
            if (String($(this).val() || '').trim() !== '') hasAny = true;
        });
        if (hasAny) {
            $block.show();
            $block.closest('.yvo-fp-egrn-check-wrap').find('.yvo-fp-collapse-header').attr('aria-expanded', 'true');
            $block.closest('.yvo-fp-egrn-check-wrap').removeClass('collapsed');
        }
    }

    function yvoFpEscHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function yvoFpFillOwnershipHistory(history) {
        var $wrap = $('#yvo-fp-ownership-history-wrap');
        var $list = $('#yvo-fp-ownership-history-list');
        if (!$wrap.length || !$list.length) return;
        $list.empty();
        if (!history || !history.length) {
            $wrap.attr('hidden', true).hide();
            return;
        }
        history.forEach(function(rec) {
            if (!rec || typeof rec !== 'object') return;
            var isCurrent = !!rec.is_current;
            var holders = yvoFpEscHtml(rec.holder_label || (Array.isArray(rec.holder_names) ? rec.holder_names.join(', ') : '') || '—');
            var status = isCurrent ? 'Текущий' : 'История';
            var html = ''
                + '<article class="yvo-fp-own-card' + (isCurrent ? ' is-current' : '') + '">'
                +   '<header class="yvo-fp-own-card-head">'
                +     '<span class="yvo-fp-own-card-title">' + holders + '</span>'
                +     '<span class="yvo-fp-own-card-badge">' + yvoFpEscHtml(status) + '</span>'
                +   '</header>'
                +   '<div class="yvo-fp-own-card-grid">'
                +     '<div><span class="yvo-fp-own-k">Основание гос. регистрации</span><span class="yvo-fp-own-v">' + yvoFpEscHtml(rec.basis || '—') + '</span></div>'
                +     '<div><span class="yvo-fp-own-k">Номер регистрации</span><span class="yvo-fp-own-v">' + yvoFpEscHtml(rec.registration_number || '—') + '</span></div>'
                +     '<div><span class="yvo-fp-own-k">Вид зарегистрированного права</span><span class="yvo-fp-own-v">' + yvoFpEscHtml(rec.right_type || '—') + '</span></div>'
                +     '<div><span class="yvo-fp-own-k">Период владения</span><span class="yvo-fp-own-v">' + yvoFpEscHtml(rec.period_label || '—') + '</span></div>'
                +   '</div>'
                + '</article>';
            $list.append(html);
        });
        $wrap.removeAttr('hidden').show();
        var $body = $('#yvo-fp-ownership-history-body');
        if ($body.length && history.length > 1) {
            $body.show();
            $wrap.find('.yvo-fp-collapse-header').attr('aria-expanded', 'true');
            $wrap.removeClass('collapsed');
        }
    }

    window.yvoFpFillOwnershipHistoryFn = yvoFpFillOwnershipHistory;

    function yvoFpFillAddressFromFullInActiveBlock() {
        var type = $('#property_object_type').val() || 'apartment';
        var scope = (type === 'share') ? 'share' : ((type === 'room') ? 'room' : 'apartment');
        var $panel = $('#yvo-fp-panel-property');
        var $block = $panel.find('.yvo-fp-object-type-block.active');
        var addr = String($block.find('[data-key="address"]').val() || '').trim();
        if (!addr) return;
        var parsed = parseAddressSimple(addr);
        var $details = $panel.find('.yvo-fp-address-details[data-address-scope="' + scope + '"]');
        ['city', 'street', 'house', 'building', 'apartment'].forEach(function(k) {
            if (!parsed[k]) return;
            var $inp = $details.find('[data-key="' + k + '"]');
            if ($inp.length && !String($inp.val() || '').trim()) {
                $inp.val(parsed[k]);
            }
        });
        if (parsed.city || parsed.street) {
            setAddressDetailsVisible(scope, true);
        }
    }

    // ——— Определить участников: парсинг всех персон и выбор ролей в модальном окне ———
    var participantsModalPersons = [];
    $('#yvo-fp-detect-participants-btn').on('click', function() {
        yvoFpCloseUploadExtraDropdown();
        var text = $text.val().trim();
        if (!text) {
            showError('Сначала загрузите документ.');
            return;
        }
        var $btn = $(this).prop('disabled', true).text('Определение...');
        $parseStatus.show().text('Поиск данных участников...').removeClass('error ok');
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_frontend_parse_all_persons',
            nonce: yvo_frontend_ajax.nonce,
            text: text
        }, 'json').done(function(res) {
            $btn.prop('disabled', false).text('Определить участников');
            $parseStatus.hide();
            if (res.success && res.data && res.data.persons && res.data.persons.length > 0) {
                participantsModalPersons = res.data.persons;
                var roleOptions = [];
                yvoFpParticipantTabButtons().each(function() {
                    var id = $(this).attr('data-tab');
                    if (id && id !== 'property') roleOptions.push({ id: id, label: $(this).text().trim() });
                });
                ['seller', 'buyer', 'seller_representative', 'buyer_representative', 'contributor'].forEach(function(role) {
                    var next = getNextForRole(role);
                    if (next && roleOptions.filter(function(r) { return r.id === next.id; }).length === 0) {
                        roleOptions.push({ id: next.id, label: next.label });
                    }
                });
                ['minor_seller', 'minor_buyer'].forEach(function(role) {
                    var next = getNextForRole(role);
                    if (!next) return;
                    if (roleOptions.filter(function(r) { return r.id === next.id + '|u14'; }).length) return;
                    roleOptions.push({ id: next.id + '|u14', label: next.label + ' (до 14 лет)' });
                    roleOptions.push({ id: next.id + '|a14_18', label: next.label + ' (от 14 лет)' });
                });
                var $list = $('#yvo-fp-participants-list').empty();
                participantsModalPersons.forEach(function(p, i) {
                    var shortName = (p.full_name || '').trim() || 'Участник ' + (i + 1);
                    var parts = shortName.split(/\s+/);
                    if (parts.length >= 3) {
                        shortName = parts[0] + ' ' + (parts[1].charAt(0) || '') + '.' + (parts[2].charAt(0) || '') + '.';
                    } else if (parts.length === 2) {
                        shortName = parts[0] + ' ' + (parts[1].charAt(0) || '') + '.';
                    }
                    var $row = $('<div class="yvo-fp-participant-row"></div>');
                    $row.append('<span class="yvo-fp-participant-name">' + $('<div>').text(shortName).html() + '</span>');
                    var $sel = $('<select class="yvo-fp-participant-role"></select>');
                    $sel.append('<option value="">— не назначать —</option>');
                    roleOptions.forEach(function(r) {
                        $sel.append('<option value="' + r.id + '">' + r.label + '</option>');
                    });
                    $list.append($row.append($sel));
                });
                $('#yvo-fp-participants-modal').show();
            } else {
                $parseStatus.text(res.data && res.data.message ? res.data.message : 'Участники не найдены').addClass('error').show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Определить участников');
            $parseStatus.text('Ошибка запроса').addClass('error').show();
        });
    });
    $('#yvo-fp-participants-modal .yvo-fp-modal-close, #yvo-fp-participants-modal .yvo-fp-modal-backdrop, .yvo-fp-participants-cancel').on('click', function() {
        $('#yvo-fp-participants-modal').hide();
    });
    $('#yvo-fp-participants-apply').on('click', function() {
        var assigned = [];
        $('#yvo-fp-participants-list .yvo-fp-participant-row').each(function(i) {
            var role = $(this).find('select').val();
            if (role && participantsModalPersons[i]) {
                assigned.push({ role: role, data: participantsModalPersons[i] });
            }
        });
        if (assigned.length === 0) {
            showError('Назначьте хотя бы одну роль.');
            return;
        }
        assigned.forEach(function(item) {
            var parsed = yvoFpTabIdAndMinorOptsFromCompositeRole(item.role);
            ensureParticipantTab(parsed.tabId, parsed.minorOpts);
            fillForm(parsed.tabId, item.data);
        });
        $('#yvo-fp-participants-modal').hide();
        $parseStatus.text('Данные подставлены в форму по выбранным ролям.').addClass('ok').show();
        $resultSection.show();
        var firstRole = yvoFpTabIdAndMinorOptsFromCompositeRole(assigned[0].role).tabId;
        yvoFpParticipantTabButtons().removeClass('active');
        yvoFpAllParticipantPanels().removeClass('active');
        yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + firstRole + '"]').addClass('active');
        $('#yvo-fp-panel-' + firstRole).addClass('active');
        if (typeof updateDeleteButtonVisibility === 'function') updateDeleteButtonVisibility();
    });

    // Должно быть до ensureParticipantTab: при ошибке выше по коду инициализации иначе ADD_ROLES остаётся undefined.
    var MAX_PER_TYPE = 7;
    var ADD_ROLES = {
        seller:                { regex: /^seller(\d*)$/,                label1: 'Продавец',                   selector: '.yvo-fp-add-seller',        baseId: 'seller',                prefix: 'seller' },
        buyer:                 { regex: /^buyer(\d*)$/,                 label1: 'Покупатель',                 selector: '.yvo-fp-add-buyer',         baseId: 'buyer',                 prefix: 'buyer' },
        seller_representative: { regex: /^seller_representative(\d*)$/,  label1: 'Доверенное лицо продавца',    selector: '.yvo-fp-add-seller-rep',   baseId: 'seller_representative', prefix: 'seller_representative' },
        buyer_representative:  { regex: /^buyer_representative(\d*)$/,   label1: 'Доверенное лицо покупателя',   selector: '.yvo-fp-add-buyer-rep',    baseId: 'buyer_representative',  prefix: 'buyer_representative' },
        contributor:           { regex: /^contributor(\d*)$/,           label1: 'Вноситель задатка',            selector: '.yvo-fp-add-contributor',  baseId: 'contributor',           prefix: 'contributor' },
        minor_seller:          { regex: /^minor_seller(\d*)$/,         label1: 'Несовершеннолетний продавец',   selector: null,                        baseId: 'minor_seller',          prefix: 'minor_seller' },
        minor_buyer:           { regex: /^minor_buyer(\d*)$/,            label1: 'Несовершеннолетний покупатель', selector: null,                        baseId: 'minor_buyer',           prefix: 'minor_buyer' },
        guardian_seller:       { regex: /^guardian_seller(\d*)$/,        label1: 'Опекун (даритель)', selector: '.yvo-fp-add-guardian-seller', baseId: 'guardian_seller',       prefix: 'guardian_seller' },
        guardian_buyer:        { regex: /^guardian_buyer(\d*)$/,         label1: 'Опекун (одаряемый)', selector: '.yvo-fp-add-guardian-buyer', baseId: 'guardian_buyer',        prefix: 'guardian_buyer' }
    };

    function yvoFpIsDepositOrAdvance(ct) {
        ct = ct || currentContractType;
        return ct === 'deposit_agreement' || ct === 'advance_agreement';
    }

    /** Подпись вкладки опекуна/представителя несовершеннолетнего покупателя по типу договора. */
    function yvoFpGuardianBuyerLabelBase(ct) {
        ct = ct || currentContractType;
        if (ct === 'gift') {
            return 'Опекун (одаряемый)';
        }
        if (yvoFpIsDepositOrAdvance(ct)) {
            return 'Представитель несов. покупателя';
        }
        return 'Опекун (покупателя)';
    }

    /** Подпись вкладки опекуна/представителя несовершеннолетнего продавца по типу договора. */
    function yvoFpGuardianSellerLabelBase(ct) {
        ct = ct || currentContractType;
        if (ct === 'gift') {
            return 'Опекун (даритель)';
        }
        if (yvoFpIsDepositOrAdvance(ct)) {
            return 'Представитель несов. продавца';
        }
        return 'Опекун (продавца)';
    }

    /** Разбор значения селекта «несовершеннолетний …|u14» (модалка / загрузка). */
    function yvoFpTabIdAndMinorOptsFromCompositeRole(val) {
        var s = String(val || '');
        var m = s.match(/^(minor_seller\d*|minor_buyer\d*)\|(u14|a14_18)$/);
        if (m) {
            return { tabId: m[1], minorOpts: { minorAgeGroup: m[2] } };
        }
        return { tabId: s, minorOpts: undefined };
    }

    function yvoFpApplyMinorDocVisibility($panel) {
        if (!$panel || !$panel.length) return;
        var v = String($panel.find('[data-key="minor_age_group"]').first().val() || '').trim() || 'u14';
        var $birth = $panel.find('.yvo-fp-birthcert-fields');
        var $passportKeys = $panel.find('[data-key="passport_series"],[data-key="passport_number"],[data-key="passport_issued_by"],[data-key="department_code"],[data-key="passport_date"]');
        if (v === 'u14') {
            $birth.show();
            $passportKeys.closest('.yvo-fp-field').hide();
        } else {
            $birth.hide();
            $passportKeys.closest('.yvo-fp-field').show();
        }
    }

    /** Убрать радиокнопки возраста из карточки (выбор только при добавлении). */
    function yvoFpNormalizeMinorPanel($panel) {
        if (!$panel || !$panel.length) return;
        var mag = String($panel.find('input[type="radio"][data-key="minor_age_group"]:checked').val() || '').trim();
        if (!mag) {
            mag = String($panel.find('input[type="hidden"][data-key="minor_age_group"]').val() || '').trim();
        }
        if (!mag) mag = 'u14';
        $panel.find('input[type="radio"][data-key="minor_age_group"]').closest('label').remove();
        $panel.find('.yvo-fp-minor-extra .yvo-fp-field').filter(function() {
            return $(this).find('label').filter(function() {
                return /возраст\s+несовершеннолетн/i.test($(this).text());
            }).length > 0;
        }).remove();
        $panel.find('input[type="radio"][data-key="minor_age_group"]').remove();
        var $extra = $panel.find('.yvo-fp-minor-extra').first();
        if (!$extra.length) {
            $extra = $('<div class="yvo-fp-grid yvo-fp-minor-extra" style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;"></div>');
            $panel.find('.yvo-fp-grid').first().prepend($extra);
        }
        var $hid = $panel.find('input[type="hidden"][data-key="minor_age_group"]');
        if (!$hid.length) {
            $extra.prepend($('<input type="hidden" data-key="minor_age_group" />').val(mag));
        } else {
            $hid.val(mag);
        }
        if (!$panel.find('.yvo-fp-birthcert-fields').length) {
            var $bcBlock = $('<div class="yvo-fp-field yvo-fp-field-full yvo-fp-birthcert-fields"></div>');
            $bcBlock.append('<label>Свидетельство о рождении (серия, номер, дата, кем выдано)</label>');
            var $bcGrid = $('<div class="yvo-fp-grid" style="grid-template-columns:1fr 1fr;gap:10px;"></div>');
            $bcGrid.append('<input type="text" data-key="birth_cert_series" placeholder="Серия" />');
            $bcGrid.append('<input type="text" data-key="birth_cert_number" placeholder="Номер" />');
            $bcGrid.append('<input type="text" data-key="birth_cert_date" placeholder="Дата выдачи (дд.мм.гггг)" />');
            $bcGrid.append('<input type="text" data-key="birth_cert_issued_by" placeholder="Кем выдано" />');
            $bcBlock.append($bcGrid);
            $extra.append($bcBlock);
        }
        yvoFpApplyMinorDocVisibility($panel);
    }

    function yvoFpNormalizeAllMinorPanels() {
        yvoFpAllParticipantPanels().each(function() {
            var id = this.id || '';
            if (/^yvo-fp-panel-minor_(seller|buyer)\d*$/.test(id)) {
                yvoFpNormalizeMinorPanel($(this));
            }
        });
    }

    function yvoFpParseMinorTabId(minorTabId) {
        var m = String(minorTabId || '').match(/^(minor_seller|minor_buyer)(\d*)$/);
        if (!m) return null;
        var num = (m[2] === '') ? 1 : (parseInt(m[2], 10) || 1);
        return { role: m[1], side: m[1] === 'minor_seller' ? 'seller' : 'buyer', num: num };
    }

    function yvoFpGuardianTabIdForMinor(minorTabId) {
        var info = yvoFpParseMinorTabId(minorTabId);
        if (!info) return null;
        var gRole = info.role === 'minor_seller' ? 'guardian_seller' : 'guardian_buyer';
        var gCfg = ADD_ROLES[gRole];
        if (!gCfg) return null;
        return info.num === 1 ? gCfg.baseId : (gCfg.prefix + info.num);
    }

    function yvoFpMinorTabForGuardianTab(guardianTabId) {
        var m = String(guardianTabId || '').match(/^guardian_(seller|buyer)(\d*)$/);
        if (!m) return '';
        return 'minor_' + m[1] + (m[2] === '' ? '' : m[2]);
    }

    /** Кого представляет вкладка опекуна (для подписи «тот же · одаряемый 2»). */
    function yvoFpGuardianRepresentsMinorPhrase(guardianTabId) {
        var $panel = $('#yvo-fp-panel-' + guardianTabId);
        var minorTab = $panel.length ? String($panel.attr('data-yvo-guardian-for') || '') : '';
        if (!minorTab) minorTab = yvoFpMinorTabForGuardianTab(guardianTabId);
        var mm = minorTab.match(/^minor_(seller|buyer)(\d*)$/);
        if (!mm) return '';
        var mn = mm[2] === '' ? 1 : (parseInt(mm[2], 10) || 1);
        if (currentContractType === 'gift') {
            var w = mm[1] === 'minor_seller' ? 'дарителя' : 'одаряемого';
            return mn === 1 ? w : w + ' ' + mn;
        }
        if (currentContractType === 'share_allocation') {
            return mn === 1 ? 'участника' : 'участника ' + mn;
        }
        var w2 = mm[1] === 'minor_seller' ? 'продавца' : 'покупателя';
        return mn === 1 ? w2 : w2 + ' ' + mn;
    }

    function yvoFpGuardianTabLabelWithLinkState(targetId, baseLabel, num) {
        var $p = $('#yvo-fp-panel-' + targetId);
        var sameAs = $p.length ? String($p.find('[data-key="same_guardian_as"]').val() || '').trim() : '';
        if (sameAs) {
            var who = yvoFpGuardianRepresentsMinorPhrase(targetId);
            return baseLabel + ' — тот же' + (who ? ' · ' + who : '');
        }
        return num > 1 ? baseLabel + ' ' + num : baseLabel;
    }

    function yvoFpRefreshGuardianTabLabel(tabId) {
        var $tab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + tabId + '"]');
        if (!$tab.length) return;
        $tab.text(yvoFpBuildParticipantTabLabel(tabId, {}));
    }

    function yvoFpRefreshAllGuardianTabLabels() {
        $('[id^="yvo-fp-panel-guardian_"]').each(function() {
            yvoFpRefreshGuardianTabLabel((this.id || '').replace('yvo-fp-panel-', ''));
        });
        if (typeof window.yvoDokiUpdateParticipantCardTitle === 'function') {
            window.yvoDokiUpdateParticipantCardTitle();
        }
    }

    /** Номер принципала (1-based) для привязки опекуна к несовершеннолетнему в договоре. */
    function yvoFpPrincipalNumberForMinorTab(minorTabId) {
        var isSellerMinor = /^minor_seller/.test(String(minorTabId || ''));
        var n = 0;
        var found = 1;
        yvoFpParticipantTabButtons().each(function() {
            var tab = String($(this).attr('data-tab') || '');
            if (!tab || tab === 'property') return;
            if (isSellerMinor) {
                if (!yvoFpTabIsSellerSide(tab)) return;
                if (!/^(seller\d*|minor_seller\d*)$/.test(tab)) return;
            } else {
                if (!yvoFpTabIsBuyerSide(tab)) return;
                if (!/^(buyer\d*|minor_buyer\d*|contributor\d*)$/.test(tab)) return;
            }
            n++;
            if (tab === minorTabId) found = n;
        });
        return found;
    }

    function yvoFpPlaceGuardianTabAfterMinor(minorTabId, guardianTabId) {
        var $minorTab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + minorTabId + '"]');
        var $gTab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + guardianTabId + '"]');
        if ($minorTab.length && $gTab.length) {
            $gTab.insertAfter($minorTab);
        }
        var $minorPanel = $('#yvo-fp-panel-' + minorTabId);
        var $gPanel = $('#yvo-fp-panel-' + guardianTabId);
        if ($minorPanel.length && $gPanel.length) {
            $gPanel.insertAfter($minorPanel);
        }
    }

    function yvoFpOtherGuardianTabsBefore(currentTabId) {
        var list = [];
        var isSeller = /^guardian_seller/.test(String(currentTabId || ''));
        var re = isSeller ? /^guardian_seller/ : /^guardian_buyer/;
        var curKey = yvoFpDraftTabSortKey(currentTabId);
        yvoFpParticipantTabButtons().each(function() {
            var tab = String($(this).attr('data-tab') || '');
            if (!re.test(tab) || tab === currentTabId) return;
            if (yvoFpDraftTabSortKey(tab) >= curKey) return;
            list.push({ id: tab, label: $.trim($(this).text()) || tab });
        });
        return list;
    }

    function yvoFpRefreshSameGuardianSelect($panel, tabId) {
        if (!$panel || !$panel.length) return;
        var $field = $panel.find('.yvo-fp-same-guardian-field');
        if (!$field.length) return;
        var $sel = $field.find('.yvo-fp-same-guardian-select');
        var prev = $sel.val() || '';
        var others = yvoFpOtherGuardianTabsBefore(tabId);
        if (!others.length) {
            $field.hide();
            $sel.val('');
            yvoFpApplySameGuardianPanelState($panel);
            return;
        }
        $field.show();
        $sel.empty();
        $sel.append($('<option></option>').attr('value', '').text('Указать отдельно (свои паспортные данные)'));
        others.forEach(function(o) {
            $sel.append($('<option></option>').attr('value', o.id).text('Те же данные, что у «' + o.label + '»'));
        });
        if (prev && $sel.find('option[value="' + prev + '"]').length) {
            $sel.val(prev);
        }
        yvoFpApplySameGuardianPanelState($panel);
    }

    function yvoFpApplySameGuardianPanelState($panel) {
        if (!$panel || !$panel.length) return;
        var srcTab = ($panel.find('.yvo-fp-same-guardian-select').val() || '').trim();
        var $note = $panel.find('.yvo-fp-same-guardian-note');
        var $body = $panel.find('.yvo-fp-grid').not('.yvo-fp-guardian-extra').not('.yvo-fp-same-guardian-field');
        if (srcTab) {
            $panel.addClass('yvo-fp-guardian-linked');
            var srcLabel = $.trim($('.yvo-fp-tab[data-tab="' + srcTab + '"]').first().text()) || srcTab;
            $note.text('В договоре используются данные вкладки «' + srcLabel + '». Заполните паспорт там.').show();
            $body.find('input, textarea').not('[data-key="represents_party_number"]').prop('disabled', true).addClass('yvo-fp-field-disabled');
        } else {
            $panel.removeClass('yvo-fp-guardian-linked');
            $note.hide().text('');
            $body.find('input, textarea').prop('disabled', false).removeClass('yvo-fp-field-disabled');
        }
    }

    function yvoFpResolveGuardianLinksInRows(rows) {
        var byTab = {};
        (rows || []).forEach(function(r) {
            if (r && r.participant_tab) byTab[r.participant_tab] = r;
        });
        return (rows || []).map(function(r) {
            if (!r) return r;
            var same = String(r.same_guardian_as || '').trim();
            if (!same || !byTab[same]) return r;
            var src = byTab[same];
            var out = $.extend({}, src);
            out.participant_tab = r.participant_tab;
            out.same_guardian_as = same;
            if (r.represents_party_number !== undefined && r.represents_party_number !== '') {
                out.represents_party_number = r.represents_party_number;
            }
            return out;
        });
    }

    /** Нужна ли вкладка опекуна: только для детей до 14 лет (не для «от 14»). */
    function yvoFpMinorNeedsGuardianTab(minorTabId) {
        if (currentContractType === 'share_allocation') return false;
        var $mPanel = $('#yvo-fp-panel-' + minorTabId);
        if (!$mPanel.length) return true;
        var mag = String($mPanel.find('[data-key="minor_age_group"]').first().val() || '').trim();
        return mag !== 'a14_18';
    }

    /**
     * Автодобавление вкладки опекуна при добавлении несовершеннолетнего до 14 лет (кроме «выделения долей»).
     * @returns {{ guardianTabId: string, created: boolean }|null}
     */
    function yvoFpEnsureGuardianForMinor(minorTabId) {
        if (currentContractType === 'share_allocation') return null;
        if (!yvoFpMinorNeedsGuardianTab(minorTabId)) return null;
        var info = yvoFpParseMinorTabId(minorTabId);
        if (!info) return null;
        var gId = yvoFpGuardianTabIdForMinor(minorTabId);
        if (!gId) return null;
        var existed = $('#yvo-fp-panel-' + gId).length > 0;
        ensureParticipantTab(gId);
        var $gPanel = $('#yvo-fp-panel-' + gId);
        var $mPanel = $('#yvo-fp-panel-' + minorTabId);
        if ($gPanel.length) {
            $gPanel.find('[data-key="represents_party_number"]').val(yvoFpPrincipalNumberForMinorTab(minorTabId));
            $gPanel.attr('data-yvo-guardian-for', minorTabId);
        }
        if ($mPanel.length) {
            $mPanel.attr('data-yvo-minor-guardian-tab', gId);
        }
        yvoFpPlaceGuardianTabAfterMinor(minorTabId, gId);
        $(document).trigger('yvo-doki-participant-added');
        if (!$gPanel.length) {
            try {
                console.warn('[YVO] Не удалось создать панель опекуна:', gId, 'для', minorTabId);
            } catch (eWarn) {}
        }
        return { guardianTabId: gId, created: !existed, panelOk: $gPanel.length > 0 };
    }

    function yvoFpRemoveGuardianForMinor(minorTabId) {
        var gId = yvoFpGuardianTabIdForMinor(minorTabId);
        if (!gId) return;
        $('#yvo-fp-panel-' + gId).remove();
        yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + gId + '"]').remove();
    }

    /** При ручном добавлении опекуна — привязать к одноимённой вкладке ребёнка (minor_buyer ↔ guardian_buyer). */
    function yvoFpLinkGuardianToPairedMinor(guardianTabId) {
        var minorTab = yvoFpMinorTabForGuardianTab(guardianTabId);
        if (!minorTab) return;
        var $gPanel = $('#yvo-fp-panel-' + guardianTabId);
        var $mPanel = $('#yvo-fp-panel-' + minorTab);
        if (!$gPanel.length) return;
        if ($mPanel.length) {
            $gPanel.attr('data-yvo-guardian-for', minorTab);
            $mPanel.attr('data-yvo-minor-guardian-tab', guardianTabId);
            $gPanel.find('[data-key="represents_party_number"]').val(yvoFpPrincipalNumberForMinorTab(minorTab));
            yvoFpPlaceGuardianTabAfterMinor(minorTab, guardianTabId);
        }
    }

    function yvoFpEnsureGuardiansForAllMinors() {
        yvoFpParticipantTabButtons().each(function() {
            var tab = String($(this).attr('data-tab') || '');
            if (/^minor_(seller|buyer)\d*$/.test(tab) && yvoFpMinorNeedsGuardianTab(tab)) {
                yvoFpEnsureGuardianForMinor(tab);
            }
        });
        yvoFpUpgradeGuardianPanelsSameAs();
    }

    /** Добавить выбор «тот же опекун» на уже существующие вкладки опекунов. */
    function yvoFpUpgradeGuardianPanelsSameAs() {
        $('[id^="yvo-fp-panel-guardian_"]').each(function() {
            var tabId = (this.id || '').replace('yvo-fp-panel-', '');
            var $panel = $(this);
            var $extra = $panel.find('.yvo-fp-guardian-extra').first();
            if ($extra.length && !$extra.find('.yvo-fp-same-guardian-field').length) {
                var $sg = $(document.createElement('div'));
                $sg.addClass('yvo-fp-field yvo-fp-field-full yvo-fp-same-guardian-field');
                $sg.append('<label>Данные опекуна</label>');
                $sg.append('<select class="yvo-fp-same-guardian-select" data-key="same_guardian_as"><option value="">Указать отдельно (свои паспортные данные)</option></select>');
                $sg.append('<p class="yvo-fp-same-guardian-note" style="display:none;margin:8px 0 0;font-size:13px;color:#475569;"></p>');
                $extra.find('.yvo-fp-field').first().after($sg);
            }
            yvoFpRefreshSameGuardianSelect($panel, tabId);
        });
    }

    $(document).on('change', '.yvo-fp-same-guardian-select', function() {
        yvoFpApplySameGuardianPanelState($(this).closest('[id^="yvo-fp-panel-"]'));
        yvoFpRefreshAllGuardianTabLabels();
        yvoFpUpdateApplySameGuardianButton();
    });

    /** Кнопка в шапке карточки: «Применить опекуна 1» рядом с заголовком вкладки. */
    function yvoFpUpdateApplySameGuardianButton() {
        var $btn = $('#yvo-fp-apply-same-guardian-btn');
        if (!$btn.length) {
            return;
        }
        var tab = getActiveTab();
        var onGuardianTab = /^guardian_(seller|buyer)/.test(String(tab || ''));
        var hasGuardianPanels = $('[id^="yvo-fp-panel-guardian_"]').length > 0;
        if (!onGuardianTab || !hasGuardianPanels || tab === 'property' || tab === 'generate') {
            $btn.prop('hidden', true).attr('hidden', 'hidden');
            return;
        }
        var others = yvoFpOtherGuardianTabsBefore(tab);
        if (!others.length) {
            $btn.prop('hidden', true).attr('hidden', 'hidden');
            return;
        }
        var first = others[0];
        var $panel = $('#yvo-fp-panel-' + tab);
        var linked = ($panel.find('[data-key="same_guardian_as"]').val() || '').trim() === first.id;
        $btn.prop('hidden', false).removeAttr('hidden');
        $btn.data('source-guardian-tab', first.id);
        if (linked) {
            $btn.text('Свои данные опекуна').addClass('yvo-fp-apply-same-guardian-btn--active');
        } else {
            $btn.text('Применить параметры опекуна').removeClass('yvo-fp-apply-same-guardian-btn--active');
        }
    }

    function yvoFpApplySameGuardianFromHeaderButton() {
        var tab = getActiveTab();
        var $btn = $('#yvo-fp-apply-same-guardian-btn');
        var src = String($btn.data('source-guardian-tab') || '').trim();
        if (!tab || !src) return;
        var $panel = $('#yvo-fp-panel-' + tab);
        if (!$panel.length) return;
        if (!$panel.find('.yvo-fp-same-guardian-select').length) {
            yvoFpUpgradeGuardianPanelsSameAs();
        }
        yvoFpRefreshSameGuardianSelect($panel, tab);
        var $sel = $panel.find('.yvo-fp-same-guardian-select');
        if (!$sel.length) return;
        var current = ($sel.val() || '').trim();
        if (current === src) {
            $sel.val('').trigger('change');
        } else {
            var $srcPanel = $('#yvo-fp-panel-' + src);
            var srcName = ($srcPanel.find('[data-key="full_name"]').val() || '').trim();
            if (!srcName) {
                showError('Сначала заполните ФИО и паспорт на вкладке «' + ($.trim($('.yvo-fp-tab[data-tab="' + src + '"]').first().text()) || src) + '».');
                yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + src + '"]').first().trigger('click');
                return;
            }
            $sel.val(src).trigger('change');
        }
        yvoFpRefreshAllGuardianTabLabels();
        yvoFpUpdateApplySameGuardianButton();
    }

    $(document).on('click', '#yvo-fp-apply-same-guardian-btn', function(e) {
        e.preventDefault();
        yvoFpApplySameGuardianFromHeaderButton();
    });
    $(document).on('yvo-doki-participant-added', function() {
        yvoFpUpgradeGuardianPanelsSameAs();
        yvoFpUpdateApplySameGuardianButton();
    });
    window.yvoFpUpdateApplySameGuardianButton = yvoFpUpdateApplySameGuardianButton;

    /**
     * Старое меню: одна кнопка «Несовершеннолетний …» → две (до 14 / от 14).
     * Работает, если на сервере ещё старый frontend-page.php.
     */
    function yvoFpUpgradeMinorAddMenu() {
        var giftCt = (typeof currentContractType !== 'undefined' && currentContractType === 'gift');
        var specs = [
            { role: 'minor_seller', side: 'seller', base: giftCt ? 'Даритель' : 'Несовершеннолетний продавец' },
            { role: 'minor_buyer', side: 'buyer', base: giftCt ? 'Одаряемый' : 'Несовершеннолетний покупатель' }
        ];
        var ageRows = [
            { suffix: 'u14', age: 'u14', lab: 'до 14 лет' },
            { suffix: 'a18', age: 'a14_18', lab: 'от 14 лет' }
        ];
        var menuRoots = ['#yvo-fp-add-participant-menu', '#yvoDokiAddParticipantPanel'];
        specs.forEach(function(spec) {
            menuRoots.forEach(function(rootSel) {
                var $root = $(rootSel);
                if (!$root.length) return;
                if ($root.find('.yvo-fp-add-minor-' + spec.side + '-u14').length) return;
                var $legacy = $root.find('[data-add="' + spec.role + '"]').filter(function() {
                    var cls = this.className || '';
                    return cls.indexOf('yvo-fp-add-minor-' + spec.side + '-u14') < 0 && cls.indexOf('yvo-fp-add-minor-' + spec.side + '-a18') < 0;
                });
                if (!$legacy.length) return;
                var $ref = $legacy.first();
                var $insertBefore = $ref;
                ageRows.forEach(function(row) {
                    var $btn = $ref.clone(false);
                    $btn.removeClass('yvo-fp-add-minor-seller yvo-fp-add-minor-buyer');
                    $btn.addClass('yvo-fp-dropdown-item yvo-fp-add-minor-' + spec.side + '-' + row.suffix);
                    $btn.attr({ 'data-add': spec.role, 'data-minor-age': row.age });
                    $btn.removeAttr('data-yvo-add-bound');
                    $btn.text(spec.base + ' (' + row.lab + ')');
                    $insertBefore.before($btn);
                });
                $legacy.remove();
            });
        });
        var $sel = $('#yvo-fp-upload-for');
        if ($sel.length && !$sel.find('option[value="minor_seller|u14"]').length) {
            var $os = $sel.find('option[value="minor_seller"]');
            if ($os.length) {
                $os.before('<option value="minor_seller|u14">Несовершеннолетний продавец (до 14 лет)</option>');
                $os.before('<option value="minor_seller|a14_18">Несовершеннолетний продавец (от 14 лет)</option>');
                $os.remove();
            }
            var $ob = $sel.find('option[value="minor_buyer"]');
            if ($ob.length) {
                $ob.before('<option value="minor_buyer|u14">Несовершеннолетний покупатель (до 14 лет)</option>');
                $ob.before('<option value="minor_buyer|a14_18">Несовершеннолетний покупатель (от 14 лет)</option>');
                $ob.remove();
            }
        }
        yvoBindNativeAddParticipantButtons();
    }

    /** Сохранить копию базовой панели перед удалением вкладки (шаблон для новых участников). */
    function yvoFpStashParticipantTemplateIfNeeded(panelDomId) {
        panelDomId = String(panelDomId || '');
        if (panelDomId !== 'yvo-fp-panel-buyer' && panelDomId !== 'yvo-fp-panel-seller') return;
        var stashId = panelDomId + '-stash';
        if (document.getElementById(stashId)) return;
        var $src = $('#' + panelDomId).first();
        if (!$src.length) return;
        var $stash = $src.clone();
        $stash.attr('id', stashId).removeClass('active').hide();
        yvoFpTabPanelsEl().append($stash);
    }

    /** Шаблон панели участника: buyer/seller, stash или любая существующая панель стороны. */
    function yvoFpFindParticipantPanelTemplate(useBuyerPanel) {
        var primaryId = useBuyerPanel ? 'yvo-fp-panel-buyer' : 'yvo-fp-panel-seller';
        var stashId = primaryId + '-stash';
        var $t = $('#' + primaryId).first();
        if ($t.length) return $t;
        $t = $('#' + stashId).first();
        if ($t.length) return $t;
        var prefixes = useBuyerPanel
            ? ['buyer', 'minor_buyer', 'buyer_representative', 'contributor', 'guardian_buyer']
            : ['seller', 'minor_seller', 'seller_representative', 'guardian_seller'];
        var p, n, id, $panel;
        for (p = 0; p < prefixes.length; p++) {
            for (n = 1; n <= MAX_PER_TYPE; n++) {
                id = n === 1 ? prefixes[p] : prefixes[p] + n;
                $panel = $('#yvo-fp-panel-' + id).first();
                if ($panel.length) return $panel;
            }
        }
        return $();
    }

    function ensureParticipantTab(targetId, opts) {
        opts = opts || {};
        if (targetId === 'seller' || targetId === 'buyer' || targetId === 'property') return;
        var $existingPanel = $('#yvo-fp-panel-' + targetId);
        var $existingTab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + targetId + '"]');
        if ($existingPanel.length && $existingTab.length) return;
        if ($existingPanel.length && !$existingTab.length) {
            yvoFpAppendParticipantTabButton(targetId, yvoFpBuildParticipantTabLabel(targetId, opts));
            return;
        }
        var role = null;
        for (var r in ADD_ROLES) {
            if (ADD_ROLES.hasOwnProperty(r) && ADD_ROLES[r].regex.test(targetId)) { role = r; break; }
        }
        if (!role) return;
        var label = yvoFpBuildParticipantTabLabel(targetId, opts);
        if (currentContractType === 'share_allocation') {
            label = 'Участник ' + (yvoCountShareParticipantTabs() + 1);
        }
        var useBuyerPanel = (role === 'buyer' || role === 'buyer_representative' || role === 'contributor' || role === 'minor_buyer' || role === 'guardian_buyer');
        var $template = yvoFpFindParticipantPanelTemplate(useBuyerPanel);
        if (!$template.length) return;
        var $newPanel = $template.clone();
        $newPanel.attr('id', 'yvo-fp-panel-' + targetId).removeClass('active').show();
        $newPanel.find('.yvo-fp-guardian-extra, .yvo-fp-minor-extra, .yvo-fp-rep-extra').remove();
        if (role === 'seller_representative' || role === 'buyer_representative') {
            if (!$newPanel.find('.yvo-fp-rep-extra').length) {
                var sideLabel = 'покупателя';
                if (role === 'seller_representative') {
                    sideLabel = currentContractType === 'gift' ? 'дарителя' : 'продавца';
                } else if (currentContractType === 'gift') {
                    sideLabel = 'одаряемого';
                }
                var $extra = $('<div class="yvo-fp-grid yvo-fp-rep-extra" style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;"></div>');
                $extra.append($('<div class="yvo-fp-field"></div>')
                    .append('<label>№ ' + sideLabel + ' (кого представляете)</label>')
                    .append('<input type="number" min="1" max="7" step="1" value="1" data-key="represents_party_number" />'));
                $extra.append($('<div class="yvo-fp-field yvo-fp-field-full"></div>')
                    .append('<label>Доверенность (№, дата выдачи, кем выдана)</label>')
                    .append('<textarea rows="2" data-key="power_of_attorney_details" placeholder="Например: доверенности № … от …, выданной …"></textarea>'));
                $newPanel.find('.yvo-fp-grid').first().prepend($extra);
            }
        }
        if (role === 'minor_seller' || role === 'minor_buyer') {
            if (!$newPanel.find('.yvo-fp-minor-extra').length) {
                var $extraM = $('<div class="yvo-fp-grid yvo-fp-minor-extra" style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;"></div>');
                $extraM.append($('<input type="hidden" data-key="minor_age_group" value="u14" />'));
                $extraM.append($('<div class="yvo-fp-field yvo-fp-field-full yvo-fp-birthcert-fields"></div>')
                    .append('<label>Свидетельство о рождении (серия, номер, дата, кем выдано)</label>')
                    .append('<div class="yvo-fp-grid" style="grid-template-columns:1fr 1fr;gap:10px;">'
                        + '<input type="text" data-key="birth_cert_series" placeholder="Серия" />'
                        + '<input type="text" data-key="birth_cert_number" placeholder="Номер" />'
                        + '<input type="text" data-key="birth_cert_date" placeholder="Дата выдачи (дд.мм.гггг)" />'
                        + '<input type="text" data-key="birth_cert_issued_by" placeholder="Кем выдано" />'
                        + '</div>')
                );
                $newPanel.find('.yvo-fp-grid').first().prepend($extraM);
            }
        }
        if (role === 'guardian_seller' || role === 'guardian_buyer') {
            if (!$newPanel.find('.yvo-fp-guardian-extra').length) {
                var sideLabel2 = 'покупателя';
                if (role === 'guardian_seller') {
                    sideLabel2 = currentContractType === 'gift' ? 'дарителя' : 'продавца';
                } else if (currentContractType === 'gift') {
                    sideLabel2 = 'одаряемого';
                }
                var $extraG = $('<div class="yvo-fp-grid yvo-fp-guardian-extra" style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;"></div>');
                $extraG.append($('<div class="yvo-fp-field"></div>')
                    .append('<label>№ ' + sideLabel2 + ' (кого представляете)</label>')
                    .append('<input type="number" min="1" max="7" step="1" value="1" data-key="represents_party_number" />'));
                $extraG.append($('<div class="yvo-fp-field yvo-fp-field-full yvo-fp-same-guardian-field"></div>')
                    .append('<label>Данные опекуна</label>')
                    .append('<select class="yvo-fp-same-guardian-select" data-key="same_guardian_as"><option value="">Указать отдельно (свои паспортные данные)</option></select>')
                    .append('<p class="yvo-fp-same-guardian-note" style="display:none;margin:8px 0 0;font-size:13px;color:#475569;"></p>'));
                $extraG.append($('<div class="yvo-fp-field yvo-fp-field-full"></div>')
                    .append('<label>Основание (опека/попечительство, реквизиты)</label>')
                    .append('<textarea rows="2" data-key="guardian_basis" placeholder="Например: свидетельство о рождении, решение органа опеки № … от …"></textarea>'));
                $newPanel.find('.yvo-fp-grid').first().prepend($extraG);
                yvoFpRefreshSameGuardianSelect($newPanel, targetId);
            }
        }
        $newPanel.find('[data-key]').each(function() {
            var key = $(this).data('key');
            $(this).attr('name', targetId + '_' + key);
        });
        $newPanel.find('input, textarea').val('');
        if (role === 'minor_seller' || role === 'minor_buyer') {
            var mag = (opts.minorAgeGroup === 'a14_18') ? 'a14_18' : 'u14';
            $newPanel.find('[data-key="minor_age_group"]').val(mag);
            yvoFpApplyMinorDocVisibility($newPanel);
        }
        var $tabPanels = yvoFpTabPanelsEl();
        if (!$tabPanels.length) return;
        $tabPanels.append($newPanel);
        var $newTab = $('<button type="button" class="yvo-fp-tab" data-tab="' + targetId + '">' + label + '</button>');
        yvoFpParticipantTabStripEl().append($newTab);
        $(document).trigger('yvo-doki-participant-added');

        if (role === 'minor_seller' || role === 'minor_buyer') {
            yvoFpEnsureGuardianForMinor(targetId);
        }
        if (role === 'guardian_seller' || role === 'guardian_buyer') {
            yvoFpRefreshSameGuardianSelect($newPanel, targetId);
        }
    }

    function ensureParticipantTabForRole(role, minorAgeOpt) {
        var next = getNextForRole(role);
        if (!next) return null;
        var opts;
        if (role === 'minor_seller' || role === 'minor_buyer') {
            var mag = String(minorAgeOpt || '').trim();
            opts = { minorAgeGroup: (mag === 'a14_18') ? 'a14_18' : 'u14' };
        }
        ensureParticipantTab(next.id, opts);
        return next.id;
    }

    // Сразу после загрузки: выбор «Объект / Продавец / Покупатель» и авто-заполнение формы
    $(document).on('click', '.yvo-fp-upload-target-btn[data-fill-type]', function() {
        var $btn = $(this);
        var type = $btn.data('fill-type');
        var label = yvoParticipantRoleShortLabels()[type] || type;
        $btn.prop('disabled', true).text('Загрузка...');
        $parseStatus.show().text('Извлечение данных...').removeClass('error ok');
        var docType = getParseDocumentType(type);
        var parseReq2 = {
            action: 'yvo_frontend_parse',
            nonce: yvo_frontend_ajax.nonce,
            text: $text.val().trim(),
            document_type: docType
        };
        if (docType === 'property') {
            parseReq2.object_type_hint = $('#property_object_type').val() || '';
        }
        $.post(yvo_frontend_ajax.ajax_url, parseReq2, 'json').done(function(res) {
            if (res.success && res.data && res.data.parsed_data) {
                fillForm(type, res.data.parsed_data);
                $parseStatus.text('Данные подставлены в форму «' + label + '».').addClass('ok');
                $resultSection.show();
                $('.yvo-fp-tab').removeClass('active');
                $('.yvo-fp-panel').removeClass('active');
                $('.yvo-fp-tab[data-tab="' + type + '"]').addClass('active');
                $('#yvo-fp-panel-' + type).addClass('active');
                if ($parseStatus[0]) $parseStatus[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                $parseStatus.text(res.data && res.data.message ? res.data.message : 'Не удалось извлечь данные').addClass('error');
            }
        }).fail(function() {
            $parseStatus.text('Ошибка запроса').addClass('error');
        }).always(function() {
            $btn.prop('disabled', false).text(label);
            yvoFpCloseUploadExtraDropdown();
        });
    });

    // ——— Добавить участника: до 7 каждого типа, меню показывает следующий номер ———
    /** Если полоска вкладок пуста/ломается темой, считаем по id панелей в #yvo-fp-tab-panels (иначе next=1 → id seller → дубликат). */
    function yvoFpMaxNumFromPanelsForCfg(cfg) {
        var maxNum = 0;
        var $root = yvoFpTabPanelsEl();
        if (!$root.length) return 0;
        $root.find('.yvo-fp-panel').each(function() {
            var fullId = this.id || '';
            var pre = 'yvo-fp-panel-';
            if (fullId.indexOf(pre) !== 0) return;
            var pid = fullId.slice(pre.length);
            var m = pid.match(cfg.regex);
            if (m) {
                var n = (m[1] === '') ? 1 : (parseInt(m[1], 10) || 0);
                if (n > maxNum) maxNum = n;
            }
        });
        return maxNum;
    }

    /** Первый свободный номер вкладки роли (учитывает панели без кнопки вкладки). */
    function yvoFpFirstFreeParticipantSlot(role) {
        var cfg = ADD_ROLES[role];
        if (!cfg) return null;
        var n;
        for (n = 1; n <= MAX_PER_TYPE; n++) {
            var id = n === 1 ? cfg.baseId : cfg.prefix + n;
            var hasPanel = !!document.getElementById('yvo-fp-panel-' + id);
            var hasTab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + id + '"]').length > 0;
            if (!hasPanel && !hasTab) {
                return { num: n, id: id, recoverTabOnly: false };
            }
            if (hasPanel && !hasTab) {
                return { num: n, id: id, recoverTabOnly: true };
            }
        }
        return null;
    }

    function yvoFpBuildParticipantTabLabel(targetId, opts) {
        opts = opts || {};
        var role = null;
        var r;
        for (r in ADD_ROLES) {
            if (ADD_ROLES.hasOwnProperty(r) && ADD_ROLES[r].regex.test(targetId)) {
                role = r;
                break;
            }
        }
        if (!role) return String(targetId);
        var cfg = ADD_ROLES[role];
        var m = targetId.match(cfg.regex);
        var num = m && m[1] !== '' ? (parseInt(m[1], 10) || 1) : 1;
        var label = num === 1 ? cfg.label1 : cfg.label1 + ' ' + num;
        if (currentContractType === 'share_allocation') {
            label = 'Участник ' + num;
        } else if (currentContractType === 'gift') {
            if (role === 'seller') {
                label = num === 1 ? 'Даритель' : 'Даритель ' + num;
            } else if (role === 'buyer') {
                label = num === 1 ? 'Одаряемый' : 'Одаряемый ' + num;
            } else if (role === 'minor_seller') {
                label = num === 1 ? 'Даритель' : 'Даритель ' + num;
            } else if (role === 'minor_buyer') {
                label = num === 1 ? 'Одаряемый' : 'Одаряемый ' + num;
            } else if (role === 'guardian_seller') {
                label = yvoFpGuardianTabLabelWithLinkState(targetId, yvoFpGuardianSellerLabelBase(), num);
            } else if (role === 'guardian_buyer') {
                label = yvoFpGuardianTabLabelWithLinkState(targetId, yvoFpGuardianBuyerLabelBase(), num);
            } else if (role === 'seller_representative') {
                label = num === 1 ? 'Представитель дарителя' : 'Представитель дарителя ' + num;
            } else if (role === 'buyer_representative') {
                label = num === 1 ? 'Представитель одаряемого' : 'Представитель одаряемого ' + num;
            }
        } else if (yvoFpIsDepositOrAdvance() && role === 'guardian_seller') {
            label = yvoFpGuardianTabLabelWithLinkState(targetId, yvoFpGuardianSellerLabelBase(), num);
        } else if (yvoFpIsDepositOrAdvance() && role === 'guardian_buyer') {
            label = yvoFpGuardianTabLabelWithLinkState(targetId, yvoFpGuardianBuyerLabelBase(), num);
        }
        if ((role === 'minor_seller' || role === 'minor_buyer') && currentContractType !== 'share_allocation') {
            var magL = (opts.minorAgeGroup === 'a14_18') ? 'a14_18' : 'u14';
            label += (magL === 'u14') ? ' · до 14' : ' · от 14';
        }
        return label;
    }

    function yvoFpAppendParticipantTabButton(targetId, label) {
        if (yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + targetId + '"]').length) {
            return;
        }
        var $newTab = $('<button type="button" class="yvo-fp-tab" data-tab="' + targetId + '">' + label + '</button>');
        yvoFpParticipantTabStripEl().append($newTab);
        if (document.getElementById('yvo-fp-panel-' + targetId)) {
            $(document).trigger('yvo-doki-participant-added');
        }
    }

    /** После добавления участника — шаг ДОКИ и активная вкладка (иначе вкладка скрыта фильтром шага). */
    function yvoFpDokiFocusParticipant(tabId) {
        if (!$('.yvo-doki-form-skin').length || !tabId) {
            return;
        }
        var step = typeof window.yvoDokiTabToStepIndex === 'function'
            ? window.yvoDokiTabToStepIndex(tabId)
            : (/^(buyer|minor_buyer|guardian_buyer|contributor|buyer_representative)/.test(tabId) ? 1
                : (/^(seller|minor_seller|guardian_seller|seller_representative)/.test(tabId) ? 0
                    : (tabId === 'property' ? 2 : -1)));
        if (step >= 0 && step <= 2 && typeof window.yvoDokiFilterParticipantTabsForStep === 'function') {
            window.yvoDokiFilterParticipantTabsForStep(step);
        }
        if (typeof window.yvoDokiSyncStepChrome === 'function' && step >= 0 && step <= 2) {
            window.yvoDokiSyncStepChrome(step, tabId);
        }
        yvoFpActivateParticipantTab(tabId, { skipDokiStepSync: true });
    }

    function getNextForRole(role) {
        var cfg = ADD_ROLES[role];
        if (!cfg) return null;
        var slot = yvoFpFirstFreeParticipantSlot(role);
        if (!slot) return null;
        var next = slot.num;
        var id = slot.id;
        var label;
        if (currentContractType === 'share_allocation') {
            if (role === 'seller') {
                var ns = 0;
                yvoFpParticipantTabButtons().each(function() {
                    var x = $(this).attr('data-tab');
                    if (x && /^seller\d*$/.test(x)) {
                        ns++;
                    }
                });
                label = 'Участник ' + (ns + 1) + ' (отчуждает)';
            } else if (role === 'buyer') {
                var nb = 0;
                yvoFpParticipantTabButtons().each(function() {
                    var x = $(this).attr('data-tab');
                    if (x && /^buyer\d*$/.test(x)) {
                        nb++;
                    }
                });
                label = 'Участник ' + (nb + 1) + ' (получает)';
            } else {
                label = 'Участник ' + (yvoCountShareParticipantTabs() + 1);
            }
        } else if (currentContractType === 'gift') {
            if (role === 'seller') {
                label = next === 1 ? 'Даритель' : 'Даритель ' + next;
            } else if (role === 'buyer') {
                label = next === 1 ? 'Одаряемый' : 'Одаряемый ' + next;
            } else if (role === 'minor_seller') {
                label = next === 1 ? 'Даритель' : 'Даритель ' + next;
            } else if (role === 'minor_buyer') {
                label = next === 1 ? 'Одаряемый' : 'Одаряемый ' + next;
            } else if (role === 'guardian_seller') {
                label = next === 1 ? 'Опекун (даритель)' : 'Опекун (даритель) ' + next;
            } else if (role === 'guardian_buyer') {
                label = next === 1 ? 'Опекун (одаряемый)' : 'Опекун (одаряемый) ' + next;
            } else if (role === 'seller_representative') {
                label = next === 1 ? 'Представитель дарителя' : 'Представитель дарителя ' + next;
            } else if (role === 'buyer_representative') {
                label = next === 1 ? 'Представитель одаряемого' : 'Представитель одаряемого ' + next;
            }
        } else {
            label = yvoFpBuildParticipantTabLabel(id, {});
        }
        return { id: id, label: label, num: next, recoverTabOnly: !!slot.recoverTabOnly };
    }

    function defaultTabLabelForDataTab(tab) {
        if (!tab) return '';
        if (tab === 'property') return 'Объект';
        var role;
        for (role in ADD_ROLES) {
            if (!ADD_ROLES.hasOwnProperty(role)) continue;
            var cfg = ADD_ROLES[role];
            var m = String(tab).match(cfg.regex);
            if (m) {
                var n = (m[1] === '') ? 1 : (parseInt(m[1], 10) || 1);
                return n === 1 ? cfg.label1 : cfg.label1 + ' ' + n;
            }
        }
        return String(tab);
    }

    /** Вкладки seller/buyer в полоске (без property), по порядку в DOM. */
    function yvoCountShareParticipantTabs() {
        var n = 0;
        yvoFpParticipantTabButtons().each(function() {
            var id = $(this).attr('data-tab');
            if (id && id !== 'property' && /^(seller|buyer|minor_seller|minor_buyer|contributor)\d*$/.test(id)) {
                n++;
            }
        });
        return n;
    }

    /** Подпись вкладки участника с учётом типа договора (в т.ч. несовершеннолетние и опекуны при дарении). */
    function yvoTabLabelForContractType(tab, ct) {
        ct = ct || currentContractType;
        if (!tab) return '';
        if (tab === 'property') return 'Объект';
        var s = String(tab);
        var mm = s.match(/^(minor_seller|minor_buyer)(\d*)$/);
        if (mm) {
            var mn = mm[2] === '' ? 1 : (parseInt(mm[2], 10) || 1);
            var mag = '';
            var $p = $('#yvo-fp-panel-' + s);
            if ($p.length) {
                mag = String($p.find('[data-key="minor_age_group"]').first().val() || '').trim();
                if (mag === 'a14_18') mag = ' · от 14';
                else if (mag === 'u14') mag = ' · до 14';
            }
            if (ct === 'gift') {
                return (mm[1] === 'minor_seller' ? 'Несовершеннолетний даритель' : 'Несовершеннолетний одаряемый') + (mn > 1 ? ' ' + mn : '') + mag;
            }
            return defaultTabLabelForDataTab(tab) + mag;
        }
        var gm = s.match(/^(guardian_seller|guardian_buyer)(\d*)$/);
        if (gm) {
            var gn = gm[2] === '' ? 1 : (parseInt(gm[2], 10) || 1);
            if (ct === 'gift') {
                var gbase = gm[1] === 'guardian_seller' ? 'Опекун (даритель)' : 'Опекун (одаряемый)';
                return yvoFpGuardianTabLabelWithLinkState(s, gbase, gn);
            }
            if (yvoFpIsDepositOrAdvance(ct) && gm[1] === 'guardian_buyer') {
                return yvoFpGuardianTabLabelWithLinkState(s, yvoFpGuardianBuyerLabelBase(ct), gn);
            }
            if (yvoFpIsDepositOrAdvance(ct) && gm[1] === 'guardian_seller') {
                return yvoFpGuardianTabLabelWithLinkState(s, yvoFpGuardianSellerLabelBase(ct), gn);
            }
            return defaultTabLabelForDataTab(tab);
        }
        var rm = s.match(/^(seller_representative|buyer_representative)(\d*)$/);
        if (rm && ct === 'gift') {
            var rn = rm[2] === '' ? 1 : (parseInt(rm[2], 10) || 1);
            var rbase = rm[1] === 'seller_representative' ? 'Представитель дарителя' : 'Представитель одаряемого';
            return rn === 1 ? rbase : rbase + ' ' + rn;
        }
        if (ct === 'gift') {
            return giftTabLabelForDataTab(tab);
        }
        return defaultTabLabelForDataTab(tab);
    }

    function giftTabLabelForDataTab(tab) {
        if (!tab) return '';
        if (tab === 'property') return 'Объект';
        var role;
        for (role in ADD_ROLES) {
            if (!ADD_ROLES.hasOwnProperty(role)) continue;
            if (role !== 'seller' && role !== 'buyer') continue;
            var cfg = ADD_ROLES[role];
            var m = String(tab).match(cfg.regex);
            if (m) {
                var n = (m[1] === '') ? 1 : (parseInt(m[1], 10) || 1);
                if (role === 'seller') {
                    return n === 1 ? 'Даритель' : 'Даритель ' + n;
                }
                return n === 1 ? 'Одаряемый' : 'Одаряемый ' + n;
            }
        }
        return defaultTabLabelForDataTab(tab);
    }

    function yvoFpGiftParticipantName(tid) {
        var name = ($('#yvo-fp-panel-' + tid).find('[data-key="full_name"]').val() || '').trim();
        if (!name) {
            name = ($('.yvo-fp-tab[data-tab="' + tid + '"]').first().text() || '').trim() || tid;
        }
        return name;
    }

    function yvoFpGiftDonorTabIds() {
        return yvoParticipantTabIdsForShareMatrix().filter(function(tid) {
            return /^seller/.test(tid) || /^minor_seller/.test(tid);
        });
    }

    function yvoFpGiftDoneeTabIds() {
        return yvoParticipantTabIdsForShareMatrix().filter(function(tid) {
            return /^buyer/.test(tid) || /^minor_buyer/.test(tid) || /^contributor/.test(tid);
        });
    }

    function yvoFpGiftDistUid() {
        return 'g' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
    }

    function yvoFpGiftDistLoad() {
        var raw = ($('#property_gift_distributions').val() || '').trim();
        if (!raw) {
            return [];
        }
        try {
            var rows = JSON.parse(raw);
            return Array.isArray(rows) ? rows : [];
        } catch (eLoad) {
            return [];
        }
    }

    function yvoFpGiftDistSave(rows) {
        $('#property_gift_distributions').val(JSON.stringify(rows || []));
    }

    function yvoFpGiftDistMigrateLegacy(rows) {
        if (rows && rows.length) {
            return rows;
        }
        var donorIds = yvoFpGiftDonorTabIds();
        var doneeIds = yvoFpGiftDoneeTabIds();
        if (!donorIds.length || !doneeIds.length) {
            return [];
        }
        var legacy = [];
        var primaryDonor = donorIds[0];
        doneeIds.forEach(function(donee) {
            var share = ($('#yvo-fp-panel-' + donee).find('[data-key="share_fraction"]').val() || '').trim();
            if (share) {
                legacy.push({
                    id: yvoFpGiftDistUid(),
                    donor_tab: primaryDonor,
                    donee_tab: donee,
                    share_fraction: share
                });
            }
        });
        return legacy;
    }

    /** Одна строка таблицы на каждого одаряемого; доли и даритель (если их несколько) сохраняются. */
    function yvoFpGiftDistSyncToParticipants(existingRows, donorIds, doneeIds) {
        var primaryDonor = donorIds[0] || '';
        var byDonee = {};
        (existingRows || []).forEach(function(r) {
            if (!r || !r.donee_tab || doneeIds.indexOf(r.donee_tab) < 0) {
                return;
            }
            if (!byDonee[r.donee_tab]) {
                byDonee[r.donee_tab] = r;
            }
        });
        var rows = [];
        doneeIds.forEach(function(donee) {
            var prev = byDonee[donee];
            if (prev) {
                rows.push({
                    id: prev.id || yvoFpGiftDistUid(),
                    donor_tab: donorIds.indexOf(prev.donor_tab) >= 0 ? prev.donor_tab : primaryDonor,
                    donee_tab: donee,
                    share_fraction: prev.share_fraction || ''
                });
            } else {
                rows.push({
                    id: yvoFpGiftDistUid(),
                    donor_tab: primaryDonor,
                    donee_tab: donee,
                    share_fraction: ''
                });
            }
        });
        return rows;
    }

    function yvoFpGiftDistCollectFromDom() {
        var rows = [];
        var donorIds = yvoFpGiftDonorTabIds();
        var singleDonor = donorIds.length === 1;
        $('#yvo-fp-share-participants-summary tr.yvo-fp-gift-dist-tr').each(function() {
            var $row = $(this);
            var doneeTab = ($row.attr('data-donee-tab') || '').trim();
            var donorTab = singleDonor
                ? donorIds[0]
                : ($row.find('.yvo-fp-gift-donor-select').val() || '').trim();
            var share = yvoFpGiftDistReadRowShare($row);
            rows.push({
                id: $row.attr('data-row-id') || yvoFpGiftDistUid(),
                donor_tab: donorTab,
                donee_tab: doneeTab,
                share_fraction: share
            });
        });
        return rows;
    }

    /** Прочитать долю из пары полей (числитель/знаменатель), в т.ч. если в числителе введено «1/2». */
    function yvoFpGiftDistReadRowShare($row) {
        var numRaw = ($row.find('.yvo-fp-gift-frac-num').val() || '').trim();
        var denRaw = ($row.find('.yvo-fp-gift-frac-den').val() || '').trim();
        if (numRaw.indexOf('/') >= 0) {
            var slashParts = yvoFpParseShareFracParts(numRaw);
            if (slashParts.num) {
                numRaw = slashParts.num;
            }
            if (slashParts.den && !denRaw) {
                denRaw = slashParts.den;
            }
        }
        return yvoFpFormatShareFracParts(numRaw, denRaw);
    }

    function yvoFpGiftDistApplySlashToRow($row) {
        var $num = $row.find('.yvo-fp-gift-frac-num');
        var raw = ($num.val() || '').trim();
        if (raw.indexOf('/') < 0) {
            return false;
        }
        var parts = yvoFpParseShareFracParts(raw);
        if (!parts.num) {
            return false;
        }
        $num.val(parts.num);
        if (parts.den) {
            $row.find('.yvo-fp-gift-frac-den').val(parts.den);
        }
        return true;
    }

    function yvoFpGiftDistEnsureTable() {
        if ($('#yvo-fp-share-participants-summary tr.yvo-fp-gift-dist-tr').length) {
            return;
        }
        yvoFpRenderGiftShareUi(false);
    }

    /** Если доля указана в карточке одаряемого — подставить в таблицу на шаге «Объект». */
    function yvoFpGiftDistSyncFromParticipantShares() {
        if (yvoFpGiftShareSyncLock || currentContractType !== 'gift' || !yvoFpGiftNeedsShareDistribution()) {
            return;
        }
        yvoFpGiftDistEnsureTable();
        yvoFpGiftShareSyncLock++;
        try {
            yvoFpGiftDoneeTabIds().forEach(function(donee) {
                var $tr = $('#yvo-fp-share-participants-summary tr.yvo-fp-gift-dist-tr[data-donee-tab="' + donee + '"]');
                if (!$tr.length) {
                    return;
                }
                if (yvoFpGiftDistReadRowShare($tr)) {
                    return;
                }
                var fromPanel = ($('#yvo-fp-panel-' + donee).find('[data-key="share_fraction"]').val() || '').trim();
                if (!fromPanel) {
                    return;
                }
                var parts = yvoFpParseShareFracParts(fromPanel);
                if (parts.num) {
                    $tr.find('.yvo-fp-gift-frac-num').val(parts.num);
                }
                if (parts.den) {
                    $tr.find('.yvo-fp-gift-frac-den').val(parts.den);
                }
            });
        } finally {
            yvoFpGiftShareSyncLock--;
        }
    }

    function yvoFpGiftDonorInitialShareFloat(donorTab) {
        var donorIds = yvoFpGiftDonorTabIds();
        var n = donorIds.length;
        if (!n) {
            return 0;
        }
        if (n === 1) {
            return 1;
        }
        return 1 / n;
    }

    function yvoFpSyncPanelsFromGiftDistributions(rows) {
        yvoFpGiftShareSyncLock++;
        try {
            var donorIds = yvoFpGiftDonorTabIds();
            var doneeIds = yvoFpGiftDoneeTabIds();
            var received = {};
            var gifted = {};
            doneeIds.forEach(function(t) { received[t] = 0; });
            donorIds.forEach(function(t) { gifted[t] = 0; });
            (rows || []).forEach(function(r) {
                if (!r || !r.share_fraction) {
                    return;
                }
                var f = yvoParseShareFractionToFloat(r.share_fraction);
                if (f === null || f === undefined) {
                    return;
                }
                if (received[r.donee_tab] !== undefined) {
                    received[r.donee_tab] += f;
                }
                if (gifted[r.donor_tab] !== undefined) {
                    gifted[r.donor_tab] += f;
                }
            });
            if (yvoFpGiftWholePropertyShareSum()) {
                donorIds.forEach(function(donor) {
                    var initial = yvoFpGiftDonorInitialShareFloat(donor);
                    var rem = initial - (gifted[donor] || 0);
                    var frac = rem > 1e-6 ? yvoFpFloatToShareFraction(rem) : '';
                    $('#yvo-fp-panel-' + donor).find('[data-key="share_fraction"]').val(frac);
                });
            }
            doneeIds.forEach(function(donee) {
                var total = received[donee] || 0;
                var frac = total > 1e-6 ? yvoFpFloatToShareFraction(total) : '';
                $('#yvo-fp-panel-' + donee).find('[data-key="share_fraction"]').val(frac);
            });
        } finally {
            yvoFpGiftShareSyncLock--;
        }
    }

    function yvoFpBuildGiftPartySelect(cssClass, label, ids, selected) {
        var $sel = $('<select class="yvo-fp-input" />').addClass(cssClass).attr('aria-label', label);
        ids.forEach(function(tid) {
            var $opt = $('<option/>').val(tid).text(yvoFpGiftParticipantName(tid));
            if (tid === selected) {
                $opt.prop('selected', true);
            }
            $sel.append($opt);
        });
        return $sel;
    }

    function yvoFpBuildGiftFracPairForRow(row, label) {
        var parts = yvoFpParseShareFracParts(row.share_fraction || '');
        var $pair = $('<div class="yvo-fp-share-frac-pair" />');
        $pair.append(
            $('<input type="text" inputmode="numeric" pattern="[0-9]*" class="yvo-fp-gift-frac-num yvo-fp-input" />')
                .attr('aria-label', (label || 'Доля') + ' — числитель')
                .attr('placeholder', '1')
                .attr('maxlength', '8')
                .val(parts.num)
        );
        $pair.append($('<span class="yvo-fp-share-frac-slash" aria-hidden="true">/</span>'));
        $pair.append(
            $('<input type="text" inputmode="numeric" pattern="[0-9]*" class="yvo-fp-gift-frac-den yvo-fp-input" />')
                .attr('aria-label', (label || 'Доля') + ' — знаменатель')
                .attr('placeholder', '4')
                .attr('maxlength', '8')
                .val(parts.den)
        );
        return $pair;
    }

    function yvoFpBuildGiftDistTableRow(row, donorIds, singleDonor) {
        var $tr = $('<tr class="yvo-fp-gift-dist-tr" />')
            .attr('data-row-id', row.id)
            .attr('data-donee-tab', row.donee_tab);
        if (!singleDonor) {
            $tr.append($('<td/>').append(yvoFpBuildGiftPartySelect('yvo-fp-gift-donor-select', 'Даритель', donorIds, row.donor_tab)));
        }
        $tr.append($('<td class="yvo-fp-gift-dist-donee-name" />').text(yvoFpGiftParticipantName(row.donee_tab)));
        $tr.append($('<td/>').append(yvoFpBuildGiftFracPairForRow(row, 'Доля в дар')));
        return $tr;
    }

    function yvoFpGiftDistRefreshSelectOptions(donorIds, singleDonor) {
        if (singleDonor) {
            return;
        }
        $('#yvo-fp-share-participants-summary tr.yvo-fp-gift-dist-tr').each(function() {
            var $tr = $(this);
            var $donor = $tr.find('.yvo-fp-gift-donor-select');
            var curD = $donor.val();
            $donor.empty();
            donorIds.forEach(function(tid) {
                var $opt = $('<option/>').val(tid).text(yvoFpGiftParticipantName(tid));
                if (tid === curD) {
                    $opt.prop('selected', true);
                }
                $donor.append($opt);
            });
            $tr.find('.yvo-fp-gift-dist-donee-name').text(yvoFpGiftParticipantName($tr.attr('data-donee-tab')));
        });
    }

    function yvoFpParseShareFracParts(str) {
        var nd = yvoParseShareFractionNd(str);
        if (nd) {
            return { num: String(nd.n), den: String(nd.d) };
        }
        var t = String(str || '').trim().replace(/\s/g, '');
        var m = t.match(/^(\d+)\/(\d*)$/);
        if (m) {
            return { num: m[1], den: m[2] || '' };
        }
        m = t.match(/^(\d+)$/);
        if (m) {
            return { num: m[1], den: '' };
        }
        return { num: '', den: '' };
    }

    function yvoFpFormatShareFracParts(num, den) {
        num = String(num || '').replace(/\D/g, '');
        den = String(den || '').replace(/\D/g, '');
        if (!num || !den) {
            return '';
        }
        var n = parseInt(num, 10);
        var d = parseInt(den, 10);
        if (!n || !d) {
            return '';
        }
        var g = yvoGcd(n, d);
        return (n / g) + '/' + (d / g);
    }

    function yvoFpFloatToShareFraction(val) {
        if (val === null || val === undefined || isNaN(val) || val <= 0) {
            return '';
        }
        var d;
        for (d = 2; d <= 10000; d++) {
            var n = Math.round(val * d);
            if (n > 0 && Math.abs(n / d - val) < 1e-6) {
                var g = yvoGcd(n, d);
                return (n / g) + '/' + (d / g);
            }
        }
        return val.toFixed(4);
    }

    function yvoFpValidateGiftDistributions(rows) {
        var donorIds = yvoFpGiftDonorTabIds();
        var doneeIds = yvoFpGiftDoneeTabIds();
        var gifted = {};
        var total = 0;
        var missing = 0;
        var filled = 0;
        donorIds.forEach(function(t) { gifted[t] = 0; });

        var rowByDonee = {};
        (rows || []).forEach(function(r) {
            if (r && r.donee_tab) {
                rowByDonee[r.donee_tab] = r;
            }
        });

        var incompleteMsg = '';
        for (var di = 0; di < doneeIds.length; di++) {
            var donee = doneeIds[di];
            var r = rowByDonee[donee];
            var share = r ? r.share_fraction : '';
            if (share) {
                continue;
            }
            var $tr = $('#yvo-fp-share-participants-summary tr.yvo-fp-gift-dist-tr[data-donee-tab="' + donee + '"]');
            var num = ($tr.find('.yvo-fp-gift-frac-num').val() || '').trim();
            var den = ($tr.find('.yvo-fp-gift-frac-den').val() || '').trim();
            var name = yvoFpGiftParticipantName(donee);
            if (num.indexOf('/') >= 0 && !den) {
                incompleteMsg = 'У одаряемого «' + name + '» в поле числителя указано «' + num + '». Введите 1 и 2 в отдельных полях или вставьте «1/2» — знаменатель заполнится автоматически.';
            } else if (num && !den) {
                incompleteMsg = 'У одаряемого «' + name + '» указан числитель (' + num + '), но не указан знаменатель (например, 2 для доли 1/2).';
            } else if (!num && den) {
                incompleteMsg = 'У одаряемого «' + name + '» указан знаменатель (' + den + '), но не указан числитель.';
            } else {
                incompleteMsg = 'У одаряемого «' + name + '» укажите долю в дар: числитель и знаменатель (например, 1 и 2).';
            }
            break;
        }
        if (incompleteMsg) {
            return { ok: false, missing: true, msg: incompleteMsg, focusTab: 'property' };
        }

        (rows || []).forEach(function(r) {
            if (!r.donor_tab || !r.donee_tab) {
                missing++;
                return;
            }
            if (!r.share_fraction) {
                return;
            }
            filled++;
            var f = yvoParseShareFractionToFloat(r.share_fraction);
            if (f === null || f === undefined) {
                missing++;
                return;
            }
            if (gifted[r.donor_tab] !== undefined) {
                gifted[r.donor_tab] += f;
            }
            total += f;
        });
        if (!filled) {
            return {
                ok: false,
                missing: true,
                msg: 'Укажите долю в дар для каждого одаряемого (раздел «Объект» → таблица распределения долей).',
                focusTab: 'property'
            };
        }
        if (missing > 0) {
            return {
                ok: false,
                missing: true,
                msg: 'Проверьте доли: выберите одаряемого и укажите корректную дробь (числитель / знаменатель).',
                focusTab: 'property'
            };
        }
        if (yvoFpGiftWholePropertyShareSum()) {
            var badDonor = donorIds.find(function(donor) {
                var initial = yvoFpGiftDonorInitialShareFloat(donor);
                return (gifted[donor] || 0) > initial + 1e-4;
            });
            if (badDonor) {
                return {
                    ok: false,
                    msg: 'Даритель «' + yvoFpGiftParticipantName(badDonor) + '» не может подарить больше своей доли (' + yvoFpFloatToShareFraction(yvoFpGiftDonorInitialShareFloat(badDonor)) + ').',
                    focusTab: 'property'
                };
            }
            if (total > 1 + 1e-4) {
                return {
                    ok: false,
                    msg: 'Сумма всех даримых долей: ' + (total * 100).toFixed(2) + '% — больше 100%.',
                    focusTab: 'property'
                };
            }
            if (yvoFpGiftDoneeTabCount() >= 2 && Math.abs(total - 1) > 1e-4) {
                return {
                    ok: false,
                    msg: 'Сумма долей одаряемых должна быть равна 1 (100%). Сейчас: ' + (total * 100).toFixed(2) + '%.',
                    focusTab: 'property'
                };
            }
            return { ok: true, total: total, msg: 'Всего в дар: ' + (total * 100).toFixed(2) + '%.' };
        }
        return { ok: true, total: total, msg: '' };
    }

    function yvoFpGiftDistRowsForSave(rows) {
        return (rows || []).filter(function(r) {
            return r && r.share_fraction && r.donee_tab && r.donor_tab;
        }).map(function(r) {
            return {
                donor_tab: r.donor_tab,
                donee_tab: r.donee_tab,
                share_fraction: r.share_fraction,
                donor_name: yvoFpGiftParticipantName(r.donor_tab),
                donee_name: yvoFpGiftParticipantName(r.donee_tab)
            };
        });
    }

    function yvoFpRefreshGiftSharePreview() {
        if (yvoFpGiftShareSyncLock) {
            return;
        }
        var $host = $('#yvo-fp-gift-preview-host');
        if (!$host.length) {
            return;
        }
        var rows = yvoFpGiftDistCollectFromDom();
        yvoFpGiftDistSave(rows);
        yvoFpSyncPanelsFromGiftDistributions(rows);
        yvoSyncSellersSharesHidden();
        $host.empty();

        var $dist = $('<div class="yvo-fp-gift-shares-preview yvo-fp-gift-shares-distribution" />');
        $dist.append($('<h4 class="yvo-fp-gift-shares-preview__title" />').text('Как будет в договоре'));
        var $distList = $('<ul class="yvo-fp-gift-shares-preview__list" />');
        var saved = yvoFpGiftDistRowsForSave(rows);
        if (!saved.length) {
            $distList.append($('<li class="yvo-fp-gift-shares-preview__empty"/>').text('Укажите долю в дар в таблице выше'));
        } else {
            saved.forEach(function(r) {
                $distList.append($('<li/>').text(
                    'Даритель ' + r.donor_name + ' дарит долю ' + r.share_fraction + ' одаряемому ' + r.donee_name
                ));
            });
        }
        $dist.append($distList);
        $host.append($dist);

        if (yvoFpGiftWholePropertyShareSum()) {
            var $preview = $('<div class="yvo-fp-gift-shares-preview yvo-fp-gift-shares-after" />');
            $preview.append($('<h4 class="yvo-fp-gift-shares-preview__title" />').text('Доли после регистрации'));
            $preview.append($('<p class="yvo-fp-gift-shares-preview__lead" />').text('Объект будет принадлежать в следующих долях:'));
            var $list = $('<ul class="yvo-fp-gift-shares-preview__list" />');
            var hasAny = false;
            yvoFpGiftDonorTabIds().forEach(function(tid) {
                var share = ($('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val() || '').trim();
                if (!share) {
                    return;
                }
                hasAny = true;
                $list.append($('<li/>').text(yvoFpGiftParticipantName(tid) + ' – ' + share + ' доли в праве общей долевой собственности'));
            });
            yvoFpGiftDoneeTabIds().forEach(function(tid) {
                var share = ($('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val() || '').trim();
                if (!share) {
                    return;
                }
                hasAny = true;
                $list.append($('<li/>').text(yvoFpGiftParticipantName(tid) + ' – ' + share + ' доли в праве общей долевой собственности'));
            });
            if (!hasAny) {
                $list.append($('<li class="yvo-fp-gift-shares-preview__empty"/>').text('Укажите доли в дар'));
            }
            $preview.append($list);
            $host.append($preview);
        }

        var val = yvoFpValidateGiftDistributions(rows);
        if (val.msg) {
            yvoSetShareValidation(val.msg, !!val.ok);
        } else {
            yvoSetShareValidation('', false);
        }
    }

    function yvoFpRenderGiftShareUi(forceRebuild) {
        var $wrap = $('#yvo-fp-share-participants-summary');
        if (!$wrap.length) {
            return;
        }
        var donorIds = yvoFpGiftDonorTabIds();
        var doneeIds = yvoFpGiftDoneeTabIds();
        if (!donorIds.length || !doneeIds.length) {
            $wrap.empty().removeAttr('data-gift-participants');
            $wrap.append($('<p class="yvo-fp-section-hint" />').text('Добавьте дарителя и одаряемого (шаги 1–2), затем укажите, кому сколько долей в дар.'));
            return;
        }

        var singleDonor = donorIds.length === 1;
        var participantKey = donorIds.join(',') + '|' + doneeIds.join(',');
        var existingRows;
        if (forceRebuild) {
            existingRows = yvoFpGiftDistLoad();
            if (!existingRows.length) {
                existingRows = yvoFpGiftDistMigrateLegacy([]);
            }
        } else if ($wrap.find('tr.yvo-fp-gift-dist-tr').length) {
            existingRows = yvoFpGiftDistCollectFromDom();
        } else {
            existingRows = yvoFpGiftDistMigrateLegacy(yvoFpGiftDistLoad());
        }
        var rows = yvoFpGiftDistSyncToParticipants(existingRows, donorIds, doneeIds);
        yvoFpGiftDistSave(rows);

        var domDoneeKey = $wrap.find('tr.yvo-fp-gift-dist-tr').map(function() {
            return $(this).attr('data-donee-tab');
        }).get().join(',');
        var needRebuild = !!forceRebuild
            || !$wrap.find('.yvo-fp-gift-dist-table').length
            || $wrap.attr('data-gift-participants') !== participantKey
            || $wrap.attr('data-gift-single-donor') !== (singleDonor ? '1' : '0')
            || domDoneeKey !== doneeIds.join(',');

        if (needRebuild) {
            $wrap.empty().attr('data-gift-participants', participantKey).attr('data-gift-single-donor', singleDonor ? '1' : '0');
            var $block = $('<div class="yvo-fp-gift-dist-block" />');
            var hintText;
            if (singleDonor) {
                hintText = 'Даритель: ' + yvoFpGiftParticipantName(donorIds[0])
                    + '. Строки по одаряемым добавляются автоматически — укажите долю в двух полях (например, 1 и 2) или вставьте «1/2».';
            } else if (yvoFpObjectTypeIsShare()) {
                hintText = 'Строки по одаряемым формируются автоматически. Выберите дарителя и укажите долю в дар.';
            } else {
                hintText = 'Строки по одаряемым формируются автоматически. При нескольких дарителях выберите дарителя в строке. Остаток доли после регистрации рассчитается автоматически.';
            }
            $block.append($('<p class="yvo-fp-section-hint yvo-fp-gift-dist-hint" />').text(hintText));

            var $table = $('<table class="yvo-fp-gift-dist-table" />');
            var $head = $('<tr/>');
            if (!singleDonor) {
                $head.append($('<th/>').text('Даритель'));
            }
            $head.append($('<th/>').text('Одаряемый')).append($('<th/>').text('Доля в дар'));
            $table.append($('<thead/>').append($head));
            var $tbody = $('<tbody id="yvo-fp-gift-dist-tbody" />');
            rows.forEach(function(row) {
                $tbody.append(yvoFpBuildGiftDistTableRow(row, donorIds, singleDonor));
            });
            $table.append($tbody);
            $block.append($table);
            $block.append($('<div class="yvo-fp-gift-preview-host" id="yvo-fp-gift-preview-host" />'));
            $wrap.append($block);
        } else {
            yvoFpGiftDistRefreshSelectOptions(donorIds, singleDonor);
            $wrap.find('tr.yvo-fp-gift-dist-tr').each(function() {
                var tid = $(this).attr('data-donee-tab');
                if (tid) {
                    $(this).find('.yvo-fp-gift-dist-donee-name').text(yvoFpGiftParticipantName(tid));
                }
            });
            var hintText2 = singleDonor
                ? ('Даритель: ' + yvoFpGiftParticipantName(donorIds[0]) + '. Строки по одаряемым добавляются автоматически — укажите долю в дар.')
                : $wrap.find('.yvo-fp-gift-dist-hint').text();
            $wrap.find('.yvo-fp-gift-dist-hint').text(hintText2);
        }

        yvoFpRefreshGiftSharePreview();
    }

    function yvoFpGiftDistOnChange() {
        if (yvoFpGiftShareSyncLock || currentContractType !== 'gift') {
            return;
        }
        yvoFpRefreshGiftSharePreview();
    }

    function yvoFpAllocParentSellerIds() {
        return yvoShareMatrixParticipantIds().filter(function(id) {
            return /^seller\d*$/.test(id);
        });
    }

    function yvoFpAllocIsJointOwnership() {
        if (currentContractType !== 'share_allocation') {
            return false;
        }
        var $cb = $('#property_share_joint_ownership');
        if (!$cb.length || !$cb.prop('checked')) {
            return false;
        }
        return yvoFpAllocParentSellerIds().length >= 2;
    }

    function yvoFpAllocJointParentLabel() {
        var names = yvoFpAllocParentSellerIds().map(function(tid) {
            return yvoFpGiftParticipantName(tid);
        }).filter(function(n) { return !!n; });
        if (names.length >= 2) {
            return names.slice(0, -1).join(', ') + ' и ' + names[names.length - 1];
        }
        return names[0] || 'Родители';
    }

    function yvoFpAllocUpdateJointOwnershipUi() {
        if (currentContractType !== 'share_allocation') {
            return;
        }
        var ids = yvoFpAllocParentSellerIds();
        var $row = $('#yvo-fp-share-joint-ownership-row');
        var $cb = $('#property_share_joint_ownership');
        if (!$row.length || !$cb.length) {
            return;
        }
        if (ids.length >= 2) {
            $row.removeAttr('hidden');
            if (!$cb.attr('data-user-set')) {
                $cb.prop('checked', true);
            }
        } else {
            $row.attr('hidden', 'hidden');
            $cb.prop('checked', false).removeAttr('data-user-set');
        }
    }

    function yvoFpAllocJointParentCombinedFloat() {
        if (!yvoFpAllocIsJointOwnership()) {
            return null;
        }
        var ids = yvoFpAllocParentSellerIds();
        if (ids.length < 2) {
            return null;
        }
        var primaryF = yvoParseShareFractionToFloat(yvoFpAllocGetShareForTab(ids[0]));
        if (primaryF !== null && primaryF > 0) {
            return primaryF;
        }
        var parts = [];
        ids.forEach(function(tid) {
            var f = yvoParseShareFractionToFloat(yvoFpAllocGetShareForTab(tid));
            if (f !== null && f > 0) {
                parts.push(f);
            }
        });
        if (!parts.length) {
            return null;
        }
        if (parts.length === 1) {
            return parts[0];
        }
        var sum = 0;
        parts.forEach(function(v) { sum += v; });
        return sum;
    }

    /** Доля 1/2 — типичное устаревшее значение по умолчанию при клонировании вкладки. */
    function yvoFpAllocIsStaleDefaultShare(str) {
        var f = yvoParseShareFractionToFloat(str);
        if (f === null) {
            return false;
        }
        return Math.abs(f - 0.5) < 0.001;
    }

    /** Разделить целые сотые доли между N участниками (остаток — последнему). */
    function yvoFpAllocSplitUnitsAmong(totalUnits, partIndex, partsCount) {
        totalUnits = Math.max(0, Math.round(totalUnits));
        if (partsCount <= 1) {
            return totalUnits;
        }
        if (partIndex < 0 || partIndex >= partsCount) {
            return 0;
        }
        var base = Math.floor(totalUnits / partsCount);
        if (partIndex === partsCount - 1) {
            return totalUnits - base * (partsCount - 1);
        }
        return base;
    }

    /**
     * Снята «совместная собственность»: объединённая доля родителей делится поровну (92/100 → 46/100 + 46/100).
     */
    function yvoFpAllocExpandSeparateParentShares(skipActive) {
        if (yvoFpAllocIsJointOwnership()) {
            return;
        }
        var parentIds = yvoFpAllocParentSellerIds();
        if (parentIds.length < 2) {
            return;
        }
        var raw0 = yvoFpAllocGetShareForTab(parentIds[0]);
        var raw1 = yvoFpAllocGetShareForTab(parentIds[1]);
        var units0 = yvoFpShareTo100Units(raw0);
        var units1 = raw1 ? yvoFpShareTo100Units(raw1) : 0;
        if (raw1 && yvoFpAllocIsStaleDefaultShare(raw1) && units0 > 0 && units0 !== units1) {
            units1 = 0;
        }
        var total = 0;
        if (units0 > 0 && units1 > 0) {
            if (units0 === units1 && units0 > 50) {
                total = units0;
            } else {
                total = units0 + units1;
            }
        } else {
            total = units0 || units1;
        }
        if (total <= 0) {
            return;
        }
        parentIds.forEach(function(tid, idx) {
            var u = yvoFpAllocSplitUnitsAmong(total, idx, parentIds.length);
            yvoFpAllocForceSetShareForTab(tid, u + '/100');
        });
    }

    function yvoFpAllocClearStaleHalfDefaults() {
        var parentIds = yvoFpAllocParentSellerIds();
        yvoShareMatrixParticipantIds().forEach(function(tid) {
            var raw = yvoFpAllocGetShareForTab(tid);
            if (!yvoFpAllocIsStaleDefaultShare(raw)) {
                return;
            }
            if (parentIds.indexOf(tid) > 0) {
                yvoFpAllocForceSetShareForTab(tid, '');
            }
        });
    }

    function yvoFpAllocAnyBuyerNeedsMatCapShare() {
        var parts = yvoFpMatCapParticipantCounts();
        return parts.buyerIds.some(function(tid) {
            var raw = yvoFpAllocGetShareForTab(tid);
            if (!raw) {
                return true;
            }
            if (yvoFpAllocIsStaleDefaultShare(raw)) {
                return true;
            }
            return yvoFpShareTo100Units(raw) <= 0;
        });
    }

    function yvoFpAllocReceiverTabIds() {
        return yvoShareMatrixParticipantIds().filter(function(tid) {
            return yvoFpAllocIsBuyerTab(tid);
        });
    }

    function yvoFpAllocTransferorTabIds() {
        return yvoShareMatrixParticipantIds().filter(function(tid) {
            return /^seller\d*$/.test(tid) || /^minor_seller/.test(tid);
        });
    }

    function yvoFpAllocApplyDistribution(calc) {
        if (!calc || !calc.ok || !calc.distribution) {
            return false;
        }
        calc.distribution.forEach(function(row) {
            if (row.joint && row.jointTabs && row.jointTabs.length) {
                row.jointTabs.forEach(function(tid, idx) {
                    yvoFpAllocForceSetShareForTab(tid, idx === 0 ? row.shareStr : '');
                });
            } else {
                yvoFpAllocForceSetShareForTab(row.tab, row.shareStr);
            }
        });
        return true;
    }

    /** После записи в карточки — обновить таблицу на шаге «Объект». */
    function yvoFpAllocSyncSharesFromPanelsToSummary() {
        if (currentContractType !== 'share_allocation') {
            return;
        }
        yvoShareMatrixParticipantIds().forEach(function(tid) {
            var v = ($('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val() || '').trim();
            var $sum = $('#yvo-fp-share-participants-summary .yvo-fp-share-summary-input[data-tab="' + tid + '"]');
            if ($sum.length && v && document.activeElement !== $sum[0]) {
                $sum.val(v);
            }
        });
    }

    function yvoFpAllocApplyMatCapFromCompute(calc) {
        if (!calc || !calc.ok) {
            return false;
        }
        $('#yvo-fp-share-mode').val('mat_capital');
        yvoFpAllocApplyDistribution(calc);
        if (yvoFpAllocIsJointOwnership()) {
            yvoFpAllocConsolidateJointParentShares();
        }
        yvoFpAllocBalanceFinalSharesTo100(true);
        yvoFpAllocSyncSharesFromPanelsToSummary();
        return true;
    }

    /** Пересчёт долей после добавления/удаления участника или смены «совместной собственности». */
    function yvoFpAllocRedistributeAfterParticipantsChanged() {
        if (currentContractType !== 'share_allocation') {
            return;
        }
        $('#property_share_family_members').removeAttr('data-user-set');
        yvoFpMatCapSyncDefaultsFromProperty();
        yvoFpAllocClearStaleHalfDefaults();
        var cap = yvoFpMatCapParseMoney($('#property_maternity_capital_rub').val());
        var price = yvoFpMatCapParseMoney($('#property_purchase_price_shares').val());
        var calc = yvoFpMatCapCompute();
        if (calc.ok && cap > 0 && price > 0) {
            yvoFpMatCapRenderResults(calc);
            yvoFpAllocApplyMatCapFromCompute(calc);
        } else {
            if (yvoFpAllocIsJointOwnership()) {
                yvoFpAllocConsolidateJointParentShares();
            } else {
                yvoFpAllocExpandSeparateParentShares(true);
            }
            yvoFpAllocSyncCustomAlienatedShares(true);
            yvoFpAllocUnifyAllShares(true);
        }
        yvoSyncSellersSharesHidden();
        yvoRefreshShareParticipantsSummary(true);
        yvoFpRefreshAllocSharePreview();
        yvoFpMatCapSchedulePreview();
    }

    function yvoFpAllocOnJointOwnershipChanged() {
        if (currentContractType !== 'share_allocation') {
            return;
        }
        if (yvoFpAllocIsJointOwnership()) {
            yvoFpAllocConsolidateJointParentShares();
            if (yvoFpAllocUsesFinalShares()) {
                yvoFpAllocBalanceFinalSharesTo100(true);
            } else {
                yvoFpAllocSyncCustomAlienatedShares(true);
            }
        } else {
            yvoFpAllocExpandSeparateParentShares(true);
            if (yvoFpAllocUsesFinalShares()) {
                yvoFpAllocBalanceFinalSharesTo100(true);
            } else {
                yvoFpAllocSyncCustomAlienatedShares(true);
            }
        }
        yvoFpAllocUnifyAllShares(true);
        yvoFpMatCapSchedulePreview();
        yvoFpRefreshAllocSharePreview();
    }

    function yvoFpAllocConsolidateJointParentShares() {
        if (!yvoFpAllocIsJointOwnership()) {
            return;
        }
        var ids = yvoFpAllocParentSellerIds();
        if (ids.length < 2) {
            return;
        }
        var combinedF = yvoFpAllocJointParentCombinedFloat();
        if (combinedF === null || combinedF <= 0) {
            for (var j = 1; j < ids.length; j++) {
                yvoFpAllocSetShareForTab(ids[j], '', true);
            }
            return;
        }
        var combined = yvoFpShareAs100ths(combinedF, 'down');
        yvoFpAllocSetShareForTab(ids[0], combined, true);
        for (var i = 1; i < ids.length; i++) {
            yvoFpAllocForceSetShareForTab(ids[i], '');
        }
    }

    function yvoFpAllocIsBuyerTab(tid) {
        return /^buyer\d*$/.test(tid) || /^minor_buyer/.test(tid) || /^contributor/.test(tid);
    }

    /** Сумма получаемых долей (в сотых). */
    function yvoFpAllocBuyerReceived100Units() {
        var units = 0;
        yvoShareMatrixParticipantIds().forEach(function(tid) {
            if (!yvoFpAllocIsBuyerTab(tid)) {
                return;
            }
            var raw = yvoFpAllocGetShareForTab(tid);
            if (!raw) {
                return;
            }
            var u = yvoFpShareTo100Units(raw);
            if (u > 0) {
                units += u;
            }
        });
        return units;
    }

    /**
     * Custom-режим: в таблице «отчуждаемая» доля родителей = сумма «получаемых» у детей.
     * После МСК/поровну в полях остаются итоговые доли — пересчитываем.
     */
    function yvoFpAllocLooksLikeFinalSharesInCustom() {
        if (yvoFpAllocUsesFinalShares()) {
            return false;
        }
        var sums = yvoFpAllocShareSideSums();
        if (sums.buyerSum <= 0 || sums.missing > 0) {
            return false;
        }
        if (Math.abs(sums.sellerSum - sums.buyerSum) < 0.005) {
            return false;
        }
        if (sums.sellerSum <= sums.buyerSum) {
            return false;
        }
        return Math.abs(yvoFpAllocMergedFinalShareSum() - 1) < 0.005;
    }

    function yvoFpAllocSyncCustomAlienatedShares(force) {
        if (currentContractType !== 'share_allocation' || yvoFpAllocUsesFinalShares()) {
            return false;
        }
        yvoFpAllocConsolidateJointParentShares();
        var buyerUnits = yvoFpAllocBuyerReceived100Units();
        if (buyerUnits <= 0) {
            return false;
        }
        var sums = yvoFpAllocShareSideSums();
        var sellerUnits = Math.round(sums.sellerSum * 100);
        if (Math.abs(sellerUnits - buyerUnits) < 1) {
            return false;
        }
        if (!force && !yvoFpAllocLooksLikeFinalSharesInCustom()) {
            return false;
        }
        var joint = yvoFpAllocIsJointOwnership();
        var parentIds = yvoFpAllocParentSellerIds();
        var changed = false;
        if (joint && parentIds.length) {
            yvoFpAllocForceSetShareForTab(parentIds[0], buyerUnits + '/100');
            changed = true;
            for (var i = 1; i < parentIds.length; i++) {
                if (yvoFpAllocGetShareForTab(parentIds[i])) {
                    yvoFpAllocForceSetShareForTab(parentIds[i], '');
                    changed = true;
                }
            }
        } else if (sums.sellerIds.length >= 2) {
            var splitTargets = parentIds.length >= 2 ? parentIds : sums.sellerIds;
            splitTargets.forEach(function(tid, idx) {
                var u = yvoFpAllocSplitUnitsAmong(buyerUnits, idx, splitTargets.length);
                yvoFpAllocForceSetShareForTab(tid, u + '/100');
            });
            changed = true;
        } else if (sums.sellerIds.length === 1) {
            yvoFpAllocForceSetShareForTab(sums.sellerIds[0], buyerUnits + '/100');
            changed = true;
        }
        return changed;
    }

    function yvoFpAllocEnterCustomShareMode() {
        var mode = ($('#yvo-fp-share-mode').val() || 'custom').trim();
        if (mode === 'mat_capital' || mode === 'equal') {
            $('#yvo-fp-share-mode').val('custom');
            yvoFpAllocSyncCustomAlienatedShares(true);
            yvoFpAllocUpdateParticipantFieldLabels();
        }
    }

    function yvoFpAllocMergedFinalShareSum() {
        var sumUnits = 0;
        yvoFpComputeAllocFinalShareRows().forEach(function(r) {
            sumUnits += yvoFpShareTo100Units(r.final_fraction);
        });
        return sumUnits / 100;
    }

    function yvoFpAllocMergeJointParentRows(rows) {
        if (!yvoFpAllocIsJointOwnership()) {
            return rows;
        }
        var parentIds = yvoFpAllocParentSellerIds();
        if (parentIds.length < 2) {
            return rows;
        }
        var parentRows = [];
        var otherRows = [];
        rows.forEach(function(r) {
            if (r.role === 'seller' && parentIds.indexOf(r.tab) !== -1) {
                parentRows.push(r);
            } else {
                otherRows.push(r);
            }
        });
        if (!parentRows.length) {
            return rows;
        }
        var shareF = 0;
        parentRows.forEach(function(r) {
            var ff = yvoParseShareFractionToFloat(r.final_fraction);
            if (ff !== null && ff > 0) {
                shareF += ff;
            }
        });
        if (shareF <= 0) {
            shareF = yvoFpAllocJointParentCombinedFloat();
        }
        var share = shareF !== null && shareF > 0 ? yvoFpShareAs100ths(shareF, 'down') : '';
        if (!share && parentRows[0]) {
            share = parentRows[0].final_fraction || parentRows[0].share_fraction || '';
        }
        otherRows.unshift({
            tab: parentIds[0],
            role: 'seller',
            full_name: yvoFpAllocJointParentLabel(),
            share_fraction: share,
            final_fraction: share,
            joint_ownership: true
        });
        return otherRows;
    }

    /** Режим распределения долей (только выделение долей). */
    function yvoFpAllocShareMode() {
        return ($('#yvo-fp-share-mode').val() || 'custom').trim();
    }

    /** Доля участника: из таблицы на шаге «Объект» или из карточки. */
    function yvoFpAllocGetShareForTab(tid) {
        var v = '';
        var $sum = $('#yvo-fp-share-participants-summary .yvo-fp-share-summary-input[data-tab="' + tid + '"]');
        if ($sum.length) {
            v = ($sum.val() || '').trim();
        }
        if (!v) {
            v = ($('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val() || '').trim();
        }
        return v;
    }

    function yvoFpAllocSyncSharesFromSummaryToPanels() {
        if (currentContractType !== 'share_allocation') {
            return;
        }
        $('#yvo-fp-share-participants-summary .yvo-fp-share-summary-input').each(function() {
            var tid = $(this).attr('data-tab');
            var v = ($(this).val() || '').trim();
            if (tid && v) {
                $('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val(v);
            }
        });
    }

    /** Итоговые доли в карточках (поровну / МСК), а не выделяемые части. */
    function yvoFpAllocUsesFinalShares() {
        var m = yvoFpAllocShareMode();
        return m === 'equal' || m === 'mat_capital';
    }

    function yvoFpAllocShareSideSums() {
        var sellerSum = 0;
        var buyerSum = 0;
        var missing = 0;
        var missingIds = [];
        var count = 0;
        var sellerIds = [];
        var buyerIds = [];
        var joint = yvoFpAllocIsJointOwnership();
        var parentIds = yvoFpAllocParentSellerIds();
        var jointCombined = joint ? yvoFpAllocJointParentCombinedFloat() : null;
        yvoShareMatrixParticipantIds().forEach(function(tid) {
            count++;
            var v = yvoFpAllocGetShareForTab(tid);
            var isSeller = /^seller/.test(tid) || /^minor_seller/.test(tid);
            if (joint && isSeller && parentIds.indexOf(tid) !== -1 && tid !== parentIds[0]) {
                return;
            }
            var f = yvoParseShareFractionToFloat(v);
            if (f === null || f === undefined) {
                missing++;
                missingIds.push(tid);
                return;
            }
            if (isSeller) {
                if (joint && parentIds.indexOf(tid) !== -1) {
                    if (tid === parentIds[0]) {
                        sellerSum += jointCombined !== null ? jointCombined : f;
                    }
                } else {
                    sellerSum += f;
                }
                sellerIds.push(tid);
            } else {
                buyerSum += f;
                buyerIds.push(tid);
            }
        });
        return {
            sellerSum: sellerSum,
            buyerSum: buyerSum,
            missing: missing,
            missingIds: missingIds,
            count: count,
            sellerIds: sellerIds,
            buyerIds: buyerIds
        };
    }

    function yvoFpValidateAllocShares() {
        if (!yvoFpAllocUsesFinalShares() && yvoFpAllocLooksLikeFinalSharesInCustom()) {
            yvoFpAllocSyncCustomAlienatedShares();
        }
        var sums = yvoFpAllocShareSideSums();
        if (yvoFpAllocIsJointOwnership()) {
            var parentIds = yvoFpAllocParentSellerIds();
            var parentHasShare = parentIds.some(function(tid) {
                return yvoParseShareFractionToFloat(yvoFpAllocGetShareForTab(tid)) !== null;
            });
            if (!parentHasShare) {
                sums.missing++;
                sums.missingIds.push(parentIds[0]);
            }
        }
        if (sums.missing > 0) {
            return {
                ok: false,
                msg: 'Укажите долю (дробь) для каждого участника (пустые: ' + sums.missingIds.join(', ') + ')'
            };
        }
        if (yvoFpAllocUsesFinalShares()) {
            var total = yvoFpAllocMergedFinalShareSum();
            if (Math.abs(total - 1) > 0.002) {
                return {
                    ok: false,
                    msg: 'Сумма итоговых долей должна быть 100%. Сейчас: ' + (total * 100).toFixed(2) + '%.'
                };
            }
            return { ok: true, msg: 'Сумма итоговых долей: 100%.' };
        }
        if (Math.abs(sums.sellerSum - sums.buyerSum) > 1e-4) {
            return {
                ok: false,
                msg: 'Сумма отчуждаемых долей (' + (sums.sellerSum * 100).toFixed(2)
                    + '%) должна равняться сумме получаемых (' + (sums.buyerSum * 100).toFixed(2) + '%).'
            };
        }
        if (sums.buyerSum >= 1 - 1e-4) {
            return {
                ok: false,
                msg: 'Сумма выделяемых долей получающим не может быть 100% или больше.'
            };
        }
        return {
            ok: true,
            msg: 'Распределение сходится: отчуждается '
                + (sums.sellerSum * 100).toFixed(2) + '%, получается ' + (sums.buyerSum * 100).toFixed(2) + '%.'
        };
    }

    function yvoFpComputeAllocFinalShareRows() {
        var rows = yvoFpAllocMergeJointParentRows(yvoFpComputeAllocFinalShareRowsCore());
        return yvoFpUnifyRowFinalFractions(rows);
    }

    /** Сумма дробей участников (точная, без float). */
    function yvoFpAllocSumFractionStrForIds(ids) {
        var nds = [];
        (ids || []).forEach(function(tid) {
            var nd = yvoParseShareFractionNd(yvoFpAllocGetShareForTab(tid));
            if (nd) {
                nds.push(nd);
            }
        });
        if (!nds.length) {
            return '';
        }
        var D = nds.length === 1 ? nds[0].d : yvoLcmArray(nds.map(function(nd) { return nd.d; }));
        if (!D) {
            return '';
        }
        var num = 0;
        nds.forEach(function(nd) {
            num += nd.n * (D / nd.d);
        });
        var s = yvoSimplifyFrac(num, D);
        return s[0] + '/' + s[1];
    }

    /** Числители отчуждаемых/получаемых на общем знаменателе D. */
    function yvoFpAllocSideNumeratorsOnDenom(D) {
        var sellerNum = 0;
        var buyerNum = 0;
        var joint = yvoFpAllocIsJointOwnership();
        var parentIds = yvoFpAllocParentSellerIds();
        yvoShareMatrixParticipantIds().forEach(function(tid) {
            var nd = yvoParseShareFractionNd(yvoFpAllocGetShareForTab(tid));
            if (!nd) {
                return;
            }
            var isSeller = /^seller/.test(tid) || /^minor_seller/.test(tid);
            if (joint && isSeller && parentIds.indexOf(tid) !== -1 && tid !== parentIds[0]) {
                return;
            }
            var nn = nd.n * (D / nd.d);
            if (isSeller) {
                sellerNum += nn;
            } else {
                buyerNum += nn;
            }
        });
        return { sellerNum: sellerNum, buyerNum: buyerNum, D: D };
    }

    /** Итоговая доля в custom-режиме — точная дробь, не float→/100. */
    function yvoFpAllocCustomFinalShareStr(shareStr, isSeller, sideNums) {
        if (!isSeller) {
            return shareStr;
        }
        var nd = yvoParseShareFractionNd(shareStr);
        if (!nd || !sideNums || !sideNums.D) {
            return shareStr || '';
        }
        var D = sideNums.D;
        var sellerN = nd.n * (D / nd.d);
        if (sideNums.sellerNum <= 0) {
            var remOnly = yvoSimplifyFrac(D - sideNums.buyerNum, D);
            return remOnly[0] + '/' + remOnly[1];
        }
        var remNum = D - sideNums.buyerNum;
        if (remNum <= 0) {
            return '0/1';
        }
        var finalNum = remNum * sellerN;
        var finalDen = sideNums.sellerNum * D;
        var s = yvoSimplifyFrac(finalNum, finalDen);
        return s[0] + '/' + s[1];
    }

    /** Итоговые доли превью — формат NN/100. */
    function yvoFpUnifyRowFinalFractions(rows) {
        return (rows || []).map(function(r) {
            if (!r.final_fraction) {
                return r;
            }
            var f = yvoParseShareFractionToFloat(r.final_fraction);
            if (f === null) {
                return r;
            }
            var mode = (r.joint_ownership || (r.role === 'seller' && yvoFpAllocUsesFinalShares())) ? 'down' : 'up';
            return Object.assign({}, r, { final_fraction: yvoFpShareAs100ths(f, mode) });
        });
    }

    function yvoFpComputeAllocFinalShareRowsCore() {
        var ids = yvoShareMatrixParticipantIds();
        var rows = [];
        var sums = yvoFpAllocShareSideSums();
        var joint = yvoFpAllocIsJointOwnership();
        var parentIds = yvoFpAllocParentSellerIds();
        var remainder = 1 - sums.buyerSum;
        if (remainder < 0 && remainder > -1e-9) {
            remainder = 0;
        }
        if (remainder < 0) {
            remainder = 0;
        }
        ids.forEach(function(tid) {
            if (joint && parentIds.indexOf(tid) > 0) {
                return;
            }
            var name = ($('#yvo-fp-panel-' + tid).find('[data-key="full_name"]').val() || '').trim();
            if (!name) {
                name = yvoFpGiftParticipantName(tid);
            }
            var share = yvoFpAllocGetShareForTab(tid);
            var isSeller = /^seller/.test(tid) || /^minor_seller/.test(tid);
            var finalStr;
            if (yvoFpAllocUsesFinalShares()) {
                finalStr = share;
            } else {
                var f = yvoParseShareFractionToFloat(share);
                var finalF;
                if (isSeller) {
                    if (sums.sellerSum > 1e-9 && f !== null) {
                        finalF = remainder * (f / sums.sellerSum);
                    } else if (sums.sellerIds.length === 1) {
                        finalF = remainder;
                    } else {
                        finalF = 0;
                    }
                    finalStr = yvoFpShareAs100ths(finalF, 'down');
                } else {
                    finalF = f !== null ? f : 0;
                    finalStr = yvoFpShareAs100ths(finalF, 'up');
                }
            }
            rows.push({
                tab: tid,
                role: isSeller ? 'seller' : 'buyer',
                full_name: name,
                share_fraction: share,
                final_fraction: finalStr
            });
        });
        return rows;
    }

    function yvoFpAllocUpdateParticipantFieldLabels() {
        if (currentContractType !== 'share_allocation') {
            return;
        }
        var finalMode = yvoFpAllocUsesFinalShares();
        yvoShareMatrixParticipantIds().forEach(function(tid) {
            var isSeller = /^seller/.test(tid) || /^minor_seller/.test(tid);
            var $row = $('#yvo-fp-panel-' + tid).find('.yvo-fp-share-fraction-row');
            var $lab = $row.find('label');
            if (!$lab.length) {
                return;
            }
            if (finalMode) {
                $lab.text('Доля в праве после выделения (дробь, напр. 1/4)');
            } else if (isSeller) {
                $lab.text('Отчуждаемая доля (дробь, напр. 1/10)');
            } else {
                $lab.text('Получаемая доля (дробь, напр. 1/20)');
            }
        });
    }

    function yvoFpRefreshAllocSharePreview(opts) {
        opts = opts || {};
        if (currentContractType !== 'share_allocation') {
            return;
        }
        yvoFpAllocSyncSharesFromPanelsToSummary();
        yvoFpAllocConsolidateJointParentShares();
        if (!yvoFpAllocUsesFinalShares()) {
            yvoFpAllocSyncCustomAlienatedShares();
        }
        if (!opts.skipUnify) {
            yvoFpAllocUnifyAllShares(true);
        }
        var $host = $('#yvo-fp-alloc-preview-host');
        if (!$host.length) {
            return;
        }
        $host.empty();
        var rows = yvoFpComputeAllocFinalShareRows();
        var hasAnyFinal = rows.some(function(r) {
            var ff = yvoParseShareFractionToFloat(r.final_fraction);
            return ff !== null && ff > 1e-9;
        });
        var $preview = $('<div class="yvo-fp-alloc-shares-preview" />');
        $preview.append($('<h4 class="yvo-fp-alloc-shares-preview__title" />').text('Доли в квартире после выделения'));
        if (yvoFpAllocUsesFinalShares()) {
            $preview.append($('<p class="yvo-fp-alloc-shares-preview__lead" />').text('Итоговое распределение долей в праве общей долевой собственности:'));
        } else {
            $preview.append($('<p class="yvo-fp-alloc-shares-preview__lead" />').text('По указанным выделяемым долям; остаток квартиры остаётся у отчуждающих:'));
        }
        var $list = $('<ul class="yvo-fp-alloc-shares-preview__list" />');
        if (!hasAnyFinal) {
            $list.append($('<li class="yvo-fp-alloc-shares-preview__empty"/>').text('Укажите доли в таблице выше'));
        } else {
            rows.forEach(function(r) {
                var ff = yvoParseShareFractionToFloat(r.final_fraction);
                if (ff === null || ff <= 1e-9) {
                    return;
                }
                var jointNote = r.joint_ownership ? ' (совместная собственность супругов)' : '';
                $list.append($('<li/>').text(
                    r.full_name + ' – ' + r.final_fraction + ' доли в праве общей долевой собственности' + jointNote
                ));
            });
        }
        $preview.append($list);
        if (hasAnyFinal) {
            var finalSum = yvoFpAllocMergedFinalShareSum();
            var sumLabel = Math.abs(finalSum - 1) < 0.005
                ? '100%'
                : (finalSum * 100).toFixed(2) + '%';
            $preview.append($('<p class="yvo-fp-alloc-shares-preview__sum" />').text('Итого: ' + sumLabel));
        }
        $host.append($preview);
        var val = yvoFpValidateAllocShares();
        if (val.msg) {
            yvoSetShareValidation(val.msg, !!val.ok);
        } else {
            yvoSetShareValidation('', false);
        }
    }

    function yvoFpRenderAllocShareUi(forceRebuild) {
        var $wrap = $('#yvo-fp-share-participants-summary');
        if (!$wrap.length) {
            return;
        }
        var ids = yvoShareMatrixParticipantIds();
        var participantKey = ids.join(',');
        var colTitle = yvoFpAllocUsesFinalShares() ? 'Доля в праве' : 'Выделяемая / получаемая доля';
        if (!ids.length) {
            $wrap.empty().removeAttr('data-alloc-participants');
            $('#yvo-fp-alloc-preview-host').empty();
            $wrap.append($('<p class="yvo-fp-section-hint" />').text('Добавьте участников вкладками «Добавить участника».'));
            yvoSetShareValidation('', false);
            return;
        }
        var needRebuild = !!forceRebuild
            || !$wrap.find('.yvo-fp-alloc-summary-table').length
            || $wrap.attr('data-alloc-participants') !== participantKey
            || $wrap.attr('data-alloc-col-title') !== colTitle;
        if (needRebuild) {
            $wrap.empty()
                .attr('data-alloc-participants', participantKey)
                .attr('data-alloc-col-title', colTitle);
            var $table = $('<table class="yvo-fp-share-summary-table yvo-fp-alloc-summary-table" />');
            $table.append(
                $('<thead/>').append(
                    $('<tr/>')
                        .append($('<th/>').text('ФИО'))
                        .append($('<th/>').text('Сторона'))
                        .append($('<th/>').text(colTitle))
                )
            );
            var $tbody = $('<tbody/>');
            ids.forEach(function(tid) {
                var side = yvoShareMatrixSideLabel(tid, 'share_allocation');
                var name = yvoFpGiftParticipantName(tid);
                var val = yvoFpAllocGetShareForTab(tid);
                var $tr = $('<tr/>');
                $tr.append($('<td class="yvo-fp-alloc-summary-name" />').text(name));
                $tr.append($('<td class="yvo-fp-alloc-summary-side" />').text(side));
                var $inp = $('<input type="text" class="yvo-fp-input yvo-fp-share-summary-input" />')
                    .attr('data-tab', tid)
                    .attr('placeholder', '1/2')
                    .attr('aria-label', 'Доля ' + name)
                    .val(val);
                $tr.append($('<td/>').append($inp));
                $tbody.append($tr);
            });
            $table.append($tbody);
            $wrap.append($table);
        } else {
            ids.forEach(function(tid) {
                var $inp = $wrap.find('.yvo-fp-share-summary-input[data-tab="' + tid + '"]');
                var val = yvoFpAllocGetShareForTab(tid);
                if ($inp.length && document.activeElement !== $inp[0]) {
                    $inp.val(val);
                }
                var $tr = $inp.closest('tr');
                if ($tr.length) {
                    $tr.find('.yvo-fp-alloc-summary-name').text(yvoFpGiftParticipantName(tid));
                    $tr.find('.yvo-fp-alloc-summary-side').text(yvoShareMatrixSideLabel(tid, 'share_allocation'));
                }
            });
        }
        yvoFpAllocUpdateParticipantFieldLabels();
        yvoFpRefreshAllocSharePreview();
    }

    /** Сводная матрица: ФИО, сторона, доля (редактирование синхронизируется с карточками участников). */
    function yvoRefreshShareParticipantsSummary(forceRebuild) {
        if (!yvoFpShowShareMatrix()) {
            return;
        }
        var $wrap = $('#yvo-fp-share-participants-summary');
        if (!$wrap.length) {
            return;
        }
        var ct = currentContractType;
        if (ct === 'gift') {
            yvoFpRenderGiftShareUi(forceRebuild);
            return;
        }
        if (ct === 'share_allocation') {
            yvoFpRenderAllocShareUi(!!forceRebuild);
            return;
        }
        var ids = yvoShareMatrixParticipantIds();
        $wrap.empty();
        if (!ids.length) {
            $wrap.append($('<p class="yvo-fp-section-hint" />').text('Добавьте участников вкладками «Добавить участника».'));
            return;
        }
        var shareColTitle = 'Доля в праве';
        var $table = $('<table class="yvo-fp-share-summary-table" />');
        $table.append(
            $('<thead/>').append(
                $('<tr/>')
                    .append($('<th/>').text('ФИО'))
                    .append($('<th/>').text('Сторона'))
                    .append($('<th/>').text(shareColTitle))
            )
        );
        var $tbody = $('<tbody/>');
        ids.forEach(function(tid) {
            var side = yvoShareMatrixSideLabel(tid, ct);
            var name = yvoFpGiftParticipantName(tid);
            var $panelInp = $('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]');
            var val = $panelInp.val() || '';
            var $tr = $('<tr/>');
            $tr.append($('<td/>').text(name));
            $tr.append($('<td/>').text(side));
            var $inp = $('<input type="text" class="yvo-fp-input yvo-fp-share-summary-input" />')
                .attr('data-tab', tid)
                .attr('placeholder', '1/2')
                .attr('aria-label', 'Доля ' + name)
                .val(val);
            $tr.append($('<td/>').append($inp));
            $tbody.append($tr);
        });
        $table.append($tbody);
        $wrap.append($table);
    }

    function yvoFpObjectTypeIsShare() {
        return ($('#property_object_type').val() || 'apartment') === 'share';
    }

    function yvoFpShareFractionIsWhole(str) {
        var t = String(str || '').trim();
        if (!t) {
            return true;
        }
        var f = yvoParseShareFractionToFloat(t);
        return f !== null && Math.abs(f - 1) <= 1e-4;
    }

    /** Шаблон «доля в квартире» — только при типе объекта «Доля» в форме. */
    function yvoFpGiftUsesDolyaKvartiraTemplate(property) {
        var ot = (property && property.object_type)
            ? String(property.object_type).trim()
            : ($('#property_object_type').val() || 'apartment').trim();
        return ot === 'share';
    }

    function yvoFpSyncGiftTemplateForObjectType() {
        if (currentContractType !== 'gift') {
            return;
        }
        var tpl = yvoFpGiftUsesDolyaKvartiraTemplate() ? 'shablon-darenie-dolya-kvartira' : 'shablon-darenie-dogovor';
        var $sel = $('#yvo-fp-contract-template');
        if ($sel.find('option[value="' + tpl + '"]').length) {
            $sel.val(tpl);
        }
    }

    function yvoFpDepositTemplateIdForObjectType(ot) {
        var map = {
            apartment: 'deposit-agreement',
            share: 'deposit-agreement',
            room: 'deposit-agreement-room',
            house_with_plot: 'deposit-agreement-house',
            land: 'deposit-agreement-land'
        };
        return map[ot] || 'deposit-agreement';
    }

    function yvoFpSyncDepositTemplateForObjectType() {
        if (currentContractType !== 'deposit_agreement') {
            return;
        }
        var ot = $('#property_object_type').val() || 'apartment';
        var tpl = yvoFpDepositTemplateIdForObjectType(ot);
        var $sel = $('#yvo-fp-contract-template');
        if ($sel.find('option[value="' + tpl + '"]').length) {
            $sel.val(tpl);
        }
    }

    function yvoFpAdvanceTemplateIdForObjectType(ot) {
        var map = {
            apartment: 'advance-agreement',
            share: 'advance-agreement',
            room: 'advance-agreement-room',
            house_with_plot: 'advance-agreement-house',
            land: 'advance-agreement-land'
        };
        return map[ot] || 'advance-agreement';
    }

    function yvoFpSyncAdvanceTemplateForObjectType() {
        if (currentContractType !== 'advance_agreement') {
            return;
        }
        var ot = $('#property_object_type').val() || 'apartment';
        var tpl = yvoFpAdvanceTemplateIdForObjectType(ot);
        var $sel = $('#yvo-fp-contract-template');
        if ($sel.find('option[value="' + tpl + '"]').length) {
            $sel.val(tpl);
        }
    }

    /** Дарение долей в квартире/комнате — не для дома, земли, гаража и т.п. */
    function yvoFpGiftAllowsShareMatrix() {
        var ot = $('#property_object_type').val() || 'apartment';
        return ot === 'share' || ot === 'apartment' || ot === 'room';
    }

    /** Дарение целого объекта (квартира, комната, дом, земля): сумма долей = 100%. */
    function yvoFpGiftWholePropertyShareSum() {
        return currentContractType === 'gift' && !yvoFpObjectTypeIsShare();
    }

    /** Число одаряемых (взрослых и несовершеннолетних) для матрицы дарения. */
    function yvoFpGiftDoneeTabCount() {
        return yvoFpGiftDoneeTabIds().length;
    }

    /** Нужно распределение долей между одаряемыми (квартира/комната/доля или 2+ одаряемых). */
    function yvoFpGiftNeedsShareDistribution() {
        if (currentContractType !== 'gift') {
            return false;
        }
        if (yvoFpObjectTypeIsShare()) {
            return true;
        }
        if (yvoFpGiftAllowsShareMatrix()) {
            return true;
        }
        return yvoFpGiftDoneeTabCount() >= 2;
    }

    /** Матрица долей: выделение долей, объект «Доля» или дарение с несколькими одаряемыми. */
    function yvoFpShowShareMatrix() {
        return currentContractType === 'share_allocation'
            || yvoFpObjectTypeIsShare()
            || (currentContractType === 'gift' && yvoFpGiftNeedsShareDistribution());
    }

    function yvoFpFirstPrincipalSellerTabId() {
        var ids = yvoShareMatrixParticipantIds();
        var i;
        for (i = 0; i < ids.length; i++) {
            if (/^seller\d*$/.test(ids[i])) {
                return ids[i];
            }
        }
        for (i = 0; i < ids.length; i++) {
            if (/^minor_seller/.test(ids[i])) {
                return ids[i];
            }
        }
        return $('#yvo-fp-panel-seller').length ? 'seller' : (ids[0] || 'seller');
    }

    function yvoSyncShareInRightFromParticipant(tid, val) {
        if (!yvoFpObjectTypeIsShare() || currentContractType !== 'gift') {
            return;
        }
        if (tid !== yvoFpFirstPrincipalSellerTabId()) {
            return;
        }
        var $inp = $('#yvo-fp-panel-property .yvo-fp-object-type-block.active [data-key="share_in_right"]');
        if ($inp.length) {
            $inp.val(val);
        }
    }

    function yvoSyncParticipantShareFromShareInRight() {
        if (!yvoFpObjectTypeIsShare() || currentContractType !== 'gift') {
            return;
        }
        var v = ($('#yvo-fp-panel-property .yvo-fp-object-type-block.active [data-key="share_in_right"]').val() || '').trim();
        if (!v) {
            return;
        }
        var tid = yvoFpFirstPrincipalSellerTabId();
        $('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val(v);
    }

    function yvoShareMatrixSideLabel(tid, ct) {
        tid = String(tid);
        var isSeller = /^seller/.test(tid) || /^minor_seller/.test(tid);
        var isBuyer = /^buyer/.test(tid) || /^minor_buyer/.test(tid) || /^contributor/.test(tid);
        if (ct === 'share_allocation') {
            return isSeller ? 'Отчуждает' : 'Получает';
        }
        if (ct === 'gift') {
            if (/^minor_seller/.test(tid)) {
                return 'Даритель (несоверш.)';
            }
            if (/^minor_buyer/.test(tid)) {
                return 'Одаряемый (несоверш.)';
            }
            return isSeller ? 'Даритель' : 'Одаряемый';
        }
        if (/^minor_seller/.test(tid)) {
            return 'Продавец (несоверш.)';
        }
        if (/^minor_buyer/.test(tid)) {
            return 'Покупатель (несоверш.)';
        }
        return isSeller ? 'Продавец' : (isBuyer ? 'Покупатель' : 'Участник');
    }

    function yvoUpdateShareMatrixChrome() {
        var showMatrix = yvoFpShowShareMatrix();
        var onAlloc = (currentContractType === 'share_allocation');
        var isGift = currentContractType === 'gift';
        var giftShare = yvoFpObjectTypeIsShare() && isGift;
        var $block = $('#yvo-fp-share-allocation-block');
        if (!$block.length) {
            return;
        }
        $block.find('.yvo-fp-share-matrix-tools').toggle(onAlloc);
        $block.find('.yvo-fp-mat-calc-panel').toggle(onAlloc);
        $block.find('#yvo-fp-alloc-advanced').toggle(onAlloc);
        $block.find('.yvo-fp-share-matrix-quick').toggle(!onAlloc && (yvoFpObjectTypeIsShare() || isGift));
        var $title = $block.find('.yvo-fp-collapse-title').first();
        var $hint = $block.find('.yvo-fp-share-hint').first();
        if (isGift && giftShare) {
            $title.text('Дарение доли');
            $hint.text('Укажите отчуждаемую долю дарителя и долю в дар каждому одаряемому. Сумма может быть меньше 100% — дарится только часть доли.');
        } else if (isGift) {
            $title.text('Условия распределения долей');
            $hint.text('Строки по одаряемым появляются автоматически. Укажите долю в дар; при нескольких дарителях — выберите дарителя в строке.');
        } else if (onAlloc) {
            $title.text('Доли участников (выделение долей)');
            $hint.text('Сначала заполните калькулятор и нажмите «Рассчитать» — как на calcus.ru. Доли запишутся участникам; при необходимости скорректируйте в таблице ниже.');
        } else if (yvoFpObjectTypeIsShare()) {
            $title.text('Матрица долей');
            $hint.text('Все стороны сделки и их доли в праве на квартиру. Сумма долей должна быть 1 (100%).');
        }
    }

    function yvoToggleShareAllocationUi() {
        var on = (currentContractType === 'share_allocation');
        var isGift = (currentContractType === 'gift');
        var objectShare = yvoFpObjectTypeIsShare();
        var showMatrix = yvoFpShowShareMatrix();
        var $block = $('#yvo-fp-share-allocation-block');
        if ($block.length) {
            if (showMatrix) {
                $block.removeAttr('hidden');
                if ($('.yvo-doki-form-skin').length) {
                    $block.addClass('yvo-doki-deal-acc is-open');
                    $('#yvo-fp-share-allocation-body').show();
                }
            } else {
                $block.attr('hidden', 'hidden');
            }
        }
        if (on || objectShare) {
            $('.yvo-fp-share-fraction-row').removeAttr('hidden');
        } else {
            $('.yvo-fp-share-fraction-row').attr('hidden', 'hidden');
        }
        if (objectShare && !on) {
            $('#yvo-fp-panel-property .yvo-fp-share-fraction-row').attr('hidden', 'hidden');
        }
        $('.yvo-doki-finance-pills').toggle(!on && !isGift);
        $('.yvo-fp-price-section').toggle(!on && !isGift);
        $('.yvo-fp-conditions-section').toggle(!on && (!showMatrix || isGift));
        $('#yvo-fp-panel-property .yvo-fp-price-option').toggle(!on && !isGift);
        yvoUpdateShareMatrixChrome();
        if (showMatrix) {
            yvoUpdateShareModeVisibility();
            yvoSyncParticipantShareFromShareInRight();
            yvoFpMatCapSyncDefaultsFromProperty();
            yvoFpAllocUpdateJointOwnershipUi();
            yvoRefreshShareParticipantsSummary();
            yvoSyncSellersSharesHidden();
            yvoFpMatCapSchedulePreview();
        } else {
            $('#yvo-fp-share-participants-summary').empty();
            yvoUpdateDefaultSharesForContract();
        }
    }

    function yvoUpdateShareModeVisibility() {
        var onAlloc = currentContractType === 'share_allocation';
        $('#yvo-fp-mat-calc-panel').toggle(onAlloc);
        if (onAlloc) {
            yvoFpMatCapSyncDefaultsFromProperty();
            yvoFpAllocUpdateJointOwnershipUi();
            yvoFpMatCapSchedulePreview();
            yvoFpRenderAllocShareUi(false);
        }
    }

    function yvoFracStrForEqualParts(n) {
        if (!n || n < 1) return '';
        return '1/' + n;
    }

    function yvoUpdateDefaultSharesForContract() {
        // Для большинства договоров: если несколько продавцов/покупателей — показать «Доля в праве» и поставить 1/N по умолчанию.
        // Если продавец/покупатель один — долю не заполняем (по умолчанию 100%).
        var ct = currentContractType;
        if (!ct || ct === 'share_allocation') {
            return;
        }
        if (yvoFpObjectTypeIsShare()) {
            $('.yvo-fp-share-fraction-row').not('#yvo-fp-panel-property .yvo-fp-share-fraction-row').attr('hidden', 'hidden');
            yvoRefreshShareParticipantsSummary();
            yvoSyncSellersSharesHidden();
            return;
        }
        var ids = yvoParticipantTabIdsOrdered();
        var sellers = ids.filter(function(id) { return /^seller\d*$/.test(id); });
        var buyers = ids.filter(function(id) { return /^buyer\d*$/.test(id); });
        var jointAllowed = sellers.length === 2;
        var jointChecked = false;
        var $jointRow = $('#yvo-fp-joint-ownership-row');
        if ($jointRow.length) {
            if (jointAllowed) {
                $jointRow.removeAttr('hidden');
                jointChecked = !!$('#property_joint_ownership').prop('checked');
            } else {
                $jointRow.attr('hidden', 'hidden');
                $('#property_joint_ownership').prop('checked', false);
            }
        }

        function setSideDefault(idsSide, isSellerSide) {
            var cnt = idsSide.length;
            idsSide.forEach(function(tid) {
                var $row = $('#yvo-fp-panel-' + tid).find('.yvo-fp-share-fraction-row');
                var $inp = $('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]');
                var show = cnt > 1;
                if (isSellerSide && jointChecked) {
                    show = false;
                }
                if ($row.length) {
                    if (show) $row.removeAttr('hidden');
                    else $row.attr('hidden', 'hidden');
                }
                if (cnt <= 1 || (isSellerSide && jointChecked)) {
                    // 100% по умолчанию — не прописываем долю
                    if (($inp.val() || '').trim() !== '') {
                        $inp.val('');
                    }
                    return;
                }
                var v = ($inp.val() || '').trim();
                if (!v) {
                    $inp.val(yvoFracStrForEqualParts(cnt));
                }
            });
        }

        setSideDefault(sellers, true);
        setSideDefault(buyers, false);
        // Обновим скрытую строку долей для договора (используется в шаблонах ДКП)
        yvoSyncSellersSharesHidden();
    }

    function yvoGcd(a, b) {
        a = Math.abs(a);
        b = Math.abs(b);
        while (b) {
            var t = b;
            b = a % b;
            a = t;
        }
        return a || 1;
    }

    function yvoSimplifyFrac(n, d) {
        if (d < 0) {
            n = -n;
            d = -d;
        }
        var g = yvoGcd(n, d);
        return [n / g, d / g];
    }

    function yvoFracToStr(num, den) {
        var s = yvoSimplifyFrac(num, den);
        return s[0] + '/' + s[1];
    }

    /**
     * Доля для договора: округление значения num/den вверх до сотых (0,01), затем дробь со знаменателем 100 (сокращение gcd).
     * Возвращает строку дроби и пояснение, если дробь изменилась (избегаем 1478/2000 и т.п.).
     */
    function yvoFracToStrCeilHundredths(num, den) {
        if (!den || den === 0) {
            return { str: '', note: '' };
        }
        var origStr = yvoFracToStr(num, den);
        var v = num / den;
        if (v <= 0) {
            return { str: origStr, note: '' };
        }
        if (v > 1) {
            return { str: origStr, note: '' };
        }
        var scaled = Math.ceil(v * 100 - 1e-9);
        if (scaled > 100) {
            scaled = 100;
        }
        if (scaled <= 0) {
            scaled = 1;
        }
        var n2 = scaled;
        var d2 = 100;
        var g = yvoGcd(n2, d2);
        n2 /= g;
        d2 /= g;
        var str = n2 + '/' + d2;
        var note = '';
        if (origStr !== str) {
            note = 'Было ' + origStr + ' (≈' + v.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 6 }) + '), для договора: ' + str + ' (округление вверх до сотых).';
        }
        return { str: str, note: note, originalStr: origStr };
    }

    /** Максимальный знаменатель доли в выделении долей (без «1363/1450»). */
    function yvoFpAllocMaxShareDen() {
        return 100;
    }

    /**
     * Доля строго как NN/100 (единый знаменатель для всех участников).
     * roundMode: 'up' — округление вверх (дети), 'down' — вниз (остаток родителям).
     */
    function yvoFpShareAs100ths(v, roundMode) {
        roundMode = roundMode || 'up';
        if (!(v > 0) || v !== v) {
            return '0/100';
        }
        if (v >= 1) {
            return '100/100';
        }
        var eps = 1e-12;
        var units = roundMode === 'down' ? Math.floor(v * 100 + eps) : Math.ceil(v * 100 - eps);
        if (units >= 100) {
            return '100/100';
        }
        if (units <= 0) {
            return '0/100';
        }
        return units + '/100';
    }

    /** Сколько сотых доли в строке n/d (для суммирования). */
    function yvoFpShareTo100Units(str) {
        var nd = yvoParseShareFractionNd(str);
        if (!nd) {
            var f = yvoParseShareFractionToFloat(str);
            if (f === null) {
                return 0;
            }
            return Math.round(f * 100);
        }
        return Math.round((nd.n / nd.d) * 100);
    }

    /**
     * Доля со знаменателем ≤ 100: для выделения долей всегда отдаём NN/100.
     */
    function yvoFpShareFromFloatMax100(v, roundMode) {
        if (currentContractType === 'share_allocation') {
            return yvoFpShareAs100ths(v, roundMode);
        }
        roundMode = roundMode || 'up';
        if (!(v > 0) || v !== v) {
            return '0/1';
        }
        if (v >= 1) {
            return '1/1';
        }
        var maxDen = yvoFpAllocMaxShareDen();
        var eps = 1e-12;
        var raw = v * maxDen;
        var units = roundMode === 'down' ? Math.floor(raw + eps) : Math.ceil(raw - eps);
        if (units >= maxDen) {
            return '1/1';
        }
        if (units <= 0) {
            return '0/1';
        }
        var g = yvoGcd(units, maxDen);
        return units / g + '/' + maxDen / g;
    }

    /** Округление введённой дроби для отображения (формат NN/100). */
    function yvoFpAllocRoundShareStr(str, roundMode) {
        var f = yvoParseShareFractionToFloat(str);
        if (f === null) {
            return str;
        }
        return yvoFpShareAs100ths(f, roundMode || 'up');
    }

    /**
     * Итоговые доли (МСК / поровну): дети — вверх, родители — остаток, сумма ровно 100/100.
     */
    function yvoFpAllocBalanceFinalSharesTo100(skipActive) {
        if (!yvoFpAllocUsesFinalShares()) {
            return;
        }
        var eps = 1e-12;
        var parentIds = yvoFpAllocParentSellerIds();
        var joint = yvoFpAllocIsJointOwnership();
        var usedUnits = 0;

        yvoShareMatrixParticipantIds().forEach(function(tid) {
            var isSeller = /^seller/.test(tid) || /^minor_seller/.test(tid);
            if (joint && parentIds.indexOf(tid) !== -1) {
                if (tid !== parentIds[0]) {
                    yvoFpAllocForceSetShareForTab(tid, '');
                }
                return;
            }
            if (!joint && parentIds.indexOf(tid) !== -1) {
                return;
            }
            if (isSeller) {
                return;
            }
            var f = yvoParseShareFractionToFloat(yvoFpAllocGetShareForTab(tid));
            if (f === null || f <= 0) {
                return;
            }
            var units = Math.ceil(f * 100 - eps);
            if (units > 100) {
                units = 100;
            }
            usedUnits += units;
            yvoFpAllocSetShareForTab(tid, units + '/100', skipActive);
        });

        var rem = Math.max(0, 100 - usedUnits);
        if (joint && parentIds.length) {
            yvoFpAllocSetShareForTab(parentIds[0], rem + '/100', skipActive);
            for (var i = 1; i < parentIds.length; i++) {
                yvoFpAllocForceSetShareForTab(parentIds[i], '');
            }
        } else if (parentIds.length >= 2) {
            parentIds.forEach(function(tid, idx) {
                var u = yvoFpAllocSplitUnitsAmong(rem, idx, parentIds.length);
                yvoFpAllocForceSetShareForTab(tid, u + '/100');
            });
        } else if (parentIds.length === 1) {
            yvoFpAllocSetShareForTab(parentIds[0], rem + '/100', skipActive);
        }
    }

    /** @deprecated — используйте yvoFpAllocBalanceFinalSharesTo100 */
    function yvoFpAllocBalanceParentRemainder(skipActive) {
        yvoFpAllocBalanceFinalSharesTo100(skipActive);
    }

    /** Доля как дробь со знаменателем ≤ 100 (округление процента вверх). */
    function yvoFloatToShareStr(v) {
        if (currentContractType === 'share_allocation') {
            return yvoFpShareFromFloatMax100(v, 'up');
        }
        if (!(v > 0) || v !== v) {
            return '0/1';
        }
        if (v >= 1) {
            return '1/1';
        }
        var cents = Math.round(v * 100);
        if (cents <= 0) {
            return '0/1';
        }
        if (cents >= 100) {
            return '1/1';
        }
        var g = yvoGcd(cents, 100);
        return cents / g + '/' + 100 / g;
    }

    function yvoLcm(a, b) {
        if (!a || !b) {
            return a || b || 0;
        }
        return Math.abs(a * b) / yvoGcd(a, b);
    }

    function yvoLcmArray(arr) {
        if (!arr.length) {
            return 1;
        }
        var r = arr[0];
        for (var i = 1; i < arr.length; i++) {
            r = yvoLcm(r, arr[i]);
        }
        return r;
    }

    /** Дробь a/b в упрощённом виде (только строка «число/число»). */
    function yvoParseShareFractionNd(str) {
        var t = String(str || '')
            .trim()
            .replace(/\s/g, '')
            .replace(',', '.');
        var m = t.match(/^(\d+)\/(\d+)$/);
        if (!m) {
            return null;
        }
        var n = parseInt(m[1], 10);
        var d = parseInt(m[2], 10);
        if (!d) {
            return null;
        }
        var g = yvoGcd(n, d);
        return { n: n / g, d: d / g };
    }

    /** Записать долю в карточку и таблицу (выделение долей). */
    function yvoFpAllocSetShareForTab(tid, val, skipActive) {
        if (!tid) {
            return;
        }
        var $panel = $('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]');
        if ($panel.length && (!skipActive || document.activeElement !== $panel[0])) {
            $panel.val(val);
        }
        var $sum = $('#yvo-fp-share-participants-summary .yvo-fp-share-summary-input[data-tab="' + tid + '"]');
        if ($sum.length && (!skipActive || document.activeElement !== $sum[0])) {
            $sum.val(val);
        }
    }

    /** Запись доли без учёта фокуса (очистка второго родителя и т.п.). */
    function yvoFpAllocForceSetShareForTab(tid, val) {
        if (!tid) {
            return;
        }
        $('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val(val);
        $('#yvo-fp-share-participants-summary .yvo-fp-share-summary-input[data-tab="' + tid + '"]').val(val);
    }

    /** Остаток 1 − N × (n/d) — знаменатель ≤ 100, округление вниз (остаток родителям). */
    function yvoFpAllocRemainderAfterShares(childShareStr, childCount) {
        var nd = yvoParseShareFractionNd(childShareStr);
        if (!nd || childCount < 1) {
            return '';
        }
        var childF = (nd.n * childCount) / nd.d;
        var rem = 1 - childF;
        if (rem <= 0) {
            return '0/1';
        }
        return yvoFpShareAs100ths(rem, 'down');
    }

    /** Разделить долю между родителями; последний получает остаток (≤ 100). */
    function yvoFpAllocSplitShareStr(shareStr, parts, partIndex) {
        var totalF = yvoParseShareFractionToFloat(shareStr);
        if (totalF === null || parts <= 1) {
            return shareStr;
        }
        if (partIndex === parts - 1) {
            var used = 0;
            for (var i = 0; i < parts - 1; i++) {
                var pf = yvoParseShareFractionToFloat(yvoFpAllocSplitShareStr(shareStr, parts, i));
                if (pf !== null) {
                    used += pf;
                }
            }
            return yvoFpShareAs100ths(totalF - used, 'down');
        }
        return yvoFpShareAs100ths(totalF / parts, 'down');
    }

    /** Все доли — единый формат NN/100; итоговые — сумма ровно 100/100. */
    function yvoFpAllocUnifyAllShares(skipActive) {
        if (currentContractType !== 'share_allocation') {
            return null;
        }
        yvoFpAllocConsolidateJointParentShares();
        if (yvoFpAllocUsesFinalShares()) {
            yvoFpAllocBalanceFinalSharesTo100(skipActive);
            return 100;
        }
        var ids = yvoShareMatrixParticipantIds();
        var parentIds = yvoFpAllocParentSellerIds();
        var joint = yvoFpAllocIsJointOwnership();
        ids.forEach(function(tid) {
            var raw = yvoFpAllocGetShareForTab(tid);
            if (!raw) {
                return;
            }
            if (joint && parentIds.indexOf(tid) > 0) {
                yvoFpAllocSetShareForTab(tid, '', skipActive);
                return;
            }
            var isBuyer = /^buyer/.test(tid) || /^minor_buyer/.test(tid);
            yvoFpAllocSetShareForTab(tid, yvoFpAllocRoundShareStr(raw, isBuyer ? 'up' : 'up'), skipActive);
        });
        return 100;
    }

    /** Все доли участников — к одному знаменателю (НОК знаменателей), без дальнейшего сокращения. */
    function yvoUnifyShareFractionDenominators() {
        if (currentContractType === 'share_allocation') {
            yvoFpAllocUnifyAllShares(true);
            return;
        }
        var ids = yvoParticipantTabIdsOrdered();
        var items = [];
        ids.forEach(function(tid) {
            var raw = ($('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val() || '').trim();
            if (!raw) {
                return;
            }
            var nd = yvoParseShareFractionNd(raw);
            if (nd) {
                items.push({ tid: tid, n: nd.n, d: nd.d });
            }
        });
        if (items.length < 2) {
            return;
        }
        var dens = items.map(function(it) {
            return it.d;
        });
        var D = yvoLcmArray(dens);
        if (!D || D > 10000) {
            return;
        }
        items.forEach(function(it) {
            if (D % it.d !== 0) {
                return;
            }
            var nn = it.n * (D / it.d);
            if (nn !== nn || nn < 0 || nn > D) {
                return;
            }
            $('#yvo-fp-panel-' + it.tid).find('[data-key="share_fraction"]').val(nn + '/' + D);
        });
    }

    function yvoParseShareFractionToFloat(str) {
        if (str === undefined || str === null) {
            return null;
        }
        var t = String(str).trim();
        if (!t) {
            return null;
        }
        t = t.replace(/\s/g, '').replace(',', '.');
        var m = t.match(/^(\d+)\s*\/\s*(\d+)$/);
        if (m) {
            var a = parseInt(m[1], 10);
            var b = parseInt(m[2], 10);
            if (!b) {
                return null;
            }
            return a / b;
        }
        var f = parseFloat(t);
        return isNaN(f) ? null : f;
    }

    function yvoParticipantTabIdsOrdered() {
        var ids = [];
        yvoFpParticipantTabButtons().each(function() {
            var id = $(this).attr('data-tab');
            if (id && id !== 'property' && /^(seller|buyer|minor_seller|minor_buyer|contributor)\d*$/.test(id)) {
                ids.push(id);
            }
        });
        return ids;
    }

    /** Все стороны для матрицы долей — по панелям формы (вкладки могут быть скрыты на шаге DOKI «Объект»). */
    function yvoParticipantTabIdsForShareMatrix() {
        function sortKey(tid) {
            var m = String(tid).match(/(\d+)$/);
            return m ? parseInt(m[1], 10) : 1;
        }
        function pushSorted(arr, tid) {
            if (arr.indexOf(tid) >= 0) {
                return;
            }
            arr.push(tid);
            arr.sort(function(a, b) { return sortKey(a) - sortKey(b); });
        }
        var sellerTabs = [];
        var buyerTabs = [];
        $('#yvo-fp-tab-panels > .yvo-fp-panel').each(function() {
            var pid = $(this).attr('id') || '';
            var m = pid.match(/^yvo-fp-panel-(.+)$/);
            if (!m) {
                return;
            }
            var tid = m[1];
            if (tid === 'property' || tid === 'generate') {
                return;
            }
            if (/^seller\d*$/.test(tid) || /^minor_seller/.test(tid)) {
                pushSorted(sellerTabs, tid);
            } else if (/^buyer\d*$/.test(tid) || /^minor_buyer/.test(tid) || /^contributor\d*/.test(tid)) {
                pushSorted(buyerTabs, tid);
            }
        });
        if (sellerTabs.length || buyerTabs.length) {
            return sellerTabs.concat(buyerTabs);
        }
        return yvoParticipantTabIdsOrdered();
    }

    function yvoShareMatrixParticipantIds() {
        return yvoFpShowShareMatrix() ? yvoParticipantTabIdsForShareMatrix() : yvoParticipantTabIdsOrdered();
    }

    function yvoSetShareValidation(msg, isOk) {
        var $el = $('#yvo-fp-share-validation');
        if (!$el.length) {
            return;
        }
        if (!msg) {
            $el.hide().text('');
            return;
        }
        $el.show().text(msg).toggleClass('ok', !!isOk).toggleClass('error', !isOk);
    }

    function yvoApplyEqualShares() {
        if (currentContractType === 'gift') {
            var donorIds = yvoFpGiftDonorTabIds();
            var donees = yvoFpGiftDoneeTabIds();
            if (!donorIds.length || !donees.length) {
                yvoSetShareValidation('Нет дарителей или одаряемых для распределения.', false);
                return;
            }
            var frac = '1/' + donees.length;
            var rows = [];
            donees.forEach(function(donee) {
                rows.push({
                    id: yvoFpGiftDistUid(),
                    donor_tab: donorIds[0],
                    donee_tab: donee,
                    share_fraction: frac
                });
            });
            yvoFpGiftDistSave(rows);
            yvoFpRenderGiftShareUi(true);
            yvoFpSyncPanelsFromGiftDistributions(rows);
            yvoFpRefreshGiftSharePreview();
            yvoSetShareValidation('Поровну между одаряемыми: по ' + frac + '.', true);
            return;
        }
        var ids = yvoShareMatrixParticipantIds();
        var n = ids.length;
        if (n < 1) {
            yvoSetShareValidation('Нет участников для распределения долей.', false);
            return;
        }
        if (currentContractType === 'share_allocation') {
            $('#yvo-fp-share-mode').val('equal');
        }
        var frac = '1/' + n;
        ids.forEach(function(tid) {
            yvoFpAllocSetShareForTab(tid, frac, true);
        });
        yvoFpAllocConsolidateJointParentShares();
        yvoFpAllocUnifyAllShares(true);
        yvoSyncSellersSharesHidden();
        $('#yvo-fp-share-round-note').attr('hidden', 'hidden').text('');
        yvoRefreshShareParticipantsSummary();
        yvoSetShareValidation('Распределено поровну: по ' + frac + ' на каждого.', true);
    }

    function yvoSumShareFractions() {
        var ids = yvoShareMatrixParticipantIds();
        var sum = 0;
        var missing = 0;
        ids.forEach(function(tid) {
            var v = $('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val();
            var f = yvoParseShareFractionToFloat(v);
            if (f === null || f === undefined) {
                missing++;
            } else {
                sum += f;
            }
        });
        return { sum: sum, count: ids.length, missing: missing };
    }

    function yvoSyncSellersSharesHidden() {
        var ids = yvoShareMatrixParticipantIds();
        var parts = [];
        var sellers = [];
        var buyers = [];
        ids.forEach(function(tid, i) {
            var data = collectFormData(tid);
            var sh = (data.share_fraction || '').trim();
            var name = (data.full_name || '').trim();
            var isSeller = /^seller/.test(tid) || /^minor_seller/.test(tid);
            var label = 'Участник ' + (i + 1) + (isSeller ? ' (отчуждает)' : ' (получает)');
            if (name) {
                label += ': ' + name;
            }
            if (sh) {
                label += ' — ' + sh;
            }
            parts.push(label);
            var row = { tab: tid, role: isSeller ? 'seller' : 'buyer', share: sh, full_name: name };
            if (isSeller) {
                sellers.push(row);
            } else {
                buyers.push(row);
            }
        });
        $('#property_sellers_shares').val(parts.join('; '));
    }

    function yvoFpMatCapParseMoney(val) {
        if (val === null || val === undefined || val === '') {
            return 0;
        }
        var n = parseFloat(String(val).replace(/\s/g, '').replace(',', '.'));
        return isNaN(n) ? 0 : n;
    }

    function yvoFpMatCapFormatRub(n) {
        return n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₽';
    }

    /** Округление доли вверх до удобной дроби (знаменатель ≤ maxDen, по умолчанию 100). */
    function yvoFpMatCapRoundShareUp(exact, maxDen) {
        if (!(exact > 0)) {
            return { str: '0/1', float: 0, exact: exact, note: '' };
        }
        if (exact >= 1) {
            return { str: '1/1', float: 1, exact: exact, note: '' };
        }
        maxDen = maxDen || (currentContractType === 'share_allocation' ? yvoFpAllocMaxShareDen() : 1200);
        var eps = 1e-12;
        var best = null;

        /** Дробь вида 1/N — основной способ на calcus.ru (1/60, 1/38 …). */
        var dUnit = Math.min(maxDen, Math.max(2, Math.floor(1 / exact)));
        while (dUnit >= 2 && (1 / dUnit) + eps < exact) {
            dUnit--;
        }
        while (dUnit < maxDen && (1 / (dUnit + 1)) - eps >= exact) {
            dUnit++;
        }
        if (dUnit >= 2 && dUnit <= maxDen) {
            best = { n: 1, d: dUnit, str: '1/' + dUnit, float: 1 / dUnit };
        } else {
            var preferred = [10, 12, 15, 16, 18, 20, 24, 25, 30, 32, 36, 38, 40, 45, 48, 50, 60, 72, 75, 80, 90, 100];
            function tryDen(d) {
                if (d > maxDen) {
                    return null;
                }
                var n = Math.ceil(exact * d - eps);
                if (n < 1) {
                    n = 1;
                }
                if (n > d) {
                    return null;
                }
                var g = yvoGcd(n, d);
                n /= g;
                d /= g;
                var f = n / d;
                if (f + eps < exact) {
                    return null;
                }
                return { n: n, d: d, str: n + '/' + d, float: f };
            }
            preferred.forEach(function(d) {
                var r = tryDen(d);
                if (r && (!best || r.float < best.float - eps)) {
                    best = r;
                }
            });
            if (!best) {
                for (var d2 = 2; d2 <= maxDen; d2++) {
                    var r2 = tryDen(d2);
                    if (r2 && (!best || r2.float < best.float - eps)) {
                        best = r2;
                    }
                }
            }
        }
        if (!best) {
            var fb = yvoFpShareAs100ths(exact, 'up');
            var ff = yvoParseShareFractionToFloat(fb);
            return { str: fb, float: ff !== null ? ff : exact, exact: exact, note: '' };
        }
        var note = '';
        if (Math.abs(best.float - exact) > 1e-6) {
            note = 'Точная доля ' + (exact * 100).toFixed(3).replace('.', ',') + '%, округлено вверх до ' + best.str + ' (' + (best.float * 100).toFixed(2).replace('.', ',') + '%).';
        }
        return { str: best.str, float: best.float, exact: exact, note: note };
    }

    function yvoFpMatCapParticipantCounts() {
        return {
            sellerIds: yvoFpAllocTransferorTabIds(),
            buyerIds: yvoFpAllocReceiverTabIds()
        };
    }

    function yvoFpMatCapDefaultFamilyMembers() {
        var p = yvoFpMatCapParticipantCounts();
        var n = p.sellerIds.length + p.buyerIds.length;
        return n >= 2 ? n : 0;
    }

    function yvoFpMatCapSyncDefaultsFromProperty() {
        if (currentContractType !== 'share_allocation') {
            return;
        }
        var $price = $('#property_purchase_price_shares');
        if ($price.length && !String($price.val() || '').trim()) {
            var propPrice = ($('#yvo-fp-panel-property .yvo-fp-object-type-block.active [data-key="price"]').val() || '').trim();
            if (propPrice) {
                $price.val(propPrice);
            }
        }
        var $area = $('#property_share_calc_area');
        if ($area.length && !String($area.val() || '').trim()) {
            var propArea = ($('#yvo-fp-panel-property .yvo-fp-object-type-block.active [data-key="area"]').val() || '').trim();
            if (propArea) {
                $area.val(propArea);
            }
        }
        var $fam = $('#property_share_family_members');
        if ($fam.length && !$fam.attr('data-user-set')) {
            var def = yvoFpMatCapDefaultFamilyMembers();
            if (def >= 2) {
                $fam.val(def);
            }
        }
    }

    /**
     * Расчёт долей по МСК (формула calcus.ru / law-treaty.ru).
     * @return {object|null}
     */
    function yvoFpMatCapCompute() {
        var cap = yvoFpMatCapParseMoney($('#property_maternity_capital_rub').val());
        var price = yvoFpMatCapParseMoney($('#property_purchase_price_shares').val());
        var area = yvoFpMatCapParseMoney($('#property_share_calc_area').val());
        var members = parseInt($('#property_share_family_members').val(), 10) || 0;
        if (members < 2) {
            members = yvoFpMatCapDefaultFamilyMembers();
        }
        var parts = yvoFpMatCapParticipantCounts();
        var sellerIds = parts.sellerIds;
        var buyerIds = parts.buyerIds;
        if (cap <= 0 || price <= 0) {
            return { ok: false, msg: 'Укажите стоимость объекта и сумму материнского капитала (положительные числа).' };
        }
        if (members < 2) {
            return { ok: false, msg: 'Укажите число членов семьи (не менее 2) или добавьте участников во вкладках.' };
        }
        if (buyerIds.length < 1) {
            return { ok: false, msg: 'Добавьте получающих долю (детей) — вкладки «участник (получает)».' };
        }
        if (sellerIds.length < 1) {
            return { ok: false, msg: 'Добавьте отчуждающих (родителей) — вкладки «участник (отчуждает)».' };
        }
        var exactMin = cap / price / members;
        var rounded = yvoFpMatCapRoundShareUp(exactMin);
        var childShare = rounded.float;
        var totalChildren = buyerIds.length * childShare;
        if (totalChildren >= 1 - 1e-9) {
            return {
                ok: false,
                msg: 'После округления доли детей сумма ≥ 100%. Увеличьте стоимость объекта или уменьшите сумму МСК / число детей.'
            };
        }
        var remainder = 1 - totalChildren;
        var jointParents = yvoFpAllocIsJointOwnership();
        var parentUnits = (jointParents && sellerIds.length >= 2) ? 1 : sellerIds.length;
        var perParentUnit = sellerIds.length > 0 ? remainder / parentUnits : 0;
        var distribution = [];
        buyerIds.forEach(function(tid) {
            distribution.push({
                tab: tid,
                role: 'buyer',
                name: yvoFpGiftParticipantName(tid),
                shareStr: rounded.str,
                shareFloat: childShare,
                rub: price * childShare,
                sqm: area > 0 ? area * childShare : null
            });
        });
        if (jointParents && sellerIds.length >= 2) {
            var jointStr = yvoFpShareAs100ths(remainder, 'down');
            distribution.push({
                tab: sellerIds[0],
                jointTabs: sellerIds.slice(),
                role: 'seller',
                joint: true,
                name: yvoFpAllocJointParentLabel(),
                shareStr: jointStr,
                shareFloat: remainder,
                rub: price * remainder,
                sqm: area > 0 ? area * remainder : null
            });
        } else {
            var parentTotalStr = yvoFpAllocRemainderAfterShares(rounded.str, buyerIds.length);
            sellerIds.forEach(function(tid, idx) {
                var pStr = yvoFpAllocSplitShareStr(parentTotalStr, sellerIds.length, idx);
                var pf = yvoParseShareFractionToFloat(pStr);
                distribution.push({
                    tab: tid,
                    role: 'seller',
                    name: yvoFpGiftParticipantName(tid),
                    shareStr: pStr,
                    shareFloat: pf !== null ? pf : 0,
                    rub: price * (pf !== null ? pf : 0),
                    sqm: area > 0 && pf !== null ? area * pf : null
                });
            });
        }
        return {
            ok: true,
            cap: cap,
            price: price,
            area: area,
            members: members,
            exactMin: exactMin,
            minShare: rounded,
            childShareStr: rounded.str,
            childShareFloat: childShare,
            childSqm: area > 0 ? area * childShare : null,
            childRub: price * childShare,
            remainder: remainder,
            jointParents: jointParents,
            distribution: distribution,
            sellerIds: sellerIds,
            buyerIds: buyerIds
        };
    }

    function yvoFpMatCapRenderResults(calc) {
        var $box = $('#yvo-fp-mat-calc-results');
        if (!$box.length) {
            return;
        }
        $box.empty();
        if (!calc || !calc.ok) {
            if (calc && calc.msg) {
                $box.removeAttr('hidden').append($('<p class="yvo-fp-mat-calc-results__error" />').text(calc.msg));
            } else {
                $box.attr('hidden', 'hidden');
            }
            return;
        }
        $box.removeAttr('hidden');
        var $wrap = $('<div class="yvo-fp-mat-calc-results__inner" />');
        var $hero = $('<div class="yvo-fp-mat-calc-results__hero" />');
        $hero.append(
            $('<div class="yvo-fp-mat-calc-results__metric" />')
                .append($('<span class="yvo-fp-mat-calc-results__metric-label" />').text('Минимальная доля ребёнка'))
                .append($('<span class="yvo-fp-mat-calc-results__metric-value" />').text(calc.childShareStr))
        );
        if (calc.childSqm !== null && calc.childSqm > 0) {
            $hero.append(
                $('<div class="yvo-fp-mat-calc-results__metric" />')
                    .append($('<span class="yvo-fp-mat-calc-results__metric-label" />').text('Площадь, соответствующая доле'))
                    .append($('<span class="yvo-fp-mat-calc-results__metric-value" />').text(
                        calc.childSqm.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' м²'
                    ))
            );
        }
        $wrap.append($hero);
        var exactSqm = calc.area > 0 ? calc.exactMin * calc.area : null;
        var noteParts = ['Доля округлена в большую сторону для получения более удобного дробного значения.'];
        noteParts.push(
            'Точная доля составляет ' + calc.exactMin.toFixed(3).replace('.', ',')
            + (exactSqm !== null ? ', что соответствует площади ' + exactSqm.toFixed(2).replace('.', ',') + ' м²' : '')
            + '.'
        );
        $wrap.append($('<p class="yvo-fp-mat-calc-results__note" />').text(noteParts.join(' ')));
        if (calc.jointParents) {
            $wrap.append($('<p class="yvo-fp-mat-calc-results__note" />').text('Родители указаны одной долей — совместная собственность супругов.'));
        }
        $wrap.append($('<p class="yvo-fp-mat-calc-results__dist-title" />').text('Итоговые доли в квартире после выделения'));
        var $list = $('<ul class="yvo-fp-mat-calc-results__list" />');
        calc.distribution.forEach(function(row) {
            var extra = row.sqm !== null && row.sqm > 0
                ? ' (' + row.sqm.toLocaleString('ru-RU', { maximumFractionDigits: 2 }) + ' м², ' + yvoFpMatCapFormatRub(row.rub) + ')'
                : ' (' + yvoFpMatCapFormatRub(row.rub) + ')';
            $list.append($('<li/>').text(row.name + ' — ' + row.shareStr + extra + (row.joint ? ' — совместная собственность супругов' : '')));
        });
        $wrap.append($list);
        $box.append($wrap);
    }

    var yvoFpMatCapCalcTimer = null;
    function yvoFpMatCapSchedulePreview() {
        if (currentContractType !== 'share_allocation') {
            return;
        }
        clearTimeout(yvoFpMatCapCalcTimer);
        yvoFpMatCapCalcTimer = setTimeout(function() {
            yvoFpMatCapRenderResults(yvoFpMatCapCompute());
        }, 280);
    }

    function yvoApplyMatCapitalShares() {
        $('#yvo-fp-share-round-note').attr('hidden', 'hidden').text('');
        yvoFpMatCapSyncDefaultsFromProperty();
        var calc = yvoFpMatCapCompute();
        yvoFpMatCapRenderResults(calc);
        if (!calc.ok) {
            yvoSetShareValidation(calc.msg, false);
            return;
        }
        yvoFpAllocApplyMatCapFromCompute(calc);
        yvoSyncSellersSharesHidden();
        yvoFpRenderAllocShareUi(true);
        yvoFpRefreshAllocSharePreview();
        var chkSum = yvoFpAllocMergedFinalShareSum();
        var ok = Math.abs(chkSum - 1) < 0.002;
        var parentShareStr = yvoFpAllocGetShareForTab(calc.jointParents ? calc.sellerIds[0] : calc.sellerIds[0]) || yvoFpShareAs100ths(calc.remainder, 'down');
        var parentMsg = calc.jointParents
            ? 'Родители (совместная собственность) — ' + parentShareStr + '; '
            : 'родителям — остаток ' + parentShareStr + '; ';
        yvoSetShareValidation(
            ok
                ? 'Записано: дети по ' + calc.childShareStr + ' каждый; ' + parentMsg
                + (calc.minShare.note ? calc.minShare.note : '')
                : 'Проверьте доли: сумма ' + (chkSum * 100).toFixed(2) + '%.',
            ok
        );
    }

    function yvoRelabelShareAllocationParticipantTabs() {
        var si = 0;
        var bi = 0;
        yvoFpParticipantTabButtons().each(function() {
            var id = $(this).attr('data-tab');
            if (!id || id === 'property' || !/^(seller|buyer|minor_seller|minor_buyer|contributor)\d*$/.test(id)) {
                return;
            }
            if (id.indexOf('seller') >= 0) {
                si++;
                $(this).text('Участник ' + si + ' (отчуждает)');
            } else {
                bi++;
                $(this).text('Участник ' + bi + ' (получает)');
            }
        });
    }

    function yvoUpdateTabLabelsForContractType() {
        var ct = currentContractType;
        if (ct === 'share_allocation') {
            yvoRelabelShareAllocationParticipantTabs();
        } else {
            yvoFpParticipantTabButtons().each(function() {
                var t = $(this).attr('data-tab');
                var label = yvoTabLabelForContractType(t, ct);
                if (label) {
                    $(this).text(label);
                }
            });
        }
        refreshAddParticipantMenu();
        yvoToggleShareAllocationUi();
        yvoSyncDokiVisibleChromeForContractType();
    }
    window.yvoUpdateTabLabelsForContractType = yvoUpdateTabLabelsForContractType;
    window.yvoTabLabelForContractType = yvoTabLabelForContractType;
    window.yvoToggleShareAllocationUi = yvoToggleShareAllocationUi;
    window.yvoFpShowShareMatrix = yvoFpShowShareMatrix;

    $(document).on('change', '#yvo-fp-share-mode', function() {
        yvoUpdateShareModeVisibility();
        if (($(this).val() || '') === 'equal') {
            yvoApplyEqualShares();
        }
    });
    $(document).on('click', '#yvo-fp-share-equal-btn, #yvo-fp-share-equal-btn-gift', function(e) {
        e.preventDefault();
        if (currentContractType === 'share_allocation') {
            $('#yvo-fp-share-mode').val('equal');
        }
        yvoApplyEqualShares();
    });
    $(document).on('click', '#yvo-fp-share-mat-calc-btn', function(e) {
        e.preventDefault();
        yvoApplyMatCapitalShares();
    });
    $(document).on('click', '#yvo-fp-mat-calc-clear', function(e) {
        e.preventDefault();
        $('#property_purchase_price_shares, #property_maternity_capital_rub, #property_share_calc_area').val('');
        $('#property_share_family_members').removeAttr('data-user-set').val('');
        $('#yvo-fp-mat-capital-preset').val('custom');
        $('#yvo-fp-mat-calc-results').attr('hidden', 'hidden').empty();
        yvoSetShareValidation('', false);
    });
    $(document).on('change', '#yvo-fp-mat-capital-preset', function() {
        var v = $(this).val() || 'custom';
        if (v !== 'custom') {
            $('#property_maternity_capital_rub').val(v);
        }
        yvoFpMatCapSchedulePreview();
    });
    $(document).on('input change', '#property_maternity_capital_rub, #property_purchase_price_shares, #property_share_family_members, #property_share_calc_area', function() {
        if ($(this).attr('id') === 'property_share_family_members') {
            $(this).attr('data-user-set', '1');
        }
        yvoFpMatCapSchedulePreview();
    });
    $(document).on('input change blur', '#yvo-fp-panel-property [data-key="price"], #yvo-fp-panel-property [data-key="area"]', function() {
        if (currentContractType !== 'share_allocation') {
            return;
        }
        var key = $(this).data('key');
        if (key === 'price' && !String($('#property_purchase_price_shares').val() || '').trim()) {
            $('#property_purchase_price_shares').val($(this).val());
        }
        if (key === 'area' && !String($('#property_share_calc_area').val() || '').trim()) {
            $('#property_share_calc_area').val($(this).val());
        }
        yvoFpMatCapSchedulePreview();
    });
    $(document).on('change blur', '#yvo-fp-share-participants-summary .yvo-fp-gift-frac-num, #yvo-fp-share-participants-summary .yvo-fp-gift-frac-den', function() {
        if (yvoFpGiftShareSyncLock) {
            return;
        }
        var $row = $(this).closest('tr.yvo-fp-gift-dist-tr');
        if ($row.length) {
            yvoFpGiftDistApplySlashToRow($row);
        }
        yvoFpGiftDistOnChange();
    });
    $(document).on('paste', '#yvo-fp-share-participants-summary .yvo-fp-gift-frac-num, #yvo-fp-share-participants-summary .yvo-fp-gift-frac-den', function(e) {
        var clip = (e.originalEvent && e.originalEvent.clipboardData)
            ? e.originalEvent.clipboardData.getData('text')
            : ((window.clipboardData && window.clipboardData.getData) ? window.clipboardData.getData('Text') : '');
        var parts = yvoFpParseShareFracParts(clip);
        if (!parts.num || !parts.den) {
            return;
        }
        e.preventDefault();
        var $row = $(this).closest('tr.yvo-fp-gift-dist-tr');
        $row.find('.yvo-fp-gift-frac-num').val(parts.num);
        $row.find('.yvo-fp-gift-frac-den').val(parts.den);
        yvoFpGiftDistOnChange();
    });
    $(document).on('change', '#yvo-fp-share-participants-summary .yvo-fp-gift-donor-select', function() {
        yvoFpGiftDistOnChange();
    });
    $(document).on('input', '#yvo-fp-tab-panels [data-key="share_fraction"]', function() {
        if (!yvoFpShowShareMatrix() || yvoFpGiftShareSyncLock) {
            return;
        }
        yvoSyncSellersSharesHidden();
        var $panel = $(this).closest('.yvo-fp-panel');
        var pid = $panel.attr('id') || '';
        if (pid.indexOf('yvo-fp-panel-') === 0) {
            var tid = pid.slice('yvo-fp-panel-'.length);
            if (currentContractType === 'gift') {
                yvoFpGiftDistSyncFromParticipantShares();
                if (!yvoFpGiftShareSyncLock) {
                    yvoFpRefreshGiftSharePreview();
                }
                return;
            }
            if (currentContractType === 'share_allocation') {
                $('#yvo-fp-share-participants-summary .yvo-fp-share-summary-input[data-tab="' + tid + '"]').val($(this).val());
                yvoSyncSellersSharesHidden();
                if ($('#yvo-fp-share-mode').val() === 'mat_capital' || $('#yvo-fp-share-mode').val() === 'equal') {
                    yvoFpAllocEnterCustomShareMode();
                }
                yvoFpRefreshAllocSharePreview({ skipUnify: true });
                return;
            }
            $('#yvo-fp-share-participants-summary .yvo-fp-share-summary-input[data-tab="' + tid + '"]').val($(this).val());
            yvoSyncShareInRightFromParticipant(tid, $(this).val());
        }
        yvoRefreshShareParticipantsSummary();
    });
    $(document).on('change blur', '#yvo-fp-tab-panels [data-key="share_fraction"]', function() {
        if (!yvoFpShowShareMatrix() || yvoFpGiftShareSyncLock) {
            return;
        }
        yvoSyncSellersSharesHidden();
        var $panel = $(this).closest('.yvo-fp-panel');
        var pid = $panel.attr('id') || '';
        if (pid.indexOf('yvo-fp-panel-') === 0) {
            var tid = pid.slice('yvo-fp-panel-'.length);
            if (currentContractType === 'gift') {
                yvoFpGiftDistSyncFromParticipantShares();
                if (!yvoFpGiftShareSyncLock) {
                    yvoFpRefreshGiftSharePreview();
                }
                return;
            }
            if (currentContractType === 'share_allocation') {
                $('#yvo-fp-share-participants-summary .yvo-fp-share-summary-input[data-tab="' + tid + '"]').val($(this).val());
                yvoSyncSellersSharesHidden();
                if ($('#yvo-fp-share-mode').val() === 'mat_capital' || $('#yvo-fp-share-mode').val() === 'equal') {
                    yvoFpAllocEnterCustomShareMode();
                }
                yvoFpRefreshAllocSharePreview();
                return;
            }
            $('#yvo-fp-share-participants-summary .yvo-fp-share-summary-input[data-tab="' + tid + '"]').val($(this).val());
            yvoSyncShareInRightFromParticipant(tid, $(this).val());
        }
        yvoRefreshShareParticipantsSummary();
    });
    $(document).on('input', '#yvo-fp-share-participants-summary .yvo-fp-share-summary-input', function() {
        if (!yvoFpShowShareMatrix() || currentContractType === 'gift') {
            return;
        }
        var tid = $(this).attr('data-tab');
        if (!tid) {
            return;
        }
        var v = $(this).val();
        $('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val(v);
        yvoSyncSellersSharesHidden();
        if (currentContractType === 'share_allocation') {
            if ($('#yvo-fp-share-mode').val() === 'mat_capital') {
                $('#yvo-fp-share-mode').val('custom');
            }
            yvoFpRefreshAllocSharePreview({ skipUnify: true });
            return;
        }
        yvoSyncShareInRightFromParticipant(tid, v);
        yvoRefreshShareParticipantsSummary();
    });
    $(document).on('change blur', '#yvo-fp-share-participants-summary .yvo-fp-share-summary-input', function() {
        if (!yvoFpShowShareMatrix() || currentContractType === 'gift') {
            return;
        }
        var tid = $(this).attr('data-tab');
        if (!tid) {
            return;
        }
        var v = $(this).val();
        $('#yvo-fp-panel-' + tid).find('[data-key="share_fraction"]').val(v);
        yvoSyncSellersSharesHidden();
        if (currentContractType === 'share_allocation') {
            if ($('#yvo-fp-share-mode').val() === 'mat_capital') {
                $('#yvo-fp-share-mode').val('custom');
            }
            yvoFpRefreshAllocSharePreview();
            return;
        }
        yvoSyncShareInRightFromParticipant(tid, v);
        yvoRefreshShareParticipantsSummary();
    });
    $(document).on('input change blur', '#yvo-fp-tab-panels [data-key="full_name"]', function() {
        if (yvoFpShowShareMatrix()) {
            yvoRefreshShareParticipantsSummary();
            yvoSyncSellersSharesHidden();
        }
    });
    $(document).on('input change blur', '#yvo-fp-panel-property [data-key="share_in_right"]', function() {
        if (yvoFpShowShareMatrix()) {
            yvoSyncParticipantShareFromShareInRight();
            yvoSyncSellersSharesHidden();
            yvoRefreshShareParticipantsSummary();
        }
        yvoFpSyncGiftTemplateForObjectType();
    });
    $(document).on('yvo-doki-participant-added', function() {
        if (currentContractType === 'share_allocation') {
            yvoRelabelShareAllocationParticipantTabs();
            refreshAddParticipantMenu();
            yvoFpAllocRedistributeAfterParticipantsChanged();
        } else {
            yvoUpdateDefaultSharesForContract();
            if (yvoFpShowShareMatrix()) {
                if (currentContractType === 'gift') {
                    yvoFpRenderGiftShareUi(true);
                } else {
                    yvoRefreshShareParticipantsSummary();
                }
                yvoSyncSellersSharesHidden();
            }
        }
    });

    $(document).on('change', '#property_share_joint_ownership', function() {
        $(this).attr('data-user-set', '1');
        yvoFpAllocOnJointOwnershipChanged();
    });
    $(document).on('change', '#property_joint_ownership', function() {
        yvoUpdateDefaultSharesForContract();
        yvoSyncSellersSharesHidden();
    });

    function yvoFpSyncDokiAddMenuForStep() {
        if (!$('.yvo-doki-form-skin').length) return;
        var step = (typeof window.yvoDokiGetCurrentStepIndex === 'function') ? window.yvoDokiGetCurrentStepIndex() : 0;
        var showContributor = (currentContractType === 'deposit_agreement' || currentContractType === 'advance_agreement');
        $('#yvoDokiAddParticipantPanel [data-add], #yvo-fp-add-participant-menu [data-add]').each(function() {
            var role = $(this).attr('data-add');
            var show = true;
            if (step === 0) {
                show = (role === 'seller' || role === 'seller_representative' || role === 'minor_seller' || role === 'guardian_seller');
            } else if (step === 1) {
                show = (role === 'buyer' || role === 'buyer_representative' || role === 'minor_buyer' || role === 'guardian_buyer');
                if (showContributor && role === 'contributor') show = true;
            } else {
                show = false;
            }
            if (role === 'contributor' && !showContributor) show = false;
            if ((role === 'guardian_seller' || role === 'guardian_buyer') && currentContractType === 'share_allocation') show = false;
            $(this).toggle(show);
        });
    }

    function refreshAddParticipantMenu() {
        yvoFpUpgradeMinorAddMenu();
        yvoFpSyncDokiAddMenuForStep();
        var showContributor = (currentContractType === 'deposit_agreement' || currentContractType === 'advance_agreement');
        $('.yvo-fp-add-contributor').toggle(showContributor);
        var role, next, $btn, cfg;
        for (role in ADD_ROLES) {
            if (!ADD_ROLES.hasOwnProperty(role)) continue;
            cfg = ADD_ROLES[role];
            if (role === 'minor_seller' || role === 'minor_buyer') {
            next = getNextForRole(role);
                var side = role === 'minor_seller' ? 'seller' : 'buyer';
                var pairs = [
                    { clsSuffix: 'u14', mag: 'u14', lab: 'до 14 лет' },
                    { clsSuffix: 'a18', mag: 'a14_18', lab: 'от 14 лет' }
                ];
                pairs.forEach(function(pr) {
                    var cls = '.yvo-fp-add-minor-' + side + '-' + pr.clsSuffix;
                    var $bs = $('.yvo-fp-add-participant-menu ' + cls + ', #yvoDokiAddParticipantPanel ' + cls);
            if (next) {
                        $bs.text(next.label + ' (' + pr.lab + ')').prop('disabled', false).show();
                    } else {
                        $bs.text(cfg.label1 + ' (' + pr.lab + ') (макс. ' + MAX_PER_TYPE + ')').prop('disabled', true).show();
                    }
                });
                continue;
            }
            if (!cfg.selector) continue;
            next = getNextForRole(role);
            $btn = $('.yvo-fp-add-participant-menu ' + cfg.selector + ', #yvoDokiAddParticipantPanel ' + cfg.selector);
            if (role === 'guardian_seller' || role === 'guardian_buyer') {
                if (currentContractType === 'share_allocation') {
                    $btn.hide();
                    continue;
                }
                if (currentContractType === 'gift') {
                    cfg = $.extend({}, cfg, {
                        label1: role === 'guardian_seller' ? 'Опекун (даритель)' : 'Опекун (одаряемый)'
                    });
                } else if (yvoFpIsDepositOrAdvance() && role === 'guardian_seller') {
                    cfg = $.extend({}, cfg, {
                        label1: yvoFpGuardianSellerLabelBase()
                    });
                } else if (yvoFpIsDepositOrAdvance() && role === 'guardian_buyer') {
                    cfg = $.extend({}, cfg, {
                        label1: yvoFpGuardianBuyerLabelBase()
                    });
                }
            }
            if (next) {
                if (currentContractType === 'share_allocation' && (role === 'seller' || role === 'buyer')) {
                    $btn.text((role === 'seller' ? 'Добавить отчуждающего: ' : 'Добавить получающего долю: ') + next.label).prop('disabled', false).show();
                } else if (role === 'guardian_seller' || role === 'guardian_buyer') {
                    $btn.text('Добавить: ' + (next.label || cfg.label1)).prop('disabled', false).show();
                } else {
                    $btn.text(next.label).prop('disabled', false).show();
                }
            } else {
                $btn.text(cfg.label1 + ' (макс. ' + MAX_PER_TYPE + ')').prop('disabled', true).show();
            }
        }
    }
    window.yvoFpRefreshAddParticipantMenu = refreshAddParticipantMenu;

    $('#yvo-fp-add-participant-btn').on('click', function(e) {
        e.stopPropagation();
        refreshAddParticipantMenu();
        $('#yvo-fp-add-participant-dropdown').toggleClass('open');
        $('#yvo-fp-autofill-dropdown').removeClass('open');
        if (!$('#yvo-fp-add-participant-dropdown').hasClass('open')) {
            $('#yvo-fp-quick-actions').removeClass('yvo-doki-quick-actions-visible');
        }
    });
    // Маппинг типа недвижимости из парсера (property_type) в значение формы (object_type)
    function mapPropertyTypeToObjectType(propertyType) {
        if (!propertyType) return 'apartment';
        var s = String(propertyType).toLowerCase();
        if (/комната|room/.test(s)) return 'room';
        if (/дол[яи]\s|долев|share/.test(s)) return 'share';
        if (/участок|земл|земельн|снт|днт|земля/.test(s)) return 'land';
        if (/дом|дача|таунхаус|коттедж|жилой дом|садовый/.test(s)) return 'house_with_plot';
        if (/гараж/.test(s)) return 'garage';
        if (/паркинг|машино-место|машиноместо/.test(s)) return 'parking';
        return 'apartment'; // квартира, апартаменты, студия и т.д.
    }

    /** Не сбрасывать «Доля» на «Квартира», если в выписке указана квартира, а пользователь выбрал тип «Доля». */
    function yvoFpResolvePropertyObjectType(data, opts) {
        opts = opts || {};
        var current = ($('#property_object_type').val() || 'apartment').trim();
        var parsed = '';
        if (data && typeof data === 'object') {
            if (data.object_type) {
                parsed = String(data.object_type).trim();
            } else if (data.property_type) {
                parsed = mapPropertyTypeToObjectType(data.property_type);
            }
            if (!parsed && data.ownership_type && /долев/i.test(String(data.ownership_type))) {
                parsed = 'share';
            }
        }
        if (parsed === 'share') {
            return 'share';
        }
        if (opts.preserveUiSelection && current && current !== 'apartment') {
            return current;
        }
        if (parsed && parsed !== 'apartment') {
            return parsed;
        }
        return current || 'apartment';
    }

    function yvoFpApplyPropertyObjectType(type) {
            $('#property_object_type').val(type);
            $('#yvo-fp-object-type-label').text(objectTypeLabels[type] || type);
            $('.yvo-fp-object-type-block').removeClass('active');
            $('.yvo-fp-object-type-block[data-object-type="' + type + '"]').addClass('active');
        yvoSyncDokiObjectTypePills();
        yvoToggleShareAllocationUi();
        yvoFpSyncGiftTemplateForObjectType();
        yvoFpSyncDepositTemplateForObjectType();
        yvoFpSyncAdvanceTemplateForObjectType();
    }

    function fillForm(role, data) {
        if (role !== 'property' && data) {
            data = yvoFpPrepareParsedForForm(data);
        }
        if (role !== 'property' && !yvoFpDraftRestoring) {
            role = yvoFpResolveParticipantPanelTab(role);
        }
        var $panel = $('#yvo-fp-panel-' + role);
        if (!$panel.length) {
            if (!yvoFpDraftRestoring) {
                showError('Не найдена форма участника («' + role + '»). Переключите вкладку или добавьте участника.');
            }
            return;
        }
        if (role === 'property') {
            var type = yvoFpResolvePropertyObjectType(data, { preserveUiSelection: !yvoFpDraftRestoring });
            yvoFpApplyPropertyObjectType(type);
        }
        var $scope = (role === 'property') ? $panel.find('.yvo-fp-object-type-block.active, .yvo-fp-price-section') : $panel;
        $scope.find('[data-key]').each(function() {
            var key = $(this).data('key');
            var val = data[key];
            if (key === 'rooms' && (val === 0 || val === '0')) {
                return;
            }
            if (val !== undefined && val !== null && val !== '') {
                $(this).val(String(val).trim());
            }
        });
        // Если для квартиры/комнаты пришли детальные поля адреса — показываем их
        if (role === 'property') {
            var showApartment = !!(data.city || data.street || data.house || data.building || data.apartment);
            if (!showApartment && data.address) {
                yvoFpFillAddressFromFullInActiveBlock();
                showApartment = true;
            }
            if (showApartment && ($('#property_object_type').val() === 'apartment')) setAddressDetailsVisible('apartment', true);
            if (showApartment && ($('#property_object_type').val() === 'room')) setAddressDetailsVisible('room', true);
            if (showApartment && ($('#property_object_type').val() === 'share')) setAddressDetailsVisible('share', true);
            try {
                if (yvoFpShowShareMatrix()) {
                    yvoSyncParticipantShareFromShareInRight();
                    yvoRefreshShareParticipantsSummary();
                    yvoSyncSellersSharesHidden();
                }
            } catch (eMatrixFill) {}
        }
        // Для объекта: показываем блок «свои средства», если пришла цена (чтобы поле цены было видно)
        if (role === 'property' && data.price && $('#property_payment_type').val() === 'mortgage') {
            $('#property_payment_type').val('cash').trigger('change');
        }
        updateFilledClass($panel);
        if (role === 'property') {
            YVO_FP_REG_SYNC_KEYS.forEach(function(k) {
                if (data[k]) {
                    yvoFpRegSyncLock = true;
                    $('.yvo-fp-reg-sync[data-key="' + k + '"]').val(String(data[k]).trim());
                    yvoFpRegSyncLock = false;
                }
            });
            yvoFpUpdateRegFieldsMissingState();
        }

        // После автозаполнения из кабинета/парсера обновляем доли для обычных договоров
        // (чтобы при появлении 2+ продавцов/покупателей сразу ставилось 1/N и поле «Доля в праве» показывалось).
        try {
            if (currentContractType !== 'share_allocation') {
                yvoUpdateDefaultSharesForContract();
            }
        } catch (eShareDefault) {}
        if (/^minor_(seller|buyer)\d*$/.test(String(role))) {
            yvoFpApplyMinorDocVisibility($panel);
        }
    }

    function updateFilledClass($scope) {
        var $root = $scope && $scope.length ? $scope : $('#yvo-fp-forms-section');
        if (!$root.length) return;
        $root.find('.yvo-fp-field input, .yvo-fp-field textarea, .yvo-fp-field select').each(function() {
            var $el = $(this);
            var v = $el.val();
            if (v !== undefined && v !== null && String(v).trim() !== '') {
                $el.addClass('yvo-filled');
            } else {
                $el.removeClass('yvo-filled');
            }
        });
    }

    // ——— Вкладки (делегирование для динамически добавленных участников) ———
    /**
     * Единое переключение вкладки участника + панели (и синхронизация шагов ДОКИ).
     * @param {string} tabId
     * @param {{skipDokiStepSync?:boolean}} opts
     */
    function yvoFpActivateParticipantTab(tabId, opts) {
        opts = opts || {};
        if (!tabId) {
            return false;
        }
        tabId = yvoFpResolveParticipantPanelTab(String(tabId));
        var $panel = $('#yvo-fp-panel-' + tabId);
        if (!$panel.length) {
            if (tabId === 'seller' && typeof yvoFpFirstSellerSideTab === 'function') {
                var altS = yvoFpFirstSellerSideTab(null);
                if (altS && altS !== tabId) {
                    return yvoFpActivateParticipantTab(altS, opts);
                }
            }
            if (tabId === 'buyer' && typeof yvoFpFirstBuyerSideTab === 'function') {
                var altB = yvoFpFirstBuyerSideTab(null);
                if (altB && altB !== tabId) {
                    return yvoFpActivateParticipantTab(altB, opts);
                }
            }
            return false;
        }
        var $tab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + tabId + '"]');
        yvoFpParticipantTabButtons().removeClass('active');
        yvoFpAllParticipantPanels().removeClass('active');
        if ($tab.length) {
            $tab.addClass('active');
        }
        $panel.addClass('active');
        if (/^minor_(seller|buyer)\d*$/.test(tabId)) {
            yvoFpNormalizeMinorPanel($panel);
        }
        updateDeleteButtonVisibility();
        if (typeof yvoFpUpdateApplySameGuardianButton === 'function') {
            yvoFpUpdateApplySameGuardianButton();
        }
        if ($('.yvo-doki-form-skin').length && !opts.skipDokiStepSync) {
            var step = -1;
            if (typeof window.yvoDokiTabToStepIndex === 'function') {
                step = window.yvoDokiTabToStepIndex(tabId);
            } else if (/^(buyer|minor_buyer|guardian_buyer|contributor|buyer_representative)/.test(tabId)) {
                step = 1;
            } else if (/^(seller|minor_seller|guardian_seller|seller_representative)/.test(tabId)) {
                step = 0;
            } else if (tabId === 'property') {
                step = 2;
            }
            if (step >= 0 && step <= 2) {
                if (typeof window.yvoDokiSyncStepChrome === 'function') {
                    window.yvoDokiSyncStepChrome(step, tabId);
                }
            }
        }
        if (typeof window.yvoDokiUpdateParticipantCardTitle === 'function') {
            window.yvoDokiUpdateParticipantCardTitle();
        }
        return true;
    }
    window.yvoFpActivateParticipantTab = yvoFpActivateParticipantTab;

    function getActiveTab() {
        var $direct = $('#yvo-fp-participant-tabs .yvo-fp-tab.active').first();
        if ($direct.length) {
            var td = $direct.attr('data-tab');
            return td !== undefined && td !== null && String(td) !== '' ? td : 'seller';
        }
        var $active = yvoFpParticipantTabStripEl().find('.yvo-fp-tab.active').first();
        if (!$active.length) return 'seller';
        var t = $active.attr('data-tab');
        return t !== undefined && t !== null && String(t) !== '' ? t : 'seller';
    }

    /** Если вкладка seller/buyer удалена (есть seller2), подставляем в существующую панель. */
    function yvoFpResolveParticipantPanelTab(tabId) {
        tabId = String(tabId || 'seller');
        if (tabId === 'property' || tabId === 'generate') {
            return tabId;
        }
        if ($('#yvo-fp-panel-' + tabId).length) {
            return tabId;
        }
        var active = String(getActiveTab() || '');
        if ($('#yvo-fp-panel-' + active).length) {
            return active;
        }
        if (tabId === 'seller' || /^seller/.test(tabId)) {
            var alt = (typeof yvoFpFirstSellerSideTab === 'function') ? yvoFpFirstSellerSideTab(null) : null;
            if (alt && $('#yvo-fp-panel-' + alt).length) {
                return alt;
            }
        }
        if (tabId === 'buyer' || /^buyer/.test(tabId)) {
            var altB = (typeof yvoFpFirstBuyerSideTab === 'function') ? yvoFpFirstBuyerSideTab(null) : null;
            if (altB && $('#yvo-fp-panel-' + altB).length) {
                return altB;
            }
        }
        return tabId;
    }

    function yvoFpResolveUploadRole(uploadFor) {
        uploadFor = String(uploadFor || '');
        if (uploadFor === 'seller' && !$('#yvo-fp-panel-seller').length) {
            return yvoFpResolveParticipantPanelTab('seller');
        }
        if (uploadFor === 'buyer' && !$('#yvo-fp-panel-buyer').length) {
            return yvoFpResolveParticipantPanelTab('buyer');
        }
        return uploadFor;
    }

    /** Как в doki.html (sellerBarAdd / buyerBarAdd): роль следующего участника = тип текущей вкладки. */
    function yvoFpAddRoleFromActiveTab() {
        var t = String(getActiveTab() || '');
        if (t === 'property') return null;
        var role, cfg;
        for (role in ADD_ROLES) {
            if (!ADD_ROLES.hasOwnProperty(role)) continue;
            cfg = ADD_ROLES[role];
            if (cfg.regex.test(t)) return role;
        }
        return 'seller';
    }

    function yvoFpHasOtherSellerSideTabs(excludeTab) {
        return yvoFpParticipantTabButtons().filter(function() {
            var t = String($(this).attr('data-tab') || '');
            if (!t || t === excludeTab) return false;
            return /^seller\d+$/.test(t) || /^minor_seller/.test(t) || /^guardian_seller/.test(t) || /^seller_representative/.test(t);
        }).length > 0;
    }

    function yvoFpHasOtherBuyerSideTabs(excludeTab) {
        return yvoFpParticipantTabButtons().filter(function() {
            var t = String($(this).attr('data-tab') || '');
            if (!t || t === excludeTab) return false;
            return /^buyer\d+$/.test(t) || /^minor_buyer/.test(t) || /^guardian_buyer/.test(t) || /^buyer_representative/.test(t) || /^contributor/.test(t);
        }).length > 0;
    }

    function yvoFpFirstSellerSideTab(excludeTab) {
        var $t = yvoFpParticipantTabButtons().filter(function() {
            var id = String($(this).attr('data-tab') || '');
            if (!id || id === excludeTab) return false;
            return /^seller\d+$/.test(id) || /^minor_seller/.test(id) || /^guardian_seller/.test(id) || /^seller_representative/.test(id);
        }).first();
        return $t.length ? $t.attr('data-tab') : null;
    }

    function yvoFpFirstBuyerSideTab(excludeTab) {
        var $t = yvoFpParticipantTabButtons().filter(function() {
            var id = String($(this).attr('data-tab') || '');
            if (!id || id === excludeTab) return false;
            return /^buyer\d+$/.test(id) || /^minor_buyer/.test(id) || /^guardian_buyer/.test(id) || /^buyer_representative/.test(id) || /^contributor/.test(id);
        }).first();
        return $t.length ? $t.attr('data-tab') : null;
    }

    function updateDeleteButtonVisibility() {
        var tab = getActiveTab();
        var $delBtn = $('#yvo-fp-tab-delete');
        if (tab === 'seller' && yvoFpHasOtherSellerSideTabs('seller')) {
            $delBtn.removeClass('yvo-fp-tab-action-disabled').attr('title', 'Удалить вкладку «Продавец»');
        } else if (tab === 'buyer' && yvoFpHasOtherBuyerSideTabs('buyer')) {
            $delBtn.removeClass('yvo-fp-tab-action-disabled').attr('title', 'Удалить вкладку «Покупатель»');
        } else if (fixedTabs.indexOf(tab) !== -1) {
            $delBtn.removeClass('yvo-fp-tab-action-disabled').attr('title', 'Очистить данные текущего участника');
        } else {
            $delBtn.removeClass('yvo-fp-tab-action-disabled').attr('title', 'Удалить добавленного участника');
        }
    }

    function yvoApplyAddParticipantRole(role, minorAgeOpt, addOpts) {
        addOpts = addOpts || {};
        var silent = !!(addOpts.silent || yvoFpDraftRestoring);
        if (!role) return false;
        if (!yvoFpParticipantTabStripEl().length) {
            if (!silent) showError('Не найдена полоска вкладок участников.');
            return false;
        }
        var $tabPanelsCheck = yvoFpTabPanelsEl();
        if (!$tabPanelsCheck.length) {
            showError('Не найден контейнер панелей формы.');
            return false;
        }
        var next = getNextForRole(role);
        if (!next) {
            if (!silent) showError('Достигнут лимит участников для этой роли.');
            return false;
        }
        var participantId = next.id;
        var $hasPanel = $('#yvo-fp-panel-' + participantId);
        var $hasTab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + participantId + '"]');
        if ($hasPanel.length && $hasTab.length) {
            if (!silent) {
                if ($('.yvo-doki-form-skin').length) {
                    yvoFpDokiFocusParticipant(participantId);
                } else {
                    yvoFpActivateParticipantTab(participantId);
                }
                showFpToastOk('Вкладка «' + ($.trim($hasTab.text()) || participantId) + '» уже есть — переключились на неё.');
            }
            return true;
        }
        var ensureOpts;
        if (role === 'minor_seller' || role === 'minor_buyer') {
            var mag = String(minorAgeOpt || '').trim();
            ensureOpts = { minorAgeGroup: (mag === 'a14_18') ? 'a14_18' : 'u14' };
        }
        if (next.recoverTabOnly) {
            yvoFpAppendParticipantTabButton(participantId, yvoFpBuildParticipantTabLabel(participantId, ensureOpts || {}));
            if ((role === 'minor_seller' || role === 'minor_buyer') && ensureOpts) {
                var $recPanel = $('#yvo-fp-panel-' + participantId);
                if ($recPanel.length) {
                    $recPanel.find('[data-key="minor_age_group"]').val(ensureOpts.minorAgeGroup);
                    yvoFpApplyMinorDocVisibility($recPanel);
                }
            }
        } else {
            ensureParticipantTab(participantId, ensureOpts);
        }
        var $newTab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + participantId + '"]');
        var $newPanel = $('#yvo-fp-panel-' + participantId);
        var guardianCreated = false;
        if ((role === 'minor_seller' || role === 'minor_buyer') && $newPanel.length) {
            yvoFpNormalizeMinorPanel($newPanel);
            if (yvoFpMinorNeedsGuardianTab(participantId)) {
                var gRes = yvoFpEnsureGuardianForMinor(participantId);
                guardianCreated = !!(gRes && gRes.created && gRes.guardianTabId);
            }
        }
        if ((role === 'guardian_seller' || role === 'guardian_buyer') && $newPanel.length) {
            yvoFpLinkGuardianToPairedMinor(participantId);
            yvoFpRefreshSameGuardianSelect($newPanel, participantId);
        }
        if (!$newTab.length || !$newPanel.length) {
            if (!silent) {
                var sideHint = (role === 'buyer' || role === 'minor_buyer' || role === 'guardian_buyer' || role === 'buyer_representative' || role === 'contributor')
                    ? 'одаряемого/покупателя'
                    : 'дарителя/продавца';
                showError('Не удалось создать вкладку участника: нет шаблона формы ' + sideHint + '. Добавьте обычного участника этой стороны или обновите страницу (Ctrl+F5).');
            }
            return false;
        }
        if (!silent) {
            $('.yvo-fp-dropdown').removeClass('open');
            $('#yvo-fp-quick-actions').removeClass('yvo-doki-quick-actions-visible');
            $('#yvoDokiAddParticipantPanel').attr('hidden', true).removeClass('is-open');
        }
        if (!silent) {
            if ($('.yvo-doki-form-skin').length) {
                yvoFpDokiFocusParticipant(participantId);
            } else {
                yvoFpActivateParticipantTab(participantId);
            }
        }
        $(document).trigger('yvo-doki-participant-added');
        if (!silent) {
            var okLabel = $.trim($newTab.text()) || participantId;
            var okMsg = 'Добавлен участник «' + okLabel + '». Заполните поля ниже.';
            if (guardianCreated) {
                okMsg += ' Добавлен опекун — укажите его ФИО и паспорт.';
            }
            showFpToastOk(okMsg);
            try {
                var scrollEl = document.getElementById('yvo-fp-panel-' + participantId);
                if (scrollEl) scrollEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (errScroll) {}
        }
        return true;
    }
    window.yvoApplyAddParticipantRole = yvoApplyAddParticipantRole;

    /* Как в doki.html: прямой addEventListener на кнопках (без jQuery-делегирования на document). */
    function yvoBindNativeAddParticipantButtons() {
        document.querySelectorAll(
            '#yvoDokiAddParticipantPanel .yvo-fp-dropdown-item[data-add], #yvo-fp-add-participant-menu .yvo-fp-dropdown-item[data-add]'
        ).forEach(function(btn) {
            if (btn.getAttribute('data-yvo-add-bound') === '1') return;
            btn.setAttribute('data-yvo-add-bound', '1');
            btn.addEventListener('click', function(ev) {
                ev.preventDefault();
                ev.stopPropagation();
                if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
                if (btn.disabled) return;
                var role = btn.getAttribute('data-add');
                if (!role) return;
                var minorAge = btn.getAttribute('data-minor-age');
                if ((role === 'minor_seller' || role === 'minor_buyer') && !minorAge) {
                    yvoFpUpgradeMinorAddMenu();
                    refreshAddParticipantMenu();
                    showError('Выберите несовершеннолетнего: «до 14 лет» или «от 14 лет».');
                    return;
                }
                yvoApplyAddParticipantRole(role, minorAge);
            }, true);
        });
    }

    $(document).on('click', '#yvo-fp-add-participant-menu [data-add], #yvoDokiAddParticipantPanel [data-add]', function(e) {
        if (this.getAttribute('data-yvo-add-bound') === '1') return;
        e.preventDefault();
        e.stopPropagation();
        var role = $(this).attr('data-add');
        if (!role) return;
        var minorAge = $(this).attr('data-minor-age');
        if ((role === 'minor_seller' || role === 'minor_buyer') && !minorAge) {
            yvoFpUpgradeMinorAddMenu();
            refreshAddParticipantMenu();
            showError('Выберите несовершеннолетнего: «до 14 лет» или «от 14 лет».');
            return;
        }
        yvoApplyAddParticipantRole(role, minorAge);
    });

    yvoFpUpgradeMinorAddMenu();
    yvoFpNormalizeAllMinorPanels();
    yvoFpEnsureGuardiansForAllMinors();
    yvoBindNativeAddParticipantButtons();
    window.yvoFpEnsureGuardianForMinor = yvoFpEnsureGuardianForMinor;
    window.yvoFpRemoveGuardianForMinor = yvoFpRemoveGuardianForMinor;

    $(document).on('click', '#yvo-fp-participant-tabs .yvo-fp-tab, .yvo-frontend-page .yvo-fp-tabs-wrap > .yvo-fp-tabs .yvo-fp-tab', function(e) {
        if ($(this).hasClass('yvo-doki-tab--filtered-out')) {
            return;
        }
        e.preventDefault();
        var tab = $(this).attr('data-tab');
        yvoFpActivateParticipantTab(tab);
    });

    // Состояние кнопки «Удалить»: неактивно для основных вкладок
    updateDeleteButtonVisibility();
    setTimeout(yvoFpUpdateApplySameGuardianButton, 0);

    $(document).on('input change', '#yvo-fp-forms-section input, #yvo-fp-forms-section textarea, #yvo-fp-forms-section select', function() {
        var $el = $(this);
        if ($el.val() !== undefined && $el.val() !== null && String($el.val()).trim() !== '') {
            $el.addClass('yvo-filled');
        } else {
            $el.removeClass('yvo-filled');
        }
    });
    updateFilledClass();

    // ——— Кнопки управления вкладками (делегирование, чтобы работали при любом порядке загрузки) ———
    $(document).on('click', '#yvo-fp-tab-delete', function(e) {
        e.preventDefault();
        if ($(this).hasClass('yvo-fp-tab-action-disabled')) return;
        var tab = getActiveTab();
        // Как в doki.html:
        // - если это seller/buyer/property: либо удаляем последнего доп. участника этой роли, либо очищаем поля
        // - если это добавленная вкладка: удаляем вкладку
        if (tab === 'seller') {
            if (yvoFpHasOtherSellerSideTabs('seller')) {
                yvoFpStashParticipantTemplateIfNeeded('yvo-fp-panel-seller');
                $('#yvo-fp-panel-seller').remove();
                yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="seller"]').remove();
                var nextSeller = yvoFpFirstSellerSideTab(null);
                if (nextSeller) {
                    yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + nextSeller + '"]').trigger('click');
                }
            } else {
                var $lastExtraSeller = yvoFpParticipantTabButtons().filter(function() {
                    return /^seller\d+$/.test(String($(this).attr('data-tab') || ''));
                }).last();
                if ($lastExtraSeller.length) {
                    var extraId = $lastExtraSeller.attr('data-tab');
                    $('#yvo-fp-panel-' + extraId).remove();
                    $lastExtraSeller.remove();
                    yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="seller"]').first().trigger('click');
                } else {
                    $('#yvo-fp-panel-seller').find('input, textarea, select').val('').trigger('change');
                }
            }
            updateDeleteButtonVisibility();
            $(document).trigger('yvo-doki-participant-added');
            return;
        }
        if (tab === 'buyer') {
            if (yvoFpHasOtherBuyerSideTabs('buyer')) {
                yvoFpStashParticipantTemplateIfNeeded('yvo-fp-panel-buyer');
                $('#yvo-fp-panel-buyer').remove();
                yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="buyer"]').remove();
                var nextBuyer = yvoFpFirstBuyerSideTab(null);
                if (nextBuyer) {
                    yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + nextBuyer + '"]').trigger('click');
                }
            } else {
                var $lastExtraBuyer = yvoFpParticipantTabButtons().filter(function() {
                    return /^buyer\d+$/.test(String($(this).attr('data-tab') || ''));
                }).last();
                if ($lastExtraBuyer.length) {
                    var extraIdB = $lastExtraBuyer.attr('data-tab');
                    $('#yvo-fp-panel-' + extraIdB).remove();
                    $lastExtraBuyer.remove();
                    yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="buyer"]').first().trigger('click');
                } else {
                    $('#yvo-fp-panel-buyer').find('input, textarea, select').val('').trigger('change');
                }
            }
            updateDeleteButtonVisibility();
            $(document).trigger('yvo-doki-participant-added');
            return;
        }
        if (tab === 'property') {
            // объект: как в doki.html — очищаем поля объекта
            $('#yvo-fp-panel-property').find('input, textarea, select').val('').trigger('change');
            // восстановим дефолт, чтобы форма не оставалась в пустом/битом состоянии
            $('#property_object_type').val('apartment').trigger('change');
            $('#property_deregistration_deadline').val('14 дней').trigger('change');
            $('#property_vacate_deadline').val('14 дней').trigger('change');
            $(document).trigger('yvo-doki-participant-added');
            return;
        }

        // Удаление добавленной вкладки (несовершеннолетний → вместе с опекуном)
        if (/^minor_(seller|buyer)\d*$/.test(tab)) {
            yvoFpRemoveGuardianForMinor(tab);
        }
        if (/^guardian_(seller|buyer)\d*$/.test(tab)) {
            var minorForG = $('#yvo-fp-panel-' + tab).attr('data-yvo-guardian-for');
            if (minorForG) {
                $('#yvo-fp-panel-' + minorForG).removeAttr('data-yvo-minor-guardian-tab');
            }
        }
        $('#yvo-fp-panel-' + tab).remove();
        yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + tab + '"]').remove();
        var $firstTab = yvoFpParticipantTabButtons().first();
        if ($firstTab.length) $firstTab.trigger('click');
        $(document).trigger('yvo-doki-participant-added');
    });

    function yvoFpHandleTabAddClick(e) {
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        var $dokiPanel = $('#yvoDokiAddParticipantPanel');
        /* DOKI: по умолчанию — меню ролей; Shift+клик — сразу следующий участник текущего шага */
        if ($dokiPanel.length && $('.yvo-doki-form-skin').length) {
            /* Выделение долей: нужны и отчуждающие, и получающие — Shift+клик не привязываем только к активной вкладке, открываем меню. */
            if (e.shiftKey && currentContractType === 'share_allocation') {
                $('.yvo-doki-participant-card').removeClass('yvo-doki-participant-card--collapsed');
                $('#yvoDokiParticipantCardMenu').attr('aria-expanded', 'true');
                refreshAddParticipantMenu();
                $dokiPanel.removeAttr('hidden').addClass('is-open');
                try {
                    $dokiPanel[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } catch (errSa) {}
                showFpToastOk('Выберите: «Добавить отчуждающего» или «Добавить получающего долю».');
                return;
            }
            if (e.shiftKey) {
                var rolePick = yvoFpAddRoleFromActiveTab();
                if (rolePick === null) {
                    showError('На шаге «Объект» второй объект добавляется отдельно. Откройте меню «Добавить участника» и выберите роль, либо переключитесь на продавца или покупателя.');
                    return;
                }
                if (yvoApplyAddParticipantRole(rolePick)) {
                    var lab = ADD_ROLES[rolePick] ? ADD_ROLES[rolePick].label1 : 'Участник';
                    showFpToastOk(lab + ' добавлен. Заполните поля ниже.');
                }
                return;
            }
            $('.yvo-doki-participant-card').removeClass('yvo-doki-participant-card--collapsed');
            $('#yvoDokiParticipantCardMenu').attr('aria-expanded', 'true');
            refreshAddParticipantMenu();
            $dokiPanel.removeAttr('hidden').addClass('is-open');
            try {
                $dokiPanel[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (err) {}
            return;
        }
        if ($dokiPanel.length) {
            $('.yvo-doki-participant-card').removeClass('yvo-doki-participant-card--collapsed');
            $('#yvoDokiParticipantCardMenu').attr('aria-expanded', 'true');
            refreshAddParticipantMenu();
            $dokiPanel.removeAttr('hidden').addClass('is-open');
            try {
                $dokiPanel[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (err2) {}
            return;
        }
        var $qa = $('#yvo-fp-quick-actions');
        var $dd = $('#yvo-fp-add-participant-dropdown');
        var $target = $('#yvo-fp-add-participant-btn');
        if ($('.yvo-doki-form-skin').length && $qa.length) {
            $qa.addClass('yvo-doki-quick-actions-visible');
        }
        refreshAddParticipantMenu();
        if ($target.length) {
            try {
            $target[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (err3) {}
        }
        function yvoFinishOpenAddParticipant() {
            if ($('.yvo-doki-form-skin').length && $qa.length) {
                $qa.addClass('yvo-doki-quick-actions-visible');
            }
            $dd.addClass('open');
            $target.attr('aria-expanded', 'true');
            $('#yvo-fp-autofill-dropdown').removeClass('open');
        }
        yvoFinishOpenAddParticipant();
        setTimeout(yvoFinishOpenAddParticipant, 0);
    }
    $(document).on('click', '#yvo-fp-tab-clear', function(e) {
        e.preventDefault();
        var tab = getActiveTab();
        var $panel = $('#yvo-fp-panel-' + tab);
        if (tab === 'property') {
            $panel.find('.yvo-fp-object-type-block.active input, .yvo-fp-object-type-block.active textarea, .yvo-fp-price-section input, .yvo-fp-price-section textarea, .yvo-fp-conditions-section input, .yvo-fp-conditions-section textarea').val('');
            $('#property_deregistration_deadline').val('14 дней');
            $('#property_vacate_deadline').val('14 дней');
        } else {
            $panel.find('input, textarea').val('');
        }
    });

    $(document).on('click', '#yvo-fp-tab-copy', function(e) {
        e.preventDefault();
        var tab = getActiveTab();
        copiedTabData = collectFormData(tab);
        var json = JSON.stringify(copiedTabData, null, 2);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(json).catch(function() {});
        }
    });

    $(document).on('click', '#yvo-fp-tab-paste', function(e) {
        e.preventDefault();
        var tab = getActiveTab();
        var data = copiedTabData || window.yvoCabinetCopiedData;
        if (!data && navigator.clipboard && navigator.clipboard.readText) {
            navigator.clipboard.readText().then(function(text) {
                try {
                    var parsed = JSON.parse(text);
                    if (!parsed || typeof parsed !== 'object') {
                        showError('В буфере нет подходящих данных для вставки');
                        return;
                    }
                    window.yvoCabinetCopiedData = parsed;
                    var obj = parsed;
                    if ((parsed.sellers || parsed.buyers || parsed.property) && !parsed.full_name && !parsed.price) {
                        if (tab === 'property' && parsed.property) obj = parsed.property;
                        else if (typeof tab === 'string' && tab.indexOf('buyer') === 0 && parsed.buyers && parsed.buyers.length) obj = parsed.buyers[0];
                        else {
                            if (parsed.sellers && parsed.sellers.length) obj = parsed.sellers[0];
                            else if (parsed.buyers && parsed.buyers.length) obj = parsed.buyers[0];
                            else if (parsed.property) obj = parsed.property;
                            else obj = parsed;
                        }
                    }
                    fillForm(tab, obj);
                    var pasteLabs = yvoParticipantRoleShortLabels();
                    var pasteTabName = tab === 'property' ? 'Объект недвижимости' : ((tab === 'seller' || (typeof tab === 'string' && tab.indexOf('seller') === 0)) ? pasteLabs.seller : pasteLabs.buyer);
                    $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Данные подставлены в форму «' + pasteTabName + '».').show();
                    setTimeout(function() { $error.fadeOut(); }, 3500);
                } catch (e) {
                    showError('В буфере нет JSON-данных формы');
                }
            }).catch(function() {
                data = copiedTabData || window.yvoCabinetCopiedData;
                if (data) {
                    var obj2 = data;
                    if ((data.sellers || data.buyers || data.property) && !data.full_name && !data.price) {
                        if (tab === 'property' && data.property) obj2 = data.property;
                        else if (typeof tab === 'string' && tab.indexOf('buyer') === 0 && data.buyers && data.buyers.length) obj2 = data.buyers[0];
                        else {
                            if (data.sellers && data.sellers.length) obj2 = data.sellers[0];
                            else if (data.buyers && data.buyers.length) obj2 = data.buyers[0];
                            else if (data.property) obj2 = data.property;
                            else obj2 = data;
                        }
                    }
                    fillForm(tab, obj2);
                    $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Данные подставлены в форму.').show();
                    setTimeout(function() { $error.fadeOut(); }, 3500);
                } else {
                    showError('Нет скопированных данных. Скопируйте данные кнопкой «Копировать данные» или «Копировать» в личном кабинете.');
                }
            });
            return;
        }
        if (data) {
            var obj2 = data;
            if ((data.sellers || data.buyers || data.property) && !data.full_name && !data.price) {
                if (tab === 'property' && data.property) obj2 = data.property;
                else if (typeof tab === 'string' && tab.indexOf('buyer') === 0 && data.buyers && data.buyers.length) obj2 = data.buyers[0];
                else {
                    if (data.sellers && data.sellers.length) obj2 = data.sellers[0];
                    else if (data.buyers && data.buyers.length) obj2 = data.buyers[0];
                    else if (data.property) obj2 = data.property;
                    else obj2 = data;
                }
            }
            fillForm(tab, obj2);
            $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Данные подставлены в форму.').show();
            setTimeout(function() { $error.fadeOut(); }, 3500);
        } else {
            showError('Сначала скопируйте данные (кнопка «Копировать данные») или загрузите из личного кабинета и нажмите «Копировать».');
        }
    });

    // ——— Сбор данных форм и генерация договора ———
    function yvoFpJoinAddressParts(parts) {
        return (parts || []).map(function(p) { return String(p || '').trim(); }).filter(Boolean).join(', ');
    }

    /** Собрать адрес объекта из полей активного блока (для валидации и отправки на сервер). */
    function yvoFpBuildPropertyAddress(objectType, $active, o) {
        o = o || {};
        var $block = $active && $active.length ? $active : $('#yvo-fp-panel-property .yvo-fp-object-type-block.active');
        var read = function(key) {
            if (o[key] && String(o[key]).trim()) return String(o[key]).trim();
            var $el = $block.find('[data-key="' + key + '"]');
            return $el.length ? String($el.val() || '').trim() : '';
        };
        var direct = read('address');
        if (direct) return direct;
        objectType = objectType || ($('#property_object_type').val() || 'apartment');
        if (objectType === 'apartment' || objectType === 'share') {
            var apt = read('apartment');
            return yvoFpJoinAddressParts([
                read('city'),
                read('street'),
                read('house') ? ('д. ' + read('house')) : '',
                read('building') ? ('корп. ' + read('building')) : '',
                apt ? ('кв. ' + apt) : ''
            ]);
        }
        if (objectType === 'room') {
            var rn = read('room_number');
            return yvoFpJoinAddressParts([
                read('city'),
                read('street'),
                read('house') ? ('д. ' + read('house')) : '',
                read('building') ? ('корп. ' + read('building')) : '',
                read('apartment') ? ('кв. ' + read('apartment')) : '',
                rn ? ('комн. ' + rn) : ''
            ]);
        }
        if (objectType === 'land') {
            return yvoFpJoinAddressParts([
                read('region'),
                read('district'),
                read('settlement'),
                read('snt_dnt'),
                read('plot_number') ? ('уч. ' + read('plot_number')) : ''
            ]);
        }
        if (objectType === 'house_with_plot') {
            return yvoFpJoinAddressParts([
                read('house_settlement'),
                read('house_street'),
                read('house_number') ? ('д. ' + read('house_number')) : ''
            ]);
        }
        if (objectType === 'garage' || objectType === 'parking') {
            return direct;
        }
        return '';
    }

    function collectFormData(role) {
        var o = {};
        var $panel = $('#yvo-fp-panel-' + role);
        if (role === 'property') {
            $panel.find('.yvo-fp-object-type-block.active [data-key], .yvo-fp-price-section [data-key], .yvo-fp-conditions-section [data-key], .yvo-fp-share-allocation-block [data-key]').each(function() {
                var key = $(this).data('key');
                o[key] = $(this).val() || '';
            });
            o.object_type = $panel.find('#property_object_type').val() || 'apartment';
            var $activeBlock = $panel.find('.yvo-fp-object-type-block.active');
            o.address = yvoFpBuildPropertyAddress(o.object_type, $activeBlock, o) || o.address || '';
            YVO_FP_REG_SYNC_KEYS.forEach(function(k) {
                if (o[k]) return;
                var $sync = $('.yvo-fp-reg-sync[data-key="' + k + '"]').filter(function() {
                    return String($(this).val() || '').trim() !== '';
                }).first();
                if ($sync.length) o[k] = $sync.val();
            });
        } else {
            $panel.find('[data-key]').each(function() {
                var $el = $(this);
                var key = $el.data('key');
                if ($el.is(':radio') && !$el.prop('checked')) {
                    return;
                }
                o[key] = $(this).val() || '';
            });
            o.participant_tab = role;
        }
        return o;
    }

    /* ——— Автосохранение черновика формы (localStorage): обновление страницы / обрыв связи ——— */
    var YVO_FP_DRAFT_VERSION = 2;
    var YVO_FP_DRAFT_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;
    var YVO_FP_DRAFT_MAX_HISTORY = 10;
    var yvoFpDraftSaveTimer = null;
    var yvoFpDraftRestoring = false;
    var yvoFpDraftSaveCounter = 0;

    function yvoFpDraftUserSuffix() {
        var uid = (typeof yvo_frontend_ajax !== 'undefined' && yvo_frontend_ajax && yvo_frontend_ajax.user_id)
            ? String(yvo_frontend_ajax.user_id) : '0';
        return 'u' + uid;
    }
    function yvoFpDraftCurrentKey() {
        return 'yvo_fp_draft_current_v2_' + yvoFpDraftUserSuffix();
    }
    function yvoFpDraftHistoryKey() {
        return 'yvo_fp_draft_history_v2_' + yvoFpDraftUserSuffix();
    }
    function yvoFpReadLsJson(key, fallback) {
        try {
            var raw = localStorage.getItem(key);
            return raw ? JSON.parse(raw) : fallback;
        } catch (e) {
            return fallback;
        }
    }
    function yvoFpWriteLsJson(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (e) {
            return false;
        }
    }
    function yvoFpFormatDraftTime(ts) {
        if (!ts) return '';
        try {
            var d = new Date(ts);
            return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        } catch (e2) {
        return '';
        }
    }
    function yvoFpDraftContractLabel(ct) {
        ct = ct || 'sale';
        if (ct === 'gift') return 'дарение';
        if (ct === 'share_allocation') return 'выделение долей';
        if (ct === 'deposit_agreement') return 'задаток';
        if (ct === 'advance_agreement') return 'аванс';
        return 'купля-продажа';
    }
    function yvoFpDraftHasMeaningfulData(snapshot) {
        if (!snapshot || !snapshot.panels) return false;
        var panels = snapshot.panels;
        var k;
        for (k in panels) {
            if (!panels.hasOwnProperty(k)) continue;
            var row = panels[k];
            if (!row || typeof row !== 'object') continue;
            if (row.full_name && String(row.full_name).trim()) return true;
            if (row.address && String(row.address).trim()) return true;
            if (row.cadastral_number && String(row.cadastral_number).trim()) return true;
            if (row.birth_cert_number && String(row.birth_cert_number).trim()) return true;
            if (row.gift_distributions && String(row.gift_distributions).trim()
                && String(row.gift_distributions).trim() !== '[]') {
                return true;
            }
        }
        if (snapshot.ocrText && String(snapshot.ocrText).trim().length > 40) return true;
        return false;
    }
    function yvoFpInferAddRoleFromTabId(tabId) {
        if (!tabId || tabId === 'property' || tabId === 'seller' || tabId === 'buyer') return null;
        if (/^guardian_/.test(tabId)) return null;
        var r;
        for (r in ADD_ROLES) {
            if (ADD_ROLES.hasOwnProperty(r) && ADD_ROLES[r].regex.test(tabId)) {
                return r;
            }
        }
        return null;
    }
    function yvoFpDraftTabSortKey(tabId) {
        if (tabId === 'seller') return 10;
        if (tabId === 'buyer') return 20;
        if (tabId === 'property') return 90;
        var m;
        m = String(tabId).match(/^seller(\d+)$/);
        if (m) return 10 + parseInt(m[1], 10);
        m = String(tabId).match(/^buyer(\d+)$/);
        if (m) return 20 + parseInt(m[1], 10);
        m = String(tabId).match(/^minor_seller(\d*)$/);
        if (m) return 30 + ((m[1] === '') ? 1 : (parseInt(m[1], 10) || 1));
        m = String(tabId).match(/^minor_buyer(\d*)$/);
        if (m) return 40 + ((m[1] === '') ? 1 : (parseInt(m[1], 10) || 1));
        m = String(tabId).match(/^guardian_seller(\d*)$/);
        if (m) return 50 + ((m[1] === '') ? 1 : (parseInt(m[1], 10) || 1));
        m = String(tabId).match(/^guardian_buyer(\d*)$/);
        if (m) return 60 + ((m[1] === '') ? 1 : (parseInt(m[1], 10) || 1));
        m = String(tabId).match(/^seller_representative(\d*)$/);
        if (m) return 35 + ((m[1] === '') ? 1 : (parseInt(m[1], 10) || 1));
        m = String(tabId).match(/^buyer_representative(\d*)$/);
        if (m) return 45 + ((m[1] === '') ? 1 : (parseInt(m[1], 10) || 1));
        m = String(tabId).match(/^contributor(\d*)$/);
        if (m) return 25 + ((m[1] === '') ? 1 : (parseInt(m[1], 10) || 1));
        return 70;
    }
    /** Создать вкладку с точным id из черновика (не «следующий свободный»). */
    function yvoFpEnsureParticipantTabById(tabId, row) {
        if (!tabId || tabId === 'property' || tabId === 'seller' || tabId === 'buyer') return;
        if (/^guardian_/.test(tabId)) return;
        var $panel = $('#yvo-fp-panel-' + tabId);
        var $tab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + tabId + '"]');
        if ($panel.length && $tab.length) return;
        var opts = {};
        if (row && row.minor_age_group) {
            opts.minorAgeGroup = String(row.minor_age_group).trim() === 'a14_18' ? 'a14_18' : 'u14';
        }
        if ($panel.length && !$tab.length) {
            yvoFpAppendParticipantTabButton(tabId, yvoFpBuildParticipantTabLabel(tabId, opts));
            return;
        }
        if (typeof ensureParticipantTab === 'function') {
            ensureParticipantTab(tabId, opts);
        }
    }
    function yvoFpCollectDraftSnapshot() {
        var tabIds = [];
        yvoFpParticipantTabButtons().each(function() {
            var t = $(this).attr('data-tab');
            if (t) tabIds.push(String(t));
        });
        var panels = {};
        tabIds.forEach(function(tabId) {
            panels[tabId] = collectFormData(tabId);
        });
        var dokiStep = null;
        if (typeof window.yvoDokiGetCurrentStepIndex === 'function') {
            dokiStep = window.yvoDokiGetCurrentStepIndex();
        }
        return {
            v: YVO_FP_DRAFT_VERSION,
            savedAt: Date.now(),
            contractType: currentContractType,
            templateId: ($('#yvo-fp-contract-template').length ? $('#yvo-fp-contract-template').val() : '') || '',
            bankId: ($('#yvo-fp-bank').length ? $('#yvo-fp-bank').val() : '') || 'standard',
            activeTab: getActiveTab(),
            dokiStep: dokiStep,
            ocrText: $text.length ? String($text.val() || '') : '',
            tabIds: tabIds,
            panels: panels
        };
    }
    function yvoFpUpdateDraftBarStatus(snapshot) {
        var $bar = $('#yvo-fp-draft-bar');
        var $st = $('#yvo-fp-draft-status');
        if (!$bar.length || !$st.length) return;
        if (snapshot && snapshot.savedAt) {
            $bar.removeClass('yvo-fp-draft-bar--restore').show();
            $st.text('Черновик сохранён ' + yvoFpFormatDraftTime(snapshot.savedAt) + ' · ' + yvoFpDraftContractLabel(snapshot.contractType));
        } else {
            $st.text('Черновик: данные сохраняются автоматически при заполнении формы.');
            $bar.removeClass('yvo-fp-draft-bar--restore').show();
        }
    }
    function yvoFpPushHistorySnapshot(snapshot) {
        if (!snapshot || !yvoFpDraftHasMeaningfulData(snapshot)) return;
        var list = yvoFpReadLsJson(yvoFpDraftHistoryKey(), []);
        if (!Array.isArray(list)) list = [];
        var label = yvoFpFormatDraftTime(snapshot.savedAt) + ' · ' + yvoFpDraftContractLabel(snapshot.contractType);
        var entry = { savedAt: snapshot.savedAt, label: label, snapshot: snapshot };
        list = list.filter(function(it) {
            if (!it || !it.savedAt) return false;
            if (Math.abs(it.savedAt - snapshot.savedAt) < 3000) return false;
            if (it.snapshot && snapshot.contractType
                && it.snapshot.contractType === snapshot.contractType
                && Math.abs(it.savedAt - snapshot.savedAt) < 20 * 60 * 1000) {
                return false;
            }
            return true;
        });
        list.unshift(entry);
        if (list.length > YVO_FP_DRAFT_MAX_HISTORY) {
            list = list.slice(0, YVO_FP_DRAFT_MAX_HISTORY);
        }
        yvoFpWriteLsJson(yvoFpDraftHistoryKey(), list);
    }
    function yvoFpDraftShouldPushHistory(snapshot, pushHistory) {
        if (pushHistory) {
            return true;
        }
        var list = yvoFpReadLsJson(yvoFpDraftHistoryKey(), []);
        var last = Array.isArray(list) && list.length ? list[0] : null;
        if (!last || !last.snapshot) {
            return true;
        }
        if (last.snapshot.contractType !== snapshot.contractType) {
            return true;
        }
        if ((snapshot.savedAt - last.savedAt) >= 15 * 60 * 1000) {
            return true;
        }
        return yvoFpDraftSaveCounter > 0 && yvoFpDraftSaveCounter % 3 === 0;
    }
    function yvoFpSaveDraft(pushHistory) {
        if (yvoFpDraftRestoring || !$('#yvo-fp-forms-section').length) return;
        var snapshot = yvoFpCollectDraftSnapshot();
        if (!yvoFpDraftHasMeaningfulData(snapshot)) return;
        yvoFpWriteLsJson(yvoFpDraftCurrentKey(), snapshot);
        try {
            sessionStorage.setItem(yvoFpDraftCurrentKey(), JSON.stringify(snapshot));
        } catch (eSs) {}
        yvoFpUpdateDraftBarStatus(snapshot);
        yvoFpDraftSaveCounter++;
        if (yvoFpDraftShouldPushHistory(snapshot, pushHistory)) {
            yvoFpPushHistorySnapshot(snapshot);
        }
    }
    function yvoFpScheduleDraftSave() {
        if (yvoFpDraftRestoring) return;
        clearTimeout(yvoFpDraftSaveTimer);
        yvoFpDraftSaveTimer = setTimeout(function() {
            yvoFpSaveDraft(false);
        }, 1200);
    }
    function yvoFpClearDraftStorage() {
        try {
            localStorage.removeItem(yvoFpDraftCurrentKey());
            sessionStorage.removeItem(yvoFpDraftCurrentKey());
        } catch (e) {}
        yvoFpUpdateDraftBarStatus(null);
    }
    function yvoFpActivateFormTab(tabId) {
        if (!tabId) return;
        var $tab = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + tabId + '"]');
        if ($tab.length) {
            $tab.trigger('click');
            return;
        }
        if (typeof yvoFpDokiFocusParticipant === 'function') {
            yvoFpDokiFocusParticipant(tabId);
        }
    }
    function yvoFpRestoreDraftSnapshot(snapshot) {
        if (!snapshot || snapshot.v !== YVO_FP_DRAFT_VERSION || !yvoFpDraftHasMeaningfulData(snapshot)) {
            showError('Не удалось восстановить черновик (нет данных или устаревший формат).');
            return false;
        }
        if (snapshot.savedAt && (Date.now() - snapshot.savedAt) > YVO_FP_DRAFT_MAX_AGE_MS) {
            showError('Черновик устарел (старше 7 дней). Удалите его или заполните форму заново.');
            return false;
        }
        clearTimeout(yvoFpDraftSaveTimer);
        yvoFpDraftRestoring = true;
        hideError();
        if (snapshot.contractType && typeof yvoApplyFrontendContractType === 'function') {
            yvoApplyFrontendContractType(snapshot.contractType);
        }
        var tabIds = (snapshot.tabIds || []).slice();
        var panels = snapshot.panels || {};
        tabIds.sort(function(a, b) {
            return yvoFpDraftTabSortKey(a) - yvoFpDraftTabSortKey(b);
        });
        if (snapshot.dokiStep !== null && snapshot.dokiStep !== undefined && typeof window.yvoDokiApplyStepIndex === 'function') {
            window.yvoDokiApplyStepIndex(snapshot.dokiStep);
        }
        tabIds.forEach(function(tabId) {
            if (!tabId || tabId === 'property') return;
            yvoFpEnsureParticipantTabById(tabId, panels[tabId]);
        });
        try {
            yvoFpEnsureGuardiansForAllMinors();
        } catch (eG) {}
        tabIds.forEach(function(tabId) {
            if (!tabId || !panels[tabId]) return;
            var row = panels[tabId];
            if (/^guardian_/.test(tabId)) {
                var $gp = $('#yvo-fp-panel-' + tabId);
                var $gt = yvoFpParticipantTabStripEl().find('.yvo-fp-tab[data-tab="' + tabId + '"]');
                if ($gp.length && !$gt.length) {
                    yvoFpAppendParticipantTabButton(tabId, yvoFpBuildParticipantTabLabel(tabId, {}));
                }
            }
            fillForm(tabId, row);
        });
        if (snapshot.templateId && $('#yvo-fp-contract-template').length) {
            $('#yvo-fp-contract-template').val(snapshot.templateId);
        }
        if (snapshot.bankId && $('#yvo-fp-bank').length) {
            $('#yvo-fp-bank').val(snapshot.bankId);
        }
        if (snapshot.ocrText && $text.length) {
            $text.val(snapshot.ocrText);
            if (String(snapshot.ocrText).trim().length > 10) {
                $resultSection.show();
            }
        }
        updateFilledClass();
        var focusTab = snapshot.activeTab || 'seller';
        setTimeout(function() {
            if (typeof yvoFpDokiFocusParticipant === 'function' && $('.yvo-doki-form-skin').length) {
                yvoFpDokiFocusParticipant(focusTab);
            } else {
                yvoFpActivateFormTab(focusTab);
            }
            if (typeof window.yvoDokiFilterParticipantTabsForStep === 'function' && typeof window.yvoDokiGetCurrentStepIndex === 'function') {
                window.yvoDokiFilterParticipantTabsForStep(window.yvoDokiGetCurrentStepIndex());
            }
            yvoFpDraftRestoring = false;
            yvoFpSaveDraft(false);
            showFpToastOk('Данные формы восстановлены из черновика.');
            $('#yvo-fp-draft-restore-banner').remove();
            $('#yvo-fp-draft-history-modal').attr('hidden', true);
        }, 120);
        return true;
    }
    function yvoFpShowDraftRestoreBanner(snapshot) {
        if ($('#yvo-fp-draft-restore-banner').length) return;
        var when = yvoFpFormatDraftTime(snapshot.savedAt);
        var $bar = $('<div id="yvo-fp-draft-restore-banner" class="yvo-fp-draft-bar yvo-fp-draft-bar--restore" role="status"></div>');
        $bar.append($('<span class="yvo-fp-draft-bar__status"></span>').text(
            'Найдено несохранённое заполнение от ' + when + ' (' + yvoFpDraftContractLabel(snapshot.contractType) + '). Восстановить?'
        ));
        var $actions = $('<div class="yvo-fp-draft-bar__actions"></div>');
        $actions.append($('<button type="button" class="yvo-fp-btn yvo-fp-btn-primary" id="yvo-fp-draft-restore-now">Восстановить</button>'));
        $actions.append($('<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-draft-dismiss-restore">Начать заново</button>'));
        $bar.append($actions);
        var $slot = $('#yvo-fp-draft-slot');
        if ($slot.length) {
            $slot.prepend($bar);
            return;
        }
        var $gen = $('#yvo-fp-section-generate');
        if ($gen.length) {
            $gen.before($bar);
            return;
        }
        var $anchor = $('.yvo-doki-forms-intro').first();
        if (!$anchor.length) {
            $anchor = $('#yvo-fp-forms-section > .yvo-fp-section-hint').first();
        }
        if (!$anchor.length) {
            $anchor = $('#yvo-fp-forms-section');
        }
        $anchor.first().before($bar);
    }
    function yvoFpOpenDraftHistoryModal() {
        var list = yvoFpReadLsJson(yvoFpDraftHistoryKey(), []);
        var current = yvoFpReadLsJson(yvoFpDraftCurrentKey(), null);
        var $modal = $('#yvo-fp-draft-history-modal');
        if (!$modal.length) {
            $modal = $('<div id="yvo-fp-draft-history-modal" class="yvo-fp-draft-history-modal" hidden></div>');
            $('body').append($modal);
        }
        var html = '<div class="yvo-fp-draft-history-modal__panel" role="dialog" aria-modal="true" aria-labelledby="yvo-fp-draft-history-title">';
        html += '<h3 id="yvo-fp-draft-history-title" style="margin:0 0 12px;font-size:1.1rem;">История заполнений</h3>';
        html += '<p style="margin:0 0 12px;color:#64748b;font-size:0.88rem;">До ' + YVO_FP_DRAFT_MAX_HISTORY + ' последних автосохранений на этом устройстве.</p>';
        if (current && yvoFpDraftHasMeaningfulData(current)) {
            html += '<div class="yvo-fp-draft-history-item"><span><strong>Текущий черновик</strong><br><small>'
                + yvoFpFormatDraftTime(current.savedAt) + ' · ' + yvoFpDraftContractLabel(current.contractType)
                + '</small></span>';
            html += '<button type="button" class="yvo-fp-btn yvo-fp-btn-primary yvo-fp-draft-restore-entry" data-entry="current">Восстановить</button></div>';
        }
        if (!list.length) {
            html += '<p style="color:#64748b;">Пока нет других записей в истории.</p>';
        } else {
            list.forEach(function(it, idx) {
                if (!it || !it.snapshot) return;
                html += '<div class="yvo-fp-draft-history-item"><span>' + (it.label || ('Запись ' + (idx + 1))) + '</span>';
                html += '<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-draft-restore-entry" data-entry="' + idx + '">Восстановить</button></div>';
            });
        }
        html += '<div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end;">';
        html += '<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-draft-history-close">Закрыть</button></div></div>';
        $modal.html(html).removeAttr('hidden');
    }
    function yvoFpInjectDraftBar() {
        if ($('#yvo-fp-draft-bar').length) return;
        var $bar = $('<div id="yvo-fp-draft-bar" class="yvo-fp-draft-bar"></div>');
        $bar.append($('<span id="yvo-fp-draft-status" class="yvo-fp-draft-bar__status"></span>').text('Черновик: автосохранение при заполнении.'));
        var $actions = $('<div class="yvo-fp-draft-bar__actions"></div>');
        $actions.append($('<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-draft-save-now">Сохранить сейчас</button>'));
        $actions.append($('<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-draft-history-btn">История</button>'));
        $actions.append($('<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-draft-clear-btn">Удалить черновик</button>'));
        $bar.append($actions);
        var $slot = $('#yvo-fp-draft-slot');
        if ($slot.length) {
            $slot.append($bar);
            return;
        }
        var $gen = $('#yvo-fp-section-generate');
        if ($gen.length) {
            $gen.before($bar);
            return;
        }
        var $anchor = $('.yvo-doki-forms-intro').first();
        if (!$anchor.length) {
            $anchor = $('#yvo-fp-forms-section > .yvo-fp-section-hint').first();
        }
        if (!$anchor.length) {
            $anchor = $('#yvo-fp-forms-section .yvo-fp-tabs-wrap').first();
        }
        $anchor.first().before($bar);
    }
    function yvoFpInitDraftPersistence() {
        if (!$('#yvo-fp-forms-section').length) return;
        yvoFpInjectDraftBar();
        var pending = yvoFpReadLsJson(yvoFpDraftCurrentKey(), null);
        if (!pending) {
            try {
                var ss = sessionStorage.getItem(yvoFpDraftCurrentKey());
                if (ss) pending = JSON.parse(ss);
            } catch (e) {}
        }
        if (pending && yvoFpDraftHasMeaningfulData(pending)) {
            yvoFpShowDraftRestoreBanner(pending);
        } else {
            yvoFpUpdateDraftBarStatus(null);
        }
        $(document).on('click', '#yvo-fp-draft-restore-now', function(e) {
            e.preventDefault();
            var snap = yvoFpReadLsJson(yvoFpDraftCurrentKey(), null);
            if (snap) yvoFpRestoreDraftSnapshot(snap);
        });
        $(document).on('click', '#yvo-fp-draft-dismiss-restore', function(e) {
            e.preventDefault();
            $('#yvo-fp-draft-restore-banner').remove();
            showFpToastOk('Черновик оставлен в памяти браузера — восстановите через «История».');
        });
        $(document).on('click', '#yvo-fp-draft-save-now', function(e) {
            e.preventDefault();
            yvoFpSaveDraft(true);
            showFpToastOk('Черновик сохранён.');
        });
        $(document).on('click', '#yvo-fp-draft-clear-btn', function(e) {
            e.preventDefault();
            if (!confirm('Удалить черновик и историю автосохранений на этом устройстве?')) return;
            yvoFpClearDraftStorage();
            try { localStorage.removeItem(yvoFpDraftHistoryKey()); } catch (e2) {}
            $('#yvo-fp-draft-restore-banner').remove();
            showFpToastOk('Черновик удалён.');
        });
        $(document).on('click', '#yvo-fp-draft-history-btn', function(e) {
            e.preventDefault();
            yvoFpSaveDraft(true);
            yvoFpOpenDraftHistoryModal();
        });
        $(document).on('click', '#yvo-fp-draft-history-close', function(e) {
            e.preventDefault();
            $('#yvo-fp-draft-history-modal').attr('hidden', true);
        });
        $(document).on('click', '#yvo-fp-draft-history-modal', function(e) {
            if (e.target === this) {
                $(this).attr('hidden', true);
            }
        });
        $(document).on('click', '.yvo-fp-draft-restore-entry', function(e) {
            e.preventDefault();
            var key = $(this).attr('data-entry');
            var snap = null;
            if (key === 'current') {
                snap = yvoFpReadLsJson(yvoFpDraftCurrentKey(), null);
            } else {
                var list = yvoFpReadLsJson(yvoFpDraftHistoryKey(), []);
                var idx = parseInt(key, 10);
                if (list[idx] && list[idx].snapshot) snap = list[idx].snapshot;
            }
            $('#yvo-fp-draft-history-modal').attr('hidden', true);
            if (snap) yvoFpRestoreDraftSnapshot(snap);
        });
        $(document).on('input change', '#yvo-fp-forms-section input, #yvo-fp-forms-section textarea, #yvo-fp-forms-section select', function() {
            yvoFpScheduleDraftSave();
        });
        $(document).on('input change', '#yvo-fp-text', function() {
            yvoFpScheduleDraftSave();
        });
        $(document).on('yvo-contract-type-changed yvo-doki-participant-added', function() {
            yvoFpScheduleDraftSave();
        });
        $(document).on('click', '.yvo-frontend-page .yvo-fp-tabs-wrap > .yvo-fp-tabs .yvo-fp-tab', function() {
            yvoFpScheduleDraftSave();
        });
        window.addEventListener('beforeunload', function() {
            yvoFpSaveDraft(true);
        });
    }
    window.yvoFpRestoreDraftSnapshot = yvoFpRestoreDraftSnapshot;
    window.yvoFpSaveFormDraft = function() { yvoFpSaveDraft(true); };

    function getPropertyAddressForValidation() {
        var type = $('#property_object_type').val() || 'apartment';
        return yvoFpBuildPropertyAddress(type);
    }

    // ——— Просмотр шаблона договора ———
    var $modal = $('#yvo-fp-template-preview-modal');
    var $modalText = $('#yvo-fp-template-preview-text');
    $('#yvo-fp-preview-template').on('click', function() {
        var templateId = $('#yvo-fp-contract-template').val();
        if (!templateId) {
            templateId = 'default';
        }
        $modalText.text('Загрузка...');
        $modal.show().css({ display: 'flex', visibility: 'visible', zIndex: 100000 });
        var ajaxUrl = (typeof yvo_frontend_ajax !== 'undefined' && yvo_frontend_ajax && yvo_frontend_ajax.ajax_url) ? yvo_frontend_ajax.ajax_url : '';
        var nonce = (typeof yvo_frontend_ajax !== 'undefined' && yvo_frontend_ajax && yvo_frontend_ajax.nonce) ? yvo_frontend_ajax.nonce : '';
        if (!ajaxUrl) {
            $modalText.text('Ошибка: не настроен адрес запроса. Обновите страницу.');
            return;
        }
        $.post(ajaxUrl, {
            action: 'yvo_frontend_get_template_preview',
            nonce: nonce,
            template_id: templateId
        }, 'json').done(function(res) {
            var text = (res && res.data && res.data.content !== undefined) ? String(res.data.content) : '';
            if (text === '' && (!res || !res.success)) {
                text = res && res.data && res.data.message ? res.data.message : 'Не удалось загрузить шаблон.';
                if (text.indexOf('безопасности') !== -1) {
                    text += ' Обновите страницу (F5) и нажмите «Посмотреть шаблон» снова.';
                }
            }
            if (text === '') {
                text = '(Шаблон пуст.)';
            }
            $modalText.text(text);
        }).fail(function(xhr, status, err) {
            $modalText.text('Ошибка загрузки. ' + (status || '') + (err ? ' ' + err : '') + '. Проверьте консоль (F12).');
        });
    });
    $('#yvo-fp-modal-close, #yvo-fp-template-preview-modal .yvo-fp-modal-backdrop').on('click', function() {
        $modal.hide();
    });

    // ——— Загрузить свой шаблон: .txt (FileReader), .docx/.pdf (сервер) → анализ через DeepSeek ———
    var customContractTemplateContent = null;
    function runAnalyzeTemplate(templateText) {
        if (!templateText || templateText.length < 50) {
            showError('Текст шаблона слишком короткий.');
            return;
        }
        $generateProgress.show().text('Анализ шаблона через DeepSeek...');
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_analyze_contract_template',
            nonce: yvo_frontend_ajax.nonce,
            template_text: templateText
        }, 'json').done(function(res) {
            $generateProgress.hide().text('Создание договора...');
            if (res.success && res.data && res.data.template_content) {
                customContractTemplateContent = res.data.template_content;
                $('#yvo-fp-custom-template-hint').remove();
                $('#yvo-fp-contract-template').closest('.yvo-fp-option-row').append('<span id="yvo-fp-custom-template-hint" class="yvo-fp-custom-hint">Используется загруженный шаблон <button type="button" class="yvo-fp-clear-custom-template" style="margin-left:6px;background:none;border:none;color:inherit;text-decoration:underline;cursor:pointer;">сбросить</button></span>');
            } else {
                showError(res.data && res.data.message ? res.data.message : 'Не удалось проанализировать шаблон.');
            }
        }).fail(function(xhr) {
            $generateProgress.hide().text('Создание договора...');
            var msg = 'Ошибка сервера';
            try {
                var j = JSON.parse(xhr.responseText);
                if (j.data && j.data.message) msg = j.data.message;
            } catch (err) {}
            showError(msg);
        });
    }
    $('#yvo-fp-upload-contract-template').on('click', function() {
        if (!isPro) {
            $('#yvo-fp-pro-modal').show();
            return;
        }
        $('#yvo-fp-file-contract-template')[0].click();
    });
    $('#yvo-fp-file-contract-template').on('change', function() {
        var file = this.files[0];
        this.value = '';
        if (!file) return;
        var ext = (file.name || '').split('.').pop().toLowerCase();
        if (ext !== 'txt' && ext !== 'docx' && ext !== 'pdf') {
            showError('Поддерживаются форматы: .txt, .docx, .pdf. Для .doc сохраните как .docx.');
            return;
        }
        if (ext === 'txt') {
            var reader = new FileReader();
            reader.onload = function(e) {
                var templateText = (e.target && e.target.result) ? String(e.target.result) : '';
                runAnalyzeTemplate(templateText);
            };
            reader.onerror = function() { showError('Не удалось прочитать файл.'); };
            reader.readAsText(file, 'UTF-8');
            return;
        }
        $generateProgress.show().text('Извлечение текста из файла...');
        var formData = new FormData();
        formData.append('action', 'yvo_extract_template_text');
        formData.append('nonce', yvo_frontend_ajax.nonce);
        formData.append('file', file);
        $.ajax({
            url: yvo_frontend_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function(res) {
            $generateProgress.hide();
            if (res.success && res.data && res.data.text) {
                runAnalyzeTemplate(res.data.text);
            } else {
                showError(res.data && res.data.message ? res.data.message : 'Не удалось извлечь текст из файла.');
            }
        }).fail(function(xhr) {
            $generateProgress.hide();
            var msg = 'Ошибка сервера';
            try {
                var j = JSON.parse(xhr.responseText);
                if (j.data && j.data.message) msg = j.data.message;
            } catch (err) {}
            showError(msg);
        });
    });
    $(document).on('click', '.yvo-fp-clear-custom-template', function() {
        customContractTemplateContent = null;
        $('#yvo-fp-custom-template-hint').remove();
    });
    $('#yvo-fp-upload-act-template').on('click', function() {
        if (!isPro) {
            $('#yvo-fp-pro-modal').show();
            return;
        }
        $('#yvo-fp-file-act-template')[0].click();
    });

    // Открытие / закрытие PRO-модалки
    $('#yvo-fp-open-pro-modal').on('click', function() {
        $('#yvo-fp-pro-modal').show();
    });
    $('#yvo-fp-pro-modal .yvo-fp-modal-close, #yvo-fp-pro-modal .yvo-fp-modal-backdrop').on('click', function() {
        $('#yvo-fp-pro-modal').hide();
    });

    // Проверка договора на ошибки (только для авторизованных)
    var yvoCheckCorrectedText = '';
    var yvoCheckFromStandalone = false;

    function runContractCheck(content, $btn, btnDoneText) {
        if (!content || content.length < 50) {
            showError('Введите или загрузите текст договора (не менее 50 символов).');
            return;
        }
        $btn.prop('disabled', true).text('Проверка...');
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_check_contract_errors',
            nonce: yvo_frontend_ajax.nonce,
            contract_content: content
        }, 'json').done(function(res) {
            $btn.prop('disabled', false).text(btnDoneText);
            if (res.success && res.data) {
                var report = res.data.report || '';
                yvoCheckCorrectedText = res.data.corrected || '';
                $('#yvo-fp-check-result-report').text(report);
                var $actions = $('#yvo-fp-check-result-actions');
                if (yvoCheckCorrectedText.length > 50) {
                    $actions.show();
                } else {
                    $actions.hide();
                }
                $('#yvo-fp-check-result-modal').show();
            } else {
                showError(res.data && res.data.message ? res.data.message : 'Ошибка проверки');
            }
        }).fail(function(xhr) {
            $btn.prop('disabled', false).text(btnDoneText);
            var msg = 'Ошибка сервера';
            try { var j = JSON.parse(xhr.responseText); if (j.data && j.data.message) msg = j.data.message; } catch (e) {}
            showError(msg);
        });
    }

    $('#yvo-fp-check-contract-btn').on('click', function() {
        if (yvoFpGuardPaidFeature('check')) {
            return;
        }
        if (typeof yvo_frontend_ajax === 'undefined' || !yvo_frontend_ajax.user_logged_in) {
            showError('Проверка доступна только авторизованным пользователям. Войдите в личный кабинет.');
            return;
        }
        var content = ($('#yvo-fp-contract-editor-text').length && $('#yvo-fp-contract-editor-modal').is(':visible'))
            ? $('#yvo-fp-contract-editor-text').val()
            : (window.yvoLastContractContent || '');
        yvoCheckFromStandalone = false;
        runContractCheck(content, $(this), 'Проверить договор');
    });

    $('#yvo-fp-check-contract-load-btn').on('click', function() {
        $('#yvo-fp-check-contract-file')[0].click();
    });
    $('#yvo-fp-check-contract-file').on('change', function() {
        var file = this.files && this.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            var text = e.target && e.target.result;
            if (typeof text === 'string') $('#yvo-fp-check-contract-text').val(text);
        };
        reader.readAsText(file, 'UTF-8');
        this.value = '';
    });

    $('#yvo-fp-check-contract-standalone-btn').on('click', function() {
        if (yvoFpGuardPaidFeature('check')) {
            return;
        }
        if (typeof yvo_frontend_ajax === 'undefined' || !yvo_frontend_ajax.user_logged_in) {
            showError('Проверка доступна только авторизованным пользователям. Войдите в личный кабинет.');
            return;
        }
        var content = $('#yvo-fp-check-contract-text').val() || '';
        yvoCheckFromStandalone = true;
        runContractCheck(content, $(this), 'Проверить договор');
    });
    $('#yvo-fp-check-result-modal .yvo-fp-modal-close, #yvo-fp-check-result-modal .yvo-fp-modal-backdrop').on('click', function() {
        $('#yvo-fp-check-result-modal').hide();
    });
    $('#yvo-fp-check-result-apply-btn').on('click', function() {
        if (yvoCheckCorrectedText && yvoCheckCorrectedText.length > 10) {
            if (yvoCheckFromStandalone) {
                $('#yvo-fp-check-contract-text').val(yvoCheckCorrectedText);
                $('#yvo-fp-check-result-modal').hide();
                $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' });
                $error.text('Исправленный текст подставлен в поле проверки. Вы можете проверить снова или скопировать.').show();
                setTimeout(function() { $error.fadeOut(); }, 4000);
            } else {
                window.yvoLastContractContent = yvoCheckCorrectedText;
                $('#yvo-fp-contract-editor-text').val(yvoCheckCorrectedText);
                $('#yvo-fp-check-result-modal').hide();
                if (!$('#yvo-fp-contract-editor-modal').is(':visible')) {
                    $('#yvo-fp-edit-contract-btn').click();
                }
                $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' });
                $error.text('Исправленный текст подставлен в редактор. Сохраните договор при необходимости.').show();
                setTimeout(function() { $error.fadeOut(); }, 4000);
            }
        }
    });

    // Переименование шаблона (локально, в этом браузере)
    function getTemplateLabelOverrides() {
        try { return JSON.parse(localStorage.getItem('yvo_template_labels') || '{}') || {}; } catch (e) { return {}; }
    }
    function setTemplateLabelOverride(templateId, label) {
        var o = getTemplateLabelOverrides();
        if (!label) delete o[templateId];
        else o[templateId] = label;
        try { localStorage.setItem('yvo_template_labels', JSON.stringify(o)); } catch (e) {}
    }
    function applyTemplateLabelOverrides() {
        var o = getTemplateLabelOverrides();
        if (!o || typeof o !== 'object') return;
        var $sel = $('#yvo-fp-contract-template');
        $sel.find('option').each(function() {
            var id = $(this).attr('value');
            if (id && o[id]) $(this).text(o[id]);
        });
        if (typeof window.yvo_contract_templates === 'object' && window.yvo_contract_templates) {
            Object.keys(o).forEach(function(id) {
                window.yvo_contract_templates[id] = o[id];
            });
        }
    }
    applyTemplateLabelOverrides();
    $('#yvo-fp-rename-template').on('click', function() {
        var id = $('#yvo-fp-contract-template').val();
        if (!id) return;
        var curLabel = $('#yvo-fp-contract-template option:selected').text().trim();
        var next = prompt('Введите новое название шаблона (только для этого браузера):', curLabel);
        if (next === null) return;
        next = String(next || '').trim();
        if (!next) {
            setTemplateLabelOverride(id, '');
        } else {
            setTemplateLabelOverride(id, next);
        }
        applyTemplateLabelOverrides();
        updateContractTemplateOptions(currentContractType);
    });

    /** Несовершеннолетний (не «выдел долей»): вкладка опекуна и ФИО опекуна обязательны. */
    function yvoFpGuardianRequiredErrorForMinorU14() {
        if (currentContractType === 'share_allocation') return '';
        var err = '';
        yvoFpParticipantTabButtons().each(function() {
            if (err) return;
            var tab = String($(this).attr('data-tab') || '');
            if (!/^minor_(seller|buyer)\d*$/.test(tab)) return;
            var $p = $('#yvo-fp-panel-' + tab);
            if (!$p.length) return;
            var gTab = yvoFpGuardianTabIdForMinor(tab);
            if (!gTab) return;
            if (!$('#yvo-fp-panel-' + gTab).length) {
                yvoFpEnsureGuardianForMinor(tab);
            }
            var $g = $('#yvo-fp-panel-' + gTab);
            if (!$g.length) {
                err = 'Для несовершеннолетнего должна быть вкладка опекуна. Обновите страницу (Ctrl+F5) и снова добавьте участника через меню.';
                return;
            }
            var sameAs = ($g.find('[data-key="same_guardian_as"]').val() || '').trim();
            var gName = '';
            if (sameAs) {
                var $src = $('#yvo-fp-panel-' + sameAs);
                gName = ($src.find('[data-key="full_name"]').val() || '').trim();
                if (!gName) {
                    var srcLabel = $.trim($('.yvo-fp-tab[data-tab="' + sameAs + '"]').first().text()) || sameAs;
                    err = 'Укажите ФИО опекуна во вкладке «' + srcLabel + '» (данные общие для нескольких несовершеннолетних).';
                    return;
                }
            } else {
                gName = ($g.find('[data-key="full_name"]').val() || '').trim();
            }
            if (!gName) {
                var gLabel = $.trim($('.yvo-fp-tab[data-tab="' + gTab + '"]').first().text()) || gTab;
                err = 'Укажите ФИО опекуна во вкладке «' + gLabel + '». Без ФИО опекун не попадает в договор.';
            }
        });
        return err;
    }

    $generateBtn.on('click', function() {
        try {
        var property = collectFormData('property');
        fillWordsFromNumber('#property_price', '#property_price_words');
        if (($('#property_payment_type').val() || 'cash') === 'mortgage') {
            fillWordsFromNumber('#property_loan_amount', '#property_loan_amount_words');
            fillWordsFromNumber('#property_loan_own_amount', '#property_loan_own_amount_words');
        }
        property = collectFormData('property');
        var bankLabel = ($('#yvo-fp-bank').length ? ($('#yvo-fp-bank option:selected').text() || '').trim() : '');
        if (bankLabel && !String(property.bank_name || '').trim()) {
            property.bank_name = bankLabel;
        }
        var contractTypeForPost = currentContractType;
        if (currentContractType === 'sale' || currentContractType === 'sale_mortgage') {
            contractTypeForPost = (property.payment_type === 'mortgage') ? 'sale_mortgage' : 'sale';
        }
        var pb = yvoFpCollectSellersAndBuyersInTabOrder();
        var sellers = pb.sellers;
        var buyers = pb.buyers;
        // Диагностика: что именно уходит на сервер (можно посмотреть в консоли: window.yvoLastGeneratePayload)
        try {
            var tabs = [];
            yvoFpParticipantTabButtons().each(function() {
                tabs.push(String($(this).attr('data-tab') || ''));
            });
            window.yvoLastGeneratePayload = {
                tabs: tabs,
                sellers_raw: sellers,
                buyers_raw: buyers,
                contract_type: currentContractType,
                template_id: $('#yvo-fp-contract-template').val() || '',
                fp_build: typeof window.YVO_FP_BUILD !== 'undefined' ? window.YVO_FP_BUILD : '',
                fp_js_mtime: (typeof yvo_frontend_ajax !== 'undefined' && yvo_frontend_ajax) ? yvo_frontend_ajax.fp_js_mtime : '',
                fp_plugin_slug: (typeof yvo_frontend_ajax !== 'undefined' && yvo_frontend_ajax) ? yvo_frontend_ajax.fp_plugin_slug : '',
            };
        } catch (eDbg) {}
        if (yvoFpFreePlanBlocksContractType(currentContractType)) {
            yvoFpShowUpgradeModal('generate', currentContractType);
            return;
        }
        if (yvoFpFreePlanBlocksObjectType(($('#property_object_type').val() || 'apartment'))) {
            yvoFpShowUpgradeModal('object', $('#property_object_type').val() || 'apartment');
            return;
        }
        // Подсветить пустые обязательные поля (с *), чтобы было видно, что ещё не заполнено
        yvoFpHighlightEmptyRequired({ scroll: false, toast: false });
        var guardianErr = yvoFpGuardianRequiredErrorForMinorU14();
        if (guardianErr) {
            yvoFpAbortGenerate(guardianErr);
            return;
        }
        // Только заполненные участники: в договор попадают те, у кого указано ФИО (включая несовершеннолетних и опекунов)
        sellers = sellers.filter(function(s) {
            return s && s.full_name && String(s.full_name).trim();
        });
        buyers = buyers.filter(function(b) {
            return b && b.full_name && String(b.full_name).trim();
        });
        if (sellers.length === 0 || buyers.length === 0) {
            var partyMsg = 'Заполните ФИО хотя бы одного продавца и одного покупателя';
            if (currentContractType === 'gift') {
                partyMsg = 'Заполните ФИО хотя бы одного дарителя и одного одаряемого';
            } else if (currentContractType === 'share_allocation') {
                partyMsg = 'Заполните ФИО хотя бы одного участника, отчуждающего долю, и одного участника, получающего долю';
            }
            yvoFpAbortGenerate(partyMsg);
            return;
        }
        if (yvoFpPrincipalPartiesLookLikeDuplicate(sellers, buyers)) {
            var dupMsg = (currentContractType === 'gift')
                ? 'Данные одаряемого совпадают с дарителем (ФИО и паспорт). Заполните вкладку «Покупатель» отдельно.'
                : 'Данные покупателя совпадают с продавцом (ФИО и паспорт). Перейдите на вкладку «Покупатель» и заполните её отдельно.';
            yvoFpAbortGenerate(dupMsg, { focusTab: 'buyer' });
            return;
        }
        var seller = sellers[0];
        var buyer = buyers[0];
        var propAddress = getPropertyAddressForValidation();
        if (!propAddress) {
            yvoFpAbortGenerate('Укажите адрес объекта', { focusTab: 'property' });
            return;
        }
        var priceOk = !!(property.price && String(property.price).trim()) || !!(property.loan_amount && String(property.loan_amount).trim());
        if (currentContractType === 'share_allocation') {
            var pps = property.purchase_price_shares && String(property.purchase_price_shares).trim();
            if (pps && parseFloat(pps) > 0) {
                priceOk = true;
            }
        }
        if (!priceOk && currentContractType !== 'gift') {
            yvoFpAbortGenerate('Укажите цену договора или сумму кредита', { focusTab: 'property' });
            return;
        }
        if (currentContractType === 'gift') {
            if (yvoFpGiftNeedsShareDistribution()) {
                yvoFpGiftDistSyncFromParticipantShares();
                var giftRows = yvoFpGiftDistCollectFromDom();
                var giftVal = yvoFpValidateGiftDistributions(giftRows);
                if (!giftVal.ok) {
                    yvoFpAbortGenerate(giftVal.msg || 'Проверьте условия распределения долей.', {
                        focusTab: giftVal.focusTab || 'property',
                        shareMsg: giftVal.msg
                    });
                    return;
                }
                yvoFpSyncPanelsFromGiftDistributions(giftRows);
                yvoSyncSellersSharesHidden();
                property.gift_distributions = yvoFpGiftDistRowsForSave(giftRows);
                $('#property_gift_distributions').val(JSON.stringify(property.gift_distributions));
                property.share_participants = [];
                yvoShareMatrixParticipantIds().forEach(function(tid) {
                    var row = collectFormData(tid);
                    var isSeller = /^seller/.test(tid) || /^minor_seller/.test(tid);
                    property.share_participants.push({
                        tab: tid,
                        role: isSeller ? 'seller' : 'buyer',
                        full_name: (row.full_name || '').trim(),
                        share_fraction: (row.share_fraction || '').trim()
                    });
                });
                var donorTid = yvoFpFirstPrincipalSellerTabId();
                if (yvoFpGiftUsesDolyaKvartiraTemplate(property)) {
                    var donorShare = ($('#yvo-fp-panel-' + donorTid).find('[data-key="share_fraction"]').val() || '').trim();
                    if (donorShare) {
                        property.share_in_right = donorShare;
                    }
                }
            } else {
                property.gift_distributions = [];
                property.share_participants = [];
                $('#property_gift_distributions').val('[]');
            }
        }
        if (currentContractType === 'share_allocation') {
            yvoSyncSellersSharesHidden();
            var allocVal = yvoFpValidateAllocShares();
            if (!allocVal.ok) {
                yvoFpAbortGenerate(allocVal.msg, { focusTab: 'property', shareMsg: allocVal.msg });
                return;
            }
            var finalRows = yvoFpComputeAllocFinalShareRows();
            property.share_joint_ownership = yvoFpAllocIsJointOwnership() ? 1 : 0;
            property.share_participants = finalRows.map(function(r) {
                return {
                    tab: r.tab,
                    role: r.role,
                    full_name: (r.full_name || '').trim(),
                    share_fraction: (r.final_fraction || '').trim(),
                    joint_ownership: r.joint_ownership ? 1 : 0
                };
            });
        }
        if (currentContractType === 'share_allocation') {
            $('#yvo-fp-contract-template').val('shablon-vydelenie-doley-kvartira');
        }
        yvoFpUpdateRegFieldsMissingState();

        hideError();
        $generateProgress.show();
        $contractResult.hide();

        var templateId = $('#yvo-fp-contract-template').val() || '';
        if (currentContractType === 'sale' || currentContractType === 'sale_mortgage') {
            templateId = yvoFpResolveDkpTemplateIdFromPayment(property) || templateId;
            if (templateId) {
                $('#yvo-fp-contract-template').val(templateId);
            }
        }
        if (currentContractType === 'preliminary') {
            templateId = 'preliminary';
            $('#yvo-fp-contract-template').val(templateId);
        }
        if (currentContractType === 'gift') {
            templateId = yvoFpGiftUsesDolyaKvartiraTemplate(property)
                ? 'shablon-darenie-dolya-kvartira'
                : 'shablon-darenie-dogovor';
            $('#yvo-fp-contract-template').val(templateId);
        }
        if (currentContractType === 'deposit_agreement') {
            templateId = yvoFpDepositTemplateIdForObjectType($('#property_object_type').val() || 'apartment');
            $('#yvo-fp-contract-template').val(templateId);
        }
        if (currentContractType === 'advance_agreement') {
            templateId = yvoFpAdvanceTemplateIdForObjectType($('#property_object_type').val() || 'apartment');
            $('#yvo-fp-contract-template').val(templateId);
        }
        $('.yvo-fp-egrn-check-block [data-egrn-check-key]').each(function() {
            var key = $(this).data('egrn-check-key');
            var val = ($(this).val() || '').trim();
            if (!key || !val) return;
            if (key === 'restrictions_summary' || key === 'encumbrance_type') {
                property[key] = val;
            }
            if (key === 'restrictions_summary' && /ипотек|№|обременен/i.test(val) && !property.encumbrance_record) {
                property.encumbrance_record = val;
            }
        });
        var allowActReceipt = (currentContractType === 'sale' || currentContractType === 'sale_mortgage' || currentContractType === 'assignment' || currentContractType === 'deposit_agreement' || currentContractType === 'advance_agreement');
        var generateAct = allowActReceipt && $('#yvo-fp-generate-act').prop('checked');
        var generateReceipt = allowActReceipt && $('#yvo-fp-generate-receipt').prop('checked');

        var postData = {
            action: 'yvo_frontend_generate_contract',
            nonce: yvo_frontend_ajax.nonce,
            seller_data: JSON.stringify(seller),
            buyer_data: JSON.stringify(buyer),
            sellers_data: JSON.stringify(sellers),
            buyers_data: JSON.stringify(buyers),
            property_data: JSON.stringify(property),
            template_id: templateId,
            contract_type: contractTypeForPost,
            bank_id: ($('#yvo-fp-bank').length ? $('#yvo-fp-bank').val() : '') || 'standard',
            generate_act: generateAct ? '1' : '0',
            generate_receipt: generateReceipt ? '1' : '0'
        };
        if (customContractTemplateContent) {
            postData.custom_template_content = customContractTemplateContent;
        }
        $.post(yvo_frontend_ajax.ajax_url, postData, 'json').done(function(res) {
            $generateProgress.hide();
            if (res.success && res.data && res.data.contract_url) {
                window.yvoLastContractContent = res.data.contract_content || '';
                window.yvoLastContractUrl = res.data.contract_url;
                window.yvoLastContractDocxUrl = res.data.contract_docx_url || '';
                window.yvoLastContractDocUrl = res.data.contract_doc_url || '';
                window.yvoLastContractHtmlUrl = res.data.contract_html_url || '';
                window.yvoLastContractPdfUrl = res.data.contract_pdf_url || '';
                window.yvoLastActUrl = res.data.act_url || '';
                window.yvoLastActDocxUrl = res.data.act_docx_url || '';
                window.yvoLastReceiptUrl = res.data.receipt_url || '';
                window.yvoLastReceiptDocxUrl = res.data.receipt_docx_url || '';
                window.yvoLastActContent = '';
                window.yvoLastReceiptContent = '';
                window.yvoLastGenerateDebug = res.data.debug || null;
                $contractResult.find('.yvo-fp-success-msg').text(res.data.message || 'Договор создан');
                try {
                    var $dbg = $('#yvo-fp-debug-info');
                    if (!$dbg.length) {
                        $dbg = $('<pre id="yvo-fp-debug-info" style="margin-top:10px;white-space:pre-wrap;background:#0b1220;color:#d7e2ff;padding:10px;border-radius:8px;font-size:12px;line-height:1.35;max-height:240px;overflow:auto;"></pre>');
                        $contractResult.prepend($dbg);
                    }
                    if (res.data.debug) {
                        $dbg.text('DEBUG:\n' + JSON.stringify(res.data.debug, null, 2)).show();
                    } else {
                        $dbg.hide();
                    }
                } catch (eDbgShow) {}
                $('#yvo-fp-download-link').attr('href', res.data.contract_url).show();
                var $docxLink = $('#yvo-fp-download-docx-link');
                if (res.data.contract_docx_url) {
                    $docxLink.attr('href', res.data.contract_docx_url).show();
                } else {
                    $docxLink.hide();
                }
                var $docLink = $('#yvo-fp-download-doc-link');
                if (res.data.contract_doc_url) {
                    $docLink.attr('href', res.data.contract_doc_url).show();
                } else {
                    $docLink.hide();
                }
                var $pdfLink = $('#yvo-fp-download-pdf-link');
                if (res.data.contract_pdf_url) {
                    $pdfLink.attr('href', res.data.contract_pdf_url).show();
                } else {
                    $pdfLink.hide();
                }
                var $htmlLink = $('#yvo-fp-download-html-link');
                if (res.data.contract_html_url) {
                    $htmlLink.attr('href', res.data.contract_html_url).show();
                } else {
                    $htmlLink.hide();
                }
                var $actLink = $('#yvo-fp-download-act-link');
                var $receiptLink = $('#yvo-fp-download-receipt-link');
                var $actDocxLink = $('#yvo-fp-download-act-docx-link');
                var $receiptDocxLink = $('#yvo-fp-download-receipt-docx-link');
                if (currentContractType === 'deposit_agreement') {
                    $actLink.text('Скачать соглашение о задатке');
                    $receiptLink.text('Скачать расписку в получении задатка');
                } else if (currentContractType === 'advance_agreement') {
                    $receiptLink.text('Скачать расписку в получении аванса');
                } else {
                    $actLink.text('Скачать акт приёма-передачи');
                    $receiptLink.text('Скачать расписку');
                }
                if (res.data.act_url) {
                    $actLink.attr('href', res.data.act_url).show();
                    $('#yvo-fp-edit-act-btn').show();
                } else {
                    $actLink.hide();
                    $('#yvo-fp-edit-act-btn').hide();
                }
                if (res.data.act_docx_url) {
                    $actDocxLink.attr('href', res.data.act_docx_url).show();
                } else {
                    $actDocxLink.hide();
                }
                if (res.data.receipt_url) {
                    $receiptLink.attr('href', res.data.receipt_url).show();
                    $('#yvo-fp-edit-receipt-btn').show();
                } else {
                    $receiptLink.hide();
                    $('#yvo-fp-edit-receipt-btn').hide();
                }
                if (res.data.receipt_docx_url) {
                    $receiptDocxLink.attr('href', res.data.receipt_docx_url).show();
                } else {
                    $receiptDocxLink.hide();
                }
                $contractResult.show();
                $contractResult[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                $('#yvo-fp-check-contract-btn').show();
                yvoFpSaveDraft(true);
            } else {
                showError(res.data && res.data.message ? res.data.message : 'Ошибка генерации', { persist: true });
            }
        }).fail(function(xhr) {
            $generateProgress.hide();
            var msg = 'Ошибка сервера';
            try {
                var j = JSON.parse(xhr.responseText);
                if (j.data && j.data.message) msg = j.data.message;
            } catch (e) {}
            showError(msg, { persist: true });
        });
        } catch (genErr) {
            $generateProgress.hide();
            yvoFpAbortGenerate('Не удалось подготовить генерацию: ' + (genErr && genErr.message ? genErr.message : String(genErr)));
        }
    });

    // ——— Редактор договора: открытие, список незаполненного, подстановка, сохранение ———
    var $editorModal = $('#yvo-fp-contract-editor-modal');
    var $editorText = $('#yvo-fp-contract-editor-text');
    var $editorPlaceholdersList = $('#yvo-fp-editor-placeholders-list');
    var $editorTitle = $('#yvo-fp-editor-modal-title');
    var $editorTextLabel = $('#yvo-fp-editor-text-label');
    var currentEditorDocType = 'contract'; // contract | act | receipt

    var placeholderLabels = {
        'номер': 'Номер договора / кредитного договора',
        'дата': 'Дата',
        'сумма': 'Сумма (руб.)',
        'количество': 'Количество (например, дней)',
        'количество комнат': 'Количество комнат',
        'наименование банка': 'Наименование банка',
        'размер долей': 'Размер долей',
        'дата регистрации права': 'Дата регистрации права',
        'данные о праве собственности Продавцов': 'Данные о праве собственности',
        'этаж': 'Этаж'
    };
    var placeholderHints = {
        'номер': 'Например: номер кредитного договора',
        'дата': 'Дата подписания кредитного договора (дд.мм.гггг)',
        'сумма': 'Сумма в рублях цифрами',
        'количество': 'Например: количество дней на передачу по акту',
        'количество комнат': 'Число комнат в квартире',
        'наименование банка': 'Например: ПАО Сбербанк',
        'размер долей': 'Например: 1/2, 1/3',
        'дата регистрации права': 'Дата регистрации в ЕГРН (дд.мм.гггг)',
        'данные о праве собственности Продавцов': 'Номер и дата гос. регистрации права',
        'этаж': 'Этаж расположения помещения',
        'прочерк': 'Введите значение вместо прочерка'
    };
    function inferUnderscoreFieldLabel(line, raw) {
        var ctx = (line || '').trim();
        if (/жилой\s+площадью/i.test(ctx)) return 'Жилая площадь (кв. м)';
        if (/подтверждается/i.test(ctx)) return 'Сведения о праве собственности (ЕГРН)';
        if (/«\d+»\s*_/.test(ctx) || /г\.\s*$/i.test(ctx) && raw.length >= 10) return 'Месяц заключения договора';
        if (/г\.\s*_/i.test(ctx) && /ул\./i.test(ctx)) return 'Город (адрес квартиры)';
        if (/ул\.\s*_/i.test(ctx)) return 'Улица (адрес квартиры)';
        if (/д\.\s*_/i.test(ctx) && /кв\./i.test(ctx)) return 'Номер дома';
        if (/кв\.\s*_/i.test(ctx)) return 'Номер квартиры';
        if (/зарегистрирован/i.test(ctx) || /по адресу/i.test(ctx)) return 'Адрес регистрации стороны';
        if (/рублей\)\s*рублей/i.test(ctx) || /\([^)]*_\s*\)\s*рублей/i.test(ctx)) return 'Сумма прописью';
        if (/одаряемой\s+_/i.test(ctx)) return 'ФИО одаряемого (порядок пользования, п.5)';
        if (/комната\s+размером\s+_/i.test(ctx)) return 'Площадь комнаты (кв. м), п.5';
        if (/гр\.\s+_/i.test(ctx)) return 'ФИО второго одаряемого (п.5)';
        if (/дарител/i.test(ctx) && /_/i.test(ctx)) return 'Подпись / строка дарителя';
        if (/опекун/i.test(ctx) && /_/i.test(ctx)) return 'Подпись опекуна';
        var idx = ctx.indexOf(raw);
        if (idx < 0) idx = 0;
        var snippet = ctx.substring(Math.max(0, idx - 45), Math.min(ctx.length, idx + raw.length + 25)).replace(/\s+/g, ' ').trim();
        return snippet ? snippet : ('Прочерк (' + raw.length + ' симв.)');
    }
    function extractPlaceholders(text) {
        var seen = {};
        var list = [];
        (text || '').replace(/\[([^\]]*)\]/g, function(m, key) {
            key = key.trim();
            if (key && !seen[key]) { seen[key] = true; list.push({ raw: '[' + key + ']', key: key, label: key }); }
            return m;
        });
        var lines = (text || '').split(/\r?\n/);
        lines.forEach(function(line, lineIdx) {
            var re = /(_{3,})/g;
            var match;
            while ((match = re.exec(line)) !== null) {
                var raw = match[1];
                var label = inferUnderscoreFieldLabel(line, raw);
                var uid = lineIdx + '|' + raw + '|' + label;
                if (!seen[uid]) {
                    seen[uid] = true;
                    list.push({ raw: raw, key: label, label: label });
                }
            }
        });
        return list;
    }
    function buildPlaceholdersList() {
        var text = $editorText.val() || '';
        var placeholders = extractPlaceholders(text);
        $editorPlaceholdersList.removeClass('empty-hint').empty();
        if (placeholders.length === 0) {
            $editorPlaceholdersList.addClass('empty-hint').text('Незаполненных полей не найдено (нет [плейсхолдеров] или длинных прочерков).');
            return;
        }
        placeholders.forEach(function(p) {
            var label = p.label || placeholderLabels[p.key] || p.key || p.raw;
            var hint = placeholderHints[p.key] || (p.label && p.label.length > 20 ? p.label : '');
            var $row = $('<div class="yvo-fp-placeholder-row"></div>');
            $row.append('<span class="yvo-fp-placeholder-label">' + $('<div>').text(label).html() + '</span>');
            var $input = $('<input type="text" class="yvo-fp-placeholder-input" data-placeholder-raw="">').attr('data-placeholder-raw', p.raw);
            $row.append($input);
            if (hint) $row.append('<span class="yvo-fp-placeholder-hint">' + $('<div>').text(hint).html() + '</span>');
            $editorPlaceholdersList.append($row);
        });
    }
    function applyPlaceholdersToContract() {
        var text = $editorText.val() || '';
        $editorPlaceholdersList.find('.yvo-fp-placeholder-input').each(function() {
            var raw = $(this).data('placeholder-raw');
            var val = $(this).val().trim();
            if (raw && val) {
                text = text.split(raw).join(val);
            }
        });
        $editorText.val(text);
        buildPlaceholdersList();
    }

    function getDocState(docType) {
        if (docType === 'act') return { title: 'Редактирование акта', label: 'Текст акта', content: window.yvoLastActContent, url: window.yvoLastActUrl };
        if (docType === 'receipt') return { title: 'Редактирование расписки', label: 'Текст расписки', content: window.yvoLastReceiptContent, url: window.yvoLastReceiptUrl };
        return { title: 'Редактирование договора', label: 'Текст договора', content: window.yvoLastContractContent, url: window.yvoLastContractUrl };
    }
    function setDocState(docType, content, url) {
        if (docType === 'act') { window.yvoLastActContent = content || ''; window.yvoLastActUrl = url || ''; return; }
        if (docType === 'receipt') { window.yvoLastReceiptContent = content || ''; window.yvoLastReceiptUrl = url || ''; return; }
        window.yvoLastContractContent = content || ''; window.yvoLastContractUrl = url || '';
    }
    function openEditorFor(docType) {
        currentEditorDocType = docType || 'contract';
        var st = getDocState(currentEditorDocType);
        if ($editorTitle.length) $editorTitle.text(st.title);
        if ($editorTextLabel.length) $editorTextLabel.text(st.label);
        $('#yvo-fp-editor-save-btn').text(currentEditorDocType === 'contract' ? 'Сохранить договор' : currentEditorDocType === 'act' ? 'Сохранить акт' : 'Сохранить расписку');

        var content = st.content || '';
        if (!content && st.url) {
            $editorText.val('Загрузка...');
            $editorModal.show();
            $editorPlaceholdersList.empty().addClass('empty-hint').text('Загрузка...');
            $.get(st.url).done(function(t) {
                $editorText.val(t);
                setDocState(currentEditorDocType, t, st.url);
                buildPlaceholdersList();
            }).fail(function() {
                $editorText.val('');
                $editorPlaceholdersList.text('Не удалось загрузить текст.');
                showError('Не удалось загрузить текст документа.');
            });
        } else {
            $editorText.val(content || '');
            $editorModal.show();
            buildPlaceholdersList();
        }
    }

    $('#yvo-fp-edit-contract-btn').on('click', function() { openEditorFor('contract'); });
    $('#yvo-fp-edit-act-btn').on('click', function() { openEditorFor('act'); });
    $('#yvo-fp-edit-receipt-btn').on('click', function() { openEditorFor('receipt'); });

    $editorModal.find('.yvo-fp-modal-close, .yvo-fp-modal-backdrop').on('click', function() {
        $editorModal.hide();
    });
    $('#yvo-fp-editor-apply-placeholders-btn').on('click', function() {
        applyPlaceholdersToContract();
    });
    $('#yvo-fp-editor-save-btn').on('click', function() {
        var content = $editorText.val().trim();
        if (content.length < 10) {
            showError('Введите или вставьте текст документа.');
            return;
        }
        var $btn = $(this).prop('disabled', true).text('Сохранение...');

        if (currentEditorDocType === 'contract') {
            $.post(yvo_frontend_ajax.ajax_url, {
                action: 'yvo_save_edited_contract',
                nonce: yvo_frontend_ajax.nonce,
                contract_content: content
            }, 'json').done(function(res) {
                $btn.prop('disabled', false).text('Сохранить договор');
                if (res.success && res.data) {
                    window.yvoLastContractContent = res.data.contract_content || content;
                    window.yvoLastContractUrl = res.data.contract_url || '';
                    window.yvoLastContractDocxUrl = res.data.contract_docx_url || '';
                    $('#yvo-fp-download-link').attr('href', res.data.contract_url).show();
                    if (res.data.contract_docx_url) {
                        $('#yvo-fp-download-docx-link').attr('href', res.data.contract_docx_url).show();
                    }
                    $error.addClass('yvo-fp-toast-success').css('background', '').css('color', '').css('border-color', '');
                    $error.text('Договор сохранён. Ссылки на скачивание обновлены.').show();
                    setTimeout(function() { $error.fadeOut(); }, 4000);
                } else {
                    showError(res.data && res.data.message ? res.data.message : 'Ошибка сохранения');
                }
            }).fail(function(xhr) {
                $btn.prop('disabled', false).text('Сохранить договор');
                var err = 'Ошибка сервера';
                try { var j = JSON.parse(xhr.responseText); if (j.data && j.data.message) err = j.data.message; } catch (e) {}
                showError(err);
            });
            return;
        }

        var action = (currentEditorDocType === 'act') ? 'yvo_save_edited_act' : 'yvo_save_edited_receipt';
        var doneLabel = (currentEditorDocType === 'act') ? 'Сохранить акт' : 'Сохранить расписку';
        $.post(yvo_frontend_ajax.ajax_url, {
            action: action,
            nonce: yvo_frontend_ajax.nonce,
            document_content: content
        }, 'json').done(function(res) {
            $btn.prop('disabled', false).text(doneLabel);
            if (res.success && res.data && res.data.url) {
                if (currentEditorDocType === 'act') {
                    window.yvoLastActContent = content;
                    window.yvoLastActUrl = res.data.url;
                    $('#yvo-fp-download-act-link').attr('href', res.data.url).show();
                    $('#yvo-fp-edit-act-btn').show();
                } else {
                    window.yvoLastReceiptContent = content;
                    window.yvoLastReceiptUrl = res.data.url;
                    $('#yvo-fp-download-receipt-link').attr('href', res.data.url).show();
                    $('#yvo-fp-edit-receipt-btn').show();
                }
                $error.addClass('yvo-fp-toast-success').css('background', '').css('color', '').css('border-color', '');
                $error.text('Документ сохранён. Ссылка на скачивание обновлена.').show();
                setTimeout(function() { $error.fadeOut(); }, 3500);
            } else {
                showError(res.data && res.data.message ? res.data.message : 'Ошибка сохранения');
            }
        }).fail(function(xhr) {
            $btn.prop('disabled', false).text(doneLabel);
            var err = 'Ошибка сервера';
            try { var j = JSON.parse(xhr.responseText); if (j.data && j.data.message) err = j.data.message; } catch (e) {}
            showError(err);
        });
    });
    $('#yvo-fp-editor-recreate-btn').on('click', function() {
        $editorModal.hide();
    });

    // ——— Личный кабинет ———
    var $cabinetBody = $('#yvo-fp-cabinet-body');
    var $cabinetList = $('#yvo-fp-cabinet-list');
    function loadCabinetList() {
        $.post(yvo_frontend_ajax.ajax_url, { action: 'yvo_cabinet_list', nonce: yvo_frontend_ajax.nonce }, 'json').done(function(res) {
            if (res.success && res.data && res.data.items) {
                renderCabinetList(res.data.items);
            } else {
                $cabinetList.html('<div class="yvo-fp-cabinet-empty">Нет сохранённых записей</div>');
            }
        }).fail(function() {
            $cabinetList.html('<div class="yvo-fp-cabinet-empty">Ошибка загрузки</div>');
        });
    }
    function renderCabinetList(items) {
        if (!items || items.length === 0) {
            $cabinetList.html('<div class="yvo-fp-cabinet-empty">Нет сохранённых записей. Сохраните текущие данные или договор кнопками выше.</div>');
            return;
        }
        var typeLabels = { dataset: 'Данные', contract: 'Договор', object: 'Объект', participants: 'Участники', transaction: 'Сделка' };
        var html = '';
        items.forEach(function(item) {
            var typeLabel = typeLabels[item.type] || item.type || 'Данные';
            var canLoad = (item.type === 'dataset' || item.type === 'object' || item.type === 'participants' || item.type === 'transaction');
            html += '<div class="yvo-fp-cabinet-item" data-id="' + (item.id || '') + '" data-type="' + (item.type || '') + '">';
            html += '<div class="yvo-fp-cabinet-item-info"><span class="yvo-fp-cabinet-item-name">' + $('<div>').text(item.name || 'Без названия').html() + '</span><span class="yvo-fp-cabinet-item-meta">' + typeLabel + ' · ' + (item.created || '') + '</span></div>';
            html += '<div class="yvo-fp-cabinet-item-actions">';
            if (canLoad) {
                html += '<button type="button" class="yvo-fp-btn yvo-fp-btn-primary yvo-fp-btn-small yvo-fp-cabinet-load-btn">Автозаполнить форму</button>';
            }
            if ((item.type === 'contract' || item.type === 'transaction') && item.data && item.data.url) {
                html += '<a href="' + (item.data.url || '#') + '" target="_blank" rel="noopener" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-btn-small yvo-fp-cabinet-download-txt">Скачать TXT</a>';
                if (item.data.docx_url) html += '<a href="' + item.data.docx_url + '" target="_blank" rel="noopener" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-btn-small">Скачать DOCX</a>';
            }
            html += '<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-btn-small yvo-fp-cabinet-copy-btn">Копировать</button>';
            html += '<button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-btn-small yvo-fp-cabinet-delete-btn">Удалить</button>';
            html += '</div></div>';
        });
        $cabinetList.html(html);
        yvoCabinetInvalidateCache();
    }

    var yvoCabinetItemsCache = null;
    function yvoCabinetInvalidateCache() {
        yvoCabinetItemsCache = null;
    }
    function cabinetItemHasNamedParticipants(arr) {
        if (!Array.isArray(arr)) return false;
        return arr.some(function(x) { return x && String(x.full_name || '').trim() !== ''; });
    }
    function cabinetItemHasProperty(p) {
        if (!p || typeof p !== 'object') return false;
        return !!(String(p.address || '').trim() || String(p.cadastral_number || '').trim() || String(p.cadastral_num || '').trim());
    }
    function yvoCabinetFilterItemsForScope(items, scope) {
        var out = [];
        var i, it, d, t;
        for (i = 0; i < items.length; i++) {
            it = items[i];
            t = it.type || '';
            d = it.data || {};
            if (scope === 'seller') {
                if (t === 'dataset' || t === 'participants' || t === 'transaction') {
                    if (cabinetItemHasNamedParticipants(d.sellers)) out.push(it);
                }
            } else if (scope === 'buyer') {
                if (t === 'dataset' || t === 'participants' || t === 'transaction') {
                    if (cabinetItemHasNamedParticipants(d.buyers)) out.push(it);
                }
            } else if (scope === 'property') {
                if (t === 'dataset' || t === 'transaction' || t === 'object') {
                    if (cabinetItemHasProperty(d.property)) out.push(it);
                }
            }
        }
        return out;
    }

    // Выбор из кабинета: строим список "что подставить" в текущем контексте.
    var yvoCabinetChoiceMap = {};
    function yvoCabinetResetChoiceMap() {
        yvoCabinetChoiceMap = {};
    }

    function yvoCabinetChoiceId(prefix, a, b, c) {
        return prefix + '_' + String(a || '') + '_' + String(b || '') + '_' + String(c || '');
    }

    function yvoCabinetBuildChoices(items, scope) {
        yvoCabinetResetChoiceMap();
        var choices = [];
        if (!Array.isArray(items)) return choices;

        function addChoice(id, name, meta, kind, payload) {
            var ch = { id: id, name: name || '', meta: meta || '', kind: kind, payload: payload || null };
            yvoCabinetChoiceMap[String(id)] = ch;
            choices.push(ch);
        }

        for (var i = 0; i < items.length; i++) {
            var it = items[i] || {};
            var t = it.type || '';
            var d = it.data || {};

            if (scope === 'property') {
                if (!(t === 'dataset' || t === 'transaction' || t === 'object')) continue;
                if (!d.property || !cabinetItemHasProperty(d.property)) continue;
                var addr = String(d.property.address || '').trim();
                var cad = String(d.property.cadastral_number || d.property.cadastral_num || '').trim();
                var title = addr || cad || (it.name || 'Объект');
                addChoice(
                    yvoCabinetChoiceId('prop', it.id, '', ''),
                    title,
                    (t === 'object' ? 'Объект' : (t === 'transaction' ? 'Сделка' : 'Данные')) + (it.created ? ' · ' + it.created : ''),
                    'property',
                    { property: d.property }
                );
                continue;
            }

            // На вкладках участников: хотим выбирать ЛЮБОГО человека по ФИО (и продавцов, и покупателей) и подставлять в текущую вкладку.
            if (!(t === 'dataset' || t === 'participants' || t === 'transaction')) continue;

            var sellers = Array.isArray(d.sellers) ? d.sellers : [];
            var buyers = Array.isArray(d.buyers) ? d.buyers : [];

            for (var si = 0; si < sellers.length; si++) {
                var s = sellers[si] || {};
                var fioS = String(s.full_name || '').trim();
                if (!fioS) continue;
                addChoice(
                    yvoCabinetChoiceId('person', it.id, 'seller', si),
                    fioS,
                    'Продавец' + (it.created ? ' · ' + it.created : ''),
                    'person',
                    { person: s }
                );
            }
            for (var bi = 0; bi < buyers.length; bi++) {
                var b = buyers[bi] || {};
                var fioB = String(b.full_name || '').trim();
                if (!fioB) continue;
                addChoice(
                    yvoCabinetChoiceId('person', it.id, 'buyer', bi),
                    fioB,
                    'Покупатель' + (it.created ? ' · ' + it.created : ''),
                    'person',
                    { person: b }
                );
            }
        }
        return choices;
    }

    function yvoApplyCabinetChoice(choice, scope) {
        if (!choice || !choice.kind) return;
        if (choice.kind === 'property' && choice.payload && choice.payload.property) {
            fillForm('property', choice.payload.property);
            return;
        }
        if (choice.kind === 'person' && choice.payload && choice.payload.person) {
            var tab = getActiveTab();
            if (tab === 'property') {
                return;
            }
            fillForm(tab, choice.payload.person);
        }
    }
    function yvoCabinetScopeFromActiveTab() {
        var tab = getActiveTab() || '';
        if (tab === 'property') return 'property';
        if (/^(buyer|contributor|minor_buyer|buyer_representative)/.test(tab)) return 'buyer';
        return 'seller';
    }
    function sellerRowIndexFromTab(tab) {
        if (!tab || tab === 'seller' || tab === 'minor_seller' || tab.indexOf('seller_') === 0) return 0;
        var m = /^seller(\d+)$/.exec(tab);
        return m ? (parseInt(m[1], 10) - 1) : 0;
    }
    function buyerRowIndexFromTab(tab) {
        if (!tab || tab === 'buyer' || tab === 'minor_buyer' || tab === 'contributor' || tab.indexOf('buyer_') === 0) return 0;
        var m = /^buyer(\d+)$/.exec(tab);
        return m ? (parseInt(m[1], 10) - 1) : 0;
    }
    function yvoEnsureParticipantTabs(role, count) {
        if (!count || count < 1) return;
        var re = role === 'seller' ? /^seller\d*$/ : /^buyer\d*$/;
        var ids = yvoParticipantTabIdsOrdered().filter(function(id) { return re.test(id); });
        while (ids.length < count) {
            if (typeof window.yvoApplyAddParticipantRole === 'function') {
                window.yvoApplyAddParticipantRole(role);
            } else {
                break;
            }
            ids = yvoParticipantTabIdsOrdered().filter(function(id) { return re.test(id); });
        }
    }

    /** Подстановка из кабинета: заполняем ВСЕ данные для выбранного блока, без перемешивания. */
    function yvoApplyCabinetItemScoped(item, scope) {
        var data = item.data || {};
        var type = item.type || '';

        // Для записей «Данные» / «Сделка» обычно ожидают полное заполнение формы (участники + объект),
        // независимо от того, на какой вкладке нажали «Из кабинета».
        if (type === 'dataset' || type === 'transaction') {
            var sellersAll = data.sellers || [];
            var buyersAll = data.buyers || [];
            if (sellersAll.length) {
                yvoEnsureParticipantTabs('seller', sellersAll.length);
                var sellerIdsAll = yvoParticipantTabIdsOrdered().filter(function(id) { return /^seller\d*$/.test(id); });
                sellersAll.forEach(function(row, i) {
                    if (sellerIdsAll[i] && row) fillForm(sellerIdsAll[i], row);
                });
            }
            if (buyersAll.length) {
                yvoEnsureParticipantTabs('buyer', buyersAll.length);
                var buyerIdsAll = yvoParticipantTabIdsOrdered().filter(function(id) { return /^buyer\d*$/.test(id); });
                buyersAll.forEach(function(row, i) {
                    if (buyerIdsAll[i] && row) fillForm(buyerIdsAll[i], row);
                });
            }
            if (data.property && Object.keys(data.property).length) {
                fillForm('property', data.property);
            }
            return;
        }

        // «Участники» — заполняем обе стороны.
        if (type === 'participants') {
            var ss = data.sellers || [];
            var bb = data.buyers || [];
            if (ss.length) {
                yvoEnsureParticipantTabs('seller', ss.length);
                var sids = yvoParticipantTabIdsOrdered().filter(function(id) { return /^seller\d*$/.test(id); });
                ss.forEach(function(row, i) { if (sids[i] && row) fillForm(sids[i], row); });
            }
            if (bb.length) {
                yvoEnsureParticipantTabs('buyer', bb.length);
                var bids = yvoParticipantTabIdsOrdered().filter(function(id) { return /^buyer\d*$/.test(id); });
                bb.forEach(function(row, i) { if (bids[i] && row) fillForm(bids[i], row); });
            }
            return;
        }

        // «Объект» — только объект.
        if (type === 'object') {
            if (data.property && Object.keys(data.property).length) {
                fillForm('property', data.property);
            }
            return;
        }

        if (scope === 'property') {
            if (data.property && Object.keys(data.property).length) {
                fillForm('property', data.property);
            }
            return;
        }
        if (scope === 'seller') {
            var sellers = data.sellers || [];
            if (!sellers.length) return;
            yvoEnsureParticipantTabs('seller', sellers.length);
            var sellerIds = yvoParticipantTabIdsOrdered().filter(function(id) { return /^seller\d*$/.test(id); });
            sellers.forEach(function(row, i) {
                if (sellerIds[i] && row) fillForm(sellerIds[i], row);
            });
            return;
        }
        if (scope === 'buyer') {
            var buyers = data.buyers || [];
            if (!buyers.length) return;
            yvoEnsureParticipantTabs('buyer', buyers.length);
            var buyerIds = yvoParticipantTabIdsOrdered().filter(function(id) { return /^buyer\d*$/.test(id); });
            buyers.forEach(function(row, i) {
                if (buyerIds[i] && row) fillForm(buyerIds[i], row);
            });
        }
    }
    function yvoCabinetRenderInlineMenu($menu, items) {
        if (!items || items.length === 0) {
            $menu.html('<div class="yvo-fp-cabinet-inline-empty" role="none">Нет сохранённых данных для этого блока. Заполните поля и нажмите «Сохранить».</div>');
            return;
        }
        var html = '';
        items.forEach(function(ch) {
            var name = $('<div>').text(ch.name || 'Без названия').html();
            var meta = $('<div>').text(ch.meta || '').html();
            html += '<button type="button" class="yvo-fp-cabinet-inline-item" role="menuitem" data-id="' + (ch.id || '') + '">';
            html += '<span class="yvo-fp-cabinet-inline-item-name">' + name + '</span>';
            if (meta) html += '<span class="yvo-fp-cabinet-inline-item-meta">' + meta + '</span>';
            html += '</button>';
        });
        $menu.html(html);
    }
    function yvoPositionCabinetInlineMenu($trigger, $menu) {
        if (!$menu || !$menu.length) {
            return;
        }
        if (!window.matchMedia('(max-width: 768px)').matches) {
            $menu.css({ position: '', top: '', left: '', right: '', width: '', maxHeight: '' });
            return;
        }
        var rect = $trigger[0].getBoundingClientRect();
        var gap = 8;
        var top = Math.round(rect.bottom + gap);
        var maxH = Math.max(120, Math.min(280, window.innerHeight - top - 12));
        $menu.css({
            position: 'fixed',
            top: top + 'px',
            left: '10px',
            right: '10px',
            width: 'auto',
            maxHeight: maxH + 'px'
        });
    }
    function yvoResetCabinetInlineMenus() {
        $('.yvo-fp-cabinet-inline-menu').each(function() {
            this.style.position = '';
            this.style.top = '';
            this.style.left = '';
            this.style.right = '';
            this.style.width = '';
            this.style.maxHeight = '';
        });
    }
    function yvoCabinetOpenInlineDropdown($trigger) {
        var $wrap = $trigger.closest('.yvo-fp-cabinet-inline-dropdown');
        var $menu = $wrap.find('.yvo-fp-cabinet-inline-menu').first();
        var scope = yvoCabinetScopeFromActiveTab();
        function renderMenu(items) {
            var filteredRaw = yvoCabinetFilterItemsForScope(items, scope);
            var choices = yvoCabinetBuildChoices(filteredRaw, scope);
            yvoCabinetRenderInlineMenu($menu, choices);
            $menu.removeAttr('hidden');
            $trigger.attr('aria-expanded', 'true');
            yvoPositionCabinetInlineMenu($trigger, $menu);
        }
        yvoEnsureCabinetCache(function(items) {
            renderMenu(items || []);
        });
    }
    $(document).on('click', '.yvo-fp-cabinet-inline-toggle', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $t = $(this);
        var $menu = $t.closest('.yvo-fp-cabinet-inline-dropdown').find('.yvo-fp-cabinet-inline-menu').first();
        var el = $menu[0];
        var isOpen = el && !el.hasAttribute('hidden');
        $('.yvo-fp-cabinet-inline-menu').attr('hidden', true);
        $('.yvo-fp-cabinet-inline-toggle').attr('aria-expanded', 'false');
        yvoResetCabinetInlineMenus();
        if (isOpen) {
            return;
        }
        yvoCabinetOpenInlineDropdown($t);
    });
    $(document).on('click', '.yvo-fp-cabinet-inline-item', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var id = $(this).data('id');
        if (!id) return;
        var scope = yvoCabinetScopeFromActiveTab();
        var ch = yvoCabinetChoiceMap[String(id)] || null;
        if (ch) {
            yvoApplyCabinetChoice(ch, scope);
            $('.yvo-fp-cabinet-inline-menu').attr('hidden', true);
            $('.yvo-fp-cabinet-inline-toggle').attr('aria-expanded', 'false');
            yvoResetCabinetInlineMenus();
            $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Данные подставлены.').show();
            setTimeout(function() { $error.fadeOut(); }, 2500);
            return;
        }
        $.ajax({
            url: yvo_frontend_ajax.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: { action: 'yvo_cabinet_load', nonce: yvo_frontend_ajax.nonce, id: id }
        }).done(function(res) {
            if (!res || !res.success || !res.data || !res.data.item) {
                var msg = (res && res.data && res.data.message) ? res.data.message : 'Запись не найдена';
                showError(msg);
                return;
            }
            yvoApplyCabinetItemScoped(res.data.item, scope);
            $('.yvo-fp-cabinet-inline-menu').attr('hidden', true);
            $('.yvo-fp-cabinet-inline-toggle').attr('aria-expanded', 'false');
            yvoResetCabinetInlineMenus();
            $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Данные из кабинета подставлены.').show();
            setTimeout(function() { $error.fadeOut(); }, 3500);
        }).fail(function() {
            showError('Не удалось загрузить запись кабинета.');
        });
    });

    function yvoCabinetRequireLogin() {
        if (typeof yvo_frontend_ajax !== 'undefined' && Number(yvo_frontend_ajax.user_logged_in) === 1) {
            return true;
        }
        showError('Войдите в аккаунт, чтобы сохранять и подставлять данные из кабинета.');
        return false;
    }

    function yvoCabinetToastOk(msg) {
        $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text(msg || 'Сохранено.').show();
        setTimeout(function() { $error.fadeOut(); }, 2800);
    }

    /** Сохранение текущего блока (участник / объект) — быстро и понятно. */
    function yvoCabinetSaveCurrentBlock($btn) {
        if (!yvoCabinetRequireLogin()) {
            return;
        }
        var scope = yvoCabinetScopeFromActiveTab();
        var tab = getActiveTab() || 'seller';
        var labelDone = ($btn && $btn.length) ? (($btn.data('yvo-label') || $btn.text() || 'Сохранить')) : 'Сохранить';
        if ($btn && $btn.length) {
            if (!$btn.data('yvo-label')) {
                $btn.data('yvo-label', labelDone);
            }
            $btn.prop('disabled', true).text('Сохранение...');
        }
        var payload = { nonce: yvo_frontend_ajax.nonce };
        var okMsg = 'Сохранено в личный кабинет.';
        if (scope === 'property') {
            var property = collectFormData('property');
            if (!cabinetItemHasProperty(property)) {
                if ($btn && $btn.length) {
                    $btn.prop('disabled', false).text(labelDone);
                }
                showError('Заполните адрес или кадастр объекта перед сохранением.');
                return;
            }
            payload.action = 'yvo_cabinet_save_object';
            payload.property_data = JSON.stringify(property);
            okMsg = 'Объект сохранён в кабинет.';
        } else {
            var person = collectFormData(tab);
            var fio = person && String(person.full_name || '').trim();
            if (!fio) {
                if ($btn && $btn.length) {
                    $btn.prop('disabled', false).text(labelDone);
                }
                showError('Укажите ФИО, чтобы сохранить участника в кабинет.');
                return;
            }
            payload.action = 'yvo_cabinet_save_participants';
            if (scope === 'buyer') {
                payload.sellers_data = '[]';
                payload.buyers_data = JSON.stringify([person]);
                okMsg = 'Покупатель сохранён в кабинет.';
            } else {
                payload.sellers_data = JSON.stringify([person]);
                payload.buyers_data = '[]';
                okMsg = 'Продавец сохранён в кабинет.';
            }
            payload.name = fio;
        }
        $.ajax({
            url: yvo_frontend_ajax.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: payload
        }).done(function(res) {
            if ($btn && $btn.length) {
                $btn.prop('disabled', false).text(labelDone);
            }
            if (!res || !res.success) {
                showError((res && res.data && res.data.message) ? res.data.message : 'Не удалось сохранить.');
                return;
            }
            yvoCabinetInvalidateCache();
            if (res.data && res.data.items) {
                // Черновики обновлены — CRM подмешается при следующем открытии меню.
                yvoEnsureCabinetCache(function() {});
            }
            yvoCabinetToastOk(okMsg);
        }).fail(function(xhr) {
            if ($btn && $btn.length) {
                $btn.prop('disabled', false).text(labelDone);
            }
            var err = 'Не удалось сохранить в кабинет.';
            try {
                var j = JSON.parse(xhr.responseText);
                if (j && j.data && j.data.message) {
                    err = j.data.message;
                }
            } catch (e) {}
            showError(err);
        });
    }

    if ($('#yvo-fp-cabinet-save-btn').length) {
        $('#yvo-fp-cabinet-save-btn').on('click', function() {
            yvoCabinetSaveCurrentBlock($(this));
        });
    }
    $(document).on('click', '#yvo-fp-tab-save', function(e) {
        e.preventDefault();
        yvoCabinetSaveCurrentBlock($(this));
    });

    // ——— Подсказки из кабинета при вводе (ФИО / адрес) ———
    function yvoParsePassportSeriesNumber(passportStr) {
        var s = String(passportStr || '').trim();
        if (!s) return { series: '', number: '' };
        var digits = s.replace(/[^\d]/g, '');
        if (digits.length >= 10) {
            return { series: digits.slice(0, 4), number: digits.slice(4, 10) };
        }
        var m = s.match(/(\d{4})\s*(\d{6})/);
        if (m) return { series: m[1], number: m[2] };
        return { series: '', number: '' };
    }

    function yvoMergeDealCrmIntoCabinetItems(items, deals) {
        if (!Array.isArray(deals) || !deals.length) {
            return items;
        }
        deals.forEach(function(d) {
            var name = (d && d.contract && d.contract.number) ? d.contract.number : ('Сделка #' + (d && d.id ? d.id : ''));
            var sellerName = (d && d.sellers && d.sellers[0] && d.sellers[0].fio) ? d.sellers[0].fio : '';
            var buyerName = (d && d.buyers && d.buyers[0] && d.buyers[0].fio) ? d.buyers[0].fio : '';
            var objAddr = (d && d.object && d.object.address) ? d.object.address : '';
            var label = [sellerName, buyerName].filter(Boolean).join(' → ');
            if (objAddr) label += (label ? ' — ' : '') + objAddr;
            items.push({
                id: 'deal_' + String(d.id || ''),
                type: 'transaction',
                name: name + (label ? (' — ' + label) : ''),
                created: '',
                data: {
                    sellers: (d.sellers || []).map(function(p) {
                        var psn = yvoParsePassportSeriesNumber(p.passport || '');
                        return {
                            full_name: p.fio || '',
                            birth_date: p.birthDate || '',
                            birth_place: p.birthPlace || '',
                            passport_series: psn.series,
                            passport_number: psn.number,
                            passport_issued_by: p.passportIssued || '',
                            passport_date: p.passportDate || '',
                            registration: p.address || ''
                        };
                    }),
                    buyers: (d.buyers || []).map(function(p) {
                        var psn = yvoParsePassportSeriesNumber(p.passport || '');
                        return {
                            full_name: p.fio || '',
                            birth_date: p.birthDate || '',
                            birth_place: p.birthPlace || '',
                            passport_series: psn.series,
                            passport_number: psn.number,
                            passport_issued_by: p.passportIssued || '',
                            passport_date: p.passportDate || '',
                            registration: p.address || ''
                        };
                    }),
                    property: {
                        address: objAddr || '',
                        cadastral_number: (d.object && d.object.cadastre) ? d.object.cadastre : '',
                        area: (d.object && d.object.area) ? String(d.object.area).replace(/[^\d.,]/g, '') : ''
                    }
                }
            });
        });
        return items;
    }

    function yvoEnsureCabinetCache(cb) {
        if (yvoCabinetItemsCache) {
            cb(yvoCabinetItemsCache);
            return;
        }
        if (!yvo_frontend_ajax || Number(yvo_frontend_ajax.user_logged_in) !== 1) {
            yvoCabinetItemsCache = [];
            cb([]);
            return;
        }
        var doneCabinet = false;
        var doneCrm = false;
        var cabinetItems = [];
        var crmDeals = [];
        function finish() {
            if (!doneCabinet || !doneCrm) {
                return;
            }
            var items = cabinetItems.slice();
            yvoMergeDealCrmIntoCabinetItems(items, crmDeals);
            yvoCabinetItemsCache = items;
            cb(items);
        }
        $.ajax({
            url: yvo_frontend_ajax.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: { action: 'yvo_cabinet_list', nonce: yvo_frontend_ajax.nonce }
        }).done(function(res) {
            cabinetItems = (res && res.success && res.data && res.data.items) ? res.data.items : [];
        }).always(function() {
            doneCabinet = true;
            finish();
        });
        $.ajax({
            url: yvo_frontend_ajax.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: { action: 'yvo_deal_crm_list', nonce: yvo_frontend_ajax.nonce }
        }).done(function(res2) {
            if (res2 && res2.success && res2.data && Array.isArray(res2.data.deals)) {
                crmDeals = res2.data.deals;
            }
        }).always(function() {
            doneCrm = true;
            finish();
        });
    }

    // Прогрев кэша кабинета сразу после загрузки формы.
    setTimeout(function() {
        try {
            yvoEnsureCabinetCache(function() {});
        } catch (ePrefetch) {}
    }, 400);

    function yvoPanelTabIdFromEl(el) {
        var $p = $(el).closest('.yvo-fp-panel');
        var pid = ($p.attr('id') || '');
        if (pid.indexOf('yvo-fp-panel-') === 0) {
            return pid.slice('yvo-fp-panel-'.length);
        }
        return getActiveTab();
    }

    function yvoScopeFromTabId(tabId) {
        if (tabId === 'property') return 'property';
        if (/^buyer\d*$/.test(tabId) || tabId === 'buyer') return 'buyer';
        return 'seller';
    }

    function yvoGetSuggestionWrap($field) {
        var $w = $field.closest('.yvo-fp-field');
        if (!$w.length) return $();
        $w.css('position', 'relative');
        var $s = $w.find('.yvo-fp-cabinet-suggest').first();
        if ($s.length) return $s;
        $s = $('<div class="yvo-fp-cabinet-suggest" hidden />').css({
            position: 'absolute',
            left: 0,
            right: 0,
            top: '100%',
            marginTop: '6px',
            zIndex: 100000,
            background: '#fff',
            border: '1px solid rgba(0,0,0,.12)',
            borderRadius: '10px',
            boxShadow: '0 10px 30px rgba(0,0,0,.08)',
            maxHeight: '260px',
            overflow: 'auto'
        });
        $w.append($s);
        return $s;
    }

    function yvoRenderSuggestItems($box, items, scope) {
        if (!items.length) {
            $box.attr('hidden', 'hidden').empty();
            return;
        }
        var html = '';
        items.forEach(function(it) {
            var name = $('<div>').text(it.name || 'Без названия').html();
            var meta = $('<div>').text(it.meta || '').html();
            html += '<button type="button" class="yvo-fp-cabinet-inline-item yvo-fp-cabinet-suggest-item" data-id="' + (it.id || '') + '" data-scope="' + scope + '" style="width:100%;text-align:left;">'
                + '<span class="yvo-fp-cabinet-inline-item-name">' + name + '</span>'
                + '<span class="yvo-fp-cabinet-inline-item-meta">' + meta + '</span>'
                + '</button>';
        });
        $box.html(html).removeAttr('hidden');
    }

    var yvoSuggestTimer = null;
    $(document).on('input focus', '#yvo-fp-tab-panels [data-key="full_name"], #yvo-fp-tab-panels [data-key="address"]', function() {
        var el = this;
        clearTimeout(yvoSuggestTimer);
        yvoSuggestTimer = setTimeout(function() {
            var q = String($(el).val() || '').trim().toLowerCase();
            if (q.length < 2) {
                yvoGetSuggestionWrap($(el)).attr('hidden', 'hidden').empty();
                return;
            }
            var tabId = yvoPanelTabIdFromEl(el);
            var scope = $(el).data('key') === 'address' ? 'property' : yvoScopeFromTabId(tabId);
            yvoEnsureCabinetCache(function(items) {
                var filteredRaw = yvoCabinetFilterItemsForScope(items, scope);
                var choices = yvoCabinetBuildChoices(filteredRaw, scope).filter(function(ch) {
                    var nm = String(ch.name || '').toLowerCase();
                    return nm.indexOf(q) !== -1;
                }).slice(0, 8);
                yvoRenderSuggestItems(yvoGetSuggestionWrap($(el)), choices, scope);
            });
        }, 180);
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.yvo-fp-cabinet-suggest, .yvo-fp-field').length) {
            $('.yvo-fp-cabinet-suggest').attr('hidden', 'hidden').empty();
        }
    });

    $(document).on('click', '.yvo-fp-cabinet-suggest-item', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var id = $(this).data('id');
        var scope = $(this).data('scope') || yvoCabinetScopeFromActiveTab();
        if (!id) return;
        var $box = $(this).closest('.yvo-fp-cabinet-suggest');
        var ch = yvoCabinetChoiceMap[String(id)] || null;
        if (ch) {
            yvoApplyCabinetChoice(ch, scope);
            $box.attr('hidden', 'hidden').empty();
            yvoCabinetToastOk('Данные подставлены.');
            return;
        }
        $.post(yvo_frontend_ajax.ajax_url, { action: 'yvo_cabinet_load', nonce: yvo_frontend_ajax.nonce, id: id }, 'json').done(function(res) {
            if (!res.success || !res.data || !res.data.item) return;
            yvoApplyCabinetItemScoped(res.data.item, scope);
            $box.attr('hidden', 'hidden').empty();
            yvoCabinetToastOk('Данные из кабинета подставлены.');
        });
    });
    if ($('#yvo-fp-cabinet-save-contract-btn').length) $('#yvo-fp-cabinet-save-contract-btn').on('click', function() {
        var content = window.yvoLastContractContent || '';
        if (content.length < 10) { showError('Сначала сгенерируйте или откройте договор в редакторе.'); return; }
        var $btn = $(this).prop('disabled', true).text('Сохранение...');
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_cabinet_save_contract',
            nonce: yvo_frontend_ajax.nonce,
            contract_content: content,
            contract_url: window.yvoLastContractUrl || '',
            contract_docx_url: window.yvoLastContractDocxUrl || ''
        }, 'json').done(function(res) {
            $btn.prop('disabled', false).text('Сохранить договор в кабинет');
            if (res.success && res.data && res.data.items) renderCabinetList(res.data.items);
        }).fail(function() { $btn.prop('disabled', false).text('Сохранить договор в кабинет'); });
    });
    if ($('#yvo-fp-cabinet-save-object-btn').length) $('#yvo-fp-cabinet-save-object-btn').on('click', function() {
        var property = collectFormData('property');
        var $btn = $(this).prop('disabled', true).text('Сохранение...');
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_cabinet_save_object',
            nonce: yvo_frontend_ajax.nonce,
            property_data: JSON.stringify(property)
        }, 'json').done(function(res) {
            $btn.prop('disabled', false).text('Сохранить только объект');
            if (res.success && res.data && res.data.items) { renderCabinetList(res.data.items); $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Объект сохранён в кабинет.').show(); setTimeout(function() { $error.fadeOut(); }, 3000); }
        }).fail(function() { $btn.prop('disabled', false).text('Сохранить только объект'); });
    });
    if ($('#yvo-fp-cabinet-save-participants-btn').length) $('#yvo-fp-cabinet-save-participants-btn').on('click', function() {
        var pb = yvoFpCollectSellersAndBuyersInTabOrder();
        var sellers = pb.sellers;
        var buyers = pb.buyers;
        var $btn = $(this).prop('disabled', true).text('Сохранение...');
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_cabinet_save_participants',
            nonce: yvo_frontend_ajax.nonce,
            sellers_data: JSON.stringify(sellers),
            buyers_data: JSON.stringify(buyers)
        }, 'json').done(function(res) {
            $btn.prop('disabled', false).text('Сохранить только участников');
            if (res.success && res.data && res.data.items) { renderCabinetList(res.data.items); $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Участники сохранены в кабинет.').show(); setTimeout(function() { $error.fadeOut(); }, 3000); }
        }).fail(function() { $btn.prop('disabled', false).text('Сохранить только участников'); });
    });
    if ($('#yvo-fp-cabinet-save-transaction-btn').length) $('#yvo-fp-cabinet-save-transaction-btn').on('click', function() {
        var content = window.yvoLastContractContent || '';
        if (content.length < 10) { showError('Сначала сгенерируйте договор, затем нажмите «Сохранить сделку».'); return; }
        var property = collectFormData('property');
        var pb = yvoFpCollectSellersAndBuyersInTabOrder();
        var sellers = pb.sellers;
        var buyers = pb.buyers;
        var $btn = $(this).prop('disabled', true).text('Сохранение...');
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_cabinet_save_transaction',
            nonce: yvo_frontend_ajax.nonce,
            sellers_data: JSON.stringify(sellers),
            buyers_data: JSON.stringify(buyers),
            property_data: JSON.stringify(property),
            contract_content: content,
            contract_url: window.yvoLastContractUrl || '',
            contract_docx_url: window.yvoLastContractDocxUrl || ''
        }, 'json').done(function(res) {
            $btn.prop('disabled', false).text('Сохранить сделку (участники + договор)');
            if (res.success && res.data && res.data.items) { renderCabinetList(res.data.items); $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Сделка сохранена в кабинет.').show(); setTimeout(function() { $error.fadeOut(); }, 3000); }
        }).fail(function() { $btn.prop('disabled', false).text('Сохранить сделку (участники + договор)'); });
    });
    $(document).on('click', '.yvo-fp-cabinet-load-btn', function() {
        var id = $(this).closest('.yvo-fp-cabinet-item').data('id');
        $.post(yvo_frontend_ajax.ajax_url, { action: 'yvo_cabinet_load', nonce: yvo_frontend_ajax.nonce, id: id }, 'json').done(function(res) {
            if (!res.success || !res.data || !res.data.item) return;
            var item = res.data.item;
            var data = item.data || {};
            function sellerTabIds() {
                var ids = [];
                yvoFpParticipantTabButtons().each(function() {
                    var t = $(this).attr('data-tab');
                    if (t === 'seller') ids.push({ id: t, n: 1 });
                    else if (typeof t === 'string' && /^seller(\d+)$/.test(t)) ids.push({ id: t, n: parseInt(t.replace('seller', '') || '1', 10) });
                });
                ids.sort(function(a, b) { return a.n - b.n; });
                return ids.map(function(x) { return x.id; });
            }
            function buyerTabIds() {
                var ids = [];
                yvoFpParticipantTabButtons().each(function() {
                    var t = $(this).attr('data-tab');
                    if (t === 'buyer') ids.push({ id: t, n: 1 });
                    else if (typeof t === 'string' && /^buyer(\d+)$/.test(t)) ids.push({ id: t, n: parseInt(t.replace('buyer', '') || '1', 10) });
                });
                ids.sort(function(a, b) { return a.n - b.n; });
                return ids.map(function(x) { return x.id; });
            }
            var sIds = sellerTabIds();
            var sellers = data.sellers || [];
            while (sIds.length < (sellers.length || 1)) {
                ensureParticipantTabForRole('seller');
                sIds = sellerTabIds();
            }
            var bIds = buyerTabIds();
            var buyers = data.buyers || [];
            while (bIds.length < (buyers.length || 1)) {
                ensureParticipantTabForRole('buyer');
                bIds = buyerTabIds();
            }
            if (sellers.length) {
                sellers.forEach(function(s, i) {
                    if (sIds[i]) fillForm(sIds[i], s);
                });
            }
            if (buyers.length) {
                buyers.forEach(function(b, i) {
                    if (bIds[i]) fillForm(bIds[i], b);
                });
            }
            if (data.property && Object.keys(data.property).length) fillForm('property', data.property);
            if (data.sellers || data.buyers || data.property) yvoSetCabinetCopiedData(data);
            if (item.type === 'transaction' && data.content) {
                window.yvoLastContractContent = data.content;
                window.yvoLastContractUrl = data.url || '';
                window.yvoLastContractDocxUrl = data.docx_url || '';
                $('#yvo-fp-contract-editor-text').val(data.content);
                if (data.url) $('#yvo-fp-download-link').attr('href', data.url).show();
                if (data.docx_url) $('#yvo-fp-download-docx-link').attr('href', data.docx_url).show();
                $contractResult.show();
            }
            if (item.type === 'contract' && data.content) {
                window.yvoLastContractContent = data.content;
                window.yvoLastContractUrl = data.url || '';
                window.yvoLastContractDocxUrl = data.docx_url || '';
                $('#yvo-fp-contract-editor-text').val(data.content);
                if (data.url) $('#yvo-fp-download-link').attr('href', data.url).show();
                if (data.docx_url) $('#yvo-fp-download-docx-link').attr('href', data.docx_url).show();
                $contractResult.show();
            }
            $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Данные загружены в форму.').show();
            setTimeout(function() { $error.fadeOut(); }, 3500);
        });
    });
    $(document).on('click', '.yvo-fp-cabinet-copy-btn', function() {
        var $item = $(this).closest('.yvo-fp-cabinet-item');
        var id = $item.data('id');
        $.post(yvo_frontend_ajax.ajax_url, { action: 'yvo_cabinet_load', nonce: yvo_frontend_ajax.nonce, id: id }, 'json').done(function(res) {
            if (res.success && res.data && res.data.item && navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(JSON.stringify(res.data.item.data, null, 2));
                yvoSetCabinetCopiedData(res.data.item.data);
                $error.addClass('yvo-fp-toast-success').css({ background: '', color: '', borderColor: '' }).text('Скопировано в буфер обмена').show();
                setTimeout(function() { $error.fadeOut(); }, 2000);
            }
        });
    });
    $(document).on('click', '.yvo-fp-cabinet-delete-btn', function() {
        var id = $(this).closest('.yvo-fp-cabinet-item').data('id');
        if (!id || !confirm('Удалить эту запись?')) return;
        $.post(yvo_frontend_ajax.ajax_url, { action: 'yvo_cabinet_delete', nonce: yvo_frontend_ajax.nonce, id: id }, 'json').done(function(res) {
            if (res.success && res.data && res.data.items) renderCabinetList(res.data.items);
        });
    });

    // Совместимость со старым шорткодом [yandex_ocr_form]
    $('#yvo-upload-form').on('submit', function(e) {
        e.preventDefault();
        var file = $('#yvo-file')[0].files[0];
        if (!file) {
            alert('Выберите файл');
            return;
        }
        if (typeof yvo_frontend_ajax === 'undefined') return;
        var maxSize = yvo_frontend_ajax.max_size || (20 * 1024 * 1024);
        if (file.size > maxSize) {
            alert('Файл слишком большой. Макс. ' + (yvo_frontend_ajax.max_size_mb || 20) + ' МБ');
            return;
        }
        $('.yvo-progress').show();
        $('#yvo-results').hide();
        $('#yvo-error').hide();
        var formData = new FormData();
        formData.append('action', 'yvo_process_upload');
        formData.append('nonce', yvo_frontend_ajax.nonce);
        formData.append('file', file);
        $.ajax({
            url: yvo_frontend_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                $('.yvo-progress').hide();
                if (response.success) {
                    $('#yvo-text-result').val(response.data.text);
                    $('#yvo-results').show();
                    $('#yvo-passport-data').hide();
                } else {
                    $('#yvo-error').text(response.data && response.data.message ? response.data.message : 'Ошибка').show();
                }
            },
            error: function() {
                $('.yvo-progress').hide();
                $('#yvo-error').text('Ошибка сервера').show();
            }
        });
    });

    $('#yvo-parse-data').on('click', function() {
        var text = $('#yvo-text-result').val();
        if (!text.trim()) { alert('Сначала распознайте текст'); return; }
        var $btn = $(this).prop('disabled', true).text('Обработка...');
        $.post(yvo_frontend_ajax.ajax_url, {
            action: 'yvo_parse_passport_data',
            nonce: yvo_frontend_ajax.nonce,
            text: text
        }, 'json').done(function(response) {
            if (response.success && response.data) {
                var d = response.data;
                $('#yvo-fio').val(d.fio || '');
                $('#yvo-series').val(d.series_number || '');
                $('#yvo-code').val(d.department_code || '');
                $('#yvo-issued').val(d.issued_by || '');
                $('#yvo-birth').val(d.birth_date || '');
                $('#yvo-issue').val(d.issue_date || '');
                $('#yvo-passport-data').show();
            } else {
                alert(response.data && response.data.message ? response.data.message : 'Ошибка извлечения');
            }
        }).always(function() {
            $btn.prop('disabled', false).text('Извлечь паспортные данные');
        });
    });

    yvoSyncDokiObjectTypePills();
    yvoFpSyncDokiParticipantTabStrip();
    yvoFpInitDraftPersistence();

    window.yvoFpFillForm = fillForm;
    window.yvoFpEnsureParticipantTab = ensureParticipantTabForRole;
    window.yvoFpEnsureParticipantTabById = ensureParticipantTab;
    window.yvoFpBuildParticipantTabLabelFn = yvoFpBuildParticipantTabLabel;
    window.yvoFpGetActiveParticipantTab = getActiveTab;
    window.yvoFpShowError = showError;
    window.yvoFpPrepareParsedForFormFn = yvoFpPrepareParsedForForm;
    window.yvoFpFillEgrnCheckBlockFn = yvoFpFillEgrnCheckBlock;
    window.yvoFpFillOwnershipHistoryFn = yvoFpFillOwnershipHistory;
    window.yvoFpFillAddressFromFullFn = yvoFpFillAddressFromFullInActiveBlock;
    window.yvoFpTabIdAndMinorOptsFromCompositeRoleFn = yvoFpTabIdAndMinorOptsFromCompositeRole;
    window.yvoFpFirstSellerSideTabFn = yvoFpFirstSellerSideTab;
    window.yvoFpFirstBuyerSideTabFn = yvoFpFirstBuyerSideTab;
    window.yvoFpDokiFocusParticipantFn = yvoFpDokiFocusParticipant;
    window.yvoFpUpdateDeleteButtonVisibilityFn = updateDeleteButtonVisibility;
    window.yvoFpShowToastOk = showFpToastOk;

    window.yvoFpHandleTabAddClick = yvoFpHandleTabAddClick;
    window.yvoFpAddRoleFromActiveTab = yvoFpAddRoleFromActiveTab;

    try {
        yvoFpApplyFreePlanUiLocks();
    } catch (yvoLockErr) {}

    } catch (yvoFpInitErr) {
        if (window.console && console.error) {
            console.error('YVO frontend init:', yvoFpInitErr);
        }
    } finally {
        try {
            if (typeof yvoFpHandleTabAddClick === 'function') {
                window.yvoFpHandleTabAddClick = yvoFpHandleTabAddClick;
            }
        } catch (yvoFpFin1) {}
        try {
            if (typeof yvoFpAddRoleFromActiveTab === 'function') {
                window.yvoFpAddRoleFromActiveTab = yvoFpAddRoleFromActiveTab;
            }
        } catch (yvoFpFin2) {}
        window.yvoFpParticipantTabAddReady = true;
    }

    }); // end DOM ready
})(jQuery);
