<?php
session_start();

// Automatický postup bez ručního uploadu:
// 1) stáhne dodavatelské XML feedy,
// 2) stáhne nejnovější sklad C-Store z FTP /stock_export/manual,
// 3) vytvoří stejné skladové výstupy jako ruční upload,
// 4) zpracuje všechny dodavatele,
// 5) vygeneruje soubory pouze se změnami.

const CFTP_VERSION = '2026-06-19-FTP-STOCK-AUTO-FULL-V14-CONFIRM';
const CFTP_EAN_COL_INDEX = 5;   // F = EAN kód
const CFTP_SKLAD_COL_INDEX = 6; // G = Skladem

if (empty($_SESSION['logged_in'])) {
    http_response_code(403);
    echo 'Nepřihlášeno.';
    exit;
}

function cftp_redirect(array $params): void {
    $base = 'index.php?view=xmlfeedy';
    if ($params) $base .= '&' . http_build_query($params);
    $base .= '#rychly-postup-dodavatele';
    header('Location: ' . $base);
    exit;
}

function cftp_safe_filename(string $name): string {
    $name = basename($name);
    $name = preg_replace('~[^A-Za-z0-9._-]+~u', '_', $name);
    $name = trim((string)$name, '._-');
    return $name !== '' ? $name : 'sklad_cstore.csv';
}

function cftp_ensure_dir(string $dir): bool {
    return is_dir($dir) || @mkdir($dir, 0775, true);
}

function cftp_detect_delim_from_file(string $file): string {
    $sample = @file_get_contents($file, false, null, 0, 8192);
    if ($sample === false) return ';';
    $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);
    $cands = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t"), '|' => substr_count($sample, '|')];
    arsort($cands);
    $delim = array_key_first($cands);
    return $delim ?: ';';
}

function cftp_norm_lower(string $s): string {
    if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
    return strtolower($s);
}

function cftp_parse_g_value(string $raw): ?int {
    $raw = trim($raw);
    if ($raw === '') return null;
    $raw = str_replace(["\xC2\xA0", ' '], '', $raw);
    $raw = str_replace(',', '.', $raw);

    if (is_numeric($raw)) {
        $float = (float)$raw;
        $int = (int)$float;
        if (abs($float - $int) < 0.000001) return $int;
        return null;
    }

    if (preg_match('~-?\d+~', $raw, $m)) return (int)$m[0];
    return null;
}

function cftp_g_allowed(int $g): bool {
    // Stejné pravidlo jako v ručním uploadu, který teď funguje.
    return ($g >= 1 && $g <= 25) || ($g >= 1001 && $g <= 1025);
}

function cftp_clean_ean(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    return preg_replace('~\D+~', '', $raw) ?: '';
}

function cftp_valid_ean_like(string $ean): bool {
    return (bool)preg_match('~^\d{8,14}$~', $ean);
}

function cftp_open_csv_writer(string $file, array $header): SplFileObject {
    $f = new SplFileObject($file, 'w');
    $f->fwrite("\xEF\xBB\xBF");
    $f->fputcsv($header, ';');
    return $f;
}

function cftp_unlink_if_file(string $file): void {
    if (is_file($file)) @unlink($file);
}

function cftp_copy_if_file(string $src, string $dst): void {
    if (is_file($src)) {
        @copy($src, $dst);
        @touch($dst, (int)(@filemtime($src) ?: time()));
    }
}

function cftp_find_existing_file(string $dirAbs, array $names): ?string {
    foreach ($names as $name) {
        $p = rtrim($dirAbs, '/\\') . '/' . $name;
        if (is_file($p)) return $p;
    }
    if (!is_dir($dirAbs)) return null;
    $list = @scandir($dirAbs) ?: [];
    foreach ($names as $name) {
        foreach ($list as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (strcasecmp($entry, $name) === 0 && is_file($dirAbs . '/' . $entry)) {
                return rtrim($dirAbs, '/\\') . '/' . $entry;
            }
        }
    }
    return null;
}

