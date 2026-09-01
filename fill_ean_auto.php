<?php
declare(strict_types=1);

/**
 * fill_ean_auto.php
 *
 * Doplňuje order_items.EAN z více zdrojů variant.
 * Hlavní zdroj pro SILVINI: /RucniNahraniAktualizace/variantysilvini_var.csv
 * Záložní zdroj: /CStore/Varianty/AllVarianty.csv
 * Párování probíhá v tomto pořadí:
 *   1) product_id + variant_id proti sloupci ID
 *   2) kód výrobku + varianta/velikost
 *   3) samotný kód výrobku, ale pouze když má v CSV jediný EAN
 *
 * Existující EAN se nikdy nepřepisuje.
 * Přímé spuštění s diagnostikou:
 *   /fill_ean_auto.php?debug=1
 */

$runningDirect = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__;
$debug = $runningDirect && isset($_GET['debug']) && (string)$_GET['debug'] === '1';
$scriptVersion = '2026-07-13-SILVINI-EAN-V3';

require_once __DIR__ . '/config.php';

if (!isset($pdo) && isset($GLOBALS['pdo'])) {
    $pdo = $GLOBALS['pdo'];
}
if (isset($pdo) && $pdo instanceof PDO) {
    $GLOBALS['pdo'] = $pdo;
}

/** @return string UTF-8 text, best effort */
function cfloat_ean_utf8(string $value): string
{
    if ($value === '') return '';
    if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }
    $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $value);
    return $converted !== false ? $converted : $value;
}

function cfloat_ean_ascii(string $value): string
{
    $value = cfloat_ean_utf8($value);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return $converted !== false ? $converted : $value;
}

function cfloat_ean_header(string $value): string
{
    $value = strtoupper(trim(cfloat_ean_ascii($value)));
    return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
}

function cfloat_ean_code(string $value): string
{
    $value = strtoupper(trim(cfloat_ean_ascii($value)));
    $value = str_replace(["\xC2\xA0", '–', '—', '−'], [' ', '-', '-', '-'], $value);
    return preg_replace('/\s+/', '', $value) ?? '';
}

function cfloat_ean_normalize(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;

    $value = str_replace(',', '.', $value);
    if (preg_match('/^(\d+)(?:\.(\d+))?[eE]\+?(\d+)$/', $value, $m)) {
        $int = $m[1];
        $frac = $m[2] ?? '';
        $exp = (int)$m[3];
        $zeros = $exp - strlen($frac);
        if ($zeros >= 0) {
            $value = $int . $frac . str_repeat('0', $zeros);
        }
    }

    $digits = preg_replace('/\D+/', '', $value) ?? '';
    $len = strlen($digits);
    if ($len < 8 || $len > 14) return null;
    return $digits;
}

/**
 * Vrací normalizované kandidáty varianty. Např. "Velikost L" => ["L"].
 * @return list<string>
 */
function cfloat_ean_variant_candidates(string $value): array
{
    $value = trim(cfloat_ean_ascii($value));
    if ($value === '') return [];

    $value = strtoupper($value);
    $value = str_replace(["\xC2\xA0", '–', '—', '−'], [' ', '-', '-', '-'], $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    // Odstranění běžných názvů atributů z API objednávek.
    $value = preg_replace(
        '/^(VELIKOST|SIZE|VARIANTA|ROZMER|DELKA|SIRKA|BARVA|OBJEM|HMOTNOST)\s*[:=\-]?\s*/',
        '',
        $value
    ) ?? $value;

    $parts = preg_split('/\s*[|;,\/]\s*/', $value) ?: [$value];
    array_unshift($parts, $value);

    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $part = preg_replace('/[^A-Z0-9.+\-]+/', '', $part) ?? '';
        if ($part === '') continue;
        $out[$part] = true;

        // CSV variant obsahuje např. 2-28, objednávka ale „Velikost XL“.
        $sizeAlias = cfloat_ean_size_alias($part);
        if ($sizeAlias !== null) {
            $out[$sizeAlias] = true;
        }
    }
    return array_keys($out);
}

