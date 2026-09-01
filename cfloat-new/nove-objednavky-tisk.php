<?php
declare(strict_types=1);

/**
 * cfloat-new/nove-objednavky-tisk.php
 *
 * Tisková stránka jedné objednávky z nového API:
 * - položky objednávky (order-products)
 * - odkaz/údaje faktury (invoices)
 * - čárový kód VYGENEROVANÝ z čísla objednávky (Code128, v prohlížeči přes JsBarcode) -
 *   nové API nevrací EAN u položek, takže čárový kód neidentifikuje zboží, ale
 *   slouží k rychlému skenování/vyhledání SAMOTNÉ OBJEDNÁVKY (např. při vychystávání).
 *
 * Použití: nove-objednavky-tisk.php?iri=/api-engine/orders/xxxxx
 * (iri se bere ze sloupce order_iri v eshop_new_orders, resp. z detailu objednávky)
 */

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$cfgCandidates = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php'];
$pdo = null;
foreach ($cfgCandidates as $p) {
    if (is_file($p)) { require $p; break; }
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Chybí config.php nebo $pdo.');
}

$secretsPath = __DIR__ . '/../secrets/eshop_new_api.php';
if (!is_file($secretsPath)) die('Chybí secrets/eshop_new_api.php.');
$cfg = include $secretsPath;
$baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
$token   = trim((string)($cfg['token'] ?? ''));

function eshop_new_api_call(string $baseUrl, string $token, string $path): array
{
    $url = str_starts_with($path, 'http') ? $path : ($baseUrl . $path);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Accept: application/ld+json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $response = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = $response !== false ? json_decode($response, true) : null;
    return ['ok' => $http >= 200 && $http < 300, 'http' => $http, 'body' => $decoded];
}

$orderIri = (string)($_GET['iri'] ?? '');
if ($orderIri === '') die('Chybí parametr iri.');

// --- Zkusíme nejdřív z naší databáze (rychlejší, ušetří API volání) ---
$stmt = $pdo->prepare("SELECT * FROM eshop_new_orders WHERE order_iri = :iri LIMIT 1");
$stmt->execute([':iri' => $orderIri]);
$dbRow = $stmt->fetch(PDO::FETCH_ASSOC);
$order = $dbRow ? json_decode((string)$dbRow['raw_json'], true) : null;

// Pokud v DB není (např. objednávka ještě nesynchronizovaná), stáhneme živě.
if (!$order) {
    $res = eshop_new_api_call($baseUrl, $token, $orderIri);
    if (!$res['ok']) die('Objednávku se nepodařilo načíst (HTTP ' . $res['http'] . ').');
    $order = $res['body'];
}

$orderCreated = $order['created'] ?? null;
$orderNumber = ''; // číslo objednávky (najdeme přes fakturu - variableSymbol odpovídá číslu objednávky)
$items = [];
$invoice = null;

if ($orderCreated) {
    $day = substr((string)$orderCreated, 0, 10);
    $from = rawurlencode($day . 'T00:00:00+02:00');
    $to = rawurlencode($day . 'T23:59:59+02:00');

    // Položky
    $itemsRes = eshop_new_api_call($baseUrl, $token, "/api-engine/order-products?order.created[after]={$from}&order.created[before]={$to}&itemsPerPage=300");
    foreach (($itemsRes['body']['hydra:member'] ?? []) as $it) {
        if (($it['order'] ?? '') === $orderIri) $items[] = $it;
    }

    // Faktura
    $invRes = eshop_new_api_call($baseUrl, $token, "/api-engine/invoices?dateIssue[after]={$from}&dateIssue[before]={$to}&itemsPerPage=200");
    foreach (($invRes['body']['hydra:member'] ?? []) as $inv) {
        if (($inv['order'] ?? '') === $orderIri) { $invoice = $inv; break; }
    }
    if ($invoice) $orderNumber = (string)($invoice['variableSymbol'] ?? '');
}

// Pokud se číslo objednávky nepodařilo najít přes fakturu, použijeme aspoň kus IRI.
if ($orderNumber === '') {
    $orderNumber = substr($orderIri, -12);
}

$customerName = $order['name'] ?? '';
$address = $order['address'] ?? '';
$city = $order['city'] ?? '';
$zip = $order['zip'] ?? '';
$country = $order['country'] ?? '';
$phone = $order['phoneNumber'] ?? '';
$email = $order['email'] ?? '';
$stateName = $order['orderState']['name'] ?? '';

