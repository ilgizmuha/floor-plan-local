<?php
if (!defined('ABSPATH')) {
    exit;
}
$reg_name_prefix = isset($reg_name_prefix) ? (string) $reg_name_prefix : 'property_';
?>
<div class="yvo-fp-field yvo-fp-field-full">
    <label>Вид, номер и дата гос. регистрации права</label>
    <input type="text" class="yvo-fp-reg-sync" name="<?php echo esc_attr($reg_name_prefix); ?>property_right_info" data-key="property_right_info" placeholder="Собственность, 02-04-01/287/2013-299, 07.08.2013">
</div>
<div class="yvo-fp-field yvo-fp-field-full">
    <label>Основание государственной регистрации</label>
    <input type="text" class="yvo-fp-reg-sync" name="<?php echo esc_attr($reg_name_prefix); ?>ownership_basis_documents" data-key="ownership_basis_documents" placeholder="Договор дарения, номер б/н, 23.07.2013">
</div>
<div class="yvo-fp-field">
    <label>Дата регистрации права</label>
    <input type="text" class="yvo-fp-reg-sync" name="<?php echo esc_attr($reg_name_prefix); ?>property_right_date" data-key="property_right_date">
</div>
