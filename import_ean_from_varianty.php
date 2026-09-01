<?php
// import_ean_from_varianty.php
// Automatický import EAN do tabulky `order_items` ze všech CSV v CStore/Varianty.
// Každý request zpracuje 1 soubor a pak se automaticky přesměruje na další,
// aby to nespadlo na timeoutu.

require __DIR__ . '/config.php'; // $pdo – připojení k d388160_cfloat

@set_time_limit(0);

$variantDir = __DIR__ . '/CStore/Varianty';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (!is_dir($variantDir)) {
    die("Složka s variantami neexistuje: " . h($variantDir));
}

// Seznam všech CSV souborů
$files = glob($variantDir . '/*.csv');
sort($files); // pro konzistentní pořadí

if (!$files) {
    die("Nenalezeny žádné CSV soubory v: " . h($variantDir));
}

// index právě zpracovávaného souboru
$index = isset($_GET['i']) ? (int)$_GET['i'] : 0;
$count = count($files);

if ($index >= $count) {
    echo "<h1>Import EAN dokončen</h1>";
    echo "Zpracováno souborů: " . h($count) . "<br>";
    exit;
}

$filePath = $files[$index];
$fileName = basename($filePath);

echo "<h1>Import EAN z CStore/Varianty</h1>";
echo "Soubor " . h($index + 1) . " z " . h($count) . ": " . h($fileName) . "<br><br>";

// Připravený UPDATE na order_items
$updateStmt = $pdo->prepare("
    UPDATE order_items
       SET ean = :ean
     WHERE product_id = :product_id
       AND variant_id = :variant_id
");

$handle = fopen($filePath, 'r');
if (!$handle) {
    die("Nelze otevřít soubor: " . h($filePath));
}

// Pokud máš v CSV čárky, změň na ','
$delimiter = ';';

$totalRows    = 0;
$totalUpdated = 0;
$totalSkipped = 0;
$totalNoMatch = 0;
$totalNoEan   = 0;

$rowIndex = 0;

while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    $rowIndex++;
    $totalRows++;

    // potřebujeme aspoň 17 sloupců (A..Q)
    if (count($row) < 17) {
        $totalSkipped++;
        continue;
    }

    $combined = trim((string)$row[0]);   // A = productId_variantId
    $ean      = trim((string)$row[16]);  // Q = EAN

    if ($combined === '') {
        $totalSkipped++;
        continue;
    }

    // řádek 1 může být hlavička
    if ($rowIndex === 1 && !preg_match('~^\d+_\d+$~', $combined)) {
        $totalSkipped++;
        continue;
    }

    if ($ean === '') {
        $totalNoEan++;
        continue;
    }

    $parts = explode('_', $combined, 2);
    if (count($parts) !== 2) {
        $totalSkipped++;
        continue;
    }

    $productId = (int)trim($parts[0]);
    $variantId = (int)trim($parts[1]);

    if ($productId <= 0) {
        $totalSkipped++;
        continue;
    }

    $updateStmt->execute([
        ':ean'        => $ean,
        ':product_id' => $productId,
        ':variant_id' => $variantId,
    ]);

    $rowCount = $updateStmt->rowCount();
    if ($rowCount > 0) {
        $totalUpdated += $rowCount;
    } else {
        $totalNoMatch++;
    }
}

fclose($handle);

echo "Soubor: " . h($fileName) . "<br>";
echo "Načtených řádků: " . h($totalRows) . "<br>";
echo "Aktualizovaných order_items.ean: " . h($totalUpdated) . "<br>";
echo "Přeskočené řádky (chybný formát / hlavička / bez klíče): " . h($totalSkipped) . "<br>";
echo "Řádky bez EANu: " . h($totalNoEan) . "<br>";
echo "Řádky bez shody v order_items (product_id + variant_id): " . h($totalNoMatch) . "<br><br>";

$next = $index + 1;

if ($next < $count) {
    $nextUrl = h($_SERVER['PHP_SELF']) . '?i=' . $next;
    echo "Pokračuji na další soubor (" . h($next + 1) . " z " . h($count) . ")…<br>";
    echo "<a href=\"$nextUrl\">Pokud se stránka nepřesměruje, klikni sem.</a>";

    // automatické přesměrování za 2 sekundy
    echo '<meta http-equiv="refresh" content="2;url=' . $nextUrl . '">';
} else {
    echo "<strong>Všechny soubory zpracovány.</strong>";
}
