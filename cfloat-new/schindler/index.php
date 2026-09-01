<?php
declare(strict_types=1);

/**
 * cfloat-new/schindler/index.php
 *
 * Dashboard modulu SCHINDLER: stažení/aktualizace XML feedu (b2b.schindler.cz)
 * do lokální cache a přehled výrobců s počty produktů. Odsud se pokračuje
 * na vyber-kategorie.php (výběr kategorie u zvoleného výrobce) a dál na
 * produkty.php (výběr konkrétních produktů) a import.php.
 */

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/lib/schindler_feed.php';

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh') {
    @set_time_limit(180);
    try {
        $meta = schindler_refresh_cache();
        $flash = ['ok' => true, 'msg' => 'Feed stažen a zpracován: ' . (int)$meta['total_items'] . ' položek, ' . (int)$meta['total_groups'] . ' produktů (skupin variant), ' . count($meta['manufacturers']) . ' výrobců.'];
    } catch (Throwable $e) {
        $flash = ['ok' => false, 'msg' => 'Chyba: ' . $e->getMessage()];
    }
}

if (isset($_GET['cleared'])) {
    $flash = ['ok' => true, 'msg' => 'Výběr produktů byl vyprázdněn.'];
}

$meta = schindler_load_meta();
$selectedCount = isset($_SESSION['schindler_selected']) ? count($_SESSION['schindler_selected']) : 0;
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>SCHINDLER – import produktů</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --g1:#24d84a; --g2:#00b52a; --border:#e7e9ec; --muted:#6b7280; }
* { box-sizing:border-box; }
body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; color:#1b1f23; }
.wrap { max-width:1100px; margin:0 auto; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:6px 14px; display:inline-block; margin-bottom:14px; }
h1 { font-size:20px; margin:6px 0 2px; }
.subtitle { color:var(--muted); font-size:13px; margin-bottom:18px; }
.card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:18px; margin-bottom:16px; }
.flash { border-radius:12px; padding:12px 16px; margin-bottom:16px; font-size:13.5px; font-weight:600; }
.flash-ok { background:#eafbf0; color:#0a7a34; border:1px solid #bdeccb; }
.flash-err { background:#fdeceb; color:#d93025; border:1px solid #f5c6c2; }
.btn { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; border:none; border-radius:999px; padding:11px 20px; font-size:13.5px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
.btn.secondary { background:#eee; color:#333; }
.meta-row { display:flex; flex-wrap:wrap; gap:14px; margin:12px 0 4px; }
.meta-chip { background:#f7f8f9; border:1px solid var(--border); border-radius:10px; padding:8px 14px; font-size:12.5px; }
.meta-chip b { display:block; font-size:16px; }
.selected-banner { background:#fff8e6; border:1.5px solid #ffe08a; border-radius:12px; padding:10px 16px; margin-bottom:16px; font-size:13.5px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px,1fr)); gap:12px; margin-top:14px; }
.manu-tile { display:block; background:#fff; border:1px solid var(--border); border-radius:12px; padding:14px 16px; text-decoration:none; color:#1b1f23; transition:box-shadow .12s,transform .12s; }
.manu-tile:hover { box-shadow:0 6px 16px rgba(0,0,0,.08); transform:translateY(-1px); border-color:var(--g2); }
.manu-name { font-weight:800; font-size:14px; }
.manu-count { color:var(--muted); font-size:12px; margin-top:3px; }
.empty-note { font-size:13px; color:var(--muted); }
</style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="../index.php">&larr; Zpět na Nový Cfloat</a>
    <h1>SCHINDLER – zakládání nových produktů z XML feedu</h1>
    <p class="subtitle">b2b.schindler.cz &middot; výběr podle výrobce → kategorie → produktů → import do Eshop-rychle</p>

    <?php if ($flash): ?>
        <div class="flash <?php echo $flash['ok'] ? 'flash-ok' : 'flash-err'; ?>"><?php echo h($flash['msg']); ?></div>
    <?php endif; ?>

    <?php if ($selectedCount > 0): ?>
        <div class="selected-banner">
            <span>🛒 Ve výběru je <b><?php echo $selectedCount; ?></b> produktů připravených k importu.</span>
            <span>
                <a class="btn" href="import.php">Pokračovat k importu →</a>
                <a class="btn secondary" href="vyprazdnit-vyber.php" onclick="return confirm('Opravdu vyprázdnit celý výběr?');">Vyprázdnit výběr</a>
            </span>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 style="font-size:15px;margin-top:0;">1) Feed dodavatele</h2>
        <?php if ($meta === null): ?>
            <p class="empty-note">Feed ještě nebyl stažen a zpracován.</p>
        <?php else: ?>
            <div class="meta-row">
                <div class="meta-chip"><b><?php echo (int)$meta['total_items']; ?></b>položek (variant) ve feedu</div>
                <div class="meta-chip"><b><?php echo (int)$meta['total_groups']; ?></b>produktů (skupin variant)</div>
                <div class="meta-chip"><b><?php echo count($meta['manufacturers']); ?></b>výrobců</div>
                <div class="meta-chip"><b><?php echo h(date('d.m.Y H:i', strtotime((string)$meta['generated_at']))); ?></b>naposledy zpracováno</div>
            </div>
        <?php endif; ?>
        <form method="post" style="margin-top:14px;" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Stahuji a zpracovávám… (může to trvat i minutu)';">
            <input type="hidden" name="action" value="refresh">
            <button type="submit" class="btn"><?php echo $meta === null ? 'Stáhnout a zpracovat feed' : 'Stáhnout a zpracovat feed znovu'; ?></button>
        </form>
    </div>

    <?php if ($meta !== null): ?>
        <div class="card">
            <h2 style="font-size:15px;margin-top:0;">2) Vyber výrobce</h2>
            <div class="grid">
                <?php foreach ($meta['manufacturers'] as $name => $count): ?>
                    <a class="manu-tile" href="vyrobce.php?m=<?php echo urlencode($name); ?>">
                        <div class="manu-name"><?php echo h($name); ?></div>
                        <div class="manu-count"><?php echo (int)$count; ?> produktů</div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
