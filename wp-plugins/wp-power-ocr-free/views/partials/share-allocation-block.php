<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="yvo-fp-collapse-block yvo-fp-share-allocation-block" id="yvo-fp-share-allocation-block" hidden>
    <button type="button" class="yvo-fp-collapse-header" aria-expanded="true" data-collapse="yvo-fp-share-allocation-body">
        <span class="yvo-fp-collapse-title"><?php esc_html_e('Доли участников (выделение долей)', 'yandex-vision-ocr-pro'); ?></span>
        <span class="yvo-fp-collapse-arrow">▼</span>
    </button>
    <div class="yvo-fp-collapse-body" id="yvo-fp-share-allocation-body">

        <div class="yvo-fp-mat-calc-panel" id="yvo-fp-mat-calc-panel">
            <h4 class="yvo-fp-mat-calc-panel__title"><?php esc_html_e('Калькулятор долей по материнскому капиталу', 'yandex-vision-ocr-pro'); ?></h4>

            <div class="yvo-fp-mat-calc-row">
                <label class="yvo-fp-mat-calc-row__label" for="property_purchase_price_shares"><?php esc_html_e('Стоимость квартиры', 'yandex-vision-ocr-pro'); ?></label>
                <div class="yvo-fp-mat-calc-row__field">
                    <input type="text" inputmode="decimal" name="property_purchase_price_shares" id="property_purchase_price_shares" data-key="purchase_price_shares" class="yvo-fp-mat-calc-input" autocomplete="off" placeholder="6 850 000">
                    <span class="yvo-fp-mat-calc-suffix">₽</span>
                </div>
            </div>

            <div class="yvo-fp-mat-calc-row">
                <label class="yvo-fp-mat-calc-row__label" for="yvo-fp-mat-capital-preset"><?php esc_html_e('Номинал сертификата (2026)', 'yandex-vision-ocr-pro'); ?></label>
                <div class="yvo-fp-mat-calc-row__field">
                    <select id="yvo-fp-mat-capital-preset" class="yvo-fp-mat-calc-select" aria-label="<?php esc_attr_e('Выбор суммы материнского капитала', 'yandex-vision-ocr-pro'); ?>">
                        <option value="custom"><?php esc_html_e('Ввести сумму вручную', 'yandex-vision-ocr-pro'); ?></option>
                        <option value="728921.90"><?php esc_html_e('728 921,90 ₽ — первый ребёнок (с 01.02.2026)', 'yandex-vision-ocr-pro'); ?></option>
                        <option value="963243.17"><?php esc_html_e('963 243,17 ₽ — второй ребёнок, полная сумма', 'yandex-vision-ocr-pro'); ?></option>
                        <option value="234321.27"><?php esc_html_e('234 321,27 ₽ — доплата на второго (если был на первого)', 'yandex-vision-ocr-pro'); ?></option>
                    </select>
                </div>
            </div>

            <div class="yvo-fp-mat-calc-row">
                <label class="yvo-fp-mat-calc-row__label" for="property_maternity_capital_rub"><?php esc_html_e('Какая сумма мат. капитала использована при покупке', 'yandex-vision-ocr-pro'); ?></label>
                <div class="yvo-fp-mat-calc-row__field">
                    <input type="text" inputmode="decimal" name="property_maternity_capital_rub" id="property_maternity_capital_rub" data-key="maternity_capital_rub" class="yvo-fp-mat-calc-input" autocomplete="off" placeholder="453 000">
                    <span class="yvo-fp-mat-calc-suffix">₽</span>
                </div>
            </div>

            <div class="yvo-fp-mat-calc-row">
                <label class="yvo-fp-mat-calc-row__label" for="property_share_calc_area"><?php esc_html_e('Площадь квартиры', 'yandex-vision-ocr-pro'); ?></label>
                <div class="yvo-fp-mat-calc-row__field">
                    <input type="text" inputmode="decimal" name="property_share_calc_area" id="property_share_calc_area" data-key="share_calc_area" class="yvo-fp-mat-calc-input" autocomplete="off" placeholder="45">
                    <span class="yvo-fp-mat-calc-suffix">м²</span>
                </div>
            </div>

            <div class="yvo-fp-mat-calc-row">
                <label class="yvo-fp-mat-calc-row__label" for="property_share_family_members"><?php esc_html_e('Кол-во членов семьи, включая всех детей', 'yandex-vision-ocr-pro'); ?></label>
                <div class="yvo-fp-mat-calc-row__field">
                    <input type="number" name="property_share_family_members" id="property_share_family_members" data-key="share_family_members" class="yvo-fp-mat-calc-input yvo-fp-mat-calc-input--plain" min="2" step="1" placeholder="4">
                </div>
            </div>

            <div class="yvo-fp-mat-calc-row yvo-fp-mat-calc-row--checkbox" id="yvo-fp-share-joint-ownership-row" hidden>
                <div class="yvo-fp-mat-calc-row__field yvo-fp-mat-calc-row__field--full">
                    <label class="yvo-fp-mat-calc-checkbox">
                        <input type="checkbox" name="property_share_joint_ownership" id="property_share_joint_ownership" data-key="share_joint_ownership" value="1" checked>
                        <span><?php esc_html_e('Родители (супруги) владеют квартирой на праве совместной собственности — доля супругов считается одной', 'yandex-vision-ocr-pro'); ?></span>
                    </label>
                </div>
            </div>

            <div class="yvo-fp-mat-calc-toolbar">
                <button type="button" class="yvo-fp-btn yvo-fp-btn-primary yvo-fp-mat-calc-run-btn" id="yvo-fp-share-mat-calc-btn"><?php esc_html_e('Рассчитать', 'yandex-vision-ocr-pro'); ?></button>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-link yvo-fp-mat-calc-clear-btn" id="yvo-fp-mat-calc-clear"><?php esc_html_e('Очистить', 'yandex-vision-ocr-pro'); ?></button>
            </div>

            <div id="yvo-fp-mat-calc-results" class="yvo-fp-mat-calc-results" hidden aria-live="polite"></div>
        </div>

        <h4 class="yvo-fp-alloc-section-title"><?php esc_html_e('Участники и итоговые доли', 'yandex-vision-ocr-pro'); ?></h4>
        <p class="yvo-fp-section-hint yvo-fp-share-hint"><?php esc_html_e('После расчёта нажмите «Рассчитать» — доли запишутся в таблицу и карточки участников. При необходимости скорректируйте вручную.', 'yandex-vision-ocr-pro'); ?></p>
        <div id="yvo-fp-share-participants-summary" class="yvo-fp-share-participants-summary" aria-live="polite"></div>
        <div id="yvo-fp-alloc-preview-host" class="yvo-fp-alloc-preview-host" aria-live="polite"></div>
        <p id="yvo-fp-share-round-note" class="yvo-fp-share-round-note" role="status" hidden></p>

        <details class="yvo-fp-alloc-advanced" id="yvo-fp-alloc-advanced">
            <summary><?php esc_html_e('Ручной ввод или распределение поровну', 'yandex-vision-ocr-pro'); ?></summary>
            <div class="yvo-fp-share-matrix-tools">
                <input type="hidden" id="yvo-fp-share-mode" data-key="share_mode" name="property_share_mode" value="custom">
                <div class="yvo-fp-share-equal-actions yvo-fp-grid" id="yvo-fp-share-equal-actions">
                    <div class="yvo-fp-field">
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-share-equal-btn"><?php esc_html_e('Распределить поровну между участниками', 'yandex-vision-ocr-pro'); ?></button>
                    </div>
                </div>
            </div>
        </details>

        <div class="yvo-fp-share-matrix-quick yvo-fp-grid">
            <div class="yvo-fp-field">
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-share-equal-btn-gift" id="yvo-fp-share-equal-btn-gift"><?php esc_html_e('Распределить поровну', 'yandex-vision-ocr-pro'); ?></button>
            </div>
        </div>
        <p class="yvo-fp-parse-status" id="yvo-fp-share-validation" role="status" style="display:none;"></p>
        <input type="hidden" name="property_sellers_shares" id="property_sellers_shares" data-key="sellers_shares" value="">
        <input type="hidden" name="property_gift_distributions" id="property_gift_distributions" data-key="gift_distributions" value="">
    </div>
</div>
