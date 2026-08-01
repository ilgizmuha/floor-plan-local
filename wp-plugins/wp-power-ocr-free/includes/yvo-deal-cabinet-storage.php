<?php
/**
 * Файлы сделок CRM: загрузка в uploads/yvo-deals/{user}/{deal}/, индекс в user_meta.
 */
if (!defined('ABSPATH')) {
    exit;
}

define('YVO_DEAL_DOC_META', 'yvo_deal_doc_index');
define('YVO_DEAL_CRM_META', 'yvo_deal_crm_deals');

add_action('wp_ajax_yvo_deal_upload', 'yvo_ajax_deal_upload');
add_action('wp_ajax_yvo_deal_list', 'yvo_ajax_deal_list');
add_action('wp_ajax_yvo_deal_delete', 'yvo_ajax_deal_delete');
add_action('wp_ajax_yvo_deal_download', 'yvo_ajax_deal_download');
add_action('wp_ajax_yvo_deal_convert', 'yvo_ajax_deal_convert');
add_action('wp_ajax_yvo_deal_crm_list', 'yvo_ajax_deal_crm_list');
add_action('wp_ajax_yvo_deal_crm_save', 'yvo_ajax_deal_crm_save');

function yvo_deal_cabinet_verify_ajax_nonce() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yvo_deal_cabinet')) {
        wp_send_json_error(array('message' => 'Сессия устарела. Обновите страницу.'));
    }
}

/**
 * @return true|WP_Error
 */
function yvo_deal_cabinet_check_access() {
    if (!is_user_logged_in()) {
        return new WP_Error('auth', 'Требуется вход.');
    }
    if (!function_exists('yvo_cabinet_user_can_see_deal_crm') || !yvo_cabinet_user_can_see_deal_crm(get_current_user_id())) {
        return new WP_Error('tariff', 'Нет доступа к разделу.');
    }
    return true;
}

/**
 * @return string|false
 */
function yvo_deal_cabinet_base_dir($user_id, $deal_id) {
    $upload = wp_upload_dir();
    if (!empty($upload['error'])) {
        return false;
    }
    $deal_id = absint($deal_id);
    return $upload['basedir'] . '/yvo-deals/' . (int) $user_id . '/' . $deal_id;
}

/**
 * @return string
 */
