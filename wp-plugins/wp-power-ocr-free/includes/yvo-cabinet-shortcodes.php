<?php
/**
 * Шорткоды и обработка форм личного кабинета: регистрация, вход, восстановление пароля.
 */
if (!defined('ABSPATH')) exit;

// Обработка форм до вывода (init)
add_action('init', 'yvo_cabinet_process_forms', 5);

/** Чтобы WordPress не «съедал» ?yvo_cabinet= при каноническом редиректе. */
add_filter('query_vars', 'yvo_cabinet_register_query_var');
function yvo_cabinet_register_query_var($vars) {
    $vars[] = 'yvo_cabinet';
    $vars[] = 'yvo_oauth';
    $vars[] = 'yvo_oauth_action';
    return $vars;
}

add_filter('redirect_canonical', 'yvo_cabinet_disable_redirect_canonical', 10, 2);
function yvo_cabinet_disable_redirect_canonical($redirect_url, $requested_url) {
    if (isset($_GET['yvo_cabinet']) && (string) $_GET['yvo_cabinet'] !== '') {
        return false;
    }
    if (function_exists('get_query_var') && (string) get_query_var('yvo_cabinet') !== '') {
        return false;
    }
    if (is_string($requested_url) && strpos($requested_url, '/shablony') !== false) {
        return false;
    }
    if (!empty($_GET['yvo_oauth'])) {
        return false;
    }
    return $redirect_url;
}

function yvo_cabinet_process_forms() {
    if (!isset($_POST['yvo_do_register']) && !isset($_POST['yvo_do_login']) && !isset($_POST['yvo_do_forgot_step1']) && !isset($_POST['yvo_do_forgot_step2'])) {
        return;
    }

    if (isset($_POST['yvo_do_register'])) {
        if (!isset($_POST['yvo_cabinet_register_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yvo_cabinet_register_nonce'])), 'yvo_cabinet_register')) {
            $GLOBALS['yvo_register_error'] = 'Ошибка безопасности. Обновите страницу.';
            return;
        }
        $name = isset($_POST['yvo_name']) ? sanitize_text_field(wp_unslash($_POST['yvo_name'])) : '';
        $login = isset($_POST['yvo_login']) ? sanitize_text_field(wp_unslash($_POST['yvo_login'])) : '';
        $password = isset($_POST['yvo_password']) ? $_POST['yvo_password'] : '';
        $password2 = isset($_POST['yvo_password2']) ? $_POST['yvo_password2'] : '';
        $role = isset($_POST['yvo_role']) ? sanitize_text_field(wp_unslash($_POST['yvo_role'])) : 'personal';
        $q = isset($_POST['yvo_control_question']) ? sanitize_text_field(wp_unslash($_POST['yvo_control_question'])) : '';
        $answer = isset($_POST['yvo_control_answer']) ? sanitize_text_field(wp_unslash($_POST['yvo_control_answer'])) : '';
        if ($password !== $password2) {
            $GLOBALS['yvo_register_error'] = 'Пароли не совпадают.';
            return;
        }
        if (empty($_POST['yvo_accept_offer']) || empty($_POST['yvo_accept_privacy'])) {
            $GLOBALS['yvo_register_error'] = 'Необходимо принять оферту и ознакомиться с политикой конфиденциальности.';
            return;
        }
        $result = yvo_cabinet_register_user($name, $login, $password, $role, $q, $answer);
        if (is_wp_error($result)) {
            $GLOBALS['yvo_register_error'] = $result->get_error_message();
        } else {
            if (function_exists('yvo_legal_record_offer_accept')) {
                yvo_legal_record_offer_accept((int) $result);
            }
            if (function_exists('yvo_legal_record_pd_consent')) {
                yvo_legal_record_pd_consent((int) $result);
            }
            $GLOBALS['yvo_register_success'] = 'Вы успешно зарегистрированы. Войдите в личный кабинет.';
            $GLOBALS['yvo_login_url'] = yvo_cabinet_get_login_url();
        }
        return;
    }

    if (isset($_POST['yvo_do_login'])) {
        if (!isset($_POST['yvo_cabinet_login_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yvo_cabinet_login_nonce'])), 'yvo_cabinet_login')) {
            $GLOBALS['yvo_login_error'] = 'Ошибка безопасности. Обновите страницу.';
            return;
        }
        $login = isset($_POST['yvo_login']) ? sanitize_text_field(wp_unslash($_POST['yvo_login'])) : '';
        $password = isset($_POST['yvo_password']) ? $_POST['yvo_password'] : '';
        $result = yvo_cabinet_login_user($login, $password);
        if (is_wp_error($result)) {
            $GLOBALS['yvo_login_error'] = $result->get_error_message();
        } else {
            $redirect = isset($_POST['yvo_redirect']) ? esc_url_raw(wp_unslash($_POST['yvo_redirect'])) : '';
            if ($redirect === '') {
                $redirect = yvo_cabinet_get_contracts_url();
            }
            wp_safe_redirect($redirect);
            exit;
        }
        return;
    }

    if (isset($_POST['yvo_do_forgot_step1'])) {
        if (!isset($_POST['yvo_cabinet_forgot_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yvo_cabinet_forgot_nonce'])), 'yvo_cabinet_forgot')) {
            $GLOBALS['yvo_forgot_error'] = 'Ошибка безопасности.';
            return;
        }
        $login = isset($_POST['yvo_forgot_login']) ? sanitize_text_field(wp_unslash($_POST['yvo_forgot_login'])) : '';
        $question = yvo_cabinet_get_control_question_for_login($login);
        if ($question === null) {
            $GLOBALS['yvo_forgot_error'] = 'Пользователь с таким логином не найден.';
            return;
        }
        $GLOBALS['yvo_forgot_step'] = 2;
        $GLOBALS['yvo_forgot_question'] = $question;
        $GLOBALS['yvo_forgot_login'] = $login;
        return;
    }

    if (isset($_POST['yvo_do_forgot_step2'])) {
        if (!isset($_POST['yvo_cabinet_forgot_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yvo_cabinet_forgot_nonce'])), 'yvo_cabinet_forgot')) {
            $GLOBALS['yvo_forgot_error'] = 'Ошибка безопасности.';
            return;
        }
        $login = isset($_POST['yvo_forgot_login']) ? sanitize_text_field(wp_unslash($_POST['yvo_forgot_login'])) : '';
        $answer = isset($_POST['yvo_control_answer']) ? sanitize_text_field(wp_unslash($_POST['yvo_control_answer'])) : '';
        $new1 = isset($_POST['yvo_new_password']) ? $_POST['yvo_new_password'] : '';
        $new2 = isset($_POST['yvo_new_password2']) ? $_POST['yvo_new_password2'] : '';
        if ($new1 !== $new2) {
            $GLOBALS['yvo_forgot_error'] = 'Пароли не совпадают.';
            $GLOBALS['yvo_forgot_step'] = 2;
            $GLOBALS['yvo_forgot_question'] = yvo_cabinet_get_control_question_for_login($login);
            $GLOBALS['yvo_forgot_login'] = $login;
            return;
        }
        $result = yvo_cabinet_reset_password_by_control($login, $answer, $new1);
        if (is_wp_error($result)) {
            $GLOBALS['yvo_forgot_error'] = $result->get_error_message();
            $GLOBALS['yvo_forgot_step'] = 2;
            $GLOBALS['yvo_forgot_question'] = yvo_cabinet_get_control_question_for_login($login);
            $GLOBALS['yvo_forgot_login'] = $login;
        } else {
            set_transient('yvo_forgot_success', __('Пароль изменён. Войдите с новым паролем.', 'yandex-vision-ocr-pro'), 60);
            wp_safe_redirect(yvo_cabinet_get_login_url());
            exit;
        }
        return;
    }
}

