<?php
/**
 * Личный кабинет: регистрация, вход, восстановление пароля по контрольному вопросу.
 * Использует WP users + user_meta для совместимости с существующим кабинетом (yvo_cabinet_drafts).
 * Позже можно синхронизировать с внешним плагином (отключить регистрацию / проверку IP).
 */
if (!defined('ABSPATH')) exit;

// Роли при регистрации
function yvo_cabinet_get_roles() {
    return array(
        'agent'       => 'Агент',
        'lawyer'      => 'Юрист',
        'personal'    => 'Для личного пользования',
        'agency'      => 'Агентство недвижимости',
    );
}

// Контрольные вопросы (ключ => текст)
function yvo_cabinet_get_control_questions() {
    return array(
        'mother_maiden'   => 'Девичья фамилия матери?',
        'first_pet'       => 'Кличка первого домашнего животного?',
        'birth_city'      => 'Город рождения?',
        'school_number'   => 'Номер школы, в которой учились?',
        'father_name'     => 'Отчество отца?',
        'first_teacher'   => 'Имя первого учителя?',
    );
}

// Мета-ключи пользователя кабинета
define('YVO_META_ROLE', 'yvo_cabinet_role');
define('YVO_META_CONTROL_QUESTION', 'yvo_control_question');
define('YVO_META_CONTROL_ANSWER', 'yvo_control_answer');
define('YVO_META_REGISTRATION_IP', 'yvo_registration_ip');

/**
 * Проверка: с этого IP уже регистрировались? (один аккаунт с одного IP)
 * Позже можно заменить на фильтр/плагин.
 */
function yvo_cabinet_ip_already_registered($ip = null) {
    if ($ip === null) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }
    if (empty($ip)) return false;
    $users = get_users(array(
        'meta_key'   => YVO_META_REGISTRATION_IP,
        'meta_value' => $ip,
        'number'     => 1,
        'fields'     => 'ID',
    ));
    return !empty($users);
}

/**
 * Регистрация пользователя кабинета.
 * Возвращает WP_Error или user_id.
 */
function yvo_cabinet_register_user($name, $login, $password, $role, $control_question_key, $control_answer) {
    $name = sanitize_text_field($name);
    $login = sanitize_user($login, true);
    $role = sanitize_text_field($role);
    $control_question_key = sanitize_text_field($control_question_key);
    $control_answer = trim((string) $control_answer);

    $roles = yvo_cabinet_get_roles();
    $questions = yvo_cabinet_get_control_questions();
    if (!isset($roles[$role])) $role = 'personal';
    if (!isset($questions[$control_question_key])) $control_question_key = key(array_slice($questions, 0, 1, true));

    if (strlen($name) < 2) return new WP_Error('yvo_cabinet', 'Укажите имя (не менее 2 символов).');
    if (strlen($login) < 3) return new WP_Error('yvo_cabinet', 'Логин не менее 3 символов.');
    if (!validate_username($login)) return new WP_Error('yvo_cabinet', 'Недопустимые символы в логине.');
    if (strlen($password) < 6) return new WP_Error('yvo_cabinet', 'Пароль не менее 6 символов.');
    if (strlen($control_answer) < 2) return new WP_Error('yvo_cabinet', 'Ответ на контрольный вопрос не менее 2 символов.');

    if (username_exists($login)) return new WP_Error('yvo_cabinet', 'Этот логин уже занят.');
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    if (!empty($ip) && yvo_cabinet_ip_already_registered($ip)) {
        return new WP_Error('yvo_cabinet', 'С одного IP-адреса можно зарегистрировать только один аккаунт.');
    }

    $email = $login . '@yvo-cabinet.local'; // заглушка, письма не отправляем
    $user_id = wp_insert_user(array(
        'user_login'   => $login,
        'user_pass'    => $password,
        'user_email'   => $email,
        'display_name' => $name,
        'role'         => 'subscriber',
        'user_registered' => current_time('mysql'),
    ));
    if (is_wp_error($user_id)) return $user_id;

    update_user_meta($user_id, YVO_META_ROLE, $role);
    update_user_meta($user_id, YVO_META_CONTROL_QUESTION, $control_question_key);
    update_user_meta($user_id, YVO_META_CONTROL_ANSWER, wp_hash_password($control_answer));
    if (!empty($ip)) update_user_meta($user_id, YVO_META_REGISTRATION_IP, $ip);

    return $user_id;
}

/**
 * Вход по логину и паролю.
 */
