<?php
/**
 * Классический стиль ДКП: преамбула «Мы, ФИО…», вариант 2 (Домклик), HTML с жирным выделением.
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Шаблоны с классической преамбулой и вёрсткой как в образце. */
function yvo_dkp_uses_classic_style($template_id) {
    $template_id = (string) $template_id;
    return in_array($template_id, array('default', 'contract-variant2', 'dkp-sale-standard'), true);
}

/** default (ДКП обычный) → contract-variant2.txt */
function yvo_resolve_dkp_default_txt_path() {
    $variant2 = YVO_PLUGIN_DIR . 'templates/contract-variant2.txt';
    if (file_exists($variant2) && filesize($variant2) > 100) {
        return $variant2;
    }
    $fallback = YVO_PLUGIN_DIR . 'templates/default.txt';
    return file_exists($fallback) ? $fallback : null;
}

/** Регион из адреса объекта (для строки «Город … Республика …»). */
function yvo_extract_region_from_address($address) {
    $address = trim((string) $address);
    if ($address === '') {
        return '________________';
    }
    if (preg_match('/Республика\s+[^,]+/u', $address, $m)) {
        return trim($m[0]);
    }
    if (preg_match('/край\s+[^,]+/ui', $address, $m)) {
        return trim($m[0]);
    }
    if (preg_match('/область\s+[^,]*/ui', $address, $m)) {
        return trim($m[0]);
    }
    return '________________';
}

/** Строка «Город Уфа Республика Башкортостан … дата» для классического шаблона. */
function yvo_build_dkp_classic_city_date_line($contract_city, $contract_date, $region = '') {
    $city = trim((string) $contract_city);
    if ($city === '' || $city === '________________') {
        $city = '________________';
    } else {
        $city = function_exists('yvo_format_contract_city_display')
            ? yvo_format_contract_city_display($city)
            : $city;
        $city = preg_replace('/^г\.\s*/ui', '', $city);
    }
    $region = trim((string) $region);
    if ($region === '') {
        $region = '________________';
    }
    $date = trim((string) $contract_date);
    if ($date === '' || $date === '________________') {
        $date = '«__» __________ 20__ г.';
    }
    return 'Город ' . $city . ' ' . $region . str_repeat(' ', max(1, 80 - mb_strlen($city . $region, 'UTF-8'))) . $date;
}

/**
 * Одна строка участника для классической преамбулы.
 *
 * @param array<string,mixed> $row
 */
function yvo_build_classic_party_segment(array $row, $role_label, $suffix) {
    $name = trim((string) ($row['full_name'] ?? ''));
    if ($name === '') {
        $name = '________________';
    }
    $birth = trim((string) ($row['birth_date'] ?? ''));
    if ($birth === '') {
        $birth = '____________';
    }
    $birth_place = trim((string) ($row['birth_place'] ?? ''));
    if ($birth_place === '') {
        $birth_place = '____________________';
    }
    $ser = trim((string) ($row['passport_series'] ?? ''));
    $num = trim((string) ($row['passport_number'] ?? ''));
    $passport = ($ser !== '' && $num !== '') ? ($ser . ' ' . $num) : '____ ________';
    $issued = trim((string) ($row['passport_issued_by'] ?? ''));
    if ($issued === '') {
        $issued = '_______________________________';
    }
    $dept = trim((string) ($row['department_code'] ?? ''));
    if ($dept === '') {
        $dept = '_______';
    }
    $reg = trim((string) ($row['registration'] ?? ''));
    if ($reg === '') {
        $reg = '________________';
    }
    return $name . ', дата рождения: ' . $birth . ', место рождения: ' . $birth_place
        . ', паспорт РФ ' . $passport . ', выдан ' . $issued . ', код подразделения ' . $dept
        . ', зарегистрированный(-ая,-ые) по адресу: ' . $reg
        . ', именуемый(-ая,-ые) в дальнейшем «' . $role_label . '»' . $suffix;
}

/**
 * Классическая преамбула: «Мы, … с одной стороны, и, … с другой стороны, … о нижеследующем:»
 *
 * @param array<int,array<string,mixed>> $sellers
 * @param array<int,array<string,mixed>> $buyers
 */
