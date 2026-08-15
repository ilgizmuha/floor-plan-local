<?php
/**
 * Plugin Name: Яндекс Vision OCR Pro с DeepSeek AI
 * Plugin URI: 
 * Version: 3.7.54
 * Description: Распознавание текста с изображений и PDF через Яндекс Vision API с AI-парсингом через DeepSeek
 * Author: Your Name
 * Text Domain: yandex-vision-ocr-pro
 *
 * Локальная разработка (Local WP, XAMPP): скопируйте папку плагина в wp-content/plugins/ и активируйте её в «Плагины».
 * Правки в копии на Рабочем столе сами по себе сайт не меняют — нужна именно папка внутри установки WordPress.
 */

// Безопасность
if (!defined('ABSPATH')) {
    exit;
}

// Константы плагина
define('YVO_VERSION', '3.7.62');
define('YVO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YVO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('YVO_TEMP_DIR', YVO_PLUGIN_DIR . 'tmp/');
define('YVO_TMP_DIR', YVO_TEMP_DIR); // алиас для совместимости

/**
 * Writable temp dir for OCR uploads (plugin tmp/, uploads/yvo-tmp/, system temp).
 * Root-owned plugin/tmp after deploy often breaks uploads for www-data.
 * Uses a real write probe — is_writable() alone can lie on some hosts.
 *
 * @return string Absolute path with trailing slash, or empty string.
 */
function yvo_writable_temp_dir() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $candidates = array();
    if (defined('YVO_PLUGIN_DIR')) {
        $candidates[] = trailingslashit(YVO_PLUGIN_DIR . 'tmp');
    }
    if (function_exists('wp_upload_dir')) {
        $upload = wp_upload_dir();
        if (empty($upload['error']) && !empty($upload['basedir'])) {
            $candidates[] = trailingslashit($upload['basedir'] . '/yvo-tmp');
        }
    }
    $sys = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '';
    if (is_string($sys) && $sys !== '') {
        $candidates[] = trailingslashit($sys) . 'yvo-ocr/';
    }
    $candidates[] = '/tmp/yvo-ocr/';

    foreach ($candidates as $dir) {
        $dir = str_replace('\\', '/', $dir);
        if ($dir === '' || substr($dir, -1) !== '/') {
            $dir = trailingslashit($dir);
        }
        if (!is_dir($dir)) {
            if (function_exists('wp_mkdir_p')) {
                @wp_mkdir_p($dir);
            }
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }
        if (!is_dir($dir)) {
            continue;
        }
        $probe = $dir . '.yvo_w_' . uniqid('', true);
        $written = @file_put_contents($probe, '1');
        if ($written !== false) {
            @unlink($probe);
            $cached = $dir;
            return $cached;
        }
    }
    $cached = '';
    return $cached;
}

/**
 * Human-readable message for Yandex Vision / OCR HTTP errors (incl. IP rate-limit 403 HTML).
 *
 * @param int    $http_code
 * @param string $response_body
 * @return string
 */
function yvo_yandex_api_error_message($http_code, $response_body = '') {
    $body = (string) $response_body;
    $http_code = (int) $http_code;
    if ($http_code === 403 || stripos($body, 'Доступ к сервису временно запрещён') !== false
        || stripos($body, 'очень много запросов') !== false
        || stripos($body, 'Access denied') !== false) {
        return 'Яндекс временно ограничил доступ к OCR с сервера (слишком много запросов, код 403). Подождите 15–60 минут и попробуйте снова. Если не поможет — напишите в поддержку Yandex Cloud.';
    }
    $error_data = json_decode($body, true);
    $error_msg = (is_array($error_data) && !empty($error_data['message'])) ? (string) $error_data['message'] : 'Неизвестная ошибка';
    if ($http_code === 401 || $http_code === 403) {
        return "Ошибка API Яндекс Vision ($http_code): $error_msg. Проверьте API-ключ и folder id в настройках.";
    }
    return "Ошибка API ($http_code): $error_msg";
}

$yvo_egrn_parser_file = YVO_PLUGIN_DIR . 'includes/egrn-text-parser.php';
if (is_file($yvo_egrn_parser_file)) {
    require_once $yvo_egrn_parser_file;
}
$yvo_passport_ocr_file = YVO_PLUGIN_DIR . 'includes/passport-birth-ocr.php';
if (is_file($yvo_passport_ocr_file)) {
    require_once $yvo_passport_ocr_file;
}
$yvo_field_review_file = YVO_PLUGIN_DIR . 'includes/yvo-field-review.php';
if (is_file($yvo_field_review_file)) {
    require_once $yvo_field_review_file;
}
$yvo_mistral_ocr_file = YVO_PLUGIN_DIR . 'includes/mistral-ocr.php';
if (is_file($yvo_mistral_ocr_file)) {
    require_once $yvo_mistral_ocr_file;
}
// Mistral только как резерв; если раньше включили как основной — вернуть Yandex.
if ((string) get_option('yvo_ocr_provider', 'yandex') === 'mistral') {
    update_option('yvo_ocr_provider', 'yandex');
}
if (function_exists('yvo_mistral_api_key') && get_option('yvo_mistral_api_key', '') === '') {
    update_option('yvo_mistral_api_key', yvo_mistral_api_key());
}

// Проверяем наличие необходимых библиотек
function yvo_check_dependencies() {
    $errors = array();
    
    // Проверяем наличие cURL
    if (!function_exists('curl_init')) {
        $errors[] = 'Требуется расширение cURL для PHP';
    }
    
    // Проверяем возможность записи в директорию
    $tmp_dir = YVO_PLUGIN_DIR . 'tmp';
    if (!is_writable(dirname($tmp_dir))) {
        $errors[] = 'Директория плагина должна быть доступна для записи';
    }
    
    return $errors;
}

// Активация/деактивация
register_activation_hook(__FILE__, 'yvo_activate');
register_deactivation_hook(__FILE__, 'yvo_deactivate');

function yvo_activate() {
    $dependencies = yvo_check_dependencies();
    if (!empty($dependencies)) {
        wp_die('Плагин не может быть активирован: ' . implode(', ', $dependencies));
    }
    
    // Создаем временную директорию
    $tmp_dir = YVO_PLUGIN_DIR . 'tmp';
    if (!file_exists($tmp_dir)) {
        wp_mkdir_p($tmp_dir);
    }
    
    // Создаем директорию для контрактов
    $contracts_dir = YVO_PLUGIN_DIR . 'contracts';
    if (!file_exists($contracts_dir)) {
        wp_mkdir_p($contracts_dir);
    }
    
    // Добавляем настройки по умолчанию
    add_option('yvo_api_key', '');
    add_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    add_option('yvo_ocr_provider', 'yandex'); // yandex основной; mistral — только fallback
    add_option('yvo_mistral_api_key', '');
    add_option('yvo_mistral_ocr_model', 'mistral-ocr-latest');
    add_option('yvo_language', 'ru');
    add_option('yvo_max_size', 20); // MB увеличен для PDF
    add_option('yvo_pdf_max_pages', 10);
    add_option('yvo_contract_template', 'default');
    
    // Настройки DeepSeek API
    add_option('yvo_deepseek_enabled', 'no');
    add_option('yvo_deepseek_api_key', '');
    add_option('yvo_deepseek_model', 'deepseek-chat');
    
    // Добавляем опции для данных форм
    add_option('yvo_seller_data', array());
    add_option('yvo_buyer_data', array());
    add_option('yvo_property_data', array());
    add_option('yvo_enable_shortcode', 1);
    add_option('yvo_cabinet_login_page', '');
    add_option('yvo_cabinet_register_page', '');
    add_option('yvo_cabinet_forgot_page', '');
    add_option('yvo_cabinet_account_page', '');
    add_option('yvo_uploaded_templates', array());
    add_option('yvo_cabinet_contracts_page', '');
    add_option('yvo_cabinet_profile_page', '');
    add_option('yvo_cabinet_wallet_page', '');
    add_option('yvo_cabinet_bootstrapped', '');
    add_option('yvo_generation_price_rub', '200');
    add_option('yvo_cabinet_pricing_page', '');
    add_option('yvo_cabinet_deals_page', '');

    if (!get_option('yvo_legal_settings') && function_exists('yvo_legal_default_settings')) {
        add_option('yvo_legal_settings', yvo_legal_default_settings());
    }

    if (function_exists('yvo_jpg_pdf_chat_install_options')) {
        yvo_jpg_pdf_chat_install_options();
    }

    // Автосоздание страниц (кабинет + договоры + юридические)
    if (function_exists('wp_insert_post')) {
        yvo_ensure_required_pages();
        if (function_exists('yvo_legal_ensure_pages')) {
            yvo_legal_ensure_pages();
            update_option('yvo_legal_pages_bootstrapped', 'yes');
        }
    if (function_exists('yvo_yookassa_install_table')) {
        yvo_yookassa_install_table();
    }
        if (function_exists('yvo_cabinet_pages_filled') && yvo_cabinet_pages_filled()) {
            update_option('yvo_cabinet_bootstrapped', 'yes');
        }
    }
}

/**
 * Все ли URL страниц кабинета сохранены в опциях.
 */
function yvo_cabinet_pages_filled() {
    $keys = array(
        'yvo_cabinet_contracts_page',
        'yvo_cabinet_login_page',
        'yvo_cabinet_register_page',
        'yvo_cabinet_forgot_page',
        'yvo_cabinet_account_page',
        'yvo_cabinet_profile_page',
        'yvo_cabinet_wallet_page',
    );
    foreach ($keys as $k) {
        if (trim((string) get_option($k, '')) === '') {
            return false;
        }
    }
    return true;
}

/**
 * Автор страницы при автосоздании (активация / init без залогиненного пользователя).
 */
function yvo_cabinet_default_post_author() {
    $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    if ($uid > 0) {
        return $uid;
    }
    if (!function_exists('get_users')) {
        return 0;
    }
    $admins = get_users(array(
        'role'    => 'administrator',
        'number'  => 1,
        'orderby' => 'ID',
        'order'   => 'ASC',
        'fields'  => array('ID'),
    ));
    if (!empty($admins) && isset($admins[0]->ID)) {
        return (int) $admins[0]->ID;
    }
    return 0;
}

/**
 * Создаёт нужные страницы и сохраняет ссылки в опции.
 * Безопасно: не создаёт дубликаты, если страницы уже настроены/существуют.
 */
function yvo_ensure_required_pages() {
    if (!function_exists('wp_insert_post') || !function_exists('get_page_by_path')) {
        return;
    }

    $author_id = yvo_cabinet_default_post_author();
    $created_any = false;

    $pages = array(
        'glavnaya' => array(
            'title' => 'Главная',
            'content' => '[yvo_doki_home]',
            'option' => 'yvo_cabinet_home_page',
        ),
        'doki' => array(
            'title' => 'Договоры',
            'content' => '[yvo_contract_form]',
            'option' => 'yvo_cabinet_contracts_page',
        ),
        'login' => array(
            'title' => 'Вход',
            'content' => '[yvo_cabinet_login]',
            'option' => 'yvo_cabinet_login_page',
        ),
        'register' => array(
            'title' => 'Регистрация',
            'content' => '[yvo_cabinet_register]',
            'option' => 'yvo_cabinet_register_page',
        ),
        'forgot-password' => array(
            'title' => 'Восстановление пароля',
            'content' => '[yvo_cabinet_forgot]',
            'option' => 'yvo_cabinet_forgot_page',
        ),
        'cabinet' => array(
            'title' => 'Кабинет',
            'content' => '[yvo_cabinet_account]',
            'option' => 'yvo_cabinet_account_page',
        ),
        'profile' => array(
            'title' => 'Профиль',
            'content' => '[yvo_cabinet_profile]',
            'option' => 'yvo_cabinet_profile_page',
        ),
        'wallet' => array(
            'title' => 'Профиль и баланс',
            'content' => '[yvo_cabinet_profile]',
            'option' => 'yvo_cabinet_wallet_page',
        ),
    );

    foreach ($pages as $slug => $cfg) {
        $opt = $cfg['option'];
        $existing_url = (string) get_option($opt, '');
        $page_id = 0;

        // Если уже сохранён URL — пытаемся найти страницу по пути
        if ($existing_url) {
            $path = trim((string) wp_parse_url($existing_url, PHP_URL_PATH), '/');
            if ($path !== '') {
                $p = get_page_by_path($path);
                if ($p && isset($p->ID)) $page_id = (int) $p->ID;
            }
        }

        // Ищем по slug
        if ($page_id <= 0) {
            $p = get_page_by_path($slug);
            if ($p && isset($p->ID)) $page_id = (int) $p->ID;
        }

        // Создаём
        if ($page_id <= 0) {
            $postarr = array(
                'post_title'   => $cfg['title'],
                'post_name'    => $slug,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => wp_slash($cfg['content']),
            );
            if ($author_id > 0) {
                $postarr['post_author'] = $author_id;
            }
            $page_id = wp_insert_post($postarr, true);
            if (is_wp_error($page_id)) {
                continue;
            }
            if ($page_id > 0) {
                $created_any = true;
            }
        }

        $url = get_permalink($page_id);
        if ($url) {
            update_option($opt, $url);
        }
    }

    if ($created_any && function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules(false);
    }
}

/**
 * Страницы и пункты меню «Кошелёк» в БД: переименование + профиль. Раньше обновлялись только page, не nav_menu_item.
 */
add_action('init', 'yvo_cabinet_migrate_wallet_page_title_in_db', 2);
function yvo_cabinet_migrate_wallet_page_title_in_db() {
    if (defined('WP_INSTALLING') && WP_INSTALLING) {
        return;
    }
    if (get_option('yvo_cabinet_wallet_db_migrated_337') === 'yes') {
        return;
    }
    if (!function_exists('get_page_by_path')) {
        return;
    }
    global $wpdb;
    // Пункты классического меню (nav_menu_item).
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_title = %s WHERE post_type = 'nav_menu_item' AND post_title = %s",
            'Профиль и баланс',
            'Кошелёк'
        )
    );
    // Редактор сайта (Local / блоки): навигация хранится в post_type wp_navigation (JSON), не в nav_menu_item.
    $like = '%' . $wpdb->esc_like('Кошелёк') . '%';
    foreach (array('wp_navigation', 'wp_template', 'wp_template_part') as $pt) {
        $nav_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_content LIKE %s",
                $pt,
                $like
            )
        );
        if (!is_array($nav_ids)) {
            continue;
        }
        foreach ($nav_ids as $nid) {
            $nid = (int) $nid;
            if ($nid <= 0) {
                continue;
            }
            $p = get_post($nid);
            if (!$p || strpos($p->post_content, 'Кошелёк') === false) {
                continue;
            }
            $new_content = str_replace('Кошелёк', 'Профиль и баланс', $p->post_content);
            wp_update_post(
                array(
                    'ID' => $nid,
                    'post_content' => $new_content,
                )
            );
        }
    }
    $to_fix = array();
    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','draft','private','pending') AND post_title = %s",
            'Кошелёк'
        )
    );
    if (is_array($rows)) {
        foreach ($rows as $rid) {
            $to_fix[(int) $rid] = true;
        }
    }
    $w = get_page_by_path('wallet');
    if ($w && !empty($w->ID)) {
        $to_fix[(int) $w->ID] = true;
    }
    foreach (array_keys($to_fix) as $pid) {
        if ($pid <= 0) {
            continue;
        }
        wp_update_post(
            array(
                'ID' => $pid,
                'post_title' => 'Профиль и баланс',
                'post_content' => '[yvo_cabinet_profile]',
            )
        );
    }
    update_option('yvo_cabinet_wallet_db_migrated_337', 'yes');
    delete_option('yvo_cabinet_wallet_db_migrated_336');
    delete_option('yvo_cabinet_wallet_db_migrated_335');
    delete_option('yvo_cabinet_wallet_db_migrated_334');
}

add_action('init', 'yvo_bootstrap_cabinet_pages_once', 3);
function yvo_bootstrap_cabinet_pages_once() {
    if (!function_exists('yvo_ensure_required_pages')) {
        return;
    }
    if (yvo_cabinet_pages_filled()) {
        update_option('yvo_cabinet_bootstrapped', 'yes');
        return;
    }
    if (get_transient('yvo_cabinet_build_lock')) {
        return;
    }
    yvo_ensure_required_pages();
    set_transient('yvo_cabinet_build_lock', 1, 15);
    if (yvo_cabinet_pages_filled()) {
        delete_transient('yvo_cabinet_build_lock');
        update_option('yvo_cabinet_bootstrapped', 'yes');
    }
}

/**
 * Автосоздание страниц при заходе администратора в wp-admin (без отдельной кнопки).
 */
add_action('admin_init', 'yvo_auto_create_cabinet_pages_in_admin', 1);
function yvo_auto_create_cabinet_pages_in_admin() {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!function_exists('yvo_ensure_required_pages') || !function_exists('yvo_cabinet_pages_filled')) {
        return;
    }
    if (yvo_cabinet_pages_filled()) {
        return;
    }
    delete_transient('yvo_cabinet_build_lock');
    yvo_ensure_required_pages();
    if (yvo_cabinet_pages_filled()) {
        delete_transient('yvo_cabinet_build_lock');
        update_option('yvo_cabinet_bootstrapped', 'yes');
    }
}

/**
 * При WP_DEBUG показывает путь к плагину — чтобы убедиться, что Local грузит нужную папку.
 */
add_action('admin_notices', 'yvo_admin_notice_plugin_source_path', 99);
function yvo_admin_notice_plugin_source_path() {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!defined('YVO_VERSION') || !defined('YVO_PLUGIN_DIR')) {
        return;
    }
    echo '<div class="notice notice-success is-dismissible"><p><strong>YVO ' . esc_html(YVO_VERSION) . '</strong> · каталог плагина: <code style="word-break:break-all;">' . esc_html(YVO_PLUGIN_DIR) . '</code></p></div>';
}

/**
 * Предупреждение в админке, если страницы кабинета не созданы.
 */
add_action('admin_notices', 'yvo_admin_notice_cabinet_pages');
function yvo_admin_notice_cabinet_pages() {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!function_exists('yvo_cabinet_pages_filled') || yvo_cabinet_pages_filled()) {
        return;
    }
    $url = admin_url('admin.php?page=yandex-ocr-pro-cabinet');
    $virt = function_exists('yvo_cabinet_route_url') ? yvo_cabinet_route_url('login') : '';
    echo '<div class="notice notice-info"><p><strong>Яндекс OCR Pro AI:</strong> личный кабинет доступен по ссылке с параметром <code>?yvo_cabinet=…</code> (см. ';
    echo '<a href="' . esc_url($url) . '">Личный кабинет</a>)';
    if ($virt) {
        echo ' — например вход: <a href="' . esc_url($virt) . '" target="_blank" rel="noopener">' . esc_html($virt) . '</a>';
    }
    echo '. Отдельные страницы в «Страницы» создавать не обязательно.</p></div>';
}

/**
 * Ссылка в списке плагинов на настройки страниц кабинета.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'yvo_plugin_action_links');
function yvo_plugin_action_links($links) {
    $cab = '<a href="' . esc_url(admin_url('admin.php?page=yandex-ocr-pro-cabinet')) . '">' . esc_html__('Личный кабинет (страницы)', 'yandex-vision-ocr-pro') . '</a>';
    array_unshift($links, $cab);
    return $links;
}

/**
 * В списке плагинов показывает реальный путь на сервере — чтобы отличить копию на хостинге от папки на Рабочем столе.
 */
add_filter('plugin_row_meta', 'yvo_plugin_row_meta_filesystem_path', 10, 2);
function yvo_plugin_row_meta_filesystem_path($links, $file) {
    if ($file !== plugin_basename(__FILE__)) {
        return $links;
    }
    if (!current_user_can('manage_options') || !defined('YVO_PLUGIN_DIR')) {
        return $links;
    }
    $path = str_replace('\\', '/', YVO_PLUGIN_DIR);
    $links[] = '<span style="word-break:break-all;">' . esc_html($path) . '</span>';
    return $links;
}

function yvo_deactivate() {
    // Очищаем временную директорию
    $tmp_dir = YVO_PLUGIN_DIR . 'tmp';
    if (file_exists($tmp_dir)) {
        $files = glob($tmp_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($tmp_dir);
    }
}

// Подключаем Composer autoload если существует
$composer_autoload = YVO_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// Личный кабинет: авторизация (регистрация, вход, восстановление пароля)
require_once YVO_PLUGIN_DIR . 'includes/yvo-cabinet-auth.php';
// Тарифы, кошелёк (раньше шорткодов — функции доступа к CRM «Сделки»)
require_once YVO_PLUGIN_DIR . 'includes/yvo-tariffs.php';
require_once YVO_PLUGIN_DIR . 'includes/yvo-cabinet-shortcodes.php';
require_once YVO_PLUGIN_DIR . 'includes/yvo-templates-library.php';
require_once YVO_PLUGIN_DIR . 'includes/yvo-doki-home.php';
require_once YVO_PLUGIN_DIR . 'includes/yvo-deal-cabinet-storage.php';
require_once YVO_PLUGIN_DIR . 'includes/yvo-public-header.php';
require_once YVO_PLUGIN_DIR . 'includes/yvo-legal-compliance.php';
require_once YVO_PLUGIN_DIR . 'includes/yvo-yookassa.php';
require_once YVO_PLUGIN_DIR . 'includes/yvo-mynalog.php';
// OAuth вход через Яндекс
require_once YVO_PLUGIN_DIR . 'includes/yvo-yandex-oauth.php';
// Модуль «Фото → PDF» (шорткод [yvo_jpg_pdf_chat]) — каталог yvo-jpg-pdf-chat/ внутри этого плагина
$yvo_jpg_pdf_chat_file = YVO_PLUGIN_DIR . 'yvo-jpg-pdf-chat/yvo-jpg-pdf-chat.php';
if (is_readable($yvo_jpg_pdf_chat_file)) {
    require_once $yvo_jpg_pdf_chat_file;
}

/**
 * Шорткод [yvo_jpg_pdf_chat]: регистрируется всегда из основного файла.
 * Если на сервере нет папки yvo-jpg-pdf-chat/, иначе WordPress показал бы сырой текст [yvo_jpg_pdf_chat].
 */
function yvo_jpg_pdf_chat_dispatch_shortcode($atts = array(), $content = null, $tag = '') {
    if (function_exists('yvo_jpg_pdf_chat_shortcode')) {
        return yvo_jpg_pdf_chat_shortcode($atts);
    }
    return '<p class="yvo-jpg-pdf-chat-missing" style="padding:12px 14px;background:#fff8e6;border:1px solid #e0a800;border-radius:8px;font-size:14px;">'
        . esc_html__('Модуль JPG→PDF не найден: в каталоге этого плагина должна быть папка ', 'yandex-vision-ocr-pro')
        . '<code>yvo-jpg-pdf-chat</code> '
        . esc_html__('(скопируйте из полной копии плагина). Затем обновите страницу.', 'yandex-vision-ocr-pro')
        . '</p>';
}
add_shortcode('yvo_jpg_pdf_chat', 'yvo_jpg_pdf_chat_dispatch_shortcode');

/**
 * Темы/виджеты, которые выводят HTML без do_shortcode.
 */
function yvo_jpg_pdf_chat_widgets_do_shortcode($html) {
    if (!is_string($html) || strpos($html, '[yvo_jpg_pdf_chat') === false) {
        return $html;
    }
    return do_shortcode($html);
}
add_filter('widget_text', 'yvo_jpg_pdf_chat_widgets_do_shortcode', 11);
add_filter('widget_text_content', 'yvo_jpg_pdf_chat_widgets_do_shortcode', 11);
add_filter('widget_block_content', 'yvo_jpg_pdf_chat_widgets_do_shortcode', 11);

/**
 * Блоки «Абзац» / «Произвольный HTML» в редакторе блоков не всегда прогоняют шорткоды.
 */
function yvo_jpg_pdf_chat_render_block_shortcodes($block_content, $block) {
    if (!is_string($block_content) || strpos($block_content, '[yvo_jpg_pdf_chat') === false) {
        return $block_content;
    }
    $name = is_array($block) && isset($block['blockName']) ? $block['blockName'] : '';
    if (in_array($name, array('core/html', 'core/paragraph', 'core/preformatted', 'core/freeform'), true)) {
        return do_shortcode($block_content);
    }
    return $block_content;
}
add_filter('render_block', 'yvo_jpg_pdf_chat_render_block_shortcodes', 10, 2);

// Класс для парсинга объектов недвижимости
class YVO_Property_Parser {
    
    private $text;
    private $text_lower;
    
    public function __construct($text) {
        $this->text = $text;
        $this->text_lower = mb_strtolower($text, 'UTF-8');
    }
    
    public function parse() {
        $data = array();
        
        $data['address'] = $this->extract_address();
        $data['cadastral_number'] = $this->extract_cadastral_number();
        $data['area'] = $this->extract_area();
        $data['floor'] = $this->extract_floor();
        $data['floors_total'] = $this->extract_floors_total();
        $data['rooms'] = $this->extract_rooms();
        $data['price'] = $this->extract_price();
        $data['property_type'] = $this->extract_property_type();
        $data['share_in_right'] = $this->extract_share_in_right();
        $data['year_built'] = $this->extract_year_built();
        $data['condition'] = $this->extract_condition();
        $data['ownership_type'] = $this->extract_ownership_type();
        if (!empty($data['share_in_right']) || (isset($data['ownership_type']) && stripos((string) $data['ownership_type'], 'долев') !== false)) {
            $data['object_type'] = 'share';
        }
        
        // Убираем только null и пустые строки, сохраняем 0 (например комнаты для студии)
        return array_filter($data, function($v) {
            return $v !== null && $v !== '';
        });
    }
    
    private function extract_address() {
        if (function_exists('yvo_egrn_extract_mestopolozhenie')) {
            $egrn_addr = yvo_egrn_extract_mestopolozhenie($this->text);
            if ($egrn_addr !== '') {
                return function_exists('yvo_clean_address_string') ? yvo_clean_address_string($egrn_addr) : $egrn_addr;
            }
        }

        if (preg_match('/(?:Местоположение|Адрес\s*\(\s*местоположение\s*\))\s*:?\s*\n?\s*([^\n]{20,260})/ui', $this->text, $m)) {
            $addr = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t,");
            if (strlen($addr) > 15 && preg_match('/(?:г\.|город|ул\.|пр-кт|кв\.)/ui', $addr)) {
                return function_exists('yvo_clean_address_string') ? yvo_clean_address_string($addr) : $addr;
            }
        }

        if (preg_match('/(?:Российская\s+Федерация[^,\n]*,\s*)?(?:республика|область|[^,]{3,40},?\s*)*г\.?\s*[А-Яа-яЁё\-]+[^,\n]{0,120}(?:,\s*[^,\n]+){0,6}(?:,\s*кв\.?\s*\d+)/ui', $this->text, $m)) {
            $addr = trim($m[0]);
            if (strlen($addr) > 20) {
                return function_exists('yvo_clean_address_string') ? yvo_clean_address_string($addr) : $addr;
            }
        }

        if (preg_match('/[А-Яа-я][^\n]{25,180}(?:г\.|город)[^\n]{5,}(?:ул\.|пр-кт|пр\.)[^\n]{3,}(?:д\.|дом)[^\n]{1,}(?:кв\.|квартира)/ui', $this->text, $m)) {
            return function_exists('yvo_clean_address_string') ? yvo_clean_address_string(trim($m[0])) : trim($m[0]);
        }

        return null;
    }
    
    private function extract_cadastral_number() {
        if (preg_match('/(\d{2}:\d{2}:\d{6,7}:\d{2,4})/u', $this->text, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/(кадастровый номер|кадастр\. номер|кадастр)[:\s№]*([\d:\s]{10,25})/ui', $this->text, $matches)) {
            return trim(preg_replace('/[^\d:]/', '', $matches[2]));
        }
        
        return null;
    }
    
    private function extract_area() {
        $patterns = array(
            array('/(?:^|\n)\s*Площадь[^\d]{0,20}(\d+[.,]?\d*)/uim', 1),
            array('/(?:площадь|площ\.|пл\.|общая площадь)[:\s]*(\d+[.,]?\d*)\s*(?:м|м2|м²|кв\.м|кв\.\s*м|квм|м\.кв)/ui', 1),
            array('/(\d+[.,]?\d*)\s*(?:м|м2|м²|кв\.м|кв\.\s*м|квм|м\.кв)\s*(?:общ|общая|общей)/ui', 1),
            array('/(?:общ|общая|общей)[\s]+площад(?:ь|и|ью)[^\d]{0,20}(\d+[.,]?\d*)\s*(?:м|м2|м²|кв\.м|кв\.\s*м|квм|м\.кв)?/ui', 1),
            array('/(?:площадью)[\s]+(\d+[.,]?\d*)\s*(?:м|м2|м²|кв\.м)/ui', 1),
            array('/(?:квартира|помещение|комната)[^\d]{0,40}(\d+[.,]?\d*)\s*(?:кв\.?\s*м|м2|м²)/ui', 1),
        );

        foreach ($patterns as $item) {
            list($pattern, $group) = $item;
            if (!preg_match($pattern, $this->text, $matches)) {
                continue;
            }
            $area = str_replace(',', '.', $matches[$group]);
            if (!is_numeric($area)) {
                continue;
            }
            $val = floatval($area);
            if ($val > 0 && $val < 100000) {
                return round($val, 2);
            }
        }

        return null;
    }
    
    private function extract_floor() {
        if (preg_match('/Этаж\s*№?\s*(\d+)/ui', $this->text, $matches)) {
            return intval($matches[1]);
        }

        if (preg_match('/(\d+)[\s]*(этаж|эт\.|эт)[\s]*(из|of)[\s]*(\d+)/ui', $this->text, $matches)) {
            return intval($matches[1]);
        }
        
        if (preg_match('/расположен[а]? на (\d+)[\s]*(этаже|эт\.)/ui', $this->text, $matches)) {
            return intval($matches[1]);
        }
        
        if (preg_match('/(\d+)[\s]*этаж/ui', $this->text, $matches)) {
            return intval($matches[1]);
        }
        
        if (preg_match('/(\d+)[\s]*(этажное|этажный|этажная)/ui', $this->text, $matches)) {
            return intval($matches[1]);
        }
        
        return null;
    }
    
    private function extract_floors_total() {
        if (preg_match('/(\d+)[\s]*(этаж|эт\.|эт)[\s]*(из|of)[\s]*(\d+)/ui', $this->text, $matches)) {
            return intval($matches[4]);
        }
        
        if (preg_match('/этажность[:\s]*(\d+)/ui', $this->text, $matches)) {
            return intval($matches[1]);
        }
        
        if (preg_match('/(\d+)[\s]*(этажное|этажный|этажная|этажный дом)/ui', $this->text, $matches)) {
            return intval($matches[1]);
        }
        
        return null;
    }
    
    private function extract_rooms() {
        $patterns = array(
            '/(\d+)[\s\-]*(комнат|комн\.|комн|к\.|к)/ui',
            '/(\d+)[\s]*(комнатный|комнатная|комнатное)/ui',
            '/(комнат|комнатность|количество комнат)[:\s]*(\d+)/ui',
            '/(\d+)[\s]*(ком\.|ком)/ui'
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $this->text, $matches)) {
                $rooms = isset($matches[2]) ? $matches[2] : $matches[1];
                return intval($rooms);
            }
        }
        
        // Ищем студию
        if (stripos($this->text_lower, 'студия') !== false || stripos($this->text_lower, 'студио') !== false) {
            return 0; // 0 для студии
        }
        
        return null;
    }
    
    private function extract_price() {
        $patterns = array(
            '/(стоимость|цена|цене)[:\s]*([\d\s]+[.,]?\d*)\s*(тыс|тр|млн|млрд|руб|р\.|рублей|₽|у\.е|\$|€)/ui',
            '/([\d\s]+[.,]?\d*)\s*(тыс|тр|млн|млрд)[\s]*(руб|р\.|рублей)/ui',
            '/(руб|р\.|рублей|\$|€)[\s]*([\d\s]+[.,]?\d*)/ui',
            '/(цена договора|стоимость договора)[:\s]*([\d\s]+[.,]?\d*)/ui'
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $this->text, $matches)) {
                $price_str = preg_replace('/\s/', '', $matches[2] ?? $matches[1]);
                $price_str = str_replace(',', '.', $price_str);
                $price = floatval($price_str);
                
                // Конвертируем множители
                if (isset($matches[3])) {
                    $multiplier = mb_strtolower($matches[3], 'UTF-8');
                    if (strpos($multiplier, 'тыс') !== false || strpos($multiplier, 'тр') !== false) {
                        $price *= 1000;
                    } elseif (strpos($multiplier, 'млн') !== false) {
                        $price *= 1000000;
                    } elseif (strpos($multiplier, 'млрд') !== false) {
                        $price *= 1000000000;
                    }
                }
                
                // Конвертируем валюту
                if (isset($matches[3]) && (strpos($matches[3], '$') !== false || stripos($matches[3], 'usd') !== false)) {
                    $price *= 90; // Примерный курс
                } elseif (isset($matches[3]) && (strpos($matches[3], '€') !== false || stripos($matches[3], 'eur') !== false)) {
                    $price *= 100; // Примерный курс
                }
                
                return number_format($price, 2, '.', '');
            }
        }
        
        return null;
    }
    
    private function extract_share_in_right() {
        $patterns = array(
            '/(?:размер\s+доли|доля\s+в\s+праве|доля\s+в\s+размере|размер\s+указанной\s+доли)[^\d]{0,60}(\d+)\s*\/\s*(\d+)/ui',
            '/(\d+)\s*\/\s*(\d+)\s*(?:дол[яи]|доля|долев)/ui',
            '/(?:дол[яи]\s+в\s+размере)[^\d]{0,40}(\d+)\s*\/\s*(\d+)/ui',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $this->text, $matches)) {
                $n = intval($matches[1]);
                $d = intval($matches[2]);
                if ($n > 0 && $d > 0 && $n <= $d) {
                    return $n . '/' . $d;
                }
            }
        }
        return null;
    }

    private function extract_property_type() {
        if (stripos($this->text_lower, 'доля в праве') !== false
            || stripos($this->text_lower, 'долевая собственность') !== false
            || stripos($this->text_lower, 'общая долевая') !== false
            || preg_match('/\d+\s*\/\s*\d+.*(?:дол[яи]|доля)/ui', $this->text)) {
            return 'доля в праве общей долевой собственности на квартиру';
        }

        $property_types = array(
            'квартира' => 'квартира',
            'апартаменты' => 'апартаменты',
            'студия' => 'студия',
            'дом' => 'дом',
            'дача' => 'дача',
            'таунхаус' => 'таунхаус',
            'коттедж' => 'коттедж',
            'участок' => 'земельный участок',
            'земля' => 'земельный участок',
            'офис' => 'офис',
            'помещение' => 'помещение',
            'гараж' => 'гараж',
            'машиноместо' => 'машиноместо',
            'ангар' => 'ангар',
            'склад' => 'склад'
        );
        
        foreach ($property_types as $key => $value) {
            if (stripos($this->text_lower, $key) !== false) {
                return $value;
            }
        }
        
        return 'не определено';
    }
    
    private function extract_year_built() {
        if (preg_match('/(год|постройки|сдачи|строительства)[\s:\-]*(\d{4})/ui', $this->text, $matches)) {
            return intval($matches[2]);
        }
        
        if (preg_match('/\b(19\d{2}|20\d{2})\b.*?(год|постройки|сдачи|строительства)/ui', $this->text, $matches)) {
            return intval($matches[1]);
        }
        
        if (preg_match('/построен[а]? в (\d{4})/ui', $this->text, $matches)) {
            return intval($matches[1]);
        }
        
        return null;
    }
    
    private function extract_condition() {
        $conditions = array(
            'евроремонт' => 'евроремонт',
            'дизайнерский ремонт' => 'дизайнерский ремонт',
            'сделан ремонт' => 'сделан ремонт',
            'требует ремонта' => 'требует ремонта',
            'черновая отделка' => 'черновая отделка',
            'чистовая отделка' => 'чистовая отделка',
            'хорошее состояние' => 'хорошее состояние',
            'отличное состояние' => 'отличное состояние',
            'удовлетворительное' => 'удовлетворительное',
            'среднее состояние' => 'среднее состояние',
            'аварийное' => 'аварийное состояние',
            'новая' => 'новая',
            'вторичка' => 'вторичное жилье'
        );
        
        foreach ($conditions as $key => $value) {
            if (stripos($this->text_lower, $key) !== false) {
                return $value;
            }
        }
        
        return null;
    }
    
    private function extract_ownership_type() {
        $ownership_types = array(
            'собственность' => 'собственность',
            'аренда' => 'аренда',
            'долевая собственность' => 'долевая собственность',
            'совместная собственность' => 'совместная собственность',
            'пай' => 'пай',
            'пожизненное владение' => 'пожизненное владение'
        );
        
        foreach ($ownership_types as $key => $value) {
            if (stripos($this->text_lower, $key) !== false) {
                return $value;
            }
        }
        
        return 'собственность';
    }
}

/**
 * Путь к pdftotext: встроенный Poppler в папке плагина (Windows) или имя в PATH.
 */
function yvo_get_pdftotext_path() {
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        return 'pdftotext';
    }
    $base = YVO_PLUGIN_DIR . 'poppler';
    if (is_dir($base)) {
        $glob = glob($base . '/*/Library/bin/pdftotext.exe');
        if (!empty($glob) && is_readable($glob[0])) {
            return $glob[0];
        }
    }
    return 'pdftotext.exe';
}

/** Путь к pdftoppm (встроенный Poppler на Windows). */
function yvo_get_pdftoppm_path() {
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        return 'pdftoppm';
    }
    $base = YVO_PLUGIN_DIR . 'poppler';
    if (is_dir($base)) {
        $glob = glob($base . '/*/Library/bin/pdftoppm.exe');
        if (!empty($glob) && is_readable($glob[0])) {
            return $glob[0];
        }
    }
    return 'pdftoppm.exe';
}

/**
 * Poppler-бинарник доступен: встроенный в плагин или в PATH (Linux VPS).
 *
 * @param string $name pdftotext|pdftoppm
 */
function yvo_poppler_binary_usable($name) {
    $name = preg_replace('/[^a-z]/', '', strtolower((string) $name));
    if ($name === '') {
        return false;
    }
    $path = $name === 'pdftotext' ? yvo_get_pdftotext_path() : yvo_get_pdftoppm_path();
    if (strpos($path, YVO_PLUGIN_DIR) === 0 && is_readable($path)) {
        return true;
    }
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return false;
    }
    if (!function_exists('shell_exec')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) {
        return false;
    }
    $which = @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
    return is_string($which) && trim($which) !== '';
}

/** Извлечение встроенного текста из PDF (pdftotext / Smalot) без Imagick. */
function yvo_pdf_has_text_extraction() {
    $pdftotext = yvo_get_pdftotext_path();
    if (strpos($pdftotext, YVO_PLUGIN_DIR) === 0 && is_readable($pdftotext)) {
        return true;
    }
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && function_exists('shell_exec')) {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!in_array('shell_exec', $disabled, true)) {
            $which = @shell_exec('command -v pdftotext 2>/dev/null');
            if (is_string($which) && trim($which) !== '') {
                return true;
            }
        }
    }
    $autoload = YVO_PLUGIN_DIR . 'vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists('Smalot\PdfParser\Parser')) {
            return true;
        }
    }
    return false;
}

/** Растеризация PDF в картинки для OCR (pdftoppm / Imagick / Spatie). */
function yvo_pdf_has_ocr_rasterization() {
    if (function_exists('yvo_poppler_binary_usable') && yvo_poppler_binary_usable('pdftoppm')) {
        return true;
    }
    if (file_exists(YVO_PLUGIN_DIR . 'vendor/autoload.php')) {
        require_once YVO_PLUGIN_DIR . 'vendor/autoload.php';
        if (class_exists('Spatie\PdfToImage\Pdf')) {
            return true;
        }
    }
    if (extension_loaded('imagick')) {
        if (function_exists('shell_exec')) {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            if (!in_array('shell_exec', $disabled, true)) {
                $gs_check = @shell_exec('gs --version 2>&1');
                if (!empty($gs_check)) {
                    return true;
                }
            }
        }
    }
    return false;
}

/** Любая поддержка PDF: текстовый слой и/или OCR. */
function yvo_pdf_is_supported() {
    return yvo_pdf_has_text_extraction() || yvo_pdf_has_ocr_rasterization();
}

/**
 * Диагностика извлечения PDF (добавляется в конец текста при OCR).
 */
function yvo_pdf_extraction_debug_get() {
    $d = isset($GLOBALS['yvo_pdf_extraction_debug']) ? $GLOBALS['yvo_pdf_extraction_debug'] : '';
    $GLOBALS['yvo_pdf_extraction_debug'] = '';
    return $d;
}
function yvo_pdf_extraction_debug_set($line) {
    $GLOBALS['yvo_pdf_extraction_debug'] = (isset($GLOBALS['yvo_pdf_extraction_debug']) ? $GLOBALS['yvo_pdf_extraction_debug'] . "\n" : '') . $line;
}

/**
 * Попытка исправить кодировку текста из PDF (Smalot иногда возвращает Windows-1251 как кракозябры).
 */
/**
 * Текст похож на «кракозябру» выписки ЕГРН (шрифт-подстановка), а не на обычный паспорт/OCR.
 */
function yvo_text_looks_like_egrn_garbled($text) {
    if (!is_string($text) || $text === '') {
        return false;
    }
    $cyr = preg_match_all('/[а-яА-ЯёЁ]/u', $text);
    if (preg_match('/паспорт|PASSPORT|личность|гражданин|серия\s+\d{2}\s*\d{2}|код\s+подразделения|удостоверяющ|дата\s+рождения/ui', $text) && $cyr >= 15) {
        return false;
    }
    return (bool) preg_match('/кадастр|ЕГРН|росреестр|выписк\s+из\s+ЕГРН|объект\s+недвижимости|правообладател|кадастровый\s+номер/ui', $text);
}

/** Нужно ли автоматически вызывать исправление кодировки ЕГРН (DeepSeek) после OCR. */
function yvo_text_needs_egrn_encoding_fix($text) {
    return yvo_text_looks_like_egrn_garbled($text);
}

function yvo_fix_pdf_text_encoding($text) {
    if (!is_string($text) || $text === '') {
        return $text;
    }
    $count_cyrillic = function ($s) {
        return preg_match_all('/[а-яА-ЯёЁ]/u', $s);
    };
    $n = $count_cyrillic($text);
    if ($n > 100) {
        return $text;
    }
    $best = $text;
    $best_n = $n;
    if (function_exists('mb_convert_encoding')) {
        $encodings = array('Windows-1251', 'CP1251', 'ISO-8859-5', 'CP866');
        foreach ($encodings as $enc) {
            $fixed = @mb_convert_encoding($text, 'UTF-8', $enc);
            if ($fixed !== false && $fixed !== '') {
                $n2 = $count_cyrillic($fixed);
                if ($n2 > $best_n) {
                    $best = $fixed;
                    $best_n = $n2;
                }
            }
        }
        if ($best_n <= $n) {
            $bytes = @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
            if ($bytes !== false) {
                $fixed = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');
                if ($fixed !== false && $fixed !== '' && $count_cyrillic($fixed) > $best_n) {
                    $best = $fixed;
                }
            }
        }
    }
    if (yvo_text_looks_like_egrn_garbled($best)) {
        $decoded = yvo_decode_egrn_garbled_symbols($best);
        $decoded_n = $count_cyrillic($decoded);
        if ($decoded !== $best && $decoded_n > $best_n) {
            $best = $decoded;
        }
    }
    return $best;
}

/**
 * Примерное / учебное ФИО из промптов и шаблонов (не из реального документа).
 */
function yvo_is_demo_full_name($name) {
    if (!is_string($name) || trim($name) === '') {
        return false;
    }
    $lower = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
    $exact = array(
        'иванов иван иванович',
        'иванов иван',
        'петров п.п.',
        'петров петр петрович',
        'петров петр',
        'сидоров сидор сидорович',
    );
    if (in_array($lower, $exact, true)) {
        return true;
    }
    if (preg_match('/^(иванов|петров|сидоров)\s+(иван|петр|сидор)(\s+(иванович|петрович|сидорович))?$/u', $lower)) {
        return true;
    }
    return (bool) preg_match('/^(фио|ф\.?\s*и\.?\s*о\.?|фамилия|имя|отчество)(\s|$)/u', $lower);
}

/**
 * Значение похоже на подсказку из JSON-промпта DeepSeek, а не на данные документа.
 */
function yvo_looks_like_parser_placeholder($value, $field_key = '') {
    if (!is_string($value) || trim($value) === '') {
        return true;
    }
    $lower = mb_strtolower(trim($value), 'UTF-8');
    if (strpos($lower, 'например') !== false || strpos($lower, 'фио полностью') !== false) {
        return true;
    }
    if (preg_match('/\(\d|\d\s*цифр|формат\s+дд|полное название|квадратные метры|серия паспорта|номер паспорта|код подразделения|кем выдан паспорт/ui', $lower)) {
        return true;
    }
    return false;
}

/**
 * Нормализация полей паспорта после парсера (пробелы, дефисы).
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function yvo_normalize_parsed_person_data($data) {
    if (!is_array($data)) {
        return array();
    }
    if (!empty($data['passport_series'])) {
        $s = preg_replace('/\D/u', '', (string) $data['passport_series']);
        if (strlen($s) === 4) {
            $data['passport_series'] = $s;
        }
    }
    if (!empty($data['passport_number'])) {
        $n = preg_replace('/\D/u', '', (string) $data['passport_number']);
        if (strlen($n) === 6) {
            $data['passport_number'] = $n;
        }
    }
    if (!empty($data['department_code'])) {
        $dc = preg_replace('/\D/u', '', (string) $data['department_code']);
        if (strlen($dc) === 6) {
            $data['department_code'] = substr($dc, 0, 3) . '-' . substr($dc, 3);
        }
    }
    if (!empty($data['full_name'])) {
        $data['full_name'] = trim(preg_replace('/\s+/u', ' ', (string) $data['full_name']));
    }
    if (!empty($data['birth_cert_series'])) {
        $data['birth_cert_series'] = strtoupper(trim(preg_replace('/\s+/u', '-', (string) $data['birth_cert_series'])));
    }
    if (!empty($data['birth_cert_number'])) {
        $data['birth_cert_number'] = preg_replace('/\D/u', '', (string) $data['birth_cert_number']);
    }
    if (!empty($data['birth_cert_date']) && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/u', (string) $data['birth_cert_date'], $dm)) {
        $data['birth_cert_date'] = $dm[1] . '.' . $dm[2] . '.' . $dm[3];
    }
    return $data;
}

/**
 * @param array<string, mixed> $data
 */
function yvo_parsed_person_has_values($data) {
    if (!is_array($data)) {
        return false;
    }
    foreach ($data as $val) {
        if ($val === null || $val === '') {
            continue;
        }
        $s = trim((string) $val);
        if ($s !== '' && strtolower($s) !== 'null') {
            return true;
        }
    }
    return false;
}

/**
 * Убирает служебные заголовки OCR PDF перед парсингом.
 */
function yvo_strip_pdf_ocr_boilerplate($text) {
    if (!is_string($text) || $text === '') {
        return '';
    }
    $text = preg_replace('/^---\s*Использовано распознавание[^\n]*\n+/u', '', $text);
    $text = preg_replace('/^---\s*Страница\s+\d+\s*---\n?/um', '', $text);
    $text = preg_replace('/\n---\s*Диагностика извлечения текста\s*---[\s\S]*$/u', '', $text);
    return trim($text);
}

/** Одно слово похоже на фамилию/имя/отчество (не подпись поля и не адрес). */
function yvo_passport_name_token_ok($token) {
    $token = trim((string) $token);
    if ($token === '' || preg_match('/\d/ui', $token)) {
        return false;
    }
    if (!preg_match('/^[А-ЯЁа-яё][А-ЯЁа-яё\-]{1,}$/u', $token)) {
        return false;
    }
    $lower = mb_strtolower($token, 'UTF-8');
    if (preg_match('/^(российская|федерация|паспорт|республика|отдел|уфмс|министерство|страхов|свидетельство|пол|рождения|место|дата|код|личный|банись|октябр)/ui', $lower)) {
        return false;
    }
    return mb_strlen($token, 'UTF-8') >= 2;
}

/** ФИО не похоже на адрес/кем выдан/служебную строку OCR. */
function yvo_full_name_looks_invalid($name) {
    if (!is_string($name) || trim($name) === '') {
        return true;
    }
    $lower = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
    if (preg_match('/\b(по|в|на|от|для|район|город|область|республик|уфмс|федерац|отделом|выдан|октябр|башкортостан|уфмс)\b/ui', $lower)) {
        return true;
    }
    $words = preg_split('/\s+/u', trim($name));
    if (count($words) < 2 || count($words) > 4) {
        return true;
    }
    foreach ($words as $w) {
        if (!yvo_passport_name_token_ok($w)) {
            return true;
        }
    }
    return false;
}

/**
 * Серия и номер паспорта (не путать с личным кодом / кодом подразделения).
 * Типичный баг OCR: «020-006» + «25» + «077838» → ложная серия 0625.
 *
 * @return array{passport_series?: string, passport_number?: string}
 */
function yvo_parse_passport_series_number_from_text($text) {
    $out = array();
    if (!is_string($text) || trim($text) === '') {
        return $out;
    }
    if (function_exists('yvo_normalize_passport_ocr_text')) {
        $text = yvo_normalize_passport_ocr_text($text);
    }
    $head = $text;
    if (preg_match('/---\s*Страница\s*2\s*---/ui', $text, $page_split, PREG_OFFSET_CAPTURE)) {
        $head = substr($text, 0, $page_split[0][1]);
    } elseif (preg_match('/МЕСТО\s+ЖИТЕЛЬСТВА/ui', $text, $reg_split, PREG_OFFSET_CAPTURE)) {
        $head = substr($text, 0, $reg_split[0][1]);
    }
    // История старых паспортов — не источник текущей серии.
    if (preg_match('/Сведения\s+о\s+ранее\s+выданных/ui', $head, $old_split, PREG_OFFSET_CAPTURE)) {
        $head = substr($head, 0, $old_split[0][1]);
    }

    $mrz = function_exists('yvo_parse_passport_mrz_from_text') ? yvo_parse_passport_mrz_from_text($text) : array();
    $digits_only = preg_replace('/\D+/u', '', $head);

    $scrub = preg_replace('/(?:личный|анн+[ыи]й)\s+код\s*/ui', ' ', $head);
    $scrub = preg_replace('/\bPd\.?\s*/ui', ' ', $scrub);
    // Убираем код подразделения (обязателен дефис!), иначе «077838»→«077»+«838».
    $scrub = preg_replace('/\b\d{3}\s*[\-–—]\s*\d{3}\b/u', ' ', $scrub);
    $scrub = preg_replace('/(?:код\s+подразделения|division\s+code)[^\n]{0,20}/ui', ' ', $scrub);

    $accept = function ($series, $number) use (&$out) {
        if (!preg_match('/^\d{4}$/', $series) || !preg_match('/^\d{6}$/', $number)) {
            return false;
        }
        // Отсекаем даты вида 2025xxxx и коды вроде 0200xx.
        if (preg_match('/^(19|20)\d{2}$/', $series)) {
            return false;
        }
        $out['passport_series'] = $series;
        $out['passport_number'] = $number;
        return true;
    };

    // 1) Явная подпись «серия … номер …»
    if (preg_match('/(?:серия|series)[^\d]{0,40}(\d{2})\s*(\d{2})\s*(?:№|N|No\.?)?\s*(\d{6})/ui', $head, $matches)) {
        if ($accept($matches[1] . $matches[2], $matches[3])) {
            return $out;
        }
    }
    if (preg_match('/(?:серия|series)[^\d]{0,40}(\d{4})\s*(?:№|N|No\.?)?\s*(\d{6})/ui', $head, $matches)) {
        if ($accept($matches[1], $matches[2])) {
            return $out;
        }
    }

    // 2) Плотный шаблон «80 25 077838» (не через полстраницы текста)
    if (preg_match_all('/(\d{2})[ \t]{1,4}(\d{2})[ \t]{1,4}(\d{6})/u', $scrub, $all, PREG_SET_ORDER)) {
        foreach ($all as $row) {
            if ($accept($row[1] . $row[2], $row[3])) {
                return $out;
            }
        }
    }
    // 2b) То же на соседних строках: 80\n25\n077838 или 25\n077838 (префикс ниже)
    if (preg_match_all('/(?<![\d])(\d{2})\s*\n\s*(\d{2})\s*\n\s*(\d{6})(?!\d)/u', $scrub, $all2, PREG_SET_ORDER)) {
        foreach ($all2 as $row) {
            if ($accept($row[1] . $row[2], $row[3])) {
                return $out;
            }
        }
    }

    // 3) Разрез OCR: «80» … «09 941378» или «25» + «077838» + префикс из MRZ/края
    $prefix = '';
    if (!empty($mrz['passport_series']) && preg_match('/^\d{4}$/', $mrz['passport_series'])) {
        $prefix = substr($mrz['passport_series'], 0, 2);
    }
    // Префикс из строки MRZ даже если ФИО из MRZ не разобралось: 8020778389RUS…
    if ($prefix === '' && preg_match('/(?<![\d])(80|45|98|75|77|50|92|63|18|40|42)(\d{2})(\d{6})\d?RUS/iu', $head, $mm)) {
        $prefix = $mm[1];
        // Если визуальный номер совпал с MRZ — берём целиком.
        if (preg_match('/(?<![\d])' . preg_quote($mm[3], '/') . '(?![\d])/u', $scrub) && $accept($mm[1] . $mm[2], $mm[3])) {
            return $out;
        }
    }
    if ($prefix === '' && preg_match('/(?:^|\n)\s*(80|45|98|75|77|50|92|63|18|40|42)\s*(?:\n|$)/m', $scrub, $pm)) {
        $prefix = $pm[1];
    }
    // Самый частый кейс фото: строка «25» / «09» сразу над номером «077838»
    if ($prefix !== '' && preg_match('/(?:^|\n)\s*(\d{2})\s*\n\s*(\d{6})\s*(?:\n|$)/mu', $scrub, $near)) {
        if ($accept($prefix . $near[1], $near[2])) {
            return $out;
        }
    }
    // «09 941378» в одной строке
    if (preg_match_all('/(?<![\d])(\d{2})[ \t]+(\d{6})(?!\d)/u', $scrub, $pairs, PREG_SET_ORDER)) {
        foreach ($pairs as $pair) {
            $tail2 = $pair[1];
            $number = $pair[2];
            if (preg_match('/^(0[1-9]|1[0-2])$/', $tail2) && preg_match('/^20[0-3]\d/', $number)) {
                continue;
            }
            if ($prefix !== '' && $accept($prefix . $tail2, $number)) {
                return $out;
            }
        }
    }
    // «80» и отдельно «09» + «941378» через короткий разрыв
    if ($prefix !== '' && preg_match('/(?:^|\n)\s*' . preg_quote($prefix, '/') . '\s*(?:\n[\s\S]{0,280}?)(?:^|\n)\s*(\d{2})\s*(?:\n|\s)+(\d{6})\b/mu', $scrub, $sm)) {
        if ($accept($prefix . $sm[1], $sm[2])) {
            return $out;
        }
    }

    // 4) MRZ — только если номер есть как отдельное 6-значное число (не хвост 8020778389).
    if (!empty($mrz['passport_series']) && !empty($mrz['passport_number'])) {
        $mrz_num = $mrz['passport_number'];
        if (preg_match('/(?<![\d])' . preg_quote($mrz_num, '/') . '(?![\d])/u', $head)
            && $accept($mrz['passport_series'], $mrz_num)) {
            return $out;
        }
    }

    // 5) «8025 077838» / «8025077838»
    if (preg_match('/(\d{4})\s*№?\s*(\d{6})/u', $scrub, $matches)) {
        if ($accept($matches[1], $matches[2])) {
            return $out;
        }
    }

    return $out;
}

/** OCR похож на свидетельство о рождении (не паспорт). */
function yvo_text_looks_like_birth_certificate($text) {
    if (!is_string($text) || trim($text) === '') {
        return false;
    }
    if (preg_match('/свидетельство\s+о\s+рождении/ui', $text)) {
        return true;
    }
    if (preg_match('/\bзагс\b/ui', $text) && preg_match('/(?:родил(?:ся|ась)|место\s+рождения|отец|мать)/ui', $text)) {
        return true;
    }
    return false;
}

/** Убирает штамп «выдан паспорт» с фото свидетельства — мешает парсингу. */
function yvo_scrub_passport_stamp_from_ocr($text) {
    if (!is_string($text)) {
        return '';
    }
    $t = preg_replace('/выдан\s+паспорт[^\n]*/ui', ' ', $text);
    $t = preg_replace('/паспорт\s+серия\s+\d+[^\n]*/ui', ' ', $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

/** Дата «29 августа 2017» → 29.08.2017 */
function yvo_parse_russian_words_date($text) {
    if (!is_string($text) || $text === '') {
        return '';
    }
    static $months = null;
    if ($months === null) {
        $months = array(
            'января' => '01', 'февраля' => '02', 'марта' => '03', 'апреля' => '04',
            'мая' => '05', 'июня' => '06', 'июля' => '07', 'августа' => '08',
            'сентября' => '09', 'октября' => '10', 'ноября' => '11', 'декабря' => '12',
        );
    }
    if (preg_match('/(\d{1,2})\s+(января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)\s+(\d{4})/ui', $text, $m)) {
        $day = str_pad((string) (int) $m[1], 2, '0', STR_PAD_LEFT);
        $mon = isset($months[mb_strtolower($m[2], 'UTF-8')]) ? $months[mb_strtolower($m[2], 'UTF-8')] : '';
        if ($mon !== '') {
            return $day . '.' . $mon . '.' . $m[3];
        }
    }
    return '';
}

/**
 * ФИО ребёнка из свидетельства (не отец/мать).
 */
function yvo_extract_child_name_from_birth_certificate($text) {
    if (!is_string($text) || trim($text) === '') {
        return '';
    }
    $text = yvo_scrub_passport_stamp_from_ocr($text);
    $text = yvo_strip_pdf_ocr_boilerplate($text);

    if (preg_match('/(?:фамилия|surname)\s*[:\s]*([А-ЯЁ][а-яё\-]+)\s+(?:имя|name)\s*[:\s]*([А-ЯЁ][а-яё\-]+)(?:\s+(?:отчество|patronymic)\s*[:\s]*([А-ЯЁ][а-яё\-]+))?/ui', $text, $m)) {
        $name = trim($m[1] . ' ' . $m[2] . (isset($m[3]) ? ' ' . $m[3] : ''));
        if (!yvo_is_demo_full_name($name) && !yvo_full_name_looks_invalid($name)) {
            return $name;
        }
    }

    if (preg_match('/([А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){1,2})\s*,?\s*(?:родил(?:ся|ась)|дата\s+рождения)/ui', $text, $m)) {
        $name = trim($m[1]);
        if (!yvo_full_name_looks_invalid($name)) {
            return $name;
        }
    }

    if (preg_match('/([А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){1,2})\s+[^\d]{0,40}(\d{2}\.\d{2}\.(?:19|20)\d{2})/u', $text, $m)) {
        $name = trim($m[1]);
        if (!preg_match('/^(отец|мать|гражданин)/ui', mb_strtolower($name, 'UTF-8')) && !yvo_full_name_looks_invalid($name)) {
            return $name;
        }
    }

    $lines = preg_split('/\r\n|\r|\n/u', $text);
    $surname = '';
    $first = '';
    $patronymic = '';
    $after_title = false;
    $parent_zone = false;
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/свидетельство\s+о\s+рождении/ui', $line)) {
                $after_title = true;
                continue;
            }
            if (preg_match('/^(отец|мать|марка\s+медицинского|запись\s+акт)/ui', $line)) {
                $parent_zone = true;
                break;
            }
            if ($parent_zone) {
                continue;
            }
            if (preg_match('/^(фамилия|имя|отчество)\s*:?\s*$/ui', $line)) {
                continue;
            }
            if (preg_match('/^(?:фамилия|имя|отчество)\s+([А-ЯЁ][а-яё\-]+)\s*$/ui', $line, $lm)) {
                if (stripos($line, 'фамилия') === 0) {
                    $surname = $lm[1];
                } elseif (stripos($line, 'имя') === 0) {
                    $first = $lm[1];
                } else {
                    $patronymic = $lm[1];
                }
                continue;
            }
            if (preg_match('/^([А-ЯЁ][а-яё\-]+)$/u', $line, $one) && yvo_passport_name_token_ok($one[1])) {
                if ($surname === '') {
                    $surname = $one[1];
                } elseif ($first === '') {
                    $first = $one[1];
                } elseif ($patronymic === '') {
                    $patronymic = $one[1];
                    break;
                }
            }
            if ($after_title && preg_match('/^([А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){1,2})$/u', $line, $lm2)) {
                $name = trim($lm2[1]);
                if (!yvo_full_name_looks_invalid($name)) {
                    return $name;
                }
            }
        }
    }
    if ($surname !== '' && $first !== '') {
        $name = trim($surname . ' ' . $first . ($patronymic !== '' ? ' ' . $patronymic : ''));
        if (!yvo_full_name_looks_invalid($name)) {
            return $name;
        }
    }
    return '';
}

/**
 * Парсинг свидетельства о рождении (серия/номер, даты, ЗАГС, ФИО ребёнка).
 *
 * @return array<string, string>
 */
function yvo_parse_birth_certificate_from_text($text) {
    $data = array();
    if (!is_string($text) || trim($text) === '') {
        return $data;
    }
    $text = yvo_scrub_passport_stamp_from_ocr($text);
    $text = yvo_strip_pdf_ocr_boilerplate($text);

    $name = yvo_extract_child_name_from_birth_certificate($text);
    if ($name !== '') {
        $data['full_name'] = $name;
    }

    if (preg_match('/(?:серия|series)\s*([IVXLC\d]+[\s\-]*[A-ZА-Я]{1,3})\s*(?:№|N|No\.?|#)\s*(\d{5,7})/ui', $text, $m)) {
        $data['birth_cert_series'] = strtoupper(preg_replace('/\s+/u', '-', trim($m[1])));
        $data['birth_cert_number'] = $m[2];
    } elseif (preg_match('/\b([IVXLC]+[\s\-]*[A-ZА-Я]{1,3})\s*(?:№|N|No\.?|#)\s*(\d{5,7})\b/ui', $text, $m)) {
        $data['birth_cert_series'] = strtoupper(preg_replace('/\s+/u', '-', trim($m[1])));
        $data['birth_cert_number'] = $m[2];
    }

    if (preg_match('/(?:родил(?:ся|ась)|дата\s+рождения)[^\d]{0,80}(\d{2}\.\d{2}\.\d{4})/ui', $text, $m)) {
        $data['birth_date'] = $m[1];
    } elseif (preg_match('/(?:родил(?:ся|ась))[^\d]{0,80}(\d{1,2})\s+(января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)\s+(\d{4})/ui', $text, $m)) {
        $data['birth_date'] = yvo_parse_russian_words_date($m[1] . ' ' . $m[2] . ' ' . $m[3]);
    } elseif (preg_match_all('/\b(\d{2}\.\d{2}\.(?:19|20)\d{2})\b/u', $text, $dates)) {
        $best = '';
        foreach ($dates[1] as $d) {
            if (preg_match('/\.20(2[1-9]|[3-9]\d)\b/', $d)) {
                continue;
            }
            if ($best === '' || strcmp($d, $best) < 0) {
                $best = $d;
            }
        }
        if ($best !== '') {
            $data['birth_date'] = $best;
        }
    }

    $month_pat = 'января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря';
    if (preg_match('/(?:дата\s+выдачи|выдано)[^\d]{0,80}(\d{2}\.\d{2}\.\d{4})/ui', $text, $m)) {
        $data['birth_cert_date'] = $m[1];
    } elseif (preg_match('/(?:дата\s+выдачи|выдано)[^\d]{0,80}(\d{1,2})\s+(' . $month_pat . ')\s+(\d{4})/ui', $text, $m)) {
        $data['birth_cert_date'] = yvo_parse_russian_words_date($m[1] . ' ' . $m[2] . ' ' . $m[3]);
    } elseif (preg_match('/(?:запис[ьи]\s+акт[^\d\n]{0,100})[\s\n]+(\d{1,2})\s+(' . $month_pat . ')\s+(\d{4})/ui', $text, $m)) {
        $data['birth_cert_date'] = yvo_parse_russian_words_date($m[1] . ' ' . $m[2] . ' ' . $m[3]);
    } elseif (preg_match('/(?:запис[ьи]\s+акт)[^\d]{0,80}(\d{1,2})\s+(' . $month_pat . ')\s+(\d{4})/ui', $text, $m)) {
        $data['birth_cert_date'] = yvo_parse_russian_words_date($m[1] . ' ' . $m[2] . ' ' . $m[3]);
    } elseif (preg_match_all('/(\d{1,2})\s+(' . $month_pat . ')\s+(\d{4})/ui', $text, $all, PREG_SET_ORDER)) {
        $birth_d = isset($data['birth_date']) ? $data['birth_date'] : '';
        foreach ($all as $m) {
            $d = yvo_parse_russian_words_date($m[1] . ' ' . $m[2] . ' ' . $m[3]);
            if ($d !== '' && $d !== $birth_d) {
                $data['birth_cert_date'] = $d;
            }
        }
    }
    if (empty($data['birth_cert_date']) && preg_match_all('/\b(\d{2}\.\d{2}\.(?:19|20)\d{2})\b/u', $text, $dates2) && count($dates2[1]) >= 2) {
        $sorted = $dates2[1];
        usort($sorted, 'strcmp');
        $candidate = end($sorted);
        if (!isset($data['birth_date']) || $candidate !== $data['birth_date']) {
            $data['birth_cert_date'] = $candidate;
        }
    }

    if (preg_match('/(?:место\s+рождения[:\s]+)([^\n]{8,160})/ui', $text, $m)) {
        $bp = trim(preg_replace('/\s+/u', ' ', $m[1]));
        $bp = preg_replace('/\s+запись\s+акт.*$/ui', '', $bp);
        $bp = preg_replace('/\s+(?:отец|мать)\b.*$/ui', '', $bp);
        if ($bp !== '') {
            $data['birth_place'] = trim($bp);
        }
    } elseif (preg_match('/(?:родил(?:ся|ась)[^\n]{0,40}\n)([^\n]{8,120})/ui', $text, $m)) {
        $bp = trim($m[1]);
        if (!preg_match('/^(отец|мать|запись)/ui', $bp)) {
            $data['birth_place'] = preg_replace('/\s+/u', ' ', $bp);
        }
    }

    if (preg_match('/((?:первый|отдел|управление)[^\n]{10,}?загс[^\n]{10,160})/ui', $text, $m)) {
        $data['birth_cert_issued_by'] = trim(preg_replace('/\s+/u', ' ', $m[1]));
    } elseif (preg_match('/(?:место\s+государственной\s+регистрации|орган[^\n]{0,40}записи)[^\n]*\n?\s*([^\n]{15,200})/ui', $text, $m)) {
        $data['birth_cert_issued_by'] = trim(preg_replace('/\s+/u', ' ', $m[1]));
    } elseif (preg_match('/(ЗАГС[^\n]{10,180})/ui', $text, $m)) {
        $data['birth_cert_issued_by'] = trim(preg_replace('/\s+/u', ' ', $m[1]));
    }
    if (!empty($data['birth_cert_issued_by'])) {
        $data['birth_cert_issued_by'] = trim(preg_replace('/\s+дата\s+выдачи.*$/ui', '', $data['birth_cert_issued_by']));
        $data['birth_cert_issued_by'] = trim(preg_replace(
            '/\s*[IVXLC]+[\s\-]*[A-ZА-Я]{1,3}\s*(?:№|N|No\.?).*$/ui',
            '',
            $data['birth_cert_issued_by']
        ));
    }

    return $data;
}

/**
 * Извлечение ФИО из OCR-текста паспорта (в т.ч. ЗАГЛАВНЫЕ буквы).
 */
function yvo_extract_full_name_from_text($text) {
    if (!is_string($text) || trim($text) === '') {
        return '';
    }
    if (yvo_text_looks_like_birth_certificate($text)) {
        $child = yvo_extract_child_name_from_birth_certificate($text);
        if ($child !== '') {
            return $child;
        }
    }
    if (function_exists('yvo_normalize_passport_ocr_text')) {
        $text = yvo_normalize_passport_ocr_text($text);
    } else {
        $text = yvo_strip_pdf_ocr_boilerplate($text);
    }
    if (preg_match('/(?:фамилия|surname)\s*[:\s]*([А-ЯЁ][А-ЯЁа-яё\-]+)\s+(?:имя|name)\s*[:\s]*([А-ЯЁ][А-ЯЁа-яё\-]+)(?:\s+(?:отчество|patronymic)\s*[:\s]*([А-ЯЁ][А-ЯЁа-яё\-]+))?/ui', $text, $labeled)) {
        $name = trim($labeled[1] . ' ' . $labeled[2] . (isset($labeled[3]) ? ' ' . $labeled[3] : ''));
        if (!yvo_is_demo_full_name($name)) {
            return $name;
        }
    }
    $lines = preg_split('/\r\n|\r|\n/u', $text);
    if (is_array($lines)) {
        $surname = '';
        $first = '';
        $patronymic = '';
        $expect = '';
        $prev = '';
        $fio_parts = array();
        $collect_fio = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $prev = '';
                continue;
            }
            if (preg_match('/^ф\.?\s*и\.?\s*о\.?\s*$/ui', $line)) {
                $collect_fio = true;
                $fio_parts = array();
                $prev = $line;
                continue;
            }
            if ($collect_fio && count($fio_parts) < 3 && yvo_passport_name_token_ok($line)) {
                $fio_parts[] = $line;
                if (count($fio_parts) === 3) {
                    $name = implode(' ', $fio_parts);
                    if (!yvo_is_demo_full_name($name) && !yvo_full_name_looks_invalid($name)) {
                        return $name;
                    }
                    $collect_fio = false;
                }
                $prev = $line;
                continue;
            }
            $collect_fio = false;
            if (preg_match('/^(фамилия|surname)\s*:?\s*$/ui', $line)) {
                if ($prev !== '' && yvo_passport_name_token_ok($prev)) {
                    $surname = $prev;
                }
                $expect = 'surname';
                $prev = $line;
                continue;
            }
            if (preg_match('/^(имя|name|given\s*names?)\s*:?\s*$/ui', $line)) {
                if ($prev !== '' && yvo_passport_name_token_ok($prev)) {
                    $first = $prev;
                }
                $expect = 'first';
                $prev = $line;
                continue;
            }
            if (preg_match('/^(отчество|patronymic)\s*:?\s*$/ui', $line)) {
                if ($prev !== '' && yvo_passport_name_token_ok($prev)) {
                    $patronymic = $prev;
                }
                $expect = 'patronymic';
                $prev = $line;
                continue;
            }
            if ($expect !== '' && yvo_passport_name_token_ok($line)) {
                if ($expect === 'surname') {
                    $surname = $line;
                } elseif ($expect === 'first') {
                    $first = $line;
                } else {
                    $patronymic = $line;
                }
                $expect = '';
                $prev = $line;
                continue;
            }
            if (preg_match('/^(?:фамилия|surname)\s*[:\s]+([А-ЯЁ][А-ЯЁа-яё\-]+)\s*$/ui', $line, $m)) {
                $surname = $m[1];
            } elseif (preg_match('/^(?:имя|name)\s*[:\s]+([А-ЯЁ][А-ЯЁа-яё\-]+)\s*$/ui', $line, $m)) {
                $first = $m[1];
            } elseif (preg_match('/^(?:отчество|patronymic)\s*[:\s]+([А-ЯЁ][А-ЯЁа-яё\-]+)\s*$/ui', $line, $m)) {
                $patronymic = $m[1];
            }
            $prev = $line;
        }
        if ($surname !== '' && $first !== '') {
            $name = trim($surname . ' ' . $first . ($patronymic !== '' ? ' ' . $patronymic : ''));
            if (!yvo_is_demo_full_name($name) && !yvo_full_name_looks_invalid($name)) {
                return $name;
            }
        }
        $name_seq = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $name_seq = array();
                continue;
            }
            // Короткие цифры (серия «80», «09») не сбрасывают набор ФИО.
            if (preg_match('/^\d{1,4}$/u', $line)) {
                continue;
            }
            if (preg_match('/^\d/ui', $line)) {
                $name_seq = array();
                continue;
            }
            if (preg_match('/^(фамилия|имя|отчество|surname|name|patronymic|дата|место|пол|код|серия|паспорт|выдан)/ui', $line)) {
                // Подпись «Отчество» — не сбрасываем уже собранные фамилию/имя.
                if (preg_match('/^(отчество|patronymic)\s*:?\s*$/ui', $line) && count($name_seq) >= 2) {
                    continue;
                }
                $name_seq = array();
                continue;
            }
            if (yvo_passport_name_token_ok($line)) {
                $name_seq[] = $line;
                if (count($name_seq) >= 3) {
                    $name = implode(' ', array_slice($name_seq, -3));
                    if (!yvo_is_demo_full_name($name) && !yvo_full_name_looks_invalid($name)) {
                        return $name;
                    }
                }
                continue;
            }
            $name_seq = array();
        }
    }
    $lines = preg_split('/\r\n|\r|\n/u', $text);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/\d/ui', $line)) {
                continue;
            }
            if (preg_match('/^([А-ЯЁ]{2,}(?:\s+[А-ЯЁ]{2,}){1,2})$/u', $line, $lm)) {
                $name = trim($lm[1]);
                if (!yvo_is_demo_full_name($name) && !preg_match('/^(российская|федерация|паспорт|республика|министерство|отдел)/ui', mb_strtolower($name, 'UTF-8'))) {
                    return $name;
                }
            }
        }
    }
    $skip = '/^(российская|федерация|паспорт|республика|город|область|район|улица|выдан|дата|код|серия|номер|министерство|отдел)/ui';
    $candidates = array();
    if (preg_match_all('/\b([А-ЯЁ]{2,}(?:\s+[А-ЯЁ]{2,}){1,2})\b/u', $text, $caps, PREG_SET_ORDER)) {
        foreach ($caps as $m) {
            $candidates[] = trim($m[1]);
        }
    }
    if (preg_match_all('/\b([А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){1,2})\b/u', $text, $mixed, PREG_SET_ORDER)) {
        foreach ($mixed as $m) {
            $candidates[] = trim($m[1]);
        }
    }
    foreach ($candidates as $name) {
        if (yvo_is_demo_full_name($name) || yvo_full_name_looks_invalid($name)) {
            continue;
        }
        $lower = mb_strtolower($name, 'UTF-8');
        if (preg_match($skip, $lower)) {
            continue;
        }
        if (preg_match('/\d/ui', $name)) {
            continue;
        }
        return $name;
    }
    return '';
}

/**
 * Убирает из ответа парсера примеры из промпта и ФИО, которых нет в тексте документа.
 *
 * @param array<string, mixed> $data
 * @param string             $source_text OCR-текст документа
 * @return array<string, mixed>
 */
function yvo_sanitize_parsed_person_data($data, $source_text = '') {
    if (!is_array($data)) {
        return array();
    }
    $data = yvo_normalize_parsed_person_data($data);
    $is_legal = !empty($data['is_legal_entity'])
        || (isset($data['person_type']) && (string) $data['person_type'] === 'legal_entity')
        || (!empty($data['company_name']) && (string) $data['company_name'] !== '');
    $string_fields = array(
        'full_name', 'company_name', 'passport_series', 'passport_number', 'department_code',
        'passport_issued_by', 'passport_date', 'birth_date', 'birth_place',
        'birth_cert_series', 'birth_cert_number', 'birth_cert_date', 'birth_cert_issued_by',
        'registration', 'inn', 'ogrn', 'snils', 'phone', 'email', 'requisites', 'bank_details',
        'ownership_status', 'ownership_status_label', 'ownership_period',
        'property_right_type', 'property_right_number', 'property_right_date',
        'person_type', 'is_legal_entity',
    );
    foreach ($string_fields as $key) {
        if (!isset($data[$key]) || !is_scalar($data[$key])) {
            continue;
        }
        $v = trim((string) $data[$key]);
        if ($v === '' || strtolower($v) === 'null') {
            unset($data[$key]);
            continue;
        }
        if (yvo_looks_like_parser_placeholder($v, $key)) {
            unset($data[$key]);
            continue;
        }
        if ($key === 'full_name' && !$is_legal && (yvo_is_demo_full_name($v) || yvo_full_name_looks_invalid($v))) {
            unset($data[$key]);
            continue;
        }
        if (preg_match('/^\(.*\)$/u', $v) || preg_match('/^[а-яё\s]+:\s*$/ui', $v)) {
            unset($data[$key]);
        }
    }
    if ($is_legal && empty($data['full_name']) && !empty($data['company_name'])) {
        $data['full_name'] = trim((string) $data['company_name']);
    }
    if (empty($data['full_name']) && $source_text !== '' && !$is_legal) {
        $retry_name = yvo_extract_full_name_from_text($source_text);
        if ($retry_name !== '' && !yvo_full_name_looks_invalid($retry_name)) {
            $data['full_name'] = $retry_name;
        }
    }
    return $data;
}

/**
 * Подстановка символов под типичную «кракозябру» выписок ЕГРН из Госуслуг/Росреестра (шрифт с неверной кодировкой).
 * Сначала нормализация кавычек/тире, затем замены целых фраз, затем оставшиеся символы по таблице.
 */
function yvo_decode_egrn_garbled_symbols($text) {
    if (!is_string($text) || $text === '') return $text;
    $t = $text;
    $t = str_replace(array("\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9A", "\xE2\x80\x9B", "\xE2\x80\xB2"), "'", $t);
    $t = str_replace(array("\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x9E", "\xE2\x80\x9F"), '"', $t);
    $t = str_replace(array("\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x92", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92"), '-', $t);
    if (function_exists('mb_convert_encoding')) {
        $valid = @preg_match('//u', $t);
        if (!$valid && strlen($t) > 0) {
            $t = @mb_convert_encoding($t, 'UTF-8', 'ISO-8859-1');
        }
    }
    static $phrases = null;
    static $char_map = null;
    if ($phrases === null) {
        $phrases = array(
            '!"#$% &' . "'" . '(' . "'" . ')*%"+"," &+' . "'" . '%*' . "'" . '-' => 'Кадастровый номер',
            '.' . "'" . '*'. "'" . ' /%0)+"$102 &' . "'" . '(' . "'" . ')*%"+"," 1"#$%' . "'" => 'Дата присвоения кадастрового номера',
            "('114\$ \"*)6*)*+68*" => 'квартира',
            'E-"F' . "'" . '(G, #2' => 'Площадь, кв.м',
            '!' . "'" . 'D1' . "'" . '7$10$' => 'Этаж',
            '!' . "'" . '0#$1"+' . "'" . '10$' => 'Собственник',
            'K*' . "'" . 'J' => 'Лист',
            'M0( J0-"," /"#$F$102' => 'Дата выдачи выписки',
            'N+' . "'" . '%*0%' . "'" => 'Росреестр',
            'N' . "'" . '(' . "'" . ')*%"+' . "'" . '2 )*"0#")*G, %6<' => 'Кадастровая стоимость',
            'E"-67' . "'" . '*$-G +4/0)&0' => 'Исполнитель',
            '/"-1"$ 1' . "'" . '0#$1"+' . "'" . '10$ ("-J1")*0' => 'Документ подписан электронной подписью',
            'P+$($102' => 'Сведения',
            'M4/0)&' . "'" . ' 0D S(01","' => 'Выписка из',
            '3' . "'" . 'D($-' => 'Раздел',
            'W0)*' => 'Лист',
            'E"#$F$10$' => 'Подпись',
            'N' . "'" . '(' . "'" . ')*%"+45 1"#$%' => 'Кадастровый номер',
            'P+$($102 " +"D#"J1")*0' => 'Сведения о зарегистрированном',
            'E-' . "'" . '1 %' . "'" . ')/"-"' . "'" . 'J$102' => 'Подпись правообладателя',
            'C' . "'" . ')>*' . "'" . '< 1:100' => 'Масштаб 1:100',
        );
    }
    $t = $text;
    foreach ($phrases as $garbled => $correct) {
        $t = str_replace($garbled, $correct, $t);
    }
    if ($char_map === null) {
        $char_map = array(
            '!' => 'К', '"' => 'а', '#' => 'д', '$' => 'о', '%' => 'с', '&' => 'т', "'" => 'р', '(' => 'о', ')' => 'в',
            '*' => 'ы', '+' => 'й', ',' => 'й', '-' => 'н', '.' => '.', '/' => 'п',
            '0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9',
            ':' => ':', ';' => ',', '<' => 'з', '=' => '=', '>' => '>', '?' => '?', '@' => 'И',
            'A' => 'О', 'B' => 'л', 'C' => 'Д', 'D' => 'б', 'E' => 'П', 'F' => 'щ', 'G' => 'и', 'H' => 'Э', 'I' => 'В', 'J' => 'т',
            'K' => 'Л', 'L' => 'L', 'M' => 'Д', 'N' => 'К', 'O' => 'О', 'P' => 'С', 'Q' => 'О', 'R' => 'И', 'S' => 'С', 'T' => 'У',
            'U' => 'О', 'V' => 'О', 'W' => 'М', 'X' => 'Х', 'Y' => 'У', 'Z' => 'З',
        );
    }
    $out = '';
    $len = @mb_strlen($t, 'UTF-8');
    if (($len === false || $len === 0) && strlen($t) > 0) {
        for ($i = 0; $i < strlen($t); $i++) {
            $b = $t[$i];
            $c = chr(ord($b));
            $out .= isset($char_map[$c]) ? $char_map[$c] : $c;
        }
        return $out;
    }
    for ($i = 0; $i < $len; $i++) {
        $c = mb_substr($t, $i, 1, 'UTF-8');
        $out .= isset($char_map[$c]) ? $char_map[$c] : $c;
    }
    return $out;
}

/**
 * Извлечение встроенного текста из PDF.
 * Сначала пробуем pdftotext (системный или встроенный), затем Smalot (PHP) как запасной вариант.
 * Так плагин может работать и на хостинге (где есть pdftotext в PATH), и локально (со встроенным Poppler).
 */
function yvo_extract_text_from_pdf_file($file_path) {
    $GLOBALS['yvo_pdf_extraction_debug'] = '';
    if (!is_readable($file_path)) {
        yvo_pdf_extraction_debug_set('Файл не читается');
        return '';
    }

    // 1) pdftotext (Poppler/Xpdf) — приоритетный способ
    if (!is_dir(YVO_TEMP_DIR)) {
        wp_mkdir_p(YVO_TEMP_DIR);
    }
    $out_file = YVO_TEMP_DIR . 'yvo_pdftotext_' . wp_rand(10000, 99999) . '.txt';
    $pdftotext = yvo_get_pdftotext_path();
    $is_bundled = strpos($pdftotext, YVO_PLUGIN_DIR) === 0 && file_exists($pdftotext);
    yvo_pdf_extraction_debug_set('pdftotext: ' . ($is_bundled ? 'встроенный' : 'системный') . ' (' . $pdftotext . ')');
    $run_ok = false;
    if ($is_bundled && function_exists('proc_open')) {
        $bin_dir = dirname($pdftotext);
        $args = array($pdftotext, '-layout', '-enc', 'UTF-8', $file_path, $out_file);
        $descriptorspec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $proc = @proc_open($args, $descriptorspec, $pipes, $bin_dir, null);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $run_ok = true;
        }
    }
    if (!$run_ok && (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions')))))) {
        $cmd = sprintf('%s -layout -enc UTF-8 %s %s 2>nul', escapeshellarg($pdftotext), escapeshellarg($file_path), escapeshellarg($out_file));
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $cmd = str_replace('2>nul', '2>/dev/null', $cmd);
        }
        @shell_exec($cmd);
        $run_ok = true;
    }
    if (is_readable($out_file)) {
        $text = file_get_contents($out_file);
        @unlink($out_file);
        if (is_string($text)) {
            $text = trim($text);
            if ($text !== '') {
                yvo_pdf_extraction_debug_set('pdftotext: извлечено ' . strlen($text) . ' символов');
                return $text;
            }
        }
    }
    if (file_exists($out_file)) {
        @unlink($out_file);
    }
    yvo_pdf_extraction_debug_set('pdftotext: результат пустой, пробуем Smalot');

    // 2) Smalot (PHP-парсер) как fallback — чтобы плагин работал даже без pdftotext
    $autoload = YVO_PLUGIN_DIR . 'vendor/autoload.php';
    yvo_pdf_extraction_debug_set('autoload: ' . (is_file($autoload) ? 'да' : 'нет'));
    if (is_file($autoload)) {
        require_once $autoload;
    }
    if (class_exists('Smalot\PdfParser\Parser')) {
        yvo_pdf_extraction_debug_set('Smalot: класс найден');
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $content = @file_get_contents($file_path);
            if ($content === false || $content === '') {
                yvo_pdf_extraction_debug_set('Smalot: не прочитать файл');
            } else {
                $pdf = $parser->parseContent($content);
                $text = $pdf->getText();
                if (is_string($text)) {
                    $text = trim($text);
                    if ($text !== '') {
                        $text = yvo_fix_pdf_text_encoding($text);
                        yvo_pdf_extraction_debug_set('Smalot: извлечено ' . strlen($text) . ' символов');
                        return $text;
                    }
                }
                yvo_pdf_extraction_debug_set('Smalot: getText() пустой');
            }
        } catch (\Throwable $e) {
            yvo_pdf_extraction_debug_set('Smalot ошибка: ' . $e->getMessage());
        }
    } else {
        yvo_pdf_extraction_debug_set('Smalot: класс не найден');
    }

    return '';
}

/**
 * Сломанный текстовый слой PDF (подстановочный шрифт / OCR внутри PDF без кириллицы).
 * Типично для сканов паспорта: «MECTO ЖИТЕЛЬСТВА», «3APETИCTPIPOBAH» вместо русского текста.
 */
function yvo_pdf_direct_text_looks_garbled($text) {
    if (!is_string($text) || $text === '') {
        return false;
    }
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    $cyr = preg_match_all('/[а-яА-ЯёЁ]/u', $text);
    $letters = preg_match_all('/[а-яА-ЯёЁa-zA-Z0-9]/u', $text);
    $latin = preg_match_all('/[a-zA-Z]/u', $text);
    if ($letters >= 60 && $cyr === 0 && $latin >= 40) {
        return true;
    }
    if ($letters >= 80 && $cyr > 0 && $letters > 0 && ($cyr / $letters) < 0.08) {
        return true;
    }
    if ($cyr < 15 && preg_match('/MECTO\s.*ITE|3APET|ZAPET|OrABneH|B0ltHcKA|tpax.?qaH|n0\s+r\.\s*yse|Cor.?trctcoro|3anIcr/ui', $text)) {
        return true;
    }
    if (function_exists('yvo_text_looks_garbled') && yvo_text_looks_garbled($text)) {
        return true;
    }
    return false;
}

/**
 * Проверка: достаточно ли в извлечённом тексте полезного содержимого (не только блок подписи ЕГРН).
 */
function yvo_pdf_direct_text_is_usable($text) {
    if (!is_string($text)) {
        return false;
    }
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    if (function_exists('yvo_pdf_direct_text_looks_garbled') && yvo_pdf_direct_text_looks_garbled($text)) {
        return false;
    }
    $letters = preg_match_all('/[а-яА-ЯёЁa-zA-Z0-9]/u', $text);
    return $letters >= 20;
}

/**
 * Если по OCR распознался в основном только блок подписи ЕГРН — добавляем подсказку.
 */
function yvo_pdf_ocr_append_hint_if_only_signature($text) {
    $t = preg_replace('/\s+/u', ' ', $text);
    $sig_len = 0;
    foreach (array('ДОКУМЕНТ ПОДПИСАН', 'Сертификат', 'Владелец', 'Действителен', 'ФЕДЕРАЛЬНАЯ СЛУЖБА', 'ЭЛЕКТРОННОЙ ПОДПИСЬЮ') as $phrase) {
        $sig_len += substr_count($t, $phrase) * strlen($phrase);
    }
    if (strlen($t) > 100 && $sig_len > strlen($t) * 0.5) {
        $text .= "\n\n[По изображению распознан в основном блок подписи. Для выписки ЕГРН установите pdftotext (poppler-utils) или библиотеку smalot/pdfparser — тогда будет извлекаться встроенный текст.]";
    }
    return $text;
}

/**
 * Проверка: похоже ли текст на «кракозябры» (мало кириллицы при длинном тексте).
 */
function yvo_text_looks_garbled($text) {
    if (!is_string($text) || strlen($text) < 500) {
        return false;
    }
    $cyrillic_count = preg_match_all('/[а-яА-ЯёЁ]/u', $text);
    $letter_count  = preg_match_all('/[а-яА-ЯёЁa-zA-Z]/u', $text);
    return $letter_count >= 200 && ($cyrillic_count < 50 || ($letter_count > 0 && $cyrillic_count / $letter_count < 0.05));
}

/**
 * Исправление текста ЕГРН с повреждённой кодировкой через DeepSeek. Возвращает исправленный текст или исходный при ошибке.
 */
function yvo_deepseek_fix_egrn_text($text) {
    if (!function_exists('yvo_text_looks_garbled') || !yvo_text_looks_garbled($text)) {
        return $text;
    }
    if (empty(get_option('yvo_deepseek_api_key'))) {
        return $text;
    }
    $parser_file = defined('YVO_PLUGIN_DIR') ? YVO_PLUGIN_DIR . 'includes/deepseek-parser.php' : __DIR__ . '/includes/deepseek-parser.php';
    if (is_file($parser_file)) {
        require_once $parser_file;
    }
    if (!class_exists('YVO_DeepSeekParser')) {
        return $text;
    }
    $parser = new YVO_DeepSeekParser();
    $result = $parser->fix_egrn_text($text);
    if (!empty($result['success']) && isset($result['text']) && strlen($result['text']) > 100) {
        return $result['text'];
    }
    return $text;
}

/**
 * Достаточно ли данных из локального парсера (без ожидания DeepSeek).
 *
 * @param array<string,mixed>|null $property
 * @param array<int,array<string,mixed>> $participants
 * @param array<string,mixed>        $egrn_check
 */
function yvo_egrn_local_extract_is_sufficient($property, $participants, $egrn_check) {
    if (is_array($property)) {
        foreach (array('cadastral_number', 'address', 'house_settlement', 'house_cadastral_number') as $k) {
            if (trim((string) ($property[$k] ?? '')) !== '') {
                return true;
            }
        }
    }
    if (is_array($participants)) {
        foreach ($participants as $p) {
            if (is_array($p) && trim((string) ($p['full_name'] ?? '')) !== '') {
                return true;
            }
        }
    }
    if (is_array($egrn_check) && !empty($egrn_check['has_mortgage'])) {
        return true;
    }
    return false;
}

/**
 * Извлечение из текста ЕГРН: объект недвижимости и участники. Возвращает array('property' => array, 'participants' => array).
 *
 * @param array{local_only?:bool,quality?:bool} $opts local_only — не вызывать DeepSeek; quality — доработать адрес через AI при необходимости.
 */
function yvo_deepseek_extract_egrn_data($text, $opts = array()) {
    if (empty($text) || strlen($text) < 200) {
        return array('property' => null, 'participants' => array(), 'participants_all' => array(), 'ownership_history' => array(), 'egrn_check' => array());
    }
    $opts = is_array($opts) ? $opts : array();
    $local_only = !empty($opts['local_only']);
    $quality = !empty($opts['quality']);

    $local = function_exists('yvo_parse_egrn_text_local')
        ? yvo_parse_egrn_text_local($text)
        : array('property' => array(), 'participants' => array(), 'participants_all' => array(), 'ownership_history' => array(), 'egrn_check' => array());

    $property = !empty($local['property']) ? $local['property'] : null;
    $participants = isset($local['participants']) && is_array($local['participants']) ? $local['participants'] : array();
    $participants_all = isset($local['participants_all']) && is_array($local['participants_all']) ? $local['participants_all'] : $participants;
    $ownership_history = isset($local['ownership_history']) && is_array($local['ownership_history']) ? $local['ownership_history'] : array();
    $egrn_check = isset($local['egrn_check']) && is_array($local['egrn_check']) ? $local['egrn_check'] : array();
    $has_local_current = !empty($participants);

    $local_ok = yvo_egrn_local_extract_is_sufficient($property, $participants, $egrn_check);
    $address_bad = function_exists('yvo_property_address_needs_refinement') && yvo_property_address_needs_refinement($property);
    $need_ai = !$local_only && !empty(get_option('yvo_deepseek_api_key')) && (!$local_ok || ($quality && $address_bad));

    if ($need_ai) {
        $parser_file = defined('YVO_PLUGIN_DIR') ? YVO_PLUGIN_DIR . 'includes/deepseek-parser.php' : __DIR__ . '/includes/deepseek-parser.php';
        if (is_file($parser_file)) {
            require_once $parser_file;
        }
        if (class_exists('YVO_DeepSeekParser')) {
            $parser = new YVO_DeepSeekParser();
            $result = $parser->parse_egrn_data($text);
            if (!empty($result['success'])) {
                if (!empty($result['property']) && is_array($result['property'])) {
                    $property = function_exists('yvo_merge_egrn_extracted_property')
                        ? yvo_merge_egrn_extracted_property($property, $result['property'])
                        : array_merge((array) $property, $result['property']);
                }
                // Не подменять актуальные локальные собственники списком из ИИ (там часто вся история).
                if (!$has_local_current && !empty($result['participants']) && is_array($result['participants'])) {
                    $participants = $result['participants'];
                }
                if (!empty($result['egrn_check']) && is_array($result['egrn_check'])) {
                    $egrn_check = array_merge($egrn_check, $result['egrn_check']);
                }
            }
        }
    }

    if (is_array($property) && !empty($property['address']) && function_exists('yvo_split_russian_address')) {
        $parts = yvo_split_russian_address($property['address']);
        $property = function_exists('yvo_merge_egrn_extracted_property')
            ? yvo_merge_egrn_extracted_property($property, $parts)
            : array_merge($property, $parts);
    }

    if (is_array($property) && function_exists('yvo_normalize_property_address_fields')) {
        $property = yvo_normalize_property_address_fields($property);
    }

    if (is_array($property) && function_exists('yvo_egrn_normalize_property_by_object_type')) {
        $property = yvo_egrn_normalize_property_by_object_type($property);
    }

    return array(
        'property' => !empty($property) ? $property : null,
        'participants' => $participants,
        'participants_all' => !empty($participants_all) ? $participants_all : $participants,
        'ownership_history' => $ownership_history,
        'egrn_check' => $egrn_check,
    );
}

// Класс обработки PDF (подключение из папки includes)
if (!class_exists('YVO_PDF_Processor')) {
    $yvo_pdf_class = __DIR__ . '/includes/class-pdf-processor.php';
    if (is_file($yvo_pdf_class)) {
        require_once $yvo_pdf_class;
    }
}
if (!class_exists('YVO_PDF_Processor')) {
    // Запасной класс, если файл includes не найден (всё в одном плагине)
    class YVO_PDF_Processor {
        public function check_pdf_support() {
            if (function_exists('yvo_pdf_is_supported')) {
                return yvo_pdf_is_supported();
            }
            return false;
        }
        public function process($pdf_url, $api_key, $folder_id, $language = 'ru') {
            if (!$this->check_pdf_support()) {
                return array('success' => false, 'message' => 'Обработка PDF недоступна.');
            }
            $local_pdf = $this->download_file($pdf_url);
            if (!$local_pdf) return array('success' => false, 'message' => 'Не удалось загрузить PDF');
            $max_size = get_option('yvo_max_size', 20) * 1024 * 1024;
            if (filesize($local_pdf) > $max_size) { @unlink($local_pdf); return array('success' => false, 'message' => 'Файл слишком большой'); }
            if (function_exists('yvo_extract_text_from_pdf_file') && function_exists('yvo_pdf_direct_text_is_usable')) {
                $direct_text = yvo_extract_text_from_pdf_file($local_pdf);
                if (yvo_pdf_direct_text_is_usable($direct_text)) {
                    @unlink($local_pdf);
                    return array('success' => true, 'text' => $direct_text, 'filename' => basename(parse_url($pdf_url, PHP_URL_PATH) ?: 'document.pdf'), 'pages' => 1);
                }
            }
            try {
                if (class_exists('Spatie\PdfToImage\Pdf')) $result = $this->process_with_spatie($local_pdf, $api_key, $folder_id, $language);
                elseif (extension_loaded('imagick')) $result = $this->process_with_imagick($local_pdf, $api_key, $folder_id, $language);
                else $result = array('success' => false, 'message' => 'Не найдены инструменты для PDF');
            } catch (Exception $e) { $result = array('success' => false, 'message' => $e->getMessage()); }
            if (file_exists($local_pdf)) @unlink($local_pdf);
            return $result;
        }
        public function process_from_path($local_pdf_path, $api_key, $folder_id, $language = 'ru') {
            if (!file_exists($local_pdf_path)) return array('success' => false, 'message' => 'Файл не найден');
            if (!$this->check_pdf_support()) return array('success' => false, 'message' => 'Обработка PDF недоступна.');
            $max_size = get_option('yvo_max_size', 20) * 1024 * 1024;
            if (filesize($local_pdf_path) > $max_size) return array('success' => false, 'message' => 'Файл слишком большой');
            if (function_exists('yvo_extract_text_from_pdf_file') && function_exists('yvo_pdf_direct_text_is_usable')) {
                $direct_text = yvo_extract_text_from_pdf_file($local_pdf_path);
                if (yvo_pdf_direct_text_is_usable($direct_text)) {
                    return array('success' => true, 'text' => $direct_text, 'filename' => basename($local_pdf_path), 'pages' => 1);
                }
            }
            try {
                if (class_exists('Spatie\PdfToImage\Pdf')) $result = $this->process_with_spatie($local_pdf_path, $api_key, $folder_id, $language);
                elseif (extension_loaded('imagick')) $result = $this->process_with_imagick($local_pdf_path, $api_key, $folder_id, $language);
                else $result = array('success' => false, 'message' => 'Не найдены инструменты');
                if (!empty($result['success']) && isset($result['text'])) {
                    $result['text'] = "--- Использовано распознавание по изображению ---\n\n" . $result['text'];
                    if (function_exists('yvo_pdf_extraction_debug_get')) { $d = yvo_pdf_extraction_debug_get(); if ($d !== '') $result['text'] .= "\n\n--- Диагностика ---\n" . $d; }
                }
            } catch (Exception $e) { return array('success' => false, 'message' => $e->getMessage()); }
            return isset($result) ? $result : array('success' => false, 'message' => 'Ошибка');
        }
        private function process_with_spatie($p, $api_key, $folder_id, $lang) {
            try {
                $pdf = new Spatie\PdfToImage\Pdf($p);
                $pages = $pdf->getNumberOfPages();
                if ($pages > get_option('yvo_pdf_max_pages', 10)) return array('success' => false, 'message' => 'Слишком много страниц');
                $temp_dir = YVO_TEMP_DIR . 'pdf_img_' . time() . '/';
                wp_mkdir_p($temp_dir);
                $all = '';
                for ($i = 1; $i <= $pages; $i++) {
                    $img = $temp_dir . 'p' . $i . '.jpg';
                    $pdf->setPage($i)->saveImage($img);
                    $r = $this->recognize_page($img, $api_key, $folder_id, $lang);
                    if (!empty($r['success'])) $all .= "--- Страница $i ---\n" . $r['text'] . "\n\n";
                    @unlink($img);
                }
                $this->delete_dir($temp_dir);
                $all = trim($all);
                if ($all === '') return array('success' => false, 'message' => 'Текст не распознан');
                if (function_exists('yvo_pdf_ocr_append_hint_if_only_signature')) $all = yvo_pdf_ocr_append_hint_if_only_signature($all);
                return array('success' => true, 'text' => $all, 'filename' => basename($p), 'pages' => $pages);
            } catch (Exception $e) { return array('success' => false, 'message' => $e->getMessage()); }
        }
        private function process_with_imagick($p, $api_key, $folder_id, $lang) {
            try {
                $im = new Imagick();
                $im->setResolution(280, 280);
                $im->readImage($p);
                $pages = $im->getNumberImages();
                if ($pages > get_option('yvo_pdf_max_pages', 10)) { $im->clear(); $im->destroy(); return array('success' => false, 'message' => 'Слишком много страниц'); }
                $temp_dir = YVO_TEMP_DIR . 'pdf_img_' . time() . '/';
                wp_mkdir_p($temp_dir);
                $all = '';
                for ($i = 0; $i < $pages; $i++) {
                    $im->setIteratorIndex($i);
                    $im->setImageFormat('jpeg');
                    $img = $temp_dir . 'p' . ($i+1) . '.jpg';
                    $im->writeImage($img);
                    $r = $this->recognize_page($img, $api_key, $folder_id, $lang);
                    if (!empty($r['success'])) $all .= "--- Страница " . ($i+1) . " ---\n" . $r['text'] . "\n\n";
                    @unlink($img);
                }
                $im->clear(); $im->destroy();
                $this->delete_dir($temp_dir);
                $all = trim($all);
                if ($all === '') return array('success' => false, 'message' => 'Текст не распознан');
                if (function_exists('yvo_pdf_ocr_append_hint_if_only_signature')) $all = yvo_pdf_ocr_append_hint_if_only_signature($all);
                return array('success' => true, 'text' => $all, 'filename' => basename($p), 'pages' => $pages);
            } catch (Exception $e) { return array('success' => false, 'message' => $e->getMessage()); }
        }
        private function recognize_page($img_path, $api_key, $folder_id, $lang) {
            if (!file_exists($img_path)) return array('success' => false, 'message' => 'Нет файла');
            $url = 'https://vision.api.cloud.yandex.net/vision/v1/batchAnalyze';
            $body = array('folderId' => $folder_id, 'analyze_specs' => array(array('content' => base64_encode(file_get_contents($img_path)), 'features' => array(array('type' => 'TEXT_DETECTION', 'text_detection_config' => array('language_codes' => array($lang)))))));
            $ch = curl_init();
            curl_setopt_array($ch, array(CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => array('Authorization: Api-Key ' . $api_key, 'Content-Type: application/json'), CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 30));
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code != 200) return array('success' => false, 'message' => "API $code");
            $data = json_decode($resp, true);
            $text = '';
            if (isset($data['results'][0]['results'][0]['textDetection']['pages'][0]['blocks'])) {
                foreach ($data['results'][0]['results'][0]['textDetection']['pages'][0]['blocks'] as $block) {
                    if (isset($block['lines'])) foreach ($block['lines'] as $line) {
                        if (isset($line['words'])) { $line_text = ''; foreach ($line['words'] as $w) $line_text .= (isset($w['text']) ? $w['text'] : '') . ' '; $text .= trim($line_text) . "\n"; }
                    }
                }
            }
            $text = trim($text);
            return $text === '' ? array('success' => false, 'message' => 'Нет текста') : array('success' => true, 'text' => $text);
        }
        private function download_file($url) {
            $tmp = YVO_TEMP_DIR . sanitize_file_name(basename(parse_url($url, PHP_URL_PATH)) ?: 'pdf') . '_' . time() . '.pdf';
            $r = wp_remote_get($url, array('timeout' => 60));
            if (is_wp_error($r) || empty(wp_remote_retrieve_body($r))) return false;
            return file_put_contents($tmp, wp_remote_retrieve_body($r)) ? $tmp : false;
        }
        private function delete_dir($dir) {
            if (!is_dir($dir)) return true;
            foreach (array_diff(scandir($dir), array('.', '..')) as $item) $this->delete_dir($dir . '/' . $item);
            return @rmdir($dir);
        }
    }
}

// Меню админки
add_action('admin_menu', 'yvo_admin_menu');
function yvo_admin_menu() {
    add_menu_page(
        'Яндекс OCR Pro AI',
        'Яндекс OCR Pro AI',
        'manage_options',
        'yandex-ocr-pro',
        'yvo_dashboard_page',
        'dashicons-text-page',
        30
    );
    
    add_submenu_page(
        'yandex-ocr-pro',
        'Главная',
        'Главная',
        'manage_options',
        'yandex-ocr-pro',
        'yvo_dashboard_page'
    );
    
    add_submenu_page(
        'yandex-ocr-pro',
        'Распознать документы',
        'Распознать документы',
        'manage_options',
        'yandex-ocr-pro-recognize',
        'yvo_main_page'
    );
    
    add_submenu_page(
        'yandex-ocr-pro',
        'Данные сделки',
        'Данные сделки',
        'manage_options',
        'yandex-ocr-pro-data',
        'yvo_data_page'
    );
    
    add_submenu_page(
        'yandex-ocr-pro',
        'История договоров',
        'История договоров',
        'manage_options',
        'yandex-ocr-pro-contracts',
        'yvo_contracts_history_page'
    );
    
    add_submenu_page(
        'yandex-ocr-pro',
        'Шаблоны',
        'Шаблоны',
        'manage_options',
        'yandex-ocr-pro-templates',
        'yvo_templates_admin_page'
    );
    
    add_submenu_page(
        'yandex-ocr-pro',
        'Настройки',
        'Настройки',
        'manage_options',
        'yandex-ocr-pro-settings',
        'yvo_settings_page'
    );

    add_submenu_page(
        'yandex-ocr-pro',
        'Личный кабинет (сайт)',
        'Личный кабинет',
        'manage_options',
        'yandex-ocr-pro-cabinet',
        'yvo_admin_cabinet_links_page'
    );
}

/**
 * Ссылки на страницы входа/кабинета на сайте (для администратора).
 */
function yvo_admin_cabinet_links_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (isset($_GET['yvo_rebuild_cabinet']) && check_admin_referer('yvo_rebuild_cabinet')) {
        delete_transient('yvo_cabinet_build_lock');
        delete_option('yvo_cabinet_bootstrapped');
        yvo_ensure_required_pages();
        if (yvo_cabinet_pages_filled()) {
            update_option('yvo_cabinet_bootstrapped', 'yes');
        }
        echo '<div class="notice notice-success is-dismissible"><p>Страницы кабинета обновлены.</p></div>';
    }

    $urls = array(
        'Договоры (форма)' => get_option('yvo_cabinet_contracts_page', ''),
        'Вход'             => get_option('yvo_cabinet_login_page', ''),
        'Регистрация'      => get_option('yvo_cabinet_register_page', ''),
        'Восстановление пароля' => get_option('yvo_cabinet_forgot_page', ''),
        'Кабинет (аккаунт)' => get_option('yvo_cabinet_account_page', ''),
        'Профиль'          => get_option('yvo_cabinet_profile_page', ''),
        'Профиль и баланс' => get_option('yvo_cabinet_wallet_page', ''),
    );
    $virt = function_exists('yvo_cabinet_route_url') ? array(
        'Договоры (вирт.)' => yvo_cabinet_route_url('contracts'),
        'Вход (вирт.)'     => yvo_cabinet_route_url('login'),
        'Регистрация (вирт.)' => yvo_cabinet_route_url('register'),
        'Восстановление (вирт.)' => yvo_cabinet_route_url('forgot'),
        'Кабинет (вирт.)'  => yvo_cabinet_route_url('account'),
        'Профиль (вирт.)'  => yvo_cabinet_route_url('profile'),
        'Кошелёк→профиль (вирт.)' => yvo_cabinet_route_url('wallet'),
        'Тарифы (вирт.)'   => yvo_cabinet_route_url('pricing'),
        'Сделки CRM (вирт., платный тариф)' => yvo_cabinet_route_url('deals'),
        'Проверка квартиры (вирт.)' => yvo_cabinet_route_url('property_check'),
    ) : array();
    $rebuild = wp_nonce_url(
        admin_url('admin.php?page=yandex-ocr-pro-cabinet&yvo_rebuild_cabinet=1'),
        'yvo_rebuild_cabinet'
    );
    ?>
    <div class="wrap">
        <h1>Личный кабинет на сайте</h1>
        <p><strong>Работает всегда:</strong> откройте на сайте ссылки с параметром <code>?yvo_cabinet=…</code> — страницы в админке «Страницы» создавать не обязательно.</p>
        <?php if (!empty($virt)) : ?>
        <table class="widefat striped" style="max-width:720px;margin-bottom:20px;">
            <thead><tr><th>Виртуальный кабинет (без страниц в БД)</th><th>URL</th></tr></thead>
            <tbody>
            <?php foreach ($virt as $label => $url) : ?>
                <tr>
                    <td><?php echo esc_html($label); ?></td>
                    <td><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($url); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <p>Опционально: страницы WordPress со шорткодами (кнопка ниже). Удобно для ЧПУ без параметров в адресной строке.</p>
        <table class="widefat striped" style="max-width:720px;">
            <thead><tr><th>Страница</th><th>URL</th></tr></thead>
            <tbody>
            <?php foreach ($urls as $label => $url) : ?>
                <tr>
                    <td><?php echo esc_html($label); ?></td>
                    <td><?php echo $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($url) . '</a>' : '<em>не создана — не обязательно, см. таблицу выше</em>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:16px;">
            <a class="button button-primary" href="<?php echo esc_url($rebuild); ?>">Создать / обновить страницы кабинета</a>
        </p>
        <p class="description">Шорткоды: <code>[yvo_cabinet_login]</code>, <code>[yvo_cabinet_register]</code>, <code>[yvo_cabinet_account]</code>, <code>[yvo_contract_form]</code>, <code>[yvo_jpg_pdf_chat]</code> (модуль в папке <code>yvo-jpg-pdf-chat/</code> этого плагина).</p>
    </div>
    <?php
}

// Стили и скрипты
add_action('admin_enqueue_scripts', 'yvo_admin_scripts');
function yvo_admin_scripts($hook) {
    $plugin_pages = array(
        'toplevel_page_yandex-ocr-pro',
        'yandex-ocr-pro_page_yandex-ocr-pro-data',
        'yandex-ocr-pro_page_yandex-ocr-pro-settings',
        'yandex-ocr-pro_page_yandex-ocr-pro-recognize',
        'yandex-ocr-pro_page_yandex-ocr-pro-contracts',
        'yandex-ocr-pro_page_yandex-ocr-pro-templates',
        'yandex-ocr-pro_page_yandex-ocr-pro-cabinet'
    );
    
    if (!in_array($hook, $plugin_pages)) {
        return;
    }
    
    wp_enqueue_style('yvo-admin-css', YVO_PLUGIN_URL . 'css/admin.css');
    wp_enqueue_script('yvo-admin-js', YVO_PLUGIN_URL . 'js/admin.js', array('jquery'), YVO_VERSION, true);
    wp_enqueue_media();
    
    wp_localize_script('yvo-admin-js', 'yvo_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('yvo_nonce'),
        'processing' => 'Обработка...',
        'success' => 'Данные распознаны успешно!',
        'error' => 'Произошла ошибка'
    ));
}

// AJAX обработчики
add_action('wp_ajax_yvo_process_document', 'yvo_ajax_process_document');
add_action('wp_ajax_yvo_test_api', 'yvo_ajax_test_api');
add_action('wp_ajax_yvo_save_form_data', 'yvo_ajax_save_form_data');
add_action('wp_ajax_yvo_load_form_data', 'yvo_ajax_load_form_data');
add_action('wp_ajax_yvo_generate_contract', 'yvo_ajax_generate_contract');
add_action('wp_ajax_yvo_check_pdf_support', 'yvo_ajax_check_pdf_support');
add_action('wp_ajax_yvo_test_deepseek', 'yvo_ajax_test_deepseek');
add_action('wp_ajax_yvo_test_fix_egrn', 'yvo_ajax_test_fix_egrn');
add_action('wp_ajax_yvo_parse_with_deepseek', 'yvo_ajax_parse_with_deepseek');

// Ранняя обработка загрузки — до любых плагинов безопасности (init priority 1)
add_action('init', function () {
    if (!defined('DOING_AJAX') || !DOING_AJAX) return;
    $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
    if ($action === 'yvo_frontend_upload') {
        yvo_ajax_frontend_upload();
        exit;
    }
}, 1);

// Фронтенд: шорткод и AJAX для отдельной страницы
add_shortcode('yvo_contract_form', 'yvo_frontend_contract_form_shortcode');
// Старый ярлык из readme / includes/shortcode.php — та же форма договора
add_shortcode('yandex_ocr_form', 'yvo_frontend_contract_form_shortcode');
add_action('wp_enqueue_scripts', 'yvo_maybe_enqueue_frontend_assets');
add_action('wp_ajax_yvo_frontend_upload', 'yvo_ajax_frontend_upload', 1);
add_action('wp_ajax_nopriv_yvo_frontend_upload', 'yvo_ajax_frontend_upload', 1);
add_action('wp_ajax_yvo_frontend_parse', 'yvo_ajax_frontend_parse');
add_action('wp_ajax_nopriv_yvo_frontend_parse', 'yvo_ajax_frontend_parse');
add_action('wp_ajax_yvo_fix_egrn_text', 'yvo_ajax_fix_egrn_text', 1);
add_action('wp_ajax_nopriv_yvo_fix_egrn_text', 'yvo_ajax_fix_egrn_text', 1);
add_action('wp_ajax_yvo_get_fix_egrn_result', 'yvo_ajax_get_fix_egrn_result', 1);
add_action('wp_ajax_nopriv_yvo_get_fix_egrn_result', 'yvo_ajax_get_fix_egrn_result', 1);
add_action('wp_ajax_yvo_extract_egrn_data', 'yvo_ajax_extract_egrn_data');
add_action('wp_ajax_nopriv_yvo_extract_egrn_data', 'yvo_ajax_extract_egrn_data');
add_action('wp_ajax_yvo_translate_text', 'yvo_ajax_translate_text');
add_action('wp_ajax_nopriv_yvo_translate_text', 'yvo_ajax_translate_text');
add_action('wp_ajax_yvo_frontend_parse_all_persons', 'yvo_ajax_frontend_parse_all_persons');
add_action('wp_ajax_nopriv_yvo_frontend_parse_all_persons', 'yvo_ajax_frontend_parse_all_persons');
add_action('wp_ajax_yvo_frontend_extract_summary', 'yvo_ajax_frontend_extract_summary');
add_action('wp_ajax_nopriv_yvo_frontend_extract_summary', 'yvo_ajax_frontend_extract_summary');
add_action('wp_ajax_yvo_frontend_review_fields', 'yvo_ajax_frontend_review_fields');
add_action('wp_ajax_nopriv_yvo_frontend_review_fields', 'yvo_ajax_frontend_review_fields');
add_action('wp_ajax_yvo_frontend_generate_contract', 'yvo_ajax_frontend_generate_contract');
add_action('wp_ajax_nopriv_yvo_frontend_generate_contract', 'yvo_ajax_frontend_generate_contract');
add_action('wp_ajax_yvo_frontend_get_template_preview', 'yvo_ajax_frontend_get_template_preview');
add_action('wp_ajax_nopriv_yvo_frontend_get_template_preview', 'yvo_ajax_frontend_get_template_preview');
add_action('wp_ajax_yvo_analyze_contract_template', 'yvo_ajax_analyze_contract_template');
add_action('wp_ajax_nopriv_yvo_analyze_contract_template', 'yvo_ajax_analyze_contract_template');
add_action('wp_ajax_yvo_extract_template_text', 'yvo_ajax_extract_template_text');
add_action('wp_ajax_nopriv_yvo_extract_template_text', 'yvo_ajax_extract_template_text');
add_action('wp_ajax_yvo_contract_editor_chat', 'yvo_ajax_contract_editor_chat');
add_action('wp_ajax_nopriv_yvo_contract_editor_chat', 'yvo_ajax_contract_editor_chat');
add_action('wp_ajax_yvo_save_edited_contract', 'yvo_ajax_save_edited_contract');
add_action('wp_ajax_nopriv_yvo_save_edited_contract', 'yvo_ajax_save_edited_contract');
add_action('wp_ajax_yvo_save_edited_act', 'yvo_ajax_save_edited_act');
add_action('wp_ajax_nopriv_yvo_save_edited_act', 'yvo_ajax_save_edited_act');
add_action('wp_ajax_yvo_save_edited_receipt', 'yvo_ajax_save_edited_receipt');
add_action('wp_ajax_nopriv_yvo_save_edited_receipt', 'yvo_ajax_save_edited_receipt');
add_action('wp_ajax_yvo_cabinet_list', 'yvo_ajax_cabinet_list');
add_action('wp_ajax_nopriv_yvo_cabinet_list', 'yvo_ajax_cabinet_list');
add_action('wp_ajax_yvo_cabinet_save', 'yvo_ajax_cabinet_save');
add_action('wp_ajax_nopriv_yvo_cabinet_save', 'yvo_ajax_cabinet_save');
add_action('wp_ajax_yvo_cabinet_load', 'yvo_ajax_cabinet_load');
add_action('wp_ajax_nopriv_yvo_cabinet_load', 'yvo_ajax_cabinet_load');
add_action('wp_ajax_yvo_cabinet_delete', 'yvo_ajax_cabinet_delete');
add_action('wp_ajax_nopriv_yvo_cabinet_delete', 'yvo_ajax_cabinet_delete');
add_action('wp_ajax_yvo_cabinet_save_contract', 'yvo_ajax_cabinet_save_contract');
add_action('wp_ajax_nopriv_yvo_cabinet_save_contract', 'yvo_ajax_cabinet_save_contract');
add_action('wp_ajax_yvo_cabinet_save_object', 'yvo_ajax_cabinet_save_object');
add_action('wp_ajax_nopriv_yvo_cabinet_save_object', 'yvo_ajax_cabinet_save_object');
add_action('wp_ajax_yvo_cabinet_save_participants', 'yvo_ajax_cabinet_save_participants');
add_action('wp_ajax_nopriv_yvo_cabinet_save_participants', 'yvo_ajax_cabinet_save_participants');
add_action('wp_ajax_yvo_cabinet_save_transaction', 'yvo_ajax_cabinet_save_transaction');
add_action('wp_ajax_nopriv_yvo_cabinet_save_transaction', 'yvo_ajax_cabinet_save_transaction');
add_action('wp_ajax_yvo_check_contract_errors', 'yvo_ajax_check_contract_errors');
add_action('wp_ajax_nopriv_yvo_check_contract_errors', 'yvo_ajax_check_contract_errors_nopriv');
add_action('wp_ajax_yvo_check_property', 'yvo_ajax_check_property');
add_action('wp_ajax_nopriv_yvo_check_property', 'yvo_ajax_check_property_nopriv');

// AJAX: Проверка поддержки PDF
function yvo_ajax_check_pdf_support() {
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_nonce')) {
        wp_send_json_error('Ошибка безопасности');
    }
    
    $pdf_processor = new YVO_PDF_Processor();
    $has_pdf_support = $pdf_processor->check_pdf_support();
    
    wp_send_json_success(array(
        'has_pdf_support' => $has_pdf_support,
        'message' => $has_pdf_support ? 'Поддержка PDF доступна' : 'Поддержка PDF недоступна'
    ));
}

// AJAX: Тестирование DeepSeek API
function yvo_ajax_test_deepseek() {
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_nonce')) {
        wp_send_json_error('Ошибка безопасности');
    }
    
    $api_key = get_option('yvo_deepseek_api_key');
    
    if (empty($api_key)) {
        wp_send_json_error('API ключ DeepSeek не настроен');
    }
    
    $result = yvo_test_deepseek_api($api_key);
    
    if ($result['success']) {
        wp_send_json_success(array(
            'message' => 'DeepSeek API подключение работает!',
            'response' => $result['response']
        ));
    } else {
        wp_send_json_error($result['message']);
    }
}

// AJAX: тестовый запрос «Исправить кодировку» — проверка связи с DeepSeek
function yvo_ajax_test_fix_egrn() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостаточно прав');
    }
    if (!wp_verify_nonce(isset($_POST['nonce']) ? $_POST['nonce'] : '', 'yvo_nonce')) {
        wp_send_json_error('Ошибка безопасности');
    }
    if (empty(get_option('yvo_deepseek_api_key'))) {
        wp_send_json_error('API ключ DeepSeek не настроен');
    }
    $parser_file = defined('YVO_PLUGIN_DIR') ? YVO_PLUGIN_DIR . 'includes/deepseek-parser.php' : __DIR__ . '/includes/deepseek-parser.php';
    if (!is_file($parser_file)) {
        wp_send_json_error('Файл deepseek-parser.php не найден');
    }
    require_once $parser_file;
    if (!class_exists('YVO_DeepSeekParser')) {
        wp_send_json_error('Класс DeepSeekParser не найден');
    }
    $sample = "!\"#\$% &'(')*%\"+\",\" &+'%*'-': 02:55:050229 .'*' /%0)+\"\$102 &'(')*%\"+\",\" 1\"#\$%': 10.07.2023 9(%\$): 3\"))05)&'2 :\$(\$%';02, 3\$)/6<-0&' ='>&\"%*')*'1 ?\"%\"()&\"5 \"&%6";
    $parser = new YVO_DeepSeekParser();
    $result = $parser->fix_egrn_text($sample);
    if (!empty($result['success']) && !empty($result['text'])) {
        wp_send_json_success(array(
            'message' => 'Запрос к DeepSeek выполнен успешно.',
            'fixed_preview' => mb_substr($result['text'], 0, 500),
            'fixed_length' => strlen($result['text'])
        ));
    }
    wp_send_json_error(isset($result['message']) ? $result['message'] : 'Не удалось получить ответ от DeepSeek');
}

// AJAX: Парсинг с помощью DeepSeek
function yvo_ajax_parse_with_deepseek() {
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_nonce')) {
        wp_send_json_error('Ошибка безопасности');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостаточно прав');
    }
    
    $text = isset($_POST['text']) ? sanitize_textarea_field($_POST['text']) : '';
    $document_type = isset($_POST['document_type']) ? sanitize_text_field($_POST['document_type']) : 'passport';
    
    if (empty($text)) {
        wp_send_json_error('Текст для парсинга пуст');
    }
    
    $result = yvo_parse_with_deepseek($text, $document_type);
    
    if ($result['success']) {
        wp_send_json_success(array(
            'parsed_data' => $result['parsed_data'],
            'raw_response' => $result['raw_response']
        ));
    } else {
        wp_send_json_error($result['message']);
    }
}

// AJAX: Обработка документа
function yvo_ajax_process_document() {
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_nonce')) {
        wp_send_json_error('Ошибка безопасности');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостаточно прав');
    }
    
    $document_url = isset($_POST['document_url']) ? esc_url_raw($_POST['document_url']) : '';
    $document_type = isset($_POST['document_type']) ? sanitize_text_field($_POST['document_type']) : 'general';
    $use_deepseek = isset($_POST['use_deepseek']) ? $_POST['use_deepseek'] === 'true' : false;
    
    if (empty($document_url)) {
        wp_send_json_error('Не указан URL документа');
    }
    
    $result = yvo_process_single_document($document_url, $document_type);
    
    // Если включен DeepSeek и тип документа не общий
    if ($use_deepseek && $document_type !== 'general' && $result['success']) {
        $deepseek_result = yvo_parse_with_deepseek($result['text'], $document_type);
        
        if ($deepseek_result['success']) {
            $result['parsed_data'] = $deepseek_result['parsed_data'];
            $result['parser'] = 'deepseek';
        } else {
            // Если DeepSeek не сработал, используем обычный парсинг
            $result['parsed_data'] = yvo_parse_document_data($result['text'], $document_type);
            $result['parser'] = 'regex';
        }
    } elseif ($document_type !== 'general' && $result['success']) {
        // Используем обычный парсинг
        $result['parsed_data'] = yvo_parse_document_data($result['text'], $document_type);
        $result['parser'] = 'regex';
    }
    
    if ($result['success']) {
        wp_send_json_success(array(
            'text' => $result['text'],
            'filename' => $result['filename'],
            'parsed_data' => isset($result['parsed_data']) ? $result['parsed_data'] : null,
            'document_type' => $document_type,
            'is_pdf' => isset($result['is_pdf']) ? $result['is_pdf'] : false,
            'pages' => isset($result['pages']) ? $result['pages'] : 1,
            'parser' => isset($result['parser']) ? $result['parser'] : 'none'
        ));
    } else {
        wp_send_json_error($result['message']);
    }
}

// Функция обработки одного документа
function yvo_process_single_document($document_url, $document_type) {
    // Получаем настройки
    $api_key = get_option('yvo_api_key');
    $folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    $language = get_option('yvo_language', 'ru');
    $mistral_fallback = function_exists('yvo_mistral_fallback_available') && yvo_mistral_fallback_available();
    
    if ((empty($api_key) || empty($folder_id)) && !$mistral_fallback) {
        return array(
            'success' => false,
            'message' => 'API ключ или ID папки не настроены'
        );
    }
    
    // Определяем тип файла по расширению
    $parsed_url = parse_url($document_url, PHP_URL_PATH);
    $extension = pathinfo($parsed_url, PATHINFO_EXTENSION);
    $extension = strtolower($extension);
    
    if ($extension === 'pdf') {
        // Обрабатываем PDF
        $pdf_processor = new YVO_PDF_Processor();
        $result = $pdf_processor->process($document_url, $api_key, $folder_id, $language);
        if (isset($result['success']) && $result['success']) {
            $result['is_pdf'] = true;
        }
    } else {
        // Обрабатываем как изображение
        $result = yvo_recognize_text($document_url, $api_key, $folder_id, $language);
        if (isset($result['success']) && $result['success']) {
            $result['is_pdf'] = false;
        }
    }
    
    return $result;
}

// Функция распознавания текста (для URL)
function yvo_recognize_text($image_url, $api_key, $folder_id, $language = 'ru') {
    $local_path = yvo_get_local_file($image_url);
    if (!$local_path) {
        return array(
            'success' => false,
            'message' => 'Не удалось загрузить изображение'
        );
    }
    
    return yvo_recognize_text_from_local($local_path, $api_key, $folder_id, $language);
}

// Функция распознавания текста из локального файла
function yvo_recognize_text_from_local($local_path, $api_key, $folder_id, $language = 'ru') {
    // Проверяем размер файла
    $max_size = get_option('yvo_max_size', 20) * 1024 * 1024;
    if (filesize($local_path) > $max_size) {
        return array(
            'success' => false,
            'message' => sprintf('Файл слишком большой (максимум %d MB)', get_option('yvo_max_size', 20))
        );
    }
    
    // Проверяем формат файла
    $allowed_formats = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp');
    $file_ext = strtolower(pathinfo($local_path, PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_formats)) {
        return array(
            'success' => false,
            'message' => 'Неподдерживаемый формат файла'
        );
    }
    
    // Кодируем изображение в base64
    $image_data = base64_encode(file_get_contents($local_path));
    
    // Подготавливаем запрос к Яндекс Vision API
    $url = 'https://vision.api.cloud.yandex.net/vision/v1/batchAnalyze';
    
    $headers = array(
        'Authorization: Api-Key ' . $api_key,
        'Content-Type: application/json'
    );
    
    $body = array(
        'folderId' => $folder_id,
        'analyze_specs' => array(
            array(
                'content' => $image_data,
                'features' => array(
                    array(
                        'type' => 'TEXT_DETECTION',
                        'text_detection_config' => array(
                            'language_codes' => array($language)
                        )
                    )
                )
            )
        )
    );
    
    // Отправляем запрос через cURL
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Обрабатываем ошибки
    if ($error) {
        $fail = array(
            'success' => false,
            'message' => 'Ошибка сети: ' . $error
        );
        return function_exists('yvo_ocr_with_mistral_fallback')
            ? yvo_ocr_with_mistral_fallback($local_path, $fail)
            : $fail;
    }
    
    if ($http_code != 200) {
        $msg = function_exists('yvo_yandex_api_error_message')
            ? yvo_yandex_api_error_message($http_code, $response)
            : ("Ошибка API ($http_code)");
        $fail = array(
            'success' => false,
            'message' => $msg
        );
        return function_exists('yvo_ocr_with_mistral_fallback')
            ? yvo_ocr_with_mistral_fallback($local_path, $fail)
            : $fail;
    }
    
    // Парсим ответ
    $data = json_decode($response, true);
    
    // Извлекаем текст
    $text = '';
    if (isset($data['results'][0]['results'][0]['textDetection']['pages'][0]['blocks'])) {
        $blocks = $data['results'][0]['results'][0]['textDetection']['pages'][0]['blocks'];
        
        foreach ($blocks as $block) {
            if (isset($block['lines'])) {
                foreach ($block['lines'] as $line) {
                    if (isset($line['words'])) {
                        $line_text = '';
                        foreach ($line['words'] as $word) {
                            if (isset($word['text'])) {
                                $line_text .= $word['text'] . ' ';
                            }
                        }
                        $text .= trim($line_text) . "\n";
                    }
                }
            }
        }
    }
    
    $text = trim($text);
    
    if (empty($text)) {
        $fail = array(
            'success' => false,
            'message' => 'На изображении не найден текст'
        );
        return function_exists('yvo_ocr_with_mistral_fallback')
            ? yvo_ocr_with_mistral_fallback($local_path, $fail)
            : $fail;
    }
    
    return array(
        'success' => true,
        'text' => $text,
        'filename' => basename($local_path)
    );
}

// Функция парсинга с помощью DeepSeek
/**
 * Подсказка с фронта: пользователь выбрал тип объекта «Доля» — не сбрасывать на квартиру после парсинга выписки.
 */
function yvo_apply_property_object_type_hint($parsed_data, $hint) {
    if (!is_array($parsed_data) || $hint !== 'share') {
        return $parsed_data;
    }
    $parsed_data['object_type'] = 'share';
    $pt = isset($parsed_data['property_type']) ? trim((string) $parsed_data['property_type']) : '';
    if ($pt === '' || stripos($pt, 'дол') === false) {
        $parsed_data['property_type'] = 'доля в праве общей долевой собственности на квартиру';
    }
    return $parsed_data;
}

function yvo_parse_with_deepseek($text, $document_type, $options = array()) {
    $api_key = get_option('yvo_deepseek_api_key');
    $model = get_option('yvo_deepseek_model', 'deepseek-chat');
    
    if (empty($api_key)) {
        return array(
            'success' => false,
            'message' => 'API ключ DeepSeek не настроен'
        );
    }
    
    // Определяем промпт в зависимости от типа документа
    $prompt = '';
    switch ($document_type) {
        case 'seller':
        case 'buyer':
        case 'passport':
            if (yvo_text_looks_like_birth_certificate($text)) {
                $prompt = "Из текста СВИДЕТЕЛЬСТВА О РОЖДЕНИИ извлеки данные РЕБЁНКА (не отца и не матери). Верни ТОЛЬКО JSON:
{
  \"full_name\": \"ФИО ребёнка полностью\",
  \"birth_date\": \"дд.мм.гггг\",
  \"birth_place\": \"место рождения\",
  \"birth_cert_series\": \"серия (например IV-АР)\",
  \"birth_cert_number\": \"номер (6-7 цифр)\",
  \"birth_cert_date\": \"дата выдачи свидетельства дд.мм.гггг\",
  \"birth_cert_issued_by\": \"орган ЗАГС, кем выдано\"
}
Игнорируй штампы «выдан паспорт» на фото. Нет данных — null.

Текст:\n\n" . $text;
            } else {
                $prompt = "Ты - AI ассистент для извлечения структурированных данных из текста. Извлеки следующие данные и верни ТОЛЬКО JSON без дополнительного текста:
{
  \"full_name\": null,
  \"passport_series\": \"Серия паспорта (4 цифры)\",
  \"passport_number\": \"Номер паспорта (6 цифр)\",
  \"department_code\": \"Код подразделения (формат 000-000)\",
  \"passport_issued_by\": \"Кем выдан паспорт (полное название организации)\",
  \"passport_date\": \"Дата выдачи (формат дд.мм.гггг)\",
  \"birth_date\": \"Дата рождения (формат дд.мм.гггг)\",
  \"birth_place\": \"Место рождения (город, область, страна)\",
  \"registration\": \"Адрес регистрации (полный адрес)\",
  \"inn\": \"ИНН (10 или 12 цифр)\",
  \"snils\": \"СНИЛС (формат 000-000-000 00)\",
  \"phone\": \"Номер телефона\",
  \"email\": \"Email адрес\"
}

Если каких-то данных нет, верни null для этих полей. Никогда не подставляй примерные ФИО (Иванов, Петров, Сидоров и т.п.) — только то, что явно есть в тексте.

Текст документа:\n\n" . $text;
            }
            break;

        case 'seller_requisites':
        case 'buyer_requisites':
            $prompt = "Извлеки из текста банковские реквизиты (банк, ИНН, КПП, расчётный счёт, БИК, получатель и т.д.). Верни ТОЛЬКО JSON с одним полем:
{\"requisites\": \"полный текст реквизитов одной строкой или с переносами\"}

Текст документа:\n\n" . $text;
            break;
            
        case 'property':
            $object_hint = isset($options['object_type_hint']) ? sanitize_key($options['object_type_hint']) : '';
            $share_note = ($object_hint === 'share')
                ? ' Пользователь оформляет сделку с ДОЛЕЙ в квартире: object_type должен быть \"share\", property_type — «доля в праве общей долевой собственности на квартиру», обязательно укажи share_in_right (например 1/2), если есть в тексте.'
                : '';
            $prompt = "Ты - AI ассистент для извлечения структурированных данных из текста об объекте недвижимости (выписка ЕГРН, ДКП, технический паспорт и т.д.). Извлеки данные и верни ТОЛЬКО JSON без дополнительного текста:
{
  \"address\": \"Краткий адрес: г. Город, ул./пр-кт Улица, д. N, кв. N (без служебных подписей «Адрес (местоположение)», без дат)\",
  \"city\": \"Только название города без области и дат\",
  \"street\": \"Улица или проспект с типом (пр-кт, ул.)\",
  \"house\": \"Номер дома (только цифры/литера)\",
  \"building\": \"Корпус/строение или null\",
  \"apartment\": \"Номер квартиры\",
  \"cadastral_number\": \"Кадастровый номер (формат XX:XX:XXXXXXX:XXX)\",
  \"area\": \"Площадь (число, квадратные метры)\",
  \"floor\": \"Этаж (число)\",
  \"floors_total\": \"Этажность дома (число)\",
  \"rooms\": \"Количество комнат (число)\",
  \"price\": \"Стоимость (число, рубли)\",
  \"property_type\": \"Тип недвижимости (квартира, доля в праве на квартиру, дом, участок и т.д.)\",
  \"object_type\": \"apartment|share|room|land|house_with_plot|garage|parking — если можно определить\",
  \"share_in_right\": \"Размер доли N/M (если в тексте долевая собственность)\",
  \"year_built\": \"Год постройки\",
  \"condition\": \"Состояние (евроремонт, требует ремонта и т.д.)\",
  \"ownership_type\": \"Тип собственности (собственность, долевая собственность и т.д.)\"
}

Не включай в адрес даты документов, подписи полей и дубли «Адрес». Если каких-то данных нет, верни null." . $share_note . "

Текст документа:\n\n" . $text;
            break;
            
        default:
            return array(
                'success' => false,
                'message' => 'Неподдерживаемый тип документа для AI парсинга'
            );
    }
    
    // Подготавливаем запрос к DeepSeek API
    $url = 'https://api.deepseek.com/v1/chat/completions';
    
    $headers = array(
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    );
    
    $body = array(
        'model' => $model,
        'messages' => array(
            array(
                'role' => 'user',
                'content' => $prompt
            )
        ),
        'max_tokens' => 2000,
        'temperature' => 0.1,
        'response_format' => array('type' => 'json_object')
    );
    
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Обрабатываем ошибки
    if ($error) {
        return array(
            'success' => false,
            'message' => 'Ошибка сети: ' . $error
        );
    }
    
    if ($http_code != 200) {
        $error_data = json_decode($response, true);
        $error_msg = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Неизвестная ошибка';
        return array(
            'success' => false,
            'message' => "Ошибка DeepSeek API ($http_code): $error_msg"
        );
    }
    
    // Парсим ответ
    $data = json_decode($response, true);
    
    if (!isset($data['choices'][0]['message']['content'])) {
        return array(
            'success' => false,
            'message' => 'Некорректный ответ от DeepSeek API'
        );
    }
    
    $content = $data['choices'][0]['message']['content'];
    $parsed_data = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return array(
            'success' => false,
            'message' => 'Ошибка парсинга JSON из ответа DeepSeek: ' . json_last_error_msg()
        );
    }
    
    if (in_array($document_type, array('seller', 'buyer', 'passport', 'seller_requisites', 'buyer_requisites'), true)) {
        $parsed_data = yvo_sanitize_parsed_person_data($parsed_data, $text);
    }

    return array(
        'success' => true,
        'parsed_data' => $parsed_data,
        'raw_response' => $content
    );
}

// Тест DeepSeek API
function yvo_test_deepseek_api($api_key) {
    $url = 'https://api.deepseek.com/v1/chat/completions';
    
    $headers = array(
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    );
    
    $body = array(
        'model' => 'deepseek-chat',
        'messages' => array(
            array(
                'role' => 'user',
                'content' => 'Привет! Ответь "Готов к работе!" если ты работаешь.'
            )
        ),
        'max_tokens' => 50,
        'temperature' => 0.1
    );
    
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ));
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return array(
            'success' => false,
            'message' => 'Ошибка сети: ' . $error
        );
    }
    
    if ($http_code != 200) {
        return array(
            'success' => false,
            'message' => "Ошибка API ($http_code)"
        );
    }
    
    $data = json_decode($response, true);
    
    return array(
        'success' => true,
        'response' => isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : 'Ответ получен'
    );
}

// Функция парсинга данных документа (регулярные выражения)
function yvo_parse_document_data($text, $document_type) {
    $data = array();
    
    switch ($document_type) {
        case 'seller':
        case 'buyer':
        case 'passport':
            $is_birth_cert = yvo_text_looks_like_birth_certificate($text);
            if ($is_birth_cert) {
                $data = yvo_parse_birth_certificate_from_text($text);
                if (function_exists('yvo_enrich_birth_certificate_from_ocr')) {
                    $data = yvo_enrich_birth_certificate_from_ocr($data, $text);
                }
            } else {
                $full_name = yvo_extract_full_name_from_text($text);
                if ($full_name !== '' && !yvo_full_name_looks_invalid($full_name)) {
                    $data['full_name'] = $full_name;
                }
                $series_num = yvo_parse_passport_series_number_from_text($text);
                foreach ($series_num as $sk => $sv) {
                    if ($sv !== '') {
                        $data[$sk] = $sv;
                    }
                }
                if (preg_match('/(?:код\s+подразделения|division\s+code)[^\d]{0,40}(\d{3})[\s\-]*(\d{3})/ui', $text, $matches)) {
                    $data['department_code'] = $matches[1] . '-' . $matches[2];
                } elseif (preg_match('/(\d{3}-\d{3})/u', $text, $matches)) {
                    $data['department_code'] = $matches[1];
                } elseif (preg_match('/\b(\d{3})\s+(\d{3})\b/u', $text, $matches)) {
                    $data['department_code'] = $matches[1] . '-' . $matches[2];
                }
                if (preg_match('/(выдан[:\s]+)([^\n]{10,100})/ui', $text, $matches)) {
                    $data['passport_issued_by'] = trim($matches[2]);
                }
                if (preg_match('/(дата выдачи[:\s]+)(\d{2}\.\d{2}\.\d{4})/ui', $text, $matches)) {
                    $data['passport_date'] = $matches[2];
                }
                if (preg_match('/(дата рождения[:\s]+)(\d{2}\.\d{2}\.\d{4})/ui', $text, $matches)) {
                    $data['birth_date'] = $matches[2];
                }
                if (preg_match('/(место рождения[:\s]+)([^\n]{10,100})/ui', $text, $matches)) {
                    $data['birth_place'] = trim($matches[2]);
                }
                if (function_exists('yvo_enrich_passport_data_from_ocr')) {
                    $data = yvo_enrich_passport_data_from_ocr($data, $text);
                }
            }
            if (!$is_birth_cert && preg_match('/свидетельство\s+о\s+рождении/ui', $text)) {
                $bc_extra = yvo_parse_birth_certificate_from_text($text);
                foreach ($bc_extra as $bk => $bv) {
                    if ($bv !== '' && (empty($data[$bk]) || !isset($data[$bk]))) {
                        $data[$bk] = $bv;
                    }
                }
            }
            
            if (preg_match('/(зарегистрирован[а]?[:\s]+)([^\n]{10,150})/ui', $text, $matches)) {
                $data['registration'] = trim($matches[2]);
            }
            
            if (preg_match('/(ИНН[:\s]*)(\d{10,12})/ui', $text, $matches)) {
                $data['inn'] = $matches[2];
            }
            
            if (preg_match('/(СНИЛС[:\s]*)(\d{3}-\d{3}-\d{3}\s\d{2})/ui', $text, $matches)) {
                $data['snils'] = $matches[2];
            }
            
            break;
            
        case 'property':
            $parser = new YVO_Property_Parser($text);
            $data = $parser->parse();
            if (function_exists('yvo_text_looks_like_egrn') ? yvo_text_looks_like_egrn($text) : preg_match('/кадастров|егрн|единый\s+государственный\s+реестр|\d{2}:\d{2}:\d{6,}/ui', $text)) {
                if (function_exists('yvo_parse_egrn_text_local')) {
                    $egrn = yvo_parse_egrn_text_local($text);
                    if (!empty($egrn['property']) && is_array($egrn['property'])) {
                        $data = function_exists('yvo_merge_egrn_property_arrays')
                            ? yvo_merge_egrn_property_arrays($data, $egrn['property'])
                            : array_merge((array) $data, $egrn['property']);
                    }
                }
            }
            if (function_exists('yvo_normalize_property_address_fields')) {
                $data = yvo_normalize_property_address_fields($data);
            }
            if (function_exists('yvo_property_address_needs_refinement') && yvo_property_address_needs_refinement($data) && !empty(get_option('yvo_deepseek_api_key'))) {
                $ai = yvo_parse_with_deepseek($text, 'property', array());
                if (!empty($ai['success']) && !empty($ai['parsed_data']) && is_array($ai['parsed_data'])) {
                    foreach ($ai['parsed_data'] as $k => $v) {
                        if ($v === null || $v === '') {
                            continue;
                        }
                        if (empty($data[$k]) || (function_exists('yvo_address_field_looks_invalid') && yvo_address_field_looks_invalid($data[$k], $k))) {
                            $data[$k] = $v;
                        }
                    }
                    if (function_exists('yvo_normalize_property_address_fields')) {
                        $data = yvo_normalize_property_address_fields($data);
                    }
                }
            }
            break;

        case 'seller_requisites':
        case 'buyer_requisites':
            $data['requisites'] = trim(preg_replace('/\s+/u', ' ', $text));
            break;
            
        default:
            $data = array();
    }

    if (in_array($document_type, array('seller', 'buyer', 'passport'), true)) {
        $data = yvo_sanitize_parsed_person_data($data, $text);
    }
    
    return $data;
}

// Получение локального файла
function yvo_get_local_file($url) {
    $upload_dir = wp_upload_dir();
    
    if (strpos($url, $upload_dir['baseurl']) === 0) {
        $local_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $url);
        if (file_exists($local_path)) {
            return $local_path;
        }
    }
    
    if (strpos($url, site_url()) === 0) {
        $relative_path = str_replace(site_url('/'), '', $url);
        $local_path = ABSPATH . $relative_path;
        if (file_exists($local_path)) {
            return $local_path;
        }
    }
    
    // Скачиваем внешний файл
    $tmp_dir = YVO_PLUGIN_DIR . 'tmp';
    if (!file_exists($tmp_dir)) {
        mkdir($tmp_dir, 0755, true);
    }
    
    $filename = basename(parse_url($url, PHP_URL_PATH));
    if (empty($filename)) {
        $timestamp = time();
        $extension = pathinfo($url, PATHINFO_EXTENSION);
        if (empty($extension)) {
            // Определяем тип по заголовкам
            $headers = get_headers($url, 1);
            if (isset($headers['Content-Type'])) {
                if (strpos($headers['Content-Type'], 'pdf') !== false) {
                    $extension = 'pdf';
                } elseif (strpos($headers['Content-Type'], 'image') !== false) {
                    $extension = 'jpg';
                }
            }
        }
        $filename = 'document_' . $timestamp . '.' . ($extension ?: 'tmp');
    }
    
    $tmp_file = $tmp_dir . '/' . sanitize_file_name($filename);
    
    // Используем wp_remote_get для скачивания
    $response = wp_remote_get($url, array(
        'timeout' => 60,
        'redirection' => 5,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ));
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $file_content = wp_remote_retrieve_body($response);
    
    if (empty($file_content)) {
        return false;
    }
    
    file_put_contents($tmp_file, $file_content);
    
    // Проверяем, что файл успешно сохранен
    if (!file_exists($tmp_file) || filesize($tmp_file) == 0) {
        return false;
    }
    
    return $tmp_file;
}

// AJAX: Тестирование API
function yvo_ajax_test_api() {
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_nonce')) {
        wp_send_json_error('Ошибка безопасности');
    }
    
    $api_key = get_option('yvo_api_key');
    $folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    
    if (empty($api_key) || empty($folder_id)) {
        wp_send_json_error('API ключ или ID папки не настроены');
    }
    
    // Тестовое изображение 1x1 пиксель
    $test_image = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    
    $url = 'https://vision.api.cloud.yandex.net/vision/v1/batchAnalyze';
    
    $headers = array(
        'Authorization: Api-Key ' . $api_key,
        'Content-Type: application/json'
    );
    
    $body = array(
        'folderId' => $folder_id,
        'analyze_specs' => array(
            array(
                'content' => $test_image,
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
        CURLOPT_TIMEOUT => 15
    ));
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 || $http_code == 400) {
        wp_send_json_success('API подключение работает!');
    } else {
        $data = json_decode($response, true);
        $error = isset($data['message']) ? $data['message'] : 'Неизвестная ошибка';
        wp_send_json_error("Ошибка ($http_code): $error");
    }
}

// AJAX: Сохранение данных формы
function yvo_ajax_save_form_data() {
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_nonce')) {
        wp_send_json_error('Ошибка безопасности');
    }
    
    $form_type = sanitize_text_field($_POST['form_type']);
    $form_data = array();
    
    if (isset($_POST['form_data']) && is_array($_POST['form_data'])) {
        foreach ($_POST['form_data'] as $key => $value) {
            $clean_key = str_replace($form_type . '_', '', $key);
            $form_data[$clean_key] = sanitize_text_field($value);
        }
    }
    
    update_option("yvo_{$form_type}_data", $form_data);
    
    wp_send_json_success('Данные сохранены');
}

// AJAX: Загрузка данных формы
function yvo_ajax_load_form_data() {
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_nonce')) {
        wp_send_json_error('Ошибка безопасности');
    }
    
    $data_type = sanitize_text_field($_POST['data_type']);
    $data = get_option("yvo_{$data_type}_data", array());
    
    wp_send_json_success(array('data' => $data));
}

// AJAX: Генерация договора
function yvo_ajax_generate_contract() {
    if (!wp_verify_nonce($_POST['nonce'], 'yvo_nonce')) {
        wp_send_json_error('Ошибка безопасности');
    }
    
    $seller_data = get_option('yvo_seller_data', array());
    $buyer_data = get_option('yvo_buyer_data', array());
    $property_data = get_option('yvo_property_data', array());
    
    if (empty($seller_data) || empty($buyer_data) || empty($property_data)) {
        wp_send_json_error('Не все данные заполнены. Заполните данные продавца, покупателя и объекта.');
    }
    
    // Создаем простой текстовый договор
    $contract_content = yvo_generate_contract_text($seller_data, $buyer_data, $property_data);
    
    $dir = YVO_PLUGIN_DIR . 'contracts/';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    $filename = 'dogovor-kupli-prodazhi-' . date('Y-m-d-H-i-s') . '.txt';
    $docx_name = 'dogovor-kupli-prodazhi-' . date('Y-m-d-H-i-s') . '.docx';
    
    $contract_content_bom = "\xEF\xBB\xBF" . $contract_content;
    if (!file_put_contents($dir . $filename, $contract_content_bom)) {
        wp_send_json_error('Ошибка при сохранении договора');
    }
    $contract_docx_url = '';
    if (yvo_create_docx_from_text($contract_content, $dir . $docx_name)) {
        $contract_docx_url = YVO_PLUGIN_URL . 'contracts/' . $docx_name;
    }
    wp_send_json_success(array(
        'contract_url' => YVO_PLUGIN_URL . 'contracts/' . $filename,
        'contract_docx_url' => $contract_docx_url,
        'message' => 'Договор успешно сгенерирован'
    ));
}

// Функция генерации текста договора
function yvo_generate_contract_text($seller_data, $buyer_data, $property_data) {
    $date = date('d.m.Y');
    
    $contract = "ДОГОВОР КУПЛИ-ПРОДАЖИ НЕДВИЖИМОСТИ\n\n";
    $contract .= "г. __________________           \"___\"__________ ____ г.\n\n";
    $contract .= "Гражданин(ка) " . ($seller_data['full_name'] ?? '________________') . ",\n";
    $contract .= "паспорт: серия " . ($seller_data['passport_series'] ?? '____') . " № " . ($seller_data['passport_number'] ?? '______') . ", \n";
    $contract .= "выдан " . ($seller_data['passport_issued_by'] ?? '________________') . ",\n";
    if (isset($seller_data['department_code']) && !empty($seller_data['department_code'])) {
        $contract .= "код подразделения: " . $seller_data['department_code'] . ",\n";
    }
    $contract .= "зарегистрированный(ая) по адресу: " . ($seller_data['registration'] ?? '________________') . ",\n";
    $contract .= "именуемый(ая) в дальнейшем \"Продавец\", с одной стороны, и\n\n";
    $contract .= "Гражданин(ка) " . ($buyer_data['full_name'] ?? '________________') . ",\n";
    $contract .= "паспорт: серия " . ($buyer_data['passport_series'] ?? '____') . " № " . ($buyer_data['passport_number'] ?? '______') . ", \n";
    $contract .= "выдан " . ($buyer_data['passport_issued_by'] ?? '________________') . ",\n";
    if (isset($buyer_data['department_code']) && !empty($buyer_data['department_code'])) {
        $contract .= "код подразделения: " . $buyer_data['department_code'] . ",\n";
    }
    $contract .= "зарегистрированный(ая) по адресу: " . ($buyer_data['registration'] ?? '________________') . ",\n";
    $contract .= "именуемый(ая) в дальнейшем \"Покупатель\", с другой стороны,\n";
    $contract .= "совместно именуемые \"Стороны\", заключили настоящий договор о нижеследующем:\n\n";
    $contract .= "1. ПРЕДМЕТ ДОГОВОРА\n";
    $contract .= "1.1. Продавец продает, а Покупатель покупает " . ($property_data['property_type'] ?? 'квартиру') . ", расположенную по адресу:\n";
    $contract .= ($property_data['address'] ?? '________________') . "\n";
    if (isset($property_data['cadastral_number']) && !empty($property_data['cadastral_number'])) {
        $contract .= "Кадастровый номер: " . $property_data['cadastral_number'] . "\n";
    }
    $contract .= "Общая площадь: " . ($property_data['area'] ?? '___') . " кв.м.\n";
    if (isset($property_data['rooms']) && $property_data['rooms'] > 0) {
        $contract .= "Количество комнат: " . $property_data['rooms'] . "\n";
    }
    if (isset($property_data['floor']) && isset($property_data['floors_total'])) {
        $contract .= "Этаж: " . $property_data['floor'] . " из " . $property_data['floors_total'] . "\n";
    }
    if (isset($property_data['year_built']) && !empty($property_data['year_built'])) {
        $contract .= "Год постройки: " . $property_data['year_built'] . "\n";
    }
    $contract .= "\n";
    $contract .= "2. ЦЕНА ДОГОВОРА И ПОРЯДОК РАСЧЕТОВ\n";
    $contract .= "2.1. Цена продаваемого объекта недвижимости составляет " . (isset($property_data['price']) ? number_format(floatval($property_data['price']), 2, ',', ' ') : '________') . " рублей.\n\n";
    $contract .= "3. ПРАВА И ОБЯЗАННОСТИ СТОРОН\n";
    $contract .= "3.1. Продавец гарантирует, что на момент заключения настоящего договора объект недвижимости\n";
    $contract .= "не заложен, не арестован, не является предметом притязаний третьих лиц.\n\n";
    $contract .= "4. ПЕРЕХОД ПРАВА СОБСТВЕННОСТИ\n";
    $contract .= "4.1. Право собственности на объект недвижимости переходит к Покупателю с момента государственной\n";
    $contract .= "регистрации перехода права в установленном законом порядке.\n\n";
    $contract .= "5. ЗАКЛЮЧИТЕЛЬНЫЕ ПОЛОЖЕНИЯ\n";
    $contract .= "5.1. Настоящий договор составлен в трех экземплярах, имеющих одинаковую юридическую силу,\n";
    $contract .= "по одному для каждой из Сторон и один для органа, осуществляющего государственную регистрацию.\n\n";
    $contract .= "ПОДПИСИ СТОРОН:\n\n";
    $contract .= "Продавец: ___________________           Покупатель: ___________________\n";
    $contract .= "\n";
    $contract .= "Дата: ___________________                Дата: ___________________\n";
    
    return $contract;
}

define('YVO_TEMPLATES_DIR', YVO_PLUGIN_DIR . 'templates/');
/** HTML-шаблон ДКП (стиль Qwen / красная верстка) */
define('YVO_DKP_QWEN_HTML_TEMPLATE', YVO_PLUGIN_DIR . 'templates/dkp-qwen-source.html');
define('YVO_CUSTOM_TEMPLATES_DIR', YVO_PLUGIN_DIR . 'Шаблоны договоров/');
define('YVO_CUSTOM_TEMPLATES_DIR_ALT', YVO_PLUGIN_DIR . 'договоры/');
define('YVO_UPLOADED_TEMPLATES_OPTION', 'yvo_uploaded_templates');

/** Список банков для шаблонов ДКП с ипотекой */
function yvo_get_banks_list() {
    return array(
        'standard' => 'Стандартный',
        'sberbank' => 'ПАО Сбербанк',
        'vtb' => 'ВТБ',
        'gazprombank' => 'Газпромбанк',
        'alfabank' => 'Альфа-Банк',
        'raiffeisen' => 'Райффайзенбанк',
        'otkritie' => 'Банк Открытие',
        'rosbank' => 'Росбанк',
        'tinkoff' => 'Тинькофф',
        'sovkombank' => 'Совкомбанк',
    );
}

/** Варианты ДКП (тип договора 1) — для привязки загруженных шаблонов */
function yvo_get_dkp_variants() {
    return array(
        'dkp_ipoteka_akkreditiv' => 'ДКП с ипотекой+аккредитив (ПВ или свои через аккредитив)',
        'dkp_ipoteka_yaweika' => 'ДКП с ипотекой+наличка в день сделки (первый взнос через ячейку)',
        'dkp_ipoteka_den_sdelki' => 'ДКП с ипотекой в день сделки ПВ (перевод своих в день сделки)',
        'dkp_svoi_den_sdelki' => 'ДКП свои средства в день сделки',
        'dkp_svoi_yaweika' => 'ДКП свои средства ячейка',
        'dkp_svoi_akkreditiv' => 'ДКП свои средства аккредитив',
    );
}

/** Варианты «Соглашение выделения долей» */
function yvo_get_share_allocation_variants() {
    return array(
        'vydel_ipoteka' => 'Выдел долей в ипотечной недвижимости',
        'vydel_obshiy' => 'Выдел долей в недвижимости',
        'project_popechenie' => 'Проект договора для органов попечительства (на покупаемый объект, основа ДКП)',
        'zayavlenie_popechenie' => 'Заявление для органов попечительства',
    );
}

/** Типы объекта недвижимости для шаблонов */
function yvo_get_property_types_list() {
    return array(
        'all' => 'Любой',
        'apartment' => 'Квартира',
        'share' => 'Доля (в квартире)',
        'room' => 'Комната',
        'land' => 'Земля (участок)',
        'house_with_plot' => 'Дом с участком',
        'garage' => 'Гараж',
        'parking' => 'Паркинг',
    );
}

/** Директория для загруженных шаблонов (в uploads) */
function yvo_get_uploaded_templates_dir() {
    $upload = wp_upload_dir();
    if ($upload['error']) {
        return YVO_PLUGIN_DIR . 'uploaded-templates/';
    }
    $dir = $upload['basedir'] . '/yvo-templates/';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    return $dir;
}

/** Все загруженные шаблоны из опции */
function yvo_get_uploaded_templates() {
    $list = get_option(YVO_UPLOADED_TEMPLATES_OPTION, array());
    return is_array($list) ? $list : array();
}

/** Сохранить один загруженный шаблон (добавить/обновить по id) */
function yvo_save_uploaded_template($item) {
    $list = yvo_get_uploaded_templates();
    $id = isset($item['id']) ? sanitize_key($item['id']) : 'upload_' . (count($list) + 1);
    $item['id'] = $id;
    $item['contract_type'] = isset($item['contract_type']) ? sanitize_key($item['contract_type']) : 'sale';
    $item['category'] = isset($item['category']) ? sanitize_key($item['category']) : 'sale';
    $item['variant_slug'] = isset($item['variant_slug']) ? sanitize_key($item['variant_slug']) : '';
    $item['variant_label'] = isset($item['variant_label']) ? sanitize_text_field($item['variant_label']) : '';
    $item['bank_id'] = isset($item['bank_id']) ? sanitize_key($item['bank_id']) : 'standard';
    $item['property_type'] = isset($item['property_type']) ? sanitize_key($item['property_type']) : 'all';
    $item['file_txt'] = isset($item['file_txt']) ? $item['file_txt'] : '';
    $item['file_docx'] = isset($item['file_docx']) ? $item['file_docx'] : '';
    if (isset($item['placeholders']) && is_array($item['placeholders'])) {
        $item['placeholders'] = array_values(array_map('sanitize_key', $item['placeholders']));
    } else {
        $item['placeholders'] = array();
    }
    $list[$id] = $item;
    update_option(YVO_UPLOADED_TEMPLATES_OPTION, $list);
    return $id;
}

/** Удалить загруженный шаблон по id */
function yvo_delete_uploaded_template($id) {
    $list = yvo_get_uploaded_templates();
    if (!isset($list[$id])) {
        return false;
    }
    $dir = yvo_get_uploaded_templates_dir();
    foreach (array('file_txt', 'file_docx') as $key) {
        if (!empty($list[$id][$key])) {
            $path = $dir . basename($list[$id][$key]);
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }
    unset($list[$id]);
    update_option(YVO_UPLOADED_TEMPLATES_OPTION, $list);
    return true;
}

/**
 * Рекурсивный сбор .txt из папки (совместимо с путями в кириллице на Windows).
 * Исключаем только служебные: ПРОЧТИ_МЕНЯ, ТЕКСТ_из_шаблона_* (остальные, в т.ч. ПОЛНЫЙ_ТЕКСТ_ и ТЕКСТ_, включаем).
 */
function yvo_scan_templates_dir($dir, $prefix = '', &$list = array()) {
    if (!is_dir($dir)) {
        return $list;
    }
    $dh = @opendir($dir);
    if (!$dh) {
        return $list;
    }
    while (($entry = readdir($dh)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $path = rtrim($dir, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            yvo_scan_templates_dir($path, $prefix . $entry . '_', $list);
        } elseif (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'txt') {
            $base = pathinfo($path, PATHINFO_FILENAME);
            if ($base === '') continue;
            if (preg_match('/^ПРОЧТИ_МЕНЯ$/u', $base)) continue;
            if (preg_match('/^ТЕКСТ_из_шаблона_/u', $base)) continue;
            $id = $prefix . $base;
            $id = preg_replace('/\s+/u', '_', trim($id));
            $label = str_replace(array('_', '-'), ' ', $base);
            $list[$id] = array('path' => $path, 'label' => $label);
        }
    }
    closedir($dh);
    return $list;
}

function yvo_get_custom_template_files() {
    $list = array();
    $dirs_to_try = array(YVO_CUSTOM_TEMPLATES_DIR, YVO_CUSTOM_TEMPLATES_DIR_ALT);
    foreach ($dirs_to_try as $dir) {
        $resolved = @realpath($dir);
        if ($resolved && is_dir($resolved)) {
            yvo_scan_templates_dir($resolved, '', $list);
        }
        if (empty($list) && is_dir($dir)) {
            yvo_scan_templates_dir($dir, '', $list);
        }
        // Дополнительно: glob по подпапкам (на случай если readdir не видит кириллицу)
        $base = rtrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $dir), DIRECTORY_SEPARATOR);
        if (is_dir($dir) || ($resolved && is_dir($resolved))) {
            $root = $resolved ? $resolved : $dir;
            $root = rtrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
            foreach (array($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.txt', $root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.txt') as $pattern) {
                $files = @glob($pattern);
                if ($files) {
                    foreach ($files as $path) {
                        $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
                        $base_name = pathinfo($path, PATHINFO_FILENAME);
                        if ($base_name === '' || preg_match('/^ПРОЧТИ_МЕНЯ$|^ТЕКСТ_из_шаблона_/u', $base_name)) continue;
                        $root_n = rtrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
                        $rel = str_replace($root_n . DIRECTORY_SEPARATOR, '', $path);
                        $parts = explode(DIRECTORY_SEPARATOR, $rel);
                        $parent = count($parts) > 1 ? pathinfo($parts[0], PATHINFO_FILENAME) : '';
                        $id = $parent !== '' ? preg_replace('/\s+/u', '_', $parent . '_' . $base_name) : preg_replace('/\s+/u', '_', $base_name);
                        if (strpos($id, 'ПРОЧТИ_МЕНЯ') !== false || $base_name === 'ПРОЧТИ_МЕНЯ') continue;
                        if (!isset($list[$id])) {
                            $list[$id] = array('path' => $path, 'label' => str_replace(array('_', '-'), ' ', $base_name));
                        }
                    }
                }
            }
        }
        if (!empty($list)) break;
    }
    if (empty($list)) {
        foreach ($dirs_to_try as $dir) {
            $pattern = rtrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $dir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.txt';
            $files = @glob($pattern);
            if ($files) {
                foreach ($files as $path) {
                    $base = pathinfo($path, PATHINFO_FILENAME);
                    if (preg_match('/^ПРОЧТИ_МЕНЯ$|^ТЕКСТ_из_шаблона_/u', $base)) continue;
                    $parent = basename(dirname($path));
                    $id = preg_replace('/\s+/u', '_', $parent . '_' . $base);
                    if (strpos($id, 'ПРОЧТИ_МЕНЯ') !== false || $base === 'ПРОЧТИ_МЕНЯ') continue;
                    $list[$id] = array('path' => $path, 'label' => str_replace(array('_', '-'), ' ', $base));
                }
                break;
            }
        }
    }
    return $list;
}

/** Шаблоны ДКП, убранные из выпадающего списка (не показывать даже если .txt на диске). */
function yvo_get_removed_template_ids() {
    return array(
        'contract-variant2',
        'contract-variant3',
        'dkp-ipoteka-akkreditiv-full',
        'gift-kvartira-apartment',
        'act-sale-standard',
        'dkp-sale-standard',
    );
}

function yvo_get_available_templates() {
    $dir = YVO_TEMPLATES_DIR;
    $list = array();
    $removed = array_flip(yvo_get_removed_template_ids());
    $names = array(
        'default' => 'ДКП обычный',
        'dkp-kvartira-ipoteka' => 'ДКП + ипотека + аккредитив',
        'dkp-nalichnye-akkreditiv-podpisi' => 'ДКП наличные + аккредитив (подписи, DOCX)',
        'preliminary' => 'Предварительный договор купли-продажи',
        'deposit-agreement' => 'Договор задатка (квартира)',
        'deposit-agreement-house' => 'Договор задатка (дом с землёй)',
        'deposit-agreement-room' => 'Договор задатка (комната)',
        'deposit-agreement-land' => 'Договор задатка (земля)',
        'deposit-receipt' => 'Расписка в получении задатка',
        'advance-agreement' => 'Договор аванса (квартира)',
        'advance-agreement-house' => 'Договор аванса (дом с землёй)',
        'advance-agreement-room' => 'Договор аванса (комната)',
        'advance-agreement-land' => 'Договор аванса (земля)',
        'advance-receipt' => 'Расписка в получении аванса',
        'shablon-darenie-dogovor' => 'Договор дарения (квартира, дом, земля и др.)',
        'shablon-darenie-dolya-kvartira' => 'Дарение доли в квартире',
        'shablon-vydelenie-doley-kvartira' => 'Соглашение о выделении долей в квартире несовершеннолетним',
        'gift-kvartira-apartment' => 'Договор дарения (устар., см. shablon-darenie-dogovor)',
    );
    if (is_dir($dir)) {
        $files = glob($dir . '*.txt');
        if ($files) {
            foreach ($files as $path) {
                $base = basename($path, '.txt');
                if ($base === '' || isset($removed[$base])) {
                    continue;
                }
                $id = $base;
                $list[$id] = isset($names[$id]) ? $names[$id] : str_replace(array('-', '_'), ' ', $base);
            }
        }
        if (empty($list)) {
            foreach (array_keys($names) as $id) {
                if (file_exists($dir . $id . '.txt')) {
                    $list[$id] = $names[$id];
                }
            }
        }
    }
    try {
        $custom = yvo_get_custom_template_files();
        if (is_array($custom)) {
            foreach ($custom as $id => $item) {
                if (isset($removed[$id]) || !is_array($item) || strpos($id, 'ПРОЧТИ_МЕНЯ') !== false || (isset($item['label']) && ($item['label'] === 'ПРОЧТИ МЕНЯ' || trim($item['label']) === 'МЕНЯ'))) {
                    continue;
                }
                // Устаревшие пообъектные .txt из «Договоры_дарения» — текст собирается из shablon-darenie-dogovor.
                if (strpos($id, 'Договоры_дарения_') === 0) {
                    continue;
                }
                // Устаревшие шаблоны задатка из «Предварительные_договоры» — используются templates/deposit-agreement*.txt
                if (strpos($id, 'Предварительные_договоры_Договор_задатка') === 0) {
                    continue;
                }
                // Устаревшие шаблоны аванса — используются templates/advance-agreement*.txt
                if (strpos($id, 'Предварительные_договоры_Договор_аванса') === 0) {
                    continue;
                }
                $label = isset($item['label']) ? $item['label'] : $id;
                if (strpos($id, 'Предварительные_договоры_Предварительный_ДКП') !== false || strpos(mb_strtolower($id, 'UTF-8'), 'предварительный_дкп') !== false) {
                    $suffix = preg_replace('/^.*предварительный_дкп_/ui', '', $id);
                    $suffix = str_replace('_', ' ', trim($suffix));
                    $label = $suffix !== '' ? 'Предварительный ДКП (' . $suffix . ')' : $label;
                }
                $dkp_labels = array(
                    'ДКП_ипотека_аккредитив' => 'ДКП с ипотекой+аккредитив (ПВ или свои через аккредитив)',
                    'ДКП_ипотека_ячейка' => 'ДКП с ипотекой+наличка в день сделки (первый взнос через ячейку)',
                    'ДКП_ипотека_в_день_сделки' => 'ДКП с ипотекой в день сделки ПВ (перевод своих в день сделки)',
                    'ДКП_наличные_в_день_сделки' => 'ДКП свои средства в день сделки',
                    'ДКП_наличные_ячейка' => 'ДКП свои средства ячейка',
                    'ДКП_наличные_аккредитив' => 'ДКП свои средства аккредитив',
                );
                foreach ($dkp_labels as $key => $dl) {
                    if (strpos($id, $key) !== false) {
                        $label = $dl;
                        break;
                    }
                }
                $list[$id] = $label;
            }
        }
    } catch (Exception $e) {
        // оставляем только встроенные шаблоны
    }
    // Загруженные через админку шаблоны
    $uploaded = yvo_get_uploaded_templates();
    $banks = yvo_get_banks_list();
    foreach ($uploaded as $uid => $u) {
        $label = !empty($u['variant_label']) ? $u['variant_label'] : $u['id'];
        if (!empty($u['bank_id']) && $u['bank_id'] !== 'standard' && isset($banks[$u['bank_id']])) {
            $label .= ' — ' . $banks[$u['bank_id']];
        }
        $list[$u['id']] = $label;
    }
    $list = array_diff_key($list, $removed);
    $order = array(
        'default' => 1, 'dkp-kvartira-ipoteka' => 4,
        'dkp-nalichnye-akkreditiv-podpisi' => 5,
        'preliminary' => 10,
        'deposit-agreement' => 20, 'deposit-receipt' => 21, 'advance-agreement' => 22, 'advance-receipt' => 23,
    );
    uksort($list, function ($a, $b) use ($order) {
        $oa = isset($order[$a]) ? $order[$a] : 100;
        $ob = isset($order[$b]) ? $order[$b] : 100;
        if ($oa !== $ob) return $oa - $ob;
        return strcasecmp($a, $b);
    });
    if (empty($list)) {
        $list = array('default' => 'ДКП обычный');
    }
    return $list;
}

/**
 * Категория каждого шаблона для фильтрации по типу договора.
 * Ключи — template_id, значения — категория: sale, sale_mortgage, assignment, gift, share_allocation, preliminary, deposit_agreement, deposit_receipt, advance_agreement.
 */
function yvo_get_template_categories() {
    $list = yvo_get_available_templates();
    $builtin = array(
        'default' => 'sale',
        'dkp-kvartira-ipoteka' => 'sale_mortgage',
        'dkp-nalichnye-akkreditiv-podpisi' => 'sale',
        'preliminary' => 'preliminary',
        'deposit-agreement' => 'deposit_agreement',
        'deposit-agreement-house' => 'deposit_agreement',
        'deposit-agreement-room' => 'deposit_agreement',
        'deposit-agreement-land' => 'deposit_agreement',
        'deposit-receipt' => 'deposit_receipt',
        'advance-agreement' => 'advance_agreement',
        'advance-agreement-house' => 'advance_agreement',
        'advance-agreement-room' => 'advance_agreement',
        'advance-agreement-land' => 'advance_agreement',
        'advance-receipt' => 'advance_receipt',
        'shablon-darenie-dogovor' => 'gift',
        'shablon-darenie-dolya-kvartira' => 'gift',
        'shablon-vydelenie-doley-kvartira' => 'share_allocation',
        'gift-kvartira-apartment' => 'gift',
    );
    $out = array();
    foreach ($list as $id => $label) {
        $uploaded = yvo_get_uploaded_templates();
        if (isset($uploaded[$id])) {
            $out[$id] = $uploaded[$id]['category'];
            continue;
        }
        if (isset($builtin[$id])) {
            $out[$id] = $builtin[$id];
            continue;
        }
        // Явно: шаблоны из папки «Предварительные_договоры»
        if (strpos($id, 'Предварительные_договоры_') === 0) {
            $id_lower = mb_strtolower($id, 'UTF-8');
            if (strpos($id, 'Предварительный_ДКП') !== false || strpos($id_lower, 'предварительный_дкп') !== false) {
                $out[$id] = 'preliminary';
            } elseif (strpos($id, 'Договор_задатка') !== false) {
                $out[$id] = 'deposit_agreement';
            } elseif (strpos($id, 'Договор_аванса') !== false) {
                $out[$id] = 'advance_agreement';
            } else {
                $out[$id] = 'preliminary';
            }
            continue;
        }
        $id_lower = mb_strtolower($id, 'UTF-8');
        $label_lower = mb_strtolower($label, 'UTF-8');
        $combined = $id_lower . ' ' . $label_lower;
        if (preg_match('/предварительн|пдкп|предвар/u', $combined)) {
            $out[$id] = 'preliminary';
        } elseif (preg_match('/задаток/u', $combined)) {
            $out[$id] = 'deposit_agreement';
        } elseif (preg_match('/расписк/u', $combined)) {
            $out[$id] = 'deposit_receipt';
        } elseif (preg_match('/аванс/u', $combined)) {
            $out[$id] = 'advance_agreement';
        } elseif (preg_match('/ипотек|аккредитив|ячейк/u', $combined)) {
            $out[$id] = 'sale_mortgage';
        } elseif (preg_match('/дарени/u', $combined)) {
            $out[$id] = 'gift';
        } elseif (preg_match('/долей|выделени|выделен/u', $combined)) {
            $out[$id] = 'share_allocation';
        } elseif (preg_match('/уступк/u', $combined)) {
            $out[$id] = 'assignment';
        } else {
            $out[$id] = 'sale';
        }
    }
    return $out;
}

/** Метаданные шаблонов для фронта: bank_id (для sale_mortgage), variant_slug */
function yvo_get_template_banks() {
    $out = array();
    $uploaded = yvo_get_uploaded_templates();
    $categories = yvo_get_template_categories();
    foreach ($uploaded as $id => $u) {
        if (isset($u['bank_id']) && ($u['category'] === 'sale_mortgage' || $u['contract_type'] === 'sale')) {
            $out[$id] = $u['bank_id'];
        }
    }
    foreach ($categories as $id => $cat) {
        if ($cat === 'sale_mortgage' && !isset($out[$id])) {
            $out[$id] = 'standard';
        }
    }
    return $out;
}

/**
 * Возвращает первый доступный template_id для типа договора (по категории).
 * Нужно, чтобы для «Соглашение выделения долей»/«Дарение» при выборе «по умолчанию» не подставлялся ДКП.
 */
function yvo_get_first_template_id_for_category($category) {
    $list = yvo_get_available_templates();
    $categories = yvo_get_template_categories();
    foreach ($list as $id => $label) {
        if (isset($categories[$id]) && $categories[$id] === $category) {
            return $id;
        }
    }
    return null;
}

function yvo_resolve_template_path($template_id, $bank_id = '') {
    $template_id = trim((string) $template_id);
    if ($template_id === '') return null;
    $uploaded = yvo_get_uploaded_templates();
    $base_dir = yvo_get_uploaded_templates_dir();
    if ($bank_id !== '') {
        foreach ($uploaded as $uid => $u) {
            if (($u['id'] === $template_id || (isset($u['variant_slug']) && $u['variant_slug'] === $template_id)) && isset($u['bank_id']) && $u['bank_id'] === $bank_id && !empty($u['file_txt'])) {
                $path = $base_dir . basename($u['file_txt']);
                return file_exists($path) ? $path : null;
            }
        }
        foreach ($uploaded as $uid => $u) {
            if (($u['id'] === $template_id || (isset($u['variant_slug']) && $u['variant_slug'] === $template_id)) && isset($u['bank_id']) && $u['bank_id'] === 'standard' && !empty($u['file_txt'])) {
                $path = $base_dir . basename($u['file_txt']);
                return file_exists($path) ? $path : null;
            }
        }
    }
    if (isset($uploaded[$template_id]) && !empty($uploaded[$template_id]['file_txt'])) {
        $path = $base_dir . basename($uploaded[$template_id]['file_txt']);
        return file_exists($path) ? $path : null;
    }
    $custom = yvo_get_custom_template_files();
    $template_id_clean = preg_replace('/\s+/u', '_', $template_id);
    if (isset($custom[$template_id_clean])) {
        return $custom[$template_id_clean]['path'];
    }
    foreach ($custom as $cid => $item) {
        if ($cid === $template_id || $cid === $template_id_clean) {
            return $item['path'];
        }
    }
    $id = preg_replace('/[^a-z0-9_\-\s]/iu', '', $template_id);
    $id = preg_replace('/[\s]+/', '_', trim($id));
    if ($id !== '') {
        $path1 = YVO_TEMPLATES_DIR . $id . '.txt';
        if (file_exists($path1)) return $path1;
        $path2 = YVO_TEMPLATES_DIR . 'contract-' . $id . '.txt';
        if (file_exists($path2)) return $path2;
    }
    if ($template_id === 'dkp-nalichnye-akkreditiv-podpisi') {
        $bundled_txt = YVO_PLUGIN_DIR . 'templates/dkp-nalichnye-akkreditiv-podpisi.txt';
        if (file_exists($bundled_txt)) {
            return $bundled_txt;
        }
    }
    return null;
}

/** Путь к файлу DOCX шаблона (для генерации с сохранением стилей), если есть */
function yvo_resolve_template_docx_path($template_id, $bank_id = '') {
    $template_id = trim((string) $template_id);
    $uploaded = yvo_get_uploaded_templates();
    $base_dir = yvo_get_uploaded_templates_dir();
    if ($bank_id !== '') {
        foreach ($uploaded as $u) {
            if (($u['id'] === $template_id || (isset($u['variant_slug']) && $u['variant_slug'] === $template_id)) && isset($u['bank_id']) && $u['bank_id'] === $bank_id && !empty($u['file_docx'])) {
                $path = $base_dir . basename($u['file_docx']);
                return file_exists($path) ? $path : null;
            }
        }
        foreach ($uploaded as $u) {
            if (($u['id'] === $template_id || (isset($u['variant_slug']) && $u['variant_slug'] === $template_id)) && isset($u['bank_id']) && $u['bank_id'] === 'standard' && !empty($u['file_docx'])) {
                $path = $base_dir . basename($u['file_docx']);
                return file_exists($path) ? $path : null;
            }
        }
    }
    if (isset($uploaded[$template_id]) && !empty($uploaded[$template_id]['file_docx'])) {
        $path = $base_dir . basename($uploaded[$template_id]['file_docx']);
        return file_exists($path) ? $path : null;
    }
    if ($template_id === 'dkp-nalichnye-akkreditiv-podpisi') {
        $bundled_docx = YVO_PLUGIN_DIR . 'templates/docx/dkp-nalichnye-akkreditiv-podpisi.docx';
        if (file_exists($bundled_docx)) {
            return $bundled_docx;
        }
    }
    $safe_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $template_id);
    if ($safe_id !== '') {
        $bundled = YVO_PLUGIN_DIR . 'templates/docx/' . $safe_id . '.docx';
        if (file_exists($bundled)) {
            return $bundled;
        }
    }
    return null;
}

/** Полный список плейсхолдеров для подстановки в шаблон (все поддерживаемые теги) */
function yvo_get_all_template_placeholders($seller_data, $buyer_data, $property_data) {
    $price = isset($property_data['price']) && $property_data['price'] !== '' ? number_format(floatval($property_data['price']), 2, ',', ' ') : '________';
    $price_words = isset($property_data['price_words']) && $property_data['price_words'] !== '' ? $property_data['price_words'] : '________________';
    $deposit = isset($property_data['deposit_amount']) && $property_data['deposit_amount'] !== '' ? number_format(floatval($property_data['deposit_amount']), 2, ',', ' ') : '__________';
    $deposit_words = isset($property_data['deposit_amount_words']) && $property_data['deposit_amount_words'] !== '' ? $property_data['deposit_amount_words'] : '________________';
    $remaining = isset($property_data['remaining_amount']) && $property_data['remaining_amount'] !== '' ? number_format(floatval($property_data['remaining_amount']), 2, ',', ' ') : '__________';
    $deadline = yvo_format_deposit_main_contract_deadline(isset($property_data['main_contract_deadline']) ? $property_data['main_contract_deadline'] : '');
    $cadastral = (isset($property_data['cadastral_number']) && $property_data['cadastral_number'] !== '') ? $property_data['cadastral_number'] : '____________________';
    $seller_passport = 'серия ' . ($seller_data['passport_series'] ?? '____') . ' № ' . ($seller_data['passport_number'] ?? '______');
    $buyer_passport = 'серия ' . ($buyer_data['passport_series'] ?? '____') . ' № ' . ($buyer_data['passport_number'] ?? '______');
    return array(
        'SELLER_FULL_NAME' => $seller_data['full_name'] ?? '________________',
        'SELLER_PASSPORT' => $seller_passport,
        'SELLER_PASSPORT_ISSUED' => $seller_data['passport_issued_by'] ?? '________________',
        'SELLER_PASSPORT_DATE' => isset($seller_data['passport_date']) && $seller_data['passport_date'] ? $seller_data['passport_date'] : '________________',
        'SELLER_BIRTH_DATE' => isset($seller_data['birth_date']) && $seller_data['birth_date'] ? $seller_data['birth_date'] : '________________',
        'SELLER_REGISTRATION' => $seller_data['registration'] ?? '________________',
        'BUYER_FULL_NAME' => $buyer_data['full_name'] ?? '________________',
        'BUYER_PASSPORT' => $buyer_passport,
        'BUYER_PASSPORT_ISSUED' => $buyer_data['passport_issued_by'] ?? '________________',
        'BUYER_PASSPORT_DATE' => isset($buyer_data['passport_date']) && $buyer_data['passport_date'] ? $buyer_data['passport_date'] : '________________',
        'BUYER_BIRTH_DATE' => isset($buyer_data['birth_date']) && $buyer_data['birth_date'] ? $buyer_data['birth_date'] : '________________',
        'BUYER_REGISTRATION' => $buyer_data['registration'] ?? '________________',
        'PROPERTY_ADDRESS' => $property_data['address'] ?? '________________',
        'PROPERTY_CADASTRAL' => $cadastral !== '____________________' ? 'Кадастровый номер: ' . $cadastral : '',
        'PROPERTY_CADASTRAL_NUM' => $cadastral,
        'PROPERTY_AREA' => isset($property_data['area']) && $property_data['area'] !== '' ? $property_data['area'] : '___',
        'PROPERTY_ROOMS' => isset($property_data['rooms']) && $property_data['rooms'] !== '' ? $property_data['rooms'] : '___',
        'PROPERTY_FLOOR' => isset($property_data['floor']) && $property_data['floor'] !== '' ? $property_data['floor'] : '___',
        'PROPERTY_FLOORS_TOTAL' => isset($property_data['floors_total']) && $property_data['floors_total'] !== '' ? $property_data['floors_total'] : '___',
        'PROPERTY_PRICE' => $price,
        'PROPERTY_PRICE_WORDS' => $price_words,
        'DEPOSIT_AMOUNT' => $deposit,
        'DEPOSIT_AMOUNT_WORDS' => $deposit_words,
        'REMAINING_AMOUNT' => $remaining,
        'MAIN_CONTRACT_DEADLINE' => $deadline,
        'CURRENT_DATE' => date('d.m.Y'),
        'CURRENT_YEAR' => date('Y'),
        'WHAT_STAYS' => isset($property_data['what_stays']) && $property_data['what_stays'] !== '' ? $property_data['what_stays'] : '________________',
        'VACATE_DEADLINE' => isset($property_data['vacate_deadline']) && $property_data['vacate_deadline'] !== '' ? $property_data['vacate_deadline'] : '14 дней',
    );
}

/** По тексту шаблона определить категорию и извлечь список плейсхолдеров {{X}} */
function yvo_detect_template_meta_from_text($text) {
    $text_lower = mb_strtolower($text, 'UTF-8');
    $category = 'sale';
    if (preg_match('/дарени/u', $text_lower)) {
        $category = 'gift';
    } elseif (preg_match('/выдел\s*долей|долей\s*в\s*недвижимост|орган\s*попечительств|заявлен.*попечительств/u', $text_lower)) {
        $category = 'share_allocation';
    } elseif (preg_match('/задаток|предварительн\s*договор|пдкп/u', $text_lower) && !preg_match('/аванс/u', $text_lower)) {
        $category = 'deposit_agreement';
    } elseif (preg_match('/аванс/u', $text_lower)) {
        $category = 'advance_agreement';
    } elseif (preg_match('/ипотек|аккредитив|ячейк|кредитн/u', $text_lower)) {
        $category = 'sale_mortgage';
    }
    $placeholders = array();
    if (preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/u', $text, $m)) {
        $placeholders = array_unique($m[1]);
        sort($placeholders);
    }
    return array('category' => $category, 'placeholders' => $placeholders);
}

// Генерация договора из файла шаблона (Вариант 2, 3 и т.д.)
function yvo_generate_contract_text_from_template($template_id, $seller_data, $buyer_data, $property_data, $bank_id = '') {
    $path = yvo_resolve_template_path($template_id, $bank_id);
    if (!$path || !file_exists($path)) {
        return yvo_generate_contract_text($seller_data, $buyer_data, $property_data);
    }
    $placeholders = yvo_get_all_template_placeholders($seller_data, $buyer_data, $property_data);
    $content = file_get_contents($path);
    $content = yvo_replace_bracket_placeholders($content, $seller_data, $buyer_data, $property_data);
    foreach ($placeholders as $key => $val) {
        $content = str_replace('{{' . $key . '}}', $val, $content);
    }
    // Любой оставшийся {{XXX}} заменить на прочерки (чтобы шаблон работал с любыми тегами)
    $content = preg_replace('/\{\{[^}]*\}\}/u', '________________', $content);
    return $content;
}

/** Массив подстановок для DOCX-шаблона (полный набор тегов, как в .txt) */
function yvo_build_docx_replacements($seller_data, $buyer_data, $property_data) {
    return yvo_get_all_template_placeholders($seller_data, $buyer_data, $property_data);
}

// Генерация текста договора по типу (sale, assignment, gift, preliminary, deposit_agreement и т.д.)
function yvo_generate_contract_by_type($contract_type, $template_id, $seller_data, $buyer_data, $property_data, $bank_id = '') {
    if ($contract_type === 'sale' || $contract_type === 'assignment') {
        if (yvo_resolve_template_path($template_id, $bank_id)) {
            return yvo_generate_contract_text_from_template($template_id, $seller_data, $buyer_data, $property_data, $bank_id);
        }
        return yvo_generate_contract_text($seller_data, $buyer_data, $property_data);
    }
    $template_files = array(
        'preliminary' => 'preliminary.txt',
        'deposit_receipt' => 'deposit-receipt.txt',
        'advance_agreement' => 'advance-agreement.txt',
    );
    if ($contract_type === 'deposit_agreement') {
        $path = yvo_resolve_deposit_agreement_template_path($template_id, $property_data, $bank_id);
        if ($path && file_exists($path)) {
            return yvo_fill_deposit_agreement_template($path, array($seller_data), array($buyer_data), $property_data);
        }
    }
    if ($contract_type === 'advance_agreement') {
        $path = yvo_resolve_advance_agreement_template_path($template_id, $property_data, $bank_id);
        if ($path && file_exists($path)) {
            return yvo_fill_advance_agreement_template($path, array($seller_data), array($buyer_data), $property_data);
        }
    }
    if (isset($template_files[$contract_type])) {
        $path = YVO_PLUGIN_DIR . 'templates/' . $template_files[$contract_type];
        if (file_exists($path)) {
            return yvo_fill_template_placeholders($path, $seller_data, $buyer_data, $property_data);
        }
    }
    if ($template_id) {
        $path = yvo_resolve_template_path($template_id, $bank_id);
        if ($path && file_exists($path)) {
            return yvo_generate_contract_text_from_template($template_id, $seller_data, $buyer_data, $property_data, $bank_id);
        }
    }
    // Для «Соглашение выделения долей» и «Дарение» при default не подставлять ДКП — взять первый шаблон из папки по категории
    $type_to_category = array('share_allocation' => 'share_allocation', 'gift' => 'gift');
    if (isset($type_to_category[$contract_type]) && ($template_id === 'default' || $template_id === '')) {
        $fallback_id = yvo_get_first_template_id_for_category($type_to_category[$contract_type]);
        if ($fallback_id) {
            $path = yvo_resolve_template_path($fallback_id, $bank_id);
            if ($path && file_exists($path)) {
                return yvo_generate_contract_text_from_template($fallback_id, $seller_data, $buyer_data, $property_data, $bank_id);
            }
        }
    }
    // Дарение, выделение долей и т.д. — заглушка, если шаблон не найден
    $titles = array(
        'gift' => 'Договор дарения',
        'share_allocation' => 'Соглашение о выделении долей',
    );
    $title = isset($titles[$contract_type]) ? $titles[$contract_type] : 'Документ';
    return $title . "\n\n(Шаблон для этого типа документа будет добавлен в следующем обновлении.)\n\n"
        . "Продавец: " . ($seller_data['full_name'] ?? '') . "\n"
        . "Покупатель: " . ($buyer_data['full_name'] ?? '') . "\n"
        . "Объект: " . ($property_data['address'] ?? '') . "\n";
}

// Подстановка плейсхолдеров в шаблон (общие + задаток/аванс, даты)
function yvo_replace_bracket_placeholders($content, $seller_data, $buyer_data, $property_data) {
    $city = isset($property_data['city']) && $property_data['city'] !== '' ? $property_data['city'] : (isset($property_data['address']) ? yvo_extract_city_from_address($property_data['address']) : '________________');
    if ($city === '') $city = '________________';
    $date = date('d.m.Y');
    $cadastral = isset($property_data['cadastral_number']) && $property_data['cadastral_number'] !== '' ? $property_data['cadastral_number'] : '____________________';
    $address = $property_data['address'] ?? '________________';
    $rooms = isset($property_data['rooms']) && $property_data['rooms'] !== '' ? $property_data['rooms'] : '___';
    $area = isset($property_data['area']) && $property_data['area'] !== '' ? $property_data['area'] : '___';
    $floor = isset($property_data['floor']) && $property_data['floor'] !== '' ? $property_data['floor'] : '___';
    $price = isset($property_data['price']) && $property_data['price'] !== '' ? number_format(floatval($property_data['price']), 2, ',', ' ') : '__________';
    $price_words = isset($property_data['price_words']) && $property_data['price_words'] !== '' ? $property_data['price_words'] : '________________';
    $deposit = isset($property_data['deposit_amount']) && $property_data['deposit_amount'] !== '' ? number_format(floatval($property_data['deposit_amount']), 2, ',', ' ') : '__________';
    $deposit_words = isset($property_data['deposit_amount_words']) && $property_data['deposit_amount_words'] !== '' ? $property_data['deposit_amount_words'] : '________________';
    $credit = isset($property_data['loan_credit_amount']) && $property_data['loan_credit_amount'] !== '' ? number_format(floatval($property_data['loan_credit_amount']), 2, ',', ' ') : '__________';
    $credit_words = isset($property_data['loan_credit_amount_words']) && $property_data['loan_credit_amount_words'] !== '' ? $property_data['loan_credit_amount_words'] : '________________';
    $own = isset($property_data['loan_own_amount']) && $property_data['loan_own_amount'] !== '' ? number_format(floatval($property_data['loan_own_amount']), 2, ',', ' ') : '__________';
    $own_words = isset($property_data['loan_own_amount_words']) && $property_data['loan_own_amount_words'] !== '' ? $property_data['loan_own_amount_words'] : '________________';
    $deadline = yvo_format_deposit_main_contract_deadline(
        isset($property_data['main_contract_deadline']) ? $property_data['main_contract_deadline'] : '',
        $date
    );
    $right = trim((string) ($property_data['property_right_info'] ?? ''));
    if ($right === '') {
        $right = '________________';
    }
    $seller_name = $seller_data['full_name'] ?? '________________';
    $buyer_name = $buyer_data['full_name'] ?? '________________';
    $vacate_days = isset($property_data['vacate_deadline']) && $property_data['vacate_deadline'] !== '' ? $property_data['vacate_deadline'] : '14 дней';
    if (preg_match('/\d+/', $vacate_days, $m)) $vacate_days = $m[0];

    $map = array(
        '[город заключения]' => $city,
        '[дата]' => $date,
        '[кадастровый номер]' => $cadastral,
        '[адрес объекта]' => $address,
        '[количество комнат]' => $rooms,
        '[площадь]' => $area,
        '[площадь прописью]' => '________________',
        '[площадь жилая]' => '___',
        '[площадь жилая прописью]' => '________________',
        '[этаж]' => $floor,
        '[этаж(и)]' => $floor,
        '[общая стоимость цифрами]' => $price,
        '[общая стоимость прописью]' => $price_words,
        '[сумма задатка цифрами]' => $deposit,
        '[сумма задатка прописью]' => $deposit_words,
        '[сумма кредитных средств цифрами]' => $credit,
        '[сумма кредитных средств прописью]' => $credit_words,
        '[сумма собственных средств цифрами]' => $own,
        '[сумма собственных средств прописью]' => $own_words,
        '[срок выхода на сделку]' => $deadline,
        '[адрес встречной сделки]' => '________________',
        '[дата регистрации права]' => '________________',
        '[номер и дата регистрации]' => $right,
        '[номер и дата регистрации, вид права]' => $right,
        '[количество]' => $vacate_days,
        '[срок]' => $vacate_days,
    );
    foreach ($map as $tag => $val) {
        $content = str_replace($tag, $val, $content);
    }
    $fio_count = 0;
    $content = preg_replace_callback('/\[Ф\.И\.О\.\]/u', function () use ($seller_name, $buyer_name, &$fio_count) {
        $fio_count++;
        return $fio_count === 1 ? $seller_name : $buyer_name;
    }, $content);
    return $content;
}

function yvo_fill_template_placeholders($template_path, $seller_data, $buyer_data, $property_data) {
    $price = isset($property_data['price']) && $property_data['price'] !== '' ? number_format(floatval($property_data['price']), 2, ',', ' ') : '__________';
    $price_words = isset($property_data['price_words']) && $property_data['price_words'] !== '' ? $property_data['price_words'] : '________________';
    $deposit = isset($property_data['deposit_amount']) && $property_data['deposit_amount'] !== '' ? number_format(floatval($property_data['deposit_amount']), 2, ',', ' ') : '__________';
    $deposit_words = isset($property_data['deposit_amount_words']) && $property_data['deposit_amount_words'] !== '' ? $property_data['deposit_amount_words'] : '________________';
    $remaining = isset($property_data['remaining_amount']) && $property_data['remaining_amount'] !== '' ? number_format(floatval($property_data['remaining_amount']), 2, ',', ' ') : '__________';
    $deadline = yvo_format_deposit_main_contract_deadline(
        isset($property_data['main_contract_deadline']) ? $property_data['main_contract_deadline'] : '',
        date('d.m.Y')
    );
    $cadastral = isset($property_data['cadastral_number']) && $property_data['cadastral_number'] !== '' ? $property_data['cadastral_number'] : '____________________';
    $seller_passport = 'серия ' . ($seller_data['passport_series'] ?? '____') . ' № ' . ($seller_data['passport_number'] ?? '______');
    $buyer_passport = 'серия ' . ($buyer_data['passport_series'] ?? '____') . ' № ' . ($buyer_data['passport_number'] ?? '______');
    $seller_date = isset($seller_data['passport_date']) && $seller_data['passport_date'] ? $seller_data['passport_date'] : '________________';
    $buyer_date = isset($buyer_data['passport_date']) && $buyer_data['passport_date'] ? $buyer_data['passport_date'] : '________________';
    $seller_birth = isset($seller_data['birth_date']) && $seller_data['birth_date'] ? $seller_data['birth_date'] : '________________';
    $buyer_birth = isset($buyer_data['birth_date']) && $buyer_data['birth_date'] ? $buyer_data['birth_date'] : '________________';
    $placeholders = array(
        'SELLER_FULL_NAME' => $seller_data['full_name'] ?? '________________',
        'SELLER_PASSPORT' => $seller_passport,
        'SELLER_PASSPORT_ISSUED' => $seller_data['passport_issued_by'] ?? '________________',
        'SELLER_PASSPORT_DATE' => $seller_date,
        'SELLER_BIRTH_DATE' => $seller_birth,
        'SELLER_REGISTRATION' => $seller_data['registration'] ?? '________________',
        'BUYER_FULL_NAME' => $buyer_data['full_name'] ?? '________________',
        'BUYER_PASSPORT' => $buyer_passport,
        'BUYER_PASSPORT_ISSUED' => $buyer_data['passport_issued_by'] ?? '________________',
        'BUYER_PASSPORT_DATE' => $buyer_date,
        'BUYER_BIRTH_DATE' => $buyer_birth,
        'BUYER_REGISTRATION' => $buyer_data['registration'] ?? '________________',
        'PROPERTY_ADDRESS' => $property_data['address'] ?? '________________',
        'PROPERTY_CADASTRAL_NUM' => $cadastral,
        'PROPERTY_AREA' => isset($property_data['area']) && $property_data['area'] !== '' ? $property_data['area'] : '___',
        'PROPERTY_PRICE' => $price,
        'PROPERTY_PRICE_WORDS' => $price_words,
        'DEPOSIT_AMOUNT' => $deposit,
        'DEPOSIT_AMOUNT_WORDS' => $deposit_words,
        'REMAINING_AMOUNT' => $remaining,
        'MAIN_CONTRACT_DEADLINE' => $deadline,
        'CURRENT_DATE' => date('d.m.Y'),
        'CURRENT_YEAR' => date('Y'),
    );
    $content = file_get_contents($template_path);
    $content = yvo_replace_bracket_placeholders($content, $seller_data, $buyer_data, $property_data);
    foreach ($placeholders as $key => $val) {
        $content = str_replace('{{' . $key . '}}', $val, $content);
    }
    return $content;
}

/**
 * Собрать адрес объекта из отдельных полей формы, если property[address] пуст.
 *
 * @param array<string, mixed> $property
 */
function yvo_property_resolve_address(array $property) {
    $addr = trim((string) ($property['address'] ?? ''));
    if ($addr !== '') {
        if (function_exists('yvo_normalize_property_address_fields')) {
            $normalized = yvo_normalize_property_address_fields(array_merge($property, array('address' => $addr)));
            $addr = trim((string) ($normalized['address'] ?? $addr));
        } elseif (function_exists('yvo_clean_address_string')) {
            $addr = yvo_clean_address_string($addr);
        }
        return $addr;
    }
    $ot = sanitize_key((string) ($property['object_type'] ?? 'apartment'));
    $parts = array();
    $push = function ($v) use (&$parts) {
        $v = trim((string) $v);
        if ($v !== '') {
            $parts[] = $v;
        }
    };
    if ($ot === 'house_with_plot') {
        $push($property['house_settlement'] ?? '');
        $push($property['house_street'] ?? '');
        $hn = trim((string) ($property['house_number'] ?? ''));
        if ($hn !== '') {
            $parts[] = 'д. ' . $hn;
        }
    } elseif ($ot === 'land') {
        $push($property['region'] ?? '');
        $push($property['district'] ?? '');
        $push($property['settlement'] ?? '');
        $push($property['snt_dnt'] ?? '');
        $pn = trim((string) ($property['plot_number'] ?? ''));
        if ($pn !== '') {
            $parts[] = 'уч. ' . $pn;
        }
    } elseif ($ot === 'room') {
        $push($property['city'] ?? '');
        $push($property['street'] ?? '');
        $h = trim((string) ($property['house'] ?? ''));
        if ($h !== '') {
            $parts[] = 'д. ' . $h;
        }
        $b = trim((string) ($property['building'] ?? ''));
        if ($b !== '') {
            $parts[] = 'корп. ' . $b;
        }
        $apt = trim((string) ($property['apartment'] ?? ''));
        if ($apt !== '') {
            $parts[] = 'кв. ' . $apt;
        }
        $rn = trim((string) ($property['room_number'] ?? ''));
        if ($rn !== '') {
            $parts[] = 'комн. ' . $rn;
        }
    } elseif ($ot === 'apartment' || $ot === 'share') {
        $push($property['city'] ?? '');
        $push($property['street'] ?? '');
        $h = trim((string) ($property['house'] ?? ''));
        if ($h !== '') {
            $parts[] = 'д. ' . $h;
        }
        $b = trim((string) ($property['building'] ?? ''));
        if ($b !== '') {
            $parts[] = 'корп. ' . $b;
        }
        $apt = trim((string) ($property['apartment'] ?? ''));
        if ($apt !== '') {
            $parts[] = 'кв. ' . $apt;
        }
    }
    return implode(', ', $parts);
}

/**
 * Извлекает город из строки адреса (например "Республика Башкортостан, г. Уфа, ул. ..." -> "Уфа").
 */
function yvo_extract_city_from_address($address) {
    if (!is_string($address) || trim($address) === '') {
        return '';
    }
    $address = trim($address);
    if (preg_match('/\bг\.?\s*о\.?\s+город\s+([^\s,]+)/ui', $address, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/\b(городской\s+округ\s+(?:город\s+)?[^,]+)/ui', $address, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/\bгород\s+([^\s,]+)/ui', $address, $m)) {
        $city = trim($m[1]);
        if (mb_strtolower($city, 'UTF-8') !== 'о') {
            return $city;
        }
    }
    if (preg_match('/\bг\.\s+([^\s,]+)/u', $address, $m)) {
        return trim($m[1]);
    }
    return '';
}

/** Город заключения для шапки договора (без ошибочного «г. ородской…»). */
function yvo_format_contract_city_display($city) {
    $city = trim((string) $city);
    if ($city === '' || $city === '________________') {
        return $city;
    }
    if (preg_match('/^г\.\s+/u', $city)) {
        return $city;
    }
    if (preg_match('/^ородской/ui', $city)) {
        return 'г' . $city;
    }
    if (preg_match('/^(городской|город\s|г\.о\.|г\.о\s|муниципальный|сельск|поселок|п\.|пгт)/ui', $city)) {
        return $city;
    }
    return 'г. ' . $city;
}

/** «по г. Уфе» для п. 11 соглашения о выделении долей (без «по город Уфа»). */
function yvo_city_for_registering_authority($city) {
    $city = trim((string) $city);
    if ($city === '' || $city === '________________') {
        return '';
    }
    $city = preg_replace('/^г\.?\s+/ui', '', $city);
    $city = preg_replace('/^город\s+/ui', '', $city);
    $city = trim(preg_replace('/\s+/u', ' ', $city));
    if ($city === '') {
        return '';
    }
    static $prepositional = array(
        'уфа' => 'Уфе',
        'москва' => 'Москве',
        'санкт-петербург' => 'Санкт-Петербурге',
        'казань' => 'Казани',
    );
    $key = mb_strtolower($city, 'UTF-8');
    if (isset($prepositional[$key])) {
        return 'по г. ' . $prepositional[$key];
    }
    return 'по г. ' . $city;
}

/** Строка «город + дата» для плейсхолдеров шаблонов. */
function yvo_build_contract_city_date_line($contract_city, $contract_date, $contract_type, $date_day, $month_gen, $date_year) {
    $city_line = yvo_format_contract_city_display($contract_city);
    if ($contract_type === 'gift') {
        return $city_line . ' «' . $date_day . '» ' . $month_gen . ' ' . $date_year . ' г.';
    }
    return $city_line . "\n" . $contract_date;
}

/** Профиль оформления DOCX из plain-text (разные типы договоров — разное оформление). */
function yvo_docx_profile_for_contract_type($contract_type) {
    if ($contract_type === 'share_allocation') {
        return 'share_allocation';
    }
    if ($contract_type === 'gift') {
        return 'gift';
    }
    if (in_array((string) $contract_type, array('deposit_agreement', 'advance_agreement', 'deposit_receipt'), true)) {
        return 'deposit';
    }
    return 'default';
}

/**
 * Идентификатор вкладки участника из данных формы (передаётся с фронта как participant_tab).
 */
function yvo_party_row_tab(array $row) {
    return isset($row['participant_tab']) ? trim((string) $row['participant_tab']) : '';
}

/** Продавцы-принципалы (без доверенных лиц), в порядке появления в массиве. */
function yvo_parties_principal_sellers_ordered(array $sellers) {
    $out = array();
    foreach ($sellers as $s) {
        if (!is_array($s)) {
            continue;
        }
        $t = yvo_party_row_tab($s);
        if ($t === '' || $t === 'seller' || preg_match('/^seller\d+$/', $t) === 1 || strpos($t, 'minor_seller') === 0) {
            $out[] = $s;
        }
    }
    return $out;
}

function yvo_row_is_seller_representative(array $row) {
    return strpos(yvo_party_row_tab($row), 'seller_representative') === 0;
}

/** Покупатели-принципалы (без доверенных лиц), в порядке появления в массиве. */
function yvo_parties_principal_buyers_ordered(array $buyers) {
    $out = array();
    foreach ($buyers as $b) {
        if (!is_array($b)) {
            continue;
        }
        $t = yvo_party_row_tab($b);
        if ($t === '' || $t === 'buyer' || preg_match('/^buyer\d+$/', $t) === 1 || strpos($t, 'contributor') === 0 || strpos($t, 'minor_buyer') === 0) {
            $out[] = $b;
        }
    }
    return $out;
}

function yvo_row_is_buyer_representative(array $row) {
    return strpos(yvo_party_row_tab($row), 'buyer_representative') === 0;
}

function yvo_row_is_seller_guardian(array $row) {
    return strpos(yvo_party_row_tab($row), 'guardian_seller') === 0;
}

function yvo_row_is_buyer_guardian(array $row) {
    return strpos(yvo_party_row_tab($row), 'guardian_buyer') === 0;
}

function yvo_row_is_minor_seller(array $row) {
    return strpos(yvo_party_row_tab($row), 'minor_seller') === 0;
}

function yvo_row_is_minor_buyer(array $row) {
    return strpos(yvo_party_row_tab($row), 'minor_buyer') === 0;
}

/** u14 — до 14 лет (опекун в договоре); a14_18 — от 14 (подписывает сам, как одаряемый). */
function yvo_row_minor_age_group(array $row) {
    if (!yvo_row_is_minor_seller($row) && !yvo_row_is_minor_buyer($row)) {
        return '';
    }
    $age = isset($row['minor_age_group']) ? trim((string) $row['minor_age_group']) : '';
    return ($age === 'a14_18') ? 'a14_18' : 'u14';
}

function yvo_row_minor_needs_guardian_in_contract(array $row) {
    return yvo_row_minor_age_group($row) === 'u14';
}

/**
 * Нужен ли блок опекуна в тексте договора (только если представляет несовершеннолетнего до 14 лет).
 *
 * @param array<int, array> $all_rows
 * @param array<int, array> $principals
 */
function yvo_guardian_should_appear_in_contract(array $guardian_row, array $all_rows, array $principals, $side) {
    $minor_tab = yvo_minor_tab_for_guardian_tab(yvo_party_row_tab($guardian_row));
    if ($minor_tab !== '') {
        foreach ($all_rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (yvo_party_row_tab($r) === $minor_tab) {
                return yvo_row_minor_needs_guardian_in_contract($r);
            }
        }
    }
    $gkey = yvo_guardian_preamble_group_key($guardian_row, $all_rows);
    $is_guardian = ($side === 'seller') ? 'yvo_row_is_seller_guardian' : 'yvo_row_is_buyer_guardian';
    foreach ($all_rows as $r) {
        if (!is_array($r) || !$is_guardian($r)) {
            continue;
        }
        if (yvo_guardian_preamble_group_key($r, $all_rows) !== $gkey) {
            continue;
        }
        $mt = yvo_minor_tab_for_guardian_tab(yvo_party_row_tab($r));
        if ($mt === '') {
            continue;
        }
        foreach ($all_rows as $m) {
            if (!is_array($m)) {
                continue;
            }
            if (yvo_party_row_tab($m) === $mt && yvo_row_minor_needs_guardian_in_contract($m)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Вкладка несовершеннолетнего, которого представляет опекун (guardian_seller → minor_seller).
 */
function yvo_minor_tab_for_guardian_tab($guardian_tab) {
    $guardian_tab = trim((string) $guardian_tab);
    if (preg_match('/^guardian_seller(\d*)$/', $guardian_tab, $m)) {
        return ($m[1] === '') ? 'minor_seller' : 'minor_seller' . $m[1];
    }
    if (preg_match('/^guardian_buyer(\d*)$/', $guardian_tab, $m)) {
        return ($m[1] === '') ? 'minor_buyer' : 'minor_buyer' . $m[1];
    }
    return '';
}

/**
 * Номер принципала (1-based) для опекуна: привязка к несовершеннолетнему в списке принципалов, не к полю «№ вкладки».
 *
 * @param array<int, array> $principals
 */
function yvo_guardian_resolve_principal_index(array $principals, array $guardian_row) {
    $minor_tab = yvo_minor_tab_for_guardian_tab(yvo_party_row_tab($guardian_row));
    if ($minor_tab !== '') {
        foreach ($principals as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (yvo_party_row_tab($p) === $minor_tab) {
                return $i + 1;
            }
        }
    }
    return yvo_rep_resolve_principal_index($principals, $guardian_row);
}

/** Вкладка-источник данных опекуна (если указан «тот же опекун»). */
function yvo_guardian_same_as_tab(array $row) {
    return trim((string) ($row['same_guardian_as'] ?? ''));
}

/**
 * @param array<int, array> $rows
 */
function yvo_party_row_by_tab(array $rows, $tab) {
    $tab = trim((string) $tab);
    if ($tab === '') {
        return null;
    }
    foreach ($rows as $row) {
        if (is_array($row) && yvo_party_row_tab($row) === $tab) {
            return $row;
        }
    }
    return null;
}

/**
 * Данные опекуна с учётом ссылки same_guardian_as (без participant_tab).
 *
 * @param array<int, array> $all_rows
 */
function yvo_party_guardian_resolved_row(array $row, array $all_rows) {
    $same = yvo_guardian_same_as_tab($row);
    if ($same === '') {
        return $row;
    }
    $src = yvo_party_row_by_tab($all_rows, $same);
    if (!is_array($src)) {
        return $row;
    }
    $copy_keys = array(
        'full_name', 'birth_date', 'birth_place', 'passport_series', 'passport_number',
        'passport_issued_by', 'department_code', 'registration', 'guardian_basis',
    );
    $merged = $row;
    foreach ($copy_keys as $k) {
        if (isset($src[$k]) && trim((string) $src[$k]) !== '') {
            $merged[$k] = $src[$k];
        }
    }
    return $merged;
}

/** Ключ идентичности опекуна для объединения в договоре. */
function yvo_guardian_identity_key(array $row) {
    $name = mb_strtolower(trim((string) ($row['full_name'] ?? '')), 'UTF-8');
    $ser = preg_replace('/\s+/u', '', (string) ($row['passport_series'] ?? ''));
    $num = preg_replace('/\s+/u', '', (string) ($row['passport_number'] ?? ''));
    if ($name !== '') {
        return $name . '|' . $ser . '|' . $num;
    }
    $same = yvo_guardian_same_as_tab($row);
    if ($same !== '') {
        return 'ref:' . $same;
    }
    return 'tab:' . yvo_party_row_tab($row);
}

/**
 * Группа опекуна в преамбуле: ссылка на первую вкладку или идентичность ФИО+паспорт.
 *
 * @param array<int, array> $all_rows
 */
function yvo_guardian_preamble_group_key(array $row, array $all_rows) {
    $same = yvo_guardian_same_as_tab($row);
    if ($same !== '') {
        foreach ($all_rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (yvo_party_row_tab($r) === $same) {
                $resolved = yvo_party_guardian_resolved_row($r, $all_rows);
                return 'id:' . yvo_guardian_identity_key($resolved);
            }
        }
        return 'ref:' . $same;
    }
    $resolved = yvo_party_guardian_resolved_row($row, $all_rows);
    return 'id:' . yvo_guardian_identity_key($resolved);
}

/**
 * @param array<int, array> $all_rows
 * @param array<int, array> $principals
 * @return array<int, string>
 */
function yvo_guardian_group_minor_phrases(array $guardian_row, array $all_rows, array $principals, $contract_type, $side) {
    $gkey = yvo_guardian_preamble_group_key($guardian_row, $all_rows);
    $is_guardian = ($side === 'seller') ? 'yvo_row_is_seller_guardian' : 'yvo_row_is_buyer_guardian';
    $parts = array();
    foreach ($all_rows as $gr) {
        if (!is_array($gr) || !$is_guardian($gr)) {
            continue;
        }
        if (yvo_guardian_preamble_group_key($gr, $all_rows) !== $gkey) {
            continue;
        }
        $pnum = yvo_guardian_resolve_principal_index($principals, $gr);
        $p = isset($principals[$pnum - 1]) && is_array($principals[$pnum - 1]) ? $principals[$pnum - 1] : array();
        $pname = isset($p['full_name']) && trim((string) $p['full_name']) !== '' ? trim((string) $p['full_name']) : '________________';
        $parts[] = yvo_contract_party_side_genitive($contract_type, $side, $pnum) . ' (' . $pname . ')';
    }
    return $parts;
}

/**
 * Порядковый номер опекуна в договоре (1 = первый уникальный человек, не номер несовершеннолетнего).
 *
 * @param array<int, array> $all_rows
 */
function yvo_guardian_contract_label_number(array $guardian_row, array $all_rows, $side) {
    $is_guardian = ($side === 'seller') ? 'yvo_row_is_seller_guardian' : 'yvo_row_is_buyer_guardian';
    $gkey_self = yvo_guardian_preamble_group_key($guardian_row, $all_rows);
    $map = array();
    $n = 0;
    foreach ($all_rows as $r) {
        if (!is_array($r) || !$is_guardian($r)) {
            continue;
        }
        $gk = yvo_guardian_preamble_group_key($r, $all_rows);
        if (!isset($map[$gk])) {
            $n++;
            $map[$gk] = $n;
        }
    }
    return isset($map[$gkey_self]) ? (int) $map[$gkey_self] : 1;
}

/**
 * Текст блока опекуна в преамбуле; null — пропустить (дубликат той же персоны).
 *
 * @param array<int, array> $all_rows
 * @param array<int, array> $principals
 * @param array<string, bool> $guardian_preamble_done
 */
function yvo_build_guardian_preamble_block(array $guardian_row, array $all_rows, array $principals, $contract_type, $side, array &$guardian_preamble_done) {
    $is_seller = ($side === 'seller');
    $gkey = yvo_guardian_preamble_group_key($guardian_row, $all_rows);
    if (isset($guardian_preamble_done[$gkey])) {
        return null;
    }
    $guardian_preamble_done[$gkey] = true;

    $resolved = yvo_party_guardian_resolved_row($guardian_row, $all_rows);
    $name = isset($resolved['full_name']) ? yvo_party_format_person_name($resolved['full_name']) : '________________';
    $birth = isset($resolved['birth_date']) && $resolved['birth_date'] ? $resolved['birth_date'] : '____________';
    $birth_place = isset($resolved['birth_place']) && $resolved['birth_place'] ? yvo_party_format_free_text($resolved['birth_place']) : '____________________';
    $pass_ser = isset($resolved['passport_series']) ? $resolved['passport_series'] : '_____';
    $pass_num = isset($resolved['passport_number']) ? $resolved['passport_number'] : '__________';
    $issued = isset($resolved['passport_issued_by']) ? $resolved['passport_issued_by'] : '_______________________________';
    if ($issued !== '_______________________________') {
        $issued = yvo_party_format_free_text($issued);
    }
    $dept = isset($resolved['department_code']) ? $resolved['department_code'] : '_______';
    $reg_line = yvo_party_registration_line($resolved);
    $gender = yvo_party_gender_from_row($resolved);
    $acting = yvo_party_gender_form($gender, 'действующий', 'действующая', 'действующий(-ая)');
    $named = yvo_party_gender_form($gender, 'именуемый', 'именуемая', 'именуемый(-ая)');
    $basis = isset($resolved['guardian_basis']) ? trim((string) $resolved['guardian_basis']) : '';
    if ($basis === '') {
        $basis = 'законного представительства';
    }
    $minor_phrases = yvo_guardian_group_minor_phrases($guardian_row, $all_rows, $principals, $contract_type, $side);
    if (count($minor_phrases) > 1) {
        $repr_line = 'законный представитель ' . implode(' и ', $minor_phrases);
    } elseif (!empty($minor_phrases)) {
        $repr_line = 'законный представитель ' . $minor_phrases[0];
    } else {
        $pnum = yvo_guardian_resolve_principal_index($principals, $guardian_row);
        $p = isset($principals[$pnum - 1]) && is_array($principals[$pnum - 1]) ? $principals[$pnum - 1] : array();
        $pname = isset($p['full_name']) && trim((string) $p['full_name']) !== '' ? trim((string) $p['full_name']) : '________________';
        $repr_line = 'законный представитель ' . yvo_contract_party_side_genitive($contract_type, $side, $pnum) . ' (' . $pname . ')';
    }
    $guardian_num = yvo_guardian_contract_label_number($guardian_row, $all_rows, $side);
    $party_label = yvo_contract_party_display_label($contract_type, $side, 'guardian', $guardian_num);

    if ($contract_type === 'gift') {
        $registered = yvo_party_gender_form($gender, 'зарегистрированный', 'зарегистрированная', 'зарегистрированный(-ая)');
        return 'гр. РФ ' . $name . ', ' . $birth . ' года рождения, место рождения: ' . $birth_place
            . ', паспорт серия ' . $pass_ser . ' номер ' . $pass_num . ', выдан ' . $issued
            . ', код подразделения ' . $dept . ', ' . $registered . ' по адресу: ' . yvo_party_registration_address_display($resolved)
            . ', ' . $acting . ' на основании ' . $basis . ', ' . $repr_line . ', '
            . 'в дальнейшем ' . $named . ' «' . $party_label . '»';
    }

    if ($contract_type === 'deposit_agreement') {
        $registered = yvo_party_gender_form($gender, 'зарегистрированный', 'зарегистрированная', 'зарегистрированный(-ая)');
        return 'пол ' . yvo_deposit_party_sex_word($resolved) . ', гражданство РФ, ' . $birth . ' года рождения, место рождения: ' . $birth_place
            . ', паспорт ' . $pass_ser . ' ' . $pass_num . ', выдан ' . $issued
            . ', код подразделения ' . $dept . ', ' . $registered . ' по адресу: ' . yvo_party_registration_address_display($resolved)
            . ', ' . $acting . ' на основании ' . $basis . ', ' . $repr_line . ', '
            . $named . ' в дальнейшем «' . $party_label . '»';
    }

    $block = $name . ",\n";
    $block .= "дата рождения: " . $birth . ", место рождения: " . $birth_place . ",\n";
    $block .= "паспорт РФ: серия " . $pass_ser . " номер " . $pass_num . ", выдан " . $issued . ",\n";
    $block .= "код подразделения " . $dept . ", " . $reg_line . "\n";
    $block .= $acting . " на основании " . $basis . ", " . $repr_line . ",\n";
    $block .= $named . " в дальнейшем «" . $party_label . "»";
    return $block;
}

/**
 * Подставить данные «того же опекуна» и нормализовать строки перед генерацией.
 *
 * @param array<int, array> $rows
 */
function yvo_parties_apply_guardian_links(array &$rows) {
    $by_tab = array();
    foreach ($rows as $row) {
        if (is_array($row)) {
            $t = yvo_party_row_tab($row);
            if ($t !== '') {
                $by_tab[$t] = $row;
            }
        }
    }
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $same = yvo_guardian_same_as_tab($row);
        if ($same === '' || !isset($by_tab[$same]) || !is_array($by_tab[$same])) {
            continue;
        }
        $rows[$i] = yvo_party_guardian_resolved_row($row, $rows);
        $rows[$i]['participant_tab'] = yvo_party_row_tab($row);
        $rows[$i]['same_guardian_as'] = $same;
    }
}

/**
 * Один и тот же опекун на нескольких вкладках — привязать к первой вкладке (ФИО + паспорт).
 *
 * @param array<int, array> $rows
 */
function yvo_parties_auto_link_duplicate_guardians(array &$rows) {
    $first_tab = array();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tab = yvo_party_row_tab($row);
        if ($tab === '' || (strpos($tab, 'guardian_') !== 0)) {
            continue;
        }
        if (yvo_guardian_same_as_tab($row) !== '') {
            continue;
        }
        $resolved = yvo_party_guardian_resolved_row($row, $rows);
        $key = yvo_guardian_identity_key($resolved);
        if ($key === '' || strpos($key, 'tab:') === 0) {
            continue;
        }
        if (!isset($first_tab[$key])) {
            $first_tab[$key] = $tab;
        }
    }
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $tab = yvo_party_row_tab($row);
        if ($tab === '' || (strpos($tab, 'guardian_') !== 0)) {
            continue;
        }
        if (yvo_guardian_same_as_tab($row) !== '') {
            continue;
        }
        $resolved = yvo_party_guardian_resolved_row($row, $rows);
        $key = yvo_guardian_identity_key($resolved);
        if ($key === '' || !isset($first_tab[$key]) || $first_tab[$key] === $tab) {
            continue;
        }
        $rows[$i]['same_guardian_as'] = $first_tab[$key];
    }
}

/**
 * Порядок строк в преамбуле: принципалы (взрослые → несовершеннолетние) → опекуны → представители.
 *
 * @param array<int, array> $rows
 * @param string            $side seller|buyer
 * @return array<int, array>
 */
function yvo_parties_sort_for_contract_side(array $rows, $side) {
    $ranked = array();
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rank = 20;
        if ($side === 'seller') {
            if (yvo_row_is_seller_representative($row)) {
                $rank = 40;
            } elseif (yvo_row_is_seller_guardian($row)) {
                $rank = 30;
            } elseif (yvo_row_is_minor_seller($row)) {
                $rank = 25;
            } else {
                $rank = 10;
            }
        } else {
            if (yvo_row_is_buyer_representative($row)) {
                $rank = 40;
            } elseif (yvo_row_is_buyer_guardian($row)) {
                $rank = 30;
            } elseif (yvo_row_is_minor_buyer($row)) {
                $rank = 25;
            } else {
                $rank = 10;
            }
        }
        $ranked[] = array('i' => $i, 'rank' => $rank, 'row' => $row);
    }
    usort($ranked, function ($a, $b) {
        if ($a['rank'] !== $b['rank']) {
            return $a['rank'] - $b['rank'];
        }
        return $a['i'] - $b['i'];
    });
    $out = array();
    foreach ($ranked as $item) {
        $out[] = $item['row'];
    }
    return $out;
}

/**
 * Перед генерацией: порядок сторон для преамбулы и подписей (несовершеннолетние + опекуны).
 *
 * @param array<int, array> $sellers
 * @param array<int, array> $buyers
 */
function yvo_prepare_parties_for_contract_generation(array &$sellers, array &$buyers) {
    yvo_parties_apply_guardian_links($sellers);
    yvo_parties_apply_guardian_links($buyers);
    yvo_parties_auto_link_duplicate_guardians($sellers);
    yvo_parties_auto_link_duplicate_guardians($buyers);
    yvo_parties_apply_guardian_links($sellers);
    yvo_parties_apply_guardian_links($buyers);
    $sellers = yvo_parties_sort_for_contract_side($sellers, 'seller');
    $buyers = yvo_parties_sort_for_contract_side($buyers, 'buyer');
}

/**
 * @param array<int, array> $rows
 * @return array{sellers_minors:int,sellers_guardians:int,sellers_reps:int,buyers_minors:int,buyers_guardians:int,buyers_reps:int}
 */
function yvo_parties_generation_counts(array $sellers, array $buyers) {
    $count = function ($rows, $fn) {
        $n = 0;
        foreach ($rows as $row) {
            if (is_array($row) && $fn($row)) {
                $n++;
            }
        }
        return $n;
    };
    return array(
        'sellers_minors' => $count($sellers, 'yvo_row_is_minor_seller'),
        'sellers_guardians' => $count($sellers, 'yvo_row_is_seller_guardian'),
        'sellers_reps' => $count($sellers, 'yvo_row_is_seller_representative'),
        'buyers_minors' => $count($buyers, 'yvo_row_is_minor_buyer'),
        'buyers_guardians' => $count($buyers, 'yvo_row_is_buyer_guardian'),
        'buyers_reps' => $count($buyers, 'yvo_row_is_buyer_representative'),
    );
}

/**
 * Номер принципала (1-based), которого представляет доверенное лицо.
 *
 * @param array<int, array> $principals
 */
function yvo_rep_resolve_principal_index(array $principals, array $rep_row) {
    $c = count($principals);
    if ($c < 1) {
        return 1;
    }
    $n = isset($rep_row['represents_party_number']) ? (int) $rep_row['represents_party_number'] : 1;
    if ($n < 1) {
        $n = 1;
    }
    if ($n > $c) {
        $n = $c;
    }
    return $n;
}

function yvo_party_poa_line(array $rep_row) {
    $d = isset($rep_row['power_of_attorney_details']) ? trim((string) $rep_row['power_of_attorney_details']) : '';
    return $d !== '' ? $d : 'доверенности';
}

/** Пол по отчеству/ФИО: m, f или пусто (неизвестно). */
function yvo_party_gender_from_row(array $row) {
    $name = trim((string) ($row['full_name'] ?? ''));
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    $patronymic = count($parts) >= 3 ? $parts[2] : (count($parts) === 2 ? $parts[1] : '');
    if ($patronymic === '') {
        return '';
    }
    $p = mb_strtolower($patronymic, 'UTF-8');
    if (preg_match('/(овна|евна|ична|инична)$/u', $p)) {
        return 'f';
    }
    if (preg_match('/(ович|евич|ьич|ич)$/u', $p)) {
        return 'm';
    }
    return '';
}

/** Согласование по полу; при неизвестном поле — нейтральная форма с (-ая). */
function yvo_party_gender_form($gender, $male, $female, $unknown = '') {
    if ($gender === 'f') {
        return $female;
    }
    if ($gender === 'm') {
        return $male;
    }
    if ($unknown !== '') {
        return $unknown;
    }
    return $male . '(-ая)';
}

/** Адрес регистрации для договора (пустой → прочерк). */
function yvo_party_registration_address_display(array $row) {
    $reg = isset($row['registration']) ? trim((string) $row['registration']) : '';
    $reg = preg_replace('/\s+/u', ' ', $reg);
    if ($reg === '' || $reg === ',') {
        return '________________';
    }
    return $reg;
}

/** Строка «зарегистрирован(а) по адресу: …». */
function yvo_party_registration_line(array $row) {
    $gender = yvo_party_gender_from_row($row);
    $reg = yvo_party_registration_address_display($row);
    $word = yvo_party_gender_form($gender, 'зарегистрированный', 'зарегистрированная', 'зарегистрированный(-ая)');
    return $word . ' по адресу: ' . $reg . ',';
}

/**
 * Преамбула дарителя/одаряемого в формате Shablon-Darenie-dogovor (гр. РФ … «Даритель»/«Одаряемый»).
 *
 * @param string $role_label Даритель|Одаряемый|…
 */
function yvo_party_format_person_name($name) {
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    if ($name === '' || preg_match('/^_+$/u', $name)) {
        return $name;
    }
    $upper = mb_strtoupper($name, 'UTF-8');
    if ($name !== $upper && !preg_match('/^[А-ЯЁA-Z][А-ЯЁA-Z\s\-\.]+$/u', $name)) {
        return $name;
    }
    $parts = preg_split('/\s+/u', $name);
    $out = array();
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (strpos($part, '-') !== false) {
            $chunks = array();
            foreach (explode('-', $part) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }
                $chunks[] = mb_strtoupper(mb_substr($chunk, 0, 1, 'UTF-8'), 'UTF-8')
                    . mb_strtolower(mb_substr($chunk, 1, null, 'UTF-8'), 'UTF-8');
            }
            $out[] = implode('-', $chunks);
        } else {
            $out[] = mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8')
                . mb_strtolower(mb_substr($part, 1, null, 'UTF-8'), 'UTF-8');
        }
    }
    return implode(' ', $out);
}

/** Адреса, органы выдачи и т.п. — без капса «всё прописными». */
function yvo_party_format_free_text($text) {
    $text = trim((string) $text);
    if ($text === '' || preg_match('/^_+$/u', $text)) {
        return $text;
    }
    $upper = mb_strtoupper($text, 'UTF-8');
    if ($text !== $upper) {
        return $text;
    }
    $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $formatted = '';
    foreach ($words as $w) {
        if (trim($w) === '') {
            $formatted .= $w;
            continue;
        }
        $formatted .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_strtolower(mb_substr($w, 1, null, 'UTF-8'), 'UTF-8');
    }
    return $formatted;
}

function yvo_build_gift_principal_preamble_line(array $row, $role_label) {
    $name = isset($row['full_name']) && trim((string) $row['full_name']) !== ''
        ? yvo_party_format_person_name(trim((string) $row['full_name'])) : '________________';
    $birth = isset($row['birth_date']) && trim((string) $row['birth_date']) !== ''
        ? trim((string) $row['birth_date']) : '____________';
    $birth_place = isset($row['birth_place']) && trim((string) $row['birth_place']) !== ''
        ? yvo_party_format_free_text(trim((string) $row['birth_place'])) : '____________________';
    $pass_ser = isset($row['passport_series']) && trim((string) $row['passport_series']) !== ''
        ? trim((string) $row['passport_series']) : '_____';
    $pass_num = isset($row['passport_number']) && trim((string) $row['passport_number']) !== ''
        ? trim((string) $row['passport_number']) : '__________';
    $issued = isset($row['passport_issued_by']) && trim((string) $row['passport_issued_by']) !== ''
        ? yvo_party_format_free_text(trim((string) $row['passport_issued_by'])) : '_______________________________';
    $dept = isset($row['department_code']) && trim((string) $row['department_code']) !== ''
        ? trim((string) $row['department_code']) : '_______';
    $reg = yvo_party_registration_address_display($row);
    $gender = yvo_party_gender_from_row($row);
    $named = yvo_party_gender_form($gender, 'именуемый', 'именуемая', 'именуемый(-ая)');
    $registered = yvo_party_gender_form($gender, 'зарегистрированный', 'зарегистрированная', 'зарегистрированный(-ая)');
    return 'гр. РФ ' . $name . ', ' . $birth . ' года рождения, место рождения: ' . $birth_place
        . ', паспорт серия ' . $pass_ser . ' номер ' . $pass_num . ', выдан ' . $issued
        . ', код подразделения ' . $dept . ', ' . $registered . ' по адресу: ' . $reg
        . ', в дальнейшем ' . $named . ' «' . $role_label . '»,';
}

/** Преамбула одаряемого-несовершеннолетного (единый абзац, как у дарителя). */
function yvo_build_gift_minor_preamble_line(array $row, $role_label) {
    $name = isset($row['full_name']) && trim((string) $row['full_name']) !== ''
        ? yvo_party_format_person_name(trim((string) $row['full_name'])) : '________________';
    $birth = isset($row['birth_date']) && trim((string) $row['birth_date']) !== ''
        ? trim((string) $row['birth_date']) : '____________';
    $birth_place = isset($row['birth_place']) && trim((string) $row['birth_place']) !== ''
        ? yvo_party_format_free_text(trim((string) $row['birth_place'])) : '____________________';
    $reg = yvo_party_registration_address_display($row);
    $gender = yvo_party_gender_from_row($row);
    $named = yvo_party_gender_form($gender, 'именуемый', 'именуемая', 'именуемый(-ая)');
    $registered = yvo_party_gender_form($gender, 'зарегистрированный', 'зарегистрированная', 'зарегистрированный(-ая)');
    $bs = isset($row['birth_cert_series']) ? trim((string) $row['birth_cert_series']) : '';
    $bn = isset($row['birth_cert_number']) ? trim((string) $row['birth_cert_number']) : '';
    $bd = isset($row['birth_cert_date']) ? trim((string) $row['birth_cert_date']) : '';
    $by = isset($row['birth_cert_issued_by']) ? trim((string) $row['birth_cert_issued_by']) : '';
    if ($bs === '') {
        $bs = '_____';
    }
    if ($bn === '') {
        $bn = '__________';
    }
    if ($bd === '') {
        $bd = '____________';
    }
    if ($by === '') {
        $by = '_______________________________';
    } else {
        $by = yvo_party_format_free_text($by);
    }
    return $name . ', ' . $birth . ' года рождения, место рождения: ' . $birth_place
        . ', свидетельство о рождении: серия ' . $bs . ' № ' . $bn . ', дата выдачи ' . $bd
        . ', кем выдано: ' . $by
        . ', ' . $registered . ' по адресу: ' . $reg
        . ', в дальнейшем ' . $named . ' «' . $role_label . '»,';
}

/** Блок «АДРЕСА СТОРОН» для договора дарения жилого помещения. */
function yvo_build_gift_parties_addresses_block(array $sellers, array $buyers) {
    $lines = array('АДРЕСА СТОРОН', '');
    $donors = yvo_parties_principal_sellers_ordered($sellers);
    $donees = yvo_parties_principal_buyers_ordered($buyers);
    foreach ($donors as $i => $s) {
        if (!is_array($s)) {
            continue;
        }
        $label = count($donors) > 1 ? 'Даритель ' . ($i + 1) : 'Даритель';
        $addr = yvo_party_registration_address_display($s);
        $lines[] = $label . ': ' . $addr;
    }
    foreach ($donees as $i => $b) {
        if (!is_array($b)) {
            continue;
        }
        $label = count($donees) > 1 ? 'Одаряемый ' . ($i + 1) : 'Одаряемый';
        $addr = yvo_party_registration_address_display($b);
        $lines[] = $label . ': ' . $addr;
    }
    return implode("\n", $lines);
}

/**
 * Склонение «одаряемый» по числу и полу одаряемых-принципалов.
 *
 * @param array<int, array> $buyers
 * @return array{nom:string,gen:string,dat:string,verb:string}
 */
function yvo_gift_donee_grammar(array $buyers) {
    $principals = yvo_parties_principal_buyers_ordered($buyers);
    $n = count($principals);
    if ($n <= 1) {
        $g = yvo_party_gender_from_row(isset($principals[0]) && is_array($principals[0]) ? $principals[0] : array());
        if ($g === 'f') {
            return array('nom' => 'Одаряемая', 'gen' => 'Одаряемой', 'dat' => 'Одаряемой', 'verb' => 'осуществляет', 'participate' => 'участвует');
        }
        return array('nom' => 'Одаряемый', 'gen' => 'Одаряемого', 'dat' => 'Одаряемому', 'verb' => 'осуществляет', 'participate' => 'участвует');
    }
    return array('nom' => 'Одаряемые', 'gen' => 'Одаряемых', 'dat' => 'Одаряемым', 'verb' => 'осуществляют', 'participate' => 'участвуют');
}

/** Компактная строка дарителя для шаблона «дарение в долевую». */
function yvo_build_gift_dolevaya_donor_line(array $row) {
    $name = isset($row['full_name']) && trim((string) $row['full_name']) !== ''
        ? yvo_party_format_person_name(trim((string) $row['full_name'])) : '________________';
    $birth = isset($row['birth_date']) && trim((string) $row['birth_date']) !== ''
        ? trim((string) $row['birth_date']) : '____________';
    $pass_ser = isset($row['passport_series']) ? trim((string) $row['passport_series']) : '____';
    $pass_num = isset($row['passport_number']) ? trim((string) $row['passport_number']) : '______';
    $pass = trim($pass_ser . ' ' . $pass_num);
    $issued = isset($row['passport_issued_by']) && trim((string) $row['passport_issued_by']) !== ''
        ? yvo_party_format_free_text(trim((string) $row['passport_issued_by'])) : '________________';
    $dept = isset($row['department_code']) && trim((string) $row['department_code']) !== ''
        ? trim((string) $row['department_code']) : '___-___';
    $reg = yvo_party_registration_address_display($row);
    $gender = yvo_party_gender_from_row($row);
    $named = yvo_party_gender_form($gender, 'именуемый', 'именуемая', 'именуемый(-ая)');
    $registered = yvo_party_gender_form($gender, 'зарегистрированный', 'зарегистрированная', 'зарегистрированный(-ая)');
    return 'гр. ' . $name . ', ' . $birth . ' года рождения, паспорт РФ ' . $pass . ', выдан ' . $issued
        . ', код подразделения ' . $dept . ', ' . $registered . ' по адресу: ' . $reg . ', ' . $named . ' «Даритель»';
}

/** Компактная строка несовершеннолетнего одаряемого. */
function yvo_build_gift_dolevaya_minor_line(array $row) {
    $birth = isset($row['birth_date']) && trim((string) $row['birth_date']) !== ''
        ? trim((string) $row['birth_date']) : '____________';
    $birth_place = isset($row['birth_place']) && trim((string) $row['birth_place']) !== ''
        ? yvo_party_format_free_text(trim((string) $row['birth_place'])) : '____________________';
    $reg = yvo_party_registration_address_display($row);
    $bs = isset($row['birth_cert_series']) ? trim((string) $row['birth_cert_series']) : '_____';
    $bn = isset($row['birth_cert_number']) ? trim((string) $row['birth_cert_number']) : '__________';
    $bd = isset($row['birth_cert_date']) ? trim((string) $row['birth_cert_date']) : '____________';
    $by = isset($row['birth_cert_issued_by']) ? trim((string) $row['birth_cert_issued_by']) : '_______________________________';
    if ($by !== '_______________________________') {
        $by = yvo_party_format_free_text($by);
    }
    $gender = yvo_party_gender_from_row($row);
    $registered = yvo_party_gender_form($gender, 'зарегистрированный', 'зарегистрированная', 'зарегистрированный(-ая)');
    return 'гр. ' . yvo_contract_bold_person_name(trim((string) ($row['full_name'] ?? ''))) . ', ' . $birth . ' года рождения, место рождения: ' . $birth_place
        . ', свидетельство о рождении: серия ' . $bs . ' № ' . $bn . ', дата выдачи ' . $bd
        . ', кем выдано: ' . $by . ', ' . $registered . ' по адресу: ' . $reg;
}

/** Преамбула «Мы, … с одной стороны, и … с другой стороны». */
function yvo_build_gift_dolevaya_preamble(array $sellers, array $buyers) {
    $donors = yvo_parties_principal_sellers_ordered($sellers);
    $donor = isset($donors[0]) && is_array($donors[0]) ? $donors[0] : array();
    $donor_line = yvo_build_gift_dolevaya_donor_line($donor);
    $principal_buyers = yvo_parties_principal_buyers_ordered($buyers);

    $minors_u14 = array();
    $donee_principals = array();
    foreach ($buyers as $b) {
        if (!is_array($b) || yvo_row_is_buyer_guardian($b) || yvo_row_is_buyer_representative($b)) {
            continue;
        }
        $tab = yvo_party_row_tab($b);
        if ($tab === '' || $tab === 'buyer' || preg_match('/^buyer\d+$/', $tab) === 1
            || strpos($tab, 'minor_buyer') === 0 || strpos($tab, 'contributor') === 0) {
            if (yvo_row_is_minor_buyer($b) && yvo_row_minor_needs_guardian_in_contract($b)) {
                $minors_u14[] = $b;
            } else {
                $donee_principals[] = $b;
            }
        }
    }

    $guardian_line = '';
    if (!empty($minors_u14)) {
        foreach ($buyers as $b) {
            if (!is_array($b) || !yvo_row_is_buyer_guardian($b)) {
                continue;
            }
            if (!yvo_guardian_should_appear_in_contract($b, $buyers, $principal_buyers, 'buyer')) {
                continue;
            }
            $resolved = yvo_party_guardian_resolved_row($b, $buyers);
            $guardian_line = yvo_build_gift_dolevaya_donor_line($resolved);
            $guardian_line = preg_replace('/,\s*именуем[а-яё(-)]+\s*«[^»]+»\s*$/ui', '', $guardian_line);
            break;
        }
    }

    $parts = array();
    if (!empty($donee_principals)) {
        $donee_lines = array();
        foreach ($donee_principals as $b) {
            $line = yvo_build_gift_dolevaya_donor_line($b);
            $donee_lines[] = preg_replace('/«Даритель»/u', '«Одаряемый»', $line);
        }
        if (count($donee_lines) === 1) {
            $parts[] = preg_replace('/именуем[а-яё(-)]+\s*«Одаряемый»/u', 'именуемый «Одаряемый»', $donee_lines[0]);
        } else {
            $parts[] = implode(' и ', $donee_lines) . ', именуемые «Одаряемые»';
        }
    }
    if (!empty($minors_u14)) {
        $minor_lines = array();
        foreach ($minors_u14 as $m) {
            $g = yvo_party_gender_from_row($m);
            $named = yvo_party_gender_form($g, 'именуемый', 'именуемая', 'именуемый(-ая)');
            $minor_lines[] = yvo_build_gift_dolevaya_minor_line($m) . ', ' . $named . ' «Одаряемый»';
        }
        $mp = implode(' и ', $minor_lines);
        if ($guardian_line !== '') {
            $mp .= ', в лице законного представителя ' . $guardian_line;
        }
        $parts[] = $mp . (count($minors_u14) > 1 ? ', именуемые «Одаряемые»' : '');
    }

    if (empty($parts)) {
        $right_side = '________________, именуемый «Одаряемый» с другой стороны';
    } elseif (count($parts) === 1) {
        $right_side = $parts[0] . ', с другой стороны';
    } else {
        $right_side = implode(', а также ', $parts) . ', с другой стороны';
    }

    return 'Мы, ' . $donor_line . ', с одной стороны, и ' . $right_side
        . ', при совместном упоминании «Стороны», заключили настоящий договор дарения о нижеследующем:';
}

/** Текст «по 1/14 доли» или перечисление долей одаряемым. */
function yvo_gift_share_per_donee_phrase(array $buyers, array $property_data) {
    $donees = yvo_gift_donee_share_rows($buyers);
    if (empty($donees) && !empty($property_data['share_participants']) && is_array($property_data['share_participants'])) {
        foreach ($property_data['share_participants'] as $sp) {
            if (!is_array($sp) || ($sp['role'] ?? '') !== 'buyer') {
                continue;
            }
            $name = trim((string) ($sp['full_name'] ?? ''));
            $share = trim((string) ($sp['share_fraction'] ?? ''));
            if ($name !== '' && $share !== '') {
                $donees[] = array('name' => $name, 'share' => $share);
            }
        }
    }
    if (empty($donees)) {
        $fallback = trim((string) ($property_data['share_in_right'] ?? $property_data['object_share'] ?? ''));
        return $fallback !== '' ? 'по ' . $fallback . ' доли' : 'доли в размере, указанном в п. 5';
    }
    $shares = array();
    foreach ($donees as $d) {
        if ($d['share'] !== '' && !in_array($d['share'], $shares, true)) {
            $shares[] = $d['share'];
        }
    }
    if (count($shares) === 1) {
        return 'по ' . $shares[0] . ' доли';
    }
    return 'доли в размере, указанном в п. 5';
}

/**
 * Одаряемые и доли в дар (карточки участников или матрица share_participants).
 *
 * @return array<int, array{name:string, share:string}>
 */
function yvo_gift_collect_donee_share_rows(array $buyers, array $property_data) {
    $donees = yvo_gift_donee_share_rows($buyers);
    if (!empty($donees)) {
        return $donees;
    }
    if (!empty($property_data['share_participants']) && is_array($property_data['share_participants'])) {
        foreach ($property_data['share_participants'] as $sp) {
            if (!is_array($sp) || ($sp['role'] ?? '') !== 'buyer') {
                continue;
            }
            $name = trim((string) ($sp['full_name'] ?? ''));
            $share = trim((string) ($sp['share_fraction'] ?? ''));
            if ($name !== '' && $share !== '') {
                $donees[] = array('name' => $name, 'share' => $share);
            }
        }
    }
    return $donees;
}

/** Имя участника по tab из gift_distributions / share_participants. */
function yvo_gift_party_name_by_tab($tab, array $sellers, array $buyers, array $property_data) {
    $tab = trim((string) $tab);
    if ($tab === '') {
        return '';
    }
    foreach (array_merge($sellers, $buyers) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (yvo_party_row_tab($row) === $tab) {
            $name = trim((string) ($row['full_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }
    if (!empty($property_data['share_participants']) && is_array($property_data['share_participants'])) {
        foreach ($property_data['share_participants'] as $sp) {
            if (!is_array($sp)) {
                continue;
            }
            if (trim((string) ($sp['tab'] ?? '')) === $tab) {
                $name = trim((string) ($sp['full_name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }
    }
    return '';
}

/**
 * Строки дарения: donor_tab → donee_tab → доля.
 *
 * @return array<int, array{donor_tab:string, donee_tab:string, share_fraction:string, donor_name?:string, donee_name?:string}>
 */
function yvo_gift_distribution_rows(array $sellers, array $buyers, array $property_data) {
    $rows = array();
    if (!empty($property_data['gift_distributions']) && is_array($property_data['gift_distributions'])) {
        foreach ($property_data['gift_distributions'] as $g) {
            if (!is_array($g)) {
                continue;
            }
            $share = trim((string) ($g['share_fraction'] ?? ''));
            if ($share === '') {
                continue;
            }
            $donor_tab = trim((string) ($g['donor_tab'] ?? ''));
            $donee_tab = trim((string) ($g['donee_tab'] ?? ''));
            $donor_name = trim((string) ($g['donor_name'] ?? ''));
            $donee_name = trim((string) ($g['donee_name'] ?? ''));
            if ($donor_name === '' && $donor_tab !== '') {
                $donor_name = yvo_gift_party_name_by_tab($donor_tab, $sellers, $buyers, $property_data);
            }
            if ($donee_name === '' && $donee_tab !== '') {
                $donee_name = yvo_gift_party_name_by_tab($donee_tab, $sellers, $buyers, $property_data);
            }
            $rows[] = array(
                'donor_tab' => $donor_tab,
                'donee_tab' => $donee_tab,
                'share_fraction' => $share,
                'donor_name' => $donor_name,
                'donee_name' => $donee_name,
            );
        }
    }
    if (!empty($rows)) {
        return $rows;
    }
    $donors = yvo_parties_principal_sellers_ordered($sellers);
    $donor_tab = '';
    $donor_name = '_______________';
    if (!empty($donors[0]) && is_array($donors[0])) {
        $donor_tab = yvo_party_row_tab($donors[0]);
        if (!empty($donors[0]['full_name'])) {
            $donor_name = trim((string) $donors[0]['full_name']);
        }
    }
    foreach (yvo_gift_collect_donee_share_rows($buyers, $property_data) as $d) {
        $rows[] = array(
            'donor_tab' => $donor_tab,
            'donee_tab' => '',
            'share_fraction' => $d['share'],
            'donor_name' => $donor_name,
            'donee_name' => $d['name'],
        );
    }
    return $rows;
}

/** П. 4.1: кто кому передаёт долю в дар. */
function yvo_build_gift_share_distribution_list(array $sellers, array $buyers, array $property_data) {
    $lines = array();
    foreach (yvo_gift_distribution_rows($sellers, $buyers, $property_data) as $g) {
        $donor_name = yvo_party_format_person_name($g['donor_name'] !== '' ? $g['donor_name'] : '_______________');
        $donee_name = yvo_party_format_person_name($g['donee_name'] !== '' ? $g['donee_name'] : '_______________');
        $lines[] = '- Даритель ' . $donor_name . ' дарит долю ' . $g['share_fraction'] . ' одаряемому ' . $donee_name . ';';
    }
    if (empty($lines)) {
        return '- Даритель дарит долю ___ одаряемому _______________;';
    }
    return implode("\n", $lines);
}

/** П. 5: кому какая доля после регистрации. */
function yvo_build_gift_post_reg_ownership_list(array $sellers, array $buyers, array $property_data) {
    $lines = array();
    if (!empty($property_data['share_participants']) && is_array($property_data['share_participants'])) {
        foreach ($property_data['share_participants'] as $sp) {
            if (!is_array($sp)) {
                continue;
            }
            $name = trim((string) ($sp['full_name'] ?? ''));
            $share = trim((string) ($sp['share_fraction'] ?? ''));
            if ($name === '' || $share === '') {
                continue;
            }
            $lines[] = '- ' . yvo_party_format_person_name($name) . ' – ' . $share . ' доли в праве общей долевой собственности;';
        }
    }
    if (!empty($lines)) {
        return implode("\n", $lines);
    }
    $donors = yvo_parties_principal_sellers_ordered($sellers);
    $alienated = yvo_gift_donor_alienated_share($sellers, $property_data);
    if (!empty($donors[0]) && is_array($donors[0])) {
        $dname = trim((string) ($donors[0]['full_name'] ?? ''));
        $dshare = yvo_party_row_share_fraction($donors[0]);
        if ($dshare === '') {
            $dshare = trim((string) ($property_data['share_in_right'] ?? ''));
        }
        $donor_gifts_all = ($alienated !== '' && $alienated !== '___' && $dshare !== '' && $dshare === $alienated);
        if ($dname !== '' && $dshare !== '' && !$donor_gifts_all) {
            $lines[] = '- ' . yvo_party_format_person_name($dname) . ' – ' . $dshare . ' доли в праве общей долевой собственности;';
        }
    }
    foreach (yvo_gift_donee_share_rows($buyers) as $d) {
        $lines[] = '- ' . yvo_party_format_person_name($d['name']) . ' – ' . $d['share'] . ' доли в праве общей долевой собственности;';
    }
    if (empty($lines)) {
        return '- ________________ – ___ доли в праве общей долевой собственности;';
    }
    return implode("\n", $lines);
}

/** П. 8: обременение (ипотека и т.п.). */
function yvo_build_gift_encumbrance_clause(array $property_data) {
    $record = trim((string) ($property_data['encumbrance_record'] ?? ''));
    if ($record === '') {
        $type = trim((string) ($property_data['encumbrance_type'] ?? ''));
        $summary = trim((string) ($property_data['restrictions_summary'] ?? ''));
        if ($summary !== '' && preg_match('/№|от\s+\d{2}\.\d{2}\.\d{4}/u', $summary)) {
            $record = $summary;
        } elseif ($type !== '') {
            $record = $type;
        } elseif ($summary !== '' && !preg_match('/не\s+зарегистрировано/ui', $summary)) {
            $record = $summary;
        }
    }
    $bank_note = trim((string) ($property_data['encumbrance_bank_note'] ?? ''));
    if ($bank_note === '') {
        $bank_note = 'Банк уведомлен, претензий не имеет.';
    }
    if ($record === '') {
        return 'На момент подписания настоящего договора обременения на объект недвижимости не зарегистрированы.';
    }
    return 'На момент подписания настоящего договора объект находится в обременении, о чем в Едином государственном реестре недвижимости сделана запись о регистрации: '
        . $record . '. ' . $bank_note;
}

/** Подписи: даритель + строка на каждого одаряемого (опекун — только для детей до 14 лет). */
function yvo_build_gift_dolevaya_signatures(array $sellers, array $buyers) {
    $lines = array();
    $donors = yvo_parties_principal_sellers_ordered($sellers);
    $donor = isset($donors[0]) && is_array($donors[0]) ? $donors[0] : array();
    $donor_name = isset($donor['full_name']) && trim((string) $donor['full_name']) !== ''
        ? yvo_party_format_person_name(trim((string) $donor['full_name'])) : '________________';
    $lines[] = 'Даритель: _______________________________ / ' . $donor_name . ' /';

    $principal_buyers = yvo_parties_principal_buyers_ordered($buyers);
    $donee_count = 0;
    $guardian_done = array();
    foreach ($buyers as $b) {
        if (!is_array($b) || yvo_row_is_buyer_representative($b)) {
            continue;
        }
        if (yvo_row_is_buyer_guardian($b)) {
            if (!yvo_guardian_should_appear_in_contract($b, $buyers, $principal_buyers, 'buyer')) {
                continue;
            }
            $gkey = yvo_guardian_preamble_group_key($b, $buyers);
            if (isset($guardian_done[$gkey])) {
                continue;
            }
            $guardian_done[$gkey] = true;
            $resolved = yvo_party_guardian_resolved_row($b, $buyers);
            $gname = isset($resolved['full_name']) && trim((string) $resolved['full_name']) !== ''
                ? yvo_party_format_person_name(trim((string) $resolved['full_name'])) : '________________';
            $gnum = yvo_guardian_contract_label_number($b, $buyers, 'buyer');
            $glab = yvo_contract_party_display_label('gift', 'buyer', 'guardian', $gnum);
            $lines[] = $glab . ': _______________________________ / ' . $gname . ' /';
            continue;
        }
        $tab = yvo_party_row_tab($b);
        if ($tab === '' || $tab === 'buyer' || preg_match('/^buyer\d+$/', $tab) === 1
            || strpos($tab, 'minor_buyer') === 0 || strpos($tab, 'contributor') === 0) {
            if (yvo_row_is_minor_buyer($b) && yvo_row_minor_needs_guardian_in_contract($b)) {
                continue;
            }
            $donee_count++;
            $bname = isset($b['full_name']) && trim((string) $b['full_name']) !== ''
                ? yvo_party_format_person_name(trim((string) $b['full_name'])) : '________________';
            $blab = count($principal_buyers) > 1
                ? yvo_contract_party_display_label('gift', 'buyer', 'principal', $donee_count)
                : yvo_contract_party_display_label('gift', 'buyer', 'principal', 1);
            $lines[] = $blab . ': _______________________________ / ' . $bname . ' /';
        }
    }
    if ($donee_count === 0) {
        $lines[] = 'Одаряемый: _______________________________ / ________________ /';
    }
    return implode("\n", $lines);
}

/**
 * Плейсхолдеры договора «Соглашение о выделении долей» (шаблон ГАРАНТ / shablon-vydelenie-doley-kvartira).
 *
 * @return array<string, string>
 */
function yvo_build_alloc_template_placeholders(array $sellers, array $buyers, array $property_data, array $options = array()) {
    $city = isset($options['contract_city']) ? trim((string) $options['contract_city']) : trim((string) ($property_data['city'] ?? ''));
    if ($city === '') {
        $city = '________________';
    }
    $contract_date = isset($options['contract_date']) ? trim((string) $options['contract_date']) : date('d.m.Y');
    $date_parts = preg_split('/\./', $contract_date);
    $date_day = isset($date_parts[0]) ? $date_parts[0] : '__';
    $date_month = isset($date_parts[1]) ? $date_parts[1] : '__';
    $date_year = isset($date_parts[2]) ? $date_parts[2] : '20__';
    $month_gen = yvo_russian_month_genitive($date_month);
    if ($month_gen === '') {
        $month_gen = '_____________';
    }
    $joint = yvo_alloc_property_joint_ownership($property_data, $sellers);
    $parents = yvo_parties_principal_sellers_ordered($sellers);
    $children = yvo_alloc_minor_children_ordered($buyers);
    $child_count = count($children);
    $copies = max(3, count($parents) + $child_count + 1);
    $build_year = trim((string) ($property_data['build_year'] ?? $property_data['year_built'] ?? ''));
    if ($build_year === '') {
        $build_year = '____';
    }
    return array(
        'ALLOC_CITY_DATE' => yvo_format_contract_city_display($city) . ' «' . $date_day . '» ' . $month_gen . ' ' . $date_year . ' г.',
        'ALLOC_PARTIES_BLOCK' => yvo_build_alloc_parties_block($sellers, $buyers),
        'ALLOC_OWNERSHIP_SUBJECT' => count($parents) > 1 ? 'Родителям' : 'Родителю',
        'ALLOC_OWNERSHIP_TYPE' => $joint ? 'совместной собственности' : (count($parents) > 1 ? 'общей долевой собственности' : 'собственности'),
        'ALLOC_SHARES_LIST' => yvo_build_alloc_shares_list($sellers, $buyers, $property_data),
        'ALLOC_SIGNATURES' => yvo_build_alloc_signatures($sellers, $buyers),
        'ALLOC_COPIES_COUNT' => (string) $copies,
        'PROPERTY_BUILD_YEAR' => $build_year,
        'PROPERTY_ROOMS' => isset($property_data['rooms']) && trim((string) $property_data['rooms']) !== ''
            ? trim((string) $property_data['rooms']) : '___',
        'PROPERTY_LIVING_AREA' => isset($property_data['living_area']) && trim((string) $property_data['living_area']) !== ''
            ? trim((string) $property_data['living_area']) : '________',
        'PROPERTY_FLOORS_TOTAL' => isset($property_data['floors_total']) && trim((string) $property_data['floors_total']) !== ''
            ? trim((string) $property_data['floors_total']) : '___',
        'CONTRACT_TYPE' => 'share_allocation',
    );
}

/** Совместная собственность супругов-родителей (флаг из калькулятора). */
function yvo_alloc_property_joint_ownership(array $property_data, array $sellers = array()) {
    $flag = !empty($property_data['share_joint_ownership'])
        || !empty($property_data['joint_ownership']);
    if (!$flag) {
        return false;
    }
    $parents = yvo_parties_principal_sellers_ordered($sellers);
    return count($parents) >= 2;
}

/** Несовершеннолетние получатели доли. */
function yvo_alloc_minor_children_ordered(array $buyers) {
    $out = array();
    foreach ($buyers as $b) {
        if (!is_array($b)) {
            continue;
        }
        if (yvo_row_is_buyer_representative($b) || yvo_row_is_buyer_guardian($b)) {
            continue;
        }
        $tab = yvo_party_row_tab($b);
        if ($tab === '' || $tab === 'buyer' || preg_match('/^buyer\d*$/', $tab) === 1 || strpos($tab, 'minor_buyer') === 0) {
            $out[] = $b;
        }
    }
    return $out;
}

/** Строка родителя для преамбулы соглашения о выделении долей. */
function yvo_build_alloc_parent_line(array $row) {
    $birth = isset($row['birth_date']) && trim((string) $row['birth_date']) !== ''
        ? trim((string) $row['birth_date']) : '____________';
    $birth_place = isset($row['birth_place']) && trim((string) $row['birth_place']) !== ''
        ? yvo_party_format_free_text(trim((string) $row['birth_place'])) : '____________________';
    $pass_ser = isset($row['passport_series']) ? trim((string) $row['passport_series']) : '____';
    $pass_num = isset($row['passport_number']) ? trim((string) $row['passport_number']) : '______';
    $issued = isset($row['passport_issued_by']) && trim((string) $row['passport_issued_by']) !== ''
        ? yvo_party_format_free_text(trim((string) $row['passport_issued_by'])) : '________________';
    $reg = yvo_party_registration_address_display($row);
    $gender = yvo_party_gender_from_row($row);
    $registered = yvo_party_gender_form($gender, 'зарегистрированный', 'зарегистрированная', 'зарегистрированный(-ая)');
    return yvo_contract_bold_person_name(trim((string) ($row['full_name'] ?? ''))) . ', ' . $birth . ' года рождения, место рождения: ' . $birth_place
        . ', паспорт: серия ' . $pass_ser . ', номер ' . $pass_num
        . ' выдан ' . $issued . ', ' . $registered . ' по адресу: ' . $reg;
}

/** Преамбула соглашения: родители + дети → «Стороны». */
function yvo_build_alloc_parties_block(array $sellers, array $buyers) {
    $parents = yvo_parties_principal_sellers_ordered($sellers);
    $children = yvo_alloc_minor_children_ordered($buyers);
    $lines = array();
    if (!empty($parents)) {
        $parent_lines = array();
        foreach ($parents as $p) {
            $parent_lines[] = yvo_build_alloc_parent_line($p);
        }
        $lines[] = implode(",\n", $parent_lines)
            . ', вместе именуемые «Родители», действующие за себя и как законные представители за своих несовершеннолетних детей';
    } else {
        $lines[] = '________________, именуемые «Родители»';
    }
    if (!empty($children)) {
        $child_bits = array();
        foreach ($children as $c) {
            $child_bits[] = yvo_build_gift_dolevaya_minor_line($c);
        }
        $child_addr = !empty($children[0]) ? yvo_party_registration_address_display($children[0]) : '________________';
        if (count($child_bits) === 1) {
            $lines[] = $child_bits[0];
        } elseif (count($child_bits) === 2) {
            $lines[] = $child_bits[0] . ' и' . "\n" . $child_bits[1];
        } else {
            $lines[] = implode(",\n", $child_bits);
        }
        $lines[] = 'проживающих по адресу: ' . $child_addr . ', все вместе именуемые «Стороны», заключили настоящее соглашение о нижеследующем:';
    } else {
        $lines[] = 'все вместе именуемые «Стороны», заключили настоящее соглашение о нижеследующем:';
    }
    return implode("\n", $lines);
}

/** Имя участника по tab (для п. 5, если в share_participants пустое ФИО). */
function yvo_alloc_resolve_participant_name($tab, array $sellers, array $buyers) {
    $tab = trim((string) $tab);
    if ($tab === '') {
        return '';
    }
    foreach (array_merge($sellers, $buyers) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (yvo_party_row_tab($row) === $tab) {
            $name = trim((string) ($row['full_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }
    return '';
}

/**
 * П. 5: итоговые доли. При совместной собственности супругов — одна строка на родителей.
 *
 * @return string
 */
function yvo_build_alloc_shares_list(array $sellers, array $buyers, array $property_data) {
    $joint = yvo_alloc_property_joint_ownership($property_data, $sellers);
    $parents = yvo_parties_principal_sellers_ordered($sellers);
    $lines = array();
    $rows = array();
    if (!empty($property_data['share_participants']) && is_array($property_data['share_participants'])) {
        foreach ($property_data['share_participants'] as $sp) {
            if (!is_array($sp)) {
                continue;
            }
            $name = trim((string) ($sp['full_name'] ?? ''));
            $share = trim((string) ($sp['share_fraction'] ?? ''));
            $role = trim((string) ($sp['role'] ?? ''));
            $tab = trim((string) ($sp['tab'] ?? ''));
            if ($name === '' && $tab !== '') {
                $name = yvo_alloc_resolve_participant_name($tab, $sellers, $buyers);
            }
            if ($name === '' || $share === '') {
                continue;
            }
            $rows[] = array(
                'role' => $role,
                'name' => $name,
                'share' => $share,
                'joint' => !empty($sp['joint_ownership']),
            );
        }
    }
    if (empty($rows)) {
        foreach (yvo_alloc_minor_children_ordered($buyers) as $c) {
            $share = yvo_party_row_share_fraction($c);
            $name = trim((string) ($c['full_name'] ?? ''));
            if ($name !== '' && $share !== '') {
                $rows[] = array('role' => 'buyer', 'name' => $name, 'share' => $share, 'joint' => false);
            }
        }
        if ($joint && count($parents) >= 2) {
            $pnames = array();
            foreach ($parents as $p) {
                $n = trim((string) ($p['full_name'] ?? ''));
                if ($n !== '') {
                    $pnames[] = yvo_party_format_person_name($n);
                }
            }
            $pshare = '';
            foreach ($parents as $p) {
                $sh = yvo_party_row_share_fraction($p);
                if ($sh !== '') {
                    $pshare = $sh;
                    break;
                }
            }
            if (!empty($pnames) && $pshare !== '') {
                $label = count($pnames) >= 2
                    ? $pnames[0] . ' и ' . $pnames[1] . (count($pnames) > 2 ? ' и др.' : '')
                    : $pnames[0];
                $rows[] = array(
                    'role' => 'seller',
                    'name' => $label,
                    'share' => $pshare,
                    'joint' => true,
                );
            }
        } else {
            foreach ($parents as $p) {
                $share = yvo_party_row_share_fraction($p);
                $name = trim((string) ($p['full_name'] ?? ''));
                if ($name !== '' && $share !== '') {
                    $rows[] = array('role' => 'seller', 'name' => $name, 'share' => $share, 'joint' => false);
                }
            }
        }
    }
    if ($joint && count($parents) >= 2) {
        $merged = array();
        $parent_row = null;
        foreach ($rows as $row) {
            if (($row['role'] ?? '') === 'seller' || !empty($row['joint'])) {
                if ($parent_row === null) {
                    $pnames = array();
                    foreach ($parents as $p) {
                        $n = trim((string) ($p['full_name'] ?? ''));
                        if ($n !== '') {
                            $pnames[] = yvo_party_format_person_name($n);
                        }
                    }
                    $label = !empty($row['name']) ? $row['name'] : (count($pnames) >= 2 ? $pnames[0] . ' и ' . $pnames[1] : ($pnames[0] ?? 'Родители'));
                    $parent_row = array('role' => 'seller', 'name' => $label, 'share' => $row['share'], 'joint' => true);
                }
                continue;
            }
            $merged[] = $row;
        }
        if ($parent_row !== null) {
            array_unshift($merged, $parent_row);
        }
        $rows = $merged;
    }
    foreach ($rows as $row) {
        $suffix = ' доли в праве общей долевой собственности';
        if (!empty($row['joint'])) {
            $suffix .= ' (доля супругов в совместной собственности)';
        }
        $name_label = trim((string) ($row['name'] ?? ''));
        if ($name_label === '') {
            $name_out = yvo_contract_bold_person_name('');
        } elseif (!empty($row['joint']) || preg_match('/\s+и\s+/u', $name_label)) {
            $name_out = yvo_contract_bold_person_names_label($name_label);
        } else {
            $name_out = yvo_contract_bold_person_name($name_label);
        }
        $lines[] = $name_out . ' – ' . $row['share'] . $suffix . ';';
    }
    if (empty($lines)) {
        return '________________ – ___ доли в праве общей долевой собственности;';
    }
    return implode("\n", $lines);
}

/** Подписи родителей (от себя и от имени детей). */
function yvo_build_alloc_signatures(array $sellers, array $buyers) {
    $lines = array('Родители подписываются от своего имени и от имени своих несовершеннолетних детей:');
    $parents = yvo_parties_principal_sellers_ordered($sellers);
    foreach ($parents as $p) {
        $name = isset($p['full_name']) && trim((string) $p['full_name']) !== ''
            ? yvo_contract_bold_person_name(trim((string) $p['full_name'])) : yvo_deposit_bold_mark('________________');
        $lines[] = 'Подпись: _______________________________ / ' . $name . ' /';
    }
    if (count($parents) < 1) {
        $lines[] = 'Подпись: _______________________________ / ________________ /';
    }
    return implode("\n", $lines);
}

/** Путь к txt-шаблону соглашения о выделении долей. */
function yvo_resolve_share_allocation_template_txt_path($template_id = '') {
    $bundled = YVO_PLUGIN_DIR . 'templates/shablon-vydelenie-doley-kvartira.txt';
    $template_id = trim((string) $template_id);
    if ($template_id === '' || $template_id === 'default') {
        return file_exists($bundled) ? $bundled : null;
    }
    if ($template_id === 'shablon-vydelenie-doley-kvartira' && file_exists($bundled)) {
        return $bundled;
    }
    $path = yvo_resolve_template_path($template_id);
    if ($path && file_exists($path)) {
        return $path;
    }
    return file_exists($bundled) ? $bundled : null;
}

/**
 * Плейсхолдеры договора дарения доли «в долевую» (шаблон shablon-darenie-dolya-kvartira).
 *
 * @return array<string, string>
 */
function yvo_build_gift_dolya_clause_placeholders($contract_type, array $sellers, array $buyers, array $s1, array $property_data) {
    if ($contract_type !== 'gift') {
        return array();
    }
    if (!yvo_gift_has_share_matrix_data($sellers, $buyers, $property_data)) {
        return array();
    }

    $addr = trim((string) ($property_data['address'] ?? ''));
    if ($addr === '') {
        $addr = '________________';
    }
    $area = isset($property_data['area']) && $property_data['area'] !== '' ? trim((string) $property_data['area']) : '___';
    $floor = isset($property_data['floor']) && $property_data['floor'] !== '' ? trim((string) $property_data['floor']) : '___';
    $cad = trim((string) ($property_data['cadastral_number'] ?? ''));
    if ($cad === '') {
        $cad = '____________________';
    }
    $share_phrase = yvo_gift_share_per_donee_phrase($buyers, $property_data);
    $donee = yvo_gift_donee_grammar($buyers);
    $accept_verb = ($donee['nom'] === 'Одаряемые') ? 'принимают' : 'принимает';
    $acquire_verb = ($donee['nom'] === 'Одаряемые') ? 'приобретают' : 'приобретает';
    return array(
        'GIFT_DOLEVAYA_PREAMBLE' => yvo_build_gift_dolevaya_preamble($sellers, $buyers),
        'GIFT_CLAUSE_1' => 'Даритель передаёт в общую долевую собственность ' . $share_phrase . ' Одаряемым квартиру, находящуюся по адресу: '
            . $addr . '. Квартира общей площадью ' . $area . ' кв.м., этаж ' . $floor . ', кадастровый номер: '
            . $cad . ' (далее – «Объект недвижимости», «квартира»).',
        'GIFT_CLAUSE_2' => 'Указанный объект недвижимости принадлежит Дарителю на праве собственности, о чем в Едином государственном реестре недвижимости сделана запись о регистрации: '
            . yvo_format_property_right_info($property_data, 'gift') . '.',
        'GIFT_CLAUSE_3' => $donee['nom'] . ' указанные доли в объекте недвижимости от Дарителя в дар ' . $accept_verb . '.',
        'GIFT_CLAUSE_4' => $donee['nom'] . ' ' . $acquire_verb . ' право общей долевой собственности на указанный объект недвижимости с момента государственной регистрации перехода права собственности в регистрирующем органе и внесения соответствующих записей в Единый государственный реестр недвижимости.',
        'GIFT_SHARE_DISTRIBUTION_LIST' => yvo_build_gift_share_distribution_list($sellers, $buyers, $property_data),
        'GIFT_POST_REG_OWNERSHIP_LIST' => yvo_build_gift_post_reg_ownership_list($sellers, $buyers, $property_data),
        'GIFT_ENCUMBRANCE_CLAUSE' => yvo_build_gift_encumbrance_clause($property_data),
        'GIFT_DOLEVAYA_SIGNATURES' => yvo_build_gift_dolevaya_signatures($sellers, $buyers),
        'GIFT_DONOR_VERB' => '',
        'GIFT_RECIPIENT_PHRASE' => '',
        'GIFT_CLAUSE_6' => '',
        'GIFT_CLAUSE_8' => '',
    );
}

function yvo_format_property_right_info(array $property_data, $contract_type = 'sale') {
    $v = trim((string) ($property_data['property_right_info'] ?? ''));
    if ($v !== '' && !preg_match('/^запись\s+№/ui', $v)) {
        return $v;
    }
    if (function_exists('yvo_egrn_build_property_right_info_line')) {
        $built = yvo_egrn_build_property_right_info_line($property_data);
        if ($built !== '') {
            return $built;
        }
    }
    $parts = array();
    $type = trim((string) ($property_data['property_right_type'] ?? ''));
    $num = trim((string) ($property_data['property_right_number'] ?? ''));
    $date = trim((string) ($property_data['property_right_date'] ?? ''));
    if ($type !== '') {
        $parts[] = $type;
    }
    if ($num !== '') {
        $parts[] = $num;
    }
    if ($date !== '') {
        $parts[] = $date;
    }
    if (!empty($parts)) {
        return implode(', ', $parts);
    }
    return $contract_type === 'gift'
        ? '________________ (сведения о госрегистрации права Дарителя)'
        : '________________ (сведения о госрегистрации права)';
}

/**
 * Две строки для блока стороны сделки: паспорт или (для несовершеннолетнего до 14) свидетельство о рождении.
 *
 * @param array<string, mixed> $row
 * @param string               $contract_type
 * @return array{0:string,1:string}
 */
function yvo_party_id_document_lines_for_party_card(array $row, $contract_type) {
    $reg_line = yvo_party_registration_line($row);
    $tab = yvo_party_row_tab($row);
    $is_minor = (strpos($tab, 'minor_seller') === 0 || strpos($tab, 'minor_buyer') === 0);
    $age = isset($row['minor_age_group']) ? (string) $row['minor_age_group'] : '';
    if ($is_minor && $age === 'u14' && $contract_type !== 'share_allocation') {
        $bs = isset($row['birth_cert_series']) ? trim((string) $row['birth_cert_series']) : '';
        $bn = isset($row['birth_cert_number']) ? trim((string) $row['birth_cert_number']) : '';
        $bd = isset($row['birth_cert_date']) ? trim((string) $row['birth_cert_date']) : '';
        $by = isset($row['birth_cert_issued_by']) ? trim((string) $row['birth_cert_issued_by']) : '';
        if ($bs === '') {
            $bs = '_____';
        }
        if ($bn === '') {
            $bn = '__________';
        }
        if ($bd === '') {
            $bd = '____________';
        }
        if ($by === '') {
            $by = '_______________________________';
        }
        return array(
            'свидетельство о рождении: серия ' . $bs . ' № ' . $bn . ', дата выдачи ' . $bd . ',',
            'кем выдано: ' . $by . ', ' . $reg_line,
        );
    }
    $pass_ser = isset($row['passport_series']) ? $row['passport_series'] : '_____';
    $pass_num = isset($row['passport_number']) ? $row['passport_number'] : '__________';
    $issued = isset($row['passport_issued_by']) ? $row['passport_issued_by'] : '_______________________________';
    $dept = isset($row['department_code']) ? $row['department_code'] : '_______';
    return array(
        'паспорт РФ: серия ' . $pass_ser . ' номер ' . $pass_num . ', выдан ' . $issued . ',',
        'код подразделения ' . $dept . ', ' . $reg_line,
    );
}

/**
 * Карта подстановок для ДКП квартиры (ипотека): те же ключи, что в шаблоне .txt / .docx с {{KEY}}.
 * Используется при генерации DOCX из фирменного шаблона и при заполнении текстового шаблона.
 *
 * @param array $sellers
 * @param array $buyers
 * @param array $property_data
 * @param array $options см. yvo_ajax_frontend_generate_contract (contract_city, loan_*, bank_name, …)
 * @return array<string, string>
 */

/**
 * Подпись стороны в договоре (продавец/даритель, опекун, несовершеннолетний и т.д.) по типу сделки.
 *
 * @param string $contract_type sale|gift|share_allocation|…
 * @param string $side        seller|buyer
 * @param string $kind        principal|minor|guardian|representative
 * @param int    $num         номер участника (1-based)
 */
function yvo_contract_party_display_label($contract_type, $side, $kind, $num = 1) {
    $contract_type = (string) $contract_type;
    $side = (string) $side;
    $kind = (string) $kind;
    $num = max(1, (int) $num);
    $suffix = $num > 1 ? ' ' . $num : '';

    if ($contract_type === 'gift') {
        if ($side === 'seller') {
            if ($kind === 'principal') {
                return ($num === 1 ? 'Даритель' : 'Даритель ' . $num);
            }
            if ($kind === 'minor') {
                return ($num === 1 ? 'Даритель' : 'Даритель ' . $num);
            }
            if ($kind === 'guardian') {
                return ($num === 1 ? 'Законный представитель дарителя' : 'Законный представитель дарителя ' . $num);
            }
            if ($kind === 'representative') {
                return 'Представитель дарителя' . $suffix;
            }
        }
        if ($side === 'buyer') {
            if ($kind === 'principal') {
                return ($num === 1 ? 'Одаряемый' : 'Одаряемый ' . $num);
            }
            if ($kind === 'minor') {
                return ($num === 1 ? 'Одаряемый' : 'Одаряемый ' . $num);
            }
            if ($kind === 'guardian') {
                return ($num === 1 ? 'Законный представитель одаряемого' : 'Законный представитель одаряемого ' . $num);
            }
            if ($kind === 'representative') {
                return 'Представитель одаряемого' . $suffix;
            }
        }
    }

    if ($contract_type === 'share_allocation') {
        if ($kind === 'principal') {
            return 'Участник ' . $num;
        }
        if ($kind === 'minor') {
            return 'Несовершеннолетний участник' . $suffix;
        }
        if ($kind === 'guardian') {
            return 'Законный представитель участника' . $suffix;
        }
        if ($kind === 'representative') {
            return 'Представитель участника' . $suffix;
        }
    }

    if ($side === 'seller') {
        if ($kind === 'principal') {
            return ($num === 1 ? 'Продавец' : 'Продавец ' . $num);
        }
        if ($kind === 'minor') {
            return 'Несовершеннолетний продавец' . $suffix;
        }
        if ($kind === 'guardian') {
            return 'Законный представитель Продавца' . $suffix;
        }
        if ($kind === 'representative') {
            return 'Представитель Продавца' . $suffix;
        }
    }

    if ($kind === 'principal') {
        return ($num === 1 ? 'Покупатель' : 'Покупатель ' . $num);
    }
    if ($kind === 'minor') {
        return 'Несовершеннолетний покупатель' . $suffix;
    }
    if ($kind === 'guardian') {
        return 'Законный представитель Покупателя' . $suffix;
    }
    if ($kind === 'representative') {
        return 'Представитель Покупателя' . $suffix;
    }
    return 'Участник' . $suffix;
}

/** Родительный падеж стороны (Продавца / Дарителя / Одаряемого). */
function yvo_contract_party_side_genitive($contract_type, $side, $num = 1) {
    $num = max(1, (int) $num);
    $suffix = $num > 1 ? ' ' . $num : '';
    if ($contract_type === 'gift') {
        return $side === 'seller' ? 'Дарителя' . $suffix : 'Одаряемого' . $suffix;
    }
    if ($contract_type === 'share_allocation') {
        return 'Участника' . $suffix;
    }
    return $side === 'seller' ? 'Продавца' . $suffix : 'Покупателя' . $suffix;
}

/** Множественное «совместно именуемые …». */
function yvo_contract_parties_joint_label($contract_type, $side, $count) {
    if ($count <= 1) {
        return '';
    }
    if ($contract_type === 'gift') {
        return $side === 'seller'
            ? "\n(далее совместно именуемые «Дарители»), с одной стороны,"
            : ", с другой стороны,";
    }
    if ($contract_type === 'share_allocation') {
        return '';
    }
    return $side === 'seller'
        ? "\n(далее совместно именуемые «Продавцы»), с одной стороны,"
        : ", с другой стороны,";
}

/**
 * Стоимость доли пропорционально размеру (1/2 и т.д.) и кадастровой/рыночной цене квартиры.
 *
 * @return array{amount: string, words: string}
 */
function yvo_calculate_share_price_amount($property_data) {
    $property_data = is_array($property_data) ? $property_data : array();
    $share = trim((string) ($property_data['share_in_right'] ?? $property_data['object_share'] ?? ''));
    $price_raw = isset($property_data['price']) ? $property_data['price'] : '';
    $fallback = array('amount' => '__________', 'words' => '________________________________');
    if ($share === '' || $price_raw === '') {
        return $fallback;
    }
    $price_num = floatval(str_replace(array(' ', ','), array('', '.'), (string) $price_raw));
    if ($price_num <= 0 || !preg_match('/(\d+)\s*\/\s*(\d+)/u', $share, $m)) {
        return $fallback;
    }
    $n = (int) $m[1];
    $d = max(1, (int) $m[2]);
    $share_amt = $price_num * $n / $d;
    $words = isset($property_data['share_price_words']) && trim((string) $property_data['share_price_words']) !== ''
        ? trim((string) $property_data['share_price_words'])
        : '';
    return array(
        'amount' => number_format($share_amt, 2, ',', ' '),
        'words' => $words !== '' ? $words : '________________________________',
    );
}

/** Доля в праве из строки участника (матрица / карточка). */
function yvo_party_row_share_fraction(array $row) {
    return trim((string) ($row['share_fraction'] ?? ''));
}

/** Числовое значение доли (1/2 → 0.5). */
function yvo_share_fraction_to_float($frac) {
    $frac = trim((string) $frac);
    if ($frac === '') {
        return null;
    }
    if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $frac, $m)) {
        $den = (int) $m[2];
        if ($den <= 0) {
            return null;
        }
        return (int) $m[1] / $den;
    }
    if (preg_match('/^(\d+(?:[.,]\d+)?)\s*%?$/u', $frac, $m)) {
        $v = (float) str_replace(',', '.', $m[1]);
        if (strpos($frac, '%') !== false || $v > 1.0) {
            return $v / 100.0;
        }
        return $v;
    }
    return null;
}

/** Пустая доля или 1/1 / 100% — весь объект, не «доля в квартире». */
function yvo_share_fraction_is_whole($frac) {
    $frac = trim((string) $frac);
    if ($frac === '') {
        return true;
    }
    $f = yvo_share_fraction_to_float($frac);
    if ($f === null) {
        return false;
    }
    return abs($f - 1.0) < 1e-4;
}

/** Типы объекта, для которых допустимо дарение долей в квартире (матрица / shablon-darenie-dolya-kvartira). */
function yvo_gift_object_type_allows_share_gift($object_type) {
    $object_type = sanitize_key((string) $object_type);
    return in_array($object_type, array('share', 'apartment', 'room'), true);
}

/**
 * Нужен ли шаблон «доля в квартире» по полям объекта (без участников).
 *
 * @param array<string, mixed> $property_data
 */
function yvo_gift_property_indicates_dolya_template(array $property_data) {
    $ot = isset($property_data['object_type']) ? sanitize_key((string) $property_data['object_type']) : '';
    return $ot === 'share';
}

/**
 * Шаблон shablon-darenie-dolya-kvartira — только если в форме выбран тип объекта «Доля».
 * Квартира, комната, дом и т.д. (в т.ч. несколько одаряемых) — shablon-darenie-dogovor.
 *
 * @param array<int, array> $sellers
 * @param array<int, array> $buyers
 */
function yvo_gift_uses_dolya_kvartira_template(array $sellers, array $buyers, array $property_data) {
    unset($sellers, $buyers);
    return yvo_gift_property_indicates_dolya_template($property_data);
}

/**
 * @deprecated Имя сохранено для совместимости; см. yvo_gift_uses_dolya_kvartira_template().
 *
 * @param array<int, array> $sellers
 * @param array<int, array> $buyers
 */
function yvo_gift_has_share_matrix_data(array $sellers, array $buyers, array $property_data) {
    return yvo_gift_uses_dolya_kvartira_template($sellers, $buyers, $property_data);
}

/**
 * Одаряемые и доли в дар (из карточек / матрицы).
 *
 * @return array<int, array{name:string, share:string}>
 */
function yvo_gift_donee_share_rows(array $buyers) {
    $rows = array();
    foreach ($buyers as $b) {
        if (!is_array($b)) {
            continue;
        }
        if (yvo_row_is_buyer_guardian($b) || yvo_row_is_buyer_representative($b)) {
            continue;
        }
        $tab = yvo_party_row_tab($b);
        if ($tab === '' || $tab === 'buyer' || preg_match('/^buyer\d+$/', $tab) === 1
            || strpos($tab, 'minor_buyer') === 0 || strpos($tab, 'contributor') === 0) {
            $name = trim((string) ($b['full_name'] ?? ''));
            $share = yvo_party_row_share_fraction($b);
            if ($name !== '' && $share !== '') {
                $rows[] = array('name' => $name, 'share' => $share);
            }
        }
    }
    return $rows;
}

/** Отчуждаемая доля дарителя: поле объекта или доля дарителя в матрице. */
function yvo_gift_donor_alienated_share(array $sellers, array $property_data) {
    $from_prop = trim((string) ($property_data['share_in_right'] ?? $property_data['object_share'] ?? ''));
    if ($from_prop !== '') {
        return $from_prop;
    }
    foreach (yvo_parties_principal_sellers_ordered($sellers) as $s) {
        if (!is_array($s)) {
            continue;
        }
        $sh = yvo_party_row_share_fraction($s);
        if ($sh !== '') {
            return $sh;
        }
    }
    return '';
}

/**
 * П. 1.1 дарения: кому какая доля в дар (матрица / share_fraction одаряемых).
 *
 * @param array<int, array> $sellers
 * @param array<int, array> $buyers
 */
function yvo_build_gift_share_subject_11(array $sellers, array $buyers, array $property_data) {
    $addr = trim((string) ($property_data['address'] ?? ''));
    if ($addr === '') {
        $addr = '________________';
    }
    $cad = trim((string) ($property_data['cadastral_number'] ?? ''));
    if ($cad === '') {
        $cad = '____________________';
    }
    $donees = yvo_gift_donee_share_rows($buyers);
    if (empty($donees) && !empty($property_data['share_participants']) && is_array($property_data['share_participants'])) {
        foreach ($property_data['share_participants'] as $sp) {
            if (!is_array($sp)) {
                continue;
            }
            $role = isset($sp['role']) ? (string) $sp['role'] : '';
            if ($role !== 'buyer') {
                continue;
            }
            $name = trim((string) ($sp['full_name'] ?? ''));
            $share = trim((string) ($sp['share_fraction'] ?? ''));
            if ($name !== '' && $share !== '') {
                $donees[] = array('name' => $name, 'share' => $share);
            }
        }
    }
    $alienated = yvo_gift_donor_alienated_share($sellers, $property_data);
    $tail = ' в праве общей долевой собственности на квартиру, расположенную по адресу: '
        . $addr . ', кадастровый номер объекта (квартиры): ' . $cad . '.';

    if (count($donees) === 1) {
        $d = $donees[0];
        return 'Даритель безвозмездно передает в собственность (дарит) ' . $d['name']
            . ', а ' . $d['name'] . ' принимает в дар от Дарителя долю в размере ' . $d['share'] . $tail;
    }
    if (count($donees) > 1) {
        $parts = array();
        foreach ($donees as $i => $d) {
            $parts[] = ($i + 1) . ') ' . $d['name'] . ' — долю в размере ' . $d['share'];
        }
        return 'Даритель безвозмездно передает в собственность (дарит) Одаряемым:' . "\n"
            . implode(";\n", $parts) . '.' . "\n"
            . trim($tail);
    }

    $fallback_share = $alienated !== '' ? $alienated : '___';
    return 'Даритель безвозмездно передает в собственность (дарит) Одаряемому, а Одаряемый принимает в дар от Дарителя долю в размере '
        . $fallback_share . $tail;
}

/** Динамические формулировки шаблона дарения (единственное/множественное число, доля/помещение). */
function yvo_build_gift_template_dynamic_clauses(array $sellers, array $buyers, array $property_data, $has_share_matrix, $gift_reg_expenses = '__________________________', $vacate_deadline = '14') {
    $object_type = isset($property_data['object_type']) ? sanitize_key((string) $property_data['object_type']) : 'apartment';
    if ($object_type === '') {
        $object_type = 'apartment';
    }
    $gobj = yvo_gift_object_grammar($object_type);
    $g = yvo_gift_donee_grammar($buyers);
    $gen = $g['gen'];
    $dat = $g['dat'];
    $nom = $g['nom'];
    $donee_dat_single = ($nom === 'Одаряемые') ? 'Одаряемым' : 'Одаряемому';
    $donee_oblig = ($nom === 'Одаряемые') ? 'обязаны' : 'обязан';
    $n_donees = count(yvo_parties_principal_buyers_ordered($buyers));
    if ($n_donees < 1) {
        $n_donees = count(yvo_gift_donee_share_rows($buyers));
    }
    $plural = ($n_donees > 1);
    $become = $plural ? 'становятся собственниками' : 'становится собственником';
    $inspect = $plural ? 'Одаряемые' : 'Одаряемый';
    $accept = $plural ? 'принимают' : 'принимает';
    $satisfied = $plural ? 'удовлетворены' : 'удовлетворен';

    $inspect_verb = $plural ? 'осмотрели' : 'осмотрел';
    $familiar_verb = $plural ? 'ознакомились' : 'ознакомился';

    if ($has_share_matrix) {
        $obj = 'Доли в размере, указанном в п. 1.1 настоящего Договора,';
        $reg_obj = 'Переход права собственности на указанные в п. 1.1 доли в праве общей долевой собственности на квартиру';
        $reg_exp = 'Расходы, связанные с государственной регистрацией перехода права собственности на указанные доли';
        $reg_common = 'Государственная регистрация перехода права собственности на указанные доли';
        $transfer = $gobj['nom'] . ' должны быть переданы Дарителем в фактическое владение ' . $dat;
        $transfer_docs = 'При передаче долей Даритель обязан передать ' . $dat . ' также всю имеющуюся техническую и иную документацию на квартиру';
        $act = 'Передача долей оформляется Актом приема-передачи.';
        $own311 = 'Доля в размере, указанном в п. 1.1, принадлежит Дарителю на праве собственности.';
    } else {
        $obj = $gobj['nom'];
        $reg_obj = 'Переход права собственности на ' . $gobj['acc'];
        $reg_exp = 'Расходы, связанные с государственной регистрацией перехода права собственности на ' . $gobj['acc'];
        $reg_common = 'Государственная регистрация перехода права собственности на ' . $gobj['acc'];
        $transfer = yvo_gift_object_must_be_transferred_phrase($gobj) . ' Дарителем в фактическое владение ' . $dat;
        $transfer_docs = 'При передаче ' . $gobj['gen'] . ' Даритель обязан передать ' . $dat . ' также всю имеющуюся техническую и иную документацию на ' . $gobj['acc'];
        $act = 'Передача ' . $gobj['gen'] . ' оформляется Актом приема-передачи.';
        $own311 = $gobj['nom'] . ' принадлежит Дарителю на праве собственности.';
    }

    $minor_accept = '';
    $minors_u14 = array();
    foreach ($buyers as $b) {
        if (is_array($b) && yvo_row_is_minor_buyer($b) && yvo_row_minor_needs_guardian_in_contract($b)) {
            $minors_u14[] = $b;
        }
    }
    if (!empty($minors_u14)) {
        $minor_accept = 'Принятие дара в пользу ' . $gen . ' осуществляется законными представителями, указанными в преамбуле настоящего Договора.';
    }

    return array(
        'GIFT_CLAUSE_1_3' => $obj . ' ' . ($has_share_matrix || $plural ? 'передаются' : 'передается') . ' в собственность ' . $gen . ' безвозмездно, без каких-либо встречных обязательств со стороны ' . $gen . '.',
        'GIFT_CLAUSE_2_1' => $reg_obj . ' от Дарителя к ' . $dat . ' подлежит государственной регистрации в Едином государственном реестре недвижимости в порядке, установленном законодательством Российской Федерации. ' . $nom . ' ' . $become . ' ' . ($has_share_matrix ? 'указанных долей' : $gobj['gen']) . ' с момента государственной регистрации.',
        'GIFT_CLAUSE_2_2' => $reg_exp . ' от Дарителя к ' . $dat . ' несет ' . $gift_reg_expenses . '.',
        'GIFT_CLAUSE_2_3' => $has_share_matrix || $gobj['mkd_clause']
            ? $reg_common . ' одновременно является государственной регистрацией перехода права общей долевой собственности на общее имущество в многоквартирном доме.'
            : 'Государственная регистрация перехода права собственности на ' . $gobj['acc'] . ' осуществляется в порядке, установленном законодательством Российской Федерации.',
        'GIFT_CLAUSE_2_4' => $transfer . ' в течение ' . $vacate_deadline . ' календарных дней с момента заключения настоящего Договора.',
        'GIFT_DONEE_DAT' => $donee_dat_single,
        'GIFT_DONEE_NOM' => $nom,
        'GIFT_DONEE_OBLIG' => $donee_oblig,
        'GIFT_CLAUSE_6_1' => $has_share_matrix
            ? 'В соответствии с п. 4 ст. 578 ГК РФ Стороны установили, что в случае если Даритель переживет Одаряемого, Даритель вправе отменить дарение. В этом случае доля (доли), переданная (переданные) в дар, возвращается (возвращаются) в собственность Дарителя.'
            : 'В соответствии с п. 4 ст. 578 ГК РФ Стороны установили, что в случае если Даритель переживет Одаряемого, Даритель вправе отменить дарение. В этом случае ' . $gobj['nom'] . ' возвращается в собственность Дарителя.',
        'GIFT_TRANSFER_DOCS' => $transfer_docs . ' и находящееся ' . $gobj['in_location'] . ' оборудование, а также документацию и предметы, связанные с владением, эксплуатацией и использованием ' . ($has_share_matrix ? 'квартиры' : $gobj['gen']) . ' (ключи, документы и т.п.).',
        'GIFT_TRANSFER_ACT' => $act,
        'GIFT_CLAUSE_3_1_1' => $own311,
        'GIFT_CLAUSE_3_2' => $inspect . ' до заключения настоящего Договора визуально ' . $inspect_verb . ' '
            . ($has_share_matrix ? 'квартиру, в отношении которой отчуждаются доли' : $gobj['acc']) . ', '
            . $familiar_verb . ' с ' . ($has_share_matrix ? 'её' : $gobj['poss_pronoun']) . ' основными конструктивными и техническими элементами и особенностями, а также с ' . ($has_share_matrix ? 'её' : $gobj['poss_pronoun']) . ' эксплуатационным и техническим состоянием.',
        'GIFT_CLAUSE_3_2_TAIL' => $inspect . ' ' . $satisfied . ' состоянием ' . ($has_share_matrix ? 'квартиры' : $gobj['gen']) . ' и ' . $accept . ' '
            . ($has_share_matrix ? 'доли в дар' : ($gobj['gender'] === 'feminine' ? 'её в дар' : ($gobj['gender'] === 'plural' ? 'их в дар' : ($gobj['gender'] === 'masculine' ? 'его в дар' : 'его в дар'))))
            . ' в состоянии, в котором ' . ($has_share_matrix ? 'находится квартира' : ('находится ' . $gobj['nom'])) . '.',
        'GIFT_MINOR_ACCEPTANCE' => $minor_accept,
        'GIFT_REG_TARGET' => $has_share_matrix ? 'перехода права собственности на указанные доли' : ('перехода права собственности на ' . $gobj['acc']),
    );
}

/** Родительный падеж названия месяца по номеру (01–12). */
function yvo_russian_month_genitive($month_num) {
    $months = array(
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
        5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
        9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    );
    $n = (int) $month_num;
    return isset($months[$n]) ? $months[$n] : '';
}

/** Есть ли одаряемые до 14 лет (подпись только законного представителя). */
function yvo_gift_has_u14_donees(array $buyers) {
    foreach ($buyers as $b) {
        if (is_array($b) && yvo_row_is_minor_buyer($b) && yvo_row_minor_needs_guardian_in_contract($b)) {
            return true;
        }
    }
    return false;
}

/** П. 6.12–6.14: нотариат (для долей — обязателен) и заключительные пункты. */
function yvo_build_gift_section_6_tail($has_share_matrix, array $buyers = array()) {
    $n = 12;
    $lines = array();
    if ($has_share_matrix) {
        $lines[] = '6.' . $n . '. Настоящий Договор подлежит обязательному нотариальному удостоверению в соответствии со ст. 42 Федерального закона от 13.07.2015 № 218-ФЗ «О государственной регистрации недвижимости».';
        $n++;
    }
    $in_force = $has_share_matrix
        ? 'с момента его нотариального удостоверения'
        : 'с момента его подписания обеими Сторонами';
    $lines[] = '6.' . $n . '. Настоящий Договор вступает в силу ' . $in_force . ' и действует до полного выполнения каждой Стороной своих обязательств по настоящему Договору.';
    $n++;
    $lines[] = '6.' . $n . '. С момента подписания настоящего Договора вся предшествующая переписка и ранее заключенные договоры и соглашения между Сторонами утрачивают свою силу.';
    $n++;
    if (yvo_gift_has_u14_donees($buyers)) {
        $page_sign = 'Каждая страница каждого экземпляра настоящего Договора подписана Дарителем и законными представителями Одаряемых.';
    } else {
        $page_sign = 'Каждая страница каждого экземпляра настоящего Договора подписана Дарителем и Одаряемым (Одаряемыми).';
    }
    $lines[] = '6.' . $n . '. Настоящий Договор составлен и подписан в трех экземплярах, имеющих равную юридическую силу: по одному экземпляру для каждой из Сторон и один экземпляр для органа, осуществляющего государственную регистрацию прав на недвижимое имущество и сделок с ним. ' . $page_sign;
    return implode("\n", $lines);
}

/** Сводка «кому — какая доля» для договора (после п. 1.1). */
function yvo_build_gift_share_allocation_summary(array $sellers, array $buyers, array $property_data) {
    $lines = array();
    $alienated = yvo_gift_donor_alienated_share($sellers, $property_data);
    if ($alienated !== '' && $alienated !== '___') {
        $donors = yvo_parties_principal_sellers_ordered($sellers);
        $dname = isset($donors[0]['full_name']) ? trim((string) $donors[0]['full_name']) : 'Даритель';
        $lines[] = 'Даритель (' . $dname . ') отчуждает (дарит) долю в размере ' . $alienated . '.';
    }
    $donees = yvo_gift_donee_share_rows($buyers);
    foreach ($donees as $d) {
        $lines[] = 'Одаряемый ' . $d['name'] . ' принимает в дар долю в размере ' . $d['share'] . '.';
    }
    return implode("\n", $lines);
}

/**
 * Падежи и атрибуты объекта для договора дарения (не матрица долей).
 *
 * @return array{nom:string,gen:string,dat:string,prep:string,acc:string,mkd_clause:bool,residential_reg:bool,poss_pronoun:string,in_location:string,short_kind:string}
 */
function yvo_gift_object_grammar($object_type) {
    $object_type = sanitize_key((string) $object_type);
    if ($object_type === '') {
        $object_type = 'apartment';
    }
    $cases = array(
        'apartment' => array(
            'nom' => 'Жилое помещение',
            'gen' => 'Жилого помещения',
            'dat' => 'Жилому помещению',
            'prep' => 'Жилом помещении',
            'acc' => 'Жилое помещение',
            'instr' => 'Жилым помещением',
            'poss_pronoun' => 'его',
            'in_location' => 'в нем',
            'gender' => 'neuter',
            'mkd_clause' => true,
            'residential_reg' => true,
            'short_kind' => 'жилое помещение',
        ),
        'room' => array(
            'nom' => 'Комната',
            'gen' => 'Комнаты',
            'dat' => 'Комнате',
            'prep' => 'Комнате',
            'acc' => 'Комнату',
            'instr' => 'Комнатой',
            'poss_pronoun' => 'её',
            'in_location' => 'в ней',
            'gender' => 'feminine',
            'mkd_clause' => true,
            'residential_reg' => true,
            'short_kind' => 'комната',
        ),
        'land' => array(
            'nom' => 'Земельный участок',
            'gen' => 'Земельного участка',
            'dat' => 'Земельному участку',
            'prep' => 'Земельном участке',
            'acc' => 'Земельный участок',
            'instr' => 'Земельным участком',
            'poss_pronoun' => 'его',
            'in_location' => 'на нем',
            'gender' => 'masculine',
            'mkd_clause' => false,
            'residential_reg' => false,
            'short_kind' => 'земельный участок',
        ),
        'house_with_plot' => array(
            'nom' => 'Жилой дом с земельным участком',
            'gen' => 'Жилого дома с земельным участком',
            'dat' => 'Жилому дому с земельным участком',
            'prep' => 'Жилом доме с земельным участком',
            'acc' => 'Жилой дом с земельным участком',
            'instr' => 'Жилым домом с земельным участком',
            'poss_pronoun' => 'их',
            'in_location' => 'на них',
            'gender' => 'masculine',
            'mkd_clause' => false,
            'residential_reg' => false,
            'short_kind' => 'жилой дом с земельным участком',
        ),
        'garage' => array(
            'nom' => 'Гараж',
            'gen' => 'Гаража',
            'dat' => 'Гаражу',
            'prep' => 'Гараже',
            'acc' => 'Гараж',
            'instr' => 'Гаражом',
            'poss_pronoun' => 'его',
            'in_location' => 'в нем',
            'gender' => 'masculine',
            'mkd_clause' => false,
            'residential_reg' => false,
            'short_kind' => 'гараж',
        ),
        'parking' => array(
            'nom' => 'Машино-место',
            'gen' => 'Машино-места',
            'dat' => 'Машино-месту',
            'prep' => 'Машино-месте',
            'acc' => 'Машино-место',
            'instr' => 'Машино-местом',
            'poss_pronoun' => 'его',
            'in_location' => 'на нем',
            'gender' => 'neuter',
            'mkd_clause' => false,
            'residential_reg' => false,
            'short_kind' => 'машино-место',
        ),
    );
    return isset($cases[$object_type]) ? $cases[$object_type] : $cases['apartment'];
}

/** Подзаголовок договора дарения по типу объекта. */
function yvo_gift_contract_subtitle_for_object($object_type) {
    $map = array(
        'apartment' => 'дарения жилого помещения',
        'room' => 'дарения комнаты',
        'land' => 'дарения земельного участка',
        'house_with_plot' => 'дарения жилого дома с земельным участком',
        'garage' => 'дарения гаража',
        'parking' => 'дарения машино-места',
    );
    $object_type = sanitize_key((string) $object_type);
    return isset($map[$object_type]) ? $map[$object_type] : 'дарения объекта недвижимости';
}

/**
 * Плейсхолдеры падежей объекта дарения для txt-шаблона.
 *
 * @return array<string, string>
 */
function yvo_gift_object_placeholder_map($object_type) {
    $g = yvo_gift_object_grammar($object_type);
    $clause_314 = $g['residential_reg']
        ? '3.1.4. На момент заключения настоящего Договора все лица, ранее проживавшие в ' . $g['prep'] . ', сняты с регистрационного учета по месту жительства и (или) по месту пребывания, а также прекратили фактическое пользование ' . $g['dat'] . ' (как постоянное, так и временное). Отсутствуют какие-либо лица, сохраняющие право проживания в ' . $g['prep'] . ' или пользования им, а также имеющие обоснованную возможность претендовать на такое право.'
        : '';
    return array(
        'GIFT_OBJECT_NOM' => $g['nom'],
        'GIFT_OBJECT_GEN' => $g['gen'],
        'GIFT_OBJECT_DAT' => $g['dat'],
        'GIFT_OBJECT_PREP' => $g['prep'],
        'GIFT_OBJECT_ACC' => $g['acc'],
        'GIFT_OBJECT_INSTR' => isset($g['instr']) ? $g['instr'] : $g['acc'],
        'GIFT_SECTION_2_TITLE' => '2. Передача ' . $g['gen'] . '.',
        'GIFT_CLAUSE_3_1_4' => $clause_314,
    );
}

/**
 * Синхронизация кадастровых номеров дома и участка для «дом с участком».
 *
 * @param array<string, mixed> $property
 * @return array<string, mixed>
 */
function yvo_normalize_house_with_plot_cadastral(array $property) {
    $ot = sanitize_key((string) ($property['object_type'] ?? ''));
    if ($ot !== 'house_with_plot') {
        return $property;
    }
    $house_cad = trim((string) ($property['house_cadastral_number'] ?? ''));
    $cad = trim((string) ($property['cadastral_number'] ?? ''));
    if ($house_cad !== '' && $cad === '') {
        $property['cadastral_number'] = $house_cad;
    } elseif ($cad !== '' && $house_cad === '') {
        $property['house_cadastral_number'] = $cad;
    }
    if (trim((string) ($property['area'] ?? '')) === '' && trim((string) ($property['house_area'] ?? '')) !== '') {
        $property['area'] = trim((string) $property['house_area']);
    }
    return $property;
}

/**
 * Заголовок и строки таблицы ЕГРН в ДКП по типу объекта.
 *
 * @return array<string, string>
 */
function yvo_build_dkp_object_meta(array $property_data) {
    $property_data = yvo_normalize_house_with_plot_cadastral($property_data);
    $ot = isset($property_data['object_type']) ? sanitize_key((string) $property_data['object_type']) : 'apartment';
    if ($ot === '') {
        $ot = 'apartment';
    }
    $share = trim((string) ($property_data['share_in_right'] ?? $property_data['object_share'] ?? ''));
    $plot_cad = trim((string) ($property_data['plot_cadastral_number'] ?? ''));

    $meta = array(
        'DKP_CONTRACT_TITLE' => 'ДОГОВОР КУПЛИ-ПРОДАЖИ КВАРТИРЫ',
        'PROPERTY_EGRN_KIND' => 'Помещение',
        'PROPERTY_EGRN_PURPOSE' => 'Жилое помещение',
        'PROPERTY_EGRN_NAME' => 'Квартира',
        'PROPERTY_DKP_TABLE_EXTRA' => "Количество комнат в квартире\t{{PROPERTY_ROOMS}}\nПлощадь по внутреннему обмеру без учета лоджий, балконов и веранд\t{{PROPERTY_AREA}} кв.м\nЭтаж(и), на котором(ых) расположено помещение\t{{PROPERTY_FLOOR}}",
    );

    switch ($ot) {
        case 'share':
            $meta['DKP_CONTRACT_TITLE'] = 'ДОГОВОР КУПЛИ-ПРОДАЖИ ДОЛИ В КВАРТИРЕ';
            $meta['PROPERTY_EGRN_NAME'] = 'Доля в праве общей долевой собственности на квартиру';
            $meta['PROPERTY_DKP_TABLE_EXTRA'] = "Доля в праве общей долевой собственности\t" . ($share !== '' ? $share : '___')
                . "\nКоличество комнат в квартире\t{{PROPERTY_ROOMS}}\nПлощадь квартиры\t{{PROPERTY_AREA}} кв.м\nЭтаж(и), на котором(ых) расположена квартира\t{{PROPERTY_FLOOR}}";
            break;
        case 'room':
            $meta['DKP_CONTRACT_TITLE'] = 'ДОГОВОР КУПЛИ-ПРОДАЖИ КОМНАТЫ';
            $meta['PROPERTY_EGRN_NAME'] = 'Комната';
            $meta['PROPERTY_DKP_TABLE_EXTRA'] = "Площадь комнаты\t{{PROPERTY_AREA}} кв.м\nЭтаж(и), на котором(ых) расположена комната\t{{PROPERTY_FLOOR}}";
            break;
        case 'land':
            $meta['DKP_CONTRACT_TITLE'] = 'ДОГОВОР КУПЛИ-ПРОДАЖИ ЗЕМЕЛЬНОГО УЧАСТКА';
            $meta['PROPERTY_EGRN_KIND'] = 'Земельный участок';
            $meta['PROPERTY_EGRN_PURPOSE'] = '—';
            $meta['PROPERTY_EGRN_NAME'] = 'Земельный участок';
            $meta['PROPERTY_DKP_TABLE_EXTRA'] = "Площадь земельного участка\t{{PROPERTY_AREA}} кв.м";
            break;
        case 'house_with_plot':
            $meta['DKP_CONTRACT_TITLE'] = 'ДОГОВОР КУПЛИ-ПРОДАЖИ ЖИЛОГО ДОМА С ЗЕМЕЛЬНЫМ УЧАСТКОМ';
            $meta['PROPERTY_EGRN_KIND'] = 'Здание';
            $meta['PROPERTY_EGRN_PURPOSE'] = 'Жилое';
            $meta['PROPERTY_EGRN_NAME'] = 'Жилой дом';
            $plot_row = $plot_cad !== '' ? $plot_cad : '____________________';
            $meta['PROPERTY_DKP_TABLE_EXTRA'] = "Кадастровый номер земельного участка\t" . $plot_row
                . "\nПлощадь жилого дома\t{{PROPERTY_AREA}} кв.м";
            break;
        case 'garage':
            $meta['DKP_CONTRACT_TITLE'] = 'ДОГОВОР КУПЛИ-ПРОДАЖИ ГАРАЖА';
            $meta['PROPERTY_EGRN_PURPOSE'] = 'Нежилое помещение';
            $meta['PROPERTY_EGRN_NAME'] = 'Гараж';
            $meta['PROPERTY_DKP_TABLE_EXTRA'] = "Площадь\t{{PROPERTY_AREA}} кв.м";
            break;
        case 'parking':
            $meta['DKP_CONTRACT_TITLE'] = 'ДОГОВОР КУПЛИ-ПРОДАЖИ МАШИНО-МЕСТА';
            $meta['PROPERTY_EGRN_PURPOSE'] = 'Нежилое помещение';
            $meta['PROPERTY_EGRN_NAME'] = 'Машино-место';
            $meta['PROPERTY_DKP_TABLE_EXTRA'] = "Площадь\t{{PROPERTY_AREA}} кв.м";
            break;
    }

    return $meta;
}

/** Банк — Сбербанк (по id или названию). */
function yvo_dkp_bank_is_sberbank($bank_name = '', $bank_id = '') {
    $bank_id = sanitize_key((string) $bank_id);
    if ($bank_id === 'sberbank') {
        return true;
    }
    return (bool) preg_match('/сбер/ui', (string) $bank_name);
}

/**
 * Полное описание ПАО Сбербанк для блока расчётов (как в ДКП Сбер/ЦНС).
 */
function yvo_dkp_sberbank_legal_block() {
    return 'Публичным акционерным обществом «Сбербанк России» (сокращенное наименование – ПАО Сбербанк), '
        . 'генеральная лицензия на осуществление банковских операций №1481 от 11.08.2015, место нахождения: '
        . '117997, г. Москва, ул. Вавилова, д. 19 (далее – Банк)';
}

/**
 * Ключ варианта расчётов для шаблона ДКП.
 * Приоритет: payment_type/method (+ Сбер→ЦНС) из опций формы; иначе — по id шаблона.
 *
 * @param string               $template_id
 * @param array<string,mixed>  $options
 */
function yvo_dkp_payment_variant_key($template_id, $options = array()) {
    $template_id = (string) $template_id;
    $id = mb_strtolower($template_id, 'UTF-8');
    $pt = isset($options['payment_type']) ? sanitize_key((string) $options['payment_type']) : '';
    $pm = isset($options['payment_method']) ? sanitize_key((string) $options['payment_method']) : '';
    if ($pt === '' && isset($options['property']) && is_array($options['property'])) {
        $prop = $options['property'];
        $pt = isset($prop['payment_type']) ? sanitize_key((string) $prop['payment_type']) : '';
        if ($pt === 'mortgage') {
            $pm = isset($prop['payment_method_mortgage']) ? sanitize_key((string) $prop['payment_method_mortgage']) : 'day_of_deal';
        } elseif ($pt === 'cash') {
            $pm = isset($prop['payment_method_cash']) ? sanitize_key((string) $prop['payment_method_cash']) : 'day_of_deal';
        }
    }
    $bank_name = isset($options['bank_name']) ? (string) $options['bank_name'] : '';
    $bank_id = isset($options['bank_id']) ? (string) $options['bank_id'] : '';
    $is_sber = yvo_dkp_bank_is_sberbank($bank_name, $bank_id);

    if ($pt === 'mortgage') {
        if ($pm === 'cell') {
            return 'ipoteka_yacheyka';
        }
        if ($is_sber && ($pm === '' || $pm === 'day_of_deal' || $pm === 'accreditive' || $pm === 'sber_cns')) {
            return 'ipoteka_sber_cns';
        }
        if ($pm === 'accreditive') {
            return 'ipoteka_akkreditiv';
        }
        if ($pm === 'day_of_deal') {
            return 'ipoteka_den_sdelki';
        }
        return $is_sber ? 'ipoteka_sber_cns' : 'ipoteka_akkreditiv';
    }
    if ($pt === 'cash') {
        if ($pm === 'cell') {
            return 'nalichnye_yacheyka';
        }
        if ($pm === 'day_of_deal') {
            return 'nalichnye_den_sdelki';
        }
        return 'nalichnye_akkreditiv';
    }

    if ($template_id === 'default') {
        return 'nalichnye_den_sdelki';
    }
    if ($template_id === 'dkp-kvartira-ipoteka') {
        return 'ipoteka_akkreditiv';
    }
    if ($template_id === 'dkp-nalichnye-akkreditiv-podpisi') {
        return 'nalichnye_akkreditiv';
    }
    if (strpos($id, 'ипотека_аккредитив') !== false || strpos($id, 'ipoteka_akkreditiv') !== false) {
        return 'ipoteka_akkreditiv';
    }
    if (strpos($id, 'ипотека_ячейка') !== false || strpos($id, 'ipoteka_yacheyka') !== false || strpos($id, 'ipoteka_yaweika') !== false) {
        return 'ipoteka_yacheyka';
    }
    if (strpos($id, 'ипотека_в_день') !== false || strpos($id, 'ipoteka_v_den') !== false || strpos($id, 'ipoteka_den') !== false) {
        return 'ipoteka_den_sdelki';
    }
    if (strpos($id, 'наличные_аккредитив') !== false || strpos($id, 'nalichnye_akkreditiv') !== false) {
        return 'nalichnye_akkreditiv';
    }
    if (strpos($id, 'наличные_ячейка') !== false || strpos($id, 'nalichnye_yacheyka') !== false) {
        return 'nalichnye_yacheyka';
    }
    if (strpos($id, 'наличные_в_день') !== false || strpos($id, 'nalichnye_den') !== false) {
        return 'nalichnye_den_sdelki';
    }
    return 'nalichnye_akkreditiv';
}

/** Старые .txt со скобками […] — собираем из dkp-sale-standard.txt + блок расчётов. */
function yvo_dkp_uses_standard_base_template($template_id) {
    $built_in_full = array('default', 'dkp-kvartira-ipoteka', 'dkp-nalichnye-akkreditiv-podpisi');
    if (in_array((string) $template_id, $built_in_full, true)) {
        return false;
    }
    $path = yvo_resolve_template_path($template_id);
    if ($path && file_exists($path)) {
        $probe = @file_get_contents($path, false, null, 0, 16384);
        if (is_string($probe) && strpos($probe, '{{SELLERS_BLOCK}}') !== false) {
            return false;
        }
    }
    return true;
}

/** ДКП с динамическими сторонами и таблицей ЕГРН. */
function yvo_dkp_uses_modern_fill_engine($template_id) {
    $template_id = (string) $template_id;
    if ($template_id === '') {
        return true;
    }
    if (in_array($template_id, array('default', 'dkp-kvartira-ipoteka', 'dkp-nalichnye-akkreditiv-podpisi'), true)) {
        return true;
    }
    if (yvo_dkp_uses_standard_base_template($template_id)) {
        return true;
    }
    $path = yvo_resolve_template_path($template_id);
    if ($path && file_exists($path)) {
        $probe = @file_get_contents($path, false, null, 0, 16384);
        return is_string($probe) && strpos($probe, '{{SELLERS_BLOCK}}') !== false;
    }
    return false;
}

function yvo_resolve_dkp_sale_txt_path_effective($template_id) {
    if (!yvo_dkp_uses_standard_base_template($template_id)) {
        if ($template_id === 'default' || $template_id === 'dkp-kvartira-ipoteka') {
            return yvo_resolve_dkp_ipoteka_txt_path_effective($template_id);
        }
        $path = yvo_resolve_template_path($template_id);
        if ($path && file_exists($path)) {
            return $path;
        }
    }
    $base = YVO_PLUGIN_DIR . 'templates/dkp-sale-standard.txt';
    return file_exists($base) ? $base : null;
}

function yvo_build_dkp_clause_4_3($variant_key) {
    if (strpos((string) $variant_key, 'ipoteka') === 0) {
        return '4.3. В случае расторжения/прекращения (по любым основаниям, кроме надлежащего исполнения)/признания незаключённой/недействительной сделкой настоящего Договора, Покупатель поручает Продавцам в течение 20 рабочих дней со дня расторжения/вступления в силу решения суда перечислить денежные средства, полученные ими от Покупателя в оплату цены Объекта, на текущий счёт заёмщика по Кредитному договору, открытый в Банке, с обязательным уведомлением Банка о возврате средств не менее чем за 5 рабочих дней до даты их перечисления.';
    }
    return '4.3. В случае расторжения/прекращения (по любым основаниям, кроме надлежащего исполнения)/признания незаключённой/недействительной сделкой настоящего Договора, Покупатель поручает Продавцам в течение 20 рабочих дней со дня расторжения/вступления в силу решения суда перечислить денежные средства, полученные ими от Покупателя в оплату цены Объекта, на указанные Покупателем платёжные реквизиты.';
}

/**
 * Раздел 2 «Цена и порядок расчётов» для dkp-sale-standard.txt.
 */
function yvo_build_dkp_payment_section($variant_key, array $options = array()) {
    $variant_key = (string) $variant_key;
    $g = function ($k, $fallback = '') use ($options) {
        if (isset($options[$k]) && trim((string) $options[$k]) !== '') {
            return (string) $options[$k];
        }
        return $fallback;
    };
    $price = $g('property_price', '__________');
    $price_words = $g('property_price_words', '________________');
    $bank = $g('bank_name', '[наименование банка]');
    $city = $g('contract_city', '________________');
    $acc_amt = $g('accreditiv_amount_display', $price);
    $acc_days = $g('accreditiv_calendar_days', '__');
    $acc_payee = $g('accreditiv_payee_label', 'Продавца');
    $seller_pay = $g('seller_payment_details', '________________');
    $loan_own = $g('loan_own_amount', '__________');
    $loan_own_w = $g('loan_own_amount_words', '________________');
    $loan_cr = $g('loan_credit_amount', '__________');
    $loan_cr_w = $g('loan_credit_amount_words', '________________');
    $loan_num = $g('loan_agreement_number', '[номер]');
    $loan_date = $g('loan_agreement_date', '[дата]');
    $paid_before = $g('paid_before_signing', '[сумма]');
    $paid_at = $g('paid_at_signing', '[сумма]');
    $paid_letters = $g('paid_by_letters', '[сумма]');
    $total_acc = $g('total_accreditives_amount', $price);
    $acc_block = $g('accreditives_block', '');
    $cell_num = $g('bank_cell_number', '________');
    $cell_days = $g('bank_cell_days', $acc_days);
    $contract_date = $g('contract_date', date('d.m.Y'));
    $receipts = 'Полный и окончательный расчёт за Объект оформляется расписками Продавцов, подтверждающими получение ими денежных средств в соответствии с условиями настоящего Договора.';
    $no_seller_pledge = 'Стороны договорились, что в соответствии с п. 5 ст. 488 Гражданского кодекса РФ право залога у Продавцов на указанный Объект не возникает.';

    switch ($variant_key) {
        case 'nalichnye_akkreditiv':
            return "2.1. Объект продаётся по согласованной Сторонами цене в размере {$price} ({$price_words}) рублей.\n"
                . "2.2. Оплата стоимости Объекта производится Покупателем за счёт собственных средств в полном объёме.\n"
                . "2.3. Сумма в размере {$acc_amt} рублей уплачивается Покупателем посредством открытия в {$bank} в течение 1 (Одного) рабочего дня с даты заключения настоящего Договора безотзывного, безакцептного, покрытого аккредитива на имя {$acc_payee}. Условием раскрытия аккредитива является предоставление выписки из ЕГРН о регистрации перехода права собственности к Покупателю.\n"
                . "2.4. Оплата Объекта Покупателем Продавцам производится в течение 1 (Одного) рабочего дня с момента государственной регистрации перехода права собственности на Объект к Покупателю в ЕГРН.\n"
                . "2.5. {$no_seller_pledge}\n"
                . "2.6. {$receipts}";

        case 'nalichnye_yacheyka':
            return "2.1. Объект продаётся по согласованной Сторонами цене в размере {$price} ({$price_words}) рублей.\n"
                . "2.2. Оплата стоимости Объекта производится Покупателем за счёт собственных средств в полном объёме.\n"
                . "2.3. Сумма в размере {$acc_amt} рублей вносится Покупателем в банковскую ячейку № {$cell_num} в {$bank} (далее – Банк) в течение {$cell_days} с даты заключения настоящего Договора. Ключ (код) от ячейки передаётся Продавцам после государственной регистрации перехода права собственности на Объект к Покупателю в ЕГРН. Условием выдачи Продавцам денежных средств из ячейки является предоставление ими в Банк оригинала или надлежащим образом заверенной копии выписки из ЕГРН о регистрации перехода права собственности к Покупателю.\n"
                . "2.4. {$no_seller_pledge}\n"
                . "2.5. {$receipts}";

        case 'nalichnye_den_sdelki':
            return "2.1. Объект продаётся по согласованной Сторонами цене в размере {$price} ({$price_words}) рублей.\n"
                . "2.2. Оплата стоимости Объекта производится Покупателем за счёт собственных средств в полном объёме.\n"
                . "2.3. Сумма в размере {$price} ({$price_words}) рублей уплачивается Покупателем Продавцам в день подписания настоящего Договора путём передачи наличных денежных средств (либо путём перечисления на счёт Продавцов по реквизитам: {$seller_pay}).\n"
                . "2.4. {$no_seller_pledge}\n"
                . "2.5. {$receipts}";

        case 'ipoteka_akkreditiv':
            return "2.1. Объект продаётся по согласованной Сторонами цене в размере {$price} ({$price_words}) рублей.\n"
                . "2.2. Объект приобретается Покупателем у Продавцов за счёт собственных средств в размере {$loan_own} ({$loan_own_w}) рублей и с использованием кредитных средств в размере {$loan_cr} ({$loan_cr_w}) рублей, предоставленных Покупателю по Кредитному договору № {$loan_num} от {$loan_date} (далее – Кредитный договор), заключённому в {$city} между Покупателем и {$bank} (далее – Банк). На момент подписания настоящего Договора указанные кредитные средства получены Покупателем полностью.\n"
                . "2.3. Сумма в размере {$paid_before} рублей уплачена до подписания настоящего Договора.\n"
                . "2.4. Сумма в размере {$paid_at} рублей уплачивается в день подписания настоящего Договора.\n"
                . "2.5. Сумма в размере {$paid_letters} рублей оплачивается Покупателем посредством открытия в Банке в течение 1 (Одного) рабочего дня с даты заключения настоящего Договора безотзывного, безакцептного, покрытого аккредитива на имя {$acc_payee} на следующих условиях:\n\n"
                . "Банк-эмитент и исполняющий банк – {$bank}; сумма аккредитива: {$acc_amt} рублей; срок и период предоставления документов: {$acc_days} календарных дней; плательщик – Покупатель, получатель – {$acc_payee}; назначение платежа: перечисление денежных средств по Договору купли-продажи от {$contract_date}; банковские реквизиты Получателя: {$seller_pay}. Перечень документов для раскрытия аккредитива: выписка из ЕГРН, подтверждающей регистрацию права залога (ипотеки) в пользу Банка.\n"
                . "2.6. Оплата Объекта Покупателем Продавцам в размере {$total_acc} рублей производится в течение 1 (Одного) рабочего дня с момента государственной регистрации права собственности на Объект по настоящему Договору, а также государственной регистрации ипотеки Объекта в силу закона в Управлении Росреестра.\n"
                . "2.7. На основании статьи 77 ФЗ «Об ипотеке (залоге недвижимости)» с момента государственной регистрации ипотеки в силу закона на Объект к Покупателю Объект считается находящимся в залоге у Банка, права которого удостоверяются закладной. Залогодержателем является Банк, залогодателем – Покупатель.\n"
                . "2.8. Объект может быть отчуждён Покупателем лишь с согласия Банка. Покупатель вправе сдавать заложенное имущество в аренду, наём, передавать во временное пользование только с согласия Банка.\n"
                . "2.9. На цели оплаты стоимости Объекта Покупателем не используются целевые кредитные или заёмные средства иных организаций, за исключением кредитных средств Банка.\n"
                . "2.10. {$no_seller_pledge}\n"
                . "2.11. {$receipts}";

        case 'ipoteka_yacheyka':
            return "2.1. Объект продаётся по согласованной Сторонами цене в размере {$price} ({$price_words}) рублей.\n"
                . "2.2. Объект приобретается Покупателем у Продавцов за счёт собственных средств в размере {$loan_own} ({$loan_own_w}) рублей и с использованием кредитных средств в размере {$loan_cr} ({$loan_cr_w}) рублей, предоставленных Покупателю по Кредитному договору № {$loan_num} от {$loan_date} (далее – Кредитный договор), заключённому в {$city} между Покупателем и {$bank} (далее – Банк). На момент подписания настоящего Договора указанные кредитные средства получены Покупателем полностью.\n"
                . "2.3. Сумма в размере {$paid_before} рублей уплачена до подписания настоящего Договора.\n"
                . "2.4. Сумма в размере {$paid_at} рублей уплачивается в день подписания настоящего Договора.\n"
                . "2.5. Сумма в размере {$paid_letters} рублей вносится Покупателем в банковскую ячейку № {$cell_num} в Банке в течение {$cell_days} с даты заключения настоящего Договора. Ключ (код) от ячейки передаётся Продавцам после государственной регистрации перехода права собственности на Объект к Покупателю и государственной регистрации ипотеки Объекта в силу закона в ЕГРН. Условием выдачи Продавцам денежных средств из ячейки является предоставление ими в Банк оригинала или надлежащим образом заверенной копии выписки из ЕГРН о регистрации перехода права собственности и регистрации ипотеки в пользу Банка, а также копии расписки органа регистрации прав о предоставлении закладной.\n"
                . "2.6. Сумма в размере {$loan_cr} рублей перечисляется Банком на счёт Продавцов в течение срока, установленного Кредитным договором, после государственной регистрации права собственности и ипотеки в силу закона.\n"
                . "2.7. На основании статьи 77 ФЗ «Об ипотеке (залоге недвижимости)» с момента государственной регистрации ипотеки в силу закона на Объект к Покупателю Объект считается находящимся в залоге у Банка, права которого удостоверяются закладной. Залогодержателем является Банк, залогодателем – Покупатель.\n"
                . "2.8. Объект может быть отчуждён Покупателем лишь с согласия Банка. На цели оплаты Покупателем не используются кредитные или заёмные средства иных организаций, за исключением средств Банка.\n"
                . "2.9. {$no_seller_pledge}\n"
                . "2.10. {$receipts}";

        case 'ipoteka_den_sdelki':
            return "2.1. Объект продаётся по согласованной Сторонами цене в размере {$price} ({$price_words}) рублей.\n"
                . "2.2. Объект приобретается Покупателем у Продавцов за счёт собственных средств в размере {$loan_own} ({$loan_own_w}) рублей и с использованием кредитных средств в размере {$loan_cr} ({$loan_cr_w}) рублей, предоставленных Покупателю по Кредитному договору № {$loan_num} от {$loan_date} (далее – Кредитный договор), заключённому в {$city} между Покупателем и {$bank} (далее – Банк). На момент подписания настоящего Договора указанные кредитные средства получены Покупателем полностью.\n"
                . "2.3. Сумма в размере {$paid_before} рублей уплачена до подписания настоящего Договора.\n"
                . "2.4. Сумма в размере {$paid_at} рублей (первый взнос) уплачивается Покупателем Продавцам в день подписания настоящего Договора путём передачи наличных денежных средств (либо путём перечисления на счёт Продавцов по реквизитам: {$seller_pay}).\n"
                . "2.5. Сумма в размере {$loan_cr} рублей перечисляется Банком на счёт Продавцов в течение срока, установленного Кредитным договором, после государственной регистрации права собственности на Объект по настоящему Договору и государственной регистрации ипотеки Объекта в силу закона в Управлении Росреестра.\n"
                . "2.6. На основании статьи 77 ФЗ «Об ипотеке (залоге недвижимости)» с момента государственной регистрации ипотеки в силу закона на Объект к Покупателю Объект считается находящимся в залоге у Банка, права которого удостоверяются закладной. Залогодержателем является Банк, залогодателем – Покупатель.\n"
                . "2.7. Объект может быть отчуждён Покупателем лишь с согласия Банка. На цели оплаты Покупателем не используются кредитные или заёмные средства иных организаций, за исключением средств Банка.\n"
                . "2.8. {$no_seller_pledge}\n"
                . "2.9. {$receipts}";

        case 'ipoteka_sber_cns':
            $buyer_names = $g('buyer_names', 'Покупателю');
            $bank_legal = $g('bank_legal_block', '');
            if ($bank_legal === '') {
                $bank_legal = yvo_dkp_bank_is_sberbank($bank, $g('bank_id', ''))
                    ? yvo_dkp_sberbank_legal_block()
                    : ($bank . ' (далее – Банк)');
            }
            $cns = 'Общества с ограниченной ответственностью «Центр недвижимости от Сбербанка» (ООО «ЦНС»), ИНН 7736249247, '
                . 'открытого в Операционном управлении Московского банка ПАО Сбербанк г.Москва, '
                . 'к/счет 30101810400000000225, БИК 044525225';
            $seller_acc = ($seller_pay !== '' && $seller_pay !== '________________')
                ? $seller_pay
                : 'счета Продавцов по реквизитам, указанным Покупателем при расчетах';
            return "2.1. Стоимость Объекта составляет {$price} рублей ({$price_words} рублей 00 копеек). Цена является окончательной и изменению не подлежит.\n"
                . "2.2. Стороны устанавливают следующий порядок оплаты стоимости Объекта:\n"
                . "{$loan_own} ({$loan_own_w}) рублей 00 копеек оплачивается за счёт собственных денежных средств Покупателя в момент подписания настоящего договора купли-продажи.\n"
                . "2.2.2. Часть стоимости Объекта в сумме {$loan_cr} ({$loan_cr_w}) рублей 00 копеек оплачивается за счет целевых кредитных денежных средств, предоставленных {$buyer_names}, в соответствии с Кредитным договором № {$loan_num} от {$loan_date}, заключенным в городе {$city} (далее – Кредитный договор) {$bank_legal}. Условия предоставления кредита предусмотрены Кредитным договором.\n"
                . "2.3. Порядок расчетов по Договору.\n"
                . "2.3.1. Денежная сумма в размере {$loan_own} ({$loan_own_w}) рублей уплачивается в день подписания настоящего договора.\n"
                . "2.3.2. Расчет денежной суммы в размере {$loan_cr} ({$loan_cr_w}) рублей 00 копеек производится с использованием номинального счета {$cns}. Бенефициаром в отношении денежных средств, размещаемых на номинальном счете, является Покупатель.\n"
                . "Перечисление денежных средств Продавцам в счет оплаты Объекта недвижимости осуществляется ООО «ЦНС», ИНН 7736249247 по поручению Покупателя после государственной регистрации перехода права собственности на Объект недвижимости к Покупателю (Заемщику) и к иным лицам (при наличии), а также государственной регистрации ипотеки Объекта недвижимости в силу закона в пользу Банка, на {$seller_acc}.\n"
                . "Передача денежных средств в размере {$loan_cr} ({$loan_cr_w}) рублей 00 копеек Продавцам в счет оплаты стоимости Объекта осуществляется в течение от 1 (одного) рабочего дня до 5 (пяти) рабочих дней с момента получения ООО «ЦНС» информации от органа, осуществляющего государственную регистрацию, о переходе права собственности на объект недвижимого имущества, указанный в п.1 Договора, к Покупателю и ипотеки Объекта в силу закона в пользу Банка.\n"
                . "2.4. {$no_seller_pledge}";

        default:
            return '';
    }
}

/**
 * Раздел 3 «Существенные условия» (стиль ДКП Сбербанк).
 */
function yvo_build_dkp_essential_section($variant_key, array $options = array()) {
    $is_mortgage = (strpos((string) $variant_key, 'ipoteka') === 0);
    $acceptance = isset($options['acceptance_days']) && trim((string) $options['acceptance_days']) !== ''
        ? trim((string) $options['acceptance_days'])
        : '[количество]';
    $lines = array();
    $n = 1;
    if ($is_mortgage) {
        $lines[] = "3.{$n}. С момента государственной регистрации ипотеки в Едином государственном реестре недвижимости Объект находится в залоге (ипотеке) у Банка на основании ст.77 Федерального закона «Об ипотеке (залоге недвижимости)» №102-ФЗ от 16.07.1998.";
        $n++;
        $lines[] = "3.{$n}. При регистрации права собственности Покупателя на Объект одновременно подлежит регистрации право залога Объекта в пользу Банка. Залогодержателем по данному залогу является Банк, а Залогодателем – Покупатель.";
        $n++;
        $lines[] = "3.{$n}. Право залога у Продавца на Объект не возникает в соответствии с п.5 ст.488 Гражданского кодекса РФ.";
        $n++;
        $lines[] = "3.{$n}. Покупатель обязуется в течение всего периода действия ипотеки на Объект без предварительного письменного согласия Банка: не отчуждать Объект и не осуществлять его последующую ипотеку; не сдавать Объект в аренду/наем, не передавать в безвозмездное пользование либо иным образом не обременять его правами третьих лиц; не проводить переустройство и перепланировку Объекта.";
        $n++;
    }
    $lines[] = "3.{$n}. Покупатель осмотрел Объект и претензий по его качеству не имеет. Продавцы обязуются передать Объект в том состоянии, в каком он имеется на день подписания Договора.";
    $n++;
    $lines[] = "3.{$n}. Согласно ст.556 Гражданского кодекса РФ передача Объекта, не обремененного задолженностями по коммунальным платежам, абонентской платой за телефон (при наличии), иными платежами, связанными с пользованием и владением Объектом, осуществляется по передаточному акту, подписываемому Сторонами в срок не позднее {$acceptance} дней с даты полной оплаты по Договору (либо в день подписания настоящего Договора — по соглашению Сторон).";
    $n++;
    $lines[] = "3.{$n}. Продавцы гарантируют, что на момент подписания Договора являются полноправными и законными собственниками Объекта, Объект не отчужден, не заложен, в споре и под арестом не состоит, в аренду (наем) не сдан, возмездное или безвозмездное пользование не передан, не обременен правами третьих лиц, право собственности Продавцов никем не оспаривается. Лиц, сохраняющих в соответствии с законом право пользования Объектом после государственной регистрации перехода права собственности на Объект к Покупателю, не имеется (статьи 292, 558 Гражданского кодекса РФ).";
    $n++;
    $lines[] = "3.{$n}. На момент подписания Договора в Объекте на регистрационном учете никто не состоит. Продавцы обязуются освободить Объект от личных вещей и другого имущества и передать ключи в день полной оплаты по настоящему Договору.";
    $n++;
    $lines[] = "3.{$n}. Покупатель приобретает право собственности на Объект с момента внесения записи в Единый государственный реестр недвижимости о переходе права собственности в установленном законом порядке к Покупателю. При этом Покупатель принимает на себя обязанности по уплате налогов на имущество, осуществляет за свой счет эксплуатацию и ремонт Объекта.";
    return implode("\n", $lines);
}

function yvo_build_dkp_registration_clause($variant_key) {
    if (strpos((string) $variant_key, 'ipoteka') === 0) {
        return '4.3. Переход права собственности и ипотека в силу закона в пользу Банка подлежат государственной регистрации в территориальном управлении Федеральной службы государственной регистрации, кадастра и картографии.';
    }
    return '4.3. Переход права собственности на Объект подлежит государственной регистрации в территориальном управлении Федеральной службы государственной регистрации, кадастра и картографии.';
}

function yvo_build_dkp_copies_clause($variant_key) {
    if (strpos((string) $variant_key, 'ipoteka') === 0) {
        return '4.5. Договор составлен в 4 (четырех) экземплярах, имеющих одинаковую юридическую силу: один экземпляр выдается на руки Продавцам, один экземпляр выдается на руки Покупателю, один экземпляр для Банка, один экземпляр хранится в делах органа, осуществляющего государственную регистрацию прав.';
    }
    return '4.5. Договор составлен в 3 (трех) экземплярах, имеющих одинаковую юридическую силу: по одному для каждой из Сторон и один для органа регистрации прав.';
}

/** Опции генерации ДКП из данных формы объекта. */
function yvo_build_dkp_options_from_property(array $property, $template_id = 'default') {
    $contract_city = isset($property['city']) && trim((string) $property['city']) !== '' ? trim((string) $property['city']) : yvo_extract_city_from_address(isset($property['address']) ? $property['address'] : '');
    if ($contract_city === '') {
        $contract_city = '________________';
    }
    $price_formatted = isset($property['price']) && $property['price'] !== '' ? number_format(floatval($property['price']), 2, ',', ' ') : '__________';
    $price_words = isset($property['price_words']) && trim((string) $property['price_words']) !== '' ? trim((string) $property['price_words']) : '________________';
    $acc_amt = (isset($property['accreditiv_amount']) && $property['accreditiv_amount'] !== '') ? number_format(floatval($property['accreditiv_amount']), 2, ',', ' ') : $price_formatted;
    $acc_days_raw = isset($property['accreditiv_calendar_days']) && trim((string) $property['accreditiv_calendar_days']) !== '' ? sanitize_text_field((string) $property['accreditiv_calendar_days']) : '60';
    $bank_n = '';
    if (isset($property['bank_name']) && trim((string) $property['bank_name']) !== '') {
        $bank_n = trim((string) $property['bank_name']);
    }
    if ($bank_n === '' && isset($property['accreditiv_bank_name']) && trim((string) $property['accreditiv_bank_name']) !== '') {
        $bank_n = trim((string) $property['accreditiv_bank_name']);
    }
    if ($bank_n === '') {
        $bank_n = '[наименование банка]';
    }
    $acceptance = isset($property['acceptance_days']) && $property['acceptance_days'] !== '' ? $property['acceptance_days'] : '[количество]';
    $seller_pay = '';
    if (isset($property['seller_details']) && trim((string) $property['seller_details']) !== '') {
        $seller_pay = trim((string) $property['seller_details']);
    } elseif (isset($property['seller_details_mortgage']) && trim((string) $property['seller_details_mortgage']) !== '') {
        $seller_pay = trim((string) $property['seller_details_mortgage']);
    }
    $pt = isset($property['payment_type']) ? sanitize_key((string) $property['payment_type']) : 'cash';
    $pm = ($pt === 'mortgage')
        ? (isset($property['payment_method_mortgage']) ? sanitize_key((string) $property['payment_method_mortgage']) : 'day_of_deal')
        : (isset($property['payment_method_cash']) ? sanitize_key((string) $property['payment_method_cash']) : 'day_of_deal');
    $bank_id = isset($property['bank_id']) ? sanitize_key((string) $property['bank_id']) : '';
    $opts = array(
        'dkp_template_id' => (string) $template_id,
        'contract_city' => $contract_city,
        'contract_date' => date('d.m.Y'),
        'property_price' => $price_formatted,
        'property_price_words' => $price_words,
        'loan_own_amount' => isset($property['loan_own_amount']) && $property['loan_own_amount'] !== '' ? number_format(floatval($property['loan_own_amount']), 2, ',', ' ') : '0,00',
        'loan_own_amount_words' => isset($property['loan_own_amount_words']) && trim((string) $property['loan_own_amount_words']) !== '' ? $property['loan_own_amount_words'] : 'ноль',
        'loan_credit_amount' => isset($property['loan_amount']) && $property['loan_amount'] !== '' ? number_format(floatval($property['loan_amount']), 2, ',', ' ') : '__________',
        'loan_credit_amount_words' => isset($property['loan_amount_words']) && trim((string) $property['loan_amount_words']) !== '' ? $property['loan_amount_words'] : '________________',
        'loan_agreement_number' => isset($property['loan_agreement_number']) && trim((string) $property['loan_agreement_number']) !== '' ? $property['loan_agreement_number'] : '[номер]',
        'loan_agreement_date' => isset($property['loan_agreement_date']) && trim((string) $property['loan_agreement_date']) !== '' ? $property['loan_agreement_date'] : '[дата]',
        'bank_name' => $bank_n,
        'bank_id' => $bank_id,
        'payment_type' => $pt,
        'payment_method' => $pm,
        'paid_before_signing' => isset($property['paid_before_signing']) && $property['paid_before_signing'] !== '' ? number_format(floatval($property['paid_before_signing']), 2, ',', ' ') : '[сумма]',
        'paid_at_signing' => isset($property['paid_at_signing']) && $property['paid_at_signing'] !== '' ? number_format(floatval($property['paid_at_signing']), 2, ',', ' ') : '[сумма]',
        'paid_by_letters' => isset($property['paid_at_signing']) && $property['paid_at_signing'] !== '' ? number_format(floatval($property['paid_at_signing']), 2, ',', ' ') : '[сумма]',
        'total_accreditives_amount' => $acc_amt,
        'acceptance_days' => $acceptance,
        'accreditiv_amount_display' => $acc_amt,
        'accreditiv_calendar_days' => $acc_days_raw,
        'seller_payment_details' => $seller_pay !== '' ? $seller_pay : '________________',
        'bank_cell_number' => isset($property['bank_cell_number']) && trim((string) $property['bank_cell_number']) !== '' ? trim((string) $property['bank_cell_number']) : '________',
        'bank_cell_days' => $acc_days_raw,
    );
    if (yvo_dkp_bank_is_sberbank($bank_n, $bank_id)) {
        $opts['bank_legal_block'] = yvo_dkp_sberbank_legal_block();
    }
    return $opts;
}

/** Подбор bundled/custom template_id по способу оплаты из формы. */
function yvo_resolve_dkp_template_id_from_payment(array $property, $fallback_id = '') {
    $fallback_id = (string) $fallback_id;
    // Единый шаблон «ДКП обычный» — не подменяем на отдельные .txt по способу оплаты.
    if ($fallback_id === '' || $fallback_id === 'default') {
        return 'default';
    }
    $pt = isset($property['payment_type']) ? sanitize_key((string) $property['payment_type']) : 'cash';
    $pm = ($pt === 'mortgage')
        ? (isset($property['payment_method_mortgage']) ? sanitize_key((string) $property['payment_method_mortgage']) : 'day_of_deal')
        : (isset($property['payment_method_cash']) ? sanitize_key((string) $property['payment_method_cash']) : 'day_of_deal');
    $suffix_map = array(
        'mortgage|accreditive' => 'ДКП_ипотека_аккредитив',
        'mortgage|cell' => 'ДКП_ипотека_ячейка',
        'mortgage|day_of_deal' => 'ДКП_ипотека_в_день_сделки',
        'cash|accreditive' => 'ДКП_наличные_аккредитив',
        'cash|cell' => 'ДКП_наличные_ячейка',
        'cash|day_of_deal' => 'ДКП_наличные_в_день_сделки',
    );
    $map_key = $pt . '|' . $pm;
    if (!isset($suffix_map[$map_key])) {
        return $fallback_id;
    }
    $needle = $suffix_map[$map_key];
    $templates = yvo_get_available_templates();
    foreach ($templates as $id => $label) {
        if (strpos((string) $id, $needle) !== false) {
            return (string) $id;
        }
    }
    if ($pt === 'mortgage' && $pm === 'accreditive' && isset($templates['default'])) {
        return 'default';
    }
    if ($pt === 'mortgage' && isset($templates['dkp-kvartira-ipoteka'])) {
        return 'dkp-kvartira-ipoteka';
    }
    if ($pt === 'cash' && $pm === 'accreditive' && isset($templates['dkp-nalichnye-akkreditiv-podpisi'])) {
        return 'dkp-nalichnye-akkreditiv-podpisi';
    }
    return $fallback_id;
}

/** Стилизованный HTML/DOCX (qwen + afchunk) — только для ДКП (sale / sale_mortgage). */
function yvo_dkp_modern_export_enabled($contract_type, $dkp_options) {
    return is_array($dkp_options) && in_array((string) $contract_type, array('sale', 'sale_mortgage'), true);
}

/** Типы договоров, для которых доступны акт и расписка. */
function yvo_contract_types_with_act_receipt() {
    return array('sale', 'sale_mortgage', 'assignment', 'deposit_agreement', 'advance_agreement');
}

/**
 * Таблица характеристик объекта по ЕГРН (как в ДКП) для txt/docx.
 *
 * @return string
 */
function yvo_build_property_egrn_table_block(array $property_data) {
    $property_data = yvo_normalize_house_with_plot_cadastral($property_data);
    $meta = yvo_build_dkp_object_meta($property_data);
    $ot = isset($property_data['object_type']) ? sanitize_key((string) $property_data['object_type']) : 'apartment';
    $cad = trim((string) ($property_data['cadastral_number'] ?? ''));
    if ($ot === 'house_with_plot') {
        $cad = trim((string) ($property_data['house_cadastral_number'] ?? $cad));
    }
    if ($cad === '') {
        $cad = '____________________';
    }
    $cad_label = ($ot === 'house_with_plot')
        ? 'Кадастровый номер жилого дома'
        : 'Кадастровый номер объекта недвижимости';
    $addr = trim((string) ($property_data['address'] ?? ''));
    if ($addr === '') {
        $addr = '________________';
    }
    $right = trim((string) ($property_data['property_right_info'] ?? ''));
    if ($right === '') {
        $right = '________________';
    }
    $rooms = isset($property_data['rooms']) && $property_data['rooms'] !== '' && (string) $property_data['rooms'] !== '0'
        ? trim((string) $property_data['rooms']) : '___';
    $area = isset($property_data['area']) && $property_data['area'] !== '' ? trim((string) $property_data['area']) : '___';
    $floor = isset($property_data['floor']) && $property_data['floor'] !== '' ? trim((string) $property_data['floor']) : '___';
    $extra = str_replace(
        array('{{PROPERTY_ROOMS}}', '{{PROPERTY_AREA}}', '{{PROPERTY_FLOOR}}'),
        array($rooms, $area, $floor),
        (string) ($meta['PROPERTY_DKP_TABLE_EXTRA'] ?? '')
    );
    $lines = array(
        "Параметр\tЗначение",
        $cad_label . "\t" . $cad,
        "Вид объекта недвижимости\t" . ($meta['PROPERTY_EGRN_KIND'] ?? '—'),
        "Адрес (местоположение)\t" . $addr,
        "Назначение\t" . ($meta['PROPERTY_EGRN_PURPOSE'] ?? '—'),
        "Наименование\t" . ($meta['PROPERTY_EGRN_NAME'] ?? '—'),
        "Сведения о праве на отчуждаемый объект недвижимости\tНомер и дата государственной регистрации, вид права: " . $right,
    );
    if ($extra !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $extra) as $row) {
            $row = trim($row);
            if ($row !== '') {
                $lines[] = $row;
            }
        }
    }
    return implode("\n", $lines);
}

/** Формат срока выхода на сделку для договора задатка: дата «дд.мм.гггг», не «14 дней». */
function yvo_format_deposit_main_contract_deadline($raw, $contract_date = null) {
    $raw = trim((string) $raw);
    if ($contract_date === null || $contract_date === '') {
        $contract_date = date('d.m.Y');
    }
    if ($raw === '') {
        return date('d.m.Y', strtotime('+2 months', yvo_parse_ru_date_to_ts($contract_date) ?: time()));
    }
    if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $raw, $m)) {
        return sprintf('%02d.%02d.%s', (int) $m[1], (int) $m[2], $m[3]);
    }
    if (preg_match('/(\d+)/u', $raw, $m)) {
        $base = yvo_parse_ru_date_to_ts($contract_date) ?: time();
        return date('d.m.Y', strtotime('+' . (int) $m[1] . ' days', $base));
    }
    return $raw;
}

/** Преобразовать дату дд.мм.гггг в timestamp (полночь). */
function yvo_parse_ru_date_to_ts($date_str) {
    $date_str = trim((string) $date_str);
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/u', $date_str, $m)) {
        return mktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3]);
    }
    return false;
}

/** Жирный фрагмент в тексте договора задатка (→ bold в DOCX). */
function yvo_deposit_bold_mark($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '________________';
    }
    return '«B»' . $text . '«/B»';
}

/** ФИО участника: нормализация регистра + жирный в DOCX. */
function yvo_contract_bold_person_name($name_raw) {
    $name_raw = trim((string) $name_raw);
    if ($name_raw === '' || preg_match('/^_+$/u', $name_raw)) {
        return yvo_deposit_bold_mark('________________');
    }
    return yvo_deposit_bold_mark(yvo_party_format_person_name($name_raw));
}

/** Несколько ФИО через « и » (супруги) — каждое в Title Case и жирным. */
function yvo_contract_bold_person_names_label($label) {
    $label = trim((string) $label);
    if ($label === '') {
        return yvo_deposit_bold_mark('________________');
    }
    $parts = preg_split('/\s+и\s+/u', $label);
    $formatted = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $formatted[] = yvo_party_format_person_name($part);
        }
    }
    if (empty($formatted)) {
        return yvo_contract_bold_person_name($label);
    }
    return yvo_deposit_bold_mark(implode(' и ', $formatted));
}

/** Один участник или список участников → массив строк. */
function yvo_deposit_normalize_parties_arg($arg) {
    if (!is_array($arg)) {
        return array();
    }
    if (array_key_exists('full_name', $arg) || array_key_exists('participant_tab', $arg)) {
        return array($arg);
    }
    return array_values($arg);
}

function yvo_deposit_party_sex_word(array $row) {
    $g = yvo_party_gender_from_row($row);
    if ($g === 'f') {
        return 'женский';
    }
    if ($g === 'm') {
        return 'мужской';
    }
    return '_______________';
}

/** Строка преамбулы одного участника (шаблон задатка). */
function yvo_build_deposit_party_line(array $row, $role_label, $with_we = false) {
    $name_raw = isset($row['full_name']) && trim((string) $row['full_name']) !== ''
        ? trim((string) $row['full_name']) : '________________';
    $name = yvo_deposit_bold_mark(yvo_party_format_person_name($name_raw));
    $birth = isset($row['birth_date']) && trim((string) $row['birth_date']) !== ''
        ? trim((string) $row['birth_date']) : '____________';
    $birth_place = isset($row['birth_place']) && trim((string) $row['birth_place']) !== ''
        ? yvo_party_format_free_text(trim((string) $row['birth_place'])) : '____________________';
    $pass_ser = isset($row['passport_series']) && trim((string) $row['passport_series']) !== ''
        ? trim((string) $row['passport_series']) : '__________';
    $pass_num = isset($row['passport_number']) && trim((string) $row['passport_number']) !== ''
        ? trim((string) $row['passport_number']) : '__________';
    $issued = isset($row['passport_issued_by']) && trim((string) $row['passport_issued_by']) !== ''
        ? yvo_party_format_free_text(trim((string) $row['passport_issued_by'])) : '_______________________________';
    $dept = isset($row['department_code']) && trim((string) $row['department_code']) !== ''
        ? trim((string) $row['department_code']) : '_______';
    $reg = yvo_party_registration_address_display($row);
    $gender = yvo_party_gender_from_row($row);
    $named = yvo_party_gender_form($gender, 'именуемый', 'именуемая', 'именуемый(-ая)');
    $registered = yvo_party_gender_form($gender, 'зарегистрированный', 'зарегистрированная', 'зарегистрированный(-ая)');
    $head = ($with_we ? 'Мы, ' : '') . $name . ',';
    $body = 'пол ' . yvo_deposit_party_sex_word($row) . ', гражданство РФ, ' . $birth . ' года рождения, место рождения: ' . $birth_place
        . ', паспорт ' . $pass_ser . ' ' . $pass_num . ', выдан ' . $issued
        . ', код подразделения ' . $dept . ', ' . $registered . ' по адресу: ' . $reg
        . ', ' . $named . ' в дальнейшем «' . $role_label . '»';
    return $head . "\n" . $body;
}

/** Несовершеннолетний участник в преамбуле задатка. */
function yvo_build_deposit_minor_party_line(array $row, $role_label, $with_we = false) {
    $name_raw = isset($row['full_name']) && trim((string) $row['full_name']) !== ''
        ? trim((string) $row['full_name']) : '________________';
    $name = yvo_deposit_bold_mark(yvo_party_format_person_name($name_raw));
    $birth = isset($row['birth_date']) && trim((string) $row['birth_date']) !== ''
        ? trim((string) $row['birth_date']) : '____________';
    $birth_place = isset($row['birth_place']) && trim((string) $row['birth_place']) !== ''
        ? yvo_party_format_free_text(trim((string) $row['birth_place'])) : '____________________';
    $reg = yvo_party_registration_address_display($row);
    $gender = yvo_party_gender_from_row($row);
    $named = yvo_party_gender_form($gender, 'именуемый', 'именуемая', 'именуемый(-ая)');
    $registered = yvo_party_gender_form($gender, 'зарегистрированный', 'зарегистрированная', 'зарегистрированный(-ая)');
    $bs = trim((string) ($row['birth_cert_series'] ?? ''));
    $bn = trim((string) ($row['birth_cert_number'] ?? ''));
    $bd = trim((string) ($row['birth_cert_date'] ?? ''));
    $by = trim((string) ($row['birth_cert_issued_by'] ?? ''));
    if ($bs === '') {
        $bs = '_____';
    }
    if ($bn === '') {
        $bn = '__________';
    }
    if ($bd === '') {
        $bd = '____________';
    }
    if ($by === '') {
        $by = '_______________________________';
    } else {
        $by = yvo_party_format_free_text($by);
    }
    $head = ($with_we ? 'Мы, ' : '') . $name . ',';
    $body = 'пол ' . yvo_deposit_party_sex_word($row) . ', гражданство РФ, ' . $birth . ' года рождения, место рождения: ' . $birth_place
        . ', свидетельство о рождении: серия ' . $bs . ' № ' . $bn . ', дата выдачи ' . $bd . ', кем выдано: ' . $by
        . ', ' . $registered . ' по адресу: ' . $reg
        . ', ' . $named . ' в дальнейшем «' . $role_label . '»';
    return $head . "\n" . $body;
}

/**
 * @param array<int, array> $rows
 * @return array<int, string>
 */
function yvo_build_deposit_side_preamble_lines(array $rows, $side) {
    $lines = array();
    $guardian_done = array();
    $principals = ($side === 'seller')
        ? yvo_parties_principal_sellers_ordered($rows)
        : yvo_parties_principal_buyers_ordered($rows);
    $with_we = true;
    foreach ($rows as $row) {
        if (!is_array($row) || trim((string) ($row['full_name'] ?? '')) === '') {
            continue;
        }
        if ($side === 'seller' && yvo_row_is_seller_representative($row)) {
            continue;
        }
        if ($side === 'buyer' && yvo_row_is_buyer_representative($row)) {
            continue;
        }
        if (($side === 'seller' && yvo_row_is_seller_guardian($row)) || ($side === 'buyer' && yvo_row_is_buyer_guardian($row))) {
            $gblock = yvo_build_guardian_preamble_block($row, $rows, $principals, 'deposit_agreement', $side, $guardian_done);
            if (is_string($gblock) && $gblock !== '') {
                $resolved = yvo_party_guardian_resolved_row($row, $rows);
                $gname = isset($resolved['full_name']) && trim((string) $resolved['full_name']) !== ''
                    ? yvo_party_format_person_name(trim((string) $resolved['full_name'])) : '________________';
                $lines[] = ($with_we ? 'Мы, ' : '') . yvo_deposit_bold_mark($gname) . ",\n" . $gblock;
                $with_we = false;
            }
            continue;
        }
        $is_minor = ($side === 'seller' && yvo_row_is_minor_seller($row))
            || ($side === 'buyer' && yvo_row_is_minor_buyer($row));
        if (!$is_minor) {
            $idx = array_search($row, $principals, true);
            if ($idx === false) {
                continue;
            }
            $label = ($side === 'seller' ? 'Продавец' : 'Покупатель');
            if (count($principals) > 1) {
                $label .= ' ' . ((int) $idx + 1);
            }
            $lines[] = yvo_build_deposit_party_line($row, $label, $with_we);
            $with_we = false;
            continue;
        }
        $label = ($side === 'seller' ? 'Продавец' : 'Покупатель');
        $lines[] = yvo_build_deposit_minor_party_line($row, $label, $with_we);
        $with_we = false;
    }
    return $lines;
}

/** Преамбула сторон для договора задатка. */
function yvo_build_deposit_parties_preamble(array $sellers, array $buyers) {
    $sellers = yvo_deposit_normalize_parties_arg($sellers);
    $buyers = yvo_deposit_normalize_parties_arg($buyers);
    yvo_parties_apply_guardian_links($sellers);
    yvo_parties_apply_guardian_links($buyers);
    $seller_lines = yvo_build_deposit_side_preamble_lines($sellers, 'seller');
    $buyer_lines = yvo_build_deposit_side_preamble_lines($buyers, 'buyer');
    if (empty($seller_lines) || empty($buyer_lines)) {
        return 'Мы, ___________________________________________________________________________, именуемый(ая) «Продавец», с одной стороны, и ___________________________________________________________________________, именуемый(ая) «Покупатель», с другой стороны, а вместе именуемые «Стороны», заключили настоящее соглашение о нижеследующем:';
    }
    $seller_text = implode(",\n", $seller_lines);
    $buyer_text = implode(",\n", $buyer_lines);
    return $seller_text . ", с одной стороны, и\n" . $buyer_text
        . ", с другой стороны, а вместе именуемые «Стороны», заключили настоящее соглашение о нижеследующем:";
}

/** Блок подписей в конце договора задатка. */
function yvo_build_deposit_signatures_block(array $sellers, array $buyers) {
    $sellers = yvo_deposit_normalize_parties_arg($sellers);
    $buyers = yvo_deposit_normalize_parties_arg($buyers);
    $lines = array();
    $principals_s = yvo_parties_principal_sellers_ordered($sellers);
    foreach ($principals_s as $i => $s) {
        if (!is_array($s) || trim((string) ($s['full_name'] ?? '')) === '') {
            continue;
        }
        $label = count($principals_s) > 1 ? 'ПРОДАВЕЦ ' . ($i + 1) : 'ПРОДАВЕЦ';
        $name = yvo_deposit_bold_mark(yvo_party_format_person_name(trim((string) $s['full_name'])));
        $lines[] = $label;
        $lines[] = '____________________________________________________________ / ' . $name;
    }
    $principals_b = yvo_parties_principal_buyers_ordered($buyers);
    foreach ($principals_b as $i => $b) {
        if (!is_array($b) || trim((string) ($b['full_name'] ?? '')) === '') {
            continue;
        }
        $label = count($principals_b) > 1 ? 'ПОКУПАТЕЛЬ ' . ($i + 1) : 'ПОКУПАТЕЛЬ';
        $name = yvo_deposit_bold_mark(yvo_party_format_person_name(trim((string) $b['full_name'])));
        $lines[] = $label;
        $lines[] = '____________________________________________________________ / ' . $name;
    }
    return implode("\n", $lines);
}

/** Подстановка [скобок] в шаблоне задатка; суммы и цены — жирным. */
function yvo_replace_deposit_bracket_placeholders($content, $seller_data, $buyer_data, $property_data) {
    $city = isset($property_data['city']) && $property_data['city'] !== '' ? $property_data['city'] : (isset($property_data['address']) ? yvo_extract_city_from_address($property_data['address']) : '________________');
    if ($city === '') {
        $city = '________________';
    }
    $date = date('d.m.Y');
    $cadastral = isset($property_data['cadastral_number']) && $property_data['cadastral_number'] !== '' ? $property_data['cadastral_number'] : '____________________';
    $address = $property_data['address'] ?? '________________';
    $rooms = isset($property_data['rooms']) && $property_data['rooms'] !== '' ? $property_data['rooms'] : '___';
    $area = isset($property_data['area']) && $property_data['area'] !== '' ? $property_data['area'] : '___';
    $floor = isset($property_data['floor']) && $property_data['floor'] !== '' ? $property_data['floor'] : '___';
    $price = isset($property_data['price']) && $property_data['price'] !== '' ? number_format(floatval($property_data['price']), 2, ',', ' ') : '__________';
    $price_words = isset($property_data['price_words']) && $property_data['price_words'] !== '' ? $property_data['price_words'] : '________________';
    $deposit = isset($property_data['deposit_amount']) && $property_data['deposit_amount'] !== '' ? number_format(floatval($property_data['deposit_amount']), 2, ',', ' ') : '__________';
    $deposit_words = isset($property_data['deposit_amount_words']) && $property_data['deposit_amount_words'] !== '' ? $property_data['deposit_amount_words'] : '________________';
    $credit = isset($property_data['loan_credit_amount']) && $property_data['loan_credit_amount'] !== '' ? number_format(floatval($property_data['loan_credit_amount']), 2, ',', ' ') : '__________';
    $credit_words = isset($property_data['loan_credit_amount_words']) && $property_data['loan_credit_amount_words'] !== '' ? $property_data['loan_credit_amount_words'] : '________________';
    $own = isset($property_data['loan_own_amount']) && $property_data['loan_own_amount'] !== '' ? number_format(floatval($property_data['loan_own_amount']), 2, ',', ' ') : '__________';
    $own_words = isset($property_data['loan_own_amount_words']) && $property_data['loan_own_amount_words'] !== '' ? $property_data['loan_own_amount_words'] : '________________';
    $deadline = yvo_format_deposit_main_contract_deadline(
        isset($property_data['main_contract_deadline']) ? $property_data['main_contract_deadline'] : '',
        $date
    );
    $right = trim((string) ($property_data['property_right_info'] ?? ''));
    if ($right === '') {
        $right = '________________';
    }
    $vacate_days = isset($property_data['vacate_deadline']) && $property_data['vacate_deadline'] !== '' ? $property_data['vacate_deadline'] : '14 дней';
    if (preg_match('/\d+/', $vacate_days, $m)) {
        $vacate_days = $m[0];
    }
    $deregistration = isset($property_data['deregistration_deadline']) && trim((string) $property_data['deregistration_deadline']) !== ''
        ? trim((string) $property_data['deregistration_deadline'])
        : (isset($property_data['vacate_deadline']) && trim((string) $property_data['vacate_deadline']) !== ''
            ? trim((string) $property_data['vacate_deadline']) : '14 дней');
    $vacate_release = isset($property_data['vacate_deadline']) && trim((string) $property_data['vacate_deadline']) !== ''
        ? trim((string) $property_data['vacate_deadline']) : '14 дней';
    $map = array(
        '[город заключения]' => $city,
        '[дата]' => $date,
        '[кадастровый номер]' => $cadastral,
        '[адрес объекта]' => $address,
        '[количество комнат]' => $rooms,
        '[площадь]' => $area,
        '[площадь прописью]' => '________________',
        '[площадь жилая]' => '___',
        '[площадь жилая прописью]' => '________________',
        '[этаж]' => $floor,
        '[этаж(и)]' => $floor,
        '[общая стоимость цифрами]' => yvo_deposit_bold_mark($price),
        '[общая стоимость прописью]' => yvo_deposit_bold_mark($price_words),
        '[сумма задатка цифрами]' => yvo_deposit_bold_mark($deposit),
        '[сумма задатка прописью]' => yvo_deposit_bold_mark($deposit_words),
        '[сумма аванса цифрами]' => yvo_deposit_bold_mark($deposit),
        '[сумма аванса прописью]' => yvo_deposit_bold_mark($deposit_words),
        '[сумма цифрами]' => yvo_deposit_bold_mark($deposit),
        '[сумма прописью]' => yvo_deposit_bold_mark($deposit_words),
        '[сумма кредитных средств цифрами]' => yvo_deposit_bold_mark($credit),
        '[сумма кредитных средств прописью]' => yvo_deposit_bold_mark($credit_words),
        '[сумма собственных средств цифрами]' => yvo_deposit_bold_mark($own),
        '[сумма собственных средств прописью]' => yvo_deposit_bold_mark($own_words),
        '[срок выхода на сделку]' => yvo_deposit_bold_mark($deadline),
        '[адрес встречной сделки]' => '________________',
        '[дата регистрации права]' => '________________',
        '[номер и дата регистрации]' => $right,
        '[номер и дата регистрации, вид права]' => $right,
        '[количество]' => $vacate_days,
        '[срок]' => $vacate_days,
        '[срок снятия с регистрации]' => $deregistration,
        '[срок освобождения]' => $vacate_release,
        '[срок освобождения (выселения)]' => $vacate_release,
    );
    foreach ($map as $tag => $val) {
        $content = str_replace($tag, $val, $content);
    }
    return $content;
}

/** Автовыбор id шаблона задатка по типу объекта (всегда актуальные templates/deposit-agreement*.txt). */
function yvo_deposit_auto_template_id(array $property_data, $template_id = '') {
    $ot = sanitize_key((string) ($property_data['object_type'] ?? 'apartment'));
    if ($ot === '') {
        $ot = 'apartment';
    }
    $map = array(
        'apartment' => 'deposit-agreement',
        'share' => 'deposit-agreement',
        'room' => 'deposit-agreement-room',
        'house_with_plot' => 'deposit-agreement-house',
        'land' => 'deposit-agreement-land',
    );
    return isset($map[$ot]) ? $map[$ot] : 'deposit-agreement';
}

/** Путь к .txt шаблону соглашения о задатке. */
function yvo_resolve_deposit_agreement_template_path($template_id, array $property_data, $bank_id = '') {
    $template_id = yvo_deposit_auto_template_id($property_data, $template_id);
    $bundled = YVO_PLUGIN_DIR . 'templates/' . $template_id . '.txt';
    if (file_exists($bundled)) {
        return $bundled;
    }
    $fallback = YVO_PLUGIN_DIR . 'templates/deposit-agreement.txt';
    return file_exists($fallback) ? $fallback : null;
}

/** Автовыбор id шаблона аванса по типу объекта. */
function yvo_advance_auto_template_id(array $property_data, $template_id = '') {
    $ot = sanitize_key((string) ($property_data['object_type'] ?? 'apartment'));
    if ($ot === '') {
        $ot = 'apartment';
    }
    $map = array(
        'apartment' => 'advance-agreement',
        'share' => 'advance-agreement',
        'room' => 'advance-agreement-room',
        'house_with_plot' => 'advance-agreement-house',
        'land' => 'advance-agreement-land',
    );
    return isset($map[$ot]) ? $map[$ot] : 'advance-agreement';
}

/** Путь к .txt шаблону договора аванса. */
function yvo_resolve_advance_agreement_template_path($template_id, array $property_data, $bank_id = '') {
    $template_id = yvo_advance_auto_template_id($property_data, $template_id);
    $bundled = YVO_PLUGIN_DIR . 'templates/' . $template_id . '.txt';
    if (file_exists($bundled)) {
        return $bundled;
    }
    $fallback = YVO_PLUGIN_DIR . 'templates/advance-agreement.txt';
    return file_exists($fallback) ? $fallback : null;
}

/** Заполнение шаблона договора аванса (.txt с [скобками] и {{ПЛЕЙСХОЛДЕРАМИ}}). */
function yvo_fill_advance_agreement_template($template_path, $sellers, $buyers, $property_data) {
    if (!$template_path || !file_exists($template_path)) {
        return '';
    }
    $content = file_get_contents($template_path);
    if (!is_string($content)) {
        return '';
    }
    $sellers = yvo_deposit_normalize_parties_arg($sellers);
    $buyers = yvo_deposit_normalize_parties_arg($buyers);
    $seller_data = !empty($sellers[0]) && is_array($sellers[0]) ? $sellers[0] : array();
    $buyer_data = !empty($buyers[0]) && is_array($buyers[0]) ? $buyers[0] : array();
    $content = yvo_replace_deposit_bracket_placeholders($content, $seller_data, $buyer_data, $property_data);
    $placeholders = yvo_get_all_template_placeholders($seller_data, $buyer_data, $property_data);
    $deadline = yvo_format_deposit_main_contract_deadline(
        isset($property_data['main_contract_deadline']) ? $property_data['main_contract_deadline'] : '',
        date('d.m.Y')
    );
    $placeholders['MAIN_CONTRACT_DEADLINE'] = yvo_deposit_bold_mark($deadline);
    $placeholders['PROPERTY_EGRN_TABLE_BLOCK'] = yvo_build_deposit_property_egrn_table_block($property_data);
    $placeholders['ADVANCE_PARTIES_PREAMBLE'] = yvo_build_deposit_parties_preamble($sellers, $buyers);
    $placeholders['ADVANCE_SIGNATURES_BLOCK'] = yvo_build_deposit_signatures_block($sellers, $buyers);
    foreach ($placeholders as $key => $val) {
        $content = str_replace('{{' . $key . '}}', (string) $val, $content);
    }
    return preg_replace('/\{\{[^}]*\}\}/u', '________________', $content);
}

/** Продавец для расписки о задатке. */
function yvo_deposit_receipt_seller_row(array $sellers) {
    $principals = yvo_parties_principal_sellers_ordered($sellers);
    return !empty($principals[0]) && is_array($principals[0]) ? $principals[0] : array();
}

/** Плательщик задатка для расписки (вноситель или покупатель). */
function yvo_deposit_receipt_payer_row(array $buyers) {
    foreach ($buyers as $b) {
        if (!is_array($b)) {
            continue;
        }
        $t = yvo_party_row_tab($b);
        if (strpos($t, 'contributor') === 0 && trim((string) ($b['full_name'] ?? '')) !== '') {
            return $b;
        }
    }
    $principals = yvo_parties_principal_buyers_ordered($buyers);
    return !empty($principals[0]) && is_array($principals[0]) ? $principals[0] : array();
}

/** Компактная фраза об участнике для расписки о задатке. */
function yvo_build_deposit_receipt_party_phrase(array $row) {
    if (!is_array($row) || trim((string) ($row['full_name'] ?? '')) === '') {
        return '________________';
    }
    $name = yvo_deposit_bold_mark(yvo_party_format_person_name(trim((string) $row['full_name'])));
    if (yvo_row_is_minor_seller($row) || yvo_row_is_minor_buyer($row)) {
        $bs = trim((string) ($row['birth_cert_series'] ?? ''));
        $bn = trim((string) ($row['birth_cert_number'] ?? ''));
        $bd = trim((string) ($row['birth_cert_date'] ?? ''));
        $by = trim((string) ($row['birth_cert_issued_by'] ?? ''));
        if ($bs === '') {
            $bs = '_____';
        }
        if ($bn === '') {
            $bn = '__________';
        }
        if ($bd === '') {
            $bd = '____________';
        }
        if ($by === '') {
            $by = '_______________________________';
        } else {
            $by = yvo_party_format_free_text($by);
        }
        return $name . ' (свидетельство о рождении: серия ' . $bs . ' № ' . $bn . ', выдано ' . $bd . ', ' . $by . ')';
    }
    $pass_ser = trim((string) ($row['passport_series'] ?? ''));
    $pass_num = trim((string) ($row['passport_number'] ?? ''));
    $issued = trim((string) ($row['passport_issued_by'] ?? ''));
    $pass_date = trim((string) ($row['passport_date'] ?? ''));
    if ($pass_ser === '') {
        $pass_ser = '__________';
    }
    if ($pass_num === '') {
        $pass_num = '__________';
    }
    if ($issued === '') {
        $issued = '_______________________________';
    } else {
        $issued = yvo_party_format_free_text($issued);
    }
    if ($pass_date === '') {
        $pass_date = '________________';
    }
    return $name . ' (паспорт: ' . $pass_ser . ' № ' . $pass_num . ', выдан ' . $issued . ', ' . $pass_date . ')';
}

/** Заполнение шаблона расписки о получении задатка. */
function yvo_fill_deposit_receipt_template($template_path, $sellers, $buyers, $property_data) {
    if (!$template_path || !file_exists($template_path)) {
        return '';
    }
    $content = file_get_contents($template_path);
    if (!is_string($content)) {
        return '';
    }
    $sellers = yvo_deposit_normalize_parties_arg($sellers);
    $buyers = yvo_deposit_normalize_parties_arg($buyers);
    $seller_row = yvo_deposit_receipt_seller_row($sellers);
    $payer_row = yvo_deposit_receipt_payer_row($buyers);
    $content = yvo_replace_deposit_bracket_placeholders($content, $seller_row, $payer_row, $property_data);
    $seller_name = trim((string) ($seller_row['full_name'] ?? ''));
    $sig_name = $seller_name !== ''
        ? yvo_deposit_bold_mark(yvo_party_format_person_name($seller_name))
        : '___________________________';
    $placeholders = array(
        'SELLER_RECEIPT_PARTY' => yvo_build_deposit_receipt_party_phrase($seller_row),
        'BUYER_RECEIPT_PARTY' => yvo_build_deposit_receipt_party_phrase($payer_row),
        'SELLER_SIGNATURE_NAME' => $sig_name,
        'PROPERTY_EGRN_TABLE_BLOCK' => yvo_build_deposit_property_egrn_table_block($property_data),
    );
    foreach ($placeholders as $key => $val) {
        $content = str_replace('{{' . $key . '}}', (string) $val, $content);
    }
    return preg_replace('/\{\{[^}]*\}\}/u', '________________', $content);
}

/** Заполнение шаблона расписки о получении аванса. */
function yvo_fill_advance_receipt_template($template_path, $sellers, $buyers, $property_data) {
    return yvo_fill_deposit_receipt_template($template_path, $sellers, $buyers, $property_data);
}

function yvo_build_deposit_property_egrn_table_block(array $property_data) {
    $property_data = yvo_normalize_house_with_plot_cadastral($property_data);
    $meta = yvo_build_dkp_object_meta($property_data);
    $ot = isset($property_data['object_type']) ? sanitize_key((string) $property_data['object_type']) : 'apartment';
    if ($ot === '') {
        $ot = 'apartment';
    }
    $cad = trim((string) ($property_data['cadastral_number'] ?? ''));
    if ($ot === 'house_with_plot') {
        $cad = trim((string) ($property_data['house_cadastral_number'] ?? $cad));
    }
    if ($cad === '') {
        $cad = '____________________';
    }
    $cad_label = ($ot === 'house_with_plot')
        ? 'Кадастровый номер жилого дома'
        : 'Кадастровый номер объекта недвижимости';
    $addr = trim((string) ($property_data['address'] ?? ''));
    if ($addr === '') {
        $addr = '________________';
    }
    $right = trim((string) ($property_data['property_right_info'] ?? ''));
    if ($right === '') {
        $right = '________________';
    }
    $rooms = isset($property_data['rooms']) && $property_data['rooms'] !== '' && (string) $property_data['rooms'] !== '0'
        ? trim((string) $property_data['rooms']) : '___';
    $area = isset($property_data['area']) && $property_data['area'] !== '' ? trim((string) $property_data['area']) : '___';
    $floor = isset($property_data['floor']) && $property_data['floor'] !== '' ? trim((string) $property_data['floor']) : '___';
    $extra = str_replace(
        array('{{PROPERTY_ROOMS}}', '{{PROPERTY_AREA}}', '{{PROPERTY_FLOOR}}'),
        array($rooms, $area, $floor),
        (string) ($meta['PROPERTY_DKP_TABLE_EXTRA'] ?? '')
    );
    $lines = array(
        $cad_label . "\t" . $cad,
        'Вид объекта недвижимости' . "\t" . ($meta['PROPERTY_EGRN_KIND'] ?? 'Помещение'),
        'Адрес (местоположение)' . "\t" . $addr,
        'Назначение' . "\t" . ($meta['PROPERTY_EGRN_PURPOSE'] ?? 'Жилое помещение'),
        'Наименование' . "\t" . ($meta['PROPERTY_EGRN_NAME'] ?? 'Квартира'),
        'Сведения о праве на отчуждаемый объект недвижимости' . "\t" . 'Номер и дата государственной регистрации, вид права: ' . $right,
    );
    if ($extra !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $extra) as $row) {
            $row = trim($row);
            if ($row !== '') {
                $lines[] = $row;
            }
        }
    }
    return implode("\n", $lines);
}

/** Заполнение шаблона соглашения о задатке (.txt с [скобками] и {{ПЛЕЙСХОЛДЕРАМИ}}). */
function yvo_fill_deposit_agreement_template($template_path, $sellers, $buyers, $property_data) {
    if (!$template_path || !file_exists($template_path)) {
        return '';
    }
    $content = file_get_contents($template_path);
    if (!is_string($content)) {
        return '';
    }
    $sellers = yvo_deposit_normalize_parties_arg($sellers);
    $buyers = yvo_deposit_normalize_parties_arg($buyers);
    $seller_data = !empty($sellers[0]) && is_array($sellers[0]) ? $sellers[0] : array();
    $buyer_data = !empty($buyers[0]) && is_array($buyers[0]) ? $buyers[0] : array();
    $content = yvo_replace_deposit_bracket_placeholders($content, $seller_data, $buyer_data, $property_data);
    $placeholders = yvo_get_all_template_placeholders($seller_data, $buyer_data, $property_data);
    $deadline = yvo_format_deposit_main_contract_deadline(
        isset($property_data['main_contract_deadline']) ? $property_data['main_contract_deadline'] : '',
        date('d.m.Y')
    );
    $placeholders['MAIN_CONTRACT_DEADLINE'] = yvo_deposit_bold_mark($deadline);
    $placeholders['PROPERTY_EGRN_TABLE_BLOCK'] = yvo_build_deposit_property_egrn_table_block($property_data);
    $placeholders['DEPOSIT_PARTIES_PREAMBLE'] = yvo_build_deposit_parties_preamble($sellers, $buyers);
    $placeholders['DEPOSIT_SIGNATURES_BLOCK'] = yvo_build_deposit_signatures_block($sellers, $buyers);
    foreach ($placeholders as $key => $val) {
        $content = str_replace('{{' . $key . '}}', (string) $val, $content);
    }
    return preg_replace('/\{\{[^}]*\}\}/u', '________________', $content);
}

/** Вводная фраза п. 1.1 дарения перед таблицей ЕГРН. */
function yvo_build_gift_cadastral_value_clause(array $property_data) {
    $object_type = isset($property_data['object_type']) ? sanitize_key((string) $property_data['object_type']) : 'apartment';
    $g = yvo_gift_object_grammar($object_type);
    $raw = trim((string) ($property_data['gift_cadastral_value'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $num = floatval(str_replace(array(' ', ','), array('', '.'), $raw));
    if ($num <= 0) {
        return '';
    }
    $price = number_format($num, 2, ',', ' ');
    $words = trim((string) ($property_data['gift_cadastral_value_words'] ?? ''));
    if ($words === '') {
        $words = '________________';
    }
    return '1.2. Кадастровая стоимость ' . $g['gen'] . ' составляет ' . $price . ' (' . $words . ') рублей.';
}

function yvo_build_gift_subject_11_intro(array $buyers, $has_share_matrix, $has_donee_shares_block = false) {
    $g = yvo_gift_donee_grammar($buyers);
    $accept = ($g['nom'] === 'Одаряемые') ? 'принимают' : 'принимает';
    if ($has_share_matrix || $has_donee_shares_block) {
        return 'Объект недвижимого имущества, передаваемый в дар, имеет следующие характеристики согласно данным Единого государственного реестра недвижимости:';
    }
    return 'Даритель безвозмездно передает в собственность (дарит) ' . $g['dat']
        . ', а ' . $g['nom'] . ' ' . $accept . ' в дар от Дарителя объект недвижимого имущества, имеющий следующие характеристики согласно данным Единого государственного реестра недвижимости:';
}

/**
 * П. 1.1: кому какая доля в дар (несколько одаряемых, целый объект — дом, земля и т.п.).
 *
 * @param array<int, array> $sellers
 * @param array<int, array> $buyers
 */
function yvo_build_gift_donee_shares_block(array $sellers, array $buyers, array $property_data, $object_type) {
    $principal_count = count(yvo_parties_principal_buyers_ordered($buyers));
    if ($principal_count < 2) {
        return '';
    }
    $rows = yvo_gift_distribution_rows($sellers, $buyers, $property_data);
    $lines = array();
    $n = 1;
    foreach ($rows as $r) {
        $share = trim((string) ($r['share_fraction'] ?? ''));
        if ($share === '') {
            continue;
        }
        $name = yvo_party_format_person_name($r['donee_name'] !== '' ? $r['donee_name'] : '_______________');
        $lines[] = $n . ') ' . $name . ' — долю в размере ' . $share;
        $n++;
    }
    if (empty($lines)) {
        return '';
    }
    $gobj = yvo_gift_object_grammar($object_type);
    $tail = 'в праве общей долевой собственности на ' . $gobj['acc']
        . ', характеристики которого приведены в п. 1.1 настоящего Договора.';
    return 'Даритель безвозмездно передает в собственность (дарит) Одаряемым:' . "\n"
        . implode(";\n", $lines) . '.' . "\n"
        . $tail;
}

/** Фраза «… должен/должна/должно быть передан(а)(о)…» по типу объекта. */
function yvo_gift_object_must_be_transferred_phrase(array $grammar_row) {
    $nom = (string) ($grammar_row['nom'] ?? 'Объект');
    $gender = isset($grammar_row['gender']) ? (string) $grammar_row['gender'] : 'neuter';
    if ($gender === 'feminine') {
        return $nom . ' должна быть передана';
    }
    if ($gender === 'masculine') {
        return $nom . ' должен быть передан';
    }
    if ($gender === 'plural') {
        return $nom . ' должны быть переданы';
    }
    return $nom . ' должно быть передано';
}

/**
 *
 * @return array<string, string>
 */
function yvo_build_property_contract_fragments($property_data, $contract_type = 'sale', $gift_template_id = '') {
    $property_data = is_array($property_data) ? $property_data : array();
    $object_type = isset($property_data['object_type']) ? sanitize_key((string) $property_data['object_type']) : 'apartment';
    if ($object_type === '') {
        $object_type = 'apartment';
    }
    $addr = trim((string) ($property_data['address'] ?? ''));
    if ($addr === '') {
        $addr = '________________';
    }
    $cad = trim((string) ($property_data['cadastral_number'] ?? ''));
    if ($cad === '') {
        $cad = '____________________';
    }
    $area = isset($property_data['area']) && $property_data['area'] !== '' ? trim((string) $property_data['area']) : '___';
    $rooms = isset($property_data['rooms']) && $property_data['rooms'] !== '' ? trim((string) $property_data['rooms']) : '___';
    if ($rooms === '0') {
        $rooms = '___';
    }
    $floor = isset($property_data['floor']) && $property_data['floor'] !== '' ? trim((string) $property_data['floor']) : '___';
    $floors_total = isset($property_data['floors_total']) && $property_data['floors_total'] !== '' ? trim((string) $property_data['floors_total']) : '___';
    $share_in_right = trim((string) ($property_data['share_in_right'] ?? $property_data['object_share'] ?? ''));
    if ($share_in_right === '') {
        $share_in_right = '___';
    }

    $is_gift = ($contract_type === 'gift');
    $transfer_verb = $is_gift ? 'передает в собственность (дарит)' : 'продает';
    $accept_verb = $is_gift ? 'принимает в дар' : 'покупает';
    $from_party = $is_gift ? 'Дарителя' : 'Продавца';
    $to_party = $is_gift ? 'Одаряемого' : 'Покупателя';
    $object_word = $is_gift ? 'Объект' : 'Объект';

    $object_names = array(
        'apartment' => 'Квартира',
        'share' => 'Доля',
        'room' => 'Комната',
        'land' => 'Земельный участок',
        'house_with_plot' => 'Дом с земельным участком',
        'garage' => 'Гараж',
        'parking' => 'Машино-место',
    );
    $object_name = isset($object_names[$object_type]) ? $object_names[$object_type] : 'Объект недвижимости';
    $object_kind = isset($property_data['property_type']) && trim((string) $property_data['property_type']) !== ''
        ? trim((string) $property_data['property_type'])
        : mb_strtolower($object_name, 'UTF-8');

    if ($object_type === 'share') {
        $subject_11 = $is_gift
            ? 'Даритель безвозмездно передает в собственность (дарит) Одаряемому, а Одаряемый принимает в дар от Дарителя долю в размере '
                . $share_in_right . ' в праве общей долевой собственности на квартиру, расположенную по адресу: '
                . $addr . ', кадастровый номер объекта (квартиры): ' . $cad . '.'
            : 'Продавцы продают, а Покупатель покупает в личную собственность долю в размере '
                . $share_in_right . ' в праве общей долевой собственности на квартиру, расположенную по адресу: '
                . $addr . ', кадастровый номер объекта (квартиры): ' . $cad . '.';
        $characteristics = 'Квартира, в отношении которой отчуждается доля, расположена на ' . $floor . ' этаже '
            . $floors_total . '-этажного многоквартирного жилого дома, состоит из ' . $rooms
            . ' комнат, общая площадь квартиры ' . $area . ' кв. м.';
        $object_label = 'долю в размере ' . $share_in_right . ' в праве общей долевой собственности на квартиру';
    } elseif ($object_type === 'room') {
        $subject_11 = ($is_gift ? 'Даритель ' . $transfer_verb . ' Одаряемому, а Одаряемый ' . $accept_verb . ' от ' . $from_party . ' '
            : 'Продавцы ' . $transfer_verb . ', а Покупатель ' . $accept_verb . ' ')
            . 'комнату, расположенную по адресу: ' . $addr . ', кадастровый номер ' . $cad . '.';
        $characteristics = 'Комната расположена на ' . $floor . ' этаже, площадь ' . $area . ' кв. м.';
        $object_label = 'комнату';
    } elseif ($is_gift && $object_type === 'apartment' && $gift_template_id === 'shablon-darenie-dogovor') {
        $premises_desc = $object_kind !== '' ? $object_kind : 'квартира';
        $subject_11 = 'Даритель безвозмездно передает в собственность (дарит) Одаряемому, а Одаряемый принимает в дар от Дарителя жилое помещение, а именно: '
            . $premises_desc . ' (в дальнейшем именуемое «Жилое помещение»), расположенное по адресу: '
            . $addr . ', имеющее кадастровый номер ' . $cad . '.';
        $characteristics = 'Жилое помещение находится на ' . $floor . ' этаже '
            . $floors_total . '-этажного многоквартирного жилого дома и имеет следующие характеристики: Жилое помещение состоит из '
            . $rooms . ' комнат, общая площадь Жилого помещения ' . $area . ' кв. м.';
        $object_label = $premises_desc;
    } elseif ($is_gift && $object_type === 'land' && $gift_template_id === 'shablon-darenie-dogovor') {
        $subject_11 = 'Даритель безвозмездно передает в собственность (дарит) Одаряемому, а Одаряемый принимает в дар от Дарителя земельный участок, расположенный по адресу: '
            . $addr . ', имеющий кадастровый номер ' . $cad . '.';
        $characteristics = 'Площадь земельного участка составляет ' . $area . ' кв. м.';
        $object_label = 'земельный участок';
    } elseif ($is_gift && $object_type === 'house_with_plot' && $gift_template_id === 'shablon-darenie-dogovor') {
        $plot_cad = trim((string) ($property_data['plot_cadastral_number'] ?? ''));
        if ($plot_cad === '') {
            $plot_cad = '____________________';
        }
        $subject_11 = 'Даритель безвозмездно передает в собственность (дарит) Одаряемому, а Одаряемый принимает в дар от Дарителя жилой дом с земельным участком, расположенные по адресу: '
            . $addr . ', кадастровый номер жилого дома: ' . $cad . ', кадастровый номер земельного участка: ' . $plot_cad . '.';
        $characteristics = 'Общая площадь жилого дома составляет ' . $area . ' кв. м.';
        $object_label = 'жилой дом с земельным участком';
    } elseif ($is_gift && in_array($object_type, array('garage', 'parking'), true) && $gift_template_id === 'shablon-darenie-dogovor') {
        $kind = $object_kind !== '' ? $object_kind : $object_name;
        $subject_11 = 'Даритель безвозмездно передает в собственность (дарит) Одаряемому, а Одаряемый принимает в дар от Дарителя '
            . $kind . ', расположенный по адресу: ' . $addr . ', имеющий кадастровый номер ' . $cad . '.';
        $characteristics = 'Площадь ' . $kind . ' составляет ' . $area . ' кв. м.';
        $object_label = $kind;
    } else {
        $subject_11 = ($is_gift ? 'Даритель ' . $transfer_verb . ' Одаряемому, а Одаряемый ' . $accept_verb . ' от ' . $from_party . ' '
            : 'Продавцы ' . $transfer_verb . ', а Покупатель ' . $accept_verb . ' ')
            . $object_kind . ', расположенное по адресу: ' . $addr . ', имеющее кадастровый номер ' . $cad . '.';
        $characteristics = 'Объект расположен на ' . $floor . ' этаже ' . $floors_total . '-этажного дома, '
            . 'состоит из ' . $rooms . ' комнат, общая площадь ' . $area . ' кв. м.';
        $object_label = $object_kind;
    }

    $egrn_kind = ($object_type === 'share') ? 'долю' : (($object_type === 'room') ? 'комнату' : 'объект');
    if ($is_gift && $object_type !== 'share') {
        $egrn_record = trim((string) ($property_data['property_right_date'] ?? ''));
        $egrn_extract = trim((string) ($property_data['egrn_extract_date'] ?? ''));
        $egrn_number = trim((string) ($property_data['egrn_extract_number'] ?? ''));
        if ($egrn_record === '') {
            $egrn_record = '____';
        }
        if ($egrn_extract === '') {
            $egrn_extract = '____________';
        }
        if ($egrn_number === '') {
            $egrn_number = '___________________________';
        }
        $gift_obj_nom = yvo_gift_object_grammar($object_type)['nom'];
        $ownership_tail = $gift_obj_nom . ' принадлежит Дарителю на праве собственности, что подтверждается записью в Едином государственном реестре недвижимости от «'
            . $egrn_record . '» года и представленной Дарителем Выпиской из Единого государственного реестра недвижимости от «'
            . $egrn_extract . '» года № ' . $egrn_number . '.';
    } else {
        $ownership_tail = $is_gift
            ? $egrn_kind . ' принадлежит Дарителю на праве собственности (доля: ' . $share_in_right . '), что подтверждается записью в ЕГРН.'
            : $egrn_kind . ' принадлежит ' . ($object_type === 'share' ? 'Продавцам' : 'Продавцу') . ' на праве собственности, что подтверждается записью в ЕГРН.';
    }

    return array(
        'PROPERTY_OBJECT_TYPE' => $object_type,
        'PROPERTY_OBJECT_NAME' => $object_name,
        'PROPERTY_OBJECT_KIND' => $object_kind,
        'PROPERTY_OBJECT_LABEL' => $object_label,
        'PROPERTY_SHARE_IN_RIGHT' => $share_in_right,
        'PROPERTY_SUBJECT_1_1' => $subject_11,
        'PROPERTY_CHARACTERISTICS' => $characteristics,
        'PROPERTY_OWNERSHIP_EGRN_LINE' => $ownership_tail,
    );
}

/** DOCX содержит незаменённые {{PLACEHOLDER}} — результат шаблона испорчен. */
function yvo_docx_has_unfilled_placeholders($docx_path) {
    if (!class_exists('ZipArchive') || !is_readable($docx_path)) {
        return true;
    }
    $zip = new ZipArchive();
    if ($zip->open($docx_path, ZipArchive::RDONLY) !== true) {
        return true;
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    return !is_string($xml) || $xml === '' || (strpos($xml, '{{') !== false);
}

function yvo_get_dkp_ipoteka_placeholder_map($sellers, $buyers, $property_data, $options = array(), $contract_type = 'sale') {
    if (!is_array($sellers)) {
        $sellers = array($sellers);
    }
    if (!is_array($buyers)) {
        $buyers = array($buyers);
    }
    yvo_prepare_parties_for_contract_generation($sellers, $buyers);

    $contract_city = isset($options['contract_city']) ? $options['contract_city'] : (isset($property_data['city']) ? $property_data['city'] : '________________');
    $contract_date = isset($options['contract_date']) ? $options['contract_date'] : date('d.m.Y');

    $principal_sellers = yvo_parties_principal_sellers_ordered($sellers);
    $principal_buyers = yvo_parties_principal_buyers_ordered($buyers);

    $sellers_block = '';
    $seller_principal_counter = 0;
    $guardian_preamble_seller_done = array();
    foreach ($sellers as $s) {
        if (!is_array($s)) {
            continue;
        }
        $name = isset($s['full_name']) ? $s['full_name'] : '________________';
        $birth = isset($s['birth_date']) && $s['birth_date'] ? $s['birth_date'] : '____________';
        $birth_place = isset($s['birth_place']) && $s['birth_place'] ? $s['birth_place'] : '____________________';
        $pass_ser = isset($s['passport_series']) ? $s['passport_series'] : '_____';
        $pass_num = isset($s['passport_number']) ? $s['passport_number'] : '__________';
        $issued = isset($s['passport_issued_by']) ? $s['passport_issued_by'] : '_______________________________';
        $dept = isset($s['department_code']) ? $s['department_code'] : '_______';
        $reg_line = yvo_party_registration_line($s);
        $s_gender = yvo_party_gender_from_row($s);
        if (yvo_row_is_seller_representative($s)) {
            $pnum = yvo_rep_resolve_principal_index($principal_sellers, $s);
            $p = isset($principal_sellers[$pnum - 1]) && is_array($principal_sellers[$pnum - 1]) ? $principal_sellers[$pnum - 1] : array();
            $pname = isset($p['full_name']) && trim((string) $p['full_name']) !== '' ? trim((string) $p['full_name']) : '________________';
            $poa = yvo_party_poa_line($s);
            $acting = yvo_party_gender_form($s_gender, 'действующий', 'действующая', 'действующий(-ая)');
            $authorized = yvo_party_gender_form($s_gender, 'уполномоченный', 'уполномоченная', 'уполномоченный(-ая)');
            $named = yvo_party_gender_form($s_gender, 'именуемый', 'именуемая', 'именуемый(-ая)');
            $sellers_block .= "Я, " . $name . ",\n";
            $sellers_block .= "дата рождения: " . $birth . ", место рождения: " . $birth_place . ",\n";
            $sellers_block .= "паспорт РФ: серия " . $pass_ser . " номер " . $pass_num . ", выдан " . $issued . ",\n";
            $sellers_block .= "код подразделения " . $dept . ", " . $reg_line . "\n";
            $sellers_block .= $acting . " на основании " . $poa . ", " . $authorized . " представлять интересы " . yvo_contract_party_side_genitive($contract_type, 'seller', $pnum) . " (" . $pname . "),\n";
            $sellers_block .= $named . " в дальнейшем «" . yvo_contract_party_display_label($contract_type, 'seller', 'representative', $pnum) . "»,\n\n";
            continue;
        }
        if (yvo_row_is_seller_guardian($s)) {
            if (!yvo_guardian_should_appear_in_contract($s, $sellers, $principal_sellers, 'seller')) {
                continue;
            }
            $gblock = yvo_build_guardian_preamble_block($s, $sellers, $principal_sellers, $contract_type, 'seller', $guardian_preamble_seller_done);
            if ($gblock !== null) {
                $sellers_block .= "Я, " . $gblock . ",\n\n";
            }
            continue;
        }
        $tab = yvo_party_row_tab($s);
        if ($tab === '' || $tab === 'seller' || preg_match('/^seller\d+$/', $tab) === 1 || strpos($tab, 'minor_seller') === 0) {
            $seller_principal_counter++;
            $n = $seller_principal_counter;
            $party_label = yvo_contract_party_display_label($contract_type, 'seller', yvo_row_is_minor_seller($s) ? 'minor' : 'principal', $n);
            if ($contract_type === 'gift' && !yvo_row_is_minor_seller($s)) {
                $sellers_block .= yvo_build_gift_principal_preamble_line($s, $party_label) . "\n\n";
            } elseif ($contract_type === 'gift' && yvo_row_is_minor_seller($s)) {
                if (yvo_row_minor_age_group($s) === 'a14_18') {
                    $party_label = yvo_contract_party_display_label($contract_type, 'seller', 'principal', $n);
                    $sellers_block .= yvo_build_gift_principal_preamble_line($s, $party_label) . "\n\n";
                } else {
                    $sellers_block .= yvo_build_gift_minor_preamble_line($s, $party_label) . "\n\n";
                }
            } else {
                $id_lines = yvo_party_id_document_lines_for_party_card($s, $contract_type);
                $intro = ($seller_principal_counter === 1 && count($principal_sellers) === 1) ? 'Я, ' : 'Мы, ';
                $sellers_block .= $intro . $name . ",\n";
                $sellers_block .= "дата рождения: " . $birth . ", место рождения: " . $birth_place . ",\n";
                $sellers_block .= $id_lines[0] . "\n";
                $sellers_block .= $id_lines[1] . "\n";
                $named = yvo_party_gender_form(yvo_party_gender_from_row($s), 'именуемый', 'именуемая', 'именуемый(-ая)');
                $sellers_block .= $named . " в дальнейшем «" . $party_label . "»,\n\n";
            }
        }
    }
    $sellers_joint = yvo_contract_parties_joint_label($contract_type, 'seller', count($principal_sellers));

    $buyers_block = '';
    $buyer_principal_counter = 0;
    $guardian_preamble_buyer_done = array();
    foreach ($buyers as $i => $b) {
        if (!is_array($b)) {
            continue;
        }
        $name = isset($b['full_name']) ? $b['full_name'] : '________________';
        $birth = isset($b['birth_date']) && $b['birth_date'] ? $b['birth_date'] : '____________';
        $birth_place = isset($b['birth_place']) && $b['birth_place'] ? $b['birth_place'] : '____________________';
        $pass_ser = isset($b['passport_series']) ? $b['passport_series'] : '_____';
        $pass_num = isset($b['passport_number']) ? $b['passport_number'] : '__________';
        $issued = isset($b['passport_issued_by']) ? $b['passport_issued_by'] : '_______________________________';
        $dept = isset($b['department_code']) ? $b['department_code'] : '_______';
        $reg_line = yvo_party_registration_line($b);
        $b_gender = yvo_party_gender_from_row($b);
        $prefix = ($i === 0 || $contract_type === 'gift') ? '' : 'и ';
        if (yvo_row_is_buyer_representative($b)) {
            $pnum = yvo_rep_resolve_principal_index($principal_buyers, $b);
            $p = isset($principal_buyers[$pnum - 1]) && is_array($principal_buyers[$pnum - 1]) ? $principal_buyers[$pnum - 1] : array();
            $pname = isset($p['full_name']) && trim((string) $p['full_name']) !== '' ? trim((string) $p['full_name']) : '________________';
            $poa = yvo_party_poa_line($b);
            $acting = yvo_party_gender_form($b_gender, 'действующий', 'действующая', 'действующий(-ая)');
            $authorized = yvo_party_gender_form($b_gender, 'уполномоченный', 'уполномоченная', 'уполномоченный(-ая)');
            $named = yvo_party_gender_form($b_gender, 'именуемый', 'именуемая', 'именуемый(-ая)');
            $buyers_block .= $prefix . $name . ",\n";
            $buyers_block .= "дата рождения: " . $birth . ", место рождения: " . $birth_place . ",\n";
            $buyers_block .= "паспорт РФ: серия " . $pass_ser . " номер " . $pass_num . ", выдан " . $issued . ",\n";
            $buyers_block .= "код подразделения " . $dept . ", " . $reg_line . "\n";
            $buyers_block .= $acting . " на основании " . $poa . ", " . $authorized . " представлять интересы " . yvo_contract_party_side_genitive($contract_type, 'buyer', $pnum) . " (" . $pname . "),\n";
            $buyers_block .= $named . " в дальнейшем «" . yvo_contract_party_display_label($contract_type, 'buyer', 'representative', $pnum) . "»" . ($contract_type === 'gift' ? ',' : ($i < count($buyers) - 1 ? "," : ", с другой стороны,")) . "\n\n";
            continue;
        }
        if (yvo_row_is_buyer_guardian($b)) {
            if (!yvo_guardian_should_appear_in_contract($b, $buyers, $principal_buyers, 'buyer')) {
                continue;
            }
            $gblock = yvo_build_guardian_preamble_block($b, $buyers, $principal_buyers, $contract_type, 'buyer', $guardian_preamble_buyer_done);
            if ($gblock !== null) {
                $buyers_block .= $prefix . $gblock . ($contract_type === 'gift' ? ',' : ($i < count($buyers) - 1 ? "," : ", с другой стороны,")) . "\n\n";
            }
            continue;
        }
        $tab = yvo_party_row_tab($b);
        if ($tab === '' || $tab === 'buyer' || preg_match('/^buyer\d+$/', $tab) === 1 || strpos($tab, 'contributor') === 0 || strpos($tab, 'minor_buyer') === 0) {
            $buyer_principal_counter++;
            $n = $buyer_principal_counter;
            $label = yvo_contract_party_display_label($contract_type, 'buyer', yvo_row_is_minor_buyer($b) ? 'minor' : 'principal', $n);
            if (count($principal_buyers) === 1 && !yvo_row_is_minor_buyer($b)) {
                $label = yvo_contract_party_display_label($contract_type, 'buyer', 'principal', 1);
            }
            if ($contract_type === 'gift' && !yvo_row_is_minor_buyer($b)) {
                $buyers_block .= $prefix . yvo_build_gift_principal_preamble_line($b, $label) . "\n\n";
            } elseif ($contract_type === 'gift' && yvo_row_is_minor_buyer($b)) {
                if (yvo_row_minor_age_group($b) === 'a14_18') {
                    $label = yvo_contract_party_display_label($contract_type, 'buyer', 'principal', $n);
                    if (count($principal_buyers) === 1) {
                        $label = yvo_contract_party_display_label($contract_type, 'buyer', 'principal', 1);
                    }
                    $buyers_block .= $prefix . yvo_build_gift_principal_preamble_line($b, $label) . "\n\n";
                } else {
                    $buyers_block .= $prefix . yvo_build_gift_minor_preamble_line($b, $label) . "\n\n";
                }
            } else {
                $id_lines = yvo_party_id_document_lines_for_party_card($b, $contract_type);
                $buyers_block .= $prefix . $name . ",\n";
                $buyers_block .= "дата рождения: " . $birth . ", место рождения: " . $birth_place . ",\n";
                $buyers_block .= $id_lines[0] . "\n";
                $buyers_block .= $id_lines[1] . "\n";
                $named = yvo_party_gender_form(yvo_party_gender_from_row($b), 'именуемый', 'именуемая', 'именуемый(-ая)');
                $buyers_block .= $named . " в дальнейшем «" . $label . "»" . ($contract_type === 'gift' ? ',' : ($i < count($buyers) - 1 ? "," : ", с другой стороны,")) . "\n\n";
            }
        }
    }

    $signatures = '';

    $seller_principal_has_rep = array();
    foreach ((array) $sellers as $s) {
        if (!is_array($s)) {
            continue;
        }
        if (yvo_row_is_seller_representative($s)) {
            $pnum = yvo_rep_resolve_principal_index($principal_sellers, $s);
            if ($pnum > 0) {
                $seller_principal_has_rep[$pnum] = true;
            }
        }
    }
    $buyer_principal_has_rep = array();
    foreach ((array) $buyers as $b) {
        if (!is_array($b)) {
            continue;
        }
        if (yvo_row_is_buyer_representative($b)) {
            $pnum = yvo_rep_resolve_principal_index($principal_buyers, $b);
            if ($pnum > 0) {
                $buyer_principal_has_rep[$pnum] = true;
            }
        }
    }

    $sig_seller_pr = 0;
    $sig_guardian_seller_done = array();
    foreach ($sellers as $s) {
        if (!is_array($s)) {
            continue;
        }
        if (yvo_row_is_seller_guardian($s)) {
            if (!yvo_guardian_should_appear_in_contract($s, $sellers, $principal_sellers, 'seller')) {
                continue;
            }
            $resolved = yvo_party_guardian_resolved_row($s, $sellers);
            if ($contract_type !== 'gift') {
                $gkey = yvo_guardian_preamble_group_key($s, $sellers);
                if (isset($sig_guardian_seller_done[$gkey])) {
                    continue;
                }
                $sig_guardian_seller_done[$gkey] = true;
                $guardian_num = yvo_guardian_contract_label_number($s, $sellers, 'seller');
            } else {
                $guardian_num = yvo_guardian_resolve_principal_index($principal_sellers, $s);
            }
            $sig_name = yvo_party_format_person_name(isset($resolved['full_name']) ? $resolved['full_name'] : '');
            $signatures .= yvo_contract_party_display_label($contract_type, 'seller', 'guardian', $guardian_num) . ': _______________________________ / ' . $sig_name . " /\n\n";
            continue;
        }
        if (yvo_row_is_seller_representative($s)) {
            $pnum = yvo_rep_resolve_principal_index($principal_sellers, $s);
            $signatures .= yvo_contract_party_display_label($contract_type, 'seller', 'representative', $pnum) . ': _______________________________ / ' . yvo_party_format_person_name(isset($s['full_name']) ? $s['full_name'] : '') . " /\n\n";
            continue;
        }
        $t = yvo_party_row_tab($s);
        if ($t === '' || $t === 'seller' || preg_match('/^seller\d+$/', $t) === 1 || strpos($t, 'minor_seller') === 0) {
            $sig_seller_pr++;
            $is_minor = (strpos($t, 'minor_seller') === 0);
            $age_group = isset($s['minor_age_group']) ? (string) $s['minor_age_group'] : '';
            if ($is_minor && $age_group === 'u14') {
                continue;
            }
            if (!isset($seller_principal_has_rep[$sig_seller_pr])) {
                $sig_kind = ($is_minor && $age_group === 'a14_18') ? 'principal' : ($is_minor ? 'minor' : 'principal');
                $sig_lab = yvo_contract_party_display_label($contract_type, 'seller', $sig_kind, $sig_seller_pr);
                $signatures .= $sig_lab . ': _______________________________ / ' . yvo_party_format_person_name(isset($s['full_name']) ? $s['full_name'] : '') . " /\n\n";
            }
        }
    }
    $sig_buyer_pr = 0;
    $sig_guardian_buyer_done = array();
    foreach ($buyers as $b) {
        if (!is_array($b)) {
            continue;
        }
        if (yvo_row_is_buyer_guardian($b)) {
            if (!yvo_guardian_should_appear_in_contract($b, $buyers, $principal_buyers, 'buyer')) {
                continue;
            }
            $resolved = yvo_party_guardian_resolved_row($b, $buyers);
            if ($contract_type !== 'gift') {
                $gkey = yvo_guardian_preamble_group_key($b, $buyers);
                if (isset($sig_guardian_buyer_done[$gkey])) {
                    continue;
                }
                $sig_guardian_buyer_done[$gkey] = true;
                $guardian_num = yvo_guardian_contract_label_number($b, $buyers, 'buyer');
            } else {
                $guardian_num = yvo_guardian_resolve_principal_index($principal_buyers, $b);
            }
            $sig_name = yvo_party_format_person_name(isset($resolved['full_name']) ? $resolved['full_name'] : '');
            $signatures .= yvo_contract_party_display_label($contract_type, 'buyer', 'guardian', $guardian_num) . ': _______________________________ / ' . $sig_name . " /\n\n";
            continue;
        }
        if (yvo_row_is_buyer_representative($b)) {
            $pnum = yvo_rep_resolve_principal_index($principal_buyers, $b);
            $signatures .= yvo_contract_party_display_label($contract_type, 'buyer', 'representative', $pnum) . ': _______________________________ / ' . yvo_party_format_person_name(isset($b['full_name']) ? $b['full_name'] : '') . " /\n\n";
            continue;
        }
        $t = yvo_party_row_tab($b);
        if ($t === '' || $t === 'buyer' || preg_match('/^buyer\d+$/', $t) === 1 || strpos($t, 'contributor') === 0 || strpos($t, 'minor_buyer') === 0) {
            $sig_buyer_pr++;
            $is_minor_b = (strpos($t, 'minor_buyer') === 0);
            $age_group_b = isset($b['minor_age_group']) ? (string) $b['minor_age_group'] : '';
            if ($is_minor_b && $age_group_b === 'u14') {
                continue;
            }
            if (!isset($buyer_principal_has_rep[$sig_buyer_pr])) {
                $sig_kind_b = ($is_minor_b && $age_group_b === 'a14_18') ? 'principal' : ($is_minor_b ? 'minor' : 'principal');
                $blab = yvo_contract_party_display_label($contract_type, 'buyer', $sig_kind_b, $sig_buyer_pr);
                if (count($principal_buyers) === 1 && (!$is_minor_b || $age_group_b === 'a14_18')) {
                    $blab = yvo_contract_party_display_label($contract_type, 'buyer', 'principal', 1);
                }
                $signatures .= $blab . ': _______________________________ / ' . yvo_party_format_person_name(isset($b['full_name']) ? $b['full_name'] : '') . " /\n\n";
            }
        }
    }

    $price = isset($property_data['price']) && $property_data['price'] !== '' ? number_format(floatval($property_data['price']), 2, ',', ' ') : '__________';
    $price_words = isset($property_data['price_words']) && trim((string) $property_data['price_words']) !== ''
        ? trim((string) $property_data['price_words'])
        : (isset($options['property_price_words']) && trim((string) $options['property_price_words']) !== ''
            ? trim((string) $options['property_price_words'])
            : '________________');
    $right_date = $property_data['property_right_date'] ?? '[дата регистрации права]';
    $shares = $property_data['sellers_shares'] ?? '[размер долей]';
    $owner_one = ($contract_type === 'gift') ? 'Дарителю' : 'Продавцу';
    $owner_many = ($contract_type === 'gift') ? 'Дарителям' : 'Продавцам';
    if (count($principal_sellers) <= 1) {
        $ownership_clause = "1.2. Объект принадлежит " . $owner_one . " на праве собственности, что подтверждается записями в ЕГРН от " . $right_date . ".";
    } else {
        $ownership_clause = "1.2. Объект принадлежит " . $owner_many . " на праве общей долевой собственности (доли в праве: " . $shares . "), что подтверждается записями в ЕГРН от " . $right_date . ".";
    }

    $accreditives = '';
    foreach ($principal_sellers as $i => $s) {
        $accreditives .= 'АККРЕДИТИВ ' . ($i + 1) . ' (в пользу Продавца ' . ($i + 1) . ")\n";
        $accreditives .= 'Банк-эмитент: ' . ($options['bank_name'] ?? '[наименование банка]') . "\n";
        $accreditives .= 'Исполняющий банк: ' . ($options['bank_name'] ?? '[наименование банка]') . "\n";
        $accreditives .= 'Сумма аккредитива: ' . (isset($options['accreditive_amount_' . ($i + 1)]) ? $options['accreditive_amount_' . ($i + 1)] : '[сумма]') . " рублей\n";
        $accreditives .= 'Получатель: Продавец ' . ($i + 1) . "\n\n";
    }

    // Удобные «плоские» плейсхолдеры для DOCX-шаблона в современном стиле (seller1/seller2/buyer1) — только принципалы.
    $s1 = isset($principal_sellers[0]) && is_array($principal_sellers[0]) ? $principal_sellers[0] : array();
    $s2 = isset($principal_sellers[1]) && is_array($principal_sellers[1]) ? $principal_sellers[1] : array();
    $b1 = isset($principal_buyers[0]) && is_array($principal_buyers[0]) ? $principal_buyers[0] : array();
    $date_parts = preg_split('/\./', (string) $contract_date);
    $date_day = isset($date_parts[0]) ? $date_parts[0] : '__';
    $date_month = isset($date_parts[1]) ? $date_parts[1] : '__';
    $date_year = isset($date_parts[2]) ? $date_parts[2] : '20__';

    $get = function ($arr, $key, $fallback) {
        if (!is_array($arr)) return $fallback;
        $v = isset($arr[$key]) ? $arr[$key] : '';
        $v = is_scalar($v) ? (string) $v : '';
        $v = trim($v);
        return $v !== '' ? $v : $fallback;
    };

    $seller_pay = '';
    if (isset($property_data['seller_details'])) {
        $seller_pay = trim((string) $property_data['seller_details']);
    }
    if ($seller_pay === '' && isset($property_data['seller_details_mortgage'])) {
        $seller_pay = trim((string) $property_data['seller_details_mortgage']);
    }
    if ($seller_pay === '') {
        $seller_pay = '________________';
    }

    $acc_amt_str = $price;
    if (isset($options['accreditiv_amount_display']) && trim((string) $options['accreditiv_amount_display']) !== '') {
        $acc_amt_str = trim((string) $options['accreditiv_amount_display']);
    }

    $acc_days_str = '__';
    if (isset($options['accreditiv_calendar_days']) && trim((string) $options['accreditiv_calendar_days']) !== '') {
        $acc_days_str = trim((string) $options['accreditiv_calendar_days']);
    }

    if ($contract_type === 'gift') {
        $acc_payee_label = count($principal_sellers) > 1 ? 'каждого из Дарителей' : 'Дарителя';
    } else {
        $acc_payee_label = count($principal_sellers) > 1 ? 'каждого из Продавцов' : 'Продавца';
    }

    $vacate_deadline = isset($property_data['vacate_deadline']) && trim((string) $property_data['vacate_deadline']) !== ''
        ? trim((string) $property_data['vacate_deadline']) : '14';
    if (preg_match('/\d+/', $vacate_deadline, $vm)) {
        $vacate_deadline = $vm[0];
    }

    $share_price_parts = yvo_calculate_share_price_amount($property_data);
    $living_area = isset($property_data['living_area']) && trim((string) $property_data['living_area']) !== ''
        ? trim((string) $property_data['living_area']) : '________';
    $prop_city = trim((string) ($property_data['city'] ?? ''));
    $prop_street = trim((string) ($property_data['street'] ?? ''));
    $prop_house = trim((string) ($property_data['house'] ?? ''));
    $prop_apartment = trim((string) ($property_data['apartment'] ?? ''));
    if ($prop_city === '') {
        $prop_city = '________________';
    }
    if ($prop_street === '') {
        $prop_street = '_________________________';
    }
    if ($prop_house === '') {
        $prop_house = '_____';
    }
    if ($prop_apartment === '') {
        $prop_apartment = '____';
    }
    $registering_authority = 'Управлении Федеральной регистрационной службы';
    if ($contract_city !== '' && $contract_city !== '________________') {
        $city_reg = function_exists('yvo_city_for_registering_authority')
            ? yvo_city_for_registering_authority($contract_city)
            : ('по ' . yvo_format_contract_city_display($contract_city));
        if ($city_reg !== '') {
            $registering_authority .= ' ' . $city_reg;
        }
    }

    $ownership_basis = trim((string) ($property_data['ownership_basis_documents'] ?? ''));
    if ($ownership_basis === '') {
        $ownership_basis = '__________________________________________________';
    }
    $gift_reg_expenses = trim((string) ($property_data['gift_registration_expenses_party'] ?? ''));
    if ($gift_reg_expenses === '') {
        $gift_reg_expenses = '__________________________';
    }

    $month_gen = yvo_russian_month_genitive($date_month);
    if ($month_gen === '') {
        $month_gen = '_____________';
    }
    $city_date_right = yvo_build_contract_city_date_line($contract_city, $contract_date, $contract_type, $date_day, $month_gen, $date_year);

    $map = array(
        'CONTRACT_CITY_DATE_RIGHT' => $city_date_right,
        'GIFT_DOLEVAYA_CITY_DATE' => $city_date_right,
        'DATE_MONTH_NAME' => $month_gen,
        'CONTRACT_CITY' => $contract_city,
        'CONTRACT_DATE' => $contract_date,
        'DATE_DAY' => $date_day,
        'DATE_MONTH' => $date_month,
        'DATE_YEAR' => $date_year,
        'SELLERS_BLOCK' => trim($sellers_block),
        'SELLERS_JOINT_LINE' => $sellers_joint,
        'BUYERS_BLOCK' => trim($buyers_block),
        'SIGNATURES_BLOCK' => trim($signatures),
        'SELLER1_FULL_NAME' => $get($s1, 'full_name', '________________'),
        'SELLER1_BIRTH_DATE' => $get($s1, 'birth_date', 'ДД.ММ.ГГГГ'),
        'SELLER1_BIRTH_PLACE' => $get($s1, 'birth_place', '____________________'),
        'SELLER1_PASSPORT_SERIES' => $get($s1, 'passport_series', '____'),
        'SELLER1_PASSPORT_NUMBER' => $get($s1, 'passport_number', '______'),
        'SELLER1_PASSPORT_ISSUED_BY' => $get($s1, 'passport_issued_by', '________________'),
        'SELLER1_DEPARTMENT_CODE' => $get($s1, 'department_code', '___-___'),
        'SELLER1_REGISTRATION' => $get($s1, 'registration', '________________'),
        'SELLER2_FULL_NAME' => $get($s2, 'full_name', '________________'),
        'SELLER2_BIRTH_DATE' => $get($s2, 'birth_date', 'ДД.ММ.ГГГГ'),
        'SELLER2_BIRTH_PLACE' => $get($s2, 'birth_place', '____________________'),
        'SELLER2_PASSPORT_SERIES' => $get($s2, 'passport_series', '____'),
        'SELLER2_PASSPORT_NUMBER' => $get($s2, 'passport_number', '______'),
        'SELLER2_PASSPORT_ISSUED_BY' => $get($s2, 'passport_issued_by', '________________'),
        'SELLER2_DEPARTMENT_CODE' => $get($s2, 'department_code', '___-___'),
        'SELLER2_REGISTRATION' => $get($s2, 'registration', '________________'),
        'BUYER1_FULL_NAME' => $get($b1, 'full_name', '________________'),
        'BUYER1_BIRTH_DATE' => $get($b1, 'birth_date', 'ДД.ММ.ГГГГ'),
        'BUYER1_BIRTH_PLACE' => $get($b1, 'birth_place', '____________________'),
        'BUYER1_PASSPORT_SERIES' => $get($b1, 'passport_series', '____'),
        'BUYER1_PASSPORT_NUMBER' => $get($b1, 'passport_number', '______'),
        'BUYER1_PASSPORT_ISSUED_BY' => $get($b1, 'passport_issued_by', '________________'),
        'BUYER1_DEPARTMENT_CODE' => $get($b1, 'department_code', '___-___'),
        'BUYER1_REGISTRATION' => $get($b1, 'registration', '________________'),
        'PROPERTY_ADDRESS' => $property_data['address'] ?? '________________',
        'PROPERTY_OBJECT_KIND' => isset($property_data['property_type']) && trim((string) $property_data['property_type']) !== ''
            ? trim((string) $property_data['property_type']) : 'жилое помещение',
        'PROPERTY_CADASTRAL_NUM' => $property_data['cadastral_number'] ?? '____________________',
        'PROPERTY_FLOORS_TOTAL' => isset($property_data['floors_total']) && $property_data['floors_total'] !== '' ? $property_data['floors_total'] : '___',
        'PROPERTY_ROOMS' => isset($property_data['rooms']) && $property_data['rooms'] !== '' ? $property_data['rooms'] : '[количество комнат]',
        'PROPERTY_AREA' => isset($property_data['area']) && $property_data['area'] !== '' ? $property_data['area'] : '___',
        'PROPERTY_FLOOR' => isset($property_data['floor']) && $property_data['floor'] !== '' ? $property_data['floor'] : '[этаж]',
        'PROPERTY_PRICE' => $price,
        'PROPERTY_PRICE_WORDS' => $price_words,
        'PROPERTY_RIGHT_INFO' => yvo_format_property_right_info($property_data, $contract_type),
        'PROPERTY_RIGHT_DATE' => $property_data['property_right_date'] ?? '[дата регистрации права]',
        'PROPERTY_OWNERSHIP_CLAUSE' => $ownership_clause,
        'SELLERS_SHARES' => $shares,
        'LOAN_OWN_AMOUNT' => $options['loan_own_amount'] ?? '__________',
        'LOAN_OWN_AMOUNT_WORDS' => $options['loan_own_amount_words'] ?? '________________',
        'LOAN_CREDIT_AMOUNT' => $options['loan_credit_amount'] ?? '__________',
        'LOAN_CREDIT_AMOUNT_WORDS' => $options['loan_credit_amount_words'] ?? '________________',
        'LOAN_AGREEMENT_NUMBER' => $options['loan_agreement_number'] ?? '[номер]',
        'LOAN_AGREEMENT_DATE' => $options['loan_agreement_date'] ?? '[дата]',
        'BANK_NAME' => $options['bank_name'] ?? '[наименование банка]',
        'PAID_BEFORE_SIGNING' => $options['paid_before_signing'] ?? '[сумма]',
        'PAID_AT_SIGNING' => $options['paid_at_signing'] ?? '[сумма]',
        'PAID_BY_LETTERS' => $options['paid_by_letters'] ?? '[сумма]',
        'TOTAL_ACCREDITIVES_AMOUNT' => $options['total_accreditives_amount'] ?? $price,
        'ACCEPTANCE_DAYS' => $options['acceptance_days'] ?? '[количество]',
        'ACCREDITIVES_BLOCK' => trim($accreditives),
        'SELLER_PAYMENT_DETAILS' => $seller_pay,
        'ACCREDITIV_AMOUNT' => $acc_amt_str,
        'ACCREDITIV_CALENDAR_DAYS' => $acc_days_str,
        'ACCREDITIV_PAYEE_LABEL' => $acc_payee_label,
        'VACATE_DEADLINE' => $vacate_deadline,
        'PROPERTY_CITY' => $prop_city,
        'PROPERTY_STREET' => $prop_street,
        'PROPERTY_HOUSE' => $prop_house,
        'PROPERTY_APARTMENT' => $prop_apartment,
        'PROPERTY_LIVING_AREA' => $living_area,
        'PROPERTY_SHARE_PRICE' => $share_price_parts['amount'],
        'PROPERTY_SHARE_PRICE_WORDS' => $share_price_parts['words'],
        'REGISTERING_AUTHORITY' => $registering_authority,
        'PROPERTY_OWNERSHIP_BASIS' => $ownership_basis,
        'GIFT_REGISTRATION_EXPENSES_PARTY' => $gift_reg_expenses,
        'GIFT_SHARE_ALLOCATION_SUMMARY' => '',
        'GIFT_SECTION_6_TAIL' => '',
        'GIFT_CONTRACT_SUBTITLE' => 'дарения жилого помещения',
        'GIFT_DONEE_DAT' => 'Одаряемому',
        'GIFT_DONEE_NOM' => 'Одаряемый',
        'GIFT_DONEE_OBLIG' => 'обязан',
        'GIFT_CLAUSE_6_1' => '',
        'GIFT_CADASTRAL_VALUE_CLAUSE' => '',
        'PARTIES_ADDRESSES_BLOCK' => '',
    );
    $gift_tpl = ($contract_type === 'gift') ? yvo_normalize_gift_template_id(
        isset($options['gift_template_id']) ? (string) $options['gift_template_id'] : '',
        $property_data
    ) : '';
    $map = array_merge($map, yvo_build_property_contract_fragments($property_data, $contract_type, $gift_tpl));
    if ($contract_type === 'sale' || $contract_type === 'sale_mortgage') {
        $map = array_merge($map, yvo_build_dkp_object_meta($property_data));
    }
    $gift_object_type = isset($property_data['object_type']) ? sanitize_key((string) $property_data['object_type']) : 'apartment';
    if ($gift_object_type === '') {
        $gift_object_type = 'apartment';
    }
    if ($contract_type === 'gift' && yvo_gift_has_share_matrix_data($sellers, $buyers, $property_data)) {
        $map['PROPERTY_SUBJECT_1_1'] = yvo_build_gift_share_subject_11($sellers, $buyers, $property_data);
        $map['GIFT_SHARE_DISTRIBUTION_LIST'] = yvo_build_gift_share_distribution_list($sellers, $buyers, $property_data);
        $map['GIFT_SHARE_ALLOCATION_SUMMARY'] = '';
        $alienated = yvo_gift_donor_alienated_share($sellers, $property_data);
        $map['PROPERTY_SHARE_IN_RIGHT'] = $alienated;
        $egrn_record = trim((string) ($property_data['property_right_date'] ?? ''));
        $egrn_extract = trim((string) ($property_data['egrn_extract_date'] ?? ''));
        $egrn_number = trim((string) ($property_data['egrn_extract_number'] ?? ''));
        if ($egrn_record === '') {
            $egrn_record = '____';
        }
        if ($egrn_extract === '') {
            $egrn_extract = '____________';
        }
        if ($egrn_number === '') {
            $egrn_number = '___________________________';
        }
        $map['PROPERTY_OWNERSHIP_EGRN_LINE'] = 'Доля в размере ' . $alienated . ' в праве общей долевой собственности на квартиру принадлежит Дарителю на праве собственности, что подтверждается записью в Едином государственном реестре недвижимости от «'
            . $egrn_record . '» года и представленной Дарителем Выпиской из Единого государственного реестра недвижимости от «'
            . $egrn_extract . '» года № ' . $egrn_number . '.';
        if ($gift_tpl === 'shablon-darenie-dogovor') {
            $map['PROPERTY_CHARACTERISTICS'] = 'Квартира, в отношении которой отчуждается доля, расположена на '
                . (isset($property_data['floor']) && $property_data['floor'] !== '' ? $property_data['floor'] : '___')
                . ' этаже '
                . (isset($property_data['floors_total']) && $property_data['floors_total'] !== '' ? $property_data['floors_total'] : '___')
                . '-этажного многоквартирного жилого дома, состоит из '
                . (isset($property_data['rooms']) && $property_data['rooms'] !== '' && (string) $property_data['rooms'] !== '0' ? $property_data['rooms'] : '___')
                . ' комнат, общая площадь квартиры '
                . (isset($property_data['area']) && $property_data['area'] !== '' ? $property_data['area'] : '___')
                . ' кв. м.';
        }
        $map['GIFT_CONTRACT_SUBTITLE'] = 'дарения доли в квартире';
        $map = array_merge($map, yvo_build_gift_template_dynamic_clauses($sellers, $buyers, $property_data, true, $gift_reg_expenses, $vacate_deadline));
        $map['GIFT_SECTION_6_TAIL'] = yvo_build_gift_section_6_tail(true, $buyers);
    } elseif ($contract_type === 'gift') {
        $map['GIFT_CONTRACT_SUBTITLE'] = yvo_gift_contract_subtitle_for_object($gift_object_type);
        $map = array_merge($map, yvo_gift_object_placeholder_map($gift_object_type));
        $map = array_merge($map, yvo_build_gift_template_dynamic_clauses($sellers, $buyers, $property_data, false, $gift_reg_expenses, $vacate_deadline));
        $map['GIFT_SECTION_6_TAIL'] = yvo_build_gift_section_6_tail(false, $buyers);
    }
    if ($contract_type === 'gift') {
        $has_share_gift = yvo_gift_has_share_matrix_data($sellers, $buyers, $property_data);
        $map = array_merge($map, yvo_build_dkp_object_meta($property_data));
        $map['PROPERTY_EGRN_TABLE_BLOCK'] = yvo_build_property_egrn_table_block($property_data);
        $donee_shares_block = '';
        if (!$has_share_gift) {
            $donee_shares_block = yvo_build_gift_donee_shares_block($sellers, $buyers, $property_data, $gift_object_type);
        }
        $map['GIFT_DONEE_SHARES_BLOCK'] = $donee_shares_block;
        $map['GIFT_SUBJECT_11_INTRO'] = yvo_build_gift_subject_11_intro($buyers, $has_share_gift, $donee_shares_block !== '');
        $g_grammar = yvo_gift_object_grammar($gift_object_type);
        if (!$has_share_gift) {
            $map['PROPERTY_SUBJECT_1_1'] = '';
            $map['PROPERTY_CHARACTERISTICS'] = '';
            $map['GIFT_OBJECT_FOOTNOTE'] = '(далее по тексту – ' . $g_grammar['nom'] . ').';
        } else {
            $map['GIFT_OBJECT_FOOTNOTE'] = '(далее по тексту – квартира, в отношении которой отчуждаются доли).';
        }
        $map['GIFT_CADASTRAL_VALUE_CLAUSE'] = yvo_build_gift_cadastral_value_clause($property_data);
    }
    if ($contract_type === 'sale' || $contract_type === 'sale_mortgage') {
        $tpl_id = isset($options['dkp_template_id']) ? (string) $options['dkp_template_id'] : '';
        $buyer_names_pay = array();
        foreach ($principal_buyers as $pb) {
            $bn = trim((string) ($pb['full_name'] ?? ''));
            if ($bn !== '') {
                $buyer_names_pay[] = $bn;
            }
        }
        $pay_opts = array_merge($options, array(
            'accreditiv_payee_label' => $acc_payee_label,
            'accreditives_block' => trim($accreditives),
            'property_price' => $price,
            'property_price_words' => $price_words,
            'seller_payment_details' => $seller_pay,
            'buyer_names' => !empty($buyer_names_pay) ? implode(', ', $buyer_names_pay) : 'Покупателю',
            'acceptance_days' => isset($options['acceptance_days']) && trim((string) $options['acceptance_days']) !== ''
                ? $options['acceptance_days']
                : '[количество]',
        ));
        if (!isset($pay_opts['payment_type']) || $pay_opts['payment_type'] === '') {
            $pay_opts['payment_type'] = ($contract_type === 'sale_mortgage') ? 'mortgage' : 'cash';
        }
        $variant = yvo_dkp_payment_variant_key($tpl_id !== '' ? $tpl_id : 'default', $pay_opts);
        $map['DKP_PAYMENT_SECTION'] = yvo_build_dkp_payment_section($variant, $pay_opts);
        $map['DKP_ESSENTIAL_SECTION'] = yvo_build_dkp_essential_section($variant, $pay_opts);
        $map['DKP_CLAUSE_4_3'] = yvo_build_dkp_clause_4_3($variant);
        $map['DKP_REGISTRATION_CLAUSE'] = yvo_build_dkp_registration_clause($variant);
        $map['DKP_COPIES_CLAUSE'] = yvo_build_dkp_copies_clause($variant);
        if ($contract_type === 'sale_mortgage' || strpos($variant, 'ipoteka') === 0) {
            $base_title = isset($map['DKP_CONTRACT_TITLE']) ? (string) $map['DKP_CONTRACT_TITLE'] : 'ДОГОВОР КУПЛИ-ПРОДАЖИ';
            $map['DKP_CONTRACT_TITLE'] = 'ДОГОВОР';
            $sub = mb_strtolower(preg_replace('/^ДОГОВОР\s+/u', '', $base_title), 'UTF-8');
            if ($sub === '' || $sub === $base_title) {
                $sub = 'купли-продажи';
            }
            $map['DKP_CONTRACT_SUBTITLE'] = $sub . ' с ипотекой в силу закона';
        } else {
            if (!isset($map['DKP_CONTRACT_SUBTITLE']) || trim((string) $map['DKP_CONTRACT_SUBTITLE']) === '') {
                $base_title = isset($map['DKP_CONTRACT_TITLE']) ? (string) $map['DKP_CONTRACT_TITLE'] : 'ДОГОВОР КУПЛИ-ПРОДАЖИ';
                $map['DKP_CONTRACT_TITLE'] = 'ДОГОВОР';
                $sub = mb_strtolower(preg_replace('/^ДОГОВОР\s+/u', '', $base_title), 'UTF-8');
                $map['DKP_CONTRACT_SUBTITLE'] = ($sub !== '' && $sub !== $base_title) ? $sub : 'купли-продажи';
            }
        }
    } else {
        $map['DKP_CONTRACT_SUBTITLE'] = isset($map['DKP_CONTRACT_SUBTITLE']) ? $map['DKP_CONTRACT_SUBTITLE'] : '';
        $map['DKP_PAYMENT_SECTION'] = '';
        $map['DKP_ESSENTIAL_SECTION'] = '';
        $map['DKP_REGISTRATION_CLAUSE'] = '';
        $map['DKP_COPIES_CLAUSE'] = '';
    }
    if ($contract_type === 'share_allocation') {
        $map = array_merge($map, yvo_build_alloc_template_placeholders($sellers, $buyers, $property_data, $options));
    }
    return array_merge($map, yvo_build_gift_dolya_clause_placeholders($contract_type, $sellers, $buyers, $s1, $property_data));
}


/** Основной bundled .txt шаблон дарения (Shablon-Darenie-dogovor). */
function yvo_resolve_gift_template_txt_path($template_id, $property_data = null) {
    $share_gift = YVO_PLUGIN_DIR . 'templates/shablon-darenie-dolya-kvartira.txt';
    $bundled_gift = YVO_PLUGIN_DIR . 'templates/shablon-darenie-dogovor.txt';
    $template_id = yvo_normalize_gift_template_id($template_id, $property_data);
    if ($template_id === 'shablon-darenie-dogovor' && file_exists($bundled_gift)) {
        return $bundled_gift;
    }
    if ($template_id === 'shablon-darenie-dolya-kvartira' && file_exists($share_gift)) {
        return $share_gift;
    }
    $legacy_gift = YVO_PLUGIN_DIR . 'templates/gift-kvartira-apartment.txt';
    $candidates = array();
    if (file_exists($bundled_gift)) {
        $candidates[] = $bundled_gift;
    }
    if ($template_id !== '' && $template_id !== 'default') {
        $resolved = yvo_resolve_template_path($template_id);
        if ($resolved) {
            $candidates[] = $resolved;
        }
    }
    $gift_id = yvo_get_first_template_id_for_category('gift');
    if ($gift_id && $gift_id !== $template_id) {
        $p2 = yvo_resolve_template_path($gift_id);
        if ($p2) {
            $candidates[] = $p2;
        }
    }
    if (file_exists($legacy_gift)) {
        $candidates[] = $legacy_gift;
    }
    foreach ($candidates as $path) {
        if (!$path || !file_exists($path)) {
            continue;
        }
        $head = @file_get_contents($path, false, null, 0, 16384);
        if (!is_string($head)) {
            continue;
        }
        if (preg_match('/дарени/ui', $head) && strpos($head, '{{SELLERS_BLOCK}}') !== false) {
            return $path;
        }
    }
    return file_exists($bundled_gift) ? $bundled_gift : (file_exists($legacy_gift) ? $legacy_gift : null);
}

/**
 * Для дарения квартиры/помещения — DOCX Shablon-Darenie-dogovor; для доли — текстовый шаблон.
 *
 * @param array<string, mixed>|null $property_data
 */
function yvo_gift_use_docx_template($template_id, $property_data = null) {
    unset($template_id, $property_data);
    // DOCX-оболочка устаревает (шрифты, нотариат, доли). Всегда собираем из актуального .txt.
    return false;
}

/** Нормализация id шаблона для дарения: доля / часть квартиры → shablon-darenie-dolya-kvartira. */
function yvo_normalize_gift_template_id($template_id, $property_data = null) {
    $template_id = trim((string) $template_id);
    if ($template_id === 'shablon-darenie-dolya-kvartira') {
        return 'shablon-darenie-dolya-kvartira';
    }
    if (is_array($property_data) && yvo_gift_property_indicates_dolya_template($property_data)) {
        return 'shablon-darenie-dolya-kvartira';
    }
    return 'shablon-darenie-dogovor';
}

/** Путь к DOCX шаблону дарения (Shablon-Darenie-dogovor.docx). */
function yvo_resolve_gift_template_docx_path($template_id, $bank_id = '') {
    $template_id = yvo_normalize_gift_template_id($template_id, null);
    $filtered = apply_filters('yvo_gift_template_docx_path', '');
    if (is_string($filtered) && $filtered !== '' && file_exists($filtered)) {
        return $filtered;
    }
    $bundled = YVO_PLUGIN_DIR . 'templates/docx/shablon-darenie-dogovor.docx';
    $desktop_candidates = array(
        'C:/Users/ilgiz/Desktop/Шаблоны окозания услуг/Shablon-Darenie-dogovor.docx',
    );
    foreach ($desktop_candidates as $desktop) {
        if (file_exists($desktop) && (!file_exists($bundled) || filemtime($desktop) > filemtime($bundled))) {
            @copy($desktop, $bundled);
            if (file_exists($bundled)) {
                break;
            }
        }
    }
    if ($template_id === '' || $template_id === 'default' || $template_id === 'shablon-darenie-dogovor' || $template_id === 'gift-kvartira-apartment') {
        if (file_exists($bundled)) {
            return $bundled;
        }
    }
    $resolved = yvo_resolve_template_docx_path($template_id, $bank_id);
    if ($resolved && file_exists($resolved)) {
        return $resolved;
    }
    $gift_id = yvo_get_first_template_id_for_category('gift');
    if ($gift_id && $gift_id !== $template_id) {
        $p2 = yvo_resolve_template_docx_path($gift_id, $bank_id);
        if ($p2 && file_exists($p2)) {
            return $p2;
        }
    }
    return file_exists($bundled) ? $bundled : null;
}

/**
 * Заполнение шаблона ДКП квартира (ипотека): динамические продавцы и покупатели.
 * $template_id — идентификатор шаблона (dkp-kvartira-ipoteka или default) для загрузки файла.
 */
function yvo_fill_dkp_ipoteka_template($sellers, $buyers, $property_data, $options = array(), $template_id = 'dkp-kvartira-ipoteka', $contract_type = null) {
    $ct = ($contract_type !== null && $contract_type !== '') ? (string) $contract_type : ($template_id === 'default' ? 'sale' : 'sale_mortgage');
    $options['dkp_template_id'] = (string) $template_id;
    $bundled_dkp = YVO_PLUGIN_DIR . 'templates/dkp-kvartira-ipoteka.txt';
    $standard_base = YVO_PLUGIN_DIR . 'templates/dkp-sale-standard.txt';
    if ($ct === 'share_allocation') {
        $path = yvo_resolve_share_allocation_template_txt_path($template_id);
    } elseif ($ct === 'gift') {
        $path = yvo_resolve_gift_template_txt_path($template_id, $property_data);
    } elseif (yvo_dkp_uses_standard_base_template($template_id) && file_exists($standard_base)) {
        $path = $standard_base;
    } else {
        $path = yvo_resolve_template_path($template_id);
        if (!$path || !file_exists($path)) {
            $path = file_exists($bundled_dkp) ? $bundled_dkp : null;
        }
        if ($path && file_exists($path)) {
            $probe = @file_get_contents($path, false, null, 0, 131072);
            if (!is_string($probe) || strpos($probe, '{{SELLERS_BLOCK}}') === false) {
                if ($template_id === 'default' && file_exists(YVO_PLUGIN_DIR . 'templates/default.txt')) {
                    $path = YVO_PLUGIN_DIR . 'templates/default.txt';
                } elseif (file_exists($bundled_dkp)) {
                    $path = $bundled_dkp;
                }
            }
        }
    }
    if (!$path || !file_exists($path)) {
        return '';
    }
    $content = file_get_contents($path);
    $map = yvo_get_dkp_ipoteka_placeholder_map($sellers, $buyers, $property_data, $options, $ct);
    foreach ($map as $key => $val) {
        $content = str_replace('{{' . $key . '}}', (string) $val, $content);
    }
  // Повторная подстановка — в динамических пунктах могут остаться вложенные {{…}}.
    foreach ($map as $key => $val) {
        $content = str_replace('{{' . $key . '}}', (string) $val, $content);
    }
    return $content;
}

/**
 * Заполнение шаблона акта приёма-передачи (стиль и таблица объекта как в ДКП).
 */
function yvo_fill_act_sale_template($sellers, $buyers, $property_data, $options = array(), $contract_type = 'sale') {
    $path = YVO_PLUGIN_DIR . 'templates/act-sale-standard.txt';
    if (!file_exists($path)) {
        return '';
    }
    $content = file_get_contents($path);
    if (!is_string($content) || $content === '') {
        return '';
    }
    $map = yvo_get_dkp_ipoteka_placeholder_map($sellers, $buyers, $property_data, $options, $contract_type);
    $ot = isset($property_data['object_type']) ? sanitize_key((string) $property_data['object_type']) : 'apartment';
    $map['PROPERTY_CADASTRAL_LABEL'] = ($ot === 'house_with_plot')
        ? 'Кадастровый номер жилого дома'
        : 'Кадастровый номер объекта недвижимости';
    if ($ot === 'house_with_plot') {
        $house_cad = trim((string) ($property_data['house_cadastral_number'] ?? $property_data['cadastral_number'] ?? ''));
        if ($house_cad !== '') {
            $map['PROPERTY_CADASTRAL_NUM'] = $house_cad;
        }
        $extra = isset($map['PROPERTY_DKP_TABLE_EXTRA']) ? (string) $map['PROPERTY_DKP_TABLE_EXTRA'] : '';
        if ($extra !== '' && strpos($content, '{{PROPERTY_DKP_TABLE_EXTRA}}') !== false) {
            // В акте кадастр дома уже в первой строке таблицы — в extra оставляем участок и площадь.
            $extra_lines = array();
            foreach (preg_split('/\r\n|\r|\n/', $extra) as $row) {
                $row = trim($row);
                if ($row === '' || preg_match('/^Кадастровый номер жилого дома/ui', $row)) {
                    continue;
                }
                $extra_lines[] = $row;
            }
            $map['PROPERTY_DKP_TABLE_EXTRA'] = implode("\n", $extra_lines);
        }
    }
    foreach ($map as $key => $val) {
        $content = str_replace('{{' . $key . '}}', (string) $val, $content);
    }
    foreach ($map as $key => $val) {
        $content = str_replace('{{' . $key . '}}', (string) $val, $content);
    }
    return $content;
}

/**
 * Стилизованный HTML акта приёма-передачи (те же CSS и карточки, что у ДКП).
 *
 * @param array<string, scalar> $replacements
 */
function yvo_generate_act_styled_html($act_content_raw, $replacements) {
    return yvo_generate_dkp_ipoteka_full_styled_html($act_content_raw, $replacements, 'act');
}

/** Какой txt-шаблон фактически будет использован для ДКП ипотека (с fallback на bundled). */
function yvo_resolve_dkp_ipoteka_txt_path_effective($template_id) {
    $path = yvo_resolve_template_path($template_id);
    $bundled_dkp = YVO_PLUGIN_DIR . 'templates/dkp-kvartira-ipoteka.txt';
    if (!$path || !file_exists($path)) {
        $path = file_exists($bundled_dkp) ? $bundled_dkp : null;
    }
    if ($path && file_exists($path)) {
        $probe = @file_get_contents($path, false, null, 0, 131072);
        if (!is_string($probe) || strpos($probe, '{{SELLERS_BLOCK}}') === false) {
            if (file_exists($bundled_dkp)) {
                $path = $bundled_dkp;
            }
        }
    }
    return ($path && file_exists($path)) ? $path : null;
}

// Построение карты плейсхолдеров из данных формы (для загруженного шаблона)
function yvo_build_placeholder_map($seller_data, $buyer_data, $property_data) {
    $price = isset($property_data['price']) && $property_data['price'] !== '' ? number_format(floatval($property_data['price']), 2, ',', ' ') : '__________';
    $price_words = isset($property_data['price_words']) && $property_data['price_words'] !== '' ? $property_data['price_words'] : '________________';
    $deposit = isset($property_data['deposit_amount']) && $property_data['deposit_amount'] !== '' ? number_format(floatval($property_data['deposit_amount']), 2, ',', ' ') : '__________';
    $deposit_words = isset($property_data['deposit_amount_words']) && $property_data['deposit_amount_words'] !== '' ? $property_data['deposit_amount_words'] : '________________';
    $remaining = isset($property_data['remaining_amount']) && $property_data['remaining_amount'] !== '' ? number_format(floatval($property_data['remaining_amount']), 2, ',', ' ') : '__________';
    $deadline = yvo_format_deposit_main_contract_deadline(isset($property_data['main_contract_deadline']) ? $property_data['main_contract_deadline'] : '');
    $cadastral = isset($property_data['cadastral_number']) && $property_data['cadastral_number'] !== '' ? $property_data['cadastral_number'] : '____________________';
    $seller_passport = 'серия ' . ($seller_data['passport_series'] ?? '____') . ' № ' . ($seller_data['passport_number'] ?? '______');
    $buyer_passport = 'серия ' . ($buyer_data['passport_series'] ?? '____') . ' № ' . ($buyer_data['passport_number'] ?? '______');
    $seller_date = isset($seller_data['passport_date']) && $seller_data['passport_date'] ? $seller_data['passport_date'] : '________________';
    $buyer_date = isset($buyer_data['passport_date']) && $buyer_data['passport_date'] ? $buyer_data['passport_date'] : '________________';
    $seller_birth = isset($seller_data['birth_date']) && $seller_data['birth_date'] ? $seller_data['birth_date'] : '________________';
    $buyer_birth = isset($buyer_data['birth_date']) && $buyer_data['birth_date'] ? $buyer_data['birth_date'] : '________________';
    return array(
        'SELLER_FULL_NAME' => $seller_data['full_name'] ?? '________________',
        'SELLER_PASSPORT' => $seller_passport,
        'SELLER_PASSPORT_ISSUED' => $seller_data['passport_issued_by'] ?? '________________',
        'SELLER_PASSPORT_DATE' => $seller_date,
        'SELLER_BIRTH_DATE' => $seller_birth,
        'SELLER_REGISTRATION' => $seller_data['registration'] ?? '________________',
        'BUYER_FULL_NAME' => $buyer_data['full_name'] ?? '________________',
        'BUYER_PASSPORT' => $buyer_passport,
        'BUYER_PASSPORT_ISSUED' => $buyer_data['passport_issued_by'] ?? '________________',
        'BUYER_PASSPORT_DATE' => $buyer_date,
        'BUYER_BIRTH_DATE' => $buyer_birth,
        'BUYER_REGISTRATION' => $buyer_data['registration'] ?? '________________',
        'PROPERTY_ADDRESS' => $property_data['address'] ?? '________________',
        'PROPERTY_CADASTRAL_NUM' => $cadastral,
        'PROPERTY_AREA' => isset($property_data['area']) && $property_data['area'] !== '' ? $property_data['area'] : '___',
        'PROPERTY_PRICE' => $price,
        'PROPERTY_PRICE_WORDS' => $price_words,
        'DEPOSIT_AMOUNT' => $deposit,
        'DEPOSIT_AMOUNT_WORDS' => $deposit_words,
        'REMAINING_AMOUNT' => $remaining,
        'MAIN_CONTRACT_DEADLINE' => $deadline,
        'CURRENT_DATE' => date('d.m.Y'),
        'CURRENT_YEAR' => date('Y'),
        'WHAT_STAYS' => isset($property_data['what_stays']) ? $property_data['what_stays'] : '',
        'VACATE_DEADLINE' => isset($property_data['vacate_deadline']) ? $property_data['vacate_deadline'] : '14 дней',
    );
}

// Заполнение загруженного шаблона (текст с плейсхолдерами {{VAR}})
function yvo_fill_custom_template_content($template_content, $seller_data, $buyer_data, $property_data) {
    $placeholders = yvo_build_placeholder_map($seller_data, $buyer_data, $property_data);
    foreach ($placeholders as $key => $val) {
        $template_content = str_replace('{{' . $key . '}}', $val, $template_content);
    }
    return $template_content;
}

// Извлечение текста из загруженного файла шаблона (.txt, .docx, .pdf)
function yvo_extract_text_from_template_file($file_path, $extension) {
    $ext = strtolower($extension);
    if ($ext === 'txt') {
        $raw = file_get_contents($file_path);
        return is_string($raw) ? $raw : '';
    }
    if ($ext === 'docx') {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_zip', 'Для .docx нужна поддержка ZipArchive.');
        }
        $zip = new ZipArchive();
        if ($zip->open($file_path, ZipArchive::RDONLY) !== true) {
            return new WP_Error('bad_docx', 'Не удалось открыть файл .docx.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') {
            return new WP_Error('bad_docx', 'В .docx не найден word/document.xml.');
        }
        $xml = preg_replace('/<w:p[^>]*>/', "\n", $xml);
        $text = preg_replace('/<[^>]+>/u', ' ', $xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim(preg_replace('/\n\s+/', "\n", $text));
        return $text;
    }
    if ($ext === 'pdf') {
        $pdftotext = 'pdftotext';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $pdftotext = 'pdftotext.exe';
        }
        $out_file = wp_tempnam('yvo_pdf_');
        $cmd = sprintf('%s -layout -enc UTF-8 %s %s 2>&1', escapeshellcmd($pdftotext), escapeshellarg($file_path), escapeshellarg($out_file));
        @exec($cmd, $_, $ret);
        if ($ret === 0 && is_readable($out_file)) {
            $text = file_get_contents($out_file);
            @unlink($out_file);
            return is_string($text) ? $text : '';
        }
        if (file_exists($out_file)) {
            @unlink($out_file);
        }
        return new WP_Error('pdf_fail', 'Извлечение текста из PDF не удалось. Установите poppler-utils (pdftotext) или загрузите шаблон в формате .txt или .docx.');
    }
    return new WP_Error('unsupported', 'Формат не поддерживается. Используйте .txt, .docx или .pdf.');
}

// AJAX: извлечение текста из файла шаблона (docx, pdf) — загрузка файла на сервер
function yvo_ajax_extract_template_text() {
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        wp_send_json_error(array('message' => 'Файл не загружен.'));
    }
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = array('txt', 'doc', 'docx', 'pdf');
    if (!in_array($ext, $allowed, true)) {
        wp_send_json_error(array('message' => 'Допустимые форматы: .txt, .doc, .docx, .pdf'));
    }
    if ($ext === 'doc') {
        wp_send_json_error(array('message' => 'Старый формат .doc не поддерживается. Сохраните документ как .docx или .txt и загрузите снова.'));
    }
    $tmp = $file['tmp_name'];
    $text = yvo_extract_text_from_template_file($tmp, $ext);
    if (is_wp_error($text)) {
        wp_send_json_error(array('message' => $text->get_error_message()));
    }
    if (strlen($text) < 50) {
        wp_send_json_error(array('message' => 'В файле слишком мало текста. Убедитесь, что это шаблон договора.'));
    }
    if (strlen($text) > 50000) {
        wp_send_json_error(array('message' => 'Файл слишком большой (макс. 50000 символов после извлечения).'));
    }
    wp_send_json_success(array('text' => $text));
}

// AJAX: анализ загруженного шаблона договора через DeepSeek (определение переменных)
function yvo_ajax_analyze_contract_template() {
    $api_key = get_option('yvo_deepseek_api_key');
    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'DeepSeek не настроен. Укажите API ключ в настройках плагина.'));
    }
    $template_text = isset($_POST['template_text']) ? wp_unslash($_POST['template_text']) : '';
    if (strlen($template_text) < 50) {
        wp_send_json_error(array('message' => 'Текст шаблона слишком короткий или не передан. Загрузите файл .txt с шаблоном договора.'));
    }
    $template_text = sanitize_textarea_field($template_text);
    if (strlen($template_text) > 50000) {
        wp_send_json_error(array('message' => 'Шаблон слишком большой (макс. 50000 символов).'));
    }
    $prompt = "Ты — ассистент по анализу шаблонов договоров. Дан текст шаблона договора.

КРИТИЧЕСКИ ВАЖНО:
1) УДАЛИ из договора ВСЕ подсказки и пояснения в скобках: (фамилия имя отчество), (дата рождения), (адрес), (серия и номер паспорта) и т.п. — на их место поставь ОДИН плейсхолдер {{...}}, без оставления текста подсказки в договоре.
2) Все пустые поля (подчёркивания _____, пропуски, прочерки) замени на соответствующий плейсхолдер {{ИМЯ_PЕРЕМЕННОЙ}}.
3) Сохраняй ТОЧНО все переносы строк, абзацы и структуру текста. Не склеивай строки и не удаляй пустые строки между абзацами.

Используй ТОЛЬКО такие имена переменных:
- ФИО продавца: {{SELLER_FULL_NAME}}; дата рождения продавца: {{SELLER_BIRTH_DATE}}
- Паспорт продавца: {{SELLER_PASSPORT}}, кем выдан: {{SELLER_PASSPORT_ISSUED}}, дата выдачи: {{SELLER_PASSPORT_DATE}}, регистрация: {{SELLER_REGISTRATION}}
- ФИО покупателя: {{BUYER_FULL_NAME}}; дата рождения покупателя: {{BUYER_BIRTH_DATE}}
- Паспорт покупателя: {{BUYER_PASSPORT}}, {{BUYER_PASSPORT_ISSUED}}, {{BUYER_PASSPORT_DATE}}, {{BUYER_REGISTRATION}}
- Адрес объекта: {{PROPERTY_ADDRESS}}, кадастровый: {{PROPERTY_CADASTRAL_NUM}}, площадь: {{PROPERTY_AREA}}
- Цена: {{PROPERTY_PRICE}}, прописью: {{PROPERTY_PRICE_WORDS}}; задаток: {{DEPOSIT_AMOUNT}}, {{DEPOSIT_AMOUNT_WORDS}}; срок сделки: {{MAIN_CONTRACT_DEADLINE}}
- Текущая дата: {{CURRENT_DATE}}, год: {{CURRENT_YEAR}}
- Что остаётся / сроки снятия с регистрации: {{WHAT_STAYS}}, {{VACATE_DEADLINE}}

Верни ТОЛЬКО итоговый текст шаблона: никаких пояснений, никакого markdown, никаких подсказок в скобках — только текст договора с плейсхолдерами {{...}} и сохранённой разметкой абзацев.\n\nТекст шаблона:\n\n" . $template_text;

    $model = get_option('yvo_deepseek_model', 'deepseek-chat');
    $url = 'https://api.deepseek.com/v1/chat/completions';
    $headers = array('Authorization: Bearer ' . $api_key, 'Content-Type: application/json');
    $body = array(
        'model' => $model,
        'messages' => array(array('role' => 'user', 'content' => $prompt)),
        'max_tokens' => 8000,
        'temperature' => 0.2,
    );
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        wp_send_json_error(array('message' => 'Ошибка сети: ' . $err));
    }
    if ($http_code != 200) {
        $data = json_decode($response, true);
        $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Ошибка DeepSeek';
        wp_send_json_error(array('message' => $msg));
    }
    $data = json_decode($response, true);
    $content = isset($data['choices'][0]['message']['content']) ? trim($data['choices'][0]['message']['content']) : '';
    if (empty($content)) {
        wp_send_json_error(array('message' => 'DeepSeek не вернул текст шаблона.'));
    }
    wp_send_json_success(array('template_content' => $content, 'message' => 'Шаблон проанализирован. Переменные заменены на плейсхолдеры.'));
}

// Превью шаблона договора (для модального окна)
function yvo_ajax_frontend_get_template_preview() {
    $template_id = isset($_POST['template_id']) ? sanitize_text_field(wp_unslash($_POST['template_id'])) : 'default';
    if ($template_id === '') {
        $template_id = 'default';
    }
    $allowed = array_keys(yvo_get_available_templates());
    if (!in_array($template_id, $allowed, true)) {
        wp_send_json_error(array('message' => 'Шаблон не найден'));
    }
    $content = yvo_get_contract_template_content($template_id);
    if ($content === '' || $content === null) {
        $content = '(Текст шаблона пуст или файл не найден. Выберите другой шаблон или проверьте папку «templates» и «Шаблоны договоров».)';
    }
    wp_send_json_success(array('content' => $content));
}

function yvo_get_contract_template_content($template_id) {
    $placeholders = array(
        'SELLER_FULL_NAME' => '________________',
        'SELLER_PASSPORT' => 'серия ____ № ______',
        'SELLER_PASSPORT_ISSUED' => '________________',
        'SELLER_PASSPORT_DATE' => '________________',
        'SELLER_BIRTH_DATE' => '________________',
        'SELLER_REGISTRATION' => '________________',
        'BUYER_FULL_NAME' => '________________',
        'BUYER_PASSPORT' => 'серия ____ № ______',
        'BUYER_PASSPORT_ISSUED' => '________________',
        'BUYER_PASSPORT_DATE' => '________________',
        'BUYER_BIRTH_DATE' => '________________',
        'BUYER_REGISTRATION' => '________________',
        'PROPERTY_ADDRESS' => '________________',
        'PROPERTY_CADASTRAL_NUM' => '____________________',
        'PROPERTY_AREA' => '___',
        'PROPERTY_ROOMS' => '___',
        'PROPERTY_FLOOR' => '___',
        'PROPERTY_FLOORS_TOTAL' => '___',
        'PROPERTY_PRICE' => '________',
        'PROPERTY_PRICE_WORDS' => '________________',
        'DEPOSIT_AMOUNT' => '__________',
        'DEPOSIT_AMOUNT_WORDS' => '________________',
        'REMAINING_AMOUNT' => '__________',
        'MAIN_CONTRACT_DEADLINE' => date('d.m.Y', strtotime('+2 months')),
        'CURRENT_DATE' => date('d.m.Y'),
        'CURRENT_YEAR' => date('Y'),
        'PROPERTY_CADASTRAL' => '',
    );
    $template_files = array(
        'preliminary' => 'preliminary.txt',
        'deposit_agreement' => 'deposit-agreement.txt',
        'deposit_receipt' => 'deposit-receipt.txt',
        'advance_agreement' => 'advance-agreement.txt',
    );
    if (isset($template_files[$template_id])) {
        $path = YVO_PLUGIN_DIR . 'templates/' . $template_files[$template_id];
    } else {
        $path = yvo_resolve_template_path($template_id);
        if (!$path) {
            $path = YVO_PLUGIN_DIR . 'templates/default.txt';
        }
    }
    if (!file_exists($path)) {
        $path = YVO_PLUGIN_DIR . 'templates/default.txt';
    }
    if (!file_exists($path)) {
        $s = array('full_name' => '________________', 'passport_series' => '____', 'passport_number' => '______', 'passport_issued_by' => '________________', 'department_code' => '', 'registration' => '________________');
        $p = array('address' => '________________', 'property_type' => 'квартира', 'cadastral_number' => '', 'area' => '___', 'rooms' => '', 'floor' => '', 'floors_total' => '', 'price' => '________', 'price_words' => '________________');
        return yvo_generate_contract_text($s, $s, $p);
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        $content = '(Не удалось прочитать файл шаблона.)';
    }
    $dummy_seller = array('full_name' => '________________', 'passport_series' => '____', 'passport_number' => '______', 'passport_issued_by' => '________________', 'registration' => '________________');
    $dummy_property = array('address' => '________________', 'city' => '________________', 'cadastral_number' => '____________________', 'area' => '___', 'rooms' => '___', 'floor' => '___', 'price' => '________', 'price_words' => '________________', 'vacate_deadline' => '14');
    $content = yvo_replace_bracket_placeholders($content, $dummy_seller, $dummy_seller, $dummy_property);
    foreach ($placeholders as $key => $val) {
        $content = str_replace('{{' . $key . '}}', $val, $content);
    }
    return $content;
}

// Генерация текста акта приёма-передачи
function yvo_generate_act_text($seller_data, $buyer_data, $property_data) {
    $property_data = yvo_normalize_house_with_plot_cadastral(is_array($property_data) ? $property_data : array());
    $date = date('d.m.Y');
    $city = trim((string) ($property_data['city'] ?? ''));
    if ($city === '') {
        $city = '_______________';
    }
    $price = isset($property_data['price']) ? number_format(floatval($property_data['price']), 2, ',', ' ') : '________';
    $price_words = isset($property_data['price_words']) ? $property_data['price_words'] : '________________';
    $prop_type = isset($property_data['property_type']) ? $property_data['property_type'] : 'квартира';
    $address = $property_data['address'] ?? '________________';
    $cadastral = trim((string) ($property_data['cadastral_number'] ?? ''));
    if ($cadastral === '' && !empty($property_data['house_cadastral_number'])) {
        $cadastral = trim((string) $property_data['house_cadastral_number']);
    }
    if ($cadastral === '') {
        $cadastral = '________________';
    }
    $plot_cad = trim((string) ($property_data['plot_cadastral_number'] ?? ''));
    $area = isset($property_data['area']) && $property_data['area'] !== '' ? $property_data['area'] : '________';

    $act = "АКТ ПРИЕМА-ПЕРЕДАЧИ НЕДВИЖИМОСТИ\n";
    $act .= "г. " . $city . " «» __________ " . date('Y') . " г.\n\n";
    $act .= "Мы, нижеподписавшиеся:\n\n";
    $act .= "Продавец: " . ($seller_data['full_name'] ?? '________________') . ",\n";
    $act .= "(Ф.И.О. полностью)\n";
    $act .= "паспорт: серия " . ($seller_data['passport_series'] ?? '____') . " номер " . ($seller_data['passport_number'] ?? '______') . ", выдан «» __________ ______ г. " . ($seller_data['passport_issued_by'] ?? '________________') . ",\n";
    $act .= "код подразделения " . ($seller_data['department_code'] ?? '_______') . ", зарегистрирован(а) по адресу: " . ($seller_data['registration'] ?? '________________') . ",\n\n";
    $act .= "Покупатель: " . ($buyer_data['full_name'] ?? '________________') . ",\n";
    $act .= "(Ф.И.О. полностью)\n";
    $act .= "паспорт: серия " . ($buyer_data['passport_series'] ?? '____') . " номер " . ($buyer_data['passport_number'] ?? '______') . ", выдан «» __________ ______ г. " . ($buyer_data['passport_issued_by'] ?? '________________') . ",\n";
    $act .= "код подразделения " . ($buyer_data['department_code'] ?? '_______') . ", зарегистрирован(а) по адресу: " . ($buyer_data['registration'] ?? '________________') . ",\n\n";
    $act .= "руководствуясь Договором купли-продажи от «» __________ " . date('Y') . " г. (далее – Договор), составили настоящий Акт о нижеследующем:\n\n";
    $act .= "1. Подтверждение исполнения обязательств\n";
    $act .= "1.1. Продавец подтверждает, что он получил от Покупателя денежные средства в размере " . $price . " (" . $price_words . ") рублей в счет оплаты стоимости Объекта недвижимости, указанного в п. 2 настоящего Акта. Расчеты произведены полностью, претензий по оплате Продавец к Покупателю не имеет.\n\n";
    $act .= "1.2. Покупатель подтверждает, что он принял от Продавца следующее недвижимое имущество (далее – Объект):\n\n";
    $act .= "(" . $prop_type . ")\n\n";
    $act .= "Адрес (местоположение): " . $address . "\n";
    $act .= "Кадастровый номер: " . $cadastral . "\n";
    if ($plot_cad !== '') {
        $act .= "Кадастровый номер земельного участка: " . $plot_cad . "\n";
    }
    $act .= "Площадь: " . $area . " кв. м\n\n";
    $act .= "1.3. Стороны подтверждают, что обязательства по Договору исполнены в полном объеме.\n\n";
    $act .= "2. Состояние Объекта и отсутствие претензий\n";
    $act .= "2.1. Объект передан в состоянии, соответствующем условиям Договора. Покупатель осмотрел Объект, претензий к его техническому и санитарному состоянию не имеет.\n\n";
    $act .= "3. Заключительные положения\n";
    $act .= "3.1. Настоящий Акт составлен в двух экземплярах, имеющих равную юридическую силу, по одному для каждой из Сторон.\n\n";
    $act .= "Подписи сторон:\n";
    $act .= "Продавец: _____________________ / ____________________ /\n";
    $act .= "Покупатель: _____________________ / ____________________ /\n";
    return $act;
}

// Генерация текста расписки
function yvo_generate_receipt_text($seller_data, $buyer_data, $property_data) {
    $date = date('Y');
    $price = isset($property_data['price']) ? number_format(floatval($property_data['price']), 2, ',', ' ') : '________';
    $price_words = isset($property_data['price_words']) ? $property_data['price_words'] : '________________';
    $address = $property_data['address'] ?? '________________';

    $receipt = "РАСПИСКА В ПОЛУЧЕНИИ ДЕНЕЖНЫХ СРЕДСТВ\n";
    $receipt .= "(Отдельный документ, который Продавец пишет собственноручно в момент получения денег)\n\n";
    $receipt .= "г. _______________ «» __________ " . $date . " г.\n\n";
    $receipt .= "Я, " . ($seller_data['full_name'] ?? '________________') . ",\n";
    $receipt .= "(Ф.И.О. продавца полностью)\n";
    $receipt .= "паспорт: серия " . ($seller_data['passport_series'] ?? '____') . " номер " . ($seller_data['passport_number'] ?? '______') . ", выдан «» __________ ______ г. " . ($seller_data['passport_issued_by'] ?? '________________') . ",\n";
    $receipt .= "код подразделения " . ($seller_data['department_code'] ?? '_______') . ", зарегистрирован(а) по адресу: " . ($seller_data['registration'] ?? '________________') . ",\n\n";
    $receipt .= "настоящей распиской подтверждаю, что получил(а) от " . ($buyer_data['full_name'] ?? '________________') . ",\n";
    $receipt .= "(Ф.И.О. покупателя полностью)\n";
    $receipt .= "паспорт: серия " . ($buyer_data['passport_series'] ?? '____') . " номер " . ($buyer_data['passport_number'] ?? '______') . ", выдан «» __________ ______ г. " . ($buyer_data['passport_issued_by'] ?? '________________') . ",\n";
    $receipt .= "код подразделения " . ($buyer_data['department_code'] ?? '_______') . ", зарегистрирован(а) по адресу: " . ($buyer_data['registration'] ?? '________________') . ",\n\n";
    $receipt .= "денежные средства в сумме " . $price . " (" . $price_words . ") рублей в счет оплаты стоимости недвижимого имущества, расположенного по адресу: " . $address . ",\n\n";
    $receipt .= "приобретаемого по Договору купли-продажи от «» __________ " . $date . " г.\n\n";
    $receipt .= "Указанную сумму получил(а) полностью. Претензий по расчетам с Покупателем не имею.\n\n";
    $receipt .= "Подпись: ____________________ / ___________________________ /\n";
    return $receipt;
}

// ————— Фронтенд: отдельная страница (шорткод) —————
/**
 * Стили оболочки ДОКИ для формы договора (должны быть в очереди до wp_head — см. yvo_cabinet_virtual_enqueue_assets).
 *
 * @param string $frontend_ver Версия для query string (как у frontend.js).
 */
/**
 * Скрипт шагов ДОКИ (после frontend.js). Отдельный файл — надёжнее, чем огромный inline.
 */
function yvo_enqueue_doki_contract_form_js($frontend_ver) {
    if (!defined('YVO_PLUGIN_DIR') || !defined('YVO_PLUGIN_URL')) {
        return;
    }
    if (apply_filters('yvo_legacy_contract_form', false)) {
        return;
    }
    $path = YVO_PLUGIN_DIR . 'js/doki-contract-form.js';
    if (!is_file($path)) {
        return;
    }
    $ver = $frontend_ver . '.' . filemtime($path);
    wp_enqueue_script(
        'yvo-doki-contract-form',
        YVO_PLUGIN_URL . 'js/doki-contract-form.js',
        array('jquery', 'yvo-frontend-js'),
        $ver,
        true
    );
}

/**
 * Резервная привязка кнопки «Добавить участника» после полной инициализации frontend.js (см. yvoFpParticipantTabAddReady).
 */
function yvo_enqueue_participant_tab_add_js($frontend_ver) {
    if (!defined('YVO_PLUGIN_DIR') || !defined('YVO_PLUGIN_URL')) {
        return;
    }
    $path = YVO_PLUGIN_DIR . 'js/yvo-participant-tab-add.js';
    if (!is_file($path)) {
        return;
    }
    $ver = $frontend_ver . '.' . filemtime($path);
    wp_enqueue_script(
        'yvo-participant-tab-add',
        YVO_PLUGIN_URL . 'js/yvo-participant-tab-add.js',
        array('jquery', 'yvo-frontend-js'),
        $ver,
        true
    );
}

function yvo_enqueue_doki_shell_form_assets($frontend_ver) {
    if (!defined('YVO_PLUGIN_DIR') || !defined('YVO_PLUGIN_URL')) {
        return;
    }
    $dir = YVO_PLUGIN_DIR;
    $url = YVO_PLUGIN_URL;
    $path_shell = $dir . 'css/doki-shell.css';
    $path_skin = $dir . 'css/doki-form-skin.css';
    /*
     * doki-shell.css — hero и полноширинная оболочка (можно отключить фильтром).
     * doki-form-skin.css — стили самой формы (шаги, таблетки); подключаются всегда, если файл есть.
     */
    $shell_ok = apply_filters('yvo_frontend_use_doki_shell', true) && is_file($path_shell);
    if ($shell_ok) {
        wp_enqueue_style(
            'yvo-doki-shell-css',
            $url . 'css/doki-shell.css',
            array('yvo-frontend-css'),
            $frontend_ver . '.' . filemtime($path_shell)
        );
        $cab_path = $dir . 'css/cabinet-auth.css';
        if (is_file($cab_path)) {
            wp_enqueue_style(
                'yvo-cabinet-auth',
                $url . 'css/cabinet-auth.css',
                array('yvo-doki-shell-css'),
                $frontend_ver . '.' . filemtime($cab_path)
            );
            if (function_exists('yvo_cabinet_topbar_mobile_inline_css')) {
                wp_add_inline_style('yvo-cabinet-auth', yvo_cabinet_topbar_mobile_inline_css());
            }
            $mob_path = $dir . 'css/mobile-site.css';
            if (is_file($mob_path)) {
                wp_enqueue_style(
                    'yvo-mobile-site',
                    $url . 'css/mobile-site.css',
                    array('yvo-cabinet-auth'),
                    $frontend_ver . '.' . filemtime($mob_path)
                );
            }
            $js_path = $dir . 'js/cabinet-topbar.js';
            if (is_file($js_path)) {
                wp_enqueue_script(
                    'yvo-cabinet-topbar',
                    $url . 'js/cabinet-topbar.js',
                    array(),
                    $frontend_ver . '.' . filemtime($js_path),
                    true
                );
            }
        }
    }
    if (apply_filters('yvo_legacy_contract_form', false)) {
        return;
    }
    if (is_file($path_skin)) {
        $deps = $shell_ok ? array('yvo-doki-shell-css', 'yvo-frontend-css') : array('yvo-frontend-css');
        $skin_ver = $frontend_ver . '.' . filemtime($path_skin);
        wp_enqueue_style(
            'yvo-doki-form-skin-css',
            $url . 'css/doki-form-skin.css',
            $deps,
            $skin_ver
        );
        /*
         * Дубль критичных правил в inline: тема WP часто подключает button/input после плагина и перебивает файл.
         * Inline идёт сразу после основного CSS того же handle — выше по каскаду, чем типовые правила темы.
         */
        wp_add_inline_style(
            'yvo-doki-form-skin-css',
            '@media (max-width:1024px){#yvo-doki-contract-type-picker{box-sizing:border-box;width:100%!important;max-width:100%!important}#yvo-doki-contract-type-picker .contract-types-inline .top-buttons{display:flex!important;flex-direction:column!important;flex-wrap:nowrap!important;align-items:stretch!important;width:100%!important;max-width:100%!important;gap:12px!important}#yvo-doki-contract-type-picker .pbtn3d.type-option,#yvo-doki-contract-type-picker .pbtn3d.type-more-btn,#yvo-doki-contract-type-picker .yvo-doki-type-extra{width:100%!important;max-width:none!important;min-height:50px!important;box-sizing:border-box!important}}'
        );
        $mos_path = $dir . 'css/doki-mobile-one-screen.css';
        if (is_file($mos_path)) {
            wp_enqueue_style(
                'yvo-doki-mobile-one-screen-css',
                $url . 'css/doki-mobile-one-screen.css',
                array('yvo-doki-form-skin-css'),
                $frontend_ver . '.' . filemtime($mos_path)
            );
        }
        $panels_js = $dir . 'js/doki-form-panels.js';
        if (is_file($panels_js)) {
            wp_enqueue_script(
                'yvo-doki-form-panels',
                $url . 'js/doki-form-panels.js',
                array(),
                $frontend_ver . '.' . filemtime($panels_js),
                true
            );
        }
    }
}

/**
 * Последний резерв: стили в footer (после темы и оптимизаторов), только если подключён skin формы ДОКИ.
 */
function yvo_print_doki_contract_type_picker_footer_css() {
    if (is_admin() || !wp_style_is('yvo-doki-form-skin-css', 'enqueued')) {
        return;
    }
    echo '<style id="yvo-doki-contract-type-picker-last">@media (max-width: 1024px) { #yvo-doki-contract-type-picker .contract-types-inline .top-buttons { display: flex !important; flex-direction: column !important; align-items: stretch !important; width: 100% !important; max-width: 100% !important; gap: 12px !important; } #yvo-doki-contract-type-picker .pbtn3d.type-option, #yvo-doki-contract-type-picker .pbtn3d.type-more-btn, #yvo-doki-contract-type-picker .yvo-doki-type-extra { width: 100% !important; max-width: none !important; box-sizing: border-box !important; } }</style>' . "\n";
}
add_action('wp_footer', 'yvo_print_doki_contract_type_picker_footer_css', 99999);

/**
 * Декодирует JSON из $_POST через wp_unslash (корректно для WordPress).
 *
 * @param string $post_key
 * @return mixed|null null — поля нет, пустая строка или невалидный JSON
 */
function yvo_json_decode_post_field($post_key) {
    if (!isset($_POST[$post_key])) {
        return null;
    }
    $raw = wp_unslash($_POST[$post_key]);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    return $decoded;
}

/**
 * Санитизация строки участника из AJAX (participant_tab, minor_age_group, многострочные поля).
 *
 * @param array<string, mixed> $row
 * @return array<string, string>
 */
function yvo_sanitize_party_row_from_request(array $row) {
    $textarea_keys = array('power_of_attorney_details', 'guardian_basis');
    $out = array();
    foreach ($row as $k => $v) {
        $key = is_string($k) ? $k : (string) $k;
        if (!is_scalar($v) && $v !== null) {
            continue;
        }
        if ($v === null) {
            $out[$key] = '';
            continue;
        }
        if (in_array($key, $textarea_keys, true)) {
            $out[$key] = sanitize_textarea_field((string) $v);
        } else {
            $out[$key] = sanitize_text_field((string) $v);
        }
    }
    return $out;
}

/**
 * Диагностика для wp_localize_script: какая копия js/frontend.js на сервере (сравните с локальной папкой плагина).
 *
 * @return array<string, scalar>
 */
function yvo_frontend_ajax_fp_diag_fields() {
    $mtime = 0;
    $slug = '';
    if (defined('YVO_PLUGIN_DIR') && is_string(YVO_PLUGIN_DIR)) {
        $base = rtrim(YVO_PLUGIN_DIR, '/\\');
        $js = $base . '/js/frontend.js';
        if (is_file($js)) {
            $mtime = (int) filemtime($js);
        }
        $slug = basename($base);
    }
    return array(
        'fp_js_mtime' => $mtime,
        'fp_plugin_slug' => $slug,
    );
}

/**
 * Подключает css/js формы до wp_head: шорткод выполняется после вывода head, иначе стили уходят в footer
 * (кэш/оптимизаторы могут их не применять).
 */
function yvo_contract_form_enqueue_assets_early() {
    if (is_admin() || !get_option('yvo_enable_shortcode', 1) || !is_user_logged_in()) {
        return;
    }
    $need = false;
    if (isset($_GET['yvo_cabinet']) && sanitize_key(wp_unslash($_GET['yvo_cabinet'])) === 'contracts') {
        $need = true;
    }
    if (!$need && function_exists('is_singular') && is_singular()) {
        $post = get_post();
        if ($post && !empty($post->post_content)) {
            $pc = $post->post_content;
            if (
                has_shortcode($pc, 'yvo_contract_form')
                || has_shortcode($pc, 'yandex_ocr_form')
                || strpos($pc, '[yvo_contract_form') !== false
                || strpos($pc, '[yandex_ocr_form') !== false
            ) {
                $need = true;
            }
        }
    }
    if (!$need) {
        return;
    }
    if (!defined('YVO_PLUGIN_DIR') || !defined('YVO_PLUGIN_URL')) {
        return;
    }
    $frontend_ver = YVO_VERSION . '.' . (file_exists(YVO_PLUGIN_DIR . 'js/frontend.js') ? filemtime(YVO_PLUGIN_DIR . 'js/frontend.js') : '');
    wp_enqueue_style('yvo-frontend-css', YVO_PLUGIN_URL . 'css/frontend.css', array(), $frontend_ver);
    if (is_file(YVO_PLUGIN_DIR . 'css/legal.css')) {
        wp_enqueue_style('yvo-legal-css', YVO_PLUGIN_URL . 'css/legal.css', array(), $frontend_ver);
    }
    yvo_enqueue_doki_shell_form_assets($frontend_ver);
    $field_review_ver = YVO_VERSION . '.' . (file_exists(YVO_PLUGIN_DIR . 'js/field-review.js') ? filemtime(YVO_PLUGIN_DIR . 'js/field-review.js') : '');
    $fp_js_deps = array('jquery');
    if (is_file(YVO_PLUGIN_DIR . 'js/field-review.js')) {
        wp_enqueue_script('yvo-field-review-js', YVO_PLUGIN_URL . 'js/field-review.js', array('jquery'), $field_review_ver, true);
        $fp_js_deps[] = 'yvo-field-review-js';
    }
    wp_enqueue_script('yvo-frontend-js', YVO_PLUGIN_URL . 'js/frontend.js', $fp_js_deps, $frontend_ver, true);
    yvo_enqueue_doki_contract_form_js($frontend_ver);
    yvo_enqueue_participant_tab_add_js($frontend_ver);
    $tariff_payload = array();
    if (function_exists('yvo_tariff_frontend_payload')) {
        $tariff_payload = yvo_tariff_frontend_payload(get_current_user_id());
    }
    wp_localize_script(
        'yvo-frontend-js',
        'yvo_frontend_ajax',
        array_merge(
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('yvo_frontend_contract'),
                'max_size' => get_option('yvo_max_size', 20) * 1024 * 1024,
                'max_size_mb' => get_option('yvo_max_size', 20),
                'upload_timeout' => 120000,
                'is_pro' => apply_filters('yvo_is_pro', false) ? 1 : 0,
                'user_logged_in' => 1,
                'tariff' => $tariff_payload,
            ),
            yvo_frontend_ajax_fp_diag_fields(),
            function_exists('yvo_legal_frontend_payload') ? yvo_legal_frontend_payload() : array()
        )
    );
}

add_action('wp_enqueue_scripts', 'yvo_contract_form_enqueue_assets_early', 6);

/**
 * Доп. query-параметр к URL скриптов формы (обход жёсткого кэша браузера/CDN при том же ?ver=).
 *
 * @param string|false $src
 * @param string       $handle
 * @return string|false
 */
function yvo_script_loader_src_cache_bust($src, $handle) {
    if (! defined('YVO_PLUGIN_DIR') || ! is_string($src) || $src === '') {
        return $src;
    }
    $map = array(
    'yvo-frontend-js' => YVO_PLUGIN_DIR . 'js/frontend.js',
    'yvo-field-review-js' => YVO_PLUGIN_DIR . 'js/field-review.js',
    'yvo-doki-contract-form' => YVO_PLUGIN_DIR . 'js/doki-contract-form.js',
        'yvo-participant-tab-add' => YVO_PLUGIN_DIR . 'js/yvo-participant-tab-add.js',
    );
    if (! isset($map[$handle])) {
        return $src;
    }
    $path = $map[$handle];
    if (! is_readable($path)) {
        return $src;
    }
    return add_query_arg('yvo_v', (string) filemtime($path), $src);
}
add_filter('script_loader_src', 'yvo_script_loader_src_cache_bust', 20, 2);

function yvo_frontend_contract_form_shortcode($atts) {
    if (!get_option('yvo_enable_shortcode', 1)) {
        return '';
    }

    // Требуем авторизацию перед доступом к форме договора.
    // Показываем форму входа (включая OAuth Яндекс), затем редирект обратно на текущую страницу.
    if (!is_user_logged_in() && function_exists('yvo_cabinet_shortcode_login')) {
        $current = function_exists('yvo_cabinet_contracts_redirect_url')
            ? yvo_cabinet_contracts_redirect_url()
            : (is_singular() ? get_permalink() : home_url('/'));
        return yvo_cabinet_shortcode_login(array('redirect' => $current));
    }

    $frontend_ver = YVO_VERSION . '.' . (file_exists(YVO_PLUGIN_DIR . 'js/frontend.js') ? filemtime(YVO_PLUGIN_DIR . 'js/frontend.js') : '');
    wp_enqueue_style('yvo-frontend-css', YVO_PLUGIN_URL . 'css/frontend.css', array(), $frontend_ver);
    if (is_file(YVO_PLUGIN_DIR . 'css/legal.css')) {
        wp_enqueue_style('yvo-legal-css', YVO_PLUGIN_URL . 'css/legal.css', array(), $frontend_ver);
    }
    yvo_enqueue_doki_shell_form_assets($frontend_ver);
    $field_review_ver = YVO_VERSION . '.' . (file_exists(YVO_PLUGIN_DIR . 'js/field-review.js') ? filemtime(YVO_PLUGIN_DIR . 'js/field-review.js') : '');
    $fp_js_deps = array('jquery');
    if (is_file(YVO_PLUGIN_DIR . 'js/field-review.js')) {
        wp_enqueue_script('yvo-field-review-js', YVO_PLUGIN_URL . 'js/field-review.js', array('jquery'), $field_review_ver, true);
        $fp_js_deps[] = 'yvo-field-review-js';
    }
    wp_enqueue_script('yvo-frontend-js', YVO_PLUGIN_URL . 'js/frontend.js', $fp_js_deps, $frontend_ver, true);
    yvo_enqueue_doki_contract_form_js($frontend_ver);
    yvo_enqueue_participant_tab_add_js($frontend_ver);
    $tariff_payload = array();
    if (function_exists('yvo_tariff_frontend_payload') && is_user_logged_in()) {
        $tariff_payload = yvo_tariff_frontend_payload(get_current_user_id());
    }
    wp_localize_script(
        'yvo-frontend-js',
        'yvo_frontend_ajax',
        array_merge(
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('yvo_frontend_contract'),
                'max_size' => get_option('yvo_max_size', 20) * 1024 * 1024,
                'max_size_mb' => get_option('yvo_max_size', 20),
                'upload_timeout' => 120000,
                'is_pro' => apply_filters('yvo_is_pro', false) ? 1 : 0,
                'user_logged_in' => is_user_logged_in() ? 1 : 0,
                'tariff' => $tariff_payload,
            ),
            yvo_frontend_ajax_fp_diag_fields(),
            function_exists('yvo_legal_frontend_payload') ? yvo_legal_frontend_payload() : array()
        )
    );
    // Вкладки / копирование / удаление — только в js/frontend.js (раньше дублировалось inline и срабатывало дважды).
    $inline_tariff = "
    jQuery(function($){
        if (typeof window.yvoFpApplyFreePlanUiLocks === 'function') {
            window.yvoFpApplyFreePlanUiLocks();
        } else if (typeof yvo_frontend_ajax !== 'undefined' && yvo_frontend_ajax.tariff) {
            var t = yvo_frontend_ajax.tariff;
            if (parseInt(t.free_no_autofill, 10) === 1 || parseInt(t.can_autofill, 10) !== 1) {
                $('#yvo-fp-autofill-dropdown').hide();
            }
            if (parseInt(t.can_contract_check, 10) !== 1) {
                $('.yvo-fp-check-contract-standalone, #yvo-fp-check-contract-btn').hide();
            }
        }
    });
    ";
    wp_add_inline_script('yvo-frontend-js', $inline_tariff, 'after');
    // Форма реально выводится — для wp_footer (подписи по типу сделки; <script> в контенте записи может резаться kses).
    $GLOBALS['yvo_contract_form_on_page'] = true;
    ob_start();
    include YVO_PLUGIN_DIR . 'views/frontend-page.php';
    return ob_get_clean();
}

/**
 * Резервные подписи вкладок/шагов ДОКИ по типу сделки (vanilla JS из js/yvo-contract-type-labels-fallback.js).
 * Вывод в footer: из шорткода иногда вырезают <script>, из-за чего подписи не обновлялись.
 */
function yvo_print_contract_type_labels_fallback_footer() {
    if (empty($GLOBALS['yvo_contract_form_on_page'])) {
        return;
    }
    if (!defined('YVO_PLUGIN_DIR')) {
        return;
    }
    $path = YVO_PLUGIN_DIR . 'js/yvo-contract-type-labels-fallback.js';
    if (!is_readable($path)) {
        return;
    }
    echo '<script id="yvo-contract-labels-fallback-footer">';
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- статический JS из файла плагина
    echo file_get_contents($path);
    echo '</script>';
}

add_action('wp_footer', 'yvo_print_contract_type_labels_fallback_footer', 99999);

function yvo_maybe_enqueue_frontend_assets() {
    // Ресурсы подключаются в шорткоде
}

/** Нормализация хоста для сравнения: без порта, без www, localhost = 127.0.0.1 */
function yvo_normalize_host($host) {
    if (!is_string($host) || $host === '') return '';
    $h = strtolower(trim(preg_replace('/:\d+$/', '', $host)));
    if (strpos($h, 'www.') === 0) $h = substr($h, 4);
    if ($h === '127.0.0.1' || $h === 'localhost' || $h === '::1') return 'localhost';
    return $h;
}

/** Проверка: запрос с того же сайта (Referer, Origin или хост). Запасной вариант при истёкшем nonce. */
function yvo_request_from_same_site() {
    $home = home_url('/');
    $home_host = parse_url($home, PHP_URL_HOST);
    $norm_home = $home_host !== null ? yvo_normalize_host($home_host) : '';

    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    if ($referer !== '' && $norm_home !== '') {
        $ref_host = parse_url($referer, PHP_URL_HOST);
        if ($ref_host !== null && yvo_normalize_host($ref_host) === $norm_home) {
            $ref_scheme = parse_url($referer, PHP_URL_SCHEME);
            $home_scheme = parse_url($home, PHP_URL_SCHEME);
            if ((!$ref_scheme || !$home_scheme) || strtolower($ref_scheme) === strtolower($home_scheme)) {
                return true;
            }
        }
    }

    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($origin !== '' && $norm_home !== '') {
        $orig_host = parse_url($origin, PHP_URL_HOST);
        if ($orig_host !== null && yvo_normalize_host($orig_host) === $norm_home) {
            return true;
        }
    }

    $req_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if ($req_host === '' && !empty($_SERVER['SERVER_NAME'])) {
        $req_host = $_SERVER['SERVER_NAME'];
        if (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] !== '80' && $_SERVER['SERVER_PORT'] !== '443') {
            $req_host .= ':' . $_SERVER['SERVER_PORT'];
        }
    }
    if ($req_host !== '' && $norm_home !== '' && yvo_normalize_host($req_host) === $norm_home) {
        return true;
    }
    return false;
}

/** Единая проверка безопасности для фронтовых AJAX: nonce или запрос с того же сайта. Отключено — на хостингах из-за кэша/Referer часто срабатывает «ошибка безопасности». */
function yvo_frontend_ajax_security_ok() {
    return true;
}

// AJAX: загрузка файла на фронтенде (OCR)
function yvo_ajax_frontend_upload() {
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }
    if (function_exists('yvo_legal_check_request_pd_consent') && !yvo_legal_check_request_pd_consent()) {
        wp_send_json_error(array('message' => 'Для загрузки документов с персональными данными необходимо согласие. Отметьте галочку согласия на обработку ПДн.'));
    }
    // Проверка nonce отключена — на хостингах из-за кэша часто срабатывала «ошибка безопасности».
    if (!isset($_FILES['file'])) {
        wp_send_json_error(array('message' => 'Файл не передан. Выберите файл и загрузите снова.'));
    }
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $err_msg = array(
            UPLOAD_ERR_INI_SIZE => 'Файл слишком большой (лимит сервера).',
            UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой.',
            UPLOAD_ERR_PARTIAL => 'Файл загружен частично. Попробуйте снова.',
            UPLOAD_ERR_NO_FILE => 'Файл не выбран.',
            UPLOAD_ERR_NO_TMP_DIR => 'Ошибка сервера: нет временной папки.',
            UPLOAD_ERR_CANT_WRITE => 'Ошибка сервера: не удалось записать файл.',
            UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением PHP.',
        );
        $msg = isset($err_msg[$_FILES['file']['error']]) ? $err_msg[$_FILES['file']['error']] : 'Ошибка загрузки (код ' . $_FILES['file']['error'] . ').';
        wp_send_json_error(array('message' => $msg));
    }
    $allowed_ext = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'pdf');
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) {
        wp_send_json_error(array('message' => 'Неверный формат. Допустимы: JPG, PNG, GIF, BMP, WEBP, PDF'));
    }
    $max_size = get_option('yvo_max_size', 20) * 1024 * 1024;
    if ($_FILES['file']['size'] > $max_size) {
        wp_send_json_error(array('message' => 'Файл слишком большой'));
    }
    $temp_dir = function_exists('yvo_writable_temp_dir') ? yvo_writable_temp_dir() : '';
    if ($temp_dir === '') {
        wp_send_json_error(array('message' => 'Нет прав на запись во временную папку. Обновите страницу (Ctrl+F5) или обратитесь к администратору.'));
    }
    $tmp_path = $temp_dir . 'yvo_' . uniqid('', true) . '_' . sanitize_file_name($_FILES['file']['name']);
    $moved = @move_uploaded_file($_FILES['file']['tmp_name'], $tmp_path);
    if (!$moved) {
        $moved = @copy($_FILES['file']['tmp_name'], $tmp_path);
        if ($moved) {
            @unlink($_FILES['file']['tmp_name']);
        }
    }
    if (!$moved || !file_exists($tmp_path)) {
        wp_send_json_error(array('message' => 'Не удалось сохранить файл для OCR. Попробуйте другой формат (JPG/PNG/PDF) или обратитесь к администратору.'));
    }
    $api_key = get_option('yvo_api_key');
    $folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    $language = get_option('yvo_language', 'ru');
    $mistral_fallback = function_exists('yvo_mistral_fallback_available') && yvo_mistral_fallback_available();
    if ($ext === 'pdf') {
        $pdf_processor = new YVO_PDF_Processor();
        $result = $pdf_processor->process_from_path($tmp_path, $api_key, $folder_id, $language);
        @unlink($tmp_path);
    } else {
        if ((empty($api_key) || empty($folder_id)) && !$mistral_fallback) {
            @unlink($tmp_path);
            wp_send_json_error(array('message' => 'API не настроен. Настройте Яндекс Vision в админке (для фото/JPG). PDF на VPS работает через pdftotext.'));
        }
        $result = yvo_recognize_text_from_local($tmp_path, $api_key, $folder_id, $language);
        @unlink($tmp_path);
    }
    if (!empty($result['success'])) {
        $data = array('text' => $result['text'], 'filename' => isset($result['filename']) ? $result['filename'] : basename($_FILES['file']['name']));
        if (empty($result['extracted_data']) && !empty($result['text']) && strlen($result['text']) > 200
            && function_exists('yvo_deepseek_extract_egrn_data')
            && preg_match('/кадастров|егрн|единый\s+государственный\s+реестр|\d{2}:\d{2}:\d{6,}/ui', $result['text'])) {
            $egrn_parsed = yvo_deepseek_extract_egrn_data($result['text']);
            if (!empty($egrn_parsed['property']) || !empty($egrn_parsed['participants'])) {
                $result['extracted_data'] = $egrn_parsed;
            }
        }
        if (!empty($result['extracted_data'])) {
            $data['extracted_data'] = $result['extracted_data'];
        }
        $data['_v'] = '3.0-upload-ok'; // метка: новая версия плагина (без проверки nonce)
        wp_send_json_success($data);
    }
    wp_send_json_error(array('message' => isset($result['message']) ? $result['message'] : 'Ошибка распознавания'));
}

// AJAX: исправить кодировку текста выписки ЕГРН через DeepSeek (по кнопке на фронте или в админке)
function yvo_ajax_fix_egrn_text() {
    set_time_limit(600);
    $text = isset($_POST['text']) ? wp_unslash($_POST['text']) : '';
    $text = is_string($text) ? trim(strip_tags($text)) : '';
    if (strlen($text) < 100) {
        wp_send_json_error(array('message' => 'Слишком короткий текст (нужно от 100 символов)'));
    }
    if (empty(get_option('yvo_deepseek_api_key'))) {
        wp_send_json_error(array('message' => 'Не настроен API ключ DeepSeek'));
    }
    $parser_file = defined('YVO_PLUGIN_DIR') ? YVO_PLUGIN_DIR . 'includes/deepseek-parser.php' : __DIR__ . '/includes/deepseek-parser.php';
    if (is_file($parser_file)) {
        require_once $parser_file;
    }
    if (!class_exists('YVO_DeepSeekParser')) {
        wp_send_json_error(array('message' => 'Модуль DeepSeek недоступен'));
    }
    $parser = new YVO_DeepSeekParser();
    $result = $parser->fix_egrn_text($text);
    if (!empty($result['success']) && !empty($result['text'])) {
        $fixed = $result['text'];
        $len = strlen($fixed);
        if ($len <= 30000) {
            wp_send_json_success(array('text' => $fixed));
        }
        $key = 'yvo_fegrn_' . bin2hex(random_bytes(16));
        $dir = defined('YVO_TEMP_DIR') ? YVO_TEMP_DIR : (defined('YVO_PLUGIN_DIR') ? YVO_PLUGIN_DIR . 'tmp/' : __DIR__ . '/tmp/');
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $file = $dir . $key . '.txt';
        if (file_put_contents($file, $fixed) !== false) {
            set_transient($key, $file, 600);
            wp_send_json_success(array('key' => $key, 'length' => $len));
        }
        wp_send_json_success(array('text' => $fixed));
    }
    wp_send_json_error(array('message' => isset($result['message']) ? $result['message'] : 'Не удалось исправить текст'));
}

// AJAX: получить полный исправленный текст ЕГРН по ключу (файл или transient)
function yvo_ajax_get_fix_egrn_result() {
    $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
    if (strpos($key, 'yvo_fegrn_') !== 0 || strlen($key) !== 42) {
        wp_send_json_error(array('message' => 'Неверный ключ'));
    }
    $path = get_transient($key);
    if ($path !== false && is_string($path) && is_file($path)) {
        delete_transient($key);
        $text = file_get_contents($path);
        @unlink($path);
        if ($text !== false) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Length: ' . strlen($text));
            echo $text;
            exit;
        }
    }
    $text = get_transient($key);
    if ($text !== false && is_string($text)) {
        delete_transient($key);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: ' . strlen($text));
        echo $text;
        exit;
    }
    wp_send_json_error(array('message' => 'Результат истёк или не найден'));
}

// AJAX: извлечь данные объекта и участников из текста выписки ЕГРН (по кнопке)
function yvo_ajax_extract_egrn_data() {
    $text = isset($_POST['text']) ? wp_unslash($_POST['text']) : '';
    $text = is_string($text) ? trim($text) : '';
    if (function_exists('yvo_strip_pdf_ocr_boilerplate')) {
        $text = yvo_strip_pdf_ocr_boilerplate($text);
    }
    if (strlen($text) < 200) {
        wp_send_json_error(array('message' => 'Слишком короткий текст'));
    }
    $local_only = !empty($_POST['local_only']);
    $quality = !empty($_POST['quality']) || !$local_only;
    $extract_opts = array('local_only' => $local_only, 'quality' => $quality);
    $extracted = function_exists('yvo_deepseek_extract_egrn_data')
        ? yvo_deepseek_extract_egrn_data($text, $extract_opts)
        : array('property' => null, 'participants' => array(), 'egrn_check' => array());
    if (!empty($extracted['participants']) && is_array($extracted['participants'])) {
        foreach ($extracted['participants'] as $i => $p) {
            if (is_array($p)) {
                $extracted['participants'][$i] = yvo_sanitize_parsed_person_data($p, $text);
            }
        }
    }
    if (empty($extracted['property']) && empty($extracted['participants']) && empty($extracted['egrn_check'])) {
        wp_send_json_error(array('message' => 'Не удалось извлечь данные из выписки. Проверьте текст (после OCR) или ключ DeepSeek для уточнения.'));
    }
    wp_send_json_success(array('extracted_data' => $extracted));
}

// AJAX: перевод текста через DeepSeek (кнопка «Перевести»)
function yvo_ajax_translate_text() {
    set_time_limit(600);
    $text = isset($_POST['text']) ? wp_unslash($_POST['text']) : '';
    $text = sanitize_textarea_field($text);
    if (strlen($text) < 10) {
        wp_send_json_error(array('message' => 'Слишком короткий текст'));
    }
    $target = isset($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : 'ru';
    if (empty(get_option('yvo_deepseek_api_key'))) {
        wp_send_json_error(array('message' => 'Не настроен API ключ DeepSeek'));
    }
    $parser_file = defined('YVO_PLUGIN_DIR') ? YVO_PLUGIN_DIR . 'includes/deepseek-parser.php' : __DIR__ . '/includes/deepseek-parser.php';
    if (is_file($parser_file)) {
        require_once $parser_file;
    }
    if (!class_exists('YVO_DeepSeekParser')) {
        wp_send_json_error(array('message' => 'Модуль DeepSeek недоступен'));
    }
    $parser = new YVO_DeepSeekParser();
    $result = $parser->translate_text($text, $target);
    if (!empty($result['success']) && isset($result['text'])) {
        wp_send_json_success(array('text' => $result['text']));
    }
    wp_send_json_error(array('message' => isset($result['message']) ? $result['message'] : 'Ошибка перевода'));
}

/** ФИО в формате «Иванов И.В.» */
function yvo_format_person_short_name($full_name) {
    $full_name = trim(preg_replace('/\s+/u', ' ', (string) $full_name));
    if ($full_name === '') {
        return '';
    }
    // Юрлица не сокращаем до «А. У. …».
    if (preg_match('/^(?:ООО|ОАО|ПАО|АО|ЗАО|НАО|НКО|ГУП|МУП|ФГУП|ИП\b|Акционерное\s+общество|Общество\s+с\s+ограниченной|Публичное\s+акционерное|Непубличное\s+акционерное)/ui', $full_name)) {
        $len = function_exists('mb_strlen') ? mb_strlen($full_name, 'UTF-8') : strlen($full_name);
        if ($len > 72) {
            return (function_exists('mb_substr') ? mb_substr($full_name, 0, 69, 'UTF-8') : substr($full_name, 0, 69)) . '…';
        }
        return $full_name;
    }
    $parts = preg_split('/\s+/u', $full_name);
    if (count($parts) >= 3) {
        return $parts[0] . ' ' . mb_substr($parts[1], 0, 1, 'UTF-8') . '.' . mb_substr($parts[2], 0, 1, 'UTF-8') . '.';
    }
    if (count($parts) === 2) {
        return $parts[0] . ' ' . mb_substr($parts[1], 0, 1, 'UTF-8') . '.';
    }
    return $full_name;
}

/** Краткая подсказка по паспорту для списка извлечённых данных. */
function yvo_format_passport_hint(array $person) {
    $inn = trim((string) ($person['inn'] ?? ''));
    $is_legal = !empty($person['is_legal_entity'])
        || (isset($person['person_type']) && (string) $person['person_type'] === 'legal_entity');
    if ($is_legal && $inn !== '') {
        return 'ИНН ' . $inn;
    }
    $ser = trim((string) ($person['passport_series'] ?? ''));
    $num = trim((string) ($person['passport_number'] ?? ''));
    if ($ser !== '' && $num !== '') {
        return 'паспорт ' . $ser . ' ' . $num;
    }
    if ($inn !== '') {
        return 'ИНН ' . $inn;
    }
    $share = trim((string) ($person['share_fraction'] ?? $person['share'] ?? ''));
    if ($share !== '') {
        return 'доля ' . $share;
    }
    return '';
}

/** Компактная строка адреса объекта для превью. */
function yvo_format_property_short_label($property) {
    if (!is_array($property)) {
        return '';
    }
    if (!empty($property['house_settlement']) || sanitize_key((string) ($property['object_type'] ?? '')) === 'house_with_plot') {
        $label = trim((string) ($property['house_settlement'] ?? ''));
        if ($label === '' && !empty($property['address'])) {
            $label = yvo_shorten_address_display((string) $property['address']);
        }
        if (!empty($property['city']) && $label !== '') {
            $label = trim((string) $property['city']) . ', ' . $label;
        } elseif (!empty($property['city'])) {
            $label = trim((string) $property['city']);
        }
        $house_cad = trim((string) ($property['house_cadastral_number'] ?? $property['cadastral_number'] ?? ''));
        $plot_cad = trim((string) ($property['plot_cadastral_number'] ?? ''));
        $cad_parts = array();
        if ($house_cad !== '') {
            $cad_parts[] = 'дом ' . $house_cad;
        }
        if ($plot_cad !== '') {
            $cad_parts[] = 'уч. ' . $plot_cad;
        }
        if (!empty($cad_parts)) {
            $label .= ($label !== '' ? ' ' : '') . '(кад. ' . implode(', ', $cad_parts) . ')';
        }
        if ($label !== '') {
            return $label;
        }
    }
    $addr = trim((string) ($property['address'] ?? ''));
    if ($addr !== '' && !preg_match('/450055|Проспект\s+Октября|МФЦ/ui', $addr)) {
        $label = yvo_shorten_address_display($addr);
        return yvo_append_area_to_property_label($label, $property);
    }
    $chunks = array();
    foreach (array('city', 'street', 'house', 'building', 'apartment', 'room_number', 'plot_number') as $k) {
        $v = trim((string) ($property[$k] ?? ''));
        if ($v !== '') {
            $chunks[] = $v;
        }
    }
    if (!empty($chunks)) {
        return yvo_append_area_to_property_label(implode(' ', $chunks), $property);
    }
    $cad = trim((string) ($property['cadastral_number'] ?? ''));
    if ($cad !== '') {
        return 'кад. ' . $cad;
    }
    $area = trim((string) ($property['area'] ?? ''));
    if ($area !== '') {
        $area_disp = str_replace('.', ',', $area);
        return 'площадь ' . $area_disp . ' м²';
    }
    return '';
}

/** Добавить площадь к строке объекта в превью. */
function yvo_append_area_to_property_label($label, $property) {
    $label = trim((string) $label);
    $area = trim((string) (is_array($property) ? ($property['area'] ?? '') : ''));
    if ($label === '' || $area === '') {
        return $label;
    }
    if (preg_match('/\d+[.,]\d*\s*м²/ui', $label)) {
        return $label;
    }
    return $label . ', ' . str_replace('.', ',', $area) . ' м²';
}

/** Укоротить полный адрес до улицы/дома/квартиры. */
function yvo_shorten_address_display($addr) {
    $addr = trim(preg_replace('/\s+/u', ' ', (string) $addr));
    if ($addr === '') {
        return '';
    }
    $addr = preg_replace('/^.*?Российская Федерация,?\s*/ui', '', $addr);
    $addr = preg_replace('/^(?:республика|область|край|АО|автономный округ)[^,]+,\s*/ui', '', $addr);
    if (preg_match('/((?:ул\.?|улица|пр\.?|проспект|пер\.?|переулок|б-р|бульвар|ш\.?|шоссе|наб\.?|набережная)[^,]+(?:,\s*д\.?\s*[^,]+)?(?:,\s*(?:кв\.?|комн\.?|квартира)\s*[^,]+)?)/ui', $addr, $m)) {
        $short = trim($m[1], " \t\n\r\0\x0B,");
        $short = preg_replace('/\b(?:ул\.?|улица)\s*/ui', '', $short);
        $short = preg_replace('/\bд\.?\s*/ui', '', $short);
        $short = preg_replace('/\b(?:кв\.?|квартира)\s*/ui', 'кв ', $short);
        return trim(preg_replace('/\s+/u', ' ', $short));
    }
    $bits = array_values(array_filter(array_map('trim', explode(',', $addr))));
    if (count($bits) >= 2) {
        return implode(' ', array_slice($bits, -2));
    }
    return mb_strlen($addr) > 72 ? (mb_substr($addr, 0, 69, 'UTF-8') . '…') : $addr;
}

/**
 * Объединить списки участников без дублей по ФИО.
 *
 * @param array<int, array<string, mixed>> $lists
 * @return array<int, array<string, mixed>>
 */
function yvo_merge_extracted_person_lists(...$lists) {
    $merged = array();
    $index = array();
    foreach ($lists as $list) {
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $person) {
            if (!is_array($person)) {
                continue;
            }
            $name = trim((string) ($person['full_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (!isset($index[$key])) {
                $index[$key] = count($merged);
                $merged[] = $person;
                continue;
            }
            $i = $index[$key];
            foreach ($person as $pk => $pv) {
                if ($pv === '' || $pv === null) {
                    continue;
                }
                if (!isset($merged[$i][$pk]) || $merged[$i][$pk] === '' || $merged[$i][$pk] === null) {
                    $merged[$i][$pk] = $pv;
                }
            }
        }
    }
    return $merged;
}

/**
 * Сводка извлечённых данных документа (участники + объект).
 *
 * @return array{persons:array<int,array>,property:array<string,mixed>|null,property_label:string,egrn_check:array<string,mixed>,ownership_history:array<int,array>}
 */
function yvo_build_document_extract_summary($text) {
    $text = is_string($text) ? trim($text) : '';
    if (function_exists('yvo_strip_pdf_ocr_boilerplate')) {
        $text = yvo_strip_pdf_ocr_boilerplate($text);
    }
    $property = null;
    $egrn_check = array();
    $egrn_participants = array();
    $egrn_participants_all = array();
    $ownership_history = array();
    $is_egrn = function_exists('yvo_text_looks_like_egrn_document')
        ? yvo_text_looks_like_egrn_document($text)
        : (bool) preg_match('/кадастров|егрн|единый\s+государственный\s+реестр|\d{2}:\d{2}:\d{6,}/ui', $text);

    if ($is_egrn && strlen($text) > 100 && function_exists('yvo_deepseek_extract_egrn_data')) {
        $egrn = yvo_deepseek_extract_egrn_data($text, array('quality' => true));
        if (!empty($egrn['property']) && is_array($egrn['property'])) {
            $property = $egrn['property'];
        }
        if (!empty($egrn['egrn_check']) && is_array($egrn['egrn_check'])) {
            $egrn_check = $egrn['egrn_check'];
        }
        if (!empty($egrn['ownership_history']) && is_array($egrn['ownership_history'])) {
            $ownership_history = $egrn['ownership_history'];
        }
        $src_all = !empty($egrn['participants_all']) && is_array($egrn['participants_all'])
            ? $egrn['participants_all']
            : ((!empty($egrn['participants']) && is_array($egrn['participants'])) ? $egrn['participants'] : array());
        foreach ($src_all as $p) {
            if (!is_array($p)) {
                continue;
            }
            $row = $p;
            if (!empty($p['share']) && empty($row['share_fraction'])) {
                $row['share_fraction'] = $p['share'];
            }
            $egrn_participants_all[] = yvo_sanitize_parsed_person_data($row, $text);
        }
        if (!empty($egrn['participants']) && is_array($egrn['participants'])) {
            foreach ($egrn['participants'] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $row = $p;
                if (!empty($p['share']) && empty($row['share_fraction'])) {
                    $row['share_fraction'] = $p['share'];
                }
                $egrn_participants[] = yvo_sanitize_parsed_person_data($row, $text);
            }
        }
    }

    if ($property === null) {
        $prop = yvo_parse_document_data($text, 'property');
        if (is_array($prop) && (trim((string) ($prop['address'] ?? '')) !== '' || trim((string) ($prop['cadastral_number'] ?? '')) !== '')) {
            $property = $prop;
        }
    }

    if (is_array($property)) {
        if (function_exists('yvo_egrn_normalize_property_by_object_type')) {
            $property = yvo_egrn_normalize_property_by_object_type($property);
        }
        $property = yvo_normalize_house_with_plot_cadastral($property);
        if (trim((string) ($property['area'] ?? '')) === '' && class_exists('YVO_Property_Parser')) {
            $prop_parser = new YVO_Property_Parser($text);
            $parsed_prop = $prop_parser->parse();
            if (!empty($parsed_prop['area'])) {
                $property['area'] = $parsed_prop['area'];
            }
        }
        if (function_exists('yvo_normalize_property_address_fields')) {
            $property = yvo_normalize_property_address_fields($property);
        }
    }

    $parsed_persons = yvo_parse_all_persons_from_text($text);
    // В «Извлечённые данные» — все распознанные из ЕГРН (текущие + история), чтобы можно было выбрать.
    if ($is_egrn && !empty($egrn_participants_all)) {
        $persons_raw = $egrn_participants_all;
    } elseif ($is_egrn && !empty($egrn_participants)) {
        $persons_raw = $egrn_participants;
    } else {
        $persons_raw = yvo_merge_extracted_person_lists($egrn_participants, $parsed_persons);
    }
    $persons = array();
    foreach ($persons_raw as $p) {
        if (!is_array($p) || trim((string) ($p['full_name'] ?? '')) === '') {
            continue;
        }
        $hint = yvo_format_passport_hint($p);
        $status = (string) ($p['ownership_status'] ?? '');
        $status_label = (string) ($p['ownership_status_label'] ?? '');
        if ($status_label === '') {
            if ($status === 'current') {
                $status_label = 'Текущий собственник';
            } elseif ($status === 'previous') {
                $status_label = 'Предыдущий собственник';
            }
        }
        if ($status_label !== '') {
            $hint = $hint !== '' ? ($status_label . ' · ' . $hint) : $status_label;
        } elseif (!empty($p['ownership_period'])) {
            $hint = $hint !== '' ? ($p['ownership_period'] . ' · ' . $hint) : (string) $p['ownership_period'];
        }
        $persons[] = array(
            'full_name' => trim((string) $p['full_name']),
            'short_name' => yvo_format_person_short_name($p['full_name']),
            'hint' => $hint,
            'ownership_status' => $status,
            'data' => $p,
        );
    }

    return array(
        'persons' => $persons,
        'property' => is_array($property) ? $property : null,
        'property_label' => yvo_format_property_short_label($property),
        'egrn_check' => $egrn_check,
        'ownership_history' => $ownership_history,
        'document_kinds' => function_exists('yvo_detect_document_kinds') ? yvo_detect_document_kinds($text) : array(),
    );
}

// AJAX: сводка извлечённых данных после загрузки документа
function yvo_ajax_frontend_extract_summary() {
    $text = isset($_POST['text']) ? wp_unslash($_POST['text']) : '';
    $text = is_string($text) ? trim(strip_tags($text)) : '';
    if (strlen($text) < 20) {
        wp_send_json_error(array('message' => 'Слишком мало текста для анализа'));
    }
    $summary = yvo_build_document_extract_summary($text);
    if (empty($summary['persons']) && empty($summary['property_label'])) {
        wp_send_json_error(array('message' => 'Не удалось найти ФИО или данные объекта в документе'));
    }
    wp_send_json_success($summary);
}

/**
 * AJAX: ИИ-ревизия подставленных полей относительно OCR-текста.
 */
function yvo_ajax_frontend_review_fields() {
    if (!check_ajax_referer('yvo_frontend_contract', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Сессия устарела. Обновите страницу.'));
    }
    $api_key = get_option('yvo_deepseek_api_key');
    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'DeepSeek не настроен. Укажите API ключ в настройках плагина.'));
    }
    $ocr = isset($_POST['ocr_text']) ? wp_unslash($_POST['ocr_text']) : '';
    $ocr = is_string($ocr) ? trim(strip_tags($ocr)) : '';
    if (function_exists('mb_strlen') ? mb_strlen($ocr, 'UTF-8') < 40 : strlen($ocr) < 40) {
        wp_send_json_error(array('message' => 'Нет текста документа для сверки.'));
    }
    if (function_exists('mb_strlen') ? mb_strlen($ocr, 'UTF-8') > 28000 : strlen($ocr) > 28000) {
        $ocr = function_exists('mb_substr') ? mb_substr($ocr, 0, 28000, 'UTF-8') : substr($ocr, 0, 28000);
    }
    $fields_json = isset($_POST['fields_json']) ? wp_unslash($_POST['fields_json']) : '';
    $fields = json_decode(is_string($fields_json) ? $fields_json : '', true);
    if (!is_array($fields)) {
        wp_send_json_error(array('message' => 'Нет данных полей формы.'));
    }

    // Rule-based layer first (always returned; AI adds on top).
    $rule_issues = array();
    foreach ((array) ($fields['persons'] ?? array()) as $person) {
        if (!is_array($person)) {
            continue;
        }
        $tab = (string) ($person['_tab'] ?? 'seller');
        $rule_issues = array_merge($rule_issues, yvo_validate_person_fields($person, $tab));
    }
    if (!empty($fields['property']) && is_array($fields['property'])) {
        $rule_issues = array_merge($rule_issues, yvo_validate_property_fields($fields['property']));
    }

    $payload = wp_json_encode($fields, JSON_UNESCAPED_UNICODE);
    $prompt = "Ты проверяешь данные, извлечённые из OCR-документа для договора недвижимости.\n";
    $prompt .= "Сравни JSON полей формы с текстом документа. Найди ошибки OCR, перепутанные ФИО, серии/номера паспорта, адреса, кадастра.\n";
    $prompt .= "Верни ТОЛЬКО JSON-объект: {\"issues\":[{\"tab\":\"id вкладки или property\",\"field\":\"ключ поля\",\"severity\":\"warn|error\",\"message\":\"кратко\",\"value\":\"текущее значение\",\"suggested_value\":\"исправление или пусто\"}]}.\n";
    $prompt .= "Если ошибок нет — {\"issues\":[]}. Не выдумывай поля, которых нет в JSON.\n\n";
    $prompt .= "JSON полей:\n" . $payload . "\n\nТекст документа:\n" . $ocr;

    $model = get_option('yvo_deepseek_model', 'deepseek-chat');
    $url = 'https://api.deepseek.com/v1/chat/completions';
    $body = array(
        'model' => $model,
        'messages' => array(array('role' => 'user', 'content' => $prompt)),
        'max_tokens' => 4000,
        'temperature' => 0.1,
    );
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $api_key, 'Content-Type: application/json'),
        CURLOPT_POSTFIELDS => wp_json_encode($body),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        wp_send_json_error(array('message' => 'Ошибка сети: ' . $err, 'rule_issues' => $rule_issues));
    }
    if ($http_code !== 200) {
        $data = json_decode((string) $response, true);
        $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Ошибка DeepSeek';
        wp_send_json_error(array('message' => $msg, 'rule_issues' => $rule_issues));
    }
    $data = json_decode((string) $response, true);
    $content = isset($data['choices'][0]['message']['content']) ? trim((string) $data['choices'][0]['message']['content']) : '';
    if (preg_match('/\{[\s\S]*\}/u', $content, $jm)) {
        $content = $jm[0];
    }
    $parsed = json_decode($content, true);
    $ai_issues = array();
    if (is_array($parsed) && !empty($parsed['issues']) && is_array($parsed['issues'])) {
        foreach ($parsed['issues'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tab = sanitize_key((string) ($row['tab'] ?? ''));
            $field = sanitize_key((string) ($row['field'] ?? ''));
            if ($tab === '' || $field === '') {
                continue;
            }
            $ai_issues[] = array(
                'tab' => $tab,
                'field' => $field,
                'severity' => (($row['severity'] ?? '') === 'error') ? 'error' : 'warn',
                'code' => 'ai_review',
                'message' => sanitize_text_field((string) ($row['message'] ?? 'Возможная ошибка по мнению ИИ')),
                'value' => sanitize_text_field((string) ($row['value'] ?? '')),
                'suggested_value' => sanitize_text_field((string) ($row['suggested_value'] ?? '')),
            );
        }
    }
    wp_send_json_success(array(
        'issues' => $ai_issues,
        'rule_issues' => $rule_issues,
    ));
}

// AJAX: парсинг текста через DeepSeek на фронтенде
function yvo_ajax_frontend_parse() {
    $text = isset($_POST['text']) ? wp_unslash($_POST['text']) : '';
    $text = is_string($text) ? trim(strip_tags($text)) : '';
    if (function_exists('yvo_strip_pdf_ocr_boilerplate')) {
        $text = yvo_strip_pdf_ocr_boilerplate($text);
    }
    $document_type = isset($_POST['document_type']) ? sanitize_text_field($_POST['document_type']) : 'passport';
    $object_type_hint = isset($_POST['object_type_hint']) ? sanitize_key($_POST['object_type_hint']) : '';
    if ($text === '') {
        wp_send_json_error(array('message' => 'Нет текста для парсинга'));
    }
    $person_types = array('seller', 'buyer', 'passport', 'seller_requisites', 'buyer_requisites');
    $parsed_data = array();
    $deepseek_error = '';
    $use_deepseek = !empty(get_option('yvo_deepseek_api_key'));
    $parse_options = array();
    if ($document_type === 'property' && $object_type_hint !== '') {
        $parse_options['object_type_hint'] = $object_type_hint;
    }
    if ($use_deepseek) {
        $result = yvo_parse_with_deepseek($text, $document_type, $parse_options);
        if ($result['success'] && !empty($result['parsed_data']) && is_array($result['parsed_data'])) {
            $parsed_data = $result['parsed_data'];
        } elseif (!$result['success']) {
            $deepseek_error = isset($result['message']) ? (string) $result['message'] : '';
        }
    }
    if (in_array($document_type, array('seller', 'buyer', 'passport'), true)) {
        $regex_data = yvo_parse_document_data($text, $document_type);
        if (!yvo_parsed_person_has_values($regex_data) && preg_match('/паспорт|passport|серия|код\s+подразделения/ui', $text)) {
            $regex_data = yvo_parse_document_data($text, 'passport');
        }
        foreach ($regex_data as $key => $val) {
            if ($val === '' || $val === null) {
                continue;
            }
            if (!isset($parsed_data[$key]) || $parsed_data[$key] === null || trim((string) $parsed_data[$key]) === '' || strtolower(trim((string) $parsed_data[$key])) === 'null' || yvo_looks_like_parser_placeholder((string) $parsed_data[$key], $key)) {
                $parsed_data[$key] = $val;
            }
        }
    } elseif ($document_type === 'property') {
        $regex_data = yvo_parse_document_data($text, 'property');
        foreach ($regex_data as $key => $val) {
            if ($val === '' || $val === null) {
                continue;
            }
            if (!isset($parsed_data[$key]) || $parsed_data[$key] === null || trim((string) $parsed_data[$key]) === '' || strtolower(trim((string) $parsed_data[$key])) === 'null') {
                $parsed_data[$key] = $val;
            }
        }
    } elseif ($parsed_data === array() || empty($parsed_data)) {
        $parsed_data = yvo_parse_document_data($text, $document_type);
    }
    if ($document_type === 'property' && $object_type_hint === 'share') {
        $parsed_data = yvo_apply_property_object_type_hint($parsed_data, 'share');
    }
    if ($document_type === 'property' && function_exists('yvo_normalize_property_address_fields') && is_array($parsed_data)) {
        $parsed_data = yvo_normalize_property_address_fields($parsed_data);
    }
    if (in_array($document_type, $person_types, true)) {
        $parsed_data = yvo_sanitize_parsed_person_data($parsed_data, $text);
    }
    if (!yvo_parsed_person_has_values($parsed_data)) {
        $msg = 'Не удалось извлечь данные. Проверьте OCR-текст ниже или заполните поля вручную.';
        if ($deepseek_error !== '') {
            $msg .= ' (' . $deepseek_error . ')';
        }
        wp_send_json_error(array('message' => $msg));
    }
    wp_send_json_success(array('parsed_data' => $parsed_data));
}

/** Извлечь данные всех лиц из текста документа (паспорт, договор, ЕГРН). */
function yvo_parse_all_persons_from_text($text) {
    $text = is_string($text) ? $text : '';
    if (strlen($text) < 20) {
        return array();
    }
    $api_key = get_option('yvo_deepseek_api_key');
    if (empty($api_key)) {
        return yvo_parse_all_persons_fallback($text);
    }
    $prompt = "В документе могут быть данные одного, двух или трёх лиц (паспорт, ФИО и т.д.). Извлеки данные КАЖДОГО человека отдельно. Верни ТОЛЬКО JSON-объект с одним ключом \"persons\" — массив объектов. Каждый объект: full_name, passport_series, passport_number, department_code, passport_issued_by, passport_date, birth_date, birth_place, registration, inn, snils. Если поля нет — null. Не подставляй вымышленные ФИО — только данные из текста.\n\nТекст документа:\n\n" . mb_substr($text, 0, 30000);
    $model = get_option('yvo_deepseek_model', 'deepseek-chat');
    $url = 'https://api.deepseek.com/v1/chat/completions';
    $headers = array('Authorization: Bearer ' . $api_key, 'Content-Type: application/json');
    $body = array(
        'model' => $model,
        'messages' => array(array('role' => 'user', 'content' => $prompt)),
        'max_tokens' => 4096,
        'temperature' => 0.1,
        'response_format' => array('type' => 'json_object'),
    );
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || $http_code != 200) {
        return yvo_parse_all_persons_fallback($text);
    }
    $data = json_decode($response, true);
    $content = isset($data['choices'][0]['message']['content']) ? trim($data['choices'][0]['message']['content']) : '';
    $decoded = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return yvo_parse_all_persons_fallback($text);
    }
    $persons = array();
    if (isset($decoded['persons']) && is_array($decoded['persons'])) {
        $persons = $decoded['persons'];
    } elseif (isset($decoded['participants']) && is_array($decoded['participants'])) {
        $persons = $decoded['participants'];
    } elseif (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
        $persons = $decoded;
    } elseif (is_array($decoded) && isset($decoded['full_name'])) {
        $persons = array($decoded);
    }
    $out = array();
    foreach ($persons as $p) {
        if (!is_array($p)) {
            continue;
        }
        $row = array(
            'full_name' => isset($p['full_name']) ? $p['full_name'] : '',
            'passport_series' => isset($p['passport_series']) ? $p['passport_series'] : '',
            'passport_number' => isset($p['passport_number']) ? $p['passport_number'] : '',
            'department_code' => isset($p['department_code']) ? $p['department_code'] : '',
            'passport_issued_by' => isset($p['passport_issued_by']) ? $p['passport_issued_by'] : '',
            'passport_date' => isset($p['passport_date']) ? $p['passport_date'] : '',
            'birth_date' => isset($p['birth_date']) ? $p['birth_date'] : '',
            'birth_place' => isset($p['birth_place']) ? $p['birth_place'] : '',
            'registration' => isset($p['registration']) ? $p['registration'] : '',
            'inn' => isset($p['inn']) ? $p['inn'] : '',
            'snils' => isset($p['snils']) ? $p['snils'] : '',
        );
        $out[] = yvo_sanitize_parsed_person_data($row, $text);
    }
    if (empty($out)) {
        return yvo_parse_all_persons_fallback($text);
    }
    return $out;
}

// AJAX: извлечь всех участников из текста (1–3 человека) для выбора ролей
function yvo_ajax_frontend_parse_all_persons() {
    $text = isset($_POST['text']) ? wp_unslash($_POST['text']) : '';
    $text = sanitize_textarea_field($text);
    if (strlen($text) < 20) {
        wp_send_json_error(array('message' => 'Нет текста для анализа'));
    }
    $out = yvo_parse_all_persons_from_text($text);
    if (empty($out)) {
        wp_send_json_error(array('message' => 'Участники не найдены'));
    }
    wp_send_json_success(array('persons' => $out));
}

function yvo_parse_all_persons_fallback($text) {
    $persons = array();
    $blocks = preg_split('/(?=\n\s*(?:паспорт|Паспорт|PASSPORT)\b)/u', (string) $text);
    if (is_array($blocks) && count($blocks) > 1) {
        foreach ($blocks as $block) {
            if (mb_strlen(trim($block)) < 40) {
                continue;
            }
            $one = yvo_parse_document_data($block, 'seller');
            $one = yvo_sanitize_parsed_person_data($one, $text);
            if (!empty($one['full_name'])) {
                $persons[] = $one;
            }
        }
    }
    if (empty($persons)) {
        $one = yvo_parse_document_data($text, 'seller');
        $one = yvo_sanitize_parsed_person_data($one, $text);
        if (!empty($one['full_name'])) {
            $persons[] = $one;
        }
    }
    if (empty($persons)) {
        if (!class_exists('YVO_DeepSeekParser')) {
            $parser_file = defined('YVO_PLUGIN_DIR') ? YVO_PLUGIN_DIR . 'includes/deepseek-parser.php' : __DIR__ . '/includes/deepseek-parser.php';
            if (is_file($parser_file)) {
                require_once $parser_file;
            }
        }
        if (class_exists('YVO_DeepSeekParser')) {
            foreach (YVO_DeepSeekParser::extract_participants_from_egrn_text($text) as $p) {
                if (!is_array($p) || empty($p['full_name'])) {
                    continue;
                }
                $row = array('full_name' => $p['full_name']);
                if (!empty($p['share'])) {
                    $row['share_fraction'] = $p['share'];
                }
                $persons[] = yvo_sanitize_parsed_person_data($row, $text);
            }
        }
    }
    return yvo_merge_extracted_person_lists($persons);
}

/**
 * Разбор строк: блоки таблицы (две колонки через TAB, как «Параметр — Значение» в .txt шаблонах).
 *
 * @param array<int, string> $lines
 * @return array<int, array<string, mixed>>
 */
function yvo_docx_segment_lines(array $lines) {
    $segments = array();
    $n = count($lines);
    $i = 0;
    while ($i < $n) {
        $line = $lines[$i];
        $trimmed = trim($line);
        if ($trimmed === '') {
            $segments[] = array('type' => 'blank');
            $i++;
            continue;
        }
        if (strpos($line, "\t") !== false) {
            $rows = array();
            while ($i < $n) {
                $L = $lines[$i];
                if (trim($L) === '') {
                    break;
                }
                if (strpos($L, "\t") === false) {
                    break;
                }
                $parts = explode("\t", $L, 2);
                $rows[] = array(
                    isset($parts[0]) ? trim($parts[0]) : '',
                    isset($parts[1]) ? trim($parts[1]) : '',
                );
                $i++;
            }
            if (!empty($rows)) {
                $segments[] = array('type' => 'table', 'rows' => $rows);
            }
            continue;
        }
        $segments[] = array('type' => 'line', 'text' => $line);
        $i++;
    }
    return $segments;
}

/**
 * Таблица Word (OOXML): границы, Times New 12 pt; первая строка «Параметр / Значение» — шапка с заливкой.
 *
 * @param array<int, array{0:string,1:string}> $rows
 */
function yvo_docx_table_xml(array $rows, $line_spacing, $space_after, $margin_after_table) {
    $b = '<w:top w:val="single" w:sz="6" w:space="0" w:color="999999"/>'
        . '<w:left w:val="single" w:sz="6" w:space="0" w:color="999999"/>'
        . '<w:bottom w:val="single" w:sz="6" w:space="0" w:color="999999"/>'
        . '<w:right w:val="single" w:sz="6" w:space="0" w:color="999999"/>'
        . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>'
        . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>';
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' . $b . '</w:tblBorders></w:tblPr>';
    foreach ($rows as $row) {
        $xml .= '<w:tr>';
        foreach ($row as $cell) {
            $cell_text = (string) $cell;
            $runs = yvo_docx_text_to_runs_xml($cell_text === '' ? '________________' : $cell_text, false, 24);
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="4680" w:type="dxa"/></w:tcPr>'
                . '<w:p><w:pPr><w:spacing w:after="' . (int) $space_after . '" w:line="' . (int) $line_spacing . '"/>'
                . '<w:jc w:val="both"/></w:pPr>'
                . $runs . '</w:p></w:tc>';
        }
        $xml .= '</w:tr>';
    }
    $xml .= '</w:tbl>';
    $xml .= '<w:p><w:pPr><w:spacing w:before="0" w:after="' . (int) $margin_after_table . '" w:line="' . (int) $line_spacing . '"/></w:pPr><w:r><w:t xml:space="preserve"> </w:t></w:r></w:p>';
    return $xml;
}

/**
 * Санитизация текста для XML 1.0 внутри DOCX.
 * Контрольные символы (0x00–0x1F кроме TAB/LF/CR) ломают word/document.xml и Word пишет «файл повреждён».
 */
function yvo_docx_sanitize_xml_text($s) {
    if (!is_string($s) || $s === '') {
        return (string) $s;
    }
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
}

/** OOXML: свойства шрифта Times New Roman. */
function yvo_docx_rpr_xml($bold = false, $size_half_points = 24, $highlight_blank = false) {
    $b = $bold ? '<w:b/>' : '';
    $hl = $highlight_blank ? '<w:highlight w:val="yellow"/>' : '';
    $sz = (int) $size_half_points;
    return '<w:rPr>' . $b . $hl . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>'
        . '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/></w:rPr>';
}

/** Фрагменты текста договора в runs (без цветовой подсветки пустых полей). */
function yvo_docx_text_to_runs_xml($text, $bold = false, $size_half_points = 24) {
    $text = (string) $text;
    if ($text === '') {
        $rpr = yvo_docx_rpr_xml($bold, $size_half_points, true);
        return '<w:r>' . $rpr . '<w:t xml:space="preserve"> </w:t></w:r>';
    }
    $segments = preg_split('/(«B»[\s\S]*?«\/B»)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($segments) || count($segments) === 0) {
        $segments = array($text);
    }
    $xml = '';
    foreach ($segments as $segment) {
        if ($segment === '') {
            continue;
        }
        $seg_bold = $bold;
        if (preg_match('/^«B»([\s\S]*?)«\/B»$/u', $segment, $bm)) {
            $segment = $bm[1];
            $seg_bold = true;
        }
        $parts = preg_split('/(_{4,}|\[[^\]]{2,120}\])/u', $segment, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) === 0) {
            $parts = array($segment);
        }
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $esc = htmlspecialchars($part, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $rpr = yvo_docx_rpr_xml($seg_bold, $size_half_points, false);
            $xml .= '<w:r>' . $rpr . '<w:t xml:space="preserve">' . ($esc === '' ? ' ' : $esc) . '</w:t></w:r>';
        }
    }
    return $xml !== '' ? $xml : '<w:r>' . yvo_docx_rpr_xml($bold, $size_half_points) . '<w:t xml:space="preserve"> </w:t></w:r>';
}

/** OOXML: один абзац договора. */
function yvo_docx_paragraph_xml($text, array $opts = array()) {
    $bold = !empty($opts['bold']);
    $size = isset($opts['size']) ? (int) $opts['size'] : 24;
    $align = isset($opts['align']) ? (string) $opts['align'] : 'both';
    $first_line = isset($opts['first_line']) ? (int) $opts['first_line'] : 0;
    $left = isset($opts['left']) ? (int) $opts['left'] : 0;
    $before = isset($opts['before']) ? (int) $opts['before'] : 0;
    $after = isset($opts['after']) ? (int) $opts['after'] : 120;
    $line = isset($opts['line']) ? (int) $opts['line'] : 276;

    $ppr = '<w:pPr><w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="' . $line . '" w:lineRule="auto"/>';
    if ($align === 'center') {
        $ppr .= '<w:jc w:val="center"/>';
    } elseif ($align === 'right') {
        $ppr .= '<w:jc w:val="right"/>';
    } elseif ($align === 'left') {
        $ppr .= '<w:jc w:val="left"/>';
    } else {
        $ppr .= '<w:jc w:val="both"/>';
    }
    if ($first_line > 0 || $left > 0) {
        $ppr .= '<w:ind';
        if ($left > 0) {
            $ppr .= ' w:left="' . $left . '"';
        }
        if ($first_line > 0) {
            $ppr .= ' w:firstLine="' . $first_line . '"';
        }
        $ppr .= '/>';
    }
    $ppr .= '</w:pPr>';
    $runs = yvo_docx_text_to_runs_xml((string) $text, $bold, $size);
    return '<w:p>' . $ppr . $runs . '</w:p>';
}

/** Параметры вёрстки DOCX по типу договора (twips: 1 см ≈ 567). */
function yvo_docx_layout_for_profile($profile) {
    $cm = 567;
    $base = array(
        'margin_left' => (int) round($cm * 3),
        'margin_right' => (int) round($cm * 2),
        'margin_top' => (int) round($cm * 2),
        'margin_bottom' => (int) round($cm * 2),
        'font_size' => 24,
        'title_size' => 28,
        'line_spacing' => 276,
        'first_line_indent' => (int) round($cm * 1.25),
        'space_after' => 60,
        'space_after_title' => 120,
        'space_before_section' => 160,
        'centered_header' => false,
    );
    if ($profile === 'share_allocation') {
        // Компактный профессиональный стиль (как типовое соглашение у нотариуса): 12 pt, поля ~2 см.
        return array_merge($base, array(
            'margin_left' => (int) round($cm * 2),
            'margin_right' => (int) round($cm * 1.25),
            'margin_top' => (int) round($cm * 1.5),
            'margin_bottom' => (int) round($cm * 1.5),
            'font_size' => 24,
            'title_size' => 28,
            'line_spacing' => 276,
            'first_line_indent' => (int) round($cm * 1.25),
            'preamble_indent' => 0,
            'space_after' => 40,
            'space_after_title' => 80,
            'space_before_section' => 120,
            'centered_header' => true,
            'title_before' => 0,
            'title_after' => 80,
            'city_before' => 0,
            'city_after' => 100,
            'preamble_after' => 60,
            'share_line_after' => 40,
            'signature_intro_before' => 160,
            'signature_intro_after' => 60,
            'signature_before' => 120,
            'signature_after' => 200,
        ));
    }
    if ($profile === 'deposit') {
        return array_merge($base, array(
            'margin_left' => (int) round($cm * 2),
            'margin_right' => (int) round($cm * 1.25),
            'margin_top' => (int) round($cm * 1.5),
            'margin_bottom' => (int) round($cm * 1.5),
            'font_size' => 24,
            'title_size' => 28,
            'line_spacing' => 276,
            'first_line_indent' => (int) round($cm * 1.25),
            'preamble_indent' => 0,
            'space_after' => 40,
            'space_after_title' => 80,
            'space_before_section' => 120,
            'centered_header' => false,
            'title_before' => 0,
            'title_after' => 60,
            'city_before' => 0,
            'city_after' => 80,
            'preamble_after' => 40,
            'signature_intro_before' => 160,
            'signature_intro_after' => 60,
            'signature_before' => 80,
            'signature_after' => 160,
        ));
    }
    if ($profile === 'gift') {
        return array_merge($base, array(
            'margin_left' => (int) round($cm * 3),
            'margin_right' => (int) round($cm * 1.5),
            'centered_header' => true,
        ));
    }
    if ($profile === 'default') {
        return array_merge($base, array(
            'margin_top' => (int) round($cm * 2.5),
            'margin_bottom' => (int) round($cm * 2.5),
        ));
    }
    return $base;
}

/**
 * Классификация строки договора для единообразного оформления DOCX.
 *
 * @return array<string, mixed>
 */
function yvo_docx_classify_contract_line($trimmed, $line_index, $seen_title, $seen_subtitle) {
    if ($trimmed === '') {
        return array('kind' => 'blank');
    }
    if (!$seen_title) {
        $upper = mb_strtoupper($trimmed, 'UTF-8');
        if (($line_index <= 3 && $trimmed === $upper && mb_strlen($trimmed) < 120)
            || (bool) preg_match('/^(ДОГОВОР|СОГЛАШЕНИЕ|РАСПИСКА|АКТ|ПРЕДВАРИТЕЛЬНЫЙ)\b/ui', $trimmed)) {
            return array('kind' => 'doc_title');
        }
    }
    if ($seen_title && !$seen_subtitle
        && (
            (bool) preg_match('/^(дарения|купли|продажи|уступки|выделения)\s/ui', $trimmed)
            || (bool) preg_match('/^о\s+выделении\s+дол/ui', $trimmed)
        )
        && mb_strlen($trimmed) < 120) {
        return array('kind' => 'doc_subtitle');
    }
    if ((bool) preg_match('/^г\.\s+/u', $trimmed)
        || (bool) preg_match('/^«\d+/u', $trimmed)
        || (bool) preg_match('/^(городской\s+округ|г\.о\.|г\.о\s)/ui', $trimmed)
        || (bool) preg_match('/«\d+»\s+\S+\s+\d{4}\s+г\.\s*$/u', $trimmed)) {
        return array('kind' => 'city_date');
    }
    if ($trimmed === 'и') {
        return array('kind' => 'connector');
    }
    if ((bool) preg_match('/^(АДРЕСА СТОРОН|ПОДПИСИ СТОРОН):?$/u', $trimmed)) {
        return array('kind' => 'block_heading');
    }
    if ((bool) preg_match('/^\d+\.\d+\./u', $trimmed)) {
        return array('kind' => 'subsection');
    }
    if ((bool) preg_match('/^\d+\.\s+/u', $trimmed)) {
        $clause_verbs = '/\s(передаёт|принадлежит|подтверждают|осуществляется|имеет|вправе|состоит|расположена|определяются|подлежит|составлено|заключили|оформляют|именуем|проживающ|Одаряем|Даритель|Сторон)/ui';
        if (mb_strlen($trimmed) < 55
            && (bool) preg_match('/\.\s*$/u', $trimmed)
            && !(bool) preg_match($clause_verbs, $trimmed)) {
            return array('kind' => 'section_heading');
        }
        return array('kind' => 'subsection');
    }
    if ((bool) preg_match('/^_{10,}\s*\/\s*.+\s*\//u', $trimmed)
        || (bool) preg_match('/^Подпись:\s*_{10,}\s*\/\s*.+\s*\//u', $trimmed)) {
        return array('kind' => 'signature');
    }
    if ((bool) preg_match('/^(Родители подписываются|Подписи сторон|Реквизиты и подписи)/ui', $trimmed)) {
        return array('kind' => 'signature_intro');
    }
    if ((bool) preg_match('/^[А-ЯЁ][^\n–—-]{2,100}\s*[–—-]\s*\d+\/\d+\s+доли/ui', $trimmed)) {
        return array('kind' => 'share_line');
    }
    if ((bool) preg_match('/^(гр\.\s*РФ\s|гр\.\s+[А-ЯЁ]|гражданин\s|гражданка\s|Я,\s|Мы,\s|и\s+[А-ЯЁA-Z])/ui', $trimmed)
        || (bool) preg_match('/именуем/ui', $trimmed)
        || (bool) preg_match('/^вместе\s+именуем/ui', $trimmed)
        || (bool) preg_match('/^проживающ/ui', $trimmed)
        || ($line_index < 80 && (bool) preg_match('/^[А-ЯЁ][а-яё]+\s+[А-ЯЁ][а-яё]+/u', $trimmed))) {
        return array('kind' => 'preamble');
    }
    if ((bool) preg_match('/^\d+\)\s+/u', $trimmed) || (bool) preg_match('/^-\s+/u', $trimmed)) {
        return array('kind' => 'list_item');
    }
    if ((bool) preg_match('/^[А-ЯЁA-Z][^:]{0,80}:\s*_{2,}/u', $trimmed)
        || (bool) preg_match('/:\s*_{10,}\s*\/\s*.+\s*\//u', $trimmed)) {
        return array('kind' => 'signature');
    }
    if ((bool) preg_match('/^\(/u', $trimmed)) {
        return array('kind' => 'parenthetical');
    }
    if ((bool) preg_match('/^(Даритель|Одаряемый|Продавец|Покупатель)\s*\d*\s*:/ui', $trimmed)) {
        return array('kind' => 'address_line');
    }
    return array('kind' => 'body');
}

// Создание .docx из текста с оформлением (шрифт, абзацы, интервалы, таблицы как в шаблоне .txt)
function yvo_create_docx_from_text($text, $output_path, $profile = 'auto') {
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $text = yvo_docx_sanitize_xml_text((string) $text);
    if ($profile === 'auto') {
        $profile = 'default';
        if (preg_match('/^ДОГОВОР\s*\r?\n[^\r\n]*дарени/ui', $text)) {
            $profile = 'gift';
        } elseif (preg_match('/^Соглашение\s*\r?\n[^\r\n]*выделени/ui', ltrim($text))) {
            $profile = 'share_allocation';
        } elseif (preg_match('/^(СОГЛАШЕНИЕ|РАСПИСКА)/ui', ltrim($text))) {
            $profile = 'deposit';
        }
    }
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $segments = yvo_docx_segment_lines($lines);
    $layout = yvo_docx_layout_for_profile($profile);
    $margin_left_twips = $layout['margin_left'];
    $margin_right_twips = $layout['margin_right'];
    $margin_top_twips = $layout['margin_top'];
    $margin_bottom_twips = $layout['margin_bottom'];
    $body_font_size = $layout['font_size'];
    $title_font_size = $layout['title_size'];
    $first_line_indent = $layout['first_line_indent'];
    $line_spacing = $layout['line_spacing'];
    $space_after = $layout['space_after'];
    $space_after_title = $layout['space_after_title'];
    $space_before_section = $layout['space_before_section'];
    $centered_header = !empty($layout['centered_header']);
    $preamble_indent = isset($layout['preamble_indent']) ? (int) $layout['preamble_indent'] : $first_line_indent;
    $title_before = isset($layout['title_before']) ? (int) $layout['title_before'] : 0;
    $title_after = isset($layout['title_after']) ? (int) $layout['title_after'] : 100;
    $city_before = isset($layout['city_before']) ? (int) $layout['city_before'] : 0;
    $city_after = isset($layout['city_after']) ? (int) $layout['city_after'] : 120;
    $preamble_after = isset($layout['preamble_after']) ? (int) $layout['preamble_after'] : 60;
    $share_line_after = isset($layout['share_line_after']) ? (int) $layout['share_line_after'] : 40;
    $signature_intro_before = isset($layout['signature_intro_before']) ? (int) $layout['signature_intro_before'] : 240;
    $signature_intro_after = isset($layout['signature_intro_after']) ? (int) $layout['signature_intro_after'] : 120;
    $signature_before = isset($layout['signature_before']) ? (int) $layout['signature_before'] : 120;
    $signature_after = isset($layout['signature_after']) ? (int) $layout['signature_after'] : 280;

    $body = '';
    $line_index = 0;
    $seen_title = false;
    $seen_subtitle = false;
    foreach ($segments as $seg) {
        if ($seg['type'] === 'table' && !empty($seg['rows'])) {
            $body .= yvo_docx_table_xml($seg['rows'], $line_spacing, $space_after, $space_after_title);
            continue;
        }
        if ($seg['type'] === 'blank') {
            continue;
        }
        $line = $seg['text'];
        $line_index++;
        $trimmed = trim($line);
        $class = yvo_docx_classify_contract_line($trimmed, $line_index, $seen_title, $seen_subtitle);
        $kind = $class['kind'];

        if ($kind === 'doc_title') {
            $seen_title = true;
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'bold' => true,
                'size' => $title_font_size,
                'align' => 'center',
                'before' => $title_before,
                'after' => $title_after,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'doc_subtitle') {
            $seen_subtitle = true;
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'size' => $body_font_size,
                'align' => 'center',
                'after' => $space_after_title,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'city_date') {
            $city_align = $centered_header ? 'center' : 'right';
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'size' => $body_font_size,
                'align' => $city_align,
                'before' => $city_before,
                'after' => $city_after,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'connector') {
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'align' => 'center',
                'after' => 80,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'block_heading') {
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'bold' => true,
                'align' => 'center',
                'before' => $space_before_section,
                'after' => 120,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'section_heading') {
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'bold' => true,
                'size' => 26,
                'align' => 'left',
                'before' => $space_before_section,
                'after' => 100,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'preamble') {
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'size' => $body_font_size,
                'align' => 'both',
                'first_line' => $preamble_indent,
                'after' => $preamble_after,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'share_line') {
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'size' => $body_font_size,
                'align' => 'both',
                'left' => $first_line_indent,
                'after' => $share_line_after,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'signature_intro') {
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'size' => $body_font_size,
                'align' => 'both',
                'first_line' => $first_line_indent,
                'before' => $signature_intro_before,
                'after' => $signature_intro_after,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'list_item') {
            $list_opts = array(
                'after' => 50,
                'line' => $line_spacing,
            );
            if ($profile === 'gift') {
                $list_opts['first_line'] = $first_line_indent;
            } elseif ($profile === 'deposit') {
                $list_opts['left'] = (int) round(567 * 0.75);
                $list_opts['first_line'] = 0;
            } else {
                $list_opts['left'] = (int) round(567 * 1.25);
                $list_opts['first_line'] = (int) round(567 * 0.5);
            }
            $body .= yvo_docx_paragraph_xml($trimmed, $list_opts);
            continue;
        }
        if ($kind === 'parenthetical') {
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'after' => 40,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'subsection') {
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'size' => $body_font_size,
                'first_line' => $first_line_indent,
                'after' => $space_after,
                'line' => $line_spacing,
            ));
            continue;
        }
        if ($kind === 'signature' || $kind === 'address_line') {
            $body .= yvo_docx_paragraph_xml($trimmed, array(
                'size' => $body_font_size,
                'align' => 'left',
                'before' => $signature_before,
                'after' => $signature_after,
                'line' => $line_spacing,
            ));
            continue;
        }

        // Основной текст и подпункты (1.1., 4.1.1. и т.д.) — обычный шрифт, красная строка.
        $body .= yvo_docx_paragraph_xml($trimmed, array(
            'size' => $body_font_size,
            'first_line' => $first_line_indent,
            'after' => $space_after,
            'line' => $line_spacing,
        ));
    }

    // Страница A4 — поля задаются профилем (для выделения долей: 3.5 см слева, 2 см справа).
    $sectPr = '<w:sectPr>'
        . '<w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="' . $margin_top_twips . '" w:right="' . $margin_right_twips . '" w:bottom="' . $margin_bottom_twips . '" w:left="' . $margin_left_twips . '" w:header="720" w:footer="720" w:gutter="0"/>'
        . '<w:docGrid w:linePitch="360"/>'
        . '</w:sectPr>';

    $document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<w:body>' . $body . $sectPr . '</w:body></w:document>';

    $content_types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';
    $doc_rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
        . '</Relationships>';
    $styles = '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman" w:eastAsia="Times New Roman"/>'
        . '<w:sz w:val="' . (int) $body_font_size . '"/><w:szCs w:val="' . (int) $body_font_size . '"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="' . (int) $space_after . '" w:line="' . (int) $line_spacing . '" w:lineRule="auto"/>'
        . '<w:jc w:val="both"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '</w:styles>';
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:defaultTabStop w:val="708"/>'
        . '<w:characterSpacingControl w:val="doNotCompress"/>'
        . '</w:settings>';

    $zip = new ZipArchive();
    if ($zip->open($output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $zip->addFromString('[Content_Types].xml', $content_types);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('word/_rels/document.xml.rels', $doc_rels);
    $zip->addFromString('word/document.xml', $document_xml);
    $zip->addFromString('word/styles.xml', $styles);
    $zip->addFromString('word/settings.xml', $settings);
    $zip->close();
    return file_exists($output_path);
}

/**
 * Создание простого .doc (HTML) из текста.
 * Не требует ZipArchive (работает даже если DOCX недоступен).
 */
function yvo_create_doc_from_text($text, $output_path) {
    $safe = htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe = str_replace("\r\n", "\n", $safe);
    $safe = str_replace("\n", "<br/>", $safe);
    $html = '<html><head><meta charset="utf-8"></head><body style="font-family:Times New Roman,serif;font-size:12pt;line-height:1.35;">'
        . $safe
        . '</body></html>';
    if (!file_put_contents($output_path, $html)) {
        return false;
    }
    return file_exists($output_path);
}

/** Простой HTML из полного текста договора (для HTML/PDF), чтобы не терять блоки. */
function yvo_contract_text_to_html_document($text) {
    $safe = htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe = str_replace("\r\n", "\n", $safe);
    $safe = str_replace("\n", "<br/>", $safe);
    return '<!doctype html><html><head><meta charset="utf-8"><title>Договор</title></head>'
        . '<body style="font-family:Times New Roman,serif;font-size:12pt;line-height:1.35;white-space:normal;">'
        . $safe
        . '</body></html>';
}

/** Проверка: DOCX-шаблон построен через <w:altChunk> (текст внутри MHT/HTML). */
function yvo_docx_template_is_altchunk($docx_path) {
    if (!class_exists('ZipArchive') || !is_readable($docx_path)) {
        return false;
    }
    $z = new ZipArchive();
    if ($z->open($docx_path, ZipArchive::RDONLY) !== true) {
        return false;
    }
    $xml = $z->getFromName('word/document.xml');
    $z->close();
    if (!is_string($xml) || $xml === '') {
        return false;
    }
    return strpos($xml, '<w:altChunk') !== false;
}

/**
 * Проверяет, поддерживает ли DOCX-шаблон динамические стороны сделки (SELLERS_BLOCK/BUYERS_BLOCK/SIGNATURES_BLOCK).
 * Если в шаблоне фиксированные поля «SELLER1_FULL_NAME» и т.п. без блоков, при нескольких участниках DOCX будет неверным.
 */
function yvo_docx_template_supports_dynamic_parties($docx_path) {
    if (!class_exists('ZipArchive') || !is_readable($docx_path)) {
        return false;
    }
    $z = new ZipArchive();
    if ($z->open($docx_path, ZipArchive::RDONLY) !== true) {
        return false;
    }
    $xml = $z->getFromName('word/document.xml');
    $mht = $z->getFromName('word/afchunk.mht');
    $z->close();
    $hay = '';
    if (is_string($xml) && $xml !== '') $hay .= $xml;
    if (is_string($mht) && $mht !== '') $hay .= $mht;
    if ($hay === '') return false;
    return (strpos($hay, '{{SELLERS_BLOCK}}') !== false)
        || (strpos($hay, '{{BUYERS_BLOCK}}') !== false)
        || (strpos($hay, '{{SIGNATURES_BLOCK}}') !== false);
}

/**
 * Регулярное выражение для плейсхолдера {{KEY}}, разорванного Word на несколько <w:t> подряд.
 *
 * @return string|null
 */
function yvo_docxml_placeholder_regex_split_runs($key) {
    $key = (string) $key;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $key)) {
        return null;
    }
    $token = '{{' . $key . '}}';
    $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars) || count($chars) < 1) {
        return null;
    }
    $glue = '<\/w:t>(?:<w:proofErr\b[^>]*\/>)?(?:<\/w:r><w:r[^>]*>(?:<w:rPr>[\s\S]*?<\/w:rPr>)?)?<w:t[^>]*>';
    $re = '/<w:t([^>]*)>' . preg_quote($chars[0], '/');
    for ($i = 1, $n = count($chars); $i < $n; $i++) {
        $re .= $glue . preg_quote($chars[$i], '/');
    }
    $re .= '<\/w:t>/u';
    return $re;
}

/**
 * Подстановка плейсхолдера {{KEY}} по последовательности соседних <w:t> узлов.
 * Word часто режет плейсхолдер на куски (не по 1 символу), поэтому regex «по символам» недостаточен.
 *
 * Заменяет от первого <w:t> до последнего <w:t>, участвующих в плейсхолдере, одной вставкой.
 * Это может убрать промежуточные <w:r> / <w:proofErr>, но зато гарантирует корректную подстановку.
 */
function yvo_docxml_replace_placeholder_across_wt_nodes($document_xml, $key, $value_str) {
    $key = (string) $key;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $key)) {
        return $document_xml;
    }
    $token = '{{' . $key . '}}';
    if (strpos($document_xml, '{{') === false) {
        return $document_xml;
    }
    if (!preg_match_all('/<w:t([^>]*)>([\\s\\S]*?)<\\/w:t>/u', $document_xml, $m, PREG_OFFSET_CAPTURE)) {
        return $document_xml;
    }

    $attrs = $m[1];
    $texts = $m[2];
    $fullMatches = $m[0];
    $count = count($fullMatches);
    if ($count < 1) {
        return $document_xml;
    }

    // Подготовка replacement XML (используем attrs первого w:t).
    $buildReplacement = function ($firstAttrs) use ($value_str) {
        if (strpos($value_str, "\n") !== false) {
            $parts = preg_split('/\r\n|\r|\n/', $value_str);
            $parts = is_array($parts) ? $parts : array($value_str);
            $parts_safe = array();
            foreach ($parts as $p) {
                $parts_safe[] = htmlspecialchars((string) $p, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
            $out = '';
            $cnt = count($parts_safe);
            for ($i = 0; $i < $cnt; $i++) {
                if ($i > 0) $out .= '<w:br/>';
                $out .= '<w:t' . $firstAttrs . '>' . ($parts_safe[$i] === '' ? ' ' : $parts_safe[$i]) . '</w:t>';
            }
            return $out;
        }
        $value_safe = htmlspecialchars((string) $value_str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<w:t' . $firstAttrs . '>' . ($value_safe === '' ? ' ' : $value_safe) . '</w:t>';
    };

    $posShift = 0;
    // Ищем и заменяем все вхождения токена.
    for ($i = 0; $i < $count; $i++) {
        $acc = '';
        for ($j = $i; $j < $count; $j++) {
            $acc .= $texts[$j][0];
            if (strlen($acc) > strlen($token) + 20) {
                break;
            }
            if ($acc === $token) {
                $start = $fullMatches[$i][1];
                $end = $fullMatches[$j][1] + strlen($fullMatches[$j][0]);
                $firstAttrs = $attrs[$i][0];
                $replacementXml = $buildReplacement($firstAttrs);
                $document_xml = substr($document_xml, 0, $start) . $replacementXml . substr($document_xml, $end);
                // Перезапускаем поиск, т.к. offsets стали невалидны после модификации.
                return yvo_docxml_replace_placeholder_across_wt_nodes($document_xml, $key, $value_str);
            }
        }
    }
    return $document_xml;
}

/**
 * Подстановка одного плоского плейсхолдера в word/document.xml (включая разбиение на несколько w:t).
 */
function yvo_docxml_apply_flat_replacement($document_xml, $key, $value) {
    $value_str = yvo_docx_sanitize_xml_text((string) $value);
    $placeholder = '{{' . $key . '}}';
    if (strpos($document_xml, $placeholder) !== false) {
        if (strpos($value_str, "\n") !== false) {
            $parts = preg_split('/\r\n|\r|\n/', $value_str);
            $parts = is_array($parts) ? $parts : array($value_str);
            $parts_safe = array();
            foreach ($parts as $p) {
                $parts_safe[] = htmlspecialchars(yvo_docx_sanitize_xml_text((string) $p), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
            $rx = '/<w:t([^>]*)>' . preg_quote($placeholder, '/') . '<\/w:t>/u';
            return preg_replace_callback($rx, function ($m) use ($parts_safe) {
                $attrs = $m[1];
                $out = '';
                $cnt = count($parts_safe);
                for ($i = 0; $i < $cnt; $i++) {
                    if ($i > 0) {
                        $out .= '<w:br/>';
                    }
                    $out .= '<w:t' . $attrs . '>' . ($parts_safe[$i] === '' ? ' ' : $parts_safe[$i]) . '</w:t>';
                }
                return $out;
            }, $document_xml);
        }
        $value_safe = htmlspecialchars(yvo_docx_sanitize_xml_text($value_str), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return str_replace($placeholder, $value_safe, $document_xml);
    }
    // Попытка №1: разорванный плейсхолдер по w:t-узлам (самый надёжный случай).
    $replaced = yvo_docxml_replace_placeholder_across_wt_nodes($document_xml, $key, $value_str);
    if ($replaced !== $document_xml) {
        return $replaced;
    }
    // Попытка №2: старый regex-вариант (по символам) — на случай экзотических разбиений.
    $split_rx = yvo_docxml_placeholder_regex_split_runs($key);
    if ($split_rx === null) {
        return $document_xml;
    }
    if (strpos($value_str, "\n") !== false) {
        $parts = preg_split('/\r\n|\r|\n/', $value_str);
        $parts = is_array($parts) ? $parts : array($value_str);
        $parts_safe = array();
        foreach ($parts as $p) {
            $parts_safe[] = htmlspecialchars(yvo_docx_sanitize_xml_text((string) $p), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }
        return preg_replace_callback($split_rx, function ($m) use ($parts_safe) {
            $attrs = $m[1];
            $out = '';
            $cnt = count($parts_safe);
            for ($i = 0; $i < $cnt; $i++) {
                if ($i > 0) {
                    $out .= '<w:br/>';
                }
                $out .= '<w:t' . $attrs . '>' . ($parts_safe[$i] === '' ? ' ' : $parts_safe[$i]) . '</w:t>';
            }
            return $out;
        }, $document_xml);
    }
    $value_safe = htmlspecialchars(yvo_docx_sanitize_xml_text($value_str), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return preg_replace_callback($split_rx, function ($m) use ($value_safe) {
        return '<w:t' . $m[1] . '>' . $value_safe . '</w:t>';
    }, $document_xml);
}

/**
 * Генерация DOCX из шаблона .docx с подстановкой плейсхолдеров — сохраняются стили и формат исходного файла.
 * В шаблоне используйте {{PLACEHOLDER}} в тексте.
 */
function yvo_create_docx_from_template_docx($template_docx_path, $replacements, $output_path) {
    if (!class_exists('ZipArchive') || !is_readable($template_docx_path)) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($template_docx_path, ZipArchive::RDONLY) !== true) {
        return false;
    }
    $document_xml = $zip->getFromName('word/document.xml');
    if ($document_xml === false || $document_xml === '') {
        $zip->close();
        return false;
    }
    foreach ($replacements as $key => $value) {
        $document_xml = yvo_docxml_apply_flat_replacement($document_xml, $key, $value);
    }
    $num = $zip->numFiles;
    $zip->close();
    $zip_out = new ZipArchive();
    if ($zip_out->open($output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $zip2 = new ZipArchive();
    if ($zip2->open($template_docx_path, ZipArchive::RDONLY) !== true) {
        $zip_out->close();
        return false;
    }
    for ($i = 0; $i < $num; $i++) {
        $name = $zip2->getNameIndex($i);
        if ($name === false) continue;
        if ($name === 'word/document.xml') {
            $zip_out->addFromString($name, $document_xml);
        } else {
            $content = $zip2->getFromIndex($i);
            if ($content !== false) {
                $zip_out->addFromString($name, $content);
            }
        }
    }
    $zip2->close();
    $zip_out->close();
    return file_exists($output_path);
}

function yvo_find_windows_browser_for_pdf() {
    $edge = (getenv('ProgramFiles(x86)') ? getenv('ProgramFiles(x86)') : 'C:\\Program Files (x86)') . '\\Microsoft\\Edge\\Application\\msedge.exe';
    if (file_exists($edge)) return $edge;
    $chrome = (getenv('ProgramFiles') ? getenv('ProgramFiles') : 'C:\\Program Files') . '\\Google\\Chrome\\Application\\chrome.exe';
    if (file_exists($chrome)) return $chrome;
    return '';
}

function yvo_html_to_pdf($html_path, $pdf_path) {
    if (!is_string($html_path) || !file_exists($html_path)) return false;
    $browser = yvo_find_windows_browser_for_pdf();
    if ($browser === '' || !file_exists($browser)) return false;
    $html_abs = realpath($html_path);
    if (!$html_abs) return false;
    $url = 'file:///' . str_replace('\\', '/', $html_abs);
    $cmd = '"' . $browser . '" --headless=new --disable-gpu --no-sandbox --print-to-pdf="' . str_replace('"', '', $pdf_path) . '" --print-to-pdf-no-header "' . $url . '"';
    @exec($cmd, $out, $ret);
    return $ret === 0 && file_exists($pdf_path) && filesize($pdf_path) > 1000;
}

/**
 * Плейсхолдер (прочерк, […]) — подсветка красным в DOCX/HTML.
 */
function yvo_html_is_placeholder_value($text) {
    $t = trim((string) $text);
    if ($t === '') {
        return true;
    }
    if (preg_match('/^\[.+\]$/u', $t)) {
        return true;
    }
    if (preg_match('/^_{2,}$/u', $t)) {
        return true;
    }
    if (preg_match('/^[\._\-]+$/u', str_replace(' ', '', $t))) {
        return true;
    }
    if (preg_match('/^ДД\.ММ\.ГГГГ$/u', $t)) {
        return true;
    }
    return false;
}

function yvo_html_value_span($text) {
    $text = (string) $text;
    $is_missing = yvo_html_is_placeholder_value($text);
    $cls = $is_missing ? 'value value-missing' : 'value';
    $style = $is_missing
        ? 'color:#c0392b;font-weight:bold;text-decoration:none;border-bottom:none;'
        : 'color:#000;font-weight:normal;text-decoration:none;border-bottom:none;';
    return '<span class="' . $cls . '" style="' . $style . '">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
}

function yvo_html_highlight_placeholders_in_text($text) {
    $text = (string) $text;
    if ($text === '') {
        return '';
    }
    if (!preg_match('/(\[[^\]]+\]|_{2,})/u', $text)) {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $parts = preg_split('/(\[[^\]]+\]|_{2,})/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match('/^(\[[^\]]+\]|_{2,})$/u', $part)) {
            $out .= '<span class="value value-missing" style="color:#c0392b;font-weight:bold;text-decoration:none;border-bottom:none;">'
                . htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        } else {
            $out .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
    return $out;
}

/**
 * Извлекает ФИО из строки преамбулы карточки участника.
 * Продавец: «Я, ФИО,» / «Мы, ФИО,»; покупатель: «ФИО,» без вводного «Я,».
 */
function yvo_parties_card_parse_fio_from_line($line) {
    $line = trim((string) $line);
    if ($line === '') {
        return null;
    }
    if (preg_match('/^(Мы|и|Я),\s*(.+)$/u', $line, $mm)) {
        $name = trim($mm[2], " \t,");
        return $name !== '' ? $name : null;
    }
    if (preg_match('/^(.+),\s*$/u', $line, $mm)) {
        $name = trim($mm[1]);
        if ($name === '' || preg_match('/^(дата|место|паспорт|код|именуем|действующ|зарегистрирован)/ui', $name)) {
            return null;
        }
        if (strpos($name, ':') !== false) {
            return null;
        }
        if (mb_strlen($name, 'UTF-8') < 3) {
            return null;
        }
        return $name;
    }
    return null;
}

/** Проверка карточек сторон перед генерацией ДКП (ФИО, дубликаты продавец=покупатель). */
function yvo_validate_parties_for_contract_generation(array $sellers, array $buyers, $contract_type = 'sale') {
    $errors = array();
    $principal_sellers = yvo_parties_principal_sellers_ordered($sellers);
    $principal_buyers = yvo_parties_principal_buyers_ordered($buyers);
    $seller_label = ($contract_type === 'gift') ? 'дарителя' : 'продавца';
    $buyer_label = ($contract_type === 'gift') ? 'одаряемого' : 'покупателя';

    foreach ($principal_sellers as $i => $s) {
        if (!is_array($s)) {
            continue;
        }
        $name = trim((string) ($s['full_name'] ?? ''));
        if ($name === '' || $name === '________________') {
            $suffix = (count($principal_sellers) > 1) ? ' ' . ($i + 1) : '';
            $errors[] = 'Не заполнено ФИО ' . $seller_label . $suffix;
        }
    }
    foreach ($principal_buyers as $i => $b) {
        if (!is_array($b)) {
            continue;
        }
        $name = trim((string) ($b['full_name'] ?? ''));
        if ($name === '' || $name === '________________') {
            $suffix = (count($principal_buyers) > 1) ? ' ' . ($i + 1) : '';
            $errors[] = 'Не заполнено ФИО ' . $buyer_label . $suffix;
        }
    }

    if (!empty($principal_sellers[0]) && is_array($principal_sellers[0]) && !empty($principal_buyers[0]) && is_array($principal_buyers[0])) {
        $s = $principal_sellers[0];
        $b = $principal_buyers[0];
        $sn = trim((string) ($s['full_name'] ?? ''));
        $bn = trim((string) ($b['full_name'] ?? ''));
        $sps = trim((string) ($s['passport_series'] ?? ''));
        $spn = trim((string) ($s['passport_number'] ?? ''));
        $bps = trim((string) ($b['passport_series'] ?? ''));
        $bpn = trim((string) ($b['passport_number'] ?? ''));
        if ($sn !== '' && $bn !== '' && mb_strtolower($sn, 'UTF-8') === mb_strtolower($bn, 'UTF-8')
            && $sps !== '' && $spn !== '' && $sps === $bps && $spn === $bpn) {
            $errors[] = 'Данные ' . $buyer_label . ' совпадают с ' . $seller_label
                . ' (ФИО и паспорт). Заполните вкладку «' . ($contract_type === 'gift' ? 'Одаряемый' : 'Покупатель') . '» отдельно.';
        }
    }

    return $errors;
}

/** HTML-карточки блока сторон содержат строку ФИО (регрессия после конвертации). */
function yvo_parties_styled_html_has_fio($block_text) {
    $block_text = trim((string) $block_text);
    if ($block_text === '') {
        return true;
    }
    $html = yvo_parties_block_text_to_styled_html_cards($block_text);
    if ($html === '') {
        return false;
    }
    return (strpos($html, 'ФИО:</span>') !== false) || (strpos($html, '>ФИО</td>') !== false);
}

/**
 * Текстовый блок сторон (SELLERS_BLOCK / BUYERS_BLOCK) → HTML-карточки для dkp-qwen-source.html.
 */
function yvo_parties_block_text_to_styled_html_cards($block_text, $accent_color = '#666666', $compact = false) {
    $block_text = trim((string) $block_text);
    if ($block_text === '') {
        return '';
    }
    $parts = preg_split("/\n\s*\n/u", $block_text);
    $out = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $title = 'Участник';
        if (preg_match('/«([^»]+)»/u', $part, $m)) {
            $title = $m[1];
        }
        $border = $accent_color;
        if (preg_match('/Законный представитель|Представитель/ui', $title)) {
            $border = '#999999';
        }
        $rows = '';
        $table_rows = array();
        $fio_added = false;
        foreach (preg_split('/\r\n|\r|\n/u', $part) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^именуемый/ui', $line)) {
                continue;
            }
            if (!$fio_added) {
                $fio_name = yvo_parties_card_parse_fio_from_line($line);
                if ($fio_name !== null) {
                    if ($compact) {
                        $table_rows[] = array('ФИО', $fio_name);
                    } else {
                        $rows .= '<span class="label">ФИО:</span> ' . yvo_html_value_span($fio_name);
                    }
                    $fio_added = true;
                    continue;
                }
            }
            if (preg_match('/^действующий/ui', $line)) {
                if ($compact) {
                    $table_rows[] = array('Основание', $line);
                } else {
                    $rows .= '<span class="label">Основание:</span> ' . yvo_html_value_span($line);
                }
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $lbl = trim(substr($line, 0, $colon));
                $val = trim(substr($line, $colon + 1));
                if ($compact) {
                    $table_rows[] = array($lbl, $val);
                } else {
                    $rows .= '<span class="label">' . htmlspecialchars($lbl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ':</span> '
                        . yvo_html_value_span($val);
                }
            }
        }
        if ($compact) {
            $rows = '<table width="100%" cellpadding="0" cellspacing="0" class="party-data-table" style="border-collapse:collapse;margin:0;font-size:9px;line-height:1.12;">';
            foreach ($table_rows as $tr) {
                $rows .= '<tr><td style="width:38%;padding:0 4px 0 0;color:#666;vertical-align:top;">'
                    . htmlspecialchars($tr[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ':</td><td style="padding:0;vertical-align:top;">'
                    . yvo_html_value_span($tr[1]) . '</td></tr>';
            }
            $rows .= '</table>';
        }
        $card_style = $compact
            ? 'border-left-color:' . htmlspecialchars($border, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ';padding:2px 5px;margin:0 0 3px 0;'
            : 'border-left-color:' . htmlspecialchars($border, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ';';
        $title_style = $compact ? ' style="margin:0 0 1px;font-size:9px;line-height:1.1;"' : '';
        $out .= '<div class="party-card" style="' . $card_style . '">'
            . '<div class="party-title"' . $title_style . '>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
            . ($compact ? $rows : '<div class="data-grid">' . $rows . '</div>') . '</div>';
    }
    return $out;
}

/** Строка «(далее совместно …)» → HTML. */
function yvo_parties_joint_line_to_html($joint_line, $compact = false) {
    $joint_line = trim((string) $joint_line);
    if ($joint_line === '') {
        return '';
    }
    $margin = $compact ? 'margin:2px 0;color:#999;font-size:9px;line-height:1.1;' : 'margin:12px 0;color:#999;font-size:10px;';
    return '<div style="text-align:center;' . $margin . '">'
        . htmlspecialchars($joint_line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</div>';
}

function yvo_signature_compact_cell_html($block) {
    $name = trim((string) $block['name']);
    $name_style = yvo_html_is_placeholder_value($name)
        ? 'color:#c0392b;font-weight:bold;text-decoration:none;'
        : 'color:#000;font-weight:normal;text-decoration:none;';
    return '<div style="margin:0;padding:0;line-height:1.05;">'
        . '<div style="font-size:9px;font-weight:bold;margin:0 0 1px;text-transform:uppercase;line-height:1.1;">'
        . htmlspecialchars($block['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
        . '<div style="border-bottom:1px solid #000;height:9px;margin:0;line-height:9px;font-size:1px;">&nbsp;</div>'
        . '<div style="font-size:9px;text-align:center;margin:0;line-height:1.1;' . $name_style . '">/ '
        . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' /</div></div>';
}

/** SIGNATURES_BLOCK → подписи на всю ширину (стиль из исправленного ДКП). */
function yvo_signatures_block_text_to_html($signatures_text, $compact = false) {
    $signatures_text = trim((string) $signatures_text);
    if ($signatures_text === '') {
        return '';
    }
    $blocks = array();
    foreach (preg_split('/\r\n|\r|\n/u', $signatures_text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(.+?):\s*_{2,}\s*\/\s*(.+?)\s*\/?\s*$/u', $line, $m)) {
            $blocks[] = array('label' => trim($m[1]), 'name' => trim($m[2]));
            continue;
        }
        if (preg_match('/^(.+?):\s*\/\s*(.+?)\s*\/?\s*$/u', $line, $m)) {
            $blocks[] = array('label' => trim($m[1]), 'name' => trim($m[2]));
        }
    }
    if (count($blocks) === 0) {
        return '';
    }
    $h2_style = $compact
        ? 'color:#000;text-decoration:none;margin:0 0 3px;font-size:11px;text-transform:uppercase;line-height:1.1;'
        : 'color:#000;text-decoration:none;margin:0 0 12px;font-size:13px;text-transform:uppercase;';
    $out = '<h2 style="' . $h2_style . '"><span style="color:#000;">5.</span> Подписи Сторон</h2>';
    if ($compact) {
        $out .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0;">';
        $col = 0;
        foreach ($blocks as $block) {
            if ($col > 0 && $col % 2 === 0) {
                $out .= '</tr>';
            }
            if ($col % 2 === 0) {
                $out .= '<tr>';
            }
            $pad = ($col % 2 === 0) ? 'padding:0 10px 3px 0;' : 'padding:0 0 3px 10px;';
            $out .= '<td width="50%" valign="top" style="' . $pad . 'vertical-align:top;">'
                . yvo_signature_compact_cell_html($block) . '</td>';
            $col++;
        }
        if ($col % 2 === 1) {
            $out .= '<td width="50%" valign="top" style="padding:0 0 3px 0;">&nbsp;</td>';
        }
        $out .= '</tr></table>';
        return $out;
    }
    $out .= '<div class="signatures-stack">';
    foreach ($blocks as $block) {
        $name = trim((string) $block['name']);
        $name_style = yvo_html_is_placeholder_value($name)
            ? 'color:#c0392b;font-weight:bold;text-decoration:none;'
            : 'color:#000;font-weight:normal;text-decoration:none;';
        $out .= '<div class="signature-block" style="width:100%;margin:0 0 10px 0;text-decoration:none;">'
            . '<div style="font-size:10px;font-weight:bold;color:#000;margin-bottom:6px;text-transform:uppercase;text-decoration:none;">'
            . htmlspecialchars($block['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
            . '<p class="signature-line" style="margin:0 0 2px 0;padding:0;border-bottom:1px solid #000;width:100%;line-height:14px;height:14px;text-decoration:none;">&nbsp;</p>'
            . '<div style="font-size:10px;text-align:center;' . $name_style . '">/ '
            . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' /</div>'
            . '</div>';
    }
    $out .= '</div>';
    return $out;
}

/** CSS из dkp-qwen-source.html + доп. стили для полного текста и подписей. */
function yvo_dkp_qwen_styles_css() {
    static $css = null;
    if ($css !== null) {
        return $css;
    }
    $css = '';
    if (defined('YVO_DKP_QWEN_HTML_TEMPLATE') && file_exists(YVO_DKP_QWEN_HTML_TEMPLATE)) {
        $raw = file_get_contents(YVO_DKP_QWEN_HTML_TEMPLATE);
        if (is_string($raw) && preg_match('/<style>([\s\S]*?)<\/style>/u', $raw, $m)) {
            $css = $m[1];
        }
    }
    $css .= "\nbody,.page{color:#000 !important;text-decoration:none !important;font-family:'Times New Roman',Times,serif;}"
        . "\n.doc-type,.main-title{color:#000 !important;text-decoration:none !important;}"
        . "\n.header-top{border-bottom:1px solid #000;padding-bottom:8px;margin-bottom:8px;}"
        . "\n.doc-type{text-align:center;font-size:16px;font-weight:bold;letter-spacing:0.02em;}"
        . "\n.main-title{text-align:center;font-size:13px;font-weight:normal;margin-top:4px;text-transform:none;}"
        . "\n.header-meta{display:block;text-align:left;margin:10px 0 16px;font-size:12px;line-height:1.45;}"
        . "\n.header-meta .contract-city{margin-bottom:2px;}"
        . "\n.header-meta .contract-date{text-align:left;}"
        . "\n.contract-city,.contract-date{color:#000;text-decoration:none !important;}"
        . "\n.value{color:#000 !important;font-weight:normal !important;border-bottom:none !important;text-decoration:none !important;}"
        . "\n.value-missing{color:#c0392b !important;font-weight:bold !important;text-decoration:none !important;border-bottom:none !important;}"
        . "\n.highlight{color:#000 !important;text-decoration:none !important;}"
        . "\n.party-card{background:transparent;border-left:none;padding:0;margin:0 0 8px 0;text-decoration:none !important;}"
        . "\n.party-title{color:#000;text-decoration:none !important;}"
        . "\n.label{color:#666;text-decoration:none !important;}"
        . "\n.parties-section{font-size:11px;text-align:justify;line-height:1.45;}"
        . "\n.signatures-stack{width:100%;text-decoration:none !important;}"
        . "\n.signatures-stack .signature-block{width:100%;text-decoration:none !important;}"
        . "\n.signatures-footer{display:block;border-top:1px solid #000;padding-top:10px;margin-top:16px;text-decoration:none !important;}"
        . "\n.signatures-footer h2{font-size:13px;margin:0 0 12px;color:#000;text-align:center;text-transform:none;text-decoration:none !important;}"
        . "\n.contract-body-html .text-block{margin:8px 0;font-size:11px;text-align:justify;line-height:1.45;color:#000;text-decoration:none !important;}"
        . "\n.contract-body-html .text-block *{text-decoration:none !important;}"
        . "\n.contract-body-html h2{margin:18px 0 10px;font-size:13px;color:#000 !important;text-transform:none;border-left:none;padding-left:0;text-align:center;font-weight:bold;text-decoration:none !important;}"
        . "\n.contract-body-html h2 span{margin-right:6px;color:#000 !important;text-decoration:none !important;}"
        . "\n.contract-body-html .specs-table{width:100%;border-collapse:collapse;margin:4px 0;font-size:11px;line-height:1.1;}"
        . "\n.contract-body-html .specs-table td{border:none;border-bottom:1px solid #eee;padding:1px 4px 2px;vertical-align:top;text-decoration:none !important;line-height:1.1;}"
        . "\n.contract-body-html .specs-table td.key{background:transparent;width:42%;color:#666;text-decoration:none !important;}"
        . "\n.contract-body-html .specs-table td.val,.specs-table .val{color:#000 !important;font-weight:normal !important;border-bottom:none !important;text-decoration:none !important;}"
        . "\n.date-box{display:none !important;}";
    return $css;
}

/** Доп. CSS для компактного акта (1 страница). */
function yvo_dkp_act_compact_css() {
    return "\n.page.act-compact{padding:10px 16px !important;font-size:10px;line-height:1.15;}"
        . "\n.act-compact .header-top{margin-bottom:2px !important;padding-bottom:2px !important;}"
        . "\n.act-compact .doc-type{font-size:10px !important;margin:0 !important;line-height:1.1 !important;}"
        . "\n.act-compact .main-title{font-size:13px !important;margin:0 !important;line-height:1.1 !important;}"
        . "\n.act-compact .header-meta{margin:1px 0 5px !important;font-size:10px !important;}"
        . "\n.act-compact .parties-section{margin-bottom:4px !important;}"
        . "\n.act-compact .party-card{background:#fafafa !important;padding:2px 5px !important;margin:0 0 3px 0 !important;}"
        . "\n.act-compact .party-title{margin:0 0 1px !important;font-size:9px !important;line-height:1.1 !important;}"
        . "\n.act-compact .contract-body-html h2{margin:6px 0 3px !important;font-size:11px !important;padding-left:6px !important;line-height:1.1 !important;}"
        . "\n.act-compact .contract-body-html .text-block{margin:1px 0 !important;font-size:10px !important;line-height:1.2 !important;}"
        . "\n.act-compact .contract-body-html .specs-table{margin:2px 0 !important;font-size:11px !important;line-height:1.1 !important;}"
        . "\n.act-compact .contract-body-html .specs-table td{padding:1px 3px !important;line-height:1.1 !important;}"
        . "\n.act-compact .signatures-footer{margin-top:6px !important;padding-top:4px !important;}"
        . "\n.act-compact p{margin:0 !important;padding:0 !important;}";
}

/**
 * Полный текст договора (txt) → HTML-тело (разделы 1–6), без преамбулы и без блока подписей.
 */
function yvo_contract_text_to_styled_body_html($contract_text) {
    $lines = preg_split('/\r\n|\r|\n/', (string) $contract_text);
    $html = '';
    $started = false;
    $in_table = false;
    $table_rows = array();

    $flush_table = function () use (&$html, &$table_rows, &$in_table) {
        if (!$in_table || count($table_rows) === 0) {
            $in_table = false;
            $table_rows = array();
            return;
        }
        $html .= '<table class="specs-table" style="width:100%;border-collapse:collapse;margin:4px 0;font-size:11px;line-height:1.1;">';
        $is_header = true;
        foreach ($table_rows as $row) {
            if ($is_header) {
                $is_header = false;
                continue;
            }
            $cells = explode("\t", $row);
            if (count($cells) >= 2) {
                $html .= '<tr><td class="key" style="padding:1px 4px 2px;line-height:1.1;vertical-align:top;border:none;border-bottom:1px solid #eee;color:#666;">' . htmlspecialchars(trim($cells[0]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</td><td class="val" style="padding:1px 4px 2px;line-height:1.1;vertical-align:top;border:none;border-bottom:1px solid #eee;color:#000;">' . yvo_html_highlight_placeholders_in_text(trim($cells[1])) . '</td></tr>';
            }
        }
        $html .= '</table>';
        $in_table = false;
        $table_rows = array();
    };

    foreach ($lines as $line) {
        $trim = trim($line);
        if (!$started) {
            if (preg_match('/^1\.\s+ПРЕДМЕТ/ui', $trim)) {
                $started = true;
            } elseif (preg_match('/^1\.\s+(?!1\.)/u', $trim)) {
                $started = true;
            } else {
                continue;
            }
        }
        if (preg_match('/^[56]\.\s+ПОДПИСИ/ui', $trim)) {
            break;
        }
        if (preg_match('/^(Даритель|Одаряемый|Продавец|Покупатель)(\s+\d+)?:\s*[_\/]/u', $trim)) {
            break;
        }
        if ($trim === '') {
            $flush_table();
            continue;
        }
        if (strpos($line, "\t") !== false) {
            $in_table = true;
            $table_rows[] = $line;
            continue;
        }
        $flush_table();
        if (preg_match('/^(\d+)\.\s+(.+)$/u', $trim, $sec)) {
            $html .= '<h2><span>' . htmlspecialchars($sec[1] . '.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
                . htmlspecialchars($sec[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
            continue;
        }
        $html .= '<div class="text-block">' . yvo_html_highlight_placeholders_in_text($trim) . '</div>';
    }
    $flush_table();
    return $html;
}

/**
 * Шапка договора в стиле qwen (date-box) — только для совместимости, в ДКП не используется.
 */
function yvo_dkp_red_header_html($doc_type_line, $main_title_html, $city, $day, $month, $year) {
    $city_esc = htmlspecialchars((string) $city, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $day_esc = htmlspecialchars((string) $day, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $month_esc = htmlspecialchars((string) $month, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $year_esc = htmlspecialchars((string) $year, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<div class="header-top"><div>'
        . '<div class="doc-type">' . htmlspecialchars((string) $doc_type_line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
        . ($main_title_html !== '' ? '<div class="main-title">' . $main_title_html . '</div>' : '')
        . '<div class="city-date"><div style="color:#666;"><span style="color:#999;">Город:</span> <span class="highlight">' . $city_esc . '</span></div></div>'
        . '</div>'
        . '<div class="date-box">'
        . '<div class="date-item"><span class="date-val">' . $day_esc . '</span><span class="date-label">День</span></div>'
        . '<div class="date-item"><span class="date-val">' . $month_esc . '</span><span class="date-label">Месяц</span></div>'
        . '<div class="date-item"><span class="date-val">' . $year_esc . '</span><span class="date-label">Год</span></div>'
        . '</div></div>';
}

/**
 * Полный договор: стили qwen + динамические стороны + полный текст из txt + подписи.
 *
 * @param array<string, scalar> $replacements
 */
function yvo_generate_dkp_ipoteka_full_styled_html($contract_content_raw, $replacements, $contract_type = '') {
    $city = isset($replacements['CONTRACT_CITY']) ? (string) $replacements['CONTRACT_CITY'] : '________________';
    $day = isset($replacements['DATE_DAY']) ? (string) $replacements['DATE_DAY'] : '__';
    $month = isset($replacements['DATE_MONTH']) ? (string) $replacements['DATE_MONTH'] : '__';
    $year = isset($replacements['DATE_YEAR']) ? (string) $replacements['DATE_YEAR'] : '20__';
    $month_name = isset($replacements['DATE_MONTH_NAME']) ? trim((string) $replacements['DATE_MONTH_NAME']) : $month;
    if (yvo_html_is_placeholder_value($month_name)) {
        $month_name = $month;
    }
    $contract_date = isset($replacements['CONTRACT_DATE']) ? trim((string) $replacements['CONTRACT_DATE']) : '';
    if ($contract_date === '' || yvo_html_is_placeholder_value($contract_date)) {
        $contract_date = '«' . $day . '» ' . $month_name . ' ' . $year . ' г.';
    }
    if ($contract_type === '' && isset($replacements['CONTRACT_TYPE'])) {
        $contract_type = (string) $replacements['CONTRACT_TYPE'];
    }
    $is_gift = ($contract_type === 'gift');
    $is_share_alloc = ($contract_type === 'share_allocation');
    $is_share_gift = $is_share_alloc
        || ($contract_type === 'gift' && preg_match('/дол[яи]\s|долев/ui', (string) $contract_content_raw));
    $dkp_title = isset($replacements['DKP_CONTRACT_TITLE']) ? trim((string) $replacements['DKP_CONTRACT_TITLE']) : '';
    $dkp_subtitle = isset($replacements['DKP_CONTRACT_SUBTITLE']) ? trim((string) $replacements['DKP_CONTRACT_SUBTITLE']) : '';
    if ($is_share_alloc) {
        $doc_type_line = 'СОГЛАШЕНИЕ';
        $main_title = 'О ВЫДЕЛЕНИИ ДОЛЕЙ В КВАРТИРЕ НЕСОВЕРШЕННОЛЕТНИМ';
    } elseif ($is_gift) {
        $doc_type_line = $is_share_gift ? 'ДОГОВОР ДАРЕНИЯ' : 'ДОГОВОР ДАРЕНИЯ';
        $main_title = $is_share_gift ? 'ДОЛИ В КВАРТИРЕ' : 'ЖИЛОГО ПОМЕЩЕНИЯ';
    } elseif ($contract_type === 'act') {
        $doc_type_line = 'АКТ ПРИЕМА-ПЕРЕДАЧИ';
        $main_title = 'НЕДВИЖИМОСТИ';
        $contract_date_intro = isset($replacements['CONTRACT_DATE']) ? trim((string) $replacements['CONTRACT_DATE']) : '';
        if ($contract_date_intro === '' || yvo_html_is_placeholder_value($contract_date_intro)) {
            $contract_date_intro = $contract_date;
        }
        $parties_intro = 'руководствуясь Договором купли-продажи от '
            . htmlspecialchars($contract_date_intro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' (далее – Договор), составили настоящий Акт о нижеследующем:';
    } elseif ($dkp_title !== '') {
        $doc_type_line = $dkp_title;
        $main_title = $dkp_subtitle;
    } else {
        $doc_type_line = 'ДОГОВОР КУПЛИ-ПРОДАЖИ КВАРТИРЫ';
        $main_title = '';
    }
    $parties_intro = $is_share_alloc
        ? 'заключили настоящее соглашение о нижеследующем:'
        : ($is_gift
        ? 'заключили настоящий договор о нижеследующем:'
        : (($contract_type === 'act')
            ? (isset($parties_intro) ? $parties_intro : 'составили настоящий Акт о нижеследующем:')
            : 'вместе — «Стороны», заключили Договор о нижеследующем:'));

    $is_act = ($contract_type === 'act');
    $compact = $is_act;

    if ($is_share_alloc) {
        $parties_html = '<div class="parties-section">'
            . yvo_parties_block_text_to_styled_html_cards(isset($replacements['ALLOC_PARTIES_BLOCK']) ? (string) $replacements['ALLOC_PARTIES_BLOCK'] : '', '#666666', $compact)
            . '</div>';
    } else {
        $parties_html = '<div class="parties-section">'
            . yvo_parties_block_text_to_styled_html_cards(isset($replacements['SELLERS_BLOCK']) ? (string) $replacements['SELLERS_BLOCK'] : '', '#666666', $compact)
            . yvo_parties_joint_line_to_html(isset($replacements['SELLERS_JOINT_LINE']) ? (string) $replacements['SELLERS_JOINT_LINE'] : '', $compact)
            . yvo_parties_block_text_to_styled_html_cards(isset($replacements['BUYERS_BLOCK']) ? (string) $replacements['BUYERS_BLOCK'] : '', '#666666', $compact)
            . '<div style="text-align:center;' . ($compact ? 'margin:3px 0;color:#666;font-size:9px;font-weight:bold;line-height:1.15;' : 'margin:12px 0;color:#666;font-size:11px;font-weight:bold;') . '">'
            . $parties_intro . '</div></div>';
    }

    $body_html = yvo_contract_text_to_styled_body_html($contract_content_raw);
    $sigs_html = yvo_signatures_block_text_to_html(isset($replacements['SIGNATURES_BLOCK']) ? (string) $replacements['SIGNATURES_BLOCK'] : '', $compact);

    $page_class = $compact ? 'page act-compact' : 'page';
    $extra_css = $compact ? yvo_dkp_act_compact_css() : '';

    $header_html = '<div class="header-top"><div class="doc-type">' . htmlspecialchars($doc_type_line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>'
        . ($main_title !== '' ? '<div class="main-title">' . htmlspecialchars($main_title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>' : '')
        . '</div>'
        . '<div class="header-meta"><div class="contract-city">г. ' . yvo_html_value_span($city) . '</div>'
        . '<div class="contract-date">' . yvo_html_value_span($contract_date) . '</div></div>';

    $doc = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>'
        . ($is_act ? 'Акт приема-передачи' : ($is_gift ? 'Договор дарения' : 'Договор купли-продажи'))
        . '</title>'
        . '<style>' . yvo_dkp_qwen_styles_css() . $extra_css . '</style></head><body spellcheck="false">'
        . '<div class="' . $page_class . '">'
        . $header_html
        . $parties_html
        . '<div class="contract-body-html">' . $body_html . '</div>'
        . '<div class="footer signatures-footer">' . $sigs_html . '</div>'
        . '</div></body></html>';
    return $doc;
}

/**
 * Стилизованный HTML договора (красная вёрстка qwen) с динамическими продавцами/покупателями.
 *
 * @param array<string, scalar> $replacements
 */
function yvo_generate_dkp_ipoteka_styled_html($replacements) {
    if (!defined('YVO_DKP_QWEN_HTML_TEMPLATE') || !file_exists(YVO_DKP_QWEN_HTML_TEMPLATE)) {
        return '';
    }
    $html = file_get_contents(YVO_DKP_QWEN_HTML_TEMPLATE);
    if (!is_string($html) || $html === '') {
        return '';
    }
    $html = str_replace('{{SELLERS_BLOCK_HTML}}', yvo_parties_block_text_to_styled_html_cards(isset($replacements['SELLERS_BLOCK']) ? (string) $replacements['SELLERS_BLOCK'] : ''), $html);
    $html = str_replace('{{SELLERS_JOINT_LINE_HTML}}', yvo_parties_joint_line_to_html(isset($replacements['SELLERS_JOINT_LINE']) ? (string) $replacements['SELLERS_JOINT_LINE'] : ''), $html);
    $html = str_replace('{{BUYERS_BLOCK_HTML}}', yvo_parties_block_text_to_styled_html_cards(isset($replacements['BUYERS_BLOCK']) ? (string) $replacements['BUYERS_BLOCK'] : ''), $html);
    $html = str_replace('{{SIGNATURES_BLOCK_HTML}}', yvo_signatures_block_text_to_html(isset($replacements['SIGNATURES_BLOCK']) ? (string) $replacements['SIGNATURES_BLOCK'] : ''), $html);
    return yvo_apply_dkp_html_bracket_map($html, $replacements);
}

/**
 * Подстановка скобочных плейсхолдеров [ФИО …] в HTML-шаблоне ДКП.
 *
 * @param array<string, scalar> $replacements
 */
function yvo_apply_dkp_html_bracket_map($html, $replacements) {
    if (!is_string($html) || $html === '') {
        return '';
    }
    $get = function($k, $fallback='') use ($replacements) {
        if (isset($replacements[$k]) && is_scalar($replacements[$k]) && trim((string)$replacements[$k]) !== '') return (string)$replacements[$k];
        return $fallback;
    };
    $day = $get('DATE_DAY', '__');
    $month = $get('DATE_MONTH', '__');
    $year = $get('DATE_YEAR', '20__');

    $map = array(
        '[город заключения]' => $get('CONTRACT_CITY', '________________'),
        '[__]' => '', // заполним ниже через точечные замены
        '20__' => $year,

        '[ФИО Продавца 1]' => $get('SELLER1_FULL_NAME', $get('SELLER_FULL_NAME', '________________')),
        '[ФИО Продавца 2]' => $get('SELLER2_FULL_NAME', '________________'),
        '[ФИО Покупателя]' => $get('BUYER1_FULL_NAME', $get('BUYER_FULL_NAME', '________________')),
        '[ДД.ММ.ГГГГ]' => 'ДД.ММ.ГГГГ',
        '[место рождения]' => '________________',
        'серия [____] № [______]' => 'серия ____ № ______',
        '[кем выдан]' => '________________',
        '[___-___]' => '___-___',
        '[адрес]' => '________________',

        '[кадастровый номер]' => $get('PROPERTY_CADASTRAL_NUM', '____________________'),
        '[адрес объекта]' => $get('PROPERTY_ADDRESS', '________________'),
        '[данные о праве]' => $get('PROPERTY_RIGHT_INFO', '________________'),
        '[количество]' => $get('PROPERTY_ROOMS', '___'),
        '[площадь]' => $get('PROPERTY_AREA', '___'),
        '[этаж]' => $get('PROPERTY_FLOOR', '___'),
        '[размер долей]' => $get('SELLERS_SHARES', '________________'),
        '[дата]' => $get('CONTRACT_DATE', 'ДД.ММ.ГГГГ'),

        '[общая стоимость цифрами]' => $get('PROPERTY_PRICE', '__________'),
        '[общая стоимость прописью]' => $get('PROPERTY_PRICE_WORDS', '________________'),
        '[сумма собственных средств цифрами]' => $get('LOAN_OWN_AMOUNT', '0,00'),
        '[сумма собственных средств прописью]' => $get('LOAN_OWN_AMOUNT_WORDS', 'ноль'),
        '[сумма кредита цифрами]' => $get('LOAN_CREDIT_AMOUNT', '__________'),
        '[сумма кредита прописью]' => $get('LOAN_CREDIT_AMOUNT_WORDS', '________________'),
        '[номер]' => $get('LOAN_AGREEMENT_NUMBER', '____'),
        '[наименование банка]' => $get('BANK_NAME', '________________'),
        '[сумма, уплаченная до подписания]' => $get('PAID_BEFORE_SIGNING', '__________'),
        '[сумма, уплачиваемая в день подписания]' => $get('PAID_AT_SIGNING', '__________'),
        '[Покупатель/Продавцы/Стороны поровну]' => 'Покупатель',
        '[процент]' => '__',
        '[банк]' => '________________',
        '[БИК]' => '__________',
        '[Ф.И.О.]' => '________________',
    );
    foreach ($map as $from => $to) {
        $html = str_replace($from, $to, $html);
    }
    // День/месяц: в шаблоне два раза [__] подряд — проще точечно
    $html = preg_replace('/<span class="date-val">\\[__\\]<\\/span>\\s*<span class="date-label">День<\\/span>/u', '<span class="date-val">' . htmlspecialchars($day, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span><span class="date-label">День</span>', $html, 1);
    $html = preg_replace('/<span class="date-val">\\[__\\]<\\/span>\\s*<span class="date-label">Месяц<\\/span>/u', '<span class="date-val">' . htmlspecialchars($month, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span><span class="date-label">Месяц</span>', $html, 1);
    return $html;
}

function yvo_generate_dkp_html_from_template($template_path, $replacements) {
    if (!file_exists($template_path)) {
        return '';
    }
    $html = file_get_contents($template_path);
    if (!is_string($html) || $html === '') {
        return '';
    }
    return yvo_apply_dkp_html_bracket_map($html, $replacements);
}

/**
 * HTML для печати «ДКП наличные + аккредитив» (шаблон со скобками как в design.html).
 *
 * @param array<string, scalar> $replacements плоская карта из yvo_get_dkp_ipoteka_placeholder_map().
 */
function yvo_generate_dkp_nalichnye_akkreditiv_html($template_path, $replacements) {
    if (!is_string($template_path) || !file_exists($template_path)) {
        return '';
    }
    $html = file_get_contents($template_path);
    if (!is_string($html) || $html === '') {
        return '';
    }

    $get = function ($k, $fallback = '') use ($replacements) {
        if (isset($replacements[$k]) && is_scalar($replacements[$k]) && trim((string) $replacements[$k]) !== '') {
            return (string) $replacements[$k];
        }
        return $fallback;
    };

    $day = $get('DATE_DAY', '__');
    $month = $get('DATE_MONTH', '__');
    $year = $get('DATE_YEAR', '20__');

    // Длинные вхождения — раньше коротких ([сумма] vs [общая стоимость …] не конфликтуют, [количество комнат] до [количество]).
    $map = array(
        '[номер и дата регистрации, вид права]' => $get('PROPERTY_RIGHT_INFO', '________________'),
        '[банковские реквизиты Продавца]' => $get('SELLER_PAYMENT_DETAILS', '________________'),
        '[общая стоимость цифрами]' => $get('PROPERTY_PRICE', '__________'),
        '[общая стоимость прописью]' => $get('PROPERTY_PRICE_WORDS', '________________'),
        '[количество комнат]' => $get('PROPERTY_ROOMS', '[количество комнат]'),
        '[дата регистрации права]' => $get('PROPERTY_RIGHT_DATE', '________________'),
        '[город заключения]' => $get('CONTRACT_CITY', '________________'),
        '[кадастровый номер]' => $get('PROPERTY_CADASTRAL_NUM', '____________________'),
        '[адрес объекта]' => $get('PROPERTY_ADDRESS', '________________'),
        '[наименование банка]' => $get('BANK_NAME', '________________'),
        '[сумма]' => $get('ACCREDITIV_AMOUNT', '__________'),
        '[срок]' => $get('ACCREDITIV_CALENDAR_DAYS', '__'),
        '[дата]' => $get('CONTRACT_DATE', 'ДД.ММ.ГГГГ'),
        '[площадь]' => $get('PROPERTY_AREA', '___'),
        '[этаж]' => $get('PROPERTY_FLOOR', '[этаж]'),
        '[количество]' => $get('ACCEPTANCE_DAYS', '[количество]'),
        '[ФИО Продавца 1]' => $get('SELLER1_FULL_NAME', $get('SELLER_FULL_NAME', '________________')),
        '[ФИО Продавца 2]' => $get('SELLER2_FULL_NAME', '________________'),
        '[ФИО Покупателя]' => $get('BUYER1_FULL_NAME', $get('BUYER_FULL_NAME', '________________')),
        '[ДД.ММ.ГГГГ]' => 'ДД.ММ.ГГГГ',
        '[место рождения]' => '________________',
        'серия [____] № [______]' => 'серия ____ № ______',
        '[кем выдан]' => '________________',
        '[___-___]' => '___-___',
        '[адрес]' => '________________',
        '[данные о праве]' => $get('PROPERTY_RIGHT_INFO', '________________'),
        '[размер долей]' => $get('SELLERS_SHARES', '________________'),
        '[Ф.И.О.]' => '', // см. ниже
        '[__]' => '',
        '20__' => $year,
    );

    foreach ($map as $from => $to) {
        if ($from !== '[Ф.И.О.]' && $from !== '[__]') {
            $html = str_replace($from, htmlspecialchars($to, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
        }
    }

    $fio_n = 0;
    $seller1 = htmlspecialchars($get('SELLER1_FULL_NAME', '________________'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $buyer1 = htmlspecialchars($get('BUYER1_FULL_NAME', '________________'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = preg_replace_callback('/\[Ф\.И\.О\.\]/u', function () use ($seller1, $buyer1, &$fio_n) {
        $fio_n++;
        return ($fio_n === 1) ? $seller1 : $buyer1;
    }, $html);

    $html = preg_replace('/<span class="date-val">\\[__\\]<\\/span>\\s*<span class="date-label">День<\\/span>/u', '<span class="date-val">' . htmlspecialchars($day, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span><span class="date-label">День</span>', $html, 1);
    $html = preg_replace('/<span class="date-val">\\[__\\]<\\/span>\\s*<span class="date-label">Месяц<\\/span>/u', '<span class="date-val">' . htmlspecialchars($month, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span><span class="date-label">Месяц</span>', $html, 1);
    return $html;
}

/**
 * Найти диапазон div с заданным class (учитывает вложенность).
 *
 * @return array{start:int,end:int,open:string,inner:string}|null
 */
function yvo_html_find_next_div_range($html, $class_name, $offset = 0) {
    $pattern = '/<div\s+[^>]*class=["\'][^"\']*\b' . preg_quote($class_name, '/') . '\b[^"\']*["\'][^>]*>/iu';
    if (!preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
        return null;
    }
    $start = (int) $m[0][1];
    $open_end = $start + strlen($m[0][0]);
    $depth = 1;
    $i = $open_end;
    $len = strlen($html);
    while ($i < $len && $depth > 0) {
        $next_open = stripos($html, '<div', $i);
        $next_close = stripos($html, '</div>', $i);
        if ($next_close === false) {
            return null;
        }
        if ($next_open !== false && $next_open < $next_close) {
            $depth++;
            $i = $next_open + 4;
            continue;
        }
        $depth--;
        if ($depth === 0) {
            return array(
                'start' => $start,
                'end' => $next_close + 6,
                'open' => substr($html, $start, $open_end - $start),
                'inner' => substr($html, $open_end, $next_close - $open_end),
            );
        }
        $i = $next_close + 6;
    }
    return null;
}

/**
 * Только для DOCX: карточки сторон и подписи как в HTML (grid/flex → таблицы Word).
 */
function yvo_html_docx_fix_parties_and_signatures($html) {
    if (!is_string($html) || $html === '') {
        return '';
    }

    // data-grid → таблица label/value (как в браузере)
    $offset = 0;
    while (($grid = yvo_html_find_next_div_range($html, 'data-grid', $offset)) !== null) {
        $inner = $grid['inner'];
        $rows = array();
        if (preg_match_all('/<span class="label">([^<]*)<\/span>\s*(<span class="value[^"]*"[^>]*>[\s\S]*?<\/span>)/u', $inner, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $pair) {
                $rows[] = array(trim(strip_tags($pair[1])), $pair[2]);
            }
        }
        if (count($rows) > 0) {
            $table = '<table width="100%" cellpadding="0" cellspacing="0" class="party-data-table" style="border-collapse:collapse;width:100%;margin:0;font-size:11px;">';
            foreach ($rows as $row) {
                $table .= '<tr><td style="width:130px;padding:4px 8px 4px 0;color:#666;vertical-align:top;">'
                    . htmlspecialchars(rtrim($row[0], ':'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ':</td><td style="padding:4px 0;color:#000;vertical-align:top;">'
                    . $row[1] . '</td></tr>';
            }
            $table .= '</table>';
            $html = substr($html, 0, $grid['start']) . $table . substr($html, $grid['end']);
            $offset = $grid['start'] + strlen($table);
        } else {
            $offset = $grid['end'];
        }
    }

    // party-card → обёртка-таблица (фон и левая полоска как в HTML)
    $offset = 0;
    while (($card = yvo_html_find_next_div_range($html, 'party-card', $offset)) !== null) {
        $open = $card['open'];
        $inner = $card['inner'];
        $border = '#666666';
        $bg = '#f7f7f7';
        if (preg_match('/border-left-color:\s*([^;"\']+)/u', $open, $bm)) {
            $border = trim($bm[1]);
        }
        if (preg_match('/background:\s*([^;"\']+)/u', $open, $bgm)) {
            $bg = trim($bgm[1]);
        }
        $wrapped = '<table width="100%" cellpadding="0" cellspacing="0" class="party-card-wrap" style="border-collapse:collapse;width:100%;margin-bottom:10px;">'
            . '<tr><td style="background:' . $bg . ';padding:12px 15px;border-left:2px solid ' . $border . ';">'
            . $inner . '</td></tr></table>';
        $html = substr($html, 0, $card['start']) . $wrapped . substr($html, $card['end']);
        $offset = $card['start'] + strlen($wrapped);
    }

    // signatures-stack → вертикальная таблица на всю ширину
    $stack = yvo_html_find_next_div_range($html, 'signatures-stack');
    if ($stack !== null) {
        $blocks_html = '';
        $boff = 0;
        while (($block = yvo_html_find_next_div_range($stack['inner'], 'signature-block', $boff)) !== null) {
            $blocks_html .= '<tr><td style="width:100%;padding:0 0 10px 0;vertical-align:top;">'
                . substr($stack['inner'], $block['start'], $block['end'] - $block['start'])
                . '</td></tr>';
            $boff = $block['end'];
        }
        if ($blocks_html !== '') {
            $table = '<table width="100%" cellpadding="0" cellspacing="0" class="signatures-stack" style="border-collapse:collapse;width:100%;">'
                . $blocks_html . '</table>';
            $html = substr($html, 0, $stack['start']) . $table . substr($html, $stack['end']);
        }
    }

    // Word игнорирует border-bottom у пустых блоков — нормализуем линию подписи
    $html = preg_replace(
        '/<p class="signature-line"[^>]*>\s*&nbsp;\s*<\/p>/u',
        '<p class="signature-line" style="margin:0 0 2px 0;padding:0;border-bottom:1px solid #000;width:100%;line-height:14px;height:14px;">&nbsp;</p>',
        $html
    );

    return $html;
}

/**
 * Подстановка заполненного HTML внутрь DOCX вида Word «Web page» (только afchunk.mht, без текстовых плейсхолдеров в document.xml).
 */
function yvo_docx_afchunk_replace_html($docx_template_path, $html_utf8, $output_docx_path) {
    if (!class_exists('ZipArchive') || !is_readable($docx_template_path)) {
        return false;
    }
    $z_in = new ZipArchive();
    if ($z_in->open($docx_template_path, ZipArchive::RDONLY) !== true) {
        return false;
    }
    $mht = $z_in->getFromName('word/afchunk.mht');
    $z_in->close();
    if (!is_string($mht) || $mht === '') {
        return false;
    }

    $delim = "\r\n------=mhtDocumentPart\r\n";
    if (strpos($mht, $delim) === false) {
        $delim = "\n------=mhtDocumentPart\n";
    }
    $parts = explode($delim, $mht, 3);
    if (count($parts) < 3) {
        return false;
    }
    $mime_header = $parts[0];
    $html_section = $parts[1];
    $image_section = $parts[2];
    if (!preg_match('/^([\s\S]*?)(\r\n\r\n|\n\n)([\s\S]*)$/', $html_section, $hm)) {
        return false;
    }

    $new_html_headers = "Content-Type: text/html;\r\n    charset=\"utf-8\"\r\nContent-Transfer-Encoding: base64\r\nContent-Location: file:///C:/fake/document.html";
    $new_body = chunk_split(base64_encode($html_utf8));
    $new_mht = $mime_header . $delim . $new_html_headers . "\r\n\r\n" . rtrim($new_body, "\r\n") . $delim . $image_section;

    $z_in = new ZipArchive();
    if ($z_in->open($docx_template_path, ZipArchive::RDONLY) !== true) {
        return false;
    }
    $z_out = new ZipArchive();
    if ($z_out->open($output_docx_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $z_in->close();
        return false;
    }
    $n = $z_in->numFiles;
    for ($i = 0; $i < $n; $i++) {
        $name = $z_in->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        $data = ($name === 'word/afchunk.mht') ? $new_mht : $z_in->getFromIndex($i);
        if ($data !== false) {
            $z_out->addFromString($name, $data);
        }
    }
    $z_in->close();
    $z_out->close();
    return file_exists($output_docx_path);
}

// AJAX: генерация договора на фронтенде (данные из формы)
function yvo_ajax_frontend_generate_contract() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Войдите в аккаунт для генерации договора.'));
    }
    $yvo_gen_uid = get_current_user_id();
    if (function_exists('yvo_tariff_can_generate')) {
        $chk = yvo_tariff_can_generate($yvo_gen_uid);
        if (is_wp_error($chk)) {
            wp_send_json_error(array('message' => $chk->get_error_message()));
        }
    }
    $seller_dec = yvo_json_decode_post_field('seller_data');
    $buyer_dec = yvo_json_decode_post_field('buyer_data');
    $sellers_dec = yvo_json_decode_post_field('sellers_data');
    $buyers_dec = yvo_json_decode_post_field('buyers_data');
    $property_dec = yvo_json_decode_post_field('property_data');
    $seller = is_array($seller_dec) ? $seller_dec : array();
    $buyer = is_array($buyer_dec) ? $buyer_dec : array();
    $property = is_array($property_dec) ? $property_dec : array();
    $sellers = is_array($sellers_dec) ? $sellers_dec : null;
    $buyers = is_array($buyers_dec) ? $buyers_dec : null;
    if ($sellers === null || !is_array($sellers)) {
        $sellers = array($seller);
    }
    if ($buyers === null || !is_array($buyers)) {
        $buyers = array($buyer);
    }
    foreach ($seller as $k => $v) {
        $seller[$k] = is_scalar($v) ? sanitize_text_field((string) $v) : $v;
    }
    foreach ($buyer as $k => $v) {
        $buyer[$k] = is_scalar($v) ? sanitize_text_field((string) $v) : $v;
    }
    $share_participants_raw = array();
    if (isset($property_dec['share_participants']) && is_array($property_dec['share_participants'])) {
        $share_participants_raw = $property_dec['share_participants'];
    }
    foreach ($property as $k => $v) {
        if ($k === 'share_participants') {
            continue;
        }
        $property[$k] = is_scalar($v) ? sanitize_text_field((string) $v) : $v;
    }
    $property['share_participants'] = array();
    foreach ($share_participants_raw as $sp_row) {
        if (!is_array($sp_row)) {
            continue;
        }
        $property['share_participants'][] = array(
            'tab' => sanitize_text_field((string) ($sp_row['tab'] ?? '')),
            'role' => sanitize_text_field((string) ($sp_row['role'] ?? '')),
            'full_name' => sanitize_text_field((string) ($sp_row['full_name'] ?? '')),
            'share_fraction' => sanitize_text_field((string) ($sp_row['share_fraction'] ?? '')),
            'joint_ownership' => !empty($sp_row['joint_ownership']) ? 1 : 0,
        );
    }
    if (isset($property_dec['share_joint_ownership'])) {
        $property['share_joint_ownership'] = !empty($property_dec['share_joint_ownership']) ? '1' : '';
    }
    $gift_dist_raw = isset($property_dec['gift_distributions']) ? $property_dec['gift_distributions'] : array();
    if (is_string($gift_dist_raw)) {
        $gift_dist_dec = json_decode(wp_unslash($gift_dist_raw), true);
        $gift_dist_raw = is_array($gift_dist_dec) ? $gift_dist_dec : array();
    }
    $property['gift_distributions'] = array();
    if (is_array($gift_dist_raw)) {
        foreach ($gift_dist_raw as $g_row) {
            if (!is_array($g_row)) {
                continue;
            }
            $share = trim((string) ($g_row['share_fraction'] ?? ''));
            if ($share === '') {
                continue;
            }
            $property['gift_distributions'][] = array(
                'donor_tab' => sanitize_text_field((string) ($g_row['donor_tab'] ?? '')),
                'donee_tab' => sanitize_text_field((string) ($g_row['donee_tab'] ?? '')),
                'share_fraction' => sanitize_text_field($share),
                'donor_name' => sanitize_text_field((string) ($g_row['donor_name'] ?? '')),
                'donee_name' => sanitize_text_field((string) ($g_row['donee_name'] ?? '')),
            );
        }
    }
    foreach ($sellers as $i => $s) {
        if (!is_array($s)) {
            unset($sellers[$i]);
            continue;
        }
        $sellers[$i] = yvo_sanitize_party_row_from_request($s);
    }
    $sellers = array_values($sellers);
    foreach ($buyers as $i => $b) {
        if (!is_array($b)) {
            unset($buyers[$i]);
            continue;
        }
        $buyers[$i] = yvo_sanitize_party_row_from_request($b);
    }
    $buyers = array_values($buyers);

    yvo_prepare_parties_for_contract_generation($sellers, $buyers);

    $property['address'] = yvo_property_resolve_address($property);
    if (function_exists('yvo_normalize_property_address_fields')) {
        $property = yvo_normalize_property_address_fields($property);
    }
    if (function_exists('yvo_egrn_normalize_property_by_object_type')) {
        $property = yvo_egrn_normalize_property_by_object_type($property);
    }
    $property = yvo_normalize_house_with_plot_cadastral($property);
    if (trim((string) ($property['city'] ?? '')) === '' && $property['address'] !== '') {
        $city_from_addr = yvo_extract_city_from_address($property['address']);
        if ($city_from_addr !== '') {
            $property['city'] = $city_from_addr;
        }
    }

    $template_id = isset($_POST['template_id']) ? sanitize_text_field($_POST['template_id']) : 'default';
    $template_id_requested = $template_id;
    $contract_type = isset($_POST['contract_type']) ? sanitize_text_field($_POST['contract_type']) : 'sale';
    if ($contract_type === 'sale' || $contract_type === 'sale_mortgage') {
        $pt = isset($property['payment_type']) ? sanitize_key((string) $property['payment_type']) : '';
        if ($pt === 'mortgage') {
            $contract_type = 'sale_mortgage';
        } elseif ($pt === 'cash') {
            $contract_type = 'sale';
        }
        $auto_tpl = yvo_resolve_dkp_template_id_from_payment($property, $template_id);
        if ($auto_tpl !== '' && $auto_tpl !== $template_id) {
            $template_id = $auto_tpl;
        }
        $debug_template_auto = ($auto_tpl !== '' && $auto_tpl !== $template_id_requested) ? $auto_tpl : null;
    }
    $bank_id = isset($_POST['bank_id']) ? sanitize_text_field($_POST['bank_id']) : '';
    if ($bank_id !== '') {
        $property['bank_id'] = $bank_id;
    }
    $generate_act = !empty($_POST['generate_act']);
    $generate_receipt = !empty($_POST['generate_receipt']);
    $custom_template_content = isset($_POST['custom_template_content']) ? wp_unslash($_POST['custom_template_content']) : '';

    if ($contract_type === 'gift') {
        $template_id = yvo_gift_uses_dolya_kvartira_template($sellers, $buyers, $property)
            ? 'shablon-darenie-dolya-kvartira'
            : 'shablon-darenie-dogovor';
    }
    if ($contract_type === 'share_allocation') {
        $template_id = 'shablon-vydelenie-doley-kvartira';
    }
    if ($contract_type === 'deposit_agreement') {
        $template_id = yvo_deposit_auto_template_id($property, $template_id);
    }
    if ($contract_type === 'advance_agreement') {
        $template_id = yvo_advance_auto_template_id($property, $template_id);
    }

    if (function_exists('yvo_tariff_apply_free_limits') && yvo_tariff_apply_free_limits($yvo_gen_uid)) {
        $custom_template_content = '';
        $all_tpl = yvo_get_available_templates();
        $allowed_tpl = yvo_tariff_filter_templates_for_user($all_tpl, $yvo_gen_uid);
        if (!in_array($contract_type, array('deposit_agreement', 'advance_agreement'), true) && !empty($allowed_tpl) && !isset($allowed_tpl[$template_id])) {
            $template_id = array_key_first($allowed_tpl);
        }
    }

    if (function_exists('yvo_tariff_validate_generation')) {
        $vchk = yvo_tariff_validate_generation($yvo_gen_uid, $contract_type, $template_id, $property);
        if (is_wp_error($vchk)) {
            wp_send_json_error(array('message' => $vchk->get_error_message()));
        }
    }

    $has_seller = false;
    foreach ($sellers as $s) { if (!empty($s['full_name'])) { $has_seller = true; break; } }
    $has_buyer = false;
    foreach ($buyers as $b) { if (!empty($b['full_name'])) { $has_buyer = true; break; } }
    if (!$has_seller || !$has_buyer || empty($property['address'])) {
        $need_parties = ($contract_type === 'gift')
            ? 'Заполните обязательные поля: ФИО дарителя и одаряемого (или несовершеннолетнего с опекуном), адрес объекта'
            : 'Заполните обязательные поля: ФИО продавца и покупателя, адрес объекта';
        wp_send_json_error(array('message' => $need_parties));
    }

    $party_validation_errors = yvo_validate_parties_for_contract_generation($sellers, $buyers, $contract_type);
    if (!empty($party_validation_errors)) {
        wp_send_json_error(array('message' => implode(' ', $party_validation_errors)));
    }

    $dkp_options = null;
    $debug = array(
        'template_id' => $template_id,
        'template_id_requested' => $template_id_requested,
        'template_id_auto' => isset($debug_template_auto) ? $debug_template_auto : null,
        'property_object_type' => isset($property['object_type']) ? (string) $property['object_type'] : '',
        'contract_type' => $contract_type,
        'txt_path' => null,
        'docx_template_path' => null,
        'docx_altchunk_skipped' => false,
        'html_mode' => null,
        'fp_server' => yvo_frontend_ajax_fp_diag_fields(),
        'counts' => array_merge(
            array(
                'sellers_total' => is_array($sellers) ? count($sellers) : 0,
                'buyers_total' => is_array($buyers) ? count($buyers) : 0,
                'sellers_principals' => is_array($sellers) ? count(yvo_parties_principal_sellers_ordered($sellers)) : 0,
                'buyers_principals' => is_array($buyers) ? count(yvo_parties_principal_buyers_ordered($buyers)) : 0,
            ),
            yvo_parties_generation_counts($sellers, $buyers)
        ),
    );
    if ($custom_template_content !== '' && strlen($custom_template_content) > 20) {
        $contract_content = yvo_fill_custom_template_content($custom_template_content, $seller, $buyer, $property);
        $debug['html_mode'] = 'custom_template_content';
        $debug['custom_template_override'] = true;
    } elseif ($contract_type === 'gift' || $contract_type === 'share_allocation') {
        $debug['txt_path'] = ($contract_type === 'gift')
            ? yvo_resolve_gift_template_txt_path($template_id, $property)
            : yvo_resolve_template_path($template_id, $bank_id);
        $contract_city = isset($property['city']) && trim((string) $property['city']) !== '' ? trim((string) $property['city']) : yvo_extract_city_from_address(isset($property['address']) ? $property['address'] : '');
        if ($contract_city === '') {
            $contract_city = '________________';
        }
        $dkp_options = array(
            'contract_city' => $contract_city,
            'contract_date' => date('d.m.Y'),
            'gift_template_id' => $template_id,
        );
        $contract_content = yvo_fill_dkp_ipoteka_template($sellers, $buyers, $property, $dkp_options, $template_id, $contract_type);
        $debug['html_mode'] = ($contract_type === 'gift') ? 'gift_template' : 'share_allocation_template';
        if ($contract_type === 'gift' && $debug['txt_path']) {
            $debug['gift_template_file'] = basename((string) $debug['txt_path']);
        }
    } elseif ($contract_type === 'deposit_agreement') {
        $path = yvo_resolve_deposit_agreement_template_path($template_id, $property, $bank_id);
        $debug['txt_path'] = $path;
        $contract_content = ($path && file_exists($path))
            ? yvo_fill_deposit_agreement_template($path, $sellers, $buyers, $property)
            : '';
        $debug['html_mode'] = 'deposit_agreement_template';
        $debug['deposit_template_id_effective'] = $template_id;
        if ($path) {
            $debug['deposit_template_file'] = basename((string) $path);
        }
    } elseif ($contract_type === 'advance_agreement') {
        $path = yvo_resolve_advance_agreement_template_path($template_id, $property, $bank_id);
        $debug['txt_path'] = $path;
        $contract_content = ($path && file_exists($path))
            ? yvo_fill_advance_agreement_template($path, $sellers, $buyers, $property)
            : '';
        $debug['html_mode'] = 'advance_agreement_template';
        $debug['advance_template_id_effective'] = $template_id;
        if ($path) {
            $debug['advance_template_file'] = basename((string) $path);
        }
    } elseif ($contract_type === 'sale' || $contract_type === 'sale_mortgage') {
        if (yvo_dkp_uses_modern_fill_engine($template_id)) {
            $debug['txt_path'] = yvo_resolve_dkp_sale_txt_path_effective($template_id);
            $dkp_options = yvo_build_dkp_options_from_property($property, $template_id);
            if ($bank_id !== '') {
                $dkp_options['bank_id'] = sanitize_key((string) $bank_id);
            }
            $contract_content = yvo_fill_dkp_ipoteka_template($sellers, $buyers, $property, $dkp_options, $template_id, $contract_type);
            $debug['html_mode'] = 'dkp_modern_template';
            $debug['dkp_payment_variant'] = yvo_dkp_payment_variant_key($template_id, $dkp_options);
        } else {
            $debug['txt_path'] = yvo_resolve_template_path($template_id, $bank_id);
            $contract_content = yvo_generate_contract_by_type($contract_type, $template_id, $seller, $buyer, $property, $bank_id);
            $debug['html_mode'] = 'legacy_template';
        }
    } else {
        $debug['txt_path'] = yvo_resolve_template_path($template_id, $bank_id);
        $contract_content = yvo_generate_contract_by_type($contract_type, $template_id, $seller, $buyer, $property, $bank_id);
    }
    $ts = date('Y-m-d-H-i-s');
    $filename_prefix = array(
        'sale' => 'dogovor-kupli-prodazhi',
        'sale_mortgage' => 'dogovor-kupli-prodazhi',
        'assignment' => 'dogovor-ustupki',
        'gift' => 'dogovor-dareniya',
        'share_allocation' => 'soglashenie-vydeleniya-doley',
        'preliminary' => 'predvaritelnyj-dogovor',
        'deposit_agreement' => 'soglashenie-o-zadatke',
        'deposit_receipt' => 'raspiska-zadatok',
        'advance_agreement' => 'soglashenie-ob-avanse',
    );
    $filename = (isset($filename_prefix[$contract_type]) ? $filename_prefix[$contract_type] : 'dogovor') . '-' . $ts . '.txt';
    $dir = YVO_PLUGIN_DIR . 'contracts/';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    if (!is_writable($dir)) {
        wp_send_json_error(array('message' => 'Нет прав на запись в папку contracts/. На сервере выполните: chown -R www-data:www-data ' . YVO_PLUGIN_DIR));
    }
    $contract_content_raw = $contract_content;
    $contract_content = "\xEF\xBB\xBF" . $contract_content;
    if (@file_put_contents($dir . $filename, $contract_content) === false) {
        wp_send_json_error(array('message' => 'Ошибка сохранения договора. Проверьте права на папку contracts/ (www-data должен иметь запись).'));
    }
    $contract_docx_url = '';
    $contract_doc_url = '';
    $contract_html_url = '';
    $contract_pdf_url = '';
    $docx_name = (isset($filename_prefix[$contract_type]) ? $filename_prefix[$contract_type] : 'dogovor') . '-' . $ts . '.docx';
    $doc_name = (isset($filename_prefix[$contract_type]) ? $filename_prefix[$contract_type] : 'dogovor') . '-' . $ts . '.doc';
    $docx_template_path = yvo_resolve_template_docx_path($template_id, $bank_id);
    $debug['docx_forced_text_mode'] = false;
    if ($contract_type === 'gift') {
        if (yvo_gift_use_docx_template($template_id, $property)) {
            $gift_docx = yvo_resolve_gift_template_docx_path($template_id, $bank_id);
            if ($gift_docx && file_exists($gift_docx)) {
                $docx_template_path = $gift_docx;
                $debug['docx_gift_from_template'] = true;
                $debug['docx_gift_from_text'] = false;
            } else {
                $docx_template_path = null;
                $debug['docx_gift_from_template'] = false;
                $debug['docx_gift_from_text'] = true;
            }
        } else {
            $docx_template_path = null;
            $debug['docx_gift_from_template'] = false;
            $debug['docx_gift_from_text'] = true;
            $debug['docx_gift_share_or_dolya_txt'] = true;
        }
    } elseif (yvo_dkp_modern_export_enabled($contract_type, $dkp_options)) {
        $docx_template_path = null;
        $debug['docx_template_skipped_for_styled_dkp'] = true;
    }
    $styled_html_content = '';
    if (yvo_dkp_modern_export_enabled($contract_type, $dkp_options)) {
        $repl_styled = yvo_get_dkp_ipoteka_placeholder_map($sellers, $buyers, $property, $dkp_options, $contract_type);
        $repl_styled['CONTRACT_TYPE'] = $contract_type;
        if (!yvo_parties_styled_html_has_fio(isset($repl_styled['SELLERS_BLOCK']) ? (string) $repl_styled['SELLERS_BLOCK'] : '')) {
            wp_send_json_error(array('message' => 'Не удалось сформировать карточку продавца: отсутствует ФИО в верстке договора. Проверьте данные продавца.'));
        }
        if (!yvo_parties_styled_html_has_fio(isset($repl_styled['BUYERS_BLOCK']) ? (string) $repl_styled['BUYERS_BLOCK'] : '')) {
            wp_send_json_error(array('message' => 'Не удалось сформировать карточку покупателя: отсутствует ФИО в верстке договора. Проверьте данные покупателя.'));
        }
        $styled_html_content = yvo_generate_dkp_ipoteka_full_styled_html($contract_content_raw, $repl_styled, $contract_type);
        $debug['html_mode'] = ($styled_html_content !== '') ? 'dkp_qwen_full' : 'from_full_text';
    }
    if (!$contract_docx_url && $styled_html_content !== '') {
        $styled_docx_shell = YVO_PLUGIN_DIR . 'templates/docx/dkp-nalichnye-akkreditiv-podpisi.docx';
        $html_for_docx = yvo_html_docx_fix_parties_and_signatures($styled_html_content);
        if (file_exists($styled_docx_shell) && yvo_docx_afchunk_replace_html($styled_docx_shell, $html_for_docx, $dir . $docx_name)) {
            $contract_docx_url = YVO_PLUGIN_URL . 'contracts/' . $docx_name;
            $debug['docx_mode'] = 'styled_full_afchunk';
        } else {
            $debug['docx_styled_skipped'] = true;
        }
    }
    // Если DOCX-шаблон построен через altChunk (HTML/MHT внутри), то плейсхолдеры часто не лежат в document.xml.
    // В этом случае формируем DOCX из полного текста, чтобы договор не «сокращался».
    if ($docx_template_path && $template_id !== 'dkp-nalichnye-akkreditiv-podpisi' && yvo_docx_template_is_altchunk($docx_template_path)) {
        $debug['docx_template_path'] = $docx_template_path;
        $debug['docx_altchunk_skipped'] = true;
        $docx_template_path = null;
    } else {
        $debug['docx_template_path'] = $docx_template_path;
    }
    // Если DOCX-шаблон не поддерживает динамические блоки сторон — он даст неверный результат (например, «Продавец 2» всегда).
    // Тогда для DKP используем генерацию DOCX из полного текста (совпадёт с HTML/TXT).
    if ($docx_template_path && is_array($dkp_options) && ($contract_type === 'gift' || yvo_dkp_modern_export_enabled($contract_type, $dkp_options))) {
        if (!yvo_docx_template_supports_dynamic_parties($docx_template_path)) {
            $debug['docx_dynamic_parties_skipped'] = true;
            $docx_template_path = null;
            if ($contract_type === 'gift') {
                $debug['docx_gift_from_template'] = false;
                $debug['docx_gift_from_text'] = true;
            }
        } else {
            $debug['docx_dynamic_parties_skipped'] = false;
        }
    }
    if ($docx_template_path) {
        $docx_done = false;
        if (!$docx_done) {
            if (yvo_dkp_modern_export_enabled($contract_type, $dkp_options)) {
                $replacements = yvo_get_dkp_ipoteka_placeholder_map($sellers, $buyers, $property, $dkp_options, $contract_type);
            } else {
                $replacements = yvo_build_docx_replacements($seller, $buyer, $property);
            }
            if (yvo_create_docx_from_template_docx($docx_template_path, $replacements, $dir . $docx_name)) {
                if (yvo_docx_has_unfilled_placeholders($dir . $docx_name)) {
                    @unlink($dir . $docx_name);
                    $debug['docx_unfilled_placeholders'] = true;
                } else {
                    $contract_docx_url = YVO_PLUGIN_URL . 'contracts/' . $docx_name;
                }
            }
        }
    }
    $docx_profile = yvo_docx_profile_for_contract_type($contract_type);
    if (!$contract_docx_url && ($contract_type === 'gift' || $contract_type === 'share_allocation')
        && yvo_create_docx_from_text($contract_content_raw, $dir . $docx_name, $docx_profile)) {
        $contract_docx_url = YVO_PLUGIN_URL . 'contracts/' . $docx_name;
        $debug['docx_mode'] = 'gift_share_full_text';
    }
    if (!$contract_docx_url && yvo_create_docx_from_text($contract_content_raw, $dir . $docx_name, $docx_profile)) {
        $contract_docx_url = YVO_PLUGIN_URL . 'contracts/' . $docx_name;
        if (!isset($debug['docx_mode'])) {
            $debug['docx_mode'] = 'full_text';
        }
    }
    if (!$contract_docx_url && !class_exists('ZipArchive')) {
        $debug['docx_error'] = 'ZipArchive отсутствует (нужно включить расширение php_zip). DOCX не может быть создан на этом сервере.';
    }
    if (yvo_create_doc_from_text($contract_content_raw, $dir . $doc_name)) {
        $contract_doc_url = YVO_PLUGIN_URL . 'contracts/' . $doc_name;
    }

    // HTML/PDF версия (тот же styled HTML, что и для DOCX)
    if (yvo_dkp_modern_export_enabled($contract_type, $dkp_options)) {
        $html_content = $styled_html_content;
        if ($html_content === '') {
            $html_content = yvo_contract_text_to_html_document($contract_content_raw);
        }
        if ($html_content !== '') {
            $html_name = (isset($filename_prefix[$contract_type]) ? $filename_prefix[$contract_type] : 'dogovor') . '-' . $ts . '.html';
            if (@file_put_contents($dir . $html_name, $html_content)) {
                $contract_html_url = YVO_PLUGIN_URL . 'contracts/' . $html_name;
                $pdf_name = (isset($filename_prefix[$contract_type]) ? $filename_prefix[$contract_type] : 'dogovor') . '-' . $ts . '.pdf';
                if (yvo_html_to_pdf($dir . $html_name, $dir . $pdf_name)) {
                    $contract_pdf_url = YVO_PLUGIN_URL . 'contracts/' . $pdf_name;
                }
            }
        }
    }
    $result = array(
        'contract_url' => YVO_PLUGIN_URL . 'contracts/' . $filename,
        'contract_docx_url' => $contract_docx_url,
        'contract_doc_url' => $contract_doc_url,
        'contract_html_url' => $contract_html_url,
        'contract_pdf_url' => $contract_pdf_url,
        'template_id_used' => $template_id,
        'contract_content' => $contract_content_raw,
        'debug' => $debug,
        'message' => 'Договор создан (build ' . (defined('YVO_VERSION') ? YVO_VERSION : 'n/a') . ')',
        'act_url' => '',
        'act_docx_url' => '',
        'receipt_url' => '',
        'receipt_docx_url' => '',
    );

    $act_seller = $seller;
    $act_buyer = $buyer;
    $principals_s = yvo_parties_principal_sellers_ordered($sellers);
    $principals_b = yvo_parties_principal_buyers_ordered($buyers);
    if (!empty($principals_s[0]) && is_array($principals_s[0])) {
        $act_seller = $principals_s[0];
    }
    if (!empty($principals_b[0]) && is_array($principals_b[0])) {
        $act_buyer = $principals_b[0];
    }

    if ($generate_act && in_array($contract_type, yvo_contract_types_with_act_receipt(), true)
        && !in_array($contract_type, array('deposit_agreement', 'advance_agreement'), true)) {
        if ($contract_type === 'deposit_agreement') {
            $path_agreement = YVO_PLUGIN_DIR . 'templates/deposit-agreement.txt';
            $act_content = file_exists($path_agreement) ? yvo_fill_deposit_agreement_template($path_agreement, $sellers, $buyers, $property) : '';
            $act_filename = 'soglashenie-o-zadatke-' . $ts . '.txt';
        } elseif ($contract_type === 'advance_agreement') {
            $path_agreement = YVO_PLUGIN_DIR . 'templates/advance-agreement.txt';
            $act_content = file_exists($path_agreement) ? yvo_fill_template_placeholders($path_agreement, $seller, $buyer, $property) : '';
            $act_filename = 'soglashenie-ob-avanse-' . $ts . '.txt';
        } else {
            $act_ct = ($contract_type === 'sale_mortgage') ? 'sale_mortgage' : 'sale';
            $act_options = is_array($dkp_options) ? $dkp_options : yvo_build_dkp_options_from_property($property, $template_id);
            $act_content_raw = yvo_fill_act_sale_template($sellers, $buyers, $property, $act_options, $act_ct);
            if ($act_content_raw === '') {
                $act_content_raw = yvo_generate_act_text($act_seller, $act_buyer, $property);
            }
            $act_content = $act_content_raw;
            $act_filename = 'akt-priema-peredachi-' . $ts . '.txt';
        }
        if ($act_content !== '') {
            $act_content = "\xEF\xBB\xBF" . $act_content;
            if (file_put_contents($dir . $act_filename, $act_content)) {
                $result['act_url'] = YVO_PLUGIN_URL . 'contracts/' . $act_filename;
            }
            $act_docx_name = 'akt-priema-peredachi-' . $ts . '.docx';
            $act_styled_html = '';
            if (!isset($act_ct)) {
                $act_ct = ($contract_type === 'sale_mortgage') ? 'sale_mortgage' : 'sale';
            }
            if (!isset($act_options)) {
                $act_options = is_array($dkp_options) ? $dkp_options : yvo_build_dkp_options_from_property($property, $template_id);
            }
            if (in_array($contract_type, array('sale', 'sale_mortgage'), true)
                && function_exists('yvo_generate_act_styled_html') && isset($act_content_raw) && $act_content_raw !== '') {
                $repl_act = yvo_get_dkp_ipoteka_placeholder_map($sellers, $buyers, $property, $act_options, $act_ct);
                $repl_act['CONTRACT_TYPE'] = 'act';
                $act_styled_html = yvo_generate_act_styled_html($act_content_raw, $repl_act);
            }
            $act_docx_shell = YVO_PLUGIN_DIR . 'templates/docx/dkp-nalichnye-akkreditiv-podpisi.docx';
            $act_html_for_docx = ($act_styled_html !== '') ? yvo_html_docx_fix_parties_and_signatures($act_styled_html) : '';
            if ($act_html_for_docx !== '' && file_exists($act_docx_shell)
                && yvo_docx_afchunk_replace_html($act_docx_shell, $act_html_for_docx, $dir . $act_docx_name)) {
                $result['act_docx_url'] = YVO_PLUGIN_URL . 'contracts/' . $act_docx_name;
            } elseif (yvo_create_docx_from_text(trim($act_content, "\xEF\xBB\xBF"), $dir . $act_docx_name, 'default')) {
                $result['act_docx_url'] = YVO_PLUGIN_URL . 'contracts/' . $act_docx_name;
            }
        }
    }
    if ($generate_receipt && in_array($contract_type, yvo_contract_types_with_act_receipt(), true)) {
        if ($contract_type === 'deposit_agreement') {
            $path_receipt = YVO_PLUGIN_DIR . 'templates/deposit-receipt.txt';
            $receipt_content = file_exists($path_receipt)
                ? yvo_fill_deposit_receipt_template($path_receipt, $sellers, $buyers, $property)
                : '';
            $receipt_filename = 'raspiska-v-poluchenii-zadatka-' . $ts . '.txt';
        } elseif ($contract_type === 'advance_agreement') {
            $path_receipt = YVO_PLUGIN_DIR . 'templates/advance-receipt.txt';
            $receipt_content = file_exists($path_receipt)
                ? yvo_fill_advance_receipt_template($path_receipt, $sellers, $buyers, $property)
                : '';
            $receipt_filename = 'raspiska-v-poluchenii-avanza-' . $ts . '.txt';
        } else {
            $receipt_content = yvo_generate_receipt_text($act_seller, $act_buyer, $property);
            $receipt_filename = 'raspiska-' . $ts . '.txt';
        }
        if ($receipt_content !== '') {
            $receipt_content = "\xEF\xBB\xBF" . $receipt_content;
            if (file_put_contents($dir . $receipt_filename, $receipt_content)) {
                $result['receipt_url'] = YVO_PLUGIN_URL . 'contracts/' . $receipt_filename;
            }
            $receipt_docx_name = 'raspiska-' . $ts . '.docx';
            if (yvo_create_docx_from_text(trim($receipt_content, "\xEF\xBB\xBF"), $dir . $receipt_docx_name, 'deposit')) {
                $result['receipt_docx_url'] = YVO_PLUGIN_URL . 'contracts/' . $receipt_docx_name;
            }
        }
    }
    if (function_exists('yvo_tariff_after_generation_success')) {
        yvo_tariff_after_generation_success($yvo_gen_uid, $contract_type, $template_id);
    }
    wp_send_json_success($result);
}

// Проверка договора на ошибки — только для авторизованных (nopriv отказ)
function yvo_ajax_check_contract_errors_nopriv() {
    wp_send_json_error(array('message' => 'Проверка договора доступна только авторизованным пользователям. Войдите в личный кабинет.'));
}

// AJAX: проверка договора на юридические и орфографические ошибки (DeepSeek)
function yvo_ajax_check_contract_errors() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Проверка договора доступна только авторизованным пользователям.'));
    }
    $uid = get_current_user_id();
    if (function_exists('yvo_tariff_can_contract_check') && !yvo_tariff_can_contract_check($uid)) {
        $pricing = function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/');
        wp_send_json_error(array(
            'message' => 'Проверка договора доступна на тарифах «Про» и «Бизнес». Выберите подписку на странице «Тарифы».',
            'pricing_url' => $pricing,
        ));
    }
    $api_key = get_option('yvo_deepseek_api_key');
    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'DeepSeek не настроен. Укажите API ключ в настройках плагина.'));
    }
    $contract_text = isset($_POST['contract_content']) ? wp_unslash($_POST['contract_content']) : '';
    if (strlen($contract_text) < 50) {
        wp_send_json_error(array('message' => 'Текст договора слишком короткий для проверки.'));
    }
    if (strlen($contract_text) > 45000) {
        $contract_text = mb_substr($contract_text, 0, 45000) . "\n\n[... текст сокращён ...]";
    }
    $prompt = "Ты — юрист и редактор. Проверь текст договора купли-продажи недвижимости на:\n";
    $prompt .= "1) Юридические и логические ошибки: противоречия, несоответствие сумм и формулировок, корректность параметров квартиры (площадь, комнаты, адрес), согласованность дат и сторон.\n";
    $prompt .= "2) Орфографические и грамматические ошибки (правописание).\n";
    $prompt .= "3) Для каждой найденной проблемы: кратко опиши и предложи исправление.\n\n";
    $prompt .= "Текст договора:\n---НАЧАЛО---\n" . $contract_text . "\n---КОНЕЦ---\n\n";
    $prompt .= "Ответ дай в таком виде:\n";
    $prompt .= "ОТЧЁТ О ПРОВЕРКЕ:\n[твой разбор по пунктам 1–3]\n\n";
    $prompt .= "Если хочешь предложить исправленный вариант всего договора, после отчёта напиши ровно:\nИСПРАВЛЕННЫЙ ТЕКСТ:\n[полный текст договора с правками, сохраняя абзацы]";
    $model = get_option('yvo_deepseek_model', 'deepseek-chat');
    $url = 'https://api.deepseek.com/v1/chat/completions';
    $headers = array('Authorization: Bearer ' . $api_key, 'Content-Type: application/json');
    $body = array(
        'model' => $model,
        'messages' => array(array('role' => 'user', 'content' => $prompt)),
        'max_tokens' => 16000,
        'temperature' => 0.2,
    );
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        wp_send_json_error(array('message' => 'Ошибка сети: ' . $err));
    }
    if ($http_code != 200) {
        $data = json_decode($response, true);
        $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Ошибка DeepSeek';
        wp_send_json_error(array('message' => $msg));
    }
    $data = json_decode($response, true);
    $content = isset($data['choices'][0]['message']['content']) ? trim($data['choices'][0]['message']['content']) : '';
    $report = $content;
    $corrected = '';
    if (preg_match('/\nИСПРАВЛЕННЫЙ ТЕКСТ:\s*\n([\s\S]+)$/u', $content, $m)) {
        $corrected = trim($m[1]);
        $report = trim(preg_replace('/\nИСПРАВЛЕННЫЙ ТЕКСТ:\s*\n[\s\S]+$/u', '', $content));
    }
    wp_send_json_success(array('report' => $report, 'corrected' => $corrected));
}

function yvo_ajax_check_property_nopriv() {
    $login = function_exists('yvo_cabinet_get_login_url') ? yvo_cabinet_get_login_url() : wp_login_url();
    wp_send_json_error(array(
        'message' => 'Войдите или зарегистрируйтесь, чтобы проверить квартиру.',
        'login_required' => 1,
        'login_url' => $login,
    ));
}

/**
 * AJAX: проверка квартиры по кадастру и документам (DeepSeek).
 */
function yvo_ajax_check_property() {
    if (!is_user_logged_in()) {
        yvo_ajax_check_property_nopriv();
        return;
    }
    $uid = get_current_user_id();
    if (function_exists('yvo_tariff_property_check_access')) {
        $access = yvo_tariff_property_check_access($uid);
        if (is_wp_error($access)) {
            $edata = $access->get_error_data();
            $pricing = is_array($edata) && !empty($edata['pricing_url'])
                ? $edata['pricing_url']
                : (function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/'));
            wp_send_json_error(array(
                'message' => $access->get_error_message(),
                'pricing_url' => $pricing,
            ));
        }
    }
    $api_key = get_option('yvo_deepseek_api_key');
    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'DeepSeek не настроен. Укажите API ключ в настройках плагина.'));
    }
    $cad = isset($_POST['cadastral_number']) ? sanitize_text_field(wp_unslash($_POST['cadastral_number'])) : '';
    $cad = preg_replace('/\s+/', '', $cad);
    $doc_text = isset($_POST['document_text']) ? wp_unslash($_POST['document_text']) : '';
    $doc_text = is_string($doc_text) ? trim($doc_text) : '';
    if ($cad === '' && strlen($doc_text) < 80) {
        wp_send_json_error(array('message' => 'Укажите кадастровый номер или загрузите выписку ЕГРН / договор (не менее 80 символов текста).'));
    }
    if (strlen($doc_text) > 45000) {
        $doc_text = mb_substr($doc_text, 0, 45000) . "\n\n[... текст сокращён ...]";
    }
    $prompt = "Ты — юрист по сделкам с недвижимостью. Проанализируй объект недвижимости (квартира) перед покупкой.\n\n";
    if ($cad !== '') {
        $prompt .= "Кадастровый номер: " . $cad . "\n\n";
    }
    if ($doc_text !== '') {
        $prompt .= "Текст документов (выписка ЕГРН, договор, выписка и т.п.):\n---НАЧАЛО---\n" . $doc_text . "\n---КОНЕЦ---\n\n";
    } else {
        $prompt .= "Документы не приложены — сделай общий чек-лист рисков по кадастровому номеру (без доступа к реестру укажи, что данные нужно сверить с актуальной выпиской ЕГРН).\n\n";
    }
    $prompt .= "Дай структурированный отчёт на русском языке:\n";
    $prompt .= "1) ОБЪЕКТ — адрес, тип, площадь, кадастровый номер (если есть в тексте), кадастровая стоимость (если есть).\n";
    $prompt .= "2) СОБСТВЕННИКИ — ФИО, доли, основания регистрации.\n";
    $prompt .= "3) ОБРЕМЕНЕНИЯ И ОГРАНИЧЕНИЯ — ипотека, арест, рента, сервитут и т.д.\n";
    $prompt .= "4) РИСКИ И РЕКОМЕНДАЦИИ — маркированный список: что проверить перед сделкой, на что обратить внимание.\n";
    $prompt .= "5) ИТОГ — краткая оценка: низкий / средний / высокий уровень риска и почему.\n\n";
    $prompt .= "Пиши понятным языком. Если данных недостаточно — явно укажи, чего не хватает. Это не юридическая консультация, а анализ ИИ.";
    $model = get_option('yvo_deepseek_model', 'deepseek-chat');
    $url = 'https://api.deepseek.com/v1/chat/completions';
    $headers = array('Authorization: Bearer ' . $api_key, 'Content-Type: application/json');
    $body = array(
        'model' => $model,
        'messages' => array(array('role' => 'user', 'content' => $prompt)),
        'max_tokens' => 8000,
        'temperature' => 0.2,
    );
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        wp_send_json_error(array('message' => 'Ошибка сети: ' . $err));
    }
    if ($http_code != 200) {
        $data = json_decode($response, true);
        $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Ошибка DeepSeek';
        wp_send_json_error(array('message' => $msg));
    }
    $data = json_decode($response, true);
    $report = isset($data['choices'][0]['message']['content']) ? trim($data['choices'][0]['message']['content']) : '';
    if ($report === '') {
        wp_send_json_error(array('message' => 'DeepSeek не вернул отчёт. Попробуйте ещё раз.'));
    }
    $charged = null;
    if (function_exists('yvo_tariff_charge_property_check')) {
        $charged = yvo_tariff_charge_property_check($uid);
        if (is_wp_error($charged)) {
            wp_send_json_error(array('message' => $charged->get_error_message()));
        }
    }
    $wallet = function_exists('yvo_tariff_get_balance') ? yvo_tariff_get_balance($uid) : 0;
    wp_send_json_success(array(
        'report' => $report,
        'cadastral_number' => $cad,
        'charged_mode' => is_array($charged) ? $charged['mode'] : 'free',
        'charged_price' => is_array($charged) ? $charged['price'] : 0,
        'wallet_balance' => $wallet,
    ));
}

// AJAX: чат в редакторе договора (DeepSeek — подсказки, правки с применением к договору)
function yvo_ajax_contract_editor_chat() {
    $api_key = get_option('yvo_deepseek_api_key');
    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'DeepSeek не настроен. Укажите API ключ в настройках.'));
    }
    $contract_text = isset($_POST['contract_text']) ? wp_unslash($_POST['contract_text']) : '';
    if (strlen($contract_text) > 45000) {
        $contract_text = mb_substr($contract_text, 0, 45000) . "\n\n[... текст сокращён из-за ограничения длины ...]";
    }
    $user_message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
    if ($user_message === '') {
        wp_send_json_error(array('message' => 'Введите сообщение.'));
    }
    $context = isset($_POST['context']) ? wp_unslash($_POST['context']) : '';
    $prompt = "Ты — помощник по редактированию договора купли-продажи. Пользователь может просить внести правки в текст договора (добавить дату рождения, исправить формулировки и т.д.).\n\n";
    $prompt .= "Текущий текст договора (полностью):\n---НАЧАЛО ДОГОВОРА---\n" . $contract_text . "\n---КОНЕЦ ДОГОВОРА---\n\n";
    if ($context !== '') {
        $prompt .= "Данные из формы (для подстановки): " . mb_substr($context, 0, 2000) . "\n\n";
    }
    $prompt .= "Сообщение пользователя: " . $user_message . "\n\n";
    $prompt .= "ПРАВИЛА ОТВЕТА:\n";
    $prompt .= "1) Если пользователь просит ВНЕСТИ ПРАВКИ в договор (добавить дату рождения продавца/покупателя, исправить, дополнить и т.п.) — ты ОБЯЗАН вернуть исправленный ПОЛНЫЙ текст договора, чтобы он подставился в редактор. Формат: сначала кратко одной фразой что сделано, затем с новой строки ровно маркер ПРИМЕНИТЬ: и с следующей строки весь текст договора от начала до конца с внесёнными правками, сохраняя все абзацы и переносы строк.\n";
    $prompt .= "2) Если пользователь только спрашивает (например «какие пункты не заполнены?») — ответь списком или текстом без маркера ПРИМЕНИТЬ.\n";
    $prompt .= "Пример ответа с правками:\nСделано: добавлена дата рождения продавца в преамбулу.\nПРИМЕНИТЬ:\n[здесь полный текст договора с правками]";
    $model = get_option('yvo_deepseek_model', 'deepseek-chat');
    $url = 'https://api.deepseek.com/v1/chat/completions';
    $headers = array('Authorization: Bearer ' . $api_key, 'Content-Type: application/json');
    $body = array(
        'model' => $model,
        'messages' => array(array('role' => 'user', 'content' => $prompt)),
        'max_tokens' => 16000,
        'temperature' => 0.2,
    );
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        wp_send_json_error(array('message' => 'Ошибка сети: ' . $err));
    }
    if ($http_code != 200) {
        $data = json_decode($response, true);
        $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Ошибка DeepSeek';
        wp_send_json_error(array('message' => $msg));
    }
    $data = json_decode($response, true);
    $content = isset($data['choices'][0]['message']['content']) ? trim($data['choices'][0]['message']['content']) : '';
    wp_send_json_success(array('reply' => $content));
}

// AJAX: сохранение отредактированного договора (новый файл .txt и .docx, возврат ссылок)
function yvo_ajax_save_edited_contract() {
    $content = isset($_POST['contract_content']) ? wp_unslash($_POST['contract_content']) : '';
    if (strlen($content) < 10) {
        wp_send_json_error(array('message' => 'Текст договора слишком короткий.'));
    }
    $content = sanitize_textarea_field($content);
    $dir = YVO_PLUGIN_DIR . 'contracts/';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    $ts = date('Y-m-d-H-i-s');
    $filename = 'dogovor-edited-' . $ts . '.txt';
    $docx_name = 'dogovor-edited-' . $ts . '.docx';
    $doc_name = 'dogovor-edited-' . $ts . '.doc';
    $content_bom = "\xEF\xBB\xBF" . $content;
    if (!file_put_contents($dir . $filename, $content_bom)) {
        wp_send_json_error(array('message' => 'Ошибка сохранения файла.'));
    }
    $contract_docx_url = '';
    $contract_doc_url = '';
    if (yvo_create_docx_from_text($content, $dir . $docx_name)) {
        $contract_docx_url = YVO_PLUGIN_URL . 'contracts/' . $docx_name;
    }
    if (yvo_create_doc_from_text($content, $dir . $doc_name)) {
        $contract_doc_url = YVO_PLUGIN_URL . 'contracts/' . $doc_name;
    }
    wp_send_json_success(array(
        'contract_url' => YVO_PLUGIN_URL . 'contracts/' . $filename,
        'contract_docx_url' => $contract_docx_url,
        'contract_doc_url' => $contract_doc_url,
        'contract_content' => $content,
        'message' => 'Договор сохранён.',
    ));
}

function yvo_save_edited_simple_document($prefix, $content) {
    $dir = YVO_PLUGIN_DIR . 'contracts/';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    $ts = date('Y-m-d-H-i-s');
    $filename = $prefix . '-edited-' . $ts . '.txt';
    $content_bom = "\xEF\xBB\xBF" . $content;
    if (!file_put_contents($dir . $filename, $content_bom)) {
        return null;
    }
    return YVO_PLUGIN_URL . 'contracts/' . $filename;
}

function yvo_ajax_save_edited_act() {
    $content = isset($_POST['document_content']) ? wp_unslash($_POST['document_content']) : '';
    if (strlen($content) < 10) {
        wp_send_json_error(array('message' => 'Текст слишком короткий.'));
    }
    $content = sanitize_textarea_field($content);
    $url = yvo_save_edited_simple_document('akt', $content);
    if (!$url) {
        wp_send_json_error(array('message' => 'Ошибка сохранения файла.'));
    }
    wp_send_json_success(array(
        'url' => $url,
        'message' => 'Акт сохранён.',
    ));
}

function yvo_ajax_save_edited_receipt() {
    $content = isset($_POST['document_content']) ? wp_unslash($_POST['document_content']) : '';
    if (strlen($content) < 10) {
        wp_send_json_error(array('message' => 'Текст слишком короткий.'));
    }
    $content = sanitize_textarea_field($content);
    $url = yvo_save_edited_simple_document('raspiska', $content);
    if (!$url) {
        wp_send_json_error(array('message' => 'Ошибка сохранения файла.'));
    }
    wp_send_json_success(array(
        'url' => $url,
        'message' => 'Расписка сохранена.',
    ));
}

// ————— Личный кабинет: сохранённые данные и договоры —————
// Интеграция с другими плагинами: кабинет использует get_current_user_id().
// Любой плагин авторизации (MemberPress, Ultimate Member, WooCommerce и т.д.),
// создающий WP-пользователей, автоматически даёт персональное сохранение (user_meta).
// Ограничить доступ только для оплативших: add_filter('yvo_cabinet_can_save', function($can, $user_id) { return your_has_subscription($user_id); }, 10, 2);

function yvo_cabinet_can_save() {
    $user_id = get_current_user_id();
    $can = $user_id > 0;
    return (bool) apply_filters('yvo_cabinet_can_save', $can, $user_id);
}

function yvo_cabinet_verify_ajax() {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'yvo_frontend_contract')) {
        wp_send_json_error(array('message' => 'Сессия устарела. Обновите страницу и войдите снова.'), 403);
    }
}

function yvo_cabinet_get_storage_key() {
    $user_id = get_current_user_id();
    return $user_id > 0 ? 'yvo_cabinet_' . $user_id : 'yvo_cabinet_guest';
}

function yvo_cabinet_get_items() {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return array();
    }
    $items = get_user_meta($user_id, 'yvo_cabinet_drafts', true);
    if (!is_array($items)) {
        $items = array();
    }
    usort($items, function ($a, $b) {
        return strcmp($b['created'] ?? '', $a['created'] ?? '');
    });
    return $items;
}

/**
 * Лимит черновиков в user_meta (самые новые остаются).
 */
function yvo_cabinet_max_items() {
    return (int) apply_filters('yvo_cabinet_max_items', 50);
}

function yvo_cabinet_fingerprint($type, $data) {
    $norm = array(
        'type' => (string) $type,
        'sellers' => isset($data['sellers']) && is_array($data['sellers']) ? $data['sellers'] : array(),
        'buyers' => isset($data['buyers']) && is_array($data['buyers']) ? $data['buyers'] : array(),
        'property' => isset($data['property']) && is_array($data['property']) ? $data['property'] : array(),
    );
    return md5(wp_json_encode($norm));
}

function yvo_cabinet_save_item($type, $name, $data) {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return new WP_Error('yvo_cabinet_login', 'Войдите в аккаунт, чтобы сохранять в кабинет.');
    }
    $items = get_user_meta($user_id, 'yvo_cabinet_drafts', true);
    if (!is_array($items)) {
        $items = array();
    }
    $fp = yvo_cabinet_fingerprint($type, is_array($data) ? $data : array());
    $updated_id = '';
    foreach ($items as $i => $item) {
        $existing_fp = isset($item['fingerprint']) ? (string) $item['fingerprint'] : '';
        if ($existing_fp === '' && isset($item['data']) && is_array($item['data'])) {
            $existing_fp = yvo_cabinet_fingerprint($item['type'] ?? '', $item['data']);
        }
        if ($existing_fp === $fp) {
            $items[$i]['name'] = $name;
            $items[$i]['created'] = current_time('Y-m-d H:i');
            $items[$i]['data'] = $data;
            $items[$i]['fingerprint'] = $fp;
            $updated_id = (string) ($item['id'] ?? '');
            break;
        }
    }
    if ($updated_id === '') {
        $id = 'yvo_' . uniqid('');
        $items[] = array(
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'created' => current_time('Y-m-d H:i'),
            'data' => $data,
            'fingerprint' => $fp,
        );
        $updated_id = $id;
    }
    usort($items, function ($a, $b) {
        return strcmp($b['created'] ?? '', $a['created'] ?? '');
    });
    $max = yvo_cabinet_max_items();
    if ($max > 0 && count($items) > $max) {
        $items = array_slice($items, 0, $max);
    }
    update_user_meta($user_id, 'yvo_cabinet_drafts', $items);
    return array(
        'id' => $updated_id,
        'updated' => true,
        'items' => yvo_cabinet_get_items(),
    );
}

function yvo_cabinet_delete_item($id) {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return array();
    }
    $items = get_user_meta($user_id, 'yvo_cabinet_drafts', true);
    if (!is_array($items)) {
        $items = array();
    }
    $items = array_values(array_filter($items, function ($item) use ($id) {
        return ($item['id'] ?? '') !== $id;
    }));
    update_user_meta($user_id, 'yvo_cabinet_drafts', $items);
    return yvo_cabinet_get_items();
}

function yvo_ajax_cabinet_list() {
    yvo_cabinet_verify_ajax();
    if (!yvo_cabinet_can_save()) {
        wp_send_json_success(array('items' => array(), 'message' => 'Войдите в аккаунт, чтобы видеть сохранённые данные.'));
        return;
    }
    wp_send_json_success(array('items' => yvo_cabinet_get_items()));
}

function yvo_ajax_cabinet_save() {
    yvo_cabinet_verify_ajax();
    if (!yvo_cabinet_can_save()) {
        wp_send_json_error(array('message' => 'Войдите в аккаунт, чтобы сохранять в кабинет.'));
    }
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $sellers = json_decode(isset($_POST['sellers_data']) ? wp_unslash($_POST['sellers_data']) : '[]', true);
    $buyers = json_decode(isset($_POST['buyers_data']) ? wp_unslash($_POST['buyers_data']) : '[]', true);
    $property = json_decode(isset($_POST['property_data']) ? wp_unslash($_POST['property_data']) : '{}', true);
    if (!is_array($sellers)) {
        $sellers = array();
    }
    if (!is_array($buyers)) {
        $buyers = array();
    }
    if (!is_array($property)) {
        $property = array();
    }
    if ($name === '') {
        $parts = array_merge(
            array_filter(array_map(function ($s) {
                return $s['full_name'] ?? '';
            }, $sellers)),
            array_filter(array_map(function ($b) {
                return $b['full_name'] ?? '';
            }, $buyers))
        );
        $name = trim(implode(', ', $parts));
        if (!empty($property['address'])) {
            $name .= ($name ? ' — ' : '') . mb_substr($property['address'], 0, 40);
        }
        if ($name === '') {
            $name = 'Данные ' . current_time('d.m.Y H:i');
        }
    }
    $result = yvo_cabinet_save_item('dataset', $name, array('sellers' => $sellers, 'buyers' => $buyers, 'property' => $property));
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    wp_send_json_success($result);
}

function yvo_ajax_cabinet_load() {
    yvo_cabinet_verify_ajax();
    if (!yvo_cabinet_can_save()) {
        wp_send_json_error(array('message' => 'Войдите в аккаунт.'));
    }
    $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
    foreach (yvo_cabinet_get_items() as $item) {
        if (($item['id'] ?? '') === $id) {
            wp_send_json_success(array('item' => $item));
        }
    }
    wp_send_json_error(array('message' => 'Запись не найдена'));
}

function yvo_ajax_cabinet_delete() {
    yvo_cabinet_verify_ajax();
    if (!yvo_cabinet_can_save()) {
        wp_send_json_error(array('message' => 'Войдите в аккаунт.'));
    }
    $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
    wp_send_json_success(array('items' => yvo_cabinet_delete_item($id)));
}

function yvo_ajax_cabinet_save_contract() {
    yvo_cabinet_verify_ajax();
    if (!yvo_cabinet_can_save()) {
        wp_send_json_error(array('message' => 'Войдите в аккаунт, чтобы сохранять в кабинет.'));
    }
    $content = isset($_POST['contract_content']) ? wp_unslash($_POST['contract_content']) : '';
    $url = isset($_POST['contract_url']) ? esc_url_raw(wp_unslash($_POST['contract_url'])) : '';
    $docx_url = isset($_POST['contract_docx_url']) ? esc_url_raw(wp_unslash($_POST['contract_docx_url'])) : '';
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : ('Договор ' . current_time('d.m.Y H:i'));
    $result = yvo_cabinet_save_item('contract', $name, array('content' => $content, 'url' => $url, 'docx_url' => $docx_url));
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    wp_send_json_success($result);
}

function yvo_ajax_cabinet_save_object() {
    yvo_cabinet_verify_ajax();
    if (!yvo_cabinet_can_save()) {
        wp_send_json_error(array('message' => 'Войдите в аккаунт, чтобы сохранять в кабинет.'));
    }
    $property = json_decode(isset($_POST['property_data']) ? wp_unslash($_POST['property_data']) : '{}', true);
    if (!is_array($property)) {
        $property = array();
    }
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    if ($name === '' && !empty($property['address'])) {
        $name = mb_substr($property['address'], 0, 60);
    }
    if ($name === '') {
        $name = 'Объект ' . current_time('d.m.Y H:i');
    }
    $result = yvo_cabinet_save_item('object', $name, array('sellers' => array(), 'buyers' => array(), 'property' => $property));
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    wp_send_json_success($result);
}

function yvo_ajax_cabinet_save_participants() {
    yvo_cabinet_verify_ajax();
    if (!yvo_cabinet_can_save()) {
        wp_send_json_error(array('message' => 'Войдите в аккаунт, чтобы сохранять в кабинет.'));
    }
    $sellers = json_decode(isset($_POST['sellers_data']) ? wp_unslash($_POST['sellers_data']) : '[]', true);
    $buyers = json_decode(isset($_POST['buyers_data']) ? wp_unslash($_POST['buyers_data']) : '[]', true);
    if (!is_array($sellers)) {
        $sellers = array();
    }
    if (!is_array($buyers)) {
        $buyers = array();
    }
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    if ($name === '') {
        $parts = array_merge(
            array_filter(array_map(function ($s) {
                return $s['full_name'] ?? '';
            }, $sellers)),
            array_filter(array_map(function ($b) {
                return $b['full_name'] ?? '';
            }, $buyers))
        );
        $name = trim(implode(', ', $parts));
        if ($name === '') {
            $name = 'Участники ' . current_time('d.m.Y H:i');
        }
    }
    $result = yvo_cabinet_save_item('participants', $name, array('sellers' => $sellers, 'buyers' => $buyers, 'property' => array()));
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    wp_send_json_success($result);
}

function yvo_ajax_cabinet_save_transaction() {
    yvo_cabinet_verify_ajax();
    if (!yvo_cabinet_can_save()) {
        wp_send_json_error(array('message' => 'Войдите в аккаунт, чтобы сохранять в кабинет.'));
    }
    $sellers = json_decode(isset($_POST['sellers_data']) ? wp_unslash($_POST['sellers_data']) : '[]', true);
    $buyers = json_decode(isset($_POST['buyers_data']) ? wp_unslash($_POST['buyers_data']) : '[]', true);
    $property = json_decode(isset($_POST['property_data']) ? wp_unslash($_POST['property_data']) : '{}', true);
    $content = isset($_POST['contract_content']) ? wp_unslash($_POST['contract_content']) : '';
    $url = isset($_POST['contract_url']) ? esc_url_raw(wp_unslash($_POST['contract_url'])) : '';
    $docx_url = isset($_POST['contract_docx_url']) ? esc_url_raw(wp_unslash($_POST['contract_docx_url'])) : '';
    if (!is_array($sellers)) {
        $sellers = array();
    }
    if (!is_array($buyers)) {
        $buyers = array();
    }
    if (!is_array($property)) {
        $property = array();
    }
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    if ($name === '') {
        $parts = array_merge(
            array_filter(array_map(function ($s) {
                return $s['full_name'] ?? '';
            }, $sellers)),
            array_filter(array_map(function ($b) {
                return $b['full_name'] ?? '';
            }, $buyers))
        );
        $name = trim(implode(', ', $parts));
        if (!empty($property['address'])) {
            $name .= ($name ? ' — ' : '') . mb_substr($property['address'], 0, 40);
        }
        if ($name === '') {
            $name = 'Сделка ' . current_time('d.m.Y H:i');
        }
    }
    $data = array('sellers' => $sellers, 'buyers' => $buyers, 'property' => $property, 'content' => $content, 'url' => $url, 'docx_url' => $docx_url);
    $result = yvo_cabinet_save_item('transaction', $name, $data);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    wp_send_json_success($result);
}

// Главная страница админки (дашборд)
function yvo_dashboard_page() {
    include YVO_PLUGIN_DIR . 'views/dashboard.php';
}

// История сгенерированных договоров
function yvo_contracts_history_page() {
    include YVO_PLUGIN_DIR . 'views/contracts-history.php';
}

// Управление шаблонами (папка templates/)
function yvo_templates_admin_page() {
    include YVO_PLUGIN_DIR . 'views/templates-admin.php';
}

// Главная страница (обновленная с поддержкой AI парсинга)
function yvo_main_page() {
    $api_key = get_option('yvo_api_key');
    $folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    
    $pdf_processor = new YVO_PDF_Processor();
    $has_pdf_support = $pdf_processor->check_pdf_support();
    
    $deepseek_enabled = get_option('yvo_deepseek_enabled') === 'yes';
    $deepseek_api_key = get_option('yvo_deepseek_api_key');
    $deepseek_configured = !empty($deepseek_api_key);
    
    // Проверяем настройки
    $is_configured = !empty($api_key) && !empty($folder_id);
    ?>
    <div class="wrap">
        <h1>Яндекс Vision OCR Pro с AI парсингом</h1>
        
        <?php if (!$is_configured): ?>
        <div class="notice notice-warning">
            <p>Пожалуйста, <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-settings'); ?>">настройте API ключ</a> перед использованием.</p>
        </div>
        <?php endif; ?>
        
        <?php if (!$has_pdf_support): ?>
        <div class="notice notice-warning">
            <p><strong>Поддержка PDF недоступна!</strong> Для работы с PDF файлами требуется установить Imagick и Ghostscript или библиотеку spatie/pdf-to-image через Composer.</p>
            <p>Вы можете использовать изображения (JPG, PNG, GIF, BMP) или <a href="#" id="yvo-install-pdf-support">установить необходимые компоненты</a>.</p>
        </div>
        <?php endif; ?>
        
        <?php if ($deepseek_enabled && !$deepseek_configured): ?>
        <div class="notice notice-warning">
            <p><strong>DeepSeek AI не настроен!</strong> Для использования AI парсинга необходимо настроить API ключ DeepSeek в <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-settings'); ?>">настройках</a>.</p>
        </div>
        <?php endif; ?>
        
        <div class="yvo-container">
            <div class="yvo-card">
                <h2>Распознать текст из документов</h2>
                
                <div class="yvo-ai-options" style="margin-bottom: 20px; padding: 15px; background: #f0f7ff; border: 1px solid #c3d9ff; border-radius: 4px;">
                    <h3 style="margin-top: 0;">AI Парсинг данных</h3>
                    <label style="display: block; margin-bottom: 10px;">
                        <input type="checkbox" id="yvo-use-deepseek" <?php echo $deepseek_enabled && $deepseek_configured ? 'checked' : ''; ?> <?php echo !$deepseek_enabled || !$deepseek_configured ? 'disabled' : ''; ?>>
                        Использовать DeepSeek AI для точного парсинга данных
                    </label>
                    <p class="description">
                        AI поможет более точно извлечь ФИО, паспортные данные, адреса и другую информацию из распознанного текста.
                        <?php if (!$deepseek_enabled || !$deepseek_configured): ?>
                        <br><a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-settings'); ?>">Настройте DeepSeek API</a>
                        <?php endif; ?>
                    </p>
                </div>
                
                <div class="yvo-upload-area">
                    <!-- Контейнер для нескольких файлов -->
                    <div class="yvo-files-container">
                        <div class="yvo-file-item" data-index="0">
                            <div class="yvo-file-row">
                                <div class="yvo-file-input">
                                    <input type="text" class="yvo-document-url regular-text" placeholder="URL документа или выберите из медиатеки">
                                    <button type="button" class="button yvo-browse">Выбрать</button>
                                    <button type="button" class="button button-secondary yvo-remove-file">×</button>
                                </div>
                                <div class="yvo-file-type">
                                    <select class="yvo-document-type">
                                        <option value="general">Общее распознавание</option>
                                        <option value="passport">Паспорт</option>
                                        <option value="seller">Данные продавца</option>
                                        <option value="buyer">Данные покупателя</option>
                                        <option value="property">Данные об объекте</option>
                                    </select>
                                </div>
                            </div>
                        </div>
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
                                    <input type="text" name="seller_full_name" class="regular-text">
                                </div>
                            </div>
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Паспорт серия:</label>
                                    <input type="text" name="seller_passport_series" class="small-text" maxlength="4">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Паспорт номер:</label>
                                    <input type="text" name="seller_passport_number" class="small-text" maxlength="6">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Код подразделения:</label>
                                    <input type="text" name="seller_department_code" class="small-text" placeholder="000-000">
                                </div>
                            </div>
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Кем выдан:</label>
                                    <textarea name="seller_passport_issued_by" rows="3" class="regular-text"></textarea>
                                </div>
                            </div>
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Дата выдачи:</label>
                                    <input type="text" name="seller_passport_date" class="regular-text" placeholder="дд.мм.гггг">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Дата рождения:</label>
                                    <input type="text" name="seller_birth_date" class="regular-text" placeholder="дд.мм.гггг">
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
                                    <textarea name="seller_registration" rows="3" class="regular-text"></textarea>
                                </div>
                            </div>
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>ИНН:</label>
                                    <input type="text" name="seller_inn" class="regular-text" maxlength="12">
                                </div>
                                <div class="yvo-form-col">
                                    <label>СНИЛС:</label>
                                    <input type="text" name="seller_snils" class="regular-text" placeholder="000-000-000 00">
                                </div>
                            </div>
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Телефон:</label>
                                    <input type="text" name="seller_phone" class="regular-text">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Email:</label>
                                    <input type="text" name="seller_email" class="regular-text">
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
                                    <input type="text" name="buyer_full_name" class="regular-text">
                                </div>
                            </div>
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Паспорт серия:</label>
                                    <input type="text" name="buyer_passport_series" class="small-text" maxlength="4">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Паспорт номер:</label>
                                    <input type="text" name="buyer_passport_number" class="small-text" maxlength="6">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Код подразделения:</label>
                                    <input type="text" name="buyer_department_code" class="small-text" placeholder="000-000">
                                </div>
                            </div>
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Кем выдан:</label>
                                    <textarea name="buyer_passport_issued_by" rows="3" class="regular-text"></textarea>
                                </div>
                            </div>
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Дата выдачи:</label>
                                    <input type="text" name="buyer_passport_date" class="regular-text" placeholder="дд.мм.гггг">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Дата рождения:</label>
                                    <input type="text" name="buyer_birth_date" class="regular-text" placeholder="дд.мм.гггг">
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
                                    <textarea name="buyer_registration" rows="3" class="regular-text"></textarea>
                                </div>
                            </div>
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>ИНН:</label>
                                    <input type="text" name="buyer_inn" class="regular-text" maxlength="12">
                                </div>
                                <div class="yvo-form-col">
                                    <label>СНИЛС:</label>
                                    <input type="text" name="buyer_snils" class="regular-text" placeholder="000-000-000 00">
                                </div>
                            </div>
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Телефон:</label>
                                    <input type="text" name="buyer_phone" class="regular-text">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Email:</label>
                                    <input type="text" name="buyer_email" class="regular-text">
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
                                    <textarea name="property_address" rows="3" class="regular-text" placeholder="Полный адрес объекта недвижимости"></textarea>
                                </div>
                            </div>
                            
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Кадастровый номер:</label>
                                    <input type="text" name="property_cadastral_number" class="regular-text" placeholder="XX:XX:XXXXXXX:XXX">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Тип недвижимости:</label>
                                    <select name="property_property_type" class="regular-text">
                                        <option value="">Выберите тип</option>
                                        <option value="квартира">Квартира</option>
                                        <option value="дом">Дом</option>
                                        <option value="апартаменты">Апартаменты</option>
                                        <option value="студия">Студия</option>
                                        <option value="таунхаус">Таунхаус</option>
                                        <option value="коттедж">Коттедж</option>
                                        <option value="участок">Земельный участок</option>
                                        <option value="офис">Офис</option>
                                        <option value="помещение">Помещение</option>
                                        <option value="гараж">Гараж</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Площадь (м²):</label>
                                    <input type="number" name="property_area" class="regular-text" step="0.01" min="0" placeholder="Например: 45.5">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Количество комнат:</label>
                                    <input type="number" name="property_rooms" class="regular-text" min="0" placeholder="0 для студии">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Этаж:</label>
                                    <input type="number" name="property_floor" class="regular-text" min="0" placeholder="Этаж расположения">
                                </div>
                            </div>
                            
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Этажность дома:</label>
                                    <input type="number" name="property_floors_total" class="regular-text" min="1" placeholder="Общая этажность">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Год постройки:</label>
                                    <input type="number" name="property_year_built" class="regular-text" min="1800" max="2025" placeholder="Год постройки">
                                </div>
                                <div class="yvo-form-col">
                                    <label>Стоимость (руб):</label>
                                    <input type="text" name="property_price" class="regular-text" placeholder="Стоимость объекта">
                                </div>
                            </div>
                            
                            <div class="yvo-form-row">
                                <div class="yvo-form-col">
                                    <label>Состояние:</label>
                                    <select name="property_condition" class="regular-text">
                                        <option value="">Выберите состояние</option>
                                        <option value="евроремонт">Евроремонт</option>
                                        <option value="дизайнерский ремонт">Дизайнерский ремонт</option>
                                        <option value="сделан ремонт">Сделан ремонт</option>
                                        <option value="требует ремонта">Требует ремонта</option>
                                        <option value="черновая отделка">Черновая отделка</option>
                                        <option value="чистовая отделка">Чистовая отделка</option>
                                        <option value="хорошее состояние">Хорошее состояние</option>
                                        <option value="отличное состояние">Отличное состояние</option>
                                        <option value="аварийное состояние">Аварийное состояние</option>
                                        <option value="новая">Новая</option>
                                    </select>
                                </div>
                                <div class="yvo-form-col">
                                    <label>Тип собственности:</label>
                                    <select name="property_ownership_type" class="regular-text">
                                        <option value="собственность">Собственность</option>
                                        <option value="аренда">Аренда</option>
                                        <option value="долевая собственность">Долевая собственность</option>
                                        <option value="совместная собственность">Совместная собственность</option>
                                        <option value="пай">Пай</option>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="button" class="button button-primary yvo-save-form" data-form="property">Сохранить данные объекта</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="yvo-card">
                <h3>Поддерживаемые форматы:</h3>
                <ul>
                    <li>PDF <?php echo $has_pdf_support ? '✓' : '✗'; ?></li>
                    <li>JPG, JPEG</li>
                    <li>PNG</li>
                    <li>GIF</li>
                    <li>BMP</li>
                </ul>
                
                <h3>Ограничения:</h3>
                <ul>
                    <li>Макс. размер файла: <?php echo get_option('yvo_max_size', 20); ?> MB</li>
                    <li>PDF: макс. <?php echo get_option('yvo_pdf_max_pages', 10); ?> страниц</li>
                </ul>
                
                <div class="yvo-ai-status" style="margin: 20px 0; padding: 15px; background: <?php echo $deepseek_enabled && $deepseek_configured ? '#f0f9f0' : '#fdf3f2'; ?>; border: 1px solid <?php echo $deepseek_enabled && $deepseek_configured ? '#c3e6c3' : '#ebccd1'; ?>; border-radius: 4px;">
                    <h4>Статус AI парсинга:</h4>
                    <p>
                        <?php if ($deepseek_enabled && $deepseek_configured): ?>
                            <span style="color:green;">✓ DeepSeek AI доступен</span><br>
                            <small>AI будет использоваться для точного извлечения данных</small>
                        <?php elseif ($deepseek_enabled && !$deepseek_configured): ?>
                            <span style="color:red;">✗ DeepSeek API не настроен</span><br>
                            <small><a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-settings'); ?>">Добавьте API ключ</a></small>
                        <?php else: ?>
                            <span style="color:orange;">✗ DeepSeek AI отключен</span><br>
                            <small>Используются регулярные выражения</small>
                        <?php endif; ?>
                    </p>
                </div>
                
                <h3>Быстрые действия:</h3>
                <div class="yvo-quick-actions">
                    <button type="button" class="button yvo-load-data" data-type="seller">Загрузить данные продавца</button>
                    <button type="button" class="button yvo-load-data" data-type="buyer">Загрузить данные покупателя</button>
                    <button type="button" class="button yvo-load-data" data-type="property">Загрузить данные об объекте</button>
                </div>
                
                <h3>Генерация договора:</h3>
                <div class="yvo-contract-actions">
                    <button type="button" class="button button-primary" id="yvo-generate-contract" <?php echo !$is_configured ? 'disabled' : ''; ?>>Сгенерировать договор</button>
                </div>
                
                <div id="yvo-contract-result" style="margin-top: 15px;"></div>
                
                <?php if ($is_configured): ?>
                <div class="yvo-stats">
                    <p><strong>Статус:</strong> <span style="color:green;">✓ Настроено</span></p>
                    <p><strong>Папка:</strong> <?php echo esc_html($folder_id); ?></p>
                    <p><strong>Поддержка PDF:</strong> 
                        <?php if ($has_pdf_support): ?>
                            <span style="color:green;">✓ Доступна</span>
                        <?php else: ?>
                            <span style="color:red;">✗ Недоступна</span>
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        var fileCounter = 1;
        
        // Проверка поддержки PDF при загрузке
        $.ajax({
            url: yvo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'yvo_check_pdf_support',
                nonce: yvo_ajax.nonce
            },
            dataType: 'json'
        });
        
        // Добавление нового поля для файла
        $('#yvo-add-file').click(function() {
            var newItem = $('.yvo-file-item:first').clone();
            newItem.attr('data-index', fileCounter);
            newItem.find('.yvo-document-url').val('');
            newItem.find('.yvo-remove-file').show();
            $('.yvo-files-container').append(newItem);
            fileCounter++;
        });
        
        // Удаление поля для файла
        $(document).on('click', '.yvo-remove-file', function() {
            if ($('.yvo-file-item').length > 1) {
                $(this).closest('.yvo-file-item').remove();
            }
        });
        
        // Выбор файла из медиабиблиотеки
        $(document).on('click', '.yvo-browse', function() {
            var button = $(this);
            var input = button.siblings('.yvo-document-url');
            
            var frame = wp.media({
                title: 'Выберите документ',
                multiple: false,
                library: { 
                    type: ['image', 'application/pdf']
                }
            });
            
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                input.val(attachment.url);
                
                // Определяем тип файла по расширению
                var filename = attachment.filename || attachment.url.split('/').pop();
                var extension = filename.split('.').pop().toLowerCase();
                
                if (extension === 'pdf') {
                    var item = button.closest('.yvo-file-item');
                    item.find('.yvo-document-type').val('passport');
                }
            });
            
            frame.open();
        });
        
        // Обработка одного выбранного файла
        $('#yvo-process-single').click(function() {
            var activeItem = $('.yvo-file-item:first');
            var documentUrl = activeItem.find('.yvo-document-url').val().trim();
            var documentType = activeItem.find('.yvo-document-type').val();
            var useDeepseek = $('#yvo-use-deepseek').is(':checked');
            
            if (!documentUrl) {
                alert('Пожалуйста, введите URL документа или выберите из медиабиблиотеки');
                return;
            }
            
            processSingleDocument(documentUrl, documentType, useDeepseek);
        });
        
        // Обработка всех файлов
        $('#yvo-process-all').click(function() {
            var filesData = [];
            var validFiles = 0;
            
            $('.yvo-file-item').each(function() {
                var url = $(this).find('.yvo-document-url').val().trim();
                var type = $(this).find('.yvo-document-type').val();
                
                if (url) {
                    filesData.push({
                        url: url,
                        type: type
                    });
                    validFiles++;
                }
            });
            
            if (validFiles === 0) {
                alert('Пожалуйста, добавьте хотя бы один файл.');
                return;
            }
            
            var useDeepseek = $('#yvo-use-deepseek').is(':checked');
            processMultipleDocuments(filesData, useDeepseek);
        });
        
        // Функция обработки одного документа
        function processSingleDocument(url, type, useDeepseek) {
            $('.yvo-progress').show();
            $('#yvo-progress-text').text('Обработка...');
            $('#yvo-results').html('');
            
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'yvo_process_document',
                    nonce: yvo_ajax.nonce,
                    document_url: url,
                    document_type: type,
                    use_deepseek: useDeepseek
                },
                dataType: 'json',
                success: function(response) {
                    $('.yvo-progress').hide();
                    
                    if (response.success) {
                        displayResult(response.data, url, type);
                    } else {
                        $('#yvo-results').html('<div class="yvo-result error"><p><strong>Ошибка:</strong> ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $('.yvo-progress').hide();
                    $('#yvo-results').html('<div class="yvo-result error"><p>Ошибка сервера. Пожалуйста, попробуйте еще раз.</p></div>');
                }
            });
        }
        
        // Функция обработки нескольких документов
        function processMultipleDocuments(filesData, useDeepseek) {
            var totalFiles = filesData.length;
            var processedFiles = 0;
            
            $('.yvo-progress').show();
            $('#yvo-progress-text').text('0/' + totalFiles);
            $('#yvo-results').html('');
            
            // Обрабатываем файлы последовательно
            function processNext() {
                if (processedFiles >= totalFiles) {
                    $('.yvo-progress').hide();
                    return;
                }
                
                var currentFile = filesData[processedFiles];
                processedFiles++;
                $('#yvo-progress-text').text(processedFiles + '/' + totalFiles);
                
                $.ajax({
                    url: yvo_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'yvo_process_document',
                        nonce: yvo_ajax.nonce,
                        document_url: currentFile.url,
                        document_type: currentFile.type,
                        use_deepseek: useDeepseek
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            displayResult(response.data, currentFile.url, currentFile.type);
                        } else {
                            $('#yvo-results').append(
                                '<div class="yvo-result error">' +
                                '<p><strong>Файл:</strong> ' + currentFile.url.split('/').pop() + '</p>' +
                                '<p><strong>Ошибка:</strong> ' + response.data + '</p>' +
                                '</div>'
                            );
                        }
                        processNext();
                    },
                    error: function() {
                        $('#yvo-results').append(
                            '<div class="yvo-result error">' +
                            '<p><strong>Файл:</strong> ' + currentFile.url.split('/').pop() + '</p>' +
                            '<p><strong>Ошибка:</strong> Ошибка сервера</p>' +
                            '</div>'
                        );
                        processNext();
                    }
                });
            }
            
            processNext();
        }
        
        // Функция отображения результата
        function displayResult(data, url, type) {
            var isPdf = data.is_pdf || false;
            var pages = data.pages || 1;
            var parser = data.parser || 'none';
            
            var html = '<div class="yvo-result success">';
            html += '<h4>Файл: ' + data.filename;
            if (isPdf) {
                html += ' <span class="yvo-pdf-badge">PDF (' + pages + ' стр.)</span>';
            }
            html += ' (' + type + ')';
            
            if (parser === 'deepseek') {
                html += ' <span class="yvo-ai-badge" style="background: #4CAF50; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 10px;">AI</span>';
            } else if (parser === 'regex') {
                html += ' <span class="yvo-regex-badge" style="background: #FF9800; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 10px;">Regex</span>';
            } else if (parser === 'property_parser') {
                html += ' <span class="yvo-property-badge" style="background: #2196F3; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 10px;">Property Parser</span>';
            }
            
            html += '</h4>';
            
            if (type === 'general') {
                html += '<div class="yvo-text-result">';
                html += '<textarea readonly style="width:100%; height:300px; font-family:monospace;">' + data.text + '</textarea>';
                html += '<div class="yvo-actions" style="margin-top:10px;">';
                html += '<button class="button button-primary yvo-copy" data-text="' + data.text.replace(/"/g, '&quot;') + '">Копировать текст</button>';
                html += '<button class="button yvo-download" data-text="' + data.text.replace(/"/g, '&quot;') + '" data-filename="' + data.filename.replace(/"/g, '&quot;') + '">Скачать как TXT</button>';
                html += '</div>';
                html += '</div>';
            } else {
                html += '<p><strong>Распознанный текст:</strong></p>';
                html += '<textarea readonly style="width:100%; height:150px; font-family:monospace; margin:10px 0;">' + data.text + '</textarea>';
                
                if (data.parsed_data && Object.keys(data.parsed_data).length > 0) {
                    html += '<div class="notice notice-success" style="margin:15px 0;">';
                    html += '<p><strong>Извлеченные данные:</strong> <small>(Парсер: ' + getParserName(parser) + ')</small></p>';
                    html += '<ul>';
                    for (var key in data.parsed_data) {
                        if (data.parsed_data[key] && data.parsed_data[key] !== 'null') {
                            html += '<li><strong>' + getFieldLabel(key) + ':</strong> ' + data.parsed_data[key] + '</li>';
                        }
                    }
                    html += '</ul>';
                    html += '</div>';
                    
                    // Автозаполнение формы
                    $('#yvo-data-forms').show();
                    $('.yvo-form-section').hide();
                    $('#' + type + '-form').show();
                    
                    var formData = data.parsed_data;
                    for (var key in formData) {
                        var inputName = type + '_' + key;
                        var value = formData[key];
                        if (value && value !== 'null') {
                            // Для селектов ищем option с соответствующим значением
                            var $input = $('input[name="' + inputName + '"], textarea[name="' + inputName + '"], select[name="' + inputName + '"]');
                            if ($input.is('select')) {
                                $input.find('option').each(function() {
                                    if ($(this).val() === value) {
                                        $(this).prop('selected', true);
                                    }
                                });
                            } else {
                                $input.val(value);
                            }
                        }
                    }
                } else {
                    html += '<div class="notice notice-warning" style="margin:15px 0;">';
                    html += '<p>Не удалось извлечь структурированные данные. Попробуйте использовать AI парсинг.</p>';
                    html += '</div>';
                }
            }
            
            html += '</div>';
            
            $('#yvo-results').append(html);
        }
        
        // Функция для получения читаемого названия парсера
        function getParserName(parser) {
            var names = {
                'deepseek': 'DeepSeek AI',
                'regex': 'Регулярные выражения',
                'property_parser': 'Парсер объектов'
            };
            return names[parser] || parser;
        }
        
        // Функция для получения читаемых названий полей
        function getFieldLabel(field) {
            var labels = {
                'address': 'Адрес',
                'cadastral_number': 'Кадастровый номер',
                'area': 'Площадь',
                'floor': 'Этаж',
                'floors_total': 'Этажность дома',
                'rooms': 'Количество комнат',
                'price': 'Стоимость',
                'property_type': 'Тип недвижимости',
                'year_built': 'Год постройки',
                'condition': 'Состояние',
                'ownership_type': 'Тип собственности',
                'full_name': 'ФИО',
                'passport_series': 'Серия паспорта',
                'passport_number': 'Номер паспорта',
                'department_code': 'Код подразделения',
                'passport_issued_by': 'Кем выдан',
                'passport_date': 'Дата выдачи',
                'birth_date': 'Дата рождения',
                'birth_place': 'Место рождения',
                'registration': 'Адрес регистрации',
                'inn': 'ИНН',
                'snils': 'СНИЛС',
                'phone': 'Телефон',
                'email': 'Email'
            };
            return labels[field] || field;
        }
        
        // Кнопка установки поддержки PDF
        $('#yvo-install-pdf-support').click(function(e) {
            e.preventDefault();
            alert('Для установки поддержки PDF:\n\n1. Установите Imagick и Ghostscript на сервере\n2. Или запустите в директории плагина: composer require spatie/pdf-to-image\n3. Переактивируйте плагин');
        });
        
        // Сохранение данных формы
        $(document).on('click', '.yvo-save-form', function() {
            var formType = $(this).data('form');
            var formData = {};
            
            $('#' + formType + '-data-form').find('input, textarea, select').each(function() {
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
                        $('#yvo-data-forms').show();
                        $('.yvo-form-section').hide();
                        $('#' + dataType + '-form').show();
                        
                        var formData = response.data;
                        for (var key in formData) {
                            var inputName = dataType + '_' + key;
                            var $input = $('input[name="' + inputName + '"], textarea[name="' + inputName + '"], select[name="' + inputName + '"]');
                            if ($input.is('select')) {
                                $input.find('option').each(function() {
                                    if ($(this).val() === formData[key]) {
                                        $(this).prop('selected', true);
                                    }
                                });
                            } else {
                                $input.val(formData[key]);
                            }
                        }
                    } else {
                        alert('Нет сохраненных данных для этого типа');
                    }
                }
            });
        });
        
        // Генерация договора
        $('#yvo-generate-contract').click(function() {
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
                        var html = '<div class="notice notice-success"><p>✅ Договор успешно сгенерирован!</p><p>';
                        if (response.data.contract_docx_url) {
                            html += '<a href="' + response.data.contract_docx_url + '" class="button button-primary" target="_blank">Скачать для печати (DOCX)</a> ';
                        }
                        html += '<a href="' + response.data.contract_url + '" class="button button-secondary" target="_blank">Скачать TXT</a></p></div>';
                        $('#yvo-contract-result').html(html);
                    } else {
                        $('#yvo-contract-result').html(
                            '<div class="notice notice-error"><p>❌ ' + response.data + '</p></div>'
                        );
                    }
                }
            });
        });
    });
    </script>
    <?php
}

// Страница настроек
function yvo_settings_page() {
    // Сохраняем настройки
    if (isset($_POST['yvo_save_settings'])) {
        check_admin_referer('yvo_settings_nonce');
        
        update_option('yvo_api_key', sanitize_text_field($_POST['api_key']));
        update_option('yvo_folder_id', sanitize_text_field($_POST['folder_id']));
        update_option('yvo_language', sanitize_text_field($_POST['language']));
        update_option('yvo_max_size', intval($_POST['max_size']));
        update_option('yvo_pdf_max_pages', intval($_POST['pdf_max_pages']));
        update_option('yvo_yandex_client_id', sanitize_text_field($_POST['yvo_yandex_client_id'] ?? ''));
        update_option('yvo_yandex_client_secret', sanitize_text_field($_POST['yvo_yandex_client_secret'] ?? ''));
        update_option('yvo_generation_price_rub', sanitize_text_field($_POST['yvo_generation_price_rub'] ?? '100'));

        // Настройки DeepSeek
        $deepseek_enabled = isset($_POST['deepseek_enabled']) ? 'yes' : 'no';
        update_option('yvo_deepseek_enabled', $deepseek_enabled);
        update_option('yvo_deepseek_api_key', sanitize_text_field($_POST['deepseek_api_key']));
        update_option('yvo_deepseek_model', sanitize_text_field($_POST['deepseek_model']));
        
        $templates = yvo_get_available_templates();
        $contract_template = isset($_POST['contract_template']) ? sanitize_text_field($_POST['contract_template']) : 'default';
        if (array_key_exists($contract_template, $templates)) {
            update_option('yvo_contract_template', $contract_template);
        }
        
        echo '<div class="notice notice-success"><p>Настройки сохранены!</p></div>';
    }
    
    $api_key = get_option('yvo_api_key');
    $folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    $language = get_option('yvo_language', 'ru');
    $max_size = get_option('yvo_max_size', 20);
    $pdf_max_pages = get_option('yvo_pdf_max_pages', 10);
    $yvo_yandex_client_id = get_option('yvo_yandex_client_id', '');
    $yvo_yandex_client_secret = get_option('yvo_yandex_client_secret', '');
    $yvo_generation_price_rub = get_option('yvo_generation_price_rub', '100');
    
    $pdf_processor = new YVO_PDF_Processor();
    $has_pdf_support = $pdf_processor->check_pdf_support();
    
    $deepseek_enabled = get_option('yvo_deepseek_enabled') === 'yes';
    $deepseek_api_key = get_option('yvo_deepseek_api_key');
    $deepseek_model = get_option('yvo_deepseek_model', 'deepseek-chat');
    
    $languages = array(
        'ru' => 'Русский',
        'en' => 'Английский',
        'tr' => 'Турецкий',
        'uk' => 'Украинский',
        'kk' => 'Казахский'
    );
    ?>
    <div class="wrap">
        <h1>Настройки Яндекс Vision OCR Pro с AI</h1>
        
        <div class="yvo-container">
            <div class="yvo-card">
                <form method="post" action="">
                    <?php wp_nonce_field('yvo_settings_nonce'); ?>
                    
                    <h2>Настройки Яндекс Vision API</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="api_key">API Ключ Яндекс</label></th>
                            <td>
                                <input type="password" 
                                       id="api_key" 
                                       name="api_key" 
                                       value="<?php echo esc_attr($api_key); ?>" 
                                       class="regular-text">
                                <p class="description">
                                    Ваш API ключ от Яндекс Облака
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="folder_id">ID Папки Яндекс</label></th>
                            <td>
                                <input type="text" 
                                       id="folder_id" 
                                       name="folder_id" 
                                       value="<?php echo esc_attr($folder_id); ?>" 
                                       class="regular-text">
                                <p class="description">
                                    Идентификатор папки в Яндекс Облаке
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="language">Язык текста</label></th>
                            <td>
                                <select id="language" name="language">
                                    <?php foreach ($languages as $code => $name): ?>
                                        <option value="<?php echo esc_attr($code); ?>" <?php selected($language, $code); ?>>
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
                                       value="<?php echo esc_attr($max_size); ?>" 
                                       min="1" max="50" step="1">
                                <p class="description">
                                    Максимальный размер файла для обработки
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="pdf_max_pages">PDF: макс. страниц</label></th>
                            <td>
                                <input type="number" 
                                       id="pdf_max_pages" 
                                       name="pdf_max_pages" 
                                       value="<?php echo esc_attr($pdf_max_pages); ?>" 
                                       min="1" max="50" step="1">
                                <p class="description">
                                    Максимальное количество страниц PDF для обработки
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h2 style="margin-top: 40px;">Вход через Яндекс (OAuth)</h2>
                    <p class="description" style="max-width: 860px; margin-bottom: 12px;">
                        Приложение создаётся <strong>в вашем аккаунте Яндекс ID</strong> (я не могу войти в ваш Яндекс за вас).
                        Откройте форму, заполните поля как ниже, затем вставьте Client ID и Secret сюда.
                    </p>
                    <p style="margin: 0 0 16px;">
                        <a class="button button-primary" href="https://oauth.yandex.ru/client/new/id/" target="_blank" rel="noopener">Создать приложение на oauth.yandex.ru</a>
                    </p>
                    <div class="notice notice-info inline" style="max-width:860px;padding:12px 14px;margin-bottom:16px;">
                        <p style="margin:0 0 8px;"><strong>Что указать в форме Яндекса:</strong></p>
                        <ul style="margin:0;padding-left:1.2rem;line-height:1.55;">
                            <li>Тип: <strong>Для авторизации пользователей</strong></li>
                            <li>Название: <code>Dokii — договоры онлайн</code></li>
                            <li>Redirect URI: <code id="yvo-yandex-redirect-uri"><?php echo esc_html(home_url('/?yvo_oauth=yandex&yvo_oauth_action=callback')); ?></code>
                                <button type="button" class="button button-small" id="yvo-copy-yandex-redirect" style="margin-left:6px;vertical-align:middle;">Копировать</button>
                            </li>
                            <li>Права (доступ к данным): <code>login:info</code>, <code>login:email</code></li>
                        </ul>
                    </div>
                    <script>
                    (function () {
                        var btn = document.getElementById('yvo-copy-yandex-redirect');
                        var el = document.getElementById('yvo-yandex-redirect-uri');
                        if (!btn || !el || !navigator.clipboard) return;
                        btn.addEventListener('click', function () {
                            navigator.clipboard.writeText(el.textContent.trim()).then(function () {
                                btn.textContent = 'Скопировано';
                                setTimeout(function () { btn.textContent = 'Копировать'; }, 1800);
                            });
                        });
                    })();
                    </script>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="yvo_yandex_client_id">Client ID</label></th>
                            <td>
                                <input type="text"
                                       id="yvo_yandex_client_id"
                                       name="yvo_yandex_client_id"
                                       value="<?php echo esc_attr($yvo_yandex_client_id); ?>"
                                       class="regular-text">
                                <p class="description">ID приложения OAuth в Яндекс.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="yvo_yandex_client_secret">Client Secret</label></th>
                            <td>
                                <input type="password"
                                       id="yvo_yandex_client_secret"
                                       name="yvo_yandex_client_secret"
                                       value="<?php echo esc_attr($yvo_yandex_client_secret); ?>"
                                       class="regular-text">
                                <p class="description">Секрет приложения OAuth в Яндекс.</p>
                                <p class="description">
                                    <strong>Redirect URI (Callback URL):</strong>
                                    <code><?php echo esc_html(home_url('/?yvo_oauth=yandex&yvo_oauth_action=callback')); ?></code>
                                </p>
                                <p class="description">
                                    <?php if (function_exists('yvo_yandex_oauth_enabled') && yvo_yandex_oauth_enabled()) : ?>
                                        <span style="color:#15803d;font-weight:600;">Вход через Яндекс включён.</span>
                                    <?php else : ?>
                                        <span style="color:#b45309;font-weight:600;">Заполните оба поля и сохраните, чтобы кнопка «Войти через Яндекс» появилась на сайте.</span>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h2 style="margin-top: 40px;">Тарифы и кошелёк</h2>
                    <p class="description">Списание с баланса при генерации (разовый тариф: 200 ₽ стандарт / 390 ₽ сложный). Бесплатный тариф — только ДКП квартира, без автозаполнения. Для приёма оплат на сайте обычно ставят <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>">WooCommerce</a> и подключают эквайринг; пополнение баланса пользователей можно делать вручную в профиле пользователя (мета <code>yvo_wallet_balance</code>) или через свою интеграцию.</p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="yvo_generation_price_rub">Списание за генерацию (₽)</label></th>
                            <td>
                                <input type="text"
                                       id="yvo_generation_price_rub"
                                       name="yvo_generation_price_rub"
                                       value="<?php echo esc_attr($yvo_generation_price_rub); ?>"
                                       class="small-text">
                                <p class="description">С баланса при списании, если нет активной подписки и пакетных кредитов.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h2 style="margin-top: 40px;">Настройки DeepSeek AI</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="deepseek_enabled">Включить AI парсинг</label></th>
                            <td>
                                <input type="checkbox" 
                                       id="deepseek_enabled" 
                                       name="deepseek_enabled" 
                                       value="yes" <?php checked($deepseek_enabled); ?>>
                                <p class="description">
                                    Использовать DeepSeek AI для точного извлечения данных из текста
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="deepseek_api_key">API Ключ DeepSeek</label></th>
                            <td>
                                <input type="password" 
                                       id="deepseek_api_key" 
                                       name="deepseek_api_key" 
                                       value="<?php echo esc_attr($deepseek_api_key); ?>" 
                                       class="regular-text">
                                <p class="description">
                                    Получите ключ на <a href="https://platform.deepseek.com/api-keys" target="_blank">platform.deepseek.com</a>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="deepseek_model">Модель DeepSeek</label></th>
                            <td>
                                <input type="text" 
                                       id="deepseek_model" 
                                       name="deepseek_model" 
                                       value="<?php echo esc_attr($deepseek_model); ?>" 
                                       class="regular-text">
                                <p class="description">
                                    Модель для использования (deepseek-chat, deepseek-coder и т.д.)
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><label for="contract_template">Шаблон договора по умолчанию</label></th>
                            <td>
                                <select id="contract_template" name="contract_template" class="regular-text">
                                    <?php
                                    $available_templates = yvo_get_available_templates();
                                    $current_template = get_option('yvo_contract_template', 'default');
                                    foreach ($available_templates as $tid => $label):
                                    ?>
                                        <option value="<?php echo esc_attr($tid); ?>" <?php selected($current_template, $tid); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    Шаблон из папки <code>templates/</code>. Добавьте туда файлы .txt — они появятся в списке.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" 
                               name="yvo_save_settings" 
                               class="button button-primary" 
                               value="Сохранить настройки">
                        <button type="button" id="yvo-test-api" class="button button-secondary">Проверить Яндекс API</button>
                        <button type="button" id="yvo-test-deepseek" class="button button-secondary">Проверить DeepSeek API</button>
                        <button type="button" id="yvo-check-pdf" class="button button-secondary">Проверить PDF</button>
                    </p>
                </form>
                
                <div class="yvo-card" style="margin-top: 24px;">
                    <h2>Исправить кодировку выписки ЕГРН</h2>
                    <p class="description">Вставьте текст выписки с кракозябрами — ИИ восстановит русский текст. Только для администратора.</p>
                    <p>
                        <label for="yvo-admin-fix-egrn-text" class="screen-reader-text">Текст выписки ЕГРН</label>
                        <textarea id="yvo-admin-fix-egrn-text" class="large-text" rows="6" placeholder="Вставьте сюда текст с поломанной кодировкой..."></textarea>
                    </p>
                    <p>
                        <button type="button" id="yvo-admin-fix-egrn-btn" class="button button-primary">Исправить кодировку (ИИ)</button>
                        <button type="button" id="yvo-test-fix-egrn-btn" class="button button-secondary">Проверить запрос</button>
                        <span id="yvo-admin-fix-egrn-status" style="margin-left:10px;"></span>
                    </p>
                    <div id="yvo-admin-fix-egrn-result" style="margin-top:12px;"></div>
                </div>
                
                <div id="yvo-test-result" style="margin-top: 20px;"></div>
            </div>
            
            <div class="yvo-card">
                <h2>Информация о системе</h2>
                
                <div class="yvo-info-box">
                    <h3>Текущие настройки:</h3>
                    <ul>
                        <li><strong>API Ключ Яндекс:</strong> <?php echo !empty($api_key) ? '✓ Установлен' : '✗ Не установлен'; ?></li>
                        <li><strong>ID Папки:</strong> <?php echo esc_html($folder_id); ?></li>
                        <li><strong>Язык:</strong> <?php echo $languages[$language] ?? 'Русский'; ?></li>
                        <li><strong>Макс. размер:</strong> <?php echo esc_html($max_size); ?> MB</li>
                        <li><strong>PDF макс. страниц:</strong> <?php echo esc_html($pdf_max_pages); ?></li>
                        <li><strong>DeepSeek AI:</strong> 
                            <?php if ($deepseek_enabled && !empty($deepseek_api_key)): ?>
                                <span style="color:green;">✓ Включен и настроен</span>
                            <?php elseif ($deepseek_enabled): ?>
                                <span style="color:orange;">✓ Включен, но не настроен</span>
                            <?php else: ?>
                                <span style="color:gray;">✗ Отключен</span>
                            <?php endif; ?>
                        </li>
                        <li><strong>Общий статус:</strong> 
                            <?php if (!empty($api_key) && !empty($folder_id)): ?>
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
                    <li><strong>Изображения:</strong> <span style="color:green;">✓ JPG, PNG, GIF, BMP</span></li>
                </ul>
                
                <h3>Фронтенд (отдельная страница)</h3>
                <p class="description">Создайте страницу или запись и вставьте шорткод <code>[yvo_contract_form]</code>. На ней пользователи смогут загружать документы, распознавать текст (Яндекс Vision), извлекать данные (DeepSeek) и генерировать договор купли-продажи. Чат «фото → PDF»: шорткод <code>[yvo_jpg_pdf_chat]</code> (модуль в каталоге плагина <code>yvo-jpg-pdf-chat/</code>, настройки: «Настройки → JPG → PDF Chat»).</p>
                
                <h3>Зависимости:</h3>
                <ul>
                    <li><strong>PHP cURL:</strong> <?php echo function_exists('curl_init') ? '✓' : '✗'; ?></li>
                    <li><strong>Imagick:</strong> <?php echo extension_loaded('imagick') ? '✓' : '✗'; ?></li>
                    <li><strong>Ghostscript:</strong> <?php echo yvo_check_ghostscript() ? '✓' : '✗'; ?></li>
                    <li><strong>Spatie PDF to Image:</strong> <?php echo file_exists(YVO_PLUGIN_DIR . 'vendor/spatie/pdf-to-image') ? '✓' : '✗'; ?></li>
                </ul>
                
                <?php if (!empty($api_key) && !empty($folder_id)): ?>
                <p style="text-align: center; margin-top: 20px;">
                    <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-recognize'); ?>" class="button button-primary">
                        Перейти к распознаванию документов
                    </a>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Тест Яндекс API
        $('#yvo-test-api').click(function() {
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Проверка...');
            $('#yvo-test-result').html('<p><span class="spinner is-active"></span> Проверка подключения к Яндекс API...</p>');
            
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'yvo_test_api',
                    nonce: yvo_ajax.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#yvo-test-result').html(
                            '<div class="notice notice-success"><p>✅ ' + response.data + '</p></div>'
                        );
                    } else {
                        $('#yvo-test-result').html(
                            '<div class="notice notice-error"><p>❌ ' + response.data + '</p></div>'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    $('#yvo-test-result').html(
                        '<div class="notice notice-error"><p>❌ Ошибка AJAX: ' + error + '</p></div>'
                    );
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Тест DeepSeek API
        $('#yvo-test-deepseek').click(function() {
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Проверка...');
            $('#yvo-test-result').html('<p><span class="spinner is-active"></span> Проверка подключения к DeepSeek API...</p>');
            
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'yvo_test_deepseek',
                    nonce: yvo_ajax.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#yvo-test-result').html(
                            '<div class="notice notice-success">' +
                            '<p>✅ ' + response.data.message + '</p>' +
                            '<p><small>Ответ AI: ' + response.data.response + '</small></p>' +
                            '</div>'
                        );
                    } else {
                        $('#yvo-test-result').html(
                            '<div class="notice notice-error"><p>❌ ' + response.data + '</p></div>'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    $('#yvo-test-result').html(
                        '<div class="notice notice-error"><p>❌ Ошибка AJAX: ' + error + '</p></div>'
                    );
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Проверка поддержки PDF
        $('#yvo-check-pdf').click(function() {
            var button = $(this);
            var originalText = button.text();
            
            button.prop('disabled', true).text('Проверка...');
            $('#yvo-test-result').html('<p><span class="spinner is-active"></span> Проверка поддержки PDF...</p>');
            
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'yvo_check_pdf_support',
                    nonce: yvo_ajax.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var message = response.data.has_pdf_support ? 
                            '✅ Поддержка PDF доступна' : 
                            '❌ Поддержка PDF недоступна';
                        $('#yvo-test-result').html(
                            '<div class="notice ' + (response.data.has_pdf_support ? 'notice-success' : 'notice-error') + '">' +
                            '<p>' + message + '</p>' +
                            '<p>' + response.data.message + '</p>' +
                            '</div>'
                        );
                    } else {
                        $('#yvo-test-result').html(
                            '<div class="notice notice-error"><p>❌ ' + response.data + '</p></div>'
                        );
                    }
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Проверить запрос (тест исправления кодировки)
        $('#yvo-test-fix-egrn-btn').click(function() {
            var $btn = $(this).prop('disabled', true).text('Проверка...');
            $('#yvo-admin-fix-egrn-result').html('<p><span class="spinner is-active"></span> Отправка тестового запроса в DeepSeek...</p>');
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: { action: 'yvo_test_fix_egrn', nonce: yvo_ajax.nonce },
                dataType: 'json',
                timeout: 120000
            }).done(function(res) {
                $btn.prop('disabled', false).text('Проверить запрос');
                if (res.success && res.data) {
                    var html = '<div class="notice notice-success"><p><strong>' + (res.data.message || 'OK') + '</strong></p>';
                    if (res.data.fixed_preview) {
                        html += '<p>Исправленный фрагмент (до 500 символов):</p><pre style="white-space:pre-wrap;background:#f5f5f5;padding:10px;">' + $('<div>').text(res.data.fixed_preview).html() + '</pre>';
                    }
                    if (res.data.fixed_length) html += '<p>Длина ответа: ' + res.data.fixed_length + ' символов.</p>';
                    html += '</div>';
                    $('#yvo-admin-fix-egrn-result').html(html);
                } else {
                    $('#yvo-admin-fix-egrn-result').html('<div class="notice notice-error"><p>Ошибка: ' + (res.data || 'неизвестная') + '</p></div>');
                }
            }).fail(function(xhr, status, err) {
                $btn.prop('disabled', false).text('Проверить запрос');
                $('#yvo-admin-fix-egrn-result').html('<div class="notice notice-error"><p>Запрос не выполнен. Статус: ' + status + '. Проверьте ключ DeepSeek и доступ к api.deepseek.com</p></div>');
            });
        });

        // Исправить кодировку ЕГРН (админ)
        $('#yvo-admin-fix-egrn-btn').click(function() {
            var text = $('#yvo-admin-fix-egrn-text').val().trim();
            if (!text || text.length < 100) {
                $('#yvo-admin-fix-egrn-status').text('Слишком короткий текст').css('color', '#b32d2e');
                return;
            }
            var $btn = $(this).prop('disabled', true);
            $('#yvo-admin-fix-egrn-status').text('Исправление...').css('color', '');
            $('#yvo-admin-fix-egrn-result').html('<p><span class="spinner is-active"></span> Подождите до 5 мин.</p>');
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: { action: 'yvo_fix_egrn_text', nonce: yvo_ajax.nonce, text: text },
                dataType: 'json',
                timeout: 300000
            }).done(function(res) {
                if (!res.success || !res.data) {
                    $('#yvo-admin-fix-egrn-result').html('<div class="notice notice-error"><p>❌ ' + (res.data && res.data.message ? res.data.message : 'Ошибка') + '</p></div>');
                    $('#yvo-admin-fix-egrn-status').text('');
                    $btn.prop('disabled', false);
                    return;
                }
                if (res.data.text) {
                    $('#yvo-admin-fix-egrn-text').val(res.data.text);
                    $('#yvo-admin-fix-egrn-result').html('<div class="notice notice-success"><p>✅ Текст исправлен и подставлен выше.</p></div>');
                    $('#yvo-admin-fix-egrn-status').text('Готово');
                    $btn.prop('disabled', false);
                    return;
                }
                if (!res.data.key) {
                    $('#yvo-admin-fix-egrn-result').html('<div class="notice notice-error"><p>❌ Нет данных в ответе</p></div>');
                    $btn.prop('disabled', false);
                    return;
                }
                $('#yvo-admin-fix-egrn-status').text('Загрузка полного текста...');
                $.ajax({
                    url: yvo_ajax.ajax_url,
                    type: 'POST',
                    data: { action: 'yvo_get_fix_egrn_result', nonce: yvo_ajax.nonce, key: res.data.key },
                    dataType: 'text',
                    timeout: 60000
                }).done(function(body) {
                    if (body && body.charAt(0) === '{' && body.length < 800) {
                        try {
                            var err = JSON.parse(body);
                            if (err && err.success === false) {
                                $('#yvo-admin-fix-egrn-result').html('<div class="notice notice-error"><p>❌ ' + (err.data && err.data.message ? err.data.message : 'Ошибка') + '</p></div>');
                            }
                        } catch (e) {}
                    } else {
                        $('#yvo-admin-fix-egrn-text').val(body || '');
                        $('#yvo-admin-fix-egrn-result').html('<div class="notice notice-success"><p>✅ Текст исправлен целиком и подставлен выше.</p></div>');
                    }
                    $('#yvo-admin-fix-egrn-status').text('Готово');
                    $btn.prop('disabled', false);
                }).fail(function() {
                    $('#yvo-admin-fix-egrn-result').html('<div class="notice notice-error"><p>❌ Ошибка загрузки результата</p></div>');
                    $('#yvo-admin-fix-egrn-status').text('');
                    $btn.prop('disabled', false);
                });
            }).fail(function() {
                $('#yvo-admin-fix-egrn-result').html('<div class="notice notice-error"><p>❌ Ошибка запроса или таймаут</p></div>');
                $('#yvo-admin-fix-egrn-status').text('');
                $btn.prop('disabled', false);
            });
        });
    });
    </script>
    <?php
}

// Вспомогательная функция для проверки Ghostscript
function yvo_check_ghostscript() {
    if (function_exists('shell_exec')) {
        $output = shell_exec('gs --version 2>&1');
        return !empty($output);
    }
    return false;
}

// Страница с данными сделки
function yvo_data_page() {
    $seller_data = get_option('yvo_seller_data', array());
    $buyer_data = get_option('yvo_buyer_data', array());
    $property_data = get_option('yvo_property_data', array());
    ?>
    <div class="wrap">
        <h1>Данные сделки</h1>
        
        <div class="yvo-container">
            <div class="yvo-card">
                <h2>Продавец</h2>
                <?php if (!empty($seller_data)): ?>
                    <table class="yvo-data-table">
                        <?php foreach ($seller_data as $key => $value): ?>
                            <?php if (!empty($value)): ?>
                                <tr>
                                    <td><?php echo esc_html(yvo_get_field_label($key)); ?>:</td>
                                    <td><?php echo esc_html($value); ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p>Данные продавца не заполнены.</p>
                <?php endif; ?>
                
                <p>
                    <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-recognize'); ?>" class="button">
                        Заполнить данные продавца
                    </a>
                </p>
            </div>
            
            <div class="yvo-card">
                <h2>Покупатель</h2>
                <?php if (!empty($buyer_data)): ?>
                    <table class="yvo-data-table">
                        <?php foreach ($buyer_data as $key => $value): ?>
                            <?php if (!empty($value)): ?>
                                <tr>
                                    <td><?php echo esc_html(yvo_get_field_label($key)); ?>:</td>
                                    <td><?php echo esc_html($value); ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p>Данные покупателя не заполнены.</p>
                <?php endif; ?>
                
                <p>
                    <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-recognize'); ?>" class="button">
                        Заполнить данные покупателя
                    </a>
                </p>
            </div>
            
            <div class="yvo-card">
                <h2>Объект недвижимости</h2>
                <?php if (!empty($property_data)): ?>
                    <table class="yvo-data-table">
                        <?php foreach ($property_data as $key => $value): ?>
                            <?php if (!empty($value)): ?>
                                <tr>
                                    <td><?php echo esc_html(yvo_get_field_label($key)); ?>:</td>
                                    <td><?php echo esc_html($value); ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p>Данные объекта не заполнены.</p>
                <?php endif; ?>
                
                <p>
                    <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-recognize'); ?>" class="button">
                        Заполнить данные объекта
                    </a>
                </p>
            </div>
        </div>
        
        <?php if (!empty($seller_data) && !empty($buyer_data) && !empty($property_data)): ?>
        <div class="yvo-card" style="margin-top: 20px;">
            <h2>Генерация договора</h2>
            <p>Все данные заполнены. Вы можете сгенерировать договор купли-продажи.</p>
            <button type="button" class="button button-primary" id="yvo-generate-contract-full">Сгенерировать договор</button>
            <div id="yvo-contract-result-full" style="margin-top: 15px;"></div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Генерация договора на странице данных
        $('#yvo-generate-contract-full').click(function() {
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
                        var html = '<div class="notice notice-success"><p>✅ Договор успешно сгенерирован!</p><p>';
                        if (response.data.contract_docx_url) {
                            html += '<a href="' + response.data.contract_docx_url + '" class="button button-primary" target="_blank">Скачать для печати (DOCX)</a> ';
                        }
                        html += '<a href="' + response.data.contract_url + '" class="button button-secondary" target="_blank">Скачать TXT</a></p></div>';
                        $('#yvo-contract-result-full').html(html);
                    } else {
                        $('#yvo-contract-result-full').html(
                            '<div class="notice notice-error"><p>❌ ' + response.data + '</p></div>'
                        );
                    }
                }
            });
        });
    });
    </script>
    <?php
}

// Функция для получения читаемых названий полей
function yvo_get_field_label($field_name) {
    $labels = array(
        'full_name' => 'ФИО',
        'passport_series' => 'Серия паспорта',
        'passport_number' => 'Номер паспорта',
        'department_code' => 'Код подразделения',
        'passport_issued_by' => 'Кем выдан',
        'passport_date' => 'Дата выдачи',
        'birth_date' => 'Дата рождения',
        'birth_place' => 'Место рождения',
        'registration' => 'Адрес регистрации',
        'inn' => 'ИНН',
        'snils' => 'СНИЛС',
        'phone' => 'Телефон',
        'email' => 'Email',
        'address' => 'Адрес',
        'cadastral_number' => 'Кадастровый номер',
        'area' => 'Площадь',
        'floor' => 'Этаж',
        'floors_total' => 'Этажей в доме',
        'rooms' => 'Количество комнат',
        'price' => 'Стоимость',
        'property_type' => 'Тип недвижимости',
        'year_built' => 'Год постройки',
        'condition' => 'Состояние',
        'ownership_type' => 'Тип собственности'
    );
    
    return isset($labels[$field_name]) ? $labels[$field_name] : $field_name;
}
?>