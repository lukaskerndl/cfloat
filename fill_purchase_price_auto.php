<?php
declare(strict_types=1);

/**
 * Automatické doplnění nákupních cen do order_items.
 *
 * Pořadí hledání:
 *  1) přesná shoda EAN v tabulce Nakupni_ceny,
 *  2) objednací / katalogové číslo v tabulce Nakupni_ceny_kody.
 *
 * Zdroje cen:
 *  - VAVRYS / CRAFT / INOV-8: DB tabulka vavrys_variants,
 *      cena = price * 0.97 * 1.21
 *  - SILVINI: aktuální REPORTS_SALESPRICE_CZK.XML,
 *      běžná cena = CZ_BUY_BASIC * 0.78 * 1.21,
 *      akční cena = CZ_BUY_PROMO * 1.21
 *  - DEVOLD a další značky z feed-3: lokální Dodavatele/DEVOLD/XML/C.xml,
 *      cena = voc * 1.21
 *  - další značky (např. Didriksons) zůstávají dostupné přes již naplněnou
 *      tabulku Nakupni_ceny.
 *
 * Existující kladnou cenu u objednávky nepřepisuje. Historická objednávka si
 * tak zachová cenu, která byla doplněna v okamžiku zpracování.
 */

const CFLOAT_PRICE_REFRESH_SECONDS = 600; // nejvýše 1 stažení / refresh za 10 minut
const CFLOAT_SILVINI_PRICE_URL = 'http://95.80.221.202:3380/REPORTS_SALESPRICE_CZK.XML';

function cfloat_price_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
        $st->execute([':t' => $table]);
        return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function cfloat_price_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table . ':' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1");
        $st->execute([':t' => $table, ':c' => $column]);
        return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function cfloat_price_norm_ean($value): ?string
{
    $s = trim((string)$value);
    if ($s === '') return null;

    $s = str_replace(["\xC2\xA0", ' '], '', $s);
    $s = strtoupper(str_replace(',', '.', $s));

    if (strpos($s, 'E') !== false && preg_match('/^([0-9]+(?:\.[0-9]+)?)E([+\-]?[0-9]+)$/', $s, $m)) {
        $mantissa = $m[1];
        $exp = (int)$m[2];
        if ($exp >= 0) {
            [$integer, $fraction] = array_pad(explode('.', $mantissa, 2), 2, '');
            $digits = $integer . $fraction;
            $zeros = $exp - strlen($fraction);
            if ($zeros >= 0) {
                $s = $digits . str_repeat('0', $zeros);
            }
        }
    }

    $digits = preg_replace('/\D+/', '', $s) ?? '';
    $len = strlen($digits);
    if ($len < 8 || $len > 14) return null;
    return $digits;
}

