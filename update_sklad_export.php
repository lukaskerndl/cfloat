<?php
// Spuštění pouze pro přihlášené (stejný login jako index.php)
session_start();
if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    echo 'Nepřihlášeno.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function logp(string $line): void { echo $line . "\n"; @ob_flush(); @flush(); }
function fmt_bytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB','MB','GB'];
    $v = $bytes / 1024;
    foreach ($units as $u) {
        if ($v < 1024 || $u === 'GB') return number_format($v, 2, ',', ' ') . ' ' . $u;
        $v /= 1024;
    }
    return $bytes . ' B';
}

// ---------- FTP (Eshop-rychle) ----------
$FTP_HOST = 'ftp.1388739759.s1.eshop-rychle.cz';
$FTP_USER = '38463.s1.eshop-rychle.cz';
$FTP_PASS = 'Alea11';
$FTP_DIR  = '/stock_export/manual';

// ---------- Cílová složka na webhostingu ----------
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');
$localRelDir = 'Dodavatele/SKLAD C-STORE';
$localAbsDir = $docRoot . '/' . $localRelDir;
if (!is_dir($localAbsDir)) { @mkdir($localAbsDir, 0775, true); }

$celyName = 'celySkladExport.csv';
$cilName  = 'AktualniExportSkladu.csv';

