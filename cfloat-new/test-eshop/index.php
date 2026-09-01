<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../../index.php');
    exit;
}

require_once __DIR__ . '/config-test.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$tokenSaveMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_token') {
    $newToken = (string)($_POST['new_token'] ?? '');
    if (test_eshop_save_token($newToken)) {
        $tokenSaveMessage = 'ok';
    } else {
        $tokenSaveMessage = 'error';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_token') {
    test_eshop_clear_token();
    $tokenSaveMessage = 'cleared';
}

$currentToken = test_eshop_get_token();

/**
 * Obecné volání testovacího Eshop-rychle API Engine.
 * @return array{ok:bool, http:int, body:mixed, raw:string, error:string}
 */
function test_eshop_api_call(string $method, string $path, ?array $jsonBody = null): array
{
    $token = test_eshop_get_token();
    if ($token === '') {
        return ['ok' => false, 'http' => 0, 'body' => null, 'raw' => '', 'error' => 'Token není vyplněný. Nastav ho ve formuláři níže.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'body' => null, 'raw' => '', 'error' => 'Na serveru chybí cURL.'];
    }

    $url = rtrim(TEST_ESHOP_BASE_URL, '/') . $path;
    $headers = [
        'X-AUTH-TOKEN: ' . $token,
        'Accept: application/ld+json',
    ];
    if ($jsonBody !== null) {
        // API Platform (Symfony) vyžaduje pro PATCH tenhle konkrétní typ,
        // jinak často vrací obecnou chybu 500 místo jasné 415/400.
        $headers[] = ($method === 'PATCH') ? 'Content-Type: application/merge-patch+json' : 'Content-Type: application/ld+json';
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
    ];
    if ($jsonBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'http' => $http, 'body' => null, 'raw' => '', 'error' => 'cURL chyba: ' . $err, 'response_headers' => ''];
    }

    $responseHeaders = substr($response, 0, $headerSize);
    $raw = substr($response, $headerSize);

    $decoded = json_decode($raw, true);
    $ok = $http >= 200 && $http < 300;

    return ['ok' => $ok, 'http' => $http, 'body' => $decoded, 'raw' => $raw, 'error' => $ok ? '' : ('HTTP ' . $http), 'response_headers' => $responseHeaders, 'sent_url' => $url, 'sent_token_len' => strlen($token)];
}

// ---------------------------------------------------------------------------
// Akce
// ---------------------------------------------------------------------------
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$result = null;

if ($action === 'list_orders') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $result = test_eshop_api_call('GET', '/api-engine/orders?page=' . $page);
}

if ($action === 'get_order_states') {
    $result = test_eshop_api_call('GET', '/api-engine/order-states');
}

