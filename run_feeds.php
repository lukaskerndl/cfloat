<?php

// Spuštění jen s platným tokenem nebo z cronu (dřív bez jakékoliv ochrany).
require_once __DIR__ . '/_cron_guard.php';



declare(strict_types=1);

// Debug + stabilita (na hostingu se občas schová skutečná fatální chyba → pak vidíš jen HTTP 500)
error_reporting(E_ALL);
@ini_set('display_errors', '1');
@ini_set('log_errors', '1');
@set_time_limit(0);

// PHP 7.x kompatibilita (pokud hosting nejede na PHP 8+)
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

function rf_lower(string $s): string {
    if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
    return strtolower($s);
}


/**
 * run_feeds.php
 *
 * Wrapper: spustí původní download_feeds.php (beze změn) a potom aktualizuje *_var.csv soubory
 * pouze podle ALL_XML.csv.
 *
 * ZDROJ:
 *  - /VSECHNY SPOJENE XML FEED/ALL_XML.csv
 *    formát řádku: Zdroj;EAN;Hodnota
 *
 * CÍL:
 *  - /Aktualizace_CSTORE/VAVRYS KOMPLET/VARIANTYIProduktyVavryskomplet_var.csv
 *  - /Aktualizace_CSTORE/Silvini/NEWNEWVariantySILVINI_var.csv
 *
 * PRO CÍL:
 *  - doplnit sloupec S podle EAN z ALL_XML (EAN=sloupec 2, hodnota=sloupec 3)
 *  - pokud nenajde -> 1
 *  - seřadit podle sloupce S vzestupně (číselně)
 *  - publikovat do /Aktualizace_CSTORE/KE_STAZENI/ (stejný název souboru)
 *  - uložit statistiku změn do /Aktualizace_CSTORE/KE_STAZENI/<soubor>.stats.json
 *
 * NIC JINÉHO NEMĚNÍME.
 */

@ob_implicit_flush(true);

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($isAjax) {
    header('Content-Type: text/plain; charset=utf-8');
}


// Když dojde k fatální chybě (memory/time/undefined function), vypiš ji do logu, ať ji vidíš v modalu.
register_shutdown_function(function() use ($isAjax){
    $e = error_get_last();
    if (!$e) return;
    $types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($e['type'] ?? 0, $types, true)) return;
    $msg = 'FATAL: ' . ($e['message'] ?? 'unknown') . ' in ' . ($e['file'] ?? '?') . ':' . ($e['line'] ?? 0);
    // pokus o vypsání (může být už poškozený output)
    if ($isAjax || PHP_SAPI === 'cli') {
        echo "
" . $msg . "
";
    } else {
        echo "<br>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "<br>";
    }
    @flush();
});

function rf_out(string $s, bool $isAjax): void {
    if ($isAjax || PHP_SAPI === 'cli') {
        echo $s . "\n";
    } else {
        echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . "<br>\n";
    }
    @flush();
}

function rf_detect_delim(string $line): string {
    $cands = [ ';', ',', "\t", '|' ];
    $best = ';';
    $bestCount = -1;
    foreach ($cands as $d) {
        $cnt = substr_count($line, $d);
        if ($cnt > $bestCount) { $bestCount = $cnt; $best = $d; }
    }
    return $best;
}

function rf_is_ean_like(string $v): bool {
    $v = trim($v);
    if ($v === '') return false;
    return (bool)preg_match('~^\d{8,14}$~', $v);
}

function rf_has_header(array $row): bool {
    foreach ($row as $c) {
        $c2 = rf_lower(trim((string)$c));
        if ($c2 === 'ean' || str_contains($c2, 'ean') || str_contains($c2, 'dostup')) return true;
    }
    return false;
}

function rf_find_all_xml(string $docRoot): ?string {
    $p1 = $docRoot . '/VSECHNY SPOJENE XML FEED/ALL_XML.csv';
    if (is_file($p1)) return $p1;
    $p2 = $docRoot . '/Aktualizace_CSTORE/KE_STAZENI/ALL_XML.csv';
    if (is_file($p2)) return $p2;
    return null;
}

/**
 * build EAN->value lookup from ALL_XML.csv
 * If $preferredSource specified, uses only rows with that source; if empty result, falls back to all sources.
 * For duplicates EAN: keeps MIN numeric value (safer for "nejmenší nahoře")
 */
