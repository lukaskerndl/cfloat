<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

function out(string $s): void { echo $s . "\n"; }

/**
 * FIXNÍ:
 * - Zdroj: /www/VSECHNY SPOJENE XML FEED/ALL_XML.csv  (B=klíč, C=hodnota)
 * - Cíl:   /www/Aktualizace_CSTORE/VAVRYS KOMPLET/VARIANTYIProduktyVavryskomplet_var.csv (Q=klíč -> S=zápis)
 *   (pro jistotu dovoluje i variantu bez "I" v názvu)
 *
 * PODMÍNKY:
 * - Hlavičku nikdy nepřepisovat (1. řádek raw)
 * - Měnit pouze sloupec S (index 18, 0-based)
 * - Když se klíč nenajde ve zdroji => do S zapsat "1"
 * - Po doplnění seřadit řádky (mimo hlavičku) podle sloupce S vzestupně (numericky)
 */

$base = __DIR__;

$sourcePath = $base . '/VSECHNY SPOJENE XML FEED/ALL_XML.csv';
$targetDir  = $base . '/Aktualizace_CSTORE/VAVRYS KOMPLET/';

$targetCandidates = [
    $targetDir . 'VARIANTYIProduktyVavryskomplet_var.csv',
    $targetDir . 'VARIANTYProduktyVavryskomplet_var.csv',
];

$targetPath = '';
foreach ($targetCandidates as $p) {
    if (is_file($p)) { $targetPath = $p; break; }
}

out("STATUS: START");
out("Zdroj: $sourcePath");
out("Cíl:   " . ($targetPath ?: ($targetCandidates[0] . " (nenalezen)")));

if (!is_file($sourcePath)) {
    out("STATUS: CHYBA");
    out("CHYBA: Zdrojový soubor neexistuje: $sourcePath");
    out("KONEC");
    exit;
}
if ($targetPath === '' || !is_file($targetPath)) {
    out("STATUS: CHYBA");
    out("CHYBA: Cílový soubor neexistuje: " . $targetCandidates[0]);
    out("KONEC");
    exit;
}
if (!is_readable($sourcePath)) {
    out("STATUS: CHYBA");
    out("CHYBA: Nemám právo číst zdroj: $sourcePath");
    out("KONEC");
    exit;
}
if (!is_readable($targetPath) || !is_writable($targetDir)) {
    out("STATUS: CHYBA");
    out("CHYBA: Nemám práva číst/zapisovat v cílovém adresáři: $targetDir");
    out("KONEC");
    exit;
}

function detect_delimiter(string $line): string {
    $sc = substr_count($line, ';');
    $cc = substr_count($line, ',');
    $tc = substr_count($line, "\t");
    if ($sc >= $cc && $sc >= $tc) return ';';
    if ($cc >= $sc && $cc >= $tc) return ',';
    return "\t";
}

function ensure_index(array &$row, int $idx): void {
    $len = count($row);
    if ($len <= $idx) {
        for ($i = $len; $i <= $idx; $i++) $row[$i] = '';
    }
}

function to_float_or_inf(string $s): float {
    $s = trim($s);
    if ($s === '') return INF;
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return INF;
    return (float)$s;
}

function csv_line_from_row(array $row, string $delimiter): string {
    $fp = fopen('php://temp', 'w+');
    if (!$fp) return '';
    fputcsv($fp, $row, $delimiter);
    rewind($fp);
    $line = stream_get_contents($fp);
    fclose($fp);
    return $line === false ? '' : $line;
}

/** chunk writer: uloží seřazený chunk do temp souboru */
function write_chunk(array $chunk, array &$chunkFiles, int $idx): void {
    usort($chunk, function($a, $b) {
        // $a = [sortKeyFloat, csvLine]
        if ($a[0] == $b[0]) return 0;
        return ($a[0] < $b[0]) ? -1 : 1;
    });

    $path = sys_get_temp_dir() . "/cfloat_sort_chunk_" . getmypid() . "_$idx.tmp";
    $fh = fopen($path, 'wb');
    if (!$fh) {
        throw new RuntimeException("Nelze vytvořit chunk: $path");
    }

    foreach ($chunk as $item) {
        $k = $item[0];
        $csvLine = $item[1];
        $kStr = is_infinite($k) ? "INF" : sprintf('%.15F', $k);
        fwrite($fh, $kStr . "\t" . $csvLine);
    }
    fclose($fh);

    $chunkFiles[] = $path;
}

