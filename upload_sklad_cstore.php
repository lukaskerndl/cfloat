<?php
// Ruční nahrání skladu C-Store a vytvoření spojeného CSV se skladem.
session_start();
const UC_SKLAD_UPLOAD_VERSION = '2026-06-09-FG-REPLACE-OLD';
const UC_EAN_COL_INDEX = 5;   // F = EAN kód
const UC_SKLAD_COL_INDEX = 6; // G = Skladem
if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    echo 'Nepřihlášeno.';
    exit;
}

function uc_redirect(array $params): void {
    $base = 'index.php?view=xmlfeedy';
    if ($params) $base .= '&' . http_build_query($params);
    header('Location: ' . $base);
    exit;
}

function uc_safe_filename(string $name): string {
    $name = basename($name);
    $name = preg_replace('~[^A-Za-z0-9._-]+~u', '_', $name);
    $name = trim((string)$name, '._-');
    return $name !== '' ? $name : 'sklad_cstore.csv';
}

function uc_ensure_dir(string $dir): bool {
    return is_dir($dir) || @mkdir($dir, 0775, true);
}

function uc_detect_delim_from_file(string $file): string {
    $sample = @file_get_contents($file, false, null, 0, 8192);
    if ($sample === false) return ';';
    $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);
    $cands = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t"), '|' => substr_count($sample, '|')];
    arsort($cands);
    $delim = array_key_first($cands);
    return $delim ?: ';';
}

function uc_norm_lower(string $s): string {
    if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
    return strtolower($s);
}

function uc_parse_g_value(string $raw): ?int {
    $raw = trim($raw);
    if ($raw === '') return null;
    $raw = str_replace(["\xC2\xA0", ' '], '', $raw);
    $raw = str_replace(',', '.', $raw);

    if (is_numeric($raw)) {
        $float = (float)$raw;
        $int = (int)$float;
        if (abs($float - $int) < 0.000001) return $int;
        return null;
    }

    // fallback pro hodnoty typu "1003 ks" apod.
    if (preg_match('~-?\d+~', $raw, $m)) return (int)$m[0];
    return null;
}

function uc_g_allowed(int $g): bool {
    return ($g >= 1 && $g <= 25) || ($g >= 1001 && $g <= 1025);
}

function uc_open_csv_writer(string $file, array $header): SplFileObject {
    $f = new SplFileObject($file, 'w');
    $f->fwrite("\xEF\xBB\xBF");
    $f->fputcsv($header, ';');
    return $f;
}

function uc_find_existing_file(string $dirAbs, array $names): ?string {
    foreach ($names as $name) {
        $p = rtrim($dirAbs, '/\\') . '/' . $name;
        if (is_file($p)) return $p;
    }
    if (!is_dir($dirAbs)) return null;
    $list = @scandir($dirAbs) ?: [];
    foreach ($names as $name) {
        foreach ($list as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (strcasecmp($entry, $name) === 0 && is_file($dirAbs . '/' . $entry)) {
                return rtrim($dirAbs, '/\\') . '/' . $entry;
            }
        }
    }
    return null;
}


function uc_http_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') return '';

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = trim(dirname($scriptName), '/.');
    return $scheme . '://' . $host . ($dir !== '' ? '/' . $dir : '') . '/';
}

function uc_loopback_request(string $script, string $method = 'GET', array $query = [], array $post = [], int $timeout = 1800): array {
    $base = uc_http_base_url();
    if ($base === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'Nelze zjistit URL webu.', 'body' => ''];
    }

    $url = $base . ltrim($script, '/');
    if ($query) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $cookie = '';
    if (session_id() !== '') {
        $cookie = session_name() . '=' . session_id();
    }

    $body = '';
    $code = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($cookie !== '') curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post ?: ['auto' => '1']);
        }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) $error = curl_error($ch);
        curl_close($ch);
    } else {
        $headers = [];
        if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
        $contextOptions = [
            'http' => [
                'method' => strtoupper($method),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ];
        if (strtoupper($method) === 'POST') {
            $contextOptions['http']['header'] .= ($contextOptions['http']['header'] !== '' ? "\r\n" : '') . 'Content-Type: application/x-www-form-urlencoded';
            $contextOptions['http']['content'] = http_build_query($post ?: ['auto' => '1']);
        }
        $ctx = stream_context_create($contextOptions);
        $bodyRaw = @file_get_contents($url, false, $ctx);
        $body = $bodyRaw === false ? '' : (string)$bodyRaw;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('~^HTTP/\S+\s+(\d+)~', $hdr, $m)) { $code = (int)$m[1]; break; }
            }
        }
        if ($bodyRaw === false) $error = 'HTTP požadavek se nepodařil.';
    }

    $ok = ($error === '' && $code >= 200 && $code < 400);
    return [
        'ok' => $ok,
        'code' => $code,
        'error' => $error,
        'body' => substr($body, 0, 1200),
        'url' => $url,
    ];
}

