<?php
// Přístup jen pro přihlášené (dřív byl tento endpoint veřejný).
require_once __DIR__ . '/_require_login.php';

// stats_items_csv.php
// CSV pro "Statistika položek" – náhrada za Google Sheet

require __DIR__ . '/config.php';  // musí v něm vzniknout $pdo (PDO připojení)

// --------------------------------------
// 1) Příprava výstupu
// --------------------------------------
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="stats_items.csv"');

$out = fopen('php://output', 'w');

// -------------------------------------------------
// 2) Hlavička – jen informativní, ale zachováme 30 sloupců,
// aby indexy v index.php (C, Y, Z, AA, AC, AD) seděly.
// A = 0, B = 1, C = 2, ..., K = 10, Y = 24, Z = 25, AA = 26, AC = 28, AD = 29
// -------------------------------------------------
$header = [
    'A',
    'B',
    'C - datum objednávky',
    'D',
    'E',
    'F',
    'G',
    'H',
    'I',
    'J',
    'K - číslo objednávky',
    'L',
    'M',
    'N',
    'O',
    'P',
    'Q',
    'R',
    'S',
    'T',
    'U',
    'V',
    'W',
    'X',
    'Y - kód položky',
    'Z - název položky',
    'AA - detail (varianta)',
    'AB',
    'AC - cena řádku s DPH',
    'AD - hodnota (stejná jako AC)',
];
fputcsv($out, $header); // oddělovač = čárka

// -------------------------------------------------
// 3) Dotaz do databáze
//    UPRAV JEN, KDYBY SE LIŠILA JMÉNA SLOUPCŮ
// -------------------------------------------------
//
// Předpokládám schéma:
//  orders:      id_order, number, created_at
//  order_items: id, id_order, product_number, product_name,
//               variant_description, price_total_with_vat, count
//
// Pokud máš názvy jinak, jen je přepiš v SELECTu.
//
$sql = "
    SELECT
        o.created_at                         AS created_at,
        o.number                             AS order_number,
        i.product_number                     AS product_number,
        i.product_name                       AS product_name,
        i.variant_description                AS variant_description,
        i.price_total_with_vat               AS line_total_with_vat,
        i.count                              AS line_count
    FROM orders o
    JOIN order_items i ON i.id_order = o.id_order
";


// jednoduché načtení všech řádků
$stmt = $pdo->query($sql);

// -------------------------------------------------
// 4) Každý řádek → rozprostřeme do 30 sloupců,
//    na pozice, které index.php používá:
//    C = datum, K = číslo objednávky,
//    Y = kód, Z = název, AA = detail, AC/AD = cena
// -------------------------------------------------
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    $cols = array_fill(0, 30, '');

    // datum objednávky – jen YYYY-MM-DD
    $created = (string)$row['created_at'];
    $dateYmd = substr($created, 0, 10);
    $cols[2]  = $dateYmd;                        // C

    // číslo objednávky
    $cols[10] = (string)$row['order_number'];    // K

    // kód položky
    $cols[24] = (string)$row['product_number'];  // Y

    // název položky
    $cols[25] = (string)$row['product_name'];    // Z

    // detail – varianta, velikost atd. (když nic není, dáme znovu název)
    $detail = trim((string)$row['variant_description']);
    if ($detail === '') {
        $detail = (string)$row['product_name'];
    }
    $cols[26] = $detail;                         // AA

    // cena řádku s DPH – použijeme total * počet (už by to mělo být total)
    $price = (float)$row['line_total_with_vat'];

    // jistota – když v DB držíš cenu za 1 ks, můžeš přenásobit počtem:
    // $price = (float)$row['price_per_unit_with_vat'] * (float)$row['line_count'];

    $priceFormatted = number_format($price, 2, '.', '');
    $cols[28] = $priceFormatted;                 // AC
    $cols[29] = $priceFormatted;                 // AD – stejné jako AC

    fputcsv($out, $cols); // pořád oddělovač čárka
}

fclose($out);
exit;
