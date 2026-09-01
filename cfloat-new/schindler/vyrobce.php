<?php
declare(strict_types=1);

/**
 * cfloat-new/schindler/vyrobce.php
 * Krok 2: v rámci zvoleného výrobce ukáže rozpad kategorií (z CATEGORYTEXT
 * feedu) s počty produktů, ať se dá zúžit výběr, než se zobrazí seznam
 * jednotlivých produktů (viz produkty.php).
 */

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/lib/schindler_feed.php';

$manufacturer = trim((string)($_GET['m'] ?? ''));
if ($manufacturer === '') {
    header('Location: index.php');
    exit;
}

$meta = schindler_load_meta();
if ($meta === null || !isset($meta['categories'][$manufacturer])) {
    header('Location: index.php');
    exit;
}

$categories = $meta['categories'][$manufacturer];
uksort($categories, 'strcasecmp');
$totalGroups = array_sum($categories);
$selectedCount = isset($_SESSION['schindler_selected']) ? count($_SESSION['schindler_selected']) : 0;
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>SCHINDLER – <?php echo h($manufacturer); ?> – kategorie</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --g1:#24d84a; --g2:#00b52a; --border:#e7e9ec; --muted:#6b7280; }
* { box-sizing:border-box; }
body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; color:#1b1f23; }
.wrap { max-width:1100px; margin:0 auto; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:6px 14px; display:inline-block; margin-bottom:14px; }
h1 { font-size:20px; margin:6px 0 2px; }
.subtitle { color:var(--muted); font-size:13px; margin-bottom:18px; }
.selected-banner { background:#fff8e6; border:1.5px solid #ffe08a; border-radius:12px; padding:10px 16px; margin-bottom:16px; font-size:13.5px; }
.btn { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; border:none; border-radius:999px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
.cat-list { background:#fff; border:1px solid var(--border); border-radius:14px; overflow:hidden; }
.cat-row { display:flex; justify-content:space-between; align-items:center; padding:13px 18px; text-decoration:none; color:#1b1f23; border-bottom:1px solid var(--border); }
.cat-row:last-child { border-bottom:none; }
.cat-row:hover { background:#f7fbf8; }
.cat-name { font-size:13.5px; font-weight:600; }
.cat-count { font-size:12px; color:var(--muted); background:#f2f4f5; border-radius:999px; padding:3px 10px; }
.cat-all { background:#f2fbf5; font-weight:800; }
</style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="index.php">&larr; Zpět na výběr výrobce</a>
    <h1>SCHINDLER – <?php echo h($manufacturer); ?></h1>
    <p class="subtitle">Vyber kategorii, ve které chceš procházet produkty (<?php echo (int)$totalGroups; ?> celkem u tohoto výrobce).</p>

    <?php if ($selectedCount > 0): ?>
        <div class="selected-banner">🛒 Ve výběru je <b><?php echo $selectedCount; ?></b> produktů. <a href="import.php">Pokračovat k importu →</a></div>
    <?php endif; ?>

    <div class="cat-list">
        <a class="cat-row cat-all" href="produkty.php?m=<?php echo urlencode($manufacturer); ?>">
            <span class="cat-name">Všechny kategorie</span>
            <span class="cat-count"><?php echo (int)$totalGroups; ?></span>
        </a>
        <?php foreach ($categories as $cat => $count): ?>
            <a class="cat-row" href="produkty.php?m=<?php echo urlencode($manufacturer); ?>&kat=<?php echo urlencode($cat); ?>">
                <span class="cat-name"><?php echo h($cat); ?></span>
                <span class="cat-count"><?php echo (int)$count; ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
