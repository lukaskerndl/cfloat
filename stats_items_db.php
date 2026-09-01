<?php
// Přístup jen pro přihlášené (dřív byl tento endpoint veřejný).
require_once __DIR__ . '/_require_login.php';

// stats_items_db.php
// Statistika položek přímo z MySQL (orders + order_items)
// - všechny objednávky v DB (žádný datumový filtr)
// - agregace podle product_number + product_name
// - vrací JSON, který můžeš použít místo Google Sheets

require __DIR__ . '/config.php'; // musí vytvořit $pdo (PDO na d388160_cfloat)

header('Content-Type: application/json; charset=utf-8');

try {
    // základní agregace: kolik se čeho prodalo + obrat
    $sql = "
        SELECT
            oi.product_number,
            oi.product_name,
            SUM(COALESCE(oi.`count`, 0)) AS total_qty,
            SUM(COALESCE(oi.price_total_with_vat, 0)) AS total_revenue_with_vat,
            COUNT(DISTINCT oi.id_order) AS orders_count,
            MIN(o.created_at) AS first_order_at,
            MAX(o.created_at) AS last_order_at
        FROM order_items oi
        JOIN orders o ON o.id_order = oi.id_order
        WHERE oi.product_number IS NOT NULL AND oi.product_number <> ''
        GROUP BY oi.product_number, oi.product_name
        ORDER BY total_qty DESC
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'      => true,
        'source'       => 'mysql',
        'generated_at' => date('c'),
        'rows'         => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
