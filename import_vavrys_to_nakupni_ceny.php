<?php
declare(strict_types=1);

// Spuštění jen s platným tokenem (dřív stačil token '123').
require_once __DIR__ . '/_cron_guard.php';


/**
 * import_vavrys_to_nakupni_ceny.php
 *
 * Import VAVRYS cen z d388160_cfloat.vavrys_variants do d388160_cfloat.Nakupni_ceny
 *
 * Pravidlo ceny:
 * - final = price * 0.97 * 1.21
 *
 * Bezpečné:
 * - nic nemaže
 * - UPSERT dle UNIQUE(ean) v Nakupni_ceny
 *
 * Spuštění:
 *   https://cfloat.cz/import_vavrys_to_nakupni_ceny.php?token=TOKEN_ZE_SOUBORU_secrets/cron_run_token.php
 * Debug:
 *   ...&debug=1
 */


const SUPPLIER_NAME = 'VAVRYS';
const DEFAULT_CURRENCY = 'CZK';
const PRIORITY = 100;

const MUL_1 = 0.97;
const MUL_2 = 1.21;

require_once __DIR__ . '/config.php';

$debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
header('Content-Type: text/plain; charset=utf-8');


if (!isset($pdo) && isset($GLOBALS['pdo'])) { $pdo = $GLOBALS['pdo']; }
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo "ERROR: PDO není k dispozici (config.php)\n";
  exit;
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // kolik validních EAN+price máme ve zdroji (pro kontrolu)
  $srcCnt = (int)$pdo->query("
    SELECT COUNT(DISTINCT ean)
    FROM vavrys_variants
    WHERE ean IS NOT NULL AND ean <> ''
      AND price IS NOT NULL AND price > 0
  ")->fetchColumn();

  $sql = "
    INSERT INTO Nakupni_ceny (ean, supplier, price, currency, priority, source, updated_at)
    SELECT
      CAST(vv.ean AS CHAR),
      :supplier,
      (vv.price * :m1 * :m2),
      :cur,
      :prio,
      'vavrys_variants',
      NOW()
    FROM vavrys_variants vv
    WHERE vv.ean IS NOT NULL AND vv.ean <> ''
      AND vv.price IS NOT NULL AND vv.price > 0
    ON DUPLICATE KEY UPDATE
      supplier = VALUES(supplier),
      price = VALUES(price),
      currency = VALUES(currency),
      priority = VALUES(priority),
      source = VALUES(source),
      updated_at = VALUES(updated_at)
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':supplier' => SUPPLIER_NAME,
    ':m1' => MUL_1,
    ':m2' => MUL_2,
    ':cur' => DEFAULT_CURRENCY,
    ':prio' => PRIORITY,
  ]);

  // ověření cílového stavu
  $dstCnt = (int)$pdo->query("
    SELECT COUNT(*)
    FROM Nakupni_ceny
    WHERE supplier = 'VAVRYS'
  ")->fetchColumn();

  echo "OK\n";
  echo "vavrys_distinct_valid_source: $srcCnt\n";
  echo "nakupni_ceny_rows_supplier_VAVRYS: $dstCnt\n";
  if ($debug) {
    echo "multiplier: " . MUL_1 . " * " . MUL_2 . "\n";
    echo "time: " . date('c') . "\n";
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
}