function yvo_deal_cabinet_base_url($user_id, $deal_id) {
    $upload = wp_upload_dir();
    $deal_id = absint($deal_id);
    return $upload['baseurl'] . '/yvo-deals/' . (int) $user_id . '/' . $deal_id;
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function yvo_deal_cabinet_get_index($user_id) {
    $raw = get_user_meta($user_id, YVO_DEAL_DOC_META, true);
    return is_array($raw) ? $raw : array();
}

/**
 * @param array<string, array<int, array<string, mixed>>> $index
 */
function yvo_deal_cabinet_save_index($user_id, $index) {
    update_user_meta($user_id, YVO_DEAL_DOC_META, $index);
}

/**
 * CRM сделки (метаданные) — храним в user_meta, чтобы форма договора могла подтягивать.
 * @return array<int, array<string, mixed>>
 */
function yvo_deal_crm_get_deals($user_id) {
    $raw = get_user_meta($user_id, YVO_DEAL_CRM_META, true);
    return is_array($raw) ? $raw : array();
}

/**
 * @param array<int, array<string, mixed>> $deals
 */
function yvo_deal_crm_save_deals($user_id, $deals) {
    update_user_meta($user_id, YVO_DEAL_CRM_META, $deals);
}

function yvo_ajax_deal_crm_list() {
    // CRM список сделок нужен и на странице формы договора (frontend.js), поэтому принимаем оба nonce.
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (
        !$nonce
        || (!wp_verify_nonce($nonce, 'yvo_deal_cabinet') && !wp_verify_nonce($nonce, 'yvo_frontend_contract'))
    ) {
        wp_send_json_error(array('message' => 'Сессия устарела. Обновите страницу.'));
    }
    $err = yvo_deal_cabinet_check_access();
    if (is_wp_error($err)) {
        wp_send_json_error(array('message' => $err->get_error_message()));
    }
    $uid = get_current_user_id();
    wp_send_json_success(array('deals' => yvo_deal_crm_get_deals($uid)));
}

function yvo_ajax_deal_crm_save() {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (
        !$nonce
        || (!wp_verify_nonce($nonce, 'yvo_deal_cabinet') && !wp_verify_nonce($nonce, 'yvo_frontend_contract'))
    ) {
        wp_send_json_error(array('message' => 'Сессия устарела. Обновите страницу.'));
    }
    $err = yvo_deal_cabinet_check_access();
    if (is_wp_error($err)) {
        wp_send_json_error(array('message' => $err->get_error_message()));
    }
    $uid = get_current_user_id();
    $deals = json_decode(isset($_POST['deals']) ? wp_unslash($_POST['deals']) : '[]', true);
    if (!is_array($deals)) {
        wp_send_json_error(array('message' => 'Некорректные данные.'));
    }
    // Лёгкая санация: оставляем массивы/скаляры, ограничиваем размер.
    if (count($deals) > 500) {
        $deals = array_slice($deals, 0, 500);
    }
    yvo_deal_crm_save_deals($uid, $deals);
    wp_send_json_success(array('ok' => true));
}

function yvo_ajax_deal_upload() {
    yvo_deal_cabinet_verify_ajax_nonce();
    $err = yvo_deal_cabinet_check_access();
    if (is_wp_error($err)) {
        wp_send_json_error(array('message' => $err->get_error_message()));
    }
    $uid = get_current_user_id();
    $deal_id = isset($_POST['deal_id']) ? absint(wp_unslash($_POST['deal_id'])) : 0;
    if ($deal_id < 1) {
        wp_send_json_error(array('message' => 'Укажите сделку.'));
    }
    if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name'])) {
        wp_send_json_error(array('message' => 'Файл не передан.'));
    }
    $file = $_FILES['file'];
    if (!empty($file['error'])) {
        $code = (int) $file['error'];
        $map = array(
            UPLOAD_ERR_INI_SIZE   => 'Файл превышает upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE  => 'Файл превышает MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL    => 'Файл загружен частично.',
            UPLOAD_ERR_NO_FILE    => 'Файл не загружен.',
            UPLOAD_ERR_NO_TMP_DIR => 'Нет временной папки.',
            UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск.',
            UPLOAD_ERR_EXTENSION  => 'Загрузка остановлена расширением PHP.',
        );
        $msg = isset($map[$code]) ? $map[$code] : 'Ошибка загрузки.';
        wp_send_json_error(array('message' => $msg . ' (код ' . $code . ', upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size') . ')'));
    }
    $max = 10 * 1024 * 1024;
    if ((int) $file['size'] > $max) {
        wp_send_json_error(array('message' => 'Файл больше 10 МБ.'));
    }
    $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    $ext = isset($check['ext']) ? strtolower((string) $check['ext']) : '';
    if ($ext === '') {
        $check2 = wp_check_filetype($file['name']);
        $ext = isset($check2['ext']) ? strtolower((string) $check2['ext']) : '';
    }
    if ($ext === '') {
        $pi = pathinfo($file['name']);
        if (!empty($pi['extension'])) {
            $ext = strtolower((string) $pi['extension']);
        }
    }
    $allowed = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'txt', 'rtf', 'odt', 'ods');
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        wp_send_json_error(array('message' => 'Недопустимый тип файла.'));
    }
    $dir = yvo_deal_cabinet_base_dir($uid, $deal_id);
    if (!$dir) {
        wp_send_json_error(array('message' => 'Каталог загрузок недоступен.'));
    }
    if (!wp_mkdir_p($dir)) {
        wp_send_json_error(array('message' => 'Не удалось создать каталог.'));
    }
    $display_name = sanitize_text_field(wp_unslash($file['name']));
    if ($display_name === '') {
        $display_name = 'document.' . $ext;
    }
    $orig = sanitize_file_name($display_name);
    // На некоторых хостингах/настройках кириллица может давать пустое имя после sanitize_file_name().
    // В этом случае используем безопасное имя для сохранения, но пользователю показываем исходное.
    if ($orig === '' || $orig === '.' . $ext) {
        $orig = 'document-' . date('Ymd-His') . '.' . $ext;
    }
    // Use wp_handle_sideload(): avoids is_uploaded_file() edge cases in some AJAX environments.
    if (!function_exists('wp_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $allowed_mimes = array(
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'zip'  => 'application/zip',
        'txt'  => 'text/plain',
        'rtf'  => 'application/rtf',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
    );

    // Allow our mime types even if site restricts uploads.
    $mimes_filter = function ($m) use ($allowed_mimes) {
        if (!is_array($m)) {
            $m = array();
        }
        foreach ($allowed_mimes as $k => $v) {
            $m[$k] = $v;
        }
        return $m;
    };
    add_filter('upload_mimes', $mimes_filter, 1000, 1);

    $upload_dir_filter = function ($u) use ($uid, $deal_id) {
            $sub = '/yvo-deals/' . (int) $uid . '/' . absint($deal_id);
            $u['subdir'] = $sub;
            $u['path'] = $u['basedir'] . $sub;
            $u['url'] = $u['baseurl'] . $sub;
            if (!is_dir($u['path'])) {
                wp_mkdir_p($u['path']);
            }
            return $u;
        };
    add_filter('upload_dir', $upload_dir_filter, 1000, 1);
    $upload_file = array(
        'name'     => $orig,
        'type'     => $file['type'],
        'tmp_name' => $file['tmp_name'],
        'error'    => $file['error'],
        'size'     => $file['size'],
    );
    if (empty($upload_file['tmp_name']) || !@is_readable($upload_file['tmp_name'])) {
        wp_send_json_error(array('message' => 'Временный файл недоступен (tmp_name unreadable).'));
    }
    $handled = wp_handle_sideload(
        $upload_file,
        array(
            'test_form' => false,
            'mimes'     => $allowed_mimes,
        )
    );
    remove_filter('upload_dir', $upload_dir_filter, 1000);
    remove_filter('upload_mimes', $mimes_filter, 1000);
    if (isset($handled['error'])) {
        $max_file = ini_get('upload_max_filesize');
        $max_post = ini_get('post_max_size');
        $writable = is_writable($dir) ? 'yes' : 'no';
        wp_send_json_error(
            array(
                'message' => 'Ошибка сохранения: ' . (string) $handled['error'] . ' (upload_max_filesize=' . $max_file . ', post_max_size=' . $max_post . ', dir_writable=' . $writable . ')',
            )
        );
    }
    $dest = isset($handled['file']) ? (string) $handled['file'] : '';
    $url = isset($handled['url']) ? (string) $handled['url'] : '';
    if ($dest === '' || $url === '') {
        wp_send_json_error(array('message' => 'Ошибка сохранения: некорректный ответ wp_handle_upload.'));
    }
    $unique = basename($dest);
    $file_id = 'f' . wp_generate_password(14, false, false);
    $record = array(
        'id' => $file_id,
        'file' => $unique,
        'name' => $display_name,
        'size' => (int) (is_file($dest) ? filesize($dest) : 0),
        'time' => time(),
    );
    $index = yvo_deal_cabinet_get_index($uid);
    $key = (string) $deal_id;
    if (!isset($index[$key])) {
        $index[$key] = array();
    }
    $index[$key][] = $record;
    yvo_deal_cabinet_save_index($uid, $index);
    wp_send_json_success(
        array(
            'id' => $file_id,
            'name' => $display_name,
            'url' => $url,
            'size' => $record['size'],
        )
    );
}

