<?php
declare(strict_types=1);

// Spuštění jen s platným tokenem (dřív stačil token '123').
require_once __DIR__ . '/_cron_guard.php';


/**
 * import_nakupni_ceny_silvini.php (SIMPLE)
 *
 * XML:
 *   http://95.80.221.202:3380/REPORTS_SALESPRICE_CZK.XML
 *
 * Cena:
 *  - IsDiscountedB2B=0 => CZ_BUY_BASIC * 0.78 * 1.21
 *  - IsDiscountedB2B=1 => CZ_BUY_PROMO  * 1.21
 *
 * Cíl:
 *   tabulka Nakupni_ceny (PRIMARY KEY = ean)
 *
 * Spuštění:
 *   /import_nakupni_ceny_silvini.php?token=TOKEN_ZE_SOUBORU_secrets/cron_run_token.php
 */

const FEED_URL = 'http://95.80.221.202:3380/REPORTS_SALESPRICE_CZK.XML';

const FACTOR_BASIC = 0.78 * 1.21;
const FACTOR_PROMO = 1.21;

const DEFAULT_CURRENCY = 'CZK';

require_once __DIR__ . '/config.php';

$debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';

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

function parse_price(string $s): ?float {
  $s = trim($s);
  if ($s === '') return null;
  $s = str_replace([' ', "\xC2\xA0"], '', $s);
  $s = str_replace(',', '.', $s);
  if (!is_numeric($s)) return null;
  $v = (float)$s;
  if (!is_finite($v) || $v <= 0) return null;
  return $v;
}

function curl_download_to_file(string $url, string $dstFile): void {
  $ch = curl_init($url);
  if ($ch === false) throw new RuntimeException("Nelze init cURL.");

  $fh = fopen($dstFile, 'wb');
  if (!$fh) throw new RuntimeException("Nelze vytvořit temp soubor.");

  curl_setopt_array($ch, [
    CURLOPT_FILE => $fh,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_USERAGENT => 'cfloat-importer/1.0',
    CURLOPT_FAILONERROR => false,
  ]);

  $ok = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

  curl_close($ch);
  fclose($fh);

  if ($ok === false) {
    @unlink($dstFile);
    throw new RuntimeException("cURL chyba: ".$err);
  }
  if ($code >= 400 || $code == 0) {
    @unlink($dstFile);
    throw new RuntimeException("HTTP chyba: ".$code);
  }
}

try {
  $pdo->query("SELECT 1 FROM `Nakupni_ceny` LIMIT 1");

  $tmp = sys_get_temp_dir() . '/silvini_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xml';
  curl_download_to_file(FEED_URL, $tmp);

  $reader = new XMLReader();
  if (!$reader->open($tmp, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS)) {
    @unlink($tmp);
    throw new RuntimeException("Nelze otevřít XMLReader.");
  }

  $upsert = $pdo->prepare("
    INSERT INTO `Nakupni_ceny` (`ean`,`price`,`currency`,`updated_at`)
    VALUES (:ean, :price, :cur, NOW())
    ON DUPLICATE KEY UPDATE
      `price`=VALUES(`price`),
      `currency`=VALUES(`currency`),
      `updated_at`=VALUES(`updated_at`)
  ");

  $processedElements = 0;
  $saved = 0;
  $skipped = 0;

  $curEan = null;
  $curDisc = null;
  $curBasic = null;
  $curPromo = null;

  $flush = function() use (&$curEan, &$curDisc, &$curBasic, &$curPromo, &$upsert, &$saved, &$skipped) {
    if ($curEan === null) return;

    $disc = ($curDisc === '1') ? 1 : 0;
    $base = null;
    $factor = null;

    if ($disc === 1) {
      if ($curPromo !== null) { $base = $curPromo; $factor = FACTOR_PROMO; }
    } else {
      if ($curBasic !== null) { $base = $curBasic; $factor = FACTOR_BASIC; }
    }

    if ($base === null || $factor === null) {
      $skipped++;
      $curEan = $curDisc = null; $curBasic = $curPromo = null;
      return;
    }

    $final = $base * $factor;

    $upsert->execute([
      ':ean' => $curEan,
      ':price' => $final,
      ':cur' => DEFAULT_CURRENCY,
    ]);
    $saved++;

    $curEan = $curDisc = null; $curBasic = $curPromo = null;
  };

  while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT) continue;
    $processedElements++;

    $name = $reader->name;

    if ($name === 'ean') {
      $flush();
      $curEan = norm_ean(trim($reader->readString()));
      continue;
    }
    if ($name === 'IsDiscountedB2B') {
      $curDisc = (trim($reader->readString()) === '1') ? '1' : '0';
      continue;
    }
    if ($name === 'CZ_BUY_BASIC') {
      $p = parse_price(trim($reader->readString()));
      if ($p !== null) $curBasic = $p;
      continue;
    }
    if ($name === 'CZ_BUY_PROMO') {
      $p = parse_price(trim($reader->readString()));
      if ($p !== null) $curPromo = $p;
      continue;
    }

    if ($curEan !== null && $curDisc !== null) {
      if ($curDisc === '1' && $curPromo !== null) $flush();
      if ($curDisc === '0' && $curBasic !== null) $flush();
    }
  }

  $flush();

  $reader->close();
  @unlink($tmp);

  echo "OK\n";
  echo "Feed: ".FEED_URL."\n";
  echo "Processed XML element nodes: ".$processedElements."\n";
  echo "Saved (upserted) rows: ".$saved."\n";
  echo "Skipped rows: ".$skipped."\n";
  echo "Rule: discounted=0 => CZ_BUY_BASIC*".FACTOR_BASIC.", discounted=1 => CZ_BUY_PROMO*".FACTOR_PROMO."\n";
  if ($debug) echo "DEBUG: ok\n";

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: ".$e->getMessage()."\n";
}
