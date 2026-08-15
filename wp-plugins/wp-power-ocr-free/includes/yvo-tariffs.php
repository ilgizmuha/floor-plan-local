<?php
/**
 * Тарифы, кошелёк и списание за генерацию договора.
 *
 * Планы: free | onetime | pro | business
 * (legacy: monthly → pro)
 *
 * Матрица функций: docs/TARIFF-FEATURE-MATRIX.md
 */
if (!defined('ABSPATH')) {
    exit;
}

define('YVO_META_TARIFF', 'yvo_tariff_plan');
define('YVO_META_SUB_UNTIL', 'yvo_subscription_until');
define('YVO_META_GEN_CREDITS', 'yvo_credit_generations');

/** Временная акция для проверки оплаты (выключить = false). */
define('YVO_TARIFF_PROMO_ACTIVE', true);
define('YVO_TARIFF_PROMO_FACTOR', 20);
define('YVO_TARIFF_PROMO_TITLE', 'Акция −95%: тестовые цены');
define('YVO_TARIFF_PROMO_NOTE', 'Временно цены снижены в 20 раз для проверки оплаты и подписки. После теста вернём обычные тарифы.');

define('YVO_TARIFF_PRICE_STANDARD_FULL', 200);
define('YVO_TARIFF_PRICE_COMPLEX_FULL', 390);
define('YVO_TARIFF_PRICE_PRO_FULL', 690);
define('YVO_TARIFF_PRICE_BUSINESS_FULL', 1200);
define('YVO_TARIFF_PRICE_PROPERTY_CHECK_FULL', 119);

define(
    'YVO_TARIFF_PRICE_STANDARD',
    YVO_TARIFF_PROMO_ACTIVE ? max(1, (int) round(YVO_TARIFF_PRICE_STANDARD_FULL / YVO_TARIFF_PROMO_FACTOR)) : YVO_TARIFF_PRICE_STANDARD_FULL
);
define(
    'YVO_TARIFF_PRICE_COMPLEX',
    YVO_TARIFF_PROMO_ACTIVE ? max(1, (int) round(YVO_TARIFF_PRICE_COMPLEX_FULL / YVO_TARIFF_PROMO_FACTOR)) : YVO_TARIFF_PRICE_COMPLEX_FULL
);
define(
    'YVO_TARIFF_PRICE_PRO',
    YVO_TARIFF_PROMO_ACTIVE ? max(1, (int) round(YVO_TARIFF_PRICE_PRO_FULL / YVO_TARIFF_PROMO_FACTOR)) : YVO_TARIFF_PRICE_PRO_FULL
);
define(
    'YVO_TARIFF_PRICE_BUSINESS',
    YVO_TARIFF_PROMO_ACTIVE ? max(1, (int) round(YVO_TARIFF_PRICE_BUSINESS_FULL / YVO_TARIFF_PROMO_FACTOR)) : YVO_TARIFF_PRICE_BUSINESS_FULL
);
define('YVO_TARIFF_CREDITS_PRO', 10);
define('YVO_TARIFF_CREDITS_BUSINESS', 30);
define('YVO_TARIFF_CREDIT_COST_COMPLEX', 2);
define(
    'YVO_TARIFF_PRICE_PROPERTY_CHECK',
    YVO_TARIFF_PROMO_ACTIVE ? max(1, (int) round(YVO_TARIFF_PRICE_PROPERTY_CHECK_FULL / YVO_TARIFF_PROMO_FACTOR)) : YVO_TARIFF_PRICE_PROPERTY_CHECK_FULL
);

/**
 * Активна ли временная акция на тарифы.
 */
function yvo_tariff_promo_active() {
    return defined('YVO_TARIFF_PROMO_ACTIVE') && YVO_TARIFF_PROMO_ACTIVE;
}

/**
 * @return string[]
 */
function yvo_tariff_valid_plans() {
    return array('free', 'onetime', 'pro', 'business', 'monthly');
}

/**
 * @param string $plan
 * @return string free|onetime|pro|business
 */
function yvo_tariff_normalize_plan($plan) {
    $plan = is_string($plan) ? sanitize_key($plan) : '';
    if ($plan === 'monthly') {
        return 'pro';
    }
    if (in_array($plan, array('free', 'onetime', 'pro', 'business'), true)) {
        return $plan;
    }
    return 'free';
}