function yvo_cabinet_login_user($login, $password) {
    $login = sanitize_user($login, true);
    if (empty($login) || empty($password)) return new WP_Error('yvo_cabinet', 'Введите логин и пароль.');
    $user = get_user_by('login', $login);
    if (!$user) return new WP_Error('yvo_cabinet', 'Неверный логин или пароль.');
    if (!wp_check_password($password, $user->user_pass, $user->ID)) {
        return new WP_Error('yvo_cabinet', 'Неверный логин или пароль.');
    }
    wp_clear_auth_cookie();
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);
    return $user->ID;
}

/**
 * Восстановление пароля: проверка ответа на контрольный вопрос и установка нового пароля.
 */
function yvo_cabinet_reset_password_by_control($login, $control_answer, $new_password) {
    $login = sanitize_user($login, true);
    $control_answer = trim((string) $control_answer);
    $new_password = (string) $new_password;
    if (empty($login)) return new WP_Error('yvo_cabinet', 'Введите логин.');
    if (strlen($new_password) < 6) return new WP_Error('yvo_cabinet', 'Новый пароль не менее 6 символов.');
    $user = get_user_by('login', $login);
    if (!$user) return new WP_Error('yvo_cabinet', 'Пользователь с таким логином не найден.');
    $stored = get_user_meta($user->ID, YVO_META_CONTROL_ANSWER, true);
    if (empty($stored)) return new WP_Error('yvo_cabinet', 'Контрольный вопрос не настроен. Обратитесь к администратору.');
    if (!wp_check_password($control_answer, $stored, $user->ID)) {
        return new WP_Error('yvo_cabinet', 'Неверный ответ на контрольный вопрос.');
    }
    wp_set_password($new_password, $user->ID);
    return $user->ID;
}

/**
 * Получить текст контрольного вопроса пользователя по логину.
 */
function yvo_cabinet_get_control_question_for_login($login) {
    $user = get_user_by('login', sanitize_user($login, true));
    if (!$user) return null;
    $key = get_user_meta($user->ID, YVO_META_CONTROL_QUESTION, true);
    $questions = yvo_cabinet_get_control_questions();
    return isset($questions[$key]) ? $questions[$key] : null;
}

/**
 * Куда вести пользователя кабинета (не админа сайта).
 */
function yvo_cabinet_default_home_url() {
    if (function_exists('yvo_cabinet_get_pricing_url')) {
        return yvo_cabinet_get_pricing_url();
    }
    if (function_exists('yvo_cabinet_get_profile_url')) {
        return yvo_cabinet_get_profile_url();
    }
    return home_url('/');
}

/**
 * После входа — в кабинет/тарифы, а не в wp-admin.
 */
add_filter('login_redirect', 'yvo_cabinet_login_redirect', 99, 3);
function yvo_cabinet_login_redirect($redirect_to, $requested, $user) {
    if (!$user instanceof WP_User) {
        return $redirect_to;
    }
    if (user_can($user, 'manage_options')) {
        return $redirect_to;
    }
    $req = (string) $requested;
    if ($req !== '' && strpos($req, 'wp-admin') === false && strpos($req, 'wp-login') === false) {
        return $req;
    }
    if (!empty($_REQUEST['redirect_to'])) {
        $rt = esc_url_raw(wp_unslash($_REQUEST['redirect_to']));
        if ($rt && strpos($rt, 'wp-admin') === false && strpos($rt, 'wp-login') === false) {
            return $rt;
        }
    }
    return yvo_cabinet_default_home_url();
}

/**
 * Подписчиков / обычных пользователей не пускать в /wp-admin/.
 */
add_action('admin_init', 'yvo_cabinet_block_wp_admin_for_subscribers');
function yvo_cabinet_block_wp_admin_for_subscribers() {
    if (!is_user_logged_in() || wp_doing_ajax() || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    if (current_user_can('manage_options') || current_user_can('edit_posts')) {
        return;
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (strpos($uri, 'admin-ajax.php') !== false || strpos($uri, 'admin-post.php') !== false) {
        return;
    }
    wp_safe_redirect(yvo_cabinet_default_home_url());
    exit;
}

/** Скрыть чёрную админ-панель сверху у обычных пользователей. */
add_filter('show_admin_bar', 'yvo_cabinet_hide_admin_bar_for_subscribers');
function yvo_cabinet_hide_admin_bar_for_subscribers($show) {
    if (is_user_logged_in() && !current_user_can('edit_posts')) {
        return false;
    }
    return $show;
}
