<?php
/**
 * fill_target_column_S.php
 *
 * Použití v cfloat:
 *  - Zdroj: /VSECHNY SPOJENE XML FEED/ALL_XML.csv
 *      * klíč   = sloupec B (index 1)
 *      * hodnota= sloupec C (index 2)
 *  - Cíl: CSV soubor z parametru ?target=...
 *      * klíč   = sloupec Q (index 16)
 *      * zapis  = sloupec S (index 18)
 *
 * Pravidla:
 *  - Najdi hodnotu pro klíč z cíle (Q) ve zdroji (B) a zapiš do cíle do S.
 *  - Pokud se klíč ve zdroji nenajde (a Q není prázdné), zapiš do S hodnotu "1".
 *
 * Výstup:
 *  - počty: změněno / přepsáno / doplněno do prázdného / default "1" / bez klíče / dokončeno
 */

declare(strict_types=1);

session_start();
if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "STATUS: CHYBA\nForbidden\n";
    exit;
}

@set_time_limit(0);
header('Content-Type: text/plain; charset=UTF-8');

function out(string $s): void {
    echo $s;
    if (function_exists('ob_flush')) @ob_flush();
    if (function_exists('flush')) @flush();
}

function detect_delim(string $absFile): string {
    $fh = @fopen($absFile, 'rb');
    if (!$fh) return ';';
    $line = '';
    while (!feof($fh)) {
        $l = fgets($fh);
        if ($l === false) break;
        $l = trim($l);
        if ($l === '') continue;
        $line = $l;
        break;
    }
    fclose($fh);
    if ($line === '') return ';';

    $c1 = substr_count($line, ';');
    $c2 = substr_count($line, ',');
    $c3 = substr_count($line, "\t");

    if ($c3 >= $c1 && $c3 >= $c2) return "\t";
    if ($c1 >= $c2) return ';';
    return ',';
}

function safe_rel_path(string $p): string {
    $p = str_replace('\\', '/', trim($p));

    // Povolit i "/www/..." nebo "www/..." – převést na relativní vůči DOCUMENT_ROOT
    if (stripos($p, '/www/') === 0) $p = substr($p, 5);
    if (stripos($p, 'www/') === 0)  $p = substr($p, 4);

    $p = ltrim($p, '/');
    if ($p === '') return '';
    if (strpos($p, '..') !== false) return '';
    return $p;
}

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

// ---- nastavení zdroje (fixně, ale lze přepsat parametrem ?source= ) ----
$sourceRel = safe_rel_path(isset($_GET['source']) ? (string)$_GET['source'] : 'VSECHNY SPOJENE XML FEED/ALL_XML.csv');
if ($sourceRel === '') $sourceRel = 'VSECHNY SPOJENE XML FEED/ALL_XML.csv';
$sourceAbs = $docRoot . '/' . $sourceRel;

// ---- cíl ----
$targetRel = safe_rel_path(isset($_GET['target']) ? (string)$_GET['target'] : '');
$hasHeader = isset($_GET['header']) ? ((string)$_GET['header'] === '1') : true;

if (!is_file($sourceAbs)) {
    out("STATUS: CHYBA\nCHYBA: Zdrojový soubor neexistuje: /{$sourceRel}\n");
    exit;
}
if ($targetRel === '') {
    out("STATUS: CHYBA\nCHYBA: Chybí parametr target.\n");
    out("Příklad: fill_target_column_S.php?target=Aktualizace_CSTORE/VAVRYS%20KOMPLET/VARIANTYProduktyVavryskomplet_var.csv\n");
    exit;
}

$targetAbs = $docRoot . '/' . $targetRel;
if (!is_file($targetAbs)) {
    out("STATUS: CHYBA\nCHYBA: Cílový soubor neexistuje: /{$targetRel}\n");
    exit;
}

