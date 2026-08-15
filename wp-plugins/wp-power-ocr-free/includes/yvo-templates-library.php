<?php
/**
 * SEO-библиотека простых шаблонов договоров (скачать бланк).
 * Аналог подхода Циан / AllContract: хаб + DOCX/DOC, CTA в генератор.
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Каталог публичных бланков для скачивания.
 *
 * @return array<int, array<string, mixed>>
 */
function yvo_templates_library_catalog() {
    $year = (int) gmdate('Y');
    $items = array(
        array(
            'slug'     => 'dogovor-kupli-prodazhi-kvartiry',
            'title'    => 'Договор купли-продажи квартиры',
            'seo'      => sprintf('Договор купли-продажи квартиры — скачать образец %d', $year),
            'desc'     => 'Полный рабочий ДКП: стороны, ЕГРН-данные объекта, цена, расчёты, передача, гарантии и регистрация.',
            'category' => 'sale',
            'badge'    => 'Популярный',
            'tone'     => 'featured',
            'file'     => 'dogovor-kupli-prodazhi-kvartiry.html',
            'format'   => 'DOC',
            'keywords' => array('ДКП', 'квартира', 'Росреестр'),
        ),
        array(
            'slug'     => 'predvaritelnyy-dogovor-kupli-prodazhi',
            'title'    => 'Предварительный договор купли-продажи',
            'seo'      => sprintf('Предварительный договор купли-продажи — образец %d', $year),
            'desc'     => 'Обязательство заключить основной договор, цена, задаток/аванс и последствия уклонения (ст. 380–381 ГК РФ).',
            'category' => 'sale',
            'badge'    => 'Бланк',
            'tone'     => 'white',
            'file'     => 'predvaritelnyy-dogovor-kupli-prodazhi.html',
            'format'   => 'DOC',
            'keywords' => array('предварительный', 'сделка'),
        ),
        array(
            'slug'     => 'dogovor-zadatka',
            'title'    => 'Договор задатка',
            'seo'      => sprintf('Договор задатка при покупке квартиры — скачать %d', $year),
            'desc'     => 'Задаток в счёт будущей купли-продажи: сумма, срок основной сделки, двойной возврат при вине продавца.',
            'category' => 'deposit',
            'badge'    => 'Задаток',
            'tone'     => 'orange',
            'file'     => 'dogovor-zadatka.html',
            'format'   => 'DOC',
            'keywords' => array('задаток', 'обеспечение'),
        ),
        array(
            'slug'     => 'dogovor-dareniya-kvartiry',
            'title'    => 'Договор дарения квартиры',
            'seo'      => sprintf('Договор дарения квартиры — бланк %d', $year),
            'desc'     => 'Безвозмездная передача квартиры: объект по ЕГРН, обременения, акт, регистрация перехода права.',
            'category' => 'gift',
            'badge'    => 'Дарение',
            'tone'     => 'white',
            'file'     => 'dogovor-dareniya-kvartiry.html',
            'format'   => 'DOC',
            'keywords' => array('дарение', 'квартира'),
        ),
        array(
            'slug'     => 'dogovor-najma-zhilogo-pomeshcheniya',
            'title'    => 'Договор найма жилого помещения',
            'seo'      => sprintf('Договор найма квартиры — скачать образец %d', $year),
            'desc'     => 'Найм квартиры: срок, плата, коммуналка, обеспечительный платёж, права сторон и акт передачи.',
            'category' => 'rent',
            'badge'    => 'Аренда',
            'tone'     => 'blue',
            'file'     => 'dogovor-najma-zhilogo-pomeshcheniya.html',
            'format'   => 'DOC',
            'keywords' => array('найм', 'аренда'),
        ),
        array(
            'slug'     => 'akt-priema-peredachi-kvartiry',
            'title'    => 'Акт приёма-передачи квартиры',
            'seo'      => sprintf('Акт приёма-передачи квартиры — бланк %d', $year),
            'desc'     => 'Приложение к ДКП или найму: состояние, счётчики, ключи, опись и отсутствие/наличие претензий.',
            'category' => 'act',
            'badge'    => 'Акт',
            'tone'     => 'white',
            'file'     => 'akt-priema-peredachi-kvartiry.html',
            'format'   => 'DOC',
            'keywords' => array('акт', 'передача'),
        ),
        array(
            'slug'     => 'raspiska-v-poluchenii-deneg',
            'title'    => 'Расписка в получении денежных средств',
            'seo'      => sprintf('Расписка в получении денег — образец %d', $year),
            'desc'     => 'Подтверждение получения денег: паспортные данные, сумма прописью, основание платежа, свидетели.',
            'category' => 'receipt',
            'badge'    => 'Расписка',
            'tone'     => 'white',
            'file'     => 'raspiska-v-poluchenii-deneg.html',
            'format'   => 'DOC',
            'keywords' => array('расписка', 'оплата'),
        ),
        array(
            'slug'     => 'dogovor-kupli-prodazhi-zemelnogo-uchastka',
            'title'    => 'Договор купли-продажи земельного участка',
            'seo'      => sprintf('Договор купли-продажи земельного участка — бланк %d', $year),
            'desc'     => 'ДКП участка: кадастр, категория, ВРИ, цена, постройки на участке, передача и регистрация в ЕГРН.',
            'category' => 'sale',
            'badge'    => 'Земля',
            'tone'     => 'blue',
            'file'     => 'dogovor-kupli-prodazhi-zemelnogo-uchastka.html',
            'format'   => 'DOC',
            'keywords' => array('участок', 'земля'),
        ),
    );

    return apply_filters('yvo_templates_library_catalog', $items);
}

