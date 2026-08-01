<?php
/**
 * Авточеки в «Мой налог» (lknpd.nalog.ru) после оплаты ЮKassa.
 * Неофициальный API личного кабинета самозанятого.
 */
if (!defined('ABSPATH')) {
    exit;
}

define('YVO_MYNALOG_API', 'https://lknpd.nalog.ru/api/v1');

/**
 * @return array<string,mixed>
 */
function yvo_mynalog_get_settings() {
    $defaults = array(
        'enabled'       => '0',
        'inn'           => '',
        'password'      => '',
        'device_id'     => '',
        'service_name'  => 'Информационные услуги сервиса ARRJ (Dokii)',
        'payment_type'  => 'ACCOUNT', // деньги приходят на счёт через эквайринг
        'timezone'      => 'Europe/Moscow',
        'email_buyer'   => '1',
        'token_json'    => '',
        'last_error'    => '',
        'last_ok_at'    => '',
    );
    $saved = get_option('yvo_mynalog_settings', array());
    if (!is_array($saved)) {
        $saved = array();
    }
    $out = array_merge($defaults, $saved);
    if ($out['device_id'] === '') {
        $out['device_id'] = substr(hash('sha256', home_url() . '|yvo-mynalog|' . wp_salt('auth')), 0, 32);
    }
    if ($out['inn'] === '' && function_exists('yvo_legal_get_settings')) {
        $legal = yvo_legal_get_settings();
        if (!empty($legal['operator_inn'])) {
            $out['inn'] = preg_replace('/\D+/', '', (string) $legal['operator_inn']);
        }
    }
    return $out;
}

function yvo_mynalog_is_enabled() {
    $s = yvo_mynalog_get_settings();
    return ($s['enabled'] === '1' || $s['enabled'] === 1)
        && $s['inn'] !== ''
        && ($s['password'] !== '' || $s['token_json'] !== '');
}

/**
 * @return array{sourceType:string,sourceDeviceId:string,appVersion:string,metaDetails:array{userAgent:string}}
 */
function yvo_mynalog_device_info($device_id) {
    return array(
        'sourceType'     => 'WEB',
        'sourceDeviceId' => (string) $device_id,
        'appVersion'     => '1.0.0',
        'metaDetails'    => array(
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ),
    );
}

/**
 * @param string               $method
 * @param string               $path
 * @param array<string,mixed>|null $body
 * @param string               $bearer
 * @return array|WP_Error
 */
function yvo_mynalog_request($method, $path, $body = null, $bearer = '') {
    $url = YVO_MYNALOG_API . $path;
    $headers = array(
        'Content-Type'     => 'application/json',
        'Accept'           => 'application/json, text/plain, */*',
        'Accept-Language'  => 'ru-RU,ru;q=0.9',
        'Referer'          => 'https://lknpd.nalog.ru/auth/login',
        'User-Agent'       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    );
    if ($bearer !== '') {
        $headers['Authorization'] = 'Bearer ' . $bearer;
    }
    $args = array(
        'method'  => $method,
        'timeout' => 45,
        'headers' => $headers,
    );
    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }
    $resp = wp_remote_request($url, $args);
    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = wp_remote_retrieve_body($resp);
    $data = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = 'HTTP ' . $code;
        if (is_array($data)) {
            if (!empty($data['message'])) {
                $msg = (string) $data['message'];
            } elseif (!empty($data['error'])) {
                $msg = is_string($data['error']) ? $data['error'] : wp_json_encode($data['error']);
            }
        } elseif ($raw !== '') {
            $msg = mb_substr(wp_strip_all_tags($raw), 0, 300);
        }
        return new WP_Error('yvo_mynalog', $msg, array('status' => $code, 'body' => $data));
    }
    if (!is_array($data)) {
        return new WP_Error('yvo_mynalog', 'Некорректный ответ Мой налог');
    }
    return $data;
}

/**
 * @param array<string,mixed> $s
 */
function yvo_mynalog_save_token_json($token_json, $s = null) {
    if ($s === null) {
        $s = yvo_mynalog_get_settings();
    }
    $s['token_json'] = is_string($token_json) ? $token_json : wp_json_encode($token_json);
    $s['last_error'] = '';
    update_option('yvo_mynalog_settings', $s, false);
}