/**
 * Převod číselníku velikostí Eshop-rychle na text, který přichází v objednávce.
 * Např. 2-28 = XL.
 */
function cfloat_ean_size_alias(string $value): ?string
{
    static $map = [
        '2-24' => 'XS',
        '2-25' => 'S',
        '2-26' => 'M',
        '2-27' => 'L',
        '2-28' => 'XL',
        '2-29' => 'XXL',
        '2-30' => '3XL',
    ];

    $value = strtoupper(trim($value));
    return $map[$value] ?? null;
}

function cfloat_ean_detect_delimiter(string $line): string
{
    return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
}

/** @return int|null */
function cfloat_ean_find_header(array $header, array $names): ?int
{
    $wanted = [];
    foreach ($names as $name) $wanted[cfloat_ean_header((string)$name)] = true;
    foreach ($header as $i => $name) {
        if (isset($wanted[cfloat_ean_header((string)$name)])) return (int)$i;
    }
    return null;
}

/**
 * Z pole Parametry vytáhne hodnoty variantních atributů, zejména velikost.
 * @return list<string>
 */
function cfloat_ean_variants_from_parameters(string $parameters): array
{
    $parameters = cfloat_ean_utf8($parameters);
    if (trim($parameters) === '') return [];

    $allowed = [
        'VELIKOST' => true, 'SIZE' => true, 'VARIANTA' => true,
        'ROZMER' => true, 'DELKA' => true, 'SIRKA' => true,
        'BARVA' => true, 'OBJEM' => true, 'HMOTNOST' => true,
    ];

    $result = [];
    foreach (explode('|-|', $parameters) as $pair) {
        $parts = explode('#-#', $pair, 2);
        if (count($parts) !== 2) continue;
        $label = cfloat_ean_header($parts[0]);
        if (!isset($allowed[$label])) continue;
        foreach (cfloat_ean_variant_candidates($parts[1]) as $candidate) {
            $result[$candidate] = true;
        }
    }
    return array_keys($result);
}

/**
 * @return list<string>
 */
function cfloat_ean_order_variant_candidates(array $row): array
{
    $out = [];
    foreach (cfloat_ean_variant_candidates((string)($row['variant_description'] ?? '')) as $v) {
        $out[$v] = true;
    }

    // Fallback do raw_json, pokud API uložilo variantu jiným názvem.
    $raw = (string)($row['raw_json'] ?? '');
    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $keys = ['variant_description', 'variant_name', 'variant', 'size', 'value'];
            $stack = [$json];
            $visited = 0;
            while ($stack && $visited < 200) {
                $current = array_pop($stack);
                $visited++;
                if (!is_array($current)) continue;
                foreach ($current as $key => $value) {
                    if (is_array($value)) {
                        $stack[] = $value;
                        continue;
                    }
                    if (!is_scalar($value)) continue;
                    if (in_array(strtolower((string)$key), $keys, true)) {
                        foreach (cfloat_ean_variant_candidates((string)$value) as $v) {
                            $out[$v] = true;
                        }
                    }
                }
            }
        }
    }

    return array_keys($out);
}

/**
 * @return array<string,mixed>
 */
