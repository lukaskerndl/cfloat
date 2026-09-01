<?php
// Přístup jen pro přihlášené (dřív byl tento endpoint veřejný).
require_once dirname(__DIR__) . '/_require_login.php';

// === DEBUG VERSION ===
// ver: 2026-01-12-DRONLY-003
if (isset($_GET['ver'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "label.php ver=2026-01-12-DRONLY-003\n";
    exit;
}

// label.php – generování PDF štítků (Packeta + GLS) podle čísla objednávky z DB (tabulka orders)

// ---- PDF výstup musí být "čistý" (žádné warningy/notice do outputu) ----
error_reporting(E_ALL);
ini_set('display_errors', '0');       // důležité: jakýkoli warning by rozbil PDF
ini_set('log_errors', '1');           // chyby logujeme do error_log
// vyčistit případné output buffery (ochrana proti rozbitému PDF)
while (ob_get_level() > 0) { @ob_end_clean(); }
// zakázat kompresi výstupu (někdy zdržuje/rozbíjí PDF stream)
if (function_exists('ini_set')) { @ini_set('zlib.output_compression', '0'); }

mb_internal_encoding('UTF-8');
require __DIR__ . '/config.php'; // připojení k DB, vytvoří $pdo

// SMS queue (volitelné) – pokud existuje složka /sms
$__sms_lib = __DIR__ . '/sms/sms_lib.php';
if (is_file($__sms_lib)) { require_once $__sms_lib; }



// ======================================================
// (původní Google Sheet – necháváme jen jako legacy, NEPOUŽÍVÁ SE)
// ======================================================

const ORDERS_SHEET_CSV_URL =
    'https://docs.google.com/spreadsheets/d/1P-ODRGtKOI5-8wQZEnY_AlPkfAgbknVkFGMc73aBHnQ/export?format=csv&gid=1311385256';

// sloupec s číslem objednávky (B = index 1)
const ORDER_COL_INDEX = 1;

// defaultní hmotnost balíku (kg)
const DEFAULT_WEIGHT_KG = 1.00;

// ======================================================
// KONFIG – PACKETA (ZÁSILKOVNA)
// ======================================================

const PACKETA_API_URL       = 'https://www.zasilkovna.cz/api/rest';

// Citlivá hesla dopravců (dřív natvrdo v kódu) – viz secrets/shipping_api_keys.php
$__cfloatShipping = include dirname(__DIR__) . '/secrets/shipping_api_keys.php';
if (!is_array($__cfloatShipping)) { $__cfloatShipping = []; }
define('PACKETA_API_KEY', $__cfloatShipping['packeta_api_key'] ?? '');
define('PACKETA_API_PASSWORD', $__cfloatShipping['packeta_api_password'] ?? '');
const PACKETA_LABEL_FORMAT  = 'A7 on A4';
const PACKETA_SENDER_LABEL  = 'C-Store.cz';

// cache pro Packetu – order => packetId
const PACKETA_CACHE_FILE    = __DIR__ . '/packeta_cache.json';
// adresář pro ukládání PDF štítků Zásilkovny
const PACKETA_LABEL_DIR     = __DIR__ . '/packeta_labels';

// ======================================================
// KONFIG – GLS (MyGLS)
// ======================================================

const GLS_USERNAME          = 'obchod@c-store.cz';
define('GLS_PASSWORD', $__cfloatShipping['gls_password'] ?? '');
const GLS_CLIENT_NUMBER_CZ  = 50018524;
const GLS_CLIENT_NUMBER_SK  = 50018524;
const GLS_API_URL_CZ        = 'https://api.mygls.cz/ParcelService.svc/json/PrintLabels';
const GLS_API_URL_SK        = GLS_API_URL_CZ; // Slovensko také přes CZ endpoint

// necháme prázdné => nebudeme ho do JSONu posílat (TypeOfPrinter dříve házel chybu 34)
const GLS_TYPE_OF_PRINTER   = '';
const GLS_PRINT_POSITION    = 1;  // 1–4 pro A4

// adresy odesílatele (pickup adresa – vždy CZ, dle smlouvy)
const GLS_PICKUP_ADDRESS = [
    'Name'            => 'C-Store.cz',
    'Street'          => 'Hrotovická',
    'HouseNumber'     => '1202/27',
    'HouseNumberInfo' => '',
    'City'            => 'Třebíč',
    'ZipCode'         => '67401',
    'CountryIsoCode'  => 'CZ',
    'ContactName'     => 'C-Store.cz',
    'ContactPhone'    => '774458618',
    'ContactEmail'    => 'obchod@c-store.cz',
];

// adresář, kam budeme ukládat GLS PDF štítky
const GLS_LABEL_DIR         = __DIR__ . '/gls_labels';
// cache pro GLS – order => parcelNumber (tracking)
const GLS_CACHE_FILE        = __DIR__ . '/gls_cache.json';

// ======================================================
// ČESKÁ POŠTA / BALÍKOVNA (B2B nAPI – ZSKService)
// ======================================================

const POSTA_API_BASE      = 'https://b2b.postaonline.cz:444/restservices/ZSKService/v1';
define('POSTA_API_TOKEN', $__cfloatShipping['posta_api_token'] ?? '');
define('POSTA_PRIVATE_KEY', $__cfloatShipping['posta_private_key'] ?? '');

// Povinné hodnoty v parcelServiceHeaderCom
const POSTA_CUSTOMER_ID      = 'M80647';   // technologické číslo
const POSTA_POST_CODE        = '67407';    // podací PSČ (Depo Třebíč)
const POSTA_LOCATION_NUMBER  = 70;         // číslo podacího místa (Depo Třebíč 70)

// Tisk štítků – idForm 101 je v ukázkách Pošty
const POSTA_ID_FORM          = 101;
// pro parcelPrinting (reprint) – smluvní číslo / CČK
const POSTA_CONTRACT_NUMBER  = '429210001';

const POSTA_CACHE_FILE       = __DIR__ . '/posta_cache.json';
const POSTA_LABEL_DIR        = __DIR__ . '/posta_labels';
const POSTA_DEFAULT_WEIGHT_KG = 1.00;

// základní služby dle Postman ukázky (7 + M). Pro dobírku doplňujeme 41.
const POSTA_PARCEL_SERVICES_BASE = ['7','M'];
// Dobírka – ČP v některých kontraktech vyžaduje službu jako "V41" (viz hlášky MISSING_REQUIRED_SERVICE_.../V41/...)
const POSTA_PARCEL_SERVICE_COD   = 'V41';
// Pro jistotu posíláme i legacy kód "41" (u některých účtů je stále akceptovaný)
const POSTA_PARCEL_SERVICE_COD_LEGACY = '41';

// ======================================================
// HELPERY
// ======================================================

function fail_label($msg) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CHYBA TISKU ŠTÍTKU (ver=2026-01-12-DRONLY-003): ' . $msg;
    exit;
}


function stream_pdf_bytes(string $pdfBytes, string $filename): void {
    // PDF musí začínat %PDF
    if (strncmp($pdfBytes, '%PDF', 4) !== 0) {
        // Někdy API vrátí JSON/text (chybová odpověď) – vypíšeme čitelně
        $sample = substr($pdfBytes, 0, 500);
        fail_label("Neplatný obsah PDF (nezačíná %PDF). Prvních 500 znaků: " . $sample);
    }

    // vyčistit případné buffery ještě jednou těsně před výstupem
    while (ob_get_level() > 0) { @ob_end_clean(); }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($pdfBytes));
    echo $pdfBytes;
    exit;
}


function ensure_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}



// ======================================================
// EVIDENCE TISKU – nekonečná řada + denní CSV (pro expedici)
// ======================================================
const PRINTLOG_DIR          = __DIR__ . '/print_logs';
const PRINTLOG_COUNTER_FILE = __DIR__ . '/print_logs/counter.txt';

function printlog_next_id(): int {
    ensure_dir(PRINTLOG_DIR);

    $fh = @fopen(PRINTLOG_COUNTER_FILE, 'c+');
    if (!$fh) {
        return 0;
    }
    $id = 0;
    if (@flock($fh, LOCK_EX)) {
        @rewind($fh);
        $raw = trim((string)@stream_get_contents($fh));
        $current = ($raw !== '' && ctype_digit($raw)) ? (int)$raw : 1;
        $id = $current;
        $next = $current + 1;

        @ftruncate($fh, 0);
        @rewind($fh);
        @fwrite($fh, (string)$next);
        @fflush($fh);
        @flock($fh, LOCK_UN);
    }
    @fclose($fh);
    return $id;
}

function printlog_today_file(): string {
    return PRINTLOG_DIR . '/' . date('Y-m-d') . '.csv';
}

function printlog_read_today_rows(): array {
    $file = printlog_today_file();
    if (!is_file($file)) return [];

    $rows = [];
    $fh = @fopen($file, 'r');
    if (!$fh) return [];

    $header = @fgetcsv($fh, 0, ';');
    if (!$header || !is_array($header)) { @fclose($fh); return []; }

    while (($r = @fgetcsv($fh, 0, ';')) !== false) {
        if (!is_array($r) || count($r) === 0) continue;
        // dorovnat počet sloupců
        if (count($r) < count($header)) {
            $r = array_pad($r, count($header), '');
        }
        $rows[] = array_combine($header, array_slice($r, 0, count($header)));
    }
    @fclose($fh);
    return $rows;
}

function printlog_row_exists_today(string $orderNumber, string $carrier, string $tracking): bool {
    $rows = printlog_read_today_rows();
    foreach ($rows as $r) {
        $o = trim((string)($r['Objednávka'] ?? ''));
        $c = trim((string)($r['Dopravce'] ?? ''));
        $t = trim((string)($r['Tracking'] ?? ''));
        if ($o === $orderNumber && $c === $carrier) {
            // když tracking neznáme, stačí order+carrier; jinak chceme i tracking shodu
            if ($tracking === '' || $t === '' || $t === $tracking) return true;
        }
    }
    return false;
}

function printlog_append_today(array $assocRow): void {
    ensure_dir(PRINTLOG_DIR);

    $file = printlog_today_file();
    $isNew = !is_file($file);

    $header = ['ID','Čas','Objednávka','Dopravce','Služba','Tracking','Dobírka','Jméno','Ulice','Město','PSČ','Telefon','Email'];
    $line = [];
    foreach ($header as $h) {
        $line[] = (string)($assocRow[$h] ?? '');
    }

    $fh = @fopen($file, 'a');
    if (!$fh) return;

    if (@flock($fh, LOCK_EX)) {
        if ($isNew) {
            @fputcsv($fh, $header, ';');
        }
        @fputcsv($fh, $line, ';');
        @fflush($fh);
        @flock($fh, LOCK_UN);
    }
    @fclose($fh);
}

