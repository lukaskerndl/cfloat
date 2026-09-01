<?php
session_start();
const UASCH_VERSION = '2026-06-18-ALL-SUPPLIER-CHANGES-V6-COMPARE-LATEST-OUTPUTS';
const UASCH_Q_EAN_COL_INDEX = 16; // Q = EAN kod ve variantách
const UASCH_S_TARGET_COL_INDEX = 18; // S = Tento produkt nezobrazovat v eshopu ve variantách
const UASCH_B_PRODUCT_CODE_COL_INDEX = 1; // B = Kod vyrobku
const UASCH_AO_MAIN_TARGET_COL_INDEX = 40; // AO = Tento produkt nezobrazovat v eshopu v hlavním souboru
const UASCH_W_EAN_COL_INDEX = 22; // W = dlouhé číslo/EAN, pokud je vyplněné
const UASCH_FOLDER = 'RucniNahraniAktualizace';
const UASCH_SNAPSHOT_FOLDER = '_predchozi_verze_zmeny';
const UASCH_VAR_OUT_NAME = 'VsechnyDodavateleVarianty_var.csv';
const UASCH_MAIN_OUT_NAME = 'VsechnyDodavateleHlavni.csv';

$supplierConfigs = [
    'vavrys' => [
        'title' => 'VAVRYS',
        'main_file' => 'NEwHlavniVavrys.csv',
        'var_file' => 'NEWVavrysVariantyALL_var.csv',
    ],
    'silvini' => [
        'title' => 'SILVINI',
        'main_file' => 'HlavniSilviniSS2026.csv',
        'var_file' => 'VariantySILVINI_var.csv',
    ],
    'ale' => [
        'title' => 'ALÉ',
        'main_file' => 'HLAVNIAlea.csv',
        'var_file' => 'VariantyAlea_var.csv',
    ],
    'isadore' => [
        'title' => 'ISADORE',
        'main_file' => 'HlavniIsadore.csv',
        'var_file' => 'VARIANTYYIsadore_var.csv',
    ],
];

if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    echo 'Nepřihlášeno.';
    exit;
}

function uasch_redirect(array $params): void {
    $base = 'index.php?view=xmlfeedy';
    if ($params) $base .= '&' . http_build_query($params);
    $base .= '#vsechny-dodavatele-zmeny';
    header('Location: ' . $base);
    exit;
}

function uasch_ensure_dir(string $dir): bool {
    return is_dir($dir) || @mkdir($dir, 0775, true);
}

function uasch_norm_lower(string $s): string {
    if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
    return strtolower($s);
}

function uasch_detect_delim_from_file(string $file): string {
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

function uasch_output_parts(string $fileName): array {
    $base = basename($fileName);
    if (preg_match('~^(.*)_var\.csv$~i', $base, $m)) {
        return [$m[1] . '_IMPORT_DO_ESHOPU_', '_var.csv'];
    }
    $withoutCsv = preg_replace('~\.csv$~i', '', $base);
    return [$withoutCsv . '_IMPORT_DO_ESHOPU_', '.csv'];
}

function uasch_legacy_output_name(string $fileName): string {
    $base = basename($fileName);
    if (strcasecmp($base, 'NEWVavrysVariantyALL_var.csv') === 0) {
        return 'NEWVavrysVariantyALL_IMPORT_DO_ESHOPU_var.csv';
    }
    if (preg_match('~^(.*)_var\.csv$~i', $base, $m)) {
        return $m[1] . '_IMPORT_DO_ESHOPU_var.csv';
    }
    return preg_replace('~\.csv$~i', '_IMPORT_DO_ESHOPU.csv', $base);
}

function uasch_find_latest_output(string $manualDir, string $fileName): array {
    [$prefix, $suffix] = uasch_output_parts($fileName);
    $bestAbs = '';
    $bestName = '';
    $bestMtime = 0;

    if (is_dir($manualDir)) {
        $list = @scandir($manualDir) ?: [];
        foreach ($list as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $abs = $manualDir . '/' . $entry;
            if (!is_file($abs)) continue;
            if (strpos($entry, $prefix) === 0 && substr($entry, -strlen($suffix)) === $suffix) {
                $mt = (int)@filemtime($abs);
                if ($mt >= $bestMtime) {
                    $bestMtime = $mt;
                    $bestAbs = $abs;
                    $bestName = $entry;
                }
            }
        }

        $legacy = uasch_legacy_output_name($fileName);
        $legacyAbs = $manualDir . '/' . $legacy;
        if ($bestAbs === '' && is_file($legacyAbs)) {
            $bestAbs = $legacyAbs;
            $bestName = $legacy;
            $bestMtime = (int)@filemtime($legacyAbs);
        }
    }

    return ['name' => $bestName, 'abs' => $bestAbs, 'mtime' => $bestMtime];
}

function uasch_snapshot_abs(string $snapshotDir, string $supplierKey, string $kind): string {
    $supplierKey = strtolower(preg_replace('~[^a-z0-9_-]+~', '', $supplierKey));
    $kind = ($kind === 'var') ? 'var' : 'main';
    return rtrim($snapshotDir, '/') . '/' . $supplierKey . '__' . $kind . '.csv';
}

function uasch_delete_old_combined_outputs(string $dir): void {
    if (!is_dir($dir)) return;
    $patterns = [
        '~^VsechnyDodavateleVarianty.*_var\.csv(?:\.stats\.json)?$~i',
        '~^VsechnyDodavateleHlavni.*\.csv(?:\.stats\.json)?$~i',
    ];
    foreach ((@scandir($dir) ?: []) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $entry) && is_file($dir . '/' . $entry)) {
                @unlink($dir . '/' . $entry);
                break;
            }
        }
    }
}

