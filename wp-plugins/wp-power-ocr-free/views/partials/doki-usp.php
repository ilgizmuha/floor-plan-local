<?php
/**
 * УТП главной: боли → преимущества (bento / editorial layout).
 * Текст и цвета — прежние; кнопки (.doki-usp__btn) не менять стилями здесь.
 */
if (!defined('ABSPATH')) {
    exit;
}

$contracts_url = function_exists('yvo_cabinet_get_contracts_url') ? yvo_cabinet_get_contracts_url() : home_url('/');
$templates_url = function_exists('yvo_cabinet_get_templates_url') ? yvo_cabinet_get_templates_url() : $contracts_url;
$pricing_url = function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/');
$autofill_url = add_query_arg('yvo_autofill', '1', $contracts_url);

$pains = array(
    array(
        'tone'  => 'fear',
        'n'     => '01',
        'title' => __('Страх ошибки', 'yandex-vision-ocr-pro'),
        'text'  => __('Боитесь пропустить пункт или вписать данные неверно — и сорвать сделку.', 'yandex-vision-ocr-pro'),
    ),
    array(
        'tone'  => 'money',
        'n'     => '02',
        'title' => __('Дорого к юристу', 'yandex-vision-ocr-pro'),
        'text'  => __('15–30 тысяч за типовой договор — слишком, а «голые» бланки из интернета пугают.', 'yandex-vision-ocr-pro'),
    ),
    array(
        'tone'  => 'time',
        'n'     => '03',
        'title' => __('Утомительный ввод', 'yandex-vision-ocr-pro'),
        'text'  => __('Десятки полей с паспорта, ЕГРН и кадастра вручную — долго и легко ошибиться.', 'yandex-vision-ocr-pro'),
    ),
    array(
        'tone'  => 'rush',
        'n'     => '04',
        'title' => __('Нужно уже завтра', 'yandex-vision-ocr-pro'),
        'text'  => __('У юриста очередь на неделю, а подписать договор нужно срочно.', 'yandex-vision-ocr-pro'),
    ),
);

