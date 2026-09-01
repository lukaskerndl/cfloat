<?php
session_start();
require_once __DIR__ . '/lib.php';

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

function csf_api_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function csf_api_invoice_in_date_range(array $inv, string $dateFrom, string $dateTo): bool {
    $issue = (string)($inv['issue_date'] ?? '');
    if ($dateFrom !== '' && $issue !== '' && $issue < $dateFrom) return false;
    if ($dateTo !== '' && $issue !== '' && $issue > $dateTo) return false;
    return true;
}


function csf_xml($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function csf_pohoda_num($n, int $decimals = 4): string {
    $n = (float)$n;
    $s = number_format($n, $decimals, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

function csf_pohoda_address_parts(array $inv): array {
    $raw = trim((string)($inv['customer_address'] ?? ''));
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw))));
    $street = $lines[0] ?? '';
    $zip = '';
    $city = '';
    $rest = $lines[1] ?? '';
    if ($rest !== '' && preg_match('/^(\d{3}\s?\d{2})\s+(.+)$/u', $rest, $m)) {
        $zip = str_replace(' ', '', $m[1]);
        $city = trim($m[2]);
    } elseif ($rest !== '') {
        $city = $rest;
    }
    return ['street' => $street, 'zip' => $zip, 'city' => $city];
}

