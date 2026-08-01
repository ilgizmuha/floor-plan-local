<?php
if (!defined('ABSPATH')) exit;

define('YVO_META_YANDEX_ID', 'yvo_yandex_id');
define('YVO_META_YANDEX_EMAIL', 'yvo_yandex_email');

add_action('init', 'yvo_yandex_oauth_maybe_handle', 6);

add_filter('allowed_redirect_hosts', 'yvo_yandex_oauth_allowed_redirect_hosts');
function yvo_yandex_oauth_allowed_redirect_hosts($hosts) {
    $hosts[] = 'oauth.yandex.ru';
    $hosts[] = 'oauth.yandex.com';
    return $hosts;
}

add_filter('login_url', 'yvo_yandex_oauth_filter_login_url', 10, 3);
function yvo_yandex_oauth_filter_login_url($login_url, $redirect, $force_reauth) {
    if (!function_exists('yvo_cabinet_get_login_url')) {
        return $login_url;
    }
    $url = yvo_cabinet_get_login_url();
    if ($redirect) {
        $url = add_query_arg('redirect_to', urlencode($redirect), $url);
    }
    return $url;
}

add_action('login_init', 'yvo_yandex_oauth_redirect_wp_login_to_cabinet', 1);
function yvo_yandex_oauth_redirect_wp_login_to_cabinet() {
    if (!function_exists('yvo_cabinet_get_login_url')) {
        return;
    }
    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login';
    if ($action !== '' && $action !== 'login') {
        return;
    }
    if (isset($_POST['log'])) {
        return;
    }
    $redirect_to = isset($_REQUEST['redirect_to']) ? (string) wp_unslash($_REQUEST['redirect_to']) : '';
    if ($redirect_to !== '' && (strpos($redirect_to, 'wp-admin') !== false || strpos($redirect_to, 'admin.php') !== false)) {
        return;
    }
    wp_safe_redirect(yvo_cabinet_get_login_url());
    exit;
}

add_action('admin_notices', 'yvo_yandex_oauth_admin_notice');
function yvo_yandex_oauth_admin_notice() {
    if (!current_user_can('manage_options') || yvo_yandex_oauth_enabled()) {
        return;
    }
    $settings = admin_url('admin.php?page=yandex-ocr-pro-settings');
    echo '<div class="notice notice-warning"><p><strong>ДОКИ:</strong> вход через Яндекс не настроен. Укажите Client ID и Client Secret в <a href="' . esc_url($settings) . '">настройках плагина</a> (раздел «Вход через Яндекс»).</p></div>';
}

function yvo_yandex_oauth_enabled() {
    $client_id = (string) get_option('yvo_yandex_client_id', '');
    $client_secret = (string) get_option('yvo_yandex_client_secret', '');
    return ($client_id !== '' && $client_secret !== '');
}

function yvo_yandex_oauth_redirect_uri() {
    return home_url('/?yvo_oauth=yandex&yvo_oauth_action=callback');
}

/**
 * @param string $redirect_to URL после успешного входа.
 * @return string
 */
function yvo_yandex_oauth_start_url($redirect_to = '') {
    if (!yvo_yandex_oauth_enabled()) {
        return '';
    }
    if ($redirect_to === '' && function_exists('yvo_cabinet_get_contracts_url')) {
        $redirect_to = yvo_cabinet_get_contracts_url();
    }
    if ($redirect_to === '') {
        $redirect_to = home_url('/');
    }
    $redirect_to = wp_validate_redirect($redirect_to, home_url('/'));
    return add_query_arg(
        array(
            'yvo_oauth'        => 'yandex',
            'yvo_oauth_action' => 'start',
            'redirect_to'      => $redirect_to,
        ),
        yvo_yandex_oauth_route_url()
    );
}

function yvo_yandex_oauth_route_url() {
    if (function_exists('yvo_cabinet_get_login_url')) {
        return yvo_cabinet_get_login_url();
    }
    return home_url('/');
}

/**
 * HTML-кнопка «Войти через Яндекс».
 *
 * @param string $redirect_to
 * @param string $label
 * @param string $class
 * @return string
 */
