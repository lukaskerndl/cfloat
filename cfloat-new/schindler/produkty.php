<?php
declare(strict_types=1);

/**
 * cfloat-new/schindler/produkty.php
 * Krok 3: seznam produktů (skupin variant) pro daného výrobce (+ volitelně
 * kategorii). Zaškrtávací výběr nebo "Vybrat vše" -> přidání do session
 * výběru ($_SESSION['schindler_selected']), který se pak zpracovává na
 * import.php. Výběr je kumulativní napříč více návštěvami této stránky
 * (různé kategorie/výrobci), dokud se ručně nevyprázdní.
 */

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/lib/schindler_feed.php';

$manufacturer = trim((string)($_GET['m'] ?? $_POST['m'] ?? ''));
$category = isset($_GET['kat']) ? trim((string)$_GET['kat']) : (isset($_POST['kat']) ? trim((string)$_POST['kat']) : null);
if ($category === '') $category = null;
$jenSklad = !empty($_GET['jen_sklad']);

if ($manufacturer === '') {
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['schindler_selected'])) $_SESSION['schindler_selected'] = [];

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_selection') {
    $gids = $_POST['gid'] ?? [];
    if (is_array($gids) && !empty($gids)) {
        foreach ($gids as $gid) {
            $_SESSION['schindler_selected'][(string)$gid] = true;
        }
        $flash = ['ok' => true, 'msg' => count($gids) . ' produktů přidáno do výběru.'];
    } else {
        $flash = ['ok' => false, 'msg' => 'Nebyl zaškrtnutý žádný produkt.'];
    }
}

// Stejné jako výše, ale rovnou přesměruje na import.php - používá se pro
// tlačítko "Přidat a přejít k importu" nahoře, aby nešlo omylem přejít na
// import bez uložení zaškrtnutých produktů (to se dřív dalo přes samostatný
// odkaz mimo formulář a produkty se pak nepřidaly).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_selection_and_go') {
    $gids = $_POST['gid'] ?? [];
    if (is_array($gids)) {
        foreach ($gids as $gid) {
            $_SESSION['schindler_selected'][(string)$gid] = true;
        }
    }
    header('Location: import.php');
    exit;
}

$groups = schindler_load_groups_filtered($manufacturer, $category);
if ($jenSklad) {
    $groups = array_filter($groups, function ($g) {
        foreach ($g['items'] as $it) { if ((int)$it['stock'] > 0) return true; }
        return false;
    });
}

$selectedCount = count($_SESSION['schindler_selected']);

