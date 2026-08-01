<?php
$seller_data = yvo_get_form_data('seller');
$buyer_data = yvo_get_form_data('buyer');
$property_data = yvo_get_form_data('property');
?>
<div class="wrap">
    <h1>Данные сделки</h1>
    
    <div class="yvo-container">
        <div class="yvo-card">
            <h2>Продавец</h2>
            <div class="yvo-data-display">
                <table class="yvo-data-table">
                    <tr>
                        <td>ФИО:</td>
                        <td><strong><?php echo esc_html($seller_data['full_name']); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Паспорт:</td>
                        <td><?php echo esc_html($seller_data['passport_series']); ?> №<?php echo esc_html($seller_data['passport_number']); ?></td>
                    </tr>
                    <tr>
                        <td>Кем выдан:</td>
                        <td><?php echo esc_html($seller_data['passport_issued_by']); ?></td>
                    </tr>
                    <tr>
                        <td>Дата выдачи:</td>
                        <td><?php echo esc_html($seller_data['passport_date']); ?></td>
                    </tr>
                    <tr>
                        <td>Дата рождения:</td>
                        <td><?php echo esc_html($seller_data['birth_date']); ?></td>
                    </tr>
                    <tr>
                        <td>Место рождения:</td>
                        <td><?php echo esc_html($seller_data['birth_place']); ?></td>
                    </tr>
                    <tr>
                        <td>Прописка:</td>
                        <td><?php echo esc_html($seller_data['registration']); ?></td>
                    </tr>
                    <tr>
                        <td>ИНН:</td>
                        <td><?php echo esc_html($seller_data['inn']); ?></td>
                    </tr>
                    <tr>
                        <td>СНИЛС:</td>
                        <td><?php echo esc_html($seller_data['snils']); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="yvo-form-actions">
                <button type="button" class="button yvo-load-data" data-type="seller">Загрузить данные</button>
                <button type="button" class="button button-secondary yvo-edit-data" data-type="seller">Редактировать</button>
                <button type="button" class="button button-link-delete yvo-clear-data" data-type="seller">Очистить</button>
            </div>
        </div>
        
        <div class="yvo-card">
            <h2>Покупатель</h2>
            <div class="yvo-data-display">
                <table class="yvo-data-table">
                    <tr>
                        <td>ФИО:</td>
                        <td><strong><?php echo esc_html($buyer_data['full_name']); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Паспорт:</td>
                        <td><?php echo esc_html($buyer_data['passport_series']); ?> №<?php echo esc_html($buyer_data['passport_number']); ?></td>
                    </tr>
                    <tr>
                        <td>Кем выдан:</td>
                        <td><?php echo esc_html($buyer_data['passport_issued_by']); ?></td>
                    </tr>
                    <tr>
                        <td>Дата выдачи:</td>
                        <td><?php echo esc_html($buyer_data['passport_date']); ?></td>
                    </tr>
                    <tr>
                        <td>Дата рождения:</td>
                        <td><?php echo esc_html($buyer_data['birth_date']); ?></td>
                    </tr>
                    <tr>
                        <td>Место рождения:</td>
                        <td><?php echo esc_html($buyer_data['birth_place']); ?></td>
                    </tr>
                    <tr>
                        <td>Прописка:</td>
                        <td><?php echo esc_html($buyer_data['registration']); ?></td>
                    </tr>
                    <tr>
                        <td>ИНН:</td>
                        <td><?php echo esc_html($buyer_data['inn']); ?></td>
                    </tr>
                    <tr>
                        <td>СНИЛС:</td>
                        <td><?php echo esc_html($buyer_data['snils']); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="yvo-form-actions">
                <button type="button" class="button yvo-load-data" data-type="buyer">Загрузить данные</button>
                <button type="button" class="button button-secondary yvo-edit-data" data-type="buyer">Редактировать</button>
                <button type="button" class="button button-link-delete yvo-clear-data" data-type="buyer">Очистить</button>
            </div>
        </div>
        
        <div class="yvo-card">
            <h2>Объект недвижимости</h2>
            <div class="yvo-data-display">
                <table class="yvo-data-table">
                    <tr>
                        <td>Адрес:</td>
                        <td><strong><?php echo esc_html($property_data['address']); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Кадастровый номер:</td>
                        <td><?php echo esc_html($property_data['cadastral_number']); ?></td>
                    </tr>
                    <tr>
                        <td>Площадь:</td>
                        <td><?php echo esc_html($property_data['area']); ?> м²</td>
                    </tr>
                    <tr>
                        <td>Комнат:</td>
                        <td><?php echo esc_html($property_data['rooms']); ?></td>
                    </tr>
                    <tr>
                        <td>Этаж:</td>
                        <td><?php echo esc_html($property_data['floor']); ?></td>
                    </tr>
                    <tr>
                        <td>Этажей в доме:</td>
                        <td><?php echo esc_html($property_data['floors_total']); ?></td>
                    </tr>
                    <tr>
                        <td>Стоимость:</td>
                        <td><strong><?php echo number_format(floatval($property_data['price']), 2, ',', ' '); ?> руб.</strong></td>
                    </tr>
                    <tr>
                        <td>Тип собственности:</td>
                        <td><?php echo esc_html($property_data['ownership_type']); ?></td>
                    </tr>
                    <tr>
                        <td>Примечания:</td>
                        <td><?php echo esc_html($property_data['notes']); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="yvo-form-actions">
                <button type="button" class="button yvo-load-data" data-type="property">Загрузить данные</button>
                <button type="button" class="button button-secondary yvo-edit-data" data-type="property">Редактировать</button>
                <button type="button" class="button button-link-delete yvo-clear-data" data-type="property">Очистить</button>
            </div>
        </div>
    </div>
    
    <div class="yvo-card" style="margin-top: 20px;">
        <h2>Сводка по сделке</h2>
        <div class="yvo-summary">
            <p><strong>Продавец:</strong> <?php echo esc_html($seller_data['full_name']); ?></p>
            <p><strong>Покупатель:</strong> <?php echo esc_html($buyer_data['full_name']); ?></p>
            <p><strong>Объект:</strong> <?php echo esc_html($property_data['address']); ?></p>
            <p><strong>Стоимость:</strong> <?php echo number_format(floatval($property_data['price']), 2, ',', ' '); ?> рублей</p>
            
            <div class="yvo-summary-actions" style="margin-top: 20px;">
                <button type="button" class="button button-primary" id="yvo-generate-contract-full">Сгенерировать договор</button>
                <button type="button" class="button" id="yvo-export-data">Экспортировать данные</button>
                <button type="button" class="button button-secondary" id="yvo-print-summary">Распечатать сводку</button>
            </div>
            
            <div id="yvo-contract-result" style="margin-top: 20px;"></div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Редактирование данных
    $('.yvo-edit-data').click(function() {
        var type = $(this).data('type');
        window.location.href = '<?php echo admin_url('admin.php?page=yandex-ocr-pro-recognize'); ?>' + '#' + type + '-form';
    });
    
    // Очистка данных
    $('.yvo-clear-data').click(function() {
        if (confirm('Вы уверены, что хотите очистить эти данные?')) {
            var type = $(this).data('type');
            var $button = $(this);
            
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'yvo_clear_form_data',
                    nonce: yvo_ajax.nonce,
                    form_type: type
                },
                success: function() {
                    alert('Данные очищены!');
                    location.reload();
                }
            });
        }
    });
    
    // Генерация договора
    $('#yvo-generate-contract-full').click(function() {
        $.ajax({
            url: yvo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'yvo_generate_contract',
                nonce: yvo_ajax.nonce
            },
            dataType: 'json',
            beforeSend: function() {
                $('#yvo-contract-result').html('<p class="yvo-loading">Генерация договора...</p>');
            },
            success: function(response) {
                if (response.success) {
                    $('#yvo-contract-result').html(
                        '<div class="notice notice-success">' +
                        '<p>✅ ' + response.data.message + '</p>' +
                        '<p>' +
                        '<a href="' + response.data.contract_url + '" class="button button-primary" target="_blank">Скачать договор</a> ' +
                        '<button class="button yvo-copy-url" data-url="' + response.data.contract_url + '">Копировать ссылку</button>' +
                        '</p>' +
                        '</div>'
                    );
                } else {
                    $('#yvo-contract-result').html('<div class="notice notice-error"><p>❌ ' + response.data + '</p></div>');
                }
            }
        });
    });
    
    // Экспорт данных
    $('#yvo-export-data').click(function() {
        $.ajax({
            url: yvo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'yvo_export_data',
                nonce: yvo_ajax.nonce
            },
            dataType: 'json',
            beforeSend: function() {
                $(this).prop('disabled', true).text('Экспорт...');
            }.bind(this),
            success: function(response) {
                if (response.success) {
                    var a = document.createElement('a');
                    a.href = response.data.url;
                    a.download = response.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                } else {
                    alert('Ошибка экспорта: ' + response.data);
                }
            },
            complete: function() {
                $(this).prop('disabled', false).text('Экспортировать данные');
            }.bind(this)
        });
    });
    
    // Печать сводки
    $('#yvo-print-summary').click(function() {
        window.print();
    });
});
</script>