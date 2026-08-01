<?php
/**
 * Юридический блок: оферта, политика ПДн, согласия, подвал, настройки оператора (152-ФЗ).
 */
if (!defined('ABSPATH')) {
    exit;
}

define('YVO_LEGAL_OFFER_VER', '1.0');
define('YVO_META_PD_CONSENT', 'yvo_pd_consent_at');
define('YVO_META_OFFER_ACCEPT', 'yvo_accepted_offer_at');
define('YVO_META_OFFER_VER', 'yvo_accepted_offer_ver');
define('YVO_COOKIE_PD_CONSENT', 'yvo_pd_consent_v1');

function yvo_legal_default_settings() {
    return array(
        'operator_name'      => 'Оператор сервиса АРР (ARRJ)',
        'operator_name_full' => '',
        'operator_inn'       => '',
        'operator_email'     => get_option('admin_email', ''),
        'operator_phone'     => '',
        'operator_address'   => 'Российская Федерация',
        'service_name'       => 'АРР',
        'pd_storage_place'   => 'Серверы на территории Российской Федерации (VPS)',
        'pd_retention_days'  => '90',
        'enforce_pd_consent' => '1',
        'show_legal_footer'  => '1',
        'hide_operator_phone'   => '0',
        'hide_operator_address' => '0',
    );
}

function yvo_legal_public_operator_name() {
    $s = yvo_legal_get_settings();
    $public = trim((string) $s['operator_name']);
    if ($public !== '') {
        return $public;
    }
    $full = trim((string) $s['operator_name_full']);
    if ($full === '') {
        return 'Оператор сервиса ' . $s['service_name'];
    }
    $parts = preg_split('/\s+/u', $full);
    if (count($parts) >= 3) {
        $last = $parts[0];
        $first = mb_substr($parts[1], 0, 1, 'UTF-8') . '.';
        $middle = mb_substr($parts[2], 0, 1, 'UTF-8') . '.';
        return trim($last . ' ' . $first . $middle);
    }
    return $full;
}

function yvo_legal_get_settings() {
    $saved = get_option('yvo_legal_settings', array());
    if (!is_array($saved)) {
        $saved = array();
    }
    return array_merge(yvo_legal_default_settings(), $saved);
}

function yvo_legal_get_url($key) {
    $map = array(
        'offer'   => 'yvo_legal_offer_page',
        'privacy' => 'yvo_legal_privacy_page',
        'pd_info' => 'yvo_legal_pd_info_page',
        'rkn'     => 'yvo_legal_rkn_page',
    );
    if (!isset($map[$key])) {
        return home_url('/');
    }
    $url = trim((string) get_option($map[$key], ''));
    return $url !== '' ? $url : home_url('/');
}

function yvo_legal_pd_consent_enforced() {
    $s = yvo_legal_get_settings();
    return isset($s['enforce_pd_consent']) && (string) $s['enforce_pd_consent'] === '1';
}

function yvo_legal_user_has_pd_consent($user_id = 0) {
    if (!yvo_legal_pd_consent_enforced()) {
        return true;
    }
    $user_id = (int) $user_id;
    if ($user_id > 0) {
        $ts = (int) get_user_meta($user_id, YVO_META_PD_CONSENT, true);
        if ($ts > 0) {
            return true;
        }
    }
    if (!empty($_COOKIE[YVO_COOKIE_PD_CONSENT])) {
        return sanitize_text_field(wp_unslash($_COOKIE[YVO_COOKIE_PD_CONSENT])) === '1';
    }
    return false;
}

function yvo_legal_record_pd_consent($user_id = 0) {
    $ts = time();
    $user_id = (int) $user_id;
    if ($user_id > 0) {
        update_user_meta($user_id, YVO_META_PD_CONSENT, (string) $ts);
    }
    if (!headers_sent()) {
        setcookie(
            YVO_COOKIE_PD_CONSENT,
            '1',
            time() + YEAR_IN_SECONDS,
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
    }
    return $ts;
}

function yvo_legal_record_offer_accept($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return;
    }
    update_user_meta($user_id, YVO_META_OFFER_ACCEPT, (string) time());
    update_user_meta($user_id, YVO_META_OFFER_VER, YVO_LEGAL_OFFER_VER);
}

function yvo_legal_check_request_pd_consent() {
    if (!yvo_legal_pd_consent_enforced()) {
        return true;
    }
    if (!empty($_POST['yvo_pd_consent']) && (string) $_POST['yvo_pd_consent'] === '1') {
        yvo_legal_record_pd_consent(get_current_user_id());
        return true;
    }
    if (yvo_legal_user_has_pd_consent(get_current_user_id())) {
        return true;
    }
    return false;
}

