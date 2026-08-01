<?php
if (!defined('ABSPATH')) {
    exit;
}
$api_key = get_option('yvo_api_key');
$folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
$is_configured = !empty($api_key) && !empty($folder_id);

$pdf_processor = new YVO_PDF_Processor();
$has_pdf_support = $pdf_processor->check_pdf_support();

$deepseek_enabled = get_option('yvo_deepseek_enabled') === 'yes';
$deepseek_api_key = get_option('yvo_deepseek_api_key');
$deepseek_configured = !empty($deepseek_api_key);

// Количество договоров в папке
$contracts_dir = YVO_PLUGIN_DIR . 'contracts';
$contracts_count = 0;
if (is_dir($contracts_dir)) {
    $files = glob($contracts_dir . '/*.{txt,docx,pdf}', GLOB_BRACE);
    $contracts_count = $files ? count($files) : 0;
}

$seller = get_option('yvo_seller_data', array());
$buyer = get_option('yvo_buyer_data', array());
$property = get_option('yvo_property_data', array());
$data_filled = (!empty($seller) && !empty(array_filter($seller))) || (!empty($buyer) && !empty(array_filter($buyer))) || (!empty($property) && !empty(array_filter($property)));
?>
<div class="wrap yvo-dashboard-wrap">
    <h1>Админ-панель — Яндекс OCR Pro AI</h1>
    
    <div class="yvo-dashboard-cards">
        <div class="yvo-dash-card yvo-dash-status">
            <h2>Статус системы</h2>
            <ul class="yvo-dash-list">
                <li class="<?php echo $is_configured ? 'ok' : 'warn'; ?>">
                    <span class="dashicons dashicons-<?php echo $is_configured ? 'yes-alt' : 'warning'; ?>"></span>
                    Яндекс Vision API: <?php echo $is_configured ? 'настроен' : 'не настроен'; ?>
                </li>
                <li class="<?php echo $has_pdf_support ? 'ok' : 'warn'; ?>">
                    <span class="dashicons dashicons-<?php echo $has_pdf_support ? 'yes-alt' : 'warning'; ?>"></span>
                    Поддержка PDF: <?php echo $has_pdf_support ? 'доступна' : 'недоступна'; ?>
                </li>
                <li class="<?php echo ($deepseek_enabled && $deepseek_configured) ? 'ok' : ($deepseek_enabled ? 'warn' : 'muted'); ?>">
                    <span class="dashicons dashicons-<?php echo ($deepseek_enabled && $deepseek_configured) ? 'yes-alt' : ($deepseek_enabled ? 'warning' : 'minus'); ?>"></span>
                    DeepSeek AI: <?php echo ($deepseek_enabled && $deepseek_configured) ? 'настроен' : ($deepseek_enabled ? 'не настроен' : 'выключен'); ?>
                </li>
            </ul>
        </div>
        
        <div class="yvo-dash-card yvo-dash-stats">
            <h2>Сводка</h2>
            <ul class="yvo-dash-list">
                <li>
                    <span class="yvo-dash-num"><?php echo (int) $contracts_count; ?></span>
                    <span class="yvo-dash-label">договоров создано</span>
                </li>
                <li>
                    <span class="yvo-dash-num"><?php echo $data_filled ? '✓' : '—'; ?></span>
                    <span class="yvo-dash-label">данные сделки <?php echo $data_filled ? 'заполнены' : 'не заполнены'; ?></span>
                </li>
            </ul>
        </div>
    </div>
    
    <div class="yvo-dash-card yvo-dash-actions">
        <h2>Быстрые действия</h2>
        <div class="yvo-dash-buttons">
            <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-recognize'); ?>" class="button button-primary button-hero">
                <span class="dashicons dashicons-media-document" style="margin-top: 4px;"></span>
                Распознать документы
            </a>
            <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-data'); ?>" class="button button-hero">
                <span class="dashicons dashicons-admin-users" style="margin-top: 4px;"></span>
                Данные сделки
            </a>
            <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-contracts'); ?>" class="button button-hero">
                <span class="dashicons dashicons-portfolio" style="margin-top: 4px;"></span>
                История договоров
            </a>
            <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-settings'); ?>" class="button button-hero">
                <span class="dashicons dashicons-admin-generic" style="margin-top: 4px;"></span>
                Настройки
            </a>
        </div>
    </div>
    
    <?php if (!$is_configured): ?>
    <div class="notice notice-warning inline">
        <p>Перед началом работы <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-settings'); ?>">настройте API ключ Яндекс Vision</a>.</p>
    </div>
    <?php endif; ?>
</div>