/**
 * @return array{token:string,refreshToken:string,profile?:array}|WP_Error
 */
function yvo_mynalog_login() {
    $s = yvo_mynalog_get_settings();
    $inn = preg_replace('/\D+/', '', (string) $s['inn']);
    $password = (string) $s['password'];
    if ($inn === '' || $password === '') {
        return new WP_Error('yvo_mynalog', 'Укажите ИНН и пароль от личного кабинета ФНС / Мой налог.');
    }
    $data = yvo_mynalog_request('POST', '/auth/lkfl', array(
        'username'   => $inn,
        'password'   => $password,
        'deviceInfo' => yvo_mynalog_device_info($s['device_id']),
    ));
    if (is_wp_error($data)) {
        return $data;
    }
    if (empty($data['token']) || empty($data['refreshToken'])) {
        return new WP_Error('yvo_mynalog', 'Вход в Мой налог: нет token/refreshToken в ответе.');
    }
    yvo_mynalog_save_token_json(wp_json_encode($data), $s);
    return $data;
}

/**
 * @return array{token:string,refreshToken:string,profile?:array}|WP_Error
 */
function yvo_mynalog_get_session($force_login = false) {
    $s = yvo_mynalog_get_settings();
    $token = array();
    if (!$force_login && $s['token_json'] !== '') {
        $decoded = json_decode($s['token_json'], true);
        if (is_array($decoded) && !empty($decoded['token']) && !empty($decoded['refreshToken'])) {
            $token = $decoded;
        }
    }
    if ($token === []) {
        return yvo_mynalog_login();
    }
    // Обновляем access token перед запросом (refresh живёт долго).
    $refreshed = yvo_mynalog_request('POST', '/auth/token', array(
        'deviceInfo'   => yvo_mynalog_device_info($s['device_id']),
        'refreshToken' => $token['refreshToken'],
    ));
    if (is_wp_error($refreshed)) {
        // refresh протух / сброшен — логинимся заново
        return yvo_mynalog_login();
    }
    if (empty($refreshed['token'])) {
        return yvo_mynalog_login();
    }
    // Сохраняем новые токены, профиль оставляем если не пришёл.
    if (empty($refreshed['refreshToken']) && !empty($token['refreshToken'])) {
        $refreshed['refreshToken'] = $token['refreshToken'];
    }
    if (empty($refreshed['profile']) && !empty($token['profile'])) {
        $refreshed['profile'] = $token['profile'];
    }
    yvo_mynalog_save_token_json(wp_json_encode($refreshed), $s);
    return $refreshed;
}

/**
 * ISO8601 с таймзоной для operationTime / requestTime.
 */
function yvo_mynalog_now_iso($timezone = 'Europe/Moscow') {
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Exception $e) {
        $tz = new DateTimeZone('Europe/Moscow');
    }
    $dt = new DateTimeImmutable('now', $tz);
    return $dt->format(DateTimeInterface::ATOM);
}

/**
 * Ссылка на печать чека.
 */
function yvo_mynalog_print_url($inn, $receipt_uuid) {
    $inn = preg_replace('/\D+/', '', (string) $inn);
    $receipt_uuid = sanitize_text_field((string) $receipt_uuid);
    return YVO_MYNALOG_API . '/receipt/' . rawurlencode($inn) . '/' . rawurlencode($receipt_uuid) . '/print';
}

/**
 * Создать чек дохода.
 *
 * @param string $name
 * @param float  $amount
 * @param array{client_name?:string,client_inn?:string,income_type?:string}|array $client
 * @return array{uuid:string,print_url:string,raw:array}|WP_Error
 */