function yvo_legal_operator_block_html() {
    $s = yvo_legal_get_settings();
    $lines = array();
    $lines[] = '<strong>' . esc_html(yvo_legal_public_operator_name()) . '</strong>';
    if (!empty($s['operator_inn'])) {
        $lines[] = 'ИНН: ' . esc_html($s['operator_inn']);
    }
    if (!empty($s['operator_email'])) {
        $lines[] = 'E-mail: <a href="mailto:' . esc_attr($s['operator_email']) . '">' . esc_html($s['operator_email']) . '</a>';
    }
    if (!empty($s['operator_phone']) && (string) $s['hide_operator_phone'] !== '1') {
        $lines[] = 'Тел.: ' . esc_html($s['operator_phone']);
    }
    if (!empty($s['operator_address']) && (string) $s['hide_operator_address'] !== '1') {
        $lines[] = esc_html($s['operator_address']);
    }
    return '<p class="yvo-legal-operator">' . implode('<br>', $lines) . '</p>';
}

function yvo_legal_render_offer() {
    $s = yvo_legal_get_settings();
    ob_start();
    ?>
    <div class="yvo-legal-doc">
        <h1>Публичная оферта</h1>
        <p class="yvo-legal-muted">Версия <?php echo esc_html(YVO_LEGAL_OFFER_VER); ?> · сервис <?php echo esc_html($s['service_name']); ?></p>
        <?php echo yvo_legal_operator_block_html(); ?>
        <h2>1. Предмет</h2>
        <p>Оператор предоставляет пользователю доступ к онлайн-сервису автоматизации подготовки договоров и связанных документов для риелторов и юристов (далее — «Сервис») на условиях настоящей оферты.</p>
        <h2>2. Услуги и тарифы</h2>
        <ul>
            <li><strong>Подписка</strong> — доступ к функциям Сервиса на оплаченный период.</li>
            <li><strong>Разовая оплата</strong> — формирование одного или нескольких договоров в рамках выбранного пакета.</li>
        </ul>
        <p>Актуальные цены указаны на странице «Тарифы». Оплата означает согласие с условиями оферты.</p>
        <h2>3. Порядок оказания услуг</h2>
        <p>После оплаты пользователю предоставляется доступ к функциям Сервиса в личном кабинете. Сервис помогает собрать документ по шаблону на основании введённых или распознанных данных и <strong>не заменяет</strong> юридическую консультацию.</p>
        <h2>4. Персональные данные</h2>
        <p>Обработка персональных данных регулируется <a href="<?php echo esc_url(yvo_legal_get_url('privacy')); ?>">Политикой конфиденциальности</a>. Загрузка документов с персональными данными возможна только после отдельного согласия пользователя.</p>
        <h2>5. Оплата и чеки</h2>
        <p>Оплата производится через платёжные системы. При применении налога на профессиональный доход (НПД) пользователю направляется кассовый чек в порядке, установленном законодательством РФ.</p>
        <h2>6. Возвраты</h2>
        <p>Если услуга не была оказана по вине Оператора (техническая невозможность генерации после оплаты), пользователь вправе обратиться на <?php echo esc_html($s['operator_email']); ?> в течение 7 дней.</p>
        <h2>7. Ответственность</h2>
        <p>Пользователь самостоятельно проверяет итоговый текст договора перед подписанием. Оператор не несёт ответственности за содержание документов, подготовленных на основании данных пользователя.</p>
        <h2>8. Реквизиты и контакты</h2>
        <?php echo yvo_legal_operator_block_html(); ?>
    </div>
    <?php
    return ob_get_clean();
}

