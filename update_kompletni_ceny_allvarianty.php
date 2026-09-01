<?php
/**
 * update_kompletni_ceny_allvarianty.php
 *
 * Načte CSV soubor:
 *   CStore/Varianty/AllVarianty.csv
 *
 * a podle značky + EAN doplní / nastaví sloupec price_s_dph
 * v tabulce:
 *   Kompletni_DatabazeVariantyEANProdejeCeny
 *
 * PRAVIDLA:
 *  - pracujeme jen s řádky, kde ve sloupci C (index 2) je text
 *      obsahující 'D1913' NEBO 'Didriksons' (case-insensitive).
 *
 *  - EAN bereme ze sloupce Q (index 16, 0-based).
 *
 *  - CENU počítáme:
 *      * pokud sloupec E (index 4, "Cena před slevou") > 0:
 *            price_s_dph = E / 2
 *      * jinak (E == 0):
 *            price_s_dph = F / 2
 *
 *  - EXISTUJÍCÍ CENY NEPŘEPISUJEME:
 *        UPDATE proběhne jen tam, kde je price_s_dph NULL nebo 0.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/config.php'; // očekává PDO v proměnné $pdo

$csvPath = __DIR__ . '/CStore/Varianty/AllVarianty.csv';

echo "START\n";
echo "CSV soubor: {$csvPath}\n";

if (!is_file($csvPath)) {
    echo "CHYBA: Soubor AllVarianty.csv neexistuje.\n";
    exit;
}

$h = fopen($csvPath, 'r');
if ($h === false) {
    echo "CHYBA: Nelze otevřít AllVarianty.csv pro čtení.\n";
    exit;
}

$updated        = 0;
$skippedNoEan   = 0;
$skippedNoBrand = 0;
$skippedNoPrice = 0;

$rowIndex = 0;

// Předpokládáme, že CSV je v oddělovači ';'
while (($row = fgetcsv($h, 0, ';')) !== false) {
    $rowIndex++;

    // Přeskočíme hlavičku (první řádek)
    if ($rowIndex === 1) {
        continue;
    }

    // Ověření indexů – kdyby byl řádek kratší, raději přeskočíme.
    if (!isset($row[2]) || !isset($row[4]) || !isset($row[5]) || !isset($row[16])) {
        continue;
    }

    $colC = trim((string)$row[2]);  // značka / popis
    $colE = trim((string)$row[4]);  // Cena před slevou
    $colF = trim((string)$row[5]);  // nějaká další cena
    $colQ = trim((string)$row[16]); // EAN

    if ($colQ === '') {
        $skippedNoEan++;
        continue;
    }

    // filtr značky: text ve sloupci C obsahuje 'D1913' nebo 'Didriksons'
    $cLower = mb_strtolower($colC, 'UTF-8');
    if (mb_strpos($cLower, 'd1913') === false && mb_strpos($cLower, 'didriksons') === false) {
        $skippedNoBrand++;
        continue;
    }

    // cena
    $eFloat = (float)str_replace(',', '.', $colE);
    $fFloat = (float)str_replace(',', '.', $colF);

    if ($eFloat > 0) {
        $priceSDph = $eFloat / 2.0;
    } elseif ($fFloat > 0) {
        $priceSDph = $fFloat / 2.0;
    } else {
        $skippedNoPrice++;
        continue;
    }

    // UPDATE pouze pokud v DB zatím není cena (NULL/0)
    $sql = "UPDATE Kompletni_DatabazeVariantyEANProdejeCeny
            SET price_s_dph = :price
            WHERE ean = :ean
              AND (price_s_dph IS NULL OR price_s_dph = 0)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':price' => $priceSDph,
        ':ean'   => $colQ,
    ]);

    if ($stmt->rowCount() > 0) {
        $updated++;
    }
}

fclose($h);

echo "HOTOVO\n";
echo "Aktualizováno záznamů v DB: {$updated}\n";
echo "Přeskočeno (bez EAN): {$skippedNoEan}\n";
echo "Přeskočeno (jiná značka než D1913/Didriksons): {$skippedNoBrand}\n";
echo "Přeskočeno (bez použitelné ceny E/F): {$skippedNoPrice}\n";
?>
