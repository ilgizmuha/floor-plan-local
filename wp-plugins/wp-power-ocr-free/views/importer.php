<div class="wrap">
    <h1>WP Power OCR - Yandex Vision</h1>
    
    <div class="wpo-card">
        <h2>Extract Text from Images</h2>
        <p>Upload images to extract text using Yandex Vision API.</p>
        
        <form id="wpo-importer">
            <div class="wpo-file-inputs">
                <div class="wpo-file-row">
                    <input type="text" class="wpo-file-url regular-text" placeholder="Image URL or select from library">
                    <button type="button" class="button wpo-browse">Select Image</button>
                    <button type="button" class="button button-secondary wpo-remove" style="display:none;">Remove</button>
                </div>
            </div>
            
            <div class="wpo-actions">
                <button type="button" class="button button-secondary" id="wpo-add-file">+ Add Another Image</button>
                <button type="button" class="button button-primary" id="wpo-process">Extract Text</button>
            </div>
        </form>
        
        <div class="wpo-progress" style="display:none;">
            <div class="spinner is-active"></div>
            <p>Processing image with Yandex Vision API...</p>
        </div>
        
        <div id="wpo-results"></div>
    </div>
    
    <div class="wpo-card">
        <h3>Tips for Best Results:</h3>
        <ul>
            <li>Use clear, high-contrast images</li>
            <li>Image should contain horizontal text</li>
            <li>Maximum file size: 2 MB</li>
            <li>Supported formats: JPG, PNG, GIF, BMP</li>
        </ul>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var fileCount = 1;
    
    // Select image from media library
    $('.wpo-browse').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var input = button.siblings('.wpo-file-url');
        
        var frame = wp.media({
            title: 'Select Image',
            multiple: false,
            library: { type: 'image' }
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            input.val(attachment.url);
            button.siblings('.wpo-remove').show();
        });
        
        frame.open();
    });
    
    // Add more files
    $('#wpo-add-file').on('click', function() {
        fileCount++;
        var newRow = $('.wpo-file-row:first').clone();
        newRow.find('input').val('');
        newRow.find('.wpo-remove').hide();
        $('.wpo-file-inputs').append(newRow);
    });
    
    // Remove file row
    $(document).on('click', '.wpo-remove', function() {
        if (fileCount > 1) {
            $(this).closest('.wpo-file-row').remove();
            fileCount--;
        } else {
            $(this).closest('.wpo-file-row').find('input').val('');
            $(this).hide();
        }
    });
    
    // Process images
    $('#wpo-process').on('click', function() {
        var files = [];
        $('.wpo-file-url').each(function() {
            var url = $(this).val().trim();
            if (url) files.push(url);
        });
        
        if (files.length === 0) {
            alert('Please select at least one image.');
            return;
        }
        
        $('.wpo-progress').show();
        $('#wpo-results').html('');
        
        // Process sequentially
        var index = 0;
        var results = [];
        
        function processNext() {
            if (index >= files.length) {
                $('.wpo-progress').hide();
                showResults();
                return;
            }
            
            $.ajax({
                url: wpo_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpo_process_yandex',
                    nonce: wpo_ajax.nonce,
                    file_url: files[index]
                },
                dataType: 'json',
                success: function(response) {
                    results.push({url: files[index], data: response});
                    index++;
                    processNext();
                },
                error: function() {
                    results.push({
                        url: files[index],
                        data: {success: false, message: 'Server error'}
                    });
                    index++;
                    processNext();
                }
            });
        }
        
        function showResults() {
            var html = '<h3>Results:</h3>';
            
            $.each(results, function(i, item) {
                html += '<div class="wpo-result">';
                html += '<h4>' + item.url.split('/').pop() + '</h4>';
                
                if (item.data.success) {
                    html += '<img src="' + item.url + '" style="max-width: 300px; margin: 10px 0;">';
                    html += '<textarea readonly style="width:100%; height:150px; margin:10px 0;">' + 
                           item.data.text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</textarea>';
                    html += '<button class="button button-small copy-text" data-text="' + 
                           item.data.text.replace(/"/g, '&quot;') + '">Copy Text</button>';
                } else {
                    html += '<div class="error"><p><strong>Error:</strong> ' + item.data.message + '</p></div>';
                }
                
                html += '</div><hr>';
            });
            
            $('#wpo-results').html(html);
            
            // Add copy functionality
            $('.copy-text').on('click', function() {
                var text = $(this).data('text');
                var textarea = $('<textarea>').val(text).appendTo('body').select();
                document.execCommand('copy');
                textarea.remove();
                alert('Text copied to clipboard!');
            });
        }
        
        processNext();
    });
});
</script>