/**
 * @return string free|onetime|pro|business
 */
function yvo_tariff_get_plan($user_id) {
    $p = get_user_meta($user_id, YVO_META_TARIFF, true);
    return yvo_tariff_normalize_plan(is_string($p) ? $p : '');
}

function yvo_tariff_is_free($user_id) {
    return yvo_tariff_get_plan($user_id) === 'free';
}

/**
 * @return array<string,string>
 */
function yvo_tariff_plan_labels() {
    return array(
        'free' => 'Бесплатный',
        'onetime' => 'Разовая оплата',
        'pro' => 'Про',
        'business' => 'Бизнес',
    );
}

function yvo_tariff_plan_label($plan) {
    $plan = yvo_tariff_normalize_plan($plan);
    $labels = yvo_tariff_plan_labels();
    return isset($labels[$plan]) ? $labels[$plan] : $plan;
}

function yvo_tariff_subscription_quota($plan) {
    $plan = yvo_tariff_normalize_plan($plan);
    if ($plan === 'pro') {
        return YVO_TARIFF_CREDITS_PRO;
    }
    if ($plan === 'business') {
        return YVO_TARIFF_CREDITS_BUSINESS;
    }
    return 0;
}

function yvo_tariff_subscription_active($user_id) {
    $plan = yvo_tariff_get_plan($user_id);
    if (!in_array($plan, array('pro', 'business'), true)) {
        return false;
    }
    $until = (int) get_user_meta($user_id, YVO_META_SUB_UNTIL, true);
    return $until > time();
}

/** Ограничения бесплатного тарифа — только при включённом биллинге. */
function yvo_tariff_apply_free_limits($user_id) {
    if (!yvo_tariff_billing_enforced()) {
        return false;
    }
    return yvo_tariff_is_free($user_id);
}

/**
 * Любой платный тариф (не free).
 */
function yvo_tariff_is_paid($user_id) {
    return !yvo_tariff_is_free($user_id);
}

/**
 * CRM «Сделки»: подписки Про и Бизнес (не разовая оплата).
 */
function yvo_tariff_has_deal_crm($user_id) {
    if (!$user_id) {
        return false;
    }
    if (!yvo_tariff_billing_enforced()) {
        return yvo_tariff_is_paid($user_id);
    }
    $plan = yvo_tariff_get_plan($user_id);
    if (!in_array($plan, array('pro', 'business'), true)) {
        return false;
    }
    return yvo_tariff_subscription_active($user_id);
}

/**
 * @deprecated Используйте yvo_tariff_has_deal_crm().
 */
function yvo_cabinet_user_can_see_deal_crm($user_id) {
    if (!$user_id) {
        return false;
    }
    if (apply_filters('yvo_cabinet_force_deal_crm', false, $user_id)) {
        return true;
    }
    if (user_can($user_id, 'manage_options')) {
        return true;
    }
    return yvo_tariff_has_deal_crm($user_id);
}

function yvo_tariff_can_use_autofill($user_id) {
    if (!yvo_tariff_billing_enforced() || user_can($user_id, 'manage_options')) {
        return true;
    }
    return !yvo_tariff_is_free($user_id);
}

function yvo_tariff_can_contract_check($user_id) {
    if (!yvo_tariff_billing_enforced() || user_can($user_id, 'manage_options')) {
        return true;
    }
    $plan = yvo_tariff_get_plan($user_id);
    if (!in_array($plan, array('pro', 'business'), true)) {
        return false;
    }
    return yvo_tariff_subscription_active($user_id);
}

/**
 * Цена разовой проверки квартиры (₽).
 */
function yvo_tariff_property_check_price_rub() {
    return (float) apply_filters('yvo_tariff_property_check_price_rub', (float) YVO_TARIFF_PRICE_PROPERTY_CHECK);
}

/**
 * Бесплатная проверка квартиры по подписке Про/Бизнес.
 */
function yvo_tariff_can_property_check_free($user_id) {
    return yvo_tariff_can_contract_check($user_id);
}

/**
 * Доступ к проверке квартиры: подписка или списание с кошелька.
 *
 * @return array{mode:string,price:float}|WP_Error
 */