function uasch_clean_ean(string $raw): string {
    $raw = trim($raw);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $raw = trim($raw, " \t\n\r\0\x0B\"'");
    if ($raw === '') return '';

    $compact = str_replace(["\xC2\xA0", ' '], '', $raw);
    $compact = trim($compact, "=\"'");

    if (preg_match('~^0*[0-9]{6,18}$~', $compact)) return $compact;
    if (preg_match('~^([0-9]{6,18})[\.,]0+$~', $compact, $m)) return $m[1];
    if (preg_match('~^[+-]?[0-9]+(?:[\.,][0-9]+)?[eE][+-]?[0-9]+$~', $compact)) {
        $num = (float)str_replace(',', '.', $compact);
        if (is_finite($num) && $num > 0) return number_format($num, 0, '', '');
    }

    return preg_replace('~\D+~', '', $compact) ?: '';
}

function uasch_maybe_clean_ean_cell(string $raw): ?string {
    $trimmed = trim($raw);
    $trimmed = preg_replace('/^\xEF\xBB\xBF/', '', $trimmed);
    $trimmed = trim($trimmed, " \t\n\r\0\x0B\"'");
    if ($trimmed === '') return null;

    $compact = str_replace(["\xC2\xA0", ' '], '', $trimmed);
    $compact = trim($compact, "=\"'");

    if (preg_match('~^0*[0-9]{6,18}$~', $compact)) return uasch_clean_ean($raw);
    if (preg_match('~^([0-9]{6,18})[\.,]0+$~', $compact)) return uasch_clean_ean($raw);
    if (preg_match('~^[+-]?[0-9]+(?:[\.,][0-9]+)?[eE][+-]?[0-9]+$~', $compact)) return uasch_clean_ean($raw);
    return null;
}

function uasch_match_key(string $raw): string {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)$raw);
    $raw = trim($raw, " \t\n\r\0\x0B\"'");
    $raw = trim($raw, "=\"'");
    $raw = str_replace(["\xC2\xA0"], ' ', $raw);
    $raw = preg_replace('~\s+~u', ' ', $raw);
    if (function_exists('mb_strtolower')) return mb_strtolower($raw, 'UTF-8');
    return strtolower($raw);
}

function uasch_normalize_s_value(string $raw): string {
    $raw = trim($raw);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $rawNoSpace = str_replace(["\xC2\xA0", ' '], '', $raw);
    $lower = uasch_norm_lower($raw);

    if ($raw === '') return '';
    if (strpos($lower, 'není') !== false || strpos($lower, 'neni') !== false || strpos($lower, 'nedostup') !== false) return '1';
    if (strpos($lower, 'nenale') !== false) return '1';
    if (is_numeric(str_replace(',', '.', $rawNoSpace))) {
        $num = (float)str_replace(',', '.', $rawNoSpace);
        return (string)((int)$num);
    }

    return '';
}

