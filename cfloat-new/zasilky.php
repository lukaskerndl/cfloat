<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Data se čtou ze stejných logů, do kterých zapisuje label.php (společné
 * pro starý i nový tisk štítků) – /print_logs/{datum}.csv. Žádná vlastní
 * databáze, jen čtení.
 */
$logDir = __DIR__ . '/../print_logs';
$perPage = 100;

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));

function zas_read_log_file(string $file): array {
    $out = [];
    if (!is_file($file)) return $out;
    $fh = @fopen($file, 'r');
    if (!$fh) return $out;
    $hdr = @fgetcsv($fh, 0, ';');
    if (!is_array($hdr) || count($hdr) === 0) { @fclose($fh); return $out; }
    while (($r = @fgetcsv($fh, 0, ';')) !== false) {
        if (!is_array($r) || count($r) === 0) continue;
        if (count($r) < count($hdr)) $r = array_pad($r, count($hdr), '');
        $assoc = @array_combine($hdr, array_slice($r, 0, count($hdr)));
        if (is_array($assoc)) $out[] = $assoc;
    }
    @fclose($fh);
    return $out;
}

/** Veřejná stránka pro sledování zásilky podle dopravce. Vrací null, pokud dopravce/tracking neznáme. */
function zas_tracking_url(string $carrier, string $tracking): ?string {
    $tracking = trim($tracking);
    if ($tracking === '') return null;
    $c = mb_strtolower($carrier, 'UTF-8');
    if (strpos($c, 'zásilkovna') !== false || strpos($c, 'zasilkovna') !== false) {
        return 'https://tracking.packeta.com/cs_CZ/' . rawurlencode($tracking);
    }
    if (strpos($c, 'gls') !== false) {
        return 'https://gls-group.eu/CZ/cs/sledovani-zasilek?match=' . rawurlencode($tracking);
    }
    if (strpos($c, 'balíkovna') !== false || strpos($c, 'balikovna') !== false || strpos($c, 'pošta') !== false || strpos($c, 'posta') !== false) {
        return 'https://www.postaonline.cz/trackandtrace/-/zasilka/cislo?parcelNumbers=' . rawurlencode($tracking);
    }
    return null;
}

// ---------------------------------------------------------------------------
// Načtení + vyhledání napříč VŠEMI logy (dnešek i historie), řazeno od nejnovějšího
// ---------------------------------------------------------------------------
$files = [];
if (is_dir($logDir)) {
    foreach (@scandir($logDir) ?: [] as $fn) {
        if ($fn === '.' || $fn === '..') continue;
        if (substr($fn, -4) !== '.csv') continue;
        $files[] = $fn;
    }
}
rsort($files); // nejnovější datum nahoře

$qLower = mb_strtolower($q, 'UTF-8');
$qTerms = ($qLower !== '') ? preg_split('/\s+/', $qLower, -1, PREG_SPLIT_NO_EMPTY) : [];

$total = 0;
$pageRows = [];
$start = ($page - 1) * $perPage;
$end = $start + $perPage;