function yvo_build_classic_parties_preamble(array $sellers, array $buyers, $contract_type = 'sale') {
    yvo_prepare_parties_for_contract_generation($sellers, $buyers);
    $principal_sellers = yvo_parties_principal_sellers_ordered($sellers);
    $principal_buyers = yvo_parties_principal_buyers_ordered($buyers);
    if (empty($principal_sellers) || empty($principal_buyers)) {
        return '';
    }
    $seller_parts = array();
    foreach ($principal_sellers as $i => $s) {
        if (!is_array($s)) {
            continue;
        }
        $label = yvo_contract_party_display_label($contract_type, 'seller', 'principal', $i + 1);
        if (count($principal_sellers) === 1) {
            $label = 'Продавец';
        }
        $seller_parts[] = yvo_build_classic_party_segment($s, $label, '');
    }
    $seller_intro = count($principal_sellers) > 1 ? 'Мы, ' : 'Мы, ';
    $seller_text = $seller_intro . implode(', ', $seller_parts) . ', с одной стороны,';

    $buyer_parts = array();
    foreach ($principal_buyers as $i => $b) {
        if (!is_array($b)) {
            continue;
        }
        $label = yvo_contract_party_display_label($contract_type, 'buyer', 'principal', $i + 1);
        if (count($principal_buyers) === 1) {
            $label = 'Покупатель';
        }
        $buyer_parts[] = yvo_build_classic_party_segment($b, $label, '');
    }
    $buyer_text = 'и, ' . implode(', ', $buyer_parts) . ', с другой стороны,';

    return $seller_text . "\n" . $buyer_text
        . ' именуемые в дальнейшем совместно «Стороны», заключили настоящий договор купли-продажи (далее - Договор) о нижеследующем:';
}

/** Описание объекта одной строкой (п. 1.1 вариант 2). */
function yvo_build_variant2_property_inline(array $property_data) {
    $meta = function_exists('yvo_build_dkp_object_meta') ? yvo_build_dkp_object_meta($property_data) : array();
    $kind = isset($meta['PROPERTY_EGRN_KIND']) ? (string) $meta['PROPERTY_EGRN_KIND'] : 'Помещение';
    $name = isset($meta['PROPERTY_EGRN_NAME']) ? (string) $meta['PROPERTY_EGRN_NAME'] : 'Квартира';
    $purpose = isset($meta['PROPERTY_EGRN_PURPOSE']) ? (string) $meta['PROPERTY_EGRN_PURPOSE'] : 'Жилое';
    $area = isset($property_data['area']) && $property_data['area'] !== '' ? trim((string) $property_data['area']) : '___';
    $floor = isset($property_data['floor']) && $property_data['floor'] !== '' ? trim((string) $property_data['floor']) : '___';
    $addr = trim((string) ($property_data['address'] ?? ''));
    if ($addr === '') {
        $addr = '________________';
    }
    $cad = trim((string) ($property_data['cadastral_number'] ?? ''));
    if ($cad === '') {
        $cad = '____________________';
    }
    return $kind . ', ' . $name . ', назначение ' . $purpose . ', площадь ' . $area . ' кв.м, этаж – ' . $floor
        . ' расположенная по адресу ' . $addr . ', с кадастровым номером ' . $cad
        . ', далее по тексту (объект \\ недвижимое имущество).';
}

/** П. 1.4 — согласие супруга (если указано в форме). */
function yvo_build_variant2_spouse_clause(array $property_data) {
    $note = trim((string) ($property_data['spouse_consent_note'] ?? $property_data['marriage_consent'] ?? ''));
    if ($note === '') {
        return "1.4. Продавец сообщает что на момент покупки объекта недвижимости, указанного в пункте 1.1. в зарегистрированном браке состоял, Согласие супруга предоставлено.\n";
    }
    return '1.4. ' . $note . "\n";
}

