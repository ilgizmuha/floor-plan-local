<?php
if (!defined('ABSPATH')) {
    exit;
}
$path = YVO_PLUGIN_DIR . 'views/deal-cabinet-inner.html';
$inner = is_readable($path) ? file_get_contents($path) : '';
$inner = str_replace('CONTRACTS_URL_PLACEHOLDER', esc_url(isset($yvo_contracts_url) ? $yvo_contracts_url : home_url('/')), $inner);
$yvo_ajax = isset($yvo_deal_ajax_url) ? esc_url($yvo_deal_ajax_url) : esc_url(admin_url('admin-ajax.php'));
$yvo_nonce = isset($yvo_deal_nonce) ? esc_attr($yvo_deal_nonce) : esc_attr(wp_create_nonce('yvo_deal_cabinet'));
$yvo_contracts_esc = esc_url(isset($yvo_contracts_url) ? $yvo_contracts_url : home_url('/'));
$yvo_build = isset($yvo_deal_build) ? esc_attr($yvo_deal_build) : '';
?>
<div class="yvo-deal-cabinet-shell" data-yvo-ajax-url="<?php echo $yvo_ajax; ?>" data-yvo-nonce="<?php echo $yvo_nonce; ?>" data-yvo-contracts-url="<?php echo $yvo_contracts_esc; ?>" data-yvo-build="<?php echo $yvo_build; ?>">
    <?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static HTML from plugin file, URL replaced above ?>
</div>
