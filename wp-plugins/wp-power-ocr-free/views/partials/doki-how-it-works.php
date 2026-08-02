<?php
/**
 * Инструкция «Как формируется договор» — 3 понятных шага.
 */
if (!defined('ABSPATH')) {
    exit;
}

$steps = array(
    array(
        'num'   => '1',
        'tone'  => 'blue',
        'title' => __('Загрузите фото документов', 'yandex-vision-ocr-pro'),
        'icon'  => 'icon-brain.svg',
        'text'  => __('Паспорт или выписка ЕГРН. Сервис считает ФИО, паспортные данные и сведения об объекте и подставит их в договор.', 'yandex-vision-ocr-pro'),
    ),
    array(
        'num'   => '2',
        'tone'  => 'orange',
        'title' => __('Выберите тип сделки', 'yandex-vision-ocr-pro'),
        'icon'  => 'icon-folder.svg',
        'text'  => __('Купля-продажа, задаток, дарение, найм и другие. Проверьте стороны и объект — при необходимости поправьте поля.', 'yandex-vision-ocr-pro'),
    ),
    array(
        'num'   => '3',
        'tone'  => 'purple',
        'title' => __('Скачайте готовый Word', 'yandex-vision-ocr-pro'),
        'icon'  => 'icon-pen.svg',
        'text'  => __('Файл DOCX готов к печати и правкам. Передайте сторонам на подпись — без очереди к юристу за типовым бланком.', 'yandex-vision-ocr-pro'),
    ),
);
?>
<section class="doki-flow-guide" aria-labelledby="dokiFlowGuideTitle">
    <header class="doki-flow-guide__head">
        <h2 id="dokiFlowGuideTitle" class="doki-flow-guide__title"><?php esc_html_e('Как это работает', 'yandex-vision-ocr-pro'); ?></h2>
        <p class="doki-flow-guide__lead"><?php esc_html_e('Три шага: фото документов → проверка → готовый договор в Word.', 'yandex-vision-ocr-pro'); ?></p>
    </header>

    <ol class="doki-flow-guide__track doki-flow-guide__track--3">
        <?php foreach ($steps as $index => $step) : ?>
            <?php
            $icon_html = function_exists('yvo_doki_ui_icon_markup')
                ? yvo_doki_ui_icon_markup($step['icon'], 'doki-flow-guide__icon-img')
                : '';
            $is_last = ($index === count($steps) - 1);
            ?>
            <li class="doki-flow-guide__item doki-flow-guide__item--<?php echo esc_attr($step['tone']); ?><?php echo $is_last ? ' is-last' : ''; ?>" style="--flow-delay: <?php echo esc_attr((string) ($index * 0.12)); ?>s">
                <article class="doki-flow-guide__card card3d">
                    <span class="doki-flow-guide__accent" aria-hidden="true"></span>
                    <div class="doki-flow-guide__card-head">
                        <span class="doki-flow-guide__num" aria-hidden="true"><?php echo esc_html($step['num']); ?></span>
                        <div class="doki-flow-guide__icon" aria-hidden="true"><?php echo $icon_html; ?></div>
                    </div>
                    <h3 class="doki-flow-guide__item-title"><?php echo esc_html($step['title']); ?></h3>
                    <p class="doki-flow-guide__item-text"><?php echo esc_html($step['text']); ?></p>
                </article>
                <?php if (!$is_last) : ?>
                    <span class="doki-flow-guide__arrow" aria-hidden="true"></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
