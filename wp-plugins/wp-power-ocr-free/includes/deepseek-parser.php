<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class YVO_DeepSeekParser {
    
    private $api_key;
    private $model;
    
    public function __construct() {
        $this->api_key = get_option('yvo_deepseek_api_key');
        $this->model = get_option('yvo_deepseek_model', 'deepseek-chat');
    }
    
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    public function parse_person_data($text) {
        if (!$this->is_configured()) {
            return array('success' => false, 'message' => 'DeepSeek не настроен');
        }
        
        $prompt = "Ты - AI ассистент для извлечения структурированных данных из текста. Извлеки следующие данные и верни ТОЛЬКО JSON без дополнительного текста:
{
  \"full_name\": null,
  \"passport_series\": \"Серия паспорта (4 цифры)\",
  \"passport_number\": \"Номер паспорта (6 цифр)\",
  \"department_code\": \"Код подразделения (формат 000-000)\",
  \"passport_issued_by\": \"Кем выдан паспорт (полное название организации)\",
  \"passport_date\": \"Дата выдачи (формат дд.мм.гггг)\",
  \"birth_date\": \"Дата рождения (формат дд.мм.гггг)\",
  \"birth_place\": \"Место рождения (город, область, страна)\",
  \"registration\": \"Адрес регистрации (полный адрес)\",
  \"inn\": \"ИНН (10 или 12 цифр)\",
  \"snils\": \"СНИЛС (формат 000-000-000 00)\",
  \"phone\": \"Номер телефона\",
  \"email\": \"Email адрес\"
}

Если каких-то данных нет, верни null для этих полей. Никогда не подставляй примерные ФИО (Иванов, Петров, Сидоров) — только то, что явно есть в тексте.

Текст документа:\n\n" . $text;
        
        return $this->send_request($prompt, 2000, null, $text);
    }
    
    public function parse_property_data($text) {
        if (!$this->is_configured()) {
            return array('success' => false, 'message' => 'DeepSeek не настроен');
        }
        
        $prompt = "Ты - AI ассистент для извлечения структурированных данных из текста об объекте недвижимости. Извлеки следующие данные и верни ТОЛЬКО JSON без дополнительного текста:
{
  \"address\": \"Полный адрес объекта\",
  \"cadastral_number\": \"Кадастровый номер (формат XX:XX:XXXXXXX:XXX)\",
  \"area\": \"Площадь (число, квадратные метры)\",
  \"floor\": \"Этаж (число)\",
  \"floors_total\": \"Этажность дома (число)\",
  \"rooms\": \"Количество комнат (число)\",
  \"price\": \"Стоимость (число, рубли)\",
  \"property_type\": \"Тип недвижимости (квартира, дом, участок и т.д.)\",
  \"year_built\": \"Год постройки\",
  \"condition\": \"Состояние (евроремонт, требует ремонта и т.д.)\"
}

Если каких-то данных нет, верни null для этих полей.

Текст документа:\n\n" . $text;
        
        return $this->send_request($prompt);
    }
    
    private function send_request($prompt, $max_tokens = 2000, $system_message = null, $source_text = '') {
        $url = 'https://api.deepseek.com/v1/chat/completions';
        
        $headers = array(
            'Authorization: Bearer ' . $this->api_key,
            'Content-Type: application/json'
        );
        
        $messages = array();
        if (!empty($system_message)) {
            $messages[] = array('role' => 'system', 'content' => $system_message);
        }
        $messages[] = array('role' => 'user', 'content' => $prompt);
        
        $body = array(
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => (int) $max_tokens,
            'temperature' => 0,
            'response_format' => array('type' => 'json_object')
        );
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ));
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return array('success' => false, 'message' => 'Ошибка сети: ' . $error);
        }
        
        if ($http_code != 200) {
            $error_data = json_decode($response, true);
            $error_msg = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Неизвестная ошибка';
            return array('success' => false, 'message' => "Ошибка DeepSeek API ($http_code): $error_msg");
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['choices'][0]['message']['content'])) {
            return array('success' => false, 'message' => 'Некорректный ответ от DeepSeek API');
        }
        
        $content = $data['choices'][0]['message']['content'];
        $parsed_data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('success' => false, 'message' => 'Ошибка парсинга JSON из ответа DeepSeek: ' . json_last_error_msg());
        }

        if (function_exists('yvo_sanitize_parsed_person_data') && is_array($parsed_data) && is_string($source_text) && $source_text !== '') {
            $parsed_data = yvo_sanitize_parsed_person_data($parsed_data, $source_text);
        }
        
        return array(
            'success' => true,
            'parsed_data' => $parsed_data,
            'raw_response' => $content
        );
    }
    
    /**
     * Запрос к DeepSeek без JSON: возвращает сырой текст ответа (для исправления кодировки ЕГРН).
     */
    private function send_request_raw_text($prompt, $max_tokens = 8192, $system_message = null) {
        $url = 'https://api.deepseek.com/v1/chat/completions';
        $headers = array(
            'Authorization: Bearer ' . $this->api_key,
            'Content-Type: application/json'
        );
        $messages = array();
        if (!empty($system_message)) {
            $messages[] = array('role' => 'system', 'content' => $system_message);
        }
        $messages[] = array('role' => 'user', 'content' => $prompt);
        $body = array(
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => (int) $max_tokens,
            'temperature' => 0
        );
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true
        ));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            return array('success' => false, 'message' => 'Ошибка сети: ' . $error);
        }
        if ($http_code != 200) {
            $err = json_decode($response, true);
            $msg = isset($err['error']['message']) ? $err['error']['message'] : 'Неизвестная ошибка';
            return array('success' => false, 'message' => "DeepSeek API ($http_code): $msg");
        }
        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            return array('success' => false, 'message' => 'Некорректный ответ DeepSeek');
        }
        $text = trim($data['choices'][0]['message']['content']);
        $finish_reason = isset($data['choices'][0]['finish_reason']) ? $data['choices'][0]['finish_reason'] : '';
        return array('success' => true, 'text' => $text, 'finish_reason' => $finish_reason);
    }
    
    public function fix_egrn_text($text) {
        if (!$this->is_configured()) {
            return array('success' => false, 'message' => 'DeepSeek не настроен', 'text' => '');
        }
        $full = mb_substr($text, 0, 60000);
        $len = mb_strlen($full, 'UTF-8');
        $system_msg = 'Ты восстанавливаешь текст выписки ЕГРН после неправильного извлечения из PDF: это подстановка символов. Твоя задача — восстановить исходные русские буквы ПОСИМВОЛЬНО. Не перефразируй, не добавляй и не угадывай города/ФИО/адреса. Числа, даты, кадастровые номера не меняй.';
        
        $anchor_lines = array();
        if (preg_match('/\b\d{2}:\d{2}:\d{6,}(?::\d+)?\b/u', $full, $m)) {
            $anchor_lines[] = "Кадастровый номер:\t" . $m[0];
        }
        if (preg_match('/\b\d{2}\.\d{2}\.\d{4}\b/u', $full, $m)) {
            $anchor_lines[] = "Дата внесения сведений:\t" . $m[0];
        }
        if (preg_match_all('/\b\d{1,4}[.,]\d{1,4}\b(?!\.\d{4})/u', $full, $mm) && !empty($mm[0])) {
            $best = null;
            foreach ($mm[0] as $cand) {
                $val = (float) str_replace(',', '.', $cand);
                if ($best === null || $val > $best['val']) {
                    $best = array('val' => $val, 'raw' => $cand);
                }
            }
            if ($best) {
                $anchor_lines[] = "Площадь, кв.м:\t" . str_replace(',', '.', $best['raw']);
            }
        }
        $anchors_block = '';
        if (!empty($anchor_lines)) {
            $anchors_block = "Обязательные строки (должны присутствовать в ответе В ТОЧНОМ ВИДЕ):\n- " . implode("\n- ", $anchor_lines) . "\n\n";
        }
        
        $prompt_instruction = "Фрагмент выписки ЕГРН с неправильной таблицей символов после извлечения из PDF. Восстанови исходный русский текст ПОСИМВОЛЬНО. Ничего не придумывай. Числа/даты не изменяй.\n\n" . $anchors_block . "Фрагмент:\n\n";

        if ($len <= 10000) {
            $prompt = $prompt_instruction . $full;
            $out = $this->send_request_raw_text($prompt, 8192, $system_msg);
            if (empty($out['success']) || !isset($out['text'])) {
                return array('success' => false, 'message' => isset($out['message']) ? $out['message'] : 'Не удалось исправить текст', 'text' => '');
            }
            $result = trim($out['text']);
            if (strlen($result) < 100) {
                return array('success' => false, 'message' => 'Ответ слишком короткий', 'text' => '');
            }
            return array('success' => true, 'text' => $result);
        }

        $chunk_size = 6000;
        $parts = array();
        for ($offset = 0; $offset < $len; $offset += $chunk_size) {
            $chunk = mb_substr($full, $offset, $chunk_size, 'UTF-8');
            if (trim($chunk) === '') continue;
            $prompt = $prompt_instruction . $chunk;
            $out = $this->send_request_raw_text($prompt, 8192, $system_msg);
            if (empty($out['success']) || !isset($out['text'])) {
                if ($offset === 0) {
                    return array('success' => false, 'message' => isset($out['message']) ? $out['message'] : 'Не удалось исправить текст', 'text' => '');
                }
                $parts[] = $chunk;
                continue;
            }
            $parts[] = $out['text'];
        }
        $result = implode("\n\n", $parts);
        if (strlen($result) < 100) {
            return array('success' => false, 'message' => 'Ответ слишком короткий', 'text' => '');
        }
        return array('success' => true, 'text' => $result);
    }
    
    public function translate_text($text, $target = 'ru') {
        if (!$this->is_configured()) {
            return array('success' => false, 'message' => 'DeepSeek не настроен', 'text' => '');
        }
        $langs = array('ru' => 'русский', 'en' => 'английский', 'kk' => 'казахский', 'uk' => 'украинский', 'de' => 'немецкий', 'fr' => 'французский', 'es' => 'испанский', 'zh' => 'китайский');
        $target_name = isset($langs[$target]) ? $langs[$target] : $target;
        $full = mb_substr($text, 0, 40000);
        $chunk_size = 8000;
        $len = mb_strlen($full, 'UTF-8');
        $parts = array();
        $prompt_instruction = "Переведи следующий текст на " . $target_name . " язык. Сохраняй числа, даты, кадастровые номера. Ответ — только перевод.\n\nТекст:\n\n";
        for ($offset = 0; $offset < $len; $offset += $chunk_size) {
            $chunk = mb_substr($full, $offset, $chunk_size, 'UTF-8');
            if (trim($chunk) === '') continue;
            $prompt = $prompt_instruction . $chunk;
            $out = $this->send_request_raw_text($prompt, 8192);
            if (empty($out['success']) || !isset($out['text'])) {
                if ($offset === 0) {
                    return array('success' => false, 'message' => isset($out['message']) ? $out['message'] : 'Ошибка перевода', 'text' => '');
                }
                $parts[] = $chunk;
                continue;
            }
            $parts[] = $out['text'];
        }
        $result = implode("\n\n", $parts);
        if (strlen($result) < 10) {
            return array('success' => false, 'message' => 'Ответ слишком короткий', 'text' => '');
        }
        return array('success' => true, 'text' => $result);
    }
    
    public function parse_egrn_data($text) {
        if (!$this->is_configured()) {
            return array('success' => false, 'message' => 'DeepSeek не настроен', 'property' => null, 'participants' => array());
        }
        $prompt = "Из текста выписки ЕГРН извлеки данные и верни ТОЛЬКО JSON без дополнительного текста.\n\nproperty: address, cadastral_number, area, floor, floors_total, rooms, living_area, property_type, share_in_right, property_right_info (формат: «Собственность, номер, дата»), property_right_date, ownership_basis_documents (несколько оснований через запятую).\nparticipants: [{full_name, share}].\negrn_check (справка, не для договора): registration_basis, restrictions_summary, bank_info, encumbrance_type, restriction_term, right_start_date, power_of_attorney, additional_info, has_mortgage (да/нет).\n\nФормат: {\"property\":{...},\"participants\":[...],\"egrn_check\":{...}}\n\nТекст выписки:\n\n" . mb_substr($text, 0, 30000);
        $res = $this->send_request($prompt);
        if (empty($res['success']) || empty($res['parsed_data'])) {
            return array('success' => false, 'message' => isset($res['message']) ? $res['message'] : 'Ошибка извлечения', 'property' => null, 'participants' => array());
        }
        $d = $res['parsed_data'];
        $property = isset($d['property']) && is_array($d['property']) ? $d['property'] : null;
        $participants = isset($d['participants']) && is_array($d['participants']) ? $d['participants'] : array();
        $egrn_check = isset($d['egrn_check']) && is_array($d['egrn_check']) ? $d['egrn_check'] : array();
        if ($property && is_array($property)) {
            $extracted_address = self::extract_address_line_from_egrn_text($text);
            if ($extracted_address !== null && mb_strlen($extracted_address) > 20) {
                $property['address'] = $extracted_address;
            }
        }
        return array('success' => true, 'property' => $property, 'participants' => $participants, 'egrn_check' => $egrn_check);
    }

    /**
     * Локальный парсер ЕГРН из уже исправленного русского текста (без ИИ).
     */
    public function parse_egrn_data_local($text) {
        if (!is_string($text) || trim($text) === '') {
            return array('success' => false, 'message' => 'Пустой текст', 'property' => null, 'participants' => array(), 'egrn_check' => array());
        }
        if (!function_exists('yvo_parse_egrn_text_local')) {
            $egrn_file = defined('YVO_PLUGIN_DIR') ? YVO_PLUGIN_DIR . 'includes/egrn-text-parser.php' : dirname(__FILE__) . '/egrn-text-parser.php';
            if (is_file($egrn_file)) {
                require_once $egrn_file;
            }
        }
        if (function_exists('yvo_parse_egrn_text_local')) {
            $parsed = yvo_parse_egrn_text_local($text);
            return array(
                'success' => true,
                'property' => !empty($parsed['property']) ? $parsed['property'] : null,
                'participants' => isset($parsed['participants']) && is_array($parsed['participants']) ? $parsed['participants'] : array(),
                'egrn_check' => isset($parsed['egrn_check']) && is_array($parsed['egrn_check']) ? $parsed['egrn_check'] : array(),
            );
        }
        return array('success' => false, 'message' => 'Парсер ЕГРН недоступен', 'property' => null, 'participants' => array(), 'egrn_check' => array());
    }

    public static function extract_participants_from_egrn_text($text) {
        return self::extract_participants_local($text);
    }

    public static function decode_egrn_raw_text_with_header_map($raw_text) {
        if (!is_string($raw_text) || $raw_text === '') return '';
        $raw = str_replace("\r\n", "\n", $raw_text);
        $raw = str_replace("\r", "\n", $raw);
        $lines = explode("\n", $raw);
        if (count($lines) < 3) return '';
        $expected_by_index = array(
            0 => 'Кадастровый номер:',
            1 => 'Дата внесения сведений:',
            2 => 'Ранее присвоенный государственный учетный номер:',
            3 => 'Адрес:',
            4 => 'Площадь, кв.м:',
            5 => 'Категория земель:',
            6 => 'Разрешенное использование:',
        );
        $map = array();
        foreach ($expected_by_index as $idx => $expected_label) {
            if (!isset($lines[$idx])) continue;
            $line = (string) $lines[$idx];
            $parts = explode("\t", $line);
            $encoded_label = isset($parts[0]) ? rtrim($parts[0]) : '';
            if ($encoded_label === '') continue;
            $enc_chars = str_split($encoded_label);
            $exp_chars = preg_split('//u', $expected_label, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($exp_chars) && count($enc_chars) === count($exp_chars)) {
                foreach ($enc_chars as $i => $ec) {
                    $dc = $exp_chars[$i];
                    if ($ec !== '' && $dc !== '') $map[$ec] = $dc;
                }
            }
        }
        if (empty($map)) return '';
        $out = '';
        for ($i = 0; $i < strlen($raw); $i++) {
            $ch = $raw[$i];
            $out .= isset($map[$ch]) ? $map[$ch] : $ch;
        }
        if (mb_strpos($out, 'Кадастровый номер:') === false || mb_strpos($out, 'Адрес:') === false) return '';
        return $out;
    }

    private static function extract_participants_local($text) {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $text);
        $lines = preg_split('/\r\n|\n|\r/u', (string) $text);
        if (!$lines) $lines = array((string) $text);
        $name_re = '/\b[А-ЯЁ][а-яё]+(?:-[А-ЯЁ][а-яё]+)?\s+[А-ЯЁ][а-яё]+(?:-[А-ЯЁ][а-яё]+)?\s+[А-ЯЁ][а-яё]+(?:-[А-ЯЁ][а-яё]+)?\b/u';
        $share_re = '/\b\d+\s*\/\s*\d+\b|\b\d{1,3}\s*%\b/u';
        $candidates = array();
        $windows = array();
        foreach ($lines as $i => $line) {
            if (preg_match('/Правообладател|Сведения о правообладателе/u', $line)) {
                $windows[] = array('start' => $i, 'end' => min(count($lines) - 1, $i + 60));
            }
        }
        if (empty($windows)) $windows[] = array('start' => 0, 'end' => min(count($lines) - 1, 250));
        foreach ($windows as $w) {
            for ($i = $w['start']; $i <= $w['end']; $i++) {
                $line = trim((string) $lines[$i]);
                if ($line === '' || !preg_match($name_re, $line, $nm)) continue;
                if (preg_match('/Получатель\s+выписки|сотрудник\s+РГАУ|МФЦ|Операционного\s+зала|ФЕДЕРАЛЬНАЯ\s+СЛУЖБА|Кутлуг|должность\s+сотрудника/ui', $line)) {
                    continue;
                }
                $full_name = $nm[0];
                $share = null;
                if (preg_match($share_re, $line, $sm)) $share = preg_replace('/\s+/u', '', $sm[0]);
                else {
                    $near = array();
                    if ($i > 0) $near[] = $lines[$i - 1];
                    $near[] = $line;
                    if ($i + 1 < count($lines)) $near[] = $lines[$i + 1];
                    if ($i + 2 < count($lines)) $near[] = $lines[$i + 2];
                    $near_text = implode(' ', $near);
                    if (preg_match($share_re, $near_text, $sm)) $share = preg_replace('/\s+/u', '', $sm[0]);
                }
                $key = function_exists('mb_strtolower') ? mb_strtolower($full_name, 'UTF-8') : strtolower($full_name);
                $candidates[$key] = array('full_name' => $full_name, 'share' => $share);
            }
        }
        $out = array_values($candidates);
        if (count($out) > 10) $out = array_slice($out, 0, 10);
        return $out;
    }
    
    public function extract_egrn_structured_from_raw($raw_text) {
        if (!$this->is_configured()) {
            return array('success' => false, 'message' => 'DeepSeek не настроен', 'property' => null, 'participants' => array(), 'dates' => array());
        }
        $text = mb_substr($raw_text, 0, 50000, 'UTF-8');
        $system_msg = 'Ты обрабатываешь выписку ЕГРН. Текст может быть с поломанной кодировкой. Извлеки только структурированные данные. Ответ — ТОЛЬКО один JSON.';
        $prompt = "Из текста выписки ЕГРН извлеки и верни ТОЛЬКО JSON: {\"property\": {\"address\": \"...\", \"cadastral_number\": \"...\", \"area\": число или null, \"property_type\": \"...\"}, \"participants\": [{\"full_name\": \"ФИО\", \"share\": \"доля\"}], \"dates\": [\"дд.мм.гггг\"]}. Адрес — только из раздела об объекте недвижимости. Числа и даты не меняй.\n\nТекст выписки:\n\n" . $text;
        $res = $this->send_request($prompt, 4000, $system_msg);
        if (empty($res['success']) || empty($res['parsed_data'])) {
            return array('success' => false, 'message' => isset($res['message']) ? $res['message'] : 'Ошибка извлечения', 'property' => null, 'participants' => array(), 'dates' => array());
        }
        $d = $res['parsed_data'];
        return array(
            'success' => true,
            'property' => isset($d['property']) && is_array($d['property']) ? $d['property'] : null,
            'participants' => isset($d['participants']) && is_array($d['participants']) ? $d['participants'] : array(),
            'dates' => isset($d['dates']) && is_array($d['dates']) ? $d['dates'] : array()
        );
    }
    
    private static function extract_address_line_from_egrn_text($text) {
        if (!is_string($text) || $text === '') return null;
        $lines = preg_split('/\r\n|\n|\r/u', $text);
        $candidates = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if (mb_strlen($line) < 30) continue;
            if (mb_stripos($line, 'Российская Федерация') !== 0) continue;
            if (!preg_match('/ул\.|улица|д\.\s*\d|кв\.\s*\d/ui', $line)) continue;
            $candidates[] = array('line' => $line, 'len' => mb_strlen($line));
        }
        if (empty($candidates)) return null;
        foreach ($candidates as $c) {
            $l = $c['line'];
            if (preg_match('/Республика Башкортостан|Мирзагитова|г\.\s*Уфа|Уфа,?\s*ул\./ui', $l)) return $l;
        }
        usort($candidates, function ($a, $b) { return $b['len'] - $a['len']; });
        return $candidates[0]['line'];
    }
    
    public function test_api() {
        if (empty($this->api_key)) return array('success' => false, 'message' => 'API ключ не настроен');
        $url = 'https://api.deepseek.com/v1/chat/completions';
        $headers = array('Authorization: Bearer ' . $this->api_key, 'Content-Type: application/json');
        $body = array('model' => $this->model, 'messages' => array(array('role' => 'user', 'content' => 'Привет! Ответь "Готов к работе!"')), 'max_tokens' => 50, 'temperature' => 0.1);
        $ch = curl_init();
        curl_setopt_array($ch, array(CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) return array('success' => false, 'message' => 'Ошибка сети: ' . $error);
        if ($http_code != 200) return array('success' => false, 'message' => "Ошибка API ($http_code)");
        $data = json_decode($response, true);
        return array('success' => true, 'response' => isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : 'Ответ получен');
    }
}
