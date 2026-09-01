<?php
// Přístup jen pro přihlášené (dřív byl tento endpoint veřejný).
require_once __DIR__ . '/_require_login.php';

// print_pickup_lookup.php – dohledání doručení / vyzvednutí podle čísla objednávky
// Používá: DB (orders) + cache (Zásilkovna/ČP) + /print_logs (fallback)
// Výstup: JSON

error_reporting(E_ALL);
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=utf-8');

function out_json($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function out_error($msg) {
    out_json(['ok' => false, 'error' => $msg]);
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

    // v projektu je SSL ověření vypnuté (stejně jako u štítků)
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

function weekday_cz($dt) {
    static $map = [
        1 => 'pondělí',
        2 => 'úterý',
        3 => 'středa',
        4 => 'čtvrtek',
        5 => 'pátek',
        6 => 'sobota',
        7 => 'neděle',
    ];
    $n = (int)$dt->format('N');
    return $map[$n] ?? '';
}

function format_dt_human($iso) {
    if (!$iso) return '';
    try {
        $dt = new DateTime($iso);
        // server má typicky Europe/Prague, ale kdyby ne, necháme tak
        return $dt->format('d.m.Y H:i') . ' (' . weekday_cz($dt) . ')';
    } catch (Throwable $e) {
        // fallback – vrátíme původní
        return (string)$iso;
    }
}


function format_dt_human_dateonly($iso) {
    if (!$iso) return '';
    try {
        $dt = new DateTime($iso);
        return $dt->format('d.m.Y') . ' (' . weekday_cz($dt) . ')';
    } catch (Throwable $e) {
        return (string)$iso;
    }
}

function dotnet_date_to_iso($value) {
    if (!$value) return '';
    $s = trim((string)$value);
    if ($s === '') return '';
    if (preg_match('~/Date\(([-]?[0-9]+)~', $s, $mm)) {
        $ms = (int)$mm[1];
        $sec = $ms / 1000.0;
        $dt = new DateTime('@' . (string)intval($sec));
        try {
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        } catch (Throwable $e) {
            // ignore
        }
        return $dt->format('c');
    }
    return $s;
}

function any_date_to_iso($value) {
    if ($value === null) return '';
    $s = trim((string)$value);
    if ($s === '') return '';

    // .NET style /Date(....)/
    if (strpos($s, '/Date(') !== false) {
        $iso = dotnet_date_to_iso($s);
        if ($iso !== '') return $iso;
    }

    // ČP/české formáty: dd.mm.YYYY nebo dd.mm.YYYY HH:MM(:SS)
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $s, $m)) {
        $dd = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mm = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $yyyy = $m[3];
        $hh = isset($m[4]) ? str_pad($m[4], 2, '0', STR_PAD_LEFT) : '00';
        $ii = isset($m[5]) ? str_pad($m[5], 2, '0', STR_PAD_LEFT) : '00';
        $ss = isset($m[6]) ? str_pad($m[6], 2, '0', STR_PAD_LEFT) : '00';
        return $yyyy . '-' . $mm . '-' . $dd . 'T' . $hh . ':' . $ii . ':' . $ss;
    }

    try {
        $dt = new DateTime($s);
        return $dt->format('c');
    } catch (Throwable $e) {
        return $s;
    }
}

// ======================================================
// LOGY – dohledání tracking+carrier podle čísla objednávky
// ======================================================
function find_latest_log_row_by_order(string $orderNo): array {
    $dir = __DIR__ . '/print_logs';
    if (!is_dir($dir)) return [];

    $files = glob($dir . '/*.csv');
    if (!$files) return [];

    // seřadit podle názvu (YYYY-MM-DD.csv) sestupně
    rsort($files);

    $best = [];
    $bestTs = 0;

    foreach ($files as $file) {
        $fh = @fopen($file, 'r');
        if (!$fh) continue;

        $header = @fgetcsv($fh, 0, ';');
        if (!$header || !is_array($header)) { @fclose($fh); continue; }

        while (($r = @fgetcsv($fh, 0, ';')) !== false) {
            if (!is_array($r) || count($r) === 0) continue;
            if (count($r) < count($header)) $r = array_pad($r, count($header), '');
            $assoc = @array_combine($header, array_slice($r, 0, count($header)));
            if (!is_array($assoc)) continue;

            $o = trim((string)($assoc['Objednávka'] ?? ''));
            if ($o !== $orderNo) continue;

            $timeStr = trim((string)($assoc['Čas'] ?? ''));
            $ts = 0;
            if ($timeStr !== '') {
                $ts = strtotime($timeStr);
            }
            if ($ts <= 0) {
                // fallback: použijeme čas souboru
                $ts = @filemtime($file) ?: 0;
            }

            if ($ts >= $bestTs) {
                $bestTs = $ts;
                $best = $assoc;
            }
        }

        @fclose($fh);

        // optimalizace: když máme z dneška a je to čerstvé, už neprocházíme starší
        if ($best && strpos(basename($file), date('Y-m-d')) === 0) {
            // zůstáváme, ale ještě projdeme celý dnešní soubor (už je)
            // starší soubory už nemají smysl
            break;
        }
    }

    return $best;
}

