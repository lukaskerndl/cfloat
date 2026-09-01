<?php

// Spuštění jen s platným tokenem nebo z cronu (dřív bez jakékoliv ochrany).
require_once __DIR__ . '/_cron_guard.php';

// update_kompletni_ceny_all_zdroje.php
//
// Doplní price_s_dph do tabulky Kompletni_DatabazeVariantyEANProdejeCeny
// ze tří zdrojů:
//   1) DB tabulka vavrys_variants (EAN -> price * 0.97 * 1.21)
//   2) Silvini XML feed (REPORTS_SALESPRICE_CZK.XML)
//   3) AllVarianty.csv (D1913 / Didriksons, E/2 nebo F/2)
//
// Pravidlo:
//   - aktualizuje pouze řádky, kde price_s_dph IS NULL nebo = 0
//   - pokud již price_s_dph hodnotu má, NEPŘEPISUJE ji

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/config.php'; // $pdo

echo "START\n";

$updatedTotal = 0;

// Společný UPDATE prepared statement
$updStmt = $pdo->prepare("
    UPDATE Kompletni_DatabazeVariantyEANProdejeCeny
    SET price_s_dph = :price
    WHERE ean = :ean
      AND (price_s_dph IS NULL OR price_s_dph = 0)
");

// Pomocná funkce pro update jednoho EANu
function update_price_if_empty(PDOStatement $stmt, string $ean, float $price): int {
    if ($ean === '' || $price <= 0) {
        return 0;
    }
    $stmt->execute([
        ':ean'   => $ean,
        ':price' => $price,
    ]);
    return $stmt->rowCount();
}

// ------------------------------------------------------------------
// 1) VAVRYS_VARIANTS (DB)  - základní nákupní ceny
// ------------------------------------------------------------------
echo "1) VAVRYS_VARIANTS -> Kompletni_DatabazeVariantyEANProdejeCeny\n";

$sqlVavrys = "SELECT ean, price FROM vavrys_variants WHERE ean IS NOT NULL AND ean <> ''";
$stVavrys  = $pdo->query($sqlVavrys);

$cntRows   = 0;
$cntUpdated = 0;

while ($row = $stVavrys->fetch(PDO::FETCH_ASSOC)) {
    $cntRows++;
    $ean = trim((string)$row['ean']);
    $priceBase = (float)str_replace(',', '.', (string)$row['price']);

    if ($ean === '' || $priceBase <= 0) {
        continue;
    }

    // pravidlo: price * 0.97 * 1.21
    $price = round($priceBase * 0.97 * 1.21, 2);

    $cntUpdated += update_price_if_empty($updStmt, $ean, $price);
}

echo "   Načteno z vavrys_variants: {$cntRows} řádků\n";
echo "   Aktualizováno (doplněno) z vavrys_variants: {$cntUpdated} řádků\n";
$updatedTotal += $cntUpdated;

// ------------------------------------------------------------------
// 2) SILVINI XML feed
// ------------------------------------------------------------------
echo "2) Silvini XML feed -> Kompletni_DatabazeVariantyEANProdejeCeny\n";

$xmlUrl = 'http://95.80.221.202:3380/REPORTS_SALESPRICE_CZK.XML';
$xmlContent = @file_get_contents($xmlUrl);

if ($xmlContent === false) {
    echo "   VAROVÁNÍ: Nelze stáhnout XML z {$xmlUrl}\n";
} else {
    $xml = @simplexml_load_string($xmlContent);
    if ($xml === false) {
        echo "   VAROVÁNÍ: Nelze parsovat XML\n";
    } else {
        $cntRows   = 0;
        $cntUpdated = 0;

        // Projdeme všechny potomky rootu, hledáme elementy s <ean>
        foreach ($xml->children() as $item) {
            if (!isset($item->ean)) {
                continue;
            }
            $cntRows++;

            $ean = trim((string)$item->ean);
            if ($ean === '') {
                continue;
            }

            $buyBasic = isset($item->CZ_BUY_BASIC) ? (float)str_replace(',', '.', (string)$item->CZ_BUY_BASIC) : 0.0;
            $buyPromo = isset($item->CZ_BUY_PROMO) ? (float)str_replace(',', '.', (string)$item->CZ_BUY_PROMO) : 0.0;
            $isDisc   = isset($item->IsDiscountedB2B) ? (int)$item->IsDiscountedB2B : 0;

            if ($isDisc === 1) {
                // promo: CZ_BUY_PROMO * 1,21
                $price = $buyPromo;
            } else {
                // základ: CZ_BUY_BASIC * 0,78
                $price = $buyBasic * 0.78;
            }
            if ($price <= 0) {
                continue;
            }
            $price = round($price * 1.21, 2); // + DPH

            $cntUpdated += update_price_if_empty($updStmt, $ean, $price);
        }

        echo "   Načteno z XML: {$cntRows} záznamů\n";
        echo "   Aktualizováno (doplněno) ze Silvini XML: {$cntUpdated} řádků\n";
        $updatedTotal += $cntUpdated;
    }
}

// ------------------------------------------------------------------
// 3) AllVarianty.csv  - D1913 / Didriksons
// ------------------------------------------------------------------
echo "3) AllVarianty.csv (D1913 / Didriksons) -> Kompletni_DatabazeVariantyEANProdejeCeny\n";

$csvPath = __DIR__ . '/CStore/Varianty/AllVarianty.csv';

if (!is_file($csvPath)) {
    echo "   VAROVÁNÍ: Soubor {$csvPath} neexistuje.\n";
} else {
    $h = fopen($csvPath, 'r');
    if ($h === false) {
        echo "   VAROVÁNÍ: Nelze otevřít AllVarianty.csv\n";
    } else {
        $rowIndex = 0;
        $cntRows = 0;
        $cntUpdated = 0;

        while (($row = fgetcsv($h, 0, ';')) !== false) {
            $rowIndex++;
            if ($rowIndex === 1) {
                // hlavička
                continue;
            }

            // Očekávané indexy:
            // 0 = product_key, 2 = značka / identifikace (C), 4 = Cena před (E), 5 = ... (F), 16 = EAN (Q)
            $productKey = isset($row[0]) ? trim((string)$row[0]) : '';
            $brand      = isset($row[2]) ? trim((string)$row[2]) : '';
            $priceE     = isset($row[4]) ? (float)str_replace(',', '.', (string)$row[4]) : 0.0;
            $priceF     = isset($row[5]) ? (float)str_replace(',', '.', (string)$row[5]) : 0.0;
            $ean        = isset($row[16]) ? trim((string)$row[16]) : '';

            if ($ean === '') {
                continue;
            }

            // Pouze záznamy, kde se ve sloupci C vyskytuje D1913 nebo Didriksons
            $brandUpper = strtoupper($brand);
            if (strpos($brandUpper, 'D1913') === false && strpos($brandUpper, 'DIDRIKSONS') === false) {
                continue;
            }

            $cntRows++;

            if ($priceE > 0) {
                $price = $priceE / 2.0;
            } elseif ($priceF > 0) {
                $price = $priceF / 2.0;
            } else {
                continue;
            }

            $price = round($price, 2);

            $cntUpdated += update_price_if_empty($updStmt, $ean, $price);
        }

        fclose($h);

        echo "   Relevantních řádků (D1913/Didriksons): {$cntRows}\n";
        echo "   Aktualizováno (doplněno) z AllVarianty.csv: {$cntUpdated} řádků\n";
        $updatedTotal += $cntUpdated;
    }
}

echo "----------------------------------------------\n";
echo "CELKEM doplněno price_s_dph ze všech zdrojů: {$updatedTotal} řádků\n";
echo "HOTOVO\n";
?>