foreach ($files as $fn) {
    $day = preg_replace('/\.csv$/', '', $fn);
    $rows = zas_read_log_file($logDir . '/' . $fn);
    if (!empty($rows)) $rows = array_reverse($rows); // novější nahoře i uvnitř dne

    foreach ($rows as $r) {
        if (!empty($qTerms)) {
            $hay = [
                (string)($r['Objednávka'] ?? ''), (string)($r['Tracking'] ?? ''), (string)($r['Jméno'] ?? ''),
                (string)($r['Ulice'] ?? ''), (string)($r['Město'] ?? ''), (string)($r['PSČ'] ?? ''),
                (string)($r['Telefon'] ?? ''), (string)($r['Email'] ?? ''), (string)($r['Dopravce'] ?? ''),
            ];
            $hayStr = mb_strtolower(implode(' | ', $hay), 'UTF-8');
            $miss = false;
            foreach ($qTerms as $t) {
                if (mb_strpos($hayStr, $t) === false) { $miss = true; break; }
            }
            if ($miss) continue;
        }

        if ($total >= $start && $total < $end) {
            $r['_Datum'] = $day;
            $pageRows[] = $r;
        }
        $total++;
    }
}

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Zásilky – Nový Cfloat</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --g1:#24d84a; --g2:#00b52a; --ink:#1b1f23; --muted:#6b7280; --border:#e7e9ec; }
* { box-sizing:border-box; }
body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#fafbfb; margin:0; padding:16px 12px 60px; color:var(--ink); }
.wrap { max-width:900px; margin:0 auto; }
.logo-top { text-align:center; margin-bottom:12px; }
.logo-top img { max-width:130px; }
.logo-top a { text-decoration:none; }
.back-link { display:inline-block; color:var(--muted); font-size:12.5px; text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:6px 14px; margin-bottom:12px; }
h1 { font-size:20px; margin:0 0 4px; font-weight:800; }
.subtitle { color:var(--muted); font-size:12.5px; margin-bottom:14px; }

