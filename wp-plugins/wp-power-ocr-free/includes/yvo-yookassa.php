<?php
/**
 * ЮKassa: оплата тарифов и пополнение кошелька.
 */
if (!defined('ABSPATH')) {
    exit;
}

define('YVO_YK_API', 'https://api.yookassa.ru/v3/payments');

function yvo_yookassa_get_settings() {
    $defaults = array(
        'shop_id'     => '',
        'secret_key'  => '',
        'test_mode'   => '1',
        'enabled'     => '0',
        'return_path' => '/profile/',
    );
    $saved = get_option('yvo_yookassa_settings', array());
    if (!is_array($saved)) {
        $saved = array();
    }
    return array_merge($defaults, $saved);
}

function yvo_yookassa_is_enabled() {
    $s = yvo_yookassa_get_settings();
    return ($s['enabled'] === '1' || $s['enabled'] === 1)
        && $s['shop_id'] !== ''
        && $s['secret_key'] !== '';
}

/**
 * Каталог оплат (ключ => сумма ₽, описание).
 *
 * @return array<string,array{amount:float,label:string,type:string,plan?:string}>
 */
function yvo_yookassa_products() {
    return array(
        'topup_200' => array(
            'amount' => (float) YVO_TARIFF_PRICE_STANDARD,
            'label'  => 'Разовая оплата — стандартный договор (' . YVO_TARIFF_PRICE_STANDARD . ' ₽)',
            'type'   => 'topup',
            'plan'   => 'onetime',
        ),
        'topup_390' => array(
            'amount' => (float) YVO_TARIFF_PRICE_COMPLEX,
            'label'  => 'Разовая оплата — сложный договор (' . YVO_TARIFF_PRICE_COMPLEX . ' ₽)',
            'type'   => 'topup',
            'plan'   => 'onetime',
        ),
        'plan_pro' => array(
            'amount' => (float) YVO_TARIFF_PRICE_PRO,
            'label'  => 'Подписка «Про» на 30 дней',
            'type'   => 'subscription',
            'plan'   => 'pro',
        ),
        'plan_business' => array(
            'amount' => (float) YVO_TARIFF_PRICE_BUSINESS,
            'label'  => 'Подписка «Бизнес» на 30 дней',
            'type'   => 'subscription',
            'plan'   => 'business',
        ),
        'topup_500' => array(
            'amount' => 500.0,
            'label'  => 'Пополнение кошелька 500 ₽',
            'type'   => 'topup',
            'plan'   => 'onetime',
        ),
        'topup_1000' => array(
            'amount' => 1000.0,
            'label'  => 'Пополнение кошелька 1000 ₽',
            'type'   => 'topup',
            'plan'   => 'onetime',
        ),
    );
}

function yvo_yookassa_install_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'yvo_payments';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        product_key varchar(64) NOT NULL DEFAULT '',
        amount decimal(12,2) NOT NULL DEFAULT 0,
        currency char(3) NOT NULL DEFAULT 'RUB',
        yk_payment_id varchar(64) NOT NULL DEFAULT '',
        status varchar(32) NOT NULL DEFAULT 'pending',
        metadata longtext NULL,
        created_at datetime NOT NULL,
        paid_at datetime NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY yk_payment_id (yk_payment_id),
        KEY user_id (user_id),
        KEY status (status)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function yvo_yookassa_return_url() {
    if (function_exists('yvo_cabinet_get_pricing_url')) {
        return add_query_arg(
            array(
                'yvo_paid' => '1',
            ),
            yvo_cabinet_get_pricing_url()
        );
    }
    if (function_exists('yvo_cabinet_get_profile_url')) {
        return add_query_arg('yvo_paid', '1', yvo_cabinet_get_profile_url());
    }
    $s = yvo_yookassa_get_settings();
    $path = isset($s['return_path']) ? (string) $s['return_path'] : '/';
    return home_url(trailingslashit($path) . '?yvo_paid=1');
}

function yvo_yookassa_api_request($method, $path, $body = null, $idempotence_key = '') {
    $s = yvo_yookassa_get_settings();
    if ($s['shop_id'] === '' || $s['secret_key'] === '') {
        return new WP_Error('yvo_yk', 'ЮKassa не настроена.');
    }
    $url = YVO_YK_API . $path;
    $args = array(
        'method'  => $method,
        'timeout' => 45,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($s['shop_id'] . ':' . $s['secret_key']),
            'Content-Type'  => 'application/json',
            'Idempotence-Key' => $idempotence_key !== '' ? $idempotence_key : wp_generate_uuid4(),
        ),
    );
    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }
    $resp = wp_remote_request($url, $args);
    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw = wp_remote_retrieve_body($resp);
    $data = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) && isset($data['description']) ? $data['description'] : ('HTTP ' . $code);
        return new WP_Error('yvo_yk', $msg, array('body' => $data));
    }
    return $data;
}

