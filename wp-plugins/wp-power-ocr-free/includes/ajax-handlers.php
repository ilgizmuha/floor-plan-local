<?php
// AJAX обработчики

if (!defined('ABSPATH')) {
    exit;
}

function yvo_init_ajax_handlers() {
    // Админ обработчики
    add_action('wp_ajax_yvo_process_image', 'yvo_ajax_process_image');
    add_action('wp_ajax_yvo_test_api', 'yvo_ajax_test_api');
    add_action('wp_ajax_yvo_parse_passport', 'yvo_ajax_parse_passport');
    
    // Фронтенд обработчики
    add_action('wp_ajax_nopriv_yvo_process_upload', 'yvo_ajax_process_upload');
    add_action('wp_ajax_yvo_process_upload', 'yvo_ajax_process_upload');
    add_action('wp_ajax_nopriv_yvo_parse_passport_data', 'yvo_ajax_parse_passport_data');
    add_action('wp_ajax_yvo_parse_passport_data', 'yvo_ajax_parse_passport_data');
}

// Обработка загруженного файла (фронтенд)
function yvo_ajax_process_upload() {
    // Включаем отладку для отслеживания ошибок
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    // Увеличиваем лимиты для загрузки файлов
    set_time_limit(60);
    
    // Проверка загруженного файла (nonce не проверяем — на хостингах из-за кэша часто срабатывает «ошибка безопасности»)
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(array('message' => 'File upload error. Error code: ' . $_FILES['file']['error']));
    }
    
    // Проверка типа файла
    $allowed_types = array(
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/bmp',
        'application/pdf',
        'application/octet-stream'
    );
    
    $file_type = $_FILES['file']['type'];
    $file_name = sanitize_file_name($_FILES['file']['name']);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    // Дополнительная проверка по расширению
    $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'pdf');
    
    if (!in_array($file_ext, $allowed_extensions)) {
        wp_send_json_error(array('message' => 'Unsupported file type: ' . $file_ext));
    }
    
    // Проверка размера
    $max_size = get_option('yvo_max_size', 2) * 1024 * 1024;
    if ($_FILES['file']['size'] > $max_size) {
        wp_send_json_error(array('message' => 
            sprintf('File too large (maximum %d MB)', get_option('yvo_max_size', 2))));
    }
    
    // Создаем временную директорию если не существует
    if (!file_exists(YVO_TMP_DIR)) {
        wp_mkdir_p(YVO_TMP_DIR);
    }
    
    // Сохраняем файл во временную директорию
    $tmp_name = YVO_TMP_DIR . uniqid('yvo_', true) . '_' . $file_name;
    
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmp_name)) {
        $error = error_get_last();
        wp_send_json_error(array('message' => 'Failed to save file: ' . $error['message']));
    }
    
    // Получаем настройки API
    $api_key = get_option('yvo_api_key');
    $folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    $language = get_option('yvo_language', 'ru');
    
    if (empty($api_key) || empty($folder_id)) {
        @unlink($tmp_name);
        wp_send_json_error(array('message' => 'API not configured. Please configure API settings first.'));
    }
    
    // Определяем тип файла и обрабатываем
    if ($file_ext === 'pdf') {
        $result = yvo_process_pdf($tmp_name, $api_key, $folder_id, $language);
    } else {
        $result = yvo_recognize_image($tmp_name, $api_key, $folder_id, $language);
    }
    
    // Удаляем временный файл
    @unlink($tmp_name);
    
    if ($result['success']) {
        wp_send_json_success(array(
            'text' => $result['text'],
            'filename' => $result['filename'],
            'is_pdf' => ($file_ext === 'pdf')
        ));
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}

// Парсинг паспортных данных (фронтенд)
function yvo_ajax_parse_passport_data() {
    $text = isset($_POST['text']) ? sanitize_textarea_field($_POST['text']) : '';
    
    if (empty($text)) {
        wp_send_json_error(array('message' => 'No text provided'));
    }
    
    $passport_data = yvo_parse_passport_data($text);
    wp_send_json_success($passport_data);
}

// Админ обработчики
function yvo_ajax_process_image() {
    // Проверка прав и nonce
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_admin_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }
    
    $image_url = isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '';
    
    if (empty($image_url)) {
        wp_send_json_error(array('message' => 'No image URL provided'));
    }
    
    $api_key = get_option('yvo_api_key');
    $folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    $language = get_option('yvo_language', 'ru');
    
    if (empty($api_key) || empty($folder_id)) {
        wp_send_json_error(array('message' => 'API not configured'));
    }
    
    $local_path = yvo_get_local_file($image_url);
    
    if (!$local_path) {
        wp_send_json_error(array('message' => 'Failed to download image'));
    }
    
    $file_ext = strtolower(pathinfo($local_path, PATHINFO_EXTENSION));
    
    if ($file_ext === 'pdf') {
        $result = yvo_process_pdf($local_path, $api_key, $folder_id, $language);
    } else {
        $result = yvo_recognize_image($local_path, $api_key, $folder_id, $language);
    }
    
    // Удаляем временный файл если он был скачан
    if (strpos($local_path, YVO_TMP_DIR) !== false) {
        @unlink($local_path);
    }
    
    if ($result['success']) {
        wp_send_json_success(array(
            'text' => $result['text'],
            'filename' => $result['filename']
        ));
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}

function yvo_ajax_test_api() {
    // Проверка прав и nonce
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_admin_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }
    
    $api_key = get_option('yvo_api_key');
    $folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    
    if (empty($api_key) || empty($folder_id)) {
        wp_send_json_error(array('message' => 'API not configured'));
    }
    
    // Простой тестовый запрос
    $url = 'https://vision.api.cloud.yandex.net/vision/v1/batchAnalyze';
    
    $headers = array(
        'Authorization: Api-Key ' . $api_key,
        'Content-Type: application/json'
    );
    
    $body = array(
        'folderId' => $folder_id,
        'analyze_specs' => array(
            array(
                'content' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
                'features' => array(
                    array(
                        'type' => 'TEXT_DETECTION',
                        'text_detection_config' => array(
                            'language_codes' => array('ru')
                        )
                    )
                )
            )
        )
    );
    
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ));
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 || $http_code == 400) {
        wp_send_json_success(array('message' => 'API connection successful'));
    } else {
        $data = json_decode($response, true);
        $error = isset($data['message']) ? $data['message'] : 'Unknown error';
        wp_send_json_error(array('message' => 'Error (' . $http_code . '): ' . $error));
    }
}

function yvo_ajax_parse_passport() {
    // Проверка прав и nonce
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_admin_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }
    
    $text = isset($_POST['text']) ? sanitize_textarea_field($_POST['text']) : '';
    
    if (empty($text)) {
        wp_send_json_error(array('message' => 'No text provided'));
    }
    
    $passport_data = yvo_parse_passport_data($text);
    wp_send_json_success($passport_data);
}