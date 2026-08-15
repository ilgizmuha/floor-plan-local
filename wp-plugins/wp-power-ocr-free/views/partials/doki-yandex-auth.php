<?php
/**
 * Блок входа через Яндекс на главной ДОКИ (для гостей).
 */
if (!defined('ABSPATH')) {
    exit;
}
if (is_user_logged_in()) {
    return;
}

$redirect = function_exists('yvo_cabinet_get_contracts_url') ? yvo_cabinet_get_contracts_url() : home_url('/');
$yandex_btn = function_exists('yvo_yandex_oauth_button_html')
    ? yvo_yandex_oauth_button_html($redirect, __('Войти или зарегистрироваться через Яндекс', 'yandex-vision-ocr-pro'), 'doki-yandex-auth__btn yvo-cabinet-btn yvo-cabinet-btn-yandex')
    : '';
$login_url = function_exists('yvo_cabinet_get_login_url') ? yvo_cabinet_get_login_url() : wp_login_url($redirect);
?>
<section class="doki-yandex-auth" aria-label="<?php esc_attr_e('Вход в сервис', 'yandex-vision-ocr-pro'); ?>">
    <div class="doki-yandex-auth__inner">
        <?php if ($yandex_btn !== '') : ?>
            <p class="doki-yandex-auth__lead"><?php esc_html_e('Войдите через Яндекс ID — первый вход создаст аккаунт автоматически.', 'yandex-vision-ocr-pro'); ?></p>
            <p class="doki-yandex-auth__action"><?php echo $yandex_btn; ?></p>
            <p class="doki-yandex-auth__alt">
                <?php esc_html_e('или', 'yandex-vision-ocr-pro'); ?>
                <a href="<?php echo esc_url($login_url); ?>"><?php esc_html_e('логин и пароль', 'yandex-vision-ocr-pro'); ?></a>
            </p>
        <?php elseif (current_user_can('manage_options')) : ?>
            <p class="doki-yandex-auth__missing">
                <?php esc_html_e('Вход через Яндекс не настроен. Укажите Client ID и Secret в', 'yandex-vision-ocr-pro'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=yandex-ocr-pro-settings')); ?>"><?php esc_html_e('настройках плагина', 'yandex-vision-ocr-pro'); ?></a>.
            </p>
        <?php endif; ?>
    </div>
</section>
