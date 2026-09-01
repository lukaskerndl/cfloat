<?php
// Přístup jen pro přihlášené (dřív byl tento endpoint veřejný).
require_once __DIR__ . '/_require_login.php';

// print_export_today.php – Export dnešních zásilek (pouze Balíkovna) do CSV pro expedici
// Pozn.: Evidence se zapisuje při tisku v label.php do /print_logs/YYYY-MM-DD.csv

error_reporting(E_ALL);
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');

$logDir = __DIR__ . '/print_logs';
$todayFile = $logDir . '/' . date('Y-m-d') . '.csv';

$rows = [];
$header = ['ID','Čas','Objednávka','Dopravce','Služba','Tracking','Dobírka','Jméno','Ulice','Město','PSČ','Telefon','Email'];

if (is_file($todayFile)) {
    $fh = @fopen($todayFile, 'r');
    if ($fh) {
        $h = @fgetcsv($fh, 0, ';');
        if (is_array($h) && count($h) > 0) {
            $header = $h;
            while (($r = @fgetcsv($fh, 0, ';')) !== false) {
                if (!is_array($r) || count($r) === 0) continue;
                if (count($r) < count($header)) {
                    $r = array_pad($r, count($header), '');
                }
                $assoc = array_combine($header, array_slice($r, 0, count($header)));
                $carrier = trim((string)($assoc['Dopravce'] ?? ''));
                if ($carrier === 'Balíkovna') {
                    $rows[] = $assoc;
                }
            }
        }
        @fclose($fh);
    }
}

$filename = 'balikovna_' . date('Y-m-d') . '.csv';

// CSV download (Excel friendly)
while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// BOM pro Excel
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
if ($out) {
    fputcsv($out, $header, ';');
    foreach ($rows as $assoc) {
        $line = [];
        foreach ($header as $k) {
            $line[] = (string)($assoc[$k] ?? '');
        }
        fputcsv($out, $line, ';');
    }
    fclose($out);
}
exit;
