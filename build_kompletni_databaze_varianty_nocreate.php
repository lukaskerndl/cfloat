<?php

// Spuštění jen s platným tokenem nebo z cronu (dřív bez jakékoliv ochrany).
require_once __DIR__ . '/_cron_guard.php';

// build_kompletni_databaze_varianty.php (verze bez CREATE TABLE)
// Plní tabulku Kompletni_DatabazeVariantyEANProdejeCeny
// - zdroj: d388160_cfloat.order_items
// - doplnění EAN podle klíče product_id_variant_id z AllVarianty.csv

require __DIR__ . '/config.php'; // PDO ve var. $pdo

// Cesta k AllVarianty.csv (na Wedosu: /www/CStore/Varianty/AllVarianty.csv)
define('ALL_VARIANTY_CSV', __DIR__ . '/CStore/Varianty/AllVarianty.csv');

// Název cílové tabulky v DB
$tableName = 'Kompletni_DatabazeVariantyEANProdejeCeny';

header('Content-Type: text/plain; charset=utf-8');

try {
    // 1) Kontrola existence CSV
    if (!is_file(ALL_VARIANTY_CSV)) {
        throw new RuntimeException('Soubor AllVarianty.csv nenalezen: ' . ALL_VARIANTY_CSV);
    }

    // 2) Ověření, že cílová tabulka existuje
    try {
        $pdo->query("SELECT 1 FROM {$tableName} LIMIT 1");
    } catch (PDOException $e) {
        // 42S02 = tabulka neexistuje
        if ($e->getCode() === '42S02') {
            $sqlExample = <<<SQL
CREATE TABLE {$tableName} (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_order INT NOT NULL,
    product_number VARCHAR(64) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    variant_description VARCHAR(255) DEFAULT NULL,
    price_per_unit DECIMAL(15,4) DEFAULT NULL,
    price_per_unit_with_vat DECIMAL(15,4) DEFAULT NULL,
    price_total DECIMAL(15,4) DEFAULT NULL,
    price_total_with_vat DECIMAL(15,4) DEFAULT NULL,
    count INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NOT NULL,
    product_key VARCHAR(64) NOT NULL,
    ean VARCHAR(64) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_order (id_order),
    KEY idx_product_key (product_key),
    KEY idx_ean (ean)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
            throw new RuntimeException(
                "Tabulka {$tableName} neexistuje. Vytvoř ji ručně v phpMyAdmin pomocí SQL:\n\n" .
                $sqlExample
            );
        }
        // jiná chyba
        throw $e;
    }

    // 3) Načtení mapy EAN z AllVarianty.csv
    $eanMap = [];
    $handle = fopen(ALL_VARIANTY_CSV, 'r');
    if ($handle === false) {
        throw new RuntimeException('Nelze otevřít AllVarianty.csv pro čtení.');
    }

    // očekáváme ; jako oddělovač, první řádek = hlavička
    $header = fgetcsv($handle, 0, ';');
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException('Soubor AllVarianty.csv je prázdný nebo nečitelný.');
    }

    $rowIndex = 0;
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $rowIndex++;
        if (count($row) < 17) {
            // potřebujeme alespoň A..Q (17 sloupců)
            continue;
        }

        $key = trim((string)$row[0]);   // sloupec A = product_id_variant_id
        $ean = trim((string)$row[16]);  // sloupec Q = EAN

        if ($key === '') {
            continue;
        }

        // poslední výskyt vyhrává (kdyby se opakoval)
        $eanMap[$key] = $ean;
    }
    fclose($handle);

    $cntMap = count($eanMap);

    // 4) Vyprázdnění cílové tabulky (budeme ji vždy sestavovat znovu)
    // TRUNCATE může vyžadovat právo CREATE, raději použijeme DELETE
    $pdo->exec("DELETE FROM {$tableName}");

    // 5) Připravený INSERT
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
            count,
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

    // 6) Projít order_items a plnit cílovou tabulku
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
            count,
            product_id,
            variant_id
        FROM order_items
    ";

    $stmtSelect = $pdo->query($sqlSelect);

    $inserted = 0;
    while ($row = $stmtSelect->fetch(PDO::FETCH_ASSOC)) {
        $productId = (int)($row['product_id'] ?? 0);
        $variantId = (int)($row['variant_id'] ?? 0);
        $productKey = $productId . '_' . $variantId;

        $ean = $eanMap[$productKey] ?? null;

        $stmtInsert->execute([
            ':id_order'                 => (int)$row['id_order'],
            ':product_number'           => (string)$row['product_number'],
            ':product_name'             => (string)$row['product_name'],
            ':variant_description'      => (string)($row['variant_description'] ?? ''),
            ':price_per_unit'           => $row['price_per_unit'],
            ':price_per_unit_with_vat'  => $row['price_per_unit_with_vat'],
            ':price_total'              => $row['price_total'],
            ':price_total_with_vat'     => $row['price_total_with_vat'],
            ':count'                    => (int)$row['count'],
            ':product_id'               => $productId,
            ':variant_id'               => $variantId,
            ':product_key'              => $productKey,
            ':ean'                      => $ean,
        ]);

        $inserted++;
    }

    echo "OK\n";
    echo "Tabulka: {$tableName}\n";
    echo "Načteno EAN z CSV: {$cntMap}\n";
    echo "Vloženo řádků z order_items: {$inserted}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "CHYBA: " . $e->getMessage() . "\n";
}
