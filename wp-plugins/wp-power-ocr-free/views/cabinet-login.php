<?php
if (!defined('ABSPATH')) {
    exit;
}
$error = isset($yvo_login_error) ? $yvo_login_error : '';
$success = isset($yvo_login_success) ? $yvo_login_success : '';
$oauth_error = isset($yvo_oauth_error) ? $yvo_oauth_error : '';
$redirect = isset($yvo_login_redirect) ? $yvo_login_redirect : '';
$yandex_url = isset($yvo_yandex_login_url) ? $yvo_yandex_login_url : '';
$register_url = isset($yvo_register_url) ? $yvo_register_url : '#';
$forgot_url = isset($yvo_forgot_url) ? $yvo_forgot_url : '';
?>
<div class="yvo-cab-login-stage depth-stage">
    <div class="yvo-cabinet-auth yvo-cabinet-login yvo-cab-login-card card3d">
        <header class="yvo-cab-login-head">
            <span class="yvo-cab-login-brand">АРР</span>
            <h2 class="yvo-cabinet-auth-title">Вход в личный кабинет</h2>
            <p class="yvo-cab-login-lead">Договоры онлайн: загрузите документы, проверьте данные и скачайте DOCX.</p>
        </header>

        <?php if ($success) : ?>
            <div class="yvo-cabinet-auth-message yvo-cabinet-success"><?php echo esc_html($success); ?></div>
        <?php endif; ?>
        <?php if ($oauth_error) : ?>
            <div class="yvo-cabinet-auth-message yvo-cabinet-error"><?php echo esc_html($oauth_error); ?></div>
        <?php endif; ?>
        <?php if ($error) : ?>
            <div class="yvo-cabinet-auth-message yvo-cabinet-error"><?php echo esc_html($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($yandex_url)) : ?>
            <div class="yvo-cab-login-yandex">
                <?php
                if (function_exists('yvo_yandex_oauth_button_html')) {
                    echo yvo_yandex_oauth_button_html(
                        $redirect,
                        'Войти через Яндекс',
                        'yvo-cabinet-btn yvo-cabinet-btn-yandex yvo-cab-login-yandex-btn btn3d'
                    );
                } else {
                    ?>
                    <a class="yvo-cabinet-btn yvo-cabinet-btn-yandex yvo-cab-login-yandex-btn btn3d" href="<?php echo esc_url($yandex_url); ?>">Войти через Яндекс</a>
                    <?php
                }
                ?>
                <p class="yvo-cabinet-yandex-hint">Первый вход создаёт аккаунт автоматически. Один Яндекс ID — один профиль на сайте.</p>
            </div>
            <div class="yvo-cabinet-divider" aria-hidden="true"><span>или логин и пароль</span></div>
        <?php elseif (current_user_can('manage_options')) : ?>
            <p class="yvo-cabinet-oauth-missing">
                Вход через Яндекс не настроен. Откройте
                <a href="<?php echo esc_url(admin_url('admin.php?page=yandex-ocr-pro-settings')); ?>">настройки плагина</a>
                и укажите Client ID и Client Secret. Redirect URI:
                <code><?php echo esc_html(home_url('/?yvo_oauth=yandex&yvo_oauth_action=callback')); ?></code>
            </p>
        <?php endif; ?>

        <form method="post" action="" class="yvo-cabinet-form yvo-cab-login-form">
            <?php wp_nonce_field('yvo_cabinet_login', 'yvo_cabinet_login_nonce'); ?>
            <?php if ($redirect) : ?>
                <input type="hidden" name="yvo_redirect" value="<?php echo esc_attr($redirect); ?>">
            <?php endif; ?>
            <p class="yvo-cabinet-field">
                <label for="yvo-login-login">Логин</label>
                <input type="text" id="yvo-login-login" name="yvo_login" required autocomplete="username" placeholder="Email или имя пользователя">
            </p>
            <p class="yvo-cabinet-field">
                <label for="yvo-login-password">Пароль</label>
                <input type="password" id="yvo-login-password" name="yvo_password" required autocomplete="current-password" placeholder="••••••••">
            </p>
            <p class="yvo-cabinet-submit">
                <button type="submit" name="yvo_do_login" class="yvo-cabinet-btn yvo-cabinet-btn-primary yvo-cab-login-submit btn3d">Войти</button>
            </p>
            <p class="yvo-cabinet-links">
                <a href="<?php echo esc_url($register_url); ?>" class="yvo-cabinet-link">Регистрация</a>
                <?php if ($forgot_url !== '') : ?>
                    <span class="yvo-cab-login-links-sep" aria-hidden="true">·</span>
                    <a href="<?php echo esc_url($forgot_url); ?>" class="yvo-cabinet-link">Забыли пароль?</a>
                <?php endif; ?>
            </p>
        </form>
    </div>
</div>
