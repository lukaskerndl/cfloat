<?php
// Přístup jen pro přihlášené (dřív byl tento endpoint veřejný).
require_once __DIR__ . '/_require_login.php';

// stats_items_view.php – jednoduchý přehled statistik položek z DB

require __DIR__ . '/config.php';

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

try {
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
        LIMIT 1000
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $error = null;
} catch (Throwable $e) {
    $error = $e->getMessage();
    $rows = [];
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Statistika položek – DB</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; margin: 20px; background:#f4f5f7; }
        h1 { margin-top:0; }
        table { border-collapse: collapse; width: 100%; background:#fff; }
        th, td { border: 1px solid #ddd; padding: 4px 6px; font-size: 13px; }
        th { background:#f0f0f0; text-align:left; position:sticky; top:0; }
        tr:nth-child(even) td { background:#fafafa; }
    </style>
</head>
<body>
    <h1>Statistika položek – z MySQL (orders + order_items)</h1>

    <?php if ($error): ?>
        <p style="color:red;">Chyba při načítání: <?= h($error) ?></p>
    <?php endif; ?>

    <p>Počet řádků: <?= count($rows) ?></p>

    <table>
        <thead>
            <tr>
                <th>Kód (product_number)</th>
                <th>Název</th>
                <th>Ks celkem</th>
                <th>Obrat s DPH</th>
                <th>Počet objednávek</th>
                <th>První prodej</th>
                <th>Poslední prodej</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= h($r['product_number']) ?></td>
                <td><?= h($r['product_name']) ?></td>
                <td><?= h($r['total_qty']) ?></td>
                <td><?= h(number_format((float)$r['total_revenue_with_vat'], 2, ',', ' ')) ?> Kč</td>
                <td><?= h($r['orders_count']) ?></td>
                <td><?= h($r['first_order_at']) ?></td>
                <td><?= h($r['last_order_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