function printlog_maybe_log_label(string $carrier, array $orderData): void {
    $orderNo = trim((string)($orderData['order_number'] ?? ''));
    if ($orderNo === '') return;

    $now = date('Y-m-d H:i:s');

    $name   = trim((string)($orderData['name_full'] ?? ''));
    $street = trim((string)($orderData['street'] ?? ''));
    $city   = trim((string)($orderData['city'] ?? ''));
    $zip    = trim((string)($orderData['zip'] ?? ''));
    $phone  = trim((string)($orderData['phone'] ?? ''));
    $email  = trim((string)($orderData['email'] ?? ''));

    $cod = (int)($orderData['cod_amount_int'] ?? 0);

    $carrierLabel = strtoupper($carrier);
    $service = '';
    $tracking = '';

    if ($carrier === 'posta') {
        $shipping = (string)($orderData['shipping'] ?? '');
        $shippingLower = mb_strtolower($shipping, 'UTF-8');

        $isPickup = posta_is_pickup_point($orderData);

        $isBalikovnaNaAdresu =
            ((mb_strpos($shippingLower, 'balíkovna') !== false) || (mb_strpos($shippingLower, 'balikovna') !== false))
            && (mb_strpos($shippingLower, 'na adresu') !== false);

        $prefix = ($isPickup ? 'NB' : 'DR');
        $service = $prefix;

        $tracking = trim((string)posta_cache_get_parcel_code($orderNo));
        $carrierLabel = (($prefix === 'NB') || ($prefix === 'ND')) ? 'Balíkovna' : 'Česká pošta';
    } elseif ($carrier === 'packeta') {
        $tracking = trim((string)packeta_cache_get_packet_id($orderNo));
        $carrierLabel = 'Zásilkovna';
    } elseif ($carrier === 'gls') {
        $carrierLabel = 'GLS';
        if (function_exists('gls_cache_get_parcel_number')) {
            $tracking = trim((string)gls_cache_get_parcel_number($orderNo));
        }
    }
// Reprint? Nechceme duplicitní řádky v dnešní evidenci
    if (printlog_row_exists_today($orderNo, $carrierLabel, $tracking)) {
        return;
    }

    $id = printlog_next_id();
    if ($id <= 0) return;

    printlog_append_today([
        'ID'        => $id,
        'Čas'       => $now,
        'Objednávka'=> $orderNo,
        'Dopravce'  => $carrierLabel,
        'Služba'    => $service,
        'Tracking'  => $tracking,
        'Dobírka'   => $cod,
        'Jméno'     => $name,
        'Ulice'     => $street,
        'Město'     => $city,
        'PSČ'       => $zip,
        'Telefon'   => $phone,
        'Email'     => $email,
    ]);
}

function normalize_order_no($value) {
    if ($value === null) return '';
    $s = trim((string)$value);
    if ($s === '') return '';
    if (strpos($s, "'") === 0) {
        $s = substr($s, 1);
    }
    if (substr($s, -2) === '.0') {
        $s = substr($s, 0, -2);
    }
    return $s;
}

function parse_price($value) {
    if ($value === null) return 0.0;
    $v = str_replace([' ', ','], ['', '.'], trim((string)$value));
    if ($v === '' || !is_numeric($v)) return 0.0;
    return (float)$v;
}

