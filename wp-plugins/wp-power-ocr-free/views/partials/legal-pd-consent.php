<?php
if (!defined('ABSPATH')) {
    exit;
}
$offer_url = function_exists('yvo_legal_get_url') ? yvo_legal_get_url('offer') : home_url('/');
$privacy_url = function_exists('yvo_legal_get_url') ? yvo_legal_get_url('privacy') : home_url('/');
$has_consent = function_exists('yvo_legal_user_has_pd_consent') && yvo_legal_user_has_pd_consent(get_current_user_id());
$enforced = function_exists('yvo_legal_pd_consent_enforced') && yvo_legal_pd_consent_enforced();
?>
<?php if ($enforced) : ?>
<div class="yvo-legal-pd-consent<?php echo $has_consent ? '' : ' is-blocked'; ?>" id="yvo-legal-pd-consent">
    <label>
        <input type="checkbox" id="yvo-legal-pd-consent-cb" value="1" <?php checked($has_consent); ?>>
        <span>Я даю <strong>согласие на обработку персональных данных</strong> (в т.ч. из загружаемых паспортов и документов) в целях формирования договора. Ознакомлен(а) с <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener">Политикой конфиденциальности</a> и <a href="<?php echo esc_url($offer_url); ?>" target="_blank" rel="noopener">Офертой</a>.</span>
    </label>
    <p class="yvo-legal-muted" style="margin:.5rem 0 0;font-size:.82rem;">Без согласия загрузка документов с персональными данными недоступна.</p>
</div>
<?php endif; ?>