/**
 * @param string $slug
 * @return array<string, mixed>|null
 */
function yvo_templates_library_get($slug) {
    $slug = sanitize_title($slug);
    foreach (yvo_templates_library_catalog() as $item) {
        if (!empty($item['slug']) && $item['slug'] === $slug) {
            return $item;
        }
    }
    return null;
}

/**
 * @return string
 */
function yvo_templates_library_dir() {
    return trailingslashit(YVO_PLUGIN_DIR) . 'templates/seo-blanks/';
}

/**
 * @param array<string, mixed> $item
 * @return string
 */
function yvo_templates_library_download_url($item) {
    if (empty($item['slug'])) {
        return '#';
    }
    return add_query_arg(
        array(
            'yvo_tpl_dl' => $item['slug'],
        ),
        home_url('/')
    );
}

/**
 * URL страницы библиотеки.
 *
 * @return string
 */
function yvo_cabinet_get_templates_url() {
    $url = trim((string) get_option('yvo_cabinet_templates_page', ''));
    if ($url !== '') {
        return $url;
    }
    $pretty = home_url('/shablony/');
    if (get_option('permalink_structure')) {
        return $pretty;
    }
    return function_exists('yvo_cabinet_route_url')
        ? yvo_cabinet_route_url('templates')
        : add_query_arg('yvo_cabinet', 'templates', home_url('/'));
}

/**
 * Скачивание бланка как Word .doc (HTML).
 */
function yvo_templates_library_handle_download() {
    if (!isset($_GET['yvo_tpl_dl'])) {
        return;
    }
    $slug = sanitize_title(wp_unslash($_GET['yvo_tpl_dl']));
    $item = yvo_templates_library_get($slug);
    if (!$item || empty($item['file'])) {
        status_header(404);
        nocache_headers();
        wp_die(esc_html__('Шаблон не найден.', 'yandex-vision-ocr-pro'), 404);
    }

    $path = yvo_templates_library_dir() . basename((string) $item['file']);
    if (!is_file($path)) {
        status_header(404);
        nocache_headers();
        wp_die(esc_html__('Файл шаблона отсутствует.', 'yandex-vision-ocr-pro'), 404);
    }

    $html = file_get_contents($path);
    if ($html === false || $html === '') {
        status_header(500);
        wp_die(esc_html__('Не удалось прочитать шаблон.', 'yandex-vision-ocr-pro'), 500);
    }

    $filename = $slug . '.doc';
    nocache_headers();
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    // Word лучше открывает HTML с BOM.
    echo "\xEF\xBB\xBF";
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw template HTML for Word download
    echo $html;
    exit;
}
add_action('template_redirect', 'yvo_templates_library_handle_download', -5);