function uasch_find_header_col(array $header, array $needles, int $fallback): int {
    foreach ($header as $i => $name) {
        $n = uasch_norm_lower(trim((string)$name));
        $n = preg_replace('/^\xEF\xBB\xBF/', '', $n);
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($n, uasch_norm_lower($needle)) !== false) {
                return (int)$i;
            }
        }
    }
    return $fallback;
}

function uasch_ean_column_indexes(array $header, array $forcedIndexes = []): array {
    $indexes = [];
    foreach ($forcedIndexes as $idx) {
        if (is_int($idx) && $idx >= 0) $indexes[$idx] = true;
    }
    foreach ($header as $i => $name) {
        $n = uasch_norm_lower(trim((string)$name));
        $n = preg_replace('/^\xEF\xBB\xBF/', '', $n);
        if (strpos($n, 'ean') !== false) $indexes[(int)$i] = true;
    }
    ksort($indexes, SORT_NUMERIC);
    return array_map('intval', array_keys($indexes));
}

function uasch_clean_ean_columns_in_row(array &$row, array $eanIndexes): void {
    foreach ($eanIndexes as $idx) {
        if (!is_int($idx) || $idx < 0 || !array_key_exists($idx, $row)) continue;
        $clean = uasch_maybe_clean_ean_cell((string)$row[$idx]);
        if ($clean !== null && $clean !== '') $row[$idx] = $clean;
    }
}

function uasch_row_key(array $row, int $codeCol, int $eanCol, bool $isVar): string {
    $code = uasch_match_key((string)($row[$codeCol] ?? ''));
    $ean = uasch_clean_ean((string)($row[$eanCol] ?? ''));

    if ($isVar) {
        if ($ean !== '') return 'ean:' . $ean;
        $p1 = uasch_match_key((string)($row[9] ?? ''));
        $p2 = uasch_match_key((string)($row[10] ?? ''));
        $p3 = uasch_match_key((string)($row[11] ?? ''));
        $url = uasch_match_key((string)($row[22] ?? ''));
        $id = uasch_match_key((string)($row[0] ?? ''));
        $key = trim($code . '|' . $p1 . '|' . $p2 . '|' . $p3 . '|' . $url . '|' . $id, '|');
        return $key !== '' ? 'var:' . $key : '';
    }

    if ($code !== '') return 'code:' . $code;
    if ($ean !== '') return 'ean:' . $ean;
    $id = uasch_match_key((string)($row[0] ?? ''));
    return $id !== '' ? 'id:' . $id : '';
}

function uasch_csv_meta(string $file, bool $isVar): array {
    $delim = uasch_detect_delim_from_file($file);
    $fh = @fopen($file, 'rb');
    if (!$fh) return [];
    $header = fgetcsv($fh, 0, $delim);
    fclose($fh);
    if (!is_array($header)) return [];

    $targetCol = $isVar
        ? uasch_find_header_col($header, ['tento produkt nezobrazovat v eshopu'], UASCH_S_TARGET_COL_INDEX)
        : uasch_find_header_col($header, ['tento produkt nezobrazovat v eshopu'], UASCH_AO_MAIN_TARGET_COL_INDEX);
    $codeCol = uasch_find_header_col($header, ['kod vyrobku', 'kód výrobku', 'kod produktu', 'kód produktu'], UASCH_B_PRODUCT_CODE_COL_INDEX);
    $eanCol = uasch_find_header_col($header, ['ean kod', 'ean'], $isVar ? UASCH_Q_EAN_COL_INDEX : 35);
    $maxCol = max($targetCol, $codeCol, $eanCol, UASCH_W_EAN_COL_INDEX);
    while (count($header) <= $maxCol) $header[] = '';
    $eanColumns = uasch_ean_column_indexes($header, [$eanCol, UASCH_W_EAN_COL_INDEX]);

    return [
        'delim' => $delim,
        'header' => $header,
        'target_col' => $targetCol,
        'code_col' => $codeCol,
        'ean_col' => $eanCol,
        'max_col' => $maxCol,
        'ean_columns' => $eanColumns,
    ];
}