if ($action === 'view_order' && isset($_GET['id'])) {
    $result = test_eshop_api_call('GET', '/api-engine/orders/' . urlencode((string)$_GET['id']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm_write_note') {
    $orderIri = (string)($_POST['order_iri'] ?? '');
    $note = (string)($_POST['note'] ?? '');
    if ($orderIri !== '') {
        $result = test_eshop_api_call('PATCH', $orderIri, ['internalNote' => $note]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm_state_change') {
    $orderIri = (string)($_POST['order_iri'] ?? '');
    $stateIri = (string)($_POST['state_iri'] ?? '');
    $sendNotif = isset($_POST['send_notif']) && $_POST['send_notif'] === '1';
    if ($orderIri !== '' && $stateIri !== '') {
        $result = test_eshop_api_call('POST', '/api-engine/order-state-changes', [
            'order' => $orderIri,
            'orderState' => $stateIri,
            'isSendNotifications' => $sendNotif,
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm_tracking') {
    $deliveryPaymentIri = (string)($_POST['delivery_payment_iri'] ?? '');
    $trackingNumber = (string)($_POST['tracking_number'] ?? '');
    $trackingLink = (string)($_POST['tracking_link'] ?? '');
    if ($deliveryPaymentIri !== '') {
        $result = test_eshop_api_call('PATCH', $deliveryPaymentIri, [
            'trackingNumber' => $trackingNumber,
            'trackingUrl' => $trackingLink,
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>TEST – Eshop-rychle API</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { --g1:#24d84a; --g2:#00b52a; --warn:#e08b00; --danger:#d93025; }
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; }
.wrap { max-width:1000px; margin:0 auto; }
.logo-top { text-align:center; margin-bottom:10px; }
.logo-top img { max-width:140px; }
.test-banner {
    background:#fff3cd; border:1.5px solid #ffe08a; color:#7a5600; border-radius:12px;
    padding:10px 16px; text-align:center; font-weight:700; font-size:13px; margin-bottom:16px;
}
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid #e7e9ec; border-radius:999px; padding:6px 14px; }
h1 { font-size:20px; margin:14px 0; }
.card { background:#fff; border:1px solid #e7e9ec; border-radius:14px; padding:16px; margin-bottom:14px; }
button, .btn { background:linear-gradient(135deg,var(--g1),var(--g2)); color:#fff; border:none; border-radius:999px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
button.secondary { background:#eee; color:#333; }
button.danger { background:var(--danger); }
pre { background:#f7f8f9; padding:10px; border-radius:10px; overflow:auto; font-size:11.5px; max-height:400px; }
input[type=text] { padding:8px 10px; border:1px solid #ccc; border-radius:8px; font-size:13px; width:100%; }
label { font-size:12px; font-weight:700; color:#666; display:block; margin:8px 0 4px; }
.confirm-box { border:1.5px dashed var(--warn); border-radius:12px; padding:12px; margin-top:10px; background:#fffaf0; }
.status-ok { color:#0a7a34; font-weight:700; }
.status-err { color:var(--danger); font-weight:700; }
</style>
</head>
<body>
<div class="wrap">
    <div class="logo-top"><a href="../index.php"><img src="../../logo-1.png" alt="C-Store.cz"></a></div>
    <a class="back-link" href="../index.php">&larr; Zpět na Nový Cfloat</a>
    <div class="test-banner">⚠ TESTOVACÍ MODUL – pracuje výhradně s testovacím (.dev) e-shopem, NE s ostrou databází. Adresa: <?php echo h(TEST_ESHOP_BASE_URL); ?></div>
    <h1>Test – Eshop-rychle API Engine</h1>

    <?php if ($tokenSaveMessage === 'ok'): ?>
        <div class="card" style="border-color:#bdeccb;background:#eafbf0;color:#0a7a34;">✓ Token uložen.</div>
    <?php elseif ($tokenSaveMessage === 'cleared'): ?>
        <div class="card" style="border-color:#e7e9ec;">Token smazán.</div>
    <?php elseif ($tokenSaveMessage === 'error'): ?>
        <div class="card" style="border-color:#f5c6c2;background:#fdeceb;color:#d93025;">Token se nepodařilo uložit (zkontroluj oprávnění zápisu do složky).</div>
    <?php endif; ?>

    <div class="card">
        <h2 style="font-size:15px;">0) API token</h2>
        <?php if ($currentToken !== ''): ?>
            <p style="font-size:12.5px;color:#0a7a34;">✓ Token je nastavený (skrytý, uložený mimo dosah webu). Poslední znaky: <code>…<?php echo h(substr($currentToken, -6)); ?></code></p>
            <p style="font-size:11.5px;color:#666;">
                Délka tokenu: <b><?php echo strlen($currentToken); ?></b> znaků &nbsp;|&nbsp;
                První 4 znaky: <code><?php echo h(substr($currentToken, 0, 4)); ?></code> &nbsp;|&nbsp;
                Obsahuje mezeru: <b><?php echo (strpos($currentToken, ' ') !== false) ? 'ANO ⚠' : 'ne'; ?></b> &nbsp;|&nbsp;
                Obsahuje dvojtečku: <b><?php echo (strpos($currentToken, ':') !== false) ? 'ano' : 'ne'; ?></b>
            </p>
            <form method="post" onsubmit="return confirm('Opravdu smazat uložený token?');">
                <input type="hidden" name="action" value="clear_token">
                <button type="submit" class="secondary">Smazat token</button>
            </form>
        <?php else: ?>
            <p style="font-size:12.5px;color:#e08b00;">Token zatím není nastavený.</p>
        <?php endif; ?>
        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="action" value="save_token">
            <label>Nový token (z administrace api.eshop-rychle.dev)</label>
            <input type="text" name="new_token" placeholder="vlož token sem" autocomplete="off">
            <button type="submit" style="margin-top:8px;">Uložit token</button>
        </form>
    </div>

    <?php if ($currentToken === ''): ?>
        <div class="card">
            <p><b>Token ještě není vyplněný.</b> Vlož ho do formuláře výše.</p>
        </div>
    <?php else: ?>

        <div class="card">
            <h2 style="font-size:15px;">1) Načíst objednávky z testovacího e-shopu</h2>
            <a class="btn" href="?action=list_orders">Načíst objednávky</a>
            <a class="btn secondary" href="?action=get_order_states" style="margin-left:8px;">Načíst seznam stavů</a>
        </div>

        <?php if ($result !== null): ?>
            <div class="card">
                <h2 style="font-size:15px;">Výsledek (<?php echo $result['ok'] ? '<span class="status-ok">OK</span>' : '<span class="status-err">Chyba</span>'; ?>, HTTP <?php echo (int)$result['http']; ?>)</h2>
                <?php if ($result['error'] !== '' && !$result['ok']): ?>
                    <p class="status-err"><?php echo h($result['error']); ?></p>
                <?php endif; ?>
                <p style="font-size:11.5px;color:#666;">
                    Volaná URL: <code><?php echo h($result['sent_url'] ?? ''); ?></code><br>
                    Délka odeslaného tokenu: <b><?php echo (int)($result['sent_token_len'] ?? 0); ?></b> znaků
                </p>
                <p style="font-size:11.5px;color:#666;">Hlavičky odpovědi ze serveru:</p>
                <pre><?php echo h($result['response_headers'] ?? ''); ?></pre>
                <p style="font-size:11.5px;color:#666;">Tělo odpovědi:</p>
                <pre><?php echo h(json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2 style="font-size:15px;">2) Ruční test zápisu (vyžaduje IRI objednávky/dopravy z výpisu výše)</h2>
            <p style="font-size:12px;color:#777;">Zkopíruj hodnotu <code>@id</code> konkrétní objednávky z výpisu výše (např. <code>/api-engine/orders/123</code>) a vlož ji sem. Každá akce vyžaduje potvrzení kliknutím.</p>

            <form method="post" class="confirm-box">
                <input type="hidden" name="action" value="confirm_write_note">
                <label>IRI objednávky (@id)</label>
                <input type="text" name="order_iri" placeholder="/api-engine/orders/123">
                <label>Interní poznámka</label>
                <input type="text" name="note" placeholder="Testovací poznámka z cFloat">
                <button type="submit" style="margin-top:10px;">⚠ Potvrdit a zapsat poznámku (TEST shop)</button>
            </form>

            <form method="post" class="confirm-box">
                <input type="hidden" name="action" value="confirm_state_change">
                <label>IRI objednávky (@id)</label>
                <input type="text" name="order_iri" placeholder="/api-engine/orders/123">
                <label>IRI nového stavu (@id ze seznamu stavů)</label>
                <input type="text" name="state_iri" placeholder="/api-engine/order-states/456">
                <label style="display:flex;align-items:center;gap:8px;font-weight:400;">
                    <input type="checkbox" name="send_notif" value="1"> Poslat zákazníkovi automatický e-mail (isSendNotifications)
                </label>
                <button type="submit" style="margin-top:10px;">⚠ Potvrdit a změnit stav (TEST shop)</button>
            </form>

            <form method="post" class="confirm-box">
                <input type="hidden" name="action" value="confirm_tracking">
                <label>IRI orderDeliveryPayment (@id)</label>
                <input type="text" name="delivery_payment_iri" placeholder="/api-engine/order-delivery-payments/789">
                <label>Tracking číslo</label>
                <input type="text" name="tracking_number" placeholder="např. 123456789">
                <label>Tracking odkaz (nepovinné)</label>
                <input type="text" name="tracking_link" placeholder="https://...">
                <button type="submit" style="margin-top:10px;">⚠ Potvrdit a zapsat tracking (TEST shop)</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