/** merge chunk files do výstupu (už otevřeného) */
function merge_chunks(array $chunkFiles, $outFh): void {
    $pq = new SplPriorityQueue();
    $pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

    $handles = [];

    foreach ($chunkFiles as $i => $path) {
        $fh = fopen($path, 'rb');
        if (!$fh) continue;
        $handles[$i] = $fh;

        $line = fgets($fh);
        if ($line === false) continue;

        $tabPos = strpos($line, "\t");
        if ($tabPos === false) continue;

        $kStr = substr($line, 0, $tabPos);
        $csvLine = substr($line, $tabPos + 1);

        $k = ($kStr === 'INF') ? INF : (float)$kStr;
        $priority = is_infinite($k) ? -INF : -$k;

        $pq->insert(['i' => $i, 'csv' => $csvLine], $priority);
    }

    while (!$pq->isEmpty()) {
        $ex = $pq->extract();
        $data = $ex['data'];

        $i = $data['i'];
        $csvLine = $data['csv'];

        fwrite($outFh, $csvLine);

        $fh = $handles[$i] ?? null;
        if ($fh) {
            $next = fgets($fh);
            if ($next !== false) {
                $tabPos = strpos($next, "\t");
                if ($tabPos !== false) {
                    $kStr2 = substr($next, 0, $tabPos);
                    $csvLine2 = substr($next, $tabPos + 1);

                    $k2 = ($kStr2 === 'INF') ? INF : (float)$kStr2;
                    $priority2 = is_infinite($k2) ? -INF : -$k2;

                    $pq->insert(['i' => $i, 'csv' => $csvLine2], $priority2);
                }
            }
        }
    }

    foreach ($handles as $fh) { @fclose($fh); }
}

$IDX_Q = 16; // Q
$IDX_S = 18; // S
$IDX_B = 1;  // B ve zdroji
$IDX_C = 2;  // C ve zdroji

// ========== 1) cílová hlavička RAW + delimiter + klíče z Q ==========
$tfhRaw = fopen($targetPath, 'rb');
if (!$tfhRaw) {
    out("STATUS: CHYBA");
    out("CHYBA: Nelze otevřít cíl: $targetPath");
    out("KONEC");
    exit;
}
$headerRaw = fgets($tfhRaw);
if ($headerRaw === false) {
    fclose($tfhRaw);
    out("STATUS: CHYBA");
    out("CHYBA: Cílový soubor je prázdný: $targetPath");
    out("KONEC");
    exit;
}
$targetDelim = detect_delimiter($headerRaw);

$needed = [];
$totalRows = 0;
$emptyKeyRows = 0;

while (($row = fgetcsv($tfhRaw, 0, $targetDelim)) !== false) {
    $totalRows++;
    $key = trim((string)($row[$IDX_Q] ?? ''));
    if ($key === '') { $emptyKeyRows++; continue; }
    $needed[$key] = true;
}
fclose($tfhRaw);

out("Cíl načten: řádků (bez hlavičky)=$totalRows, bez Q=$emptyKeyRows, unikátních klíčů=" . count($needed));

// ========== 2) načti mapu z ALL_XML (B->C) jen pro potřebné klíče ==========
$sfhRaw = fopen($sourcePath, 'rb');
if (!$sfhRaw) {
    out("STATUS: CHYBA");
    out("CHYBA: Nelze otevřít zdroj: $sourcePath");
    out("KONEC");
    exit;
}
$srcHeader = fgets($sfhRaw);
if ($srcHeader === false) {
    fclose($sfhRaw);
    out("STATUS: CHYBA");
    out("CHYBA: Zdrojový soubor je prázdný: $sourcePath");
    out("KONEC");
    exit;
}
$sourceDelim = detect_delimiter($srcHeader);

$map = [];
$srcRows = 0;
$hits = 0;

while (($row = fgetcsv($sfhRaw, 0, $sourceDelim)) !== false) {
    $srcRows++;
    $k = trim((string)($row[$IDX_B] ?? ''));
    if ($k === '' || !isset($needed[$k])) continue;
    $v = trim((string)($row[$IDX_C] ?? ''));
    $map[$k] = $v;
    $hits++;
}
fclose($sfhRaw);

out("Zdroj načten: řádků (bez hlavičky)=$srcRows, shod=$hits");