function uasch_load_values_by_key(string $file, bool $isVar): array {
    $meta = uasch_csv_meta($file, $isVar);
    if (!$meta) return [];
    $fh = @fopen($file, 'rb');
    if (!$fh) return [];

    $values = [];
    $lineNo = 0;
    while (($row = fgetcsv($fh, 0, $meta['delim'])) !== false) {
        $lineNo++;
        if ($lineNo === 1) continue;
        if (!is_array($row) || (count($row) === 1 && trim((string)$row[0]) === '')) continue;
        while (count($row) <= $meta['max_col']) $row[] = '';
        $key = uasch_row_key($row, (int)$meta['code_col'], (int)$meta['ean_col'], $isVar);
        if ($key === '') continue;
        $val = uasch_normalize_s_value((string)($row[$meta['target_col']] ?? ''));
        if ($val === '0' || $val === '1') $values[$key] = $val;
    }
    fclose($fh);
    return $values;
}

function uasch_count_csv_data_rows(string $absFile): int {
    if (!is_file($absFile)) return 0;
    $delim = uasch_detect_delim_from_file($absFile);
    $fh = @fopen($absFile, 'rb');
    if (!$fh) return 0;
    $rows = 0;
    $line = 0;
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        $line++;
        if ($line === 1) continue;
        if (!$r || count($r) < 1) continue;
        if (trim((string)($r[0] ?? '')) === '') continue;
        $rows++;
    }
    fclose($fh);
    return $rows;
}

function uasch_write_header_if_needed(SplFileObject $out, array $header, string $delim, bool &$written): void {
    if (!$written) {
        $out->fputcsv($header, $delim);
        $written = true;
    }
}

function uasch_append_changed_rows(string $currentFile, string $previousFile, bool $isVar, SplFileObject $out, string &$outDelim, bool &$headerWritten, array &$stats): void {
    if (!is_file($currentFile) || !is_file($previousFile)) return;

    $oldValues = uasch_load_values_by_key($previousFile, $isVar);
    if (!$oldValues) return;

    $meta = uasch_csv_meta($currentFile, $isVar);
    if (!$meta) return;
    if (!$headerWritten) $outDelim = (string)$meta['delim'];

    $fh = @fopen($currentFile, 'rb');
    if (!$fh) return;

    $lineNo = 0;
    while (($row = fgetcsv($fh, 0, $meta['delim'])) !== false) {
        $lineNo++;
        if ($lineNo === 1) {
            uasch_write_header_if_needed($out, $meta['header'], $outDelim, $headerWritten);
            continue;
        }
        if (!is_array($row) || (count($row) === 1 && trim((string)$row[0]) === '')) continue;
        while (count($row) <= $meta['max_col']) $row[] = '';

        $key = uasch_row_key($row, (int)$meta['code_col'], (int)$meta['ean_col'], $isVar);
        if ($key === '' || !array_key_exists($key, $oldValues)) continue;

        $oldVal = (string)$oldValues[$key];
        $newVal = uasch_normalize_s_value((string)($row[$meta['target_col']] ?? ''));
        if (!(($oldVal === '0' || $oldVal === '1') && ($newVal === '0' || $newVal === '1') && $oldVal !== $newVal)) continue;

        uasch_clean_ean_columns_in_row($row, (array)$meta['ean_columns']);
        $row[$meta['target_col']] = $newVal;
        $out->fputcsv($row, $outDelim);

        if ($isVar) {
            $stats['variant_changed_rows']++;
            if ($oldVal === '0' && $newVal === '1') {
                $stats['variant_hidden_rows']++;
            } elseif ($oldVal === '1' && $newVal === '0') {
                $stats['variant_opened_rows']++;
            }
        } else {
            $stats['main_changed_rows']++;
            if ($oldVal === '0' && $newVal === '1') {
                $stats['hidden_products']++;
            } elseif ($oldVal === '1' && $newVal === '0') {
                $stats['opened_products']++;
            }
        }
    }
    fclose($fh);
}

