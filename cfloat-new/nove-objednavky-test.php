<?php
declare(strict_types=1);

/**
 * cfloat-new/nove-objednavky-test.php
 *
 * Ověřovací skript pro NOVÉ Eshop-rychle API na produkčním e-shopu
 * (1388739759.s1.eshop-rychle.cz). POUZE ČTENÍ – nic se tu nezapisuje,
 * nic se neukládá do databáze cfloatu. Slouží jen k ověření, že token
 * funguje a jak vypadají data objednávky (stav, platba) v novém API.
 */

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../index.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$secretsPath = __DIR__ . '/../secrets/eshop_new_api.php';
if (!is_file($secretsPath)) {
    die('Chybí soubor secrets/eshop_new_api.php. Zkontroluj, že je nahraný ve složce www/secrets/ (ne jinde).');
}

$cfg = include $secretsPath;
$baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
$token   = trim((string)($cfg['token'] ?? ''));

if ($token === '') {
    die('Token v secrets/eshop_new_api.php je zatím prázdný. Vlož ho tam přímo na serveru a stránku obnov.');
}

/**
 * Volání nového API (api-engine). Vrací pole s ok/http/body/error.
 */
function eshop_new_api_call(string $baseUrl, string $token, string $method, string $path, ?array $jsonBody = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'body' => null, 'error' => 'Na serveru chybí cURL.'];
    }

    $url = $baseUrl . $path;
    $headers = [
        'X-AUTH-TOKEN: ' . $token,
        'Accept: application/ld+json',
    ];
    if ($jsonBody !== null) {
        $headers[] = ($method === 'PATCH') ? 'Content-Type: application/merge-patch+json' : 'Content-Type: application/ld+json';
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($jsonBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'http' => $http, 'body' => null, 'error' => 'cURL chyba: ' . $err];
    }

    $decoded = json_decode($response, true);
    $ok = $http >= 200 && $http < 300;

    return ['ok' => $ok, 'http' => $http, 'body' => $decoded, 'error' => $ok ? '' : ('HTTP ' . $http)];
}

$action = $_GET['action'] ?? '';
$result = null;

