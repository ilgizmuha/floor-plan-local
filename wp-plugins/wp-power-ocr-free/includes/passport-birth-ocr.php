<?php
/**
 * OCR паспортов и свидетельств о рождении: MRZ, шумные сканы.
 */

if (!defined('ABSPATH')) {
    exit;
}

function yvo_ocr_mb_strlen($s) {
    return function_exists('mb_strlen') ? mb_strlen((string) $s, 'UTF-8') : strlen((string) $s);
}

function yvo_ocr_mb_strtolower($s) {
    $s = (string) $s;
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    return strtr($s, array(
        'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е', 'Ё' => 'ё',
        'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й', 'К' => 'к', 'Л' => 'л', 'М' => 'м',
        'Н' => 'н', 'О' => 'о', 'П' => 'п', 'Р' => 'р', 'С' => 'с', 'Т' => 'т', 'У' => 'у',
        'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч', 'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ',
        'Ы' => 'ы', 'Ь' => 'ь', 'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я',
    ));
}

function yvo_ocr_mb_strtoupper($s) {
    $s = (string) $s;
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($s, 'UTF-8');
    }
    return strtr($s, array(
        'а' => 'А', 'б' => 'Б', 'в' => 'В', 'г' => 'Г', 'д' => 'Д', 'е' => 'Е', 'ё' => 'Ё',
        'ж' => 'Ж', 'з' => 'З', 'и' => 'И', 'й' => 'Й', 'к' => 'К', 'л' => 'Л', 'м' => 'М',
        'н' => 'Н', 'о' => 'О', 'п' => 'П', 'р' => 'Р', 'с' => 'С', 'т' => 'Т', 'у' => 'У',
        'ф' => 'Ф', 'х' => 'Х', 'ц' => 'Ц', 'ч' => 'Ч', 'ш' => 'Ш', 'щ' => 'Щ', 'ъ' => 'Ъ',
        'ы' => 'Ы', 'ь' => 'Ь', 'э' => 'Э', 'ю' => 'Ю', 'я' => 'Я',
    ));
}

/**
 * Схлопывает letter-spacing OCR: «А Л Е К С Е Й» → «АЛЕКСЕЙ»,
 * «Р О С С И Й С К А Я» → «РОССИЙСКАЯ».
 * Только внутри одной строки (не через \n), чтобы не склеивать блоки.
 */
function yvo_collapse_letter_spaced_ocr($text) {
    if (!is_string($text) || $text === '') {
        return '';
    }
    $lines = preg_split('/\r\n|\r|\n/u', $text);
    if (!is_array($lines)) {
        return $text;
    }
    $out = array();
    foreach ($lines as $line) {
        $out[] = preg_replace_callback(
            '/(?<![\p{L}\d])((?:[A-Za-zА-ЯЁа-яё] ){2,}[A-Za-zА-ЯЁа-яё])(?![\p{L}\d])/u',
            function ($m) {
                $parts = explode(' ', trim($m[1]));
                if (count($parts) < 3) {
                    return $m[1];
                }
                foreach ($parts as $p) {
                    if (yvo_ocr_mb_strlen($p) !== 1) {
                        return $m[1];
                    }
                }
                return implode('', $parts);
            },
            $line
        );
    }
    return implode("\n", $out);
}

/**
 * Нормализация OCR паспорта перед regex-парсингом.
 */
function yvo_normalize_passport_ocr_text($text) {
    if (!is_string($text) || $text === '') {
        return '';
    }
    if (function_exists('yvo_strip_pdf_ocr_boilerplate')) {
        $text = yvo_strip_pdf_ocr_boilerplate($text);
    }
    return yvo_collapse_letter_spaced_ocr($text);
}

/**
 * Транслит MRZ (латиница) → кириллица для ФИО.
 */