/**
 * Постоянные URL кабинета без создания страниц в БД: ?yvo_cabinet=login|register|...
 */
function yvo_cabinet_route_url($route) {
    $allowed = array('login', 'register', 'forgot', 'account', 'contracts', 'profile', 'wallet', 'pricing', 'deals', 'property_check', 'templates');
    if (!in_array($route, $allowed, true)) {
        return home_url('/');
    }
    return add_query_arg('yvo_cabinet', $route, home_url('/'));
}

/**
 * Виртуальная страница ?yvo_cabinet=…
 */
function yvo_cabinet_is_virtual_route() {
    $route = '';
    if (isset($_GET['yvo_cabinet'])) {
        $route = sanitize_key(wp_unslash($_GET['yvo_cabinet']));
    } elseif (function_exists('get_query_var')) {
        $route = sanitize_key((string) get_query_var('yvo_cabinet'));
    }
    if ($route === '') {
        return false;
    }
    return in_array($route, array('login', 'register', 'forgot', 'account', 'contracts', 'profile', 'wallet', 'pricing', 'deals', 'property_check', 'templates'), true);
}

/**
 * @param string $route
 * @return string
 */
function yvo_cabinet_virtual_topbar_tab($route) {
    $map = array(
        'contracts' => 'contracts',
        'pricing'   => 'pricing',
        'templates' => 'templates',
        'profile'   => 'profile',
        'wallet'    => 'profile',
        'account'   => 'account',
        'deals'          => 'account',
        'property_check' => 'property_check',
    );
    return isset($map[$route]) ? $map[$route] : 'contracts';
}

/**
 * Шапка в потоке документа на виртуальных страницах кабинета.
 *
 * @param string $route
 * @return string
 */
function yvo_cabinet_render_virtual_header($route) {
    if ($route === 'contracts') {
        return '';
    }
    ob_start();
    echo '<div class="yvo-cabinet-virtual-head">';
    if (is_user_logged_in()) {
        echo yvo_cabinet_render_topbar(yvo_cabinet_virtual_topbar_tab($route));
    } else {
        $home = function_exists('yvo_cabinet_get_home_url') ? yvo_cabinet_get_home_url() : home_url('/');
        $pricing = yvo_cabinet_get_pricing_url();
        $templates = function_exists('yvo_cabinet_get_templates_url') ? yvo_cabinet_get_templates_url() : yvo_cabinet_route_url('templates');
        $login = yvo_cabinet_get_login_url();
        $reg = yvo_cabinet_get_register_url();
        $property_check = yvo_cabinet_get_property_check_url();
        $yvo_yandex_guest_url = function_exists('yvo_yandex_oauth_start_url')
            ? yvo_yandex_oauth_start_url(yvo_cabinet_get_contracts_url())
            : '';
        if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
            yvo_cabinet_enqueue_topbar_assets();
        }
        include YVO_PLUGIN_DIR . 'views/cabinet-guest-bar.php';
    }
    echo '</div>';
    return ob_get_clean();
}

/**
 * URL возврата на форму договоров (виртуальная страница или обычная с шорткодом).
 */
function yvo_cabinet_contracts_redirect_url() {
    if (isset($_GET['yvo_cabinet']) && sanitize_key(wp_unslash($_GET['yvo_cabinet'])) === 'contracts') {
        return yvo_cabinet_route_url('contracts');
    }
    if (function_exists('is_singular') && is_singular()) {
        return get_permalink();
    }
    return yvo_cabinet_route_url('contracts');
}

function yvo_cabinet_get_contracts_url() {
    $url = trim((string) get_option('yvo_cabinet_contracts_page', ''));
    if ($url !== '') {
        return $url;
    }
    return yvo_cabinet_route_url('contracts');
}

function yvo_cabinet_get_login_url() {
    $url = trim((string) get_option('yvo_cabinet_login_page', ''));
    if ($url !== '') {
        return $url;
    }
    return yvo_cabinet_route_url('login');
}

function yvo_cabinet_get_register_url() {
    $url = trim((string) get_option('yvo_cabinet_register_page', ''));
    if ($url !== '') {
        return $url;
    }
    return yvo_cabinet_route_url('register');
}

function yvo_cabinet_get_forgot_url() {
    $url = trim((string) get_option('yvo_cabinet_forgot_page', ''));
    if ($url !== '') {
        return $url;
    }
    return yvo_cabinet_route_url('forgot');
}

function yvo_cabinet_get_account_url() {
    $url = trim((string) get_option('yvo_cabinet_account_page', ''));
    if ($url !== '') {
        return $url;
    }
    return yvo_cabinet_route_url('account');
}

function yvo_cabinet_get_profile_url() {
    $url = trim((string) get_option('yvo_cabinet_profile_page', ''));
    if ($url !== '') {
        return $url;
    }
    return yvo_cabinet_route_url('profile');
}

function yvo_cabinet_get_wallet_url() {
    $url = trim((string) get_option('yvo_cabinet_wallet_page', ''));
    if ($url !== '') {
        return $url;
    }
    return yvo_cabinet_get_profile_url();
}

function yvo_cabinet_get_pricing_url() {
    $url = trim((string) get_option('yvo_cabinet_pricing_page', ''));
    if ($url !== '') {
        return $url;
    }
    return yvo_cabinet_route_url('pricing');
}

/**
 * CRM «Сделки» и «Личный кабинет» — одна страница (account).
 */
function yvo_cabinet_get_deals_url() {
    return yvo_cabinet_get_account_url();
}

/**
 * Страница «Проверка квартиры» (лендинг + DeepSeek).
 */
function yvo_cabinet_get_property_check_url() {
    $url = trim((string) get_option('yvo_cabinet_property_check_page', ''));
    if ($url !== '') {
        return $url;
    }
    return yvo_cabinet_route_url('property_check');
}

/**
 * Версия cabinet-auth.css (сброс кэша после правок файла).
 */
function yvo_cabinet_auth_css_version() {
    $path = YVO_PLUGIN_DIR . 'css/cabinet-auth.css';
    return YVO_VERSION . '.' . (is_file($path) ? (string) filemtime($path) : '0');
}

function yvo_cabinet_mobile_css_version() {
    $path = YVO_PLUGIN_DIR . 'css/mobile-site.css';
    return YVO_VERSION . '.' . (is_file($path) ? (string) filemtime($path) : '0');
}

/**
 * Стили и скрипт шапки кабинета.
 */
function yvo_cabinet_enqueue_topbar_assets() {
    wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), yvo_cabinet_auth_css_version());
    $mob = YVO_PLUGIN_DIR . 'css/mobile-site.css';
    if (is_file($mob)) {
        wp_enqueue_style('yvo-mobile-site', YVO_PLUGIN_URL . 'css/mobile-site.css', array('yvo-cabinet-auth'), yvo_cabinet_mobile_css_version());
    }
    if (function_exists('yvo_cabinet_topbar_mobile_inline_css')) {
        wp_add_inline_style('yvo-cabinet-auth', yvo_cabinet_topbar_mobile_inline_css());
    }
    $js_path = YVO_PLUGIN_DIR . 'js/cabinet-topbar.js';
    if (is_file($js_path)) {
        wp_enqueue_script(
            'yvo-cabinet-topbar',
            YVO_PLUGIN_URL . 'js/cabinet-topbar.js',
            array(),
            YVO_VERSION . '.' . filemtime($js_path),
            true
        );
    }
}

/**
 * Критичные мобильные стили шапки (!important — перебивают тему и старый кэш).
 *
 * @return string
 */
