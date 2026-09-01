<?php
/**
 * fill_target_column_S.php
 *
 * Zdroj: /VSECHNY SPOJENE XML FEED/ALL_XML.csv
 *  - klíč: sloupec B (index 1)
 *  - hodnota: sloupec C (index 2)
 *
 * Cíl: libovolný CSV soubor (parametr ?target=...)
 *  - klíč: sloupec Q (index 16)
 *  - zapisuje se do: sloupec S (index 18)
 *
 * Výkon: nejdřív načte unikátní klíče z cíle (Q), pak projde zdroj a vytáhne jen potřebné.
 */

declare(strict_types=1);

session_start();
if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

@set_time_limit(0);

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

$sourceRel = 'VSECHNY SPOJENE XML FEED/ALL_XML.csv';
$sourceAbs = $docRoot . '/' . $sourceRel;

$targetRel = isset($_GET['target']) ? trim((string)$_GET['target']) : '';
$hasHeader = isset($_GET['header']) ? ((string)$_GET['header'] === '1') : true;

function out(string $s): void {
    echo $s;
    if (function_exists('ob_flush')) @ob_flush();
    if (function_exists('flush')) @flush();
}

function detect_delim(string $absFile): string {
    $fh = @fopen($absFile, 'rb');
    if (!$fh) return ';';
    $line = '';
    // vezmeme první neprázdný řádek
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

function safe_rel_path(string $rel): string {
    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');
    if ($rel === '') return '';
    if (str_contains($rel, "..")) return '';
    return $rel;
}

$targetRel = safe_rel_path($targetRel);

header('Content-Type: text/plain; charset=UTF-8');

if (!is_file($sourceAbs)) {
    out("CHYBA: Zdrojový soubor neexistuje: /{$sourceRel}\n");
    exit;
}

if ($targetRel === '') {
    out("CHYBA: Chybí parametr target.\n");
    out("Příklad: fill_target_column_S.php?target=Dodavatele/Vavrys/vavrys_variants.csv\n");
    exit;
}

$targetAbs = $docRoot . '/' . $targetRel;
if (!is_file($targetAbs)) {
    out("CHYBA: Cílový soubor neexistuje: /{$targetRel}\n");
    exit;
}

$sourceDelim = detect_delim($sourceAbs);
$targetDelim = detect_delim($targetAbs);

out("START\n");
out("Zdroj : /{$sourceRel} (delim: " . ($sourceDelim === "\t" ? 'TAB' : $sourceDelim) . ")\n");
out("Cíl  : /{$targetRel} (delim: " . ($targetDelim === "\t" ? 'TAB' : $targetDelim) . ")\n");
out("Hlavička v cíli: " . ($hasHeader ? 'ANO' : 'NE') . "\n\n");

// Sloupce (0-based)
$SRC_KEY = 1;   // B
$SRC_VAL = 2;   // C
$TGT_KEY = 16;  // Q
$TGT_SET = 18;  // S

// 1) první průchod cílem: nasbírat potřebné klíče
out("1/3 Načítám klíče z cílového CSV (sloupec Q)…\n");
$need = [];
$needCount = 0;

// statistiky cíle
$targetRowsTotal = 0;   // včetně hlavičky
$targetRowsData  = 0;   // bez hlavičky
$targetRowsHeader = 0;

$fh = @fopen($targetAbs, 'rb');
if (!$fh) {
    out("CHYBA: Nelze otevřít cíl pro čtení.\n");
    exit;
}

$rowNo = 0;
while (($row = fgetcsv($fh, 0, $targetDelim)) !== false) {
    $rowNo++;

    $targetRowsTotal++;
    if ($rowNo === 1 && $hasHeader) {
        $targetRowsHeader = 1;
        continue;
    }
    $targetRowsData++;

    $k = trim((string)($row[$TGT_KEY] ?? ''));
    if ($k === '') continue;

    if (!isset($need[$k])) {
        $need[$k] = true;
        $needCount++;
        if (($needCount % 200000) === 0) {
            out("  …klíčů: {$needCount}\n");
        }
    }
}

fclose($fh);
out("OK – unikátních klíčů v cíli: {$needCount}\n\n");
out("Cíl – řádků celkem: {$targetRowsTotal} (data: {$targetRowsData}" . ($hasHeader ? ", +hlavička" : "") . ")\n\n");

if ($needCount === 0) {
    out("NIC K DOPLNĚNÍ: v cíli není žádný klíč ve sloupci Q.\n");
    exit;
}

// 2) průchod zdrojem: vytáhnout jen potřebné dvojice
out("2/3 Procházím zdroj a vytahuju hodnoty (B->C) jen pro potřebné klíče…\n");
$map = [];
$found = 0;
$checked = 0;
$remaining = $needCount;

$fh = @fopen($sourceAbs, 'rb');
if (!$fh) {
    out("CHYBA: Nelze otevřít zdroj pro čtení.\n");
    exit;
}

$rowNo = 0;
while (($row = fgetcsv($fh, 0, $sourceDelim)) !== false) {
    $rowNo++;
    $checked++;

    // přeskoč hlavičku
    if ($rowNo === 1) {
        $first = mb_strtolower(trim((string)($row[0] ?? '')), 'UTF-8');
        if ($first === 'zdroj') {
            continue;
        }
    }

    $k = trim((string)($row[$SRC_KEY] ?? ''));
    if ($k === '' || !isset($need[$k])) {
        if (($checked % 500000) === 0) {
            out("  …řádků zdroje: {$checked} | nalezeno: {$found} | zbývá: {$remaining}\n");
        }
        continue;
    }

    if (!isset($map[$k])) {
        $v = (string)($row[$SRC_VAL] ?? '');
        $map[$k] = $v;
        $found++;
        $remaining--;

        // optimalizace: uvolnit z need
        unset($need[$k]);

        if (($found % 50000) === 0) {
            out("  …nalezeno: {$found} | zbývá: {$remaining}\n");
        }

        if ($remaining <= 0) {
            break;
        }
    }
}

fclose($fh);
out("OK – nalezených mapování: {$found}\n\n");

// 3) druhý průchod cílem: zapsat S
out("3/3 Zapisuju do cíle (Q->S)…\n");

$dir = dirname($targetAbs);
$base = basename($targetAbs);
$ts = date('Ymd_His');
$bakAbs = $dir . '/' . $base . '.bak_' . $ts;
$tmpAbs = $dir . '/' . $base . '.tmp_' . $ts;

if (!@copy($targetAbs, $bakAbs)) {
    out("CHYBA: Nepodařilo se vytvořit zálohu: {$bakAbs}\n");
    exit;
}

$in = @fopen($targetAbs, 'rb');
if (!$in) {
    out("CHYBA: Nelze otevřít cíl pro čtení (2. průchod).\n");
    exit;
}

$outF = @fopen($tmpAbs, 'wb');
if (!$outF) {
    fclose($in);
    out("CHYBA: Nelze otevřít dočasný soubor pro zápis: {$tmpAbs}\n");
    exit;
}

$rowNo = 0;
$written = 0;
$matched = 0;          // řádky se shodou (Q nalezeno ve zdroji)
$defaulted = 0;        // řádky bez shody (Q nenalezeno) -> doplníme '1'
$noKey = 0;            // řádky bez klíče v Q
$changed = 0;          // skutečně změněné hodnoty v S
$unchanged = 0;        // hodnoty v S už byly stejné

// detail změn ve sloupci S
$filledEmpty = 0;      // S bylo prázdné a doplnilo se (nové != '' && staré == '')
$overwritten = 0;      // S už mělo hodnotu a změnilo se (staré != '' && nové != staré)
$forcedOne = 0;        // kolikrát jsme nastavili přesně '1' (nenalezeno)

while (($row = fgetcsv($in, 0, $targetDelim)) !== false) {
    $rowNo++;

    if ($rowNo === 1 && $hasHeader) {
        fputcsv($outF, $row, $targetDelim);
        $written++;
        continue;
    }

    // doplň pole do délky (musí být sekvenční 0..S, jinak fputcsv může "useknout" konec)
    for ($i = count($row); $i <= $TGT_SET; $i++) {
        $row[$i] = '';
    }

    $k = trim((string)($row[$TGT_KEY] ?? ''));
    $oldS = (string)($row[$TGT_SET] ?? '');

    // Logika:
    // - Q prázdné -> nic nedoplňujeme
    // - Q existuje a je v map -> doplníme hodnotu ze zdroje
    // - Q existuje, ale není v map -> doplníme '1'
    if ($k === '') {
        $noKey++;
    } elseif (isset($map[$k])) {
        $newS = (string)$map[$k];
        $row[$TGT_SET] = $newS;
        $matched++;
        if ($newS !== $oldS) {
            $changed++;
            if (trim($oldS) === '') $filledEmpty++; else $overwritten++;
        } else {
            $unchanged++;
        }
    } else {
        $newS = '1';
        $row[$TGT_SET] = $newS;
        $defaulted++;
        $forcedOne++;
        if ($newS !== $oldS) {
            $changed++;
            if (trim($oldS) === '') $filledEmpty++; else $overwritten++;
        } else {
            $unchanged++;
        }
    }

    fputcsv($outF, $row, $targetDelim);
    $written++;

    if (($written % 200000) === 0) {
        out("  …zapsáno řádků: {$written} | shoda: {$matched} | default '1': {$defaulted} | změny: {$changed}\n");
    }
}

fclose($in);
fclose($outF);

// přepsání cíle
if (!@rename($tmpAbs, $targetAbs)) {
    // fallback na Windows / některé FS
    @unlink($targetAbs);
    if (!@rename($tmpAbs, $targetAbs)) {
        out("CHYBA: Nepodařilo se přepsat cílový soubor. Dočasný zůstává: {$tmpAbs}\n");
        exit;
    }
}

// shrnutí
$headerRows = ($hasHeader ? 1 : 0);
$dataRows = max(0, $written - $headerRows);

$completed = (is_file($targetAbs) && filesize($targetAbs) > 0 && $dataRows === $targetRowsData);

out("\nHOTOVO\n");
out("STATUS: " . ($completed ? "DOKONČENO" : "VAROVÁNÍ – zkontroluj výstup") . "\n");
out("Cíl přepsán: /{$targetRel}\n");
out("Záloha: " . str_replace($docRoot . '/', '/', $bakAbs) . "\n");
out("Řádků celkem zapsáno: {$written} (data: {$dataRows}" . ($hasHeader ? ", +hlavička" : "") . ")\n");
out("Kontrola počtu řádků: očekáváno data={$targetRowsData}, zapsáno data={$dataRows}\n");
out("Shoda (Q nalezeno ve zdroji) -> doplněno ze zdroje: {$matched}\n");
out("Bez shody (Q nenalezeno) -> doplněno hodnotou '1': {$defaulted}\n");
out("Řádků bez klíče v Q (nezměněno): {$noKey}\n");
out("Skutečně změněno ve sloupci S: {$changed}\n");
out("  - doplněno do prázdného S: {$filledEmpty}\n");
out("  - přepsáno (S už mělo hodnotu): {$overwritten}\n");
out("Beze změny (S už bylo stejné): {$unchanged}\n");
out("KONEC\n");
