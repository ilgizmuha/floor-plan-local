<?php
/**
 * Локальный разбор выписки ЕГРН (без ИИ): объект, право, обременения, справка.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array{property:array<string,mixed>,participants:array<int,array>,ownership_history:array<int,array>,egrn_check:array<string,mixed>}
 */
function yvo_text_looks_like_egrn($text) {
    if (!is_string($text) || trim($text) === '') {
        return false;
    }
    return (bool) preg_match('/кадастров|егрн|единый\s+государственн|02:\d{2}:\d{6,}|02-\d{2}-\d{6,}\/\d{4}/ui', $text);
}

/**
 * Обычная читаемая выписка ЕГРН (не путать с yvo_text_looks_like_egrn_garbled — только «кракозябра» для ИИ-fix).
 */
function yvo_text_looks_like_egrn_document($text) {
    if (!is_string($text) || trim($text) === '') {
        return false;
    }
    if (yvo_text_looks_like_egrn($text)) {
        return true;
    }
    return (bool) preg_match(
        '/выписк\s+из\s+единого\s+государственного\s+реестр|сведения\s+об\s+основных\s+характеристиках|сведения\s+о\s+зарегистрированных\s+правах|правообладатель\s*\(правообладатели\)|местоположение\s*:/ui',
        $text
    );
}

function yvo_egrn_prepare_text_for_parse($text) {
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $text);
    if (!class_exists('YVO_DeepSeekParser')) {
        $parser_file = defined('YVO_PLUGIN_DIR') ? YVO_PLUGIN_DIR . 'includes/deepseek-parser.php' : dirname(__FILE__) . '/deepseek-parser.php';
        if (is_file($parser_file)) {
            require_once $parser_file;
        }
    }
    if (class_exists('YVO_DeepSeekParser')) {
        $decoded = YVO_DeepSeekParser::decode_egrn_raw_text_with_header_map($text);
        if (is_string($decoded) && strlen($decoded) > 200) {
            $text = $decoded;
        }
    }
    return $text;
}

/**
 * Байтовый срез без разрезания UTF-8 символа (иначе preg_* с /u молча не матчит).
 */
function yvo_egrn_byte_slice($text, $start, $end) {
    $text = (string) $text;
    $len = strlen($text);
    $start = max(0, min((int) $start, $len));
    $end = max($start, min((int) $end, $len));
    // Старт внутри многобайтового символа → сдвинуть вперёд к началу следующего.
    while ($start < $end && (ord($text[$start]) & 0xC0) === 0x80) {
        $start++;
    }
    // Конец внутри символа → не включать частичный хвост.
    while ($end > $start && $end < $len && (ord($text[$end]) & 0xC0) === 0x80) {
        $end--;
    }
    return substr($text, $start, $end - $start);
}

function yvo_egrn_normalize_reg_number($raw) {
    $s = trim((string) $raw);
    $s = preg_replace('/\s*([\-\/])\s*/u', '$1', $s);
    $s = preg_replace('/\s+/u', '', $s);
    return $s;
}

function yvo_egrn_title_case_right_type($s) {
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }
    if ($s === 'Собственность') {
        return 'Собственность';
    }
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
    }
    return $s;
}

function yvo_egrn_detect_right_type($scope) {
    if (preg_match('/\b(общая\s+долев[а-яё]*\s+собственност[ьи])\b/ui', $scope, $m)) {
        return yvo_egrn_title_case_right_type($m[1]);
    }
    if (preg_match('/\b(долев[а-яё]*\s+собственност[ьи])\b/ui', $scope, $m)) {
        return yvo_egrn_title_case_right_type($m[1]);
    }
    if (preg_match('/\b(Собственность)\b/u', $scope, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Формат для договора: «Собственность, 02-04-01/287/2013-299, 07.08.2013».
 *
 * @param array<string, mixed> $property
 */
function yvo_egrn_build_property_right_info_line(array $property) {
    $type = trim((string) ($property['property_right_type'] ?? ''));
    $num = trim((string) ($property['property_right_number'] ?? ''));
    $date = trim((string) ($property['property_right_date'] ?? ''));
    if ($num !== '' && $date !== '') {
        $date_phrase = $date;
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/u', $date, $m) && function_exists('yvo_russian_month_genitive')) {
            $month = yvo_russian_month_genitive($m[2]);
            if ($month !== '') {
                $date_phrase = '«' . $m[1] . '» ' . $month . ' ' . $m[3] . ' г.';
            }
        }
        return 'запись о государственной регистрации права № ' . $num . ' от ' . $date_phrase;
    }
    $parts = array();
    if ($type !== '') {
        $parts[] = $type;
    }
    if ($num !== '') {
        $parts[] = $num;
    }
    if ($date !== '') {
        $parts[] = $date;
    }
    return implode(', ', $parts);
}

function yvo_egrn_normalize_basis_line($s) {
    $s = yvo_egrn_normalize_multiline($s);
    if ($s === '' || preg_match('/^[\d.\s\-]+$/u', $s)) {
        return '';
    }
    if (preg_match('/^Основани[ея]\s+государственн/ui', $s)) {
        return '';
    }
    return $s;
}

/**
 * Несколько оснований гос. регистрации через запятую.
 */
function yvo_egrn_extract_all_registration_bases($text, $section4_scope = '') {
    $found = array();
    $scopes = array((string) $text);
    if ($section4_scope !== '') {
        $scopes[] = $section4_scope;
    }
    foreach ($scopes as $scope) {
        if (preg_match_all('/Основани[ея]\s+государственн[а-яё]*\s+регистрации\s*[\t:]\s*([^\n\r]+)/ui', $scope, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $b = yvo_egrn_normalize_basis_line($m[1]);
                if ($b !== '') {
                    $found[$b] = true;
                }
            }
        }
        if (preg_match('/Основани[ея]\s+государственн[а-яё]*\s+регистрации([\s\S]*?)(?=^\s*4\.\d|^\s*5[\.\s]|Ограничени[ея]\s+прав|$)/umi', $scope, $block)) {
            $lines = preg_split('/\r\n|\n|\r/u', $block[1]);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^[\d.]+$/u', $line)) {
                    continue;
                }
                if (preg_match('/^(?:Договор|Соглашение|Акт|Решение|Постановление|Свидетельство|Устав|Выписк|Протокол)/ui', $line)) {
                    $b = yvo_egrn_normalize_basis_line($line);
                    if ($b !== '') {
                        $found[$b] = true;
                    }
                }
            }
        }
        if (preg_match_all('/((?:Договор|Соглашение)\s+(?:дарения|ипотеки|купли|долевого)[^\n\r]{4,200})/ui', $scope, $mm)) {
            foreach ($mm[1] as $line) {
                $b = yvo_egrn_normalize_basis_line($line);
                if ($b !== '') {
                    $found[$b] = true;
                }
            }
        }
    }
    return implode(', ', array_keys($found));
}

/**
 * Блок раздела 4 выписки (зарегистрированные права).
 */
function yvo_egrn_extract_section4_block($text) {
    if (preg_match('/(?:Сведения о зарегистрированн[а-яё]*\s+правах|4[\s.]*Сведения о зарегистрированн[а-яё]*\s+правах)([\s\S]*?)(?=^\s*5[\s.\)]|Ограничени[ея]\s+прав|Ограничения прав|Раздел\s*5\b)/umi', $text, $m)) {
        return $m[1];
    }
    return '';
}

function yvo_parse_egrn_text_local($text) {
    $out = array(
        'property' => array(),
        'participants' => array(),
        'participants_all' => array(),
        'ownership_history' => array(),
        'egrn_check' => array(),
    );
    if (!is_string($text) || trim($text) === '') {
        return $out;
    }
    $text = yvo_egrn_prepare_text_for_parse($text);
    $clean = preg_replace('/[ \t]{2,}/u', ' ', str_replace("\t", ' ', $text));

    if (class_exists('YVO_Property_Parser')) {
        $prop_parser = new YVO_Property_Parser($text);
        $out['property'] = $prop_parser->parse();
    }

    $egrn = yvo_egrn_extract_rights_and_encumbrances($text);
    $out['property'] = yvo_merge_egrn_property_arrays($out['property'], $egrn['property']);
    $out['egrn_check'] = $egrn['egrn_check'];

    if (!empty($out['property']['address'])) {
        $parts = yvo_split_russian_address($out['property']['address']);
        foreach ($parts as $k => $v) {
            if ($v !== '' && (empty($out['property'][$k]) || !isset($out['property'][$k]))) {
                $out['property'][$k] = $v;
            }
        }
    }

    $base = yvo_egrn_extract_object_and_participants($text);
    $out['property'] = yvo_merge_egrn_property_with_priority($base['property'], $out['property']);
    if (!empty($base['participants'])) {
        $out['participants'] = $base['participants'];
    }
    if (!empty($base['participants_all'])) {
        $out['participants_all'] = $base['participants_all'];
    } elseif (!empty($out['participants'])) {
        $out['participants_all'] = $out['participants'];
    }
    if (!empty($base['ownership_history'])) {
        $out['ownership_history'] = $base['ownership_history'];
    }

    // Право/основание для формы — у действующего права (всех текущих с одной записью — берём последнюю по дате).
    if (!empty($out['ownership_history']) && is_array($out['ownership_history'])) {
        $current = null;
        foreach ($out['ownership_history'] as $rec) {
            if (!empty($rec['is_current'])) {
                $current = $rec;
            }
        }
        if ($current === null) {
            $current = $out['ownership_history'][count($out['ownership_history']) - 1];
        }
        if (is_array($current)) {
            if (!empty($current['right_type'])) {
                $out['property']['property_right_type'] = $current['right_type'];
            }
            if (!empty($current['registration_number'])) {
                $out['property']['property_right_number'] = $current['registration_number'];
            }
            if (!empty($current['registration_date'])) {
                $out['property']['property_right_date'] = $current['registration_date'];
            }
            if (!empty($current['basis'])) {
                $out['property']['ownership_basis_documents'] = $current['basis'];
                $out['egrn_check']['registration_basis'] = $current['basis'];
            }
            $info = yvo_egrn_build_property_right_info_line($out['property']);
            if ($info !== '') {
                $out['property']['property_right_info'] = $info;
            }
        }
    }

    if (!empty($out['property']) && is_array($out['property']) && function_exists('yvo_normalize_property_address_fields')) {
        $out['property'] = yvo_normalize_property_address_fields($out['property']);
    }

    return $out;
}

/**
 * Строка служебного адреса (МФЦ, подпись) — не адрес объекта.
 */
function yvo_egrn_is_service_address($address) {
    $a = trim((string) $address);
    if ($a === '') {
        return true;
    }
    return (bool) preg_match('/450055|Проспект\s+Октября|ул\.\s*Проспект|МФЦ|ФЕДЕРАЛЬНАЯ\s+СЛУЖБА|ТОСП\s+РГАУ|ДОКУМЕНТ\s+ПОДПИСАН/ui', $a);
}

/**
 * Местоположение объекта из раздела 1 (не «Адрес:» в подписи).
 */
