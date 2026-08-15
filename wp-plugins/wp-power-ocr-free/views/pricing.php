<?php
if (!defined('ABSPATH')) {
    exit;
}
$profile_balance = function_exists('yvo_cabinet_get_profile_url') ? yvo_cabinet_get_profile_url() : home_url('/');
$login = function_exists('yvo_cabinet_get_login_url') ? yvo_cabinet_get_login_url() : home_url('/');
$plan = is_user_logged_in() && function_exists('yvo_tariff_get_plan') ? yvo_tariff_get_plan(get_current_user_id()) : 'free';

$price_standard = defined('YVO_TARIFF_PRICE_STANDARD') ? YVO_TARIFF_PRICE_STANDARD : 200;
$price_complex = defined('YVO_TARIFF_PRICE_COMPLEX') ? YVO_TARIFF_PRICE_COMPLEX : 390;
$price_pro = defined('YVO_TARIFF_PRICE_PRO') ? YVO_TARIFF_PRICE_PRO : 690;
$price_business = defined('YVO_TARIFF_PRICE_BUSINESS') ? YVO_TARIFF_PRICE_BUSINESS : 1200;
$credits_pro = defined('YVO_TARIFF_CREDITS_PRO') ? YVO_TARIFF_CREDITS_PRO : 10;
$credits_business = defined('YVO_TARIFF_CREDITS_BUSINESS') ? YVO_TARIFF_CREDITS_BUSINESS : 30;
$promo_active = function_exists('yvo_tariff_promo_active') && yvo_tariff_promo_active();
$price_standard_was = defined('YVO_TARIFF_PRICE_STANDARD_FULL') ? YVO_TARIFF_PRICE_STANDARD_FULL : 200;
$price_complex_was = defined('YVO_TARIFF_PRICE_COMPLEX_FULL') ? YVO_TARIFF_PRICE_COMPLEX_FULL : 390;
$price_pro_was = defined('YVO_TARIFF_PRICE_PRO_FULL') ? YVO_TARIFF_PRICE_PRO_FULL : 690;
$price_business_was = defined('YVO_TARIFF_PRICE_BUSINESS_FULL') ? YVO_TARIFF_PRICE_BUSINESS_FULL : 1200;

// На время акции цена списания с кошелька = акционная.
if ($promo_active && (string) get_option('yvo_generation_price_rub', '') !== (string) $price_standard) {
    update_option('yvo_generation_price_rub', (string) $price_standard);
}

if (!empty($_GET['yvo_paid'])) {
    echo '<div class="yvo-pr-paid-banner" role="status" style="margin:0 0 16px;padding:14px 16px;border-radius:10px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:15px;">Оплата получена. Если тариф ещё не обновился — обновите страницу через несколько секунд (ожидаем подтверждение от ЮKassa).</div>';
}

/**
 * @param string $plan_id
 * @param string $current
 */
function yvo_pricing_render_select($plan_id, $current, $btn_class, $btn_label) {
    if ($current === $plan_id) {
        echo '<span class="yvo-pr-tier__current">' . esc_html__('Текущий тариф', 'yandex-vision-ocr-pro') . '</span>';
        return;
    }
    ?>
    <form method="post" action="" class="yvo-pr-tier__form">
        <?php wp_nonce_field('yvo_select_plan', 'yvo_select_plan_nonce'); ?>
        <input type="hidden" name="yvo_select_plan" value="1">
        <input type="hidden" name="yvo_plan" value="<?php echo esc_attr($plan_id); ?>">
        <button type="submit" class="yvo-pr-btn <?php echo esc_attr($btn_class); ?>"><?php echo esc_html($btn_label); ?></button>
    </form>
    <?php
}

/**
 * @param string $class
 */
