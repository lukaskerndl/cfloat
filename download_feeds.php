<?php

/**
 * download_feeds.php
 *
 * Stahování + generování CSV + merge do ALL_XML.csv
 * - SportImport (HTTP)
 * - SILVINI     (HTTP)
 * - Vavrys      (HTTP, Zboží.cz feed – od 2026-08-31; dřív DB tabulka vavrys_variants,
 *                viz poznámka u definice feedu níže)
 * - DEVOLD      (FTP /feed-3/C.xml) + generuje devold.csv a AktualizacniDevold.csv
 *
 * DEVOLD pravidlo:
 *   bereme EAN + inStock; do aktualizačního bereme jen inStock >= 2 a zapisujeme Dostupnost = 0
 */

// POZOR: declare(strict_types=1) musí být úplně první příkaz souboru (smí mu předcházet
// jen komentáře). Dřív tu před ním byl require_once _cron_guard.php, což je fatální chyba
// parsování PHP ("strict_types declaration must be the very first statement in the script")
// – celý soubor se kvůli tomu vůbec nespustil. Opraveno přesunutím require_once až sem.
declare(strict_types=1);

// Spuštění jen s platným tokenem nebo z cronu (dřív bez jakékoliv ochrany).
require_once __DIR__ . '/_cron_guard.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

// streamování výstupu (kvůli průběhu)
@ini_set('output_buffering','off');
@ini_set('zlib.output_compression','0');
while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($isAjax) {
    header('Content-Type: text/plain; charset=utf-8');
}

// Vavrys feed (Zboží.cz export) má cca 80+ MB a ~40 000 položek – stažení + streamovaný
// XML parsing může na sdíleném hostingu snadno přesáhnout výchozí max_execution_time.
@set_time_limit(0);
@ini_set('max_execution_time', '0');

$connectTimeout  = 20;
$transferTimeout = 0; // 0 = bez limitu

// SportImport Basic Auth (pokud je potřeba) přes ENV:
$sportimportUser    = getenv('SPORTIMPORT_USER') ?: '';
$sportimportPass    = getenv('SPORTIMPORT_PASS') ?: '';
$sportimportBrandId = getenv('SPORTIMPORT_BRAND_ID') ?: '';

// FTP DEVOLD
const FTP_HOST = '185.61.87.47';
const FTP_USER = 'feed-3';
const FTP_PASS = '8vnhMDv4ej';

// FTP - C-Store sklad export (Eshop-rychle)
const SKLAD_FTP_HOST = 'ftp.1388739759.s1.eshop-rychle.cz';
const SKLAD_FTP_USER = '38463.s1.eshop-rychle.cz';
const SKLAD_FTP_PASS = 'Alea11';
const SKLAD_FTP_DIRS = ['/stock_export/automatic', '/stock_export/manual'];


$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

// DB (pro Vavrys z databáze)
$pdo = null;
if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$feeds = [
    [
        'name'       => 'SportImport',
        'type'       => 'http',
        'url'        => 'https://www.sportimport.cz/export/xml/catalogue',
        'dir'        => 'Dodavatele/SportImport',
        'xml'        => 'catalogue.xml',
        'csv_full'   => 'catalogue.csv',
        'csv_extra'  => 'AktualizacniSportImport.csv',
        'mode'       => 'sportimport',
    ],
    [
        'name'       => 'SILVINI',
        'type'       => 'http',
        'url'        => 'http://95.80.221.202:3380/STOCKREPORT_VIP_spOn2020.XML',
        'dir'        => 'Dodavatele/SILVINI',
        'xml'        => 'stockreport.xml',
        'csv_full'   => 'stockreport.csv',
        'csv_extra'  => 'AktualizacniSilvini.csv',
        'mode'       => 'silvini',
    ],
        [
        // 2026-08-31: přepnuto z 'db' (tabulka vavrys_variants) na živý HTTP feed pro Zboží.cz.
        // Důvod: DB varianta (vavrys_generate_from_db) zapisovala Dostupnost=0 pro KAŽDÝ EAN,
        // který se kdy objevil v tabulce vavrys_variants, bez ohledu na skutečnou skladovost –
        // produkty dávno vyřazené z katalogu Vavrys tak zůstávaly navěky "skladem". Tenhle feed
        // má formát <SHOPITEM><EAN>...</EAN><DELIVERY_DATE>0</DELIVERY_DATE>...</SHOPITEM>,
        // kde DELIVERY_DATE=0 znamená skladem/ihned k odeslání – přesně pro to je níže
        // (funkce parse_xml_stream, mode 'vavrys') už připravené (dřív nepoužívané) pravidlo.
        'name'       => 'Vavrys',
        'type'       => 'http',
        'url'        => 'https://b2b.vavrys.cz/Integration/GetFeedXml?provider=zbozicz',
        'dir'        => 'Dodavatele/Vavrys',
        'xml'        => 'vavrys.xml',
        'csv_full'   => 'vavrys.csv',
        'csv_extra'  => 'AktualizacniVavrys.csv',
        'mode'       => 'vavrys',
    ],
[
    'name'       => 'ISADORE',
    'type'       => 'http',
    'url'        => 'https://isadore-prod1.mibron.dev/feeds/partner-product-feed?countryCode=cz&languageCode=sk&currencyCode=czk',
    'dir'        => 'Dodavatele/ISADORE',
    'xml'        => 'isadore.xml',
    'csv_full'   => 'isadore.csv',
    'csv_extra'  => 'AktualizacniIsadore.csv',
    'mode'       => 'isadore',
],

    [
        'name'       => 'DEVOLD (FTP)',
        'type'       => 'ftp',
        'dir'        => 'Dodavatele/DEVOLD/XML',
        'xml'        => 'C.xml',
        'ftp_dir'    => '/feed-3',
        'ftp_file'   => 'C.xml',
        'csv_full'   => 'devold.csv',
        'csv_extra'  => 'AktualizacniDevold.csv',
        'mode'       => 'devold',
    ],
];
// výběr dodavatelů (checkboxy v index.php)
$selectedModes = [];
if (isset($_POST['suppliers'])) {
    $selectedModes = (array)$_POST['suppliers'];
} elseif (isset($_GET['suppliers'])) {
    $selectedModes = (array)$_GET['suppliers'];
}
$selectedModes = array_values(array_unique(array_map('strtolower', array_filter($selectedModes, fn($x) => is_string($x) && $x !== ''))));