function http_post_raw($url, $body, $headersAssoc = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $hdr = [];
    foreach ($headersAssoc as $k => $v) {
        $hdr[] = $k . ': ' . $v;
    }
    if ($hdr) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $respBody = curl_exec($ch);
    if ($respBody === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('HTTP POST chyba: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, $respBody];
}

function http_get_raw($url, $headersAssoc = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $hdr = [];
    foreach ($headersAssoc as $k => $v) {
        $hdr[] = $k . ': ' . $v;
    }
    if ($hdr) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    }

    // Pozn.: v tomto projektu je SSL ověření vypnuté i pro ostatní dopravce.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $respBody = curl_exec($ch);
    if ($respBody === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fail_label('HTTP GET selhal: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, $respBody];
}


function http_post_json($url, $data) {
    $body = json_encode($data, JSON_UNESCAPED_UNICODE);
    return http_post_raw($url, $body, [
        'Content-Type' => 'application/json; charset=utf-8',
        'Accept'       => 'application/json',
    ]);
}

// ======================================================
// DB – načtení objednávky (náhrada za Google Sheet)
// ======================================================

function load_order_rows_from_sheet($order_number) {
    global $pdo;

    if (!isset($pdo) || !$pdo) {
        fail_label('Chybí připojení k databázi.');
    }

    $sql = "
        SELECT
            o.*,
            o.id_order,

            o.customer_name    AS customer_jmeno,
            o.customer_street  AS customer_ulice,
            o.customer_city    AS customer_mesto,
            o.customer_zip     AS customer_PSC,
            o.customer_country AS customer_zeme,
            o.customer_phone   AS customer_telefon,
            o.customer_email   AS customer_email,

            o.delivery_name    AS nazev_dopravy,
            o.payment_name     AS nazev_platby,

            o.total_price_with_vat AS total_price_s_DPH,
            o.total_price          AS total_price_DPH,

            o.payment_amount       AS platba_castka_dobirka,
            o.payment_amount       AS dobirka_castka,
            o.payment_amount       AS dobirka,

            o.zaplaceno             AS zaplaceno,
            o.gopay_zaplaceno       AS gopay_zaplaceno,
            o.gateway_payment_state AS gateway_payment_state

        FROM orders o
        WHERE o.number = :number
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':number' => $order_number,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [];
    }

    return [$row];
}

// ======================================================
// Extrakce dat z objednávky
// ======================================================

function extract_customer_and_packet_data($order_number, $rows) {
    if (!$rows) {
        fail_label('Objednávka nebyla v databázi nalezena.');
    }

    $first = $rows[0];

    $g = function($key) use ($first) {
        return trim((string)($first[$key] ?? ''));
    };

    $name_full = $g('customer_jmeno');
    $street    = $g('customer_ulice');
    $city      = $g('customer_mesto');
    $zip_code  = $g('customer_PSC');
    $country   = $g('customer_zeme') ?: 'CZ';
    $phone     = $g('customer_telefon');
    $email     = $g('customer_email');
    $shipping  = $g('nazev_dopravy');
    $payment   = $g('nazev_platby');

    if ($name_full === '' || $street === '' || $city === '' || $zip_code === '') {
        fail_label('V objednávce chybí povinné údaje zákazníka (jméno/adresa).');
    }

    $total_price = parse_price($first['total_price_s_DPH'] ?? '');
    if ($total_price <= 0) {
        $total_price = parse_price($first['total_price_DPH'] ?? '');
    }

    // DOBÍRKA – výchozí
    $cod_amount = 0.0;
    $is_cod = false;

    foreach (['platba_castka_dobirka', 'dobirka_castka', 'dobirka'] as $col) {
        if (array_key_exists($col, $first)) {
            $val = parse_price($first[$col]);
            if ($val > 0) {
                $cod_amount = $val;
                $is_cod = true;
                break;
            }
        }
    }

    $paymentLower = mb_strtolower($payment, 'UTF-8');

    // Speciálně: „Platba dobírkou“ → dobírka = total_price_with_vat
    if (mb_strpos($paymentLower, 'platba dobírkou') !== false || mb_strpos($paymentLower, 'platba dobirkou') !== false) {
        $is_cod = true;
        if ($total_price > 0) {
            $cod_amount = $total_price;
        }
    }

    // Fallback: název platby obsahuje „dobír“
    if (!$is_cod && mb_stripos($payment, 'dobír', 0, 'UTF-8') !== false) {
        $is_cod = true;
        if ($cod_amount == 0 && $total_price > 0) {
            $cod_amount = $total_price;
        }
    }

    $cod_amount_int = ($cod_amount > 0) ? (int)round($cod_amount) : 0;

    // Kontrola zaplacení – zaplaceno/gopay_zaplaceno = A/N
    $zaplaceno      = strtoupper(trim((string)($first['zaplaceno'] ?? '')));
    $gopayZaplaceno = strtoupper(trim((string)($first['gopay_zaplaceno'] ?? '')));
    $paid           = ($zaplaceno === 'A' || $gopayZaplaceno === 'A');

    $is_bank_transfer =
        (mb_strpos($paymentLower, 'převod') !== false) ||
        (mb_strpos($paymentLower, 'bank')   !== false) ||
        (mb_strpos($paymentLower, 'účet')   !== false);

    $is_online_payment =
        (mb_strpos($paymentLower, 'kartou') !== false) ||
        (mb_strpos($paymentLower, 'online') !== false) ||
        (mb_strpos($paymentLower, 'gopay')  !== false) ||
        (mb_strpos($paymentLower, 'go pay') !== false);

    if (!$is_cod && ($is_bank_transfer || $is_online_payment) && !$paid) {
        fail_label('Objednávka není zaplacená (převod / karta / online) – štítek nelze vytvořit.');
    }

    // Zásilkovna – ID výdejního místa [12345] v názvu dopravy
    $pickup_point_id = null;
    if ($shipping !== '') {
        if (preg_match('/\[(\d+)\]/u', $shipping, $m)) {
            $pickup_point_id = $m[1];
        }
    }

    // rozdělení jména
    $parts = preg_split('/\s+/u', $name_full);
    if (count($parts) >= 2) {
        $name    = array_shift($parts);
        $surname = implode(' ', $parts);
    } else {
        $name    = $name_full;
        $surname = '';
    }

    return [
        // full DB row (pro ČP/Balíkovnu – hledání ID výdejního místa v dalších polích)
        'raw_row'       => $first,
        'order_number'   => $order_number,
        'name_full'      => $name_full,
        'name'           => $name,
        'surname'        => $surname,
        'street'         => $street,
        'city'           => $city,
        'zip'            => $zip_code,
        'country'        => $country,   // Zásilkovna používá přímo DB hodnotu
        'phone'          => $phone,
        'email'          => $email,
        'shipping'       => $shipping,
        'payment'        => $payment,
        'total_price'    => $total_price,
        'is_cod'         => $is_cod,
        'cod_amount'     => $cod_amount,
        'cod_amount_int' => $cod_amount_int,
        'pickup_point_id'=> $pickup_point_id,
    ];
}

// ======================================================
// GLS – určení CZ / SK (jen pro GLS, ne pro Zásilkovnu)
// ======================================================


// ======================================================
// GLS – cache tracking (parcelNumber) pro SMS / evidenci
// ======================================================

function gls_cache_load(): array {
    $file = GLS_CACHE_FILE;
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function gls_cache_save(array $cache): void {
    @file_put_contents(GLS_CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function gls_cache_get_parcel_number(string $orderNo): string {
    $cache = gls_cache_load();
    $v = $cache[$orderNo]['parcelNumber'] ?? ($cache[$orderNo] ?? '');
    return is_string($v) ? $v : '';
}

function gls_cache_set_parcel_number(string $orderNo, string $parcelNumber): void {
    $orderNo = trim($orderNo);
    $parcelNumber = trim($parcelNumber);
    if ($orderNo === '' || $parcelNumber === '') return;
    $cache = gls_cache_load();
    if (!isset($cache[$orderNo]) || !is_array($cache[$orderNo])) $cache[$orderNo] = [];
    $cache[$orderNo]['parcelNumber'] = $parcelNumber;
    $cache[$orderNo]['updated_at'] = date('c');
    gls_cache_save($cache);
}

/**
 * Robustní „best-effort“ extrakce tracking čísla z GLS odpovědi.
 * Hledá klíče jako ParcelNumber / ParcelNo / TrackingNumber atd.
 */
function gls_extract_parcel_number_from_response($data): string {
    $candidates = [];

    $stack = [ $data ];
    while (!empty($stack)) {
        $cur = array_pop($stack);
        if (is_array($cur)) {
            foreach ($cur as $k => $v) {
                if (is_string($k)) {
                    $kl = strtolower($k);
                    if (in_array($kl, ['parcelnumber','parcelno','trackingnumber','tracking','parcelid','parcelcode'], true)) {
                        if (is_string($v) || is_numeric($v)) {
                            $candidates[] = (string)$v;
                        }
                    }
                }
                if (is_array($v)) {
                    $stack[] = $v;
                }
            }
        }
    }

    // zkus vybrat „nejpravděpodobnější“ (často 10–14 číslic)
    foreach ($candidates as $c) {
        $c = trim($c);
        if ($c === '') continue;
        if (preg_match('/^\d{8,20}$/', $c)) return $c;
        if (preg_match('/^[A-Z0-9\-]{8,30}$/', $c)) return $c;
    }

    // fallback: první kandidát
    return isset($candidates[0]) ? trim((string)$candidates[0]) : '';
}

function gls_resolve_country_code(array $orderData): string {
    // výchozí země z objednávky
    $baseCountry = strtoupper(trim((string)($orderData['country'] ?? '')));
    if ($baseCountry === '') {
        $baseCountry = 'CZ';
    }

    // pokud je v názvu dopravy "GLS Slovensko", použijeme SK
    $shippingLower = mb_strtolower((string)($orderData['shipping'] ?? ''), 'UTF-8');
    if (mb_strpos($shippingLower, 'gls slovensko') !== false) {
        return 'SK';
    }

    return $baseCountry;
}

// ======================================================
// PACKETA – CACHE + LABELY
// ======================================================

function packeta_cache_load() {
    if (!file_exists(PACKETA_CACHE_FILE)) return [];
    $json = @file_get_contents(PACKETA_CACHE_FILE);
    if ($json === false || $json === '') return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function packeta_cache_save($arr) {
    @file_put_contents(PACKETA_CACHE_FILE, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function packeta_cache_get_packet_id($orderNo) {
    $arr = packeta_cache_load();
    return $arr[$orderNo] ?? null;
}

function packeta_cache_store_packet_id($orderNo, $packetId) {
    $arr = packeta_cache_load();
    $arr[$orderNo] = $packetId;
    packeta_cache_save($arr);
}

// ======================================================
// PACKETA – createPacket + packetLabelPdf
// ======================================================

function packeta_build_create_packet_xml($data) {
    $doc = new DOMDocument('1.0', 'UTF-8');

    $root = $doc->createElement('createPacket');
    $doc->appendChild($root);

    $apiPassword = $doc->createElement('apiPassword', PACKETA_API_PASSWORD);
    $root->appendChild($apiPassword);

    $packetAttrs = $doc->createElement('packetAttributes');
    $root->appendChild($packetAttrs);

    $add = function($tag, $value) use ($doc, $packetAttrs) {
        $el = $doc->createElement($tag);
        if ($value !== null && $value !== '') {
            $el->appendChild($doc->createTextNode((string)$value));
        }
        $packetAttrs->appendChild($el);
    };

    $add('number',   $data['order_number']);
    $add('name',     $data['name']);
    $add('surname',  $data['surname']);
    $add('email',    $data['email']);
    $add('phone',    $data['phone']);

    if (!empty($data['pickup_point_id'])) {
        $add('addressId', $data['pickup_point_id']);
    }

    $add('street',   $data['street']);
    $add('city',     $data['city']);
    $add('zip',      $data['zip']);
    $add('country',  $data['country']); // CZ / SK z DB

    $add('value',    number_format($data['total_price'], 2, '.', ''));
    $add('currency', 'CZK');

    if (!empty($data['is_cod']) && $data['cod_amount_int'] > 0) {
        $add('cod',   (string)$data['cod_amount_int']);
        $add('isCod', 'true');
    } else {
        $add('cod',   '0');
        $add('isCod', 'false');
    }

    $add('eshop',  PACKETA_SENDER_LABEL);
    $add('weight', number_format(DEFAULT_WEIGHT_KG, 3, '.', ''));
    $add('apiKey', PACKETA_API_KEY);

    $doc->formatOutput = false;
    return $doc->saveXML();
}

function packeta_create_packet($orderData) {
    $xmlBody = packeta_build_create_packet_xml($orderData);

    list($status, $body) = http_post_raw(
        PACKETA_API_URL,
        $xmlBody,
        [
            'Content-Type' => 'text/xml; charset=utf-8',
            'Accept'       => 'application/xml',
        ]
    );

    if ($status >= 400) {
        throw new Exception('Packeta createPacket – HTTP ' . $status . ', odpověď: ' . substr($body, 0, 400));
    }

    $xml = @simplexml_load_string($body);
    if ($xml === false) {
        throw new Exception('Packeta createPacket – nelze parsovat XML, odpověď: ' . substr($body, 0, 400));
    }

    $statusNode = strtolower(trim((string)($xml->status ?? '')));
    if ($statusNode === 'fault') {
        $msg   = (string)($xml->string ?? '');
        $detail= (string)($xml->detail ?? '');
        throw new Exception('Packeta createPacket – fault: ' . trim($msg . ' ' . $detail));
    }

    $barcode = '';
    $node = $xml->xpath('.//barcode');
    if ($node && trim((string)$node[0]) !== '') {
        $barcode = trim((string)$node[0]);
    }

    if ($barcode === '') {
        throw new Exception('Packeta createPacket – v odpovědi chybí <barcode>, odpověď: ' . substr($body, 0, 400));
    }

    return $barcode;
}

function packeta_get_label_pdf($packetId, $format = null) {
    $useFormat = ($format !== null && $format !== '') ? $format : PACKETA_LABEL_FORMAT;
    $xmlBody =
'<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
'<packetLabelPdf>' . "\n" .
'  <apiPassword>' . htmlspecialchars(PACKETA_API_PASSWORD, ENT_QUOTES, 'UTF-8') . '</apiPassword>' . "\n" .
'  <packetId>'    . htmlspecialchars($packetId, ENT_QUOTES, 'UTF-8')        . '</packetId>' . "\n" .
'  <format>'      . htmlspecialchars($useFormat, ENT_QUOTES, 'UTF-8') . '</format>' . "\n" .
'  <offset>0</offset>' . "\n" .
'</packetLabelPdf>';

    list($status, $body) = http_post_raw(
        PACKETA_API_URL,
        $xmlBody,
        [
            'Content-Type' => 'text/xml; charset=utf-8',
            'Accept'       => 'application/xml',
        ]
    );

    if ($status >= 400) {
        throw new Exception('Packeta packetLabelPdf – HTTP ' . $status . ', odpověď: ' . substr($body, 0, 400));
    }

    $xml = @simplexml_load_string($body);
    if ($xml === false) {
        throw new Exception('Packeta packetLabelPdf – nelze parsovat XML, odpověď: ' . substr($body, 0, 400));
    }

    $statusNode = strtolower(trim((string)($xml->status ?? '')));
    if ($statusNode !== 'ok') {
        $fault  = (string)($xml->fault ?? '');
        $msg    = (string)($xml->string ?? '');
        $detail = (string)($xml->detail ?? '');
        throw new Exception(
            'Packeta packetLabelPdf – chyba: ' . trim($fault . ' ' . $msg . ' ' . $detail) .
            ', odpověď: ' . substr($body, 0, 400)
        );
    }

    $pdf_b64 = '';
    if (isset($xml->result->pdf)) {
        $pdf_b64 = trim((string)$xml->result->pdf);
    }
    if ($pdf_b64 === '' && isset($xml->result)) {
        $pdf_b64 = trim((string)$xml->result);
    }

    if ($pdf_b64 === '') {
        throw new Exception('Packeta packetLabelPdf – v odpovědi chybí PDF.');
    }

    $pdf = base64_decode($pdf_b64, true);
    if ($pdf === false) {
        throw new Exception('Packeta packetLabelPdf – chyba při dekódování Base64.');
    }

    return $pdf;
}

/**
 * Packeta – vrátí ZPL text štítku (packetLabelZpl), NE PDF. Podle dokumentace
 * je odpověď XML/HTML escapovaný ZPL text, který je NUTNÉ odescapovat před
 * odesláním na tiskárnu. dpi: 203 nebo 300 (podle tiskárny).
 */
function packeta_get_label_zpl($packetId, $dpi = 203) {
    $xmlBody =
'<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
'<packetLabelZpl>' . "\n" .
'  <apiPassword>' . htmlspecialchars(PACKETA_API_PASSWORD, ENT_QUOTES, 'UTF-8') . '</apiPassword>' . "\n" .
'  <packetId>'    . htmlspecialchars($packetId, ENT_QUOTES, 'UTF-8')        . '</packetId>' . "\n" .
'  <dpi>'         . (int)$dpi . '</dpi>' . "\n" .
'</packetLabelZpl>';

    list($status, $body) = http_post_raw(
        PACKETA_API_URL,
        $xmlBody,
        [
            'Content-Type' => 'text/xml; charset=utf-8',
            'Accept'       => 'application/xml',
        ]
    );

    if ($status >= 400) {
        throw new Exception('Packeta packetLabelZpl – HTTP ' . $status . ', odpověď: ' . substr($body, 0, 400));
    }

    $xml = @simplexml_load_string($body);
    if ($xml === false) {
        throw new Exception('Packeta packetLabelZpl – nelze parsovat XML, odpověď: ' . substr($body, 0, 400));
    }

    $statusNode = strtolower(trim((string)($xml->status ?? '')));
    if ($statusNode !== 'ok') {
        $fault  = (string)($xml->fault ?? '');
        $msg    = (string)($xml->string ?? '');
        $detail = (string)($xml->detail ?? '');
        throw new Exception('Packeta packetLabelZpl – chyba: ' . trim($fault . ' ' . $msg . ' ' . $detail));
    }

    $zplRaw = '';
    if (isset($xml->result)) {
        $zplRaw = (string)$xml->result;
    }
    if ($zplRaw === '') {
        throw new Exception('Packeta packetLabelZpl – v odpovědi chybí ZPL.');
    }

    // Odescapování (odpověď je XML/HTML escapovaný text – viz upozornění v dokumentaci)
    $zpl = html_entity_decode($zplRaw, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return $zpl;
}

// Zásilkovna ZPL – uložit na disk + použít cache packetId, stejně jako u PDF verze.
function packeta_get_or_create_label_zpl($orderData, $forceNew = false, $dpi = 203) {
    $orderNo = $orderData['order_number'];

    ensure_dir(PACKETA_LABEL_DIR);
    $safeOrder = preg_replace('/[^0-9A-Za-z_\-]/', '_', $orderNo);
    $zplPath = PACKETA_LABEL_DIR . '/PACKETA_' . $safeOrder . '_ZPL' . (int)$dpi . '.zpl';

    if (!$forceNew && is_file($zplPath) && filesize($zplPath) > 0) {
        $zpl = @file_get_contents($zplPath);
        if ($zpl !== false && trim($zpl) !== '') {
            return $zpl;
        }
    }

    $packetId = $forceNew ? null : packeta_cache_get_packet_id($orderNo);
    if (!$packetId) {
        $createData = $orderData;
        if ($forceNew) {
            $createData['order_number'] = packeta_make_unique_packet_number((string)$orderNo);
        }
        $packetId = packeta_create_packet($createData);
        packeta_cache_store_packet_id($orderNo, $packetId);
    }

    $zpl = packeta_get_label_zpl($packetId, $dpi);

    for ($i = 0; $i < 8; $i++) {
        if (is_string($zpl) && trim($zpl) !== '') break;
        usleep(300000);
        $zpl = packeta_get_label_zpl($packetId, $dpi);
    }

    if (is_string($zpl) && trim($zpl) !== '') { @file_put_contents($zplPath, $zpl); }

    return $zpl;
}



function packeta_make_unique_packet_number(string $baseOrderNo): string {
    // Packeta vyžaduje unikátní "number" – při opakovaném tisku tedy vytvoříme nový referenční identifikátor.
    // Používáme pouze znaky 0-9A-Za-z-_
    $base = preg_replace('/[^0-9A-Za-z_\-]/', '_', $baseOrderNo);
    $ts = date('ymdHis'); // 12 znaků
    try {
        $rnd = random_int(10, 99);
    } catch (Throwable $e) {
        $rnd = (int)(microtime(true) * 100) % 90 + 10;
    }
    return $base . '-R' . $ts . $rnd;
}

// Zásilkovna – uložit PDF na disk stejně jako u GLS + použít cache packetId
function packeta_get_or_create_label_pdf($orderData, $forceNew = false, $format = null) {
    $orderNo = $orderData['order_number'];

    ensure_dir(PACKETA_LABEL_DIR);
    $safeOrder = preg_replace('/[^0-9A-Za-z_\-]/', '_', $orderNo);
    // Jiný formát = jiný soubor v cache, ať se nepřepisuje/nekoliduje s výchozím
    // formátem používaným starou administrací (label_format se tam nikdy neposílá).
    $formatSlug = ($format !== null && $format !== '') ? '_' . preg_replace('/[^0-9A-Za-z]/', '', $format) : '';
    $labelPath = PACKETA_LABEL_DIR . '/PACKETA_' . $safeOrder . $formatSlug . '.pdf';

    // pokud už PDF existuje, jen ho vrátíme (pokud nevynucujeme nový štítek)
    if (!$forceNew && is_file($labelPath) && filesize($labelPath) > 0) {
        $pdf = @file_get_contents($labelPath);
        if ($pdf !== false && $pdf !== '') {
            return $pdf;
        }
    }

    // jinak použijeme cache packetId (nebo vytvoříme nový packet)
    $packetId = $forceNew ? null : packeta_cache_get_packet_id($orderNo);
    if (!$packetId) {
        $createData = $orderData;
        if ($forceNew) {
            $createData['order_number'] = packeta_make_unique_packet_number((string)$orderNo);
        }
        $packetId = packeta_create_packet($createData); // tady se zásilka vytvoří v aplikaci Zásilkovna
        packeta_cache_store_packet_id($orderNo, $packetId);
    }

    $pdf = packeta_get_label_pdf($packetId, $format);

// někdy může být PDF chvíli nedostupné – krátce zkusíme dotáhnout znovu
for ($i = 0; $i < 8; $i++) { // max ~2.4s
    if (is_string($pdf) && $pdf !== '' && strncmp($pdf, '%PDF', 4) === 0) {
        break;
    }
    usleep(300000);
    $pdf = packeta_get_label_pdf($packetId, $format);
}

    if (is_string($pdf) && $pdf !== '' && strncmp($pdf, '%PDF', 4) === 0) { @file_put_contents($labelPath, $pdf); }

    return $pdf;
}

// ======================================================
// GLS – pomocné funkce
// ======================================================

function gls_password_to_byte_array($password) {
    $hashBin = hash('sha512', $password, true);
    $arr = unpack('C*', $hashBin);
    return array_values($arr);
}

function gls_split_street($streetFull) {
    $s = trim((string)$streetFull);
    if ($s === '') return [$s, ''];

    $parts = preg_split('/\s+/u', $s);
    if (count($parts) < 2) {
        return [$s, ''];
    }

    $last = array_pop($parts);
    if (preg_match('/\d/u', $last)) {
        $street = implode(' ', $parts);
        $house  = $last;
        return [$street, $house];
    }

    return [$s, ''];
}

function gls_build_addresses($orderData, $glsCountry) {
    $glsCountry = strtoupper($glsCountry);
    if ($glsCountry !== 'SK') {
        $glsCountry = 'CZ';
    }

    // PICKUP adresa – vždy z GLS_PICKUP_ADDRESS (CZ)
    $pickup = GLS_PICKUP_ADDRESS; // CountryIsoCode = CZ, ZipCode = 67401

    list($street, $houseNumber) = gls_split_street($orderData['street']);

    // === ÚPRAVA PSČ PRO GLS – delivery ZIP ===
    $zipRaw    = trim((string)$orderData['zip']);
    $zipDigits = preg_replace('/\D+/', '', $zipRaw); // jen číslice

    if ($zipDigits !== '') {
        if (strlen($zipDigits) > 5) {
            $zipDigits = substr($zipDigits, 0, 5);
        }
    }

    if ($zipDigits === '' || strlen($zipDigits) < 4) {
        fail_label(
            "Neplatné PSČ pro GLS: '{$zipRaw}'. " .
            "Očekáváno 4–5 číslic (např. 82105)."
        );
    }

    $delivery = [
        'Name'            => $orderData['name_full'],
        'Street'          => $street,
        'HouseNumber'     => $houseNumber,
        'HouseNumberInfo' => '',
        'City'            => $orderData['city'],
        'ZipCode'         => $zipDigits,
        'CountryIsoCode'  => $glsCountry,       // CZ nebo SK – podle dopravy
        'ContactName'     => $orderData['name_full'],
        'ContactPhone'    => $orderData['phone'],
        'ContactEmail'    => $orderData['email'],
    ];

    return [$pickup, $delivery];
}

function gls_build_parcel($orderData, $clientNumber, $codCurrency, $glsCountry) {
    list($pickup, $delivery) = gls_build_addresses($orderData, $glsCountry);

    $is_cod    = !empty($orderData['is_cod']);
    $codAmount = (float)($orderData['cod_amount'] ?? 0.0);

    $unix_ms = (int)round(microtime(true) * 1000);
    $pickupDateStr = '/Date(' . $unix_ms . ')/';

    return [
        'ClientNumber'       => (int)$clientNumber,               // podle dokumentace Parcel.ClientNumber
        'ClientReference'    => $orderData['order_number'],
        'Count'              => 1,
        'CODAmount'          => ($is_cod && $codAmount > 0) ? $codAmount : 0.0,
        'CODReference'       => ($is_cod && $codAmount > 0) ? $orderData['order_number'] : '',
        'CODCurrency'        => ($is_cod && $codAmount > 0) ? $codCurrency : '',
        'Content'            => 'Sportovní zboží',
        'PickupDate'         => $pickupDateStr,
        'PickupAddress'      => $pickup,
        'DeliveryAddress'    => $delivery,
        'ServiceList'        => [],
        'ParcelPropertyList' => [],
    ];
}

function gls_extract_pdf_from_labels($val) {
    if (is_array($val) && !empty($val)) {
        $first = reset($val);
        if (is_int($first)) {
            $bin = '';
            foreach ($val as $b) {
                $bin .= chr((int)$b);
            }
            return $bin;
        }
        if (is_string($first)) {
            foreach ($val as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $decoded = base64_decode(trim($item), true);
                    if ($decoded !== false) return $decoded;
                }
            }
        }
    } elseif (is_string($val) && trim($val) !== '') {
        $decoded = base64_decode(trim($val), true);
        if ($decoded !== false) return $decoded;
    }
    return null;
}

function gls_create_label_pdf($orderData, $glsCountry, $forceNew = false, $typeOfPrinter = null) {
    $glsCountry = strtoupper($glsCountry);
    if ($glsCountry === 'SK') {
        $apiUrl       = GLS_API_URL_SK;
        $clientNumber = GLS_CLIENT_NUMBER_SK;
        $codCurrency  = 'EUR';
    } else {
        $apiUrl       = GLS_API_URL_CZ;
        $clientNumber = GLS_CLIENT_NUMBER_CZ;
        $codCurrency  = 'CZK';
    }

    ensure_dir(GLS_LABEL_DIR);
    $safeOrder = preg_replace('/[^0-9A-Za-z_\-]/', '_', $orderData['order_number']);
    // Jiný typ tiskárny = jiný soubor v cache, ať nekoliduje s výchozím
    // formátem používaným starou administrací.
    $printerSlug = ($typeOfPrinter !== null && $typeOfPrinter !== '') ? '_' . preg_replace('/[^0-9A-Za-z]/', '', $typeOfPrinter) : '';
    $labelPath = GLS_LABEL_DIR . '/GLS_' . $glsCountry . '_' . $safeOrder . $printerSlug . '.pdf';

    if (!$forceNew && is_file($labelPath) && filesize($labelPath) > 0) {
        $pdf = @file_get_contents($labelPath);
        if ($pdf !== false && $pdf !== '') {
            return $pdf;
        }
    }

    $parcel = gls_build_parcel($orderData, $clientNumber, $codCurrency, $glsCountry);

    // podle dokumentace PrintLabelsRequest: ParcelList, Username, Password, WebshopEngine, PrintPosition, ShowPrintDialog, TypeOfPrinter
    $body = [
        'Password'        => gls_password_to_byte_array(GLS_PASSWORD),
        'Username'        => GLS_USERNAME,
        'WebshopEngine'   => 'C-Store PHP',
        'ParcelList'      => [$parcel],
        'PrintPosition'   => GLS_PRINT_POSITION,
        'ShowPrintDialog' => false,
    ];

    // ClientNumberList NEPOSÍLÁME – v dokumentaci je „ClientNumberList: do not use“
    $useTypeOfPrinter = ($typeOfPrinter !== null && $typeOfPrinter !== '') ? $typeOfPrinter : GLS_TYPE_OF_PRINTER;
    if ($useTypeOfPrinter !== '') {
        $body['TypeOfPrinter'] = $useTypeOfPrinter;
    }

    list($status, $respBody) = http_post_json($apiUrl, $body);

    if ($status != 200) {
        throw new Exception('GLS PrintLabels: HTTP ' . $status . ', odpověď: ' . substr($respBody, 0, 400));
    }

    $data = json_decode($respBody, true);
    if (!is_array($data)) {
        throw new Exception('GLS PrintLabels: nelze parsovat JSON, odpověď: ' . substr($respBody, 0, 400));
    }

    if (!empty($data['PrintLabelsErrorList'])) {
        $firstErr = $data['PrintLabelsErrorList'][0] ?? null;
        if (is_array($firstErr)) {
            $code = $firstErr['ErrorCode'] ?? '';
            $msg  = $firstErr['ErrorDescription'] ?? '';
            throw new Exception('GLS PrintLabels: chyba ' . $code . ' – ' . $msg);
        }
    }

    // pokus o získání tracking (parcelNumber) a uložení do cache
    $pn = gls_extract_parcel_number_from_response($data);
    if ($pn !== '') {
        gls_cache_set_parcel_number((string)$orderData['order_number'], (string)$pn);
    }

    // DOČASNÉ LADĚNÍ – zapíše syrovou odpověď GLS, ať vidíme přesnou strukturu
    // a najdeme správné pole s trackingem (ne variabilní symbol/referenci).
    // Bezpečné smazat/odstranit, jakmile problém vyřešíme.
    $glsDebugDir = __DIR__ . '/cfloat-new/dodavatele';
    if (!is_dir($glsDebugDir)) @mkdir($glsDebugDir, 0775, true);
    $glsDebugWritten = @file_put_contents(
        $glsDebugDir . '/gls_debug_last_response.json',
        json_encode(['order_number' => $orderData['order_number'] ?? '', 'extracted_pn' => $pn, 'raw' => $data], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    // Záložní pokus i do kořene, kdyby se to náhodou zapsat dalo:
    if ($glsDebugWritten === false) {
        @file_put_contents(
            __DIR__ . '/gls_debug_last_response.json',
            json_encode(['order_number' => $orderData['order_number'] ?? '', 'extracted_pn' => $pn, 'raw' => $data], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    $pdfBytes = null;

    if (!empty($data['Labels'])) {
        $pdfBytes = gls_extract_pdf_from_labels($data['Labels']);
    }
    if ($pdfBytes === null) {
        $pdfBytes = gls_extract_pdf_from_labels($data);
    }

    if ($pdfBytes === null) {
        throw new Exception(
            'GLS PrintLabels: v odpovědi není PDF label. Data: ' .
            substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 400)
        );
    }

    @file_put_contents($labelPath, $pdfBytes);

    return $pdfBytes;
}

// ======================================================
// ČESKÁ POŠTA / BALÍKOVNA – B2B nAPI (ZSKService)
// ======================================================

function posta_require_config(): void {
    if (POSTA_API_TOKEN === '' || POSTA_PRIVATE_KEY === '' || POSTA_CUSTOMER_ID === '' || POSTA_POST_CODE === '' || (int)POSTA_LOCATION_NUMBER === 0) {
        fail_label('Česká pošta: chybí konfigurace (token / privátní klíč / customerID / postCode / locationNumber).');
    }
}

function posta_uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function posta_only_digits(string $s): string {
    return preg_replace('/\D+/', '', $s);
}

function posta_parse_czk_amount($val): int {
    if (is_int($val)) return max(0, $val);
    $s = trim((string)$val);
    if ($s === '') return 0;

    // Odstraň mezery (včetně NBSP) a měnu
    $s = str_replace(["\xc2\xa0", ' '], '', $s);
    $s = str_ireplace(['kč', 'czk'], '', $s);

    if (preg_match('/-?\d+(?:[\.,]\d+)?/', $s, $m)) {
        $num = str_replace(',', '.', $m[0]);
        $f = (float)$num;
        return (int)round(max(0, $f));
    }

    $digits = posta_only_digits($s);
    return $digits === '' ? 0 : (int)$digits;
}

function posta_normalize_phone(?string $phone): string {
    $p = trim((string)$phone);
    if ($p === '') return '';
    $p = preg_replace('/[^\d\+]+/', '', $p);
    $digits = posta_only_digits($p);
    if (strlen($digits) === 9) return '+420' . $digits;
    if ($p !== '' && $p[0] !== '+' && $digits !== '') return '+' . $digits;
    return $p;
}

function posta_normalize_isocountry($val): string {
    // Pošta chce ISO 3166-1 alpha-2 (např. CZ). Některé systémy dávají např. "ČESKÁ REPUBLIKA".
    $raw = trim((string)$val);
    if ($raw === '') return 'CZ';

    $u = strtoupper($raw);

    // už je to 2-znakový kód
    if (preg_match('/^[A-Z]{2}$/', $u)) return $u;

    // zkusíme přepsat diakritiku a porovnat
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
    $asciiU = strtoupper(trim((string)$ascii));

    if (strpos($asciiU, 'CESKA REPUBLIKA') !== false || strpos($asciiU, 'CZECH REPUBLIC') !== false || strpos($asciiU, 'CZECHIA') !== false) {
        return 'CZ';
    }
    if (strpos($asciiU, 'SLOVENSKO') !== false || strpos($asciiU, 'SLOVAKIA') !== false) {
        return 'SK';
    }

    // Neznámá hodnota → raději použijeme CZ (ČP default), než posílat celý název státu.
    return 'CZ';
}

function posta_parse_street(string $streetFull): array {
    $streetFull = trim($streetFull);
    if ($streetFull === '') return ['', '', ''];

    // např. "Těšínská 42/2672", "Hrotovická 1202/27", "Masarykova 12"
    if (preg_match('/^(.*?)(\d+[A-Za-z]?)\s*(?:\/\s*(\d+))?\s*$/u', $streetFull, $m)) {
        $street = trim($m[1]);
        $house = trim($m[2]);
        $seq   = isset($m[3]) ? trim($m[3]) : '';
        return [$street, $house, $seq];
    }

    // fallback – aspoň zkusíme poslední token jako číslo
    $parts = preg_split('/\s+/u', $streetFull);
    if (count($parts) >= 2) {
        $last = array_pop($parts);
        if (preg_match('/\d/u', $last)) {
            return [implode(' ', $parts), $last, ''];
        }
    }

    return [$streetFull, '', ''];
}

function posta_is_pickup_point(array $orderData): bool {
    $shipping = (string)($orderData['shipping'] ?? '');
    $shippingLower = mb_strtolower($shipping, 'UTF-8');

    // „Balíkovna na adresu“ = doručení na adresu (DR) => NENÍ výdejní místo
    if (mb_strpos($shippingLower, 'na adresu') !== false || mb_strpos($shippingLower, 'doručení na adresu') !== false || mb_strpos($shippingLower, 'doruceni na adresu') !== false) {
        return false;
    }

    // Pokud už máme ID výdejního místa (stejně jako u Zásilkovny), bereme to jako výdejní místo.
    if (!empty($orderData['pickup_point_id'])) return true;

    return (mb_strpos($shippingLower, 'balíkovna') !== false)
        || (mb_strpos($shippingLower, 'balikovna') !== false)
        || (mb_strpos($shippingLower, 'výdej') !== false)
        || (mb_strpos($shippingLower, 'vydej') !== false)
        || (mb_strpos($shippingLower, 'box') !== false)
        || (mb_strpos($shippingLower, 'pobočk') !== false)
        || (mb_strpos($shippingLower, 'pobock') !== false);
}

function posta_extract_pickup_id(array $orderData): string {
    if (!empty($orderData['pickup_point_id'])) {
        return (string)$orderData['pickup_point_id'];
    }

    $shipping = (string)($orderData['shipping'] ?? '');

    if (preg_match('/\[(\d{3,10})\]/u', $shipping, $m)) return $m[1];
    if (preg_match('/\bID\s*[:\-]?\s*(\d{3,10})\b/ui', $shipping, $m)) return $m[1];
    if (preg_match('/(\d{3,10})\s*$/u', trim($shipping), $m)) return $m[1];

    // fallback: zkusíme najít ID v dalších polích z DB (pokud tam Eshop-rychle ukládá ID výdejního místa)
    $raw = $orderData['raw_row'] ?? null;
    if (is_array($raw)) {
        // 1) podle názvů sloupců, které často obsahují pickup/branch/pudo apod.
        foreach ($raw as $k => $v) {
            if (!is_scalar($v)) continue;
            $s = trim((string)$v);
            if ($s === '') continue;
            $kl = mb_strtolower((string)$k, 'UTF-8');
            if (preg_match('/(pickup|pudo|vydej|výdej|balikovna|balíkovna|branch|point|box|zasilk|zásilk|packeta)/u', $kl)) {
                if (preg_match('/\b(\d{3,10})\b/u', $s, $m)) return $m[1];
            }
        }

        // 2) podle hodnot – typicky "ID: 12345" nebo "[12345]" někde v poznámce/poli
        foreach ($raw as $v) {
            if (!is_scalar($v)) continue;
            $s = trim((string)$v);
            if ($s === '') continue;
            if (preg_match('/\[(\d{3,10})\]/u', $s, $m)) return $m[1];
            if (preg_match('/\bID\s*[:\-]?\s*(\d{3,10})\b/ui', $s, $m)) return $m[1];
        }
    }

    // 3) poslední možnost: u některých e-shopů je ID Balíkovny uložené v PSČ doručovací adresy
    $zip = trim((string)($orderData['zip'] ?? ''));
    if ($zip !== '' && preg_match('/^\d{3,10}$/', $zip)) {
        return $zip;
    }

    return '';
}

function posta_build_auth_headers(string $jsonBody, int $timestamp, string $nonce): array {
    // přesně dle Postman kolekce: HMAC( SHA256(payload) + ';' + timestamp + ';' + nonce, secret )
    $sha256 = hash('sha256', $jsonBody); // hex
    $msg = $sha256 . ';' . $timestamp . ';' . $nonce;
    $sig = base64_encode(hash_hmac('sha256', $msg, POSTA_PRIVATE_KEY, true));

    return [
        'Authorization'                => 'CP-HMAC-SHA256 nonce="' . $nonce . '" signature="' . $sig . '"',
        'Api-Token'                    => POSTA_API_TOKEN,
        'Authorization-Content-SHA256' => $sha256,
        'Authorization-Timestamp'      => (string)$timestamp,
        'Content-Type'                 => 'application/json; charset=utf-8',
        'Accept'                       => 'application/json',
    ];
}

function posta_post_json(string $path, array $payload): array {
    posta_require_config();

    $url = POSTA_API_BASE . $path;

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fail_label('Česká pošta: json_encode selhal.');
    }

    $timestamp = time();
    $nonce = posta_uuidv4();
    $headers = posta_build_auth_headers($json, $timestamp, $nonce);

    list($status, $body) = http_post_raw($url, $json, $headers);

    if ($status < 200 || $status >= 300) {
        fail_label("Česká pošta: HTTP {$status}. Odpověď: {$body}");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        fail_label("Česká pošta: neočekávaná odpověď (není JSON). Prvních 500 znaků: " . substr((string)$body, 0, 500));
    }

    return $data;
}


function posta_get_json(string $path): array {
    posta_require_config();

    $url = POSTA_API_BASE . $path;

    // GET request má prázdné tělo -> sha256("")
    $timestamp = time();
    $nonce = posta_uuidv4();
    $headers = posta_build_auth_headers('', $timestamp, $nonce);

    list($status, $body) = http_get_raw($url, $headers);

    if ($status < 200 || $status >= 300) {
        fail_label("Česká pošta: HTTP {$status} (GET {$path}). Odpověď: {$body}");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        fail_label("Česká pošta: neočekávaná odpověď (GET {$path}) – není JSON. Prvních 500 znaků: " . substr((string)$body, 0, 500));
    }

    return $data;
}

function posta_row_location_number(array $row): int {
    $ln = $row['locationNumber'] ?? ($row['location_number'] ?? null);
    if (is_int($ln)) return $ln;
    if (is_string($ln) && ctype_digit(trim($ln))) return (int)trim($ln);
    if (is_float($ln)) return (int)$ln;
    return 0;
}

function posta_row_post_code(array $row): string {
    // Posta někdy vrací různé názvy polí – zkusíme nejdřív známé klíče, pak projdeme hodnoty a vytáhneme první 5místné PSČ.
    $keys = [
        'postCode','post_code','postOfficeZipCode','postOfficePostCode','zipCode','zipcode','postalCode','postcode','psc','psč'
    ];
    foreach ($keys as $k) {
        if (!isset($row[$k])) continue;
        $v = $row[$k];
        if (is_scalar($v)) {
            $d = posta_only_digits((string)$v);
            if (strlen($d) === 5) return $d;
        }
    }
    foreach ($row as $v) {
        if (is_scalar($v)) {
            $d = posta_only_digits((string)$v);
            if (strlen($d) === 5) return $d;
        }
    }
    return '';
}

function posta_extract_locations_from_response($data): array {
    // Najde z odpovědi pole lokací (záznamy, které obsahují locationNumber)
    $out = [];

    $stack = [$data];
    while ($stack) {
        $cur = array_pop($stack);
        if (is_array($cur)) {
            $isAssoc = array_keys($cur) !== range(0, count($cur) - 1);

            if ($isAssoc && (isset($cur['locationNumber']) || isset($cur['location_number']))) {
                // sjednotíme klíč
                if (!isset($cur['locationNumber']) && isset($cur['location_number'])) {
                    $cur['locationNumber'] = $cur['location_number'];
                }
                $out[] = $cur;
            }

            foreach ($cur as $v) {
                if (is_array($v)) $stack[] = $v;
            }
        }
    }

    // Odstranění duplicit dle locationNumber
    $uniq = [];
    foreach ($out as $row) {
        $ln = (string)posta_row_location_number($row);
        if ($ln === '0') continue;
        $uniq[$ln] = $row;
    }
    return array_values($uniq);
}

function posta_resolve_sender_location(): array {
    // Vrací ['postCode' => 'xxxxx', 'locationNumber' => int]
    // 1) zkusí použít konfiguraci; 2) když nesedí, stáhne lokace z API dle idContract a vybere nejvhodnější
    $cfgPost = (string)POSTA_POST_CODE;
    $cfgLoc  = (int)POSTA_LOCATION_NUMBER;

    $locResp = posta_get_json('/location/idContract/' . rawurlencode((string)POSTA_CONTRACT_NUMBER));
    $locations = posta_extract_locations_from_response($locResp);

    if (!$locations) {
        // když to Pošta vrátí jinak, aspoň vrať konfiguraci
        return ['postCode' => $cfgPost, 'locationNumber' => $cfgLoc];
    }

    // Preferujeme:
    // - přesná shoda locationNumber
    // - shoda postCode
    // - shoda "Třebíč" v názvu
    $best = null;

    foreach ($locations as $row) {
        $ln = posta_row_location_number($row);
        if ($cfgLoc && $ln === $cfgLoc) { $best = $row; break; }
    }

    if ($best === null && $cfgPost !== '') {
        foreach ($locations as $row) {
            $pc = posta_row_post_code($row);
            if ($pc !== '' && $pc === $cfgPost) { $best = $row; break; }
        }
    }

    if ($best === null) {
        foreach ($locations as $row) {
            $name = (string)($row['locationName'] ?? ($row['name'] ?? ''));
            if ($name !== '' && preg_match('~třebíč|trebic~iu', $name)) { $best = $row; break; }
        }
    }

    if ($best === null) $best = $locations[0];

    $pc = posta_row_post_code($best);
    if ($pc === '') $pc = $cfgPost;
    $ln = posta_row_location_number($best);
    if ($ln === 0) $ln = $cfgLoc;

    return ['postCode' => $pc, 'locationNumber' => $ln];
}

function posta_result_header(array $resp): array {
    $rh = $resp['responseHeader']['resultHeader'] ?? null;
    return is_array($rh) ? $rh : [];
}




function posta_find_parcel_state_texts(array $resp): array {
    $out = [];
    $rpd = $resp['responseHeader']['resultParcelData'] ?? null;
    if (is_array($rpd)) {
        foreach ($rpd as $row) {
            $psr = $row['parcelStateResponse'] ?? null;
            if (is_array($psr)) {
                foreach ($psr as $e) {
                    $t = (string)($e['responseText'] ?? '');
                    if ($t !== '') $out[] = $t;
                }
            }
        }
    }
    return array_values(array_unique($out));
}

function posta_has_parcel_state_text(array $resp, string $needle): bool {
    foreach (posta_find_parcel_state_texts($resp) as $t) {
        if (strcasecmp($t, $needle) === 0) return true;
    }
    return false;
}

function posta_cache_load(): array {
    if (!is_file(POSTA_CACHE_FILE)) return [];
    $raw = @file_get_contents(POSTA_CACHE_FILE);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function posta_cache_save(array $cache): void {
    @file_put_contents(POSTA_CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function posta_cache_get_parcel_code(string $orderNo): string {
    $cache = posta_cache_load();
    return (string)($cache[$orderNo]['parcelCode'] ?? '');
}

function posta_cache_set_parcel_code(string $orderNo, string $parcelCode): void {
    $cache = posta_cache_load();
    if (!isset($cache[$orderNo])) $cache[$orderNo] = [];
    $cache[$orderNo]['parcelCode'] = $parcelCode;
    posta_cache_save($cache);
}

function posta_find_pdf_base64($val): ?string {
    if (is_string($val)) {
        $s = trim($val);
        if (strlen($s) > 100) {
            $bin = base64_decode($s, true);
            if ($bin !== false && strncmp($bin, '%PDF', 4) === 0) return $s;
        }
        return null;
    }
    if (is_array($val)) {
        foreach ($val as $v) {
            $found = posta_find_pdf_base64($v);
            if ($found) return $found;
        }
    }
    return null;
}

function posta_extract_pdf_bytes(array $resp): string {
    $b64 = posta_find_pdf_base64($resp);
    if (!$b64) {
        fail_label('Česká pošta: v odpovědi jsem nenašel Base64 PDF. Odpověď: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
    }
    $pdf = base64_decode($b64, true);
    if ($pdf === false) {
        fail_label('Česká pošta: base64_decode selhal.');
    }
    return $pdf;
}

function posta_find_parcel_code($val): string {
    if (is_string($val)) {
        $s = trim($val);
        if (preg_match('/^(DR|NB|NP|ND)\d{6,}[A-Z0-9]*$/', $s)) return $s;
        return '';
    }
    if (is_array($val)) {
        foreach ($val as $v) {
            $found = posta_find_parcel_code($v);
            if ($found !== '') return $found;
        }
    }
    return '';
}

function posta_create_parcel_and_get_code(array $orderData): string {
    $shipping = (string)($orderData['shipping'] ?? '');
    $shippingLower = mb_strtolower($shipping, 'UTF-8');

    $isPickup = posta_is_pickup_point($orderData);

    $prefix = ($isPickup ? 'NB' : 'DR');

    $isCod = (!empty($orderData['is_cod']) && posta_parse_czk_amount($orderData['cod_amount'] ?? 0) > 0);

    $services = POSTA_PARCEL_SERVICES_BASE;
    if ($isCod) {
        $services[] = POSTA_PARCEL_SERVICE_COD;
        if (defined('POSTA_PARCEL_SERVICE_COD_LEGACY')) $services[] = POSTA_PARCEL_SERVICE_COD_LEGACY;
    }
    $services = array_values(array_unique($services));

    $insuredValue = (int)round(max(0, (float)($orderData['total_price'] ?? 0)));
    $zipDigits = posta_only_digits((string)($orderData['zip'] ?? ''));
    if ($zipDigits !== '' && strlen($zipDigits) > 5) $zipDigits = substr($zipDigits, 0, 5);

    $addr = null;

    if ($isPickup) {
        $pickupId = posta_extract_pickup_id($orderData);
        if ($pickupId === '') {
            $ship = (string)($orderData['shipping'] ?? '');
            $zip  = (string)($orderData['zip'] ?? '');
            fail_label("Česká pošta (Balíkovna – výdejní místo): nenašel jsem ID výdejního místa v datech objednávky. Hledal jsem v názvu dopravy ([12345] / ID: 12345), v dalších polích z DB a jako poslední možnost v PSČ. Název dopravy: {$ship} | PSČ v objednávce: {$zip}");
        }
        $addr = [
            'street'     => 'Balíkovna',
            'zipCode'    => (string)$pickupId,
            'isoCountry' => 'CZ',
        ];
    } else {
        list($street, $house, $seq) = posta_parse_street((string)($orderData['street'] ?? ''));
        $addr = [
            'street'     => $street !== '' ? $street : (string)($orderData['street'] ?? ''),
            'houseNumber'=> $house,
            'city'       => (string)($orderData['city'] ?? ''),
            'zipCode'    => $zipDigits,
            'isoCountry' => posta_normalize_isocountry($orderData['country'] ?? 'CZ'),
        ];
        if ($seq !== '') $addr['sequenceNumber'] = $seq;

        if ($addr['zipCode'] === '' || strlen((string)$addr['zipCode']) < 4) {
            fail_label("Česká pošta: neplatné PSČ v objednávce: '" . (string)($orderData['zip'] ?? '') . "'");
        }
        if ($addr['houseNumber'] === '') {
            // Pošta občas vyžaduje číslo domu – když ho nerozpoznáme, pošleme aspoň celý řetězec do street
            unset($addr['houseNumber']);
        }
    }

    $parcelParams = [
        'recordID'         => '1',
        'prefixParcelCode' => $prefix,
        'weight'           => (string)POSTA_DEFAULT_WEIGHT_KG,
        'insuredValue'     => $insuredValue,
        'currency'         => 'CZK',
    ];

    if ($isCod) {
        $parcelParams['amount'] = posta_parse_czk_amount($orderData['cod_amount'] ?? 0);
        $vs = posta_only_digits((string)($orderData['order_number'] ?? ''));
        $parcelParams['vsVoucher'] = $vs !== '' ? $vs : (string)($orderData['order_number'] ?? '');
    }

    $payload = [
        'parcelServiceHeader' => [
            'parcelServiceHeaderCom' => [
                'transmissionDate' => date('Y-m-d'),
                'customerID'       => POSTA_CUSTOMER_ID,
                'postCode'         => POSTA_POST_CODE,
                'locationNumber'   => (int)POSTA_LOCATION_NUMBER,
            ],
        ],
        'parcelServiceData' => [
            'parcelParams'   => $parcelParams,
            'parcelServices' => array_values($services),
            'parcelAddress'  => [
                'recordID'        => '1',
                'firstName'       => (string)($orderData['name'] ?? ''),
                'surname'         => (string)($orderData['surname'] ?? ''),
                'aditionAddress'  => '',
                'address'         => $addr,
                'mobilNumber'     => posta_normalize_phone((string)($orderData['phone'] ?? '')),
                'emailAddress'    => (string)($orderData['email'] ?? ''),
            ],
        ],
    ];

    $triedLocations = [];
    $triedLocations[] = (string)$payload['parcelServiceHeader']['parcelServiceHeaderCom']['postCode'] . '-' . (string)$payload['parcelServiceHeader']['parcelServiceHeaderCom']['locationNumber'];

    $resp = posta_post_json('/parcelService', $payload);

    // Kontrola responseHeader + případné opravy lokace/prefixu
    $rh = posta_result_header($resp);
    $codeInt = (int)($rh['responseCode'] ?? 0);
    if (!empty($rh) && !in_array($codeInt, [0,1], true)) {
        $txt = (string)($rh['responseText'] ?? '');

        // 1) INVALID_LOCATION = špatně nastavené podací místo (postCode/locationNumber)
        if (strcasecmp($txt, 'INVALID_LOCATION') === 0) {
            $resolved = posta_resolve_sender_location();
            if (!empty($resolved['postCode']) && !empty($resolved['locationNumber'])) {
                $payload['parcelServiceHeader']['parcelServiceHeaderCom']['postCode'] = (string)$resolved['postCode'];
                $payload['parcelServiceHeader']['parcelServiceHeaderCom']['locationNumber'] = (int)$resolved['locationNumber'];
                $triedLocations[] = (string)$resolved['postCode'] . '-' . (string)$resolved['locationNumber'];
                $resp = posta_post_json('/parcelService', $payload);
                $rh = posta_result_header($resp);
                $codeInt = (int)($rh['responseCode'] ?? 0);
            } else {
                fail_label('Česká pošta: INVALID_LOCATION a nepodařilo se zjistit správné podací místo z /location.');
            }
        }

        // 2) INVALID_PREFIX (v parcelStateResponse) = pro danou lokaci není povolený prefix (u vás DR)
        if (!in_array($codeInt, [0,1], true) && $prefix === 'DR' && posta_has_parcel_state_text($resp, 'INVALID_PREFIX')) {
            $locRespAll = posta_get_json('/location/idContract/' . rawurlencode((string)POSTA_CONTRACT_NUMBER));
            $locsAll = posta_extract_locations_from_response($locRespAll);

            // připravíme seznam lokací k vyzkoušení
            $pairs = [];
            if ($locsAll) {
                foreach ($locsAll as $row) {
                    $pcTry = posta_row_post_code($row);
                    $lnTry = posta_row_location_number($row);
                    if ($pcTry === '' || $lnTry === 0) continue;
                    $pairs[] = [$pcTry, $lnTry];
                }
            }
            if (!$pairs) {
                $pairs[] = [(string)$payload['parcelServiceHeader']['parcelServiceHeaderCom']['postCode'], (int)$payload['parcelServiceHeader']['parcelServiceHeaderCom']['locationNumber']];
            }

            // varianty služeb – některé účty nepovolují "M" u DR; u některých je naopak potřeba doplnit 34/97
            $curServices = $payload['parcelServiceData']['parcelServices'] ?? [];
            if (!is_array($curServices)) $curServices = [];
            $serviceVariants = [];
            $serviceVariants[] = array_values($curServices);

            if (in_array('M', $curServices, true)) {
                $serviceVariants[] = array_values(array_diff($curServices, ['M']));
            }
            $plus = array_values(array_unique(array_merge($curServices, ['34','97'])));
            $serviceVariants[] = $plus;
            if (in_array('M', $plus, true)) {
                $serviceVariants[] = array_values(array_diff($plus, ['M']));
            }

            // deduplikace variant
            $uniq = [];
            foreach ($serviceVariants as $sv) {
                $sv = array_values(array_filter($sv, fn($x) => $x !== null && $x !== ''));
                $key = implode(',', $sv);
                if (!isset($uniq[$key])) $uniq[$key] = $sv;
            }
            $serviceVariants = array_values($uniq);

            foreach ($serviceVariants as $sv) {
                // nastavíme služby
                $payload['parcelServiceData']['parcelServices'] = $sv;

                foreach ($pairs as $p) {
                    [$pcTry, $lnTry] = $p;
                    $key = $pcTry . '-' . (string)$lnTry . ' svc=' . implode(',', $sv);
                    if (in_array($key, $triedLocations, true)) continue;

                    $payload['parcelServiceHeader']['parcelServiceHeaderCom']['postCode'] = $pcTry;
                    $payload['parcelServiceHeader']['parcelServiceHeaderCom']['locationNumber'] = (int)$lnTry;
                    $triedLocations[] = $key;

                    $respTry = posta_post_json('/parcelService', $payload);
                    $rhTry = posta_result_header($respTry);
                    if (!empty($rhTry) && in_array((int)($rhTry['responseCode'] ?? 0), [0,1], true)) {
                        $resp = $respTry;
                        $rh = $rhTry;
                        $codeInt = (int)($rh['responseCode'] ?? 0);
                        break 2; // ven i ze smyčky služeb
                    }
                }
            }
        }

        // Pokud se nám nepodařilo opravit – končíme chybou
        if (!in_array($codeInt, [0,1], true)) {
            $extra = '';
            if ($prefix === 'DR' && posta_has_parcel_state_text($resp, 'INVALID_PREFIX')) {
                $extra = ' | Prefix=DR. Vyzkoušené lokace: ' . implode(', ', array_values(array_unique($triedLocations)));
            }
            fail_label('Česká pošta: ' . (string)($rh['responseText'] ?? $txt) . ' (kód ' . (string)($rh['responseCode'] ?? '') . ')' . $extra . '. Odpověď: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
        }
    }

    $code = posta_find_parcel_code($resp);
    if ($code === '') {
        fail_label('Česká pošta: nepodařilo se z odpovědi /parcelService vyčíst parcelCode. Odpověď: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
    }

    return $code;
}

function posta_print_label_pdf_by_code(string $parcelCode): string {
    if (POSTA_CONTRACT_NUMBER === '') {
        fail_label('Česká pošta: chybí POSTA_CONTRACT_NUMBER (CČK) – je potřeba pro parcelPrinting.');
    }

    $payload = [
        'printingHeader' => [
            'customerID'      => POSTA_CUSTOMER_ID,
            'contractNumber'  => POSTA_CONTRACT_NUMBER,
            'idForm'          => (int)POSTA_ID_FORM,
            'shiftHorizontal' => 0,
            'shiftVertical'   => 0,
            'position'        => 0,
        ],
        'printingData' => [$parcelCode],
    ];

    $resp = posta_post_json('/parcelPrinting', $payload);

    return posta_extract_pdf_bytes($resp);
}

function posta_get_or_create_label_pdf(array $orderData, bool $forceNew = false): string {
    ensure_dir(POSTA_LABEL_DIR);

    $orderNo = (string)($orderData['order_number'] ?? '');
    $safeOrder = preg_replace('/[^0-9A-Za-z_\-]/', '_', $orderNo);

    if (!$forceNew) {
    $cachedCode = posta_cache_get_parcel_code($orderNo);
    if ($cachedCode !== '') {
        $labelPath = POSTA_LABEL_DIR . '/POSTA_' . $safeOrder . '.pdf';
        if (is_file($labelPath) && filesize($labelPath) > 0) {
            $pdf = @file_get_contents($labelPath);
            if ($pdf !== false && $pdf !== '') return $pdf;
        }
        $pdf = posta_print_label_pdf_by_code($cachedCode);
        @file_put_contents($labelPath, $pdf);
        return $pdf;
    }
} else {
    $cachedCode = '';
}


    $code = posta_create_parcel_and_get_code($orderData);
    posta_cache_set_parcel_code($orderNo, $code);

    $pdf = posta_print_label_pdf_by_code($code);
    $labelPath = POSTA_LABEL_DIR . '/POSTA_' . $safeOrder . '.pdf';
    @file_put_contents($labelPath, $pdf);

    return $pdf;
}

// ======================================================
// HLAVNÍ LOGIKA – výběr dopravce + výstup PDF
// ======================================================

$eanRaw = isset($_GET['ean']) ? trim((string)$_GET['ean']) : '';
if ($eanRaw === '') {
    fail_label('chybí parametr ?ean=číslo_objednávky.');
}

$orderNo = normalize_order_no($eanRaw);
if ($orderNo === '') {
    fail_label('číslo objednávky má neplatný formát.');
}

$rows = load_order_rows_from_sheet($orderNo);
$orderData = extract_customer_and_packet_data($orderNo, $rows);

// Volitelný override dobírky z UI (Tisk štítků)
$codOverrideOn  = isset($_GET['cod_override_on']) && (string)$_GET['cod_override_on'] === '1';
$codOverrideRaw = isset($_GET['cod_override']) ? trim((string)$_GET['cod_override']) : '';
if ($codOverrideOn) {
    $ov = ($codOverrideRaw === '') ? 0.0 : parse_price($codOverrideRaw);
    if ($ov < 0) $ov = 0.0;
    $orderData['cod_amount'] = $ov;
    $orderData['cod_amount_int'] = ($ov > 0) ? (int)round($ov) : 0;
    $orderData['is_cod'] = ($orderData['cod_amount_int'] > 0);
}

// Volitelný formát štítku Zásilkovny (jen pro Packetu). Bez tohoto parametru
// (starý systém ho nikdy neposílá) se chová úplně stejně jako doteď –
// PACKETA_LABEL_FORMAT ("A7 on A4"). Použito např. novým cFloat modulem
// Tisk štítků pro tisk přímo na A6 štítkovou tiskárnu.
$labelFormatAllowed = ['A7 on A4', 'A6 on A4', 'A6 on A6', 'A7 on A7', 'A8 on A8', '105x35mm on A4'];
$labelFormatRaw = isset($_GET['label_format']) ? trim((string)$_GET['label_format']) : '';
$labelFormat = in_array($labelFormatRaw, $labelFormatAllowed, true) ? $labelFormatRaw : null;

// Volitelný typ tiskárny pro GLS (výchozí = 4 štítky na A4 arch; THERMO = jeden
// štítek přímo ve velikosti štítkové tiskárny). Bez parametru (starý systém ho
// nikdy neposílá) se chová úplně stejně jako doteď.
$glsPrinterTypeAllowed = ['A4_2x2', 'A4_4x1', 'Connect', 'Thermo', 'ThermoZPL', 'ThermoZPL_300DPI', 'ShipItThermoPdf'];
$glsPrinterTypeRaw = isset($_GET['gls_printer_type']) ? trim((string)$_GET['gls_printer_type']) : '';
$glsPrinterType = in_array($glsPrinterTypeRaw, $glsPrinterTypeAllowed, true) ? $glsPrinterTypeRaw : null;

// Volitelný výstup v čistém ZPL (přímý jazyk štítkových/Zebra tiskáren) místo
// PDF. Nejspolehlivější způsob, jak štítek přesně vyplní celou plochu, protože
// ZPL má rozměr štítku pevně daný a nezávisí na žádném PDF škálování.
// Bez parametru (starý systém ho nikdy neposílá) beze změny = PDF jako dřív.
$wantZpl = isset($_GET['output']) && (string)$_GET['output'] === 'zpl';

$shippingLower = mb_strtolower($orderData['shipping'], 'UTF-8');

// Pokud je aktivní override dobírky, vynutíme nový štítek (nové vytvoření zásilky) – jinak by se použila cache/PDF.
$forceNewLabel = $codOverrideOn;


// dopravce
$carrier = null;
if (
    mb_strpos($shippingLower, 'zasilkovna') !== false ||
    mb_strpos($shippingLower, 'zásilkovna') !== false ||
    mb_strpos($shippingLower, 'packeta')    !== false
) {
    $carrier = 'packeta';
} elseif (mb_strpos($shippingLower, 'gls') !== false) {
    $carrier = 'gls';
} elseif (
    mb_strpos($shippingLower, 'balíkovna')   !== false ||
    mb_strpos($shippingLower, 'balikovna')   !== false ||
    mb_strpos($shippingLower, 'česká pošta') !== false ||
    mb_strpos($shippingLower, 'ceska posta') !== false ||
    mb_strpos($shippingLower, 'pošta')       !== false ||
    mb_strpos($shippingLower, 'posta')       !== false
) {
    $carrier = 'posta';
} else {
    fail_label('Podle dopravy nelze určit dopravce (Zásilkovna / GLS / Česká pošta).');
}

// země pro GLS – pro Zásilkovnu se NEMĚNÍ (bere se z DB)
$countryForGls = gls_resolve_country_code($orderData);

// ---------------------------------------------------------------------
// ZPL VÝSTUP (přímý jazyk štítkové tiskárny) – jen když je vyžádán a jen
// pro dopravce, kde to umíme (Packeta, GLS). Nic nemění na běžném PDF
// chování, pokud parametr output=zpl není poslán.
// ---------------------------------------------------------------------
if ($wantZpl && ($carrier === 'packeta' || $carrier === 'gls')) {
    try {
        $zpl = null;
        if ($carrier === 'packeta') {
            $zpl = packeta_get_or_create_label_zpl($orderData, $forceNewLabel);
        } elseif ($carrier === 'gls') {
            $glsZplType = ($glsPrinterType !== null && stripos($glsPrinterType, 'zpl') !== false)
                ? $glsPrinterType
                : 'ThermoZPL';
            $zpl = gls_create_label_pdf($orderData, $countryForGls, $forceNewLabel, $glsZplType);
        }
        if ($zpl === null || trim((string)$zpl) === '') {
            fail_label('ZPL štítek se nepodařilo vytvořit (prázdná odpověď).');
        }
        try { printlog_maybe_log_label($carrier, $orderData); } catch (Throwable $e) { error_log('PRINTLOG: ' . $e->getMessage()); }
        try { sms_enqueue_after_label($carrier, $orderData); } catch (Throwable $e) { error_log('SMS: ' . $e->getMessage()); }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $zpl;
        exit;
    } catch (Exception $e) {
        fail_label($e->getMessage());
    }
}

// Pokud už pro tuto objednávku existuje štítek / cache, další tisk má vytvořit NOVOU zásilku
// (= nové číslo balíku / tracking). Výjimka: první tisk (bez cache) zůstává beze změny.
if (!$forceNewLabel) {
    $hadExisting = false;
    $safeOrder = preg_replace('/[^0-9A-Za-z_\-]/', '_', (string)$orderNo);

    if ($carrier === 'packeta') {
        $formatSlug = ($labelFormat !== null) ? '_' . preg_replace('/[^0-9A-Za-z]/', '', $labelFormat) : '';
        $labelPath = PACKETA_LABEL_DIR . '/PACKETA_' . $safeOrder . $formatSlug . '.pdf';
        $cachedId = trim((string)packeta_cache_get_packet_id((string)$orderNo));
        $hadExisting = ((is_file($labelPath) && filesize($labelPath) > 0) || ($cachedId !== ''));
    } elseif ($carrier === 'gls') {
        $glsC = strtoupper((string)$countryForGls);
        if ($glsC !== 'SK') $glsC = 'CZ';
        $printerSlug = ($glsPrinterType !== null) ? '_' . preg_replace('/[^0-9A-Za-z]/', '', $glsPrinterType) : '';
        $labelPath = GLS_LABEL_DIR . '/GLS_' . $glsC . '_' . $safeOrder . $printerSlug . '.pdf';
        $hadExisting = (is_file($labelPath) && filesize($labelPath) > 0);
        if (!$hadExisting && function_exists('gls_cache_get_parcel_number')) {
            $cachedPn = trim((string)gls_cache_get_parcel_number((string)$orderNo));
            $hadExisting = ($cachedPn !== '');
        }
    } elseif ($carrier === 'posta') {
        $labelPath = POSTA_LABEL_DIR . '/POSTA_' . $safeOrder . '.pdf';
        $hadExisting = (is_file($labelPath) && filesize($labelPath) > 0);
        if (!$hadExisting) {
            $cachedCode = trim((string)posta_cache_get_parcel_code((string)$orderNo));
            $hadExisting = ($cachedCode !== '');
        }
    }

    if ($hadExisting) {
        $forceNewLabel = true;
    }
}

$pdfBytes = null;

try {
    if ($carrier === 'packeta') {
        $pdfBytes = packeta_get_or_create_label_pdf($orderData, $forceNewLabel, $labelFormat);
    } elseif ($carrier === 'gls') {
        $pdfBytes = gls_create_label_pdf($orderData, $countryForGls, $forceNewLabel, $glsPrinterType);
    } elseif ($carrier === 'posta') {
        $pdfBytes = posta_get_or_create_label_pdf($orderData, $forceNewLabel);
    }
} catch (Exception $e) {
    fail_label($e->getMessage());
}

if ($pdfBytes === null) {
    fail_label('Štítek se nepodařilo vytvořit.');
}

try { printlog_maybe_log_label($carrier, $orderData); } catch (Throwable $e) { error_log('PRINTLOG: ' . $e->getMessage()); }

try { sms_enqueue_after_label($carrier, $orderData); } catch (Throwable $e) { error_log('SMS: ' . $e->getMessage()); }

stream_pdf_bytes($pdfBytes, $carrier . '_label_' . $orderNo . '.pdf');