/** Раздел 2 вариант 2 (собственные + кредит + Домклик). */
function yvo_build_variant2_payment_section(array $property_data, array $options, array $buyers) {
    $g = function ($k, $fb = '__________') use ($options, $property_data) {
        if (isset($options[$k]) && trim((string) $options[$k]) !== '' && trim((string) $options[$k]) !== '[сумма]') {
            return (string) $options[$k];
        }
        if (isset($property_data[$k]) && trim((string) $property_data[$k]) !== '') {
            return (string) $property_data[$k];
        }
        return $fb;
    };
    $price = $g('property_price', '__________');
    $price_words = $g('property_price_words', '________________');
    $own = $g('loan_own_amount', '__________');
    $own_words = $g('loan_own_amount_words', '________________');
    $credit = $g('loan_credit_amount', '__________');
    $credit_words = $g('loan_credit_amount_words', '________________');
    $loan_num = $g('loan_agreement_number', '[номер]');
    $loan_date = $g('loan_agreement_date', '[дата]');
    $city = $g('contract_city', '________________');
    $bank = $g('bank_name', '[наименование банка]');
    $prepaid = $g('paid_before_signing', '[сумма]');
    $main_pay = $g('paid_at_signing', $price);
    if ($main_pay === '[сумма]' || $main_pay === '__________') {
        $main_pay = $price;
    }
    $buyer_name = '________________';
    if (!empty($buyers[0]['full_name'])) {
        $buyer_name = trim((string) $buyers[0]['full_name']);
    }
    $seller_pay = trim((string) ($property_data['seller_details'] ?? $property_data['seller_details_mortgage'] ?? ''));
    if ($seller_pay === '') {
        $seller_pay = 'по реквизитам Продавца';
    }
    $domclick = trim((string) ($property_data['domclick_payment_block'] ?? ''));
    if ($domclick === '') {
        $domclick = "2.3. Порядок расчетов по Договору.\n"
            . "2.3.1. Денежная сумма в размере {$prepaid} уплачена до подписания настоящего договора.\n"
            . "2.3.2. Расчет денежной суммы в размере {$main_pay} рублей производится с использованием номинального счета Общества с ограниченной ответственностью «Домклик» (ООО «Домклик»), ИНН 7736249247, открытого в Операционном управлении Московского банка ПАО Сбербанк г.Москва, к/счет 30101810400000000225, БИК 044525225. Бенефициаром в отношении денежных средств, размещаемых на номинальном счете, является Покупатель.\n"
            . "Перечисление денежных средств в размере {$main_pay} рублей Продавцу в счет оплаты Объекта недвижимости осуществляется ООО «Домклик», ИНН 7736249247 по поручению Покупателя после государственной регистрации перехода права собственности на Объект недвижимости к Заемщику и к иным лицам (при наличии), а также государственной регистрации ипотеки Объекта недвижимости в силу закона в пользу Банка, {$seller_pay}.\n"
            . "Передача денежных средств в размере {$main_pay} рублей Продавцу в счет оплаты стоимости Объекта осуществляется в течение от 1 (одного) рабочего дня до 5 (пяти) рабочих дней с момента получения ООО «Домклик» информации от органа, осуществляющего государственную регистрацию, о переходе права собственности на объект недвижимого имущества, указанный в п.1 Договора к Покупателю и ипотеки Объекта в силу закона в пользу Банка в органе, осуществляющем государственную регистрацию прав на недвижимое имущество и сделок с ним.";
    }
    return "2.1. Стоимость Объекта составляет {$price} рублей ({$price_words}). Цена является окончательной и изменению не подлежит.\n"
        . "2.2. Стороны устанавливают следующий порядок оплаты стоимости Объекта.\n"
        . "2.2.1. Часть стоимости Объекта в сумме {$own} рублей ({$own_words}) оплачиваются за счет собственных денежных средств Покупателя.\n"
        . "2.2.2. Часть стоимости Объекта в сумме {$credit} рублей ({$credit_words}) оплачивается за счет целевых кредитных денежных средств, предоставленных: {$buyer_name} в соответствии с Кредитным договором № {$loan_num} от {$loan_date}, заключенным в городе {$city} (далее – Кредитный договор), {$bank} (далее – Банк). Условия предоставления кредита предусмотрены Кредитным договором.\n\n"
        . $domclick;
}

