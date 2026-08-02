<?php
// Админ страницы

if (!defined('ABSPATH')) {
    exit;
}

function yvo_init_admin_pages() {
    add_action('admin_menu', 'yvo_add_admin_menu');
}

function yvo_add_admin_menu() {
    add_menu_page(
        __('Yandex OCR', 'yandex-vision-ocr'),
        __('Yandex OCR', 'yandex-vision-ocr'),
        'manage_options',
        'yandex-ocr',
        'yvo_admin_main_page',
        'dashicons-text-page',
        30
    );
    
    add_submenu_page(
        'yandex-ocr',
        __('Recognize Text', 'yandex-vision-ocr'),
        __('Recognize Text', 'yandex-vision-ocr'),
        'manage_options',
        'yandex-ocr',
        'yvo_admin_main_page'
    );
    
    add_submenu_page(
        'yandex-ocr',
        __('Settings', 'yandex-vision-ocr'),
        __('Settings', 'yandex-vision-ocr'),
        'manage_options',
        'yandex-ocr-settings',
        'yvo_admin_settings_page'
    );
}

function yvo_admin_main_page() {
    $api_key = get_option('yvo_api_key');
    $folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    $is_configured = !empty($api_key) && !empty($folder_id);
    ?>
    <div class="wrap">
        <h1><?php _e('Yandex Vision OCR', 'yandex-vision-ocr'); ?></h1>
        
        <?php if (!$is_configured): ?>
        <div class="notice notice-warning">
            <p><?php 
                printf(
                    __('Please <a href="%s">configure API settings</a> before using the plugin.', 'yandex-vision-ocr'),
                    admin_url('admin.php?page=yandex-ocr-settings')
                ); 
            ?></p>
        </div>
        <?php endif; ?>
        
        <div class="yvo-admin-container">
            <div class="yvo-card">
                <h2><?php _e('Recognize Text from Image', 'yandex-vision-ocr'); ?></h2>
                
                <div class="yvo-upload-section">
                    <div class="yvo-file-input-group">
                        <input type="text" id="yvo-image-url" class="regular-text" 
                               placeholder="<?php _e('Image URL or select from media library', 'yandex-vision-ocr'); ?>">
                        <button type="button" id="yvo-browse-btn" class="button">
                            <?php _e('Select Image', 'yandex-vision-ocr'); ?>
                        </button>
                    </div>
                    
                    <div class="yvo-actions">
                        <button type="button" id="yvo-process-btn" class="button button-primary" 
                                <?php echo !$is_configured ? 'disabled' : ''; ?>>
                            <?php _e('Recognize Text', 'yandex-vision-ocr'); ?>
                        </button>
                    </div>
                    
                    <div class="yvo-progress" style="display:none;">
                        <span class="spinner is-active"></span>
                        <p><?php _e('Processing image...', 'yandex-vision-ocr'); ?></p>
                    </div>
                </div>
                
                <div id="yvo-results-area"></div>
            </div>
            
            <div class="yvo-card">
                <h3><?php _e('Supported Formats:', 'yandex-vision-ocr'); ?></h3>
                <ul>
                    <li>JPG, JPEG</li>
                    <li>PNG</li>
                    <li>GIF</li>
                    <li>BMP</li>
                    <li>PDF (first page only)</li>
                </ul>
                
                <h3><?php _e('Recommendations:', 'yandex-vision-ocr'); ?></h3>
                <ul>
                    <li><?php _e('Use clear, high-contrast images', 'yandex-vision-ocr'); ?></li>
                    <li><?php _e('Text should be horizontal', 'yandex-vision-ocr'); ?></li>
                    <li><?php 
                        printf(
                            __('Maximum file size: %d MB', 'yandex-vision-ocr'),
                            get_option('yvo_max_size', 2)
                        ); 
                    ?></li>
                    <li><?php _e('For best results, use Russian or English language', 'yandex-vision-ocr'); ?></li>
                </ul>
                
                <?php if ($is_configured): ?>
                <div class="yvo-status-info">
                    <p><strong><?php _e('Status:', 'yandex-vision-ocr'); ?></strong> 
                       <span style="color:green;">✓ <?php _e('Configured', 'yandex-vision-ocr'); ?></span></p>
                    <p><strong><?php _e('Folder ID:', 'yandex-vision-ocr'); ?></strong> <?php echo esc_html($folder_id); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function yvo_admin_settings_page() {
    // Сохраняем настройки
    if (isset($_POST['yvo_save_settings']) && check_admin_referer('yvo_settings_save')) {
        update_option('yvo_api_key', sanitize_text_field($_POST['api_key']));
        update_option('yvo_folder_id', sanitize_text_field($_POST['folder_id']));
        update_option('yvo_language', sanitize_text_field($_POST['language']));
        update_option('yvo_max_size', intval($_POST['max_size']));
        update_option('yvo_enable_shortcode', isset($_POST['enable_shortcode']) ? 1 : 0);
        update_option('yvo_yandex_client_id', sanitize_text_field($_POST['yandex_client_id'] ?? ''));
        update_option('yvo_yandex_client_secret', sanitize_text_field($_POST['yandex_client_secret'] ?? ''));
        
        echo '<div class="notice notice-success"><p>' . __('Settings saved!', 'yandex-vision-ocr') . '</p></div>';
    }
    
    $api_key = get_option('yvo_api_key');
    $folder_id = get_option('yvo_folder_id', 'b1gdef3liihncenqdht6');
    $language = get_option('yvo_language', 'ru');
    $max_size = get_option('yvo_max_size', 2);
    $enable_shortcode = get_option('yvo_enable_shortcode', 1);
    $yandex_client_id = get_option('yvo_yandex_client_id', '');
    $yandex_client_secret = get_option('yvo_yandex_client_secret', '');
    
    $languages = array(
        'ru' => __('Russian', 'yandex-vision-ocr'),
        'en' => __('English', 'yandex-vision-ocr'),
        'tr' => __('Turkish', 'yandex-vision-ocr'),
        'uk' => __('Ukrainian', 'yandex-vision-ocr'),
        'kk' => __('Kazakh', 'yandex-vision-ocr')
    );
    ?>
    <div class="wrap">
        <h1><?php _e('Yandex Vision API Settings', 'yandex-vision-ocr'); ?></h1>
        
        <div class="yvo-settings-container">
            <div class="yvo-card">
                <form method="post" action="">
                    <?php wp_nonce_field('yvo_settings_save'); ?>
                    
                    <h2><?php _e('API Settings', 'yandex-vision-ocr'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="api_key"><?php _e('API Key', 'yandex-vision-ocr'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="api_key" name="api_key" 
                                       value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                                <p class="description">
                                    <?php _e('Your Yandex Cloud API key', 'yandex-vision-ocr'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="folder_id"><?php _e('Folder ID', 'yandex-vision-ocr'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="folder_id" name="folder_id" 
                                       value="<?php echo esc_attr($folder_id); ?>" class="regular-text">
                                <p class="description">
                                    <?php _e('Yandex Cloud folder identifier', 'yandex-vision-ocr'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="language"><?php _e('Text Language', 'yandex-vision-ocr'); ?></label>
                            </th>
                            <td>
                                <select id="language" name="language">
                                    <?php foreach ($languages as $code => $name): ?>
                                        <option value="<?php echo esc_attr($code); ?>" <?php selected($language, $code); ?>>
                                            <?php echo esc_html($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="max_size"><?php _e('Max File Size (MB)', 'yandex-vision-ocr'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="max_size" name="max_size" 
                                       value="<?php echo esc_attr($max_size); ?>" min="1" max="20" step="1">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="enable_shortcode"><?php _e('Enable Frontend Form', 'yandex-vision-ocr'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="enable_shortcode" name="enable_shortcode" 
                                           value="1" <?php checked($enable_shortcode, 1); ?>>
                                    <?php _e('Enable shortcode [yandex_ocr_form] on the site', 'yandex-vision-ocr'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="yandex_client_id"><?php _e('Yandex OAuth Client ID', 'yandex-vision-ocr'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="yandex_client_id" name="yandex_client_id"
                                       value="<?php echo esc_attr($yandex_client_id); ?>" class="regular-text">
                                <p class="description">
                                    <?php _e('Create an OAuth app in Yandex and paste Client ID here.', 'yandex-vision-ocr'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="yandex_client_secret"><?php _e('Yandex OAuth Client Secret', 'yandex-vision-ocr'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="yandex_client_secret" name="yandex_client_secret"
                                       value="<?php echo esc_attr($yandex_client_secret); ?>" class="regular-text">
                                <p class="description">
                                    <?php _e('Client Secret from Yandex OAuth app settings.', 'yandex-vision-ocr'); ?>
                                </p>
                                <p class="description">
                                    <?php
                                    if (function_exists('home_url')) {
                                        echo esc_html__('Redirect URI for Yandex:', 'yandex-vision-ocr') . ' ' . esc_html(home_url('/?yvo_oauth=yandex&yvo_oauth_action=callback'));
                                    }
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="yvo_save_settings" 
                               class="button button-primary" 
                               value="<?php _e('Save Settings', 'yandex-vision-ocr'); ?>">
                        <button type="button" id="yvo-test-api-btn" class="button button-secondary">
                            <?php _e('Test API Connection', 'yandex-vision-ocr'); ?>
                        </button>
                    </p>
                </form>
                
                <div id="yvo-test-result"></div>
            </div>
            
            <div class="yvo-card">
                <h2><?php _e('Current Settings', 'yandex-vision-ocr'); ?></h2>
                
                <div class="yvo-info-box">
                    <ul>
                        <li><strong><?php _e('API Key:', 'yandex-vision-ocr'); ?></strong> 
                            <?php echo !empty($api_key) ? '✓ ' . __('Set', 'yandex-vision-ocr') : '✗ ' . __('Not set', 'yandex-vision-ocr'); ?>
                        </li>
                        <li><strong><?php _e('Folder ID:', 'yandex-vision-ocr'); ?></strong> <?php echo esc_html($folder_id); ?></li>
                        <li><strong><?php _e('Language:', 'yandex-vision-ocr'); ?></strong> <?php echo $languages[$language] ?? 'Russian'; ?></li>
                        <li><strong><?php _e('Max Size:', 'yandex-vision-ocr'); ?></strong> <?php echo $max_size; ?> MB</li>
                        <li><strong><?php _e('Frontend Form:', 'yandex-vision-ocr'); ?></strong> 
                            <?php echo $enable_shortcode ? '✓ ' . __('Enabled', 'yandex-vision-ocr') : '✗ ' . __('Disabled', 'yandex-vision-ocr'); ?>
                        </li>
                    </ul>
                </div>
                
                <h3><?php _e('Shortcode Usage', 'yandex-vision-ocr'); ?></h3>
                <p><?php _e('Add this shortcode to any page or post to display the OCR form:', 'yandex-vision-ocr'); ?></p>
                <code>[yandex_ocr_form]</code>
                
                <?php if (!empty($api_key) && !empty($folder_id)): ?>
                <p style="margin-top: 20px;">
                    <a href="<?php echo admin_url('admin.php?page=yandex-ocr'); ?>" class="button button-primary">
                        <?php _e('Go to Text Recognition', 'yandex-vision-ocr'); ?>
                    </a>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}