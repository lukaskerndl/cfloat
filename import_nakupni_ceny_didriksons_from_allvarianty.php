<?php
declare(strict_types=1);

// Spuštění jen s platným tokenem (dřív stačil token '123').
require_once __DIR__ . '/_cron_guard.php';


/**
 * import_nakupni_ceny_didriksons_from_allvarianty.php (v3)
 *
 * FIX: EAN ve sloupci Q bývá v CSV často ve vědeckém zápisu (např. 7,318E+12).
 * - Umíme převést scientific notation na celé číslo bez ztráty.
 *
 * Podmínka:
 *  - sloupec C obsahuje (case-insensitive): D1913 / Didrkons (a Didriksons)
 *
 * EAN: sloupec Q (index 16)
 * Cena: E (4) jinak F (5), /2
 *
 * Spuštění:
 *  /import_nakupni_ceny_didriksons_from_allvarianty.php?token=TOKEN_ZE_SOUBORU_secrets/cron_run_token.php
 */

const DEFAULT_CURRENCY = 'CZK';
const CSV_PATH = __DIR__ . '/CStore/Varianty/AllVarianty.csv';

const NAME_NEEDLES = ['d1913', 'didrkons', 'didriksons'];

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');


if (!isset($pdo) && isset($GLOBALS['pdo'])) { $pdo = $GLOBALS['pdo']; }
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo "ERROR: PDO není k dispozici (config.php)\n";
  exit;
}

function detect_delim(string $line): string {
  $commas = substr_count($line, ',');
  $semis  = substr_count($line, ';');
  return ($semis > $commas) ? ';' : ',';
}

/**
 * Převod scientific notation na integer string (bez floatu => bez ztráty).
 * Příklady:
 *  - "7,318E+12" => "7318000000000"
 *  - "7.028567648636E+12" => "7028567648636"
 */
function sci_to_int_string(string $s): ?string {
  $t = trim($s);
  if ($t === '') return null;
  $t = str_replace(["\xC2\xA0", ' '], '', $t);
  $u = strtoupper($t);
  $u = str_replace(',', '.', $u);

  if (strpos($u, 'E') === false) return null;

  if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)E([+\-]?[0-9]+)$/', $u, $m)) return null;

  $mant = $m[1];              // "7.318567..."
  $exp  = (int)$m[2];         // 12

  if ($exp < 0) return null;  // pro EAN nečekáme

  $parts = explode('.', $mant, 2);
  $intPart = $parts[0];
  $fracPart = $parts[1] ?? '';
  $digits = $intPart . $fracPart;     // bez tečky
  $decimals = strlen($fracPart);

  // posun tečky doprava o exp míst
  $zeros = $exp - $decimals;
  if ($zeros < 0) {
    // tečka by byla uvnitř -> pro EAN nečekáme; ale uděláme ořez na celé
    $digits = substr($digits, 0, $exp + strlen($intPart));
    $zeros = 0;
  }
  return $digits . str_repeat('0', $zeros);
}

function norm_ean(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;

  // 1) scientific notation?
  $sci = sci_to_int_string($s);
  if ($sci !== null) $s = $sci;

  // 2) jen číslice
  $digits = preg_replace('/\D+/', '', $s) ?? '';
  if ($digits === '') return null;

  // ořez případných trailing .0 apod.
  $digits = ltrim($digits, '0'); // EAN někdy nemá začínat nulou; ale pokud by měl, necháme fallback:
  if ($digits === '') $digits = '0';

  $len = strlen($digits);
  if ($len < 8 || $len > 14) return null;

  return $digits;
}

function parse_price_allow_zero(string $s): ?float {
  $s = trim($s);
  if ($s === '') return null;
  $s = str_replace(["\xC2\xA0", ' '], '', $s);
  $s = str_replace(',', '.', $s);
  if (!is_numeric($s)) return null;
  $v = (float)$s;
  if (!is_finite($v)) return null;
  return $v;
}

try {
  if (!is_file(CSV_PATH)) throw new RuntimeException("CSV nenalezeno: " . CSV_PATH);
  $fh = fopen(CSV_PATH, 'rb');
  if (!$fh) throw new RuntimeException("Nelze otevřít CSV: " . CSV_PATH);

  $first = fgets($fh);
  if ($first === false) { fclose($fh); throw new RuntimeException("CSV je prázdné: " . CSV_PATH); }

  $delim = detect_delim($first);
  $row0 = str_getcsv(rtrim($first, "\r\n"), $delim);

  // header?
  $isHeader = false;
  if (isset($row0[0])) {
    $h0 = mb_strtolower(trim((string)$row0[0]), 'UTF-8');
    if ($h0 === 'id' || $h0 === 'kod vyrobku' || $h0 === 'kód vyrobku') $isHeader = true;
  }

  $upsert = $pdo->prepare("
    INSERT INTO Nakupni_ceny (ean, price, currency, updated_at)
    VALUES (:ean, :price, :cur, NOW())
    ON DUPLICATE KEY UPDATE
      price = VALUES(price),
      currency = VALUES(currency),
      updated_at = VALUES(updated_at)
  ");

  $processed = 0;
  $matched = 0;
  $saved = 0;

  $invalidEan = 0;
  $invalidPrice = 0;
  $tooShortRow = 0;

  $processRow = function(array $row) use (&$processed,&$matched,&$saved,&$invalidEan,&$invalidPrice,$upsert) {
    $processed++;

    // potřebujeme až Q=16
    $nameRaw = (string)($row[2] ?? '');
    $name = mb_strtolower($nameRaw, 'UTF-8');

    $ok = false;
    foreach (NAME_NEEDLES as $needle) {
      if ($needle !== '' && mb_strpos($name, $needle, 0, 'UTF-8') !== false) { $ok = true; break; }
    }
    if (!$ok) return;

    $matched++;

    $eanRaw = (string)($row[16] ?? '');
    $ean = norm_ean($eanRaw);
    if ($ean === null) { $invalidEan++; return; }

    $pEraw = (string)($row[4] ?? '');
    $pFraw = (string)($row[5] ?? '');

    $pE = parse_price_allow_zero($pEraw);
    $use = null;
    if ($pE !== null && $pE > 0) $use = $pE;
    else {
      $pF = parse_price_allow_zero($pFraw);
      if ($pF !== null && $pF > 0) $use = $pF;
    }
    if ($use === null) { $invalidPrice++; return; }

    $final = $use / 2.0;

    $upsert->execute([
      ':ean' => $ean,
      ':price' => $final,
      ':cur' => DEFAULT_CURRENCY,
    ]);

    $saved++;
  };

  if (!$isHeader) {
    if (count($row0) >= 17) $processRow($row0);
  }

  while (($row = fgetcsv($fh, 0, $delim)) !== false) {
    if (!$row) continue;
    if (count($row) < 17) { $tooShortRow++; continue; }
    $processRow($row);
  }

  fclose($fh);

  echo "OK\n";
  echo "csv: " . CSV_PATH . "\n";
  echo "delimiter: " . $delim . "\n";
  echo "processed_rows: $processed\n";
  echo "matched_name_rows: $matched\n";
  echo "saved_to_db: $saved\n";
  echo "invalid_ean: $invalidEan\n";
  echo "invalid_price: $invalidPrice\n";
  echo "too_short_rows(<17): $tooShortRow\n";

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
}