function cfloat_price_norm_code($value): ?string
{
    $s = trim((string)$value);
    if ($s === '') return null;
    $s = str_replace(["\xC2\xA0", "\r", "\n", "\t"], [' ', ' ', ' ', ' '], $s);
    $s = trim($s, " \t\n\r\0\x0B\"'=");
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    if ($s === '') return null;
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

function cfloat_price_parse_number($value): ?float
{
    $s = trim((string)$value);
    if ($s === '') return null;
    $s = str_replace(["\xC2\xA0", ' '], '', $s);
    $s = str_replace(',', '.', $s);
    $s = preg_replace('/[^0-9.\-]/', '', $s) ?? '';
    if ($s === '' || !is_numeric($s)) return null;
    $v = (float)$s;
    if (!is_finite($v) || $v <= 0) return null;
    return $v;
}

function cfloat_price_ensure_tables(PDO $pdo): void
{
    if (!cfloat_price_table_exists($pdo, 'Nakupni_ceny')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `Nakupni_ceny` (
            `ean` VARCHAR(50) NOT NULL,
            `price` DECIMAL(20,8) NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'CZK',
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ean`),
            KEY `idx_updated_at` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `Nakupni_ceny_kody` (
        `supplier` VARCHAR(40) NOT NULL,
        `code` VARCHAR(191) NOT NULL,
        `code_norm` VARCHAR(191) NOT NULL,
        `price` DECIMAL(20,8) NOT NULL,
        `currency` CHAR(3) NOT NULL DEFAULT 'CZK',
        `source` VARCHAR(255) NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`supplier`, `code_norm`),
        KEY `idx_code_norm` (`code_norm`),
        KEY `idx_updated_at` (`updated_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** @return PDOStatement */
function cfloat_price_prepare_ean_upsert(PDO $pdo)
{
    $columns = ['ean', 'price', 'currency', 'updated_at'];
    $values = [':ean', ':price', ':currency', 'NOW()'];
    $updates = ['price = VALUES(price)', 'currency = VALUES(currency)', 'updated_at = VALUES(updated_at)'];

    if (cfloat_price_column_exists($pdo, 'Nakupni_ceny', 'supplier')) {
        $columns[] = 'supplier';
        $values[] = ':supplier';
        $updates[] = 'supplier = VALUES(supplier)';
    }
    if (cfloat_price_column_exists($pdo, 'Nakupni_ceny', 'priority')) {
        $columns[] = 'priority';
        $values[] = ':priority';
        $updates[] = 'priority = VALUES(priority)';
    }
    if (cfloat_price_column_exists($pdo, 'Nakupni_ceny', 'source')) {
        $columns[] = 'source';
        $values[] = ':source';
        $updates[] = 'source = VALUES(source)';
    }

    $quotedColumns = array_map(static fn(string $c): string => '`' . $c . '`', $columns);
    $sql = 'INSERT INTO `Nakupni_ceny` (' . implode(',', $quotedColumns) . ') VALUES (' . implode(',', $values) . ')'
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    return $pdo->prepare($sql);
}

function cfloat_price_execute_ean_upsert(PDOStatement $stmt, PDO $pdo, string $ean, float $price, string $supplier, int $priority, string $source): void
{
    $params = [
        ':ean' => $ean,
        ':price' => $price,
        ':currency' => 'CZK',
    ];
    if (cfloat_price_column_exists($pdo, 'Nakupni_ceny', 'supplier')) $params[':supplier'] = $supplier;
    if (cfloat_price_column_exists($pdo, 'Nakupni_ceny', 'priority')) $params[':priority'] = $priority;
    if (cfloat_price_column_exists($pdo, 'Nakupni_ceny', 'source')) $params[':source'] = $source;
    $stmt->execute($params);
}

/** @return PDOStatement */
function cfloat_price_prepare_code_upsert(PDO $pdo)
{
    return $pdo->prepare("INSERT INTO `Nakupni_ceny_kody`
        (`supplier`,`code`,`code_norm`,`price`,`currency`,`source`,`updated_at`)
        VALUES (:supplier,:code,:code_norm,:price,'CZK',:source,NOW())
        ON DUPLICATE KEY UPDATE
          `code`=VALUES(`code`),
          `price`=VALUES(`price`),
          `currency`=VALUES(`currency`),
          `source`=VALUES(`source`),
          `updated_at`=VALUES(`updated_at`)");
}

function cfloat_price_execute_code_upsert(PDOStatement $stmt, string $supplier, $code, float $price, string $source): bool
{
    $raw = trim((string)$code);
    $norm = cfloat_price_norm_code($raw);
    if ($norm === null || $price <= 0) return false;
    $stmt->execute([
        ':supplier' => $supplier,
        ':code' => $raw,
        ':code_norm' => $norm,
        ':price' => $price,
        ':source' => $source,
    ]);
    return true;
}

function cfloat_price_node_text(DOMNode $root, array $names): ?string
{
    $doc = $root->ownerDocument;
    if (!$doc) return null;
    $xpath = new DOMXPath($doc);
    $parts = [];
    foreach ($names as $name) {
        $name = strtolower((string)$name);
        $parts[] = "translate(local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='" . str_replace("'", '', $name) . "'";
    }
    $nodes = $xpath->query('.//*[' . implode(' or ', $parts) . ']', $root);
    if (!$nodes) return null;
    foreach ($nodes as $node) {
        $value = trim((string)$node->textContent);
        if ($value !== '') return $value;
    }
    return null;
}

function cfloat_price_node_texts(DOMNode $root, array $names): array
{
    $out = [];
    $doc = $root->ownerDocument;
    if (!$doc) return $out;
    $xpath = new DOMXPath($doc);
    $parts = [];
    foreach ($names as $name) {
        $name = strtolower((string)$name);
        $parts[] = "translate(local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='" . str_replace("'", '', $name) . "'";
    }
    $nodes = $xpath->query('.//*[' . implode(' or ', $parts) . ']', $root);
    if (!$nodes) return $out;
    foreach ($nodes as $node) {
        $value = trim((string)$node->textContent);
        if ($value !== '') $out[$value] = $value;
    }
    return array_values($out);
}

function cfloat_price_refresh_vavrys(PDO $pdo): array
{
    $sourceTable = '';
    $sourceCodeColumn = '';
    $pricesCount = 0;
    $variantsCount = 0;
    if (cfloat_price_table_exists($pdo, 'vavrys_prices')) {
        try {
            $pricesCount = (int)$pdo->query("SELECT COUNT(*) FROM `vavrys_prices` WHERE ean IS NOT NULL AND TRIM(ean) <> '' AND price > 0")->fetchColumn();
        } catch (Throwable $ignore) {}
    }
    if (cfloat_price_table_exists($pdo, 'vavrys_variants')) {
        try {
            $variantsCount = (int)$pdo->query("SELECT COUNT(*) FROM `vavrys_variants` WHERE ean IS NOT NULL AND TRIM(ean) <> '' AND price > 0")->fetchColumn();
        } catch (Throwable $ignore) {}
    }
    if ($pricesCount > 0 && $pricesCount >= $variantsCount) {
        $sourceTable = 'vavrys_prices';
        $sourceCodeColumn = 'supplier_code';
    } elseif ($variantsCount > 0) {
        $sourceTable = 'vavrys_variants';
        $sourceCodeColumn = 'karcislo';
    }
    if ($sourceTable === '') {
        return ['ok' => false, 'message' => 'Tabulka vavrys_prices ani vavrys_variants není dostupná.'];
    }

    $columns = ['`ean`', '`price`', '`currency`', '`updated_at`'];
    $selects = ['CAST(vv.ean AS CHAR)', '(vv.price * 0.97 * 1.21)', "'CZK'", 'NOW()'];
    $updates = ['`price`=VALUES(`price`)', '`currency`=VALUES(`currency`)', '`updated_at`=VALUES(`updated_at`)'];

    if (cfloat_price_column_exists($pdo, 'Nakupni_ceny', 'supplier')) {
        $columns[] = '`supplier`';
        $selects[] = "'VAVRYS'";
        $updates[] = '`supplier`=VALUES(`supplier`)';
    }
    if (cfloat_price_column_exists($pdo, 'Nakupni_ceny', 'priority')) {
        $columns[] = '`priority`';
        $selects[] = '100';
        $updates[] = '`priority`=VALUES(`priority`)';
    }
    if (cfloat_price_column_exists($pdo, 'Nakupni_ceny', 'source')) {
        $columns[] = '`source`';
        $selects[] = "'db:{$sourceTable}'";
        $updates[] = '`source`=VALUES(`source`)';
    }

    $sql = 'INSERT INTO `Nakupni_ceny` (' . implode(',', $columns) . ') '
        . 'SELECT ' . implode(',', $selects) . " FROM `{$sourceTable}` vv "
        . "WHERE vv.ean IS NOT NULL AND TRIM(vv.ean) <> '' AND vv.price IS NOT NULL AND vv.price > 0 "
        . 'ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
    $savedEan = (int)$pdo->exec($sql);

    $savedCode = 0;
    $baseExpr = 'TRIM(' . $sourceCodeColumn . ')';
    $aliases = [$baseExpr];
    if ($sourceTable === 'vavrys_variants') {
        $aliases[] = "CONCAT(TRIM(karcislo), '-', TRIM(color_code))";
        $aliases[] = "CONCAT(TRIM(karcislo), TRIM(color_code))";
        $aliases[] = "CONCAT(TRIM(karcislo), '/', TRIM(color_code))";
    }

    foreach ($aliases as $idx => $expr) {
        $needsColor = $sourceTable === 'vavrys_variants' && $idx > 0;
        $where = "{$sourceCodeColumn} IS NOT NULL AND TRIM({$sourceCodeColumn}) <> '' AND price IS NOT NULL AND price > 0";
        if ($needsColor) $where .= " AND color_code IS NOT NULL AND TRIM(color_code) <> ''";

        $codeSql = "INSERT INTO `Nakupni_ceny_kody`
            (`supplier`,`code`,`code_norm`,`price`,`currency`,`source`,`updated_at`)
            SELECT 'VAVRYS', x.code, UPPER(REPLACE(TRIM(x.code), ' ', '')), x.price, 'CZK', 'db:{$sourceTable}', NOW()
            FROM (
                SELECT {$expr} AS code, MAX(price * 0.97 * 1.21) AS price
                FROM `{$sourceTable}`
                WHERE {$where}
                GROUP BY {$expr}
            ) x
            WHERE x.code IS NOT NULL AND TRIM(x.code) <> '' AND x.price > 0
            ON DUPLICATE KEY UPDATE
              `code`=VALUES(`code`), `price`=VALUES(`price`), `currency`=VALUES(`currency`),
              `source`=VALUES(`source`), `updated_at`=VALUES(`updated_at`)";
        $savedCode += (int)$pdo->exec($codeSql);
    }

    return ['ok' => true, 'source' => $sourceTable, 'source_rows' => max($pricesCount, $variantsCount), 'ean' => $savedEan, 'codes' => $savedCode];
}

function cfloat_price_find_devold_xml(): string
{
    $dir = __DIR__ . '/Dodavatele/DEVOLD/XML';
    foreach (['C.xml', 'c.xml', 'C', 'devold.xml', 'DEVOLD.xml'] as $name) {
        $path = $dir . '/' . $name;
        if (is_file($path) && is_readable($path)) return $path;
    }
    return '';
}

function cfloat_price_refresh_devold(PDO $pdo, string $xmlPath): array
{
    if ($xmlPath === '' || !is_file($xmlPath)) return ['ok' => false, 'message' => 'DEVOLD XML nebylo nalezeno.'];

    $reader = new XMLReader();
    if (!$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
        return ['ok' => false, 'message' => 'DEVOLD XML nelze otevřít.'];
    }

    $eanStmt = cfloat_price_prepare_ean_upsert($pdo);
    $codeStmt = cfloat_price_prepare_code_upsert($pdo);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $savedEan = 0;
    $savedCode = 0;
    $processed = 0;
    $ownTransaction = !$pdo->inTransaction();

    try {
        if ($ownTransaction) $pdo->beginTransaction();
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || strtolower($reader->localName) !== 'item') continue;
            $node = $reader->expand($doc);
            if (!$node) continue;
            $processed++;

            $voc = cfloat_price_parse_number(cfloat_price_node_text($node, ['voc']) ?? '');
            if ($voc === null) continue;
            $price = $voc * 1.21;
            $ean = cfloat_price_norm_ean(cfloat_price_node_text($node, ['ean', 'barcode', 'eancode']) ?? '');
            if ($ean !== null) {
                cfloat_price_execute_ean_upsert($eanStmt, $pdo, $ean, $price, 'DEVOLD', 80, 'local:' . basename($xmlPath));
                $savedEan++;
            }

            $codes = cfloat_price_node_texts($node, ['code', 'katalog', 'varianta', 'catalog', 'sku']);
            foreach ($codes as $code) {
                if (cfloat_price_execute_code_upsert($codeStmt, 'DEVOLD', $code, $price, 'local:' . basename($xmlPath))) $savedCode++;
            }
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        $reader->close();
        throw $e;
    }
    $reader->close();

    return ['ok' => true, 'processed' => $processed, 'ean' => $savedEan, 'codes' => $savedCode];
}

function cfloat_price_download_silvini(string $destination): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'message' => 'Na serveru není cURL.'];
    $dir = dirname($destination);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'Nelze vytvořit složku pro SILVINI feed.'];
    }

    $tmp = $destination . '._tmp';
    $fh = @fopen($tmp, 'wb');
    if (!$fh) return ['ok' => false, 'message' => 'Nelze vytvořit dočasný SILVINI soubor.'];

    $ch = curl_init(CFLOAT_SILVINI_PRICE_URL);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_USERAGENT => 'CFloat-Purchase-Price/2.0',
        CURLOPT_HTTPHEADER => ['Accept: application/xml,text/xml,*/*'],
    ]);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($ok === false || $http >= 400 || $http === 0 || !is_file($tmp) || filesize($tmp) < 100) {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'SILVINI feed se nepodařilo stáhnout: ' . ($error !== '' ? $error : 'HTTP ' . $http)];
    }

    $head = @file_get_contents($tmp, false, null, 0, 256) ?: '';
    if (stripos(ltrim($head), '<html') === 0 || stripos(ltrim($head), '<!doctype html') === 0) {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'SILVINI server vrátil HTML místo XML.'];
    }

    if (!@rename($tmp, $destination)) {
        if (!@copy($tmp, $destination)) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'SILVINI XML nelze uložit.'];
        }
        @unlink($tmp);
    }
    return ['ok' => true, 'bytes' => filesize($destination) ?: 0];
}

