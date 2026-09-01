<?php
declare(strict_types=1);

/**
 * cfloat-new/nove-objednavky-detail.php
 *
 * Detail objednávky z nového API: položky, aktuální sklad e-shopu, "zbývá po
 * odečtení objednaných kusů", zaškrtávání k Vavrys (persistováno v
 * eshop_new_order_item_checks), příprava a odeslání k Vavrys - stejná logika
 * a stejné bezpečnostní pojistky (jednorázový token + potvrzovací checkbox)
 * jako v objednavky.php, jen nad daty z nového API.
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
if (!isset($pdo) || !($pdo instanceof PDO)) die('Chybí config.php nebo $pdo.');

require_once __DIR__ . '/lib/vavrys_katalog.php';
require_once __DIR__ . '/lib/eshop_new_helpers.php';

$secretsPath = __DIR__ . '/../secrets/eshop_new_api.php';
if (!is_file($secretsPath)) die('Chybí secrets/eshop_new_api.php.');
$cfg = include $secretsPath;
$baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
$token   = trim((string)($cfg['token'] ?? ''));

// eshop_new_api_call() teď žije v lib/eshop_new_helpers.php

$orderIri = (string)($_GET['iri'] ?? '');
if ($orderIri === '') die('Chybí parametr iri.');
$isEmbed = !empty($_GET['embed']);

// ---------------------------------------------------------------------------
// AJAX: přepnutí zaškrtnutí položky k Vavrys (perzistuje se hned)
// ---------------------------------------------------------------------------
if (isset($_POST['ajax_toggle'])) {
    header('Content-Type: application/json');
    $itemIri = (string)($_POST['item_iri'] ?? '');
    $checked = !empty($_POST['checked']) ? 1 : 0;
    if ($itemIri === '') { echo json_encode(['ok' => false]); exit; }
    try {
        $pdo->prepare("
            INSERT INTO eshop_new_order_item_checks (order_iri, item_iri, vavrys_check, updated_at)
            VALUES (:o, :i, :c, NOW())
            ON DUPLICATE KEY UPDATE vavrys_check = VALUES(vavrys_check), updated_at = NOW()
        ")->execute([':o' => $orderIri, ':i' => $itemIri, ':c' => $checked]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Načtení objednávky (nejdřív z DB, jinak živě)
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM eshop_new_orders WHERE order_iri = :iri LIMIT 1");
$stmt->execute([':iri' => $orderIri]);
$dbRow = $stmt->fetch(PDO::FETCH_ASSOC);
$order = $dbRow ? json_decode((string)$dbRow['raw_json'], true) : null;
if (!$order) {
    $res = eshop_new_api_call($baseUrl, $token, $orderIri);
    if (!$res['ok']) die('Objednávku se nepodařilo načíst (HTTP ' . $res['http'] . ').');
    $order = $res['body'];
}

$orderCreated = $order['created'] ?? null;
$invoice = null;
$orderNumber = '';

if ($orderCreated) {
    $invoice = eshop_new_fetch_order_invoice($baseUrl, $token, $orderIri, $orderCreated);
    if ($invoice) $orderNumber = (string)($invoice['variableSymbol'] ?? '');
}
if ($orderNumber === '') $orderNumber = substr($orderIri, -12);

// ---------------------------------------------------------------------------
// Položky - nejdřív z DB (uložené webhookem, rychlé), jinak dopočítáme naživo
// a rovnou uložíme pro příště.
// ---------------------------------------------------------------------------
$itemsStmt = $pdo->prepare("SELECT * FROM eshop_new_order_items WHERE order_iri = :o");
$itemsStmt->execute([':o' => $orderIri]);
$dbItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$checksStmt = $pdo->prepare("SELECT item_iri, vavrys_check FROM eshop_new_order_item_checks WHERE order_iri = :o");
$checksStmt->execute([':o' => $orderIri]);
$checksMap = [];
foreach ($checksStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $checksMap[$r['item_iri']] = (bool)$r['vavrys_check'];

if (!empty($dbItems)) {
    $items = [];
    foreach ($dbItems as $r) {
        $items[] = [
            '@id' => $r['item_iri'], 'name' => $r['name'], 'number' => $r['number'], 'ean' => $r['ean'] ?? null,
            'oldVariantValue1' => $r['size'], 'pieces' => (int)$r['pieces'], 'priceWithVat' => (float)$r['price_with_vat'],
            '_stock' => $r['stock'] !== null ? (int)$r['stock'] : null, '_stock_exact' => (bool)$r['stock_exact'],
            '_zbyva' => $r['stock'] !== null ? (int)$r['stock'] - (int)$r['pieces'] : null,
            '_checked' => $checksMap[$r['item_iri']] ?? false,
        ];
    }
} else {
    // Webhook se k téhle objednávce ještě nedostal (typicky starší objednávka z historie) -
    // dopočítáme naživo a rovnou uložíme, ať je to příště rychlé.
    eshop_new_persist_order($pdo, $baseUrl, $token, $order);
    $itemsStmt->execute([':o' => $orderIri]);
    $dbItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    $items = [];
    foreach ($dbItems as $r) {
        $items[] = [
            '@id' => $r['item_iri'], 'name' => $r['name'], 'number' => $r['number'], 'ean' => $r['ean'] ?? null,
            'oldVariantValue1' => $r['size'], 'pieces' => (int)$r['pieces'], 'priceWithVat' => (float)$r['price_with_vat'],
            '_stock' => $r['stock'] !== null ? (int)$r['stock'] : null, '_stock_exact' => (bool)$r['stock_exact'],
            '_zbyva' => $r['stock'] !== null ? (int)$r['stock'] - (int)$r['pieces'] : null,
            '_checked' => $checksMap[$r['item_iri']] ?? false,
        ];
    }
}

// ---------------------------------------------------------------------------
// Příprava náhledu odeslání k Vavrys (jen zaškrtnuté položky)
// ---------------------------------------------------------------------------
$vavrysPreview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vavrys_priprava') {
    $file = vpo_find_vavrys_file();
    if ($file === null) {
        $vavrysPreview = ['error' => 'Katalog Vavrys (XML) nebyl na serveru nalezen.'];
    } else {
        $matched = []; $unmatched = [];
        foreach ($items as $it) {
            if (empty($it['_checked'])) continue;
            $velikost = (string)($it['oldVariantValue1'] ?? '');
            $found = vpo_lookup_item($file, null, (string)($it['number'] ?? ''), $velikost);
            if ($found) {
                $matched[] = ['item' => $it, 'match' => $found];
            } else {
                $unmatched[] = $it;
            }
        }
        if (empty($matched)) {
            $vavrysPreview = ['error' => 'Žádná zaškrtnutá položka se nenašla v katalogu Vavrys.'];
        } else {
            $token128 = bin2hex(random_bytes(16));
            $_SESSION['vavrys_new_token_' . $orderIri] = $token128;
            $vavrysPreview = ['matched' => $matched, 'unmatched' => $unmatched, 'token' => $token128];
        }
    }
}

// ---------------------------------------------------------------------------
// Skutečné odeslání (po potvrzení náhledu) - stejná pojistka jako objednavky.php
// ---------------------------------------------------------------------------
$vavrysFlash = null;
if (isset($_SESSION['vavrys_new_flash'])) { $vavrysFlash = $_SESSION['vavrys_new_flash']; unset($_SESSION['vavrys_new_flash']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vavrys_odeslat') {
    $postToken = (string)($_POST['token'] ?? '');
    $sessKey = 'vavrys_new_token_' . $orderIri;
    $sessToken = (string)($_SESSION[$sessKey] ?? '');
    unset($_SESSION[$sessKey]);

    if ($postToken === '' || $sessToken === '' || !hash_equals($sessToken, $postToken)) {
        $_SESSION['vavrys_new_flash'] = ['ok' => false, 'text' => 'Neplatný nebo už použitý token. Otevřete přípravu znovu.'];
    } elseif (empty($_POST['confirm_final'])) {
        $_SESSION['vavrys_new_flash'] = ['ok' => false, 'text' => 'Nebylo zaškrtnuto potvrzení, nic se neodeslalo.'];
    } elseif (!isset($VAVRYS_LOGIN, $VAVRYS_PASSWORD) || $VAVRYS_LOGIN === '' || $VAVRYS_PASSWORD === '') {
        $_SESSION['vavrys_new_flash'] = ['ok' => false, 'text' => 'Přihlašovací údaje k Vavrys nejsou k dispozici.'];
    } else {
        $file = vpo_find_vavrys_file();
        $polozky = [];
        foreach ($items as $it) {
            if (empty($it['_checked'])) continue;
            $velikost = (string)($it['oldVariantValue1'] ?? '');
            $found = $file ? vpo_lookup_item($file, null, (string)($it['number'] ?? ''), $velikost) : null;
            if ($found) {
                $polozky[] = [
                    'katalogId' => $found['katalogId'] ?? '', 'strCislo' => $found['strCislo'] ?? '',
                    'karCislo' => $found['karCislo'] ?? '', 'karCisloId' => $found['karCisloId'] ?? '',
                    'idX' => $found['idX'] ?? '', 'idY' => $found['idY'] ?? '',
                    'mnozstvi' => (int)($it['pieces'] ?? 1), 'cena' => $found['cena'] ?? 0,
                ];
            }
        }
        if (empty($polozky)) {
            $_SESSION['vavrys_new_flash'] = ['ok' => false, 'text' => 'Mezitím se nenašla žádná odpovídající položka. Zkuste přípravu znovu.'];
        } else {
            $data = vpo_build_objednavka_data($orderNumber, date('Y-m-d'), $polozky);
            $sendResult = vpo_send_objednavka($VAVRYS_LOGIN, $VAVRYS_PASSWORD, $data);
            $_SESSION['vavrys_new_flash'] = $sendResult['success']
                ? ['ok' => true, 'text' => 'Objednávka byla úspěšně odeslána u Vavrys (' . count($polozky) . ' položek).']
                : ['ok' => false, 'text' => 'Odeslání selhalo: ' . ($sendResult['message'] ?? ($sendResult['error'] ?? 'neznámá chyba'))];
        }
    }
    header('Location: nove-objednavky-detail.php?iri=' . urlencode($orderIri) . ($isEmbed ? '&embed=1' : ''));
    exit;
}

// ---------------------------------------------------------------------------
// Změna stavu objednávky (zápis do e-shopu) - s volbou, zda poslat e-mail zákazníkovi
// ---------------------------------------------------------------------------
$stateChangeFlash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_state') {
    $newStateIri = (string)($_POST['new_state_iri'] ?? '');
    $sendEmail = !empty($_POST['send_email']);
    if ($newStateIri === '') {
        $stateChangeFlash = ['ok' => false, 'text' => 'Nebyl vybrán žádný stav.'];
    } else {
        $ch = curl_init($baseUrl . '/api-engine/order-state-changes');
        $body = json_encode(['order' => $orderIri, 'orderState' => $newStateIri, 'isSendNotifications' => $sendEmail], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['X-AUTH-TOKEN: ' . $token, 'Content-Type: application/ld+json', 'Accept: application/ld+json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http >= 200 && $http < 300) {
            // Osvěžíme lokální kopii, ať se stav hned promítne i v cFloatu (bez čekání na webhook)
            $fresh = eshop_new_api_call($baseUrl, $token, $orderIri);
            if ($fresh['ok'] && is_array($fresh['body'])) {
                $order = $fresh['body'];
                $pdo->prepare("UPDATE eshop_new_orders SET order_state_iri = :si, order_state_name = :sn, raw_json = :raw, updated_at = NOW() WHERE order_iri = :iri")
                    ->execute([
                        ':si' => $order['orderState']['@id'] ?? null,
                        ':sn' => $order['orderState']['name'] ?? null,
                        ':raw' => json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':iri' => $orderIri,
                    ]);
            }
            $stateChangeFlash = ['ok' => true, 'text' => 'Stav objednávky byl změněn' . ($sendEmail ? ' a zákazníkovi byl odeslán e-mail.' : ', bez odeslání e-mailu.')];
        } else {
            $stateChangeFlash = ['ok' => false, 'text' => "Změna stavu selhala (HTTP {$http})."];
        }
    }
}

// Seznam dostupných stavů (pro dropdown)
$orderStatesRes = eshop_new_api_call($baseUrl, $token, '/api-engine/order-states?itemsPerPage=100');
$orderStates = $orderStatesRes['body']['hydra:member'] ?? [];

$customerName = $order['name'] ?? '';
$stateName = $order['orderState']['name'] ?? '';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Detail objednávky <?php echo h($orderNumber); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: system-ui, sans-serif; background:#fafbfb; margin:0; padding:20px 16px 60px; }
.wrap { max-width:1000px; margin:0 auto; }
.card { background:#fff; border:1px solid #e7e9ec; border-radius:14px; padding:18px; margin-bottom:16px; }
.back-link { color:#666; font-size:12.5px; text-decoration:none; border:1px solid #e7e9ec; border-radius:999px; padding:6px 14px; margin-right:8px; }
.btn { background:linear-gradient(135deg,#24d84a,#00b52a); color:#fff; border:none; border-radius:999px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
.btn.danger { background:linear-gradient(135deg,#d93025,#a11f17); }
table { border-collapse:collapse; width:100%; font-size:13px; }
td, th { border:1px solid #e7e9ec; padding:8px 10px; text-align:left; }
th { background:#f7f8f9; }
.pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; background:#eef6ee; color:#2a7a2a; }
.warn { color:#e08b00; font-weight:700; }
.neg { color:#d93025; font-weight:700; }
.note { font-size:11.5px; color:#888; margin-top:6px; }
.flash-ok { background:#eafbf0; border:1px solid #bdeccb; color:#0a7a34; border-radius:12px; padding:10px 16px; margin-bottom:14px; }
.flash-err { background:#fdeceb; border:1px solid #f5c6c2; color:#d93025; border-radius:12px; padding:10px 16px; margin-bottom:14px; }
.confirm-box { border:1.5px dashed #e08b00; border-radius:12px; padding:14px; margin-top:12px; background:#fffaf0; }
</style>
</head>
<body<?php echo $isEmbed ? ' style="padding:8px;"' : ''; ?>>
<div class="wrap">
    <?php if (!$isEmbed): ?>
    <a class="back-link" href="nove-objednavky.php">&larr; Zpět na seznam</a>
    <a class="btn" href="nove-objednavky-tisk.php?iri=<?php echo urlencode($orderIri); ?>">🖨 Tisk objednávky</a>
    <?php else: ?>
    <a class="back-link" href="nove-objednavky-tisk.php?iri=<?php echo urlencode($orderIri); ?>" target="_blank">🖨 Tisk objednávky</a>
    <?php endif; ?>

    <h1>Objednávka č. <?php echo h($orderNumber); ?></h1>

    <?php if ($vavrysFlash): ?>
        <div class="<?php echo $vavrysFlash['ok'] ? 'flash-ok' : 'flash-err'; ?>"><?php echo h($vavrysFlash['text']); ?></div>
    <?php endif; ?>

    <?php if ($stateChangeFlash): ?>
        <div class="<?php echo $stateChangeFlash['ok'] ? 'flash-ok' : 'flash-err'; ?>"><?php echo h($stateChangeFlash['text']); ?></div>
    <?php endif; ?>

    <div class="card">
        <p><b>Zákazník:</b> <?php echo h($customerName); ?></p>
        <p><b>Vytvořeno:</b> <?php echo h($orderCreated ? date('d.m.Y H:i', strtotime($orderCreated)) : '-'); ?></p>
        <?php if ($stateName): ?><p><b>Stav:</b> <span class="pill"><?php echo h($stateName); ?></span></p><?php endif; ?>

        <form method="post" style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="action" value="change_state">
            <select name="new_state_iri" style="padding:8px 10px;border:1px solid #ccc;border-radius:8px;">
                <option value="">-- změnit stav na --</option>
                <?php foreach ($orderStates as $st): ?>
                    <option value="<?php echo h($st['@id']); ?>"><?php echo h($st['name'] ?? ''); ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:13px;"><input type="checkbox" name="send_email" value="1"> Odeslat e-mail zákazníkovi</label>
            <button type="submit" class="btn">Uložit stav</button>
        </form>
        <p class="note">Bez zaškrtnutí se stav změní tiše, zákazník nedostane žádné avízo. Se zaškrtnutím se pošle automatický e-mail nastavený v e-shopu pro daný stav.</p>
    </div>

    <div class="card">
        <h2 style="font-size:15px;">Položky</h2>
        <p class="note">Sloupec "Sklad" je pro konkrétní velikost položky, pokud se ji podařilo v e-shopu dohledat (⚠ = nedohledáno přesně, jde o odhad z nejvyšší varianty). "Zbývá" = sklad − objednané kusy této položky.</p>
        <table>
            <thead>
                <tr><th></th><th>Položka</th><th>Kód</th><th>EAN</th><th>Velikost</th><th>Ks</th><th>Sklad</th><th>Zbývá</th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><input type="checkbox" class="vavrys-chk" data-item-iri="<?php echo h($it['@id']); ?>" <?php echo $it['_checked'] ? 'checked' : ''; ?>></td>
                    <td><?php echo h($it['name'] ?? ''); ?></td>
                    <td><?php echo h($it['number'] ?? ''); ?></td>
                    <td><?php echo h($it['ean'] ?? '-'); ?></td>
                    <td><?php echo h($it['oldVariantValue1'] ?? ''); ?></td>
                    <td><?php echo (int)($it['pieces'] ?? 1); ?></td>
                    <td><?php echo $it['_stock'] !== null ? (int)$it['_stock'] : '?'; ?><?php if ($it['_stock'] !== null && empty($it['_stock_exact'])): ?> <span class="warn" title="Nedohledáno přesně pro tuto velikost, jde o odhad">⚠</span><?php endif; ?></td>
                    <td class="<?php echo ($it['_zbyva'] !== null && $it['_zbyva'] < 0) ? 'neg' : ''; ?>">
                        <?php echo $it['_zbyva'] !== null ? (int)$it['_zbyva'] : '?'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" style="margin-top:14px;">
            <input type="hidden" name="action" value="vavrys_priprava">
            <button type="submit" class="btn">Připravit odeslání zaškrtnutých u Vavrys</button>
        </form>
    </div>

    <?php if ($vavrysPreview): ?>
        <div class="card">
            <h2 style="font-size:15px;">Náhled odeslání Vavrys</h2>
            <?php if (!empty($vavrysPreview['error'])): ?>
                <p class="flash-err"><?php echo h($vavrysPreview['error']); ?></p>
            <?php else: ?>
                <p><b>K odeslání (<?php echo count($vavrysPreview['matched']); ?>):</b></p>
                <ul>
                <?php foreach ($vavrysPreview['matched'] as $m): ?>
                    <li><?php echo h($m['item']['name']); ?> — <?php echo (int)$m['item']['pieces']; ?> ks</li>
                <?php endforeach; ?>
                </ul>
                <?php if (!empty($vavrysPreview['unmatched'])): ?>
                    <p class="warn">⚠ Nenalezeno v katalogu Vavrys (nebude odesláno):</p>
                    <ul>
                    <?php foreach ($vavrysPreview['unmatched'] as $u): ?>
                        <li><?php echo h($u['name']); ?></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="confirm-box">
                    <form method="post" onsubmit="return confirm('Opravdu ZÁVAZNĚ odeslat tuto objednávku Vavrys? Nelze vzít zpět.');">
                        <input type="hidden" name="action" value="vavrys_odeslat">
                        <input type="hidden" name="token" value="<?php echo h($vavrysPreview['token']); ?>">
                        <label><input type="checkbox" name="confirm_final" value="1" required> Potvrzuji, že chci závazně odeslat objednávku Vavrys.</label><br><br>
                        <button type="submit" class="btn danger">Závazně odeslat Vavrys</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.vavrys-chk').forEach(function(cb) {
    cb.addEventListener('change', function() {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'ajax_toggle=1&item_iri=' + encodeURIComponent(cb.dataset.itemIri) + '&checked=' + (cb.checked ? '1' : '0')
        });
    });
});
</script>
</body>
</html>
