<?php
session_start();
const UVAV_VERSION = '2026-06-09-MANUAL-SUPPLIERS-VAVRYS-SILVINI-ALE-ISADORE';
const UVAV_Q_EAN_COL_INDEX = 16; // Q = EAN kod ve variantách
const UVAV_S_TARGET_COL_INDEX = 18; // S = Tento produkt nezobrazovat v eshopu ve variantách
const UVAV_B_PRODUCT_CODE_COL_INDEX = 1; // B = Kod vyrobku
const UVAV_AO_MAIN_TARGET_COL_INDEX = 40; // AO = Tento produkt nezobrazovat v eshopu v hlavním souboru
const UVAV_W_EAN_COL_INDEX = 22; // W = dlouhé číslo/EAN, pokud je vyplněné
const UVAV_FOLDER = 'RucniNahraniAktualizace';

$supplierConfigs = [
    'vavrys' => [
        'title' => 'VAVRYS',
        'anchor' => 'vavrys-aktualizace',
        'main_file' => 'NEwHlavniVavrys.csv',
        'var_file' => 'NEWVavrysVariantyALL_var.csv',
    ],
    'silvini' => [
        'title' => 'SILVINI',
        'anchor' => 'silvini-aktualizace',
        'main_file' => 'HlavniSilviniSS2026.csv',
        'var_file' => 'VariantySILVINI_var.csv',
    ],
    'ale' => [
        'title' => 'ALÉ',
        'anchor' => 'ale-aktualizace',
        'main_file' => 'HLAVNIAlea.csv',
        'var_file' => 'VariantyAlea_var.csv',
    ],
    'isadore' => [
        'title' => 'ISADORE',
        'anchor' => 'isadore-aktualizace',
        'main_file' => 'HlavniIsadore.csv',
        'var_file' => 'VARIANTYYIsadore_var.csv',
    ],
];

if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    echo 'Nepřihlášeno.';
    exit;
}

function uvav_redirect(array $params, string $anchor = 'vavrys-aktualizace'): void {
    $base = 'index.php?view=xmlfeedy';
    if ($params) $base .= '&' . http_build_query($params);
    if ($anchor !== '') $base .= '#' . rawurlencode($anchor);
    header('Location: ' . $base);
    exit;
}

function uvav_safe_filename(string $name): string {
    $name = basename($name);
    $name = preg_replace('~[^A-Za-z0-9._-]+~u', '_', $name);
    $name = trim((string)$name, '._-');
    return $name;
}

function uvav_safe_supplier_key(string $key): string {
    $key = strtolower(trim($key));
    $key = preg_replace('~[^a-z0-9_-]+~', '', $key);
    return $key !== '' ? $key : 'vavrys';
}

function uvav_ensure_dir(string $dir): bool {
    return is_dir($dir) || @mkdir($dir, 0775, true);
}

function uvav_norm_lower(string $s): string {
    if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
    return strtolower($s);
}

function uvav_detect_delim_from_file(string $file): string {
    $sample = @file_get_contents($file, false, null, 0, 32768);
    if ($sample === false) return ';';
    $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);
    $cands = [
        ';' => substr_count($sample, ';'),
        ',' => substr_count($sample, ','),
        "\t" => substr_count($sample, "\t"),
        '|' => substr_count($sample, '|'),
    ];
    arsort($cands);
    $delim = array_key_first($cands);
    return $delim ?: ';';
}

function uvav_case_file(string $dirAbs, array $names): ?string {
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
}

function uvav_find_fixed_file(string $fileName, string $supplierKey, string $scriptRoot, string $docRoot, string $manualDir): ?string {
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

    $supplierDirs = [];
    $supplierKey = trim(str_replace(['\\', '/'], '', $supplierKey));
    if ($supplierKey !== '') {
        $supplierDirs[] = $supplierKey;
        $supplierDirs[] = strtolower($supplierKey);
        $supplierDirs[] = strtoupper($supplierKey);
    }

    $dirs = [
        $manualDir,
        $manualDir . '/' . UVAV_FOLDER,
        $scriptRoot . '/' . UVAV_FOLDER,
        $scriptRoot . '/' . UVAV_FOLDER . '/' . UVAV_FOLDER,
        $docRoot . '/' . UVAV_FOLDER,
        $docRoot . '/' . UVAV_FOLDER . '/' . UVAV_FOLDER,
    ];
    foreach ($supplierDirs as $sd) {
        $dirs[] = $scriptRoot . '/Dodavatele na SS26 Aktualizace/' . $sd;
        $dirs[] = $docRoot . '/Dodavatele na SS26 Aktualizace/' . $sd;
    }

    foreach (array_unique($dirs) as $dir) {
        $p = uvav_case_file($dir, $names);
        if ($p) return $p;
    }
    return null;
}

