<?php
/**
 * Тёмная полоса под шапкой кабинета: поиск и быстрые действия (страница формы договора).
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="yvo-cab-topbar-sub" role="region" aria-label="<?php esc_attr_e('Поиск и быстрые действия', 'yandex-vision-ocr-pro'); ?>">
    <div class="yvo-cab-topbar-sub-inner">
        <form class="yvo-cab-topbar-search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
            <span class="yvo-cab-topbar-search__icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z" stroke="currentColor" stroke-width="2"/><path d="M16.5 16.5L21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </span>
            <label class="yvo-sr-only" for="yvo-cab-topbar-search-input"><?php esc_html_e('Поиск по кабинету', 'yandex-vision-ocr-pro'); ?></label>
            <input type="search" id="yvo-cab-topbar-search-input" name="s" class="yvo-cab-topbar-search__input" placeholder="<?php esc_attr_e('Поиск по кабинету…', 'yandex-vision-ocr-pro'); ?>" autocomplete="off" />
        </form>
        <div class="yvo-cab-topbar-sub-actions">
            <button type="button" class="yvo-cab-topbar-sub-iconbtn" aria-label="<?php esc_attr_e('Чат и поддержка', 'yandex-vision-ocr-pro'); ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 6a2 2 0 012-2h12a2 2 0 012 2v8a2 2 0 01-2 2h-4l-4 3v-3H6a2 2 0 01-2-2V6z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="yvo-cab-topbar-sub-iconbtn" aria-label="<?php esc_attr_e('Уведомления', 'yandex-vision-ocr-pro'); ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M6 10a6 6 0 1112 0c0 5 2 6 2 6H4s2-1 2-6z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/><path d="M10.5 20a2.5 2.5 0 005 0" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
            </button>
            <button type="button" class="yvo-cab-topbar-sub-iconbtn" aria-label="<?php esc_attr_e('Добавить участника', 'yandex-vision-ocr-pro'); ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.75"/><path d="M22 11h-6M19 8v6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
            </button>
        </div>
    </div>
</div>