// ======================================================
// PACKETA (Zásilkovna) – tracking
// ======================================================
const PACKETA_API_URL      = 'https://www.zasilkovna.cz/api/rest';

// Citlivá hesla dopravců (dřív natvrdo v kódu) – viz secrets/shipping_api_keys.php
$__cfloatShipping = include __DIR__ . '/secrets/shipping_api_keys.php';
if (!is_array($__cfloatShipping)) { $__cfloatShipping = []; }
define('PACKETA_API_PASSWORD', $__cfloatShipping['packeta_api_password'] ?? '');
const PACKETA_CACHE_FILE   = __DIR__ . '/packeta_cache.json';

function packeta_cache_load() {
    if (!file_exists(PACKETA_CACHE_FILE)) return [];
    $json = @file_get_contents(PACKETA_CACHE_FILE);
    if ($json === false || $json === '') return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}
function packeta_cache_get_packet_id($orderNo) {
    $arr = packeta_cache_load();
    return $arr[$orderNo] ?? null;
}

function packeta_get_tracking_xml($packetId) {
    $xmlBody =
'<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
'<packetTracking>' . "\n" .
'  <apiPassword>' . htmlspecialchars(PACKETA_API_PASSWORD, ENT_QUOTES, 'UTF-8') . '</apiPassword>' . "\n" .
'  <packetId>'    . htmlspecialchars($packetId, ENT_QUOTES, 'UTF-8')        . '</packetId>' . "\n" .
'</packetTracking>';

    list($status, $body) = http_post_raw(
        PACKETA_API_URL,
        $xmlBody,
        [
            'Content-Type'    => 'text/xml; charset=utf-8',
            'Accept'          => 'application/xml',
            'Accept-Language' => 'cs-CZ',
        ]
    );

    if ($status >= 400) {
        throw new Exception('Zásilkovna packetTracking – HTTP ' . $status . ', odpověď: ' . substr($body, 0, 400));
    }

    $xml = @simplexml_load_string($body);
    if ($xml === false) {
        throw new Exception('Zásilkovna packetTracking – nelze parsovat XML, odpověď: ' . substr($body, 0, 400));
    }

    $statusNode = strtolower(trim((string)($xml->status ?? '')));
    if ($statusNode === 'fault') {
        $msg   = (string)($xml->string ?? '');
        $detail= (string)($xml->detail ?? '');
        throw new Exception('Zásilkovna packetTracking – fault: ' . trim($msg . ' ' . $detail));
    }

    return $xml;
}

