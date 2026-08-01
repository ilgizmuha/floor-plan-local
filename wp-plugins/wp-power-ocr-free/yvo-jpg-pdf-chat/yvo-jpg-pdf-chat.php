<?php
/**
 * Модуль «Фото → PDF» внутри основного плагина YVO.
 *
 * Расположение: каталог плагина/yvo-jpg-pdf-chat/ (рядом с wp-power-ocr-free.php).
 * Шорткод: [yvo_jpg_pdf_chat]
 *
 * Тот же код можно скопировать в wp-content/plugins/yvo-jpg-pdf-chat/ как отдельный плагин
 * (тогда нужен заголовок Plugin Name в копии главного файла).
 */
if (!defined('ABSPATH')) {
    exit;
}

if (defined('YVO_JPG_PDF_CHAT_MODULE')) {
    return;
}
define('YVO_JPG_PDF_CHAT_MODULE', true);

if (!defined('YVO_JPG_PDF_CHAT_VER')) {
    define('YVO_JPG_PDF_CHAT_VER', '1.0.0');
}
if (!defined('YVO_JPG_PDF_CHAT_DIR')) {
    define('YVO_JPG_PDF_CHAT_DIR', plugin_dir_path(__FILE__));
}
if (!defined('YVO_JPG_PDF_CHAT_URL')) {
    define('YVO_JPG_PDF_CHAT_URL', plugin_dir_url(__FILE__));
}

/**
 * Опции по умолчанию (вызывается из yvo_activate основного плагина).
 */
function yvo_jpg_pdf_chat_install_options() {
    if (get_option('yvo_jpg_pdf_chat_max_mb', '') === '') {
        add_option('yvo_jpg_pdf_chat_max_mb', 50);
    }
}

/**
 * Лимит размера файла для виджета (байты), не больше upload_max.
 */
function yvo_jpg_pdf_chat_max_bytes() {
    $mb   = max(1, (int) get_option('yvo_jpg_pdf_chat_max_mb', 50));
    $want = $mb * 1024 * 1024;
    $cap  = wp_max_upload_size();
    if ($cap > 0 && $want > $cap) {
        return $cap;
    }
    return $want;
}

/**
 * Ранняя подгрузка стилей, если в записи есть шорткод.
 */
function yvo_jpg_pdf_chat_enqueue_assets_early() {
    if (is_admin()) {
        return;
    }
    $need = false;
    if (function_exists('is_singular') && is_singular()) {
        $post = get_post();
        if ($post && !empty($post->post_content)) {
            $pc = $post->post_content;
            if (has_shortcode($pc, 'yvo_jpg_pdf_chat') || strpos($pc, '[yvo_jpg_pdf_chat') !== false) {
                $need = true;
            }
        }
    }
    if (!$need) {
        return;
    }
    yvo_jpg_pdf_chat_enqueue_assets();
}

add_action('wp_enqueue_scripts', 'yvo_jpg_pdf_chat_enqueue_assets_early', 6);

/**
 * Подключение css/js (вызывается из шорткода и из early enqueue).
 */
function yvo_jpg_pdf_chat_enqueue_assets() {
    $ver = YVO_JPG_PDF_CHAT_VER . '.' . (file_exists(YVO_JPG_PDF_CHAT_DIR . 'assets/js/yvo-jpg-pdf-chat.js') ? (int) filemtime(YVO_JPG_PDF_CHAT_DIR . 'assets/js/yvo-jpg-pdf-chat.js') : 0);
    wp_enqueue_style('yvo-jpg-pdf-chat', YVO_JPG_PDF_CHAT_URL . 'assets/css/yvo-jpg-pdf-chat.css', array(), $ver);
    wp_enqueue_script('pdf-lib', 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js', array(), '1.17.1', true);
    wp_enqueue_script('jszip', 'https://unpkg.com/jszip@3.10.1/dist/jszip.min.js', array(), '3.10.1', true);
    wp_enqueue_script('yvo-jpg-pdf-chat', YVO_JPG_PDF_CHAT_URL . 'assets/js/yvo-jpg-pdf-chat.js', array('pdf-lib', 'jszip'), $ver, true);
    wp_localize_script(
        'yvo-jpg-pdf-chat',
        'yvo_jpg_pdf_chat_cfg',
        array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('yvo_frontend_contract'),
            'max_size'    => yvo_jpg_pdf_chat_max_bytes(),
            'max_size_mb' => (int) ceil(yvo_jpg_pdf_chat_max_bytes() / 1024 / 1024),
        )
    );
}