function uvav_find_merged_sklad_file(string $scriptRoot, string $docRoot): ?string {
    $names = ['SpojeneFeedy_CSTORE_SKLAD.csv', 'spojenefeedy_cstore_sklad.csv', 'ALL_XML_CSTORE_SKLAD.csv', 'all_xml_cstore_sklad.csv'];
    $dirs = [
        $scriptRoot . '/VSECHNY SPOJENE XML FEED',
        $docRoot . '/VSECHNY SPOJENE XML FEED',
        $scriptRoot,
        $docRoot,
    ];
    foreach (array_unique($dirs) as $dir) {
        $p = uvav_case_file($dir, $names);
        if ($p) return $p;
    }
    return null;
}

function uvav_clean_ean(string $raw): string {
    $raw = trim($raw);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $raw = trim($raw, " \t\n\r\0\x0B\"'");
    if ($raw === '') return '';

    $compact = str_replace(["\xC2\xA0", ' '], '', $raw);
    $compact = trim($compact, "=\"'");

    if (preg_match('~^0*[0-9]{6,18}$~', $compact)) {
        return $compact;
    }

    if (preg_match('~^([0-9]{6,18})[\.,]0+$~', $compact, $m)) {
        return $m[1];
    }

    if (preg_match('~^[+-]?[0-9]+(?:[\.,][0-9]+)?[eE][+-]?[0-9]+$~', $compact)) {
        $num = (float)str_replace(',', '.', $compact);
        if (is_finite($num) && $num > 0) {
            return number_format($num, 0, '', '');
        }
    }

    return preg_replace('~\D+~', '', $compact) ?: '';
}

function uvav_match_key(string $raw): string {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)$raw);
    $raw = trim($raw, " \t\n\r\0\x0B\"'");
    $raw = trim($raw, "=\"'");
    $raw = str_replace(["\xC2\xA0"], ' ', $raw);
    $raw = preg_replace('~\s+~u', ' ', $raw);
    if (function_exists('mb_strtolower')) return mb_strtolower($raw, 'UTF-8');
    return strtolower($raw);
}

function uvav_normalize_s_value(string $raw): string {
    $raw = trim($raw);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $rawNoSpace = str_replace(["\xC2\xA0", ' '], '', $raw);
    $lower = uvav_norm_lower($raw);

    if ($raw === '') return '1';
    if (strpos($lower, 'není') !== false || strpos($lower, 'neni') !== false || strpos($lower, 'nedostup') !== false) return '1';
    if (strpos($lower, 'nenale') !== false) return '1';
    if (is_numeric(str_replace(',', '.', $rawNoSpace))) {
        $num = (float)str_replace(',', '.', $rawNoSpace);
        return (string)((int)$num);
    }

    return '1';
}

function uvav_is_zero_value(string $raw): bool {
    return uvav_normalize_s_value($raw) === '0';
}

function uvav_maybe_clean_ean_cell(string $raw): ?string {
    $trimmed = trim($raw);
    $trimmed = preg_replace('/^\xEF\xBB\xBF/', '', $trimmed);
    $trimmed = trim($trimmed, " \t\n\r\0\x0B\"'");
    if ($trimmed === '') return null;

    $compact = str_replace(["\xC2\xA0", ' '], '', $trimmed);
    $compact = trim($compact, "=\"'");

    if (preg_match('~^0*[0-9]{6,18}$~', $compact)) return uvav_clean_ean($raw);
    if (preg_match('~^([0-9]{6,18})[\.,]0+$~', $compact)) return uvav_clean_ean($raw);
    if (preg_match('~^[+-]?[0-9]+(?:[\.,][0-9]+)?[eE][+-]?[0-9]+$~', $compact)) return uvav_clean_ean($raw);
    return null;
}


