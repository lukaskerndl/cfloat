<?php
declare(strict_types=1);

// Spuštění jen s platným tokenem (dřív stačil token '123').
require_once __DIR__ . '/_cron_guard.php';


/**
 * fill_ean_now.php
 * Jednorázově doplní EAN do d388160_cfloat.order_items z AllVarianty.csv
 * - BEZ CREATE práv v DB (nevytváří žádné tabulky)
 * - klíč: CONCAT(product_id,'_',variant_id)  => např. 1770_1023805951
 * - CSV: bere "ID" (klíč) a "EAN kod" (EAN)
 *
 * Spuštění:
 *   https://cfloat.cz/fill_ean_now.php?token=TOKEN_ZE_SOUBORU_secrets/cron_run_token.php
 *
 * Umístění CSV (neměníme):
 *   /www/CStore/Varianty/AllVarianty.csv  => z pohledu skriptu: __DIR__/CStore/Varianty/AllVarianty.csv
 */

const CSV_PATH  = __DIR__ . '/CStore/Varianty/AllVarianty.csv';

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');


if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo "ERROR: V config.php není PDO \$pdo\n";
  exit;
}

function normalizeKey(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  return preg_match('/^\d+_\d+$/', $s) ? $s : null;
}

function sciToIntString(string $s): ?string {
  // "7,3E+12" / "7.3E+12" -> "7300000000000"
  $s = trim($s);
  if ($s === '') return null;
  $s = str_replace(',', '.', $s);

  if (!preg_match('/^(\d+)(?:\.(\d+))?[eE]\+?(\d+)$/', $s, $m)) return null;

  $int = $m[1];
  $frac = $m[2] ?? '';
  $exp = (int)$m[3];

  $digits = $int . $frac;
  $fracLen = strlen($frac);

  $zeros = $exp - $fracLen;
  if ($zeros < 0) return null;

  return $digits . str_repeat('0', $zeros);
}

function normalizeEan(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;

  // když Excel poslal vědecký zápis
  $maybe = sciToIntString($s);
  if ($maybe !== null) $s = $maybe;

  // jen číslice
  $digits = preg_replace('/\D+/', '', $s) ?? '';
  if ($digits === '') return null;

  // EAN typicky 8–14 číslic
  $len = strlen($digits);
  if ($len < 8 || $len > 14) return null;

  return $digits;
}

function findHeaderIndex(array $header, array $needles): ?int {
  $lower = array_map(static fn($x) => mb_strtolower(trim((string)$x)), $header);
  foreach ($needles as $needle) {
    $needle = mb_strtolower($needle);
    foreach ($lower as $i => $h) {
      if ($h === $needle) return (int)$i;
    }
  }
  return null;
}

function detectKeyAndEanFallback(array $row): array {
  $key = null;
  $ean = null;
  foreach ($row as $cell) {
    $v = trim((string)$cell);
    if ($v === '') continue;

    if ($key === null) {
      $maybeKey = normalizeKey($v);
      if ($maybeKey !== null) $key = $maybeKey;
    }
    if ($ean === null) {
      $maybeEan = normalizeEan($v);
      if ($maybeEan !== null) $ean = $maybeEan;
    }
    if ($key && $ean) break;
  }
  return [$key, $ean];
}