function yvo_egrn_extract_mestopolozhenie($text) {
    // Онлайн Госуслуги: часть адреса ДО метки, продолжение (д./кв. или ул./с.) — после.
    // Пример:
    //   … г Уфа, ул Комсомольская,
    // Адрес (местоположение)
    //   д. 159/1, кв. 16
    // Или (здание):
    //   … м.р-н Уфимский, с.п. Миловский
    // Адрес (местоположение)   сельсовет, с Миловка, ул Советская, д. 73/3
    if (preg_match(
        '/([^\n]*(?:Российская\s+Федерация|Республика|обл\.|область|г\.о\.|город|г\.?\s+[А-ЯЁа-яё]|ул\.|улица|пр-кт|проспект|пер\.|мкр|м\.р-н|с\.п\.)[^\n]{5,220})\s*\n\s*Адрес\s*\(\s*местоположение\s*\)\s*:?\s*([^\n]{3,200})/ui',
        $text,
        $m
    )) {
        $head = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t,");
        $tail = trim(preg_replace('/\s+/u', ' ', $m[2]), " \t,");
        $tail_ok = $tail !== ''
            && !preg_match('/^Адрес|^Номер|^Площад|^Назначен|^Дата|^Кадастр|^Вид\s+номера|^номер:/ui', $tail)
            && (
                preg_match('/(?:^д\.?\s*\d|\d+|кв\.?\s*\d)/ui', $tail)
                || preg_match('/(?:ул\.|улица|с\.|сел|д\.|дер\.|г\.|город)/ui', $tail)
            );
        if ($tail_ok) {
            $addr = trim($head . ', ' . $tail, " \t,");
            $addr = preg_replace('/,\s*,+/u', ',', $addr);
            if (!yvo_egrn_is_service_address($addr) && strlen($addr) > 20) {
                return $addr;
            }
        }
    }
    // Адрес ПОД меткой / сдвинут колонками (pdftotext): РФ/улица до «Площадь», в т.ч. перенос «ул Максима\nРыльского».
    if (preg_match(
        '/Адрес\s*\(\s*местоположение\s*\)([\s\S]{20,1200}?)(?=\n[ \t]*Площадь\b)/ui',
        $text,
        $m
    )) {
        $block = $m[1];
        $addr = '';
        if (preg_match(
            '/((?:Российская\s+Федерация|Республика\s+[А-ЯЁа-яё\-]+)[^\n]+)\r?\n[ \t]*([А-ЯЁа-яё][^\n]{2,120})/ui',
            $block,
            $am
        )) {
            $addr = trim($am[1] . ' ' . $am[2], " \t,");
        } elseif (preg_match('/((?:Российская\s+Федерация|Республика\s+[А-ЯЁа-яё\-]+)[^\n]{10,260})/ui', $block, $am)) {
            $addr = trim($am[1], " \t,");
        }
        if ($addr !== '') {
            $addr = preg_replace('/\s+/u', ' ', $addr);
            $addr = preg_replace('/\b(?:Вид\s+номера|Номер:\s*[\d:\/A-Za-z]*|Организация[^,]*|Данные\s+отсутствуют|Дата\s+присвоения|Инвентарный\s+номер)[^,]*/ui', '', $addr);
            $addr = trim(preg_replace('/\s+/u', ' ', $addr), " \t,");
            $addr = preg_replace('/,\s*,+/u', ',', $addr);
            $addr = preg_replace('/\s+\d+\s*Этаж\b.*$/ui', '', $addr);
            if (!yvo_egrn_is_service_address($addr) && strlen($addr) > 25 && preg_match('/(?:ул\.|улица|д\.|кв\.|г\.|город|с\.|сел)/ui', $addr)) {
                return $addr;
            }
        }
    }
    // Та же метка в одной строке: «Адрес (местоположение)   с Миловка, ул …».
    if (preg_match('/Адрес\s*\(\s*местоположение\s*\)\s*:?\s*((?:Российская\s+Федерация|Республика|обл\.|сельсовет|г\.|город|с\.|д\.|ул\.|м\.р-н)[^\n]{8,220})/ui', $text, $m)) {
        $addr = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t,");
        if (!yvo_egrn_is_service_address($addr) && !preg_match('/^номер:/ui', $addr) && strlen($addr) > 15) {
            return $addr;
        }
    }
    if (preg_match('/Местоположение\s*:\s*\n?\s*(Российская\s+Федерация[^\n]+)/ui', $text, $m)) {
        $addr = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t,");
        if (!yvo_egrn_is_service_address($addr)) {
            return $addr;
        }
    }
    if (preg_match('/\bАдрес:\s*(Российская Федерация[\s\S]*?)(?=\n\s*(Площадь|Категория|Разрешенное|Сведения, не указанные|Лист ЕГРН|Вид права|Назначение)\b)/u', $text, $m)) {
        $addr = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t\n\r\0\x0B,");
        if (!yvo_egrn_is_service_address($addr)) {
            return $addr;
        }
    }
    // Адрес без «Российская Федерация» (Брянск, МО и т.п.), в т.ч. многострочный.
    if (preg_match('/\bАдрес:\s*((?:Московская|Брянская|Республика|обл\.|область|край|г\.?\s*[А-Яа-яЁё])[\s\S]*?)(?=\n\s*(?:Площадь|Категория|Назначение|Наименование|Номер кадастрового|Разрешенное|Виды разрешенного)\b)/ui', $text, $m)) {
        $addr = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t\n\r\0\x0B,");
        if (!yvo_egrn_is_service_address($addr) && strlen($addr) > 15) {
            return $addr;
        }
    }
    // Онлайн-выписка: «Местоположение» и значение через пробелы в одной строке (без «Российская Федерация»).
    if (preg_match('/Местоположение\s+:?\s*((?:Российская\s+Федерация|Республика|обл\.|область|край|г\.|город|д\.|дер\.|с\.)[^\n]{8,260})/ui', $text, $m)) {
        $addr = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t,");
        if (!yvo_egrn_is_service_address($addr)) {
            return $addr;
        }
    }
    // Не принимать только хвост «д. N, кв. N» без улицы — это обломок онлайн-layout.
    if (preg_match('/(?:Местоположение|Адрес\s*\(\s*местоположение\s*\))\s*:?\s*\n?\s*([^\n]{20,260})/ui', $text, $m)) {
        $addr = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t,");
        $looks_tail_only = (bool) preg_match('/^(?:д\.?\s*)?\d+[0-9A-Za-zА-Яа-яЁё\/\-]*(?:\s*,\s*(?:корп\.?\s*)?[^\n]*)?(?:\s*,\s*кв\.?\s*\d+)?$/ui', $addr);
        if (!$looks_tail_only && !yvo_egrn_is_service_address($addr) && preg_match('/(?:г\.|город|ул\.|пр-кт|пр\.|кв\.|квартира|д\.|дер\.|с\.|мкр)/ui', $addr)) {
            if (!preg_match('/^Российская\s+Федерация/ui', $addr)) {
                $addr = 'Российская Федерация, ' . $addr;
            }
            return $addr;
        }
    }
    // Сдвинутый layout ЗУ (fallback): адрес сразу над/под «Ранее присвоенный» / в блоке характеристик.
    if (preg_match('/((?:Республика|обл\.|область)[^\n]{5,100}(?:,\s*)?(?:г\.|город)\s*[А-ЯЁа-яё\-]+[^\n]{0,40}(?:,\s*)?(?:д\.|дер\.|с\.|пос\.)\s*[А-ЯЁа-яё\-]+)/ui', $text, $m)) {
        $addr = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t,");
        if (!preg_match('/Паспорт|СНИЛС|Правообладатель|выдан:|Минцифры/ui', $addr)
            && !yvo_egrn_is_service_address($addr)
            && strlen($addr) > 15) {
            return $addr;
        }
    }
    return '';
}

/**
 * Блок раздела 2 (зарегистрированные права) в новых выписках ЕГРН.
 */