function uvav_ean_column_indexes(array $header, array $forcedIndexes = []): array {
    $indexes = [];
    foreach ($forcedIndexes as $idx) {
        if (is_int($idx) && $idx >= 0) $indexes[$idx] = true;
    }

    foreach ($header as $i => $name) {
        $n = uvav_norm_lower(trim((string)$name));
        $n = preg_replace('/^\xEF\xBB\xBF/', '', $n);
        if (strpos($n, 'ean') !== false) {
            $indexes[(int)$i] = true;
        }
    }

    ksort($indexes, SORT_NUMERIC);
    return array_map('intval', array_keys($indexes));
}

function uvav_clean_ean_columns_in_row(array &$row, array $eanIndexes): void {
    foreach ($eanIndexes as $idx) {
        if (!is_int($idx) || $idx < 0 || !array_key_exists($idx, $row)) continue;
        $clean = uvav_maybe_clean_ean_cell((string)$row[$idx]);
        if ($clean !== null && $clean !== '') {
            $row[$idx] = $clean;
        }
    }
}


function uvav_rewrite_output_with_clean_eans(string $file, string $delim, array $forcedIndexes = []): bool {
    if (!is_file($file)) return false;

    $in = @fopen($file, 'rb');
    if (!$in) return false;

    $tmp = $file . '.ean_tmp_' . date('Ymd_His') . '_' . substr(md5((string)microtime(true)), 0, 8);
    $out = @fopen($tmp, 'wb');
    if (!$out) {
        fclose($in);
        return false;
    }

    $lineNo = 0;
    $eanIndexes = [];
    while (($row = fgetcsv($in, 0, $delim)) !== false) {
        if (!is_array($row)) continue;
        $lineNo++;

        if ($lineNo === 1) {
            $eanIndexes = uvav_ean_column_indexes($row, $forcedIndexes);
            fputcsv($out, $row, $delim);
            continue;
        }

        if ($eanIndexes) {
            foreach ($eanIndexes as $idx) {
                while (count($row) <= $idx) $row[] = '';
                $clean = uvav_maybe_clean_ean_cell((string)$row[$idx]);
                if ($clean !== null && $clean !== '') {
                    // Výstup musí mít EAN jako čisté celé číslo, žádné 7.31857E+12 ani ="...".
                    $row[$idx] = $clean;
                }
            }
        }

        fputcsv($out, $row, $delim);
    }

    fclose($in);
    fclose($out);

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    @touch($file);
    return true;
}

function uvav_sort_bucket_key(string $value): string {
    $v = uvav_normalize_s_value($value);
    if (is_numeric($v)) return str_pad((string)((int)$v), 10, '0', STR_PAD_LEFT);
    return '9999999999';
}

function uvav_output_parts(string $fileName): array {
    $base = basename($fileName);
    if (preg_match('~^(.*)_var\.csv$~i', $base, $m)) {
        return [$m[1] . '_IMPORT_DO_ESHOPU_', '_var.csv'];
    }
    $withoutCsv = preg_replace('~\.csv$~i', '', $base);
    return [$withoutCsv . '_IMPORT_DO_ESHOPU_', '.csv'];
}

function uvav_output_name(string $fileName, ?string $stamp = null): string {
    [$prefix, $suffix] = uvav_output_parts($fileName);
    if ($stamp === null || $stamp === '') $stamp = date('Ymd_His');
    return $prefix . $stamp . $suffix;
}

function uvav_legacy_output_name(string $fileName): string {
    $base = basename($fileName);
    if (strcasecmp($base, 'NEWVavrysVariantyALL_var.csv') === 0) {
        return 'NEWVavrysVariantyALL_IMPORT_DO_ESHOPU_var.csv';
    }
    if (preg_match('~^(.*)_var\.csv$~i', $base, $m)) {
        return $m[1] . '_IMPORT_DO_ESHOPU_var.csv';
    }
    return preg_replace('~\.csv$~i', '_IMPORT_DO_ESHOPU.csv', $base);
}

function uvav_delete_old_outputs(string $dir, string $fileName, string $keepName = ''): void {
    if (!is_dir($dir)) return;
    [$prefix, $suffix] = uvav_output_parts($fileName);
    $legacy = uvav_legacy_output_name($fileName);
    $list = @scandir($dir) ?: [];
    foreach ($list as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $delete = false;
        if ($entry === $legacy || $entry === $legacy . '.stats.json') {
            $delete = true;
        }
        if (strpos($entry, $prefix) === 0 && substr($entry, -strlen($suffix)) === $suffix) {
            $delete = true;
        }
        if (strpos($entry, $prefix) === 0 && substr($entry, -strlen($suffix . '.stats.json')) === $suffix . '.stats.json') {
            $delete = true;
        }
        if ($keepName !== '' && ($entry === $keepName || $entry === $keepName . '.stats.json')) {
            $delete = false;
        }
        if ($delete && is_file($dir . '/' . $entry)) {
            @unlink($dir . '/' . $entry);
        }
    }
}