function packeta_extract_best_event(SimpleXMLElement $xml): array {
    // pokusíme se vytáhnout seznam záznamů: element, který obsahuje statusCode + date/dateTime
    $records = [];

    $walk = function($node) use (&$walk, &$records) {
        if (!($node instanceof SimpleXMLElement)) return;

        // má node statusCode?
        $hasStatus = isset($node->statusCode) && trim((string)$node->statusCode) !== '';
        $dateStr = '';
        foreach (['dateTime','datetime','date','statusDate','created','time'] as $k) {
            if (isset($node->{$k}) && trim((string)$node->{$k}) !== '') {
                $dateStr = trim((string)$node->{$k});
                break;
            }
        }

        if ($hasStatus && $dateStr !== '') {
            $records[] = [
                'statusCode' => trim((string)$node->statusCode),
                'statusText' => trim((string)($node->statusText ?? $node->codeText ?? $node->text ?? $node->status ?? '')),
                'date'       => $dateStr,
            ];
        }

        foreach ($node->children() as $ch) {
            $walk($ch);
        }
    };

    $walk($xml);

    // pokud nic, zkusíme najít alespoň poslední statusCode a nějaké datum v dokumentu
    if (!$records) {
        $codes = $xml->xpath('.//statusCode');
        $dates = $xml->xpath('.//date | .//dateTime | .//statusDate');
        $code = ($codes && isset($codes[0])) ? trim((string)$codes[0]) : '';
        $date = ($dates && isset($dates[0])) ? trim((string)$dates[0]) : '';
        return ['statusCode'=>$code, 'statusText'=>'', 'date'=>$date];
    }

    // vybereme nejnovější dle data (pokud jde parsovat)
    $best = $records[0];
    $bestTs = strtotime($best['date']) ?: 0;

    foreach ($records as $r) {
        $ts = strtotime($r['date']) ?: 0;
        if ($ts >= $bestTs) {
            $bestTs = $ts;
            $best = $r;
        }
    }

    // pokud existuje záznam statusCode=7 (vyzvednuto), preferujeme jej
    foreach ($records as $r) {
        if (trim($r['statusCode']) === '7') {
            return $r + ['isPickedUp' => true];
        }
    }

    return $best;
}

// ======================================================
// ČESKÁ POŠTA – tracking (parcelStatus)
// ======================================================
const POSTA_API_BASE   = 'https://b2b.postaonline.cz:444/restservices/ZSKService/v1';
define('POSTA_API_TOKEN', $__cfloatShipping['posta_api_token'] ?? '');
define('POSTA_PRIVATE_KEY', $__cfloatShipping['posta_private_key'] ?? '');
const POSTA_CACHE_FILE = __DIR__ . '/posta_cache.json';

function posta_uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function posta_build_auth_headers(string $jsonBody, int $timestamp, string $nonce): array {
    // dle Postman kolekce: HMAC( SHA256(payload) + ';' + timestamp + ';' + nonce, secret )
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
    $url = POSTA_API_BASE . $path;

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new Exception('Česká pošta: json_encode selhal.');
    }

    $timestamp = time();
    $nonce = posta_uuidv4();
    $headers = posta_build_auth_headers($json, $timestamp, $nonce);

    list($status, $body) = http_post_raw($url, $json, $headers);

    if ($status < 200 || $status >= 300) {
        throw new Exception("Česká pošta: HTTP {$status}. Odpověď: {$body}");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new Exception('Česká pošta: neplatná JSON odpověď: ' . substr($body, 0, 500));
    }
    return $data;
}

function posta_cache_load(): array {
    if (!file_exists(POSTA_CACHE_FILE)) return [];
    $json = @file_get_contents(POSTA_CACHE_FILE);
    if ($json === false || $json === '') return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}
function posta_cache_get_parcel_code(string $orderNo): string {
    $arr = posta_cache_load();
    return (string)($arr[$orderNo] ?? '');
}

function posta_extract_all_statuses(array $resp, string $idParcel): array {
    // /parcelStatus může vracet více záznamů i v různých strukturách. Sesbíráme všechny status objekty.
    $raw = [];

    $walk = function($node) use (&$walk, &$raw, $idParcel) {
        if (!is_array($node)) return;

        // wrapper: {idParcel, parcelStatus:{...}}
        if (isset($node['parcelStatus']) && is_array($node['parcelStatus'])) {
            $id = (string)($node['idParcel'] ?? $node['parcelId'] ?? $node['parcelNumber'] ?? '');
            if ($id === '' || $id === $idParcel) {
                $raw[] = $node['parcelStatus'];
            }
        }

        // status přímo bez wrapperu (některé odpovědi)
        if (isset($node['date']) && isset($node['statusDescription'])) {
            $id = (string)($node['idParcel'] ?? $node['parcelId'] ?? $node['parcelNumber'] ?? '');
            if ($id === '' || $id === $idParcel) {
                $raw[] = $node;
            }
        }

        foreach ($node as $v) {
            $walk($v);
        }
    };

    $walk($resp);

    // normalize + dedupe
    $out = [];
    $seen = [];
    foreach ($raw as $st) {
        if (!is_array($st)) continue;

        // normalize keys
        if (!isset($st['statusID']) && isset($st['statusId'])) $st['statusID'] = $st['statusId'];
        if (!isset($st['reasonID']) && isset($st['reasonId'])) $st['reasonID'] = $st['reasonId'];
        if (!isset($st['statusDescription']) && isset($st['status_description'])) $st['statusDescription'] = $st['status_description'];

        $date = trim((string)($st['date'] ?? ''));
        $desc = trim((string)($st['statusDescription'] ?? ''));
        if ($date === '' && $desc === '') continue;

        $key = trim((string)($st['statusID'] ?? '')) . '|' . $date . '|' . $desc;
        if (isset($seen[$key])) continue;
        $seen[$key] = 1;

        $out[] = $st;
    }

    return $out;
}


