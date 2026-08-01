<?php
if (!defined('ABSPATH')) {
    exit;
}

$year = (int) gmdate('Y');
$contracts_url = function_exists('yvo_cabinet_get_contracts_url') ? yvo_cabinet_get_contracts_url() : home_url('/');
$pricing_url = function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/');
$catalog = function_exists('yvo_templates_library_catalog') ? yvo_templates_library_catalog() : array();

/**
 * @param string $tone
 */
function yvo_tpl_card_icon($tone) {
    $icons = array(
        'featured' => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.6"/><path d="M14 3v5h5" stroke="currentColor" stroke-width="1.6"/><path d="M8 13h8M8 17h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'orange'   => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2.5" stroke="currentColor" stroke-width="1.6"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'blue'     => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 20V9l8-4 8 4v11" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 20v-5h6v5" stroke="currentColor" stroke-width="1.6"/></svg>',
        'green'    => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 20V9l8-4 8 4v11" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 20v-5h6v5" stroke="currentColor" stroke-width="1.6"/></svg>',
        'white'    => '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v18M5 8h14M7 13h10M9 18h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
    );
    $svg = isset($icons[$tone]) ? $icons[$tone] : $icons['white'];
    echo '<div class="yvo-tpl-card__icon yvo-tpl-card__icon--' . esc_attr($tone) . '" aria-hidden="true">' . $svg . '</div>';
}
?>
<div class="yvo-tpl-page yvo-tpl-stage">
    <div class="yvo-tpl-bg" aria-hidden="true">
        <span class="yvo-tpl-orb yvo-tpl-orb--1"></span>
        <span class="yvo-tpl-orb yvo-tpl-orb--2"></span>
        <span class="yvo-tpl-orb yvo-tpl-orb--3"></span>
    </div>

    <div class="yvo-tpl-wrap">
        <header class="yvo-tpl-head">
            <p class="yvo-tpl-head__eyebrow"><?php esc_html_e('Бесплатные бланки', 'yandex-vision-ocr-pro'); ?></p>
            <h1 class="yvo-tpl-head__title">
                <?php esc_html_e('Шаблоны договоров', 'yandex-vision-ocr-pro'); ?>
                <span>АРР</span>
            </h1>
            <p class="yvo-tpl-head__lead">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: year */
                        __('Простые образцы %d для скачивания в Word. Заполните вручную или соберите готовый договор онлайн по паспорту и ЕГРН.', 'yandex-vision-ocr-pro'),
                        $year
                    )
                );
                ?>
            </p>
            <div class="yvo-tpl-head__actions">
                <a class="yvo-tpl-btn yvo-tpl-btn--primary" href="<?php echo esc_url($contracts_url); ?>">
                    <?php esc_html_e('Заполнить онлайн', 'yandex-vision-ocr-pro'); ?>
                </a>
                <a class="yvo-tpl-btn yvo-tpl-btn--ghost" href="#yvo-tpl-grid">
                    <?php esc_html_e('Скачать бланки', 'yandex-vision-ocr-pro'); ?>
                </a>
            </div>
        </header>

        <section class="yvo-tpl-note card3d" aria-label="<?php esc_attr_e('Важно', 'yandex-vision-ocr-pro'); ?>">
            <p>
                <?php esc_html_e('Шаблоны типовые и даны для ознакомления. Они не заменяют юридическую консультацию. Для сделки с вашими данными удобнее генератор АРР — подставит стороны и объект автоматически.', 'yandex-vision-ocr-pro'); ?>
            </p>
        </section>

        <div id="yvo-tpl-grid" class="yvo-tpl-grid">
            <?php foreach ($catalog as $i => $item) :
                $tone = isset($item['tone']) ? (string) $item['tone'] : 'white';
                $dl = function_exists('yvo_templates_library_download_url')
                    ? yvo_templates_library_download_url($item)
                    : '#';
                ?>
                <article class="yvo-tpl-card card3d yvo-tpl-card--<?php echo esc_attr($tone); ?>" style="--tpl-i:<?php echo (int) $i; ?>">
                    <div class="yvo-tpl-card__top">
                        <?php yvo_tpl_card_icon($tone); ?>
                        <?php if (!empty($item['badge'])) : ?>
                            <span class="yvo-tpl-card__badge"><?php echo esc_html((string) $item['badge']); ?></span>
                        <?php endif; ?>
                    </div>
                    <h2 class="yvo-tpl-card__title"><?php echo esc_html((string) $item['title']); ?></h2>
                    <p class="yvo-tpl-card__desc"><?php echo esc_html((string) $item['desc']); ?></p>
                    <?php if (!empty($item['keywords']) && is_array($item['keywords'])) : ?>
                        <ul class="yvo-tpl-card__tags">
                            <?php foreach ($item['keywords'] as $kw) : ?>
                                <li><?php echo esc_html((string) $kw); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="yvo-tpl-card__foot">
                        <a class="yvo-tpl-btn yvo-tpl-btn--download" href="<?php echo esc_url($dl); ?>">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %s: file format */
                                    __('Скачать %s', 'yandex-vision-ocr-pro'),
                                    isset($item['format']) ? (string) $item['format'] : 'DOC'
                                )
                            );
                            ?>
                        </a>
                        <a class="yvo-tpl-card__link" href="<?php echo esc_url($contracts_url); ?>">
                            <?php esc_html_e('Собрать онлайн →', 'yandex-vision-ocr-pro'); ?>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <section class="yvo-tpl-cta card3d">
            <div class="yvo-tpl-cta__text">
                <h2 class="yvo-tpl-cta__title"><?php esc_html_e('Нужен заполненный договор?', 'yandex-vision-ocr-pro'); ?></h2>
                <p class="yvo-tpl-cta__lead">
                    <?php esc_html_e('Загрузите паспорт или выписку ЕГРН — АРР подставит данные и сформирует DOCX под тип сделки.', 'yandex-vision-ocr-pro'); ?>
                </p>
            </div>
            <div class="yvo-tpl-cta__actions">
                <a class="yvo-tpl-btn yvo-tpl-btn--primary" href="<?php echo esc_url($contracts_url); ?>">
                    <?php esc_html_e('Перейти к генерации', 'yandex-vision-ocr-pro'); ?>
                </a>
                <a class="yvo-tpl-btn yvo-tpl-btn--ghost" href="<?php echo esc_url($pricing_url); ?>">
                    <?php esc_html_e('Тарифы', 'yandex-vision-ocr-pro'); ?>
                </a>
            </div>
        </section>

        <section class="yvo-tpl-seo">
            <h2><?php echo esc_html(sprintf(__('Какие шаблоны договоров можно скачать бесплатно в %d году', 'yandex-vision-ocr-pro'), $year)); ?></h2>
            <p>
                <?php esc_html_e('На этой странице собраны самые востребованные простые бланки: договор купли-продажи квартиры, предварительный договор, задаток, дарение, найм жилого помещения, акт приёма-передачи, расписка и договор купли-продажи земельного участка. Файлы открываются в Microsoft Word и аналогичных редакторах.', 'yandex-vision-ocr-pro'); ?>
            </p>
            <p>
                <?php esc_html_e('Если нужна не пустая форма, а документ с вашими реквизитами — используйте онлайн-генератор АРР. Это удобнее ручного заполнения и снижает риск пропустить обязательные поля для регистрации в Росреестре.', 'yandex-vision-ocr-pro'); ?>
            </p>
        </section>
    </div>
</div>