function uasch_sort_csv_target_zero_first(string $absFile, int $fallbackTargetCol): void {
    if (!is_file($absFile)) return;

    $delim = uasch_detect_delim_from_file($absFile);
    $fh = @fopen($absFile, 'rb');
    if (!$fh) return;

    $header = fgetcsv($fh, 0, $delim);
    if (!is_array($header)) {
        fclose($fh);
        return;
    }

    $targetCol = uasch_find_header_col($header, ['tento produkt nezobrazovat v eshopu'], $fallbackTargetCol);
    while (count($header) <= $targetCol) $header[] = '';
    $eanColumns = uasch_ean_column_indexes($header, [UASCH_Q_EAN_COL_INDEX, UASCH_W_EAN_COL_INDEX]);

    $rows = [];
    $ord = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        if (!is_array($row)) continue;
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        while (count($row) <= $targetCol) $row[] = '';
        uasch_clean_ean_columns_in_row($row, $eanColumns);
        $val = uasch_normalize_s_value((string)($row[$targetCol] ?? ''));
        $rank = ($val === '0') ? 0 : (($val === '1') ? 1 : 2);
        $rows[] = ['rank' => $rank, 'ord' => $ord++, 'row' => $row];
    }
    fclose($fh);

    usort($rows, function(array $a, array $b): int {
        if ($a['rank'] === $b['rank']) return $a['ord'] <=> $b['ord'];
        return $a['rank'] <=> $b['rank'];
    });

    $out = new SplFileObject($absFile, 'w');
    $out->fputcsv($header, $delim);
    foreach ($rows as $item) $out->fputcsv($item['row'], $delim);
    $out = null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uasch_redirect(['all_supplier_changes' => 'err', 'msg' => 'Akce nebyla odeslána.']);
}

$scriptRoot = rtrim(str_replace('\\', '/', __DIR__), '/');
$outDir = $scriptRoot . '/' . UASCH_FOLDER;
$snapshotDir = $outDir . '/' . UASCH_SNAPSHOT_FOLDER;

if (!uasch_ensure_dir($outDir) || !uasch_ensure_dir($snapshotDir)) {
    uasch_redirect(['all_supplier_changes' => 'err', 'msg' => 'Nelze otevřít nebo vytvořit složku pro změny.']);
}

uasch_delete_old_combined_outputs($outDir);

$varOutAbs = $outDir . '/' . UASCH_VAR_OUT_NAME;
$mainOutAbs = $outDir . '/' . UASCH_MAIN_OUT_NAME;
$varOut = new SplFileObject($varOutAbs, 'w');
$mainOut = new SplFileObject($mainOutAbs, 'w');
$varHeaderWritten = false;
$mainHeaderWritten = false;
$varOutDelim = ';';
$mainOutDelim = ';';

$totalStats = [
    'hidden_products' => 0,
    'opened_products' => 0,
    'main_changed_rows' => 0,
    'variant_changed_rows' => 0,
    'variant_hidden_rows' => 0,
    'variant_opened_rows' => 0,
];
$bySupplier = [];
$initializedSnapshots = [];

foreach ($supplierConfigs as $supplierKey => $cfg) {
    $title = (string)($cfg['title'] ?? strtoupper($supplierKey));
    $currentVar = uasch_find_latest_output($outDir, (string)$cfg['var_file']);
    $currentMain = uasch_find_latest_output($outDir, (string)$cfg['main_file']);

    if (empty($currentVar['abs']) || !is_file((string)$currentVar['abs'])) {
        uasch_redirect(['all_supplier_changes' => 'err', 'msg' => 'Chybí aktuální výstup variant pro ' . $title . '. Nejdřív klikni u dodavatele na Zpracovat.']);
    }
    if (empty($currentMain['abs']) || !is_file((string)$currentMain['abs'])) {
        uasch_redirect(['all_supplier_changes' => 'err', 'msg' => 'Chybí aktuální výstup hlavních produktů pro ' . $title . '. Nejdřív klikni u dodavatele na Zpracovat.']);
    }

    $prevVar = uasch_snapshot_abs($snapshotDir, $supplierKey, 'var');
    $prevMain = uasch_snapshot_abs($snapshotDir, $supplierKey, 'main');

    $supplierStats = [
        'title' => $title,
        'current_var' => (string)$currentVar['name'],
        'current_main' => (string)$currentMain['name'],
        'previous_var_exists' => is_file($prevVar),
        'previous_main_exists' => is_file($prevMain),
        'hidden_products' => 0,
        'opened_products' => 0,
        'main_changed_rows' => 0,
        'variant_changed_rows' => 0,
        'variant_hidden_rows' => 0,
        'variant_opened_rows' => 0,
    ];

    if (is_file($prevVar)) {
        uasch_append_changed_rows((string)$currentVar['abs'], $prevVar, true, $varOut, $varOutDelim, $varHeaderWritten, $supplierStats);
    } else {
        $initializedSnapshots[] = $title . ' varianty';
    }

    if (is_file($prevMain)) {
        uasch_append_changed_rows((string)$currentMain['abs'], $prevMain, false, $mainOut, $mainOutDelim, $mainHeaderWritten, $supplierStats);
    } else {
        $initializedSnapshots[] = $title . ' hlavní';
    }

    foreach ($totalStats as $k => $v) {
        $totalStats[$k] += (int)($supplierStats[$k] ?? 0);
    }

    // Po vytvoření změnového souboru nastavíme aktuální kompletní výstup jako novou předchozí verzi.
    // Další kliknutí „Vygenerovat vše“ bez nového zpracování dodavatele pak správně vrátí 0 změn.
    @copy((string)$currentVar['abs'], $prevVar);
    @copy((string)$currentMain['abs'], $prevMain);

    $bySupplier[$supplierKey] = $supplierStats;
}