function yvo_cabinet_topbar_mobile_inline_css() {
    return '@media (max-width:767px){'
        . 'body.yvo-plugin-site-header:not(.wp-admin){padding-top:0!important}'
        . 'body.yvo-plugin-site-header.admin-bar:not(.wp-admin){padding-top:0!important}'
        . 'body.yvo-cabinet-virtual .yvo-cab-topbar,body.yvo-cabinet-inflow-header .yvo-cab-topbar{position:relative!important;top:auto!important;left:auto!important;right:auto!important;margin:0!important}'
        . 'body.yvo-plugin-site-header:not(.wp-admin) .yvo-cab-topbar{position:sticky!important;top:0!important;z-index:100000!important;transition:transform .22s ease,box-shadow .22s ease}'
        . 'body.admin-bar.yvo-plugin-site-header:not(.wp-admin) .yvo-cab-topbar{top:46px!important}'
        . 'body.admin-bar.yvo-cabinet-inflow-header:not(.wp-admin),body.admin-bar.yvo-contract-form-doki-page:not(.wp-admin){padding-top:46px!important}'
        . '.yvo-cab-topbar.yvo-cab-topbar--scroll-hidden{transform:translateY(-110%)!important;box-shadow:none!important}'
        . '.yvo-cab-topbar-inner{flex-wrap:nowrap!important;align-items:center!important;padding:4px 10px!important;gap:6px!important;min-height:40px!important}'
        . '.yvo-cab-topbar-logo{font-size:1.05rem!important}'
        . '.yvo-cab-topbar-burger{display:inline-flex!important;flex-direction:column!important;margin-left:auto!important;width:40px!important;height:40px!important;gap:4px!important}'
        . '.yvo-cab-topbar-right{position:fixed!important;top:0!important;right:0!important;bottom:0!important;left:auto!important;width:min(320px,90vw)!important;margin:0!important;padding:0!important;flex-direction:column!important;align-items:stretch!important;gap:0!important;background:#fff!important;box-shadow:-8px 0 32px rgba(15,23,42,.14)!important;transform:translateX(105%)!important;transition:transform .24s ease!important;z-index:100002!important;overflow-y:auto!important}'
        . '.yvo-cab-topbar.is-menu-open .yvo-cab-topbar-right{transform:translateX(0)!important}'
        . '.yvo-cab-topbar-backdrop{position:fixed!important;inset:0!important;z-index:100001!important;background:rgba(15,23,42,.45)!important}'
        . '.yvo-cab-topbar.is-menu-open .yvo-cab-topbar-backdrop{display:block!important}'
        . '.yvo-cab-topbar-nav{flex-direction:column!important;align-items:stretch!important;width:100%!important;padding:10px 12px 16px!important;gap:4px!important}'
        . '.yvo-cab-topbar-link{display:block!important;width:100%!important;white-space:normal!important;text-align:left!important;padding:12px 14px!important}'
        . '.yvo-cab-topbar-user--desktop,.yvo-cab-topbar-user{display:none!important}'
        . '.yvo-cab-topbar-mobile-head{display:flex!important}'
        . '.yvo-cab-topbar-close{display:inline-flex!important}'
        . '.yvo-cab-topbar-dropdown__panel{position:static!important;display:none!important}'
        . '.yvo-cab-topbar-dropdown.is-open .yvo-cab-topbar-dropdown__panel{display:block!important}'
        . '.yvo-cab-topbar-dropdown:hover .yvo-cab-topbar-dropdown__panel{display:none!important}'
        . '.yvo-cab-topbar-dropdown.is-open:hover .yvo-cab-topbar-dropdown__panel{display:block!important}'
        . 'body.yvo-contract-form-doki-page .yvo-cab-contract-head .yvo-cab-topbar,body.yvo-contract-form-page .yvo-cab-contract-head .yvo-cab-topbar{position:relative!important;top:auto!important}'
        . 'body.yvo-contract-form-doki-page .yvo-cab-topbar-sub,body.yvo-contract-form-page .yvo-cab-topbar-sub{display:none!important}'
        . 'body.yvo-deal-cabinet-page .yvo-cabinet-virtual-head{position:relative!important;z-index:2147483647!important}'
        . 'body.yvo-deal-cabinet-page .yvo-cabinet-virtual-head .yvo-cab-topbar{position:relative!important;z-index:2147483647!important}'
        . 'body.yvo-deal-cabinet-page .yvo-cab-topbar.is-menu-open .yvo-cab-topbar-right{z-index:2147483648!important}'
        . 'body.yvo-deal-cabinet-page .yvo-cab-topbar-backdrop{z-index:2147483646!important}'
        . '.yvo-cabinet-virtual-wrap{padding:0 10px 20px!important}'
        . 'body.yvo-contract-form-doki-page .yvo-cabinet-virtual-wrap{padding:0!important}'
        . '.yvo-cabinet-virtual .yvo-pricing.yvo-cabinet-auth,.yvo-cabinet-virtual .yvo-cabinet-auth{margin:8px 0!important;padding:14px!important}'
        . '}';
}

/**
 * Разметка профиля: кошелёк, подписка, меню (используется и для страницы «Личный кабинет»).
 *
 * @param WP_User $user Пользователь.
 * @return string
 */
function yvo_cabinet_render_profile_view($user) {
    if (!$user || empty($user->ID)) {
        return '';
    }
    wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), yvo_cabinet_auth_css_version());
    $yvo_profile_user = $user;
    $yvo_profile_role_key = get_user_meta($user->ID, 'yvo_cabinet_role', true);
    $yvo_profile_role_label = yvo_cabinet_user_role_label($user);
    $yvo_contracts_url = yvo_cabinet_get_contracts_url();
    $yvo_pricing_url = yvo_cabinet_get_pricing_url();
    $yvo_account_url = yvo_cabinet_get_account_url();
    $yvo_logout_url = wp_logout_url($yvo_contracts_url);
    $name = $user->display_name ? $user->display_name : $user->user_login;
    $yvo_profile_avatar_initial = function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'))
        : strtoupper(substr($name, 0, 1));
    if ($yvo_profile_avatar_initial === '') {
        $yvo_profile_avatar_initial = '?';
    }
    $user_id = (int) $user->ID;
    $balance = get_user_meta($user_id, 'yvo_wallet_balance', true);
    if ($balance === '' || $balance === false) {
        $balance = '0';
    }
    $yvo_wallet_balance = is_numeric($balance) ? number_format((float) $balance, 0, ',', ' ') : sanitize_text_field((string) $balance);
    $yvo_wallet_plan = function_exists('yvo_tariff_get_plan') ? yvo_tariff_get_plan($user_id) : 'free';
    $yvo_wallet_credits = (int) get_user_meta($user_id, 'yvo_credit_generations', true);
    $yvo_wallet_sub_until = (int) get_user_meta($user_id, 'yvo_subscription_until', true);
    $yvo_profile_deals_url = yvo_cabinet_get_deals_url();
    $yvo_profile_show_deals = function_exists('yvo_cabinet_user_can_see_deal_crm') && yvo_cabinet_user_can_see_deal_crm($user_id);
    ob_start();
    include YVO_PLUGIN_DIR . 'views/cabinet-profile.php';
    return ob_get_clean();
}

/**
 * Подпись роли для отображения: сначала роль кабинета, иначе роль WordPress.
 */
function yvo_cabinet_user_role_label($user) {
    if (!$user || empty($user->ID)) {
        return '';
    }
    $key = get_user_meta($user->ID, 'yvo_cabinet_role', true);
    $cab_roles = yvo_cabinet_get_roles();
    if (is_string($key) && $key !== '' && isset($cab_roles[$key])) {
        return $cab_roles[$key];
    }
    if (is_string($key) && $key !== '') {
        return $key;
    }
    $wp_roles = wp_roles();
    $all = $user->roles;
    if (!empty($all)) {
        $first = reset($all);
        if (isset($wp_roles->roles[$first]['name'])) {
            return translate_user_role($wp_roles->roles[$first]['name']);
        }
        return $first;
    }
    return '';
}

