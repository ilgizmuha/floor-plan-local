<?php
if (!defined('ABSPATH')) exit;
$max_size_mb = get_option('yvo_max_size', 20);
if (!isset($yvo_doki_embed_url) && defined('YVO_PLUGIN_URL')) {
    $yvo_doki_embed_url = YVO_PLUGIN_URL . 'animatsiya-generatsiya-2.html?embed=1&v=14';
}
if (!isset($yvo_doki_img_base) && defined('YVO_PLUGIN_URL')) {
    $yvo_doki_img_base = YVO_PLUGIN_URL . 'images/';
}
/*
 * Новый интерфейс формы (ДОКИ): по умолчанию включён.
 * Старая вёрстка: add_filter('yvo_legacy_contract_form', '__return_true');
 * Hero/широкая оболочка сверху отключаются отдельно: add_filter('yvo_frontend_use_doki_shell', '__return_false');
 */
$use_doki_shell = ! apply_filters('yvo_legacy_contract_form', false);
$yvo_fp_diag_mtime = (defined('YVO_PLUGIN_DIR') && is_file(YVO_PLUGIN_DIR . 'js/frontend.js')) ? (int) filemtime(YVO_PLUGIN_DIR . 'js/frontend.js') : 0;
$yvo_fp_diag_slug = (defined('YVO_PLUGIN_DIR') && is_string(YVO_PLUGIN_DIR)) ? basename(rtrim(YVO_PLUGIN_DIR, '/\\')) : '';
?>
<?php if ($use_doki_shell) : ?>
<div class="yvo-doki-contract-page depth-stage"><!-- YVO: интерфейс формы ДОКИ активен (отключить: фильтр yvo_legacy_contract_form) -->
    <?php if (apply_filters('yvo_frontend_use_doki_shell', true)) : ?>
    <?php include __DIR__ . '/partials/doki-contract-hero.php'; ?>
    <?php endif; ?>