/**
 * @param array<string, mixed> $atts Shortcode attributes.
 */
function yvo_jpg_pdf_chat_shortcode($atts) {
    yvo_jpg_pdf_chat_enqueue_assets();
    $max_mb = (int) ceil(yvo_jpg_pdf_chat_max_bytes() / 1024 / 1024);
    ob_start();
    ?>
    <div class="yvo-jpg-pdf-chat" role="region" aria-label="<?php echo esc_attr__('Конвертер изображений в PDF', 'yvo-jpg-pdf-chat'); ?>">
        <div class="yvo-jpg-pdf-chat__head">
            <h3 class="yvo-jpg-pdf-chat__title"><?php esc_html_e('Фото / PDF', 'yvo-jpg-pdf-chat'); ?></h3>
            <span class="yvo-jpg-pdf-chat__badge"><?php esc_html_e('как iLovePDF', 'yvo-jpg-pdf-chat'); ?></span>
        </div>
        <div class="yvo-jpg-pdf-chat__messages" data-yvo-jpg-messages>
            <div class="yvo-jpg-pdf-chat__bubble yvo-jpg-pdf-chat__bubble--bot">
                <?php esc_html_e('Добавьте JPG, PNG или PDF. Порядок в PDF — как номера на миниатюрах (↑↓ или перетаскивание). «Объединить» / «Разделить» — как раньше. Кнопки ИИ: сначала OCR каждого фото, затем DeepSeek предлагает порядок страниц (паспорт РФ или другой документ); после проверки нажмите «Объединить в PDF».', 'yvo-jpg-pdf-chat'); ?>
                <p class="yvo-jpg-pdf-chat__hint" style="margin-top:8px;">
                    <?php esc_html_e('ИИ нужен ключ DeepSeek в настройках плагина; OCR — ключ Яндекс Vision.', 'yvo-jpg-pdf-chat'); ?>
                </p>
            </div>
        </div>
        <div class="yvo-jpg-pdf-chat__dropzone" data-yvo-jpg-dropzone>
            <p class="yvo-jpg-pdf-chat__dropzone-text"><?php esc_html_e('Перетащите JPG, PNG или PDF сюда', 'yvo-jpg-pdf-chat'); ?></p>
        </div>
        <div class="yvo-jpg-pdf-chat__queue" data-yvo-jpg-queue hidden>
            <p class="yvo-jpg-pdf-chat__queue-title"><?php esc_html_e('Очередь', 'yvo-jpg-pdf-chat'); ?> <span class="yvo-jpg-pdf-chat__queue-hint" data-yvo-jpg-queue-hint></span></p>
            <div class="yvo-jpg-pdf-chat__thumbs" data-yvo-jpg-thumbs></div>
        </div>
        <details class="yvo-jpg-pdf-chat__details">
            <summary class="yvo-jpg-pdf-chat__summary"><?php esc_html_e('Вид PDF: формат и поля (мм)', 'yvo-jpg-pdf-chat'); ?></summary>
            <div class="yvo-jpg-pdf-chat__pdf-opts">
                <label class="yvo-jpg-pdf-chat__field">
                    <span><?php esc_html_e('Формат страницы', 'yvo-jpg-pdf-chat'); ?></span>
                    <select data-yvo-page-format class="yvo-jpg-pdf-chat__select">
                        <option value="a4p">A4 <?php esc_html_e('вертикально', 'yvo-jpg-pdf-chat'); ?></option>
                        <option value="a4l">A4 <?php esc_html_e('горизонтально', 'yvo-jpg-pdf-chat'); ?></option>
                        <option value="a5p">A5 <?php esc_html_e('вертикально', 'yvo-jpg-pdf-chat'); ?></option>
                        <option value="a5l">A5 <?php esc_html_e('горизонтально', 'yvo-jpg-pdf-chat'); ?></option>
                        <option value="letterp">Letter <?php esc_html_e('вертикально', 'yvo-jpg-pdf-chat'); ?></option>
                        <option value="letterl">Letter <?php esc_html_e('горизонтально', 'yvo-jpg-pdf-chat'); ?></option>
                    </select>
                </label>
                <label class="yvo-jpg-pdf-chat__field yvo-jpg-pdf-chat__field--inline">
                    <input type="checkbox" data-yvo-margin-link checked>
                    <span><?php esc_html_e('Одинаковые поля со всех сторон', 'yvo-jpg-pdf-chat'); ?></span>
                </label>
                <label class="yvo-jpg-pdf-chat__field" data-yvo-margin-uniform-wrap>
                    <span><?php esc_html_e('Поля (мм)', 'yvo-jpg-pdf-chat'); ?></span>
                    <input type="number" class="yvo-jpg-pdf-chat__input" data-yvo-margin-uniform min="0" max="60" step="1" value="10">
                </label>
                <div class="yvo-jpg-pdf-chat__margin-grid" data-yvo-margin-four hidden>
                    <label><?php esc_html_e('Верх', 'yvo-jpg-pdf-chat'); ?> <input type="number" class="yvo-jpg-pdf-chat__input yvo-jpg-pdf-chat__input--sm" data-yvo-m-t min="0" max="60" value="10"></label>
                    <label><?php esc_html_e('Право', 'yvo-jpg-pdf-chat'); ?> <input type="number" class="yvo-jpg-pdf-chat__input yvo-jpg-pdf-chat__input--sm" data-yvo-m-r min="0" max="60" value="10"></label>
                    <label><?php esc_html_e('Низ', 'yvo-jpg-pdf-chat'); ?> <input type="number" class="yvo-jpg-pdf-chat__input yvo-jpg-pdf-chat__input--sm" data-yvo-m-b min="0" max="60" value="10"></label>
                    <label><?php esc_html_e('Лево', 'yvo-jpg-pdf-chat'); ?> <input type="number" class="yvo-jpg-pdf-chat__input yvo-jpg-pdf-chat__input--sm" data-yvo-m-l min="0" max="60" value="10"></label>
                </div>
            </div>
        </details>
        <div class="yvo-jpg-pdf-chat__foot">
            <div class="yvo-jpg-pdf-chat__row">
                <label class="yvo-jpg-pdf-chat__btn yvo-jpg-pdf-chat__btn--primary yvo-jpg-pdf-chat__file-label">
                    <input type="file" class="yvo-jpg-pdf-chat__file" data-yvo-jpg-file accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" multiple>
                    <?php esc_html_e('Добавить файлы', 'yvo-jpg-pdf-chat'); ?>
                </label>
                <button type="button" class="yvo-jpg-pdf-chat__btn yvo-jpg-pdf-chat__btn--success" data-yvo-jpg-merge disabled><?php esc_html_e('Объединить в PDF', 'yvo-jpg-pdf-chat'); ?></button>
                <button type="button" class="yvo-jpg-pdf-chat__btn" data-yvo-jpg-split disabled><?php esc_html_e('Разделить PDF', 'yvo-jpg-pdf-chat'); ?></button>
            </div>
            <p class="yvo-jpg-pdf-chat__hint"><?php printf(esc_html__('Максимум ~%d МБ на файл (настройка ниже и лимит сервера).', 'yvo-jpg-pdf-chat'), $max_mb); ?></p>
            <div class="yvo-jpg-pdf-chat__row yvo-jpg-pdf-chat__actions" data-yvo-jpg-api-row>
                <button type="button" class="yvo-jpg-pdf-chat__btn" data-yvo-api="ocr_front"><?php esc_html_e('OCR: yvo_frontend_upload', 'yvo-jpg-pdf-chat'); ?></button>
                <button type="button" class="yvo-jpg-pdf-chat__btn" data-yvo-api="ocr_legacy"><?php esc_html_e('OCR: yvo_process_upload', 'yvo-jpg-pdf-chat'); ?></button>
            </div>
            <div class="yvo-jpg-pdf-chat__row yvo-jpg-pdf-chat__actions yvo-jpg-pdf-chat__ai-row" data-yvo-jpg-api-row>
                <span class="yvo-jpg-pdf-chat__ai-label"><?php esc_html_e('ИИ (DeepSeek):', 'yvo-jpg-pdf-chat'); ?></span>
                <button type="button" class="yvo-jpg-pdf-chat__btn yvo-jpg-pdf-chat__btn--ai" data-yvo-ai-order="passport"><?php esc_html_e('Порядок страниц — паспорт РФ', 'yvo-jpg-pdf-chat'); ?></button>
                <button type="button" class="yvo-jpg-pdf-chat__btn yvo-jpg-pdf-chat__btn--ai" data-yvo-ai-order="generic"><?php esc_html_e('Порядок — другой документ', 'yvo-jpg-pdf-chat'); ?></button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Настройки: лимит МБ.
 */
function yvo_jpg_pdf_chat_admin_menu() {
    add_options_page(
        __('JPG → PDF Chat', 'yvo-jpg-pdf-chat'),
        __('JPG → PDF Chat', 'yvo-jpg-pdf-chat'),
        'manage_options',
        'yvo-jpg-pdf-chat',
        'yvo_jpg_pdf_chat_settings_page'
    );
}

add_action('admin_menu', 'yvo_jpg_pdf_chat_admin_menu');

/**
 * Сохранение настроек.
 */
function yvo_jpg_pdf_chat_register_settings() {
    register_setting(
        'yvo_jpg_pdf_chat',
        'yvo_jpg_pdf_chat_max_mb',
        array(
            'type'              => 'integer',
            'sanitize_callback' => function ($v) {
                $v = (int) $v;
                return min(200, max(1, $v));
            },
            'default'           => 50,
        )
    );
}

add_action('admin_init', 'yvo_jpg_pdf_chat_register_settings');

/**
 * Страница «Настройки → JPG → PDF Chat».
 */
function yvo_jpg_pdf_chat_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('YVO JPG → PDF Chat', 'yvo-jpg-pdf-chat'); ?></h1>
        <p><?php esc_html_e('Модуль встроен в основной плагин (папка yvo-jpg-pdf-chat/). Вставьте на страницу шорткод:', 'yvo-jpg-pdf-chat'); ?> <code>[yvo_jpg_pdf_chat]</code></p>
        <form method="post" action="options.php">
            <?php settings_fields('yvo_jpg_pdf_chat'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="yvo_jpg_pdf_chat_max_mb"><?php esc_html_e('Максимум МБ на файл (виджет)', 'yvo-jpg-pdf-chat'); ?></label></th>
                    <td>
                        <input name="yvo_jpg_pdf_chat_max_mb" id="yvo_jpg_pdf_chat_max_mb" type="number" min="1" max="200" value="<?php echo (int) esc_attr(get_option('yvo_jpg_pdf_chat_max_mb', 50)); ?>">
                        <p class="description"><?php esc_html_e('Фактический лимит не превысит настройки PHP (upload_max_filesize).', 'yvo-jpg-pdf-chat'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * AJAX: DeepSeek расставляет порядок страниц по OCR-фрагментам (виджет JPG/PDF).
 */
function yvo_jpg_pdf_chat_ajax_order_pages() {
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }
    $api_key = get_option('yvo_deepseek_api_key');
    if (empty($api_key)) {
        wp_send_json_error(array('message' => __('Не настроен API ключ DeepSeek в админке плагина.', 'yvo-jpg-pdf-chat')));
    }
    $kind = isset($_POST['document_kind']) ? sanitize_key(wp_unslash($_POST['document_kind'])) : 'passport';
    if (!in_array($kind, array('passport', 'generic'), true)) {
        $kind = 'passport';
    }
    $raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
    $items = json_decode($raw, true);
    if (!is_array($items) || count($items) < 2 || count($items) > 20) {
        wp_send_json_error(array('message' => __('Нужно от 2 до 20 фрагментов с распознанным текстом.', 'yvo-jpg-pdf-chat')));
    }
    $clean = array();
    foreach ($items as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $idx = isset($row['index']) ? (int) $row['index'] : $i;
        $name = isset($row['name']) ? sanitize_file_name($row['name']) : 'file-' . $i;
        $text = isset($row['text']) ? wp_strip_all_tags((string) $row['text']) : '';
        $text = mb_substr($text, 0, 2200);
        if (mb_strlen($text) < 8) {
            wp_send_json_error(array('message' => sprintf(/* translators: %d file index */ __('Слишком короткий OCR для файла №%d.', 'yvo-jpg-pdf-chat'), $idx + 1)));
        }
        $clean[] = array('index' => $idx, 'name' => $name, 'text' => $text);
    }
    $n = count($clean);
    if ($n < 2) {
        wp_send_json_error(array('message' => __('Недостаточно данных для ИИ.', 'yvo-jpg-pdf-chat')));
    }
    $lines = array();
    foreach ($clean as $row) {
        $lines[] = '--- Файл index=' . (int) $row['index'] . ' name=' . $row['name'] . " ---\n" . $row['text'];
    }
    $blob = implode("\n\n", $lines);

    if ($kind === 'passport') {
        $instr = 'Документ: паспорт гражданина РФ (может быть несколько фото/сканов разных разворотов, порядок загрузки случайный). По фрагментам OCR определи логичный порядок чтения: обычно сначала разворот с фотографией и «Паспорт выдан», затем регистрация и прочие страницы. Верни ТОЛЬКО JSON: {"order":[...],"reason_short":"кратко по-русски"}. Ключ "order" — массив целых чисел: перестановка индексов 0..' . ($n - 1) . ', т.е. ровно все числа от 0 до ' . ($n - 1) . ' по одному разу в порядке, как должны идти страницы в PDF.';
    } else {
        $instr = 'Документ: произвольный (договор, справка и т.п.), несколько изображений, порядок загрузки может быть неверным. По OCR определи логичный порядок страниц (сначала титул/шапка, затем последовательные листы). Верни ТОЛЬКО JSON: {"order":[...],"reason_short":"кратко по-русски"}. "order" — перестановка всех индексов 0..' . ($n - 1) . ' ровно по одному разу.';
    }
    $prompt = $instr . "\n\nФрагменты:\n\n" . $blob;

    $model = get_option('yvo_deepseek_model', 'deepseek-chat');
    $url   = 'https://api.deepseek.com/v1/chat/completions';
    $headers = array('Authorization: Bearer ' . $api_key, 'Content-Type: application/json');
    $body    = array(
        'model'            => $model,
        'messages'         => array(array('role' => 'user', 'content' => $prompt)),
        'max_tokens'       => 2048,
        'temperature'      => 0.15,
        'response_format'  => array('type' => 'json_object'),
    );
    $ch = curl_init();
    curl_setopt_array(
        $ch,
        array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => wp_json_encode($body),
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => true,
        )
    );
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);
    if ($err) {
        wp_send_json_error(array('message' => __('Ошибка сети: ', 'yvo-jpg-pdf-chat') . $err));
    }
    if ((int) $http_code !== 200) {
        $data = json_decode($response, true);
        $msg  = isset($data['error']['message']) ? $data['error']['message'] : 'DeepSeek HTTP ' . $http_code;
        wp_send_json_error(array('message' => $msg));
    }
    $data    = json_decode($response, true);
    $content = isset($data['choices'][0]['message']['content']) ? trim((string) $data['choices'][0]['message']['content']) : '';
    $decoded = json_decode($content, true);
    if (!is_array($decoded) || !isset($decoded['order']) || !is_array($decoded['order'])) {
        wp_send_json_error(array('message' => __('ИИ вернул неверный формат. Попробуйте ещё раз.', 'yvo-jpg-pdf-chat')));
    }
    $order = array_values(array_map('intval', $decoded['order']));
    if (count($order) !== $n) {
        wp_send_json_error(array('message' => __('ИИ вернул неверную длину массива order.', 'yvo-jpg-pdf-chat')));
    }
    $seen = array();
    foreach ($order as $v) {
        if ($v < 0 || $v >= $n || isset($seen[$v])) {
            wp_send_json_error(array('message' => __('ИИ вернул недопустимую перестановку индексов.', 'yvo-jpg-pdf-chat')));
        }
        $seen[$v] = true;
    }
    $reason = isset($decoded['reason_short']) ? sanitize_text_field((string) $decoded['reason_short']) : '';
    wp_send_json_success(
        array(
            'order'        => $order,
            'reason_short' => $reason,
        )
    );
}

add_action('wp_ajax_yvo_jpg_pdf_chat_order_pages', 'yvo_jpg_pdf_chat_ajax_order_pages');
add_action('wp_ajax_nopriv_yvo_jpg_pdf_chat_order_pages', 'yvo_jpg_pdf_chat_ajax_order_pages');