/**
 * Убирает из HTML topbar устаревший пункт «Кошелёк» (любой вариант разметки).
 *
 * @param string $html HTML.
 * @return string
 */
function yvo_cabinet_sanitize_topbar_html($html) {
    if ($html === '') {
        return $html;
    }
    // Только отдельный пункт «Кошелёк» в полоске навигации (класс topbar-link). Ссылка «Кошелёк» в подменю «Профиль» не трогаем.
    $html = preg_replace('#<a\s[^>]*\byvo-cab-topbar-link\b[^>]*>\s*Кошелёк\s*</a>#ius', '', $html);
    return $html;
}

/**
 * Верхняя панель (навигация после входа).
 *
 * @param string $current contracts|account|profile|wallet
 */
function yvo_cabinet_render_topbar($current = 'contracts') {
    if (!is_user_logged_in()) {
        return '';
    }
    yvo_cabinet_enqueue_topbar_assets();
    $user = wp_get_current_user();
    $name = $user->display_name ? $user->display_name : $user->user_login;
    $initial = function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'))
        : strtoupper(substr($name, 0, 1));
    if ($initial === '') {
        $initial = '?';
    }
    $yvo_tb_user = $user;
    $yvo_tb_initial = $initial;
    $yvo_tb_contracts_url = yvo_cabinet_get_contracts_url();
    $yvo_tb_home_url = function_exists('yvo_cabinet_get_home_url') ? yvo_cabinet_get_home_url() : home_url('/');
    $yvo_tb_pricing_url = yvo_cabinet_get_pricing_url();
    $yvo_tb_templates_url = function_exists('yvo_cabinet_get_templates_url') ? yvo_cabinet_get_templates_url() : yvo_cabinet_route_url('templates');
    $yvo_tb_account_url = yvo_cabinet_get_account_url();
    $yvo_tb_profile_url = yvo_cabinet_get_profile_url();
    $yvo_tb_wallet_url = yvo_cabinet_get_wallet_url();
    $yvo_tb_deals_url = yvo_cabinet_get_deals_url();
    $yvo_tb_property_check_url = yvo_cabinet_get_property_check_url();
    $yvo_tb_show_deals = function_exists('yvo_cabinet_user_can_see_deal_crm')
        && yvo_cabinet_user_can_see_deal_crm($user->ID);
    $yvo_tb_logout_url = wp_logout_url($yvo_tb_contracts_url);
    $yvo_tb_current = $current;
    $user_id = (int) $user->ID;
    $balance_raw = get_user_meta($user_id, 'yvo_wallet_balance', true);
    if ($balance_raw === '' || $balance_raw === false) {
        $balance_raw = '0';
    }
    $yvo_tb_balance_display = is_numeric($balance_raw)
        ? number_format((float) $balance_raw, 0, ',', ' ')
        : sanitize_text_field((string) $balance_raw);
    $yvo_tb_wallet_plan = function_exists('yvo_tariff_get_plan') ? yvo_tariff_get_plan($user_id) : 'free';
    $yvo_tb_wallet_credits = (int) get_user_meta($user_id, 'yvo_credit_generations', true);
    $yvo_tb_wallet_sub_until = (int) get_user_meta($user_id, 'yvo_subscription_until', true);
    $yvo_tb_plan_labels = function_exists('yvo_tariff_plan_labels') ? yvo_tariff_plan_labels() : array(
        'free' => __('Бесплатный', 'yandex-vision-ocr-pro'),
        'onetime' => __('Разовая оплата', 'yandex-vision-ocr-pro'),
        'pro' => __('Про', 'yandex-vision-ocr-pro'),
        'business' => __('Бизнес', 'yandex-vision-ocr-pro'),
    );
    $yvo_tb_wallet_plan = function_exists('yvo_tariff_normalize_plan')
        ? yvo_tariff_normalize_plan($yvo_tb_wallet_plan)
        : $yvo_tb_wallet_plan;
    $yvo_tb_plan_label = isset($yvo_tb_plan_labels[$yvo_tb_wallet_plan]) ? $yvo_tb_plan_labels[$yvo_tb_wallet_plan] : $yvo_tb_wallet_plan;
    $sub_text = $yvo_tb_wallet_sub_until > time() ? date_i18n('d.m.Y H:i', $yvo_tb_wallet_sub_until) : '—';
    if ($yvo_tb_wallet_plan === 'free') {
        $yvo_tb_sub_detail = __('ДКП квартира, без автозаполнения', 'yandex-vision-ocr-pro');
    } elseif (in_array($yvo_tb_wallet_plan, array('pro', 'business'), true)) {
        $yvo_tb_sub_detail = $yvo_tb_wallet_sub_until > time()
            ? sprintf(__('до %s · генераций: %d', 'yandex-vision-ocr-pro'), $sub_text, $yvo_tb_wallet_credits)
            : __('подписка не активна', 'yandex-vision-ocr-pro');
    } elseif ($yvo_tb_wallet_plan === 'onetime') {
        $yvo_tb_sub_detail = __('оплата с кошелька', 'yandex-vision-ocr-pro');
    } else {
        $yvo_tb_sub_detail = sprintf(__('осталось генераций: %d', 'yandex-vision-ocr-pro'), $yvo_tb_wallet_credits);
    }
    ob_start();
    include YVO_PLUGIN_DIR . 'views/cabinet-topbar.php';
    $html = ob_get_clean();
    return yvo_cabinet_sanitize_topbar_html($html);
}

function yvo_cabinet_shortcode_register($atts) {
    $atts = shortcode_atts(array(
        'login_url' => yvo_cabinet_get_login_url(),
    ), $atts, 'yvo_cabinet_register');
    $yvo_register_error = isset($GLOBALS['yvo_register_error']) ? $GLOBALS['yvo_register_error'] : '';
    $yvo_register_success = isset($GLOBALS['yvo_register_success']) ? $GLOBALS['yvo_register_success'] : '';
    $yvo_login_url = isset($GLOBALS['yvo_login_url']) ? $GLOBALS['yvo_login_url'] : $atts['login_url'];
    if (empty($yvo_login_url)) {
        $yvo_login_url = yvo_cabinet_get_login_url();
    }
    $yvo_yandex_register_url = function_exists('yvo_yandex_oauth_start_url')
        ? yvo_yandex_oauth_start_url(yvo_cabinet_get_contracts_url())
        : '';
    wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), yvo_cabinet_auth_css_version());
    ob_start();
    include YVO_PLUGIN_DIR . 'views/cabinet-register.php';
    return ob_get_clean();
}

function yvo_cabinet_shortcode_login($atts) {
    $atts = shortcode_atts(array(
        'register_url' => yvo_cabinet_get_register_url(),
        'forgot_url'   => yvo_cabinet_get_forgot_url(),
        'redirect'     => yvo_cabinet_get_contracts_url(),
    ), $atts, 'yvo_cabinet_login');
    $yvo_login_error = isset($GLOBALS['yvo_login_error']) ? $GLOBALS['yvo_login_error'] : '';
    $yvo_login_success = '';
    if (get_transient('yvo_forgot_success')) {
        $yvo_login_success = get_transient('yvo_forgot_success');
        delete_transient('yvo_forgot_success');
    }
    $yvo_login_redirect = $atts['redirect'];
    $yvo_register_url = $atts['register_url'];
    $yvo_forgot_url = $atts['forgot_url'];
    $yvo_oauth_error = get_transient('yvo_login_oauth_error') ? get_transient('yvo_login_oauth_error') : '';
    if ($yvo_oauth_error) delete_transient('yvo_login_oauth_error');
    $yvo_yandex_login_url = function_exists('yvo_yandex_oauth_start_url')
        ? yvo_yandex_oauth_start_url($yvo_login_redirect)
        : '';
    wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), yvo_cabinet_auth_css_version());
    ob_start();
    include YVO_PLUGIN_DIR . 'views/cabinet-login.php';
    return ob_get_clean();
}