<?php endif; ?>
    <div class="yvo-frontend-page<?php echo $use_doki_shell ? ' yvo-doki-form-skin' : ''; ?>"<?php
        echo $use_doki_shell ? ' data-yvo-doki-step="0"' : '';
        echo ' data-yvo-fp-js-mtime="' . esc_attr((string) $yvo_fp_diag_mtime) . '" data-yvo-fp-plugin="' . esc_attr($yvo_fp_diag_slug) . '"';
    ?>>
        <?php if ($use_doki_shell) : ?>
        <script>
        (function () {
            function yvoMosApply() {
                var on = window.matchMedia('(max-width: 768px)').matches;
                document.documentElement.classList.toggle('yvo-mos-active', on);
                document.body.classList.toggle('yvo-mos-active', on);
            }
            yvoMosApply();
            window.addEventListener('resize', yvoMosApply);
        })();
        </script>
        <?php endif; ?>
        <!-- input вне скрытой секции .yvo-fp-upload — иначе в ДОКИ programmatic .click() не открывает диалог -->
        <div id="yvo-fp-file-host" class="yvo-fp-file-host" aria-hidden="true">
            <input type="file" id="yvo-fp-file" name="file" accept=".jpg,.jpeg,.png,.gif,.bmp,.webp,.pdf" class="yvo-fp-file-input" tabindex="-1">
        </div>
        <?php if ($use_doki_shell) : ?>
        <div class="yvo-doki-mos-compact-bar" aria-label="<?php esc_attr_e('Раздел договоры', 'yandex-vision-ocr-pro'); ?>">
            <span class="yvo-doki-mos-compact-bar__title"><?php esc_html_e('Договоры', 'yandex-vision-ocr-pro'); ?></span>
            <button type="button" class="yvo-doki-mos-type-toggle" id="yvoDokiMosTypeToggle" aria-expanded="false" aria-controls="yvo-doki-contract-type-picker">
                <span id="yvoDokiMosTypeToggleLabel"><?php esc_html_e('Купля-продажа', 'yandex-vision-ocr-pro'); ?></span>
                <span class="yvo-doki-mos-type-toggle__caret" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="yvo-doki-mos-scroll" id="yvoDokiMosScroll">
        <?php endif; ?>
        <!-- Загрузка + согласие выше «Параметры сторон», чтобы после типа сделки сразу шли участники -->
        <section class="yvo-fp-section yvo-fp-upload<?php echo $use_doki_shell ? ' yvo-fp-upload--compact' : ''; ?>">
            <h2 class="yvo-fp-section-title"><?php echo $use_doki_shell ? esc_html__('Загрузите документ', 'yandex-vision-ocr-pro') : '1. Загрузите документ'; ?></h2>
            <?php if ($use_doki_shell) : ?>
            <p class="yvo-fp-upload-explain"><?php esc_html_e('Загрузите паспорт, выписку ЕГРН или другой документ — сервис распознает текст и подставит ФИО, паспорт, адрес и данные объекта в форму. Сначала отметьте согласие на обработку данных.', 'yandex-vision-ocr-pro'); ?></p>
            <?php endif; ?>
            <?php include __DIR__ . '/partials/legal-pd-consent.php'; ?>
            <div class="yvo-fp-upload-for-row">
                <label for="yvo-fp-upload-for" class="yvo-fp-label-inline"><?php echo $use_doki_shell ? esc_html__('Для кого:', 'yandex-vision-ocr-pro') : 'Загрузить документ для:'; ?></label>
                <select id="yvo-fp-upload-for" class="yvo-fp-select-upload-for">
                    <option value="">— выбрать после загрузки —</option>
                    <option value="seller">Продавец</option>
                    <option value="buyer">Покупатель</option>
                    <option value="property">Объект недвижимости</option>
                    <option value="seller_requisites">Реквизиты продавца</option>
                    <option value="buyer_requisites">Реквизиты покупателя</option>
                    <option value="seller_representative">Доверенное лицо продавца</option>
                    <option value="buyer_representative">Доверенное лицо покупателя</option>
                    <option value="contributor">Вноситель задатка (аванса)</option>
                    <option value="minor_seller|u14">Несовершеннолетний продавец (до 14 лет)</option>
                    <option value="minor_seller|a14_18">Несовершеннолетний продавец (от 14 лет)</option>
                    <option value="minor_buyer|u14">Несовершеннолетний покупатель (до 14 лет)</option>
                    <option value="minor_buyer|a14_18">Несовершеннолетний покупатель (от 14 лет)</option>
                </select>
            </div>
            <div class="yvo-fp-dropzone" id="yvo-fp-dropzone">
                <p class="yvo-fp-dropzone-text"><?php echo $use_doki_shell ? esc_html__('Файл сюда или нажмите', 'yandex-vision-ocr-pro') : 'Перетащите файл сюда или нажмите для выбора'; ?></p>
                <p class="yvo-fp-dropzone-hint">JPG, PNG, GIF, BMP, PDF. Не более <?php echo (int) $max_size_mb; ?> МБ</p>
            </div>
            <div class="yvo-fp-progress" id="yvo-fp-progress" style="display:none;">
                <div class="yvo-fp-spinner"></div>
                <p>Распознавание текста...</p>
            </div>
        </section>

        <div class="yvo-fp-header<?php echo $use_doki_shell ? ' section-caption' : ''; ?>">
            <div>
                <h1 class="yvo-fp-title"><?php echo $use_doki_shell ? esc_html__('Параметры сторон', 'yandex-vision-ocr-pro') : 'Договор купли-продажи'; ?></h1>
                <p class="yvo-fp-desc"><?php echo $use_doki_shell
                    ? esc_html__('Выберите тип сделки, затем заполните продавца, покупателя и объект. Документы и согласие — выше на странице.', 'yandex-vision-ocr-pro')
                    : 'Загрузите документы (паспорта, выписки). Текст распознается через Яндекс Vision, данные извлекаются через DeepSeek. Заполните формы и сгенерируйте договор.'; ?></p>
                <?php if (is_user_logged_in()) : ?>
                <p class="yvo-fp-build-line" style="margin-top:8px;padding:8px 10px;border-radius:8px;background:#f1f5f9;font-size:12px;line-height:1.45;color:#0f172a;border:1px solid #e2e8f0;">
                    <?php
                    echo esc_html(
                        sprintf(
                            'Сборка: %s · каталог плагина: %s · mtime frontend.js: %s',
                            defined('YVO_VERSION') ? YVO_VERSION : '?',
                            $yvo_fp_diag_slug !== '' ? $yvo_fp_diag_slug : '?',
                            $yvo_fp_diag_mtime > 0 ? (string) $yvo_fp_diag_mtime : '?'
                        )
                    );
                    ?>
                </p>
                <?php endif; ?>
            </div>
            <?php if ($use_doki_shell) : ?>
            <div class="type-badge" id="yvoDokiFormTypeBadge"><?php esc_html_e('Купля-продажа', 'yandex-vision-ocr-pro'); ?></div>
            <?php endif; ?>
        </div>

        <?php if ($use_doki_shell) : ?>
        <?php include __DIR__ . '/partials/doki-wp-contract-types.php'; ?>
        <div class="main-panel doki-wp-main-panel">
            <div class="form-column card3d">
        <?php endif; ?>

    <!-- Результат OCR и парсинг (текст показывается после загрузки файла) -->
    <section class="yvo-fp-section yvo-fp-result" id="yvo-fp-result-section" style="display:none;">
        <h2 class="yvo-fp-section-title">2. Распознанный текст</h2>
        <textarea id="yvo-fp-text" class="yvo-fp-textarea" rows="8" readonly></textarea>
        <div class="yvo-fp-upload-target" id="yvo-fp-upload-target">
            <span class="yvo-fp-upload-target-label">Действия с текстом:</span>
            <div class="yvo-fp-dropdown" id="yvo-fp-upload-target-extra-dropdown">
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-toggle" id="yvo-fp-upload-target-extra-toggle" aria-haspopup="true" aria-expanded="false">Доп. опции <span class="yvo-fp-dropdown-arrow">▼</span></button>
                <div class="yvo-fp-dropdown-menu yvo-fp-upload-target-extra-menu-wide" id="yvo-fp-upload-target-extra-menu" role="menu">
                    <button type="button" class="yvo-fp-dropdown-item" id="yvo-fp-fix-egrn-btn" role="menuitem">Исправить кодировку (ИИ)</button>
                    <button type="button" class="yvo-fp-dropdown-item" id="yvo-fp-extract-egrn-btn" role="menuitem">Извлечь данные выписки ЕГРН</button>
                    <button type="button" class="yvo-fp-dropdown-item" id="yvo-fp-detect-participants-btn" role="menuitem">Определить участников</button>
                    <div class="yvo-fp-dropdown-divider" role="separator" aria-hidden="true"></div>
                    <div class="yvo-fp-dropdown-menu-subtitle">Подставить в форму</div>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-upload-target-btn" id="yvo-fp-fill-object" data-fill-type="property" role="menuitem">Объект</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-upload-target-btn" id="yvo-fp-fill-seller" data-fill-type="seller" role="menuitem">Продавец</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-upload-target-btn" id="yvo-fp-fill-buyer" data-fill-type="buyer" role="menuitem">Покупатель</button>
                </div>
            </div>
        </div>
        <div class="yvo-fp-upload-target-hint" id="yvo-fp-upload-target-hint">
            После загрузки ниже появятся извлечённые ФИО и объект. Нажмите кнопку рядом с нужной строкой, чтобы подставить данные в форму. Объект подставляется автоматически. Дополнительно: <strong>«Доп. опции»</strong> — исправление кодировки, ЕГРН, ручной выбор вкладки.
        </div>
        <div class="yvo-fp-extracted-summary" id="yvo-fp-extracted-summary" style="display:none;" aria-live="polite">
            <h3 class="yvo-fp-extracted-title">Извлечённые данные</h3>
            <p class="yvo-fp-extracted-hint">Добавьте участников в форме. Для каждого человека нажмите кнопку нужной вкладки (Участник 1, Участник 2, Покупатель …) — данные сразу подставятся. «Копировать» — для вставки в другую вкладку.</p>
            <div class="yvo-fp-extracted-list" id="yvo-fp-extracted-list"></div>
        </div>
        <div class="yvo-fp-field-review" id="yvo-fp-field-review" hidden style="display:none;" aria-live="polite">
            <div class="yvo-fp-field-review__head">
                <h3 class="yvo-fp-field-review-title" id="yvo-fp-field-review-title">Проверка полей</h3>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-field-review-ai" id="yvo-fp-field-review-ai">Перепроверить ИИ</button>
            </div>
            <p class="yvo-fp-field-review-hint">После подстановки из документа поля сверяются с правилами и текстом OCR. Нажмите замечание — откроется нужное поле.</p>
            <div class="yvo-fp-field-review-list" id="yvo-fp-field-review-list"></div>
            <p class="yvo-fp-field-review-ai-status" id="yvo-fp-field-review-ai-status" style="display:none;"></p>
        </div>
        <div class="yvo-fp-parse-status" id="yvo-fp-parse-status" style="display:none;"></div>
    </section>

    <!-- Модальное окно: назначение ролей найденным участникам -->
    <div class="yvo-fp-modal" id="yvo-fp-participants-modal" role="dialog" aria-modal="true" style="display:none;">
        <div class="yvo-fp-modal-backdrop"></div>
        <div class="yvo-fp-modal-content" style="max-width:520px;">
            <div class="yvo-fp-modal-header">
                <h2 class="yvo-fp-modal-title">Назначьте роли участникам</h2>
                <button type="button" class="yvo-fp-modal-close" aria-label="Закрыть">&times;</button>
            </div>
            <div class="yvo-fp-modal-body">
                <p class="yvo-fp-participants-hint">В документе найдены данные участников. Выберите для каждого роль — данные будут подставлены в форму.</p>
                <div id="yvo-fp-participants-list" class="yvo-fp-participants-list"></div>
                <div class="yvo-fp-modal-actions">
                    <button type="button" class="yvo-fp-btn yvo-fp-btn-primary" id="yvo-fp-participants-apply">Применить</button>
                    <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-participants-cancel">Отмена</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Панель кнопок: после блока «Распознанный текст» -->
    <div class="yvo-fp-quick-actions" id="yvo-fp-quick-actions">
        <div class="yvo-fp-btn-group">
            <div class="yvo-fp-dropdown" id="yvo-fp-autofill-dropdown">
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-toggle" id="yvo-fp-autofill-btn" aria-haspopup="true" aria-expanded="false">Автозаполнить <span class="yvo-fp-dropdown-arrow">▼</span></button>
                <div class="yvo-fp-dropdown-menu" id="yvo-fp-autofill-menu" role="menu"></div>
            </div>
            <div class="yvo-fp-dropdown" id="yvo-fp-add-participant-dropdown">
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-toggle" id="yvo-fp-add-participant-btn" aria-haspopup="true" aria-expanded="false">Добавить участника <span class="yvo-fp-dropdown-arrow">▼</span></button>
                <div class="yvo-fp-dropdown-menu yvo-fp-add-participant-menu" id="yvo-fp-add-participant-menu" role="menu">
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-seller" data-add="seller" role="menuitem">Продавец 2</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-seller-rep" data-add="seller_representative" role="menuitem">Доверенное лицо продавца</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-minor-seller-u14" data-add="minor_seller" data-minor-age="u14" role="menuitem">Несовершеннолетний продавец (до 14 лет)</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-minor-seller-a18" data-add="minor_seller" data-minor-age="a14_18" role="menuitem">Несовершеннолетний продавец (от 14 лет)</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-guardian-seller" data-add="guardian_seller" role="menuitem">Опекун (даритель)</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-buyer" data-add="buyer" role="menuitem">Покупатель 2</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-buyer-rep" data-add="buyer_representative" role="menuitem">Доверенное лицо покупателя</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-contributor" data-add="contributor" role="menuitem">Вноситель задатка (аванса)</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-minor-buyer-u14" data-add="minor_buyer" data-minor-age="u14" role="menuitem">Несовершеннолетний покупатель (до 14 лет)</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-minor-buyer-a18" data-add="minor_buyer" data-minor-age="a14_18" role="menuitem">Несовершеннолетний покупатель (от 14 лет)</button>
                    <button type="button" class="yvo-fp-dropdown-item yvo-fp-add-guardian-buyer" data-add="guardian_buyer" role="menuitem">Опекун (одаряемый)</button>
                </div>
            </div>
            <div class="yvo-fp-dropdown" id="yvo-fp-object-type-dropdown">
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-toggle" id="yvo-fp-object-type-btn" aria-haspopup="true" aria-expanded="false">Объект <span class="yvo-fp-dropdown-arrow">▼</span></button>
                <div class="yvo-fp-dropdown-menu" id="yvo-fp-object-type-menu" role="menu">
                    <button type="button" class="yvo-fp-dropdown-item" data-object-type="apartment" role="menuitem">Квартира</button>
                    <button type="button" class="yvo-fp-dropdown-item" data-object-type="share" role="menuitem">Доля (в квартире)</button>
                    <button type="button" class="yvo-fp-dropdown-item" data-object-type="room" role="menuitem">Комната</button>
                    <button type="button" class="yvo-fp-dropdown-item" data-object-type="land" role="menuitem">Участок</button>
                    <button type="button" class="yvo-fp-dropdown-item" data-object-type="house_with_plot" role="menuitem">Дом с участком</button>
                    <button type="button" class="yvo-fp-dropdown-item" data-object-type="garage" role="menuitem">Гараж</button>
                    <button type="button" class="yvo-fp-dropdown-item" data-object-type="parking" role="menuitem">Паркинг</button>
                </div>
            </div>
            <div class="yvo-fp-dropdown" id="yvo-fp-contract-type-dropdown">
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-toggle" id="yvo-fp-contract-type-btn" aria-haspopup="true" aria-expanded="false">Тип договора <span class="yvo-fp-dropdown-arrow">▼</span></button>
                <div class="yvo-fp-dropdown-menu" id="yvo-fp-contract-type-menu" role="menu">
                    <button type="button" class="yvo-fp-dropdown-item" data-contract-type="sale" role="menuitem">1. Договоры купли-продажи</button>
                    <button type="button" class="yvo-fp-dropdown-item" data-contract-type="gift" role="menuitem">2. Договоры дарения</button>
                    <button type="button" class="yvo-fp-dropdown-item" data-contract-type="share_allocation" role="menuitem">3. Соглашение выделения долей</button>
                    <button type="button" class="yvo-fp-dropdown-item" data-contract-type="deposit_agreement" role="menuitem">Задаток</button>
                    <button type="button" class="yvo-fp-dropdown-item" data-contract-type="advance_agreement" role="menuitem">Аванс</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Формы данных (всегда видны) -->
    <section class="yvo-fp-section yvo-fp-forms yvo-fp-forms-visible" id="yvo-fp-forms-section">
        <?php if ($use_doki_shell) : ?>
        <p class="yvo-fp-section-hint yvo-doki-forms-intro"><?php esc_html_e('По умолчанию: один продавец и один покупатель. В договор попадут только те, у кого заполнено ФИО.', 'yandex-vision-ocr-pro'); ?></p>
        <div class="yvo-doki-steps-toolbar">
            <div class="steps-indicator yvo-doki-steps" role="tablist" aria-label="<?php esc_attr_e('Шаги заполнения', 'yandex-vision-ocr-pro'); ?>">
                <button type="button" class="step-pill active" data-yvo-step-index="0" data-tab="seller" aria-selected="true"><?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-person.svg', 'yvo-doki-btn-icon step-pill-icon') : ''; ?><span class="yvo-doki-step-label"><?php esc_html_e('продавец', 'yandex-vision-ocr-pro'); ?></span></button>
                <button type="button" class="step-pill" data-yvo-step-index="1" data-tab="buyer" aria-selected="false"><?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-person.svg', 'yvo-doki-btn-icon step-pill-icon') : ''; ?><span class="yvo-doki-step-label"><?php esc_html_e('покупатель', 'yandex-vision-ocr-pro'); ?></span></button>
                <button type="button" class="step-pill" data-yvo-step-index="2" data-tab="property" aria-selected="false"><?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-house.svg', 'yvo-doki-btn-icon step-pill-icon') : ''; ?><span class="yvo-doki-step-label"><?php esc_html_e('объект', 'yandex-vision-ocr-pro'); ?></span></button>
                <button type="button" class="step-pill" data-yvo-step-index="3" data-tab="generate" aria-selected="false"><?php echo function_exists('yvo_doki_ui_icon_markup') ? yvo_doki_ui_icon_markup('icon-gear.svg', 'yvo-doki-btn-icon step-pill-icon') : ''; ?><span class="yvo-doki-step-label"><?php esc_html_e('генерация', 'yandex-vision-ocr-pro'); ?></span></button>
            </div>
        </div>
        <p class="yvo-doki-multi-participant-hint" id="yvoDokiMultiParticipantHint" hidden><?php esc_html_e('Несколько участников: переключайтесь вкладками «Продавец», «Продавец 2»… сразу под шагами (не путать с кнопками шагов сверху).', 'yandex-vision-ocr-pro'); ?></p>
        <?php endif; ?>
        <?php if (!$use_doki_shell) : ?>
        <h2 class="yvo-fp-section-title">3. Данные для договора</h2>
        <p class="yvo-fp-section-hint">По умолчанию: один продавец и один покупатель. Добавьте участников при необходимости — в договор попадут только те, у кого заполнено ФИО.</p>
        <?php endif; ?>

        <div class="yvo-fp-tabs-wrap">
            <div id="yvo-fp-participant-tabs" class="yvo-fp-tabs<?php echo $use_doki_shell ? ' yvo-doki-tabs-hidden' : ''; ?>">
                <button type="button" class="yvo-fp-tab active" data-tab="seller">Продавец</button>
                <button type="button" class="yvo-fp-tab" data-tab="buyer">Покупатель</button>
                <button type="button" class="yvo-fp-tab" data-tab="property">Объект</button>
            </div>
            <?php if ($use_doki_shell) : ?>
            <div class="yvo-doki-participant-card">
                <div class="yvo-doki-participant-card__head">
                    <div class="yvo-doki-participant-card__title-block">
                        <span class="yvo-doki-participant-card__title" id="yvoDokiParticipantCardTitle"><?php esc_html_e('Продавец (участник 1)', 'yandex-vision-ocr-pro'); ?></span>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm yvo-doki-add-participant-btn" id="yvo-fp-tab-add" title="<?php esc_attr_e('Открыть список: Продавец 2, Покупатель 2, вноситель задатка и др. Shift+клик — сразу добавить участника текущего шага без меню.', 'yandex-vision-ocr-pro'); ?>"><span class="yvo-doki-add-participant-btn__plus" aria-hidden="true">+</span><span class="yvo-doki-add-participant-btn__text"><?php esc_html_e('Добавить участника', 'yandex-vision-ocr-pro'); ?></span></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action-sm yvo-fp-apply-same-guardian-btn" id="yvo-fp-apply-same-guardian-btn" hidden title="<?php esc_attr_e('Подставить ФИО и паспорт опекуна с первой вкладки (один опекун на нескольких детей)', 'yandex-vision-ocr-pro'); ?>"><?php esc_html_e('Применить параметры опекуна', 'yandex-vision-ocr-pro'); ?></button>
                        <span class="yvo-doki-participant-card__hint" id="yvoDokiParticipantCardHint" hidden></span>
                    </div>
                    <div class="yvo-doki-participant-card__toolbar" role="toolbar" aria-label="<?php esc_attr_e('Вставка из ЛК, копирование и удаление данных вкладки', 'yandex-vision-ocr-pro'); ?>">
                        <div class="yvo-fp-dropdown yvo-fp-cabinet-inline-dropdown">
                            <button type="button" class="yvo-doki-participant-icon-btn yvo-fp-cabinet-inline-toggle" id="yvo-fp-cabinet-inline-toggle" data-yvo-tip="<?php esc_attr_e('Вставить из ЛК', 'yandex-vision-ocr-pro'); ?>" title="<?php esc_attr_e('Подставить сохранённые данные из личного кабинета', 'yandex-vision-ocr-pro'); ?>" aria-expanded="false" aria-haspopup="true" aria-label="<?php esc_attr_e('Вставить из ЛК', 'yandex-vision-ocr-pro'); ?>"><span class="yvo-doki-participant-icon-btn__ic yvo-doki-participant-icon-btn__ic--cabinet" aria-hidden="true"></span><span class="yvo-doki-participant-icon-btn__label"><span class="yvo-doki-participant-icon-btn__label-long"><?php esc_html_e('Вставить из ЛК', 'yandex-vision-ocr-pro'); ?></span><span class="yvo-doki-participant-icon-btn__label-short"><?php esc_html_e('Из ЛК', 'yandex-vision-ocr-pro'); ?></span></span></button>
                            <div class="yvo-fp-cabinet-inline-menu" id="yvo-fp-cabinet-inline-menu" role="menu" hidden></div>
                        </div>
                        <button type="button" class="yvo-doki-participant-icon-btn" data-yvo-doki-mirror="#yvo-fp-tab-copy" data-yvo-tip="<?php esc_attr_e('Копировать', 'yandex-vision-ocr-pro'); ?>" title="<?php esc_attr_e('Копировать данные текущей вкладки', 'yandex-vision-ocr-pro'); ?>" aria-label="<?php esc_attr_e('Копировать', 'yandex-vision-ocr-pro'); ?>"><span class="yvo-doki-participant-icon-btn__ic yvo-doki-participant-icon-btn__ic--copy" aria-hidden="true"></span><span class="yvo-doki-participant-icon-btn__label"><span class="yvo-doki-participant-icon-btn__label-long"><?php esc_html_e('Копировать', 'yandex-vision-ocr-pro'); ?></span><span class="yvo-doki-participant-icon-btn__label-short"><?php esc_html_e('Копия', 'yandex-vision-ocr-pro'); ?></span></span></button>
                        <button type="button" class="yvo-doki-participant-icon-btn" data-yvo-doki-mirror="#yvo-fp-tab-paste" data-yvo-tip="<?php esc_attr_e('Вставить', 'yandex-vision-ocr-pro'); ?>" title="<?php esc_attr_e('Вставить в текущую вкладку', 'yandex-vision-ocr-pro'); ?>" aria-label="<?php esc_attr_e('Вставить', 'yandex-vision-ocr-pro'); ?>"><span class="yvo-doki-participant-icon-btn__ic yvo-doki-participant-icon-btn__ic--paste" aria-hidden="true"></span><span class="yvo-doki-participant-icon-btn__label"><span class="yvo-doki-participant-icon-btn__label-long"><?php esc_html_e('Вставить', 'yandex-vision-ocr-pro'); ?></span><span class="yvo-doki-participant-icon-btn__label-short"><?php esc_html_e('Вст.', 'yandex-vision-ocr-pro'); ?></span></span></button>
                        <button type="button" class="yvo-doki-participant-icon-btn" data-yvo-doki-mirror="#yvo-fp-tab-delete" data-yvo-tip="<?php esc_attr_e('Удалить', 'yandex-vision-ocr-pro'); ?>" title="<?php esc_attr_e('Удалить вкладку или очистить поля', 'yandex-vision-ocr-pro'); ?>" aria-label="<?php esc_attr_e('Удалить', 'yandex-vision-ocr-pro'); ?>"><span class="yvo-doki-participant-icon-btn__ic yvo-doki-participant-icon-btn__ic--delete" aria-hidden="true"></span><span class="yvo-doki-participant-icon-btn__label"><span class="yvo-doki-participant-icon-btn__label-long"><?php esc_html_e('Удалить', 'yandex-vision-ocr-pro'); ?></span><span class="yvo-doki-participant-icon-btn__label-short"><?php esc_html_e('Удал.', 'yandex-vision-ocr-pro'); ?></span></span></button>
                    </div>
                </div>
                <div class="yvo-doki-add-participant-panel" id="yvoDokiAddParticipantPanel" hidden>
                    <div class="yvo-doki-add-participant-panel__label"><?php esc_html_e('Кого добавить?', 'yandex-vision-ocr-pro'); ?></div>
                    <div class="yvo-fp-add-participant-menu yvo-doki-add-participant-panel__menu" role="menu">
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-seller" data-add="seller"><?php esc_html_e('Продавец 2', 'yandex-vision-ocr-pro'); ?></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-seller-rep" data-add="seller_representative"><?php esc_html_e('Доверенное лицо продавца', 'yandex-vision-ocr-pro'); ?></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-minor-seller-u14" data-add="minor_seller" data-minor-age="u14"><?php esc_html_e('Несовершеннолетний продавец (до 14 лет)', 'yandex-vision-ocr-pro'); ?></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-minor-seller-a18" data-add="minor_seller" data-minor-age="a14_18"><?php esc_html_e('Несовершеннолетний продавец (от 14 лет)', 'yandex-vision-ocr-pro'); ?></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-guardian-seller" data-add="guardian_seller"><?php esc_html_e('Опекун (даритель)', 'yandex-vision-ocr-pro'); ?></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-buyer" data-add="buyer"><?php esc_html_e('Покупатель 2', 'yandex-vision-ocr-pro'); ?></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-buyer-rep" data-add="buyer_representative"><?php esc_html_e('Доверенное лицо покупателя', 'yandex-vision-ocr-pro'); ?></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-contributor" data-add="contributor"><?php esc_html_e('Вноситель задатка (аванса)', 'yandex-vision-ocr-pro'); ?></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-minor-buyer-u14" data-add="minor_buyer" data-minor-age="u14"><?php esc_html_e('Несовершеннолетний покупатель (до 14 лет)', 'yandex-vision-ocr-pro'); ?></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-minor-buyer-a18" data-add="minor_buyer" data-minor-age="a14_18"><?php esc_html_e('Несовершеннолетний покупатель (от 14 лет)', 'yandex-vision-ocr-pro'); ?></button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-dropdown-item yvo-fp-add-guardian-buyer" data-add="guardian_buyer"><?php esc_html_e('Опекун (одаряемый)', 'yandex-vision-ocr-pro'); ?></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!$use_doki_shell) : ?>
            <div class="yvo-fp-tab-actions">
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-delete" title="Удалить текущую вкладку (только добавленные)">Удалить вкладку</button>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-add" title="Добавить участника">Добавить участника</button>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-clear" title="Очистить поля текущей вкладки">Очистить</button>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-copy" title="Скопировать данные текущей вкладки">Копировать данные</button>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-paste" title="Вставить скопированные данные">Вставить</button>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-primary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-save" title="Сохранить данные в личный кабинет">Сохранить</button>
                <div class="yvo-fp-dropdown yvo-fp-cabinet-inline-dropdown yvo-fp-cabinet-inline-dropdown--legacy">
                    <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm yvo-fp-cabinet-inline-toggle" id="yvo-fp-cabinet-inline-toggle-legacy" title="Подставить из личного кабинета">Вставить из кабинета ▾</button>
                    <div class="yvo-fp-cabinet-inline-menu" id="yvo-fp-cabinet-inline-menu-legacy" role="menu" hidden></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($use_doki_shell) : ?>
        <div class="yvo-doki-autofill-wrap">
            <div class="yvo-doki-autofill yvo-doki-autofill--seller" data-yvo-autofill-tab="seller">
                <div class="autofill-hint">
                    <div class="autofill-hint-text"><?php esc_html_e('Загрузите паспорт или документ — сервис подставит ФИО, паспорт и адрес. Поля ниже можно править.', 'yandex-vision-ocr-pro'); ?></div>
                    <div class="autofill-actions">
                        <button type="button" class="yvo-doki-autofill-btn yvo-doki-autofill-upload" id="yvo-doki-seller-upload" data-yvo-autofill-role="seller"><?php esc_html_e('Загрузить паспорт / документ продавца', 'yandex-vision-ocr-pro'); ?></button>
                    </div>
                </div>
            </div>
            <div class="yvo-doki-autofill yvo-doki-autofill--buyer" data-yvo-autofill-tab="buyer" hidden>
                <div class="autofill-hint">
                    <div class="autofill-hint-text"><?php esc_html_e('Загрузите паспорт или документ — сервис подставит ФИО, паспорт и адрес.', 'yandex-vision-ocr-pro'); ?></div>
                    <div class="autofill-actions">
                        <button type="button" class="yvo-doki-autofill-btn yvo-doki-autofill-upload" id="yvo-doki-buyer-upload" data-yvo-autofill-role="buyer"><?php esc_html_e('Загрузить паспорт / документ покупателя', 'yandex-vision-ocr-pro'); ?></button>
                    </div>
                </div>
            </div>
            <div class="yvo-doki-autofill yvo-doki-autofill--property" data-yvo-autofill-tab="property" hidden>
                <div class="yvo-doki-object-type-block">
                    <p class="yvo-doki-picker-heading"><?php esc_html_e('Тип объекта', 'yandex-vision-ocr-pro'); ?></p>
                    <?php include __DIR__ . '/partials/doki-object-type-picks.php'; ?>
                </div>
                <div class="autofill-hint">
                    <div class="autofill-hint-text"><?php esc_html_e('Загрузите выписку ЕГРН или другие документы по объекту — сервис подставит адрес и кадастр.', 'yandex-vision-ocr-pro'); ?></div>
                    <div class="autofill-actions">
                        <button type="button" class="yvo-doki-autofill-btn yvo-doki-autofill-upload" id="yvo-doki-property-upload" data-yvo-autofill-role="property"><?php esc_html_e('Загрузить документы по объекту', 'yandex-vision-ocr-pro'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="yvo-fp-tab-panels" id="yvo-fp-tab-panels">
            <div class="yvo-fp-panel active" id="yvo-fp-panel-seller">
                <div class="yvo-fp-grid">
                    <div class="yvo-fp-field"><label>ФИО *</label><input type="text" name="seller_full_name" data-key="full_name" required></div>
                    <div class="yvo-fp-field"><label>Код подразделения</label><input type="text" name="seller_department_code" data-key="department_code" placeholder="000-000"></div>
                    <div class="yvo-fp-field"><label>Серия паспорта *</label><input type="text" name="seller_passport_series" data-key="passport_series" maxlength="4" pattern="\d{4}"></div>
                    <div class="yvo-fp-field"><label>Номер паспорта *</label><input type="text" name="seller_passport_number" data-key="passport_number" maxlength="6" pattern="\d{6}"></div>
                    <div class="yvo-fp-field yvo-fp-field-issued-by">
                        <label>Кем выдан *</label>
                        <textarea name="seller_passport_issued_by" data-key="passport_issued_by" rows="2"></textarea>
                    </div>
                    <div class="yvo-fp-field"><label>Дата выдачи</label><input type="text" name="seller_passport_date" data-key="passport_date" placeholder="дд.мм.гггг"></div>
                    <div class="yvo-fp-field"><label>Дата рождения</label><input type="text" name="seller_birth_date" data-key="birth_date" placeholder="дд.мм.гггг"></div>
                    <div class="yvo-fp-field"><label>Место рождения</label><input type="text" name="seller_birth_place" data-key="birth_place"></div>
                    <div class="yvo-fp-field yvo-fp-field-full">
                        <label>Прописка / регистрация *</label>
                        <textarea name="seller_registration" data-key="registration" rows="2"></textarea>
                    </div>
                    <div class="yvo-fp-field yvo-fp-share-fraction-row" hidden>
                        <label><?php esc_html_e('Доля в праве (дробь, напр. 1/4)', 'yandex-vision-ocr-pro'); ?></label>
                        <input type="text" name="seller_share_fraction" data-key="share_fraction" autocomplete="off">
                    </div>
                    <?php $reg_name_prefix = 'seller_'; include __DIR__ . '/partials/registration-fields-sync.php'; ?>
                </div>
            </div>

            <div class="yvo-fp-panel" id="yvo-fp-panel-buyer">
                <div class="yvo-fp-grid">
                    <div class="yvo-fp-field"><label>ФИО *</label><input type="text" name="buyer_full_name" data-key="full_name" required></div>
                    <div class="yvo-fp-field"><label>Код подразделения</label><input type="text" name="buyer_department_code" data-key="department_code" placeholder="000-000"></div>
                    <div class="yvo-fp-field"><label>Серия паспорта *</label><input type="text" name="buyer_passport_series" data-key="passport_series" maxlength="4"></div>
                    <div class="yvo-fp-field"><label>Номер паспорта *</label><input type="text" name="buyer_passport_number" data-key="passport_number" maxlength="6"></div>
                    <div class="yvo-fp-field yvo-fp-field-issued-by">
                        <label>Кем выдан *</label>
                        <textarea name="buyer_passport_issued_by" data-key="passport_issued_by" rows="2"></textarea>
                    </div>
                    <div class="yvo-fp-field"><label>Дата выдачи</label><input type="text" name="buyer_passport_date" data-key="passport_date" placeholder="дд.мм.гггг"></div>
                    <div class="yvo-fp-field"><label>Дата рождения</label><input type="text" name="buyer_birth_date" data-key="birth_date" placeholder="дд.мм.гггг"></div>
                    <div class="yvo-fp-field"><label>Место рождения</label><input type="text" name="buyer_birth_place" data-key="birth_place"></div>
                    <div class="yvo-fp-field yvo-fp-field-full">
                        <label>Прописка / регистрация *</label>
                        <textarea name="buyer_registration" data-key="registration" rows="2"></textarea>
                    </div>
                    <div class="yvo-fp-field yvo-fp-share-fraction-row" hidden>
                        <label><?php esc_html_e('Доля в праве (дробь, напр. 1/4)', 'yandex-vision-ocr-pro'); ?></label>
                        <input type="text" name="buyer_share_fraction" data-key="share_fraction" autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="yvo-fp-panel" id="yvo-fp-panel-property">
                <input type="hidden" name="property_object_type" id="property_object_type" data-key="object_type" value="apartment">
                <p class="yvo-fp-object-type-current"><strong>Тип объекта:</strong> <span id="yvo-fp-object-type-label">Квартира</span></p>

                <!-- Параметры объекта (сворачиваемый блок) -->
                <div class="yvo-fp-collapse-block<?php echo $use_doki_shell ? ' yvo-doki-deal-acc is-open' : ''; ?>">
                    <button type="button" class="yvo-fp-collapse-header" aria-expanded="true" data-collapse="yvo-fp-params-body">
                        <span class="yvo-fp-collapse-title"><?php echo $use_doki_shell ? esc_html__('Параметры объекта', 'yandex-vision-ocr-pro') : 'Параметры объекта'; ?></span>
                        <span class="yvo-fp-collapse-arrow">▼</span>
                    </button>
                    <div class="yvo-fp-collapse-body" id="yvo-fp-params-body">
                <!-- Квартира -->
                <div class="yvo-fp-object-type-block active" data-object-type="apartment">
                    <div class="yvo-fp-grid">
                        <div class="yvo-fp-field"><label>Тип недвижимости</label><input type="text" name="property_property_type" data-key="property_type" value="квартира" readonly></div>
                        <div class="yvo-fp-field"><label>Кадастровый номер квартиры</label><input type="text" name="property_cadastral_number" data-key="cadastral_number" placeholder="00:00:0000000:00"></div>
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Адрес (полный) *</label><input type="text" name="property_address" data-key="address" placeholder="г. Москва, ул. Примерная, д. 1, кв. 1"></div>
                        <div class="yvo-fp-field yvo-fp-field-full yvo-fp-address-details" data-address-scope="apartment">
                            <div class="yvo-fp-grid yvo-fp-address-details-grid">
                                <div class="yvo-fp-field"><label>Город *</label><input type="text" name="property_city" data-key="city"></div>
                                <div class="yvo-fp-field"><label>Улица *</label><input type="text" name="property_street" data-key="street"></div>
                                <div class="yvo-fp-field"><label>Дом *</label><input type="text" name="property_house" data-key="house"></div>
                                <div class="yvo-fp-field"><label>Корпус</label><input type="text" name="property_building" data-key="building"></div>
                                <div class="yvo-fp-field"><label>Квартира *</label><input type="text" name="property_apartment" data-key="apartment"></div>
                            </div>
                        </div>
                        <div class="yvo-fp-field"><label>Общая площадь (кв. м) *</label><input type="number" name="property_area" data-key="area" step="0.01" min="0"></div>
                        <div class="yvo-fp-field"><label>Этаж</label><input type="number" name="property_floor" data-key="floor" min="0"></div>
                        <div class="yvo-fp-field"><label>Этажность дома</label><input type="number" name="property_floors_total" data-key="floors_total" min="0"></div>
                        <div class="yvo-fp-field"><label>Количество комнат *</label><input type="number" name="property_rooms" data-key="rooms" min="0"></div>
                        <?php $reg_name_prefix = 'property_'; include __DIR__ . '/partials/registration-fields-sync.php'; ?>
                    </div>
                </div>

                <!-- Доля в квартире -->
                <div class="yvo-fp-object-type-block" data-object-type="share">
                    <div class="yvo-fp-grid">
                        <div class="yvo-fp-field"><label>Тип недвижимости</label><input type="text" name="property_share_type" data-key="property_type" value="доля в праве общей долевой собственности на квартиру" readonly></div>
                        <div class="yvo-fp-field"><label>Размер доли (отчуждаемой) *</label><input type="text" name="property_share_in_right" data-key="share_in_right" placeholder="например: 1/2"></div>
                        <div class="yvo-fp-field"><label>Кадастровый номер квартиры</label><input type="text" name="property_share_cadastral" data-key="cadastral_number" placeholder="00:00:0000000:00"></div>
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Адрес квартиры (полный) *</label><input type="text" name="property_share_address" data-key="address" placeholder="г., ул., д., кв."></div>
                        <div class="yvo-fp-field yvo-fp-field-full yvo-fp-address-details" data-address-scope="share">
                            <div class="yvo-fp-grid yvo-fp-address-details-grid">
                                <div class="yvo-fp-field"><label>Город *</label><input type="text" name="property_share_city" data-key="city"></div>
                                <div class="yvo-fp-field"><label>Улица *</label><input type="text" name="property_share_street" data-key="street"></div>
                                <div class="yvo-fp-field"><label>Дом *</label><input type="text" name="property_share_house" data-key="house"></div>
                                <div class="yvo-fp-field"><label>Корпус</label><input type="text" name="property_share_building" data-key="building"></div>
                                <div class="yvo-fp-field"><label>Квартира *</label><input type="text" name="property_share_apartment" data-key="apartment"></div>
                            </div>
                        </div>
                        <div class="yvo-fp-field"><label>Общая площадь квартиры (кв. м) *</label><input type="number" name="property_share_area" data-key="area" step="0.01" min="0"></div>
                        <div class="yvo-fp-field"><label>Жилая площадь квартиры (кв. м)</label><input type="number" name="property_share_living_area" data-key="living_area" step="0.01" min="0" placeholder="для п. 1 договора"></div>
                        <div class="yvo-fp-field"><label>Этаж</label><input type="number" name="property_share_floor" data-key="floor" min="0"></div>
                        <div class="yvo-fp-field"><label>Этажность дома</label><input type="number" name="property_share_floors_total" data-key="floors_total" min="0"></div>
                        <div class="yvo-fp-field"><label>Количество комнат в квартире *</label><input type="number" name="property_share_rooms" data-key="rooms" min="0"></div>
                        <?php $reg_name_prefix = 'property_share_'; include __DIR__ . '/partials/registration-fields-sync.php'; ?>
                    </div>
                </div>

                <!-- Комната -->
                <div class="yvo-fp-object-type-block" data-object-type="room">
                    <div class="yvo-fp-grid">
                        <div class="yvo-fp-field"><label>Тип недвижимости</label><input type="text" name="property_room_type" data-key="property_type" value="комната" readonly></div>
                        <div class="yvo-fp-field"><label>Кадастровый номер комнаты (или квартиры с указанием комнаты)</label><input type="text" name="property_room_cadastral" data-key="cadastral_number"></div>
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Адрес (полный) *</label><input type="text" name="property_room_address" data-key="address" placeholder="г., ул., д., кв., комната"></div>
                        <div class="yvo-fp-field yvo-fp-field-full yvo-fp-address-details" data-address-scope="room">
                            <div class="yvo-fp-grid yvo-fp-address-details-grid">
                                <div class="yvo-fp-field"><label>Город *</label><input type="text" name="property_room_city" data-key="city"></div>
                                <div class="yvo-fp-field"><label>Улица *</label><input type="text" name="property_room_street" data-key="street"></div>
                                <div class="yvo-fp-field"><label>Дом</label><input type="text" name="property_room_house" data-key="house"></div>
                                <div class="yvo-fp-field"><label>Корпус</label><input type="text" name="property_room_building" data-key="building"></div>
                                <div class="yvo-fp-field"><label>Квартира</label><input type="text" name="property_room_apartment" data-key="apartment"></div>
                                <div class="yvo-fp-field"><label>Номер комнаты *</label><input type="text" name="property_room_number" data-key="room_number"></div>
                            </div>
                        </div>
                        <div class="yvo-fp-field"><label>Площадь комнаты, жилая (кв. м) *</label><input type="number" name="property_room_area" data-key="area" step="0.01" min="0"></div>
                        <div class="yvo-fp-field"><label>Этаж</label><input type="number" name="property_room_floor" data-key="floor" min="0"></div>
                        <div class="yvo-fp-field"><label>Этажность дома</label><input type="number" name="property_room_floors_total" data-key="floors_total" min="0"></div>
                        <div class="yvo-fp-field"><label>Общая площадь квартиры (для сведения)</label><input type="number" name="property_room_apt_area" data-key="apartment_total_area" step="0.01" min="0"></div>
                    </div>
                </div>

                <!-- Участок -->
                <div class="yvo-fp-object-type-block" data-object-type="land">
                    <div class="yvo-fp-grid">
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Адрес (полный) / описание участка *</label><input type="text" name="property_land_address" data-key="address" placeholder="область, район, СНТ, участок №"></div>
                        <div class="yvo-fp-field"><label>Область</label><input type="text" name="property_land_region" data-key="region"></div>
                        <div class="yvo-fp-field"><label>Район</label><input type="text" name="property_land_district" data-key="district"></div>
                        <div class="yvo-fp-field"><label>Населённый пункт *</label><input type="text" name="property_land_settlement" data-key="settlement"></div>
                        <div class="yvo-fp-field"><label>СНТ / ДНТ</label><input type="text" name="property_land_snt" data-key="snt_dnt"></div>
                        <div class="yvo-fp-field"><label>Участок № *</label><input type="text" name="property_land_plot_number" data-key="plot_number"></div>
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Кадастровый номер участка</label><input type="text" name="property_land_cadastral" data-key="cadastral_number"></div>
                        <div class="yvo-fp-field"><label>Категория земель</label><input type="text" name="property_land_category" data-key="land_category"></div>
                        <div class="yvo-fp-field"><label>Вид разрешённого использования</label><input type="text" name="property_land_vri" data-key="land_vri"></div>
                        <div class="yvo-fp-field"><label>Площадь (соток или кв. м) *</label><input type="text" name="property_land_area" data-key="area" placeholder="например: 6 сот."></div>
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Сведения о границах</label><select name="property_land_boundaries" data-key="boundaries"><option value="">—</option><option value="установлены">установлены</option><option value="не установлены">не установлены</option></select></div>
                    </div>
                </div>

                <!-- Дом с участком -->
                <div class="yvo-fp-object-type-block" data-object-type="house_with_plot">
                    <h4 class="yvo-fp-subtitle">Объект (дом)</h4>
                    <div class="yvo-fp-grid">
                        <div class="yvo-fp-field"><label>Населённый пункт *</label><input type="text" name="property_house_settlement" data-key="house_settlement"></div>
                        <div class="yvo-fp-field"><label>Улица *</label><input type="text" name="property_house_street" data-key="house_street"></div>
                        <div class="yvo-fp-field"><label>Номер дома *</label><input type="text" name="property_house_number" data-key="house_number"></div>
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Кадастровый номер дома</label><input type="text" name="property_house_cadastral" data-key="house_cadastral_number"></div>
                        <div class="yvo-fp-field"><label>Назначение</label><select name="property_house_purpose" data-key="house_purpose"><option value="">—</option><option value="жилой дом">жилой дом</option><option value="садовый дом">садовый дом</option></select></div>
                        <div class="yvo-fp-field"><label>Площадь общая (кв. м)</label><input type="number" name="property_house_area" data-key="house_area" step="0.01" min="0"></div>
                        <div class="yvo-fp-field"><label>Площадь жилая (кв. м)</label><input type="number" name="property_house_living_area" data-key="house_living_area" step="0.01" min="0"></div>
                        <div class="yvo-fp-field"><label>Этажность</label><input type="number" name="property_house_floors" data-key="house_floors" min="0"></div>
                        <div class="yvo-fp-field"><label>Материал стен</label><input type="text" name="property_house_material" data-key="house_material"></div>
                    </div>
                    <h4 class="yvo-fp-subtitle">Объект (участок)</h4>
                    <div class="yvo-fp-grid">
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Кадастровый номер участка</label><input type="text" name="property_plot_cadastral" data-key="plot_cadastral_number"></div>
                        <div class="yvo-fp-field"><label>Категория земель</label><input type="text" name="property_plot_category" data-key="plot_land_category"></div>
                        <div class="yvo-fp-field"><label>ВРИ</label><input type="text" name="property_plot_vri" data-key="plot_vri"></div>
                        <div class="yvo-fp-field"><label>Площадь участка</label><input type="text" name="property_plot_area" data-key="plot_area" placeholder="соток или кв. м"></div>
                    </div>
                    <div class="yvo-fp-grid yvo-fp-price-option">
                        <div class="yvo-fp-field"><label>Цена указана</label><select name="property_house_plot_price_type" data-key="house_plot_price_type"><option value="общая">одна общая цена за дом и участок</option><option value="раздельно">раздельно (дом и участок)</option></select></div>
                    </div>
                </div>

                <!-- Гараж -->
                <div class="yvo-fp-object-type-block" data-object-type="garage">
                    <div class="yvo-fp-grid">
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Адрес: гаражный кооператив, бокс № *</label><input type="text" name="property_garage_address" data-key="address"></div>
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Кадастровый номер</label><input type="text" name="property_garage_cadastral" data-key="cadastral_number"></div>
                        <div class="yvo-fp-field"><label>Площадь (кв. м)</label><input type="number" name="property_garage_area" data-key="area" step="0.01" min="0"></div>
                        <div class="yvo-fp-field"><label>Назначение</label><select name="property_garage_purpose" data-key="purpose"><option value="">—</option><option value="нежилое помещение">нежилое помещение</option><option value="гараж">гараж</option></select></div>
                        <div class="yvo-fp-field"><label>Этаж</label><input type="number" name="property_garage_floor" data-key="floor" min="0"></div>
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Подвал / смотровая яма</label><input type="text" name="property_garage_basement" data-key="basement_observation_pit" placeholder="если есть"></div>
                    </div>
                </div>

                <!-- Паркинг -->
                <div class="yvo-fp-object-type-block" data-object-type="parking">
                    <div class="yvo-fp-grid">
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Адрес: подземный паркинг, машино-место № *</label><input type="text" name="property_parking_address" data-key="address"></div>
                        <div class="yvo-fp-field yvo-fp-field-full"><label>Кадастровый номер</label><input type="text" name="property_parking_cadastral" data-key="cadastral_number"></div>
                        <div class="yvo-fp-field"><label>Площадь (кв. м)</label><input type="number" name="property_parking_area" data-key="area" step="0.01" min="0"></div>
                        <div class="yvo-fp-field"><label>Этаж</label><input type="number" name="property_parking_floor" data-key="floor" min="0"></div>
                    </div>
                </div>

                <div class="yvo-fp-collapse-block yvo-fp-egrn-check-wrap">
                    <button type="button" class="yvo-fp-collapse-header" aria-expanded="false" data-collapse="yvo-fp-egrn-check-body">
                        <span class="yvo-fp-collapse-title">Справка по выписке ЕГРН</span>
                        <span class="yvo-fp-collapse-arrow">▼</span>
                    </button>
                    <div class="yvo-fp-collapse-body yvo-fp-egrn-check-block" id="yvo-fp-egrn-check-body" style="display:none;">
                        <div class="yvo-fp-grid">
                            <div class="yvo-fp-field yvo-fp-field-full"><label>Документы-основания (раздел 3)</label><textarea rows="2" data-egrn-check-key="basis_documents" readonly></textarea></div>
                            <div class="yvo-fp-field yvo-fp-field-full"><label>Основание гос. регистрации</label><textarea rows="2" data-egrn-check-key="registration_basis" readonly></textarea></div>
                            <div class="yvo-fp-field yvo-fp-field-full"><label>Ограничения и обременения</label><textarea rows="3" data-egrn-check-key="restrictions_summary" readonly></textarea></div>
                            <div class="yvo-fp-field yvo-fp-field-full"><label>Банк / залогодержатель</label><input type="text" data-egrn-check-key="bank_info" readonly></div>
                            <div class="yvo-fp-field"><label>Вид обременения</label><input type="text" data-egrn-check-key="encumbrance_type" readonly></div>
                            <div class="yvo-fp-field"><label>Ипотека</label><input type="text" data-egrn-check-key="has_mortgage" readonly></div>
                            <div class="yvo-fp-field"><label>Дата рег. обременения</label><input type="text" data-egrn-check-key="encumbrance_registration_date" readonly></div>
                            <div class="yvo-fp-field"><label>Номер рег. обременения</label><input type="text" data-egrn-check-key="encumbrance_registration_number" readonly></div>
                            <div class="yvo-fp-field yvo-fp-field-full"><label>Срок действия обременения</label><input type="text" data-egrn-check-key="restriction_term" readonly></div>
                            <div class="yvo-fp-field"><label>Дата возникновения права</label><input type="text" data-egrn-check-key="right_start_date" readonly></div>
                            <div class="yvo-fp-field"><label>Регистрация без личного участия</label><input type="text" data-egrn-check-key="registration_without_personal" readonly></div>
                            <div class="yvo-fp-field yvo-fp-field-full"><label>Доверенность</label><input type="text" data-egrn-check-key="power_of_attorney" readonly></div>
                            <div class="yvo-fp-field yvo-fp-field-full"><label>Доп. сведения</label><textarea rows="2" data-egrn-check-key="additional_info" readonly></textarea></div>
                        </div>
                    </div>
                </div>

                    </div>
                </div>

                <!-- Общий блок: Цена и расчёты (для всех типов объекта) -->
                <?php include __DIR__ . '/partials/property-price-block.php'; ?>
                <?php include __DIR__ . '/partials/share-allocation-block.php'; ?>

                <!-- Условия (сворачиваемый блок) -->
                <div class="yvo-fp-collapse-block yvo-fp-conditions-section<?php echo $use_doki_shell ? ' yvo-doki-deal-acc is-open' : ''; ?>">
                    <button type="button" class="yvo-fp-collapse-header" aria-expanded="true" data-collapse="yvo-fp-conditions-body">
                        <span class="yvo-fp-collapse-title"><?php echo $use_doki_shell ? esc_html__('Условия', 'yandex-vision-ocr-pro') : 'Условия'; ?></span>
                        <span class="yvo-fp-collapse-arrow">▼</span>
                    </button>
                    <div class="yvo-fp-collapse-body" id="yvo-fp-conditions-body">
                        <div class="yvo-fp-grid">
                            <div class="yvo-fp-field yvo-fp-field-full">
                                <label>Что остаётся в квартире / комнате / в доме</label>
                                <textarea name="property_what_stays" data-key="what_stays" rows="3" placeholder="мебель, техника, что передаётся покупателю"></textarea>
                            </div>
                            <div class="yvo-fp-field">
                                <label>Срок снятия с регистрации</label>
                                <input type="text" name="property_deregistration_deadline" id="property_deregistration_deadline" data-key="deregistration_deadline" value="14 дней" placeholder="14 дней">
                            </div>
                            <div class="yvo-fp-field">
                                <label>Срок освобождения (выселения)</label>
                                <input type="text" name="property_vacate_deadline" id="property_vacate_deadline" data-key="vacate_deadline" value="14 дней" placeholder="14 дней">
                            </div>
                            <div class="yvo-fp-field yvo-fp-field-full yvo-fp-deposit-advance-only" hidden>
                                <label>Срок выхода на сделку (макс. срок)</label>
                                <input type="text" name="property_main_contract_deadline" id="property_main_contract_deadline" data-key="main_contract_deadline" placeholder="30.06.2026">
                            </div>
                            <div class="yvo-fp-field yvo-fp-field-full" id="yvo-fp-joint-ownership-row" hidden>
                                <label style="display:flex;align-items:center;gap:10px;">
                                    <input type="checkbox" name="property_joint_ownership" id="property_joint_ownership" data-key="joint_ownership" value="1">
                                    <span><?php esc_html_e('Продавцы (2 человека) владеют на праве совместной собственности (супруги) — доли не указывать', 'yandex-vision-ocr-pro'); ?></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="yvo-fp-collapse-block yvo-fp-ownership-history-wrap<?php echo $use_doki_shell ? ' yvo-doki-deal-acc' : ''; ?>" id="yvo-fp-ownership-history-wrap" hidden>
                    <button type="button" class="yvo-fp-collapse-header" aria-expanded="false" data-collapse="yvo-fp-ownership-history-body">
                        <span class="yvo-fp-collapse-title">История собственников</span>
                        <span class="yvo-fp-collapse-arrow">▼</span>
                    </button>
                    <div class="yvo-fp-collapse-body" id="yvo-fp-ownership-history-body" style="display:none;">
                        <p class="yvo-fp-ownership-history-hint">В списке «Извлечённые данные» — все распознанные собственники (текущие и из истории). В договор удобнее подставлять отмеченных как «Текущий»; действующих может быть несколько.</p>
                        <div id="yvo-fp-ownership-history-list" class="yvo-fp-ownership-history-list"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($use_doki_shell) : ?>
        <div class="yvo-doki-participant-foot">
            <div class="yvo-fp-tab-actions participant-action-bar yvo-doki-participant-actions yvo-doki-participant-actions--foot" id="yvoDokiParticipantCardActions">
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-delete" title="<?php esc_attr_e('Удалить текущую вкладку (только добавленные)', 'yandex-vision-ocr-pro'); ?>"><?php esc_html_e('Удалить', 'yandex-vision-ocr-pro'); ?></button>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-clear" title="<?php esc_attr_e('Очистить поля текущей вкладки', 'yandex-vision-ocr-pro'); ?>"><?php esc_html_e('Очистить', 'yandex-vision-ocr-pro'); ?></button>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-copy" title="<?php esc_attr_e('Скопировать данные текущей вкладки', 'yandex-vision-ocr-pro'); ?>"><?php esc_html_e('Копировать', 'yandex-vision-ocr-pro'); ?></button>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-paste" title="<?php esc_attr_e('Вставить скопированные данные', 'yandex-vision-ocr-pro'); ?>"><?php esc_html_e('Вставить', 'yandex-vision-ocr-pro'); ?></button>
                <div class="yvo-fp-dropdown yvo-fp-cabinet-inline-dropdown yvo-fp-cabinet-inline-dropdown--toolbar">
                    <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-tab-action yvo-fp-tab-action-sm yvo-fp-cabinet-inline-toggle" id="yvo-fp-tab-cabinet-fill" title="<?php esc_attr_e('Подставить данные из личного кабинета для текущей вкладки', 'yandex-vision-ocr-pro'); ?>"><?php esc_html_e('Вставить из кабинета', 'yandex-vision-ocr-pro'); ?> ▾</button>
                    <div class="yvo-fp-cabinet-inline-menu" id="yvo-fp-cabinet-inline-menu-toolbar" role="menu" hidden></div>
                </div>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-primary yvo-fp-tab-action yvo-fp-tab-action-sm" id="yvo-fp-tab-save" title="<?php esc_attr_e('Сохранить текущего участника или объект в личный кабинет', 'yandex-vision-ocr-pro'); ?>"><?php esc_html_e('Сохранить', 'yandex-vision-ocr-pro'); ?></button>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <div id="yvo-fp-draft-slot" class="yvo-fp-draft-slot" aria-live="polite"></div>

    <!-- Генерация договора -->
    <section class="yvo-fp-section yvo-fp-generate" id="yvo-fp-section-generate">
        <h2 class="yvo-fp-section-title"><?php echo $use_doki_shell ? esc_html__('Генерация договора', 'yandex-vision-ocr-pro') : '4. Сгенерировать договор'; ?></h2>

        <div class="yvo-fp-generate-options">
            <div class="yvo-fp-option-row">
                <label class="yvo-fp-label">Шаблон договора:</label>
                <select id="yvo-fp-contract-template" name="contract_template">
                    <?php
                    $templates_list = yvo_get_available_templates();
                    if (empty($templates_list)) {
                        $templates_list = array('default' => 'ДКП (стандартный)');
                    }
                    $default_template = get_option('yvo_contract_template', 'default');
                    foreach ($templates_list as $tid => $label):
                    ?>
                        <option value="<?php echo esc_attr($tid); ?>" <?php selected($default_template, $tid); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-preview-template">Посмотреть шаблон</button>
                    <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-rename-template">Переименовать</button>
            </div>
            <div class="yvo-fp-option-row yvo-fp-related-contracts" id="yvo-fp-related-contracts">
                <label class="yvo-fp-label">Другие договоры:</label>
                <div class="yvo-fp-related-contracts__list" role="group" aria-label="<?php echo esc_attr__('Варианты договоров к сделке купли-продажи', 'yandex-vision-ocr-pro'); ?>">
                    <button type="button" class="yvo-fp-related-contract-btn is-active" data-contract-type="sale" data-related="dkp" title="Основной договор купли-продажи">ДКП</button>
                    <button type="button" class="yvo-fp-related-contract-btn" data-contract-type="preliminary" data-template-id="preliminary" data-related="preliminary" title="Предварительный договор купли-продажи">Предварительный</button>
                    <button type="button" class="yvo-fp-related-contract-btn" data-contract-type="deposit_agreement" data-related="deposit" title="Соглашение о задатке">Задаток</button>
                    <button type="button" class="yvo-fp-related-contract-btn" data-contract-type="advance_agreement" data-related="advance" title="Соглашение об авансе">Аванс</button>
                </div>
                <p class="yvo-fp-related-contracts__hint">На этапе генерации можно сразу выбрать один из трёх связанных договоров вместо основного ДКП.</p>
            </div>
            <div class="yvo-fp-option-row" id="yvo-fp-bank-row" style="display:none;">
                <label class="yvo-fp-label">Банк:</label>
                <select id="yvo-fp-bank" name="contract_bank">
                    <option value="standard">Стандартный</option>
                    <option value="sberbank">ПАО Сбербанк</option>
                    <option value="vtb">ВТБ</option>
                    <option value="gazprombank">Газпромбанк</option>
                    <option value="alfabank">Альфа-Банк</option>
                    <option value="raiffeisen">Райффайзенбанк</option>
                    <option value="otkritie">Банк Открытие</option>
                    <option value="rosbank">Росбанк</option>
                    <option value="tinkoff">Тинькофф</option>
                    <option value="sovkombank">Совкомбанк</option>
                </select>
            </div>
            <div class="yvo-fp-option-row yvo-fp-checkboxes" id="yvo-fp-act-receipt-options">
                <label class="yvo-fp-checkbox-label" id="yvo-fp-act-option-row"><input type="checkbox" id="yvo-fp-generate-act" name="generate_act" checked> <span id="yvo-fp-label-act">Создать акт приёма-передачи недвижимости</span></label>
                <label class="yvo-fp-checkbox-label"><input type="checkbox" id="yvo-fp-generate-receipt" name="generate_receipt" checked> <span id="yvo-fp-label-receipt">Создать расписку в получении денежных средств</span></label>
            </div>
        </div>

        <button type="button" class="yvo-fp-btn yvo-fp-btn-primary" id="yvo-fp-generate-btn">Создать договор</button>
        <p class="yvo-fp-parse-status yvo-fp-generate-validation" id="yvo-fp-generate-validation" role="alert" style="display:none;"></p>
        <div class="yvo-fp-generate-progress" id="yvo-fp-generate-progress" style="display:none;">Создание договора...</div>
        <div class="yvo-fp-contract-result" id="yvo-fp-contract-result" style="display:none;">
            <p class="yvo-fp-success-msg"></p>
            <div class="yvo-fp-download-links">
                <a href="#" class="yvo-fp-btn yvo-fp-btn-primary yvo-fp-download-link" id="yvo-fp-download-docx-link" style="display:none" target="_blank" rel="noopener">Скачать для печати (DOCX)</a>
                <a href="#" class="yvo-fp-btn yvo-fp-btn-primary yvo-fp-download-link" id="yvo-fp-download-pdf-link" style="display:none" target="_blank" rel="noopener">Скачать (PDF)</a>
                <a href="#" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-download-link" id="yvo-fp-download-act-docx-link" style="display:none" target="_blank" rel="noopener">Скачать акт (DOCX)</a>
                <a href="#" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-download-link" id="yvo-fp-download-receipt-docx-link" style="display:none" target="_blank" rel="noopener">Скачать расписку (DOCX)</a>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-edit-contract-btn">Редактировать</button>

                <div class="yvo-fp-options" id="yvo-fp-contract-options">
                    <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary yvo-fp-options-toggle" id="yvo-fp-contract-options-toggle" aria-haspopup="true" aria-expanded="false" title="Опции">Опции <span class="yvo-fp-dropdown-arrow">▼</span></button>
                    <div class="yvo-fp-options-menu" id="yvo-fp-contract-options-menu" role="menu" aria-label="Опции договора" style="display:none;">
                        <a href="#" class="yvo-fp-options-item yvo-fp-download-link" id="yvo-fp-download-html-link" style="display:none" target="_blank" rel="noopener" role="menuitem">Открыть HTML-версию</a>
                        <a href="#" class="yvo-fp-options-item yvo-fp-download-link" id="yvo-fp-download-doc-link" style="display:none" target="_blank" rel="noopener" role="menuitem">Скачать (DOC)</a>
                        <a href="#" class="yvo-fp-options-item yvo-fp-download-link" id="yvo-fp-download-link" target="_blank" rel="noopener" role="menuitem">Скачать договор (TXT)</a>
                        <button type="button" class="yvo-fp-options-item yvo-fp-check-contract-btn" id="yvo-fp-check-contract-btn" role="menuitem" style="display:none;">Проверить договор</button>
                        <a href="#" class="yvo-fp-options-item yvo-fp-download-link" id="yvo-fp-download-act-link" role="menuitem" style="display:none" target="_blank" rel="noopener">Скачать акт</a>
                        <button type="button" class="yvo-fp-options-item" id="yvo-fp-edit-act-btn" role="menuitem" style="display:none">Редактировать акт</button>
                        <a href="#" class="yvo-fp-options-item yvo-fp-download-link" id="yvo-fp-download-receipt-link" role="menuitem" style="display:none" target="_blank" rel="noopener">Скачать расписку</a>
                        <button type="button" class="yvo-fp-options-item" id="yvo-fp-edit-receipt-btn" role="menuitem" style="display:none">Редактировать расписку</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Проверка договора: загрузка/вставка текста и проверка (до личного кабинета) -->
    <section class="yvo-fp-section yvo-fp-check-contract-standalone">
        <h2 class="yvo-fp-section-title">Проверка договора</h2>
        <p class="yvo-fp-section-hint">Вставьте текст договора в поле ниже или загрузите файл (.txt), затем нажмите «Проверить договор». Доступно только авторизованным пользователям.</p>
        <div class="yvo-fp-check-contract-input-wrap">
            <textarea id="yvo-fp-check-contract-text" class="yvo-fp-check-contract-text" rows="10" placeholder="Вставьте сюда текст договора или загрузите файл..."></textarea>
            <div class="yvo-fp-check-contract-actions">
                <input type="file" id="yvo-fp-check-contract-file" accept=".txt" style="display:none">
                <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-check-contract-load-btn">Загрузить договор (.txt)</button>
                <button type="button" class="yvo-fp-btn yvo-fp-btn-primary" id="yvo-fp-check-contract-standalone-btn">Проверить договор</button>
            </div>
        </div>
    </section>

    <?php if ($use_doki_shell) : ?>
    </div><!-- .yvo-doki-mos-scroll -->
    <div class="yvo-doki-step-footer" role="navigation" aria-label="<?php esc_attr_e('Переход между шагами', 'yandex-vision-ocr-pro'); ?>">
        <button type="button" class="yvo-doki-step-btn yvo-doki-step-prev" id="yvoDokiStepPrev" aria-label="<?php esc_attr_e('Назад к предыдущему шагу', 'yandex-vision-ocr-pro'); ?>"><?php esc_html_e('Назад', 'yandex-vision-ocr-pro'); ?></button>
        <span class="yvo-doki-step-label" id="yvoDokiStepFooterLabel"><?php esc_html_e('Шаг 1 из 4 · продавец', 'yandex-vision-ocr-pro'); ?></span>
        <button type="button" class="yvo-doki-step-btn yvo-doki-step-next" id="yvoDokiStepNext" aria-label="<?php esc_attr_e('Далее к следующему шагу', 'yandex-vision-ocr-pro'); ?>"><?php esc_html_e('Далее', 'yandex-vision-ocr-pro'); ?></button>
    </div>
    <?php endif; ?>

    <!-- Встроенный «Личный кабинет» убран: используем отдельную страницу кабинета в верхней шапке. -->

    <!-- Модальное окно: редактор договора + чат -->
    <div class="yvo-fp-modal" id="yvo-fp-contract-editor-modal" role="dialog" aria-modal="true" style="display:none;">
        <div class="yvo-fp-modal-backdrop"></div>
        <div class="yvo-fp-modal-content yvo-fp-editor-modal-content">
            <div class="yvo-fp-modal-header">
                <h2 class="yvo-fp-modal-title" id="yvo-fp-editor-modal-title">Редактирование договора</h2>
                <button type="button" class="yvo-fp-modal-close" aria-label="Закрыть">&times;</button>
            </div>
            <div class="yvo-fp-editor-body">
                <div class="yvo-fp-editor-main">
                    <label class="yvo-fp-editor-label" id="yvo-fp-editor-text-label">Текст договора</label>
                    <textarea id="yvo-fp-contract-editor-text" class="yvo-fp-contract-editor-text" rows="18" placeholder="Текст договора..."></textarea>
                    <div class="yvo-fp-editor-actions">
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-primary" id="yvo-fp-editor-save-btn">Сохранить договор</button>
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-secondary" id="yvo-fp-editor-recreate-btn">Создать заново</button>
                    </div>
                </div>
                <div class="yvo-fp-editor-chat">
                    <label class="yvo-fp-editor-label">Помощник: что не заполнено</label>
                    <p class="yvo-fp-editor-chat-hint">Список незаполненных полей в договоре. Заполните значения и нажмите «Подставить в договор» — они подставятся в текст. Сохраните договор кнопкой слева.</p>
                    <div id="yvo-fp-editor-placeholders-list" class="yvo-fp-editor-placeholders-list"></div>
                    <div class="yvo-fp-editor-actions yvo-fp-editor-actions-right">
                        <button type="button" class="yvo-fp-btn yvo-fp-btn-primary" id="yvo-fp-editor-apply-placeholders-btn">Подставить в договор</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно: просмотр шаблона -->
    <div class="yvo-fp-modal" id="yvo-fp-template-preview-modal" role="dialog" aria-modal="true" aria-labelledby="yvo-fp-modal-title" style="display:none;">
        <div class="yvo-fp-modal-backdrop"></div>
        <div class="yvo-fp-modal-content">
            <div class="yvo-fp-modal-header">
                <h3 id="yvo-fp-modal-title">Текст шаблона договора</h3>
                <button type="button" class="yvo-fp-modal-close" id="yvo-fp-modal-close" aria-label="Закрыть">&times;</button>
            </div>
            <div class="yvo-fp-modal-body">
                <pre id="yvo-fp-template-preview-text" class="yvo-fp-template-preview"></pre>
            </div>
        </div>
    </div>

    <!-- Модальное окно: PRO / тарифы -->
    <div class="yvo-fp-modal" id="yvo-fp-pro-modal" role="dialog" aria-modal="true" style="display:none;">
        <div class="yvo-fp-modal-backdrop"></div>
        <div class="yvo-fp-modal-content" style="max-width:560px;">
            <div class="yvo-fp-modal-header">
                <h3 class="yvo-fp-modal-title">Доступно в PRO</h3>
                <button type="button" class="yvo-fp-modal-close" aria-label="Закрыть">&times;</button>
            </div>
            <div class="yvo-fp-modal-body">
                <p>Загрузка собственных шаблонов (договор/акт) доступна в PRO-версии.</p>
                <div class="yvo-fp-pricing">
                    <div class="yvo-fp-pricing-row"><span class="yvo-fp-pricing-name">Разово — стандарт</span><span class="yvo-fp-pricing-price">200 ₽</span></div>
                    <div class="yvo-fp-pricing-row"><span class="yvo-fp-pricing-name">Разово — сложный</span><span class="yvo-fp-pricing-price">390 ₽</span></div>
                    <div class="yvo-fp-pricing-row"><span class="yvo-fp-pricing-name">Про (10 ген./мес.)</span><span class="yvo-fp-pricing-price">690 ₽</span></div>
                    <div class="yvo-fp-pricing-row"><span class="yvo-fp-pricing-name">Бизнес (30 ген./мес.)</span><span class="yvo-fp-pricing-price">1 200 ₽</span></div>
                </div>
                <p class="yvo-fp-section-hint">Для подключения PRO обратитесь к администратору.</p>
            </div>
        </div>
    </div>

    <!-- Модальное окно: результаты проверки договора -->
    <div class="yvo-fp-modal" id="yvo-fp-check-result-modal" role="dialog" aria-modal="true" style="display:none;">
        <div class="yvo-fp-modal-backdrop"></div>
        <div class="yvo-fp-modal-content yvo-fp-editor-modal-content" style="max-width:720px;">
            <div class="yvo-fp-modal-header">
                <h3 class="yvo-fp-modal-title">Результаты проверки договора</h3>
                <button type="button" class="yvo-fp-modal-close" aria-label="Закрыть">&times;</button>
            </div>
            <div class="yvo-fp-modal-body">
                <pre id="yvo-fp-check-result-report" class="yvo-fp-check-result-report"></pre>
                <div class="yvo-fp-check-result-actions" id="yvo-fp-check-result-actions" style="display:none;">
                    <button type="button" class="yvo-fp-btn yvo-fp-btn-primary" id="yvo-fp-check-result-apply-btn">Подставить исправленный текст в редактор</button>
                </div>
            </div>
        </div>
    </div>

    <div class="yvo-fp-error" id="yvo-fp-error" style="display:none;"></div>
        <?php if ($use_doki_shell) : ?>
            </div>
        </div>
        <?php endif; ?>
</div>
<script>
(function(){
    var list = <?php
        /* Полный каталог для выпадающего списка и фильтра по типу сделки. Ограничение тарифа free — только при генерации (AJAX), см. wp-power-ocr-free.php */
        echo json_encode(yvo_get_available_templates());
    ?>;
    var cat = <?php echo json_encode(yvo_get_template_categories()); ?>;
    var banks = <?php echo json_encode(yvo_get_template_banks()); ?>;
    if (!list || typeof list !== 'object' || Object.keys(list).length === 0) {
        list = { 'default': 'ДКП (стандартный)' };
        cat = { 'default': 'sale' };
    }
    window.yvo_contract_templates = list;
    window.yvo_template_categories = cat;
    window.yvo_template_banks = banks || {};
})();
window.yvo_default_contract_template = <?php echo json_encode(get_option('yvo_contract_template', 'default')); ?>;
<?php if (defined('WP_DEBUG') && WP_DEBUG) : ?>
(function(){
    var t = window.yvo_contract_templates || {};
    var c = window.yvo_template_categories || {};
    var preliminary = [];
    for (var id in t) { if (t.hasOwnProperty(id) && c[id] === 'preliminary') preliminary.push({ id: id, label: t[id] }); }
    console.log('[YVO] Шаблонов: ' + Object.keys(t).length + ', preliminary (ПДКП): ' + preliminary.length);
    if (preliminary.length) console.log('[YVO] ПДКП:', preliminary);
})();
<?php endif; ?>
</script>
<?php if (!empty($use_doki_shell)) : ?>
</div>
<?php endif; ?>