// ========== 3) projdi cíl, změň pouze S, a vytvoř chunk soubory pro sort ==========
$ts = date('Ymd_His');
$backup = $targetPath . ".bak_$ts";
$tmpFinal = $targetPath . ".tmp_sorted_$ts";

if (!copy($targetPath, $backup)) {
    out("STATUS: CHYBA");
    out("CHYBA: Nepodařilo se vytvořit zálohu: $backup");
    out("KONEC");
    exit;
}

$tfh = fopen($targetPath, 'rb');
if (!$tfh) {
    out("STATUS: CHYBA");
    out("CHYBA: Nelze otevřít cíl pro čtení: $targetPath");
    out("KONEC");
    exit;
}

// zahod hlavičku (raw už máme)
fgets($tfh);

$processed = 0;
$changed = 0;
$overwritten = 0;
$filledEmpty = 0;
$defaulted = 0;
$matched = 0;
$unchanged = 0;

$chunkSize = 5000;
$chunk = [];
$chunkFiles = [];
$chunkIdx = 0;

try {
    while (($row = fgetcsv($tfh, 0, $targetDelim)) !== false) {
        $processed++;

        ensure_index($row, $IDX_S); // aby existovalo S

        $key  = trim((string)($row[$IDX_Q] ?? ''));
        $oldS = trim((string)($row[$IDX_S] ?? ''));

        if ($key !== '') {
            if (array_key_exists($key, $map)) {
                $newS = (string)$map[$key];
                $matched++;
            } else {
                $newS = '1';
                $defaulted++;
            }

            // měníme jen S
            $row[$IDX_S] = $newS;

            if ($newS !== $oldS) {
                $changed++;
                if ($oldS === '' && $newS !== '') $filledEmpty++;
                if ($oldS !== '' && $newS !== $oldS) $overwritten++;
            } else {
                $unchanged++;
            }
        } else {
            // bez klíče v Q – nic neměnit
            $unchanged++;
        }

        // sort key = S numeric, non-numeric => INF (na konec)
        $sortKey = to_float_or_inf((string)$row[$IDX_S]);
        $csvLine = csv_line_from_row($row, $targetDelim);

        $chunk[] = [$sortKey, $csvLine];

        if (count($chunk) >= $chunkSize) {
            write_chunk($chunk, $chunkFiles, $chunkIdx);
            $chunk = [];
            $chunkIdx++;
        }
    }

    if (count($chunk) > 0) {
        write_chunk($chunk, $chunkFiles, $chunkIdx);
        $chunk = [];
    }
} catch (Throwable $e) {
    fclose($tfh);
    out("STATUS: CHYBA");
    out("CHYBA: " . $e->getMessage());
    out("KONEC");
    exit;
}

fclose($tfh);

out("Chunk souborů: " . count($chunkFiles));

// ========== 4) merge chunků do finálu (hlavička raw beze změny) ==========
$outFh = fopen($tmpFinal, 'wb');
if (!$outFh) {
    out("STATUS: CHYBA");
    out("CHYBA: Nelze vytvořit temp výstup: $tmpFinal");
    out("KONEC");
    exit;
}

// HLAVIČKU píšeme RAW, nikdy nepřepisovat
fwrite($outFh, $headerRaw);

try {
    merge_chunks($chunkFiles, $outFh);
} catch (Throwable $e) {
    fclose($outFh);
    out("STATUS: CHYBA");
    out("CHYBA: " . $e->getMessage());
    out("KONEC");
    exit;
}

fclose($outFh);

// cleanup chunk files
foreach ($chunkFiles as $p) { @unlink($p); }

// přepiš cíl atomicky
if (!rename($tmpFinal, $targetPath)) {
    out("STATUS: CHYBA");
    out("CHYBA: Nepodařilo se přepsat cílový soubor. Temp zůstává: $tmpFinal");
    out("KONEC");
    exit;
}

out("STATUS: DOKONČENO");
out("Souhrn:");
out("- zpracováno řádků cíle (bez hlavičky): $processed");
out("- nalezeno ve zdroji: $matched");
out("- nenalezeno → doplněno '1': $defaulted");
out("- skutečně změněno řádků ve S: $changed");
out("  - doplněno do prázdného S: $filledEmpty");
out("  - přepsáno (S už mělo hodnotu): $overwritten");
out("- beze změny: $unchanged");
out("- seřazeno: dle sloupce S vzestupně (hlavička zachována RAW)");
out("Záloha: $backup");
out("KONEC");
