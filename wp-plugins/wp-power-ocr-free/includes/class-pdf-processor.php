<?php
if (!defined('ABSPATH')) {
    exit;
}
class YVO_PDF_Processor {
    
    public function check_pdf_support() {
        if (function_exists('yvo_pdf_is_supported')) {
            return yvo_pdf_is_supported();
        }
        return $this->check_pdf_ocr_support();
    }

    /** OCR по изображениям страниц (без извлечения текстового слоя). */
    public function check_pdf_ocr_support() {
        if (function_exists('yvo_pdf_has_ocr_rasterization')) {
            return yvo_pdf_has_ocr_rasterization();
        }
        $has_imagick = extension_loaded('imagick');
        $has_ghostscript = false;
        if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))))) {
            $gs_check = @shell_exec('gs --version 2>&1');
            $has_ghostscript = !empty($gs_check);
        }
        $has_spatie = false;
        if (file_exists(YVO_PLUGIN_DIR . 'vendor/autoload.php')) {
            require_once YVO_PLUGIN_DIR . 'vendor/autoload.php';
            $has_spatie = class_exists('Spatie\PdfToImage\Pdf');
        }
        return ($has_imagick && $has_ghostscript) || $has_spatie;
    }
    
    public function process($pdf_url, $api_key, $folder_id, $language = 'ru') {
        if (!$this->check_pdf_support()) {
            return array(
                'success' => false,
                'message' => 'Обработка PDF недоступна. Загрузите папки vendor/ и poppler/ в плагин или установите Imagick + Ghostscript.'
            );
        }

        // Скачиваем PDF
        $local_pdf = $this->download_file($pdf_url);
        if (!$local_pdf) {
            return array(
                'success' => false,
                'message' => 'Не удалось загрузить PDF файл'
            );
        }
        
        // Проверяем размер
        $max_size = get_option('yvo_max_size', 20) * 1024 * 1024;
        if (filesize($local_pdf) > $max_size) {
            @unlink($local_pdf);
            return array(
                'success' => false,
                'message' => sprintf('PDF файл слишком большой (максимум %d MB)', get_option('yvo_max_size', 20))
            );
        }
        if (function_exists('yvo_extract_text_from_pdf_file')) {
            $direct_text = yvo_extract_text_from_pdf_file($local_pdf);
            if ($this->direct_pdf_text_is_usable($direct_text)) {
                @unlink($local_pdf);
                return array(
                    'success' => true,
                    'text'    => trim($direct_text),
                    'filename' => basename(parse_url($pdf_url, PHP_URL_PATH) ?: 'document.pdf'),
                    'pages'   => 1
                );
            }
        }
        $result = $this->process_pdf_ocr_raster($local_pdf, $api_key, $folder_id, $language);
        if (empty($result['success']) && function_exists('yvo_ocr_with_mistral_fallback')) {
            $result = yvo_ocr_with_mistral_fallback($local_pdf, $result);
        }

        // Очищаем временные файлы
        if (file_exists($local_pdf)) {
            @unlink($local_pdf);
        }

        return $result;
    }
    
    /**
     * Обработка PDF по локальному пути (для загрузки файла на фронтенде).
     * Файл не удаляется — удаление выполняет вызывающий код.
     */
    public function process_from_path($local_pdf_path, $api_key, $folder_id, $language = 'ru') {
        if (!file_exists($local_pdf_path)) {
            return array('success' => false, 'message' => 'Файл не найден');
        }
        if (!$this->check_pdf_support()) {
            // Без растра: если Yandex недоступен по инфраструктуре — попробуем Mistral по PDF.
            if (function_exists('yvo_mistral_fallback_available') && yvo_mistral_fallback_available()) {
                $mistral = yvo_mistral_ocr_file($local_pdf_path);
                if (!empty($mistral['success'])) {
                    $mistral['text'] = "--- Использовано распознавание Mistral OCR (резерв) ---\n\n" . $mistral['text'];
                    return $mistral;
                }
            }
            return array(
                'success' => false,
                'message' => 'Обработка PDF недоступна. Скопируйте в плагин папки vendor/ и poppler/ (см. README-PDF-хостинг.txt) или загрузите JPG/PNG.'
            );
        }
        $max_size = get_option('yvo_max_size', 20) * 1024 * 1024;
        if (filesize($local_pdf_path) > $max_size) {
            return array(
                'success' => false,
                'message' => sprintf('PDF файл слишком большой (максимум %d MB)', get_option('yvo_max_size', 20))
            );
        }
        if (function_exists('yvo_extract_text_from_pdf_file')) {
            $direct_text = yvo_extract_text_from_pdf_file($local_pdf_path);
            if ($this->direct_pdf_text_is_usable($direct_text)) {
                return array(
                    'success'  => true,
                    'text'     => trim($direct_text),
                    'filename' => basename($local_pdf_path),
                    'pages'    => 1
                );
            }
        }
        // 1) Yandex Vision по страницам
        $result = $this->process_pdf_ocr_raster($local_pdf_path, $api_key, $folder_id, $language);
        if (!empty($result['success']) && isset($result['text'])) {
            $result['text'] = "--- Использовано распознавание по изображению (встроенный текст не извлечён) ---\n\n" . $result['text'];
            if (function_exists('yvo_pdf_extraction_debug_get')) {
                $debug = yvo_pdf_extraction_debug_get();
                if ($debug !== '') {
                    $result['text'] .= "\n\n--- Диагностика извлечения текста ---\n" . $debug;
                }
            }
            return $result;
        }
        // 2) Резерв: Mistral OCR по всему PDF
        $yandex_err = isset($result['message']) ? (string) $result['message'] : 'Yandex OCR failed';
        if (function_exists('yvo_mistral_fallback_available') && yvo_mistral_fallback_available()) {
            $mistral = yvo_mistral_ocr_file($local_pdf_path);
            if (!empty($mistral['success'])) {
                $mistral['text'] = "--- Использовано распознавание Mistral OCR (резерв; Yandex: $yandex_err) ---\n\n" . $mistral['text'];
                return $mistral;
            }
            $yandex_err .= ' | Mistral: ' . (isset($mistral['message']) ? $mistral['message'] : 'failed');
        }
        return array('success' => false, 'message' => $yandex_err);
    }

    /**
     * OCR PDF: Poppler pdftoppm → Yandex Vision, иначе Spatie / Imagick.
     */
    private function process_pdf_ocr_raster($pdf_path, $api_key, $folder_id, $language) {
        if (!$this->check_pdf_ocr_support()) {
            return array(
                'success' => false,
                'message' => 'В PDF нет текстового слоя для извлечения. Для сканов загрузите JPG/PNG или установите Imagick + Ghostscript.'
            );
        }
        $last_message = '';
        try {
            $poppler = $this->process_with_poppler($pdf_path, $api_key, $folder_id, $language);
            if (!empty($poppler['success'])) {
                return $poppler;
            }
            if (!empty($poppler['message'])) {
                $last_message = (string) $poppler['message'];
            }
            if (class_exists('Spatie\PdfToImage\Pdf')) {
                $spatie = $this->process_with_spatie($pdf_path, $api_key, $folder_id, $language);
                if (!empty($spatie['success'])) {
                    return $spatie;
                }
                if (!empty($spatie['message'])) {
                    $last_message = (string) $spatie['message'];
                }
            }
            if (extension_loaded('imagick')) {
                $imagick = $this->process_with_imagick($pdf_path, $api_key, $folder_id, $language);
                if (!empty($imagick['success'])) {
                    return $imagick;
                }
                if (!empty($imagick['message'])) {
                    $last_message = (string) $imagick['message'];
                }
            }
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Ошибка при обработке PDF: ' . $e->getMessage());
        }
        if ($last_message !== '') {
            return array('success' => false, 'message' => $last_message);
        }
        return array('success' => false, 'message' => 'Не найдены инструменты для распознавания PDF по изображению');
    }

    /** Poppler pdftoppm + Vision OCR (работает на XAMPP без Imagick). */
    private function process_with_poppler($pdf_path, $api_key, $folder_id, $language) {
        if (!function_exists('yvo_get_pdftoppm_path')) {
            return array('success' => false, 'message' => 'pdftoppm недоступен');
        }
        if (function_exists('yvo_poppler_binary_usable') && !yvo_poppler_binary_usable('pdftoppm')) {
            return array('success' => false, 'message' => 'pdftoppm недоступен на сервере');
        }
        $pdftoppm = yvo_get_pdftoppm_path();
        $base_tmp = function_exists('yvo_writable_temp_dir') ? yvo_writable_temp_dir() : YVO_TEMP_DIR;
        if ($base_tmp === '') {
            return array('success' => false, 'message' => 'Нет прав на запись во временную папку для PDF.');
        }
        $temp_dir = $base_tmp . 'pdf_images_' . time() . '/';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        $prefix = $temp_dir . 'page';
        $bin_dir = (strpos($pdftoppm, YVO_PLUGIN_DIR) === 0) ? dirname($pdftoppm) : null;
        $run_ok = false;
        if (function_exists('proc_open')) {
            $args = array($pdftoppm, '-jpeg', '-r', '200', $pdf_path, $prefix);
            $descriptorspec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
            $proc = @proc_open($args, $descriptorspec, $pipes, $bin_dir, null);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                $run_ok = true;
            }
        }
        if (!$run_ok) {
            $this->delete_directory($temp_dir);
            return array('success' => false, 'message' => 'Не удалось запустить pdftoppm');
        }
        $images = glob($temp_dir . 'page*.jpg');
        if (!is_array($images) || count($images) === 0) {
            $images = glob($temp_dir . '*.jpg');
        }
        if (!is_array($images) || count($images) === 0) {
            $this->delete_directory($temp_dir);
            return array('success' => false, 'message' => 'pdftoppm не создал изображения страниц');
        }
        natsort($images);
        $images = array_values($images);
        $max_pages = (int) get_option('yvo_pdf_max_pages', 10);
        if (count($images) > $max_pages) {
            $this->delete_directory($temp_dir);
            return array(
                'success' => false,
                'message' => sprintf('PDF содержит слишком много страниц (%d). Максимум %d.', count($images), $max_pages)
            );
        }
        $all_text = '';
        $page_num = 0;
        foreach ($images as $image_path) {
            $page_num++;
            $page_result = $this->recognize_page($image_path, $api_key, $folder_id, $language);
            if (!empty($page_result['success'])) {
                $all_text .= "--- Страница {$page_num} ---\n";
                $all_text .= $page_result['text'] . "\n\n";
            }
        }
        $this->delete_directory($temp_dir);
        $all_text = trim($all_text);
        if ($all_text === '') {
            return array('success' => false, 'message' => 'Не удалось распознать текст из PDF');
        }
        if (function_exists('yvo_pdf_ocr_append_hint_if_only_signature')) {
            $all_text = yvo_pdf_ocr_append_hint_if_only_signature($all_text);
        }
        return array(
            'success' => true,
            'text' => $all_text,
            'filename' => basename($pdf_path),
            'pages' => $page_num
        );
    }
    
    private function process_with_spatie($pdf_path, $api_key, $folder_id, $language) {
        try {
            $pdf = new Spatie\PdfToImage\Pdf($pdf_path);
            $pages = $pdf->getNumberOfPages();
            
            // Проверяем лимит страниц
            $max_pages = get_option('yvo_pdf_max_pages', 10);
            if ($pages > $max_pages) {
                return array(
                    'success' => false,
                    'message' => sprintf('PDF содержит слишком много страниц (%d). Максимум %d страниц.', $pages, $max_pages)
                );
            }
            
            // Создаем временную директорию
            $base_tmp = function_exists('yvo_writable_temp_dir') ? yvo_writable_temp_dir() : YVO_TEMP_DIR;
            if ($base_tmp === '') {
                return array('success' => false, 'message' => 'Нет прав на запись во временную папку для PDF.');
            }
            $temp_dir = $base_tmp . 'pdf_images_' . time() . '/';
            if (!file_exists($temp_dir)) {
                wp_mkdir_p($temp_dir);
            }
            
            $all_text = '';
            
            // Обрабатываем каждую страницу
            for ($i = 1; $i <= $pages; $i++) {
                try {
                    $temp_image = $temp_dir . 'page_' . $i . '.jpg';
                    $pdf->setPage($i)->saveImage($temp_image);
                    
                    // Распознаем текст с изображения
                    $page_result = $this->recognize_page($temp_image, $api_key, $folder_id, $language);
                    
                    if ($page_result['success']) {
                        $all_text .= "--- Страница {$i} ---\n";
                        $all_text .= $page_result['text'] . "\n\n";
                    }
                    
                    // Удаляем временное изображение
                    @unlink($temp_image);
                } catch (Exception $e) {
                    // Пропускаем страницу с ошибкой
                    continue;
                }
            }
            
            // Удаляем временную директорию
            $this->delete_directory($temp_dir);
            
            $all_text = trim($all_text);
            
            if (empty($all_text)) {
                return array(
                    'success' => false,
                    'message' => 'Не удалось распознать текст из PDF'
                );
            }
            if (function_exists('yvo_pdf_ocr_append_hint_if_only_signature')) {
                $all_text = yvo_pdf_ocr_append_hint_if_only_signature($all_text);
            }
            return array(
                'success' => true,
                'text' => $all_text,
                'filename' => basename($pdf_path),
                'pages' => $pages
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Ошибка Spatie: ' . $e->getMessage()
            );
        }
    }
    
    private function process_with_imagick($pdf_path, $api_key, $folder_id, $language) {
        try {
            $imagick = new Imagick();
            $imagick->setResolution(280, 280);
            $imagick->readImage($pdf_path);
            $pages = $imagick->getNumberImages();
            
            // Проверяем лимит страниц
            $max_pages = get_option('yvo_pdf_max_pages', 10);
            if ($pages > $max_pages) {
                $imagick->clear();
                $imagick->destroy();
                return array(
                    'success' => false,
                    'message' => sprintf('PDF содержит слишком много страниц (%d). Максимум %d страниц.', $pages, $max_pages)
                );
            }
            
            // Создаем временную директорию
            $base_tmp = function_exists('yvo_writable_temp_dir') ? yvo_writable_temp_dir() : YVO_TEMP_DIR;
            if ($base_tmp === '') {
                return array('success' => false, 'message' => 'Нет прав на запись во временную папку для PDF.');
            }
            $temp_dir = $base_tmp . 'pdf_images_' . time() . '/';
            if (!file_exists($temp_dir)) {
                wp_mkdir_p($temp_dir);
            }
            
            $all_text = '';
            
            // Обрабатываем каждую страницу
            for ($i = 0; $i < $pages; $i++) {
                try {
                    $imagick->setIteratorIndex($i);
                    $imagick->setImageFormat('jpeg');
                    $imagick->setImageCompressionQuality(85);
                    
                    $temp_image = $temp_dir . 'page_' . ($i + 1) . '.jpg';
                    $imagick->writeImage($temp_image);
                    
                    // Распознаем текст с изображения
                    $page_result = $this->recognize_page($temp_image, $api_key, $folder_id, $language);
                    
                    if ($page_result['success']) {
                        $all_text .= "--- Страница " . ($i + 1) . " ---\n";
                        $all_text .= $page_result['text'] . "\n\n";
                    }
                    
                    // Удаляем временное изображение
                    @unlink($temp_image);
                } catch (Exception $e) {
                    // Пропускаем страницу с ошибкой
                    continue;
                }
            }
            
            $imagick->clear();
            $imagick->destroy();
            
            // Удаляем временную директорию
            $this->delete_directory($temp_dir);
            
            $all_text = trim($all_text);
            
            if (empty($all_text)) {
                return array(
                    'success' => false,
                    'message' => 'Не удалось распознать текст из PDF'
                );
            }
            if (function_exists('yvo_pdf_ocr_append_hint_if_only_signature')) {
                $all_text = yvo_pdf_ocr_append_hint_if_only_signature($all_text);
            }
            return array(
                'success' => true,
                'text' => $all_text,
                'filename' => basename($pdf_path),
                'pages' => $pages
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Ошибка Imagick: ' . $e->getMessage()
            );
        }
    }
    
    private function recognize_page($image_path, $api_key, $folder_id, $language) {
        // Проверяем существование файла
        if (!file_exists($image_path)) {
            return array('success' => false, 'message' => 'Файл не найден');
        }
        
        // Кодируем изображение
        $image_data = base64_encode(file_get_contents($image_path));
        
        // Запрос к API
        $url = 'https://vision.api.cloud.yandex.net/vision/v1/batchAnalyze';
        
        $headers = array(
            'Authorization: Api-Key ' . $api_key,
            'Content-Type: application/json'
        );
        
        $body = array(
            'folderId' => $folder_id,
            'analyze_specs' => array(
                array(
                    'content' => $image_data,
                    'features' => array(
                        array(
                            'type' => 'TEXT_DETECTION',
                            'text_detection_config' => array(
                                'language_codes' => array($language)
                            )
                        )
                    )
                )
            )
        );
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 60
        ));
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code != 200) {
            $msg = function_exists('yvo_yandex_api_error_message')
                ? yvo_yandex_api_error_message($http_code, $response)
                : ("Ошибка API ($http_code)");
            $fail = array('success' => false, 'message' => $msg);
            return function_exists('yvo_ocr_with_mistral_fallback')
                ? yvo_ocr_with_mistral_fallback($image_path, $fail)
                : $fail;
        }
        
        $data = json_decode($response, true);
        
        // Извлекаем текст
        $text = '';
        if (isset($data['results'][0]['results'][0]['textDetection']['pages'][0]['blocks'])) {
            $blocks = $data['results'][0]['results'][0]['textDetection']['pages'][0]['blocks'];
            
            foreach ($blocks as $block) {
                if (isset($block['lines'])) {
                    foreach ($block['lines'] as $line) {
                        if (isset($line['words'])) {
                            $line_text = '';
                            foreach ($line['words'] as $word) {
                                if (isset($word['text'])) {
                                    $line_text .= $word['text'] . ' ';
                                }
                            }
                            $text .= trim($line_text) . "\n";
                        }
                    }
                }
            }
        }
        
        $text = trim($text);
        
        if (empty($text)) {
            $fail = array('success' => false, 'message' => 'Текст не найден');
            return function_exists('yvo_ocr_with_mistral_fallback')
                ? yvo_ocr_with_mistral_fallback($image_path, $fail)
                : $fail;
        }
        
        return array('success' => true, 'text' => $text);
    }
    
    private function download_file($url) {
        $tmp_dir = function_exists('yvo_writable_temp_dir') ? yvo_writable_temp_dir() : YVO_TEMP_DIR;
        if ($tmp_dir === '') {
            return false;
        }
        $filename = sanitize_file_name(basename(parse_url($url, PHP_URL_PATH)));
        
        if (empty($filename)) {
            $filename = 'pdf_' . time() . '.pdf';
        }
        
        $tmp_file = $tmp_dir . $filename;
        
        $response = wp_remote_get($url, array(
            'timeout' => 60,
            'redirection' => 5
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $file_content = wp_remote_retrieve_body($response);
        
        if (empty($file_content)) {
            return false;
        }
        
        file_put_contents($tmp_file, $file_content);
        
        return $tmp_file;
    }
    
    /**
     * Встроенный текст PDF пригоден только если в нём реально есть данные (не один символ формы/пробелы).
     */
    private function direct_pdf_text_is_usable($text) {
        if (!is_string($text)) {
            return false;
        }
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (function_exists('yvo_pdf_direct_text_is_usable')) {
            return yvo_pdf_direct_text_is_usable($text);
        }
        $letters = preg_match_all('/[а-яА-ЯёЁa-zA-Z0-9]/u', $text);
        return $letters >= 40;
    }

    private function delete_directory($dir) {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return @unlink($dir);
        }
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            if (!$this->delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        
        return @rmdir($dir);
    }
}