function uc_run_auto_after_sklad_upload(): array {
    // Důležité: uvolní session lock, aby interní POST požadavky se stejným PHPSESSID nečekaly samy na sebe.
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }

    $steps = [];

    $steps['feeds'] = uc_loopback_request('run_feeds.php', 'GET', ['ajax' => '1', 'auto' => '1']);
    if (empty($steps['feeds']['ok'])) {
        throw new RuntimeException('Načtení dodavatelských XML feedů selhalo: HTTP ' . (string)($steps['feeds']['code'] ?? 0) . ' ' . (string)($steps['feeds']['error'] ?? ''));
    }

    $steps['process'] = uc_loopback_request('process_all_suppliers.php', 'POST', [], ['auto' => '1']);
    if (empty($steps['process']['ok'])) {
        throw new RuntimeException('Zpracování všech dodavatelů selhalo: HTTP ' . (string)($steps['process']['code'] ?? 0) . ' ' . (string)($steps['process']['error'] ?? ''));
    }

    $steps['changes'] = uc_loopback_request('generate_all_supplier_changes.php', 'POST', [], ['auto' => '1']);
    if (empty($steps['changes']['ok'])) {
        throw new RuntimeException('Generování změnových souborů selhalo: HTTP ' . (string)($steps['changes']['code'] ?? 0) . ' ' . (string)($steps['changes']['error'] ?? ''));
    }

    return $steps;
}


function uc_unlink_if_file(string $file): void {
    if (is_file($file)) @unlink($file);
}

function uc_copy_if_file(string $src, string $dst): void {
    if (is_file($src)) {
        @copy($src, $dst);
        @touch($dst, (int)(@filemtime($src) ?: time()));
    }
}

function uc_clean_ean(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    return preg_replace('~\D+~', '', $raw) ?: '';
}

function uc_valid_ean_like(string $ean): bool {
    // EAN z e-shopu je číselný. Tohle zároveň zabrání tomu, aby se omylem propsal kód produktu ze sloupce E.
    return (bool)preg_match('~^\d{8,14}$~', $ean);
}

function uc_source_is_cstore(string $src): bool {
    $s = uc_norm_lower(trim($src));
    if ($s === '') return false;
    return (strpos($s, 'cstore') !== false)
        || (strpos($s, 'c-store') !== false)
        || (strpos($s, 'c store') !== false)
        || (strpos($s, 'sklad c-store') !== false)
        || (strpos($s, 'sklad_cstore') !== false)
        || (strpos($s, 'cstore_sklad') !== false)
        || (strpos($s, 'c-store sklad') !== false)
        || (strpos($s, 'sklad') !== false && (strpos($s, 'store') !== false || strpos($s, 'c-') !== false));
}


function uc_source_is_sportimport(string $src): bool {
    $s = uc_norm_lower(trim($src));
    $s = str_replace([' ', '-', '_'], '', $s);
    return $s === 'sportimport';
}

function uc_append_update_csv_rows(SplFileObject $fout, string $sourceName, string $updateCsv, string $feedDate, int &$written): void {
    if (!is_file($updateCsv)) return;
    $delim = uc_detect_delim_from_file($updateCsv);
    $fh = @fopen($updateCsv, 'rb');
    if (!$fh) return;

    $lineNo = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $lineNo++;
        if (!is_array($row) || count($row) < 1) continue;
        $ean = uc_clean_ean((string)($row[0] ?? ''));
        $val = trim((string)($row[1] ?? '0'));

        if ($lineNo === 1) {
            $probe = uc_norm_lower(trim(implode(' ', $row)));
            if (strpos($probe, 'ean') !== false || strpos($probe, 'dostup') !== false) continue;
        }
        if ($ean === '' || strtolower($ean) === 'ean') continue;
        if ($val === '') $val = '0';
        $fout->fputcsv([$sourceName, $ean, $val, $feedDate], ';');
        $written++;
    }
    fclose($fh);
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uc_redirect(['sklad_upload' => 'err', 'msg' => 'Soubor nebyl odeslán.']);
}

if (empty($_FILES['sklad_csv']) || !is_array($_FILES['sklad_csv'])) {
    uc_redirect(['sklad_upload' => 'err', 'msg' => 'Vyber CSV soubor.']);
}

$file = $_FILES['sklad_csv'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    uc_redirect(['sklad_upload' => 'err', 'msg' => 'Upload selhal, kód chyby: ' . (string)($file['error'] ?? '')]);
}

$tmpName = (string)($file['tmp_name'] ?? '');
$origName = uc_safe_filename((string)($file['name'] ?? 'sklad_cstore.csv'));
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    uc_redirect(['sklad_upload' => 'err', 'msg' => 'Dočasný soubor uploadu není dostupný.']);
}

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
if ($docRoot === '') $docRoot = realpath(__DIR__) ?: __DIR__;

