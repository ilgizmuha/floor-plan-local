<?php
/**
 * Хаб «Личный кабинет» для тарифов без CRM «Сделки».
 */
if (!defined('ABSPATH')) {
    exit;
}
$user = isset($yvo_acc_user) ? $yvo_acc_user : wp_get_current_user();
$initial = isset($yvo_acc_initial) ? $yvo_acc_initial : '?';
$bal = isset($yvo_acc_balance) ? $yvo_acc_balance : '0';
$plan_label = isset($yvo_acc_plan_label) ? $yvo_acc_plan_label : '';
$sub_detail = isset($yvo_acc_sub_detail) ? $yvo_acc_sub_detail : '';
$contracts_url = isset($yvo_acc_contracts_url) ? $yvo_acc_contracts_url : '#';
$pricing_url = isset($yvo_acc_pricing_url) ? $yvo_acc_pricing_url : '#';
$profile_url = isset($yvo_acc_profile_url) ? $yvo_acc_profile_url : '#';
$property_check_url = isset($yvo_acc_property_check_url) ? $yvo_acc_property_check_url : '#';
$templates_url = isset($yvo_acc_templates_url) ? $yvo_acc_templates_url : '#';
$deals_url = isset($yvo_acc_deals_url) ? $yvo_acc_deals_url : '#';
$show_deals = !empty($yvo_acc_show_deals);
$logout_url = isset($yvo_acc_logout_url) ? $yvo_acc_logout_url : wp_logout_url(home_url('/'));
?>
<div class="yvo-cabinet-auth yvo-cabinet-account-hub">
    <div class="yvo-prof-hero">
        <span class="yvo-prof-avatar" aria-hidden="true"><?php echo esc_html($initial); ?></span>
        <div class="yvo-prof-hero-text">
            <h2 class="yvo-prof-hero-name"><?php esc_html_e('Личный кабинет', 'yandex-vision-ocr-pro'); ?></h2>
            <p class="yvo-prof-hero-email"><?php echo esc_html($user->display_name ? $user->display_name : $user->user_login); ?> · <?php echo esc_html($user->user_email); ?></p>
        </div>
    </div>

    <div class="yvo-prof-cards">
        <div class="yvo-prof-card">
            <span class="yvo-prof-card-label"><?php esc_html_e('Баланс', 'yandex-vision-ocr-pro'); ?></span>
            <p class="yvo-prof-card-value"><span class="yvo-wallet-sum"><?php echo esc_html($bal); ?></span> <span class="yvo-wallet-cur">₽</span></p>
        </div>
        <div class="yvo-prof-card">
            <span class="yvo-prof-card-label"><?php esc_html_e('Тариф', 'yandex-vision-ocr-pro'); ?></span>
            <p class="yvo-prof-card-value yvo-prof-card-value--sm"><?php echo esc_html($plan_label); ?></p>
            <?php if ($sub_detail !== '') : ?>
            <p class="yvo-prof-card-sub"><?php echo esc_html($sub_detail); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <p class="yvo-prof-actions">
        <a class="yvo-cabinet-btn yvo-cabinet-btn-primary" href="<?php echo esc_url($contracts_url); ?>"><?php esc_html_e('Создать договор', 'yandex-vision-ocr-pro'); ?></a>
        <a class="yvo-cabinet-btn yvo-cabinet-btn-secondary" href="<?php echo esc_url($pricing_url); ?>"><?php esc_html_e('Тарифы / Пополнить', 'yandex-vision-ocr-pro'); ?></a>
    </p>

    <?php if (!$show_deals) : ?>
    <div class="yvo-deal-cabinet-gate yvo-cabinet-auth" style="margin:12px 0;padding:14px;">
        <p style="margin:0 0 10px;"><?php esc_html_e('CRM сделок в личном кабинете доступна на тарифах Про и Бизнес с активной подпиской.', 'yandex-vision-ocr-pro'); ?></p>
        <a class="yvo-cabinet-btn yvo-cabinet-btn-primary" href="<?php echo esc_url($pricing_url); ?>"><?php esc_html_e('Открыть тарифы', 'yandex-vision-ocr-pro'); ?></a>
    </div>
    <?php endif; ?>

    <nav class="yvo-prof-nav" aria-label="<?php esc_attr_e('Разделы кабинета', 'yandex-vision-ocr-pro'); ?>">
        <a class="yvo-prof-nav-item" href="<?php echo esc_url($contracts_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">▣</span>
            <span><?php esc_html_e('Договоры', 'yandex-vision-ocr-pro'); ?></span>
        </a>
        <a class="yvo-prof-nav-item" href="<?php echo esc_url($templates_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">▤</span>
            <span><?php esc_html_e('Шаблоны', 'yandex-vision-ocr-pro'); ?></span>
        </a>
        <a class="yvo-prof-nav-item" href="<?php echo esc_url($property_check_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">⌕</span>
            <span><?php esc_html_e('Проверка квартиры', 'yandex-vision-ocr-pro'); ?></span>
        </a>
        <a class="yvo-prof-nav-item" href="<?php echo esc_url($pricing_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">◈</span>
            <span><?php esc_html_e('Тарифы', 'yandex-vision-ocr-pro'); ?></span>
        </a>
        <a class="yvo-prof-nav-item" href="<?php echo esc_url($profile_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">◎</span>
            <span><?php esc_html_e('Профиль', 'yandex-vision-ocr-pro'); ?></span>
        </a>
        <a class="yvo-prof-nav-item yvo-prof-nav-item--logout" href="<?php echo esc_url($logout_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">⎋</span>
            <span><?php esc_html_e('Выйти', 'yandex-vision-ocr-pro'); ?></span>
        </a>
    </nav>
</div>