// pokud je výběr poslaný, jedeme jen vybrané; jinak defaultně vše (zpětná kompatibilita)
if (count($selectedModes) > 0) {
    $feeds = array_values(array_filter($feeds, function($f) use ($selectedModes) {
        $m = strtolower((string)($f['mode'] ?? ''));
        return $m !== '' && in_array($m, $selectedModes, true);
    }));
}



$mergedRelDir  = 'VSECHNY SPOJENE XML FEED';
$mergedDir     = $docRoot . '/' . $mergedRelDir;
$mergedFile    = $mergedDir . '/ALL_XML.csv';
$mergedSpojeneFile = $mergedDir . '/SpojeneFeedy.csv';

// Jeden čas aktualizace pro celý běh.
// Do SpojeneFeedy.csv / ALL_XML.csv zapisujeme čas dokončení tohoto spuštění,
// ne starý filemtime z cache, aby Excel vždy ukazoval aktuální datum po kliknutí na aktualizaci.
$runStartedAt = time();

function is_cli_or_ajax(bool $isAjax): bool {
    return $isAjax || PHP_SAPI === 'cli';
}

function out_line(string $s, bool $isAjax): void {
    if (is_cli_or_ajax($isAjax)) {
        echo $s . "\n";
    } else {
        echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . "<br>\n";
    }
    @flush();
    if (function_exists('ob_flush')) @ob_flush();
}

function ensure_dir(string $dir): bool {
    return is_dir($dir) || @mkdir($dir, 0775, true);
}

function validate_not_html(string $file, string $contentType, string &$msg): bool {
    $raw = @file_get_contents($file, false, null, 0, 4096);
    if ($raw === false) return true;

    $raw  = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $trim = ltrim($raw);
    $head = strtolower(substr($trim, 0, 64));

    if (str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html')) {
        $msg = "Stažený obsah je HTML (pravděpodobně přihlášení). CT={$contentType}";
        return false;
    }
    return true;
}

/** Spočítá datové řádky v CSV (bez hlavičky) - pro diagnostiku "stáhlo se to, ale kolik toho vlastně bylo". */
function dfeed_count_csv_data_rows(string $file): int {
    if (!is_file($file)) return 0;
    $fh = @fopen($file, 'rb');
    if (!$fh) return 0;
    $n = -1; // první řádek je hlavička
    while (fgets($fh) !== false) $n++;
    fclose($fh);
    return max(0, $n);
}