function yvo_tariff_property_check_access($user_id) {
    if (!$user_id) {
        return new WP_Error('yvo_auth', 'Войдите или зарегистрируйтесь, чтобы проверить квартиру.');
    }
    if (user_can($user_id, 'manage_options') || !yvo_tariff_billing_enforced()) {
        return array('mode' => 'free', 'price' => 0.0);
    }
    if (yvo_tariff_can_property_check_free($user_id)) {
        return array('mode' => 'subscription', 'price' => 0.0);
    }
    $price = yvo_tariff_property_check_price_rub();
    $bal = yvo_tariff_get_balance($user_id);
    if ($bal >= $price) {
        return array('mode' => 'wallet', 'price' => $price);
    }
    $pricing = function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/');
    return new WP_Error(
        'yvo_tariff',
        sprintf(
            'Проверка квартиры — %s ₽ с кошелька или бесплатно по подписке «Про» / «Бизнес». Пополните кошелёк или выберите тариф.',
            number_format($price, 0, ',', ' ')
        ),
        array('pricing_url' => $pricing, 'price' => $price, 'wallet_balance' => $bal)
    );
}

/**
 * Списать оплату за проверку (если не подписка).
 *
 * @return array{mode:string,price:float}|WP_Error
 */
function yvo_tariff_charge_property_check($user_id) {
    $access = yvo_tariff_property_check_access($user_id);
    if (is_wp_error($access)) {
        return $access;
    }
    if ($access['mode'] === 'wallet' && $access['price'] > 0) {
        $bal = yvo_tariff_get_balance($user_id);
        update_user_meta($user_id, 'yvo_wallet_balance', (string) round($bal - $access['price'], 2));
    }
    return $access;
}

function yvo_tariff_can_property_check($user_id) {
    return !is_wp_error(yvo_tariff_property_check_access($user_id));
}

/**
 * Сложный договор (2 кредита / цена 390 ₽).
 */
function yvo_tariff_is_complex_generation($contract_type, $template_id = '') {
    $contract_type = sanitize_key((string) $contract_type);
    if (in_array($contract_type, array('share_allocation', 'gift', 'assignment', 'sale_mortgage'), true)) {
        return true;
    }
    $complex_templates = array(
        'dkp-kvartira-ipoteka',
        'shablon-vydelenie-doley-kvartira',
        'shablon-darenie-dogovor',
        'shablon-darenie-dolya-kvartira',
    );
    return in_array(sanitize_key((string) $template_id), $complex_templates, true);
}

/**
 * Сколько кредитов списать за генерацию.
 */
function yvo_tariff_generation_credit_cost($contract_type, $template_id = '') {
    return yvo_tariff_is_complex_generation($contract_type, $template_id)
        ? YVO_TARIFF_CREDIT_COST_COMPLEX
        : 1;
}

/**
 * Цена разовой генерации с кошелька (₽).
 */
function yvo_tariff_pay_per_use_price_rub($contract_type, $template_id = '') {
    return yvo_tariff_is_complex_generation($contract_type, $template_id)
        ? (float) YVO_TARIFF_PRICE_COMPLEX
        : (float) YVO_TARIFF_PRICE_STANDARD;
}

/**
 * Бесплатный тариф: только ДКП квартира (sale + apartment + шаблон default).
 */
function yvo_tariff_free_allows_generation($contract_type, $template_id, array $property) {
    $contract_type = sanitize_key((string) $contract_type);
    if ($contract_type !== 'sale') {
        $kind = 'этот тип договора';
        if ($contract_type === 'deposit_agreement') {
            $kind = 'договор задатка';
        } elseif ($contract_type === 'advance_agreement') {
            $kind = 'договор аванса';
        } elseif ($contract_type === 'gift') {
            $kind = 'договор дарения';
        } elseif ($contract_type === 'share_allocation') {
            $kind = 'соглашение о выделении долей';
        }
        $pricing = function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/');
        return new WP_Error(
            'yvo_tariff_free',
            sprintf(
                'На бесплатном тарифе доступен только договор купли-продажи квартиры (ДКП). Для «%s» выберите разовый тариф или подписку: %s',
                $kind,
                $pricing
            ),
            array('pricing_url' => $pricing)
        );
    }
    $object_type = isset($property['object_type']) ? sanitize_key((string) $property['object_type']) : '';
    if ($object_type !== '' && $object_type !== 'apartment') {
        return new WP_Error(
            'yvo_tariff_free',
            'На бесплатном тарифе доступен только объект «квартира». Для других типов объектов выберите платный тариф.'
        );
    }
    $template_id = sanitize_key((string) $template_id);
    if ($template_id !== '' && $template_id !== 'default') {
        return new WP_Error(
            'yvo_tariff_free',
            'На бесплатном тарифе доступен только базовый шаблон ДКП квартиры.'
        );
    }
    return true;
}