function csf_pohoda_invoice_xml(array $inv): string {
    $no = preg_replace('/[^0-9]/', '', (string)($inv['invoice_number'] ?? ''));
    if ($no === '' || strpos($no, '9') !== 0) return '';

    $date = (string)($inv['issue_date'] ?? date('Y-m-d'));
    $taxDate = (string)($inv['tax_date'] ?? $date);
    $dueDate = (string)($inv['due_date'] ?? $date);
    $customerName = (string)($inv['customer_name'] ?? 'C-Servis');
    $phone = (string)($inv['customer_phone'] ?? '');
    $ico = (string)($inv['customer_ico'] ?? '');
    $dic = (string)($inv['customer_dic'] ?? '');
    $addr = csf_pohoda_address_parts($inv);

    $itemsXml = '';
    foreach (($inv['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $name = trim((string)($it['name'] ?? ''));
        $code = trim((string)($it['code'] ?? ''));
        if ($name === '' && $code === '') continue;
        $qty = csf_num($it['qty'] ?? 1);
        if ($qty <= 0) $qty = 1;
        $unitNet = csf_num($it['unit_net'] ?? 0);
        $unitGross = csf_num($it['unit_gross'] ?? 0);
        if ($unitNet <= 0 && $unitGross > 0) $unitNet = csf_unit_net_from_gross($unitGross);
        $text = $name !== '' ? $name : $code;
        $itemsXml .= '<inv:invoiceItem>' . "\n";
        $itemsXml .= '  <inv:text>' . csf_xml($text) . '</inv:text>' . "\n";
        $itemsXml .= '  <inv:quantity>' . csf_pohoda_num($qty, 4) . '</inv:quantity>' . "\n";
        $itemsXml .= '  <inv:unit>ks</inv:unit>' . "\n";
        $itemsXml .= '  <inv:rateVAT>high</inv:rateVAT>' . "\n";
        $itemsXml .= '  <inv:payVAT>false</inv:payVAT>' . "\n";
        $itemsXml .= '  <inv:homeCurrency><typ:unitPrice>' . csf_pohoda_num($unitNet, 4) . '</typ:unitPrice></inv:homeCurrency>' . "\n";
        if ($code !== '') {
            // Pohoda u Cservisu nemá založený sklad, proto exportujeme jen textovou položku + kód.
            $itemsXml .= '  <inv:code>' . csf_xml($code) . '</inv:code>' . "\n";
            $itemsXml .= '  <inv:guarantee>48</inv:guarantee>' . "\n";
            $itemsXml .= '  <inv:guaranteeType>month</inv:guaranteeType>' . "\n";
        }
        $itemsXml .= '</inv:invoiceItem>' . "\n";
    }
    if ($itemsXml === '') return '';

    $net = csf_num($inv['totals']['net'] ?? 0);
    $vat = csf_num($inv['totals']['vat'] ?? 0);
    $gross = csf_num($inv['totals']['gross'] ?? 0);
    if ($gross > 0 && $net <= 0) $net = csf_round($gross / 1.21);
    if ($gross > 0 && $vat <= 0) $vat = csf_round($gross - $net);

    return '<dat:dataPackItem id="' . csf_xml($no) . '" version="2.0">' . "\n" .
'<inv:invoice version="2.0">' . "\n" .
'  <inv:invoiceHeader>' . "\n" .
'    <inv:invoiceType>issuedInvoice</inv:invoiceType>' . "\n" .
'    <inv:number><typ:numberRequested>' . csf_xml($no) . '</typ:numberRequested></inv:number>' . "\n" .
'    <inv:symVar>' . csf_xml((string)($inv['variable_symbol'] ?? $no)) . '</inv:symVar>' . "\n" .
'    <inv:date>' . csf_xml($date) . '</inv:date>' . "\n" .
'    <inv:dateTax>' . csf_xml($taxDate) . '</inv:dateTax>' . "\n" .
'    <inv:dateDue>' . csf_xml($dueDate) . '</inv:dateDue>' . "\n" .
'    <inv:accounting><typ:ids>3Fv</typ:ids></inv:accounting>' . "\n" .
'    <inv:classificationVAT><typ:ids>UD</typ:ids></inv:classificationVAT>' . "\n" .
'    <inv:text>Fakturujeme Vám zboží dle Vaší objednávky:' . csf_xml($no) . '</inv:text>' . "\n" .
'    <inv:paymentType><typ:paymentType>creditcard</typ:paymentType></inv:paymentType>' . "\n" .
'    <inv:note>Načteno z XML</inv:note>' . "\n" .
'    <inv:intNote></inv:intNote>' . "\n" .
'    <inv:partnerIdentity>' . "\n" .
'      <typ:address>' . "\n" .
'        <typ:company></typ:company>' . "\n" .
'        <typ:division></typ:division>' . "\n" .
'        <typ:name>' . csf_xml($customerName) . '</typ:name>' . "\n" .
'        <typ:city>' . csf_xml($addr['city']) . '</typ:city>' . "\n" .
'        <typ:street>' . csf_xml($addr['street']) . '</typ:street>' . "\n" .
'        <typ:zip>' . csf_xml($addr['zip']) . '</typ:zip>' . "\n" .
'        <typ:country><typ:ids>CZ</typ:ids></typ:country>' . "\n" .
'        <typ:ico>' . csf_xml($ico) . '</typ:ico>' . "\n" .
'        <typ:dic>' . csf_xml($dic) . '</typ:dic>' . "\n" .
'        <typ:mobilPhone>' . csf_xml($phone) . '</typ:mobilPhone>' . "\n" .
'        <typ:email></typ:email>' . "\n" .
'      </typ:address>' . "\n" .
'      <typ:shipToAddress>' . "\n" .
'        <typ:company></typ:company>' . "\n" .
'        <typ:division></typ:division>' . "\n" .
'        <typ:name>' . csf_xml($customerName) . '</typ:name>' . "\n" .
'        <typ:city>' . csf_xml($addr['city']) . '</typ:city>' . "\n" .
'        <typ:street>' . csf_xml($addr['street']) . '</typ:street>' . "\n" .
'        <typ:zip>' . csf_xml($addr['zip']) . '</typ:zip>' . "\n" .
'        <typ:country><typ:ids>CZ</typ:ids></typ:country>' . "\n" .
'        <typ:phone>' . csf_xml($phone) . '</typ:phone>' . "\n" .
'        <typ:email></typ:email>' . "\n" .
'      </typ:shipToAddress>' . "\n" .
'    </inv:partnerIdentity>' . "\n" .
'    <inv:numberOrder>' . csf_xml($no) . '</inv:numberOrder>' . "\n" .
'  </inv:invoiceHeader>' . "\n" .
'  <inv:invoiceDetail>' . "\n" . $itemsXml . '  </inv:invoiceDetail>' . "\n" .
'  <inv:invoiceSummary>' . "\n" .
'    <inv:roundingVAT>none</inv:roundingVAT>' . "\n" .
'    <inv:homeCurrency>' . "\n" .
'      <typ:priceNone>0</typ:priceNone>' . "\n" .
'      <typ:price3>0</typ:price3><typ:price3VAT>0</typ:price3VAT><typ:price3Sum>0</typ:price3Sum>' . "\n" .
'      <typ:priceLow>0</typ:priceLow><typ:priceLowVAT>0</typ:priceLowVAT><typ:priceLowSum>0</typ:priceLowSum>' . "\n" .
'      <typ:priceHigh>' . csf_pohoda_num($net, 2) . '</typ:priceHigh>' . "\n" .
'      <typ:priceHighVAT>' . csf_pohoda_num($vat, 2) . '</typ:priceHighVAT>' . "\n" .
'      <typ:priceHighSum>' . csf_pohoda_num($gross, 2) . '</typ:priceHighSum>' . "\n" .
'      <typ:round><typ:priceRound>0</typ:priceRound></typ:round>' . "\n" .
'    </inv:homeCurrency>' . "\n" .
'  </inv:invoiceSummary>' . "\n" .
'</inv:invoice>' . "\n" .
'</dat:dataPackItem>' . "\n";
}

function csf_pohoda_export_xml(string $dateFrom, string $dateTo): array {
    $invoices = csf_load_invoices();
    $items = '';
    $count = 0;
    foreach ($invoices as $inv) {
        if (!is_array($inv)) continue;
        $no = (string)($inv['invoice_number'] ?? '');
        if (strpos($no, '9') !== 0) continue;
        if ((string)($inv['workflow_state'] ?? 'final') === 'new') continue;
        if (!csf_api_invoice_in_date_range($inv, $dateFrom, $dateTo)) continue;
        $xml = csf_pohoda_invoice_xml($inv);
        if ($xml === '') continue;
        $items .= $xml;
        $count++;
    }
    $id = 'cservis9_' . date('Ymd_His');
    $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n" .
'<dat:dataPack id="fa003" ico="09730761" application="StwTest" version="2.0" note="Import FA"' . "\n" .
'  xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"' . "\n" .
'  xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"' . "\n" .
'  xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd">' . "\n" .
$items . '</dat:dataPack>' . "\n";
    return ['xml' => $xml, 'count' => $count, 'id' => $id];
}

if (empty($_SESSION['logged_in'])) {
    if ($action === 'pdf') {
        http_response_code(403);
        echo 'Nepřihlášeno.';
        exit;
    }
    csf_api_json(['ok' => false, 'message' => 'Nepřihlášeno.'], 403);
}

try {

    if ($action === 'pohoda_export') {
        $dateFrom = isset($_GET['date_from']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['date_to']) : '';
        $export = csf_pohoda_export_xml($dateFrom, $dateTo);
        $filename = 'pohoda_cservis_rada9_' . ($dateFrom ?: 'od') . '_' . ($dateTo ?: 'do') . '.xml';
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $export['xml'];
        exit;
    }

    if ($action === 'search') {
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;
        $limit = max(1, min(100, $limit));
        csf_api_json(['ok' => true, 'items' => csf_search_products($q, $limit)]);
    }

    if ($action === 'rebuild_cache') {
        $items = csf_load_products(true);
        csf_api_json(['ok' => true, 'message' => 'Cache produktů byla obnovena.', 'count' => count($items)]);
    }

    if ($action === 'create') {
        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) throw new RuntimeException('Neplatná data formuláře.');
        $invoice = csf_prepare_invoice($payload);
        $invoice = csf_save_invoice($invoice);
        csf_api_json([
            'ok' => true,
            'message' => 'Faktura byla vytvořena do stavu Nová.',
            'invoice' => csf_public_invoice($invoice),
            'pdf_url' => 'modules/cservis-fakturace/api.php?action=pdf&no=' . rawurlencode((string)$invoice['invoice_number']),
        ]);
    }


    if ($action === 'update') {
        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) throw new RuntimeException('Neplatná data formuláře.');
        $invoiceNumber = isset($payload['invoice_number']) ? preg_replace('/[^0-9]/', '', (string)$payload['invoice_number']) : '';
        if ($invoiceNumber === '') throw new RuntimeException('Chybí číslo faktury.');

        $invoices = csf_load_invoices();
        if (empty($invoices[$invoiceNumber]) || !is_array($invoices[$invoiceNumber])) {
            throw new RuntimeException('Faktura nebyla nalezena.');
        }
        $old = $invoices[$invoiceNumber];
        if ((string)($old['workflow_state'] ?? 'final') !== 'new') {
            throw new RuntimeException('Upravovat lze pouze fakturu ve stavu Nová.');
        }

        $tz = new DateTimeZone('Europe/Prague');
        $issueRaw = trim((string)($payload['issue_date'] ?? ($old['issue_date'] ?? '')));
        $issue = DateTime::createFromFormat('Y-m-d', $issueRaw, $tz);
        if (!($issue instanceof DateTime)) $issue = DateTime::createFromFormat('Y-m-d', (string)($old['issue_date'] ?? ''), $tz);
        if (!($issue instanceof DateTime)) $issue = new DateTime('now', $tz);
        $issue->setTime(0,0,0);
        $due = clone $issue;
        $due->modify('+10 days');

        $payment = (string)($payload['payment'] ?? ($old['payment'] ?? 'cash'));
        $payment = $payment === 'card' ? 'card' : 'cash';
        $customerSuffix = (string)($payload['customer_suffix'] ?? ($old['customer_suffix'] ?? ''));
        $customer = csf_customer_name($customerSuffix);

        $items = [];
        foreach (($payload['items'] ?? []) as $rawItem) {
            if (!is_array($rawItem)) continue;
            $code = trim((string)($rawItem['code'] ?? ''));
            $name = trim((string)($rawItem['name'] ?? ''));
            if ($code === '' && $name === '') continue;
            $qty = csf_num($rawItem['qty'] ?? 1);
            if ($qty <= 0) $qty = 1;
            $unitGrossBase = csf_num($rawItem['unit_gross'] ?? 0);
            $discount = csf_num($rawItem['discount_percent'] ?? 0);
            if ($discount < 0) $discount = 0;
            if ($discount > 100) $discount = 100;
            $unitGross = csf_round($unitGrossBase * (1 - ($discount / 100)));
            $unitNet = csf_unit_net_from_gross($unitGross);
            $totalGross = csf_round($unitGross * $qty);
            $totalNet = csf_round($unitNet * $qty);
            $totalVat = csf_round($totalGross - $totalNet);
            $items[] = [
                'code' => $code,
                'name' => $name,
                'qty' => $qty,
                'unit_gross_base' => $unitGrossBase,
                'discount_percent' => $discount,
                'purchase_price' => csf_num($rawItem['purchase_price'] ?? 0),
                'unit_net' => $unitNet,
                'unit_gross' => $unitGross,
                'vat_rate' => csf_vat_rate(),
                'total_net' => $totalNet,
                'total_vat' => $totalVat,
                'total_gross' => $totalGross,
            ];
        }
        if (!$items) throw new RuntimeException('Faktura nemá žádnou položku.');

        $sumGross = 0.0;
        foreach ($items as $it) $sumGross += csf_num($it['total_gross'] ?? 0);
        $sumGross = csf_round($sumGross);
        $sumNet = csf_round($sumGross / 1.21);
        $sumVat = csf_round($sumGross - $sumNet);

        $invoice = $old;
        $invoice['invoice_number'] = $invoiceNumber;
        $invoice['variable_symbol'] = $invoiceNumber;
        $invoice['issue_date'] = $issue->format('Y-m-d');
        $invoice['tax_date'] = $issue->format('Y-m-d');
        $invoice['due_date'] = $due->format('Y-m-d');
        $invoice['payment'] = $payment;
        $invoice['payment_label'] = csf_payment_label($payment);
        $invoice['transport'] = 'Osobní odběr';
        $invoice['customer_suffix'] = trim($customerSuffix);
        $invoice['customer_name'] = $customer;
        $invoice['customer_phone'] = trim((string)($payload['customer_phone'] ?? ''));
        $invoice['customer_address'] = trim((string)($payload['customer_address'] ?? ''));
        $invoice['customer_ico'] = trim((string)($payload['customer_ico'] ?? ''));
        $invoice['customer_dic'] = trim((string)($payload['customer_dic'] ?? ''));
        $invoice['note'] = trim((string)($payload['note'] ?? ''));
        $invoice['items'] = $items;
        $invoice['totals'] = ['net' => $sumNet, 'vat' => $sumVat, 'gross' => $sumGross];
        $invoice['workflow_state'] = 'new';
        $invoice['updated_at'] = date('Y-m-d H:i:s');
        $invoice['pdf_file'] = csf_invoice_dir() . '/' . $invoiceNumber . '.pdf';

        csf_generate_invoice_pdf($invoice, $invoice['pdf_file']);
        $invoices[$invoiceNumber] = $invoice;
        krsort($invoices, SORT_STRING);
        if (!csf_save_invoices($invoices)) throw new RuntimeException('Nepodařilo se uložit upravenou fakturu.');

        csf_api_json([
            'ok' => true,
            'message' => 'Faktura byla upravena.',
            'invoice' => csf_public_invoice($invoice),
            'pdf_url' => 'modules/cservis-fakturace/api.php?action=pdf&no=' . rawurlencode($invoiceNumber),
        ]);
    }

    if ($action === 'move') {
        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) throw new RuntimeException('Neplatná data formuláře.');
        $invoiceNumber = isset($payload['invoice_number']) ? (string)$payload['invoice_number'] : '';
        $targetPayment = isset($payload['target_payment']) ? (string)$payload['target_payment'] : 'cash';
        $invoice = csf_move_invoice_series($invoiceNumber, $targetPayment);
        csf_api_json([
            'ok' => true,
            'message' => 'Faktura byla přesunuta do jiné řady.',
            'invoice' => csf_public_invoice($invoice),
            'pdf_url' => 'modules/cservis-fakturace/api.php?action=pdf&no=' . rawurlencode((string)$invoice['invoice_number']),
        ]);
    }



    if ($action === 'delete') {
        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) throw new RuntimeException('Neplatná data formuláře.');
        $invoiceNumber = isset($payload['invoice_number']) ? preg_replace('/[^0-9]/', '', (string)$payload['invoice_number']) : '';
        if ($invoiceNumber === '') throw new RuntimeException('Chybí číslo faktury.');
        $invoices = csf_load_invoices();
        if (empty($invoices[$invoiceNumber]) || !is_array($invoices[$invoiceNumber])) {
            throw new RuntimeException('Faktura nebyla nalezena.');
        }
        unset($invoices[$invoiceNumber]);
        if (!csf_save_invoices($invoices)) throw new RuntimeException('Fakturu se nepodařilo smazat z evidence.');
        $pdf = csf_invoice_dir() . '/' . $invoiceNumber . '.pdf';
        if (is_file($pdf)) @unlink($pdf);
        csf_api_json(['ok' => true, 'message' => 'Faktura byla smazána.', 'invoice_number' => $invoiceNumber]);
    }

    if ($action === 'get') {
        $no = isset($_GET['no']) ? preg_replace('/[^0-9]/', '', (string)$_GET['no']) : '';
        if ($no === '') throw new RuntimeException('Chybí číslo faktury.');
        $invoices = csf_load_invoices();
        if (empty($invoices[$no]) || !is_array($invoices[$no])) throw new RuntimeException('Faktura nebyla nalezena.');
        $inv = $invoices[$no];
        csf_api_json([
            'ok' => true,
            'invoice' => csf_public_invoice($inv),
            'full' => [
                'invoice_number' => (string)($inv['invoice_number'] ?? ''),
                'customer_suffix' => (string)($inv['customer_suffix'] ?? ''),
                'customer_name' => (string)($inv['customer_name'] ?? ''),
                'customer_phone' => (string)($inv['customer_phone'] ?? ''),
                'customer_address' => (string)($inv['customer_address'] ?? ''),
                'customer_ico' => (string)($inv['customer_ico'] ?? ''),
                'customer_dic' => (string)($inv['customer_dic'] ?? ''),
                'payment' => (string)($inv['payment'] ?? ''),
                'issue_date' => (string)($inv['issue_date'] ?? ''),
                'note' => (string)($inv['note'] ?? ''),
                'items' => is_array($inv['items'] ?? null) ? array_values($inv['items']) : [],
                'profit_detail' => csf_invoice_profit_detail($inv),
            ],
        ]);
    }


    if ($action === 'report') {
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $dateFrom = isset($_GET['date_from']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['date_to']) : '';
        $invoices = csf_load_invoices();
        $rows = [];
        $summary = [
            'count_items' => 0,
            'count_invoices' => 0,
            'total_qty' => 0.0,
            'total_purchase' => 0.0,
            'total_sale_for_profit' => 0.0,
            'total_profit' => 0.0,
            'total_net' => 0.0,
            'total_gross' => 0.0,
        ];
        $seenInvoices = [];
        foreach ($invoices as $inv) {
            if (!is_array($inv)) continue;
            if ((string)($inv['workflow_state'] ?? 'final') === 'new') continue;
            if (!csf_api_invoice_in_date_range($inv, $dateFrom, $dateTo)) continue;
            if (!csf_invoice_matches_query($inv, $q)) continue;
            $pub = csf_public_invoice($inv);
            $detail = csf_invoice_profit_detail($inv);
            $seenInvoices[$pub['invoice_number']] = true;
            foreach (($detail['rows'] ?? []) as $r) {
                if (!is_array($r)) continue;
                $row = [
                    'invoice_number' => $pub['invoice_number'],
                    'issue_date' => $pub['issue_date'],
                    'customer_name' => $pub['customer_name'],
                    'customer_phone' => $pub['customer_phone'],
                    'payment' => $pub['payment'],
                    'payment_label' => $pub['payment_label'],
                    'profit_mode_label' => (string)($detail['profit_mode_label'] ?? ''),
                    'code' => (string)($r['code'] ?? ''),
                    'name' => (string)($r['name'] ?? ''),
                    'qty' => (float)($r['qty'] ?? 0),
                    'purchase_unit' => (float)($r['purchase_unit'] ?? 0),
                    'purchase_total' => (float)($r['purchase_total'] ?? 0),
                    'unit_net' => (float)($r['unit_net'] ?? 0),
                    'unit_gross' => (float)($r['unit_gross'] ?? 0),
                    'total_net' => (float)($r['total_net'] ?? 0),
                    'total_gross' => (float)($r['total_gross'] ?? 0),
                    'sale_for_profit' => (float)($r['sale_for_profit'] ?? 0),
                    'profit' => (float)($r['profit'] ?? 0),
                ];
                $summary['count_items']++;
                $summary['total_qty'] += $row['qty'];
                $summary['total_purchase'] += $row['purchase_total'];
                $summary['total_sale_for_profit'] += $row['sale_for_profit'];
                $summary['total_profit'] += $row['profit'];
                $summary['total_net'] += $row['total_net'];
                $summary['total_gross'] += $row['total_gross'];
                $rows[] = $row;
            }
        }
        $summary['count_invoices'] = count($seenInvoices);
        foreach ($summary as $k => $v) {
            if (is_float($v)) $summary[$k] = csf_round($v);
        }
        csf_api_json(['ok' => true, 'summary' => $summary, 'items' => array_slice($rows, 0, 2000)]);
    }

    if ($action === 'list') {
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $dateFrom = isset($_GET['date_from']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['date_from']) : '';
        $dateTo = isset($_GET['date_to']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['date_to']) : '';
        $invoices = csf_load_invoices();
        $rows = [];
        foreach ($invoices as $inv) {
            if (!is_array($inv)) continue;
            if (!csf_api_invoice_in_date_range($inv, $dateFrom, $dateTo)) continue;
            if (!csf_invoice_matches_query($inv, $q)) continue;
            $pub = csf_public_invoice($inv);
            $previewItems = [];
            foreach (($inv['items'] ?? []) as $it) {
                if (!is_array($it)) continue;
                $previewItems[] = [
                    'code' => (string)($it['code'] ?? ''),
                    'name' => (string)($it['name'] ?? ''),
                    'qty' => (float)csf_num($it['qty'] ?? 1),
                    'unit_net' => (float)csf_num($it['unit_net'] ?? 0),
                    'unit_gross' => (float)csf_num($it['unit_gross'] ?? 0),
                    'total_net' => (float)csf_num($it['total_net'] ?? 0),
                    'total_gross' => (float)csf_num($it['total_gross'] ?? 0),
                ];
                if (count($previewItems) >= 6) break;
            }
            $pub['preview_items'] = $previewItems;
            $rows[] = $pub;
        }
        csf_api_json(['ok' => true, 'items' => array_slice($rows, 0, 100)]);
    }

    if ($action === 'pdf') {
        $no = isset($_GET['no']) ? preg_replace('/[^0-9]/', '', (string)$_GET['no']) : '';
        if ($no === '') throw new RuntimeException('Chybí číslo faktury.');
        $invoices = csf_load_invoices();
        if (empty($invoices[$no]) || !is_array($invoices[$no])) throw new RuntimeException('Faktura nebyla nalezena.');
        $inv = $invoices[$no];
        $path = csf_invoice_dir() . '/' . $no . '.pdf';
        if (!is_file($path)) csf_generate_invoice_pdf($inv, $path);
        if (!is_file($path)) throw new RuntimeException('PDF se nepodařilo vytvořit.');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="faktura_' . $no . '.pdf"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    csf_api_json(['ok' => false, 'message' => 'Neznámá akce.'], 404);
} catch (Throwable $e) {
    if ($action === 'pdf') {
        http_response_code(500);
        echo 'Chyba: ' . $e->getMessage();
        exit;
    }
    csf_api_json(['ok' => false, 'message' => $e->getMessage()], 500);
}