function yvo_legal_render_privacy() {
    $s = yvo_legal_get_settings();
    ob_start();
    ?>
    <div class="yvo-legal-doc">
        <h1>Политика конфиденциальности</h1>
        <p class="yvo-legal-muted">Обработка персональных данных в соответствии с Федеральным законом № 152-ФЗ</p>
        <?php echo yvo_legal_operator_block_html(); ?>
        <h2>1. Оператор персональных данных</h2>
        <p>Оператором является лицо, указанное выше. По вопросам ПДн: <?php echo esc_html($s['operator_email']); ?>.</p>
        <h2>2. Какие данные обрабатываются</h2>
        <ul>
            <li>ФИО, паспортные данные, адрес, телефон, e-mail, ИНН, СНИЛС — из загруженных документов и форм;</li>
            <li>данные учётной записи (логин, роль);</li>
            <li>технические данные (IP, cookies, журнал действий без содержимого паспортов).</li>
        </ul>
        <h2>3. Цели обработки</h2>
        <ul>
            <li>распознавание текста документов (OCR);</li>
            <li>автозаполнение полей договора;</li>
            <li>генерация договоров и хранение черновиков сделок;</li>
            <li>биллинг и поддержка пользователей.</li>
        </ul>
        <h2>4. Правовые основания</h2>
        <p>Согласие субъекта персональных данных, договор (оферта), исполнение обязанностей оператора.</p>
        <h2>5. Локализация и хранение</h2>
        <p>Персональные данные граждан РФ обрабатываются и хранятся на серверах, расположенных на территории Российской Федерации: <?php echo esc_html($s['pd_storage_place']); ?>.</p>
        <p>Срок хранения: до <?php echo esc_html($s['pd_retention_days']); ?> дней после завершения сделки, если иное не требуется законом или не запрошено удаление ранее.</p>
        <h2>6. Передача третьим лицам</h2>
        <ul>
            <li><strong>Yandex Cloud / Yandex Vision</strong> — распознавание текста (РФ);</li>
            <li><strong>хостинг-провайдер</strong> — размещение сайта (РФ);</li>
            <li>иные сервисы AI — только при отдельном согласии на трансграничную передачу, если включены администратором.</li>
        </ul>
        <h2>7. Права субъекта ПДн</h2>
        <p>Вы вправе запросить доступ, уточнение, блокирование или удаление данных, отозвать согласие. Запрос направляется на <?php echo esc_html($s['operator_email']); ?>. Срок ответа — до 30 дней.</p>
        <h2>8. Меры защиты</h2>
        <p>HTTPS, разграничение доступа, хранение файлов вне публичных ссылок, резервное копирование, учёт согласий пользователей.</p>
        <p><a href="<?php echo esc_url(yvo_legal_get_url('pd_info')); ?>">Как удалить свои данные</a></p>
    </div>
    <?php
    return ob_get_clean();
}

function yvo_legal_render_pd_info() {
    $s = yvo_legal_get_settings();
    ob_start();
    ?>
    <div class="yvo-legal-doc">
        <h1>Обработка и удаление персональных данных</h1>
        <?php echo yvo_legal_operator_block_html(); ?>
        <p>Для запроса на удаление или выгрузку данных напишите на <a href="mailto:<?php echo esc_attr($s['operator_email']); ?>"><?php echo esc_html($s['operator_email']); ?></a> с темой «Персональные данные» и укажите логин в Сервисе.</p>
        <p>В личном кабинете вы можете удалить черновики сделок — связанные загруженные данные будут удалены из активного хранилища в срок до <?php echo esc_html($s['pd_retention_days']); ?> дней.</p>
    </div>
    <?php
    return ob_get_clean();
}

function yvo_legal_rkn_notification_draft() {
    $s = yvo_legal_get_settings();
    $operator_full = trim((string) $s['operator_name_full']);
    if ($operator_full === '') {
        $operator_full = (string) $s['operator_name'];
    }
    $lines = array(
        'Оператор: ' . $operator_full,
        'ИНН: ' . ($s['operator_inn'] !== '' ? $s['operator_inn'] : '(укажите в админке)'),
        'E-mail: ' . $s['operator_email'],
        'Сайт: ' . home_url('/'),
        '',
        'Цели обработки ПДн:',
        '  - регистрация и авторизация;',
        '  - OCR документов;',
        '  - генерация договоров;',
        '  - оплата тарифов.',
        '',
        'Категории ПДн: ФИО, паспорт, адрес, телефон, e-mail, ИНН, СНИЛС.',
        'Субъекты: пользователи сервиса, клиенты риелторов/юристов.',
        'Основание: согласие, договор (оферта).',
        'Хранение: ' . $s['pd_storage_place'],
        'Срок: ' . $s['pd_retention_days'] . ' дней.',
        'Контакт: ' . $s['operator_email'],
    );
    return implode("\n", $lines);
}