/**
 * Pretty URL /shablony/ → yvo_cabinet=templates.
 */
function yvo_templates_library_rewrite() {
    add_rewrite_rule('^shablony/?$', 'index.php?yvo_cabinet=templates', 'top');
}
add_action('init', 'yvo_templates_library_rewrite', 20);

/**
 * Flush rewrite once after adding /shablony/.
 */
function yvo_templates_library_maybe_flush_rewrites() {
    $flag = 'yvo_templates_library_rewrite_v1';
    if (get_option($flag)) {
        return;
    }
    flush_rewrite_rules(false);
    update_option($flag, 1, false);
}
add_action('init', 'yvo_templates_library_maybe_flush_rewrites', 99);

/**
 * SEO title / description for templates hub.
 */
function yvo_templates_library_document_title($parts) {
    $route = yvo_templates_library_current_route();
    if ($route !== 'templates') {
        return $parts;
    }
    $year = (int) gmdate('Y');
    $parts['title'] = sprintf(
        /* translators: %d: year */
        __('Шаблоны договоров — скачать бесплатно %d', 'yandex-vision-ocr-pro'),
        $year
    );
    $parts['site'] = 'АРР';
    return $parts;
}
add_filter('document_title_parts', 'yvo_templates_library_document_title');

/**
 * @return string
 */
function yvo_templates_library_current_route() {
    if (isset($_GET['yvo_cabinet'])) {
        return sanitize_key(wp_unslash($_GET['yvo_cabinet']));
    }
    $qv = get_query_var('yvo_cabinet');
    return $qv ? sanitize_key((string) $qv) : '';
}

function yvo_templates_library_meta_tags() {
    if (yvo_templates_library_current_route() !== 'templates') {
        return;
    }
    $year = (int) gmdate('Y');
    $desc = sprintf(
        'Бесплатные простые шаблоны договоров %d: купля-продажа квартиры, задаток, дарение, найм, акт и расписка. Скачайте бланк Word или заполните договор онлайн в АРР.',
        $year
    );
    echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
    echo '<meta name="robots" content="index,follow">' . "\n";
    $url = yvo_cabinet_get_templates_url();
    echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
}
add_action('wp_head', 'yvo_templates_library_meta_tags', 4);

/**
 * Assets for templates page.
 */
function yvo_cabinet_enqueue_templates_assets() {
    if (!defined('YVO_PLUGIN_DIR') || !defined('YVO_PLUGIN_URL')) {
        return;
    }
    $css_path = YVO_PLUGIN_DIR . 'css/templates-library.css';
    $js_path = YVO_PLUGIN_DIR . 'js/pricing-cards.js';
    $css_ver = YVO_VERSION . (is_file($css_path) ? '.' . filemtime($css_path) : '');
    $js_ver = YVO_VERSION . (is_file($js_path) ? '.' . filemtime($js_path) : '');

    if (function_exists('yvo_cabinet_enqueue_topbar_assets')) {
        yvo_cabinet_enqueue_topbar_assets();
    }
    if (function_exists('yvo_cabinet_auth_css_version')) {
        wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), yvo_cabinet_auth_css_version());
    }
    wp_enqueue_style('yvo-templates-library', YVO_PLUGIN_URL . 'css/templates-library.css', array('yvo-cabinet-auth'), $css_ver);
    if (is_file($js_path)) {
        wp_enqueue_script('yvo-pricing-cards', YVO_PLUGIN_URL . 'js/pricing-cards.js', array(), $js_ver, true);
    }
}

/**
 * Shortcode [yvo_templates]
 */
function yvo_cabinet_shortcode_templates($atts) {
    yvo_cabinet_enqueue_templates_assets();
    ob_start();
    include YVO_PLUGIN_DIR . 'views/templates-library.php';
    return ob_get_clean();
}
add_shortcode('yvo_templates', 'yvo_cabinet_shortcode_templates');
