<?php
declare(strict_types=1);

/**
 * cfloat-new/nove-objednavky-backfill.php
 *
 * Znovu projede objednávky od 1.8.2026 a přepočítá je přes AKTUÁLNÍ verzi
 * eshop_new_persist_order() - doplní chybějící pole (e-mail, telefon, číslo
 * objednávky, faktura, doprava) a opraví položky (přesná velikost/EAN/sklad
 * podle idProductItem, ne jen odhad). Nic nemaže, jen přepisuje/doplňuje.
 *
 * Běží po dávkách a stránka se sama obnovuje - nechte ji otevřenou.
 */

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

@set_time_limit(60);

$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$pdo = null;
foreach ($cfgCandidates as $p) {
    if (is_file($p)) { require $p; break; }
}
if (!isset($pdo) || !($pdo instanceof PDO)) die('Chybí config.php nebo $pdo.');

require_once __DIR__ . '/lib/eshop_new_helpers.php';

$secretsPath = __DIR__ . '/../secrets/eshop_new_api.php';
if (!is_file($secretsPath)) die('Chybí secrets/eshop_new_api.php.');
$cfg = include $secretsPath;
$baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
$token   = trim((string)($cfg['token'] ?? ''));
if ($token === '') die('Token je prázdný.');

const BACKFILL_FROM = '2026-08-01T00:00:00+02:00';
const ORDERS_PER_RUN = 8;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS eshop_new_backfill_state (
        id INT NOT NULL PRIMARY KEY,
        page INT NOT NULL DEFAULT 1,
        index_in_page INT NOT NULL DEFAULT 0,
        processed INT NOT NULL DEFAULT 0,
        total_items INT NULL,
        done TINYINT(1) NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO eshop_new_backfill_state (id, page, index_in_page, processed, done) VALUES (1, 1, 0, 0, 0)");
} catch (Throwable $e) {
    // tabulka pravděpodobně existuje - v pořádku
}

$state = $pdo->query("SELECT * FROM eshop_new_backfill_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$log = [];

if ((int)$state['done'] === 0) {
    $page = (int)$state['page'];
    $skipInPage = (int)$state['index_in_page'];
    $processedThisRun = 0;

    while ($processedThisRun < ORDERS_PER_RUN) {
        $path = '/api-engine/orders?created[after]=' . rawurlencode(BACKFILL_FROM) . '&page=' . $page . '&itemsPerPage=30';
        $res = eshop_new_api_call($baseUrl, $token, $path);

        if (!$res['ok']) {
            $pdo->prepare("UPDATE eshop_new_backfill_state SET last_error = :e, updated_at = NOW() WHERE id = 1")
                ->execute([':e' => 'HTTP ' . $res['http'] . ' na stránce ' . $page]);
            $log[] = "Stránka {$page}: chyba HTTP {$res['http']}";
            break;
        }

        $totalItems = (int)($res['body']['hydra:totalItems'] ?? 0);
        $members = $res['body']['hydra:member'] ?? [];

        if (empty($members)) {
            $pdo->prepare("UPDATE eshop_new_backfill_state SET done = 1, total_items = :t, updated_at = NOW() WHERE id = 1")
                ->execute([':t' => $totalItems]);
            $log[] = "Stránka {$page}: prázdná - dokončeno.";
            $state['done'] = 1;
            break;
        }

        for ($i = $skipInPage; $i < count($members); $i++) {
            if ($processedThisRun >= ORDERS_PER_RUN) break;
            eshop_new_persist_order($pdo, $baseUrl, $token, $members[$i]);
            $processedThisRun++;
            $skipInPage = $i + 1;
        }

        $pdo->prepare("UPDATE eshop_new_backfill_state SET page = :p, index_in_page = :ix, total_items = :t, processed = processed + :c, updated_at = NOW() WHERE id = 1")
            ->execute([':p' => $page, ':ix' => $skipInPage, ':t' => $totalItems, ':c' => $processedThisRun]);

        $log[] = "Stránka {$page}, pozice {$skipInPage}/" . count($members) . ": zpracováno {$processedThisRun} v tomto běhu.";

        if ($skipInPage >= count($members)) {
            $page++;
            $skipInPage = 0;
            $pdo->prepare("UPDATE eshop_new_backfill_state SET page = :p, index_in_page = 0, updated_at = NOW() WHERE id = 1")->execute([':p' => $page]);
        }

        if ($processedThisRun >= ORDERS_PER_RUN) break;
    }
}

$state = $pdo->query("SELECT * FROM eshop_new_backfill_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$isDone = (bool)$state['done'];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Doplnění dat objednávek od 1.8.2026</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!$isDone): ?><meta http-equiv="refresh" content="1"><?php endif; ?>
<style>
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; }
.wrap { max-width:800px; margin:0 auto; }
.card { background:#fff; border:1px solid #e7e9ec; border-radius:14px; padding:18px; margin-bottom:14px; }
.bar-bg { background:#eee; border-radius:999px; height:18px; overflow:hidden; }
.bar-fill { background:linear-gradient(135deg,#24d84a,#00b52a); height:100%; transition:width .3s; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid #e7e9ec; border-radius:999px; padding:6px 14px; }
pre { background:#f7f8f9; padding:10px; border-radius:10px; overflow:auto; font-size:11.5px; max-height:200px; }
.done-badge { color:#0a7a34; font-weight:700; font-size:18px; }
</style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="nove-objednavky.php">&larr; Zpět na Nové objednávky</a>
    <h1>Doplnění dat objednávek od 1.8.2026</h1>
    <div class="card">
        <?php if ($isDone): ?>
            <p class="done-badge">✓ Hotovo!</p>
            <p>Zpracováno celkem <b><?php echo (int)$state['processed']; ?></b> objednávek.</p>
        <?php else: ?>
            <p>Zpracovávám... stránka se sama obnovuje, nechte ji prosím otevřenou.</p>
            <?php $total = (int)($state['total_items'] ?? 0); $done = (int)$state['processed'];
                  $pct = $total > 0 ? min(100, round($done / $total * 100)) : 0; ?>
            <div class="bar-bg"><div class="bar-fill" style="width:<?php echo $pct; ?>%;"></div></div>
            <p style="font-size:13px;color:#666;">Zpracováno <?php echo $done; ?> z odhadovaných <?php echo $total ?: '?'; ?> (<?php echo $pct; ?>%).</p>
        <?php endif; ?>
        <?php if (!empty($state['last_error'])): ?><p style="color:#d93025;"><b>Poslední chyba:</b> <?php echo h($state['last_error']); ?></p><?php endif; ?>
    </div>
    <?php if (!empty($log)): ?><div class="card"><h2 style="font-size:14px;">Log tohoto běhu</h2><pre><?php echo h(implode("\n", $log)); ?></pre></div><?php endif; ?>
</div>
</body>
</html>