function yvo_yandex_oauth_button_html($redirect_to = '', $label = '', $class = 'yvo-cabinet-btn yvo-cabinet-btn-yandex') {
    $url = yvo_yandex_oauth_start_url($redirect_to);
    if ($url === '') {
        return '';
    }
    if ($label === '') {
        $label = __('Войти через Яндекс', 'yandex-vision-ocr-pro');
    }
    return '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
}

function yvo_yandex_oauth_create_state($redirect = '') {
    $key = wp_generate_password(32, false, false);
    set_transient(
        'yvo_yoauth_' . $key,
        array(
            'r' => (string) wp_validate_redirect($redirect, ''),
            't' => time(),
        ),
        15 * MINUTE_IN_SECONDS
    );
    return $key;
}

/**
 * @param string $state_key
 * @return array|WP_Error
 */
function yvo_yandex_oauth_resolve_state($state_key) {
    $state_key = sanitize_text_field((string) $state_key);
    if ($state_key === '') {
        return new WP_Error('yvo_yandex_oauth', __('Сессия авторизации истекла. Попробуйте снова.', 'yandex-vision-ocr-pro'));
    }

    $data = get_transient('yvo_yoauth_' . $state_key);
    if (is_array($data) && !empty($data['t'])) {
        delete_transient('yvo_yoauth_' . $state_key);
        if (time() - (int) $data['t'] > 15 * MINUTE_IN_SECONDS) {
            return new WP_Error('yvo_yandex_oauth', __('Сессия авторизации истекла. Попробуйте снова.', 'yandex-vision-ocr-pro'));
        }
        return $data;
    }

    // Старый формат state (base64 JSON + nonce) — на случай незавершённых сессий.
    $state_json = json_decode(yvo_yandex_oauth_state_decode($state_key), true);
    if (is_array($state_json) && !empty($state_json['n'])) {
        if (!wp_verify_nonce((string) $state_json['n'], 'yvo_yandex_oauth')) {
            return new WP_Error('yvo_yandex_oauth', __('Сессия авторизации истекла. Попробуйте снова.', 'yandex-vision-ocr-pro'));
        }
        return array(
            'r' => isset($state_json['r']) ? (string) $state_json['r'] : '',
            't' => isset($state_json['t']) ? (int) $state_json['t'] : time(),
        );
    }

    return new WP_Error('yvo_yandex_oauth', __('Сессия авторизации истекла. Попробуйте снова.', 'yandex-vision-ocr-pro'));
}

function yvo_yandex_oauth_state_decode($state_raw) {
    $state_raw = (string) $state_raw;
    $decoded = base64_decode(strtr($state_raw, '-_', '+/'), true);
    if ($decoded !== false && $decoded !== '') {
        return $decoded;
    }
    return (string) base64_decode($state_raw, true);
}

function yvo_yandex_oauth_build_authorize_url($params) {
    return 'https://oauth.yandex.ru/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function yvo_yandex_oauth_login_url($redirect = '') {
    if (!yvo_yandex_oauth_enabled()) {
        return '';
    }

    $client_id = (string) get_option('yvo_yandex_client_id', '');
    $state = yvo_yandex_oauth_create_state($redirect);

    $params = array(
        'response_type' => 'code',
        'client_id'     => $client_id,
        'redirect_uri'  => yvo_yandex_oauth_redirect_uri(),
        'scope'         => 'login:info login:email',
        'state'         => $state,
        'force_confirm' => 'no',
    );

    return yvo_yandex_oauth_build_authorize_url($params);
}

function yvo_yandex_oauth_set_error_and_redirect($message, $redirect = '') {
    set_transient('yvo_login_oauth_error', (string) $message, 120);
    $login = function_exists('yvo_cabinet_get_login_url') ? yvo_cabinet_get_login_url() : home_url('/');
    wp_safe_redirect($redirect !== '' ? $redirect : $login);
    exit;
}

