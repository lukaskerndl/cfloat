<?php
// import_ean_to_map.php
// Naplní tabulku ean_map (product_id + variant_id -> EAN) ze všech CSV ve složce CStore/Varianty.

require __DIR__ . '/config.php'; // $pdo – připojení k d388160_cfloat

@set_time_limit(0);

$variantDir = __DIR__ . '/CStore/Varianty';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Najdeme všechny CSV soubory
$files = glob($variantDir . '/*.csv');
if (!$files) {
    echo 'Ve složce ' . h($variantDir) . ' nebyly nalezeny žádné CSV soubory.';
    exit;
}

// Index souboru z parametru ?i=
$index = isset($_GET['i']) ? max(0, (int)$_GET['i']) : 0;
$count = count($files);

if ($index >= $count) {
    echo '<strong>Všechny soubory zpracovány.</strong>';
    exit;
}

$file = $files[$index];
echo '<h3>Soubor ' . h(basename($file)) . ' (' . ($index+1) . ' / ' . $count . ')</h3>';

if (!is_readable($file)) {
    echo 'Soubor nelze číst: ' . h($file);
    exit;
}

$fh = fopen($file, 'r');
if (!$fh) {
    echo 'Nepodařilo se otevřít soubor: ' . h($file);
    exit;
}

$rowIndex       = 0;
$totalRows      = 0;
$totalInserted  = 0;
$totalUpdated   = 0;
$totalNoMatchPN = 0;

while (($row = fgetcsv($fh, 0, ';')) !== false) {
    $rowIndex++;
    $totalRows++;

    // očekáváme: A = productId_variantId, Q = EAN (index 16)
    if (count($row) < 17) {
        continue;
    }

    $combined = trim((string)$row[0]);   // A = productId_variantId
    $ean      = trim((string)$row[16]);  // Q = EAN

    // přeskočíme prázdné řádky
    if ($combined === '') {
        continue;
    }

    // první datový řádek – pokud nevypadá jako "123_456", považujeme ho za hlavičku
    if ($rowIndex === 1 && !preg_match('~^\d+_\d+$~', $combined)) {
        echo 'První řádek vypadá jako hlavička, přeskočeno.<br>';
        continue;
    }

    // musí mít tvar "productId_variantId"
    if (!preg_match('~^(\d+)_(\d+)$~', $combined, $m)) {
        continue;
    }

    $productId = (int)$m[1];
    $variantId = (int)$m[2];

    if ($ean === '') {
        // nic k zapsání
        continue;
    }

    // Zkusíme zjistit product_number z order_items pro lepší přehled
    $productNumber = '';
    try {
        $stmtPN = $pdo->prepare('SELECT product_number FROM order_items WHERE product_id = :pid AND variant_id = :vid LIMIT 1');
        $stmtPN->execute([
            ':pid' => $productId,
            ':vid' => $variantId,
        ]);
        $pnRow = $stmtPN->fetch(PDO::FETCH_ASSOC);
        if ($pnRow && isset($pnRow['product_number'])) {
            $productNumber = (string)$pnRow['product_number'];
        } else {
            $totalNoMatchPN++;
        }
    } catch (Throwable $e) {
        // Pokud to spadne, jen přeskočíme doplnění product_number, EAN ale zapíšeme
        $totalNoMatchPN++;
    }

    // INSERT / UPDATE do ean_map
    try {
        $sql = 'INSERT INTO ean_map (product_id, variant_id, product_number, ean)
                VALUES (:pid, :vid, :pnum, :ean)
                ON DUPLICATE KEY UPDATE
                    product_number = VALUES(product_number),
                    ean            = VALUES(ean)';
        $stmtIns = $pdo->prepare($sql);
        $stmtIns->execute([
            ':pid'  => $productId,
            ':vid'  => $variantId,
            ':pnum' => $productNumber,
            ':ean'  => $ean,
        ]);

        if ($stmtIns->rowCount() === 1) {
            // nový záznam
            $totalInserted++;
        } else {
            // update existujícího záznamu
            $totalUpdated++;
        }
    } catch (Throwable $e) {
        echo 'Chyba při zápisu do ean_map pro ' . h($combined) . ': ' . h($e->getMessage()) . '<br>';
    }
}

fclose($fh);

echo 'Řádků v CSV: ' . h($totalRows) . '<br>';
echo 'Nově vložených do ean_map: ' . h($totalInserted) . '<br>';
echo 'Aktualizovaných v ean_map: ' . h($totalUpdated) . '<br>';
echo 'Kde se nepodařilo dohledat product_number v order_items: ' . h($totalNoMatchPN) . '<br><br>';

$next = $index + 1;
if ($next < $count) {
    $nextUrl = h($_SERVER['PHP_SELF']) . '?i=' . $next;
    echo 'Pokračuji na další soubor (' . ($next + 1) . ' z ' . $count . ')…<br>';
    echo '<a href="' . $nextUrl . '">Pokud se stránka nepřesměruje, klikni sem.</a>';
    echo '<meta http-equiv="refresh" content="2;url=' . $nextUrl . '">';
} else {
    echo '<strong>Všechny soubory zpracovány.</strong>';
}