function yvo_ajax_deal_list() {
    yvo_deal_cabinet_verify_ajax_nonce();
    $err = yvo_deal_cabinet_check_access();
    if (is_wp_error($err)) {
        wp_send_json_error(array('message' => $err->get_error_message()));
    }
    $uid = get_current_user_id();
    $index = yvo_deal_cabinet_get_index($uid);
    $out = array();
    foreach ($index as $did => $items) {
        $did = absint($did);
        foreach ((array) $items as $it) {
            if (empty($it['file'])) {
                continue;
            }
            $base = yvo_deal_cabinet_base_url($uid, $did);
            $out[] = array(
                'deal_id' => $did,
                'id' => isset($it['id']) ? (string) $it['id'] : '',
                'name' => isset($it['name']) ? (string) $it['name'] : (string) $it['file'],
                'url' => $base . '/' . rawurlencode((string) $it['file']),
                'size' => isset($it['size']) ? (int) $it['size'] : 0,
            );
        }
    }
    wp_send_json_success(array('files' => $out));
}

function yvo_ajax_deal_delete() {
    yvo_deal_cabinet_verify_ajax_nonce();
    $err = yvo_deal_cabinet_check_access();
    if (is_wp_error($err)) {
        wp_send_json_error(array('message' => $err->get_error_message()));
    }
    $uid = get_current_user_id();
    $deal_id = isset($_POST['deal_id']) ? absint(wp_unslash($_POST['deal_id'])) : 0;
    $file_id = isset($_POST['file_id']) ? sanitize_text_field(wp_unslash($_POST['file_id'])) : '';
    if ($deal_id < 1 || $file_id === '') {
        wp_send_json_error(array('message' => 'Некорректные данные.'));
    }
    $index = yvo_deal_cabinet_get_index($uid);
    $key = (string) $deal_id;
    if (empty($index[$key])) {
        wp_send_json_error(array('message' => 'Файл не найден.'));
    }
    $fname = '';
    $new = array();
    foreach ($index[$key] as $it) {
        if (isset($it['id']) && (string) $it['id'] === $file_id) {
            $fname = isset($it['file']) ? (string) $it['file'] : '';
            continue;
        }
        $new[] = $it;
    }
    if ($fname === '') {
        wp_send_json_error(array('message' => 'Файл не найден.'));
    }
    $path = yvo_deal_cabinet_base_dir($uid, $deal_id) . '/' . $fname;
    if (is_file($path)) {
        wp_delete_file($path);
    }
    $index[$key] = $new;
    if (empty($index[$key])) {
        unset($index[$key]);
    }
    yvo_deal_cabinet_save_index($uid, $index);
    wp_send_json_success(array('ok' => true));
}

