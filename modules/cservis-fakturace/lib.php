<?php
// Cservis FAKTURACE - pomocne funkce modulu

if (!function_exists('csf_h')) {
    function csf_h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function csf_root_dir(): string { return __DIR__; }

function csf_lower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
function csf_strlen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}
function csf_substr(string $s, int $start, ?int $len = null): string {
    if (function_exists('mb_substr')) return $len === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $len, 'UTF-8');
    return $len === null ? substr($s, $start) : substr($s, $start, $len);
}

function csf_data_dir(): string {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    return $dir;
}

function csf_invoice_dir(): string {
    $dir = csf_data_dir() . '/invoices';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function csf_products_xlsx_path(): string {
    return csf_data_dir() . '/SERVIS_ALL.xlsx';
}

function csf_json_load(string $file, $default) {
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return $default;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $default;
}

function csf_json_save(string $file, $data): bool {
    @mkdir(dirname($file), 0775, true);
    $tmp = $file . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $file);
}

function csf_money(float $amount): string {
    return number_format($amount, 2, ',', ' ') . ' Kč';
}

function csf_money_pdf(float $amount): string {
    $s = str_replace(' ', '&nbsp;', csf_h(csf_money($amount)));
    return '<span style="white-space:nowrap;">' . $s . '</span>';
}

function csf_qty_pdf(float $qty): string {
    if (abs($qty - round($qty)) < 0.00001) {
        $s = number_format($qty, 0, ',', ' ') . '&nbsp;ks';
    } else {
        $s = number_format($qty, 2, ',', ' ') . '&nbsp;ks';
    }
    return '<span style="white-space:nowrap;">' . $s . '</span>';
}

function csf_num($raw): float {
    $s = trim((string)$raw);
    if ($s === '') return 0.0;
    $s = str_replace(["\xC2\xA0", ' '], '', $s);
    $s = str_replace(',', '.', $s);
    $s = preg_replace('/[^0-9.\-]/', '', $s);
    if ($s === '' || $s === '-' || $s === '.') return 0.0;
    return (float)$s;
}

function csf_round(float $n): float { return round($n + 0.0000001, 2); }
function csf_vat_rate(): float { return 21.0; }
function csf_unit_net_from_gross(float $gross): float { return csf_round($gross / 1.21); }

function csf_norm_text($s): string {
    $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = csf_lower($s);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if (is_string($converted) && $converted !== '') $s = $converted;
    $s = preg_replace('/[^a-z0-9]+/i', ' ', $s);
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    return $s;
}

function csf_excluded_product_terms(): array {
    static $terms = null;
    if ($terms !== null) return $terms;
    $raw = ['craft', 'silvini', 'haglos', 'haglofs', 'inov', 'isadore', 'ale', 'd1913', 'didrikson1913', 'didriksons1913', 'neon', 'primus', 'devold'];
    $terms = [];
    foreach ($raw as $t) $terms[] = csf_norm_text($t);
    return array_values(array_unique(array_filter($terms)));
}

function csf_product_is_excluded(array $product): bool {
    $hay = csf_norm_text((string)($product['code'] ?? '') . ' ' . (string)($product['ean'] ?? '') . ' ' . (string)($product['name'] ?? '') . ' ' . (string)($product['description'] ?? ''));
    if ($hay === '') return false;
    foreach (csf_excluded_product_terms() as $term) {
        if ($term === '') continue;
        if ($term === 'ale') {
            if (preg_match('/(^|\s)ale($|\s)/', $hay)) return true;
            continue;
        }
        if (strpos($hay, $term) !== false) return true;
    }
    return false;
}

function csf_xlsx_col_to_index(string $letters): int {
    $letters = strtoupper(preg_replace('/[^A-Z]/', '', $letters));
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n;
}

function csf_xlsx_cell_col_letters(string $cellRef): string {
    if (preg_match('/^[A-Z]+/i', $cellRef, $m)) return strtoupper($m[0]);
    return '';
}

function csf_xlsx_shared_strings(ZipArchive $zip): array {
    $shared = [];
    $sstXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sstXml === false || $sstXml === '') return $shared;

    $xr = new XMLReader();
    if (!$xr->XML($sstXml)) return $shared;
    $i = -1;
    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'si') {
            $i++;
            $outer = $xr->readOuterXML();
            $txt = '';
            if ($outer !== '' && preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $outer, $mm)) {
                foreach ($mm[1] as $part) {
                    $txt .= html_entity_decode($part, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
            $shared[$i] = $txt;
        }
    }
    $xr->close();
    return $shared;
}