$benefits = array(
    array(
        'tone'  => 'blue',
        'icon'  => 'icon-brain.svg',
        'title' => __('Фото документов — поля заполняются сами', 'yandex-vision-ocr-pro'),
        'text'  => __('Загрузите паспорт или выписку ЕГРН. Сервис считает данные и подставит стороны и объект — без ручного набора сорока полей.', 'yandex-vision-ocr-pro'),
        'stat'  => __('главное', 'yandex-vision-ocr-pro'),
        'span'  => 'wide',
    ),
    array(
        'tone'  => 'orange',
        'icon'  => 'icon-doc-lightning.svg',
        'title' => __('Готовый договор за пару минут', 'yandex-vision-ocr-pro'),
        'text'  => __('Не ждите юриста сутками. Проверили данные — скачали Word. Можно подписывать уже сегодня.', 'yandex-vision-ocr-pro'),
        'stat'  => __('скорость', 'yandex-vision-ocr-pro'),
        'span'  => '',
    ),
    array(
        'tone'  => 'purple',
        'icon'  => 'icon-pen.svg',
        'title' => __('Экономия вместо счёта на 15–30 тысяч', 'yandex-vision-ocr-pro'),
        'text'  => __('Типовой договор от 200 ₽. Платите за генерацию, а не за часы юриста за стандартный документ.', 'yandex-vision-ocr-pro'),
        'stat'  => __('цена', 'yandex-vision-ocr-pro'),
        'span'  => '',
    ),
    array(
        'tone'  => 'dark',
        'icon'  => 'icon-shield.svg',
        'title' => __('Меньше опечаток в реквизитах', 'yandex-vision-ocr-pro'),
        'text'  => __('ФИО, паспорт и кадастр берутся из ваших документов, а не «на глаз». Меньше риска из‑за случайной ошибки.', 'yandex-vision-ocr-pro'),
        'stat'  => __('защита', 'yandex-vision-ocr-pro'),
        'span'  => '',
    ),
    array(
        'tone'  => 'blue',
        'icon'  => 'icon-house.svg',
        'title' => __('Квартира, дом, участок, дарение, аренда', 'yandex-vision-ocr-pro'),
        'text'  => __('Один сервис для разных сделок: купля-продажа, задаток, дарение, найм — без поиска шаблонов по всему интернету.', 'yandex-vision-ocr-pro'),
        'stat'  => __('универсально', 'yandex-vision-ocr-pro'),
        'span'  => '',
    ),
    array(
        'tone'  => 'orange',
        'icon'  => 'icon-folder.svg',
        'title' => __('Файл Word сразу к печати и МФЦ', 'yandex-vision-ocr-pro'),
        'text'  => __('Скачайте DOCX, при необходимости поправьте под свою сделку и передайте сторонам на подпись.', 'yandex-vision-ocr-pro'),
        'stat'  => __('удобно', 'yandex-vision-ocr-pro'),
        'span'  => 'tall',
    ),
);
?>
<section class="doki-usp" aria-labelledby="dokiUspTitle">
    <div class="doki-usp__frame">
        <header class="doki-usp__head doki-usp-reveal">
            <p class="doki-usp__eyebrow"><?php esc_html_e('Умный помощник в сделках с недвижимостью', 'yandex-vision-ocr-pro'); ?></p>
            <h1 id="dokiUspTitle" class="doki-usp__title">
                <?php esc_html_e('Договор купли-продажи за 2 минуты', 'yandex-vision-ocr-pro'); ?>
            </h1>
            <p class="doki-usp__lead">
                <?php esc_html_e('Без юриста и без ручного ввода данных. Загрузите фото документов — сервис сам заполнит договор. Вы только проверите и скачаете Word.', 'yandex-vision-ocr-pro'); ?>
            </p>
            <p class="doki-usp__hook">
                <span class="doki-usp__hook-mark" aria-hidden="true"></span>
                <span class="doki-usp__hook-text"><?php esc_html_e('Сфотографируйте паспорт и выписку ЕГРН — договор заполнится сам.', 'yandex-vision-ocr-pro'); ?></span>
            </p>
            <div class="doki-usp__head-actions">
                <a class="doki-usp__btn doki-usp__btn--primary" href="<?php echo esc_url($autofill_url); ?>">
                    <?php esc_html_e('Загрузить документы', 'yandex-vision-ocr-pro'); ?>
                </a>
                <a class="doki-usp__btn doki-usp__btn--ghost" href="<?php echo esc_url($contracts_url); ?>">
                    <?php esc_html_e('Собрать договор', 'yandex-vision-ocr-pro'); ?>
                </a>
            </div>
        </header>

        <div class="doki-usp__metrics doki-usp-reveal" style="--usp-delay: 0.05s" role="list">
            <div class="doki-usp__metric doki-usp__metric--time" role="listitem">
                <strong class="doki-usp__metric-val">2 мин</strong>
                <span class="doki-usp__metric-label"><?php esc_html_e('до готового файла', 'yandex-vision-ocr-pro'); ?></span>
            </div>
            <div class="doki-usp__metric doki-usp__metric--price" role="listitem">
                <strong class="doki-usp__metric-val">от 200 ₽</strong>
                <span class="doki-usp__metric-label"><?php esc_html_e('вместо 15–30 тыс. юристу', 'yandex-vision-ocr-pro'); ?></span>
            </div>
            <div class="doki-usp__metric doki-usp__metric--auto" role="listitem">
                <strong class="doki-usp__metric-val"><?php esc_html_e('фото → поля', 'yandex-vision-ocr-pro'); ?></strong>
                <span class="doki-usp__metric-label"><?php esc_html_e('без ручного набора', 'yandex-vision-ocr-pro'); ?></span>
            </div>
        </div>

        <div class="doki-usp__pains doki-usp-reveal" style="--usp-delay: 0.08s">
            <div class="doki-usp__pains-head">
                <h2 class="doki-usp__pains-title"><?php esc_html_e('Знакомо?', 'yandex-vision-ocr-pro'); ?></h2>
                <p class="doki-usp__pains-bridge">
                    <?php esc_html_e('АРР как раз для этого: быстрее бланка из интернета и заметно дешевле юриста за типовой договор.', 'yandex-vision-ocr-pro'); ?>
                </p>
            </div>
            <ol class="doki-usp__pains-list">
                <?php foreach ($pains as $pain) : ?>
                    <li class="doki-usp__pain doki-usp__pain--<?php echo esc_attr($pain['tone']); ?>">
                        <span class="doki-usp__pain-n" aria-hidden="true"><?php echo esc_html($pain['n']); ?></span>
                        <div class="doki-usp__pain-body">
                            <h3 class="doki-usp__pain-title"><?php echo esc_html($pain['title']); ?></h3>
                            <p class="doki-usp__pain-text"><?php echo esc_html($pain['text']); ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>

        <div class="doki-usp__benefits-head doki-usp-reveal" style="--usp-delay: 0.1s">
            <h2 class="doki-usp__benefits-title"><?php esc_html_e('Почему это удобнее', 'yandex-vision-ocr-pro'); ?></h2>
            <p class="doki-usp__benefits-lead"><?php esc_html_e('Шесть причин выбрать сервис вместо ручного бланка или очереди к юристу.', 'yandex-vision-ocr-pro'); ?></p>
        </div>

        <div class="doki-usp__bento">
            <?php foreach ($benefits as $i => $item) :
                $icon_html = function_exists('yvo_doki_ui_icon_markup')
                    ? yvo_doki_ui_icon_markup($item['icon'], 'doki-usp__icon-img')
                    : '';
                $span = !empty($item['span']) ? ' doki-usp__tile--' . $item['span'] : '';
                ?>
                <article
                    class="doki-usp__tile card3d doki-usp__tile--<?php echo esc_attr($item['tone']); ?><?php echo esc_attr($span); ?> doki-usp-reveal"
                    style="--usp-delay: <?php echo esc_attr((string) (0.12 + $i * 0.05)); ?>s; --usp-i: <?php echo (int) $i; ?>"
                >
                    <div class="doki-usp__tile-top">
                        <div class="doki-usp__icon" aria-hidden="true"><?php echo $icon_html; ?></div>
                        <span class="doki-usp__stat"><?php echo esc_html($item['stat']); ?></span>
                    </div>
                    <h3 class="doki-usp__tile-title"><?php echo esc_html($item['title']); ?></h3>
                    <p class="doki-usp__tile-text"><?php echo esc_html($item['text']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="doki-usp__cta doki-usp-reveal" style="--usp-delay: 0.48s">
            <div class="doki-usp__cta-text">
                <h2 class="doki-usp__cta-title"><?php esc_html_e('Сделайте договор прямо сейчас', 'yandex-vision-ocr-pro'); ?></h2>
                <p class="doki-usp__cta-lead">
                    <?php esc_html_e('Загрузите фото — получите заполненный договор. Или скачайте простой бланк бесплатно.', 'yandex-vision-ocr-pro'); ?>
                </p>
            </div>
            <div class="doki-usp__cta-actions">
                <a class="doki-usp__btn doki-usp__btn--primary" href="<?php echo esc_url($autofill_url); ?>">
                    <?php esc_html_e('Начать с фото документов', 'yandex-vision-ocr-pro'); ?>
                </a>
                <a class="doki-usp__btn doki-usp__btn--ghost" href="<?php echo esc_url($templates_url); ?>">
                    <?php esc_html_e('Скачать шаблоны', 'yandex-vision-ocr-pro'); ?>
                </a>
                <a class="doki-usp__btn doki-usp__btn--link" href="<?php echo esc_url($pricing_url); ?>">
                    <?php esc_html_e('Смотреть тарифы', 'yandex-vision-ocr-pro'); ?>
                </a>
            </div>
        </div>
    </div>
</section>
