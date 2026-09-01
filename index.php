<?php
// Přístup může být i přes .htaccess (Basic Auth na úrovni serveru)

require_once __DIR__ . '/_auth_guard.php';

// PDO (DB d388160_cfloat – orders / order_items)
// PDO (DB d388160_cfloat – orders / order_items)
$cfgCandidates = [__DIR__ . '/config.php', __DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$cfgLoaded = false;
foreach ($cfgCandidates as $p) { if (is_file($p)) { require $p; $cfgLoaded = true; break; } }
if (!$cfgLoaded || !isset($pdo)) { die('Chybí config.php nebo $pdo.'); }

// Automatické EAN a nákupní ceny z aktuálních dodavatelských feedů.
require_once __DIR__ . '/fill_ean_auto.php';
require_once __DIR__ . '/fill_purchase_price_auto.php';

// SMS (volitelné) – fronta pro Android odesílání
$smsSettings = [];
$__sms_lib = __DIR__ . '/sms/sms_lib.php';
if (is_file($__sms_lib)) {
    require_once $__sms_lib;
    $smsSettings = sms_load_settings();
}

// (volitelně) Ecomail klíč mimo config.php (ECOMAIL modul je nahrazen modulem MAIL)
// if (is_file(__DIR__ . '/ecomail_config.php')) { require __DIR__ . '/ecomail_config.php'; }


/**
 * SHEET 1 – objednávky (EAN + jméno + zaplaceno + dobírka)
 */
const ORDERS_SHEET_CSV_URL =
    'https://docs.google.com/spreadsheets/d/1P-ODRGtKOI5-8wQZEnY_AlPkfAgbknVkFGMc73aBHnQ/export?format=csv&gid=1311385256';

// indexy sloupců – A=0, B=1, ..., I=8, T=19, AA=26, AB=27
const ORDER_COL_INDEX     = 1;  // B – číslo objednávky / EAN
const ORDER_COD_COL_INDEX = 8;  // I – částka dobírky
const NAME_COL_INDEX      = 19; // T – jméno zákazníka
const PAID_COL_INDEX      = 27; // AB – A/N (zaplaceno / nezaplaceno)

/**
 * SHEET 2 – statistika položek (Y, Z, datum C, K, AA, AC, AD)
 */
const STATS_SHEET_CSV_URL = 'https://cfloat.cz/stats_items_csv.php';


// indexy – A=0, B=1, C=2, ..., K=10, Y=24, Z=25, AA=26, AC=28, AD=29
const STATS_DATE_COL_INDEX  = 2;  // C – datum
const STATS_KEY_COL_INDEX   = 10; // K – klíč (jen info v detailu, teď se nepoužívá)
const STATS_CODE_COL_INDEX  = 24; // Y – kód položky
const STATS_NAME_COL_INDEX  = 25; // Z – název položky
const STATS_EXTRA_COL_INDEX = 26; // AA – detail, zobrazí se po rozkliknutí
const STATS_PRICE_COL_INDEX = 28; // AC – cena (Kč) – sčítáme po kódu
const STATS_AD_COL_INDEX    = 29; // AD – globální součet všech položek (počítáme celkem)

/**
 * SHEET 4 – položky objednávek (detail pro zákazníky)
 */
const ORDER_ITEMS_SHEET_CSV_URL     = STATS_SHEET_CSV_URL;
const ORDER_ITEMS_ORDER_COL_INDEX   = 10; // K – číslo objednávky
const ORDER_ITEMS_CODE_COL_INDEX    = 24; // Y – kód položky (product_number)
const ORDER_ITEMS_NAME_COL_INDEX    = 25; // Z – název položky (product_name)
const ORDER_ITEMS_VARIANT_COL_INDEX = 26; // AA – detail (variant_description)
const ORDER_ITEMS_QTY_COL_INDEX     = 27; // AB – množství (pokud není, bereme 1 ks)

/**
 * SHEET 3 – vrácené zboží
 */
const RETURNS_SHEET_CSV_URL =
    'https://docs.google.com/spreadsheets/d/1SlpPjKpZpKq6nNbYzSlrNWEo31XU9dTXuEqQQhY4ONc/export?format=csv&gid=1968157874';

// indexy – A=0, B=1, C=2, D=3, E=4, ..., I=8, J=9, K=10, L=11, M=12, ..., Z=25
const RETURNS_DATE_COL_INDEX = 1;  // B – datum
const RETURNS_D_COL_INDEX    = 3;  // D – kód / doplňující info (za datem)
const RETURNS_I_COL_INDEX    = 8;  // I – popis (detail)
const RETURNS_J_COL_INDEX    = 9;  // J – hlavní text, bez něj řádek zahodíme
const RETURNS_K_COL_INDEX    = 10; // K – klíč pro deduplikaci
const RETURNS_L_COL_INDEX    = 11; // L – počet vrácených kusů
const RETURNS_M_COL_INDEX    = 12; // M – cena
const RETURNS_Z_COL_INDEX    = 25; // Z – název (nepoužíváme)
const RETURNS_E_COL_INDEX    = 4;  // E – další sloupec v tabulce

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Zjistí existenci sloupce v DB (MySQL/MariaDB) – bezpečně, s cache.
 */
function db_has_column(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    try {
        $st = $pdo->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c
            LIMIT 1
        ");
        $st->execute([':t' => $table, ':c' => $column]);
        $ok = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        $ok = false;
    }

    $cache[$key] = $ok;
    return $ok;
}

/**
 * Formát peněz pro CZK/EUR (používáme všude stejný výstup).
 */
function fmt_money($amount, $currencyCode = 'CZK'): string {
    $cc = strtoupper(trim((string)$currencyCode));
    if ($cc === '') $cc = 'CZK';

    $suffix = ' Kč';
    if ($cc === 'EUR') $suffix = ' €';

    return number_format((float)$amount, 2, ',', ' ') . $suffix;
}


function returns_status_labels(): array {
    return [
        'NEW'       => 'Nová',
        'RECEIVED'  => 'Příchozí vratka',
        'APPROVED'  => 'Zpracována',
        'TO_REFUND' => 'Předat k proplacení',
        'REFUNDED'  => 'Proplacena',
        'REJECTED'  => 'Zamítnuta',
        'CLOSED'    => 'Uzavřena',
    ];
}

function returns_db_has_index(PDO $pdo, string $table, string $index): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i LIMIT 1");
        $st->execute([':t'=>$table, ':i'=>$index]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function returns_meta_file(): string {
    $dir = __DIR__ . '/vraceni-cstore/data';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/returns_meta.json';
}
function returns_meta_load(): array {
    $file = returns_meta_file();
    if (!is_file($file)) return [];
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
function returns_meta_save(array $data): void {
    $file = returns_meta_file();
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
}
function returns_meta_get(int $id): array {
    $all = returns_meta_load();
    return (isset($all[(string)$id]) && is_array($all[(string)$id])) ? $all[(string)$id] : [];
}
function returns_meta_update(int $id, array $patch): array {
    $all = returns_meta_load();
    $key = (string)$id;
    $cur = (isset($all[$key]) && is_array($all[$key])) ? $all[$key] : [];
    foreach ($patch as $k=>$v) $cur[$k] = $v;
    $cur['updated_at'] = date('Y-m-d H:i:s');
    $all[$key] = $cur;
    returns_meta_save($all);
    return $cur;
}

function returns_ensure_schema(PDO $pdo): void {
    // DB uživatel na hostingu nemá právo CREATE/ALTER. Proto tady nic nevytváříme ani neupravujeme.
    // Používají se původní tabulky returns_requests + returns_items a nové údaje se ukládají do JSON meta souboru.
    foreach (['returns_requests','returns_items'] as $table) {
        try {
            $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
            $st->execute([':t'=>$table]);
            if (!$st->fetchColumn()) throw new RuntimeException('Chybí tabulka ' . $table . '. Spusť ručně SQL soubor www/sql/returns_tables.sql přes phpMyAdmin.');
        } catch (RuntimeException $e) { throw $e;
        } catch (Throwable $ignore) {}
    }
}

function returns_next_credit_note_number(PDO $pdo): string {
    $max = 262500899;
    foreach (returns_meta_load() as $m) {
        if (!is_array($m)) continue;
        $n = trim((string)($m['credit_note_number'] ?? ''));
        if ($n !== '' && ctype_digit($n)) $max = max($max, (int)$n);
    }
    return (string)($max + 1);
}
function returns_assign_credit_note(PDO $pdo, int $id): string {
    $meta = returns_meta_get($id);
    $cur = trim((string)($meta['credit_note_number'] ?? ''));
    if ($cur !== '') return $cur;
    $num = returns_next_credit_note_number($pdo);
    returns_meta_update($id, ['credit_note_number'=>$num, 'credit_note_created_at'=>date('Y-m-d H:i:s')]);
    return $num;
}


function returns_recalc_total(PDO $pdo, int $requestId): float {
    $total = 0.0;
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(line_total_with_vat),0) FROM returns_items WHERE request_id = :id");
        $st->execute([':id'=>$requestId]);
        $total = (float)$st->fetchColumn();
    } catch (Throwable $ignore) {}
    try {
        $stU = $pdo->prepare("UPDATE returns_requests SET total_return_with_vat = :t, updated_at = NOW() WHERE id = :id");
        $stU->execute([':t'=>round($total, 2), ':id'=>$requestId]);
    } catch (Throwable $ignore) {}
    return round($total, 2);
}

function returns_item_clean_num($v): float {
    $s = trim((string)$v);
    $s = str_replace(["\xc2\xa0", ' '], '', $s);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

function returns_search_products_for_admin(PDO $pdo, string $q, int $limit = 25): array {
    $q = trim($q);
    if (mb_strlen($q, 'UTF-8') < 2) return [];
    $out = [];
    $seen = [];

    $feedIndex = __DIR__ . '/api/feed_index.php';
    if (is_file($feedIndex)) {
        try {
            require_once $feedIndex;
            if (function_exists('vb_search_products')) {
                foreach (vb_search_products($q, $limit) as $p) {
                    $code = (string)($p['code'] ?? $p['productno'] ?? $p['id'] ?? '');
                    $ean  = (string)($p['ean'] ?? '');
                    $name = (string)($p['name'] ?? '');
                    $var  = (string)($p['variant'] ?? '');
                    $price = (float)($p['price'] ?? 0);
                    $key = ($ean !== '' ? $ean : ($code.'|'.$name.'|'.$var));
                    if ($key === '' || isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $out[] = [
                        'product_number'=>$code,
                        'product_name'=>$name,
                        'variant_description'=>$var,
                        'ean'=>$ean,
                        'price_per_unit_with_vat'=>$price,
                    ];
                    if (count($out) >= $limit) return $out;
                }
            }
        } catch (Throwable $ignore) {}
    }

    try {
        $like = '%' . $q . '%';
        $st = $pdo->prepare("\n            SELECT\n                product_number,\n                product_name,\n                variant_description,\n                EAN AS ean,\n                MAX(CASE WHEN `count` > 0 THEN price_total_with_vat / `count` ELSE price_total_with_vat END) AS price_per_unit_with_vat\n            FROM order_items\n            WHERE product_number LIKE :q\n               OR product_name LIKE :q\n               OR variant_description LIKE :q\n               OR EAN LIKE :q\n            GROUP BY product_number, product_name, variant_description, EAN\n            ORDER BY product_name ASC, variant_description ASC\n            LIMIT " . (int)$limit);
        $st->execute([':q1'=>$like, ':q2'=>$like, ':q3'=>$like, ':q4'=>$like]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $code = (string)($r['product_number'] ?? '');
            $ean  = (string)($r['ean'] ?? '');
            $key = ($ean !== '' ? $ean : ($code.'|'.($r['product_name'] ?? '').'|'.($r['variant_description'] ?? '')));
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = [
                'product_number'=>$code,
                'product_name'=>(string)($r['product_name'] ?? ''),
                'variant_description'=>(string)($r['variant_description'] ?? ''),
                'ean'=>$ean,
                'price_per_unit_with_vat'=>(float)($r['price_per_unit_with_vat'] ?? 0),
            ];
            if (count($out) >= $limit) break;
        }
    } catch (Throwable $ignore) {}

    return $out;
}


function service_vat_marks_path(): string {
    return __DIR__ . '/service_vat_marks.json';
}

function service_vat_marks_load(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $file = service_vat_marks_path();
    if (!is_file($file)) {
        $cache = [];
        return $cache;
    }

    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        $cache = [];
        return $cache;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $cache = [];
        return $cache;
    }

    $out = [];
    foreach ($json as $itemId => $state) {
        $iid = (int)$itemId;
        if ($iid > 0 && !empty($state)) {
            $out[$iid] = 1;
        }
    }

    $cache = $out;
    return $cache;
}

function service_vat_marks_save(array $marks): bool {
    $clean = [];
    foreach ($marks as $itemId => $state) {
        $iid = (int)$itemId;
        if ($iid > 0 && !empty($state)) {
            $clean[$iid] = 1;
        }
    }

    ksort($clean, SORT_NUMERIC);
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    $ok = @file_put_contents(service_vat_marks_path(), $json, LOCK_EX);
    return $ok !== false;
}

function service_vat_is_marked(int $itemId): bool {
    if ($itemId <= 0) return false;
    $marks = service_vat_marks_load();
    return !empty($marks[$itemId]);
}

function service_vat_set_mark(int $itemId, bool $state): bool {
    if ($itemId <= 0) return false;
    $marks = service_vat_marks_load();
    if ($state) {
        $marks[$itemId] = 1;
    } else {
        unset($marks[$itemId]);
    }
    return service_vat_marks_save($marks);
}


function service_manual_rows_path(): string {
    return __DIR__ . '/service_manual_rows.json';
}

function service_manual_parse_number($raw): ?float {
    $s = trim((string)$raw);
    if ($s === '') return null;
    $s = str_replace(["\xc2\xa0", ' '], '', $s);
    $s = str_replace(',', '.', $s);
    $s = preg_replace('/[^0-9.\-]/', '', $s);
    if ($s === '' || !is_numeric($s)) return null;
    return (float)$s;
}

function service_manual_rows_load(): array {
    $file = service_manual_rows_path();
    if (!is_file($file)) return [];

    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return [];

    $json = json_decode($raw, true);
    if (!is_array($json)) return [];

    $rows = isset($json['rows']) && is_array($json['rows']) ? $json['rows'] : $json;
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $id = isset($r['id']) ? (int)$r['id'] : 0;
        if ($id <= 0) continue;

        $date = trim((string)($r['date'] ?? ''));
        if ($date === '') {
            $date = trim((string)($r['created_at'] ?? ''));
        }
        if ($date === '') {
            $date = date('Y-m-d H:i:s');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date .= ' 12:00:00';
        }

        $qty = service_manual_parse_number($r['qty'] ?? 1);
        if ($qty === null || $qty <= 0) $qty = 1.0;

        $lineTotal = service_manual_parse_number($r['line_total'] ?? 0);
        if ($lineTotal === null) $lineTotal = 0.0;

        $purchase = service_manual_parse_number($r['purchase'] ?? '');
        if ($purchase !== null && $purchase < 0) $purchase = 0.0;

        $out[] = [
            'id' => $id,
            'date' => $date,
            'name' => trim((string)($r['name'] ?? '')),
            'order_number' => trim((string)($r['order_number'] ?? '')),
            'code' => trim((string)($r['code'] ?? '')),
            'purchase' => $purchase,
            'product' => trim((string)($r['product'] ?? '')),
            'variant' => trim((string)($r['variant'] ?? '')),
            'qty' => $qty,
            'line_total' => $lineTotal,
            'vat' => !empty($r['vat']) ? 1 : 0,
            'created_at' => trim((string)($r['created_at'] ?? $date)),
        ];
    }
    return $out;
}

function service_manual_rows_save(array $rows): bool {
    $clean = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $id = isset($r['id']) ? (int)$r['id'] : 0;
        if ($id <= 0) continue;
        $clean[] = $r;
    }

    $payload = json_encode(['rows' => array_values($clean)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($payload === false) return false;

    $file = service_manual_rows_path();
    $tmp = $file . '.tmp';
    $ok = @file_put_contents($tmp, $payload, LOCK_EX);
    if ($ok === false) return false;
    return @rename($tmp, $file);
}

function service_is_work_item(string $productName, string $variantDescription = ''): bool {
    $hay = mb_strtolower($productName . ' ' . $variantDescription, 'UTF-8');
    $needles = ['práce mechanika','prace mechanika','malý servis','maly servis','velký servis','velky servis','základní kontrola','zakladni kontrola'];
    foreach ($needles as $nd) {
        if ($nd !== '' && mb_strpos($hay, $nd) !== false) return true;
    }
    return false;
}


/**
 * Minimal XLSX loader – načte mapu [kód => nákupní cena bez DPH] z XLSX.
 * Defaultně bere kód ze sloupce A a cenu ze sloupce BO.
 * Nevyžaduje žádnou knihovnu (PhpSpreadsheet).
 */
function xlsx_col_to_index(string $letters): int {
    $letters = strtoupper(preg_replace('/[^A-Z]/', '', $letters));
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n;
}

function xlsx_cell_col_letters(string $cellRef): string {
    if (preg_match('/^[A-Z]+/i', $cellRef, $m)) {
        return strtoupper($m[0]);
    }
    return '';
}

function xlsx_norm_price($v): ?float {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;

    // NBSP + mezery
    $s = str_replace(["\xC2\xA0", ' '], '', $s);
    $s = str_replace(',', '.', $s);
    $s = preg_replace('/[^0-9\.\-]/', '', $s);

    if ($s === '' || $s === '-' || $s === '.') return null;
    return (float)$s;
}

function xlsx_load_code_price_map(string $xlsxPath, string $codeCol = 'A', string $priceCol = 'BO'): array {
    $map = [];
    if (!is_file($xlsxPath)) return $map;
    if (!class_exists('ZipArchive')) return $map;

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) return $map;

    // sharedStrings (kvůli textovým buňkám)
    $shared = [];
    $sstXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sstXml !== false && $sstXml !== '') {
        $xr = new XMLReader();
        $xr->XML($sstXml);
        $i = -1;
        while ($xr->read()) {
            if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'si') {
                $i++;
                $outer = $xr->readOuterXML();
                $txt = '';
                if ($outer !== '') {
                    if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $outer, $mm)) {
                        foreach ($mm[1] as $part) {
                            $txt .= html_entity_decode($part, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    }
                }
                $shared[$i] = $txt;
            }
        }
        $xr->close();
    }

    // první worksheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false || $sheetXml === '') {
        // fallback: první sheet*.xml
        for ($k = 0; $k < $zip->numFiles; $k++) {
            $stat = $zip->statIndex($k);
            if (!empty($stat['name']) && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $stat['name'])) {
                $sheetXml = $zip->getFromName($stat['name']);
                break;
            }
        }
    }

    if ($sheetXml === false || $sheetXml === '') {
        $zip->close();
        return $map;
    }

    $codeIdx  = xlsx_col_to_index($codeCol);
    $priceIdx = xlsx_col_to_index($priceCol);

    $xr = new XMLReader();
    $xr->XML($sheetXml);

    $rowCode  = null;
    $rowPrice = null;

    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'row') {
            $rowCode = null;
            $rowPrice = null;
        }

        if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'c') {
            $cellRef = (string)$xr->getAttribute('r');
            $colLetters = xlsx_cell_col_letters($cellRef);
            if ($colLetters === '') continue;
            $colIdx = xlsx_col_to_index($colLetters);

            if ($colIdx !== $codeIdx && $colIdx !== $priceIdx) {
                // necháme projít – jen neparsujeme hodnotu (rychlejší než rozebírat vše)
                continue;
            }

            $cellType = (string)$xr->getAttribute('t');
            $outer = $xr->readOuterXML();
            $val = '';

            if ($outer !== '') {
                if ($cellType === 's') {
                    if (preg_match('/<v>(\d+)<\/v>/', $outer, $mm)) {
                        $si = (int)$mm[1];
                        $val = $shared[$si] ?? '';
                    }
                } elseif ($cellType === 'inlineStr') {
                    if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $outer, $mm)) {
                        $tmp = '';
                        foreach ($mm[1] as $part) {
                            $tmp .= html_entity_decode($part, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                        $val = $tmp;
                    }
                } else {
                    if (preg_match('/<v>(.*?)<\/v>/', $outer, $mm)) {
                        $val = $mm[1];
                    }
                }
            }

            if ($colIdx === $codeIdx) {
                $rowCode = trim((string)$val);
            } elseif ($colIdx === $priceIdx) {
                $rowPrice = trim((string)$val);
            }
        }

        if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'row') {
            $code = trim((string)$rowCode);
            if ($code === '' || mb_strtolower($code, 'UTF-8') === 'kód' || mb_strtolower($code, 'UTF-8') === 'kod') {
                continue;
            }
            $price = xlsx_norm_price($rowPrice);
            if ($price !== null && $price > 0) {
                $map[$code] = (float)$price;
            }
        }
    }

    $xr->close();
    $zip->close();
    return $map;
}


function xlsx_norm_ean($v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;

    $digits = preg_replace('/\D+/', '', $s);
    if (!is_string($digits) || $digits === '') return null;

    $len = strlen($digits);
    if ($len < 8 || $len > 14) return null;

    return $digits;
}

function xlsx_load_key_value_map(
    string $xlsxPath,
    string $keyCol,
    string $valueCol,
    ?callable $keyNormalizer = null,
    ?callable $valueNormalizer = null
): array {
    $map = [];
    if (!is_file($xlsxPath)) return $map;
    if (!class_exists('ZipArchive')) return $map;

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) return $map;

    $shared = [];
    $sstXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sstXml !== false && $sstXml !== '') {
        $xr = new XMLReader();
        $xr->XML($sstXml);
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
    }

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

    if ($sheetXml === false || $sheetXml === '') {
        $zip->close();
        return $map;
    }

    $keyIdx   = xlsx_col_to_index($keyCol);
    $valueIdx = xlsx_col_to_index($valueCol);

    $xr = new XMLReader();
    $xr->XML($sheetXml);

    $rowKey = null;
    $rowVal = null;

    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'row') {
            $rowKey = null;
            $rowVal = null;
        }

        if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'c') {
            $cellRef = (string)$xr->getAttribute('r');
            $colLetters = xlsx_cell_col_letters($cellRef);
            if ($colLetters === '') continue;

            $colIdx = xlsx_col_to_index($colLetters);
            if ($colIdx !== $keyIdx && $colIdx !== $valueIdx) {
                continue;
            }

            $cellType = (string)$xr->getAttribute('t');
            $outer = $xr->readOuterXML();
            $val = '';

            if ($outer !== '') {
                if ($cellType === 's') {
                    if (preg_match('/<v>(\d+)<\/v>/', $outer, $mm)) {
                        $si = (int)$mm[1];
                        $val = $shared[$si] ?? '';
                    }
                } elseif ($cellType === 'inlineStr') {
                    if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $outer, $mm)) {
                        $tmp = '';
                        foreach ($mm[1] as $part) {
                            $tmp .= html_entity_decode($part, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                        $val = $tmp;
                    }
                } else {
                    if (preg_match('/<v>(.*?)<\/v>/', $outer, $mm)) {
                        $val = $mm[1];
                    }
                }
            }

            if ($colIdx === $keyIdx) {
                $rowKey = $val;
            } elseif ($colIdx === $valueIdx) {
                $rowVal = $val;
            }
        }

        if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'row') {
            $key = $keyNormalizer ? $keyNormalizer($rowKey) : trim((string)$rowKey);
            $val = $valueNormalizer ? $valueNormalizer($rowVal) : trim((string)$rowVal);

            if ($key === null || $key === '' || $val === null || $val === '') {
                continue;
            }

            $map[(string)$key] = $val;
        }
    }

    $xr->close();
    $zip->close();
    return $map;
}

function orders_excel_find_path(): string {
    $dir = __DIR__ . '/Aktualizace_CSTORE';
    if (!is_dir($dir)) return '';

    $candidates = [
        $dir . '/craftinov8_nakupnicenysdph.xlsx',
        $dir . '/craftinov8_nakupnicenysdph.XLSX',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) return $c;
    }

    $files = array_merge(
        glob($dir . '/*.xlsx') ?: [],
        glob($dir . '/*.XLSX') ?: []
    );

    if (empty($files)) return '';

    usort($files, static function(string $a, string $b): int {
        $sa = mb_strtolower(basename($a), 'UTF-8');
        $sb = mb_strtolower(basename($b), 'UTF-8');

        $score = static function(string $name): int {
            $s = 0;
            foreach (['nakup', 'cena', 'craft', 'inov'] as $needle) {
                if (mb_strpos($name, $needle) !== false) $s += 10;
            }
            return $s;
        };

        $cmp = $score($sb) <=> $score($sa);
        if ($cmp !== 0) return $cmp;

        return filemtime($b) <=> filemtime($a);
    });

    return $files[0] ?? '';
}

function orders_excel_detect_price_col(string $xlsxPath): string {
    $candidates = ['C','D','E','F','G','H','I','J'];
    $bestCol = 'C';
    $bestCount = -1;

    foreach ($candidates as $col) {
        $map = xlsx_load_key_value_map(
            $xlsxPath,
            'A',
            $col,
            static function($v) {
                $s = trim((string)$v);
                return $s !== '' ? $s : null;
            },
            static function($v) {
                $p = xlsx_norm_price($v);
                return ($p !== null && $p > 0) ? $p : null;
            }
        );
        $cnt = count($map);
        if ($cnt > $bestCount) {
            $bestCount = $cnt;
            $bestCol = $col;
        }
    }

    return $bestCol;
}

function orders_fill_missing_from_excel(PDO $pdo, string $from = '', string $to = ''): array {
    $xlsxPath = orders_excel_find_path();
    if ($xlsxPath === '') {
        return ['ok' => false, 'message' => 'Excel se zdrojem cen nebyl nalezen ve složce /Aktualizace_CSTORE/.'];
    }

    $priceCol = orders_excel_detect_price_col($xlsxPath);

    $codeEanMap = xlsx_load_key_value_map(
        $xlsxPath,
        'A',
        'B',
        static function($v) {
            $s = trim((string)$v);
            return $s !== '' ? $s : null;
        },
        static function($v) {
            return xlsx_norm_ean($v);
        }
    );

    $codePriceMap = xlsx_load_key_value_map(
        $xlsxPath,
        'A',
        $priceCol,
        static function($v) {
            $s = trim((string)$v);
            return $s !== '' ? $s : null;
        },
        static function($v) {
            $p = xlsx_norm_price($v);
            return ($p !== null && $p > 0) ? $p : null;
        }
    );

    $eanPriceMap = xlsx_load_key_value_map(
        $xlsxPath,
        'B',
        $priceCol,
        static function($v) {
            return xlsx_norm_ean($v);
        },
        static function($v) {
            $p = xlsx_norm_price($v);
            return ($p !== null && $p > 0) ? $p : null;
        }
    );

    if (empty($codeEanMap) && empty($codePriceMap) && empty($eanPriceMap)) {
        return ['ok' => false, 'message' => 'Excel byl nalezen, ale nepodařilo se z něj načíst EAN ani ceny.'];
    }

    $sql = "
        SELECT oi.id, oi.product_number, oi.EAN, oi.nakupni_cena
        FROM order_items oi
        INNER JOIN orders o ON o.id_order = oi.id_order
        WHERE (oi.EAN IS NULL OR TRIM(oi.EAN) = '' OR oi.nakupni_cena IS NULL OR oi.nakupni_cena = 0)
    ";
    $sqlParams = [];

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= " AND o.created_at >= :from_date";
        $sqlParams[':from_date'] = $from . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= " AND o.created_at <= :to_date";
        $sqlParams[':to_date'] = $to . ' 23:59:59';
    }

    $sql .= " ORDER BY oi.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($sqlParams);
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $checked = 0;
    $updatedRows = 0;
    $eanUpdated = 0;
    $priceUpdated = 0;

    foreach ($rows as $row) {
        $checked++;

        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;

        $code = trim((string)($row['product_number'] ?? ''));
        $currentEan = trim((string)($row['EAN'] ?? ''));
        $currentEanNorm = xlsx_norm_ean($currentEan);
        $currentPrice = isset($row['nakupni_cena']) ? (float)$row['nakupni_cena'] : 0.0;

        $newEan = null;
        if ($currentEanNorm === null && $code !== '' && isset($codeEanMap[$code])) {
            $newEan = (string)$codeEanMap[$code];
        }

        $newPrice = null;
        if ($currentPrice <= 0) {
            if ($code !== '' && isset($codePriceMap[$code])) {
                $newPrice = (float)$codePriceMap[$code];
            } else {
                $priceEanKey = $currentEanNorm ?: $newEan;
                if ($priceEanKey !== null && isset($eanPriceMap[$priceEanKey])) {
                    $newPrice = (float)$eanPriceMap[$priceEanKey];
                }
            }
        }

        $set = [];
        $params = [':id' => $id];

        if ($currentEanNorm === null && $newEan !== null && $newEan !== '') {
            $set[] = "EAN = :ean";
            $params[':ean'] = $newEan;
            $eanUpdated++;
        }

        if ($currentPrice <= 0 && $newPrice !== null && $newPrice > 0) {
            $set[] = "nakupni_cena = :price";
            $params[':price'] = $newPrice;
            $priceUpdated++;
        }

        if (empty($set)) {
            continue;
        }

        $sql = "UPDATE order_items SET " . implode(', ', $set) . " WHERE id = :id LIMIT 1";
        $upd = $pdo->prepare($sql);
        $upd->execute($params);

        if ($upd->rowCount() > 0) {
            $updatedRows++;
        }
    }

    return [
        'ok' => true,
        'message' => 'Doplněno z Excelu: EAN ' . (int)$eanUpdated . '×, ceny ' . (int)$priceUpdated . '×, upravené řádky ' . (int)$updatedRows . '×.',
        'checked' => $checked,
        'updated_rows' => $updatedRows,
        'ean_updated' => $eanUpdated,
        'price_updated' => $priceUpdated,
        'xlsx' => basename($xlsxPath),
        'price_col' => $priceCol,
    ];
}


/**
 * CSV loader přes fgetcsv – zvládá i buňky s více řádky
 */
function loadCsvRows(string $url) {
    $raw = @file_get_contents($url);
    if ($raw === false) {
        return false;
    }

    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
        return false;
    }
    fwrite($fp, $raw);
    rewind($fp);

    $rows = [];
    while (($cols = fgetcsv($fp, 0, ",")) !== false) {
        $rows[] = $cols;
    }
    fclose($fp);
    return $rows;
}

function parseSheetDate(?string $s): ?DateTime {
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;

    // Google date číslo
    if (is_numeric($s)) {
        $base = new DateTime('1899-12-30');
        $base->modify('+' . (int)$s . ' days');
        $base->setTime(0, 0, 0);
        return $base;
    }

    $formats = [
        'Y-m-d',
        'd.m.Y',
        'd. m. Y',
        'd.m.Y H:i:s',
        'd. m. Y H:i:s',
    ];
    foreach ($formats as $f) {
        $dt = DateTime::createFromFormat($f, $s);
        if ($dt instanceof DateTime) {
            $dt->setTime(0, 0, 0);
            return $dt;
        }
    }

    $ts = strtotime($s);
    if ($ts !== false) {
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone('Europe/Prague'));
        $dt->setTime(0, 0, 0);
        return $dt;
    }

    return null;
}

/**
 * Najde index sloupce v hlavičce CSV podle názvu (nebo části názvu).
 */
function findHeaderIndexDynamic(array $headerRow, array $candidates): ?int {
    foreach ($headerRow as $idx => $cell) {
        $value = mb_strtolower(trim((string)$cell), 'UTF-8');
        if ($value === '') continue;

        foreach ($candidates as $cand) {
            $candNorm = mb_strtolower(trim((string)$cand), 'UTF-8');
            if ($candNorm === '') continue;

            if ($value === $candNorm || mb_strpos($value, $candNorm) !== false) {
                return $idx;
            }
        }
    }
    return null;
}

/** brand helpery **/
function mb_contains_any(string $haystack, array $needles): bool {
    foreach ($needles as $n) {
        if ($n === '') continue;
        if (mb_stripos($haystack, $n, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Zda řádek odpovídá vybranému brand filtru
 */
function match_brand_filter(string $brand, string $nameLower): bool {
    if ($brand === 'all') {
        return true;
    }

    switch ($brand) {
        case 'craft':
            return mb_contains_any($nameLower, ['craft']);
        case 'silvini':
            return mb_contains_any($nameLower, ['silvini']);
        case 'devold':
            return mb_contains_any($nameLower, ['devold', 'devodl', 'devol', 'devod']);
        case 'ale':
            return mb_contains_any($nameLower, ['alé', 'ale']);
        case 'didriksons':
            return mb_contains_any($nameLower, ['didriksons', 'd1913']);
        case 'haglofs':
            return mb_contains_any($nameLower, ['haglofs', 'haglöfs']);
        case 'viking':
            return mb_contains_any($nameLower, ['viking']);
        case 'isadore':
            return mb_contains_any($nameLower, ['isadore']);
        case 'neon':
            return mb_contains_any($nameLower, ['neon']);
        case 'lillsport':
            return mb_contains_any($nameLower, ['lill-sport', 'lillsport', 'lill sport']);
        case 'inov8':
            return mb_contains_any($nameLower, ['inov-8', 'inov8', 'inov 8']);
        case 'silva':
            return mb_contains_any($nameLower, ['silva']);
        case 'karitraa':
            return mb_contains_any($nameLower, ['kari traa', 'karitraa']);
    }

    return true;
}

/**
 * Vrátí klíč značky pro graf
 */
function detect_brand_key(string $nameLower): string {
    if (mb_contains_any($nameLower, ['craft']))         return 'craft';
    if (mb_contains_any($nameLower, ['silvini']))       return 'silvini';
    if (mb_contains_any($nameLower, ['devold','devodl','devol','devod'])) return 'devold';
    if (mb_contains_any($nameLower, ['alé','ale']))     return 'ale';
    if (mb_contains_any($nameLower, ['didriksons','d1913'])) return 'didriksons';
    if (mb_contains_any($nameLower, ['haglofs','haglöfs']))  return 'haglofs';
    if (mb_contains_any($nameLower, ['viking']))        return 'viking';
    return 'other';
}

// view: home / print / stats / customers / orders / service / cservis_fakturace / returns / xmlfeedy
$view = isset($_GET['view']) ? $_GET['view'] : 'home';

// Modul "Vrácené zboží" byl odstraněn (už se nepoužívá). Zbytek kódu níže
// (funkce returns_*, AJAX handlery, vykreslení záložky) zůstává ve
// souboru, ale je od teď trvale nedosažitelný – přesměrujeme pryč dřív,
// než se k němu dostane provádění.
if ($view === 'returns') {
    header('Location: index.php');
    exit;
}

if ($loggedIn && $view === 'returns' && isset($_GET['returns_api']) && $_GET['returns_api'] === 'product_search') {
    $oldDisplayErrors = ini_get('display_errors');
    $oldErrorReporting = error_reporting();
    ini_set('display_errors', '0');
    error_reporting(0);
    header('Content-Type: application/json; charset=utf-8');
    $noise = '';
    ob_start();
    try {
        $qApi = trim((string)($_GET['q'] ?? ''));
        $itemsApi = returns_search_products_for_admin($pdo, $qApi, 25);
        $noise = (string)ob_get_clean();
        if ($noise !== '') { @error_log('returns product_search suppressed output: ' . substr(strip_tags($noise), 0, 500)); }
        echo json_encode(['success'=>true, 'items'=>$itemsApi], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        $noise = (string)ob_get_clean();
        if ($noise !== '') { @error_log('returns product_search suppressed output: ' . substr(strip_tags($noise), 0, 500)); }
        echo json_encode(['success'=>false, 'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    ini_set('display_errors', (string)$oldDisplayErrors);
    error_reporting($oldErrorReporting);
    exit;
}

$ordersFillFlash = '';
if (isset($_SESSION['orders_fill_flash'])) {
    $ordersFillFlash = (string)$_SESSION['orders_fill_flash'];
    unset($_SESSION['orders_fill_flash']);
}
$returnsFlash = '';
if (isset($_SESSION['returns_flash'])) {
    $returnsFlash = (string)$_SESSION['returns_flash'];
    unset($_SESSION['returns_flash']);
}

// Akce v modulu Vrácené zboží
if ($loggedIn && $view === 'returns' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['returns_action'])) {
    $from = trim((string)($_POST['from'] ?? ''));
    $to   = trim((string)($_POST['to'] ?? ''));
    $q    = trim((string)($_POST['q'] ?? ''));
    $tab  = trim((string)($_POST['tab'] ?? ''));

    try {
        returns_ensure_schema($pdo);
        $action = (string)($_POST['returns_action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Chybí ID vratky.');

        if ($action === 'update_status') {
            $status = (string)($_POST['status'] ?? 'NEW');
            $labels = returns_status_labels();
            if (!isset($labels[$status])) throw new RuntimeException('Neplatný stav.');

            // Stav TO_REFUND nemusí být v DB enumu. Skutečný stav ukládáme do meta JSON.
            $dbStatus = ($status === 'TO_REFUND') ? 'APPROVED' : $status;
            if (!in_array($dbStatus, ['NEW','RECEIVED','APPROVED','REFUNDED','REJECTED','CLOSED'], true)) $dbStatus = 'NEW';
            try {
                $st = $pdo->prepare('UPDATE returns_requests SET status = :s, updated_at = NOW() WHERE id = :id');
                $st->execute([':s'=>$dbStatus, ':id'=>$id]);
            } catch (Throwable $ignore) {}

            returns_meta_update($id, ['status' => $status]);

            if ($status === 'TO_REFUND') {
                $cn = returns_assign_credit_note($pdo, $id);
                $_SESSION['returns_flash'] = 'Vratka byla předána k proplacení. Dobropis: ' . $cn;
            } else {
                $_SESSION['returns_flash'] = 'Stav vratky byl uložen.';
            }
        } elseif ($action === 'update_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('Chybí ID položky.');
            $qty = max(0, returns_item_clean_num($_POST['return_qty'] ?? 0));
            $price = returns_item_clean_num($_POST['price_per_unit_with_vat'] ?? 0);
            $line = round($qty * $price, 2);
            $pn = trim((string)($_POST['product_number'] ?? ''));
            $name = trim((string)($_POST['product_name'] ?? ''));
            $variant = trim((string)($_POST['variant_description'] ?? ''));
            $ean = trim((string)($_POST['ean'] ?? ''));

            $st = $pdo->prepare("\n                UPDATE returns_items\n                SET product_number = :pn,\n                    product_name = :name,\n                    variant_description = :variant,\n                    ean = :ean,\n                    return_qty = :qty,\n                    price_per_unit_with_vat = :price,\n                    line_total_with_vat = :line\n                WHERE id = :item_id AND request_id = :request_id\n            ");
            $st->execute([
                ':pn'=>$pn, ':name'=>$name, ':variant'=>$variant, ':ean'=>$ean,
                ':qty'=>$qty, ':price'=>$price, ':line'=>$line,
                ':item_id'=>$itemId, ':request_id'=>$id
            ]);
            returns_recalc_total($pdo, $id);
            $_SESSION['returns_flash'] = 'Položka vratky byla uložena.';
        } elseif ($action === 'delete_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId <= 0) throw new RuntimeException('Chybí ID položky.');
            $st = $pdo->prepare("DELETE FROM returns_items WHERE id = :item_id AND request_id = :request_id");
            $st->execute([':item_id'=>$itemId, ':request_id'=>$id]);
            returns_recalc_total($pdo, $id);
            $_SESSION['returns_flash'] = 'Položka byla odstraněna.';
        } elseif ($action === 'add_item') {
            $qty = max(0, returns_item_clean_num($_POST['return_qty'] ?? 1));
            if ($qty <= 0) $qty = 1.0;
            $price = returns_item_clean_num($_POST['price_per_unit_with_vat'] ?? 0);
            $line = round($qty * $price, 2);
            $pn = trim((string)($_POST['product_number'] ?? ''));
            $name = trim((string)($_POST['product_name'] ?? ''));
            $variant = trim((string)($_POST['variant_description'] ?? ''));
            $ean = trim((string)($_POST['ean'] ?? ''));
            if ($name === '' && $pn === '' && $ean === '') throw new RuntimeException('Vyber nebo doplň produkt.');

            $st = $pdo->prepare("\n                INSERT INTO returns_items\n                  (request_id, product_number, product_name, variant_description, ean, return_qty, price_per_unit_with_vat, line_total_with_vat)\n                VALUES\n                  (:request_id, :pn, :name, :variant, :ean, :qty, :price, :line)\n            ");
            $st->execute([
                ':request_id'=>$id, ':pn'=>$pn, ':name'=>$name, ':variant'=>$variant,
                ':ean'=>$ean, ':qty'=>$qty, ':price'=>$price, ':line'=>$line
            ]);
            returns_recalc_total($pdo, $id);
            $_SESSION['returns_flash'] = 'Produkt byl přidán do vratky.';
        }
    } catch (Throwable $e) {
        $_SESSION['returns_flash'] = 'CHYBA: ' . $e->getMessage();
    }

    $qs = ['view'=>'returns'];
    if ($from !== '') $qs['from'] = $from;
    if ($to !== '') $qs['to'] = $to;
    if ($q !== '') $qs['q'] = $q;
    if ($tab !== '') $qs['tab'] = $tab;
    header('Location: index.php?' . http_build_query($qs));
    exit;
}

// Uložení SMS nastavení (jen v modulu Tisk štítků)
if ($loggedIn && $view === 'orders' && isset($_POST['action']) && in_array((string)$_POST['action'], ['orders_fill_missing_supplier_feeds', 'orders_fill_missing_excel'], true)) {
    $q     = trim((string)($_POST['q'] ?? ''));
    $from  = trim((string)($_POST['from'] ?? ''));
    $to    = trim((string)($_POST['to'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $page  = max(1, (int)($_POST['page'] ?? 1));

    try {
        $eanRes = function_exists('cfloat_fill_ean_auto') ? cfloat_fill_ean_auto($pdo, false) : ['updated_rows' => 0];
        $fillRes = cfloat_fill_purchase_price_auto($pdo, true, true, $from, $to);
        $msg = 'EAN doplněn u ' . (int)($eanRes['updated_rows'] ?? 0) . ' položek. ';
        $msg .= !empty($fillRes['message']) ? (string)$fillRes['message'] : 'Akce dokončena.';
        $_SESSION['orders_fill_flash'] = (!empty($fillRes['ok']) ? 'OK: ' : 'CHYBA: ') . $msg;
    } catch (Throwable $e) {
        $_SESSION['orders_fill_flash'] = 'CHYBA: ' . $e->getMessage();
    }

    $qs = ['view' => 'orders'];
    if ($q !== '')     $qs['q'] = $q;
    if ($from !== '')  $qs['from'] = $from;
    if ($to !== '')    $qs['to'] = $to;
    if ($email !== '') $qs['email'] = $email;
    if ($page > 1)     $qs['page'] = $page;

    header('Location: index.php?' . http_build_query($qs));
    exit;
}

if ($loggedIn && $view === 'print' && isset($_POST['action']) && $_POST['action'] === 'save_sms_settings' && function_exists('sms_save_settings')) {
    $ok = sms_save_settings([
        'enabled' => isset($_POST['sms_enabled']) ? 1 : 0,
        'test_mode' => isset($_POST['sms_test_mode']) ? 1 : 0,
        'test_phone' => (string)($_POST['sms_test_phone'] ?? ''),
        'daily_limit' => (string)($_POST['sms_daily_limit'] ?? ''),
        'api_token' => (string)($_POST['sms_api_token'] ?? ''),
        'template' => (string)($_POST['sms_template'] ?? ''),
        'send_only_if_tracking' => isset($_POST['sms_only_if_tracking']) ? 1 : 0,
    ]);
    header('Location: index.php?view=print&sms_saved=' . ($ok ? '1' : '0'));
    exit;
}


// SERVIS – ruční uložení nákupní ceny bez DPH pro položku
if ($loggedIn && $view === 'service' && isset($_POST['action']) && $_POST['action'] === 'service_save_purchase') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $from   = trim((string)($_POST['from'] ?? ''));
    $to     = trim((string)($_POST['to'] ?? ''));
    $raw    = trim((string)($_POST['purchase_price'] ?? ''));

    if ($itemId > 0) {
        $price = null;

        if ($raw !== '') {
            $normalized = str_replace([' ', ','], ['', '.'], $raw);
            $normalized = preg_replace('/[^0-9.\-]/', '', $normalized);
            if ($normalized !== '' && is_numeric($normalized)) {
                $price = (float)$normalized;
                if ($price < 0) {
                    $price = 0.0;
                }
            }
        }

        try {
            $stUpd = $pdo->prepare("
                UPDATE order_items
                SET nakupni_cena = :price
                WHERE id = :id
                LIMIT 1
            ");
            $stUpd->bindValue(':id', $itemId, PDO::PARAM_INT);
            if ($price === null) {
                $stUpd->bindValue(':price', null, PDO::PARAM_NULL);
            } else {
                $stUpd->bindValue(':price', $price);
            }
            $stUpd->execute();
        } catch (Throwable $e) {
            // bez hlášky, návrat zpět do přehledu
        }
    }

    $qs = ['view' => 'service'];
    if ($from !== '') $qs['from'] = $from;
    if ($to   !== '') $qs['to']   = $to;

    header('Location: index.php?' . http_build_query($qs));
    exit;
}


// SERVIS – ruční přidání řádku
if ($loggedIn && $view === 'service' && isset($_POST['action']) && $_POST['action'] === 'service_add_manual_row') {
    $from = trim((string)($_POST['from'] ?? ''));
    $to   = trim((string)($_POST['to'] ?? ''));

    $manualDate = trim((string)($_POST['manual_date'] ?? ''));
    $manualDt = DateTime::createFromFormat('Y-m-d', $manualDate);
    if (!$manualDt instanceof DateTime) {
        $manualDt = new DateTime('now', new DateTimeZone('Europe/Prague'));
    }
    $manualCreatedAt = $manualDt->format('Y-m-d') . ' 12:00:00';

    $name        = trim((string)($_POST['manual_name'] ?? ''));
    $orderNumber = trim((string)($_POST['manual_order_number'] ?? ''));
    $code        = trim((string)($_POST['manual_code'] ?? ''));
    $product     = trim((string)($_POST['manual_product'] ?? ''));
    $variant     = trim((string)($_POST['manual_variant'] ?? ''));

    $purchase  = service_manual_parse_number($_POST['manual_purchase'] ?? '');
    $qty       = service_manual_parse_number($_POST['manual_qty'] ?? '1');
    $lineTotal = service_manual_parse_number($_POST['manual_line_total'] ?? '0');

    if ($qty === null || $qty <= 0) $qty = 1.0;
    if ($lineTotal === null) $lineTotal = 0.0;
    if ($purchase !== null && $purchase < 0) $purchase = 0.0;

    if ($name === '') $name = 'Ruční servis';
    if ($product === '') $product = 'Ruční položka';
    if ($orderNumber === '') $orderNumber = 'RUČNÍ-' . $manualDt->format('Ymd-His');

    $rows = service_manual_rows_load();
    $rows[] = [
        'id' => (int)(microtime(true) * 1000) + random_int(1, 999),
        'date' => $manualCreatedAt,
        'name' => $name,
        'order_number' => $orderNumber,
        'code' => $code,
        'purchase' => $purchase,
        'product' => $product,
        'variant' => $variant,
        'qty' => $qty,
        'line_total' => $lineTotal,
        'vat' => isset($_POST['manual_vat']) ? 1 : 0,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    service_manual_rows_save($rows);

    $qs = ['view' => 'service'];
    if ($from !== '') $qs['from'] = $from;
    if ($to   !== '') $qs['to']   = $to;

    header('Location: index.php?' . http_build_query($qs));
    exit;
}

// SERVIS – úprava ceny u ručního řádku
if ($loggedIn && $view === 'service' && isset($_POST['action']) && $_POST['action'] === 'service_update_manual_row') {
    $from = trim((string)($_POST['from'] ?? ''));
    $to   = trim((string)($_POST['to'] ?? ''));
    $manualId = (int)($_POST['manual_id'] ?? 0);

    if ($manualId > 0) {
        $rows = service_manual_rows_load();
        foreach ($rows as &$r) {
            if ((int)($r['id'] ?? 0) !== $manualId) continue;

            if (array_key_exists('manual_purchase', $_POST)) {
                $purchase = service_manual_parse_number($_POST['manual_purchase'] ?? '');
                if ($purchase !== null && $purchase < 0) $purchase = 0.0;
                $r['purchase'] = $purchase;
            }

            if (array_key_exists('manual_line_total', $_POST)) {
                $lineTotal = service_manual_parse_number($_POST['manual_line_total'] ?? '0');
                if ($lineTotal === null) $lineTotal = 0.0;
                if ($lineTotal < 0) $lineTotal = 0.0;
                $r['line_total'] = $lineTotal;
            }

            if (array_key_exists('manual_vat_update', $_POST)) {
                $r['vat'] = isset($_POST['manual_vat']) ? 1 : 0;
            }

            break;
        }
        unset($r);
        service_manual_rows_save($rows);
    }

    $qs = ['view' => 'service'];
    if ($from !== '') $qs['from'] = $from;
    if ($to   !== '') $qs['to']   = $to;

    header('Location: index.php?' . http_build_query($qs));
    exit;
}

// SERVIS – smazání ručního řádku
if ($loggedIn && $view === 'service' && isset($_POST['action']) && $_POST['action'] === 'service_delete_manual_row') {
    $from = trim((string)($_POST['from'] ?? ''));
    $to   = trim((string)($_POST['to'] ?? ''));
    $manualId = (int)($_POST['manual_id'] ?? 0);

    if ($manualId > 0) {
        $rows = service_manual_rows_load();
        $rows = array_values(array_filter($rows, function($r) use ($manualId) {
            return (int)($r['id'] ?? 0) !== $manualId;
        }));
        service_manual_rows_save($rows);
    }

    $qs = ['view' => 'service'];
    if ($from !== '') $qs['from'] = $from;
    if ($to   !== '') $qs['to']   = $to;

    header('Location: index.php?' . http_build_query($qs));
    exit;
}


if ($loggedIn && $view === 'service' && isset($_POST['action']) && $_POST['action'] === 'service_toggle_purchase_vat') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $from   = trim((string)($_POST['from'] ?? ''));
    $to     = trim((string)($_POST['to'] ?? ''));
    $turnOn = isset($_POST['vat_mode']) && (string)$_POST['vat_mode'] === '1';

    if ($itemId > 0) {
        // jen ulož/odlož označení (výpočet ceny s DPH děláme až při zobrazení + ve výpočtu zisku)
        service_vat_set_mark($itemId, $turnOn);
    }

    $qs = ['view' => 'service'];
    if ($from !== '') $qs['from'] = $from;
    if ($to   !== '') $qs['to']   = $to;

    header('Location: index.php?' . http_build_query($qs));
    exit;
}


// VB (výměnný balík) – modul byl odstraněn, ponechávám proměnnou kvůli
// zpětné kompatibilitě šablony (badge se už nezobrazí).
$vbNewCount = 0;


// TISK
$ean          = '';
$status       = '';
$message      = '';
$customerName = '';
$isPaid       = null;
$paidTextRaw  = '';
$codAmount    = '';
$codOverrideOn   = false;
$codOverrideRaw  = '';
$printItems      = [];
$printItemsError = '';

// OBJEDNÁVKY
$ordersView        = false;
$ordersError       = '';
$ordersSearch      = '';
$ordersPage        = 1;
$ordersPerPage     = 100;
$ordersTotal       = 0;
$ordersRows        = [];
$ordersItemsById   = [];
$ordersPurchaseById= [];
$ordersProfitById  = [];
$ordersMissingById = [];
$ordersEmailStats  = [];
$ordersFilterEmail = '';
$ordersDateFromStr = '';
$ordersDateToStr   = '';
$ordersProfitSum   = 0.0;

// SERVIS
$serviceView        = false;
$serviceDateFromStr = '';
$serviceDateToStr   = '';
$serviceError       = '';
$serviceRows        = [];
$serviceItemsById   = [];
$serviceOrderHasInvoice = [];
$serviceTotalSum    = 0.0;
$serviceWorkCount   = 0.0;
$serviceWorkSum     = 0.0;

$serviceGoodsProfitSum = 0.0;
$serviceGoodsProfitMissing = 0;

// STATISTIKA
$showStats    = false;
$dateFromStr  = '';
$dateToStr    = '';
$brand        = 'all';
$statsError   = '';
$statsResult  = [];
$statsTotalAD = 0.0;

// SUMY PRO GRAF DLE ZNAČEK (AC)
$brandSums = [
    'craft'      => 0.0,
    'silvini'    => 0.0,
    'devold'     => 0.0,
    'ale'        => 0.0,
    'didriksons' => 0.0,
    'haglofs'    => 0.0,
    'viking'     => 0.0,
    'isadore'    => 0.0,
    'neon'       => 0.0,
    'lillsport'  => 0.0,
    'inov8'      => 0.0,
    'silva'      => 0.0,
    'karitraa'   => 0.0,
    'other'      => 0.0,
];

// ZÁKAZNÍCI
$customersShow        = false;
$customersDateFromStr = '';
$customersDateToStr   = '';
$customersSort        = 'orders';
$customersError       = '';
$customersData        = [];

// položky objednávek (pro detail zákazníků)
$orderItemsError         = '';
$orderItemsByOrderNumber = [];
$orderItemsAvailable     = false;

// VRÁCENÉ ZBOŽÍ
$returnsShow        = false;
$returnsDateFromStr = '';
$returnsDateToStr   = '';
$returnsSearchQuery = '';
$returnsTab         = 'list';
$returnsError       = '';
$returnsRows        = [];
$returnsToRefundRows = [];
$returnsPaidRows     = [];
$returnsProductsRows = [];
$returnsItemsByRequest = [];
$returnsTotalQty    = 0;
$returnsTotalPrice  = 0.0;
$returnsStats       = [];
$returnsTopStats    = [];
$returnsStatusLabels = returns_status_labels();

// ---------- TISK ŠTÍTKŮ ----------
if ($loggedIn && $view === 'print') {
    $ean = isset($_GET['ean']) ? trim($_GET['ean']) : '';
    $codOverrideOn  = isset($_GET['cod_override_on']) && (string)$_GET['cod_override_on'] === '1';
    $codOverrideRaw = isset($_GET['cod_override']) ? trim((string)$_GET['cod_override']) : '';

    if ($ean !== '') {
        try {
            $sql = "
                SELECT
                    id_order,
                    created_at,
                    customer_name,
                    zaplaceno,
                    gopay_zaplaceno,
                    gateway_payment_state,
                    payment_name,
                    payment_amount
                FROM orders
                WHERE number = :number
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':number' => $ean]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $customerName = trim((string)($row['customer_name'] ?? ''));

                // vyhodnocení zaplaceno
                $dbPaid    = isset($row['zaplaceno']) ? ((int)$row['zaplaceno'] === 1) : false;
                $gopayPaid = isset($row['gopay_zaplaceno']) ? ((int)$row['gopay_zaplaceno'] === 1) : false;
                $gwState   = isset($row['gateway_payment_state']) ? (string)$row['gateway_payment_state'] : '';
                $gwNorm    = mb_strtolower($gwState, 'UTF-8');

                if ($dbPaid || $gopayPaid || $gwNorm === 'paid') {
                    $isPaid = true;
                    $paidTextRaw = $gwState !== '' ? $gwState : 'paid';
                } else {
                    $isPaid = false;
                    $paidTextRaw = $gwState !== '' ? $gwState : 'unpaid';
                }

                if (isset($row['payment_amount']) && $row['payment_amount'] !== null) {
                    $codAmount = (string)$row['payment_amount'];
                }

                $status  = 'ok';
                $message = 'V pořádku se načetlo. Tisknu štítek…';

                // načtení položek
                try {
                    if (!empty($row['id_order'])) {
                        $sqlItems = "
                            SELECT
                                product_number,
                                product_name,
                                variant_description,
                                count,
                                price_total_with_vat
                            FROM order_items
                            WHERE id_order = :id_order
                            ORDER BY id ASC
                        ";
                        $stmtItems = $pdo->prepare($sqlItems);
                        $stmtItems->execute([
                            ':id_order' => (int)$row['id_order'],
                        ]);
                        $printItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }
                } catch (Throwable $e) {
                    $printItemsError = 'Nepodařilo se načíst položky objednávky: ' . $e->getMessage();
                }

            } else {
                $status  = 'notfound';
                $message = 'Objednávka s tímto kódem nebyla nalezena.';
            }

        } catch (Throwable $e) {
            $status  = 'error';
            $message = 'Chyba při načítání objednávky z databáze: ' . $e->getMessage();
        }
    }
}

// ---------- OBJEDNÁVKY ----------
if ($loggedIn && $view === 'orders') {
    $ordersView        = true;
    $ordersSearch      = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $ordersFilterEmail = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
    $ordersPage        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $ordersDateFromStr = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    $ordersDateToStr   = isset($_GET['to']) ? trim((string)$_GET['to']) : '';

    // výchozí období – aktuální měsíc, pokud uživatel nic nezadal
    if ($ordersDateFromStr === '' && $ordersDateToStr === '') {
        try {
            $dtFrom = new DateTime('first day of this month 00:00:00');
            $dtTo   = new DateTime('tomorrow');
            $ordersDateFromStr = $dtFrom->format('Y-m-d');
            $ordersDateToStr   = $dtTo->format('Y-m-d');
        } catch (Exception $e) {
            $ordersDateFromStr = '';
            $ordersDateToStr   = '';
        }
    }

    $whereParts = [];
    $params     = [];
    $joinItems  = '';

    // --- měna / kurz / faktura odkazy (pokud jsou sloupce v DB) ---
    $ordersCurrencyCol = '';
    $ordersRateCol     = '';
    $ordersInvUrlCol   = '';
    $ordersInvHtmlCol  = '';

    // zjistíme názvy sloupců dynamicky (aby to nespadlo, když v DB sloupec není)
    try {
        $currencyCandidates = ['currency_code','currency','selected_currency','selected_currency_code','mena','currencyCode'];
        foreach ($currencyCandidates as $c) {
            if (db_has_column($pdo, 'orders', $c)) { $ordersCurrencyCol = $c; break; }
        }

        $rateCandidates = ['exchange_rate','exchangeRate','selected_currency_rate','currency_rate','rate','exchange_rate_value'];
        foreach ($rateCandidates as $c) {
            if (db_has_column($pdo, 'orders', $c)) { $ordersRateCol = $c; break; }
        }

        $invCandidates = ['invoice_url','invoiceUrl','invoice_pdf_url','invoice_pdf','invoice_link'];
        foreach ($invCandidates as $c) {
            if (db_has_column($pdo, 'orders', $c)) { $ordersInvUrlCol = $c; break; }
        }

        $invHtmlCandidates = ['invoice_url_html','invoiceUrlHtml','invoice_html_url','invoice_html','invoice_link_html'];
        foreach ($invHtmlCandidates as $c) {
            if (db_has_column($pdo, 'orders', $c)) { $ordersInvHtmlCol = $c; break; }
        }
    } catch (Throwable $e) {
        // necháme prázdné – jen se nevypíšou odkazy / měna
    }

    if ($ordersFilterEmail !== '') {
        $whereParts[]     = 'LOWER(TRIM(o.customer_email)) = LOWER(TRIM(:email))';
        $params[':email'] = $ordersFilterEmail;
    }

    // filtrování podle data vytvoření objednávky
    if ($ordersDateFromStr !== '') {
        $whereParts[]           = 'o.created_at >= :from_date';
        $params[':from_date']   = $ordersDateFromStr . ' 00:00:00';
    }
    if ($ordersDateToStr !== '') {
        $whereParts[]           = 'o.created_at <= :to_date';
        $params[':to_date']     = $ordersDateToStr . ' 23:59:59';
    }

    if ($ordersSearch !== '') {
        $joinItems = 'LEFT JOIN order_items oi ON oi.id_order = o.id_order';

        // POZOR: v PDO (MySQL) může opakované použití stejného named parametru (např. :qs vícekrát)
        // vyhodit SQLSTATE[HY093]. Proto generujeme unikátní parametry :qs1, :qs2, ...
        $searchVal = '%' . $ordersSearch . '%';
        $orParts   = [];
        $i         = 1;

        // orders sloupce (existence-safe)
        if (db_has_column($pdo, 'orders', 'number')) {
            $k = ':qs' . $i++; $orParts[] = "o.number LIKE $k"; $params[$k] = $searchVal;
        }
        if (db_has_column($pdo, 'orders', 'invoice_number')) {
            $k = ':qs' . $i++; $orParts[] = "o.invoice_number LIKE $k"; $params[$k] = $searchVal;
        }
        if (db_has_column($pdo, 'orders', 'customer_name')) {
            $k = ':qs' . $i++; $orParts[] = "o.customer_name LIKE $k"; $params[$k] = $searchVal;
        }
        if (db_has_column($pdo, 'orders', 'customer_email')) {
            $k = ':qs' . $i++; $orParts[] = "o.customer_email LIKE $k"; $params[$k] = $searchVal;
        }
        if (db_has_column($pdo, 'orders', 'customer_phone')) {
            $k = ':qs' . $i++; $orParts[] = "o.customer_phone LIKE $k"; $params[$k] = $searchVal;
        }

        // order_items sloupce (existence-safe)
        if (db_has_column($pdo, 'order_items', 'product_name')) {
            $k = ':qs' . $i++; $orParts[] = "oi.product_name LIKE $k"; $params[$k] = $searchVal;
        }
        if (db_has_column($pdo, 'order_items', 'product_number')) {
            $k = ':qs' . $i++; $orParts[] = "oi.product_number LIKE $k"; $params[$k] = $searchVal;
        }

        if (!empty($orParts)) {
            $whereParts[] = '(' . implode(' OR ', $orParts) . ')';
        }
    }

    $whereSql = '';
    if (!empty($whereParts)) {
        $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
    }



    // --- EXPORT SILVINI DAT (CSV) ---
    // Exportuje položky objednávek pro vybrané období (a případné filtry výše)
    // pouze pro produkty obsahující "Silvini" (case-insensitive).
    if (isset($_GET['export']) && (string)$_GET['export'] === 'silvini') {
        try {
            // EAN v order_items může být u nově přidaných variant prázdný.
            // Pro export proto bereme fallback z ean_map / kompletní databáze variant podle product_id + variant_id.
            // Nic se neukládá do DB, mění se jen hodnota vypsaná do CSV.
            $exportEanParts = ["NULLIF(TRIM(oi.`EAN`), '')"];
            $exportEanJoins = '';

            if (
                db_has_column($pdo, 'order_items', 'product_id') &&
                db_has_column($pdo, 'order_items', 'variant_id') &&
                db_has_column($pdo, 'ean_map', 'product_id') &&
                db_has_column($pdo, 'ean_map', 'variant_id') &&
                db_has_column($pdo, 'ean_map', 'ean')
            ) {
                $exportEanJoins .= "
                LEFT JOIN ean_map em
                  ON em.product_id = oi.product_id
                 AND em.variant_id = oi.variant_id";
                $exportEanParts[] = "NULLIF(TRIM(em.`ean`), '')";
            }

            if (
                db_has_column($pdo, 'order_items', 'product_id') &&
                db_has_column($pdo, 'order_items', 'variant_id') &&
                db_has_column($pdo, 'Kompletni_DatabazeVariantyEANProdejeCeny', 'product_id') &&
                db_has_column($pdo, 'Kompletni_DatabazeVariantyEANProdejeCeny', 'variant_id') &&
                db_has_column($pdo, 'Kompletni_DatabazeVariantyEANProdejeCeny', 'ean')
            ) {
                $exportEanJoins .= "
                LEFT JOIN (
                    SELECT
                        product_id,
                        variant_id,
                        MAX(NULLIF(TRIM(ean), '')) AS ean
                    FROM Kompletni_DatabazeVariantyEANProdejeCeny
                    WHERE ean IS NOT NULL AND TRIM(ean) <> ''
                    GROUP BY product_id, variant_id
                ) kev
                  ON kev.product_id = oi.product_id
                 AND kev.variant_id = oi.variant_id";
                $exportEanParts[] = "NULLIF(TRIM(kev.`ean`), '')";
            }

            $exportEanSql = 'COALESCE(' . implode(', ', $exportEanParts) . ", '')";

            $sqlExport = "
                SELECT
                    oi.product_name AS produkt,
                    $exportEanSql AS ean,
                    (CASE WHEN oi.`count` IS NULL OR oi.`count` = 0 THEN 1 ELSE oi.`count` END) AS ks
                FROM order_items oi
                $exportEanJoins
                JOIN (
                    SELECT DISTINCT o.id_order
                    FROM orders o
                    $joinItems
                    $whereSql
                ) x ON x.id_order = oi.id_order
                WHERE oi.product_name IS NOT NULL
                  AND LOWER(oi.product_name) LIKE '%silvini%'
                ORDER BY oi.product_name ASC, ean ASC
            ";

            $stX = $pdo->prepare($sqlExport);
            $stX->execute($params);

            $rows = $stX->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $fileFrom = $ordersDateFromStr !== '' ? $ordersDateFromStr : 'from';
            $fileTo   = $ordersDateToStr !== '' ? $ordersDateToStr : 'to';
            $filename = 'silvini_' . $fileFrom . '_' . $fileTo . '.csv';
            $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $filename);
            header('Pragma: no-cache');
            header('Expires: 0');

            // UTF-8 BOM kvůli Excelu
            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new RuntimeException('Nelze otevřít výstup pro CSV.');
            }

            // CSV se středníkem (CZ Excel)
            fputcsv($out, ['Produkt', 'EAN', 'KS'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    (string)($r['produkt'] ?? ''),
                    (string)($r['ean'] ?? ''),
                    (string)($r['ks'] ?? ''),
                ], ';');
            }
            fclose($out);
            exit;
        } catch (Throwable $e) {
            // Pokud export selže, spadneme zpět na stránku s chybou
            $ordersError = 'Chyba při exportu SILVINI DAT: ' . $e->getMessage();
        }
    }

    // celkový počet pro stránkování
    try {
        $sqlCount = "SELECT COUNT(DISTINCT o.id_order) AS c
                     FROM orders o
                     $joinItems
                     $whereSql";
        $st = $pdo->prepare($sqlCount);
        $st->execute($params);
        $ordersTotal = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $ordersError = 'Chyba při načítání počtu objednávek: ' . $e->getMessage();
    }

    // data pro stránku
    if ($ordersError === '' && $ordersTotal > 0) {
        $offset = ($ordersPage - 1) * $ordersPerPage;

        try {
            $selectExtra = '';
            if ($ordersCurrencyCol !== '') { $selectExtra .= ", o.`{$ordersCurrencyCol}` AS currency_code"; }
            if ($ordersRateCol !== '')     { $selectExtra .= ", o.`{$ordersRateCol}` AS exchange_rate"; }
            if ($ordersInvUrlCol !== '')   { $selectExtra .= ", o.`{$ordersInvUrlCol}` AS invoice_url"; }
            if ($ordersInvHtmlCol !== '')  { $selectExtra .= ", o.`{$ordersInvHtmlCol}` AS invoice_url_html"; }

            $sql = "SELECT
                        o.id_order,
                        o.number,
                        o.created_at,
                        o.customer_name,
                        o.customer_email,
                        o.customer_phone,
                        o.total_price_with_vat,
                        o.zaplaceno,
                        o.gopay_zaplaceno,
                        o.payment_name,
                        o.delivery_name,
                        o.invoice_number
                        $selectExtra
                    FROM orders o
                    $joinItems
                    $whereSql
                    GROUP BY o.id_order
                    ORDER BY o.created_at DESC
                    LIMIT :limit OFFSET :offset";

            $st = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $st->bindValue($k, $v);
            }
            $st->bindValue(':limit', $ordersPerPage, PDO::PARAM_INT);
            $st->bindValue(':offset', $offset, PDO::PARAM_INT);
            $st->execute();
            $ordersRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $ordersError = 'Chyba při načítání objednávek: ' . $e->getMessage();
        }
    }

    // statistika e-mailů (kolik objednávek má daný e-mail)
    if ($ordersError === '') {
        try {
            $sqlE = "SELECT
                        LOWER(TRIM(customer_email)) AS email_key,
                        COUNT(*) AS cnt
                     FROM orders
                     WHERE customer_email IS NOT NULL
                       AND TRIM(customer_email) <> ''
                     GROUP BY email_key";
            $stE = $pdo->query($sqlE);
            while ($row = $stE->fetch(PDO::FETCH_ASSOC)) {
                $key = $row['email_key'] ?? '';
                if ($key === '') continue;
                $ordersEmailStats[$key] = (int)($row['cnt'] ?? 0);
            }
        } catch (Throwable $e) {
            // není kritické
        }
    }

    // položky objednávek
    if ($ordersError === '' && !empty($ordersRows)) {
        $orderIds = [];
        foreach ($ordersRows as $r) {
            if (isset($r['id_order'])) {
                $orderIds[] = (int)$r['id_order'];
            }
        }
        $orderIds = array_values(array_unique($orderIds));

        if (!empty($orderIds)) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            try {
                $sqlItems = "SELECT
                                id_order,
                                product_number,
                                product_name,
                                variant_description,
                                `count`,
                                price_total_with_vat,
                                `EAN` AS ean,
                                nakupni_cena AS price_s_dph
                             FROM order_items
                             WHERE id_order IN ($placeholders)
                             ORDER BY id_order, product_name";
                $stI = $pdo->prepare($sqlItems);
                $stI->execute($orderIds);

                while ($row = $stI->fetch(PDO::FETCH_ASSOC)) {
                    $oid = (int)($row['id_order'] ?? 0);
                    if ($oid <= 0) continue;

                    if (!isset($ordersItemsById[$oid])) {
                        $ordersItemsById[$oid] = [];
                    }
                    if (!isset($ordersPurchaseById[$oid])) {
                        $ordersPurchaseById[$oid] = 0.0;
                    }

                    $qty = 1.0;
                    if (isset($row['count']) && $row['count'] !== null) {
                        $q = (float)$row['count'];
                        if ($q > 0) $qty = $q;
                    }

                    $rawPurchase = $row['price_s_dph'] ?? null;
                    $priceSDph = ($rawPurchase !== null && $rawPurchase !== '' && (float)$rawPurchase > 0) ? (float)$rawPurchase : 0.0;
                    $missingPurchase = !($rawPurchase !== null && $rawPurchase !== '' && (float)$rawPurchase > 0);

                    if (!isset($ordersProfitById[$oid])) {
                        $ordersProfitById[$oid] = 0.0;
                    }
                    if (!isset($ordersMissingById[$oid])) {
                        $ordersMissingById[$oid] = false;
                    }

                    $lineTotal = isset($row['price_total_with_vat']) ? (float)$row['price_total_with_vat'] : 0.0;

                    if ($missingPurchase === false) {
                        $linePurchase = $priceSDph * $qty;
                        $ordersPurchaseById[$oid] += $linePurchase;
                        $ordersProfitById[$oid] += ($lineTotal - $linePurchase);
                    } else {
                        $ordersMissingById[$oid] = true;
                    }

                    $ordersItemsById[$oid][] = [
                        'code'        => (string)($row['product_number'] ?? ''),
                        'ean'         => (string)($row['ean'] ?? ''),
                        'name'        => (string)($row['product_name'] ?? ''),
                        'variant'     => (string)($row['variant_description'] ?? ''),
                        'qty'         => $qty,
                        'price_s_dph' => $priceSDph,
                        'missing_purchase' => $missingPurchase,
                        'total'   => $lineTotal,
                    ];
                }
            } catch (Throwable $e) {
                $ordersError = 'Chyba při načítání položek objednávek: ' . $e->getMessage();
            }
        }
    }

    // součet zisku pro vybrané období (nezávisle na stránce)
// počítá se jen z položek, které mají vyplněnou nákupní cenu
if ($ordersError === '') {
        try {
            $sqlProfit = "SELECT COALESCE(SUM(oi2.price_total_with_vat - (oi2.nakupni_cena * (CASE WHEN oi2.`count` IS NULL OR oi2.`count` = 0 THEN 1 ELSE oi2.`count` END))), 0) AS p
                          FROM order_items oi2
                          JOIN (
                              SELECT DISTINCT o.id_order
                              FROM orders o
                              $joinItems
                              $whereSql
                          ) x ON x.id_order = oi2.id_order
                          WHERE oi2.nakupni_cena IS NOT NULL AND oi2.nakupni_cena > 0";
            $stP = $pdo->prepare($sqlProfit);
            $stP->execute($params);
            $ordersProfitSum = (float)($stP->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            // necháme 0, aby se nerozbila stránka
            $ordersProfitSum = 0.0;
        }
    }
}

// ---------- SERVIS ----------
if ($loggedIn && $view === 'service') {
    $serviceView        = true;
    $serviceDateFromStr = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    $serviceDateToStr   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

    // výchozí – aktuální měsíc (1. den do dneška)
    $today = new DateTime('now', new DateTimeZone('Europe/Prague'));
    if ($serviceDateFromStr === '') {
        $first = clone $today;
        $first->modify('first day of this month');
        $serviceDateFromStr = $first->format('Y-m-d');
    }
    if ($serviceDateToStr === '') {
        $serviceDateToStr = $today->format('Y-m-d');
    }
    // Nákupní ceny SERVIS – čteme z /www/SERVIS/CenyServis.xlsx (sloupec BO)
    $servicePriceMap        = [];
    $servicePriceMapLoaded  = false;
    $servicePriceXlsxPath   = '';

    $servicePriceDir = __DIR__ . '/SERVIS';
    if (is_dir($servicePriceDir)) {
        $cands = [
            $servicePriceDir . '/CenyServis.xlsx',
            $servicePriceDir . '/CenyServis.XLSX',
        ];
        foreach ($cands as $c) {
            if (is_file($c)) { $servicePriceXlsxPath = $c; break; }
        }
        if ($servicePriceXlsxPath === '') {
            $g = glob($servicePriceDir . '/*.xlsx');
            if (!empty($g)) $servicePriceXlsxPath = $g[0];
        }
        if ($servicePriceXlsxPath !== '') {
            try {
                // Kód je ve sloupci B, nákupní cena bez DPH ve sloupci BO
                $servicePriceMap = xlsx_load_code_price_map($servicePriceXlsxPath, 'B', 'BO');
                $servicePriceMapLoaded = true;
            } catch (Throwable $e) {
                $servicePriceMap = [];
                $servicePriceMapLoaded = false;
            }
        }
    }



    $where  = "WHERE LOWER(o.customer_name) LIKE :cservis";
    $params = [
        ':cservis' => '%c-servis%',
    ];

    if ($serviceDateFromStr !== '') {
        $fromDt = DateTime::createFromFormat('Y-m-d', $serviceDateFromStr);
        if ($fromDt instanceof DateTime) {
            $where .= " AND o.created_at >= :from";
            $params[':from'] = $fromDt->format('Y-m-d 00:00:00');
        }
    }
    if ($serviceDateToStr !== '') {
        $toDt = DateTime::createFromFormat('Y-m-d', $serviceDateToStr);
        if ($toDt instanceof DateTime) {
            $where .= " AND o.created_at <= :to";
            $params[':to'] = $toDt->format('Y-m-d 23:59:59');
        }
    }

    // --- měna / kurz (pokud jsou sloupce v DB) ---
    $serviceCurrencyCol = '';
    $serviceRateCol     = '';
    try {
        $currencyCandidates = ['currency_code','currency','selected_currency','selected_currency_code','mena','currencyCode'];
        foreach ($currencyCandidates as $c) {
            if (db_has_column($pdo, 'orders', $c)) { $serviceCurrencyCol = $c; break; }
        }

        $rateCandidates = ['exchange_rate','exchangeRate','selected_currency_rate','currency_rate','rate','exchange_rate_value'];
        foreach ($rateCandidates as $c) {
            if (db_has_column($pdo, 'orders', $c)) { $serviceRateCol = $c; break; }
        }
    } catch (Throwable $e) {
        // necháme prázdné – částky se zobrazí jako CZK
    }

    $selectExtra = '';
    if ($serviceCurrencyCol !== '') { $selectExtra .= ", o.`{$serviceCurrencyCol}` AS currency_code"; }
    if ($serviceRateCol !== '')     { $selectExtra .= ", o.`{$serviceRateCol}` AS exchange_rate"; }

    try {
        $sql = "SELECT
                    o.id_order,
                    o.number,
                    o.created_at,
                    o.customer_name,
                    o.customer_email,
                    o.customer_phone,
                    o.total_price_with_vat,
                    o.zaplaceno,
                    o.gopay_zaplaceno,
                    o.payment_name,
                    o.delivery_name,
                    o.invoice_number
                    $selectExtra
                FROM orders o
                $where
                ORDER BY o.created_at DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $serviceRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $serviceError = 'Chyba při načítání servisních objednávek: ' . $e->getMessage();
    }

    if ($serviceError === '' && !empty($serviceRows)) {
        $orderIds = [];
        foreach ($serviceRows as $r) {
            // sčítání celkové ceny
            if (isset($r['total_price_with_vat']) && $r['total_price_with_vat'] !== null) {
                $serviceTotalSum += (float)$r['total_price_with_vat'];
            }
            if (isset($r['id_order'])) {
                $oidSrv = (int)$r['id_order'];
                $orderIds[] = $oidSrv;

                $invoiceNumberSrv = trim((string)($r['invoice_number'] ?? ''));
                $serviceOrderHasInvoice[$oidSrv] = ($invoiceNumberSrv !== '' && $invoiceNumberSrv !== '-');
            }
        }
        $orderIds = array_values(array_unique($orderIds));

        if (!empty($orderIds)) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            try {
                $sqlItems = "SELECT
                                id,
                                id_order,
                                product_number,
                                product_name,
                                variant_description,
                                `count`,
                                price_total_with_vat,
                                nakupni_cena
                             FROM order_items
                             WHERE id_order IN ($placeholders)
                             ORDER BY id_order, product_name";
                $stI = $pdo->prepare($sqlItems);
                $stI->execute($orderIds);

                while ($row = $stI->fetch(PDO::FETCH_ASSOC)) {
                    $oid = (int)($row['id_order'] ?? 0);
                    if ($oid <= 0) continue;

                    if (!isset($serviceItemsById[$oid])) {
                        $serviceItemsById[$oid] = [];
                    }

                    $qty = 1.0;
                    if (isset($row['count']) && $row['count'] !== null) {
                        $q = (float)$row['count'];
                        if ($q > 0) $qty = $q;
                    }

                    
                    $lineTotal = isset($row['price_total_with_vat']) ? (float)$row['price_total_with_vat'] : 0.0;

                    // Práce + servis – součet (práce mechanika / malý servis / velký servis / základní kontrola)
                    $hay = mb_strtolower(((string)($row['product_name'] ?? '')) . ' ' . ((string)($row['variant_description'] ?? '')), 'UTF-8');
                    $needles = ['práce mechanika','prace mechanika','malý servis','maly servis','velký servis','velky servis','základní kontrola','zakladni kontrola'];
                    $isWork = false;
                    foreach ($needles as $nd) {
                        if ($nd !== '' && mb_strpos($hay, $nd) !== false) { $isWork = true; break; }
                    }
                    if ($isWork) {
                        $serviceWorkCount += $qty;
                        $serviceWorkSum   += $lineTotal;
                    }

                    $itemIdSrv = (int)($row['id'] ?? 0);
                    $codeSrv   = trim((string)($row['product_number'] ?? ''));
                    $hasInvoiceSrv = !empty($serviceOrderHasInvoice[$oid]);

                    // Nákupní cena bez DPH:
                    // 1) ručně uložená v DB (order_items.nakupni_cena)
                    // 2) fallback z XLSX
                    // 3) pro práce/servis vždy 0
                    $purchaseSrv = null;
                    $missingSrvPrice = false;

                    if ($isWork) {
                        $purchaseSrv = 0.0;
                    } elseif (isset($row['nakupni_cena']) && $row['nakupni_cena'] !== null && $row['nakupni_cena'] !== '') {
                        $purchaseSrv = (float)$row['nakupni_cena'];
                    } elseif ($servicePriceMapLoaded) {
                        if ($codeSrv !== '' && isset($servicePriceMap[$codeSrv]) && (float)$servicePriceMap[$codeSrv] > 0) {
                            $purchaseSrv = (float)$servicePriceMap[$codeSrv];
                        } else {
                            $missingSrvPrice = true;
                        }
                    }

                    // Zisk na zboží = pouze zbožové položky, práce/servis se sem nezapočítávají
                    // Pokud je řádek označen (DPH), nákupní cena se do výpočtu zisku počítá s DPH (× 1,21).
                    if (!$isWork && $purchaseSrv !== null) {
                        $usePurchase = $purchaseSrv;
                        if (!empty($hasInvoiceSrv) && service_vat_is_marked($itemIdSrv)) {
                            $usePurchase = $purchaseSrv * 1.21;
                        }
                        $serviceGoodsProfitSum += ($lineTotal - ($usePurchase * $qty));
                    } elseif (!$isWork && $missingSrvPrice) {
                        $serviceGoodsProfitMissing++;
                    }

                    $serviceItemsById[$oid][] = [
                        'item_id' => $itemIdSrv,
                        'code'    => $codeSrv,
                        'name'    => (string)($row['product_name'] ?? ''),
                        'variant' => (string)($row['variant_description'] ?? ''),
                        'qty'     => $qty,
                        'total'   => $lineTotal,
                        'purchase'=> $purchaseSrv,
                        'has_invoice' => $hasInvoiceSrv,
                        'missing_purchase' => $missingSrvPrice,
                        'is_work' => $isWork,
                    ];
                }
            } catch (Throwable $e) {
                $serviceError = 'Chyba při načítání položek servisních objednávek: ' . $e->getMessage();
            }
        }
    }

    // Ručně přidané servisní řádky – držíme mimo objednávky z e-shopu, ale počítáme je do stejných součtů.
    $serviceManualRows = service_manual_rows_load();
    if (!empty($serviceManualRows)) {
        $fromLimit = null;
        $toLimit = null;
        if ($serviceDateFromStr !== '') {
            $tmpFrom = DateTime::createFromFormat('Y-m-d H:i:s', $serviceDateFromStr . ' 00:00:00');
            if ($tmpFrom instanceof DateTime) $fromLimit = $tmpFrom;
        }
        if ($serviceDateToStr !== '') {
            $tmpTo = DateTime::createFromFormat('Y-m-d H:i:s', $serviceDateToStr . ' 23:59:59');
            if ($tmpTo instanceof DateTime) $toLimit = $tmpTo;
        }

        foreach ($serviceManualRows as $mr) {
            $createdAtManual = trim((string)($mr['date'] ?? ''));
            if ($createdAtManual === '') $createdAtManual = date('Y-m-d H:i:s');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdAtManual)) {
                $createdAtManual .= ' 12:00:00';
            }

            try {
                $manualDt = new DateTime($createdAtManual);
            } catch (Throwable $e) {
                $manualDt = new DateTime('now', new DateTimeZone('Europe/Prague'));
                $createdAtManual = $manualDt->format('Y-m-d H:i:s');
            }

            if ($fromLimit instanceof DateTime && $manualDt < $fromLimit) continue;
            if ($toLimit instanceof DateTime && $manualDt > $toLimit) continue;

            $manualId = (int)($mr['id'] ?? 0);
            if ($manualId <= 0) continue;

            $idManualOrder = -1 * $manualId;
            $nameManual = trim((string)($mr['name'] ?? ''));
            if ($nameManual === '') $nameManual = 'Ruční servis';
            $orderNumberManual = trim((string)($mr['order_number'] ?? ''));
            if ($orderNumberManual === '') $orderNumberManual = 'RUČNÍ-' . $manualId;

            $productManual = trim((string)($mr['product'] ?? ''));
            if ($productManual === '') $productManual = 'Ruční položka';
            $variantManual = trim((string)($mr['variant'] ?? ''));
            $qtyManual = isset($mr['qty']) ? (float)$mr['qty'] : 1.0;
            if ($qtyManual <= 0) $qtyManual = 1.0;
            $lineTotalManual = isset($mr['line_total']) ? (float)$mr['line_total'] : 0.0;
            $purchaseManual = array_key_exists('purchase', $mr) ? $mr['purchase'] : null;
            if ($purchaseManual !== null) $purchaseManual = (float)$purchaseManual;
            $isWorkManual = service_is_work_item($productManual, $variantManual);
            $vatManual = !empty($mr['vat']);

            $serviceRows[] = [
                'id_order' => $idManualOrder,
                'number' => $orderNumberManual,
                'created_at' => $createdAtManual,
                'customer_name' => $nameManual,
                'customer_email' => '',
                'customer_phone' => '',
                'total_price_with_vat' => $lineTotalManual,
                'zaplaceno' => '',
                'gopay_zaplaceno' => '',
                'payment_name' => 'Ruční řádek',
                'delivery_name' => '',
                'invoice_number' => 'RUČNÍ',
                'is_manual' => 1,
            ];

            $serviceOrderHasInvoice[$idManualOrder] = true;
            $serviceTotalSum += $lineTotalManual;

            if ($isWorkManual) {
                $serviceWorkCount += $qtyManual;
                $serviceWorkSum += $lineTotalManual;
            } elseif ($purchaseManual !== null) {
                $usePurchaseManual = $vatManual ? ($purchaseManual * 1.21) : $purchaseManual;
                $serviceGoodsProfitSum += ($lineTotalManual - ($usePurchaseManual * $qtyManual));
            } else {
                $serviceGoodsProfitMissing++;
            }

            $serviceItemsById[$idManualOrder] = [[
                'item_id' => 0,
                'manual_id' => $manualId,
                'is_manual' => true,
                'manual_vat' => $vatManual,
                'code' => trim((string)($mr['code'] ?? '')),
                'name' => $productManual,
                'variant' => $variantManual,
                'qty' => $qtyManual,
                'total' => $lineTotalManual,
                'purchase' => $purchaseManual,
                'has_invoice' => true,
                'missing_purchase' => (!$isWorkManual && $purchaseManual === null),
                'is_work' => $isWorkManual,
            ]];
        }

        if (!empty($serviceRows)) {
            usort($serviceRows, function($a, $b) {
                return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            });
        }
    }

    // --- EXPORT SERVIS (C-servis) – CSV / Excel ---
    // Exportuje každou položku na samostatný řádek a opakuje u ní datum, jméno a údaje objednávky.
    $serviceExport = isset($_GET['export']) ? (string)$_GET['export'] : '';
    if ($serviceExport === 'service_csv' || $serviceExport === 'service_excel') {
        try {
            $headers = [
                'Datum',
                'Jméno',
                'E-mail',
                'Telefon',
                'Číslo objednávky',
                'Číslo faktury',
                'Celkem objednávka s DPH',
                'Měna',
                'Kurz',
                'Platba',
                'Doprava',
                'Kód',
                'Produkt',
                'Varianta',
                'Ks',
                'Řádek s DPH',
                'Nákupní cena bez DPH',
                'DPH označeno',
                'Nákupní cena pro výpočet',
                'Zisk řádku',
                'Typ položky',
                'Ruční řádek',
            ];

            $numOut = static function($value): string {
                if ($value === null || $value === '') return '';
                if (!is_numeric($value)) return (string)$value;
                return number_format((float)$value, 2, ',', '');
            };

            $exportRows = [];
            foreach ($serviceRows as $row) {
                $idOrder = (int)($row['id_order'] ?? 0);

                $createdDisplay = '';
                if (!empty($row['created_at'])) {
                    try {
                        $dt = new DateTime((string)$row['created_at']);
                        $createdDisplay = $dt->format('d.m.Y H:i');
                    } catch (Throwable $e) {
                        $createdDisplay = (string)$row['created_at'];
                    }
                }

                $currencyCode = 'CZK';
                if (isset($row['currency_code']) && trim((string)$row['currency_code']) !== '') {
                    $currencyCode = strtoupper(trim((string)$row['currency_code']));
                }
                $exchangeRate = isset($row['exchange_rate']) ? (float)$row['exchange_rate'] : 0.0;

                $items = $serviceItemsById[$idOrder] ?? [];
                if (empty($items)) {
                    $items = [[
                        'item_id' => 0,
                        'code' => '',
                        'name' => '',
                        'variant' => '',
                        'qty' => '',
                        'total' => '',
                        'purchase' => null,
                        'has_invoice' => !empty($serviceOrderHasInvoice[$idOrder]),
                        'missing_purchase' => false,
                        'is_work' => false,
                    ]];
                }

                foreach ($items as $item) {
                    $qty = (isset($item['qty']) && $item['qty'] !== '') ? (float)$item['qty'] : null;
                    $lineTotal = (isset($item['total']) && $item['total'] !== '') ? (float)$item['total'] : null;
                    $purchase = array_key_exists('purchase', $item) && $item['purchase'] !== null && $item['purchase'] !== '' ? (float)$item['purchase'] : null;
                    $isWork = !empty($item['is_work']);
                    $isManual = !empty($item['is_manual']) || !empty($row['is_manual']);

                    $vatMarked = false;
                    if ($isManual) {
                        $vatMarked = !empty($item['manual_vat']);
                    } else {
                        $itemId = (int)($item['item_id'] ?? 0);
                        $vatMarked = $itemId > 0 && !empty($item['has_invoice']) && service_vat_is_marked($itemId);
                    }

                    $purchaseForCalc = null;
                    $profit = null;
                    if ($isWork) {
                        $purchaseForCalc = 0.0;
                        $profit = $lineTotal;
                    } elseif ($purchase !== null) {
                        $purchaseForCalc = $vatMarked ? ($purchase * 1.21) : $purchase;
                        if ($lineTotal !== null && $qty !== null) {
                            $profit = $lineTotal - ($purchaseForCalc * $qty);
                        }
                    }

                    $exportRows[] = [
                        'Datum' => $createdDisplay,
                        'Jméno' => (string)($row['customer_name'] ?? ''),
                        'E-mail' => (string)($row['customer_email'] ?? ''),
                        'Telefon' => (string)($row['customer_phone'] ?? ''),
                        'Číslo objednávky' => (string)($row['number'] ?? ''),
                        'Číslo faktury' => (string)($row['invoice_number'] ?? ''),
                        'Celkem objednávka s DPH' => $numOut($row['total_price_with_vat'] ?? ''),
                        'Měna' => $currencyCode,
                        'Kurz' => $exchangeRate > 0 ? $numOut($exchangeRate) : '',
                        'Platba' => (string)($row['payment_name'] ?? ''),
                        'Doprava' => (string)($row['delivery_name'] ?? ''),
                        'Kód' => (string)($item['code'] ?? ''),
                        'Produkt' => (string)($item['name'] ?? ''),
                        'Varianta' => (string)($item['variant'] ?? ''),
                        'Ks' => $qty !== null ? $numOut($qty) : '',
                        'Řádek s DPH' => $lineTotal !== null ? $numOut($lineTotal) : '',
                        'Nákupní cena bez DPH' => $purchase !== null ? $numOut($purchase) : '',
                        'DPH označeno' => $vatMarked ? 'ANO' : 'NE',
                        'Nákupní cena pro výpočet' => $purchaseForCalc !== null ? $numOut($purchaseForCalc) : '',
                        'Zisk řádku' => $profit !== null ? $numOut($profit) : '',
                        'Typ položky' => $isWork ? 'Práce/servis' : 'Zboží',
                        'Ruční řádek' => $isManual ? 'ANO' : 'NE',
                    ];
                }
            }

            $fileFrom = $serviceDateFromStr !== '' ? $serviceDateFromStr : 'od';
            $fileTo   = $serviceDateToStr !== '' ? $serviceDateToStr : 'do';
            $baseName = 'servis_c-servis_' . $fileFrom . '_' . $fileTo;
            $baseName = preg_replace('/[^A-Za-z0-9._-]/', '_', $baseName);

            if ($serviceExport === 'service_csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
                header('Pragma: no-cache');
                header('Expires: 0');

                echo chr(0xEF) . chr(0xBB) . chr(0xBF);
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    throw new RuntimeException('Nelze otevřít výstup pro CSV.');
                }
                fputcsv($out, $headers, ';');
                foreach ($exportRows as $er) {
                    $line = [];
                    foreach ($headers as $hName) {
                        $line[] = (string)($er[$hName] ?? '');
                    }
                    fputcsv($out, $line, ';');
                }
                fclose($out);
                exit;
            }

            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $baseName . '.xls"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $xml = static function($value): string {
                return htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            };

            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
            echo '<Worksheet ss:Name="Servis C-servis"><Table>' . "\n";
            echo '<Row>';
            foreach ($headers as $hName) {
                echo '<Cell><Data ss:Type="String">' . $xml($hName) . '</Data></Cell>';
            }
            echo '</Row>' . "\n";
            foreach ($exportRows as $er) {
                echo '<Row>';
                foreach ($headers as $hName) {
                    echo '<Cell><Data ss:Type="String">' . $xml($er[$hName] ?? '') . '</Data></Cell>';
                }
                echo '</Row>' . "\n";
            }
            echo '</Table></Worksheet></Workbook>';
            exit;
        } catch (Throwable $e) {
            $serviceError = 'Chyba při exportu SERVIS (C-servis): ' . $e->getMessage();
        }
    }
}

// ---------- STATISTIKA ----------
if ($loggedIn && $view === 'stats') {
    $showStats   = true;
    $dateFromStr = isset($_GET['from']) ? trim($_GET['from']) : '';
    $dateToStr   = isset($_GET['to'])   ? trim($_GET['to'])   : '';
    $brand       = isset($_GET['brand']) ? trim($_GET['brand']) : 'all';
    $statsSearch = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $statsPage    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $statsPerPage = 100;

    // výchozí období – aktuální měsíc (1. den do dneška), pokud uživatel nic nezadal
    if ($dateFromStr === '' && $dateToStr === '') {
        try {
            $today = new DateTime('now', new DateTimeZone('Europe/Prague'));
            $first = clone $today;
            $first->modify('first day of this month');
            $dateFromStr = $first->format('Y-m-d');
            $dateToStr   = $today->format('Y-m-d');
        } catch (Exception $e) {
            // necháme prázdné
        }
    }


    $mode = isset($_GET['mode']) ? trim($_GET['mode']) : 'stat';
    if ($mode !== 'nocolor') {
        $mode = 'stat';
    }

    $fromDate = $dateFromStr !== '' ? parseSheetDate($dateFromStr) : null;
    $toDate   = $dateToStr   !== '' ? parseSheetDate($dateToStr)   : null;

    $effectiveBrand = $brand;
    $rows = loadCsvRows(STATS_SHEET_CSV_URL);

    if ($rows === false) {
        $statsError = 'Nepodařilo se načíst data pro statistiku.';
    } else {
        foreach ($rows as $i => $cols) {
            if ($i === 0) continue;

            $rowDate = null;
            if (isset($cols[STATS_DATE_COL_INDEX])) {
                $rowDate = parseSheetDate((string)$cols[STATS_DATE_COL_INDEX]);
            }

            if (($fromDate || $toDate) && !$rowDate) continue;
            if ($fromDate && $rowDate && $rowDate < $fromDate) continue;
            if ($toDate && $rowDate && $rowDate > $toDate)   continue;

            $name = isset($cols[STATS_NAME_COL_INDEX]) ? trim((string)$cols[STATS_NAME_COL_INDEX]) : '';
            $nameLower = mb_strtolower($name, 'UTF-8');
            $rawCode = isset($cols[STATS_CODE_COL_INDEX]) ? trim((string)$cols[STATS_CODE_COL_INDEX]) : '';
            $aaVal = isset($cols[STATS_EXTRA_COL_INDEX]) ? trim((string)$cols[STATS_EXTRA_COL_INDEX]) : '';

            // Vyhledávání se musí projevit už na úrovni řádků, aby seděl i součet Kč a graf.
            // Hledáme ve všech sloupcích řádku (kód, název, detail, objednávka atd.).
            if ($statsSearch !== '') {
                $qNeedle = mb_strtolower($statsSearch, 'UTF-8');
                $hayParts = [];
                foreach ($cols as $cVal) {
                    $hayParts[] = (string)$cVal;
                }
                $haystack = mb_strtolower(implode(' ', $hayParts), 'UTF-8');
                if ($qNeedle !== '' && mb_strpos($haystack, $qNeedle) === false) {
                    continue;
                }
            }

            $price = 0.0;
            if (isset($cols[STATS_PRICE_COL_INDEX])) {
                $raw = trim((string)$cols[STATS_PRICE_COL_INDEX]);
                if ($raw !== '') {
                    $num = str_replace([' ', 'Kč'], '', $raw);
                    $num = str_replace(',', '.', $num);
                    if (is_numeric($num)) {
                        $price = (float)$num;
                    }
                }
            }

            $brandKeyForChart = detect_brand_key($nameLower);
            if (!isset($brandSums[$brandKeyForChart])) {
                $brandSums[$brandKeyForChart] = 0.0;
            }
            $brandSums[$brandKeyForChart] += $price;

            if (!match_brand_filter($effectiveBrand, $nameLower)) {
                continue;
            }

            if (isset($cols[STATS_AD_COL_INDEX])) {
                $rawAd = trim((string)$cols[STATS_AD_COL_INDEX]);
                if ($rawAd !== '') {
                    $numAd = str_replace([' ', 'Kč'], '', $rawAd);
                    $numAd = str_replace(',', '.', $numAd);
                    if (is_numeric($numAd)) {
                        $statsTotalAD += (float)$numAd;
                    }
                }
            }

            if ($rawCode === '') continue;

            $code = ($mode === 'nocolor') ? explode('-', $rawCode)[0] : $rawCode;
            if ($code === '') continue;

            if (!isset($statsResult[$code])) {
                $statsResult[$code] = [
                    'name'  => $name,
                    'count' => 0,
                    'aa'    => [],
                    'sum'   => 0.0,
                ];
            } else {
                if ($statsResult[$code]['name'] === '' && $name !== '') {
                    $statsResult[$code]['name'] = $name;
                }
            }

            $statsResult[$code]['count']++;
            $statsResult[$code]['sum'] += $price;

            if ($aaVal !== '') {
                if (!isset($statsResult[$code]['aa'][$aaVal])) {
                    $statsResult[$code]['aa'][$aaVal] = 0;
                }
                $statsResult[$code]['aa'][$aaVal]++;
            }
        }

        if (!empty($statsResult)) {
            uasort($statsResult, function ($a, $b) {
                if ($a['count'] === $b['count']) return 0;
                return ($a['count'] < $b['count']) ? 1 : -1;
            });
        }

        // Vyhledávání je aplikované už ve smyčce po řádcích, aby součty a graf počítaly stejný filtr.

        // stránkování výsledků (100 produktů na stránku)
        $statsTotalItems = count($statsResult);
        $statsTotalPages = (int)ceil($statsTotalItems / $statsPerPage);
        if ($statsTotalPages < 1) $statsTotalPages = 1;
        if ($statsPage > $statsTotalPages) $statsPage = $statsTotalPages;
        $statsOffset = ($statsPage - 1) * $statsPerPage;
        $statsResultPage = array_slice($statsResult, $statsOffset, $statsPerPage, true);

    }
}

// ---------- ZÁKAZNÍCI ----------
if ($loggedIn && $view === 'customers') {
    $customersShow        = true;
    $customersDateFromStr = isset($_GET['from']) ? trim($_GET['from']) : '';
    $customersDateToStr   = isset($_GET['to'])   ? trim($_GET['to'])   : '';
    $customersPage    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $customersPerPage = 100;

    // výchozí období – aktuální měsíc (1. den do dneška), pokud uživatel nic nezadal
    if ($customersDateFromStr === '' && $customersDateToStr === '') {
        try {
            $today = new DateTime('now', new DateTimeZone('Europe/Prague'));
            $first = clone $today;
            $first->modify('first day of this month');
            $customersDateFromStr = $first->format('Y-m-d');
            $customersDateToStr   = $today->format('Y-m-d');
        } catch (Exception $e) {
            // necháme prázdné
        }
    }

    $customersSort        = isset($_GET['sort']) ? trim($_GET['sort']) : 'orders';
    if ($customersSort !== 'value') {
        $customersSort = 'orders';
    }

    $orderRows = [];
    try {
        $sql = "SELECT
                    id_order,
                    number,
                    created_at,
                    customer_name,
                    customer_email,
                    customer_phone,
                    total_price_with_vat
                FROM orders
                WHERE 1=1";

        $params = [];

        if ($customersDateFromStr !== '') {
            $fromDt = DateTime::createFromFormat('Y-m-d', $customersDateFromStr);
            if ($fromDt instanceof DateTime) {
                $sql .= " AND created_at >= :from";
                $params[':from'] = $fromDt->format('Y-m-d 00:00:00');
            }
        }

        if ($customersDateToStr !== '') {
            $toDt = DateTime::createFromFormat('Y-m-d', $customersDateToStr);
            if ($toDt instanceof DateTime) {
                $sql .= " AND created_at <= :to";
                $params[':to'] = $toDt->format('Y-m-d 23:59:59');
            }
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orderRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $customersError = 'Chyba při načítání objednávek z databáze: ' . $e->getMessage();
    }

    if ($customersError === '' && !empty($orderRows)) {
        $customersAgg = [];

        foreach ($orderRows as $row) {
            $name  = trim((string)($row['customer_name'] ?? ''));
            $email = trim((string)($row['customer_email'] ?? ''));
            $phone = trim((string)($row['customer_phone'] ?? ''));
            $orderNumber = trim((string)($row['number'] ?? ''));

            if ($name === '' || $orderNumber === '') {
                continue;
            }

            $phoneKey = $phone !== '' ? preg_replace('/\s+/', '', $phone) : '';

            $orderDateDisplay = '';
            if (!empty($row['created_at'])) {
                try {
                    $dt = new DateTime($row['created_at']);
                    $orderDateDisplay = $dt->format('Y-m-d');
                } catch (Exception $e) {
                    $orderDateDisplay = (string)$row['created_at'];
                }
            }

            $totalPrice = 0.0;
            if (isset($row['total_price_with_vat']) && $row['total_price_with_vat'] !== null) {
                $totalPrice = (float)$row['total_price_with_vat'];
            }

            $key = mb_strtolower($name, 'UTF-8') . '|' .
                   $phoneKey . '|' .
                   mb_strtolower($email, 'UTF-8');

            if (!isset($customersAgg[$key])) {
                $customersAgg[$key] = [
                    'name'         => $name,
                    'phone'        => $phone,
                    'email'        => $email,
                    'orders_count' => 0,
                    'orders_total' => 0.0,
                    'orders'       => [],
                ];
            } else {
                if ($customersAgg[$key]['phone'] === '' && $phone !== '') {
                    $customersAgg[$key]['phone'] = $phone;
                }
                if ($customersAgg[$key]['email'] === '' && $email !== '') {
                    $customersAgg[$key]['email'] = $email;
                }
            }

            if (!isset($customersAgg[$key]['orders'][$orderNumber])) {
                $customersAgg[$key]['orders'][$orderNumber] = [
                    'number'   => $orderNumber,
                    'date'     => $orderDateDisplay,
                    'total'    => $totalPrice,
                    'id_order' => (int)($row['id_order'] ?? 0),
                ];
                $customersAgg[$key]['orders_count']++;
                $customersAgg[$key]['orders_total'] += $totalPrice;
            }
        }

        if (!empty($customersAgg)) {
            $customersData = array_values($customersAgg);

            usort($customersData, function($a, $b) use ($customersSort) {
                if ($customersSort === 'value') {
                    if ($a['orders_total'] == $b['orders_total']) {
                        if ($a['orders_count'] === $b['orders_count']) return 0;
                        return ($a['orders_count'] < $b['orders_count']) ? 1 : -1;
                    }
                    return ($a['orders_total'] < $b['orders_total']) ? 1 : -1;
                } else {
                    if ($a['orders_count'] === $b['orders_count']) {
                        if ($a['orders_total'] == $b['orders_total']) return 0;
                        return ($a['orders_total'] < $b['orders_total']) ? 1 : -1;
                    }
                    return ($a['orders_count'] < $b['orders_count']) ? 1 : -1;
                }
            });
        }
    }

    if ($customersError === '' && !empty($customersData)) {
        try {
            $orderNumbers = [];
            foreach ($customersData as $cust) {
                if (!empty($cust['orders'])) {
                    foreach ($cust['orders'] as $ord) {
                        $num = trim((string)($ord['number'] ?? ''));
                        if ($num !== '') {
                            $orderNumbers[$num] = true;
                        }
                    }
                }
            }

            $orderNumbers = array_keys($orderNumbers);

            if (!empty($orderNumbers)) {
                $placeholders = implode(',', array_fill(0, count($orderNumbers), '?'));
                $sqlItems = "SELECT
                        oi.id_order,
                        oi.product_number,
                        oi.product_name,
                        oi.variant_description,
                        oi.`count`,
                        o.number AS order_number
                    FROM order_items oi
                    JOIN orders o ON o.id_order = oi.id_order
                    WHERE o.number IN ($placeholders)
                    ORDER BY o.number, oi.id_order, oi.product_name
                ";

                $stmtItems = $pdo->prepare($sqlItems);
                $stmtItems->execute(array_values($orderNumbers));

                while ($row = $stmtItems->fetch(PDO::FETCH_ASSOC)) {
                    $ordNum = trim((string)($row['order_number'] ?? ''));
                    if ($ordNum === '') {
                        continue;
                    }

                    if (!isset($orderItemsByOrderNumber[$ordNum])) {
                        $orderItemsByOrderNumber[$ordNum] = [];
                    }

                    $qty = 1.0;
                    if (isset($row['count']) && $row['count'] !== null) {
                        $qnum = (float)$row['count'];
                        if ($qnum > 0) {
                            $qty = $qnum;
                        }
                    }

                    $orderItemsByOrderNumber[$ordNum][] = [
                        'code'    => (string)($row['product_number'] ?? ''),
                        'name'    => (string)($row['product_name'] ?? ''),
                        'variant' => (string)($row['variant_description'] ?? ''),
                        'qty'     => $qty,
                    ];
                }

                if (!empty($orderItemsByOrderNumber)) {
                    $orderItemsAvailable = true;
                } else {
                    $orderItemsError = 'Položky objednávek nejsou k dispozici (tabulka order_items je prázdná pro vybrané objednávky).';
                }
            }
        } catch (Throwable $e) {
            $orderItemsError = 'Chyba při načítání položek objednávek: ' . $e->getMessage();
        }
    }
    // stránkování zákazníků (100 na stránku)
    $customersTotalItems = count($customersData);
    $customersTotalPages = (int)ceil($customersTotalItems / $customersPerPage);
    if ($customersTotalPages < 1) $customersTotalPages = 1;
    if ($customersPage > $customersTotalPages) $customersPage = $customersTotalPages;
    $customersOffset = ($customersPage - 1) * $customersPerPage;
    $customersDataPage = array_slice($customersData, $customersOffset, $customersPerPage);

}

// ---------- VRÁCENÉ ZBOŽÍ ----------
if ($loggedIn && $view === 'returns') {
    $returnsShow        = true;
    $returnsDateFromStr = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    $returnsDateToStr   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';
    $returnsSearchQuery = isset($_GET['q'])    ? trim((string)$_GET['q'])    : '';
    $returnsTab         = isset($_GET['tab'])  ? trim((string)$_GET['tab'])  : 'list';
    if (!in_array($returnsTab, ['list','products'], true)) $returnsTab = 'list';

    if ($returnsDateFromStr === '' && $returnsDateToStr === '') {
        try {
            $today = new DateTime('now', new DateTimeZone('Europe/Prague'));
            $first = clone $today;
            $first->modify('first day of this month');
            $returnsDateFromStr = $first->format('Y-m-d');
            $returnsDateToStr   = $today->format('Y-m-d');
        } catch (Exception $e) {}
    }

    try {
        returns_ensure_schema($pdo);
        $metaAll = returns_meta_load();
        $qNorm = mb_strtolower($returnsSearchQuery, 'UTF-8');
        $matchesSearch = function(array $r) use ($qNorm): bool {
            if ($qNorm === '') return true;
            $hay = implode(' ', array_map(static fn($v) => (string)$v, $r));
            return mb_stripos($hay, $qNorm, 0, 'UTF-8') !== false;
        };

        $where = [];
        $params = [];
        if ($returnsDateFromStr !== '') { $where[] = 'DATE(rr.created_at) >= :from'; $params[':from'] = $returnsDateFromStr; }
        if ($returnsDateToStr !== '') { $where[] = 'DATE(rr.created_at) <= :to'; $params[':to'] = $returnsDateToStr; }
        $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "
            SELECT
                rr.id, rr.order_number, rr.id_order, rr.status, rr.customer_name AS rr_customer_name,
                rr.customer_email, rr.bank_account, rr.note, rr.total_return_with_vat, rr.pdf_path,
                rr.created_at, rr.updated_at,
                COALESCE(NULLIF(rr.customer_name,''), o.customer_name, '') AS customer_name,
                COALESCE(o.invoice_number, '') AS invoice_number_view,
                COALESCE(o.invoice_variable_symbol, '') AS variable_symbol_view,
                GROUP_CONCAT(CONCAT_WS(' ', ri.product_number, ri.product_name, ri.variant_description, ri.ean) SEPARATOR ' | ') AS item_summary,
                SUM(CASE WHEN COALESCE(ri.product_number,'') <> 'DOPRAVNE' THEN COALESCE(ri.return_qty,0) ELSE 0 END) AS total_qty
            FROM returns_requests rr
            LEFT JOIN orders o ON (o.id_order = rr.id_order OR (rr.id_order IS NULL AND o.number = rr.order_number))
            LEFT JOIN returns_items ri ON ri.request_id = rr.id
            $whereSql
            GROUP BY rr.id
            ORDER BY rr.created_at DESC, rr.id DESC
            LIMIT 700
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $allRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($allRows as $r) {
            $id = (int)($r['id'] ?? 0);
            $m = (isset($metaAll[(string)$id]) && is_array($metaAll[(string)$id])) ? $metaAll[(string)$id] : [];
            $r['return_number'] = trim((string)($m['return_number'] ?? ''));
            if ($r['return_number'] === '') $r['return_number'] = 'VR' . str_pad((string)(26000 + $id), 5, '0', STR_PAD_LEFT);
            if (!empty($m['invoice_number'])) $r['invoice_number_view'] = (string)$m['invoice_number'];
            if (!empty($m['variable_symbol'])) $r['variable_symbol_view'] = (string)$m['variable_symbol'];
            $r['credit_note_number'] = (string)($m['credit_note_number'] ?? '');
            $r['status'] = (string)($m['status'] ?? ($r['status'] ?? 'NEW'));
            $r['return_shipping_method'] = (string)($m['return_shipping_method'] ?? '');
            $r['shipping_deduction_with_vat'] = (float)($m['shipping_deduction_with_vat'] ?? 0);
            if (!$matchesSearch($r)) continue;
            $qty = (float)($r['total_qty'] ?? 0);
            $price = (float)($r['total_return_with_vat'] ?? 0);
            $returnsTotalQty += $qty;
            $returnsTotalPrice += $price;
            $statusForBoard = (string)($r['status'] ?? '');
            if ($statusForBoard === 'TO_REFUND') $returnsToRefundRows[] = $r;
            elseif ($statusForBoard === 'REFUNDED') $returnsPaidRows[] = $r;
            else $returnsRows[] = $r;
        }

        $visibleReturnIds = [];
        foreach (array_merge($returnsRows, $returnsToRefundRows, $returnsPaidRows) as $rrVis) {
            $ridVis = (int)($rrVis['id'] ?? 0);
            if ($ridVis > 0) $visibleReturnIds[] = $ridVis;
        }
        $visibleReturnIds = array_values(array_unique($visibleReturnIds));
        if (!empty($visibleReturnIds)) {
            $in = implode(',', array_fill(0, count($visibleReturnIds), '?'));
            $stItems = $pdo->prepare("\n                SELECT id, request_id, product_number, product_name, variant_description, ean,\n                       return_qty, price_per_unit_with_vat, line_total_with_vat\n                FROM returns_items\n                WHERE request_id IN ($in)\n                ORDER BY request_id ASC, id ASC\n            ");
            $stItems->execute($visibleReturnIds);
            foreach (($stItems->fetchAll(PDO::FETCH_ASSOC) ?: []) as $itRow) {
                $rid = (int)($itRow['request_id'] ?? 0);
                if ($rid <= 0) continue;
                if (!isset($returnsItemsByRequest[$rid])) $returnsItemsByRequest[$rid] = [];
                $returnsItemsByRequest[$rid][] = $itRow;
            }
        }

        $prodWhere = [];
        $prodParams = [];
        if ($returnsDateFromStr !== '') { $prodWhere[] = 'DATE(rr.created_at) >= :pfrom'; $prodParams[':pfrom'] = $returnsDateFromStr; }
        if ($returnsDateToStr !== '') { $prodWhere[] = 'DATE(rr.created_at) <= :pto'; $prodParams[':pto'] = $returnsDateToStr; }
        $prodWhere[] = "COALESCE(ri.product_number,'') <> 'DOPRAVNE'";
        $prodWhereSql = 'WHERE ' . implode(' AND ', $prodWhere);

        $sqlP = "
            SELECT
                rr.id AS request_id, rr.order_number, rr.created_at,
                COALESCE(NULLIF(rr.customer_name,''), o.customer_name, '') AS customer_name,
                COALESCE(o.invoice_number, '') AS invoice_number,
                COALESCE(o.invoice_variable_symbol, '') AS variable_symbol,
                rr.note,
                ri.product_number, ri.product_name, ri.variant_description, ri.ean,
                ri.return_qty, ri.price_per_unit_with_vat, ri.line_total_with_vat,
                COALESCE(rr.note, '') AS reason
            FROM returns_items ri
            JOIN returns_requests rr ON rr.id = ri.request_id
            LEFT JOIN orders o ON (o.id_order = rr.id_order OR (rr.id_order IS NULL AND o.number = rr.order_number))
            $prodWhereSql
            ORDER BY rr.created_at DESC, rr.id DESC, ri.id ASC
            LIMIT 1500
        ";
        $stP = $pdo->prepare($sqlP);
        $stP->execute($prodParams);
        foreach (($stP->fetchAll(PDO::FETCH_ASSOC) ?: []) as $pr) {
            $id = (int)($pr['request_id'] ?? 0);
            $m = (isset($metaAll[(string)$id]) && is_array($metaAll[(string)$id])) ? $metaAll[(string)$id] : [];
            $pr['return_number'] = trim((string)($m['return_number'] ?? ''));
            if ($pr['return_number'] === '') $pr['return_number'] = 'VR' . str_pad((string)(26000 + $id), 5, '0', STR_PAD_LEFT);
            if (!empty($m['invoice_number'])) $pr['invoice_number'] = (string)$m['invoice_number'];
            if (!empty($m['variable_symbol'])) $pr['variable_symbol'] = (string)$m['variable_symbol'];
            if (!$matchesSearch($pr)) continue;
            $returnsProductsRows[] = $pr;
        }

        $stat = [];
        foreach ($returnsProductsRows as $pr) {
            $name = trim((string)($pr['product_name'] ?? '') . ' ' . (string)($pr['variant_description'] ?? ''));
            if ($name === '') $name = trim((string)($pr['product_number'] ?? ''));
            if ($name === '') continue;
            if (!isset($stat[$name])) $stat[$name] = 0.0;
            $stat[$name] += (float)($pr['return_qty'] ?? 0);
        }
        foreach ($stat as $name => $qty) $returnsTopStats[] = ['name'=>$name, 'qty'=>$qty];
        usort($returnsTopStats, function($a,$b){ return ($b['qty'] <=> $a['qty']); });
        $returnsTopStats = array_slice($returnsTopStats, 0, 10);

    } catch (Throwable $e) {
        $returnsError = 'Nepodařilo se načíst modul Vrácené zboží: ' . $e->getMessage();
    }
}

$brandOptions = [
    'all'        => 'Vše',
    'craft'      => 'Craft',
    'silvini'    => 'SILVINI',
    'devold'     => 'Devold',
    'ale'        => 'Alé',
    'didriksons' => 'Didriksons (D1913)',
    'haglofs'    => 'HAGLÖFS',
    'viking'     => 'Viking',
    'isadore'    => 'Isadore',
    'neon'       => 'Neon',
    'lillsport'  => 'LILL-SPORT',
    'inov8'      => 'INOV-8',
    'silva'      => 'SILVA',
    'karitraa'   => 'KARI TRAA',
];

// ----- MĚSÍČNÍ PŘEHLED -----
// Heslo pro vstup do modulu se řeší přes sdílené secrets/admin_login.php
// (stejný mechanismus jako hlavní login, viz _auth_guard.php).

// Přihlášení do modulu (uloží do session)
if ($loggedIn && isset($_POST['action']) && $_POST['action'] === 'monthly_login') {
    $pw = isset($_POST['monthly_pass']) ? (string)$_POST['monthly_pass'] : '';
    if (cfloat_auth_verify_and_migrate($__cfloatAuthData, 'monthly_pass_hash', 'monthly_pass_bootstrap', $pw, $__cfloatAuthFile)) {
        $_SESSION['monthly_ok'] = true;
        header('Location: index.php?view=monthly');
        exit;
    } else {
        $_SESSION['monthly_ok'] = false;
        $_SESSION['monthly_error'] = 'Neplatné heslo.';
        header('Location: index.php?view=monthly');
        exit;
    }
}

// AJAX – okamžité ukládání nákladů
if ($loggedIn && isset($_POST['action']) && in_array($_POST['action'], ['monthly_cost_create','monthly_cost_update','monthly_cost_delete'], true)) {
    header('Content-Type: application/json; charset=utf-8');

    // musí být přihlášen do modulu
    if (empty($_SESSION['monthly_ok'])) {
        echo json_encode(['ok' => false, 'error' => 'Neautorizováno (měsíční přehled).']);
        exit;
    }

    // tabulka nákladů – musí existovat (na hostingu nemusí mít DB uživatel právo CREATE)
    $monthlyCreateSql = <<<SQLMONTHLY
CREATE TABLE monthly_costs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  start_year SMALLINT NOT NULL,
  start_month TINYINT NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  carry TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_start (start_year, start_month),
  KEY idx_carry (carry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQLMONTHLY;
    try {
        $pdo->query("SELECT 1 FROM monthly_costs LIMIT 1");
    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'error' => 'Tabulka monthly_costs neexistuje nebo k ní nemáš přístup. Vytvoř ji ručně v phpMyAdmin (nebo dej DB uživateli právo CREATE).',
            'sql' => $monthlyCreateSql
        ]);
        exit;
    }
$action = (string)$_POST['action'];

    // helpery
    $y = isset($_POST['y']) ? (int)$_POST['y'] : 0;
    $m = isset($_POST['m']) ? (int)$_POST['m'] : 0;

    if ($action === 'monthly_cost_create') {
        if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) {
            echo json_encode(['ok' => false, 'error' => 'Neplatný rok/měsíc.']);
            exit;
        }
        try {
            $st = $pdo->prepare("INSERT INTO monthly_costs (start_year, start_month, description, amount, carry) VALUES (:y,:m,'',0,0)");
            $st->execute([':y' => $y, ':m' => $m]);
            $id = (int)$pdo->lastInsertId();
            echo json_encode(['ok' => true, 'id' => $id]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Nelze vytvořit řádek nákladu: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'monthly_cost_delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Neplatné ID.']);
            exit;
        }
        try {
            $st = $pdo->prepare("DELETE FROM monthly_costs WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Nelze smazat náklad: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'monthly_cost_update') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Neplatné ID.']);
            exit;
        }

        $desc  = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
        $amountRaw = isset($_POST['amount']) ? (string)$_POST['amount'] : '0';
        $amountRaw = str_replace([' ', ','], ['', '.'], $amountRaw);
        $amount = (float)$amountRaw;

        $carry = isset($_POST['carry']) ? (int)$_POST['carry'] : 0;
        $carry = $carry ? 1 : 0;

        // volitelně umožníme změnit start měsíc/rok jen pokud přijde (pro budoucno) – jinak se nemění
        $fields = [
            'description' => $desc,
            'amount'      => $amount,
            'carry'       => $carry,
        ];

        try {
            $st = $pdo->prepare("
                UPDATE monthly_costs
                SET description = :d,
                    amount = :a,
                    carry = :c
                WHERE id = :id
                LIMIT 1
            ");
            $st->execute([
                ':d' => $fields['description'],
                ':a' => $fields['amount'],
                ':c' => $fields['carry'],
                ':id'=> $id,
            ]);
            // pokud je přenášet, vytvoř kopii do dalšího měsíce (jen pokud ještě neexistuje)
            if ($carry === 1 && $y >= 2000 && $m >= 1) {
                $ny = $y;
                $nm = $m + 1;
                if ($nm > 12) { $ny++; $nm = 1; }

                if (in_array($ny, [2025, 2026, 2027], true)) {
                    $chk = $pdo->prepare("SELECT id FROM monthly_costs WHERE start_year = :y AND start_month = :m AND description = :d LIMIT 1");
                    $chk->execute([':y' => $ny, ':m' => $nm, ':d' => $desc]);
                    $exists = $chk->fetchColumn();

                    if (!$exists) {
                        $ins = $pdo->prepare("INSERT INTO monthly_costs (start_year, start_month, description, amount, carry) VALUES (:y,:m,:d,:a,:c)");
                        $ins->execute([':y' => $ny, ':m' => $nm, ':d' => $desc, ':a' => $amount, ':c' => $carry]);
                    }
                }
            }

            echo json_encode(['ok' => true]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Nelze uložit náklad: ' . $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'Neznámá akce.']);
    exit;
}

// Výpočty a data pro stránku modulu
if ($loggedIn && $view === 'monthly') {
    $monthlyView = true;

    // připrav tabulku – musí existovat (automatické CREATE je často zakázané)
    $monthlyCreateSql = <<<SQLMONTHLY
CREATE TABLE monthly_costs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  start_year SMALLINT NOT NULL,
  start_month TINYINT NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  carry TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_start (start_year, start_month),
  KEY idx_carry (carry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQLMONTHLY;

    $monthlyTableReady = true;
    try {
        $pdo->query("SELECT 1 FROM monthly_costs LIMIT 1");
    } catch (Throwable $e) {
        $monthlyTableReady = false;
        $monthlyError = 'Tabulka monthly_costs neexistuje nebo k ní nemáš přístup (DB uživatel nemá CREATE). Níže je SQL pro ruční vytvoření.';
    }
$monthlyAuthed = !empty($_SESSION['monthly_ok']);
    $monthlyErrorMsg = '';
    if (!empty($_SESSION['monthly_error'])) {
        $monthlyErrorMsg = (string)$_SESSION['monthly_error'];
        unset($_SESSION['monthly_error']);
    }

    // výběr roku/měsíce
    $allowedYears = [2025, 2026, 2027];
    $y = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
    if (!in_array($y, $allowedYears, true)) $y = 2025;

    $m = isset($_GET['m']) ? (int)$_GET['m'] : (int)date('n');
    if ($m < 1 || $m > 12) $m = (int)date('n');

    $monthlyYear  = $y;
    $monthlyMonth = $m;

    // rozsah měsíce
    try {
        $tz = new DateTimeZone('Europe/Prague');
        $start = new DateTime(sprintf('%04d-%02d-01 00:00:00', $monthlyYear, $monthlyMonth), $tz);
        $end = clone $start;
        $end->modify('last day of this month 23:59:59');
        $monthlyFrom = $start->format('Y-m-d H:i:s');
        $monthlyTo   = $end->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $monthlyFrom = '';
        $monthlyTo   = '';
    }

    // zisk ze zboží za měsíc (jen položky s nákupní cenou)
    $monthlyGoodsProfit = 0.0;
    $monthlyMissingItems = 0;

    if ($monthlyFrom !== '' && $monthlyTo !== '' && empty($monthlyError)) {
        try {
            $sql = "
                SELECT
                    COALESCE(SUM(
                        CASE
                            WHEN oi.nakupni_cena IS NOT NULL AND oi.nakupni_cena > 0
                            THEN (oi.price_total_with_vat - (oi.nakupni_cena * (CASE WHEN oi.`count` IS NULL OR oi.`count` = 0 THEN 1 ELSE oi.`count` END)))
                            ELSE 0
                        END
                    ), 0) AS profit,
                    COALESCE(SUM(
                        CASE
                            WHEN oi.nakupni_cena IS NULL OR oi.nakupni_cena <= 0 THEN 1 ELSE 0
                        END
                    ), 0) AS missing_cnt
                FROM orders o
                JOIN order_items oi ON oi.id_order = o.id_order
                WHERE o.created_at >= :from AND o.created_at <= :to
            ";
            $st = $pdo->prepare($sql);
            $st->execute([':from' => $monthlyFrom, ':to' => $monthlyTo]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $monthlyGoodsProfit   = (float)($row['profit'] ?? 0);
            $monthlyMissingItems  = (int)($row['missing_cnt'] ?? 0);
        } catch (Throwable $e) {
            $monthlyError = 'Chyba při výpočtu zisku ze zboží: ' . $e->getMessage();
        }
    }

    
    // mini souhrn za měsíc: počet objednávek + obrat (z orders)
    $monthlyOrdersCount = 0;
    $monthlyTurnover = 0.0;

    if ($monthlyFrom !== '' && $monthlyTo !== '' && empty($monthlyError)) {
        try {
            $st = $pdo->prepare("
                SELECT
                    COUNT(*) AS orders_cnt,
                    COALESCE(SUM(o.total_price_with_vat), 0) AS turnover
                FROM orders o
                WHERE o.created_at >= :from AND o.created_at <= :to
            ");
            $st->execute([':from' => $monthlyFrom, ':to' => $monthlyTo]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $monthlyOrdersCount = (int)($row['orders_cnt'] ?? 0);
            $monthlyTurnover    = (float)($row['turnover'] ?? 0);
        } catch (Throwable $e) {
            // souhrn je doplňkový – když selže, modul má pořád fungovat
            $monthlyOrdersCount = 0;
            $monthlyTurnover = 0.0;
        }
    }

// náklady pro daný měsíc (snapshot – každý měsíc má vlastní hodnoty; přenášení = kopie do dalšího měsíce)
    $monthlyCostsRows = [];
    $monthlyCostsTotal = 0.0;

    if (empty($monthlyError) && $monthlyTableReady) {
        try {
            // Auto-přenos (snapshot):
            // do aktuálního měsíce zkopíruje „přenášené“ náklady (carry=1) z posledního dostupného
            // předchozího měsíce pro každý popis (funguje i když přeskočíš měsíce),
            // ale jen pokud v aktuálním měsíci ještě neexistuje řádek se stejným popisem.
            $ym = ($monthlyYear * 100) + $monthlyMonth;

            $copy = $pdo->prepare("
                INSERT INTO monthly_costs (start_year, start_month, description, amount, carry)
                SELECT ?, ?, mc.description, mc.amount, mc.carry
                FROM monthly_costs mc
                JOIN (
                    SELECT description, MAX((start_year * 100) + start_month) AS maxym
                    FROM monthly_costs
                    WHERE carry = 1 AND ((start_year * 100) + start_month) < ?
                    GROUP BY description
                ) t ON t.description = mc.description AND ((mc.start_year * 100) + mc.start_month) = t.maxym
                WHERE NOT EXISTS (
                    SELECT 1 FROM monthly_costs x
                    WHERE x.start_year = ? AND x.start_month = ? AND x.description = mc.description
                )
            ");
            $copy->execute([$monthlyYear, $monthlyMonth, $ym, $monthlyYear, $monthlyMonth]);

$st = $pdo->prepare("
                SELECT id, start_year, start_month, description, amount, carry
                FROM monthly_costs
                WHERE start_year = :y AND start_month = :m
                ORDER BY id ASC
            ");
            $st->execute([':y' => $monthlyYear, ':m' => $monthlyMonth]);
            $monthlyCostsRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($monthlyCostsRows as $r) {
                $monthlyCostsTotal += (float)($r['amount'] ?? 0);
            }
        } catch (Throwable $e) {
            $monthlyError = 'Chyba při načítání nákladů: ' . $e->getMessage();
        }
    }

    // dnešní den: zisk ze zboží (pro Měsíční přehled)
    $todayGoodsProfit  = 0.0;
    $todayMissingItems = 0;
    $todayFrom = '';
    $todayTo   = '';

    try {
        $tzToday = new DateTimeZone('Europe/Prague');
        $t0 = new DateTime('today 00:00:00', $tzToday);
        $t1 = new DateTime('today 23:59:59', $tzToday);
        $todayFrom = $t0->format('Y-m-d H:i:s');
        $todayTo   = $t1->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $todayFrom = '';
        $todayTo   = '';
    }

    if ($todayFrom !== '' && $todayTo !== '' && empty($monthlyError)) {
        try {
            $sql = "
                SELECT
                    COALESCE(SUM(
                        CASE
                            WHEN oi.nakupni_cena IS NOT NULL AND oi.nakupni_cena > 0
                            THEN (oi.price_total_with_vat - (oi.nakupni_cena * (CASE WHEN oi.`count` IS NULL OR oi.`count` = 0 THEN 1 ELSE oi.`count` END)))
                            ELSE 0
                        END
                    ), 0) AS profit,
                    COALESCE(SUM(
                        CASE
                            WHEN oi.nakupni_cena IS NULL OR oi.nakupni_cena <= 0 THEN 1 ELSE 0
                        END
                    ), 0) AS missing_cnt
                FROM orders o
                JOIN order_items oi ON oi.id_order = o.id_order
                WHERE o.created_at >= :from AND o.created_at <= :to
            ";
            $st = $pdo->prepare($sql);
            $st->execute([':from' => $todayFrom, ':to' => $todayTo]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $todayGoodsProfit  = (float)($row['profit'] ?? 0);
            $todayMissingItems = (int)($row['missing_cnt'] ?? 0);
        } catch (Throwable $e) {
            $todayGoodsProfit  = 0.0;
            $todayMissingItems = 0;
        }
    }

    // včerejší den: zisk ze zboží (pro Měsíční přehled)
    $yesterdayGoodsProfit  = 0.0;
    $yesterdayMissingItems = 0;
    $yesterdayFrom = '';
    $yesterdayTo   = '';

    try {
        $tzYesterday = new DateTimeZone('Europe/Prague');
        $y0 = new DateTime('yesterday 00:00:00', $tzYesterday);
        $y1 = new DateTime('yesterday 23:59:59', $tzYesterday);
        $yesterdayFrom = $y0->format('Y-m-d H:i:s');
        $yesterdayTo   = $y1->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $yesterdayFrom = '';
        $yesterdayTo   = '';
    }

    if ($yesterdayFrom !== '' && $yesterdayTo !== '' && empty($monthlyError)) {
        try {
            $sql = "
                SELECT
                    COALESCE(SUM(
                        CASE
                            WHEN oi.nakupni_cena IS NOT NULL AND oi.nakupni_cena > 0
                            THEN (oi.price_total_with_vat - (oi.nakupni_cena * (CASE WHEN oi.`count` IS NULL OR oi.`count` = 0 THEN 1 ELSE oi.`count` END)))
                            ELSE 0
                        END
                    ), 0) AS profit,
                    COALESCE(SUM(
                        CASE
                            WHEN oi.nakupni_cena IS NULL OR oi.nakupni_cena <= 0 THEN 1 ELSE 0
                        END
                    ), 0) AS missing_cnt
                FROM orders o
                JOIN order_items oi ON oi.id_order = o.id_order
                WHERE o.created_at >= :from AND o.created_at <= :to
            ";
            $st = $pdo->prepare($sql);
            $st->execute([':from' => $yesterdayFrom, ':to' => $yesterdayTo]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $yesterdayGoodsProfit  = (float)($row['profit'] ?? 0);
            $yesterdayMissingItems = (int)($row['missing_cnt'] ?? 0);
        } catch (Throwable $e) {
            $yesterdayGoodsProfit  = 0.0;
            $yesterdayMissingItems = 0;
        }
    }



    $monthlyNetProfit = $monthlyGoodsProfit - $monthlyCostsTotal;
}

?>

<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>cFloat – přehled</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="600">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --g1:#24d84a; --g2:#00b52a; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:#fff; margin:0; min-height:100vh;
            display:flex; align-items:flex-start; justify-content:center;
        }
        .wrap { width:100%; max-width:900px; padding:24px 16px 32px; }
        .wrap.wrap-wide { max-width:1500px; }
        .wrap.wrap-returns { max-width:none; padding-left:32px; padding-right:32px; }
        .logo-top { text-align:center; margin-bottom:16px; }
        .logo-top img { max-width:200px; height:auto; display:inline-block; }
        .logo-top a { text-decoration:none; }

        /* DLAŽDICE – 2 vedle sebe, další řada pod tím, i na mobilu */
        .tiles {
            display:grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap:12px;
            margin-top:8px;
        }
        .tile {
            background:#ffffff;
            position:relative;
            border-radius:14px;
            border:2px solid var(--g2);
            box-shadow:0 2px 8px rgba(0,0,0,0.06);
            padding:12px 8px;
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            color:var(--g2);
            transition:
                transform .12s ease,
                box-shadow .12s ease,
                background-color .12s ease,
                color .12s ease;
            text-align:center;
            min-height:70px;
        }

        .tile-badge{
            position:absolute;
            top:6px;
            right:8px;
            background:#d93025;
            color:#fff;
            min-width:22px;
            height:22px;
            padding:0 6px;
            border-radius:999px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:12px;
            font-weight:900;
            line-height:1;
            box-shadow:0 2px 6px rgba(0,0,0,0.18);
        }
        .tile:hover,
        .tile:active,
        .tile:focus,
        .tile:focus-visible {
            background:linear-gradient(135deg,var(--g1),var(--g2));
            color:#ffffff !important;
            box-shadow:0 6px 20px rgba(0,0,0,0.18);
            transform:translateY(-1px);
            outline:none;
        }
        /* Oranžové moduly (C-Store.cz) */
        .tile.tile-orange {
            background:#fff0d9;
        }
        .tile.tile-orange:hover,
        .tile.tile-orange:active,
        .tile.tile-orange:focus,
        .tile.tile-orange:focus-visible {
            background:#ffe2bb;
            color:#111111 !important;
        }
        /* Červený modul (Měsíční přehled) */
        .tile.tile-red-light {
            background:#ffe3e3;
        }
        .tile.tile-red-light:hover,
        .tile.tile-red-light:active,
        .tile.tile-red-light:focus,
        .tile.tile-red-light:focus-visible {
            background:#ffd0d0;
            color:#111111 !important;
        }


        .tile:hover .tile-title,
        .tile:hover .tile-text,
        .tile:active .tile-title,
        .tile:active .tile-text,
        .tile:focus .tile-title,
        .tile:focus .tile-text,
        .tile:focus-visible .tile-title,
        .tile:focus-visible .tile-text {
            color:#ffffff;
        }
        .tile-title {
            font-size:16px;
            font-weight:800;
            letter-spacing:0.03em;
            margin-bottom:4px;
        }
        .tile-text {
            font-size:11px;
            font-weight:400;
            color:inherit;
            display:-webkit-box;
            -webkit-line-clamp:3;
            -webkit-box-orient:vertical;
            overflow:hidden;
        }

        .card {
            background:#fff; border-radius:18px; padding:20px 24px 22px;
            box-shadow:0 2px 10px rgba(0,0,0,0.10); margin-top:16px;
        }
        .card-print { max-width:600px; margin:0 auto; text-align:center; }
        .card-orders { max-width:1180px; margin:16px auto; }
        @media (max-width: 768px) {
            .card-orders { max-width:100%; margin:16px 0; }
        }
        .card-print h1 { text-align:center; }
        .card-login {
            max-width:380px; margin:0 auto;
            padding:22px 22px 26px; border-radius:20px;
            box-shadow:0 12px 30px rgba(0,0,0,0.12);
        }
        .card-login h1 { margin:0 0 14px; font-size:22px; color:#1b5e20; text-align:center; }
        .card-login .login-subtitle { text-align:center; font-size:13px; margin-bottom:16px; color:#607d8b; }
        .back-link {
            display:inline-flex; align-items:center; font-size:13px;
            text-decoration:none; color:#2e7d32; margin-bottom:10px;
        }
        .back-link span { margin-left:4px; }
        .card h1 { margin:0 0 14px; font-size:22px; color:#1b5e20; }
        label { display:block; font-size:14px; margin-bottom:4px; }
        input[type="text"],input[type="date"],input[type="password"],select {
            width:100%; padding:9px 11px; border-radius:10px; border:1px solid #d0e4d2;
            font-size:14px; box-sizing:border-box;
            transition:border-color .15s, box-shadow .15s;
        }
        input:focus, select:focus {
            outline:none; border-color:#24d84a; box-shadow:0 0 0 2px rgba(36,216,74,0.18);
        }
        .ean-input { text-align:center; font-size:24px; padding:16px 12px; }
        button {
            margin-top:10px; padding:10px 18px; border-radius:999px; border:none;
            background:linear-gradient(135deg,var(--g1),var(--g2));
            color:#fff; font-size:16px; font-weight:700; cursor:pointer; letter-spacing:.03em;
        }
        button:hover { filter:brightness(1.03); box-shadow:0 4px 12px rgba(0,0,0,0.18); }
        .btn-full { width:100%; }
        .card-print .btn-full { max-width:320px; margin:0 auto; }
        .msg { margin-top:12px; font-size:14px; }

        .returns-master-row { cursor:pointer; }
        .returns-master-row:hover td { background:#f6fff8; }
        .returns-detail-row { display:none; }
        .returns-detail-row.open { display:table-row; }
        .returns-detail-row.open > td {
            position:fixed; left:50%; transform:translateX(-50%); top:5vh; bottom:5vh; z-index:10001;
            width:min(1180px, calc(100vw - 48px)); display:block; overflow:auto; background:#fff !important; padding:16px !important;
            border:2px solid #19cf42; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.35);
        }
        .returns-detail-row > td { background:#fbfffc !important; padding:12px !important; border-top:2px solid #19cf42; }
        body.returns-modal-open { overflow:hidden; }
        #returns-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:10000; }
        #returns-modal-backdrop.open { display:block; }
        .returns-modal-close { float:right; margin:0 0 10px 10px; border-radius:999px; padding:7px 12px; background:#111; color:#fff; font-weight:800; border:0; cursor:pointer; }
        .returns-detail-box { border:1px solid #bfe8c6; border-radius:10px; padding:12px; background:#fff; }
        .returns-detail-box h3 { margin:0 0 4px; color:#0b5d1e; font-size:15px; }
        .returns-detail-muted { color:#555; font-size:12px; margin-bottom:10px; }
        .returns-items-edit-table { min-width:1100px; margin-top:8px; }
        .returns-items-edit-table input, .returns-add-product input, .returns-add-product select { width:100%; padding:6px 7px; border:1px solid #cfd8dc; border-radius:7px; font-size:12px; }
        .returns-items-edit-table input[name="return_qty"] { max-width:80px; }
        .returns-items-edit-table input[name="price_per_unit_with_vat"] { max-width:110px; }
        .returns-actions-cell { white-space:nowrap !important; }
        .returns-mini-btn { border:0; border-radius:7px; padding:7px 9px; background:#19cf42; color:#fff; font-weight:800; cursor:pointer; font-size:12px; }
        .returns-mini-btn:hover { background:#10b832; }
        .returns-mini-btn.danger { background:#d93025; margin-top:4px; }
        .returns-mini-btn.paid { background:#0b8f2d; padding:5px 8px; }
        .returns-paid-list { margin-top:14px; border-top:1px solid #d8ecd9; padding-top:8px; }
        .returns-add-product { margin-top:12px; border-top:1px solid #d8ecd9; padding-top:10px; }
        .returns-add-product h4 { margin:0 0 8px; color:#0b5d1e; }
        .returns-product-search-row { display:grid; grid-template-columns:1fr auto; gap:8px; margin-bottom:8px; }
        .returns-add-grid { display:grid; grid-template-columns:120px 1.4fr 1fr 160px 80px 120px; gap:8px; margin:8px 0; }
        .returns-accounting-list .returns-detail-row > td { border-top:2px solid #19cf42; }
        @media (max-width:900px){ .returns-add-grid{grid-template-columns:1fr;} .returns-product-search-row{grid-template-columns:1fr;} }
        .msg-ok { color:#1b5e20; font-weight:600; }
        .msg-error { color:#c62828; font-weight:500; }
        .detail { margin-top:20px; font-size:20px; }
        .detail-row { margin-top:10px; }
        .label { font-weight:800; color:#1b5e20; margin-right:4px; }
        .badge { display:inline-block; padding:6px 14px; border-radius:999px; font-size:18px; }
        .badge-paid { background:#c8e6c9; color:#1b5e20; }
        .badge-unpaid { background:#ffcdd2; color:#b71c1c; }
        .stats-grid {
            display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
            gap:8px 12px; margin-bottom:8px;
        }
        .stats-label { font-size:13px; margin-bottom:2px; }
        .table-wrap { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .stats-table {
            width:100%; border-collapse:collapse; margin-top:10px;
            font-size:12px; table-layout:fixed;
        }
        .stats-table th,.stats-table td {
            border:1px solid #c8e6c9; padding:4px 4px; text-align:left;
            white-space:normal; word-wrap:break-word;
        }
        .stats-table th { background:#c8e6c9; }
        .stats-detail-row { display:none; background:#f1f8e9; }
        .stats-detail-cell { padding:6px 8px; font-size:12px; color:#33691e; }
        .stats-detail-list { margin:0; padding-left:16px; }
        .stats-detail-list li { margin:2px 0; }
        .stats-name-btn {
            border:none; background:none; padding:0; margin:0; font:inherit;
            color:#1b5e20; cursor:pointer; text-decoration:underline;
            text-decoration-thickness:1px; text-underline-offset:2px; width:100%; text-align:left;
        }
        .stats-name-btn:hover { color:#2e7d32; }
        .stats-summary { margin-top:8px; font-size:14px; font-weight:600; color:#1b5e20; }
        .returns-summary { margin-top:10px; font-size:14px; font-weight:600; color:#1b5e20; }
        .returns-filter-form { margin-bottom:10px; }
        .returns-search-row { display:flex; gap:8px; align-items:center; margin-top:10px; }
        .returns-search-row input { flex:1; min-width:220px; padding:10px 12px; border:1px solid #cfd8dc; border-radius:10px; }
        .returns-search-btn { width:auto; min-width:190px; margin-top:0; }
        .returns-tabs { display:flex; gap:8px; margin:10px 0; flex-wrap:wrap; }
        .returns-tab { display:inline-block; padding:9px 14px; border-radius:999px; background:#e8f5e9; color:#0b5d1e; text-decoration:none; font-weight:800; }
        .returns-tab.active { background:#19cf42; color:#fff; }
        .returns-card { width:100%; }
        .returns-board { display:grid; grid-template-columns:minmax(650px,1.35fr) minmax(390px,.85fr); gap:12px; align-items:start; }
        .returns-section-title { font-size:15px; margin:10px 0 7px; color:#0b5d1e; }
        .returns-table { table-layout:auto; min-width:820px; }
        .returns-main-list .returns-table { min-width:760px; font-size:11px; }
        .returns-accounting-list .returns-table { min-width:500px; }
        .returns-products-wrap .returns-table { min-width:1150px; }
        .returns-table th,.returns-table td { vertical-align:middle; word-break:normal; overflow-wrap:normal; padding:2px 5px; font-size:11px; line-height:1.08; }
        .returns-table th { white-space:normal; }
        .returns-table td { white-space:normal; }
        .returns-main-list .returns-table th,
        .returns-main-list .returns-table td { padding:2px 4px; font-size:11px; line-height:1.08; }
        .returns-table td:nth-child(1), .returns-table td:nth-child(2), .returns-table td:nth-child(3), .returns-table td:nth-child(4), .returns-table td:nth-child(6), .returns-table td:nth-child(7), .returns-table td:nth-child(8), .returns-table td:nth-child(9) { white-space:nowrap; }
        .returns-status-form { margin:0; }
        .returns-refund-btn { background:#19cf42; color:#fff; border:0; border-radius:7px; padding:4px 7px; font-weight:800; font-size:11px; cursor:pointer; white-space:nowrap; }
        .returns-refund-btn:hover { background:#10b832; }
        .returns-accounting-list { border-left:3px solid #19cf42; padding-left:12px; }
        .returns-products-wrap .stats-table td { font-size:12px; }
        .msg-ok { background:#e9f7ef; border:1px solid #b7e1c1; color:#0b5d1e; }
        @media (max-width:1100px){ .wrap.wrap-returns{padding-left:16px;padding-right:16px;} .returns-board{grid-template-columns:1fr;} .returns-accounting-list{border-left:0;padding-left:0;} }
        @media (max-width:720px){ .returns-search-row{display:block;} .returns-search-btn{width:100%;margin-top:8px;} }

        /* Kompaktní řádky pro celý modul Vrácené zboží */
        .returns-card .table-wrap { margin-top:6px; }
        .returns-card .stats-table { border-collapse:collapse; }
        .returns-card .stats-table th,
        .returns-card .stats-table td {
            padding:2px 5px !important;
            font-size:11px !important;
            line-height:1.08 !important;
            vertical-align:middle !important;
        }
        .returns-card .stats-table th { font-weight:800; }
        .returns-card .stats-table td strong { line-height:1.08 !important; }
        .returns-main-list .returns-table,
        .returns-accounting-list .returns-table,
        .returns-paid-list .returns-table {
            min-width:0 !important;
        }
        .returns-refund-btn,
        .returns-mini-btn {
            padding:2px 6px !important;
            font-size:10.5px !important;
            line-height:1.1 !important;
            min-height:0 !important;
        }
        .returns-status-form { line-height:1 !important; }
        .returns-section-title { margin:8px 0 5px !important; }
        .returns-summary { margin-top:7px !important; }

        #stats-chart-wrap { display:none; margin-top:16px; }
        .btn-logout {
            background:#000 !important; color:#fff !important; box-shadow:none !important; margin-top:24px;
        }
        .btn-logout:hover {
            filter:none !important; background:#222 !important;
            box-shadow:0 4px 10px rgba(0,0,0,0.3) !important;
        }
        .logout-wrap { margin-top:24px; text-align:center; }
        .cfloat-new-wrap { margin-top:14px; text-align:center; }
        .btn-cfloat-round {
            display:inline-flex; align-items:center; justify-content:center;
            width:112px; height:112px; border-radius:50%;
            background:linear-gradient(135deg,var(--g1),var(--g2));
            color:#fff !important; text-decoration:none;
            font-weight:800; font-size:13px; line-height:1.2; text-align:center;
            letter-spacing:0.02em;
            box-shadow:0 4px 14px rgba(0,0,0,0.20);
            transition: transform .12s ease, box-shadow .12s ease, filter .12s ease;
            padding:8px;
        }
        .btn-cfloat-round:hover,
        .btn-cfloat-round:focus,
        .btn-cfloat-round:active {
            filter:brightness(1.06);
            box-shadow:0 8px 22px rgba(0,0,0,0.28);
            transform:translateY(-2px);
            outline:none;
        }

        /* OBJEDNÁVKY / SERVIS – více roztažené a přizpůsobené sloupcům */
        .orders-controls {
            display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:8px;
        }
        .orders-controls-left { flex:1 1 200px; }
        .orders-controls-right { display:flex; gap:8px; align-items:center; }
        .orders-controls-middle { flex:0 0 260px; min-width:220px; }
        .orders-date-range { display:flex; align-items:center; gap:4px; }
        .orders-date-range input[type="date"] { width:120px; }
        .orders-search-input { width:100%; }

        .orders-table {
            table-layout:auto;
        }
        .orders-table th,.orders-table td {
            font-size:12px;
            white-space:nowrap;
        }

        /* šířky sloupců – desktop / základ */
        .orders-col-date    { min-width: 115px; }
        .orders-col-profit  { min-width: 95px; }
        .orders-col-name    { min-width: 180px; }
        .orders-col-email   { min-width: 165px; }
        .orders-col-phone   { min-width: 120px; }
        .orders-col-order   { min-width: 115px; }
        .orders-col-invoice { min-width: 110px; }
        .orders-col-total   { min-width: 95px; }
        .orders-col-payment { min-width: 120px; }
        .orders-col-delivery{ min-width: 120px; }

        .orders-paid-indicator {
            display:inline-block; width:12px; height:12px;
            border-radius:2px; margin-right:4px; background:#ccc; vertical-align:middle;
        }
        .orders-paid-indicator.paid { background:#2e7d32; }
        .orders-paid-indicator.unpaid { background:#c62828; }
        .order-main-row { cursor:pointer; }
        .order-main-row.selected { background:#e8f5e9; }
        .order-main-row.selected td { font-weight:600; }
        .order-main-row.has-missing td { color:#c00; }
        .order-detail-row { display:none; }
        
        /* Detail (vyjížděcí) řádek NIKDY nebarvit – zeleně pouze hlavní řádek */
        .order-detail-row, .order-detail-row td { background:#fff !important; font-weight:400 !important; }
        .order-detail-row.selected, .order-detail-row.selected td { background:#fff !important; font-weight:400 !important; }
/* EXTRA: detail (rozjížděcí) část vždy bílá – nezdědit zelené zvýraznění */
.order-main-row.selected + .order-detail-row,
.order-main-row.selected + .order-detail-row td,
.order-detail-row,
.order-detail-row td,
.order-detail-cell,
.order-detail-row .order-items-table,
.order-detail-row .order-items-table th,
.order-detail-row .order-items-table td {
    background:#fff !important;
}

/* Negativní zisk – lehce červené pozadí, černé písmo */
.neg-profit { background: rgba(255, 0, 0, 0.12) !important; color:#000 !important; }
.order-detail-row td.neg-profit { background: rgba(255, 0, 0, 0.12) !important; color:#000 !important; }

.order-detail-cell { padding:4px 6px; font-size:12px; color:inherit; }
        .order-items-table { width:auto; border-collapse:collapse; table-layout:auto; }
        .order-items-table th,.order-items-table td {
            border:1px solid #c8e6c9; padding:3px 4px; font-size:11px; white-space:nowrap;
        }
        .order-delivery-note { font-size:11px; margin-top:4px; color:inherit; }
        .orders-badge-email { cursor:pointer; font-size:11px; opacity:0.8; }
        .delivery-toggle { cursor:pointer; text-decoration:underline; }

        .inv-wrap { display:flex; flex-direction:column; gap:2px; }
        .inv-links { display:flex; gap:6px; flex-wrap:wrap; margin-top:2px; }
        .inv-link {
            display:inline-block;
            padding:2px 6px;
            border:1px solid #81c784;
            border-radius:8px;
            font-size:11px;
            color:#2e7d32;
            text-decoration:none;
            background:#f1f8e9;
            line-height:1.2;
        }
        .inv-link:hover { filter:brightness(0.97); }
    

        @media(max-width:640px) {
            .tiles { gap:8px; }
            .tile { padding:10px 6px; border-radius:12px; min-height:64px; }
            .tile-title { font-size:14px; }
            .tile-text { font-size:10px; }

            /* větší výška řádků + lepší čitelnost v Objednávky / Servis / detail */
            .stats-table th,
            .stats-table td {
                padding:8px 6px;
                line-height:1.4;
                font-size:11px;
            }

            .orders-table th,
            .orders-table td {
                padding:8px 6px;
                line-height:1.4;
                font-size:11px;
                white-space:normal;
                word-wrap:break-word;
            }

            .orders-col-date,
            .orders-col-name,
            .orders-col-email,
            .orders-col-phone,
            .orders-col-order,
            .orders-col-invoice,
            .orders-col-total,
            .orders-col-purchase,
            .orders-col-payment,
            .orders-col-delivery {
                min-width:auto;
            }

            .order-detail-cell {
                padding:10px 8px;
            }
            .order-items-table th,
            .order-items-table td {
                padding:6px 4px;
                line-height:1.4;
                font-size:10px;
                white-space:normal;
                word-wrap:break-word;
            }

            .card-login { margin-top:8px; }
        }
    
        /* Měsíční přehled */
        .month-grid {
            display:grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap:8px;
            margin-top:12px;
        }
        @media (max-width: 700px) {
            .month-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
        }
        .month-btn {
            display:block;
            text-decoration:none;
            border:2px solid #dfeee1;
            border-radius:12px;
            padding:10px 10px;
            font-weight:800;
            color:#0b7a23;
            background:#fff;
            text-align:center;
        }
        .month-btn.active {
            border-color: var(--g2);
            background: linear-gradient(135deg, rgba(36,216,74,0.12), rgba(0,181,42,0.12));
        }
        .year-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
        .year-tab {
            display:inline-block;
            padding:8px 12px;
            border-radius:999px;
            border:2px solid #dfeee1;
            text-decoration:none;
            color:#0b7a23;
            font-weight:900;
            background:#fff;
        }
        .year-tab.active { border-color: var(--g2); background: rgba(0,181,42,0.10); }
        
        .monthly-mini { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
                .monthly-mini .chip{
                    display:inline-flex;
                    align-items:center;
                    gap:6px;
                    padding:6px 10px;
                    border-radius:999px;
                    border:2px solid #e8f3ea;
                    background:#fff;
                    font-weight:900;
                    font-size:13px;
                    color:#0b7a23;
                }
                .monthly-mini .chip b{ color:#111; }

        .monthly-kpis { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:10px; margin-top:14px; }
        @media (max-width: 700px) { .monthly-kpis { grid-template-columns: 1fr; } }
        .kpi {
            border:2px solid #e8f3ea;
            border-radius:14px;
            padding:12px 12px;
            background:#fff;
        }
        .kpi .lbl { font-size:12px; color:#2b2b2b; opacity:.8; }
        .kpi .val { font-size:18px; font-weight:900; margin-top:4px; }
        .kpi .val.negative { color:#b30000; }
        .kpi .val.positive { color:#0b7a23; }

        .costs-wrap { margin-top:12px; }
        .costs-table { width:100%; border-collapse:collapse; }
        .costs-table th, .costs-table td { border-bottom:1px solid #eef2ef; padding:10px 8px; vertical-align:middle; }
        .costs-table th { text-align:left; font-size:12px; opacity:.75; }
        .costs-desc { width:70%; }
        .costs-amt { width:30%; text-align:right; white-space:nowrap; }
        .costs-input {
            width:100%;
            padding:8px 10px;
            border:1px solid #dfeee1;
            border-radius:10px;
            font-size:14px;
            box-sizing:border-box;
        }
        .costs-inline {
            display:flex;
            gap:8px;
            align-items:center;
        }
        .carry-box { display:flex; gap:6px; align-items:center; font-size:12px; opacity:.85; }
        .carry-note { font-size:11px; opacity:.7; margin-left:6px; }
        .btn-mini {
            border:none;
            border-radius:10px;
            padding:8px 10px;
            font-weight:900;
            cursor:pointer;
        }
        .btn-mini-add { background: linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; }
        .btn-mini-del { background:#ffe7e7; color:#b30000; }
        .save-dot { display:inline-block; width:8px; height:8px; border-radius:99px; background:#bbb; margin-left:6px; vertical-align:middle; }
        .save-dot.ok { background: #0b7a23; }
        .save-dot.err { background: #b30000; }


        /* Rychlý postup XML feed – Dodavatelé */
        .supplier-quick-guide {
            margin: 12px 0 16px 0;
            padding: 16px;
            border: 2px solid #0a7d67;
            border-radius: 18px;
            background: linear-gradient(180deg, #f7fffa 0%, #ffffff 100%);
            box-shadow: 0 8px 22px rgba(0, 80, 40, .08);
        }
        .supplier-quick-guide-header {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:12px;
            margin-bottom: 12px;
        }
        .supplier-quick-guide-title {
            font-weight: 900;
            font-size: 18px;
            color:#075f35;
            line-height:1.2;
        }
        .supplier-quick-guide-subtitle {
            margin-top:4px;
            font-size: 12px;
            color:#37614a;
            line-height:1.35;
        }
        .supplier-quick-guide-note {
            display:inline-block;
            padding:7px 10px;
            border-radius:999px;
            background:#eaf8ef;
            color:#0f6b35;
            font-weight:800;
            font-size:12px;
            white-space:nowrap;
        }
        .supplier-auto-all-box {
            margin: 12px 0 14px 0;
            padding: 14px;
            border: 2px solid #16a34a;
            border-radius: 18px;
            background: linear-gradient(135deg, #ecfff2 0%, #ffffff 100%);
            display:grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(280px, .8fr);
            gap: 14px;
            align-items:center;
        }
        .supplier-auto-all-title {
            font-weight: 950;
            color:#064e2a;
            font-size: 17px;
            margin-bottom: 5px;
        }
        .supplier-auto-all-text {
            color:#315943;
            font-size: 12px;
            line-height:1.45;
        }
        .supplier-auto-all-form {
            display:flex;
            flex-direction:column;
            gap:8px;
            margin:0;
        }
        .supplier-auto-all-form input[type="file"] {
            width:100%;
            box-sizing:border-box;
            border:1px solid #cdebd6;
            border-radius: 12px;
            padding:8px;
            background:#fff;
            font-size: 12px;
        }
        .supplier-auto-all-button {
            width:100%;
            border:0;
            border-radius:999px;
            padding: 11px 14px !important;
            background: linear-gradient(135deg, #18b743, #009b3f) !important;
            color:#fff !important;
            font-weight: 950;
            font-size: 13px !important;
            cursor:pointer;
            box-shadow: 0 7px 16px rgba(24,183,67,.22);
        }
        .supplier-quick-steps {
            display:grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }
        .supplier-quick-step {
            border: 1px solid #d9efdf;
            border-radius: 16px;
            padding: 12px;
            background:#fff;
            min-height: 168px;
            display:flex;
            flex-direction:column;
            gap:8px;
        }
        .supplier-quick-step-top {
            display:flex;
            align-items:center;
            gap:8px;
        }
        .supplier-quick-number {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            background: linear-gradient(135deg, #18b743, #009b3f);
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight: 900;
            font-size: 16px;
            flex:0 0 auto;
            box-shadow: 0 5px 12px rgba(24,183,67,.22);
        }
        .supplier-quick-step-title {
            font-weight: 900;
            color:#0a5e2b;
            font-size: 14px;
            line-height:1.2;
        }
        .supplier-quick-step-text {
            color:#3d5f49;
            font-size: 12px;
            line-height:1.35;
            flex: 1 1 auto;
        }
        .supplier-quick-action-btn,
        .supplier-quick-step .btn-full {
            display:inline-block;
            width:100%;
            box-sizing:border-box;
            text-align:center;
            border:0;
            border-radius: 999px;
            padding: 9px 12px !important;
            background: linear-gradient(135deg, #18b743, #009b3f) !important;
            color:#fff !important;
            font-weight: 900;
            font-size: 12px !important;
            line-height:1.2;
            text-decoration:none;
            cursor:pointer;
            box-shadow: 0 5px 12px rgba(24,183,67,.18);
        }
        .supplier-quick-action-btn.secondary {
            background: #eaf8ef !important;
            color:#075f35 !important;
            border:1px solid #cdebd6;
            box-shadow:none;
        }
        .supplier-quick-upload-form {
            display:flex;
            flex-direction:column;
            gap:8px;
            margin:0;
        }
        .supplier-quick-upload-form input[type="file"] {
            width:100%;
            max-width:100%;
            box-sizing:border-box;
            border:1px solid #d9efdf;
            border-radius: 12px;
            padding:7px;
            background:#f9fffb;
            font-size: 12px;
        }
        .supplier-quick-downloads {
            display:flex;
            flex-direction:column;
            gap:7px;
        }
        .supplier-quick-download-link {
            display:block;
            padding:8px 9px;
            border-radius:12px;
            background:#f0fbf3;
            border:1px solid #d9efdf;
            color:#075f35;
            font-weight:900;
            font-size:12px;
            text-decoration:none;
            overflow-wrap:anywhere;
        }
        .supplier-quick-meta {
            color:#5d7867;
            font-size:11px;
            line-height:1.25;
            margin-top:3px;
        }
        @media(max-width:960px) {
            .supplier-quick-steps { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media(max-width:640px) {
            .supplier-quick-guide { padding: 12px; border-radius: 14px; }
            .supplier-quick-guide-header { display:block; }
            .supplier-quick-guide-note { margin-top:8px; white-space:normal; }
            .supplier-auto-all-box { grid-template-columns: 1fr; padding:12px; border-radius:14px; }
            .supplier-auto-all-title { font-size:15px; }
            .supplier-auto-all-text { font-size:11px; }
            .supplier-quick-steps { grid-template-columns: 1fr; gap:8px; }
            .supplier-quick-step { min-height:0; padding:10px; border-radius:14px; }
            .supplier-quick-number { width:28px; height:28px; font-size:14px; }
            .supplier-quick-step-title { font-size:13px; }
            .supplier-quick-step-text { font-size:11px; }
            .supplier-quick-action-btn,
            .supplier-quick-step .btn-full { font-size:11px !important; padding:8px 10px !important; }
        }



        /* Ruční aktualizace dodavatelů – kompaktní zobrazení pro telefon */
        .manual-supplier-box {
            font-size:13px;
        }
        .manual-supplier-title {
            font-weight:800;
            margin-bottom:8px;
            font-size:16px;
            color:#1b5e20;
        }
        .manual-supplier-table {
            width:100%;
            border-collapse:collapse;
            margin-top:6px;
            font-size:13px;
            table-layout:auto;
        }
        .manual-supplier-table th,
        .manual-supplier-table td {
            border-bottom:1px solid #e4f0e7;
            padding:6px 7px;
            line-height:1.25;
            vertical-align:top;
            overflow-wrap:anywhere;
            word-break:normal;
        }
        .manual-supplier-table th {
            background:#eef8f1;
            color:#1b5e20;
            font-size:12px;
            font-weight:800;
            text-align:left;
        }
        .manual-supplier-table a {
            overflow-wrap:anywhere;
            word-break:normal;
        }
        .manual-supplier-box .muted {
            font-size:12px;
            line-height:1.25;
        }
        .manual-supplier-box button,
        .manual-supplier-box .btn-full {
            font-size:12px !important;
            padding:6px 10px !important;
            margin-top:0;
            line-height:1.2;
            letter-spacing:0;
        }
        .manual-supplier-action-form {
            position:relative;
            z-index:5;
        }
        .manual-supplier-action-button {
            min-height:36px;
            -webkit-appearance:none;
            appearance:none;
            touch-action:manipulation;
            pointer-events:auto;
            position:relative;
            z-index:6;
        }
        .manual-supplier-status {
            font-size:12px !important;
            line-height:1.2;
        }
        .manual-supplier-file-meta {
            font-size:12px;
            line-height:1.25;
        }
        .manual-supplier-stats {
            margin-top:10px;
            padding:8px 10px;
            border-top:4px solid #2fb344;
            background:#eefaf1;
            border-radius:8px;
            font-weight:700;
            font-size:13px;
            line-height:1.35;
        }
        @media(max-width:640px) {
            .manual-supplier-box {
                padding:10px !important;
                margin-top:10px !important;
                border-radius:12px !important;
                font-size:11px;
            }
            .manual-supplier-title {
                font-size:14px !important;
                margin-bottom:6px !important;
            }
            .manual-supplier-table {
                font-size:10.5px;
                margin-top:4px !important;
            }
            .manual-supplier-table th,
            .manual-supplier-table td {
                padding:5px 4px;
                line-height:1.2;
            }
            .manual-supplier-table th {
                font-size:10.5px;
            }
            .manual-supplier-table th:nth-child(2),
            .manual-supplier-table td:nth-child(2) {
                width:72px;
            }
            .manual-supplier-table th:nth-child(3),
            .manual-supplier-table td:nth-child(3) {
                width:116px;
            }
            .manual-supplier-action-form {
                display:block !important;
                margin:0 0 4px 0 !important;
            }
            .manual-supplier-box button,
            .manual-supplier-box .btn-full {
                font-size:10.5px !important;
                padding:5px 7px !important;
                line-height:1.15;
                white-space:normal;
            }
            .manual-supplier-action-button {
                display:block !important;
                width:100% !important;
                min-height:40px !important;
                padding:9px 10px !important;
                white-space:nowrap !important;
            }
            .manual-supplier-status {
                font-size:10.5px !important;
                padding:4px 7px !important;
                margin:0 0 4px 0 !important;
            }
            .manual-supplier-download {
                display:inline-block !important;
                width:auto !important;
                max-width:100%;
                margin-top:3px;
                overflow-wrap:anywhere;
            }
            .manual-supplier-file-meta,
            .manual-supplier-box .muted {
                font-size:10.5px !important;
                line-height:1.2;
            }
            .manual-supplier-stats {
                margin-top:8px !important;
                padding:7px 8px !important;
                font-size:11px !important;
                line-height:1.25;
            }
            .manual-supplier-stats .muted {
                display:block;
                margin-left:0 !important;
                margin-top:3px;
            }
        }

</style>
</head>
<body>
<div class="wrap <?php echo ($loggedIn && $view === 'cservis_fakturace') ? 'wrap-wide wrap-returns' : ''; ?>">
    <div class="logo-top">
        <a href="index.php">
            <img src="LOGO-1.png" alt="C-Store.cz">
        </a>
    </div>

    <?php if (!$loggedIn): ?>

        <div class="card card-login">
            <h1>Přihlášení</h1>
            <div class="login-subtitle">Zadej přístupové údaje k interní aplikaci cFloat.</div>
            <form method="post" action="index.php">
                <label for="username">Uživatel</label>
                <input type="text" name="username" id="username" required>

                <label for="password">Heslo</label>
                <input type="password" name="password" id="password" required>

                <button type="submit" name="action" value="login" class="btn-full">
                    PŘIHLÁSIT SE
                </button>
            </form>

            <?php if ($loginError !== ''): ?>
                <div class="msg msg-error"><?php echo h($loginError); ?></div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var u = document.getElementById('username');
            if (u) u.focus();
        })();
        </script>

    <?php else: ?>

        <?php if ($view === 'home'): ?>

            <div class="tiles">
                <a class="tile tile-orange" href="https://1388739759.s1.eshop-rychle.cz/admin/script.php?svol=2&vol=eshop4">
                    <div class="tile-title">C-Store.cz / Objednávky</div>
                    <div class="tile-text">Administrace e-shopu – Objednávky</div>
                </a>

                <a class="tile tile-orange" href="https://1388739759.s1.eshop-rychle.cz/admin/script.php?svol=4&vol=orders_gains">
                    <div class="tile-title">C-Store.cz / Statistika</div>
                    <div class="tile-text">Administrace e-shopu – Statistika</div>
                </a>

                
                <a class="tile" href="index.php?view=print">
                    <div class="tile-title">TISK ŠTÍTKŮ</div>
                    <div class="tile-text">Načíst EAN / číslo objednávky. Automaticky se vytiskne štítek.</div>
                </a>

                <a class="tile" href="index.php?view=stats">
                    <div class="tile-title">STATISTIKA</div>
                    <div class="tile-text">Statistika prodejů zboží dle značek</div>
                </a>

                <a class="tile" href="index.php?view=orders">
                    <div class="tile-title">OBJEDNÁVKY</div>
                    <div class="tile-text">Přehled objednávek s detailem položek.</div>
                </a>

                <a class="tile" href="index.php?view=customers">
                    <div class="tile-title">ZÁKAZNÍCI</div>
                    <div class="tile-text">Top zákazníci podle počtu objednávek a útraty.</div>
                </a>

                <a class="tile" href="index.php?view=service">
                    <div class="tile-title">Servis</div>
                    <div class="tile-text">Přehled servisních zakázek (C-servis).</div>
                </a>

                <a class="tile" href="index.php?view=cservis_fakturace">
                    <div class="tile-title">Cservis FAKTURACE</div>
                    <div class="tile-text">Fakturace cykloservisu z databáze produktů, hotově/kartou a PDF.</div>
                </a>

                <a class="tile" href="nastaveni.php">
                    <div class="tile-title">Nastavení</div>
                    <div class="tile-text">Sloučení CSV variant do AllVarianty.csv</div>
                </a>

                <a class="tile" href="index.php?view=xmlfeedy">
                    <div class="tile-title">XML feed – Dodavatelé</div>
                    <div class="tile-text">Správa uložených XML feedů dodavatelů</div>
                </a>

                <a class="tile tile-red-light" href="index.php?view=monthly">
                    <div class="tile-title">MĚSÍČNÍ PŘEHLED</div>
                    <div class="tile-text">Zisk ze zboží, náklady a čistý zisk po měsících.</div>
                </a>

</div>

        <?php elseif ($view === 'cservis_fakturace'): ?>

            <?php include __DIR__ . '/modules/cservis-fakturace/cservis-fakturace.php'; ?>

        <?php elseif ($view === 'print'): ?>

            <div class="card card-print">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Tisk štítků</h1>

                <form method="get" action="index.php">
                    <input type="hidden" name="view" value="print">
                    <label for="ean">EAN / číslo objednávky</label>
                    <input type="text" id="ean" name="ean" class="ean-input" value="<?php echo h($ean); ?>" autofocus>

                    <div style="margin-top:10px;">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none;">
                            <input type="checkbox" id="cod_override_on" name="cod_override_on" value="1" <?php echo $codOverrideOn ? 'checked' : ''; ?>>
                            <span>Upravit dobírku pro štítek</span>
                        </label>
                    </div>

                    <div id="cod_override_wrap" style="margin-top:10px;<?php echo $codOverrideOn ? '' : 'display:none;'; ?>">
                        <label for="cod_override">Dobírka do štítku (CZK)</label>
                        <input type="text" id="cod_override" name="cod_override" class="ean-input" value="<?php echo h($codOverrideRaw); ?>" placeholder="např. 0">
                        <div style="font-size:12px;opacity:0.8;margin-top:4px;">Zadej 0 pro vypnutí dobírky (i když je v objednávce dobírka).</div>
                    </div>

                    <button type="submit" class="btn-full">NAČÍST OBJEDNÁVKU</button>
                </form>

                <?php if ($message !== ''): ?>
                    <div class="msg <?php echo $status === 'ok' ? 'msg-ok' : 'msg-error'; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($status === 'ok'): ?>
                    <div class="detail">
                        <div class="detail-row">
                            <span class="label">Objednávka:</span>
                            <span><?php echo h($ean); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Zákazník:</span>
                            <span><?php echo $customerName !== '' ? h($customerName) : '—'; ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Platba:</span>
                            <?php if ($isPaid === true): ?>
                                <span class="badge badge-paid">Zaplaceno</span>
                            <?php elseif ($isPaid === false): ?>
                                <span class="badge badge-unpaid">Nezaplaceno</span>
                            <?php else: ?>
                                <span>Neznámý stav (<?php echo h($paidTextRaw); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($isPaid === false && $codAmount !== ''): ?>
                            <div class="detail-row">
                                <span class="label">Dobírka:</span>
                                <span><?php echo h($codAmount); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($printItemsError !== ''): ?>
                        <div class="msg msg-error"><?php echo h($printItemsError); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($printItems)): ?>
                        <h2>Položky objednávky</h2>
                        <div class="table-wrap">
                            <table class="stats-table">
                                <thead>
                                <tr>
                                    <th>Kód</th>
                                    <th>Produkt</th>
                                    <th>Varianta</th>
                                    <th>Ks</th>
                                    <th>Řádek (s DPH)</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($printItems as $it): ?>
                                    <tr>
                                        <td><?php echo h($it['product_number']); ?></td>
                                        <td><?php echo h($it['product_name']); ?></td>
                                        <td><?php echo h($it['variant_description']); ?></td>
                                        <td><?php echo (float)$it['count']; ?></td>
                                        <td><?php echo number_format((float)$it['price_total_with_vat'], 2, ',', ' '); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>


            <?php
            // Evidence tisku (dnes) – všechny dopravci (pro expedici + SMS)
            $todayLogFile = __DIR__ . '/print_logs/' . date('Y-m-d') . '.csv';
            $todayRows = [];
            if (is_file($todayLogFile)) {
                $fh = @fopen($todayLogFile, 'r');
                if ($fh) {
                    $hdr = @fgetcsv($fh, 0, ';');
                    if (is_array($hdr) && count($hdr) > 0) {
                        while (($r = @fgetcsv($fh, 0, ';')) !== false) {
                            if (!is_array($r) || count($r) === 0) continue;
                            if (count($r) < count($hdr)) $r = array_pad($r, count($hdr), '');
                            $assoc = array_combine($hdr, array_slice($r, 0, count($hdr)));
                            if (is_array($assoc)) {
                                $todayRows[] = $assoc;
                            }
                        }
                    }
                    @fclose($fh);
                }
            }

            $todayCount = count($todayRows);
            $showAllShipments = isset($_GET['ship_all']) && (string)$_GET['ship_all'] === '1';

            // default: posledních 15, po kliknutí zobrazit vše
            $todayRowsDisplay = $todayRows;
            if (!$showAllShipments && $todayCount > 15) {
                $todayRowsDisplay = array_slice($todayRows, -15);
            }
            ?>

            <div style="margin-top:26px;">
                <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <h2 style="margin:0 0 10px; font-size:18px; color:#1b5e20;">Dnešní zásilky – evidence (<?php echo (int)$todayCount; ?>)</h2>

                    <?php if ($todayCount > 15): ?>
                        <?php if (!$showAllShipments): ?>
                            <a href="index.php?view=print&amp;ship_all=1" class="btn-full" style="text-decoration:none; padding:10px 14px;">Zobrazit všechny</a>
                        <?php else: ?>
                            <a href="index.php?view=print" class="btn-full" style="text-decoration:none; padding:10px 14px;">Zavřít (zobrazit jen 15)</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <form method="get" action="print_export_today.php" target="_blank" style="margin-bottom:10px;">
                    <button type="submit" class="btn-full">Stáhnout CSV (dnes) – Balíkovna</button>
                </form>

                <?php if ($todayCount > 0): ?>
                    <style>
                        /* Evidence (dnes) – držet vše v 1 řádku, adresa jako rozbalovací detail */
                        .stats-evidence-table th,
                        .stats-evidence-table td {
                            white-space: nowrap;
                            overflow: hidden;
                            text-overflow: ellipsis;
                        }
                        .stats-evidence-table td.col-addr { text-align:left; }
                        .stats-evidence-table td.col-addr .stats-name-btn { width:auto; }
                        .stats-evidence-table td a { white-space: nowrap; }

                        /* Tracking – nezkracovat (GLS bývá delší) */
                        .stats-evidence-table td.col-tracking,
                        .stats-evidence-table td.col-tracking * {
                            overflow: visible !important;
                            text-overflow: clip !important;
                            max-width: none !important;
                        }
                    </style>

                    <div class="table-wrap">
                        <table class="stats-table stats-evidence-table">
                            <thead>
                                <tr>
                                    <th style="width:50px;">ID</th>
                                    <th style="width:120px;">Čas</th>
                                    <th style="width:90px;">Objednávka</th>
                                    <th style="width:95px;">Dopravce</th>
                                    <th style="width:70px;">Služba</th>
                                    <th style="width:260px;">Tracking</th>
                                    <th style="width:70px;">Dobírka</th>
                                    <th style="width:140px;">Jméno</th>
                                    <th style="width:90px;">Adresa</th>
                                    <th style="width:110px;">Město</th>
                                    <th style="width:75px;">PSČ</th>
                                    <th style="width:120px;">Telefon</th>
                                    <th style="width:220px;">Email</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowIndex = 0; foreach ($todayRowsDisplay as $r): $rowIndex++; $detailId = 'shipdetail-' . $rowIndex; ?>
                                    <?php
                                        $street = trim((string)($r['Ulice'] ?? ''));
                                        $city   = trim((string)($r['Město'] ?? ''));
                                        $zipc   = trim((string)($r['PSČ'] ?? ''));
                                        $phone  = trim((string)($r['Telefon'] ?? ''));
                                        $email  = trim((string)($r['Email'] ?? ''));
                                        $addrExists = ($street !== '' || $city !== '' || $zipc !== '' || $phone !== '' || $email !== '');

                                        $trackingRaw = (string)($r['Tracking'] ?? '');
                                        $trackingOne = preg_replace('/\s+/', '', $trackingRaw);
                                    ?>
                                    <tr>
                                        <td><?php echo h($r['ID'] ?? ''); ?></td>
                                        <td><?php echo h($r['Čas'] ?? ''); ?></td>
                                        <td><?php echo h($r['Objednávka'] ?? ''); ?></td>
                                        <td><?php echo h($r['Dopravce'] ?? ''); ?></td>
                                        <td><?php echo h($r['Služba'] ?? ''); ?></td>
                                        <td class="col-tracking"><span title="<?php echo h($trackingOne); ?>"><?php echo h($trackingOne); ?></span></td>
                                        <td><?php echo h($r['Dobírka'] ?? ''); ?></td>
                                        <td><?php echo h($r['Jméno'] ?? ''); ?></td>
                                        <td class="col-addr">
                                            <?php if ($addrExists): ?>
                                                <button type="button" class="stats-name-btn ship-toggle" data-detail-id="<?php echo h($detailId); ?>">Adresa</button>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo h($r['Město'] ?? ''); ?></td>
                                        <td><?php echo h($r['PSČ'] ?? ''); ?></td>
                                        <td><?php echo h($r['Telefon'] ?? ''); ?></td>
                                        <td><?php echo h($r['Email'] ?? ''); ?></td>
                                    </tr>
                                    <tr id="<?php echo h($detailId); ?>" class="stats-detail-row">
                                        <td colspan="13" class="stats-detail-cell">
                                            <strong>Adresa:</strong>
                                            <?php
                                                $addrLine = trim($street);
                                                $cityZip  = trim(($zipc !== '' ? $zipc . ' ' : '') . $city);
                                                if ($cityZip !== '') $addrLine .= ($addrLine !== '' ? ', ' : '') . $cityZip;
                                                echo h($addrLine !== '' ? $addrLine : '—');
                                            ?>
                                            <?php if ($phone !== ''): ?> &nbsp; | &nbsp; <strong>Telefon:</strong> <?php echo h($phone); ?><?php endif; ?>
                                            <?php if ($email !== ''): ?> &nbsp; | &nbsp; <strong>Email:</strong> <?php echo h($email); ?><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <script>
                    (function(){
                        var btns = document.querySelectorAll('.ship-toggle');
                        btns.forEach(function(btn){
                            btn.addEventListener('click', function(e){
                                e.stopPropagation();
                                var id = btn.getAttribute('data-detail-id');
                                if (!id) return;
                                var row = document.getElementById(id);
                                if (!row) return;
                                row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
                            });
                        });
                    })();
                    </script>
<?php else: ?>
                    <div class="msg">Dnes zatím není v evidenci žádná zásilka.</div>
                <?php endif; ?>
            </div>


            <?php
            // ======================================================
            // Historie zásilek (evidence z /print_logs/* mimo dnešek)
            // ======================================================
            $showShipHistory = isset($_GET['ship_hist']) && (string)$_GET['ship_hist'] === '1';
            $shipHistQ = isset($_GET['ship_hist_q']) ? trim((string)$_GET['ship_hist_q']) : '';
            $shipHistPage = isset($_GET['ship_hist_page']) ? (int)$_GET['ship_hist_page'] : 1;
            if ($shipHistPage < 1) $shipHistPage = 1;
            $shipHistPerPage = 50;

            // helper: bezpečné načtení jednoho CSV souboru (s hlavičkou)
            $readLogFile = function(string $file): array {
                $out = [];
                if (!is_file($file)) return $out;
                $fh = @fopen($file, 'r');
                if (!$fh) return $out;
                $hdr = @fgetcsv($fh, 0, ';');
                if (!is_array($hdr) || count($hdr) === 0) { @fclose($fh); return $out; }
                while (($r = @fgetcsv($fh, 0, ';')) !== false) {
                    if (!is_array($r) || count($r) === 0) continue;
                    if (count($r) < count($hdr)) $r = array_pad($r, count($hdr), '');
                    $assoc = array_combine($hdr, array_slice($r, 0, count($hdr)));
                    if (is_array($assoc)) $out[] = $assoc;
                }
                @fclose($fh);
                return $out;
            };

            $shipHistTotal = 0;
            $shipHistRows  = [];
            $shipHistPages = 1;

            if ($showShipHistory) {
                $logDir = __DIR__ . '/print_logs';
                $todayName = date('Y-m-d') . '.csv';

                $files = [];
                if (is_dir($logDir)) {
                    foreach (@scandir($logDir) ?: [] as $fn) {
                        if ($fn === '.' || $fn === '..') continue;
                        if (substr($fn, -4) !== '.csv') continue;
                        if ($fn === $todayName) continue; // dnešek je nahoře
                        $files[] = $fn;
                    }
                }

                // novější nahoře
                rsort($files);

                $q = mb_strtolower($shipHistQ, 'UTF-8');
                $qTerms = ($q !== '') ? preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) : [];
                $start = ($shipHistPage - 1) * $shipHistPerPage;
                $end   = $start + $shipHistPerPage;

                foreach ($files as $fn) {
                    $file = $logDir . '/' . $fn;
                    $day = preg_replace('/\.csv$/', '', $fn);
                    $rows = $readLogFile($file);
                    // v rámci dne chceme novější nahoře
                    if (!empty($rows)) $rows = array_reverse($rows);
                    foreach ($rows as $r) {
                        // vyhledávání přes více polí
                        if (!empty($qTerms)) {
                            $hay = [];
                            $hay[] = (string)($r['Objednávka'] ?? '');
                            $hay[] = (string)($r['Tracking'] ?? '');
                            $hay[] = (string)($r['Jméno'] ?? '');
                            $hay[] = (string)($r['Ulice'] ?? '');
                            $hay[] = (string)($r['Město'] ?? '');
                            $hay[] = (string)($r['PSČ'] ?? '');
                            $hay[] = (string)($r['Telefon'] ?? '');
                            $hay[] = (string)($r['Email'] ?? '');
                            $hayStr = mb_strtolower(implode(' | ', $hay), 'UTF-8');
                            foreach ($qTerms as $t) {
                                if ($t === '') continue;
                                if (mb_strpos($hayStr, $t) === false) {
                                    continue 2;
                                }
                            }
                        }

                        if ($shipHistTotal >= $start && $shipHistTotal < $end) {
                            $r['_Datum'] = $day;
                            $shipHistRows[] = $r;
                        }
                        $shipHistTotal++;
                    }
                }

                $shipHistPages = max(1, (int)ceil($shipHistTotal / $shipHistPerPage));
                if ($shipHistPage > $shipHistPages) $shipHistPage = $shipHistPages;
            }

            // URL helper pro historii (zachovat ship_all, ale vyčistit ship_hist_page podle potřeby)
            $shipAllParam = ($showAllShipments ? '&ship_all=1' : '');
            $histBase = 'index.php?view=print' . $shipAllParam . '&ship_hist=1';
            $histBaseQ = $histBase . '&ship_hist_q=' . urlencode($shipHistQ);
            ?>

            <div style="margin-top:16px;">
                <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <h2 style="margin:0 0 10px; font-size:18px; color:#1b5e20;">Historie zásilek</h2>
                    <?php if (!$showShipHistory): ?>
                        <a href="index.php?view=print<?php echo $shipAllParam; ?>&amp;ship_hist=1" class="btn-full" style="text-decoration:none; padding:10px 14px;">Zobrazit historii</a>
                    <?php else: ?>
                        <a href="index.php?view=print<?php echo $shipAllParam; ?>" class="btn-full" style="text-decoration:none; padding:10px 14px;">Sbalit</a>
                    <?php endif; ?>
                </div>

                <?php if ($showShipHistory): ?>
                    <form method="get" action="index.php" style="margin:0 0 10px;">
                        <input type="hidden" name="view" value="print">
                        <?php if ($showAllShipments): ?><input type="hidden" name="ship_all" value="1"><?php endif; ?>
                        <input type="hidden" name="ship_hist" value="1">

                        <div class="orders-controls" style="gap:12px; align-items:flex-end;">
                            <div class="orders-controls-left" style="min-width:280px; flex:1;">
                                <label for="ship_hist_q" class="stats-label">Hledat (jméno, příjmení, tracking, adresa, PSČ, město…)</label>
                                <input
                                    type="text"
                                    id="ship_hist_q"
                                    name="ship_hist_q"
                                    class="orders-search-input"
                                    value="<?php echo h($shipHistQ); ?>"
                                    placeholder="např. Novák / 4410... / 67401 / Třebíč"
                                >
                            </div>
                            <div>
                                <button type="submit" class="btn-full" style="padding:10px 14px;">Hledat</button>
                            </div>
                        </div>
                    </form>

                    <?php if ($shipHistTotal > 0): ?>
                        <div class="table-wrap">
                            <table class="stats-table stats-evidence-table">
                                <thead>
                                    <tr>
                                        <th style="width:95px;">Datum</th>
                                        <th style="width:50px;">ID</th>
                                        <th style="width:120px;">Čas</th>
                                        <th style="width:90px;">Objednávka</th>
                                        <th style="width:95px;">Dopravce</th>
                                        <th style="width:70px;">Služba</th>
                                        <th style="width:260px;">Tracking</th>
                                        <th style="width:70px;">Dobírka</th>
                                        <th style="width:160px;">Jméno</th>
                                        <th style="width:90px;">Adresa</th>
                                        <th style="width:110px;">Město</th>
                                        <th style="width:75px;">PSČ</th>
                                        <th style="width:120px;">Telefon</th>
                                        <th style="width:220px;">Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $hRowIndex = 0; foreach ($shipHistRows as $r): $hRowIndex++; $detailId = 'shiphistdetail-' . $hRowIndex; ?>
                                        <?php
                                            $street = trim((string)($r['Ulice'] ?? ''));
                                            $city   = trim((string)($r['Město'] ?? ''));
                                            $zipc   = trim((string)($r['PSČ'] ?? ''));
                                            $phone  = trim((string)($r['Telefon'] ?? ''));
                                            $email  = trim((string)($r['Email'] ?? ''));
                                            $addrExists = ($street !== '' || $city !== '' || $zipc !== '' || $phone !== '' || $email !== '');

                                            $trackingRaw = (string)($r['Tracking'] ?? '');
                                            $trackingOne = preg_replace('/\s+/', '', $trackingRaw);
                                        ?>
                                        <tr>
                                            <td><?php echo h($r['_Datum'] ?? ''); ?></td>
                                            <td><?php echo h($r['ID'] ?? ''); ?></td>
                                            <td><?php echo h($r['Čas'] ?? ''); ?></td>
                                            <td><?php echo h($r['Objednávka'] ?? ''); ?></td>
                                            <td><?php echo h($r['Dopravce'] ?? ''); ?></td>
                                            <td><?php echo h($r['Služba'] ?? ''); ?></td>
                                            <td class="col-tracking"><span title="<?php echo h($trackingOne); ?>"><?php echo h($trackingOne); ?></span></td>
                                            <td><?php echo h($r['Dobírka'] ?? ''); ?></td>
                                            <td><?php echo h($r['Jméno'] ?? ''); ?></td>
                                            <td class="col-addr">
                                                <?php if ($addrExists): ?>
                                                    <button type="button" class="stats-name-btn shiphist-toggle" data-detail-id="<?php echo h($detailId); ?>">Adresa</button>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo h($r['Město'] ?? ''); ?></td>
                                            <td><?php echo h($r['PSČ'] ?? ''); ?></td>
                                            <td><?php echo h($r['Telefon'] ?? ''); ?></td>
                                            <td><?php echo h($r['Email'] ?? ''); ?></td>
                                        </tr>
                                        <tr id="<?php echo h($detailId); ?>" class="stats-detail-row">
                                            <td colspan="14" class="stats-detail-cell">
                                                <strong>Adresa:</strong>
                                                <?php
                                                    $addrLine = trim($street);
                                                    $cityZip  = trim(($zipc !== '' ? $zipc . ' ' : '') . $city);
                                                    if ($cityZip !== '') $addrLine .= ($addrLine !== '' ? ', ' : '') . $cityZip;
                                                    echo h($addrLine !== '' ? $addrLine : '—');
                                                ?>
                                                <?php if ($phone !== ''): ?> &nbsp; | &nbsp; <strong>Telefon:</strong> <?php echo h($phone); ?><?php endif; ?>
                                                <?php if ($email !== ''): ?> &nbsp; | &nbsp; <strong>Email:</strong> <?php echo h($email); ?><?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($shipHistPages > 1): ?>
                            <div class="stats-summary" style="margin-top:10px;">
                                <?php if ($shipHistPage > 1): ?>
                                    <a href="<?php echo h($histBaseQ . '&ship_hist_page=' . ($shipHistPage - 1)); ?>">◀ Předchozí</a>
                                <?php endif; ?>
                                &nbsp;
                                <span style="opacity:.8;">Strana <?php echo (int)$shipHistPage; ?> / <?php echo (int)$shipHistPages; ?> (<?php echo (int)$shipHistTotal; ?> záznamů)</span>
                                &nbsp;
                                <?php if ($shipHistPage < $shipHistPages): ?>
                                    <a href="<?php echo h($histBaseQ . '&ship_hist_page=' . ($shipHistPage + 1)); ?>">Další ▶</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="stats-summary" style="margin-top:10px; opacity:.8;">(<?php echo (int)$shipHistTotal; ?> záznamů)</div>
                        <?php endif; ?>

                        <script>
                        (function(){
                            // znovu použijeme stejný toggle jako u dnešní evidence
                            var btns = document.querySelectorAll('.shiphist-toggle');
                            btns.forEach(function(btn){
                                btn.addEventListener('click', function(e){
                                    e.stopPropagation();
                                    var id = btn.getAttribute('data-detail-id');
                                    if (!id) return;
                                    var row = document.getElementById(id);
                                    if (!row) return;
                                    row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
                                });
                            });
                        })();
                        </script>

                    <?php else: ?>
                        <div class="msg">V historii nejsou žádné záznamy<?php echo ($shipHistQ !== '' ? ' pro zadané hledání.' : '.'); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

<div style="margin-top:26px;">
                <h2 style="margin:0 0 10px; font-size:18px; color:#1b5e20;">Dohledání doručení / vyzvednutí (podle čísla objednávky)</h2>

                <div class="orders-controls" style="gap:12px; align-items:flex-end;">
                    <div class="orders-controls-left" style="min-width:280px;">
                        <label for="pickupOrder" class="stats-label">Číslo objednávky</label>
                        <input type="text" id="pickupOrder" class="orders-search-input" placeholder="např. 1735101282">
                    </div>
                    <div>
                        <button type="button" id="pickupBtn" class="btn-full" style="padding:10px 14px;">Zjistit</button>
                    </div>
                </div>

                <div id="pickupResult" class="msg" style="margin-top:10px; display:none;"></div>
            
                <hr style="margin:18px 0; border:none; border-top:1px solid #e5e7eb;">
                <h2 style="margin:0 0 10px;">SMS notifikace (Android)</h2>

                <?php if (isset($_GET['sms_saved'])): ?>
                    <div class="msg <?php echo ($_GET['sms_saved'] === '1') ? 'ok' : 'err'; ?>" style="display:block;">
                        <?php echo ($_GET['sms_saved'] === '1') ? 'SMS nastavení uloženo.' : 'Nepodařilo se uložit SMS nastavení (zkontroluj práva na soubor sms/sms_settings.json).'; ?>
                    </div>
                <?php endif; ?>

                <?php
                    $smsS = is_array($smsSettings ?? null) ? $smsSettings : [];
                    $smsEnabled = !empty($smsS['enabled']);
                    $smsTestMode = !empty($smsS['test_mode']);
                    $smsTestPhone = (string)($smsS['test_phone'] ?? '+420604524524');
                    $smsDailyLimit = (int)($smsS['daily_limit'] ?? 40);
                    $smsApiToken = (string)($smsS['api_token'] ?? '');
                    $smsTpl = (string)($smsS['template'] ?? 'Dobrý den, objednávka z C-Store.cz byla vyřízena. Číslo zásilky: {TRACKING}');
                    $smsOnlyIfTracking = !empty($smsS['send_only_if_tracking']);
                ?>

                <form method="post" action="index.php?view=print" style="margin-top:10px;">
                    <input type="hidden" name="action" value="save_sms_settings">

                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:8px 0;">
                        <input type="checkbox" name="sms_enabled" value="1" <?php echo $smsEnabled ? 'checked' : ''; ?>>
                        <span><b>Zapnuto</b> (po vytištění štítku se vytvoří SMS ve frontě)</span>
                    </label>

                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:8px 0;">
                        <input type="checkbox" name="sms_test_mode" value="1" <?php echo $smsTestMode ? 'checked' : ''; ?>>
                        <span><b>TEST MODE</b> – všechny SMS se přesměrují na test číslo</span>
                    </label>

                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                        <div style="flex:1;min-width:260px;">
                            <label for="sms_test_phone">Testovací číslo</label>
                            <input id="sms_test_phone" class="ean-input" type="text" name="sms_test_phone" value="<?php echo h($smsTestPhone); ?>">
                        </div>

                        <div style="width:160px;">
                            <label for="sms_daily_limit">Limit / den</label>
                            <input id="sms_daily_limit" class="ean-input" type="number" name="sms_daily_limit" value="<?php echo h((string)$smsDailyLimit); ?>" min="1" max="500">
                        </div>
                    </div>

                    <div style="margin-top:10px;">
                        <label for="sms_template">Text SMS (šablona)</label>
                        <textarea id="sms_template" name="sms_template" style="width:100%;min-height:70px;border:1px solid #e5e7eb;border-radius:12px;padding:10px;font-size:15px;"><?php echo h($smsTpl); ?></textarea>
                        <div style="font-size:12px;opacity:.8;margin-top:6px;">
                            Použitelné proměnné: <b>{TRACKING}</b>, <b>{ORDER}</b>, {CARRIER}.
                        </div>
                    </div>

                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:8px 0;">
                        <input type="checkbox" name="sms_only_if_tracking" value="1" <?php echo $smsOnlyIfTracking ? 'checked' : ''; ?>>
                        <span>Poslat jen pokud se podaří zjistit tracking</span>
                    </label>

                    <div style="margin-top:10px;">
                        <label for="sms_api_token">API token (Android app)</label>
                        <input id="sms_api_token" class="ean-input" type="text" name="sms_api_token" value="<?php echo h($smsApiToken); ?>">
                        <div style="font-size:12px;opacity:.8;margin-top:6px;">
                            Android volá: <b>/sms_api/next.php</b> a hlásí výsledky na <b>/sms_api/report.php</b>.
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <button type="submit" class="btn" style="padding:10px 14px;">Uložit SMS nastavení</button>
                    </div>
                </form>
</div>

            <script>
            (function(){
                function el(id){ return document.getElementById(id); }
                var btn = el('pickupBtn');
                var inp = el('pickupOrder');
                var out = el('pickupResult');
                if(!btn || !inp || !out) return;

                function show(cls, html){
                    out.className = 'msg ' + (cls || '');
                    out.style.display = 'block';
                    out.innerHTML = html;
                }

                function run(){
                    var v = (inp.value || '').trim();
                    if(!v){ show('msg-error', 'Zadej číslo objednávky.'); inp.focus(); return; }
                    show('', 'Načítám…');
                    fetch('print_pickup_lookup.php?order=' + encodeURIComponent(v), {credentials:'same-origin'})
                      .then(function(r){ return r.json(); })
                      .then(function(j){
                          if(!j || !j.ok){
                              show('msg-error', (j && j.error) ? j.error : 'Nepodařilo se dohledat zásilku.');
                              return;
                          }
                          var line1 = '<b>Objednávka:</b> ' + (j.order || v);
                          var line2 = (j.customer ? ('<br><b>Zákazník:</b> ' + j.customer) : '');
                          var line3 = '';
                          if(j.event && j.datetime_human){
                              line3 = '<br><b>' + j.event + ':</b> ' + j.datetime_human;
                          } else if (j.status_text){
                              line3 = '<br><b>Stav:</b> ' + j.status_text + (j.datetime_human ? (' (' + j.datetime_human + ')') : '');
                          }
                          var line4 = (j.carrier ? ('<br><b>Dopravce:</b> ' + j.carrier) : '') + (j.tracking ? ('<br><b>Tracking:</b> ' + j.tracking) : '');
                          show('msg-ok', line1 + line2 + line3 + line4);
                      })
                      .catch(function(e){
                          show('msg-error', 'Chyba: ' + (e && e.message ? e.message : e));
                      });
                }

                btn.addEventListener('click', run);
                inp.addEventListener('keydown', function(e){
                    if(e.key === 'Enter'){ e.preventDefault(); run(); }
                });
            })();
            </script>


            <script>
            (function() {
                var statusVal = <?php echo json_encode($status); ?>;
                var eanVal    = <?php echo json_encode($ean); ?>;
                var input     = document.getElementById('ean');
                var codChk    = document.getElementById('cod_override_on');
                var codWrap   = document.getElementById('cod_override_wrap');
                if (codChk && codWrap) {
                    codChk.addEventListener('change', function () {
                        codWrap.style.display = this.checked ? '' : 'none';
                    });
                }
                if (statusVal === 'ok' && eanVal) {
                    try {
                        var url = 'label.php?ean=' + encodeURIComponent(eanVal);
                        var codOverrideOn = <?php echo json_encode($codOverrideOn); ?>;
                        var codOverrideRaw = <?php echo json_encode($codOverrideRaw); ?>;
                        if (codOverrideOn) {
                            url += '&cod_override_on=1&cod_override=' + encodeURIComponent(codOverrideRaw);
                        }
                        window.open(url, '_blank');
                    } catch (e) { console.error(e); }

                    setTimeout(function () {
                        window.location.href = 'index.php?view=print';
                    }, 10000);
                } else if (input) {
                    input.focus();
                    input.select();
                }
            })();
            </script>

        <?php elseif ($view === 'orders'): ?>

            <div class="card card-orders">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Objednávky</h1>

                <form method="get" action="index.php">
                    <input type="hidden" name="view" value="orders">
                    <?php if ($ordersFilterEmail !== ''): ?>
                        <input type="hidden" name="email" value="<?php echo h($ordersFilterEmail); ?>">
                    <?php endif; ?>
                    <?php if ($ordersPage > 1): ?>
                        <input type="hidden" name="page" value="<?php echo (int)$ordersPage; ?>">
                    <?php endif; ?>

                    <div class="orders-controls">
                        <div class="orders-controls-left">
                            <label for="orders-q" class="stats-label">Vyhledat</label>
                            <input
                                type="text"
                                id="orders-q"
                                name="q"
                                class="orders-search-input"
                                value="<?php echo h($ordersSearch); ?>"
                                placeholder="Číslo objednávky, jméno, e-mail, telefon, produkt…"
                            >
                        </div>
                        <div class="orders-controls-middle">
                            <label class="stats-label">Období</label>
                            <div class="orders-date-range">
                                <input
                                    type="date"
                                    name="from"
                                    value="<?php echo h($ordersDateFromStr); ?>"
                                >
                                <span>–</span>
                                <input
                                    type="date"
                                    name="to"
                                    value="<?php echo h($ordersDateToStr); ?>"
                                >
                            </div>
                        </div>
                        <div class="orders-controls-right">
                            <button type="submit">HLEDAT</button>
                            <button type="submit" name="export" value="silvini">EXPORT SILVINI DAT</button>
                            <button
                                type="submit"
                                name="action"
                                value="orders_fill_missing_supplier_feeds"
                                formmethod="post"
                                formaction="index.php?view=orders"
                            >AKTUALIZOVAT A DOPLNIT EAN + CENY</button>
                            <a href="index.php?view=orders" style="text-decoration:none;">
                                <button type="button">Zpět</button>
                            </a>
                        </div>
                    </div>
                </form>

                <?php if ($ordersFillFlash !== ''): ?>
                    <?php $isOrdersFillError = (mb_strpos($ordersFillFlash, 'CHYBA:') === 0); ?>
                    <div class="msg <?php echo $isOrdersFillError ? 'msg-error' : 'msg-ok'; ?>"><?php echo h($ordersFillFlash); ?></div>
                <?php endif; ?>

                <?php if ($ordersError !== ''): ?>
                    <div class="msg msg-error"><?php echo h($ordersError); ?></div>
                <?php else: ?>
                    <?php
                    $totalPages = $ordersPerPage > 0 ? (int)ceil($ordersTotal / $ordersPerPage) : 1;
                    if ($totalPages < 1) $totalPages = 1;
                    ?>
                    <?php if ($ordersTotal > 0): ?>
                        <div class="stats-summary">
                            Objednávek celkem: <?php echo (int)$ordersTotal; ?>,
                            strana <?php echo (int)$ordersPage; ?> / <?php echo $totalPages; ?>
                            <?php if ($ordersFilterEmail !== ''): ?>
                                – filtrováno podle zákazníka: <?php echo h($ordersFilterEmail); ?>
                            <?php endif; ?>
                        </div>
                        <div class="stats-summary">
                            Období: 
                            <?php echo h($ordersDateFromStr); ?>
                            –
                            <?php echo h($ordersDateToStr); ?>,
                            součet zisku (vybrané období): 
                            <?php echo number_format($ordersProfitSum, 2, ',', ' '); ?> Kč
                        </div>

                        <div class="table-wrap">
                            <table class="stats-table orders-table">
                                <thead>
                                <tr>
                                    <th class="orders-col-date">Datum</th>
                                    <th class="orders-col-profit">Zisk</th>
                                    <th class="orders-col-total">Celkem</th>
                                    <th class="orders-col-purchase">Nákupní cena</th>
                                    <th class="orders-col-name">Jméno</th>
                                    <th class="orders-col-email">E-mail</th>
                                    <th class="orders-col-phone">Telefon</th>
                                    <th class="orders-col-order">Číslo objednávky</th>
                                    <th class="orders-col-invoice">Číslo faktury</th>
                                    <th class="orders-col-payment">Platba</th>
                                    <th class="orders-col-delivery">Doprava</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($ordersRows as $row): ?>
                                    <?php
                                    $idOrder = (int)($row['id_order'] ?? 0);

                                    $createdDisplay = '';
                                    if (!empty($row['created_at'])) {
                                        try {
                                            $dt = new DateTime($row['created_at']);
                                            $createdDisplay = $dt->format('d.m.Y H:i');
                                        } catch (Exception $e) {
                                            $createdDisplay = (string)$row['created_at'];
                                        }
                                    }

                                    $name   = trim((string)($row['customer_name'] ?? ''));
                                    $email  = trim((string)($row['customer_email'] ?? ''));
                                    $phone  = trim((string)($row['customer_phone'] ?? ''));
                                    $orderNumber   = trim((string)($row['number'] ?? ''));
                                    $orderNumberDisplay = $orderNumber !== '' ? $orderNumber : '—';
                                    if (mb_strlen($orderNumberDisplay, 'UTF-8') > 11) {
                                        $orderNumberDisplay = mb_substr($orderNumberDisplay, 0, 11, 'UTF-8');
                                    }
                                    $invoiceNumber = trim((string)($row['invoice_number'] ?? ''));
                                    $totalPrice    = isset($row['total_price_with_vat']) ? (float)$row['total_price_with_vat'] : 0.0;

                                    // měna + kurz (pokud máme) – zobrazení částek správně CZK/EUR
                                    $currencyCode = 'CZK';
                                    if (isset($row['currency_code']) && trim((string)$row['currency_code']) !== '') {
                                        $currencyCode = strtoupper(trim((string)$row['currency_code']));
                                    }
                                    $exchangeRate = isset($row['exchange_rate']) ? (float)$row['exchange_rate'] : 0.0;

                                    // Předpoklad: DB ukládá částky v CZK. Pokud je měna EUR a máme kurz, přepočítáme do EUR.
                                    $fx = 1.0;
                                    $currencyDisplayCode = 'CZK';
                                    if ($currencyCode !== 'CZK' && $exchangeRate > 0) {
                                        $fx = 1.0 / $exchangeRate;
                                        $currencyDisplayCode = $currencyCode;
                                    }

                                    $totalPriceDisp = $totalPrice * $fx;


                                    $emailKey = mb_strtolower(trim($email), 'UTF-8');
                                    $emailCnt = $emailKey !== '' && isset($ordersEmailStats[$emailKey])
                                        ? (int)$ordersEmailStats[$emailKey]
                                        : 0;

                                    $dbPaid    = isset($row['zaplaceno']) && (string)$row['zaplaceno'] === 'A';
                                    $gopayPaid = isset($row['gopay_zaplaceno']) && (string)$row['gopay_zaplaceno'] === 'A';
                                    $isPaidOrder = $dbPaid || $gopayPaid;

                                    $paymentName   = trim((string)($row['payment_name'] ?? ''));
                                    $deliveryRaw   = trim((string)($row['delivery_name'] ?? ''));
                                    $deliveryLower = mb_strtolower($deliveryRaw, 'UTF-8');
                                    $isZasilkovna  = ($deliveryRaw !== '' && mb_strpos($deliveryLower, 'zasilkovna') !== false);

                                    $purchaseTotal = isset($ordersPurchaseById[$idOrder]) ? (float)$ordersPurchaseById[$idOrder] : 0.0;
                                    $profitTotal   = isset($ordersProfitById[$idOrder]) ? (float)$ordersProfitById[$idOrder] : ($totalPrice - $purchaseTotal);

                                    // zalomení jména na 2 řádky pokud je delší než 22 znaků
                                    $nameDisplay = $name !== '' ? $name : '—';
                                    if (mb_strlen($nameDisplay, 'UTF-8') > 22) {
                                        $first = mb_substr($nameDisplay, 0, 22, 'UTF-8');
                                        $rest  = mb_substr($nameDisplay, 22, null, 'UTF-8');
                                        $nameHtml = h($first) . '<br>' . h($rest);
                                    } else {
                                        $nameHtml = h($nameDisplay);
                                    }

                                    // zalomení telefonu na 2 řádky pokud je delší než 22 znaků
                                    $phoneDisplay = $phone !== '' ? $phone : '—';
                                    if (mb_strlen($phoneDisplay, 'UTF-8') > 22) {
                                        $firstP = mb_substr($phoneDisplay, 0, 22, 'UTF-8');
                                        $restP  = mb_substr($phoneDisplay, 22, null, 'UTF-8');
                                        $phoneHtml = h($firstP) . '<br>' . h($restP);
                                    } else {
                                        $phoneHtml = h($phoneDisplay);
                                    }
                                    ?>
                                    <tr class="order-main-row<?php echo (!empty($ordersMissingById[$idOrder]) ? ' has-missing' : ''); ?>" data-order-id="<?php echo $idOrder; ?>" onclick="toggleOrderDetail(<?php echo $idOrder; ?>);">
                                        <td class="orders-col-date"><?php echo h($createdDisplay); ?></td>
<td class="orders-col-profit<?php echo ($purchaseTotal > 0 && $profitTotal < 0 ? ' neg-profit' : ''); ?>">
                                            <?php if ($purchaseTotal > 0): ?>
                                                <?php echo fmt_money($profitTotal * $fx, $currencyDisplayCode); ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
<td class="orders-col-total"><?php echo fmt_money($totalPriceDisp, $currencyDisplayCode); ?></td>
<td class="orders-col-purchase">
                                            <?php if ($purchaseTotal > 0): ?>
                                                <?php echo fmt_money($purchaseTotal * $fx, $currencyDisplayCode); ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
<td class="orders-col-name">
                                            <?php echo $nameHtml; ?>
                                            <?php if ($emailKey !== '' && $emailCnt > 1): ?>
                                                <span class="orders-badge-email" data-email="<?php echo h($email); ?>">
                                                    (<?php echo (int)$emailCnt; ?>)
                                                </span>
                                            <?php endif; ?>
                                        </td>
<td class="orders-col-email"><?php echo $email !== '' ? h($email) : '—'; ?></td>
<td class="orders-col-phone"><?php echo $phoneHtml; ?></td>
<td class="orders-col-order"><?php echo h($orderNumberDisplay); ?></td>
<td class="orders-col-invoice">
                                            <?php
                                                $invUrl  = isset($row['invoice_url']) ? trim((string)$row['invoice_url']) : '';
                                                $invHtml = isset($row['invoice_url_html']) ? trim((string)$row['invoice_url_html']) : '';
                                            ?>
                                            <div class="inv-wrap">
                                                <div><?php echo $invoiceNumber !== '' ? h($invoiceNumber) : '—'; ?></div>
                                                <?php if ($invUrl !== '' || $invHtml !== ''): ?>
                                                    <div class="inv-links">
                                                        <?php if ($invHtml !== ''): ?>
                                                            <a class="inv-link" href="<?php echo h($invHtml); ?>" target="_blank" rel="noopener">HTML</a>
                                                        <?php endif; ?>
                                                        <?php if ($invUrl !== ''): ?>
                                                            <a class="inv-link" href="<?php echo h($invUrl); ?>" target="_blank" rel="noopener">PDF</a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
<td class="orders-col-payment">
                                            <span class="orders-paid-indicator <?php echo $isPaidOrder ? 'paid' : 'unpaid'; ?>"></span>
                                            <?php echo h($paymentName); ?>
                                        </td>
<td class="orders-col-delivery">
                                            <?php if ($isZasilkovna): ?>
                                                <span class="delivery-toggle" data-target="delivery-<?php echo $idOrder; ?>">Zásilkovna</span>
                                            <?php else: ?>
                                                <?php echo $deliveryRaw !== '' ? h($deliveryRaw) : '—'; ?>
                                            <?php endif; ?>
                                        </td>

                                    </tr>
                                    <tr id="order-detail-<?php echo $idOrder; ?>" class="order-detail-row">
                                        <td colspan="11" class="order-detail-cell">
                                            <?php if ($isZasilkovna && $deliveryRaw !== ''): ?>
                                                <div id="delivery-<?php echo $idOrder; ?>" class="order-delivery-note" style="display:none;">
                                                    <?php echo nl2br(h($deliveryRaw)); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php $items = isset($ordersItemsById[$idOrder]) ? $ordersItemsById[$idOrder] : []; ?>
                                            <?php if (!empty($items)): ?>
                                                <div class="table-wrap" style="margin-top:6px;">
                                                    <table class="order-items-table">
                                                        <thead>
                                                        <tr>
                                                            <th>Kód</th>
                                                            <th>Zisk</th>
                                                            <th>EAN</th>
                                                            <th>Produkt</th>
                                                            <th>Varianta</th>
                                                            <th>Ks</th>
                                                            <th>Nákupní cena (s DPH)</th>
                                                            <th>Řádek (s DPH)</th>
                                                        </tr>
                                                        </thead>
                                                        <tbody>
                                                        <?php foreach ($items as $it): ?>
                                                            <?php
                                                                $lineQty = isset($it['qty']) ? (float)$it['qty'] : 0.0;
                                                                $lineTotal = isset($it['total']) ? (float)$it['total'] : 0.0;
                                                                $linePurchase = isset($it['price_s_dph']) ? (float)$it['price_s_dph'] * $lineQty : 0.0;
                                                                $lineProfit = $lineTotal - $linePurchase;
                                                            ?>
                                                            <tr<?php echo (!empty($it['missing_purchase']) ? ' style="color:#c00;"' : ''); ?>>
                                                                <td><?php echo h($it['code']); ?></td>
                                                                <td<?php echo ($linePurchase > 0 && $lineProfit < 0 ? ' class="neg-profit"' : ''); ?>>
                                                                    <?php
                                                                    if ($linePurchase > 0) {
                                                                        echo fmt_money($lineProfit * $fx, $currencyDisplayCode);
                                                                    } else {
                                                                        echo '—';
                                                                    }
                                                                    ?>
                                                                </td>
                                                                <td><?php echo h($it['ean']); ?></td>
                                                                <td><?php echo h($it['name']); ?></td>
                                                                <td><?php echo h($it['variant']); ?></td>
                                                                <td><?php echo (float)$it['qty']; ?></td>
                                                                <td>
                                                                    <?php
                                                                    if (!empty($it['price_s_dph'])) {
                                                                        echo fmt_money(((float)$it['price_s_dph']) * $fx, $currencyDisplayCode);
                                                                    } else {
                                                                        echo '—';
                                                                    }
                                                                    ?>
                                                                </td>
<td><?php echo fmt_money(((float)$it['total']) * $fx, $currencyDisplayCode); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <em>Žádné položky objednávky k zobrazení.</em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalPages > 1): ?>
                            <div class="stats-summary" style="margin-top:10px;">
                                <?php if ($ordersPage > 1): ?>
                                    <a href="index.php?view=orders&amp;page=<?php echo $ordersPage - 1; ?>&amp;q=<?php echo urlencode($ordersSearch); ?>">◀ Předchozí</a>
                                <?php endif; ?>
                                &nbsp;
                                <?php if ($ordersPage < $totalPages): ?>
                                    <a href="index.php?view=orders&amp;page=<?php echo $ordersPage + 1; ?>&amp;q=<?php echo urlencode($ordersSearch); ?>">Další ▶</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="msg">Žádné objednávky pro aktuální filtr.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <script>
            (function() {
                window.toggleOrderDetail = function(id) {
                    var row  = document.getElementById('order-detail-' + id);
                    var main = document.querySelector('tr.order-main-row[data-order-id="' + id + '"]');
                    if (!row || !main) return;
                    var visible = row.style.display === 'table-row';
                    row.style.display = visible ? 'none' : 'table-row';
                    if (visible) {
                        main.classList.remove('selected');
                    } else {
                        main.classList.add('selected');
                    }
                };

                var emailBadges = document.querySelectorAll('.orders-badge-email');
                emailBadges.forEach(function(el) {
                    el.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var email = el.getAttribute('data-email');
                        if (!email) return;
                        var url = new URL(window.location.href);
                        url.searchParams.set('view', 'orders');
                        url.searchParams.set('email', email);
                        url.searchParams.delete('page');
                        url.searchParams.delete('q');
                        window.location.href = url.toString();
                    });
                });

                // OPRAVA: klik na "Zasilkovna" – otevře detail řádku a ukáže adresu
                var vatChecks = document.querySelectorAll('.service-vat-check');
                        vatChecks.forEach(function(cb) {
                            cb.addEventListener('change', function(e) {
                                e.stopPropagation();
                                try {
                                    var row = cb.closest('tr');
                                    var priceInput = row ? row.querySelector("input[name='purchase_price']") : null;
                                    var hidden = cb.form ? cb.form.querySelector("input[name='current_purchase_price']") : null;
                                    if (hidden && priceInput) {
                                        hidden.value = priceInput.value || '';
                                    }
                                } catch (err) {}
                                if (cb.form) cb.form.submit();
                            });
                            cb.addEventListener('click', function(e){ e.stopPropagation(); });
                        });

                        var deliveryToggles = document.querySelectorAll('.delivery-toggle');
                deliveryToggles.forEach(function(el) {
                    el.addEventListener('click', function(e) {
                        // NEZASTAVUJEME propagaci – tím se spustí onclick na řádku (toggleOrderDetail)
                        var targetId = el.getAttribute('data-target');
                        if (!targetId) return;
                        var box = document.getElementById(targetId);
                        if (!box) return;
                        box.style.display = (box.style.display === 'block') ? 'none' : 'block';
                    });
                });
            })();
            </script>

        <?php elseif ($view === 'stats'): ?>

            <div class="card">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Statistika položek</h1>

                <form method="get" action="index.php" id="stats-form">
                    <input type="hidden" name="view" value="stats">

                    <div class="stats-grid">
                        <div>
                            <div class="stats-label">Datum od (sloupec C)</div>
                            <input type="date" name="from" value="<?php echo h($dateFromStr); ?>">
                        </div>
                        <div>
                            <div class="stats-label">Datum do (sloupec C)</div>
                            <input type="date" name="to" value="<?php echo h($dateToStr); ?>">
                        </div>
                        <div>
                            <div class="stats-label">Značka (filtr podle Z)</div>
                            <select name="brand" id="brand-select">
                                <?php foreach ($brandOptions as $key => $label): ?>
                                    <option value="<?php echo h($key); ?>" <?php echo $brand === $key ? 'selected' : ''; ?>>
                                        <?php echo h($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <div class="stats-label">Vyhledat (kód / název)</div>
                            <input type="text" name="q" value="<?php echo h($statsSearch); ?>" placeholder="např. 190123, Active, Devold…">
                        </div>
                    </div>

                    <button type="submit" class="btn-full" name="mode" value="stat">ZOBRAZIT STATISTIKU</button>
                    <button type="submit" class="btn-full" name="mode" value="nocolor" style="margin-top:8px;">
                        PRODUKTY BEZ BARVY (CRAFT)
                    </button>
                </form>

                <?php if ($statsError !== ''): ?>
                    <div class="msg msg-error"><?php echo h($statsError); ?></div>

                <?php elseif (!empty($statsResult) || array_sum($brandSums) > 0): ?>
                    <?php
                    $chartLabels = [];
                    $chartValues = [];

                    $brandLabelMap = [
                        'craft'      => 'Craft',
                        'silvini'    => 'SILVINI',
                        'devold'     => 'Devold',
                        'ale'        => 'Alé',
                        'didriksons' => 'Didriksons',
                        'haglofs'    => 'HAGLÖFS',
                        'viking'     => 'Viking',
                        'isadore'    => 'Isadore',
                        'neon'       => 'Neon',
                        'lillsport'  => 'LILL-SPORT',
                        'inov8'      => 'INOV-8',
                        'silva'      => 'SILVA',
                        'karitraa'   => 'KARI TRAA',
                        'other'      => 'Ostatní',
                    ];

                    foreach ($brandSums as $bKey => $sumVal) {
                        if ($sumVal <= 0) continue;
                        $chartLabels[] = $brandLabelMap[$bKey] ?? $bKey;
                        $chartValues[] = round($sumVal, 2);
                    }

                    $modeText = isset($_GET['mode']) && $_GET['mode'] === 'nocolor'
                        ? ' (režim: produkty bez barvy – Y do „-“) '
                        : '';

                    $chartSummaryText = '';
                    if (!empty($chartLabels) && !empty($chartValues)) {
                        $totalChart = array_sum($chartValues);
                        if ($totalChart > 0) {
                            $parts = [];
                            foreach ($chartLabels as $idx => $label) {
                                $val = $chartValues[$idx] ?? 0;
                                if ($val <= 0) continue;
                                $pct = $val / $totalChart * 100;
                                $parts[] = $label . ' ' . number_format($pct, 1, ',', '') . ' %';
                            }
                            $chartSummaryText = implode(' | ', $parts);
                        }
                    }
                    ?>

                    <div class="stats-summary">
                        Součet všech položek (sloupec AD) pro uvedený filtr<?php echo h($modeText); ?>:
                        <?php echo number_format($statsTotalAD, 2, ',', ' '); ?> Kč
                    </div>

                    <button type="button" id="show-chart-btn" class="btn-full" style="margin-top:8px;">
                        ZOBRAZIT GRAF PODLE ZNAČEK
                    </button>

                    <div id="stats-chart-wrap">
                        <canvas id="stats-pie" height="260"></canvas>
                        <?php if ($chartSummaryText !== ''): ?>
                            <div id="stats-chart-summary" class="stats-summary">
                                <?php echo h($chartSummaryText); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($statsResult)): ?>
                        <div class="table-wrap">
                            <table class="stats-table">
                                <thead>
                                <tr>
                                    <th>Kód (Y)</th>
                                    <th>Název (Z)</th>
                                    <th>Počet kusů</th>
                                    <th>Kč (součet AC)</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                $rowIndex = 0;
                                $statsResultToShow = (isset($statsResultPage) && is_array($statsResultPage)) ? $statsResultPage : $statsResult;
                                foreach ($statsResultToShow as $code => $data):
                                    $rowIndex++;
                                    $detailId = 'detail-' . $rowIndex;
                                    ?>
                                    <tr>
                                        <td><?php echo h($code); ?></td>
                                        <td>
                                            <button
                                                type="button"
                                                class="stats-name-btn stats-toggle"
                                                data-detail-id="<?php echo h($detailId); ?>"
                                            >
                                                <?php echo h($data['name']); ?>
                                            </button>
                                        </td>
                                        <td><?php echo (int)$data['count']; ?></td>
                                        <td><?php echo number_format($data['sum'], 2, ',', ' '); ?></td>
                                    </tr>
                                    <tr id="<?php echo h($detailId); ?>" class="stats-detail-row">
                                        <td colspan="4" class="stats-detail-cell">
                                            <?php if (!empty($data['aa'])): ?>
                                                <strong>Detail prodeje:</strong>
                                                <ul class="stats-detail-list">
                                                    <?php foreach ($data['aa'] as $aaVal => $aaCount): ?>
                                                        <li><?php echo h($aaVal); ?> – <?php echo (int)$aaCount; ?> ks</li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <em>Žádná data ve sloupci AA.</em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!empty($statsResult) && isset($statsTotalPages) && $statsTotalPages > 1): ?>
                            <div class="stats-summary" style="margin-top:10px;">
                                <?php if ($statsPage > 1): ?>
                                    <a href="index.php?view=stats&amp;page=<?php echo $statsPage - 1; ?>&amp;from=<?php echo h($dateFromStr); ?>&amp;to=<?php echo h($dateToStr); ?>&amp;q=<?php echo urlencode($statsSearch); ?>&amp;brand=<?php echo h($brand); ?>&amp;mode=<?php echo h($mode); ?>">◀ Předchozí</a>
                                <?php endif; ?>
                                &nbsp;
                                <?php if ($statsPage < $statsTotalPages): ?>
                                    <a href="index.php?view=stats&amp;page=<?php echo $statsPage + 1; ?>&amp;from=<?php echo h($dateFromStr); ?>&amp;to=<?php echo h($dateToStr); ?>&amp;q=<?php echo urlencode($statsSearch); ?>&amp;brand=<?php echo h($brand); ?>&amp;mode=<?php echo h($mode); ?>">Další ▶</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="msg">Pro zadaný filtr nejsou žádné položky v tabulce, graf ale ukazuje poměr značek.</div>
                    <?php endif; ?>

                    <script>
                    function serviceTogglePurchaseVat(cb) {
                        if (!cb || !cb.form) return;
                        try {
                            var row = cb.closest('tr');
                            var priceInput = row ? row.querySelector("input[name='purchase_price']") : null;
                            var hidden = cb.form.querySelector("input[name='current_purchase_price']");
                            if (hidden && priceInput) {
                                hidden.value = priceInput.value || '';
                            }
                        } catch (e) {}
                        cb.form.submit();
                    }
                    (function() {
                        var buttons = document.querySelectorAll('.stats-toggle');
                        buttons.forEach(function(btn) {
                            btn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                var id = btn.getAttribute('data-detail-id');
                                if (!id) return;
                                var row = document.getElementById(id);
                                if (!row) return;
                                row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
                            });
                        });

                        var chartLabels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
                        var chartValues = <?php echo json_encode($chartValues); ?>;

                        var showBtn   = document.getElementById('show-chart-btn');
                        var chartWrap = document.getElementById('stats-chart-wrap');
                        var chartObj  = null;

                        if (showBtn && chartWrap && chartLabels.length > 0) {
                            showBtn.addEventListener('click', function() {
                                if (chartWrap.style.display === 'none' || chartWrap.style.display === '') {
                                    chartWrap.style.display = 'block';

                                    if (!chartObj) {
                                        var ctx = document.getElementById('stats-pie').getContext('2d');
                                        var baseColors = ['#2ecc71','#3498db','#9b59b6','#f1c40f','#e67e22','#e74c3c','#1abc9c','#34495e'];
                                        var bgColors = [];
                                        for (var i=0;i<chartLabels.length;i++) {
                                            bgColors.push(baseColors[i % baseColors.length]);
                                        }
                                        chartObj = new Chart(ctx, {
                                            type:'pie',
                                            data:{
                                                labels:chartLabels,
                                                datasets:[{ data:chartValues, backgroundColor:bgColors }]
                                            },
                                            options:{
                                                responsive:true,
                                                plugins:{
                                                    legend:{ position:'bottom', labels:{ boxWidth:12, font:{size:11} } },
                                                    tooltip:{
                                                        callbacks:{
                                                            label:function(context){
                                                                var label = context.label || '';
                                                                var value = context.parsed || 0;
                                                                return label + ': ' +
                                                                    value.toLocaleString('cs-CZ',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' Kč';
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        });
                                    }
                                } else {
                                    chartWrap.style.display = 'none';
                                }
                            });
                        } else if (showBtn && chartLabels.length === 0) {
                            showBtn.disabled = true;
                            showBtn.textContent = 'Pro tento filtr nejsou data pro graf';
                        }

                        var brandSelect = document.getElementById('brand-select');
                        if (brandSelect) {
                            brandSelect.addEventListener('change', function() {
                                var form = document.getElementById('stats-form');
                                if (form) form.submit();
                            });
                        }
                    })();
                    </script>

                <?php elseif ($dateFromStr !== '' || $dateToStr !== ''): ?>
                    <div class="msg">Pro zadaný filtr nejsou žádná data.</div>
                <?php else: ?>
                    <div class="msg">Zadej datum nebo rovnou zobraz statistiku bez filtru.</div>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'customers'): ?>

            <div class="card">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Zákazníci</h1>

                <form method="get" action="index.php">
                    <input type="hidden" name="view" value="customers">

                    <div class="stats-grid">
                        <div>
                            <div class="stats-label">Datum od (datum objednávky)</div>
                            <input type="date" name="from" value="<?php echo h($customersDateFromStr); ?>">
                        </div>
                        <div>
                            <div class="stats-label">Datum do (datum objednávky)</div>
                            <input type="date" name="to" value="<?php echo h($customersDateToStr); ?>">
                        </div>
                        <div>
                            <div class="stats-label">Řazení</div>
                            <select name="sort">
                                <option value="orders" <?php echo $customersSort === 'orders' ? 'selected' : ''; ?>>podle počtu objednávek</option>
                                <option value="value"  <?php echo $customersSort === 'value'  ? 'selected' : ''; ?>>podle hodnoty (Kč)</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn-full">ZOBRAZIT ZÁKAZNÍKY</button>
                </form>

                <?php if ($customersError !== ''): ?>
                    <div class="msg msg-error"><?php echo h($customersError); ?></div>

                <?php elseif (!empty($customersData)): ?>
                    <div class="stats-summary">
                        Zobrazeni zákazníci jsou seřazeni podle
                        <?php echo $customersSort === 'value'
                            ? 'celkové útraty a počtu objednávek.'
                            : 'počtu objednávek a celkové útraty.'; ?>
                    </div>

                    <?php if ($orderItemsError !== ''): ?>
                        <div class="msg msg-error" style="margin-top:8px;"><?php echo h($orderItemsError); ?></div>
                    <?php endif; ?>

                    <div class="table-wrap" style="margin-top:10px;">
                        <table class="stats-table">
                            <thead>
                            <tr>
                                <th>Zákazník</th>
                                <th>Telefon</th>
                                <th>Počet objednávek</th>
                                <th>Celkem (s DPH)</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            $cIndex = 0;
                            foreach ($customersData as $cust):
                                $cIndex++;
                                $detailId = 'cust-detail-' . $cIndex;
                                $emailId  = 'cust-email-' . $cIndex;
                                ?>
                                <tr>
                                    <td>
                                        <div class="customer-name-toggle" data-email-id="<?php echo h($emailId); ?>" style="cursor:pointer;font-weight:600;">
                                            <?php echo h($cust['name']); ?>
                                        </div>
                                        <?php if (!empty($cust['email'])): ?>
                                            <div id="<?php echo h($emailId); ?>" style="display:none;margin-top:2px;font-size:12px;">
                                                <?php echo h($cust['email']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h($cust['phone']); ?></td>
                                    <td>
                                        <button
                                            type="button"
                                            class="stats-name-btn customer-orders-toggle"
                                            data-detail-id="<?php echo h($detailId); ?>"
                                        >
                                            <?php echo (int)$cust['orders_count']; ?>
                                        </button>
                                    </td>
                                    <td><?php echo number_format((float)$cust['orders_total'], 2, ',', ' '); ?></td>
                                </tr>
                                <tr id="<?php echo h($detailId); ?>" class="stats-detail-row">
                                    <td colspan="4" class="stats-detail-cell">
                                        <?php if (!empty($cust['orders']) && $orderItemsError === ''): ?>
                                            <strong>Objednané položky:</strong>
                                            <div class="table-wrap" style="margin-top:6px;">
                                                <table class="stats-table">
                                                    <thead>
                                                    <tr>
                                                        <th>Objednávka</th>
                                                        <th>Datum</th>
                                                        <th>Kód produktu</th>
                                                        <th>Název</th>
                                                        <th>Varianta</th>
                                                        <th>Ks</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($cust['orders'] as $ord): ?>
                                                        <?php
                                                        $ordNum  = $ord['number'];
                                                        $ordDate = $ord['date'];
                                                        $items   = $orderItemsAvailable && isset($orderItemsByOrderNumber[$ordNum])
                                                            ? $orderItemsByOrderNumber[$ordNum] : [];
                                                        if (empty($items)) continue;
                                                        foreach ($items as $it):
                                                        ?>
                                                            <tr>
                                                                <td><?php echo h($ordNum); ?></td>
                                                                <td><?php echo h($ordDate); ?></td>
                                                                <td><?php echo h($it['code']); ?></td>
                                                                <td><?php echo h($it['name']); ?></td>
                                                                <td><?php echo h($it['variant']); ?></td>
                                                                <td><?php echo (float)$it['qty']; ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php elseif ($orderItemsError !== ''): ?>
                                            <?php echo h($orderItemsError); ?>
                                        <?php else: ?>
                                            <em>Žádné položky k zobrazení.</em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <script>
                    (function() {
                        var orderButtons = document.querySelectorAll('.customer-orders-toggle');
                        orderButtons.forEach(function(btn) {
                            btn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                var id = btn.getAttribute('data-detail-id');
                                if (!id) return;
                                var row = document.getElementById(id);
                                if (!row) return;
                                row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
                            });
                        });

                        var nameElems = document.querySelectorAll('.customer-name-toggle');
                        nameElems.forEach(function(el) {
                            el.addEventListener('click', function(e) {
                                e.stopPropagation();
                                var id = el.getAttribute('data-email-id');
                                if (!id) return;
                                var box = document.getElementById(id);
                                if (!box) return;
                                box.style.display = (box.style.display === 'block') ? 'none' : 'block';
                            });
                        });
                    })();
                    </script>

                <?php elseif ($customersDateFromStr !== '' || $customersDateToStr !== ''): ?>
                    <div class="msg">Pro zadaný filtr nejsou žádní zákazníci.</div>
                <?php else: ?>
                    <div class="msg">Zadej datum nebo zobraz top zákazníky bez filtru.</div>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'service'): ?>

            <div class="card">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Servis (C-servis)</h1>

                <form method="get" action="index.php">
                    <input type="hidden" name="view" value="service">
                    <div class="stats-grid">
                        <div>
                            <div class="stats-label">Datum od</div>
                            <input type="date" name="from" value="<?php echo h($serviceDateFromStr); ?>">
                        </div>
                        <div>
                            <div class="stats-label">Datum do</div>
                            <input type="date" name="to" value="<?php echo h($serviceDateToStr); ?>">
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:8px; margin-top:10px;">
                        <button type="submit" class="btn-full">ZOBRAZIT SERVIS</button>
                        <button type="submit" name="export" value="service_csv" class="btn-full">STÁHNOUT CSV</button>
                        <button type="submit" name="export" value="service_excel" class="btn-full">STÁHNOUT EXCEL</button>
                    </div>
                </form>

                <form method="post" action="index.php?view=service" style="margin-top:12px; padding:12px; border:1px solid #c8e6c9; border-radius:12px; background:#f8fff8;">
                    <input type="hidden" name="action" value="service_add_manual_row">
                    <input type="hidden" name="from" value="<?php echo h($serviceDateFromStr); ?>">
                    <input type="hidden" name="to" value="<?php echo h($serviceDateToStr); ?>">
                    <div class="stats-label" style="font-weight:700; margin-bottom:8px;">Přidat ruční řádek</div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:8px; align-items:end;">
                        <div>
                            <div class="stats-label">Jméno</div>
                            <input type="text" name="manual_name" placeholder="Jméno" required>
                        </div>
                        <div>
                            <div class="stats-label">Datum</div>
                            <input type="date" name="manual_date" value="<?php echo h($serviceDateToStr !== '' ? $serviceDateToStr : date('Y-m-d')); ?>" required>
                        </div>
                        <div>
                            <div class="stats-label">Číslo objednávky</div>
                            <input type="text" name="manual_order_number" placeholder="Číslo objednávky">
                        </div>
                        <div>
                            <div class="stats-label">Kód</div>
                            <input type="text" name="manual_code" placeholder="Kód">
                        </div>
                        <div>
                            <div class="stats-label">Nákupní cena bez DPH</div>
                            <input type="text" name="manual_purchase" data-service-manual-purchase placeholder="Cena bez DPH">
                        </div>
                        <div>
                            <div class="stats-label">Produkt</div>
                            <input type="text" name="manual_product" placeholder="Produkt" required>
                        </div>
                        <div>
                            <div class="stats-label">Varianta</div>
                            <input type="text" name="manual_variant" placeholder="Varianta">
                        </div>
                        <div>
                            <div class="stats-label">Ks</div>
                            <input type="text" name="manual_qty" value="1">
                        </div>
                        <div>
                            <div class="stats-label">Řádek (s DPH)</div>
                            <input type="text" name="manual_line_total" placeholder="Prodej s DPH" required>
                        </div>
                        <div>
                            <div class="stats-label">DPH</div>
                            <label style="display:flex; gap:6px; align-items:center; height:38px;">
                                <input type="checkbox" name="manual_vat" value="1" data-service-manual-vat>
                                <span>započítat DPH</span>
                            </label>
                        </div>
                        <div>
                            <div class="stats-label">Cena s DPH</div>
                            <input type="text" value="" placeholder="auto" readonly data-service-manual-price-vat>
                        </div>
                        <div>
                            <button type="submit" class="btn" style="width:100%; padding:10px 12px;">Přidat řádek</button>
                        </div>
                    </div>
                </form>

                <script>
                (function() {
                    var purchase = document.querySelector('[data-service-manual-purchase]');
                    var vat = document.querySelector('[data-service-manual-vat]');
                    var out = document.querySelector('[data-service-manual-price-vat]');
                    function parseCzNumber(v) {
                        v = String(v || '').replace(/\s+/g, '').replace(',', '.').replace(/[^0-9.\-]/g, '');
                        var n = parseFloat(v);
                        return isNaN(n) ? null : n;
                    }
                    function fmt(n) {
                        return n.toLocaleString('cs-CZ', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Kč';
                    }
                    function update() {
                        if (!purchase || !vat || !out) return;
                        var n = parseCzNumber(purchase.value);
                        out.value = (n !== null && vat.checked) ? fmt(n * 1.21) : '';
                    }
                    if (purchase) purchase.addEventListener('input', update);
                    if (vat) vat.addEventListener('change', update);
                    update();
                })();
                </script>

                <?php if ($serviceError !== ''): ?>
                    <div class="msg msg-error"><?php echo h($serviceError); ?></div>
                <?php elseif (!empty($serviceRows)): ?>
                    <div class="stats-summary" style="margin-top:8px;">
                        Počet servisních zakázek: <?php echo count($serviceRows); ?>,
                        celkem: <?php echo number_format($serviceTotalSum, 2, ',', ' '); ?> Kč
                        <br>Práce+servis: <?php echo number_format($serviceWorkCount, 0, ',', ' '); ?> položek, suma: <?php echo number_format($serviceWorkSum, 2, ',', ' '); ?> Kč
                        <br>Zisk na zboží: <?php echo number_format($serviceGoodsProfitSum, 2, ',', ' '); ?> Kč
                        <br>Celkem zisk: <?php echo number_format($serviceWorkSum + $serviceGoodsProfitSum, 2, ',', ' '); ?> Kč
                    </div>

                    <div class="table-wrap">
                        <table class="stats-table orders-table">
                            <thead>
                            <tr>
                                <th class="orders-col-date">Datum</th>
                                <th class="orders-col-name">Jméno</th>
                                <th class="orders-col-email">E-mail</th>
                                <th class="orders-col-phone">Telefon</th>
                                <th class="orders-col-order">Číslo objednávky</th>
                                <th class="orders-col-invoice">Číslo faktury</th>
                                <th class="orders-col-total">Celkem</th>
                                <th class="orders-col-payment">Platba</th>
                                <th class="orders-col-delivery">Doprava</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($serviceRows as $row): ?>
                                <?php
                                $idOrder = (int)($row['id_order'] ?? 0);

                                $createdDisplay = '';
                                if (!empty($row['created_at'])) {
                                    try {
                                        $dt = new DateTime($row['created_at']);
                                        $createdDisplay = $dt->format('d.m.Y H:i');
                                    } catch (Exception $e) {
                                        $createdDisplay = (string)$row['created_at'];
                                    }
                                }

                                $name   = trim((string)($row['customer_name'] ?? ''));
                                $email  = trim((string)($row['customer_email'] ?? ''));
                                $phone  = trim((string)($row['customer_phone'] ?? ''));
                                $orderNumber   = trim((string)($row['number'] ?? ''));
                                $orderNumberDisplay = $orderNumber !== '' ? $orderNumber : '—';
                                if (mb_strlen($orderNumberDisplay, 'UTF-8') > 11) {
                                    $orderNumberDisplay = mb_substr($orderNumberDisplay, 0, 11, 'UTF-8');
                                }
                                $invoiceNumber = trim((string)($row['invoice_number'] ?? ''));
                                $totalPrice    = isset($row['total_price_with_vat']) ? (float)$row['total_price_with_vat'] : 0.0;

                                // měna + kurz (pokud máme) – zobrazení částek správně CZK/EUR
                                $currencyCode = 'CZK';
                                if (isset($row['currency_code']) && trim((string)$row['currency_code']) !== '') {
                                    $currencyCode = strtoupper(trim((string)$row['currency_code']));
                                }
                                $exchangeRate = isset($row['exchange_rate']) ? (float)$row['exchange_rate'] : 0.0;

                                // Předpoklad: DB ukládá částky v CZK. Pokud je měna EUR a máme kurz, přepočítáme do EUR.
                                $fx = 1.0;
                                $currencyDisplayCode = 'CZK';
                                if ($currencyCode !== 'CZK' && $exchangeRate > 0) {
                                    $fx = 1.0 / $exchangeRate;
                                    $currencyDisplayCode = $currencyCode;
                                }

                                $totalPriceDisp = $totalPrice * $fx;

                                $dbPaid    = isset($row['zaplaceno']) && (string)$row['zaplaceno'] === 'A';
                                $gopayPaid = isset($row['gopay_zaplaceno']) && (string)$row['gopay_zaplaceno'] === 'A';
                                $isPaidOrder = $dbPaid || $gopayPaid;

                                $paymentName  = trim((string)($row['payment_name'] ?? ''));
                                $deliveryRaw  = trim((string)($row['delivery_name'] ?? ''));
                                $deliveryLower = mb_strtolower($deliveryRaw, 'UTF-8');
                                $isZasilkovna = ($deliveryRaw !== '' && mb_strpos($deliveryLower, 'zasilkovna') !== false);
                                $isManualOrder = !empty($row['is_manual']);
                                ?>
                                <tr class="order-main-row" data-order-id="<?php echo $idOrder; ?>" onclick="toggleOrderDetail(<?php echo $idOrder; ?>);">
                                    <td class="orders-col-date"><?php echo h($createdDisplay); ?></td>
                                    <td class="orders-col-name">
                                        <?php if ($isManualOrder && $idOrder < 0): ?>
                                            <form method="post" action="index.php?view=service" style="display:inline; margin:0;" onclick="event.stopPropagation();" onsubmit="event.stopPropagation(); return confirm('Opravdu smazat ruční řádek?');">
                                                <input type="hidden" name="action" value="service_delete_manual_row">
                                                <input type="hidden" name="manual_id" value="<?php echo abs($idOrder); ?>">
                                                <input type="hidden" name="from" value="<?php echo h($serviceDateFromStr); ?>">
                                                <input type="hidden" name="to" value="<?php echo h($serviceDateToStr); ?>">
                                                <button type="submit" title="Odstranit ruční řádek" style="border:0; background:#e53935; color:#fff; border-radius:50%; width:20px; height:20px; line-height:18px; padding:0; cursor:pointer; margin-right:6px; font-weight:700;">×</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php echo h($name); ?>
                                    </td>
                                    <td class="orders-col-email"><?php echo $email !== '' ? h($email) : '—'; ?></td>
                                    <td class="orders-col-phone"><?php echo $phone !== '' ? h($phone) : '—'; ?></td>
                                    <td class="orders-col-order"><?php echo h($orderNumberDisplay); ?></td>
                                    <td class="orders-col-invoice"><?php echo $invoiceNumber !== '' ? h($invoiceNumber) : '—'; ?></td>
                                    <td class="orders-col-total"><?php echo fmt_money($totalPriceDisp, $currencyDisplayCode); ?></td>
                                    <td class="orders-col-payment">
                                        <span class="orders-paid-indicator <?php echo $isPaidOrder ? 'paid' : 'unpaid'; ?>"></span>
                                        <?php echo h($paymentName); ?>
                                    </td>
                                    <td class="orders-col-delivery">
                                        <?php if ($isZasilkovna): ?>
                                            <span class="delivery-toggle" data-target="service-delivery-<?php echo $idOrder; ?>">Zásilkovna</span>
                                        <?php else: ?>
                                            <?php echo $deliveryRaw !== '' ? h($deliveryRaw) : '—'; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr id="order-detail-<?php echo $idOrder; ?>" class="order-detail-row">
                                    <td colspan="9" class="order-detail-cell">
                                        <?php if ($isZasilkovna && $deliveryRaw !== ''): ?>
                                            <div id="service-delivery-<?php echo $idOrder; ?>" class="order-delivery-note" style="display:none;">
                                                <?php echo nl2br(h($deliveryRaw)); ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php $items = isset($serviceItemsById[$idOrder]) ? $serviceItemsById[$idOrder] : []; ?>
                                        <?php if (!empty($items)): ?>
                                            <div class="table-wrap" style="margin-top:6px;">
                                                <table class="order-items-table">
                                                    <thead>
                                                    <tr>
                                                        <th>Kód</th>
                                                        <th>Nákupní cena bez DPH</th>
                                                        <th>Produkt</th>
                                                        <th>Varianta</th>
                                                        <th>Ks</th>
                                                        <th>Řádek (s DPH)</th>
                                                        <th>DPH</th>
                                                        <th>Cena s DPH</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($items as $it): ?>
                                                        <?php
                                                        $itemIdSrv = (int)($it['item_id'] ?? 0);
                                                        $codeSrv = trim((string)($it['code'] ?? ''));
                                                        $manualIdSrv = (int)($it['manual_id'] ?? 0);
                                                        $isManualSrv = !empty($it['is_manual']);
                                                        $vatChecked = !empty($it['manual_vat']);

                                                        // hodnoty už jsou připravené při načítání (kvůli zisku + zvýraznění)
                                                        $purchaseSrv = array_key_exists('purchase', $it) ? $it['purchase'] : null;
                                                        $missingSrvPrice = !empty($it['missing_purchase']);

                                                        // fallback (kdyby se do $items dostaly starší záznamy bez těchto polí)
                                                        if ($purchaseSrv === null && !$missingSrvPrice && !empty($servicePriceMapLoaded)) {
                                                            if ($codeSrv !== '' && isset($servicePriceMap[$codeSrv]) && (float)$servicePriceMap[$codeSrv] > 0) {
                                                                $purchaseSrv = (float)$servicePriceMap[$codeSrv];
                                                            } else {
                                                                $missingSrvPrice = true;
                                                            }
                                                        }

                                                        $purchaseInputValue = ($purchaseSrv !== null)
                                                            ? rtrim(rtrim(number_format((float)$purchaseSrv, 2, '.', ''), '0'), '.')
                                                            : '';
                                                        $lineTotalInputValue = rtrim(rtrim(number_format((float)($it['total'] ?? 0), 2, '.', ''), '0'), '.');
                                                        ?>
                                                        <tr<?php echo $missingSrvPrice ? ' style="background-color:#ffd6d6 !important;"' : ''; ?>>
                                                            <td>
                                                                <?php if ($isManualSrv && $manualIdSrv > 0): ?>
                                                                    <form method="post" action="index.php?view=service" style="display:inline; margin:0;" onsubmit="return confirm('Opravdu smazat ruční řádek?');">
                                                                        <input type="hidden" name="action" value="service_delete_manual_row">
                                                                        <input type="hidden" name="manual_id" value="<?php echo $manualIdSrv; ?>">
                                                                        <input type="hidden" name="from" value="<?php echo h($serviceDateFromStr); ?>">
                                                                        <input type="hidden" name="to" value="<?php echo h($serviceDateToStr); ?>">
                                                                        <button type="submit" title="Odstranit ruční řádek" style="border:0; background:#e53935; color:#fff; border-radius:50%; width:20px; height:20px; line-height:18px; padding:0; cursor:pointer; margin-right:6px; font-weight:700;">×</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <?php echo h($codeSrv); ?>
                                                            </td>
                                                            <td>
                                                                <?php if (!empty($it['is_work'])): ?>
                                                                    <?php echo fmt_money(0, 'CZK'); ?>
                                                                <?php elseif ($isManualSrv): ?>
                                                                    <form method="post" action="index.php?view=service" style="display:flex; gap:6px; align-items:center; margin:0;" onclick="event.stopPropagation();">
                                                                        <input type="hidden" name="action" value="service_update_manual_row">
                                                                        <input type="hidden" name="manual_id" value="<?php echo $manualIdSrv; ?>">
                                                                        <input type="hidden" name="from" value="<?php echo h($serviceDateFromStr); ?>">
                                                                        <input type="hidden" name="to" value="<?php echo h($serviceDateToStr); ?>">
                                                                        <input type="text" name="manual_purchase" value="<?php echo h($purchaseInputValue); ?>" placeholder="Cena bez DPH" style="width:110px; min-width:110px;">
                                                                        <button type="submit" class="btn" style="padding:6px 10px;">Uložit</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <form method="post" action="index.php?view=service" style="display:flex; gap:6px; align-items:center; margin:0;">
                                                                        <input type="hidden" name="action" value="service_save_purchase">
                                                                        <input type="hidden" name="item_id" value="<?php echo $itemIdSrv; ?>">
                                                                        <input type="hidden" name="from" value="<?php echo h($serviceDateFromStr); ?>">
                                                                        <input type="hidden" name="to" value="<?php echo h($serviceDateToStr); ?>">
                                                                        <input type="text" name="purchase_price" value="<?php echo h($purchaseInputValue); ?>" placeholder="Cena bez DPH" style="width:110px; min-width:110px;">
                                                                        <button type="submit" class="btn" style="padding:6px 10px;">Uložit</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo h($it['name']); ?></td>
                                                            <td><?php echo h($it['variant']); ?></td>
                                                            <td><?php echo (float)$it['qty']; ?></td>
                                                            <td>
                                                                <?php if ($isManualSrv): ?>
                                                                    <form method="post" action="index.php?view=service" style="display:flex; gap:6px; align-items:center; margin:0;" onclick="event.stopPropagation();">
                                                                        <input type="hidden" name="action" value="service_update_manual_row">
                                                                        <input type="hidden" name="manual_id" value="<?php echo $manualIdSrv; ?>">
                                                                        <input type="hidden" name="from" value="<?php echo h($serviceDateFromStr); ?>">
                                                                        <input type="hidden" name="to" value="<?php echo h($serviceDateToStr); ?>">
                                                                        <input type="text" name="manual_line_total" value="<?php echo h($lineTotalInputValue); ?>" placeholder="Řádek s DPH" style="width:110px; min-width:110px;">
                                                                        <button type="submit" class="btn" style="padding:6px 10px;">Uložit</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <?php echo fmt_money(((float)$it['total']) * $fx, $currencyDisplayCode); ?>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td style="text-align:center; white-space:nowrap;">
                                                                <?php $rowHasInvoice = !empty($it['has_invoice']); ?>
                                                                <?php if ($isManualSrv): ?>
                                                                    <form method="post" action="index.php?view=service" style="margin:0; display:inline-flex; align-items:center; justify-content:center;" onclick="event.stopPropagation();">
                                                                        <input type="hidden" name="action" value="service_update_manual_row">
                                                                        <input type="hidden" name="manual_id" value="<?php echo $manualIdSrv; ?>">
                                                                        <input type="hidden" name="from" value="<?php echo h($serviceDateFromStr); ?>">
                                                                        <input type="hidden" name="to" value="<?php echo h($serviceDateToStr); ?>">
                                                                        <input type="hidden" name="manual_vat_update" value="1">
                                                                        <input type="checkbox" name="manual_vat" value="1" <?php echo !empty($vatChecked) ? 'checked' : ''; ?> onchange="this.form.submit();" onclick="event.stopPropagation();">
                                                                    </form>
                                                                <?php elseif (!empty($it['is_work']) || !$rowHasInvoice || $itemIdSrv <= 0 || $purchaseSrv === null || (float)$purchaseSrv <= 0): ?>
                                                                    —
                                                                <?php else: ?>
                                                                    <?php $vatChecked = service_vat_is_marked($itemIdSrv); ?>
                                                                    <form method="post" action="index.php?view=service" class="service-vat-form" style="margin:0; display:inline-flex; align-items:center; justify-content:center;" onclick="event.stopPropagation();">
                                                                        <input type="hidden" name="action" value="service_toggle_purchase_vat">
                                                                        <input type="hidden" name="item_id" value="<?php echo $itemIdSrv; ?>">
                                                                        <input type="hidden" name="from" value="<?php echo h($serviceDateFromStr); ?>">
                                                                        <input type="hidden" name="to" value="<?php echo h($serviceDateToStr); ?>">
                                                                        <input type="hidden" name="current_purchase_price" value="<?php echo h($purchaseInputValue); ?>">
                                                                        <input type="hidden" name="vat_mode" value="0">
                                                                        <input type="checkbox" class="service-vat-check" name="vat_mode" value="1" <?php echo $vatChecked ? 'checked' : ''; ?> onchange="serviceTogglePurchaseVat(this);" onclick="event.stopPropagation();">
                                                                    </form>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td style="white-space:nowrap;">
                                                                <?php if ($isManualSrv): ?>
                                                                    <?php echo ($purchaseSrv !== null && (float)$purchaseSrv > 0 && !empty($vatChecked)) ? fmt_money(((float)$purchaseSrv) * 1.21 * $fx, $currencyDisplayCode) : '—'; ?>
                                                                <?php elseif (!empty($it['is_work']) || !$rowHasInvoice || $purchaseSrv === null || (float)$purchaseSrv <= 0): ?>
                                                                    —
                                                                <?php else: ?>
                                                                    <?php if (!empty($vatChecked)): ?>
                                                                        <?php echo fmt_money(((float)$purchaseSrv) * 1.21 * $fx, $currencyDisplayCode); ?>
                                                                    <?php else: ?>
                                                                        —
                                                                    <?php endif; ?>
                                                                <?php endif; ?>
                                                            </td>
</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <em>Žádné položky k zobrazení.</em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                        <?php if (!empty($customersData) && isset($customersTotalPages) && $customersTotalPages > 1): ?>
                            <div class="stats-summary" style="margin-top:10px;">
                                <?php if ($customersPage > 1): ?>
                                    <a href="index.php?view=customers&amp;page=<?php echo $customersPage - 1; ?>&amp;from=<?php echo h($customersDateFromStr); ?>&amp;to=<?php echo h($customersDateToStr); ?>&amp;sort=<?php echo h($customersSort); ?>">◀ Předchozí</a>
                                <?php endif; ?>
                                &nbsp;
                                <?php if ($customersPage < $customersTotalPages): ?>
                                    <a href="index.php?view=customers&amp;page=<?php echo $customersPage + 1; ?>&amp;from=<?php echo h($customersDateFromStr); ?>&amp;to=<?php echo h($customersDateToStr); ?>&amp;sort=<?php echo h($customersSort); ?>">Další ▶</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>


                    <script>
                    (function() {
                        window.toggleOrderDetail = function(id) {
                            var row  = document.getElementById('order-detail-' + id);
                            var main = document.querySelector('tr.order-main-row[data-order-id="' + id + '"]');
                            if (!row || !main) return;
                            var visible = row.style.display === 'table-row';
                            row.style.display = visible ? 'none' : 'table-row';
                            if (visible) {
                                main.classList.remove('selected');
                            } else {
                                main.classList.add('selected');
                            }
                        };

                        var deliveryToggles = document.querySelectorAll('.delivery-toggle');
                        deliveryToggles.forEach(function(el) {
                            el.addEventListener('click', function(e) {
                                e.stopPropagation();
                                var id = el.getAttribute('data-target');
                                if (!id) return;
                                var box = document.getElementById(id);
                                if (!box) return;
                                box.style.display = (box.style.display === 'block') ? 'none' : 'block';
                            });
                        });
                    })();
                    </script>

                    <script>
                    window.serviceTogglePurchaseVat = function(cb) {
                        if (!cb || !cb.form) return;
                        try {
                            var row = cb.closest('tr');
                            var priceInput = row ? row.querySelector("input[name='purchase_price']") : null;
                            var hidden = cb.form.querySelector("input[name='current_purchase_price']");
                            if (hidden && priceInput) {
                                hidden.value = priceInput.value || '';
                            }
                        } catch (e) {}
                        cb.form.submit();
                    };
                    </script>

                <?php elseif ($serviceDateFromStr !== '' || $serviceDateToStr !== ''): ?>
                    <div class="msg">Pro zadaný filtr nejsou žádné servisní zakázky.</div>
                <?php else: ?>
                    <div class="msg">Zadej datum nebo zobraz servisní zakázky bez filtru.</div>
                <?php endif; ?>
            </div>

        
        <?php elseif ($view === 'monthly'): ?>

            <div class="card">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Měsíční přehled</h1>

                <?php if (!empty($monthlyErrorMsg)): ?>
                    <div class="msg msg-error"><?php echo h($monthlyErrorMsg); ?></div>
                <?php endif; ?>

                <?php if (!empty($monthlyError)): ?>
                    <div class="msg msg-error"><?php echo h($monthlyError); ?></div>
                    <?php if (isset($monthlyCreateSql) && isset($monthlyTableReady) && !$monthlyTableReady): ?>
                        <div class="msg" style="margin-top:8px;">
                            Vytvoř tabulku ručně (SQL):
                        </div>
                        <pre class="codebox" style="white-space:pre-wrap; overflow:auto; padding:10px; border-radius:12px; background:#0b1220; color:#e5f3ff; font-size:12px;"><?php echo h($monthlyCreateSql); ?></pre>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (empty($_SESSION['monthly_ok'])): ?>
                    <div class="msg" style="margin-top:8px;">
                        Pro vstup do modulu zadej heslo.
                    </div>

                    <form method="post" action="index.php?view=monthly" style="margin-top:10px;">
                        <input type="hidden" name="action" value="monthly_login">
                        <div class="stats-grid" style="grid-template-columns: 1fr;">
                            <div>
                                <div class="stats-label">Heslo</div>
                                <input type="password" name="monthly_pass" placeholder="Heslo" autocomplete="current-password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn-full">VSTOUPIT</button>
                    </form>

                <?php else: ?>

                    <?php
                        $months = [
                            1=>'Leden',2=>'Únor',3=>'Březen',4=>'Duben',5=>'Květen',6=>'Červen',
                            7=>'Červenec',8=>'Srpen',9=>'Září',10=>'Říjen',11=>'Listopad',12=>'Prosinec'
                        ];
                        $allowedYears = [2025, 2026, 2027];
                    ?>

                    <div class="year-tabs">
                        <?php foreach ($allowedYears as $yy): ?>
                            <a class="year-tab <?php echo ($yy === (int)$monthlyYear) ? 'active' : ''; ?>"
                               href="index.php?view=monthly&amp;y=<?php echo (int)$yy; ?>&amp;m=<?php echo (int)$monthlyMonth; ?>">
                               <?php echo (int)$yy; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="month-grid">
                        <?php foreach ($months as $mm => $label): ?>
                            <a class="month-btn <?php echo ($mm === (int)$monthlyMonth) ? 'active' : ''; ?>"
                               href="index.php?view=monthly&amp;y=<?php echo (int)$monthlyYear; ?>&amp;m=<?php echo (int)$mm; ?>">
                               <?php echo h($label); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="msg" style="margin-top:12px;">
                        Vybraný měsíc: <strong><?php echo h($months[(int)$monthlyMonth] ?? ''); ?> <?php echo (int)$monthlyYear; ?></strong>
                        <?php if (!empty($monthlyMissingItems)): ?>
                            <br><span style="color:#b30000;font-weight:800;">Pozor: <?php echo (int)$monthlyMissingItems; ?> položek bez nákupní ceny (nepočítá se do zisku).</span>
                        <?php endif; ?>
                    </div>

                    <div class="monthly-mini">
                        <span class="chip">Objednávek: <b><?php echo (int)$monthlyOrdersCount; ?></b></span>
                        <span class="chip">Obrat: <b><?php echo fmt_money($monthlyTurnover, 'CZK'); ?></b></span>
                        <span class="chip">Zisk: <b><?php echo fmt_money($monthlyGoodsProfit, 'CZK'); ?></b></span>
                    </div>

                    <div class="monthly-kpis">
                        
                        <div class="kpi">
                            <div class="lbl">Dnešní den: Zisk ze zboží</div>
                            <div class="val" id="today-goods-profit-val"><?php echo fmt_money($todayGoodsProfit, 'CZK'); ?></div>
                        </div>

                        <div class="kpi">
                            <div class="lbl">Včerejší den: Zisk ze zboží</div>
                            <div class="val" id="yesterday-goods-profit-val"><?php echo fmt_money($yesterdayGoodsProfit, 'CZK'); ?></div>
                        </div>
<div class="kpi">
                            <div class="lbl">ZISK ze zboží</div>
                            <div class="val" id="goods-profit-val"><?php echo fmt_money($monthlyGoodsProfit, 'CZK'); ?></div>
                        </div>
                        <div class="kpi">
                            <div class="lbl">Celkové náklady</div>
                            <div class="val" id="costs-total-val"><?php echo fmt_money($monthlyCostsTotal, 'CZK'); ?></div>
                        </div>
                        <div class="kpi">
                            <div class="lbl">Čistý zisk</div>
                            <div class="val <?php echo ($monthlyNetProfit < 0) ? 'negative' : 'positive'; ?>" id="net-profit-val">
                                <?php echo fmt_money($monthlyNetProfit, 'CZK'); ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!isset($monthlyTableReady) || $monthlyTableReady): ?>
                    <div class="costs-wrap">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;">
                            <div style="font-weight:900;">Náklady (upravitelné, ukládají se okamžitě)</div>
                            <button type="button" class="btn-mini btn-mini-add" id="btn-add-cost">+ Přidat náklad</button>
                        </div>

                        <div class="table-wrap" style="margin-top:10px;">
                            <table class="costs-table" id="costs-table">
                                <thead>
                                <tr>
                                    <th class="costs-desc">Popis</th>
                                    <th class="costs-amt">Náklady</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($monthlyCostsRows)): ?>
                                    <?php foreach ($monthlyCostsRows as $r): ?>
                                        <?php
                                            $sid = (int)($r['id'] ?? 0);
                                            $sy  = (int)($r['start_year'] ?? 0);
                                            $sm  = (int)($r['start_month'] ?? 0);
                                            $isCarried = !empty($r['carry']) && (($sy*100 + $sm) < ((int)$monthlyYear*100 + (int)$monthlyMonth));
                                        ?>
                                        <tr data-id="<?php echo $sid; ?>">
                                            <td class="costs-desc">
                                                <div class="costs-inline">
                                                    <label class="carry-box" title="Přenášet do dalších měsíců">
                                                        <input type="checkbox" class="js-carry" <?php echo !empty($r['carry']) ? 'checked' : ''; ?>>
                                                        Přenášet
                                                    </label>
                                                    <?php if ($isCarried): ?>
                                                        <span class="carry-note">(od <?php echo sprintf('%02d/%04d', $sm, $sy); ?>)</span>
                                                    <?php endif; ?>
                                                    <span class="save-dot js-dot" title="Uloženo / chyba"></span>
                                                </div>
                                                <input class="costs-input js-desc" type="text" value="<?php echo h($r['description'] ?? ''); ?>" placeholder="Např. nájem, mzdy, PPC…">
                                            </td>
                                            <td class="costs-amt">
                                                <div class="costs-inline" style="justify-content:flex-end;">
                                                    <input class="costs-input js-amt" type="number" step="0.01" value="<?php echo h((string)($r['amount'] ?? '0')); ?>" style="max-width:160px;text-align:right;">
                                                    <button type="button" class="btn-mini btn-mini-del js-del" title="Smazat">✕</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="2" style="opacity:.7;padding:14px 8px;">Zatím žádné náklady. Přidej první řádek.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="msg msg-error" style="margin-top:12px;">Náklady nelze ukládat, protože chybí tabulka <b>monthly_costs</b>. Vytvoř ji podle SQL výše a obnov stránku.</div>
                    <?php endif; ?>



                    <script>
                    (function(){
                        const y = <?php echo (int)$monthlyYear; ?>;
                        const m = <?php echo (int)$monthlyMonth; ?>;

                        const goodsProfit = <?php echo json_encode((float)$monthlyGoodsProfit); ?>;

                        function fd(obj){
                            const p = new URLSearchParams();
                            Object.keys(obj).forEach(k => p.append(k, obj[k]));
                            return p.toString();
                        }

                        async function post(action, data){
                            const res = await fetch('index.php', {
                                method: 'POST',
                                headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                                body: fd(Object.assign({action: action}, data || {}))
                            });
                            return await res.json();
                        }

                        function setDot(tr, state){
                            const dot = tr.querySelector('.js-dot');
                            if (!dot) return;
                            dot.classList.remove('ok','err');
                            if (state === 'ok') dot.classList.add('ok');
                            if (state === 'err') dot.classList.add('err');
                        }


                        function fmtCzk(n){
                                                    const x = Number(n || 0);
                                                    // 2 desetinná místa, české formátování
                                                    return x.toLocaleString('cs-CZ', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' Kč';
                                                }
                        
                                                function recomputeKpis(){
                                                    let sum = 0;
                                                    document.querySelectorAll('#costs-table tbody tr[data-id] .js-amt').forEach(inp => {
                                                        const v = String(inp.value || '0').replace(',', '.');
                                                        const num = parseFloat(v);
                                                        if (!isNaN(num)) sum += num;
                                                    });
                        
                                                    const costsEl = document.getElementById('costs-total-val');
                                                    const netEl   = document.getElementById('net-profit-val');
                                                    if (costsEl) costsEl.textContent = fmtCzk(sum);
                        
                                                    const net = Number(goodsProfit) - sum;
                                                    if (netEl) {
                                                        netEl.textContent = fmtCzk(net);
                                                        netEl.classList.remove('negative','positive');
                                                        netEl.classList.add(net < 0 ? 'negative' : 'positive');
                                                    }
                                                }
                        
                                                function debounce(fn, ms){
                            let t = null;
                            return function(){
                                const args = arguments;
                                clearTimeout(t);
                                t = setTimeout(() => fn.apply(this,args), ms);
                            };
                        }

                        async function saveRow(tr){
                            const id = tr.getAttribute('data-id');
                            const desc = (tr.querySelector('.js-desc')||{}).value || '';
                            const amt  = (tr.querySelector('.js-amt')||{}).value || '0';
                            const carry = (tr.querySelector('.js-carry')||{}).checked ? 1 : 0;

                            try{
                                const r = await post('monthly_cost_update', {id:id, description:desc, amount:amt, carry:carry});
                                if (r && r.ok){
                                    setDot(tr,'ok');
                                    recomputeKpis();
                                } else {
                                    setDot(tr,'err');
                                }
                            } catch(e){
                                setDot(tr,'err');
                            }
                        }

                        const saveRowDebounced = debounce(saveRow, 500);

                        document.querySelectorAll('#costs-table tbody tr[data-id]').forEach(tr => {
                            const desc = tr.querySelector('.js-desc');
                            const amt  = tr.querySelector('.js-amt');
                            const carry= tr.querySelector('.js-carry');
                            const del  = tr.querySelector('.js-del');

                            if (desc) {
                                desc.addEventListener('input', () => saveRowDebounced(tr));
                                desc.addEventListener('blur', () => saveRow(tr));
                            }
                            if (amt) {
                                amt.addEventListener('input', () => saveRowDebounced(tr));
                                amt.addEventListener('blur', () => saveRow(tr));
                            }
                            if (carry) {
                                carry.addEventListener('change', () => saveRow(tr));
                            }
                            if (del) {
                                del.addEventListener('click', async () => {
                                    if (!confirm('Smazat tento náklad?')) return;
                                    const id = tr.getAttribute('data-id');
                                    try{
                                        const r = await post('monthly_cost_delete', {id:id});
                                        if (r && r.ok) {
                                            window.location.reload();
                                        } else {
                                            alert(r && r.error ? r.error : 'Nelze smazat.');
                                        }
                                    } catch(e){
                                        alert('Nelze smazat.');
                                    }
                                });
                            }
                        });

                        const btnAdd = document.getElementById('btn-add-cost');
                        recomputeKpis();

                        if (btnAdd) {
                            btnAdd.addEventListener('click', async () => {
                                try{
                                    const r = await post('monthly_cost_create', {y:y, m:m});
                                    if (r && r.ok && r.id) {
                                        window.location.reload();
                                    } else {
                                        alert(r && r.error ? r.error : 'Nelze přidat řádek.');
                                    }
                                } catch(e){
                                    alert('Nelze přidat řádek.');
                                }
                            });
                        }
                    })();
                    </script>

                <?php endif; ?>
            </div>



<?php elseif ($view === 'returns'): ?>

            <div class="card returns-card">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Vrácené zboží</h1>

                <?php if ($returnsFlash !== ''): ?>
                    <div class="msg <?php echo stripos($returnsFlash, 'CHYBA') === 0 ? 'msg-error' : 'msg-ok'; ?>"><?php echo h($returnsFlash); ?></div>
                <?php endif; ?>

                <form method="get" action="index.php" class="returns-filter-form">
                    <input type="hidden" name="view" value="returns">
                    <input type="hidden" name="tab" value="<?php echo h($returnsTab); ?>">

                    <div class="stats-grid">
                        <div>
                            <div class="stats-label">Datum od</div>
                            <input type="date" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                        </div>
                        <div>
                            <div class="stats-label">Datum do</div>
                            <input type="date" name="to" value="<?php echo h($returnsDateToStr); ?>">
                        </div>
                    </div>

                    <div class="returns-search-row">
                        <input type="text" name="q" value="<?php echo h($returnsSearchQuery); ?>" placeholder="Hledat: faktura, EAN, objednávka, jméno, VS, produkt, důvod…">
                        <button type="submit" class="btn-full returns-search-btn">HLEDAT / ZOBRAZIT</button>
                    </div>
                </form>

                <div class="returns-tabs">
                    <a class="returns-tab <?php echo $returnsTab === 'list' ? 'active' : ''; ?>" href="index.php?<?php echo h(http_build_query(['view'=>'returns','from'=>$returnsDateFromStr,'to'=>$returnsDateToStr,'q'=>$returnsSearchQuery,'tab'=>'list'])); ?>">Vratky</a>
                    <a class="returns-tab <?php echo $returnsTab === 'products' ? 'active' : ''; ?>" href="index.php?<?php echo h(http_build_query(['view'=>'returns','from'=>$returnsDateFromStr,'to'=>$returnsDateToStr,'q'=>$returnsSearchQuery,'tab'=>'products'])); ?>">Vrácené produkty</a>
                </div>

                <?php if (!empty($returnsTopStats)): ?>
                    <button type="button" id="returns-stats-toggle" class="btn-full" style="margin-top:8px;">
                        STATISTIKA VRÁCENÉHO ZBOŽÍ (TOP 10)
                    </button>
                    <div id="returns-stats-wrap" style="display:none;margin-top:10px;">
                        <div class="table-wrap">
                            <table class="stats-table">
                                <thead><tr><th>Produkt</th><th>Vráceno ks</th></tr></thead>
                                <tbody>
                                <?php foreach ($returnsTopStats as $st): ?>
                                    <tr><td><?php echo h($st['name']); ?></td><td><?php echo (float)$st['qty']; ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($returnsError !== ''): ?>
                    <div class="msg msg-error"><?php echo h($returnsError); ?></div>
                <?php else: ?>
                    <div class="returns-summary">
                        Celkem vráceno: <?php echo (float)$returnsTotalQty; ?> ks,
                        celková cena: <?php echo number_format($returnsTotalPrice, 2, ',', ' '); ?> Kč
                    </div>

                    <?php if ($returnsTab === 'products'): ?>
                        <div class="table-wrap returns-products-wrap">
                            <table class="stats-table returns-table">
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Číslo vratky</th>
                                        <th>Objednávka</th>
                                        <th>EAN</th>
                                        <th>Produkt</th>
                                        <th class="right">Ks</th>
                                        <th class="right">Cena</th>
                                        <th>Důvod</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($returnsProductsRows)): ?>
                                    <tr><td colspan="8" class="muted">Pro zadaný filtr nejsou žádné vrácené produkty.</td></tr>
                                <?php else: foreach ($returnsProductsRows as $pr): ?>
                                    <tr>
                                        <td><?php echo h(substr((string)($pr['created_at'] ?? ''), 0, 10)); ?></td>
                                        <td><strong><?php echo h($pr['return_number'] ?? ''); ?></strong></td>
                                        <td><?php echo h($pr['order_number'] ?? ''); ?></td>
                                        <td><?php echo h($pr['ean'] ?? ''); ?></td>
                                        <td><?php echo h(trim((string)($pr['product_name'] ?? '') . ' ' . (string)($pr['variant_description'] ?? ''))); ?></td>
                                        <td class="right"><?php echo h(rtrim(rtrim(number_format((float)($pr['return_qty'] ?? 0), 2, ',', ' '), '0'), ',')); ?></td>
                                        <td class="right"><?php echo number_format((float)($pr['line_total_with_vat'] ?? 0), 2, ',', ' '); ?> Kč</td>
                                        <td><?php echo nl2br(h($pr['reason'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="returns-board">
                            <div class="returns-main-list">
                                <h2 class="returns-section-title">Vratky</h2>
                                <div class="table-wrap">
                                    <table class="stats-table returns-table">
                                        <thead>
                                            <tr>
                                                <th>Číslo vratky</th>
                                                <th>Faktura</th>
                                                <th>Objednávka</th>
                                                <th>Datum vrácení</th>
                                                <th>Jméno a příjmení</th>
                                                <th>Variabilní symbol</th>
                                                <th class="right">Celková cena</th>
                                                <th>K proplacení</th>
                                                <th>PDF</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($returnsRows)): ?>
                                            <tr><td colspan="9" class="muted">Pro zadaný filtr nejsou žádné vratky.</td></tr>
                                        <?php else: foreach ($returnsRows as $row): ?>
                                            <tr class="returns-master-row" data-detail-id="return-detail-<?php echo (int)$row['id']; ?>">
                                                <td><strong><?php echo h($row['return_number'] ?? ''); ?></strong></td>
                                                <td><?php echo h($row['invoice_number_view'] ?? ''); ?></td>
                                                <td><?php echo h($row['order_number'] ?? ''); ?></td>
                                                <td><?php echo h(substr((string)($row['created_at'] ?? ''), 0, 10)); ?></td>
                                                <td><?php echo h($row['customer_name'] ?? ''); ?></td>
                                                <td><?php echo h($row['variable_symbol_view'] ?? ''); ?></td>
                                                <td class="right"><strong><?php echo number_format((float)($row['total_return_with_vat'] ?? 0), 2, ',', ' '); ?> Kč</strong></td>
                                                <td>
                                                    <form method="post" class="returns-status-form" onsubmit="return confirm('Předat vratku k proplacení?');">
                                                        <input type="hidden" name="returns_action" value="update_status">
                                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                        <input type="hidden" name="status" value="TO_REFUND">
                                                        <input type="hidden" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                                                        <input type="hidden" name="to" value="<?php echo h($returnsDateToStr); ?>">
                                                        <input type="hidden" name="q" value="<?php echo h($returnsSearchQuery); ?>">
                                                        <input type="hidden" name="tab" value="list">
                                                        <button type="submit" class="returns-refund-btn">K proplacení</button>
                                                    </form>
                                                </td>
                                                <td><a href="vraceni_pdf.php?id=<?php echo (int)$row['id']; ?>" download>Stáhnout PDF</a></td>
                                            </tr>

                                            <?php
                                                $ridDetail = (int)$row['id'];
                                                $detailItems = $returnsItemsByRequest[$ridDetail] ?? [];
                                            ?>
                                            <tr id="return-detail-<?php echo $ridDetail; ?>" class="returns-detail-row">
                                                <td colspan="9">
                                                    <div class="returns-detail-box">
                                                        <h3>Detail vratky <?php echo h($row['return_number'] ?? ''); ?></h3>
                                                        <div class="returns-detail-muted">Zde můžeš upravit produkt, EAN, počet kusů a cenu. Po uložení se celková cena vratky přepočítá.</div>
                                                        <div class="table-wrap">
                                                            <table class="stats-table returns-items-edit-table">
                                                                <thead><tr><th>Kód</th><th>Produkt</th><th>Varianta</th><th>EAN</th><th class="right">Ks</th><th class="right">Cena/ks</th><th class="right">Celkem</th><th>Akce</th></tr></thead>
                                                                <tbody>
                                                                <?php if (empty($detailItems)): ?>
                                                                    <tr><td colspan="8" class="muted">Vratka zatím nemá položky.</td></tr>
                                                                <?php else: foreach ($detailItems as $di): ?>
                                                                    <?php
                                                                        $diId = (int)($di['id'] ?? 0);
                                                                        $formId = 'ret-item-' . $ridDetail . '-' . $diId;
                                                                        $delFormId = 'ret-del-' . $ridDetail . '-' . $diId;
                                                                        $diQty = (float)($di['return_qty'] ?? 0);
                                                                        $diPrice = (float)($di['price_per_unit_with_vat'] ?? 0);
                                                                        $diLine = (float)($di['line_total_with_vat'] ?? 0);
                                                                        if ($diLine == 0.0 && $diQty != 0.0 && $diPrice != 0.0) $diLine = $diQty * $diPrice;
                                                                    ?>
                                                                    <tr>
                                                                        <td><input form="<?php echo h($formId); ?>" type="text" name="product_number" value="<?php echo h($di['product_number'] ?? ''); ?>"></td>
                                                                        <td><input form="<?php echo h($formId); ?>" type="text" name="product_name" value="<?php echo h($di['product_name'] ?? ''); ?>"></td>
                                                                        <td><input form="<?php echo h($formId); ?>" type="text" name="variant_description" value="<?php echo h($di['variant_description'] ?? ''); ?>"></td>
                                                                        <td><input form="<?php echo h($formId); ?>" type="text" name="ean" value="<?php echo h($di['ean'] ?? ''); ?>"></td>
                                                                        <td class="right"><input form="<?php echo h($formId); ?>" type="number" step="0.01" min="0" name="return_qty" value="<?php echo h(rtrim(rtrim(number_format($diQty, 2, '.', ''), '0'), '.')); ?>"></td>
                                                                        <td class="right"><input form="<?php echo h($formId); ?>" type="number" step="0.01" name="price_per_unit_with_vat" value="<?php echo h(number_format($diPrice, 2, '.', '')); ?>"></td>
                                                                        <td class="right"><strong><?php echo number_format($diLine, 2, ',', ' '); ?> Kč</strong></td>
                                                                        <td class="returns-actions-cell">
                                                                            <form method="post" id="<?php echo h($formId); ?>" class="returns-edit-item-form">
                                                                                <input type="hidden" name="returns_action" value="update_item">
                                                                                <input type="hidden" name="id" value="<?php echo $ridDetail; ?>">
                                                                                <input type="hidden" name="item_id" value="<?php echo $diId; ?>">
                                                                                <input type="hidden" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                                                                                <input type="hidden" name="to" value="<?php echo h($returnsDateToStr); ?>">
                                                                                <input type="hidden" name="q" value="<?php echo h($returnsSearchQuery); ?>">
                                                                                <input type="hidden" name="tab" value="list">
                                                                                <button type="submit" class="returns-mini-btn">Uložit</button>
                                                                            </form>
                                                                            <form method="post" id="<?php echo h($delFormId); ?>" class="returns-delete-item-form" onsubmit="return confirm('Opravdu odstranit položku z vratky?');">
                                                                                <input type="hidden" name="returns_action" value="delete_item">
                                                                                <input type="hidden" name="id" value="<?php echo $ridDetail; ?>">
                                                                                <input type="hidden" name="item_id" value="<?php echo $diId; ?>">
                                                                                <input type="hidden" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                                                                                <input type="hidden" name="to" value="<?php echo h($returnsDateToStr); ?>">
                                                                                <input type="hidden" name="q" value="<?php echo h($returnsSearchQuery); ?>">
                                                                                <input type="hidden" name="tab" value="list">
                                                                                <button type="submit" class="returns-mini-btn danger">Smazat</button>
                                                                            </form>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; endif; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                        <div class="returns-add-product">
                                                            <h4>Přidat produkt z e-shopu/databáze</h4>
                                                            <div class="returns-product-search-row">
                                                                <input type="text" class="returns-product-search-input" placeholder="Hledat EAN, kód nebo název produktu">
                                                                <button type="button" class="returns-mini-btn returns-product-search-btn">Hledat</button>
                                                            </div>
                                                            <form method="post" class="returns-add-item-form">
                                                                <input type="hidden" name="returns_action" value="add_item">
                                                                <input type="hidden" name="id" value="<?php echo $ridDetail; ?>">
                                                                <input type="hidden" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                                                                <input type="hidden" name="to" value="<?php echo h($returnsDateToStr); ?>">
                                                                <input type="hidden" name="q" value="<?php echo h($returnsSearchQuery); ?>">
                                                                <input type="hidden" name="tab" value="list">
                                                                <select class="returns-product-select"><option value="">Nejdřív vyhledej produkt…</option></select>
                                                                <div class="returns-add-grid">
                                                                    <input type="text" name="product_number" placeholder="Kód">
                                                                    <input type="text" name="product_name" placeholder="Název produktu">
                                                                    <input type="text" name="variant_description" placeholder="Varianta">
                                                                    <input type="text" name="ean" placeholder="EAN">
                                                                    <input type="number" step="0.01" min="0" name="return_qty" value="1" placeholder="Ks">
                                                                    <input type="number" step="0.01" name="price_per_unit_with_vat" placeholder="Cena/ks s DPH">
                                                                </div>
                                                                <button type="submit" class="returns-mini-btn">Přidat do vratky</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="returns-accounting-list">
                                <h2 class="returns-section-title">K proplacení / účetní</h2>
                                <div class="table-wrap">
                                    <table class="stats-table returns-table">
                                        <thead>
                                            <tr>
                                                <th>Dobropis</th>
                                                <th>Číslo vratky</th>
                                                <th>Faktura</th>
                                                <th>Jméno</th>
                                                <th class="right">Částka</th>
                                                <th>Dobropis PDF</th>
                                                <th>Zaplaceno</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($returnsToRefundRows)): ?>
                                            <tr><td colspan="7" class="muted">Žádné vratky k proplacení.</td></tr>
                                        <?php else: foreach ($returnsToRefundRows as $row): ?>
                                            <tr class="returns-master-row" data-detail-id="return-detail-<?php echo (int)$row['id']; ?>">
                                                <td><strong><?php echo h($row['credit_note_number'] ?? ''); ?></strong></td>
                                                <td><?php echo h($row['return_number'] ?? ''); ?></td>
                                                <td><?php echo h($row['invoice_number_view'] ?? ''); ?></td>
                                                <td><?php echo h($row['customer_name'] ?? ''); ?></td>
                                                <td class="right"><strong><?php echo number_format((float)($row['total_return_with_vat'] ?? 0), 2, ',', ' '); ?> Kč</strong></td>
                                                <td><a href="vraceni_pdf.php?id=<?php echo (int)$row['id']; ?>&amp;type=credit" download>Dobropis</a></td>
                                                <td>
                                                    <form method="post" class="returns-status-form" onsubmit="return confirm('Označit vratku jako zaplacenou?');">
                                                        <input type="hidden" name="returns_action" value="update_status">
                                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                        <input type="hidden" name="status" value="REFUNDED">
                                                        <input type="hidden" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                                                        <input type="hidden" name="to" value="<?php echo h($returnsDateToStr); ?>">
                                                        <input type="hidden" name="q" value="<?php echo h($returnsSearchQuery); ?>">
                                                        <input type="hidden" name="tab" value="list">
                                                        <button type="submit" class="returns-mini-btn paid">Zaplaceno</button>
                                                    </form>
                                                </td>
                                            </tr>

                                            <?php
                                                $ridDetail = (int)$row['id'];
                                                $detailItems = $returnsItemsByRequest[$ridDetail] ?? [];
                                            ?>
                                            <tr id="return-detail-<?php echo $ridDetail; ?>" class="returns-detail-row">
                                                <td colspan="7">
                                                    <div class="returns-detail-box">
                                                        <h3>Detail vratky <?php echo h($row['return_number'] ?? ''); ?></h3>
                                                        <div class="returns-detail-muted">Zde můžeš upravit produkt, EAN, počet kusů a cenu. Po uložení se celková cena vratky přepočítá.</div>
                                                        <div class="table-wrap">
                                                            <table class="stats-table returns-items-edit-table">
                                                                <thead><tr><th>Kód</th><th>Produkt</th><th>Varianta</th><th>EAN</th><th class="right">Ks</th><th class="right">Cena/ks</th><th class="right">Celkem</th><th>Akce</th></tr></thead>
                                                                <tbody>
                                                                <?php if (empty($detailItems)): ?>
                                                                    <tr><td colspan="8" class="muted">Vratka zatím nemá položky.</td></tr>
                                                                <?php else: foreach ($detailItems as $di): ?>
                                                                    <?php
                                                                        $diId = (int)($di['id'] ?? 0);
                                                                        $formId = 'ret-item-' . $ridDetail . '-' . $diId;
                                                                        $delFormId = 'ret-del-' . $ridDetail . '-' . $diId;
                                                                        $diQty = (float)($di['return_qty'] ?? 0);
                                                                        $diPrice = (float)($di['price_per_unit_with_vat'] ?? 0);
                                                                        $diLine = (float)($di['line_total_with_vat'] ?? 0);
                                                                        if ($diLine == 0.0 && $diQty != 0.0 && $diPrice != 0.0) $diLine = $diQty * $diPrice;
                                                                    ?>
                                                                    <tr>
                                                                        <td><input form="<?php echo h($formId); ?>" type="text" name="product_number" value="<?php echo h($di['product_number'] ?? ''); ?>"></td>
                                                                        <td><input form="<?php echo h($formId); ?>" type="text" name="product_name" value="<?php echo h($di['product_name'] ?? ''); ?>"></td>
                                                                        <td><input form="<?php echo h($formId); ?>" type="text" name="variant_description" value="<?php echo h($di['variant_description'] ?? ''); ?>"></td>
                                                                        <td><input form="<?php echo h($formId); ?>" type="text" name="ean" value="<?php echo h($di['ean'] ?? ''); ?>"></td>
                                                                        <td class="right"><input form="<?php echo h($formId); ?>" type="number" step="0.01" min="0" name="return_qty" value="<?php echo h(rtrim(rtrim(number_format($diQty, 2, '.', ''), '0'), '.')); ?>"></td>
                                                                        <td class="right"><input form="<?php echo h($formId); ?>" type="number" step="0.01" name="price_per_unit_with_vat" value="<?php echo h(number_format($diPrice, 2, '.', '')); ?>"></td>
                                                                        <td class="right"><strong><?php echo number_format($diLine, 2, ',', ' '); ?> Kč</strong></td>
                                                                        <td class="returns-actions-cell">
                                                                            <form method="post" id="<?php echo h($formId); ?>" class="returns-edit-item-form">
                                                                                <input type="hidden" name="returns_action" value="update_item">
                                                                                <input type="hidden" name="id" value="<?php echo $ridDetail; ?>">
                                                                                <input type="hidden" name="item_id" value="<?php echo $diId; ?>">
                                                                                <input type="hidden" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                                                                                <input type="hidden" name="to" value="<?php echo h($returnsDateToStr); ?>">
                                                                                <input type="hidden" name="q" value="<?php echo h($returnsSearchQuery); ?>">
                                                                                <input type="hidden" name="tab" value="list">
                                                                                <button type="submit" class="returns-mini-btn">Uložit</button>
                                                                            </form>
                                                                            <form method="post" id="<?php echo h($delFormId); ?>" class="returns-delete-item-form" onsubmit="return confirm('Opravdu odstranit položku z vratky?');">
                                                                                <input type="hidden" name="returns_action" value="delete_item">
                                                                                <input type="hidden" name="id" value="<?php echo $ridDetail; ?>">
                                                                                <input type="hidden" name="item_id" value="<?php echo $diId; ?>">
                                                                                <input type="hidden" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                                                                                <input type="hidden" name="to" value="<?php echo h($returnsDateToStr); ?>">
                                                                                <input type="hidden" name="q" value="<?php echo h($returnsSearchQuery); ?>">
                                                                                <input type="hidden" name="tab" value="list">
                                                                                <button type="submit" class="returns-mini-btn danger">Smazat</button>
                                                                            </form>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; endif; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                        <div class="returns-add-product">
                                                            <h4>Přidat produkt z e-shopu/databáze</h4>
                                                            <div class="returns-product-search-row">
                                                                <input type="text" class="returns-product-search-input" placeholder="Hledat EAN, kód nebo název produktu">
                                                                <button type="button" class="returns-mini-btn returns-product-search-btn">Hledat</button>
                                                            </div>
                                                            <form method="post" class="returns-add-item-form">
                                                                <input type="hidden" name="returns_action" value="add_item">
                                                                <input type="hidden" name="id" value="<?php echo $ridDetail; ?>">
                                                                <input type="hidden" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                                                                <input type="hidden" name="to" value="<?php echo h($returnsDateToStr); ?>">
                                                                <input type="hidden" name="q" value="<?php echo h($returnsSearchQuery); ?>">
                                                                <input type="hidden" name="tab" value="list">
                                                                <select class="returns-product-select"><option value="">Nejdřív vyhledej produkt…</option></select>
                                                                <div class="returns-add-grid">
                                                                    <input type="text" name="product_number" placeholder="Kód">
                                                                    <input type="text" name="product_name" placeholder="Název produktu">
                                                                    <input type="text" name="variant_description" placeholder="Varianta">
                                                                    <input type="text" name="ean" placeholder="EAN">
                                                                    <input type="number" step="0.01" min="0" name="return_qty" value="1" placeholder="Ks">
                                                                    <input type="number" step="0.01" name="price_per_unit_with_vat" placeholder="Cena/ks s DPH">
                                                                </div>
                                                                <button type="submit" class="returns-mini-btn">Přidat do vratky</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="returns-paid-list">
                                    <h2 class="returns-section-title">Zaplacené vratky</h2>
                                    <div class="table-wrap">
                                        <table class="stats-table returns-table">
                                            <thead>
                                                <tr>
                                                    <th>Dobropis</th>
                                                    <th>Číslo vratky</th>
                                                    <th>Faktura</th>
                                                    <th>Jméno</th>
                                                    <th class="right">Částka</th>
                                                    <th>Dobropis PDF</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php if (empty($returnsPaidRows)): ?>
                                                <tr><td colspan="6" class="muted">Žádné zaplacené vratky.</td></tr>
                                            <?php else: foreach ($returnsPaidRows as $row): ?>
                                                <tr class="returns-master-row" data-detail-id="return-detail-<?php echo (int)$row['id']; ?>">
                                                    <td><strong><?php echo h($row['credit_note_number'] ?? ''); ?></strong></td>
                                                    <td><?php echo h($row['return_number'] ?? ''); ?></td>
                                                    <td><?php echo h($row['invoice_number_view'] ?? ''); ?></td>
                                                    <td><?php echo h($row['customer_name'] ?? ''); ?></td>
                                                    <td class="right"><strong><?php echo number_format((float)($row['total_return_with_vat'] ?? 0), 2, ',', ' '); ?> Kč</strong></td>
                                                    <td><a href="vraceni_pdf.php?id=<?php echo (int)$row['id']; ?>&amp;type=credit" download>Dobropis</a></td>
                                                </tr>
                                                <?php $ridDetail = (int)$row['id']; ?>
                                                <tr id="return-detail-<?php echo $ridDetail; ?>" class="returns-detail-row">
                                                    <td colspan="6">
                                                        <div class="returns-detail-box">
                                                            <h3>Detail vratky <?php echo h($row['return_number'] ?? ''); ?></h3>
                                                            <div class="returns-detail-muted">Zaplacená vratka. Položky lze případně upravit a celková cena se přepočítá.</div>
                                                            <div class="table-wrap">
                                                                <table class="stats-table returns-items-edit-table">
                                                                    <thead>
                                                                        <tr><th>Kód</th><th>Produkt</th><th>Varianta</th><th>EAN</th><th class="right">Ks</th><th class="right">Cena/ks</th><th class="right">Celkem</th><th>Akce</th></tr>
                                                                    </thead>
                                                                    <tbody>
                                                                    <?php $detailItems = $returnsItemsByRequest[$ridDetail] ?? []; ?>
                                                                    <?php if (empty($detailItems)): ?>
                                                                        <tr><td colspan="8" class="muted">Vratka zatím nemá položky.</td></tr>
                                                                    <?php else: foreach ($detailItems as $di): ?>
                                                                        <?php
                                                                            $diId = (int)($di['id'] ?? 0);
                                                                            $diQty = (float)($di['return_qty'] ?? 0);
                                                                            $diPrice = (float)($di['price_per_unit_with_vat'] ?? 0);
                                                                            $diLine = (float)($di['line_total_with_vat'] ?? 0);
                                                                            if ($diLine == 0.0 && $diQty != 0.0 && $diPrice != 0.0) $diLine = $diQty * $diPrice;
                                                                            $formId = 'paid-return-item-' . $ridDetail . '-' . $diId;
                                                                            $delFormId = 'paid-return-item-del-' . $ridDetail . '-' . $diId;
                                                                        ?>
                                                                        <tr>
                                                                            <td><input form="<?php echo h($formId); ?>" type="text" name="product_number" value="<?php echo h($di['product_number'] ?? ''); ?>"></td>
                                                                            <td><input form="<?php echo h($formId); ?>" type="text" name="product_name" value="<?php echo h($di['product_name'] ?? ''); ?>"></td>
                                                                            <td><input form="<?php echo h($formId); ?>" type="text" name="variant_description" value="<?php echo h($di['variant_description'] ?? ''); ?>"></td>
                                                                            <td><input form="<?php echo h($formId); ?>" type="text" name="ean" value="<?php echo h($di['ean'] ?? ''); ?>"></td>
                                                                            <td class="right"><input form="<?php echo h($formId); ?>" type="number" step="0.01" min="0" name="return_qty" value="<?php echo h(rtrim(rtrim(number_format($diQty, 2, '.', ''), '0'), '.')); ?>"></td>
                                                                            <td class="right"><input form="<?php echo h($formId); ?>" type="number" step="0.01" name="price_per_unit_with_vat" value="<?php echo h(number_format($diPrice, 2, '.', '')); ?>"></td>
                                                                            <td class="right"><strong><?php echo number_format($diLine, 2, ',', ' '); ?> Kč</strong></td>
                                                                            <td class="returns-actions-cell">
                                                                                <form method="post" id="<?php echo h($formId); ?>" class="returns-edit-item-form">
                                                                                    <input type="hidden" name="returns_action" value="update_item">
                                                                                    <input type="hidden" name="id" value="<?php echo $ridDetail; ?>">
                                                                                    <input type="hidden" name="item_id" value="<?php echo $diId; ?>">
                                                                                    <input type="hidden" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                                                                                    <input type="hidden" name="to" value="<?php echo h($returnsDateToStr); ?>">
                                                                                    <input type="hidden" name="q" value="<?php echo h($returnsSearchQuery); ?>">
                                                                                    <input type="hidden" name="tab" value="list">
                                                                                    <button type="submit" class="returns-mini-btn">Uložit</button>
                                                                                </form>
                                                                                <form method="post" id="<?php echo h($delFormId); ?>" class="returns-delete-item-form" onsubmit="return confirm('Opravdu odstranit položku z vratky?');">
                                                                                    <input type="hidden" name="returns_action" value="delete_item">
                                                                                    <input type="hidden" name="id" value="<?php echo $ridDetail; ?>">
                                                                                    <input type="hidden" name="item_id" value="<?php echo $diId; ?>">
                                                                                    <input type="hidden" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                                                                                    <input type="hidden" name="to" value="<?php echo h($returnsDateToStr); ?>">
                                                                                    <input type="hidden" name="q" value="<?php echo h($returnsSearchQuery); ?>">
                                                                                    <input type="hidden" name="tab" value="list">
                                                                                    <button type="submit" class="returns-mini-btn danger">Smazat</button>
                                                                                </form>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; endif; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div id="returns-modal-backdrop"></div>

            <script>
            (function() {
                var statsBtn  = document.getElementById('returns-stats-toggle');
                var statsWrap = document.getElementById('returns-stats-wrap');
                if (statsBtn && statsWrap) {
                    statsBtn.addEventListener('click', function() {
                        statsWrap.style.display = (statsWrap.style.display === 'none' || statsWrap.style.display === '') ? 'block' : 'none';
                    });
                }

                var returnsBackdrop = document.getElementById('returns-modal-backdrop');
                function closeReturnModal(){
                    document.querySelectorAll('.returns-detail-row.open').forEach(function(r){ r.classList.remove('open'); });
                    document.body.classList.remove('returns-modal-open');
                    if (returnsBackdrop) returnsBackdrop.classList.remove('open');
                }
                function openReturnModal(id){
                    closeReturnModal();
                    var detail = document.getElementById(id);
                    if (!detail) return;
                    detail.classList.add('open');
                    document.body.classList.add('returns-modal-open');
                    if (returnsBackdrop) returnsBackdrop.classList.add('open');
                    var box = detail.querySelector('.returns-detail-box');
                    if (box && !box.querySelector('.returns-modal-close')) {
                        var closeBtn = document.createElement('button');
                        closeBtn.type = 'button';
                        closeBtn.className = 'returns-modal-close';
                        closeBtn.textContent = 'Zavřít';
                        closeBtn.addEventListener('click', function(e){ e.preventDefault(); closeReturnModal(); });
                        box.insertBefore(closeBtn, box.firstChild);
                    }
                    setTimeout(function(){
                        var first = detail.querySelector('input, select, textarea, button');
                        if (first) { try { first.focus({preventScroll:true}); } catch(e) { first.focus(); } }
                    }, 50);
                }
                if (returnsBackdrop) returnsBackdrop.addEventListener('click', closeReturnModal);
                document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeReturnModal(); });
                document.querySelectorAll('.returns-master-row').forEach(function(row){
                    row.addEventListener('click', function(e){
                        if (e.target.closest('a,button,input,select,textarea,label,form')) return;
                        var id = row.getAttribute('data-detail-id');
                        if (!id) return;
                        openReturnModal(id);
                    });
                });

                function productLabel(p){
                    var parts = [];
                    if (p.product_number) parts.push(p.product_number);
                    if (p.product_name) parts.push(p.product_name);
                    if (p.variant_description) parts.push(p.variant_description);
                    if (p.ean) parts.push('EAN ' + p.ean);
                    var price = parseFloat(p.price_per_unit_with_vat || 0);
                    if (price) parts.push(price.toLocaleString('cs-CZ', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' Kč');
                    return parts.join(' | ');
                }

                document.querySelectorAll('.returns-add-product').forEach(function(box){
                    var input = box.querySelector('.returns-product-search-input');
                    var btn = box.querySelector('.returns-product-search-btn');
                    var select = box.querySelector('.returns-product-select');
                    var form = box.querySelector('.returns-add-item-form');
                    if (!input || !btn || !select || !form) return;

                    function fillForm(p){
                        form.querySelector('[name="product_number"]').value = p.product_number || '';
                        form.querySelector('[name="product_name"]').value = p.product_name || '';
                        form.querySelector('[name="variant_description"]').value = p.variant_description || '';
                        form.querySelector('[name="ean"]').value = p.ean || '';
                        if (p.price_per_unit_with_vat) form.querySelector('[name="price_per_unit_with_vat"]').value = p.price_per_unit_with_vat;
                    }

                    btn.addEventListener('click', function(){
                        var q = (input.value || '').trim();
                        if (q.length < 2) { alert('Zadej aspoň 2 znaky.'); return; }
                        btn.disabled = true;
                        btn.textContent = 'Hledám…';
                        fetch('index.php?view=returns&returns_api=product_search&q=' + encodeURIComponent(q), {credentials:'same-origin', cache:'no-store'})
                            .then(function(r){ return r.text(); })
                            .then(function(text){
                                var j = null;
                                try { j = JSON.parse(text); }
                                catch(e) { throw new Error('API nevrátilo JSON: ' + text.replace(/<[^>]*>/g,' ').slice(0,180)); }
                                select.innerHTML = '';
                                if (!j || !j.success || !j.items || !j.items.length) {
                                    var opt = document.createElement('option');
                                    opt.value = '';
                                    opt.textContent = 'Nic nenalezeno – produkt můžeš dopsat ručně';
                                    select.appendChild(opt);
                                    return;
                                }
                                var first = document.createElement('option');
                                first.value = '';
                                first.textContent = 'Vyber produkt…';
                                select.appendChild(first);
                                j.items.forEach(function(p){
                                    var opt = document.createElement('option');
                                    opt.value = JSON.stringify(p);
                                    opt.textContent = productLabel(p);
                                    select.appendChild(opt);
                                });
                            })
                            .catch(function(err){ alert('Chyba hledání produktu: ' + (err && err.message ? err.message : err)); })
                            .finally(function(){ btn.disabled = false; btn.textContent = 'Hledat'; });
                    });

                    input.addEventListener('keydown', function(e){
                        if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
                    });

                    select.addEventListener('change', function(){
                        if (!select.value) return;
                        try { fillForm(JSON.parse(select.value)); } catch(e) {}
                    });
                });
            })();
            </script>


        <?php elseif ($view === 'xmlfeedy'): ?>

            <?php
            $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

            // Spojené feedy – primárně SpojeneFeedy.csv, fallback na ALL_XML.csv.
            // Na hostingu se v minulosti některé soubory uložily malými písmeny, proto hledáme i case-insensitive.
            $mergedRelDir  = 'VSECHNY SPOJENE XML FEED';
            $findRelFile = function(string $dirRel, array $names) use ($docRoot) {
                $dirRel = trim($dirRel, '/');
                foreach ($names as $name) {
                    $rel = $dirRel . '/' . $name;
                    if (is_file($docRoot . '/' . $rel)) return $rel;
                }
                $dirAbs = $docRoot . '/' . $dirRel;
                if (is_dir($dirAbs)) {
                    $list = @scandir($dirAbs) ?: [];
                    foreach ($names as $name) {
                        foreach ($list as $entry) {
                            if ($entry === '.' || $entry === '..') continue;
                            if (strcasecmp($entry, $name) === 0 && is_file($dirAbs . '/' . $entry)) {
                                return $dirRel . '/' . $entry;
                            }
                        }
                    }
                }
                return $dirRel . '/' . ($names[0] ?? 'SpojeneFeedy.csv');
            };
            $mergedRelFile = $findRelFile($mergedRelDir, ['SpojeneFeedy.csv', 'spojenefeedy.csv', 'ALL_XML.csv', 'all_xml.csv']);
            $mergedAbsFile = $docRoot . '/' . $mergedRelFile;
            $mergedLabel = basename($mergedRelFile);

            $fmtSize = function($bytes) {
                $bytes = (int)$bytes;
                if ($bytes < 1024) return $bytes . ' B';
                $kb = $bytes / 1024;
                if ($kb < 1024) return number_format($kb, 1, ',', ' ') . ' KB';
                $mb = $kb / 1024;
                if ($mb < 1024) return number_format($mb, 1, ',', ' ') . ' MB';
                $gb = $mb / 1024;
                return number_format($gb, 2, ',', ' ') . ' GB';
            };
            $fmtDt = function($ts) {
                $ts = (int)$ts;
                if ($ts <= 0) return '';
                return date('d.m.Y H:i:s', $ts);
            };

            $sources   = [];
            $totalRows = 0;

            if (is_file($mergedAbsFile)) {
                $fh = @fopen($mergedAbsFile, 'rb');
                if ($fh) {
                    $lineNo = 0;
                    while (($line = fgets($fh)) !== false) {
                        $lineNo++;
                        $line = trim($line);
                        if ($line === '') continue;

                        // BOM na prvním řádku
                        if ($lineNo === 1) {
                            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                        }

                        $cols = explode(';', $line);

                        // přeskoč hlavičku
                        if ($lineNo === 1) {
                            $first = mb_strtolower(trim($cols[0] ?? ''), 'UTF-8');
                            if ($first === 'zdroj') {
                                continue;
                            }
                        }

                        $src = trim($cols[0] ?? '');
                        if ($src === '') continue;

                        // fallback: starší ALL_XML.csv mohl mít číselné zdroje 0/1/2
                        $srcMap = [
                            '0' => 'SportImport',
                            '1' => 'SILVINI',
                            '2' => 'Vavrys',
                            '3' => 'DEVOLD',
                        ];
$normalizeSrc = function(string $src): string {
    $s = trim($src);
    if ($s === '') return '';
    $sl = mb_strtolower($s, 'UTF-8');

    if ($sl === 'sportimport' || $sl === 'sportimport (ftp)' || $sl === 'sport import') return 'SportImport';
    if ($sl === 'silvini') return 'SILVINI';
    if ($sl === 'vavrys') return 'Vavrys';
    if ($sl === 'isadore') return 'ISADORE';
    if ($sl === 'devold' || $sl === 'devold (ftp)' || $sl === 'devold(ftp)' || $sl === 'devold (ftp )') return 'DEVOLD';

    return $s;
};

                        if (isset($srcMap[$src])) {
                            $src = $srcMap[$src];
                        }

                        $src = $normalizeSrc($src);
                        if ($src === '') continue;

                        $sources[$src] = ($sources[$src] ?? 0) + 1;
                        $totalRows++;
                    }
                    fclose($fh);
                }
            }


// Zobraz vždy všechny očekávané zdroje (i kdyby měl některý 0 řádků)
foreach (['SportImport','SILVINI','Vavrys','ISADORE','DEVOLD'] as $exp) {
    if (!isset($sources[$exp])) $sources[$exp] = 0;
}

            if (!empty($sources)) {
                ksort($sources, SORT_NATURAL | SORT_FLAG_CASE);
            }

            $mergedExists = is_file($mergedAbsFile);
            $mergedSize   = $mergedExists ? (int)@filesize($mergedAbsFile) : 0;
            $mergedMtime  = $mergedExists ? (int)@filemtime($mergedAbsFile) : 0;

            // Ručně nahraný sklad C-Store – samostatný spojený soubor pod hlavním SpojeneFeedy.csv.
            $mergedSkladRelFile = $findRelFile($mergedRelDir, ['SpojeneFeedy_CSTORE_SKLAD.csv', 'spojenefeedy_cstore_sklad.csv']);
            $mergedSkladAbsFile = $docRoot . '/' . $mergedSkladRelFile;
            $mergedSkladLabel   = basename($mergedSkladRelFile);
            $mergedSkladExists  = is_file($mergedSkladAbsFile);
            $mergedSkladSize    = $mergedSkladExists ? (int)@filesize($mergedSkladAbsFile) : 0;
            $mergedSkladMtime   = $mergedSkladExists ? (int)@filemtime($mergedSkladAbsFile) : 0;
            $mergedSkladRows    = 0;
            if ($mergedSkladExists) {
                $fhSklad = @fopen($mergedSkladAbsFile, 'rb');
                if ($fhSklad) {
                    $lineNoSklad = 0;
                    while (($rowSklad = fgetcsv($fhSklad, 0, ';')) !== false) {
                        $lineNoSklad++;
                        if (!$rowSklad || count($rowSklad) < 1) continue;
                        if ($lineNoSklad === 1) {
                            $firstSklad = mb_strtolower(trim((string)($rowSklad[0] ?? '')), 'UTF-8');
                            $firstSklad = preg_replace('/^\xEF\xBB\xBF/', '', $firstSklad);
                            if ($firstSklad === 'zdroj') continue;
                        }
                        if (trim((string)($rowSklad[0] ?? '')) === '') continue;
                        $mergedSkladRows++;
                    }
                    fclose($fhSklad);
                }
            }

            // Poslední sklad stažený z FTP pro horní rychlý postup.
            $ftpStockInfo = [];
            $ftpStockInfoFile = $docRoot . '/Dodavatele/SKLAD C-STORE/posledni_ftp_sklad_info.json';
            if (is_file($ftpStockInfoFile)) {
                $ftpStockInfoRaw = @file_get_contents($ftpStockInfoFile);
                $ftpStockInfoDecoded = $ftpStockInfoRaw !== false ? json_decode($ftpStockInfoRaw, true) : null;
                if (is_array($ftpStockInfoDecoded)) $ftpStockInfo = $ftpStockInfoDecoded;
            }

            // Ruční soubory dodavatelů a aktualizace _var podle SpojeneFeedy_CSTORE_SKLAD.csv.
            // Soubory jsou napevno připravené ve složce /RucniNahraniAktualizace/.
            $vavrysManualRelDir = 'RucniNahraniAktualizace';
            $appRoot = rtrim(str_replace('\\', '/', __DIR__), '/');
            $vavrysManualAbsDir = $appRoot . '/' . $vavrysManualRelDir;
            if (!is_dir($vavrysManualAbsDir)) {
                @mkdir($vavrysManualAbsDir, 0775, true);
            }
            $vavrysManualDirExists = is_dir($vavrysManualAbsDir);

            $vavrysUrl = function(string $rel): string {
                $rel = trim(str_replace('\\', '/', $rel), '/');
                if ($rel === '') return '';
                $parts = explode('/', $rel);
                $parts = array_map('rawurlencode', $parts);
                return implode('/', $parts);
            };

            $vavrysRelFromAbs = function(string $abs) use ($appRoot, $docRoot): string {
                $abs = str_replace('\\', '/', $abs);
                $roots = [
                    rtrim(str_replace('\\', '/', $appRoot), '/') . '/',
                    rtrim(str_replace('\\', '/', $docRoot), '/') . '/',
                ];
                foreach ($roots as $root) {
                    if ($root !== '/' && stripos($abs, $root) === 0) {
                        return ltrim(substr($abs, strlen($root)), '/');
                    }
                }
                return '';
            };

            $vavrysOutputParts = function(string $fileName): array {
                $base = basename($fileName);
                if (preg_match('~^(.*)_var\.csv$~i', $base, $m)) {
                    return [$m[1] . '_IMPORT_DO_ESHOPU_', '_var.csv'];
                }
                $withoutCsv = preg_replace('~\.csv$~i', '', $base);
                return [$withoutCsv . '_IMPORT_DO_ESHOPU_', '.csv'];
            };

            $vavrysLegacyOutputName = function(string $fileName): string {
                $base = basename($fileName);
                if (strcasecmp($base, 'NEWVavrysVariantyALL_var.csv') === 0) {
                    return 'NEWVavrysVariantyALL_IMPORT_DO_ESHOPU_var.csv';
                }
                if (preg_match('~^(.*)_var\.csv$~i', $base, $m)) {
                    return $m[1] . '_IMPORT_DO_ESHOPU_var.csv';
                }
                return preg_replace('~\.csv$~i', '_IMPORT_DO_ESHOPU.csv', $base);
            };

            $vavrysFindLatestOutput = function(string $fileName) use ($vavrysManualAbsDir, $vavrysOutputParts, $vavrysLegacyOutputName): array {
                [$prefix, $suffix] = $vavrysOutputParts($fileName);
                $bestAbs = '';
                $bestName = '';
                $bestMtime = 0;

                if (is_dir($vavrysManualAbsDir)) {
                    $list = @scandir($vavrysManualAbsDir) ?: [];
                    foreach ($list as $entry) {
                        if ($entry === '.' || $entry === '..') continue;
                        $abs = $vavrysManualAbsDir . '/' . $entry;
                        if (!is_file($abs)) continue;
                        if (strpos($entry, $prefix) === 0 && substr($entry, -strlen($suffix)) === $suffix) {
                            $mt = (int)@filemtime($abs);
                            if ($mt >= $bestMtime) {
                                $bestMtime = $mt;
                                $bestAbs = $abs;
                                $bestName = $entry;
                            }
                        }
                    }

                    // Kompatibilita se starým pevným názvem, pokud ještě existuje.
                    $legacy = $vavrysLegacyOutputName($fileName);
                    $legacyAbs = $vavrysManualAbsDir . '/' . $legacy;
                    if ($bestAbs === '' && is_file($legacyAbs)) {
                        $bestAbs = $legacyAbs;
                        $bestName = $legacy;
                        $bestMtime = (int)@filemtime($legacyAbs);
                    }
                }

                return [
                    'name' => $bestName,
                    'abs' => $bestAbs,
                    'mtime' => $bestMtime,
                ];
            };

            $vavrysReadChangeStats = function(string $outAbs): array {
                $statsFile = $outAbs !== '' ? ($outAbs . '.stats.json') : '';
                if ($statsFile === '' || !is_file($statsFile)) {
                    return [
                        'hidden_products' => 0,
                        'opened_products' => 0,
                        'created_at' => '',
                    ];
                }
                $raw = @file_get_contents($statsFile);
                $json = $raw !== false ? json_decode($raw, true) : null;
                if (!is_array($json)) {
                    return [
                        'hidden_products' => 0,
                        'opened_products' => 0,
                        'created_at' => '',
                    ];
                }
                return [
                    'hidden_products' => (int)($json['hidden_products'] ?? 0),
                    'opened_products' => (int)($json['opened_products'] ?? 0),
                    'created_at' => (string)($json['created_at'] ?? ''),
                ];
            };

            $vavrysCaseFile = function(string $dirAbs, array $names): ?string {
                $dirAbs = rtrim(str_replace('\\', '/', $dirAbs), '/');
                foreach ($names as $name) {
                    $p = $dirAbs . '/' . $name;
                    if (is_file($p)) return $p;
                }
                if (!is_dir($dirAbs)) return null;
                $list = @scandir($dirAbs) ?: [];
                foreach ($names as $name) {
                    foreach ($list as $entry) {
                        if ($entry === '.' || $entry === '..') continue;
                        if (strcasecmp($entry, $name) === 0 && is_file($dirAbs . '/' . $entry)) {
                            return $dirAbs . '/' . $entry;
                        }
                    }
                }
                return null;
            };

            $vavrysFindFixedFile = function(string $fileName, string $supplierKey = '') use ($appRoot, $docRoot, $vavrysManualAbsDir, $vavrysManualRelDir, $vavrysCaseFile): ?string {
                $names = array_values(array_unique([
                    $fileName,
                    strtolower($fileName),
                    strtoupper($fileName),
                    $fileName === 'NEwHlavniVavrys.csv' ? 'newhlavnivavrys.csv' : $fileName,
                    $fileName === 'NEWVavrysVariantyALL_var.csv' ? 'newvavrysvariantyall_var.csv' : $fileName,
                    $fileName === 'HlavniSilviniSS2026.csv' ? 'hlavnisilviniss2026.csv' : $fileName,
                    $fileName === 'VariantySILVINI_var.csv' ? 'variantysilvini_var.csv' : $fileName,
                    $fileName === 'HLAVNIAlea.csv' ? 'hlavnialea.csv' : $fileName,
                    $fileName === 'VariantyAlea_var.csv' ? 'variantyalea_var.csv' : $fileName,
                    $fileName === 'HlavniIsadore.csv' ? 'hlavniisadore.csv' : $fileName,
                    $fileName === 'VARIANTYYIsadore_var.csv' ? 'variantyyisadore_var.csv' : $fileName,
                ]));

                $supplierKey = trim(str_replace(['\\', '/'], '', $supplierKey));
                $supplierDirs = [];
                if ($supplierKey !== '') {
                    $supplierDirs[] = $supplierKey;
                    $supplierDirs[] = strtolower($supplierKey);
                    $supplierDirs[] = strtoupper($supplierKey);
                }

                $dirs = [
                    $vavrysManualAbsDir,
                    $vavrysManualAbsDir . '/' . $vavrysManualRelDir, // když je složka omylem nahraná vnořeně
                    $appRoot . '/' . $vavrysManualRelDir,
                    $appRoot . '/' . $vavrysManualRelDir . '/' . $vavrysManualRelDir,
                    $docRoot . '/' . $vavrysManualRelDir,
                    $docRoot . '/' . $vavrysManualRelDir . '/' . $vavrysManualRelDir,
                ];
                foreach ($supplierDirs as $sd) {
                    $dirs[] = $appRoot . '/Dodavatele na SS26 Aktualizace/' . $sd;
                    $dirs[] = $docRoot . '/Dodavatele na SS26 Aktualizace/' . $sd;
                }

                foreach (array_unique($dirs) as $dir) {
                    $p = $vavrysCaseFile($dir, $names);
                    if ($p) return $p;
                }
                return null;
            };

            $countCsvDataRows = function(string $absFile): int {
                if (!is_file($absFile)) return 0;
                $sample = @file_get_contents($absFile, false, null, 0, 32768);
                $delim = ';';
                if ($sample !== false) {
                    $cands = [
                        ';' => substr_count($sample, ';'),
                        ',' => substr_count($sample, ','),
                        "\t" => substr_count($sample, "\t"),
                        '|' => substr_count($sample, '|'),
                    ];
                    arsort($cands);
                    $delim = array_key_first($cands) ?: ';';
                }
                $fh = @fopen($absFile, 'rb');
                if (!$fh) return 0;
                $rows = 0;
                $line = 0;
                while (($r = fgetcsv($fh, 0, $delim)) !== false) {
                    $line++;
                    if ($line === 1) continue;
                    if (!$r || count($r) < 1) continue;
                    if (trim((string)($r[0] ?? '')) === '') continue;
                    $rows++;
                }
                fclose($fh);
                return $rows;
            };

            $manualSupplierConfigs = [
                'vavrys' => [
                    'title' => 'VAVRYS',
                    'anchor' => 'vavrys-aktualizace',
                    'files' => [
                        ['file' => 'NEWVavrysVariantyALL_var.csv', 'label' => 'Varianty Vavrys'],
                        ['file' => 'NEwHlavniVavrys.csv', 'label' => 'Hlavní Vavrys'],
                    ],
                ],
                'silvini' => [
                    'title' => 'SILVINI',
                    'anchor' => 'silvini-aktualizace',
                    'files' => [
                        ['file' => 'VariantySILVINI_var.csv', 'label' => 'Varianty Silvini'],
                        ['file' => 'HlavniSilviniSS2026.csv', 'label' => 'Hlavní Silvini'],
                    ],
                ],
                'ale' => [
                    'title' => 'ALÉ',
                    'anchor' => 'ale-aktualizace',
                    'files' => [
                        ['file' => 'VariantyAlea_var.csv', 'label' => 'Varianty ALÉ'],
                        ['file' => 'HLAVNIAlea.csv', 'label' => 'Hlavní ALÉ'],
                    ],
                ],
                'isadore' => [
                    'title' => 'ISADORE',
                    'anchor' => 'isadore-aktualizace',
                    'files' => [
                        ['file' => 'VARIANTYYIsadore_var.csv', 'label' => 'Varianty ISADORE'],
                        ['file' => 'HlavniIsadore.csv', 'label' => 'Hlavní ISADORE'],
                    ],
                ],
            ];

            $manualSupplierGroups = [];
            foreach ($manualSupplierConfigs as $supplierKey => $supplierCfg) {
                $files = [];
                $supplierChangeStats = [
                    'hidden_products' => 0,
                    'opened_products' => 0,
                    'created_at' => '',
                ];
                foreach ($supplierCfg['files'] as $item) {
                    $entry = $item['file'];
                    $label = $item['label'];
                    $abs = $vavrysFindFixedFile($entry, $supplierKey);
                    $isVar = (bool)preg_match('~_var\.csv$~i', $entry);
                    $outInfo = $vavrysFindLatestOutput($entry);
                    $outName = (string)($outInfo['name'] ?? '');
                    $outAbs  = (string)($outInfo['abs'] ?? '');
                    $rel = $abs ? $vavrysRelFromAbs($abs) : '';
                    $outRel = $outName !== '' ? ($vavrysManualRelDir . '/' . $outName) : '';
                    if (!$isVar && $outAbs !== '' && is_file($outAbs)) {
                        $supplierChangeStats = $vavrysReadChangeStats($outAbs);
                    }
                    $files[] = [
                        'supplier' => $supplierKey,
                        'name' => $entry,
                        'label' => $label,
                        'rel' => $rel !== '' ? $vavrysUrl($rel) : '',
                        'display_rel' => $rel,
                        'exists' => ($abs !== null && is_file($abs)),
                        'size' => ($abs !== null && is_file($abs)) ? (int)@filesize($abs) : 0,
                        'mtime' => ($abs !== null && is_file($abs)) ? (int)@filemtime($abs) : 0,
                        'is_var' => $isVar,
                        'output_name' => $outName,
                        'output_rel' => $outName !== '' ? $vavrysUrl($outRel) : '',
                        'output_exists' => ($outAbs !== '' && is_file($outAbs)),
                        'output_size' => ($outAbs !== '' && is_file($outAbs)) ? (int)@filesize($outAbs) : 0,
                        'output_mtime' => ($outAbs !== '' && is_file($outAbs)) ? (int)@filemtime($outAbs) : 0,
                        'output_rows' => ($outAbs !== '' && is_file($outAbs)) ? $countCsvDataRows($outAbs) : 0,
                    ];
                }
                $supplierCfg['files'] = $files;
                $supplierCfg['change_stats'] = $supplierChangeStats;
                $manualSupplierGroups[$supplierKey] = $supplierCfg;
            }

            $allSupplierChangesFindLatest = function(string $prefix, string $suffix) use ($vavrysManualAbsDir): array {
                $bestAbs = '';
                $bestName = '';
                $bestMtime = 0;
                if (is_dir($vavrysManualAbsDir)) {
                    $list = @scandir($vavrysManualAbsDir) ?: [];
                    foreach ($list as $entry) {
                        if ($entry === '.' || $entry === '..') continue;
                        if (strpos($entry, $prefix) !== 0) continue;
                        if (substr($entry, -strlen($suffix)) !== $suffix) continue;
                        $abs = $vavrysManualAbsDir . '/' . $entry;
                        if (!is_file($abs)) continue;
                        $mt = (int)@filemtime($abs);
                        if ($mt >= $bestMtime) {
                            $bestMtime = $mt;
                            $bestAbs = $abs;
                            $bestName = $entry;
                        }
                    }
                }
                return ['name' => $bestName, 'abs' => $bestAbs, 'mtime' => $bestMtime];
            };

            $allSupplierChangesReadStats = function(string $abs): array {
                $statsFile = $abs !== '' ? ($abs . '.stats.json') : '';
                $defaults = [
                    'hidden_products' => 0,
                    'opened_products' => 0,
                    'main_changed_rows' => 0,
                    'variant_changed_rows' => 0,
                    'variant_hidden_rows' => 0,
                    'variant_opened_rows' => 0,
                    'created_at' => '',
                ];
                if ($statsFile === '' || !is_file($statsFile)) return $defaults;
                $raw = @file_get_contents($statsFile);
                $json = $raw !== false ? json_decode($raw, true) : null;
                if (!is_array($json)) return $defaults;
                foreach ($defaults as $k => $v) {
                    if (!array_key_exists($k, $json)) $json[$k] = $v;
                }
                return $json;
            };

            $allSupplierVarLatest = $allSupplierChangesFindLatest('VsechnyDodavateleVarianty', '_var.csv');
            $allSupplierMainLatest = $allSupplierChangesFindLatest('VsechnyDodavateleHlavni', '.csv');
            $allSupplierChangesStats = $allSupplierChangesReadStats((string)($allSupplierMainLatest['abs'] ?? ''));


            $allSuppliersProcessStatsFile = $vavrysManualAbsDir . '/_zpracovat_vse.stats.json';
            $allSuppliersProcessStats = [];
            if (is_file($allSuppliersProcessStatsFile)) {
                $rawAllProcess = @file_get_contents($allSuppliersProcessStatsFile);
                $jsonAllProcess = $rawAllProcess !== false ? json_decode($rawAllProcess, true) : null;
                if (is_array($jsonAllProcess)) {
                    $allSuppliersProcessStats = $jsonAllProcess;
                }
            }


            // Přehled feedů (uložené soubory)
            $feeds = [
                [
                    'name'      => 'SportImport',
                    'mode'      => 'sportimport',
                    'dir'       => 'Dodavatele/SportImport',
                    'xml'       => 'catalogue.xml',
                    'csv_full'  => 'catalogue.csv',
                    'csv_extra' => 'AktualizacniSportImport.csv',
                ],
                [
                    'name'      => 'SILVINI',
                    'mode'      => 'silvini',
                    'dir'       => 'Dodavatele/SILVINI',
                    'xml'       => 'stockreport.xml',
                    'csv_full'  => 'stockreport.csv',
                    'csv_extra' => 'AktualizacniSilvini.csv',
                ],
                [
                    'name'      => 'Vavrys',
                    'mode'      => 'vavrys',
                    'dir'       => 'Dodavatele/Vavrys',
                    'xml'       => 'vavrys.xml',
                    'csv_full'  => 'vavrys.csv',
                    'csv_extra' => 'AktualizacniVavrys.csv',
                ],
[
    'name'      => 'ISADORE',
    'mode'      => 'isadore',
    'dir'       => 'Dodavatele/ISADORE',
    'xml'       => 'isadore.xml',
    'csv_full'  => 'isadore.csv',
    'csv_extra' => 'AktualizacniIsadore.csv',
],

                [
                    'name'      => 'DEVOLD (FTP)',
                    'mode'      => 'devold',
                    'dir'       => 'Dodavatele/DEVOLD/XML',
                    'xml'       => 'C.xml',
                    'csv_full'  => 'devold.csv',
                    'csv_extra' => 'AktualizacniDevold.csv',
                ],
            ];

            $fileInfo = function(string $rel) use ($docRoot) {
                $rel = ltrim(str_replace('\\', '/', $rel), '/');
                $abs = $docRoot . '/' . $rel;

                if (!is_file($abs)) {
                    $dirRel = trim(str_replace('\\', '/', dirname($rel)), '/');
                    if ($dirRel === '.') $dirRel = '';
                    $base = basename($rel);
                    $dirAbs = rtrim($docRoot . '/' . $dirRel, '/');
                    if (is_dir($dirAbs)) {
                        foreach ((@scandir($dirAbs) ?: []) as $entry) {
                            if ($entry === '.' || $entry === '..') continue;
                            if (strcasecmp($entry, $base) === 0 && is_file($dirAbs . '/' . $entry)) {
                                $rel = ($dirRel !== '' ? ($dirRel . '/') : '') . $entry;
                                $abs = $docRoot . '/' . $rel;
                                break;
                            }
                        }
                    }
                }

                if (!is_file($abs)) return null;
                return [
                    'rel'   => $rel,
                    'size'  => (int)@filesize($abs),
                    'mtime' => (int)@filemtime($abs),
                ];
            };
            ?>

            <div class="card">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                                <h1>XML feed – Dodavatelé</h1>

                <div class="supplier-quick-guide" id="rychly-postup-dodavatele">
                    <div class="supplier-quick-guide-header">
                        <div>
                            <div class="supplier-quick-guide-title">Rychlý postup pro aktualizaci dodavatelů</div>
                            <div class="supplier-quick-guide-subtitle">Postupuj shora dolů. Původní detailní zpracování zůstává níže beze změny.</div>
                        </div>
                        <div class="supplier-quick-guide-note">Výstup pro e-shop = pouze změny</div>
                    </div>

                    <div class="supplier-auto-all-box" style="border-color:#047857; background:linear-gradient(135deg,#e8fff2 0%,#ffffff 100%);">
                        <div>
                            <div class="supplier-auto-all-title">Nejrychlejší postup – sklad z FTP a vše se dokončí samo</div>
                            <div class="supplier-auto-all-text">
                                Klikni na tlačítko. Modul se nejdřív podívá na FTP a ukáže, jaký skladový soubor našel, datum uložení a velikost. Teprve po potvrzení pokračuje: načte dodavatelské XML feedy, stáhne nejnovější sklad C-Store z FTP, přiřadí sklad, zpracuje všechny dodavatele a vygeneruje soubory <strong>pouze se změnami</strong> pro nahrání do e-shopu.
                                <br><strong>Po kontrole FTP musíš potvrdit pokračování.</strong>
                                <?php if (!empty($ftpStockInfo)): ?>
                                    <div class="supplier-quick-meta" style="margin-top:6px;">
                                        Poslední FTP sklad:
                                        <?php echo h((string)($ftpStockInfo['ftp_file'] ?? $ftpStockInfo['source_file'] ?? '')); ?>
                                        <?php if (!empty($ftpStockInfo['ftp_mtime_text'])): ?> · FTP datum: <?php echo h((string)$ftpStockInfo['ftp_mtime_text']); ?><?php endif; ?>
                                        <?php if (!empty($ftpStockInfo['downloaded_at'])): ?> · staženo: <?php echo h((string)$ftpStockInfo['downloaded_at']); ?><?php endif; ?>
                                        <?php if (isset($ftpStockInfo['rows_accepted'])): ?> · EANů: <?php echo (int)$ftpStockInfo['rows_accepted']; ?><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <form method="post" action="auto_sklad_ftp_full_process.php" class="supplier-auto-all-form">
                            <input type="hidden" name="auto_ftp_all" value="1">
                            <button type="submit" class="supplier-auto-all-button" onclick="if(this.form){ this.disabled=true; this.innerText='Kontroluji FTP…'; this.form.submit(); } return false;">Zkontrolovat sklad na FTP a pak vytvořit soubory</button>
                        </form>
                    </div>

                    <div class="supplier-auto-all-box">
                        <div>
                            <div class="supplier-auto-all-title">Nejrychlejší postup – nahraj sklad a vše se dokončí samo</div>
                            <div class="supplier-auto-all-text">
                                Vyber aktuální CSV sklad C-Store a klikni na tlačítko. Modul automaticky načte dodavatelské XML feedy, přiřadí sklad C-Store, zpracuje všechny dodavatele a vygeneruje soubory <strong>pouze se změnami</strong> pro nahrání do e-shopu.
                                <br><strong>Po kliknutí počkej, může to trvat delší dobu.</strong>
                            </div>
                        </div>
                        <form method="post" action="upload_sklad_cstore.php" enctype="multipart/form-data" class="supplier-auto-all-form">
                            <input type="hidden" name="auto_all" value="1">
                            <input type="file" name="sklad_csv" accept=".csv,text/csv" required>
                            <button type="submit" class="supplier-auto-all-button" onclick="if(this.form){ this.disabled=true; this.innerText='Pracuji… načítám feedy a generuji soubory'; this.form.submit(); } return false;">Nahrát sklad a vytvořit soubory pro e-shop</button>
                        </form>
                    </div>

                    <div class="supplier-quick-steps">
                        <div class="supplier-quick-step">
                            <div class="supplier-quick-step-top">
                                <div class="supplier-quick-number">1</div>
                                <div class="supplier-quick-step-title">Načti dodavatelské XML feedy</div>
                            </div>
                            <div class="supplier-quick-step-text">
                                Stáhne a spojí aktuální XML feedy dodavatelů.
                                <?php if (!empty($mergedExists)): ?>
                                    <div class="supplier-quick-meta">Poslední spojený soubor: <?php echo h($fmtDt((int)$mergedMtime)); ?>, řádků <?php echo (int)$totalRows; ?>.</div>
                                <?php endif; ?>
                            </div>
                            <a class="supplier-quick-action-btn" href="run_feeds.php" id="quickRunFeedsLink">Načti dodavatelské XML feedy</a>
                        </div>

                        <div class="supplier-quick-step">
                            <div class="supplier-quick-step-top">
                                <div class="supplier-quick-number">2</div>
                                <div class="supplier-quick-step-title">Nahraj C-Store skladové zásoby</div>
                            </div>
                            <div class="supplier-quick-step-text">
                                Ruční krok bez automatického dokončení. Pro nejrychlejší postup použij zelený blok výše.
                                <?php if (!empty($mergedSkladExists)): ?>
                                    <div class="supplier-quick-meta">Skladový spojený soubor: <?php echo h($fmtDt((int)$mergedSkladMtime)); ?>, řádků <?php echo (int)$mergedSkladRows; ?>.</div>
                                <?php endif; ?>
                            </div>
                            <form method="post" action="upload_sklad_cstore.php" enctype="multipart/form-data" class="supplier-quick-upload-form">
                                <input type="file" name="sklad_csv" accept=".csv,text/csv" required>
                                <button type="submit" class="supplier-quick-action-btn">Nahraj sklad C-Store</button>
                            </form>
                        </div>

                        <div class="supplier-quick-step">
                            <div class="supplier-quick-step-top">
                                <div class="supplier-quick-number">3</div>
                                <div class="supplier-quick-step-title">Zpracuj všechny dodavatele</div>
                            </div>
                            <div class="supplier-quick-step-text">
                                Spustí VAVRYS, SILVINI, ALÉ a ISADORE najednou. Předchozí výstupy se uloží pro porovnání změn.
                                <?php if (!empty($allSuppliersProcessStats['created_at'])): ?>
                                    <div class="supplier-quick-meta">Poslední zpracování: <?php echo h((string)$allSuppliersProcessStats['created_at']); ?>.</div>
                                <?php endif; ?>
                            </div>
                            <form method="post" action="process_all_suppliers.php" style="margin:0;">
                                <button type="submit" class="supplier-quick-action-btn" onclick="if(this.form){ this.disabled=true; this.innerText='Zpracovávám…'; this.form.submit(); } return false;">Zpracovat všechny dodavatele</button>
                            </form>
                        </div>

                        <div class="supplier-quick-step">
                            <div class="supplier-quick-step-top">
                                <div class="supplier-quick-number">4</div>
                                <div class="supplier-quick-step-title">Soubory pro nahrání do e-shopu</div>
                            </div>
                            <div class="supplier-quick-step-text">
                                Nejdřív vygeneruj soubory pouze se změnami. Potom stáhni varianty a hlavní produkty pro hromadný import.
                                <?php if (!empty($allSupplierChangesStats['created_at'])): ?>
                                    <div class="supplier-quick-meta">Poslední změny: <?php echo h((string)$allSupplierChangesStats['created_at']); ?>. Schované <?php echo (int)($allSupplierChangesStats['hidden_products'] ?? 0); ?> / otevřené <?php echo (int)($allSupplierChangesStats['opened_products'] ?? 0); ?>.</div>
                                <?php endif; ?>
                            </div>
                            <form method="post" action="generate_all_supplier_changes.php" style="margin:0;">
                                <button type="submit" class="supplier-quick-action-btn" onclick="if(this.form){ this.disabled=true; this.innerText='Generuji změny…'; this.form.submit(); } return false;">Vygenerovat pouze změny</button>
                            </form>
                            <div class="supplier-quick-downloads">
                                <?php if (!empty($allSupplierVarLatest['name'])): ?>
                                    <a class="supplier-quick-download-link" href="<?php echo h($vavrysUrl($vavrysManualRelDir . '/' . $allSupplierVarLatest['name'])); ?>" download>Hromadný import Variant<br><span class="supplier-quick-meta"><?php echo h($allSupplierVarLatest['name']); ?> · řádků <?php echo (int)$countCsvDataRows((string)$allSupplierVarLatest['abs']); ?></span></a>
                                <?php else: ?>
                                    <div class="supplier-quick-meta">Hromadný import Variant zatím není vygenerovaný.</div>
                                <?php endif; ?>

                                <?php if (!empty($allSupplierMainLatest['name'])): ?>
                                    <a class="supplier-quick-download-link" href="<?php echo h($vavrysUrl($vavrysManualRelDir . '/' . $allSupplierMainLatest['name'])); ?>" download>Hromadný import Hlavních produktů<br><span class="supplier-quick-meta"><?php echo h($allSupplierMainLatest['name']); ?> · řádků <?php echo (int)$countCsvDataRows((string)$allSupplierMainLatest['abs']); ?></span></a>
                                <?php else: ?>
                                    <div class="supplier-quick-meta">Hromadný import Hlavních produktů zatím není vygenerovaný.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>


                <?php if (($_GET['ftp_auto_all'] ?? '') === 'ok'): ?>
                    <div class="msg" style="margin-bottom:14px;" id="ftp-auto-all-hotovo">
                        Automatická FTP aktualizace je hotová. Dodavatelské feedy byly načtené, nejnovější sklad C-Store stažený z FTP, všichni dodavatelé zpracovaní a soubory pouze se změnami jsou připravené v části <strong>Soubory pro nahrání do e-shopu</strong>.
                        <?php if (!empty($_GET['ftp_file'])): ?><br>FTP soubor: <strong><?php echo h((string)$_GET['ftp_file']); ?></strong><?php endif; ?>
                        <?php if (!empty($_GET['rows'])): ?> · EANů ze skladu: <strong><?php echo h((string)$_GET['rows']); ?></strong><?php endif; ?>
                    </div>
                <?php elseif (($_GET['ftp_auto_all'] ?? '') === 'err'): ?>
                    <div class="msg msg-error" style="margin-bottom:14px;" id="ftp-auto-all-chyba">
                        Automatická FTP aktualizace se zastavila: <?php echo h($_GET['msg'] ?? 'neznámá chyba'); ?>. Níže můžeš pokračovat ručně přes jednotlivé kroky.
                    </div>
                <?php endif; ?>

                <?php if (($_GET['auto_all'] ?? '') === 'ok'): ?>
                    <div class="msg" style="margin-bottom:14px;" id="auto-all-hotovo">
                        Automatická aktualizace je hotová. Sklad byl nahrán, dodavatelské feedy načtené, všichni dodavatelé zpracovaní a soubory pouze se změnami jsou připravené v části <strong>Soubory pro nahrání do e-shopu</strong>.
                    </div>
                <?php elseif (($_GET['auto_all'] ?? '') === 'err'): ?>
                    <div class="msg msg-error" style="margin-bottom:14px;" id="auto-all-chyba">
                        Sklad byl nahrán, ale automatické dokončení se zastavilo: <?php echo h($_GET['msg'] ?? 'neznámá chyba'); ?>. Níže můžeš pokračovat ručně přes jednotlivé kroky.
                    </div>
                <?php endif; ?>

                <?php if (($_GET['sklad_upload'] ?? '') === 'ok'): ?>
                    <div class="msg" style="margin-bottom:14px;">
                        Sklad C-Store byl nahrán. Použito pevně <strong>F + G</strong> a stará skladová data byla nahrazena novým uploadem.
                        Zpracováno řádků: <strong><?php echo h($_GET['rows'] ?? '0'); ?></strong>
                        z celkem <strong><?php echo h($_GET['total'] ?? '0'); ?></strong>.
                    </div>
                <?php elseif (($_GET['sklad_upload'] ?? '') === 'err'): ?>
                    <div class="msg msg-error" style="margin-bottom:14px;">
                        Nahrání skladu C-Store se nepovedlo: <?php echo h($_GET['msg'] ?? 'neznámá chyba'); ?>
                    </div>
                <?php endif; ?>

                <?php
                    $manualUpdateSupplier = strtolower((string)($_GET['supplier'] ?? 'vavrys'));
                    $manualUpdateTitleMap = ['vavrys' => 'VAVRYS', 'silvini' => 'SILVINI', 'ale' => 'ALÉ', 'isadore' => 'ISADORE'];
                    $manualUpdateTitle = $manualUpdateTitleMap[$manualUpdateSupplier] ?? strtoupper($manualUpdateSupplier);
                    $manualUpdateMsgId = ($manualUpdateSupplier !== '' ? $manualUpdateSupplier : 'vavrys') . '-aktualizace-msg';
                ?>
                <?php if (($_GET['vavrys_update'] ?? '') === 'ok'): ?>
                    <div class="msg" style="margin-bottom:14px;" id="<?php echo h($manualUpdateMsgId); ?>">
                        <?php echo h($manualUpdateTitle); ?> aktualizace je hotová – <strong>připraveno na import do eshopu</strong>.
                        Řádků: <strong><?php echo h($_GET['rows'] ?? '0'); ?></strong>,
                        nalezeno: <strong><?php echo h($_GET['found'] ?? '0'); ?></strong>,
                        nenalezeno doplněno jako 1: <strong><?php echo h($_GET['missing'] ?? '0'); ?></strong>.
                    </div>
                <?php elseif (($_GET['vavrys_update'] ?? '') === 'err'): ?>
                    <div class="msg msg-error" style="margin-bottom:14px;" id="<?php echo h($manualUpdateMsgId); ?>">
                        <?php echo h($manualUpdateTitle); ?> aktualizace se nepovedla: <?php echo h($_GET['msg'] ?? 'neznámá chyba'); ?>
                    </div>
                <?php endif; ?>

                <?php if (($_GET['all_supplier_changes'] ?? '') === 'ok'): ?>
                    <div class="msg" style="margin-bottom:14px;" id="vsechny-dodavatele-zmeny-msg">
                        Vygenerováno vše – změnové soubory pro všechny dodavatele jsou připravené.
                        Schované produkty: <strong><?php echo h($_GET['hidden_products'] ?? '0'); ?></strong>
                        &nbsp; | &nbsp;
                        Otevřené produkty: <strong><?php echo h($_GET['opened_products'] ?? '0'); ?></strong>.
                    </div>
                <?php elseif (($_GET['all_supplier_changes'] ?? '') === 'err'): ?>
                    <div class="msg msg-error" style="margin-bottom:14px;" id="vsechny-dodavatele-zmeny-msg">
                        Hromadné vygenerování změn se nepovedlo: <?php echo h($_GET['msg'] ?? 'neznámá chyba'); ?>
                    </div>
                <?php endif; ?>

                <?php if (($_GET['vavrys_upload'] ?? '') === 'ok'): ?>
                    <div class="msg" style="margin-bottom:14px;" id="vavrys-upload-msg">
                        VAVRYS soubory byly nahrány do složky <strong>/RucniNahraniAktualizace/</strong>.
                        Počet souborů: <strong><?php echo h($_GET['files'] ?? '0'); ?></strong>.
                    </div>
                <?php elseif (($_GET['vavrys_upload'] ?? '') === 'err'): ?>
                    <div class="msg msg-error" style="margin-bottom:14px;" id="vavrys-upload-msg">
                        Nahrání VAVRYS souborů se nepovedlo: <?php echo h($_GET['msg'] ?? 'neznámá chyba'); ?>
                    </div>
                <?php endif; ?>


                <?php
                // =========================
                // KE STAŽENÍ (centrální seznam)
                // =========================
                $scanDir = function(string $dirRel, array $patterns) use ($docRoot) {
                    $out = [];
                    $docRootReal = realpath($docRoot);
                    $absBase = realpath($docRoot . '/' . ltrim($dirRel, '/'));
                    if ($docRootReal === false || $absBase === false) return $out;
                    if (strpos($absBase, $docRootReal) !== 0) return $out;

                    foreach ($patterns as $pat) {
                        foreach (glob($absBase . '/' . $pat) ?: [] as $abs) {
                            if (!is_file($abs)) continue;
                            $bn = basename($abs);
                            $rel = rtrim($dirRel, '/') . '/' . $bn;
                            $out[$rel] = [
                                'rel'   => $rel,
                                'name'  => $bn,
                                'size'  => (int)@filesize($abs),
                                'mtime' => (int)@filemtime($abs),
                            ];
                        }
                    }

                    // seřadit podle nejnovějších
                    uasort($out, function($a, $b){
                        return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
                    });

                    return array_values($out);
                };

                $downloads = [];

                // 1) Spojené feedy – ukaž všechny CSV ve složce (ALL_XML/SpojeneFeedy i starší lowercase názvy)
                foreach ($scanDir($mergedRelDir, ['*.csv', '*.CSV']) as $it) {
                    $downloads[] = [
                        'label' => $it['name'],
                        'rel'   => $it['rel'],
                        'size'  => $it['size'],
                        'mtime' => $it['mtime'],
                    ];
                }

                // 2) Aktualizační CSV všech dodavatelů – po každé aktualizaci rychlé stažení dole v modulu
                foreach ($feeds as $feedRow) {
                    $relExtra = $feedRow['dir'] . '/' . $feedRow['csv_extra'];
                    $infoExtra = $fileInfo($relExtra);
                    if ($infoExtra) {
                        $downloads[] = [
                            'label' => $feedRow['name'] . ' – ' . basename($infoExtra['rel']),
                            'rel'   => $infoExtra['rel'],
                            'size'  => $infoExtra['size'],
                            'mtime' => $infoExtra['mtime'],
                        ];
                    }
                }

                // 3) Vavrys komplet – všechny *_var.csv (včetně aktualizovaného)
                foreach ($scanDir('Aktualizace_CSTORE/VAVRYS KOMPLET', ['*_var.csv']) as $it) {
                    $downloads[] = [
                        'label' => $it['name'],
                        'rel'   => $it['rel'],
                        'size'  => $it['size'],
                        'mtime' => $it['mtime'],
                    ];
                }

                // 4) Další soubory – vše co dáš do složky /Aktualizace_CSTORE/KE_STAZENI/
                foreach ($scanDir('Aktualizace_CSTORE/KE_STAZENI', ['*.*']) as $it) {
                        if (preg_match('~\.json$~i', $it['name'])) { continue; }
                        if (strcasecmp($it['name'], 'AktualizovaneVarianty_var.csv') === 0) { continue; }
$downloads[] = [
                        'label' => $it['name'],
                        'rel'   => $it['rel'],
                        'size'  => $it['size'],
                        'mtime' => $it['mtime'],
                    ];
                }

                // Odstranit duplicity podle názvu souboru (basename) – pokud je stejný soubor v různých složkách,
                // ponecháme jen ten s nejnovějším mtime.
                $uniq = [];
                foreach ($downloads as $d) {
                    $key = mb_strtolower(basename($d['rel']), 'UTF-8');
                    if (!isset($uniq[$key]) || (($d['mtime'] ?? 0) > ($uniq[$key]['mtime'] ?? 0))) {
                        $uniq[$key] = $d;
                    }
                }
                $downloads = array_values($uniq);
                usort($downloads, function($a, $b){
                    return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
                });
                ?>

                
                
                <!-- DOPLNĚNÍ (FIXNĚ): VARIANTYProduktyVavryskomplet_var.csv (Q -> S podle ALL_XML.csv) -->
<div class="box" style="margin-bottom:14px;">
    <div style="font-weight:700; margin-bottom:8px;">Doplnění VARIANTYProduktyVavryskomplet_var.csv (Q → S)</div>
    <div class="muted" style="margin-bottom:10px;">
        Zdroj: <strong>/VSECHNY SPOJENE XML FEED/ALL_XML.csv</strong> (B=klíč, C=hodnota)<br>
        Cíl: <strong>/Aktualizace_CSTORE/VAVRYS KOMPLET/VARIANTYProduktyVavryskomplet_var.csv</strong> (Q=klíč → S=hodnota; nenalezeno = 1)
    </div>
    <a class="btn-full" href="fill_var_Q_to_S.php" target="_blank" rel="noopener">Spustit doplnění (otevře log)</a>
    <div class="muted" style="margin-top:8px;">Po dokončení uvidíš: <strong>STATUS: DOKONČENO</strong> + počty změn/přepisů + <strong>KONEC</strong>.</div>
</div>

                <!-- NOVĚ: C-Store sklad export (FTP) – aktualizační tlačítko + cílový CSV ke stažení -->
                <?php
                    $skladDir = 'Dodavatele/SKLAD C-STORE';
                    $skladTargetRel = $skladDir . '/AktualniExportSkladu.csv';
                    $skladFullRel   = $skladDir . '/celySkladExport.csv';
                    $skladTargetInfo = $fileInfo($skladTargetRel);
                    $skladFullInfo   = $fileInfo($skladFullRel);
                ?>
                <div class="box" style="margin-bottom:14px;">
                    <div style="font-weight:700; margin-bottom:8px;">C-Store sklad export (FTP)</div>
                    <div class="muted" style="margin-bottom:10px;">
                        Stáhne nejnovější soubor z <strong>/stock_export/manual</strong> a vytvoří cíl <strong>AktualniExportSkladu.csv</strong>.
                    </div>

                    <a class="btn-full" href="update_sklad_export.php" target="_blank" rel="noopener">Zkontrolovat a aktualizovat sklad (FTP)</a>

                    <div style="margin-top:12px;"></div>

                    <?php if ($skladTargetInfo): ?>
                        <a class="btn-full" href="<?php echo h($skladTargetInfo['rel'] ?? $skladTargetRel); ?>" download>Stáhnout AktualniExportSkladu.csv (CÍL)</a>
                        <div class="muted" style="margin-top:8px;">
                            CÍL: <strong><?php echo h($fmtSize($skladTargetInfo['size'])); ?></strong>
                            • Aktualizace: <strong><?php echo h($fmtDt($skladTargetInfo['mtime'])); ?></strong>
                        </div>
                    <?php else: ?>
                        <div class="msg msg-error" style="margin-top:10px;">Soubor CÍL zatím neexistuje: <?php echo h('/' . $skladTargetRel); ?></div>
                        <div class="muted" style="margin-top:6px;">Nejdřív klikni <strong>Aktualizovat sklad (FTP)</strong>.</div>
                    <?php endif; ?>

                    <?php if ($skladFullInfo): ?>
                        <div class="muted" style="margin-top:10px;">
                            Zdrojová kopie: <a href="<?php echo h($skladFullInfo['rel'] ?? $skladFullRel); ?>" download><?php echo h('celySkladExport.csv'); ?></a>
                            • <strong><?php echo h($fmtSize($skladFullInfo['size'])); ?></strong>
                            • <strong><?php echo h($fmtDt($skladFullInfo['mtime'])); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

                <?php
                    // Vavrys komplet – výstup generovaný v download_feeds.php
                    $vkDirRel = 'Aktualizace_CSTORE/VAVRYS KOMPLET';
                    $vkSrcRel = $vkDirRel . '/VARIANTYIProduktyVavryskomplet_var.csv';
                    $vkOutRel = $vkDirRel . '/VARIANTYIProduktyVavryskomplet_updated_var.csv';
                    $vkSrcInfo = $fileInfo($vkSrcRel);
                    $vkOutInfo = $fileInfo($vkOutRel);
                ?>
                <div class="box" style="margin-bottom:14px;">
                    <div style="font-weight:700; margin-bottom:8px;">Vavrys komplet – doplnění dostupnosti</div>
                    <div class="muted" style="margin-bottom:10px;">
                        Po spuštění <strong>download_feeds.php</strong> se vytvoří nový soubor, kde se do sloupce <strong>S</strong>
                        doplní hodnoty z <strong>ALL_MC</strong> (match dle EAN). Pokud se nenajde, dosadí se <strong>1</strong>.
                    </div>

                    <?php if ($vkSrcInfo): ?>
                        <div class="muted" style="margin-top:6px;">
                            Zdroj: <a href="<?php echo h($vkSrcInfo['rel'] ?? $vkSrcRel); ?>" download><?php echo h(basename($vkSrcRel)); ?></a>
                            • <strong><?php echo h($fmtSize($vkSrcInfo['size'])); ?></strong>
                            • <strong><?php echo h($fmtDt($vkSrcInfo['mtime'])); ?></strong>
                        </div>
                    <?php else: ?>
                        <div class="msg msg-error" style="margin-top:10px;">Zdrojový soubor neexistuje: <?php echo h('/' . $vkSrcRel); ?></div>
                    <?php endif; ?>

                    <?php if ($vkOutInfo): ?>
                        <a class="btn-full" style="margin-top:12px;" href="<?php echo h($vkOutInfo['rel'] ?? $vkOutRel); ?>" download>Stáhnout <?php echo h(basename($vkOutRel)); ?></a>
                        <div class="muted" style="margin-top:8px;">
                            Výstup: <strong><?php echo h($fmtSize($vkOutInfo['size'])); ?></strong>
                            • Aktualizace: <strong><?php echo h($fmtDt($vkOutInfo['mtime'])); ?></strong>
                        </div>
                    <?php else: ?>
                        <div class="muted" style="margin-top:10px;">Výstup ještě není vytvořen. Spusť <strong>Spustit aktualizaci feedů</strong>.</div>
                    <?php endif; ?>
                </div>

<!-- PŮVODNÍ INFORMACE / ODKAZY -->
                <div class="box">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                        <div style="font-weight:700;">Uložené feedy</div>
                        <button type="button" id="feedsCheckAll" class="btn-full" style="padding:8px 12px; width:auto;">Zaškrtnout vše</button>
                    </div>

                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th style="width:110px;">Aktualizovat</th>
                                <th>Dodavatel</th>
                                <th>XML</th>
                                <th>CSV (full)</th>
                                <th>Aktualizační CSV</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($feeds as $f): ?>
                            <tr>
                                <td style="text-align:center;">
                                    <input class="feedChk" type="checkbox" name="suppliers[]" value="<?php echo h($f['mode'] ?? ''); ?>" checked>
                                </td>
                                <td><?php echo h($f['name']); ?></td>

                                <td>
                                    <?php $rel = $f['dir'].'/'.$f['xml']; $info = $fileInfo($rel); ?>
                                    <?php if ($info): ?>
                                        <a href="<?php echo h($info['rel'] ?? $rel); ?>" target="_blank"><?php echo h(basename($info['rel'] ?? $f['xml'])); ?></a><br>
                                        <span class="muted"><?php echo h($fmtSize($info['size']) . ' • ' . $fmtDt($info['mtime'])); ?></span>
                                    <?php else: ?>
                                        <span class="msg msg-error"><?php echo h($f['xml']); ?> není</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php $rel = $f['dir'].'/'.$f['csv_full']; $info = $fileInfo($rel); ?>
                                    <?php if ($info): ?>
                                        <a href="<?php echo h($info['rel'] ?? $rel); ?>" target="_blank"><?php echo h(basename($info['rel'] ?? $f['csv_full'])); ?></a><br>
                                        <span class="muted"><?php echo h($fmtSize($info['size']) . ' • ' . $fmtDt($info['mtime'])); ?></span>
                                    <?php else: ?>
                                        <span class="msg msg-error"><?php echo h($f['csv_full']); ?> není</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php $rel = $f['dir'].'/'.$f['csv_extra']; $info = $fileInfo($rel); ?>
                                    <?php if ($info): ?>
                                        <a href="<?php echo h($info['rel'] ?? $rel); ?>" target="_blank"><?php echo h(basename($info['rel'] ?? $f['csv_extra'])); ?></a><br>
                                        <span class="muted"><?php echo h($fmtSize($info['size']) . ' • ' . $fmtDt($info['mtime'])); ?></span>
                                    <?php else: ?>
                                        <span class="msg msg-error"><?php echo h($f['csv_extra']); ?> není</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="margin-top:14px;">
                        <a class="btn-full" href="run_feeds.php" id="runFeedsLink">Spustit aktualizaci feedů (download_feeds.php)</a>

                        <div class="box" style="margin-top:14px; margin-bottom:0;">
                            <div style="font-weight:800; margin-bottom:6px;">Celkový soubor</div>
                            <div class="muted" style="margin-bottom:10px;">
                                Celkový spojený CSV soubor vytvořený po spuštění aktualizace feedů.
                            </div>

                            <?php if ($mergedExists): ?>
                                <a class="btn-full" href="<?php echo h($mergedRelFile); ?>" download>Stáhnout <?php echo h($mergedLabel); ?></a>
                                <div class="muted" style="margin-top:8px;">
                                    Velikost: <strong><?php echo h($fmtSize($mergedSize)); ?></strong>
                                    • Aktualizace: <strong><?php echo h($fmtDt($mergedMtime)); ?></strong>
                                    • Řádků: <strong><?php echo (int)$totalRows; ?></strong>
                                </div>
                            <?php else: ?>
                                <div class="msg msg-error" style="margin:0;">
                                    Soubor <?php echo h($mergedLabel); ?> zatím neexistuje: <?php echo h('/' . $mergedRelFile); ?>
                                </div>
                                <div class="muted" style="margin-top:8px;">
                                    Vytvoří se po spuštění <strong>download_feeds.php</strong>.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="box" style="margin-top:14px; margin-bottom:0;">
                            <div style="font-weight:800; margin-bottom:6px;">Nahrát sklad C-Store</div>
                            <div class="muted" style="margin-bottom:10px;">
                                Nahraješ CSV z disku. Soubor se může jmenovat pokaždé jinak.
                                Použije se sloupec <strong>F</strong> a sloupec <strong>G</strong>; z G projdou jen hodnoty <strong>1–25</strong> nebo <strong>1001–1025</strong>.
                            </div>

                            <form method="post" action="upload_sklad_cstore.php" enctype="multipart/form-data" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                                <input type="file" name="sklad_csv" accept=".csv,text/csv" required>
                                <button type="submit" class="btn-full" style="width:auto; padding:8px 14px;">Nahrát Sklad C-Store</button>
                            </form>
                        </div>

                        <div class="box" style="margin-top:14px; margin-bottom:0;">
                            <div style="font-weight:800; margin-bottom:6px;">Celkový soubor se sklady C-Store</div>
                            <div class="muted" style="margin-bottom:10px;">
                                Celkový spojený CSV soubor vytvořený z posledního <strong>SpojeneFeedy.csv</strong> + ručně nahraného skladu C-Store.
                            </div>

                            <?php if ($mergedSkladExists): ?>
                                <a class="btn-full" href="<?php echo h($mergedSkladRelFile); ?>" download>Stáhnout <?php echo h($mergedSkladLabel); ?></a>
                                <div class="muted" style="margin-top:8px;">
                                    Velikost: <strong><?php echo h($fmtSize($mergedSkladSize)); ?></strong>
                                    • Aktualizace: <strong><?php echo h($fmtDt($mergedSkladMtime)); ?></strong>
                                    • Řádků: <strong><?php echo (int)$mergedSkladRows; ?></strong>
                                </div>
                            <?php else: ?>
                                <div class="muted" style="margin:0;">
                                    Soubor se sklady zatím není vytvořený. Nejdřív spusť aktualizaci feedů a potom nahraj CSV přes tlačítko výše.
                                </div>
                            <?php endif; ?>
                        </div>


                        <div class="box manual-supplier-box" id="zpracovat-vsechny-dodavatele" style="margin-top:14px; margin-bottom:0; border:2px solid #0a7d67; border-radius:14px; padding:16px; background:#f8fffb;">
                            <div class="manual-supplier-title">Zpracovat všechny dodavatele</div>
                            <div class="muted" style="margin:6px 0 12px 0;">
                                Jedním kliknutím spustí stejné zpracování jako tlačítko <strong>Zpracovat</strong> u jednotlivých dodavatelů níže: <strong>VAVRYS</strong>, <strong>SILVINI</strong>, <strong>ALÉ</strong> a <strong>ISADORE</strong>. Vytvoří nové kompletní výstupy variant i hlavních produktů. Předchozí kompletní výstupy se uloží pro následné porovnání v části <strong>Všichni dodavatelé – pouze změny</strong>.
                            </div>
                            <form method="post" action="process_all_suppliers.php" style="margin:0 0 10px 0;">
                                <button type="submit" class="btn-full manual-supplier-action-button" style="width:auto; padding:9px 16px;" onclick="if(this.form){ this.disabled=true; this.innerText='Zpracovávám vše…'; this.form.submit(); } return false;">ZPRACOVAT VŠE</button>
                            </form>
                            <?php if (isset($_GET['all_suppliers_process'])): ?>
                                <?php if ((string)($_GET['all_suppliers_process'] ?? '') === 'ok'): ?>
                                    <div class="msg" style="margin-top:8px; display:inline-block; padding:7px 10px;">Hotovo – zpracováno dodavatelů: <strong><?php echo (int)($_GET['processed'] ?? 0); ?></strong></div>
                                <?php else: ?>
                                    <div class="msg msg-error" style="margin-top:8px;">Zpracovat vše se nepovedlo: <?php echo h((string)($_GET['msg'] ?? 'neznámá chyba')); ?></div>
                                <?php endif; ?>
                            <?php elseif (!empty($allSuppliersProcessStats)): ?>
                                <div class="muted" style="margin-top:8px;">
                                    Poslední Zpracovat vše: <strong><?php echo h((string)($allSuppliersProcessStats['created_at'] ?? '')); ?></strong>
                                    <?php if (!empty($allSuppliersProcessStats['processed_count'])): ?>
                                        • dodavatelů: <strong><?php echo (int)($allSuppliersProcessStats['processed_count'] ?? 0); ?></strong>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($manualSupplierGroups as $supplierKey => $supplierGroup): ?>
                            <div class="box manual-supplier-box" id="<?php echo h($supplierGroup['anchor'] ?? ($supplierKey . '-aktualizace')); ?>" style="margin-top:14px; margin-bottom:0; border:2px solid #d7eadf; border-radius:14px; padding:16px; background:#fff;">
                                <div class="manual-supplier-title"><?php echo h($supplierGroup['title'] ?? strtoupper($supplierKey)); ?></div>

                                <?php if (!$vavrysManualDirExists): ?>
                                    <div class="msg msg-error" style="margin:0;">
                                        Složka /RucniNahraniAktualizace/ neexistuje nebo do ní nejde zapisovat.
                                    </div>
                                <?php else: ?>
                                    <table class="table manual-supplier-table">
                                        <thead>
                                            <tr>
                                                <th>Soubor</th>
                                                <th>Velikost / datum</th>
                                                <th>Akce / stav</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach (($supplierGroup['files'] ?? []) as $vf): ?>
                                            <tr>
                                                <td>
                                                    <?php if (!empty($vf['exists'])): ?>
                                                        <a href="<?php echo h($vf['rel']); ?>" download><strong><?php echo h($vf['label'] ?? $vf['name']); ?></strong></a>
                                                    <?php else: ?>
                                                        <strong><?php echo h($vf['label'] ?? $vf['name']); ?></strong>
                                                        <div class="muted" style="margin-top:4px;">Soubor zatím není nahraný.</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($vf['exists'])): ?>
                                                        <?php echo h($fmtSize($vf['size'] ?? 0)); ?><br>
                                                        <span class="muted"><?php echo h($fmtDt($vf['mtime'] ?? 0)); ?></span>
                                                    <?php else: ?>
                                                        <span class="muted">nenahráno</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($vf['is_var']) && !empty($vf['exists'])): ?>
                                                        <form class="manual-supplier-action-form" method="post" action="upload_vavrys_aktualizace.php" style="display:inline-block; margin:0 8px 0 0;">
                                                            <input type="hidden" name="supplier" value="<?php echo h($vf['supplier'] ?? $supplierKey); ?>">
                                                            <input type="hidden" name="vavrys_var_file" value="<?php echo h($vf['name']); ?>">
                                                            <button type="submit" class="btn-full manual-supplier-action-button" style="width:auto; padding:8px 14px;" onclick="if(this.form){ this.disabled=true; this.innerText='Zpracovávám…'; this.form.submit(); } return false;">Zpracovat</button>
                                                        </form>
                                                    <?php elseif (empty($vf['is_var'])): ?>
                                                        <span class="muted">Zpracuje se společně</span>
                                                    <?php endif; ?>

                                                    <?php if (($vf['output_exists'] ?? false)): ?>
                                                        <span class="msg manual-supplier-status" style="display:inline-block; margin:0 8px 0 0; padding:7px 10px;">Hotovo</span>
                                                        <a class="btn-full manual-supplier-download" style="display:inline-block; width:auto; padding:7px 10px;" href="<?php echo h($vf['output_rel']); ?>" download>
                                                            Stáhnout <?php echo h($vf['output_name']); ?>
                                                        </a>
                                                        <div class="muted manual-supplier-file-meta" style="margin-top:6px;">
                                                            Velikost: <strong><?php echo h($fmtSize($vf['output_size'] ?? 0)); ?></strong>
                                                            • Aktualizace: <strong><?php echo h($fmtDt($vf['output_mtime'] ?? 0)); ?></strong>
                                                            • Řádků: <strong><?php echo (int)($vf['output_rows'] ?? 0); ?></strong>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <?php
                                        $supplierStats = $supplierGroup['change_stats'] ?? [];
                                        $hiddenProducts = (int)($supplierStats['hidden_products'] ?? 0);
                                        $openedProducts = (int)($supplierStats['opened_products'] ?? 0);
                                        $statsCreatedAt = (string)($supplierStats['created_at'] ?? '');
                                    ?>
                                    <div class="manual-supplier-stats">
                                        Schované produkty: <strong><?php echo (int)$hiddenProducts; ?></strong>
                                        &nbsp; | &nbsp;
                                        Otevřené produkty: <strong><?php echo (int)$openedProducts; ?></strong>
                                        <?php if ($statsCreatedAt !== ''): ?>
                                            <span class="muted" style="font-weight:400; margin-left:8px;">Poslední zpracování: <?php echo h($statsCreatedAt); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="box manual-supplier-box" id="vsechny-dodavatele-zmeny" style="margin-top:18px; margin-bottom:0; border:2px solid #0a7d67; border-radius:14px; padding:16px; background:#f8fffb;">
                            <div class="manual-supplier-title">Všichni dodavatelé – pouze změny</div>
                            <div class="muted" style="margin:6px 0 12px 0;">
                                Porovná aktuální kompletní výstupy dodavatelů s předchozí uloženou verzí a vygeneruje jen změny: <strong>VsechnyDodavateleVarianty_var.csv</strong> a <strong>VsechnyDodavateleHlavni.csv</strong>.
                                Do souborů se dají jen kompletní řádky, kde se hodnota <strong>Tento produkt nezobrazovat v eshopu</strong> opravdu změnila z <strong>0 na 1</strong> nebo z <strong>1 na 0</strong>. EAN zůstává jako čisté číslo. Soubor <strong>_var.csv</strong> se po dokončení vždy seřadí: napřed hodnota <strong>0</strong>, potom <strong>1</strong>.
                            </div>

                            <form method="post" action="generate_all_supplier_changes.php" style="margin:0 0 14px 0;">
                                <button type="submit" class="btn-full manual-supplier-action-button" style="width:auto; padding:9px 16px;" onclick="if(this.form){ this.disabled=true; this.innerText='Generuji…'; this.form.submit(); } return false;">Vygenerovat vše</button>
                            </form>

                            <table class="table manual-supplier-table">
                                <thead>
                                    <tr>
                                        <th>Soubor</th>
                                        <th>Velikost / datum</th>
                                        <th>Stažení / řádky</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Všichni dodavatelé Varianty</strong><br><span class="muted">VsechnyDodavateleVarianty_var.csv</span></td>
                                        <td>
                                            <?php if (!empty($allSupplierVarLatest['abs']) && is_file($allSupplierVarLatest['abs'])): ?>
                                                <?php echo h($fmtSize((int)@filesize($allSupplierVarLatest['abs']))); ?><br>
                                                <span class="muted"><?php echo h($fmtDt((int)($allSupplierVarLatest['mtime'] ?? 0))); ?></span>
                                            <?php else: ?>
                                                <span class="muted">zatím nevygenerováno</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($allSupplierVarLatest['name'])): ?>
                                                <a class="btn-full manual-supplier-download" style="display:inline-block; width:auto; padding:7px 10px;" href="<?php echo h($vavrysUrl($vavrysManualRelDir . '/' . $allSupplierVarLatest['name'])); ?>" download>
                                                    Stáhnout <?php echo h($allSupplierVarLatest['name']); ?>
                                                </a>
                                                <div class="muted" style="margin-top:6px;">Řádků změn: <strong><?php echo (int)$countCsvDataRows((string)$allSupplierVarLatest['abs']); ?></strong></div>
                                                <?php if (!empty($allSupplierChangesStats)): ?>
                                                    <div class="muted" style="margin-top:4px;">Varianty 0 / otevřené: <strong><?php echo (int)($allSupplierChangesStats['variant_opened_rows'] ?? 0); ?></strong> &nbsp;|&nbsp; Varianty 1 / schované: <strong><?php echo (int)($allSupplierChangesStats['variant_hidden_rows'] ?? 0); ?></strong></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="muted">není k dispozici</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Všichni dodavatelé Hlavní</strong><br><span class="muted">VsechnyDodavateleHlavni.csv</span></td>
                                        <td>
                                            <?php if (!empty($allSupplierMainLatest['abs']) && is_file($allSupplierMainLatest['abs'])): ?>
                                                <?php echo h($fmtSize((int)@filesize($allSupplierMainLatest['abs']))); ?><br>
                                                <span class="muted"><?php echo h($fmtDt((int)($allSupplierMainLatest['mtime'] ?? 0))); ?></span>
                                            <?php else: ?>
                                                <span class="muted">zatím nevygenerováno</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($allSupplierMainLatest['name'])): ?>
                                                <a class="btn-full manual-supplier-download" style="display:inline-block; width:auto; padding:7px 10px;" href="<?php echo h($vavrysUrl($vavrysManualRelDir . '/' . $allSupplierMainLatest['name'])); ?>" download>
                                                    Stáhnout <?php echo h($allSupplierMainLatest['name']); ?>
                                                </a>
                                                <div class="muted" style="margin-top:6px;">Řádků změn: <strong><?php echo (int)$countCsvDataRows((string)$allSupplierMainLatest['abs']); ?></strong></div>
                                            <?php else: ?>
                                                <span class="muted">není k dispozici</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <?php
                                $allHiddenProducts = (int)($allSupplierChangesStats['hidden_products'] ?? 0);
                                $allOpenedProducts = (int)($allSupplierChangesStats['opened_products'] ?? 0);
                                $allCreatedAt = (string)($allSupplierChangesStats['created_at'] ?? '');
                            ?>
                            <div class="manual-supplier-stats" style="margin-top:10px;">
                                Hlavní produkty – schované: <strong><?php echo (int)$allHiddenProducts; ?></strong>
                                &nbsp; | &nbsp;
                                otevřené: <strong><?php echo (int)$allOpenedProducts; ?></strong>
                                <?php if ($allCreatedAt !== ''): ?>
                                    <span class="muted" style="font-weight:400; margin-left:8px;">Poslední vygenerování: <?php echo h($allCreatedAt); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>


                    <div id="feedsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999;">
                        <div style="max-width:900px; margin:6vh auto; background:#fff; border-radius:14px; box-shadow:0 8px 30px rgba(0,0,0,.18); padding:18px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                                <div style="font-weight:700; color:#0a7d67;">Aktualizace feedů – průběh</div>
                                <button type="button" id="feedsClose" style="border:1px solid #cfe3db; background:#fff; border-radius:10px; padding:6px 10px; cursor:pointer;">Zavřít</button>
                            </div>

                            <div style="margin-top:12px;">
                                <div style="height:10px; background:#e8f2ee; border-radius:999px; overflow:hidden;">
                                    <div id="feedsBar" style="height:10px; width:0%; background:#0a7d67;"></div>
                                </div>
                                <div id="feedsPct" style="margin-top:6px; font-size:13px; color:#6a7b78;">0 %</div>
                            </div>

                            <pre id="feedsLog" style="margin-top:12px; background:#f6f8f7; border:1px solid #e2ece8; border-radius:12px; padding:12px; height:320px; overflow:auto; white-space:pre-wrap; font-size:12px;"></pre>
                        </div>
                    </div>

                    <script>
                    (function(){
                        const link = document.getElementById('runFeedsLink');
                        const modal = document.getElementById('feedsModal');
                        const closeBtn = document.getElementById('feedsClose');
                        const bar = document.getElementById('feedsBar');
                        const pct = document.getElementById('feedsPct');
                        const log = document.getElementById('feedsLog');


                        const checkAllBtn = document.getElementById('feedsCheckAll');

                        function checkAll(){
                            document.querySelectorAll('input.feedChk').forEach(ch => ch.checked = true);
                        }
                        checkAllBtn && checkAllBtn.addEventListener('click', function(ev){
                            ev.preventDefault();
                            checkAll();
                        });

                        // defaultně vždy zaškrtnout vše po načtení stránky
                        checkAll();

                        function open(){ modal.style.display='block'; }
                        function close(){ modal.style.display='none'; }
                        closeBtn && closeBtn.addEventListener('click', close);
                        modal && modal.addEventListener('click', (e)=>{ if(e.target===modal) close(); });

                        function setProgress(step,total,msg){
                            const p = total>0 ? Math.round((step/total)*100) : 0;
                            bar.style.width = p + '%';
                            pct.textContent = p + ' %' + (msg ? ' – ' + msg : '');
                        }

                        async function run(){
                            open();
                            log.textContent = '';
                            setProgress(0, 1, 'spouštím…');

                            try{
                                const checked = Array.from(document.querySelectorAll('input.feedChk:checked'));
                                if(checked.length===0){
                                    open();
                                    log.textContent = 'Nic není zaškrtnuté – nic neaktualizuji.\n';
                                    setProgress(0, 1, 'nic nevybráno');
                                    return;
                                }
                                const fd = new FormData();
                                checked.forEach(ch => fd.append('suppliers[]', ch.value));
                                const resp = await fetch('run_feeds.php?ajax=1', {method:'POST', body: fd, cache:'no-store'});
                                if(!resp.ok){
                                    log.textContent += 'CHYBA HTTP: ' + resp.status + '\n';
                                }

                                const reader = resp.body.getReader();
                                const decoder = new TextDecoder('utf-8');
                                let buf = '';
                                let total = 5;

                                while(true){
                                    const {value, done} = await reader.read();
                                    if(done) break;
                                    buf += decoder.decode(value, {stream:true});

                                    let idx;
                                    while((idx = buf.indexOf('\n')) >= 0){
                                        const line = buf.slice(0, idx).replace(/\r$/, '');
                                        buf = buf.slice(idx+1);

                                        if(line.startsWith('__PROGRESS__|')){
                                            const parts = line.split('|');
                                            const step = parseInt(parts[1]||'0',10);
                                            total = parseInt(parts[2]||'5',10);
                                            const msg = parts.slice(3).join('|');
                                            setProgress(step,total,msg);
                                            continue;
                                        }

                                        if(line.trim() !== ''){
                                            log.textContent += line + '\n';
                                            log.scrollTop = log.scrollHeight;
                                        }
                                    }
                                }

                                setProgress(total, total, 'hotovo');
                                log.textContent += '\nHotovo. Obnov stránku (F5), aby se načetly nové počty a DEVOLD v ALL_XML.csv.\n';
                            }catch(e){
                                log.textContent += 'CHYBA: ' + (e && e.message ? e.message : e) + '\n';
                            }
                        }

                        if(link){
                            link.addEventListener('click', function(ev){
                                ev.preventDefault();
                                run();
                            });
                        }
                    })();
                    </script>
                    </div>
                </div>
            </div>


        <?php endif; ?>

        <div class="logout-wrap">
            <form method="get" action="index.php">
                <input type="hidden" name="logout" value="1">
                <button type="submit" class="btn-logout">Odhlásit se</button>
            </form>
        </div>

        <div class="cfloat-new-wrap">
            <a class="btn-cfloat-round" href="cfloat-new/index.php" title="Nová vyvíjená verze cFloat">Nový<br>Cfloat</a>
        </div>

    <?php endif; ?>
</div>
</body>
</html>