function yvo_legal_render_rkn_guide() {
    $s = yvo_legal_get_settings();
    ob_start();
    ?>
    <div class="yvo-legal-doc yvo-legal-rkn">
        <h1>Уведомление Роскомнадзора (для оператора)</h1>
        <p class="yvo-legal-muted">Служебная памятка. Подаётся оператором сервиса, не заменяет консультацию юриста.</p>
        <?php echo yvo_legal_operator_block_html(); ?>
        <h2>Шаги</h2>
        <ol>
            <li>Заполните ИНН и контакты оператора в админке WordPress: <strong>Яндекс OCR Pro AI → Юридические документы</strong>.</li>
            <li>Подайте уведомление об обработке ПДн на портале <a href="https://pd.rkn.gov.ru/operators-registry/notification/form/" target="_blank" rel="noopener noreferrer">pd.rkn.gov.ru</a>.</li>
            <li>Укажите цели: генерация договоров, OCR документов, личный кабинет.</li>
            <li>Категории ПДн: ФИО, паспорт, контакты, адрес, ИНН, СНИЛС, данные сделки.</li>
            <li>Место хранения: <?php echo esc_html($s['pd_storage_place']); ?>.</li>
            <li>Назначьте ответственного за обработку ПДн (приказ).</li>
            <li>Разместите на сайте оферту и политику конфиденциальности (страницы создаются автоматически плагином).</li>
        </ol>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('yvo_legal_offer', function () {
    wp_enqueue_style('yvo-legal-css', YVO_PLUGIN_URL . 'css/legal.css', array(), YVO_VERSION);
    return yvo_legal_render_offer();
});
add_shortcode('yvo_legal_privacy', function () {
    wp_enqueue_style('yvo-legal-css', YVO_PLUGIN_URL . 'css/legal.css', array(), YVO_VERSION);
    return yvo_legal_render_privacy();
});
add_shortcode('yvo_legal_pd_info', function () {
    wp_enqueue_style('yvo-legal-css', YVO_PLUGIN_URL . 'css/legal.css', array(), YVO_VERSION);
    return yvo_legal_render_pd_info();
});
add_shortcode('yvo_legal_rkn_guide', function () {
    wp_enqueue_style('yvo-legal-css', YVO_PLUGIN_URL . 'css/legal.css', array(), YVO_VERSION);
    return yvo_legal_render_rkn_guide();
});

/**
 * Создаёт юридические страницы (оферта, политика, …).
 */
function yvo_legal_ensure_pages() {
    if (!function_exists('wp_insert_post') || !function_exists('get_page_by_path')) {
        return;
    }
    $author_id = function_exists('yvo_cabinet_default_post_author') ? yvo_cabinet_default_post_author() : 0;
    $pages = array(
        'oferta' => array(
            'title'   => 'Публичная оферта',
            'content' => '[yvo_legal_offer]',
            'option'  => 'yvo_legal_offer_page',
        ),
        'privacy' => array(
            'title'   => 'Политика конфиденциальности',
            'content' => '[yvo_legal_privacy]',
            'option'  => 'yvo_legal_privacy_page',
        ),
        'personal-data' => array(
            'title'   => 'Персональные данные',
            'content' => '[yvo_legal_pd_info]',
            'option'  => 'yvo_legal_pd_info_page',
        ),
        'rkn-operator' => array(
            'title'   => 'Роскомнадзор (памятка оператора)',
            'content' => '[yvo_legal_rkn_guide]',
            'option'  => 'yvo_legal_rkn_page',
        ),
    );
    foreach ($pages as $slug => $cfg) {
        $page_id = 0;
        $existing_url = (string) get_option($cfg['option'], '');
        if ($existing_url !== '') {
            $path = trim((string) wp_parse_url($existing_url, PHP_URL_PATH), '/');
            if ($path !== '') {
                $p = get_page_by_path($path);
                if ($p && isset($p->ID)) {
                    $page_id = (int) $p->ID;
                }
            }
        }
        if ($page_id <= 0) {
            $p = get_page_by_path($slug);
            if ($p && isset($p->ID)) {
                $page_id = (int) $p->ID;
            }
        }
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
        }
        $url = get_permalink($page_id);
        if ($url) {
            update_option($cfg['option'], $url);
        }
    }
}

add_action('init', 'yvo_legal_bootstrap_pages', 4);
function yvo_legal_bootstrap_pages() {
    if (defined('WP_INSTALLING') && WP_INSTALLING) {
        return;
    }
    if (get_option('yvo_legal_pages_bootstrapped') === 'yes') {
        return;
    }
    yvo_legal_ensure_pages();
    update_option('yvo_legal_pages_bootstrapped', 'yes');
}