// Sloupce (0-based)
$SRC_KEY = 1;    // B
$SRC_VAL = 2;    // C
$TGT_KEY = 16;   // Q
$TGT_SET = 18;   // S
$DEFAULT_NOT_FOUND = '1';

$sourceDelim = detect_delim($sourceAbs);
$targetDelim = detect_delim($targetAbs);

out("STATUS: BEZI\nSTART\n");
out("Zdroj : /{$sourceRel} (delim: " . ($sourceDelim === "\t" ? 'TAB' : $sourceDelim) . ")\n");
out("Cíl  : /{$targetRel} (delim: " . ($targetDelim === "\t" ? 'TAB' : $targetDelim) . ")\n");
out("Hlavička v cíli: " . ($hasHeader ? 'ANO' : 'NE') . "\n");
out("Pravidlo: Q→S podle B→C; nenalezeno => S='{$DEFAULT_NOT_FOUND}'\n\n");

// 1) klíče z cíle
out("1/3 Načítám unikátní klíče z cíle (sloupec Q)…\n");
$need = [];
$needCount = 0;

$fh = @fopen($targetAbs, 'rb');
if (!$fh) {
    out("STATUS: CHYBA\nCHYBA: Nelze otevřít cíl pro čtení.\n");
    exit;
}

$rowNo = 0;
while (($row = fgetcsv($fh, 0, $targetDelim)) !== false) {
    $rowNo++;
    if ($rowNo === 1 && $hasHeader) continue;

    $k = trim((string)($row[$TGT_KEY] ?? ''));
    if ($k === '') continue;

    if (!isset($need[$k])) {
        $need[$k] = true;
        $needCount++;
        if (($needCount % 200000) === 0) out("  …klíčů: {$needCount}\n");
    }
}
fclose($fh);

out("OK – unikátních klíčů v cíli: {$needCount}\n\n");
if ($needCount === 0) {
    out("STATUS: DOKONČENO\nNIC K DOPLNĚNÍ: v cíli není žádný klíč ve sloupci Q.\nKONEC\n");
    exit;
}

// 2) mapování ze zdroje jen pro potřebné klíče
out("2/3 Procházím zdroj a vytahuju hodnoty (B→C) jen pro klíče z cíle…\n");
$map = [];
$found = 0;
$checked = 0;
$remaining = $needCount;

$fh = @fopen($sourceAbs, 'rb');
if (!$fh) {
    out("STATUS: CHYBA\nCHYBA: Nelze otevřít zdroj pro čtení.\n");
    exit;
}

$rowNo = 0;
while (($row = fgetcsv($fh, 0, $sourceDelim)) !== false) {
    $rowNo++;
    $checked++;

    // přeskoč hlavičku (ALL_XML.csv má v A "Zdroj")
    if ($rowNo === 1) {
        $first = mb_strtolower(trim((string)($row[0] ?? '')), 'UTF-8');
        if ($first === 'zdroj') continue;
    }

    $k = trim((string)($row[$SRC_KEY] ?? ''));
    if ($k === '' || !isset($need[$k])) {
        if (($checked % 500000) === 0) out("  …řádků zdroje: {$checked} | nalezeno: {$found} | zbývá: {$remaining}\n");
        continue;
    }

    if (!isset($map[$k])) {
        $map[$k] = (string)($row[$SRC_VAL] ?? '');
        $found++;
        $remaining--;
        unset($need[$k]);

        if (($found % 50000) === 0) out("  …nalezeno: {$found} | zbývá: {$remaining}\n");
        if ($remaining <= 0) break;
    }
}
fclose($fh);

out("OK – nalezených mapování ve zdroji: {$found}\n");
out("Nenalezené klíče ve zdroji budou mít v cíli S='{$DEFAULT_NOT_FOUND}'.\n\n");

// 3) přepis cíle: Q→S + default 1, a počty změn
out("3/3 Zapisuju do cíle (Q→S)…\n");

$dir  = dirname($targetAbs);
$base = basename($targetAbs);
$ts   = date('Ymd_His');