function cfloat_fill_ean_auto(PDO $pdo, bool $debug = false): array
{
    // Oficiální místo pro nový SILVINI variantní export s EANy.
    // Hlavní produktový soubor není pro doplnění EAN potřeba.
    $sourceCandidates = [
        // Základní zdroj pro všechny značky.
        __DIR__ . '/CStore/Varianty/AllVarianty.csv',
        __DIR__ . '/CStore/Varianty/allvarianty.csv',

        // Známé zdroje SILVINI.
        __DIR__ . '/Aktualizace_CSTORE/Silvini/newnewvariantysilvini_var.csv',
        __DIR__ . '/Dodavatele na SS26 Aktualizace/silvini/variantysilvini_var.csv',
        __DIR__ . '/RucniNahraniAktualizace/variantysilvini_var.csv',
    ];

    // Přidá také novější exporty, jejichž název obsahuje SILVINI/varianty a končí _var.csv.
    // Tím není nutné pokaždé měnit PHP při názvu typu
    // VariantySILVINI_IMPORT_DO_ESHOPU_20260712_202421_var.csv.
    $scanDirs = [
        __DIR__ . '/Aktualizace_CSTORE/Silvini',
        __DIR__ . '/Dodavatele na SS26 Aktualizace/silvini',
        __DIR__ . '/RucniNahraniAktualizace',
    ];
    $discovered = [];
    foreach ($scanDirs as $scanDir) {
        if (!is_dir($scanDir)) continue;
        $items = @scandir($scanDir);
        if (!is_array($items)) continue;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $scanDir . '/' . $item;
            if (!is_file($full)) continue;
            $lower = strtolower($item);
            if (!str_ends_with($lower, '.csv')) continue;
            if (!str_contains($lower, 'silvini')) continue;
            if (!str_contains($lower, 'variant')) continue;
            if (!str_contains($lower, '_var')) continue;
            $discovered[] = $full;
        }
    }
    usort($discovered, static function (string $a, string $b): int {
        return ((int)@filemtime($a)) <=> ((int)@filemtime($b));
    });
    foreach ($discovered as $path) {
        $sourceCandidates[] = $path;
    }

    $csvPaths = [];
    $seenReal = [];
    foreach ($sourceCandidates as $candidate) {
        if (!is_file($candidate)) continue;
        $real = realpath($candidate) ?: $candidate;
        if (isset($seenReal[$real])) continue;
        $seenReal[$real] = true;
        $csvPaths[] = $candidate;
    }

    if (empty($csvPaths)) {
        throw new RuntimeException(
            'Nebyl nalezen žádný zdroj EAN. Nahraj SILVINI varianty do: '
            . __DIR__ . '/RucniNahraniAktualizace/variantysilvini_var.csv'
        );
    }

    $stmt = $pdo->query("\n        SELECT id, product_id, variant_id, product_number, variant_description, raw_json\n        FROM order_items\n        WHERE EAN IS NULL OR EAN = ''\n        ORDER BY id DESC\n    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $neededIds = [];
    $neededCodes = [];
    foreach ($rows as $row) {
        $pid = trim((string)($row['product_id'] ?? ''));
        $vid = trim((string)($row['variant_id'] ?? ''));
        if ($pid !== '' && $pid !== '0' && $vid !== '' && $vid !== '0') {
            $neededIds[$pid . '_' . $vid] = true;
        }
        $code = cfloat_ean_code((string)($row['product_number'] ?? ''));
        if ($code !== '') $neededCodes[$code] = true;
    }

    $mapById = [];
    $mapByCodeVariant = [];
    $eansByCode = [];
    $sourceStats = [];
    $totalCsvRows = 0;

    foreach ($csvPaths as $csvPath) {
        $fh = fopen($csvPath, 'rb');
        if (!$fh) continue;

        $first = fgets($fh);
        if ($first === false) {
            fclose($fh);
            continue;
        }
        $delimiter = cfloat_ean_detect_delimiter($first);
        rewind($fh);

        $header = fgetcsv($fh, 0, $delimiter);
        if ($header === false) {
            fclose($fh);
            continue;
        }

        $idxId = cfloat_ean_find_header($header, ['ID']);
        $idxCode = cfloat_ean_find_header($header, ['Kod vyrobku', 'Kód výrobku', 'MPN']);
        $idxEan = cfloat_ean_find_header($header, ['EAN kod', 'EAN kód', 'EAN']);
        $idxParams = cfloat_ean_find_header($header, ['Parametry']);
        $idxP1 = cfloat_ean_find_header($header, ['Parametr 1']);
        $idxP2 = cfloat_ean_find_header($header, ['Parametr 2']);
        $idxP3 = cfloat_ean_find_header($header, ['Parametr 3']);
        $idxBook = cfloat_ean_find_header($header, [
            'Pripojit ciselnik vlastnosti (oddelujte znakem "|")',
            'Připojit číselník vlastností (oddělujte znakem "|")',
        ]);

        if ($idxEan === null) {
            fclose($fh);
            $sourceStats[] = [
                'path' => $csvPath,
                'delimiter' => $delimiter,
                'rows' => 0,
                'used' => false,
                'note' => 'chybí sloupec EAN',
            ];
            continue;
        }

        $csvRows = 0;
        $rowsWithEan = 0;
        $exactNeededFound = 0;

        while (($csvRow = fgetcsv($fh, 0, $delimiter)) !== false) {
            $csvRows++;
            if (!$csvRow) continue;

            $ean = cfloat_ean_normalize((string)($csvRow[$idxEan] ?? ''));
            if ($ean === null) continue;
            $rowsWithEan++;

            if ($idxId !== null) {
                $id = trim((string)($csvRow[$idxId] ?? ''));
                if ($id !== '' && isset($neededIds[$id])) {
                    // Pozdější/ruční zdroj má přednost.
                    $mapById[$id] = $ean;
                    $exactNeededFound++;
                }
            }

            $code = $idxCode !== null ? cfloat_ean_code((string)($csvRow[$idxCode] ?? '')) : '';
            if ($code === '' || !isset($neededCodes[$code])) continue;

            $eansByCode[$code][$ean] = true;

            $variants = [];
            if ($idxParams !== null) {
                foreach (cfloat_ean_variants_from_parameters((string)($csvRow[$idxParams] ?? '')) as $v) {
                    $variants[$v] = true;
                }
            }
            foreach ([$idxP1, $idxP2, $idxP3, $idxBook] as $idxParam) {
                if ($idxParam === null) continue;
                foreach (cfloat_ean_variant_candidates((string)($csvRow[$idxParam] ?? '')) as $v) {
                    $variants[$v] = true;
                }
            }

            foreach (array_keys($variants) as $variant) {
                if (!isset($mapByCodeVariant[$code][$variant])) {
                    $mapByCodeVariant[$code][$variant] = $ean;
                } elseif ($mapByCodeVariant[$code][$variant] !== $ean) {
                    // Stejný kód+velikost s různými EANy nepoužívat jako fallback.
                    $mapByCodeVariant[$code][$variant] = null;
                }
            }
        }
        fclose($fh);

        $totalCsvRows += $csvRows;
        $sourceStats[] = [
            'path' => $csvPath,
            'delimiter' => $delimiter,
            'rows' => $csvRows,
            'rows_with_ean' => $rowsWithEan,
            'exact_needed_found' => $exactNeededFound,
            'used' => true,
            'note' => '',
        ];
    }

    $update = $pdo->prepare("\n        UPDATE order_items\n        SET EAN = :ean\n        WHERE id = :id AND (EAN IS NULL OR EAN = '')\n    ");

    $matchedById = 0;
    $matchedByCodeVariant = 0;
    $matchedByCodeOnly = 0;
    $updated = 0;
    $unmatched = [];

    foreach ($rows as $row) {
        $ean = null;
        $method = '';

        $pid = trim((string)($row['product_id'] ?? ''));
        $vid = trim((string)($row['variant_id'] ?? ''));
        $idKey = ($pid !== '' && $pid !== '0' && $vid !== '' && $vid !== '0') ? $pid . '_' . $vid : '';

        if ($idKey !== '' && isset($mapById[$idKey])) {
            $ean = $mapById[$idKey];
            $method = 'id';
        }

        $code = cfloat_ean_code((string)($row['product_number'] ?? ''));
        if ($ean === null && $code !== '' && isset($mapByCodeVariant[$code])) {
            foreach (cfloat_ean_order_variant_candidates($row) as $variant) {
                if (isset($mapByCodeVariant[$code][$variant]) && $mapByCodeVariant[$code][$variant] !== null) {
                    $ean = $mapByCodeVariant[$code][$variant];
                    $method = 'code_variant';
                    break;
                }
            }
        }

        if ($ean === null && $code !== '' && isset($eansByCode[$code]) && count($eansByCode[$code]) === 1) {
            $ean = (string)array_key_first($eansByCode[$code]);
            $method = 'code_only';
        }

        if ($ean === null) {
            if (count($unmatched) < 20) {
                $unmatched[] = [
                    'id' => (int)$row['id'],
                    'key' => $idKey,
                    'code' => (string)($row['product_number'] ?? ''),
                    'variant' => (string)($row['variant_description'] ?? ''),
                ];
            }
            continue;
        }

        $update->execute([':ean' => $ean, ':id' => (int)$row['id']]);
        if ($update->rowCount() > 0) {
            $updated++;
            if ($method === 'id') $matchedById++;
            elseif ($method === 'code_variant') $matchedByCodeVariant++;
            elseif ($method === 'code_only') $matchedByCodeOnly++;
        }
    }

    return [
        'sources' => $sourceStats,
        'csv_rows' => $totalCsvRows,
        'checked_rows' => count($rows),
        'needed_id_keys' => count($neededIds),
        'needed_codes' => count($neededCodes),
        'matched_by_id' => $matchedById,
        'matched_by_code_variant' => $matchedByCodeVariant,
        'matched_by_code_only' => $matchedByCodeOnly,
        'updated_rows' => $updated,
        'unmatched_examples' => $unmatched,
    ];
}

