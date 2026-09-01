<?php
declare(strict_types=1);

/**
 * cfloat-new/nove-objednavky-import-historie.php
 *
 * Jednorázový import historie objednávek (od 1.1.2025) z NOVÉHO Eshop-rychle API
 * do SAMOSTATNÉ tabulky `eshop_new_orders` - nijak se nedotýká tabulky `orders`
 * ani sync_orders_live.php / objednavky.php.
 *
 * Funguje po dávkách (kvůli časovému limitu sdíleného hostingu) a stránka se
 * sama obnovuje, dokud není hotovo - stačí ji nechat otevřenou.
 */

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$pdo = null;
foreach ($cfgCandidates as $p) {
    if (is_file($p)) { require $p; break; }
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Chybí config.php nebo $pdo.');
}

$secretsPath = __DIR__ . '/../secrets/eshop_new_api.php';
if (!is_file($secretsPath)) {
    die('Chybí secrets/eshop_new_api.php.');
}
$cfg = include $secretsPath;
$baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
$token   = trim((string)($cfg['token'] ?? ''));
if ($token === '') {
    die('Token v secrets/eshop_new_api.php je prázdný.');
}

// Od kdy importujeme - pevně dané dle zadání (1.1.2025)
const IMPORT_FROM = '2025-01-01T00:00:00+01:00';

// Kolik stránek zpracovat v jednom běhu (šetří časový limit hostingu)
const PAGES_PER_RUN = 3;

// -------------------------------------------------------------------------
// Tabulky (vytvoří se samy při prvním spuštění, nezávisle na stávajících)
// -------------------------------------------------------------------------
function db_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}

// Na sdíleném hostingu databázový účet obvykle nemá právo CREATE TABLE.
// Pokusíme se tabulky založit, ale pokud to selže KVŮLI PRÁVŮM a tabulky
// přitom neexistují, dáme jasnou instrukci místo fatal error.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eshop_new_orders (
            order_iri        VARCHAR(255) NOT NULL PRIMARY KEY,
            created          DATETIME NULL,
            order_state_iri  VARCHAR(255) NULL,
            order_state_name VARCHAR(190) NULL,
            raw_json         LONGTEXT NULL,
            imported_at      DATETIME NOT NULL,
            updated_at       DATETIME NOT NULL,
            INDEX (created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eshop_new_import_state (
            id INT NOT NULL PRIMARY KEY,
            next_page INT NOT NULL DEFAULT 1,
            total_items INT NULL,
            imported_count INT NOT NULL DEFAULT 0,
            done TINYINT(1) NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            started_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    if (!db_table_exists($pdo, 'eshop_new_orders') || !db_table_exists($pdo, 'eshop_new_import_state')) {
        die('Databázový účet nemá právo vytvářet tabulky (CREATE TABLE). '
          . 'Vytvoř je prosím jednorázově ručně přes phpMyAdmin – SQL k tomu najdeš '
          . 'v souboru vytvor_tabulky_nove_objednavky.sql, který jsi dostal. '
          . 'Chyba: ' . h($e->getMessage()));
    }
    // Tabulky už existují (např. založené ručně) - v pořádku, jedeme dál.
}

$pdo->exec("INSERT IGNORE INTO eshop_new_import_state (id, next_page, imported_count, done) VALUES (1, 1, 0, 0)");

// -------------------------------------------------------------------------
// API volání
// -------------------------------------------------------------------------
function eshop_new_api_call(string $baseUrl, string $token, string $path): array
{
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Accept: application/ld+json'],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'http' => $http, 'body' => null, 'error' => 'cURL: ' . $err];
    }
    $decoded = json_decode($response, true);
    $ok = $http >= 200 && $http < 300;
    return ['ok' => $ok, 'http' => $http, 'body' => $decoded, 'error' => $ok ? '' : ('HTTP ' . $http)];
}