/** Раздел 3 вариант 2. */
function yvo_build_variant2_essential_section(array $property_data, array $options) {
    $acceptance = isset($options['acceptance_days']) && trim((string) $options['acceptance_days']) !== ''
        ? trim((string) $options['acceptance_days']) : '14';
    if (preg_match('/\d+/', $acceptance, $m)) {
        $acceptance = $m[0];
    }
    $vacate = isset($property_data['vacate_deadline']) && trim((string) $property_data['vacate_deadline']) !== ''
        ? trim((string) $property_data['vacate_deadline']) : '14';
    if (preg_match('/\d+/', $vacate, $m)) {
        $vacate = $m[0];
    }
    $furniture = trim((string) ($property_data['furniture_list'] ?? ''));
    $furniture_clause = $furniture !== ''
        ? ' Продавец передает покупателю недвижимое имущество вместе с мебелью: ' . $furniture . '.'
        : '';
    return "3.1. С момента государственной регистрации ипотеки в Едином государственном реестре недвижимости, Объект находится в залоге (ипотеке) у Банка на основании ст.77 Федерального закона «Об ипотеке (залоге недвижимости)» №102-ФЗ от 16.07.1998.\n"
        . "3.2. При регистрации права собственности Покупателя на Объект одновременно подлежит регистрации право залога Объекта в пользу Банка. Залогодержателем по данному залогу является Банк, а Залогодателем – Покупатель.\n"
        . "3.3. Право залога у Продавца на Объект не возникает в соответствии с п.5 ст.488 Гражданского кодекса РФ.\n"
        . "3.4. Покупатель обязуется в течение всего периода действия ипотеки на Объект без предварительного письменного согласия Банка: не отчуждать Объект и не осуществлять ее последующую ипотеку; не сдавать Объект в аренду/наем, не передавать в безвозмездное пользование либо иным образом не обременять ее правами третьих лиц; не проводить переустройство и перепланировку Объекта.\n"
        . "3.5. Покупатель осмотрел Объект и претензий по его качеству не имеет. Продавец обязуется передать Объект в том состоянии, каком он имеется на день подписания Договора." . $furniture_clause . "\n"
        . "3.6. В соответствии со ст. 556 Гражданского кодекса Российской Федерации, передача Объекта Продавцом и принятие его Покупателем осуществляется по передаточному акту, подписываемому Сторонами после государственной регистрации перехода права собственности на Объект к Покупателю, не позднее {$acceptance} дней с момента поступления денежных средств на счет Продавца по договору купли-продажи недвижимости. Стороны не связывают момент перехода права собственности с условием о передаче Объекта. Государственная регистрация перехода права собственности будет осуществлена без предоставления передаточного акта. Договор имеет силу Акта.\n"
        . "3.7. Продавец гарантирует, что на момент подписания Договора Объект не отчужден, под арестом не состоит, в аренду (наем) не сдан, возмездное или безвозмездное пользование не передан, не обременен правами третьих лиц, право собственности Продавца никем не оспаривается. Лиц, сохраняющих в соответствии с законом право пользования Объектом после государственной регистрации перехода права собственности на Объект к Покупателю, не имеется (статьи 292, 558 Гражданского кодекса РФ).\n"
        . "3.8. Продавец сообщает, что в объекте недвижимого имущества никто не зарегистрирован. Лиц, сохраняющих в соответствии с законом право пользования Объектом после государственной регистрации перехода права собственности на Объект к Покупателю, не имеется (статьи 292, 558 Гражданского кодекса РФ). Продавец обязуется освободить Объект от своих личных вещей и передать ключи не позднее {$vacate} дней с момента полной оплаты по настоящему договору купли-продажи. Риск случайной гибели(повреждения) Объекта (квартиры) переходит на Покупателя после передачи ключей.\n"
        . "3.9. Покупатель приобретает право собственности на Объект с момента внесения записи в Единый государственный реестр недвижимости о переходе права собственности в установленном законом порядке к Покупателю. При этом Покупатель принимает на себя обязанности по уплате налогов на имущество, осуществляет за свой счет эксплуатацию и ремонт Объекта.\n"
        . "3.10. Продавец гарантирует, что не является иностранным агентом в понимании Федерального закона от 14.07.2022 N 255-ФЗ «О контроле за деятельностью лиц, находящихся под иностранным влиянием», и он не обязан использовать специальный рублевый счет, открытый в уполномоченном банке, режим которого, в том числе особенности внесения на него платежей и списания с него средств, устанавливается решением Совета директоров Центрального банка Российской Федерации, подлежащим официальному опубликованию в соответствии со статьей 7 Федерального закона от 10 июля 2002 года N 86-ФЗ «О Центральном банке Российской Федерации (Банке России).";
}

