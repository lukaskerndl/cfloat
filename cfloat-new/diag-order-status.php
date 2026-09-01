<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$pdo = null;
foreach ($cfgCandidates as $p) {
    if (is_file($p)) { require $p; break; }
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Chybí config.php nebo $pdo.');
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$orderNumber = isset($_GET['number']) ? trim((string)$_GET['number']) : '';
$row = null;
$error = '';

if ($orderNumber !== '') {
    try {
        $stmt = $pdo->prepare("SELECT id_order, number, id_order_state, idOrderState, raw_json FROM orders WHERE number = :n LIMIT 1");
        $stmt->execute([':n' => $orderNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) $error = 'Objednávka s tímto číslem nebyla nalezena.';
    } catch (Throwable $e) {
        $error = 'Chyba: ' . $e->getMessage();
    }
} else {
    // Bez zadání ukážeme poslední synchronizovanou objednávku
    try {
        $stmt = $pdo->query("SELECT id_order, number, id_order_state, idOrderState, raw_json FROM orders ORDER BY id_order DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = 'Chyba: ' . $e->getMessage();
    }
}

$prettyJson = '';
$stateRelatedLines = [];
if ($row && !empty($row['raw_json'])) {
    $decoded = json_decode($row['raw_json'], true);
    if (is_array($decoded)) {
        $prettyJson = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        // najdeme všechny klíče, které vypadají, že souvisí se stavem
        $flat = [];
        $walk = function($arr, $prefix) use (&$walk, &$flat) {
            foreach ($arr as $k => $v) {
                $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
                if (is_array($v)) {
                    $walk($v, $key);
                } else {
                    $flat[$key] = $v;
                }
            }
        };
        $walk($decoded, '');
        foreach ($flat as $k => $v) {
            if (stripos($k, 'state') !== false || stripos($k, 'stav') !== false || stripos($k, 'status') !== false) {
                $stateRelatedLines[$k] = $v;
            }
        }
    } else {
        $prettyJson = '(raw_json se nepodařilo dekódovat jako JSON)';
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Diagnostika – stav objednávky</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; }
.wrap { max-width:900px; margin:0 auto; }
.card { background:#fff; border:1px solid #e7e9ec; border-radius:14px; padding:18px; margin-bottom:16px; }
input[type=text] { padding:9px 12px; border:1px solid #ccc; border-radius:8px; font-size:14px; width:260px; }
button { padding:9px 16px; border:none; border-radius:8px; background:#00b52a; color:#fff; font-weight:700; cursor:pointer; }
pre { background:#f7f8f9; padding:12px; border-radius:10px; overflow:auto; font-size:12px; max-height:500px; }
table { border-collapse:collapse; width:100%; font-size:13px; }
td, th { border:1px solid #e7e9ec; padding:6px 10px; text-align:left; }
.hit { background:#fff8e6; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid #e7e9ec; border-radius:999px; padding:6px 14px; }
</style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="index.php">&larr; Zpět na Nový Cfloat</a>
    <h1>Diagnostika – stav objednávky</h1>

    <div class="card">
        <form method="get">
            <label>Číslo objednávky (nepovinné, jinak poslední):</label><br><br>
            <input type="text" name="number" value="<?php echo h($orderNumber); ?>" placeholder="např. 1119107606">
            <button type="submit">Zobrazit</button>
        </form>
    </div>

    <?php if ($error !== ''): ?>
        <div class="card" style="color:#c00;"><?php echo h($error); ?></div>
    <?php elseif ($row): ?>
        <div class="card">
            <p><b>Objednávka:</b> <?php echo h($row['number']); ?> (id_order <?php echo h($row['id_order']); ?>)</p>
            <p><b>id_order_state (uložený sloupec):</b> <?php echo h($row['id_order_state'] ?? '(prázdné)'); ?></p>
            <p><b>idOrderState (uložený sloupec):</b> <?php echo h($row['idOrderState'] ?? '(prázdné)'); ?></p>
        </div>

        <?php if (!empty($stateRelatedLines)): ?>
            <div class="card">
                <h2>Klíče v raw_json obsahující "state"/"stav"/"status"</h2>
                <table>
                    <thead><tr><th>Klíč (cesta v JSON)</th><th>Hodnota</th></tr></thead>
                    <tbody>
                    <?php foreach ($stateRelatedLines as $k => $v): ?>
                        <tr class="hit"><td><?php echo h($k); ?></td><td><?php echo h(is_array($v) ? json_encode($v) : (string)$v); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="card">Žádný klíč obsahující "state"/"stav"/"status" v JSON nenalezen.</div>
        <?php endif; ?>

        <div class="card">
            <h2>Celý raw_json (formátovaně)</h2>
            <pre><?php echo h($prettyJson); ?></pre>
        </div>
    <?php else: ?>
        <div class="card">Žádná objednávka v databázi.</div>
    <?php endif; ?>
</div>
</body>
</html>