function yvo_cabinet_shortcode_forgot($atts) {
    $atts = shortcode_atts(array(
        'login_url' => yvo_cabinet_get_login_url(),
    ), $atts, 'yvo_cabinet_forgot');
    $yvo_forgot_step = isset($GLOBALS['yvo_forgot_step']) ? $GLOBALS['yvo_forgot_step'] : 1;
    $yvo_forgot_error = isset($GLOBALS['yvo_forgot_error']) ? $GLOBALS['yvo_forgot_error'] : '';
    $yvo_forgot_success = get_transient('yvo_forgot_success') ? get_transient('yvo_forgot_success') : '';
    if ($yvo_forgot_success) delete_transient('yvo_forgot_success');
    $yvo_forgot_question = isset($GLOBALS['yvo_forgot_question']) ? $GLOBALS['yvo_forgot_question'] : '';
    $yvo_forgot_login = isset($GLOBALS['yvo_forgot_login']) ? $GLOBALS['yvo_forgot_login'] : '';
    $yvo_login_url = $atts['login_url'];
    wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), yvo_cabinet_auth_css_version());
    ob_start();
    include YVO_PLUGIN_DIR . 'views/cabinet-forgot.php';
    return ob_get_clean();
}

function yvo_cabinet_account_embeds_deal_crm($user_id) {
    $user_id = (int) $user_id;
    if (!$user_id) {
        return false;
    }
    $default = function_exists('yvo_cabinet_user_can_see_deal_crm')
        && yvo_cabinet_user_can_see_deal_crm($user_id);
    return (bool) apply_filters('yvo_cabinet_account_embed_deal_crm', $default, $user_id);
}

function yvo_cabinet_shortcode_account($atts) {
    if (!is_user_logged_in()) {
        wp_safe_redirect(yvo_cabinet_get_login_url());
        exit;
    }
    $user = wp_get_current_user();
    if (yvo_cabinet_account_embeds_deal_crm($user->ID)) {
        yvo_cabinet_enqueue_deal_cabinet_assets();
        $inner = yvo_cabinet_render_deal_cabinet_markup();
        return '<div class="yvo-deal-cabinet-embed">' . $inner . '</div>';
    }
    return yvo_cabinet_render_account_hub($user);
}

/**
 * Хаб «Личный кабинет» без CRM (free / onetime).
 *
 * @param WP_User $user Пользователь.
 * @return string
 */
function yvo_cabinet_render_account_hub($user) {
    if (!$user || empty($user->ID)) {
        return '';
    }
    wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), yvo_cabinet_auth_css_version());
    $name = $user->display_name ? $user->display_name : $user->user_login;
    $yvo_acc_user = $user;
    $yvo_acc_initial = function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'))
        : strtoupper(substr($name, 0, 1));
    if ($yvo_acc_initial === '') {
        $yvo_acc_initial = '?';
    }
    $user_id = (int) $user->ID;
    $balance = get_user_meta($user_id, 'yvo_wallet_balance', true);
    if ($balance === '' || $balance === false) {
        $balance = '0';
    }
    $yvo_acc_balance = is_numeric($balance) ? number_format((float) $balance, 0, ',', ' ') : sanitize_text_field((string) $balance);
    $plan = function_exists('yvo_tariff_get_plan') ? yvo_tariff_get_plan($user_id) : 'free';
    $plan = function_exists('yvo_tariff_normalize_plan') ? yvo_tariff_normalize_plan($plan) : $plan;
    $plan_labels = function_exists('yvo_tariff_plan_labels') ? yvo_tariff_plan_labels() : array(
        'free' => 'Бесплатный',
        'onetime' => 'Разовая оплата',
        'pro' => 'Про',
        'business' => 'Бизнес',
    );
    $yvo_acc_plan_label = isset($plan_labels[$plan]) ? $plan_labels[$plan] : $plan;
    $credits = (int) get_user_meta($user_id, 'yvo_credit_generations', true);
    $sub_until = (int) get_user_meta($user_id, 'yvo_subscription_until', true);
    if ($plan === 'free') {
        $yvo_acc_sub_detail = 'Только ДКП квартира, без автозаполнения';
    } elseif (in_array($plan, array('pro', 'business'), true)) {
        $yvo_acc_sub_detail = $sub_until > time()
            ? sprintf('до %s · генераций: %d', date_i18n('d.m.Y H:i', $sub_until), $credits)
            : 'подписка не активна';
    } elseif ($plan === 'onetime') {
        $yvo_acc_sub_detail = 'оплата с кошелька за каждый договор';
    } else {
        $yvo_acc_sub_detail = sprintf('осталось генераций: %d', $credits);
    }
    $yvo_acc_contracts_url = yvo_cabinet_get_contracts_url();
    $yvo_acc_pricing_url = yvo_cabinet_get_pricing_url();
    $yvo_acc_profile_url = yvo_cabinet_get_profile_url();
    $yvo_acc_property_check_url = yvo_cabinet_get_property_check_url();
    $yvo_acc_templates_url = function_exists('yvo_cabinet_get_templates_url')
        ? yvo_cabinet_get_templates_url()
        : yvo_cabinet_route_url('templates');
    $yvo_acc_deals_url = yvo_cabinet_get_deals_url();
    $yvo_acc_show_deals = function_exists('yvo_cabinet_user_can_see_deal_crm') && yvo_cabinet_user_can_see_deal_crm($user_id);
    $yvo_acc_logout_url = wp_logout_url($yvo_acc_contracts_url);
    ob_start();
    include YVO_PLUGIN_DIR . 'views/cabinet-account.php';
    return ob_get_clean();
}

function yvo_cabinet_shortcode_profile($atts) {
    if (!is_user_logged_in()) {
        wp_safe_redirect(yvo_cabinet_get_login_url());
        exit;
    }
    return yvo_cabinet_render_profile_view(wp_get_current_user());
}

function yvo_cabinet_shortcode_wallet($atts) {
    // Редирект в профиль выполняется в yvo_cabinet_wallet_redirect_to_profile (до вывода).
    return '';
}

/**
 * Кошелёк перенесён в профиль: редирект по ?yvo_cabinet=wallet и по странице с шорткодом [yvo_cabinet_wallet].
 */
function yvo_cabinet_wallet_redirect_to_profile() {
    if (is_admin()) {
        return;
    }
    $wallet_q = isset($_GET['yvo_cabinet']) && sanitize_key(wp_unslash($_GET['yvo_cabinet'])) === 'wallet';
    $post_has = false;
    if (!$wallet_q && function_exists('is_singular') && is_singular()) {
        global $post;
        if ($post && !empty($post->post_content) && has_shortcode($post->post_content, 'yvo_cabinet_wallet')) {
            $post_has = true;
        }
    }
    if (!$wallet_q && !$post_has) {
        return;
    }
    if (!is_user_logged_in()) {
        wp_safe_redirect(yvo_cabinet_get_login_url());
        exit;
    }
    wp_safe_redirect(yvo_cabinet_get_profile_url());
    exit;
}

add_action('template_redirect', 'yvo_cabinet_wallet_redirect_to_profile', -1);

/**
 * «Сделки» = «Личный кабинет»: редирект старых URL.
 */