function yvo_egrn_extract_section2_rights_block($text) {
    if (preg_match('/Сведения о зарегистрированных правах([\s\S]*?)(?=---\s*Страница\s+\d+|Описание местоположения|^\s*Раздел\s+(?:3|4)\s\b)/umi', $text, $m)) {
        return $m[1];
    }
    if (preg_match('/Раздел\s*2\b([\s\S]*?)(?=---\s*Страница\s+\d+|^\s*Раздел\s+(?:3|4)\s\b|Описание местоположения)/umi', $text, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Только блок правообладателя (ФИО, паспорт) без обременений.
 */
function yvo_egrn_extract_right_holders_block($text) {
    if (preg_match('/Правообладатель\s*\(правообладатели\)([\s\S]*?)(?=Сведения о возможности|Вид,\s*номер,\s*дата|Ограничение\s+прав|ДОКУМЕНТ\s+ПОДПИСАН)/ui', $text, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Область текста с правами: раздел 2 (новый формат) или раздел 4 (старый).
 */
function yvo_egrn_extract_rights_section_scope($text) {
    $s2 = yvo_egrn_extract_section2_rights_block($text);
    if ($s2 !== '') {
        return $s2;
    }
    return yvo_egrn_extract_section4_block($text);
}

/**
 * Вид объекта из выписки: здание, участок, помещение.
 */
function yvo_egrn_detect_object_kind($text) {
    $scope = $text;
    if (preg_match('/Сведения об основных характеристиках([\s\S]*?)(?:Сведения о зарегистрированных|Раздел\s*2\b)/ui', $text, $m)) {
        $scope = $m[1];
    }
    if (preg_match('/Вид\s+объекта[^\n]{0,120}Земельный\s+участок/ui', $text)
        || preg_match('/(?:^|\n)\s*Земельный\s+участок\s*(?:\n|$)/ui', $scope)) {
        return 'land';
    }
    if (preg_match('/(?:^|\n)\s*Здание\s*(?:\n|$)/ui', $scope)
        || preg_match('/вид\s+объекта\s+недвижимости\s*\n\s*Здание/ui', $scope)
        || preg_match('/Вид\s+объекта[^\n]{0,120}Здание/ui', $text)) {
        return 'house_with_plot';
    }
    if (preg_match('/(?:^|\n)\s*Помещение\s*(?:\n|$)/ui', $scope)
        || preg_match('/вид\s+объекта\s+недвижимости[^\n]{0,40}Помещение/ui', $text)
        || preg_match('/Вид\s+жилого\s+помещения\s*:?\s*Квартира/ui', $text)) {
        if (preg_match('/\b(?:комната|комн\.)\b/ui', $scope)) {
            return 'room';
        }
        return 'apartment';
    }
    if (preg_match('/(?:^|\n)\s*Квартира\s*(?:\n|$)/ui', $scope)) {
        return 'apartment';
    }
    return '';
}

/**
 * Поля адреса для дома с участком из строки «Местоположение».
 *
 * @return array<string, string>
 */
function yvo_egrn_map_location_to_property_fields($address, $object_type) {
    $fields = array();
    $address = trim((string) $address);
    if ($address === '') {
        return $fields;
    }
    $fields['address'] = $address;
    if ($object_type !== 'house_with_plot') {
        return $fields;
    }
    if (preg_match('/,\s*(?:дер\.|деревня)\s*([^,]+)/ui', $address, $m)) {
        $fields['house_settlement'] = 'дер. ' . trim($m[1]);
    } elseif (preg_match('/,\s*с\.?\s+([А-ЯЁ][а-яё]+(?:\s+[А-ЯЁа-яё]+){0,2})(?=,|$)/u', $address, $m)
        || preg_match('/,\s*село\s+([А-ЯЁа-яё][^,]*)/ui', $address, $m)) {
        $name = trim($m[1]);
        if ($name !== '' && !preg_match('/^\d/u', $name)) {
            $fields['house_settlement'] = 'с. ' . $name;
        }
    } elseif (preg_match('/,\s*(?:д\.|дер\.)\s*([А-ЯЁа-яё][^,]*)$/ui', $address, $m)) {
        // «д. Елкибаево» в конце — НП; «д. 73/3» — номер дома, пропускаем.
        $name = trim($m[1]);
        if ($name !== '' && !preg_match('/^\d/u', $name)) {
            $fields['house_settlement'] = 'д. ' . $name;
        }
    }
    if (preg_match('/,\s*([^,]+)\s+город/ui', $address, $m)) {
        $fields['city'] = trim($m[1]);
    } elseif (preg_match('/,\s*(?:г\.|город)\s*([^,]+)/ui', $address, $m)) {
        $fields['city'] = trim($m[1]);
    }
    return $fields;
}

/**
 * Общие поля объекта → поля формы «дом с участком».
 *
 * @param array<string, mixed> $property
 * @return array<string, mixed>
 */
function yvo_egrn_normalize_property_by_object_type(array $property) {
    $type = trim((string) ($property['object_type'] ?? ''));
    if ($type === 'house_with_plot') {
        if (!empty($property['cadastral_number']) && empty($property['house_cadastral_number'])) {
            $property['house_cadastral_number'] = $property['cadastral_number'];
        } elseif (!empty($property['house_cadastral_number']) && empty($property['cadastral_number'])) {
            $property['cadastral_number'] = $property['house_cadastral_number'];
        }
        if (isset($property['area']) && $property['area'] !== '' && empty($property['house_area'])) {
            $property['house_area'] = $property['area'];
        }
        if (!empty($property['floors_total']) && empty($property['house_floors'])) {
            $property['house_floors'] = $property['floors_total'];
        }
        if (!empty($property['year_built']) && empty($property['year_built_house'])) {
            $property['year_built_house'] = $property['year_built'];
        }
        if (preg_match('/жил/ui', (string) ($property['property_type'] ?? '')) && empty($property['house_purpose'])) {
            $property['house_purpose'] = 'жилой дом';
        }
        if (!empty($property['address'])) {
            $loc = yvo_egrn_map_location_to_property_fields($property['address'], 'house_with_plot');
            foreach ($loc as $k => $v) {
                if ($v !== '' && empty($property[$k])) {
                    $property[$k] = $v;
                }
            }
        }
    }
    return $property;
}

/**
 * Нормализовать название юрлица из выписки.
 */
function yvo_egrn_normalize_org_name($name) {
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name), " \t,;.");
    $name = preg_replace('/\s*,?\s*ИНН\s*:?\s*\d{10,12}.*$/ui', '', $name);
    $name = preg_replace('/\s*,?\s*ОГРН\s*:?\s*\d{13,15}.*$/ui', '', $name);
    $name = trim($name, " \t,;.");
    return $name;
}

/**
 * Строка похожа на наименование организации.
 */
function yvo_egrn_string_looks_like_org($s) {
    $s = trim((string) $s);
    if ($s === '' || strlen($s) < 5) {
        return false;
    }
    return (bool) preg_match(
        '/^(?:ООО|ОАО|АО(?!\s+[А-ЯЁ][а-яё]+\s+[А-ЯЁ])|ПАО|ЗАО|НАО|НКО|ГУП|МУП|ФГУП|ТОО|ПТ|ПК|СНТ|ТСН|ТСЖ|ИП\b|Акционерное\s+общество|Общество\s+с\s+ограниченной|Публичное\s+акционерное|Непубличное\s+акционерное|Производственный\s+кооператив|Крестьянск|Федеральн|Государственн|Муниципальн)/ui',
        $s
    );
}

/**
 * Юрлица из блока правообладателя.
 *
 * @return array<int, array<string, mixed>>
 */
function yvo_egrn_extract_holder_orgs_from_block($block) {
    $raw = (string) $block;
    $flat = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $raw);
    $flat = preg_replace('/\s+/u', ' ', $flat);
    $orgs = array();
    $seen = array();

    $candidates = array();
    // «ООО "Ромашка"», «ПАО «Сбербанк»»
    if (preg_match_all('/((?:ООО|ОАО|ПАО|АО|ЗАО|НАО|НКО|ГУП|МУП|ФГУП|ИП)\s*[«"][^»"]+[»"])/ui', $flat, $mm)) {
        foreach ($mm[1] as $c) {
            $candidates[] = $c;
        }
    }
    // «Акционерное общество Управляющая компания "Восточная Европа"»
    if (preg_match_all('/((?:Акционерное\s+общество|Общество\s+с\s+ограниченной\s+ответственностью|Публичное\s+акционерное\s+общество|Непубличное\s+акционерное\s+общество)[^,]{3,160})/ui', $flat, $mm)) {
        foreach ($mm[1] as $c) {
            $candidates[] = $c;
        }
    }
    // После метки правообладателя до вида права: «: ООО …» / «1.1. ООО …»
    if (preg_match('/Правообладатель\s*\(правообладатели\)\s*:?\s*(?:1\.\d+\.?\s*)?(.+?)(?=\s+(?:Вид,?\s*номер|Документы-основания|Основание\s+государственной|Ограничение\s+прав|ИНН\s*:|$))/ui', $flat, $m)) {
        $tail = trim($m[1], " \t,.");
        if ($tail !== '' && !preg_match('/^\d+\.\d+\.?$/u', $tail) && yvo_egrn_string_looks_like_org($tail)) {
            $candidates[] = $tail;
        }
    }
    // Наименование перед меткой (как в Щелково) + ИНН ниже.
    if (preg_match('/((?:Акционерное\s+общество|Общество\s+с\s+ограниченной|Публичное\s+акционерное|ООО|ПАО|АО|ЗАО)[^\n]{5,160})\s*\n?\s*(?:\d+\.\s*)?Правообладатель/ui', $raw, $m)) {
        $candidates[] = $m[1];
    }

    $inn = '';
    $ogrn = '';
    if (preg_match('/ИНН\s*:?\s*(\d{10}|\d{12})\b/u', $flat, $im)) {
        $inn = $im[1];
    }
    if (preg_match('/ОГРН\s*:?\s*(\d{13}|\d{15})\b/u', $flat, $om)) {
        $ogrn = $om[1];
    }

    foreach ($candidates as $cand) {
        $name = yvo_egrn_normalize_org_name($cand);
        if ($name === '' || !yvo_egrn_string_looks_like_org($name)) {
            continue;
        }
        if (preg_match('/Правообладатель|Вид,\s*номер|ограничение\s+прав|данные\s+отсутствуют/ui', $name)) {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $row = array(
            'full_name' => $name,
            'company_name' => $name,
            'is_legal_entity' => '1',
            'person_type' => 'legal_entity',
        );
        if ($inn !== '') {
            $row['inn'] = $inn;
        }
        if ($ogrn !== '') {
            $row['ogrn'] = $ogrn;
        }
        $orgs[] = $row;
    }

    // Только ИНН без имени — не создаём пустую запись.
    return $orgs;
}

/**
 * ФИО и юрлица из блока правообладателя.
 *
 * @return array<int, array<string, mixed>>
 */
function yvo_egrn_extract_holder_persons_from_block($block) {
    $block = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $block);
    $block_sp = preg_replace('/\s+/u', ' ', $block);
    $participants = array();
    $seen = array();
    // Дефис в отчестве/имени: «Ибрагим-бек», «Анна-Мария».
    $name_re = '/([А-ЯЁ][а-яё]+(?:-[А-ЯЁа-яё][а-яё]+)?\s+[А-ЯЁ][а-яё]+(?:-[А-ЯЁа-яё][а-яё]+)?\s+[А-ЯЁ][а-яё]+(?:-[А-ЯЁа-яё][а-яё]+)?)\s*,\s*(\d{2}\.\d{2}\.\d{4})/u';
    if (preg_match_all($name_re, $block_sp, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $idx => $m) {
            $full_name = trim($m[1][0]);
            $birth_date = $m[2][0];
            if (preg_match('/сотрудник\s+РГАУ|МФЦ|Операционного\s+зала|ФЕДЕРАЛЬНАЯ\s+СЛУЖБА|Получатель\s+выписки|Постановлен|Правительств|Минцифры|Росреестр|Действителен|Сертификат|Владелец/ui', $full_name)) {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($full_name, 'UTF-8') : strtolower($full_name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $start = $m[0][1];
            $end = isset($matches[$idx + 1]) ? $matches[$idx + 1][0][1] : strlen($block_sp);
            $person_block = substr($block_sp, $start, $end - $start);
            $person = array(
                'full_name' => $full_name,
                'birth_date' => $birth_date,
                'person_type' => 'person',
            );
            if (preg_match('/' . preg_quote($birth_date, '/') . '\s*,\s*(.+?)(?:,\s*(?:Российская\s+Федерация|СНИЛС|Паспорт))/ui', $person_block, $bp)) {
                $person['birth_place'] = trim(preg_replace('/\s+/u', ' ', $bp[1]), ' ,');
            }
            if (preg_match('/СНИЛС\s+([\d\-\s]+)/ui', $person_block, $sm)) {
                $person['snils'] = preg_replace('/\s+/u', ' ', trim($sm[1]));
            }
            if (preg_match('/Паспорт[^\d]*(?:серия\s*:?\s*)?(\d{2})\s*(\d{2})\s*[,;]?\s*(?:номер\s*:?\s*)?[№N]?\s*(\d{6})/ui', $person_block, $pm)) {
                $person['passport_series'] = $pm[1] . ' ' . $pm[2];
                $person['passport_number'] = $pm[3];
            } elseif (preg_match('/серия\s*:?\s*(\d{2})\s*(\d{2})\s*,?\s*номер\s*:?\s*(\d{6})/ui', $person_block, $pm)) {
                $person['passport_series'] = $pm[1] . ' ' . $pm[2];
                $person['passport_number'] = $pm[3];
            } elseif (preg_match('/Паспорт[^\d]*серия\s+(\d{4})\s*[№N]?\s*(\d{6})/ui', $person_block, $pm)) {
                $person['passport_series'] = substr($pm[1], 0, 2) . ' ' . substr($pm[1], 2, 2);
                $person['passport_number'] = $pm[2];
            } elseif (preg_match('/Паспорт[^\d]*(\d{2})\s*(\d{2})\s+(\d{6})/ui', $person_block, $pm)) {
                $person['passport_series'] = $pm[1] . ' ' . $pm[2];
                $person['passport_number'] = $pm[3];
            }
            if (preg_match('/выдан\s+(\d{2}\.\d{2}\.\d{4})/ui', $person_block, $pd)) {
                $person['passport_date'] = $pd[1];
            }
            if (preg_match('/выдан\s+\d{2}\.\d{2}\.\d{4}\s*,?\s*(.+?)(?=\s+(?:Вид,?\s*номер|Документы-основания|Правообладатель|\d\s+Вид\b)|\s+\d{6}\s*,|\s+\d{6}\s|$)/ui', $person_block, $pi)) {
                $issued = trim(preg_replace('/\s+/u', ' ', $pi[1]), ' ,');
                $issued = preg_replace('/\s+\d+\s*$/u', '', $issued);
                $issued = preg_replace('/\s+Вид,?\s*номер.*$/ui', '', $issued);
                $issued_len = function_exists('mb_strlen') ? mb_strlen($issued, 'UTF-8') : strlen($issued);
                if ($issued_len > 180) {
                    $issued = function_exists('mb_substr') ? mb_substr($issued, 0, 180, 'UTF-8') : substr($issued, 0, 180);
                }
                $person['passport_issued_by'] = trim($issued, ' ,');
            }
            if (preg_match('/\b(\d{6})\s*,\s*((?:Респ\.|обл\.|р-н\.|район)[^,]+(?:,\s*[^,]+)*)/ui', $person_block, $rg)) {
                $person['registration'] = trim(preg_replace('/\s+/u', ' ', $rg[1] . ', ' . $rg[2]));
            }
            $participants[] = $person;
        }
    }
    if (empty($participants)) {
        $name_only = '/([А-ЯЁ][а-яё]+(?:-[А-ЯЁа-яё][а-яё]+)?\s+[А-ЯЁ][а-яё]+(?:-[А-ЯЁа-яё][а-яё]+)?\s+[А-ЯЁ][а-яё]+(?:-[А-ЯЁа-яё][а-яё]+)?)/u';
        if (preg_match_all($name_only, $block_sp, $nm, PREG_SET_ORDER)) {
            foreach ($nm as $row) {
                $full_name = trim($row[1]);
                if (preg_match('/Получатель|Российская|Федерация|Собственность|Газпромбанк|общества|Постановлен|Правительств|Республик|Башкортостан|Октябрьск|Акционерное|Управляющая|Восточная|Минцифры|Росреестр|Действителен|Сертификат|Владелец|Электронн/ui', $full_name)) {
                    continue;
                }
                $key = function_exists('mb_strtolower') ? mb_strtolower($full_name, 'UTF-8') : strtolower($full_name);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $participants[] = array(
                    'full_name' => $full_name,
                    'person_type' => 'person',
                );
            }
        }
    }

    $orgs = yvo_egrn_extract_holder_orgs_from_block($block);
    foreach ($orgs as $org) {
        $key = function_exists('mb_strtolower') ? mb_strtolower((string) ($org['full_name'] ?? ''), 'UTF-8') : strtolower((string) ($org['full_name'] ?? ''));
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $participants[] = $org;
    }
    return $participants;
}

/**
 * Сырые текстовые блоки по каждому правообладателю (все страницы выписки).
 *
 * @return array<int, string>
 */
function yvo_egrn_split_right_holder_blocks($text) {
    $text = (string) $text;
    if (!preg_match_all('/Правообладатель\s*\(правообладатели\)/ui', $text, $mm, PREG_OFFSET_CAPTURE)) {
        return array();
    }
    $positions = $mm[0];
    $count = count($positions);
    $blocks = array();
    for ($i = 0; $i < $count; $i++) {
        $label_pos = (int) $positions[$i][1];
        // Онлайн Госуслуги: ФИО стоит сразу перед меткой «Правообладатель» (в пределах ~280 символов).
        // Нумерация XML / юрлицо перед меткой (Щелково) — тоже lookback.
        // Классическая выписка: ФИО после метки — lookback не нужен (иначе подтянется предыдущий собственник).
        $near_len = 480;
        $near_start = max(0, $label_pos - $near_len);
        $near = yvo_egrn_byte_slice($text, $near_start, $label_pos);
        $near_start = $label_pos - strlen($near);
        $start = $label_pos;
        $need_lookback = preg_match('/(?:^|\n)\s*\d+\.\d+\s+[А-ЯЁ]/u', $near)
            || preg_match('/[А-ЯЁ][а-яё]+(?:-[А-ЯЁа-яё][а-яё]+)?\s+[А-ЯЁ][а-яё]+(?:-[А-ЯЁа-яё][а-яё]+)?\s+[А-ЯЁ][а-яё]+(?:-[А-ЯЁа-яё][а-яё]+)?\s*,\s*\d{2}\.\d{2}\.\d{4}[\s\S]{0,160}$/u', $near)
            || preg_match('/(?:Акционерное\s+общество|Общество\s+с\s+ограниченной|Публичное\s+акционерное|Непубличное\s+акционерное|(?:ООО|ОАО|ПАО|АО|ЗАО|НАО)\b)/ui', $near);
        if ($need_lookback) {
            if (preg_match('/(?:Акционерное\s+общество|Общество\s+с\s+ограниченной|Публичное\s+акционерное|Непубличное\s+акционерное|(?:ООО|ОАО|ПАО|АО|ЗАО|НАО)\s*[«"]|(?:ООО|ОАО|ПАО|АО|ЗАО)\s+[А-ЯЁA-Z])/ui', $near, $om, PREG_OFFSET_CAPTURE)) {
                $start = $near_start + (int) $om[0][1];
            } elseif (preg_match_all('/(?:^|\n)\s*\d+\.\d+\s+/u', $near, $bm, PREG_OFFSET_CAPTURE)) {
                $last = end($bm[0]);
                if (is_array($last) && isset($last[1])) {
                    $start = $near_start + (int) $last[1];
                }
            } else {
                $start = $near_start;
            }
        }
        $end = ($i + 1 < $count) ? (int) $positions[$i + 1][1] : strlen($text);
        $chunk = yvo_egrn_byte_slice($text, $start, $end);
        // Обременения (ипотека) между блоками 1.1 и 1.2 — отрезать.
        if (preg_match('/Ограничение\s+прав\s+и\s+обременение\s+объекта/ui', $chunk, $om, PREG_OFFSET_CAPTURE)) {
            $cut = (int) $om[0][1];
            if ($cut > 80) {
                $chunk = substr($chunk, 0, $cut);
            }
        }
        // Не тянуть следующий номерной блок обременения «2.1 Ипотека» внутрь прав.
        if (preg_match('/(?:^|\n)\s*\d+\.\d+\s+\S*Ипотека/ui', $chunk, $im, PREG_OFFSET_CAPTURE)) {
            $cut = (int) $im[0][1];
            if ($cut > 80) {
                $chunk = substr($chunk, 0, $cut);
            }
        }
        $chunk = trim($chunk);
        if ($chunk !== '') {
            $blocks[] = $chunk;
        }
    }
    return $blocks;
}

/**
 * Один блок → запись истории владения.
 *
 * @return array<string, mixed>|null
 */
function yvo_egrn_parse_ownership_record_from_block($block) {
    $block = (string) $block;
    if (!preg_match('/Правообладатель/ui', $block)) {
        return null;
    }
    $holders = yvo_egrn_extract_holder_persons_from_block($block);
    if (empty($holders)) {
        return null;
    }
    $flat = preg_replace('/\s+/u', ' ', $block);
    $right_type = '';
    $reg_number = '';
    $reg_date = '';
    if (preg_match('/Вид,?\s*номер\s*и\s*дата\s*государственной\s*регистрации\s*права\s*:?\s*(.+?)(?=\s+Основание\s+государственной|\s+Документы-основания|\s+Дата,\s*номер\s*и\s*основание|$)/ui', $flat, $m)) {
        $right_line = yvo_egrn_normalize_multiline($m[1]);
        if (preg_match('/^(Собственность|Общая\s+совместная\s+собственность|Общая\s+долевая\s+собственность|Аренда)[^,]*(?:,\s*)?([0-9][0-9:\-\/]*)?\s*,?\s*(\d{2}\.\d{2}\.\d{4})?/ui', $right_line, $rm)) {
            $right_type = yvo_egrn_title_case_right_type($rm[1]);
            if (!empty($rm[2])) {
                $reg_number = yvo_egrn_normalize_reg_number($rm[2]);
            }
            if (!empty($rm[3])) {
                $reg_date = $rm[3];
            }
        } else {
            $right_type = yvo_egrn_detect_right_type($right_line) ?: '';
            if (preg_match('/\b(\d{2}[:\-]\d{2}[\d:\-\/]+)\b/u', $right_line, $nm)) {
                $reg_number = yvo_egrn_normalize_reg_number($nm[1]);
            }
            if (preg_match('/\b(\d{2}\.\d{2}\.\d{4})\b/u', $right_line, $dm)) {
                $reg_date = $dm[1];
            }
        }
    }
    $basis = '';
    if (preg_match('/(?:Основание\s+государственной\s+регистрации|Документы-основания)\s*:?\s*(.+?)(?=\s+Дата,\s*номер\s*и\s*основание|\s+Сведения\s+об\s+осуществлении|\s+Заявленные|$)/ui', $flat, $m)) {
        $basis = yvo_egrn_normalize_multiline($m[1]);
        if (preg_match('/^Данные\s+отсутствуют$/ui', $basis)) {
            $basis = '';
        }
    }
    $transition = '';
    if (preg_match('/Дата,\s*номер\s*и\s*основание\s*государственной\s*регистрации\s*(?:перехода\s*\(прекращения\)\s*права)?\s*:?\s*(.+?)(?=\s+Сведения\s+об\s+осуществлении|\s+Заявленные|\s+Сведения\s+о\s+возражении|\s+Сведения\s+о\s+невозможности|$)/ui', $flat, $m)) {
        $transition = yvo_egrn_normalize_multiline($m[1]);
    } elseif (preg_match('/перехода\s*\(прекращения\)\s*права\s*:?\s*(.+?)(?=\s+Сведения\s+об|\s+Заявленные|$)/ui', $flat, $m)) {
        $transition = yvo_egrn_normalize_multiline($m[1]);
    }
    // Формат Госуслуг: значение между двумя частями метки.
    if ($transition === '' || preg_match('/^перехода/ui', $transition)) {
        if (preg_match('/Дата,\s*номер\s*и\s*основание\s*государственной\s*регистрации\s+(Право\s+на\s+недвижимость\s+действующее|\d{2}\.\d{2}\.\d{4}[^А-ЯЁ]*)\s+перехода/ui', $flat, $m)) {
            $transition = yvo_egrn_normalize_multiline($m[1]);
        }
    }
    $transition = preg_replace('/\s*перехода\s*\(прекращения\)\s*права\s*:?\s*/ui', '', $transition);
    $transition = trim($transition);

    $is_current = (bool) preg_match('/действующ|по\s+настоящее\s+время/ui', $transition);
    if (!$is_current && preg_match('/Право\s+на\s+недвижимость\s+действующ/ui', $flat)) {
        $is_current = true;
    }
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}/u', $transition) && !preg_match('/действующ/ui', $transition)) {
        $is_current = false;
    }

    $period_from = $reg_date;
    $period_to = '';
    if ($is_current) {
        $period_to = 'настоящее время';
    } elseif (preg_match('/(\d{2}\.\d{2}\.\d{4})/u', $transition, $tm)) {
        $period_to = $tm[1];
    }

    $holder_names = array();
    foreach ($holders as $h) {
        if (!empty($h['full_name'])) {
            $holder_names[] = $h['full_name'];
        }
    }

    $period_label = '';
    if ($period_from !== '' || $period_to !== '') {
        $to_show = $period_to !== '' ? $period_to : '—';
        if ($to_show === 'настоящее время') {
            $period_label = 'С ' . ($period_from !== '' ? $period_from : '—') . ' по настоящее время';
        } else {
            $period_label = 'С ' . ($period_from !== '' ? $period_from : '—') . ' по ' . $to_show;
        }
    }

    return array(
        'holders' => $holders,
        'holder_names' => $holder_names,
        'holder_label' => implode(', ', $holder_names),
        'right_type' => $right_type,
        'registration_number' => $reg_number,
        'registration_date' => $reg_date,
        'basis' => $basis,
        'transition' => $transition,
        'is_current' => $is_current,
        'period_from' => $period_from,
        'period_to' => $period_to,
        'period_label' => $period_label,
        'status_label' => $is_current ? 'Текущий собственник' : 'Предыдущий собственник',
    );
}

/**
 * История прав + участники.
 * participants — все действующие (их может быть несколько);
 * recognized — все распознанные правообладатели (и текущие, и из истории).
 *
 * @return array{history:array<int,array>,participants:array<int,array>,recognized:array<int,array>}
 */
function yvo_egrn_parse_ownership_history($text) {
    $blocks = yvo_egrn_split_right_holder_blocks($text);
    $history = array();
    foreach ($blocks as $block) {
        $rec = yvo_egrn_parse_ownership_record_from_block($block);
        if ($rec !== null) {
            $history[] = $rec;
        }
    }
    if (empty($history)) {
        return array('history' => array(), 'participants' => array(), 'recognized' => array());
    }

    $has_current_flag = false;
    foreach ($history as $rec) {
        if (!empty($rec['is_current'])) {
            $has_current_flag = true;
            break;
        }
    }
    // Нет явного «действующее»: все записи без даты прекращения — действующие
    // (общая долевая / совместная собственность часто несколькими блоками).
    if (!$has_current_flag) {
        foreach ($history as $i => $rec) {
            $term = (string) ($rec['transition'] ?? '');
            $terminated = (bool) preg_match('/^\d{2}\.\d{2}\.\d{4}/u', $term)
                && !preg_match('/действующ/ui', $term);
            if ($terminated) {
                continue;
            }
            if ($term === '' || preg_match('/^Данные\s+отсутствуют$/ui', $term) || preg_match('/действующ/ui', $term)) {
                $history[$i]['is_current'] = true;
                $history[$i]['period_to'] = 'настоящее время';
                $pf = (string) ($rec['period_from'] ?? '');
                $history[$i]['period_label'] = 'С ' . ($pf !== '' ? $pf : '—') . ' по настоящее время';
                $history[$i]['status_label'] = 'Текущий собственник';
                $has_current_flag = true;
            }
        }
    } else {
        // Дополнительно: блоки без даты прекращения тоже действующие (не один только).
        foreach ($history as $i => $rec) {
            if (!empty($rec['is_current'])) {
                continue;
            }
            $term = (string) ($rec['transition'] ?? '');
            $terminated = (bool) preg_match('/^\d{2}\.\d{2}\.\d{4}/u', $term)
                && !preg_match('/действующ/ui', $term);
            if ($terminated) {
                continue;
            }
            if ($term === '' || preg_match('/^Данные\s+отсутствуют$/ui', $term)) {
                $history[$i]['is_current'] = true;
                $history[$i]['period_to'] = 'настоящее время';
                $pf = (string) ($rec['period_from'] ?? '');
                $history[$i]['period_label'] = 'С ' . ($pf !== '' ? $pf : '—') . ' по настоящее время';
                $history[$i]['status_label'] = 'Текущий собственник';
            }
        }
    }
    if (!$has_current_flag && !empty($history)) {
        // Запасной вариант: самый поздний по дате регистрации.
        $best = 0;
        $best_key = '';
        foreach ($history as $i => $rec) {
            $da = (string) ($rec['registration_date'] ?? '');
            $ta = $da !== '' && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $da, $m)
                ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
            if ($ta !== '' && $ta >= $best_key) {
                $best_key = $ta;
                $best = $i;
            }
        }
        $history[$best]['is_current'] = true;
        $history[$best]['period_to'] = 'настоящее время';
        $history[$best]['status_label'] = 'Текущий собственник';
        $pf = (string) ($history[$best]['period_from'] ?? '');
        $history[$best]['period_label'] = 'С ' . ($pf !== '' ? $pf : '—') . ' по настоящее время';
    }

    // Хронология: старые → новые; внутри одной даты текущие ниже (новее для UI).
    usort($history, function ($a, $b) {
        $da = (string) ($a['registration_date'] ?? '');
        $db = (string) ($b['registration_date'] ?? '');
        $ta = $da !== '' && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $da, $m)
            ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '0000-00-00';
        $tb = $db !== '' && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $db, $m)
            ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '0000-00-00';
        if ($ta === $tb) {
            return ((int) !empty($a['is_current'])) - ((int) !empty($b['is_current']));
        }
        return strcmp($ta, $tb);
    });

    $participants = array();
    $recognized = array();
    $seen_current = array();
    $seen_all = array();
    foreach ($history as $rec) {
        $is_cur = !empty($rec['is_current']);
        foreach ((array) ($rec['holders'] ?? array()) as $p) {
            $name = trim((string) ($p['full_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            $row = $p;
            $row['ownership_status'] = $is_cur ? 'current' : 'previous';
            $row['ownership_status_label'] = $is_cur ? 'Текущий собственник' : 'Предыдущий собственник';
            if (!empty($rec['period_label'])) {
                $row['ownership_period'] = $rec['period_label'];
            }
            if (!empty($rec['right_type'])) {
                $row['property_right_type'] = $rec['right_type'];
            }
            if (!empty($rec['registration_number'])) {
                $row['property_right_number'] = $rec['registration_number'];
            }
            if (!empty($rec['registration_date'])) {
                $row['property_right_date'] = $rec['registration_date'];
            }
            if (!isset($seen_all[$key])) {
                $seen_all[$key] = true;
                $recognized[] = $row;
            } elseif ($is_cur) {
                // Если человек встретился и в истории, и как текущий — оставляем статус «текущий».
                foreach ($recognized as $ri => $rp) {
                    $rk = function_exists('mb_strtolower') ? mb_strtolower((string) ($rp['full_name'] ?? ''), 'UTF-8') : strtolower((string) ($rp['full_name'] ?? ''));
                    if ($rk === $key) {
                        $recognized[$ri] = array_merge($rp, $row);
                        break;
                    }
                }
            }
            if ($is_cur && !isset($seen_current[$key])) {
                $seen_current[$key] = true;
                $participants[] = $row;
            }
        }
    }

    // В списке распознанных: сначала все текущие, затем история.
    usort($recognized, function ($a, $b) {
        $ca = (($a['ownership_status'] ?? '') === 'current') ? 0 : 1;
        $cb = (($b['ownership_status'] ?? '') === 'current') ? 0 : 1;
        if ($ca !== $cb) {
            return $ca - $cb;
        }
        return strcmp((string) ($a['full_name'] ?? ''), (string) ($b['full_name'] ?? ''));
    });

    return array(
        'history' => $history,
        'participants' => $participants,
        'recognized' => $recognized,
    );
}

/**
 * Правообладатели раздела 2 с паспортом и пропиской (только актуальные).
 *
 * @return array<int, array<string, mixed>>
 */
function yvo_egrn_parse_right_holders_full($text) {
    $parsed = yvo_egrn_parse_ownership_history($text);
    if (!empty($parsed['participants'])) {
        return $parsed['participants'];
    }
    // Запасной путь: старый одиночный блок.
    $section2 = yvo_egrn_extract_right_holders_block($text);
    if ($section2 === '') {
        if (preg_match('/Правообладатель\s*\(правообладатели\)[\s\S]{0,800}/ui', $text, $m)) {
            $section2 = $m[0];
            // Захватить ФИО перед меткой (онлайн-выписка Госуслуг).
            $pos = strpos($text, $m[0]);
            if ($pos !== false && $pos > 0) {
                $lookback = max(0, $pos - 400);
                $section2 = substr($text, $lookback, ($pos - $lookback) + strlen($m[0]));
            }
        } else {
            return array();
        }
    }
    return yvo_egrn_extract_holder_persons_from_block($section2);
}

/**
 * Адрес, кадастр, площадь, участники — из типовой структуры выписки ЕГРН.
 *
 * @return array{property:array<string,mixed>,participants:array<int,array>,ownership_history:array<int,array>}
 */
function yvo_egrn_extract_object_and_participants($text) {
    $property = array();
    $participants = array();
    $ownership_history = array();
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $text);
    $clean = preg_replace('/[ ]{2,}/u', ' ', str_replace("\t", ' ', $text));

    $section1 = $text;
    if (preg_match('/Сведения об основных характеристиках([\s\S]*?)(?:Сведения о зарегистрированных|Раздел\s*2\b|Получатель выписки)/ui', $text, $m)) {
        $section1 = $m[1];
    }

    if (preg_match('/Кадастровый\s+номер\s*:?\s+(\d{2}:\d{2}:\d{6,}:\d+)/ui', $section1, $m)) {
        $property['cadastral_number'] = $m[1];
    } elseif (preg_match('/Кадастровый\s+номер\s*:?\s*\n?\s*(\d{2}:\d{2}:\d{6,}:\d+)/ui', $clean, $m)) {
        $property['cadastral_number'] = $m[1];
    } elseif (preg_match('/\b(\d{2}:\d{2}:\d{6,}:\d+)\b/u', $section1, $m)) {
        $property['cadastral_number'] = $m[1];
    }

    $address = yvo_egrn_extract_mestopolozhenie($text);
    if ($address !== '') {
        $property['address'] = $address;
    }

    if (preg_match('/Площадь(?:\s*,?\s*м\s*2)?\s*:?\s*([0-9]+(?:[.,][0-9]+)?)/ui', $section1, $m)) {
        $property['area'] = (float) str_replace(',', '.', $m[1]);
    } elseif (preg_match('/Площадь(?:\s*,?\s*м\s*2)?\s*:?\s*([0-9]+(?:[.,][0-9]+)?)/ui', $clean, $m)) {
        $property['area'] = (float) str_replace(',', '.', $m[1]);
    } elseif (preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*,\s*Уточн[её]нная\s+площадь/ui', $section1, $m)
        || preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*,\s*Уточн[её]нная\s+площадь/ui', $clean, $m)) {
        // Сдвинутый layout онлайн-выписки: «630, Уточненная площадь» не у метки «Площадь».
        $property['area'] = (float) str_replace(',', '.', $m[1]);
    }
    if (preg_match('/\bколичество\s+комнат[^:\d]{0,20}(\d+)/ui', $clean, $m)) {
        $rooms = (int) $m[1];
        if ($rooms > 0) {
            $property['rooms'] = $rooms;
        }
    }
    if (preg_match('/Вид\s+жилого\s+помещения\s*:?\s*(Квартира|Комната)/ui', $section1, $m)
        || preg_match('/Вид\s+жилого\s+помещения\s*:?\s*(Квартира|Комната)/ui', $clean, $m)) {
        $property['property_type'] = preg_match('/комнат/ui', $m[1]) ? 'комната' : 'квартира';
    } elseif (preg_match('/Назначение\s*:?\s*(Жилое|Нежилое|жилой\s+дом|садовый\s+дом)/ui', $section1, $m)) {
        $purpose = trim($m[1]);
        $object_kind_tmp = yvo_egrn_detect_object_kind($text);
        if ($object_kind_tmp === 'apartment' || preg_match('/помещени|квартир/ui', $section1)) {
            $property['property_type'] = 'квартира';
        } elseif (preg_match('/жил/ui', $purpose)) {
            $property['property_type'] = 'жилой дом';
        } else {
            $property['property_type'] = function_exists('mb_strtolower') ? mb_strtolower($purpose, 'UTF-8') : strtolower($purpose);
        }
    }
    if (preg_match('/Этаж\s*№?\s*(\d+)/ui', $section1, $m) || preg_match('/Этаж\s*№?\s*(\d+)/ui', $clean, $m)) {
        $property['floor'] = (int) $m[1];
    } elseif (preg_match('/Номер,\s*тип\s+этажа\s+(\d+)\s*Этаж/ui', $clean, $m)
        || preg_match('/(\d+)\s*Этаж/ui', $section1, $m)) {
        $property['floor'] = (int) $m[1];
    }
    if (preg_match('/Количество\s+этажей[^:\d]*:\s*\n?\s*(\d+)/ui', $section1, $m)) {
        $property['floors_total'] = (int) $m[1];
    }
    if (preg_match('/Год\s+завершения\s+строительства\s*:\s*\n?\s*(\d{4})/ui', $section1, $m)) {
        $property['year_built'] = (int) $m[1];
    }
    if (preg_match('/Кадастровые\s+номера\s+иных\s+объектов[^\d]*(\d{2}:\d{2}:\d{6,}:\d+)/ui', $section1, $m)) {
        $plot_cad = $m[1];
        if ($plot_cad !== ($property['cadastral_number'] ?? '')) {
            $property['plot_cadastral_number'] = $plot_cad;
        }
    }

    $object_kind = yvo_egrn_detect_object_kind($text);
    if ($object_kind !== '') {
        $property['object_type'] = $object_kind;
    }
    if (preg_match('/\b(доля\s+в\s+праве|долевой\s+собственности)/ui', $clean)) {
        $property['property_type'] = 'доля в праве общей долевой собственности на квартиру';
        $property['object_type'] = 'share';
    }

    $property = yvo_egrn_normalize_property_by_object_type($property);

    $own = yvo_egrn_parse_ownership_history($text);
    $ownership_history = $own['history'];
    $participants = $own['participants'];
    $participants_all = !empty($own['recognized']) ? $own['recognized'] : $participants;
    if (empty($participants)) {
        $participants = yvo_egrn_parse_right_holders_full($text);
        $participants_all = $participants;
    }
    if (empty($participants)) {
        if (!class_exists('YVO_DeepSeekParser')) {
            $parser_file = defined('YVO_PLUGIN_DIR') ? YVO_PLUGIN_DIR . 'includes/deepseek-parser.php' : dirname(__FILE__) . '/deepseek-parser.php';
            if (is_file($parser_file)) {
                require_once $parser_file;
            }
        }
        if (class_exists('YVO_DeepSeekParser')) {
            $participants = YVO_DeepSeekParser::extract_participants_from_egrn_text($text);
            $participants_all = $participants;
        }
    }

    return array(
        'property' => $property,
        'participants' => $participants,
        'participants_all' => $participants_all,
        'ownership_history' => $ownership_history,
    );
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 * @return array<string, mixed>
 */
function yvo_merge_egrn_property_arrays(array $a, array $b) {
    foreach ($b as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        if (!isset($a[$k]) || $a[$k] === '' || $a[$k] === null) {
            $a[$k] = $v;
        }
    }
    return $a;
}

/**
 * Слияние с приоритетом полей из выписки ЕГРН (перезаписывает ошибочный адрес из подписи).
 *
 * @param array<string, mixed> $priority
 * @param array<string, mixed> $fallback
 * @return array<string, mixed>
 */
function yvo_merge_egrn_property_with_priority(array $priority, array $fallback) {
    $merged = yvo_merge_egrn_property_arrays($fallback, $priority);
    $overwrite_keys = array(
        'address', 'object_type', 'property_type', 'cadastral_number', 'area',
        'house_settlement', 'house_cadastral_number', 'house_area', 'house_floors', 'house_purpose',
        'plot_cadastral_number', 'year_built', 'floors_total', 'city', 'rooms',
    );
    foreach ($overwrite_keys as $k) {
        if (!empty($priority[$k])) {
            $merged[$k] = $priority[$k];
        }
    }
    return $merged;
}

/**
 * @param array<string, mixed>|null $base
 * @param array<string, mixed>|null $overlay
 * @return array<string, mixed>
 */
function yvo_merge_egrn_extracted_property($base, $overlay) {
    $base = is_array($base) ? $base : array();
    $overlay = is_array($overlay) ? $overlay : array();
    return yvo_merge_egrn_property_arrays($base, $overlay);
}

/**
 * Документы-основания из раздела 3 выписки (техплан, договор и т.д.).
 */
function yvo_egrn_extract_basis_documents_block($text) {
    $found = array();
    if (preg_match('/Документы-основания([\s\S]*?)(?=Ограничение\s+прав|ДОКУМЕНТ\s+ПОДПИСАН|---\s*Страница|Раздел\s+[34]\b|$)/ui', $text, $m)) {
        if (preg_match_all('/((?:Технический\s+план|Договор|Акт|Соглашение|Постановление|Решение|Свидетельство|Протокол)[^\n\r]{4,220})/ui', $m[1], $mm)) {
            foreach ($mm[1] as $line) {
                $b = yvo_egrn_normalize_basis_line(trim($line));
                if ($b !== '') {
                    $found[$b] = true;
                }
            }
        }
    }
    return implode(', ', array_keys($found));
}

/**
 * Краткое наименование банка из строки обременения.
 */
function yvo_egrn_extract_bank_short_name($line) {
    $line = trim((string) $line);
    if ($line === '') {
        return '';
    }
    if (preg_match('/«([^»]+)»/u', $line, $m)) {
        return 'ПАО ' . trim($m[1]);
    }
    return trim(preg_replace('/,\s*ИНН.*$/ui', '', $line));
}

/**
 * Обременения / ипотека — новый формат выписки ЕГРН (блок 4.x).
 *
 * @return array<string, mixed>
 */
function yvo_egrn_parse_encumbrance_details($text) {
    $check = array();
    if (!preg_match('/Ограничение\s+прав\s+и\s+обременение|ВИД:\s*\n?\s*Ипотека|\bИпотека\s*,\s*\d{2}:|Ипотека\s+в\s+силу\s+закона/ui', $text)) {
        return $check;
    }
    $block = $text;
    // Заголовок иногда идёт после «Вид: Ипотека» — захватить и предшествующие ~900 байт.
    // Важно: не резать UTF-8 посередине символа, иначе /u-регулярки на $block молча не сработают.
    if (preg_match('/Ограничение\s+прав\s+и\s+обременение[\s\S]*?(?=---\s*Страница|Раздел\s+[34]\b|ДОКУМЕНТ\s+ПОДПИСАН|$)/ui', $text, $m, PREG_OFFSET_CAPTURE)) {
        $hpos = (int) $m[0][1];
        $matched_len = strlen($m[0][0]);
        $from = max(0, $hpos - 900);
        while ($from > 0 && $from < $hpos && (ord($text[$from]) & 0xC0) === 0x80) {
            $from++;
        }
        $block = substr($text, $from, ($hpos - $from) + $matched_len);
    } elseif (preg_match('/(?:ВИД:\s*\n?\s*Ипотека|Ипотека\s*,\s*\d{2}:|Ипотека\s+в\s+силу\s+закона)[\s\S]{0,1200}/ui', $text, $m)) {
        $block = $m[0];
    }
    if (preg_match('/ВИД:\s*\n?\s*((?:Ипотека|Залог(?:\s+в\s+силу\s+закона)?|Аренда|Сервитут)[^\n\r,]{0,40})/ui', $block, $m)) {
        $type = trim($m[1]);
        if ($type !== '') {
            $check['encumbrance_type'] = $type;
        }
    } elseif (preg_match('/Вид,?\s*номер\s*и\s*дата\s*государственной\s*регистрации\s+((?:Ипотека|Залог(?:\s+в\s+силу\s+закона)?|Аренда|Сервитут)\s*,\s*[^\n\r]+)/ui', $block, $m)) {
        $line = yvo_egrn_normalize_multiline($m[1]);
        if (preg_match('/^((?:Ипотека|Залог(?:\s+в\s+силу\s+закона)?|Аренда|Сервитут))\s*,\s*([0-9][0-9:\-\/]*)\s*,\s*(\d{2}\.\d{2}\.\d{4})/ui', $line, $em)) {
            $check['encumbrance_type'] = trim($em[1]);
            $check['encumbrance_registration_number'] = yvo_egrn_normalize_reg_number($em[2]);
            $check['encumbrance_registration_date'] = $em[3];
        } else {
            $check['encumbrance_type'] = preg_match('/Ипотека|Залог/ui', $line, $tm) ? trim($tm[0]) : $line;
        }
    } elseif (preg_match('/\b((?:Ипотека(?:\s+в\s+силу\s+закона)?|Залог\s+в\s+силу\s+закона))\b/ui', $block, $m)) {
        $check['encumbrance_type'] = trim($m[1]);
    }
    if (empty($check['encumbrance_registration_date']) && preg_match('/дата\s+государственной\s+регистрации:\s*\n?\s*(\d{2}\.\d{2}\.\d{4}(?:\s+\d{2}:\d{2}:\d{2})?)/ui', $block, $m)) {
        $check['encumbrance_registration_date'] = trim($m[1]);
    }
    if (empty($check['encumbrance_registration_number']) && preg_match('/номер\s+государственной\s+регистрации:\s*\n?\s*(\d{2}:\d{2}:\d{6,}:\d+-\d{2}\/\d+\/\d{4}-\d+)/ui', $block, $m)) {
        $check['encumbrance_registration_number'] = yvo_egrn_normalize_reg_number($m[1]);
    }
    if (preg_match('/Срок\s+действия\s+([^\n\r]+(?:\n[^\n\r]+)?)/ui', $block, $m)) {
        $term = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t,.");
        if ($term !== '') {
            $check['restriction_term'] = $term;
        }
    } elseif (preg_match('/срок,\s*на\s+который\s+установлены[\s\S]{0,120}?(\d{2}\.\d{2}\.\d{4}[^\n\r]{0,120})/ui', $block, $m)) {
        $check['restriction_term'] = trim(preg_replace('/\s+/u', ' ', $m[1]));
    }
        if (preg_match('/лицо,\s*в\s+пользу\s+которого[\s\S]{0,220}?:?\s*((?:Публичное|ПАО|АО\s|Банк|ООО|АКБ|\"Газпромбанк\"|«Газпромбанк»)[^\n\r]{5,220})/ui', $block, $m)) {
            $check['bank_info'] = trim($m[1]);
        } elseif (preg_match('/лицо,\s*в\s+пользу\s+которого[\s\S]*?\n\s*([^\n\r]{10,250})/ui', $block, $m)) {
            $candidate = trim($m[1]);
            if ($candidate !== '' && !preg_match('/[:：]\s*$/u', $candidate) && !preg_match('/обременение|ограничение\s+прав/ui', $candidate)) {
                $check['bank_info'] = $candidate;
            }
        }
        if (empty($check['bank_info']) && preg_match('/(?:Залогодержатель|Ипотекодержатель|Кредитор)[:\s]*([^\n\r]{5,200})/ui', $block, $m)) {
            $check['bank_info'] = trim($m[1]);
        }
        if (empty($check['bank_info']) && preg_match('/((?:Публичное\s+акционерное\s+общество|ПАО|\"Газпромбанк\"|«Газпромбанк»)[^\n\r]{0,120})/ui', $block, $m)) {
            $check['bank_info'] = trim($m[1]);
        }
        if (!empty($check['bank_info'])) {
            $bi = trim((string) $check['bank_info']);
            $bi_len = function_exists('mb_strlen') ? mb_strlen($bi, 'UTF-8') : strlen($bi);
            if ($bi === '' || preg_match('/[:：]\s*$/u', $bi) || preg_match('/обременение|ограничение\s+прав|^объекта\s+недвижимости/ui', $bi) || $bi_len < 8) {
                unset($check['bank_info']);
            }
        }
        if (!empty($check['encumbrance_type']) && preg_match('/ипотек|залог/ui', $check['encumbrance_type'])) {
            $check['has_mortgage'] = 'да';
        } elseif (preg_match('/\b(?:Ипотека|Залог\s+в\s+силу\s+закона)\b/ui', $block)) {
            $check['has_mortgage'] = 'да';
            if (empty($check['encumbrance_type'])) {
                $check['encumbrance_type'] = preg_match('/Залог\s+в\s+силу\s+закона/ui', $block) ? 'Залог в силу закона' : 'Ипотека';
            }
        }
    $lines = array();
    if (!empty($check['encumbrance_type'])) {
        $lines[] = 'Вид: ' . $check['encumbrance_type'];
    }
    if (!empty($check['encumbrance_registration_date'])) {
        $lines[] = 'Дата регистрации: ' . $check['encumbrance_registration_date'];
    }
    if (!empty($check['encumbrance_registration_number'])) {
        $lines[] = 'Номер регистрации: ' . $check['encumbrance_registration_number'];
    }
    if (!empty($check['restriction_term'])) {
        $lines[] = 'Срок: ' . $check['restriction_term'];
    }
    if (!empty($check['bank_info'])) {
        $lines[] = 'В пользу: ' . $check['bank_info'];
    }
    if (!empty($lines)) {
        $check['restrictions_summary'] = implode('; ', $lines);
    }
    return $check;
}

/**
 * Объединить строки оснований без дублей.
 */
function yvo_egrn_merge_basis_strings($a, $b) {
    $found = array();
    foreach (array($a, $b) as $chunk) {
        $chunk = trim((string) $chunk);
        if ($chunk === '') {
            continue;
        }
        foreach (preg_split('/\s*,\s*/u', $chunk) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $found[$part] = true;
            }
        }
    }
    return implode(', ', array_keys($found));
}

/**
 * @return array{property:array<string,mixed>,egrn_check:array<string,mixed>}
 */
function yvo_egrn_extract_rights_and_encumbrances($text) {
    $property = array();
    $check = array();
    $clean = preg_replace('/[ \t]{2,}/u', ' ', (string) $text);

    // Кадастровая стоимость → price
    if (preg_match('/кадастров(?:ая|ой)\s+стоимост[ьи][^\d]{0,40}([\d\s]+(?:[.,]\d+)?)/ui', $clean, $m)) {
        $property['price'] = str_replace(array(' ', ','), array('', '.'), trim($m[1]));
    }

    // Жилая площадь
    if (preg_match('/жил(?:ая|ой)\s+площад[ьи][^\d]{0,30}([\d]+(?:[.,]\d+)?)/ui', $clean, $m)) {
        $property['living_area'] = str_replace(',', '.', $m[1]);
    }

    // Этаж / этажность (дополнительно к Property_Parser)
    if (preg_match('/расположен[аы]?\s+на\s+(\d+)\s*[-–]?\s*м?\s*этаж/ui', $clean, $m)) {
        $property['floor'] = (int) $m[1];
    }
    if (preg_match('/(\d+)\s*[-–]\s*этажн/ui', $clean, $m)) {
        $property['floors_total'] = (int) $m[1];
    }

    $section4 = yvo_egrn_extract_rights_section_scope($text);
    $scope = $section4 !== '' ? $section4 : $text;
    $scope_clean = preg_replace('/[ \t]{2,}/u', ' ', str_replace("\t", ' ', $scope));

    // Строка «Собственность, 02:55:040571:3124-02/372/2023-1, 14.12.2023»
    if (preg_match('/\b(\d{2}:\d{2}:\d{6,}:\d+-\d{2}\/\d+\/\d{4}-\d+)\b/u', $scope_clean, $m)) {
        $property['property_right_number'] = yvo_egrn_normalize_reg_number($m[1]);
    }
    if (preg_match('/(Собственность|общая\s+долев[а-яё]*\s+собственност[ьи]|долев[а-яё]*\s+собственност[ьи])\s*[,;\s]+(\d{2}:\d{2}:\d{6,}:\d+-\d{2}\/\d+\/\d{4}-\d+)\s*[,;\s]+(\d{2}\.\d{2}\.\d{4})/ui', $scope_clean, $m)) {
        $property['property_right_type'] = yvo_egrn_title_case_right_type($m[1]);
        $property['property_right_number'] = yvo_egrn_normalize_reg_number($m[2]);
        $property['property_right_date'] = $m[3];
    } elseif (preg_match('/(Собственность|общая\s+долев[а-яё]*\s+собственност[ьи]|долев[а-яё]*\s+собственност[ьи])\s*[,;\s]+(\d{2}-\d{2}[\d\-\/]+)\s*[,;\s]+(\d{2}\.\d{2}\.\d{4})/ui', $scope_clean, $m)) {
        $property['property_right_type'] = yvo_egrn_title_case_right_type($m[1]);
        $property['property_right_number'] = yvo_egrn_normalize_reg_number($m[2]);
        $property['property_right_date'] = $m[3];
    }

    // Раздел 4: номер регистрации права
    $num_patterns = array(
        '/\b(\d{2}-\d{2}-\d{2,}\/\d+\/\d{4}-\d+)\b/u',
        '/\b(\d{2}-\d{2}-\d{2,}\/\d+\/\d{4})\b/u',
        '/\b(\d{2}[\s\-:]\d{2}[\s\-:]\d{6,}[\s\/]\d{4}[\s\/]\d+(?:[\s\-]\d+)?)\b/u',
        '/\b(\d{2}-\d{2}-\d{6,}\/\d{4}\/\d+(?:-\d+)?)\b/u',
    );
    foreach ($num_patterns as $np) {
        if (preg_match($np, $scope_clean, $m)) {
            $property['property_right_number'] = yvo_egrn_normalize_reg_number($m[1]);
            break;
        }
    }
    if (empty($property['property_right_number']) && preg_match('/(?:Собственность|долев[а-яё]*\s+собственност[ьи])[\s\S]{0,400}?(\d{2}-\d{2}[\d\-\/]+)/ui', $scope, $m)) {
        $property['property_right_number'] = yvo_egrn_normalize_reg_number($m[1]);
    }
    if (empty($property['property_right_number'])) {
        if (preg_match('/Вид,\s*номер[^:]*:\s*([0-9\-\/\s]+)/ui', $scope_clean, $m)) {
            $property['property_right_number'] = yvo_egrn_normalize_reg_number($m[1]);
        } elseif (preg_match('/номер\s+регистрации[:\s]*([0-9\-\/\s]+)/ui', $scope_clean, $m)) {
            $property['property_right_number'] = yvo_egrn_normalize_reg_number($m[1]);
        }
    }

    if (empty($property['property_right_type'])) {
        $property['property_right_type'] = yvo_egrn_detect_right_type($scope);
    }
    if (empty($property['property_right_type']) && preg_match('/(?:^|\n)\s*Собственность\s*(?:\n|$)/u', $scope)) {
        $property['property_right_type'] = 'Собственность';
    }

    // Дата регистрации права
    if (empty($property['property_right_date']) && !empty($property['property_right_number'])) {
        $num_esc = preg_quote((string) $property['property_right_number'], '/');
        if (preg_match('/' . $num_esc . '\s+(\d{2}\.\d{2}\.\d{4})/u', $scope_clean, $m)) {
            $property['property_right_date'] = $m[1];
        }
    }
    if (empty($property['property_right_date']) && preg_match('/(?:Собственность|долев[а-яё]*\s+собственност[ьи])[\s\S]{0,350}?(\d{2}\.\d{2}\.\d{4})/ui', $scope, $m)) {
        $property['property_right_date'] = $m[1];
    } elseif (empty($property['property_right_date']) && preg_match('/дата\s+регистрации[:\s]*(\d{2}\.\d{2}\.\d{4})/ui', $scope_clean, $m)) {
        $property['property_right_date'] = $m[1];
    } elseif (empty($property['property_right_date']) && preg_match_all('/\b(\d{2}\.\d{2}\.\d{4})\b/u', $scope, $dm) && !empty($dm[1])) {
        foreach ($dm[1] as $candidate_date) {
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $candidate_date, $dp)) {
                $year = (int) $dp[3];
                if ($year >= 1990 && $year <= (int) date('Y') + 1) {
                    $property['property_right_date'] = $candidate_date;
                    break;
                }
            }
        }
    } elseif (empty($property['property_right_date']) && preg_match('/зарегистрировано[^\d]{0,120}(\d{2}\.\d{2}\.\d{4})/ui', $clean, $m)) {
        $property['property_right_date'] = $m[1];
    }

    // Основание(я) государственной регистрации — через запятую
    $basis = yvo_egrn_extract_all_registration_bases($text, $section4);
    $basis_docs = yvo_egrn_extract_basis_documents_block($text);
    if ($basis_docs !== '') {
        $check['basis_documents'] = $basis_docs;
        $basis = yvo_egrn_merge_basis_strings($basis, $basis_docs);
    }
    if ($basis !== '') {
        $property['ownership_basis_documents'] = $basis;
        $check['registration_basis'] = $basis;
    }

    $info = yvo_egrn_build_property_right_info_line($property);
    if ($info !== '') {
        $property['property_right_info'] = $info;
    }

    // Срок возникновения права
    if (preg_match('/дата\s+возникновения[^:]*:\s*(\d{2}\.\d{2}\.\d{4})/ui', $clean, $m)) {
        $check['right_start_date'] = $m[1];
        $property['property_right_date'] = $property['property_right_date'] ?? $m[1];
    }

    // Обременения / ограничения
    $enc = yvo_egrn_parse_encumbrance_details($text);
    foreach ($enc as $k => $v) {
        if ($v !== '' && $v !== null) {
            $check[$k] = $v;
        }
    }
    if (!empty($check['has_mortgage']) && $check['has_mortgage'] === 'да' && !empty($check['bank_info'])) {
        $property['bank_name'] = yvo_egrn_extract_bank_short_name($check['bank_info']);
    }
    if (preg_match('/не\s+зарегистрировано/ui', $clean) && preg_match('/обременен/ui', $clean) && empty($check['restrictions_summary'])) {
        $check['restrictions_summary'] = 'Обременения не зарегистрированы';
        $check['has_mortgage'] = 'нет';
    }

    // Доверенность / представитель
    if (preg_match('/доверенност[ьи][^\n]{0,200}/ui', $clean, $m)) {
        $check['power_of_attorney'] = 'Упоминается доверенность: ' . trim($m[0]);
    } elseif (preg_match('/нотариальн[а-я]+[^\n]{0,120}/ui', $clean, $m)) {
        $check['power_of_attorney'] = trim($m[0]);
    } else {
        $check['power_of_attorney'] = 'Не обнаружено в тексте выписки';
    }

    // Регистрация без личного участия (важный флаг из раздела «иные сведения»).
    if (preg_match('/невозможности\s+государственной\s+регистрации\s+без\s+личного\s+участия([\s\S]{0,220})/ui', $text, $m)) {
        $tail = yvo_egrn_normalize_multiline($m[1]);
        if (preg_match('/Принято\s+заявление|невозможн|без\s+личного\s+участия\s+правообладателя/ui', $tail)
            && !preg_match('/^:?\s*Данные\s+отсутствуют/ui', $tail)) {
            $check['registration_without_personal'] = 'выявлены';
            if (preg_match('/Принято\s+заявление[^.]+/ui', $tail, $sm)) {
                $check['registration_without_personal_detail'] = yvo_egrn_normalize_multiline($sm[0]);
            }
        } else {
            $check['registration_without_personal'] = 'не выявлены';
        }
    }

    // Доп. сведения
    $extra = array();
    if (preg_match('/Сведения,\s*необходимые\s+для\s+заполнения\s+раздела/ui', $text)) {
        $extra[] = 'Есть сведения для раздела 9 (налоговая отчётность)';
    }
    if (!empty($property['cadastral_number'])) {
        $extra[] = 'Кадастровый номер: ' . $property['cadastral_number'];
    }
    if (!empty($check['registration_without_personal']) && $check['registration_without_personal'] === 'выявлены') {
        $extra[] = 'Регистрация без личного участия: выявлены';
    }
    $check['additional_info'] = !empty($extra) ? implode('. ', $extra) : '';

    return array('property' => $property, 'egrn_check' => $check);
}