function posta_extract_current_status(array $resp, string $idParcel): array {
    // fallback – původní chování: vezme první nalezený parcelStatus
    $found = null;

    $walk = function($node) use (&$walk, &$found, $idParcel) {
        if ($found !== null) return;

        if (is_array($node)) {
            if (isset($node['idParcel']) && (string)$node['idParcel'] === $idParcel) {
                if (isset($node['parcelStatus']) && is_array($node['parcelStatus'])) {
                    $found = $node['parcelStatus'];
                    return;
                }
            }
            foreach ($node as $v) {
                $walk($v);
                if ($found !== null) return;
            }
        }
    };

    $walk($resp);

    if (is_array($found)) {
        return $found;
    }

    // fallback: najít první parcelStatus v odpovědi
    $found2 = null;
    $walk2 = function($node) use (&$walk2, &$found2) {
        if ($found2 !== null) return;
        if (is_array($node)) {
            if (isset($node['parcelStatus']) && is_array($node['parcelStatus']) && isset($node['parcelStatus']['date'])) {
                $found2 = $node['parcelStatus'];
                return;
            }
            foreach ($node as $v) {
                $walk2($v);
                if ($found2 !== null) return;
            }
        }
    };
    $walk2($resp);
    return is_array($found2) ? $found2 : [];
}

function posta_status_ts(array $st): int {
    $d = trim((string)($st['date'] ?? ''));
    if ($d === '') return 0;
    $iso = any_date_to_iso($d);
    $ts = strtotime($iso);
    return $ts ? (int)$ts : 0;
}

function posta_is_picked_up(string $descLower): bool {
    return (mb_strpos($descLower, 'vyzved') !== false) || (mb_strpos($descLower, 'vydán') !== false) || (mb_strpos($descLower, 'vydan') !== false);
}

function posta_is_delivered(string $descLower): bool {
    // doručení / předání / dodání
    if (mb_strpos($descLower, 'doručen') !== false || mb_strpos($descLower, 'dorucen') !== false) return true;
    if (mb_strpos($descLower, 'předán') !== false || mb_strpos($descLower, 'predan') !== false) return true;
    if (mb_strpos($descLower, 'dodán') !== false || mb_strpos($descLower, 'dodan') !== false || mb_strpos($descLower, 'dodán') !== false) return true;
    // někdy bývá formulace "zásilka dodána"
    if (mb_strpos($descLower, 'dodán') !== false || mb_strpos($descLower, 'dodana') !== false || mb_strpos($descLower, 'dodá') !== false) return true;
    return false;
}

function posta_pick_latest_by_pred(array $statuses, callable $pred): array {
    $best = [];
    $bestTs = -1;
    foreach ($statuses as $st) {
        if (!is_array($st)) continue;
        $desc = trim((string)($st['statusDescription'] ?? ''));
        $descLower = mb_strtolower($desc, 'UTF-8');
        if (!$pred($descLower)) continue;
        $ts = posta_status_ts($st);
        if ($ts >= $bestTs) {
            $bestTs = $ts;
            $best = $st;
        }
    }
    return $best;
}

function posta_pick_latest_any(array $statuses): array {
    $best = [];
    $bestTs = -1;
    foreach ($statuses as $st) {
        if (!is_array($st)) continue;
        $ts = posta_status_ts($st);
        if ($ts >= $bestTs) {
            $bestTs = $ts;
            $best = $st;
        }
    }
    return $best;
}