function yvo_ajax_deal_download() {
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'yvo_deal_cabinet')) {
        wp_die('Сессия устарела. Обновите страницу.', 403);
    }
    $err = yvo_deal_cabinet_check_access();
    if (is_wp_error($err)) {
        wp_die($err->get_error_message(), 403);
    }

    $uid = get_current_user_id();
    $deal_id = isset($_REQUEST['deal_id']) ? absint(wp_unslash($_REQUEST['deal_id'])) : 0;
    $file_id = isset($_REQUEST['file_id']) ? sanitize_text_field(wp_unslash($_REQUEST['file_id'])) : '';
    if ($deal_id < 1 || $file_id === '') {
        wp_die('Некорректные данные.', 400);
    }

    $index = yvo_deal_cabinet_get_index($uid);
    $key = (string) $deal_id;
    if (empty($index[$key])) {
        wp_die('Файл не найден.', 404);
    }

    $fname = '';
    $orig = '';
    foreach ($index[$key] as $it) {
        if (isset($it['id']) && (string) $it['id'] === $file_id) {
            $fname = isset($it['file']) ? (string) $it['file'] : '';
            $orig = isset($it['name']) ? (string) $it['name'] : $fname;
            break;
        }
    }
    if ($fname === '') {
        wp_die('Файл не найден.', 404);
    }

    $path = yvo_deal_cabinet_base_dir($uid, $deal_id) . '/' . $fname;
    if (!is_file($path)) {
        wp_die('Файл не найден.', 404);
    }

    $disp = isset($_REQUEST['disposition']) ? sanitize_key(wp_unslash($_REQUEST['disposition'])) : 'attachment';
    if (!in_array($disp, array('inline', 'attachment'), true)) {
        $disp = 'attachment';
    }

    if (ob_get_level()) {
        @ob_end_clean();
    }
    nocache_headers();
    $ft = wp_check_filetype($path);
    $mime = !empty($ft['type']) ? $ft['type'] : 'application/octet-stream';
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode(basename($orig ?: $fname)) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Connection: close');
    readfile($path);
    wp_die();
}