function ftp_latest_stock_info(string $host, string $user, string $pass, string $dir): array {
    if (!function_exists('ftp_connect')) {
        throw new RuntimeException('PHP nemá povolené FTP funkce (ftp_connect).');
    }
    $ftp = @ftp_connect($host, 21, 30);
    if (!$ftp) throw new RuntimeException('Nelze se připojit na FTP.');
    if (!@ftp_login($ftp, $user, $pass)) {
        @ftp_close($ftp);
        throw new RuntimeException('Nelze se přihlásit na FTP.');
    }
    @ftp_pasv($ftp, true);
    if (!@ftp_chdir($ftp, $dir)) {
        @ftp_close($ftp);
        throw new RuntimeException('Nelze přejít do složky na FTP: ' . $dir);
    }

    $files = @ftp_nlist($ftp, '.');
    if (!$files || !is_array($files)) {
        @ftp_close($ftp);
        throw new RuntimeException('Nelze vypsat soubory v adresáři na FTP.');
    }

    $all = [];
    $csv = [];
    foreach ($files as $f) {
        $f = trim((string)$f);
        if ($f === '' || $f === '.' || $f === '..') continue;
        $base = basename($f);
        $size = @ftp_size($ftp, $base);
        if ($size === -1) continue;
        $mtime = @ftp_mdtm($ftp, $base);
        if ($mtime === -1) $mtime = 0;
        $item = [
            'name' => $base,
            'size' => (int)$size,
            'mtime' => (int)$mtime,
            'mtime_text' => $mtime > 0 ? date('Y-m-d H:i:s', (int)$mtime) : 'nezjištěno',
            'dir' => $dir,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
        $all[] = $item;
        if (preg_match('~\.csv$~i', $base)) $csv[] = $item;
    }
    @ftp_close($ftp);

    $candidates = $csv ?: $all;
    if (!$candidates) throw new RuntimeException('V adresáři není žádný soubor ke stažení.');
    usort($candidates, function($a, $b) {
        return ((int)$b['mtime'] <=> (int)$a['mtime']) ?: ((int)$b['size'] <=> (int)$a['size']);
    });
    return $candidates[0];
}

function render_confirm(array $info, string $warning = ''): void {
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Potvrzení FTP skladu</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f3f7f4;margin:0;padding:24px;color:#111827}.card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #d1fae5;border-radius:18px;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,.08)}h1{margin:0 0 10px}.lead{color:#374151;line-height:1.5}.box{background:#ecfdf5;border:1px solid #86efac;border-radius:14px;padding:14px;margin:16px 0}.warn{background:#fff7ed;border:1px solid #fdba74;border-radius:14px;padding:14px;margin:16px 0;color:#9a3412}.grid{display:grid;grid-template-columns:190px 1fr;gap:8px 12px}.k{font-weight:700}.v{word-break:break-all}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.btn{border:0;border-radius:12px;padding:12px 18px;font-weight:800;text-decoration:none;display:inline-block;cursor:pointer}.go{background:#16a34a;color:#fff}.back{background:#e5e7eb;color:#111827}</style>';
    echo '</head><body><div class="card"><h1>Kontrola FTP skladu</h1>';
    echo '<p class="lead">Na FTP jsem našel skladový soubor. Zkontroluj název, datum a velikost. Teprve po potvrzení se stáhne a vytvoří se AktualniExportSkladu.csv.</p>';
    if ($warning !== '') echo '<div class="warn"><strong>Pozor:</strong> ' . h($warning) . '</div>';
    echo '<div class="box"><div class="grid">';
    echo '<div class="k">FTP složka</div><div class="v"><code>' . h((string)$info['dir']) . '</code></div>';
    echo '<div class="k">Soubor</div><div class="v"><strong>' . h((string)$info['name']) . '</strong></div>';
    echo '<div class="k">Datum na FTP</div><div class="v"><strong>' . h((string)$info['mtime_text']) . '</strong></div>';
    echo '<div class="k">Velikost</div><div class="v">' . h(fmt_bytes((int)$info['size'])) . '</div>';
    echo '<div class="k">Kontrola</div><div class="v">' . h((string)$info['checked_at']) . '</div>';
    echo '</div></div>';
    echo '<form method="post" action="update_sklad_export.php" class="actions">';
    echo '<input type="hidden" name="confirm_ftp" value="1">';
    echo '<input type="hidden" name="confirm_file" value="' . h((string)$info['name']) . '">';
    echo '<input type="hidden" name="confirm_mtime" value="' . (int)$info['mtime'] . '">';
    echo '<input type="hidden" name="confirm_size" value="' . (int)$info['size'] . '">';
    echo '<button class="btn go" type="submit" onclick="this.disabled=true;this.innerText=\'Stahuji…\';this.form.submit();">Ano, pokračovat a stáhnout sklad</button>';
    echo '<a class="btn back" href="index.php?view=xmlfeedy">Zpět bez změn</a>';
    echo '</form></div></body></html>';
}

function confirm_matches(array $latest): bool {
    return (string)($_POST['confirm_file'] ?? '') === (string)$latest['name']
        && (int)($_POST['confirm_mtime'] ?? -1) === (int)$latest['mtime']
        && (int)($_POST['confirm_size'] ?? -1) === (int)$latest['size'];
}

try {
    $latest = ftp_latest_stock_info($FTP_HOST, $FTP_USER, $FTP_PASS, $FTP_DIR);
    if (empty($_POST['confirm_ftp'])) {
        render_confirm($latest);
        exit;
    }
    if (!confirm_matches($latest)) {
        render_confirm($latest, 'Soubor na FTP se mezitím změnil. Zkontroluj nové údaje a potvrď pokračování znovu.');
        exit;
    }
} catch (Throwable $e) {
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:Arial,sans-serif;padding:20px;color:#991b1b"><strong>Chyba FTP:</strong> ' . h($e->getMessage()) . '</div>';
    exit;
}

echo '<!doctype html><meta charset="utf-8"><title>Aktualizace skladu (FTP)</title>';
echo '<pre style="white-space:pre-wrap;font:12px/1.4 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; padding:12px;">';

logp('START');
logp('FTP: ' . $FTP_HOST);
logp('REMOTE DIR: ' . $FTP_DIR);
logp('LOCAL DIR: ' . $localAbsDir);
logp('POTVRZENÝ SOUBOR: ' . $latest['name']);
logp('DATUM NA FTP: ' . $latest['mtime_text']);
logp('VELIKOST: ' . fmt_bytes((int)$latest['size']));
logp('');

$ftp = @ftp_connect($FTP_HOST, 21, 30);
if (!$ftp) { logp('CHYBA: Nelze se připojit na FTP.'); logp('KONEC'); echo '</pre>'; exit; }
if (!@ftp_login($ftp, $FTP_USER, $FTP_PASS)) { logp('CHYBA: Nelze se přihlásit na FTP.'); @ftp_close($ftp); logp('KONEC'); echo '</pre>'; exit; }
@ftp_pasv($ftp, true);
if (!@ftp_chdir($ftp, $FTP_DIR)) { logp('CHYBA: Nelze přejít do složky na FTP: ' . $FTP_DIR); @ftp_close($ftp); logp('KONEC'); echo '</pre>'; exit; }

$bestFile = $latest['name'];
$tmpAbs = $localAbsDir . '/__tmp__' . $bestFile;
$origAbs = $localAbsDir . '/' . $bestFile;
$celyAbs = $localAbsDir . '/' . $celyName;
$cilAbs  = $localAbsDir . '/' . $cilName;

if (!@ftp_get($ftp, $tmpAbs, $bestFile, FTP_BINARY)) {
    logp('CHYBA: Stažení souboru z FTP selhalo.');
    @ftp_close($ftp);
    logp('KONEC');
    echo '</pre>';
    exit;
}
@ftp_close($ftp);

@rename($tmpAbs, $origAbs);
logp('ULOŽENO: ' . $origAbs);

if (!@copy($origAbs, $celyAbs)) {
    logp('CHYBA: Nelze vytvořit kopii ' . $celyName);
    logp('KONEC');
    echo '</pre>';
    exit;
}
logp('KOPIE: ' . $celyAbs);

$fp = @fopen($celyAbs, 'rb');
if (!$fp) { logp('CHYBA: Nelze otevřít ' . $celyName); logp('KONEC'); echo '</pre>'; exit; }

$firstLine = fgets($fp);
if ($firstLine === false) { fclose($fp); logp('CHYBA: Soubor je prázdný: ' . $celyName); logp('KONEC'); echo '</pre>'; exit; }

$semi = substr_count($firstLine, ';');
$comma = substr_count($firstLine, ',');
$tab = substr_count($firstLine, "\t");
$delim = ';';
if ($comma > $semi && $comma >= $tab) $delim = ',';
elseif ($tab > $semi && $tab > $comma) $delim = "\t";

rewind($fp);
$out = @fopen($cilAbs, 'wb');
if (!$out) { fclose($fp); logp('CHYBA: Nelze vytvořit ' . $cilName); logp('KONEC'); echo '</pre>'; exit; }

fwrite($out, "EAN;Dostupnost\n");

$rowsTotal = 0;
$rowsWritten = 0;
$skipped = 0;

while (($row = fgetcsv($fp, 0, $delim)) !== false) {
    $rowsTotal++;
    if (!is_array($row) || count($row) < 7) { $skipped++; continue; }

    $fVal = trim((string)$row[5]);
    $gValRaw = trim((string)$row[6]);

    if ($rowsTotal === 1) {
        $probe = strtolower($fVal . ' ' . $gValRaw);
        if (str_contains($probe, 'ean') || str_contains($probe, 'sklad') || str_contains($probe, 'stock')) { $skipped++; continue; }
    }

    if ($fVal === '') { $skipped++; continue; }
    if (!preg_match('~\-?\d+~', $gValRaw, $m)) { $skipped++; continue; }
    $gInt = (int)$m[0];

    if (($gInt >= 1 && $gInt <= 30) || ($gInt >= 1001 && $gInt <= 1030)) {
        fwrite($out, $fVal . ';' . $gInt . "\n");
        $rowsWritten++;
    } else {
        $skipped++;
    }
}

fclose($fp);
fclose($out);

$info = [
    'source' => 'FTP manual',
    'ftp_file' => $bestFile,
    'ftp_dir' => $FTP_DIR,
    'ftp_size' => (int)$latest['size'],
    'ftp_mtime' => (int)$latest['mtime'],
    'ftp_mtime_text' => (string)$latest['mtime_text'],
    'downloaded_at' => date('Y-m-d H:i:s'),
    'rows_total' => $rowsTotal,
    'rows_accepted' => $rowsWritten,
    'rows_skipped' => $skipped,
];
@file_put_contents($localAbsDir . '/posledni_ftp_sklad_info.json', json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

logp('');
logp('HOTOVO');
logp('ŘÁDKŮ CELKEM (včetně hlavičky): ' . $rowsTotal);
logp('ZAPSÁNO DO ' . $cilName . ': ' . $rowsWritten);
logp('PŘESKOČENO: ' . $skipped);
logp('');

$relCely = $localRelDir . '/' . $celyName;
$relCil  = $localRelDir . '/' . $cilName;
$relOrig = $localRelDir . '/' . $bestFile;

logp('Odkazy:');
logp('- ' . $relOrig);
logp('- ' . $relCely);
logp('- ' . $relCil);
logp('STATUS: DOKONČENO');
logp('KONEC');

echo "\n\n";
echo 'TIP: Pokud chceš stáhnout soubory, otevři je přes web (relativní cesta výše).';
echo '</pre>';
