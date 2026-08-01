<?php
// Безопасность
if (!defined('ABSPATH')) {
    exit;
}

// Основные функции плагина
function yvo_is_api_configured() {
    $api_key = get_option('yvo_api_key', '');
    $folder_id = get_option('yvo_folder_id', '');
    return !empty($api_key) && !empty($folder_id);
}

function yvo_get_form_data($type) {
    $data = get_option("yvo_{$type}_data", array());
    
    $defaults = array(
        'full_name' => '',
        'passport_series' => '',
        'passport_number' => '',
        'passport_issued_by' => '',
        'passport_date' => '',
        'birth_date' => '',
        'birth_place' => '',
        'registration' => '',
        'inn' => '',
        'snils' => '',
        'address' => '',
        'area' => '',
        'rooms' => '',
        'floor' => '',
        'floors_total' => '',
        'price' => '',
        'ownership_type' => 'ownership'
    );
    
    return array_merge($defaults, $data);
}

function yvo_get_pdf_support() {
    // Упрощенная проверка
    if (extension_loaded('imagick')) {
        return true;
    }
    
    if (file_exists(YVO_PLUGIN_DIR . 'vendor/autoload.php')) {
        require_once YVO_PLUGIN_DIR . 'vendor/autoload.php';
        if (class_exists('Spatie\PdfToImage\Pdf')) {
            return true;
        }
    }
    
    return false;
}

function yvo_get_languages() {
    return array(
        'ru' => 'Русский',
        'en' => 'Английский',
        'tr' => 'Турецкий',
        'uk' => 'Украинский',
        'kk' => 'Казахский'
    );
}

function yvo_log_message($message, $type = 'info') {
    if (!get_option('yvo_enable_logging', false)) {
        return;
    }
    
    $log_dir = YVO_PLUGIN_DIR . 'logs/';
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }
    
    $log_file = $log_dir . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    
    $log_entry = "[$timestamp] [$type] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// AJAX обработчики для дополнительных функций
add_action('wp_ajax_yvo_check_pdf_support', 'yvo_ajax_check_pdf_support');
add_action('wp_ajax_yvo_clear_data', 'yvo_ajax_clear_data');

function yvo_ajax_check_pdf_support() {
    check_ajax_referer('yvo_secure_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостаточно прав');
    }
    
    $has_pdf_support = yvo_get_pdf_support();
    
    wp_send_json_success(array(
        'has_pdf_support' => $has_pdf_support,
        'message' => $has_pdf_support ? 'PDF поддерживается' : 'PDF не поддерживается'
    ));
}

function yvo_ajax_clear_data() {
    check_ajax_referer('yvo_secure_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостаточно прав');
    }
    
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    
    if (in_array($type, array('seller', 'buyer', 'property'))) {
        delete_option("yvo_{$type}_data");
        wp_send_json_success('Данные очищены');
    }
    
    wp_send_json_error('Неверный тип данных');
}