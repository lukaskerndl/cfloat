<?php

// Spuštění jen s platným tokenem nebo z cronu (dřív bez jakékoliv ochrany).
require_once __DIR__ . '/_cron_guard.php';

/**
 * update_kompletni_ceny_xml.php (finální verze)
 *
 * Načte XML feed z:
 *   http://95.80.221.202:3380/REPORTS_SALESPRICE_CZK.XML
 *
 * a podle EAN doplní / nastaví sloupec price_s_dph v tabulce
 *   Kompletni_DatabazeVariantyEANProdejeCeny
 *
 * PRAVIDLA:
 *  - pokud IsDiscountedB2B == 1:
 *        price_s_dph = CZ_BUY_PROMO * 1.21
 *  - pokud IsDiscountedB2B == 0:
 *        price_s_dph = CZ_BUY_BASIC * 0.78 * 1.21
 *
 *  - EXISTUJÍCÍ HODNOTY NEPŘEPISUJEME:
 *        UPDATE proběhne jen tam, kde je price_s_dph NULL nebo 0.
 *
 * Struktura XML dle vzorku:
 *  <SERVICE_REPORTS_SALESPRICE_CZK>
 *      <ean>8596016218666</ean>
 *      <CZ_BUY_BASIC>1533.00</CZ_BUY_BASIC>
 *      <CZ_BUY_PROMO>1533.00</CZ_BUY_PROMO>
 *      <IsDiscountedB2B>0</IsDiscountedB2B>
 *      ...
 *  </SERVICE_REPORTS_SALESPRICE_CZK>
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/config.php'; // očekává PDO v proměnné $pdo

$url = 'http://95.80.221.202:3380/REPORTS_SALESPRICE_CZK.XML';

echo "START\n";
echo "Stahuji XML: {$url}\n";

// ---------- 1) Stažení XML ----------
$ctx = stream_context_create([
    'http' => [
        'timeout' => 30,
    ],
]);
$xmlString = @file_get_contents($url, false, $ctx);
if ($xmlString === false) {
    echo "CHYBA: Nelze stáhnout XML z {$url}\n";
    exit;
}

// ---------- 2) Parsování XML ----------
$xml = @simplexml_load_string($xmlString);
if ($xml === false) {
    echo "CHYBA: XML není validní, nepodařilo se jej načíst.\n";
    exit;
}

// Záznamy jsou děti rootu (např. <SERVICE_REPORTS_SALESPRICE_CZK>...)
$rows = $xml->children();
$rowCount = 0;
foreach ($rows as $r) {
    $rowCount++;
}
echo "Načteno XML záznamů: {$rowCount}\n";

// ---------- 3) Připravený UPDATE ----------
$sqlUpdate = "UPDATE Kompletni_DatabazeVariantyEANProdejeCeny
              SET price_s_dph = :price
              WHERE ean = :ean
                AND (price_s_dph IS NULL OR price_s_dph = 0)";
$stmtUpdate = $pdo->prepare($sqlUpdate);

$updated        = 0;
$skippedNoEan   = 0;
$skippedNoPrice = 0;

// ---------- 4) Průchod XML ----------
foreach ($rows as $row) {

    // přesné názvy podle ukázky
    $ean = isset($row->ean) ? trim((string)$row->ean) : '';
    if ($ean === '') {
        $skippedNoEan++;
        continue;
    }

    $isDiscounted = isset($row->IsDiscountedB2B) ? trim((string)$row->IsDiscountedB2B) : '0';

    $basicStr = isset($row->CZ_BUY_BASIC) ? trim((string)$row->CZ_BUY_BASIC) : '';
    $promoStr = isset($row->CZ_BUY_PROMO) ? trim((string)$row->CZ_BUY_PROMO) : '';

    // desetinná tečka/čárka
    $basicStr = str_replace(',', '.', $basicStr);
    $promoStr = str_replace(',', '.', $promoStr);

    $basic = ($basicStr !== '') ? (float)$basicStr : 0.0;
    $promo = ($promoStr !== '') ? (float)$promoStr : 0.0;

    // výpočet ceny s DPH
    $priceSDph = 0.0;

    if ($isDiscounted === '1') {
        if ($promo <= 0) {
            $skippedNoPrice++;
            continue;
        }
        $priceSDph = $promo * 1.21;
    } else {
        if ($basic <= 0) {
            $skippedNoPrice++;
            continue;
        }
        $priceSDph = $basic * 0.78 * 1.21;
    }

    // UPDATE pouze pokud v DB zatím není cena (NULL/0)
    $stmtUpdate->execute([
        ':price' => $priceSDph,
        ':ean'   => $ean,
    ]);

    if ($stmtUpdate->rowCount() > 0) {
        $updated++;
    }
}

// ---------- 5) Shrnutí ----------
echo "HOTOVO\n";
echo "Aktualizováno záznamů v DB: {$updated}\n";
echo "Přeskočeno (bez EAN): {$skippedNoEan}\n";
echo "Přeskočeno (bez použitelné ceny): {$skippedNoPrice}\n";
?>
