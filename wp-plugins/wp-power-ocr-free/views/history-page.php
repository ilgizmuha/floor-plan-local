<?php
global $wpdb;
$table_name = $wpdb->prefix . 'yvo_documents';
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Получаем общее количество записей
$total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

// Получаем записи
$items = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_name ORDER BY processed_at DESC LIMIT %d OFFSET %d",
    $per_page,
    $offset
), ARRAY_A);

// Получаем типы документов
$document_types = array(
    'general' => 'Общее',
    'passport' => 'Паспорт',
    'seller' => 'Продавец',
    'buyer' => 'Покупатель',
    'property' => 'Объект'
);
?>
<div class="wrap">
    <h1>История обработки документов</h1>
    
    <div class="yvo-card">
        <?php if (empty($items)): ?>
            <p>История обработки документов пуста.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped yvo-history-table">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Файл</th>
                        <th>Тип</th>
                        <th>Статус</th>
                        <th>Пользователь</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): 
                        $user = get_userdata($item['user_id']);
                        $parsed_data = !empty($item['parsed_data']) ? json_decode($item['parsed_data'], true) : array();
                    ?>
                        <tr>
                            <td><?php echo date('d.m.Y H:i', strtotime($item['processed_at'])); ?></td>
                            <td>
                                <strong><?php echo esc_html($item['filename']); ?></strong><br>
                                <small><?php echo $item['file_type']; ?></small>
                            </td>
                            <td>
                                <span class="yvo-type-badge">
                                    <?php echo isset($document_types[$item['document_type']]) ? $document_types[$item['document_type']] : $item['document_type']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="yvo-status yvo-status-<?php echo $item['status']; ?>">
                                    <?php echo $item['status'] === 'success' ? 'Успешно' : 'Ошибка'; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $user ? $user->display_name : 'Неизвестно'; ?><br>
                                <small>ID: <?php echo $item['user_id']; ?></small>
                            </td>
                            <td>
                                <button type="button" class="button button-small yvo-view-details" 
                                        data-id="<?php echo $item['id']; ?>"
                                        data-filename="<?php echo esc_attr($item['filename']); ?>"
                                        data-text="<?php echo esc_attr($item['text_content']); ?>"
                                        data-parsed='<?php echo esc_attr(json_encode($parsed_data)); ?>'>
                                    Просмотреть
                                </button>
                                <button type="button" class="button button-small button-link-delete yvo-delete-history" 
                                        data-id="<?php echo $item['id']; ?>">
                                    Удалить
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Пагинация -->
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $total_pages = ceil($total_items / $per_page);
                    if ($total_pages > 1) {
                        $page_links = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        
                        if ($page_links) {
                            echo '<div class="tablenav-pages">' . $page_links . '</div>';
                        }
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Модальное окно для просмотра деталей -->
    <div id="yvo-details-modal" class="yvo-modal" style="display: none;">
        <div class="yvo-modal-content">
            <div class="yvo-modal-header">
                <h3 id="yvo-modal-title"></h3>
                <button type="button" class="yvo-modal-close">&times;</button>
            </div>
            <div class="yvo-modal-body">
                <div id="yvo-modal-text" style="display: none;">
                    <h4>Распознанный текст:</h4>
                    <textarea readonly class="yvo-text-output" style="width: 100%; height: 300px;"></textarea>
                </div>
                <div id="yvo-modal-parsed" style="display: none;">
                    <h4>Извлеченные данные:</h4>
                    <div id="yvo-parsed-list"></div>
                </div>
            </div>
            <div class="yvo-modal-footer">
                <button type="button" class="button button-primary yvo-modal-copy">Копировать текст</button>
                <button type="button" class="button yvo-modal-close">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<style>
.yvo-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
}

.yvo-modal-content {
    background: white;
    width: 90%;
    max-width: 800px;
    max-height: 90vh;
    border-radius: 4px;
    display: flex;
    flex-direction: column;
}

.yvo-modal-header {
    padding: 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.yvo-modal-header h3 {
    margin: 0;
}

.yvo-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.yvo-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}

.yvo-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    text-align: right;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Просмотр деталей
    $('.yvo-view-details').click(function() {
        var $button = $(this);
        var filename = $button.data('filename');
        var text = $button.data('text');
        var parsed = JSON.parse($button.data('parsed') || '{}');
        
        $('#yvo-modal-title').text(filename);
        $('#yvo-modal-text textarea').val(text);
        
        if (text) {
            $('#yvo-modal-text').show();
        }
        
        if (Object.keys(parsed).length > 0) {
            var html = '<ul class="yvo-data-list">';
            for (var key in parsed) {
                if (parsed[key]) {
                    html += '<li><strong>' + key + ':</strong> ' + parsed[key] + '</li>';
                }
            }
            html += '</ul>';
            $('#yvo-parsed-list').html(html);
            $('#yvo-modal-parsed').show();
        }
        
        $('#yvo-details-modal').show();
    });
    
    // Удаление записи
    $('.yvo-delete-history').click(function() {
        if (confirm('Вы уверены, что хотите удалить эту запись?')) {
            var $button = $(this);
            var id = $button.data('id');
            
            $.ajax({
                url: yvo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'yvo_delete_history',
                    nonce: yvo_ajax.nonce,
                    id: id
                },
                success: function() {
                    $button.closest('tr').fadeOut(300, function() {
                        $(this).remove();
                    });
                }
            });
        }
    });
    
    // Закрытие модального окна
    $('.yvo-modal-close, .yvo-modal .yvo-modal-close').click(function() {
        $('#yvo-details-modal').hide();
    });
    
    // Копирование текста в модальном окне
    $('.yvo-modal-copy').click(function() {
        var text = $('#yvo-modal-text textarea').val();
        navigator.clipboard.writeText(text).then(function() {
            alert('Текст скопирован в буфер обмена');
        });
    });
    
    // Закрытие по клику вне окна
    $(window).click(function(e) {
        if ($(e.target).hasClass('yvo-modal')) {
            $('#yvo-details-modal').hide();
        }
    });
});
</script>