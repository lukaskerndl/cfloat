<?php
declare(strict_types=1);

// Spuštění jen s platným tokenem (dřív stačil token '123').
require_once __DIR__ . '/_cron_guard.php';


/**
 * import_devold_local.php
 *
 * Čte lokální DEVOLD XML ze složky:
 *   /www/Dodavatele/DEVOLD/XML/
 * (zkouší C.xml, C, devold.xml, DEVOLD.xml)
 *
 * Pravidlo ceny:
 * - čteme <voc> (nákupní cena bez DPH)
 * - cena s DPH = voc * 1.21
 *
 * Zapíše do: d388160_cfloat.Nakupni_ceny (UPSERT dle UNIQUE(ean))
 *
 * Spuštění:
 *   https://cfloat.cz/import_devold_local.php?token=TOKEN_ZE_SOUBORU_secrets/cron_run_token.php
 * Debug:
 *   ...&debug=1
 */


const SUPPLIER_NAME = 'DEVOLD';
const DEFAULT_CURRENCY = 'CZK';
const PRIORITY = 80;

// Připočítat 21 % DPH
const PRICE_MULTIPLIER = 1.21;

// kde hledat lokální soubor (relative k rootu webu)
const LOCAL_DIR = __DIR__ . '/Dodavatele/DEVOLD/XML';
const LOCAL_CANDIDATES = ['C.xml', 'C', 'devold.xml', 'DEVOLD.xml'];

require_once __DIR__ . '/config.php';

$debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
header('Content-Type: text/plain; charset=utf-8');


if (!isset($pdo) && isset($GLOBALS['pdo'])) { $pdo = $GLOBALS['pdo']; }
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo "ERROR: PDO není k dispozici (config.php)\n";
  exit;
}

function norm_digits(string $s): string { return preg_replace('/\D+/', '', $s) ?? ''; }

function sci_to_int_string(string $s): ?string {
  $t = trim($s);
  if ($t === '') return null;
  $t = str_replace(["\xC2\xA0", ' '], '', $t);
  $u = strtoupper(str_replace(',', '.', $t));
  if (strpos($u, 'E') === false) return null;
  if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)E([+\-]?[0-9]+)$/', $u, $m)) return null;

  $mant = $m[1];
  $exp  = (int)$m[2];
  if ($exp < 0) return null;

  $parts = explode('.', $mant, 2);
  $intPart = $parts[0];
  $fracPart = $parts[1] ?? '';
  $digits = $intPart . $fracPart;
  $decimals = strlen($fracPart);

  $zeros = $exp - $decimals;
  if ($zeros < 0) {
    $digits = substr($digits, 0, $exp + strlen($intPart));
    $zeros = 0;
  }
  return $digits . str_repeat('0', $zeros);
}

function norm_ean(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  $sci = sci_to_int_string($s);
  if ($sci !== null) $s = $sci;

  $digits = norm_digits($s);
  if ($digits === '') return null;
  $len = strlen($digits);
  if ($len < 8 || $len > 14) return null;
  return $digits;
}

function parse_price(string $s): ?float {
  $s = trim($s);
  if ($s === '') return null;
  $s = str_replace(["\xC2\xA0", ' '], '', $s);
  $s = str_replace(',', '.', $s);
  if (!is_numeric($s)) return null;
  $v = (float)$s;
  if (!is_finite($v) || $v <= 0) return null;
  return $v;
}

function find_first_text(DOMNode $root, array $names): ?string {
  $doc = $root->ownerDocument;
  if (!$doc) return null;
  $xpath = new DOMXPath($doc);
  $conds = [];
  foreach ($names as $n) {
    $n = strtolower($n);
    $conds[] = "translate(local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='$n'";
  }
  $q = ".//*[" . implode(' or ', $conds) . "]";
  $nodes = $xpath->query($q, $root);
  if (!$nodes || $nodes->length === 0) return null;
  foreach ($nodes as $node) {
    $v = trim((string)$node->textContent);
    if ($v !== '') return $v;
  }
  return null;
}

function pick_local_file(): string {
  if (!is_dir(LOCAL_DIR)) {
    throw new RuntimeException("Složka neexistuje: " . LOCAL_DIR);
  }
  foreach (LOCAL_CANDIDATES as $name) {
    $p = rtrim(LOCAL_DIR, '/\\') . '/' . $name;
    if (is_file($p) && is_readable($p)) return $p;
  }
  $files = @scandir(LOCAL_DIR) ?: [];
  $list = [];
  foreach ($files as $f) {
    if ($f === '.' || $f === '..') continue;
    $list[] = $f;
  }
  throw new RuntimeException("Nenalezen lokální XML soubor v " . LOCAL_DIR . ". Nalezené soubory: " . implode(', ', $list));
}

try {
  $xmlPath = pick_local_file();

  if ($debug) {
    echo "OK: local file\n";
    echo "path: $xmlPath\n";
    echo "bytes: " . filesize($xmlPath) . "\n";
    echo "multiplier: " . PRICE_MULTIPLIER . " (připočteno 21 % DPH)\n";
  }

  $sql = "
    INSERT INTO Nakupni_ceny (ean, supplier, price, currency, priority, source, updated_at)
    VALUES (:ean, :supplier, :price, :cur, :prio, :src, NOW())
    ON DUPLICATE KEY UPDATE
      supplier = VALUES(supplier),
      price = VALUES(price),
      currency = VALUES(currency),
      priority = VALUES(priority),
      source = VALUES(source),
      updated_at = VALUES(updated_at)
  ";
  $upsert = $pdo->prepare($sql);

  $reader = new XMLReader();
  if (!$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
    throw new RuntimeException("Nelze otevřít XMLReader: $xmlPath");
  }

  $itemCandidates = ['item','product','variant','row','record','entry','offer','position'];
  $eanTags = ['ean','ean_kod','eankod','barcode','eancode'];
  $vocTags = ['voc'];

  $processed = 0;
  $saved = 0;
  $skipped = 0;

  $doc = new DOMDocument('1.0', 'UTF-8');

  while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT) continue;
    $lname = strtolower($reader->localName);
    if (!in_array($lname, $itemCandidates, true)) continue;

    $node = $reader->expand($doc);
    if (!$node) continue;

    $processed++;

    $eanRaw = find_first_text($node, $eanTags);
    $ean = $eanRaw !== null ? norm_ean($eanRaw) : null;
    if ($ean === null) { $skipped++; continue; }

    $vocRaw = find_first_text($node, $vocTags);
    $voc = $vocRaw !== null ? parse_price($vocRaw) : null;
    if ($voc === null) { $skipped++; continue; }

    $final = $voc * PRICE_MULTIPLIER;

    $upsert->execute([
      ':ean' => $ean,
      ':supplier' => SUPPLIER_NAME,
      ':price' => $final,
      ':cur' => DEFAULT_CURRENCY,
      ':prio' => PRIORITY,
      ':src' => 'local:' . str_replace(__DIR__, '', $xmlPath),
    ]);

    $saved++;
  }

  $reader->close();

  echo "OK\n";
  echo "processed_items: $processed\n";
  echo "saved_to_db: $saved\n";
  echo "skipped_invalid: $skipped\n";

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
}