function yvo_mrz_latin_to_cyrillic($latin) {
    $latin = strtoupper(preg_replace('/[^A-Z<]/', '', (string) $latin));
    $latin = str_replace('<', '', $latin);
    if ($latin === '') {
        return '';
    }
    static $map = null;
    if ($map === null) {
        $map = array(
            'SHCH' => 'Щ', 'SCH' => 'Щ', 'YO' => 'Ё', 'ZH' => 'Ж', 'KH' => 'Х', 'TS' => 'Ц',
            'CH' => 'Ч', 'SH' => 'Ш', 'YU' => 'Ю', 'YA' => 'Я', 'YE' => 'Е',
            'A' => 'А', 'B' => 'Б', 'V' => 'В', 'G' => 'Г', 'D' => 'Д', 'E' => 'Е',
            'Z' => 'З', 'I' => 'И', 'J' => 'Й', 'K' => 'К', 'L' => 'Л', 'M' => 'М',
            'N' => 'Н', 'O' => 'О', 'P' => 'П', 'R' => 'Р', 'S' => 'С', 'T' => 'Т',
            'U' => 'У', 'F' => 'Ф', 'H' => 'Х', 'C' => 'С', 'Y' => 'Ы', 'W' => 'В',
            'Q' => 'К', 'X' => 'КС',
        );
    }
    $out = '';
    $i = 0;
    $len = strlen($latin);
    while ($i < $len) {
        $matched = false;
        foreach (array('SHCH', 'SCH', 'YO', 'ZH', 'KH', 'TS', 'CH', 'SH', 'YU', 'YA', 'YE') as $d) {
            $dl = strlen($d);
            if (substr($latin, $i, $dl) === $d) {
                $out .= $map[$d];
                $i += $dl;
                $matched = true;
                break;
            }
        }
        if ($matched) {
            continue;
        }
        $ch = $latin[$i];
        $out .= isset($map[$ch]) ? $map[$ch] : '';
        $i++;
    }
    return $out;
}

/**
 * Разбор MRZ российского паспорта из OCR.
 *
 * @return array<string,string>
 */
function yvo_parse_passport_mrz_from_text($text) {
    $out = array();
    if (!is_string($text) || $text === '') {
        return $out;
    }
    $raw = strtoupper(str_replace(array("\r\n", "\r"), "\n", $text));
    $raw = preg_replace('/[^A-Z0-9<\n]/', '', $raw);
    if (!preg_match('/P[A-Z]?RUS([A-Z<]{2,})<<([A-Z<]{2,})/u', $raw, $name_m)) {
        return $out;
    }
    $surname_lat = trim(str_replace('<', ' ', $name_m[1]));
    $given_lat = trim(str_replace('<', ' ', $name_m[2]));
    $given_parts = preg_split('/\s+/', $given_lat);
    $first_lat = isset($given_parts[0]) ? $given_parts[0] : '';
    $patr_lat = isset($given_parts[1]) ? $given_parts[1] : '';
    // Часто в конце отчества OCR заменяет CH на цифру 3.
    $patr_lat = preg_replace('/3$/', 'CH', $patr_lat);
    // Цифры в зоне имени MRZ — типичный мусор OCR.
    $surname_lat = preg_replace('/\d+/', '', $surname_lat);
    $first_lat = preg_replace('/\d+/', '', $first_lat);
    $patr_lat = preg_replace('/\d+/', '', $patr_lat);

    $surname = yvo_mrz_latin_to_cyrillic($surname_lat);
    $first = yvo_mrz_latin_to_cyrillic($first_lat);
    $patr = yvo_mrz_latin_to_cyrillic($patr_lat);
    if ($surname !== '' && $first !== '') {
        $out['full_name'] = trim($surname . ' ' . $first . ($patr !== '' ? ' ' . $patr : ''));
    }

    if (preg_match('/\b(\d{9})(\d)RUS(\d{6})([MF])/u', $raw, $doc)) {
        $num9 = $doc[1];
        $out['passport_series'] = substr($num9, 0, 2) . substr($num9, 2, 2);
        $out['passport_number'] = substr($num9, 4, 6);
        $bd = $doc[3];
        $yy = (int) substr($bd, 0, 2);
        $year = $yy >= 30 ? (1900 + $yy) : (2000 + $yy);
        $out['birth_date'] = substr($bd, 4, 2) . '.' . substr($bd, 2, 2) . '.' . $year;
        $out['sex'] = ($doc[4] === 'F') ? 'жен' : 'муж';
    } elseif (preg_match('/\b(\d{2})(\d{2})(\d{6})(\d)?RUS(\d{6})([MF])/u', $raw, $doc2)) {
        $out['passport_series'] = $doc2[1] . $doc2[2];
        $out['passport_number'] = $doc2[3];
        $bd = $doc2[5];
        $yy = (int) substr($bd, 0, 2);
        $year = $yy >= 30 ? (1900 + $yy) : (2000 + $yy);
        $out['birth_date'] = substr($bd, 4, 2) . '.' . substr($bd, 2, 2) . '.' . $year;
    }

    return $out;
}