function download_http(string $url, string $finalFile, int $connectTimeout, int $transferTimeout, array $curlExtra, array &$out): bool {
    $tmpFile = $finalFile . '._tmp';

    $fp = @fopen($tmpFile, 'wb');
    if (!$fp) { $out[] = "Nelze otevřít do zápisu: {$tmpFile}"; return false; }

    $ch = curl_init();
    $base = [
        CURLOPT_URL            => $url,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT        => $transferTimeout,
        CURLOPT_FILE           => $fp,
        CURLOPT_USERAGENT      => 'CFloat-Feeds-Downloader/13.0',
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept: application/xml,text/xml,*/*'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    foreach ($curlExtra as $k => $v) $base[$k] = $v;
    curl_setopt_array($ch, $base);

    $ok    = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $eff   = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    curl_close($ch);
    fclose($fp);

    if (!$ok || $errno) { @unlink($tmpFile); $out[] = "cURL ({$errno}) {$err}"; return false; }
    if ($http < 200 || $http >= 300) { @unlink($tmpFile); $out[] = "HTTP {$http} (CT={$ctype}) URL={$eff}"; return false; }

    $vmsg = '';
    if (!validate_not_html($tmpFile, $ctype, $vmsg)) {
        @unlink($tmpFile);
        $out[] = $vmsg;
        return false;
    }

    if (!@rename($tmpFile, $finalFile)) {
        if (!@copy($tmpFile, $finalFile)) { @unlink($tmpFile); $out[] = "Nelze uložit do {$finalFile}"; return false; }
        @unlink($tmpFile);
    }

    return true;
}

function download_ftp_curl(string $host, string $user, string $pass, string $remoteDir, string $remoteFile, string $finalFile, array &$out): bool {
    $remoteDir = trim($remoteDir);
    if ($remoteDir === '' || $remoteDir === '.') $remoteDir = '/';
    if ($remoteDir[0] !== '/') $remoteDir = '/' . $remoteDir;
    $remoteDir = rtrim($remoteDir, '/') . '/';

    $remoteFile = ltrim($remoteFile, '/');
    $url = 'ftp://' . $host . $remoteDir . $remoteFile;

    $tmpFile = $finalFile . '._tmp';
    $fp = @fopen($tmpFile, 'wb');
    if (!$fp) { $out[] = "FTP(curl): nelze otevřít do zápisu {$tmpFile}"; return false; }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL              => $url,
        CURLOPT_USERPWD          => $user . ':' . $pass,
        CURLOPT_FILE             => $fp,
        CURLOPT_CONNECTTIMEOUT   => 20,
        CURLOPT_TIMEOUT          => 0,
        CURLOPT_FAILONERROR      => true,
        CURLOPT_FTP_USE_EPSV     => true,
        CURLOPT_FTP_SKIP_PASV_IP => true, // důležité na hostingu
        CURLOPT_USERAGENT        => 'CFloat-Feeds-Downloader/13.0',
    ]);

    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $rcode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);
    fclose($fp);

    if (!$ok || $errno) {
        @unlink($tmpFile);
        $out[] = "FTP(curl): chyba ({$errno}) {$err} | RESP={$rcode} | URL={$url}";
        return false;
    }

    if (!@rename($tmpFile, $finalFile)) {
        if (!@copy($tmpFile, $finalFile)) { @unlink($tmpFile); $out[] = "FTP(curl): nelze uložit do {$finalFile}"; return false; }
        @unlink($tmpFile);
    }

    return true;
}

function open_csv(string $path, array $header): SplFileObject {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $f = new SplFileObject($path, 'w');
    $f->fwrite("\xEF\xBB\xBF"); // BOM
    $f->fputcsv($header, ';');
    return $f;
}

function parse_number($v): ?float {
    $s = trim((string)$v);
    if ($s === '') return null;

    // odstranění mezer (včetně NBSP)
    $s = str_replace(["\xC2\xA0", " "], "", $s);

    $lastDot   = strrpos($s, '.');
    $lastComma = strrpos($s, ',');

    // pokud jsou obě, rozhodni podle toho, co je dál vpravo (desetinný oddělovač)
    if ($lastDot !== false && $lastComma !== false) {
        if ($lastComma > $lastDot) {
            // 1.234,56  => 1234.56
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            // 1,234.56  => 1234.56
            $s = str_replace(',', '', $s);
        }
    } elseif ($lastComma !== false) {
        // 2,0 => 2.0
        $s = str_replace(',', '.', $s);
    }

    if (!is_numeric($s)) return null;
    return (float)$s;
}


/**
 * Stream parser: vytáhne EAN + value dle režimu a zapisuje full CSV + update CSV dle pravidla.
 */
function parse_xml_stream(string $xmlFile, string $mode, string $fullCsv, string $updCsv, array &$out): bool {
    $tags = [
        'sportimport' => ['ean' => ['ean'], 'val' => ['instock','in_stock','stock','quantity'], 'header' => ['EAN','IN_STOCK']],
        'silvini'     => ['ean' => ['ean'], 'val' => ['dispozice','stock'], 'header' => ['EAN','DISPOZICE']],
        'vavrys'      => ['ean' => ['ean'], 'val' => ['delivery_date','deliverydate'], 'header' => ['EAN','DELIVERY_DATE']],
        'devold'      => ['ean' => ['ean'], 'val' => ['instock','stockqty','stock_qty','stock','quantity','inStock','in_stock','stock_count'], 'header' => ['EAN','inStock']],
    ];
    if (!isset($tags[$mode])) { $out[] = "Neznámý mode: {$mode}"; return false; }
    $cfg = $tags[$mode];

    $full = open_csv($fullCsv, $cfg['header']);
    $upd  = open_csv($updCsv, ['EAN','Dostupnost']);

    $r = new XMLReader();
    if (!$r->open($xmlFile, null, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
        $out[] = "Nelze otevřít XML: {$xmlFile}";
        return false;
    }

    $ean = null;
    $val = null;
    $recordDepth = null;

    $eanTags = array_fill_keys(array_map('strtolower', $cfg['ean']), true);
    $valTags = array_fill_keys(array_map('strtolower', $cfg['val']), true);

    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT) {
            $n = strtolower($r->localName ?: $r->name);


            // atributy (kvůli feedům s namespace/prefixy nebo EAN ve vlastnosti)
            if ($r->hasAttributes) {
                while ($r->moveToNextAttribute()) {
                    $an = strtolower($r->localName ?: $r->name);
                    $av = trim($r->value);
                    if ($av !== '') {
                        if ($ean === null && isset($eanTags[$an])) {
                            $ean = $av;
                            $recordDepth = $recordDepth ?? max(0, $r->depth - 1);
                        } elseif ($val === null && isset($valTags[$an])) {
                            $val = is_numeric($av) ? (int)$av : $av;
                            $recordDepth = $recordDepth ?? max(0, $r->depth - 1);
                        }
                    }
                }
                $r->moveToElement();
            }

            if (isset($eanTags[$n])) {
                $eanVal = trim($r->readString());
                if ($eanVal !== '') {
                    $ean = $eanVal;
                    $recordDepth = $recordDepth ?? max(0, $r->depth - 1);
                }
            } elseif (isset($valTags[$n])) {
                $v = trim($r->readString());
                if ($v !== '') {
                    $val = is_numeric($v) ? (int)$v : $v;
                    $recordDepth = $recordDepth ?? max(0, $r->depth - 1);
                }
            }
        } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
            if ($recordDepth !== null && $r->depth === $recordDepth) {
                if ($ean !== null && $val !== null) {
                    $full->fputcsv([$ean, (string)$val], ';');

                    $ok = false;
                    if ($mode === 'sportimport' || $mode === 'silvini') {
                        $n = parse_number($val);
                        $ok = ($n !== null) && ($n >= 1);
                    } elseif ($mode === 'devold') {
                        $n = parse_number($val);
                        $ok = ($n !== null) && ($n >= 2);
                    } elseif ($mode === 'vavrys') {
                        $n = parse_number($val);
                        $ok = ($n !== null && (int)$n === 0) || ((string)$val === '0');
                    }

                    if ($ok) {
                        $upd->fputcsv([$ean, '0'], ';');
                    }
                }

                $ean = null;
                $val = null;
                $recordDepth = null;
            }
        }
    }

    $r->close();
    return true;
}