function schindler_qs(array $override = []): string
{
    $base = ['m' => $_GET['m'] ?? '', 'kat' => $_GET['kat'] ?? null, 'jen_sklad' => $_GET['jen_sklad'] ?? null];
    $merged = array_merge($base, $override);
    $merged = array_filter($merged, fn($v) => $v !== null && $v !== '');
    return http_build_query($merged);
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>SCHINDLER – <?php echo h($manufacturer); ?> – produkty</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --g1:#24d84a; --g2:#00b52a; --border:#e7e9ec; --muted:#6b7280; }
* { box-sizing:border-box; }
body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#fafbfb; margin:0; padding:20px 16px 80px; color:#1b1f23; }
.wrap { max-width:1200px; margin:0 auto; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid var(--border); border-radius:999px; padding:6px 14px; display:inline-block; margin-bottom:14px; }
h1 { font-size:20px; margin:6px 0 2px; }
.subtitle { color:var(--muted); font-size:13px; margin-bottom:14px; }
.flash { border-radius:12px; padding:10px 16px; margin-bottom:14px; font-size:13px; font-weight:600; }
.flash-ok { background:#eafbf0; color:#0a7a34; border:1px solid #bdeccb; }
.flash-err { background:#fdeceb; color:#d93025; border:1px solid #f5c6c2; }
.toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:14px; }
.toolbar input[type=text] { padding:8px 12px; border:1px solid #ccc; border-radius:999px; font-size:13px; width:220px; }
.toolbar label { font-size:12.5px; color:var(--muted); display:flex; align-items:center; gap:5px; }
.btn { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; border:none; border-radius:999px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
.btn.secondary { background:#eee; color:#333; }
table.plist { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border); border-radius:14px; overflow:hidden; font-size:12.5px; }
table.plist th { text-align:left; background:#f7f8f9; padding:9px 10px; border-bottom:2px solid var(--border); font-size:10.5px; text-transform:uppercase; color:var(--muted); }
table.plist td { padding:8px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
table.plist tr.already-selected { background:#f2fbf5; }
.p-thumb { width:44px; height:44px; object-fit:cover; border-radius:8px; border:1px solid var(--border); background:#f2f4f5; }
.p-name { font-weight:700; }
.p-sub { color:var(--muted); font-size:11px; }
.badge { border-radius:999px; padding:2px 9px; font-size:11px; font-weight:700; }
.badge-stock { background:#eafbf0; color:#0a7a34; }
.badge-nostock { background:#f2f4f5; color:#8a8f98; }
.sticky-bar { position:sticky; bottom:0; background:#fff; border-top:1px solid var(--border); padding:12px 18px; margin-top:14px; border-radius:14px; box-shadow:0 -4px 14px rgba(0,0,0,.05); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.empty-note { font-size:13px; color:var(--muted); padding:20px; text-align:center; }
</style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="vyrobce.php?m=<?php echo urlencode($manufacturer); ?>">&larr; Zpět na kategorie (<?php echo h($manufacturer); ?>)</a>
    <h1>SCHINDLER – <?php echo h($manufacturer); ?><?php if ($category): ?> / <?php echo h($category); ?><?php endif; ?></h1>
    <p class="subtitle"><?php echo count($groups); ?> produktů. Zaškrtni, které chceš importovat, nebo klikni na „Vybrat vše“.</p>

    <?php if ($flash): ?>
        <div class="flash <?php echo $flash['ok'] ? 'flash-ok' : 'flash-err'; ?>"><?php echo h($flash['msg']); ?></div>
    <?php endif; ?>

    <?php if (!empty($groups)): ?>
    <form method="post" id="selectForm">
        <input type="hidden" name="m" value="<?php echo h($manufacturer); ?>">
        <?php if ($category): ?><input type="hidden" name="kat" value="<?php echo h($category); ?>"><?php endif; ?>
    <?php endif; ?>

    <div class="toolbar">
        <input type="text" id="filterBox" placeholder="Hledat v názvu…" onkeyup="schindlerFilterRows()">
        <label><input type="checkbox" id="jenSkladChk" onchange="location.href=schindlerToggleSklad()" <?php echo $jenSklad ? 'checked' : ''; ?>> jen skladem</label>
        <span style="margin-left:auto;font-size:12.5px;color:var(--muted);">Ve výběru celkem: <b><?php echo $selectedCount; ?></b></span>
        <?php if (!empty($groups)): ?>
            <button type="submit" name="action" value="add_to_selection_and_go" class="btn secondary" onclick="return schindlerConfirmGo();">Přidat zaškrtnuté a přejít k importu →</button>
        <?php else: ?>
            <a class="btn secondary" href="import.php">Přejít k importu →</a>
        <?php endif; ?>
    </div>

    <?php if (empty($groups)): ?>
        <div class="empty-note">V této kategorii nejsou žádné produkty (podle aktuálního filtru).</div>
    <?php else: ?>

        <table class="plist" id="plistTable">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" id="checkAll" onclick="schindlerToggleAll(this)"></th>
                    <th></th>
                    <th>Produkt</th>
                    <th>Varianty</th>
                    <th>Skladem</th>
                    <th>Cena</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $gid => $g):
                $sum = schindler_group_summary($g);
                $already = isset($_SESSION['schindler_selected'][$gid]);
                $priceTxt = $sum['min_price'] === $sum['max_price']
                    ? number_format((float)$sum['min_price'], 0, ',', ' ') . ' Kč'
                    : number_format((float)$sum['min_price'], 0, ',', ' ') . '–' . number_format((float)$sum['max_price'], 0, ',', ' ') . ' Kč';
                $sizesTxt = !empty($sum['sizes']) ? implode(', ', array_unique($sum['sizes'])) : '';
            ?>
                <tr class="<?php echo $already ? 'already-selected' : ''; ?>" data-name="<?php echo h(mb_strtolower($g['name']));  ?>">
                    <td><input type="checkbox" name="gid[]" value="<?php echo h($gid); ?>" class="row-check" <?php echo $already ? 'checked disabled' : ''; ?>></td>
                    <td><img class="p-thumb" src="<?php echo h($g['image']); ?>" loading="lazy" alt=""></td>
                    <td>
                        <div class="p-name"><?php echo h($g['name']); ?></div>
                        <div class="p-sub"><?php echo h($g['category']); ?><?php if ($sizesTxt): ?> · vel.: <?php echo h($sizesTxt); ?><?php endif; ?><?php if ($already): ?> · <b style="color:#0a7a34;">už ve výběru</b><?php endif; ?></div>
                    </td>
                    <td><?php echo (int)$sum['variant_count']; ?></td>
                    <td>
                        <?php if ($sum['in_stock_count'] > 0): ?>
                            <span class="badge badge-stock"><?php echo (int)$sum['in_stock_count']; ?>/<?php echo (int)$sum['variant_count']; ?></span>
                        <?php else: ?>
                            <span class="badge badge-nostock">0/<?php echo (int)$sum['variant_count']; ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo h($priceTxt); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="sticky-bar">
            <span style="font-size:12.5px;color:var(--muted);">Zaškrtnuto na této stránce se přidá k celkovému výběru (nic se nepřepisuje).</span>
            <button type="submit" name="action" value="add_to_selection" class="btn">Přidat zaškrtnuté do výběru</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function schindlerToggleAll(cb) {
    document.querySelectorAll('.row-check:not(:disabled)').forEach(function (el) { el.checked = cb.checked; });
}
function schindlerConfirmGo() {
    var anyChecked = document.querySelectorAll('.row-check:checked').length > 0;
    if (!anyChecked) {
        return confirm('Nemáš zaškrtnutý žádný nový produkt na téhle stránce. Pokračovat k importu jen s tím, co už bylo přidáno dřív?');
    }
    return true;
}
function schindlerFilterRows() {
    var q = document.getElementById('filterBox').value.toLowerCase();
    document.querySelectorAll('#plistTable tbody tr').forEach(function (tr) {
        tr.style.display = tr.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
    });
}
function schindlerToggleSklad() {
    var url = new URL(window.location.href);
    var chk = document.getElementById('jenSkladChk').checked;
    if (chk) url.searchParams.set('jen_sklad', '1'); else url.searchParams.delete('jen_sklad');
    return url.toString();
}
</script>
</body>
</html>