function yvo_pricing_render_foot($plan_key, $login, $profile_balance, $plan, $price_standard, $price_pro, $price_business, $credits_pro, $credits_business, $class = 'yvo-pr-btn--ghost') {
    if (!is_user_logged_in()) {
        echo '<a class="yvo-pr-btn ' . esc_attr($class) . '" href="' . esc_url($login) . '">' . esc_html__('Войти', 'yandex-vision-ocr-pro') . '</a>';
        return;
    }
    if ($plan_key === 'free') {
        yvo_pricing_render_select('free', $plan, 'yvo-pr-btn--ghost', __('Выбрать', 'yandex-vision-ocr-pro'));
        return;
    }
    if ($plan_key === 'onetime') {
        if (function_exists('yvo_yookassa_render_pay_button')) {
            echo yvo_yookassa_render_pay_button('topup_200', 'Оплатить ' . number_format($price_standard, 0, '', ' ') . ' ₽', 'yvo-pr-btn yvo-pr-btn--primary');
        }
        if (!function_exists('yvo_yookassa_is_enabled') || !yvo_yookassa_is_enabled()) {
            echo '<p class="yvo-pr-tier__pay-hint">' . esc_html__('Оплата временно недоступна. Подключите ЮKassa в настройках.', 'yandex-vision-ocr-pro') . '</p>';
        }
        echo '<a class="yvo-pr-tier__link" href="' . esc_url($profile_balance) . '">' . esc_html__('Баланс в профиле', 'yandex-vision-ocr-pro') . '</a>';
        return;
    }
    if ($plan_key === 'pro') {
        if (function_exists('yvo_yookassa_render_pay_button')) {
            echo yvo_yookassa_render_pay_button('plan_pro', 'Оплатить ' . number_format($price_pro, 0, '', ' ') . ' ₽', 'yvo-pr-btn yvo-pr-btn--primary');
        }
        if (!function_exists('yvo_yookassa_is_enabled') || !yvo_yookassa_is_enabled()) {
            echo '<p class="yvo-pr-tier__pay-hint">' . esc_html__('Оплата временно недоступна. Подключите ЮKassa в настройках.', 'yandex-vision-ocr-pro') . '</p>';
        }
        echo '<a class="yvo-pr-tier__link" href="' . esc_url($profile_balance) . '">' . esc_html__('Баланс в профиле', 'yandex-vision-ocr-pro') . '</a>';
        return;
    }
    if ($plan_key === 'business') {
        if (function_exists('yvo_yookassa_render_pay_button')) {
            echo yvo_yookassa_render_pay_button('plan_business', 'Оплатить ' . number_format($price_business, 0, '', ' ') . ' ₽', 'yvo-pr-btn yvo-pr-btn--primary');
        }
        if (!function_exists('yvo_yookassa_is_enabled') || !yvo_yookassa_is_enabled()) {
            echo '<p class="yvo-pr-tier__pay-hint">' . esc_html__('Оплата временно недоступна. Подключите ЮKassa в настройках.', 'yandex-vision-ocr-pro') . '</p>';
        }
        echo '<a class="yvo-pr-tier__link" href="' . esc_url($profile_balance) . '">' . esc_html__('Баланс в профиле', 'yandex-vision-ocr-pro') . '</a>';
    }
}
/**
 * SVG-иконка тарифа.
 *
 * @param string $variant free|onetime|pro|business
 */