function merge_updates(array $sourceToFile, string $outFile, array &$out, array $sourceTimestamps = []): bool {
    $dir = dirname($outFile);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        $out[] = "Nelze vytvořit složku: {$dir}";
        return false;
    }

    $fout = new SplFileObject($outFile, 'w');
    $fout->fwrite("\xEF\xBB\xBF");
    $fout->fputcsv(['Zdroj','EAN','Dostupnost','Aktualizováno'], ';');

    foreach ($sourceToFile as $src => $file) {
        if (!is_file($file)) { $out[] = "Chybí aktualizační CSV: {$file}"; continue; }

        $fh = fopen($file, 'rb');
        if (!$fh) { $out[] = "Nelze číst: {$file}"; continue; }

        $lineNo = 0;
        clearstatcache(true, $file);
        $feedTs = isset($sourceTimestamps[$src]) ? (int)$sourceTimestamps[$src] : (int)(@filemtime($file) ?: 0);
        $feedDate = $feedTs > 0 ? date('Y-m-d H:i:s', $feedTs) : '';

        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            $lineNo++;
            if ($lineNo === 1) continue; // header
            if (!$row || count($row) < 1) continue;
            $ean = trim((string)($row[0] ?? ''));
            $val = trim((string)($row[1] ?? '0'));
            if ($ean === '') continue;
            $fout->fputcsv([$src, $ean, $val === '' ? '0' : $val, $feedDate], ';');
        }
        fclose($fh);
    }

    return true;
}



/**
 * ISADORE parser: vytáhne g:ean, g:stock_count, g:price
 * Vytvoří:
 *  - isadore.csv (EAN;stock_count;price)
 *  - AktualizacniIsadore.csv (EAN;Dostupnost) s pravidlem stock_count >= 5 => Dostupnost=0
 */
function parse_isadore_stream(string $xmlFile, string $fullCsv, string $updCsv, array &$out): bool {
    $full = open_csv($fullCsv, ['EAN','stock_count','price']);
    $upd  = open_csv($updCsv,  ['EAN','Dostupnost']);

    $r = new XMLReader();
    if (!$r->open($xmlFile, null, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
        $out[] = "Nelze otevřít XML: {$xmlFile}";
        return false;
    }

    $ean = null;
    $stock = null;
    $price = null;
    $recordDepth = null;

    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT) {
            $local  = strtolower($r->localName ?: $r->name);
            $prefix = strtolower((string)($r->prefix ?? ''));
            $name   = strtolower((string)($r->name ?? ''));

            $isG = ($prefix === 'g') || (strpos($name, 'g:') === 0);

            if ($isG && $local === 'ean') {
                $ean = trim($r->readString());
                if ($recordDepth === null) $recordDepth = max(0, $r->depth - 1);
            } elseif ($isG && ($local === 'stock_count' || $local === 'stockcount')) {
                $stock = trim($r->readString());
                if ($recordDepth === null) $recordDepth = max(0, $r->depth - 1);
            } elseif ($isG && $local === 'price') {
                $price = trim($r->readString());
                if ($recordDepth === null) $recordDepth = max(0, $r->depth - 1);
            }
        } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
            if ($recordDepth !== null && $r->depth === $recordDepth) {
                if ($ean !== null && $ean !== '') {
                    $full->fputcsv([$ean, (string)$stock, (string)$price], ';');

                    $n = parse_number($stock);
                    if ($n !== null && $n >= 5) {
                        $upd->fputcsv([$ean, '0'], ';');
                    }
                }
                $ean = null; $stock = null; $price = null;
                $recordDepth = null;
            }
        }
    }

    // fallback: kdyby XML neuzavřelo očekávaný record
    if ($ean !== null && $ean !== '') {
        $full->fputcsv([$ean, (string)$stock, (string)$price], ';');
        $n = parse_number($stock);
        if ($n !== null && $n >= 5) {
            $upd->fputcsv([$ean, '0'], ';');
        }
    }

    $r->close();
    return true;
}


/**
 * LEGACY / od 2026-08-31 se nepoužívá (viz definice feedu Vavrys výše, teď 'type' => 'http').
 *
 * VAVRYS z databáze:
 *  - bere DISTINCT EAN z tabulky vavrys_variants
 *  - do CSV i aktualizačního CSV zapisuje Dostupnost VŽDY 0 (skladem) – bez ohledu na
 *    sloupce stock/stock_supplier, které tabulka vavrys_variants reálně obsahuje.
 *  - PROBLÉM: tabulka vavrys_variants se maže a plní znovu jen při ručním spuštění
 *    vavrys_download_catalog.php + vavrys_import_full.php (SOAP KompletniKatalog). Pokud
 *    tenhle plný refresh dlouho neběžel, zůstávaly v tabulce EANy produktů, které Vavrys
 *    už vůbec nemá v katalogu (nebo mají nulovou zásobu) – a protože se skladovost vůbec
 *    nekontrolovala, hlásily se navěky jako "skladem". Nahrazeno živým HTTP feedem pro
 *    Zboží.cz (DELIVERY_DATE=0 = skladem), viz mode 'vavrys' v parse_xml_stream().
 */