/** Доп. плейсхолдеры для классического ДКП / вариант 2. */
function yvo_build_classic_dkp_placeholder_extras($template_id, array $sellers, array $buyers, array $property_data, array $options, $contract_type) {
    $tpl = (string) $template_id;
    if (!yvo_dkp_uses_classic_style($tpl)) {
        return array();
    }
    $city = isset($options['contract_city']) ? (string) $options['contract_city'] : '';
    $date = isset($options['contract_date']) ? (string) $options['contract_date'] : date('d.m.Y');
    $region = yvo_extract_region_from_address(isset($property_data['address']) ? (string) $property_data['address'] : '');
    $extras = array(
        'DKP_CLASSIC_CITY_DATE_LINE' => yvo_build_dkp_classic_city_date_line($city, $date, $region),
        'CLASSIC_PARTIES_PREAMBLE' => yvo_build_classic_parties_preamble($sellers, $buyers, $contract_type),
        'DKP_DOC_TITLE' => 'Договор купли-продажи',
        'CONTRACT_REGION' => $region,
    );
    if ($tpl === 'default' || $tpl === 'contract-variant2') {
        $extras['VARIANT2_PROPERTY_INLINE'] = yvo_build_variant2_property_inline($property_data);
        $extras['VARIANT2_SPOUSE_CLAUSE'] = yvo_build_variant2_spouse_clause($property_data);
        $extras['VARIANT2_PAYMENT_SECTION'] = yvo_build_variant2_payment_section($property_data, $options, $buyers);
        $extras['VARIANT2_ESSENTIAL_SECTION'] = yvo_build_variant2_essential_section($property_data, $options);
    }
    return $extras;
}

/** Список фраз для жирного выделения в классическом HTML. */
function yvo_classic_dkp_collect_bold_terms(array $replacements, $contract_text) {
    $terms = array();
    $keys = array(
        'SELLER1_FULL_NAME', 'SELLER2_FULL_NAME', 'BUYER1_FULL_NAME', 'BUYER2_FULL_NAME',
        'PROPERTY_ADDRESS', 'PROPERTY_CADASTRAL_NUM', 'PROPERTY_PRICE', 'PROPERTY_PRICE_WORDS',
        'LOAN_OWN_AMOUNT', 'LOAN_OWN_AMOUNT_WORDS', 'LOAN_CREDIT_AMOUNT', 'LOAN_CREDIT_AMOUNT_WORDS',
        'LOAN_AGREEMENT_NUMBER', 'LOAN_AGREEMENT_DATE', 'BANK_NAME', 'PAID_BEFORE_SIGNING', 'PAID_AT_SIGNING',
        'CLASSIC_PARTIES_PREAMBLE',
    );
    foreach ($keys as $k) {
        if (!empty($replacements[$k]) && !yvo_html_is_placeholder_value((string) $replacements[$k])) {
            $v = trim((string) $replacements[$k]);
            if (mb_strlen($v, 'UTF-8') >= 3) {
                $terms[] = $v;
            }
        }
    }
    if (preg_match_all('/\b\d{2}:\d{2}:\d{6,}:\d+\b/u', (string) $contract_text, $m)) {
        foreach ($m[0] as $cad) {
            $terms[] = $cad;
        }
    }
    if (preg_match_all('/\b\d{4}\s\d{6}\b/u', (string) $contract_text, $m2)) {
        foreach ($m2[0] as $p) {
            $terms[] = $p;
        }
    }
    if (preg_match_all('/\d[\d\s]{2,}(?:,\d{2})?\s*рублей(?:\s*\([^)]+\))?/ui', (string) $contract_text, $m3)) {
        foreach ($m3[0] as $sum) {
            $terms[] = trim($sum);
        }
    }
    if (preg_match_all('/«[^»]+»/u', (string) $contract_text, $m4)) {
        foreach ($m4[0] as $q) {
            if (mb_stripos($q, 'Продавец', 0, 'UTF-8') !== false || mb_stripos($q, 'Покупатель', 0, 'UTF-8') !== false || mb_stripos($q, 'Стороны', 0, 'UTF-8') !== false) {
                $terms[] = $q;
            }
        }
    }
    $terms[] = 'Договор имеет силу Акта.';
    $terms[] = 'ООО «Домклик»';
    $terms = array_unique(array_filter($terms, function ($t) {
        return mb_strlen((string) $t, 'UTF-8') >= 3;
    }));
    usort($terms, function ($a, $b) {
        return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
    });
    return $terms;
}