function yvo_mynalog_create_income($name, $amount, $client = array()) {
    $s = yvo_mynalog_get_settings();
    $session = yvo_mynalog_get_session();
    if (is_wp_error($session)) {
        return $session;
    }
    $amount = round((float) $amount, 2);
    if ($amount <= 0) {
        return new WP_Error('yvo_mynalog', 'Сумма чека должна быть больше 0.');
    }
    $name = trim((string) $name);
    if ($name === '') {
        $name = (string) $s['service_name'];
    }
    $amount_str = number_format($amount, 2, '.', '');
    $income_type = !empty($client['income_type']) ? (string) $client['income_type'] : 'FROM_INDIVIDUAL';
    $client_payload = array(
        'contactPhone' => null,
        'displayName'  => !empty($client['client_name']) ? (string) $client['client_name'] : null,
        'incomeType'   => $income_type,
        'inn'          => !empty($client['client_inn']) ? preg_replace('/\D+/', '', (string) $client['client_inn']) : null,
    );
    if ($client_payload['inn'] === '') {
        $client_payload['inn'] = null;
    }
    $payment_type = ($s['payment_type'] === 'CASH') ? 'CASH' : 'ACCOUNT';
    $now = yvo_mynalog_now_iso($s['timezone']);
    $body = array(
        'operationTime' => $now,
        'requestTime'   => $now,
        'services'      => array(
            array(
                'name'     => mb_substr($name, 0, 255),
                'amount'   => $amount_str,
                'quantity' => 1,
            ),
        ),
        'totalAmount'   => $amount_str,
        'client'        => $client_payload,
        'paymentType'   => $payment_type,
        'ignoreMaxTotalIncomeRestriction' => false,
    );

    $data = yvo_mynalog_request('POST', '/income', $body, (string) $session['token']);
    if (is_wp_error($data)) {
        $edata = $data->get_error_data();
        $http = is_array($edata) && isset($edata['status']) ? (int) $edata['status'] : 0;
        if ($http === 401) {
            $session = yvo_mynalog_get_session(true);
            if (is_wp_error($session)) {
                return $session;
            }
            $data = yvo_mynalog_request('POST', '/income', $body, (string) $session['token']);
        }
    }
    if (is_wp_error($data)) {
        return $data;
    }

    $uuid = '';
    if (!empty($data['approvedReceiptUuid'])) {
        $uuid = (string) $data['approvedReceiptUuid'];
    } elseif (!empty($data['receiptUuid'])) {
        $uuid = (string) $data['receiptUuid'];
    }
    if ($uuid === '') {
        return new WP_Error('yvo_mynalog', 'Чек создан, но UUID не получен.', array('body' => $data));
    }

    $inn = preg_replace('/\D+/', '', (string) $s['inn']);
    if ($inn === '' && !empty($session['profile']['inn'])) {
        $inn = preg_replace('/\D+/', '', (string) $session['profile']['inn']);
    }
    $print_url = yvo_mynalog_print_url($inn, $uuid);

    $s = yvo_mynalog_get_settings();
    $s['last_ok_at'] = current_time('mysql');
    $s['last_error'] = '';
    update_option('yvo_mynalog_settings', $s, false);

    return array(
        'uuid'      => $uuid,
        'print_url' => $print_url,
        'raw'       => $data,
    );
}

/**
 * Записать результат чека в metadata платежа.
 *
 * @param array<string,mixed> $row
 * @param array<string,mixed> $extra
 */
