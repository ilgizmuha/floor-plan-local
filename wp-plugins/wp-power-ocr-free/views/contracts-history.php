<?php
if (!defined('ABSPATH')) {
    exit;
}
$contracts_dir = YVO_PLUGIN_DIR . 'contracts';
$files = array();
if (is_dir($contracts_dir)) {
    $scan = glob($contracts_dir . '/*');
    if ($scan) {
        foreach ($scan as $path) {
            if (is_file($path)) {
                $files[] = array(
                    'name' => basename($path),
                    'path' => $path,
                    'url'  => YVO_PLUGIN_URL . 'contracts/' . rawurlencode(basename($path)),
                    'size' => filesize($path),
                    'time' => filemtime($path),
                );
            }
        }
        usort($files, function ($a, $b) {
            return $b['time'] - $a['time'];
        });
    }
}
?>
<div class="wrap">
    <h1>История договоров</h1>
    <p class="description">Список сгенерированных договоров в папке плагина.</p>
    
    <div class="yvo-card" style="margin-top: 20px;">
        <?php if (empty($files)): ?>
            <p>Договоров пока нет. Перейдите в <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-recognize'); ?>">Распознать документы</a>, заполните данные и сгенерируйте договор.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50%;">Файл</th>
                        <th>Размер</th>
                        <th>Дата</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $f): ?>
                        <tr>
                            <td><strong><?php echo esc_html($f['name']); ?></strong></td>
                            <td><?php echo size_format($f['size']); ?></td>
                            <td><?php echo date_i18n('d.m.Y H:i', $f['time']); ?></td>
                            <td>
                                <a href="<?php echo esc_url($f['url']); ?>" class="button button-small" target="_blank" rel="noopener">Скачать</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <p style="margin-top: 15px;">
        <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro'); ?>" class="button">← На главную админки</a>
    </p>
</div>
