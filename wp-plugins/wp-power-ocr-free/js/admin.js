jQuery(document).ready(function($) {
    // Выбор документа из медиабиблиотеки
    $('#yvo-browse').click(function(e) {
        e.preventDefault();
        
        var frame = wp.media({
            title: 'Выберите документ',
            multiple: false,
            library: { 
                type: ['image', 'application/pdf']
            }
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#yvo-document-url').val(attachment.url);
        });
        
        frame.open();
    });
    
    // Обработка документа
    $('#yvo-process').click(function() {
        var documentUrl = $('#yvo-document-url').val().trim();
        var documentType = $('#yvo-document-type').val();
        var useDeepseek = $('#yvo-use-deepseek').is(':checked');
        
        if (!documentUrl) {
            alert('Пожалуйста, введите URL документа или выберите из медиабиблиотеки');
            return;
        }
        
        // Показываем индикатор загрузки
        $('.yvo-progress').show();
        $('#yvo-results').html('');
        $('#yvo-data-forms').hide();
        
        // Отправляем AJAX запрос
        $.ajax({
            url: yvo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'yvo_process_document',
                nonce: yvo_ajax.nonce,
                document_url: documentUrl,
                document_type: documentType,
                use_deepseek: useDeepseek
            },
            dataType: 'json',
            success: function(response) {
                $('.yvo-progress').hide();
                
                if (response.success) {
                    var html = '<div class="yvo-result success">';
                    html += '<h3>Результат распознавания:</h3>';
                    
                    if (response.data.document_type === 'general') {
                        html += '<div class="yvo-text-result">';
                        html += '<textarea readonly>' + response.data.text + '</textarea>';
                        html += '<div class="yvo-actions">';
                        html += '<button class="button button-primary yvo-copy" data-text="' + response.data.text.replace(/"/g, '&quot;') + '">Копировать текст</button>';
                        html += '<button class="button yvo-download" data-text="' + response.data.text.replace(/"/g, '&quot;') + '" data-filename="' + response.data.filename.replace(/"/g, '&quot;') + '">Скачать как TXT</button>';
                        html += '</div>';
                        html += '</div>';
                    } else {
                        html += '<p><strong>Файл:</strong> ' + response.data.filename + '</p>';
                        
                        // Показываем используемый парсер
                        if (response.data.parser === 'deepseek') {
                            html += '<p><small>Использован: <span style="color: #4CAF50;">DeepSeek AI парсер</span></small></p>';
                        } else if (response.data.parser === 'regex') {
                            html += '<p><small>Использован: <span style="color: #FF9800;">Регулярные выражения</span></small></p>';
                        }
                        
                        // Автозаполнение формы
                        if (response.data.parsed_data) {
                            $('#yvo-data-forms').show();
                            
                            // Скрываем все формы
                            $('.yvo-form-section').hide();
                            
                            // Показываем нужную форму
                            $('#' + response.data.document_type + '-form').show();
                            
                            // Заполняем форму распарсенными данными
                            var formData = response.data.parsed_data;
                            var formPrefix = response.data.document_type;
                            
                            for (var key in formData) {
                                var inputName = formPrefix + '_' + key;
                                var value = formData[key];
                                if (value && value !== 'null') {
                                    $('input[name="' + inputName + '"], textarea[name="' + inputName + '"]').val(value);
                                }
                            }
                            
                            html += '<div class="notice notice-success"><p>Данные успешно извлечены и заполнены в форму ниже.</p></div>';
                        }
                    }
                    
                    html += '</div>';
                    
                    $('#yvo-results').html(html);
                } else {
                    $('#yvo-results').html('<div class="yvo-result error"><p><strong>Ошибка:</strong> ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $('.yvo-progress').hide();
                $('#yvo-results').html('<div class="yvo-result error"><p>Ошибка сервера. Пожалуйста, попробуйте еще раз.</p></div>');
            }
        });
    });
    
    // Сохранение данных формы
    $(document).on('click', '.yvo-save-form', function() {
        var formType = $(this).data('form');
        var formData = {};
        
        $('#' + formType + '-data-form').find('input, textarea').each(function() {
            var name = $(this).attr('name').replace(formType + '_', '');
            formData[name] = $(this).val();
        });
        
        $.ajax({
            url: yvo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'yvo_save_form_data',
                nonce: yvo_ajax.nonce,
                form_type: formType,
                form_data: formData
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert('Данные сохранены успешно!');
                } else {
                    alert('Ошибка сохранения: ' + response.data);
                }
            }
        });
    });
    
    // Загрузка сохраненных данных
    $(document).on('click', '.yvo-load-data', function() {
        var dataType = $(this).data('type');
        
        $.ajax({
            url: yvo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'yvo_load_form_data',
                nonce: yvo_ajax.nonce,
                data_type: dataType
            },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    // Показываем форму
                    $('#yvo-data-forms').show();
                    $('.yvo-form-section').hide();
                    $('#' + dataType + '-form').show();
                    
                    // Заполняем форму
                    var formData = response.data;
                    for (var key in formData) {
                        var inputName = dataType + '_' + key;
                        $('input[name="' + inputName + '"], textarea[name="' + inputName + '"]').val(formData[key]);
                    }
                } else {
                    alert('Нет сохраненных данных для этого типа');
                }
            }
        });
    });
    
    // Генерация договора
    $('#yvo-generate-contract, #yvo-generate-contract-full').click(function() {
        $.ajax({
            url: yvo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'yvo_generate_contract',
                nonce: yvo_ajax.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var html = '<div class="notice notice-success">' +
                        '<p>✅ Договор успешно сгенерирован!</p><p>';
                    if (response.data.contract_docx_url) {
                        html += '<a href="' + response.data.contract_docx_url + '" class="button button-primary" target="_blank">Скачать для печати (DOCX)</a> ';
                    }
                    html += '<a href="' + response.data.contract_url + '" class="button button-secondary" target="_blank">Скачать TXT</a></p></div>';
                    $('#yvo-contract-result, #yvo-contract-result-full').html(html);
                } else {
                    $('#yvo-contract-result, #yvo-contract-result-full').html(
                        '<div class="notice notice-error"><p>❌ ' + response.data + '</p></div>'
                    );
                }
            }
        });
    });
    
    // Копирование текста
    $(document).on('click', '.yvo-copy', function() {
        var text = $(this).data('text');
        var tempInput = $('<textarea>');
        $('body').append(tempInput);
        tempInput.val(text).select();
        document.execCommand('copy');
        tempInput.remove();
        alert('Текст скопирован в буфер обмена');
    });
    
    // Скачивание текста
    $(document).on('click', '.yvo-download', function() {
        var text = $(this).data('text');
        var filename = $(this).data('filename').replace(/\.[^/.]+$/, "") + '.txt';
        var element = document.createElement('a');
        element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(text));
        element.setAttribute('download', filename);
        element.style.display = 'none';
        document.body.appendChild(element);
        element.click();
        document.body.removeChild(element);
    });
});