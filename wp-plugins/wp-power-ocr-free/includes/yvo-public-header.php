<?php
/**
 * Единая верхняя панель на всём сайте: для гостей — только Вход и Регистрация.
 * Скрывает перегруженное меню темы (страницы, новостройки и т.д.).
 */
if (!defined('ABSPATH')) {
    exit;
}

add_filter('body_class', 'yvo_public_header_body_class');
function yvo_public_header_body_class($classes) {
    if (is_admin()) {
        return $classes;
    }
    if (isset($_GET['yvo_cabinet']) && sanitize_key(wp_unslash($_GET['yvo_cabinet'])) === 'deals') {
        $classes[] = 'yvo-deal-cabinet-page';
        $classes[] = 'yvo-hide-theme-nav';
        return $classes;
    }
    /* Виртуальная страница договоров: шорткод в контенте нет — иначе не матчятся стили body.yvo-contract-form-doki-page */
    if (isset($_GET['yvo_cabinet']) && sanitize_key(wp_unslash($_GET['yvo_cabinet'])) === 'contracts' && apply_filters('yvo_frontend_use_doki_shell', true)) {
        $classes[] = 'yvo-contract-form-doki-page';
    }
    if (is_singular()) {
        global $post;
        if ($post && yvo_post_has_contract_form_shortcode($post)) {
            $classes[] = 'yvo-contract-form-page';
            if (!apply_filters('yvo_legacy_contract_form', false)) {
                $classes[] = 'yvo-contract-form-doki-page';
            }
        }
        if ($post && function_exists('yvo_post_has_doki_home_shortcode') && yvo_post_has_doki_home_shortcode($post)) {
            $classes[] = 'yvo-doki-home-page';
            if (!apply_filters('yvo_legacy_contract_form', false)) {
                $classes[] = 'yvo-contract-form-doki-page';
            }
        }
    }
    $classes[] = 'yvo-plugin-site-header';
    $classes[] = 'yvo-hide-theme-nav';
    if (defined('YVO_VERSION')) {
        $classes[] = 'yvo-plugin-ver-' . str_replace('.', '-', preg_replace('/[^0-9.]/', '', (string) YVO_VERSION));
    }
    return array_values(array_unique($classes));
}

/**
 * В контенте записи есть шорткод формы договора: [yvo_contract_form] или устаревший [yandex_ocr_form].
 * Учитываются: редактор записи, Gutenberg, Elementor (_elementor_data), Bricks (_bricks).
 *
 * @param WP_Post|null $post Запись.
 * @return bool
 */