function cftp_source_is_cstore(string $src): bool {
    $s = cftp_norm_lower(trim($src));
    if ($s === '') return false;
    return (strpos($s, 'cstore') !== false)
        || (strpos($s, 'c-store') !== false)
        || (strpos($s, 'c store') !== false)
        || (strpos($s, 'sklad c-store') !== false)
        || (strpos($s, 'sklad_cstore') !== false)
        || (strpos($s, 'cstore_sklad') !== false)
        || (strpos($s, 'c-store sklad') !== false)
        || (strpos($s, 'sklad') !== false && (strpos($s, 'store') !== false || strpos($s, 'c-') !== false));
}

function cftp_source_is_sportimport(string $src): bool {
    $s = cftp_norm_lower(trim($src));
    $s = str_replace([' ', '-', '_'], '', $s);
    return $s === 'sportimport';
}

function cftp_append_update_csv_rows(SplFileObject $fout, string $sourceName, string $updateCsv, string $feedDate, int &$written): void {
    if (!is_file($updateCsv)) return;
    $delim = cftp_detect_delim_from_file($updateCsv);
    $fh = @fopen($updateCsv, 'rb');
    if (!$fh) return;

    $lineNo = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $lineNo++;
        if (!is_array($row) || count($row) < 1) continue;
        $ean = cftp_clean_ean((string)($row[0] ?? ''));
        $val = trim((string)($row[1] ?? '0'));

        if ($lineNo === 1) {
            $probe = cftp_norm_lower(trim(implode(' ', $row)));
            if (strpos($probe, 'ean') !== false || strpos($probe, 'dostup') !== false) continue;
        }
        if ($ean === '' || strtolower($ean) === 'ean') continue;
        if ($val === '') $val = '0';
        $fout->fputcsv([$sourceName, $ean, $val, $feedDate], ';');
        $written++;
    }
    fclose($fh);
}

function cftp_http_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') return '';

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = trim(dirname($scriptName), '/.');
    return $scheme . '://' . $host . ($dir !== '' ? '/' . $dir : '') . '/';
}

function cftp_loopback_request(string $script, string $method = 'GET', array $query = [], array $post = [], int $timeout = 1800): array {
    $base = cftp_http_base_url();
    if ($base === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'Nelze zjistit URL webu.', 'body' => ''];
    }

    $url = $base . ltrim($script, '/');
    if ($query) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $cookie = '';
    if (session_id() !== '') {
        $cookie = session_name() . '=' . session_id();
    }

    $body = '';
    $code = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($cookie !== '') curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post ?: ['auto' => '1']);
        }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) $error = curl_error($ch);
        curl_close($ch);
    } else {
        $headers = [];
        if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
        $contextOptions = [
            'http' => [
                'method' => strtoupper($method),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ];
        if (strtoupper($method) === 'POST') {
            $contextOptions['http']['header'] .= ($contextOptions['http']['header'] !== '' ? "\r\n" : '') . 'Content-Type: application/x-www-form-urlencoded';
            $contextOptions['http']['content'] = http_build_query($post ?: ['auto' => '1']);
        }
        $ctx = stream_context_create($contextOptions);
        $bodyRaw = @file_get_contents($url, false, $ctx);
        $body = $bodyRaw === false ? '' : (string)$bodyRaw;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('~^HTTP/\S+\s+(\d+)~', $hdr, $m)) { $code = (int)$m[1]; break; }
            }
        }
        if ($bodyRaw === false) $error = 'HTTP požadavek se nepodařil.';
    }

    $ok = ($error === '' && $code >= 200 && $code < 400);
    return [
        'ok' => $ok,
        'code' => $code,
        'error' => $error,
        'body' => substr($body, 0, 1200),
        'url' => $url,
    ];
}

function cftp_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cftp_format_bytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB','MB','GB'];
    $v = (float)$bytes / 1024;
    foreach ($units as $u) {
        if ($v < 1024 || $u === 'GB') return number_format($v, 2, ',', ' ') . ' ' . $u;
        $v /= 1024;
    }
    return (string)$bytes . ' B';
}

