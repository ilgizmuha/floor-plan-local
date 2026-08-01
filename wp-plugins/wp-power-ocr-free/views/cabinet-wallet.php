<?php
if (!defined('ABSPATH')) {
    exit;
}
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
?>
<div class="yvo-cabinet-auth yvo-cabinet-wallet-card">
    <h2 class="yvo-cabinet-auth-title">Кошелёк</h2>
    <p class="yvo-wallet-meta"><strong>Тариф:</strong> <?php echo esc_html($plan_label); ?></p>
    <?php if ($plan === 'onetime') : ?>
        <p class="yvo-wallet-meta"><strong>Оплата:</strong> <?php echo esc_html(number_format(defined('YVO_TARIFF_PRICE_STANDARD') ? YVO_TARIFF_PRICE_STANDARD : 200, 0, '', ' ')); ?> / <?php echo esc_html(number_format(defined('YVO_TARIFF_PRICE_COMPLEX') ? YVO_TARIFF_PRICE_COMPLEX : 390, 0, '', ' ')); ?> ₽ за договор с кошелька</p>
    <?php endif; ?>
    <?php if (in_array($plan, array('pro', 'business'), true)) : ?>
        <p class="yvo-wallet-meta"><strong>Осталось генераций:</strong> <?php echo (int) $credits; ?></p>
        <p class="yvo-wallet-meta"><strong>Подписка до:</strong> <?php echo esc_html($sub_text); ?></p>
    <?php elseif ($credits > 0) : ?>
        <p class="yvo-wallet-meta"><strong>Осталось генераций:</strong> <?php echo (int) $credits; ?></p>
    <?php endif; ?>
    <p class="yvo-wallet-balance"><span class="yvo-wallet-sum"><?php echo esc_html($bal); ?></span> <span class="yvo-wallet-cur">₽</span></p>
    <p class="yvo-cabinet-yandex-hint">Бесплатный тариф — только ДКП квартира, без автозаполнения. Платные тарифы: разовая оплата с кошелька (200 / 390 ₽) или подписка Про / Бизнес с лимитом генераций.</p>
    <p class="yvo-cabinet-links">
        <a class="yvo-cabinet-btn yvo-cabinet-btn-primary" href="<?php echo esc_url(isset($yvo_pricing_url) ? $yvo_pricing_url : '#'); ?>">Тарифы</a>
        <a class="yvo-cabinet-btn yvo-cabinet-btn-secondary" href="<?php echo esc_url(isset($yvo_pricing_url) ? $yvo_pricing_url : '#'); ?>">Пополнить</a>
        <a class="yvo-cabinet-link" href="<?php echo esc_url(isset($yvo_contracts_url) ? $yvo_contracts_url : '#'); ?>">← К договорам</a>
    </p>
    <p class="yvo-cabinet-yandex-hint" style="opacity:.75;">
        Пополнение через оплату подключается через WooCommerce. Пока можно выбрать тариф на странице «Тарифы», а администратор может начислить баланс/кредиты в блоке «Тест».
    </p>
    <?php if (current_user_can('manage_options')) : ?>
        <div class="yvo-wallet-admin-demo">
            <h3 class="yvo-wallet-admin-title">Тест (только администратор)</h3>
            <form method="post" action="">
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