try {
  if (!is_file(CSV_PATH)) {
    throw new RuntimeException("CSV nenalezeno: " . CSV_PATH);
  }

  // 0) Kolik je teď bez EAN
  $beforeMissing = (int)$pdo->query("SELECT COUNT(*) FROM `order_items` WHERE (EAN IS NULL OR EAN='')")->fetchColumn();

  // 1) Načti klíče z DB, které potřebujeme doplnit (jen ty bez EAN)
  $need = [];
  $stmtNeed = $pdo->query("
    SELECT DISTINCT CONCAT(product_id,'_',variant_id) AS k
    FROM `order_items`
    WHERE (EAN IS NULL OR EAN='')
      AND product_id IS NOT NULL AND variant_id IS NOT NULL
  ");
  while ($row = $stmtNeed->fetch(PDO::FETCH_ASSOC)) {
    $k = isset($row['k']) ? (string)$row['k'] : '';
    if (preg_match('/^\d+_\d+$/', $k)) $need[$k] = true;
  }
  $needCount = count($need);

  if ($needCount === 0) {
    echo "OK: V DB nejsou žádné řádky bez EAN.\n";
    exit;
  }

  // 2) Projdi CSV a vyber jen potřebné páry key->ean
  $fh = fopen(CSV_PATH, 'rb');
  if (!$fh) throw new RuntimeException("Nelze otevřít CSV: " . CSV_PATH);

  // delimiter
  $firstLine = fgets($fh);
  if ($firstLine === false) throw new RuntimeException("CSV je prázdné.");
  $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
  rewind($fh);

  $rowsProcessed = 0;
  $mappedPairs = 0;

  $map = []; // key => ean

  // header
  $header = fgetcsv($fh, 0, $delimiter);
  if ($header === false) throw new RuntimeException("Nelze načíst první řádek CSV.");
  $rowsProcessed++;

  $idxKey = findHeaderIndex($header, ['ID', 'id']);
  $idxEan = findHeaderIndex($header, ['EAN kod', 'EAN', 'ean', 'eankod', 'ean_kod']);

  $hasHeader = ($idxKey !== null && $idxEan !== null);

  // pokud to hlavička není, zpracujeme ji jako datový řádek
  if (!$hasHeader) {
    [$key, $ean] = detectKeyAndEanFallback($header);
    if ($key && $ean && isset($need[$key])) {
      $map[$key] = $ean;
      $mappedPairs++;
      unset($need[$key]);
    }
  }

  while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
    $rowsProcessed++;
    if (!$row || count($row) < 2) continue;

    if ($hasHeader) {
      $key = normalizeKey((string)($row[$idxKey] ?? ''));
      $ean = normalizeEan((string)($row[$idxEan] ?? ''));
    } else {
      [$key, $ean] = detectKeyAndEanFallback($row);
    }

    if (!$key || !$ean) continue;
    if (!isset($need[$key])) continue;

    $map[$key] = $ean;
    $mappedPairs++;
    unset($need[$key]);

    // když už máme všechno, skončíme dřív
    if (empty($need)) break;
  }
  fclose($fh);

  // 3) UPDATE do order_items (jen bez EAN)
  $upd = $pdo->prepare("
    UPDATE `order_items`
    SET `EAN` = :ean
    WHERE `product_id` = :pid
      AND `variant_id` = :vid
      AND (`EAN` IS NULL OR `EAN` = '')
  ");

  $pdo->beginTransaction();
  $updatedRows = 0;
  $updatedExecs = 0;

  foreach ($map as $key => $ean) {
    [$pid, $vid] = explode('_', $key, 2);
    if ($pid === '' || $vid === '') continue;

    $upd->execute([
      ':ean' => $ean,
      ':pid' => (int)$pid,
      ':vid' => (int)$vid,
    ]);

    $updatedExecs++;
    $updatedRows += (int)$upd->rowCount();
  }

  $pdo->commit();

  $afterMissing = (int)$pdo->query("SELECT COUNT(*) FROM `order_items` WHERE (EAN IS NULL OR EAN='')")->fetchColumn();

  echo "OK\n";
  echo "CSV: " . CSV_PATH . "\n";
  echo "Delimiter: " . $delimiter . "\n";
  echo "Rows processed: " . $rowsProcessed . "\n";
  echo "DB klíče bez EAN: " . $needCount . "\n";
  echo "Nalezeno v CSV (key->ean): " . $mappedPairs . "\n";
  echo "UPDATE execute count: " . $updatedExecs . "\n";
  echo "UPDATE řádků změněno: " . $updatedRows . "\n";
  echo "Bez EAN před: " . $beforeMissing . "\n";
  echo "Bez EAN po: " . $afterMissing . "\n";

  if ($mappedPairs === 0) {
    echo "\nPOZN.: Nenašel jsem žádné páry key->ean. Zkontroluj, že CSV má sloupce 'ID' a 'EAN kod' a že 'ID' je ve formátu productId_variantId.\n";
  }

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
}
