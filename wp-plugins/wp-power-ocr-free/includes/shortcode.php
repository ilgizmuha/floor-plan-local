<?php
// Шорткод для фронтенд формы

if (!defined('ABSPATH')) {
    exit;
}

function yvo_init_shortcode() {
    add_shortcode('yandex_ocr_form', 'yvo_frontend_form_shortcode');
}

function yvo_frontend_form_shortcode($atts) {
    // Проверяем, включен ли шорткод в настройках
    if (!get_option('yvo_enable_shortcode', 1)) {
        return '';
    }
    
    ob_start();
    ?>
    <div class="yvo-frontend-form">
        <div class="yvo-form-container">
            <h2>Распознавание паспортных данных</h2>
            <p>Загрузите изображение или PDF-файл паспорта для автоматического распознавания данных</p>
            
            <form id="yvo-upload-form" method="post" enctype="multipart/form-data">
                <div class="yvo-form-group">
                    <label for="yvo-file">Выберите файл:</label>
                    <input type="file" id="yvo-file" name="file" accept=".jpg,.jpeg,.png,.gif,.bmp,.pdf" required>
                    <small>Поддерживаемые форматы: JPG, PNG, GIF, BMP, PDF. Макс. размер: <?php echo get_option('yvo_max_size', 2); ?> MB</small>
                </div>
                
                <button type="submit" class="yvo-submit-btn">Распознать текст</button>
            </form>
            
            <div class="yvo-progress" style="display: none;">
                <div class="yvo-spinner"></div>
                <p>Обработка файла...</p>
            </div>
            
            <div id="yvo-results" style="display: none;">
                <h3>Результаты распознавания</h3>
                
                <div class="yvo-text-section">
                    <h4>Распознанный текст:</h4>
                    <textarea id="yvo-text-result" rows="8" readonly></textarea>
                    <button type="button" id="yvo-parse-data" class="yvo-secondary-btn">Извлечь паспортные данные</button>
                </div>
                
                <div id="yvo-passport-data" style="display: none;">
                    <h4>Паспортные данные:</h4>
                    
                    <div class="yvo-form-group">
                        <label>ФИО:</label>
                        <input type="text" id="yvo-fio" class="yvo-field" readonly>
                    </div>
                    
                    <div class="yvo-form-row">
                        <div class="yvo-form-group">
                            <label>Серия и номер:</label>
                            <input type="text" id="yvo-series" class="yvo-field" readonly>
                        </div>
                        <div class="yvo-form-group">
                            <label>Код подразделения:</label>
                            <input type="text" id="yvo-code" class="yvo-field" readonly>
                        </div>
                    </div>
                    
                    <div class="yvo-form-group">
                        <label>Кем выдан:</label>
                        <input type="text" id="yvo-issued" class="yvo-field" readonly>
                    </div>
                    
                    <div class="yvo-form-row">
                        <div class="yvo-form-group">
                            <label>Дата рождения:</label>
                            <input type="text" id="yvo-birth" class="yvo-field" readonly>
                        </div>
                        <div class="yvo-form-group">
                            <label>Дата выдачи:</label>
                            <input type="text" id="yvo-issue" class="yvo-field" readonly>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="yvo-error" class="yvo-error-message" style="display: none;"></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}