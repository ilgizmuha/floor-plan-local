/**
 * Перепроверка полей формы после подстановки OCR/парсера.
 * Зависит от jQuery; хуки вызываются из frontend.js.
 */
(function(window, $) {
    'use strict';

    window.yvoFpLastOcrText = window.yvoFpLastOcrText || '';
    window.yvoFpFieldReviewAiIssues = window.yvoFpFieldReviewAiIssues || [];

    var NAME_STOP = /Минцифры|Росреестр|Действителен|Сертификат|Владелец|Электронн|Получатель|Федерация|Правительств|Постановлен/i;
    var reviewTimer = null;

    function yvoFpSetLastOcrText(text) {
        window.yvoFpLastOcrText = String(text || '').trim();
        var $t = $('#yvo-fp-text');
        if ($t.length && window.yvoFpLastOcrText && !String($t.val() || '').trim()) {
            $t.val(window.yvoFpLastOcrText);
        }
    }

    function yvoFpGetOcrText() {
        var cached = String(window.yvoFpLastOcrText || '').trim();
        if (cached.length > 20) {
            return cached;
        }
        var fromField = String($('#yvo-fp-text').val() || '').trim();
        if (fromField.length > 20) {
            window.yvoFpLastOcrText = fromField;
            return fromField;
        }
        return cached || fromField;
    }

    function normText(s) {
        return String(s || '')
            .toLowerCase()
            .replace(/ё/g, 'е')
            .replace(/[«»"'„“”]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function digitsOnly(s) {
        return String(s || '').replace(/\D+/g, '');
    }

    function parseRuDate(s) {
        var m = String(s || '').trim().match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
        if (!m) return null;
        var d = parseInt(m[1], 10);
        var mo = parseInt(m[2], 10) - 1;
        var y = parseInt(m[3], 10);
        var dt = new Date(y, mo, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== mo || dt.getDate() !== d) {
            return null;
        }
        return dt;
    }

    function valueInOcr(value, ocr) {
        var v = normText(value);
        if (!v || v.length < 2) return true;
        var hay = normText(ocr);
        if (!hay) return true;
        if (hay.indexOf(v) !== -1) return true;
        // digits-only (passport/snils/cadastral)
        var vd = digitsOnly(v);
        if (vd.length >= 4 && hay.replace(/\D+/g, '').indexOf(vd) !== -1) return true;
        // FIO tokens
        var parts = v.split(' ').filter(function(p) { return p.length > 2; });
        if (parts.length >= 2) {
            var hit = 0;
            parts.forEach(function(p) {
                if (hay.indexOf(p) !== -1) hit++;
            });
            if (hit >= Math.min(2, parts.length)) return true;
        }
        return false;
    }

    function pushIssue(issues, tabId, fieldKey, severity, code, message, value) {
        issues.push({
            tabId: tabId,
            fieldKey: fieldKey,
            severity: severity,
            code: code,
            message: message,
            value: value == null ? '' : String(value)
        });
    }

    function readPanelFields($panel) {
        var data = {};
        $panel.find('[data-key]').each(function() {
            var key = $(this).data('key');
            if (!key) return;
            var val = $(this).val();
            if (val == null) return;
            val = String(val).trim();
            if (val !== '') data[key] = val;
        });
        return data;
    }

    function validatePersonFields(tabId, data, ocr, issues) {
        var name = data.full_name || '';
        if (name) {
            var tokens = name.split(/\s+/).filter(Boolean);
            if (tokens.length < 2 || tokens.length > 5) {
                pushIssue(issues, tabId, 'full_name', 'error', 'name_tokens', 'ФИО: ожидается 2–4 слова', name);
            }
            if (NAME_STOP.test(name)) {
                pushIssue(issues, tabId, 'full_name', 'error', 'name_stop', 'ФИО похоже на служебную строку документа', name);
            }
            if (ocr && !valueInOcr(name, ocr)) {
                pushIssue(issues, tabId, 'full_name', 'warn', 'not_in_ocr', 'ФИО не найдено в тексте документа', name);
            }
        }

        var series = data.passport_series || '';
        if (series) {
            var sd = digitsOnly(series);
            if (sd.length !== 4) {
                pushIssue(issues, tabId, 'passport_series', 'error', 'passport_series', 'Серия паспорта: 4 цифры', series);
            } else if (ocr && !valueInOcr(series, ocr) && !valueInOcr(sd, ocr)) {
                pushIssue(issues, tabId, 'passport_series', 'warn', 'not_in_ocr', 'Серия не найдена в тексте документа', series);
            }
        }
        var number = data.passport_number || '';
        if (number) {
            var nd = digitsOnly(number);
            if (nd.length !== 6) {
                pushIssue(issues, tabId, 'passport_number', 'error', 'passport_number', 'Номер паспорта: 6 цифр', number);
            } else if (ocr && !valueInOcr(number, ocr) && !valueInOcr(nd, ocr)) {
                pushIssue(issues, tabId, 'passport_number', 'warn', 'not_in_ocr', 'Номер паспорта не найден в тексте документа', number);
            }
        }
        var dept = data.department_code || '';
        if (dept && !/^\d{3}-\d{3}$/.test(dept)) {
            pushIssue(issues, tabId, 'department_code', 'error', 'department_code', 'Код подразделения: формат XXX-XXX', dept);
        }

        ['passport_date', 'birth_date'].forEach(function(key) {
            var raw = data[key];
            if (!raw) return;
            var dt = parseRuDate(raw);
            if (!dt) {
                pushIssue(issues, tabId, key, 'error', 'date_format', 'Дата: формат ДД.ММ.ГГГГ', raw);
                return;
            }
            var today = new Date();
            today.setHours(23, 59, 59, 999);
            if (dt > today) {
                pushIssue(issues, tabId, key, 'error', 'date_future', 'Дата не может быть в будущем', raw);
            }
        });

        var snils = data.snils || '';
        if (snils) {
            var sn = digitsOnly(snils);
            if (sn.length !== 11 && !/^\d{3}-\d{3}-\d{3}\s*\d{2}$/.test(snils.trim())) {
                pushIssue(issues, tabId, 'snils', 'error', 'snils', 'СНИЛС: формат XXX-XXX-XXX XX', snils);
            } else if (ocr && !valueInOcr(snils, ocr) && sn.length === 11 && !valueInOcr(sn, ocr)) {
                pushIssue(issues, tabId, 'snils', 'warn', 'not_in_ocr', 'СНИЛС не найден в тексте документа', snils);
            }
        }

        var inn = data.inn || '';
        if (inn) {
            var id = digitsOnly(inn);
            if (id.length !== 10 && id.length !== 12) {
                pushIssue(issues, tabId, 'inn', 'error', 'inn', 'ИНН: 10 или 12 цифр', inn);
            }
        }
    }

    function validatePropertyFields(data, ocr, issues) {
        var tabId = 'property';
        var cad = data.cadastral_number || '';
        if (cad) {
            if (!/^\d{2}:\d{2}:\d{6,}:\d+$/.test(cad.replace(/\s+/g, ''))) {
                pushIssue(issues, tabId, 'cadastral_number', 'error', 'cadastral', 'Кадастровый номер: формат XX:XX:……:…', cad);
            } else if (ocr && !valueInOcr(cad, ocr)) {
                pushIssue(issues, tabId, 'cadastral_number', 'warn', 'not_in_ocr', 'Кадастровый номер не найден в тексте документа', cad);
            }
        }
        var ot = String(data.object_type || '').toLowerCase();
        var isApt = ot === 'apartment' || ot === 'room' || /квартир|помещен/i.test(String(data.property_type || ''));
        if (isApt || data.apartment) {
            if (!data.street && !data.address) {
                pushIssue(issues, tabId, 'street', 'warn', 'addr_street', 'Для квартиры желательно указать улицу', '');
            }
            if (!data.house && !data.address) {
                pushIssue(issues, tabId, 'house', 'warn', 'addr_house', 'Для квартиры желательно указать дом', '');
            }
            if (!data.apartment && isApt) {
                pushIssue(issues, tabId, 'apartment', 'warn', 'addr_apt', 'Не указан номер квартиры', '');
            }
        }
        if (data.address && ocr && !valueInOcr(data.address, ocr)) {
            // address often reformatted — only warn if core tokens missing
            var core = [data.street, data.house, data.apartment].filter(Boolean).join(' ');
            if (core && !valueInOcr(core, ocr)) {
                pushIssue(issues, tabId, 'address', 'warn', 'not_in_ocr', 'Адрес слабо совпадает с текстом документа', data.address);
            }
        }
        if (data.area) {
            var areaNum = parseFloat(String(data.area).replace(',', '.'));
            if (!(areaNum > 0) || areaNum > 1000000) {
                pushIssue(issues, tabId, 'area', 'error', 'area', 'Площадь выглядит некорректно', data.area);
            }
        }
    }

    /**
     * @param {string} [scopeTabId] optional: only this panel
     * @returns {Array}
     */
    function yvoFpValidateFilledFields(scopeTabId) {
        var issues = [];
        var ocr = yvoFpGetOcrText();
        var $panels = scopeTabId
            ? $('#yvo-fp-panel-' + scopeTabId)
            : $('#yvo-fp-tab-panels .yvo-fp-panel, #yvo-fp-panel-property');

        $panels.each(function() {
            var $panel = $(this);
            var id = ($panel.attr('id') || '').replace(/^yvo-fp-panel-/, '');
            if (!id) return;
            var data = readPanelFields($panel);
            if (!Object.keys(data).length) return;
            if (id === 'property') {
                validatePropertyFields(data, ocr, issues);
            } else {
                validatePersonFields(id, data, ocr, issues);
            }
        });

        // merge AI issues that still match current values
        (window.yvoFpFieldReviewAiIssues || []).forEach(function(ai) {
            if (!ai || !ai.fieldKey) return;
            var $inp = $('#yvo-fp-panel-' + (ai.tabId || '') + ' [data-key="' + ai.fieldKey + '"]');
            if (!$inp.length) return;
            var cur = String($inp.val() || '').trim();
            if (ai.value && cur && normText(ai.value) !== normText(cur)) return;
            var dup = issues.some(function(x) {
                return x.tabId === ai.tabId && x.fieldKey === ai.fieldKey && x.code === ai.code;
            });
            if (!dup) issues.push(ai);
        });

        return issues;
    }

    function clearHighlights() {
        $('.yvo-fp-field-warn, .yvo-fp-field-error').removeClass('yvo-fp-field-warn yvo-fp-field-error');
        $('.yvo-fp-field').removeClass('yvo-fp-field-has-warn yvo-fp-field-has-error');
    }

    function applyHighlights(issues) {
        clearHighlights();
        issues.forEach(function(iss) {
            var $inp = $('#yvo-fp-panel-' + iss.tabId + ' [data-key="' + iss.fieldKey + '"]').first();
            if (!$inp.length) return;
            $inp.addClass(iss.severity === 'error' ? 'yvo-fp-field-error' : 'yvo-fp-field-warn');
            $inp.closest('.yvo-fp-field').addClass(iss.severity === 'error' ? 'yvo-fp-field-has-error' : 'yvo-fp-field-has-warn');
        });
    }

    function tabLabel(tabId) {
        if (tabId === 'property') return 'Объект';
        var $btn = $('.yvo-fp-tab[data-tab="' + tabId + '"]').first();
        var t = ($btn.text() || '').trim();
        return t || tabId;
    }

    function yvoFpRenderFieldReview(issues) {
        var $box = $('#yvo-fp-field-review');
        var $list = $('#yvo-fp-field-review-list');
        var $title = $('#yvo-fp-field-review-title');
        if (!$box.length || !$list.length) return;

        issues = issues || [];
        applyHighlights(issues);

        if (!issues.length) {
            $box.hide().attr('hidden', true);
            $list.empty();
            return;
        }

        var errors = issues.filter(function(i) { return i.severity === 'error'; }).length;
        var warns = issues.length - errors;
        var title = 'Проверка полей: ' + issues.length;
        if (errors) title += ' (ошибок: ' + errors + ')';
        else if (warns) title += ' (замечаний: ' + warns + ')';
        if ($title.length) $title.text(title);

        $list.empty();
        issues.forEach(function(iss, idx) {
            var $li = $('<button type="button" class="yvo-fp-field-review-item"></button>');
            $li.addClass(iss.severity === 'error' ? 'is-error' : 'is-warn');
            $li.attr('data-idx', idx);
            $li.attr('data-tab', iss.tabId);
            $li.attr('data-key', iss.fieldKey);
            var label = tabLabel(iss.tabId) + ' · ' + iss.fieldKey;
            $li.append($('<span class="yvo-fp-field-review-item__where"></span>').text(label));
            $li.append($('<span class="yvo-fp-field-review-item__msg"></span>').text(iss.message));
            if (iss.value) {
                $li.append($('<span class="yvo-fp-field-review-item__val"></span>').text(iss.value));
            }
            $list.append($li);
        });

        $box.show().removeAttr('hidden');
    }

    function yvoFpRunFieldReview(scopeTabId) {
        var issues = yvoFpValidateFilledFields(scopeTabId);
        yvoFpRenderFieldReview(issues);
        return issues;
    }

    function yvoFpScheduleFieldReview() {
        if (reviewTimer) clearTimeout(reviewTimer);
        reviewTimer = setTimeout(function() {
            yvoFpRunFieldReview();
        }, 300);
    }

    function focusIssue(tabId, fieldKey) {
        var $panel = $('#yvo-fp-panel-' + tabId);
        var $inp = $panel.find('[data-key="' + fieldKey + '"]').first();
        if ($('.yvo-doki-form-skin').length && typeof window.yvoFpDokiFocusParticipantFn === 'function') {
            window.yvoFpDokiFocusParticipantFn(tabId);
        } else {
            $('.yvo-fp-tab').removeClass('active');
            $('.yvo-fp-panel').removeClass('active');
            $('.yvo-fp-tab[data-tab="' + tabId + '"]').addClass('active');
            $panel.addClass('active');
        }
        if ($inp.length) {
            try {
                $inp[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (e) { /* ignore */ }
            $inp.trigger('focus');
        }
    }

    function collectFormPayload() {
        var persons = [];
        $('#yvo-fp-tab-panels .yvo-fp-panel').each(function() {
            var id = ($(this).attr('id') || '').replace(/^yvo-fp-panel-/, '');
            if (!id || id === 'property') return;
            var data = readPanelFields($(this));
            if (data.full_name || data.passport_number || data.inn) {
                data._tab = id;
                persons.push(data);
            }
        });
        var property = {};
        if ($('#yvo-fp-panel-property').length) {
            property = readPanelFields($('#yvo-fp-panel-property'));
        }
        return { persons: persons, property: property };
    }

    function yvoFpAiReviewFields() {
        var $btn = $('#yvo-fp-field-review-ai');
        var $status = $('#yvo-fp-field-review-ai-status');
        if (typeof yvo_frontend_ajax === 'undefined' || !yvo_frontend_ajax.ajax_url) {
            if ($status.length) $status.text('AJAX не настроен').show();
            return;
        }
        var ocr = yvoFpGetOcrText();
        if (ocr.length < 40) {
            if ($status.length) $status.text('Нет текста документа для сверки. Сначала загрузите файл.').show();
            return;
        }
        var payload = collectFormPayload();
        $btn.prop('disabled', true);
        if ($status.length) $status.text('ИИ проверяет поля…').show();
        $.ajax({
            url: yvo_frontend_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            timeout: 90000,
            data: {
                action: 'yvo_frontend_review_fields',
                nonce: yvo_frontend_ajax.nonce,
                ocr_text: ocr.slice(0, 28000),
                fields_json: JSON.stringify(payload)
            }
        }).done(function(res) {
            if (!res || !res.success) {
                var msg = (res && res.data && res.data.message) ? res.data.message : 'Не удалось выполнить ИИ-проверку';
                if ($status.length) $status.text(msg).show();
                return;
            }
            var list = (res.data && res.data.issues) ? res.data.issues : [];
            window.yvoFpFieldReviewAiIssues = list.map(function(it) {
                return {
                    tabId: it.tab || it.tabId || '',
                    fieldKey: it.field || it.fieldKey || '',
                    severity: it.severity === 'error' ? 'error' : 'warn',
                    code: 'ai_' + (it.code || 'review'),
                    message: it.message || 'ИИ: возможная ошибка',
                    value: it.value || it.suggested_value || ''
                };
            }).filter(function(it) { return it.tabId && it.fieldKey; });
            var n = yvoFpRunFieldReview();
            if ($status.length) {
                $status.text('ИИ: добавлено замечаний — ' + window.yvoFpFieldReviewAiIssues.length +
                    '; всего сейчас: ' + n.length).show();
            }
        }).fail(function() {
            if ($status.length) $status.text('Сеть: ИИ-проверка не выполнена').show();
        }).always(function() {
            $btn.prop('disabled', false);
        });
    }

    // UI events
    $(document).on('click', '.yvo-fp-field-review-item', function() {
        focusIssue($(this).attr('data-tab'), $(this).attr('data-key'));
    });
    $(document).on('click', '#yvo-fp-field-review-ai', function() {
        yvoFpAiReviewFields();
    });
    $(document).on('input change blur', '#yvo-fp-tab-panels [data-key], #yvo-fp-panel-property [data-key]', function() {
        if (!$('#yvo-fp-field-review').length) return;
        // только если панель уже была показана или есть OCR
        if (yvoFpGetOcrText().length > 20 || $('#yvo-fp-field-review').is(':visible')) {
            yvoFpScheduleFieldReview();
        }
    });

    window.yvoFpSetLastOcrText = yvoFpSetLastOcrText;
    window.yvoFpGetOcrText = yvoFpGetOcrText;
    window.yvoFpValidateFilledFields = yvoFpValidateFilledFields;
    window.yvoFpRenderFieldReview = yvoFpRenderFieldReview;
    window.yvoFpRunFieldReview = yvoFpRunFieldReview;
    window.yvoFpScheduleFieldReview = yvoFpScheduleFieldReview;
    window.yvoFpAiReviewFields = yvoFpAiReviewFields;

})(window, jQuery);
