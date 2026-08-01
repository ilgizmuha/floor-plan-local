<?php
if (!defined('ABSPATH')) {
    exit;
}

$templates_dir = YVO_TEMPLATES_DIR;
$available = yvo_get_available_templates();
$real_path = realpath($templates_dir);
$writable = $real_path && is_writable($templates_dir);
$upload_dir = yvo_get_uploaded_templates_dir();
$upload_writable = is_writable($upload_dir);

$banks = yvo_get_banks_list();
$dkp_variants = yvo_get_dkp_variants();
$share_variants = yvo_get_share_allocation_variants();
$property_types = yvo_get_property_types_list();
$uploaded_list = yvo_get_uploaded_templates();

$contract_types_admin = array(
    'sale' => '1. Договоры купли-продажи',
    'gift' => '2. Договоры дарения',
    'share_allocation' => '3. Соглашение выделения долей',
    'deposit_agreement' => '4. Договоры задатка и аванса (задаток / предварительный ДКП)',
    'advance_agreement' => '4. Договоры задатка и аванса (аванс)',
);

// Удаление шаблона
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && current_user_can('manage_options')) {
    check_admin_referer('yvo_delete_template_' . sanitize_key($_GET['id']));
    $id = sanitize_key($_GET['id']);
    if (yvo_delete_uploaded_template($id)) {
        echo '<div class="notice notice-success"><p>Шаблон удалён.</p></div>';
    }
    $uploaded_list = yvo_get_uploaded_templates();
}