// ======================================================
// GLS (MyGLS) – tracking (GetParcelStatuses)
// ======================================================
const GLS_USERNAME       = 'obchod@c-store.cz';
define('GLS_PASSWORD', $__cfloatShipping['gls_password'] ?? '');
const GLS_TRACK_API_URL  = 'https://api.mygls.cz/ParcelService.svc/json/GetParcelStatuses';

function gls_password_to_byte_array($password) {
    $hashBin = hash('sha512', $password, true);
    $arr = unpack('C*', $hashBin);
    return array_values($arr);
}

function gls_get_parcel_statuses($parcelNumber) {
    $pn = preg_replace('/\D+/', '', (string)$parcelNumber);
    if ($pn === '') {
        throw new Exception('GLS: Neplatné číslo zásilky.');
    }

    $payload = [
        'Username'       => GLS_USERNAME,
        'Password'       => gls_password_to_byte_array(GLS_PASSWORD),
        'ParcelNumber'   => (int)$pn,
        'ReturnPOD'      => false,
        'LanguageIsoCode'=> 'CS',
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new Exception('GLS: json_encode selhal.');
    }

    list($status, $body) = http_post_raw(GLS_TRACK_API_URL, $json, [
        'Content-Type' => 'application/json; charset=utf-8',
        'Accept'       => 'application/json',
    ]);

    if ($status < 200 || $status >= 300) {
        throw new Exception('GLS: HTTP ' . $status . '. Odpověď: ' . substr($body, 0, 400));
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new Exception('GLS: Neplatná JSON odpověď: ' . substr($body, 0, 300));
    }

    $errs = $data['GetParcelStatusErrors'] ?? $data['getParcelStatusErrors'] ?? [];
    if (is_array($errs) && !empty($errs)) {
        $e0 = $errs[0] ?? null;
        if (is_array($e0)) {
            $num = $e0['ErrorNumber'] ?? $e0['errorNumber'] ?? '';
            $desc= $e0['ErrorDescription'] ?? $e0['errorDescription'] ?? '';
            $msg = trim((string)$num . ' ' . (string)$desc);
            throw new Exception('GLS: ' . ($msg !== '' ? $msg : 'chyba služby'));
        }
        throw new Exception('GLS: chyba služby.');
    }

    $list = $data['ParcelStatusList'] ?? $data['parcelStatusList'] ?? [];
    return is_array($list) ? $list : [];
}

function gls_norm_status($s) {
    if (!is_array($s)) return ['code'=>'','desc'=>'','iso'=>''];
    $code = trim((string)($s['StatusCode'] ?? $s['statusCode'] ?? ''));
    $desc = trim((string)($s['StatusDescription'] ?? $s['statusDescription'] ?? ''));
    $dateRaw = $s['StatusDate'] ?? $s['statusDate'] ?? '';
    $iso = any_date_to_iso($dateRaw);
    return ['code'=>$code, 'desc'=>$desc, 'iso'=>$iso];
}

function gls_pick_latest_status($list) {
    $best = null;
    $bestTs = 0;
    foreach ((array)$list as $s) {
        $a = gls_norm_status($s);
        $ts = $a['iso'] ? (strtotime($a['iso']) ?: 0) : 0;
        if ($best === null || $ts >= $bestTs) {
            $best = $a;
            $bestTs = $ts;
        }
    }
    return $best ?: ['code'=>'','desc'=>'','iso'=>''];
}

function gls_pick_delivered_status($list) {
    // dle MyGLS dokumentace (Appendix G) – typicky: 5=delivered, 54/55=box/ParcelShop, 58=neighbor, 92=delivered, 93=signature
    $deliveredCodes = ['5','54','55','58','92','93'];
    $best = null;
    $bestTs = 0;
    foreach ((array)$list as $s) {
        $a = gls_norm_status($s);
        if ($a['code'] === '' || !in_array($a['code'], $deliveredCodes, true)) continue;
        $ts = $a['iso'] ? (strtotime($a['iso']) ?: 0) : 0;
        if ($best === null || $ts >= $bestTs) {
            $best = $a;
            $bestTs = $ts;
        }
    }
    return $best ?: ['code'=>'','desc'=>'','iso'=>''];
}