function cfloat_price_refresh_silvini(PDO $pdo, string $xmlPath): array
{
    if (!is_file($xmlPath)) return ['ok' => false, 'message' => 'SILVINI cenové XML nebylo nalezeno.'];

    $reader = new XMLReader();
    if (!$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
        return ['ok' => false, 'message' => 'SILVINI cenové XML nelze otevřít.'];
    }

    $recordNames = ['reports_salesprice_czk', 'reports_salesprice', 'salesprice', 'item', 'row', 'record'];
    $eanStmt = cfloat_price_prepare_ean_upsert($pdo);
    $codeStmt = cfloat_price_prepare_code_upsert($pdo);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $processed = 0;
    $savedEan = 0;
    $savedCode = 0;
    $ownTransaction = !$pdo->inTransaction();

    try {
        if ($ownTransaction) $pdo->beginTransaction();
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) continue;
            $name = strtolower($reader->localName);
            if (!in_array($name, $recordNames, true)) continue;
            $node = $reader->expand($doc);
            if (!$node) continue;

            $ean = cfloat_price_norm_ean(cfloat_price_node_text($node, ['ean', 'barcode', 'eancode']) ?? '');
            if ($ean === null) continue;
            $processed++;

            $discounted = trim((string)(cfloat_price_node_text($node, ['isdiscountedb2b']) ?? '0')) === '1';
            $basic = cfloat_price_parse_number(cfloat_price_node_text($node, ['cz_buy_basic']) ?? '');
            $promo = cfloat_price_parse_number(cfloat_price_node_text($node, ['cz_buy_promo']) ?? '');
            $price = null;
            if ($discounted && $promo !== null) $price = $promo * 1.21;
            if (!$discounted && $basic !== null) $price = $basic * 0.78 * 1.21;
            if ($price === null || $price <= 0) continue;

            cfloat_price_execute_ean_upsert($eanStmt, $pdo, $ean, $price, 'SILVINI', 90, 'xml:REPORTS_SALESPRICE_CZK.XML');
            $savedEan++;

            $codes = cfloat_price_node_texts($node, ['poslsort', 'cislo_mat', 'code', 'kod', 'product_code', 'sku']);
            foreach ($codes as $code) {
                if (cfloat_price_execute_code_upsert($codeStmt, 'SILVINI', $code, $price, 'xml:REPORTS_SALESPRICE_CZK.XML')) $savedCode++;
            }
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        $reader->close();
        throw $e;
    }
    $reader->close();

    return ['ok' => true, 'processed' => $processed, 'ean' => $savedEan, 'codes' => $savedCode];
}

