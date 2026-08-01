<?php
/**
 * Главная ДОКИ (hero) отдельно от формы договоров.
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param string $file Имя файла в images/ (svg или png).
 * @return string
 */
function yvo_doki_ui_icon_url($file) {
    $file = ltrim((string) $file, '/');
    if ($file === '' || !defined('YVO_PLUGIN_DIR') || !defined('YVO_PLUGIN_URL')) {
        return '';
    }
    $path = YVO_PLUGIN_DIR . 'images/' . $file;
    if (!is_file($path)) {
        return '';
    }
    return YVO_PLUGIN_URL . 'images/' . $file;
}

/**
 * @param string $file
 * @param string $class
 * @return string
 */
function yvo_doki_ui_icon_markup($file, $class = 'yvo-doki-btn-icon') {
    $url = yvo_doki_ui_icon_url($file);
    if ($url === '') {
        return '';
    }
    return '<span class="' . esc_attr($class) . '" aria-hidden="true"><img src="' . esc_url($url) . '" alt="" width="20" height="20" decoding="async" loading="lazy"></span>';
}

/**
 * @param WP_Post|int|null $post
 * @return bool
 */
function yvo_post_has_doki_home_shortcode($post = null) {
    if (!function_exists('has_shortcode')) {
        return false;
    }
    if ($post === null) {
        if (!function_exists('is_singular') || !is_singular()) {
            return false;
        }
        $post = get_post();
    }
    if ($post instanceof WP_Post) {
        $pc = (string) $post->post_content;
        return $pc !== '' && has_shortcode($pc, 'yvo_doki_home');
    }
    if (is_numeric($post)) {
        $p = get_post((int) $post);
        return $p instanceof WP_Post && yvo_post_has_doki_home_shortcode($p);
    }
    return false;
}

function yvo_cabinet_get_home_url() {
    $url = trim((string) get_option('yvo_cabinet_home_page', ''));
    if ($url !== '') {
        return $url;
    }
    $p = function_exists('get_page_by_path') ? get_page_by_path('glavnaya') : null;
    if ($p && isset($p->ID)) {
        $u = get_permalink((int) $p->ID);
        if ($u) {
            return $u;
        }
    }
    return home_url('/');
}

/**
 * Страница только с формой договоров (без hero).
 *
 * @return bool
 */
function yvo_is_doki_contracts_page_context() {
    if (isset($_GET['yvo_cabinet']) && sanitize_key(wp_unslash($_GET['yvo_cabinet'])) === 'contracts') {
        return true;
    }
    if (!function_exists('is_singular') || !is_singular()) {
        return false;
    }
    $post = get_post();
    if (!$post || !function_exists('yvo_post_has_contract_form_shortcode')) {
        return false;
    }
    return yvo_post_has_contract_form_shortcode($post) && !yvo_post_has_doki_home_shortcode($post);
}

function yvo_is_doki_home_page_context() {
    if (!function_exists('is_singular') || !is_singular()) {
        return false;
    }
    return yvo_post_has_doki_home_shortcode(get_post());
}

add_filter('yvo_contract_page_show_cabinet_subbar', 'yvo_doki_home_hide_cabinet_subbar', 20);
function yvo_doki_home_hide_cabinet_subbar($show) {
    if (function_exists('yvo_is_doki_home_page_context') && yvo_is_doki_home_page_context()) {
        return false;
    }
    return $show;
}

add_filter('yvo_frontend_use_doki_shell', 'yvo_doki_filter_frontend_use_shell', 20);
function yvo_doki_filter_frontend_use_shell($show) {
    if (yvo_is_doki_contracts_page_context()) {
        return false;
    }
    if (yvo_is_doki_home_page_context()) {
        return true;
    }
    return $show;
}

function yvo_enqueue_doki_home_assets($frontend_ver) {
    if (!defined('YVO_PLUGIN_DIR') || !defined('YVO_PLUGIN_URL')) {
        return;
    }
    $dir = YVO_PLUGIN_DIR;
    $url = YVO_PLUGIN_URL;
    $path_shell = $dir . 'css/doki-shell.css';
    if (is_file($path_shell)) {
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
    $flow_js = $dir . 'js/doki-flow-guide.js';
    if (is_file($flow_js)) {
        wp_enqueue_script(
            'yvo-doki-flow-guide',
            $url . 'js/doki-flow-guide.js',
            array(),
            $frontend_ver . '.' . filemtime($flow_js),
            true
        );
    }
}

function yvo_doki_home_shortcode($atts) {
    if (!get_option('yvo_enable_shortcode', 1)) {
        return '';
    }

    $frontend_ver = YVO_VERSION . '.' . (file_exists(YVO_PLUGIN_DIR . 'js/frontend.js') ? filemtime(YVO_PLUGIN_DIR . 'js/frontend.js') : '');
    wp_enqueue_style('yvo-frontend-css', YVO_PLUGIN_URL . 'css/frontend.css', array(), $frontend_ver);
    yvo_enqueue_doki_home_assets($frontend_ver);

    $GLOBALS['yvo_doki_home_on_page'] = true;

    $yvo_doki_embed_url = '';
    $yvo_doki_img_base = defined('YVO_PLUGIN_URL') ? YVO_PLUGIN_URL . 'images/' : '';
    $yvo_doki_hero_topbar_tab = 'home';

    ob_start();
    echo '<div class="yvo-doki-contract-page depth-stage yvo-doki-home-page">';
    include YVO_PLUGIN_DIR . 'views/partials/doki-contract-hero.php';
    include YVO_PLUGIN_DIR . 'views/partials/doki-usp.php';
    include YVO_PLUGIN_DIR . 'views/partials/doki-how-it-works.php';
    include YVO_PLUGIN_DIR . 'views/partials/doki-yandex-auth.php';
    echo '</div>';
    return ob_get_clean();
}

add_shortcode('yvo_doki_home', 'yvo_doki_home_shortcode');

add_action('init', 'yvo_doki_ensure_home_page_once', 5);
function yvo_doki_ensure_home_page_once() {
    if (get_option('yvo_doki_home_page_ready') === 'yes') {
        return;
    }
    if (function_exists('yvo_ensure_required_pages')) {
        yvo_ensure_required_pages();
    }
    $url = trim((string) get_option('yvo_cabinet_home_page', ''));
    $p = function_exists('get_page_by_path') ? get_page_by_path('glavnaya') : null;
    if ($url !== '' || ($p && isset($p->ID))) {
        update_option('yvo_doki_home_page_ready', 'yes');
    }
}