function yvo_yookassa_insert_pending($user_id, $product_key, $amount, $yk_id, $meta = array()) {
    global $wpdb;
    $table = $wpdb->prefix . 'yvo_payments';
    $wpdb->insert(
        $table,
        array(
            'user_id'       => (int) $user_id,
            'product_key'   => sanitize_key($product_key),
            'amount'        => $amount,
            'currency'      => 'RUB',
            'yk_payment_id' => sanitize_text_field($yk_id),
            'status'        => 'pending',
            'metadata'      => wp_json_encode($meta),
            'created_at'    => current_time('mysql'),
        ),
        array('%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s')
    );
}

function yvo_yookassa_get_by_yk_id($yk_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'yvo_payments';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE yk_payment_id = %s LIMIT 1", $yk_id), ARRAY_A);
}

function yvo_yookassa_mark_paid($row) {
    global $wpdb;
    if (empty($row['id']) || $row['status'] === 'paid') {
        return;
    }
    $table = $wpdb->prefix . 'yvo_payments';
    $wpdb->update(
        $table,
        array('status' => 'paid', 'paid_at' => current_time('mysql')),
        array('id' => (int) $row['id']),
        array('%s', '%s'),
        array('%d')
    );
    $user_id = (int) $row['user_id'];
    $product_key = sanitize_key((string) $row['product_key']);
    $products = yvo_yookassa_products();
    if (!isset($products[$product_key])) {
        return;
    }
    $p = $products[$product_key];
    if ($p['type'] === 'subscription' && !empty($p['plan']) && function_exists('yvo_tariff_apply_plan_selection')) {
        yvo_tariff_apply_plan_selection($user_id, $p['plan']);
    } elseif ($p['type'] === 'topup') {
        if (!empty($p['plan']) && function_exists('yvo_tariff_apply_plan_selection')) {
            yvo_tariff_apply_plan_selection($user_id, $p['plan']);
        }
        $bal = function_exists('yvo_tariff_get_balance') ? yvo_tariff_get_balance($user_id) : 0.0;
        update_user_meta($user_id, 'yvo_wallet_balance', (string) round($bal + (float) $row['amount'], 2));
    }
    update_option('yvo_enforce_tariff_billing', '1');

    // Авточек в «Мой налог» (не блокирует выдачу тарифа при ошибке).
    if (function_exists('yvo_mynalog_issue_for_payment')) {
        $fresh = yvo_yookassa_get_by_yk_id((string) $row['yk_payment_id']);
        yvo_mynalog_issue_for_payment($fresh ? $fresh : $row);
    }
}

function yvo_yookassa_create_payment($user_id, $product_key) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return new WP_Error('yvo_yk', 'Войдите в аккаунт для оплаты.');
    }
    $products = yvo_yookassa_products();
    $product_key = sanitize_key($product_key);
    if (!isset($products[$product_key])) {
        return new WP_Error('yvo_yk', 'Неизвестный тариф.');
    }
    $p = $products[$product_key];
    $amount = number_format((float) $p['amount'], 2, '.', '');
    $user = get_userdata($user_id);
    $email = $user && $user->user_email && strpos($user->user_email, '@yvo-cabinet.local') === false
        ? $user->user_email
        : '';
    $body = array(
        'amount' => array('value' => $amount, 'currency' => 'RUB'),
        'capture' => true,
        'confirmation' => array(
            'type'       => 'redirect',
            'return_url' => yvo_yookassa_return_url(),
        ),
        'description' => mb_substr($p['label'], 0, 128),
        'metadata' => array(
            'user_id'     => (string) $user_id,
            'product_key' => $product_key,
            'cms'         => 'dokii-wp',
        ),
    );
    if ($email !== '') {
        $body['receipt'] = array(
            'customer' => array('email' => $email),
            'items' => array(
                array(
                    'description' => mb_substr($p['label'], 0, 128),
                    'quantity'    => '1.00',
                    'amount'      => array('value' => $amount, 'currency' => 'RUB'),
                    'vat_code'    => 1,
                    'payment_mode'=> 'full_payment',
                    'payment_subject' => 'service',
                ),
            ),
        );
    }
    $idem = 'yvo-' . $user_id . '-' . $product_key . '-' . time();
    $result = yvo_yookassa_api_request('POST', '', $body, $idem);
    if (is_wp_error($result)) {
        return $result;
    }
    if (empty($result['id']) || empty($result['confirmation']['confirmation_url'])) {
        return new WP_Error('yvo_yk', 'Некорректный ответ ЮKassa.');
    }
    yvo_yookassa_insert_pending($user_id, $product_key, (float) $amount, $result['id'], $body['metadata']);
    return array(
        'payment_id' => $result['id'],
        'pay_url'    => $result['confirmation']['confirmation_url'],
    );
}