function cftp_stock_ftp_config(): array {
    return [
        'host' => 'ftp.1388739759.s1.eshop-rychle.cz',
        'user' => '38463.s1.eshop-rychle.cz',
        'pass' => 'Alea11',
        'dir'  => '/stock_export/manual',
    ];
}

function cftp_get_latest_stock_file_info(): array {
    $cfg = cftp_stock_ftp_config();

    if (!function_exists('ftp_connect')) {
        throw new RuntimeException('PHP nemá povolené FTP funkce (ftp_connect).');
    }

    $ftp = @ftp_connect($cfg['host'], 21, 30);
    if (!$ftp) throw new RuntimeException('Nelze se připojit na FTP.');

    if (!@ftp_login($ftp, $cfg['user'], $cfg['pass'])) {
        @ftp_close($ftp);
        throw new RuntimeException('Nelze se přihlásit na FTP.');
    }

    @ftp_pasv($ftp, true);

    if (!@ftp_chdir($ftp, $cfg['dir'])) {
        @ftp_close($ftp);
        throw new RuntimeException('Nelze přejít do složky na FTP: ' . $cfg['dir']);
    }

    $files = @ftp_nlist($ftp, '.');
    if (!$files || !is_array($files)) {
        @ftp_close($ftp);
        throw new RuntimeException('Nelze vypsat soubory ve FTP složce.');
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
            'ftp_file' => $base,
            'ftp_size' => (int)$size,
            'ftp_mtime' => (int)$mtime,
            'ftp_mtime_text' => $mtime > 0 ? date('Y-m-d H:i:s', (int)$mtime) : 'nezjištěno',
            'ftp_dir' => (string)$cfg['dir'],
            'ftp_host' => (string)$cfg['host'],
        ];
        $all[] = $item;
        if (preg_match('~\.csv$~i', $base)) $csv[] = $item;
    }

    @ftp_close($ftp);

    $candidates = $csv ?: $all;
    if (!$candidates) {
        throw new RuntimeException('Ve FTP složce není žádný soubor ke stažení.');
    }

    usort($candidates, function($a, $b) {
        return ((int)($b['ftp_mtime'] ?? 0) <=> (int)($a['ftp_mtime'] ?? 0)) ?: ((int)($b['ftp_size'] ?? 0) <=> (int)($a['ftp_size'] ?? 0));
    });

    $best = $candidates[0];
    $best['checked_at'] = date('Y-m-d H:i:s');
    $best['version'] = CFTP_VERSION;
    return $best;
}

function cftp_download_stock_file_from_info(array $best, string $localAbsDir): array {
    $cfg = cftp_stock_ftp_config();

    if (!function_exists('ftp_connect')) {
        throw new RuntimeException('PHP nemá povolené FTP funkce (ftp_connect).');
    }

    $fileName = (string)($best['ftp_file'] ?? '');
    if ($fileName === '') throw new RuntimeException('Není určený FTP soubor ke stažení.');

    $ftp = @ftp_connect($cfg['host'], 21, 30);
    if (!$ftp) throw new RuntimeException('Nelze se připojit na FTP.');

    if (!@ftp_login($ftp, $cfg['user'], $cfg['pass'])) {
        @ftp_close($ftp);
        throw new RuntimeException('Nelze se přihlásit na FTP.');
    }

    @ftp_pasv($ftp, true);

    if (!@ftp_chdir($ftp, $cfg['dir'])) {
        @ftp_close($ftp);
        throw new RuntimeException('Nelze přejít do složky na FTP: ' . $cfg['dir']);
    }

    $safe = cftp_safe_filename($fileName);
    $stamp = date('Ymd_His');
    $localAbs = rtrim($localAbsDir, '/\\') . '/' . $stamp . '_' . $safe;
    $tmpAbs = rtrim($localAbsDir, '/\\') . '/__tmp_ftp_' . $stamp . '_' . $safe;

    if (!@ftp_get($ftp, $tmpAbs, $fileName, FTP_BINARY)) {
        @ftp_close($ftp);
        throw new RuntimeException('Stažení skladového souboru z FTP selhalo.');
    }
    @ftp_close($ftp);

    if (!@rename($tmpAbs, $localAbs)) {
        @unlink($tmpAbs);
        throw new RuntimeException('Stažený skladový soubor nejde uložit na server.');
    }

    $best['local_file'] = $localAbs;
    $best['local_name'] = basename($localAbs);
    return $best;
}