function vavrys_generate_from_db(PDO $pdo, string $xmlPath, string $fullCsv, string $updCsv, array &$out): bool {
    $sql = "SELECT DISTINCT EAN FROM vavrys_variants WHERE EAN IS NOT NULL AND EAN <> ''";
    $st = $pdo->query($sql);
    if (!$st) {
        $out[] = "DB: nelze spustit dotaz na vavrys_variants";
        return false;
    }

    // XML – streamovaný zápis (kvůli paměti)
    $xh = @fopen($xmlPath, 'wb');
    if (!$xh) {
        $out[] = "DB: nelze otevřít do zápisu {$xmlPath}";
        return false;
    }
    fwrite($xh, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<items>
");

    // CSV
    $full = open_csv($fullCsv, ['EAN','DELIVERY_DATE']);
    $upd  = open_csv($updCsv,  ['EAN','Dostupnost']);

    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $ean = trim((string)($row['EAN'] ?? ''));
        if ($ean === '') continue;

        $safe = htmlspecialchars($ean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        fwrite($xh, "  <item><ean>{$safe}</ean><delivery_date>0</delivery_date></item>
");

        $full->fputcsv([$ean, '0'], ';');
        $upd->fputcsv([$ean, '0'], ';');
    }

    fwrite($xh, "</items>
");
    fclose($xh);

    return true;
}



/**
 * C-STORE SKLAD (FTP):
 *  - stáhne nejnovější soubor z FTP (porovná /stock_export/automatic a /stock_export/manual)
 *  - uloží ho jako Dodavatele/SKLAD C-STORE/celySkladExport.csv
 *  - pro ALL_XML vyrábí aktualizační CSV ve tvaru: EAN;0 (EAN je sloupec F zdrojového CSV, filtr podle sloupce G)
 */
function download_sklad_ftp_latest(string $destFile, array &$out, ?string &$pickedRemote = null, ?int &$pickedMtime = null): bool {
    $host = getenv('SKLAD_FTP_HOST') ?: SKLAD_FTP_HOST;
    $user = getenv('SKLAD_FTP_USER') ?: SKLAD_FTP_USER;
    $pass = getenv('SKLAD_FTP_PASS') ?: SKLAD_FTP_PASS;

    // umí brát nejnovější soubor z více složek (automatic + manual)
    $dirs = [];
    $envDirs = getenv('SKLAD_FTP_DIRS');
    if (is_string($envDirs) && trim($envDirs) !== '') {
        $dirs = array_values(array_filter(array_map('trim', explode(',', $envDirs)), fn($x) => $x !== ''));
    } else {
        $envDir = getenv('SKLAD_FTP_DIR');
        if (is_string($envDir) && trim($envDir) !== '') {
            $dirs = [trim($envDir)];
        } else {
            $dirs = SKLAD_FTP_DIRS;
        }
    }

    if (!function_exists('ftp_connect')) {
        $out[] = "PHP FTP rozšíření není dostupné (ftp_connect neexistuje).";
        return false;
    }

    $ftp = @ftp_connect($host, 21, 20);
    if (!$ftp) { $out[] = "Nelze se připojit na FTP {$host}"; return false; }

    if (!@ftp_login($ftp, $user, $pass)) {
        @ftp_close($ftp);
        $out[] = "Nelze se přihlásit na FTP (uživatel/heslo).";
        return false;
    }
    @ftp_pasv($ftp, true);

    $latestRemote = '';
    $latestMtime  = -1;
    $dirWarnings  = [];

    foreach ($dirs as $dir) {
        $dir = '/' . ltrim((string)$dir, '/');
        $dir = rtrim($dir, '/');

        $list = @ftp_nlist($ftp, $dir);
        if ($list === false) {
            $list = @ftp_nlist($ftp, $dir . '/');
        }
        if ($list === false || !is_array($list) || count($list) === 0) {
            $dirWarnings[] = "nelze vypsat {$dir}";
            continue;
        }

        foreach ($list as $item) {
            if (!is_string($item) || $item === '' || $item === '.' || $item === '..') continue;

            // ftp_nlist někdy vrací jen názvy, někdy plné cesty
            $remote = (str_starts_with($item, '/')) ? $item : ($dir . '/' . ltrim($item, '/'));
            $remote = str_replace('\\', '/', $remote);

            $size = @ftp_size($ftp, $remote);
            if ($size === -1) continue; // přeskoč složky / neznámé

            $mt = @ftp_mdtm($ftp, $remote);
            if ($mt === -1) $mt = 0;

            if ($mt > $latestMtime) {
                $latestMtime  = $mt;
                $latestRemote = $remote;
            }
        }
    }

    if ($latestRemote === '' && count($dirWarnings) > 0) {
        // přidáme do $out informaci, kde to nešlo načíst
        $out[] = "FTP: varování: " . implode(', ', $dirWarnings);
    }

    if ($latestRemote === '') {
        @ftp_close($ftp);
        $out[] = "FTP: Nenašel jsem žádný soubor ke stažení v žádné ze složek (automatic/manual).";
        return false;
    }

    // report vybraného souboru volajícímu
    $pickedRemote = $latestRemote;
    $pickedMtime  = ($latestMtime > 0) ? $latestMtime : null;

    $tmp = $destFile . '._tmp';
    if (!ensure_dir(dirname($destFile))) {
        @ftp_close($ftp);
        $out[] = "Nelze vytvořit cílovou složku pro {$destFile}";
        return false;
    }

    if (is_file($tmp)) @unlink($tmp);
    $ok = @ftp_get($ftp, $tmp, $latestRemote, FTP_BINARY);
    @ftp_close($ftp);

    if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
        if (is_file($tmp)) @unlink($tmp);
        $out[] = "FTP: Stažení selhalo ({$latestRemote}).";
        return false;
    }

    if (!@rename($tmp, $destFile)) {
        if (!@copy($tmp, $destFile)) { @unlink($tmp); $out[] = "Nelze uložit do {$destFile}"; return false; }
        @unlink($tmp);
    }

    $out[] = "Staženo: {$latestRemote}";
    return true;
}

function detect_csv_delimiter(string $file): string {
    $sample = @file_get_contents($file, false, null, 0, 4096);
    if ($sample === false) return ';';
    $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);

    $counts = [
        ';'  => substr_count($sample, ';'),
        ','  => substr_count($sample, ','),
        "\t" => substr_count($sample, "\t"),
    ];
    arsort($counts);
    $delim = array_key_first($counts);
    return $delim ?: ';';
}

function sklad_make_update_csv(string $srcCsv, string $updCsv, array &$out): bool {
    if (!is_file($srcCsv)) { $out[] = "Chybí zdroj: {$srcCsv}"; return false; }

    // Ve zdrojovém celySkladExport.csv bereme:
    // - EAN = sloupec F (index 5)
    // - filtr = sloupec G (index 6) musí být v rozsahu [1001..1029] nebo [1..29]
    // Výstup: EAN;0
    $delim = detect_csv_delimiter($srcCsv);

    $upd = open_csv($updCsv, ['EAN','Dostupnost']);

    $fh = fopen($srcCsv, 'rb');
    if (!$fh) { $out[] = "Nelze číst: {$srcCsv}"; return false; }

    $lineNo = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $lineNo++;

        if (!$row || count($row) < 7) continue; // potřebujeme min. do sloupce G

        // header detekce (pokus)
        if ($lineNo === 1) {
            $hF = strtolower(trim((string)($row[5] ?? '')));
            $hG = strtolower(trim((string)($row[6] ?? '')));
            if ($hF === 'ean' || $hF === 'barcode' || $hF === 'kód' || $hF === 'kod') {
                continue;
            }
            if ($hG === 'sklad' || $hG === 'warehouse' || $hG === 'id_sklad' || $hG === 'id sklad') {
                continue;
            }
        }

        $ean = trim((string)($row[5] ?? '')); // F
        if ($ean === '' || strtolower($ean) === 'ean') continue;

        $gRaw = trim((string)($row[6] ?? '')); // G
        if ($gRaw === '') continue;

        // normalizace čísla (např. "1", "1001", "1.0", "1,0")
        $gNorm = str_replace([' ', "\xC2\xA0"], '', $gRaw);
        $gNorm = str_replace(',', '.', $gNorm);
        if (!is_numeric($gNorm)) continue;
        $gVal = (float)$gNorm;

        $inRange = (($gVal >= 1001 && $gVal < 1030) || ($gVal >= 1 && $gVal < 30));
        if (!$inRange) continue;

        $upd->fputcsv([$ean, '0'], ';');
    }
    fclose($fh);

    return true;
}