/**
 * Проверка перед генерацией (тип договора / шаблон / объект).
 */
function yvo_tariff_validate_generation($user_id, $contract_type, $template_id, array $property) {
    if (!yvo_tariff_billing_enforced() || user_can($user_id, 'manage_options')) {
        return true;
    }
    if (yvo_tariff_apply_free_limits($user_id)) {
        return yvo_tariff_free_allows_generation($contract_type, $template_id, $property);
    }
    return true;
}

/**
 * Бесплатный тариф: только шаблон default (ДКП квартира).
 */
function yvo_tariff_filter_templates_for_user($list, $user_id) {
    if (!is_array($list) || empty($list)) {
        return $list;
    }
    if (!yvo_tariff_apply_free_limits($user_id)) {
        return $list;
    }
    if (isset($list['default'])) {
        return array('default' => $list['default']);
    }
    $keys = array_keys($list);
    $first = $keys[0];
    return array($first => $list[$first]);
}

function yvo_tariff_billing_enforced() {
    $filtered = apply_filters('yvo_tariff_billing_enforced', null);
    if ($filtered !== null) {
        return (bool) $filtered;
    }
    return get_option('yvo_enforce_tariff_billing', '0') === '1';
}

/**
 * Можно ли сгенерировать договор (баланс / кредиты / подписка).
 */
function yvo_tariff_can_generate($user_id) {
    if (!$user_id) {
        return new WP_Error('yvo_auth', 'Войдите в аккаунт для генерации договора.');
    }
    if (user_can($user_id, 'manage_options')) {
        return true;
    }
    if (!yvo_tariff_billing_enforced()) {
        return true;
    }
    $plan = yvo_tariff_get_plan($user_id);
    if ($plan === 'free') {
        return true;
    }
    $credits = (int) get_user_meta($user_id, YVO_META_GEN_CREDITS, true);
    if ($credits > 0) {
        return true;
    }
    if (in_array($plan, array('pro', 'business'), true) && yvo_tariff_subscription_active($user_id)) {
        return new WP_Error(
            'yvo_tariff',
            'Лимит генераций по подписке исчерпан. Докупите генерации или дождитесь продления тарифа «' . yvo_tariff_plan_label($plan) . '».'
        );
    }
    $bal = yvo_tariff_get_balance($user_id);
    if ($bal >= YVO_TARIFF_PRICE_STANDARD) {
        return true;
    }
    return new WP_Error(
        'yvo_tariff',
        'Недостаточно средств или лимитов (тариф: ' . yvo_tariff_plan_label($plan) . '). Пополните кошелёк на странице «Тарифы» или выберите другой тариф.'
    );
}

function yvo_tariff_get_balance($user_id) {
    $raw = get_user_meta($user_id, 'yvo_wallet_balance', true);
    if ($raw === '' || $raw === false) {
        return 0.0;
    }
    return (float) str_replace(',', '.', (string) $raw);
}

/**
 * @deprecated Используйте yvo_tariff_pay_per_use_price_rub().
 */
function yvo_tariff_generation_price_rub() {
    $p = get_option('yvo_generation_price_rub', (string) YVO_TARIFF_PRICE_STANDARD);
    return max(0.0, (float) str_replace(',', '.', (string) $p));
}

/**
 * После успешной генерации: списание кредитов или баланса.
 *
 * @param int    $user_id
 * @param string $contract_type
 * @param string $template_id
 */