function yvo_post_has_contract_form_shortcode($post) {
    if (!$post instanceof WP_Post) {
        return false;
    }
    $pc = isset($post->post_content) ? (string) $post->post_content : '';
    if ($pc !== '') {
        if (function_exists('has_shortcode')) {
            if (has_shortcode($pc, 'yvo_contract_form') || has_shortcode($pc, 'yandex_ocr_form')) {
                return true;
            }
        }
        if (strpos($pc, '[yvo_contract_form') !== false || strpos($pc, '[yandex_ocr_form') !== false) {
            return true;
        }
    }
    $pid = (int) $post->ID;
    if ($pid > 0) {
        $elementor = get_post_meta($pid, '_elementor_data', true);
        if (is_string($elementor) && $elementor !== '' && (strpos($elementor, 'yvo_contract_form') !== false || strpos($elementor, 'yandex_ocr_form') !== false)) {
            return true;
        }
        foreach (array('_bricks', '_bricks_page_content') as $bricks_key) {
            $bricks = get_post_meta($pid, $bricks_key, true);
            if (is_string($bricks) && $bricks !== '' && (strpos($bricks, 'yvo_contract_form') !== false || strpos($bricks, 'yandex_ocr_form') !== false)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Страница с [yvo_contract_form]: убираем дублирующий заголовок темы («Договоры») и лишний отступ.
 */
function yvo_contract_form_should_suppress_theme_title() {
    if (is_admin() || !apply_filters('yvo_suppress_theme_title_on_contract_form_page', true)) {
        return false;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }
    if (!is_singular()) {
        return false;
    }
    $post = get_queried_object();
    if (!$post instanceof WP_Post) {
        return false;
    }
    return yvo_post_has_contract_form_shortcode($post)
        || (function_exists('yvo_post_has_doki_home_shortcode') && yvo_post_has_doki_home_shortcode($post));
}

/**
 * @param string $title   Заголовок.
 * @param int    $post_id ID записи.
 * @return string
 */
function yvo_contract_form_filter_the_title_for_theme($title, $post_id) {
    if (!yvo_contract_form_should_suppress_theme_title()) {
        return $title;
    }
    $qid = (int) get_queried_object_id();
    $pid = (int) $post_id;
    if ($pid === 0) {
        $pid = (int) get_the_ID();
    }
    if ($qid === 0 || $pid !== $qid) {
        return $title;
    }
    return '';
}

/**
 * @param string  $title Заголовок.
 * @param WP_Post $post  Запись.
 * @return string
 */
function yvo_contract_form_filter_single_post_title($title, $post) {
    if (!yvo_contract_form_should_suppress_theme_title() || !$post instanceof WP_Post) {
        return $title;
    }
    if ((int) $post->ID !== (int) get_queried_object_id()) {
        return $title;
    }
    return '';
}

/**
 * @param string               $block_content Разметка.
 * @param array<string, mixed> $block         Блок.
 * @param mixed                $instance      Экземпляр блока (WP_Block), с WP 5.9+ для части хуков.
 * @return string
 */
function yvo_contract_form_render_block_hide_post_title($block_content, $block, $instance = null) {
    if (!is_array($block)) {
        return $block_content;
    }
    $name = isset($block['blockName']) ? (string) $block['blockName'] : '';
    if ($name !== 'core/post-title') {
        return $block_content;
    }
    if (!yvo_contract_form_should_suppress_theme_title()) {
        return $block_content;
    }
    return '';
}

add_filter('the_title', 'yvo_contract_form_filter_the_title_for_theme', 999, 2);
add_filter('single_post_title', 'yvo_contract_form_filter_single_post_title', 999, 2);
add_filter('render_block', 'yvo_contract_form_render_block_hide_post_title', 999, 2);
/** Дубль для FSE: динамический хук ядра для блока post-title (см. render_block_{$name}). */
add_filter('render_block_core/post-title', 'yvo_contract_form_render_block_hide_post_title', 999, 2);

/**
 * Удаляет из контента страницы служебную подсказку («Страница для оформления договоров… Добавьте шорткод…»),
 * если она снова попала в запись (черновик темы, импорт, лишний абзац над шорткодом).
 *
 * @param string $content HTML контента.
 * @return string
 */
function yvo_strip_contract_form_placeholder_notice($content) {
    if (!is_string($content) || $content === '') {
        return $content;
    }
    if (is_admin() || is_feed() || (function_exists('wp_is_json_request') && wp_is_json_request())) {
        return $content;
    }
    if (!function_exists('is_singular') || !is_singular()) {
        return $content;
    }
    $post = get_post();
    if (!$post instanceof WP_Post || !function_exists('yvo_post_has_contract_form_shortcode') || !yvo_post_has_contract_form_shortcode($post)) {
        return $content;
    }
    // Gutenberg: блок paragraph целиком.
    $content = preg_replace(
        '/<!--\s*wp:paragraph[^>]*-->[\s\n]*<p[^>]*>[\s\S]*?(?:Страница\s+для\s+оформления\s+договоров|Добавьте\s+шорткод\s+формы)[\s\S]*?<\/p>[\s\n]*<!--\s*\/wp:paragraph\s*>/iu',
        '',
        $content
    );
    // Один абзац с обеими фразами.
    $content = preg_replace(
        '/<p[^>]*>[\s\S]*?Страница\s+для\s+оформления\s+договоров[\s\S]*?Добавьте\s+шорткод\s+формы[\s\S]*?:?[\s\S]*?<\/p>/iu',
        '',
        $content
    );
    // Два абзаца подряд.
    $content = preg_replace(
        '/<p[^>]*>[\s\S]*?Страница\s+для\s+оформления\s+договоров[\s\S]*?<\/p>\s*(?:<br\s*\/?>\s*)*<p[^>]*>[\s\S]*?Добавьте\s+шорткод\s+формы[\s\S]*?<\/p>/iu',
        '',
        $content
    );
    // Отдельный абзац только с «Добавьте шорткод…» (если первая строка уже удалена).
    $content = preg_replace(
        '/<p[^>]*>[\s\S]*?Добавьте\s+шорткод\s+формы[\s\S]*?<\/p>/iu',
        '',
        $content
    );
    return $content;
}

add_filter('the_content', 'yvo_strip_contract_form_placeholder_notice', 4);

/**
 * Критичные стили (поверх кэша/оптимизаторов темы): отступ body и скрытие заголовка страницы.
 * Селекторы с :has(.yvo-doki-contract-page) работают даже если класс на body не выставился.
 */
function yvo_contract_form_print_hide_theme_title_css() {
    if (is_admin() || !is_singular()) {
        return;
    }
    if (!apply_filters('yvo_suppress_theme_title_on_contract_form_page', true)) {
        return;
    }
    $post = get_queried_object();
    if (!$post instanceof WP_Post || !function_exists('yvo_post_has_contract_form_shortcode')) {
        return;
    }
    $is_doki_shell = yvo_post_has_contract_form_shortcode($post)
        || (function_exists('yvo_post_has_doki_home_shortcode') && yvo_post_has_doki_home_shortcode($post));
    if (!$is_doki_shell) {
        return;
    }
    ?>
<style id="yvo-contract-form-hide-wp-title">
/* YVO: страница формы договора — без заголовка темы и без «пустого» отступа под шапку */
body.yvo-contract-form-page.yvo-plugin-site-header:not(.wp-admin),
body.yvo-contract-form-doki-page.yvo-plugin-site-header:not(.wp-admin){padding-top:0!important;}
body.yvo-contract-form-page.yvo-plugin-site-header.admin-bar:not(.wp-admin),
body.yvo-contract-form-doki-page.yvo-plugin-site-header.admin-bar:not(.wp-admin){padding-top:32px!important;}
@media screen and (max-width:782px){
body.yvo-contract-form-page.yvo-plugin-site-header.admin-bar:not(.wp-admin),
body.yvo-contract-form-doki-page.yvo-plugin-site-header.admin-bar:not(.wp-admin){padding-top:46px!important;}
}
body.yvo-contract-form-page .entry-title:not(.yvo-fp-title),
body.yvo-contract-form-page h1.entry-title:not(.yvo-fp-title),
body.yvo-contract-form-page .wp-block-post-title,
body.yvo-contract-form-page .wp-block-post-title a,
body.yvo-contract-form-page .page-title:not(.yvo-fp-title),
body.yvo-contract-form-page .ast-title-bar .entry-title,
body.yvo-contract-form-page .elementor-widget-theme-post-title,
body.yvo-contract-form-page .elementor-widget-container > h1:first-child,
body.yvo-contract-form-page .entry-title-wrap{display:none!important;}
body.yvo-contract-form-page .entry-header,
body.yvo-contract-form-page header.page-header,
body.yvo-contract-form-page .ast-title-bar{margin:0!important;padding:0!important;border:0!important;min-height:0!important;}
/* Dream Home и др.: шапка темы (#masthead) и полоса с h1 «Договоры» над контентом */
body.yvo-contract-form-page:not(.wp-admin) #masthead,
body.yvo-contract-form-doki-page:not(.wp-admin) #masthead,
body:has(.yvo-doki-contract-page):not(.wp-admin) #masthead{display:none!important;}
body.yvo-contract-form-page:not(.wp-admin) article .entry-header:first-of-type,
body.yvo-contract-form-doki-page:not(.wp-admin) article .entry-header:first-of-type,
body:has(.yvo-doki-contract-page):not(.wp-admin) article .entry-header:first-of-type{display:none!important;}
body.yvo-contract-form-page:not(.wp-admin) .wp-site-blocks > header,
body.yvo-contract-form-doki-page:not(.wp-admin) .wp-site-blocks > header,
body:has(.yvo-doki-contract-page):not(.wp-admin) .wp-site-blocks > header{display:none!important;}
body.yvo-contract-form-page:not(.wp-admin) #Tab-NaviagtionBX,
body.yvo-contract-form-doki-page:not(.wp-admin) #Tab-NaviagtionBX,
body:has(.yvo-doki-contract-page):not(.wp-admin) #Tab-NaviagtionBX{padding-top:0!important;}
body.yvo-contract-form-page:not(.wp-admin) .yvo-cab-contract-head,
body.yvo-contract-form-doki-page:not(.wp-admin) .yvo-cab-contract-head,
body:has(.yvo-doki-contract-page):not(.wp-admin) .yvo-cab-contract-head{margin-top:0!important;}
/*
 * FSE / блоки: первый блок контента — часто только заголовок записи.
 * Не трогаем корень шорткода (.yvo-doki-contract-page / .yvo-frontend-page):
 * :has(h1) иначе скрывает всю главную, т.к. USP содержит свой <h1>.
 */
body:has(.yvo-doki-contract-page) .entry-content > *:first-child:not(.yvo-doki-contract-page):not(.yvo-frontend-page):has(.wp-block-post-title),
body:has(.yvo-doki-contract-page) .entry-content > *:first-child:not(.yvo-doki-contract-page):not(.yvo-frontend-page):has(> h1:not(.yvo-fp-title)),
body:has(.yvo-doki-contract-page) #primary > *:first-child:not(.yvo-doki-contract-page):not(.yvo-frontend-page):has(.wp-block-post-title),
body:has(.yvo-doki-contract-page) .site-main > *:first-child:not(.yvo-doki-contract-page):not(.yvo-frontend-page):has(.wp-block-post-title),
body:has(.yvo-doki-contract-page) main > *:first-child:not(.yvo-doki-contract-page):not(.yvo-frontend-page):has(.wp-block-post-title){display:none!important;margin:0!important;padding:0!important;border:0!important;}
body:has(.yvo-doki-contract-page) .entry-content > .alignwide:first-child:not(.yvo-doki-contract-page):has(> h1.wp-block-post-title),
body:has(.yvo-doki-contract-page) .entry-content > .wp-block-group:first-child:has(.wp-block-post-title){display:none!important;}
body:has(.yvo-frontend-page.yvo-doki-form-skin) .entry-content > *:first-child:not(.yvo-frontend-page):has(.wp-block-post-title){display:none!important;}
/* Legacy: нет обёртки .yvo-doki-contract-page — только .yvo-frontend-page */
body.yvo-contract-form-page:has(.yvo-frontend-page) .entry-content > *:first-child:not(.yvo-frontend-page):has(.wp-block-post-title),
body.yvo-contract-form-page:has(.yvo-frontend-page) .entry-content > *:first-child:not(.yvo-frontend-page):has(> h1:not(.yvo-fp-title)){display:none!important;}
/* Контент записи: без верхнего «пустого» поля перед блоком ДОКИ / формой */
body:has(.yvo-doki-contract-page) .entry-content,
body.yvo-contract-form-doki-page .entry-content,
body.yvo-contract-form-page:has(.yvo-doki-contract-page) .entry-content{margin-top:0!important;padding-top:0!important;}
body:has(.yvo-doki-contract-page) .site-main,
body:has(.yvo-doki-contract-page) #primary,
body:has(.yvo-doki-contract-page) main,
body.yvo-contract-form-doki-page .site-main,
body.yvo-contract-form-doki-page #primary{padding-top:0!important;margin-top:0!important;}
body:has(.yvo-doki-contract-page) .content-area,
body.yvo-contract-form-doki-page .content-area{padding-top:0!important;margin-top:0!important;}
</style>
<?php
}

add_action('wp_head', 'yvo_contract_form_print_hide_theme_title_css', 999);

/**
 * FSE / блоковые темы: не показывать ленту записей («Hello world!») под страницами плагина.
 */
function yvo_hide_theme_blog_loop_css() {
    if (is_admin()) {
        return;
    }
    ?>
<style id="yvo-hide-theme-blog-loop">
body.yvo-hide-theme-nav .wp-block-query,
body.yvo-hide-theme-nav ul.wp-block-post-template,
body.yvo-hide-theme-nav .wp-block-latest-posts,
body:has(.yvo-doki-contract-page) .wp-block-query,
body:has(.yvo-frontend-page) .wp-block-query,
body:has(.yvo-deal-cabinet-embed) .wp-block-query{display:none!important;margin:0!important;padding:0!important;}
body.yvo-hide-theme-nav main .wp-block-post-content ~ .wp-block-query,
body.yvo-hide-theme-nav .entry-content ~ .wp-block-query{display:none!important;}
</style>
    <?php
}

add_action('wp_head', 'yvo_hide_theme_blog_loop_css', 998);

/**
 * Резерв: тема может вывести заголовок без фильтров WP — скрываем в DOM после загрузки.
 */
function yvo_contract_form_print_hide_theme_title_js() {
    if (is_admin() || !is_singular()) {
        return;
    }
    if (!apply_filters('yvo_suppress_theme_title_on_contract_form_page', true)) {
        return;
    }
    $post = get_queried_object();
    if (!$post instanceof WP_Post || !function_exists('yvo_post_has_contract_form_shortcode')) {
        return;
    }
    $is_doki_shell = yvo_post_has_contract_form_shortcode($post)
        || (function_exists('yvo_post_has_doki_home_shortcode') && yvo_post_has_doki_home_shortcode($post));
    if (!$is_doki_shell) {
        return;
    }
    ?>
<script id="yvo-contract-form-hide-wp-title-js">
(function(){
function yvoStripAncestors(form){
  var el=form;
  while(el&&el!==document.body){
    var p=el.parentElement;
    if(!p)break;
    var s=el.previousElementSibling;
    while(s){
      var ps=s.previousElementSibling;
      var txt=(s.textContent||'').replace(/\s+/g,' ').trim();
      var looksTitle=s.querySelector&&s.querySelector('.wp-block-post-title,h1.entry-title,.entry-title,.page-title');
      if(looksTitle||(/^Договоры/i.test(txt)&&txt.length<120)||(s.tagName&&/^H[12]$/.test(s.tagName)&&/Договоры/i.test(txt))){
        s.remove();
      }
      s=ps;
    }
    if(p.classList&&p.classList.contains('entry-content'))break;
    if(p.id==='primary'||p.tagName==='MAIN')break;
    el=p;
  }
}
function yvoHideDupTitle(){
  var form=document.querySelector('.yvo-doki-contract-page')||document.querySelector('div.yvo-frontend-page');
  if(!form)return;
  yvoStripAncestors(form);
  var n=form.previousElementSibling;
  while(n){
    var prev=n.previousElementSibling;
    var t=(n.textContent||'').replace(/\s+/g,' ').trim();
    if((/^Договоры\s*$/.test(t)||/^Договоры\s*[|·\-–]/.test(t))&&t.length<160){
      n.remove();
    }
    n=prev;
  }
  var skip='.yvo-doki-contract-page,.yvo-frontend-page,.yvo-fp-modal,dialog,[role="dialog"]';
  var sels=['.entry-title:not(.yvo-fp-title)','h1.entry-title:not(.yvo-fp-title)','.wp-block-post-title','.wp-block-post-title a','.elementor-widget-theme-post-title','.page-title:not(.yvo-fp-title)','article>header h1','.entry-header h1','.page-header h1','.page-header .entry-title','header.entry-header h1','.content-title','.title-wrapper h1'];
  sels.forEach(function(sel){
    try{
      document.querySelectorAll(sel).forEach(function(el){
        if(el.closest(skip))return;
        if(el.classList&&el.classList.contains('yvo-fp-title'))return;
        el.style.setProperty('display','none','important');
        el.setAttribute('hidden','hidden');
      });
    }catch(e){}
  });
  document.querySelectorAll('.entry-header,header.page-header,.ast-title-bar,.elementor-widget-theme-post-title').forEach(function(wrap){
    if(wrap.querySelector('.yvo-doki-contract-page'))return;
    if(!wrap.querySelector('h1,.entry-title,.wp-block-post-title'))return;
    wrap.style.setProperty('margin','0','important');
    wrap.style.setProperty('padding-top','0','important');
    wrap.style.setProperty('padding-bottom','0','important');
    wrap.style.setProperty('min-height','0','important');
    wrap.style.setProperty('border','none','important');
  });
  var mh=document.getElementById('masthead');
  if(mh && !mh.querySelector('.yvo-doki-contract-page')){mh.style.setProperty('display','none','important');}
  document.querySelectorAll('article .entry-header').forEach(function(h){
    if(h.closest('.yvo-doki-contract-page'))return;
    if(h.querySelector('.yvo-fp-title'))return;
    h.style.setProperty('display','none','important');
  });
}
yvoHideDupTitle();
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',yvoHideDupTitle);
setTimeout(yvoHideDupTitle,50);
setTimeout(yvoHideDupTitle,250);
setTimeout(yvoHideDupTitle,800);
})();
</script>
<?php
}

add_action('wp_footer', 'yvo_contract_form_print_hide_theme_title_js', 9999);

add_action('wp_enqueue_scripts', 'yvo_public_header_enqueue', 8);
function yvo_public_header_enqueue() {
    if (is_admin()) {
        return;
    }
    if (isset($_GET['yvo_cabinet']) && sanitize_key(wp_unslash($_GET['yvo_cabinet'])) === 'deals') {
        return;
    }
    $cab_ver = YVO_VERSION;
    $cab_path = YVO_PLUGIN_DIR . 'css/cabinet-auth.css';
    if (is_file($cab_path)) {
        $cab_ver = YVO_VERSION . '.' . filemtime($cab_path);
    }
    wp_enqueue_style(
        'yvo-cabinet-auth',
        YVO_PLUGIN_URL . 'css/cabinet-auth.css',
        array(),
        $cab_ver
    );
    if (function_exists('yvo_cabinet_topbar_mobile_inline_css')) {
        wp_add_inline_style('yvo-cabinet-auth', yvo_cabinet_topbar_mobile_inline_css());
    }
    $js_path = YVO_PLUGIN_DIR . 'js/cabinet-topbar.js';
    if (is_file($js_path)) {
        wp_enqueue_script(
            'yvo-cabinet-topbar',
            YVO_PLUGIN_URL . 'js/cabinet-topbar.js',
            array(),
            YVO_VERSION . '.' . filemtime($js_path),
            true
        );
    }
    $ver = YVO_VERSION;
    $css_path = YVO_PLUGIN_DIR . 'css/public-header.css';
    if (is_file($css_path)) {
        $ver = YVO_VERSION . '.' . filemtime($css_path);
    }
    wp_enqueue_style(
        'yvo-public-header',
        YVO_PLUGIN_URL . 'css/public-header.css',
        array('yvo-cabinet-auth'),
        $ver
    );
}

/**
 * Страница с [yvo_contract_form] и оболочкой ДОКИ: шапка выводится в views/partials/doki-contract-hero.php,
 * дублировать yvo_cab_topbar в wp_body_open не нужно.
 *
 * @return bool
 */
function yvo_contract_page_uses_doki_hero_header() {
    if (apply_filters('yvo_legacy_contract_form', false)) {
        return false;
    }
    if (!function_exists('is_singular') || !is_singular()) {
        return false;
    }
    $post = get_post();
    if (!$post) {
        return false;
    }
    if (function_exists('yvo_post_has_doki_home_shortcode') && yvo_post_has_doki_home_shortcode($post)) {
        return false;
    }
    if (function_exists('yvo_post_has_contract_form_shortcode') && yvo_post_has_contract_form_shortcode($post)) {
        return apply_filters('yvo_frontend_use_doki_shell', true);
    }
    return false;
}

/**
 * Активный пункт верхней панели для авторизованных.
 *
 * @return string contracts|account|profile|wallet
 */
function yvo_public_header_current_tab() {
    if (!is_user_logged_in()) {
        return '';
    }
    if (isset($_GET['yvo_cabinet'])) {
        $r = sanitize_key(wp_unslash($_GET['yvo_cabinet']));
        if ($r === 'wallet') {
            return 'profile';
        }
        if (in_array($r, array('account', 'profile', 'contracts', 'pricing', 'deals', 'home', 'property_check'), true)) {
            return $r;
        }
    }
    if (is_singular()) {
        $post = get_post();
        if ($post && !empty($post->post_content)) {
            if (has_shortcode($post->post_content, 'yvo_cabinet_account')) {
                return 'account';
            }
            if (has_shortcode($post->post_content, 'yvo_cabinet_profile')) {
                return 'profile';
            }
            if (has_shortcode($post->post_content, 'yvo_cabinet_wallet')) {
                return 'profile';
            }
            if (has_shortcode($post->post_content, 'yvo_pricing')) {
                return 'pricing';
            }
            if (has_shortcode($post->post_content, 'yvo_deal_cabinet')) {
                return 'deals';
            }
            if (has_shortcode($post->post_content, 'yvo_property_check')) {
                return 'property_check';
            }
            if (yvo_post_has_contract_form_shortcode($post)) {
                return 'contracts';
            }
            if (function_exists('yvo_post_has_doki_home_shortcode') && yvo_post_has_doki_home_shortcode($post)) {
                return 'home';
            }
        }
    }
    return 'contracts';
}

add_action('wp_body_open', 'yvo_public_header_maybe_print', 2);
add_action('wp_footer', 'yvo_public_header_maybe_print', 1);

function yvo_public_header_maybe_print() {
    static $printed = false;
    if ($printed || is_admin()) {
        return;
    }
    if (function_exists('yvo_cabinet_is_virtual_route') && yvo_cabinet_is_virtual_route()) {
        $printed = true;
        return;
    }
    if (isset($_GET['yvo_cabinet']) && sanitize_key(wp_unslash($_GET['yvo_cabinet'])) === 'deals') {
        $printed = true;
        return;
    }
    $printed = true;
    yvo_public_header_render();
}

function yvo_public_header_render() {
    if (!function_exists('yvo_cabinet_get_login_url')) {
        return;
    }
    if (!wp_style_is('yvo-cabinet-auth', 'enqueued')) {
        $cab_ver = YVO_VERSION;
        $cab_path = YVO_PLUGIN_DIR . 'css/cabinet-auth.css';
        if (is_file($cab_path)) {
            $cab_ver = YVO_VERSION . '.' . filemtime($cab_path);
        }
        wp_enqueue_style('yvo-cabinet-auth', YVO_PLUGIN_URL . 'css/cabinet-auth.css', array(), $cab_ver);
    }
    if (function_exists('yvo_cabinet_topbar_mobile_inline_css')) {
        wp_add_inline_style('yvo-cabinet-auth', yvo_cabinet_topbar_mobile_inline_css());
    }
    if (!wp_script_is('yvo-cabinet-topbar', 'enqueued')) {
        $js_path = YVO_PLUGIN_DIR . 'js/cabinet-topbar.js';
        if (is_file($js_path)) {
            wp_enqueue_script(
                'yvo-cabinet-topbar',
                YVO_PLUGIN_URL . 'js/cabinet-topbar.js',
                array(),
                YVO_VERSION . '.' . filemtime($js_path),
                true
            );
        }
    }
    if (function_exists('yvo_contract_page_uses_doki_hero_header') && yvo_contract_page_uses_doki_hero_header()) {
        return;
    }
    if (is_user_logged_in()) {
        $tab = yvo_public_header_current_tab();
        echo yvo_cabinet_render_topbar($tab);
        yvo_cabinet_print_topbar_wallet_strip_script();
        return;
    }
    $login = yvo_cabinet_get_login_url();
    $reg = yvo_cabinet_get_register_url();
    $home = function_exists('yvo_cabinet_get_home_url') ? yvo_cabinet_get_home_url() : home_url('/');
    $pricing = function_exists('yvo_cabinet_get_pricing_url') ? yvo_cabinet_get_pricing_url() : home_url('/');
    $property_check = function_exists('yvo_cabinet_get_property_check_url') ? yvo_cabinet_get_property_check_url() : '';
    $yvo_yandex_guest_url = '';
    if (function_exists('yvo_yandex_oauth_start_url') && function_exists('yvo_cabinet_get_contracts_url')) {
        $yvo_yandex_guest_url = yvo_yandex_oauth_start_url(yvo_cabinet_get_contracts_url());
    }
    $yvo_oauth_error = get_transient('yvo_login_oauth_error') ? (string) get_transient('yvo_login_oauth_error') : '';
    if ($yvo_oauth_error !== '') {
        delete_transient('yvo_login_oauth_error');
    }
    include YVO_PLUGIN_DIR . 'views/cabinet-guest-bar.php';
}

/**
 * Скрипт: убрать пункт «Кошелёк» из панели плагина (на случай старого кэша PHP/HTML).
 */
function yvo_cabinet_print_topbar_wallet_strip_script() {
    ?>
<script>
(function(){function s(){document.querySelectorAll('.yvo-cab-topbar-nav a,.yvo-cab-topbar a[href*="yvo_cabinet=wallet"],.yvo-cab-topbar a[href*="yvo_cabinet%3Dwallet"]').forEach(function(a){if(a.closest&&a.closest(".yvo-cab-topbar-dropdown"))return;var h=a.getAttribute("href")||"",t=(a.textContent||"").replace(/\s+/g," ").trim();if(t==="Кошелёк"||/yvo_cabinet=wallet|yvo_cabinet%3Dwallet/i.test(h))a.remove();});}s();if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",s);setTimeout(s,50);})();
</script>
<?php
}

/**
 * Дублируем в подвале — если тема не выводит блок сразу после wp_body_open.
 */
function yvo_cabinet_topbar_strip_wallet_link_script() {
    if (is_admin() || !is_user_logged_in()) {
        return;
    }
    yvo_cabinet_print_topbar_wallet_strip_script();
}

add_action('wp_footer', 'yvo_cabinet_topbar_strip_wallet_link_script', 999);

/**
 * Для администраторов: в конце HTML — комментарий с версией и путём (Просмотр кода страницы).
 */
add_action('wp_footer', 'yvo_public_footer_admin_source_comment', 99999);
function yvo_public_footer_admin_source_comment() {
    if (is_admin() || !is_user_logged_in() || !current_user_can('manage_options')) {
        return;
    }
    if (!defined('YVO_VERSION') || !defined('YVO_PLUGIN_DIR')) {
        return;
    }
    echo "\n<!-- YVO " . esc_html(YVO_VERSION) . ' path=' . esc_html(str_replace('\\', '/', YVO_PLUGIN_DIR)) . " -->\n";
}

/**
 * Пункт «Кошелёк» в меню темы (wp_nav_menu) — скрываем.
 *
 * @param WP_Post[] $items Элементы меню.
 * @param object    $args  Аргументы.
 * @return WP_Post[]
 */
function yvo_cabinet_nav_menu_remove_wallet($items, $args) {
    if (empty($items) || !is_array($items)) {
        return $items;
    }
    $out = array();
    foreach ($items as $key => $item) {
        if (!is_object($item)) {
            $out[$key] = $item;
            continue;
        }
        $title = isset($item->title) ? wp_strip_all_tags($item->title) : '';
        $url = isset($item->url) ? (string) $item->url : '';
        if ($title === 'Кошелёк') {
            continue;
        }
        if ($url !== '' && (strpos($url, 'yvo_cabinet=wallet') !== false || strpos($url, 'yvo_cabinet%3Dwallet') !== false)) {
            continue;
        }
        $out[$key] = $item;
    }
    return $out;
}

add_filter('wp_nav_menu_objects', 'yvo_cabinet_nav_menu_remove_wallet', 20, 2);

/**
 * Подпись в меню (если в БД ещё «Кошелёк») — показываем актуальное название.
 *
 * @param string   $title     Заголовок пункта.
 * @param WP_Post  $menu_item Объект пункта.
 * @param stdClass $args      Аргументы wp_nav_menu.
 * @param int      $depth     Уровень вложенности.
 */
function yvo_cabinet_nav_menu_item_title_rename_wallet($title, $menu_item, $args, $depth) {
    $t = wp_strip_all_tags((string) $title);
    if ($t === 'Кошелёк') {
        return 'Профиль и баланс';
    }
    return $title;
}

add_filter('nav_menu_item_title', 'yvo_cabinet_nav_menu_item_title_rename_wallet', 10, 4);

/**
 * Блок навигации Gutenberg: ссылка «Кошелёк» на кошелёк.
 *
 * @param string               $block_content Разметка блока.
 * @param array<string, mixed> $block         Парсинг блока.
 * @return string
 */
function yvo_cabinet_render_block_hide_wallet_link($block_content, $block) {
    if (!is_array($block)) {
        return $block_content;
    }
    $name = isset($block['blockName']) ? (string) $block['blockName'] : '';
    if ($name !== 'core/navigation-link') {
        return $block_content;
    }
    $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
    $label = isset($attrs['label']) ? wp_strip_all_tags((string) $attrs['label']) : '';
    $url = isset($attrs['url']) ? (string) $attrs['url'] : '';
    if ($label === 'Кошелёк') {
        return '';
    }
    if ($url !== '' && (strpos($url, 'yvo_cabinet=wallet') !== false || strpos($url, 'yvo_cabinet%3Dwallet') !== false)) {
        return '';
    }
    return $block_content;
}

add_filter('render_block', 'yvo_cabinet_render_block_hide_wallet_link', 10, 2);

/**
 * Стили: скрыть ссылку на wallet в шапке плагина (пока JS не сработал).
 */
function yvo_cabinet_topbar_wallet_hide_css() {
    if (is_admin() || !is_user_logged_in()) {
        return;
    }
    echo '<style id="yvo-hide-wallet-in-plugin-topbar">.yvo-cab-topbar-nav a[href*="yvo_cabinet=wallet"],.yvo-cab-topbar-nav a[href*="yvo_cabinet%3Dwallet"]{display:none!important}</style>';
}

add_action('wp_head', 'yvo_cabinet_topbar_wallet_hide_css', 99);

/**
 * Удаляет из DOM любые ссылки «Кошелёк» / на wallet (тема, блоки FSE, кэш, старый HTML).
 */
function yvo_cabinet_global_wallet_link_remover_script() {
    if (is_admin()) {
        return;
    }
    ?>
<script>
(function(){
function yvoRmWalletA(a){
var x=(a.textContent||'').replace(/\s+/g,' ').trim();
var h=a.getAttribute('href');
if(h==null||h===''){h='';}
if(x==='Кошелёк')return true;
if(/yvo_cabinet=wallet|yvo_cabinet%3Dwallet/i.test(h))return true;
try{var u=new URL(h,window.location.href);if(/\/wallet\/?$/.test(u.pathname))return true;}catch(e){}
return false;
}
var tm;
function kill(){document.querySelectorAll('a').forEach(function(a){if(yvoRmWalletA(a))a.remove();});}
function sch(){clearTimeout(tm);tm=setTimeout(kill,90);}
kill();
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',kill);
setTimeout(kill,250);
setTimeout(kill,800);
if(window.MutationObserver){new MutationObserver(sch).observe(document.documentElement,{childList:true,subtree:true});}
})();
</script>
<?php
}

add_action('wp_head', 'yvo_cabinet_global_wallet_link_remover_script', 5);

/**
 * Вырезает из HTML фрагменты с заголовком страницы («Договоры») до блока формы — обход тем/кэша без фильтров WP.
 *
 * @param string $buffer Полный HTML.
 * @return string
 */
function yvo_contract_form_ob_strip_theme_title_html($buffer) {
    if (!is_string($buffer) || $buffer === '' || stripos($buffer, '<html') === false) {
        return $buffer;
    }
    if (!apply_filters('yvo_contract_form_ob_strip_theme_title', true, $buffer)) {
        return $buffer;
    }
    if (stripos($buffer, 'yvo-contract-form-page') === false && stripos($buffer, 'yvo-doki-contract-page') === false) {
        return $buffer;
    }
    /*
     * Нельзя искать «yvo-doki-contract-page» по всему HTML: в <head> в инлайн-CSS (doki-shell.css)
     * эта строка встречается раньше, чем разметка формы — $before обрезался бы у обрыва <head>.
     */
    $head_close = '</head>';
    $head_end = stripos($buffer, $head_close);
    $slice_start = $head_end !== false ? $head_end + strlen($head_close) : 0;
    $slice = substr($buffer, $slice_start);
    $anchors = array(
        '<div class="yvo-doki-contract-page',
        "<div class='yvo-doki-contract-page",
        '<div class="yvo-doki-contract-page ',
    );
    $rel = false;
    foreach ($anchors as $a) {
        $p = stripos($slice, $a);
        if ($p !== false && ($rel === false || $p < $rel)) {
            $rel = $p;
        }
    }
    if ($rel === false) {
        $rel = stripos($slice, '<div class="yvo-frontend-page');
        if ($rel === false) {
            $rel = stripos($slice, "<div class='yvo-frontend-page");
        }
    }
    if ($rel === false) {
        return $buffer;
    }
    $pos = $slice_start + $rel;
    $before = substr($buffer, 0, $pos);
    $after = substr($buffer, $pos);
    $patterns = array(
        // Заголовок записи: «Договоры» (в т.ч. с вложенными span)
        '#<h1\b[^>]*>[\s\S]*?Договоры[\s\S]*?</h1>#iu',
        '#<h2\b[^>]*>[\s\S]*?Договоры[\s\S]*?</h2>#iu',
        '#<p\b[^>]*>\s*Договоры\s*</p>#iu',
        // Типичные обёртки темы / FSE
        '#<header\b[^>]*\bentry-header\b[^>]*>[\s\S]*?</header>#iu',
        '#<header\b[^>]*\bpage-header\b[^>]*>[\s\S]*?</header>#iu',
        '#<div\b[^>]*\bwp-block-post-title\b[^>]*>[\s\S]*?</div>#iu',
    );
    foreach ($patterns as $re) {
        $before = preg_replace($re, '', $before);
    }
    foreach ($patterns as $re) {
        $before = preg_replace($re, '', $before);
    }
    /* Служебный абзац из контента записи (над формой) */
    $before = preg_replace(
        '#<p\b[^>]*>[\s\S]*?Страница\s+для\s+оформления\s+договоров[\s\S]*?Добавьте\s+шорткод\s+формы[\s\S]*?</p>#iu',
        '',
        $before
    );
    $before = preg_replace(
        '#<p\b[^>]*>[\s\S]*?Добавьте\s+шорткод\s+формы[\s\S]*?</p>#iu',
        '',
        $before
    );
    // Повтор: иногда тема дублирует обёртки
    $before = preg_replace('#<header\b[^>]*\bentry-header\b[^>]*>\s*</header>#iu', '', $before);
    $before = preg_replace('#<div\b[^>]*class="[^"]*alignwide[^"]*"[^>]*>\s*</div>#iu', '', $before);
    return $before . $after;
}

/**
 * Фильтр всего HTML страницы: «Кошелёк» в меню + заголовок страницы на экране формы договора.
 *
 * @param string $buffer HTML.
 * @param int    $phase  Фаза буфера PHP.
 * @return string
 */
function yvo_cabinet_ob_filter_html($buffer, $phase = 0) {
    if (!is_string($buffer) || $buffer === '') {
        return $buffer;
    }
    if (strpos($buffer, 'Кошелёк') !== false) {
        $buffer = preg_replace('#<a\b[^>]*yvo-cab-topbar-link[^>]*>\s*Кошелёк\s*</a>#iu', '', $buffer);
        $buffer = preg_replace('#<a\b[^>]*>\s*Кошелёк\s*</a>#iu', '', $buffer);
        $buffer = preg_replace('#<a\b[^>]*href=["\'][^"\']*yvo_cabinet=wallet[^"\']*["\'][^>]*>.*?</a>#ius', '', $buffer);
        $buffer = preg_replace('#<a\b[^>]*href=["\'][^"\']*yvo_cabinet%3Dwallet[^"\']*["\'][^>]*>.*?</a>#ius', '', $buffer);
    }
    $buffer = yvo_contract_form_ob_strip_theme_title_html($buffer);
    return $buffer;
}

/**
 * @return void
 */
function yvo_cabinet_start_output_buffer_for_wallet_strip() {
    if (is_admin()) {
        return;
    }
    if (wp_doing_ajax()) {
        return;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }
    if (function_exists('wp_is_json_request') && wp_is_json_request()) {
        return;
    }
    if (is_feed()) {
        return;
    }
    ob_start('yvo_cabinet_ob_filter_html');
}

add_action('template_redirect', 'yvo_cabinet_start_output_buffer_for_wallet_strip', -1);