// ======================================================
// MAIN
// ======================================================
$orderNo = normalize_order_no($_GET['order'] ?? '');
if ($orderNo === '') {
    out_error('Chybí číslo objednávky.');
}

require_once __DIR__ . '/config.php';
if (!isset($pdo)) {
    out_error('Chybí DB připojení ($pdo).');
}

// načíst objednávku (jméno + doprava)
try {
    $stmt = $pdo->prepare("SELECT number, customer_name, delivery_name FROM orders WHERE number = :n LIMIT 1");
    $stmt->execute([':n' => $orderNo]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$o) {
        out_error('Objednávka nebyla nalezena.');
    }
} catch (Throwable $e) {
    out_error('Chyba DB: ' . $e->getMessage());
}

$customer = trim((string)($o['customer_name'] ?? ''));
$shipping = trim((string)($o['delivery_name'] ?? ''));
$shipLower = mb_strtolower($shipping, 'UTF-8');

// nejdřív zkusíme log (má nejpřesnější dopravce/tracking)
$logRow = find_latest_log_row_by_order($orderNo);
$carrier = trim((string)($logRow['Dopravce'] ?? ''));
$tracking = trim((string)($logRow['Tracking'] ?? ''));
$service = trim((string)($logRow['Služba'] ?? ''));

// fallback dopravce dle názvu dopravy
if ($carrier === '') {
    if (mb_strpos($shipLower, 'zásilkovna') !== false || mb_strpos($shipLower, 'zasilkovna') !== false || mb_strpos($shipLower, 'packeta') !== false) {
        $carrier = 'Zásilkovna';
    } elseif (mb_strpos($shipLower, 'gls') !== false) {
        $carrier = 'GLS';
    } elseif (mb_strpos($shipLower, 'balíkovna') !== false || mb_strpos($shipLower, 'balikovna') !== false || mb_strpos($shipLower, 'česká pošta') !== false || mb_strpos($shipLower, 'ceska posta') !== false || mb_strpos($shipLower, 'pošta') !== false || mb_strpos($shipLower, 'posta') !== false) {
        $carrier = 'Balíkovna';
    }
}

