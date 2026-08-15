<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var WP_User $yvo_tb_user */
/** @var string $yvo_tb_initial */
/** @var string $yvo_tb_contracts_url */
/** @var string $yvo_tb_home_url */
/** @var string $yvo_tb_pricing_url */
/** @var string $yvo_tb_templates_url */
/** @var string $yvo_tb_account_url */
/** @var string $yvo_tb_profile_url */
/** @var string $yvo_tb_wallet_url */
/** @var string $yvo_tb_deals_url */
/** @var string $yvo_tb_property_check_url */
/** @var bool $yvo_tb_show_deals */
/** @var string $yvo_tb_balance_display */
/** @var string $yvo_tb_plan_label */
/** @var string $yvo_tb_sub_detail */
$yvo_tb_current = isset($yvo_tb_current) ? $yvo_tb_current : 'contracts';
$yvo_tb_home_url = isset($yvo_tb_home_url) ? $yvo_tb_home_url : (function_exists('yvo_cabinet_get_home_url') ? yvo_cabinet_get_home_url() : home_url('/'));
$yvo_tb_balance_display = isset($yvo_tb_balance_display) ? $yvo_tb_balance_display : '0';
$yvo_tb_plan_label = isset($yvo_tb_plan_label) ? $yvo_tb_plan_label : '';
$yvo_tb_sub_detail = isset($yvo_tb_sub_detail) ? $yvo_tb_sub_detail : '';
$yvo_tb_display_name = $yvo_tb_user->display_name ? $yvo_tb_user->display_name : $yvo_tb_user->user_login;
$yvo_tb_prof_active = ($yvo_tb_current === 'profile' || $yvo_tb_current === 'wallet');
?>
<!-- yvo-cab-topbar v3.5.0-compact -->
<header class="yvo-cab-topbar yvo-cab-topbar--compact" role="banner" data-yvo-plugin="<?php echo esc_attr(defined('YVO_VERSION') ? YVO_VERSION : ''); ?>">
    <div class="yvo-cab-topbar-inner">
        <a class="yvo-cab-topbar-logo" href="<?php echo esc_url($yvo_tb_home_url); ?>">АРР</a>

        <button type="button" class="yvo-cab-topbar-burger" aria-expanded="false" aria-controls="yvo-cab-topbar-panel" aria-label="<?php esc_attr_e('Открыть меню', 'yandex-vision-ocr-pro'); ?>">
            <span class="yvo-cab-topbar-burger__bar" aria-hidden="true"></span>
            <span class="yvo-cab-topbar-burger__bar" aria-hidden="true"></span>
            <span class="yvo-cab-topbar-burger__bar" aria-hidden="true"></span>
        </button>

        <div class="yvo-cab-topbar-right" id="yvo-cab-topbar-panel">
            <div class="yvo-cab-topbar-mobile-head">
                <div class="yvo-cab-topbar-mobile-user">
                    <span class="yvo-cab-topbar-avatar" aria-hidden="true"><?php echo esc_html($yvo_tb_initial); ?></span>
                    <div class="yvo-cab-topbar-mobile-user__text">
                        <span class="yvo-cab-topbar-name"><?php echo esc_html($yvo_tb_display_name); ?></span>
                        <span class="yvo-cab-topbar-mobile-meta">
                            <span class="yvo-wallet-sum"><?php echo esc_html($yvo_tb_balance_display); ?></span> ₽
                            <?php if ($yvo_tb_plan_label !== '') : ?>
                                · <?php echo esc_html($yvo_tb_plan_label); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <button type="button" class="yvo-cab-topbar-close" aria-label="<?php esc_attr_e('Закрыть меню', 'yandex-vision-ocr-pro'); ?>">&times;</button>
            </div>

            <nav class="yvo-cab-topbar-nav" aria-label="Кабинет">
                <a class="yvo-cab-topbar-link<?php echo $yvo_tb_current === 'home' ? ' is-active' : ''; ?>" href="<?php echo esc_url($yvo_tb_home_url); ?>"><?php esc_html_e('Главная', 'yandex-vision-ocr-pro'); ?></a>
                <a class="yvo-cab-topbar-link<?php echo $yvo_tb_current === 'contracts' ? ' is-active' : ''; ?>" href="<?php echo esc_url($yvo_tb_contracts_url); ?>">Договоры</a>
                <a class="yvo-cab-topbar-link<?php echo $yvo_tb_current === 'templates' ? ' is-active' : ''; ?>" href="<?php echo esc_url(isset($yvo_tb_templates_url) ? $yvo_tb_templates_url : '#'); ?>">Шаблоны</a>
                <a class="yvo-cab-topbar-link<?php echo $yvo_tb_current === 'property_check' ? ' is-active' : ''; ?>" href="<?php echo esc_url($yvo_tb_property_check_url); ?>">Проверка квартиры</a>
                <a class="yvo-cab-topbar-link<?php echo $yvo_tb_current === 'pricing' ? ' is-active' : ''; ?>" href="<?php echo esc_url($yvo_tb_pricing_url); ?>">Тарифы</a>
                <a class="yvo-cab-topbar-link yvo-cab-topbar-link--cabinet<?php echo ($yvo_tb_current === 'account' || $yvo_tb_current === 'deals') ? ' is-active' : ''; ?>" href="<?php echo esc_url($yvo_tb_account_url); ?>"><?php esc_html_e('Личный кабинет', 'yandex-vision-ocr-pro'); ?></a>
                <div class="yvo-cab-topbar-dropdown">
                    <span class="yvo-cab-topbar-link yvo-cab-topbar-dropdown__trigger<?php echo $yvo_tb_prof_active ? ' is-active' : ''; ?>" tabindex="0" role="button" aria-haspopup="true" id="yvo-cab-topbar-profile-trigger"><?php esc_html_e('Профиль', 'yandex-vision-ocr-pro'); ?></span>
                    <div class="yvo-cab-topbar-dropdown__panel" role="region" aria-labelledby="yvo-cab-topbar-profile-trigger">
                        <div class="yvo-cab-topbar-dropdown__panel-inner">
                            <div class="yvo-cab-topbar-dropdown__stats">
                                <div class="yvo-cab-topbar-dropdown__stat">
                                    <span class="yvo-cab-topbar-dropdown__stat-label"><?php esc_html_e('Баланс', 'yandex-vision-ocr-pro'); ?></span>
                                    <span class="yvo-cab-topbar-dropdown__stat-value"><span class="yvo-wallet-sum"><?php echo esc_html($yvo_tb_balance_display); ?></span> <span class="yvo-wallet-cur">₽</span></span>
                                </div>
                                <div class="yvo-cab-topbar-dropdown__stat">
                                    <span class="yvo-cab-topbar-dropdown__stat-label"><?php esc_html_e('Подписка и тариф', 'yandex-vision-ocr-pro'); ?></span>
                                    <span class="yvo-cab-topbar-dropdown__stat-value yvo-cab-topbar-dropdown__stat-value--sm"><?php echo esc_html($yvo_tb_plan_label); ?></span>
                                    <?php if ($yvo_tb_sub_detail !== '') : ?>
                                    <span class="yvo-cab-topbar-dropdown__stat-sub"><?php echo esc_html($yvo_tb_sub_detail); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <ul class="yvo-cab-topbar-dropdown__menu" role="menu">
                                <li role="none">
                                    <a class="yvo-cab-topbar-dropdown__item<?php echo $yvo_tb_current === 'wallet' ? ' is-active' : ''; ?>" role="menuitem" href="<?php echo esc_url($yvo_tb_wallet_url); ?>"><?php esc_html_e('Кошелёк', 'yandex-vision-ocr-pro'); ?></a>
                                </li>
                                <li role="none">
                                    <a class="yvo-cab-topbar-dropdown__item<?php echo $yvo_tb_current === 'profile' ? ' is-active' : ''; ?>" role="menuitem" href="<?php echo esc_url($yvo_tb_profile_url); ?>"><?php esc_html_e('Изменить профиль', 'yandex-vision-ocr-pro'); ?></a>
                                </li>
                                <li role="none">
                                    <a class="yvo-cab-topbar-dropdown__item<?php echo $yvo_tb_current === 'pricing' ? ' is-active' : ''; ?>" role="menuitem" href="<?php echo esc_url($yvo_tb_pricing_url); ?>"><?php esc_html_e('Стать партнёром', 'yandex-vision-ocr-pro'); ?></a>
                                </li>
                                <li role="none">
                                    <a class="yvo-cab-topbar-dropdown__item yvo-cab-topbar-dropdown__item--logout" role="menuitem" href="<?php echo esc_url($yvo_tb_logout_url); ?>"><?php esc_html_e('Выйти', 'yandex-vision-ocr-pro'); ?></a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>
            <div class="yvo-cab-topbar-user yvo-cab-topbar-user--desktop">
                <span class="yvo-cab-topbar-avatar" aria-hidden="true"><?php echo esc_html($yvo_tb_initial); ?></span>
                <span class="yvo-cab-topbar-name"><?php echo esc_html($yvo_tb_display_name); ?></span>
            </div>
        </div>
    </div>
    <div class="yvo-cab-topbar-backdrop" hidden aria-hidden="true"></div>
</header>