/** Оборачивает известные фразы в <strong>. */
function yvo_classic_dkp_boldify_html($html, array $terms) {
    $escaped = htmlspecialchars((string) $html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    foreach ($terms as $term) {
        $term = trim((string) $term);
        if ($term === '' || mb_strlen($term, 'UTF-8') < 3) {
            continue;
        }
        $e = htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($e === '' || strpos($escaped, '<strong>' . $e . '</strong>') !== false) {
            continue;
        }
        $escaped = str_replace($e, '<strong>' . $e . '</strong>', $escaped);
    }
    return $escaped;
}

/** CSS классического ДКП (как на образце). */
function yvo_dkp_classic_styles_css() {
    return "body{margin:0;padding:0;background:#fff;color:#000;font-family:'Times New Roman',Times,serif;font-size:12pt;line-height:1.35;}"
        . ".page{width:210mm;min-height:297mm;margin:0 auto;padding:18mm 16mm 14mm;box-sizing:border-box;}"
        . ".dkp-title{text-align:center;font-weight:bold;font-size:13pt;margin:0 0 10pt;}"
        . ".dkp-city-date{font-size:12pt;margin:0 0 14pt;white-space:pre-wrap;}"
        . ".dkp-preamble{text-align:justify;margin:0 0 14pt;text-indent:0;}"
        . ".dkp-body{text-align:justify;}"
        . ".dkp-clause{margin:0 0 8pt;text-indent:1.25cm;}"
        . ".dkp-section{font-weight:bold;margin:12pt 0 6pt;text-indent:0;}"
        . ".dkp-subclause{text-indent:1.25cm;margin:0 0 6pt;}"
        . ".dkp-signatures{margin-top:18pt;}"
        . ".dkp-signatures .sig-label{font-weight:bold;margin:16pt 0 4pt;}"
        . ".dkp-signatures .sig-line{border-bottom:1px solid #000;height:14pt;margin:0 0 2pt;}"
        . "strong{font-weight:bold;}";
}

/** Текст договора → HTML-тело с отступами. */
function yvo_classic_dkp_body_to_html($contract_text, array $bold_terms) {
    $lines = preg_split('/\r\n|\r|\n/', (string) $contract_text);
    $html = '';
    $started = false;
    foreach ($lines as $line) {
        $trim = trim($line);
        if (!$started) {
            if (preg_match('/^1\.\s+/u', $trim)) {
                $started = true;
            } else {
                continue;
            }
        }
        if (preg_match('/^5\.\s+Подписи/ui', $trim)) {
            break;
        }
        if ($trim === '') {
            continue;
        }
        if (preg_match('/^(\d+)\.\s+(.+)$/u', $trim, $sec) && !preg_match('/^\d+\.\d+/u', $trim)) {
            $html .= '<div class="dkp-section">' . yvo_classic_dkp_boldify_html($sec[1] . '. ' . $sec[2], $bold_terms) . '</div>';
            continue;
        }
        $html .= '<div class="dkp-clause">' . yvo_classic_dkp_boldify_html($trim, $bold_terms) . '</div>';
    }
    return $html;
}

/** Классический HTML всего договора. */
function yvo_generate_dkp_classic_styled_html($contract_content_raw, array $replacements, $contract_type = 'sale') {
    $city_line = isset($replacements['DKP_CLASSIC_CITY_DATE_LINE'])
        ? (string) $replacements['DKP_CLASSIC_CITY_DATE_LINE']
        : '';
    $preamble = isset($replacements['CLASSIC_PARTIES_PREAMBLE'])
        ? (string) $replacements['CLASSIC_PARTIES_PREAMBLE']
        : '';
    $bold_terms = yvo_classic_dkp_collect_bold_terms($replacements, $contract_content_raw);
    $body_html = yvo_classic_dkp_body_to_html($contract_content_raw, $bold_terms);
    $sigs_html = yvo_signatures_block_text_to_html(isset($replacements['SIGNATURES_BLOCK']) ? (string) $replacements['SIGNATURES_BLOCK'] : '', false);
    if ($sigs_html !== '') {
        $sigs_html = '<div class="dkp-signatures">' . $sigs_html . '</div>';
    }
    return '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Договор купли-продажи</title>'
        . '<style>' . yvo_dkp_classic_styles_css() . '</style></head><body spellcheck="false">'
        . '<div class="page">'
        . '<div class="dkp-title">Договор купли-продажи</div>'
        . '<div class="dkp-city-date">' . yvo_classic_dkp_boldify_html($city_line, $bold_terms) . '</div>'
        . '<div class="dkp-preamble">' . yvo_classic_dkp_boldify_html($preamble, $bold_terms) . '</div>'
        . '<div class="dkp-body">' . $body_html . '</div>'
        . $sigs_html
        . '</div></body></html>';
}
