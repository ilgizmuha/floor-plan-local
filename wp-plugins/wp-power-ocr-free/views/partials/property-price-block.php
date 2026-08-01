<?php
if (!defined('ABSPATH')) {
    exit;
}
$is_doki = !empty($use_doki_shell);
?>
<?php if ($is_doki) : ?>
<div class="yvo-doki-finance-pills" aria-label="<?php esc_attr_e('Способ оплаты и расчёты', 'yandex-vision-ocr-pro'); ?>">
    <div class="yvo-doki-finance-pill-row">
        <span class="yvo-doki-finance-pill-label"><?php esc_html_e('Способ покупки', 'yandex-vision-ocr-pro'); ?></span>
        <div class="yvo-doki-choice-row yvo-doki-choice-row--cols-2" role="radiogroup">
            <label class="yvo-doki-choice-pill active">
                <input type="radio" name="yvo_doki_pt" value="cash" checked>
                <?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-cash.svg', 'yvo-doki-btn-icon yvo-doki-choice-icon') : ''; ?>
                <span class="yvo-doki-choice-text"><?php esc_html_e('Наличные', 'yandex-vision-ocr-pro'); ?></span>
            </label>
            <label class="yvo-doki-choice-pill">
                <input type="radio" name="yvo_doki_pt" value="mortgage">
                <?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-mortgage.svg', 'yvo-doki-btn-icon yvo-doki-choice-icon') : ''; ?>
                <span class="yvo-doki-choice-text"><?php esc_html_e('Ипотека', 'yandex-vision-ocr-pro'); ?></span>
            </label>
        </div>
    </div>
    <div class="yvo-doki-finance-pill-row">
        <span class="yvo-doki-finance-pill-label"><?php esc_html_e('Передача денежных средств', 'yandex-vision-ocr-pro'); ?></span>
        <div class="yvo-doki-choice-row yvo-doki-choice-row--cols-3" role="radiogroup">
            <label class="yvo-doki-choice-pill">
                <input type="radio" name="yvo_doki_settlement" value="day_of_deal">
                <?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-calendar.svg', 'yvo-doki-btn-icon yvo-doki-choice-icon') : ''; ?>
                <span class="yvo-doki-choice-text"><?php esc_html_e('Расчёт в день сделки', 'yandex-vision-ocr-pro'); ?></span>
            </label>
            <label class="yvo-doki-choice-pill active">
                <input type="radio" name="yvo_doki_settlement" value="cell" checked>
                <?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-safe.svg', 'yvo-doki-btn-icon yvo-doki-choice-icon') : ''; ?>
                <span class="yvo-doki-choice-text"><?php esc_html_e('Использование ячейки', 'yandex-vision-ocr-pro'); ?></span>
            </label>
            <label class="yvo-doki-choice-pill">
                <input type="radio" name="yvo_doki_settlement" value="accreditive">
                <?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-landmark.svg', 'yvo-doki-btn-icon yvo-doki-choice-icon') : ''; ?>
                <span class="yvo-doki-choice-text"><?php esc_html_e('Аккредитив', 'yandex-vision-ocr-pro'); ?></span>
            </label>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="yvo-fp-price-section yvo-fp-collapse-block<?php echo $is_doki ? ' yvo-doki-deal-acc is-open' : ''; ?>">
    <button type="button" class="yvo-fp-collapse-header" aria-expanded="true" data-collapse="yvo-fp-price-body">
        <span class="yvo-fp-collapse-title"><?php esc_html_e('Цена и расчёты', 'yandex-vision-ocr-pro'); ?></span>
        <span class="yvo-fp-collapse-arrow">▼</span>
    </button>
    <div class="yvo-fp-collapse-body" id="yvo-fp-price-body">
        <div class="yvo-fp-grid yvo-fp-price-common">
            <div class="yvo-fp-field"><label><?php esc_html_e('Стоимость объекта (руб., цифрами) *', 'yandex-vision-ocr-pro'); ?></label><input type="number" name="property_price" id="property_price" data-key="price" step="0.01" min="0"></div>
            <div class="yvo-fp-field yvo-fp-field-full"><label><?php esc_html_e('Стоимость объекта прописью *', 'yandex-vision-ocr-pro'); ?></label><input type="text" name="property_price_words" id="property_price_words" data-key="price_words" placeholder="<?php esc_attr_e('авто по числу', 'yandex-vision-ocr-pro'); ?>"></div>
        </div>
        <div class="yvo-fp-grid">
            <div class="yvo-fp-field yvo-fp-field-full<?php echo $is_doki ? ' yvo-doki-native-select-row' : ''; ?>">
                <label><?php esc_html_e('Способ расчёта *', 'yandex-vision-ocr-pro'); ?></label>
                <select name="property_payment_type" id="property_payment_type" data-key="payment_type">
                    <option value="cash"><?php esc_html_e('Свои средства', 'yandex-vision-ocr-pro'); ?></option>
                    <option value="mortgage"><?php esc_html_e('Ипотека', 'yandex-vision-ocr-pro'); ?></option>
                </select>
            </div>
        </div>
        <div class="yvo-fp-price-cash">
            <div class="yvo-fp-grid">
                <div class="yvo-fp-field yvo-fp-field-full<?php echo $is_doki ? ' yvo-doki-native-select-row' : ''; ?>">
                    <label><?php esc_html_e('Способ передачи средств', 'yandex-vision-ocr-pro'); ?></label>
                    <select name="property_payment_method_cash" id="property_payment_method_cash" data-key="payment_method_cash">
                        <option value="cell"><?php esc_html_e('Ячейка', 'yandex-vision-ocr-pro'); ?></option>
                        <option value="accreditive"><?php esc_html_e('Аккредитив', 'yandex-vision-ocr-pro'); ?></option>
                        <option value="day_of_deal"><?php esc_html_e('Передача в день сделки', 'yandex-vision-ocr-pro'); ?></option>
                    </select>
                </div>
                <div class="yvo-fp-field yvo-fp-field-full"><label><?php esc_html_e('Банк аккредитива (свои средства)', 'yandex-vision-ocr-pro'); ?></label><input type="text" name="property_accreditiv_bank_name" data-key="accreditiv_bank_name" placeholder="<?php esc_attr_e('если отличается от полей ипотеки', 'yandex-vision-ocr-pro'); ?>"></div>
                <div class="yvo-fp-field"><label><?php esc_html_e('Сумма по аккредитиву (руб.; пусто = цена объекта)', 'yandex-vision-ocr-pro'); ?></label><input type="number" name="property_accreditiv_amount" data-key="accreditiv_amount" step="0.01" min="0" placeholder="<?php esc_attr_e('как в договоре', 'yandex-vision-ocr-pro'); ?>"></div>
                <div class="yvo-fp-field"><label><?php esc_html_e('Срок аккредитива (календарных дней)', 'yandex-vision-ocr-pro'); ?></label><input type="number" name="property_accreditiv_calendar_days" data-key="accreditiv_calendar_days" min="1" placeholder="60"><span class="yvo-fp-field-hint"><?php esc_html_e('Для шаблона «ДКП наличные + аккредитив»', 'yandex-vision-ocr-pro'); ?></span></div>
                <div class="yvo-fp-field"><label><?php esc_html_e('Срок передачи по акту (дней)', 'yandex-vision-ocr-pro'); ?></label><input type="number" name="property_acceptance_days_cash" data-key="acceptance_days" min="1" placeholder="<?php esc_attr_e('например: 14', 'yandex-vision-ocr-pro'); ?>"><span class="yvo-fp-field-hint"><?php esc_html_e('С полной оплаты до акта', 'yandex-vision-ocr-pro'); ?></span></div>
                <div class="yvo-fp-field yvo-fp-field-full"><label><?php esc_html_e('Реквизиты продавца (для расчётов)', 'yandex-vision-ocr-pro'); ?></label><textarea name="property_seller_details" data-key="seller_details" rows="2" placeholder="<?php esc_attr_e('банк, расчётный счёт, ИНН, КПП и т.д.', 'yandex-vision-ocr-pro'); ?>"></textarea></div>
                <div class="yvo-fp-field yvo-fp-field-full"><label><?php esc_html_e('Реквизиты покупателя (для расчётов)', 'yandex-vision-ocr-pro'); ?></label><textarea name="property_buyer_details" data-key="buyer_details" rows="2" placeholder="<?php esc_attr_e('банк, счёт заёмщика и т.д.', 'yandex-vision-ocr-pro'); ?>"></textarea></div>
            </div>
        </div>
        <div class="yvo-fp-price-preliminary" style="display:none;">
            <div class="yvo-fp-grid">
                <div class="yvo-fp-field"><label id="yvo-fp-label-deposit-amount"><?php esc_html_e('Сумма задатка (руб., цифрами)', 'yandex-vision-ocr-pro'); ?></label><input type="number" name="property_deposit_amount" id="property_deposit_amount" data-key="deposit_amount" step="0.01" min="0"></div>
                <div class="yvo-fp-field yvo-fp-field-full"><label id="yvo-fp-label-deposit-amount-words"><?php esc_html_e('Сумма задатка прописью', 'yandex-vision-ocr-pro'); ?></label><input type="text" name="property_deposit_amount_words" id="property_deposit_amount_words" data-key="deposit_amount_words" placeholder="<?php esc_attr_e('авто по числу', 'yandex-vision-ocr-pro'); ?>"></div>
            </div>
        </div>
        <div class="yvo-fp-price-mortgage" style="display:none;">
            <div class="yvo-fp-grid">
                <div class="yvo-fp-field yvo-fp-field-full"><label><?php esc_html_e('Наименование банка-кредитора', 'yandex-vision-ocr-pro'); ?></label><input type="text" name="property_bank_name" data-key="bank_name" placeholder="<?php esc_attr_e('например: ПАО Сбербанк', 'yandex-vision-ocr-pro'); ?>"></div>
                <div class="yvo-fp-field"><label><?php esc_html_e('Номер кредитного договора', 'yandex-vision-ocr-pro'); ?></label><input type="text" name="property_loan_agreement_number" data-key="loan_agreement_number"><span class="yvo-fp-field-hint"><?php esc_html_e('Номер договора с банком', 'yandex-vision-ocr-pro'); ?></span></div>
                <div class="yvo-fp-field"><label><?php esc_html_e('Дата кредитного договора', 'yandex-vision-ocr-pro'); ?></label><input type="text" name="property_loan_agreement_date" data-key="loan_agreement_date" placeholder="<?php esc_attr_e('дд.мм.гггг', 'yandex-vision-ocr-pro'); ?>"><span class="yvo-fp-field-hint"><?php esc_html_e('Дата подписания кредитного договора', 'yandex-vision-ocr-pro'); ?></span></div>
                <div class="yvo-fp-field"><label><?php esc_html_e('Сумма кредита (руб., цифрами)', 'yandex-vision-ocr-pro'); ?></label><input type="number" name="property_loan_amount" id="property_loan_amount" data-key="loan_amount" step="0.01" min="0"></div>
                <div class="yvo-fp-field yvo-fp-field-full"><label><?php esc_html_e('Сумма кредита прописью', 'yandex-vision-ocr-pro'); ?></label><input type="text" name="property_loan_amount_words" id="property_loan_amount_words" data-key="loan_amount_words"></div>
                <div class="yvo-fp-field"><label><?php esc_html_e('Сумма своих средств (руб.)', 'yandex-vision-ocr-pro'); ?></label><input type="number" name="property_loan_own_amount" id="property_loan_own_amount" data-key="loan_own_amount" step="0.01" min="0"></div>
                <div class="yvo-fp-field yvo-fp-field-full"><label><?php esc_html_e('Сумма своих средств прописью', 'yandex-vision-ocr-pro'); ?></label><input type="text" name="property_loan_own_amount_words" id="property_loan_own_amount_words" data-key="loan_own_amount_words"></div>
                <div class="yvo-fp-field"><label><?php esc_html_e('Уплачено до подписания (руб.)', 'yandex-vision-ocr-pro'); ?></label><input type="number" name="property_paid_before_signing" data-key="paid_before_signing" step="0.01" min="0"></div>
                <div class="yvo-fp-field"><label><?php esc_html_e('Уплачивается в день подписания (руб.)', 'yandex-vision-ocr-pro'); ?></label><input type="number" name="property_paid_at_signing" data-key="paid_at_signing" step="0.01" min="0"></div>
                <div class="yvo-fp-field"><label><?php esc_html_e('Срок передачи по акту (дней)', 'yandex-vision-ocr-pro'); ?></label><input type="number" name="property_acceptance_days" data-key="acceptance_days" min="1" placeholder="<?php esc_attr_e('например: 14', 'yandex-vision-ocr-pro'); ?>"><span class="yvo-fp-field-hint"><?php esc_html_e('Срок в днях с даты полной оплаты до подписания акта приёма-передачи', 'yandex-vision-ocr-pro'); ?></span></div>
                <div class="yvo-fp-field yvo-fp-field-full<?php echo $is_doki ? ' yvo-doki-native-select-row' : ''; ?>">
                    <label><?php esc_html_e('Способ передачи своих средств', 'yandex-vision-ocr-pro'); ?></label>
                    <select name="property_payment_method_mortgage" id="property_payment_method_mortgage" data-key="payment_method_mortgage">
                        <option value="cell"><?php esc_html_e('Ячейка', 'yandex-vision-ocr-pro'); ?></option>
                        <option value="accreditive"><?php esc_html_e('Аккредитив', 'yandex-vision-ocr-pro'); ?></option>
                        <option value="day_of_deal"><?php esc_html_e('Передача в день сделки', 'yandex-vision-ocr-pro'); ?></option>
                    </select>
                </div>
                <div class="yvo-fp-field yvo-fp-field-full"><label><?php esc_html_e('Реквизиты продавца (для расчётов)', 'yandex-vision-ocr-pro'); ?></label><textarea name="property_seller_details_mortgage" data-key="seller_details_mortgage" rows="2"></textarea></div>
            </div>
        </div>
    </div>
</div>