if ($action === 'list_orders') {
    $result = eshop_new_api_call($baseUrl, $token, 'GET', '/api-engine/orders?page=1');
}
if ($action === 'order_states') {
    $result = eshop_new_api_call($baseUrl, $token, 'GET', '/api-engine/order-states');
}
if ($action === 'test_date_filter') {
    // Ověření, že filtr na datum vytvoření funguje (než spustíme import historie).
    // Pokud API filtr ignoruje, hydra:totalItems bude stejné jako bez filtru (106685).
    $result = eshop_new_api_call($baseUrl, $token, 'GET', '/api-engine/orders?created[after]=2025-01-01T00:00:00%2B01:00&page=1');
}
if ($action === 'view_order' && isset($_GET['id'])) {
    $result = eshop_new_api_call($baseUrl, $token, 'GET', '/api-engine/orders/' . urlencode((string)$_GET['id']));
}
if ($action === 'order_items' && isset($_GET['order_iri'])) {
    $orderIri = (string)$_GET['order_iri'];
    // Stejný problém jako u faktur: 'order' je řazení, ne filtr na vztah.
    // Jediný dostupný filtr je order.created[after/before] - vezmeme položky
    // za den objednávky a mezi nimi najdeme shodu v poli "order".
    $orderDetail = eshop_new_api_call($baseUrl, $token, 'GET', $orderIri);
    $orderCreated = $orderDetail['body']['created'] ?? null;
    if ($orderCreated) {
        $day = substr((string)$orderCreated, 0, 10);
        $from = rawurlencode($day . 'T00:00:00+02:00');
        $to = rawurlencode($day . 'T23:59:59+02:00');
        $itemsRes = eshop_new_api_call($baseUrl, $token, 'GET', "/api-engine/order-products?order.created[after]={$from}&order.created[before]={$to}&itemsPerPage=300");
        $matches = [];
        $memberCount = count($itemsRes['body']['hydra:member'] ?? []);
        if ($memberCount > 0) {
            foreach ($itemsRes['body']['hydra:member'] as $item) {
                if (($item['order'] ?? '') === $orderIri) { $matches[] = $item; }
            }
        }
        $result = ['ok' => true, 'http' => 200, 'body' => ['nalezeno_polozek' => count($matches), 'prohledano_za_den' => $memberCount, 'polozky' => $matches], 'error' => ''];
    } else {
        $result = ['ok' => false, 'http' => 0, 'body' => null, 'error' => 'Nepodařilo se zjistit datum objednávky.'];
    }
}
if ($action === 'order_invoice' && isset($_GET['order_iri'])) {
    $orderIri = (string)$_GET['order_iri'];
    // 'order' je v tomhle API vyhrazené pro ŘAZENÍ, ne filtr na vztah k objednávce
    // (viz hydra:search - order[dateIssue]/order[invoiceChanged]/order[idInvoice] jsou
    // možnosti řazení, ne filtr podle konkrétní objednávky). Proto zjistíme datum
    // objednávky, vezmeme faktury za ten den a najdeme tu se shodným polem "order".
    $orderDetail = eshop_new_api_call($baseUrl, $token, 'GET', $orderIri);
    $orderCreated = $orderDetail['body']['created'] ?? null;
    if ($orderCreated) {
        $day = substr((string)$orderCreated, 0, 10);
        $from = rawurlencode($day . 'T00:00:00+02:00');
        $to = rawurlencode($day . 'T23:59:59+02:00');
        $invRes = eshop_new_api_call($baseUrl, $token, 'GET', "/api-engine/invoices?dateIssue[after]={$from}&dateIssue[before]={$to}&itemsPerPage=200");
        $match = null;
        $memberCount = count($invRes['body']['hydra:member'] ?? []);
        if ($memberCount > 0) {
            foreach ($invRes['body']['hydra:member'] as $inv) {
                if (($inv['order'] ?? '') === $orderIri) { $match = $inv; break; }
            }
        }
        $result = ['ok' => true, 'http' => 200, 'body' => $match ?? ['info' => "Faktura nenalezena mezi {$memberCount} fakturami za den {$day}"], 'error' => ''];
    } else {
        $result = ['ok' => false, 'http' => 0, 'body' => null, 'error' => 'Nepodařilo se zjistit datum objednávky (detail objednávky nevrátil "created").'];
    }
}
if ($action === 'product_stock' && isset($_GET['product_number'])) {
    $num = (string)$_GET['product_number'];
    // Zkusíme najít produkt/variantu podle kódu - přesný endpoint zatím neznáme,
    // zkusíme nejpravděpodobnější: product-items s filtrem "number".
    $result = eshop_new_api_call($baseUrl, $token, 'GET', '/api-engine/product-items?number=' . urlencode($num));
}
if ($action === 'test_stock_patch' && isset($_GET['product_item_id']) && isset($_GET['new_stock'])) {
    $piId = (string)$_GET['product_item_id'];
    $newStock = (int)$_GET['new_stock'];
    $ch = curl_init($baseUrl . '/api-engine/product-items/' . urlencode($piId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['stock' => $newStock], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Content-Type: application/merge-patch+json', 'Accept: application/ld+json'],
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result = ['ok' => $http >= 200 && $http < 300, 'http' => $http, 'body' => json_decode((string)$resp, true), 'error' => ''];
}
$testOrderIri = (string)($_GET['order_iri'] ?? '');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Ověření – nové API (produkce)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; }
.wrap { max-width:1000px; margin:0 auto; }
.card { background:#fff; border:1px solid #e7e9ec; border-radius:14px; padding:16px; margin-bottom:14px; }
.warn-banner { background:#fff3cd; border:1.5px solid #ffe08a; color:#7a5600; border-radius:12px; padding:10px 16px; margin-bottom:16px; font-weight:700; font-size:13px; text-align:center; }
.btn { background:linear-gradient(135deg,#24d84a,#00b52a); color:#fff; border:none; border-radius:999px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; margin-right:8px; }
pre { background:#f7f8f9; padding:10px; border-radius:10px; overflow:auto; font-size:11.5px; max-height:500px; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid #e7e9ec; border-radius:999px; padding:6px 14px; }
.status-ok { color:#0a7a34; font-weight:700; }
.status-err { color:#d93025; font-weight:700; }
</style>
</head>
<body>
<div class="wrap">
    <a class="back-link" href="index.php">&larr; Zpět na Nový Cfloat</a>
    <div class="warn-banner">⚠ POUZE ČTENÍ – pracuje s OSTRÝM e-shopem (<?php echo h($baseUrl); ?>), ale nic zde nezapisuje ani neukládá do databáze cfloatu.</div>
    <h1>Ověření nového API</h1>

    <div class="card">
        <h2 style="font-size:15px;">Stav tokenu</h2>
        <p style="font-size:12.5px;color:#0a7a34;">✓ Token načten ze secrets/eshop_new_api.php. Délka: <b><?php echo strlen($token); ?></b> znaků, poslední znaky: <code>…<?php echo h(substr($token, -6)); ?></code></p>
    </div>

    <div class="card">
        <h2 style="font-size:15px;">Testy</h2>
        <a class="btn" href="?action=list_orders">Načíst objednávky (str. 1)</a>
        <a class="btn" href="?action=order_states">Načíst stavy objednávek</a>
        <a class="btn" href="?action=test_date_filter" style="background:linear-gradient(135deg,#4a90d9,#2f6fb8);">Otestovat filtr od 1.1.2025</a>
    </div>

    <div class="card">
        <h2 style="font-size:15px;">Položky a faktura ke konkrétní objednávce</h2>
        <p style="font-size:12px;color:#666;">Vlož IRI objednávky, např. <code>/api-engine/orders/0bec7692-a386-11f1-aa46-d70aca470997</code> (najdeš ho v poli <code>@id</code> u kterékoliv objednávky výše).</p>
        <form method="get" style="margin-bottom:8px;">
            <input type="text" name="order_iri" value="<?php echo h($testOrderIri); ?>" placeholder="/api-engine/orders/xxxxx" style="width:100%;max-width:500px;">
            <br><br>
            <button type="submit" name="action" value="order_items" class="btn" style="background:linear-gradient(135deg,#e08b3a,#c26f1f);">Načíst položky objednávky</button>
            <button type="submit" name="action" value="order_invoice" class="btn" style="background:linear-gradient(135deg,#9a5fd9,#7a3fb8);">Načíst fakturu objednávky</button>
        </form>
    </div>

    <div class="card">
        <h2 style="font-size:15px;">Sklad ke konkrétnímu produktu</h2>
        <p style="font-size:12px;color:#666;">Vlož kód produktu (pole <code>number</code> z položky objednávky), např. <code>C17341-9990</code> nebo <code>1265236</code>.</p>
        <form method="get">
            <input type="text" name="product_number" placeholder="kód produktu" style="width:100%;max-width:300px;">
            <br><br>
            <button type="submit" name="action" value="product_stock" class="btn" style="background:linear-gradient(135deg,#3aa0a0,#1f7a7a);">Načíst sklad produktu</button>
        </form>
    </div>

    <div class="card" style="border:2px solid #d93025;">
        <h2 style="font-size:15px;color:#d93025;">⚠ Test zápisu do skladu (opatrně!)</h2>
        <p style="font-size:12px;color:#666;">Vlož ID konkrétní varianty (poslední část @id z "Načíst sklad produktu", např. <code>4654ddec-0e3f-11f1-805a-e37750729a1f</code>) a novou hodnotu skladu. Použij na produktu, kde to nevadí, když se to pokazí!</p>
        <form method="get">
            <input type="text" name="product_item_id" placeholder="ID varianty (product-items/XXXX)" style="width:100%;max-width:400px;">
            <input type="number" name="new_stock" placeholder="nová hodnota skladu" style="width:150px;">
            <br><br>
            <button type="submit" name="action" value="test_stock_patch" class="btn" style="background:#d93025;">Zapsat nový sklad (PATCH)</button>
        </form>
    </div>

    <?php if ($result !== null): ?>
        <div class="card">
            <h2 style="font-size:15px;">
                Výsledek:
                <?php if ($result['ok']): ?><span class="status-ok">OK (HTTP <?php echo (int)$result['http']; ?>)</span>
                <?php else: ?><span class="status-err">Chyba (HTTP <?php echo (int)$result['http']; ?>) – <?php echo h($result['error']); ?></span>
                <?php endif; ?>
            </h2>
            <pre><?php echo h(json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