function rf_build_lookup(string $allXmlPath, ?string $preferredSource): array {
    $fh = @fopen($allXmlPath, 'r');
    if (!$fh) return [];

    $firstLine = fgets($fh);
    if ($firstLine === false) { fclose($fh); return []; }
    $delim = rf_detect_delim($firstLine);
    rewind($fh);

    $mapPref = [];
    $mapAll  = [];

    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        if (!is_array($row) || count($row) < 3) continue;
        $src = trim((string)$row[0]);
        $ean = trim((string)$row[1]);
        $val = trim((string)$row[2]);
        if (!rf_is_ean_like($ean)) continue;

        $valOut = is_numeric($val) ? (string)(int)$val : $val;

        $push = function (&$map) use ($ean, $valOut) {
            if (!isset($map[$ean])) {
                $map[$ean] = $valOut;
                return;
            }
            if (is_numeric($map[$ean]) && is_numeric($valOut)) {
                if ((int)$valOut < (int)$map[$ean]) $map[$ean] = (string)(int)$valOut;
            }
        };

        $push($mapAll);

        if ($preferredSource && strcasecmp($src, $preferredSource) === 0) {
            $push($mapPref);
        }
    }
    fclose($fh);

    if ($preferredSource && count($mapPref) > 0) return $mapPref;
    return $mapAll;
}


/**
 * CSTORE_SKLAD override:
 * Pokud je EAN na našem skladě (hodnota 0), NESMÍME ho "schovat" (S=1).
 * Vrací set EANů, které mají CSTORE dostupnost = 0 (tj. máme skladem).
 *
 * Primárně čte z ALL_XML.csv: řádky, kde Zdroj obsahuje "CSTORE" a Hodnota == 0.
 * Fallback: pokud existuje soubor /Dodavatele/SKLAD C-STORE/AktualizacniSkladCSTORE.csv, načte i z něj.
 */
function rf_build_cstore_instock_from_all_xml(string $allXmlPath): array {
    $set = [];

    $fh = @fopen($allXmlPath, 'r');
    if (!$fh) return $set;

    $firstLine = fgets($fh);
    if ($firstLine === false) { fclose($fh); return $set; }

    $delim = rf_detect_delim($firstLine);
    $firstRow = str_getcsv(trim($firstLine), $delim);

    // pokud první řádek vypadá jako hlavička, přeskočíme; jinak ho zpracujeme
    $hasHeader = rf_has_header($firstRow);

    if (!$hasHeader) {
        if (count($firstRow) >= 3) {
            $src = trim((string)$firstRow[0]);
            $ean = trim((string)$firstRow[1]);
            $val = trim((string)$firstRow[2]);
            if ($ean !== '' && stripos($src, 'cstore') !== false && $val === '0') {
                $set[$ean] = true;
            }
        }
    }

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $row = str_getcsv($line, $delim);
        if (count($row) < 3) continue;

        $src = trim((string)$row[0]);
        $ean = trim((string)$row[1]);
        $val = trim((string)$row[2]);

        if ($ean !== '' && stripos($src, 'cstore') !== false && $val === '0') {
            $set[$ean] = true;
        }
    }

    fclose($fh);
    return $set;
}

function rf_build_cstore_instock_from_file(?string $path): array {
    $set = [];
    if (!$path || !is_file($path)) return $set;

    $fh = @fopen($path, 'r');
    if (!$fh) return $set;

    $firstLine = fgets($fh);
    if ($firstLine === false) { fclose($fh); return $set; }

    $delim = rf_detect_delim($firstLine);
    $firstRow = str_getcsv(trim($firstLine), $delim);
    $hasHeader = rf_has_header($firstRow);

    if (!$hasHeader) {
        // očekáváme EAN;Dostupnost
        $ean = trim((string)($firstRow[0] ?? ''));
        $val = trim((string)($firstRow[1] ?? ''));
        if ($ean !== '' && $val === '0') $set[$ean] = true;
    }

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $row = str_getcsv($line, $delim);
        $ean = trim((string)($row[0] ?? ''));
        $val = trim((string)($row[1] ?? ''));
        if ($ean !== '' && $val === '0') $set[$ean] = true;
    }

    fclose($fh);
    return $set;
}


/**
 * detect EAN column in target *_var.csv
 */
