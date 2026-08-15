<?php
/**
 * Кнопки выбора типа объекта (синхронизация с #property_object_type через JS).
 */
if (!defined('ABSPATH')) {
    exit;
}
$sidebar_class = !empty($yvo_doki_object_type_sidebar) ? ' yvo-doki-object-type-row--sidebar' : '';
?>
<div class="yvo-doki-object-type-row<?php echo esc_attr($sidebar_class); ?>" role="group" aria-label="<?php esc_attr_e('Тип объекта недвижимости', 'yandex-vision-ocr-pro'); ?>">
    <button type="button" class="yvo-doki-object-type-pick active" data-object-type="apartment"><?php esc_html_e('Квартира', 'yandex-vision-ocr-pro'); ?></button>
    <button type="button" class="yvo-doki-object-type-pick" data-object-type="share"><?php esc_html_e('Доля', 'yandex-vision-ocr-pro'); ?></button>
    <button type="button" class="yvo-doki-object-type-pick" data-object-type="room"><?php esc_html_e('Комната', 'yandex-vision-ocr-pro'); ?></button>
    <button type="button" class="yvo-doki-object-type-pick" data-object-type="land"><?php esc_html_e('Участок', 'yandex-vision-ocr-pro'); ?></button>
    <button type="button" class="yvo-doki-object-type-pick" data-object-type="house_with_plot"><?php esc_html_e('Дом с участком', 'yandex-vision-ocr-pro'); ?></button>
    <button type="button" class="yvo-doki-object-type-pick" data-object-type="garage"><?php esc_html_e('Гараж', 'yandex-vision-ocr-pro'); ?></button>
    <button type="button" class="yvo-doki-object-type-pick" data-object-type="parking"><?php esc_html_e('Паркинг', 'yandex-vision-ocr-pro'); ?></button>
</div>