/**
 * Дата штампа прописки → дд.мм.гггг (или '').
 */
function yvo_passport_registration_stamp_date($chunk) {
    if (function_exists('yvo_parse_russian_words_date')) {
        $d = yvo_parse_russian_words_date($chunk);
        if ($d !== '') {
            return $d;
        }
    }
    if (preg_match('/(\d{1,2})\s+(января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)\s+(\d{4})/ui', $chunk, $m)) {
        static $months = array(
            'января' => '01', 'февраля' => '02', 'марта' => '03', 'апреля' => '04',
            'мая' => '05', 'июня' => '06', 'июля' => '07', 'августа' => '08',
            'сентября' => '09', 'октября' => '10', 'ноября' => '11', 'декабря' => '12',
        );
        $monKey = yvo_ocr_mb_strtolower($m[2]);
        if (isset($months[$monKey])) {
            return str_pad((string) (int) $m[1], 2, '0', STR_PAD_LEFT) . '.' . $months[$monKey] . '.' . $m[3];
        }
    }
    if (preg_match('/\b(\d{2}\.\d{2}\.(?:19|20)\d{2})\b/u', $chunk, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Разбор одного штампа «ЗАРЕГИСТРИРОВАН».
 *
 * @return array{address:string,date:string}
 */
function yvo_parse_passport_registration_stamp($chunk) {
    $chunk = trim((string) $chunk);
    $chunk = preg_replace('/СНЯТ\s+С\s+РЕГИСТРАЦИОННОГО[\s\S]*$/ui', '', $chunk);
    $city = '';
    $street = '';
    $house = '';
    $building = '';
    $apt = '';
    $region = '';

    if (preg_match('/гор\.?\s*Уф/ui', $chunk) || preg_match('/г\.?\s*Уф/ui', $chunk)) {
        $city = 'г. Уфа';
    } elseif (preg_match('/Рег-?н\s*:?\s*([^\n]{5,80})/ui', $chunk, $m)) {
        $region = trim($m[1]);
    } elseif (preg_match('/Республика\s+Башкортостан/ui', $chunk)) {
        $region = 'Респ. Башкортостан';
    }

    if (preg_match('/(?:Улица|ул\.?)\s*:?\s*([^\n,]{2,60})/ui', $chunk, $m)) {
        $street = trim(preg_replace('/^(ул\.?\s*)/ui', '', trim($m[1])));
        $street = 'ул. ' . $street;
    } elseif (preg_match('/ЗАРЕГИСТРИРОВАН\s*\n\s*([А-ЯЁа-яё][А-ЯЁа-яё\-\s]{3,60}?)\s*\n\s*дом/ui', $chunk, $m)) {
        $street = 'ул. ' . trim($m[1]);
    } elseif (preg_match('/\n\s*([А-ЯЁ][А-ЯЁа-яё]+(?:\s+[А-ЯЁа-яё]+){0,3})\s*\n\s*дом\s*№/ui', $chunk, $m)) {
        $candidate = trim($m[1]);
        if (!preg_match('/УФМС|МВД|ОТДЕЛ|РАЙОН|ЗАРЕГИСТРИРОВАН|подпись|Росси/ui', $candidate)) {
            $street = 'ул. ' . $candidate;
        }
    }

    if (preg_match('/дом\s*(?:№|#)?\s*(\d+[А-Яа-яA-Za-z\/\-]*)\s*(?:кор(?:п|п\.|пус|\.)?\s*\.?\s*|корп\.?\s*)(\d+[А-Яа-яA-Za-z\/\-]*)/ui', $chunk, $m)) {
        $house = 'д. ' . trim($m[1]);
        $building = 'корп. ' . trim($m[2]);
    } elseif (preg_match('/дом\s*(?:№|#)?\s*(\d+[А-Яа-яA-Za-z\/\-]*)/ui', $chunk, $m)) {
        $house = 'д. ' . trim($m[1]);
    } elseif (preg_match('/(?:^|[^\p{L}])д\.?\s*(?:№|#)?\s*(\d+[А-Яа-яA-Za-z\/\-]*)/ui', $chunk, $m)) {
        $house = 'д. ' . trim($m[1]);
    }
    if ($building === '' && preg_match('/кор(?:п|п\.|пус|\.)?\s*\.?\s*(\d+)/ui', $chunk, $m)) {
        $building = 'корп. ' . trim($m[1]);
    }

    if (preg_match('/(?:кв\.?|квартира)\s*(?:№|#)?\s*:?\s*(\d+)/ui', $chunk, $m)) {
        $apt = 'кв. ' . trim($m[1]);
    } elseif (preg_match('/дом\s*(?:№|#)?\s*\d+[^\n]*\n\s*(\d{1,4})\s*(?:\n|$)/ui', $chunk, $m)) {
        // В штампе часто квартира одной цифрой на следующей строке после «дом № … корп …».
        $apt = 'кв. ' . trim($m[1]);
    }

    $place = '';
    if (preg_match('/(?:Пункт|с\.|село|дер\.|д\.)\s*:?\s*([А-Яа-яЁё0-9\-\s]{2,40})/ui', $chunk, $m)) {
        $pl = trim($m[1]);
        if (!preg_match('/^\d/u', $pl)) {
            $place = $pl;
        }
    }

    $date = yvo_passport_registration_stamp_date($chunk);
    $bits = array_filter(array($region, $city, $place, $street, $house, $building, $apt));
    return array(
        'address' => implode(', ', $bits),
        'date' => $date,
    );
}

/**
 * Регистрация по штампам прописки: берём ПОСЛЕДНЮЮ запись «ЗАРЕГИСТРИРОВАН»
 * (не «снят с учёта»), с максимальной датой.
 */
function yvo_extract_passport_registration_from_text($text) {
    if (!is_string($text) || $text === '') {
        return '';
    }
    // Разбиваем по штампам регистрации; блоки «снят» отбрасываем.
    $chunks = preg_split('/(?=ЗАРЕГИСТРИРОВАН)/ui', $text);
    if (!is_array($chunks)) {
        return '';
    }
    $candidates = array();
    foreach ($chunks as $chunk) {
        if (!preg_match('/ЗАРЕГИСТРИРОВАН/ui', $chunk)) {
            continue;
        }
        // Это штамп снятия, не прописка.
        if (preg_match('/^\s*СНЯТ\s+С\s+РЕГИСТРАЦИОННОГО/ui', $chunk)
            || (preg_match('/СНЯТ\s+С\s+РЕГИСТРАЦИОННОГО/ui', $chunk) && !preg_match('/ЗАРЕГИСТРИРОВАН[\s\S]{0,40}дом/ui', $chunk))) {
            // Если в куске есть и снятие, и регистрация — обрежем снятие.
            $chunk = preg_replace('/СНЯТ\s+С\s+РЕГИСТРАЦИОННОГО[\s\S]*/ui', '', $chunk);
        }
        if (!preg_match('/ЗАРЕГИСТРИРОВАН/ui', $chunk)) {
            continue;
        }
        // Кусок только про снятие.
        if (preg_match('/СНЯТ\s+С\s+РЕГИСТРАЦИОННОГО/ui', $chunk) && !preg_match('/дом\s*№|ул\.|Улица/ui', $chunk)) {
            continue;
        }
        $parsed = yvo_parse_passport_registration_stamp($chunk);
        if ($parsed['address'] === '' || (!preg_match('/\d/u', $parsed['address']))) {
            continue;
        }
        $sort = 0;
        if ($parsed['date'] !== '' && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $parsed['date'], $dm)) {
            $sort = ((int) $dm[3]) * 10000 + ((int) $dm[2]) * 100 + (int) $dm[1];
        }
        $candidates[] = array(
            'address' => $parsed['address'],
            'date' => $parsed['date'],
            'sort' => $sort,
            'pos' => strlen($text) - strlen($chunk), // ближе к концу документа = новее по расположению
        );
    }
    if (empty($candidates)) {
        return '';
    }
    usort($candidates, function ($a, $b) {
        if ($a['sort'] !== $b['sort']) {
            return $a['sort'] - $b['sort'];
        }
        return $a['pos'] - $b['pos'];
    });
    $best = end($candidates);
    $addr = $best['address'];
    // Не дублируем дату в адресе — она нужна как критерий выбора, адрес для договора.
    return $addr;
}

/**
 * Доп. эвристики паспорта после базового regex-парсера.
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function yvo_enrich_passport_data_from_ocr(array $data, $text) {
    if (!is_string($text)) {
        $text = '';
    }
    if (function_exists('yvo_normalize_passport_ocr_text')) {
        $text = yvo_normalize_passport_ocr_text($text);
    }
    $mrz = yvo_parse_passport_mrz_from_text($text);
    // MRZ series/number только если номер встречается в OCR (иначе битый MRZ: 778389≠077838).
    if (!empty($mrz['passport_number'])) {
        $digits = preg_replace('/\D+/u', '', $text);
        if (strpos($digits, (string) $mrz['passport_number']) === false) {
            unset($mrz['passport_series'], $mrz['passport_number']);
        }
    }
    // Пересчёт серии/номера с защитой от «код подразделения → 0625».
    if (function_exists('yvo_parse_passport_series_number_from_text')) {
        $sn = yvo_parse_passport_series_number_from_text($text);
        if (!empty($sn['passport_series']) && !empty($sn['passport_number'])) {
            $data['passport_series'] = $sn['passport_series'];
            $data['passport_number'] = $sn['passport_number'];
        }
    }
    // Сборка ФИО: кириллица из подписей + имя из MRZ при битом OCR («АЗАТАСИЯ»).
    $surname = $first = $patr = '';
    if (preg_match('/([А-ЯЁ][А-ЯЁа-яё\-]+)\s*\n\s*(?:Фамилия|surname)/ui', $text, $sm)) {
        $surname = yvo_ocr_mb_strtoupper(trim($sm[1]));
    }
    if (preg_match('/\n\s*([А-ЯЁа-яё][А-ЯЁа-яё\-]+)\s*\n\s*(?:Имя|name)/ui', $text, $fm)) {
        $first = yvo_ocr_mb_strtoupper(trim($fm[1]));
    }
    // Letter-spaced имя уже схлопнуто; запасной путь: строка перед «Отчество».
    if ($first === '' && preg_match('/\n\s*([А-ЯЁ]{2,})\s*\n\s*(?:Отчество|patronymic)/ui', $text, $fm2)) {
        $cand = yvo_ocr_mb_strtoupper(trim($fm2[1]));
        if ($cand !== 'МУЖ' && $cand !== 'ЖЕН' && function_exists('yvo_passport_name_token_ok') && yvo_passport_name_token_ok($cand)) {
            $first = $cand;
        }
    }
    if (preg_match('/([А-ЯЁ][А-ЯЁа-яё\-]+)\s*\n\s*(?:Отчество|patronymic)/ui', $text, $pm)) {
        $patr = yvo_ocr_mb_strtoupper(trim($pm[1]));
    }
    if ($first === '' && !empty($mrz['full_name'])) {
        $mp = preg_split('/\s+/u', trim($mrz['full_name']));
        if (count($mp) >= 2) {
            $first = $mp[1];
        }
    }
    if ($surname !== '' && $first !== '' && $patr !== '') {
        $built = trim($surname . ' ' . $first . ' ' . $patr);
        if (!yvo_full_name_looks_invalid($built)) {
            $data['full_name'] = $built;
        }
    } elseif (empty($data['full_name']) || yvo_full_name_looks_invalid((string) ($data['full_name'] ?? '')) || count(preg_split('/\s+/u', trim((string) ($data['full_name'] ?? '')))) < 3) {
        if (!empty($mrz['full_name']) && !yvo_full_name_looks_invalid($mrz['full_name'])) {
            $data['full_name'] = $mrz['full_name'];
        }
    }

    foreach ($mrz as $k => $v) {
        if ($v === '' || $v === null || $k === 'full_name') {
            continue;
        }
        if ($k === 'passport_series' || $k === 'passport_number') {
            // Уже выставили через yvo_parse_passport_series_number_from_text.
            if (!empty($data[$k])) {
                continue;
            }
        }
        if (empty($data[$k])) {
            $data[$k] = $v;
        }
    }

    if (empty($data['passport_date'])) {
        if (preg_match('/(?:дата\s*выдачи|выдачи)\s*[^\d]{0,20}(\d{2}\.\d{2}\.\d{4})/ui', $text, $m)) {
            $data['passport_date'] = $m[1];
        } elseif (preg_match('/\b(\d{2}\.\d{2}\.(?:19|20)\d{2})\b/u', substr($text, 0, 800), $m)) {
            // Первая дата на развороте часто дата выдачи.
            if (empty($data['birth_date']) || $m[1] !== $data['birth_date']) {
                $data['passport_date'] = $m[1];
            }
        }
    }

    if (empty($data['passport_issued_by'])) {
        if (preg_match('/(?:Паспорт\s+выдан|выдан)\s*\n?\s*((?:ОТДЕЛЕНИЕМ|ОТДЕЛОМ|УФМС|МВД|ГУ\s+МВД)[^\n]{10,120}(?:\n[^\n]{5,80}){0,3})/ui', $text, $m)) {
            $by = trim(preg_replace('/\s+/u', ' ', $m[1]));
            $by = preg_replace('/\s+(?:Дата|Код|Личный|01\.|020-).*$/ui', '', $by);
            if (yvo_ocr_mb_strlen($by) > 10) {
                $data['passport_issued_by'] = $by;
            }
        }
    }

    if (empty($data['birth_place']) && preg_match('/(?:Место\s*\n?\s*рождения|рождения)\s*\n?\s*((?:ДЕР\.|Д\.|Г\.|ГОР\.)[^\n]{3,80}(?:\n[^\n]{3,60}){0,3})/ui', $text, $m)) {
        $bp = trim(preg_replace('/\s+/u', ' ', $m[1]));
        $bp = preg_replace('/\s+\d{2}\s+\d{2}\s+\d{6}.*$/u', '', $bp);
        $data['birth_place'] = trim($bp, ' ,');
    }

    $reg = yvo_extract_passport_registration_from_text($text);
    if ($reg !== '') {
        // Всегда берём последнюю прописку по дате штампа, а не первую попавшуюся.
        $data['registration'] = $reg;
    }

    return $data;
}

/**
 * Улучшенный разбор свидетельства: «ГАЗИЗОВ / фамилия / АМИР МАРАТОВИЧ».
 *
 * @return array<string,string>
 */
function yvo_enrich_birth_certificate_from_ocr(array $data, $text) {
    if (!is_string($text) || $text === '') {
        return $data;
    }
    // Серия II-AP / II-АР
    if (empty($data['birth_cert_series']) || empty($data['birth_cert_number'])) {
        if (preg_match('/\b([IVXLC]{1,4})[\s\-]*([A-ZА-ЯЁ]{1,3})\s*(?:№|N|No\.?|#)\s*(\d{5,7})\b/ui', $text, $m)) {
            $data['birth_cert_series'] = strtoupper($m[1] . '-' . $m[2]);
            $data['birth_cert_number'] = $m[3];
        }
    }
    if (empty($data['full_name'])) {
        if (preg_match('/([А-ЯЁ][А-ЯЁа-яё\-]+)\s*\n\s*фамилия\s*\n\s*([А-ЯЁ][А-ЯЁа-яё\-]+(?:\s+[А-ЯЁ][А-ЯЁа-яё\-]+)?)/ui', $text, $m)) {
            $name = trim($m[1] . ' ' . $m[2]);
            if (!yvo_full_name_looks_invalid($name)) {
                $data['full_name'] = $name;
            }
        }
    }
    if (empty($data['birth_date']) && preg_match('/родил(?:ся|ась)[^\d]{0,40}(\d{2}\.\d{2}\.\d{4})/ui', $text, $m)) {
        $data['birth_date'] = $m[1];
    } elseif (empty($data['birth_date']) && preg_match('/\b(\d{2}\.\d{2}\.(?:19|20)\d{2})\b/u', $text, $m)) {
        $data['birth_date'] = $m[1];
    }
    if (empty($data['birth_place']) && preg_match('/место\s+рождения\s*\n?\s*([^\n]{3,80})/ui', $text, $m)) {
        $bp = trim($m[1]);
        if (preg_match('/\n\s*(Республика[^\n]+)/ui', $text, $m2) && stripos($text, $bp) !== false) {
            $bp .= ', ' . trim($m2[1]);
        }
        $data['birth_place'] = trim(preg_replace('/\s+/u', ' ', $bp), ' ,');
    }
    if (empty($data['birth_cert_date'])) {
        if (preg_match('/Дата\s+выдачи[^\d]{0,30}[«\"]?\s*(\d{1,2})\s*[»\"]?\s*\n?\s*(января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)\s*\n?\s*(\d{4})/ui', $text, $m)) {
            $data['birth_cert_date'] = yvo_parse_russian_words_date($m[1] . ' ' . $m[2] . ' ' . $m[3]);
        } elseif (preg_match('/[«\"]\s*(\d{1,2})\s*[»\"]\s*\n?\s*(сентября|августа|января|февраля|марта|апреля|мая|июня|июля|октября|ноября|декабря)\s*\n?\s*(\d{4})/ui', $text, $m)) {
            $data['birth_cert_date'] = yvo_parse_russian_words_date($m[1] . ' ' . $m[2] . ' ' . $m[3]);
        }
    }
    if (empty($data['birth_cert_issued_by']) && preg_match('/(?:Место\s+государственной\s+регистрации|отдел\s+загс)[^\n]*\n?\s*([^\n]{10,160})/ui', $text, $m)) {
        $data['birth_cert_issued_by'] = trim(preg_replace('/\s+/u', ' ', $m[1]));
    }
    // Родители
    if (preg_match('/Отец\s*\n?\s*([А-ЯЁ][А-ЯЁа-яё\-]+)\s*\n?\s*(?:фамилия)?\s*\n?\s*([А-ЯЁ][А-ЯЁа-яё\-]+(?:\s+[А-ЯЁ][А-ЯЁа-яё\-]+)?)/ui', $text, $m)) {
        $data['father_name'] = trim($m[1] . ' ' . $m[2]);
    }
    if (preg_match('/Мать\s*\n?\s*([А-ЯЁ][А-ЯЁа-яё\-]+)\s*\n?\s*(?:фамилия)?\s*\n?\s*([А-ЯЁ][А-ЯЁа-яё\-]+(?:\s+[А-ЯЁ][А-ЯЁа-яё\-]+)?)/ui', $text, $m)) {
        $data['mother_name'] = trim($m[1] . ' ' . $m[2]);
    }
    return $data;
}