$bakAbs = $dir . '/' . $base . '.bak_' . $ts;
$tmpAbs = $dir . '/' . $base . '.tmp_' . $ts;

if (!@copy($targetAbs, $bakAbs)) {
    out("STATUS: CHYBA\nCHYBA: Nepodařilo se vytvořit zálohu: {$bakAbs}\n");
    exit;
}

$in = @fopen($targetAbs, 'rb');
if (!$in) {
    out("STATUS: CHYBA\nCHYBA: Nelze otevřít cíl pro čtení (2. průchod).\n");
    exit;
}

$outF = @fopen($tmpAbs, 'wb');
if (!$outF) {
    fclose($in);
    out("STATUS: CHYBA\nCHYBA: Nelze otevřít dočasný soubor pro zápis: {$tmpAbs}\n");
    exit;
}

$rowNo = 0;

// metriky
$totalDataRows = 0;
$changed = 0;
$overwritten = 0;
$filledEmpty = 0;
$defaulted = 0;
$fromSource = 0;
$missingKey = 0;
$unchanged = 0;

while (($row = fgetcsv($in, 0, $targetDelim)) !== false) {
    $rowNo++;

    if ($rowNo === 1 && $hasHeader) {
        fputcsv($outF, $row, $targetDelim);
        continue;
    }

    $totalDataRows++;

    $k = trim((string)($row[$TGT_KEY] ?? ''));
    if ($k === '') {
        $missingKey++;
        fputcsv($outF, $row, $targetDelim);
        continue;
    }

    // zajistit délku řádku alespoň do S
    for ($i = count($row); $i <= $TGT_SET; $i++) {
        $row[$i] = '';
    }

    $oldS = (string)($row[$TGT_SET] ?? '');
    $newS = $DEFAULT_NOT_FOUND;

    if (isset($map[$k])) {
        $newS = (string)$map[$k];
        $fromSource++;
    } else {
        $defaulted++;
    }

    if ($oldS !== $newS) {
        $changed++;
        if (trim((string)$oldS) === '') $filledEmpty++;
        else $overwritten++;
        $row[$TGT_SET] = $newS;
    } else {
        $unchanged++;
        $row[$TGT_SET] = $newS; // necháme beze změny, ale sjednotíme zápis
    }

    fputcsv($outF, $row, $targetDelim);

    if (($totalDataRows % 200000) === 0) {
        out("  …řádků: {$totalDataRows} | změněno: {$changed} | přepsáno: {$overwritten} | doplněno: {$filledEmpty}\n");
    }
}

fclose($in);
fclose($outF);

// přepsání cíle
if (!@rename($tmpAbs, $targetAbs)) {
    @unlink($targetAbs);
    if (!@rename($tmpAbs, $targetAbs)) {
        out("STATUS: CHYBA\nCHYBA: Nepodařilo se přepsat cílový soubor. Dočasný zůstává: {$tmpAbs}\n");
        exit;
    }
}

$bakRel = str_replace($docRoot . '/', '/', $bakAbs);

out("\nSTATUS: DOKONČENO\n");
out("Cíl přepsán: /{$targetRel}\n");
out("Záloha: {$bakRel}\n\n");

out("Souhrn:\n");
out("- Data řádků (bez hlavičky): {$totalDataRows}\n");
out("- Skutečně změněno řádků ve sloupci S: {$changed}\n");
out("  - doplněno do prázdného S: {$filledEmpty}\n");
out("  - přepsáno (S už mělo hodnotu): {$overwritten}\n");
out("- Nezměněno (S už bylo stejné): {$unchanged}\n");
out("- Shoda ve zdroji (ALL_XML): {$fromSource}\n");
out("- Bez shody ve zdroji → doplněno '{$DEFAULT_NOT_FOUND}': {$defaulted}\n");
out("- Řádků bez klíče v Q: {$missingKey}\n");

out("\nKONEC\n");