function yvo_cabinet_deals_redirect_to_account() {
    if (is_admin()) {
        return;
    }
    $deals_q = isset($_GET['yvo_cabinet']) && sanitize_key(wp_unslash($_GET['yvo_cabinet'])) === 'deals';
    $post_has = false;
    if (!$deals_q && function_exists('is_singular') && is_singular()) {
        global $post;
        if ($post && !empty($post->post_content) && has_shortcode($post->post_content, 'yvo_deal_cabinet')) {
            $post_has = true;
        }
    }
    if (!$deals_q && !$post_has) {
        return;
    }
    wp_safe_redirect(yvo_cabinet_get_account_url(), 301);
    exit;
}
add_action('template_redirect', 'yvo_cabinet_deals_redirect_to_account', -1);

function yvo_cabinet_enqueue_property_check_assets() {
    if (wp_style_is('yvo-property-check-landing', 'enqueued')) {
        return;
    }
    if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
        yvo_cabinet_enqueue_topbar_assets();
    }
    $css = YVO_PLUGIN_DIR . 'css/property-check-landing.css';
    $js = YVO_PLUGIN_DIR . 'js/property-check-landing.js';
    $cv = YVO_VERSION . '.' . (is_file($css) ? filemtime($css) : '0');
    $jv = YVO_VERSION . '.' . (is_file($js) ? filemtime($js) : '0');
    wp_enqueue_style('yvo-property-check-landing', YVO_PLUGIN_URL . 'css/property-check-landing.css', array('yvo-cabinet-auth'), $cv);
    wp_enqueue_script('yvo-property-check-landing', YVO_PLUGIN_URL . 'js/property-check-landing.js', array(), $jv, true);
    $uid = get_current_user_id();
    $price = function_exists('yvo_tariff_property_check_price_rub') ? yvo_tariff_property_check_price_rub() : 119;
    $free_sub = $uid && function_exists('yvo_tariff_can_property_check_free') && yvo_tariff_can_property_check_free($uid);
    $wallet = $uid && function_exists('yvo_tariff_get_balance') ? yvo_tariff_get_balance($uid) : 0;
    $billing = function_exists('yvo_tariff_billing_enforced') && yvo_tariff_billing_enforced();
    wp_localize_script('yvo-property-check-landing', 'yvoPropertyCheck', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('yvo_property_check'),
        'loggedIn' => is_user_logged_in() ? 1 : 0,
        'loginUrl' => yvo_cabinet_get_login_url(),
        'registerUrl' => yvo_cabinet_get_register_url(),
        'pricingUrl' => yvo_cabinet_get_pricing_url(),
        'price' => $price,
        'freeSub' => $free_sub ? 1 : 0,
        'wallet' => $wallet,
        'billingEnforced' => $billing ? 1 : 0,
    ));
}

function yvo_cabinet_shortcode_property_check($atts) {
    yvo_cabinet_enqueue_property_check_assets();
    $uid = get_current_user_id();
    $yvo_pc_logged_in = is_user_logged_in();
    $yvo_pc_login_url = yvo_cabinet_get_login_url();
    $yvo_pc_register_url = yvo_cabinet_get_register_url();
    $yvo_pc_pricing_url = yvo_cabinet_get_pricing_url();
    $yvo_pc_price = function_exists('yvo_tariff_property_check_price_rub') ? yvo_tariff_property_check_price_rub() : 119;
    $yvo_pc_free_sub = $uid && function_exists('yvo_tariff_can_property_check_free') && yvo_tariff_can_property_check_free($uid);
    $yvo_pc_wallet = $uid && function_exists('yvo_tariff_get_balance') ? yvo_tariff_get_balance($uid) : 0;
    ob_start();
    include YVO_PLUGIN_DIR . 'views/property-check-landing.php';
    return ob_get_clean();
}

function yvo_cabinet_enqueue_pricing_assets() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $css_path = YVO_PLUGIN_DIR . 'css/pricing.css';
    $js_path = YVO_PLUGIN_DIR . 'js/pricing-cards.js';
    $css_ver = YVO_VERSION . '.' . (is_file($css_path) ? filemtime($css_path) : '');
    $js_ver = YVO_VERSION . '.' . (is_file($js_path) ? filemtime($js_path) : '');
    if (!wp_style_is('yvo-cabinet-auth', 'enqueued')) {
        wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), yvo_cabinet_auth_css_version());
    }
    wp_enqueue_style('yvo-pricing', YVO_PLUGIN_URL . 'css/pricing.css', array('yvo-cabinet-auth'), $css_ver);
    if (is_file($js_path)) {
        wp_enqueue_script('yvo-pricing-cards', YVO_PLUGIN_URL . 'js/pricing-cards.js', array(), $js_ver, true);
    }
}

function yvo_cabinet_shortcode_pricing($atts) {
    yvo_cabinet_enqueue_pricing_assets();
    ob_start();
    include YVO_PLUGIN_DIR . 'views/pricing.php';
    return ob_get_clean();
}

/**
 * Подключение CSS/JS CRM «Сделки» (один раз).
 */
function yvo_cabinet_enqueue_deal_cabinet_assets() {
    if (wp_style_is('yvo-deal-cabinet', 'enqueued')) {
        return;
    }
    if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
        yvo_cabinet_enqueue_topbar_assets();
    }
    $pub_ver = YVO_VERSION;
    $pub_css = YVO_PLUGIN_DIR . 'css/public-header.css';
    if (is_file($pub_css)) {
        $pub_ver = YVO_VERSION . '.' . filemtime($pub_css);
    }
    wp_enqueue_style('yvo-public-header', YVO_PLUGIN_URL . 'css/public-header.css', array('yvo-cabinet-auth'), $pub_ver);
    $contracts_url = yvo_cabinet_get_contracts_url();
    $dc_css = YVO_PLUGIN_DIR . 'css/deal-cabinet.css';
    $dc_js = YVO_PLUGIN_DIR . 'js/deal-cabinet.js';
    $cv = YVO_VERSION . '.' . (is_file($dc_css) ? filemtime($dc_css) : '');
    $jv = YVO_VERSION . '.' . (is_file($dc_js) ? filemtime($dc_js) : '');
    wp_enqueue_style('yvo-deal-cabinet', YVO_PLUGIN_URL . 'css/deal-cabinet.css', array(), $cv);
    wp_enqueue_script('yvo-deal-cabinet', YVO_PLUGIN_URL . 'js/deal-cabinet.js', array(), $jv, true);
    $fw = YVO_PLUGIN_DIR . 'js/deal-cabinet-fullwidth.js';
    $fwv = YVO_VERSION . '.' . (is_file($fw) ? filemtime($fw) : '');
    wp_enqueue_script('yvo-deal-cabinet-fullwidth', YVO_PLUGIN_URL . 'js/deal-cabinet-fullwidth.js', array('yvo-deal-cabinet'), $fwv, true);
    wp_localize_script(
        'yvo-deal-cabinet',
        'yvoDealCabinet',
        array(
            'contractsUrl' => $contracts_url,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('yvo_deal_cabinet'),
        )
    );
    wp_add_inline_script(
        'yvo-deal-cabinet',
        '(function(){var el=document.querySelector(".yvo-deal-cabinet-shell");if(!el||!el.getAttribute("data-yvo-ajax-url"))return;if(typeof window.yvoDealCabinet!=="object"||!window.yvoDealCabinet||!window.yvoDealCabinet.ajaxUrl){window.yvoDealCabinet=window.yvoDealCabinet||{};window.yvoDealCabinet.ajaxUrl=el.getAttribute("data-yvo-ajax-url");window.yvoDealCabinet.nonce=el.getAttribute("data-yvo-nonce")||"";if(!window.yvoDealCabinet.contractsUrl){window.yvoDealCabinet.contractsUrl=el.getAttribute("data-yvo-contracts-url")||"";}}})();',
        'before'
    );
}

/**
 * HTML CRM «Сделки» (после проверки yvo_cabinet_user_can_see_deal_crm).
 */
