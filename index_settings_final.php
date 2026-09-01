<?php
// Přístup může být i přes .htaccess (Basic Auth na úrovni serveru)

require_once __DIR__ . '/_auth_guard.php';

// PDO (DB d388160_cfloat – orders / order_items)
// PDO (DB d388160_cfloat – orders / order_items)
$cfgCandidates = [__DIR__ . '/config.php', __DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$cfgLoaded = false;
foreach ($cfgCandidates as $p) { if (is_file($p)) { require $p; $cfgLoaded = true; break; } }
if (!$cfgLoaded || !isset($pdo)) { die('Chybí config.php nebo $pdo.'); }

// složka s CSV variantami (AllVarianty.csv a další)
const VARIANTS_DIR = __DIR__ . '/CStore/Varianty';

// AJAX – sloučení všech CSV ve složce VARIANTS_DIR do jednoho AllVarianty.csv
if ($loggedIn && isset($_GET['ajax']) && $_GET['ajax'] === 'merge_variants') {
    header('Content-Type: application/json; charset=utf-8');

    $dir = VARIANTS_DIR;
    if (!is_dir($dir)) {
        echo json_encode(['ok' => false, 'message' => 'Složka s CSV neexistuje: ' . $dir], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $files = glob($dir . '/*.csv');
    if ($files === false) {
        echo json_encode(['ok' => false, 'message' => 'Nelze načíst seznam CSV souborů.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $allPath = $dir . '/AllVarianty.csv';
    $out = fopen($allPath, 'w');
    if ($out === false) {
        echo json_encode(['ok' => false, 'message' => 'Nelze otevřít AllVarianty.csv pro zápis.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $headerWritten = false;
    $totalRows = 0;

    foreach ($files as $file) {
        // přeskočit cílový soubor, pokud už existuje
        if (basename($file) === 'AllVarianty.csv') {
            continue;
        }
        $h = fopen($file, 'r');
        if ($h === false) {
            continue;
        }

        $rowIndex = 0;
        while (($row = fgetcsv($h, 0, ';')) !== false) {
            // první řádek = hlavička
            if ($rowIndex === 0) {
                if (!$headerWritten) {
                    fputcsv($out, $row, ';');
                    $headerWritten = true;
                }
            } else {
                fputcsv($out, $row, ';');
                $totalRows++;
            }
            $rowIndex++;
        }
        fclose($h);
    }

    fclose($out);

    echo json_encode([
        'ok'      => true,
        'message' => 'Varianty byly spojeny.',
        'rows'    => $totalRows,
        'file'    => $allPath,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


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

// view: home / print / stats / customers / orders / service / returns
$view = isset($_GET['view']) ? $_GET['view'] : 'home';

// TISK
$ean          = '';
$status       = '';
$message      = '';
$customerName = '';
$isPaid       = null;
$paidTextRaw  = '';
$codAmount    = '';
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
$ordersEmailStats  = [];
$ordersFilterEmail = '';

// SERVIS
$serviceView        = false;
$serviceDateFromStr = '';
$serviceDateToStr   = '';
$serviceError       = '';
$serviceRows        = [];
$serviceItemsById   = [];
$serviceTotalSum    = 0.0;

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
$returnsError       = '';
$returnsRows        = [];
$returnsTotalQty    = 0;
$returnsTotalPrice  = 0.0;
$returnsStats       = [];
$returnsTopStats    = [];

// ---------- TISK ŠTÍTKŮ ----------
if ($loggedIn && $view === 'print') {
    $ean = isset($_GET['ean']) ? trim($_GET['ean']) : '';

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

    $whereParts = [];
    $params     = [];
    $joinItems  = '';

    if ($ordersFilterEmail !== '') {
        $whereParts[]     = 'LOWER(TRIM(o.customer_email)) = LOWER(TRIM(:email))';
        $params[':email'] = $ordersFilterEmail;
    }

    if ($ordersSearch !== '') {
        $joinItems = 'LEFT JOIN order_items oi ON oi.id_order = o.id_order';
        $whereParts[] =
            '(o.number LIKE :qs
              OR o.invoice_number LIKE :qs
              OR o.customer_name LIKE :qs
              OR o.customer_email LIKE :qs
              OR o.customer_phone LIKE :qs
              OR oi.product_name LIKE :qs
              OR oi.product_number LIKE :qs)';
        $params[':qs'] = '%' . $ordersSearch . '%';
    }

    $whereSql = '';
    if (!empty($whereParts)) {
        $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
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
                                ean,
                                price_s_dph
                             FROM Kompletni_DatabazeVariantyEANProdejeCeny
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

                    $priceSDph = isset($row['price_s_dph']) ? (float)$row['price_s_dph'] : 0.0;
                    $ordersPurchaseById[$oid] += $priceSDph * $qty;

                    $ordersItemsById[$oid][] = [
                        'code'        => (string)($row['product_number'] ?? ''),
                        'ean'         => (string)($row['ean'] ?? ''),
                        'name'        => (string)($row['product_name'] ?? ''),
                        'variant'     => (string)($row['variant_description'] ?? ''),
                        'qty'         => $qty,
                        'price_s_dph' => $priceSDph,
                        'total'       => isset($row['price_total_with_vat']) ? (float)$row['price_total_with_vat'] : 0.0,
                    ];
                }
            } catch (Throwable $e) {
                $ordersError = 'Chyba při načítání položek objednávek: ' . $e->getMessage();
            }
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
                                price_total_with_vat
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

                    $serviceItemsById[$oid][] = [
                        'code'    => (string)($row['product_number'] ?? ''),
                        'name'    => (string)($row['product_name'] ?? ''),
                        'variant' => (string)($row['variant_description'] ?? ''),
                        'qty'     => $qty,
                        'total'   => isset($row['price_total_with_vat']) ? (float)$row['price_total_with_vat'] : 0.0,
                    ];
                }
            } catch (Throwable $e) {
                $serviceError = 'Chyba při načítání položek servisních objednávek: ' . $e->getMessage();
            }
        }
    }
}

// ---------- STATISTIKA ----------
if ($loggedIn && $view === 'stats') {
    $showStats   = true;
    $dateFromStr = isset($_GET['from']) ? trim($_GET['from']) : '';
    $dateToStr   = isset($_GET['to'])   ? trim($_GET['to'])   : '';
    $brand       = isset($_GET['brand']) ? trim($_GET['brand']) : 'all';

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

            if (!isset($cols[STATS_CODE_COL_INDEX])) continue;
            $rawCode = trim((string)$cols[STATS_CODE_COL_INDEX]);
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

            $aaVal = isset($cols[STATS_EXTRA_COL_INDEX]) ? trim((string)$cols[STATS_EXTRA_COL_INDEX]) : '';
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
    }
}

// ---------- ZÁKAZNÍCI ----------
if ($loggedIn && $view === 'customers') {
    $customersShow        = true;
    $customersDateFromStr = isset($_GET['from']) ? trim($_GET['from']) : '';
    $customersDateToStr   = isset($_GET['to'])   ? trim($_GET['to'])   : '';
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
}

// ---------- VRÁCENÉ ZBOŽÍ ----------
if ($loggedIn && $view === 'returns') {
    // Modul "Vrácené zboží" byl odstraněn (už se nepoužívá).
    header('Location: ' . basename($_SERVER['PHP_SELF']));
    exit;
    $returnsShow        = true;
    $returnsDateFromStr = isset($_GET['from']) ? trim($_GET['from']) : '';
    $returnsDateToStr   = isset($_GET['to'])   ? trim($_GET['to'])   : '';

    $fromYmd = $returnsDateFromStr !== '' ? $returnsDateFromStr : null;
    $toYmd   = $returnsDateToStr   !== '' ? $returnsDateToStr   : null;

    

    // DB zdroj: returns_requests + returns_items (namísto Google Sheets CSV)
    $dbRows = [];
    try {
        $sql = "
            SELECT
                rr.id AS request_id,
                rr.created_at,
                rr.order_number,
                o.invoice_number AS invoice_number,
                COALESCE(NULLIF(rr.customer_name,''), o.customer_name) AS customer_name,
                o.payment_name AS payment_method,
                o.delivery_name AS delivery_method,
                rr.bank_account,
                rr.note,
                rr.pdf_path,
                ri.id AS item_id,
                ri.product_number,
                ri.product_name,
                ri.variant_description,
                ri.ean,
                ri.return_qty,
                ri.price_per_unit_with_vat,
                ri.line_total_with_vat
            FROM returns_requests rr
            LEFT JOIN orders o
              ON (o.id_order = rr.id_order OR (rr.id_order IS NULL AND o.number = rr.order_number))
            JOIN returns_items ri ON ri.request_id = rr.id
            WHERE 1=1
        ";

        $params = [];
        if ($fromYmd) {
            $sql .= " AND DATE(rr.created_at) >= :from";
            $params[':from'] = $fromYmd;
        }
        if ($toYmd) {
            $sql .= " AND DATE(rr.created_at) <= :to";
            $params[':to'] = $toYmd;
        }

        $sql .= " ORDER BY rr.created_at DESC, rr.id DESC, ri.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    } catch (Throwable $e) {
        $returnsError = 'Nepodařilo se načíst data pro vrácené zboží z databáze: ' . $e->getMessage();
        $dbRows = [];
    }

    if ($returnsError === '') {
        $returnsByKey = [];

        foreach ($dbRows as $r) {
            $created = isset($r['created_at']) ? (string)$r['created_at'] : '';
            $rowDateObj = null;
            if ($created !== '') {
                try {
                    $rowDateObj = new DateTime($created, new DateTimeZone('Europe/Prague'));
                    $rowDateObj->setTime(0, 0, 0);
                } catch (Throwable $e) {
                    $rowDateObj = null;
                }
            }
            $rowDateYmd = $rowDateObj ? $rowDateObj->format('Y-m-d') : null;

            // Filtr je už v SQL, ale necháme bezpečnostní kontrolu
            if (($fromYmd || $toYmd) && !$rowDateYmd) continue;
            if ($fromYmd && $rowDateYmd && $rowDateYmd < $fromYmd) continue;
            if ($toYmd && $rowDateYmd && $rowDateYmd > $toYmd)     continue;

            $productName = isset($r['product_name']) ? trim((string)$r['product_name']) : '';
            $variantDesc = isset($r['variant_description']) ? trim((string)$r['variant_description']) : '';
            $colJ = trim($productName . ($variantDesc !== '' ? (' ' . $variantDesc) : ''));
            if ($colJ === '') continue;

            $orderVal = isset($r['order_number']) ? trim((string)$r['order_number']) : '';

            // K – klíč pro deduplikaci (kód / EAN / fallback na název)
            $kVal = isset($r['product_number']) ? trim((string)$r['product_number']) : '';
            if ($kVal === '') $kVal = isset($r['ean']) ? trim((string)$r['ean']) : '';
            if ($kVal === '') $kVal = $colJ;

            $key = ($orderVal !== '' || $kVal !== '') ? ($orderVal . '|' . $kVal) : ('row-' . md5($created . $colJ));

            // D – doplňující info (typicky faktura)
            $colD = isset($r['invoice_number']) ? trim((string)$r['invoice_number']) : '';

            // E – další sloupec v tabulce (typicky zákazník)
            $colE = isset($r['customer_name']) ? trim((string)$r['customer_name']) : '';

            // PDF – uložený dokument
            $colPdf = isset($r['pdf_path']) ? trim((string)$r['pdf_path']) : '';

            // I – popis (detail)
            $note = isset($r['note']) ? trim((string)$r['note']) : '';
            $bank = isset($r['bank_account']) ? trim((string)$r['bank_account']) : '';
            $pay  = isset($r['payment_method']) ? trim((string)$r['payment_method']) : '';
            $del  = isset($r['delivery_method']) ? trim((string)$r['delivery_method']) : '';

            $detailLines = [];
            if ($note !== '') $detailLines[] = 'Důvod vrácení: ' . $note;
            if ($pay !== '')  $detailLines[] = 'Platba: ' . $pay;
            if ($del !== '')  $detailLines[] = 'Doprava: ' . $del;
            if ($bank !== '') $detailLines[] = 'Bankovní účet: ' . $bank;
            $colI = implode("
", $detailLines);

            // L – počet vrácených kusů
            $qty = isset($r['return_qty']) ? (float)$r['return_qty'] : 0.0;

            // M – cena (součet řádku)
            $lineTotal = $r['line_total_with_vat'] ?? null;
            if ($lineTotal === null || $lineTotal === '') {
                $ppu = isset($r['price_per_unit_with_vat']) ? (float)$r['price_per_unit_with_vat'] : 0.0;
                $lineTotal = $ppu * $qty;
            }
            $price = (float)$lineTotal;
            $colM = number_format($price, 2, ',', ' ');

            if (!isset($returnsByKey[$key])) {
                $returnsByKey[$key] = [
                    'date'    => $rowDateYmd ?? $created,
                    'd'       => $colD,
                    'j'       => $colJ,
                    'i'       => $colI,
                    'e'       => $colE,
                    'pdf'     => $colPdf,
                    'k'       => $kVal,
                    'm'       => $colM,
                    'qty'     => $qty,
                    'date_ts' => $rowDateObj ? $rowDateObj->getTimestamp() : 0,
                ];

                $returnsTotalQty   += $qty;
                $returnsTotalPrice += $price;

            } else {
                $ref =& $returnsByKey[$key];

                if ($colI !== '') {
                    if ($ref['i'] === '') {
                        $ref['i'] = $colI;
                    } elseif (mb_strpos($ref['i'], $colI, 0, 'UTF-8') === false) {
                        $ref['i'] .= "
" . $colI;
                    }
                }

                if ($ref['d'] === '' && $colD !== '') $ref['d'] = $colD;
                if ($ref['j'] === '' && $colJ !== '') $ref['j'] = $colJ;
                if ($ref['e'] === '' && $colE !== '') $ref['e'] = $colE;
                if ($ref['pdf'] === '' && $colPdf !== '') $ref['pdf'] = $colPdf;
                if ($ref['k'] === '' && $kVal !== '') $ref['k'] = $kVal;
                if ($ref['m'] === '' && $colM !== '') $ref['m'] = $colM;

                if ($rowDateObj && $rowDateObj->getTimestamp() > $ref['date_ts']) {
                    $ref['date_ts'] = $rowDateObj->getTimestamp();
                    $ref['date']    = $rowDateYmd ?? $created;
                }
                unset($ref);
            }
        }

        $returnsRows = array_values($returnsByKey);
        if (!empty($returnsRows)) {
            usort($returnsRows, function ($a, $b) {
                if ($a['date_ts'] === $b['date_ts']) return 0;
                return ($a['date_ts'] < $b['date_ts']) ? 1 : -1;
            });
        }

        if (!empty($returnsByKey)) {
            $returnsStats = [];
            foreach ($returnsByKey as $rec) {
                $nameJ = $rec['j'];
                $q     = $rec['qty'];
                if ($nameJ === '' || $q <= 0) continue;

                if (!isset($returnsStats[$nameJ])) {
                    $returnsStats[$nameJ] = 0.0;
                }
                $returnsStats[$nameJ] += $q;
            }

            if (!empty($returnsStats)) {
                $returnsTopStats = [];
                foreach ($returnsStats as $nameJ => $sumQty) {
                    $returnsTopStats[] = [
                        'name' => $nameJ,
                        'qty'  => $sumQty,
                    ];
                }
                usort($returnsTopStats, function($a, $b) {
                    if ($a['qty'] == $b['qty']) return 0;
                    return ($a['qty'] < $b['qty']) ? 1 : -1;
                });
                $returnsTopStats = array_slice($returnsTopStats, 0, 10);
            }
        }
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
        #stats-chart-wrap { display:none; margin-top:16px; }
        .btn-logout {
            background:#000 !important; color:#fff !important; box-shadow:none !important; margin-top:24px;
        }
        .btn-logout:hover {
            filter:none !important; background:#222 !important;
            box-shadow:0 4px 10px rgba(0,0,0,0.3) !important;
        }
        .logout-wrap { margin-top:24px; text-align:center; }

        /* OBJEDNÁVKY / SERVIS – více roztažené a přizpůsobené sloupcům */
        .orders-controls {
            display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:8px;
        }
        .orders-controls-left { flex:1 1 200px; }
        .orders-controls-right { display:flex; gap:8px; align-items:center; }
        .orders-search-input { width:100%; }

        .orders-table {
            table-layout:auto;
        }
        .orders-table th,.orders-table td {
            font-size:12px;
            white-space:nowrap;
        }

        /* šířky sloupců – desktop / základ */
        .orders-col-date    { min-width: 150px; }
        .orders-col-profit  { min-width: 110px; }
        .orders-col-name    { min-width: 220px; }
        .orders-col-email   { min-width: 210px; }
        .orders-col-phone   { min-width: 130px; }
        .orders-col-order   { min-width: 130px; }
        .orders-col-invoice { min-width: 130px; }
        .orders-col-total   { min-width: 110px; }
        .orders-col-payment { min-width: 130px; }
        .orders-col-delivery{ min-width: 140px; }

        .orders-paid-indicator {
            display:inline-block; width:12px; height:12px;
            border-radius:2px; margin-right:4px; background:#ccc; vertical-align:middle;
        }
        .orders-paid-indicator.paid { background:#2e7d32; }
        .orders-paid-indicator.unpaid { background:#c62828; }
        .order-main-row { cursor:pointer; }
        .order-main-row.selected { background:#e8f5e9; }
        .order-main-row.selected td { font-weight:600; }
        .order-detail-row { display:none; background:#f1f8e9; }
        .order-detail-cell { padding:8px 8px; font-size:12px; color:#33691e; }
        .order-items-table { width:100%; border-collapse:collapse; }
        .order-items-table th,.order-items-table td {
            border:1px solid #c8e6c9; padding:4px 4px; font-size:11px; white-space:nowrap;
        }
        .order-delivery-note { font-size:11px; margin-top:4px; color:#33691e; }
        .orders-badge-email { cursor:pointer; font-size:11px; opacity:0.8; }
        .delivery-toggle { cursor:pointer; text-decoration:underline; }

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
    </style>
</head>
<body>
<div class="wrap">
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

                <a class="tile" href="index.php?view=returns">
                    <div class="tile-title">Vrácené zboží</div>
                    <div class="tile-text">Vrácené zboží / vyplněné formuláře</div>
                </a>
                <a class="tile" href="index.php?view=settings">
                    <div class="tile-title">Nastavení</div>
                    <div class="tile-text">Nástroje pro správu – sloučení CSV variant do AllVarianty.</div>
                </a>

            </div>

        <?php elseif ($view === 'print'): ?>

            <div class="card card-print">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Tisk štítků</h1>

                <form method="get" action="index.php">
                    <input type="hidden" name="view" value="print">
                    <label for="ean">EAN / číslo objednávky</label>
                    <input type="text" id="ean" name="ean" class="ean-input" value="<?php echo h($ean); ?>" autofocus>
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

            <script>
            (function() {
                var statusVal = <?php echo json_encode($status); ?>;
                var eanVal    = <?php echo json_encode($ean); ?>;
                var input     = document.getElementById('ean');

                if (statusVal === 'ok' && eanVal) {
                    try {
                        var url = 'label.php?ean=' + encodeURIComponent(eanVal);
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

            <div class="card">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Objednávky</h1>

                <form method="get" action="index.php">
                    <input type="hidden" name="view" value="orders">

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
                        <div class="orders-controls-right">
                            <button type="submit">HLEDAT</button>
                            <a href="index.php?view=orders" style="text-decoration:none;">
                                <button type="button">Zpět</button>
                            </a>
                        </div>
                    </div>
                </form>

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

                    <div class="table-wrap">
                            <table class="stats-table orders-table">
                                <thead>
                                <tr>
                                    <th class="orders-col-date">Datum</th>
                                    <th class="orders-col-profit">Zisk</th>
                                    <th class="orders-col-name">Jméno</th>
                                    <th class="orders-col-email">E-mail</th>
                                    <th class="orders-col-phone">Telefon</th>
                                    <th class="orders-col-order">Číslo objednávky</th>
                                    <th class="orders-col-invoice">Číslo faktury</th>
                                    <th class="orders-col-total">Celkem</th>
                                    <th class="orders-col-purchase">Nákupní cena</th>
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
                                    $profitTotal   = $totalPrice - $purchaseTotal;

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
                                    <tr class="order-main-row" data-order-id="<?php echo $idOrder; ?>" onclick="toggleOrderDetail(<?php echo $idOrder; ?>);">
                                        <td class="orders-col-date"><?php echo h($createdDisplay); ?></td>
                                        <td class="orders-col-profit">
                                            <?php if ($purchaseTotal > 0): ?>
                                                <?php echo number_format($profitTotal, 2, ',', ' '); ?> Kč
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
                                        <td class="orders-col-invoice"><?php echo $invoiceNumber !== '' ? h($invoiceNumber) : '—'; ?></td>
                                        <td class="orders-col-total"><?php echo number_format($totalPrice, 2, ',', ' '); ?> Kč</td>
                                        <td class="orders-col-purchase">
                                            <?php if ($purchaseTotal > 0): ?>
                                                <?php echo number_format($purchaseTotal, 2, ',', ' '); ?> Kč
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
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
                                        <td colspan="10" class="order-detail-cell">
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
                                                            <tr>
                                                                <td><?php echo h($it['code']); ?></td>
                                                                <td>
                                                                    <?php
                                                                    if ($linePurchase > 0) {
                                                                        echo number_format($lineProfit, 2, ',', ' ') . ' Kč';
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
                                                                        echo number_format((float)$it['price_s_dph'], 2, ',', ' ') . ' Kč';
                                                                    } else {
                                                                        echo '—';
                                                                    }
                                                                    ?>
                                                                </td>
                                                                <td><?php echo number_format((float)$it['total'], 2, ',', ' '); ?> Kč</td>
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
                                foreach ($statsResult as $code => $data):
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
                    <?php else: ?>
                        <div class="msg">Pro zadaný filtr nejsou žádné položky v tabulce, graf ale ukazuje poměr značek.</div>
                    <?php endif; ?>

                    <script>
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
                    <button type="submit" class="btn-full">ZOBRAZIT SERVIS</button>
                </form>

                <?php if ($serviceError !== ''): ?>
                    <div class="msg msg-error"><?php echo h($serviceError); ?></div>
                <?php elseif (!empty($serviceRows)): ?>
                    <div class="stats-summary" style="margin-top:8px;">
                        Počet servisních zakázek: <?php echo count($serviceRows); ?>,
                        celkem: <?php echo number_format($serviceTotalSum, 2, ',', ' '); ?> Kč
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
                                $invoiceNumber = trim((string)($row['invoice_number'] ?? ''));
                                $totalPrice    = isset($row['total_price_with_vat']) ? (float)$row['total_price_with_vat'] : 0.0;

                                $dbPaid    = isset($row['zaplaceno']) && (string)$row['zaplaceno'] === 'A';
                                $gopayPaid = isset($row['gopay_zaplaceno']) && (string)$row['gopay_zaplaceno'] === 'A';
                                $isPaidOrder = $dbPaid || $gopayPaid;

                                $paymentName  = trim((string)($row['payment_name'] ?? ''));
                                $deliveryRaw  = trim((string)($row['delivery_name'] ?? ''));
                                $deliveryLower = mb_strtolower($deliveryRaw, 'UTF-8');
                                $isZasilkovna = ($deliveryRaw !== '' && mb_strpos($deliveryLower, 'zasilkovna') !== false);
                                ?>
                                <tr class="order-main-row" data-order-id="<?php echo $idOrder; ?>" onclick="toggleOrderDetail(<?php echo $idOrder; ?>);">
                                    <td class="orders-col-date"><?php echo h($createdDisplay); ?></td>
                                    <td class="orders-col-name"><?php echo h($name); ?></td>
                                    <td class="orders-col-email"><?php echo $email !== '' ? h($email) : '—'; ?></td>
                                    <td class="orders-col-phone"><?php echo $phone !== '' ? h($phone) : '—'; ?></td>
                                    <td class="orders-col-order"><?php echo h($orderNumberDisplay); ?></td>
                                    <td class="orders-col-invoice"><?php echo $invoiceNumber !== '' ? h($invoiceNumber) : '—'; ?></td>
                                    <td class="orders-col-total"><?php echo number_format($totalPrice, 2, ',', ' '); ?> Kč</td>
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
                                                        <th>Produkt</th>
                                                        <th>Varianta</th>
                                                        <th>Ks</th>
                                                        <th>Řádek (s DPH)</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($items as $it): ?>
                                                        <tr>
                                                            <td><?php echo h($it['code']); ?></td>
                                                            <td><?php echo h($it['name']); ?></td>
                                                            <td><?php echo h($it['variant']); ?></td>
                                                            <td><?php echo (float)$it['qty']; ?></td>
                                                            <td><?php echo number_format((float)$it['total'], 2, ',', ' '); ?> Kč</td>
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

                <?php elseif ($serviceDateFromStr !== '' || $serviceDateToStr !== ''): ?>
                    <div class="msg">Pro zadaný filtr nejsou žádné servisní zakázky.</div>
                <?php else: ?>
                    <div class="msg">Zadej datum nebo zobraz servisní zakázky bez filtru.</div>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'returns'): ?>

            <div class="card">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Vrácené zboží</h1>

                <form method="get" action="index.php">
                    <input type="hidden" name="view" value="returns">

                    <div class="stats-grid">
                        <div>
                            <div class="stats-label">Datum od (sloupec B)</div>
                            <input type="date" name="from" value="<?php echo h($returnsDateFromStr); ?>">
                        </div>
                        <div>
                            <div class="stats-label">Datum do (sloupec B)</div>
                            <input type="date" name="to" value="<?php echo h($returnsDateToStr); ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-full">ZOBRAZIT VRÁCENÉ ZBOŽÍ</button>
                </form>

                <?php if (!empty($returnsTopStats)): ?>
                    <button type="button" id="returns-stats-toggle" class="btn-full" style="margin-top:8px;">
                        STATISTIKA VRÁCENÉHO ZBOŽÍ (TOP 10)
                    </button>

                    <div id="returns-stats-wrap" style="display:none;margin-top:10px;">
                        <div class="table-wrap">
                            <table class="stats-table">
                                <thead>
                                <tr>
                                    <th>Produkt (J)</th>
                                    <th>Vráceno ks (součet L)</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($returnsTopStats as $st): ?>
                                    <tr>
                                        <td><?php echo h($st['name']); ?></td>
                                        <td><?php echo (float)$st['qty']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($returnsError !== ''): ?>
                    <div class="msg msg-error"><?php echo h($returnsError); ?></div>

                <?php elseif (!empty($returnsRows)): ?>
                    <div class="returns-summary">
                        Celkem vráceno: <?php echo (float)$returnsTotalQty; ?> ks,
                        celková cena: <?php echo number_format($returnsTotalPrice, 2, ',', ' '); ?> Kč
                    </div>

                    <div style="margin-top:10px;">
                        <input type="text" id="returns-search" placeholder="Hledat jméno / příjmení…" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;">
                    </div>

                    <?php
                    $returnsPdfRelDir = 'vraceni-cstore/pdf';
                    if (is_dir(__DIR__ . '/vraceni-cstore/PDF')) {
                        $returnsPdfRelDir = 'vraceni-cstore/PDF';
                    } elseif (is_dir(__DIR__ . '/vraceni-cstore/pdf')) {
                        $returnsPdfRelDir = 'vraceni-cstore/pdf';
                    }
                    ?>

                    <div class="table-wrap">

                        <table class="stats-table">
                            <thead>
                            <tr>
                                <th>Datum (B)</th>
                                <th>D</th>
                                <th>J</th>
                                <th>Popis (I)</th>
                                <th>E</th>
                                <th>M (cena)</th>
                                <th>PDF</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            $rIndex = 0;
                            foreach ($returnsRows as $row):
                                $rIndex++;
                                $detailId = 'ret-detail-' . $rIndex;
                                $textI   = $row['i'];
                                $shortI  = mb_strlen($textI, 'UTF-8') > 60
                                    ? mb_substr($textI, 0, 60, 'UTF-8') . '…'
                                    : $textI;
                                ?>
                                <tr class="returns-main-row" data-customer="<?php echo h($row['e']); ?>" data-detail-id="<?php echo h($detailId); ?>">
                                    <td><?php echo h($row['date']); ?></td>
                                    <td><?php echo h($row['d']); ?></td>
                                    <td><?php echo h($row['j']); ?></td>
                                    <td class="returns-popis-cell" data-detail-id="<?php echo h($detailId); ?>">
                                        <button
                                            type="button"
                                            class="stats-name-btn returns-toggle"
                                            data-detail-id="<?php echo h($detailId); ?>"
                                        >
                                            <?php echo h($shortI); ?>
                                        </button>
                                    </td>
                                    <td><?php echo h($row['e']); ?></td>
                                    <td><?php echo h($row['m']); ?></td>
                                    <td>
                                        <?php
                                        $pdfFile = isset($row['pdf']) ? trim((string)$row['pdf']) : '';
                                        $pdfFile = $pdfFile !== '' ? basename($pdfFile) : '';
                                        if ($pdfFile !== ''):
                                            $pdfHref = $returnsPdfRelDir . '/' . rawurlencode($pdfFile);
                                        ?>
                                            <a href="<?php echo h($pdfHref); ?>" target="_blank" rel="noopener">PDF</a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr id="<?php echo h($detailId); ?>" class="stats-detail-row">
                                    <td colspan="7" class="stats-detail-cell">
                                        <strong>Detail vrácení:</strong>
                                        <?php echo $textI !== '' ? ' ' . nl2br(h($textI)) : ' (bez popisu)'; ?>
                                        <?php if ($row['k'] !== ''): ?>
                                            <br><em>K (další popis): <?php echo h($row['k']); ?></em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($returnsDateFromStr !== '' || $returnsDateToStr !== ''): ?>
                    <div class="msg">Pro zadaný filtr nejsou žádné záznamy.</div>
                <?php else: ?>
                    <div class="msg">Pro zobrazení vráceného zboží zadej datum, nebo zobraz vše bez filtru.</div>
                <?php endif; ?>
            </div>

            <script>
            (function() {
                function toggleRow(id) {
                    var row = document.getElementById(id);
                    if (!row) return;
                    row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
                }

                var btns = document.querySelectorAll('.returns-toggle');
                btns.forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var id = btn.getAttribute('data-detail-id');
                        if (id) toggleRow(id);
                    });
                });

                var cells = document.querySelectorAll('.returns-popis-cell');
                cells.forEach(function(td) {
                    td.addEventListener('click', function(e) {
                        if (e.target.tagName.toLowerCase() === 'button') return;
                        var id = td.getAttribute('data-detail-id');
                        if (id) toggleRow(id);
                    });
                });
                // Live vyhledávání podle jména/příjmení (sloupec E)
                var searchEl = document.getElementById('returns-search');
                function normTxt(s){
                    s = (s || '').toString().toLowerCase();
                    try {
                        s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                    } catch(e){}
                    return s;
                }
                function applyReturnsSearch(){
                    if (!searchEl) return;
                    var q = normTxt(searchEl.value);
                    var mains = document.querySelectorAll('tr.returns-main-row');
                    mains.forEach(function(tr){
                        var name = normTxt(tr.getAttribute('data-customer') || '');
                        var show = (q === '') || (name.indexOf(q) !== -1);
                        tr.style.display = show ? '' : 'none';

                        var detId = tr.getAttribute('data-detail-id');
                        if (detId) {
                            var det = document.getElementById(detId);
                            if (det) {
                                if (!show) det.style.display = 'none';
                            }
                        }
                    });
                }
                if (searchEl) {
                    searchEl.addEventListener('input', applyReturnsSearch);
                }


                var statsBtn  = document.getElementById('returns-stats-toggle');
                var statsWrap = document.getElementById('returns-stats-wrap');
                if (statsBtn && statsWrap) {
                    statsBtn.addEventListener('click', function() {
                        if (statsWrap.style.display === 'none' || statsWrap.style.display === '') {
                            statsWrap.style.display = 'block';
                        } else {
                            statsWrap.style.display = 'none';
                        }
                    });
                }
            })();
            </script>

        <?php elseif ($view === 'settings'): ?>

            <div class="card">
                <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
                <h1>Nastavení</h1>

                <p>Sloučení všech CSV souborů ve složce <code>CStore/Varianty</code> do souboru <strong>AllVarianty.csv</strong>.</p>

                <div id="merge-box">
                    <button id="btn-merge" type="button" class="btn-full">Sloučení souborů</button>

                    <div id="merge-progress-wrap" style="margin-top:10px; display:none;">
                        <div class="stats-label">Průběh sloučení:</div>
                        <div class="progress-bar" style="width:100%; background:#eee; border-radius:999px; overflow:hidden; height:18px;">
                            <div id="merge-progress-bar" style="width:0%; height:100%; background:#4caf50;"></div>
                        </div>
                        <div id="merge-progress-text" style="margin-top:4px; font-size:13px;">0 %</div>
                    </div>

                    <div id="merge-result" style="margin-top:10px; font-size:13px;"></div>

                    <button id="btn-merge-ok" type="button" class="btn-full" style="margin-top:16px; display:none;">OK</button>
                </div>
            </div>

            <script>
            (function() {
                var btnMerge      = document.getElementById('btn-merge');
                var btnMergeOk    = document.getElementById('btn-merge-ok');
                var box           = document.getElementById('merge-box');
                var progressWrap  = document.getElementById('merge-progress-wrap');
                var progressBar   = document.getElementById('merge-progress-bar');
                var progressText  = document.getElementById('merge-progress-text');
                var resultEl      = document.getElementById('merge-result');
                if (!btnMerge) return;

                var timer = null;
                var current = 0;
                function startFakeProgress() {
                    current = 0;
                    progressBar.style.width = '0%';
                    progressText.textContent = '0 %';
                    progressWrap.style.display = 'block';
                    resultEl.textContent = '';
                    btnMerge.style.display = 'none';
                    btnMergeOk.style.display = 'none';

                    timer = setInterval(function() {
                        if (current >= 90) return;
                        current += Math.random() * 8;
                        if (current > 90) current = 90;
                        progressBar.style.width = Math.round(current) + '%';
                        progressText.textContent = Math.round(current) + ' %';
                    }, 400);
                }

                function finishProgress() {
                    if (timer) {
                        clearInterval(timer);
                        timer = null;
                    }
                    current = 100;
                    progressBar.style.width = '100%';
                    progressText.textContent = '100 %';
                }

                btnMerge.addEventListener('click', function() {
                    startFakeProgress();

                    fetch('index.php?ajax=merge_variants', { credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            finishProgress();
                            if (data && data.ok) {
                                resultEl.textContent = data.message || 'Varianty byly spojeny.';
                            } else {
                                resultEl.textContent = (data && data.message) ? data.message : 'Sloučení se nezdařilo.';
                            }
                            btnMergeOk.style.display = 'block';
                        })
                        .catch(function(err) {
                            finishProgress();
                            resultEl.textContent = 'Chyba při komunikaci se serverem.';
                            btnMergeOk.style.display = 'block';
                        });
                });

                btnMergeOk.addEventListener('click', function() {
                    // návrat do výchozího stavu (jen tlačítko Sloučení)
                    progressWrap.style.display = 'none';
                    progressBar.style.width = '0%';
                    progressText.textContent = '0 %';
                    resultEl.textContent = '';
                    btnMerge.style.display = 'block';
                    btnMergeOk.style.display = 'none';
                });
            })();
            </script>


        <?php endif; ?>

        <div class="logout-wrap">
            <form method="get" action="index.php">
                <input type="hidden" name="logout" value="1">
                <button type="submit" class="btn-logout">Odhlásit se</button>
            </form>
        </div>

    <?php endif; ?>
</div>
</body>
</html>