// -------------------------------------------------------------------------
// Zpracování jednoho běhu (max PAGES_PER_RUN stránek)
// -------------------------------------------------------------------------
$state = $pdo->query("SELECT * FROM eshop_new_import_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$log = [];

if ((int)$state['done'] === 0) {
    if ($state['started_at'] === null) {
        $pdo->exec("UPDATE eshop_new_import_state SET started_at = NOW() WHERE id = 1");
    }

    $upsert = $pdo->prepare("
        INSERT INTO eshop_new_orders (order_iri, created, order_state_iri, order_state_name, raw_json, imported_at, updated_at)
        VALUES (:iri, :created, :state_iri, :state_name, :raw, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            created = VALUES(created),
            order_state_iri = VALUES(order_state_iri),
            order_state_name = VALUES(order_state_name),
            raw_json = VALUES(raw_json),
            updated_at = NOW()
    ");

    $page = (int)$state['next_page'];
    $importedThisRun = 0;

    for ($i = 0; $i < PAGES_PER_RUN; $i++) {
        $path = '/api-engine/orders?created[after]=' . rawurlencode(IMPORT_FROM) . '&page=' . $page;
        $res = eshop_new_api_call($baseUrl, $token, $path);

        if (!$res['ok']) {
            $pdo->prepare("UPDATE eshop_new_import_state SET last_error = :e, updated_at = NOW() WHERE id = 1")
                ->execute([':e' => substr((string)$res['error'], 0, 500)]);
            $log[] = "Stránka $page: CHYBA - " . $res['error'];
            break;
        }

        $body = $res['body'];
        $totalItems = (int)($body['hydra:totalItems'] ?? 0);
        $members = $body['hydra:member'] ?? [];

        if (empty($members)) {
            // Žádné další položky - hotovo
            $pdo->prepare("UPDATE eshop_new_import_state SET done = 1, total_items = :t, updated_at = NOW() WHERE id = 1")
                ->execute([':t' => $totalItems]);
            $log[] = "Stránka $page: prázdná - import dokončen.";
            $state['done'] = 1;
            break;
        }

        foreach ($members as $order) {
            $iri = (string)($order['@id'] ?? '');
            if ($iri === '') continue;

            $created = null;
            if (!empty($order['created'])) {
                $ts = strtotime((string)$order['created']);
                if ($ts !== false) $created = date('Y-m-d H:i:s', $ts);
            }

            $stateIri = $order['orderState']['@id'] ?? null;
            $stateName = $order['orderState']['name'] ?? null;

            $upsert->execute([
                ':iri' => $iri,
                ':created' => $created,
                ':state_iri' => $stateIri,
                ':state_name' => $stateName,
                ':raw' => json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $importedThisRun++;
        }

        $log[] = "Stránka $page: " . count($members) . " objednávek uloženo.";
        $page++;

        $pdo->prepare("UPDATE eshop_new_import_state SET next_page = :p, total_items = :t, imported_count = imported_count + :c, updated_at = NOW() WHERE id = 1")
            ->execute([':p' => $page, ':t' => $totalItems, ':c' => count($members)]);
    }
}

$state = $pdo->query("SELECT * FROM eshop_new_import_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$isDone = (bool)$state['done'];
$countInDb = (int)$pdo->query("SELECT COUNT(*) FROM eshop_new_orders")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Import historie objednávek</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!$isDone): ?>
<meta http-equiv="refresh" content="2">
<?php endif; ?>
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
    <a class="back-link" href="index.php">&larr; Zpět na Nový Cfloat</a>
    <h1>Import historie objednávek (od 1.1.2025)</h1>

    <div class="card">
        <?php if ($isDone): ?>
            <p class="done-badge">✓ Hotovo!</p>
            <p>V tabulce <code>eshop_new_orders</code> je celkem <b><?php echo $countInDb; ?></b> objednávek.</p>
        <?php else: ?>
            <p>Stahuji... stránka se sama obnovuje, nechte ji prosím otevřenou.</p>
            <?php
                $total = (int)($state['total_items'] ?? 0);
                $imported = (int)($state['imported_count'] ?? 0);
                $pct = $total > 0 ? min(100, round($imported / $total * 100)) : 0;
            ?>
            <div class="bar-bg"><div class="bar-fill" style="width:<?php echo $pct; ?>%;"></div></div>
            <p style="font-size:13px;color:#666;">Staženo <?php echo $imported; ?> z odhadovaných <?php echo $total ?: '?'; ?> (<?php echo $pct; ?>%). V databázi zatím: <?php echo $countInDb; ?>.</p>
        <?php endif; ?>

        <?php if (!empty($state['last_error'])): ?>
            <p style="color:#d93025;"><b>Poslední chyba:</b> <?php echo h($state['last_error']); ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($log)): ?>
        <div class="card">
            <h2 style="font-size:14px;">Log tohoto běhu</h2>
            <pre><?php echo h(implode("\n", $log)); ?></pre>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