add_action('init', 'yvo_yookassa_handle_pay_start', 25);
function yvo_yookassa_handle_pay_start() {
    if (empty($_POST['yvo_yookassa_pay']) || empty($_POST['yvo_yookassa_product'])) {
        return;
    }
    if (!is_user_logged_in()) {
        $login = function_exists('yvo_cabinet_get_login_url') ? yvo_cabinet_get_login_url() : wp_login_url();
        wp_safe_redirect($login);
        exit;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yvo_yookassa_nonce'] ?? '')), 'yvo_yookassa_pay')) {
        yvo_yookassa_pay_fail_redirect('Ошибка безопасности. Обновите страницу и попробуйте снова.');
    }
    if (!yvo_yookassa_is_enabled()) {
        yvo_yookassa_pay_fail_redirect('Оплата временно недоступна.');
    }
    $product = sanitize_key(wp_unslash($_POST['yvo_yookassa_product']));
    $res = yvo_yookassa_create_payment(get_current_user_id(), $product);
    if (is_wp_error($res)) {
        yvo_yookassa_pay_fail_redirect($res->get_error_message());
    }
    $pay_url = isset($res['pay_url']) ? (string) $res['pay_url'] : '';
    if ($pay_url === '' || !preg_match('#^https?://#i', $pay_url)) {
        yvo_yookassa_pay_fail_redirect('Некорректная ссылка оплаты от ЮKassa.');
    }
    // Внешний URL ЮKassa — обычный redirect (wp_safe_redirect его блокирует).
    if (!headers_sent()) {
        nocache_headers();
        wp_redirect($pay_url, 302);
        exit;
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . esc_attr($pay_url) . '"></head><body>';
    echo '<p>Переход к оплате… <a href="' . esc_url($pay_url) . '">Нажмите сюда</a>, если не перенаправило.</p>';
    echo '</body></html>';
    exit;
}

/**
 * Ошибка оплаты → обратно на тарифы с сообщением.
 *
 * @param string $message
 */
function yvo_yookassa_pay_fail_redirect($message) {
    $url = function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/');
    $url = add_query_arg(
        array(
            'yvo_pay_error' => rawurlencode(wp_strip_all_tags((string) $message)),
        ),
        $url
    );
    wp_safe_redirect($url);
    exit;
}

add_filter('allowed_redirect_hosts', 'yvo_yookassa_allowed_redirect_hosts');
function yvo_yookassa_allowed_redirect_hosts($hosts) {
    $extra = array(
        'yookassa.ru',
        'www.yookassa.ru',
        'yoomoney.ru',
        'www.yoomoney.ru',
        'paymentcard.payture.com',
    );
    return array_values(array_unique(array_merge((array) $hosts, $extra)));
}

add_action('wp_ajax_yvo_yookassa_webhook', 'yvo_yookassa_webhook');
add_action('wp_ajax_nopriv_yvo_yookassa_webhook', 'yvo_yookassa_webhook');
function yvo_yookassa_webhook() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['object']['id'])) {
        status_header(400);
        exit;
    }
    $obj = $data['object'];
    $yk_id = sanitize_text_field($obj['id']);
    $row = yvo_yookassa_get_by_yk_id($yk_id);
    if (!$row) {
        status_header(404);
        exit;
    }
    if (isset($obj['status']) && $obj['status'] === 'succeeded') {
        yvo_yookassa_mark_paid($row);
    } elseif (isset($obj['status']) && in_array($obj['status'], array('canceled', 'failed'), true)) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'yvo_payments',
            array('status' => sanitize_key($obj['status'])),
            array('id' => (int) $row['id']),
            array('%s'),
            array('%d')
        );
    }
    status_header(200);
    echo 'ok';
    exit;
}