$totalWithVat = 0;
foreach ($items as $it) { $totalWithVat += (float)($it['priceWithVat'] ?? 0) * (float)($it['pieces'] ?? 1); }
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Tisk objednávky <?php echo h($orderNumber); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdnjs.cloudflare.com/ajax/libs/JsBarcode/3.11.5/JsBarcode.all.min.js"></script>
<style>
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; color:#222; }
.wrap { max-width:800px; margin:0 auto; background:#fff; border:1px solid #e7e9ec; border-radius:14px; padding:24px; }
.no-print { margin-bottom:16px; }
.btn { background:linear-gradient(135deg,#24d84a,#00b52a); color:#fff; border:none; border-radius:999px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid #e7e9ec; border-radius:999px; padding:6px 14px; margin-right:8px; }
.header-row { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; }
.order-number { font-size:22px; font-weight:800; }
.pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; background:#eef6ee; color:#2a7a2a; }
table { border-collapse:collapse; width:100%; font-size:13px; margin-top:16px; }
td, th { border:1px solid #e7e9ec; padding:6px 8px; text-align:left; }
th { background:#f7f8f9; }
.totals { text-align:right; margin-top:10px; font-size:15px; font-weight:700; }
.customer-box { font-size:13px; line-height:1.5; }
@media print {
    body { background:#fff; padding:0; }
    .wrap { border:none; border-radius:0; padding:0; max-width:none; }
    .no-print { display:none; }
}
</style>
</head>
<body>
<div class="wrap">
    <div class="no-print">
        <a class="back-link" href="nove-objednavky-detail.php?iri=<?php echo urlencode($orderIri); ?>">&larr; Zpět na detail</a>
        <button class="btn" onclick="window.print()">Vytisknout</button>
    </div>

    <div class="header-row">
        <div>
            <div class="order-number">Objednávka č. <?php echo h($orderNumber); ?></div>
            <div><?php echo h($orderCreated ? date('d.m.Y H:i', strtotime($orderCreated)) : ''); ?></div>
            <?php if ($stateName): ?><div><span class="pill"><?php echo h($stateName); ?></span></div><?php endif; ?>
        </div>
        <div style="text-align:right;">
            <svg id="barcode"></svg>
        </div>
    </div>

    <div class="customer-box">
        <b><?php echo h($customerName); ?></b><br>
        <?php echo h($address); ?><br>
        <?php echo h($zip . ' ' . $city); ?><br>
        <?php echo h($country); ?><br>
        <?php if ($phone): ?>Tel: <?php echo h($phone); ?><br><?php endif; ?>
        <?php if ($email): ?><?php echo h($email); ?><?php endif; ?>
    </div>

    <table>
        <thead>
            <tr><th>Položka</th><th>Kód</th><th>Velikost</th><th>Ks</th><th>Cena/ks s DPH</th><th>Celkem</th></tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="6">Žádné položky nenalezeny (zkontroluj, že objednávka existuje a má data za tento den).</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $it): ?>
            <?php $lineTotal = (float)($it['priceWithVat'] ?? 0) * (float)($it['pieces'] ?? 1); ?>
            <tr>
                <td><?php echo h($it['name'] ?? ''); ?></td>
                <td><?php echo h($it['number'] ?? ''); ?></td>
                <td><?php echo h($it['oldVariantValue1'] ?? ''); ?></td>
                <td><?php echo (int)($it['pieces'] ?? 1); ?></td>
                <td><?php echo number_format((float)($it['priceWithVat'] ?? 0), 2, ',', ' '); ?> Kč</td>
                <td><?php echo number_format($lineTotal, 2, ',', ' '); ?> Kč</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">Celkem s DPH: <?php echo number_format($totalWithVat, 2, ',', ' '); ?> Kč</div>

    <?php if ($invoice): ?>
        <div style="margin-top:16px;font-size:12.5px;color:#555;">
            Faktura č. <?php echo h($invoice['number'] ?? ''); ?>,
            vystaveno <?php echo h(date('d.m.Y', strtotime((string)($invoice['dateIssue'] ?? 'now')))); ?>,
            splatnost <?php echo h(date('d.m.Y', strtotime((string)($invoice['dateDue'] ?? 'now')))); ?>,
            stav platby: <?php echo (($invoice['paymentStatus'] ?? 0) == 1) ? '<b style="color:#0a7a34;">zaplaceno</b>' : '<b style="color:#d93025;">nezaplaceno</b>'; ?>
        </div>
    <?php else: ?>
        <div style="margin-top:16px;font-size:12.5px;color:#999;">Faktura k této objednávce nebyla nalezena.</div>
    <?php endif; ?>
</div>

<script>
    JsBarcode("#barcode", <?php echo json_encode($orderNumber); ?>, {
        format: "CODE128",
        width: 2,
        height: 50,
        displayValue: true,
        fontSize: 14
    });
</script>
</body>
</html>
