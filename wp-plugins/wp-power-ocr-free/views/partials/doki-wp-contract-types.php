<?php

/**

 * Выбор типа договора — синхронизируется с #yvo-fp-contract-type-menu через JS.

 */

if (!defined('ABSPATH')) {

    exit;

}



if (!function_exists('yvo_doki_type_btn')) {

    /**

     * @param string $icon

     * @param string $label

     * @return string

     */

    function yvo_doki_type_btn($icon, $label) {

        $icon_html = function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup($icon) : '';

        return $icon_html . '<span class="yvo-doki-btn-label">' . esc_html($label) . '</span>';

    }

}

?>

<style id="yvo-doki-contract-type-picker-critical">

@media (max-width: 1024px) {

    #yvo-doki-contract-type-picker[data-yvo-type-picker="flat-v2"] .contract-types-inline {

        display: flex !important;

        flex-direction: column !important;

        align-items: stretch !important;

        width: 100% !important;

        max-width: 100% !important;

        box-sizing: border-box !important;

    }

    #yvo-doki-contract-type-picker[data-yvo-type-picker="flat-v2"] .top-buttons {

        display: flex !important;

        flex-direction: column !important;

        flex-wrap: nowrap !important;

        align-items: stretch !important;

        justify-content: flex-start !important;

        gap: 12px !important;

        width: 100% !important;

        max-width: 100% !important;

        margin: 0 !important;

        padding: 0 !important;

        box-sizing: border-box !important;

    }

    #yvo-doki-contract-type-picker[data-yvo-type-picker="flat-v2"] .top-buttons > button.pbtn3d {

        width: 100% !important;

        max-width: none !important;

        min-width: 0 !important;

        flex: 0 0 auto !important;

        box-sizing: border-box !important;

        align-self: stretch !important;

    }

}

</style>

<div id="yvo-doki-contract-type-picker" class="contract-types-wrap yvo-wp-contract-types" data-yvo-type-picker="flat-v2" aria-label="<?php esc_attr_e('Тип договора', 'yandex-vision-ocr-pro'); ?>">

    <div class="contract-types-inline">

        <p class="deal-type-heading"><?php esc_html_e('Выберите тип сделки', 'yandex-vision-ocr-pro'); ?></p>

        <div class="top-buttons">

            <button type="button" class="pbtn3d type-option yvo-doki-type-pick active" data-contract-type="sale"><?php echo yvo_doki_type_btn('icon-doc-lightning.svg', __('Купля-продажа', 'yandex-vision-ocr-pro')); ?></button>

            <button type="button" class="pbtn3d type-option yvo-doki-type-pick" data-contract-type="deposit_agreement"><?php echo yvo_doki_type_btn('icon-shield.svg', __('Задаток', 'yandex-vision-ocr-pro')); ?></button>

            <button type="button" class="pbtn3d type-option yvo-doki-type-pick" data-contract-type="share_allocation"><?php echo yvo_doki_type_btn('icon-shares.svg', __('Выделение долей', 'yandex-vision-ocr-pro')); ?></button>

            <button type="button" class="pbtn3d type-more-btn" id="yvoDokiTypeMoreToggle" aria-expanded="false" aria-controls="yvoDokiTypeExtraGift">

                <?php

                echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-folder.svg') : '';

                ?>

                <span class="yvo-doki-btn-label"><?php esc_html_e('Ещё', 'yandex-vision-ocr-pro'); ?></span>

                <span class="yvo-doki-btn-caret" aria-hidden="true">▾</span>

            </button>

            <button type="button" class="pbtn3d type-option yvo-doki-type-pick yvo-doki-type-extra" id="yvoDokiTypeExtraGift" data-contract-type="gift" hidden><?php echo yvo_doki_type_btn('icon-gift.svg', __('Дарение', 'yandex-vision-ocr-pro')); ?></button>

            <button type="button" class="pbtn3d type-option yvo-doki-type-pick yvo-doki-type-extra" id="yvoDokiTypeExtraAdvance" data-contract-type="advance_agreement" hidden><?php echo yvo_doki_type_btn('icon-wallet.svg', __('Аванс', 'yandex-vision-ocr-pro')); ?></button>

        </div>

    </div>

</div>