function yvo_yookassa_webhook_url() {
    return admin_url('admin-ajax.php?action=yvo_yookassa_webhook');
}

function yvo_yookassa_render_pay_button($product_key, $label, $class = 'yvo-cabinet-btn yvo-cabinet-btn-primary') {
    if (!is_user_logged_in()) {
        return '';
    }
    if (!yvo_yookassa_is_enabled()) {
        return '';
    }
    $products = yvo_yookassa_products();
    if (!isset($products[$product_key])) {
        return '';
    }
    ob_start();
    ?>
    <form method="post" class="yvo-yk-pay-form">
        <?php wp_nonce_field('yvo_yookassa_pay', 'yvo_yookassa_nonce'); ?>
        <input type="hidden" name="yvo_yookassa_pay" value="1">
        <input type="hidden" name="yvo_yookassa_product" value="<?php echo esc_attr($product_key); ?>">
        <button type="submit" class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
    return ob_get_clean();
}

add_action('admin_menu', 'yvo_yookassa_admin_menu', 100);
function yvo_yookassa_admin_menu() {
    add_submenu_page(
        'yandex-ocr-pro',
        'ЮKassa',
        'ЮKassa',
        'manage_options',
        'yvo-yookassa-settings',
        'yvo_yookassa_admin_page'
    );
}

function yvo_yookassa_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (isset($_POST['yvo_yk_save']) && check_admin_referer('yvo_yookassa_settings')) {
        $in = array(
            'shop_id'    => sanitize_text_field(wp_unslash($_POST['yvo_yk_shop_id'] ?? '')),
            'secret_key' => sanitize_text_field(wp_unslash($_POST['yvo_yk_secret_key'] ?? '')),
            'test_mode'  => !empty($_POST['yvo_yk_test_mode']) ? '1' : '0',
            'enabled'    => !empty($_POST['yvo_yk_enabled']) ? '1' : '0',
            'return_path'=> sanitize_text_field(wp_unslash($_POST['yvo_yk_return_path'] ?? '/profile/')),
        );
        update_option('yvo_yookassa_settings', $in);
        if (!empty($_POST['yvo_yk_enable_billing'])) {
            update_option('yvo_enforce_tariff_billing', '1');
        }
        echo '<div class="notice notice-success"><p>Настройки ЮKassa сохранены.</p></div>';
    }
    $s = yvo_yookassa_get_settings();
    ?>
    <div class="wrap">
        <h1>ЮKassa — оплата тарифов</h1>
        <p>Webhook URL для личного кабинета ЮKassa:</p>
        <p><code><?php echo esc_html(yvo_yookassa_webhook_url()); ?></code></p>
        <p>События: <strong>payment.succeeded</strong>, <strong>payment.canceled</strong>.</p>
        <form method="post">
            <?php wp_nonce_field('yvo_yookassa_settings'); ?>
            <table class="form-table">
                <tr><th>Shop ID</th><td><input type="text" class="regular-text" name="yvo_yk_shop_id" value="<?php echo esc_attr($s['shop_id']); ?>"></td></tr>
                <tr><th>Secret Key</th><td><input type="password" class="regular-text" name="yvo_yk_secret_key" value="<?php echo esc_attr($s['secret_key']); ?>" autocomplete="new-password"></td></tr>
                <tr><th>Тестовый режим</th><td><label><input type="checkbox" name="yvo_yk_test_mode" value="1" <?php checked($s['test_mode'], '1'); ?>> Да</label></td></tr>
                <tr><th>Включить оплату на сайте</th><td><label><input type="checkbox" name="yvo_yk_enabled" value="1" <?php checked($s['enabled'], '1'); ?>> Да</label></td></tr>
                <tr><th>Включить проверку тарифов (биллинг)</th><td><label><input type="checkbox" name="yvo_yk_enable_billing" value="1" <?php checked(get_option('yvo_enforce_tariff_billing', '0'), '1'); ?>> После первой оплаты тарифы обязательны</label></td></tr>
            </table>
            <p class="submit"><button type="submit" name="yvo_yk_save" class="button button-primary">Сохранить</button></p>
        </form>
        <h2>Товары</h2>
        <ul>
            <?php foreach (yvo_yookassa_products() as $k => $p) : ?>
                <li><code><?php echo esc_html($k); ?></code> — <?php echo esc_html($p['label']); ?> (<?php echo esc_html((string) $p['amount']); ?> ₽)</li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}