function yvo_mynalog_update_payment_meta($row, $extra) {
    global $wpdb;
    if (empty($row['id'])) {
        return;
    }
    $meta = array();
    if (!empty($row['metadata'])) {
        $decoded = json_decode((string) $row['metadata'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    $meta = array_merge($meta, $extra);
    $wpdb->update(
        $wpdb->prefix . 'yvo_payments',
        array('metadata' => wp_json_encode($meta)),
        array('id' => (int) $row['id']),
        array('%s'),
        array('%d')
    );
}

/**
 * @param array<string,mixed> $entry
 */
function yvo_mynalog_push_log($entry) {
    $log = get_option('yvo_mynalog_log', array());
    if (!is_array($log)) {
        $log = array();
    }
    array_unshift($log, array_merge(array('at' => current_time('mysql')), $entry));
    $log = array_slice($log, 0, 50);
    update_option('yvo_mynalog_log', $log, false);
}

/**
 * Выпустить чек по оплаченному платежу ЮKassa.
 *
 * @param array<string,mixed> $row строка yvo_payments
 * @return array|WP_Error|true true если уже был чек / модуль выключен
 */
function yvo_mynalog_issue_for_payment($row) {
    if (!yvo_mynalog_is_enabled()) {
        return true;
    }
    if (empty($row['id']) || empty($row['amount'])) {
        return new WP_Error('yvo_mynalog', 'Нет данных платежа.');
    }

    $meta = array();
    if (!empty($row['metadata'])) {
        $decoded = json_decode((string) $row['metadata'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    if (!empty($meta['mynalog_uuid'])) {
        return true; // уже выписан
    }

    $s = yvo_mynalog_get_settings();
    $products = function_exists('yvo_yookassa_products') ? yvo_yookassa_products() : array();
    $product_key = sanitize_key((string) ($row['product_key'] ?? ''));
    $label = $s['service_name'];
    if ($product_key !== '' && isset($products[$product_key]['label'])) {
        $label = (string) $products[$product_key]['label'];
    }

    $client = array();
    $user_id = (int) ($row['user_id'] ?? 0);
    if ($user_id > 0) {
        $user = get_userdata($user_id);
        if ($user) {
            $display = trim($user->display_name);
            if ($display !== '' && strpos($user->user_email, '@yvo-cabinet.local') === false) {
                $client['client_name'] = mb_substr($display, 0, 120);
            }
        }
    }

    $result = yvo_mynalog_create_income($label, (float) $row['amount'], $client);
    if (is_wp_error($result)) {
        $err = $result->get_error_message();
        $s = yvo_mynalog_get_settings();
        $s['last_error'] = $err;
        update_option('yvo_mynalog_settings', $s, false);
        yvo_mynalog_update_payment_meta($row, array(
            'mynalog_error'    => $err,
            'mynalog_error_at' => current_time('mysql'),
        ));
        yvo_mynalog_push_log(array(
            'ok'         => 0,
            'payment_id' => (int) $row['id'],
            'yk_id'      => (string) ($row['yk_payment_id'] ?? ''),
            'amount'     => (float) $row['amount'],
            'error'      => $err,
        ));
        // Повтор через WP-Cron через 5 минут.
        if (!wp_next_scheduled('yvo_mynalog_retry_payment', array((int) $row['id']))) {
            wp_schedule_single_event(time() + 300, 'yvo_mynalog_retry_payment', array((int) $row['id']));
        }
        return $result;
    }

    yvo_mynalog_update_payment_meta($row, array(
        'mynalog_uuid'      => $result['uuid'],
        'mynalog_print_url' => $result['print_url'],
        'mynalog_at'        => current_time('mysql'),
        'mynalog_error'     => '',
    ));
    yvo_mynalog_push_log(array(
        'ok'         => 1,
        'payment_id' => (int) $row['id'],
        'yk_id'      => (string) ($row['yk_payment_id'] ?? ''),
        'amount'     => (float) $row['amount'],
        'uuid'       => $result['uuid'],
        'print_url'  => $result['print_url'],
    ));

    if (($s['email_buyer'] === '1' || $s['email_buyer'] === 1) && $user_id > 0) {
        yvo_mynalog_email_buyer($user_id, $result['print_url'], (float) $row['amount'], $label);
    }

    return $result;
}

/**
 * @param int    $user_id
 * @param string $print_url
 * @param float  $amount
 * @param string $label
 */
function yvo_mynalog_email_buyer($user_id, $print_url, $amount, $label) {
    $user = get_userdata((int) $user_id);
    if (!$user || !$user->user_email || strpos($user->user_email, '@yvo-cabinet.local') !== false) {
        return;
    }
    $subject = 'Чек об оплате — ARRJ';
    $body = "Здравствуйте!\n\n"
        . "Оплата прошла успешно.\n"
        . "Услуга: {$label}\n"
        . "Сумма: " . number_format((float) $amount, 2, '.', ' ') . " ₽\n\n"
        . "Электронный чек самозанятого:\n{$print_url}\n\n"
        . "Сервис ARRJ (Dokii)\n";
    wp_mail($user->user_email, $subject, $body);
}

add_action('yvo_mynalog_retry_payment', 'yvo_mynalog_retry_payment_cb', 10, 1);
function yvo_mynalog_retry_payment_cb($payment_id) {
    global $wpdb;
    $payment_id = (int) $payment_id;
    if ($payment_id <= 0) {
        return;
    }
    $row = $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'yvo_payments WHERE id = %d LIMIT 1', $payment_id),
        ARRAY_A
    );
    if (!$row || ($row['status'] ?? '') !== 'paid') {
        return;
    }
    yvo_mynalog_issue_for_payment($row);
}

add_action('admin_menu', 'yvo_mynalog_admin_menu', 101);
function yvo_mynalog_admin_menu() {
    add_submenu_page(
        'yandex-ocr-pro',
        'Мой налог',
        'Мой налог',
        'manage_options',
        'yvo-mynalog-settings',
        'yvo_mynalog_admin_page'
    );
}

function yvo_mynalog_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['yvo_mn_save']) && check_admin_referer('yvo_mynalog_settings')) {
        $cur = yvo_mynalog_get_settings();
        $password = sanitize_text_field(wp_unslash($_POST['yvo_mn_password'] ?? ''));
        if ($password === '' && !empty($cur['password'])) {
            $password = $cur['password'];
        }
        $in = array(
            'enabled'      => !empty($_POST['yvo_mn_enabled']) ? '1' : '0',
            'inn'          => preg_replace('/\D+/', '', sanitize_text_field(wp_unslash($_POST['yvo_mn_inn'] ?? ''))),
            'password'     => $password,
            'device_id'    => $cur['device_id'] !== '' ? $cur['device_id'] : substr(hash('sha256', home_url() . '|yvo-mynalog'), 0, 32),
            'service_name' => sanitize_text_field(wp_unslash($_POST['yvo_mn_service_name'] ?? '')),
            'payment_type' => (sanitize_text_field(wp_unslash($_POST['yvo_mn_payment_type'] ?? 'ACCOUNT')) === 'CASH') ? 'CASH' : 'ACCOUNT',
            'timezone'     => sanitize_text_field(wp_unslash($_POST['yvo_mn_timezone'] ?? 'Europe/Moscow')),
            'email_buyer'  => !empty($_POST['yvo_mn_email_buyer']) ? '1' : '0',
            'token_json'   => $cur['token_json'],
            'last_error'   => $cur['last_error'],
            'last_ok_at'   => $cur['last_ok_at'],
        );
        if ($in['service_name'] === '') {
            $in['service_name'] = 'Информационные услуги сервиса ARRJ (Dokii)';
        }
        update_option('yvo_mynalog_settings', $in, false);
        echo '<div class="notice notice-success"><p>Настройки «Мой налог» сохранены.</p></div>';
    }

    if (isset($_POST['yvo_mn_login']) && check_admin_referer('yvo_mynalog_settings')) {
        $res = yvo_mynalog_login();
        if (is_wp_error($res)) {
            echo '<div class="notice notice-error"><p>Вход не удался: ' . esc_html($res->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Вход в «Мой налог» успешен, токены сохранены.</p></div>';
        }
    }

    if (isset($_POST['yvo_mn_test']) && check_admin_referer('yvo_mynalog_settings')) {
        $amount = (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['yvo_mn_test_amount'] ?? '1')));
        if ($amount < 1) {
            $amount = 1;
        }
        $res = yvo_mynalog_create_income('Тестовый чек ARRJ (можно отменить в приложении)', $amount);
        if (is_wp_error($res)) {
            echo '<div class="notice notice-error"><p>Тест не удался: ' . esc_html($res->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Тестовый чек создан. <a href="' . esc_url($res['print_url']) . '" target="_blank" rel="noopener">Открыть чек</a> (UUID: ' . esc_html($res['uuid']) . ')</p></div>';
        }
    }

    $s = yvo_mynalog_get_settings();
    $has_token = $s['token_json'] !== '';
    $log = get_option('yvo_mynalog_log', array());
    if (!is_array($log)) {
        $log = array();
    }
    ?>
    <div class="wrap">
        <h1>Мой налог — авточеки</h1>
        <p>После успешной оплаты ЮKassa плагин сам зарегистрирует доход в кабинете самозанятого и сохранит ссылку на чек.</p>
        <p><strong>Важно:</strong> используется неофициальный API <code>lknpd.nalog.ru</code>. Нужен пароль от личного кабинета ФНС (тот же, что для входа в «Мой налог» по ИНН).</p>
        <?php if ($s['last_error'] !== '') : ?>
            <div class="notice notice-warning"><p>Последняя ошибка: <?php echo esc_html($s['last_error']); ?></p></div>
        <?php endif; ?>
        <?php if ($s['last_ok_at'] !== '') : ?>
            <p>Последний успешный чек: <code><?php echo esc_html($s['last_ok_at']); ?></code></p>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('yvo_mynalog_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Включить авточеки</th>
                    <td><label><input type="checkbox" name="yvo_mn_enabled" value="1" <?php checked($s['enabled'], '1'); ?>> После оплаты ЮKassa создавать чек в «Мой налог»</label></td>
                </tr>
                <tr>
                    <th>ИНН самозанятого</th>
                    <td><input type="text" class="regular-text" name="yvo_mn_inn" value="<?php echo esc_attr($s['inn']); ?>" autocomplete="off"></td>
                </tr>
                <tr>
                    <th>Пароль ЛК ФНС / Мой налог</th>
                    <td>
                        <input type="password" class="regular-text" name="yvo_mn_password" value="" autocomplete="new-password" placeholder="<?php echo $s['password'] !== '' ? '•••••••• (оставьте пустым, чтобы не менять)' : ''; ?>">
                        <p class="description">Хранится в настройках WordPress. Лучше отдельный сложный пароль ЛК.</p>
                    </td>
                </tr>
                <tr>
                    <th>Название услуги в чеке</th>
                    <td><input type="text" class="large-text" name="yvo_mn_service_name" value="<?php echo esc_attr($s['service_name']); ?>"></td>
                </tr>
                <tr>
                    <th>Тип оплаты</th>
                    <td>
                        <select name="yvo_mn_payment_type">
                            <option value="ACCOUNT" <?php selected($s['payment_type'], 'ACCOUNT'); ?>>На счёт (эквайринг ЮKassa)</option>
                            <option value="CASH" <?php selected($s['payment_type'], 'CASH'); ?>>Наличные</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Часовой пояс</th>
                    <td><input type="text" class="regular-text" name="yvo_mn_timezone" value="<?php echo esc_attr($s['timezone']); ?>"></td>
                </tr>
                <tr>
                    <th>Письмо покупателю</th>
                    <td><label><input type="checkbox" name="yvo_mn_email_buyer" value="1" <?php checked($s['email_buyer'], '1'); ?>> Отправлять ссылку на чек на e-mail пользователя</label></td>
                </tr>
                <tr>
                    <th>Сессия</th>
                    <td><?php echo $has_token ? '<span style="color:green;">Токены сохранены</span>' : '<span style="color:#a00;">Нет токенов — нажмите «Войти»</span>'; ?></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="yvo_mn_save" class="button button-primary">Сохранить</button>
                <button type="submit" name="yvo_mn_login" class="button">Войти и получить токены</button>
            </p>
        </form>

        <h2>Тестовый чек</h2>
        <form method="post">
            <?php wp_nonce_field('yvo_mynalog_settings'); ?>
            <p>
                Сумма (₽):
                <input type="number" step="0.01" min="1" name="yvo_mn_test_amount" value="1" style="width:100px;">
                <button type="submit" name="yvo_mn_test" class="button">Создать тестовый чек 1 ₽</button>
            </p>
            <p class="description">Создаст реальный доход в «Мой налог». При необходимости отмените чек в приложении.</p>
        </form>

        <h2>Журнал (последние 50)</h2>
        <?php if (!$log) : ?>
            <p>Пока пусто.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Статус</th>
                        <th>Платёж</th>
                        <th>Сумма</th>
                        <th>Чек / ошибка</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($log as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ($row['at'] ?? '')); ?></td>
                        <td><?php echo !empty($row['ok']) ? 'OK' : 'Ошибка'; ?></td>
                        <td>#<?php echo esc_html((string) ($row['payment_id'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['amount'] ?? '')); ?> ₽</td>
                        <td>
                            <?php if (!empty($row['print_url'])) : ?>
                                <a href="<?php echo esc_url($row['print_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($row['uuid'] ?? 'чек')); ?></a>
                            <?php else : ?>
                                <?php echo esc_html((string) ($row['error'] ?? '')); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
