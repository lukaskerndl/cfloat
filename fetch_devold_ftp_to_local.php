<?php
declare(strict_types=1);

/**
 * fetch_devold_ftp_to_local.php
 *
 * Účel:
 * - stáhnout XML feed z dodavatelského FTP a uložit ho na TVŮJ hosting do:
 *   /www/Dodavatele/DEVOLD/XML/devold.xml  (tj. ./Dodavatele/DEVOLD/XML/devold.xml relativně k rootu)
 *
 * Spuštění:
 *   https://cfloat.cz/fetch_devold_ftp_to_local.php?token=TOKEN_ZE_SOUBORU_secrets/cron_run_token.php
 * Debug:
 *   ...&debug=1
 *
 * Poznámka:
 * - Pokud se ti na Wedosu bude opakovat problém (login OK, ale LIST/RETR fail),
 *   tak je to blokace FTP datového kanálu na hostingu. V tom případě použij Make:
 *   FTP (dodavatel) -> FTP Upload (Wedos) do stejné složky.
 */

// ====== DODAVATEL FTP (DOPLŇ) ======
const SUP_FTP_HOST = 'CHANGE_ME_HOST';
const SUP_FTP_PORT = 21;
const SUP_FTP_USER = 'CHANGE_ME_USER';
const SUP_FTP_PASS = 'CHANGE_ME_PASS';

// vzdálené cesty, které budeme zkoušet (doplň podle reality)
const SUP_REMOTE_CANDIDATES = [
  'C.xml',
  'C',
  '/C.xml',
  '/C',
  'feed/C.xml',
  '/feed/C.xml',
];

// ====== KAM ULOŽIT NA WEBHOSTINGU (NECH) ======
const LOCAL_DIR  = __DIR__ . '/Dodavatele/DEVOLD/XML';
const LOCAL_FILE = 'devold.xml'; // cílový název

header('Content-Type: text/plain; charset=utf-8');

$debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';

// --- Ochrana proti spuštění kýmkoliv přes veřejnou URL (dřív triviální token '123') ---
require_once __DIR__ . '/_cron_guard.php';

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException("Nelze vytvořit složku: $dir");
    }
  }
  if (!is_writable($dir)) {
    throw new RuntimeException("Složka není zapisovatelná: $dir");
  }
}

function ftp_download(string $tmpPath, bool $debug): string {
  $conn = @ftp_connect(SUP_FTP_HOST, SUP_FTP_PORT, 25);
  if (!$conn) throw new RuntimeException("FTP connect failed: " . SUP_FTP_HOST . ":" . SUP_FTP_PORT);

  if (!@ftp_login($conn, SUP_FTP_USER, SUP_FTP_PASS)) {
    @ftp_close($conn);
    throw new RuntimeException("FTP login failed (user/pass)");
  }

  // zkusíme PASV on/off
  foreach ([true, false] as $pasv) {
    @ftp_pasv($conn, $pasv);
    if ($debug) {
      $pwd = @ftp_pwd($conn);
      echo "pasv: " . ($pasv ? "ON" : "OFF") . "\n";
      echo "ftp_pwd: " . ($pwd ?: '(unknown)') . "\n";
    }

    foreach (SUP_REMOTE_CANDIDATES as $remote) {
      if ($debug) echo "try_get: $remote\n";
      if (@ftp_get($conn, $tmpPath, $remote, FTP_BINARY)) {
        @ftp_close($conn);
        return $remote . " (pasv " . ($pasv ? "ON" : "OFF") . ")";
      }
    }

    // fallback chdir
    if ($debug) echo "try_chdir: feed-3\n";
    if (@ftp_chdir($conn, 'feed-3')) {
      foreach (['C.xml','C'] as $remote) {
        if ($debug) echo "try_get(chdir): $remote\n";
        if (@ftp_get($conn, $tmpPath, $remote, FTP_BINARY)) {
          @ftp_close($conn);
          return "feed-3/$remote via chdir (pasv " . ($pasv ? "ON" : "OFF") . ")";
        }
      }
    }
  }

  @ftp_close($conn);
  throw new RuntimeException("FTP download failed: tried candidates + pasv ON/OFF");
}

try {
  ensure_dir(LOCAL_DIR);

  $tmp = sys_get_temp_dir() . '/devold_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.xml';

  $from = ftp_download($tmp, $debug);

  $dest = rtrim(LOCAL_DIR, '/\\') . '/' . LOCAL_FILE;

  // atomický přepis (nejdřív .part)
  $part = $dest . '.part';
  if (!@copy($tmp, $part)) {
    throw new RuntimeException("Nelze zkopírovat do: $part");
  }
  @chmod($part, 0664);
  if (!@rename($part, $dest)) {
    // fallback: unlink + rename
    @unlink($dest);
    if (!@rename($part, $dest)) {
      throw new RuntimeException("Nelze přejmenovat $part -> $dest");
    }
  }

  @unlink($tmp);

  echo "OK\n";
  echo "saved_to: $dest\n";
  echo "downloaded_from: $from\n";
  echo "bytes: " . filesize($dest) . "\n";
  echo "time: " . date('c') . "\n";

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
}