function yvo_cabinet_render_deal_cabinet_markup() {
    $yvo_contracts_url = yvo_cabinet_get_contracts_url();
    $yvo_deal_ajax_url = admin_url('admin-ajax.php');
    $yvo_deal_nonce = wp_create_nonce('yvo_deal_cabinet');
    $yvo_deal_build = defined('YVO_VERSION') ? (string) YVO_VERSION : '';
    ob_start();
    include YVO_PLUGIN_DIR . 'views/deal-cabinet.php';
    return ob_get_clean();
}

/**
 * ЛК «Сделки» (deal-cabinet.html): список сделок и карточки. Только платный тариф.
 */
function yvo_cabinet_shortcode_deal_cabinet($atts) {
    $contracts_url = yvo_cabinet_get_contracts_url();
    $pricing_url = yvo_cabinet_get_pricing_url();
    $login_url = yvo_cabinet_get_login_url();
    if (!is_user_logged_in()) {
        if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
            yvo_cabinet_enqueue_topbar_assets();
        }
        ob_start();
        ?>
        <div class="yvo-deal-cabinet-gate yvo-cabinet-auth">
            <p>Войдите в аккаунт, чтобы открыть раздел «Сделки».</p>
            <div class="yvo-deal-cabinet-gate-actions">
                <a class="yvo-cabinet-btn yvo-cabinet-btn-primary" href="<?php echo esc_url($login_url); ?>">Войти</a>
                <a class="yvo-cabinet-btn yvo-cabinet-btn-secondary" href="<?php echo esc_url($contracts_url); ?>">К договорам</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    $uid = get_current_user_id();
    if (!function_exists('yvo_cabinet_user_can_see_deal_crm') || !yvo_cabinet_user_can_see_deal_crm($uid)) {
        if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
            yvo_cabinet_enqueue_topbar_assets();
        }
        ob_start();
        ?>
        <div class="yvo-deal-cabinet-gate yvo-cabinet-auth">
            <p>Раздел «Сделки» доступен на тарифах <strong>Про</strong> и <strong>Бизнес</strong> (активная подписка) либо администратору сайта. На бесплатном и разовом тарифе доступна генерация договора с соответствующими ограничениями.</p>
            <div class="yvo-deal-cabinet-gate-actions">
                <a class="yvo-cabinet-btn yvo-cabinet-btn-primary" href="<?php echo esc_url($pricing_url); ?>">Тарифы</a>
                <a class="yvo-cabinet-btn yvo-cabinet-btn-secondary" href="<?php echo esc_url($contracts_url); ?>">Договоры (форма)</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    yvo_cabinet_enqueue_deal_cabinet_assets();
    return '<div class="yvo-deal-cabinet-embed">' . yvo_cabinet_render_deal_cabinet_markup() . '</div>';
}

/** Обёртка: если не авторизован — показываем форму входа, иначе — форму договоров (с кабинетом). */
function yvo_cabinet_shortcode_contract_form($atts) {
    if (!is_user_logged_in()) {
        $redir = yvo_cabinet_contracts_redirect_url();
        $GLOBALS['yvo_login_redirect'] = $redir;
        return yvo_cabinet_shortcode_login(array('redirect' => $redir));
    }
    return do_shortcode('[yvo_contract_form]');
}

/**
 * Стили/скрипты до wp_head(): при виртуальной странице шорткод выполняется после wp_head(), иначе ресурсы теряются.
 */
function yvo_cabinet_virtual_enqueue_assets() {
    if (is_admin()) {
        return;
    }
    $route = '';
    if (isset($_GET['yvo_cabinet'])) {
        $route = sanitize_key(wp_unslash($_GET['yvo_cabinet']));
    } elseif (function_exists('get_query_var')) {
        $route = sanitize_key((string) get_query_var('yvo_cabinet'));
    }
    if ($route === '') {
        return;
    }
    $valid = array('login', 'register', 'forgot', 'account', 'contracts', 'profile', 'wallet', 'pricing', 'deals', 'templates', 'property_check');
    if (!in_array($route, $valid, true)) {
        return;
    }
    $pub_ver = YVO_VERSION;
    $pub_css = YVO_PLUGIN_DIR . 'css/public-header.css';
    if (is_file($pub_css)) {
        $pub_ver = YVO_VERSION . '.' . filemtime($pub_css);
    }
    if ($route === 'deals') {
        if (is_user_logged_in() && function_exists('yvo_cabinet_user_can_see_deal_crm') && yvo_cabinet_user_can_see_deal_crm(get_current_user_id())) {
            yvo_cabinet_enqueue_deal_cabinet_assets();
        } else {
            if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
                yvo_cabinet_enqueue_topbar_assets();
            }
            wp_enqueue_style('yvo-public-header', YVO_PLUGIN_URL . 'css/public-header.css', array('yvo-cabinet-auth'), $pub_ver);
        }
        return;
    }
    if ($route === 'account' && is_user_logged_in() && yvo_cabinet_account_embeds_deal_crm(get_current_user_id())
        && function_exists('yvo_cabinet_user_can_see_deal_crm') && yvo_cabinet_user_can_see_deal_crm(get_current_user_id())) {
        yvo_cabinet_enqueue_deal_cabinet_assets();
    }
    if ($route === 'contracts' && !is_user_logged_in()) {
        if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
            yvo_cabinet_enqueue_topbar_assets();
        }
        wp_enqueue_style('yvo-public-header', YVO_PLUGIN_URL . 'css/public-header.css', array('yvo-cabinet-auth'), $pub_ver);
        return;
    }
    if ($route === 'contracts' && is_user_logged_in() && get_option('yvo_enable_shortcode', 1)) {
        if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
            yvo_cabinet_enqueue_topbar_assets();
        }
        wp_enqueue_style('yvo-public-header', YVO_PLUGIN_URL . 'css/public-header.css', array('yvo-cabinet-auth'), $pub_ver);
        $frontend_ver = YVO_VERSION . '.' . (file_exists(YVO_PLUGIN_DIR . 'js/frontend.js') ? filemtime(YVO_PLUGIN_DIR . 'js/frontend.js') : '');
        wp_enqueue_style('yvo-frontend-css', YVO_PLUGIN_URL . 'css/frontend.css', array(), $frontend_ver);
        if (function_exists('yvo_enqueue_doki_shell_form_assets')) {
            yvo_enqueue_doki_shell_form_assets($frontend_ver);
        }
        wp_enqueue_script('yvo-frontend-js', YVO_PLUGIN_URL . 'js/frontend.js', array('jquery'), $frontend_ver, true);
        if (function_exists('yvo_enqueue_doki_contract_form_js')) {
            yvo_enqueue_doki_contract_form_js($frontend_ver);
        }
        if (function_exists('yvo_enqueue_participant_tab_add_js')) {
            yvo_enqueue_participant_tab_add_js($frontend_ver);
        }
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
                    'user_logged_in' => 1,
                    'tariff' => $tariff_payload,
                ),
                function_exists('yvo_frontend_ajax_fp_diag_fields') ? yvo_frontend_ajax_fp_diag_fields() : array()
            )
        );
        return;
    }
    if ($route === 'pricing' && function_exists('yvo_cabinet_enqueue_pricing_assets')) {
        yvo_cabinet_enqueue_pricing_assets();
        wp_enqueue_style('yvo-public-header', YVO_PLUGIN_URL . 'css/public-header.css', array('yvo-pricing'), $pub_ver);
        return;
    }
    if ($route === 'templates' && function_exists('yvo_cabinet_enqueue_templates_assets')) {
        yvo_cabinet_enqueue_templates_assets();
        wp_enqueue_style('yvo-public-header', YVO_PLUGIN_URL . 'css/public-header.css', array('yvo-templates-library'), $pub_ver);
        return;
    }
    if ($route === 'pricing' || $route === 'templates' || $route === 'profile' || $route === 'wallet' || $route === 'account' || $route === 'login' || $route === 'register' || $route === 'forgot') {
        if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
            yvo_cabinet_enqueue_topbar_assets();
        }
    }
    wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), yvo_cabinet_auth_css_version());
    if (function_exists('yvo_cabinet_topbar_mobile_inline_css')) {
        wp_add_inline_style('yvo-cabinet-auth', yvo_cabinet_topbar_mobile_inline_css());
    }
    wp_enqueue_style('yvo-public-header', YVO_PLUGIN_URL . 'css/public-header.css', array('yvo-cabinet-auth'), $pub_ver);
}