// Сохранение загруженного шаблона (POST с файлами)
$upload_nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
if (isset($_POST['yvo_upload_template']) && current_user_can('manage_options')) {
    if (!wp_verify_nonce($upload_nonce, 'yvo_upload_template_nonce')) {
        echo '<div class="notice notice-error"><p>Ошибка безопасности. Обновите страницу (F5) и загрузите файл снова.</p></div>';
    } else {
    $contract_type = isset($_POST['contract_type']) ? sanitize_key($_POST['contract_type']) : 'sale';
    $category = ($contract_type === 'sale' && isset($_POST['category']) && $_POST['category'] === 'sale_mortgage') ? 'sale_mortgage' : $contract_type;
    $variant_slug = isset($_POST['variant_slug']) ? sanitize_key($_POST['variant_slug']) : '';
    $variant_label = isset($_POST['variant_label']) ? sanitize_text_field(wp_unslash($_POST['variant_label'])) : '';
    $bank_id = isset($_POST['bank_id']) ? sanitize_key($_POST['bank_id']) : 'standard';
    $property_type = isset($_POST['property_type']) ? sanitize_key($_POST['property_type']) : 'all';

    $file_txt_name = '';
    $file_docx_name = '';
    if (!empty($_FILES['file_txt']['tmp_name']) && is_uploaded_file($_FILES['file_txt']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['file_txt']['name'], PATHINFO_EXTENSION));
        if ($ext === 'txt') {
            $file_txt_name = 'upload_' . time() . '_' . wp_unique_id() . '.txt';
            if (move_uploaded_file($_FILES['file_txt']['tmp_name'], $upload_dir . $file_txt_name)) {
                $file_txt_name = $file_txt_name;
            } else {
                $file_txt_name = '';
            }
        }
    }
    if (!empty($_FILES['file_docx']['tmp_name']) && is_uploaded_file($_FILES['file_docx']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['file_docx']['name'], PATHINFO_EXTENSION));
        if ($ext === 'docx') {
            $file_docx_name = 'upload_' . time() . '_' . wp_unique_id() . '.docx';
            if (move_uploaded_file($_FILES['file_docx']['tmp_name'], $upload_dir . $file_docx_name)) {
                $file_docx_name = $file_docx_name;
            } else {
                $file_docx_name = '';
            }
        }
    }

    if ($file_txt_name !== '') {
        $txt_path = $upload_dir . $file_txt_name;
        $txt_content = is_readable($txt_path) ? file_get_contents($txt_path) : '';
        $detected = function_exists('yvo_detect_template_meta_from_text') && $txt_content !== '' ? yvo_detect_template_meta_from_text($txt_content) : array('category' => $category, 'placeholders' => array());
        if (!empty($detected['category']) && $contract_type === 'sale' && ($category === 'sale' || $category === 'sale_mortgage')) {
            if ($detected['category'] === 'sale_mortgage') {
                $category = 'sale_mortgage';
            }
        } elseif (!empty($detected['category']) && $contract_type !== 'sale') {
            $category = $detected['category'];
        }
        $placeholders_list = isset($detected['placeholders']) && is_array($detected['placeholders']) ? $detected['placeholders'] : array();

        $id = $variant_slug ?: ('upload_' . (count($uploaded_list) + 1));
        if ($bank_id !== 'standard') {
            $id = $id . '_' . $bank_id;
        }
        $id = preg_replace('/[^a-z0-9_\-]/i', '_', $id);
        if (isset($uploaded_list[$id])) {
            $id = $id . '_' . time();
        }
        yvo_save_uploaded_template(array(
            'id' => $id,
            'contract_type' => $contract_type,
            'category' => $category,
            'variant_slug' => $variant_slug,
            'variant_label' => $variant_label,
            'bank_id' => $bank_id,
            'property_type' => $property_type,
            'file_txt' => $file_txt_name,
            'file_docx' => $file_docx_name,
            'placeholders' => $placeholders_list,
        ));
        $placeholders_str = count($placeholders_list) > 0 ? implode(', ', array_slice($placeholders_list, 0, 15)) . (count($placeholders_list) > 15 ? '…' : '') : '—';
        $cat_labels = array('sale' => 'ДКП (свои средства)', 'sale_mortgage' => 'ДКП с ипотекой', 'gift' => 'Дарение', 'share_allocation' => 'Выдел долей', 'deposit_agreement' => 'Задаток', 'advance_agreement' => 'Аванс');
        $cat_label = isset($cat_labels[$category]) ? $cat_labels[$category] : $category;
        echo '<div class="notice notice-success"><p><strong>Шаблон добавлен.</strong> Он появится в списке на форме генерации договоров.</p>';
        echo '<p>Распознанная категория: <strong>' . esc_html($cat_label) . '</strong>. Найденные теги в шаблоне: ' . esc_html($placeholders_str) . '.</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Загрузите файл .txt (обязательно).</p></div>';
    }
    $uploaded_list = yvo_get_uploaded_templates();
    }
}
?>
<div class="wrap">
    <h1>Шаблоны договоров</h1>

    <div class="yvo-card" style="margin-top: 20px;">
        <h2>Загрузить свой шаблон (TXT и DOCX)</h2>
        <p class="description">Загрузите .txt (обязательно) и при необходимости .docx. При генерации договора, если для выбранного шаблона есть DOCX — будет использован он (сохраняются стили и формат). В тексте используйте плейсхолдеры: {{SELLER_FULL_NAME}}, {{BUYER_FULL_NAME}}, {{PROPERTY_ADDRESS}}, {{PROPERTY_PRICE}}, {{PROPERTY_PRICE_WORDS}}, {{CURRENT_DATE}} и т.д.</p>
        <?php if (!$upload_writable): ?>
            <p class="notice notice-warning inline">Папка загрузок недоступна для записи: <code><?php echo esc_html($upload_dir); ?></code></p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" action="">
            <?php wp_nonce_field('yvo_upload_template_nonce'); ?>
            <input type="hidden" name="yvo_upload_template" value="1">
            <table class="form-table">
                <tr>
                    <th><label for="contract_type">Тип договора</label></th>
                    <td>
                        <select name="contract_type" id="contract_type">
                            <?php foreach ($contract_types_admin as $k => $v): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr id="row_category" style="display:none;">
                    <th><label for="category">Категория ДКП</label></th>
                    <td>
                        <select name="category" id="category">
                            <option value="sale">Свои средства (обычный ДКП)</option>
                            <option value="sale_mortgage">С ипотекой</option>
                        </select>
                    </td>
                </tr>
                <tr id="row_variant">
                    <th><label for="variant_slug">Вариант шаблона</label></th>
                    <td>
                        <select name="variant_slug" id="variant_slug">
                            <option value="">— выберите или введите название ниже —</option>
                            <?php foreach ($dkp_variants as $k => $v): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Для «Соглашение выделения долей» и «Дарение» укажите название в поле ниже.</p>
                        <input type="text" name="variant_label" id="variant_label" class="regular-text" placeholder="Название варианта (если нет в списке)">
                    </td>
                </tr>
                <tr id="row_bank">
                    <th><label for="bank_id">Банк</label></th>
                    <td>
                        <select name="bank_id" id="bank_id">
                            <?php foreach ($banks as $k => $v): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Для шаблонов «с ипотекой» можно загрузить отдельный шаблон под каждый банк.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="property_type">Тип объекта</label></th>
                    <td>
                        <select name="property_type" id="property_type">
                            <?php foreach ($property_types as $k => $v): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="file_txt">Файл .txt</label></th>
                    <td>
                        <input type="file" name="file_txt" id="file_txt" accept=".txt" required>
                        <p class="description">Текст шаблона с плейсхолдерами {{...}}</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="file_docx">Файл .docx (необязательно)</label></th>
                    <td>
                        <input type="file" name="file_docx" id="file_docx" accept=".docx">
                        <p class="description">Если указан — при генерации будет использован этот файл (стили сохранятся). Плейсхолдеры те же.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary">Загрузить шаблон</button>
            </p>
        </form>
        <?php endif; ?>
    </div>

    <div class="yvo-card" style="margin-top: 20px;">
        <h2>Загруженные через админку шаблоны</h2>
        <?php if (empty($uploaded_list)): ?>
            <p>Пока нет. Загрузите шаблоны формой выше.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Тип</th>
                        <th>Категория</th>
                        <th>Вариант / название</th>
                        <th>Банк</th>
                        <th>Объект</th>
                        <th>Теги (плейсхолдеры)</th>
                        <th>TXT</th>
                        <th>DOCX</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $cat_labels = array('sale' => 'ДКП', 'sale_mortgage' => 'ДКП ипотека', 'gift' => 'Дарение', 'share_allocation' => 'Выдел долей', 'deposit_agreement' => 'Задаток', 'advance_agreement' => 'Аванс');
                    foreach ($uploaded_list as $id => $u):
                        $pl = isset($u['placeholders']) && is_array($u['placeholders']) ? $u['placeholders'] : array();
                        $tags_preview = count($pl) > 0 ? implode(', ', array_slice($pl, 0, 5)) . (count($pl) > 5 ? '…' : '') : '—';
                        $cat = isset($u['category']) ? $u['category'] : 'sale';
                        $cat_label = isset($cat_labels[$cat]) ? $cat_labels[$cat] : $cat;
                    ?>
                        <tr>
                            <td><code><?php echo esc_html($id); ?></code></td>
                            <td><?php echo esc_html($u['contract_type']); ?></td>
                            <td><?php echo esc_html($cat_label); ?></td>
                            <td><?php echo esc_html(!empty($u['variant_label']) ? $u['variant_label'] : $u['variant_slug']); ?></td>
                            <td><?php echo esc_html(isset($banks[$u['bank_id']]) ? $banks[$u['bank_id']] : $u['bank_id']); ?></td>
                            <td><?php echo esc_html(isset($property_types[$u['property_type']]) ? $property_types[$u['property_type']] : $u['property_type']); ?></td>
                            <td title="<?php echo esc_attr(implode(', ', $pl)); ?>"><?php echo esc_html($tags_preview); ?></td>
                            <td><?php echo !empty($u['file_txt']) ? '✓' : '—'; ?></td>
                            <td><?php echo !empty($u['file_docx']) ? '✓' : '—'; ?></td>
                            <td>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=yandex-ocr-pro-templates&action=delete&id=' . $id), 'yvo_delete_template_' . $id)); ?>" class="button button-small" onclick="return confirm('Удалить этот шаблон?');">Удалить</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="yvo-card" style="margin-top: 20px;">
        <h2>Папка встроенных шаблонов</h2>
        <p>
            <strong>Путь:</strong> <code><?php echo esc_html($real_path ?: $templates_dir); ?></code>
            <?php if ($writable): ?>
                <span style="color:green;"> (доступна для записи)</span>
            <?php else: ?>
                <span style="color:orange;"> (проверьте права на запись)</span>
            <?php endif; ?>
        </p>
        <p class="description">
            Файлы .txt в папках плагина «Шаблоны договоров» и «договоры» подхватываются автоматически. Загруженные через форму выше сохраняются в <code><?php echo esc_html($upload_dir); ?></code> и привязываются к типу, варианту и банку.
        </p>
    </div>

    <div class="yvo-card" style="margin-top: 20px;">
        <h2>Доступные шаблоны (все)</h2>
        <?php if (empty($available)): ?>
            <p>Нет шаблонов. Добавьте .txt в папку плагина или загрузите через форму выше.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Идентификатор</th>
                        <th>Название в интерфейсе</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($available as $id => $label): ?>
                        <tr>
                            <td><code><?php echo esc_html($id); ?></code></td>
                            <td><?php echo esc_html($label); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <p style="margin-top: 20px;">
        <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro-settings'); ?>" class="button">Настройки</a>
        <a href="<?php echo admin_url('admin.php?page=yandex-ocr-pro'); ?>" class="button">На главную</a>
    </p>
</div>
<script>
(function(){
    var ct = document.getElementById('contract_type');
    var rowCat = document.getElementById('row_category');
    var rowBank = document.getElementById('row_bank');
    if (!ct) return;
    function toggle() {
        var v = ct.value;
        rowCat.style.display = (v === 'sale') ? '' : 'none';
        rowBank.style.display = (v === 'sale') ? '' : 'none';
    }
    ct.addEventListener('change', toggle);
    toggle();
})();
</script>