function cfloat_price_state_load(): array
{
    $path = __DIR__ . '/.purchase_price_refresh_state.json';
    if (!is_file($path)) return [];
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function cfloat_price_state_save(array $state): void
{
    $path = __DIR__ . '/.purchase_price_refresh_state.json';
    $tmp = $path . '.tmp';
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return;
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) @rename($tmp, $path);
}

function cfloat_refresh_purchase_price_sources(PDO $pdo, bool $force = false): array
{
    @set_time_limit(0);
    cfloat_price_ensure_tables($pdo);
    $result = ['ok' => true, 'sources' => [], 'errors' => []];

    $lockPath = __DIR__ . '/.lock_purchase_price_refresh';
    $lock = @fopen($lockPath, 'c+');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return ['ok' => true, 'sources' => [], 'errors' => [], 'message' => 'Aktualizace cen právě běží v jiném procesu.'];
    }

    try {
        $state = cfloat_price_state_load();
        $now = time();

        $lastVavrys = (int)($state['vavrys_at'] ?? 0);
        if ($force || $lastVavrys < $now - CFLOAT_PRICE_REFRESH_SECONDS) {
            try {
                $r = cfloat_price_refresh_vavrys($pdo);
                $result['sources']['VAVRYS'] = $r;
                if (!empty($r['ok'])) $state['vavrys_at'] = $now;
                else $result['errors'][] = 'VAVRYS: ' . ($r['message'] ?? 'neznámá chyba');
            } catch (Throwable $e) {
                $result['errors'][] = 'VAVRYS: ' . $e->getMessage();
            }
        }

        $devoldPath = cfloat_price_find_devold_xml();
        $devoldMtime = $devoldPath !== '' ? (int)@filemtime($devoldPath) : 0;
        if ($devoldPath !== '' && ($force || $devoldMtime !== (int)($state['devold_mtime'] ?? 0))) {
            try {
                $r = cfloat_price_refresh_devold($pdo, $devoldPath);
                $result['sources']['DEVOLD'] = $r;
                if (!empty($r['ok'])) $state['devold_mtime'] = $devoldMtime;
                else $result['errors'][] = 'DEVOLD: ' . ($r['message'] ?? 'neznámá chyba');
            } catch (Throwable $e) {
                $result['errors'][] = 'DEVOLD: ' . $e->getMessage();
            }
        }

        $silviniPath = __DIR__ . '/Dodavatele/SILVINI/salesprice.xml';
        $silviniAge = is_file($silviniPath) ? ($now - (int)@filemtime($silviniPath)) : PHP_INT_MAX;
        $lastSilviniAttempt = (int)($state['silvini_download_attempt_at'] ?? 0);
        $shouldDownloadSilvini = $force || (
            $silviniAge >= CFLOAT_PRICE_REFRESH_SECONDS
            && $lastSilviniAttempt < $now - CFLOAT_PRICE_REFRESH_SECONDS
        );
        if ($shouldDownloadSilvini) {
            $state['silvini_download_attempt_at'] = $now;
            $download = cfloat_price_download_silvini($silviniPath);
            $result['sources']['SILVINI_DOWNLOAD'] = $download;
            if (empty($download['ok'])) {
                $result['errors'][] = 'SILVINI stažení: ' . ($download['message'] ?? 'neznámá chyba');
            }
        }
        $silviniMtime = is_file($silviniPath) ? (int)@filemtime($silviniPath) : 0;
        if ($silviniMtime > 0 && ($force || $silviniMtime !== (int)($state['silvini_mtime'] ?? 0))) {
            try {
                $r = cfloat_price_refresh_silvini($pdo, $silviniPath);
                $result['sources']['SILVINI'] = $r;
                if (!empty($r['ok'])) $state['silvini_mtime'] = $silviniMtime;
                else $result['errors'][] = 'SILVINI: ' . ($r['message'] ?? 'neznámá chyba');
            } catch (Throwable $e) {
                $result['errors'][] = 'SILVINI: ' . $e->getMessage();
            }
        }

        $state['last_run_at'] = $now;
        cfloat_price_state_save($state);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }

    if (!empty($result['errors'])) $result['ok'] = false;
    return $result;
}