.search-card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:12px 14px; margin-bottom:14px; }
.search-card input[type=text] { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; font-size:14px; }
.search-card label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.02em; display:block; margin-bottom:5px; }
.search-row { display:flex; gap:8px; margin-top:8px; }
.btn { border:none; border-radius:999px; padding:9px 18px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
.btn-green { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; }
.btn-secondary { background:#eee; color:#333; }

.summary { font-size:12.5px; color:var(--muted); margin-bottom:10px; }

.ship-card {
    background:#fff; border:1px solid var(--border); border-radius:14px; padding:12px 14px; margin-bottom:8px;
}
.ship-top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; }
.ship-order { font-weight:800; font-size:14px; }
.ship-datetime { font-size:11px; color:var(--muted); }
.carrier-badge {
    display:inline-block; font-size:10.5px; font-weight:800; padding:3px 9px; border-radius:999px;
    background:#eafbf0; color:#0a7a34; white-space:nowrap;
}
.ship-name { font-size:13.5px; font-weight:700; margin-top:6px; }
.ship-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:4px 14px; margin-top:8px; font-size:12.5px; color:#444; }
.ship-detail-grid .full { grid-column:1 / -1; }
.ship-detail-grid .label { color:var(--muted); font-size:10.5px; text-transform:uppercase; letter-spacing:.02em; display:block; }
.tracking-link {
    color:var(--g2); font-weight:700; text-decoration:none; word-break:break-all;
}
.tracking-link:hover { text-decoration:underline; }
.tracking-plain { color:#444; word-break:break-all; }
.cod-badge { color:#b8860b; font-weight:700; }

.pagination { display:flex; gap:10px; justify-content:center; align-items:center; margin-top:16px; font-size:13px; }
.pagination a, .pagination span { color:var(--g2); text-decoration:none; font-weight:700; padding:6px 10px; }
.pagination .disabled { color:#ccc; }

.empty-msg { text-align:center; color:var(--muted); padding:40px 0; font-size:14px; }

@media (max-width: 480px) {
    .ship-detail-grid { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="wrap">
    <div class="logo-top"><a href="index.php"><img src="../logo-1.png" alt="C-Store.cz"></a></div>
    <a class="back-link" href="index.php">&larr; Zpět na Nový Cfloat</a>
    <a class="back-link" href="tisk-stitku.php" style="margin-left:8px;">🏷️ Tisk štítků</a>
    <h1>Zásilky</h1>
    <div class="subtitle">Přehled všech vytištěných štítků – dnešní i historie. Klikni na tracking pro otevření sledování u dopravce.</div>

    <div class="search-card">
        <form method="get" action="zasilky.php">
            <label for="q">Hledat (jméno, příjmení, tracking, adresa, PSČ, město…)</label>
            <input type="text" id="q" name="q" value="<?php echo h($q); ?>" placeholder="např. Novák, nebo číslo zásilky, nebo město">
            <div class="search-row">
                <button type="submit" class="btn btn-green">Hledat</button>
                <?php if ($q !== ''): ?><a class="btn btn-secondary" href="zasilky.php">Vymazat</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="summary">
        Celkem nalezeno: <b><?php echo (int)$total; ?></b> zásilek &nbsp;|&nbsp; strana <b><?php echo (int)$page; ?></b> / <?php echo (int)$totalPages; ?>
    </div>

    <?php if (empty($pageRows)): ?>
        <div class="empty-msg">Žádné zásilky neodpovídají hledání.</div>
    <?php else: ?>
        <?php foreach ($pageRows as $r): ?>
            <?php
            $carrier = (string)($r['Dopravce'] ?? '');
            $tracking = trim((string)($r['Tracking'] ?? ''));
            $trackUrl = zas_tracking_url($carrier, $tracking);
            $street = trim((string)($r['Ulice'] ?? ''));
            $city = trim((string)($r['Město'] ?? ''));
            $zip = trim((string)($r['PSČ'] ?? ''));
            $phone = trim((string)($r['Telefon'] ?? ''));
            $cod = trim((string)($r['Dobírka'] ?? ''));
            $service = trim((string)($r['Služba'] ?? ''));
            $addrLine = trim($street);
            $cityZip = trim(($zip !== '' ? $zip . ' ' : '') . $city);
            if ($cityZip !== '') $addrLine .= ($addrLine !== '' ? ', ' : '') . $cityZip;
            ?>
            <div class="ship-card">
                <div class="ship-top">
                    <div>
                        <span class="ship-order">#<?php echo h($r['Objednávka'] ?? ''); ?></span>
                        <span class="carrier-badge"><?php echo h($carrier . ($service !== '' ? ' · ' . $service : '')); ?></span>
                    </div>
                    <div class="ship-datetime"><?php echo h(($r['_Datum'] ?? '') . ' ' . ($r['Čas'] ?? '')); ?></div>
                </div>
                <div class="ship-name"><?php echo h($r['Jméno'] ?? '—'); ?></div>
                <div class="ship-detail-grid">
                    <div class="full">
                        <span class="label">Tracking</span>
                        <?php if ($tracking === ''): ?>
                            —
                        <?php elseif ($trackUrl !== null): ?>
                            <a class="tracking-link" href="<?php echo h($trackUrl); ?>" target="_blank" rel="noopener"><?php echo h($tracking); ?> ↗</a>
                        <?php else: ?>
                            <span class="tracking-plain"><?php echo h($tracking); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="full">
                        <span class="label">Adresa</span>
                        <?php echo h($addrLine !== '' ? $addrLine : '—'); ?>
                    </div>
                    <div>
                        <span class="label">Telefon</span>
                        <?php echo $phone !== '' ? '<a href="tel:' . h($phone) . '" style="color:inherit;text-decoration:none;">' . h($phone) . '</a>' : '—'; ?>
                    </div>
                    <div>
                        <span class="label">Dobírka</span>
                        <?php echo ($cod !== '' && (float)$cod > 0) ? '<span class="cod-badge">' . h($cod) . ' Kč</span>' : '—'; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="zasilky.php?q=<?php echo urlencode($q); ?>&page=<?php echo $page - 1; ?>">◀ Předchozí</a>
                <?php else: ?>
                    <span class="disabled">◀ Předchozí</span>
                <?php endif; ?>
                <span>strana <?php echo (int)$page; ?> / <?php echo (int)$totalPages; ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="zasilky.php?q=<?php echo urlencode($q); ?>&page=<?php echo $page + 1; ?>">Další ▶</a>
                <?php else: ?>
                    <span class="disabled">Další ▶</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
