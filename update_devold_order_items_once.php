<?php
declare(strict_types=1);

// Spuštění jen s platným tokenem (dřív stačil token '123').
require_once __DIR__ . '/_cron_guard.php';


/**
 * update_devold_order_items_once.php
 *
 * Jednorázově přepíše (aktualizuje) nakupni_cena ve starých objednávkách (order_items)
 * pro položky DEVOLD podle tabulky Nakupni_ceny (supplier='DEVOLD').
 *
 * - NEMAŽE EAN
 * - Přepisuje jen nakupni_cena pro řádky, které mají EAN a existují v Nakupni_ceny jako DEVOLD.
 * - Jednorázová pojistka: vytvoří lock soubor. Opakování jen s ?force=1
 *
 * Spuštění:
 *   https://cfloat.cz/update_devold_order_items_once.php?token=TOKEN_ZE_SOUBORU_secrets/cron_run_token.php
 * Debug:
 *   ...&debug=1
 * Force rerun:
 *   ...&force=1
 */

const LOCK_FILE = __DIR__ . '/.lock_update_devold_order_items_once';

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

$debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';


if (!$force && is_file(LOCK_FILE)) {
  echo "STOP: už jednou proběhlo (lock existuje).\n";
  echo "Pokud to chceš spustit znovu: přidej &force=1 nebo smaž soubor: " . LOCK_FILE . "\n";
  exit;
}

if (!isset($pdo) && isset($GLOBALS['pdo'])) { $pdo = $GLOBALS['pdo']; }
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo "ERROR: PDO není k dispozici (config.php)\n";
  exit;
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Kolik řádků by se mělo dotknout (kolik order_items má EAN, které je DEVOLD v Nakupni_ceny)
  $cntSql = "
    SELECT COUNT(*) 
    FROM order_items oi
    JOIN Nakupni_ceny nc ON nc.ean = oi.EAN
    WHERE oi.EAN IS NOT NULL AND oi.EAN <> ''
      AND nc.supplier = 'DEVOLD'
  ";
  $targetCount = (int)$pdo->query($cntSql)->fetchColumn();

  if ($debug) {
    echo "target_rows_to_update: $targetCount\n";
  }

  // UPDATE (přepis)
  // Pozn.: neřešíme qty, v order_items je nakupni_cena za kus (dle tvého zadání).
  $updSql = "
    UPDATE order_items oi
    JOIN Nakupni_ceny nc ON nc.ean = oi.EAN
    SET oi.nakupni_cena = nc.price
    WHERE oi.EAN IS NOT NULL AND oi.EAN <> ''
      AND nc.supplier = 'DEVOLD'
  ";
  $affected = $pdo->exec($updSql);

  // Lock – vytvoříme po úspěchu
  @file_put_contents(LOCK_FILE, "OK " . date('c') . "\nrows:$affected\n", LOCK_EX);

  echo "OK\n";
  echo "updated_rows: " . (int)$affected . "\n";
  echo "target_rows: $targetCount\n";
  echo "lock: " . LOCK_FILE . "\n";

  if ($debug) {
    // kontrola: kolik řádků má nyní nakupni_cena NULL/0 mezi DEVOLD
    $checkSql = "
      SELECT 
        SUM(oi.nakupni_cena IS NULL) AS null_cnt,
        SUM(oi.nakupni_cena = 0) AS zero_cnt
      FROM order_items oi
      JOIN Nakupni_ceny nc ON nc.ean = oi.EAN
      WHERE oi.EAN IS NOT NULL AND oi.EAN <> ''
        AND nc.supplier = 'DEVOLD'
    ";
    $row = $pdo->query($checkSql)->fetch(PDO::FETCH_ASSOC) ?: [];
    echo "after_check_null: " . (int)($row['null_cnt'] ?? 0) . "\n";
    echo "after_check_zero: " . (int)($row['zero_cnt'] ?? 0) . "\n";
    echo "time: " . date('c') . "\n";
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
}