add_action('wp_footer', 'yvo_legal_print_site_footer', 50);
function yvo_legal_print_site_footer() {
    if (is_admin()) {
        return;
    }
    $s = yvo_legal_get_settings();
    if (empty($s['show_legal_footer']) || (string) $s['show_legal_footer'] !== '1') {
        return;
    }
    wp_enqueue_style('yvo-legal-css', YVO_PLUGIN_URL . 'css/legal.css', array(), YVO_VERSION);
    $offer = yvo_legal_get_url('offer');
    $privacy = yvo_legal_get_url('privacy');
    $pd = yvo_legal_get_url('pd_info');
    echo '<footer class="yvo-legal-footer" role="contentinfo">';
    echo '<nav class="yvo-legal-footer-nav" aria-label="Юридическая информация">';
    echo '<a href="' . esc_url($offer) . '">Оферта</a>';
    echo '<a href="' . esc_url($privacy) . '">Политика конфиденциальности</a>';
    echo '<a href="' . esc_url($pd) . '">Персональные данные</a>';
    echo '</nav>';
    $s = yvo_legal_get_settings();
    if (!empty($s['operator_inn'])) {
        echo '<p class="yvo-legal-footer-copy">© ' . esc_html(gmdate('Y')) . ' ' . esc_html($s['service_name']) . ' · ИНН ' . esc_html($s['operator_inn']) . '</p>';
    } else {
        echo '<p class="yvo-legal-footer-copy">© ' . esc_html(gmdate('Y')) . ' ' . esc_html($s['service_name']) . '</p>';
    }
    echo '</footer>';
}

function yvo_legal_frontend_payload() {
    return array(
        'pd_consent_required' => yvo_legal_pd_consent_enforced() ? 1 : 0,
        'pd_consent_given'    => yvo_legal_user_has_pd_consent(get_current_user_id()) ? 1 : 0,
        'offer_url'           => yvo_legal_get_url('offer'),
        'privacy_url'         => yvo_legal_get_url('privacy'),
        'pd_info_url'         => yvo_legal_get_url('pd_info'),
    );
}

add_action('wp_ajax_yvo_save_pd_consent', 'yvo_ajax_save_pd_consent');
add_action('wp_ajax_nopriv_yvo_save_pd_consent', 'yvo_ajax_save_pd_consent');
function yvo_ajax_save_pd_consent() {
    if (empty($_POST['yvo_pd_consent']) || (string) $_POST['yvo_pd_consent'] !== '1') {
        wp_send_json_error(array('message' => 'Необходимо согласие на обработку персональных данных.'));
    }
    yvo_legal_record_pd_consent(get_current_user_id());
    wp_send_json_success(array('message' => 'Согласие сохранено.'));
}

add_action('admin_menu', 'yvo_legal_admin_menu', 99);
function yvo_legal_admin_menu() {
    add_submenu_page(
        'yandex-ocr-pro',
        'Юридические документы',
        'Юридические документы',
        'manage_options',
        'yvo-legal-settings',
        'yvo_legal_admin_page'
    );
}