function rf_detect_ean_col(string $path, string $delim): int {
    $fh = @fopen($path, 'r');
    if (!$fh) return 0;

    $rows = [];
    $maxScan = 200;
    $i = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        if (!is_array($row)) continue;
        $rows[] = $row;
        $i++;
        if ($i >= $maxScan) break;
    }
    fclose($fh);

    if (count($rows) === 0) return 0;

    $header = $rows[0];
    if (rf_has_header($header)) {
        foreach ($header as $idx => $cell) {
            $c = rf_lower(trim((string)$cell));
            if ($c === 'ean' || str_contains($c, 'ean')) return (int)$idx;
        }
    }

    $colCounts = [];
    foreach ($rows as $rIdx => $row) {
        if ($rIdx === 0 && rf_has_header($row)) continue;
        foreach ($row as $cIdx => $cell) {
            if (rf_is_ean_like((string)$cell)) {
                $colCounts[$cIdx] = ($colCounts[$cIdx] ?? 0) + 1;
            }
        }
    }
    if (!$colCounts) return 0;
    arsort($colCounts);
    return (int)array_key_first($colCounts);
}

/**
 * Update target *_var.csv:
 * - fill S (index 18)
 * - count changed rows (old S != new S)
 * - sort by S asc numeric
 * - write back + publish + write stats json
 */