function cftp_confirm_matches(array $latest): bool {
    $postedFile = (string)($_POST['confirm_file'] ?? '');
    $postedMtime = (int)($_POST['confirm_mtime'] ?? -1);
    $postedSize = (int)($_POST['confirm_size'] ?? -1);

    return $postedFile === (string)($latest['ftp_file'] ?? '')
        && $postedMtime === (int)($latest['ftp_mtime'] ?? 0)
        && $postedSize === (int)($latest['ftp_size'] ?? 0);
}

function cftp_render_ftp_confirm_page(array $info, string $warning = ''): void {
    $back = 'index.php?view=xmlfeedy#rychly-postup-dodavatele';
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Potvrzení FTP skladu</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f3f7f4;margin:0;padding:24px;color:#111827}.card{max-width:820px;margin:0 auto;background:#fff;border:1px solid #d1fae5;border-radius:18px;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,.08)}h1{margin:0 0 10px;font-size:24px}.lead{color:#374151;line-height:1.5}.okbox{background:#ecfdf5;border:1px solid #86efac;border-radius:14px;padding:14px;margin:16px 0}.warn{background:#fff7ed;border:1px solid #fdba74;border-radius:14px;padding:14px;margin:16px 0;color:#9a3412}.grid{display:grid;grid-template-columns:220px 1fr;gap:8px 12px;margin-top:10px}.k{font-weight:700;color:#374151}.v{word-break:break-all}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.btn{border:0;border-radius:12px;padding:12px 18px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-block}.btn-go{background:#16a34a;color:#fff}.btn-back{background:#e5e7eb;color:#111827}.small{font-size:13px;color:#6b7280;margin-top:12px}</style>';
    echo '</head><body><div class="card">';
    echo '<h1>Kontrola skladu na FTP</h1>';
    echo '<p class="lead">Modul našel nejnovější skladový soubor na FTP. Zkontroluj název, datum a velikost. Teprve po potvrzení se spustí celý proces a vygenerují se soubory pro e-shop.</p>';
    if ($warning !== '') echo '<div class="warn"><strong>Pozor:</strong> ' . cftp_h($warning) . '</div>';
    echo '<div class="okbox"><div class="grid">';
    echo '<div class="k">FTP složka</div><div class="v"><code>' . cftp_h($info['ftp_dir'] ?? '') . '</code></div>';
    echo '<div class="k">Nalezený soubor</div><div class="v"><strong>' . cftp_h($info['ftp_file'] ?? '') . '</strong></div>';
    echo '<div class="k">Datum souboru na FTP</div><div class="v"><strong>' . cftp_h($info['ftp_mtime_text'] ?? '') . '</strong></div>';
    echo '<div class="k">Velikost</div><div class="v">' . cftp_h(cftp_format_bytes((int)($info['ftp_size'] ?? 0))) . '</div>';
    echo '<div class="k">Kontrola provedena</div><div class="v">' . cftp_h($info['checked_at'] ?? date('Y-m-d H:i:s')) . '</div>';
    echo '</div></div>';
    echo '<form method="post" action="auto_sklad_ftp_full_process.php" class="actions">';
    echo '<input type="hidden" name="auto_ftp_all" value="1">';
    echo '<input type="hidden" name="confirm_ftp" value="1">';
    echo '<input type="hidden" name="confirm_file" value="' . cftp_h($info['ftp_file'] ?? '') . '">';
    echo '<input type="hidden" name="confirm_mtime" value="' . (int)($info['ftp_mtime'] ?? 0) . '">';
    echo '<input type="hidden" name="confirm_size" value="' . (int)($info['ftp_size'] ?? 0) . '">';
    echo '<button type="submit" class="btn btn-go" onclick="this.disabled=true;this.innerText=\'Pracuji…\';this.form.submit();">Ano, pokračovat a vytvořit soubory</button>';
    echo '<a class="btn btn-back" href="' . cftp_h($back) . '">Zpět bez změn</a>';
    echo '</form>';
    echo '<div class="small">Po potvrzení se stáhnou dodavatelské feedy, stáhne se tento sklad, zpracují se všichni dodavatelé a vygenerují se pouze změny.</div>';
    echo '</div></body></html>';
}