function yvo_legal_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (isset($_POST['yvo_legal_save']) && check_admin_referer('yvo_legal_settings')) {
        $keys = array_keys(yvo_legal_default_settings());
        $in = array();
        foreach ($keys as $k) {
            if (!isset($_POST['yvo_legal'][$k])) {
                continue;
            }
            $v = wp_unslash($_POST['yvo_legal'][$k]);
            if (in_array($k, array('enforce_pd_consent', 'show_legal_footer', 'hide_operator_phone', 'hide_operator_address'), true)) {
                $in[$k] = '1';
            } else {
                $in[$k] = sanitize_text_field($v);
            }
        }
        if (!isset($in['enforce_pd_consent'])) {
            $in['enforce_pd_consent'] = '0';
        }
        if (!isset($in['show_legal_footer'])) {
            $in['show_legal_footer'] = '0';
        }
        if (!isset($in['hide_operator_phone'])) {
            $in['hide_operator_phone'] = '0';
        }
        if (!isset($in['hide_operator_address'])) {
            $in['hide_operator_address'] = '0';
        }
        update_option('yvo_legal_settings', array_merge(yvo_legal_get_settings(), $in));
        delete_option('yvo_legal_pages_bootstrapped');
        yvo_legal_ensure_pages();
        update_option('yvo_legal_pages_bootstrapped', 'yes');
        echo '<div class="notice notice-success"><p>Настройки сохранены. Страницы оферты и политики обновлены.</p></div>';
    }
    $s = yvo_legal_get_settings();
    ?>
    <div class="wrap">
        <h1>Юридические документы и 152-ФЗ</h1>
        <p>Заполните данные оператора (самозанятый или ИП). Страницы: <a href="<?php echo esc_url(yvo_legal_get_url('offer')); ?>" target="_blank">Оферта</a>, <a href="<?php echo esc_url(yvo_legal_get_url('privacy')); ?>" target="_blank">Политика</a>, <a href="<?php echo esc_url(yvo_legal_get_url('rkn')); ?>" target="_blank">Памятка РКН</a>.</p>
        <form method="post">
            <?php wp_nonce_field('yvo_legal_settings'); ?>
            <table class="form-table">
                <tr><th>Название сервиса</th><td><input type="text" class="regular-text" name="yvo_legal[service_name]" value="<?php echo esc_attr($s['service_name']); ?>"></td></tr>
                <tr><th>На сайте (публично)</th><td><input type="text" class="regular-text" name="yvo_legal[operator_name]" value="<?php echo esc_attr($s['operator_name']); ?>" placeholder="Напр.: Самозанятый · ARRJ или Ильмухаметов И. Р."><p class="description">Показывается в оферте и политике. Полное ФИО в футере не выводится.</p></td></tr>
                <tr><th>ФИО полностью (для РКН)</th><td><input type="text" class="regular-text" name="yvo_legal[operator_name_full]" value="<?php echo esc_attr($s['operator_name_full']); ?>" placeholder="Только для черновика уведомления в РКН"></td></tr>
                <tr><th>ИНН</th><td><input type="text" class="regular-text" name="yvo_legal[operator_inn]" value="<?php echo esc_attr($s['operator_inn']); ?>"></td></tr>
                <tr><th>E-mail для ПДн</th><td><input type="email" class="regular-text" name="yvo_legal[operator_email]" value="<?php echo esc_attr($s['operator_email']); ?>"></td></tr>
                <tr><th>Телефон</th><td><input type="text" class="regular-text" name="yvo_legal[operator_phone]" value="<?php echo esc_attr($s['operator_phone']); ?>"></td></tr>
                <tr><th>Адрес</th><td><input type="text" class="large-text" name="yvo_legal[operator_address]" value="<?php echo esc_attr($s['operator_address']); ?>"></td></tr>
                <tr><th>Место хранения ПДн</th><td><input type="text" class="large-text" name="yvo_legal[pd_storage_place]" value="<?php echo esc_attr($s['pd_storage_place']); ?>"></td></tr>
                <tr><th>Срок хранения (дней)</th><td><input type="number" min="1" name="yvo_legal[pd_retention_days]" value="<?php echo esc_attr($s['pd_retention_days']); ?>"></td></tr>
                <tr><th>Требовать согласие на ПДн перед загрузкой</th><td><label><input type="checkbox" name="yvo_legal[enforce_pd_consent]" value="1" <?php checked($s['enforce_pd_consent'], '1'); ?>> Включено</label></td></tr>
                <tr><th>Подвал с ссылками на документы</th><td><label><input type="checkbox" name="yvo_legal[show_legal_footer]" value="1" <?php checked($s['show_legal_footer'], '1'); ?>> Показывать на всех страницах</label></td></tr>
                <tr><th>Скрыть на сайте</th><td>
                    <label><input type="checkbox" name="yvo_legal[hide_operator_phone]" value="1" <?php checked($s['hide_operator_phone'], '1'); ?>> Телефон в оферте/политике</label><br>
                    <label><input type="checkbox" name="yvo_legal[hide_operator_address]" value="1" <?php checked($s['hide_operator_address'], '1'); ?>> Адрес в оферте/политике</label>
                </td></tr>
            </table>
            <p class="submit"><button type="submit" name="yvo_legal_save" class="button button-primary">Сохранить</button></p>
        </form>
        <h2>Роскомнадзор</h2>
        <p>После заполнения ИНН подайте уведомление на <a href="https://pd.rkn.gov.ru/operators-registry/notification/form/" target="_blank" rel="noopener">pd.rkn.gov.ru</a>.</p>
        <p><strong>Текст для копирования в форму РКН:</strong></p>
        <textarea readonly rows="14" class="large-text code" style="width:100%;font-family:monospace;"><?php echo esc_textarea(yvo_legal_rkn_notification_draft()); ?></textarea>
    </div>
    <?php
}

add_action('send_headers', 'yvo_legal_security_headers');
function yvo_legal_security_headers() {
    if (is_admin() || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
