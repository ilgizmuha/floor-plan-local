<?php
// Обработка сохранения настроек
if (isset($_POST['yvo_save_settings']) && check_admin_referer('yvo_settings_nonce')) {
    $options = array(
        'yvo_api_key' => sanitize_text_field($_POST['api_key']),
        'yvo_folder_id' => sanitize_text_field($_POST['folder_id']),
        'yvo_language' => sanitize_text_field($_POST['language']),
        'yvo_max_size' => intval($_POST['max_size']),
        'yvo_pdf_max_pages' => intval($_POST['pdf_max_pages']),
        'yvo_enable_logging' => isset($_POST['enable_logging']) ? 1 : 0,
        'yvo_auto_cleanup' => isset($_POST['auto_cleanup']) ? 1 : 0,
        'yvo_contract_template' => sanitize_text_field($_POST['contract_template'])
    );
    
    foreach ($options as $key => $value) {
        update_option($key, $value);
    }
    
    echo '<div class="notice notice-success"><p>Настройки сохранены!</p></div>';
}

// Получаем текущие настройки
$settings = yvo_get_api_settings();
$has_pdf_support = yvo_get_pdf_support();
$languages = yvo_get_languages();
$templates = function_exists('yvo_get_available_templates') ? yvo_get_available_templates() : array('default' => 'Стандартный договор');
?>
<div class="wrap">
    <h1>Настройки Яндекс Vision OCR Pro</h1>
    
    <div class="yvo-container">
        <div class="yvo-card" style="flex: 2;">
            <form method="post" action="">
                <?php wp_nonce_field('yvo_settings_nonce'); ?>
                
                <h2>Настройки API</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_key">API Ключ</label></th>
                        <td>
                            <input type="password" 
                                   id="api_key" 
                                   name="api_key" 
                                   value="<?php echo esc_attr($settings['key']); ?>" 
                                   class="regular-text">
                            <p class="description">
                                Ваш API ключ от Яндекс Облака. <a href="https://cloud.yandex.ru/docs/vision/quickstart" target="_blank">Как получить ключ?</a>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="folder_id">ID Папки</label></th>
                        <td>
                            <input type="text" 
                                   id="folder_id" 
                                   name="folder_id" 
                                   value="<?php echo esc_attr($settings['folder_id']); ?>" 
                                   class="regular-text">
                            <p class="description">
                                Идентификатор папки в Яндекс Облаке
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="language">Язык текста</label></th>
                        <td>
                            <select id="language" name="language" class="regular-text">
                                <?php foreach ($languages as $code => $name): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($settings['language'], $code); ?>>
                                        <?php echo esc_html($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Язык текста на документах
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="max_size">Макс. размер (MB)</label></th>
                        <td>
                            <input type="number" 
                                   id="max_size" 
                                   name="max_size" 
                                   value="<?php echo esc_attr($settings['max_size']); ?>" 
                                   class="small-text" min="1" max="50" step="1">
                            <p class="description">
                                Максимальный размер файла для обработки (рекомендуется 20MB)
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="pdf_max_pages">PDF: макс. страниц</label></th>
                        <td>
                            <input type="number" 
                                   id="pdf_max_pages" 
                                   name="pdf_max_pages" 
                                   value="<?php echo esc_attr($settings['pdf_max_pages']); ?>" 
                                   class="small-text" min="1" max="50" step="1">
                            <p class="description">
                                Максимальное количество страниц PDF для обработки
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="contract_template">Шаблон договора</label></th>
                        <td>
                            <select id="contract_template" name="contract_template" class="regular-text">
                                <?php foreach ($templates as $template => $name): ?>
                                    <option value="<?php echo esc_attr($template); ?>" <?php selected(get_option('yvo_contract_template', 'default'), $template); ?>>
                                        <?php echo esc_html($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Шаблон для генерации договора
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label>Дополнительные настройки</label></th>
                        <td>
                            <label for="enable_logging">
                                <input type="checkbox" 
                                       id="enable_logging" 
                                       name="enable_logging" 
                                       value="1" 
                                       <?php checked(get_option('yvo_enable_logging', false)); ?>>
                                Включить логирование
                            </label>
                            <p class="description">
                                Сохранять историю обработки документов
                            </p>
                            
                            <br>
                            
                            <label for="auto_cleanup">
                                <input type="checkbox" 
                                       id="auto_cleanup" 
                                       name="auto_cleanup" 
                                       value="1" 
                                       <?php checked(get_option('yvo_auto_cleanup', true)); ?>>
                                Автоматическая очистка временных файлов
                            </label>
                            <p class="description">
                                Удалять временные файлы старше 24 часов
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" 
                           name="yvo_save_settings" 
                           class="button button-primary" 
                           value="Сохранить настройки">
                    <button type="button" id="yvo-test-api" class="button button-secondary">Проверить API</button>
                    <button type="button" id="yvo-check-pdf" class="button button-secondary">Проверить PDF</button>
                </p>
            </form>
            
            <div id="yvo-test-result" style="margin-top: 20px;"></div>
        </div>
        
        <div class="yvo-card">
            <h2>Информация о системе</h2>
            
            <div class="yvo-info-box">
                <h3>Текущие настройки:</h3>
                <ul>
                    <li><strong>API Ключ:</strong> <?php echo !empty($settings['key']) ? '✓ Установлен' : '✗ Не установлен'; ?></li>
                    <li><strong>ID Папки:</strong> <?php echo esc_html($settings['folder_id']); ?></li>
                    <li><strong>Язык:</strong> <?php echo $languages[$settings['language']] ?? 'Русский'; ?></li>
                    <li><strong>Макс. размер:</strong> <?php echo esc_html($settings['max_size']); ?> MB</li>
                    <li><strong>PDF макс. страниц:</strong> <?php echo esc_html($settings['pdf_max_pages']); ?></li>
                    <li><strong>Логирование:</strong> <?php echo get_option('yvo_enable_logging', false) ? 'Включено' : 'Выключено'; ?></li>
                    <li><strong>Статус:</strong> 
                        <?php if (!empty($settings['key']) && !empty($settings['folder_id'])): ?>
                            <span style="color:green;">✓ Готов к работе</span>
                        <?php else: ?>
                            <span style="color:red;">✗ Требуется настройка</span>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
            
            <h3>Поддержка форматов:</h3>
            <ul>
                <li><strong>PDF:</strong> 
                    <?php if ($has_pdf_support): ?>
                        <span style="color:green;">✓ Доступна</span>
                    <?php else: ?>
                        <span style="color:red;">✗ Недоступна</span>
                        <p class="description">Требуется: Imagick + Ghostscript или spatie/pdf-to-image</p>
                    <?php endif; ?>
                </li>
                <li><strong>Изображения:</strong> <span style="color:green;">✓ JPG, PNG, GIF, BMP, WEBP</span></li>
            </ul>
            
            <h3>Зависимости:</h3>
            <ul>
                <li><strong>PHP версия:</strong> <?php echo PHP_VERSION; ?> <?php echo version_compare(PHP_VERSION, '7.4', '>=') ? '✓' : '✗'; ?></li>
                <li><strong>cURL:</strong> <?php echo function_exists('curl_init') ? '✓' : '✗'; ?></li>
                <li><strong>Imagick:</strong> <?php echo extension_loaded('imagick') ? '✓' : '✗'; ?></li>
                <li><strong>Ghostscript:</strong> <?php echo function_exists('shell_exec') && !empty(@shell_exec('gs --version 2>&1')) ? '✓' : '✗'; ?></li>
                <li><strong>Память PHP:</strong> <?php echo ini_get('memory_limit'); ?></li>
                <li><strong>Макс. размер загрузки:</strong> <?php echo ini_get('upload_max_filesize'); ?></li>
            </ul>
            
            <h3>Быстрые действия:</h3>
            <div class="yvo-quick-actions">
                <button type="button" class="button" id="yvo-clear-logs">Очистить логи</button>
                <button type="button" class="button" id="yvo-clear-temp">Очистить временные файлы</button>
                <button type="button" class="button" id="yvo-reset-settings">Сбросить настройки</button>
            </div>
            
            <?php if (yvo_is_api_configured()): ?>
            <p style="text-align: center; margin-top: 20px;">
                <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro'); ?>" class="button button-primary">
                    Перейти к распознаванию документов
                </a>
            </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Очистка логов
    $('#yvo-clear-logs').click(function() {
        if (confirm('Вы уверены, что хотите очистить все логи?')) {
            var $button = $(this);
            $button.prop('disabled', true).text('Очистка...');
            
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'yvo_clear_logs',
                    nonce: yvo_ajax.nonce
                },
                success: function() {
                    alert('Логи очищены!');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Очистить логи');
                }
            });
        }
    });
    
    // Очистка временных файлов
    $('#yvo-clear-temp').click(function() {
        if (confirm('Вы уверены, что хотите очистить временные файлы?')) {
            var $button = $(this);
            $button.prop('disabled', true).text('Очистка...');
            
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'yvo_clear_temp',
                    nonce: yvo_ajax.nonce
                },
                success: function() {
                    alert('Временные файлы очищены!');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Очистить временные файлы');
                }
            });
        }
    });
    
    // Сброс настроек
    $('#yvo-reset-settings').click(function() {
        if (confirm('Вы уверены, что хотите сбросить все настройки к значениям по умолчанию?')) {
            var $button = $(this);
            $button.prop('disabled', true).text('Сброс...');
            
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'yvo_reset_settings',
                    nonce: yvo_ajax.nonce
                },
                success: function() {
                    alert('Настройки сброшены! Страница будет перезагружена.');
                    location.reload();
                },
                error: function() {
                    $button.prop('disabled', false).text('Сбросить настройки');
                }
            });
        }
    });
});
</script>