function cfloat_price_date_where(string $from, string $to, array &$params): string
{
    $sql = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND o.created_at >= :purchase_from';
        $params[':purchase_from'] = $from . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND o.created_at <= :purchase_to';
        $params[':purchase_to'] = $to . ' 23:59:59';
    }
    return $sql;
}

/**
 * @return array{ok:bool,before_missing:int,updated_ean:int,updated_code:int,after_missing:int,refresh:array,message:string}
 */
function cfloat_fill_purchase_price_auto(PDO $pdo, bool $refreshSources = true, bool $forceRefresh = false, string $from = '', string $to = ''): array
{
    cfloat_price_ensure_tables($pdo);
    $refresh = $refreshSources ? cfloat_refresh_purchase_price_sources($pdo, $forceRefresh) : ['ok' => true, 'sources' => [], 'errors' => []];

    $countParams = [];
    $dateWhere = cfloat_price_date_where($from, $to, $countParams);
    $countSql = "SELECT COUNT(*) FROM order_items oi INNER JOIN orders o ON o.id_order = oi.id_order
        WHERE (oi.nakupni_cena IS NULL OR oi.nakupni_cena = 0)" . $dateWhere;
    $countSt = $pdo->prepare($countSql);
    $countSt->execute($countParams);
    $before = (int)$countSt->fetchColumn();

    $params = [];
    $dateWhere = cfloat_price_date_where($from, $to, $params);
    $sqlEan = "UPDATE order_items oi
        INNER JOIN orders o ON o.id_order = oi.id_order
        INNER JOIN Nakupni_ceny nc ON TRIM(nc.ean) = TRIM(oi.EAN)
        SET oi.nakupni_cena = nc.price
        WHERE (oi.nakupni_cena IS NULL OR oi.nakupni_cena = 0)
          AND oi.EAN IS NOT NULL AND TRIM(oi.EAN) <> ''
          AND nc.price IS NOT NULL AND nc.price > 0" . $dateWhere;
    $stEan = $pdo->prepare($sqlEan);
    $stEan->execute($params);
    $updatedEan = $stEan->rowCount();

    $params = [];
    $dateWhere = cfloat_price_date_where($from, $to, $params);
    $sqlCode = "UPDATE order_items oi
        INNER JOIN orders o ON o.id_order = oi.id_order
        INNER JOIN (
            SELECT code_norm, MAX(price) AS price
            FROM Nakupni_ceny_kody
            WHERE price IS NOT NULL AND price > 0
            GROUP BY code_norm
        ) nk ON nk.code_norm = UPPER(REPLACE(TRIM(oi.product_number), ' ', ''))
        SET oi.nakupni_cena = nk.price
        WHERE (oi.nakupni_cena IS NULL OR oi.nakupni_cena = 0)
          AND oi.product_number IS NOT NULL AND TRIM(oi.product_number) <> ''" . $dateWhere;
    $stCode = $pdo->prepare($sqlCode);
    $stCode->execute($params);
    $updatedCode = $stCode->rowCount();

    $countSt = $pdo->prepare($countSql);
    $countSt->execute($countParams);
    $after = (int)$countSt->fetchColumn();

    $errors = $refresh['errors'] ?? [];
    $message = 'Nákupní ceny doplněny: podle EAN ' . (int)$updatedEan . '×, podle objednacího čísla ' . (int)$updatedCode . '×.';
    if ($after > 0) $message .= ' Bez ceny zůstává ' . $after . ' položek.';
    if (!empty($errors)) $message .= ' Některý zdroj se nepodařilo aktualizovat: ' . implode(' | ', $errors);

    return [
        'ok' => true,
        'warnings' => $errors,
        'before_missing' => $before,
        'updated_ean' => (int)$updatedEan,
        'updated_code' => (int)$updatedCode,
        'after_missing' => $after,
        'refresh' => $refresh,
        'message' => $message,
    ];
}

$runningDirect = isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__;
if ($runningDirect) {
    require_once __DIR__ . '/config.php';
    header('Content-Type: text/plain; charset=utf-8');
    $debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
    $force = isset($_GET['force']) && (string)$_GET['force'] === '1';
    try {
        if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('PDO není k dispozici.');
        $r = cfloat_fill_purchase_price_auto($pdo, true, $force);
        echo (!empty($r['ok']) ? "OK\n" : "DOKONČENO S VAROVÁNÍM\n");
        echo $r['message'] . "\n";
        if ($debug) echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'ERROR: ' . $e->getMessage() . "\n";
    }
}
