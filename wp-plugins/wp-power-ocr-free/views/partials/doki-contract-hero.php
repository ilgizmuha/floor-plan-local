<?php
/**
 * Шапка и hero «ДОКИ» для страницы с [yvo_contract_form] (без дублирования формы).
 */
if (!defined('ABSPATH')) {
    exit;
}
$embed = isset($yvo_doki_embed_url) ? (string) $yvo_doki_embed_url : '';
$img_base = isset($yvo_doki_img_base) ? (string) $yvo_doki_img_base : '';
$permalink = get_permalink();
$contracts_url = function_exists('yvo_cabinet_get_contracts_url') ? yvo_cabinet_get_contracts_url() : home_url('/');
$autofill_url = add_query_arg('yvo_autofill', '1', $contracts_url);
$hero_topbar_tab = isset($yvo_doki_hero_topbar_tab) ? (string) $yvo_doki_hero_topbar_tab : 'contracts';
$doki_embedded_header = !(function_exists('yvo_is_doki_home_page_context') && yvo_is_doki_home_page_context());
$is_doki_home = function_exists('yvo_is_doki_home_page_context') && yvo_is_doki_home_page_context();

if (!function_exists('yvo_doki_pill_icon_url')) {
    function yvo_doki_pill_icon_url($file) {
        $path = YVO_PLUGIN_DIR . 'images/' . $file;
        if (!is_file($path)) {
            return '';
        }
        $base = defined('YVO_PLUGIN_URL') ? YVO_PLUGIN_URL . 'images/' : '';
        return $base ? $base . $file : '';
    }
}

// На главной шапка фиксированная через wp_body_open (как на кабинете/профиле).
// На странице договоров — в контенте (doki-contract-hero).
$show_dokii_header = $doki_embedded_header
    && is_user_logged_in()
    && function_exists('yvo_cabinet_render_topbar')
    && !apply_filters('yvo_legacy_contract_form', false);
?>
<?php if ($show_dokii_header) : ?>
<div class="yvo-cab-contract-head">
    <?php echo yvo_cabinet_render_topbar($hero_topbar_tab); ?>
    <?php if (apply_filters('yvo_contract_page_show_cabinet_subbar', true)) : ?>
        <?php include __DIR__ . '/cabinet-topbar-sub.php'; ?>
    <?php endif; ?>
</div>
<?php elseif ($doki_embedded_header) : ?>
<?php
    // Для гостя на странице договоров — шапка в контенте.
    if (function_exists('yvo_cabinet_auth_css_version') && defined('YVO_PLUGIN_URL')) {
        if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
            yvo_cabinet_enqueue_topbar_assets();
        } else {
            wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), yvo_cabinet_auth_css_version());
        }
    }
    $home = function_exists('yvo_cabinet_get_home_url') ? yvo_cabinet_get_home_url() : home_url('/');
    $pricing = function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/');
    $login = function_exists('yvo_cabinet_get_login_url') ? yvo_cabinet_get_login_url() : wp_login_url($permalink);
    $reg = function_exists('yvo_cabinet_get_register_url') ? yvo_cabinet_get_register_url() : wp_registration_url();
    $property_check = function_exists('yvo_cabinet_get_property_check_url') ? yvo_cabinet_get_property_check_url() : '';
    $yvo_yandex_guest_url = function_exists('yvo_yandex_oauth_start_url') ? yvo_yandex_oauth_start_url($permalink) : '';
    include YVO_PLUGIN_DIR . 'views/cabinet-guest-bar.php';
?>
<?php endif; ?>

