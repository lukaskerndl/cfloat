<?php
// fill_ean_from_ean_map.php
// Doplní EAN do všech řádků v order_items podle tabulky ean_map.

require __DIR__ . '/config.php'; // $pdo – připojení k d388160_cfloat

echo '<pre>';

try {
    // Kolik řádků v order_items je aktuálně bez EAN?
    $stmtMissingBefore = $pdo->query("SELECT COUNT(*) AS cnt FROM order_items WHERE EAN IS NULL OR EAN = ''");
    $missingBefore = (int)$stmtMissingBefore->fetchColumn();
    echo 'Řádků v order_items bez EAN před aktualizací: ' . $missingBefore . "\n\n";

    // UPDATE z ean_map
    $sql = "UPDATE order_items AS oi
            JOIN ean_map    AS em
              ON em.product_id = oi.product_id
             AND em.variant_id = oi.variant_id
            SET oi.EAN = em.ean
            WHERE (oi.EAN IS NULL OR oi.EAN = '')";

    $affected = $pdo->exec($sql);
    if ($affected === false) {
        echo "UPDATE selhal.\n";
    } else {
        echo 'Počet upravených řádků (doplněn EAN): ' . (int)$affected . "\n\n";
    }

    // Kolik řádků zůstalo bez EAN po aktualizaci?
    $stmtMissingAfter = $pdo->query("SELECT COUNT(*) AS cnt FROM order_items WHERE EAN IS NULL OR EAN = ''");
    $missingAfter = (int)$stmtMissingAfter->fetchColumn();
    echo 'Řádků v order_items bez EAN po aktualizaci: ' . $missingAfter . "\n";

} catch (Throwable $e) {
    echo 'Chyba: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
}

echo '</pre>';
