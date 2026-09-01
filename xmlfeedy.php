<?php
// xmlfeedy.php – správa XML feedů dodavatelů + export do CSV
// Verze bez CREATE TABLE (hosting nepovoluje CREATE).
// Pokud tabulka xml_feeds neexistuje, zobrazí se SQL, které vlož do phpMyAdmin ručně.

require_once __DIR__ . '/_auth_guard.php';

require __DIR__ . '/config.php'; // $pdo

$saveMessage   = '';
$existingFeeds = [];
$tableExists   = false;

$tableCreateSql = "CREATE TABLE `xml_feeds` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(255) NOT NULL,
  `feed_url` text NOT NULL,
  `item_tag` varchar(64) NOT NULL DEFAULT '',
  `ean_tag` varchar(64) NOT NULL DEFAULT '',
  `stock_tag` varchar(64) NOT NULL DEFAULT '',
  `qty_tag` varchar(64) NOT NULL DEFAULT '',
  `price_tag` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supplier` (`supplier_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($loggedIn) {
    // zjistíme, jestli tabulka existuje
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'xml_feeds'");
        if ($check && $check->rowCount() > 0) {
            $tableExists = true;
        }
    } catch (Throwable $e) {
        $tableExists = false;
    }

    if ($tableExists) {
        // SMAZÁNÍ FEEDU
        if (isset($_GET['delete_id']) && ctype_digit((string)$_GET['delete_id'])) {
            $delId  = (int)$_GET['delete_id'];
            $stmtDel = $pdo->prepare("DELETE FROM xml_feeds WHERE id = :id");
            $stmtDel->execute([':id' => $delId]);
            header('Location: xmlfeedy.php');
            exit;
        }

        // CSV EXPORT JEDNOHO FEEDU
        if (isset($_GET['csv']) && ctype_digit((string)$_GET['csv'])) {
            $csvId = (int)$_GET['csv'];

            $stmt = $pdo->prepare("SELECT supplier_name, feed_url FROM xml_feeds WHERE id = :id");
            $stmt->execute([':id' => $csvId]);
            $feed = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$feed) {
                header('Content-Type: text/plain; charset=utf-8');
                echo "Feed ID {$csvId} nebyl nalezen.";
                exit;
            }

            $url          = trim((string)$feed['feed_url']);
            $supplierName = $feed['supplier_name'];

            $xmlContent = @file_get_contents($url);
            if ($xmlContent === false) {
                header('Content-Type: text/plain; charset=utf-8');
                echo "Nepodařilo se stáhnout XML feed.";
                exit;
            }

            $xml = @simplexml_load_string($xmlContent);
            if ($xml === false) {
                header('Content-Type: text/plain; charset=utf-8');
                echo "XML feed má neplatný formát.";
                exit;
            }

            // najdi pravděpodobný tag položky – opakující se element
            $itemTag = null;
            foreach ($xml->children() as $child) {
                $name    = $child->getName();
                $matches = $xml->xpath('//' . $name);
                if (is_array($matches) && count($matches) > 1) {
                    $itemTag = $name;
                    break;
                }
            }
            if ($itemTag === null) {
                foreach ($xml->children() as $child) {
                    $itemTag = $child->getName();
                    break;
                }
            }
            if ($itemTag === null) {
                header('Content-Type: text/plain; charset=utf-8');
                echo "Nepodařilo se najít položky ve feedu.";
                exit;
            }

            $items = $xml->xpath('//' . $itemTag);
            if (!$items || count($items) === 0) {
                header('Content-Type: text/plain; charset=utf-8');
                echo "Ve feedu nejsou žádné položky.";
                exit;
            }

            // sloupce = všechny child tagy
            $columns = [];
            foreach ($items as $item) {
                foreach ($item->children() as $child) {
                    $field          = $child->getName();
                    $columns[$field] = true;
                }
            }
            $columns = array_keys($columns);

            // bezpečný název souboru
            $fileNameSafe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $supplierName);
            if ($fileNameSafe === '') {
                $fileNameSafe = 'feed_' . $csvId;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fileNameSafe . '.csv"');

            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['#', 'item_tag'], $columns));

            $i = 1;
            foreach ($items as $item) {
                $row = [$i++, $itemTag];
                foreach ($columns as $col) {
                    $val = isset($item->{$col}) ? (string)$item->{$col} : '';
                    $row[] = $val;
                }
                fputcsv($out, $row);
            }
            fclose($out);
            exit;
        }

        // ULOŽENÍ NOVÝCH FEEDŮ
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['feeds_submit'])) {
            $names = isset($_POST['supplier_name']) && is_array($_POST['supplier_name']) ? $_POST['supplier_name'] : [];
            $urls  = isset($_POST['feed_url'])    && is_array($_POST['feed_url'])    ? $_POST['feed_url']    : [];

            $inserted = 0;

            if (!empty($names) && !empty($urls)) {
                $stmtIns = $pdo->prepare("
                    INSERT INTO xml_feeds (supplier_name, feed_url)
                    VALUES (:name, :url)
                ");

                $count = max(count($names), count($urls));
                for ($i = 0; $i < $count; $i++) {
                    $name = isset($names[$i]) ? trim((string)$names[$i]) : '';
                    $url  = isset($urls[$i])  ? trim((string)$urls[$i])  : '';

                    if ($name === '' && $url === '') {
                        continue;
                    }
                    if ($url === '') {
                        continue;
                    }

                    $stmtIns->execute([
                        ':name' => $name,
                        ':url'  => $url,
                    ]);
                    $inserted++;
                }
            }

            if ($inserted > 0) {
                $saveMessage = "Uloženo {$inserted} feedů.";
            } else {
                $saveMessage = "Nebyl uložen žádný nový feed.";
            }
        }

        // NAČTENÍ EXISTUJÍCÍCH FEEDŮ
        $q = $pdo->query("SELECT id, supplier_name, feed_url, created_at FROM xml_feeds ORDER BY id DESC");
        $existingFeeds = $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <title>XML feed – Dodavatelé</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin:0; font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:#f5f5f5; }
        .page { max-width:960px; margin:32px auto; padding:0 16px; }
        .card { background:#fff; border-radius:16px; padding:24px 20px; box-shadow:0 10px 30px rgba(0,0,0,0.08); }
        h1 { margin-top:0; font-size:24px; }
        p { font-size:14px; color:#444; }
        .back-link { text-decoration:none; font-size:13px; color:#0b9150; display:inline-flex; align-items:center; margin-bottom:12px; }
        .back-link span { margin-left:4px; }
        .btn-full { width:100%; border:none; background:#0b9150; color:#fff; border-radius:999px; padding:10px 18px; cursor:pointer; font-size:14px; font-weight:600; }
        .btn-full:hover { background:#0aa145; }
        .btn-small { display:inline-block; border-radius:999px; padding:4px 10px; font-size:12px; border:none; cursor:pointer; text-decoration:none; margin-right:4px; }
        .btn-small-green { background:#0b9150; color:#fff; }
        .btn-small-red { background:#e53935; color:#fff; }
        .login-card { max-width:360px; margin:48px auto; }
        .login-field { margin-bottom:10px; }
        .login-field label { font-size:13px; display:block; margin-bottom:4px; }
        .login-field input { width:100%; padding:8px 10px; border-radius:999px; border:1px solid:#ccc; font-size:14px; }
        .login-error { color:#e53935; font-size:13px; margin-bottom:8px; }
        .feeds-container { margin-top:16px; border-radius:12px; border:1px solid:#e0e0e0; padding:12px; background:#fafafa; }
        .feed-row { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px; }
        .feed-row input[type="text"] { flex:1; min-width:0; padding:6px 10px; border-radius:999px; border:1px solid:#ddd; font-size:13px; }
        .feed-row input[name="supplier_name[]"] { max-width:220px; }
        .add-feed-btn { margin-top:8px; background:#e9f7f0; color:#0b9150; border:1px dashed:#0b9150; border-radius:999px; padding:6px 12px; font-size:13px; cursor:pointer; }
        .save-message { margin-bottom:12px; font-size:13px; color:#0b9150; }
        .feeds-table-wrap { margin-top:24px; }
        table.feeds-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.feeds-table th, table.feeds-table td { border-bottom:1px solid:#eee; padding:6px 4px; text-align:left; vertical-align:top; }
        table.feeds-table th { font-weight:600; color:#333; }
        table.feeds-table tr:hover td { background:#fafafa; }
        .logout-wrap { margin-top:24px; text-align:center; }
        .btn-logout { border:none; background:#111; color:#fff; border-radius:999px; padding:8px 18px; cursor:pointer; font-size:14px; }
        pre.sql-box { background:#111; color:#0f0; padding:12px; border-radius:8px; font-size:11px; overflow:auto; }
        @media (max-width:640px) {
            .feed-row { flex-direction:column; }
            .feed-row input[name="supplier_name[]"] { max-width:100%; }
        }
    </style>
</head>
<body>
<div class="page">
<?php if (!$loggedIn): ?>
    <div class="card login-card">
        <h1>Přihlášení</h1>
        <?php if ($loginError !== ''): ?>
            <div class="login-error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" action="xmlfeedy.php">
            <input type="hidden" name="action" value="login">
            <div class="login-field">
                <label for="username">Uživatel</label>
                <input type="text" id="username" name="username" autocomplete="username">
            </div>
            <div class="login-field">
                <label for="password">Heslo</label>
                <input type="password" id="password" name="password" autocomplete="current-password">
            </div>
            <button type="submit" class="btn-full">Přihlásit se</button>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <a href="index.php" class="back-link">← <span>Zpět na hlavní stránku</span></a>
        <h1>XML feed – Dodavatelé</h1>

        <?php if (!$tableExists): ?>
            <p>Tabulka <code>xml_feeds</code> zatím neexistuje a z aplikace ji nemůžeme vytvořit
               (hosting nepovoluje příkaz <code>CREATE TABLE</code> pro tohoto uživatele).</p>
            <p>Prosím otevři si phpMyAdmin a ve své databázi <strong>d388160_cfloat</strong> spusť následující SQL:</p>
            <pre class="sql-box"><?php echo htmlspecialchars($tableCreateSql, ENT_NOQUOTES, 'UTF-8'); ?></pre>
            <p>Poté stránku znovu načti (F5) – formulář pro správu XML feedů se zobrazí.</p>
        <?php else: ?>

            <p>Zde můžeš uložit XML feedy jednotlivých dodavatelů. U každého feedu je tlačítko CSV,
               které stáhne zjednodušený náhled feedu jako CSV soubor.</p>

            <?php if ($saveMessage !== ''): ?>
                <div class="save-message"><?php echo htmlspecialchars($saveMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" action="xmlfeedy.php">
                <div class="feeds-container" id="feeds-container">
                    <div class="feed-row">
                        <input type="text" name="supplier_name[]" placeholder="Název dodavatele (např. Silvini)">
                        <input type="text" name="feed_url[]" placeholder="Odkaz na XML feed (https://... nebo ftp://...)">
                    </div>
                </div>
                <button type="button" class="add-feed-btn" id="btn-add-feed">+ Přidat další feed</button>

                <div style="margin-top:12px;">
                    <button type="submit" name="feeds_submit" value="1" class="btn-full">Uložit feedy</button>
                </div>
            </form>

            <div class="feeds-table-wrap">
                <h2>Uložené feedy</h2>

                <?php if (empty($existingFeeds)): ?>
                    <p style="font-size:13px; color:#666;">Zatím nejsou uložené žádné feedy.</p>
                <?php else: ?>
                    <table class="feeds-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Název dodavatele</th>
                                <th>Odkaz na XML feed</th>
                                <th>Vytvořeno</th>
                                <th>Akce</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($existingFeeds as $f): ?>
                                <tr>
                                    <td><?php echo (int)$f['id']; ?></td>
                                    <td><?php echo htmlspecialchars($f['supplier_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <a href="<?php echo htmlspecialchars($f['feed_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                            <?php echo htmlspecialchars($f['feed_url'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($f['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <a class="btn-small btn-small-green" href="xmlfeedy.php?csv=<?php echo (int)$f['id']; ?>">CSV</a>
                                        <a class="btn-small btn-small-red"
                                           href="xmlfeedy.php?delete_id=<?php echo (int)$f['id']; ?>"
                                           onclick="return confirm('Opravdu smazat tento feed?');">Smazat</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

        <?php endif; ?>

        <div class="logout-wrap">
            <form method="get" action="xmlfeedy.php">
                <input type="hidden" name="logout" value="1">
                <button type="submit" class="btn-logout">Odhlásit se</button>
            </form>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
(function() {
    var container = document.getElementById('feeds-container');
    var addBtn = document.getElementById('btn-add-feed');
    if (!container || !addBtn) return;

    addBtn.addEventListener('click', function() {
        var row = document.createElement('div');
        row.className = 'feed-row';
        row.innerHTML =
            '<input type="text" name="supplier_name[]" placeholder="Název dodavatele (např. Silvini)">' +
            '<input type="text" name="feed_url[]" placeholder="Odkaz na XML feed (https://... nebo ftp://...)">';
        container.appendChild(row);
    });
})();
</script>
</body>
</html>