$skladRelDir = 'Dodavatele/SKLAD C-STORE';
$skladAbsDir = $docRoot . '/' . $skladRelDir;
if (!uc_ensure_dir($skladAbsDir)) {
    uc_redirect(['sklad_upload' => 'err', 'msg' => 'Nelze vytvořit složku skladu.']);
}

$stamp = date('Ymd_His');
$uploadedAbs = $skladAbsDir . '/' . $stamp . '_' . $origName;
$celyAbs     = $skladAbsDir . '/celySkladExport.csv';
$aktualniAbs = $skladAbsDir . '/AktualniExportSkladu.csv';
$updAbs      = $skladAbsDir . '/AktualizacniSkladCSTORE.csv';

$mergedRelDir = 'VSECHNY SPOJENE XML FEED';
$mergedAbsDir = $docRoot . '/' . $mergedRelDir;
if (!uc_ensure_dir($mergedAbsDir)) {
    uc_redirect(['sklad_upload' => 'err', 'msg' => 'Nelze vytvořit složku pro spojený soubor.']);
}

// Před novým uploadem smažeme všechny staré skladové výstupy, včetně starších názvů s jinou velikostí písmen.
// Tím je jisté, že po nahrání zůstanou jen data z aktuálně nahraného CSV.
foreach ([
    $skladAbsDir . '/celySkladExport.csv',
    $skladAbsDir . '/celyskladexport.csv',
    $skladAbsDir . '/AktualniExportSkladu.csv',
    $skladAbsDir . '/aktualniexportskladu.csv',
    $skladAbsDir . '/AktualizacniSkladCSTORE.csv',
    $skladAbsDir . '/aktualizacniskladcstore.csv',
    $mergedAbsDir . '/SpojeneFeedy_CSTORE_SKLAD.csv',
    $mergedAbsDir . '/spojenefeedy_cstore_sklad.csv',
    $mergedAbsDir . '/ALL_XML_CSTORE_SKLAD.csv',
    $mergedAbsDir . '/all_xml_cstore_sklad.csv',
] as $oldStockFile) {
    uc_unlink_if_file($oldStockFile);
}

if (!@move_uploaded_file($tmpName, $uploadedAbs)) {
    uc_redirect(['sklad_upload' => 'err', 'msg' => 'Soubor se nepodařilo uložit.']);
}
@copy($uploadedAbs, $celyAbs);
@touch($celyAbs);
uc_copy_if_file($celyAbs, $skladAbsDir . '/celyskladexport.csv');

$delim = uc_detect_delim_from_file($uploadedAbs);
$fh = @fopen($uploadedAbs, 'rb');
if (!$fh) {
    uc_redirect(['sklad_upload' => 'err', 'msg' => 'Nahraný CSV soubor nejde otevřít.']);
}

$outAktualni = uc_open_csv_writer($aktualniAbs, ['EAN','Sklad_G']);
$outUpd      = uc_open_csv_writer($updAbs, ['EAN','Dostupnost']);

$totalRows = 0;
$acceptedRows = 0;
$skippedRows = 0;
$cstoreRows = [];

while (($row = fgetcsv($fh, 0, $delim)) !== false) {
    $totalRows++;
    if (!is_array($row) || count($row) < 7) { $skippedRows++; continue; }

    // PEVNĚ: sloupec F = EAN kód (index 5), sloupec G = Skladem (index 6).
    // Sloupec E se tady vůbec nepoužívá.
    $ean = uc_clean_ean((string)($row[UC_EAN_COL_INDEX] ?? ''));
    $gRaw = trim((string)($row[UC_SKLAD_COL_INDEX] ?? ''));

    if ($totalRows === 1) {
        $probe = uc_norm_lower($ean . ' ' . $gRaw);
        if (strpos($probe, 'ean') !== false || strpos($probe, 'sklad') !== false || strpos($probe, 'stock') !== false) {
            $skippedRows++;
            continue;
        }
    }

    if ($ean === '' || !uc_valid_ean_like($ean)) { $skippedRows++; continue; }
    $g = uc_parse_g_value($gRaw);
    if ($g === null || !uc_g_allowed($g)) { $skippedRows++; continue; }

    // Jeden EAN stačí jednou, aby se ve spojeném souboru neduplikoval.
    if (!isset($cstoreRows[$ean])) {
        $cstoreRows[$ean] = $g;
        $outAktualni->fputcsv([$ean, (string)$g], ';');
        $outUpd->fputcsv([$ean, '0'], ';');
        $acceptedRows++;
    }
}
fclose($fh);
@touch($aktualniAbs);
@touch($updAbs);

