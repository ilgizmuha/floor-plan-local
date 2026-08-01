<?php
/**
 * Правила перепроверки полей формы после подстановки OCR.
 */

if (!function_exists('yvo_validate_person_fields')) {
    /**
     * @param array<string, mixed> $data
     * @param string               $tab_id
     * @return array<int, array<string, string>>
     */
    function yvo_validate_person_fields($data, $tab_id = 'person') {
        $issues = array();
        if (!is_array($data)) {
            return $issues;
        }
        $push = static function ($field, $severity, $code, $message, $value = '') use (&$issues, $tab_id) {
            $issues[] = array(
                'tab' => (string) $tab_id,
                'field' => (string) $field,
                'severity' => $severity,
                'code' => (string) $code,
                'message' => (string) $message,
                'value' => (string) $value,
            );
        };
        $name = trim((string) ($data['full_name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/u', $name);
            $pc = is_array($parts) ? count($parts) : 0;
            if ($pc < 2 || $pc > 5) {
                $push('full_name', 'error', 'name_tokens', 'ФИО: ожидается 2–4 слова', $name);
            }
            if (preg_match('/Минцифры|Росреестр|Действителен|Сертификат|Владелец/ui', $name)) {
                $push('full_name', 'error', 'name_stop', 'ФИО похоже на служебную строку документа', $name);
            }
        }
        $series = preg_replace('/\D/u', '', (string) ($data['passport_series'] ?? ''));
        if ($series !== '' && strlen($series) !== 4) {
            $push('passport_series', 'error', 'passport_series', 'Серия паспорта: 4 цифры', (string) ($data['passport_series'] ?? ''));
        }
        $number = preg_replace('/\D/u', '', (string) ($data['passport_number'] ?? ''));
        if ($number !== '' && strlen($number) !== 6) {
            $push('passport_number', 'error', 'passport_number', 'Номер паспорта: 6 цифр', (string) ($data['passport_number'] ?? ''));
        }
        $dept = trim((string) ($data['department_code'] ?? ''));
        if ($dept !== '' && !preg_match('/^\d{3}-\d{3}$/u', $dept)) {
            $push('department_code', 'error', 'department_code', 'Код подразделения: формат XXX-XXX', $dept);
        }
        foreach (array('passport_date', 'birth_date') as $dk) {
            $raw = trim((string) ($data[$dk] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/u', $raw, $dm)) {
                $push($dk, 'error', 'date_format', 'Дата: формат ДД.ММ.ГГГГ', $raw);
                continue;
            }
            $ts = strtotime($dm[3] . '-' . $dm[2] . '-' . $dm[1]);
            if ($ts === false || $ts > time() + 86400) {
                $push($dk, 'error', 'date_future', 'Дата некорректна или в будущем', $raw);
            }
        }
        $snils = trim((string) ($data['snils'] ?? ''));
        if ($snils !== '') {
            $sd = preg_replace('/\D/u', '', $snils);
            if (strlen($sd) !== 11 && !preg_match('/^\d{3}-\d{3}-\d{3}\s*\d{2}$/u', $snils)) {
                $push('snils', 'error', 'snils', 'СНИЛС: формат XXX-XXX-XXX XX', $snils);
            }
        }
        $inn = preg_replace('/\D/u', '', (string) ($data['inn'] ?? ''));
        if ($inn !== '' && strlen($inn) !== 10 && strlen($inn) !== 12) {
            $push('inn', 'error', 'inn', 'ИНН: 10 или 12 цифр', (string) ($data['inn'] ?? ''));
        }
        return $issues;
    }
}

if (!function_exists('yvo_validate_property_fields')) {
    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, string>>
     */
    function yvo_validate_property_fields($data) {
        $issues = array();
        if (!is_array($data)) {
            return $issues;
        }
        $cad = preg_replace('/\s+/u', '', (string) ($data['cadastral_number'] ?? ''));
        if ($cad !== '' && !preg_match('/^\d{2}:\d{2}:\d{6,}:\d+$/u', $cad)) {
            $issues[] = array(
                'tab' => 'property',
                'field' => 'cadastral_number',
                'severity' => 'error',
                'code' => 'cadastral',
                'message' => 'Кадастровый номер: формат XX:XX:……:…',
                'value' => (string) ($data['cadastral_number'] ?? ''),
            );
        }
        return $issues;
    }
}