function yvo_tariff_after_generation_success($user_id, $contract_type = 'sale', $template_id = 'default') {
    if (!yvo_tariff_billing_enforced() || user_can($user_id, 'manage_options')) {
        return;
    }
    $plan = yvo_tariff_get_plan($user_id);
    if ($plan === 'free') {
        return;
    }
    $cost = yvo_tariff_generation_credit_cost($contract_type, $template_id);
    $credits = (int) get_user_meta($user_id, YVO_META_GEN_CREDITS, true);
    if ($credits >= $cost) {
        update_user_meta($user_id, YVO_META_GEN_CREDITS, max(0, $credits - $cost));
        return;
    }
    $price = yvo_tariff_pay_per_use_price_rub($contract_type, $template_id);
    if ($price <= 0) {
        return;
    }
    $bal = yvo_tariff_get_balance($user_id);
    if ($bal >= $price) {
        update_user_meta($user_id, 'yvo_wallet_balance', (string) round($bal - $price, 2));
    }
}

/**
 * Данные для фронтенда (ограничения тарифа).
 */
function yvo_tariff_frontend_payload($user_id) {
    $plan = yvo_tariff_get_plan($user_id);
    $billing_enforced = yvo_tariff_billing_enforced();
    $free_limits = $billing_enforced && ($plan === 'free');
    $paid = $billing_enforced && ($plan !== 'free');
    if (!$billing_enforced) {
        $free_limits = false;
        $paid = false;
    }
    $has_crm = function_exists('yvo_tariff_has_deal_crm') && yvo_tariff_has_deal_crm($user_id);
    return array(
        'plan' => $plan,
        'plan_label' => yvo_tariff_plan_label($plan),
        'billing_enforced' => $billing_enforced ? 1 : 0,
        'free_no_autofill' => $free_limits ? 1 : 0,
        'free_one_template' => $free_limits ? 1 : 0,
        'free_dkp_apartment_only' => $free_limits ? 1 : 0,
        'paid_tariff' => $paid ? 1 : 0,
        'can_autofill' => yvo_tariff_can_use_autofill($user_id) ? 1 : 0,
        'can_contract_check' => yvo_tariff_can_contract_check($user_id) ? 1 : 0,
        'can_property_check' => yvo_tariff_can_property_check($user_id) ? 1 : 0,
        'can_property_check_free' => yvo_tariff_can_property_check_free($user_id) ? 1 : 0,
        'property_check_price' => yvo_tariff_property_check_price_rub(),
        'property_check_url' => function_exists('yvo_cabinet_get_property_check_url') ? yvo_cabinet_get_property_check_url() : home_url('/'),
        'can_deal_crm' => $has_crm ? 1 : 0,
        'deal_cabinet_url' => ($has_crm && function_exists('yvo_cabinet_get_deals_url')) ? yvo_cabinet_get_deals_url() : '',
        'wallet_balance' => yvo_tariff_get_balance($user_id),
        'credits' => (int) get_user_meta($user_id, YVO_META_GEN_CREDITS, true),
        'subscription_until' => (int) get_user_meta($user_id, YVO_META_SUB_UNTIL, true),
        'subscription_active' => yvo_tariff_subscription_active($user_id) ? 1 : 0,
        'price_standard' => YVO_TARIFF_PRICE_STANDARD,
        'price_complex' => YVO_TARIFF_PRICE_COMPLEX,
        'price_pro' => YVO_TARIFF_PRICE_PRO,
        'price_business' => YVO_TARIFF_PRICE_BUSINESS,
        'credits_pro' => defined('YVO_TARIFF_CREDITS_PRO') ? (int) YVO_TARIFF_CREDITS_PRO : 10,
        'credits_business' => defined('YVO_TARIFF_CREDITS_BUSINESS') ? (int) YVO_TARIFF_CREDITS_BUSINESS : 30,
        'promo_active' => yvo_tariff_promo_active() ? 1 : 0,
        'promo_title' => yvo_tariff_promo_active() ? YVO_TARIFF_PROMO_TITLE : '',
        'promo_note' => yvo_tariff_promo_active() ? YVO_TARIFF_PROMO_NOTE : '',
        'price_standard_was' => YVO_TARIFF_PRICE_STANDARD_FULL,
        'price_complex_was' => YVO_TARIFF_PRICE_COMPLEX_FULL,
        'price_pro_was' => YVO_TARIFF_PRICE_PRO_FULL,
        'price_business_was' => YVO_TARIFF_PRICE_BUSINESS_FULL,
        'pricing_url' => function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/'),
        'wallet_url' => function_exists('yvo_cabinet_get_wallet_url') ? yvo_cabinet_get_wallet_url() : home_url('/'),
    );
}