function rf_update_var(string $docRoot, string $targetPath, string $publishDir, array $lookup, bool $isAjax, string $label): array {
    $res = [
        'ok' => false,
        'changed_rows' => 0,
        'total_rows' => 0,
        'hits' => 0,
        'miss' => 0,
        'header' => null,
        'delim' => ';',
        'changed_data' => [],
        'published_path' => null,
    ];

    if (!is_file($targetPath)) {
        rf_out($label . ': CHYBA - cílový soubor neexistuje: ' . $targetPath, $isAjax);
        return $res;
    }

    $fh = @fopen($targetPath, 'r');
    if (!$fh) {
        rf_out($label . ': CHYBA - nelze číst: ' . $targetPath, $isAjax);
        return $res;
    }

    $firstLine = fgets($fh);
    if ($firstLine === false) { fclose($fh); return $res; }
    $delim = rf_detect_delim($firstLine);
    $res['delim'] = $delim;
    rewind($fh);

    $eanCol = rf_detect_ean_col($targetPath, $delim);

    $rows = [];
    $header = null;
    $hasHeader = false;

    $totalRows = 0;
    $changedRows = 0;
    $hits = 0;
    $miss = 0;

    $lineNo = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $lineNo++;
        if (!is_array($row)) continue;

        if ($lineNo === 1 && rf_has_header($row)) {
            $header = $row;
            $hasHeader = true;
            continue;
        }

        $need = 19;
        $cnt = count($row);
        if ($cnt < $need) {
            for ($k = $cnt; $k < $need; $k++) $row[] = '';
        }

        $ean = isset($row[$eanCol]) ? trim((string)$row[$eanCol]) : '';
        $oldS = trim((string)$row[18]);

        $newS = '1';
        if ($ean !== '' && isset($lookup[$ean])) {
            $newS = (string)$lookup[$ean];
            if ($newS === '') $newS = '1';
            $hits++;
        } else {
            $miss++;
        }



// CSTORE_SKLAD override: pokud je EAN na našem skladě (CSTORE=0), nesmí být schovaný => S=0
if ($ean !== '' && isset($GLOBALS['RF_CSTORE_INSTOCK'][$ean])) {
    $newS = '0';
}

        if ($oldS !== $newS) {
            $changedRows++;
            $row[18] = $newS;
            $res['changed_data'][] = $row; // uložíme celý řádek po přepsání
        } else {
            $row[18] = $newS;
        }

        $rows[] = $row;
        $totalRows++;
    }
    fclose($fh);

    // seřadit podle S vzestupně, čísla
    usort($rows, function($a, $b){
        $va = isset($a[18]) && is_numeric($a[18]) ? (float)$a[18] : 1e18;
        $vb = isset($b[18]) && is_numeric($b[18]) ? (float)$b[18] : 1e18;
        if ($va == $vb) return 0;
        return ($va < $vb) ? -1 : 1;
    });

    // zapsat zpět (přepsat)
    $tmp = $targetPath . '.tmp_' . uniqid('', true);
    $fw = @fopen($tmp, 'w');
    if (!$fw) {
        rf_out($label . ': CHYBA - nelze zapsat tmp: ' . $tmp, $isAjax);
        return $res;
    }

    if ($hasHeader && is_array($header)) {
        fputcsv($fw, $header, $delim);
    }
    foreach ($rows as $r) {
        fputcsv($fw, $r, $delim);
    }
    fclose($fw);

    if (!@rename($tmp, $targetPath)) {
        @copy($tmp, $targetPath);
        @unlink($tmp);
    }
    @touch($targetPath);

    // publikovat do KE_STAZENI
    if (!is_dir($publishDir)) {
        @mkdir($publishDir, 0775, true);
    }
    $pubPath = rtrim($publishDir, '/\\') . '/' . basename($targetPath);
    @copy($targetPath, $pubPath);
    @touch($pubPath);
    $res['published_path'] = $pubPath;

    // stats json (pro tabulku "Změněno řádků")
    $stats = [
        'changed_rows' => $changedRows,
        'total_rows'   => $totalRows,
        'hits'         => $hits,
        'miss'         => $miss,
        'generated_at' => date('c'),
    ];
    @file_put_contents($pubPath . '.stats.json', json_encode($stats, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

    $mtime = @filemtime($pubPath);
    $mt = $mtime ? date('Y-m-d H:i:s', $mtime) : '-';
    rf_out($label . ': OK -> publikováno: ' . $pubPath . ' (mtime ' . $mt . ') | změněno řádků: ' . $changedRows . ' / ' . $totalRows . ' | hits: ' . $hits . ' miss: ' . $miss, $isAjax);

    $res['ok'] = true;
    $res['changed_rows'] = $changedRows;
    $res['total_rows'] = $totalRows;
    $res['hits'] = $hits;
    $res['miss'] = $miss;
    $res['header'] = $header;
    return $res;
}

// ------------------ 1) Spusť download_feeds.php beze změn ------------------ beze změn ------------------
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
if ($docRoot === '') $docRoot = realpath(__DIR__) ?: __DIR__;

$download = __DIR__ . '/download_feeds.php';

rf_out('__PROGRESS__|0|6|Spouštím...', $isAjax);

if (is_file($download)) {
    $_GET['ajax'] = '1';
    try { include $download; } catch (Throwable $e) { rf_out('CHYBA: výjimka v download_feeds.php: ' . $e->getMessage(), $isAjax); rf_out($e->getFile() . ':' . $e->getLine(), $isAjax); }
} else {
    rf_out('CHYBA: download_feeds.php nenalezen: ' . $download, $isAjax);
}

// ------------------ 2) Post-processing: použij ALL_XML.csv ------------------
rf_out('__PROGRESS__|6|6|POST: doplňuji soubory pro Ke stažení...', $isAjax);

$allXml = rf_find_all_xml($docRoot);
if (!$allXml) {
    rf_out('POST: CHYBA - nenašel jsem ALL_XML.csv (čekám ve složce /VSECHNY SPOJENE XML FEED/)', $isAjax);
    exit;
}
rf_out('POST: zdroj ALL_XML -> ' . $allXml, $isAjax);



// CSTORE_SKLAD override (EAN na našem skladě => S musí být 0, nikdy 1)
$cstoreSet = rf_build_cstore_instock_from_all_xml($allXml);

// Fallback: pokud existuje samostatný soubor skladu (generuje se z FTP exportu), přimícháme ho také
$cstoreFile = $docRoot . '/Dodavatele/SKLAD C-STORE/AktualizacniSkladCSTORE.csv';
if (is_file($cstoreFile)) {
    $cstoreSet = $cstoreSet + rf_build_cstore_instock_from_file($cstoreFile);
    rf_out('POST: CSTORE_SKLAD override -> načteno EAN (0) ze souboru: ' . $cstoreFile . ' | count=' . count($cstoreSet), $isAjax);
} else {
    rf_out('POST: CSTORE_SKLAD override -> EAN (0) načteno z ALL_XML | count=' . count($cstoreSet), $isAjax);
}

$GLOBALS['RF_CSTORE_INSTOCK'] = $cstoreSet;

$publishDir = $docRoot . '/Aktualizace_CSTORE/KE_STAZENI';

// cíle
$vavrysTarget  = $docRoot . '/Aktualizace_CSTORE/VAVRYS KOMPLET/VARIANTYIProduktyVavryskomplet_var.csv';
$silviniTarget = $docRoot . '/Aktualizace_CSTORE/Silvini/NEWNEWVariantySILVINI_var.csv';

// lookupy (preferované zdroje v ALL_XML)
$vavrysLookup  = rf_build_lookup($allXml, 'Vavrys');
$silviniLookup = rf_build_lookup($allXml, 'SILVINI');

$vRes = rf_update_var($docRoot, $vavrysTarget,  $publishDir, $vavrysLookup,  $isAjax, 'VAVRYS_KOMPLET');
$sRes = rf_update_var($docRoot, $silviniTarget, $publishDir, $silviniLookup, $isAjax, 'SILVINI_VAR');

// Hromadný soubor: pouze řádky, které se přepsaly (změnil se sloupec S)
$bulkName = 'AktualizovaneVarianty_var.csv';
$bulkPath = rtrim($publishDir, "/\\") . '/' . $bulkName;

$bulkRows = [];
if (isset($vRes['changed_data']) && is_array($vRes['changed_data'])) $bulkRows = array_merge($bulkRows, $vRes['changed_data']);
if (isset($sRes['changed_data']) && is_array($sRes['changed_data'])) $bulkRows = array_merge($bulkRows, $sRes['changed_data']);

$bulkHeader = (isset($vRes['header']) && is_array($vRes['header'])) ? $vRes['header'] : null;
$bulkDelim  = (isset($vRes['delim']) && is_string($vRes['delim']) && $vRes['delim'] !== '') ? $vRes['delim'] : ';';

$bulkCount = count($bulkRows);
if ($bulkCount > 0) {
    $hdrLen = $bulkHeader ? count($bulkHeader) : count($bulkRows[0]);

    // sjednotit délku řádků na délku hlavičky (hlavička musí odpovídat Vavrys souboru)
    foreach ($bulkRows as &$r) {
        $c = count($r);
        if ($c < $hdrLen) {
            for ($k = $c; $k < $hdrLen; $k++) $r[] = '';
        } elseif ($c > $hdrLen) {
            $r = array_slice($r, 0, $hdrLen);
        }
    }
    unset($r);

    // seřadit podle S (index 18) vzestupně
    usort($bulkRows, function($a, $b){
        $va = isset($a[18]) && is_numeric($a[18]) ? (float)$a[18] : 1e18;
        $vb = isset($b[18]) && is_numeric($b[18]) ? (float)$b[18] : 1e18;
        if ($va == $vb) return 0;
        return ($va < $vb) ? -1 : 1;
    });

    $tmpBulk = $bulkPath . '.tmp_' . uniqid('', true);
    $fw = @fopen($tmpBulk, 'w');
    if ($fw) {
        if ($bulkHeader) fputcsv($fw, $bulkHeader, $bulkDelim);
        foreach ($bulkRows as $r) fputcsv($fw, $r, $bulkDelim);
        fclose($fw);

        if (!@rename($tmpBulk, $bulkPath)) {
            @copy($tmpBulk, $bulkPath);
            @unlink($tmpBulk);
        }
        @touch($bulkPath);

        // stats json pro hromadný soubor (vždy changed=total, protože obsahuje jen změněné řádky)
        $stats = [
            'changed_rows' => $bulkCount,
            'total_rows'   => $bulkCount,
            'hits'         => (int)($vRes['hits'] ?? 0) + (int)($sRes['hits'] ?? 0),
            'miss'         => (int)($vRes['miss'] ?? 0) + (int)($sRes['miss'] ?? 0),
            'generated_at' => date('c'),
        ];
        @file_put_contents($bulkPath . '.stats.json', json_encode($stats, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        $mtime = @filemtime($bulkPath);
        $mt = $mtime ? date('Y-m-d H:i:s', $mtime) : '-';
        rf_out('AKTUALIZOVANE_VARIANTY: OK -> publikováno: ' . $bulkPath . ' (mtime ' . $mt . ') | řádků: ' . $bulkCount, $isAjax);
    } else {
        rf_out('AKTUALIZOVANE_VARIANTY: CHYBA - nelze zapsat: ' . $bulkPath, $isAjax);
    }
} else {
    rf_out('AKTUALIZOVANE_VARIANTY: OK -> žádné změněné řádky (soubor se nevytvořil / nepřepsal)', $isAjax);
}


rf_out('Hotovo. Obnov stránku (F5), aby se načetl nový čas a počty změn u souborů v "Ke stažení".', $isAjax);
?>