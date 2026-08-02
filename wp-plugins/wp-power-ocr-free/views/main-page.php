<?php
$settings = yvo_get_api_settings();
$is_configured = yvo_is_api_configured();
$has_pdf_support = yvo_get_pdf_support();
?>
<div class="wrap">
    <h1>Яндекс Vision OCR Pro</h1>
    
    <?php if (!$is_configured): ?>
    <div class="notice notice-warning">
        <p>Пожалуйста, <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-settings'); ?>">настройте API ключ</a> перед использованием.</p>
    </div>
    <?php endif; ?>
    
    <?php if (!$has_pdf_support): ?>
    <div class="notice notice-warning yvo-pdf-warning" style="display: none;">
        <p><strong>Поддержка PDF недоступна!</strong> Для работы с PDF файлами требуется установить Imagick и Ghostscript или библиотеку spatie/pdf-to-image через Composer.</p>
        <p>Вы можете использовать изображения (JPG, PNG, GIF, BMP) или <a href="#" id="yvo-install-pdf-support">установить необходимые компоненты</a>.</p>
    </div>
    <?php endif; ?>
    
    <div class="yvo-container">
        <div class="yvo-card" style="flex: 2;">
            <h2>Распознать текст из документов</h2>
            
            <div class="yvo-upload-area">
                <!-- Контейнер для нескольких файлов -->
                <div class="yvo-files-container">
                    <!-- Динамически добавляемые поля -->
                </div>
                
                <button type="button" class="button" id="yvo-add-file">+ Добавить еще файл</button>
                
                <div class="yvo-batch-actions">
                    <button type="button" id="yvo-process-single" class="button button-secondary" <?php echo !$is_configured ? 'disabled' : ''; ?>>Распознать выбранный</button>
                    <button type="button" id="yvo-process-all" class="button button-primary" <?php echo !$is_configured ? 'disabled' : ''; ?>>Распознать все файлы</button>
                </div>
                
                <div class="yvo-progress" style="display:none;">
                    <div class="spinner is-active"></div>
                    <p>Обработка документов... <span id="yvo-progress-text">0/0</span></p>
                </div>
            </div>
            
            <div id="yvo-results"></div>
            
            <!-- Формы для данных -->
            <div id="yvo-data-forms" style="display: none;">
                <!-- Данные продавца -->
                <div class="yvo-form-section" id="seller-form" style="display: none;">
                    <h3>Данные продавца</h3>
                    <form id="seller-data-form">
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>ФИО:</label>
                                <input type="text" name="seller_full_name" class="regular-text" required>
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Паспорт серия:</label>
                                <input type="text" name="seller_passport_series" class="small-text" maxlength="4" pattern="\d{4}" required>
                            </div>
                            <div class="yvo-form-col">
                                <label>Паспорт номер:</label>
                                <input type="text" name="seller_passport_number" class="small-text" maxlength="6" pattern="\d{6}" required>
                            </div>
                            <div class="yvo-form-col">
                                <label>Код подразделения:</label>
                                <input type="text" name="seller_department_code" class="small-text" placeholder="000-000" pattern="\d{3}-\d{3}">
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Кем выдан:</label>
                                <textarea name="seller_passport_issued_by" rows="2" class="regular-text" required></textarea>
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Дата выдачи:</label>
                                <input type="text" name="seller_passport_date" class="regular-text" placeholder="дд.мм.гггг" pattern="\d{2}\.\d{2}\.\d{4}">
                            </div>
                            <div class="yvo-form-col">
                                <label>Дата рождения:</label>
                                <input type="text" name="seller_birth_date" class="regular-text" placeholder="дд.мм.гггг" pattern="\d{2}\.\d{2}\.\d{4}">
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Место рождения:</label>
                                <input type="text" name="seller_birth_place" class="regular-text">
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Прописка/регистрация:</label>
                                <textarea name="seller_registration" rows="2" class="regular-text" required></textarea>
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>ИНН:</label>
                                <input type="text" name="seller_inn" class="regular-text" maxlength="12" pattern="\d{10}|\d{12}">
                            </div>
                            <div class="yvo-form-col">
                                <label>СНИЛС:</label>
                                <input type="text" name="seller_snils" class="regular-text" placeholder="123-456-789 00">
                            </div>
                        </div>
                        <button type="button" class="button button-primary yvo-save-form" data-form="seller">Сохранить данные продавца</button>
                    </form>
                </div>
                
                <!-- Данные покупателя -->
                <div class="yvo-form-section" id="buyer-form" style="display: none;">
                    <h3>Данные покупателя</h3>
                    <form id="buyer-data-form">
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>ФИО:</label>
                                <input type="text" name="buyer_full_name" class="regular-text" required>
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Паспорт серия:</label>
                                <input type="text" name="buyer_passport_series" class="small-text" maxlength="4" pattern="\d{4}" required>
                            </div>
                            <div class="yvo-form-col">
                                <label>Паспорт номер:</label>
                                <input type="text" name="buyer_passport_number" class="small-text" maxlength="6" pattern="\d{6}" required>
                            </div>
                            <div class="yvo-form-col">
                                <label>Код подразделения:</label>
                                <input type="text" name="buyer_department_code" class="small-text" placeholder="000-000" pattern="\d{3}-\d{3}">
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Кем выдан:</label>
                                <textarea name="buyer_passport_issued_by" rows="2" class="regular-text" required></textarea>
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Дата выдачи:</label>
                                <input type="text" name="buyer_passport_date" class="regular-text" placeholder="дд.мм.гггг" pattern="\d{2}\.\d{2}\.\d{4}">
                            </div>
                            <div class="yvo-form-col">
                                <label>Дата рождения:</label>
                                <input type="text" name="buyer_birth_date" class="regular-text" placeholder="дд.мм.гггг" pattern="\d{2}\.\d{2}\.\d{4}">
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Место рождения:</label>
                                <input type="text" name="buyer_birth_place" class="regular-text">
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Прописка/регистрация:</label>
                                <textarea name="buyer_registration" rows="2" class="regular-text" required></textarea>
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>ИНН:</label>
                                <input type="text" name="buyer_inn" class="regular-text" maxlength="12" pattern="\d{10}|\d{12}">
                            </div>
                            <div class="yvo-form-col">
                                <label>СНИЛС:</label>
                                <input type="text" name="buyer_snils" class="regular-text" placeholder="123-456-789 00">
                            </div>
                        </div>
                        <button type="button" class="button button-primary yvo-save-form" data-form="buyer">Сохранить данные покупателя</button>
                    </form>
                </div>
                
                <!-- Данные об объекте -->
                <div class="yvo-form-section" id="property-form" style="display: none;">
                    <h3>Данные об объекте недвижимости</h3>
                    <form id="property-data-form">
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Адрес объекта:</label>
                                <textarea name="property_address" rows="2" class="regular-text" required></textarea>
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Кадастровый номер:</label>
                                <input type="text" name="property_cadastral_number" class="regular-text" placeholder="00:00:0000000:00">
                            </div>
                            <div class="yvo-form-col">
                                <label>Площадь (м²):</label>
                                <input type="number" name="property_area" class="regular-text" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Количество комнат:</label>
                                <input type="number" name="property_rooms" class="small-text" min="1">
                            </div>
                            <div class="yvo-form-col">
                                <label>Этаж:</label>
                                <input type="number" name="property_floor" class="small-text" min="1">
                            </div>
                            <div class="yvo-form-col">
                                <label>Этажность дома:</label>
                                <input type="number" name="property_floors_total" class="small-text" min="1">
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Стоимость (руб.):</label>
                                <input type="number" name="property_price" class="regular-text" step="0.01" min="0" required>
                            </div>
                            <div class="yvo-form-col">
                                <label>Тип собственности:</label>
                                <select name="property_ownership_type" class="regular-text">
                                    <option value="ownership">Собственность</option>
                                    <option value="share">Долевая собственность</option>
                                    <option value="rent">Аренда</option>
                                    <option value="inheritance">Пожизненное наследуемое владение</option>
                                </select>
                            </div>
                        </div>
                        <div class="yvo-form-row">
                            <div class="yvo-form-col">
                                <label>Дополнительная информация:</label>
                                <textarea name="property_notes" rows="3" class="regular-text"></textarea>
                            </div>
                        </div>
                        <button type="button" class="button button-primary yvo-save-form" data-form="property">Сохранить данные об объекте</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="yvo-card">
            <h3>Поддерживаемые форматы:</h3>
            <ul>
                <li>PDF <?php echo $has_pdf_support ? '✓' : '✗'; ?></li>
                <li>JPG, JPEG ✓</li>
                <li>PNG ✓</li>
                <li>GIF ✓</li>
                <li>BMP ✓</li>
                <li>WEBP ✓</li>
            </ul>
            
            <h3>Ограничения:</h3>
            <ul>
                <li>Макс. размер файла: <?php echo $settings['max_size']; ?> MB</li>
                <li>PDF: макс. <?php echo $settings['pdf_max_pages']; ?> страниц</li>
                <li>Язык распознавания: <?php echo $settings['language']; ?></li>
            </ul>
            
            <h3>Быстрые действия:</h3>
            <div class="yvo-quick-actions">
                <button type="button" class="button yvo-load-data" data-type="seller">Загрузить данные продавца</button>
                <button type="button" class="button yvo-load-data" data-type="buyer">Загрузить данные покупателя</button>
                <button type="button" class="button yvo-load-data" data-type="property">Загрузить данные об объекте</button>
            </div>
            
            <h3>Генерация договора:</h3>
            <div class="yvo-contract-actions">
                <button type="button" class="button button-primary" id="yvo-generate-contract" <?php echo !$is_configured ? 'disabled' : ''; ?>>Сгенерировать договор</button>
                <div id="yvo-contract-result" style="margin-top: 10px;"></div>
            </div>
            
            <?php if ($is_configured): ?>
            <div class="yvo-stats">
                <p><strong>Статус:</strong> <span style="color:green;">✓ Настроено</span></p>
                <p><strong>Папка:</strong> <?php echo esc_html($settings['folder_id']); ?></p>
                <p><strong>Поддержка PDF:</strong> 
                    <?php if ($has_pdf_support): ?>
                        <span style="color:green;">✓ Доступна</span>
                    <?php else: ?>
                        <span style="color:red;">✗ Недоступна</span>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            
            <p style="text-align: center; margin-top: 20px;">
                <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-settings'); ?>" class="button button-secondary">
                    Настройки плагина
                </a>
            </p>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Кнопка установки PDF поддержки
    $('#yvo-install-pdf-support').click(function(e) {
        e.preventDefault();
        alert('Для установки поддержки PDF:\n\n1. Установите Imagick и Ghostscript на сервере\n2. Или запустите в директории плагина: composer require spatie/pdf-to-image\n3. Переактивируйте плагин');
    });
    
    // Валидация форм
    $('input[pattern]').on('input', function() {
        var $input = $(this);
        var pattern = new RegExp($input.attr('pattern'));
        var value = $input.val();
        
        if (value && !pattern.test(value)) {
            $input.addClass('error');
        } else {
            $input.removeClass('error');
        }
    });
});
</script>