add_action('wp_enqueue_scripts', 'yvo_cabinet_virtual_enqueue_assets', 5);

/**
 * Раннее подключение CRM на странице «Личный кабинет» (шорткод до wp_head).
 */
add_action('wp_enqueue_scripts', 'yvo_cabinet_early_enqueue_deal_on_account_page', 4);
function yvo_cabinet_early_enqueue_deal_on_account_page() {
    if (is_admin() || !is_user_logged_in()) {
        return;
    }
    if (!function_exists('yvo_cabinet_user_can_see_deal_crm') || !yvo_cabinet_user_can_see_deal_crm(get_current_user_id())) {
        return;
    }
    if (isset($_GET['yvo_cabinet']) && sanitize_key(wp_unslash($_GET['yvo_cabinet'])) === 'account') {
        yvo_cabinet_enqueue_deal_cabinet_assets();
        return;
    }
    if (!is_singular()) {
        return;
    }
    $post = get_post();
    if ($post && !empty($post->post_content)) {
        $pc = $post->post_content;
        if (has_shortcode($pc, 'yvo_cabinet_account') || strpos($pc, 'yvo_cabinet_account') !== false) {
            yvo_cabinet_enqueue_deal_cabinet_assets();
        }
    }
}

/**
 * Вывод кабинета по ?yvo_cabinet=… без страниц в WordPress (обходит 404 и сбои wp_insert_post).
 */
function yvo_cabinet_virtual_template_redirect() {
    if (is_admin()) {
        return;
    }
    $route = '';
    if (isset($_GET['yvo_cabinet'])) {
        $route = sanitize_key(wp_unslash($_GET['yvo_cabinet']));
    } elseif (function_exists('get_query_var')) {
        $route = sanitize_key((string) get_query_var('yvo_cabinet'));
    }
    if ($route === '') {
        return;
    }
    $map = array(
        'login'     => '[yvo_cabinet_login]',
        'register'  => '[yvo_cabinet_register]',
        'forgot'    => '[yvo_cabinet_forgot]',
        'account'   => '[yvo_cabinet_account]',
        'profile'   => '[yvo_cabinet_profile]',
        'wallet'    => '[yvo_cabinet_wallet]',
        'pricing'   => '[yvo_pricing]',
        'templates' => '[yvo_templates]',
        'deals'     => '[yvo_deal_cabinet]',
        'contracts'      => '[yvo_cabinet_contract_form]',
        'property_check' => '[yvo_property_check]',
    );
    if (!isset($map[$route])) {
        return;
    }
    global $wp_query;
    if (is_object($wp_query)) {
        $wp_query->is_404 = false;
        $wp_query->is_page = true;
        $wp_query->is_singular = true;
    }
    status_header(200);
    nocache_headers();
    echo '<!DOCTYPE html><html ';
    language_attributes();
    echo '><head><meta charset="';
    echo esc_attr(get_bloginfo('charset'));
    echo '"><meta name="viewport" content="width=device-width, initial-scale=1">';
    wp_head();
    echo '</head><body ';
    if (function_exists('body_class')) {
        $body_extra = array(
            'yvo-cabinet-virtual',
            'yvo-cabinet-route-' . $route,
            'yvo-hide-theme-nav',
            'yvo-cabinet-inflow-header',
        );
        if ($route === 'deals') {
            $body_extra[] = 'yvo-deal-cabinet-page';
        } elseif ($route === 'contracts') {
            $body_extra[] = 'yvo-contract-form-doki-page';
            $body_extra[] = 'yvo-plugin-site-header';
        } elseif ($route === 'property_check') {
            $body_extra[] = 'yvo-property-check-page';
            $body_extra[] = 'yvo-plugin-site-header';
        } else {
            $body_extra[] = 'yvo-plugin-site-header';
        }
        body_class($body_extra);
    } else {
        $cls = 'yvo-cabinet-virtual yvo-cabinet-route-' . esc_attr($route) . ' yvo-hide-theme-nav yvo-cabinet-inflow-header';
        $cls .= ($route === 'deals') ? ' yvo-deal-cabinet-page' : ' yvo-plugin-site-header';
        echo 'class="' . $cls . '"';
    }
    echo '>';
    if (function_exists('wp_body_open')) {
        wp_body_open();
    }
    if (function_exists('yvo_cabinet_render_virtual_header')) {
        echo yvo_cabinet_render_virtual_header($route);
    }
    echo '<div class="yvo-cabinet-virtual-wrap">';
    echo do_shortcode($map[$route]);
    echo '</div>';
    wp_footer();
    echo '</body></html>';
    exit;
}

add_action('template_redirect', 'yvo_cabinet_virtual_template_redirect', 0);

add_shortcode('yvo_cabinet_register', 'yvo_cabinet_shortcode_register');
add_shortcode('yvo_cabinet_login', 'yvo_cabinet_shortcode_login');
add_shortcode('yvo_cabinet_forgot', 'yvo_cabinet_shortcode_forgot');
add_shortcode('yvo_cabinet_account', 'yvo_cabinet_shortcode_account');
add_shortcode('yvo_cabinet_profile', 'yvo_cabinet_shortcode_profile');
add_shortcode('yvo_cabinet_wallet', 'yvo_cabinet_shortcode_wallet');
add_action('wp_footer', 'yvo_cabinet_topbar_footer_mobile_css', 1);
function yvo_cabinet_topbar_footer_mobile_css() {
    if (is_admin()) {
        return;
    }
    $on_virtual = isset($_GET['yvo_cabinet']);
    if (!$on_virtual && !wp_style_is('yvo-cabinet-auth', 'enqueued')) {
        return;
    }
    if (!function_exists('yvo_cabinet_topbar_mobile_inline_css')) {
        return;
    }
    echo '<style id="yvo-topbar-mobile-fallback">' . yvo_cabinet_topbar_mobile_inline_css() . '</style>';
}

add_action('wp_footer', 'yvo_cabinet_topbar_footer_assets', 5);
function yvo_cabinet_topbar_footer_assets() {
    if (is_admin()) {
        return;
    }
    if (wp_script_is('yvo-cabinet-topbar', 'done')) {
        return;
    }
    $js_path = YVO_PLUGIN_DIR . 'js/cabinet-topbar.js';
    if (!is_file($js_path)) {
        return;
    }
    if (!wp_script_is('yvo-cabinet-topbar', 'enqueued')) {
        wp_enqueue_script(
            'yvo-cabinet-topbar',
            YVO_PLUGIN_URL . 'js/cabinet-topbar.js',
            array(),
            YVO_VERSION . '.' . filemtime($js_path),
            true
        );
    }
}

add_shortcode('yvo_pricing', 'yvo_cabinet_shortcode_pricing');
add_shortcode('yvo_property_check', 'yvo_cabinet_shortcode_property_check');
add_shortcode('yvo_deal_cabinet', 'yvo_cabinet_shortcode_deal_cabinet');
add_shortcode('yvo_cabinet_contract_form', 'yvo_cabinet_shortcode_contract_form');