function uvav_find_latest_output_abs(string $dir, string $fileName): string {
    if (!is_dir($dir)) return '';
    [$prefix, $suffix] = uvav_output_parts($fileName);
    $bestAbs = '';
    $bestMtime = 0;

    foreach ((@scandir($dir) ?: []) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $abs = $dir . '/' . $entry;
        if (!is_file($abs)) continue;
        if (strpos($entry, $prefix) === 0 && substr($entry, -strlen($suffix)) === $suffix) {
            $mt = (int)@filemtime($abs);
            if ($mt >= $bestMtime) {
                $bestMtime = $mt;
                $bestAbs = $abs;
            }
        }
    }

    if ($bestAbs === '') {
        $legacyAbs = $dir . '/' . uvav_legacy_output_name($fileName);
        if (is_file($legacyAbs)) $bestAbs = $legacyAbs;
    }

    return $bestAbs;
}

function uvav_snapshot_previous_output_for_changes(string $dir, string $fileName, string $supplierKey, string $kind): void {
    $src = uvav_find_latest_output_abs($dir, $fileName);
    if ($src === '' || !is_file($src)) return;

    $snapDir = rtrim($dir, '/') . '/_predchozi_verze_zmeny';
    if (!is_dir($snapDir)) @mkdir($snapDir, 0775, true);
    if (!is_dir($snapDir)) return;

    $supplierKey = strtolower(preg_replace('~[^a-z0-9_-]+~', '', $supplierKey));
    $kind = ($kind === 'var') ? 'var' : 'main';
    $dest = $snapDir . '/' . $supplierKey . '__' . $kind . '.csv';
    @copy($src, $dest);
}

function uvav_find_header_col(array $header, array $needles, int $fallback): int {
    foreach ($header as $i => $name) {
        $n = uvav_norm_lower(trim((string)$name));
        $n = preg_replace('/^\xEF\xBB\xBF/', '', $n);
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($n, uvav_norm_lower($needle)) !== false) {
                return (int)$i;
            }
        }
    }
    return $fallback;
}


