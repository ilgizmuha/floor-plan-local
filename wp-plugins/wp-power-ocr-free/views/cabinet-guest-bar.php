<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var string $home */
/** @var string $login */
/** @var string $reg */
/** @var string $pricing */
/** @var string $templates */
/** @var string $yvo_yandex_guest_url */
/** @var string $property_check */
/** @var string $yvo_oauth_error */
$yvo_oauth_error = isset($yvo_oauth_error) ? $yvo_oauth_error : '';
?>
<?php if ($yvo_oauth_error !== '') : ?>
<div class="yvo-cabinet-auth-message yvo-cabinet-error yvo-cabinet-oauth-banner" role="alert"><?php echo esc_html($yvo_oauth_error); ?></div>
<?php endif; ?>
<header class="yvo-cab-topbar yvo-cab-topbar--guest yvo-cab-topbar--compact" role="banner">
    <div class="yvo-cab-topbar-inner">
        <a class="yvo-cab-topbar-logo" href="<?php echo esc_url($home); ?>">АРР</a>

        <button type="button" class="yvo-cab-topbar-burger" aria-expanded="false" aria-controls="yvo-cab-topbar-panel-guest" aria-label="<?php esc_attr_e('Открыть меню', 'yandex-vision-ocr-pro'); ?>">
            <span class="yvo-cab-topbar-burger__bar" aria-hidden="true"></span>
            <span class="yvo-cab-topbar-burger__bar" aria-hidden="true"></span>
            <span class="yvo-cab-topbar-burger__bar" aria-hidden="true"></span>
        </button>

        <div class="yvo-cab-topbar-right" id="yvo-cab-topbar-panel-guest">
            <div class="yvo-cab-topbar-mobile-head yvo-cab-topbar-mobile-head--guest">
                <span class="yvo-cab-topbar-mobile-head__title"><?php esc_html_e('Меню', 'yandex-vision-ocr-pro'); ?></span>
                <button type="button" class="yvo-cab-topbar-close" aria-label="<?php esc_attr_e('Закрыть меню', 'yandex-vision-ocr-pro'); ?>">&times;</button>
            </div>
            <nav class="yvo-cab-topbar-nav yvo-cab-topbar-nav--guest" aria-label="Меню">
                <a class="yvo-cab-topbar-link" href="<?php echo esc_url($home); ?>"><?php esc_html_e('Главная', 'yandex-vision-ocr-pro'); ?></a>
                <?php
                $contracts = function_exists('yvo_cabinet_get_contracts_url') ? yvo_cabinet_get_contracts_url() : home_url('/doki/');
                ?>
                <a class="yvo-cab-topbar-link" href="<?php echo esc_url($contracts); ?>"><?php esc_html_e('Договоры', 'yandex-vision-ocr-pro'); ?></a>
                <?php if (!empty($property_check)) : ?>
                <a class="yvo-cab-topbar-link" href="<?php echo esc_url($property_check); ?>">Проверка квартиры</a>
                <?php endif; ?>
                <?php if (!empty($templates)) : ?>
                <a class="yvo-cab-topbar-link" href="<?php echo esc_url($templates); ?>">Шаблоны</a>
                <?php endif; ?>
                <a class="yvo-cab-topbar-link" href="<?php echo esc_url($pricing); ?>">Тарифы</a>
                <?php if (!empty($yvo_yandex_guest_url)) : ?>
                    <a class="yvo-cab-topbar-link yvo-cab-topbar-yandex" href="<?php echo esc_url($yvo_yandex_guest_url); ?>">Войти через Яндекс</a>
                <?php endif; ?>
                <a class="yvo-cab-topbar-link" href="<?php echo esc_url($login); ?>">Войти</a>
                <a class="yvo-cab-topbar-link yvo-cab-topbar-link--accent" href="<?php echo esc_url($reg); ?>">Регистрация</a>
            </nav>
        </div>
    </div>
    <div class="yvo-cab-topbar-backdrop" hidden aria-hidden="true"></div>
</header>