function find_existing_file_ci(string $dirAbs, array $names): ?string {
    $dirAbs = rtrim($dirAbs, '/\\');
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
}

function source_is_cstore_like(string $src): bool {
    $s = strtolower(trim($src));
    return $s !== '' && (
        strpos($s, 'cstore') !== false ||
        strpos($s, 'c-store') !== false ||
        strpos($s, 'c store') !== false ||
        strpos($s, 'sklad c-store') !== false ||
        strpos($s, 'cstore_sklad') !== false
    );
}

function source_is_sportimport_like(string $src): bool {
    $s = strtolower(trim($src));
    $s = str_replace([' ', '-', '_'], '', $s);
    return $s === 'sportimport';
}

function normalize_ean_digits(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $digits = preg_replace('~\D+~', '', $raw);
    return $digits ?: $raw;
}

function ensure_sportimport_update_source(array &$updatesForMerge, array &$sourceTimestamps, string $docRoot): void {
    $sportDir = rtrim($docRoot, '/\\') . '/Dodavatele/SportImport';
    $sportUpd = find_existing_file_ci($sportDir, ['AktualizacniSportImport.csv', 'aktualizacnisportimport.csv']);
    if (!$sportUpd || !is_file($sportUpd)) return;

    // Vždy držíme SportImport podle aktuálního souboru AktualizacniSportImport.csv.
    // Pokud se feed v tomto běhu nestáhl nebo nebyl vybraný, stejně se do spojených feedů doplní.
    foreach (array_keys($updatesForMerge) as $src) {
        if (source_is_sportimport_like((string)$src)) unset($updatesForMerge[$src]);
    }
    $updatesForMerge['SportImport'] = $sportUpd;
    $sourceTimestamps['SportImport'] = (int)(@filemtime($sportUpd) ?: time());
}

function append_update_csv_rows_to_merged(SplFileObject $fout, string $sourceName, string $updateCsv, string $feedDate, int &$written): void {
    if (!is_file($updateCsv)) return;
    $delim = detect_csv_delimiter($updateCsv);
    $fh = @fopen($updateCsv, 'rb');
    if (!$fh) return;

    $lineNo = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $lineNo++;
        if (!is_array($row) || count($row) < 1) continue;
        $ean = normalize_ean_digits((string)($row[0] ?? ''));
        $val = trim((string)($row[1] ?? '0'));

        if ($lineNo === 1) {
            $probe = strtolower(trim(implode(' ', $row)));
            if (strpos($probe, 'ean') !== false || strpos($probe, 'dostup') !== false) continue;
        }
        if ($ean === '' || strtolower($ean) === 'ean') continue;
        if ($val === '') $val = '0';

        $fout->fputcsv([$sourceName, $ean, $val, $feedDate], ';');
        $written++;
    }
    fclose($fh);
}

function rebuild_cstore_sklad_merged(string $baseMerged, string $cstoreUpdateCsv, string $targetFile, array &$out): bool {
    if (!is_file($baseMerged)) return false;
    if (!is_file($cstoreUpdateCsv)) return false;

    $dir = dirname($targetFile);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        $out[] = "Nelze vytvořit složku: {$dir}";
        return false;
    }

    $fout = new SplFileObject($targetFile, 'w');
    $fout->fwrite("\xEF\xBB\xBF");
    $fout->fputcsv(['Zdroj','EAN','Dostupnost','Aktualizováno'], ';');
    $written = 0;

    $baseDelim = detect_csv_delimiter($baseMerged);
    $fhBase = @fopen($baseMerged, 'rb');
    if ($fhBase) {
        $lineNo = 0;
        while (($row = fgetcsv($fhBase, 0, $baseDelim)) !== false) {
            $lineNo++;
            if (!is_array($row) || count($row) < 3) continue;
            if ($lineNo === 1) {
                $first = strtolower(trim((string)($row[0] ?? '')));
                $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
                if ($first === 'zdroj') continue;
            }

            $src = trim((string)($row[0] ?? ''));
            $ean = normalize_ean_digits((string)($row[1] ?? ''));
            $val = trim((string)($row[2] ?? ''));
            $dt  = trim((string)($row[3] ?? ''));
            if ($src === '' || $ean === '') continue;
            if (source_is_cstore_like($src)) continue;
            if ($val === '') $val = '0';
            if ($dt === '') $dt = date('Y-m-d H:i:s', (int)(@filemtime($baseMerged) ?: time()));
            $fout->fputcsv([$src, $ean, $val, $dt], ';');
            $written++;
        }
        fclose($fhBase);
    }

    $cstoreDate = date('Y-m-d H:i:s', (int)(@filemtime($cstoreUpdateCsv) ?: time()));
    append_update_csv_rows_to_merged($fout, 'CSTORE_SKLAD', $cstoreUpdateCsv, $cstoreDate, $written);
    @touch($targetFile);
    $out[] = "{$targetFile}: {$written} řádků";
    return true;
}