function uvav_process_one_supplier_batch(string $supplierKey, array $cfg, string $scriptRoot, string $docRoot, string $vavrysAbsDir): array {
    $anchor = (string)($cfg['anchor'] ?? 'zpracovat-vsechny-dodavatele');

    if (!uvav_ensure_dir($vavrysAbsDir)) {
        throw new RuntimeException('Nelze vytvořit nebo otevřít složku /' . UVAV_FOLDER . '/.');
    }

    $uploadedVarAbs = uvav_find_fixed_file((string)$cfg['var_file'], $supplierKey, $scriptRoot, $docRoot, $vavrysAbsDir);
    if (!$uploadedVarAbs || !is_file($uploadedVarAbs)) {
        throw new RuntimeException('Chybí soubor ' . $cfg['var_file'] . ' ve složce /' . UVAV_FOLDER . '/.');
    }

    $mainAbs = uvav_find_fixed_file((string)$cfg['main_file'], $supplierKey, $scriptRoot, $docRoot, $vavrysAbsDir);
    if (!$mainAbs || !is_file($mainAbs)) {
        throw new RuntimeException('Chybí hlavní soubor ' . $cfg['main_file'] . ' ve složce /' . UVAV_FOLDER . '/.');
    }

    $lookupFile = uvav_find_merged_sklad_file($scriptRoot, $docRoot);
    if (!$lookupFile || !is_file($lookupFile)) {
        throw new RuntimeException('Nejdřív vytvoř SpojeneFeedy_CSTORE_SKLAD.csv přes nahrání skladu C-Store.');
    }

    $stamp = date('Ymd_His');
    $outName = uvav_output_name((string)$cfg['var_file'], $stamp);
    $outAbs = $vavrysAbsDir . '/' . $outName;
    $mainOutName = uvav_output_name((string)$cfg['main_file'], $stamp);
    $mainOutAbs = $vavrysAbsDir . '/' . $mainOutName;

    // Než smažeme starší kompletní výstup, schováme jednu předchozí verzi pro modul „Všichni dodavatelé – pouze změny“.
    uvav_snapshot_previous_output_for_changes($vavrysAbsDir, (string)$cfg['var_file'], $supplierKey, 'var');
    uvav_snapshot_previous_output_for_changes($vavrysAbsDir, (string)$cfg['main_file'], $supplierKey, 'main');

    // Každé zpracování vytvoří nový název souboru a starší výstupy stejného dodavatele smaže.
    uvav_delete_old_outputs($vavrysAbsDir, (string)$cfg['var_file'], $outName);
    uvav_delete_old_outputs($vavrysAbsDir, (string)$cfg['main_file'], $mainOutName);

    $lookup = [];
    $lookupDelim = uvav_detect_delim_from_file($lookupFile);
    $fhLookup = @fopen($lookupFile, 'rb');
    if (!$fhLookup) {
        throw new RuntimeException('Spojený soubor se skladem nejde otevřít.');
    }
    $lookupRows = 0;
    while (($row = fgetcsv($fhLookup, 0, $lookupDelim)) !== false) {
        if (!is_array($row) || count($row) < 3) continue;
        $ean = uvav_clean_ean((string)($row[1] ?? ''));
        $val = uvav_normalize_s_value((string)($row[2] ?? ''));
        if ($ean === '' || $ean === 'ean') continue;

        $first = uvav_norm_lower(trim((string)($row[0] ?? '')));
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        if ($first === 'zdroj' && uvav_norm_lower(trim((string)($row[1] ?? ''))) === 'ean') continue;

        if (!isset($lookup[$ean]) || (int)$val < (int)$lookup[$ean]) {
            $lookup[$ean] = $val;
        }
        $lookupRows++;
    }
    fclose($fhLookup);

    if (!$lookup) {
        throw new RuntimeException('Ve SpojeneFeedy_CSTORE_SKLAD.csv nejsou žádné použitelné EAN hodnoty.');
    }

    $varDelim = uvav_detect_delim_from_file($uploadedVarAbs);
    $fhVar = @fopen($uploadedVarAbs, 'rb');
    if (!$fhVar) {
        throw new RuntimeException('_var soubor nejde otevřít.');
    }

    $tmpDir = $vavrysAbsDir . '/tmp_' . $supplierKey . '_' . $stamp . '_' . substr(md5($uploadedVarAbs), 0, 8);
    if (!uvav_ensure_dir($tmpDir)) {
        fclose($fhVar);
        throw new RuntimeException('Nelze vytvořit dočasnou složku pro řazení.');
    }

    $bucketFiles = [];
    $bucketHandles = [];
    $header = null;
    $totalRows = 0;
    $foundRows = 0;
    $missingRows = 0;
    $lineNo = 0;
    $zeroProductKeys = [];
    $varEanColumns = [];
    $varEanLookupCol = UVAV_Q_EAN_COL_INDEX;
    $varTargetCol = UVAV_S_TARGET_COL_INDEX;
    $varCodeCol = UVAV_B_PRODUCT_CODE_COL_INDEX;
    $varMinCols = max(UVAV_S_TARGET_COL_INDEX, UVAV_W_EAN_COL_INDEX);

    while (($row = fgetcsv($fhVar, 0, $varDelim)) !== false) {
        $lineNo++;
        if (!is_array($row)) continue;

        if ($lineNo === 1) {
            $header = $row;
            $varEanLookupCol = uvav_find_header_col($header, ['ean kod', 'ean'], UVAV_Q_EAN_COL_INDEX);
            $varTargetCol = uvav_find_header_col($header, ['tento produkt nezobrazovat v eshopu'], UVAV_S_TARGET_COL_INDEX);
            $varCodeCol = uvav_find_header_col($header, ['kod vyrobku', 'kód výrobku', 'kod produktu', 'kód produktu'], UVAV_B_PRODUCT_CODE_COL_INDEX);
            $varMinCols = max($varEanLookupCol, $varTargetCol, UVAV_W_EAN_COL_INDEX, $varCodeCol);
            while (count($header) <= $varMinCols) $header[] = '';
            $varEanColumns = uvav_ean_column_indexes($header, [$varEanLookupCol, UVAV_W_EAN_COL_INDEX]);
            continue;
        }

        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        while (count($row) <= $varMinCols) $row[] = '';

        uvav_clean_ean_columns_in_row($row, $varEanColumns);
        $ean = uvav_clean_ean((string)($row[$varEanLookupCol] ?? ''));
        if ($ean !== '') {
            $row[$varEanLookupCol] = $ean;
        }

        if ($ean !== '' && isset($lookup[$ean])) {
            $sVal = uvav_normalize_s_value((string)$lookup[$ean]);
            $foundRows++;
        } else {
            $sVal = '1';
            $missingRows++;
        }
        $row[$varTargetCol] = $sVal;

        if (uvav_is_zero_value($sVal)) {
            $productKey = uvav_match_key((string)($row[$varCodeCol] ?? ''));
            if ($productKey !== '') {
                $zeroProductKeys[$productKey] = true;
            }
        }

        $bucketKey = uvav_sort_bucket_key($sVal);
        if (!isset($bucketHandles[$bucketKey])) {
            $bucketFile = $tmpDir . '/bucket_' . $bucketKey . '.csv';
            $bucketFiles[$bucketKey] = $bucketFile;
            $bucketHandles[$bucketKey] = new SplFileObject($bucketFile, 'w');
        }
        $bucketHandles[$bucketKey]->fputcsv($row, $varDelim);
        $totalRows++;
    }
    fclose($fhVar);

    foreach ($bucketHandles as $k => $h) { $bucketHandles[$k] = null; }
    $bucketHandles = [];

    if ($header === null) {
        throw new RuntimeException('_var soubor neobsahuje hlavičku.');
    }

    ksort($bucketFiles, SORT_STRING);
    $out = new SplFileObject($outAbs, 'w');
    $out->fputcsv($header, $varDelim);
    foreach ($bucketFiles as $bucketFile) {
        $fhBucket = @fopen($bucketFile, 'rb');
        if (!$fhBucket) continue;
        while (($line = fgets($fhBucket)) !== false) {
            $out->fwrite($line);
        }
        fclose($fhBucket);
        @unlink($bucketFile);
    }
    $out = null;
    @rmdir($tmpDir);
    uvav_rewrite_output_with_clean_eans($outAbs, $varDelim, [$varEanLookupCol, UVAV_W_EAN_COL_INDEX]);
    @touch($outAbs);

    $mainDelim = uvav_detect_delim_from_file($mainAbs);
    $fhMain = @fopen($mainAbs, 'rb');
    if (!$fhMain) {
        throw new RuntimeException('Hlavní soubor nejde otevřít.');
    }

    $mainOut = new SplFileObject($mainOutAbs, 'w');
    $mainRows = 0;
    $mainZeroRows = 0;
    $mainOneRows = 0;
    $mainHiddenProducts = 0;
    $mainOpenedProducts = 0;
    $mainLineNo = 0;
    $mainHeader = null;
    $mainEanColumns = [];
    $mainTargetCol = UVAV_AO_MAIN_TARGET_COL_INDEX;
    $mainCodeCol = UVAV_B_PRODUCT_CODE_COL_INDEX;

    while (($row = fgetcsv($fhMain, 0, $mainDelim)) !== false) {
        $mainLineNo++;
        if (!is_array($row)) continue;

        if ($mainLineNo === 1) {
            $mainHeader = $row;
            $mainTargetCol = uvav_find_header_col($mainHeader, ['tento produkt nezobrazovat v eshopu'], UVAV_AO_MAIN_TARGET_COL_INDEX);
            $mainCodeCol = uvav_find_header_col($mainHeader, ['kod vyrobku', 'kód výrobku', 'kod produktu', 'kód produktu'], UVAV_B_PRODUCT_CODE_COL_INDEX);
            while (count($mainHeader) <= max($mainTargetCol, $mainCodeCol)) $mainHeader[] = '';
            if (trim((string)$mainHeader[$mainTargetCol]) === '') {
                $mainHeader[$mainTargetCol] = 'Tento produkt nezobrazovat v eshopu';
            }
            $mainEanColumns = uvav_ean_column_indexes($mainHeader, []);
            $mainOut->fputcsv($mainHeader, $mainDelim);
            continue;
        }

        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        while (count($row) <= max($mainTargetCol, $mainCodeCol)) $row[] = '';

        uvav_clean_ean_columns_in_row($row, $mainEanColumns);

        $mainKey = uvav_match_key((string)($row[$mainCodeCol] ?? ''));
        $oldMainRaw = (string)($row[$mainTargetCol] ?? '');
        $oldMainTrimmed = trim($oldMainRaw);
        $oldMainVal = ($oldMainTrimmed === '') ? '' : uvav_normalize_s_value($oldMainRaw);

        if ($mainKey !== '' && isset($zeroProductKeys[$mainKey])) {
            $newMainVal = '0';
            $mainZeroRows++;
        } else {
            $newMainVal = '1';
            $mainOneRows++;
        }

        if ($oldMainVal === '0' && $newMainVal === '1') {
            $mainHiddenProducts++;
        } elseif ($oldMainVal === '1' && $newMainVal === '0') {
            $mainOpenedProducts++;
        }

        $row[$mainTargetCol] = $newMainVal;

        $mainOut->fputcsv($row, $mainDelim);
        $mainRows++;
    }
    fclose($fhMain);
    $mainOut = null;
    uvav_rewrite_output_with_clean_eans($mainOutAbs, $mainDelim, []);
    @touch($mainOutAbs);

    if ($mainHeader === null) {
        throw new RuntimeException('Hlavní soubor neobsahuje hlavičku.');
    }

    $stats = [
        'supplier' => $supplierKey,
        'title' => (string)($cfg['title'] ?? strtoupper($supplierKey)),
        'created_at' => date('Y-m-d H:i:s'),
        'var_output' => $outName,
        'main_output' => $mainOutName,
        'rows' => $totalRows,
        'found' => $foundRows,
        'missing' => $missingRows,
        'hidden_products' => $mainHiddenProducts,
        'opened_products' => $mainOpenedProducts,
        'main_zero' => $mainZeroRows,
        'main_one' => $mainOneRows,
        'main_rows' => $mainRows,
        'anchor' => $anchor,
    ];
    @file_put_contents($mainOutAbs . '.stats.json', json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return $stats;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uvav_redirect(['all_suppliers_process' => 'err', 'msg' => 'Akce nebyla odeslána.'], 'zpracovat-vsechny-dodavatele');
}

$scriptRoot = rtrim(str_replace('\\', '/', __DIR__), '/');
$docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($docRoot === '') $docRoot = $scriptRoot;
$vavrysAbsDir = $scriptRoot . '/' . UVAV_FOLDER;

$processed = [];
$totalHidden = 0;
$totalOpened = 0;
$totalVarRows = 0;
$totalMainRows = 0;

try {
    foreach ($supplierConfigs as $supplierKey => $cfg) {
        $one = uvav_process_one_supplier_batch((string)$supplierKey, (array)$cfg, $scriptRoot, $docRoot, $vavrysAbsDir);
        $processed[$supplierKey] = $one;
        $totalHidden += (int)($one['hidden_products'] ?? 0);
        $totalOpened += (int)($one['opened_products'] ?? 0);
        $totalVarRows += (int)($one['rows'] ?? 0);
        $totalMainRows += (int)($one['main_rows'] ?? 0);
    }
} catch (Throwable $e) {
    @file_put_contents($vavrysAbsDir . '/_zpracovat_vse.stats.json', json_encode([
        'created_at' => date('Y-m-d H:i:s'),
        'status' => 'err',
        'message' => $e->getMessage(),
        'processed' => $processed,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    uvav_redirect(['all_suppliers_process' => 'err', 'msg' => $e->getMessage()], 'zpracovat-vsechny-dodavatele');
}

@file_put_contents($vavrysAbsDir . '/_zpracovat_vse.stats.json', json_encode([
    'version' => UVAV_VERSION,
    'created_at' => date('Y-m-d H:i:s'),
    'status' => 'ok',
    'processed_count' => count($processed),
    'hidden_products' => $totalHidden,
    'opened_products' => $totalOpened,
    'variant_rows' => $totalVarRows,
    'main_rows' => $totalMainRows,
    'processed' => $processed,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

uvav_redirect([
    'all_suppliers_process' => 'ok',
    'processed' => (string)count($processed),
    'hidden_products' => (string)$totalHidden,
    'opened_products' => (string)$totalOpened,
    'variant_rows' => (string)$totalVarRows,
    'main_rows' => (string)$totalMainRows,
], 'zpracovat-vsechny-dodavatele');
