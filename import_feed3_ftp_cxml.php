<?php
declare(strict_types=1);

// Spuštění jen s platným tokenem (dřív stačil token '123').
require_once __DIR__ . '/_cron_guard.php';


/**
 * import_feed3_ftp_cxml.php (VOC * 1.21) - FTP robust v3
 *
 * Problém: PHP FTP může mít jiný "root" než Total Commander. Proto:
 * - zkusí více cest (/feed-3/C.xml, feed-3/C.xml, C.xml, ...)
 * - zkusí chdir('feed-3') a stáhnout C.xml
 * - zkusí PASV ON i PASV OFF
 * - debug vypíše ftp_pwd + rawlist (když nlist selže)
 */


// FTP
const FTP_HOST = '185.61.87.47';
const FTP_USER = 'feed-3';
const FTP_PASS = '8vnhMDv4ej';

// Import meta
const SUPPLIER_NAME = 'feed-3';
const DEFAULT_CURRENCY = 'CZK';
const PRIORITY = 75;

// Přepočet ceny
const VOC_MULTIPLIER = 1.21;

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

function ftp_try_download($conn, string $localPath, string $remote, bool $debug): bool {
  if ($debug) echo "try_get: $remote\n";
  return @ftp_get($conn, $localPath, $remote, FTP_BINARY);
}

function ftp_dump_dir($conn, bool $debug): void {
  if (!$debug) return;

  $pwd = @ftp_pwd($conn);
  echo "ftp_pwd: " . ($pwd ?: '(unknown)') . "\n";

  echo "nlist:\n";
  $list = @ftp_nlist($conn, ".");
  if (is_array($list)) {
    foreach ($list as $x) echo " - $x\n";
  } else {
    echo " - (nlist failed)\n";
  }

  echo "rawlist:\n";
  $raw = @ftp_rawlist($conn, ".");
  if (is_array($raw)) {
    foreach ($raw as $x) echo " - $x\n";
  } else {
    echo " - (rawlist failed)\n";
  }
}

function ftp_download_to(string $localPath, bool $debug): string {
  $conn = @ftp_connect(FTP_HOST, 21, 25);
  if (!$conn) throw new RuntimeException("FTP connect failed: " . FTP_HOST);

  if (!@ftp_login($conn, FTP_USER, FTP_PASS)) {
    @ftp_close($conn);
    throw new RuntimeException("FTP login failed (user/pass)");
  }

  $candidates = [
    '/feed-3/C.xml',
    'feed-3/C.xml',
    '/feed-3/C',
    'feed-3/C',
    '/C.xml',
    'C.xml',
    '/C',
    'C',
  ];

  foreach ([true, false] as $pasv) {
    @ftp_pasv($conn, $pasv);
    if ($debug) echo "pasv: " . ($pasv ? "ON" : "OFF") . "\n";

    ftp_dump_dir($conn, $debug);

    // 1) přímé cesty
    foreach ($candidates as $remote) {
      if (ftp_try_download($conn, $localPath, $remote, $debug)) {
        @ftp_close($conn);
        return $remote . " (pasv " . ($pasv ? "ON" : "OFF") . ")";
      }
    }

    // 2) chdir do feed-3 a stáhnout
    if ($debug) echo "try_chdir: feed-3\n";
    if (@ftp_chdir($conn, 'feed-3')) {
      ftp_dump_dir($conn, $debug);
      foreach (['C.xml','C'] as $remote) {
        if (ftp_try_download($conn, $localPath, $remote, $debug)) {
          @ftp_close($conn);
          return "feed-3/$remote via chdir (pasv " . ($pasv ? "ON" : "OFF") . ")";
        }
      }
      // vrátit se zpět
      @ftp_chdir($conn, '/');
    }
  }

  @ftp_close($conn);
  throw new RuntimeException("FTP download failed: tried all candidate paths + chdir + pasv ON/OFF");
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

try {
  $tmp = sys_get_temp_dir() . '/feed3_C_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xml';
  $usedRemote = ftp_download_to($tmp, $debug);

  if ($debug) {
    echo "download_ok_from: $usedRemote\n";
    echo "tmp: $tmp\n";
    echo "size: " . filesize($tmp) . " bytes\n";
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
  if (!$reader->open($tmp, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
    throw new RuntimeException("Nelze otevřít XMLReader: $tmp");
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

    $final = $voc * VOC_MULTIPLIER;

    $upsert->execute([
      ':ean' => $ean,
      ':supplier' => SUPPLIER_NAME,
      ':price' => $final,
      ':cur' => DEFAULT_CURRENCY,
      ':prio' => PRIORITY,
      ':src' => 'ftp://' . FTP_HOST . '/C.xml',
    ]);

    $saved++;
  }

  $reader->close();
  @unlink($tmp);

  echo "OK\n";
  echo "processed_items: $processed\n";
  echo "saved_to_db: $saved\n";
  echo "skipped_invalid: $skipped\n";

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
}
