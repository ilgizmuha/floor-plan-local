<?php
if (!defined('ABSPATH')) {
    exit;
}
$user = isset($yvo_profile_user) ? $yvo_profile_user : wp_get_current_user();
$role_label = isset($yvo_profile_role_label) ? $yvo_profile_role_label : '';
$initial = isset($yvo_profile_avatar_initial) ? $yvo_profile_avatar_initial : '?';
$bal = isset($yvo_wallet_balance) ? $yvo_wallet_balance : '0';
$plan = isset($yvo_wallet_plan) ? $yvo_wallet_plan : 'free';
$plan_labels = function_exists('yvo_tariff_plan_labels') ? yvo_tariff_plan_labels() : array(
    'free' => 'Бесплатный',
    'onetime' => 'Разовая оплата',
    'pro' => 'Про',
    'business' => 'Бизнес',
);
$plan = function_exists('yvo_tariff_normalize_plan') ? yvo_tariff_normalize_plan($plan) : $plan;
$plan_label = isset($plan_labels[$plan]) ? $plan_labels[$plan] : $plan;
$credits = isset($yvo_wallet_credits) ? (int) $yvo_wallet_credits : 0;
$sub_until = isset($yvo_wallet_sub_until) ? (int) $yvo_wallet_sub_until : 0;
$sub_text = $sub_until > time() ? date_i18n('d.m.Y H:i', $sub_until) : '—';
$contracts_url = isset($yvo_contracts_url) ? $yvo_contracts_url : '#';
$pricing_url = isset($yvo_pricing_url) ? $yvo_pricing_url : '#';
$account_url = isset($yvo_account_url) ? $yvo_account_url : '#';
$logout_url = isset($yvo_logout_url) ? $yvo_logout_url : wp_logout_url(home_url('/'));
$deals_url = isset($yvo_profile_deals_url) ? $yvo_profile_deals_url : '#';
$show_deals = !empty($yvo_profile_show_deals);
if ($plan === 'free') {
    $sub_detail = 'Только ДКП квартира, без автозаполнения';
} elseif (in_array($plan, array('pro', 'business'), true)) {
    $sub_detail = $sub_until > time()
        ? sprintf('до %s · осталось генераций: %d', $sub_text, $credits)
        : 'подписка не активна';
} elseif ($plan === 'onetime') {
    $sub_detail = 'оплата с кошелька за каждый договор';
} else {
    $sub_detail = sprintf('осталось генераций: %d', $credits);
}
?>
<div class="yvo-cabinet-auth yvo-cabinet-profile-avito">
    <div class="yvo-prof-hero">
        <span class="yvo-prof-avatar" aria-hidden="true"><?php echo esc_html($initial); ?></span>
        <div class="yvo-prof-hero-text">
            <h2 class="yvo-prof-hero-name"><?php echo esc_html($user->display_name ? $user->display_name : $user->user_login); ?></h2>
            <p class="yvo-prof-hero-email"><?php echo esc_html($user->user_email); ?></p>
        </div>
    </div>

    <div class="yvo-prof-cards" id="yvo-profile-wallet">
        <div class="yvo-prof-card">
            <span class="yvo-prof-card-label">Баланс</span>
            <p class="yvo-prof-card-value"><span class="yvo-wallet-sum"><?php echo esc_html($bal); ?></span> <span class="yvo-wallet-cur">₽</span></p>
        </div>
        <div class="yvo-prof-card">
            <span class="yvo-prof-card-label">Подписка и тариф</span>
            <p class="yvo-prof-card-value yvo-prof-card-value--sm"><?php echo esc_html($plan_label); ?></p>
            <?php if ($sub_detail !== '') : ?>
            <p class="yvo-prof-card-sub"><?php echo esc_html($sub_detail); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <p class="yvo-prof-actions">
        <a class="yvo-cabinet-btn yvo-cabinet-btn-primary" href="<?php echo esc_url($pricing_url); ?>">Тарифы</a>
        <a class="yvo-cabinet-btn yvo-cabinet-btn-secondary" href="<?php echo esc_url($pricing_url); ?>">Пополнить</a>
    </p>
    <p class="yvo-cabinet-yandex-hint">Бесплатный тариф — только ДКП квартира, без автозаполнения. Платные тарифы: разовая оплата с кошелька (200 / 390 ₽) или подписка Про / Бизнес с лимитом генераций.</p>
    <p class="yvo-cabinet-yandex-hint" style="opacity:.75;">Пополнение через оплату подключается через WooCommerce. Пока можно выбрать тариф на странице «Тарифы», а администратор может начислить баланс/кредиты в блоке «Тест».</p>

    <nav class="yvo-prof-nav" aria-label="Разделы кабинета">
        <a class="yvo-prof-nav-item" href="<?php echo esc_url($contracts_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">▣</span>
            <span>Договоры</span>
        </a>
        <a class="yvo-prof-nav-item" href="<?php echo esc_url($pricing_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">◈</span>
            <span>Тарифы</span>
        </a>
        <a class="yvo-prof-nav-item" href="<?php echo esc_url($account_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">☰</span>
            <span>Личный кабинет</span>
        </a>
        <a class="yvo-prof-nav-item yvo-prof-nav-item--accent" href="<?php echo esc_url($pricing_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">✦</span>
            <span>Стать партнёром</span>
        </a>
        <a class="yvo-prof-nav-item yvo-prof-nav-item--logout" href="<?php echo esc_url($logout_url); ?>">
            <span class="yvo-prof-nav-icon" aria-hidden="true">⎋</span>
            <span>Выйти из профиля</span>
        </a>
    </nav>

    <details class="yvo-prof-details">
        <summary>Данные аккаунта</summary>
        <dl class="yvo-cab-profile-dl">
            <dt>Имя</dt>
            <dd><?php echo esc_html($user->display_name); ?></dd>
            <dt>Email</dt>
            <dd><?php echo esc_html($user->user_email); ?></dd>
            <dt>Логин</dt>
            <dd><code><?php echo esc_html($user->user_login); ?></code></dd>
            <?php if ($role_label !== '') : ?>
            <dt>Роль в сервисе</dt>
            <dd><?php echo esc_html($role_label); ?></dd>
            <?php endif; ?>
        </dl>
    </details>

    <p class="yvo-cabinet-links"><a class="yvo-cabinet-link" href="<?php echo esc_url($contracts_url); ?>">← К договорам</a></p>

    <?php if (current_user_can('manage_options')) : ?>
    <div class="yvo-wallet-admin-demo">
        <h3 class="yvo-wallet-admin-title">Тест (только администратор)</h3>
        <form class="yvo-cabinet-form" method="post" action="">
            <?php wp_nonce_field('yvo_wallet_demo', 'yvo_wallet_demo_nonce'); ?>
            <input type="hidden" name="yvo_wallet_demo" value="1">
            <p class="yvo-cabinet-field">
                <label for="yvo-demo-plan">Тариф</label>
                <select name="yvo_demo_plan" id="yvo-demo-plan">
                    <option value="free" <?php selected($plan, 'free'); ?>>Бесплатный</option>
                    <option value="onetime" <?php selected($plan, 'onetime'); ?>>Разовая оплата</option>
                    <option value="pro" <?php selected($plan, 'pro'); ?>>Про (<?php echo (int) (defined('YVO_TARIFF_CREDITS_PRO') ? YVO_TARIFF_CREDITS_PRO : 10); ?> ген.)</option>
                    <option value="business" <?php selected($plan, 'business'); ?>>Бизнес (<?php echo (int) (defined('YVO_TARIFF_CREDITS_BUSINESS') ? YVO_TARIFF_CREDITS_BUSINESS : 30); ?> ген.)</option>
                </select>
            </p>
            <p class="yvo-cabinet-field">
                <label for="yvo-demo-balance">Баланс (₽)</label>
                <input type="text" name="yvo_demo_balance" id="yvo-demo-balance" value="<?php echo esc_attr(str_replace(' ', '', (string) $bal)); ?>">
            </p>
            <p class="yvo-cabinet-submit">
                <button type="submit" class="button">Применить</button>
            </p>
        </form>
    </div>
    <?php endif; ?>
</div>