function yvo_yandex_oauth_maybe_handle() {
    if (empty($_GET['yvo_oauth']) || $_GET['yvo_oauth'] !== 'yandex') {
        return;
    }

    $action = isset($_GET['yvo_oauth_action']) ? sanitize_text_field(wp_unslash($_GET['yvo_oauth_action'])) : '';

    if ($action === 'start') {
        $redirect_raw = isset($_GET['redirect_to']) ? wp_unslash($_GET['redirect_to']) : '';
        if (is_array($redirect_raw)) {
            $redirect_raw = '';
        }
        $redirect = esc_url_raw((string) $redirect_raw);
        $redirect = wp_validate_redirect($redirect, function_exists('yvo_cabinet_get_contracts_url') ? yvo_cabinet_get_contracts_url() : home_url('/'));
        $url = yvo_yandex_oauth_login_url($redirect);
        if (!$url) {
            yvo_yandex_oauth_set_error_and_redirect(__('Вход через Яндекс не настроен. Обратитесь к администратору сайта.', 'yandex-vision-ocr-pro'));
        }
        nocache_headers();
        wp_redirect($url);
        exit;
    }

    $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
    $is_callback = ($action === 'callback') || ($code !== '' && isset($_GET['state']));
    if (!$is_callback) {
        return;
    }

    if (!empty($_GET['error'])) {
        $err = sanitize_text_field(wp_unslash($_GET['error']));
        $desc = isset($_GET['error_description']) ? sanitize_text_field(wp_unslash($_GET['error_description'])) : '';
        $msg = $desc !== '' ? $desc : $err;
        yvo_yandex_oauth_set_error_and_redirect(__('Яндекс отклонил вход: ', 'yandex-vision-ocr-pro') . $msg);
    }

    $state_raw = isset($_GET['state']) ? (string) wp_unslash($_GET['state']) : '';
    if ($code === '' || $state_raw === '') {
        yvo_yandex_oauth_set_error_and_redirect(__('Не получен код авторизации от Яндекса.', 'yandex-vision-ocr-pro'));
    }

    $state_data = yvo_yandex_oauth_resolve_state($state_raw);
    if (is_wp_error($state_data)) {
        yvo_yandex_oauth_set_error_and_redirect($state_data->get_error_message());
    }
    $redirect = isset($state_data['r']) ? (string) $state_data['r'] : '';

    $token = yvo_yandex_oauth_exchange_code($code);
    if (is_wp_error($token)) {
        yvo_yandex_oauth_set_error_and_redirect($token->get_error_message());
    }

    $info = yvo_yandex_oauth_userinfo($token);
    if (is_wp_error($info)) {
        yvo_yandex_oauth_set_error_and_redirect($info->get_error_message());
    }

    $user_id = yvo_yandex_oauth_resolve_user($info);
    if (is_wp_error($user_id)) {
        yvo_yandex_oauth_set_error_and_redirect($user_id->get_error_message());
    }

    wp_clear_auth_cookie();
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);
    do_action('wp_login', wp_get_current_user()->user_login, wp_get_current_user());

    $go = wp_validate_redirect($redirect, function_exists('yvo_cabinet_get_contracts_url') ? yvo_cabinet_get_contracts_url() : home_url('/'));
    wp_safe_redirect($go);
    exit;
}

function yvo_yandex_oauth_exchange_code($code) {
    $client_id = (string) get_option('yvo_yandex_client_id', '');
    $client_secret = (string) get_option('yvo_yandex_client_secret', '');
    if ($client_id === '' || $client_secret === '') {
        return new WP_Error('yvo_yandex_oauth', __('Yandex OAuth не настроен.', 'yandex-vision-ocr-pro'));
    }

    $body = array(
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => yvo_yandex_oauth_redirect_uri(),
    );

    $resp = wp_remote_post('https://oauth.yandex.ru/token', array(
        'timeout' => 20,
        'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
        'body'    => $body,
    ));
    if (is_wp_error($resp)) {
        return $resp;
    }
    $status = (int) wp_remote_retrieve_response_code($resp);
    $raw = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['access_token'])) {
        $detail = '';
        if (is_array($json)) {
            if (!empty($json['error_description'])) {
                $detail = (string) $json['error_description'];
            } elseif (!empty($json['error'])) {
                $detail = (string) $json['error'];
            }
        }
        if ($detail === '') {
            $detail = __('не удалось получить токен', 'yandex-vision-ocr-pro');
        }
        return new WP_Error('yvo_yandex_oauth', __('Ошибка Яндекс OAuth: ', 'yandex-vision-ocr-pro') . $detail);
    }
    return (string) $json['access_token'];
}