$baseMerged = uc_find_existing_file($mergedAbsDir, ['SpojeneFeedy.csv', 'spojenefeedy.csv', 'ALL_XML.csv', 'all_xml.csv']);
$outSpojene = $mergedAbsDir . '/SpojeneFeedy_CSTORE_SKLAD.csv';
$outAllXml  = $mergedAbsDir . '/ALL_XML_CSTORE_SKLAD.csv';

$writeMerged = function(string $target) use ($baseMerged, $cstoreRows, $docRoot): int {
    $fout = uc_open_csv_writer($target, ['Zdroj','EAN','Dostupnost','Aktualizováno']);
    $written = 0;
    $baseDate = $baseMerged && is_file($baseMerged) ? date('Y-m-d H:i:s', (int)(@filemtime($baseMerged) ?: time())) : date('Y-m-d H:i:s');

    $sportUpd = uc_find_existing_file($docRoot . '/Dodavatele/SportImport', ['AktualizacniSportImport.csv', 'aktualizacnisportimport.csv']);
    $refreshSportImport = ($sportUpd !== null && is_file($sportUpd));

    if ($baseMerged && is_file($baseMerged)) {
        $baseDelim = uc_detect_delim_from_file($baseMerged);
        $fhBase = @fopen($baseMerged, 'rb');
        if ($fhBase) {
            $lineNo = 0;
            while (($row = fgetcsv($fhBase, 0, $baseDelim)) !== false) {
                $lineNo++;
                if (!is_array($row) || count($row) < 3) continue;
                if ($lineNo === 1) {
                    $first = uc_norm_lower(trim((string)($row[0] ?? '')));
                    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
                    if ($first === 'zdroj') continue;
                }
                $src = trim((string)($row[0] ?? ''));
                $ean = trim((string)($row[1] ?? ''));
                $val = trim((string)($row[2] ?? ''));
                $dt  = trim((string)($row[3] ?? ''));
                if ($src === '' || $ean === '') continue;
                if (uc_source_is_cstore($src)) continue;
                if ($refreshSportImport && uc_source_is_sportimport($src)) continue;
                if ($val === '') $val = '0';
                if ($dt === '') $dt = $baseDate;
                $fout->fputcsv([$src, $ean, $val, $dt], ';');
                $written++;
            }
            fclose($fhBase);
        }
    }

    if ($refreshSportImport && $sportUpd) {
        $sportDate = date('Y-m-d H:i:s', (int)(@filemtime($sportUpd) ?: time()));
        uc_append_update_csv_rows($fout, 'SportImport', $sportUpd, $sportDate, $written);
    }

    $uploadDate = date('Y-m-d H:i:s');
    foreach ($cstoreRows as $ean => $g) {
        // Do spojeného souboru zapisujeme Dostupnost=0, protože EAN je na skladě C-Store.
        // Hodnota ze sloupce G slouží jako filtr a je uložená v AktualniExportSkladu.csv.
        $fout->fputcsv(['CSTORE_SKLAD', (string)$ean, '0', $uploadDate], ';');
        $written++;
    }
    @touch($target);
    return $written;
};

$mergedRows = $writeMerged($outSpojene);
$writeMerged($outAllXml);
uc_copy_if_file($aktualniAbs, $skladAbsDir . '/aktualniexportskladu.csv');
uc_copy_if_file($updAbs, $skladAbsDir . '/aktualizacniskladcstore.csv');
uc_copy_if_file($outSpojene, $mergedAbsDir . '/spojenefeedy_cstore_sklad.csv');
uc_copy_if_file($outAllXml, $mergedAbsDir . '/all_xml_cstore_sklad.csv');

@file_put_contents($skladAbsDir . '/posledni_upload_info.json', json_encode([
    'uploaded_file' => basename($uploadedAbs),
    'source_file' => $origName,
    'uploaded_at' => date('c'),
    'rows_total' => $totalRows,
    'rows_accepted' => $acceptedRows,
    'rows_skipped' => $skippedRows,
    'merged_rows' => $mergedRows,
    'rule' => 'F + G, G pouze 1-25 nebo 1001-1025, sloupec E se nepoužívá, staré skladové výstupy se před uploadem mažou, ve spojeném souboru Dostupnost=0',
    'version' => UC_SKLAD_UPLOAD_VERSION,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$redirectParams = [
    'sklad_upload' => 'ok',
    'rows' => (string)$acceptedRows,
    'total' => (string)$totalRows,
    'merged' => (string)$mergedRows,
];

if (!empty($_POST['auto_all'])) {
    try {
        uc_run_auto_after_sklad_upload();
        $redirectParams['auto_all'] = 'ok';
    } catch (Throwable $e) {
        $redirectParams['auto_all'] = 'err';
        $redirectParams['msg'] = $e->getMessage();
    }
}

uc_redirect($redirectParams);