// ---------- RUN ----------
if (!is_cli_or_ajax($isAjax)) {
    echo "<!doctype html><meta charset='utf-8'><title>download_feeds</title><div style='font-family:system-ui;padding:14px'>";
}

$updatesForMerge = [];
$sourceTimestamps = [];
$totalSteps = count($feeds) + 1; // + merge
$step = 0;
out_line("__PROGRESS__|0|{$totalSteps}|Spouštím…", $isAjax);

// POZOR: SpojeneFeedy.csv / ALL_XML.csv se teď skládá výhradně z dodavatelů
// zaškrtnutých v tabulce XML feed – Dodavatelé.
// C-Store sklad export (FTP) se do spojených feedů NIKDY nepřidává.
// Pro sklad zůstává samostatné tlačítko / samostatný modul.


foreach ($feeds as $f) {
    $name = $f['name'];
    $dir  = $docRoot . '/' . $f['dir'];
    if (!ensure_dir($dir)) {
        out_line("{$name}: CHYBA - nelze vytvořit složku {$dir}", $isAjax);
        $step++;
        out_line("__PROGRESS__|{$step}|{$totalSteps}|{$name}", $isAjax);
        continue;
    }

    $xmlPath = $dir . '/' . $f['xml'];


    // CSV cesty (použije se jak pro download parsování, tak pro DB variantu Vavrys)
    $fullCsv = $dir . '/' . $f['csv_full'];
    $updCsv  = $dir . '/' . $f['csv_extra'];

    // LEGACY / od 2026-08-31 nepoužíváno: Vavrys má teď 'type' => 'http' (viz definice feedu
    // výše), takže sem se běh nikdy nedostane. Kód je ponechán jen jako záloha pro případ,
    // že by bylo potřeba se k DB variantě vrátit. Původní chování bylo chybné - viz komentář
    // u vavrys_generate_from_db().
    if (($f['type'] ?? 'http') === 'db') {
        $dbOut = [];
        if (!($pdo instanceof PDO)) {
            $dbOut[] = "Chybí DB připojení (\$pdo) – zkontroluj config.php";
            out_line("{$name}: CHYBA - " . implode(" | ", $dbOut), $isAjax);
            $step++;
            out_line("__PROGRESS__|{$step}|{$totalSteps}|{$name}", $isAjax);
            continue;
        }

        $ok = vavrys_generate_from_db($pdo, $xmlPath, $fullCsv, $updCsv, $dbOut);
        if (!$ok) {
            out_line("{$name}: CHYBA - " . implode(" | ", $dbOut), $isAjax);
            $step++;
            out_line("__PROGRESS__|{$step}|{$totalSteps}|{$name}", $isAjax);
            continue;
        }

        // merge do ALL_XML.csv
        $updatesForMerge[$name] = $updCsv;
        $sourceTimestamps[$name] = time();

        out_line(
            "{$name}: OK -> {$xmlPath} (" . (@filesize($xmlPath) ?: 0) . " B), "
            . dfeed_count_csv_data_rows($fullCsv) . " polozek celkem, "
            . dfeed_count_csv_data_rows($updCsv) . " skladem",
            $isAjax
        );
        $step++;
        out_line("__PROGRESS__|{$step}|{$totalSteps}|{$name}", $isAjax);
        continue;
    }

    if (($f['type'] ?? 'http') === 'ftp') {
        $out = [];
        $ok = download_ftp_curl(FTP_HOST, FTP_USER, FTP_PASS, (string)($f['ftp_dir'] ?? '/'), (string)($f['ftp_file'] ?? $f['xml']), $xmlPath, $out);
        if (!$ok) {
            out_line("{$name}: CHYBA - " . implode(" | ", $out), $isAjax);
            $step++;
            out_line("__PROGRESS__|{$step}|{$totalSteps}|{$name}", $isAjax);
            continue;
        }
    } else {
        $url = (string)$f['url'];

        if ($name === 'SportImport' && $sportimportBrandId !== '' && !preg_match('~/catalogue/\d+/?$~', $url)) {
            $url = rtrim($url, '/') . '/' . rawurlencode($sportimportBrandId);
        }

        $curlExtra = [];
        if ($name === 'SportImport' && ($sportimportUser !== '' || $sportimportPass !== '')) {
            $curlExtra[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $curlExtra[CURLOPT_USERPWD]  = $sportimportUser . ':' . $sportimportPass;
        }

        $out = [];
        $ok = download_http($url, $xmlPath, $connectTimeout, $transferTimeout, $curlExtra, $out);
        if (!$ok) {
            out_line("{$name}: CHYBA - " . implode(" | ", $out), $isAjax);
            $step++;
            out_line("__PROGRESS__|{$step}|{$totalSteps}|{$name}", $isAjax);
            continue;
        }
    }

    // CSV (full + aktualizační)

    $pout = [];
    $mode = strtolower((string)($f['mode'] ?? ''));
    if ($mode === 'isadore') {
        $ok2 = parse_isadore_stream($xmlPath, $fullCsv, $updCsv, $pout);
    } else {
        $ok2 = parse_xml_stream($xmlPath, $mode, $fullCsv, $updCsv, $pout);
    }

    if (!$ok2) {
        out_line("{$name}: CHYBA - CSV - " . implode(" | ", $pout), $isAjax);
        $step++;
        out_line("__PROGRESS__|{$step}|{$totalSteps}|{$name}", $isAjax);
        continue;
    }

    // merge do ALL_XML.csv: chceme i DEVOLD + ISADORE
    $srcKey = ($mode === 'devold') ? 'DEVOLD' : $name;
    $updatesForMerge[$srcKey] = $updCsv;
    $sourceTimestamps[$srcKey] = time();

out_line(
    "{$name}: OK -> {$xmlPath} (" . (@filesize($xmlPath) ?: 0) . " B), "
    . dfeed_count_csv_data_rows($fullCsv) . " polozek celkem, "
    . dfeed_count_csv_data_rows($updCsv) . " skladem",
    $isAjax
);
    $step++;
    out_line("__PROGRESS__|{$step}|{$totalSteps}|{$name}", $isAjax);
}

// Do spojených feedů vždy doplníme i SportImport z aktuálního AktualizacniSportImport.csv.
ensure_sportimport_update_source($updatesForMerge, $sourceTimestamps, $docRoot);

// merge
// Před samotným spojením nastavíme všem řádkům čas dokončení aktualizace.
// Díky tomu nebude ve SpojeneFeedy.csv zůstávat starý čas z minulého běhu.
$mergeTimestamp = time();
foreach (array_keys($updatesForMerge) as $srcName) {
    $sourceTimestamps[$srcName] = $mergeTimestamp;
}

$mergeOut = [];
$mergeOk1 = merge_updates($updatesForMerge, $mergedFile, $mergeOut, $sourceTimestamps);
$mergeOk2 = merge_updates($updatesForMerge, $mergedSpojeneFile, $mergeOut, $sourceTimestamps);
$mergeOk = $mergeOk1 && $mergeOk2;
if (!$mergeOk) {
    out_line("SPOJENE FEEDY: CHYBA - " . implode(" | ", $mergeOut), $isAjax);
} else {
    out_line("SPOJENE FEEDY: OK -> {$mergedFile} a {$mergedSpojeneFile}", $isAjax);
}

// Pokud už existuje ručně nahraný sklad C-Store, po aktualizaci feedů znovu přepíšeme i
// SpojeneFeedy_CSTORE_SKLAD.csv, aby v něm byl stejný SportImport jako ve SpojeneFeedy.csv.
$cstoreUpdForMerge = find_existing_file_ci($docRoot . '/Dodavatele/SKLAD C-STORE', ['AktualizacniSkladCSTORE.csv', 'aktualizacniskladcstore.csv']);
if ($mergeOk && $cstoreUpdForMerge && is_file($cstoreUpdForMerge)) {
    $cstoreMergeOut = [];
    $cstoreSpojene = $mergedDir . '/SpojeneFeedy_CSTORE_SKLAD.csv';
    $cstoreAllXml  = $mergedDir . '/ALL_XML_CSTORE_SKLAD.csv';
    rebuild_cstore_sklad_merged($mergedSpojeneFile, $cstoreUpdForMerge, $cstoreSpojene, $cstoreMergeOut);
    rebuild_cstore_sklad_merged($mergedFile,        $cstoreUpdForMerge, $cstoreAllXml,  $cstoreMergeOut);
    @copy($cstoreSpojene, $mergedDir . '/spojenefeedy_cstore_sklad.csv');
    @copy($cstoreAllXml,  $mergedDir . '/all_xml_cstore_sklad.csv');
    out_line("CSTORE_SKLAD: OK -> {$cstoreSpojene}", $isAjax);
} elseif ($mergeOk) {
    out_line("CSTORE_SKLAD: VAROVANI - nenalezen soubor skladu C-Store (AktualizacniSkladCSTORE.csv), spojeny lookup NEBYL prebudovan a muze byt zastaraly.", $isAjax);
}

// ---------------------------------------------------------------------------
// AUTOMATICKÉ PŘEPOČÍTÁNÍ zpracovane/*.csv HNED PO STAŽENÍ - dřív se muselo po
// každém stažení feedů ručně kliknout na "Zpracovat" v UI, a pokud se to zapomnělo
// (nebo proběhlo jen dílčí stažení jednoho dodavatele), zůstal v zpracovane/*.csv
// (a tedy i v souboru staženém tlačítkem "jen změny") starý, neaktuální výstup,
// i když už feedy měly čerstvá data. Teď se přepočet spouští automaticky pokaždé,
// hned jak se spojené feedy úspěšně obnoví.
if ($mergeOk) {
    require_once __DIR__ . '/cfloat-new/dodavatele/zpracovat_core.php';
    $zprRoot = __DIR__ . '/cfloat-new/dodavatele';

    $lookupForFreshnessCheck = zpr_find_merged_sklad_file($zprRoot, $docRoot);
    if ($lookupForFreshnessCheck) {
        $freshnessWarning = zpr_check_freshness($lookupForFreshnessCheck, [
            'SpojeneFeedy.csv' => $mergedSpojeneFile,
            'ALL_XML.csv'      => $mergedFile,
        ]);
        if ($freshnessWarning !== '') {
            out_line("ZPRACOVANI: VAROVANI - {$freshnessWarning}", $isAjax);
        }
    }

    try {
        $zprSummary = zpr_run_all($zprRoot, $docRoot);
        if (isset($zprSummary['error'])) {
            out_line("ZPRACOVANI: CHYBA - " . $zprSummary['error'], $isAjax);
        } else {
            out_line(
                "ZPRACOVANI: OK -> skryto {$zprSummary['hidden_products']}, otevreno {$zprSummary['opened_products']}, "
                . "zmenene varianty {$zprSummary['variant_changed_rows']}, zmenene hlavni {$zprSummary['main_changed_rows']}",
                $isAjax
            );
        }
    } catch (Throwable $eZpr) {
        out_line("ZPRACOVANI: CHYBA - " . $eZpr->getMessage(), $isAjax);
    }
}

$step = $totalSteps;
out_line("__PROGRESS__|{$step}|{$totalSteps}|SPOJENE FEEDY", $isAjax);

if (!is_cli_or_ajax($isAjax)) {
    echo "<div style='margin-top:12px'><a href='index.php?view=xmlfeedy'>Zpět do modulu</a></div></div>";
}