function yvo_ajax_deal_convert() {
    yvo_deal_cabinet_verify_ajax_nonce();
    $err = yvo_deal_cabinet_check_access();
    if (is_wp_error($err)) {
        wp_send_json_error(array('message' => $err->get_error_message()));
    }
    if (!class_exists('Imagick')) {
        wp_send_json_error(array('message' => 'Конвертация недоступна: на сервере нет Imagick.'));
    }

    $uid = get_current_user_id();
    $deal_id = isset($_POST['deal_id']) ? absint(wp_unslash($_POST['deal_id'])) : 0;
    $file_id = isset($_POST['file_id']) ? sanitize_text_field(wp_unslash($_POST['file_id'])) : '';
    $target = isset($_POST['target']) ? sanitize_key(wp_unslash($_POST['target'])) : '';

    if ($deal_id < 1 || $file_id === '' || $target === '') {
        wp_send_json_error(array('message' => 'Некорректные данные.'));
    }

    $index = yvo_deal_cabinet_get_index($uid);
    $key = (string) $deal_id;
    if (empty($index[$key])) {
        wp_send_json_error(array('message' => 'Файл не найден.'));
    }

    $fname = '';
    $orig = '';
    foreach ($index[$key] as $it) {
        if (isset($it['id']) && (string) $it['id'] === $file_id) {
            $fname = isset($it['file']) ? (string) $it['file'] : '';
            $orig = isset($it['name']) ? (string) $it['name'] : $fname;
            break;
        }
    }
    if ($fname === '') {
        wp_send_json_error(array('message' => 'Файл не найден.'));
    }

    $dir = yvo_deal_cabinet_base_dir($uid, $deal_id);
    if (!$dir || !is_dir($dir)) {
        wp_send_json_error(array('message' => 'Каталог сделки не найден.'));
    }
    $src = $dir . '/' . $fname;
    if (!is_file($src)) {
        wp_send_json_error(array('message' => 'Файл не найден на диске.'));
    }

    $pi = pathinfo($fname);
    $ext = isset($pi['extension']) ? strtolower((string) $pi['extension']) : '';
    $base = isset($pi['filename']) ? (string) $pi['filename'] : 'file';

    try {
        if ($target === 'pdf') {
            if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) {
                wp_send_json_error(array('message' => 'В PDF можно конвертировать только изображения.'));
            }
            $out_name = wp_unique_filename($dir, $base . '.pdf');
            $out_path = $dir . '/' . $out_name;

            $im = new Imagick();
            $im->readImage($src);
            $im->setImageFormat('pdf');
            $im->writeImages($out_path, true);
            $im->clear();
            $im->destroy();

            $new_id = 'f' . wp_generate_password(14, false, false);
            $new_orig = $base . '.pdf';
            $index[$key][] = array(
                'id' => $new_id,
                'file' => $out_name,
                'name' => $new_orig,
                'size' => (int) filesize($out_path),
                'time' => time(),
            );
            yvo_deal_cabinet_save_index($uid, $index);

            $url = yvo_deal_cabinet_base_url($uid, $deal_id) . '/' . rawurlencode($out_name);
            wp_send_json_success(array('file' => array('deal_id' => $deal_id, 'id' => $new_id, 'name' => $new_orig, 'url' => $url)));
        }

        if ($target === 'jpg') {
            if ($ext !== 'pdf') {
                wp_send_json_error(array('message' => 'В JPG можно конвертировать только PDF.'));
            }
            $out_name2 = wp_unique_filename($dir, $base . '.jpg');
            $out_path2 = $dir . '/' . $out_name2;

            $im2 = new Imagick();
            $im2->setResolution(150, 150);
            $im2->readImage($src . '[0]');
            $im2->setImageFormat('jpeg');
            $im2->setImageCompressionQuality(90);
            $im2->writeImage($out_path2);
            $im2->clear();
            $im2->destroy();

            $new_id2 = 'f' . wp_generate_password(14, false, false);
            $new_orig2 = $base . '.jpg';
            $index[$key][] = array(
                'id' => $new_id2,
                'file' => $out_name2,
                'name' => $new_orig2,
                'size' => (int) filesize($out_path2),
                'time' => time(),
            );
            yvo_deal_cabinet_save_index($uid, $index);

            $url2 = yvo_deal_cabinet_base_url($uid, $deal_id) . '/' . rawurlencode($out_name2);
            wp_send_json_success(array('file' => array('deal_id' => $deal_id, 'id' => $new_id2, 'name' => $new_orig2, 'url' => $url2)));
        }

        wp_send_json_error(array('message' => 'Неизвестный формат конвертации.'));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Ошибка конвертации: ' . $e->getMessage()));
    }
}