function cftp_prepare_stock_outputs_from_file(string $downloadedAbs, array $downloadInfo): array {
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
    if ($docRoot === '') $docRoot = realpath(__DIR__) ?: __DIR__;

    $skladRelDir = 'Dodavatele/SKLAD C-STORE';
    $skladAbsDir = $docRoot . '/' . $skladRelDir;
    if (!cftp_ensure_dir($skladAbsDir)) {
        throw new RuntimeException('Nelze vytvořit složku skladu.');
    }

    $mergedRelDir = 'VSECHNY SPOJENE XML FEED';
    $mergedAbsDir = $docRoot . '/' . $mergedRelDir;
    if (!cftp_ensure_dir($mergedAbsDir)) {
        throw new RuntimeException('Nelze vytvořit složku pro spojený soubor.');
    }

    // Smaž jen pracovní skladové výstupy, ne dodavatelské výstupy a ne ruční proces.
    foreach ([
        $skladAbsDir . '/celySkladExport.csv',
        $skladAbsDir . '/celyskladexport.csv',
        $skladAbsDir . '/AktualniExportSkladu.csv',
        $skladAbsDir . '/aktualniexportskladu.csv',
        $skladAbsDir . '/AktualizacniSkladCSTORE.csv',
        $skladAbsDir . '/aktualizacniskladcstore.csv',
        $mergedAbsDir . '/SpojeneFeedy_CSTORE_SKLAD.csv',
        $mergedAbsDir . '/spojenefeedy_cstore_sklad.csv',
        $mergedAbsDir . '/ALL_XML_CSTORE_SKLAD.csv',
        $mergedAbsDir . '/all_xml_cstore_sklad.csv',
    ] as $oldStockFile) {
        cftp_unlink_if_file($oldStockFile);
    }

    $celyAbs = $skladAbsDir . '/celySkladExport.csv';
    $aktualniAbs = $skladAbsDir . '/AktualniExportSkladu.csv';
    $updAbs = $skladAbsDir . '/AktualizacniSkladCSTORE.csv';

    if (!@copy($downloadedAbs, $celyAbs)) {
        throw new RuntimeException('Nelze vytvořit kopii celySkladExport.csv.');
    }
    @touch($celyAbs);
    cftp_copy_if_file($celyAbs, $skladAbsDir . '/celyskladexport.csv');

    $delim = cftp_detect_delim_from_file($downloadedAbs);
    $fh = @fopen($downloadedAbs, 'rb');
    if (!$fh) {
        throw new RuntimeException('Stažený CSV soubor nejde otevřít.');
    }

    $outAktualni = cftp_open_csv_writer($aktualniAbs, ['EAN','Sklad_G']);
    $outUpd = cftp_open_csv_writer($updAbs, ['EAN','Dostupnost']);

    $totalRows = 0;
    $acceptedRows = 0;
    $skippedRows = 0;
    $cstoreRows = [];

    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $totalRows++;
        if (!is_array($row) || count($row) < 7) { $skippedRows++; continue; }

        $ean = cftp_clean_ean((string)($row[CFTP_EAN_COL_INDEX] ?? ''));
        $gRaw = trim((string)($row[CFTP_SKLAD_COL_INDEX] ?? ''));

        if ($totalRows === 1) {
            $probe = cftp_norm_lower($ean . ' ' . $gRaw);
            if (strpos($probe, 'ean') !== false || strpos($probe, 'sklad') !== false || strpos($probe, 'stock') !== false) {
                $skippedRows++;
                continue;
            }
        }

        if ($ean === '' || !cftp_valid_ean_like($ean)) { $skippedRows++; continue; }
        $g = cftp_parse_g_value($gRaw);
        if ($g === null || !cftp_g_allowed($g)) { $skippedRows++; continue; }

        if (!isset($cstoreRows[$ean])) {
            $cstoreRows[$ean] = $g;
            $outAktualni->fputcsv([$ean, (string)$g], ';');
            $outUpd->fputcsv([$ean, '0'], ';');
            $acceptedRows++;
        }
    }
    fclose($fh);
    @touch($aktualniAbs);
    @touch($updAbs);

    $baseMerged = cftp_find_existing_file($mergedAbsDir, ['SpojeneFeedy.csv', 'spojenefeedy.csv', 'ALL_XML.csv', 'all_xml.csv']);
    $outSpojene = $mergedAbsDir . '/SpojeneFeedy_CSTORE_SKLAD.csv';
    $outAllXml = $mergedAbsDir . '/ALL_XML_CSTORE_SKLAD.csv';

    $writeMerged = function(string $target) use ($baseMerged, $cstoreRows, $docRoot): int {
        $fout = cftp_open_csv_writer($target, ['Zdroj','EAN','Dostupnost','Aktualizováno']);
        $written = 0;
        $baseDate = $baseMerged && is_file($baseMerged) ? date('Y-m-d H:i:s', (int)(@filemtime($baseMerged) ?: time())) : date('Y-m-d H:i:s');

        $sportUpd = cftp_find_existing_file($docRoot . '/Dodavatele/SportImport', ['AktualizacniSportImport.csv', 'aktualizacnisportimport.csv']);
        $refreshSportImport = ($sportUpd !== null && is_file($sportUpd));

        if ($baseMerged && is_file($baseMerged)) {
            $baseDelim = cftp_detect_delim_from_file($baseMerged);
            $fhBase = @fopen($baseMerged, 'rb');
            if ($fhBase) {
                $lineNo = 0;
                while (($row = fgetcsv($fhBase, 0, $baseDelim)) !== false) {
                    $lineNo++;
                    if (!is_array($row) || count($row) < 3) continue;
                    if ($lineNo === 1) {
                        $first = cftp_norm_lower(trim((string)($row[0] ?? '')));
                        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
                        if ($first === 'zdroj') continue;
                    }
                    $src = trim((string)($row[0] ?? ''));
                    $ean = trim((string)($row[1] ?? ''));
                    $val = trim((string)($row[2] ?? ''));
                    $dt = trim((string)($row[3] ?? ''));
                    if ($src === '' || $ean === '') continue;
                    if (cftp_source_is_cstore($src)) continue;
                    if ($refreshSportImport && cftp_source_is_sportimport($src)) continue;
                    if ($val === '') $val = '0';
                    if ($dt === '') $dt = $baseDate;
                    $fout->fputcsv([$src, $ean, $val, $dt], ';');
                    $written++;
                }
                fclose($fhBase);
            }
        }

        if ($refreshSportImport && $sportUpd) {
            $sportDate = date('Y-m-d H:i:s', (int)(@filemtime($sportUpd) ?: time()));
            cftp_append_update_csv_rows($fout, 'SportImport', $sportUpd, $sportDate, $written);
        }

        $uploadDate = date('Y-m-d H:i:s');
        foreach ($cstoreRows as $ean => $g) {
            $fout->fputcsv(['CSTORE_SKLAD', (string)$ean, '0', $uploadDate], ';');
            $written++;
        }
        @touch($target);
        return $written;
    };

    $mergedRows = $writeMerged($outSpojene);
    $writeMerged($outAllXml);

    cftp_copy_if_file($aktualniAbs, $skladAbsDir . '/aktualniexportskladu.csv');
    cftp_copy_if_file($updAbs, $skladAbsDir . '/aktualizacniskladcstore.csv');
    cftp_copy_if_file($outSpojene, $mergedAbsDir . '/spojenefeedy_cstore_sklad.csv');
    cftp_copy_if_file($outAllXml, $mergedAbsDir . '/all_xml_cstore_sklad.csv');

    $info = [
        'version' => CFTP_VERSION,
        'source' => 'FTP',
        'ftp_file' => (string)($downloadInfo['ftp_file'] ?? ''),
        'ftp_dir' => (string)($downloadInfo['ftp_dir'] ?? ''),
        'ftp_size' => (int)($downloadInfo['ftp_size'] ?? 0),
        'ftp_mtime' => (int)($downloadInfo['ftp_mtime'] ?? 0),
        'ftp_mtime_text' => (string)($downloadInfo['ftp_mtime_text'] ?? ''),
        'local_file' => basename((string)($downloadInfo['local_file'] ?? '')),
        'downloaded_at' => date('Y-m-d H:i:s'),
        'rows_total' => $totalRows,
        'rows_accepted' => $acceptedRows,
        'rows_skipped' => $skippedRows,
        'merged_rows' => $mergedRows,
        'rule' => 'F + G, G pouze 1-25 nebo 1001-1025, sloupec E se nepoužívá, ve spojeném souboru Dostupnost=0',
    ];

    @file_put_contents($skladAbsDir . '/posledni_ftp_sklad_info.json', json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    @file_put_contents($skladAbsDir . '/posledni_upload_info.json', json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    return $info;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cftp_redirect(['ftp_auto_all' => 'err', 'msg' => 'Akce nebyla odeslána.']);
}

try {
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
    if ($docRoot === '') $docRoot = realpath(__DIR__) ?: __DIR__;
    $skladAbsDir = $docRoot . '/Dodavatele/SKLAD C-STORE';
    if (!cftp_ensure_dir($skladAbsDir)) {
        throw new RuntimeException('Nelze vytvořit složku skladu.');
    }

    // 1. krok: jen sáhnout na FTP, zjistit nejnovější soubor a zeptat se, jestli pokračovat.
    $latestFtpInfo = cftp_get_latest_stock_file_info();
    if (empty($_POST['confirm_ftp'])) {
        cftp_render_ftp_confirm_page($latestFtpInfo);
        exit;
    }

    // Když se mezi potvrzením a spuštěním na FTP změnil soubor, znovu zobraz potvrzení.
    if (!cftp_confirm_matches($latestFtpInfo)) {
        cftp_render_ftp_confirm_page($latestFtpInfo, 'Soubor na FTP se mezitím změnil. Zkontroluj nové údaje a potvrď pokračování znovu.');
        exit;
    }

    // Uvolní session lock, aby interní požadavky se stejným PHPSESSID nečekaly samy na sebe.
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }

    $feeds = cftp_loopback_request('run_feeds.php', 'GET', ['ajax' => '1', 'auto' => '1']);
    if (empty($feeds['ok'])) {
        throw new RuntimeException('Načtení dodavatelských XML feedů selhalo: HTTP ' . (string)($feeds['code'] ?? 0) . ' ' . (string)($feeds['error'] ?? ''));
    }

    $downloadInfo = cftp_download_stock_file_from_info($latestFtpInfo, $skladAbsDir);
    $stockInfo = cftp_prepare_stock_outputs_from_file((string)$downloadInfo['local_file'], $downloadInfo);

    $process = cftp_loopback_request('process_all_suppliers.php', 'POST', [], ['auto' => '1']);
    if (empty($process['ok'])) {
        throw new RuntimeException('Zpracování všech dodavatelů selhalo: HTTP ' . (string)($process['code'] ?? 0) . ' ' . (string)($process['error'] ?? ''));
    }

    $changes = cftp_loopback_request('generate_all_supplier_changes.php', 'POST', [], ['auto' => '1']);
    if (empty($changes['ok'])) {
        throw new RuntimeException('Generování změnových souborů selhalo: HTTP ' . (string)($changes['code'] ?? 0) . ' ' . (string)($changes['error'] ?? ''));
    }

    cftp_redirect([
        'ftp_auto_all' => 'ok',
        'ftp_file' => (string)($stockInfo['ftp_file'] ?? ''),
        'rows' => (string)($stockInfo['rows_accepted'] ?? 0),
        'merged' => (string)($stockInfo['merged_rows'] ?? 0),
    ]);
} catch (Throwable $e) {
    cftp_redirect([
        'ftp_auto_all' => 'err',
        'msg' => $e->getMessage(),
    ]);
}