<section class="doki-hero" aria-label="<?php esc_attr_e('Возможности', 'yandex-vision-ocr-pro'); ?>">
    <div class="doki-layout">
        <div class="doki-circle">
            <div>
                <?php if ($is_doki_home) : ?>
                    <?php echo esc_html__('ДОГОВОР', 'yandex-vision-ocr-pro'); ?><br><?php echo esc_html__('ЗА 2 МИН', 'yandex-vision-ocr-pro'); ?>
                    <span><?php esc_html_e('без юриста', 'yandex-vision-ocr-pro'); ?></span>
                <?php else : ?>
                    <?php echo esc_html__('ДОГОВОРЫ', 'yandex-vision-ocr-pro'); ?><br><?php echo esc_html__('ОНЛАЙН', 'yandex-vision-ocr-pro'); ?>
                    <span><?php esc_html_e('АРР', 'yandex-vision-ocr-pro'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="doki-pill-stack">
            <a class="doki-pill blue card3d" href="<?php echo esc_url($contracts_url); ?>">
                <span class="doki-pill-icon"><?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-doc-lightning.svg', 'doki-pill-icon__img') : ''; ?></span>
                <span class="doki-pill-text">
                    <span class="doki-pill-title"><?php esc_html_e('Сгенерировать договор', 'yandex-vision-ocr-pro'); ?></span>
                    <span class="doki-pill-desc"><?php echo $is_doki_home ? esc_html__('за 2 минуты, без ручного ввода', 'yandex-vision-ocr-pro') : esc_html__('параметры сторон и генерация', 'yandex-vision-ocr-pro'); ?></span>
                </span>
                <span class="doki-pill-badge"><?php esc_html_e('СТАРТ', 'yandex-vision-ocr-pro'); ?></span>
            </a>
            <?php
            $pricing = function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/');
            $templates_url = function_exists('yvo_cabinet_get_templates_url') ? yvo_cabinet_get_templates_url() : $pricing;
            ?>
            <a class="doki-pill orange card3d" href="<?php echo esc_url($pricing); ?>">
                <span class="doki-pill-icon"><?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-pen.svg', 'doki-pill-icon__img') : ''; ?></span>
                <span class="doki-pill-text">
                    <span class="doki-pill-title"><?php esc_html_e('Тарифы', 'yandex-vision-ocr-pro'); ?></span>
                    <span class="doki-pill-desc"><?php echo $is_doki_home ? esc_html__('от 200 ₽ вместо юриста', 'yandex-vision-ocr-pro') : esc_html__('Подключение оплат', 'yandex-vision-ocr-pro'); ?></span>
                </span>
                <span class="doki-pill-badge"><?php esc_html_e('ЦЕНА', 'yandex-vision-ocr-pro'); ?></span>
            </a>
            <a class="doki-pill purple card3d" href="<?php echo esc_url($templates_url); ?>">
                <span class="doki-pill-icon"><?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-shield.svg', 'doki-pill-icon__img') : ''; ?></span>
                <span class="doki-pill-text">
                    <span class="doki-pill-title"><?php esc_html_e('Шаблоны договоров', 'yandex-vision-ocr-pro'); ?></span>
                    <span class="doki-pill-desc"><?php esc_html_e('Скачать бланки бесплатно', 'yandex-vision-ocr-pro'); ?></span>
                </span>
                <span class="doki-pill-badge"><?php esc_html_e('БЛАНКИ', 'yandex-vision-ocr-pro'); ?></span>
            </a>
            <a class="doki-pill dark card3d" href="<?php echo esc_url($autofill_url); ?>">
                <span class="doki-pill-icon"><?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-brain.svg', 'doki-pill-icon__img') : ''; ?></span>
                <span class="doki-pill-text">
                    <span class="doki-pill-title"><?php esc_html_e('Загрузить фото документов', 'yandex-vision-ocr-pro'); ?></span>
                    <span class="doki-pill-desc"><?php esc_html_e('Паспорт или ЕГРН — поля сами', 'yandex-vision-ocr-pro'); ?></span>
                </span>
                <span class="doki-pill-badge"><?php esc_html_e('ФОТО', 'yandex-vision-ocr-pro'); ?></span>
            </a>
        </div>
    </div>
</section>

<?php if ($embed !== '') : ?>
<section class="doki-demo-embed-section" aria-label="<?php esc_attr_e('Демонстрация шагов', 'yandex-vision-ocr-pro'); ?>">
    <div class="doki-gen2-embed">
        <iframe class="doki-gen2-embed__iframe" src="<?php echo esc_url($embed); ?>" title="<?php esc_attr_e('Демо: загрузка и генерация', 'yandex-vision-ocr-pro'); ?>" loading="lazy" scrolling="no"></iframe>
    </div>
</section>
<?php endif; ?>
