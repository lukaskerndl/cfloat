<?php
declare(strict_types=1);

// Spuštění jen s platným tokenem (dřív stačil token '123').
require_once __DIR__ . '/_cron_guard.php';


/**
 * import_nakupni_ceny_vavrys.php (SIMPLE)
 * Zdroj: d388160_cfloat.vavrys_variants (sloupce ean, price)
 *
 * Pravidlo:
 *   final_price = price * 0.97 * 1.21
 *
 * Cíl:
 *   tabulka Nakupni_ceny (PRIMARY KEY = ean)
 *
 * Spuštění:
 *   /import_nakupni_ceny_vavrys.php?token=TOKEN_ZE_SOUBORU_secrets/cron_run_token.php
 */

const FACTOR = 0.97 * 1.21;
const DEFAULT_CURRENCY = 'CZK';
const BATCH_SIZE = 800;

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');


if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo "ERROR: V config.php není PDO $pdo\n";
  exit;
}

function norm_ean(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  $digits = preg_replace('/\D+/', '', $s) ?? '';
  if ($digits === '') return null;
  $len = strlen($digits);
  if ($len < 8 || $len > 14) return null;
  return $digits;
}

try {
  $pdo->query("SELECT 1 FROM `Nakupni_ceny` LIMIT 1");

  $total = (int)$pdo->query("SELECT COUNT(*) FROM `vavrys_variants` WHERE ean IS NOT NULL AND ean<>'' AND price IS NOT NULL")->fetchColumn();

  $offset = 0;
  $processed = 0;
  $upserted = 0;

  while (true) {
    $stmt = $pdo->prepare("
      SELECT ean, price
      FROM `vavrys_variants`
      WHERE ean IS NOT NULL AND ean<>'' AND price IS NOT NULL
      LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':lim', BATCH_SIZE, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) break;

    $values = [];
    $params = [];
    foreach ($rows as $r) {
      $ean = norm_ean((string)($r['ean'] ?? ''));
      if ($ean === null) continue;

      $p = $r['price'];
      if ($p === null || $p === '') continue;

      $price = (float)$p;
      if (!is_finite($price) || $price <= 0) continue;

      $final = $price * FACTOR;

      $values[] = "(?, ?, ?, NOW())";
      $params[] = $ean;
      $params[] = $final;
      $params[] = DEFAULT_CURRENCY;
    }

    if ($values) {
      $sql = "INSERT INTO `Nakupni_ceny` (`ean`,`price`,`currency`,`updated_at`) VALUES "
        . implode(',', $values)
        . " ON DUPLICATE KEY UPDATE `price`=VALUES(`price`), `currency`=VALUES(`currency`), `updated_at`=VALUES(`updated_at`)";
      $ins = $pdo->prepare($sql);
      $ins->execute($params);
      $upserted += (int)$ins->rowCount();
    }

    $processed += count($rows);
    $offset += BATCH_SIZE;
    if ($processed >= $total) break;
  }

  echo "OK\n";
  echo "Source: vavrys_variants\n";
  echo "Total rows (with ean+price): $total\n";
  echo "Processed: $processed\n";
  echo "Upsert rowCount (MySQL counts insert+update): $upserted\n";
  echo "Factor: ".FACTOR."\n";

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: ".$e->getMessage()."\n";
}
