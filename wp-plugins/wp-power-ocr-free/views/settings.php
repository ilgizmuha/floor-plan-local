<?php
$auth_type = get_option('wpo_yandex_auth_type', 'iam_token');
$iam_token = get_option('wpo_yandex_iam_token');
$api_key = get_option('wpo_yandex_api_key');
$folder_id = get_option('wpo_yandex_folder_id');
$language = get_option('wpo_yandex_language', 'ru');

$languages = [
    'ru' => 'Russian',
    'en' => 'English',
    'tr' => 'Turkish',
    'kk' => 'Kazakh',
    'az' => 'Azerbaijani',
    'be' => 'Belarusian',
    'uk' => 'Ukrainian',
    'hy' => 'Armenian',
    'ka' => 'Georgian'
];
?>

<div class="wrap">
    <h1>Yandex Vision API Settings</h1>
    
    <div class="wpo-card">
        <form method="post" action="">
            <?php wp_nonce_field('wpo_yandex_settings'); ?>
            
            <h2>Authentication</h2>
            
            <table class="form-table">
                <tr>
                    <th><label>Authentication Type</label></th>
                    <td>
                        <select name="auth_type" id="auth_type">
                            <option value="iam_token" <?php selected($auth_type, 'iam_token'); ?>>IAM Token (Temporary)</option>
                            <option value="api_key" <?php selected($auth_type, 'api_key'); ?>>API Key (Permanent)</option>
                        </select>
                    </td>
                </tr>
                
                <tr id="iam_token_row" style="<?php echo $auth_type !== 'iam_token' ? 'display:none;' : ''; ?>">
                    <th><label for="iam_token">IAM Token</label></th>
                    <td>
                        <input type="password" name="iam_token" id="iam_token" 
                               value="<?php echo esc_attr($iam_token); ?>" class="regular-text">
                        <p class="description">
                            Get IAM token: <code>yc iam create-token</code><br>
                            Valid for 12 hours
                        </p>
                    </td>
                </tr>
                
                <tr id="api_key_row" style="<?php echo $auth_type !== 'api_key' ? 'display:none;' : ''; ?>">
                    <th><label for="api_key">API Key</label></th>
                    <td>
                        <input type="password" name="api_key" id="api_key" 
                               value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                        <p class="description">
                            Permanent API key from service account
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="folder_id">Folder ID</label></th>
                    <td>
                        <input type="text" name="folder_id" id="folder_id" 
                               value="<?php echo esc_attr($folder_id); ?>" class="regular-text">
                        <p class="description">
                            Your Yandex Cloud folder ID
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="language">Language</label></th>
                    <td>
                        <select name="language" id="language">
                            <?php foreach ($languages as $code => $name): ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($language, $code); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="wpo_save_settings" class="button button-primary" value="Save Settings">
                <button type="button" id="wpo-test-api" class="button button-secondary">Test API Connection</button>
            </p>
        </form>
        
        <div id="wpo-test-result"></div>
    </div>
    
    <div class="wpo-card">
        <h2>Your Current Credentials</h2>
        
        <?php if (!empty($folder_id)): ?>
        <div class="notice notice-info">
            <p><strong>Folder ID:</strong> <?php echo esc_html($folder_id); ?></p>
            <p><strong>Authentication:</strong> <?php echo esc_html($auth_type); ?></p>
            <p><strong>Status:</strong> 
                <?php 
                if (($auth_type === 'iam_token' && !empty($iam_token)) || 
                    ($auth_type === 'api_key' && !empty($api_key))) {
                    echo '<span style="color:green;">✓ Configured</span>';
                } else {
                    echo '<span style="color:red;">✗ Not configured</span>';
                }
                ?>
            </p>
        </div>
        <?php endif; ?>
        
        <p><a href="<?php echo admin_url('admin.php?page=wp_power_ocr_yandex'); ?>" class="button">
            Start Using OCR
        </a></p>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Toggle auth fields
    $('#auth_type').change(function() {
        var type = $(this).val();
        $('#iam_token_row, #api_key_row').hide();
        $('#' + type + '_row').show();
    });
    
    // Test API connection
    $('#wpo-test-api').click(function() {
        var button = $(this);
        var originalText = button.text();
        
        button.prop('disabled', true).text('Testing...');
        $('#wpo-test-result').html('<p><span class="spinner is-active"></span> Testing connection...</p>');
        
        $.ajax({
            url: wpo_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wpo_test_yandex_api',
                nonce: wpo_ajax.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#wpo-test-result').html(
                        '<div class="notice notice-success"><p>✅ ' + response.data.message + '</p></div>'
                    );
                } else {
                    $('#wpo-test-result').html(
                        '<div class="notice notice-error"><p>❌ ' + response.data.message + '</p></div>'
                    );
                }
            },
            error: function() {
                $('#wpo-test-result').html(
                    '<div class="notice notice-error"><p>❌ Server error occurred</p></div>'
                );
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
            }
        });
    });
});
</script>