// Když nebyla zapsaná žádná hlavička, vytvoř aspoň hlavičku z prvního aktuálního souboru, aby CSV bylo platné.
if (!$varHeaderWritten) {
    foreach ($supplierConfigs as $supplierKey => $cfg) {
        $cur = uasch_find_latest_output($outDir, (string)$cfg['var_file']);
        if (!empty($cur['abs']) && is_file((string)$cur['abs'])) {
            $meta = uasch_csv_meta((string)$cur['abs'], true);
            if ($meta) {
                $varOutDelim = (string)$meta['delim'];
                $varOut->fputcsv((array)$meta['header'], $varOutDelim);
                $varHeaderWritten = true;
                break;
            }
        }
    }
}
if (!$mainHeaderWritten) {
    foreach ($supplierConfigs as $supplierKey => $cfg) {
        $cur = uasch_find_latest_output($outDir, (string)$cfg['main_file']);
        if (!empty($cur['abs']) && is_file((string)$cur['abs'])) {
            $meta = uasch_csv_meta((string)$cur['abs'], false);
            if ($meta) {
                $mainOutDelim = (string)$meta['delim'];
                $mainOut->fputcsv((array)$meta['header'], $mainOutDelim);
                $mainHeaderWritten = true;
                break;
            }
        }
    }
}

$varOut = null;
$mainOut = null;

uasch_sort_csv_target_zero_first($varOutAbs, UASCH_S_TARGET_COL_INDEX);
@touch($varOutAbs);
@touch($mainOutAbs);

$stats = [
    'version' => UASCH_VERSION,
    'created_at' => date('Y-m-d H:i:s'),
    'variant_output' => UASCH_VAR_OUT_NAME,
    'main_output' => UASCH_MAIN_OUT_NAME,
    'hidden_products' => (int)$totalStats['hidden_products'],
    'opened_products' => (int)$totalStats['opened_products'],
    'main_changed_rows' => (int)$totalStats['main_changed_rows'],
    'variant_changed_rows' => (int)$totalStats['variant_changed_rows'],
    'variant_hidden_rows' => (int)$totalStats['variant_hidden_rows'],
    'variant_opened_rows' => (int)$totalStats['variant_opened_rows'],
    'variant_rows' => uasch_count_csv_data_rows($varOutAbs),
    'main_rows' => uasch_count_csv_data_rows($mainOutAbs),
    'initialized_snapshots' => $initializedSnapshots,
    'by_supplier' => $bySupplier,
];
@file_put_contents($mainOutAbs . '.stats.json', json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
@file_put_contents($varOutAbs . '.stats.json', json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

uasch_redirect([
    'all_supplier_changes' => 'ok',
    'hidden_products' => (string)$totalStats['hidden_products'],
    'opened_products' => (string)$totalStats['opened_products'],
    'main_rows' => (string)$totalStats['main_changed_rows'],
    'variant_rows' => (string)$totalStats['variant_changed_rows'],
    'variant_hidden_rows' => (string)$totalStats['variant_hidden_rows'],
    'variant_opened_rows' => (string)$totalStats['variant_opened_rows'],
]);