/**
 * Применить тариф при выборе на странице / в админ-демо.
 *
 * @param int    $user_id
 * @param string $plan
 */
function yvo_tariff_apply_plan_selection($user_id, $plan) {
    $plan = yvo_tariff_normalize_plan($plan);
    if (!in_array($plan, array('free', 'onetime', 'pro', 'business'), true)) {
        $plan = 'free';
    }
    update_user_meta($user_id, YVO_META_TARIFF, $plan);
    if ($plan === 'onetime') {
        update_user_meta($user_id, YVO_META_GEN_CREDITS, 0);
        update_user_meta($user_id, YVO_META_SUB_UNTIL, '');
    } elseif ($plan === 'pro') {
        update_user_meta($user_id, YVO_META_GEN_CREDITS, YVO_TARIFF_CREDITS_PRO);
        update_user_meta($user_id, YVO_META_SUB_UNTIL, (string) (time() + 30 * DAY_IN_SECONDS));
    } elseif ($plan === 'business') {
        update_user_meta($user_id, YVO_META_GEN_CREDITS, YVO_TARIFF_CREDITS_BUSINESS);
        update_user_meta($user_id, YVO_META_SUB_UNTIL, (string) (time() + 30 * DAY_IN_SECONDS));
    } else {
        update_user_meta($user_id, YVO_META_GEN_CREDITS, 0);
        update_user_meta($user_id, YVO_META_SUB_UNTIL, '');
    }
}

add_action('init', 'yvo_tariff_admin_demo_save', 30);
function yvo_tariff_admin_demo_save() {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        return;
    }
    if (empty($_POST['yvo_wallet_demo']) || empty($_POST['yvo_wallet_demo_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yvo_wallet_demo_nonce'])), 'yvo_wallet_demo')) {
        return;
    }
    $uid = get_current_user_id();
    $plan = isset($_POST['yvo_demo_plan']) ? sanitize_key(wp_unslash($_POST['yvo_demo_plan'])) : 'free';
    yvo_tariff_apply_plan_selection($uid, $plan);
    if (isset($_POST['yvo_demo_balance'])) {
        $b = floatval(str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['yvo_demo_balance']))));
        update_user_meta($uid, 'yvo_wallet_balance', (string) round(max(0, $b), 2));
    }
    $ref = wp_get_referer();
    wp_safe_redirect($ref ? $ref : home_url('/'));
    exit;
}

add_action('init', 'yvo_tariff_handle_select_plan', 31);
function yvo_tariff_handle_select_plan() {
    if (empty($_POST['yvo_select_plan']) || empty($_POST['yvo_select_plan_nonce'])) {
        return;
    }
    if (!is_user_logged_in()) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['yvo_select_plan_nonce'])), 'yvo_select_plan')) {
        return;
    }
    $uid = get_current_user_id();
    $plan = isset($_POST['yvo_plan']) ? sanitize_key(wp_unslash($_POST['yvo_plan'])) : 'free';
    $plan = function_exists('yvo_tariff_normalize_plan') ? yvo_tariff_normalize_plan($plan) : $plan;

    // Платные тарифы — только через оплату (ЮKassa). POST «Выбрать Про» больше не выдаёт подписку бесплатно.
    if ($plan !== 'free') {
        $pricing = function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/');
        wp_safe_redirect(add_query_arg('yvo_pay_required', '1', $pricing));
        exit;
    }

    yvo_tariff_apply_plan_selection($uid, 'free');
    $ref = wp_get_referer();
    wp_safe_redirect($ref ? $ref : home_url('/'));
    exit;
}

/**
 * Реальный Pro/Business для фронта (шаблоны и т.п.): активная подписка.
 *
 * @param bool $is_pro
 * @return bool
 */
function yvo_tariff_filter_is_pro($is_pro) {
    if ($is_pro) {
        return true;
    }
    if (!is_user_logged_in()) {
        return false;
    }
    $uid = get_current_user_id();
    if (user_can($uid, 'manage_options')) {
        return true;
    }
    return function_exists('yvo_tariff_subscription_active') && yvo_tariff_subscription_active($uid);
}
add_filter('yvo_is_pro', 'yvo_tariff_filter_is_pro', 10);
