<?php

// Spuštění jen s platným tokenem nebo z cronu (dřív bez jakékoliv ochrany).
require_once __DIR__ . '/_cron_guard.php';

// build_kompletni_databaze_varianty.php
// Rekonstrukce tabulky Kompletni_DatabazeVariantyEANProdejeCeny
// tak, aby:
//  - NEvznikaly duplicity,
//  - sloupec price_s_dph zůstal zachovaný (nepřepisuje se),
//  - EAN se doplňuje z AllVarianty.csv podle klíče product_id_variant_id.

require __DIR__ . '/config.php';   // $pdo

header('Content-Type: text/plain; charset=utf-8');

// Cesta k AllVarianty.csv (na Wedosu je soubor ve /www/CStore/Varianty/)
define('ALL_VARIANTY_CSV', __DIR__ . '/CStore/Varianty/AllVarianty.csv');

// Název cílové tabulky
$tableName = 'Kompletni_DatabazeVariantyEANProdejeCeny';

try {
    echo "START\n";

    // ------------------------------------------------------------------
    // 1) Mapa EAN z AllVarianty.csv
    // ------------------------------------------------------------------
    if (!is_file(ALL_VARIANTY_CSV)) {
        throw new RuntimeException('Soubor AllVarianty.csv nenalezen: ' . ALL_VARIANTY_CSV);
    }

    $eanMap = [];
    $h = fopen(ALL_VARIANTY_CSV, 'r');
    if ($h === false) {
        throw new RuntimeException('Nelze otevřít AllVarianty.csv.');
    }

    // první řádek = hlavička
    $header = fgetcsv($h, 0, ';');
    if ($header === false) {
        fclose($h);
        throw new RuntimeException('AllVarianty.csv je prázdný.');
    }

    $rowIndex = 0;
    while (($row = fgetcsv($h, 0, ';')) !== false) {
        $rowIndex++;

        // potřebujeme minimálně A..Q (index 0..16)
        if (!isset($row[0], $row[16])) {
            continue;
        }

        $key = trim((string)$row[0]);   // A = product_id_variant_id
        $ean = trim((string)$row[16]);  // Q = EAN

        if ($key === '') {
            continue;
        }

        $eanMap[$key] = $ean; // poslední výskyt vyhrává
    }
    fclose($h);

    echo "Načteno EAN z CSV: " . count($eanMap) . "\n";

    // ------------------------------------------------------------------
    // 2) Načtení existujících záznamů z Kompletni_DatabazeVariantyEANProdejeCeny
    //    => kvůli zabránění duplicitám a zachování price_s_dph
    // ------------------------------------------------------------------
    $existing = [];   // key => id
    $touched  = [];   // key => true

    $sqlExisting = "
        SELECT
            id,
            id_order,
            product_id,
            variant_id,
            price_total_with_vat,
            `count`
        FROM {$tableName}
    ";
    foreach ($pdo->query($sqlExisting) as $row) {
        $key = $row['id_order'] . '|' .
               $row['product_id'] . '|' .
               $row['variant_id'] . '|' .
               $row['price_total_with_vat'] . '|' .
               $row['count'];

        $existing[$key] = (int)$row['id'];
    }

    echo "Existujících záznamů v {$tableName}: " . count($existing) . "\n";

    // ------------------------------------------------------------------
    // 3) Připravené dotazy INSERT a UPDATE
    //     INSERT = nový řádek (price_s_dph zůstává DEFAULT NULL)
    //     UPDATE = přepis ostatních sloupců, ale price_s_dph necháváme tak jak je
    // ------------------------------------------------------------------
    $sqlInsert = "
        INSERT INTO {$tableName} (
            id_order,
            product_number,
            product_name,
            variant_description,
            price_per_unit,
            price_per_unit_with_vat,
            price_total,
            price_total_with_vat,
            `count`,
            product_id,
            variant_id,
            product_key,
            ean
        ) VALUES (
            :id_order,
            :product_number,
            :product_name,
            :variant_description,
            :price_per_unit,
            :price_per_unit_with_vat,
            :price_total,
            :price_total_with_vat,
            :count,
            :product_id,
            :variant_id,
            :product_key,
            :ean
        )
    ";
    $stmtInsert = $pdo->prepare($sqlInsert);

    $sqlUpdate = "
        UPDATE {$tableName}
        SET
            product_number          = :product_number,
            product_name            = :product_name,
            variant_description     = :variant_description,
            price_per_unit          = :price_per_unit,
            price_per_unit_with_vat = :price_per_unit_with_vat,
            price_total             = :price_total,
            price_total_with_vat    = :price_total_with_vat,
            `count`                 = :count,
            product_id              = :product_id,
            variant_id              = :variant_id,
            product_key             = :product_key,
            ean                     = :ean
        WHERE id = :id
    ";
    $stmtUpdate = $pdo->prepare($sqlUpdate);

    // ------------------------------------------------------------------
    // 4) Načtení order_items a zrcadlení do cílové tabulky bez duplicit
    // ------------------------------------------------------------------
    $sqlSelect = "
        SELECT
            id_order,
            product_number,
            product_name,
            variant_description,
            price_per_unit,
            price_per_unit_with_vat,
            price_total,
            price_total_with_vat,
            `count`,
            product_id,
            variant_id
        FROM order_items
        ORDER BY id_order, id_order_item
    ";

    $stmtSel = $pdo->query($sqlSelect);

    $inserted = 0;
    $updated  = 0;

    while ($row = $stmtSel->fetch(PDO::FETCH_ASSOC)) {
        $idOrder    = (int)$row['id_order'];
        $productId  = (int)$row['product_id'];
        $variantId  = (int)$row['variant_id'];
        $count      = (int)$row['count'];
        $ptw        = (float)$row['price_total_with_vat'];

        // klíč pro EAN z CSV
        $productKey = $productId . '_' . $variantId;
        $ean        = $eanMap[$productKey] ?? null;

        // klíč pro duplicitní kontrolu v cílové tabulce
        $key = $idOrder . '|' . $productId . '|' . $variantId . '|' . $ptw . '|' . $count;
        $touched[$key] = true;

        $commonParams = [
            ':id_order'               => $idOrder,
            ':product_number'         => $row['product_number'],
            ':product_name'           => $row['product_name'],
            ':variant_description'    => $row['variant_description'],
            ':price_per_unit'         => $row['price_per_unit'],
            ':price_per_unit_with_vat'=> $row['price_per_unit_with_vat'],
            ':price_total'            => $row['price_total'],
            ':price_total_with_vat'   => $row['price_total_with_vat'],
            ':count'                  => $count,
            ':product_id'             => $productId,
            ':variant_id'             => $variantId,
            ':product_key'            => $productKey,
            ':ean'                    => $ean,
        ];

        if (isset($existing[$key])) {
            // UPDATE – nepřepisujeme price_s_dph
            $params = $commonParams;
            $params[':id'] = $existing[$key];
            $stmtUpdate->execute($params);
            $updated++;
        } else {
            // INSERT – nový záznam
            $stmtInsert->execute($commonParams);
            $inserted++;
        }
    }

    // ------------------------------------------------------------------
    // 5) Smazání záznamů, které už v order_items neexistují
    // ------------------------------------------------------------------
    $deleted = 0;
    if (!empty($existing)) {
        $idsToDelete = [];
        foreach ($existing as $key => $id) {
            if (!isset($touched[$key])) {
                $idsToDelete[] = $id;
            }
        }

        if ($idsToDelete) {
            $chunks = array_chunk($idsToDelete, 500);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $sqlDel = "DELETE FROM {$tableName} WHERE id IN ({$placeholders})";
                $stmtDel = $pdo->prepare($sqlDel);
                $stmtDel->execute($chunk);
                $deleted += $stmtDel->rowCount();
            }
        }
    }

    echo "HOTOVO\n";
    echo "Vloženo nových řádků : {$inserted}\n";
    echo "Aktualizováno řádků   : {$updated}\n";
    echo "Smazáno starých řádků : {$deleted}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "CHYBA: " . $e->getMessage() . "\n";
}
