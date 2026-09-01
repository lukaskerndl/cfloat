<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

const GLS_CACHE_FILE_DIAG = __DIR__ . '/../gls_cache.json';

$cache = [];
if (is_file(GLS_CACHE_FILE_DIAG)) {
    $raw = @file_get_contents(GLS_CACHE_FILE_DIAG);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($decoded)) $cache = $decoded;
}

$order = isset($_GET['order']) ? trim((string)$_GET['order']) : '';
$entry = ($order !== '' && isset($cache[$order])) ? $cache[$order] : null;

// bez zadaného čísla ukážeme poslední objednávku, co má uložené debug_raw_response
if ($entry === null && $order === '') {
    foreach (array_reverse($cache, true) as $k => $v) {
        if (is_array($v) && isset($v['debug_raw_response'])) {
            $order = $k;
            $entry = $v;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>GLS debug – syrová odpověď</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; }
.wrap { max-width:900px; margin:0 auto; }
.card { background:#fff; border:1px solid #e7e9ec; border-radius:14px; padding:18px; margin-bottom:16px; }
input[type=text] { padding:9px 12px; border:1px solid #ccc; border-radius:8px; font-size:14px; width:220px; }
button { padding:9px 16px; border:none; border-radius:8px; background:#00b52a; color:#fff; font-weight:700; cursor:pointer; }
pre { background:#f7f8f9; padding:12px; border-radius:10px; overflow:auto; font-size:12px; max-height:600px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>GLS debug – syrová odpověď z API</h1>

    <div class="card">
        <form method="get">
            <label>Číslo objednávky (nepovinné, jinak poslední s debug daty):</label><br><br>
            <input type="text" name="order" value="<?php echo h($order); ?>" placeholder="např. 1545107657">
            <button type="submit">Zobrazit</button>
        </form>
    </div>

    <?php if ($entry === null): ?>
        <div class="card">Žádná debug data nenalezena (buď pro tuhle objednávku, nebo vůbec).</div>
    <?php else: ?>
        <div class="card">
            <p><b>Objednávka:</b> <?php echo h($order); ?></p>
            <p><b>Naším kódem vytažené číslo (extracted_pn):</b> <?php echo h($entry['debug_extracted_pn'] ?? '(prázdné)'); ?></p>
        </div>
        <div class="card">
            <h2 style="font-size:15px;">Celá syrová odpověď GLS</h2>
            <pre><?php echo h(json_encode($entry['debug_raw_response'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