function yvo_pricing_tier_icon($variant) {
    $icons = array(
        'free' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2l2.4 4.8L20 8l-4 3.6L17 18l-5-2.8L7 18l1-6.4L4 8l5.6-1.2L12 2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.2" fill="currentColor" opacity=".35"/></svg>',
        'onetime' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 10h18" stroke="currentColor" stroke-width="1.6"/><path d="M7 15h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'pro' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3l2.6 5.3 5.9.9-4.2 4.1 1 5.8L12 16.8 6.7 19l1-5.8-4.2-4.1 5.9-.9L12 3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
        'business' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 20V9l8-4 8 4v11" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 20v-5h6v5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 12h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
    );
    $svg = isset($icons[$variant]) ? $icons[$variant] : $icons['free'];
    echo '<div class="yvo-pr-tier__icon yvo-pr-tier__icon--' . esc_attr($variant) . '">' . $svg . '</div>';
}
?>
<div class="yvo-pr-page yvo-pr-stage">
    <div class="yvo-pr-bg" aria-hidden="true">
        <span class="yvo-pr-orb yvo-pr-orb--1"></span>
        <span class="yvo-pr-orb yvo-pr-orb--2"></span>
        <span class="yvo-pr-orb yvo-pr-orb--3"></span>
    </div>
    <div class="yvo-pr-wrap">
        <header class="yvo-pr-head">
            <h1 class="yvo-pr-head__title"><?php esc_html_e('Тарифы', 'yandex-vision-ocr-pro'); ?> <span>АРР</span></h1>
            <p class="yvo-pr-head__lead"><?php esc_html_e('Прозрачные цены без скрытых платежей. Начните бесплатно или выберите план под ваш объём сделок.', 'yandex-vision-ocr-pro'); ?></p>
            <?php if ($promo_active) : ?>
                <div class="yvo-pr-promo" role="status">
                    <span class="yvo-pr-promo__badge">Акция</span>
                    <div class="yvo-pr-promo__text">
                        <strong><?php echo esc_html(defined('YVO_TARIFF_PROMO_TITLE') ? YVO_TARIFF_PROMO_TITLE : 'Акция'); ?></strong>
                        <span><?php echo esc_html(defined('YVO_TARIFF_PROMO_NOTE') ? YVO_TARIFF_PROMO_NOTE : ''); ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['yvo_pay_required'])) : ?>
                <p class="yvo-pr-head__alert"><?php esc_html_e('Платные тарифы оформляются через кнопку «Оплатить» (ЮKassa).', 'yandex-vision-ocr-pro'); ?></p>
            <?php endif; ?>
            <?php if (!empty($_GET['yvo_pay_error'])) : ?>
                <p class="yvo-pr-head__alert" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;"><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['yvo_pay_error'])))); ?></p>
            <?php endif; ?>
            <?php if (function_exists('yvo_yookassa_is_enabled') && yvo_yookassa_is_enabled()) : ?>
                <p class="yvo-pr-head__note"><?php esc_html_e('Оплата картой или СБП. Тариф активируется автоматически.', 'yandex-vision-ocr-pro'); ?></p>
            <?php endif; ?>
        </header>

        <div class="yvo-pr-tiers">
            <article class="yvo-pr-tier card3d yvo-pr-tier--free" style="--tier-i:0">
                <?php yvo_pricing_tier_icon('free'); ?>
                <div class="yvo-pr-tier__top">
                    <h2 class="yvo-pr-tier__name"><?php esc_html_e('Бесплатный', 'yandex-vision-ocr-pro'); ?></h2>
                    <p class="yvo-pr-tier__desc"><?php esc_html_e('Попробовать сервис', 'yandex-vision-ocr-pro'); ?></p>
                </div>
                <p class="yvo-pr-tier__price"><span class="yvo-pr-tier__amount">0</span><span class="yvo-pr-tier__cur">₽</span></p>
                <ul class="yvo-pr-tier__features">
                    <li><?php esc_html_e('ДКП квартира (базовый шаблон)', 'yandex-vision-ocr-pro'); ?></li>
                    <li><?php esc_html_e('Ручной ввод полей', 'yandex-vision-ocr-pro'); ?></li>
                    <li class="yvo-pr-tier__off"><?php esc_html_e('Без OCR и автозаполнения', 'yandex-vision-ocr-pro'); ?></li>
                    <li class="yvo-pr-tier__off"><?php esc_html_e('Без проверки и «Сделок»', 'yandex-vision-ocr-pro'); ?></li>
                </ul>
                <div class="yvo-pr-tier__foot">
                    <?php
                    if (!is_user_logged_in()) {
                        echo '<a class="yvo-pr-btn yvo-pr-btn--ghost" href="' . esc_url($login) . '">' . esc_html__('Войти и начать', 'yandex-vision-ocr-pro') . '</a>';
                    } else {
                        yvo_pricing_render_select('free', $plan, 'yvo-pr-btn--ghost', __('Выбрать', 'yandex-vision-ocr-pro'));
                    }
                    ?>
                </div>
            </article>

            <article class="yvo-pr-tier card3d yvo-pr-tier--onetime" style="--tier-i:1">
                <?php yvo_pricing_tier_icon('onetime'); ?>
                <div class="yvo-pr-tier__top">
                    <h2 class="yvo-pr-tier__name"><?php esc_html_e('Разовая оплата', 'yandex-vision-ocr-pro'); ?></h2>
                    <p class="yvo-pr-tier__desc"><?php esc_html_e('Без подписки', 'yandex-vision-ocr-pro'); ?></p>
                </div>
                <p class="yvo-pr-tier__price">
                    <?php if ($promo_active) : ?>
                        <span class="yvo-pr-tier__was"><?php echo esc_html(number_format($price_standard_was, 0, '', ' ')); ?> / <?php echo esc_html(number_format($price_complex_was, 0, '', ' ')); ?> ₽</span>
                    <?php endif; ?>
                    <span class="yvo-pr-tier__amount"><?php echo esc_html(number_format($price_standard, 0, '', ' ')); ?></span>
                    <span class="yvo-pr-tier__sep">/</span>
                    <span class="yvo-pr-tier__amount yvo-pr-tier__amount--sm"><?php echo esc_html(number_format($price_complex, 0, '', ' ')); ?></span>
                    <span class="yvo-pr-tier__cur">₽</span>
                </p>
                <?php if ($promo_active) : ?><p class="yvo-pr-tier__promo-tag">по акции</p><?php endif; ?>
                <p class="yvo-pr-tier__billing"><?php esc_html_e('за документ', 'yandex-vision-ocr-pro'); ?></p>
                <ul class="yvo-pr-tier__features">
                    <li><?php printf(esc_html__('%s ₽ — стандартный договор', 'yandex-vision-ocr-pro'), number_format($price_standard, 0, '', ' ')); ?></li>
                    <li><?php printf(esc_html__('%s ₽ — сложный договор', 'yandex-vision-ocr-pro'), number_format($price_complex, 0, '', ' ')); ?></li>
                    <li><?php esc_html_e('Все шаблоны и автозаполнение', 'yandex-vision-ocr-pro'); ?></li>
                    <li><?php esc_html_e('Списание с кошелька', 'yandex-vision-ocr-pro'); ?></li>
                </ul>
                <div class="yvo-pr-tier__foot">
                    <?php yvo_pricing_render_foot('onetime', $login, $profile_balance, $plan, $price_standard, $price_pro, $price_business, $credits_pro, $credits_business); ?>
                </div>
            </article>

            <article class="yvo-pr-tier card3d yvo-pr-tier--pro yvo-pr-tier--featured" style="--tier-i:2">
                <span class="yvo-pr-tier__badge"><?php esc_html_e('Рекомендуем', 'yandex-vision-ocr-pro'); ?></span>
                <?php yvo_pricing_tier_icon('pro'); ?>
                <div class="yvo-pr-tier__top">
                    <h2 class="yvo-pr-tier__name"><?php esc_html_e('Про', 'yandex-vision-ocr-pro'); ?></h2>
                    <p class="yvo-pr-tier__desc"><?php esc_html_e('Для риелтора', 'yandex-vision-ocr-pro'); ?></p>
                </div>
                <p class="yvo-pr-tier__price">
                    <?php if ($promo_active) : ?>
                        <span class="yvo-pr-tier__was"><?php echo esc_html(number_format($price_pro_was, 0, '', ' ')); ?> ₽</span>
                    <?php endif; ?>
                    <span class="yvo-pr-tier__amount"><?php echo esc_html(number_format($price_pro, 0, '', ' ')); ?></span>
                    <span class="yvo-pr-tier__cur">₽</span>
                </p>
                <?php if ($promo_active) : ?><p class="yvo-pr-tier__promo-tag">по акции</p><?php endif; ?>
                <p class="yvo-pr-tier__billing"><?php esc_html_e('в месяц', 'yandex-vision-ocr-pro'); ?></p>
                <ul class="yvo-pr-tier__features">
                    <li><?php printf(esc_html__('~%d генераций в месяц', 'yandex-vision-ocr-pro'), (int) $credits_pro); ?></li>
                    <li><?php esc_html_e('Автозаполнение и все шаблоны', 'yandex-vision-ocr-pro'); ?></li>
                    <li><?php esc_html_e('Проверка договора', 'yandex-vision-ocr-pro'); ?></li>
                    <li><?php esc_html_e('Личный кабинет «Сделки»', 'yandex-vision-ocr-pro'); ?></li>
                </ul>
                <div class="yvo-pr-tier__foot">
                    <?php yvo_pricing_render_foot('pro', $login, $profile_balance, $plan, $price_standard, $price_pro, $price_business, $credits_pro, $credits_business, 'yvo-pr-btn--primary'); ?>
                </div>
            </article>

            <article class="yvo-pr-tier card3d yvo-pr-tier--business yvo-pr-tier--premium" style="--tier-i:3">
                <?php yvo_pricing_tier_icon('business'); ?>
                <div class="yvo-pr-tier__top">
                    <h2 class="yvo-pr-tier__name"><?php esc_html_e('Бизнес', 'yandex-vision-ocr-pro'); ?></h2>
                    <p class="yvo-pr-tier__desc"><?php esc_html_e('Для агентства', 'yandex-vision-ocr-pro'); ?></p>
                </div>
                <p class="yvo-pr-tier__price">
                    <?php if ($promo_active) : ?>
                        <span class="yvo-pr-tier__was"><?php echo esc_html(number_format($price_business_was, 0, '', ' ')); ?> ₽</span>
                    <?php endif; ?>
                    <span class="yvo-pr-tier__amount"><?php echo esc_html(number_format($price_business, 0, '', ' ')); ?></span>
                    <span class="yvo-pr-tier__cur">₽</span>
                </p>
                <?php if ($promo_active) : ?><p class="yvo-pr-tier__promo-tag">по акции</p><?php endif; ?>
                <p class="yvo-pr-tier__billing"><?php esc_html_e('в месяц', 'yandex-vision-ocr-pro'); ?></p>
                <ul class="yvo-pr-tier__features">
                    <li><?php printf(esc_html__('~%d генераций в месяц', 'yandex-vision-ocr-pro'), (int) $credits_business); ?></li>
                    <li><?php esc_html_e('Всё из тарифа «Про»', 'yandex-vision-ocr-pro'); ?></li>
                    <li><?php esc_html_e('Расширенная проверка', 'yandex-vision-ocr-pro'); ?></li>
                    <li><?php esc_html_e('Экспорт сделок', 'yandex-vision-ocr-pro'); ?></li>
                </ul>
                <div class="yvo-pr-tier__foot">
                    <?php yvo_pricing_render_foot('business', $login, $profile_balance, $plan, $price_standard, $price_pro, $price_business, $credits_pro, $credits_business); ?>
                </div>
            </article>
        </div>

        <p class="yvo-pr-footnote"><?php esc_html_e('Сложные договоры в подписке = 2 генерации. Неиспользованные генерации не переносятся. Сверх лимита — списание по разовым ценам.', 'yandex-vision-ocr-pro'); ?></p>

        <section class="yvo-pr-compare card3d yvo-pr-reveal" aria-labelledby="yvo-pr-compare-title">
            <h2 id="yvo-pr-compare-title" class="yvo-pr-compare__title"><?php esc_html_e('Сравнение', 'yandex-vision-ocr-pro'); ?></h2>
            <div class="yvo-pr-compare__scroll">
                <table class="yvo-pr-compare__table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Возможность', 'yandex-vision-ocr-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Бесплатно', 'yandex-vision-ocr-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Разово', 'yandex-vision-ocr-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Про', 'yandex-vision-ocr-pro'); ?></th>
                            <th scope="col"><?php esc_html_e('Бизнес', 'yandex-vision-ocr-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php esc_html_e('ДКП квартира', 'yandex-vision-ocr-pro'); ?></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Все шаблоны', 'yandex-vision-ocr-pro'); ?></td>
                            <td><span class="yvo-pr-dash">—</span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Автозаполнение', 'yandex-vision-ocr-pro'); ?></td>
                            <td><span class="yvo-pr-dash">—</span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Проверка договора', 'yandex-vision-ocr-pro'); ?></td>
                            <td><span class="yvo-pr-dash">—</span></td>
                            <td><span class="yvo-pr-dash">—</span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('ЛК «Сделки»', 'yandex-vision-ocr-pro'); ?></td>
                            <td><span class="yvo-pr-dash">—</span></td>
                            <td><span class="yvo-pr-dash">—</span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                            <td><span class="yvo-pr-check" aria-hidden="true"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Генераций / мес.', 'yandex-vision-ocr-pro'); ?></td>
                            <td><?php esc_html_e('—', 'yandex-vision-ocr-pro'); ?></td>
                            <td><?php esc_html_e('по оплате', 'yandex-vision-ocr-pro'); ?></td>
                            <td>~<?php echo (int) $credits_pro; ?></td>
                            <td>~<?php echo (int) $credits_business; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
