<?php
/**
 * Mistral OCR — резерв, если Yandex Vision вернул ошибку.
 * Ключ: option yvo_mistral_api_key (или временный fallback в коде).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Основной провайдер OCR: всегда yandex, пока явно не задано иное. */
function yvo_ocr_provider() {
    $p = (string) get_option('yvo_ocr_provider', 'yandex');
    return ($p === 'mistral') ? 'mistral' : 'yandex';
}

/** @deprecated use yvo_mistral_fallback_available — Mistral не основной. */
function yvo_ocr_provider_is_mistral() {
    return yvo_ocr_provider() === 'mistral';
}

function yvo_mistral_api_key() {
    $key = trim((string) get_option('yvo_mistral_api_key', ''));
    if ($key !== '') {
        return $key;
    }
    // Временный резервный ключ.
    return 'dh4J8g5UcIdpSQX8b6voliXuuGhnIwPb';
}

function yvo_mistral_fallback_available() {
    return yvo_mistral_api_key() !== '' && function_exists('curl_init');
}

function yvo_mistral_ocr_model() {
    $m = trim((string) get_option('yvo_mistral_ocr_model', 'mistral-ocr-latest'));
    return $m !== '' ? $m : 'mistral-ocr-latest';
}

/**
 * MIME + data-URL для файла.
 *
 * @return array{0:string,1:string}|null [mime, data_url]
 */
function yvo_mistral_file_data_url($path) {
    if (!is_string($path) || !is_readable($path)) {
        return null;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime_map = array(
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
    );
    if (!isset($mime_map[$ext])) {
        return null;
    }
    $bin = @file_get_contents($path);
    if ($bin === false || $bin === '') {
        return null;
    }
    $mime = $mime_map[$ext];
    return array($mime, 'data:' . $mime . ';base64,' . base64_encode($bin));
}

/**
 * OCR файла (PDF или изображение) через Mistral /v1/ocr.
 *
 * @return array{success:bool,text?:string,message?:string,pages?:int,engine?:string,filename?:string}
 */
function yvo_mistral_ocr_file($path) {
    $key = yvo_mistral_api_key();
    if ($key === '') {
        return array('success' => false, 'message' => 'Mistral API ключ не задан');
    }
    $data = yvo_mistral_file_data_url($path);
    if ($data === null) {
        return array('success' => false, 'message' => 'Файл недоступен или формат не поддерживается Mistral OCR');
    }
    list($mime, $data_url) = $data;
    $is_pdf = ($mime === 'application/pdf');
    $document = $is_pdf
        ? array('type' => 'document_url', 'document_url' => $data_url)
        : array('type' => 'image_url', 'image_url' => $data_url);

    $body = array(
        'model' => yvo_mistral_ocr_model(),
        'document' => $document,
        'include_image_base64' => false,
    );

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => 'https://api.mistral.ai/v1/ocr',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Accept: application/json',
        ),
        CURLOPT_POSTFIELDS => wp_json_encode($body),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return array('success' => false, 'message' => 'Mistral сеть: ' . $err);
    }
    $json = json_decode((string) $response, true);
    if ($http < 200 || $http >= 300) {
        $msg = '';
        if (is_array($json)) {
            if (!empty($json['message'])) {
                $msg = (string) $json['message'];
            } elseif (!empty($json['detail'])) {
                $msg = is_string($json['detail']) ? $json['detail'] : wp_json_encode($json['detail']);
            }
        }
        if ($msg === '') {
            $msg = substr((string) $response, 0, 300);
        }
        return array('success' => false, 'message' => "Mistral OCR HTTP $http: $msg");
    }
    if (!is_array($json)) {
        return array('success' => false, 'message' => 'Mistral OCR: пустой ответ');
    }

    $parts = array();
    $pages = isset($json['pages']) && is_array($json['pages']) ? $json['pages'] : array();
    foreach ($pages as $i => $page) {
        $md = '';
        if (is_array($page) && isset($page['markdown'])) {
            $md = trim((string) $page['markdown']);
        } elseif (is_array($page) && isset($page['text'])) {
            $md = trim((string) $page['text']);
        }
        if ($md === '') {
            continue;
        }
        $num = $i + 1;
        $parts[] = "--- Страница $num ---\n" . $md;
    }
    $text = trim(implode("\n\n", $parts));
    if ($text === '' && !empty($json['text'])) {
        $text = trim((string) $json['text']);
    }
    if ($text === '') {
        return array('success' => false, 'message' => 'Mistral OCR: текст не извлечён');
    }
    return array(
        'success' => true,
        'text' => $text,
        'pages' => max(1, count($pages)),
        'filename' => basename($path),
        'engine' => 'mistral',
    );
}

/**
 * Если Yandex вернул ошибку — пробуем Mistral.
 *
 * @param string               $path
 * @param array<string,mixed>  $yandex_result
 * @return array<string,mixed>
 */
function yvo_ocr_with_mistral_fallback($path, $yandex_result) {
    if (!is_array($yandex_result)) {
        $yandex_result = array('success' => false, 'message' => 'Yandex OCR failed');
    }
    if (!empty($yandex_result['success'])) {
        return $yandex_result;
    }
    if (!yvo_mistral_fallback_available()) {
        return $yandex_result;
    }
    $mistral = yvo_mistral_ocr_file($path);
    if (!empty($mistral['success'])) {
        $err = isset($yandex_result['message']) ? (string) $yandex_result['message'] : 'error';
        $mistral['text'] = "--- Mistral OCR (резерв; Yandex: $err) ---\n\n" . $mistral['text'];
        return $mistral;
    }
    $m_err = isset($mistral['message']) ? (string) $mistral['message'] : 'failed';
    $yandex_result['message'] = (isset($yandex_result['message']) ? $yandex_result['message'] . ' | ' : '')
        . 'Mistral: ' . $m_err;
    return $yandex_result;
}