if ($runningDirect) {
try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('PDO připojení není k dispozici.');
    }

    $stats = cfloat_fill_ean_auto($pdo, $debug);

    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "OK\n";
        echo 'version: ' . $scriptVersion . "\n";
        echo "sources:\n";
        foreach ($stats['sources'] as $src) {
            echo '- ' . $src['path']
                . ' | used=' . (!empty($src['used']) ? '1' : '0')
                . ' | rows=' . (int)($src['rows'] ?? 0)
                . ' | rows_with_ean=' . (int)($src['rows_with_ean'] ?? 0)
                . ' | exact_needed_found=' . (int)($src['exact_needed_found'] ?? 0)
                . (!empty($src['note']) ? ' | ' . $src['note'] : '')
                . "\n";
        }
        echo 'csv_rows: ' . $stats['csv_rows'] . "\n";
        echo 'checked_rows: ' . $stats['checked_rows'] . "\n";
        echo 'needed_id_keys: ' . $stats['needed_id_keys'] . "\n";
        echo 'needed_codes: ' . $stats['needed_codes'] . "\n";
        echo 'matched_by_id: ' . $stats['matched_by_id'] . "\n";
        echo 'matched_by_code_variant: ' . $stats['matched_by_code_variant'] . "\n";
        echo 'matched_by_code_only: ' . $stats['matched_by_code_only'] . "\n";
        echo 'updated_rows: ' . $stats['updated_rows'] . "\n";
        if (!empty($stats['unmatched_examples'])) {
            echo "unmatched_examples:\n";
            foreach ($stats['unmatched_examples'] as $item) {
                echo '- row_id=' . $item['id']
                    . ' key=' . $item['key']
                    . ' code=' . $item['code']
                    . ' variant=' . $item['variant'] . "\n";
            }
        }
    }
} catch (Throwable $e) {
    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ERROR: ' . $e->getMessage() . "\n";
    } else {
        error_log('fill_ean_auto.php: ' . $e->getMessage());
    }
}

}