function yvo_yandex_oauth_userinfo($access_token) {
    $resp = wp_remote_get('https://login.yandex.ru/info?format=json', array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'OAuth ' . $access_token,
        ),
    ));
    if (is_wp_error($resp)) {
        return $resp;
    }
    $status = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['id'])) {
        return new WP_Error('yvo_yandex_oauth', __('Не удалось получить профиль Яндекса.', 'yandex-vision-ocr-pro'));
    }
    return $json;
}

/**
 * Защита от мультиаккаунта:
 * - 1 Yandex ID -> 1 WP user
 * - если email уже есть без привязки Яндекс — привязываем при первом входе
 */
function yvo_yandex_oauth_resolve_user($info) {
    $yandex_id = isset($info['id']) ? sanitize_text_field((string) $info['id']) : '';
    $email = isset($info['default_email']) ? sanitize_email((string) $info['default_email']) : '';
    $name = isset($info['real_name']) ? sanitize_text_field((string) $info['real_name']) : '';
    if ($yandex_id === '') {
        return new WP_Error('yvo_yandex_oauth', __('В профиле Яндекса нет идентификатора.', 'yandex-vision-ocr-pro'));
    }

    $existing = get_users(array(
        'meta_key'   => YVO_META_YANDEX_ID,
        'meta_value' => $yandex_id,
        'number'     => 1,
        'fields'     => 'ID',
    ));
    if (!empty($existing)) {
        return (int) $existing[0];
    }

    if ($email !== '') {
        $by_email = get_user_by('email', $email);
        if ($by_email) {
            $linked_id = (string) get_user_meta($by_email->ID, YVO_META_YANDEX_ID, true);
            if ($linked_id !== '' && $linked_id !== $yandex_id) {
                return new WP_Error('yvo_yandex_oauth', __('Этот email уже привязан к другому аккаунту Яндекс.', 'yandex-vision-ocr-pro'));
            }
            if ($linked_id === '') {
                update_user_meta($by_email->ID, YVO_META_YANDEX_ID, $yandex_id);
                update_user_meta($by_email->ID, YVO_META_YANDEX_EMAIL, $email);
                if ($name !== '' && $by_email->display_name === $by_email->user_login) {
                    wp_update_user(array(
                        'ID'           => $by_email->ID,
                        'display_name' => $name,
                    ));
                }
                return (int) $by_email->ID;
            }
        }
    }

    $base_login = 'ya_' . strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $yandex_id));
    $login = $base_login;
    $i = 1;
    while (username_exists($login)) {
        $login = $base_login . '_' . $i;
        $i++;
        if ($i > 50) {
            return new WP_Error('yvo_yandex_oauth', __('Не удалось создать уникальный логин.', 'yandex-vision-ocr-pro'));
        }
    }

    $display = $name !== '' ? $name : $login;
    $pass = wp_generate_password(32, true, true);
    $user_id = wp_insert_user(array(
        'user_login'      => $login,
        'user_pass'       => $pass,
        'user_email'      => ($email !== '' ? $email : $login . '@yandex.local'),
        'display_name'    => $display,
        'role'            => 'subscriber',
        'user_registered' => current_time('mysql'),
    ));
    if (is_wp_error($user_id)) {
        return $user_id;
    }

    update_user_meta($user_id, YVO_META_YANDEX_ID, $yandex_id);
    if ($email !== '') {
        update_user_meta($user_id, YVO_META_YANDEX_EMAIL, $email);
    }
    if (!get_user_meta($user_id, YVO_META_ROLE, true)) {
        update_user_meta($user_id, YVO_META_ROLE, 'personal');
    }

    return (int) $user_id;
}