function yvo_egrn_normalize_multiline($s) {
    $s = preg_replace('/\s+/u', ' ', trim((string) $s));
    return trim($s, " \t\n\r\0\x0B,;");
}

/** Убрать служебные подписи OCR и мусор из строки адреса. */
function yvo_clean_address_string($address) {
    $a = trim(preg_replace('/\s+/u', ' ', (string) $address));
    if ($a === '') {
        return '';
    }
    $a = preg_replace('/^(?:Адрес\s*\(\s*местоположение\s*\)\s*,?\s*)+/ui', '', $a);
    $a = preg_replace('/(?:^|\s*,\s*)Адрес\s*\(\s*местоположение\s*\)[:\s]*/ui', '', $a);
    $a = preg_replace('/(?:^|\s*,\s*)(?:адрес|местонахождение|расположение)\s*(?:\(местоположение\))?[:\s]*/ui', '', $a);
    $a = preg_replace('/,\s*д\.?\s*Адрес\s*\(\s*местоположение\s*\)\s*,?/ui', ',', $a);
    $a = preg_replace('/,\s*д\.?\s*Адрес[^,]*/ui', '', $a);
    $a = preg_replace('/^.*?Российская Федерация,?\s*/ui', '', $a);
    $a = preg_replace('/^(?:республика|область|край|АО|автономный округ)[^,]+,\s*/ui', '', $a);
    $a = preg_replace('/\bг\.о\.\s*/ui', '', $a);
    $a = preg_replace('/\b\d{2}\.\d{2}\.\d{4}\b/u', '', $a);
    $a = preg_replace('/(?:^|,\s*)ород\s+/ui', ' город ', $a);
    $a = preg_replace('/,\s*,+/u', ',', $a);
    return trim($a, " \t,");
}