function csf_xlsx_cell_value(string $outer, string $cellType, array $shared): string {
    $val = '';
    if ($cellType === 's') {
        if (preg_match('/<v>(\d+)<\/v>/', $outer, $mm)) {
            $idx = (int)$mm[1];
            $val = isset($shared[$idx]) ? (string)$shared[$idx] : '';
        }
    } elseif ($cellType === 'inlineStr') {
        if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $outer, $mm)) {
            foreach ($mm[1] as $part) $val .= html_entity_decode($part, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    } else {
        if (preg_match('/<v>(.*?)<\/v>/s', $outer, $mm)) $val = html_entity_decode($mm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    return trim((string)$val);
}

function csf_products_cache_file(): string { return csf_data_dir() . '/products.cache.json'; }

function csf_load_products(bool $force = false): array {
    $xlsx = csf_products_xlsx_path();
    $cacheFile = csf_products_cache_file();
    $mtime = is_file($xlsx) ? (int)filemtime($xlsx) : 0;
    $cache = is_file($cacheFile) ? csf_json_load($cacheFile, []) : [];
    $cacheProducts = is_array($cache['products'] ?? null) ? $cache['products'] : [];
    // Pro rychlost a stabilitu na hostingu používáme předgenerovanou cache, pokud existuje.
    // XLSX se znovu načte až po kliknutí na „Obnovit cache produktů“.
    if (!$force && ($cache['version'] ?? '') === '2026-06-29-4' && $cacheProducts) {
        return $cacheProducts;
    }

    $products = [];
    // Když hosting nemá ZipArchive/XMLReader, modul použije předgenerovanou cache přibalenou v ZIPu.
    if (!is_file($xlsx) || !class_exists('ZipArchive') || !class_exists('XMLReader')) return $cacheProducts;

    $zip = new ZipArchive();
    if ($zip->open($xlsx) !== true) return $cacheProducts;

    $shared = csf_xlsx_shared_strings($zip);
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false || $sheetXml === '') {
        for ($k = 0; $k < $zip->numFiles; $k++) {
            $stat = $zip->statIndex($k);
            if (!empty($stat['name']) && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $stat['name'])) {
                $sheetXml = $zip->getFromName($stat['name']);
                break;
            }
        }
    }
    if ($sheetXml === false || $sheetXml === '') { $zip->close(); return $cacheProducts; }

    $want = [
        csf_xlsx_col_to_index('B') => 'code',
        csf_xlsx_col_to_index('C') => 'name',
        csf_xlsx_col_to_index('E') => 'description',
        csf_xlsx_col_to_index('G') => 'price_vat',
        csf_xlsx_col_to_index('AJ') => 'ean',
        csf_xlsx_col_to_index('BO') => 'purchase_price',
    ];

    $xr = new XMLReader();
    if (!$xr->XML($sheetXml)) { $zip->close(); return $cacheProducts; }
    $row = [];

    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'row') {
            $row = [];
        }
        if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'c') {
            $cellRef = (string)$xr->getAttribute('r');
            $colLetters = csf_xlsx_cell_col_letters($cellRef);
            if ($colLetters === '') continue;
            $colIdx = csf_xlsx_col_to_index($colLetters);
            if (!isset($want[$colIdx])) continue;
            $cellType = (string)$xr->getAttribute('t');
            $outer = $xr->readOuterXML();
            $row[$want[$colIdx]] = csf_xlsx_cell_value($outer, $cellType, $shared);
        }
        if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'row') {
            $code = trim((string)($row['code'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            if ($code === '' && $name === '') continue;
            $head = csf_norm_text($code . ' ' . $name);
            if ($head === 'kod vyrobku nazev vyrobku' || $head === 'kod vyrobku nazev vyrobku') continue;
            $price = csf_num($row['price_vat'] ?? 0);
            $descRaw = (string)($row['description'] ?? '');
            $desc = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($descRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (csf_strlen($desc) > 1800) $desc = csf_substr($desc, 0, 1800) . '…';
            $ean = preg_replace('/[^0-9A-Za-z]/', '', trim((string)($row['ean'] ?? '')));
            $norm = csf_norm_text($code . ' ' . $ean . ' ' . $name . ' ' . $desc);
            $product = [
                'code' => $code,
                'name' => $name,
                'description' => $desc,
                'ean' => $ean,
                'price_vat' => $price,
                'purchase_price' => csf_num($row['purchase_price'] ?? 0),
                '_norm' => $norm,
            ];
            if (csf_product_is_excluded($product)) continue;
            $products[] = $product;
        }
    }
    $xr->close();
    $zip->close();

    csf_json_save($cacheFile, [
        'version' => '2026-06-29-4',
        'mtime' => $mtime,
        'generated_at' => date('Y-m-d H:i:s'),
        'products' => $products,
    ]);
    return $products;
}

function csf_search_products(string $q, int $limit = 40): array {
    $q = trim($q);
    if ($q === '') return [];
    $norm = csf_norm_text($q);
    if ($norm === '') return [];
    $tokens = preg_split('/\s+/', $norm, -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) return [];

    $out = [];
    foreach (csf_load_products(false) as $p) {
        if (csf_product_is_excluded($p)) continue;
        $hay = (string)($p['_norm'] ?? '');
        $ok = true;
        foreach ($tokens as $t) {
            if ($t !== '' && strpos($hay, $t) === false) { $ok = false; break; }
        }
        if (!$ok) continue;
        $codeNorm = csf_norm_text($p['code'] ?? '');
        $eanNorm = csf_norm_text($p['ean'] ?? '');
        $nameNorm = csf_norm_text($p['name'] ?? '');
        $score = 10;
        if ($eanNorm !== '' && $eanNorm === $norm) $score += 1200;
        if ($codeNorm === $norm) $score += 1000;
        if ($eanNorm !== '' && strpos($eanNorm, $norm) === 0) $score += 800;
        if ($codeNorm !== '' && strpos($codeNorm, $norm) === 0) $score += 600;
        if ($nameNorm !== '' && strpos($nameNorm, $norm) === 0) $score += 300;
        if ($eanNorm !== '' && strpos($eanNorm, $norm) !== false) $score += 250;
        if ($codeNorm !== '' && strpos($codeNorm, $norm) !== false) $score += 180;
        if ($nameNorm !== '' && strpos($nameNorm, $norm) !== false) $score += 100;
        $out[] = ['score' => $score, 'p' => $p];
    }

    usort($out, function($a, $b) {
        if ($a['score'] === $b['score']) return strcmp((string)$a['p']['code'], (string)$b['p']['code']);
        return $b['score'] <=> $a['score'];
    });

    $res = [];
    foreach (array_slice($out, 0, max(1, $limit)) as $x) {
        $p = $x['p'];
        unset($p['_norm']);
        $res[] = $p;
    }
    return $res;
}


function csf_product_purchase_price(string $code, string $name = ''): float {
    $codeNorm = csf_norm_text($code);
    $nameNorm = csf_norm_text($name);
    if ($codeNorm === '' && $nameNorm === '') return 0.0;

    foreach (csf_load_products(false) as $p) {
        $pCodeNorm = csf_norm_text((string)($p['code'] ?? ''));
        if ($codeNorm !== '' && $pCodeNorm === $codeNorm) {
            return csf_num($p['purchase_price'] ?? 0);
        }
    }
    if ($nameNorm !== '') {
        foreach (csf_load_products(false) as $p) {
            $pNameNorm = csf_norm_text((string)($p['name'] ?? ''));
            if ($pNameNorm !== '' && $pNameNorm === $nameNorm) {
                return csf_num($p['purchase_price'] ?? 0);
            }
        }
    }
    return 0.0;
}

function csf_invoice_profit_detail(array $inv): array {
    $invoiceNumber = (string)($inv['invoice_number'] ?? '');
    $series = substr($invoiceNumber, 0, 1);
    $profitMode = $series === '9' ? 'bez_dph' : 's_dph';
    $rows = [];
    $totalSale = 0.0;
    $totalCost = 0.0;
    $totalProfit = 0.0;

    foreach (($inv['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $code = (string)($it['code'] ?? '');
        $name = (string)($it['name'] ?? '');
        $qty = csf_num($it['qty'] ?? 1);
        if ($qty <= 0) $qty = 1;

        $purchaseUnit = csf_num($it['purchase_price'] ?? 0);
        if ($purchaseUnit <= 0) $purchaseUnit = csf_product_purchase_price($code, $name);

        $unitNet = csf_num($it['unit_net'] ?? 0);
        $unitGross = csf_num($it['unit_gross'] ?? 0);
        if ($unitGross <= 0) $unitGross = csf_num($it['unit_gross_base'] ?? 0);
        if ($unitNet <= 0 && $unitGross > 0) $unitNet = csf_unit_net_from_gross($unitGross);

        $totalNet = csf_num($it['total_net'] ?? 0);
        $totalGross = csf_num($it['total_gross'] ?? 0);
        if ($totalGross <= 0 && $unitGross > 0) $totalGross = csf_round($unitGross * $qty);
        if ($totalNet <= 0 && $unitNet > 0) $totalNet = csf_round($unitNet * $qty);

        $saleForProfit = $profitMode === 'bez_dph' ? $totalNet : $totalGross;
        $costTotal = csf_round($purchaseUnit * $qty);
        $profit = csf_round($saleForProfit - $costTotal);

        $totalSale += $saleForProfit;
        $totalCost += $costTotal;
        $totalProfit += $profit;

        $rows[] = [
            'code' => $code,
            'name' => $name,
            'qty' => $qty,
            'purchase_unit' => csf_round($purchaseUnit),
            'purchase_total' => csf_round($costTotal),
            'unit_net' => csf_round($unitNet),
            'unit_gross' => csf_round($unitGross),
            'total_net' => csf_round($totalNet),
            'total_gross' => csf_round($totalGross),
            'sale_for_profit' => csf_round($saleForProfit),
            'profit' => csf_round($profit),
        ];
    }

    return [
        'invoice_number' => $invoiceNumber,
        'profit_mode' => $profitMode,
        'profit_mode_label' => $profitMode === 'bez_dph' ? 'Řada 9: zisk počítán z prodejní ceny bez DPH' : 'Řada 7: zisk počítán z prodejní ceny s DPH',
        'total_sale_for_profit' => csf_round($totalSale),
        'total_purchase' => csf_round($totalCost),
        'total_profit' => csf_round($totalProfit),
        'rows' => $rows,
    ];
}

function csf_invoice_search_blob(array $inv): string {
    $parts = [
        (string)($inv['invoice_number'] ?? ''),
        (string)($inv['customer_name'] ?? ''),
        (string)($inv['customer_suffix'] ?? ''),
        (string)($inv['customer_phone'] ?? ''),
        (string)($inv['customer_address'] ?? ''),
        (string)($inv['customer_ico'] ?? ''),
        (string)($inv['customer_dic'] ?? ''),
        (string)($inv['payment_label'] ?? ''),
        csf_money((float)($inv['totals']['net'] ?? 0)),
        csf_money((float)($inv['totals']['gross'] ?? 0)),
        (string)($inv['totals']['net'] ?? ''),
        (string)($inv['totals']['gross'] ?? ''),
    ];
    foreach (($inv['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $parts[] = (string)($it['code'] ?? '');
        $parts[] = (string)($it['name'] ?? '');
        $parts[] = (string)($it['qty'] ?? '');
        $parts[] = (string)($it['unit_gross'] ?? '');
        $parts[] = (string)($it['unit_net'] ?? '');
    }
    return csf_norm_text(implode(' ', $parts));
}

function csf_invoice_matches_query(array $inv, string $query): bool {
    $query = trim($query);
    if ($query === '') return true;
    $blob = csf_invoice_search_blob($inv);
    $tokens = preg_split('/\s+/', csf_norm_text($query), -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) return true;
    foreach ($tokens as $t) {
        if ($t !== '' && strpos($blob, $t) === false) return false;
    }
    return true;
}


function csf_invoices_file(): string { return csf_data_dir() . '/invoices.json'; }
function csf_counters_file(): string { return csf_data_dir() . '/counters.json'; }

function csf_load_invoices(): array {
    $data = csf_json_load(csf_invoices_file(), []);
    return is_array($data) ? $data : [];
}

function csf_save_invoices(array $invoices): bool { return csf_json_save(csf_invoices_file(), $invoices); }

function csf_invoice_number_prefix(string $payment): string {
    return $payment === 'card' ? '9' : '7';
}

function csf_payment_label(string $payment): string {
    return $payment === 'card' ? 'Kartou' : 'Hotově';
}

function csf_next_invoice_number(string $payment, DateTime $date): string {
    $prefix = csf_invoice_number_prefix($payment);
    $ym = $date->format('ym');
    $key = $prefix . $ym;
    $lockFile = csf_data_dir() . '/counters.lock';
    $lock = @fopen($lockFile, 'c+');
    if ($lock) @flock($lock, LOCK_EX);

    $used = [];
    $invoices = csf_load_invoices();
    foreach ($invoices as $no => $inv) {
        $no = (string)$no;
        if (strpos($no, $key) === 0) {
            $seq = (int)substr($no, strlen($key));
            if ($seq > 0) $used[$seq] = true;
        }
    }

    // Doplní první volné číslo v řadě, aby nevznikaly mezery:
    // 001, 003 => další bude 002. Když mezera není, pokračuje max+1.
    $next = 1;
    while (isset($used[$next])) $next++;

    $counters = csf_json_load(csf_counters_file(), []);
    $counters[$key] = $next;
    csf_json_save(csf_counters_file(), $counters);

    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
    return $key . sprintf('%03d', $next);
}

function csf_customer_name(string $suffix): string {
    $suffix = trim($suffix);
    if ($suffix === '') return 'C-Servis';
    if (preg_match('/^c\s*-?\s*servis/i', $suffix)) return $suffix;
    return 'C-Servis ' . $suffix;
}

function csf_prepare_invoice(array $payload): array {
    $tz = new DateTimeZone('Europe/Prague');
    $issueRaw = trim((string)($payload['issue_date'] ?? ''));
    $issue = DateTime::createFromFormat('Y-m-d', $issueRaw, $tz);
    if (!($issue instanceof DateTime)) $issue = new DateTime('now', $tz);
    $issue->setTime(0,0,0);
    $due = clone $issue;
    $due->modify('+10 days');

    $payment = (string)($payload['payment'] ?? 'cash');
    $payment = $payment === 'card' ? 'card' : 'cash';
    $customerSuffix = (string)($payload['customer_suffix'] ?? '');
    $customer = csf_customer_name($customerSuffix);

    $items = [];
    foreach (($payload['items'] ?? []) as $raw) {
        if (!is_array($raw)) continue;
        $code = trim((string)($raw['code'] ?? ''));
        $name = trim((string)($raw['name'] ?? ''));
        if ($code === '' && $name === '') continue;
        $qty = csf_num($raw['qty'] ?? 1);
        if ($qty <= 0) $qty = 1;
        $unitGrossBase = csf_num($raw['unit_gross'] ?? 0);
        $discount = csf_num($raw['discount_percent'] ?? 0);
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
            'purchase_price' => csf_num($raw['purchase_price'] ?? csf_product_purchase_price($code, $name)),
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
    foreach ($items as $it) { $sumGross += $it['total_gross']; }
    $sumGross = csf_round($sumGross);
    // Souhrn DPH počítáme z celkové částky s DPH, aby seděl stejně jako vzor z Eshop-rychle.
    $sumNet = csf_round($sumGross / 1.21);
    $sumVat = csf_round($sumGross - $sumNet);

    $no = csf_next_invoice_number($payment, $issue);
    return [
        'invoice_number' => $no,
        'created_at' => date('Y-m-d H:i:s'),
        'issue_date' => $issue->format('Y-m-d'),
        'tax_date' => $issue->format('Y-m-d'),
        'due_date' => $due->format('Y-m-d'),
        'payment' => $payment,
        'payment_label' => csf_payment_label($payment),
        'variable_symbol' => $no,
        'transport' => 'Osobní odběr',
        'customer_suffix' => trim($customerSuffix),
        'customer_name' => $customer,
        'customer_phone' => trim((string)($payload['customer_phone'] ?? '')),
        'customer_address' => trim((string)($payload['customer_address'] ?? '')),
        'customer_ico' => trim((string)($payload['customer_ico'] ?? '')),
        'customer_dic' => trim((string)($payload['customer_dic'] ?? '')),
        'note' => trim((string)($payload['note'] ?? '')),
        'workflow_state' => 'new',
        'items' => $items,
        'totals' => [
            'net' => $sumNet,
            'vat' => $sumVat,
            'gross' => $sumGross,
        ],
    ];
}

function csf_save_invoice(array $invoice): array {
    $no = (string)$invoice['invoice_number'];
    $pdfFile = csf_invoice_dir() . '/' . $no . '.pdf';
    $invoice['pdf_file'] = $pdfFile;
    csf_generate_invoice_pdf($invoice, $pdfFile);
    $invoices = csf_load_invoices();
    $invoices[$no] = $invoice;
    krsort($invoices, SORT_STRING);
    if (!csf_save_invoices($invoices)) throw new RuntimeException('Nepodařilo se uložit evidenci faktur.');
    return $invoice;
}

function csf_move_invoice_series(string $invoiceNumber, string $targetPayment): array {
    $invoiceNumber = preg_replace('/[^0-9]/', '', $invoiceNumber);
    $targetPayment = $targetPayment === 'card' ? 'card' : 'cash';
    if ($invoiceNumber === '') throw new RuntimeException('Chybí číslo faktury.');

    $invoices = csf_load_invoices();
    if (empty($invoices[$invoiceNumber]) || !is_array($invoices[$invoiceNumber])) {
        throw new RuntimeException('Faktura nebyla nalezena.');
    }

    $invoice = $invoices[$invoiceNumber];
    $currentPayment = (string)($invoice['payment'] ?? 'cash');
    $currentState = (string)($invoice['workflow_state'] ?? 'final');

    $targetPrefix = csf_invoice_number_prefix($targetPayment);
    if ($currentPayment === $targetPayment && strpos($invoiceNumber, $targetPrefix) === 0) {
        $invoice['payment'] = $targetPayment;
        $invoice['payment_label'] = csf_payment_label($targetPayment);
        $invoice['workflow_state'] = 'final';
        $invoice['pdf_file'] = csf_invoice_dir() . '/' . $invoiceNumber . '.pdf';
        csf_generate_invoice_pdf($invoice, $invoice['pdf_file']);
        $invoices[$invoiceNumber] = $invoice;
        krsort($invoices, SORT_STRING);
        if (!csf_save_invoices($invoices)) throw new RuntimeException('Nepodařilo se uložit upravenou fakturu.');
        return $invoice;
    }

    $tz = new DateTimeZone('Europe/Prague');
    $issue = DateTime::createFromFormat('Y-m-d', (string)($invoice['issue_date'] ?? ''), $tz);
    if (!($issue instanceof DateTime)) $issue = new DateTime('now', $tz);

    $newNumber = csf_next_invoice_number($targetPayment, $issue);
    $oldPdf = csf_invoice_dir() . '/' . $invoiceNumber . '.pdf';
    if (is_file($oldPdf)) @unlink($oldPdf);

    unset($invoices[$invoiceNumber]);
    $invoice['invoice_number'] = $newNumber;
    $invoice['variable_symbol'] = $newNumber;
    $invoice['payment'] = $targetPayment;
    $invoice['payment_label'] = csf_payment_label($targetPayment);
    $invoice['workflow_state'] = 'final';
    $invoice['pdf_file'] = csf_invoice_dir() . '/' . $newNumber . '.pdf';

    csf_generate_invoice_pdf($invoice, $invoice['pdf_file']);
    $invoices[$newNumber] = $invoice;
    krsort($invoices, SORT_STRING);
    if (!csf_save_invoices($invoices)) throw new RuntimeException('Nepodařilo se uložit upravenou fakturu.');
    return $invoice;
}

function csf_date_cz(string $ymd): string {
    $dt = DateTime::createFromFormat('Y-m-d', $ymd, new DateTimeZone('Europe/Prague'));
    return $dt instanceof DateTime ? $dt->format('d.m.Y') : $ymd;
}

function csf_generate_invoice_pdf(array $invoice, string $target): void {
    $tcpdf = __DIR__ . '/../../tcpdf/tcpdf.php';
    if (!is_file($tcpdf)) throw new RuntimeException('Chybí knihovna TCPDF: ' . $tcpdf);
    $curlFallback = [
        'CURLOPT_CONNECTTIMEOUT' => 78, 'CURLOPT_MAXREDIRS' => 68, 'CURLOPT_PROTOCOLS' => 181,
        'CURLPROTO_HTTP' => 1, 'CURLPROTO_HTTPS' => 2, 'CURLPROTO_FTP' => 4, 'CURLPROTO_FTPS' => 8,
        'CURLOPT_SSL_VERIFYHOST' => 81, 'CURLOPT_SSL_VERIFYPEER' => 64, 'CURLOPT_TIMEOUT' => 13,
        'CURLOPT_USERAGENT' => 10018, 'CURLOPT_FAILONERROR' => 45, 'CURLOPT_RETURNTRANSFER' => 19913,
    ];
    foreach ($curlFallback as $constName => $constValue) {
        if (!defined($constName)) define($constName, $constValue);
    }
    require_once $tcpdf;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Cfloat - Cservis FAKTURACE');
    $pdf->SetAuthor('AleaSport CZ, s.r.o.');
    $pdf->SetTitle('Faktura ' . (string)$invoice['invoice_number']);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(14, 10, 14);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 9);

    $rows = '';
    foreach ($invoice['items'] as $it) {
        $disc = (float)$it['discount_percent'];
        $name = csf_h((string)$it['name']);
        if ($disc > 0) {
            $name .= '<br><span class="tiny-note">Sleva ' . csf_h(number_format($disc, 2, ',', ' ')) . ' % z původní ceny ' . csf_money_pdf((float)$it['unit_gross_base']) . ' s DPH</span>';
        }
        $rows .= '<tr>
            <td width="9%" valign="middle">' . csf_h($it['code']) . '</td>
            <td width="31%" valign="middle">' . $name . '</td>
            <td width="8%" align="right" valign="middle">' . csf_qty_pdf((float)$it['qty']) . '</td>
            <td width="11%" align="right" valign="middle">' . csf_money_pdf((float)$it['unit_net']) . '</td>
            <td width="5%" align="center" valign="middle">21 %</td>
            <td width="11%" align="right" valign="middle">' . csf_money_pdf((float)$it['unit_gross']) . '</td>
            <td width="12%" align="right" valign="middle">' . csf_money_pdf((float)$it['total_net']) . '</td>
            <td width="13%" align="right" valign="middle">' . csf_money_pdf((float)$it['total_gross']) . '</td>
        </tr>';
    }

    $zeroRows = [
        [(string)$invoice['payment_label'], (string)$invoice['payment_label']],
    ];
    foreach ($zeroRows as $zr) {
        $rows .= '<tr>
            <td width="9%" valign="middle">' . csf_h($zr[0]) . '</td>
            <td width="31%" valign="middle">' . csf_h($zr[1]) . '</td>
            <td width="8%" align="right" valign="middle">1&nbsp;ks</td>
            <td width="11%" align="right" valign="middle">0,00&nbsp;Kč</td>
            <td width="5%" align="center" valign="middle">21 %</td>
            <td width="11%" align="right" valign="middle">0,00&nbsp;Kč</td>
            <td width="12%" align="right" valign="middle">0,00&nbsp;Kč</td>
            <td width="13%" align="right" valign="middle">0,00&nbsp;Kč</td>
        </tr>';
    }

    $customerLine = csf_h((string)$invoice['customer_name']);
    if (!empty($invoice['customer_phone'])) $customerLine .= '<br>Tel.: ' . csf_h((string)$invoice['customer_phone']);
    if (!empty($invoice['customer_address'])) $customerLine .= '<br>' . nl2br(csf_h((string)$invoice['customer_address']));
    if (!empty($invoice['customer_ico'])) $customerLine .= '<br>IČO: ' . csf_h((string)$invoice['customer_ico']);
    if (!empty($invoice['customer_dic'])) $customerLine .= '<br>DIČ: ' . csf_h((string)$invoice['customer_dic']);

    $supplierLine = '<b>AleaSport CZ, s.r.o.</b><br>Modřínová 453<br>674 01 Třebíč<br>IČO: 09730761<br>DIČ: CZ09730761';

    $html = '
<style>
    .company { font-size: 17px; font-weight: bold; color: #111111; }
    .muted { font-size: 7.6px; color: #667085; }
    .doc-label { font-size: 8.5px; color: #0b9150; font-weight: bold; }
    .doc-no { font-size: 23px; font-weight: bold; color: #111111; }
    .vs-line { font-size: 8px; color: #111111; font-weight: bold; }
    .section-title { font-size: 10px; font-weight: bold; color: #0b9150; }
    .card { border: 1px solid #d9e5dd; background-color: #fbfdfb; }
    .meta-card { border: 1px solid #d9e5dd; background-color: #eff8f3; }
    .meta-label { font-size: 6.7px; color: #5f6b6a; text-transform: uppercase; }
    .meta-value { font-size: 9.5px; font-weight: bold; color: #111111; }
    .body-text { font-size: 8.8px; color: #111111; line-height: 1.42; }
    .body-center { font-size: 9px; color: #111111; line-height: 1.46; text-align: center; }
    .tiny-note { font-size: 6.9px; color: #667085; }
    .section-gap { height: 6mm; }
    .items { table-layout: fixed; }
    .items th { background-color: #eaf5ee; border-top: 1px solid #cfe3d4; border-bottom: 1px solid #cfe3d4; padding: 8px 5px; font-size: 7.0px; white-space: nowrap; font-weight: bold; color: #153a27; vertical-align: middle; line-height: 1.18; }
    .items td { border-bottom: 1px solid #e7eeea; padding: 9px 5px; font-size: 8.15px; color: #111111; vertical-align: middle; line-height: 1.24; }
    .totals-box { border: 1px solid #d9e5dd; background-color: #fbfdfb; }
    .totals-row td { padding: 5px 7px; font-size: 9px; color: #111111; }
    .due-box { border: 1px solid #0b9150; background-color: #eff8f3; }
    .kv td { padding: 3.8px 0; font-size: 8.4px; color: #111111; vertical-align: top; }
    .kv .k { width: 31%; font-weight: bold; color: #111111; }
    .kv .v { width: 69%; color: #111111; }
    .legal-box { border-top: 1px solid #d9e5dd; padding-top: 6px; }
    .legal { font-size: 7.1px; color: #667085; }
</style>

<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td width="58%">
            <span class="company">AleaSport CZ, s.r.o.</span><br>
            <span class="muted">Modřínová 453, 674 01 Třebíč &nbsp; | &nbsp; IČO 09730761 &nbsp; | &nbsp; DIČ CZ09730761</span>
        </td>
        <td width="42%" align="right">
            <span class="doc-label">FAKTURA – DAŇOVÝ DOKLAD</span><br>
            <span class="doc-no">' . csf_h((string)$invoice['invoice_number']) . '</span><br>
            <span class="vs-line">Variabilní symbol: ' . csf_h((string)$invoice['variable_symbol']) . '</span><br>
            <span class="muted">Strana 1 z 1</span>
        </td>
    </tr>
</table>

<br>
<table width="100%" cellpadding="5" cellspacing="5">
    <tr>
        <td width="33.33%" class="meta-card">
            <span class="meta-label">Datum vystavení</span><br>
            <span class="meta-value">' . csf_h(csf_date_cz((string)$invoice['issue_date'])) . '</span>
        </td>
        <td width="33.33%" class="meta-card">
            <span class="meta-label">Datum splatnosti</span><br>
            <span class="meta-value">' . csf_h(csf_date_cz((string)$invoice['due_date'])) . '</span>
        </td>
        <td width="33.34%" class="meta-card">
            <span class="meta-label">Způsob platby</span><br>
            <span class="meta-value">' . csf_h((string)$invoice['payment_label']) . '</span>
        </td>
    </tr>
</table>

<table width="100%" cellpadding="6" cellspacing="5">
    <tr>
        <td width="50%" class="card" valign="top" height="34mm">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr><td align="left"><span class="section-title">Dodavatel</span></td></tr>
                <tr><td style="height:3mm;">&nbsp;</td></tr>
                <tr><td align="center" class="body-center">' . $supplierLine . '</td></tr>
            </table>
        </td>
        <td width="50%" class="card" valign="top" height="34mm">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr><td align="left"><span class="section-title">Odběratel</span></td></tr>
                <tr><td style="height:3mm;">&nbsp;</td></tr>
                <tr><td align="center" class="body-center"><b>' . $customerLine . '</b></td></tr>
            </table>
        </td>
    </tr>
</table>

<br>
<table width="100%" cellpadding="0" cellspacing="0">
    <tr><td><span class="section-title">Položky faktury</span></td></tr>
</table>
<table width="100%"><tr><td style="height:10mm;">&nbsp;</td></tr></table>
<table class="items" width="100%" cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <th width="9%">Kód<br>produktu</th>
            <th width="31%">Název položky</th>
            <th width="8%" align="right">Množství</th>
            <th width="11%" align="right">Cena/MJ<br>bez DPH</th>
            <th width="5%" align="center">DPH</th>
            <th width="11%" align="right">Cena/MJ<br>s DPH</th>
            <th width="12%" align="right">Celkem bez<br>DPH</th>
            <th width="13%" align="right">Celkem s DPH</th>
        </tr>
    </thead>
    <tbody>' . $rows . '</tbody>
</table>

<table width="100%"><tr><td style="height:11mm;">&nbsp;</td></tr></table>
<table width="100%" cellpadding="0" cellspacing="5">
    <tr>
        <td width="51%" class="card" valign="top">
            <table width="100%" cellpadding="0" cellspacing="0" class="kv">
                <tr><td colspan="2"><span class="section-title">Platební a dodací údaje</span></td></tr>
                <tr><td colspan="2" style="height:5mm;">&nbsp;</td></tr>
                <tr><td class="k">Provozovna:</td><td class="v">C-Store.cz, Hrotovická 1202/27<br>674 01 Třebíč</td></tr>
                <tr><td class="k">Bankovní účet:</td><td class="v">2201906692/2010</td></tr>
                <tr><td class="k">Banka:</td><td class="v">Fio banka</td></tr>
                <tr><td class="k">Datum UZP:</td><td class="v">' . csf_h(csf_date_cz((string)$invoice['tax_date'])) . '</td></tr>
                <tr><td class="k">Způsob dopravy:</td><td class="v">' . csf_h((string)$invoice['transport']) . '</td></tr>
                <tr><td class="k">Způsob platby:</td><td class="v">' . csf_h((string)$invoice['payment_label']) . '</td></tr>
                <tr><td class="k">E-mail:</td><td class="v">obchod@c-store.cz</td></tr>
                <tr><td class="k">Web:</td><td class="v">www.c-store.cz</td></tr>
            </table>
        </td>
        <td width="49%" valign="top">
            <table width="100%" cellpadding="0" cellspacing="0" class="totals-box">
                <tr class="totals-row"><td width="52%">Celkem bez DPH</td><td width="48%" align="right"><b>' . csf_money_pdf((float)$invoice['totals']['net']) . '</b></td></tr>
                <tr class="totals-row"><td width="52%">DPH 21 %</td><td width="48%" align="right"><b>' . csf_money_pdf((float)$invoice['totals']['vat']) . '</b></td></tr>
                <tr class="totals-row"><td width="52%">Celkem s DPH</td><td width="48%" align="right"><b>' . csf_money_pdf((float)$invoice['totals']['gross']) . '</b></td></tr>
            </table>
            <br>
            <table width="100%" cellpadding="10" cellspacing="0" class="due-box">
                <tr>
                    <td width="32%"><span class="section-title" style="color:#111111;">Zbývá k úhradě</span></td>
                    <td width="68%" align="right"><span style="font-size:14px; font-weight:bold; color:#0b9150; white-space:nowrap;">' . csf_money_pdf((float)$invoice['totals']['gross']) . '</span></td>
                </tr>
            </table>
        </td>
    </tr>
</table>';

    if (!empty($invoice['note'])) {
        $html .= '<br><table width="100%" cellpadding="6" cellspacing="0" class="card"><tr><td><span class="section-title">Poznámka</span><br><br><span class="body-text">' . nl2br(csf_h((string)$invoice['note'])) . '</span></td></tr></table>';
    }

    $html .= '<br><br><table width="100%" cellpadding="0" cellspacing="0" class="legal-box"><tr><td class="legal">Plátce DPH | Spis. zn. C 120544 vedená u Krajského soudu v Brně<br>Adresa pro výměnu a vrácení zboží: C-Store.cz, Hrotovická 1202/27, Třebíč, 674 01</td></tr></table>';

    $pdf->writeHTML($html, true, false, true, false, '');
    @mkdir(dirname($target), 0775, true);
    $pdf->Output($target, 'F');
}


function csf_invoice_items_summary(array $inv): string {
    $items = [];
    foreach (($inv['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $code = trim((string)($it['code'] ?? ''));
        $name = trim((string)($it['name'] ?? ''));
        if ($code === '' && $name === '') continue;
        $items[] = trim($code . ' ' . $name);
        if (count($items) >= 3) break;
    }
    return implode(', ', $items);
}

function csf_public_invoice(array $inv): array {
    $invoiceNumber = (string)($inv['invoice_number'] ?? '');
    $payment = (string)($inv['payment'] ?? '');
    if ($payment === '') $payment = (strpos($invoiceNumber, '9') === 0) ? 'card' : 'cash';
    return [
        'invoice_number' => $invoiceNumber,
        'created_at' => (string)($inv['created_at'] ?? ''),
        'issue_date' => (string)($inv['issue_date'] ?? ''),
        'customer_name' => (string)($inv['customer_name'] ?? ''),
        'customer_suffix' => (string)($inv['customer_suffix'] ?? ''),
        'customer_phone' => (string)($inv['customer_phone'] ?? ''),
        'items_summary' => csf_invoice_items_summary($inv),
        'payment' => $payment,
        'payment_label' => (string)($inv['payment_label'] ?? csf_payment_label($payment)),
        'total_net' => (float)($inv['totals']['net'] ?? 0),
        'total_vat' => (float)($inv['totals']['vat'] ?? 0),
        'total_gross' => (float)($inv['totals']['gross'] ?? 0),
        'series' => substr($invoiceNumber, 0, 1),
        'workflow_state' => (string)($inv['workflow_state'] ?? 'final'),
    ];
}