try {
    // ZÁSILKOVNA
    if ($carrier === 'Zásilkovna') {
        $packetId = $tracking !== '' ? $tracking : (string)packeta_cache_get_packet_id($orderNo);
        if ($packetId === '') {
            out_error('U této objednávky nemám dohledatelné číslo zásilky (packetId). Zkontroluj, že byl štítek vytištěn.');
        }

        $xml = packeta_get_tracking_xml($packetId);
        $evt = packeta_extract_best_event($xml);

        $statusCode = trim((string)($evt['statusCode'] ?? ''));
        $statusText = trim((string)($evt['statusText'] ?? ''));
        $date = trim((string)($evt['date'] ?? ''));

        $eventLabel = '';
        if ($statusCode === '7') {
            $eventLabel = 'Vyzvednuto zákazníkem';
        } else {
            $eventLabel = 'Poslední stav';
        }

        out_json([
            'ok' => true,
            'order' => $orderNo,
            'customer' => $customer,
            'carrier' => $carrier,
            'service' => $service,
            'tracking' => $packetId,
            'event' => $eventLabel,
            'status_code' => $statusCode,
            'status_text' => $statusText,
            'datetime' => $date,
            'datetime_human' => format_dt_human($date),
            'picked_up_datetime' => ($eventLabel !== 'Poslední stav') ? any_date_to_iso($date) : '',
            'picked_up_human' => ($eventLabel !== 'Poslední stav') ? format_dt_human_dateonly(any_date_to_iso($date)) : '',
        ]);
    }

    // ČESKÁ POŠTA / BALÍKOVNA
    if ($carrier === 'Balíkovna' || $carrier === 'Česká pošta' || $carrier === 'Pošta') {
        $parcelCode = $tracking !== '' ? $tracking : posta_cache_get_parcel_code($orderNo);
        if ($parcelCode === '') {
            out_error('U této objednávky nemám dohledatelné číslo zásilky (tracking). Zkontroluj, že byl štítek vytištěn.');
        }

        $resp = posta_post_json('/parcelStatus', [
            'parcelIds' => [$parcelCode],
            'language'  => 'cze',
        ]);

        // /parcelStatus vrací více záznamů (neagregované stavy) – sesbíráme je a vybereme poslední relevantní.
        $statuses = posta_extract_all_statuses($resp, $parcelCode);

        // fallback: když se nepodaří sesbírat všechny stavy, vezmeme aspoň jeden (původní logika)
        if (!$statuses) {
            $one = posta_extract_current_status($resp, $parcelCode);
            if (is_array($one) && $one) {
                $statuses = [$one];
            }
        }

        if (!$statuses) {
            $stOne = posta_extract_current_status($resp, $parcelCode);
            if (is_array($stOne) && !empty($stOne)) {
                $statuses = [$stOne];
            }
        }

        // Preferujeme skutečné vyzvednutí zákazníkem; když není, tak doručení/předání; jinak jen poslední stav.
        $stPicked = posta_pick_latest_by_pred($statuses, 'posta_is_picked_up');
        $stDeliv  = $stPicked ? [] : posta_pick_latest_by_pred($statuses, 'posta_is_delivered');
        $stShow   = $stPicked ?: ($stDeliv ?: posta_pick_latest_any($statuses));

        $date = trim((string)($stShow['date'] ?? ''));
        $desc = trim((string)($stShow['statusDescription'] ?? ''));
        $statusId = (string)($stShow['statusID'] ?? '');
        $reasonId = (string)($stShow['reasonID'] ?? '');

        $descLower = mb_strtolower($desc, 'UTF-8');

        $eventLabel = 'Poslední stav';
        if ($stPicked) {
            $eventLabel = 'Vyzvednuto zákazníkem';
        } elseif ($stDeliv) {
            $eventLabel = 'Doručeno';
        }

        $pickedIso = '';
        if ($stPicked) {
            $pickedIso = any_date_to_iso(trim((string)($stPicked['date'] ?? '')));
        } elseif ($stDeliv) {
            $pickedIso = any_date_to_iso(trim((string)($stDeliv['date'] ?? '')));
        }

        // fallback: když nemáme jasné vyzvednuto/doručeno, vezmeme aspoň datum posledního stavu
        if ($pickedIso === '' && $date !== '') {
            $pickedIso = any_date_to_iso($date);
        }

        out_json([
            'ok' => true,
            'order' => $orderNo,
            'customer' => $customer,
            'carrier' => $carrier,
            'service' => $service,
            'tracking' => $parcelCode,
            'event' => $eventLabel,
            'status_code' => $statusId . ($reasonId !== '' ? '/' . $reasonId : ''),
            'status_text' => $desc,
            'datetime' => $date,
            'datetime_human' => format_dt_human($date),
            'picked_up_datetime' => $pickedIso,
            'picked_up_human' => $pickedIso ? format_dt_human_dateonly($pickedIso) : '',
        ]);
    }

    // GLS
    if ($carrier === 'GLS') {
        $pn = $tracking;
        if ($pn === '') {
            out_error('U této objednávky nemám dohledatelné číslo zásilky (tracking). Zkontroluj, že byl štítek vytištěn.');
        }

        $list = gls_get_parcel_statuses($pn);
        $last = gls_pick_latest_status($list);
        $del  = gls_pick_delivered_status($list);

        $lastIso = trim((string)($last['iso'] ?? ''));
        $lastDesc = trim((string)($last['desc'] ?? ''));
        $lastCode = trim((string)($last['code'] ?? ''));

        $pickedIso = trim((string)($del['iso'] ?? ''));

        out_json([
            'ok' => true,
            'order' => $orderNo,
            'customer' => $customer,
            'carrier' => $carrier,
            'service' => $service,
            'tracking' => $pn,
            'event' => 'Poslední stav',
            'status_code' => $lastCode,
            'status_text' => $lastDesc,
            'datetime' => $lastIso,
            'datetime_human' => format_dt_human($lastIso),
            'picked_up_datetime' => $pickedIso,
            'picked_up_human' => $pickedIso ? format_dt_human_dateonly($pickedIso) : '',
        ]);
    }


    out_error('U objednávky se nepodařilo rozpoznat dopravce.');
} catch (Throwable $e) {
    out_error($e->getMessage());
}