/** Поле адреса выглядит ошибочно распознанным. */
function yvo_address_field_looks_invalid($val, $key) {
    $v = trim((string) $val);
    if ($v === '') {
        return false;
    }
    if (preg_match('/^(адрес|местоположение|расположение)$/ui', $v)) {
        return true;
    }
    if (preg_match('/местоположение|\(\s*местоположение\s*\)/ui', $v)) {
        return true;
    }
    if ($key === 'city' && preg_match('/\d{2}\.\d{2}\.\d{4}|республик|област|край/ui', $v)) {
        return true;
    }
    if ($key === 'house' && !preg_match('/\d/u', $v)) {
        return true;
    }
    if ($key === 'apartment' && (!preg_match('/\d/u', $v) || preg_match('/артир/ui', $v))) {
        return true;
    }
    if ($key === 'address' && preg_match('/,\s*д\.?\s*Адрес|кв\.?\s*артир/ui', $v)) {
        return true;
    }
    return false;
}

/** Нужна ли доработка адреса (локальный парсер дал мусор). */
function yvo_property_address_needs_refinement($property) {
    if (!is_array($property)) {
        return true;
    }
    $addr = trim((string) ($property['address'] ?? ''));
    if ($addr === '') {
        return true;
    }
    if (yvo_address_field_looks_invalid($addr, 'address')) {
        return true;
    }
    foreach (array('city' => 'city', 'street' => 'street', 'house' => 'house') as $k => $type) {
        $v = trim((string) ($property[$k] ?? ''));
        if ($v !== '' && yvo_address_field_looks_invalid($v, $type)) {
            return true;
        }
    }
    if (trim((string) ($property['city'] ?? '')) === '' && trim((string) ($property['street'] ?? '')) === '') {
        $parts = yvo_split_russian_address($addr);
        if (empty($parts['city']) && empty($parts['street'])) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $property
 * @return array<string, mixed>
 */
function yvo_normalize_property_address_fields(array $property) {
    if (!empty($property['address'])) {
        $property['address'] = yvo_clean_address_string($property['address']);
    }
    $parts = !empty($property['address']) ? yvo_split_russian_address($property['address']) : array();
    foreach ($parts as $k => $v) {
        if ($v === '') {
            continue;
        }
        if (empty($property[$k]) || yvo_address_field_looks_invalid($property[$k], $k)) {
            $property[$k] = $v;
        }
    }
    foreach (array('city', 'street', 'house', 'building', 'apartment', 'address') as $k) {
        if (!empty($property[$k])) {
            $property[$k] = trim(preg_replace('/\b\d{2}\.\d{2}\.\d{4}\b/u', '', (string) $property[$k]));
            $property[$k] = trim(preg_replace('/\s+/u', ' ', $property[$k]), ' ,');
        }
    }
    if (!empty($parts['city']) || !empty($parts['street'])) {
        $property['address'] = yvo_format_address_from_parts($property);
    }
    return $property;
}

/** Собрать короткий адрес из частей. */
function yvo_format_address_from_parts(array $p) {
    $chunks = array();
    $city = trim((string) ($p['city'] ?? ''));
    if ($city !== '') {
        $chunks[] = preg_match('/^г\.?\s*/ui', $city) ? $city : ('г. ' . $city);
    }
    $settlement = trim((string) ($p['house_settlement'] ?? $p['settlement'] ?? ''));
    if ($settlement !== '') {
        $chunks[] = $settlement;
    }
    $street = trim((string) ($p['street'] ?? ''));
    if ($street !== '') {
        if (!preg_match('/^(ул\.?|улица|пр-кт|пр\.?|просп\.?|проспект|пер\.?|б-р|ш\.?|мкр)/ui', $street)) {
            $street = 'ул. ' . $street;
        }
        $chunks[] = $street;
    }
    $house = trim((string) ($p['house'] ?? ''));
    if ($house !== '' && preg_match('/\d/u', $house)) {
        $chunks[] = preg_match('/^д\.?\s*/ui', $house) ? $house : ('д. ' . $house);
    }
    $building = trim((string) ($p['building'] ?? ''));
    if ($building !== '') {
        $chunks[] = preg_match('/^корп\.?\s*/ui', $building) ? $building : ('корп. ' . $building);
    }
    $apt = trim((string) ($p['apartment'] ?? ''));
    if ($apt !== '' && preg_match('/\d/u', $apt) && !preg_match('/артир/ui', $apt)) {
        $chunks[] = preg_match('/^(кв\.?|квартира)/ui', $apt) ? $apt : ('кв. ' . $apt);
    }
    return implode(', ', $chunks);
}

/**
 * @return array{city?:string,street?:string,house?:string,building?:string,apartment?:string}
 */
function yvo_split_russian_address($address) {
    $a = yvo_clean_address_string($address);
    $out = array();
    if ($a === '') {
        return $out;
    }
    if (preg_match('/(?:^|,\s*)г\.о\.\s*город\s+([А-Яа-яЁё\-]+)/ui', $a, $m)) {
        $out['city'] = trim($m[1]);
    } elseif (preg_match('/(?:^|,\s*)город\s+([А-Яа-яЁё\-]+)/u', $a, $m)) {
        $out['city'] = trim($m[1]);
    } elseif (preg_match('/(?:^|,\s*)г\.?\s*([А-ЯЁа-яё]{2,}(?:-[А-ЯЁа-яё]+)?)/u', $a, $m)) {
        $out['city'] = trim($m[1]);
    }
    if (preg_match('/(?:^|,\s*)(?:пр-кт\.?\s*|пр\.?\s*|просп\.?\s*|проспект\s+)([^,]+)/ui', $a, $m)) {
        $out['street'] = 'пр-кт ' . trim($m[1]);
    } elseif (preg_match('/(?:^|,\s*)(?:ул\.?\s*|улица\s+)([^,]+)/ui', $a, $m)) {
        $out['street'] = trim($m[1]);
    } elseif (preg_match('/(?:^|,\s*)(?:мкр-?н?\.?\s*|микрорайон\s+)([^,]+)/ui', $a, $m)) {
        $out['street'] = 'мкр ' . trim($m[1]);
    } elseif (preg_match('/(?:^|,\s*)(?:пер\.?\s*|переулок\s+)([^,]+)/ui', $a, $m)) {
        $out['street'] = 'пер. ' . trim($m[1]);
    }
    // Дом только с цифрой; «д. Елкибаево» / «д Большие Жеребцы» — населённый пункт.
    if (preg_match('/(?:^|,\s*)(?:д\.?\s*|дом\s+)(\d+[0-9A-Za-zА-Яа-яЁё\/\-]*)/ui', $a, $m)) {
        $house = trim($m[1]);
        if (!yvo_address_field_looks_invalid($house, 'house')) {
            $out['house'] = $house;
        }
    } elseif (preg_match('/(?:пр-кт|ул\.|улица|пер\.|просп\.|мкр)\s+[^,]+,\s*(?:д\.?\s*)?(\d+[0-9A-Za-zА-Яа-яЁё\/\-]*)/ui', $a, $m)) {
        $house = trim($m[1]);
        if (!yvo_address_field_looks_invalid($house, 'house')) {
            $out['house'] = $house;
        }
    } elseif (empty($out['house']) && preg_match('/(?:^|,\s*)(\d+[0-9A-Za-zА-Яа-яЁё\/\-]*)\s*,\s*кв\.?\s*\d+/ui', $a, $m)) {
        // Обломок «17/6, кв. 34» без «д.» — всё же вытащим дом.
        $house = trim($m[1]);
        if (!yvo_address_field_looks_invalid($house, 'house')) {
            $out['house'] = $house;
        }
    }
    if (preg_match('/(?:^|,\s*)(?:д\.?|дер\.|деревня|с\.|село|пос\.|пгт\.?)\s*([А-Яа-яЁё][А-Яа-яЁё\-]*(?:\s+[А-Яа-яЁё\-]+){0,3})(?=,|$)/ui', $a, $m)) {
        $settlement = trim($m[1]);
        // Не путать «д. 73/3» (дом) с населённым пунктом.
        if ($settlement !== ''
            && !preg_match('/^\d/u', $settlement)
            && !preg_match('/^(г|город)$/ui', $settlement)
            && !preg_match('/\d/u', $settlement)) {
            $prefix = 'д.';
            if (preg_match('/(?:^|,\s*)(с\.|село)\s*' . preg_quote($settlement, '/') . '/ui', $a)) {
                $prefix = 'с.';
            } elseif (preg_match('/(?:^|,\s*)(пос\.|пгт)/ui', $a)) {
                $prefix = 'пос.';
            }
            $out['settlement'] = $settlement;
            $out['house_settlement'] = $prefix . ' ' . $settlement;
        }
    }
    if (preg_match('/(?:^|,\s*)(?:корп\.?\s*|корпус\s+)([0-9A-Za-zА-Яа-яЁё\/\-]+)/ui', $a, $m)) {
        $out['building'] = trim($m[1]);
    }
    // «кв. 50», «квартира 50», «кв 117» (без точки).
    if (preg_match('/(?:^|,\s*)(?:квартира|кв\.?)\s*([0-9][0-9A-Za-zА-Яа-яЁё\/\-]*)/ui', $a, $m)) {
        $apt = trim($m[1]);
        if (!preg_match('/артир/ui', $apt)) {
            $out['apartment'] = $apt;
        }
    }
    foreach ($out as $k => $v) {
        $out[$k] = trim(preg_replace('/\b\d{2}\.\d{2}\.\d{4}\b/u', '', $v));
        $out[$k] = trim(preg_replace('/\s+/u', ' ', $out[$k]), ' ,');
    }
    return $out;
}

/**
 * Типы документов в загруженном тексте (может быть несколько).
 *
 * @return array<int, string>
 */
function yvo_detect_document_kinds($text) {
    $text = (string) $text;
    $kinds = array();
    if (function_exists('yvo_text_looks_like_egrn_document') && yvo_text_looks_like_egrn_document($text)) {
        $kinds[] = 'egrn';
    } elseif (preg_match('/кадастров|егрн|единый\s+государственный\s+реестр|\d{2}:\d{2}:\d{6,}/ui', $text)) {
        $kinds[] = 'egrn';
    }
    if (function_exists('yvo_text_looks_like_birth_certificate') && yvo_text_looks_like_birth_certificate($text)) {
        $kinds[] = 'birth_certificate';
    } elseif (preg_match('/свидетельство\s+о\s+рождени/ui', $text)) {
        $kinds[] = 'birth_certificate';
    }
    if (preg_match('/доверенност/ui', $text)) {
        $kinds[] = 'power_of_attorney';
    }
    if (preg_match('/паспорт|passport|код\s+подразделения|выдан\s+отделом/ui', $text)) {
        $kinds[] = 'passport';
    }
    if (preg_match('/договор\s+купли|дкп|пдкп|предварительн\w*\s+договор|соглашение\s+о\s+задатке|договор\s+уступки|переуступк|акт\s+при[eё]ма[-\s]передачи|передачи\s+в\s+недви|договор\s+передачи/ui', $text)) {
        $kinds[] = 'basis_contract';
    }
    if (preg_match('/расч[её]тн|к\/с\b|бик\b|квитанц|плат[её]жн/ui', $text)) {
        $kinds[] = 'requisites';
    }
    return array_values(array_unique($kinds));
}
